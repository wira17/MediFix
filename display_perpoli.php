<?php
session_start();
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

// Cek login (opsional, bisa dihapus jika tidak perlu)
$nama = $_SESSION['nama'] ?? 'Admin';

$today = date('Y-m-d');

try {
    $excluded_poli = ['IGDK','PL013','PL014','PL015','PL016','PL017','U0022','U0030'];
    $excluded_list = "'" . implode("','", $excluded_poli) . "'";

    // Ambil daftar poli dan dokter yang aktif hari ini
    $sql_poli = "
        SELECT 
            p.kd_poli, 
            p.nm_poli,
            d.kd_dokter,
            d.nm_dokter,
            COUNT(r.no_reg) as total_pasien,
            SUM(CASE WHEN r.stts = 'Sudah' THEN 1 ELSE 0 END) as sudah_dilayani,
            SUM(CASE WHEN r.stts IN ('Menunggu','Belum') THEN 1 ELSE 0 END) as menunggu
        FROM reg_periksa r
        JOIN poliklinik p ON r.kd_poli = p.kd_poli
        JOIN dokter d ON r.kd_dokter = d.kd_dokter
        WHERE r.tgl_registrasi = :tgl
          AND r.kd_poli NOT IN ($excluded_list)
        GROUP BY p.kd_poli, p.nm_poli, d.kd_dokter, d.nm_dokter
        ORDER BY p.nm_poli ASC, d.nm_dokter ASC";
    $stmt_poli = $pdo_simrs->prepare($sql_poli);
    $stmt_poli->bindValue(':tgl', $today);
    $stmt_poli->execute();
    $daftar_poli = $stmt_poli->fetchAll(PDO::FETCH_ASSOC);
    
    // Group by poli untuk hitung jumlah dokter
    $poli_groups = [];
    foreach ($daftar_poli as $item) {
        $kd_poli = $item['kd_poli'];
        if (!isset($poli_groups[$kd_poli])) {
            $poli_groups[$kd_poli] = [
                'nm_poli' => $item['nm_poli'],
                'dokters' => [],
                'total_pasien' => 0,
                'sudah_dilayani' => 0,
                'menunggu' => 0
            ];
        }
        $poli_groups[$kd_poli]['dokters'][] = $item;
        $poli_groups[$kd_poli]['total_pasien'] += $item['total_pasien'];
        $poli_groups[$kd_poli]['sudah_dilayani'] += $item['sudah_dilayani'];
        $poli_groups[$kd_poli]['menunggu'] += $item['menunggu'];
    }
} catch (PDOException $e) {
    die("Gagal mengambil data: " . $e->getMessage());
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pilih Display Poliklinik - MediFix</title>
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
    --primary: #8b5cf6;
    --primary-dark: #7c3aed;
    --secondary: #a78bfa;
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
    box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
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
    background: linear-gradient(90deg, var(--primary), var(--secondary), #c4b5fd);
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

/* Poli Grid */
.poli-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
}

/* Poli Card - Single Doctor */
.poli-card {
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
    animation: fadeInUp 0.5s ease-out backwards;
}

.poli-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: var(--card-color);
    transform: scaleX(0);
    transition: transform 0.3s ease;
}

.poli-card:hover::before {
    transform: scaleX(1);
}

.poli-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 24px rgba(0, 0, 0, 0.12);
    border-color: var(--card-color);
}

.poli-content {
    padding: 24px;
}

.poli-icon-wrapper {
    width: 56px;
    height: 56px;
    background: var(--card-bg);
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 16px;
    transition: all 0.3s ease;
}

.poli-card:hover .poli-icon-wrapper {
    transform: scale(1.1) rotate(-5deg);
    background: var(--card-color);
}

.poli-icon {
    font-size: 28px;
    color: var(--card-color);
    transition: color 0.3s ease;
}

.poli-card:hover .poli-icon {
    color: white;
}

.poli-name {
    font-size: 18px;
    font-weight: 700;
    color: var(--dark);
    margin-bottom: 8px;
}

.dokter-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: linear-gradient(135deg, #f5f3ff, #ede9fe);
    color: var(--primary);
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 16px;
}

.poli-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 16px;
}

.stat-box {
    text-align: center;
    padding: 12px 8px;
    background: #f8fafc;
    border-radius: 10px;
}

.stat-label {
    font-size: 10px;
    color: #64748b;
    font-weight: 600;
    text-transform: uppercase;
    margin-bottom: 4px;
}

.stat-value {
    font-size: 20px;
    font-weight: 800;
    color: var(--dark);
}

