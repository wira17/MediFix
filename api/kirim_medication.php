<?php
/**
 * api/kirim_medication.php
 * Kirim Medication (master obat/alkes) ke Satu Sehat FHIR API
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

// ── Helper: ambil data obat ───────────────────────────────────────
function fetchMedicationRow(PDO $pdo, string $kodeBrng): ?array {
    $stmt = $pdo->prepare("
        SELECT
            m.kode_brng, m.id_medication,
            db.nama_brng, db.kdjns,
            db.kode_satbesar, db.kode_sat,
            db.isi, db.kapasitas,
            db.kode_industri, db.kode_kategori, db.kode_golongan,
            db.status AS status_brng, db.expire
        FROM satu_sehat_medication m
        LEFT JOIN databarang db ON m.kode_brng = db.kode_brng
        WHERE m.kode_brng = ?
        LIMIT 1
    ");
    $stmt->execute([$kodeBrng]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── Build body FHIR Medication ────────────────────────────────────
function buildMedicationBody(array $row): array {
    $body = [
        'resourceType' => 'Medication',
        'meta' => [
            'profile' => ['https://fhir.kemkes.go.id/r4/StructureDefinition/Medication']
        ],
        'identifier' => [[
            'system' => 'http://sys-ids.kemkes.go.id/medication/' . SS_ORG_ID,
            'use'    => 'official',
            'value'  => $row['kode_brng'],
        ]],
        'code' => [
            'coding' => [[
                'system'  => 'http://sys-ids.kemkes.go.id/kfa',
                'code'    => $row['kode_brng'],
                'display' => $row['nama_brng'] ?? $row['kode_brng'],
            ]],
            'text' => $row['nama_brng'] ?? $row['kode_brng'],
        ],
        'status' => ($row['status_brng'] ?? '1') === '1' ? 'active' : 'inactive',
    ];

    // Manufacturer dari kode industri jika ada
    if (!empty($row['kode_industri'])) {
        $body['manufacturer'] = [
            'display' => $row['kode_industri'],
        ];
    }

    // Form sediaan dari kode golongan
    if (!empty($row['kode_golongan'])) {
        $body['form'] = [
            'coding' => [[
                'system'  => 'http://terminology.hl7.org/CodeSystem/v3-orderableDrugForm',
                'code'    => $row['kode_golongan'],
                'display' => $row['kode_golongan'],
            ]],
        ];
    }

    // Extension: isi dan satuan
    if (!empty($row['isi']) && !empty($row['kode_satbesar'])) {
        $body['amount'] = [
            'numerator' => [
                'value'  => (float)$row['isi'],
                'system' => 'http://terminology.hl7.org/CodeSystem/v3-orderableDrugForm',
                'code'   => $row['kode_satbesar'],
                'unit'   => $row['kode_satbesar'],
            ],
            'denominator' => [
                'value'  => 1,
                'system' => 'http://terminology.hl7.org/CodeSystem/v3-orderableDrugForm',
                'code'   => $row['kode_sat'] ?? $row['kode_satbesar'],
                'unit'   => $row['kode_sat'] ?? $row['kode_satbesar'],
            ],
        ];
    }

    return $body;
}

// ── POST ke Satu Sehat ────────────────────────────────────────────
function postMedication(array $row): string {
    if (empty($row['nama_brng'])) {
        throw new RuntimeException('Nama barang kosong di databarang');
    }
    $body = buildMedicationBody($row);
    $resp = ssPost('Medication', $body);
    if (empty($resp['id'])) {
        throw new RuntimeException('Response tidak mengandung id Medication');
    }
    return $resp['id'];
}

// ── Simpan id_medication ke tabel Khanza ─────────────────────────
function saveMedicationResult(PDO $pdo, string $kodeBrng, string $idMedication): void {
    $pdo->prepare("
        UPDATE satu_sehat_medication
        SET id_medication = ?
        WHERE kode_brng = ?
    ")->execute([$idMedication, $kodeBrng]);
}

// ══════════════════════════════════════════════════════════════════
//  ACTION: kirim (satu)
// ══════════════════════════════════════════════════════════════════
if ($action === 'kirim') {
    $kodeBrng = trim($_POST['kode_brng'] ?? '');
    if (!$kodeBrng) {
        echo json_encode(['status'=>'error','message'=>'kode_brng wajib diisi']);
        exit;
    }

    $row = fetchMedicationRow($pdo_simrs, $kodeBrng);
    if (!$row) {
        echo json_encode(['status'=>'error','message'=>"Barang $kodeBrng tidak ditemukan"]);
        exit;
    }

    try {
        $idMedication = postMedication($row);
        saveMedicationResult($pdo_simrs, $kodeBrng, $idMedication);
        echo json_encode([
            'status'       => 'ok',
            'message'      => 'Medication berhasil dikirim',
            'kode_brng'    => $kodeBrng,
            'id_medication'=> $idMedication,
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
    $stmt = $pdo_simrs->query("
        SELECT kode_brng FROM satu_sehat_medication
        WHERE id_medication IS NULL OR id_medication = ''
        ORDER BY kode_brng ASC
    ");
    $list = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($list)) {
        echo json_encode(['status'=>'ok','message'=>'Tidak ada data pending','jumlah'=>0,'berhasil'=>0,'gagal'=>0]);
        exit;
    }

    $berhasil = 0; $gagal = 0; $errors = [];
    foreach ($list as $kodeBrng) {
        $row = fetchMedicationRow($pdo_simrs, $kodeBrng);
        if (!$row || empty($row['nama_brng'])) { $gagal++; continue; }
        try {
            $idMedication = postMedication($row);
            saveMedicationResult($pdo_simrs, $kodeBrng, $idMedication);
            $berhasil++;
            usleep(200000); // 200ms
        } catch (Exception $e) {
            $gagal++;
            $errors[] = "$kodeBrng: " . $e->getMessage();
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