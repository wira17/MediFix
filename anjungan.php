<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
date_default_timezone_set('Asia/Jakarta');
include 'koneksi2.php';
include 'koneksi.php';

// ---------- SIMPAN DATA VISITOR ----------
include 'koneksi.php'; // Pastikan koneksi ini sesuai tabel visitor_log

$fiturAktif = [];
$stmt = $pdo->query("SELECT kode_fitur, status FROM feature_control");
foreach ($stmt as $row) {
    $fiturAktif[$row['kode_fitur']] = $row['status'] == 1;
}


if(isset($_POST['saveVisitor'])){

    $nik  = trim($_POST['nik']);
    $nama = trim($_POST['nama']);

    if($nik != "" && $nama != ""){
        try {
            $sql = "INSERT INTO visitor_log (nik, nama) VALUES (?,?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$nik, $nama]);

            // Simpan session agar tidak isi ulang lagi
            $_SESSION['visitor_verified'] = true;
            $_SESSION['visitor_name'] = $nama;

            // Redirect ke menu cari pasien
            header("Location: cari_pasien.php");
            exit;

        } catch(Exception $e){
            $error_msg = "Gagal menyimpan data: " . $e->getMessage();
        }
    }
}


// Array hari dan bulan dalam Bahasa Indonesia
$hari = array(
    'Sunday' => 'Minggu',
    'Monday' => 'Senin',
    'Tuesday' => 'Selasa',
    'Wednesday' => 'Rabu',
    'Thursday' => 'Kamis',
    'Friday' => 'Jumat',
    'Saturday' => 'Sabtu'
);

$bulan = array(
    'January' => 'Januari',
    'February' => 'Februari',
    'March' => 'Maret',
    'April' => 'April',
    'May' => 'Mei',
    'June' => 'Juni',
    'July' => 'Juli',
    'August' => 'Agustus',
    'September' => 'September',
    'October' => 'Oktober',
    'November' => 'November',
    'December' => 'Desember'
);

$hariIni = $hari[date('l')];
$tanggal = date('d');
$bulanIni = $bulan[date('F')];
$tahun = date('Y');
$tanggalLengkap = "$hariIni, $tanggal $bulanIni $tahun";

// Ambil nama rumah sakit
try {
    $stmt = $pdo_simrs->query("SELECT nama_instansi FROM setting LIMIT 1");
    $rs = $stmt->fetch(PDO::FETCH_ASSOC);
    $namaRS = $rs['nama_instansi'] ?? 'Nama Rumah Sakit';
} catch (Exception $e) {
    $namaRS = 'Nama Rumah Sakit';
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Anjungan Mandiri RS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
    background-size: 400% 400%;
    animation: gradientFlow 15s ease infinite;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    overflow-x: hidden;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

@keyframes gradientFlow {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}

/* Partikel Background */
.particles {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
    z-index: 0;
}

.particle {
    position: absolute;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 50%;
    animation: float 20s infinite;
}

@keyframes float {
    0%, 100% { transform: translateY(0) translateX(0) rotate(0deg); }
    25% { transform: translateY(-100px) translateX(50px) rotate(90deg); }
    50% { transform: translateY(-200px) translateX(-50px) rotate(180deg); }
    75% { transform: translateY(-100px) translateX(100px) rotate(270deg); }
}

.header {
    text-align: center;
    padding: 25px 20px 15px;
    color: #fff;
    text-shadow: 2px 4px 8px rgba(0,0,0,0.3);
    position: relative;
    z-index: 10;
}

.header h1 {
    font-weight: 800;
    font-size: 2.5rem;
    margin-bottom: 8px;
    letter-spacing: 1px;
    animation: slideDown 0.8s ease-out;
}

.header .date {
    font-size: 1.1rem;
    font-weight: 500;
    opacity: 0.95;
    background: rgba(255,255,255,0.2);
    padding: 8px 20px;
    border-radius: 20px;
    display: inline-block;
    backdrop-filter: blur(10px);
    animation: slideDown 1s ease-out;
}

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-30px); }
    to { opacity: 1; transform: translateY(0); }
}

.menu-container {
    flex: 1;
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 20px 30px 100px;
    position: relative;
    z-index: 10;
}

.menu-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 25px;
    max-width: 1600px;
    width: 100%;
    animation: fadeInUp 1s ease-out;
}

@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(40px); }
    to { opacity: 1; transform: translateY(0); }
}

