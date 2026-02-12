<?php
// DEBUG MODE
error_reporting(E_ALL);        // tampilkan semua error
ini_set('display_errors', 1);  // tampilkan di layar
ini_set('display_startup_errors', 1);

session_start();
include 'koneksi.php';  // koneksi database anjungan
include 'koneksi2.php'; // koneksi database SIMRS
require_once __DIR__ . '/vendor/autoload.php';
use LZCompressor\LZString;

date_default_timezone_set('Asia/Jakarta');

// === AMBIL DATA SETTING VCLAIM DARI DATABASE ===
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

// === FUNGSI ENKRIPSI & API ===
function stringEncrypt($key, $string) {
    $encrypt_method = 'AES-256-CBC';
    $key_hash = hex2bin(hash('sha256', $key));
    $iv = substr($key_hash, 0, 16);
    return base64_encode(openssl_encrypt($string, $encrypt_method, $key_hash, OPENSSL_RAW_DATA, $iv));
}

function stringDecrypt($key, $string) {
    $encrypt_method = 'AES-256-CBC';
    $key_hash = hex2bin(hash('sha256', $key));
    $iv = substr($key_hash, 0, 16);
    return openssl_decrypt(base64_decode($string), $encrypt_method, $key_hash, OPENSSL_RAW_DATA, $iv);
}

function decompress($string) {
    return LZString::decompressFromEncodedURIComponent($string);
}



function postBpjsData($endpoint, $data, $cons_id, $secret_key, $user_key, $tStamp) {
    // PENTING: Timestamp diterima dari parameter, tidak buat baru!
    // Harus sama dengan timestamp yang dipakai untuk enkripsi
    
    $signature = base64_encode(hash_hmac('sha256', $cons_id . "&" . $tStamp, $secret_key, true));

  $headers = [
        "X-cons-id: $cons_id",
        "X-timestamp: $tStamp",
        "X-signature: $signature",
        "user_key: $user_key",
    "Content-Type: application/x-www-form-urlencoded"
];


    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error_msg = curl_error($ch);
    curl_close($ch);

    if ($errno) {
        return [
            'metaData' => [
                'code' => 500,
                'message' => "Curl error: $error_msg"
            ]
        ];
    }

    // --- RESPONSE BPJS SELALU TERENKRIPSI ---
    $jsonResponse = json_decode($response, true);

    if (!$jsonResponse) {
        return [
            "metaData" => [
                "code" => 501,
                "message" => "Format response tidak valid / gagal decode JSON"
            ],
            "raw" => $response
        ];
    }

    if (!isset($jsonResponse["response"]) || empty($jsonResponse["response"])) {
        // Data kosong biasanya karena GAGAL decrypt / signature salah
        return $jsonResponse;
    }

    // --- PROSES DECRYPT BPJS ---
    $key = $cons_id . $secret_key . $tStamp;
    $decrypt = stringDecrypt($key, $jsonResponse["response"]);
    $decompressed = decompress($decrypt);

    $jsonResponse["response"] = json_decode($decompressed, true);

    return $jsonResponse;
}


// === GENERATE NOMOR RM BARU ===
function generateNoRM($pdo_simrs) {
    try {
        $stmt = $pdo_simrs->query("SELECT no_rkm_medis FROM pasien WHERE no_rkm_medis NOT IN ('-','000001') ORDER BY no_rkm_medis DESC LIMIT 1");
        $last = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($last) {
            $lastNum = intval($last['no_rkm_medis']);
            $newNum = $lastNum + 1;
            return str_pad($newNum, 6, '0', STR_PAD_LEFT);
        } else {
            return '000002';
        }
    } catch (Exception $e) {
        return '000002';
    }
}

// === GENERATE NOMOR REGISTRASI ===
function generateNoReg($pdo_simrs, $tgl_registrasi) {
    try {
        // Cari nomor registrasi terakhir untuk tanggal yang sama
        $stmt = $pdo_simrs->prepare("SELECT no_reg FROM reg_periksa WHERE tgl_registrasi = :tgl ORDER BY no_reg DESC LIMIT 1");
        $stmt->execute(['tgl' => $tgl_registrasi]);
        $last = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($last) {
            $lastNum = intval($last['no_reg']);
            $newNum = $lastNum + 1;
            return str_pad($newNum, 3, '0', STR_PAD_LEFT);
        } else {
            return '001'; // Nomor pertama untuk hari ini
        }
    } catch (Exception $e) {
        return '001';
    }
}

// === VARIABEL UTAMA ===
$data_rujukan = [];
$pasien_data = null;
$no_rm_baru = '';
$error = '';
$success = '';
$step = 1;

// === PROSES CEK PASIEN ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['noKartu']) && !isset($_POST['simpan_pasien']) && !isset($_POST['buat_sep'])) {
    $data_rujukan = [
        'noKartu' => $_POST['noKartu'] ?? '',
        'noRujukan' => $_POST['noRujukan'] ?? '',
        'namaPeserta' => $_POST['namaPeserta'] ?? '',
        'diagAwal' => $_POST['diagAwal'] ?? '',
        'poliTujuan' => $_POST['poliTujuan'] ?? '',
        'hakKelas' => $_POST['hakKelas'] ?? '',
        'statusPeserta' => $_POST['statusPeserta'] ?? '',
        'jenisPeserta' => $_POST['jenisPeserta'] ?? '',
        'noTelp' => $_POST['noTelp'] ?? '',
        // Data tambahan dari WS BPJS untuk auto-fill
        'nik' => $_POST['nik'] ?? '',
        'tglLahir' => $_POST['tglLahir'] ?? '',
        'jenisKelamin' => $_POST['jenisKelamin'] ?? '',
        'tglRujukan' => $_POST['tglRujukan'] ?? '',
        'kdProvPerujuk' => $_POST['kdProvPerujuk'] ?? '',
        'nmProvPerujuk' => $_POST['nmProvPerujuk'] ?? ''
    ];

    try {
        $stmt = $pdo_simrs->prepare("SELECT * FROM pasien WHERE no_peserta = :no_peserta LIMIT 1");
        $stmt->execute(['no_peserta' => $data_rujukan['noKartu']]);
        $pasien_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($pasien_data) {
            $step = 3;
        } else {
            $no_rm_baru = generateNoRM($pdo_simrs);
            $step = 2;
        }
    } catch (Exception $e) {
        $error = "Error cek pasien: " . $e->getMessage();
    }
}

