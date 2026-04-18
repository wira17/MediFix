<?php
/**
 * api/sync_ihs_pasien.php
 * Sinkronisasi IHS Number pasien dari Satu Sehat berdasarkan NIK
 *
 * Actions:
 *   sync_satu   → sync satu pasien by no_rkm_medis
 *   sync_semua  → sync semua pasien yang belum punya IHS Number
 *   cek_status  → cek status sync
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'Sesi habis']);
    exit;
}

require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../koneksi2.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../includes/satusehat_api.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Helper: cari IHS Number dari Satu Sehat by NIK ───────────────
function cariIHSByNIK(string $nik): ?string {
    try {
        $resp = ssGet("Patient?identifier=https://fhir.kemkes.go.id/id/nik|$nik");
        if (!empty($resp['entry'][0]['resource']['id'])) {
            return $resp['entry'][0]['resource']['id'];
        }
    } catch (Exception $e) {
        throw new RuntimeException("Gagal cari NIK $nik: " . $e->getMessage());
    }
    return null;
}

// ── Helper: simpan hasil sync ke tabel medifix_ss_pasien ─────────
function simpanHasil(PDO $pdo, string $noRkm, ?string $ihsNumber, string $status, string $errorMsg = ''): void {
    $pdo->prepare("
        UPDATE medifix_ss_pasien
        SET ihs_number  = ?,
            tgl_sync    = NOW(),
            status_sync = ?,
            error_msg   = ?
        WHERE no_rkm_medis = ?
    ")->execute([$ihsNumber, $status, mb_substr($errorMsg, 0, 300), $noRkm]);
}

// ══════════════════════════════════════════════════════════════════
//  ACTION: sync_satu
// ══════════════════════════════════════════════════════════════════
if ($action === 'sync_satu') {
    $noRkm = trim($_POST['no_rkm_medis'] ?? '');
    if (!$noRkm) { echo json_encode(['status'=>'error','message'=>'no_rkm_medis kosong']); exit; }

    // Ambil NIK dari tabel pasien Khanza
    $stmt = $pdo_simrs->prepare("SELECT no_rkm_medis, nm_pasien, no_ktp FROM pasien WHERE no_rkm_medis = ?");
    $stmt->execute([$noRkm]);
    $pasien = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pasien) { echo json_encode(['status'=>'error','message'=>'Pasien tidak ditemukan']); exit; }
    if (empty($pasien['no_ktp'])) {
        simpanHasil($pdo_simrs, $noRkm, null, 'error', 'NIK kosong di data pasien');
        echo json_encode(['status'=>'error','message'=>'NIK pasien kosong di SIMRS']);
        exit;
    }

    $nik = trim($pasien['no_ktp']);
    try {
        $ihsNumber = cariIHSByNIK($nik);
        if ($ihsNumber) {
            simpanHasil($pdo_simrs, $noRkm, $ihsNumber, 'ditemukan');
            echo json_encode([
                'status'       => 'ok',
                'message'      => 'IHS Number ditemukan',
                'no_rkm_medis' => $noRkm,
                'nm_pasien'    => $pasien['nm_pasien'],
                'nik'          => $nik,
                'ihs_number'   => $ihsNumber,
            ]);
        } else {
            simpanHasil($pdo_simrs, $noRkm, null, 'tidak_ditemukan', 'NIK tidak terdaftar di Satu Sehat');
            echo json_encode([
                'status'  => 'not_found',
                'message' => "NIK $nik tidak ditemukan di Satu Sehat",
                'nm_pasien' => $pasien['nm_pasien'],
            ]);
        }
    } catch (Exception $e) {
        simpanHasil($pdo_simrs, $noRkm, null, 'error', $e->getMessage());
        echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════
//  ACTION: sync_semua (batch)
// ══════════════════════════════════════════════════════════════════
if ($action === 'sync_semua') {
    $limit = (int)($_POST['limit'] ?? 50); // proses per batch
    $limit = min(max($limit, 1), 200);

    // Ambil pasien yang belum punya IHS Number
    $stmt = $pdo_simrs->prepare("
        SELECT m.no_rkm_medis, p.nm_pasien, p.no_ktp
        FROM medifix_ss_pasien m
        JOIN pasien p ON m.no_rkm_medis = p.no_rkm_medis
        WHERE (m.ihs_number IS NULL OR m.ihs_number = '')
          AND m.status_sync != 'tidak_ditemukan'
          AND (p.no_ktp IS NOT NULL AND p.no_ktp != '')
        ORDER BY m.no_rkm_medis ASC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($list)) {
        echo json_encode(['status'=>'ok','message'=>'Semua pasien sudah tersync','jumlah'=>0,'ditemukan'=>0,'tidak_ditemukan'=>0,'error'=>0]);
        exit;
    }

    $ditemukan = 0; $tidakDitemukan = 0; $error = 0;

    foreach ($list as $pasien) {
        $noRkm = $pasien['no_rkm_medis'];
        $nik   = trim($pasien['no_ktp'] ?? '');

        if (!$nik) {
            simpanHasil($pdo_simrs, $noRkm, null, 'error', 'NIK kosong');
            $error++; continue;
        }

        try {
            $ihsNumber = cariIHSByNIK($nik);
            if ($ihsNumber) {
                simpanHasil($pdo_simrs, $noRkm, $ihsNumber, 'ditemukan');
                $ditemukan++;
            } else {
                simpanHasil($pdo_simrs, $noRkm, null, 'tidak_ditemukan', 'NIK tidak terdaftar di Satu Sehat');
                $tidakDitemukan++;
            }
            usleep(200000); // 200ms jeda agar tidak rate-limit
        } catch (Exception $e) {
            simpanHasil($pdo_simrs, $noRkm, null, 'error', $e->getMessage());
            $error++;
        }
    }

    // Hitung sisa yang belum sync
    $stmtSisa = $pdo_simrs->query("
        SELECT COUNT(*) FROM medifix_ss_pasien
        WHERE (ihs_number IS NULL OR ihs_number = '')
          AND status_sync != 'tidak_ditemukan'
    ");
    $sisa = (int)$stmtSisa->fetchColumn();

    echo json_encode([
        'status'          => 'ok',
        'jumlah'          => count($list),
        'ditemukan'       => $ditemukan,
        'tidak_ditemukan' => $tidakDitemukan,
        'error'           => $error,
        'sisa'            => $sisa,
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════════════
//  ACTION: cek_status
// ══════════════════════════════════════════════════════════════════
if ($action === 'cek_status') {
    $stmt = $pdo_simrs->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN ihs_number IS NOT NULL AND ihs_number != '' THEN 1 ELSE 0 END) AS ditemukan,
            SUM(CASE WHEN status_sync = 'tidak_ditemukan' THEN 1 ELSE 0 END) AS tidak_ditemukan,
            SUM(CASE WHEN status_sync = 'error' THEN 1 ELSE 0 END) AS error,
            SUM(CASE WHEN status_sync = 'pending' THEN 1 ELSE 0 END) AS pending
        FROM medifix_ss_pasien
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['status'=>'ok','stats'=>$stats]);
    exit;
}

echo json_encode(['status'=>'error','message'=>"Action '$action' tidak dikenal"]);