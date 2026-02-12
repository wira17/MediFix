<?php
session_start();
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

error_reporting(E_ALL);
ini_set('display_errors', 1);

$kodeAntrian = null;
$errorMsg = null;
$successMsg = null;

// Ambil identitas rumah sakit
try {
    $stmt = $pdo_simrs->query("SELECT nama_instansi, alamat_instansi, kabupaten, kontak, email FROM setting LIMIT 1");
    $setting = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $setting = [
        'nama_instansi' => 'RS Permata Hati',
        'alamat_instansi' => 'Jl. Kesehatan No. 123',
        'kabupaten' => 'Kota Sehat',
        'kontak' => '(021) 1234567',
        'email' => 'info@rspermatahati.com'
    ];
}

// === LOGIKA AMBIL NOMOR ANTRIAN ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ambil'])) {
    try {
        $jenis = 'ADMISI';
        $tgl = date('Y-m-d');

        // Ambil nomor terakhir hari ini
        $stmt = $pdo_simrs->prepare("
            SELECT nomor AS last_nomor
            FROM antrian_wira
            WHERE jenis = ? AND DATE(created_at) = ?
            ORDER BY CAST(SUBSTRING(nomor, 2) AS UNSIGNED) DESC
            LIMIT 1
        ");
        $stmt->execute([$jenis, $tgl]);
        $last = $stmt->fetch(PDO::FETCH_ASSOC);

        $lastNomor = 0;
        if ($last && isset($last['last_nomor'])) {
            $lastNomor = (int)ltrim($last['last_nomor'], 'A');
        }

        // Buat nomor baru
        $nomorBaru = $lastNomor + 1;
        $kodeAntrian = 'A' . str_pad($nomorBaru, 1, '0', STR_PAD_LEFT);

        // Simpan ke database
        $stmt = $pdo_simrs->prepare("
            INSERT INTO antrian_wira (jenis, nomor, status, created_at)
            VALUES (?, ?, 'Menunggu', NOW())
        ");
        $stmt->execute([$jenis, $kodeAntrian]);

        // Set flag untuk auto print
        $successMsg = $kodeAntrian;

    } catch (Exception $e) {
        $errorMsg = "Terjadi kesalahan: " . $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Anjungan Antrian Admisi - <?= htmlspecialchars($setting['nama_instansi']) ?></title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

<style>
* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

body {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  font-family: 'Inter', sans-serif;
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  padding: 20px;
}

/* Animated Background */
.bg-animated {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  overflow: hidden;
  z-index: 0;
}

.circle-float {
  position: absolute;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.05);
  animation: float-around 20s infinite ease-in-out;
}

.circle-1 { width: 300px; height: 300px; top: 10%; left: 10%; animation-delay: 0s; }
.circle-2 { width: 200px; height: 200px; top: 60%; left: 70%; animation-delay: 5s; }
.circle-3 { width: 150px; height: 150px; top: 80%; left: 20%; animation-delay: 10s; }

@keyframes float-around {
  0%, 100% { transform: translate(0, 0) scale(1); }
  33% { transform: translate(50px, -50px) scale(1.1); }
  66% { transform: translate(-30px, 30px) scale(0.9); }
}

/* Main Container */
.main-container {
  position: relative;
  z-index: 1;
  width: 100%;
  max-width: 1000px;
  background: rgba(255, 255, 255, 0.98);
  backdrop-filter: blur(30px);
  border-radius: 30px;
  box-shadow: 0 30px 90px rgba(0, 0, 0, 0.3);
  border: 2px solid rgba(255, 255, 255, 0.3);
  overflow: hidden;
  animation: slideUp 0.6s ease-out;
}

@keyframes slideUp {
  from {
    opacity: 0;
    transform: translateY(40px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

/* Header Gradient Bar */
.gradient-bar {
  height: 6px;
  background: linear-gradient(90deg, #0ea5e9, #06b6d4, #14b8a6, #10b981);
}

/* Content Layout */
.content-wrapper {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 30px;
  padding: 40px;
}

/* Left Section */
.left-section {
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 25px;
}

.header-info {
  text-align: left;
}

.header-info h1 {
  font-size: 38px;
  font-weight: 900;
  background: linear-gradient(135deg, #0ea5e9, #06b6d4);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  margin-bottom: 10px;
  line-height: 1.2;
}

.header-info p {
  font-size: 16px;
  color: #64748b;
  font-weight: 600;
  margin: 0;
}

.info-boxes {
  display: flex;
  flex-direction: column;
  gap: 15px;
}

.info-box {
  background: linear-gradient(135deg, #e0f2fe, #dbeafe);
  border-radius: 16px;
  padding: 18px 20px;
  border: 2px solid #bae6fd;
  display: flex;
  align-items: center;
  gap: 15px;
  transition: transform 0.3s ease;
}

.info-box:hover {
  transform: translateX(5px);
}

.info-box i {
  font-size: 28px;
  color: #0369a1;
}

.info-box-content {
  flex: 1;
  text-align: left;
}

.info-box-content label {
  font-size: 12px;
  color: #0369a1;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  display: block;
  margin-bottom: 3px;
}

.info-box-content p {
  font-size: 18px;
  font-weight: 800;
  color: #0369a1;
  margin: 0;
}

/* Right Section */
.right-section {
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 20px;
}

.icon-display {
  text-align: center;
  margin-bottom: 10px;
}

.icon-circle {
  width: 160px;
  height: 160px;
  background: linear-gradient(135deg, #0ea5e9, #06b6d4);
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 20px 60px rgba(14, 165, 233, 0.4);
  animation: pulse-icon 3s ease-in-out infinite;
  position: relative;
}

.icon-circle::before {
  content: '';
  position: absolute;
  width: 180px;
  height: 180px;
  border: 3px solid rgba(14, 165, 233, 0.3);
  border-radius: 50%;
  animation: ripple 2s ease-out infinite;
}

@keyframes pulse-icon {
  0%, 100% { transform: scale(1); }
  50% { transform: scale(1.05); }
}

@keyframes ripple {
  0% {
    transform: scale(1);
    opacity: 1;
  }
  100% {
    transform: scale(1.25);
    opacity: 0;
  }
}

.icon-circle i {
  font-size: 80px;
  color: white;
  position: relative;
  z-index: 1;
}

/* Alert */
.alert-custom {
  border-radius: 14px;
  border: none;
  padding: 16px 20px;
  font-weight: 700;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
  display: flex;
  align-items: center;
  gap: 12px;
  animation: shake 0.5s ease;
}

@keyframes shake {
  0%, 100% { transform: translateX(0); }
  25% { transform: translateX(-8px); }
  75% { transform: translateX(8px); }
}

.alert-danger {
  background: linear-gradient(135deg, #fee2e2, #fecaca);
  color: #991b1b;
}

.alert-danger i {
  font-size: 24px;
}

/* Notice Box */
.notice-box {
  background: linear-gradient(135deg, #fef3c7, #fde68a);
  border-radius: 14px;
  padding: 16px;
  border: 2px solid #fde047;
  text-align: center;
}

.notice-box p {
  margin: 0;
  color: #92400e;
  font-weight: 700;
  font-size: 15px;
  display: flex;
  align-items: center;
  gap: 10px;
  justify-content: center;
}

.notice-box i {
  font-size: 20px;
}

/* Buttons */
.button-group {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.btn-action {
  height: 65px;
  border: none;
  border-radius: 16px;
  font-weight: 800;
  font-size: 20px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  transition: all 0.3s ease;
  box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
  position: relative;
  overflow: hidden;
  cursor: pointer;
  text-decoration: none;
}

.btn-action::before {
  content: '';
  position: absolute;
  top: 50%;
  left: 50%;
  width: 0;
  height: 0;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.3);
  transform: translate(-50%, -50%);
  transition: width 0.6s, height 0.6s;
}

.btn-action:hover::before {
  width: 400px;
  height: 400px;
}

.btn-action i {
  font-size: 28px;
  position: relative;
  z-index: 1;
}

.btn-action span {
  position: relative;
  z-index: 1;
}

.btn-primary-custom {
  background: linear-gradient(135deg, #0ea5e9, #06b6d4);
  color: white;
}

.btn-primary-custom:hover {
  transform: translateY(-3px);
  box-shadow: 0 10px 30px rgba(14, 165, 233, 0.4);
  color: white;
}

.btn-secondary-custom {
  background: linear-gradient(135deg, #6b7280, #4b5563);
  color: white;
}

.btn-secondary-custom:hover {
  transform: translateY(-3px);
  box-shadow: 0 10px 30px rgba(107, 114, 128, 0.4);
  color: white;
}

/* Footer */
.footer-section {
  background: linear-gradient(135deg, #f8fafc, #f1f5f9);
  padding: 20px 40px;
  border-top: 2px solid #e2e8f0;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 15px;
}

.footer-section p {
  margin: 0;
  color: #64748b;
  font-size: 13px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 6px;
}

.footer-section .copyright {
  color: #0ea5e9;
  font-weight: 800;
}

.footer-section i {
  color: #10b981;
}

/* Print Styles - PENTING! */
@media print {
  @page {
    size: 80mm auto;
    margin: 0;
  }
  
  body {
    background: white;
  }
  
  body > *:not(.print-area) {
    display: none !important;
  }
  
  .print-area {
    display: block !important;
    position: static !important;
    width: 80mm !important;
    padding: 10mm !important;
    margin: 0 !important;
    background: white !important;
  }
  
  .print-area * {
    visibility: visible !important;
  }
}

/* Print Area - Always Hidden on Screen */
.print-area {
  display: none;
}

/* Responsive */
@media (max-width: 768px) {
  .content-wrapper {
    grid-template-columns: 1fr;
    padding: 30px 20px;
  }
  
  .header-info {
    text-align: center;
  }
  
  .header-info h1 {
    font-size: 32px;
  }
  
  .icon-circle {
    width: 130px;
    height: 130px;
  }
  
  .icon-circle i {
    font-size: 65px;
  }
  
  .footer-section {
    flex-direction: column;
    text-align: center;
  }
}
</style>
</head>
<body>

<!-- Animated Background -->
<div class="bg-animated">
  <div class="circle-float circle-1"></div>
  <div class="circle-float circle-2"></div>
  <div class="circle-float circle-3"></div>
</div>

<div class="main-container">
  <!-- Gradient Bar -->
  <div class="gradient-bar"></div>
  
  <!-- Content Wrapper -->
  <div class="content-wrapper">
    <!-- Left Section -->
    <div class="left-section">
      <div class="header-info">
        <h1>🎫 Antrian Admisi</h1>
        <p>Sistem Pengambilan Nomor Antrian Pendaftaran Pasien</p>
      </div>
      
      <div class="info-boxes">
        <div class="info-box">
          <i class="bi bi-calendar-event-fill"></i>
          <div class="info-box-content">
            <label>Tanggal</label>
            <p id="tanggal"></p>
          </div>
        </div>
        
        <div class="info-box">
          <i class="bi bi-clock-fill"></i>
          <div class="info-box-content">
            <label>Waktu</label>
            <p id="waktu"></p>
          </div>
        </div>
      </div>
      
      <div class="notice-box">
        <p>
          <i class="bi bi-info-circle-fill"></i>
          Silakan ambil nomor antrian untuk pendaftaran
        </p>
      </div>
    </div>
    
    <!-- Right Section -->
    <div class="right-section">
      <div class="icon-display">
        <div class="icon-circle">
          <i class="bi bi-ticket-perforated-fill"></i>
        </div>
      </div>
      
      <!-- Alert Error -->
      <?php if (!empty($errorMsg)): ?>
        <div class="alert-custom alert-danger">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <div><?= htmlspecialchars($errorMsg) ?></div>
        </div>
      <?php endif; ?>
      
      <!-- Form -->
      <form method="post" id="formAntrian">
        <div class="button-group">
          <button type="submit" name="ambil" class="btn-action btn-primary-custom">
            <i class="bi bi-ticket-detailed-fill"></i>
            <span>Ambil Nomor Antrian</span>
          </button>
          <a href="anjungan.php" class="btn-action btn-secondary-custom">
            <i class="bi bi-arrow-left-circle-fill"></i>
            <span>Kembali</span>
          </a>
        </div>
      </form>
    </div>
  </div>
  
  <!-- Footer -->
  <div class="footer-section">
    <p>
      <i class="bi bi-c-circle"></i> 
      <?= date('Y') ?> 
      <span class="copyright"><?= htmlspecialchars($setting['nama_instansi']) ?></span>
    </p>
    <p>
      <i class="bi bi-code-slash"></i>
      Powered by <span class="copyright">M. Wira Sb. S. Kom</span>
    </p>
    <p>
      <i class="bi bi-whatsapp" style="color: #25D366;"></i>
      <span class="copyright">082177846209</span>
    </p>
  </div>
</div>

<!-- Print Area (Hidden on screen, visible on print) -->
<?php if (!empty($successMsg)): ?>
<div class="print-area" id="printArea">
  <div style="text-align:center; font-family:'Courier New', monospace; padding:5px; background:white;">
    <h3 style="margin:8px 0; font-size:18px; font-weight:bold;"><?= htmlspecialchars($setting['nama_instansi']) ?></h3>
    <p style="margin:3px 0; font-size:11px;"><?= htmlspecialchars($setting['alamat_instansi']) ?>, <?= htmlspecialchars($setting['kabupaten']) ?></p>
    <p style="margin:3px 0; font-size:11px;">Telp: <?= htmlspecialchars($setting['kontak']) ?></p>
    <div style="border-top:1px dashed #000; margin:8px 0;"></div>
    <h1 style="font-size:72px; margin:15px 0; font-weight:900; color:#000;"><?= $successMsg ?></h1>
    <p style="margin:5px 0; font-size:16px; font-weight:bold;">ANTRIAN PENDAFTARAN</p>
    <p style="margin:5px 0; font-size:13px;"><strong>Tanggal:</strong> <?= date('d-m-Y H:i') ?> WIB</p>
    <div style="border-top:1px dashed #000; margin:8px 0;"></div>
    <p style="font-size:11px; margin:8px 0;">Terima kasih telah mengambil nomor antrian.</p>
    <p style="font-size:11px; margin:0;">Silakan menunggu panggilan di ruang tunggu.</p>
    <p style="font-size:10px; margin:10px 0 0 0;">Powered by MediFix - 082177846209</p>
  </div>
</div>
<?php endif; ?>

<script>
// Real-time Date & Time
function updateDateTime() {
  const now = new Date();
  
  const optionsTanggal = { 
    weekday: 'long', 
    year: 'numeric', 
    month: 'long', 
    day: 'numeric'
  };
  
  const optionsWaktu = {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit'
  };
  
  document.getElementById('tanggal').textContent = now.toLocaleDateString('id-ID', optionsTanggal);
  document.getElementById('waktu').textContent = now.toLocaleTimeString('id-ID', optionsWaktu) + ' WIB';
}

setInterval(updateDateTime, 1000);
updateDateTime();

// Auto Print and Redirect
<?php if (!empty($successMsg)): ?>
window.onload = function() {
  setTimeout(function() {
    window.print();
    
    // Redirect setelah print dialog ditutup
    setTimeout(function() {
      window.location.href = 'antrian_admisi.php';
    }, 1000);
  }, 500);
};
<?php endif; ?>
</script>

</body>
</html>