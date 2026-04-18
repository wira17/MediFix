<?php
/**
 * api/kirim_service_request.php
 * AJAX endpoint untuk kirim ServiceRequest ke Satu Sehat
 * Gantikan bagian TODO di data_service_request.php lama Anda
 *
 * POST actions:
 *   action=kirim        → kirim satu noorder
 *   action=kirim_semua  → kirim semua pending pada tanggal tertentu
 *   action=status       → cek status pengiriman satu noorder
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

// Auth guard
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Sesi habis, silakan login ulang']);
    exit;
}

require_once __DIR__ . '/../koneksi.php';          // $pdo_simrs
require_once __DIR__ . '/../includes/satusehat_api.php';
require_once __DIR__ . '/../includes/service_request.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ══════════════════════════════════════════════════════════════════
//  HELPER: Ambil data lengkap satu noorder
// ══════════════════════════════════════════════════════════════════
function fetchRowByNoorder(PDO $pdo, string $noorder): ?array {
    $stmt = $pdo->prepare("
        SELECT
            s.noorder, s.kd_jenis_prw, s.id_servicerequest,
            s.status_kirim_sr, s.error_msg_sr,
            pr.no_rawat, pr.tgl_permintaan, pr.jam_permintaan,
            pr.diagnosa_klinis, pr.informasi_tambahan,
            pr.status AS status_rawat,
            p.no_rkm_medis, p.nm_pasien, p.jk, p.tgl_lahir,
            p.ihs_number,
            r.id_encounter,
            d.kd_dokter, d.nm_dokter, d.ihs_dokter,
            pl.nm_poli,
            j.nm_jenis_prw
        FROM satu_sehat_servicerequest_radiologi s
        JOIN permintaan_radiologi pr ON s.noorder      = pr.noorder
        JOIN reg_periksa r           ON pr.no_rawat    = r.no_rawat
        JOIN pasien p               ON r.no_rkm_medis  = p.no_rkm_medis
        LEFT JOIN dokter d          ON pr.dokter_perujuk = d.kd_dokter
        LEFT JOIN poliklinik pl     ON r.kd_poli       = pl.kd_poli
        LEFT JOIN jenis_perawatan j ON s.kd_jenis_prw  = j.kd_jenis_prw
        WHERE s.noorder = ?
    ");
    $stmt->execute([$noorder]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ══════════════════════════════════════════════════════════════════
//  ACTION: kirim (satu noorder)
// ══════════════════════════════════════════════════════════════════
if ($action === 'kirim') {
    $noorder = trim($_POST['noorder'] ?? '');
    if (!$noorder) {
        echo json_encode(['status' => 'error', 'message' => 'No. Order tidak boleh kosong']);
        exit;
    }

    $row = fetchRowByNoorder($pdo_simrs, $noorder);
    if (!$row) {
        echo json_encode(['status' => 'error', 'message' => "Data noorder $noorder tidak ditemukan"]);
        exit;
    }

    // Validasi data wajib
    $missing = [];
    if (empty($row['ihs_number']))   $missing[] = 'ihs_number pasien';
    if (empty($row['id_encounter'])) $missing[] = 'id_encounter';
    if (empty($row['ihs_dokter']))   $missing[] = 'ihs_dokter';
    if ($missing) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Data belum lengkap: ' . implode(', ', $missing) . '. Pastikan data pasien & dokter sudah sinkron ke Satu Sehat.',
            'noorder' => $noorder,
        ]);
        exit;
    }

    try {
        $idSR = postServiceRequest($row);
        saveServiceRequestResult($pdo_simrs, $noorder, $idSR);

        echo json_encode([
            'status'  => 'ok',
            'message' => 'ServiceRequest berhasil dikirim',
            'noorder' => $noorder,
            'id_sr'   => $idSR,
        ]);
    } catch (Exception $e) {
        saveServiceRequestError($pdo_simrs, $noorder, $e->getMessage());
        echo json_encode([
            'status'  => 'error',
            'message' => $e->getMessage(),
            'noorder' => $noorder,
        ]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════
//  ACTION: kirim_semua (batch pending)
// ══════════════════════════════════════════════════════════════════
if ($action === 'kirim_semua') {
    $tanggal = trim($_POST['tanggal'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        echo json_encode(['status' => 'error', 'message' => 'Format tanggal tidak valid']);
        exit;
    }

    // Ambil semua pending pada tanggal tersebut
    $stmt = $pdo_simrs->prepare("
        SELECT s.noorder
        FROM satu_sehat_servicerequest_radiologi s
        JOIN permintaan_radiologi pr ON s.noorder = pr.noorder
        WHERE pr.tgl_permintaan = ?
          AND (s.id_servicerequest IS NULL OR s.id_servicerequest = '')
          AND (s.status_kirim_sr IS NULL OR s.status_kirim_sr != 'terkirim')
        ORDER BY pr.jam_permintaan ASC
    ");
    $stmt->execute([$tanggal]);
    $noorders = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($noorders)) {
        echo json_encode(['status' => 'ok', 'message' => 'Tidak ada data pending', 'jumlah' => 0]);
        exit;
    }

    $berhasil = 0;
    $gagal    = 0;
    $errors   = [];

    foreach ($noorders as $noorder) {
        $row = fetchRowByNoorder($pdo_simrs, $noorder);
        if (!$row) { $gagal++; continue; }

        // Skip jika data tidak lengkap (jangan lempar error, lanjut ke berikutnya)
        if (empty($row['ihs_number']) || empty($row['id_encounter']) || empty($row['ihs_dokter'])) {
            $gagal++;
            $errors[] = "$noorder: data tidak lengkap (ihs_number/encounter/dokter)";
            continue;
        }

        try {
            $idSR = postServiceRequest($row);
            saveServiceRequestResult($pdo_simrs, $noorder, $idSR);
            $berhasil++;
            // Sedikit jeda agar tidak rate-limit
            usleep(200000); // 200ms
        } catch (Exception $e) {
            saveServiceRequestError($pdo_simrs, $noorder, $e->getMessage());
            $gagal++;
            $errors[] = "$noorder: " . $e->getMessage();
        }
    }

    echo json_encode([
        'status'   => 'ok',
        'jumlah'   => count($noorders),
        'berhasil' => $berhasil,
        'gagal'    => $gagal,
        'errors'   => array_slice($errors, 0, 10), // maks 10 error ditampilkan
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════════════
//  ACTION: status (cek satu noorder)
// ══════════════════════════════════════════════════════════════════
if ($action === 'status') {
    $noorder = trim($_POST['noorder'] ?? $_GET['noorder'] ?? '');
    if (!$noorder) {
        echo json_encode(['status' => 'error', 'message' => 'noorder wajib diisi']);
        exit;
    }

    $stmt = $pdo_simrs->prepare("
        SELECT id_servicerequest, id_imagingstudy,
               status_kirim_sr, tgl_kirim_sr, error_msg_sr,
               status_kirim_is, tgl_kirim_is, error_msg_is
        FROM satu_sehat_servicerequest_radiologi
        WHERE noorder = ?
    ");
    $stmt->execute([$noorder]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$r) {
        echo json_encode(['status' => 'error', 'message' => 'Data tidak ditemukan']);
        exit;
    }

    echo json_encode(['status' => 'ok', 'data' => $r]);
    exit;
}

// Fallback
echo json_encode(['status' => 'error', 'message' => "Action '$action' tidak dikenal"]);
