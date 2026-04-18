<?php
/**
 * config/loinc_mapping.php
 * Mapping kd_jenis_prw SIMRS → kode LOINC untuk ServiceRequest
 *
 * Cara penggunaan:
 *   include 'config/loinc_mapping.php';
 *   $loinc = getLoinc($kd_jenis_prw);
 *
 * Tambahkan mapping sesuai kd_jenis_prw di SIMRS Khanza Anda.
 * Referensi kode LOINC radiologi: https://loinc.org/radiology/
 */

function getLoinc(string $kd): array {
    $map = [
        // ── Foto Polos / Plain Film ──────────────────────────────────
        'RO-THX'    => ['24748-1',  'Chest X-ray'],
        'RO-THXPA'  => ['24748-1',  'Chest X-ray PA'],
        'RO-THXAP'  => ['36643-5',  'Chest X-ray AP'],
        'RO-ABD'    => ['28565-3',  'Abdomen X-ray'],
        'RO-BNO'    => ['28565-3',  'BNO X-ray'],
        'RO-LS'     => ['36554-4',  'Lumbar spine X-ray'],
        'RO-CS'     => ['36643-5',  'Cervical spine X-ray'],
        'RO-TS'     => ['36554-4',  'Thoracic spine X-ray'],
        'RO-PEL'    => ['36722-7',  'Pelvis X-ray'],
        'RO-KNEE'   => ['36354-9',  'Knee X-ray'],
        'RO-ANKLE'  => ['36642-7',  'Ankle X-ray'],
        'RO-FOOT'   => ['36642-7',  'Foot X-ray'],
        'RO-HAND'   => ['36643-5',  'Hand X-ray'],
        'RO-WRIST'  => ['36643-5',  'Wrist X-ray'],
        'RO-ELBOW'  => ['36643-5',  'Elbow X-ray'],
        'RO-SHLDR'  => ['36643-5',  'Shoulder X-ray'],
        'RO-SKULL'  => ['24727-5',  'Skull X-ray'],
        'RO-SINUS'  => ['36643-5',  'Paranasal sinus X-ray'],

        // ── CT Scan ──────────────────────────────────────────────────
        'CT-THX'    => ['24627-7',  'CT Chest'],
        'CT-ABD'    => ['24550-3',  'CT Abdomen'],
        'CT-HEAD'   => ['24725-9',  'CT Head'],
        'CT-BRAIN'  => ['24725-9',  'CT Brain'],
        'CT-LS'     => ['24957-8',  'CT Lumbar spine'],
        'CT-CS'     => ['24957-8',  'CT Cervical spine'],
        'CT-PEL'    => ['24566-9',  'CT Pelvis'],
        'CT-ABDPEL' => ['28561-2',  'CT Abdomen and Pelvis'],
        'CT-CTA'    => ['24601-2',  'CTA'],
        'CT-SINUS'  => ['36136-0',  'CT Paranasal sinus'],
        'CT-CHEST'  => ['24627-7',  'CT Chest with contrast'],

        // ── MRI ──────────────────────────────────────────────────────
        'MR-BRAIN'  => ['24590-2',  'MRI Brain'],
        'MR-LS'     => ['24967-7',  'MRI Lumbar spine'],
        'MR-CS'     => ['24967-7',  'MRI Cervical spine'],
        'MR-KNEE'   => ['24719-2',  'MRI Knee'],
        'MR-ABD'    => ['24558-6',  'MRI Abdomen'],
        'MR-PEL'    => ['24578-7',  'MRI Pelvis'],
        'MR-SHLDR'  => ['24719-2',  'MRI Shoulder'],
        'MR-MRA'    => ['24590-2',  'MRA'],
        'MR-MRCP'   => ['24558-6',  'MRCP'],

        // ── USG ──────────────────────────────────────────────────────
        'US-ABD'    => ['24557-8',  'USG Abdomen'],
        'US-HATI'   => ['24557-8',  'USG Hepar'],
        'US-GINJAL' => ['24557-8',  'USG Renal'],
        'US-PELVIC' => ['24577-9',  'USG Pelvis'],
        'US-OB'     => ['24535-4',  'USG Obstetri'],
        'US-THYRD'  => ['24635-0',  'USG Thyroid'],
        'US-MSK'    => ['24719-2',  'USG Muskuloskeletal'],
        'US-DOPPL'  => ['24557-8',  'USG Doppler'],
        'US-ECHO'   => ['11522-0',  'Echocardiografi'],
        'US-TRANV'  => ['24577-9',  'USG Transvaginal'],
        'US-SCROT'  => ['24557-8',  'USG Scrotum'],
        'US-MAMMA'  => ['24606-1',  'USG Mammae'],

        // ── Fluoroskopi ──────────────────────────────────────────────
        'FL-UGI'    => ['24768-9',  'Upper GI fluoroscopy'],
        'FL-BNO'    => ['28565-3',  'BNO fluoroscopy'],
        'FL-IVP'    => ['24536-2',  'IVP'],
        'FL-HSG'    => ['24735-8',  'HSG'],
        'FL-MCU'    => ['24768-9',  'MCU'],
        'FL-COLON'  => ['24773-9',  'Colon in loop'],

        // ── Mammografi ───────────────────────────────────────────────
        'MG-MAMMA'  => ['24606-1',  'Mammografi'],

        // ── Intervensi Radiologi ─────────────────────────────────────
        'IR-BIOPSI' => ['26443-9',  'Biopsy image guided'],
        'IR-DRAINS' => ['26443-9',  'Drainage image guided'],
    ];

    // Coba exact match dulu, lalu case-insensitive
    if (isset($map[$kd])) return $map[$kd];
    $upper = strtoupper($kd);
    if (isset($map[$upper])) return $map[$upper];

    // Fallback: cari partial match
    foreach ($map as $k => $v) {
        if (str_contains($upper, explode('-', $k)[0])) return $v;
    }

    // Default jika tidak ditemukan
    return ['363679005', 'Imaging (SNOMED)'];
}

/**
 * Dapatkan kode modality DICOM dari kd_jenis_prw
 */
function getModalityCode(string $kd): string {
    $prefix = strtoupper(explode('-', $kd)[0] ?? '');
    return match($prefix) {
        'CT'    => 'CT',
        'MR'    => 'MR',
        'US'    => 'US',
        'FL'    => 'RF',   // Fluoroscopy
        'MG'    => 'MG',   // Mammografi
        'IR'    => 'XA',   // Interventional
        default => 'CR',   // Computed Radiography (X-ray default)
    };
}