// === PROSES SIMPAN PASIEN BARU ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_pasien'])) {
    $no_rkm_medis = $_POST['no_rkm_medis'];
    $nm_pasien = strtoupper($_POST['nm_pasien']);
    $no_ktp = $_POST['no_ktp'];
    $jk = $_POST['jk'];
    $tmp_lahir = strtoupper($_POST['tmp_lahir']);
    $tgl_lahir = $_POST['tgl_lahir'];
    $nm_ibu = strtoupper($_POST['nm_ibu']);
    $alamat = strtoupper($_POST['alamat']);
    $no_tlp = $_POST['no_tlp'];
    $no_peserta = $_POST['no_peserta'];
    $pekerjaan = strtoupper($_POST['pekerjaan']);
    $agama = strtoupper($_POST['agama']);
    
    $birthDate = new DateTime($tgl_lahir);
    $today = new DateTime();
    $interval = $birthDate->diff($today);
    $umur = $interval->y . " Th " . $interval->m . " Bl " . $interval->d . " Hr";
    
    try {
        $pdo_simrs->beginTransaction();
        
        $sql = "INSERT INTO pasien (
            no_rkm_medis, nm_pasien, no_ktp, jk, tmp_lahir, tgl_lahir, nm_ibu, alamat, 
            gol_darah, pekerjaan, stts_nikah, agama, tgl_daftar, no_tlp, umur, pnd, 
            keluarga, namakeluarga, kd_pj, no_peserta, kd_kel, kd_kec, kd_kab, 
            pekerjaanpj, alamatpj, kelurahanpj, kecamatanpj, kabupatenpj, 
            perusahaan_pasien, suku_bangsa, bahasa_pasien, cacat_fisik, 
            email, nip, kd_prop, propinsipj
        ) VALUES (
            :no_rkm_medis, :nm_pasien, :no_ktp, :jk, :tmp_lahir, :tgl_lahir, :nm_ibu, :alamat,
            '-', :pekerjaan, 'MENIKAH', :agama, CURDATE(), :no_tlp, :umur, '-',
            'DIRI SENDIRI', '-', 'BPJ', :no_peserta, 1, 1, 1,
            '-', 'ALAMAT', 'KELURAHAN', 'KECAMATAN', 'KABUPATEN',
            '-', 1, 1, 1,
            '-', '-', 1, 'PROPINSI'
        )";
        
        $stmt = $pdo_simrs->prepare($sql);
        $stmt->execute([
            'no_rkm_medis' => $no_rkm_medis,
            'nm_pasien' => $nm_pasien,
            'no_ktp' => $no_ktp,
            'jk' => $jk,
            'tmp_lahir' => $tmp_lahir,
            'tgl_lahir' => $tgl_lahir,
            'nm_ibu' => $nm_ibu,
            'alamat' => $alamat,
            'pekerjaan' => $pekerjaan,
            'agama' => $agama,
            'no_tlp' => $no_tlp,
            'umur' => $umur,
            'no_peserta' => $no_peserta
        ]);
        
        $pdo_simrs->commit();
        
        $pasien_data = [
            'no_rkm_medis' => $no_rkm_medis,
            'nm_pasien' => $nm_pasien,
            'no_ktp' => $no_ktp,
            'jk' => $jk,
            'tmp_lahir' => $tmp_lahir,
            'tgl_lahir' => $tgl_lahir,
            'no_tlp' => $no_tlp,
            'no_peserta' => $no_peserta,
            'pekerjaan' => $pekerjaan,
            'agama' => $agama,
            'alamat' => $alamat
        ];
        
        $data_rujukan = [
            'noKartu' => $_POST['hidden_noKartu'],
            'noRujukan' => $_POST['hidden_noRujukan'],
            'namaPeserta' => $_POST['hidden_namaPeserta'],
            'diagAwal' => $_POST['hidden_diagAwal'],
            'poliTujuan' => $_POST['hidden_poliTujuan'],
            'hakKelas' => $_POST['hidden_hakKelas'],
            'statusPeserta' => $_POST['hidden_statusPeserta'],
            'jenisPeserta' => $_POST['hidden_jenisPeserta'],
            'noTelp' => $_POST['hidden_noTelp'],
            'nik' => $_POST['hidden_nik'] ?? '',
            'tglLahir' => $_POST['hidden_tglLahir'] ?? '',
            'jenisKelamin' => $_POST['hidden_jenisKelamin'] ?? '',
            'tglRujukan' => $_POST['hidden_tglRujukan'] ?? '',
            'kdProvPerujuk' => $_POST['hidden_kdProvPerujuk'] ?? '',
            'nmProvPerujuk' => $_POST['hidden_nmProvPerujuk'] ?? ''
        ];
        
        $success = "✅ Data pasien berhasil disimpan!";
        $step = 3;
        
    } catch (Exception $e) {
        $pdo_simrs->rollBack();
        $error = "❌ Gagal menyimpan data pasien: " . $e->getMessage();
        $step = 2;
    }
}

