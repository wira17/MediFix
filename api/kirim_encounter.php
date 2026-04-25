<?php
/**
 * api/kirim_encounter.php
 * Kirim Encounter ke Satu Sehat
 *
 * Fix berdasarkan debug:
 * - Tidak ada tabel satu_sehat_mapping_dokter → GET IHS dokter langsung dari Satu Sehat via KTP
 * - Mapping lokasi PL024 belum ada → tampilkan pesan jelas ke user
 * - Tgl pulang dari nota_jalan.tanggal + nota_jalan.jam
 */

header('Content-Type: application/json');

if (!defined('SS_CLIENT_ID')) {
    require_once __DIR__ . '/../config/env.php';
    if (isset($pdo)) loadSatuSehatConfig($pdo);
    elseif (isset($pdo_simrs)) loadSatuSehatConfig($pdo_simrs);
}

if (!defined('SS_CLIENT_ID') || SS_CLIENT_ID === '') {
    echo json_encode(['status' => 'error', 'message' => 'Konfigurasi Satu Sehat belum diatur']);
    exit;
}

if (!isset($pdo_simrs) && isset($pdo)) $pdo_simrs = $pdo;

function jsonOut(array $d): void { echo json_encode($d); exit; }

function logENC(string $msg): void {
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents("$dir/encounter_" . date('Y-m') . ".log",
        date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
}

// ── Token ─────────────────────────────────────────────────────────
function getToken(): string {
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
        CURLOPT_TIMEOUT        => 20,
    ]);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200) throw new Exception("Auth gagal HTTP $code: " . substr($resp, 0, 200));
    $data = json_decode($resp, true);
    if (empty($data['access_token'])) throw new Exception("Token kosong");
    file_put_contents($cache, json_encode([
        'access_token' => $data['access_token'],
        'expires_at'   => time() + (int)($data['expires_in'] ?? 3600),
    ]));
    return $data['access_token'];
}

// ── GET IHS Practitioner via NIK/KTP dokter ───────────────────────
function getIHSDokter(string $nik, string $token): string {
    if (empty($nik)) return '';
    $url = SS_FHIR_URL . '/Practitioner?identifier=https://fhir.kemkes.go.id/id/nik|' . urlencode($nik);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch); curl_close($ch);
    $data = json_decode($resp, true);
    return $data['entry'][0]['resource']['id'] ?? '';
}

// ── Format ISO datetime ───────────────────────────────────────────
function toISO(string $tgl, string $jam = '00:00:00'): string {
    $tgl = trim($tgl); $jam = trim($jam);
    if (strpos($tgl, 'T') !== false)
        return preg_match('/[+\-]\d{2}:\d{2}$|Z$/', $tgl) ? $tgl : $tgl . '+07:00';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) $tgl = date('Y-m-d');
    if (!preg_match('/^\d{2}:\d{2}/', $jam))         $jam = '00:00:00';
    if (strlen($jam) === 5) $jam .= ':00';
    return $tgl . 'T' . $jam . '+07:00';
}

