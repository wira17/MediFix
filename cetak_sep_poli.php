<?php
// DEBUG MODE - CRITICAL: Tampilkan semua error untuk debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();
include 'koneksi.php';  // koneksi database anjungan (tempat setting vclaim)
include 'koneksi2.php'; // koneksi database SIMRS
require_once __DIR__ . '/vendor/autoload.php';
use LZCompressor\LZString;

date_default_timezone_set('Asia/Jakarta');

// === FUNGSI ENKRIPSI & DEKRIPSI (SESUAI DOKUMENTASI BPJS) ===
function stringEncrypt($key, $data) {
    $method = 'AES-256-CBC';
    $key_hash = hash('sha256', $key, true);
    $iv = substr(hash('sha256', $key), 0, 16);
    $encrypted = openssl_encrypt($data, $method, $key_hash, OPENSSL_RAW_DATA, $iv);
    return base64_encode($encrypted);
}

function stringDecrypt($key, $string) {
    $encrypt_method = 'AES-256-CBC';
    // hash
    $key_hash = hex2bin(hash('sha256', $key));
    // iv - encrypt method AES-256-CBC expects 16 bytes
    $iv = substr(hex2bin(hash('sha256', $key)), 0, 16);
    $output = openssl_decrypt(base64_decode($string), $encrypt_method, $key_hash, OPENSSL_RAW_DATA, $iv);
    return $output;
}

function decompress($string) {
    return LZString::decompressFromEncodedURIComponent($string);
}

// === FUNGSI ENCRYPT UNTUK POST (HARUS SAMA DENGAN DECRYPT!) ===
function stringEncryptPost($key, $data) {
    $encrypt_method = 'AES-256-CBC';
    // CRITICAL: Harus SAMA dengan stringDecrypt untuk kompatibilitas
    $key_hash = hex2bin(hash('sha256', $key));  // hex2bin, sama dengan decrypt
    $iv = substr(hex2bin(hash('sha256', $key)), 0, 16);  // hex2bin, sama dengan decrypt
    $encrypted = openssl_encrypt($data, $encrypt_method, $key_hash, OPENSSL_RAW_DATA, $iv);
    return base64_encode($encrypted);
}

// === AMBIL DATA SETTING VCLAIM ===
try {
    $stmt = $pdo->query("SELECT * FROM setting_vclaim LIMIT 1");
    $setting = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($setting) {
        $cons_id    = trim($setting['cons_id']);
        $secret_key = trim($setting['secret_key']);
        $user_key   = trim($setting['user_key']);
        $base_url   = trim($setting['base_url']);
        $kd_ppk     = trim($setting['kd_ppk']);
        $nm_ppk     = trim($setting['nm_ppk']);
    } else {
        die("❌ Data setting VClaim belum diset.");
    }
} catch (Exception $e) {
    die("Gagal mengambil data setting VClaim: " . $e->getMessage());
}

// === MAPPING KODE POLI (CRITICAL FIX!) ===
// Mapping kode poli dari database ke standar BPJS
$POLI_MAPPING = [
    'OBGYN' => 'OBG',  // OBGYN di database → OBG di BPJS
    'INTER' => 'INT',  // Internal → INT
    // Tambahkan mapping lain jika diperlukan
];

// === FUNGSI GET DATA DARI BPJS (DENGAN DEBUGGING) ===
function getBpjsData($endpoint, $cons_id, $secret_key, $user_key, $debug = true) {
    date_default_timezone_set('UTC');
    $tStamp = strval(time());
    date_default_timezone_set('Asia/Jakarta');
    
    $signature = base64_encode(hash_hmac('sha256', $cons_id . "&" . $tStamp, $secret_key, true));
    
    $headers = [
        "X-cons-id: $cons_id",
        "X-timestamp: $tStamp",
        "X-signature: $signature",
        "user_key: $user_key",
        "Content-Type: application/json"
    ];
    
    if ($debug) {
        echo "<!-- DEBUG GET REQUEST -->\n";
        echo "<!-- Endpoint: $endpoint -->\n";
        echo "<!-- Timestamp: $tStamp -->\n";
        echo "<!-- Signature: $signature -->\n";
    }
    
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false, // Untuk development
        CURLOPT_SSL_VERIFYHOST => false  // Untuk development
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($debug) {
        echo "<!-- HTTP Code: $httpCode -->\n";
        echo "<!-- Response: " . htmlspecialchars(substr($response, 0, 500)) . " -->\n";
    }
    
    if ($errno) return ['metaData'=>['code'=>500,'message'=>"Curl error ($errno): $error"]];
    
    $json = json_decode($response, true);
    
    if (!$json) {
        return ['metaData'=>['code'=>501,'message'=>'JSON decode failed'], 'raw'=>$response];
    }
    
    if (isset($json['response']) && !empty($json['response']) && is_string($json['response'])) {
        $key = $cons_id . $secret_key . $tStamp;
        try {
            $decrypt = stringDecrypt($key, $json['response']);
            $decompressed = decompress($decrypt);
            $json['response'] = json_decode($decompressed, true);
        } catch (Exception $e) {
            if ($debug) {
                echo "<!-- Decrypt Error: " . $e->getMessage() . " -->\n";
            }
        }
    }
    
    return $json;
}

