<?php
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

// Ambil nama hari (SENIN dst)
$hari_ini = strtoupper(date('l'));
$mapHari = [
    'MONDAY' => 'SENIN',
    'TUESDAY' => 'SELASA',
    'WEDNESDAY' => 'RABU',
    'THURSDAY' => 'KAMIS',
    'FRIDAY' => 'JUMAT',
    'SATURDAY' => 'SABTU',
    'SUNDAY' => 'MINGGU'
];
$hari_indo = $mapHari[$hari_ini] ?? 'SENIN';

// Ambil jadwal dokter hari ini
$sql = "
    SELECT 
        jadwal.kd_dokter,
        dokter.nm_dokter,
        poli.nm_poli,
        jadwal.hari_kerja,
        jadwal.jam_mulai,
        jadwal.jam_selesai,
        jadwal.kuota
    FROM jadwal
    INNER JOIN dokter ON jadwal.kd_dokter = dokter.kd_dokter
    INNER JOIN poliklinik AS poli ON jadwal.kd_poli = poli.kd_poli
    WHERE jadwal.hari_kerja = '$hari_indo'
    ORDER BY poli.nm_poli, dokter.nm_dokter
";
$stmt = $pdo_simrs->query($sql);
$jadwal = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Hitung jumlah pasien per dokter hari ini
$jumlah_pasien = [];
$tanggal_hari_ini = date('Y-m-d');
$query_pasien = "
    SELECT kd_dokter, COUNT(no_rawat) AS total_pasien
    FROM reg_periksa
    WHERE tgl_registrasi = :tgl
    GROUP BY kd_dokter
";
$stmt_pasien = $pdo_simrs->prepare($query_pasien);
$stmt_pasien->execute([':tgl' => $tanggal_hari_ini]);
while ($row = $stmt_pasien->fetch(PDO::FETCH_ASSOC)) {
    $jumlah_pasien[$row['kd_dokter']] = (int)$row['total_pasien'];
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Jadwal Dokter Hari Ini</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

html, body {
    height: 100%;
    overflow: hidden;
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
    font-family: 'Inter', sans-serif;
}

/* Animated Background */
.particles {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    overflow: hidden;
    z-index: 0;
}

.particle {
    position: absolute;
    background: rgba(255, 255, 255, 0.03);
    border-radius: 50%;
    animation: float-particle linear infinite;
}

@keyframes float-particle {
    0% {
        transform: translateY(100vh) scale(0);
        opacity: 0;
    }
    10% {
        opacity: 1;
    }
    90% {
        opacity: 1;
    }
    100% {
        transform: translateY(-100vh) scale(1);
        opacity: 0;
    }
}

/* Header */
.header {
    position: relative;
    z-index: 10;
    background: linear-gradient(135deg, #8b5cf6, #7c3aed, #6d28d9);
    padding: 18px 30px;
    box-shadow: 0 8px 32px rgba(139, 92, 246, 0.4);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.header-left {
    display: flex;
    align-items: center;
    gap: 16px;
}

.header-icon {
    width: 50px;
    height: 50px;
    background: rgba(255, 255, 255, 0.2);
    backdrop-filter: blur(10px);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid rgba(255, 255, 255, 0.3);
}

.header-icon i {
    font-size: 24px;
    color: white;
}

.header-title {
    color: white;
}

.header-title h1 {
    font-size: 24px;
    font-weight: 800;
    margin: 0;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
}

.header-title p {
    font-size: 14px;
    margin: 0;
    opacity: 0.9;
    font-weight: 600;
}

.header-datetime {
    background: rgba(255, 255, 255, 0.2);
    backdrop-filter: blur(10px);
    padding: 10px 20px;
    border-radius: 50px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    display: flex;
    align-items: center;
    gap: 10px;
    color: white;
    font-weight: 700;
    font-size: 15px;
}

.header-datetime i {
    font-size: 18px;
}

/* Main Content */
.main-content {
    position: relative;
    z-index: 5;
    height: calc(100vh - 150px);
    overflow: hidden;
    padding: 20px;
}

.page {
    display: none;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
    height: 100%;
    overflow-y: auto;
    padding-bottom: 20px;
}

.page::-webkit-scrollbar {
    width: 8px;
}

.page::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.05);
    border-radius: 10px;
}

.page::-webkit-scrollbar-thumb {
    background: rgba(139, 92, 246, 0.5);
    border-radius: 10px;
}

.page.active {
    display: grid;
}

/* Doctor Card */
.doctor-card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
    border: 1px solid rgba(255, 255, 255, 0.3);
    transition: all 0.3s ease;
    position: relative;
    animation: slideUp 0.5s ease-out backwards;
}