// ── Kirim 1 Encounter ─────────────────────────────────────────────
function kirimSatuEncounter(string $noRawat, PDO $db): array
{
    // Cek sudah ada
    $stmtCek = $db->prepare("SELECT id_encounter FROM satu_sehat_encounter WHERE no_rawat = ? LIMIT 1");
    $stmtCek->execute([$noRawat]);
    $existing = $stmtCek->fetchColumn();
    if (!empty($existing)) {
        return ['status' => 'ok', 'id_encounter' => $existing, 'note' => 'sudah ada'];
    }

    // ── Ambil data dasar reg_periksa ──────────────────────────────
    $stmtReg = $db->prepare("
        SELECT
            rp.no_rawat, rp.no_rkm_medis, rp.kd_dokter, rp.kd_poli,
            rp.tgl_registrasi, rp.jam_reg,
            rp.stts, rp.status_lanjut,
            p.nm_pasien, p.no_ktp AS ktp_pasien,
            pg.nama AS nm_dokter, pg.no_ktp AS ktp_dokter,
            pl.nm_poli,
            IFNULL(msp.ihs_number,'') AS ihs_pasien,
            dp.kd_penyakit,
            py.nm_penyakit
        FROM reg_periksa rp
        JOIN pasien p               ON rp.no_rkm_medis = p.no_rkm_medis
        JOIN pegawai pg             ON pg.nik           = rp.kd_dokter
        JOIN poliklinik pl          ON pl.kd_poli       = rp.kd_poli
        LEFT JOIN medifix_ss_pasien msp ON p.no_rkm_medis = msp.no_rkm_medis
        LEFT JOIN diagnosa_pasien dp    ON dp.no_rawat  = rp.no_rawat
        LEFT JOIN penyakit py           ON py.kd_penyakit = dp.kd_penyakit
        WHERE rp.no_rawat = ?
        ORDER BY dp.status ASC
        LIMIT 1
    ");
    $stmtReg->execute([$noRawat]);
    $row = $stmtReg->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return ['status' => 'error', 'message' => "Data reg_periksa tidak ditemukan untuk no_rawat=$noRawat"];
    }

    $isRanap  = strtolower($row['status_lanjut'] ?? '') === 'ranap';
    $kdPoli   = $row['kd_poli'];

    // ── Ambil tgl pulang dari nota_jalan / nota_inap ──────────────
    $tglPulang = '';
    if (!$isRanap) {
        // Ralan → nota_jalan
        try {
            $s = $db->prepare("SELECT tanggal, jam FROM nota_jalan WHERE no_rawat = ? ORDER BY tanggal DESC, jam DESC LIMIT 1");
            $s->execute([$noRawat]);
            $nj = $s->fetch(PDO::FETCH_ASSOC);
            if ($nj) $tglPulang = toISO($nj['tanggal'], $nj['jam']);
        } catch (Exception $e) { logENC("WARN nota_jalan: " . $e->getMessage()); }

        // Fallback ke pemeriksaan_ralan
        if (empty($tglPulang)) {
            try {
                $s = $db->prepare("SELECT tgl_perawatan, jam_rawat FROM pemeriksaan_ralan WHERE no_rawat = ? ORDER BY tgl_perawatan DESC LIMIT 1");
                $s->execute([$noRawat]);
                $pr = $s->fetch(PDO::FETCH_ASSOC);
                if ($pr) $tglPulang = toISO($pr['tgl_perawatan'], $pr['jam_rawat']);
            } catch (Exception $e) { logENC("WARN pemeriksaan_ralan: " . $e->getMessage()); }
        }
    } else {
        // Ranap → nota_inap
        try {
            $s = $db->prepare("SELECT tanggal, jam FROM nota_inap WHERE no_rawat = ? ORDER BY tanggal DESC, jam DESC LIMIT 1");
            $s->execute([$noRawat]);
            $ni = $s->fetch(PDO::FETCH_ASSOC);
            if ($ni) $tglPulang = toISO($ni['tanggal'], $ni['jam']);
        } catch (Exception $e) { logENC("WARN nota_inap: " . $e->getMessage()); }

        // Fallback ke kamar_inap
        if (empty($tglPulang)) {
            try {
                $s = $db->prepare("SELECT tgl_keluar, jam_keluar FROM kamar_inap WHERE no_rawat = ? ORDER BY tgl_keluar DESC LIMIT 1");
                $s->execute([$noRawat]);
                $ki = $s->fetch(PDO::FETCH_ASSOC);
                if ($ki && $ki['tgl_keluar'] && $ki['tgl_keluar'] !== '0000-00-00')
                    $tglPulang = toISO($ki['tgl_keluar'], $ki['jam_keluar']);
            } catch (Exception $e) { logENC("WARN kamar_inap: " . $e->getMessage()); }
        }
    }

    // Fallback tgl pulang = tgl registrasi
    if (empty($tglPulang)) {
        $tglPulang = toISO($row['tgl_registrasi'], $row['jam_reg']);
    }

    // ── Mapping lokasi poliklinik ─────────────────────────────────
    $idLokasi = '';
    $nmLokasi = $row['nm_poli'];

    // Coba ralan dulu
    try {
        $s = $db->prepare("SELECT id_lokasi_satusehat FROM satu_sehat_mapping_lokasi_ralan WHERE kd_poli = ? LIMIT 1");
        $s->execute([$kdPoli]);
        $idLokasi = $s->fetchColumn() ?: '';
    } catch (Exception $e) { $idLokasi = ''; }

    // Fallback ke ranap
    if (empty($idLokasi)) {
        try {
            $s = $db->prepare("SELECT id_lokasi_satusehat FROM satu_sehat_mapping_lokasi_ranap WHERE kd_poli = ? LIMIT 1");
            $s->execute([$kdPoli]);
            $idLokasi = $s->fetchColumn() ?: '';
        } catch (Exception $e) { $idLokasi = ''; }
    }

    if (empty($idLokasi)) {
        return [
            'status'  => 'error',
            'message' => "Mapping lokasi untuk poliklinik '{$row['nm_poli']}' (kd_poli=$kdPoli) belum diisi di tabel satu_sehat_mapping_lokasi_ralan. Silakan isi dulu di menu Setting → Mapping Lokasi Satu Sehat.",
        ];
    }

    // ── Validasi IHS Pasien ───────────────────────────────────────
    if (empty($row['ihs_pasien'])) {
        return ['status' => 'error', 'message' => "IHS Number pasien '{$row['nm_pasien']}' belum ada — klik tombol Sync IHS dulu."];
    }

    try {
        $token = getToken();

        // ── IHS Dokter — GET langsung via KTP (tidak perlu tabel cache) ──
        $ihsDokter = getIHSDokter($row['ktp_dokter'], $token);

        if (empty($ihsDokter)) {
            return [
                'status'  => 'error',
                'message' => "IHS Practitioner dokter '{$row['nm_dokter']}' tidak ditemukan di Satu Sehat. Pastikan KTP dokter ({$row['ktp_dokter']}) sudah terdaftar di platform Satu Sehat.",
            ];
        }

        // ── Build payload Encounter ───────────────────────────────
        $tglMulai = toISO($row['tgl_registrasi'], $row['jam_reg']);

        // ── Periode — pastikan end tidak lebih kecil dari start ───
        $tglMulaiTs  = strtotime($tglMulai);
        $tglPulangTs = strtotime($tglPulang);
        if ($tglPulangTs < $tglMulaiTs) {
            // Kalau tgl pulang lebih kecil dari tgl mulai (data aneh), pakai tgl mulai
            $tglPulang = $tglMulai;
            logENC("WARN period.end < period.start, fallback end=start untuk no_rawat=$noRawat");
        }

        $period = ['start' => $tglMulai, 'end' => $tglPulang];

        // ── Diagnosis — WAJIB di Satu Sehat (RuleNumber: 10457) ───
        $diagnosisBlock = [];
        if (!empty($row['kd_penyakit'])) {
            $diagnosisBlock = [[
                'condition' => [
                    'reference' => 'Condition/' . $row['kd_penyakit'],
                    'display'   => $row['nm_penyakit'] ?? $row['kd_penyakit'],
                ],
                'use' => [
                    'coding' => [[
                        'system'  => 'http://terminology.hl7.org/CodeSystem/diagnosis-role',
                        'code'    => 'DD',
                        'display' => 'Discharge diagnosis',
                    ]],
                ],
                'rank' => 1,
            ]];
        }

        $payload = [
            'resourceType'  => 'Encounter',
            'identifier'    => [[
                'system' => 'http://sys-ids.kemkes.go.id/encounter/' . SS_ORG_ID,
                'value'  => $noRawat,
            ]],
            'status'        => 'finished',
            'class'         => [
                'system'  => 'http://terminology.hl7.org/CodeSystem/v3-ActCode',
                'code'    => $isRanap ? 'IMP' : 'AMB',
                'display' => $isRanap ? 'inpatient encounter' : 'ambulatory',
            ],
            'subject'       => [
                'reference' => 'Patient/' . $row['ihs_pasien'],
                'display'   => $row['nm_pasien'],
            ],
            'participant'   => [[
                'type'       => [[
                    'coding' => [[
                        'system'  => 'http://terminology.hl7.org/CodeSystem/v3-ParticipationType',
                        'code'    => 'ATND',
                        'display' => 'attender',
                    ]],
                ]],
                'individual' => [
                    'reference' => 'Practitioner/' . $ihsDokter,
                    'display'   => $row['nm_dokter'],
                ],
            ]],
            'period'        => $period,
            'statusHistory' => [[
                'status' => 'finished',
                'period' => $period,
            ]],
            'location'      => [[
                'location' => [
                    'reference' => 'Location/' . $idLokasi,
                    'display'   => $nmLokasi,
                ],
            ]],
            'serviceProvider' => [
                'reference' => 'Organization/' . SS_ORG_ID,
            ],
        ];

        // Tambah diagnosis hanya jika ada data
        if (!empty($diagnosisBlock)) {
            $payload['diagnosis'] = $diagnosisBlock;
        }

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        logENC("SEND no_rawat=$noRawat jenis=" . ($isRanap ? 'Ranap' : 'Ralan') . " PAYLOAD=$jsonPayload");

        $ch = curl_init(SS_FHIR_URL . '/Encounter');
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

        logENC("RESPONSE HTTP=$httpCode BODY=" . $resp);
        if ($curlErr) throw new Exception("cURL error: $curlErr");

        $respData = json_decode($resp, true);

        // ── Duplikat ──────────────────────────────────────────────
        if ($httpCode === 400) {
            $issue    = $respData['issue'][0] ?? [];
            $diagText = ($issue['diagnostics'] ?? '') . ' ' . ($issue['details']['text'] ?? '');
            // Kumpulkan semua issue untuk pesan error yang lebih informatif
            $allIssues = [];
            foreach (($respData['issue'] ?? []) as $iss) {
                $txt = ($iss['diagnostics'] ?? '') ?: ($iss['details']['text'] ?? '') ?: ($iss['details']['coding'][0]['display'] ?? '');
                if ($txt) $allIssues[] = $txt;
            }
            $fullErrMsg = implode(' | ', $allIssues) ?: "HTTP 400: " . substr($resp, 0, 400);

            if (stripos($diagText, 'duplicat') !== false || stripos($diagText, 'already exist') !== false) {
                // GET existing
                $getUrl = SS_FHIR_URL . '/Encounter?identifier=' . urlencode('http://sys-ids.kemkes.go.id/encounter/' . SS_ORG_ID . '|' . $noRawat);
                $chGet  = curl_init($getUrl);
                curl_setopt_array($chGet, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
                    CURLOPT_TIMEOUT        => 15, CURLOPT_SSL_VERIFYPEER => false,
                ]);
                $getResp = json_decode(curl_exec($chGet), true);
                curl_close($chGet);
                $idEnc = $getResp['entry'][0]['resource']['id'] ?? '';
                if (!empty($idEnc)) {
                    $db->prepare("
                        INSERT INTO satu_sehat_encounter (no_rawat, id_encounter)
                        VALUES (?,?)
                        ON DUPLICATE KEY UPDATE id_encounter = VALUES(id_encounter)
                    ")->execute([$noRawat, $idEnc]);
                    logENC("OK (duplikat) no_rawat=$noRawat id_encounter=$idEnc");
                    return ['status' => 'ok', 'id_encounter' => $idEnc];
                }
            }
            throw new Exception($fullErrMsg);
        }

        // ── Sukses ────────────────────────────────────────────────
        if (in_array($httpCode, [200, 201], true)) {
            $idEnc = $respData['id'] ?? '';
            if (empty($idEnc)) throw new Exception("Response sukses tapi id kosong: " . substr($resp, 0, 200));

            $db->prepare("
                INSERT INTO satu_sehat_encounter (no_rawat, id_encounter)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE id_encounter = VALUES(id_encounter)
            ")->execute([$noRawat, $idEnc]);

            logENC("OK no_rawat=$noRawat id_encounter=$idEnc");
            return ['status' => 'ok', 'id_encounter' => $idEnc];
        }

        $errMsg = ($respData['issue'][0]['diagnostics'] ?? '')
               ?: ($respData['issue'][0]['details']['text'] ?? '')
               ?: "HTTP $httpCode: " . substr($resp, 0, 300);
        throw new Exception($errMsg);

    } catch (Exception $e) {
        logENC("ERROR no_rawat=$noRawat msg=" . $e->getMessage());
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

// ── Routing ───────────────────────────────────────────────────────
$action  = $_POST['action'] ?? '';
$noRawat = trim($_POST['no_rawat'] ?? '');

try {
    if ($action === 'kirim_encounter') {
        if (!$noRawat) jsonOut(['status' => 'error', 'message' => 'no_rawat tidak boleh kosong']);
        jsonOut(kirimSatuEncounter($noRawat, $pdo_simrs));
    }
    jsonOut(['status' => 'error', 'message' => "Action '$action' tidak dikenal"]);
} catch (Exception $e) {
    logENC("EXCEPTION action=$action msg=" . $e->getMessage());
    jsonOut(['status' => 'error', 'message' => $e->getMessage()]);
}