// === PROSES BUAT SEP ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buat_sep'])) {
    $no_rawat = $_POST['no_rawat'];
    $tglsep = $_POST['tglsep'];
    $tglrujukan = $_POST['tglrujukan'];
    $catatan = $_POST['catatan'];
    $kd_dokter = $_POST['kd_dokter'];
    $kd_poli_rs = $_POST['kd_poli_rs'];
    
    $no_rkm_medis = $_POST['no_rkm_medis'];
    $stmt = $pdo_simrs->prepare("SELECT * FROM pasien WHERE no_rkm_medis = :norm LIMIT 1");
    $stmt->execute(['norm' => $no_rkm_medis]);
    $pasien_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $data_rujukan = [
        'noKartu' => $_POST['hidden_noKartu'],
        'noRujukan' => $_POST['hidden_noRujukan'],
        'diagAwal' => $_POST['hidden_diagAwal'],
        'poliTujuan' => $_POST['hidden_poliTujuan'],
        'hakKelas' => $_POST['hidden_hakKelas'],
        'kdProvPerujuk' => $_POST['hidden_kdProvPerujuk'] ?? '',
        'nmProvPerujuk' => $_POST['hidden_nmProvPerujuk'] ?? ''
    ];
    
    $stmt = $pdo_simrs->prepare("SELECT * FROM maping_poli_bpjs WHERE kd_poli_rs = :kd_poli");
    $stmt->execute(['kd_poli' => $kd_poli_rs]);
    $poli_bpjs = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $pdo_simrs->prepare("SELECT * FROM maping_dokter_dpjpvclaim WHERE kd_dokter = :kd_dokter");
    $stmt->execute(['kd_dokter' => $kd_dokter]);
    $dokter_bpjs = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $pdo_simrs->prepare("SELECT nm_penyakit FROM penyakit WHERE kd_penyakit = :kd");
    $stmt->execute(['kd' => $data_rujukan['diagAwal']]);
    $diagnosa = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Gunakan kode PPK perujuk dari data rujukan, jika tidak ada gunakan default
    $kdppkrujukan = !empty($data_rujukan['kdPpkRujukan']) 
                ? $data_rujukan['kdPpkRujukan'] 
                : $kd_ppk;   // fallback ke kode RS

    $nmppkrujukan = !empty($data_rujukan['nmProvPerujuk']) ? $data_rujukan['nmProvPerujuk'] : 'FASKES PERUJUK';
    
$tglSepFormatted = date('Y-m-d', strtotime($tglsep));
$tglRujukanFormatted = date('Y-m-d', strtotime($tglrujukan));



    

// echo "<pre>";
// var_dump($pasien_data);
// var_dump($data_rujukan);
// var_dump($poli_bpjs);
// var_dump($dokter_bpjs);
// echo "</pre>";
// exit;


    $sepData = [
    "noKartu" => $pasien_data['no_peserta'],
    "tglSep" => $tglSepFormatted,
    "ppkPelayanan" => $kd_ppk,
    "jnsPelayanan" => "2",
    
    "klsRawat" => [
        "klsRawatHak" => $data_rujukan['hakKelas'],
        "klsRawatNaik" => "0",
        "pembiayaan" => "0",
        "penanggungJawab" => ""
    ],

    "noMR" => $pasien_data['no_rkm_medis'],

    "rujukan" => [
        "asalRujukan" => "1",
        "tglRujukan" => $tglRujukanFormatted,
        "noRujukan" => $data_rujukan['noRujukan'],
        "ppkRujukan" => $kdppkrujukan
    ],

    "catatan" => $catatan,
    "diagAwal" => $data_rujukan['diagAwal'],

    "poli" => [
        "tujuan" => $poli_bpjs['kd_poli_bpjs'],
        "eksekutif" => "0"
    ],

    "cob" => ["cob" => "0"],
    "katarak" => ["katarak" => "0"],

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
        "kodeDPJP" => $dokter_bpjs['kd_dokter_bpjs']
    ],

    "dpjpLayan" => $dokter_bpjs['kd_dokter_bpjs'],
    "noTelp" => $pasien_data['no_tlp'],

    "user" => $_SESSION['username'] ?? 'Admin'
];


// ============================================
// ENKRIPSI & KIRIM DATA KE BPJS
// ============================================

// STEP 1: Buat timestamp di timezone UTC (sesuai dokumentasi BPJS)
// Dokumentasi BPJS: "Format waktu menggunakan Coordinated Universal Time (UTC)"
date_default_timezone_set('UTC');
$tStamp = strval(time()-strtotime('1970-01-01 00:00:00'));

// STEP 2: Set endpoint URL
$url = $base_url . "/SEP/2.0/insert";

// STEP 3: Enkripsi data dengan key yang SAMA dengan timestamp
$jsonPayload = json_encode($sepData, JSON_UNESCAPED_SLASHES);
$key = $cons_id . $secret_key . $tStamp;  // Key = cons_id + secret_key + timestamp
$encrypted = stringEncrypt($key, $jsonPayload);

// STEP 4: Wrap data terenkripsi dalam parameter "request"
$postData = "request=" . urlencode($encrypted);

// STEP 5: Kirim data dengan timestamp yang SAMA
$response = postBpjsData($url, $postData, $cons_id, $secret_key, $user_key, $tStamp);


// ============================================
// DEBUG OUTPUT
// ============================================

// Hitung signature untuk ditampilkan
$signature_debug = base64_encode(hash_hmac('sha256', $cons_id . "&" . $tStamp, $secret_key, true));

echo "<pre style='background:#f5f5f5; padding:20px; border:1px solid #ddd; font-family:monospace;'>";

// ENDPOINT INFO
echo "<div style='background:#e3f2fd; padding:15px; margin:10px 0; border-left:4px solid #2196f3;'>";
echo "<strong style='font-size:16px;'>🔍 INFO DEBUG BPJS SEP</strong><br><br>";
echo "<strong>📡 ENDPOINT INFO:</strong><br>";
echo "URL: <code style='background:#fff; padding:2px 5px;'>" . htmlspecialchars($url) . "</code><br>";
echo "Base URL: <code style='background:#fff; padding:2px 5px;'>" . htmlspecialchars($base_url) . "</code><br>";
echo "Method: POST<br>";
echo "</div>\n\n";

