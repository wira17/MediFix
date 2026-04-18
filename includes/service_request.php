<?php
/**
 * includes/service_request.php
 * Build body FHIR ServiceRequest dan kirim ke Satu Sehat
 */

require_once __DIR__ . '/satusehat_api.php';
require_once __DIR__ . '/../config/loinc_mapping.php';

// ══════════════════════════════════════════════════════════════════
//  BUILD FHIR BODY
// ══════════════════════════════════════════════════════════════════

/**
 * Bangun array body FHIR ServiceRequest dari row database.
 *
 * Kolom wajib di $row:
 *   noorder, kd_jenis_prw, nm_jenis_prw (opsional),
 *   ihs_number (no IHS pasien),
 *   id_encounter, ihs_dokter,
 *   tgl_permintaan, jam_permintaan,
 *   diagnosa_klinis, informasi_tambahan
 */
function buildServiceRequestBody(array $row): array {
    [$loincCode, $loincDisplay] = getLoinc($row['kd_jenis_prw']);
    $modality = getModalityCode($row['kd_jenis_prw']);

    // Gabungkan tanggal + jam → ISO 8601
    $tglJam = trim(($row['tgl_permintaan'] ?? '') . ' ' . ($row['jam_permintaan'] ?? '00:00:00'));
    try {
        $dt = new DateTime($tglJam, new DateTimeZone('Asia/Jakarta'));
        $authoredOn = $dt->format(DateTime::ATOM);
    } catch (Exception) {
        $authoredOn = date(DateTime::ATOM);
    }

    $body = [
        'resourceType' => 'ServiceRequest',
        'status'       => 'active',
        'intent'       => 'order',
        'category'     => [[
            'coding' => [[
                'system'  => 'http://snomed.info/sct',
                'code'    => '363679005',
                'display' => 'Imaging',
            ]]
        ]],
        'code' => [
            'coding' => [[
                'system'  => str_starts_with($loincCode, '3636') ? 'http://snomed.info/sct' : 'http://loinc.org',
                'code'    => $loincCode,
                'display' => $loincDisplay,
            ]],
            'text' => $row['nm_jenis_prw'] ?? $loincDisplay,
        ],
        'subject' => [
            'reference' => 'Patient/' . $row['ihs_number'],
            'display'   => $row['nm_pasien'] ?? '',
        ],
        'encounter' => [
            'reference' => 'Encounter/' . $row['id_encounter'],
        ],
        'authoredOn' => $authoredOn,
    ];

    // Encounter — opsional, ambil dari satu_sehat_encounter
    if (!empty($row['id_encounter'])) {
        $body['encounter'] = ['reference' => 'Encounter/' . $row['id_encounter']];
    }

    // Dokter perujuk — opsional
    if (!empty($row['ihs_dokter'])) {
        $body['requester'] = [
            'reference' => 'Practitioner/' . $row['ihs_dokter'],
            'display'   => $row['nm_dokter'] ?? '',
        ];
    }

    // Identifer: noorder sebagai ACSN (Accession Number)
    $body['identifier'] = [[
        'use'    => 'usual',
        'system' => 'http://sys-ids.kemkes.go.id/acsn/' . SS_ORG_ID,
        'value'  => $row['noorder'],
    ]];

    // Diagnosa klinis sebagai reasonCode
    if (!empty($row['diagnosa_klinis'])) {
        $body['reasonCode'] = [[
            'text' => $row['diagnosa_klinis'],
        ]];
    }

    // Informasi tambahan sebagai note
    if (!empty($row['informasi_tambahan'])) {
        $body['note'] = [[
            'text' => $row['informasi_tambahan'],
        ]];
    }

    // Poli / lokasi
    if (!empty($row['nm_poli'])) {
        $body['locationReference'] = [[
            'display' => $row['nm_poli'],
        ]];
    }

    // Jenis rawat (ranap / ralan)
    if (!empty($row['status_rawat'])) {
        $body['extension'][] = [
            'url'         => 'https://fhir.kemkes.go.id/r4/StructureDefinition/serviceRequestClass',
            'valueString' => strtolower($row['status_rawat']) === 'ranap' ? 'inpatient' : 'outpatient',
        ];
    }

    return $body;
}

// ══════════════════════════════════════════════════════════════════
//  KIRIM KE SATU SEHAT
// ══════════════════════════════════════════════════════════════════

/**
 * POST ServiceRequest ke Satu Sehat.
 * Returns: string id_servicerequest
 */
function postServiceRequest(array $row): string {
    if (empty($row['ihs_number']))  throw new RuntimeException('ihs_number pasien kosong — sync IHS pasien terlebih dahulu di menu Sinkronisasi IHS Pasien');

    $body = buildServiceRequestBody($row);
    $resp = ssPost('ServiceRequest', $body);

    if (empty($resp['id'])) {
        throw new RuntimeException('Response Satu Sehat tidak mengandung id resource');
    }
    return $resp['id'];
}

/**
 * Kirim ulang (PUT) ServiceRequest yang sudah pernah dikirim.
 * Diperlukan jika ada perubahan data.
 */
function updateServiceRequest(string $idSR, array $row): string {
    $body       = buildServiceRequestBody($row);
    $body['id'] = $idSR;
    $resp = ssPut('ServiceRequest', $idSR, $body);
    return $resp['id'] ?? $idSR;
}

// ══════════════════════════════════════════════════════════════════
//  SIMPAN KE DB
// ══════════════════════════════════════════════════════════════════

/**
 * Update tabel satu_sehat_servicerequest_radiologi setelah berhasil kirim.
 */
function saveServiceRequestResult(PDO $pdo, string $noorder, string $idSR): void {
    $stmt = $pdo->prepare("
        UPDATE satu_sehat_servicerequest_radiologi
        SET id_servicerequest = ?,
            tgl_kirim_sr      = NOW(),
            status_kirim_sr   = 'terkirim',
            error_msg_sr      = NULL
        WHERE noorder = ?
    ");
    $stmt->execute([$idSR, $noorder]);
}

/**
 * Catat error pengiriman ServiceRequest ke database.
 */
function saveServiceRequestError(PDO $pdo, string $noorder, string $errorMsg): void {
    $stmt = $pdo->prepare("
        UPDATE satu_sehat_servicerequest_radiologi
        SET status_kirim_sr = 'error',
            error_msg_sr    = ?
        WHERE noorder = ?
    ");
    $stmt->execute([mb_substr($errorMsg, 0, 500), $noorder]);
}