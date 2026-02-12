<?php
session_start();
include 'koneksi.php';
include 'koneksi2.php';

// Jika sudah login → arahkan ke dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

// Ambil informasi Rumah Sakit
try {
    $stmt = $pdo_simrs->query("SELECT * FROM setting LIMIT 1");
    $rs = $stmt->fetch(PDO::FETCH_ASSOC);
    $namaRS      = $rs['nama_instansi'] ?? 'Nama Rumah Sakit';
    $alamatRS    = $rs['alamat_instansi'] ?? '';
    $kabupatenRS = $rs['kabupaten'] ?? '';
    $propinsiRS  = $rs['propinsi'] ?? '';
} catch (Exception $e) {
    $namaRS = 'Nama Rumah Sakit';
    $alamatRS = $kabupatenRS = $propinsiRS = '';
}

$error = '';
$success = '';

/* =========================
        PROSES LOGIN
========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {

    $login    = trim($_POST['login']);
    $password = trim($_POST['password']);

    if ($login === '' || $password === '') {
        $error = "Email/NIK dan Password wajib diisi.";
    } else {

        $stmt = filter_var($login, FILTER_VALIDATE_EMAIL)
            ? $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1")
            : $pdo->prepare("SELECT * FROM users WHERE nik = ? LIMIT 1");

        $stmt->execute([$login]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['nama']    = $user['nama'];
            $_SESSION['email']   = $user['email'];

            header("Location: dashboard.php");
            exit;

        } else {
            $error = "Email/NIK atau Password salah.";
        }
    }
}


/* =========================
        PROSES REGISTER
========================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_submit'])) {

    $nik      = trim($_POST['nik']);
    $nama     = trim($_POST['nama']);
    $email    = trim($_POST['email']);
    $hp       = trim($_POST['hp']);
    $password = trim($_POST['password']);
    $confirm  = trim($_POST['confirm']);

    if (!$nik || !$nama || !$email || !$password || !$confirm) {
        $error = 'Semua field wajib diisi.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid.';
    } elseif ($password !== $confirm) {
        $error = 'Konfirmasi password tidak sama.';
    } else {
        $cek = $pdo->prepare("SELECT COUNT(*) FROM users WHERE nik = ? OR email = ?");
        $cek->execute([$nik, $email]);

        if ($cek->fetchColumn() > 0) {
            $error = 'NIK atau Email sudah terdaftar.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $pdo->prepare("INSERT INTO users (nik, nama, email, hp, password) VALUES (?, ?, ?, ?, ?)")
                ->execute([$nik, $nama, $email, $hp, $hash]);

            $success = 'Pendaftaran berhasil! Silakan login.';
        }
    }
}
?>

<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MediFix - Login</title>

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
    --secondary: #06b6d4;
    --success: #10b981;
    --danger: #ef4444;
    --warning: #f59e0b;
    --dark: #1e293b;
}

body {
    font-family: 'Inter', sans-serif;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    position: relative;
    overflow: hidden;
}

/* Animated Background */
.bg-animated {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 0;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.bg-animated::before,
.bg-animated::after {
    content: '';
    position: absolute;
    border-radius: 50%;
}

.bg-animated::before {
    width: 800px;
    height: 800px;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.15) 0%, transparent 70%);
    top: -400px;
    right: -200px;
    animation: float1 20s ease-in-out infinite;
}

.bg-animated::after {
    width: 600px;
    height: 600px;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.15) 0%, transparent 70%);
    bottom: -300px;
    left: -200px;
    animation: float2 25s ease-in-out infinite;
}

@keyframes float1 {
    0%, 100% { transform: translate(0, 0) rotate(0deg); }
    50% { transform: translate(50px, -50px) rotate(10deg); }
}

@keyframes float2 {
    0%, 100% { transform: translate(0, 0) rotate(0deg); }
    50% { transform: translate(-30px, 30px) rotate(-10deg); }
}

