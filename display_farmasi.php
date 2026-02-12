<?php
session_start();
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

// === CEK LOGIN ===
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$nama = $_SESSION['nama'] ?? 'Pengguna';

// === Fungsi untuk membaca data panggilan terakhir dari file ===
function getLastCall() {
    // Coba beberapa lokasi file
    $locations = [
        __DIR__ . '/data/last_farmasi.json',        // Lokasi utama (data folder)
        __DIR__ . '/last_farmasi.json',             // Fallback 1 (root folder)
        sys_get_temp_dir() . '/last_farmasi.json'   // Fallback 2 (temp folder)
    ];
    
    foreach ($locations as $file) {
        if (file_exists($file)) {
            $content = file_get_contents($file);
            $data = json_decode($content, true);
            
            // Debug log
            error_log("✅ Reading file: " . $file);
            error_log("File content: " . $content);
            
            // Check if data is fresh (within last 2 hours)
            if (isset($data['waktu'])) {
                $callTime = strtotime($data['waktu']);
                $currentTime = time();
                $diff = $currentTime - $callTime;
                
                // If more than 2 hours, return null
                if ($diff > 7200) {
                    error_log("⚠️ Data too old (" . round($diff/60) . " minutes), returning null");
                    continue;
                }
            }
            
            error_log("✅ Valid data found in: " . $file);
            return $data;
        }
    }
    
    error_log("❌ No valid file found in any location");
    return null;
}

