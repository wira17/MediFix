<?php
/**
 * api/kirim_vaksin.php
 * Kirim Immunization (Vaksin/Imunisasi) ke Satu Sehat
 *
 * Sumber data:
 *   - detail_pemberian_obat     → vaksin yang diberikan (no_batch, no_faktur, tgl, jam, jml)
 *   - satu_sehat_mapping_vaksin → kode KFA, route, dose
 *   - detailbeli                → expired date (kadaluarsa)
 *
 * Penyimpanan hasil:
 *   - satu_sehat_immunization   → tabel resmi Khanza (no_rawat, tgl_perawatan, jam, kode_brng, no_batch, no_faktur, id_immunization)
 *
 * Rules FHIR Satu Sehat:
 *   10103 : vaccineCode kode KFA mengandung "93"
 *   10105 : wajib reasonCode
 *   10306 : wajib lotNumber
 *   10307 : wajib expirationDate + performer code AP/OP
 *   10450 : wajib protocolApplied
 */

header('Content-Type: application/json');

if (!isset($pdo)) {
    require_once __DIR__ . '/../koneksi.php';
}
if (!isset($pdo_simrs)) {
    require_once __DIR__ . '/../koneksi2.php';
}

if (!defined('SS_CLIENT_ID')) {
    require_once __DIR__ . '/../config/env.php';
    if (!defined('SS_CLIENT_ID')) {
        if (isset($pdo))           loadSatuSehatConfig($pdo);
        elseif (isset($pdo_simrs)) loadSatuSehatConfig($pdo_simrs);
    }
}

if (!defined('SS_CLIENT_ID') || SS_CLIENT_ID === '') {
    echo json_encode(['status' => 'error', 'message' => 'Konfigurasi Satu Sehat belum diatur']);
    exit;
}

if (!isset($pdo_simrs)) $pdo_simrs = $pdo;