/* Login Container */
.login-container {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: 900px;
    animation: fadeInUp 0.6s ease-out;
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Login Card */
.login-card {
    background: rgba(255, 255, 255, 0.98);
    backdrop-filter: blur(20px);
    border-radius: 24px;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    display: flex;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

/* Left Panel */
.left-panel {
    width: 45%;
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    padding: 50px 40px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    position: relative;
    overflow: hidden;
}

.left-panel::before {
    content: '';
    position: absolute;
    width: 300px;
    height: 300px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 50%;
    top: -150px;
    right: -150px;
}

.logo-section {
    position: relative;
    z-index: 1;
    text-align: center;
}

.logo-wrapper {
    width: 100px;
    height: 100px;
    background: rgba(255, 255, 255, 0.2);
    backdrop-filter: blur(10px);
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
}

.logo-wrapper img {
    width: 70px;
    height: 70px;
    object-fit: contain;
}

.app-title {
    font-size: 32px;
    font-weight: 900;
    color: white;
    margin-bottom: 8px;
    text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.2);
}

.app-subtitle {
    font-size: 14px;
    color: rgba(255, 255, 255, 0.9);
    font-weight: 500;
}

.rs-info {
    position: relative;
    z-index: 1;
    background: rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(10px);
    padding: 20px;
    border-radius: 16px;
    text-align: center;
}

.rs-name {
    font-size: 16px;
    font-weight: 700;
    color: white;
    margin-bottom: 8px;
}

.rs-address {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.9);
    line-height: 1.5;
}

.btn-about {
    background: rgba(255, 255, 255, 0.2);
    backdrop-filter: blur(10px);
    border: 2px solid rgba(255, 255, 255, 0.3);
    color: white;
    padding: 12px 24px;
    border-radius: 12px;
    font-weight: 700;
    font-size: 14px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    transition: all 0.3s ease;
    margin-top: 20px;
}

.btn-about:hover {
    background: rgba(255, 255, 255, 0.3);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
    color: white;
}

.footer-left {
    position: relative;
    z-index: 1;
    text-align: center;
    color: rgba(255, 255, 255, 0.8);
    font-size: 12px;
}

/* Right Panel */
.right-panel {
    width: 55%;
    padding: 50px 40px;
}

.form-header {
    margin-bottom: 30px;
}

.form-title {
    font-size: 28px;
    font-weight: 800;
    color: var(--dark);
    margin-bottom: 8px;
}

.form-subtitle {
    font-size: 14px;
    color: #64748b;
}

/* Alert Styles */
.alert {
    border-radius: 12px;
    border: none;
    font-size: 14px;
    font-weight: 500;
    padding: 12px 16px;
    margin-bottom: 20px;
}

.alert-danger {
    background: #fee2e2;
    color: #991b1b;
}

.alert-success {
    background: #d1fae5;
    color: #065f46;
}

/* Form Styles */
.input-group {
    margin-bottom: 16px;
}

.input-group-text {
    background: #f1f5f9;
    border: none;
    border-radius: 12px 0 0 12px;
    padding: 12px 16px;
    color: #64748b;
}

.form-control {
    background: #f8fafc;
    border: 2px solid #e2e8f0;
    border-radius: 0 12px 12px 0;
    padding: 12px 16px;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.3s ease;
}

.form-control:focus {
    background: white;
    border-color: var(--primary);
    box-shadow: 0 0 0 4px rgba(14, 165, 233, 0.1);
}

.btn-submit {
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    border: none;
    border-radius: 12px;
    color: white;
    font-weight: 700;
    font-size: 15px;
    padding: 14px;
    width: 100%;
    transition: all 0.3s ease;
    box-shadow: 0 4px 16px rgba(14, 165, 233, 0.3);
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(14, 165, 233, 0.4);
}

.form-switch-text {
    text-align: center;
    margin-top: 20px;
    font-size: 14px;
    color: #64748b;
}

.form-switch-text a {
    color: var(--primary);
    font-weight: 700;
    text-decoration: none;
    transition: color 0.3s ease;
}

.form-switch-text a:hover {
    color: var(--secondary);
}

.hidden {
    display: none !important;
}

/* Modal Styles */
.modal-content {
    border-radius: 20px;
    border: none;
    overflow: hidden;
}

.modal-header {
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    color: white;
    border: none;
    padding: 20px 24px;
}