// === Ambil data resep hari ini - SESUAI KHANZA ASLI ===
try {
    $stmt = $pdo_simrs->prepare("
        SELECT 
            ro.no_resep, ro.tgl_peresepan, ro.jam_peresepan, ro.status as status_resep,
            r.no_rkm_medis, p.nm_pasien, d.nm_dokter, pl.kd_poli, pl.nm_poli,
            CASE 
                WHEN EXISTS (SELECT 1 FROM resep_dokter_racikan rr WHERE rr.no_resep = ro.no_resep)
                THEN 'Racikan'
                ELSE 'Non Racikan'
            END AS jenis_resep
        FROM resep_obat ro
        INNER JOIN reg_periksa r ON ro.no_rawat = r.no_rawat
        INNER JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
        INNER JOIN dokter d ON ro.kd_dokter = d.kd_dokter
        LEFT JOIN poliklinik pl ON r.kd_poli = pl.kd_poli
        WHERE ro.tgl_peresepan = CURDATE()
          AND ro.status = 'ralan'
          AND ro.jam_peresepan <> '00:00:00'
        ORDER BY ro.tgl_peresepan DESC, ro.jam_peresepan DESC
    ");
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Pisahkan berdasarkan jenis resep
    $nonRacik = array_filter($data, fn($r) => $r['jenis_resep'] === 'Non Racikan');
    $racik = array_filter($data, fn($r) => $r['jenis_resep'] === 'Racikan');

    // Ambil data panggilan terakhir dari file
    $lastCall = getLastCall();
    $sedangDilayani = null;
    
    if ($lastCall && isset($lastCall['no_resep'])) {
        // Langsung gunakan data dari file JSON
        $sedangDilayani = [
            'no_resep' => $lastCall['no_resep'],
            'nm_pasien' => $lastCall['nm_pasien'] ?? '-',
            'nm_poli' => $lastCall['nm_poli'] ?? '-',
            'jenis_resep' => $lastCall['jenis_resep'] ?? 'Non Racikan'
        ];
        
        error_log("Display will show: " . print_r($sedangDilayani, true));
    } else {
        error_log("No last call data available");
    }

    // Fungsi sensor nama pasien
    function sensorNama($nama) {
        $parts = explode(' ', $nama);
        $result = [];
        foreach ($parts as $p) {
            $len = mb_strlen($p);
            if ($len <= 2) {
                $result[] = str_repeat('*', $len);
            } else {
                $result[] = mb_substr($p, 0, 1) . str_repeat('*', $len - 2) . mb_substr($p, -1);
            }
        }
        return implode(' ', $result);
    }

    // Konversi tanggal ke Bahasa Indonesia
    function tanggalIndonesia($tgl) {
        $hari = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
        $bulan = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
        $tanggal = date('j', strtotime($tgl));
        $bulanIdx = $bulan[(int)date('n', strtotime($tgl))];
        $tahun = date('Y', strtotime($tgl));
        $hariNama = $hari[(int)date('w', strtotime($tgl))];
        return "$hariNama, $tanggal $bulanIdx $tahun";
    }

} catch (PDOException $e) {
    die("Gagal mengambil data: " . $e->getMessage());
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Display Antrian Farmasi - MediFix</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Inter', sans-serif;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
    overflow-x: hidden;
}

/* Header */
.header {
    background: rgba(255, 255, 255, 0.98);
    backdrop-filter: blur(20px);
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
    padding: 1.5rem 0;
    margin-bottom: 1.5rem;
}

.header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.brand-section {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.brand-icon {
    width: 70px;
    height: 70px;
    background: linear-gradient(135deg, #ff9800, #ff6f00);
    border-radius: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 36px;
    box-shadow: 0 4px 15px rgba(255, 152, 0, 0.3);
}

.brand-text h1 {
    font-size: 32px;
    font-weight: 900;
    color: #1e293b;
    margin: 0;
    letter-spacing: -0.5px;
}

.brand-text p {
    font-size: 16px;
    color: #64748b;
    margin: 0;
    font-weight: 600;
}

.header-info {
    text-align: right;
}

.date-display {
    font-size: 16px;
    color: #475569;
    font-weight: 600;
    margin-bottom: 0.5rem;
}

.clock-display {
    font-size: 42px;
    font-weight: 800;
    color: #ff9800;
    font-variant-numeric: tabular-nums;
    letter-spacing: 2px;
}

/* Main Grid */
.display-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}

/* Section Card */
.section-card {
    background: white;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.15);
}

.section-header {
    padding: 1.5rem 2rem;
    color: white;
    display: flex;
    align-items: center;
    gap: 1rem;
    font-size: 24px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.section-header.non-racikan {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
}

.section-header.racikan {
    background: linear-gradient(135deg, #ff9800, #ff6f00);
}

.section-body {
    padding: 3rem 2rem;
    text-align: center;
    min-height: 380px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.queue-label {
    font-size: 18px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 2px;
    margin-bottom: 1.5rem;
}

.queue-number {
    font-size: 140px;
    font-weight: 900;
    line-height: 1;
    margin-bottom: 2rem;
    font-variant-numeric: tabular-nums;
    text-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
}

.queue-number.non-racikan {
    color: #3b82f6;
}

.queue-number.racikan {
    color: #ff9800;
}

.queue-number.empty {
    color: #cbd5e1;
    font-size: 90px;
}

.patient-info {
    background: #f8fafc;
    border-radius: 15px;
    padding: 1.5rem 2rem;
    margin-top: 1.5rem;
}

.info-row {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 1rem;
    padding: 0.75rem 0;
    font-size: 20px;
    font-weight: 600;
    color: #1e293b;
}

.info-row i {
    font-size: 26px;
    color: #ff9800;
}

.info-row.empty {
    color: #94a3b8;
}

/* Info Box */
.info-box {
    background: white;
    border-radius: 20px;
    padding: 2rem 2.5rem;
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
    border-left: 6px solid #ff9800;
}

.info-box-title {
    font-size: 20px;
    font-weight: 800;
    color: #1e293b;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.info-box-title i {
    color: #ff9800;
    font-size: 26px;
}

.info-box-content {
    font-size: 17px;
    line-height: 1.8;
    color: #475569;
}

.info-box-content strong {
    color: #1e293b;
    font-weight: 700;
}

/* Stats Banner */
.stats-banner {
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.95), rgba(255, 255, 255, 0.9));
    backdrop-filter: blur(10px);
    border-radius: 20px;
    padding: 2rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.5rem;
}

.stat-item {
    text-align: center;
    padding: 1.5rem 1rem;
    background: white;
    border-radius: 12px;
    border: 2px solid #e2e8f0;
}

.stat-value {
    font-size: 48px;
    font-weight: 900;
    color: #ff9800;
    margin-bottom: 0.5rem;
    line-height: 1;
}

.stat-label {
    font-size: 15px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Footer */
.footer {
    background: linear-gradient(135deg, rgba(255, 152, 0, 0.95), rgba(255, 111, 0, 0.95));
    backdrop-filter: blur(10px);
    color: white;
    padding: 1.25rem 0;
    position: fixed;
    bottom: 0;
    width: 100%;
    box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.15);
    overflow: hidden;
}

.marquee-container {
    display: flex;
    align-items: center;
    font-size: 20px;
    font-weight: 600;
    white-space: nowrap;
}

.marquee {
    display: inline-block;
    padding-left: 100%;
    animation: marquee 25s linear infinite;
}

@keyframes marquee {
    from { transform: translateX(0); }
    to { transform: translateX(-100%); }
}

/* Pulse Animation for Active Number */
@keyframes pulse {
    0%, 100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.05);
    }
}

