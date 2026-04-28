<?php
/**
 * api/sync_ihs_pasien.php
 * Sinkronisasi IHS Number pasien dari Satu Sehat berdasarkan NIK
 *
 * Actions:
 *   sync_satu   → sync satu pasien by no_rkm_medis
 *   sync_semua  → sync batch pasien yang belum punya IHS
 *   cek_status  → cek statistik status sync
 */

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Sesi habis']);
    exit;
}

if (!isset($pdo_simrs)) {
    require_once __DIR__ . '/../koneksi.php';
    require_once __DIR__ . '/../koneksi2.php';
}
if (!defined('SS_CLIENT_ID')) {
    require_once __DIR__ . '/../config/env.php';
    if (isset($pdo_simrs)) loadSatuSehatConfig($pdo_simrs);
    elseif (isset($pdo))   loadSatuSehatConfig($pdo);
}
if (!isset($pdo_simrs) && isset($pdo)) $pdo_simrs = $pdo;

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Log ───────────────────────────────────────────────────────────
function logIHS(string $msg): void {
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents("$dir/ihs_" . date('Y-m') . ".log",
        date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
}

// ── Token Satu Sehat ──────────────────────────────────────────────
function getTokenIHS(): string {
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
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) throw new Exception("Auth gagal HTTP $code: " . substr($resp, 0, 200));
    $data = json_decode($resp, true);
    if (empty($data['access_token'])) throw new Exception("Token kosong");
    file_put_contents($cache, json_encode([
        'access_token' => $data['access_token'],
        'expires_at'   => time() + (int)($data['expires_in'] ?? 3600),
    ]));
    return $data['access_token'];
}

// ── Cari IHS via NIK ─────────────────────────────────────────────
function cariIHSByNIK(string $nik, string $token): ?string {
    $url = SS_FHIR_URL . '/Patient?identifier=https://fhir.kemkes.go.id/id/nik|' . urlencode($nik);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) throw new Exception("HTTP $code saat cari NIK $nik");
    $data = json_decode($resp, true);
    return $data['entry'][0]['resource']['id'] ?? null;
}

