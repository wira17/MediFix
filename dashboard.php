<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

include 'koneksi.php';

// refresh akses dari DB
$stmtAkses = $pdo->prepare("SELECT menu, izin FROM hak_akses WHERE user_id=?");
$stmtAkses->execute([$_SESSION['user_id']]);
$aksesData = $stmtAkses->fetchAll(PDO::FETCH_ASSOC);

$_SESSION['akses'] = [];
foreach ($aksesData as $row) {
    $_SESSION['akses'][$row['menu']] = $row['izin'];
}

$nama = $_SESSION['nama'] ?? 'Pengguna';

function boleh($menu) {
    return isset($_SESSION['akses'][$menu]) && $_SESSION['akses'][$menu] == 1;
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard - MediFix</title>
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

.btn-logout {
    display: flex;
    align-items: center;
    gap: 6px;
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
    padding: 8px 16px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
}

.btn-logout:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(239, 68, 68, 0.4);
    color: white;
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
    padding: 20px 30px;
    margin-bottom: 20px;
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
    background: linear-gradient(90deg, var(--primary), var(--secondary), var(--success), var(--warning));
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
    max-width: 1400px;
    margin: 0 auto;
}

/* Menu Card */
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
    padding: 20px;
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

/* Disabled State */
.menu-item.disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.menu-item.disabled:hover {
    transform: none;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    border-color: rgba(0, 0, 0, 0.05);
}

.menu-item.disabled::after {
    content: '🔒';
    position: absolute;
    top: 12px;
    right: 12px;
    font-size: 16px;
}

.menu-item.disabled .menu-arrow {
    opacity: 0.3;
}

.menu-item.disabled:hover .menu-arrow {
    transform: none;
}

/* Individual Menu Colors */
.menu-anjungan {
    --menu-color: #10b981;
    --menu-bg: #ecfdf5;
}

.menu-admisi {
    --menu-color: #3b82f6;
    --menu-bg: #eff6ff;
}

.menu-poliklinik {
    --menu-color: #8b5cf6;
    --menu-bg: #f5f3ff;
}

.menu-farmasi {
    --menu-color: #ef4444;
    --menu-bg: #fef2f2;
}

.menu-bridging {
    --menu-color: #06b6d4;
    --menu-bg: #ecfeff;
}

.menu-setting {
    --menu-color: #ec4899;
    --menu-bg: #fdf2f8;
}

.menu-tentang {
    --menu-color: #f59e0b;
    --menu-bg: #fffbeb;
}

/* Modal Styles - COMPACT VERSION */
.modal-content {
    border-radius: 20px;
    border: none;
    overflow: hidden;
    max-height: 85vh;
}

.modal-header {
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    color: white;
    border: none;
    padding: 16px 24px;
}

.modal-title {
    font-weight: 800;
    font-size: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.modal-body {
    padding: 20px 24px;
    overflow-y: auto;
    max-height: calc(85vh - 100px);
}

.modal-body::-webkit-scrollbar {
    width: 6px;
}

.modal-body::-webkit-scrollbar-track {
    background: #f1f5f9;
}

.modal-body::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 3px;
}

/* Compact Info Boxes */
.info-box-compact {
    background: #f8fafc;
    border-radius: 10px;
    padding: 12px 16px;
    margin-bottom: 12px;
    border-left: 3px solid var(--primary);
}

