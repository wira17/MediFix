<?php
/**
 * api/kirim_episode_of_care.php
 * Kirim EpisodeOfCare ANC ke Satu Sehat
 * Fix: Unparseable_resource — struktur payload diperbaiki sesuai spec FHIR Kemkes
 */

header('Content-Type: application/json');

// ── Load config ───────────────────────────────────────────────────
if (!defined('SS_CLIENT_ID')) {
    require_once __DIR__ . '/../config/env.php';
    if (isset($pdo)) loadSatuSehatConfig($pdo);
    elseif (isset($pdo_simrs)) loadSatuSehatConfig($pdo_simrs);
}

if (!defined('SS_CLIENT_ID') || SS_CLIENT_ID === '') {
    echo json_encode(['status' => 'error', 'message' => 'Konfigurasi Satu Sehat belum diatur']);
    exit;
}

// ── Helpers ───────────────────────────────────────────────────────
function jsonOut(array $d): void { echo json_encode($d); exit; }

function logEOC(string $msg): void {
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents("$dir/episode_of_care_" . date('Y-m') . ".log",
        date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
}

// ── Ambil token ───────────────────────────────────────────────────
function getSatuSehatToken(): string {
    $cache = SS_TOKEN_CACHE_FILE;
    if (file_exists($cache)) {
        $c = json_decode(file_get_contents($cache), true);
        if (!empty($c['access_token']) && ($c['expires_at'] ?? 0) > time() + 60)
            return $c['access_token'];
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
        CURLOPT_TIMEOUT    => 20,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) throw new Exception("Auth gagal HTTP $code: " . substr($resp, 0, 200));
    $data = json_decode($resp, true);
    if (empty($data['access_token'])) throw new Exception("Token kosong dari response");

    file_put_contents($cache, json_encode([
        'access_token' => $data['access_token'],
        'expires_at'   => time() + (int)($data['expires_in'] ?? 3600),
    ]));
    return $data['access_token'];
}

// ── Format tanggal ke ISO8601 dengan timezone Jakarta ────────────
function toISO(string $tanggal, string $jam = '00:00:00'): string {
    // Bersihkan input
    $tanggal = trim($tanggal);
    $jam     = trim($jam);

    // Kalau sudah ada T (sudah ISO), kembalikan langsung
    if (strpos($tanggal, 'T') !== false) {
        // Pastikan ada timezone
        if (!preg_match('/[+\-]\d{2}:\d{2}$|Z$/', $tanggal)) {
            return rtrim($tanggal) . '+07:00';
        }
        return $tanggal;
    }

    // Validasi format tanggal Y-m-d
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        $tanggal = date('Y-m-d'); // fallback hari ini
    }

    // Validasi format jam H:i:s atau H:i
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $jam)) {
        $jam = '00:00:00';
    }
    if (strlen($jam) === 5) $jam .= ':00';

    return $tanggal . 'T' . $jam . '+07:00';
}