.stat-value.total { color: #3b82f6; }
.stat-value.done { color: #10b981; }
.stat-value.waiting { color: #f59e0b; }

.poli-action {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px;
    background: linear-gradient(135deg, #f8fafc, #f1f5f9);
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    color: var(--card-color);
    transition: all 0.3s ease;
}

.poli-card:hover .poli-action {
    background: var(--card-color);
    color: white;
}

.poli-action i {
    transition: transform 0.3s ease;
}

.poli-card:hover .poli-action i {
    transform: translateX(4px);
}

/* Poli Card - Multiple Doctors */
.poli-card-group {
    background: white;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    border: 1px solid rgba(0, 0, 0, 0.05);
    position: relative;
    overflow: hidden;
    animation: fadeInUp 0.5s ease-out backwards;
}

.poli-card-group::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: var(--card-color);
}

.dokter-count-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    color: #1e40af;
    padding: 8px 14px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    margin: 16px 0;
}

.dokter-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 2px dashed #f1f5f9;
}

.dokter-list-title {
    font-size: 12px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
}

.dokter-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    background: #f8fafc;
    border-radius: 10px;
    text-decoration: none;
    color: inherit;
    transition: all 0.3s ease;
    border: 2px solid transparent;
}

.dokter-item:hover {
    background: linear-gradient(135deg, #f5f3ff, #ede9fe);
    border-color: var(--primary);
    transform: translateX(4px);
}

.dokter-item-icon {
    width: 36px;
    height: 36px;
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 16px;
}

.dokter-item-info {
    flex: 1;
}

.dokter-item-name {
    font-size: 14px;
    font-weight: 700;
    color: var(--dark);
    margin-bottom: 2px;
}

.dokter-item-stats {
    display: flex;
    gap: 10px;
    font-size: 11px;
    font-weight: 600;
}

.dokter-item-stats span {
    display: flex;
    align-items: center;
    gap: 4px;
}

.dokter-item-stats .waiting {
    color: #f59e0b;
}

.dokter-item-arrow {
    font-size: 20px;
    color: #cbd5e1;
    transition: all 0.3s ease;
}

.dokter-item:hover .dokter-item-arrow {
    color: var(--primary);
    transform: translateX(4px);
}

.poli-action-all {
    margin-top: 16px;
    padding: 14px;
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    color: white !important;
    border-radius: 10px;
    text-decoration: none;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-size: 14px;
    font-weight: 700;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
}

.poli-action-all:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(139, 92, 246, 0.4);
}

/* Color Variations */
.color-purple {
    --card-color: #8b5cf6;
    --card-bg: #f5f3ff;
}

.color-blue {
    --card-color: #3b82f6;
    --card-bg: #eff6ff;
}

.color-green {
    --card-color: #10b981;
    --card-bg: #ecfdf5;
}

.color-orange {
    --card-color: #f59e0b;
    --card-bg: #fffbeb;
}

.color-red {
    --card-color: #ef4444;
    --card-bg: #fef2f2;
}

.color-indigo {
    --card-color: #6366f1;
    --card-bg: #eef2ff;
}

.color-pink {
    --card-color: #ec4899;
    --card-bg: #fdf2f8;
}

.color-cyan {
    --card-color: #06b6d4;
    --card-bg: #ecfeff;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: white;
    border-radius: 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    border: 1px solid rgba(0, 0, 0, 0.05);
}

.empty-icon {
    width: 80px;
    height: 80px;
    background: #f1f5f9;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
}

.empty-icon i {
    font-size: 40px;
    color: #cbd5e1;
}

.empty-title {
    font-size: 20px;
    font-weight: 700;
    color: var(--dark);
    margin-bottom: 8px;
}

.empty-text {
    color: #64748b;
    font-size: 14px;
}

/* Animations */
.poli-card:nth-child(1), .poli-card-group:nth-child(1) { animation-delay: 0.1s; }
.poli-card:nth-child(2), .poli-card-group:nth-child(2) { animation-delay: 0.15s; }
.poli-card:nth-child(3), .poli-card-group:nth-child(3) { animation-delay: 0.2s; }
.poli-card:nth-child(4), .poli-card-group:nth-child(4) { animation-delay: 0.25s; }
.poli-card:nth-child(5), .poli-card-group:nth-child(5) { animation-delay: 0.3s; }
.poli-card:nth-child(6), .poli-card-group:nth-child(6) { animation-delay: 0.35s; }

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

/* Responsive */
@media (max-width: 992px) {
    .poli-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 16px;
    }
}