// ── Simpan hasil sync ─────────────────────────────────────────────
function simpanHasil(PDO $db, string $noRkm, ?string $ihs, string $status, string $err = ''): void {
    $db->prepare("
        INSERT INTO medifix_ss_pasien (no_rkm_medis, ihs_number, tgl_sync, status_sync, error_msg)
        VALUES (?, ?, NOW(), ?, ?)
        ON DUPLICATE KEY UPDATE
            ihs_number  = VALUES(ihs_number),
            tgl_sync    = NOW(),
            status_sync = VALUES(status_sync),
            error_msg   = VALUES(error_msg)
    ")->execute([$noRkm, $ihs, $status, mb_substr($err, 0, 300)]);
}

// ── Serve log ─────────────────────────────────────────────────────
if (isset($_GET['_log']) && $_GET['_log'] === 'ihs') {
    header('Content-Type: text/plain; charset=utf-8');
    $logFile = __DIR__ . '/../logs/ihs_' . date('Y-m') . '.log';
    echo file_exists($logFile)
        ? implode("\n", array_slice(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -500))
        : '(Log belum ada)';
    exit;
}

// ══════════════════════════════════════════════════════════════════
// ACTION: sync_satu
// ══════════════════════════════════════════════════════════════════
if ($action === 'sync_satu') {
    $noRkm = trim($_POST['no_rkm_medis'] ?? '');
    if (!$noRkm) { echo json_encode(['status' => 'error', 'message' => 'no_rkm_medis kosong']); exit; }

    $stmt = $pdo_simrs->prepare("SELECT no_rkm_medis, nm_pasien, no_ktp FROM pasien WHERE no_rkm_medis = ?");
    $stmt->execute([$noRkm]);
    $pasien = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pasien) { echo json_encode(['status' => 'error', 'message' => 'Pasien tidak ditemukan']); exit; }
    if (empty($pasien['no_ktp'])) {
        simpanHasil($pdo_simrs, $noRkm, null, 'error', 'NIK kosong');
        echo json_encode(['status' => 'error', 'message' => 'NIK pasien kosong di SIMRS']);
        exit;
    }

    $nik = trim($pasien['no_ktp']);
    try {
        $token     = getTokenIHS();
        $ihsNumber = cariIHSByNIK($nik, $token);
        if ($ihsNumber) {
            simpanHasil($pdo_simrs, $noRkm, $ihsNumber, 'ditemukan');
            logIHS("OK no_rkm=$noRkm nik=$nik ihs=$ihsNumber nm={$pasien['nm_pasien']}");
            echo json_encode(['status' => 'ok', 'ihs_number' => $ihsNumber, 'nm_pasien' => $pasien['nm_pasien']]);
        } else {
            simpanHasil($pdo_simrs, $noRkm, null, 'tidak_ditemukan', 'NIK tidak terdaftar di Satu Sehat');
            logIHS("NOT_FOUND no_rkm=$noRkm nik=$nik nm={$pasien['nm_pasien']}");
            echo json_encode(['status' => 'not_found', 'message' => "NIK $nik tidak ditemukan", 'nm_pasien' => $pasien['nm_pasien']]);
        }
    } catch (Exception $e) {
        simpanHasil($pdo_simrs, $noRkm, null, 'error', $e->getMessage());
        logIHS("ERROR no_rkm=$noRkm nik=$nik msg=" . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════
// ACTION: sync_semua (batch — dari reg_periksa via nota_jalan/nota_inap)
// ══════════════════════════════════════════════════════════════════
if ($action === 'sync_semua') {
    $batchLimit = min(max((int)($_POST['limit'] ?? 20), 1), 100);
    $tglDari    = trim($_POST['tgl_dari']   ?? '');
    $tglSampai  = trim($_POST['tgl_sampai'] ?? '');

    // Validasi tanggal
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglDari))   $tglDari   = '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglSampai)) $tglSampai = '';

    try {
        $token = getTokenIHS();

        if (!empty($tglDari) && !empty($tglSampai)) {
            // Sync pasien dari periode tertentu (nota_jalan + nota_inap)
            $stmt = $pdo_simrs->prepare("
                SELECT DISTINCT p.no_rkm_medis, p.nm_pasien, p.no_ktp
                FROM reg_periksa rp
                JOIN pasien p ON p.no_rkm_medis = rp.no_rkm_medis
                JOIN (
                    SELECT no_rawat FROM nota_jalan WHERE tanggal BETWEEN ? AND ?
                    UNION
                    SELECT no_rawat FROM nota_inap  WHERE tanggal BETWEEN ? AND ?
                ) nj ON nj.no_rawat = rp.no_rawat
                LEFT JOIN medifix_ss_pasien msp ON msp.no_rkm_medis = p.no_rkm_medis
                WHERE (msp.ihs_number IS NULL OR msp.ihs_number = '')
                  AND (msp.status_sync IS NULL OR msp.status_sync NOT IN ('tidak_ditemukan'))
                  AND p.no_ktp IS NOT NULL AND p.no_ktp != ''
             LIMIT " . intval($batchLimit) . "
            ");
            $stmt->execute([$tglDari, $tglSampai, $tglDari, $tglSampai]);
        } else {
            // Sync semua pasien yang belum punya IHS
            $stmt = $pdo_simrs->prepare("
                SELECT p.no_rkm_medis, p.nm_pasien, p.no_ktp
                FROM pasien p
                LEFT JOIN medifix_ss_pasien msp ON msp.no_rkm_medis = p.no_rkm_medis
                WHERE (msp.ihs_number IS NULL OR msp.ihs_number = '')
                  AND (msp.status_sync IS NULL OR msp.status_sync NOT IN ('tidak_ditemukan'))
                  AND p.no_ktp IS NOT NULL AND p.no_ktp != ''
                ORDER BY p.no_rkm_medis ASC
            LIMIT " . intval($batchLimit) . "
            ");
            $stmt->execute([]);
        }

        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($list)) {
            echo json_encode(['status' => 'ok', 'jumlah' => 0, 'ditemukan' => 0, 'tidak_ditemukan' => 0, 'error' => 0, 'sisa' => 0]);
            exit;
        }

        $ditemukan = 0; $tidakDitemukan = 0; $error = 0;

        foreach ($list as $pasien) {
            $noRkm = $pasien['no_rkm_medis'];
            $nik   = trim($pasien['no_ktp'] ?? '');

            if (!$nik) {
                simpanHasil($pdo_simrs, $noRkm, null, 'error', 'NIK kosong');
                logIHS("ERROR no_rkm=$noRkm NIK kosong");
                $error++;
                continue;
            }

            try {
                $ihsNumber = cariIHSByNIK($nik, $token);
                if ($ihsNumber) {
                    simpanHasil($pdo_simrs, $noRkm, $ihsNumber, 'ditemukan');
                    logIHS("OK no_rkm=$noRkm nik=$nik ihs=$ihsNumber nm={$pasien['nm_pasien']}");
                    $ditemukan++;
                } else {
                    simpanHasil($pdo_simrs, $noRkm, null, 'tidak_ditemukan', 'NIK tidak terdaftar di Satu Sehat');
                    logIHS("NOT_FOUND no_rkm=$noRkm nik=$nik nm={$pasien['nm_pasien']}");
                    $tidakDitemukan++;
                }
                usleep(200000);
            } catch (Exception $e) {
                simpanHasil($pdo_simrs, $noRkm, null, 'error', $e->getMessage());
                logIHS("ERROR no_rkm=$noRkm nik=$nik msg=" . $e->getMessage());
                $error++;
            }
        }

        // Hitung sisa
        if (!empty($tglDari) && !empty($tglSampai)) {
            $stmtSisa = $pdo_simrs->prepare("
                SELECT COUNT(DISTINCT p.no_rkm_medis)
                FROM reg_periksa rp
                JOIN pasien p ON p.no_rkm_medis = rp.no_rkm_medis
                JOIN (
                    SELECT no_rawat FROM nota_jalan WHERE tanggal BETWEEN ? AND ?
                    UNION
                    SELECT no_rawat FROM nota_inap  WHERE tanggal BETWEEN ? AND ?
                ) nj ON nj.no_rawat = rp.no_rawat
                LEFT JOIN medifix_ss_pasien msp ON msp.no_rkm_medis = p.no_rkm_medis
                WHERE (msp.ihs_number IS NULL OR msp.ihs_number = '')
                  AND (msp.status_sync IS NULL OR msp.status_sync NOT IN ('tidak_ditemukan'))
                  AND p.no_ktp IS NOT NULL AND p.no_ktp != ''
            ");
            $stmtSisa->execute([$tglDari, $tglSampai, $tglDari, $tglSampai]);
        } else {
            $stmtSisa = $pdo_simrs->query("
                SELECT COUNT(*) FROM pasien p
                LEFT JOIN medifix_ss_pasien msp ON msp.no_rkm_medis = p.no_rkm_medis
                WHERE (msp.ihs_number IS NULL OR msp.ihs_number = '')
                  AND (msp.status_sync IS NULL OR msp.status_sync NOT IN ('tidak_ditemukan'))
                  AND p.no_ktp IS NOT NULL AND p.no_ktp != ''
            ");
        }
        $sisa = (int)$stmtSisa->fetchColumn();

        logIHS("SYNC_SEMUA total=" . count($list) . " ok=$ditemukan tidak=$tidakDitemukan error=$error sisa=$sisa");
        echo json_encode([
            'status'          => 'ok',
            'jumlah'          => count($list),
            'ditemukan'       => $ditemukan,
            'tidak_ditemukan' => $tidakDitemukan,
            'error'           => $error,
            'sisa'            => $sisa,
        ]);

    } catch (Exception $e) {
        logIHS("EXCEPTION sync_semua: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════
// ACTION: cek_status
// ══════════════════════════════════════════════════════════════════
if ($action === 'cek_status') {
    $stmt = $pdo_simrs->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN ihs_number IS NOT NULL AND ihs_number != '' THEN 1 ELSE 0 END) AS ditemukan,
            SUM(CASE WHEN status_sync = 'tidak_ditemukan' THEN 1 ELSE 0 END) AS tidak_ditemukan,
            SUM(CASE WHEN status_sync = 'error' THEN 1 ELSE 0 END) AS error_sync,
            SUM(CASE WHEN (ihs_number IS NULL OR ihs_number = '') AND status_sync NOT IN ('tidak_ditemukan','error') THEN 1 ELSE 0 END) AS pending
        FROM medifix_ss_pasien
    ");
    echo json_encode(['status' => 'ok', 'stats' => $stmt->fetch(PDO::FETCH_ASSOC)]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => "Action '$action' tidak dikenal"]);