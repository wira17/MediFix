<?php
date_default_timezone_set('Asia/Jakarta');
include 'koneksi.php';
use LZCompressor\LZString;
require_once __DIR__ . '/vendor/autoload.php';

/* ============================================================
   1. SETTING ANTROL
   ============================================================ */
try {
    $stmt = $pdo->query("SELECT * FROM setting_antrol LIMIT 1");
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$config) die("Setting Antrol belum diisi!");
} catch (Exception $e) {
    die("Error DB: " . $e->getMessage());
}

$cons_id    = $config['cons_id'];
$secret_key = $config['secret_key'];
$user_key   = $config['user_key'];
$base_url   = rtrim($config['base_url'], '/');

/* ============================================================
   2. PARAMETER FILTER
   ============================================================ */
$bulan   = $_GET['bulan'] ?? date('m');
$tahun   = $_GET['tahun'] ?? date('Y');
$waktu   = $_GET['waktu'] ?? 'rs';

/* ============================================================
   3. TIMESTAMP & SIGNATURE
   ============================================================ */
$tStamp = strval(time());
$signature = base64_encode(
    hash_hmac('sha256', $cons_id . "&" . $tStamp, $secret_key, true)
);

/* ============================================================
   4. HEADER
   ============================================================ */
$headers = [
    "x-cons-id: $cons_id",
    "x-timestamp: $tStamp",
    "x-signature: $signature",
    "user_key: $user_key",
    "Accept: application/json"
];

/* ============================================================
   5. URL ENDPOINT
   ============================================================ */
$url = $base_url . "/dashboard/waktutunggu/bulan/".urlencode($bulan)
     ."/tahun/".urlencode($tahun)
     ."/waktu/".urlencode($waktu);

/* ============================================================
   6. CURL REQUEST
   ============================================================ */
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);

/* ============================================================
   7. DEKRIPSI + DEKOMPRESI
   ============================================================ */
function stringDecrypt($key, $string){
    $key_hash = hex2bin(hash('sha256', $key));
    $iv = substr($key_hash, 0, 16);
    return openssl_decrypt(base64_decode($string), 'AES-256-CBC', $key_hash, OPENSSL_RAW_DATA, $iv);
}

/* ============================================================
   8. KONVERSI WAKTU KE MENIT
   ============================================================ */
function toMinutes($value){
    if(!is_numeric($value)) return '-';
    return round($value/60, 2); // konversi detik ke menit
}

