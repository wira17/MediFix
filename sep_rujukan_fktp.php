<?php
session_start();
include 'koneksi.php';  // gunakan koneksi utama karena setting_vclaim ada di sini
include 'koneksi2.php'; // koneksi SIMRS / database pasien
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
    } else {
        die("❌ Data setting VClaim belum diset. Silakan isi dulu di halaman pengaturan.");
    }
} catch (Exception $e) {
    die("Gagal mengambil data setting VClaim: " . $e->getMessage());
}

$debug = true;

// === FUNGSI ===
function stringDecrypt($key, $string) {
    $encrypt_method = 'AES-256-CBC';
    $key_hash = hex2bin(hash('sha256', $key));
    $iv = substr($key_hash, 0, 16);
    return openssl_decrypt(base64_decode($string), $encrypt_method, $key_hash, OPENSSL_RAW_DATA, $iv);
}
function decompress($string) {
    return LZString::decompressFromEncodedURIComponent($string);
}
function getBpjsData($endpoint, $cons_id, $secret_key, $user_key, $debug = false) {
    date_default_timezone_set('UTC');
    $tStamp = strval(time());
    $signature = base64_encode(hash_hmac('sha256', $cons_id . "&" . $tStamp, $secret_key, true));

    $headers = [
        "X-cons-id: $cons_id",
        "X-timestamp: $tStamp",
        "X-signature: $signature",
        "user_key: $user_key",
        "Content-Type: application/json"
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30
    ]);
    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error_msg = curl_error($ch);
    curl_close($ch);

    if ($errno) return ['metaData'=>['code'=>500,'message'=>"Curl error: $error_msg"]];

    $json = json_decode($response, true);
    if (isset($json['response']) && !empty($json['response'])) {
        $key = $cons_id . $secret_key . $tStamp;
        $decrypt = stringDecrypt($key, $json['response']);
        $decompressed = decompress($decrypt);
        $json['response'] = json_decode($decompressed, true);
    }
    return $json;
}

