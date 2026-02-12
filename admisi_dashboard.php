<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$nama = $_SESSION['nama'] ?? 'Pengguna';
date_default_timezone_set('Asia/Jakarta');
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard Admisi - MediFix</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

:root {
    --primary: #0ea5e9;
    --primary-dark: #0284c7;
    --secondary: #06b6d4;
    --success: #10b981;
    --danger: #ef4444;
    --warning: #f59e0b;
    --dark: #1e293b;
    --light: #f8fafc;
}

body {
    font-family: 'Inter', sans-serif;
    background: #f0f4f8;
    height: 100vh;
    overflow: hidden;
    position: relative;
}

/* Animated Background */
.bg-animated {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 0;
    overflow: hidden;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.bg-animated::before,
.bg-animated::after {
    content: '';
    position: absolute;
    border-radius: 50%;
    opacity: 0.1;
}

.bg-animated::before {
    width: 800px;
    height: 800px;
    background: radial-gradient(circle, #fff 0%, transparent 70%);
    top: -400px;
    right: -400px;
    animation: pulse 8s ease-in-out infinite;
}

.bg-animated::after {
    width: 600px;
    height: 600px;
    background: radial-gradient(circle, #fff 0%, transparent 70%);
    bottom: -300px;
    left: -300px;
    animation: pulse 10s ease-in-out infinite reverse;
}

@keyframes pulse {
    0%, 100% { transform: scale(1); opacity: 0.1; }
    50% { transform: scale(1.1); opacity: 0.15; }
}

/* Top Bar */
.top-bar {
    background: rgba(255, 255, 255, 0.98);
    backdrop-filter: blur(20px);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    padding: 12px 0;
    position: relative;
    z-index: 100;
    border-bottom: 1px solid rgba(0, 0, 0, 0.05);
}

.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 20px;
    font-weight: 800;
    color: var(--dark);
}

.brand-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 20px;
}

.top-bar-right {
    display: flex;
    align-items: center;
    gap: 20px;
}

.time-badge {
    display: flex;
    align-items: center;
    gap: 8px;
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    color: white;
    padding: 8px 16px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    box-shadow: 0 4px 12px rgba(14, 165, 233, 0.3);
}

.user-badge {
    display: flex;
    align-items: center;
    gap: 10px;
    background: white;
    padding: 6px 6px 6px 14px;
    border-radius: 50px;
    border: 2px solid #e2e8f0;
    font-size: 13px;
    font-weight: 600;
    color: var(--dark);
}

.user-avatar-small {
    width: 32px;
    height: 32px;
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 14px;
}

/* Main Content */
.main-content {
    height: calc(100vh - 130px);
    overflow-y: auto;
    position: relative;
    z-index: 1;
    padding: 20px 0;
}

.main-content::-webkit-scrollbar {
    width: 8px;
}

.main-content::-webkit-scrollbar-track {
    background: transparent;
}

.main-content::-webkit-scrollbar-thumb {
    background: rgba(148, 163, 184, 0.3);
    border-radius: 10px;
}

/* Page Header */
.page-header {
    background: white;
    border-radius: 20px;
    padding: 30px;
    margin-bottom: 25px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    border: 1px solid rgba(0, 0, 0, 0.05);
    position: relative;
    overflow: hidden;
}

.page-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--primary), var(--secondary), var(--success));
}

.page-title {
    font-size: 28px;
    font-weight: 800;
    color: var(--dark);
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.page-title i {
    font-size: 32px;
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.page-subtitle {
    color: #64748b;
    font-size: 14px;
    font-weight: 500;
}

.welcome-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
    color: var(--primary);
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
    margin-top: 12px;
    border: 1px solid rgba(14, 165, 233, 0.2);
}

/* Menu Grid */
.menu-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
}

/* Menu Card - New Design */
.menu-item {
    background: white;
    border-radius: 16px;
    padding: 0;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    border: 1px solid rgba(0, 0, 0, 0.05);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    cursor: pointer;
    position: relative;
    overflow: hidden;
    text-decoration: none;
    display: block;
}

.menu-item::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: var(--menu-color);
    transform: scaleX(0);
    transition: transform 0.3s ease;
}

.menu-item:hover::before {
    transform: scaleX(1);
}

.menu-item:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 24px rgba(0, 0, 0, 0.12);
    border-color: var(--menu-color);
}