.queue-number:not(.empty) {
    animation: pulse 2s ease-in-out infinite;
}

/* ========================================
   RESPONSIVE BREAKPOINTS
   ======================================== */

/* Large Desktop / TV (1920px+) */
@media (min-width: 1920px) {
    .brand-icon {
        width: 100px;
        height: 100px;
        font-size: 50px;
    }
    
    .brand-text h1 {
        font-size: 48px;
    }
    
    .brand-text p {
        font-size: 22px;
    }
    
    .date-display {
        font-size: 22px;
    }
    
    .clock-display {
        font-size: 60px;
    }
    
    .section-header {
        padding: 2.5rem 3rem;
        font-size: 36px;
    }
    
    .section-body {
        padding: 5rem 3rem;
        min-height: 500px;
    }
    
    .queue-label {
        font-size: 26px;
        margin-bottom: 2rem;
    }
    
    .queue-number {
        font-size: 220px;
        margin-bottom: 3rem;
    }
    
    .queue-number.empty {
        font-size: 140px;
    }
    
    .info-row {
        font-size: 32px;
        padding: 1.25rem 0;
    }
    
    .info-row i {
        font-size: 40px;
    }
    
    .stat-value {
        font-size: 72px;
    }
    
    .stat-label {
        font-size: 22px;
    }
    
    .info-box-title {
        font-size: 32px;
    }
    
    .info-box-title i {
        font-size: 40px;
    }
    
    .info-box-content {
        font-size: 26px;
    }
    
    .marquee-container {
        font-size: 32px;
    }
}

/* Desktop (1440px - 1919px) */
@media (min-width: 1440px) and (max-width: 1919px) {
    .brand-icon {
        width: 85px;
        height: 85px;
        font-size: 42px;
    }
    
    .brand-text h1 {
        font-size: 40px;
    }
    
    .brand-text p {
        font-size: 19px;
    }
    
    .clock-display {
        font-size: 52px;
    }
    
    .section-header {
        font-size: 30px;
    }
    
    .queue-number {
        font-size: 180px;
    }
    
    .queue-number.empty {
        font-size: 110px;
    }
    
    .info-row {
        font-size: 26px;
    }
    
    .info-row i {
        font-size: 32px;
    }
    
    .stat-value {
        font-size: 60px;
    }
    
    .stat-label {
        font-size: 18px;
    }
    
    .info-box-title {
        font-size: 26px;
    }
    
    .info-box-content {
        font-size: 21px;
    }
    
    .marquee-container {
        font-size: 26px;
    }
}

/* Standard Desktop / Laptop (1024px - 1439px) */
@media (min-width: 1024px) and (max-width: 1439px) {
    .container {
        max-width: 100%;
        padding: 0 2rem;
    }
    
    .section-body {
        min-height: 320px;
    }
    
    .queue-number {
        font-size: 120px;
    }
    
    .queue-number.empty {
        font-size: 80px;
    }
}

