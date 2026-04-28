<?php
/**
 * api/kirim_encounter.php
 * Kirim Encounter ke Satu Sehat
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
            IFNULL(msp.ihs_number,'') AS ihs_pasien
        FROM reg_periksa rp
        JOIN pasien p               ON rp.no_rkm_medis = p.no_rkm_medis
        JOIN pegawai pg             ON pg.nik           = rp.kd_dokter
        JOIN poliklinik pl          ON pl.kd_poli       = rp.kd_poli
        LEFT JOIN medifix_ss_pasien msp ON p.no_rkm_medis = msp.no_rkm_medis
        WHERE rp.no_rawat = ?
        LIMIT 1
    ");
    $stmtReg->execute([$noRawat]);
    $row = $stmtReg->fetch(PDO::FETCH_ASSOC);

   if (!$row) {
        logENC("ERROR no_rawat=$noRawat msg=Data reg_periksa tidak ditemukan");
        return ['status' => 'error', 'message' => "Data reg_periksa tidak ditemukan untuk no_rawat=$noRawat"];
    }

    // ── Ambil diagnosis ───────────────────────────────────────────
    $kdPenyakit = '';
    $nmPenyakit = '';
    try {
        $stmtDx = $db->prepare("
            SELECT dp.kd_penyakit, py.nm_penyakit
            FROM diagnosa_pasien dp
            LEFT JOIN penyakit py ON py.kd_penyakit = dp.kd_penyakit
            WHERE dp.no_rawat = ?
            ORDER BY dp.status ASC
            LIMIT 1
        ");
        $stmtDx->execute([$noRawat]);
        $dx = $stmtDx->fetch(PDO::FETCH_ASSOC);
        if ($dx) {
            $kdPenyakit = $dx['kd_penyakit'] ?? '';
            $nmPenyakit = $dx['nm_penyakit'] ?? $kdPenyakit;
        }
    } catch (Exception $e) {
        logENC("WARN diagnosa_pasien: " . $e->getMessage());
    }

    $isRanap = strtolower($row['status_lanjut'] ?? '') === 'ranap';
    $kdPoli  = $row['kd_poli'];

    // ── Ambil tgl pulang ──────────────────────────────────────────
    $tglPulang = '';
    if (!$isRanap) {
        try {
            $s = $db->prepare("SELECT tanggal, jam FROM nota_jalan WHERE no_rawat = ? ORDER BY tanggal DESC, jam DESC LIMIT 1");
            $s->execute([$noRawat]);
            $nj = $s->fetch(PDO::FETCH_ASSOC);
            if ($nj) $tglPulang = toISO($nj['tanggal'], $nj['jam']);
        } catch (Exception $e) { logENC("WARN nota_jalan: " . $e->getMessage()); }

        if (empty($tglPulang)) {
            try {
                $s = $db->prepare("SELECT tgl_perawatan, jam_rawat FROM pemeriksaan_ralan WHERE no_rawat = ? ORDER BY tgl_perawatan DESC LIMIT 1");
                $s->execute([$noRawat]);
                $pr = $s->fetch(PDO::FETCH_ASSOC);
                if ($pr) $tglPulang = toISO($pr['tgl_perawatan'], $pr['jam_rawat']);
            } catch (Exception $e) { logENC("WARN pemeriksaan_ralan: " . $e->getMessage()); }
        }
    } else {
        try {
            $s = $db->prepare("SELECT tanggal, jam FROM nota_inap WHERE no_rawat = ? ORDER BY tanggal DESC, jam DESC LIMIT 1");
            $s->execute([$noRawat]);
            $ni = $s->fetch(PDO::FETCH_ASSOC);
            if ($ni) $tglPulang = toISO($ni['tanggal'], $ni['jam']);
        } catch (Exception $e) { logENC("WARN nota_inap: " . $e->getMessage()); }

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

    if (empty($tglPulang)) {
        $tglPulang = toISO($row['tgl_registrasi'], $row['jam_reg']);
    }

    // ── Mapping lokasi ────────────────────────────────────────────
    $idLokasi = '';
    $nmLokasi = $row['nm_poli'];
    $nmPoliLc = strtolower($nmLokasi);

    $lokasiQueries = [];
    $lokasiQueries[] = [
        "SELECT id_lokasi_satusehat FROM satu_sehat_mapping_lokasi_ralan WHERE kd_poli = ? LIMIT 1",
        [$kdPoli]
    ];
    if (preg_match('/lab|laborat/i', $nmPoliLc)) {
        $lokasiQueries[] = ["SELECT ml.id_lokasi_satusehat FROM satu_sehat_mapping_lokasi_ruanglab ml JOIN satu_sehat_mapping_departemen md ON ml.id_organisasi_satusehat = md.id_organisasi_satusehat JOIN departemen dep ON md.dep_id = dep.dep_id LIMIT 1", []];
        $lokasiQueries[] = ["SELECT ml.id_lokasi_satusehat FROM satu_sehat_mapping_lokasi_ruanglabpa ml JOIN satu_sehat_mapping_departemen md ON ml.id_organisasi_satusehat = md.id_organisasi_satusehat LIMIT 1", []];
        $lokasiQueries[] = ["SELECT ml.id_lokasi_satusehat FROM satu_sehat_mapping_lokasi_ruanglabmb ml JOIN satu_sehat_mapping_departemen md ON ml.id_organisasi_satusehat = md.id_organisasi_satusehat LIMIT 1", []];
    }
    if (preg_match('/radiologi|rontgen|xray|x-ray|usg|ct.scan|mri/i', $nmPoliLc)) {
        $lokasiQueries[] = ["SELECT ml.id_lokasi_satusehat FROM satu_sehat_mapping_lokasi_ruangrad ml JOIN satu_sehat_mapping_departemen md ON ml.id_organisasi_satusehat = md.id_organisasi_satusehat LIMIT 1", []];
    }
    if (preg_match('/ok|operat|bedah|kamar.op/i', $nmPoliLc)) {
        $lokasiQueries[] = ["SELECT ml.id_lokasi_satusehat FROM satu_sehat_mapping_lokasi_ruangok ml JOIN satu_sehat_mapping_departemen md ON ml.id_organisasi_satusehat = md.id_organisasi_satusehat LIMIT 1", []];
    }
   $lokasiQueries[] = [
        "SELECT lr.id_lokasi_satusehat
         FROM kamar_inap ki
         JOIN satu_sehat_mapping_lokasi_ranap lr ON lr.kd_kamar = ki.kd_kamar
         WHERE ki.no_rawat = ?
         LIMIT 1",
        [$noRawat]
    ];
    $lokasiQueries[] = [
        "SELECT id_lokasi_satusehat FROM satu_sehat_mapping_lokasi_ranap LIMIT 1",
        []
    ];

    foreach ($lokasiQueries as [$sql, $sqlParams]) {
        try {
            $sLok = $db->prepare($sql);
            $sLok->execute($sqlParams);
            $found = $sLok->fetchColumn();
            if (!empty($found)) { $idLokasi = $found; break; }
        } catch (Exception $e) {
            logENC("WARN lokasi query skip: " . $e->getMessage());
        }
    }

    if (empty($idLokasi)) {
        return [
            'status'  => 'error',
            'message' => "Mapping lokasi untuk poliklinik '{$row['nm_poli']}' (kd_poli=$kdPoli) belum diisi. "
                       . "Silakan isi di Khanza: Setting → Satu Sehat → Mapping Lokasi.",
        ];
    }

 if (empty($row['ihs_pasien'])) {
        // Auto-sync IHS sebelum menyerah
        logENC("WARN no_rawat=$noRawat IHS kosong untuk {$row['nm_pasien']}, mencoba auto-sync...");
        $nikPasien = '';
        try {
            $stmtNik = $db->prepare("SELECT no_ktp FROM pasien WHERE no_rkm_medis = ? LIMIT 1");
            $stmtNik->execute([$row['no_rkm_medis']]);
            $nikPasien = trim($stmtNik->fetchColumn() ?? '');
        } catch (Exception $e) {}

        if (!empty($nikPasien)) {
            try {
                $tokenTmp = getToken();
                $ihsTmp   = getIHSDokter($nikPasien, $tokenTmp); // reuse fungsi GET ke /Patient
                // getIHSDokter hanya support Practitioner, pakai curl langsung untuk Patient
                $urlPat = SS_FHIR_URL . '/Patient?identifier=https://fhir.kemkes.go.id/id/nik|' . urlencode($nikPasien);
                $chPat  = curl_init($urlPat);
                curl_setopt_array($chPat, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $tokenTmp, 'Accept: application/json'],
                    CURLOPT_TIMEOUT        => 15,
                    CURLOPT_SSL_VERIFYPEER => false,
                ]);
                $patResp = json_decode(curl_exec($chPat), true);
                curl_close($chPat);
                $ihsAutoSync = $patResp['entry'][0]['resource']['id'] ?? '';

                if (!empty($ihsAutoSync)) {
                    // Simpan ke medifix_ss_pasien
                    $db->prepare("
                        INSERT INTO medifix_ss_pasien (no_rkm_medis, ihs_number, tgl_sync, status_sync, error_msg)
                        VALUES (?, ?, NOW(), 'ditemukan', '')
                        ON DUPLICATE KEY UPDATE ihs_number=VALUES(ihs_number), tgl_sync=NOW(), status_sync='ditemukan', error_msg=''
                    ")->execute([$row['no_rkm_medis'], $ihsAutoSync]);
                    $row['ihs_pasien'] = $ihsAutoSync;
                    logENC("OK auto-sync IHS no_rkm={$row['no_rkm_medis']} nik=$nikPasien ihs=$ihsAutoSync nm={$row['nm_pasien']}");
                } else {
                    logENC("ERROR no_rawat=$noRawat IHS tidak ditemukan di Satu Sehat nik=$nikPasien nm={$row['nm_pasien']}");
                    return ['status' => 'error', 'message' => "IHS pasien '{$row['nm_pasien']}' tidak ditemukan di Satu Sehat (NIK: $nikPasien)"];
                }
            } catch (Exception $e) {
                logENC("ERROR no_rawat=$noRawat auto-sync IHS gagal: " . $e->getMessage());
                return ['status' => 'error', 'message' => "Gagal auto-sync IHS pasien '{$row['nm_pasien']}': " . $e->getMessage()];
            }
        } else {
            logENC("ERROR no_rawat=$noRawat NIK pasien kosong nm={$row['nm_pasien']}");
            return ['status' => 'error', 'message' => "NIK pasien '{$row['nm_pasien']}' kosong di SIMRS — tidak bisa sync IHS."];
        }
    }

    try {
        $token = getToken();

        $ihsDokter = getIHSDokter($row['ktp_dokter'], $token);
       if (empty($ihsDokter)) {
            logENC("ERROR no_rawat=$noRawat msg=IHS dokter tidak ditemukan nm={$row['nm_dokter']} ktp={$row['ktp_dokter']}");
            return [
                'status'  => 'error',
                'message' => "IHS Practitioner dokter '{$row['nm_dokter']}' tidak ditemukan. KTP: {$row['ktp_dokter']}",
            ];
        }

        $tglMulai    = toISO($row['tgl_registrasi'], $row['jam_reg']);
        $tglMulaiTs  = strtotime($tglMulai);
        $tglPulangTs = strtotime($tglPulang);
        if ($tglPulangTs < $tglMulaiTs) {
            $tglPulang = $tglMulai;
            logENC("WARN period.end < period.start, fallback end=start untuk no_rawat=$noRawat");
        }

        $period = ['start' => $tglMulai, 'end' => $tglPulang];

        $diagnosisBlock = [];
      if (!empty($kdPenyakit)) {
            $diagnosisBlock = [[
                'condition' => [
                    'display' => $kdPenyakit . ' - ' . $nmPenyakit,
                ],
                'use' => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/diagnosis-role', 'code' => 'DD', 'display' => 'Discharge diagnosis']]],
                'rank' => 1,
            ]];
        } else {
            $diagnosisBlock = [[
                'condition' => [
                    'display' => 'Belum ada diagnosa',
                ],
                'use' => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/diagnosis-role', 'code' => 'AD', 'display' => 'Admission diagnosis']]],
                'rank' => 1,
            ]];
        }

        $payload = [
            'resourceType' => 'Encounter',
            'identifier'   => [['system' => 'http://sys-ids.kemkes.go.id/encounter/' . SS_ORG_ID, 'value' => $noRawat]],
            'status'       => 'finished',
            'class'        => [
                'system'  => 'http://terminology.hl7.org/CodeSystem/v3-ActCode',
                'code'    => $isRanap ? 'IMP' : 'AMB',
                'display' => $isRanap ? 'inpatient encounter' : 'ambulatory',
            ],
            'subject'      => ['reference' => 'Patient/' . $row['ihs_pasien'], 'display' => $row['nm_pasien']],
            'participant'  => [[
                'type'       => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/v3-ParticipationType', 'code' => 'ATND', 'display' => 'attender']]]],
                'individual' => ['reference' => 'Practitioner/' . $ihsDokter, 'display' => $row['nm_dokter']],
            ]],
            'period'        => $period,
            'statusHistory' => [['status' => 'finished', 'period' => $period]],
            'location'      => [['location' => ['reference' => 'Location/' . $idLokasi, 'display' => $nmLokasi]]],
            'serviceProvider' => ['reference' => 'Organization/' . SS_ORG_ID],
            'diagnosis'     => $diagnosisBlock,
        ];

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        logENC("SEND no_rawat=$noRawat jenis=" . ($isRanap ? 'Ranap' : 'Ralan'));

        $ch = curl_init(SS_FHIR_URL . '/Encounter');
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

        logENC("RESPONSE HTTP=$httpCode BODY=$resp");
        if ($curlErr) throw new Exception("cURL error: $curlErr");

        $respData = json_decode($resp, true);

        if ($httpCode === 400) {
            $issue    = $respData['issue'][0] ?? [];
            $diagText = ($issue['diagnostics'] ?? '') . ' ' . ($issue['details']['text'] ?? '');
            $allIssues = [];
            foreach (($respData['issue'] ?? []) as $iss) {
                $txt = ($iss['diagnostics'] ?? '') ?: ($iss['details']['text'] ?? '') ?: ($iss['details']['coding'][0]['display'] ?? '');
                if ($txt) $allIssues[] = $txt;
            }
            $fullErrMsg = implode(' | ', $allIssues) ?: "HTTP 400: " . substr($resp, 0, 400);

            if (stripos($diagText, 'duplicat') !== false || stripos($diagText, 'already exist') !== false) {
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
                    $db->prepare("INSERT INTO satu_sehat_encounter (no_rawat, id_encounter) VALUES (?,?) ON DUPLICATE KEY UPDATE id_encounter = VALUES(id_encounter)")->execute([$noRawat, $idEnc]);
                    logENC("OK (duplikat) no_rawat=$noRawat id_encounter=$idEnc");
                    return ['status' => 'ok', 'id_encounter' => $idEnc];
                }
            }
            throw new Exception($fullErrMsg);
        }

        if (in_array($httpCode, [200, 201], true)) {
            $idEnc = $respData['id'] ?? '';
            if (empty($idEnc)) throw new Exception("Response sukses tapi id kosong: " . substr($resp, 0, 200));
            $db->prepare("INSERT INTO satu_sehat_encounter (no_rawat, id_encounter) VALUES (?, ?) ON DUPLICATE KEY UPDATE id_encounter = VALUES(id_encounter)")->execute([$noRawat, $idEnc]);
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

    if ($action === 'kirim_semua_encounter') {
        $tglDari   = trim($_POST['tgl_dari']   ?? date('Y-m-d'));
        $tglSampai = trim($_POST['tgl_sampai'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglDari))   $tglDari   = date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglSampai)) $tglSampai = date('Y-m-d');

        // FIX: ambil dari nota_jalan + nota_inap, bukan dari satu_sehat_encounter
        // sehingga pasien yang belum pernah dikirim sama sekali ikut terambil
        $stmtList = $pdo_simrs->prepare("
            SELECT DISTINCT rp.no_rawat
            FROM reg_periksa rp
            JOIN (
                SELECT no_rawat FROM nota_jalan WHERE tanggal BETWEEN ? AND ?
                UNION
                SELECT no_rawat FROM nota_inap  WHERE tanggal BETWEEN ? AND ?
            ) nj ON nj.no_rawat = rp.no_rawat
            LEFT JOIN satu_sehat_encounter se ON se.no_rawat = rp.no_rawat
            WHERE (se.id_encounter IS NULL OR se.id_encounter = '')
        ");
        $stmtList->execute([$tglDari, $tglSampai, $tglDari, $tglSampai]);
        $list = $stmtList->fetchAll(PDO::FETCH_COLUMN);

        $berhasil = 0; $gagal = 0; $errors = [];
        foreach ($list as $nr) {
            $res = kirimSatuEncounter($nr, $pdo_simrs);
            if ($res['status'] === 'ok') $berhasil++;
            else { $gagal++; $errors[] = $nr . ': ' . ($res['message'] ?? '?'); }
            usleep(300000);
        }
        logENC("KIRIM_SEMUA tgl=$tglDari/$tglSampai total=" . count($list) . " ok=$berhasil gagal=$gagal");
        jsonOut(['status' => 'ok', 'jumlah' => count($list), 'berhasil' => $berhasil, 'gagal' => $gagal, 'errors' => $errors]);
    }

    jsonOut(['status' => 'error', 'message' => "Action '$action' tidak dikenal"]);
} catch (Exception $e) {
    logENC("EXCEPTION action=$action msg=" . $e->getMessage());
    jsonOut(['status' => 'error', 'message' => $e->getMessage()]);
}