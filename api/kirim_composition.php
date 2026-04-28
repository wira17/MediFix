<?php
/**
 * api/kirim_composition.php
 * Kirim Composition (Modul Gizi/Diet) ke Satu Sehat
 * Sumber: catatan_adime_gizi
 * Simpan ke: satu_sehat_diet (no_rawat, tanggal, id_diet)
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

function jsonOutC(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

function logCOMP(string $msg): void {
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents("$dir/composition_" . date('Y-m') . ".log",
        date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
}

// ── Token ─────────────────────────────────────────────────────────
function getTokenCOMP(): string {
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
function getIHSDokterCOMP(string $nik, string $token): string {
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

// ── Kirim 1 Composition ───────────────────────────────────────────
function kirimSatuComposition(string $noRawat, string $tanggal, PDO $db): array
{
    // Cek sudah terkirim
    $stmtCek = $db->prepare("SELECT id_diet FROM satu_sehat_diet WHERE no_rawat = ? AND tanggal = ? LIMIT 1");
    $stmtCek->execute([$noRawat, $tanggal]);
    $existing = $stmtCek->fetchColumn();
    if (!empty($existing)) {
        return ['status' => 'ok', 'id_diet' => $existing, 'note' => 'sudah ada'];
    }

    // Ambil data
    $stmt = $db->prepare("
        SELECT
            rp.no_rawat, rp.no_rkm_medis, rp.tgl_registrasi, rp.jam_reg,
            p.nm_pasien, p.no_ktp,
            pg.nama AS nm_dokter, pg.no_ktp AS ktp_dokter,
            cag.tanggal, cag.instruksi, cag.asesmen, cag.diagnosis,
            cag.intervensi, cag.monitoring, cag.evaluasi,
            se.id_encounter,
            IFNULL(msp.ihs_number,'') AS ihs_pasien
        FROM catatan_adime_gizi cag
        JOIN reg_periksa rp          ON rp.no_rawat      = cag.no_rawat
        JOIN pasien p                ON p.no_rkm_medis   = rp.no_rkm_medis
        JOIN pegawai pg              ON pg.nik            = cag.nip
        JOIN satu_sehat_encounter se ON se.no_rawat       = cag.no_rawat
        LEFT JOIN medifix_ss_pasien msp ON msp.no_rkm_medis = p.no_rkm_medis
        WHERE cag.no_rawat = ? AND cag.tanggal = ?
        LIMIT 1
    ");
    $stmt->execute([$noRawat, $tanggal]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        logCOMP("ERROR no_rawat=$noRawat tanggal=$tanggal Data tidak ditemukan");
        return ['status' => 'error', 'message' => "Data tidak ditemukan: no_rawat=$noRawat tanggal=$tanggal"];
    }
    if (empty($row['id_encounter'])) {
        logCOMP("ERROR no_rawat=$noRawat Encounter belum ada");
        return ['status' => 'error', 'message' => "Encounter belum ada untuk no_rawat=$noRawat — kirim Encounter dulu."];
    }
    if (empty($row['ihs_pasien'])) {
        // Auto-sync IHS
        logCOMP("WARN no_rawat=$noRawat IHS kosong untuk {$row['nm_pasien']}, mencoba auto-sync...");
        try {
            $tokenTmp = getTokenCOMP();
            $nikPasien = trim($row['no_ktp'] ?? '');
            if (!empty($nikPasien)) {
                $urlPat = SS_FHIR_URL . '/Patient?identifier=https://fhir.kemkes.go.id/id/nik|' . urlencode($nikPasien);
                $chPat  = curl_init($urlPat);
                curl_setopt_array($chPat, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $tokenTmp, 'Accept: application/json'],
                    CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false,
                ]);
                $patData = json_decode(curl_exec($chPat), true);
                curl_close($chPat);
                $ihsAuto = $patData['entry'][0]['resource']['id'] ?? '';
                if (!empty($ihsAuto)) {
                    $db->prepare("INSERT INTO medifix_ss_pasien (no_rkm_medis, ihs_number, tgl_sync, status_sync, error_msg) VALUES (?,?,NOW(),'ditemukan','') ON DUPLICATE KEY UPDATE ihs_number=VALUES(ihs_number),tgl_sync=NOW(),status_sync='ditemukan',error_msg=''")->execute([$row['no_rkm_medis'], $ihsAuto]);
                    $row['ihs_pasien'] = $ihsAuto;
                    logCOMP("OK auto-sync IHS no_rkm={$row['no_rkm_medis']} ihs=$ihsAuto");
                } else {
                    logCOMP("ERROR no_rawat=$noRawat IHS tidak ditemukan nik=$nikPasien nm={$row['nm_pasien']}");
                    return ['status' => 'error', 'message' => "IHS pasien '{$row['nm_pasien']}' tidak ditemukan di Satu Sehat (NIK: $nikPasien)"];
                }
            } else {
                logCOMP("ERROR no_rawat=$noRawat NIK pasien kosong nm={$row['nm_pasien']}");
                return ['status' => 'error', 'message' => "NIK pasien '{$row['nm_pasien']}' kosong di SIMRS."];
            }
        } catch (Exception $e) {
            logCOMP("ERROR no_rawat=$noRawat auto-sync IHS gagal: " . $e->getMessage());
            return ['status' => 'error', 'message' => "Gagal auto-sync IHS: " . $e->getMessage()];
        }
    }

    try {
        $token     = getTokenCOMP();
        $ihsDokter = getIHSDokterCOMP($row['ktp_dokter'], $token);
        if (empty($ihsDokter)) {
            logCOMP("ERROR no_rawat=$noRawat IHS dokter tidak ditemukan ktp={$row['ktp_dokter']}");
            return ['status' => 'error', 'message' => "IHS dokter '{$row['nm_dokter']}' tidak ditemukan. KTP: {$row['ktp_dokter']}"];
        }

        // Format tanggal — sesuai Khanza: tanggal + "01+07:00"
        $tglFormatted = date('Y-m-d', strtotime($row['tanggal']));
        $jamFormatted = date('H:i:s', strtotime($row['tanggal']));
        $dateComposition = $tglFormatted . 'T' . $jamFormatted . '+07:00';

        // Buat konten section dari instruksi + data ADIME
        $instruksi  = trim($row['instruksi'] ?? '');
        $asesmen    = trim($row['asesmen']    ?? '');
        $diagnosis  = trim($row['diagnosis']  ?? '');
        $intervensi = trim($row['intervensi'] ?? '');
        $monitoring = trim($row['monitoring'] ?? '');
        $evaluasi   = trim($row['evaluasi']   ?? '');

        // Buat teks HTML untuk section
        $divContent = '<div xmlns="http://www.w3.org/1999/xhtml">';
        if ($instruksi)  $divContent .= '<p><b>Instruksi Diet:</b> ' . htmlspecialchars($instruksi) . '</p>';
        if ($asesmen)    $divContent .= '<p><b>Asesmen:</b> ' . htmlspecialchars($asesmen) . '</p>';
        if ($diagnosis)  $divContent .= '<p><b>Diagnosis Gizi:</b> ' . htmlspecialchars($diagnosis) . '</p>';
        if ($intervensi) $divContent .= '<p><b>Intervensi:</b> ' . htmlspecialchars($intervensi) . '</p>';
        if ($monitoring) $divContent .= '<p><b>Monitoring:</b> ' . htmlspecialchars($monitoring) . '</p>';
        if ($evaluasi)   $divContent .= '<p><b>Evaluasi:</b> ' . htmlspecialchars($evaluasi) . '</p>';
        $divContent .= '</div>';

        $payload = [
            'resourceType' => 'Composition',
            'identifier'   => [
                'system' => 'http://sys-ids.kemkes.go.id/composition/' . SS_ORG_ID,
                'value'  => $noRawat,
            ],
            'status' => 'final',
            'type'   => [
                'coding' => [[
                    'system'  => 'http://loinc.org',
                    'code'    => '18842-5',
                    'display' => 'Discharge summary',
                ]],
            ],
            'category' => [[
                'coding' => [[
                    'system'  => 'http://loinc.org',
                    'code'    => 'LP173421-1',
                    'display' => 'Report',
                ]],
            ]],
            'subject' => [
                'reference' => 'Patient/' . $row['ihs_pasien'],
                'display'   => $row['nm_pasien'],
            ],
            'encounter' => [
                'reference' => 'Encounter/' . $row['id_encounter'],
                'display'   => 'Kunjungan ' . $row['nm_pasien'] . ' pada tanggal ' . $row['tgl_registrasi'] . ' dengan nomor kunjungan ' . $noRawat,
            ],
            'date'   => $dateComposition,
            'author' => [[
                'reference' => 'Practitioner/' . $ihsDokter,
                'display'   => $row['nm_dokter'],
            ]],
            'title'     => 'Modul Gizi',
            'custodian' => [
                'reference' => 'Organization/' . SS_ORG_ID,
            ],
            'section' => [[
                'code' => [
                    'coding' => [[
                        'system'  => 'http://loinc.org',
                        'code'    => '42344-2',
                        'display' => 'Discharge diet (narrative)',
                    ]],
                ],
                'text' => [
                    'status' => 'additional',
                    'div'    => $divContent,
                ],
            ]],
        ];

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        logCOMP("SEND no_rawat=$noRawat tanggal=$tanggal instruksi=" . substr($instruksi, 0, 60));

        $ch = curl_init(SS_FHIR_URL . '/Composition');
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

        logCOMP("RESPONSE HTTP=$httpCode BODY=" . substr($resp, 0, 300));
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
            $idDiet = $respData['id'] ?? '';
            if (empty($idDiet)) throw new Exception("Response sukses tapi id kosong");

            $db->prepare("
                INSERT INTO satu_sehat_diet (no_rawat, tanggal, id_diet)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE id_diet = VALUES(id_diet)
            ")->execute([$noRawat, $tanggal, $idDiet]);

            logCOMP("OK no_rawat=$noRawat tanggal=$tanggal id_diet=$idDiet");
            return ['status' => 'ok', 'id_diet' => $idDiet];
        }

        $errMsg = ($respData['issue'][0]['diagnostics'] ?? '')
               ?: ($respData['issue'][0]['details']['text'] ?? '')
               ?: "HTTP $httpCode: " . substr($resp, 0, 200);
        throw new Exception($errMsg);

    } catch (Exception $e) {
        logCOMP("ERROR no_rawat=$noRawat msg=" . $e->getMessage());
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

// ── Routing ───────────────────────────────────────────────────────
$action   = $_POST['action']   ?? '';
$noRawat  = trim($_POST['no_rawat']  ?? '');
$tanggal  = trim($_POST['tanggal']   ?? '');

try {
    if ($action === 'kirim_composition') {
        if (!$noRawat || !$tanggal)
            jsonOutC(['status' => 'error', 'message' => 'Parameter tidak lengkap']);
        jsonOutC(kirimSatuComposition($noRawat, $tanggal, $pdo_simrs));
    }

    if ($action === 'kirim_semua_composition') {
        $tglDari   = trim($_POST['tgl_dari']   ?? date('Y-m-d'));
        $tglSampai = trim($_POST['tgl_sampai'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglDari))   $tglDari   = date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglSampai)) $tglSampai = date('Y-m-d');

        $stmtList = $pdo_simrs->prepare("
            SELECT cag.no_rawat, cag.tanggal
            FROM catatan_adime_gizi cag
            JOIN reg_periksa rp         ON rp.no_rawat = cag.no_rawat
            JOIN satu_sehat_encounter se ON se.no_rawat = cag.no_rawat
            LEFT JOIN satu_sehat_diet sd ON sd.no_rawat = cag.no_rawat AND sd.tanggal = cag.tanggal
            WHERE rp.tgl_registrasi BETWEEN ? AND ?
              AND cag.instruksi IS NOT NULL AND cag.instruksi != ''
              AND (sd.id_diet IS NULL OR sd.id_diet = '')
        ");
        $stmtList->execute([$tglDari, $tglSampai]);
        $list = $stmtList->fetchAll(PDO::FETCH_ASSOC);

        $berhasil = 0; $gagal = 0; $errors = [];
        foreach ($list as $item) {
            $res = kirimSatuComposition($item['no_rawat'], $item['tanggal'], $pdo_simrs);
            if ($res['status'] === 'ok') $berhasil++;
            else {
                $gagal++;
                $errors[] = $item['no_rawat'] . ': ' . ($res['message'] ?? '?');
            }
            usleep(300000);
        }

        logCOMP("KIRIM_SEMUA tgl=$tglDari/$tglSampai total=" . count($list) . " ok=$berhasil gagal=$gagal");
        jsonOutC(['status' => 'ok', 'jumlah' => count($list), 'berhasil' => $berhasil, 'gagal' => $gagal, 'errors' => $errors]);
    }

    jsonOutC(['status' => 'error', 'message' => "Action '$action' tidak dikenal"]);

} catch (Exception $e) {
    logCOMP("EXCEPTION action=$action msg=" . $e->getMessage());
    jsonOutC(['status' => 'error', 'message' => $e->getMessage()]);
}