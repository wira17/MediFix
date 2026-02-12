<?php
session_start();
include 'koneksi.php';  // gunakan koneksi utama untuk setting_vclaim
include 'koneksi2.php'; // tetap include koneksi SIMRS kalau dibutuhkan
require_once __DIR__ . '/vendor/autoload.php';
use LZCompressor\LZString;

// === AMBIL CREDENTIALS DARI DATABASE ===
try {
    $stmt = $pdo->query("SELECT cons_id, secret_key, user_key, base_url FROM setting_vclaim LIMIT 1");
    $setting = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($setting) {
        $cons_id    = trim($setting['cons_id']);
        $secret_key = trim($setting['secret_key']);
        $user_key   = trim($setting['user_key']);
        $base_url   = rtrim($setting['base_url'], '/');
    } else {
        die("<h3 style='color:red;text-align:center;'>⚠️ Setting VClaim belum diset. Silakan isi di menu Setting VClaim.</h3>");
    }
} catch (Exception $e) {
    die("<h3 style='color:red;text-align:center;'>Gagal mengambil setting VClaim: " . htmlspecialchars($e->getMessage()) . "</h3>");
}

// === FUNGSI DEKRIPSI ===
function stringDecrypt($key, $string) {
    $encrypt_method = 'AES-256-CBC';
    $key_hash = hex2bin(hash('sha256', $key));
    $iv = substr($key_hash, 0, 16);
    return openssl_decrypt(base64_decode($string), $encrypt_method, $key_hash, OPENSSL_RAW_DATA, $iv);
}
function decompress($string) {
    return LZString::decompressFromEncodedURIComponent($string);
}

// === FUNGSI REQUEST BPJS ===
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
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        return ['metaData'=>['code'=>500,'message'=>"Curl error: $error_msg"]];
    }

    $json = json_decode($response, true);
    if (isset($json['response']) && !empty($json['response'])) {
        $key = $cons_id . $secret_key . $tStamp;
        $decrypt = stringDecrypt($key, $json['response']);
        $decompressed = decompress($decrypt);
        $json['response'] = json_decode($decompressed, true);
    }

    if ($debug) {
        $json['debug'] = ['timestamp'=>$tStamp,'http_code'=>$http_code];
    }

    return $json;
}