@media (max-width: 576px) {
    .poli-grid {
        grid-template-columns: 1fr;
    }
    
    .page-header {
        padding: 20px;
    }
    
    .page-title {
        font-size: 22px;
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
        
      
        
        <!-- Poli Grid -->
        <?php if (count($poli_groups) > 0): ?>
            <div class="poli-grid">
                <?php 
                $colors = ['color-purple', 'color-blue', 'color-green', 'color-orange', 'color-red', 'color-indigo', 'color-pink', 'color-cyan'];
                $colorIndex = 0;
                foreach ($poli_groups as $kd_poli => $poli): 
                    $colorClass = $colors[$colorIndex % count($colors)];
                    $colorIndex++;
                ?>
                
                    <?php if (count($poli['dokters']) == 1): ?>
                        <?php $dok = $poli['dokters'][0]; ?>
                        <!-- Single Doctor Card -->
                        <a href="display_poli.php?poli=<?= htmlspecialchars($kd_poli) ?>&dokter=<?= htmlspecialchars($dok['kd_dokter']) ?>" 
                           target="_blank" 
                           class="poli-card <?= $colorClass ?>">
                            
                            <div class="poli-content">
                                <div class="poli-icon-wrapper">
                                    <i class="bi bi-hospital poli-icon"></i>
                                </div>
                                
                                <div class="poli-name">
                                    <?= htmlspecialchars($poli['nm_poli']) ?>
                                </div>
                                
                                <div class="dokter-badge">
                                    <i class="bi bi-person-badge"></i>
                                    <?= htmlspecialchars($dok['nm_dokter']) ?>
                                </div>
                                
                                <div class="poli-stats">
                                    <div class="stat-box">
                                        <div class="stat-label">Total</div>
                                        <div class="stat-value total"><?= $dok['total_pasien'] ?></div>
                                    </div>
                                    <div class="stat-box">
                                        <div class="stat-label">Selesai</div>
                                        <div class="stat-value done"><?= $dok['sudah_dilayani'] ?></div>
                                    </div>
                                    <div class="stat-box">
                                        <div class="stat-label">Tunggu</div>
                                        <div class="stat-value waiting"><?= $dok['menunggu'] ?></div>
                                    </div>
                                </div>
                                
                                <div class="poli-action">
                                    <span>Tampilkan Display</span>
                                    <i class="bi bi-arrow-right-circle-fill"></i>
                                </div>
                            </div>
                        </a>
                        
                    <?php else: ?>
                        <!-- Multiple Doctors Card -->
                        <div class="poli-card-group <?= $colorClass ?>">
                            <div class="poli-icon-wrapper">
                                <i class="bi bi-hospital poli-icon"></i>
                            </div>
                            
                            <div class="poli-name">
                                <?= htmlspecialchars($poli['nm_poli']) ?>
                            </div>
                            
                            <div class="dokter-count-badge">
                                <i class="bi bi-people-fill"></i>
                                <?= count($poli['dokters']) ?> Dokter Praktek
                            </div>
                            
                            <div class="poli-stats">
                                <div class="stat-box">
                                    <div class="stat-label">Total</div>
                                    <div class="stat-value total"><?= $poli['total_pasien'] ?></div>
                                </div>
                                <div class="stat-box">
                                    <div class="stat-label">Selesai</div>
                                    <div class="stat-value done"><?= $poli['sudah_dilayani'] ?></div>
                                </div>
                                <div class="stat-box">
                                    <div class="stat-label">Tunggu</div>
                                    <div class="stat-value waiting"><?= $poli['menunggu'] ?></div>
                                </div>
                            </div>
                            
                            <div class="dokter-list">
                                <div class="dokter-list-title">
                                    <i class="bi bi-list-ul"></i> Pilih Dokter:
                                </div>
                                <?php foreach ($poli['dokters'] as $dok): ?>
                                    <a href="display_poli.php?poli=<?= htmlspecialchars($kd_poli) ?>&dokter=<?= htmlspecialchars($dok['kd_dokter']) ?>" 
                                       target="_blank"
                                       class="dokter-item">
                                        <div class="dokter-item-icon">
                                            <i class="bi bi-person-badge"></i>
                                        </div>
                                        <div class="dokter-item-info">
                                            <div class="dokter-item-name"><?= htmlspecialchars($dok['nm_dokter']) ?></div>
                                            <div class="dokter-item-stats">
                                                <span class="waiting">
                                                    <i class="bi bi-hourglass-split"></i>
                                                    <?= $dok['menunggu'] ?> menunggu
                                                </span>
                                            </div>
                                        </div>
                                        <i class="bi bi-arrow-right-circle-fill dokter-item-arrow"></i>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                            
                            <a href="display_poli.php?poli=<?= htmlspecialchars($kd_poli) ?>" 
                               target="_blank"
                               class="poli-action-all">
                                <i class="bi bi-grid-3x3-gap-fill"></i>
                                <span>Tampilkan Semua Dokter</span>
                            </a>
                        </div>
                    <?php endif; ?>
                    
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon">
                    <i class="bi bi-inbox"></i>
                </div>
                <div class="empty-title">Tidak Ada Poliklinik Aktif</div>
                <div class="empty-text">
                    Belum ada poliklinik yang memiliki pasien hari ini
                </div>
            </div>
        <?php endif; ?>
        
    </div>
</div>

<!-- Bottom Bar -->
<div class="bottom-bar">
    <div class="container">
        <div class="footer-content">
            <i class="bi bi-hospital-fill"></i>
            MediFix - Sistem Display Antrian Poliklinik © <?= date('Y') ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Update Clock
function updateClock() {
    const now = new Date();
    const time = now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    document.getElementById('clockDisplay').textContent = time;
}

setInterval(updateClock, 1000);
updateClock();

// Auto refresh setiap 30 detik untuk update data poli
setInterval(() => {
    location.reload();
}, 30000);
</script>
</body>
</html>