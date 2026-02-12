<?php
session_start();
include 'koneksi.php';
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

// Mapping hari ke bahasa Indonesia
$hari_ini = strtoupper(date('l'));
$hari_map = [
    'SUNDAY' => 'MINGGU',
    'MONDAY' => 'SENIN',
    'TUESDAY' => 'SELASA',
    'WEDNESDAY' => 'RABU',
    'THURSDAY' => 'KAMIS',
    'FRIDAY' => 'JUMAT',
    'SATURDAY' => 'SABTU'
];
$hari_indo = $hari_map[$hari_ini] ?? 'SENIN';

$swal_data = null;

// ===============================
// PROSES PENDAFTARAN
// ===============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['no_rkm_medis'])) {
    try {
        $no_rkm_medis = trim($_POST['no_rkm_medis']);
        $kd_poli      = trim($_POST['kd_poli']);
        $kd_dokter    = trim($_POST['kd_dokter']);
        $kd_pj        = trim($_POST['kd_pj']);

        if (!$no_rkm_medis || !$kd_poli || !$kd_dokter || !$kd_pj)
            throw new Exception("Data tidak lengkap!");

        $tgl = date('Y-m-d');
        $jam = date('H:i:s');

        // CEK STATUS DAFTAR BARU/LAMA
        $stmtCekDaftar = $pdo_simrs->prepare("SELECT COUNT(*) FROM reg_periksa WHERE no_rkm_medis=?");
        $stmtCekDaftar->execute([$no_rkm_medis]);
        $stts_daftar = ($stmtCekDaftar->fetchColumn() > 0) ? "Lama" : "Baru";

        // CEK STATUS POLI
        $cekStatus = $pdo_simrs->prepare("
            SELECT COUNT(*) FROM reg_periksa WHERE no_rkm_medis=? AND kd_poli=?");
        $cekStatus->execute([$no_rkm_medis, $kd_poli]);
        $status_poli = ($cekStatus->fetchColumn() > 0) ? "Lama" : "Baru";

        // CEK KAMAR INAP
        $stmt_inap = $pdo_simrs->prepare("
            SELECT COUNT(*) FROM reg_periksa r 
            JOIN kamar_inap k ON r.no_rawat = k.no_rawat 
            WHERE r.no_rkm_medis=? AND k.stts_pulang='-' 
        ");
        $stmt_inap->execute([$no_rkm_medis]);
        if ($stmt_inap->fetchColumn() > 0)
            throw new Exception("Pasien sedang dalam perawatan inap!");

        // CEK SUDAH DAFTAR HARI INI
        $cek = $pdo_simrs->prepare("
            SELECT COUNT(*) FROM reg_periksa 
            WHERE no_rkm_medis=? AND kd_poli=? AND kd_dokter=? AND tgl_registrasi=?
        ");
        $cek->execute([$no_rkm_medis, $kd_poli, $kd_dokter, $tgl]);
        if ($cek->fetchColumn() > 0)
            throw new Exception("Pasien sudah terdaftar hari ini!");

        // NOMOR REG
        $stmt_no = $pdo_simrs->prepare("
            SELECT MAX(CAST(no_reg AS UNSIGNED)) 
            FROM reg_periksa WHERE tgl_registrasi=?");
        $stmt_no->execute([$tgl]);
        $no_reg = str_pad((($stmt_no->fetchColumn() ?: 0) + 1), 3, '0', STR_PAD_LEFT);

        // NOMOR RAWAT
        $stmt_rawat = $pdo_simrs->prepare("
            SELECT MAX(CAST(SUBSTRING(no_rawat, 12, 6) AS UNSIGNED))
            FROM reg_periksa WHERE tgl_registrasi=?
        ");
        $stmt_rawat->execute([$tgl]);
        $max_rawat_seq = $stmt_rawat->fetchColumn();
        $no_rawat = date('Y/m/d/') . str_pad((($max_rawat_seq ?: 0) + 1), 6, '0', STR_PAD_LEFT);

        // ============================================
        // AMBIL DATA PASIEN SESUAI TABEL YANG BENAR
        // ============================================
        $stmt_pasien = $pdo_simrs->prepare("
            SELECT 
                nm_pasien,
                alamat,
                tgl_lahir,
                keluarga AS hubunganpj,
                namakeluarga AS p_jawab,
                alamatpj
            FROM pasien 
            WHERE no_rkm_medis=?
        ");
        $stmt_pasien->execute([$no_rkm_medis]);
        $pasien = $stmt_pasien->fetch(PDO::FETCH_ASSOC);

        if (!$pasien)
            throw new Exception("Data pasien tidak ditemukan!");

        if (empty($pasien['tgl_lahir']))
            throw new Exception("Tanggal lahir belum diinput di data pasien!");

        // Jika tidak ada data pj, fallback pakai nama pasien
        $p_jawab     = $pasien['p_jawab'] ?: $pasien['nm_pasien'];
        $almt_pj     = $pasien['alamatpj'] ?: $pasien['alamat'];
        $hubunganpj  = $pasien['hubunganpj'] ?: "-";

        // HITUNG UMUR
        $lahir = new DateTime($pasien['tgl_lahir']);
        $today = new DateTime();
        $umur  = $today->diff($lahir)->y;

        // BIAYA REGISTRASI
        $stmt_biaya = $pdo_simrs->prepare("
            SELECT registrasi, registrasilama FROM poliklinik WHERE kd_poli=?");
        $stmt_biaya->execute([$kd_poli]);
        $biaya = $stmt_biaya->fetch(PDO::FETCH_ASSOC);
        $biaya_reg = ($stts_daftar == "Lama") ? $biaya['registrasilama'] : $biaya['registrasi'];

        // ============================================
        // INSERT DATA REGISTRASI
        // ============================================
        $stmt = $pdo_simrs->prepare("
            INSERT INTO reg_periksa 
            (no_reg,no_rawat,tgl_registrasi,jam_reg,kd_dokter,no_rkm_medis,kd_poli,
             p_jawab,almt_pj,hubunganpj,biaya_reg,stts,stts_daftar,status_lanjut,
             kd_pj,umurdaftar,sttsumur,status_bayar,status_poli)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");

        $stmt->execute([
            $no_reg,
            $no_rawat,
            $tgl,
            $jam,
            $kd_dokter,
            $no_rkm_medis,
            $kd_poli,
            $p_jawab,
            $almt_pj,
            $hubunganpj,
            $biaya_reg,
            'Belum',
            $stts_daftar,
            'Ralan',
            $kd_pj,
            $umur,
            'Th',
            'Belum Bayar',
            $status_poli
        ]);

        // SWEETALERT SUKSES
        $printUrl = "print_antrian.php?no_reg={$no_reg}&no_rawat={$no_rawat}&nm_pasien={$pasien['nm_pasien']}";

        $swal_data = [
            'icon' => 'success',
            'title' => 'Pendaftaran Berhasil!',
            'html'  => "<strong>No. Rawat:</strong> {$no_rawat}<br>
                        <strong>No Antri Poliklinik :</strong> {$kd_poli}-{$no_reg}",
            'confirmText' => 'Cetak Antrian',
            'cancelText'  => 'Tutup',
            'printUrl'    => $printUrl,
            'redirect'    => 'daftar_poli.php'
        ];

    } catch (Exception $e) {

        $swal_data = [
            'icon' => 'error',
            'title' => 'Gagal!',
            'text'  => $e->getMessage(),
            'confirmText' => 'OK',
            'redirect' => 'daftar_poli.php'
        ];
    }
}
?>

<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Anjungan Pasien Mandiri - Pendaftaran Poliklinik</title>

<!-- Bootstrap & Icons -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<!-- Google Font -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

<style>
:root {
  --primary: #1e3a8a;
  --primary-dark: #1e40af;
  --secondary: #0ea5e9;
  --accent: #3b82f6;
  --success: #10b981;
  --danger: #ef4444;
  --warning: #f59e0b;
  --dark: #1f2937;
  --light: #f8fafc;
  --shadow-sm: 0 2px 8px rgba(0,0,0,0.08);
  --shadow-md: 0 4px 16px rgba(0,0,0,0.12);
  --shadow-lg: 0 8px 32px rgba(0,0,0,0.16);
}

* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

body {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  font-family: 'Inter', sans-serif;
  min-height: 100vh;
  padding: 20px;
  position: relative;
  overflow-x: hidden;
}

body::before {
  content: '';
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: 
    radial-gradient(circle at 20% 50%, rgba(99, 102, 241, 0.2) 0%, transparent 50%),
    radial-gradient(circle at 80% 80%, rgba(168, 85, 247, 0.2) 0%, transparent 50%);
  pointer-events: none;
  z-index: 0;
}

.main-container {
  max-width: 1400px;
  margin: 0 auto;
  position: relative;
  z-index: 1;
}

/* Header Section */
.header-section {
  background: rgba(255, 255, 255, 0.98);
  backdrop-filter: blur(20px);
  border-radius: 24px;
  padding: 32px 40px;
  margin-bottom: 24px;
  box-shadow: var(--shadow-lg);
  border: 1px solid rgba(255, 255, 255, 0.3);
  position: relative;
  overflow: hidden;
}

.header-section::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 4px;
  background: linear-gradient(90deg, var(--primary), var(--secondary), var(--accent));
}

.header-section h3 {
  font-size: 32px;
  font-weight: 800;
  color: var(--primary);
  margin-bottom: 8px;
  letter-spacing: -0.5px;
}

.header-section h5 {
  font-size: 16px;
  font-weight: 500;
  color: #64748b;
  margin: 0;
}

.header-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 48px;
  height: 48px;
  background: linear-gradient(135deg, var(--primary), var(--accent));
  border-radius: 12px;
  color: white;
  font-size: 24px;
  margin-right: 16px;
  box-shadow: 0 4px 12px rgba(30, 58, 138, 0.3);
}

/* Search Card */
.search-card {
  background: rgba(255, 255, 255, 0.98);
  backdrop-filter: blur(20px);
  border-radius: 20px;
  padding: 32px;
  margin-bottom: 24px;
  box-shadow: var(--shadow-md);
  border: 1px solid rgba(255, 255, 255, 0.3);
}

.search-input-wrapper {
  position: relative;
  margin-bottom: 20px;
}

.search-input-wrapper .form-control {
  height: 64px;
  border-radius: 16px;
  border: 2px solid #e2e8f0;
  font-size: 20px;
  font-weight: 500;
  padding: 0 120px 0 24px;
  text-align: center;
  background: #f8fafc;
  transition: all 0.3s ease;
  color: var(--dark);
}

.search-input-wrapper .form-control:focus {
  border-color: var(--primary);
  box-shadow: 0 0 0 4px rgba(30, 58, 138, 0.1);
  background: white;
}

.search-input-wrapper .btn-search {
  position: absolute;
  right: 6px;
  top: 50%;
  transform: translateY(-50%);
  height: 52px;
  padding: 0 32px;
  border-radius: 12px;
  background: linear-gradient(135deg, var(--primary), var(--accent));
  border: none;
  color: white;
  font-weight: 600;
  font-size: 16px;
  transition: all 0.3s ease;
  box-shadow: 0 4px 12px rgba(30, 58, 138, 0.3);
}

.search-input-wrapper .btn-search:hover {
  transform: translateY(-50%) scale(1.02);
  box-shadow: 0 6px 16px rgba(30, 58, 138, 0.4);
}

/* Action Buttons */
.action-buttons {
  display: flex;
  gap: 12px;
  justify-content: center;
  flex-wrap: wrap;
}

.btn-action {
  height: 56px;
  padding: 0 32px;
  border-radius: 14px;
  font-weight: 600;
  font-size: 16px;
  border: none;
  display: inline-flex;
  align-items: center;
  gap: 10px;
  transition: all 0.3s ease;
  box-shadow: var(--shadow-sm);
}

.btn-action i {
  font-size: 20px;
}

.btn-keyboard {
  background: linear-gradient(135deg, #f59e0b, #f97316);
  color: white;
}

.btn-keyboard:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(245, 158, 11, 0.4);
  color: white;
}

.btn-exit {
  background: linear-gradient(135deg, #dc2626, #ef4444);
  color: white;
}

.btn-exit:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(220, 38, 38, 0.4);
  color: white;
}

/* Table Container */
.table-container {
  background: rgba(255, 255, 255, 0.98);
  backdrop-filter: blur(20px);
  border-radius: 20px;
  padding: 32px;
  box-shadow: var(--shadow-md);
  border: 1px solid rgba(255, 255, 255, 0.3);
  overflow: hidden;
}

.table-responsive {
  border-radius: 12px;
  overflow: hidden;
}

.table {
  margin: 0;
  background: white;
}

.table thead th {
  background: linear-gradient(135deg, var(--primary), var(--primary-dark));
  color: white;
  font-weight: 600;
  font-size: 14px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  padding: 18px 16px;
  border: none;
  white-space: nowrap;
}

.table tbody tr {
  transition: all 0.2s ease;
  border-bottom: 1px solid #f1f5f9;
}

.table tbody tr:hover {
  background: #f8fafc;
  transform: scale(1.01);
}

.table tbody td {
  padding: 18px 16px;
  vertical-align: middle;
  color: #334155;
  font-size: 14px;
  font-weight: 500;
}

.btn-pilih {
  background: linear-gradient(135deg, var(--success), #14b8a6);
  color: white;
  border: none;
  padding: 10px 24px;
  border-radius: 10px;
  font-weight: 600;
  font-size: 14px;
  transition: all 0.3s ease;
  box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
  display: inline-flex;
  align-items: center;
  gap: 8px;
}

.btn-pilih:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
  color: white;
}

/* Alert Styles */
.alert {
  border-radius: 14px;
  border: none;
  padding: 20px 24px;
  font-weight: 500;
  box-shadow: var(--shadow-sm);
}

.alert-warning {
  background: linear-gradient(135deg, #fef3c7, #fde68a);
  color: #92400e;
}

/* Modal Premium */
.modal-content {
  border: none;
  border-radius: 24px;
  overflow: hidden;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
  background: white;
}

.modal-header {
  background: linear-gradient(135deg, var(--primary), var(--accent));
  color: white;
  padding: 24px 32px;
  border: none;
}

.modal-header .modal-title {
  font-size: 24px;
  font-weight: 700;
  display: flex;
  align-items: center;
  gap: 12px;
}

.modal-header .btn-close {
  filter: brightness(0) invert(1);
  opacity: 0.8;
}

.modal-body {
  padding: 32px;
  background: #f8fafc;
}

.modal-body .form-label {
  font-weight: 600;
  color: var(--dark);
  margin-bottom: 10px;
  font-size: 14px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.modal-body .form-control,
.modal-body .form-select {
  height: 52px;
  border-radius: 12px;
  border: 2px solid #e2e8f0;
  font-size: 15px;
  font-weight: 500;
  padding: 0 16px;
  transition: all 0.3s ease;
  background: white;
}

.modal-body .form-control:focus,
.modal-body .form-select:focus {
  border-color: var(--primary);
  box-shadow: 0 0 0 4px rgba(30, 58, 138, 0.1);
}

.modal-body .form-control[readonly] {
  background: #f1f5f9;
  color: #64748b;
  cursor: not-allowed;
}

.modal-footer {
  padding: 24px 32px;
  border: none;
  background: white;
  gap: 12px;
}

.btn-modal {
  height: 52px;
  padding: 0 40px;
  border-radius: 12px;
  font-weight: 600;
  font-size: 16px;
  border: none;
  display: inline-flex;
  align-items: center;
  gap: 10px;
  transition: all 0.3s ease;
}

.btn-modal-success {
  background: linear-gradient(135deg, var(--success), #14b8a6);
  color: white;
  box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.btn-modal-success:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4);
  color: white;
}

.btn-modal-secondary {
  background: #e2e8f0;
  color: #475569;
}

.btn-modal-secondary:hover {
  background: #cbd5e1;
  color: #334155;
}

/* Virtual Keyboard Premium */
.virtual-keyboard {
  position: fixed;
  bottom: 20px;
  left: 50%;
  transform: translateX(-50%);
  background: rgba(255, 255, 255, 0.98);
  backdrop-filter: blur(20px);
  border: 1px solid rgba(255, 255, 255, 0.3);
  border-radius: 20px;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
  padding: 24px;
  z-index: 2000;
  display: none;
  width: 95%;
  max-width: 900px;
}

.keyboard-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
  padding-bottom: 16px;
  border-bottom: 2px solid #e2e8f0;
}

.keyboard-title {
  font-size: 16px;
  font-weight: 700;
  color: var(--primary);
  display: flex;
  align-items: center;
  gap: 8px;
}

#closeKeyboard {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  background: linear-gradient(135deg, var(--danger), #f87171);
  color: white;
  border: none;
  font-size: 24px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.3s ease;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
}

#closeKeyboard:hover {
  transform: scale(1.1) rotate(90deg);
  box-shadow: 0 6px 16px rgba(239, 68, 68, 0.4);
}

.key-row {
  display: flex;
  justify-content: center;
  gap: 8px;
  margin-bottom: 10px;
}

.key {
  min-width: 60px;
  height: 56px;
  background: linear-gradient(135deg, #f8fafc, #f1f5f9);
  color: var(--dark);
  border: 2px solid #e2e8f0;
  border-radius: 12px;
  font-size: 18px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s ease;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
  display: flex;
  align-items: center;
  justify-content: center;
}

.key:hover {
  background: linear-gradient(135deg, white, #f8fafc);
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
  border-color: var(--primary);
}

.key:active {
  transform: translateY(0);
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.key.special {
  background: linear-gradient(135deg, var(--primary), var(--accent));
  color: white;
  border-color: var(--primary);
  min-width: 100px;
  font-size: 14px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.key.special:hover {
  background: linear-gradient(135deg, var(--primary-dark), var(--primary));
  border-color: var(--primary-dark);
}

/* Responsive Design */
@media (max-width: 768px) {
  body {
    padding: 12px;
  }

  .header-section {
    padding: 20px;
    border-radius: 16px;
  }

  .header-section h3 {
    font-size: 22px;
  }

  .header-section h5 {
    font-size: 14px;
  }

  .header-icon {
    width: 40px;
    height: 40px;
    font-size: 20px;
    margin-right: 12px;
  }

  .search-card {
    padding: 20px;
    border-radius: 16px;
  }

  .search-input-wrapper .form-control {
    height: 56px;
    font-size: 16px;
    padding: 0 100px 0 16px;
  }

  .search-input-wrapper .btn-search {
    height: 44px;
    padding: 0 20px;
    font-size: 14px;
  }

  .btn-action {
    height: 48px;
    padding: 0 24px;
    font-size: 14px;
  }

  .table-container {
    padding: 20px;
    border-radius: 16px;
  }

  .table thead th {
    font-size: 12px;
    padding: 14px 12px;
  }

  .table tbody td {
    font-size: 13px;
    padding: 14px 12px;
  }

  .modal-body {
    padding: 24px;
  }

  .modal-footer {
    padding: 20px 24px;
  }

  .virtual-keyboard {
    width: 98%;
    padding: 16px;
    border-radius: 16px;
  }

  .key {
    min-width: 48px;
    height: 48px;
    font-size: 16px;
    border-radius: 10px;
  }

  .key.special {
    min-width: 80px;
    font-size: 12px;
  }
}

/* Loading Animation */
@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.6; }
}

.loading {
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}

/* Smooth Scrollbar */
::-webkit-scrollbar {
  width: 10px;
  height: 10px;
}

::-webkit-scrollbar-track {
  background: #f1f5f9;
  border-radius: 10px;
}

::-webkit-scrollbar-thumb {
  background: linear-gradient(135deg, var(--primary), var(--accent));
  border-radius: 10px;
}

::-webkit-scrollbar-thumb:hover {
  background: linear-gradient(135deg, var(--primary-dark), var(--primary));
}
</style>
</head>
<body>

<div class="main-container">
  <!-- Header Section -->
  <div class="header-section text-center">
    <div class="d-flex align-items-center justify-content-center">
      <span class="header-icon">
        <i class="bi bi-hospital"></i>
      </span>
      <div class="text-start">
        <h3 class="mb-0">ANJUNGAN PENDAFTARAN PASIEN MANDIRI</h3>
        <h5 class="mb-0">Silakan cari data pasien untuk mendaftar ke poliklinik tujuan</h5>
      </div>
    </div>
  </div>

  <!-- Search Card -->
  <div class="search-card">
    <form method="get">
      <div class="search-input-wrapper">
        <input 
          type="text" 
          id="inputCari" 
          name="cari" 
          class="form-control" 
          placeholder="Ketik No. RM atau Nama Pasien..." 
          value="<?= htmlspecialchars($_GET['cari'] ?? '') ?>"
          autocomplete="off"
        >
        <button class="btn-search" type="submit">
          <i class="bi bi-search"></i> CARI
        </button>
      </div>
    </form>

    <div class="action-buttons">
      <button type="button" class="btn-action btn-keyboard" onclick="toggleKeyboard()">
        <i class="bi bi-keyboard-fill"></i>
        KEYBOARD VIRTUAL
      </button>
      <a href="anjungan.php" class="btn-action btn-exit">
        <i class="bi bi-box-arrow-left"></i>
        KELUAR
      </a>
    </div>
  </div>

  <!-- Results Table -->
  <?php
  if (isset($_GET['cari'])) {
      $keyword = trim($_GET['cari']);
      $stmt = $pdo_simrs->prepare("SELECT no_rkm_medis,nm_pasien,jk,tgl_lahir,alamat FROM pasien WHERE no_rkm_medis LIKE ? OR nm_pasien LIKE ? LIMIT 20");
      $stmt->execute(["%$keyword%", "%$keyword%"]);
      $pasien = $stmt->fetchAll(PDO::FETCH_ASSOC);

      if (!$pasien) {
          echo "<div class='table-container'>
                  <div class='alert alert-warning' role='alert'>
                    <i class='bi bi-exclamation-triangle-fill me-2'></i>
                    <strong>Data tidak ditemukan.</strong> Silakan periksa kembali No. RM atau Nama Pasien yang Anda masukkan.
                  </div>
                </div>";
      } else {
          echo "<div class='table-container'>";
          echo "<div class='table-responsive'>";
          echo "<table class='table table-hover align-middle mb-0'>";
          echo "<thead>
                  <tr>
                    <th>NO. RM</th>
                    <th>NAMA PASIEN</th>
                    <th>JENIS KELAMIN</th>
                    <th>TANGGAL LAHIR</th>
                    <th>ALAMAT</th>
                    <th>AKSI</th>
                  </tr>
                </thead>
                <tbody>";
          
          foreach ($pasien as $p) {
              $no = htmlspecialchars($p['no_rkm_medis']);
              $nm = htmlspecialchars($p['nm_pasien']);
              $jk = htmlspecialchars($p['jk']);
              $tgl_lahir = htmlspecialchars($p['tgl_lahir']);
              $alamat = htmlspecialchars($p['alamat']);
              
              // Format tanggal lahir
              $tgl_format = date('d/m/Y', strtotime($tgl_lahir));
              
              echo "<tr>
                  <td><strong>{$no}</strong></td>
                  <td>{$nm}</td>
                  <td class='text-center'><span class='badge bg-secondary'>{$jk}</span></td>
                  <td class='text-center'>{$tgl_format}</td>
                  <td>{$alamat}</td>
                  <td class='text-center'>
                      <button type='button' class='btn-pilih' data-bs-toggle='modal' data-bs-target='#modalDaftar' 
                      data-norm='{$no}' data-nama='{$nm}'>
                      <i class='bi bi-person-check-fill'></i> PILIH
                      </button>
                  </td>
              </tr>";
          }
          echo "</tbody></table></div></div>";
      }
  }
  ?>
</div>

<!-- Modal Daftar Poli -->
<div class="modal fade" id="modalDaftar" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <form method="post" id="formDaftar">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">
            <i class="bi bi-clipboard2-pulse-fill"></i>
            FORMULIR PENDAFTARAN POLIKLINIK
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        
        <div class="modal-body">
          <input type="hidden" name="no_rkm_medis" id="no_rkm_medis">
          
          <div class="row g-4">
            <div class="col-md-6">
              <label class="form-label">
                <i class="bi bi-person-circle"></i>
                NAMA PASIEN
              </label>
              <input type="text" id="nama_pasien" class="form-control" readonly>
            </div>
            
            <div class="col-md-6">
              <label class="form-label">
                <i class="bi bi-building-fill-add"></i>
                POLIKLINIK TUJUAN
              </label>
              <select name="kd_poli" id="kd_poli" class="form-select" required>
                <option value="">-- Pilih Poliklinik --</option>
                <?php
                $poli = $pdo_simrs->prepare("SELECT DISTINCT j.kd_poli, p.nm_poli FROM jadwal j 
                                             JOIN poliklinik p ON j.kd_poli=p.kd_poli 
                                             WHERE j.hari_kerja=? ORDER BY p.nm_poli");
                $poli->execute([$hari_indo]);
                foreach ($poli as $pl) {
                    $kd = htmlspecialchars($pl['kd_poli']);
                    $nm = htmlspecialchars($pl['nm_poli']);
                    echo "<option value='{$kd}'>{$nm}</option>";
                }
                ?>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label">
                <i class="bi bi-person-badge-fill"></i>
                DOKTER PEMERIKSA
              </label>
              <select name="kd_dokter" id="kd_dokter" class="form-select" required>
                <option value="">-- Pilih Dokter --</option>
                <?php
                $dok = $pdo_simrs->prepare("SELECT DISTINCT j.kd_dokter, d.nm_dokter FROM jadwal j 
                                            JOIN dokter d ON j.kd_dokter=d.kd_dokter 
                                            WHERE j.hari_kerja=? ORDER BY d.nm_dokter");
                $dok->execute([$hari_indo]);
                foreach ($dok as $d) {
                    $kd = htmlspecialchars($d['kd_dokter']);
                    $nm = htmlspecialchars($d['nm_dokter']);
                    echo "<option value='{$kd}'>{$nm}</option>";
                }
                ?>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label">
                <i class="bi bi-credit-card-fill"></i>
                CARA PEMBAYARAN
              </label>
              <select name="kd_pj" class="form-select" required>
                <option value="">-- Pilih Cara Bayar --</option>
                <?php
                $penjab = $pdo_simrs->query("SELECT kd_pj, png_jawab FROM penjab ORDER BY png_jawab");
                foreach ($penjab as $pj) {
                    $kd = htmlspecialchars($pj['kd_pj']);
                    $pn = htmlspecialchars($pj['png_jawab']);
                    echo "<option value='{$kd}'>{$pn}</option>";
                }
                ?>
              </select>
            </div>

            <div class="col-12">
              <div class="alert alert-info mb-0" style="background: linear-gradient(135deg, #dbeafe, #bfdbfe); border: none; color: #1e40af;">
                <i class="bi bi-info-circle-fill me-2"></i>
                <strong>Perhatian:</strong> Pastikan semua data yang Anda isi sudah benar sebelum menekan tombol <strong>SIMPAN</strong>.
              </div>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="submit" class="btn-modal btn-modal-success">
            <i class="bi bi-check-circle-fill"></i>
            SIMPAN PENDAFTARAN
          </button>
          <button type="button" class="btn-modal btn-modal-secondary" data-bs-dismiss="modal">
            <i class="bi bi-x-circle-fill"></i>
            BATAL
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Virtual Keyboard Premium -->
<div id="keyboard" class="virtual-keyboard">
  <div class="keyboard-header">
    <div class="keyboard-title">
      <i class="bi bi-keyboard"></i>
      KEYBOARD VIRTUAL
    </div>
    <button id="closeKeyboard">×</button>
  </div>
  <div class="key-row" id="row1"></div>
  <div class="key-row" id="row2"></div>
  <div class="key-row" id="row3"></div>
  <div class="key-row" id="row4"></div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
/* Modal fill data */
const modal = document.getElementById('modalDaftar');
modal.addEventListener('show.bs.modal', e => {
  const btn = e.relatedTarget;
  document.getElementById('no_rkm_medis').value = btn.dataset.norm;
  document.getElementById('nama_pasien').value = btn.dataset.nama;
});

/* Virtual Keyboard */
const keyboard = document.getElementById('keyboard');
const inputCari = document.getElementById('inputCari');
const keys1 = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'];
const keys2 = ['Q', 'W', 'E', 'R', 'T', 'Y', 'U', 'I', 'O', 'P'];
const keys3 = ['A', 'S', 'D', 'F', 'G', 'H', 'J', 'K', 'L'];
const keys4 = ['Z', 'X', 'C', 'V', 'B', 'N', 'M', 'Backspace', 'Space'];

function renderKeys(keys, rowId) {
  const row = document.getElementById(rowId);
  keys.forEach(k => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'key' + (k === 'Backspace' || k === 'Space' ? ' special' : '');
    
    if (k === 'Space') {
      btn.innerHTML = '<i class="bi bi-space"></i> SPASI';
    } else if (k === 'Backspace') {
      btn.innerHTML = '<i class="bi bi-backspace"></i> HAPUS';
    } else {
      btn.textContent = k;
    }
    
    btn.onclick = () => pressKey(k);
    row.appendChild(btn);
  });
}

function pressKey(k) {
  if (k === 'Backspace') {
    inputCari.value = inputCari.value.slice(0, -1);
  } else if (k === 'Space') {
    inputCari.value += ' ';
  } else {
    inputCari.value += k;
  }
  inputCari.focus();
}

function toggleKeyboard() {
  if (keyboard.style.display === 'block') {
    keyboard.style.display = 'none';
  } else {
    keyboard.style.display = 'block';
    inputCari.focus();
  }
}

document.getElementById('closeKeyboard').onclick = () => {
  keyboard.style.display = 'none';
};

// Render keyboard
renderKeys(keys1, 'row1');
renderKeys(keys2, 'row2');
renderKeys(keys3, 'row3');
renderKeys(keys4, 'row4');

// Auto focus input when page loads
window.addEventListener('load', () => {
  inputCari.focus();
});

/* SweetAlert2 Custom Theme */
const swalWithCustom = Swal.mixin({
  customClass: {
    confirmButton: 'btn-modal btn-modal-success',
    cancelButton: 'btn-modal btn-modal-secondary',
    popup: 'swal-custom-popup'
  },
  buttonsStyling: false
});
</script>

<?php if ($swal_data): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  const data = <?= json_encode($swal_data, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;

  if (data.icon === 'success') {
    swalWithCustom.fire({
      icon: data.icon,
      title: data.title,
      html: `<div style="font-size: 18px; line-height: 1.8; color: #334155;">${data.html || data.text || ''}</div>`,
      showCancelButton: true,
      confirmButtonText: '<i class="bi bi-printer-fill me-2"></i>' + (data.confirmText || 'Cetak'),
      cancelButtonText: '<i class="bi bi-x-circle me-2"></i>' + (data.cancelText || 'Tutup'),
      allowOutsideClick: false,
      allowEscapeKey: false,
      width: '600px',
      padding: '32px',
      backdrop: 'rgba(30, 58, 138, 0.4)',
      iconColor: '#10b981'
    }).then((result) => {
      if (result.isConfirmed) {
        if (data.printUrl) window.open(data.printUrl, '_blank');
        window.location = data.redirect || 'daftar_poli.php';
      } else {
        window.location = data.redirect || 'daftar_poli.php';
      }
    });
  } else {
    swalWithCustom.fire({
      icon: data.icon || 'error',
      title: data.title || 'Perhatian',
      html: `<div style="font-size: 18px; color: #334155;">${data.text || ''}</div>`,
      confirmButtonText: '<i class="bi bi-check-circle me-2"></i>' + (data.confirmText || 'OK'),
      allowOutsideClick: false,
      allowEscapeKey: false,
      width: '500px',
      padding: '32px',
      backdrop: 'rgba(239, 68, 68, 0.4)',
      iconColor: '#ef4444'
    }).then(() => {
      window.location = data.redirect || 'daftar_poli.php';
    });
  }
});
</script>
<?php endif; ?>

</body>
</html>