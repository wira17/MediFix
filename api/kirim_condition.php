<?php
/**
 * api/kirim_condition.php
 * Kirim Condition (Diagnosis) ke Satu Sehat FHIR API
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

// ── Helper: ambil data lengkap satu no_rawat + kd_penyakit ───────
function fetchConditionRow(PDO $pdo, string $noRawat, string $kdPenyakit): ?array {
    $stmt = $pdo->prepare("
        SELECT
            c.no_rawat, c.kd_penyakit, c.status AS status_rawat, c.id_condition,
            r.tgl_registrasi, r.jam_reg, r.kd_pj, r.status_lanjut,
            p.no_rkm_medis, p.nm_pasien, p.tgl_lahir,
            mp.ihs_number,
            se.id_encounter,
            d.nm_dokter,
            pl.nm_poli,
            pk.nm_penyakit
        FROM satu_sehat_condition c
        JOIN reg_periksa r         ON c.no_rawat      = r.no_rawat
        JOIN pasien p              ON r.no_rkm_medis   = p.no_rkm_medis
        LEFT JOIN medifix_ss_pasien mp  ON p.no_rkm_medis = mp.no_rkm_medis
        LEFT JOIN satu_sehat_encounter se ON r.no_rawat  = se.no_rawat
        LEFT JOIN dokter d         ON r.kd_dokter      = d.kd_dokter
        LEFT JOIN poliklinik pl    ON r.kd_poli        = pl.kd_poli
        LEFT JOIN penyakit pk      ON c.kd_penyakit    = pk.kd_penyakit
        WHERE c.no_rawat = ? AND c.kd_penyakit = ?
        LIMIT 1
    ");
    $stmt->execute([$noRawat, $kdPenyakit]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── Build body FHIR Condition ─────────────────────────────────────
function buildConditionBody(array $row): array {
    $tgl = $row['tgl_registrasi'] ?? date('Y-m-d');
    $body = [
        'resourceType' => 'Condition',
        'clinicalStatus' => [
            'coding' => [[
                'system'  => 'http://terminology.hl7.org/CodeSystem/condition-clinical',
                'code'    => 'active',
                'display' => 'Active',
            ]]
        ],
        'category' => [[
            'coding' => [[
                'system'  => 'http://terminology.hl7.org/CodeSystem/condition-category',
                'code'    => 'encounter-diagnosis',
                'display' => 'Encounter Diagnosis',
            ]]
        ]],
        'code' => [
            'coding' => [[
                'system'  => 'http://hl7.org/fhir/sid/icd-10',
                'code'    => $row['kd_penyakit'],
                'display' => $row['nm_penyakit'] ?? $row['kd_penyakit'],
            ]],
            'text' => $row['nm_penyakit'] ?? $row['kd_penyakit'],
        ],
        'subject' => [
            'reference' => 'Patient/' . $row['ihs_number'],
            'display'   => $row['nm_pasien'] ?? '',
        ],
        'recordedDate' => $tgl,
    ];

    // Encounter opsional
    if (!empty($row['id_encounter'])) {
        $body['encounter'] = ['reference' => 'Encounter/' . $row['id_encounter']];
    }

    // Onset date
    $body['onsetDateTime'] = $tgl;

    return $body;
}

// ── POST Condition ke Satu Sehat ──────────────────────────────────
function postCondition(array $row): string {
    if (empty($row['ihs_number'])) {
        throw new RuntimeException('IHS Number pasien kosong — sync IHS pasien terlebih dahulu');
    }
    $body = buildConditionBody($row);
    $resp = ssPost('Condition', $body);
    if (empty($resp['id'])) {
        throw new RuntimeException('Response tidak mengandung id Condition');
    }
    return $resp['id'];
}

// ── Update tabel Khanza (hanya kolom id_condition) ───────────────
function saveConditionResult(PDO $pdo, string $noRawat, string $kdPenyakit, string $idCondition): void {
    $pdo->prepare("
        UPDATE satu_sehat_condition
        SET id_condition = ?
        WHERE no_rawat = ? AND kd_penyakit = ?
    ")->execute([$idCondition, $noRawat, $kdPenyakit]);
}

// ══════════════════════════════════════════════════════════════════
//  ACTION: kirim (satu)
// ══════════════════════════════════════════════════════════════════
if ($action === 'kirim') {
    $noRawat    = trim($_POST['no_rawat']    ?? '');
    $kdPenyakit = trim($_POST['kd_penyakit'] ?? '');

    if (!$noRawat || !$kdPenyakit) {
        echo json_encode(['status'=>'error','message'=>'no_rawat dan kd_penyakit wajib diisi']);
        exit;
    }

    $row = fetchConditionRow($pdo_simrs, $noRawat, $kdPenyakit);
    if (!$row) {
        echo json_encode(['status'=>'error','message'=>"Data tidak ditemukan"]);
        exit;
    }

    try {
        $idCondition = postCondition($row);
        saveConditionResult($pdo_simrs, $noRawat, $kdPenyakit, $idCondition);
        echo json_encode([
            'status'       => 'ok',
            'message'      => 'Condition berhasil dikirim',
            'no_rawat'     => $noRawat,
            'id_condition' => $idCondition,
        ]);
    } catch (Exception $e) {
        echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════
//  ACTION: kirim_semua (batch)
// ══════════════════════════════════════════════════════════════════
if ($action === 'kirim_semua') {
    $tanggal = trim($_POST['tanggal'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        echo json_encode(['status'=>'error','message'=>'Format tanggal tidak valid']); exit;
    }

    $stmt = $pdo_simrs->prepare("
        SELECT c.no_rawat, c.kd_penyakit
        FROM satu_sehat_condition c
        JOIN reg_periksa r ON c.no_rawat = r.no_rawat
        WHERE r.tgl_registrasi = ?
          AND (c.id_condition IS NULL OR c.id_condition = '')
        ORDER BY r.jam_reg ASC
    ");
    $stmt->execute([$tanggal]);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($list)) {
        echo json_encode(['status'=>'ok','message'=>'Tidak ada data pending','jumlah'=>0,'berhasil'=>0,'gagal'=>0]);
        exit;
    }

    $berhasil = 0; $gagal = 0; $errors = [];
    foreach ($list as $item) {
        $row = fetchConditionRow($pdo_simrs, $item['no_rawat'], $item['kd_penyakit']);
        if (!$row || empty($row['ihs_number'])) { $gagal++; continue; }
        try {
            $idCondition = postCondition($row);
            saveConditionResult($pdo_simrs, $item['no_rawat'], $item['kd_penyakit'], $idCondition);
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