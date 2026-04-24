<?php
error_reporting(E_ALL);
ini_set("log_errors", 1);
ini_set("error_log", __DIR__ . "/../logs/php_upload_error.log");

header('Content-Type: application/json');

function jsonOut(array $d): void { echo json_encode($d); exit; }

function logUpload(string $msg): void {
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents("$dir/dicom_upload_" . date('Y-m') . ".log",
        date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
}

logUpload("REQUEST noorder=" . ($_POST['noorder'] ?? '-')
    . " file=" . ($_FILES['gambar']['name'] ?? 'TIDAK ADA')
    . " file_error=" . ($_FILES['gambar']['error'] ?? '-'));

// ── Validasi input ────────────────────────────────────────────────
$noorder    = trim($_POST['noorder']         ?? '');
$kdJenisPrw = trim($_POST['kd_jenis_prw']    ?? '');
$noRawat    = trim($_POST['no_rawat']        ?? '');
$ihsNumber  = trim($_POST['ihs_number']      ?? '');
$studyUID   = trim($_POST['study_uid']       ?? '');
$idIS       = trim($_POST['id_imagingstudy'] ?? '');

if (!$noorder || !isset($_FILES['gambar'])) {
    logUpload("ERROR parameter tidak lengkap");
    jsonOut(['status' => 'error', 'message' => 'Parameter tidak lengkap']);
}

$file = $_FILES['gambar'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    logUpload("ERROR upload error code=" . $file['error']);
    jsonOut(['status' => 'error', 'message' => 'Upload gagal: error code ' . $file['error']]);
}

// ── Validasi gambar pakai getimagesize — tidak butuh extension fileinfo ───
$allowedExt = ['jpg', 'jpeg', 'png', 'bmp', 'gif'];
$ext        = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExt)) {
    logUpload("ERROR ekstensi tidak didukung: $ext");
    jsonOut(['status' => 'error', 'message' => "Format tidak didukung: .$ext (gunakan JPG, PNG, atau BMP)"]);
}

$imgInfo = @getimagesize($file['tmp_name']);
if ($imgInfo === false) {
    logUpload("ERROR bukan gambar valid: " . $file['name']);
    jsonOut(['status' => 'error', 'message' => 'File bukan gambar valid atau corrupt']);
}
$mime = image_type_to_mime_type($imgInfo[2]);
logUpload("MIME OK: $mime ext=$ext size=" . $file['size']);

if ($file['size'] > 20 * 1024 * 1024) {
    jsonOut(['status' => 'error', 'message' => 'File terlalu besar (max 20MB)']);
}

// ── Load konfigurasi ──────────────────────────────────────────────
require_once __DIR__ . '/../config/env.php';
if (isset($pdo)) loadSatuSehatConfig($pdo);
elseif (isset($pdo_simrs)) loadSatuSehatConfig($pdo_simrs);

if (!defined('SS_CLIENT_ID') || SS_CLIENT_ID === '') {
    logUpload("ERROR konfigurasi SS kosong");
    jsonOut(['status' => 'error', 'message' => 'Konfigurasi Satu Sehat belum diatur di tabel setting_satusehat']);
}
logUpload("CONFIG OK org=" . SS_ORG_ID);

