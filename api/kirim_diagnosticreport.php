<?php
/**
 * api/kirim_diagnosticreport.php
 * Handler AJAX untuk pengiriman DiagnosticReport Radiologi ke Satu Sehat
 * Di-include dari data_diagnosticreport.php
 *
 * Alur: Observation (hasil bacaan) → DiagnosticReport (referensi Observation di result)
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/env.php';
if (isset($pdo)) {
    loadSatuSehatConfig($pdo);
} elseif (isset($pdo_simrs)) {
    loadSatuSehatConfig($pdo_simrs);
}

if (!defined('SS_CLIENT_ID') || SS_CLIENT_ID === '') {
    echo json_encode(['status' => 'error', 'message' => 'Konfigurasi Satu Sehat belum diatur di tabel setting_satusehat']);
    exit;
}

// ── Helper ────────────────────────────────────────────────────────
function jsonOut(array $data): void {
    echo json_encode($data);
    exit;
}

function logDR(string $msg): void {
    $logDir  = __DIR__ . '/../logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    $logFile = $logDir . '/diagnosticreport_' . date('Y-m') . '.log';
    @file_put_contents($logFile, date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
}

// ── Ambil Access Token ────────────────────────────────────────────
function getSatuSehatToken(): string {
    $cacheFile = SS_TOKEN_CACHE_FILE;
    if (file_exists($cacheFile)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (!empty($cached['access_token']) && ($cached['expires_at'] ?? 0) > time() + 60) {
            return $cached['access_token'];
        }
    }
    $ch = curl_init(SS_OAUTH_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => SS_CLIENT_ID,
            'client_secret' => SS_CLIENT_SECRET,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT    => SS_CURL_TIMEOUT,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    if ($curlErr) throw new Exception("cURL error auth: $curlErr");
    if ($httpCode !== 200) throw new Exception("Auth gagal HTTP $httpCode: $resp");
    $data = json_decode($resp, true);
    if (empty($data['access_token'])) throw new Exception("Token kosong dari response auth");
    file_put_contents($cacheFile, json_encode([
        'access_token' => $data['access_token'],
        'expires_at'   => time() + (int)($data['expires_in'] ?? 3600),
    ]));
    return $data['access_token'];
}

// ── Step 1: Kirim Observation dari teks hasil bacaan ─────────────
// SATUSEHAT mewajibkan DiagnosticReport.result berisi referensi Observation.
// Observation dibuat dari kolom hasil_radiologi.hasil (teks bacaan dokter).
function kirimObservation(array $row, string $token): string
{
    $obsResource = [
        'resourceType' => 'Observation',
        'status'       => 'final',
        'category'     => [[
            'coding' => [[
                'system'  => 'http://terminology.hl7.org/CodeSystem/observation-category',
                'code'    => 'imaging',
                'display' => 'Imaging',
            ]],
        ]],
        'code' => [
            'coding' => [[
                'system'  => 'http://loinc.org',
                'code'    => '59776-5',
                'display' => 'Procedure findings Narrative',
            ]],
            'text' => 'Hasil Bacaan Radiologi',
        ],
        'subject' => [
            'reference' => 'Patient/' . ($row['ihs_number'] ?? ''),
            'display'   => $row['nm_pasien'] ?? '',
        ],
        'effectiveDateTime' => date('c', strtotime(
            ($row['tgl_periksa'] ?? date('Y-m-d')) . ' ' .
            ($row['jam_periksa'] ?? '00:00:00')
        )),
        'issued'    => date('c'),
        'performer' => [[
            'reference' => 'Organization/' . SS_ORG_ID,
        ]],
        'valueString' => $row['hasil'] ?? '',
    ];

    if (!empty($row['id_encounter'])) {
        $obsResource['encounter'] = ['reference' => 'Encounter/' . $row['id_encounter']];
    }

    if (SS_DEBUG) logDR("OBS PAYLOAD " . json_encode($obsResource));

    $ch = curl_init(SS_FHIR_URL . '/Observation');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($obsResource),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_TIMEOUT => SS_CURL_TIMEOUT,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) throw new Exception("cURL Observation error: $curlErr");

    $respData = json_decode($resp, true);
    if ($httpCode !== 200 && $httpCode !== 201) {
        $errMsg = $respData['issue'][0]['diagnostics']
               ?? $respData['issue'][0]['details']['text']
               ?? "Observation HTTP $httpCode";
        throw new Exception("Observation gagal: $errMsg");
    }

    $idObs = $respData['id'] ?? '';
    if (empty($idObs)) throw new Exception("ID Observation kosong dari response SATUSEHAT");

    logDR("OBS OK id_obs=$idObs");
    return $idObs;
}

// ── Step 2: Build + Kirim DiagnosticReport ────────────────────────
function kirimDiagnosticReport(array $row, string $idObservation, string $token): string
{
    $resource = [
        'resourceType' => 'DiagnosticReport',
        'status'       => 'final',
        'category'     => [[
            'coding' => [[
                'system'  => 'http://terminology.hl7.org/CodeSystem/v2-0074',
                'code'    => 'RAD',
                'display' => 'Radiology',
            ]],
        ]],
        'code' => [
            'coding' => [[
                'system'  => 'http://loinc.org',
                'code'    => '18748-4',
                'display' => 'Diagnostic imaging study',
            ]],
        ],
        'subject' => [
            'reference' => 'Patient/' . ($row['ihs_number'] ?? ''),
            'display'   => $row['nm_pasien'] ?? '',
        ],
        'effectiveDateTime' => date('c', strtotime(
            ($row['tgl_periksa'] ?? date('Y-m-d')) . ' ' .
            ($row['jam_periksa'] ?? '00:00:00')
        )),
        'issued'    => date('c'),
        'performer' => [[
            'reference' => 'Organization/' . SS_ORG_ID,
        ]],
        // result wajib — referensi Observation yang baru dibuat
        'result' => [[
            'reference' => 'Observation/' . $idObservation,
            'display'   => 'Hasil bacaan radiologi',
        ]],
        'conclusion' => $row['hasil'] ?? '',
    ];

    if (!empty($row['id_encounter'])) {
        $resource['encounter'] = ['reference' => 'Encounter/' . $row['id_encounter']];
    }
    if (!empty($row['id_servicerequest'])) {
        $resource['basedOn'] = [['reference' => 'ServiceRequest/' . $row['id_servicerequest']]];
    }
    if (!empty($row['id_imagingstudy'])) {
        $resource['imagingStudy'] = [['reference' => 'ImagingStudy/' . $row['id_imagingstudy']]];
    }

    if (SS_DEBUG) logDR("DR PAYLOAD " . json_encode($resource));

    $ch = curl_init(SS_FHIR_URL . '/DiagnosticReport');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($resource),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_TIMEOUT => SS_CURL_TIMEOUT,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) throw new Exception("cURL DiagnosticReport error: $curlErr");

    $respData = json_decode($resp, true);
    if ($httpCode !== 200 && $httpCode !== 201) {
        $errMsg = $respData['issue'][0]['diagnostics']
               ?? $respData['issue'][0]['details']['text']
               ?? ($respData['issue'][0]['details']['coding'][0]['display'] ?? null)
               ?? "DR HTTP $httpCode";
        throw new Exception("DiagnosticReport gagal: $errMsg");
    }

    $idDR = $respData['id'] ?? '';
    if (empty($idDR)) throw new Exception("ID DiagnosticReport kosong dari response SATUSEHAT");

    logDR("DR OK id_dr=$idDR");
    return $idDR;
}

// ── Kirim 1 pasien: Observation → DiagnosticReport ───────────────
function kirimSatuDR(string $noorder, string $kdJenisPrw, string $noRawat, PDO $pdo_simrs): array
{
    // Ambil data lengkap
    // Start dari satu_sehat_servicerequest_radiologi (Khanza) agar semua noorder
    // bisa ditemukan meski belum ada di satu_sehat_diagnosticreport_radiologi
    $stmt = $pdo_simrs->prepare("
        SELECT
            ssr.noorder,
            ssr.kd_jenis_prw,
            COALESCE(msr.id_servicerequest, ssr.id_servicerequest) AS id_servicerequest,
            msr.id_imagingstudy,
            pr.no_rawat,
            pr.diagnosa_klinis,
            hr.tgl_periksa,
            hr.jam   AS jam_periksa,
            hr.hasil,
            p.no_rkm_medis,
            p.nm_pasien,
            msp.ihs_number,
            se.id_encounter,
            sdr.id_diagnosticreport AS id_dr_khanza
        FROM satu_sehat_servicerequest_radiologi ssr
        JOIN permintaan_radiologi pr               ON ssr.noorder     = pr.noorder
        JOIN hasil_radiologi hr                    ON pr.no_rawat     = hr.no_rawat
        JOIN reg_periksa reg                       ON pr.no_rawat     = reg.no_rawat
        JOIN pasien p                              ON reg.no_rkm_medis = p.no_rkm_medis
        LEFT JOIN satu_sehat_diagnosticreport_radiologi sdr ON ssr.noorder = sdr.noorder
        LEFT JOIN medifix_ss_diagnosticreport_radiologi mdr ON ssr.noorder = mdr.noorder
        LEFT JOIN medifix_ss_radiologi msr          ON ssr.noorder     = msr.noorder
        LEFT JOIN medifix_ss_pasien msp             ON p.no_rkm_medis  = msp.no_rkm_medis
        LEFT JOIN satu_sehat_encounter se           ON pr.no_rawat     = se.no_rawat
        WHERE ssr.noorder = ?
        LIMIT 1
    ");
    $stmt->execute([$noorder]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row)                             return ['status' => 'error', 'message' => "Data tidak ditemukan noorder=$noorder"];
    if (empty(trim($row['hasil'] ?? '')))  return ['status' => 'error', 'message' => 'Hasil bacaan belum diisi dokter radiolog'];
    if (empty($row['ihs_number']))         return ['status' => 'error', 'message' => 'IHS Number pasien belum ada — sync IHS dulu di halaman SR'];

    // Cek apakah Khanza sudah kirim DR duluan — jika ya, cukup sync, jangan kirim ulang
    $idDRKhanza = $row['id_dr_khanza'] ?? '';
    if (!empty($idDRKhanza)) {
        $pdo_simrs->prepare("
            INSERT INTO medifix_ss_diagnosticreport_radiologi
                (noorder, kd_jenis_prw, no_rawat, id_diagnosticreport,
                 id_servicerequest, id_imagingstudy, status_kirim, tgl_kirim)
            VALUES (?, ?, ?, ?, ?, ?, 'terkirim', NOW())
            ON DUPLICATE KEY UPDATE
                id_diagnosticreport = VALUES(id_diagnosticreport),
                status_kirim        = 'terkirim',
                tgl_kirim           = NOW(),
                error_msg           = NULL,
                updated_at          = NOW()
        ")->execute([
            $noorder, $kdJenisPrw, $noRawat, $idDRKhanza,
            $row['id_servicerequest'] ?? null,
            $row['id_imagingstudy']   ?? null,
        ]);
        logDR("SYNC_KHANZA noorder=$noorder id_dr=$idDRKhanza (sudah ada di Khanza, tidak dikirim ulang)");
        return ['status' => 'ok', 'id_dr' => $idDRKhanza, 'source' => 'khanza'];
    }

    try {
        $token = getSatuSehatToken();

        // Step 1 — kirim Observation, dapat ID
        $idObservation = kirimObservation($row, $token);

        // Step 2 — kirim DiagnosticReport dengan result → Observation
        $idDR = kirimDiagnosticReport($row, $idObservation, $token);

        // Simpan hasil ke medifix_ss_diagnosticreport_radiologi
        $pdo_simrs->prepare("
            INSERT INTO medifix_ss_diagnosticreport_radiologi
                (noorder, kd_jenis_prw, no_rawat,
                 id_diagnosticreport, id_servicerequest, id_imagingstudy,
                 status_kirim, tgl_kirim, error_msg)
            VALUES (?, ?, ?, ?, ?, ?, 'terkirim', NOW(), NULL)
            ON DUPLICATE KEY UPDATE
                id_diagnosticreport = VALUES(id_diagnosticreport),
                id_servicerequest   = VALUES(id_servicerequest),
                id_imagingstudy     = VALUES(id_imagingstudy),
                status_kirim        = 'terkirim',
                tgl_kirim           = NOW(),
                error_msg           = NULL,
                updated_at          = NOW()
        ")->execute([
            $noorder, $kdJenisPrw, $noRawat, $idDR,
            $row['id_servicerequest'] ?? null,
            $row['id_imagingstudy']   ?? null,
        ]);

        // Balikkan ID ke tabel Khanza
        if (!empty($idDR)) {
            $pdo_simrs->prepare("
                UPDATE satu_sehat_diagnosticreport_radiologi
                SET id_diagnosticreport = ?
                WHERE noorder = ? AND kd_jenis_prw = ?
                  AND (id_diagnosticreport IS NULL OR id_diagnosticreport = '')
            ")->execute([$idDR, $noorder, $kdJenisPrw]);
        }

        logDR("SUKSES noorder=$noorder id_obs=$idObservation id_dr=$idDR");
        return ['status' => 'ok', 'id_dr' => $idDR, 'id_observation' => $idObservation];

    } catch (Exception $e) {
        $errMsg = $e->getMessage();

        $pdo_simrs->prepare("
            INSERT INTO medifix_ss_diagnosticreport_radiologi
                (noorder, kd_jenis_prw, no_rawat, status_kirim, tgl_kirim, error_msg)
            VALUES (?, ?, ?, 'error', NOW(), ?)
            ON DUPLICATE KEY UPDATE
                status_kirim = 'error',
                tgl_kirim    = NOW(),
                error_msg    = VALUES(error_msg),
                updated_at   = NOW()
        ")->execute([$noorder, $kdJenisPrw, $noRawat, $errMsg]);

        logDR("ERROR noorder=$noorder msg=$errMsg");
        return ['status' => 'error', 'message' => $errMsg];
    }
}

// ══════════════════════════════════════════════════════════════════
// ROUTING ACTION
// ══════════════════════════════════════════════════════════════════
$action = $_POST['action'] ?? '';

try {

    if ($action === 'kirim') {
        $noorder    = trim($_POST['noorder']      ?? '');
        $kdJenisPrw = trim($_POST['kd_jenis_prw'] ?? '');
        $noRawat    = trim($_POST['no_rawat']     ?? '');
        if (!$noorder || !$kdJenisPrw || !$noRawat) {
            jsonOut(['status' => 'error', 'message' => 'Parameter tidak lengkap']);
        }
        jsonOut(kirimSatuDR($noorder, $kdJenisPrw, $noRawat, $pdo_simrs));
    }

    if ($action === 'kirim_semua') {
        $tglDari   = $_POST['tgl_dari']   ?? date('Y-m-d');
        $tglSampai = $_POST['tgl_sampai'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglDari))   $tglDari   = date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglSampai)) $tglSampai = date('Y-m-d');

        // Ambil semua noorder yang SR sudah terkirim, ada hasil radiologi,
        // tapi DR belum terkirim — start dari satu_sehat_servicerequest_radiologi
        $stmtList = $pdo_simrs->prepare("
            SELECT ssr.noorder, ssr.kd_jenis_prw, pr.no_rawat
            FROM satu_sehat_servicerequest_radiologi ssr
            JOIN permintaan_radiologi pr    ON ssr.noorder = pr.noorder
            JOIN hasil_radiologi hr         ON pr.no_rawat = hr.no_rawat
            LEFT JOIN satu_sehat_diagnosticreport_radiologi sdr ON ssr.noorder = sdr.noorder
            LEFT JOIN medifix_ss_diagnosticreport_radiologi mdr ON ssr.noorder = mdr.noorder
            WHERE hr.tgl_periksa BETWEEN ? AND ?
              AND hr.hasil IS NOT NULL AND hr.hasil != ''
              AND (
                  mdr.status_kirim IN ('pending','error')
                  OR mdr.noorder IS NULL
                  OR (sdr.id_diagnosticreport IS NULL OR sdr.id_diagnosticreport = '')
              )
            ORDER BY hr.tgl_periksa ASC, hr.jam ASC
        ");
        $stmtList->execute([$tglDari, $tglSampai]);
        $list = $stmtList->fetchAll(PDO::FETCH_ASSOC);

        $berhasil = 0; $gagal = 0; $errors = [];
        foreach ($list as $item) {
            $res = kirimSatuDR($item['noorder'], $item['kd_jenis_prw'], $item['no_rawat'], $pdo_simrs);
            if ($res['status'] === 'ok') $berhasil++;
            else { $gagal++; $errors[] = $item['noorder'] . ': ' . ($res['message'] ?? ''); }
            usleep(400000); // 400ms jeda — 2 request per order (Observation + DR)
        }

        logDR("KIRIM_SEMUA tgl=$tglDari/$tglSampai total=" . count($list) . " ok=$berhasil gagal=$gagal");
        jsonOut(['status' => 'ok', 'jumlah' => count($list), 'berhasil' => $berhasil, 'gagal' => $gagal, 'errors' => $errors]);
    }

    jsonOut(['status' => 'error', 'message' => "Action '$action' tidak dikenal"]);

} catch (Exception $e) {
    logDR("EXCEPTION action=$action msg=" . $e->getMessage());
    jsonOut(['status' => 'error', 'message' => $e->getMessage()]);
}