.info-box-compact h6 {
    font-weight: 700;
    color: var(--dark);
    margin-bottom: 8px;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.info-box-compact ul {
    margin: 0;
    padding-left: 20px;
    font-size: 11px;
    color: #64748b;
}

.info-box-compact ul li {
    margin-bottom: 4px;
}

.donation-box-compact {
    background: linear-gradient(135deg, #ecfdf5, #d1fae5);
    border-radius: 10px;
    padding: 16px;
    margin: 12px 0;
    border-left: 3px solid #10b981;
    text-align: center;
}

.donation-box-compact h6 {
    font-weight: 700;
    color: #059669;
    margin-bottom: 8px;
    font-size: 13px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}

.donation-box-compact p {
    font-size: 11px;
    color: #064e3b;
    margin-bottom: 10px;
}

.donation-rekening-compact {
    background: white;
    padding: 12px;
    border-radius: 8px;
    font-size: 12px;
}

.donation-rekening-compact strong {
    font-size: 14px;
    color: #059669;
}

.tech-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 8px;
    margin-top: 8px;
}

.tech-item {
    background: white;
    padding: 8px;
    border-radius: 8px;
    text-align: center;
    border: 2px solid #e2e8f0;
}

.tech-item i {
    font-size: 20px;
}

.tech-item div {
    font-size: 9px;
    font-weight: 700;
    color: #1e293b;
    margin-top: 4px;
}

.developer-box {
    text-align: center;
    padding: 16px;
    background: linear-gradient(135deg, #f8fafc, #f1f5f9);
    border-radius: 10px;
    border: 2px solid #e2e8f0;
    margin-top: 12px;
}

.developer-box p {
    font-size: 11px;
    color: #64748b;
    margin: 0;
}

.developer-box strong {
    font-size: 13px;
    color: #1e293b;
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
.menu-item:nth-child(7) { animation-delay: 0.4s; }

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
@media (max-width: 1200px) {
    .menu-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 16px;
    }
}

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
    
    .top-bar-right {
        gap: 10px;
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
                
                <a href="logout.php" class="btn-logout">
                    <i class="bi bi-box-arrow-right"></i>
                    Logout
                </a>
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
                <i class="bi bi-speedometer2"></i>
                Dashboard MediFix
            </div>
            <div class="page-subtitle">
                Sistem Informasi Manajemen Rumah Sakit
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
            
            <!-- Anjungan Pasien -->
            <a href="<?= boleh('anjungan') ? 'anjungan.php' : '#' ?>" 
               target="<?= boleh('anjungan') ? '_blank' : '_self' ?>"
               class="menu-item menu-anjungan <?= boleh('anjungan') ? '' : 'disabled' ?>">
                <div class="menu-content">
                    <div class="menu-icon-wrapper">
                        <i class="bi bi-building"></i>
                    </div>
                    <div class="menu-text">
                        <div class="menu-title">Anjungan Pasien</div>
                        <div class="menu-desc">Pendaftaran mandiri</div>
                    </div>
                    <div class="menu-arrow">
                        <i class="bi bi-arrow-right"></i>
                    </div>
                </div>
            </a>
            
            <!-- Admisi -->
            <a href="<?= boleh('admisi') ? 'admisi_dashboard.php' : '#' ?>"
               class="menu-item menu-admisi <?= boleh('admisi') ? '' : 'disabled' ?>">
                <div class="menu-content">
                    <div class="menu-icon-wrapper">
                        <i class="bi bi-person-badge"></i>
                    </div>
                    <div class="menu-text">
                        <div class="menu-title">Admisi</div>
                        <div class="menu-desc">Administrasi pasien</div>
                    </div>
                    <div class="menu-arrow">
                        <i class="bi bi-arrow-right"></i>
                    </div>
                </div>
            </a>
            
            <!-- Poliklinik -->
            <a href="<?= boleh('poliklinik') ? 'poli_dashboard.php' : '#' ?>"
               class="menu-item menu-poliklinik <?= boleh('poliklinik') ? '' : 'disabled' ?>">
                <div class="menu-content">
                    <div class="menu-icon-wrapper">
                        <i class="bi bi-hospital"></i>
                    </div>
                    <div class="menu-text">
                        <div class="menu-title">Poliklinik</div>
                        <div class="menu-desc">Pelayanan poli</div>
                    </div>
                    <div class="menu-arrow">
                        <i class="bi bi-arrow-right"></i>
                    </div>
                </div>
            </a>
            
            <!-- Farmasi -->
            <a href="<?= boleh('farmasi') ? 'farmasi_dashboard.php' : '#' ?>"
               class="menu-item menu-farmasi <?= boleh('farmasi') ? '' : 'disabled' ?>">
                <div class="menu-content">
                    <div class="menu-icon-wrapper">
                        <i class="bi bi-capsule-pill"></i>
                    </div>
                    <div class="menu-text">
                        <div class="menu-title">Farmasi</div>
                        <div class="menu-desc">Apotek & obat</div>
                    </div>
                    <div class="menu-arrow">
                        <i class="bi bi-arrow-right"></i>
                    </div>
                </div>
            </a>
            
            <!-- Setting -->
            <a href="<?= boleh('setting') ? 'setting_dashboard.php' : '#' ?>"
               class="menu-item menu-setting <?= boleh('setting') ? '' : 'disabled' ?>">
                <div class="menu-content">
                    <div class="menu-icon-wrapper">
                        <i class="bi bi-gear-fill"></i>
                    </div>
                    <div class="menu-text">
                        <div class="menu-title">Setting</div>
                        <div class="menu-desc">Pengaturan sistem</div>
                    </div>
                    <div class="menu-arrow">
                        <i class="bi bi-arrow-right"></i>
                    </div>
                </div>
            </a>
            
            <!-- Tentang MediFix -->
            <a href="#" data-bs-toggle="modal" data-bs-target="#tentangModal"
               class="menu-item menu-tentang">
                <div class="menu-content">
                    <div class="menu-icon-wrapper">
                        <i class="bi bi-info-circle-fill"></i>
                    </div>
                    <div class="menu-text">
                        <div class="menu-title">Tentang MediFix</div>
                        <div class="menu-desc">Info & dukungan</div>
                    </div>
                    <div class="menu-arrow">
                        <i class="bi bi-arrow-right"></i>
                    </div>
                </div>
            </a>
            
        </div>
        
    </div>
</div>

<!-- Modal Tentang MediFix - COMPACT VERSION -->
<div class="modal fade" id="tentangModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-heart-pulse-fill"></i>
                    Tentang MediFix Apps
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                
                <div class="row g-3">
                    
                    <!-- Kolom Kiri -->
                    <div class="col-md-6">
                        
                        <!-- Logo & Deskripsi -->
                        <div style="text-align: center; margin-bottom: 16px;">
                            <div style="width: 60px; height: 60px; background: linear-gradient(135deg, #0ea5e9, #06b6d4); border-radius: 16px; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 10px;">
                                <i class="bi bi-hospital-fill" style="font-size: 30px; color: white;"></i>
                            </div>
                            <h5 style="font-weight: 800; color: #1e293b; margin-bottom: 6px; font-size: 18px;">MediFix Apps v1.0</h5>
                            <p style="color: #64748b; font-size: 11px; margin: 0;">
                                Sistem Anjungan & Antrian Terintegrasi SIMRS Khanza
                            </p>
                        </div>

                        <!-- Lisensi -->
                        <div class="info-box-compact">
                            <h6><i class="bi bi-shield-check-fill"></i> Lisensi & Ketentuan</h6>
                            <ul>
                                <li><strong>Aplikasi GRATIS</strong> untuk pengguna SIMRS Khanza</li>
                                <li><strong>DILARANG</strong> diperjualbelikan</li>
                                <li><strong>Open Source</strong> boleh dikembangkan,namun tidak mengubah nama aplikasi dan nama pemilik aplikasi</li>
                                <li><strong>Table</strong>: antrian_wira, loket_admisi_wira</li>
                            </ul>
                        </div>

                        <!-- Donasi -->
                        <div class="donation-box-compact">
                            <h6><i class="bi bi-heart-fill"></i> Dukung Pengembangan</h6>
                            <p>Aplikasi <strong>GRATIS</strong> selamanya. Donasi sukarela untuk pengembangan 🙏</p>
                            <div class="donation-rekening-compact">
                                <strong>7134197557</strong><br>
                                <small>BSI - a.n. M Wira Satria Buana</small>
                            </div>
                        </div>

                    </div>
                    
                    <!-- Kolom Kanan -->
                    <div class="col-md-6">
                        
                        <!-- Fitur Utama -->
                        <div class="info-box-compact">
                            <h6><i class="bi bi-star-fill"></i> Fitur Utama</h6>
                            <ul>
                                <li>Anjungan Pasien Mandiri</li>
                                <li>Antrian Admisi dengan Audio</li>
                                <li>Antrian Poliklinik Multi-Dokter</li>
                                <li>Antrian Farmasi</li>
                                <li>Display Real-time</li>
                                <li>Dashboard Monitor</li>
                            </ul>
                        </div>

                        <!-- Teknologi -->
                        <div class="info-box-compact" style="margin-bottom: 12px;">
                            <h6><i class="bi bi-code-slash"></i> Teknologi</h6>
                            <div class="tech-grid">
                                <div class="tech-item">
                                    <i class="bi bi-filetype-php" style="color: #777bb4;"></i>
                                    <div>PHP</div>
                                </div>
                                <div class="tech-item">
                                    <i class="bi bi-filetype-js" style="color: #f7df1e;"></i>
                                    <div>JavaScript</div>
                                </div>
                                <div class="tech-item">
                                    <i class="bi bi-bootstrap-fill" style="color: #7952b3;"></i>
                                    <div>Bootstrap</div>
                                </div>
                                <div class="tech-item">
                                    <i class="bi bi-database-fill" style="color: #00758f;"></i>
                                    <div>MySQL</div>
                                </div>
                            </div>
                        </div>

                        <!-- Developer -->
                        <div class="developer-box">
                            <p>
                                <strong>Dikembangkan dengan ❤️ oleh:</strong><br>
                                <span style="font-size: 14px; font-weight: 700; color: #1e293b;">M. Wira Satria Buana, S.Kom</span><br>
                                <i class="bi bi-whatsapp" style="color: #25D366;"></i> 
                                <strong style="color: #1e293b;">082177846209</strong><br>
                                <small style="color: #94a3b8;">© 2024 MediFix Apps</small>
                            </p>
                        </div>

                    </div>
                    
                </div>

            </div>
        </div>
    </div>
</div>

<!-- Bottom Bar -->
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