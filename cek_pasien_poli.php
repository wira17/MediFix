<?php
session_start();
include 'koneksi.php';
include 'koneksi2.php';

date_default_timezone_set('Asia/Jakarta');

// Ambil data setting untuk nama PPK
$setting = $pdo->query("SELECT * FROM setting_vclaim LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$nm_ppk = $setting['nm_ppk'] ?? 'RS Permata Hati';
$kd_ppk = $setting['kd_ppk'] ?? '0081R007';

// Proses pencarian
$result = [];
$error = '';
$no_rm_search = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cari_pasien'])) {
    $search_param = trim($_POST['search_param']);
    $tgl_periksa = $_POST['tgl_periksa'] ?? date('Y-m-d');
    
    if (empty($search_param)) {
        $error = "Masukkan No. RM atau Nama Pasien!";
    } else {
        try {
            // Query untuk mencari pasien berdasarkan no_rm atau nama, dengan registrasi pada tanggal tertentu
            $sql = "SELECT 
                        p.no_rkm_medis,
                        p.nm_pasien,
                        p.no_peserta,
                        p.jk,
                        p.tgl_lahir,
                        p.no_tlp,
                        r.no_rawat,
                        r.tgl_registrasi,
                        r.jam_reg,
                        r.kd_dokter,
                        d.nm_dokter,
                        r.kd_poli,
                        pl.nm_poli,
                        pj.png_jawab,
                        r.status_lanjut,
                        r.stts,
                        r.kd_pj
                    FROM reg_periksa r
                    INNER JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
                    LEFT JOIN dokter d ON r.kd_dokter = d.kd_dokter
                    LEFT JOIN poliklinik pl ON r.kd_poli = pl.kd_poli
                    LEFT JOIN penjab pj ON r.kd_pj = pj.kd_pj
                    WHERE r.tgl_registrasi = :tgl
                    AND (p.no_rkm_medis LIKE :search OR p.nm_pasien LIKE :search)
                    ORDER BY r.jam_reg DESC";
            
            $stmt = $pdo_simrs->prepare($sql);
            $stmt->execute([
                'tgl' => $tgl_periksa,
                'search' => "%$search_param%"
            ]);
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($result)) {
                $error = "Data tidak ditemukan untuk tanggal $tgl_periksa!";
            }
            
            $no_rm_search = $search_param;
        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>🔍 Cek Pasien & Cetak SEP</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<style>
body {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
    padding: 20px;
}
.main-card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: 20px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}
.card-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 20px 20px 0 0 !important;
    padding: 1.5rem;
}
.search-box {
    background: white;
    padding: 2rem;
    border-radius: 15px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}