// === PROSES ===
$result = [];
$error  = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['no_rujukan'])) {
    $no_rujukan = trim($_POST['no_rujukan']);
    if ($no_rujukan === '') {
        $error = "Nomor rujukan tidak boleh kosong!";
    } else {
        $url = $base_url . "/Rujukan/" . $no_rujukan;
        $response = getBpjsData($url, $cons_id, $secret_key, $user_key, $debug);

        if (isset($response['metaData']['code']) && $response['metaData']['code'] == '200') {
            $result = $response['response']['rujukan'] ?? [];
        } else {
            $error = $response['metaData']['message'] ?? 'Rujukan tidak ditemukan.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>🏥 Cek Rujukan FKTP (PCARE)</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/simple-keyboard@latest/build/simple-keyboard.umd.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/simple-keyboard@latest/build/css/index.css">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<style>
body {
    background: linear-gradient(135deg, #ff9900, #ff3300, #007bff, #ffcc00);
    background-size: 400% 400%;
    animation: gradientMove 10s ease infinite;
    color: #333;
}
@keyframes gradientMove {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}
.container { max-width: 600px; margin-top: 5%; }
.card { border-radius: 20px; }
#keyboard { display: none; margin-top: 15px; }
</style>
</head>
<body>
<div class="container text-center">
    <h4 class="mb-4 text-white">🏥 Cetak SEP Mandiri (Rujukan FKTP)</h4>

    <form method="POST" class="card p-4 shadow-lg bg-white">
        <div class="mb-3">
            <label class="form-label fw-semibold text-primary">Masukkan Nomor Rujukan</label>
            <input type="text" id="no_rujukan" name="no_rujukan" class="form-control form-control-lg text-center" placeholder="Contoh: 030107010217Y001465" required>
        </div>
        <div class="d-flex justify-content-center gap-3 mb-3">
            <button type="button" id="toggleKeyboard" class="btn btn-warning btn-lg">⌨️ Keyboard</button>
            <button type="submit" class="btn btn-primary btn-lg">🔍 Cek Rujukan</button>
            <a href="anjungan.php" class="btn btn-secondary btn-lg">⬅️ Kembali</a>
        </div>
        <div id="keyboard"></div>
    </form>
</div>

<!-- Modal Hasil -->
<div class="modal fade" id="hasilModal" tabindex="-1" aria-labelledby="hasilModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="hasilModalLabel">📋 Hasil Rujukan</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php elseif (!empty($result)): ?>
            <table class="table table-bordered">
                <tr><th>No. Rujukan</th><td><?= htmlspecialchars($result['noKunjungan'] ?? '-') ?></td></tr>
                <tr><th>Tanggal Kunjungan</th><td><?= htmlspecialchars($result['tglKunjungan'] ?? '-') ?></td></tr>
                <tr><th>Diagnosa</th><td><?= htmlspecialchars($result['diagnosa']['nama'] ?? '-') ?> (<?= htmlspecialchars($result['diagnosa']['kode'] ?? '-') ?>)</td></tr>
                <tr><th>Keluhan</th><td><?= htmlspecialchars($result['keluhan'] ?? '-') ?></td></tr>
                <tr><th>Poli Rujukan</th><td><?= htmlspecialchars($result['poliRujukan']['nama'] ?? '-') ?></td></tr>
                <tr><th>Faskes Perujuk</th><td><?= htmlspecialchars($result['provPerujuk']['nama'] ?? '-') ?></td></tr>
                <tr><th>Peserta</th><td><?= htmlspecialchars($result['peserta']['nama'] ?? '-') ?> (<?= htmlspecialchars($result['peserta']['noKartu'] ?? '-') ?>)</td></tr>
                <tr><th>Status Peserta</th><td><?= htmlspecialchars($result['peserta']['statusPeserta']['keterangan'] ?? '-') ?></td></tr>
                <tr><th>Jenis Peserta</th><td><?= htmlspecialchars($result['peserta']['jenisPeserta']['keterangan'] ?? '-') ?></td></tr>
                <tr><th>Hak Kelas</th><td><?= htmlspecialchars($result['peserta']['hakKelas']['keterangan'] ?? '-') ?></td></tr>
            </table>
        <?php else: ?>
            <div class="alert alert-warning">Data tidak ditemukan.</div>
        <?php endif; ?>
      </div>
      <div class="modal-footer d-flex justify-content-center gap-3">
      <?php if (!empty($result)): ?>
<form action="buat_sep_rujukan_fktp.php" method="POST" target="_blank">
    <input type="hidden" name="noKartu" value="<?= htmlspecialchars($result['peserta']['noKartu'] ?? '') ?>">
    <input type="hidden" name="noRujukan" value="<?= htmlspecialchars($result['noKunjungan'] ?? '') ?>">
    <input type="hidden" name="namaPeserta" value="<?= htmlspecialchars($result['peserta']['nama'] ?? '') ?>">
    <input type="hidden" name="diagAwal" value="<?= htmlspecialchars($result['diagnosa']['kode'] ?? '') ?>">
    <input type="hidden" name="poliTujuan" value="<?= htmlspecialchars($result['poliRujukan']['kode'] ?? '') ?>">
    <input type="hidden" name="hakKelas" value="<?= htmlspecialchars($result['peserta']['hakKelas']['kode'] ?? '') ?>">
    <input type="hidden" name="statusPeserta" value="<?= htmlspecialchars($result['peserta']['statusPeserta']['keterangan'] ?? '') ?>">
    <input type="hidden" name="jenisPeserta" value="<?= htmlspecialchars($result['peserta']['jenisPeserta']['keterangan'] ?? '') ?>">
    <input type="hidden" name="noTelp" value="<?= htmlspecialchars($result['peserta']['mr']['noTelepon'] ?? '') ?>">
    <button type="submit" class="btn btn-success btn-lg">🧾 Buat SEP</button>
</form>
<?php endif; ?>

        <button type="button" class="btn btn-danger btn-lg" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>

<?php if (!empty($result) || $error): ?>
<script>
var hasilModal = new bootstrap.Modal(document.getElementById('hasilModal'));
hasilModal.show();
</script>
<?php endif; ?>

<script>
document.getElementById('toggleKeyboard').addEventListener('click', function() {
    const container = document.getElementById('keyboard');
    container.style.display = (container.style.display === 'none' || container.style.display === '') ? 'block' : 'none';
    if (container.style.display === 'block' && !window.keyboard) {
        window.keyboard = new SimpleKeyboard.default({
            onChange: input => document.getElementById('no_rujukan').value = input,
            layout: { default: [
                '1 2 3 4 5 6 7 8 9 0',
                'Q W E R T Y U I O P',
                'A S D F G H J K L',
                'Z X C V B N M',
                '{bksp}'
            ] }
        });
    }
});
</script>
</body>
</html>