// === PROSES CEK PESERTA ===
$hasil = [];
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $no_kartu = trim($_POST['no_kartu'] ?? '');
    $tgl_sep = date('Y-m-d');

    if ($no_kartu === '') {
        $error = "Masukkan nomor kartu BPJS.";
    } else {
        $url = $base_url . "/Peserta/nokartu/$no_kartu/tglSEP/$tgl_sep";
        $response = getBpjsData($url, $cons_id, $secret_key, $user_key, true);
        if (isset($response['metaData']['code']) && $response['metaData']['code'] == '200') {
            $hasil = $response['response']['peserta'] ?? [];
        } else {
            $error = $response['metaData']['message'] ?? 'Peserta tidak ditemukan.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Anjungan Cek Kepesertaan BPJS</title>

<!-- Bootstrap & Icons -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

<!-- Google Font -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

<style>
:root {
  --primary: #047857;
  --primary-dark: #065f46;
  --secondary: #10b981;
  --accent: #34d399;
  --success: #10b981;
  --danger: #ef4444;
  --dark: #1f2937;
  --light: #f8fafc;
  --shadow-sm: 0 2px 8px rgba(0,0,0,0.08);
  --shadow-md: 0 4px 16px rgba(0,0,0,0.12);
  --shadow-lg: 0 8px 32px rgba(0,0,0,0.16);
}

* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

body {
  background: linear-gradient(135deg, #059669 0%, #10b981 50%, #34d399 100%);
  font-family: 'Inter', sans-serif;
  height: 100vh;
  padding: 15px;
  position: relative;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
}

body::before {
  content: '';
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: 
    radial-gradient(circle at 20% 50%, rgba(52, 211, 153, 0.2) 0%, transparent 50%),
    radial-gradient(circle at 80% 80%, rgba(16, 185, 129, 0.2) 0%, transparent 50%);
  pointer-events: none;
  z-index: 0;
}

.main-container {
  max-width: 1200px;
  width: 100%;
  height: 100%;
  display: flex;
  flex-direction: column;
  position: relative;
  z-index: 1;
}

/* Header Section */
.header-section {
  background: rgba(255, 255, 255, 0.98);
  backdrop-filter: blur(20px);
  border-radius: 20px;
  padding: 20px 30px;
  margin-bottom: 15px;
  box-shadow: var(--shadow-lg);
  border: 1px solid rgba(255, 255, 255, 0.3);
  position: relative;
  overflow: hidden;
  flex-shrink: 0;
}

.header-section::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 4px;
  background: linear-gradient(90deg, var(--primary), var(--secondary), var(--accent));
}

.header-title {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 16px;
}

.bpjs-logo {
  width: 60px;
  height: 60px;
  background: linear-gradient(135deg, var(--primary), var(--secondary));
  border-radius: 16px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: white;
  font-size: 30px;
  box-shadow: 0 6px 20px rgba(4, 120, 87, 0.3);
}

.header-text h3 {
  font-size: 24px;
  font-weight: 800;
  color: var(--primary);
  margin: 0;
  letter-spacing: -0.5px;
}

.header-text p {
  font-size: 13px;
  color: #64748b;
  margin: 2px 0 0 0;
  font-weight: 500;
}

/* Form Card */
.form-card {
  background: rgba(255, 255, 255, 0.98);
  backdrop-filter: blur(20px);
  border-radius: 18px;
  padding: 24px 30px;
  box-shadow: var(--shadow-md);
  border: 1px solid rgba(255, 255, 255, 0.3);
  flex-shrink: 0;
}

.form-label-custom {
  font-size: 16px;
  font-weight: 700;
  color: var(--primary);
  margin-bottom: 12px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.input-kartu {
  height: 60px;
  border-radius: 14px;
  border: 2px solid #d1fae5;
  font-size: 22px;
  font-weight: 600;
  text-align: center;
  letter-spacing: 2px;
  background: #f0fdf4;
  transition: all 0.3s ease;
  color: var(--dark);
}

.input-kartu:focus {
  border-color: var(--primary);
  box-shadow: 0 0 0 4px rgba(4, 120, 87, 0.1);
  background: white;
}

/* Action Buttons */
.action-buttons {
  display: flex;
  gap: 12px;
  justify-content: center;
  margin-top: 20px;
}

.btn-action {
  height: 52px;
  padding: 0 32px;
  border-radius: 14px;
  font-weight: 700;
  font-size: 16px;
  border: none;
  display: inline-flex;
  align-items: center;
  gap: 10px;
  transition: all 0.3s ease;
  box-shadow: var(--shadow-sm);
  text-decoration: none;
}

.btn-action i {
  font-size: 20px;
}

.btn-check {
  background: linear-gradient(135deg, var(--primary), var(--secondary));
  color: white;
  box-shadow: 0 4px 16px rgba(4, 120, 87, 0.4);
}

.btn-check:hover {
  transform: translateY(-3px);
  box-shadow: 0 8px 24px rgba(4, 120, 87, 0.5);
  color: white;
}

.btn-back {
  background: linear-gradient(135deg, #6b7280, #9ca3af);
  color: white;
}

.btn-back:hover {
  transform: translateY(-3px);
  box-shadow: 0 8px 24px rgba(107, 114, 128, 0.4);
  color: white;
}

/* Virtual Keyboard */
.keyboard-container {
  background: rgba(255, 255, 255, 0.98);
  backdrop-filter: blur(20px);
  border-radius: 18px;
  padding: 20px 24px;
  margin-top: 15px;
  box-shadow: var(--shadow-md);
  border: 1px solid rgba(255, 255, 255, 0.3);
  flex-grow: 1;
  display: flex;
  flex-direction: column;
  justify-content: center;
}

.keyboard-header {
  font-size: 14px;
  font-weight: 700;
  color: var(--primary);
  margin-bottom: 16px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}

.keyboard {
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  gap: 10px;
  max-width: 800px;
  margin: 0 auto;
}

.key {
  height: 60px;
  background: linear-gradient(135deg, #f8fafc, #f1f5f9);
  color: var(--dark);
  border: 2px solid #e2e8f0;
  border-radius: 12px;
  font-size: 24px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.2s ease;
  box-shadow: 0 3px 8px rgba(0, 0, 0, 0.1);
  display: flex;
  align-items: center;
  justify-content: center;
}

.key:hover {
  background: linear-gradient(135deg, white, #f8fafc);
  transform: translateY(-3px);
  box-shadow: 0 6px 16px rgba(0, 0, 0, 0.15);
  border-color: var(--primary);
}

.key:active {
  transform: translateY(0);
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.key.special {
  background: linear-gradient(135deg, var(--primary), var(--secondary));
  color: white;
  border-color: var(--primary);
  font-size: 18px;
  font-weight: 600;
}

.key.special:hover {
  background: linear-gradient(135deg, var(--primary-dark), var(--primary));
  border-color: var(--primary-dark);
}

.key.delete {
  background: linear-gradient(135deg, #f59e0b, #f97316);
  color: white;
  border-color: #f59e0b;
}

.key.delete:hover {
  background: linear-gradient(135deg, #d97706, #ea580c);
}

.key.clear {
  background: linear-gradient(135deg, #dc2626, #ef4444);
  color: white;
  border-color: #dc2626;
}

.key.clear:hover {
  background: linear-gradient(135deg, #b91c1c, #dc2626);
}

.key.enter {
  background: linear-gradient(135deg, var(--primary), var(--secondary));
  color: white;
  border-color: var(--primary);
  grid-column: span 2;
  font-size: 16px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 1px;
}

.key.enter:hover {
  background: linear-gradient(135deg, var(--primary-dark), var(--primary));
  box-shadow: 0 6px 20px rgba(4, 120, 87, 0.4);
}

/* Modal Premium */
.modal-content {
  border: none;
  border-radius: 24px;
  overflow: hidden;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
  max-height: 90vh;
  display: flex;
  flex-direction: column;
}

.modal-header {
  background: linear-gradient(135deg, var(--primary), var(--secondary));
  color: white;
  padding: 20px 28px;
  border: none;
  flex-shrink: 0;
}

.modal-header .modal-title {
  font-size: 22px;
  font-weight: 700;
  display: flex;
  align-items: center;
  gap: 12px;
}

.modal-header .btn-close {
  filter: brightness(0) invert(1);
  opacity: 0.8;
}

.modal-body {
  padding: 24px 28px;
  background: #f8fafc;
  overflow-y: auto;
  flex-grow: 1;
}

.modal-footer {
  padding: 20px 28px;
  border-top: 1px solid #e2e8f0;
  background: white;
  flex-shrink: 0;
  display: flex;
  justify-content: center;
  gap: 12px;
}

/* Table Data */
.data-table {
  background: white;
  border-radius: 12px;
  overflow: hidden;
  box-shadow: var(--shadow-sm);
}

.data-table tr {
  border-bottom: 1px solid #f1f5f9;
}

.data-table tr:last-child {
  border-bottom: none;
}

.data-table th {
  background: linear-gradient(135deg, #f0fdf4, #dcfce7);
  color: var(--primary-dark);
  font-weight: 700;
  font-size: 13px;
  padding: 14px 18px;
  width: 40%;
  text-align: left;
}

.data-table td {
  padding: 14px 18px;
  color: #334155;
  font-weight: 600;
  font-size: 14px;
}

/* Button Modal */
.btn-modal-ok {
  height: 50px;
  padding: 0 36px;
  border-radius: 12px;
  font-weight: 700;
  font-size: 16px;
  border: none;
  display: inline-flex;
  align-items: center;
  gap: 10px;
  transition: all 0.3s ease;
  background: linear-gradient(135deg, var(--primary), var(--secondary));
  color: white;
  box-shadow: 0 4px 12px rgba(4, 120, 87, 0.3);
  cursor: pointer;
}

.btn-modal-ok:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 16px rgba(4, 120, 87, 0.4);
}

/* Badge Status */
.badge-status {
  display: inline-block;
  padding: 6px 14px;
  border-radius: 20px;
  font-size: 13px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.badge-aktif {
  background: linear-gradient(135deg, #10b981, #34d399);
  color: white;
}

.badge-nonaktif {
  background: linear-gradient(135deg, #ef4444, #f87171);
  color: white;
}

/* Alert */
.alert-custom {
  border-radius: 14px;
  border: none;
  padding: 20px 24px;
  font-weight: 600;
  box-shadow: var(--shadow-sm);
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: 16px;
}

.alert-warning {
  background: linear-gradient(135deg, #fef3c7, #fde68a);
  color: #92400e;
}

/* Responsive */
@media (max-width: 768px) {
  body {
    padding: 10px;
  }

  .main-container {
    max-width: 100%;
  }

  .header-section {
    padding: 16px 20px;
    border-radius: 16px;
  }

  .header-title {
    flex-direction: column;
    text-align: center;
  }

  .bpjs-logo {
    width: 50px;
    height: 50px;
    font-size: 26px;
  }

  .header-text h3 {
    font-size: 18px;
  }

  .header-text p {
    font-size: 12px;
  }

  .form-card {
    padding: 20px;
  }

  .input-kartu {
    height: 52px;
    font-size: 18px;
  }

  .btn-action {
    height: 46px;
    padding: 0 20px;
    font-size: 14px;
  }

  .keyboard {
    grid-template-columns: repeat(4, 1fr);
    gap: 8px;
  }

  .key {
    height: 50px;
    font-size: 20px;
  }

  .key.special {
    font-size: 14px;
  }

  .key.enter {
    grid-column: span 2;
    font-size: 14px;
  }

  .data-table th,
  .data-table td {
    padding: 10px 14px;
    font-size: 12px;
  }

  .modal-body {
    padding: 20px;
  }
}

/* Loading Animation */
.loading-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.7);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9999;
}

.spinner {
  width: 60px;
  height: 60px;
  border: 5px solid rgba(255, 255, 255, 0.3);
  border-top-color: white;
  border-radius: 50%;
  animation: spin 1s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

/* Smooth Scrollbar */
::-webkit-scrollbar {
  width: 10px;
  height: 10px;
}

::-webkit-scrollbar-track {
  background: #f1f5f9;
  border-radius: 10px;
}

::-webkit-scrollbar-thumb {
  background: linear-gradient(135deg, var(--primary), var(--secondary));
  border-radius: 10px;
}

::-webkit-scrollbar-thumb:hover {
  background: linear-gradient(135deg, var(--primary-dark), var(--primary));
}
</style>
</head>
<body>

<div class="main-container">
  <!-- Header Section -->
  <div class="header-section">
    <div class="header-title">
      <div class="bpjs-logo">
        <i class="bi bi-shield-check"></i>
      </div>
      <div class="header-text">
        <h3>CEK KEPESERTAAN BPJS KESEHATAN</h3>
        <p>Verifikasi status kepesertaan dan data peserta BPJS</p>
      </div>
    </div>
  </div>

  <!-- Form Card -->
  <div class="form-card">
    <form method="POST" id="formCek">
      <div class="mb-3">
        <label class="form-label-custom">
          <i class="bi bi-credit-card-2-front"></i>
          NOMOR KARTU BPJS
        </label>
        <input 
          type="text" 
          id="no_kartu" 
          name="no_kartu" 
          class="form-control input-kartu" 
          placeholder="0000000000000" 
          maxlength="13"
          required 
          autofocus
          autocomplete="off"
        >
      </div>

      <div class="action-buttons">
        <button type="submit" class="btn-action btn-check">
          <i class="bi bi-search"></i>
          CEK PESERTA
        </button>
        <a href="anjungan.php" class="btn-action btn-back">
          <i class="bi bi-arrow-left-circle"></i>
          KEMBALI
        </a>
      </div>
    </form>
  </div>

  <!-- Virtual Keyboard -->
  <div class="keyboard-container">
    <div class="keyboard-header">
      <i class="bi bi-keyboard"></i>
      KEYBOARD VIRTUAL
    </div>
    <div class="keyboard">
      <button type="button" class="key" onclick="pressKey('1')">1</button>
      <button type="button" class="key" onclick="pressKey('2')">2</button>
      <button type="button" class="key" onclick="pressKey('3')">3</button>
      <button type="button" class="key" onclick="pressKey('4')">4</button>
      <button type="button" class="key" onclick="pressKey('5')">5</button>
      <button type="button" class="key" onclick="pressKey('6')">6</button>
      <button type="button" class="key" onclick="pressKey('7')">7</button>
      <button type="button" class="key" onclick="pressKey('8')">8</button>
      <button type="button" class="key" onclick="pressKey('9')">9</button>
      <button type="button" class="key special" onclick="pressKey('0')">0</button>
      <button type="button" class="key delete" onclick="deleteKey()">
        <i class="bi bi-backspace"></i>
      </button>
      <button type="button" class="key clear" onclick="clearInput()">
        <i class="bi bi-x-lg"></i> HAPUS
      </button>
      <button type="button" class="key enter" onclick="submitForm()">
        <i class="bi bi-check-circle-fill me-2"></i>CEK SEKARANG
      </button>
    </div>
  </div>
</div>

<!-- Modal Hasil -->
<div class="modal fade" id="resultModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="bi bi-file-text"></i>
          DATA KEPESERTAAN BPJS
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <?php if (!empty($hasil)): ?>
        <table class="table data-table">
          <tr>
            <th><i class="bi bi-credit-card me-2"></i>No. Kartu</th>
            <td><?= htmlspecialchars($hasil['noKartu'] ?? '-') ?></td>
          </tr>
          <tr>
            <th><i class="bi bi-person me-2"></i>Nama Lengkap</th>
            <td><?= htmlspecialchars($hasil['nama'] ?? '-') ?></td>
          </tr>
          <tr>
            <th><i class="bi bi-card-heading me-2"></i>NIK</th>
            <td><?= htmlspecialchars($hasil['nik'] ?? '-') ?></td>
          </tr>
          <tr>
            <th><i class="bi bi-gender-ambiguous me-2"></i>Jenis Kelamin</th>
            <td><?= ($hasil['sex'] ?? '') == 'L' ? 'Laki-laki' : 'Perempuan' ?></td>
          </tr>
          <tr>
            <th><i class="bi bi-calendar-event me-2"></i>Tanggal Lahir</th>
            <td><?= htmlspecialchars($hasil['tglLahir'] ?? '-') ?></td>
          </tr>
          <tr>
            <th><i class="bi bi-check-circle me-2"></i>Status Peserta</th>
            <td>
              <?php 
              $status = $hasil['statusPeserta']['keterangan'] ?? '-';
              $badgeClass = (strtoupper($status) == 'AKTIF') ? 'badge-aktif' : 'badge-nonaktif';
              ?>
              <span class="badge-status <?= $badgeClass ?>"><?= htmlspecialchars($status) ?></span>
            </td>
          </tr>
          <tr>
            <th><i class="bi bi-people me-2"></i>Jenis Peserta</th>
            <td><?= htmlspecialchars($hasil['jenisPeserta']['keterangan'] ?? '-') ?></td>
          </tr>
          <tr>
            <th><i class="bi bi-award me-2"></i>Hak Kelas</th>
            <td><strong><?= htmlspecialchars($hasil['hakKelas']['keterangan'] ?? '-') ?></strong></td>
          </tr>
          <tr>
            <th><i class="bi bi-hospital me-2"></i>Faskes Terdaftar</th>
            <td><?= htmlspecialchars($hasil['provUmum']['nmProvider'] ?? '-') ?></td>
          </tr>
          <tr>
            <th><i class="bi bi-calendar-check me-2"></i>Tanggal TMT</th>
            <td><?= htmlspecialchars($hasil['tglTMT'] ?? '-') ?></td>
          </tr>
          <tr>
            <th><i class="bi bi-calendar-x me-2"></i>Tanggal TAT</th>
            <td><?= htmlspecialchars($hasil['tglTAT'] ?? '-') ?></td>
          </tr>
          <tr>
            <th><i class="bi bi-hourglass-split me-2"></i>Umur Sekarang</th>
            <td><?= htmlspecialchars($hasil['umur']['umurSekarang'] ?? '-') ?></td>
          </tr>
        </table>
        <?php elseif ($error): ?>
          <div class="alert-custom alert-warning">
            <i class="bi bi-exclamation-triangle-fill" style="font-size: 24px;"></i>
            <div>
              <strong>Data Tidak Ditemukan</strong><br>
              <?= htmlspecialchars($error) ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-modal-ok" data-bs-dismiss="modal">
          <i class="bi bi-check-circle-fill"></i>
          OK, MENGERTI
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const input = document.getElementById('no_kartu');
const formCek = document.getElementById('formCek');

function pressKey(val) {
  if (input.value.length < 13) {
    input.value += val;
    input.focus();
  }
}

function deleteKey() {
  input.value = input.value.slice(0, -1);
  input.focus();
}

function clearInput() {
  input.value = '';
  input.focus();
}

function submitForm() {
  if (input.value.length > 0) {
    formCek.submit();
  } else {
    alert('Silakan masukkan nomor kartu BPJS terlebih dahulu!');
    input.focus();
  }
}

// Auto show modal
<?php if (!empty($hasil) || $error): ?>
const resultModal = new bootstrap.Modal(document.getElementById('resultModal'));
resultModal.show();
<?php endif; ?>

// Auto focus on load
window.addEventListener('load', () => {
  input.focus();
});

// Hanya izinkan input angka
input.addEventListener('input', (e) => {
  e.target.value = e.target.value.replace(/[^0-9]/g, '').slice(0, 13);
});

// Enter key submit
input.addEventListener('keypress', (e) => {
  if (e.key === 'Enter') {
    e.preventDefault();
    submitForm();
  }
});
</script>

</body>
</html>