$finalData = [];
try {
    if(isset($data["response"]["list"])) {
        $finalData = $data["response"]["list"];
    } else {
        $keyDecrypt = $cons_id . $secret_key . $tStamp;
        $decrypted = stringDecrypt($keyDecrypt, $data["response"]);
        $decompressed = LZCompressor\LZString::decompressFromEncodedURIComponent($decrypted);
        $finalData = json_decode($decompressed, true);
    }
} catch(Exception $e){
    die("Error decrypt/decompress: ".$e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard Waktu Tunggu Per Bulan</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background: #f8f9fa; font-family: 'Arial', sans-serif; font-size: 14px; }
.container { margin-top: 30px; }
h2 { margin-bottom: 20px; color: #ff6600; font-weight: bold; text-align: center; }
.table-responsive { box-shadow: 0 0 15px rgba(0,0,0,0.1); border-radius: 8px; overflow: hidden; }
table { background: #fff; border-collapse: separate; border-spacing: 0; }
thead th { 
    background: linear-gradient(90deg, #ff6600, #ff0000, #ffff00, #0066ff);
    color: #fff;
    text-align: center;
    vertical-align: middle;
    font-size: 13px;
    border: 1px solid #ddd;
}
tbody td { text-align: center; vertical-align: middle; font-size: 13px; }
tbody tr:nth-child(even) { background: #f9f9f9; }
tbody tr:hover { background: #ffe5b4; }
.form-inline { margin-bottom: 20px; display:flex; gap:10px; flex-wrap:wrap; justify-content:center; }
.btn-orange { background-color:#ff6600;color:#fff;border:none; }
.btn-orange:hover { background-color:#e65c00; }
input[type=text], input[type=number], select { padding:5px 10px; border-radius:5px; border:1px solid #ccc; }
</style>
</head>
<body>
<div class="container">
<h2>Dashboard Waktu Tunggu Per Bulan</h2>

<form method="get" class="form-inline">
    <select name="bulan" required>
        <?php for($m=1;$m<=12;$m++):
            $sel = ($bulan==sprintf("%02d",$m))?'selected':'';
        ?>
        <option value="<?php echo sprintf("%02d",$m); ?>" <?php echo $sel; ?>><?php echo date('F', mktime(0,0,0,$m,1)); ?></option>
        <?php endfor; ?>
    </select>
    <input type="number" name="tahun" value="<?php echo htmlspecialchars($tahun); ?>" required min="2000" max="2100">
    <select name="waktu" required>
        <option value="rs" <?php echo ($waktu=='rs')?'selected':''; ?>>RS</option>
        <option value="server" <?php echo ($waktu=='server')?'selected':''; ?>>Server</option>
    </select>
    <button type="submit" class="btn btn-orange">Tampilkan</button>
</form>

<?php if(!empty($finalData)): ?>
<div class="table-responsive">
<table class="table table-bordered table-hover">
<thead>
<tr>
    <th>No</th>
    <th>PPK</th>
    <th>Nama PPK</th>
    <th>Kode Poli</th>
    <th>Nama Poli</th>
    <th>Jumlah Antrean</th>
    <th>Waktu Task1 (Menit)</th>
    <th>Avg Waktu Task1 (Menit)</th>
    <th>Waktu Task2 (Menit)</th>
    <th>Avg Waktu Task2 (Menit)</th>
    <th>Waktu Task3 (Menit)</th>
    <th>Avg Waktu Task3 (Menit)</th>
    <th>Waktu Task4 (Menit)</th>
    <th>Avg Waktu Task4 (Menit)</th>
    <th>Waktu Task5 (Menit)</th>
    <th>Avg Waktu Task5 (Menit)</th>
    <th>Waktu Task6 (Menit)</th>
    <th>Avg Waktu Task6 (Menit)</th>
</tr>
</thead>
<tbody>
<?php foreach($finalData as $i=>$row): ?>
<tr>
    <td><?php echo $i+1; ?></td>
    <td><?php echo $row['kdppk'] ?? '-'; ?></td>
    <td><?php echo $row['nmppk'] ?? '-'; ?></td>
    <td><?php echo $row['kodepoli'] ?? '-'; ?></td>
    <td><?php echo $row['namapoli'] ?? '-'; ?></td>
    <td><?php echo $row['jumlah_antrean'] ?? '-'; ?></td>
    <td><?php echo toMinutes($row['waktu_task1'] ?? 0); ?></td>
    <td><?php echo toMinutes($row['avg_waktu_task1'] ?? 0); ?></td>
    <td><?php echo toMinutes($row['waktu_task2'] ?? 0); ?></td>
    <td><?php echo toMinutes($row['avg_waktu_task2'] ?? 0); ?></td>
    <td><?php echo toMinutes($row['waktu_task3'] ?? 0); ?></td>
    <td><?php echo toMinutes($row['avg_waktu_task3'] ?? 0); ?></td>
    <td><?php echo toMinutes($row['waktu_task4'] ?? 0); ?></td>
    <td><?php echo toMinutes($row['avg_waktu_task4'] ?? 0); ?></td>
    <td><?php echo toMinutes($row['waktu_task5'] ?? 0); ?></td>
    <td><?php echo toMinutes($row['avg_waktu_task5'] ?? 0); ?></td>
    <td><?php echo toMinutes($row['waktu_task6'] ?? 0); ?></td>
    <td><?php echo toMinutes($row['avg_waktu_task6'] ?? 0); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else: ?>
<div class="alert alert-warning text-center">Tidak ada data untuk filter ini.</div>
<?php endif; ?>
</div>
</body>
</html>
