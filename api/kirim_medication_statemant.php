<?php
/**
 * api/kirim_medication_statement.php
 * Kirim MedicationStatement ke Satu Sehat
 * Sumber : resep_dokter (obat biasa) + resep_dokter_racikan_detail (obat racikan)
 * Simpan : satu_sehat_medicationstatement       (no_resep, kode_brng, id_medicationstatement)
 *           satu_sehat_medicationstatement_racikan (no_resep, kode_brng, no_racik, id_medicationstatement)
 */

header('Content-Type: application/json');

if (!defined('SS_CLIENT_ID')) {
    require_once __DIR__ . '/../config/env.php';
    if (isset($pdo))           loadSatuSehatConfig($pdo);
    elseif (isset($pdo_simrs)) loadSatuSehatConfig($pdo_simrs);
}
if (!defined('SS_CLIENT_ID') || SS_CLIENT_ID === '') {
    echo json_encode(['status' => 'error', 'message' => 'Konfigurasi Satu Sehat belum diatur']);
    exit;
}
if (!isset($pdo_simrs) && isset($pdo)) $pdo_simrs = $pdo;

function jsonOutMS(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

function logMS(string $msg): void {
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents("$dir/medstatement_" . date('Y-m') . ".log",
        date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
}

// ── Token ─────────────────────────────────────────────────────────
function getTokenMS(): string {
    $cache = SS_TOKEN_CACHE_FILE;
    if (file_exists($cache)) {
        $c = json_decode(file_get_contents($cache), true);
        if (!empty($c['access_token']) && ($c['expires_at'] ?? 0) > time() + 60)
            return $c['access_token'];
    }
    $ch = curl_init(SS_OAUTH_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS     => http_build_query(['client_id' => SS_CLIENT_ID, 'client_secret' => SS_CLIENT_SECRET]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200) throw new Exception("Auth gagal HTTP $code");
    $data = json_decode($resp, true);
    if (empty($data['access_token'])) throw new Exception("Token kosong");
    file_put_contents($cache, json_encode([
        'access_token' => $data['access_token'],
        'expires_at'   => time() + (int)($data['expires_in'] ?? 3600),
    ]));
    return $data['access_token'];
}

// ── Auto-sync IHS Pasien ──────────────────────────────────────────
function autoSyncIHSMS(string $noRkm, string $nik, string $nmPasien, PDO $db): string {
    if (empty($nik)) return '';
    try {
        $token = getTokenMS();
        $ch    = curl_init(SS_FHIR_URL . '/Patient?identifier=https://fhir.kemkes.go.id/id/nik|' . urlencode($nik));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Accept: application/json'], CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>false]);
        $data = json_decode(curl_exec($ch), true); curl_close($ch);
        $ihs  = $data['entry'][0]['resource']['id'] ?? '';
        if (!empty($ihs)) {
            $db->prepare("INSERT INTO medifix_ss_pasien (no_rkm_medis,ihs_number,tgl_sync,status_sync,error_msg) VALUES(?,?,NOW(),'ditemukan','') ON DUPLICATE KEY UPDATE ihs_number=VALUES(ihs_number),tgl_sync=NOW(),status_sync='ditemukan',error_msg=''")->execute([$noRkm, $ihs]);
            logMS("OK auto-sync IHS no_rkm=$noRkm ihs=$ihs nm=$nmPasien");
        }
        return $ihs;
    } catch (Exception $e) {
        logMS("ERROR auto-sync IHS no_rkm=$noRkm: " . $e->getMessage());
        return '';
    }
}

// ── Parse signa (contoh: "2x1 tab") → [dosis, frekuensi] ─────────
function parseSigna(string $signa): array {
    $signa = strtolower(trim($signa));
    $dosis = 1; $frekuensi = 1;
    // Format: "NxM" atau "N x M"
    if (preg_match('/(\d+[\.,]?\d*)\s*x\s*(\d+[\.,]?\d*)/i', $signa, $m)) {
        $dosis     = (float) str_replace(',', '.', $m[1]);
        $frekuensi = (int)   str_replace(',', '.', $m[2]);
    } elseif (preg_match('/(\d+[\.,]?\d*)/', $signa, $m)) {
        $dosis = (float) str_replace(',', '.', $m[1]);
    }
    return [$dosis ?: 1, $frekuensi ?: 1];
}

// ── Build & POST MedicationStatement ─────────────────────────────
function postMedicationStatement(array $row, string $identifierValue, PDO $db): string {
    $token = getTokenMS();

    [$dosis, $frekuensi] = parseSigna($row['aturan_pakai'] ?? '');

    $jamPenyerahan = $row['jam_penyerahan'] ?? '00:00:00';
    if (strlen($jamPenyerahan) === 5) $jamPenyerahan .= ':00';
    $dateAsserted = ($row['tgl_penyerahan'] ?? date('Y-m-d')) . 'T' . $jamPenyerahan . '+07:00';

    $jenisRawat = strtolower($row['jenis_rawat'] ?? 'ralan');
    $categoryCode    = $jenisRawat === 'ranap' ? 'inpatient'  : 'outpatient';
    $categoryDisplay = $jenisRawat === 'ranap' ? 'Inpatient'  : 'Outpatient';

    $payload = [
        'resourceType' => 'MedicationStatement',
        'identifier'   => [[
            'system' => 'http://sys-ids.kemkes.go.id/medicationstatement/' . SS_ORG_ID,
            'use'    => 'official',
            'value'  => $identifierValue,
        ]],
        'status'   => 'completed',
        'category' => [
            'coding' => [[
                'system'  => 'http://terminology.hl7.org/CodeSystem/medication-statement-category',
                'code'    => $categoryCode,
                'display' => $categoryDisplay,
            ]],
        ],
        'medicationReference' => [
            'reference' => 'Medication/' . $row['id_medication'],
            'display'   => $row['obat_display'],
        ],
        'subject' => [
            'reference' => 'Patient/' . $row['ihs_pasien'],
            'display'   => $row['nm_pasien'],
        ],
        'context' => [
            'reference' => 'Encounter/' . $row['id_encounter'],
        ],
        'dateAsserted'     => $dateAsserted,
        'informationSource' => [
            'reference' => 'Patient/' . $row['ihs_pasien'],
            'display'   => $row['nm_pasien'],
        ],
        'dosage' => [[
            'text'   => $row['aturan_pakai'] ?? '',
            'timing' => [
                'repeat' => [
                    'frequency'  => $frekuensi,
                    'period'     => 1,
                    'periodUnit' => 'd',
                ],
            ],
            'route' => [
                'coding' => [[
                    'system'  => $row['route_system'] ?: 'http://www.whocc.no/atc',
                    'code'    => $row['route_code']   ?: '',
                    'display' => $row['route_display'] ?: '',
                ]],
            ],
            'doseAndRate' => [[
                'doseQuantity' => [
                    'value'  => $dosis,
                    'unit'   => $row['denominator_code'] ?: 'TAB',
                    'system' => $row['denominator_system'] ?: 'http://unitsofmeasure.org',
                    'code'   => $row['denominator_code'] ?: 'TAB',
                ],
            ]],
        ]],
        'note' => [[
            'text' => 'Pasien sudah memahami aturan pakai yang dijelaskan oleh petugas & Obat sudah diserahkan ke pasien',
        ]],
    ];

    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    logMS("SEND identifier=$identifierValue obat={$row['obat_display']}");

    $ch = curl_init(SS_FHIR_URL . '/MedicationStatement');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $jsonPayload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    logMS("RESPONSE HTTP=$httpCode BODY=" . substr($resp, 0, 300));
    if ($curlErr) throw new Exception("cURL: $curlErr");

    $respData = json_decode($resp, true);

    if ($httpCode === 400) {
        $issues = [];
        foreach (($respData['issue'] ?? []) as $iss) {
            $txt = ($iss['diagnostics'] ?? '') ?: ($iss['details']['text'] ?? '');
            if ($txt) $issues[] = $txt;
        }
        throw new Exception(implode(' | ', $issues) ?: "HTTP 400: " . substr($resp, 0, 300));
    }

    if (in_array($httpCode, [200, 201], true)) {
        $id = $respData['id'] ?? '';
        if (empty($id)) throw new Exception("Response sukses tapi id kosong");
        return $id;
    }

    throw new Exception(($respData['issue'][0]['diagnostics'] ?? '') ?: "HTTP $httpCode: " . substr($resp, 0, 200));
}

// ── Ambil data & validasi ─────────────────────────────────────────
function ambilDataMS(string $noResep, string $kodeBrng, string $jenisRawat, PDO $db): ?array {
    $stmt = $db->prepare("
        SELECT
            rp.no_rawat, rp.no_rkm_medis,
            p.nm_pasien, p.no_ktp,
            ro.tgl_penyerahan, ro.jam_penyerahan,
            rd.aturan_pakai, rd.jml, rd.kode_brng,
            mo.obat_display, mo.route_code, mo.route_system, mo.route_display,
            mo.denominator_code, mo.denominator_system,
            sm.id_medication,
            IFNULL(se.id_encounter,'') AS id_encounter,
            IFNULL(msp.ihs_number,'') AS ihs_pasien,
            ? AS jenis_rawat
        FROM resep_dokter rd
        JOIN resep_obat ro               ON ro.no_resep    = rd.no_resep
        JOIN reg_periksa rp              ON rp.no_rawat    = ro.no_rawat
        JOIN pasien p                    ON p.no_rkm_medis = rp.no_rkm_medis
        JOIN satu_sehat_mapping_obat mo  ON mo.kode_brng   = rd.kode_brng
        JOIN satu_sehat_medication sm    ON sm.kode_brng   = rd.kode_brng
        LEFT JOIN satu_sehat_encounter se ON se.no_rawat   = rp.no_rawat
        LEFT JOIN medifix_ss_pasien msp  ON msp.no_rkm_medis = p.no_rkm_medis
        WHERE rd.no_resep = ? AND rd.kode_brng = ?
        LIMIT 1
    ");
    $stmt->execute([$jenisRawat, $noResep, $kodeBrng]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function ambilDataMSRacikan(string $noResep, string $kodeBrng, string $noRacik, string $jenisRawat, PDO $db): ?array {
    $stmt = $db->prepare("
        SELECT
            rp.no_rawat, rp.no_rkm_medis,
            p.nm_pasien, p.no_ktp,
            ro.tgl_penyerahan, ro.jam_penyerahan,
            rdr.aturan_pakai, rdrd.jml, rdrd.kode_brng,
            mo.obat_display, mo.route_code, mo.route_system, mo.route_display,
            mo.denominator_code, mo.denominator_system,
            sm.id_medication,
            IFNULL(se.id_encounter,'') AS id_encounter,
            IFNULL(msp.ihs_number,'') AS ihs_pasien,
            ? AS jenis_rawat
        FROM resep_dokter_racikan_detail rdrd
        JOIN resep_dokter_racikan rdr    ON rdr.no_resep   = rdrd.no_resep AND rdr.no_racik = rdrd.no_racik
        JOIN resep_obat ro               ON ro.no_resep    = rdrd.no_resep
        JOIN reg_periksa rp              ON rp.no_rawat    = ro.no_rawat
        JOIN pasien p                    ON p.no_rkm_medis = rp.no_rkm_medis
        JOIN satu_sehat_mapping_obat mo  ON mo.kode_brng   = rdrd.kode_brng
        JOIN satu_sehat_medication sm    ON sm.kode_brng   = rdrd.kode_brng
        LEFT JOIN satu_sehat_encounter se ON se.no_rawat   = rp.no_rawat
        LEFT JOIN medifix_ss_pasien msp  ON msp.no_rkm_medis = p.no_rkm_medis
        WHERE rdrd.no_resep = ? AND rdrd.kode_brng = ? AND rdrd.no_racik = ?
        LIMIT 1
    ");
    $stmt->execute([$jenisRawat, $noResep, $kodeBrng, $noRacik]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── Kirim obat biasa ──────────────────────────────────────────────
function kirimSatuMS(string $noResep, string $kodeBrng, string $jenisRawat, PDO $db): array {
    // Cek sudah terkirim
    $stmtCek = $db->prepare("SELECT id_medicationstatement FROM satu_sehat_medicationstatement WHERE no_resep=? AND kode_brng=? LIMIT 1");
    $stmtCek->execute([$noResep, $kodeBrng]);
    $existing = $stmtCek->fetchColumn();
    if (!empty($existing)) return ['status'=>'ok','id_ms'=>$existing,'note'=>'sudah ada'];

    $row = ambilDataMS($noResep, $kodeBrng, $jenisRawat, $db);
    if (!$row) {
        logMS("ERROR no_resep=$noResep kode_brng=$kodeBrng Data tidak ditemukan");
        return ['status'=>'error','message'=>"Data tidak ditemukan: no_resep=$noResep kode_brng=$kodeBrng"];
    }
    if (empty($row['id_encounter'])) {
        logMS("ERROR no_resep=$noResep Encounter belum ada");
        return ['status'=>'error','message'=>"Encounter belum ada — kirim Encounter dulu."];
    }
    if (empty($row['id_medication'])) {
        logMS("ERROR no_resep=$noResep kode_brng=$kodeBrng id_medication kosong");
        return ['status'=>'error','message'=>"Medication belum ada untuk kode_brng=$kodeBrng — kirim Medication dulu."];
    }
    if (empty($row['ihs_pasien'])) {
        logMS("WARN no_resep=$noResep IHS kosong, mencoba auto-sync...");
        $ihs = autoSyncIHSMS($row['no_rkm_medis'], $row['no_ktp']??'', $row['nm_pasien'], $db);
        if (empty($ihs)) return ['status'=>'error','message'=>"IHS pasien '{$row['nm_pasien']}' tidak ditemukan (NIK: {$row['no_ktp']})"];
        $row['ihs_pasien'] = $ihs;
    }

    try {
        $identifierValue = $noResep . '-' . $kodeBrng;
        $idMS = postMedicationStatement($row, $identifierValue, $db);

        $db->prepare("
            INSERT INTO satu_sehat_medicationstatement (no_resep, kode_brng, tgl_penyerahan, jam_penyerahan, id_medicationstatement)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE id_medicationstatement = VALUES(id_medicationstatement)
        ")->execute([$noResep, $kodeBrng, $row['tgl_penyerahan'], $row['jam_penyerahan'], $idMS]);

        logMS("OK no_resep=$noResep kode_brng=$kodeBrng id=$idMS");
        return ['status'=>'ok','id_ms'=>$idMS];
    } catch (Exception $e) {
        logMS("ERROR no_resep=$noResep kode_brng=$kodeBrng msg=".$e->getMessage());
        return ['status'=>'error','message'=>$e->getMessage()];
    }
}

// ── Kirim obat racikan ────────────────────────────────────────────
function kirimSatuMSRacikan(string $noResep, string $kodeBrng, string $noRacik, string $jenisRawat, PDO $db): array {
    $stmtCek = $db->prepare("SELECT id_medicationstatement FROM satu_sehat_medicationstatement_racikan WHERE no_resep=? AND kode_brng=? AND no_racik=? LIMIT 1");
    $stmtCek->execute([$noResep, $kodeBrng, $noRacik]);
    $existing = $stmtCek->fetchColumn();
    if (!empty($existing)) return ['status'=>'ok','id_ms'=>$existing,'note'=>'sudah ada'];

    $row = ambilDataMSRacikan($noResep, $kodeBrng, $noRacik, $jenisRawat, $db);
    if (!$row) {
        logMS("ERROR racikan no_resep=$noResep kode_brng=$kodeBrng no_racik=$noRacik Data tidak ditemukan");
        return ['status'=>'error','message'=>"Data racikan tidak ditemukan"];
    }
    if (empty($row['id_encounter'])) return ['status'=>'error','message'=>"Encounter belum ada."];
    if (empty($row['id_medication'])) return ['status'=>'error','message'=>"Medication belum ada untuk kode_brng=$kodeBrng."];
    if (empty($row['ihs_pasien'])) {
        $ihs = autoSyncIHSMS($row['no_rkm_medis'], $row['no_ktp']??'', $row['nm_pasien'], $db);
        if (empty($ihs)) return ['status'=>'error','message'=>"IHS pasien '{$row['nm_pasien']}' tidak ditemukan."];
        $row['ihs_pasien'] = $ihs;
    }

    try {
        $identifierValue = $noResep . '-' . $kodeBrng . '-' . $noRacik;
        $idMS = postMedicationStatement($row, $identifierValue, $db);

        $db->prepare("
            INSERT INTO satu_sehat_medicationstatement_racikan (no_resep, kode_brng, no_racik, id_medicationstatement)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE id_medicationstatement = VALUES(id_medicationstatement)
        ")->execute([$noResep, $kodeBrng, $noRacik, $idMS]);

        logMS("OK racikan no_resep=$noResep kode_brng=$kodeBrng no_racik=$noRacik id=$idMS");
        return ['status'=>'ok','id_ms'=>$idMS];
    } catch (Exception $e) {
        logMS("ERROR racikan no_resep=$noResep msg=".$e->getMessage());
        return ['status'=>'error','message'=>$e->getMessage()];
    }
}

// ── Routing ───────────────────────────────────────────────────────
$action    = $_POST['action']     ?? '';
$noResep   = trim($_POST['no_resep']   ?? '');
$kodeBrng  = trim($_POST['kode_brng']  ?? '');
$noRacik   = trim($_POST['no_racik']   ?? '');
$jenisRawat= trim($_POST['jenis_rawat'] ?? 'Ralan');

try {
    if ($action === 'kirim_ms') {
        if (!$noResep || !$kodeBrng) jsonOutMS(['status'=>'error','message'=>'Parameter tidak lengkap']);
        if (!empty($noRacik)) {
            jsonOutMS(kirimSatuMSRacikan($noResep, $kodeBrng, $noRacik, $jenisRawat, $pdo_simrs));
        } else {
            jsonOutMS(kirimSatuMS($noResep, $kodeBrng, $jenisRawat, $pdo_simrs));
        }
    }

    if ($action === 'kirim_semua_ms') {
        $tglDari   = trim($_POST['tgl_dari']   ?? date('Y-m-d'));
        $tglSampai = trim($_POST['tgl_sampai'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglDari))   $tglDari   = date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglSampai)) $tglSampai = date('Y-m-d');

        // Obat biasa Ralan + Ranap
        $stmtList = $pdo_simrs->prepare("
            SELECT rd.no_resep, rd.kode_brng, rp.status_lanjut AS jenis_rawat, '' AS no_racik
            FROM resep_dokter rd
            JOIN resep_obat ro              ON ro.no_resep    = rd.no_resep
            JOIN reg_periksa rp             ON rp.no_rawat    = ro.no_rawat
            JOIN satu_sehat_mapping_obat mo ON mo.kode_brng   = rd.kode_brng
            JOIN satu_sehat_medication sm   ON sm.kode_brng   = rd.kode_brng
            LEFT JOIN satu_sehat_encounter se ON se.no_rawat  = rp.no_rawat
            LEFT JOIN satu_sehat_medicationstatement ms
                ON ms.no_resep = rd.no_resep AND ms.kode_brng = rd.kode_brng
            WHERE rp.tgl_registrasi BETWEEN ? AND ?
              AND ro.tgl_penyerahan != '0000-00-00'
              AND se.id_encounter IS NOT NULL AND se.id_encounter != ''
              AND (ms.id_medicationstatement IS NULL OR ms.id_medicationstatement = '')
            UNION ALL
            SELECT rdrd.no_resep, rdrd.kode_brng, rp.status_lanjut AS jenis_rawat, rdrd.no_racik
            FROM resep_dokter_racikan_detail rdrd
            JOIN resep_dokter_racikan rdr    ON rdr.no_resep  = rdrd.no_resep AND rdr.no_racik = rdrd.no_racik
            JOIN resep_obat ro               ON ro.no_resep   = rdrd.no_resep
            JOIN reg_periksa rp              ON rp.no_rawat   = ro.no_rawat
            JOIN satu_sehat_mapping_obat mo  ON mo.kode_brng  = rdrd.kode_brng
            JOIN satu_sehat_medication sm    ON sm.kode_brng  = rdrd.kode_brng
            LEFT JOIN satu_sehat_encounter se ON se.no_rawat  = rp.no_rawat
            LEFT JOIN satu_sehat_medicationstatement_racikan msr
                ON msr.no_resep = rdrd.no_resep AND msr.kode_brng = rdrd.kode_brng AND msr.no_racik = rdrd.no_racik
            WHERE rp.tgl_registrasi BETWEEN ? AND ?
              AND ro.tgl_penyerahan != '0000-00-00'
              AND se.id_encounter IS NOT NULL AND se.id_encounter != ''
              AND (msr.id_medicationstatement IS NULL OR msr.id_medicationstatement = '')
        ");
        $stmtList->execute([$tglDari, $tglSampai, $tglDari, $tglSampai]);
        $list = $stmtList->fetchAll(PDO::FETCH_ASSOC);

        $berhasil = 0; $gagal = 0; $errors = [];
        foreach ($list as $item) {
            if (!empty($item['no_racik'])) {
                $res = kirimSatuMSRacikan($item['no_resep'], $item['kode_brng'], $item['no_racik'], $item['jenis_rawat'], $pdo_simrs);
            } else {
                $res = kirimSatuMS($item['no_resep'], $item['kode_brng'], $item['jenis_rawat'], $pdo_simrs);
            }
            if ($res['status'] === 'ok') $berhasil++;
            else { $gagal++; $errors[] = $item['no_resep'].'/'.$item['kode_brng'].': '.($res['message']??'?'); }
            usleep(300000);
        }

        logMS("KIRIM_SEMUA tgl=$tglDari/$tglSampai total=".count($list)." ok=$berhasil gagal=$gagal");
        jsonOutMS(['status'=>'ok','jumlah'=>count($list),'berhasil'=>$berhasil,'gagal'=>$gagal,'errors'=>$errors]);
    }

    jsonOutMS(['status'=>'error','message'=>"Action '$action' tidak dikenal"]);
} catch (Exception $e) {
    logMS("EXCEPTION action=$action msg=".$e->getMessage());
    jsonOutMS(['status'=>'error','message'=>$e->getMessage()]);
}