// === FUNGSI COMPRESS DATA (PENTING UNTUK POST) ===
function compress($string) {
    return LZString::compressToEncodedURIComponent($string);
}

// === FUNGSI POST DATA KE BPJS (DENGAN DEBUGGING LENGKAP) ===
function postBpjsData($endpoint, $postData, $cons_id, $secret_key, $user_key, $debug = true, $tStamp = null) {
    // Gunakan timestamp yang diberikan, atau buat baru jika tidak ada
    if ($tStamp === null) {
        date_default_timezone_set('UTC');
        $tStamp = strval(time());
        date_default_timezone_set('Asia/Jakarta');
    }
    
    // Signature untuk POST
    $signature = base64_encode(hash_hmac('sha256', $cons_id . "&" . $tStamp, $secret_key, true));
    
    // Convert data ke JSON string
    $jsonData = json_encode($postData);
    
    if ($debug) {
        echo "<div class='alert alert-info'>\n";
        echo "<h6>📤 DEBUG - Original Data to Send:</h6>\n";
        echo "<pre>" . htmlspecialchars($jsonData) . "</pre>\n";
        echo "</div>\n";
    }
    
    // Encrypt data: Compress dulu, baru encrypt
    $key = $cons_id . $secret_key . $tStamp;
    $compressedData = compress($jsonData);
    $encryptedData = stringEncryptPost($key, $compressedData);
    
    // VALIDASI: Test decrypt untuk memastikan enkripsi benar
    if ($debug) {
        $testDecrypt = stringDecrypt($key, $encryptedData);
        $testDecompress = decompress($testDecrypt);
        if ($testDecompress === $jsonData) {
            echo "<div class='alert alert-success'>\n";
            echo "✅ Enkripsi/Dekripsi VALID - Data bisa di-decrypt kembali dengan benar\n";
            echo "</div>\n";
        } else {
            echo "<div class='alert alert-danger'>\n";
            echo "❌ WARNING: Enkripsi/Dekripsi TIDAK VALID!\n";
            echo "<br>Original length: " . strlen($jsonData) . "\n";
            echo "<br>Decrypted length: " . strlen($testDecompress) . "\n";
            echo "<br>Match: " . ($testDecompress === $jsonData ? 'YES' : 'NO') . "\n";
            echo "</div>\n";
        }
    }
    
    // Buat request body dengan format yang benar (CRITICAL: harus pakai 'request=')
    $requestBody = "request=" . urlencode($encryptedData);

    
    // Headers yang benar untuk BPJS (TAMBAHKAN HOST HEADER)
    $headers = [
        "Host: apijkn-dev.bpjs-kesehatan.go.id",
        "X-cons-id: $cons_id",
        "X-timestamp: $tStamp",
        "X-signature: $signature",
        "user_key: $user_key",  // PENTING: tanpa X- prefix
        "Content-Type: application/x-www-form-urlencoded"
    ];
    
    if ($debug) {
        echo "<div class='alert alert-secondary'>\n";
        echo "<h6>📤 DEBUG - Request Info</h6>\n";
        echo "<small>\n";
        echo "Endpoint: $endpoint<br>\n";
        echo "Timestamp: $tStamp<br>\n";
        echo "Cons ID: $cons_id<br>\n";
        echo "User Key: $user_key<br>\n";
        echo "Signature: $signature<br>\n";
        echo "Plain JSON Length: " . strlen($jsonData) . " bytes<br>\n";
        echo "Compressed Length: " . strlen($compressedData) . " bytes<br>\n";
        echo "Encrypted Length: " . strlen($encryptedData) . " bytes<br>\n";
        echo "Encryption Key Preview: " . substr($key, 0, 20) . "...<br>\n";
        echo "Encrypted Data Preview: <pre>request=" . substr($encryptedData, 0, 100) . "...</pre>\n";
        echo "</small>\n";
        echo "</div>\n";
    }
    
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $requestBody,  // Kirim sebagai JSON string
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($debug) {
        echo "<div class='alert alert-warning'>\n";
        echo "<h6>📥 DEBUG - Response Info</h6>\n";
        echo "<small>\n";
        echo "HTTP Code: <strong>$httpCode</strong><br>\n";
        
        if ($httpCode == 200) {
            echo "<span class='text-success'>✅ Response OK</span><br>\n";
            echo "Response Preview: " . htmlspecialchars(substr($response, 0, 500)) . "<br>\n";
        } elseif ($httpCode == 400) {
            echo "<span class='text-danger'>❌ BAD REQUEST - Request ditolak server</span><br>\n";
            echo "<strong>Kemungkinan masalah:</strong> Kode poli, diagnosa, atau dokter tidak valid<br>\n";
            echo "<strong>Full Response:</strong><br>\n";
            echo "<textarea class='form-control' rows='10' style='font-size: 11px;'>" . htmlspecialchars(substr($response, 0, 3000)) . "</textarea><br>\n";
        } elseif ($httpCode == 500 || $httpCode == 502 || $httpCode == 503) {
            echo "<span class='text-danger'>❌ SERVER ERROR - BPJS tidak bisa process request</span><br>\n";
            echo "<strong>Kemungkinan masalah:</strong><br>\n";
            echo "1. Data terenkripsi tidak bisa di-decrypt oleh BPJS<br>\n";
            echo "2. Format data JSON tidak sesuai spesifikasi<br>\n";
            echo "3. Field required tidak lengkap atau format salah<br>\n";
            echo "4. Server BPJS sedang bermasalah<br>\n";
            echo "<strong>Full Response:</strong><br>\n";
            echo "<textarea class='form-control' rows='10' style='font-size: 11px;'>" . htmlspecialchars(substr($response, 0, 3000)) . "</textarea><br>\n";
        } elseif ($httpCode == 401) {
            echo "<span class='text-danger'>❌ UNAUTHORIZED - Kredensial tidak valid</span><br>\n";
            echo "<strong>Periksa:</strong> cons_id, secret_key, user_key, signature<br>\n";
        } else {
            echo "<span class='text-warning'>⚠️ Unexpected HTTP Code</span><br>\n";
            echo "Response Preview: " . htmlspecialchars(substr($response, 0, 1000)) . "<br>\n";
        }
        
        echo "</small>\n";
        echo "</div>\n";
    }
    
    if ($errno) {
        return ['metaData'=>['code'=>500,'message'=>"Curl error ($errno): $error"]];
    }
    
    // Parse JSON response
    $jsonResponse = json_decode($response, true);
    
    if (!$jsonResponse) {
        return [
            "metaData" => ["code" => 501, "message" => "JSON decode failed"],
            "raw" => $response,
            "http_code" => $httpCode
        ];
    }
    
    // Cek apakah ada response yang perlu di-decrypt
    if (isset($jsonResponse["response"]) && is_string($jsonResponse["response"]) && !empty($jsonResponse["response"])) {
        try {
            $decrypt = stringDecrypt($key, $jsonResponse["response"]);
            
            if ($debug) {
                echo "<!-- Decrypted: " . htmlspecialchars(substr($decrypt, 0, 500)) . " -->\n";
            }
            
            $decompressed = decompress($decrypt);
            
            if ($debug) {
                echo "<!-- Decompressed: " . htmlspecialchars(substr($decompressed, 0, 500)) . " -->\n";
            }
            
            $jsonResponse["response"] = json_decode($decompressed, true);
            
            if (!$jsonResponse["response"]) {
                $jsonResponse["response"] = $decompressed; // Fallback ke string
            }
            
        } catch (Exception $e) {
            if ($debug) {
                echo "<!-- Decrypt/Decompress Error: " . $e->getMessage() . " -->\n";
            }
            return [
                "metaData" => ["code" => 502, "message" => "Decrypt error: " . $e->getMessage()],
                "raw" => $response
            ];
        }
    }
    
    return $jsonResponse;
}

