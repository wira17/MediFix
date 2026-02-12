<?php
session_start();
date_default_timezone_set('Asia/Jakarta');
include 'koneksi2.php';

$nama = $_SESSION['nama'] ?? 'Pengguna';
$qr_path = 'qrcode/JKN/qr-code.jpg';
$msg = "";

// Ambil data pasien berdasarkan input nama atau nobooking
$patient = null;
if (isset($_POST['search']) && !empty($_POST['search_name'])) {
    $search = "%".$_POST['search_name']."%";
    $stmt = $pdo_simrs->prepare("SELECT * FROM referensi_mobilejkn_bpjs WHERE nomorkartu LIKE :search OR nobooking LIKE :search OR nik LIKE :search OR norm LIKE :search LIMIT 1");
    $stmt->execute([':search' => $search]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Proses Check-In
if (isset($_POST['checkin']) && !empty($_POST['nobooking'])) {
    $nobooking = $_POST['nobooking'];

    try {
        // 1. Update status di referensi_mobilejkn_bpjs
        $upd = $pdo_simrs->prepare("UPDATE referensi_mobilejkn_bpjs 
                                    SET status='Checkin', validasi=NOW() 
                                    WHERE nobooking=:nobooking");
        $upd->execute([':nobooking' => $nobooking]);

        // 2. Hapus data di referensi_mobilejkn_bpjs_batal
        $del = $pdo_simrs->prepare("DELETE FROM referensi_mobilejkn_bpjs_batal WHERE nobooking=:nobooking");
        $del->execute([':nobooking' => $nobooking]);

        $msg = "<div class='alert alert-success text-center'>
                    <i class='bi bi-check-circle'></i> Check-In berhasil!<br>
                    Nobooking: <strong>$nobooking</strong>
                </div>";
        $patient = null; // reset input setelah checkin
    } catch (PDOException $e) {
        $msg = "<div class='alert alert-danger text-center'>Terjadi kesalahan: " . $e->getMessage() . "</div>";
    }
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Check-In JKN</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
<style>
body {
    background: linear-gradient(135deg, #ff9800, #2196f3, #f44336, #ffeb3b);
    background-size: 400% 400%;
    animation: gradientMove 12s ease infinite;
    font-family:'Segoe UI',sans-serif;
    min-height:100vh;
}

@keyframes gradientMove {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}

.qr-container {
    text-align:center;
    background:white;
    padding:40px;
    border-radius:25px;
    box-shadow:0 10px 25px rgba(0,0,0,0.15);
    max-width:480px;
    margin:0 auto;
    border-top:6px solid #2196f3;
}

.qr-container img {
    width:100%;
    max-width:300px;
    border-radius:20px;
    border:4px solid #ff9800;
    margin-bottom:20px;
}

.btn-action {
    border-radius:12px;
    font-weight:600;
    padding:12px;
    width:100%;
    font-size:16px;
}

.btn-primary {
    background:#2196f3;
    border:none;
}

.btn-success {
    background:#4caf50;
    border:none;
}

.btn-warning {
    background:#ff9800;
    border:none;
    color:#fff;
}

.btn-secondary {
    background:#f44336;
    border:none;
    color:white;
}

.btn-group-custom {
    display:flex;
    flex-direction:column;
    gap:12px;
    margin-top:15px;
}

.alert-info {
    border-left:5px solid #2196f3;
}
</style>

</head>
<body>

<div class="container py-5">
  <div class="text-center mb-4">
    <h3><i class="bi bi-person-check"></i> Check-In JKN</h3>
    <p class="text-muted">Masukkan nama pasien atau nobooking untuk mencari data.</p>
  </div>

  <?= $msg ?>

  <div class="qr-container">
    <?php if (file_exists($qr_path)): ?>
        <img src="<?= htmlspecialchars($qr_path) ?>" alt="QR Code JKN">
    <?php endif; ?>

    <!-- Form cari pasien -->
    <form method="POST" class="btn-group-custom">
        <input type="text" name="search_name" class="form-control text-center" placeholder="Ketik nama / Nobooking / NIK" required>
        <button type="submit" name="search" class="btn btn-primary btn-action">
            <i class="bi bi-search"></i> Cari
        </button>
    </form>

    <?php if($patient): ?>
        <form method="POST" class="btn-group-custom mt-3">
            <input type="hidden" name="nobooking" value="<?= htmlspecialchars($patient['nobooking']) ?>">
            <div class="alert alert-info text-start">
                <strong>Data Pasien:</strong><br>
                Nama: <?= htmlspecialchars($patient['norm'] . ' / ' . $patient['nik'] ?? '') ?><br>
                Nomor Booking: <?= htmlspecialchars($patient['nobooking']) ?><br>
                No HP: <?= htmlspecialchars($patient['nohp']) ?><br>
                Jenis Kunjungan: <?= htmlspecialchars($patient['jeniskunjungan']) ?><br>
                Poli: <?= htmlspecialchars($patient['kodepoli']) ?><br>
                Dokter: <?= htmlspecialchars($patient['kodedokter']) ?>
            </div>
            <button type="submit" name="checkin" class="btn btn-success btn-action">
                <i class="bi bi-person-check"></i> Check-In
            </button>
            <a href="anjungan.php" class="btn btn-secondary btn-action">
                <i class="bi bi-box-arrow-left"></i> Keluar
            </a>
        </form>
    <?php endif; ?>

  </div>
</div>


</body>
</html>
