<?php
session_start();
include 'koneksi.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$nama = $_SESSION['nama'] ?? 'Pengguna';
date_default_timezone_set('Asia/Jakarta');

$data = $pdo->query("SELECT * FROM setting_frista LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$success = $error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['frista_username']);
    $password = trim($_POST['frista_password']);

    if($username && $password){
        $stmt = $pdo->prepare("UPDATE setting_frista SET username=?, password=?, updated_at=NOW() WHERE id=1");
        $stmt->execute([$username, $password]);

        $success = "✔ Setting Frista berhasil diperbarui!";
        $data = $pdo->query("SELECT * FROM setting_frista LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    } else {
        $error = "⚠ Semua field wajib diisi.";
    }
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Setting Frista BPJS - MediFix</title>
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
    --info: #06b6d4;
    --pink: #ec4899;
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
    font-size: 18px;
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
    border-radius: 16px;
    padding: 20px;
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
    height: 3px;
    background: linear-gradient(90deg, var(--pink), #f472b6, #fb7185);
}

.page-title {
    font-size: 20px;
    font-weight: 700;
    color: var(--dark);
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.page-title i {
    font-size: 24px;
    background: linear-gradient(135deg, var(--pink), #f472b6);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.page-subtitle {
    color: #64748b;
    font-size: 12px;
    font-weight: 500;
}

/* Alert */
.alert-custom {
    border-radius: 12px;
    padding: 14px 20px;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 20px;
    animation: slideDown 0.4s ease;
    border: none;
    display: flex;
    align-items: center;
    gap: 10px;
}

.alert-success {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.alert-danger {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Content Wrapper */
.content-wrapper {
    display: grid;
    grid-template-columns: 340px 1fr;
    gap: 20px;
    max-width: 900px;
    margin: 0 auto;
}

/* Data Card */
.data-card {
    background: white;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    border: 1px solid rgba(0, 0, 0, 0.05);
}

.data-card-title {
    font-size: 16px;
    font-weight: 700;
    color: var(--dark);
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.data-card-title i {
    color: var(--pink);
    font-size: 20px;
}

.info-item {
    background: #fdf2f8;
    padding: 14px 16px;
    border-radius: 12px;
    margin-bottom: 12px;
    border-left: 3px solid var(--pink);
    display: flex;
    align-items: center;
    gap: 12px;
}

.info-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, var(--pink), #f472b6);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 20px;
    flex-shrink: 0;
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
    font-size: 14px;
    font-weight: 600;
    color: var(--dark);
}

/* Form Card */
.form-card {
    background: white;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    border: 1px solid rgba(0, 0, 0, 0.05);
}

.form-card-title {
    font-size: 16px;
    font-weight: 700;
    color: var(--dark);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.form-card-title i {
    color: var(--warning);
    font-size: 20px;
}

.form-group {
    margin-bottom: 16px;
}

.form-group label {
    font-size: 13px;
    font-weight: 600;
    color: var(--dark);
    margin-bottom: 6px;
    display: block;
}

.form-group input {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    font-size: 13px;
    background: #f8fafc;
    transition: all 0.3s ease;
}

.form-group input:focus {
    outline: none;
    border-color: var(--pink);
    background: white;
    box-shadow: 0 0 0 3px rgba(236, 72, 153, 0.1);
}

/* Buttons */
.btn-action {
    padding: 12px 24px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    border: none;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    cursor: pointer;
    text-decoration: none;
}

.btn-save {
    background: linear-gradient(135deg, var(--success), #059669);
    color: white;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    flex: 1;
}

.btn-save:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4);
    color: white;
}

.btn-back {
    background: linear-gradient(135deg, #64748b, #475569);
    color: white;
    box-shadow: 0 4px 12px rgba(100, 116, 139, 0.3);
    flex: 1;
}

.btn-back:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(100, 116, 139, 0.4);
    color: white;
}

.btn-row {
    display: flex;
    gap: 12px;
    margin-top: 20px;
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
    font-size: 12px;
    color: #64748b;
    font-weight: 600;
}

.footer-logo {
    height: 24px;
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
    border-radius: 8px;
    font-size: 11px;
    font-weight: 700;
}

/* Responsive */
@media (max-width: 992px) {
    .content-wrapper {
        grid-template-columns: 1fr;
    }
    
    .user-badge span {
        display: none;
    }
}

@media (max-width: 768px) {
    .page-title {
        font-size: 18px;
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
                <i class="bi bi-credit-card-2-front"></i>
                Setting Frista BPJS
            </div>
            <div class="page-subtitle">
                Konfigurasi akses Frista (First Registration Station) BPJS Kesehatan
            </div>
        </div>
        
        <!-- Alert Success -->
        <?php if ($success): ?>
        <div class="alert-custom alert-success">
            <i class="bi bi-check-circle-fill"></i> <?= $success ?>
        </div>
        <?php endif; ?>
        
        <!-- Alert Error -->
        <?php if ($error): ?>
        <div class="alert-custom alert-danger">
            <i class="bi bi-exclamation-triangle-fill"></i> <?= $error ?>
        </div>
        <?php endif; ?>
        
        <!-- Content -->
        <div class="content-wrapper">
            
            <!-- Left: Data Card -->
            <div class="data-card">
                <div class="data-card-title">
                    <i class="bi bi-database-fill"></i>
                    Data Frista Aktif
                </div>
                
                <div class="info-item">
                    <div class="info-icon">
                        <i class="bi bi-person-circle"></i>
                    </div>
                    <div class="info-content">
                        <div class="info-label">Username</div>
                        <div class="info-value"><?= htmlspecialchars($data['username']) ?></div>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-icon">
                        <i class="bi bi-lock-fill"></i>
                    </div>
                    <div class="info-content">
                        <div class="info-label">Password</div>
                        <div class="info-value">••••••••••••••</div>
                    </div>
                </div>
            </div>
            
            <!-- Right: Form Card -->
            <div class="form-card">
                <div class="form-card-title">
                    <i class="bi bi-gear-fill"></i>
                    Form Pengaturan
                </div>
                
                <form method="POST">
                    <div class="form-group">
                        <label>Username Frista BPJS</label>
                        <input type="text" name="frista_username" value="<?= htmlspecialchars($data['username']) ?>" required placeholder="Masukkan username Frista">
                    </div>
                    
                    <div class="form-group">
                        <label>Password Frista BPJS</label>
                        <input type="password" name="frista_password" value="<?= htmlspecialchars($data['password']) ?>" required placeholder="Masukkan password Frista">
                    </div>
                    
                    <div class="btn-row">
                        <a href="setting_dashboard.php" class="btn-action btn-back">
                            <i class="bi bi-arrow-left-circle"></i> Kembali
                        </a>
                        <button type="submit" name="simpan" class="btn-action btn-save">
                            <i class="bi bi-save"></i> Simpan Perubahan
                        </button>
                    </div>
                </form>
            </div>
            
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
    const waktu = now.toLocaleTimeString('id-ID');
    document.getElementById('clockDisplay').innerHTML = waktu;
}

setInterval(updateClock, 1000);
updateClock();
</script>
</body>
</html>