// TIMESTAMP & CREDENTIALS
echo "<div style='background:#fff9c4; padding:15px; margin:10px 0; border-left:4px solid #fbc02d;'>";
echo "<strong>🔑 CREDENTIALS & TIMESTAMP:</strong><br>";
echo "Timezone: <code style='background:#fff; padding:2px 5px;'>UTC</code> (sesuai dokumentasi BPJS)<br>";
echo "Timestamp: <code style='background:#fff; padding:2px 5px;'>" . htmlspecialchars($tStamp) . "</code><br>";
echo "Cons ID: <code style='background:#fff; padding:2px 5px;'>" . htmlspecialchars($cons_id) . "</code><br>";
echo "Secret Key: <code style='background:#fff; padding:2px 5px;'>" . htmlspecialchars(substr($secret_key, 0, 4)) . "..." . htmlspecialchars(substr($secret_key, -4)) . "</code> (length: " . strlen($secret_key) . ")<br>";
echo "User Key: <code style='background:#fff; padding:2px 5px;'>" . htmlspecialchars(substr($user_key, 0, 10)) . "...</code><br>";
echo "</div>\n\n";

// SIGNATURE INFO
echo "<div style='background:#e1bee7; padding:15px; margin:10px 0; border-left:4px solid #9c27b0;'>";
echo "<strong>✍️ SIGNATURE INFO:</strong><br>";
echo "Message: <code style='background:#fff; padding:2px 5px;'>" . htmlspecialchars($cons_id . "&" . $tStamp) . "</code><br>";
echo "Signature: <code style='background:#fff; padding:2px 5px;'>" . htmlspecialchars(substr($signature_debug, 0, 30)) . "...</code><br>";
echo "</div>\n\n";