.table-responsive {
    border-radius: 10px;
    overflow: hidden;
}
.table {
    margin-bottom: 0;
}
.badge-success {
    background: #10b981;
}
.badge-warning {
    background: #f59e0b;
}
.badge-danger {
    background: #ef4444;
}
.badge-info {
    background: #3b82f6;
}
.btn-cetak {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    border: none;
    color: white;
    transition: all 0.3s;
}
.btn-cetak:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(16, 185, 129, 0.4);
    color: white;
}
.btn-buat-sep {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    border: none;
    color: white;
    transition: all 0.3s;
}
.btn-buat-sep:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(245, 158, 11, 0.4);
    color: white;
}
.alert {
    border-radius: 10px;
}
.info-box {
    background: #f8f9fa;
    padding: 1rem;
    border-radius: 10px;
    margin-bottom: 1rem;
}
.sep-status {
    font-size: 11px;
    display: block;
    margin-top: 3px;
}
</style>
</head>
<body>
<div class="container">
    <div class="main-card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h3 class="mb-0"><i class="bi bi-search"></i> Cek Pasien & Cetak SEP (Poli)</h3>
                <a href="anjungan.php" class="btn btn-light">
                    <i class="bi bi-house-door"></i> Beranda
                </a>
            </div>
        </div>
        <div class="card-body p-4">
            
            <!-- Form Pencarian -->
            <div class="search-box mb-4">
                <form method="POST">
                    <input type="hidden" name="cari_pasien" value="1">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label fw-bold">
                                <i class="bi bi-person-circle"></i> No. RM / Nama Pasien
                            </label>
                            <input type="text" 
                                   class="form-control form-control-lg" 
                                   name="search_param" 
                                   placeholder="Masukkan No. RM atau Nama Pasien"
                                   value="<?= htmlspecialchars($no_rm_search) ?>"
                                   required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">
                                <i class="bi bi-calendar-check"></i> Tanggal Periksa
                            </label>
                            <input type="date" 
                                   class="form-control form-control-lg" 
                                   name="tgl_periksa" 
                                   value="<?= $_POST['tgl_periksa'] ?? date('Y-m-d') ?>"
                                   required>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary btn-lg w-100">
                                <i class="bi bi-search"></i> Cari Pasien
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Alert Error -->
            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <strong>Error!</strong> <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Hasil Pencarian -->
            <?php if (!empty($result)): ?>
            <div class="info-box">
                <div class="row">
                    <div class="col-md-6">
                        <strong><i class="bi bi-info-circle-fill text-primary"></i> Informasi:</strong>
                    </div>
                    <div class="col-md-6 text-end">
                        <strong>Total Data: <?= count($result) ?> registrasi</strong>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover table-striped">
                    <thead class="table-dark">
                        <tr>
                            <th width="50">No</th>
                            <th>No. RM</th>
                            <th>Nama Pasien</th>
                            <th>No. Kartu BPJS</th>
                            <th>No. Rawat</th>
                            <th>Tgl Daftar</th>
                            <th>Jam</th>
                            <th>Poli</th>
                            <th>Dokter</th>
                            <th>Penjamin</th>
                            <th>Status</th>
                            <th width="200">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $no = 1;
                        foreach ($result as $row): 
                            // Cek apakah sudah ada SEP di bridging_sep
                            $stmt_sep = $pdo_simrs->prepare("SELECT no_sep FROM bridging_sep WHERE no_rawat = ?");
                            $stmt_sep->execute([$row['no_rawat']]);
                            $sep_data = $stmt_sep->fetch(PDO::FETCH_ASSOC);
                            
                            // Tentukan status badge pelayanan
                            $status_badge = 'secondary';
                            $status_text = $row['stts'];
                            switch($row['stts']) {
                                case 'Sudah':
                                    $status_badge = 'success';
                                    break;
                                case 'Belum':
                                    $status_badge = 'warning';
                                    break;
                                case 'Batal':
                                    $status_badge = 'danger';
                                    break;
                            }
                            
                            // Cek apakah pasien BPJS
                            $is_bpjs = (strtoupper($row['kd_pj']) == 'BPJ' || strtoupper($row['kd_pj']) == 'BPJS');
                            $has_no_peserta = !empty($row['no_peserta']);
                        ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td><strong><?= htmlspecialchars($row['no_rkm_medis']) ?></strong></td>
                            <td><?= htmlspecialchars($row['nm_pasien']) ?></td>
                            <td><?= htmlspecialchars($row['no_peserta'] ?: '-') ?></td>
                            <td><code><?= htmlspecialchars($row['no_rawat']) ?></code></td>
                            <td><?= date('d/m/Y', strtotime($row['tgl_registrasi'])) ?></td>
                            <td><?= date('H:i', strtotime($row['jam_reg'])) ?></td>
                            <td><?= htmlspecialchars($row['nm_poli']) ?></td>
                            <td><?= htmlspecialchars($row['nm_dokter']) ?></td>
                            <td><span class="badge badge-info"><?= htmlspecialchars($row['png_jawab']) ?></span></td>
                            <td><span class="badge badge-<?= $status_badge ?>"><?= $status_text ?></span></td>
                            <td>
                                <?php if ($sep_data): ?>
                                    <!-- ✅ Tombol Cetak SEP - Muncul jika data SEP sudah ada -->
                                    <a href="cetak_sep_poli.php?no_rawat=<?= urlencode($row['no_rawat']) ?>" 
                                       class="btn btn-success btn-sm w-100 mb-1" 
                                       target="_blank"
                                       title="Cetak SEP: <?= htmlspecialchars($sep_data['no_sep']) ?>">
                                        <i class="bi bi-printer-fill"></i> Cetak SEP
                                    </a>
                                    <small class="sep-status text-success">
                                        <i class="bi bi-check-circle-fill"></i> No. SEP: <?= htmlspecialchars($sep_data['no_sep']) ?>
                                    </small>
                                <?php else: ?>
                                    <!-- ⚠️ SEP Belum Ada -->
                                    <?php if ($is_bpjs && $has_no_peserta): ?>
                                        <!-- Tombol Buat SEP - Untuk pasien BPJS yang belum punya SEP -->
                                        <a href="cetak_sep_poli.php?no_rawat=<?= urlencode($row['no_rawat']) ?>&no_rm=<?= urlencode($row['no_rkm_medis']) ?>" 
                                           class="btn btn-warning btn-sm w-100 mb-1 btn-buat-sep"
                                           title="Buat SEP baru untuk pasien ini">
                                            <i class="bi bi-file-earmark-plus"></i> Buat SEP
                                        </a>
                                        <small class="sep-status text-warning">
                                            <i class="bi bi-exclamation-triangle"></i> Belum ada SEP
                                        </small>
                                    <?php else: ?>
                                        <!-- Badge untuk Non-BPJS atau tidak ada nomor peserta -->
                                        <span class="badge bg-secondary w-100" 
                                              title="<?= !$is_bpjs ? 'Pasien bukan BPJS' : 'No. Peserta BPJS kosong' ?>">
                                            <i class="bi bi-x-circle"></i> 
                                            <?= !$is_bpjs ? 'Bukan BPJS' : 'No. Peserta Kosong' ?>
                                        </span>
                                        <small class="sep-status text-muted">
                                            <i class="bi bi-info-circle"></i> Tidak perlu SEP
                                        </small>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if (empty($result) && $_SERVER['REQUEST_METHOD'] === 'POST' && !$error): ?>
            <div class="alert alert-info text-center">
                <i class="bi bi-info-circle-fill"></i> 
                Silakan lakukan pencarian pasien terlebih dahulu.
            </div>
            <?php endif; ?>

            <!-- Petunjuk Penggunaan -->
            <?php if (empty($result) && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
            <div class="alert alert-info">
                <h5><i class="bi bi-lightbulb"></i> Petunjuk Penggunaan:</h5>
                <ol class="mb-0">
                    <li>Masukkan <strong>No. RM</strong> atau <strong>Nama Pasien</strong> pada kolom pencarian</li>
                    <li>Pilih <strong>Tanggal Periksa</strong> (default: hari ini)</li>
                    <li>Klik tombol <strong>"Cari Pasien"</strong></li>
                    <li>Sistem akan menampilkan status SEP untuk setiap registrasi:
                        <ul>
                            <li><span class="badge bg-success">Cetak SEP</span> - SEP sudah ada, bisa dicetak</li>
                            <li><span class="badge bg-warning">Buat SEP</span> - Pasien BPJS, belum ada SEP (klik untuk membuat)</li>
                            <li><span class="badge bg-secondary">Bukan BPJS</span> - Pasien non-BPJS, tidak perlu SEP</li>
                        </ul>
                    </li>
                </ol>
                <hr>
                <p class="mb-0">
                    <strong><i class="bi bi-exclamation-triangle"></i> Catatan:</strong> 
                    Tombol "Buat SEP" akan redirect ke halaman pembuatan SEP. 
                    Pastikan Anda sudah menyiapkan file <code>buat_sep_manual.php</code> atau sesuaikan link-nya.
                </p>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- Info Footer -->
    <div class="text-center text-white mt-4">
        <small>
            <i class="bi bi-hospital"></i> <?= htmlspecialchars($nm_ppk) ?> | 
            Kode PPK: <?= htmlspecialchars($kd_ppk) ?>
        </small>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>