.modal-title {
    font-weight: 800;
    font-size: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.modal-body {
    padding: 24px;
    max-height: 70vh;
    overflow-y: auto;
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

/* Responsive */
@media (max-width: 768px) {
    .login-card {
        flex-direction: column;
    }
    
    .left-panel,
    .right-panel {
        width: 100%;
    }
    
    .left-panel {
        padding: 30px 20px;
    }
    
    .right-panel {
        padding: 30px 20px;
    }
    
    .logo-wrapper {
        width: 80px;
        height: 80px;
    }
    
    .logo-wrapper img {
        width: 50px;
        height: 50px;
    }
    
    .app-title {
        font-size: 24px;
    }
}
</style>

</head>
<body>

<div class="bg-animated"></div>

<div class="login-container">
    <div class="login-card">
        
        <!-- Left Panel -->
        <div class="left-panel">
            <div class="logo-section">
                <div class="logo-wrapper">
                    <img src="image/logo.png" alt="Logo">
                </div>
                <div class="app-title">MediFix</div>
                <div class="app-subtitle">Anjungan Pasien Mandiri & Sistem Antrian</div>
                
                <button type="button" class="btn-about" data-bs-toggle="modal" data-bs-target="#aboutModal">
                    <i class="bi bi-info-circle-fill"></i>
                    Tentang MediFix
                </button>
            </div>
            <br>
            
            <div class="rs-info">
                <div class="rs-name"><?= htmlspecialchars($namaRS) ?></div>
                <div class="rs-address">
                    <?= htmlspecialchars($alamatRS) ?><br>
                    <?= htmlspecialchars($kabupatenRS) ?> - <?= htmlspecialchars($propinsiRS) ?>
                </div>
            </div>
            <br>
            
            <div class="footer-left">
                <i class="bi bi-whatsapp"></i> 082177846209<br>
                © <?= date('Y') ?> MediFix Apps
            </div>
        </div>
        
        <!-- Right Panel -->
        <div class="right-panel">
            
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-circle-fill"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php elseif ($success): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle-fill"></i>
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>
            
            <!-- Login Form -->
            <form id="loginForm" method="post" <?= isset($_POST['register_submit']) ? 'class="hidden"' : '' ?>>
                <div class="form-header">
                    <div class="form-title">Selamat Datang! 👋</div>
                    <div class="form-subtitle">Silakan login untuk melanjutkan</div>
                </div>
                
                <div class="input-group">
                    <span class="input-group-text">
                        <i class="bi bi-person-fill"></i>
                    </span>
                    <input type="text" name="login" class="form-control" placeholder="Email atau NIK" required>
                </div>
                
                <div class="input-group">
                    <span class="input-group-text">
                        <i class="bi bi-lock-fill"></i>
                    </span>
                    <input type="password" name="password" class="form-control" placeholder="Password" required>
                </div>
                
                <button type="submit" name="login_submit" class="btn-submit">
                    <i class="bi bi-box-arrow-in-right"></i> Masuk Sekarang
                </button>
                
                <div class="form-switch-text">
                    Belum punya akun? <a href="#" id="showRegister">Daftar di sini</a>
                </div>
            </form>
            
            <!-- Register Form -->
            <form id="registerForm" method="post" class="<?= isset($_POST['register_submit']) ? '' : 'hidden' ?>">
                <div class="form-header">
                    <div class="form-title">Buat Akun Baru 📝</div>
                    <div class="form-subtitle">Isi data di bawah untuk mendaftar</div>
                </div>
                
                <div class="row g-2">
                    <div class="col-6">
                        <input name="nik" class="form-control" placeholder="NIK" required style="border-radius: 12px;">
                    </div>
                    <div class="col-6">
                        <input name="nama" class="form-control" placeholder="Nama Lengkap" required style="border-radius: 12px;">
                    </div>
                    <div class="col-6">
                        <input name="email" class="form-control" placeholder="Email" required style="border-radius: 12px;">
                    </div>
                    <div class="col-6">
                        <input name="hp" class="form-control" placeholder="Nomor HP" style="border-radius: 12px;">
                    </div>
                    <div class="col-6">
                        <input type="password" name="password" class="form-control" placeholder="Password" required style="border-radius: 12px;">
                    </div>
                    <div class="col-6">
                        <input type="password" name="confirm" class="form-control" placeholder="Konfirmasi Password" required style="border-radius: 12px;">
                    </div>
                </div>
                
                <button type="submit" name="register_submit" class="btn-submit mt-3">
                    <i class="bi bi-person-plus-fill"></i> Daftar Sekarang
                </button>
                
                <div class="form-switch-text">
                    Sudah punya akun? <a href="#" id="showLogin">Login di sini</a>
                </div>
            </form>
            
        </div>
        
    </div>
</div>

<!-- Modal Tentang MediFix -->
<div class="modal fade" id="aboutModal" tabindex="-1">
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
                        
                        <div style="text-align: center; margin-bottom: 16px;">
                            <div style="width: 60px; height: 60px; background: linear-gradient(135deg, #0ea5e9, #06b6d4); border-radius: 16px; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 10px;">
                                <i class="bi bi-hospital-fill" style="font-size: 30px; color: white;"></i>
                            </div>
                            <h5 style="font-weight: 800; color: #1e293b; margin-bottom: 6px; font-size: 18px;">MediFix Apps v1.0</h5>
                            <p style="color: #64748b; font-size: 11px; margin: 0;">
                                Sistem Anjungan & Antrian Terintegrasi SIMRS Khanza
                            </p>
                        </div>

                        <div class="info-box-compact">
                            <h6><i class="bi bi-shield-check-fill"></i> Lisensi & Ketentuan</h6>
                            <ul>
                                <li><strong>Aplikasi GRATIS</strong> untuk pengguna SIMRS Khanza</li>
                                <li><strong>DILARANG</strong> diperjualbelikan</li>
                                <li><strong>Open Source</strong> boleh dikembangkan</li>
                                <li><strong>Table</strong>:antrian_wira, loket_admisi_wira</li>
                            </ul>
                        </div>

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

                        <div class="info-box-compact" style="margin-bottom: 12px;">
                            <h6><i class="bi bi-code-slash"></i> Teknologi</h6>
                            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-top: 8px;">
                                <div style="background: white; padding: 8px; border-radius: 8px; text-align: center; border: 2px solid #e2e8f0;">
                                    <i class="bi bi-filetype-php" style="font-size: 20px; color: #777bb4;"></i>
                                    <div style="font-size: 9px; font-weight: 700; color: #1e293b; margin-top: 4px;">PHP</div>
                                </div>
                                <div style="background: white; padding: 8px; border-radius: 8px; text-align: center; border: 2px solid #e2e8f0;">
                                    <i class="bi bi-filetype-js" style="font-size: 20px; color: #f7df1e;"></i>
                                    <div style="font-size: 9px; font-weight: 700; color: #1e293b; margin-top: 4px;">JavaScript</div>
                                </div>
                                <div style="background: white; padding: 8px; border-radius: 8px; text-align: center; border: 2px solid #e2e8f0;">
                                    <i class="bi bi-bootstrap-fill" style="font-size: 20px; color: #7952b3;"></i>
                                    <div style="font-size: 9px; font-weight: 700; color: #1e293b; margin-top: 4px;">Bootstrap</div>
                                </div>
                                <div style="background: white; padding: 8px; border-radius: 8px; text-align: center; border: 2px solid #e2e8f0;">
                                    <i class="bi bi-database-fill" style="font-size: 20px; color: #00758f;"></i>
                                    <div style="font-size: 9px; font-weight: 700; color: #1e293b; margin-top: 4px;">MySQL</div>
                                </div>
                            </div>
                        </div>

                        <div style="text-align: center; padding: 16px; background: linear-gradient(135deg, #f8fafc, #f1f5f9); border-radius: 10px; border: 2px solid #e2e8f0;">
                            <p style="font-size: 11px; color: #64748b; margin: 0;">
                                <strong style="color: #1e293b;">Dikembangkan dengan ❤️ oleh:</strong><br>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById("showRegister")?.addEventListener("click", (e) => {
    e.preventDefault();
    document.getElementById("loginForm").classList.add("hidden");
    document.getElementById("registerForm").classList.remove("hidden");
});

document.getElementById("showLogin")?.addEventListener("click", (e) => {
    e.preventDefault();
    document.getElementById("registerForm").classList.add("hidden");
    document.getElementById("loginForm").classList.remove("hidden");
});
</script>

</body>
</html>