.menu-content {
    padding: 24px;
    display: flex;
    align-items: center;
    gap: 16px;
}

.menu-icon-wrapper {
    width: 56px;
    height: 56px;
    background: var(--menu-bg);
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: all 0.3s ease;
}

.menu-item:hover .menu-icon-wrapper {
    transform: scale(1.1) rotate(-5deg);
    background: var(--menu-color);
}

.menu-icon-wrapper i {
    font-size: 28px;
    color: var(--menu-color);
    transition: color 0.3s ease;
}

.menu-item:hover .menu-icon-wrapper i {
    color: white;
}

.menu-text {
    flex: 1;
}

.menu-title {
    font-size: 15px;
    font-weight: 700;
    color: var(--dark);
    margin-bottom: 4px;
}

.menu-desc {
    font-size: 12px;
    color: #64748b;
    font-weight: 500;
}

.menu-arrow {
    font-size: 20px;
    color: #cbd5e1;
    transition: all 0.3s ease;
}

.menu-item:hover .menu-arrow {
    color: var(--menu-color);
    transform: translateX(4px);
}

/* Individual Menu Colors */
.menu-panggil {
    --menu-color: #ef4444;
    --menu-bg: #fef2f2;
}

.menu-display {
    --menu-color: #dc2626;
    --menu-bg: #fef2f2;
}

.menu-finger {
    --menu-color: #3b82f6;
    --menu-bg: #eff6ff;
}

.menu-kamar {
    --menu-color: #06b6d4;
    --menu-bg: #ecfeff;
}

.menu-jadwal {
    --menu-color: #8b5cf6;
    --menu-bg: #f5f3ff;
}

.menu-back {
    --menu-color: #64748b;
    --menu-bg: #f8fafc;
}

/* Bottom Bar */
.bottom-bar {
    background: rgba(255, 255, 255, 0.98);
    backdrop-filter: blur(20px);
    padding: 12px 0;
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 100;
    box-shadow: 0 -1px 3px rgba(0, 0, 0, 0.05);
    border-top: 1px solid rgba(0, 0, 0, 0.05);
}

.footer-content {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 16px;
    font-size: 13px;
    color: #64748b;
    font-weight: 600;
}

.footer-logo {
    height: 28px;
    border-radius: 6px;
}

.footer-divider {
    width: 1px;
    height: 20px;
    background: #e2e8f0;
}

.footer-contact {
    display: flex;
    align-items: center;
    gap: 6px;
    background: linear-gradient(135deg, #25D366, #128C7E);
    color: white;
    padding: 6px 12px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 700;
}

/* Animations */
.menu-item {
    animation: fadeInUp 0.5s ease-out backwards;
}

.menu-item:nth-child(1) { animation-delay: 0.1s; }
.menu-item:nth-child(2) { animation-delay: 0.15s; }
.menu-item:nth-child(3) { animation-delay: 0.2s; }
.menu-item:nth-child(4) { animation-delay: 0.25s; }
.menu-item:nth-child(5) { animation-delay: 0.3s; }
.menu-item:nth-child(6) { animation-delay: 0.35s; }

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Responsive */
@media (max-width: 992px) {
    .menu-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 16px;
    }
}

@media (max-width: 576px) {
    .menu-grid {
        grid-template-columns: 1fr;
    }
    
    .page-header {
        padding: 20px;
    }
    
    .page-title {
        font-size: 22px;
    }
    
    .user-badge span {
        display: none;
    }
    
    .footer-content {
        flex-wrap: wrap;
        gap: 8px;
    }
}
</style>
</head>
<body>

<!-- Animated Background -->
<div class="bg-animated"></div>

