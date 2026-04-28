<?php
/**
 * api/kirim_questionnaireresponse.php
 * Kirim QuestionnaireResponse (Telaah Farmasi) ke Satu Sehat
 * Sumber : telaah_farmasi + resep_obat
 * Simpan : satu_sehat_questionresponse_telaah_farmasi (no_resep, id_questionresponse)
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

function jsonOutQR(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

function logQR(string $msg): void {
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents("$dir/questionnaire_" . date('Y-m') . ".log",
        date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
}

// ── Token ─────────────────────────────────────────────────────────
function getTokenQR(): string {
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
function getIHSDokterQR(string $nik, string $token): string {
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
function autoSyncIHSQR(string $noRkm, string $nik, string $nmPasien, PDO $db): string {
    if (empty($nik)) return '';
    try {
        $token = getTokenQR();
        $ch    = curl_init(SS_FHIR_URL . '/Patient?identifier=https://fhir.kemkes.go.id/id/nik|' . urlencode($nik));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
            CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $data = json_decode(curl_exec($ch), true); curl_close($ch);
        $ihs  = $data['entry'][0]['resource']['id'] ?? '';
        if (!empty($ihs)) {
            $db->prepare("INSERT INTO medifix_ss_pasien (no_rkm_medis,ihs_number,tgl_sync,status_sync,error_msg) VALUES(?,?,NOW(),'ditemukan','') ON DUPLICATE KEY UPDATE ihs_number=VALUES(ihs_number),tgl_sync=NOW(),status_sync='ditemukan',error_msg=''")->execute([$noRkm, $ihs]);
            logQR("OK auto-sync IHS no_rkm=$noRkm nik=$nik ihs=$ihs nm=$nmPasien");
        }
        return $ihs;
    } catch (Exception $e) {
        logQR("ERROR auto-sync IHS no_rkm=$noRkm: " . $e->getMessage());
        return '';
    }
}

// ── Helper: buat item answer ──────────────────────────────────────
function qrItem(string $linkId, string $text, string $value): array {
    return [
        'linkId' => $linkId,
        'text'   => $text,
        'answer' => [['valueString' => $value ?: '']],
    ];
}

// ── Kirim 1 QuestionnaireResponse ─────────────────────────────────
function kirimSatuQR(string $noResep, PDO $db): array
{
    // Cek sudah terkirim
    $stmtCek = $db->prepare("SELECT id_questionresponse FROM satu_sehat_questionresponse_telaah_farmasi WHERE no_resep = ? LIMIT 1");
    $stmtCek->execute([$noResep]);
    $existing = $stmtCek->fetchColumn();
    if (!empty($existing)) {
        return ['status' => 'ok', 'id_qr' => $existing, 'note' => 'sudah ada'];
    }

    // Ambil data
    $stmt = $db->prepare("
        SELECT
            rp.no_rawat, rp.no_rkm_medis, rp.tgl_registrasi, rp.jam_reg,
            p.nm_pasien, p.no_ktp,
            pg.nama AS nm_dokter, pg.no_ktp AS ktp_dokter,
            ro.no_resep, ro.tgl_peresepan, ro.jam_peresepan,
            se.id_encounter,
            IFNULL(msp.ihs_number,'') AS ihs_pasien,
            -- Telaah Resep
            tf.resep_identifikasi_pasien, tf.resep_ket_identifikasi_pasien,
            tf.resep_tepat_obat, tf.resep_ket_tepat_obat,
            tf.resep_tepat_dosis, tf.resep_ket_tepat_dosis,
            tf.resep_tepat_cara_pemberian, tf.resep_ket_tepat_cara_pemberian,
            tf.resep_tepat_waktu_pemberian, tf.resep_ket_tepat_waktu_pemberian,
            tf.resep_ada_tidak_duplikasi_obat, tf.resep_ket_ada_tidak_duplikasi_obat,
            tf.resep_interaksi_obat, tf.resep_ket_interaksi_obat,
            tf.resep_kontra_indikasi_obat, tf.resep_ket_kontra_indikasi_obat,
            -- Telaah Obat
            tf.obat_tepat_pasien, tf.obat_tepat_obat, tf.obat_tepat_dosis,
            tf.obat_tepat_cara_pemberian, tf.obat_tepat_waktu_pemberian
        FROM resep_obat ro
        JOIN reg_periksa rp             ON rp.no_rawat      = ro.no_rawat
        JOIN pasien p                   ON p.no_rkm_medis   = rp.no_rkm_medis
        JOIN telaah_farmasi tf          ON tf.no_resep       = ro.no_resep
        JOIN pegawai pg                 ON pg.nik            = tf.nip
        LEFT JOIN satu_sehat_encounter se ON se.no_rawat     = ro.no_rawat
        LEFT JOIN medifix_ss_pasien msp ON msp.no_rkm_medis  = p.no_rkm_medis
        WHERE ro.no_resep = ?
        LIMIT 1
    ");
    $stmt->execute([$noResep]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        logQR("ERROR no_resep=$noResep Data tidak ditemukan");
        return ['status' => 'error', 'message' => "Data tidak ditemukan untuk no_resep=$noResep"];
    }
    if (empty($row['id_encounter'])) {
        logQR("ERROR no_resep=$noResep Encounter belum ada no_rawat={$row['no_rawat']}");
        return ['status' => 'error', 'message' => "Encounter belum ada untuk no_rawat={$row['no_rawat']} — kirim Encounter dulu."];
    }

    // Auto-sync IHS jika kosong
    if (empty($row['ihs_pasien'])) {
        logQR("WARN no_resep=$noResep IHS kosong untuk {$row['nm_pasien']}, mencoba auto-sync...");
        $ihs = autoSyncIHSQR($row['no_rkm_medis'], $row['no_ktp'] ?? '', $row['nm_pasien'], $db);
        if (empty($ihs)) {
            logQR("ERROR no_resep=$noResep IHS tidak ditemukan nik={$row['no_ktp']}");
            return ['status' => 'error', 'message' => "IHS pasien '{$row['nm_pasien']}' tidak ditemukan (NIK: {$row['no_ktp']})"];
        }
        $row['ihs_pasien'] = $ihs;
    }

    try {
        $token     = getTokenQR();
        $ihsDokter = getIHSDokterQR($row['ktp_dokter'], $token);
        if (empty($ihsDokter)) {
            logQR("ERROR no_resep=$noResep IHS dokter tidak ditemukan ktp={$row['ktp_dokter']}");
            return ['status' => 'error', 'message' => "IHS dokter '{$row['nm_dokter']}' tidak ditemukan. KTP: {$row['ktp_dokter']}"];
        }

        // Format tanggal authored
        $jamPeresepan = $row['jam_peresepan'] ?? '00:00:00';
        if (strlen($jamPeresepan) === 5) $jamPeresepan .= ':00';
        $authored = ($row['tgl_peresepan'] ?? date('Y-m-d')) . 'T' . $jamPeresepan . '+07:00';

        // Build payload
        $payload = [
            'resourceType' => 'QuestionnaireResponse',
            'status'       => 'completed',
            'authored'     => $authored,
            'subject'      => [
                'reference' => 'Patient/' . $row['ihs_pasien'],
                'display'   => $row['nm_pasien'],
            ],
            'source'    => ['reference' => 'Patient/' . $row['ihs_pasien']],
            'encounter' => ['reference' => 'Encounter/' . $row['id_encounter']],
            'author'    => [
                'reference' => 'Practitioner/' . $ihsDokter,
                'display'   => $row['nm_dokter'],
            ],
            'item' => [
                // ── Identitas ──────────────────────────────────────
                [
                    'linkId' => 'identitas',
                    'text'   => 'Identitas',
                    'item'   => [
                        qrItem('no-rawat', 'No. Rawat',  $row['no_rawat']),
                        qrItem('no-rm',    'No. RM',     $row['no_rkm_medis']),
                        qrItem('no-resep', 'No. Resep',  $row['no_resep']),
                    ],
                ],
                // ── Telaah Resep ───────────────────────────────────
                [
                    'linkId' => 'telaah-resep',
                    'text'   => 'Telaah Resep',
                    'item'   => [
                        qrItem('tr-1-tepat-identifikasi-pasien',     '1. Tepat Identifikasi Pasien',    $row['resep_identifikasi_pasien']       ?? ''),
                        qrItem('tr-1-tepat-identifikasi-pasien-ket', 'Keterangan',                      $row['resep_ket_identifikasi_pasien']   ?? ''),
                        qrItem('tr-2-tepat-obat',                    '2. Tepat Obat',                   $row['resep_tepat_obat']                ?? ''),
                        qrItem('tr-2-tepat-obat-ket',                'Keterangan',                      $row['resep_ket_tepat_obat']            ?? ''),
                        qrItem('tr-3-tepat-dosis',                   '3. Tepat Dosis',                  $row['resep_tepat_dosis']               ?? ''),
                        qrItem('tr-3-tepat-dosis-ket',               'Keterangan',                      $row['resep_ket_tepat_dosis']           ?? ''),
                        qrItem('tr-4-tepat-cara-pemberian',          '4. Tepat Cara Pemberian',         $row['resep_tepat_cara_pemberian']      ?? ''),
                        qrItem('tr-4-tepat-cara-pemberian-ket',      'Keterangan',                      $row['resep_ket_tepat_cara_pemberian']  ?? ''),
                        qrItem('tr-5-tepat-waktu-pemberian',         '5. Tepat Waktu Pemberian',        $row['resep_tepat_waktu_pemberian']     ?? ''),
                        qrItem('tr-5-tepat-waktu-pemberian-ket',     'Keterangan',                      $row['resep_ket_tepat_waktu_pemberian'] ?? ''),
                        qrItem('tr-6-duplikasi-obat',                '6. Ada Tidak Duplikasi Obat',     $row['resep_ada_tidak_duplikasi_obat']  ?? ''),
                        qrItem('tr-6-duplikasi-obat-ket',            'Keterangan',                      $row['resep_ket_ada_tidak_duplikasi_obat'] ?? ''),
                        qrItem('tr-7-interaksi-obat',                '7. Interaksi Obat',               $row['resep_interaksi_obat']            ?? ''),
                        qrItem('tr-7-interaksi-obat-ket',            'Keterangan',                      $row['resep_ket_interaksi_obat']        ?? ''),
                        qrItem('tr-8-kontra-indikasi-obat',          '8. Kontra Indikasi Obat',         $row['resep_kontra_indikasi_obat']      ?? ''),
                        qrItem('tr-8-kontra-indikasi-obat-ket',      'Keterangan',                      $row['resep_ket_kontra_indikasi_obat']  ?? ''),
                    ],
                ],
                // ── Telaah Obat ────────────────────────────────────
                [
                    'linkId' => 'telaah-obat',
                    'text'   => 'Telaah Obat',
                    'item'   => [
                        qrItem('to-1-tepat-pasien',           '1. Tepat Pasien',          $row['obat_tepat_pasien']          ?? ''),
                        qrItem('to-2-tepat-obat',             '2. Tepat Obat',            $row['obat_tepat_obat']            ?? ''),
                        qrItem('to-3-tepat-dosis',            '3. Tepat Dosis',           $row['obat_tepat_dosis']           ?? ''),
                        qrItem('to-4-tepat-cara-pemberian',   '4. Tepat Cara Pemberian',  $row['obat_tepat_cara_pemberian']  ?? ''),
                        qrItem('to-5-tepat-waktu-pemberian',  '5. Tepat Waktu Pemberian', $row['obat_tepat_waktu_pemberian'] ?? ''),
                    ],
                ],
            ],
        ];

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        logQR("SEND no_resep=$noResep no_rawat={$row['no_rawat']}");

        $ch = curl_init(SS_FHIR_URL . '/QuestionnaireResponse');
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

        logQR("RESPONSE HTTP=$httpCode BODY=" . substr($resp, 0, 300));
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
            $idQR = $respData['id'] ?? '';
            if (empty($idQR)) throw new Exception("Response sukses tapi id kosong");

            $db->prepare("
                INSERT INTO satu_sehat_questionresponse_telaah_farmasi (no_resep, id_questionresponse)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE id_questionresponse = VALUES(id_questionresponse)
            ")->execute([$noResep, $idQR]);

            logQR("OK no_resep=$noResep id_qr=$idQR");
            return ['status' => 'ok', 'id_qr' => $idQR];
        }

        $errMsg = ($respData['issue'][0]['diagnostics'] ?? '')
               ?: ($respData['issue'][0]['details']['text'] ?? '')
               ?: "HTTP $httpCode: " . substr($resp, 0, 200);
        throw new Exception($errMsg);

    } catch (Exception $e) {
        logQR("ERROR no_resep=$noResep msg=" . $e->getMessage());
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

// ── Routing ───────────────────────────────────────────────────────
$action  = $_POST['action']   ?? '';
$noResep = trim($_POST['no_resep'] ?? '');

try {
    if ($action === 'kirim_qr') {
        if (!$noResep) jsonOutQR(['status' => 'error', 'message' => 'no_resep tidak boleh kosong']);
        jsonOutQR(kirimSatuQR($noResep, $pdo_simrs));
    }

    if ($action === 'kirim_semua_qr') {
        $tglDari   = trim($_POST['tgl_dari']   ?? date('Y-m-d'));
        $tglSampai = trim($_POST['tgl_sampai'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglDari))   $tglDari   = date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglSampai)) $tglSampai = date('Y-m-d');

        $stmtList = $pdo_simrs->prepare("
            SELECT ro.no_resep
            FROM resep_obat ro
            JOIN reg_periksa rp         ON rp.no_rawat = ro.no_rawat
            JOIN telaah_farmasi tf      ON tf.no_resep = ro.no_resep
            LEFT JOIN satu_sehat_encounter se ON se.no_rawat = ro.no_rawat
            LEFT JOIN satu_sehat_questionresponse_telaah_farmasi sq ON sq.no_resep = ro.no_resep
            WHERE ro.tgl_peresepan BETWEEN ? AND ?
              AND se.id_encounter IS NOT NULL AND se.id_encounter != ''
              AND (sq.id_questionresponse IS NULL OR sq.id_questionresponse = '')
        ");
        $stmtList->execute([$tglDari, $tglSampai]);
        $list = $stmtList->fetchAll(PDO::FETCH_COLUMN);

        $berhasil = 0; $gagal = 0; $errors = [];
        foreach ($list as $nr) {
            $res = kirimSatuQR($nr, $pdo_simrs);
            if ($res['status'] === 'ok') $berhasil++;
            else { $gagal++; $errors[] = $nr . ': ' . ($res['message'] ?? '?'); }
            usleep(300000);
        }

        logQR("KIRIM_SEMUA tgl=$tglDari/$tglSampai total=" . count($list) . " ok=$berhasil gagal=$gagal");
        jsonOutQR(['status' => 'ok', 'jumlah' => count($list), 'berhasil' => $berhasil, 'gagal' => $gagal, 'errors' => $errors]);
    }

    jsonOutQR(['status' => 'error', 'message' => "Action '$action' tidak dikenal"]);

} catch (Exception $e) {
    logQR("EXCEPTION action=$action msg=" . $e->getMessage());
    jsonOutQR(['status' => 'error', 'message' => $e->getMessage()]);
}