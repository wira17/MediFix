<?php


require_once __DIR__ . '/../koneksi.php';   // $pdo_simrs
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../includes/satusehat_api.php';
require_once __DIR__ . '/../includes/imaging_study.php';

// ── Security: hanya terima dari Orthanc (shared secret) ──────────
define('ORTHANC_SECRET', getenv('ORTHANC_WEBHOOK_SECRET') ?: 'ganti_dengan_secret_anda');

// Log helper
function logIS(string $level, string $msg): void {
    $line = date('Y-m-d H:i:s') . " [$level] $msg\n";
    file_put_contents(__DIR__ . '/../logs/imagingstudy.log', $line, FILE_APPEND | LOCK_EX);
    if (SS_DEBUG) error_log("[ImagingStudy] $msg");
}

// ── Parse input ───────────────────────────────────────────────────
$raw     = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!$payload) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Body JSON tidak valid']);
    exit;
}

// Cek shared secret
if (($payload['secret_key'] ?? '') !== ORTHANC_SECRET) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    logIS('WARN', 'Akses ditolak - secret tidak cocok dari ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    exit;
}

$accession = trim($payload['accession_number'] ?? '');
if (!$accession) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'accession_number kosong']);
    logIS('WARN', 'Request tanpa accession_number');
    exit;
}

logIS('INFO', "Diterima dari Orthanc: accession=$accession study_uid=" . ($payload['study_uid'] ?? '-'));

// ── Cari data di SIMRS berdasarkan noorder = accession ───────────
try {
    $stmt = $pdo_simrs->prepare("
        SELECT
            s.noorder, s.kd_jenis_prw,
            s.id_servicerequest, s.id_imagingstudy,
            s.status_kirim_is,
            pr.no_rawat, pr.tgl_permintaan, pr.jam_permintaan,
            pr.diagnosa_klinis,
            p.no_rkm_medis, p.nm_pasien, p.ihs_number,
            r.id_encounter,
            d.nm_dokter, d.ihs_dokter,
            -- dokter radiologi (jika ada kolom tersendiri)
            dr.nm_dokter   AS nm_dokter_rad,
            dr.ihs_dokter  AS ihs_dokter_rad
        FROM satu_sehat_servicerequest_radiologi s
        JOIN permintaan_radiologi pr ON s.noorder      = pr.noorder
        JOIN reg_periksa r           ON pr.no_rawat    = r.no_rawat
        JOIN pasien p               ON r.no_rkm_medis  = p.no_rkm_medis
        LEFT JOIN dokter d          ON pr.dokter_perujuk = d.kd_dokter
        LEFT JOIN dokter dr         ON pr.dokter_radiologi = dr.kd_dokter
        WHERE s.noorder = ?
    ");
    $stmt->execute([$accession]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB error: ' . $e->getMessage()]);
    logIS('ERROR', "DB error untuk accession=$accession: " . $e->getMessage());
    exit;
}

if (!$row) {
    // DICOM masuk tapi tidak ada di tabel service request
    // Mungkin bukan dari sistem radiologi (worklist lain), skip saja
    http_response_code(404);
    echo json_encode([
        'status'  => 'skip',
        'message' => "Accession $accession tidak ditemukan di tabel service request radiologi",
    ]);
    logIS('INFO', "Accession $accession tidak ada di DB — skip");
    exit;
}

// ── Cek duplikat (sudah pernah dikirim dan berhasil?) ────────────
if (!empty($row['id_imagingstudy']) && ($row['status_kirim_is'] ?? '') === 'terkirim') {
    logIS('INFO', "ImagingStudy accession=$accession sudah terkirim sebelumnya ({$row['id_imagingstudy']}) — skip");
    echo json_encode([
        'status'           => 'skip',
        'message'          => 'Sudah terkirim sebelumnya',
        'id_imagingstudy'  => $row['id_imagingstudy'],
    ]);
    exit;
}

// ── Cek ServiceRequest sudah ada ─────────────────────────────────
if (empty($row['id_servicerequest'])) {
    // ServiceRequest belum dikirim — tandai sebagai antrian untuk dicoba lagi
    logIS('WARN', "ServiceRequest accession=$accession belum ada id_sr — ImagingStudy ditunda");

    // Simpan study_uid agar bisa di-retry
    $pdo_simrs->prepare("
        UPDATE satu_sehat_servicerequest_radiologi
        SET study_uid_dicom = ?,
            status_kirim_is = 'pending_sr'
        WHERE noorder = ?
    ")->execute([$payload['study_uid'] ?? '', $accession]);

    http_response_code(202);
    echo json_encode([
        'status'  => 'pending',
        'message' => 'ServiceRequest belum terkirim. ImagingStudy akan dikirim setelah SR selesai.',
    ]);
    exit;
}

// ── Validasi field wajib ─────────────────────────────────────────
$missing = [];
if (empty($row['ihs_number']))   $missing[] = 'ihs_number';
if (empty($row['id_encounter'])) $missing[] = 'id_encounter';
if (empty($payload['study_uid'])) $missing[] = 'study_uid (dari DICOM)';

if ($missing) {
    http_response_code(422);
    $msg = 'Field kurang: ' . implode(', ', $missing);
    saveImagingStudyError($pdo_simrs, $accession, $msg);
    echo json_encode(['status' => 'error', 'message' => $msg]);
    logIS('ERROR', "accession=$accession — $msg");
    exit;
}

// ── POST ImagingStudy ke Satu Sehat ──────────────────────────────
try {
    $idIS = postImagingStudy($row, $payload);
    saveImagingStudyResult($pdo_simrs, $accession, $idIS, $payload['study_uid']);

    logIS('INFO', "ImagingStudy BERHASIL accession=$accession id_is=$idIS");
    echo json_encode([
        'status'           => 'ok',
        'message'          => 'ImagingStudy berhasil dikirim ke Satu Sehat',
        'accession'        => $accession,
        'id_imagingstudy'  => $idIS,
    ]);
} catch (Exception $e) {
    saveImagingStudyError($pdo_simrs, $accession, $e->getMessage());
    logIS('ERROR', "ImagingStudy GAGAL accession=$accession — " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status'    => 'error',
        'message'   => $e->getMessage(),
        'accession' => $accession,
    ]);
}
