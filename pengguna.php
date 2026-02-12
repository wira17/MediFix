<?php
session_start();
include 'koneksi.php';

if (!isset($_SESSION['id_user'])) {
    header('Location: login.php');
    exit;
}

$nama = $_SESSION['nama'] ?? 'Pengguna';

// Ambil data pengguna
try {
    $stmt = $pdo->query("
        SELECT 
            id, 
            asal_instansi, 
            identitas, 
            nama, 
            email, 
            created_at, 
            status 
        FROM pengguna 
        ORDER BY id DESC
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $users = [];
    error_log($e->getMessage());
}

date_default_timezone_set('Asia/Jakarta');
$jam = date('H');
$sapaan = ($jam < 12) ? 'Selamat pagi' : (($jam < 17) ? 'Selamat siang' : 'Selamat malam');
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Data Pengguna - S.I.M.V.I.S</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<style>
body {
    background: #f4f6f9;
    font-family: 'Segoe UI', sans-serif;
    display: flex;
    flex-direction: column;
    min-height: 100vh;
}
.navbar {
    background: linear-gradient(90deg, #0055a5, #007bff, #00bfff);
}
.navbar-brand, .navbar-nav .nav-link {
    color: #fff !important;
}
.navbar .btn-outline-danger {
    border-color: #fff;
    color: #fff;
}
.navbar .btn-outline-danger:hover {
    background: #dc3545;
    border-color: #dc3545;
}
.page-header {
    background: linear-gradient(90deg, #0055a5, #007bff);
    color: white;
    border-radius: 12px;
    padding: 25px;
    box-shadow: 0 6px 15px rgba(0,0,0,0.1);
    margin-top: 20px;
}
.table-wrapper {
    background: #fff;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 3px 15px rgba(0,0,0,0.05);
}
.table thead {
    background: linear-gradient(90deg, #0055a5, #00bfff);
    color: #fff;
}
.table tbody tr:hover {
    background-color: #eef7ff;
    transition: 0.2s;
}
.btn-primary {
    background: linear-gradient(90deg, #007bff, #00bfff);
    border: none;
    color: #fff;
    font-weight: 600;
}
.btn-primary:hover {
    background: linear-gradient(90deg, #0069d9, #00a2e8);
}
.status-aktif {
    color: #198754;
    font-weight: 600;
}
.status-nonaktif {
    color: #dc3545;
    font-weight: 600;
}
footer {
    background: linear-gradient(90deg, #0055a5, #007bff, #00bfff);
    color: #fff;
    text-align: center;
    padding: 10px 0;
    font-size: 0.9rem;
    margin-top: auto;
    position: fixed;
    bottom: 0;
    width: 100%;
}
</style>
</head>
<body>

<!-- === NAVBAR === -->
<nav class="navbar navbar-expand-lg shadow-sm sticky-top">
  <div class="container">
    <a class="navbar-brand fw-bold" href="dashboard.php">
      <i class="bi bi-shield-plus"></i> S.I.M.V.I.S
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSIMVIS">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navbarSIMVIS">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
        <li class="nav-item"><a class="nav-link" href="permintaan_visum.php"><i class="bi bi-file-earmark-text"></i> Permintaan Visum</a></li>
        <li class="nav-item"><a class="nav-link" href="data_visum.php"><i class="bi bi-folder2-open"></i> Data Visum</a></li>
        <li class="nav-item"><a class="nav-link" href="laporan.php"><i class="bi bi-clipboard-data"></i> Laporan</a></li>
        <li class="nav-item"><a class="nav-link active fw-semibold" href="pengguna.php"><i class="bi bi-people"></i> Pengguna</a></li>
      </ul>

      <div class="d-flex align-items-center">
        <span class="me-3 text-light"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($nama) ?></span>
        <a href="logout.php" class="btn btn-outline-danger btn-sm"><i class="bi bi-box-arrow-right"></i> Logout</a>
      </div>
    </div>
  </div>
</nav>

<!-- === HEADER === -->
<div class="container">
  <div class="page-header text-center">
    <h3><i class="bi bi-people"></i> Data Pengguna Sistem Permintaan Visum</h3>
    <p class="mb-0 fs-5"><?= $sapaan ?>, <b><?= htmlspecialchars($nama) ?></b> 👋</p>
    <small><?= date('l, d F Y H:i') ?> WIB</small>
  </div>
</div>

<!-- === TABEL DATA === -->
<div class="container my-4 mb-5">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="fw-bold text-secondary"><i class="bi bi-list"></i> Daftar Pengguna Sistem</h5>
    <a href="register.php" class="btn btn-primary btn-sm"><i class="bi bi-person-plus"></i> Tambah Pengguna</a>
  </div>

  <div class="table-wrapper">
    <?php if (count($users) > 0): ?>
    <div class="table-responsive">
      <table class="table table-bordered table-hover align-middle text-center">
        <thead>
          <tr>
            <th>#</th>
            <th>Asal Instansi</th>
            <th>Identitas</th>
            <th>Nama</th>
            <th>Email</th>
            <th>Status</th>
            <th>Tanggal Daftar</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $i => $u): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><?= htmlspecialchars($u['asal_instansi'] ?? '-') ?></td>
            <td><?= htmlspecialchars($u['identitas'] ?? '-') ?></td>
            <td><?= htmlspecialchars($u['nama']) ?></td>
            <td><?= htmlspecialchars($u['email']) ?></td>
            <td>
              <?php if ($u['status'] == 'aktif'): ?>
                <span class="status-aktif"><i class="bi bi-check-circle-fill"></i> Aktif</span>
              <?php else: ?>
                <span class="status-nonaktif"><i class="bi bi-x-circle-fill"></i> Nonaktif</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars(date('d-m-Y H:i', strtotime($u['created_at']))) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
      <div class="alert alert-info text-center mb-0">Belum ada pengguna terdaftar.</div>
    <?php endif; ?>
  </div>
</div>

<!-- === FOOTER === -->
<footer>
  <i class="bi bi-shield-plus"></i> S.I.M.V.I.S - Sistem Informasi Permintaan Visum RSUD Panglima Sebaya © <?= date('Y') ?>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
