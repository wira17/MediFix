<?php
session_start();
include 'koneksi.php';
include 'koneksi2.php';
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

function getBpjsData($endpoint, $cons_id, $secret_key, $user_key) {
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
        $response = getBpjsData($url, $cons_id, $secret_key, $user_key);

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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<style>
body {
    background: linear-gradient(135deg, #ff9900, #ff3300, #007bff, #ffcc00);
    background-size: 400% 400%;
    animation: gradientMove 10s ease infinite;
    color: #333;
    min-height: 100vh;
    overflow: hidden;
}
@keyframes gradientMove {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}
.container { max-width: 900px; margin-top: 1%; padding: 0 15px; }
.card { border-radius: 15px; }

.flow-chart {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 100px;
    margin-bottom: 15px;
    flex-wrap: wrap;
}
.flow-step {
    background: white;
    border-radius: 10px;
    padding: 10px 15px;
    text-align: center;
    box-shadow: 0 3px 10px rgba(0,0,0,0.1);
    min-width: 140px;
    transition: transform 0.3s ease;
}
.flow-step:hover { transform: translateY(-3px); }
.flow-step.active {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}
.flow-icon { font-size: 2rem; margin-bottom: 5px; }
.flow-arrow {
    font-size: 1.5rem;
    color: white;
    text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
}
.flow-title { font-weight: bold; font-size: 0.75rem; margin-top: 3px; }

.virtual-keyboard {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 12px;
    margin-top: 12px;
    box-shadow: 0 3px 10px rgba(0,0,0,0.1);
}
.keyboard-row {
    display: flex;
    justify-content: center;
    gap: 5px;
    margin-bottom: 6px;
}
.key-btn {
    min-width: 42px;
    height: 42px;
    font-size: 16px;
    font-weight: bold;
    border: 2px solid #dee2e6;
    border-radius: 6px;
    background: white;
    cursor: pointer;
    transition: all 0.2s ease;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.key-btn:hover {
    background: #e9ecef;
    transform: translateY(-2px);
    box-shadow: 0 3px 6px rgba(0,0,0,0.15);
}
.key-btn:active {
    transform: translateY(0);
    box-shadow: 0 1px 3px rgba(0,0,0,0.2);
}
.key-btn.space { min-width: 250px; }
.key-btn.backspace {
    min-width: 90px;
    background: #dc3545;
    color: white;
    border-color: #dc3545;
    font-size: 14px;
}
.key-btn.backspace:hover { background: #c82333; }

/* Phone Input Keyboard */
.phone-keyboard {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
    margin-top: 15px;
}
.phone-key {
    padding: 20px;
    font-size: 24px;
    font-weight: bold;
    border: 2px solid #007bff;
    border-radius: 10px;
    background: white;
    cursor: pointer;
    transition: all 0.2s ease;
}
.phone-key:hover {
    background: #007bff;
    color: white;
    transform: scale(1.05);
}
.phone-key.backspace-phone {
    background: #dc3545;
    color: white;
    border-color: #dc3545;
    font-size: 18px;
}
.phone-key.backspace-phone:hover {
    background: #c82333;
}
</style>
</head>
<body>
<br>
<div class="container">
    <h4 class="mb-2 text-white text-center" style="font-size: 1.3rem;">🏥 Cetak SEP Mandiri (Rujukan FKTP)</h4>

    <div class="flow-chart">
        <div class="flow-step active">
            <div class="flow-icon">📋</div>
            <div class="flow-title">1. Cek Rujukan</div>
        </div>
        <div class="flow-arrow">➜</div>
        <div class="flow-step">
            <div class="flow-icon">👆</div>
            <div class="flow-title">2. Frista/Finger</div>
        </div>
        <div class="flow-arrow">➜</div>
        <div class="flow-step">
            <div class="flow-icon">🖨️</div>
            <div class="flow-title">3. Cetak SEP</div>
        </div>
    </div>
    
    <form method="POST" class="card p-3 shadow-lg bg-white">
        <div class="mb-2">
            <label class="form-label fw-semibold text-primary" style="font-size: 1rem;">
                <i class="bi bi-123"></i> Masukkan Nomor Rujukan
            </label>
            <input type="text" id="no_rujukan" name="no_rujukan" 
                   class="form-control form-control-lg text-center" 
                   placeholder="Contoh: 030107010217Y001465" 
                   required 
                   autocomplete="off"
                   style="font-size: 1.1rem; padding: 0.5rem;">
        </div>

        <div class="virtual-keyboard">
            <div class="keyboard-row">
                <button type="button" class="key-btn" onclick="addChar('1')">1</button>
                <button type="button" class="key-btn" onclick="addChar('2')">2</button>
                <button type="button" class="key-btn" onclick="addChar('3')">3</button>
                <button type="button" class="key-btn" onclick="addChar('4')">4</button>
                <button type="button" class="key-btn" onclick="addChar('5')">5</button>
                <button type="button" class="key-btn" onclick="addChar('6')">6</button>
                <button type="button" class="key-btn" onclick="addChar('7')">7</button>
                <button type="button" class="key-btn" onclick="addChar('8')">8</button>
                <button type="button" class="key-btn" onclick="addChar('9')">9</button>
                <button type="button" class="key-btn" onclick="addChar('0')">0</button>
            </div>
            <div class="keyboard-row">
                <button type="button" class="key-btn" onclick="addChar('Q')">Q</button>
                <button type="button" class="key-btn" onclick="addChar('W')">W</button>
                <button type="button" class="key-btn" onclick="addChar('E')">E</button>
                <button type="button" class="key-btn" onclick="addChar('R')">R</button>
                <button type="button" class="key-btn" onclick="addChar('T')">T</button>
                <button type="button" class="key-btn" onclick="addChar('Y')">Y</button>
                <button type="button" class="key-btn" onclick="addChar('U')">U</button>
                <button type="button" class="key-btn" onclick="addChar('I')">I</button>
                <button type="button" class="key-btn" onclick="addChar('O')">O</button>
                <button type="button" class="key-btn" onclick="addChar('P')">P</button>
            </div>
            <div class="keyboard-row">
                <button type="button" class="key-btn" onclick="addChar('A')">A</button>
                <button type="button" class="key-btn" onclick="addChar('S')">S</button>
                <button type="button" class="key-btn" onclick="addChar('D')">D</button>
                <button type="button" class="key-btn" onclick="addChar('F')">F</button>
                <button type="button" class="key-btn" onclick="addChar('G')">G</button>
                <button type="button" class="key-btn" onclick="addChar('H')">H</button>
                <button type="button" class="key-btn" onclick="addChar('J')">J</button>
                <button type="button" class="key-btn" onclick="addChar('K')">K</button>
                <button type="button" class="key-btn" onclick="addChar('L')">L</button>
            </div>
            <div class="keyboard-row">
                <button type="button" class="key-btn" onclick="addChar('Z')">Z</button>
                <button type="button" class="key-btn" onclick="addChar('X')">X</button>
                <button type="button" class="key-btn" onclick="addChar('C')">C</button>
                <button type="button" class="key-btn" onclick="addChar('V')">V</button>
                <button type="button" class="key-btn" onclick="addChar('B')">B</button>
                <button type="button" class="key-btn" onclick="addChar('N')">N</button>
                <button type="button" class="key-btn" onclick="addChar('M')">M</button>
            </div>
            <div class="keyboard-row">
                <button type="button" class="key-btn backspace" onclick="backspace()">⌫ Hapus</button>
                <button type="button" class="key-btn space" onclick="addChar(' ')">Spasi</button>
            </div>
        </div>

        <div class="d-flex justify-content-center gap-2 mt-3">
            <button type="submit" class="btn btn-primary btn-lg px-4" style="font-size: 1rem;">
                <i class="bi bi-search"></i> Cek Rujukan
            </button>
            <a href="anjungan.php" class="btn btn-secondary btn-lg px-4" style="font-size: 1rem;">
                <i class="bi bi-arrow-left"></i> Kembali
            </a>
        </div>
    </form>
</div>

<!-- Modal Hasil -->
<div class="modal fade" id="hasilModal" tabindex="-1" aria-labelledby="hasilModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header bg-gradient-primary text-white rounded-top-4 px-4 py-3">
        <div class="d-flex align-items-center gap-2">
          <span class="fs-3"><i class="bi bi-file-earmark-medical"></i></span>
          <h5 class="modal-title mb-0" id="hasilModalLabel">📋 Hasil Rujukan BPJS</h5>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body bg-light px-4 py-3" style="max-height: 70vh; overflow-y: auto;">
        <?php if ($error): ?>
          <div class="alert alert-danger border-0 shadow-sm rounded-3"><?= htmlspecialchars($error) ?></div>
        <?php elseif (!empty($result)): ?>
          <div class="card border-0 shadow-sm rounded-3 mb-4">
            <div class="card-body px-4 py-3 bg-white">
              <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                  <tbody>
                    <tr class="table-primary">
                      <th width="30%">No Rujukan</th>
                      <td><?= htmlspecialchars($result['noKunjungan'] ?? '-') ?></td>
                    </tr>
                    <tr>
                      <th>Tgl Kunjungan</th>
                      <td><?= htmlspecialchars($result['tglKunjungan'] ?? '-') ?></td>
                    </tr>
                    <tr>
                      <th>Pelayanan</th>
                      <td><?= htmlspecialchars($result['pelayanan']['nama'] ?? '-') ?> (<?= htmlspecialchars($result['pelayanan']['kode'] ?? '-') ?>)</td>
                    </tr>
                    <tr>
                      <th>Diagnosa</th>
                      <td><?= htmlspecialchars($result['diagnosa']['nama'] ?? '-') ?> (<?= htmlspecialchars($result['diagnosa']['kode'] ?? '-') ?>)</td>
                    </tr>
                    <tr>
                      <th>Poli Rujukan</th>
                      <td><?= htmlspecialchars($result['poliRujukan']['nama'] ?? '-') ?> (<?= htmlspecialchars($result['poliRujukan']['kode'] ?? '-') ?>)</td>
                    </tr>
                    <tr>
                      <th>Faskes Perujuk</th>
                      <td><?= htmlspecialchars($result['provPerujuk']['nama'] ?? '-') ?> (<?= htmlspecialchars($result['provPerujuk']['kode'] ?? '-') ?>)</td>
                    </tr>
                    <tr class="table-info">
                      <th>Peserta</th>
                      <td>
                        <strong>Nama:</strong> <?= htmlspecialchars($result['peserta']['nama'] ?? '-') ?><br>
                        <strong>No Kartu:</strong> <?= htmlspecialchars($result['peserta']['noKartu'] ?? '-') ?><br>
                        <strong>NIK:</strong> <?= htmlspecialchars($result['peserta']['nik'] ?? '-') ?><br>
                        <strong>Hak Kelas:</strong> <?= htmlspecialchars($result['peserta']['hakKelas']['keterangan'] ?? '-') ?>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        <?php else: ?>
          <div class="alert alert-warning border-0 shadow-sm rounded-3">Data tidak ditemukan.</div>
        <?php endif; ?>
      </div>
      <div class="modal-footer bg-white d-flex justify-content-center gap-3 rounded-bottom-4 px-4 py-3">
        <?php if (!empty($result)): ?>
          <button type="button" class="btn btn-success btn-lg px-4 shadow-sm" onclick="showPhoneModal()">
            <i class="bi bi-file-plus"></i> Lanjut Buat SEP
          </button>
        <?php endif; ?>
        <button type="button" class="btn btn-danger btn-lg px-4 shadow-sm" data-bs-dismiss="modal">
          <i class="bi bi-x-circle"></i> Tutup
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal Input Nomor Telepon -->
<div class="modal fade" id="phoneModal" tabindex="-1" aria-labelledby="phoneModalLabel" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header bg-success text-white rounded-top-4">
        <h5 class="modal-title" id="phoneModalLabel"><i class="bi bi-telephone"></i> Masukkan Nomor Telepon</h5>
      </div>
      <div class="modal-body text-center p-4">
        <p class="text-muted mb-3">Nomor telepon diperlukan untuk pembuatan SEP</p>
        <input type="text" id="phoneInput" class="form-control form-control-lg text-center mb-3" 
               placeholder="Contoh: 081234567890" 
               maxlength="15" 
               style="font-size: 24px; letter-spacing: 2px;">
        
        <!-- Phone Keyboard -->
        <div class="phone-keyboard">
          <button type="button" class="phone-key" onclick="addPhone('1')">1</button>
          <button type="button" class="phone-key" onclick="addPhone('2')">2</button>
          <button type="button" class="phone-key" onclick="addPhone('3')">3</button>
          <button type="button" class="phone-key" onclick="addPhone('4')">4</button>
          <button type="button" class="phone-key" onclick="addPhone('5')">5</button>
          <button type="button" class="phone-key" onclick="addPhone('6')">6</button>
          <button type="button" class="phone-key" onclick="addPhone('7')">7</button>
          <button type="button" class="phone-key" onclick="addPhone('8')">8</button>
          <button type="button" class="phone-key" onclick="addPhone('9')">9</button>
          <button type="button" class="phone-key backspace-phone" onclick="backspacePhone()">⌫ Hapus</button>
          <button type="button" class="phone-key" onclick="addPhone('0')">0</button>
          <button type="button" class="phone-key" onclick="clearPhone()">Clear</button>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="backToResult()">Kembali</button>
        <button type="button" class="btn btn-success btn-lg" onclick="submitWithPhone()">
          <i class="bi bi-check-circle"></i> Lanjutkan
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Hidden Form untuk Submit -->
<form id="sepForm" action="buat_sep_rujukan_fktp.php" method="POST" style="display:none;">
    <?php if (!empty($result)): ?>
    <input type="hidden" name="noKartu" value="<?= htmlspecialchars($result['peserta']['noKartu'] ?? '') ?>">
    <input type="hidden" name="noRujukan" value="<?= htmlspecialchars($result['noKunjungan'] ?? '') ?>">
    <input type="hidden" name="namaPeserta" value="<?= htmlspecialchars($result['peserta']['nama'] ?? '') ?>">
    <input type="hidden" name="diagAwal" value="<?= htmlspecialchars($result['diagnosa']['kode'] ?? '') ?>">
    <input type="hidden" name="poliTujuan" value="<?= htmlspecialchars($result['poliRujukan']['kode'] ?? '') ?>">
    <input type="hidden" name="hakKelas" value="<?= htmlspecialchars($result['peserta']['hakKelas']['kode'] ?? '') ?>">
    <input type="hidden" name="tglRujukan" value="<?= htmlspecialchars($result['tglKunjungan'] ?? '') ?>">
    <input type="hidden" name="kdProvPerujuk" value="<?= htmlspecialchars($result['provPerujuk']['kode'] ?? '') ?>">
    <input type="hidden" name="nmProvPerujuk" value="<?= htmlspecialchars($result['provPerujuk']['nama'] ?? '') ?>">
    <input type="hidden" name="noTelp" id="hiddenPhone" value="">
    <?php endif; ?>
</form>

<?php if (!empty($result) || $error): ?>
<script>
var hasilModal = new bootstrap.Modal(document.getElementById('hasilModal'));
hasilModal.show();
</script>
<?php endif; ?>

<script>
// Keyboard Functions
function addChar(char) {
    var input = document.getElementById('no_rujukan');
    input.value += char;
    input.focus();
}

function backspace() {
    var input = document.getElementById('no_rujukan');
    input.value = input.value.slice(0, -1);
    input.focus();
}

// Phone Keyboard Functions
function addPhone(digit) {
    var input = document.getElementById('phoneInput');
    if (input.value.length < 15) {
        input.value += digit;
    }
}

function backspacePhone() {
    var input = document.getElementById('phoneInput');
    input.value = input.value.slice(0, -1);
}

function clearPhone() {
    document.getElementById('phoneInput').value = '';
}

function showPhoneModal() {
    var hasilModal = bootstrap.Modal.getInstance(document.getElementById('hasilModal'));
    hasilModal.hide();
    var phoneModal = new bootstrap.Modal(document.getElementById('phoneModal'));
    phoneModal.show();
}

function backToResult() {
    var phoneModal = bootstrap.Modal.getInstance(document.getElementById('phoneModal'));
    phoneModal.hide();
    var hasilModal = new bootstrap.Modal(document.getElementById('hasilModal'));
    hasilModal.show();
}

function submitWithPhone() {
    var phone = document.getElementById('phoneInput').value.trim();
    
    if (phone === '' || phone.length < 10) {
        alert('Nomor telepon harus diisi minimal 10 digit!');
        return;
    }
    
    // Validasi hanya angka
    if (!/^\d+$/.test(phone)) {
        alert('Nomor telepon hanya boleh berisi angka!');
        return;
    }
    
    document.getElementById('hiddenPhone').value = phone;
    document.getElementById('sepForm').submit();
}

window.addEventListener('load', function() {
    document.getElementById('no_rujukan').focus();
});
</script>
</body>
</html>