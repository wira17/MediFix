<?php
/**
 * api/kirim_procedure.php
 * Kirim Procedure ke Satu Sehat FHIR API
 * Actions: kirim | kirim_semua
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

$action = $_POST['action'] ?? '';

// ── Helper: ambil data lengkap ────────────────────────────────────
function fetchProcedureRow(PDO $pdo, string $noRawat, string $kode): ?array {
    $stmt = $pdo->prepare("
        SELECT
            sp.no_rawat, sp.kode, sp.status AS status_rawat, sp.id_procedure,
            r.tgl_registrasi, r.jam_reg, r.kd_pj, r.status_lanjut,
            p.no_rkm_medis, p.nm_pasien, p.tgl_lahir,
            mp.ihs_number,
            se.id_encounter,
            d.nm_dokter, d.ihs_dokter,
            pl.nm_poli
        FROM satu_sehat_procedure sp
        JOIN reg_periksa r              ON sp.no_rawat     = r.no_rawat
        JOIN pasien p                   ON r.no_rkm_medis   = p.no_rkm_medis
        LEFT JOIN medifix_ss_pasien mp  ON p.no_rkm_medis   = mp.no_rkm_medis
        LEFT JOIN satu_sehat_encounter se ON r.no_rawat     = se.no_rawat
        LEFT JOIN dokter d              ON r.kd_dokter      = d.kd_dokter
        LEFT JOIN poliklinik pl         ON r.kd_poli        = pl.kd_poli
        WHERE sp.no_rawat = ? AND sp.kode = ?
        LIMIT 1
    ");
    $stmt->execute([$noRawat, $kode]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── Build body FHIR Procedure ─────────────────────────────────────
function buildProcedureBody(array $row): array {
    $tgl = $row['tgl_registrasi'] ?? date('Y-m-d');

    $body = [
        'resourceType' => 'Procedure',
        'status'       => 'completed',
        'category' => [
            'coding' => [[
                'system'  => 'http://snomed.info/sct',
                'code'    => '387713003',
                'display' => 'Surgical procedure',
            ]]
        ],
        'code' => [
            'coding' => [[
                'system'  => 'http://hl7.org/fhir/sid/icd-9-cm',
                'code'    => $row['kode'],
                'display' => $row['kode'],
            ]],
            'text' => $row['kode'],
        ],
        'subject' => [
            'reference' => 'Patient/' . $row['ihs_number'],
            'display'   => $row['nm_pasien'] ?? '',
        ],
        'performedDateTime' => $tgl,
    ];

    // Encounter opsional
    if (!empty($row['id_encounter'])) {
        $body['encounter'] = ['reference' => 'Encounter/' . $row['id_encounter']];
    }

    // Performer (dokter) opsional
    if (!empty($row['ihs_dokter'])) {
        $body['performer'] = [[
            'actor' => [
                'reference' => 'Practitioner/' . $row['ihs_dokter'],
                'display'   => $row['nm_dokter'] ?? '',
            ]
        ]];
    }

    return $body;
}

// ── POST ke Satu Sehat ────────────────────────────────────────────
function postProcedure(array $row): string {
    if (empty($row['ihs_number'])) {
        throw new RuntimeException('IHS Number pasien kosong — sync IHS pasien terlebih dahulu');
    }
    $body = buildProcedureBody($row);
    $resp = ssPost('Procedure', $body);
    if (empty($resp['id'])) {
        throw new RuntimeException('Response tidak mengandung id Procedure');
    }
    return $resp['id'];
}

// ── Simpan id_procedure ke tabel Khanza ──────────────────────────
function saveProcedureResult(PDO $pdo, string $noRawat, string $kode, string $idProcedure): void {
    $pdo->prepare("
        UPDATE satu_sehat_procedure
        SET id_procedure = ?
        WHERE no_rawat = ? AND kode = ?
    ")->execute([$idProcedure, $noRawat, $kode]);
}

// ══════════════════════════════════════════════════════════════════
//  ACTION: kirim (satu)
// ══════════════════════════════════════════════════════════════════
if ($action === 'kirim') {
    $noRawat = trim($_POST['no_rawat'] ?? '');
    $kode    = trim($_POST['kode']     ?? '');

    if (!$noRawat || !$kode) {
        echo json_encode(['status'=>'error','message'=>'no_rawat dan kode wajib diisi']);
        exit;
    }

    $row = fetchProcedureRow($pdo_simrs, $noRawat, $kode);
    if (!$row) {
        echo json_encode(['status'=>'error','message'=>'Data tidak ditemukan']);
        exit;
    }

    try {
        $idProcedure = postProcedure($row);
        saveProcedureResult($pdo_simrs, $noRawat, $kode, $idProcedure);
        echo json_encode([
            'status'       => 'ok',
            'message'      => 'Procedure berhasil dikirim',
            'no_rawat'     => $noRawat,
            'id_procedure' => $idProcedure,
        ]);
    } catch (Exception $e) {
        echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════
//  ACTION: kirim_semua (batch dengan range tanggal)
// ══════════════════════════════════════════════════════════════════
if ($action === 'kirim_semua') {
    $tglDari   = trim($_POST['tgl_dari']   ?? date('Y-m-d'));
    $tglSampai = trim($_POST['tgl_sampai'] ?? date('Y-m-d'));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglDari))   $tglDari   = date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglSampai)) $tglSampai = date('Y-m-d');
    if ($tglSampai < $tglDari) $tglSampai = $tglDari;

    $stmt = $pdo_simrs->prepare("
        SELECT sp.no_rawat, sp.kode
        FROM satu_sehat_procedure sp
        JOIN reg_periksa r ON sp.no_rawat = r.no_rawat
        WHERE r.tgl_registrasi BETWEEN ? AND ?
          AND (sp.id_procedure IS NULL OR sp.id_procedure = '')
        ORDER BY r.tgl_registrasi ASC, r.jam_reg ASC
    ");
    $stmt->execute([$tglDari, $tglSampai]);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($list)) {
        echo json_encode(['status'=>'ok','message'=>'Tidak ada data pending','jumlah'=>0,'berhasil'=>0,'gagal'=>0]);
        exit;
    }

    $berhasil = 0; $gagal = 0; $errors = [];
    foreach ($list as $item) {
        $row = fetchProcedureRow($pdo_simrs, $item['no_rawat'], $item['kode']);
        if (!$row || empty($row['ihs_number'])) { $gagal++; continue; }
        try {
            $idProcedure = postProcedure($row);
            saveProcedureResult($pdo_simrs, $item['no_rawat'], $item['kode'], $idProcedure);
            $berhasil++;
            usleep(300000);
        } catch (Exception $e) {
            $gagal++;
            $errors[] = $item['no_rawat'] . ': ' . $e->getMessage();
        }
    }

    echo json_encode([
        'status'   => 'ok',
        'jumlah'   => count($list),
        'berhasil' => $berhasil,
        'gagal'    => $gagal,
        'errors'   => array_slice($errors, 0, 10),
    ]);
    exit;
}

echo json_encode(['status'=>'error','message'=>"Action '$action' tidak dikenal"]);