/* Tablet Landscape (768px - 1023px) */
@media (min-width: 768px) and (max-width: 1023px) {
    .display-grid {
        grid-template-columns: 1fr;
        gap: 1.5rem;
    }
    
    .section-body {
        min-height: 280px;
    }
    
    .queue-number {
        font-size: 110px;
    }
    
    .queue-number.empty {
        font-size: 70px;
    }
    
    .stats-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

/* Mobile & Tablet Portrait (max 767px) */
@media (max-width: 767px) {
    .header-content {
        flex-direction: column;
        gap: 1.5rem;
        text-align: center;
    }
    
    .header-info {
        text-align: center;
    }
    
    .brand-icon {
        width: 60px;
        height: 60px;
        font-size: 30px;
    }
    
    .brand-text h1 {
        font-size: 24px;
    }
    
    .brand-text p {
        font-size: 14px;
    }
    
    .clock-display {
        font-size: 36px;
    }
    
    .display-grid {
        grid-template-columns: 1fr;
    }
    
    .section-header {
        font-size: 20px;
        padding: 1rem 1.5rem;
    }
    
    .section-body {
        padding: 2rem 1.5rem;
        min-height: 260px;
    }
    
    .queue-number {
        font-size: 90px;
    }
    
    .queue-number.empty {
        font-size: 60px;
    }
    
    .info-row {
        font-size: 16px;
    }
    
    .info-row i {
        font-size: 20px;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    
    .stat-value {
        font-size: 36px;
    }
    
    .stat-label {
        font-size: 13px;
    }
    
    .info-box-title {
        font-size: 16px;
    }
    
    .info-box-content {
        font-size: 14px;
    }
    
    .marquee-container {
        font-size: 16px;
    }
}

/* Loading Animation */
.loading-indicator {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #ff9800;
    animation: blink 1.4s infinite both;
    margin-left: 10px;
}

@keyframes blink {
    0%, 80%, 100% { opacity: 0; }
    40% { opacity: 1; }
}
</style>
</head>
<body>

<!-- Header -->
<div class="header">
    <div class="container">
        <div class="header-content">
            <div class="brand-section">
                <div class="brand-icon">
                    <i class="bi bi-capsule-pill"></i>
                </div>
                <div class="brand-text">
                    <h1>Display Antrian Farmasi</h1>
                    <p>RS Permata Hati - MediFix System</p>
                </div>
            </div>
            <div class="header-info">
                <div class="date-display">
                    <i class="bi bi-calendar-check"></i>
                    <?= tanggalIndonesia(date('Y-m-d')) ?>
                </div>
                <div class="clock-display" id="clock">00:00:00</div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<div class="container" style="padding-bottom: 100px;">
    
  
    
    <!-- Display Grid -->
    <div class="display-grid">
        
        <!-- Non Racikan -->
        <div class="section-card">
            <div class="section-header non-racikan">
                <i class="bi bi-prescription2"></i>
                <span>Non Racikan</span>
            </div>
            <div class="section-body">
                <div class="queue-label">Sedang Dilayani</div>
                <?php
                    $nomorNon = '-';
                    $namaNon = '-';
                    $poliNon = '-';
                    $hasDataNon = false;
                    
                    if ($sedangDilayani && $sedangDilayani['jenis_resep'] === 'Non Racikan') {
                        $nomorNon = 'F' . str_pad(substr($sedangDilayani['no_resep'], -4), 4, '0', STR_PAD_LEFT);
                        $namaNon = sensorNama($sedangDilayani['nm_pasien'] ?? '-');
                        $poliNon = $sedangDilayani['nm_poli'] ?? '-';
                        $hasDataNon = true;
                    }
                ?>
                <div class="queue-number non-racikan <?= !$hasDataNon ? 'empty' : '' ?>">
                    <?= htmlspecialchars($nomorNon) ?>
                </div>
                
                <?php if ($hasDataNon): ?>
                <div class="patient-info">
                    <div class="info-row">
                        <i class="bi bi-person-fill"></i>
                        <span><?= htmlspecialchars($namaNon) ?></span>
                    </div>
                    <div class="info-row">
                        <i class="bi bi-hospital-fill"></i>
                        <span><?= htmlspecialchars($poliNon) ?></span>
                    </div>
                </div>
                <?php else: ?>
                <div class="patient-info">
                    <div class="info-row empty">
                        <i class="bi bi-hourglass-split"></i>
                        <span>Menunggu Panggilan</span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Racikan -->
        <div class="section-card">
            <div class="section-header racikan">
                <i class="bi bi-capsule"></i>
                <span>Racikan</span>
            </div>
            <div class="section-body">
                <div class="queue-label">Sedang Dilayani</div>
                <?php
                    $nomorRacik = '-';
                    $namaRacik = '-';
                    $poliRacik = '-';
                    $hasDataRacik = false;
                    
                    if ($sedangDilayani && $sedangDilayani['jenis_resep'] === 'Racikan') {
                        $nomorRacik = 'F' . str_pad(substr($sedangDilayani['no_resep'], -4), 4, '0', STR_PAD_LEFT);
                        $namaRacik = sensorNama($sedangDilayani['nm_pasien'] ?? '-');
                        $poliRacik = $sedangDilayani['nm_poli'] ?? '-';
                        $hasDataRacik = true;
                    }
                ?>
                <div class="queue-number racikan <?= !$hasDataRacik ? 'empty' : '' ?>">
                    <?= htmlspecialchars($nomorRacik) ?>
                </div>
                
                <?php if ($hasDataRacik): ?>
                <div class="patient-info">
                    <div class="info-row">
                        <i class="bi bi-person-fill"></i>
                        <span><?= htmlspecialchars($namaRacik) ?></span>
                    </div>
                    <div class="info-row">
                        <i class="bi bi-hospital-fill"></i>
                        <span><?= htmlspecialchars($poliRacik) ?></span>
                    </div>
                </div>
                <?php else: ?>
                <div class="patient-info">
                    <div class="info-row empty">
                        <i class="bi bi-hourglass-split"></i>
                        <span>Menunggu Panggilan</span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
    </div>
    
    <!-- Info Box -->
    <div class="info-box">
        <div class="info-box-title">
            <i class="bi bi-info-circle-fill"></i>
            <span>Informasi Waktu Tunggu Resep Racikan</span>
        </div>
        <div class="info-box-content">
            Sesuai <strong>Permenkes 72/2016</strong>, obat racikan membutuhkan proses tambahan 
            (penimbangan, peracikan, pelabelan & validasi apoteker). 
            Estimasi waktu pelayanan: <strong>± 15 – 60 menit</strong>. 
            Terima kasih atas kesabaran dan pengertian Anda 🙏
        </div>
    </div>
    
</div>

<!-- Footer -->
<div class="footer">
    <div class="marquee-container">
        <div class="marquee">
            <i class="bi bi-heart-pulse-fill"></i> 
            Selamat datang di RS Permata Hati • Layanan Farmasi Siap Melayani Anda dengan Sepenuh Hati 💊 • 
            Untuk informasi lebih lanjut hubungi loket farmasi • 
            Mohon ambil nomor antrian dan tunggu panggilan Anda • 
            Terima kasih atas kepercayaan Anda 
            <i class="bi bi-heart-pulse-fill"></i> &nbsp;&nbsp;&nbsp;&nbsp;
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// === Jam Digital ===
function updateClock() {
    const now = new Date();
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const seconds = String(now.getSeconds()).padStart(2, '0');
    document.getElementById('clock').textContent = `${hours}:${minutes}:${seconds}`;
}

setInterval(updateClock, 1000);
updateClock();

// === Auto Refresh setiap 5 detik untuk update data terbaru ===
setInterval(() => {
    location.reload();
}, 5000);

// === Page Visibility API - pause refresh ketika tab tidak aktif ===
let refreshInterval;

function startRefresh() {
    refreshInterval = setInterval(() => {
        location.reload();
    }, 5000);
}

function stopRefresh() {
    if (refreshInterval) {
        clearInterval(refreshInterval);
    }
}

document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        stopRefresh();
    } else {
        startRefresh();
    }
});

// Start initial refresh
if (!document.hidden) {
    startRefresh();
}

// === Smooth fade in on load ===
window.addEventListener('load', () => {
    document.body.style.opacity = '0';
    document.body.style.transition = 'opacity 0.3s ease';
    setTimeout(() => {
        document.body.style.opacity = '1';
    }, 100);
});
</script>

</body>
</html>