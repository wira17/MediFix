<?php
/**
 * includes/imaging_study.php
 * Build body FHIR ImagingStudy dan kirim ke Satu Sehat
 * Dipanggil setelah DICOM masuk ke Orthanc (via webhook)
 */

require_once __DIR__ . '/satusehat_api.php';
require_once __DIR__ . '/../config/loinc_mapping.php';

// ══════════════════════════════════════════════════════════════════
//  BUILD FHIR BODY
// ══════════════════════════════════════════════════════════════════

/**
 * Bangun body FHIR ImagingStudy.
 *
 * $row    = data dari DB (JOIN satu_sehat + pasien + encounter + dokter)
 * $dicom  = data dari Orthanc/DICOM tags:
 *   study_uid, series_uid, instance_uid, sop_class_uid,
 *   modality, study_date, study_time, series_number, instance_number,
 *   body_part (opsional), series_description (opsional)
 */
function buildImagingStudyBody(array $row, array $dicom): array {
    // Waktu study dari DICOM (format: YYYYMMDD HHMMSS)
    $studyDate = $dicom['study_date'] ?? date('Ymd');
    $studyTime = $dicom['study_time'] ?? '000000';
    try {
        $dt = DateTime::createFromFormat('Ymd His', "$studyDate $studyTime", new DateTimeZone('Asia/Jakarta'));
        $started = $dt ? $dt->format(DateTime::ATOM) : date(DateTime::ATOM);
    } catch (Exception) {
        $started = date(DateTime::ATOM);
    }

    $modality = strtoupper($dicom['modality'] ?? getModalityCode($row['kd_jenis_prw'] ?? ''));

    $body = [
        'resourceType' => 'ImagingStudy',
        'status'       => 'available',
        'identifier'   => [[
            'use'    => 'official',
            'system' => 'urn:dicom:uid',
            'value'  => 'urn:oid:' . ($dicom['study_uid'] ?? ''),
        ]],
        'modality' => [[
            'system'  => 'http://dicom.nema.org/resources/ontology/DCM',
            'code'    => $modality,
            'display' => getModalityDisplay($modality),
        ]],
        'subject' => [
            'reference' => 'Patient/' . $row['ihs_number'],
            'display'   => $row['nm_pasien'] ?? '',
        ],
        'encounter' => [
            'reference' => 'Encounter/' . $row['id_encounter'],
        ],
        'started' => $started,
    ];

    // Referensi ke ServiceRequest (wajib jika sudah ada id_sr)
    if (!empty($row['id_servicerequest'])) {
        $body['basedOn'] = [[
            'reference' => 'ServiceRequest/' . $row['id_servicerequest'],
        ]];
    }

    // Interpreter / dokter radiologi jika ada
    if (!empty($row['ihs_dokter_rad'])) {
        $body['interpreter'] = [[
            'reference' => 'Practitioner/' . $row['ihs_dokter_rad'],
            'display'   => $row['nm_dokter_rad'] ?? '',
        ]];
    }

    // Jumlah series dan instance
    $body['numberOfSeries']    = 1;
    $body['numberOfInstances'] = (int)($dicom['number_of_instances'] ?? 1);

    // Series
    $series = [
        'uid'    => $dicom['series_uid'] ?? $dicom['study_uid'] . '.1',
        'number' => (int)($dicom['series_number'] ?? 1),
        'modality' => [
            'system'  => 'http://dicom.nema.org/resources/ontology/DCM',
            'code'    => $modality,
            'display' => getModalityDisplay($modality),
        ],
        'numberOfInstances' => (int)($dicom['number_of_instances'] ?? 1),
        'instance' => [[
            'uid'    => $dicom['instance_uid'] ?? ($dicom['series_uid'] . '.1'),
            'number' => (int)($dicom['instance_number'] ?? 1),
            'sopClass' => [
                'system' => 'urn:ietf:rfc:3986',
                'code'   => $dicom['sop_class_uid'] ?? '1.2.840.10008.5.1.4.1.1.2',
            ],
        ]],
    ];

    // Body part
    if (!empty($dicom['body_part'])) {
        $series['bodySite'] = [
            'system'  => 'http://snomed.info/sct',
            'display' => $dicom['body_part'],
        ];
    }

    // Deskripsi series
    if (!empty($dicom['series_description'])) {
        $series['description'] = $dicom['series_description'];
    }

    $body['series'] = [$series];

    // AccessionNumber sebagai identifier tambahan
    if (!empty($row['noorder'])) {
        $body['identifier'][] = [
            'use'    => 'usual',
            'system' => 'http://sys-ids.kemkes.go.id/acsn/' . SS_ORG_ID,
            'value'  => $row['noorder'],
        ];
    }

    return $body;
}

// ══════════════════════════════════════════════════════════════════
//  KIRIM KE SATU SEHAT
// ══════════════════════════════════════════════════════════════════

/**
 * POST ImagingStudy ke Satu Sehat.
 * Returns: string id_imagingstudy
 */
function postImagingStudy(array $row, array $dicom): string {
    if (empty($row['ihs_number']))   throw new RuntimeException('ihs_number kosong');
    if (empty($row['id_encounter'])) throw new RuntimeException('id_encounter kosong');
    if (empty($dicom['study_uid']))  throw new RuntimeException('study_uid DICOM kosong');

    $body = buildImagingStudyBody($row, $dicom);
    $resp = ssPost('ImagingStudy', $body);

    if (empty($resp['id'])) {
        throw new RuntimeException('Response ImagingStudy tidak mengandung id');
    }
    return $resp['id'];
}

// ══════════════════════════════════════════════════════════════════
//  SIMPAN KE DB
// ══════════════════════════════════════════════════════════════════

/**
 * Simpan id_imagingstudy ke tabel setelah berhasil kirim.
 */
function saveImagingStudyResult(PDO $pdo, string $noorder, string $idIS, string $studyUid): void {
    $stmt = $pdo->prepare("
        UPDATE satu_sehat_servicerequest_radiologi
        SET id_imagingstudy  = ?,
            study_uid_dicom  = ?,
            tgl_kirim_is     = NOW(),
            status_kirim_is  = 'terkirim',
            error_msg_is     = NULL
        WHERE noorder = ?
    ");
    $stmt->execute([$idIS, $studyUid, $noorder]);
}

/**
 * Catat error ImagingStudy.
 */
function saveImagingStudyError(PDO $pdo, string $noorder, string $errorMsg): void {
    $stmt = $pdo->prepare("
        UPDATE satu_sehat_servicerequest_radiologi
        SET status_kirim_is = 'error',
            error_msg_is    = ?
        WHERE noorder = ?
    ");
    $stmt->execute([mb_substr($errorMsg, 0, 500), $noorder]);
}

// ══════════════════════════════════════════════════════════════════
//  HELPER
// ══════════════════════════════════════════════════════════════════

function getModalityDisplay(string $code): string {
    return match(strtoupper($code)) {
        'CR'  => 'Computed Radiography',
        'DX'  => 'Digital Radiography',
        'CT'  => 'Computed Tomography',
        'MR'  => 'Magnetic Resonance',
        'US'  => 'Ultrasound',
        'RF'  => 'Radio Fluoroscopy',
        'MG'  => 'Mammography',
        'XA'  => 'X-Ray Angiography',
        'NM'  => 'Nuclear Medicine',
        'PT'  => 'Positron emission tomography',
        'OT'  => 'Other',
        default => $code,
    };
}