// === FUNGSI MAPPING KODE POLI ===
function mapKodePoli($kode, $pdo_simrs, $POLI_MAPPING) {
    global $cons_id, $secret_key, $user_key, $base_url;
    
    // Cek apakah kode valid di BPJS
    $url_cek = $base_url . "/referensi/poli/" . $kode;
    $response = getBpjsData($url_cek, $cons_id, $secret_key, $user_key, false);
    
    if ($response['metaData']['code'] == '200') {
        // Kode valid, return langsung
        return ['kode' => $kode, 'valid' => true];
    }
    
    // Kode tidak valid, coba mapping
    if (isset($POLI_MAPPING[$kode])) {
        $kode_mapped = $POLI_MAPPING[$kode];
        
        // Verify kode hasil mapping
        $url_cek2 = $base_url . "/referensi/poli/" . $kode_mapped;
        $response2 = getBpjsData($url_cek2, $cons_id, $secret_key, $user_key, false);
        
        if ($response2['metaData']['code'] == '200') {
            return ['kode' => $kode_mapped, 'valid' => true, 'mapped' => true, 'original' => $kode];
        }
    }
    
    // Tidak bisa mapping
    return ['kode' => $kode, 'valid' => false];
}

// === VARIABEL UTAMA ===
$error = '';
$success = '';
$step = 1;
$data_pasien = null;
$data_rujukan = [];
$debug_info = [];

// Validasi parameter
if (!isset($_GET['no_rawat']) || empty($_GET['no_rawat'])) {
    die("❌ Parameter no_rawat tidak ditemukan!");
}

$no_rawat = $_GET['no_rawat'];
$no_rm = $_GET['no_rm'] ?? '';

