<?php
/**
 * api/kirim_clinical.php
 * Kirim ClinicalImpression ke Satu Sehat
 * Sumber : pemeriksaan_ralan + pemeriksaan_ranap
 * Simpan : satu_sehat_clinicalimpression (no_rawat, tgl_perawatan, jam_rawat, status, id_clinicalimpression)
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

function jsonOutCI(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

function logCI(string $msg): void {
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents("$dir/clinical_" . date('Y-m') . ".log",
        date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
}

// ── Token ─────────────────────────────────────────────────────────
function getTokenCI(): string {
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

// ── GET IHS Practitioner ──────────────────────────────────────────
function getIHSDokterCI(string $nik, string $token): string {
    if (empty($nik)) return '';
    $ch = curl_init(SS_FHIR_URL . '/Practitioner?identifier=https://fhir.kemkes.go.id/id/nik|' . urlencode($nik));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch); curl_close($ch);
    return json_decode($resp, true)['entry'][0]['resource']['id'] ?? '';
}

// ── Auto-sync IHS Pasien ──────────────────────────────────────────
function autoSyncIHSCI(string $noRkm, string $nik, string $nmPasien, PDO $db): string {
    if (empty($nik)) return '';
    try {
        $token = getTokenCI();
        $url   = SS_FHIR_URL . '/Patient?identifier=https://fhir.kemkes.go.id/id/nik|' . urlencode($nik);
        $ch    = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
            CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $data = json_decode(curl_exec($ch), true);
        curl_close($ch);
        $ihs = $data['entry'][0]['resource']['id'] ?? '';
        if (!empty($ihs)) {
            $db->prepare("INSERT INTO medifix_ss_pasien (no_rkm_medis, ihs_number, tgl_sync, status_sync, error_msg) VALUES (?,?,NOW(),'ditemukan','') ON DUPLICATE KEY UPDATE ihs_number=VALUES(ihs_number),tgl_sync=NOW(),status_sync='ditemukan',error_msg=''")->execute([$noRkm, $ihs]);
            logCI("OK auto-sync IHS no_rkm=$noRkm nik=$nik ihs=$ihs nm=$nmPasien");
        }
        return $ihs;
    } catch (Exception $e) {
        logCI("ERROR auto-sync IHS no_rkm=$noRkm: " . $e->getMessage());
        return '';
    }
}

// ── Kirim 1 ClinicalImpression ────────────────────────────────────
function kirimSatuClinical(
    string $noRawat,
    string $tglPerawatan,
    string $jamRawat,
    string $status,   // Ralan | Ranap
    PDO $db
): array {
    // Cek sudah terkirim
    $stmtCek = $db->prepare("
        SELECT id_clinicalimpression FROM satu_sehat_clinicalimpression
        WHERE no_rawat=? AND tgl_perawatan=? AND jam_rawat=? AND status=? LIMIT 1
    ");
    $stmtCek->execute([$noRawat, $tglPerawatan, $jamRawat, $status]);
    $existing = $stmtCek->fetchColumn();
    if (!empty($existing)) {
        return ['status' => 'ok', 'id_clinical' => $existing, 'note' => 'sudah ada'];
    }

    $tbl = $status === 'Ranap' ? 'pemeriksaan_ranap' : 'pemeriksaan_ralan';

    // Ambil data
    $stmt = $db->prepare("
        SELECT
            rp.no_rawat, rp.no_rkm_medis, rp.tgl_registrasi, rp.jam_reg,
            p.nm_pasien, p.no_ktp,
            pg.nama AS nm_dokter, pg.no_ktp AS ktp_dokter,
            pr.tgl_perawatan, pr.jam_rawat,
            pr.keluhan, pr.pemeriksaan, pr.penilaian,
            se.id_encounter,
            IFNULL(msp.ihs_number,'') AS ihs_pasien,
            -- Condition: ambil yang statusnya sesuai
            sc.kd_penyakit, sc.id_condition,
            py.nm_penyakit
        FROM $tbl pr
        JOIN reg_periksa rp              ON rp.no_rawat      = pr.no_rawat
        JOIN pasien p                    ON p.no_rkm_medis   = rp.no_rkm_medis
        JOIN pegawai pg                  ON pg.nik            = pr.nip
        LEFT JOIN satu_sehat_encounter se ON se.no_rawat      = pr.no_rawat
        LEFT JOIN medifix_ss_pasien msp  ON msp.no_rkm_medis = p.no_rkm_medis
        LEFT JOIN satu_sehat_condition sc ON sc.no_rawat      = pr.no_rawat
                                        AND sc.status         = ?
        LEFT JOIN penyakit py            ON py.kd_penyakit    = sc.kd_penyakit
        WHERE pr.no_rawat = ? AND pr.tgl_perawatan = ? AND pr.jam_rawat = ?
        LIMIT 1
    ");
    $stmt->execute([$status, $noRawat, $tglPerawatan, $jamRawat]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        logCI("ERROR no_rawat=$noRawat tgl=$tglPerawatan jam=$jamRawat status=$status Data tidak ditemukan");
        return ['status' => 'error', 'message' => "Data tidak ditemukan: no_rawat=$noRawat tgl=$tglPerawatan jam=$jamRawat"];
    }
    if (empty($row['id_encounter'])) {
        logCI("ERROR no_rawat=$noRawat Encounter belum ada");
        return ['status' => 'error', 'message' => "Encounter belum ada untuk no_rawat=$noRawat — kirim Encounter dulu."];
    }

    // Auto-sync IHS jika kosong
    if (empty($row['ihs_pasien'])) {
        logCI("WARN no_rawat=$noRawat IHS kosong untuk {$row['nm_pasien']}, mencoba auto-sync...");
        $ihs = autoSyncIHSCI($row['no_rkm_medis'], $row['no_ktp'] ?? '', $row['nm_pasien'], $db);
        if (empty($ihs)) {
            logCI("ERROR no_rawat=$noRawat IHS tidak ditemukan nik={$row['no_ktp']} nm={$row['nm_pasien']}");
            return ['status' => 'error', 'message' => "IHS pasien '{$row['nm_pasien']}' tidak ditemukan (NIK: {$row['no_ktp']})"];
        }
        $row['ihs_pasien'] = $ihs;
    }

    try {
        $token     = getTokenCI();
        $ihsDokter = getIHSDokterCI($row['ktp_dokter'], $token);
        if (empty($ihsDokter)) {
            logCI("ERROR no_rawat=$noRawat IHS dokter tidak ditemukan ktp={$row['ktp_dokter']}");
            return ['status' => 'error', 'message' => "IHS dokter '{$row['nm_dokter']}' tidak ditemukan. KTP: {$row['ktp_dokter']}"];
        }

        // Format waktu
        $jamStr = $row['jam_rawat'];
        if (strlen($jamStr) === 5) $jamStr .= ':00';
        $effectiveDateTime = $row['tgl_perawatan'] . 'T' . $jamStr . '+07:00';

        // Teks description: gabungan keluhan + pemeriksaan
        $keluhan    = trim($row['keluhan']    ?? '');
        $pemeriksaan= trim($row['pemeriksaan'] ?? '');
        $penilaian  = trim($row['penilaian']  ?? '');
        $description = trim(implode(', ', array_filter([$keluhan, $pemeriksaan])));
        if (empty($description)) $description = $penilaian;

        // Bersihkan newline/tab untuk JSON
        $description = preg_replace('/[\r\n\t]+/', ' ', $description);
        $penilaian   = preg_replace('/[\r\n\t]+/', ' ', $penilaian);

        // Payload
        $payload = [
            'resourceType' => 'ClinicalImpression',
            'status'       => 'completed',
            'description'  => $description,
            'subject'      => [
                'reference' => 'Patient/' . $row['ihs_pasien'],
                'display'   => $row['nm_pasien'],
            ],
            'encounter'    => [
                'reference' => 'Encounter/' . $row['id_encounter'],
                'display'   => 'Kunjungan ' . $row['nm_pasien'] . ' pada tanggal ' . $row['tgl_registrasi'] . ' dengan nomor kunjungan ' . $noRawat,
            ],
            'effectiveDateTime' => $effectiveDateTime,
            'date'              => $effectiveDateTime,
            'assessor'          => [
                'reference' => 'Practitioner/' . $ihsDokter,
            ],
            'summary' => $penilaian,
            'prognosisCodeableConcept' => [[
                'coding' => [[
                    'system'  => 'http://terminology.kemkes.go.id/CodeSystem/clinical-term',
                    'code'    => 'PR000001',
                    'display' => 'Prognosis',
                ]],
            ]],
        ];

        // Tambah finding hanya jika ada condition
        if (!empty($row['id_condition']) && !empty($row['kd_penyakit'])) {
            $payload['finding'] = [[
                'itemCodeableConcept' => [
                    'coding' => [[
                        'system'  => 'http://hl7.org/fhir/sid/icd-10',
                        'code'    => $row['kd_penyakit'],
                        'display' => $row['nm_penyakit'] ?? $row['kd_penyakit'],
                    ]],
                ],
                'itemReference' => [
                    'reference' => 'Condition/' . $row['id_condition'],
                ],
            ]];
        }

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        logCI("SEND no_rawat=$noRawat tgl=$tglPerawatan status=$status penilaian=" . substr($penilaian, 0, 60));

        $ch = curl_init(SS_FHIR_URL . '/ClinicalImpression');
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

        logCI("RESPONSE HTTP=$httpCode BODY=" . substr($resp, 0, 300));
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
            $idClinical = $respData['id'] ?? '';
            if (empty($idClinical)) throw new Exception("Response sukses tapi id kosong");

            $db->prepare("
                INSERT INTO satu_sehat_clinicalimpression
                    (no_rawat, tgl_perawatan, jam_rawat, status, id_clinicalimpression)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE id_clinicalimpression = VALUES(id_clinicalimpression)
            ")->execute([$noRawat, $tglPerawatan, $jamRawat, $status, $idClinical]);

            logCI("OK no_rawat=$noRawat tgl=$tglPerawatan status=$status id=$idClinical");
            return ['status' => 'ok', 'id_clinical' => $idClinical];
        }

        $errMsg = ($respData['issue'][0]['diagnostics'] ?? '')
               ?: ($respData['issue'][0]['details']['text'] ?? '')
               ?: "HTTP $httpCode: " . substr($resp, 0, 200);
        throw new Exception($errMsg);

    } catch (Exception $e) {
        logCI("ERROR no_rawat=$noRawat msg=" . $e->getMessage());
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

// ── Routing ───────────────────────────────────────────────────────
$action       = $_POST['action']        ?? '';
$noRawat      = trim($_POST['no_rawat']       ?? '');
$tglPerawatan = trim($_POST['tgl_perawatan']  ?? '');
$jamRawat     = trim($_POST['jam_rawat']      ?? '');
$status       = trim($_POST['status']         ?? 'Ralan');

try {
    if ($action === 'kirim_clinical') {
        if (!$noRawat || !$tglPerawatan || !$jamRawat)
            jsonOutCI(['status' => 'error', 'message' => 'Parameter tidak lengkap']);
        jsonOutCI(kirimSatuClinical($noRawat, $tglPerawatan, $jamRawat, $status, $pdo_simrs));
    }

    if ($action === 'kirim_semua_clinical') {
        $tglDari   = trim($_POST['tgl_dari']   ?? date('Y-m-d'));
        $tglSampai = trim($_POST['tgl_sampai'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglDari))   $tglDari   = date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglSampai)) $tglSampai = date('Y-m-d');

        // Ambil ralan + ranap yang belum terkirim dan sudah ada encounter
        $stmtList = $pdo_simrs->prepare("
            SELECT pr.no_rawat, pr.tgl_perawatan, pr.jam_rawat, 'Ralan' AS status
            FROM pemeriksaan_ralan pr
            JOIN reg_periksa rp          ON rp.no_rawat = pr.no_rawat
            JOIN satu_sehat_encounter se  ON se.no_rawat = pr.no_rawat
            LEFT JOIN satu_sehat_clinicalimpression ci
                ON ci.no_rawat = pr.no_rawat AND ci.tgl_perawatan = pr.tgl_perawatan
                AND ci.jam_rawat = pr.jam_rawat AND ci.status = 'Ralan'
            WHERE rp.tgl_registrasi BETWEEN ? AND ?
              AND pr.penilaian IS NOT NULL AND pr.penilaian != ''
              AND (ci.id_clinicalimpression IS NULL OR ci.id_clinicalimpression = '')
            UNION ALL
            SELECT pr.no_rawat, pr.tgl_perawatan, pr.jam_rawat, 'Ranap' AS status
            FROM pemeriksaan_ranap pr
            JOIN reg_periksa rp          ON rp.no_rawat = pr.no_rawat
            JOIN satu_sehat_encounter se  ON se.no_rawat = pr.no_rawat
            LEFT JOIN satu_sehat_clinicalimpression ci
                ON ci.no_rawat = pr.no_rawat AND ci.tgl_perawatan = pr.tgl_perawatan
                AND ci.jam_rawat = pr.jam_rawat AND ci.status = 'Ranap'
            WHERE rp.tgl_registrasi BETWEEN ? AND ?
              AND pr.penilaian IS NOT NULL AND pr.penilaian != ''
              AND (ci.id_clinicalimpression IS NULL OR ci.id_clinicalimpression = '')
        ");
        $stmtList->execute([$tglDari, $tglSampai, $tglDari, $tglSampai]);
        $list = $stmtList->fetchAll(PDO::FETCH_ASSOC);

        $berhasil = 0; $gagal = 0; $errors = [];
        foreach ($list as $item) {
            $res = kirimSatuClinical(
                $item['no_rawat'], $item['tgl_perawatan'],
                $item['jam_rawat'], $item['status'], $pdo_simrs
            );
            if ($res['status'] === 'ok') $berhasil++;
            else {
                $gagal++;
                $errors[] = $item['no_rawat'] . '/' . $item['status'] . ': ' . ($res['message'] ?? '?');
            }
            usleep(300000);
        }

        logCI("KIRIM_SEMUA tgl=$tglDari/$tglSampai total=" . count($list) . " ok=$berhasil gagal=$gagal");
        jsonOutCI(['status' => 'ok', 'jumlah' => count($list), 'berhasil' => $berhasil, 'gagal' => $gagal, 'errors' => $errors]);
    }

    jsonOutCI(['status' => 'error', 'message' => "Action '$action' tidak dikenal"]);

} catch (Exception $e) {
    logCI("EXCEPTION action=$action msg=" . $e->getMessage());
    jsonOutCI(['status' => 'error', 'message' => $e->getMessage()]);
}