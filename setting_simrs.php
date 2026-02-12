<?php
session_start();
include 'koneksi.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$nama = $_SESSION['nama'] ?? 'Pengguna';

// ==== Ambil Data ====
$data = $pdo->query("SELECT * FROM setting_simrs LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$success = $error = "";

// ==== Simpan Data ====
if (isset($_POST['simpan'])) {

    $nama_simrs = trim($_POST['nama_simrs']);
    $host       = trim($_POST['host']);
    $username   = trim($_POST['username']);
    $password   = trim($_POST['password']);
    $database   = trim($_POST['database_name']);

    if($nama_simrs && $host && $username && $database){

        // Cek apakah sudah ada data di tabel
        $cek = $pdo->query("SELECT COUNT(*) FROM setting_simrs")->fetchColumn();

        if ($cek == 0) {
            // INSERT pertama kali
            $stmt = $pdo->prepare("INSERT INTO setting_simrs (nama_simrs, host, username, password, database_name, updated_at) 
                                   VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$nama_simrs, $host, $username, $password, $database]);

        } else {
            // UPDATE jika sudah ada data
            $stmt = $pdo->prepare("UPDATE setting_simrs 
                SET nama_simrs=?, host=?, username=?, password=?, database_name=?, updated_at=NOW() WHERE id=1");
            $stmt->execute([$nama_simrs, $host, $username, $password, $database]);
        }

        $success = "✔ Setting SIMRS berhasil disimpan!";
        $data = $pdo->query("SELECT * FROM setting_simrs LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    } else {
        $error = "⚠ Semua field wajib diisi (kecuali password opsional).";
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Setting Koneksi SIMRS - MediFix</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

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

/* Navbar */
.top-bar {
    background: white;
    padding: 10px 0;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.brand {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 20px;
    font-weight: 800;
    color: #667eea;
}

.brand i {
    font-size: 24px;
}

.user-badge {
    display: flex;
    align-items: center;
    gap: 8px;
    background: #667eea;
    color: white;
    padding: 6px 16px;
    border-radius: 50px;
    font-size: 14px;
    font-weight: 600;
}

.user-badge i {
    font-size: 18px;
}

/* Main Container */
.main-wrapper {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px 20px 80px;
}

/* Page Header */
.page-header {
    text-align: center;
    color: white;
    margin-bottom: 20px;
}

.page-header h1 {
    font-size: 28px;
    font-weight: 800;
    margin-bottom: 5px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

.page-header h1 i {
    font-size: 32px;
}

.page-header p {
    font-size: 14px;
    opacity: 0.95;
}

/* Cards Container */
.cards-container {
    display: grid;
    grid-template-columns: 380px 1fr;
    gap: 20px;
    max-height: calc(100vh - 200px);
}

/* Info Card */
.info-card {
    background: white;
    border-radius: 20px;
    padding: 20px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
}

.card-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #f0f0f0;
}

.card-header i {
    font-size: 24px;
    color: #667eea;
}

.card-header h3 {
    font-size: 18px;
    font-weight: 700;
    margin: 0;
    color: #2d3748;
}

.info-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.info-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    background: #f8f9fc;
    border-radius: 12px;
    border-left: 4px solid #667eea;
}

.info-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #667eea, #764ba2);
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
    color: #718096;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 2px;
}

.info-value {
    font-size: 14px;
    font-weight: 700;
    color: #2d3748;
    word-break: break-all;
}

/* Form Card */
.form-card {
    background: white;
    border-radius: 20px;
    padding: 25px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
    max-height: calc(100vh - 200px);
    overflow-y: auto;
}

.form-card::-webkit-scrollbar {
    width: 6px;
}

.form-card::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

.form-card::-webkit-scrollbar-thumb {
    background: #667eea;
    border-radius: 10px;
}

.form-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #f0f0f0;
}

.form-header i {
    font-size: 24px;
    color: #f093fb;
}

.form-header h3 {
    font-size: 18px;
    font-weight: 700;
    margin: 0;
    color: #2d3748;
}

/* Alert */
.alert-box {
    padding: 12px 16px;
    border-radius: 12px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 600;
    font-size: 14px;
}

.alert-success {
    background: #d4fc79;
    color: #22543d;
    border-left: 4px solid #38a169;
}

.alert-error {
    background: #ffeaa7;
    color: #7c2d12;
    border-left: 4px solid #e53e3e;
}

/* Form Group */
.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    font-weight: 700;
    color: #2d3748;
    margin-bottom: 8px;
}

.form-group label i {
    font-size: 16px;
    color: #667eea;
}

.form-control {
    width: 100%;
    padding: 10px 14px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 500;
    color: #2d3748;
    background: #f8fafc;
    transition: all 0.3s ease;
}

.form-control:focus {
    outline: none;
    border-color: #667eea;
    background: white;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

/* Buttons */
.btn-group {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-top: 20px;
}

.btn {
    padding: 11px 24px;
    border: none;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    text-decoration: none;
    transition: all 0.3s ease;
}

.btn i {
    font-size: 18px;
}

.btn-secondary {
    background: #cbd5e0;
    color: #2d3748;
}

.btn-secondary:hover {
    background: #a0aec0;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.btn-primary {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
}

/* Footer */
.footer {
    position: fixed;
    bottom: 0;
    left: 0;
    width: 100%;
    background: white;
    padding: 10px 0;
    box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
    z-index: 1000;
}

.footer-content {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    font-size: 13px;
    font-weight: 600;
    color: #2d3748;
}

.footer-logo {
    height: 28px;
    border-radius: 6px;
}

.footer-content .bi-whatsapp {
    color: #25D366;
    font-size: 18px;
}

/* Responsive */
@media (max-width: 1200px) {
    .cards-container {
        grid-template-columns: 1fr;
        max-height: none;
    }
    
    .form-card {
        max-height: none;
    }
}

@media (max-width: 576px) {
    .page-header h1 {
        font-size: 22px;
    }
    
    .btn-group {
        grid-template-columns: 1fr;
    }
}
</style>
</head>

<body>

<!-- Navbar -->
<div class="top-bar">
    <div class="container">
        <div class="d-flex align-items-center justify-content-between">
            <div class="brand">
                <i class="bi bi-hospital"></i>
                <span>MediFix</span>
            </div>
            <div class="user-badge">
                <i class="bi bi-person-circle"></i>
                <span><?= htmlspecialchars($nama) ?></span>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<div class="main-wrapper">
    
    <!-- Page Header -->
    <div class="page-header">
        <h1>
            <i class="bi bi-gear-fill"></i>
            Pengaturan Koneksi SIMRS
        </h1>
        <p>Kelola konfigurasi database sistem informasi rumah sakit</p>
    </div>

    <!-- Cards Container -->
    <div class="cards-container">
        
        <!-- Info Card (Left) -->
        <div class="info-card">
            <div class="card-header">
                <i class="bi bi-database-fill-gear"></i>
                <h3>Informasi Database</h3>
            </div>

            <div class="info-list">
                <div class="info-row">
                    <div class="info-icon">
                        <i class="bi bi-building"></i>
                    </div>
                    <div class="info-content">
                        <div class="info-label">Nama SIMRS</div>
                        <div class="info-value"><?= htmlspecialchars($data['nama_simrs'] ?? '-') ?></div>
                    </div>
                </div>

                <div class="info-row">
                    <div class="info-icon">
                        <i class="bi bi-hdd-network"></i>
                    </div>
                    <div class="info-content">
                        <div class="info-label">Host Server</div>
                        <div class="info-value"><?= htmlspecialchars($data['host'] ?? '-') ?></div>
                    </div>
                </div>

                <div class="info-row">
                    <div class="info-icon">
                        <i class="bi bi-person-badge"></i>
                    </div>
                    <div class="info-content">
                        <div class="info-label">Username</div>
                        <div class="info-value"><?= htmlspecialchars($data['username'] ?? '-') ?></div>
                    </div>
                </div>

                <div class="info-row">
                    <div class="info-icon">
                        <i class="bi bi-shield-lock"></i>
                    </div>
                    <div class="info-content">
                        <div class="info-label">Password</div>
                        <div class="info-value">••••••••••••</div>
                    </div>
                </div>

                <div class="info-row">
                    <div class="info-icon">
                        <i class="bi bi-archive"></i>
                    </div>
                    <div class="info-content">
                        <div class="info-label">Nama Database</div>
                        <div class="info-value"><?= htmlspecialchars($data['database_name'] ?? '-') ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Form Card (Right) -->
        <div class="form-card">
            <div class="form-header">
                <i class="bi bi-gear-fill"></i>
                <h3>Konfigurasi Database</h3>
            </div>

            <?php if ($success): ?>
                <div class="alert-box alert-success">
                    <i class="bi bi-check-circle-fill"></i>
                    <span><?= $success ?></span>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert-box alert-error">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span><?= $error ?></span>
                </div>
            <?php endif; ?>

            <form method="post">
                
                <div class="form-group">
                    <label>
                        <i class="bi bi-building"></i>
                        Nama SIMRS
                    </label>
                    <input type="text" 
                           name="nama_simrs" 
                           class="form-control" 
                           value="<?= htmlspecialchars($data['nama_simrs'] ?? '') ?>" 
                           placeholder="SIMRS KHANZA"
                           required>
                </div>

                <div class="form-group">
                    <label>
                        <i class="bi bi-hdd-network"></i>
                        Host Database
                    </label>
                    <input type="text" 
                           name="host" 
                           class="form-control" 
                           value="<?= htmlspecialchars($data['host'] ?? '') ?>" 
                           placeholder="localhost"
                           required>
                </div>

                <div class="form-group">
                    <label>
                        <i class="bi bi-person-badge"></i>
                        Username Database
                    </label>
                    <input type="text" 
                           name="username" 
                           class="form-control" 
                           value="<?= htmlspecialchars($data['username'] ?? '') ?>" 
                           placeholder="root"
                           required>
                </div>

                <div class="form-group">
                    <label>
                        <i class="bi bi-shield-lock"></i>
                        Password Database
                    </label>
                    <input type="password" 
                           name="password" 
                           class="form-control" 
                           value="<?= htmlspecialchars($data['password'] ?? '') ?>" 
                           placeholder="••••••••••••">
                </div>

                <div class="form-group">
                    <label>
                        <i class="bi bi-archive"></i>
                        Nama Database
                    </label>
                    <input type="text" 
                           name="database_name" 
                           class="form-control" 
                           value="<?= htmlspecialchars($data['database_name'] ?? '') ?>" 
                           placeholder="khanzaaptonline"
                           required>
                </div>

                <div class="btn-group">
                    <a href="setting_dashboard.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i>
                        <span>Kembali</span>
                    </a>
                    <button type="submit" name="simpan" class="btn btn-primary">
                        <i class="bi bi-save"></i>
                        <span>Simpan</span>
                    </button>
                </div>

            </form>
        </div>

    </div>
</div>

<!-- Footer -->
<footer class="footer">
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
</footer>


</body>
</html>