<?php
session_start();
include 'koneksi.php'; // koneksi setting VClaim
date_default_timezone_set('Asia/Jakarta');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Akses tidak diperbolehkan!");
}

// Ambil data dari form
$input = $_POST;

// Ambil setting VClaim
$stmt = $pdo->query("SELECT * FROM setting_vclaim LIMIT 1");
$setting = $stmt->fetch(PDO::FETCH_ASSOC);
$cons_id    = trim($setting['cons_id']);
$secret_key = trim($setting['secret_key']);
$user_key   = trim($setting['user_key']);
$base_url   = trim($setting['base_url']);

// === Fungsi Trustmark ===
function stringEncrypt($key, $string){
    $encrypt_method = 'AES-256-CBC';
    $key_hash = hex2bin(hash('sha256', $key));
    $iv = substr($key_hash,0,16);
    return base64_encode(openssl_encrypt($string,$encrypt_method,$key_hash,OPENSSL_RAW_DATA,$iv));
}

function getBpjsDataPost($endpoint, $cons_id, $secret_key, $user_key, $data){
    date_default_timezone_set('UTC');
    $tStamp = strval(time());
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
        CURLOPT_POSTFIELDS => http_build_query(['request'=>json_encode($data)]),
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error_msg = curl_error($ch);
    curl_close($ch);

    if ($errno) return ['metaData'=>['code'=>500,'message'=>"Curl error: $error_msg"]];
    return json_decode($response,true);
}

// === Susun JSON SEP 2.0 ===
$sepData = [
    "t_sep" => [
        "noKartu" => $input['noKartu'],
        "tglSep" => $input['tglSep'],
        "ppkPelayanan" => $setting['kd_faskes'], // bisa diset default di setting
        "jnsPelayanan" => $input['jnsPelayanan'],
        "klsRawat" => [
            "klsRawatHak" => $input['klsRawatHak'],
            "klsRawatNaik" => $input['klsRawatNaik'] ?? "",
            "pembiayaan" => $input['pembiayaan'] ?? "",
            "penanggungJawab" => $input['penanggungJawab'] ?? ""
        ],
        "noMR" => $input['noMR'],
        "rujukan" => [
            "asalRujukan" => "2",
            "tglRujukan" => $input['tglRujukan'],
            "noRujukan" => $input['noRujukan'],
            "ppkRujukan" => $setting['kd_faskes']
        ],
        "catatan" => $input['catatan'],
        "diagAwal" => $input['diagAwal'],
        "poli" => [
            "tujuan" => $input['poliTujuan'],
            "eksekutif" => $input['poliEksekutif'] ?? "0"
        ],
        "cob" => ["cob" => $input['cob'] ?? "0"],
        "katarak" => ["katarak" => $input['katarak'] ?? "0"],
        "jaminan" => [
            "lakaLantas" => $input['lakaLantas'] ?? "0",
            "noLP" => $input['noLP'] ?? "",
            "penjamin" => [
                "tglKejadian" => $input['tglKejadian'] ?? "",
                "keterangan" => $input['keterangan'] ?? "",
                "suplesi" => [
                    "suplesi" => $input['suplesi'] ?? "0",
                    "noSepSuplesi" => $input['noSepSuplesi'] ?? "",
                    "lokasiLaka" => [
                        "kdPropinsi" => $input['kdPropinsi'] ?? "",
                        "kdKabupaten" => $input['kdKabupaten'] ?? "",
                        "kdKecamatan" => $input['kdKecamatan'] ?? ""
                    ]
                ]
            ]
        ],
        "tujuanKunj" => $input['tujuanKunj'] ?? "0",
        "flagProcedure" => $input['flagProcedure'] ?? "",
        "kdPenunjang" => $input['kdPenunjang'] ?? "",
        "assesmentPel" => $input['assesmentPel'] ?? "",
        "skdp" => [
            "noSurat" => $input['noSurat'] ?? "",
            "kodeDPJP" => $input['kodeDPJP'] ?? ""
        ],
        "dpjpLayan" => $input['dpjpLayan'] ?? "",
        "noTelp" => $input['noTelp'] ?? "",
        "user" => $input['user'] ?? ""
    ]
];

// POST ke BPJS
$endpoint = $base_url . "/SEP/2.0/insert";
$response = getBpjsDataPost($endpoint, $cons_id, $secret_key, $user_key, $sepData);
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Hasil SEP</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-4">
<h4>📋 Hasil Pembuatan SEP</h4>

<?php if (isset($response['metaData']['code']) && $response['metaData']['code']==200): ?>
    <div class="alert alert-success">
        ✅ SEP Berhasil dibuat!<br>
        Nomor SEP: <?= $response['response']['sep']['noSep'] ?? '-' ?><br>
        Tanggal SEP: <?= $response['response']['sep']['tglSep'] ?? '-' ?>
    </div>
<?php else: ?>
    <div class="alert alert-danger">
        ❌ Gagal membuat SEP<br>
        Pesan: <?= $response['metaData']['message'] ?? 'Tidak diketahui' ?>
    </div>
<?php endif; ?>

<a href="form_sep.php" class="btn btn-secondary">⬅ Kembali</a>
</div>
</body>
</html>
