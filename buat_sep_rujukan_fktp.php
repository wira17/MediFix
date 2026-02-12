<?php
/**
 * INSERT SEP - KHANZA METHOD
 * Mengikuti exact method dari SIMRS Khanza
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
include 'koneksi.php';
include 'koneksi2.php';
require_once __DIR__ . '/vendor/autoload.php';
use LZCompressor\LZString;

date_default_timezone_set('Asia/Jakarta');

// === FUNGSI DARI KHANZA ===
function stringDecrypt($key, $string) {
    $encrypt_method = 'AES-256-CBC';
    $key_hash = hex2bin(hash('sha256', $key));
    $iv = substr(hex2bin(hash('sha256', $key)), 0, 16);
    return openssl_decrypt(base64_decode($string), $encrypt_method, $key_hash, OPENSSL_RAW_DATA, $iv);
}

function stringEncrypt($key, $data) {
    $encrypt_method = 'AES-256-CBC';
    $key_hash = hex2bin(hash('sha256', $key));
    $iv = substr(hex2bin(hash('sha256', $key)), 0, 16);
    $encrypted = openssl_encrypt($data, $encrypt_method, $key_hash, OPENSSL_RAW_DATA, $iv);
    return base64_encode($encrypted);
}

function decompress($string) {
    return LZCompressor\LZString::decompressFromEncodedURIComponent($string);
}

function compress($string) {
    return LZCompressor\LZString::compressToEncodedURIComponent($string);
}

// === AMBIL SETTING ===
$stmt = $pdo->query("SELECT * FROM setting_vclaim LIMIT 1");
$setting = $stmt->fetch(PDO::FETCH_ASSOC);

$cons_id = trim($setting['cons_id']);
$secret_key = trim($setting['secret_key']);
$user_key = trim($setting['user_key']);
$base_url = trim($setting['base_url']);
$kd_ppk = trim($setting['kd_ppk']);

// === DATA RUJUKAN (dari session atau POST) ===
$data_rujukan = $_SESSION['data_rujukan'] ?? [
    'noKartu' => '0002041485603',
    'noRujukan' => '1004U2010126P000001',
    'namaPeserta' => 'SUMIYATI',
    'diagAwal' => 'Z03.8',
    'poliTujuan' => 'INT',
    'hakKelas' => '2',
    'noTelp' => '082177846209',
    'tglRujukan' => '2026-01-20',
    'kdProvPerujuk' => '1004U201',
    'nmProvPerujuk' => 'KLINIK FARFA'
];

$result = null;
$error = '';

if (isset($_POST['test_khanza_method'])) {
    
    // === PERSIS SEPERTI KHANZA ===
    
    // 1. Generate timestamp (UTC)
    date_default_timezone_set('UTC');
    $utc = strval(time());
    date_default_timezone_set('Asia/Jakarta');
    
    // 2. Generate signature
    $data_to_sign = $cons_id . "&" . $utc;
    $signature = base64_encode(hash_hmac('sha256', $data_to_sign, $secret_key, true));
    
    // 3. Build request JSON (PLAIN, tidak di-encrypt dulu)
    $requestJson = [
        "request" => [
            "t_sep" => [
                "noKartu" => $data_rujukan['noKartu'],
                "tglSep" => date('Y-m-d'),
                "ppkPelayanan" => $kd_ppk,
                "jnsPelayanan" => "2", // 2 = Rawat Jalan
                "klsRawat" => [
                    "klsRawatHak" => $data_rujukan['hakKelas'],
                    "klsRawatNaik" => "",
                    "pembiayaan" => "",
                    "penanggungJawab" => ""
                ],
                "noMR" => $data_rujukan['noKartu'], // Atau no MR real
                "rujukan" => [
                    "asalRujukan" => "1", // 1 = Faskes 1
                    "tglRujukan" => $data_rujukan['tglRujukan'],
                    "noRujukan" => $data_rujukan['noRujukan'],
                    "ppkRujukan" => $data_rujukan['kdProvPerujuk']
                ],
                "catatan" => "Rujukan FKTP",
                "diagAwal" => $data_rujukan['diagAwal'],
                "poli" => [
                    "tujuan" => $data_rujukan['poliTujuan'],
                    "eksekutif" => "0"
                ],
                "cob" => [
                    "cob" => "0"
                ],
                "katarak" => [
                    "katarak" => "0"
                ],
                "jaminan" => [
                    "lakaLantas" => "0",
                    "noLP" => "",
                    "penjamin" => [
                        "tglKejadian" => "",
                        "keterangan" => "",
                        "suplesi" => [
                            "suplesi" => "0",
                            "noSepSuplesi" => "",
                            "lokasiLaka" => [
                                "kdPropinsi" => "",
                                "kdKabupaten" => "",
                                "kdKecamatan" => ""
                            ]
                        ]
                    ]
                ],
                "tujuanKunj" => "0",
                "flagProcedure" => "",
                "kdPenunjang" => "",
                "assesmentPel" => "",
                "skdp" => [
                    "noSurat" => "",
                    "kodeDPJP" => "261387"
                ],
                "dpjpLayan" => "261387",
                "noTelp" => $data_rujukan['noTelp'],
                "user" => "Anjungan Mandiri"
            ]
        ]
    ];
    
    // 4. Convert to JSON string
    $jsonString = json_encode($requestJson);
    
    // 5. ENKRIPSI (seperti yang Khanza lakukan di api.getRest().exchange())
    $key = $cons_id . $secret_key . $utc;
    $compressed = compress($jsonString);
    $encrypted = stringEncrypt($key, $compressed);
    
    // 6. Prepare request body (x-www-form-urlencoded)
    $requestBody = "request=" . urlencode($encrypted);
    
    // 7. Prepare headers
    $headers = [
        "X-Cons-ID: $cons_id",
        "X-Timestamp: $utc",
        "X-Signature: $signature",
        "user_key: $user_key",
        "Content-Type: application/x-www-form-urlencoded"
    ];
    
    // 8. Send request
    $url = $base_url . "/SEP/2.0/insert";
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $requestBody,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // 9. Parse response
    $isHtml = (stripos($response, '<html') !== false);
    
    if (!$isHtml && !empty($response)) {
        $json = json_decode($response, true);
        
        // 10. Decrypt response (jika ada)
        if (isset($json['response']) && is_string($json['response']) && !empty($json['response'])) {
            try {
                $decrypted = stringDecrypt($key, $json['response']);
                $decompressed = decompress($decrypted);
                $json['response'] = json_decode($decompressed, true);
            } catch (Exception $e) {
                $error = "Decrypt error: " . $e->getMessage();
            }
        }
        
        $result = [
            'http_code' => $httpCode,
            'response' => $json,
            'is_html' => false
        ];
    } else {
        $result = [
            'http_code' => $httpCode,
            'response' => null,
            'is_html' => true,
            'raw' => $response
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>INSERT SEP - Khanza Method</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
    padding: 20px;
}
.card {
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
    margin-bottom: 20px;
}
pre {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 5px;
    max-height: 400px;
    overflow-y: auto;
}
</style>
</head>
<body>

<div class="container" style="max-width: 1000px;">
    <h2 class="text-white text-center mb-4">🏥 INSERT SEP - Khanza Method</h2>
    
    <div class="card">
        <div class="card-header bg-primary text-white">
            <h5>📋 Informasi</h5>
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <h6>🔍 Method ini mengikuti EXACT cara yang digunakan SIMRS Khanza</h6>
                <p class="mb-0">Berdasarkan analisa kode Java Khanza, method ini sudah disesuaikan 100%</p>
            </div>
            
            <table class="table table-sm">
                <tr>
                    <th>Base URL:</th>
                    <td><code><?= htmlspecialchars($base_url) ?></code></td>
                </tr>
                <tr>
                    <th>Endpoint:</th>
                    <td><code>/SEP/2.0/insert</code></td>
                </tr>
                <tr>
                    <th>Data Rujukan:</th>
                    <td>
                        No Kartu: <?= $data_rujukan['noKartu'] ?><br>
                        No Rujukan: <?= $data_rujukan['noRujukan'] ?>
                    </td>
                </tr>
            </table>
        </div>
    </div>
    
    <?php if ($result): ?>
    <div class="card">
        <div class="card-header bg-<?= $result['http_code'] == 200 ? 'success' : 'danger' ?> text-white">
            <h5>📥 Result</h5>
        </div>
        <div class="card-body">
            <div class="alert alert-<?= $result['http_code'] == 200 ? 'success' : 'danger' ?>">
                <strong>HTTP Code:</strong> <?= $result['http_code'] ?>
            </div>
            
            <?php if ($result['is_html']): ?>
            <div class="alert alert-danger">
                <h5>❌ Response HTML Error</h5>
                <p>Server mengembalikan HTML, bukan JSON</p>
            </div>
            <pre><?= htmlspecialchars(substr($result['raw'], 0, 500)) ?></pre>
            
            <?php else: ?>
            
            <?php if (isset($result['response']['metaData'])): ?>
            <div class="alert alert-<?= $result['response']['metaData']['code'] == 200 ? 'success' : 'warning' ?>">
                <strong>Meta Code:</strong> <?= $result['response']['metaData']['code'] ?><br>
                <strong>Message:</strong> <?= htmlspecialchars($result['response']['metaData']['message']) ?>
            </div>
            <?php endif; ?>
            
            <?php if (isset($result['response']['response']['sep']['noSep'])): ?>
            <div class="alert alert-success">
                <h4>✅ SEP BERHASIL DIBUAT!</h4>
                <p class="mb-0"><strong>No. SEP:</strong> <code><?= $result['response']['response']['sep']['noSep'] ?></code></p>
            </div>
            <?php endif; ?>
            
            <details>
                <summary>Full Response</summary>
                <pre><?= json_encode($result['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?></pre>
            </details>
            
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
    <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>
    
    <div class="card">
        <div class="card-header bg-success text-white">
            <h5>🚀 Test INSERT SEP</h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <button type="submit" name="test_khanza_method" class="btn btn-success btn-lg w-100">
                    <i class="bi bi-play-circle"></i> Test dengan Khanza Method
                </button>
            </form>
        </div>
    </div>
    
    <div class="text-center mt-3">
        <a href="cek_rujukan_fktp.php" class="btn btn-secondary">← Kembali</a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>