// ── Helpers ────────────────────────────────────────────────────────
function jsonOutV(array $d): void {
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

function logVAK(string $msg): void {
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents("$dir/vaksin_" . date('Y-m') . ".log",
        date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
}

// ── Token ──────────────────────────────────────────────────────────
function getTokenVAK(): string {
    $cache = SS_TOKEN_CACHE_FILE;
    if (file_exists($cache)) {
        $c = json_decode(file_get_contents($cache), true);
        if (!empty($c['access_token']) && ($c['expires_at'] ?? 0) > time() + 60)
            return $c['access_token'];
    }
    $ch = curl_init(SS_OAUTH_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => SS_CLIENT_ID,
            'client_secret' => SS_CLIENT_SECRET,
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) throw new Exception("Auth gagal HTTP $code: " . substr($resp, 0, 200));
    $data = json_decode($resp, true);
    if (empty($data['access_token'])) throw new Exception("Token kosong");
    file_put_contents($cache, json_encode([
        'access_token' => $data['access_token'],
        'expires_at'   => time() + (int)($data['expires_in'] ?? 3600),
    ]));
    return $data['access_token'];
}

// ── GET IHS Practitioner via NIK ───────────────────────────────────
function getIHSDokterVAK(string $nik, string $token): string {
    if (empty($nik)) return '';
    $url = SS_FHIR_URL . '/Practitioner?identifier=https://fhir.kemkes.go.id/id/nik|' . urlencode($nik);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    return $data['entry'][0]['resource']['id'] ?? '';
}

// ── Simpan ke satu_sehat_immunization ─────────────────────────────
function simpanImmunization(PDO $db, array $row, string $idImmunization): void {
    $db->prepare("
        INSERT INTO satu_sehat_immunization
            (no_rawat, tgl_perawatan, jam, kode_brng, no_batch, no_faktur, id_immunization)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            id_immunization = VALUES(id_immunization)
    ")->execute([
        $row['no_rawat'],
        $row['tgl_perawatan'],
        $row['jam'],
        $row['kode_brng'],
        $row['no_batch']  ?? '',
        $row['no_faktur'] ?? '',
        $idImmunization,
    ]);
}

// ── Kirim 1 Immunization ───────────────────────────────────────────
function kirimSatuVaksin(
    string $noRawat,
    string $kodeBrng,
    string $tglPemberian,
    string $jam,
    PDO $db
): array {
    // ── Ambil semua data dalam 1 query ────────────────────────────
    $stmt = $db->prepare("
        SELECT
            rp.no_rawat, rp.no_rkm_medis,
            p.nm_pasien,
            pg.nama AS nm_dokter, pg.no_ktp AS ktp_dokter,
            se.id_encounter,
            IFNULL(msp.ihs_number,'') AS ihs_pasien,
            -- Pemberian vaksin
            dpo.kode_brng, dpo.no_batch, dpo.no_faktur,
            dpo.tgl_perawatan, dpo.jam, dpo.jml,
            -- Mapping KFA
            mv.vaksin_code, mv.vaksin_system, mv.vaksin_display,
            mv.route_code, mv.route_system, mv.route_display,
            mv.dose_quantity_code, mv.dose_quantity_system, mv.dose_quantity_unit,
            -- Nama barang
            IFNULL(br.nama_brng, mv.vaksin_display) AS nama_brng,
            -- Expired date dari pembelian
            db2.kadaluarsa,
            -- Status kirim (tabel resmi Khanza)
            IFNULL(si.id_immunization,'') AS id_immunization_lama
        FROM reg_periksa rp
        JOIN pasien p                       ON p.no_rkm_medis    = rp.no_rkm_medis
        JOIN pegawai pg                     ON pg.nik             = rp.kd_dokter
        LEFT JOIN satu_sehat_encounter se   ON se.no_rawat        = rp.no_rawat
        JOIN detail_pemberian_obat dpo      ON dpo.no_rawat       = rp.no_rawat
                                           AND dpo.kode_brng      = ?
                                           AND dpo.tgl_perawatan  = ?
                                           AND dpo.jam            = ?
        JOIN satu_sehat_mapping_vaksin mv   ON mv.kode_brng       = dpo.kode_brng
        LEFT JOIN medifix_ss_pasien msp     ON msp.no_rkm_medis   = p.no_rkm_medis
        LEFT JOIN databarang br             ON br.kode_brng        = dpo.kode_brng
        LEFT JOIN detailbeli db2            ON db2.no_faktur       = dpo.no_faktur
                                           AND db2.kode_brng       = dpo.kode_brng
                                           AND db2.no_batch        = dpo.no_batch
        LEFT JOIN satu_sehat_immunization si ON si.no_rawat        = dpo.no_rawat
                                            AND si.kode_brng       = dpo.kode_brng
                                            AND si.tgl_perawatan   = dpo.tgl_perawatan
                                            AND si.jam             = dpo.jam
        WHERE rp.no_rawat = ?
        LIMIT 1
    ");
    $stmt->execute([$kodeBrng, $tglPemberian, $jam, $noRawat]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return ['status' => 'error', 'message' => "Data tidak ditemukan: no_rawat=$noRawat kode_brng=$kodeBrng tgl=$tglPemberian jam=$jam"];
    }
    if (empty($row['id_encounter'])) {
        return ['status' => 'error', 'message' => "Encounter belum ada untuk no_rawat=$noRawat — kirim Encounter terlebih dahulu."];
    }
    if (empty($row['ihs_pasien'])) {
        return ['status' => 'error', 'message' => "IHS Number pasien '{$row['nm_pasien']}' belum ada — klik Sync IHS dulu."];
    }
    if (empty($row['vaksin_code'])) {
        return ['status' => 'error', 'message' => "Kode KFA vaksin untuk kode_brng=$kodeBrng belum ada di mapping."];
    }

    try {
        $token = getTokenVAK();

        // IHS Dokter
        $ihsDokter = getIHSDokterVAK($row['ktp_dokter'], $token);
        if (empty($ihsDokter)) {
            return ['status' => 'error', 'message' => "IHS Practitioner dokter '{$row['nm_dokter']}' tidak ditemukan. KTP: {$row['ktp_dokter']}"];
        }

        // Waktu pemberian
        $jamStr = $row['jam'];
        if (strlen($jamStr) === 5) $jamStr .= ':00';
        $occurrenceDateTime = $row['tgl_perawatan'] . 'T' . $jamStr . '+07:00';

        // lotNumber (Rule 10306)
        $lotNumber = !empty($row['no_batch'])
            ? $row['no_batch']
            : 'LOT' . date('Ymd', strtotime($row['tgl_perawatan']));

        // expirationDate (Rule 10307)
        $expDate = !empty($row['kadaluarsa'])
            ? $row['kadaluarsa']
            : date('Y-m-d', strtotime('+2 years'));

        // Perbaiki system URL (sys-id → sys-ids)
        $vaksinSystem = str_replace(
            'http://sys-id.kemkes.go.id/kfa',
            'http://sys-ids.kemkes.go.id/kfa',
            $row['vaksin_system'] ?: 'http://sys-ids.kemkes.go.id/kfa'
        );

        // ── Payload FHIR Immunization R4 ──────────────────────────
        $payload = [
            'resourceType' => 'Immunization',
            'status'       => 'completed',

            // Rule 10103: kode KFA mengandung "93"
            'vaccineCode' => [
                'coding' => [[
                    'system'  => $vaksinSystem,
                    'code'    => $row['vaksin_code'],
                    'display' => $row['vaksin_display'],
                ]],
                'text' => $row['nama_brng'] ?: $row['vaksin_display'],
            ],

            'patient' => [
                'reference' => 'Patient/' . $row['ihs_pasien'],
                'display'   => $row['nm_pasien'],
            ],

            'encounter' => [
                'reference' => 'Encounter/' . $row['id_encounter'],
            ],

            'occurrenceDateTime' => $occurrenceDateTime,
            'recorded'           => $occurrenceDateTime,
            'primarySource'      => true,

            // Rule 10306
            'lotNumber' => $lotNumber,

            // Rule 10307
            'expirationDate' => $expDate,

            // Rule 10307: performer code AP
            'performer' => [[
                'function' => [
                    'coding' => [[
                        'system'  => 'http://terminology.hl7.org/CodeSystem/v2-0443',
                        'code'    => 'AP',
                        'display' => 'Administering Provider',
                    ]],
                ],
                'actor' => [
                    'reference' => 'Practitioner/' . $ihsDokter,
                    'display'   => $row['nm_dokter'],
                ],
            ]],

            // Route pemberian dari mapping
            'route' => !empty($row['route_code']) ? [
                'coding' => [[
                    'system'  => $row['route_system'] ?: 'http://www.whocc.no/atc',
                    'code'    => $row['route_code'],
                    'display' => $row['route_display'],
                ]],
            ] : null,

            // Dosis dari mapping
            'doseQuantity' => !empty($row['dose_quantity_unit']) ? [
                'value'  => (float)($row['jml'] ?? 1),
                'unit'   => $row['dose_quantity_unit'],
                'system' => $row['dose_quantity_system'] ?: 'http://unitsofmeasure.org',
                'code'   => $row['dose_quantity_code'] ?: $row['dose_quantity_unit'],
            ] : null,

            // Rule 10105: wajib reasonCode
            'reasonCode' => [[
                'coding' => [[
                    'system'  => 'http://snomed.info/sct',
                    'code'    => '830152006',
                    'display' => 'Vaccination given (situation)',
                ]],
            ]],

            // Rule 10450: wajib protocolApplied
            'protocolApplied' => [[
                'targetDisease' => [[
                    'coding' => [[
                        'system'  => 'http://snomed.info/sct',
                        'code'    => '40733004',
                        'display' => 'Infectious disease',
                    ]],
                ]],
                'doseNumberPositiveInt' => 1,
            ]],

            'note' => [[
                'text' => 'Vaksin: ' . $row['vaksin_display']
                        . ' — No. Rawat: ' . $noRawat
                        . ' — Batch: ' . $lotNumber,
            ]],
        ];

        // Hapus key dengan value null
        $payload = array_filter($payload, fn($v) => $v !== null);

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        logVAK("SEND no_rawat=$noRawat kode_brng=$kodeBrng kfa={$row['vaksin_code']} lot=$lotNumber exp=$expDate");

        // ── POST ke Satu Sehat ────────────────────────────────────
        $ch = curl_init(SS_FHIR_URL . '/Immunization');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonPayload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        logVAK("RESPONSE HTTP=$httpCode BODY=$resp");
        if ($curlErr) throw new Exception("cURL error: $curlErr");

        $respData = json_decode($resp, true);

        // ── Handle 400 — termasuk duplikat ────────────────────────
        if ($httpCode === 400) {
            $issues       = [];
            $fullIssueText = '';
            foreach (($respData['issue'] ?? []) as $iss) {
                $txt = ($iss['diagnostics'] ?? '') ?: ($iss['details']['text'] ?? '');
                if ($txt) { $issues[] = $txt; $fullIssueText .= $txt . ' '; }
            }
            $errMsg = implode(' | ', $issues) ?: "HTTP 400: " . substr($resp, 0, 300);

            // Duplikat → GET existing ID
            if (stripos($fullIssueText, 'duplicate') !== false || stripos($fullIssueText, '20002') !== false) {
                $getUrl = SS_FHIR_URL . '/Immunization?encounter=Encounter/' . urlencode($row['id_encounter']);
                $chGet  = curl_init($getUrl);
                curl_setopt_array($chGet, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
                    CURLOPT_TIMEOUT        => 15,
                    CURLOPT_SSL_VERIFYPEER => false,
                ]);
                $getResp = json_decode(curl_exec($chGet), true);
                curl_close($chGet);

                // Cari entry yang cocok dengan kode vaksin ini
                $idImmunization = '';
                foreach (($getResp['entry'] ?? []) as $entry) {
                    $entryCode = $entry['resource']['vaccineCode']['coding'][0]['code'] ?? '';
                    if ($entryCode === $row['vaksin_code']) {
                        $idImmunization = $entry['resource']['id'] ?? '';
                        break;
                    }
                }
                // Fallback: ambil entry pertama
                if (empty($idImmunization)) {
                    $idImmunization = $getResp['entry'][0]['resource']['id'] ?? '';
                }

                if (!empty($idImmunization)) {
                    simpanImmunization($db, $row, $idImmunization);
                    logVAK("OK (duplikat) no_rawat=$noRawat kode_brng=$kodeBrng id_immunization=$idImmunization");
                    return ['status' => 'ok', 'id_imunisasi' => $idImmunization];
                }
            }

            throw new Exception($errMsg);
        }

        // ── Sukses ────────────────────────────────────────────────
        if (in_array($httpCode, [200, 201], true)) {
            $idImmunization = $respData['id'] ?? '';
            if (empty($idImmunization)) {
                throw new Exception("Response sukses tapi id kosong: " . substr($resp, 0, 200));
            }

            simpanImmunization($db, $row, $idImmunization);
            logVAK("OK no_rawat=$noRawat kode_brng=$kodeBrng id_immunization=$idImmunization");
            return ['status' => 'ok', 'id_imunisasi' => $idImmunization];
        }

        // ── Error lain ────────────────────────────────────────────
        $errMsg = ($respData['issue'][0]['diagnostics'] ?? '')
               ?: ($respData['issue'][0]['details']['text'] ?? '')
               ?: "HTTP $httpCode: " . substr($resp, 0, 300);
        throw new Exception($errMsg);

    } catch (Exception $e) {
        logVAK("ERROR no_rawat=$noRawat kode_brng=$kodeBrng msg=" . $e->getMessage());
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

// ── Routing ────────────────────────────────────────────────────────
$action       = $_POST['action']       ?? '';
$noRawat      = trim($_POST['no_rawat']      ?? '');
$kodeBrng     = trim($_POST['kode_brng']     ?? '');
$tglPemberian = trim($_POST['tgl_pemberian'] ?? '');
$jam          = trim($_POST['jam']           ?? '');

try {
    if ($action === 'kirim_vaksin') {
        if (!$noRawat)      jsonOutV(['status' => 'error', 'message' => 'no_rawat tidak boleh kosong']);
        if (!$kodeBrng)     jsonOutV(['status' => 'error', 'message' => 'kode_brng tidak boleh kosong']);
        if (!$tglPemberian) jsonOutV(['status' => 'error', 'message' => 'tgl_pemberian tidak boleh kosong']);
        if (!$jam)          jsonOutV(['status' => 'error', 'message' => 'jam tidak boleh kosong']);
        jsonOutV(kirimSatuVaksin($noRawat, $kodeBrng, $tglPemberian, $jam, $pdo_simrs));
    }

    if ($action === 'kirim_semua_vaksin') {
        $tglDari   = trim($_POST['tgl_dari']   ?? date('Y-m-d'));
        $tglSampai = trim($_POST['tgl_sampai'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglDari))   $tglDari   = date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglSampai)) $tglSampai = date('Y-m-d');

        // Ambil semua vaksin yang ada mapping tapi belum terkirim
        $stmtList = $pdo_simrs->prepare("
            SELECT
                dpo.no_rawat, dpo.kode_brng,
                dpo.tgl_perawatan, dpo.jam
            FROM detail_pemberian_obat dpo
            JOIN satu_sehat_mapping_vaksin mv   ON mv.kode_brng  = dpo.kode_brng
            JOIN satu_sehat_encounter se         ON se.no_rawat  = dpo.no_rawat
            LEFT JOIN satu_sehat_immunization si ON si.no_rawat      = dpo.no_rawat
                                                AND si.kode_brng     = dpo.kode_brng
                                                AND si.tgl_perawatan = dpo.tgl_perawatan
                                                AND si.jam           = dpo.jam
            WHERE dpo.tgl_perawatan BETWEEN ? AND ?
            AND (si.id_immunization IS NULL OR si.id_immunization = '')
        ");
        $stmtList->execute([$tglDari, $tglSampai]);
        $list = $stmtList->fetchAll(PDO::FETCH_ASSOC);

        $berhasil = 0; $gagal = 0; $errors = [];
        foreach ($list as $item) {
            $res = kirimSatuVaksin(
                $item['no_rawat'],
                $item['kode_brng'],
                $item['tgl_perawatan'],
                $item['jam'],
                $pdo_simrs
            );
            if ($res['status'] === 'ok') $berhasil++;
            else {
                $gagal++;
                $errors[] = $item['no_rawat'] . '/' . $item['kode_brng'] . ': ' . ($res['message'] ?? '?');
            }
            usleep(300000);
        }
        logVAK("KIRIM_SEMUA tgl=$tglDari/$tglSampai total=" . count($list) . " ok=$berhasil gagal=$gagal");
        jsonOutV([
            'status'   => 'ok',
            'jumlah'   => count($list),
            'berhasil' => $berhasil,
            'gagal'    => $gagal,
            'errors'   => $errors,
        ]);
    }

    jsonOutV(['status' => 'error', 'message' => "Action '$action' tidak dikenal"]);

} catch (Exception $e) {
    logVAK("EXCEPTION action=$action msg=" . $e->getMessage());
    jsonOutV(['status' => 'error', 'message' => $e->getMessage()]);
}