// ── Kirim 1 EpisodeOfCare ─────────────────────────────────────────
function kirimSatuEOC(string $noRawat, string $kdPenyakit, string $statusDiagnosa, PDO $pdo_simrs): array
{
    // Cek sudah ada
    $stmtCek = $pdo_simrs->prepare("
        SELECT id_episode_of_care FROM satu_sehat_episode_of_care
        WHERE no_rawat = ? AND kd_penyakit = ? AND status = ?
        LIMIT 1
    ");
    $stmtCek->execute([$noRawat, $kdPenyakit, $statusDiagnosa]);
    $existing = $stmtCek->fetchColumn();
    if (!empty($existing)) {
        return ['status' => 'ok', 'id_eoc' => $existing, 'note' => 'sudah ada'];
    }

    // ── Ambil data pasien dari ralan ─────────────────────────────
    $row = null;

    $sqlRalan = "
        SELECT
            rp.no_rawat, rp.no_rkm_medis,
            rp.tgl_registrasi, rp.jam_reg,
            p.nm_pasien, p.no_ktp,
            pr.tgl_perawatan, pr.jam_rawat,
            se.id_encounter,
            dp.kd_penyakit, dp.status AS status_dp,
            py.nm_penyakit,
            IFNULL(msp.ihs_number,'') AS ihs_number,
            'Ralan' AS jenis_rawat
        FROM reg_periksa rp
        JOIN pasien p                     ON rp.no_rkm_medis = p.no_rkm_medis
        JOIN pemeriksaan_ralan pr         ON pr.no_rawat      = rp.no_rawat
        LEFT JOIN satu_sehat_encounter se ON se.no_rawat      = rp.no_rawat
        JOIN diagnosa_pasien dp           ON dp.no_rawat      = rp.no_rawat
                                         AND dp.kd_penyakit   = ?
                                         AND dp.status        = ?
        JOIN penyakit py                  ON py.kd_penyakit   = dp.kd_penyakit
        LEFT JOIN medifix_ss_pasien msp   ON p.no_rkm_medis   = msp.no_rkm_medis
        WHERE rp.no_rawat = ?
        ORDER BY pr.tgl_perawatan DESC
        LIMIT 1
    ";
    $stmt = $pdo_simrs->prepare($sqlRalan);
    $stmt->execute([$kdPenyakit, $statusDiagnosa, $noRawat]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // ── Ranap jika tidak ketemu di ralan ─────────────────────────
    if (!$row) {
        $sqlRanap = "
            SELECT
                rp.no_rawat, rp.no_rkm_medis,
                rp.tgl_registrasi, rp.jam_reg,
                p.nm_pasien, p.no_ktp,
                ki.tgl_keluar AS tgl_perawatan, ki.jam_keluar AS jam_rawat,
                se.id_encounter,
                dp.kd_penyakit, dp.status AS status_dp,
                py.nm_penyakit,
                IFNULL(msp.ihs_number,'') AS ihs_number,
                'Ranap' AS jenis_rawat
            FROM reg_periksa rp
            JOIN pasien p                     ON rp.no_rkm_medis = p.no_rkm_medis
            JOIN kamar_inap ki                ON ki.no_rawat      = rp.no_rawat
            LEFT JOIN satu_sehat_encounter se ON se.no_rawat      = rp.no_rawat
            JOIN diagnosa_pasien dp           ON dp.no_rawat      = rp.no_rawat
                                             AND dp.kd_penyakit   = ?
                                             AND dp.status        = ?
            JOIN penyakit py                  ON py.kd_penyakit   = dp.kd_penyakit
            LEFT JOIN medifix_ss_pasien msp   ON p.no_rkm_medis   = msp.no_rkm_medis
            WHERE rp.no_rawat = ?
            ORDER BY ki.tgl_keluar DESC
            LIMIT 1
        ";
        $stmt = $pdo_simrs->prepare($sqlRanap);
        $stmt->execute([$kdPenyakit, $statusDiagnosa, $noRawat]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return ['status' => 'error', 'message' => "Data tidak ditemukan: no_rawat=$noRawat kd=$kdPenyakit status=$statusDiagnosa"];
    }

    // ── Validasi IHS Number ───────────────────────────────────────
    if (empty($row['ihs_number'])) {
        return ['status' => 'error', 'message' => 'IHS Number pasien belum ada — sync IHS dulu'];
    }

    // ── Validasi Encounter ────────────────────────────────────────
    // EpisodeOfCare BISA dikirim tanpa encounter, tapi log warning
    if (empty($row['id_encounter'])) {
        logEOC("WARN no encounter untuk no_rawat=$noRawat — tetap dikirim");
    }

    try {
        $token = getSatuSehatToken();

        // ── Tanggal mulai = tgl_registrasi + jam_reg ──────────────
        $tglMulai = toISO($row['tgl_registrasi'], $row['jam_reg']);

        // ── Build payload — sesuai spec Kemkes FHIR R4 ───────────
        // PENTING: hanya field yang WAJIB/valid, tidak boleh ada field kosong
        $payload = [
            'resourceType' => 'EpisodeOfCare',
            'identifier'   => [
                [
                    'system' => 'http://sys-ids.kemkes.go.id/episode-of-care/' . SS_ORG_ID,
                    'value'  => $noRawat,
                ],
            ],
            'status'        => 'active',
            'statusHistory' => [
                [
                    'status' => 'active',
                    'period' => [
                        'start' => $tglMulai,
                    ],
                ],
            ],
            'type' => [
                [
                    'coding' => [
                        [
                            'system'  => 'http://terminology.kemkes.go.id/CodeSystem/episodeofcare-type',
                            'code'    => 'ANC',
                            'display' => 'Antenatal Care',
                        ],
                    ],
                ],
            ],
            'patient' => [
                'reference' => 'Patient/' . $row['ihs_number'],
                'display'   => $row['nm_pasien'],
            ],
            'managingOrganization' => [
                'reference' => 'Organization/' . SS_ORG_ID,
            ],
            'period' => [
                'start' => $tglMulai,
            ],
        ];

        // Tambah careManager hanya jika SS_ORG_ID ada (tidak wajib tapi umum)
        // JANGAN tambah field kosong seperti diagnosis tanpa isi lengkap

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        logEOC("SEND no_rawat=$noRawat PAYLOAD=$jsonPayload");

        $ch = curl_init(SS_FHIR_URL . '/EpisodeOfCare');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonPayload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        logEOC("RESPONSE HTTP=$httpCode BODY=" . substr($resp, 0, 500));

        if ($curlErr) throw new Exception("cURL error: $curlErr");

        $respData = json_decode($resp, true);

        // ── Duplikat ──────────────────────────────────────────────
        if ($httpCode === 400) {
            $issue     = $respData['issue'][0] ?? [];
            $diagText  = $issue['diagnostics']        ?? '';
            $detailTxt = $issue['details']['text']    ?? '';
            $combined  = $diagText . ' ' . $detailTxt;

            if (stripos($combined, 'duplicat') !== false || stripos($combined, 'already exist') !== false) {
                logEOC("DUPLIKAT — coba GET existing EOC");

                // Cari existing EOC via GET
                $getUrl = SS_FHIR_URL . '/EpisodeOfCare?patient=' . urlencode($row['ihs_number']) . '&status=active';
                $chGet  = curl_init($getUrl);
                curl_setopt_array($chGet, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => [
                        'Authorization: Bearer ' . $token,
                        'Accept: application/json',
                    ],
                    CURLOPT_TIMEOUT        => 15,
                    CURLOPT_SSL_VERIFYPEER => false,
                ]);
                $getResp = json_decode(curl_exec($chGet), true);
                curl_close($chGet);

                $idEOC = '';
                foreach ($getResp['entry'] ?? [] as $entry) {
                    $res = $entry['resource'] ?? [];
                    foreach ($res['type'] ?? [] as $type) {
                        foreach ($type['coding'] ?? [] as $coding) {
                            if (($coding['code'] ?? '') === 'ANC') {
                                $idEOC = $res['id'] ?? '';
                                break 3;
                            }
                        }
                    }
                }
                if (empty($idEOC) && !empty($getResp['entry'][0]['resource']['id'])) {
                    $idEOC = $getResp['entry'][0]['resource']['id'];
                }

                if (!empty($idEOC)) {
                    $pdo_simrs->prepare("
                        INSERT IGNORE INTO satu_sehat_episode_of_care
                            (no_rawat, kd_penyakit, status, id_episode_of_care)
                        VALUES (?, ?, ?, ?)
                    ")->execute([$noRawat, $kdPenyakit, $statusDiagnosa, $idEOC]);

                    logEOC("OK (duplikat, simpan existing) no_rawat=$noRawat id_eoc=$idEOC");
                    return ['status' => 'ok', 'id_eoc' => $idEOC];
                }
            }

            $errMsg = $diagText ?: $detailTxt ?: "HTTP 400: " . substr($resp, 0, 300);
            throw new Exception($errMsg);
        }

        // ── Sukses 200 / 201 ─────────────────────────────────────
        if (in_array($httpCode, [200, 201], true)) {
            $idEOC = $respData['id'] ?? '';

            if (empty($idEOC)) {
                throw new Exception("Response 2xx tapi id kosong: " . substr($resp, 0, 200));
            }

            $pdo_simrs->prepare("
                INSERT INTO satu_sehat_episode_of_care
                    (no_rawat, kd_penyakit, status, id_episode_of_care)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    id_episode_of_care = VALUES(id_episode_of_care)
            ")->execute([$noRawat, $kdPenyakit, $statusDiagnosa, $idEOC]);

            logEOC("OK no_rawat=$noRawat kd=$kdPenyakit status=$statusDiagnosa id_eoc=$idEOC");
            return ['status' => 'ok', 'id_eoc' => $idEOC];
        }

        // ── Error lain ────────────────────────────────────────────
        $errMsg = ($respData['issue'][0]['diagnostics'] ?? '')
               ?: ($respData['issue'][0]['details']['text'] ?? '')
               ?: "HTTP $httpCode: " . substr($resp, 0, 300);
        throw new Exception($errMsg);

    } catch (Exception $e) {
        logEOC("ERROR no_rawat=$noRawat kd=$kdPenyakit msg=" . $e->getMessage());
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

// ── Routing ───────────────────────────────────────────────────────
$action = $_POST['action'] ?? '';

// Pastikan $pdo_simrs tersedia
if (!isset($pdo_simrs) && isset($pdo)) $pdo_simrs = $pdo;

try {
    // ── Kirim satu ────────────────────────────────────────────────
    if ($action === 'kirim') {
        $noRawat        = trim($_POST['no_rawat']        ?? '');
        $kdPenyakit     = trim($_POST['kd_penyakit']     ?? '');
        $statusDiagnosa = trim($_POST['status_diagnosa'] ?? '');

        if (!$noRawat || !$kdPenyakit) {
            jsonOut(['status' => 'error', 'message' => 'Parameter no_rawat / kd_penyakit tidak boleh kosong']);
        }
        // status_diagnosa boleh kosong, default ke string kosong
        jsonOut(kirimSatuEOC($noRawat, $kdPenyakit, $statusDiagnosa, $pdo_simrs));
    }

    // ── Kirim semua (batch) ───────────────────────────────────────
    if ($action === 'kirim_semua') {
        $tglDari   = trim($_POST['tgl_dari']   ?? date('Y-m-d'));
        $tglSampai = trim($_POST['tgl_sampai'] ?? date('Y-m-d'));

        // Validasi format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglDari))   $tglDari   = date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglSampai)) $tglSampai = date('Y-m-d');

        $stmtList = $pdo_simrs->prepare("
            SELECT DISTINCT rp.no_rawat, dp.kd_penyakit, dp.status AS status_diagnosa
            FROM reg_periksa rp
            JOIN pemeriksaan_ralan pr ON pr.no_rawat = rp.no_rawat
            JOIN diagnosa_pasien dp   ON dp.no_rawat = rp.no_rawat AND dp.kd_penyakit LIKE 'O%'
            LEFT JOIN satu_sehat_episode_of_care seoc
                ON seoc.no_rawat    = rp.no_rawat
               AND seoc.kd_penyakit = dp.kd_penyakit
               AND seoc.status      = dp.status
            WHERE pr.tgl_perawatan BETWEEN ? AND ?
              AND (seoc.id_episode_of_care IS NULL OR seoc.id_episode_of_care = '')

            UNION

            SELECT DISTINCT rp.no_rawat, dp.kd_penyakit, dp.status AS status_diagnosa
            FROM reg_periksa rp
            JOIN kamar_inap ki        ON ki.no_rawat = rp.no_rawat
            JOIN diagnosa_pasien dp   ON dp.no_rawat = rp.no_rawat AND dp.kd_penyakit LIKE 'O%'
            LEFT JOIN satu_sehat_episode_of_care seoc
                ON seoc.no_rawat    = rp.no_rawat
               AND seoc.kd_penyakit = dp.kd_penyakit
               AND seoc.status      = dp.status
            WHERE ki.tgl_keluar BETWEEN ? AND ?
              AND (seoc.id_episode_of_care IS NULL OR seoc.id_episode_of_care = '')
        ");
        $stmtList->execute([$tglDari, $tglSampai, $tglDari, $tglSampai]);
        $list = $stmtList->fetchAll(PDO::FETCH_ASSOC);

        $berhasil = 0; $gagal = 0; $errors = [];
        foreach ($list as $item) {
            $res = kirimSatuEOC($item['no_rawat'], $item['kd_penyakit'], $item['status_diagnosa'], $pdo_simrs);
            if ($res['status'] === 'ok') {
                $berhasil++;
            } else {
                $gagal++;
                $errors[] = $item['no_rawat'] . ': ' . ($res['message'] ?? '?');
            }
            usleep(300000); // 300ms jeda antar request
        }

        logEOC("KIRIM_SEMUA tgl=$tglDari/$tglSampai total=" . count($list) . " ok=$berhasil gagal=$gagal");
        jsonOut([
            'status'   => 'ok',
            'jumlah'   => count($list),
            'berhasil' => $berhasil,
            'gagal'    => $gagal,
            'errors'   => $errors,
        ]);
    }

    jsonOut(['status' => 'error', 'message' => "Action '$action' tidak dikenal"]);

} catch (Exception $e) {
    logEOC("EXCEPTION action=$action msg=" . $e->getMessage());
    jsonOut(['status' => 'error', 'message' => $e->getMessage()]);
}