<!-- Top Bar -->
<div class="top-bar">
    <div class="container">
        <div class="d-flex align-items-center justify-content-between">
            <div class="brand">
                <div class="brand-icon">
                    <i class="bi bi-hospital-fill"></i>
                </div>
                <span>MediFix</span>
            </div>
            
            <div class="top-bar-right">
                <div class="time-badge">
                    <i class="bi bi-clock-fill"></i>
                    <span id="clockDisplay"></span>
                </div>
                
                <div class="user-badge">
                    <span><?= htmlspecialchars($nama) ?></span>
                    <div class="user-avatar-small">
                        <i class="bi bi-person-fill"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<div class="main-content">
    <div class="container">
        
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-title">
                <i class="bi bi-person-badge-fill"></i>
                Dashboard Admisi
            </div>
            <div class="page-subtitle">
                Pusat Kontrol Pendaftaran & Administrasi Pasien
            </div>
            <div class="welcome-tag">
                👋 Selamat datang, <strong><?= htmlspecialchars($nama) ?></strong>
            </div>
            <div class="mt-2">
                <small class="text-muted" id="tanggalSekarang" style="font-size: 12px;"></small>
            </div>
        </div>
        
        <!-- Menu Grid -->
        <div class="menu-grid">
            
            <!-- Panggil Admisi -->
            <a href="data_antri_admisi.php" class="menu-item menu-panggil">
                <div class="menu-content">
                    <div class="menu-icon-wrapper">
                        <i class="bi bi-person-vcard-fill"></i>
                    </div>
                    <div class="menu-text">
                        <div class="menu-title">Panggil Admisi</div>
                        <div class="menu-desc">Kelola antrian pasien</div>
                    </div>
                    <div class="menu-arrow">
                        <i class="bi bi-arrow-right"></i>
                    </div>
                </div>
            </a>
            
            <!-- Display Admisi -->
            <a href="display_admisi.php" target="_blank" class="menu-item menu-display">
                <div class="menu-content">
                    <div class="menu-icon-wrapper">
                        <i class="bi bi-tv-fill"></i>
                    </div>
                    <div class="menu-text">
                        <div class="menu-title">Display Admisi</div>
                        <div class="menu-desc">Tampilan layar antrian</div>
                    </div>
                    <div class="menu-arrow">
                        <i class="bi bi-arrow-right"></i>
                    </div>
                </div>
            </a>
            
          
            
            <!-- Ketersediaan Kamar -->
            <a href="ketersediaan_kamar.php" target="_blank" class="menu-item menu-kamar">
                <div class="menu-content">
                    <div class="menu-icon-wrapper">
                        <i class="bi bi-door-closed-fill"></i>
                    </div>
                    <div class="menu-text">
                        <div class="menu-title">Ketersediaan Kamar</div>
                        <div class="menu-desc">Cek status kamar rawat</div>
                    </div>
                    <div class="menu-arrow">
                        <i class="bi bi-arrow-right"></i>
                    </div>
                </div>
            </a>
            
            <!-- Jadwal Dokter -->
            <a href="display_jadwal_dokter.php" target="_blank" class="menu-item menu-jadwal">
                <div class="menu-content">
                    <div class="menu-icon-wrapper">
                        <i class="bi bi-calendar-check-fill"></i>
                    </div>
                    <div class="menu-text">
                        <div class="menu-title">Jadwal Dokter</div>
                        <div class="menu-desc">Lihat jadwal praktik</div>
                    </div>
                    <div class="menu-arrow">
                        <i class="bi bi-arrow-right"></i>
                    </div>
                </div>
            </a>
            
            <!-- Kembali -->
            <a href="dashboard.php" class="menu-item menu-back">
                <div class="menu-content">
                    <div class="menu-icon-wrapper">
                        <i class="bi bi-arrow-left-circle-fill"></i>
                    </div>
                    <div class="menu-text">
                        <div class="menu-title">Kembali</div>
                        <div class="menu-desc">Ke dashboard utama</div>
                    </div>
                    <div class="menu-arrow">
                        <i class="bi bi-arrow-right"></i>
                    </div>
                </div>
            </a>
            
        </div>
        
    </div>
</div>

<div class="bottom-bar">
    <div class="container">
        <div class="footer-content">
            <img src="image/logo.png" class="footer-logo" alt="Logo">
            <div class="footer-divider"></div>
            <div class="footer-contact">
                <i class="bi bi-whatsapp"></i>
                <strong style="color: #1e293b;">082177846209 - © 2026 MediFix Apps - All Rights Reserved</strong>
            </div>
            <div class="footer-divider"></div>
            <img src="image/logo.png" class="footer-logo" alt="Logo">
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function updateClock() {
    const now = new Date();
    const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
    const tanggal = now.toLocaleDateString('id-ID', options);
    const waktu = now.toLocaleTimeString('id-ID');
    
    document.getElementById('tanggalSekarang').innerHTML = tanggal;
    document.getElementById('clockDisplay').innerHTML = waktu;
}

setInterval(updateClock, 1000);
updateClock();
</script>
</body>
</html>