// === AMBIL DATA PASIEN & REGISTRASI ===
try {
    $sql = "SELECT 
                p.no_rkm_medis,
                p.nm_pasien,
                p.no_peserta,
                p.jk,
                p.tgl_lahir,
                p.no_tlp,
                p.alamat,
                r.no_rawat,
                r.tgl_registrasi,
                r.jam_reg,
                r.kd_dokter,
                d.nm_dokter,
                r.kd_poli,
                pl.nm_poli,
                mpb.kd_poli_bpjs,
                mpb.nm_poli_bpjs,
                mdb.kd_dokter_bpjs,
                pj.png_jawab,
                r.kd_pj
            FROM reg_periksa r
            INNER JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
            LEFT JOIN dokter d ON r.kd_dokter = d.kd_dokter
            LEFT JOIN poliklinik pl ON r.kd_poli = pl.kd_poli
            LEFT JOIN maping_poli_bpjs mpb ON r.kd_poli = mpb.kd_poli_rs
            LEFT JOIN maping_dokter_dpjpvclaim mdb ON r.kd_dokter = mdb.kd_dokter
            LEFT JOIN penjab pj ON r.kd_pj = pj.kd_pj
            WHERE r.no_rawat = :no_rawat
            LIMIT 1";
    
    $stmt = $pdo_simrs->prepare($sql);
    $stmt->execute(['no_rawat' => $no_rawat]);
    $data_pasien = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$data_pasien) {
        die("❌ Data registrasi tidak ditemukan untuk No. Rawat: " . htmlspecialchars($no_rawat));
    }
    
    // Cek apakah sudah ada SEP
    $stmt_cek = $pdo_simrs->prepare("SELECT no_sep FROM bridging_sep WHERE no_rawat = ?");
    $stmt_cek->execute([$no_rawat]);
    $sep_exist = $stmt_cek->fetch(PDO::FETCH_ASSOC);
    
    if ($sep_exist) {
        header("Location: cetak_sep_poli.php?no_rawat=" . urlencode($no_rawat));
        exit;
    }
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// === CEK RUJUKAN OTOMATIS ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cek_rujukan'])) {
    $noKartu = trim($_POST['noKartu']);
    
    if (empty($noKartu)) {
        $error = "No. Kartu BPJS tidak boleh kosong!";
    } else {
        $url = $base_url . "/Rujukan/List/Peserta/" . $noKartu;
        $response = getBpjsData($url, $cons_id, $secret_key, $user_key, true);
        
        $debug_info['rujukan_response'] = $response;
        
        if (isset($response['metaData']['code']) && $response['metaData']['code'] == '200') {
            $rujukan_list = $response['response']['rujukan'] ?? [];
            
            if (!empty($rujukan_list)) {
                $rujukan = $rujukan_list[0];
                
                // MAPPING KODE POLI DARI RUJUKAN
                $kode_poli_rujukan = $rujukan['poliRujukan']['kode'] ?? '';
                if (!empty($kode_poli_rujukan)) {
                    $poli_result = mapKodePoli($kode_poli_rujukan, $pdo_simrs, $POLI_MAPPING);
                    if (isset($poli_result['mapped']) && $poli_result['mapped']) {
                        echo "<div class='alert alert-info'>";
                        echo "ℹ️ Kode poli di-mapping: {$poli_result['original']} → {$poli_result['kode']}";
                        echo "</div>";
                    }
                    $kode_poli_rujukan = $poli_result['kode'];
                }
                
                $data_rujukan = [
                    'noRujukan' => $rujukan['noKunjungan'] ?? '',
                    'tglRujukan' => $rujukan['tglKunjungan'] ?? date('Y-m-d'),
                    'ppkRujukan' => $rujukan['provPerujuk']['kode'] ?? '',
                    'nmppkRujukan' => $rujukan['provPerujuk']['nama'] ?? '',
                    'diagAwal' => $rujukan['diagnosa']['kode'] ?? '',
                    'nmDiagAwal' => $rujukan['diagnosa']['nama'] ?? '',
                    'poliTujuan' => $kode_poli_rujukan,  // KODE YANG SUDAH DI-MAPPING
                    'nmPoliTujuan' => $rujukan['poliRujukan']['nama'] ?? '',
                    'peserta' => $rujukan['peserta']['jenisPeserta']['keterangan'] ?? '',
                    'noTelp' => $rujukan['peserta']['mr']['noTelepon'] ?? ''
                ];
                
                $step = 2;
            } else {
                $error = "❌ Tidak ada rujukan aktif untuk No. Kartu: $noKartu";
            }
        } else {
            $error = "❌ " . ($response['metaData']['message'] ?? 'Gagal mengambil data rujukan');
            if (isset($response['raw'])) {
                $debug_info['error_detail'] = $response['raw'];
            }
        }
    }
}