.card-menu {
    background: rgba(255, 255, 255, 0.95);
    border-radius: 20px;
    padding: 30px 20px;
    text-align: center;
    text-decoration: none;
    color: #2d3748;
    font-weight: 600;
    font-size: 1.05rem;
    box-shadow: 0 10px 30px rgba(0,0,0,0.15);
    transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    position: relative;
    overflow: hidden;
    border: 2px solid transparent;
}

.card-menu::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
    transition: left 0.5s;
}

.card-menu:hover::before {
    left: 100%;
}

.card-menu:hover {
    transform: translateY(-10px) scale(1.03);
    box-shadow: 0 15px 40px rgba(0,0,0,0.25);
    border-color: #667eea;
}

.icon-wrapper {
    width: 85px;
    height: 85px;
    margin: 0 auto 15px;
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.8rem;
    color: #fff;
    box-shadow: 0 8px 20px rgba(0,0,0,0.15);
    transition: all 0.3s ease;
}

.card-menu:hover .icon-wrapper {
    transform: rotate(360deg) scale(1.1);
}

/* Warna Icon */
.bg-admisi { background: linear-gradient(135deg, #667eea, #764ba2); }
.bg-poli { background: linear-gradient(135deg, #f093fb, #f5576c); }
.bg-cek { background: linear-gradient(135deg, #4facfe, #00f2fe); }
.bg-checkin { background: linear-gradient(135deg, #43e97b, #38f9d7); }
.bg-frista { background: linear-gradient(135deg, #fa709a, #fee140); }
.bg-finger { background: linear-gradient(135deg, #ffecd2, #fcb69f); }
.bg-rujukan { background: linear-gradient(135deg, #a8edea, #fed6e3); }
.bg-sep { background: linear-gradient(135deg, #ff9a9e, #fecfef); }

.menu-label {
    margin-top: 10px;
    font-size: 1.05rem;
    font-weight: 700;
    color: #2d3748;
}

.menu-subtitle {
    font-size: 0.82rem;
    color: #718096;
    margin-top: 5px;
    font-weight: 500;
}

footer {
    background: linear-gradient(135deg, rgba(0,0,0,0.85), rgba(45,55,72,0.9));
    backdrop-filter: blur(10px);
    color: #fff;
    text-align: center;
    padding: 12px 20px;
    font-size: 0.95rem;
    position: fixed;
    bottom: 0;
    width: 100%;
    z-index: 100;
    box-shadow: 0 -5px 20px rgba(0,0,0,0.3);
}

.footer-content {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

footer img {
    height: 32px;
    width: auto;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
}

footer strong {
    color: #fbbf24;
}

.whatsapp-icon {
    color: #25D366;
    font-size: 1.1rem;
}

/* Responsive */
@media (max-width: 1400px) {
    .menu-grid {
        grid-template-columns: repeat(5, 1fr);
        gap: 20px;
    }
}

@media (max-width: 1024px) {
    .menu-grid {
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
    }
    .header h1 {
        font-size: 2rem;
    }
}
#liveClock + span {
    opacity: 0.85;
    font-size: 0.95rem;
}



.header .date {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 10px;
}

@media (max-width: 768px) {
    .menu-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
    }
    .header h1 {
        font-size: 1.6rem;
    }
    .icon-wrapper {
        width: 70px;
        height: 70px;
        font-size: 2.2rem;
    }
}

@media (max-width: 480px) {
    .menu-grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }
}
</style>
</head>
<body>

<!-- Partikel Dekoratif -->
<div class="particles">
    <div class="particle" style="width:60px;height:60px;top:10%;left:10%;animation-delay:0s;"></div>
    <div class="particle" style="width:40px;height:40px;top:20%;left:80%;animation-delay:2s;"></div>
    <div class="particle" style="width:50px;height:50px;top:60%;left:15%;animation-delay:4s;"></div>
    <div class="particle" style="width:35px;height:35px;top:70%;left:85%;animation-delay:6s;"></div>
</div>




<!-- HEADER -->
<div class="header">
    <h1>🏥 ANJUNGAN PASIEN MANDIRI</h1>
    <h2 style="font-size: 1.4rem; font-weight: 600; margin: 10px 0;"><?= htmlspecialchars($namaRS) ?></h2>
  <div class="date">
    <i class="bi bi-calendar-event"></i> <?= $tanggalLengkap ?> 
    <span style="font-weight:600;">•</span>
    <i class="bi bi-clock-history"></i>
    <span id="liveClock" style="font-weight:600;"></span>
    <span style="font-weight:600;">WIB</span>
</div>



</div>

<div class="menu-container">
    <div class="menu-grid">

        <!-- ANTRI ADMISI -->
        <a 
            href="<?= $fiturAktif['admisi'] ? 'antrian_admisi.php' : 'javascript:void(0)' ?>" 
            class="card-menu <?= $fiturAktif['admisi'] ? '' : 'opacity-50' ?>" 
            <?= $fiturAktif['admisi'] ? '' : 'data-bs-toggle="modal" data-bs-target="#notAvailableModal"' ?>
        >
            <div class="icon-wrapper bg-admisi">
                <i class="bi bi-clipboard-check-fill"></i>
            </div>
            <div class="menu-label">ANTRI ADMISI</div>
            <div class="menu-subtitle">Ambil nomor antrian</div>
        </a>


        <!-- DAFTAR POLI -->
        <a 
            href="<?= $fiturAktif['daftar_poli'] ? 'daftar_poli.php' : 'javascript:void(0)' ?>" 
            class="card-menu <?= $fiturAktif['daftar_poli'] ? '' : 'opacity-50' ?>"
            <?= $fiturAktif['daftar_poli'] ? '' : 'data-bs-toggle="modal" data-bs-target="#notAvailableModal"' ?>
        >
            <div class="icon-wrapper bg-poli">
                <i class="bi bi-journal-medical"></i>
            </div>
            <div class="menu-label">DAFTAR POLI</div>
            <div class="menu-subtitle">Pendaftaran poliklinik</div>
        </a>


        <!-- CEK BPJS -->
        <a 
            href="<?= $fiturAktif['cek_bpjs'] ? 'cek_peserta.php' : 'javascript:void(0)' ?>" 
            class="card-menu <?= $fiturAktif['cek_bpjs'] ? '' : 'opacity-50' ?>" 
            <?= $fiturAktif['cek_bpjs'] ? '' : 'data-bs-toggle="modal" data-bs-target="#notAvailableModal"' ?>
        >
            <div class="icon-wrapper bg-cek">
                <i class="bi bi-person-check-fill"></i>
            </div>
            <div class="menu-label">CEK KEPESERTAAN BPJS</div>
            <div class="menu-subtitle">Verifikasi data pasien</div>
        </a>


        <!-- CHECK IN JKN -->
        <a 
            href="<?= $fiturAktif['checkin'] ? 'checkin_jkn.php' : 'javascript:void(0)' ?>" 
            class="card-menu <?= $fiturAktif['checkin'] ? '' : 'opacity-50' ?>" 
            <?= $fiturAktif['checkin'] ? '' : 'data-bs-toggle="modal" data-bs-target="#notAvailableModal"' ?>
            <?= $fiturAktif['checkin'] ? 'target="_blank"' : '' ?>
        >
            <div class="icon-wrapper bg-checkin">
                <i class="bi bi-qr-code-scan"></i>
            </div>
            <div class="menu-label">CHECK-IN JKN</div>
            <div class="menu-subtitle">Konfirmasi kehadiran</div>
        </a>


        <!-- FRISTA -->
        <a 
            href="<?= $fiturAktif['frista'] ? 'fristaweb/frista/index.php' : 'javascript:void(0)' ?>" 
            class="card-menu <?= $fiturAktif['frista'] ? '' : 'opacity-50' ?>" 
            <?= $fiturAktif['frista'] ? '' : 'data-bs-toggle="modal" data-bs-target="#notAvailableModal"' ?>
        >
            <div class="icon-wrapper bg-frista">
                <i class="bi bi-person-vcard-fill"></i>
            </div>
            <div class="menu-label">FRISTA</div>
            <div class="menu-subtitle">Layanan frista</div>
        </a>


        <!-- CARI PASIEN RANAP (special case: tetap modal form) -->
        <a 
            href="javascript:void(0)" 
            class="card-menu <?= $fiturAktif['cari_ranap'] ? '' : 'opacity-50' ?>" 
            <?= $fiturAktif['cari_ranap'] ? 'data-bs-toggle="modal" data-bs-target="#privacyModal"' : 'data-bs-toggle="modal" data-bs-target="#notAvailableModal"' ?>
        >
            <div class="icon-wrapper" style="background:#4c8bff;">
                <i class="bi bi-search-heart"></i>
            </div>
            <div class="menu-label">CARI PASIEN R. INAP</div>
            <div class="menu-subtitle">Pencarian data pasien R. Inap</div>
        </a>


        <!-- RUJUKAN -->
        <a 
            href="<?= $fiturAktif['rujukan'] ? 'cek_rujukan_fktp.php' : 'javascript:void(0)' ?>" 
            class="card-menu <?= $fiturAktif['rujukan'] ? '' : 'opacity-50' ?>" 
            <?= $fiturAktif['rujukan'] ? '' : 'data-bs-toggle="modal" data-bs-target="#notAvailableModal"' ?>
        >
            <div class="icon-wrapper bg-rujukan">
                <i class="bi bi-arrow-left-right"></i>
            </div>
            <div class="menu-label">SEP RUJUKAN FKTP</div>
            <div class="menu-subtitle">Surat eligibilitas peserta</div>
        </a>


        <!-- SEP POLI -->
        <a 
            href="<?= $fiturAktif['sep_poli'] ? 'cek_pasien_poli.php' : 'javascript:void(0)' ?>" 
            class="card-menu <?= $fiturAktif['sep_poli'] ? '' : 'opacity-50' ?>" 
            <?= $fiturAktif['sep_poli'] ? '' : 'data-bs-toggle="modal" data-bs-target="#notAvailableModal"' ?>
        >
            <div class="icon-wrapper bg-sep">
                <i class="bi bi-calendar2-check-fill"></i>
            </div>
            <div class="menu-label">SEP POLI</div>
            <div class="menu-subtitle">Layanan poliklinik</div>
        </a>

        <!-- SURAT KONTROL RAJAL -->
        <a 
            href="<?= $fiturAktif['kontrol_rajal'] ?? false ? 'surat_kontrol_rajal.php' : 'javascript:void(0)' ?>" 
            class="card-menu <?= ($fiturAktif['kontrol_rajal'] ?? false) ? '' : 'opacity-50' ?>" 
            <?= ($fiturAktif['kontrol_rajal'] ?? false) ? '' : 'data-bs-toggle="modal" data-bs-target="#notAvailableModal"' ?>
        >
            <div class="icon-wrapper" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                <i class="bi bi-clipboard2-pulse-fill"></i>
            </div>
            <div class="menu-label">SURAT KONTROL RAJAL</div>
            <div class="menu-subtitle">Rawat Jalan</div>
        </a>

        <!-- SURAT KONTROL RANAP -->
        <a 
            href="<?= $fiturAktif['kontrol_ranap'] ?? false ? 'surat_kontrol_ranap.php' : 'javascript:void(0)' ?>" 
            class="card-menu <?= ($fiturAktif['kontrol_ranap'] ?? false) ? '' : 'opacity-50' ?>" 
            <?= ($fiturAktif['kontrol_ranap'] ?? false) ? '' : 'data-bs-toggle="modal" data-bs-target="#notAvailableModal"' ?>
        >
            <div class="icon-wrapper" style="background: linear-gradient(135deg, #14b8a6, #0d9488);">
                <i class="bi bi-hospital-fill"></i>
            </div>
            <div class="menu-label">SURAT KONTROL RANAP</div>
            <div class="menu-subtitle">Rawat Inap</div>
        </a>

    </div>
</div>



<footer>
    <div class="footer-content">
        <img src="image/logo.png" alt="MediFix Logo">
        <div>
            <strong>MediFix Apps</strong> — Anjungan Pasien Mandiri
        </div>
        <div>
            <i class="bi bi-whatsapp whatsapp-icon"></i>
            <strong>082177846209</strong> - M. Wira Satria Buana
        </div>
        <img src="image/logo.png" alt="MediFix Logo">
    </div>
</footer>

<!-- Modal Privasi -->
<div class="modal fade" id="privacyModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content" style="border-radius:20px; overflow:hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
      
      <div class="modal-header" style="background: linear-gradient(135deg, #667eea, #764ba2); color:white; border:none; padding:25px 30px;">
        <h4 class="modal-title" style="font-weight:700; display:flex; align-items:center; gap:12px;">
          <i class="bi bi-shield-lock-fill" style="font-size:1.8rem;"></i> 
          Kebijakan Privasi Pasien
        </h4>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body" style="padding:35px 30px;">
        <div style="background: linear-gradient(135deg, #e0e7ff, #fce7f3); padding:25px; border-radius:15px; margin-bottom:25px;">
          <h5 style="color:#4c1d95; font-weight:700; margin-bottom:15px;">
            <i class="bi bi-exclamation-triangle-fill"></i> Perhatian Penting
          </h5>
          <p style="font-size:1rem; color:#5b21b6; margin:0; line-height:1.6;">
            Informasi pasien rawat inap bersifat <strong>rahasia dan dilindungi</strong>. 
            Akses ini hanya diperuntukkan bagi <strong>keluarga atau kerabat dekat pasien yang sah</strong>.
          </p>
        </div>

        <div style="background:#f8fafc; padding:20px; border-radius:12px; border-left:4px solid #667eea;">
          <p style="font-size:0.95rem; color:#475569; margin-bottom:12px;">
            <i class="bi bi-check-circle-fill" style="color:#10b981;"></i> 
            Anda akan diminta mengisi identitas sebelum melanjutkan
          </p>
          <p style="font-size:0.95rem; color:#475569; margin:0;">
            <i class="bi bi-check-circle-fill" style="color:#10b981;"></i> 
            Data kunjungan akan tercatat untuk keperluan audit
          </p>
        </div>
      </div>

      <div class="modal-footer" style="background:#f9fafb; border:none; padding:20px 30px; justify-content:center; gap:15px;">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="padding:12px 30px; border-radius:10px; font-weight:600;">
          <i class="bi bi-x-circle"></i> Batal
        </button>
        <button class="btn" id="nextToForm" style="background: linear-gradient(135deg, #667eea, #764ba2); color:white; padding:12px 35px; border-radius:10px; font-weight:600; border:none;">
          <i class="bi bi-check-circle"></i> Saya Setuju & Lanjutkan
        </button>
      </div>

    </div>
  </div>
</div>


<!-- Modal Form Identitas Pengunjung -->
<div class="modal fade" id="visitorFormModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content" style="border-radius:20px; overflow:hidden;">

      <div class="modal-header" style="background: linear-gradient(135deg, #667eea, #764ba2); color:white; border:none; padding:25px 30px;">
        <h4 class="modal-title" style="font-weight:700;">
          <i class="bi bi-person-badge-fill"></i> Identitas Pengunjung
        </h4>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <form method="POST">
        <div class="modal-body" style="padding:35px 30px;">
          
          <div class="alert" style="background: linear-gradient(135deg, #dbeafe, #e0e7ff); border:none; border-left:4px solid #3b82f6; color:#1e3a8a; margin-bottom:25px;">
            <i class="bi bi-info-circle-fill"></i> 
            <strong>Mohon isi data dengan benar.</strong> Data akan digunakan untuk keperluan log kunjungan.
          </div>

          <div class="mb-4">
            <label class="form-label" style="font-weight:600; color:#334155; margin-bottom:10px;">
              <i class="bi bi-credit-card-2-front"></i> NIK (Nomor KTP)
            </label>
            <input 
              type="text" 
              name="nik" 
              id="inputNIK"
              class="form-control virtual-keyboard-input" 
              maxlength="20" 
              placeholder="Masukkan NIK Anda"
              style="padding:15px; font-size:1.1rem; border:2px solid #e2e8f0; border-radius:12px;"
              required
              readonly
            >
            <small class="text-muted">Klik pada kolom untuk memunculkan keyboard</small>
          </div>

          <div class="mb-3">
            <label class="form-label" style="font-weight:600; color:#334155; margin-bottom:10px;">
              <i class="bi bi-person-fill"></i> Nama Lengkap Pengunjung
            </label>
            <input 
              type="text" 
              name="nama" 
              id="inputNama"
              class="form-control virtual-keyboard-input" 
              placeholder="Masukkan nama lengkap Anda"
              style="padding:15px; font-size:1.1rem; border:2px solid #e2e8f0; border-radius:12px;"
              required
              readonly
            >
            <small class="text-muted">Klik pada kolom untuk memunculkan keyboard</small>
          </div>

        </div>

        <div class="modal-footer" style="background:#f9fafb; border:none; padding:20px 30px; justify-content:center;">
          <button type="submit" name="saveVisitor" class="btn btn-success" style="padding:14px 40px; border-radius:12px; font-weight:700; font-size:1.05rem;">
            <i class="bi bi-check-circle-fill"></i> Simpan & Lanjutkan
          </button>
        </div>
      </form>

    </div>
  </div>
</div>

<!-- Virtual Keyboard CSS & Script -->
<style>
.virtual-keyboard {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: linear-gradient(135deg, #1e293b, #334155);
    padding: 20px;
    box-shadow: 0 -10px 40px rgba(0,0,0,0.5);
    z-index: 9999;
    display: none;
    animation: slideUp 0.3s ease-out;
}

@keyframes slideUp {
    from { transform: translateY(100%); }
    to { transform: translateY(0); }
}

.keyboard-row {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin-bottom: 8px;
}

.key-btn {
    background: linear-gradient(135deg, #475569, #64748b);
    color: white;
    border: none;
    padding: 15px;
    min-width: 50px;
    border-radius: 8px;
    font-size: 1.1rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    box-shadow: 0 4px 8px rgba(0,0,0,0.3);
}

.key-btn:hover {
    background: linear-gradient(135deg, #667eea, #764ba2);
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(102, 126, 234, 0.4);
}

.key-btn:active {
    transform: translateY(0);
}

.key-space {
    min-width: 300px;
}

.key-backspace, .key-close {
    background: linear-gradient(135deg, #ef4444, #dc2626);
}

.key-shift {
    background: linear-gradient(135deg, #f59e0b, #d97706);
}

.active-input {
    border-color: #667eea !important;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2) !important;
}
</style>

<div id="virtualKeyboard" class="virtual-keyboard">
    <div class="keyboard-row">
        <button class="key-btn" data-key="1">1</button>
        <button class="key-btn" data-key="2">2</button>
        <button class="key-btn" data-key="3">3</button>
        <button class="key-btn" data-key="4">4</button>
        <button class="key-btn" data-key="5">5</button>
        <button class="key-btn" data-key="6">6</button>
        <button class="key-btn" data-key="7">7</button>
        <button class="key-btn" data-key="8">8</button>
        <button class="key-btn" data-key="9">9</button>
        <button class="key-btn" data-key="0">0</button>
        <button class="key-btn key-backspace" data-key="backspace">
            <i class="bi bi-backspace-fill"></i>
        </button>
    </div>
    <div class="keyboard-row">
        <button class="key-btn" data-key="Q">Q</button>
        <button class="key-btn" data-key="W">W</button>
        <button class="key-btn" data-key="E">E</button>
        <button class="key-btn" data-key="R">R</button>
        <button class="key-btn" data-key="T">T</button>
        <button class="key-btn" data-key="Y">Y</button>
        <button class="key-btn" data-key="U">U</button>
        <button class="key-btn" data-key="I">I</button>
        <button class="key-btn" data-key="O">O</button>
        <button class="key-btn" data-key="P">P</button>
    </div>
    <div class="keyboard-row">
        <button class="key-btn" data-key="A">A</button>
        <button class="key-btn" data-key="S">S</button>
        <button class="key-btn" data-key="D">D</button>
        <button class="key-btn" data-key="F">F</button>
        <button class="key-btn" data-key="G">G</button>
        <button class="key-btn" data-key="H">H</button>
        <button class="key-btn" data-key="J">J</button>
        <button class="key-btn" data-key="K">K</button>
        <button class="key-btn" data-key="L">L</button>
    </div>
    <div class="keyboard-row">
        <button class="key-btn key-shift" data-key="shift">
            <i class="bi bi-shift-fill"></i>
        </button>
        <button class="key-btn" data-key="Z">Z</button>
        <button class="key-btn" data-key="X">X</button>
        <button class="key-btn" data-key="C">C</button>
        <button class="key-btn" data-key="V">V</button>
        <button class="key-btn" data-key="B">B</button>
        <button class="key-btn" data-key="N">N</button>
        <button class="key-btn" data-key="M">M</button>
    </div>
    <div class="keyboard-row">
        <button class="key-btn key-space" data-key=" ">SPASI</button>
        <button class="key-btn key-close" data-key="close">
            <i class="bi bi-x-lg"></i> TUTUP
        </button>
    </div>
</div>

<script>
// Virtual Keyboard Handler
let currentInput = null;
let isUpperCase = false;

document.addEventListener('DOMContentLoaded', function() {
    const keyboard = document.getElementById('virtualKeyboard');
    const inputs = document.querySelectorAll('.virtual-keyboard-input');
    
    // Show keyboard when input is clicked
    inputs.forEach(input => {
        input.addEventListener('click', function() {
            currentInput = this;
            keyboard.style.display = 'block';
            
            // Remove active class from all inputs
            inputs.forEach(i => i.classList.remove('active-input'));
            // Add active class to current input
            this.classList.add('active-input');
        });
    });
    
    // Handle key clicks
    keyboard.addEventListener('click', function(e) {
        if (e.target.classList.contains('key-btn')) {
            const key = e.target.getAttribute('data-key');
            
            if (key === 'backspace') {
                if (currentInput) {
                    currentInput.value = currentInput.value.slice(0, -1);
                }
            } else if (key === 'shift') {
                isUpperCase = !isUpperCase;
                e.target.style.background = isUpperCase ? 
                    'linear-gradient(135deg, #10b981, #059669)' : 
                    'linear-gradient(135deg, #f59e0b, #d97706)';
            } else if (key === 'close') {
                keyboard.style.display = 'none';
                inputs.forEach(i => i.classList.remove('active-input'));
                currentInput = null;
            } else if (currentInput) {
                const char = isUpperCase ? key : key.toLowerCase();
                currentInput.value += char;
                
                // Auto lowercase after typing
                if (isUpperCase && key !== ' ') {
                    isUpperCase = false;
                    document.querySelector('[data-key="shift"]').style.background = 
                        'linear-gradient(135deg, #f59e0b, #d97706)';
                }
            }
        }
    });
});

// Modal transition
document.getElementById("nextToForm").addEventListener("click", function(){
    let modal1 = bootstrap.Modal.getInstance(document.getElementById("privacyModal"));
    modal1.hide();

    setTimeout(()=>{
        new bootstrap.Modal(document.getElementById("visitorFormModal")).show();
    }, 300);
});
</script>

<!-- Modal Form Identitas Pengunjung -->
<div class="modal fade" id="visitorFormModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:18px;">

      <div class="modal-header" style="background:#4c8bff;color:white;border-radius:18px 18px 0 0;">
        <h5 class="modal-title"><i class="bi bi-person-badge"></i> Identitas Pengunjung</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <form method="POST">
        <div class="modal-body">

          <div class="mb-3">
            <label class="form-label">NIK (Nomor KTP)</label>
            <input type="text" name="nik" class="form-control" maxlength="20" required>
          </div>

          <div class="mb-3">
            <label class="form-label">Nama Pengunjung</label>
            <input type="text" name="nama" class="form-control" required>
          </div>

        </div>

        <div class="modal-footer" style="justify-content:center;">
          <button type="submit" name="saveVisitor" class="btn btn-success">
            Simpan & Lanjutkan
          </button>
        </div>
      </form>

    </div>
  </div>
</div>



<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>

// Auto refresh untuk update waktu
setTimeout(function() {
    location.reload();
}, 3600000); // Refresh setiap 1 jam

function updateClock() {
    const now = new Date();
    let jam = String(now.getHours()).padStart(2, '0');
    let menit = String(now.getMinutes()).padStart(2, '0');
    let detik = String(now.getSeconds()).padStart(2, '0');

    document.getElementById("liveClock").textContent = `${jam}:${menit}:${detik}`;
}

setInterval(updateClock, 1000);
updateClock();

</script>

<script>
document.getElementById("nextToForm").addEventListener("click", function(){
    let modal1 = bootstrap.Modal.getInstance(document.getElementById("privacyModal"));
    modal1.hide();

    setTimeout(()=>{
        new bootstrap.Modal(document.getElementById("visitorFormModal")).show();
    },300);
});
</script>

<div class="modal fade" id="notAvailableModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:15px;">
      
      <!-- Header dengan warna ungu soft -->
      <div class="modal-header" style="
          background: linear-gradient(135deg, #b9a9ff, #9b8df5);
          color:white;
          border-radius:15px 15px 0 0;">
        <h5 class="modal-title">
          <i class="bi bi-exclamation-circle-fill"></i> Fitur Tidak Tersedia
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <!-- Body -->
      <div class="modal-body text-center" style="
          font-size:1.15rem;
          font-weight:600;
          color:#4b3d8a;
          padding:20px;">
        🙂 Maaf, fitur ini dalam proses pengembangan.
      </div>

    </div>
  </div>
</div>


</body>
</html>