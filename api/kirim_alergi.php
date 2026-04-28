<?php
/**
 * api/kirim_alergi.php
 * Kirim AllergyIntolerance ke Satu Sehat
 * Sumber: pemeriksaan_ralan + pemeriksaan_ranap
 * Simpan ke: satu_sehat_allergy_intolerance
 */

header('Content-Type: application/json');

if (!defined('SS_CLIENT_ID')) {
    require_once __DIR__ . '/../config/env.php';
    if (isset($pdo))       loadSatuSehatConfig($pdo);
    elseif (isset($pdo_simrs)) loadSatuSehatConfig($pdo_simrs);
}
if (!defined('SS_CLIENT_ID') || SS_CLIENT_ID === '') {
    echo json_encode(['status' => 'error', 'message' => 'Konfigurasi Satu Sehat belum diatur']);
    exit;
}
if (!isset($pdo_simrs) && isset($pdo)) $pdo_simrs = $pdo;

function jsonOutA(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

function logALG(string $msg): void {
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents("$dir/alergi_" . date('Y-m') . ".log",
        date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
}

// ── Token ─────────────────────────────────────────────────────────
function getTokenALG(): string {
    $cache = SS_TOKEN_CACHE_FILE;
    if (file_exists($cache)) {
        $c = json_decode(file_get_contents($cache), true);
        if (!empty($c['access_token']) && ($c['expires_at'] ?? 0) > time() + 60)
            return $c['access_token'];
    }
    $ch = curl_init(SS_OAUTH_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS     => http_build_query(['client_id' => SS_CLIENT_ID, 'client_secret' => SS_CLIENT_SECRET]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200) throw new Exception("Auth gagal HTTP $code");
    $data = json_decode($resp, true);
    if (empty($data['access_token'])) throw new Exception("Token kosong");
    file_put_contents($cache, json_encode([
        'access_token' => $data['access_token'],
        'expires_at'   => time() + (int)($data['expires_in'] ?? 3600),
    ]));
    return $data['access_token'];
}

// ── GET IHS Practitioner ──────────────────────────────────────────
function getIHSDokterALG(string $nik, string $token): string {
    if (empty($nik)) return '';
    $ch = curl_init(SS_FHIR_URL . '/Practitioner?identifier=https://fhir.kemkes.go.id/id/nik|' . urlencode($nik));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch); curl_close($ch);
    return json_decode($resp, true)['entry'][0]['resource']['id'] ?? '';
}

// ── Mapping keyword alergi → kode FHIR ───────────────────────────
// category: medication | food | environment | biologic
function mappingAlergi(string $namaAlergi): array {
    $q = strtolower(trim($namaAlergi));

    $mappings = [
        // Antibiotik
        ['keywords' => ['amoxicil','amoksisil','amoxsicil','amoxcilin'],
         'category' => 'medication', 'system' => 'http://www.nlm.nih.gov/research/umls/rxnorm',
         'code' => '723', 'display' => 'Amoxicillin', 'text' => 'Amoxicillin'],
        ['keywords' => ['ceftriaxon','seftriakson'],
         'category' => 'medication', 'system' => 'http://www.nlm.nih.gov/research/umls/rxnorm',
         'code' => '2193', 'display' => 'Ceftriaxone', 'text' => 'Ceftriaxone'],
        ['keywords' => ['cefadroxil','sepadroksil'],
         'category' => 'medication', 'system' => 'http://www.nlm.nih.gov/research/umls/rxnorm',
         'code' => '2231', 'display' => 'Cefadroxil', 'text' => 'Cefadroxil'],
        ['keywords' => ['ampisilin','ampicillin'],
         'category' => 'medication', 'system' => 'http://www.nlm.nih.gov/research/umls/rxnorm',
         'code' => '733', 'display' => 'Ampicillin', 'text' => 'Ampicillin'],
        ['keywords' => ['penisilin','penicillin'],
         'category' => 'medication', 'system' => 'http://www.nlm.nih.gov/research/umls/rxnorm',
         'code' => '7980', 'display' => 'Penicillin', 'text' => 'Penicillin'],
        ['keywords' => ['eritromisin','erythromycin'],
         'category' => 'medication', 'system' => 'http://www.nlm.nih.gov/research/umls/rxnorm',
         'code' => '4053', 'display' => 'Erythromycin', 'text' => 'Erythromycin'],
        ['keywords' => ['ciprofloxacin','siprofloksasin','cipro'],
         'category' => 'medication', 'system' => 'http://www.nlm.nih.gov/research/umls/rxnorm',
         'code' => '2551', 'display' => 'Ciprofloxacin', 'text' => 'Ciprofloxacin'],
        ['keywords' => ['metronidazol','metronidazole','flagyl'],
         'category' => 'medication', 'system' => 'http://www.nlm.nih.gov/research/umls/rxnorm',
         'code' => '6922', 'display' => 'Metronidazole', 'text' => 'Metronidazole'],
        ['keywords' => ['kotrimoksazol','cotrimoxazole','bactrim','sulfametoksazol'],
         'category' => 'medication', 'system' => 'http://www.nlm.nih.gov/research/umls/rxnorm',
         'code' => '10829', 'display' => 'Sulfamethoxazole / Trimethoprim', 'text' => 'Cotrimoxazole'],
        ['keywords' => ['tetrasiklin','tetracycline'],
         'category' => 'medication', 'system' => 'http://www.nlm.nih.gov/research/umls/rxnorm',
         'code' => '10395', 'display' => 'Tetracycline', 'text' => 'Tetracycline'],
        ['keywords' => ['klindamisin','clindamycin'],
         'category' => 'medication', 'system' => 'http://www.nlm.nih.gov/research/umls/rxnorm',
         'code' => '2582', 'display' => 'Clindamycin', 'text' => 'Clindamycin'],
        // NSAID / Analgetik
        ['keywords' => ['ibuprofen','ibupropen','brufen'],
         'category' => 'medication', 'system' => 'http://www.nlm.nih.gov/research/umls/rxnorm',
         'code' => '5640', 'display' => 'Ibuprofen', 'text' => 'Ibuprofen'],
        ['keywords' => ['aspirin','asam asetilsalisilat','asetosal'],
         'category' => 'medication', 'system' => 'http://www.nlm.nih.gov/research/umls/rxnorm',
         'code' => '1191', 'display' => 'Aspirin', 'text' => 'Aspirin'],
        ['keywords' => ['paracetamol','parasetamol','acetaminophen','panadol','pamol'],
         'category' => 'medication', 'system' => 'http://www.nlm.nih.gov/research/umls/rxnorm',
         'code' => '161', 'display' => 'Acetaminophen', 'text' => 'Paracetamol'],
        ['keywords' => ['paramex'],
         'category' => 'medication', 'system' => 'http://www.nlm.nih.gov/research/umls/rxnorm',
         'code' => '161', 'display' => 'Acetaminophen', 'text' => 'Paramex (Paracetamol)'],
        ['keywords' => ['diklofenak','diclofenac','voltaren'],
         'category' => 'medication', 'system' => 'http://www.nlm.nih.gov/research/umls/rxnorm',
         'code' => '3355', 'display' => 'Diclofenac', 'text' => 'Diclofenac'],
        ['keywords' => ['mefenamat','mefenamic','ponstan'],
         'category' => 'medication', 'system' => 'http://www.nlm.nih.gov/research/umls/rxnorm',
         'code' => '41493', 'display' => 'Mefenamic acid', 'text' => 'Mefenamic Acid'],
        ['keywords' => ['ketorolac','ketoprofen'],
         'category' => 'medication', 'system' => 'http://www.nlm.nih.gov/research/umls/rxnorm',
         'code' => '35827', 'display' => 'Ketorolac', 'text' => 'Ketorolac'],
        ['keywords' => ['tramadol'],
         'category' => 'medication', 'system' => 'http://www.nlm.nih.gov/research/umls/rxnorm',
         'code' => '10689', 'display' => 'Tramadol', 'text' => 'Tramadol'],
        ['keywords' => ['morfin','morphine'],
         'category' => 'medication', 'system' => 'http://www.nlm.nih.gov/research/umls/rxnorm',
         'code' => '7052', 'display' => 'Morphine', 'text' => 'Morphine'],
        // Obat lain
        ['keywords' => ['captopril'],
         'category' => 'medication', 'system' => 'http://www.nlm.nih.gov/research/umls/rxnorm',
         'code' => '1998', 'display' => 'Captopril', 'text' => 'Captopril'],
        ['keywords' => ['amlodipine','amlodipine','amlodipin'],
         'category' => 'medication', 'system' => 'http://www.nlm.nih.gov/research/umls/rxnorm',
         'code' => '17767', 'display' => 'Amlodipine', 'text' => 'Amlodipine'],
        ['keywords' => ['allopurinol','alopurinol'],
         'category' => 'medication', 'system' => 'http://www.nlm.nih.gov/research/umls/rxnorm',
         'code' => '519', 'display' => 'Allopurinol', 'text' => 'Allopurinol'],
        ['keywords' => ['dexamethason','deksametason','dexamethasone'],
         'category' => 'medication', 'system' => 'http://www.nlm.nih.gov/research/umls/rxnorm',
         'code' => '3264', 'display' => 'Dexamethasone', 'text' => 'Dexamethasone'],
        ['keywords' => ['codein','kodein','codeine'],
         'category' => 'medication', 'system' => 'http://www.nlm.nih.gov/research/umls/rxnorm',
         'code' => '2670', 'display' => 'Codeine', 'text' => 'Codeine'],
        ['keywords' => ['sulfur','belerang'],
         'category' => 'medication', 'system' => 'http://snomed.info/sct',
         'code' => '13578004', 'display' => 'Sulfur', 'text' => 'Sulfur'],
        // Makanan
        ['keywords' => ['susu sapi','susu','dairy','milk','laktosa'],
         'category' => 'food', 'system' => 'http://snomed.info/sct',
         'code' => '226760005', 'display' => 'Dairy food', 'text' => 'Susu Sapi'],
        ['keywords' => ['telur','egg'],
         'category' => 'food', 'system' => 'http://snomed.info/sct',
         'code' => '102263004', 'display' => 'Eggs', 'text' => 'Telur'],
        ['keywords' => ['kacang','nuts','peanut','groundnut'],
         'category' => 'food', 'system' => 'http://snomed.info/sct',
         'code' => '256349002', 'display' => 'Peanut', 'text' => 'Kacang'],
        ['keywords' => ['udang','seafood','kepiting','kerang','crab','shrimp'],
         'category' => 'food', 'system' => 'http://snomed.info/sct',
         'code' => '278840001', 'display' => 'Seafood', 'text' => 'Seafood'],
        ['keywords' => ['ikan','fish'],
         'category' => 'food', 'system' => 'http://snomed.info/sct',
         'code' => '735029006', 'display' => 'Fish', 'text' => 'Ikan'],
        ['keywords' => ['gandum','gluten','wheat'],
         'category' => 'food', 'system' => 'http://snomed.info/sct',
         'code' => '227493005', 'display' => 'Cashew nuts', 'text' => 'Gluten/Gandum'],
        // Lingkungan
        ['keywords' => ['debu','dust','tungau'],
         'category' => 'environment', 'system' => 'http://snomed.info/sct',
         'code' => '372003', 'display' => 'House dust mite', 'text' => 'Debu/Tungau'],
        ['keywords' => ['serbuk bunga','pollen','bunga'],
         'category' => 'environment', 'system' => 'http://snomed.info/sct',
         'code' => '256259004', 'display' => 'Pollen', 'text' => 'Serbuk Bunga'],
        ['keywords' => ['bulu','fur','cat','anjing','binatang','hewan'],
         'category' => 'environment', 'system' => 'http://snomed.info/sct',
         'code' => '232346004', 'display' => 'Animal dander', 'text' => 'Bulu Binatang'],
        ['keywords' => ['latex','lateks','karet'],
         'category' => 'environment', 'system' => 'http://snomed.info/sct',
         'code' => '1003755004', 'display' => 'Latex', 'text' => 'Latex'],
        ['keywords' => ['nikel','nickel','logam','metal'],
         'category' => 'environment', 'system' => 'http://snomed.info/sct',
         'code' => '48420002', 'display' => 'Nickel', 'text' => 'Nikel'],
    ];

    foreach ($mappings as $m) {
        foreach ($m['keywords'] as $kw) {
            if (strpos($q, $kw) !== false) {
                return $m;
            }
        }
    }

    // Default: medication tidak dikenal — pakai kode substance umum
    return [
        'category'       => 'medication',
        'system'         => 'http://snomed.info/sct',
        'code'           => '372687004',
        'display'        => 'Amide',
        'text'           => $namaAlergi,
        'unknown'        => true,
    ];
}

// ── Kirim 1 AllergyIntolerance ────────────────────────────────────
function kirimSatuAlergi(
    string $noRawat,
    string $tglPerawatan,
    string $jamRawat,
    string $status, // Ralan | Ranap
    PDO $db
): array {
    // Cek sudah terkirim
    $stmtCek = $db->prepare("
        SELECT id_allergy_intolerance FROM satu_sehat_allergy_intolerance
        WHERE no_rawat = ? AND tgl_perawatan = ? AND jam_rawat = ? AND status = ?
        LIMIT 1
    ");
    $stmtCek->execute([$noRawat, $tglPerawatan, $jamRawat, $status]);
    $existing = $stmtCek->fetchColumn();
    if (!empty($existing)) {
        return ['status' => 'ok', 'id_allergy' => $existing, 'note' => 'sudah ada'];
    }

    // Tabel pemeriksaan sesuai jenis
    $tblPemeriksaan = $status === 'Ranap' ? 'pemeriksaan_ranap' : 'pemeriksaan_ralan';

    // Ambil data
    $stmt = $db->prepare("
        SELECT
            rp.no_rawat, rp.no_rkm_medis,
            p.nm_pasien,
            pg.nama AS nm_dokter, pg.no_ktp AS ktp_dokter,
            pr.alergi, pr.tgl_perawatan, pr.jam_rawat,
            se.id_encounter,
            IFNULL(msp.ihs_number,'') AS ihs_pasien
        FROM $tblPemeriksaan pr
        JOIN reg_periksa rp             ON rp.no_rawat      = pr.no_rawat
        JOIN pasien p                   ON p.no_rkm_medis   = rp.no_rkm_medis
        JOIN pegawai pg                 ON pg.nik            = pr.nip
        JOIN satu_sehat_encounter se    ON se.no_rawat       = pr.no_rawat
        LEFT JOIN medifix_ss_pasien msp ON msp.no_rkm_medis = p.no_rkm_medis
        WHERE pr.no_rawat = ? AND pr.tgl_perawatan = ? AND pr.jam_rawat = ?
        LIMIT 1
    ");
    $stmt->execute([$noRawat, $tglPerawatan, $jamRawat]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        logALG("ERROR no_rawat=$noRawat tgl=$tglPerawatan jam=$jamRawat Data tidak ditemukan");
        return ['status' => 'error', 'message' => "Data tidak ditemukan: no_rawat=$noRawat tgl=$tglPerawatan jam=$jamRawat"];
    }
    if (empty($row['id_encounter'])) {
        logALG("ERROR no_rawat=$noRawat Encounter belum ada");
        return ['status' => 'error', 'message' => "Encounter belum ada untuk no_rawat=$noRawat — kirim Encounter dulu."];
    }
    if (empty($row['ihs_pasien'])) {
        logALG("ERROR no_rawat=$noRawat IHS pasien kosong nm={$row['nm_pasien']}");
        return ['status' => 'error', 'message' => "IHS pasien '{$row['nm_pasien']}' belum ada — sync IHS dulu."];
    }

    // Mapping alergi
    $namaAlergi = trim($row['alergi']);
    $mapping    = mappingAlergi($namaAlergi);

    try {
        $token     = getTokenALG();
        $ihsDokter = getIHSDokterALG($row['ktp_dokter'], $token);
        if (empty($ihsDokter)) {
            logALG("ERROR no_rawat=$noRawat IHS dokter tidak ditemukan ktp={$row['ktp_dokter']}");
            return ['status' => 'error', 'message' => "IHS dokter '{$row['nm_dokter']}' tidak ditemukan. KTP: {$row['ktp_dokter']}"];
        }

        // Format tanggal
        $jamStr = $row['jam_rawat'];
        if (strlen($jamStr) === 5) $jamStr .= ':00';
        $recordedDate = $row['tgl_perawatan'] . 'T' . $jamStr . '+07:00';

        $payload = [
            'resourceType' => 'AllergyIntolerance',
            'identifier'   => [[
                'system' => 'http://sys-ids.kemkes.go.id/allergy/' . SS_ORG_ID,
                'value'  => $noRawat . '-' . str_replace([':', '-', ' '], '', $tglPerawatan . $jamRawat),
            ]],
            'clinicalStatus' => [
                'coding' => [[
                    'system'  => 'http://terminology.hl7.org/CodeSystem/allergyintolerance-clinical',
                    'code'    => 'active',
                    'display' => 'Active',
                ]],
            ],
            'verificationStatus' => [
                'coding' => [[
                    'system'  => 'http://terminology.hl7.org/CodeSystem/allergyintolerance-verification',
                    'code'    => 'confirmed',
                    'display' => 'Confirmed',
                ]],
            ],
            'category' => [$mapping['category']],
            'code'     => [
                'coding' => [[
                    'system'  => $mapping['system'],
                    'code'    => $mapping['code'],
                    'display' => $mapping['display'],
                ]],
                'text' => $mapping['text'],
            ],
            'patient'  => [
                'reference' => 'Patient/' . $row['ihs_pasien'],
                'display'   => $row['nm_pasien'],
            ],
            'encounter' => [
                'reference' => 'Encounter/' . $row['id_encounter'],
                'display'   => 'Kunjungan ' . $row['nm_pasien'] . ' tgl ' . $row['tgl_perawatan'],
            ],
            'recordedDate' => $recordedDate,
            'recorder'     => [
                'reference' => 'Practitioner/' . $ihsDokter,
                'display'   => $row['nm_dokter'],
            ],
        ];

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        logALG("SEND no_rawat=$noRawat alergi=$namaAlergi category={$mapping['category']} code={$mapping['code']}");

        $ch = curl_init(SS_FHIR_URL . '/AllergyIntolerance');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonPayload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        logALG("RESPONSE HTTP=$httpCode BODY=$resp");
        if ($curlErr) throw new Exception("cURL: $curlErr");

        $respData = json_decode($resp, true);

        // Duplikat
        if ($httpCode === 400) {
            $issues = [];
            foreach (($respData['issue'] ?? []) as $iss) {
                $txt = ($iss['diagnostics'] ?? '') ?: ($iss['details']['text'] ?? '');
                if ($txt) $issues[] = $txt;
            }
            $errMsg = implode(' | ', $issues) ?: "HTTP 400: " . substr($resp, 0, 300);
            throw new Exception($errMsg);
        }

        if (in_array($httpCode, [200, 201], true)) {
            $idAllergy = $respData['id'] ?? '';
            if (empty($idAllergy)) throw new Exception("Response sukses tapi id kosong");

            $db->prepare("
                INSERT INTO satu_sehat_allergy_intolerance
                    (no_rawat, tgl_perawatan, jam_rawat, status, id_allergy_intolerance)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE id_allergy_intolerance = VALUES(id_allergy_intolerance)
            ")->execute([$noRawat, $tglPerawatan, $jamRawat, $status, $idAllergy]);

            logALG("OK no_rawat=$noRawat id_allergy=$idAllergy");
            return ['status' => 'ok', 'id_allergy' => $idAllergy];
        }

        $errMsg = ($respData['issue'][0]['diagnostics'] ?? '')
               ?: ($respData['issue'][0]['details']['text'] ?? '')
               ?: "HTTP $httpCode: " . substr($resp, 0, 200);
        throw new Exception($errMsg);

    } catch (Exception $e) {
        logALG("ERROR no_rawat=$noRawat msg=" . $e->getMessage());
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

// ── Routing ───────────────────────────────────────────────────────
$action       = $_POST['action']        ?? '';
$noRawat      = trim($_POST['no_rawat']       ?? '');
$tglPerawatan = trim($_POST['tgl_perawatan']  ?? '');
$jamRawat     = trim($_POST['jam_rawat']      ?? '');
$status       = trim($_POST['status']         ?? 'Ralan');

try {
    if ($action === 'kirim_alergi') {
        if (!$noRawat || !$tglPerawatan || !$jamRawat)
            jsonOutA(['status' => 'error', 'message' => 'Parameter tidak lengkap']);
        jsonOutA(kirimSatuAlergi($noRawat, $tglPerawatan, $jamRawat, $status, $pdo_simrs));
    }

    if ($action === 'kirim_semua_alergi') {
        $tglDari   = trim($_POST['tgl_dari']   ?? date('Y-m-d'));
        $tglSampai = trim($_POST['tgl_sampai'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglDari))   $tglDari   = date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglSampai)) $tglSampai = date('Y-m-d');

        // Kata yang dianggap "tidak ada alergi"
        $skipWords = ['tidak ada','tidak','tdk ada','tdk','tidak aad','tdak ada','tidak ad','tidak ada alergi','--','-','none','no','nihil'];

        // Ambil data ralan + ranap yang belum terkirim
        $stmtList = $pdo_simrs->prepare("
            SELECT pr.no_rawat, pr.tgl_perawatan, pr.jam_rawat, 'Ralan' AS status, pr.alergi
            FROM pemeriksaan_ralan pr
            JOIN reg_periksa rp ON rp.no_rawat = pr.no_rawat
            JOIN satu_sehat_encounter se ON se.no_rawat = pr.no_rawat
            LEFT JOIN satu_sehat_allergy_intolerance sa
                ON sa.no_rawat = pr.no_rawat AND sa.tgl_perawatan = pr.tgl_perawatan
                AND sa.jam_rawat = pr.jam_rawat AND sa.status = 'Ralan'
            WHERE rp.tgl_registrasi BETWEEN ? AND ?
              AND pr.alergi IS NOT NULL AND pr.alergi != ''
              AND (sa.id_allergy_intolerance IS NULL OR sa.id_allergy_intolerance = '')
            UNION ALL
            SELECT pr.no_rawat, pr.tgl_perawatan, pr.jam_rawat, 'Ranap' AS status, pr.alergi
            FROM pemeriksaan_ranap pr
            JOIN reg_periksa rp ON rp.no_rawat = pr.no_rawat
            JOIN satu_sehat_encounter se ON se.no_rawat = pr.no_rawat
            LEFT JOIN satu_sehat_allergy_intolerance sa
                ON sa.no_rawat = pr.no_rawat AND sa.tgl_perawatan = pr.tgl_perawatan
                AND sa.jam_rawat = pr.jam_rawat AND sa.status = 'Ranap'
            WHERE rp.tgl_registrasi BETWEEN ? AND ?
              AND pr.alergi IS NOT NULL AND pr.alergi != ''
              AND (sa.id_allergy_intolerance IS NULL OR sa.id_allergy_intolerance = '')
        ");
        $stmtList->execute([$tglDari, $tglSampai, $tglDari, $tglSampai]);
        $list = $stmtList->fetchAll(PDO::FETCH_ASSOC);

        $berhasil = 0; $gagal = 0; $dilewati = 0; $errors = [];
        foreach ($list as $item) {
            // Skip kalau isinya "tidak ada alergi"
            $alergiLower = strtolower(trim($item['alergi']));
            if (in_array($alergiLower, $skipWords) || strlen($alergiLower) <= 2) {
                $dilewati++;
                continue;
            }

            $res = kirimSatuAlergi(
                $item['no_rawat'], $item['tgl_perawatan'],
                $item['jam_rawat'], $item['status'], $pdo_simrs
            );
            if ($res['status'] === 'ok') $berhasil++;
            else {
                $gagal++;
                $errors[] = $item['no_rawat'] . ': ' . ($res['message'] ?? '?');
            }
            usleep(300000);
        }

        logALG("KIRIM_SEMUA tgl=$tglDari/$tglSampai total=" . count($list) . " ok=$berhasil gagal=$gagal dilewati=$dilewati");
        jsonOutA(['status' => 'ok', 'jumlah' => count($list), 'berhasil' => $berhasil, 'gagal' => $gagal, 'dilewati' => $dilewati, 'errors' => $errors]);
    }

    jsonOutA(['status' => 'error', 'message' => "Action '$action' tidak dikenal"]);

} catch (Exception $e) {
    logALG("EXCEPTION action=$action msg=" . $e->getMessage());
    jsonOutA(['status' => 'error', 'message' => $e->getMessage()]);
}