// ── Ambil data pasien dari DB ─────────────────────────────────────
$row = [];
try {
    $stmt = $pdo_simrs->prepare("
        SELECT m.study_uid_dicom, msp.ihs_number, p.nm_pasien,
               p.tgl_lahir, p.jk, pr.tgl_permintaan
        FROM medifix_ss_radiologi m
        JOIN permintaan_radiologi pr ON m.noorder     = pr.noorder
        JOIN reg_periksa reg         ON pr.no_rawat   = reg.no_rawat
        JOIN pasien p                ON reg.no_rkm_medis = p.no_rkm_medis
        LEFT JOIN medifix_ss_pasien msp ON p.no_rkm_medis = msp.no_rkm_medis
        WHERE m.noorder = ? LIMIT 1
    ");
    $stmt->execute([$noorder]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if ($row) {
        if (empty($studyUID))  $studyUID  = $row['study_uid_dicom'] ?? '';
        if (empty($ihsNumber)) $ihsNumber = $row['ihs_number'] ?? '';
    }
    logUpload("DB row=" . ($row ? 'ADA nm=' . ($row['nm_pasien'] ?? '') . ' ihs=' . $ihsNumber : 'NULL'));
} catch (Exception $e) {
    logUpload("DB ERROR: " . $e->getMessage());
}

// Fallback jika data pasien tidak ketemu
if (empty($row)) {
    $row = ['nm_pasien' => 'UNKNOWN', 'tgl_lahir' => '1900-01-01', 'jk' => 'O'];
}

// Generate UID jika belum ada
if (empty($studyUID)) {
    $studyUID = '2.25.' . time() . mt_rand(100000, 999999);
}
$seriesUID   = $studyUID . '.1';
$instanceUID = $studyUID . '.1.' . time() . mt_rand(10, 99);

// ── Konversi gambar ke DICOM ──────────────────────────────────────
if (!extension_loaded('gd')) {
    logUpload("ERROR GD extension tidak tersedia");
    jsonOut(['status' => 'error', 'message' => 'PHP GD extension tidak tersedia di server']);
}

$img = imagecreatefromstring(file_get_contents($file['tmp_name']));
if (!$img) {
    logUpload("ERROR imagecreatefromstring gagal untuk: " . $file['name']);
    jsonOut(['status' => 'error', 'message' => 'Gagal membaca gambar — file mungkin corrupt']);
}

$origW = imagesx($img);
$origH = imagesy($img);
logUpload("IMAGE size={$origW}x{$origH}");

// Resize ke max 2048px
$maxPx = 2048;
if ($origW > $maxPx || $origH > $maxPx) {
    $ratio   = min($maxPx / $origW, $maxPx / $origH);
    $newW    = (int)($origW * $ratio);
    $newH    = (int)($origH * $ratio);
    $resized = imagecreatetruecolor($newW, $newH);
    imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
    imagedestroy($img);
    $img   = $resized;
    $origW = $newW;
    $origH = $newH;
}

// Konversi ke grayscale 8-bit
imagefilter($img, IMG_FILTER_GRAYSCALE);

// Ambil pixel data raw bytes
$pixelData = '';
for ($y = 0; $y < $origH; $y++) {
    for ($x = 0; $x < $origW; $x++) {
        $color      = imagecolorat($img, $x, $y);
        $pixelData .= chr(($color >> 16) & 0xFF);
    }
}
imagedestroy($img);

// DICOM requirement: pixel data harus panjang genap
if (strlen($pixelData) % 2 !== 0) $pixelData .= "\x00";

// ── Build DICOM file (Little Endian Explicit) ─────────────────────
function dicomStr(string $val, int $maxLen): string {
    $val = substr($val, 0, $maxLen);
    if (strlen($val) % 2 !== 0) $val .= ' ';
    return $val;
}
function dicomTag(int $group, int $elem, string $vr, string $value): string {
    $tag    = pack('vv', $group, $elem);
    $len    = strlen($value);
    $longVR = ['OB', 'OW', 'SQ', 'UC', 'UR', 'UT', 'UN'];
    if (in_array($vr, $longVR)) {
        return $tag . $vr . "\x00\x00" . pack('V', $len) . $value;
    }
    return $tag . $vr . pack('v', $len) . $value;
}
function dicomUI(int $g, int $e, string $v): string { return dicomTag($g, $e, 'UI', dicomStr($v, 64)); }
function dicomUS(int $g, int $e, int $v): string    { return dicomTag($g, $e, 'US', pack('v', $v)); }
function dicomLO(int $g, int $e, string $v): string { return dicomTag($g, $e, 'LO', dicomStr($v, 64)); }
function dicomDA(int $g, int $e, string $v): string { return dicomTag($g, $e, 'DA', dicomStr(str_replace('-', '', $v), 8)); }
function dicomTM(int $g, int $e, string $v): string { return dicomTag($g, $e, 'TM', dicomStr(str_replace(':', '', $v), 14)); }
function dicomPN(int $g, int $e, string $v): string { return dicomTag($g, $e, 'PN', dicomStr($v, 64)); }
function dicomCS(int $g, int $e, string $v): string { return dicomTag($g, $e, 'CS', dicomStr($v, 16)); }

$sopClassUID       = '1.2.840.10008.5.1.4.1.1.7'; // Secondary Capture Image Storage
$transferSyntaxUID = '1.2.840.10008.1.2.1';        // Explicit VR Little Endian
$tgl               = date('Y-m-d');
$jam               = date('H:i:s');
$nmPasien          = strtoupper(str_replace(' ', '^', trim($row['nm_pasien'] ?? 'UNKNOWN')));
$tglLahir          = $row['tgl_lahir'] ?? '1900-01-01';
$jk                = strtoupper(substr($row['jk'] ?? 'O', 0, 1)) === 'L' ? 'M' : 'F';

$metaElements =
    dicomUI(0x0002, 0x0002, $sopClassUID) .
    dicomUI(0x0002, 0x0003, $instanceUID) .
    dicomUI(0x0002, 0x0010, $transferSyntaxUID) .
    dicomUI(0x0002, 0x0012, '1.2.276.0.7230010.3.0.3.6.4') .
    dicomLO(0x0002, 0x0013, 'MEDIFIX_DICOM_1.0');

$metaLen  = strlen($metaElements);
$fileMeta = dicomTag(0x0002, 0x0000, 'UL', pack('V', $metaLen)) . $metaElements;

$dataset =
    dicomLO(0x0010, 0x0020, $ihsNumber) .
    dicomPN(0x0010, 0x0010, $nmPasien) .
    dicomDA(0x0010, 0x0030, $tglLahir) .
    dicomCS(0x0010, 0x0040, $jk) .
    dicomUI(0x0020, 0x000D, $studyUID) .
    dicomDA(0x0008, 0x0020, $tgl) .
    dicomTM(0x0008, 0x0030, $jam) .
    dicomLO(0x0008, 0x0050, $noorder) .
    dicomLO(0x0020, 0x0010, $noorder) .
    dicomUI(0x0020, 0x000E, $seriesUID) .
    dicomDA(0x0008, 0x0021, $tgl) .
    dicomTM(0x0008, 0x0031, $jam) .
    dicomCS(0x0008, 0x0060, 'DX') .
    dicomUI(0x0008, 0x0016, $sopClassUID) .
    dicomUI(0x0008, 0x0018, $instanceUID) .
    dicomCS(0x0008, 0x0008, 'ORIGINAL\PRIMARY') .
    dicomUS(0x0028, 0x0010, $origH) .
    dicomUS(0x0028, 0x0011, $origW) .
    dicomUS(0x0028, 0x0002, 1) .
    dicomCS(0x0028, 0x0004, 'MONOCHROME2') .
    dicomUS(0x0028, 0x0100, 8) .
    dicomUS(0x0028, 0x0101, 8) .
    dicomUS(0x0028, 0x0102, 7) .
    dicomUS(0x0028, 0x0103, 0) .
    dicomTag(0x7FE0, 0x0010, 'OW', $pixelData);

$dicomFile = str_repeat("\x00", 128) . 'DICM' . $fileMeta . $dataset;
logUpload("DICOM built size=" . strlen($dicomFile) . " bytes");

// ── Ambil token Satu Sehat ────────────────────────────────────────
function getSatuSehatToken(): string {
    $cache = SS_TOKEN_CACHE_FILE;
    if (file_exists($cache)) {
        $c = json_decode(file_get_contents($cache), true);
        if (!empty($c['access_token']) && ($c['expires_at'] ?? 0) > time() + 60) {
            return $c['access_token'];
        }
    }
    $ch = curl_init(SS_OAUTH_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => SS_CLIENT_ID,
            'client_secret' => SS_CLIENT_SECRET,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT    => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) throw new Exception("Auth gagal HTTP $code: $resp");
    $data = json_decode($resp, true);
    if (empty($data['access_token'])) throw new Exception("Token kosong dari response");
    file_put_contents($cache, json_encode([
        'access_token' => $data['access_token'],
        'expires_at'   => time() + (int)($data['expires_in'] ?? 3600),
    ]));
    return $data['access_token'];
}

// ── Kirim ke SATUSEHAT DICOMweb STOW-RS ──────────────────────────
try {
    $token = getSatuSehatToken();
    logUpload("TOKEN OK");

    $boundary = 'DICOMwebBoundary' . uniqid();
    $dicomUrl = SS_BASE_URL . '/dicom/v1/dicomWeb/studies';

    $body  = "--$boundary\r\n";
    $body .= "Content-Type: application/dicom\r\n";
    $body .= "Content-Length: " . strlen($dicomFile) . "\r\n\r\n";
    $body .= $dicomFile . "\r\n";
    $body .= "--$boundary--\r\n";

    $ch = curl_init($dicomUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            "Content-Type: multipart/related; type=\"application/dicom\"; boundary=\"$boundary\"",
            "Authorization: Bearer $token",
            "Accept: application/dicom+json",
        ],
        CURLOPT_TIMEOUT => 60,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    logUpload("CURL done httpCode=$httpCode curlErr=" . ($curlErr ?: 'none') . " respLen=" . strlen($resp));

    if ($curlErr) throw new Exception("cURL error: $curlErr");

    if ($httpCode === 200 || $httpCode === 202) {

        $pdo_simrs->prepare("
            INSERT INTO medifix_dicom_uploads
                (noorder, kd_jenis_prw, no_rawat, filename_ori, instance_uid, status, uploaded_by)
            VALUES (?, ?, ?, ?, ?, 'terkirim', ?)
        ")->execute([
            $noorder, $kdJenisPrw, $noRawat,
            $file['name'], $instanceUID,
            $_SESSION['nama'] ?? 'system',
        ]);

        $pdo_simrs->prepare("
            UPDATE medifix_ss_radiologi
            SET study_uid_dicom = ?
            WHERE noorder = ? AND (study_uid_dicom IS NULL OR study_uid_dicom = '')
        ")->execute([$studyUID, $noorder]);

        logUpload("OK noorder=$noorder instance=$instanceUID study=$studyUID file=" . $file['name']);
        jsonOut(['status' => 'ok', 'instance_uid' => $instanceUID, 'study_uid' => $studyUID]);

    } else {
        $rd  = json_decode($resp, true);
        $err = $rd['issue'][0]['diagnostics']
            ?? $rd['issue'][0]['details']['text']
            ?? "HTTP $httpCode: " . substr($resp, 0, 300);
        throw new Exception($err);
    }

} catch (Exception $e) {
    try {
        $pdo_simrs->prepare("
            INSERT INTO medifix_dicom_uploads
                (noorder, kd_jenis_prw, no_rawat, filename_ori, status, error_msg, uploaded_by)
            VALUES (?, ?, ?, ?, 'error', ?, ?)
        ")->execute([
            $noorder, $kdJenisPrw, $noRawat,
            $file['name'],
            $e->getMessage(),
            $_SESSION['nama'] ?? 'system',
        ]);
    } catch (Exception $dbErr) {
        logUpload("DB INSERT ERROR: " . $dbErr->getMessage());
    }
    logUpload("ERROR noorder=$noorder msg=" . $e->getMessage());
    jsonOut(['status' => 'error', 'message' => $e->getMessage()]);
}