// PAYLOAD
echo "<div style='background:#fff3cd; padding:15px; margin:10px 0; border-left:4px solid #ffc107;'>";
echo "<strong>📤 PAYLOAD (SEBELUM ENKRIPSI):</strong><br>";
echo "<pre style='max-height:300px; overflow:auto; background:white; padding:10px; border:1px solid #ddd;'>";
echo json_encode($sepData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
echo "</pre></div>\n\n";

// ENKRIPSI INFO
echo "<div style='background:#e8f5e9; padding:15px; margin:10px 0; border-left:4px solid #4caf50;'>";
echo "<strong>🔐 INFO ENKRIPSI:</strong><br>";
echo "Key untuk enkripsi: <code style='background:#fff; padding:2px 5px;'>" . htmlspecialchars(substr($key, 0, 30)) . "...</code> (length: " . strlen($key) . ")<br>";
echo "Data JSON length: " . strlen($jsonPayload) . " bytes<br>";
echo "Data terenkripsi: <code style='background:#fff; padding:2px 5px;'>" . htmlspecialchars(substr($encrypted, 0, 50)) . "...</code> (length: " . strlen($encrypted) . ")<br>";
echo "Post data length: " . strlen($postData) . " bytes<br>";
echo "Post data sample: <code style='background:#fff; padding:2px 5px;'>" . htmlspecialchars(substr($postData, 0, 60)) . "...</code><br>";
echo "</div>\n\n";

// HEADERS YANG DIKIRIM
echo "<div style='background:#b3e5fc; padding:15px; margin:10px 0; border-left:4px solid #03a9f4;'>";
echo "<strong>📋 HEADERS YANG DIKIRIM:</strong><br>";
echo "<pre style='background:white; padding:10px; border:1px solid #ddd;'>";
echo "X-cons-id: " . htmlspecialchars($cons_id) . "\n";
echo "X-timestamp: " . htmlspecialchars($tStamp) . "\n";
echo "X-signature: " . htmlspecialchars(substr($signature_debug, 0, 40)) . "...\n";
echo "X-user-key: " . htmlspecialchars(substr($user_key, 0, 20)) . "...\n";
echo "Content-Type: application/x-www-form-urlencoded";
echo "</pre></div>\n\n";

// RESPONSE
echo "<div style='background:#f8d7da; padding:15px; margin:10px 0; border-left:4px solid #dc3545;'>";
echo "<strong>📥 RESPONSE DARI BPJS:</strong><br>";
echo "<pre style='max-height:400px; overflow:auto; background:white; padding:10px; border:1px solid #ddd;'>";
print_r($response);
echo "</pre></div>\n\n";

// ERROR ANALYSIS
if (isset($response['metaData']['code']) && $response['metaData']['code'] != '200') {
    echo "<div style='background:#ffcdd2; padding:15px; margin:10px 0; border-left:4px solid #f44336;'>";
    echo "<strong>❌ ERROR TERDETEKSI:</strong><br>";
    echo "Code: <strong>" . htmlspecialchars($response['metaData']['code']) . "</strong><br>";
    echo "Message: <strong>" . htmlspecialchars($response['metaData']['message']) . "</strong><br>";
    
    if (isset($response['raw'])) {
        echo "Raw Response: <code style='background:#fff; padding:2px 5px;'>" . htmlspecialchars($response['raw']) . "</code><br>";
    }
    
    echo "<br><strong>💡 ANALISA & SOLUSI:</strong><br>";
    
    $errorMsg = $response['metaData']['message'] ?? '';
    $rawMsg = $response['raw'] ?? '';
    
    if (strpos($rawMsg, 'Authentication parameters missing') !== false) {
        echo "<div style='background:#ffe082; padding:10px; margin:10px 0;'>";
        echo "<strong>🔍 Kemungkinan penyebab:</strong><br>";
        echo "1. Timestamp untuk enkripsi berbeda dengan timestamp untuk signature<br>";
        echo "2. Format data terenkripsi salah<br>";
        echo "3. Content-Type header salah<br><br>";
        
        echo "<strong>✅ Yang sudah benar di request ini:</strong><br>";
        echo "• Timestamp konsisten: $tStamp (dipakai untuk enkripsi & signature)<br>";
        echo "• Data terenkripsi: " . (strlen($encrypted) > 0 ? "✓ Ada" : "✗ Kosong") . "<br>";
        echo "• Content-Type: application/x-www-form-urlencoded ✓<br>";
        echo "• Header X-user-key: ✓ Benar<br><br>";
        
        echo "<strong>🔧 Coba cek:</strong><br>";
        echo "• Secret key tidak ada spasi (length: " . strlen($secret_key) . ")<br>";
        echo "• Cons ID benar: $cons_id<br>";
        echo "• Server time sync (timestamp: $tStamp)<br>";
        echo "</div>";
    } elseif (strpos($rawMsg, 'No Mapping Rule') !== false) {
        echo "<div style='background:#ffe082; padding:10px; margin:10px 0;'>";
        echo "<strong>Endpoint mungkin salah!</strong><br>";
        echo "Coba ganti ke: <code>\$url = \$base_url . '/SEP/1.1/insert';</code><br>";
        echo "</div>";
    }
    
    echo "</div>\n";
} else {
    echo "<div style='background:#c8e6c9; padding:20px; margin:10px 0; border-left:4px solid #4caf50;'>";
    echo "<h3 style='margin:0; color:#2e7d32;'>✅ SEP BERHASIL DIBUAT!</h3><br>";
    if (isset($response['response']['sep']['noSep'])) {
        echo "No. SEP: <strong style='font-size:18px;'>" . htmlspecialchars($response['response']['sep']['noSep']) . "</strong>";
    }
    echo "</div>\n";
}

echo "</pre>";
exit;

    
    if (isset($response['metaData']['code']) && $response['metaData']['code'] == '200') {
        $sep_result = $response['response']['sep'] ?? [];
        $no_sep = $sep_result['noSep'] ?? '';
        
        try {
            $pdo_simrs->beginTransaction();
            
            // Generate nomor registrasi
            $no_reg = generateNoReg($pdo_simrs, $tglsep);
            
            // Hitung umur pasien saat daftar
            $birthDate = new DateTime($pasien_data['tgl_lahir']);
            $regDate = new DateTime($tglsep);
            $interval = $birthDate->diff($regDate);
            $umurdaftar = $interval->y;
            $sttsumur = 'Th';
            
            // Cek status pasien lama/baru (cek apakah pernah daftar sebelumnya)
            $stmt = $pdo_simrs->prepare("SELECT COUNT(*) as total FROM reg_periksa WHERE no_rkm_medis = :norm");
            $stmt->execute(['norm' => $pasien_data['no_rkm_medis']]);
            $cek = $stmt->fetch(PDO::FETCH_ASSOC);
            $stts_daftar = ($cek['total'] > 0) ? 'Lama' : 'Baru';
            
            // Cek status poli lama/baru (cek apakah pernah ke poli ini sebelumnya)
            $stmt = $pdo_simrs->prepare("SELECT COUNT(*) as total FROM reg_periksa WHERE no_rkm_medis = :norm AND kd_poli = :poli");
            $stmt->execute(['norm' => $pasien_data['no_rkm_medis'], 'poli' => $kd_poli_rs]);
            $cek_poli = $stmt->fetch(PDO::FETCH_ASSOC);
            $status_poli = ($cek_poli['total'] > 0) ? 'Lama' : 'Baru';
            
            // Ambil penanggung jawab dari data pasien
            $p_jawab = $pasien_data['namakeluarga'] ?? '-';
            $almt_pj = $pasien_data['alamatpj'] ?? $pasien_data['alamat'];
            $hubunganpj = $pasien_data['keluarga'] ?? 'DIRI SENDIRI';
            
            // 1. INSERT ke tabel reg_periksa (PENDAFTARAN)
            $sql_reg = "INSERT INTO reg_periksa (
                no_reg, no_rawat, tgl_registrasi, jam_reg, kd_dokter, no_rkm_medis, kd_poli,
                p_jawab, almt_pj, hubunganpj, biaya_reg, stts, stts_daftar, status_lanjut,
                kd_pj, umurdaftar, sttsumur, status_bayar, status_poli
            ) VALUES (
                :no_reg, :no_rawat, :tgl_registrasi, :jam_reg, :kd_dokter, :no_rkm_medis, :kd_poli,
                :p_jawab, :almt_pj, :hubunganpj, 10000, 'Belum', :stts_daftar, 'Ralan',
                :kd_pj, :umurdaftar, :sttsumur, 'Belum Bayar', :status_poli
            )";
            
            $stmt_reg = $pdo_simrs->prepare($sql_reg);
            $stmt_reg->execute([
                'no_reg' => $no_reg,
                'no_rawat' => $no_rawat,
                'tgl_registrasi' => $tglsep,
                'jam_reg' => date('H:i:s'),
                'kd_dokter' => $kd_dokter,
                'no_rkm_medis' => $pasien_data['no_rkm_medis'],
                'kd_poli' => $kd_poli_rs,
                'p_jawab' => $p_jawab,
                'almt_pj' => $almt_pj,
                'hubunganpj' => $hubunganpj,
                'stts_daftar' => $stts_daftar,
                'kd_pj' => 'BPJ', // Kode penjamin BPJS
                'umurdaftar' => $umurdaftar,
                'sttsumur' => $sttsumur,
                'status_poli' => $status_poli
            ]);
            
            // 2. INSERT ke tabel bridging_sep (DATA SEP)
            $sql_sep = "INSERT INTO bridging_sep (
                no_sep, no_rawat, tglsep, tglrujukan, no_rujukan, kdppkrujukan, nmppkrujukan,
                kdppkpelayanan, nmppkpelayanan, jnspelayanan, catatan, diagawal, nmdiagnosaawal,
                kdpolitujuan, nmpolitujuan, klsrawat, klsnaik, pembiayaan, pjnaikkelas, lakalantas,
                user, nomr, nama_pasien, tanggal_lahir, peserta, jkel, no_kartu, tglpulang,
                asal_rujukan, eksekutif, cob, notelep, katarak, tglkkl, keterangankkl, suplesi,
                no_sep_suplesi, kdprop, nmprop, kdkab, nmkab, kdkec, nmkec, noskdp, kddpjp,
                nmdpdjp, tujuankunjungan, flagprosedur, penunjang, asesmenpelayanan, kddpjplayanan, nmdpjplayanan
            ) VALUES (
                :no_sep, :no_rawat, :tglsep, :tglrujukan, :no_rujukan, :kdppkrujukan, :nmppkrujukan,
                :kdppkpelayanan, :nmppkpelayanan, '2', :catatan, :diagawal, :nmdiagnosaawal,
                :kdpolitujuan, :nmpolitujuan, :klsrawat, '', '', '', '0',
                :user, :nomr, :nama_pasien, :tanggal_lahir, :peserta, :jkel, :no_kartu, NULL,
                '1. Faskes 1', '0. Tidak', '0. Tidak', :notelep, '0. Tidak', '0000-00-00', '', '0. Tidak',
                '', '', '', '', '', '', '', '', :kddpjp,
                :nmdpdjp, '0', '', '', '', :kddpjplayanan, :nmdpjplayanan
            )";
            
            $stmt_sep = $pdo_simrs->prepare($sql_sep);
            $stmt_sep->execute([
                'no_sep' => $no_sep,
                'no_rawat' => $no_rawat,
                'tglsep' => $tglsep,
                'tglrujukan' => $tglrujukan,
                'no_rujukan' => $data_rujukan['noRujukan'],
                'kdppkrujukan' => $kdppkrujukan,
                'nmppkrujukan' => $nmppkrujukan,
                'kdppkpelayanan' => $kd_ppk,
                'nmppkpelayanan' => $nm_ppk,
                'catatan' => $catatan,
                'diagawal' => $data_rujukan['diagAwal'],
                'nmdiagnosaawal' => $diagnosa['nm_penyakit'] ?? '-',
                'kdpolitujuan' => $poli_bpjs['kd_poli_bpjs'],
                'nmpolitujuan' => $poli_bpjs['nm_poli_bpjs'],
                'klsrawat' => $data_rujukan['hakKelas'],
                'user' => $_SESSION['username'] ?? 'Admin',
                'nomr' => $pasien_data['no_rkm_medis'],
                'nama_pasien' => $pasien_data['nm_pasien'],
                'tanggal_lahir' => $pasien_data['tgl_lahir'],
                'peserta' => $pasien_data['pekerjaan'],
                'jkel' => $pasien_data['jk'],
                'no_kartu' => $pasien_data['no_peserta'],
                'notelep' => $pasien_data['no_tlp'],
                'kddpjp' => $dokter_bpjs['kd_dokter_bpjs'],
                'nmdpdjp' => $dokter_bpjs['nm_dokter_bpjs'],
                'kddpjplayanan' => $dokter_bpjs['kd_dokter_bpjs'],
                'nmdpjplayanan' => $dokter_bpjs['nm_dokter_bpjs']
            ]);
            
            $pdo_simrs->commit();
            $success = "✅ SEP berhasil dibuat! No. SEP: <strong>" . $no_sep . "</strong><br>No. Registrasi: <strong>" . $no_reg . "</strong><br>No. Rawat: <strong>" . $no_rawat . "</strong>";
            $step = 4;
            
        } catch (Exception $e) {
            $pdo_simrs->rollBack();
            $error = "❌ Gagal menyimpan data: " . $e->getMessage();
        }
        
    } else {
        $error = "❌ Gagal membuat SEP: " . ($response['metaData']['message'] ?? 'Terjadi kesalahan');
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>📋 Buat SEP Rujukan FKTP</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<style>
body {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
    padding: 20px 0;
}
.container { max-width: 1000px; }
.card {
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    border: none;
}
.card-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 15px 15px 0 0 !important;
    padding: 20px;
}
.btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
}
.btn-primary:hover {
    background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
}
.form-label {
    font-weight: 600;
    color: #333;
}
.step-indicator {
    display: flex;
    justify-content: space-between;
    margin-bottom: 30px;
}
.step-item {
    flex: 1;
    text-align: center;
    padding: 15px;
    background: white;
    border-radius: 10px;
    margin: 0 5px;
    position: relative;
}
.step-item.active {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}
.step-item::after {
    content: '→';
    position: absolute;
    right: -15px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 24px;
    color: white;
}
.step-item:last-child::after {
    display: none;
}
.info-box {
    background: #f8f9fa;
    border-left: 4px solid #667eea;
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 5px;
}
</style>
</head>
<body>

