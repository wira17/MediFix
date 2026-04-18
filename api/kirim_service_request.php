<?php
/**
 * api/kirim_service_request.php
 * AJAX endpoint — ServiceRequest & ImagingStudy ke Satu Sehat
 * Actions: kirim | kirim_semua | kirim_is | status
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'Sesi habis, silakan login ulang']);
    exit;
}

require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../includes/satusehat_api.php';
require_once __DIR__ . '/../includes/service_request.php';
require_once __DIR__ . '/../includes/imaging_study.php';

$action = $_POST['action'] ?? '';

function fetchRow(PDO $pdo, string $noorder): ?array {
    $stmt = $pdo->prepare("
        SELECT s.noorder, s.kd_jenis_prw,
               m.id_servicerequest, m.id_imagingstudy,
               m.status_kirim_sr, m.status_kirim_is, m.study_uid_dicom,
               pr.no_rawat, pr.tgl_permintaan, pr.jam_permintaan,
               pr.diagnosa_klinis, pr.informasi_tambahan,
               pr.status AS status_rawat,
               p.no_rkm_medis, p.nm_pasien, p.jk, p.tgl_lahir,
               mp.ihs_number,
               se.id_encounter,
               r.no_rawat AS no_rawat_reg,
               d.nm_dokter, pl.nm_poli
        FROM satu_sehat_servicerequest_radiologi s
        JOIN medifix_ss_radiologi m      ON s.noorder        = m.noorder
        JOIN permintaan_radiologi pr     ON s.noorder        = pr.noorder
        JOIN reg_periksa r               ON pr.no_rawat      = r.no_rawat
        JOIN pasien p                    ON r.no_rkm_medis   = p.no_rkm_medis
        LEFT JOIN medifix_ss_pasien mp   ON p.no_rkm_medis   = mp.no_rkm_medis
        LEFT JOIN satu_sehat_encounter se ON r.no_rawat      = se.no_rawat
        LEFT JOIN dokter d               ON pr.dokter_perujuk = d.kd_dokter
        LEFT JOIN poliklinik pl          ON r.kd_poli        = pl.kd_poli
        WHERE s.noorder = ? LIMIT 1
    ");
    $stmt->execute([$noorder]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function updateStatus(PDO $pdo, string $noorder, array $fields): void {
    $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
    $vals = array_values($fields);
    $vals[] = $noorder;
    $pdo->prepare("UPDATE medifix_ss_radiologi SET $set WHERE noorder = ?")->execute($vals);
}

// ── kirim SR ─────────────────────────────────────────────────────
if ($action === 'kirim') {
    $noorder = trim($_POST['noorder'] ?? '');
    if (!$noorder) { echo json_encode(['status'=>'error','message'=>'No. Order kosong']); exit; }
    $row = fetchRow($pdo_simrs, $noorder);
    if (!$row) { echo json_encode(['status'=>'error','message'=>"Data $noorder tidak ditemukan"]); exit; }
    try {
        $idSR = postServiceRequest($row);
        updateStatus($pdo_simrs, $noorder, [
            'id_servicerequest' => $idSR,
            'status_kirim_sr'   => 'terkirim',
            'tgl_kirim_sr'      => date('Y-m-d H:i:s'),
            'error_msg_sr'      => null,
        ]);
        $pdo_simrs->prepare("UPDATE satu_sehat_servicerequest_radiologi SET id_servicerequest = ? WHERE noorder = ?")
                  ->execute([$idSR, $noorder]);
        echo json_encode(['status'=>'ok','message'=>'SR berhasil dikirim','noorder'=>$noorder,'id_sr'=>$idSR]);
    } catch (Exception $e) {
        updateStatus($pdo_simrs, $noorder, ['status_kirim_sr'=>'error','error_msg_sr'=>mb_substr($e->getMessage(),0,500)]);
        echo json_encode(['status'=>'error','message'=>$e->getMessage(),'noorder'=>$noorder]);
    }
    exit;
}

// ── kirim IS manual ───────────────────────────────────────────────
if ($action === 'kirim_is') {
    $noorder = trim($_POST['noorder'] ?? '');
    if (!$noorder) { echo json_encode(['status'=>'error','message'=>'No. Order kosong']); exit; }
    $row = fetchRow($pdo_simrs, $noorder);
    if (!$row) { echo json_encode(['status'=>'error','message'=>"Data $noorder tidak ditemukan"]); exit; }
    if (empty($row['id_servicerequest'])) {
        echo json_encode(['status'=>'error','message'=>'SR belum dikirim. Kirim SR terlebih dahulu.']);
        exit;
    }
    $studyUid = $row['study_uid_dicom'] ?: '2.25.' . abs(crc32($noorder)) . '.' . time();
    $dicom = [
        'study_uid'           => $studyUid,
        'series_uid'          => $studyUid . '.1',
        'instance_uid'        => $studyUid . '.1.1',
        'sop_class_uid'       => '1.2.840.10008.5.1.4.1.1.2',
        'modality'            => strtoupper(explode('-', $row['kd_jenis_prw'])[0] ?? 'CR'),
        'study_date'          => date('Ymd', strtotime($row['tgl_permintaan'])),
        'study_time'          => date('His', strtotime($row['jam_permintaan'])),
        'number_of_instances' => 1,
    ];
    try {
        $idIS = postImagingStudy($row, $dicom);
        updateStatus($pdo_simrs, $noorder, [
            'id_imagingstudy' => $idIS,
            'study_uid_dicom' => $studyUid,
            'status_kirim_is' => 'terkirim',
            'tgl_kirim_is'    => date('Y-m-d H:i:s'),
            'error_msg_is'    => null,
        ]);
        echo json_encode(['status'=>'ok','message'=>'ImagingStudy berhasil dikirim','noorder'=>$noorder,'id_imagingstudy'=>$idIS]);
    } catch (Exception $e) {
        updateStatus($pdo_simrs, $noorder, ['status_kirim_is'=>'error','error_msg_is'=>mb_substr($e->getMessage(),0,500)]);
        echo json_encode(['status'=>'error','message'=>$e->getMessage(),'noorder'=>$noorder]);
    }
    exit;
}

// ── kirim semua SR ────────────────────────────────────────────────
if ($action === 'kirim_semua') {
    $tanggal = trim($_POST['tanggal'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        echo json_encode(['status'=>'error','message'=>'Format tanggal tidak valid']); exit;
    }
    $stmt = $pdo_simrs->prepare("
        SELECT s.noorder FROM satu_sehat_servicerequest_radiologi s
        JOIN medifix_ss_radiologi m  ON s.noorder = m.noorder
        JOIN permintaan_radiologi pr ON s.noorder = pr.noorder
        WHERE pr.tgl_permintaan = ? AND m.status_kirim_sr != 'terkirim'
        ORDER BY pr.jam_permintaan ASC
    ");
    $stmt->execute([$tanggal]);
    $noorders = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($noorders)) {
        echo json_encode(['status'=>'ok','message'=>'Tidak ada SR pending','jumlah'=>0,'berhasil'=>0,'gagal'=>0]); exit;
    }
    $berhasil = 0; $gagal = 0; $errors = [];
    foreach ($noorders as $noorder) {
        $row = fetchRow($pdo_simrs, $noorder);
        if (!$row) { $gagal++; continue; }
        try {
            $idSR = postServiceRequest($row);
            updateStatus($pdo_simrs, $noorder, [
                'id_servicerequest' => $idSR, 'status_kirim_sr' => 'terkirim',
                'tgl_kirim_sr' => date('Y-m-d H:i:s'), 'error_msg_sr' => null,
            ]);
            $pdo_simrs->prepare("UPDATE satu_sehat_servicerequest_radiologi SET id_servicerequest = ? WHERE noorder = ?")
                      ->execute([$idSR, $noorder]);
            $berhasil++; usleep(300000);
        } catch (Exception $e) {
            updateStatus($pdo_simrs, $noorder, ['status_kirim_sr'=>'error','error_msg_sr'=>mb_substr($e->getMessage(),0,500)]);
            $gagal++; $errors[] = "$noorder: " . $e->getMessage();
        }
    }
    echo json_encode(['status'=>'ok','jumlah'=>count($noorders),'berhasil'=>$berhasil,'gagal'=>$gagal,'errors'=>array_slice($errors,0,10)]);
    exit;
}

// ── status ────────────────────────────────────────────────────────
if ($action === 'status') {
    $noorder = trim($_POST['noorder'] ?? $_GET['noorder'] ?? '');
    if (!$noorder) { echo json_encode(['status'=>'error','message'=>'noorder wajib']); exit; }
    $stmt = $pdo_simrs->prepare("SELECT * FROM medifix_ss_radiologi WHERE noorder = ?");
    $stmt->execute([$noorder]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    echo $r ? json_encode(['status'=>'ok','data'=>$r]) : json_encode(['status'=>'error','message'=>'Tidak ditemukan']);
    exit;
}

echo json_encode(['status'=>'error','message'=>"Action '$action' tidak dikenal"]);