.doctor-card:nth-child(1) { animation-delay: 0.05s; }
.doctor-card:nth-child(2) { animation-delay: 0.1s; }
.doctor-card:nth-child(3) { animation-delay: 0.15s; }
.doctor-card:nth-child(4) { animation-delay: 0.2s; }
.doctor-card:nth-child(5) { animation-delay: 0.25s; }
.doctor-card:nth-child(6) { animation-delay: 0.3s; }

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.doctor-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 12px 40px rgba(139, 92, 246, 0.4);
}

.card-header-custom {
    background: linear-gradient(135deg, #8b5cf6, #7c3aed);
    padding: 16px;
    position: relative;
    overflow: hidden;
}

.card-header-custom::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, #fbbf24, #f59e0b, #fbbf24);
}

.poli-name {
    color: white;
    font-size: 15px;
    font-weight: 800;
    text-align: center;
    margin: 0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

.card-body-custom {
    padding: 20px;
}

.doctor-name {
    font-size: 18px;
    font-weight: 800;
    color: #1e293b;
    margin-bottom: 16px;
    text-align: center;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.doctor-name i {
    color: #8b5cf6;
    font-size: 20px;
}

.info-grid {
    display: grid;
    gap: 12px;
}

.info-item {
    background: linear-gradient(135deg, #f8fafc, #f1f5f9);
    border-radius: 12px;
    padding: 12px;
    display: flex;
    align-items: center;
    gap: 12px;
    border: 2px solid #e2e8f0;
    transition: all 0.3s ease;
}

.info-item:hover {
    transform: translateX(5px);
    border-color: #8b5cf6;
}

.info-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}

.info-icon.time {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
}

.info-icon.quota {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
}

.info-icon.registered {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: white;
}

.info-icon.available {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
}

.info-content {
    flex: 1;
}

.info-label {
    font-size: 11px;
    color: #64748b;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 2px;
}

.info-value {
    font-size: 16px;
    font-weight: 800;
    color: #1e293b;
}

/* Footer */
.footer {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 10;
    background: linear-gradient(135deg, #8b5cf6, #7c3aed, #6d28d9);
    padding: 14px 0;
    box-shadow: 0 -8px 32px rgba(139, 92, 246, 0.4);
    overflow: hidden;
}

.marquee-container {
    overflow: hidden;
    white-space: nowrap;
}

.marquee {
    display: inline-block;
    animation: marquee 25s linear infinite;
    color: white;
    font-size: 16px;
    font-weight: 700;
    letter-spacing: 0.5px;
}

@keyframes marquee {
    0% {
        transform: translateX(100%);
    }
    100% {
        transform: translateX(-100%);
    }
}

.marquee i {
    margin: 0 10px;
    color: #fbbf24;
    font-size: 18px;
}

/* Page Indicator */
.page-indicator {
    position: fixed;
    top: 90px;
    right: 30px;
    z-index: 20;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 10px 20px;
    border-radius: 50px;
    border: 2px solid rgba(139, 92, 246, 0.3);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
    display: flex;
    align-items: center;
    gap: 8px;
    color: #1e293b;
    font-weight: 700;
    font-size: 14px;
}

.page-indicator i {
    color: #8b5cf6;
}

/* Empty State */
.empty-state {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    text-align: center;
    color: white;
}

.empty-icon {
    width: 100px;
    height: 100px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
}

.empty-icon i {
    font-size: 50px;
    color: rgba(255, 255, 255, 0.5);
}

.empty-title {
    font-size: 24px;
    font-weight: 800;
    margin-bottom: 10px;
}

.empty-text {
    font-size: 16px;
    opacity: 0.7;
}

/* Responsive */
@media (max-width: 1400px) {
    .page {
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    }
}

@media (max-width: 992px) {
    .header {
        flex-direction: column;
        gap: 15px;
        padding: 15px 20px;
    }
    
    .header-datetime {
        font-size: 13px;
        padding: 8px 16px;
    }
    
    .page {
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 15px;
    }
    
    .page-indicator {
        top: auto;
        bottom: 80px;
        right: 20px;
    }
}

@media (max-width: 768px) {
    .header-title h1 {
        font-size: 20px;
    }
    
    .header-title p {
        font-size: 12px;
    }
    
    .page {
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    }
    
    .doctor-name {
        font-size: 16px;
    }
    
    .info-value {
        font-size: 14px;
    }
}

@media (max-width: 576px) {
    .page {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>

<!-- Animated Particles -->
<div class="particles" id="particles"></div>

<!-- Header -->
<div class="header">
    <div class="header-left">
        <div class="header-icon">
            <i class="bi bi-calendar-heart"></i>
        </div>
        <div class="header-title">
            <h1>Jadwal Dokter Hari Ini</h1>
            <p><?= ucfirst(strtolower($hari_indo)); ?>, <?= date('d F Y'); ?></p>
        </div>
    </div>
    
    <div class="header-datetime">
        <i class="bi bi-clock-fill"></i>
        <span id="liveTime"></span>
    </div>
</div>



<!-- Main Content -->
<div class="main-content">
    <?php
    if (empty($jadwal)) {
        echo '
        <div class="empty-state">
            <div class="empty-icon">
                <i class="bi bi-calendar-x"></i>
            </div>
            <div class="empty-title">Tidak Ada Jadwal</div>
            <div class="empty-text">Tidak ada jadwal dokter untuk hari ini</div>
        </div>';
    } else {
        // Bagi data jadi per 9 jadwal per halaman
        $pages = array_chunk($jadwal, 9);
        $pageIndex = 0;
        
        foreach ($pages as $page):
    ?>
    <div class="page <?= $pageIndex === 0 ? 'active' : '' ?>">
        <?php foreach ($page as $j): 
            $pasien = $jumlah_pasien[$j['kd_dokter']] ?? 0;
            $sisa = max(0, $j['kuota'] - $pasien);
        ?>
        <div class="doctor-card">
            <div class="card-header-custom">
                <h3 class="poli-name">
                    <i class="bi bi-hospital"></i>
                    <?= htmlspecialchars($j['nm_poli']); ?>
                </h3>
            </div>
            
            <div class="card-body-custom">
                <div class="doctor-name">
                    <i class="bi bi-person-circle"></i>
                    <?= htmlspecialchars($j['nm_dokter']); ?>
                </div>
                
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-icon time">
                            <i class="bi bi-clock-fill"></i>
                        </div>
                        <div class="info-content">
                            <div class="info-label">Jam Praktik</div>
                            <div class="info-value">
                                <?= substr($j['jam_mulai'],0,5) ?> - <?= substr($j['jam_selesai'],0,5) ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-icon quota">
                            <i class="bi bi-people-fill"></i>
                        </div>
                        <div class="info-content">
                            <div class="info-label">Kuota</div>
                            <div class="info-value"><?= (int)$j['kuota'] ?> Pasien</div>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-icon registered">
                            <i class="bi bi-person-check-fill"></i>
                        </div>
                        <div class="info-content">
                            <div class="info-label">Terdaftar</div>
                            <div class="info-value"><?= $pasien ?> Pasien</div>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-icon available">
                            <i class="bi bi-hourglass-split"></i>
                        </div>
                        <div class="info-content">
                            <div class="info-label">Sisa Kuota</div>
                            <div class="info-value"><?= $sisa ?> Pasien</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php 
        $pageIndex++;
        endforeach;
    }
    ?>
</div>

<!-- Footer -->
<div class="footer">
    <div class="marquee-container">
        <div class="marquee">
            <i class="bi bi-info-circle-fill"></i>
            Jadwal dokter diperbarui otomatis setiap hari dari SIMRS
            <i class="bi bi-calendar-check"></i>
            <?= date('d F Y'); ?>
            <i class="bi bi-check-circle-fill"></i>
            Informasi terkini, cepat, dan akurat
            <i class="bi bi-heart-pulse-fill"></i>
            Selamat datang di RS Permata Hati
        </div>
    </div>
</div>

<script>
// Create Particles
function createParticles() {
    const container = document.getElementById('particles');
    const particleCount = 20;
    
    for (let i = 0; i < particleCount; i++) {
        const particle = document.createElement('div');
        particle.className = 'particle';
        
        const size = Math.random() * 80 + 30;
        particle.style.width = size + 'px';
        particle.style.height = size + 'px';
        particle.style.left = Math.random() * 100 + '%';
        particle.style.animationDuration = (Math.random() * 15 + 10) + 's';
        particle.style.animationDelay = Math.random() * 5 + 's';
        
        container.appendChild(particle);
    }
}

createParticles();

// Update Live Time
function updateTime() {
    const now = new Date();
    const timeStr = now.toLocaleTimeString('id-ID', { 
        hour: '2-digit', 
        minute: '2-digit',
        second: '2-digit'
    });
    document.getElementById('liveTime').textContent = timeStr;
}

setInterval(updateTime, 1000);
updateTime();

// Page Slider
let currentPage = 0;
const pages = document.querySelectorAll('.page');
const totalPages = pages.length;

document.getElementById('totalPages').textContent = totalPages;

function showPage(index) {
    pages.forEach(p => p.classList.remove('active'));
    if (pages[index]) {
        pages[index].classList.add('active');
        document.getElementById('currentPage').textContent = index + 1;
    }
}

// Auto-slide setiap 12 detik
if (totalPages > 1) {
    setInterval(() => {
        currentPage = (currentPage + 1) % totalPages;
        showPage(currentPage);
    }, 12000);
}
</script>

</body>
</html>