// === PROSES BUAT SEP ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buat_sep'])) {
    try {
        $noKartu = $_POST['noKartu'];
        $tglSep = $_POST['tglSep'];
        $noRujukan = $_POST['noRujukan'];
        $ppkRujukan = $_POST['ppkRujukan'];
        $tglRujukan = $_POST['tglRujukan'];
        $diagAwal = $_POST['diagAwal'];
        $kdPoliTujuan = $_POST['kdPoliTujuan'];
        $kdDokter = $_POST['kdDokter'];
        $klsRawat = $_POST['klsRawat'];
        $catatan = $_POST['catatan'];
        $noTelp = $_POST['noTelp'];
        $user = $_SESSION['username'] ?? 'AdminPoli';
        
        // ===== MAPPING KODE POLI SEBELUM KIRIM (CRITICAL!) =====
        $poli_result = mapKodePoli($kdPoliTujuan, $pdo_simrs, $POLI_MAPPING);
        
        if (!$poli_result['valid']) {
            throw new Exception("Kode poli '$kdPoliTujuan' tidak valid di referensi BPJS. Silakan pilih poli lain atau hubungi admin untuk mapping kode poli.");
        }
        
        if (isset($poli_result['mapped']) && $poli_result['mapped']) {
            $kdPoliTujuan = $poli_result['kode'];
            echo "<div class='alert alert-success'>";
            echo "✅ Kode poli berhasil di-mapping: {$poli_result['original']} → {$kdPoliTujuan}";
            echo "</div>";
        }
        
        // Buat JSON request untuk BPJS (SESUAI DOKUMENTASI VCLAIM 2.0)
        $request_data = [
            "noKartu" => $noKartu,
            "tglSep" => $tglSep,
            "ppkPelayanan" => $kd_ppk,
            "jnsPelayanan" => "2", // 2 = Rawat Jalan
            "klsRawat" => [
                "klsRawatHak" => $klsRawat,
                "klsRawatNaik" => "",
                "pembiayaan" => "",
                "penanggungJawab" => ""
            ],
            "noMR" => $data_pasien['no_rkm_medis'],
            "rujukan" => [
                "asalRujukan" => "1",
                "tglRujukan" => $tglRujukan,
                "noRujukan" => $noRujukan,
                "ppkRujukan" => $ppkRujukan
            ],
            "catatan" => $catatan ?: "-",
            "diagAwal" => $diagAwal,
            "poli" => [
                "tujuan" => $kdPoliTujuan,  // KODE YANG SUDAH DI-MAPPING
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
                "kodeDPJP" => $kdDokter
            ],
            "dpjpLayan" => $kdDokter,
            "noTelp" => $noTelp ?: "0",
            "user" => $user
        ];
        
        // === PROSES DATA UNTUK KIRIM KE BPJS ===
        $debug_info['request_data'] = $request_data;
        
        // Kirim ke BPJS
        $url = $base_url . "/SEP/2.0/insert";
        
        $dataToSend = [
            "request" => [
                "t_sep" => $request_data
            ]
        ];
        
        $response = postBpjsData($url, $dataToSend, $cons_id, $secret_key, $user_key, true);
        
        $debug_info['sep_response'] = $response;
        
        if (isset($response['metaData']['code']) && $response['metaData']['code'] == '200') {
            $sep_response = $response['response'] ?? [];
            
            // Cek struktur response
            if (is_array($sep_response) && isset($sep_response['sep'])) {
                $sep_data = $sep_response['sep'];
                $no_sep = $sep_data['noSep'] ?? '';
            } elseif (is_array($sep_response) && isset($sep_response['noSep'])) {
                $no_sep = $sep_response['noSep'];
            } else {
                $no_sep = '';
            }
            
            if ($no_sep) {
                // Simpan ke database bridging_sep
                $sql_insert = "INSERT INTO bridging_sep SET
                    no_sep = :no_sep,
                    no_rawat = :no_rawat,
                    tglsep = :tglsep,
                    tglrujukan = :tglrujukan,
                    no_rujukan = :no_rujukan,
                    kdppkrujukan = :kdppkrujukan,
                    nmppkrujukan = :nmppkrujukan,
                    kdppkpelayanan = :kdppkpelayanan,
                    nmppkpelayanan = :nmppkpelayanan,
                    jnspelayanan = '2',
                    catatan = :catatan,
                    diagawal = :diagawal,
                    nmdiagnosaawal = :nmdiagnosaawal,
                    kdpolitujuan = :kdpolitujuan,
                    nmpolitujuan = :nmpolitujuan,
                    klsrawat = :klsrawat,
                    klsnaik = '',
                    pembiayaan = '',
                    pjnaikkelas = '',
                    lakalantas = '0',
                    user = :user,
                    nomr = :nomr,
                    nama_pasien = :nama_pasien,
                    tanggal_lahir = :tanggal_lahir,
                    peserta = :peserta,
                    jkel = :jkel,
                    no_kartu = :no_kartu,
                    tglpulang = NULL,
                    asal_rujukan = '1. Faskes 1',
                    eksekutif = '0. Tidak',
                    cob = '0. Tidak',
                    notelep = :notelep,
                    katarak = '0. Tidak',
                    tglkkl = '0000-00-00',
                    keterangankkl = '',
                    suplesi = '0. Tidak',
                    no_sep_suplesi = '',
                    kdprop = '',
                    nmprop = '',
                    kdkab = '',
                    nmkab = '',
                    kdkec = '',
                    nmkec = '',
                    noskdp = '',
                    kddpjp = :kddpjp,
                    nmdpdjp = :nmdpdjp,
                    tujuankunjungan = '0',
                    flagprosedur = '',
                    penunjang = '',
                    asesmenpelayanan = '',
                    kddpjplayanan = :kddpjplayanan,
                    nmdpjplayanan = :nmdpjplayanan";
                
                $stmt_insert = $pdo_simrs->prepare($sql_insert);
                $stmt_insert->execute([
                    'no_sep' => $no_sep,
                    'no_rawat' => $no_rawat,
                    'tglsep' => $tglSep,
                    'tglrujukan' => $tglRujukan,
                    'no_rujukan' => $noRujukan,
                    'kdppkrujukan' => $ppkRujukan,
                    'nmppkrujukan' => $_POST['nmppkRujukan'] ?? '-',
                    'kdppkpelayanan' => $kd_ppk,
                    'nmppkpelayanan' => $nm_ppk,
                    'catatan' => $catatan,
                    'diagawal' => $diagAwal,
                    'nmdiagnosaawal' => $_POST['nmDiagAwal'] ?? '-',
                    'kdpolitujuan' => $kdPoliTujuan,
                    'nmpolitujuan' => $_POST['nmPoliTujuan'] ?? $data_pasien['nm_poli_bpjs'],
                    'klsrawat' => $klsRawat,
                    'user' => $user,
                    'nomr' => $data_pasien['no_rkm_medis'],
                    'nama_pasien' => $data_pasien['nm_pasien'],
                    'tanggal_lahir' => $data_pasien['tgl_lahir'],
                    'peserta' => $_POST['jenisPeserta'] ?? '-',
                    'jkel' => $data_pasien['jk'],
                    'no_kartu' => $noKartu,
                    'notelep' => $noTelp,
                    'kddpjp' => $kdDokter,
                    'nmdpdjp' => $data_pasien['nm_dokter'],
                    'kddpjplayanan' => $kdDokter,
                    'nmdpjplayanan' => $data_pasien['nm_dokter']
                ]);
                
                $success = "✅ SEP berhasil dibuat! No. SEP: $no_sep";
                $step = 3;
            } else {
                $error = "❌ Gagal mendapatkan nomor SEP dari response BPJS. Response: " . print_r($sep_response, true);
            }
        } else {
            $error = "❌ " . ($response['metaData']['message'] ?? 'Gagal membuat SEP');
            if (isset($response['raw'])) {
                $error .= "<br><small>Detail: " . htmlspecialchars(substr($response['raw'], 0, 500)) . "</small>";
            }
        }
        
    } catch (Exception $e) {
        $error = "❌ Error: " . $e->getMessage();
        $debug_info['exception'] = $e->getTraceAsString();
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>📄 Buat SEP Manual</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<style>
body {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
    padding: 20px;
}
.main-card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: 20px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    max-width: 1200px;
    margin: 0 auto;
}
.card-header {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
    border-radius: 20px 20px 0 0 !important;
    padding: 1.5rem;
}
.info-box {
    background: #f8f9fa;
    padding: 1.5rem;
    border-radius: 10px;
    margin-bottom: 1.5rem;
    border-left: 4px solid #f59e0b;
}
.form-section {
    background: white;
    padding: 2rem;
    border-radius: 15px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    margin-bottom: 1.5rem;
}
.section-title {
    color: #f59e0b;
    font-weight: bold;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #f59e0b;
}
.auto-fill-badge {
    background: #10b981;
    color: white;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
    margin-left: 5px;
}
.debug-box {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 5px;
    padding: 10px;
    margin: 10px 0;
    font-family: monospace;
    font-size: 11px;
    max-height: 300px;
    overflow-y: auto;
}
</style>
</head>
<body>
<div class="container">
    <div class="main-card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h3 class="mb-0"><i class="bi bi-file-earmark-plus"></i> Buat SEP Manual</h3>
                <a href="cek_pasien_poli.php" class="btn btn-light">
                    <i class="bi bi-arrow-left"></i> Kembali
                </a>
            </div>
        </div>
        <div class="card-body p-4">
            
            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill"></i> <?= $error ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($debug_info) && isset($_GET['debug'])): ?>
            <div class="alert alert-warning">
                <strong>Debug Information:</strong>
                <div class="debug-box">
                    <pre><?= htmlspecialchars(print_r($debug_info, true)) ?></pre>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill"></i> <?= $success ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <div class="text-center mt-3">
                <a href="cetak_sep_poli.php?no_rawat=<?= urlencode($no_rawat) ?>" 
                   class="btn btn-success btn-lg" target="_blank">
                    <i class="bi bi-printer-fill"></i> Cetak SEP
                </a>
                <a href="cek_pasien_poli.php" class="btn btn-secondary btn-lg">
                    <i class="bi bi-arrow-left"></i> Kembali ke Cek Pasien
                </a>
            </div>
            <?php else: ?>
            
            <!-- Info Pasien -->
            <div class="info-box">
                <h5><i class="bi bi-person-circle"></i> Informasi Pasien</h5>
                <div class="row">
                    <div class="col-md-6">
                        <strong>No. RM:</strong> <?= htmlspecialchars($data_pasien['no_rkm_medis']) ?><br>
                        <strong>Nama:</strong> <?= htmlspecialchars($data_pasien['nm_pasien']) ?><br>
                        <strong>No. Kartu BPJS:</strong> <?= htmlspecialchars($data_pasien['no_peserta']) ?><br>
                        <strong>Tgl Lahir:</strong> <?= date('d/m/Y', strtotime($data_pasien['tgl_lahir'])) ?>
                    </div>
                    <div class="col-md-6">
                        <strong>No. Rawat:</strong> <?= htmlspecialchars($no_rawat) ?><br>
                        <strong>Poli:</strong> <?= htmlspecialchars($data_pasien['nm_poli']) ?><br>
                        <strong>Dokter:</strong> <?= htmlspecialchars($data_pasien['nm_dokter']) ?><br>
                        <strong>Tgl Registrasi:</strong> <?= date('d/m/Y', strtotime($data_pasien['tgl_registrasi'])) ?>
                    </div>
                </div>
            </div>
            
            <?php if ($step == 1): ?>
            <!-- Step 1: Cek Rujukan Otomatis -->
            <div class="alert alert-info">
                <h5><i class="bi bi-lightbulb"></i> Petunjuk:</h5>
                <p class="mb-0">
                    Klik tombol <strong>"Cek Rujukan Otomatis"</strong> untuk mengambil data rujukan dari BPJS Web Service.
                    Data seperti nomor rujukan, PPK perujuk, dan diagnosa akan terisi otomatis.
                </p>
            </div>
            
            <div class="form-section">
                <h5 class="section-title">Cek Rujukan Otomatis</h5>
                <form method="POST">
                    <input type="hidden" name="cek_rujukan" value="1">
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">No. Kartu BPJS <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-lg" name="noKartu" 
                                   value="<?= htmlspecialchars($data_pasien['no_peserta']) ?>" required>
                        </div>
                        <div class="col-md-4 mb-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary btn-lg w-100">
                                <i class="bi bi-search"></i> Cek Rujukan Otomatis
                            </button>
                        </div>
                    </div>
                </form>
                
                <hr>
                
                <div class="text-center text-muted">
                    <small>Atau Anda bisa <a href="?no_rawat=<?= urlencode($no_rawat) ?>&manual=1">isi manual tanpa cek rujukan</a></small>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($step == 2 || isset($_GET['manual'])): ?>
            <!-- Step 2: Form Buat SEP (dengan/tanpa auto-fill) -->
            <?php if (!empty($data_rujukan)): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle-fill"></i> 
                <strong>Rujukan ditemukan!</strong> Data telah terisi otomatis. Silakan periksa dan lengkapi jika ada yang kurang.
            </div>
            <?php endif; ?>
            
            <form method="POST">
                <input type="hidden" name="buat_sep" value="1">
                
                <div class="form-section">
                    <h5 class="section-title">Data Peserta BPJS</h5>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">No. Kartu BPJS <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="noKartu" 
                                   value="<?= htmlspecialchars($data_pasien['no_peserta']) ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                Jenis Peserta
                                <?php if (!empty($data_rujukan['peserta'])): ?>
                                <span class="auto-fill-badge">AUTO</span>
                                <?php endif; ?>
                            </label>
                            <input type="text" class="form-control" name="jenisPeserta" 
                                   value="<?= htmlspecialchars($data_rujukan['peserta'] ?? 'PBPU DAN BP PEMERINTAH DAERAH') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Hak Kelas <span class="text-danger">*</span></label>
                            <select class="form-select" name="klsRawat" required>
                                <option value="3">Kelas 3</option>
                                <option value="2">Kelas 2</option>
                                <option value="1">Kelas 1</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                No. Telepon <span class="text-danger">*</span>
                                <?php if (!empty($data_rujukan['noTelp'])): ?>
                                <span class="auto-fill-badge">AUTO</span>
                                <?php endif; ?>
                            </label>
                            <input type="text" class="form-control" name="noTelp" 
                                   value="<?= htmlspecialchars($data_rujukan['noTelp'] ?? $data_pasien['no_tlp'] ?? '08') ?>" required>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h5 class="section-title">
                        Data Rujukan
                        <?php if (!empty($data_rujukan)): ?>
                        <span class="auto-fill-badge">DATA AUTO-FILL DARI BPJS</span>
                        <?php endif; ?>
                    </h5>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                No. Rujukan <span class="text-danger">*</span>
                                <?php if (!empty($data_rujukan['noRujukan'])): ?>
                                <span class="auto-fill-badge">AUTO</span>
                                <?php endif; ?>
                            </label>
                            <input type="text" class="form-control" name="noRujukan" 
                                   value="<?= htmlspecialchars($data_rujukan['noRujukan'] ?? '') ?>" required>
                            <small class="text-muted">Nomor rujukan dari Faskes 1</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                Tanggal Rujukan <span class="text-danger">*</span>
                                <?php if (!empty($data_rujukan['tglRujukan'])): ?>
                                <span class="auto-fill-badge">AUTO</span>
                                <?php endif; ?>
                            </label>
                            <input type="date" class="form-control" name="tglRujukan" 
                                   value="<?= htmlspecialchars($data_rujukan['tglRujukan'] ?? date('Y-m-d')) ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                Kode PPK Rujukan <span class="text-danger">*</span>
                                <?php if (!empty($data_rujukan['ppkRujukan'])): ?>
                                <span class="auto-fill-badge">AUTO</span>
                                <?php endif; ?>
                            </label>
                            <input type="text" class="form-control" name="ppkRujukan" 
                                   value="<?= htmlspecialchars($data_rujukan['ppkRujukan'] ?? '') ?>"
                                   placeholder="Contoh: 00010001" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                Nama PPK Rujukan
                                <?php if (!empty($data_rujukan['nmppkRujukan'])): ?>
                                <span class="auto-fill-badge">AUTO</span>
                                <?php endif; ?>
                            </label>
                            <input type="text" class="form-control" name="nmppkRujukan" 
                                   value="<?= htmlspecialchars($data_rujukan['nmppkRujukan'] ?? '') ?>">
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h5 class="section-title">Data Pelayanan</h5>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Tanggal SEP <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="tglSep" 
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                Poli Tujuan <span class="text-danger">*</span>
                                <?php if (!empty($data_rujukan['poliTujuan'])): ?>
                                <span class="auto-fill-badge">AUTO</span>
                                <?php endif; ?>
                            </label>
                            <select class="form-select" name="kdPoliTujuan" required>
                                <option value="">-- Pilih Poli --</option>
                                <?php
                                $stmt = $pdo_simrs->query("SELECT * FROM maping_poli_bpjs ORDER BY nm_poli_bpjs");
                                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                    $selected = '';
                                    if (!empty($data_rujukan['poliTujuan']) && $row['kd_poli_bpjs'] == $data_rujukan['poliTujuan']) {
                                        $selected = 'selected';
                                    } elseif (empty($data_rujukan) && $row['kd_poli_rs'] == $data_pasien['kd_poli']) {
                                        $selected = 'selected';
                                    }
                                    echo "<option value='{$row['kd_poli_bpjs']}' $selected>{$row['nm_poli_bpjs']}</option>";
                                }
                                ?>
                            </select>
                            <input type="hidden" name="nmPoliTujuan" value="<?= htmlspecialchars($data_rujukan['nmPoliTujuan'] ?? $data_pasien['nm_poli_bpjs'] ?? '') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                Diagnosa Awal (ICD-10) <span class="text-danger">*</span>
                                <?php if (!empty($data_rujukan['diagAwal'])): ?>
                                <span class="auto-fill-badge">AUTO</span>
                                <?php endif; ?>
                            </label>
                            <input type="text" class="form-control" name="diagAwal" 
                                   value="<?= htmlspecialchars($data_rujukan['diagAwal'] ?? '') ?>"
                                   placeholder="Contoh: K30" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                Nama Diagnosa
                                <?php if (!empty($data_rujukan['nmDiagAwal'])): ?>
                                <span class="auto-fill-badge">AUTO</span>
                                <?php endif; ?>
                            </label>
                            <input type="text" class="form-control" name="nmDiagAwal" 
                                   value="<?= htmlspecialchars($data_rujukan['nmDiagAwal'] ?? '') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Dokter DPJP <span class="text-danger">*</span></label>
                            <select class="form-select" name="kdDokter" required>
                                <option value="">-- Pilih Dokter --</option>
                                <?php
                                $stmt = $pdo_simrs->query("SELECT d.kd_dokter, d.nm_dokter, m.kd_dokter_bpjs 
                                                          FROM dokter d 
                                                          LEFT JOIN maping_dokter_dpjpvclaim m ON d.kd_dokter = m.kd_dokter 
                                                          WHERE m.kd_dokter_bpjs IS NOT NULL 
                                                          ORDER BY d.nm_dokter");
                                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                    $selected = ($row['kd_dokter'] == $data_pasien['kd_dokter']) ? 'selected' : '';
                                    echo "<option value='{$row['kd_dokter_bpjs']}' $selected>{$row['nm_dokter']}</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Catatan</label>
                            <input type="text" class="form-control" name="catatan" value="-">
                        </div>
                    </div>
                </div>
                
                <div class="d-flex gap-2 justify-content-end">
                    <a href="?no_rawat=<?= urlencode($no_rawat) ?>" class="btn btn-secondary btn-lg">
                        <i class="bi bi-arrow-left"></i> Kembali
                    </a>
                    <button type="submit" class="btn btn-warning btn-lg">
                        <i class="bi bi-file-earmark-plus"></i> Buat SEP
                    </button>
                </div>
            </form>
            <?php endif; ?>
            
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>