<div class="container">
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-x-circle-fill"></i> <?= $error ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle-fill"></i> <?= $success ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($step < 4): ?>
    <div class="step-indicator">
        <div class="step-item <?= $step >= 1 ? 'active' : '' ?>">
            <div class="fw-bold">1. Cek Pasien</div>
        </div>
        <div class="step-item <?= $step >= 2 ? 'active' : '' ?>">
            <div class="fw-bold">2. Data Pasien</div>
        </div>
        <div class="step-item <?= $step >= 3 ? 'active' : '' ?>">
            <div class="fw-bold">3. Buat SEP</div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($step == 2): ?>
    <div class="card">
        <div class="card-header">
            <h4 class="mb-0"><i class="bi bi-person-plus-fill"></i> Input Data Pasien Baru</h4>
        </div>
        <div class="card-body">
            <div class="info-box">
                <strong><i class="bi bi-info-circle-fill"></i> Informasi:</strong><br>
                Pasien dengan No. Kartu BPJS <strong><?= htmlspecialchars($data_rujukan['noKartu']) ?></strong> belum terdaftar di sistem.<br>
                Nomor Rekam Medis baru: <strong class="text-primary"><?= $no_rm_baru ?></strong>
            </div>

            <form method="POST">
                <input type="hidden" name="simpan_pasien" value="1">
                <input type="hidden" name="no_rkm_medis" value="<?= $no_rm_baru ?>">
                <input type="hidden" name="no_peserta" value="<?= htmlspecialchars($data_rujukan['noKartu']) ?>">
                
                <input type="hidden" name="hidden_noKartu" value="<?= htmlspecialchars($data_rujukan['noKartu']) ?>">
                <input type="hidden" name="hidden_noRujukan" value="<?= htmlspecialchars($data_rujukan['noRujukan']) ?>">
                <input type="hidden" name="hidden_namaPeserta" value="<?= htmlspecialchars($data_rujukan['namaPeserta']) ?>">
                <input type="hidden" name="hidden_diagAwal" value="<?= htmlspecialchars($data_rujukan['diagAwal']) ?>">
                <input type="hidden" name="hidden_poliTujuan" value="<?= htmlspecialchars($data_rujukan['poliTujuan']) ?>">
                <input type="hidden" name="hidden_hakKelas" value="<?= htmlspecialchars($data_rujukan['hakKelas']) ?>">
                <input type="hidden" name="hidden_statusPeserta" value="<?= htmlspecialchars($data_rujukan['statusPeserta']) ?>">
                <input type="hidden" name="hidden_jenisPeserta" value="<?= htmlspecialchars($data_rujukan['jenisPeserta']) ?>">
                <input type="hidden" name="hidden_noTelp" value="<?= htmlspecialchars($data_rujukan['noTelp']) ?>">
                <input type="hidden" name="hidden_nik" value="<?= htmlspecialchars($data_rujukan['nik']) ?>">
                <input type="hidden" name="hidden_tglLahir" value="<?= htmlspecialchars($data_rujukan['tglLahir']) ?>">
                <input type="hidden" name="hidden_jenisKelamin" value="<?= htmlspecialchars($data_rujukan['jenisKelamin']) ?>">
                <input type="hidden" name="hidden_tglRujukan" value="<?= htmlspecialchars($data_rujukan['tglRujukan']) ?>">
                <input type="hidden" name="hidden_kdProvPerujuk" value="<?= htmlspecialchars($data_rujukan['kdProvPerujuk']) ?>">
                <input type="hidden" name="hidden_nmProvPerujuk" value="<?= htmlspecialchars($data_rujukan['nmProvPerujuk']) ?>">

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">No. Rekam Medis <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" value="<?= $no_rm_baru ?>" readonly>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">No. Kartu BPJS <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($data_rujukan['noKartu']) ?>" readonly>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="nm_pasien" value="<?= htmlspecialchars($data_rujukan['namaPeserta']) ?>" required readonly style="background-color: #e9ecef;">
                        <small class="text-muted">✓ Terisi otomatis dari data rujukan BPJS</small>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">NIK / No. KTP <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="no_ktp" maxlength="16" value="<?= htmlspecialchars($data_rujukan['nik']) ?>" required <?= !empty($data_rujukan['nik']) ? 'readonly style="background-color: #e9ecef;"' : '' ?>>
                        <?php if (!empty($data_rujukan['nik'])): ?>
                            <small class="text-muted">✓ Terisi otomatis dari data rujukan BPJS</small>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Jenis Kelamin <span class="text-danger">*</span></label>
                        <select class="form-select" name="jk" required <?= !empty($data_rujukan['jenisKelamin']) ? 'style="background-color: #e9ecef;"' : '' ?>>
                            <option value="">-- Pilih --</option>
                            <option value="L" <?= ($data_rujukan['jenisKelamin'] ?? '') == 'L' ? 'selected' : '' ?>>Laki-laki</option>
                            <option value="P" <?= ($data_rujukan['jenisKelamin'] ?? '') == 'P' ? 'selected' : '' ?>>Perempuan</option>
                        </select>
                        <?php if (!empty($data_rujukan['jenisKelamin'])): ?>
                            <small class="text-muted">✓ Terisi otomatis dari data rujukan BPJS</small>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Tempat Lahir <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="tmp_lahir" required placeholder="Contoh: JAMBI">
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Tanggal Lahir <span class="text-danger">*</span></label>
                        <?php
                        // Convert format tanggal dari YYYY-MM-DD ke format yang dibutuhkan
                        $tglLahirValue = '';
                        if (!empty($data_rujukan['tglLahir'])) {
                            $tglLahirValue = $data_rujukan['tglLahir'];
                        }
                        ?>
                        <input type="date" class="form-control" name="tgl_lahir" value="<?= htmlspecialchars($tglLahirValue) ?>" required <?= !empty($tglLahirValue) ? 'readonly style="background-color: #e9ecef;"' : '' ?>>
                        <?php if (!empty($tglLahirValue)): ?>
                            <small class="text-muted">✓ Terisi otomatis dari data rujukan BPJS</small>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Nama Ibu Kandung <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="nm_ibu" required placeholder="Nama lengkap ibu kandung">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Alamat Lengkap <span class="text-danger">*</span></label>
                    <textarea class="form-control" name="alamat" rows="2" required></textarea>
                </div>

                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">No. Telepon <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="no_tlp" value="<?= htmlspecialchars($data_rujukan['noTelp']) ?>" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Pekerjaan <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="pekerjaan" value="<?= htmlspecialchars($data_rujukan['jenisPeserta']) ?>" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Agama <span class="text-danger">*</span></label>
                        <select class="form-select" name="agama" required>
                            <option value="">-- Pilih --</option>
                            <option value="ISLAM">Islam</option>
                            <option value="KRISTEN">Kristen</option>
                            <option value="KATOLIK">Katolik</option>
                            <option value="HINDU">Hindu</option>
                            <option value="BUDDHA">Buddha</option>
                            <option value="KONGHUCU">Konghucu</option>
                            <option value="KEPERCAYAAN">Kepercayaan</option>
                        </select>
                    </div>
                </div>

                <div class="d-flex gap-2 justify-content-end">
                    <a href="cek_rujukan_fktp.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Kembali
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Simpan & Lanjut ke SEP
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($step == 3): ?>
    <div class="card">
        <div class="card-header">
            <h4 class="mb-0"><i class="bi bi-file-medical-fill"></i> Form Pembuatan SEP</h4>
        </div>
        <div class="card-body">
            <div class="info-box">
                <div class="row">
                    <div class="col-md-6">
                        <strong>Data Pasien:</strong><br>
                        No. RM: <strong><?= htmlspecialchars($pasien_data['no_rkm_medis']) ?></strong><br>
                        Nama: <strong><?= htmlspecialchars($pasien_data['nm_pasien']) ?></strong><br>
                        No. Kartu BPJS: <strong><?= htmlspecialchars($pasien_data['no_peserta']) ?></strong>
                    </div>
                    <div class="col-md-6">
                        <strong>Data Rujukan:</strong><br>
                        No. Rujukan: <strong><?= htmlspecialchars($data_rujukan['noRujukan']) ?></strong><br>
                        Diagnosa Awal: <strong><?= htmlspecialchars($data_rujukan['diagAwal']) ?></strong><br>
                        Hak Kelas: <strong><?= htmlspecialchars($data_rujukan['hakKelas']) ?></strong>
                    </div>
                </div>
            </div>

            <form method="POST">
                <input type="hidden" name="buat_sep" value="1">
                <input type="hidden" name="no_rkm_medis" value="<?= htmlspecialchars($pasien_data['no_rkm_medis']) ?>">
                
                <input type="hidden" name="hidden_noKartu" value="<?= htmlspecialchars($data_rujukan['noKartu']) ?>">
                <input type="hidden" name="hidden_noRujukan" value="<?= htmlspecialchars($data_rujukan['noRujukan']) ?>">
                <input type="hidden" name="hidden_diagAwal" value="<?= htmlspecialchars($data_rujukan['diagAwal']) ?>">
                <input type="hidden" name="hidden_poliTujuan" value="<?= htmlspecialchars($data_rujukan['poliTujuan']) ?>">
                <input type="hidden" name="hidden_hakKelas" value="<?= htmlspecialchars($data_rujukan['hakKelas']) ?>">

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">No. Rawat <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="no_rawat" value="<?= date('Y/m/d') ?>/000001" required>
                        <small class="text-muted">Format: YYYY/MM/DD/XXXXXX</small>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Tanggal SEP <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="tglsep" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Tanggal Rujukan <span class="text-danger">*</span></label>
                        <?php
                        // Auto-fill tanggal rujukan dari WS BPJS
                        $tglRujukanValue = date('Y-m-d'); // default hari ini
                        if (!empty($data_rujukan['tglRujukan'])) {
                            $tglRujukanValue = $data_rujukan['tglRujukan'];
                        }
                        ?>
                        <input type="date" class="form-control" name="tglrujukan" value="<?= htmlspecialchars($tglRujukanValue) ?>" required style="background-color: #e9ecef;">
                        <?php if (!empty($data_rujukan['tglRujukan'])): ?>
                            <small class="text-muted">✓ Tanggal rujukan dari WS BPJS</small>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Poli Tujuan <span class="text-danger">*</span></label>
                        <select class="form-select" name="kd_poli_rs" required>
                            <option value="">-- Pilih Poli --</option>
                            <?php
                            $stmt = $pdo_simrs->query("SELECT * FROM maping_poli_bpjs ORDER BY nm_poli_bpjs");
                            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                // Auto-select poli sesuai dengan poli rujukan dari WS BPJS
                                $selected = '';
                                if (!empty($data_rujukan['poliTujuan']) && $row['kd_poli_bpjs'] == $data_rujukan['poliTujuan']) {
                                    $selected = 'selected';
                                }
                                echo "<option value='{$row['kd_poli_rs']}' $selected>{$row['nm_poli_bpjs']}</option>";
                            }
                            ?>
                        </select>
                        <?php if (!empty($data_rujukan['poliTujuan'])): ?>
                            <small class="text-muted">✓ Poli tujuan dari rujukan: <?= htmlspecialchars($data_rujukan['poliTujuan']) ?></small>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Dokter DPJP <span class="text-danger">*</span></label>
                        <select class="form-select" name="kd_dokter" required>
                            <option value="">-- Pilih Dokter --</option>
                            <?php
                            $stmt = $pdo_simrs->query("SELECT d.kd_dokter, d.nm_dokter, m.kd_dokter_bpjs, m.nm_dokter_bpjs 
                                                  FROM dokter d 
                                                  LEFT JOIN maping_dokter_dpjpvclaim m ON d.kd_dokter = m.kd_dokter 
                                                  WHERE m.kd_dokter_bpjs IS NOT NULL 
                                                  ORDER BY d.nm_dokter");
                            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                echo "<option value='{$row['kd_dokter']}'>{$row['nm_dokter']}</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Catatan</label>
                        <input type="text" class="form-control" name="catatan" value="-">
                    </div>
                </div>

                <div class="d-flex gap-2 justify-content-end">
                    <a href="cek_rujukan_fktp.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Kembali
                    </a>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-circle"></i> Buat SEP
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($step == 4): ?>
    <div class="card">
        <div class="card-header bg-success text-white">
            <h4 class="mb-0"><i class="bi bi-check-circle-fill"></i> SEP Berhasil Dibuat!</h4>
        </div>
        <div class="card-body text-center">
            <div class="alert alert-success">
                <h3><i class="bi bi-file-earmark-check-fill"></i></h3>
                <h4><?= $success ?></h4>
                <p class="mb-0">SEP telah tersimpan di database dan siap untuk digunakan.</p>
            </div>
            
            <div class="d-flex gap-2 justify-content-center mt-4">
                <a href="cek_rujukan_fktp.php" class="btn btn-primary">
                    <i class="bi bi-arrow-left"></i> Kembali ke Cek Rujukan
                </a>
                <a href="anjungan.php" class="btn btn-secondary">
                    <i class="bi bi-house-door"></i> Ke Halaman Utama
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

</body>
</html>