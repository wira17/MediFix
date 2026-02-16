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
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>MediFix - Login</title>
  <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
  
  <!-- Bootstrap 3.3.7 -->
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
  <!-- Google Font -->
  <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700,300italic,400italic,600italic">

  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    html, body {
      height: 100vh;
      overflow: hidden;
      font-family: 'Source Sans Pro', sans-serif;
    }

    body {
      background: linear-gradient(135deg, #3c8dbc 0%, #367fa9 50%, #2c6c91 100%);
      display: flex;
      align-items: center;
      justify-content: center;
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
    }

    .particle {
      position: absolute;
      background: rgba(255, 255, 255, 0.06);
      border-radius: 50%;
      animation: float 30s infinite ease-in-out;
    }

    .particle:nth-child(1) { width: 120px; height: 120px; top: 15%; left: 10%; animation-delay: 0s; }
    .particle:nth-child(2) { width: 90px; height: 90px; top: 65%; left: 80%; animation-delay: 5s; }
    .particle:nth-child(3) { width: 70px; height: 70px; top: 45%; left: 15%; animation-delay: 10s; }
    .particle:nth-child(4) { width: 100px; height: 100px; top: 25%; left: 75%; animation-delay: 15s; }
    .particle:nth-child(5) { width: 60px; height: 60px; top: 80%; left: 50%; animation-delay: 20s; }

    @keyframes float {
      0%, 100% { transform: translate(0, 0) scale(1); opacity: 0.3; }
      33% { transform: translate(40px, -50px) scale(1.1); opacity: 0.5; }
      66% { transform: translate(-30px, 30px) scale(0.9); opacity: 0.4; }
    }

    /* Main Container - Landscape & Fullscreen */
    .login-wrapper {
      position: relative;
      z-index: 1;
      width: 95%;
      max-width: 1400px;
      height: 85vh;
      max-height: 700px;
      background: white;
      border-radius: 20px;
      box-shadow: 0 25px 80px rgba(0, 0, 0, 0.4);
      overflow: hidden;
      display: flex;
      animation: slideIn 0.6s ease-out;
    }

    @keyframes slideIn {
      from { opacity: 0; transform: scale(0.95); }
      to { opacity: 1; transform: scale(1); }
    }

    /* Left Panel - Info - AdminLTE Blue Theme */
    .left-panel {
      flex: 0 0 45%;
      background: linear-gradient(135deg, #3c8dbc 0%, #367fa9 100%);
      padding: 50px 40px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      color: white;
      position: relative;
      overflow: hidden;
    }

    .left-panel::before {
      content: '';
      position: absolute;
      top: -50%;
      right: -30%;
      width: 400px;
      height: 400px;
      background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
      border-radius: 50%;
    }

    .left-panel::after {
      content: '';
      position: absolute;
      bottom: -30%;
      left: -20%;
      width: 350px;
      height: 350px;
      background: radial-gradient(circle, rgba(0, 166, 90, 0.15) 0%, transparent 70%);
      border-radius: 50%;
    }

    .branding {
      text-align: center;
      margin-bottom: 40px;
      position: relative;
      z-index: 1;
    }

    .branding-icon {
      width: 90px;
      height: 90px;
      background: rgba(255, 255, 255, 0.2);
      backdrop-filter: blur(10px);
      border-radius: 18px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 20px;
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
      border: 2px solid rgba(255, 255, 255, 0.3);
    }

    .branding-icon i {
      font-size: 45px;
      color: white;
    }

    .branding h1 {
      font-size: 38px;
      font-weight: 700;
      margin-bottom: 10px;
      text-shadow: 0 3px 12px rgba(0, 0, 0, 0.2);
    }

    .branding h1 b {
      font-weight: 800;
    }

    .branding p {
      font-size: 15px;
      opacity: 0.95;
      font-weight: 400;
    }

    .hospital-info-box {
      background: rgba(255, 255, 255, 0.15);
      backdrop-filter: blur(10px);
      border-radius: 15px;
      padding: 25px;
      border: 1px solid rgba(255, 255, 255, 0.25);
      position: relative;
      z-index: 1;
    }

    .hospital-info-box h4 {
      font-size: 17px;
      font-weight: 700;
      margin-bottom: 15px;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .hospital-info-box h4 i {
      font-size: 20px;
    }

    .hospital-info-box p {
      margin: 8px 0;
      font-size: 14px;
      opacity: 0.95;
      display: flex;
      align-items: flex-start;
      gap: 10px;
      line-height: 1.5;
    }

    .hospital-info-box p i {
      width: 16px;
      font-size: 13px;
      margin-top: 2px;
      flex-shrink: 0;
    }

    /* Right Panel - Form */
    .right-panel {
      flex: 1;
      padding: 50px 45px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      overflow-y: auto;
      background: #f9fafb;
    }

    .right-panel::-webkit-scrollbar {
      width: 6px;
    }

    .right-panel::-webkit-scrollbar-track {
      background: #f1f1f1;
    }

    .right-panel::-webkit-scrollbar-thumb {
      background: #3c8dbc;
      border-radius: 3px;
    }

    .form-title {
      margin-bottom: 30px;
    }

    .form-title h2 {
      font-size: 28px;
      font-weight: 700;
      color: #2c3e50;
      margin-bottom: 8px;
    }

    .form-title p {
      color: #7f8c8d;
      font-size: 14px;
    }

    /* Alerts */
    .alert {
      border-radius: 8px;
      border: none;
      padding: 12px 18px;
      margin-bottom: 20px;
      font-weight: 600;
      font-size: 14px;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .alert-danger {
      background: #f8d7da;
      color: #842029;
      border-left: 4px solid #dc3545;
    }

    .alert-success {
      background: #d1e7dd;
      color: #0f5132;
      border-left: 4px solid #00a65a;
    }

    .alert .close {
      margin-left: auto;
      opacity: 0.6;
      font-size: 20px;
      line-height: 1;
      color: inherit;
      background: transparent;
      border: none;
      cursor: pointer;
    }

    /* Form Elements */
    .form-group {
      margin-bottom: 18px;
    }

    .form-label {
      font-size: 13px;
      font-weight: 600;
      color: #555;
      margin-bottom: 6px;
      display: block;
    }

    .input-wrapper {
      position: relative;
    }

    .input-icon {
      position: absolute;
      left: 15px;
      top: 50%;
      transform: translateY(-50%);
      color: #95a5a6;
      font-size: 16px;
      z-index: 1;
    }

    .form-control {
      height: 45px;
      border-radius: 8px;
      border: 1px solid #dfe4ea;
      padding: 10px 15px 10px 42px;
      font-size: 14px;
      transition: all 0.3s ease;
      background: #fff;
      width: 100%;
    }

    .form-control:focus {
      border-color: #3c8dbc;
      box-shadow: 0 0 0 3px rgba(60, 141, 188, 0.1);
      outline: none;
    }

    /* Buttons - AdminLTE Colors */
    .btn-submit {
      height: 45px;
      border-radius: 8px;
      font-size: 15px;
      font-weight: 600;
      border: none;
      transition: all 0.3s ease;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      cursor: pointer;
      width: 100%;
    }

    .btn-primary {
      background-color: #3c8dbc;
      border-color: #367fa9;
      color: white;
    }

    .btn-primary:hover {
      background-color: #367fa9;
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(60, 141, 188, 0.3);
    }

    .btn-success {
      background-color: #00a65a;
      border-color: #008d4c;
      color: white;
    }

    .btn-success:hover {
      background-color: #008d4c;
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(0, 166, 90, 0.3);
    }

    /* Form Toggle */
    .form-switch {
      text-align: center;
      margin-top: 20px;
      padding-top: 20px;
      border-top: 1px solid #ecf0f1;
      color: #7f8c8d;
      font-size: 13px;
    }

    .form-switch a {
      color: #3c8dbc;
      font-weight: 600;
      text-decoration: none;
      transition: color 0.3s ease;
    }

    .form-switch a:hover {
      color: #367fa9;
      text-decoration: underline;
    }

    .form-register {
      display: none;
    }

    .form-register.active,
    .form-login.active {
      display: block;
    }

    /* Grid for Register */
    .input-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 15px;
    }

    .input-row .form-group {
      margin-bottom: 18px;
    }

    /* Footer */
    .form-footer {
      margin-top: 25px;
      text-align: center;
      padding-top: 20px;
      border-top: 1px solid #ecf0f1;
      color: #95a5a6;
      font-size: 12px;
    }

    .form-footer i {
      margin: 0 4px;
    }

    .form-footer a {
      color: #00a65a;
      text-decoration: none;
    }

    .form-footer a:hover {
      text-decoration: underline;
    }

    /* Feature Highlights */
    .features {
      margin-top: 30px;
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 15px;
      position: relative;
      z-index: 1;
    }

    .feature-item {
      background: rgba(255, 255, 255, 0.1);
      backdrop-filter: blur(5px);
      border-radius: 10px;
      padding: 15px;
      display: flex;
      align-items: center;
      gap: 12px;
      border: 1px solid rgba(255, 255, 255, 0.2);
      transition: all 0.3s ease;
    }

    .feature-item:hover {
      background: rgba(255, 255, 255, 0.15);
      transform: translateY(-3px);
    }

    .feature-icon {
      width: 40px;
      height: 40px;
      background: rgba(0, 166, 90, 0.2);
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #00a65a;
      font-size: 18px;
      flex-shrink: 0;
    }

    .feature-text {
      font-size: 13px;
      line-height: 1.4;
    }

    /* Responsive */
    @media (max-width: 1024px) {
      .login-wrapper {
        width: 98%;
        height: 90vh;
      }

      .left-panel {
        flex: 0 0 40%;
        padding: 35px 30px;
      }

      .right-panel {
        padding: 35px 30px;
      }

      .branding h1 {
        font-size: 32px;
      }

      .form-title h2 {
        font-size: 24px;
      }

      .features {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 768px) {
      .login-wrapper {
        flex-direction: column;
        width: 95%;
        height: 95vh;
      }

      .left-panel {
        flex: 0 0 auto;
        padding: 25px;
      }

      .branding-icon {
        width: 70px;
        height: 70px;
      }

      .branding-icon i {
        font-size: 35px;
      }

      .branding h1 {
        font-size: 28px;
      }

      .features {
        display: none;
      }

      .right-panel {
        padding: 25px;
        overflow-y: auto;
      }

      .input-row {
        grid-template-columns: 1fr;
        gap: 0;
      }
    }
  </style>
</head>
<body>

<!-- Animated Background -->
<div class="bg-animated">
  <div class="particle"></div>
  <div class="particle"></div>
  <div class="particle"></div>
  <div class="particle"></div>
  <div class="particle"></div>
</div>

<!-- Login Wrapper -->
<div class="login-wrapper">
  
  <!-- Left Panel -->
  <div class="left-panel">
    <div class="branding">
      <div class="branding-icon">
        <i class="fa fa-heartbeat"></i>
      </div>
      <h1><b>Medi</b>Fix</h1>
      <p>Anjungan Pasien Mandiri & Sistem Antrian</p>
    </div>
    
    <div class="hospital-info-box">
      <h4><i class="fa fa-hospital-o"></i> <?= htmlspecialchars($namaRS) ?></h4>
      <?php if (!empty($alamatRS)): ?>
      <p><i class="fa fa-map-marker"></i> <?= htmlspecialchars($alamatRS) ?></p>
      <?php endif; ?>
      <?php if (!empty($kabupatenRS) || !empty($propinsiRS)): ?>
      <p><i class="fa fa-map"></i> <?= htmlspecialchars($kabupatenRS) ?><?= !empty($propinsiRS) ? ' - ' . htmlspecialchars($propinsiRS) : '' ?></p>
      <?php endif; ?>
    </div>

    <div class="features">
      <div class="feature-item">
        <div class="feature-icon">
          <i class="fa fa-clock-o"></i>
        </div>
        <div class="feature-text">
          <strong>Antrian Real-time</strong><br>
          Monitor antrian secara langsung
        </div>
      </div>
      <div class="feature-item">
        <div class="feature-icon">
          <i class="fa fa-mobile"></i>
        </div>
        <div class="feature-text">
          <strong>Akses Mudah</strong><br>
          Daftar & cek dari mana saja
        </div>
      </div>
      <div class="feature-item">
        <div class="feature-icon">
          <i class="fa fa-shield"></i>
        </div>
        <div class="feature-text">
          <strong>Data Aman</strong><br>
          Terlindungi dengan enkripsi
        </div>
      </div>
      <div class="feature-item">
        <div class="feature-icon">
          <i class="fa fa-check-circle"></i>
        </div>
        <div class="feature-text">
          <strong>Mudah Digunakan</strong><br>
          Interface simpel & intuitif
        </div>
      </div>
    </div>
  </div>

  <!-- Right Panel -->
  <div class="right-panel">
    
    <?php if ($error): ?>
    <div class="alert alert-danger">
      <i class="fa fa-exclamation-triangle"></i>
      <span><?= htmlspecialchars($error) ?></span>
      <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
    <div class="alert alert-success">
      <i class="fa fa-check-circle"></i>
      <span><?= htmlspecialchars($success) ?></span>
      <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
    <?php endif; ?>

    <!-- Login Form -->
    <form id="loginForm" class="form-login <?= isset($_POST['register_submit']) ? '' : 'active' ?>" method="post">
      <div class="form-title">
        <h2>Selamat Datang Kembali</h2>
        <p>Silakan login untuk melanjutkan ke dashboard</p>
      </div>
      
      <div class="form-group">
        <label class="form-label">Email atau NIK</label>
        <div class="input-wrapper">
          <i class="fa fa-user input-icon"></i>
          <input type="text" name="login" class="form-control" placeholder="Masukkan email atau NIK" required>
        </div>
      </div>
      
      <div class="form-group">
        <label class="form-label">Password</label>
        <div class="input-wrapper">
          <i class="fa fa-lock input-icon"></i>
          <input type="password" name="password" class="form-control" placeholder="Masukkan password" required>
        </div>
      </div>
      
      <button type="submit" name="login_submit" class="btn-submit btn-primary">
        <i class="fa fa-sign-in"></i>
        <span>Masuk Sekarang</span>
      </button>
      
      <div class="form-switch">
        Belum punya akun? <a href="#" id="showRegister">Daftar di sini</a>
      </div>
    </form>

    <!-- Register Form -->
    <form id="registerForm" class="form-register <?= isset($_POST['register_submit']) ? 'active' : '' ?>" method="post">
      <div class="form-title">
        <h2>Daftar Akun Baru</h2>
        <p>Lengkapi data di bawah ini untuk membuat akun</p>
      </div>
      
      <div class="input-row">
        <div class="form-group">
          <label class="form-label">NIK</label>
          <div class="input-wrapper">
            <i class="fa fa-id-card input-icon"></i>
            <input type="text" name="nik" class="form-control" placeholder="Nomor Induk Kependudukan" required>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Nama Lengkap</label>
          <div class="input-wrapper">
            <i class="fa fa-user input-icon"></i>
            <input type="text" name="nama" class="form-control" placeholder="Nama lengkap Anda" required>
          </div>
        </div>
      </div>
      
      <div class="input-row">
        <div class="form-group">
          <label class="form-label">Email</label>
          <div class="input-wrapper">
            <i class="fa fa-envelope input-icon"></i>
            <input type="email" name="email" class="form-control" placeholder="email@example.com" required>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Nomor HP</label>
          <div class="input-wrapper">
            <i class="fa fa-phone input-icon"></i>
            <input type="text" name="hp" class="form-control" placeholder="08xxxxxxxxxx">
          </div>
        </div>
      </div>
      
      <div class="input-row">
        <div class="form-group">
          <label class="form-label">Password</label>
          <div class="input-wrapper">
            <i class="fa fa-lock input-icon"></i>
            <input type="password" name="password" class="form-control" placeholder="Buat password" required>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Konfirmasi Password</label>
          <div class="input-wrapper">
            <i class="fa fa-lock input-icon"></i>
            <input type="password" name="confirm" class="form-control" placeholder="Ulangi password" required>
          </div>
        </div>
      </div>
      
      <button type="submit" name="register_submit" class="btn-submit btn-success">
        <i class="fa fa-user-plus"></i>
        <span>Daftar Sekarang</span>
      </button>
      
      <div class="form-switch">
        Sudah punya akun? <a href="#" id="showLogin">Login di sini</a>
      </div>
    </form>

    <div class="form-footer">
      <i class="fa fa-copyright"></i> <?= date('Y') ?> MediFix Apps
      <br>
      <i class="fa fa-whatsapp"></i> <a href="https://wa.me/6282177846209" target="_blank">082177846209</a>
    </div>
  </div>
  
</div>

<!-- jQuery 3 -->
<script src="https://code.jquery.com/jquery-3.2.1.min.js"></script>
<!-- Bootstrap 3.3.7 -->
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

<script>
document.getElementById('showRegister')?.addEventListener('click', function(e) {
  e.preventDefault();
  document.getElementById('loginForm').classList.remove('active');
  document.getElementById('loginForm').style.display = 'none';
  document.getElementById('registerForm').classList.add('active');
  document.getElementById('registerForm').style.display = 'block';
});

document.getElementById('showLogin')?.addEventListener('click', function(e) {
  e.preventDefault();
  document.getElementById('registerForm').classList.remove('active');
  document.getElementById('registerForm').style.display = 'none';
  document.getElementById('loginForm').classList.add('active');
  document.getElementById('loginForm').style.display = 'block';
});
</script>

</body>
</html>