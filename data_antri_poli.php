<?php
session_start();
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

// === CEK LOGIN ===
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$nama = $_SESSION['nama'] ?? 'Pengguna';
$today = date('Y-m-d');

// === POLI YANG DISEMBUNYIKAN ===
$exclude_poli = ['IGDK','MCU01','PL010','PL011','PL013','PL014','PL015','PL016','PL017','U0022','U0026','U0028','U0030'];

// === PAGINATION ===
$limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 20;
$page  = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

// === FILTER POLI & DOKTER ===
$filter_poli   = $_GET['poli'] ?? '';
$filter_dokter = $_GET['dokter'] ?? '';

// === AMBIL DAFTAR POLI ===
try {
    $placeholders = implode(',', array_fill(0, count($exclude_poli), '?'));
    $sqlPoli = "SELECT kd_poli, nm_poli FROM poliklinik 
                WHERE status='1' AND kd_poli NOT IN ($placeholders)
                ORDER BY nm_poli ASC";
    $stmtPoli = $pdo_simrs->prepare($sqlPoli);
    $stmtPoli->execute($exclude_poli);
    $poliklinik = $stmtPoli->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Gagal ambil daftar poli: ".$e->getMessage());
}

// === AMBIL DAFTAR DOKTER ===
try {
    $sqlDokter = "SELECT kd_dokter, nm_dokter FROM dokter ORDER BY nm_dokter ASC";
    $stmtDokter = $pdo_simrs->query($sqlDokter);
    $dokterList = $stmtDokter->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Gagal ambil daftar dokter: ".$e->getMessage());
}

// === AMBIL STATISTIK ===
try {
    $placeholders = implode(',', array_fill(0, count($exclude_poli), '?'));
    
    // Total Pasien
    $sqlTotal = "SELECT COUNT(*) FROM reg_periksa r
                 LEFT JOIN poliklinik p ON r.kd_poli = p.kd_poli
                 WHERE r.tgl_registrasi = ? AND p.status='1' AND r.kd_poli NOT IN ($placeholders)";
    $stmtTotal = $pdo_simrs->prepare($sqlTotal);
    $params = [$today];
    $params = array_merge($params, $exclude_poli);
    $stmtTotal->execute($params);
    $total_pasien = (int)$stmtTotal->fetchColumn();
    
    // Sudah Dilayani
    $sqlSudah = "SELECT COUNT(*) FROM reg_periksa r
                 LEFT JOIN poliklinik p ON r.kd_poli = p.kd_poli
                 WHERE r.tgl_registrasi = ? AND r.stts='Sudah' AND p.status='1' AND r.kd_poli NOT IN ($placeholders)";
    $stmtSudah = $pdo_simrs->prepare($sqlSudah);
    $stmtSudah->execute($params);
    $sudah_dilayani = (int)$stmtSudah->fetchColumn();
    
    // Menunggu
    $sqlMenunggu = "SELECT COUNT(*) FROM reg_periksa r
                    LEFT JOIN poliklinik p ON r.kd_poli = p.kd_poli
                    WHERE r.tgl_registrasi = ? AND r.stts IN ('Menunggu','Belum') AND p.status='1' AND r.kd_poli NOT IN ($placeholders)";
    $stmtMenunggu = $pdo_simrs->prepare($sqlMenunggu);
    $stmtMenunggu->execute($params);
    $menunggu = (int)$stmtMenunggu->fetchColumn();
    
    // Batal
    $sqlBatal = "SELECT COUNT(*) FROM reg_periksa r
                 LEFT JOIN poliklinik p ON r.kd_poli = p.kd_poli
                 WHERE r.tgl_registrasi = ? AND r.stts='Batal' AND p.status='1' AND r.kd_poli NOT IN ($placeholders)";
    $stmtBatal = $pdo_simrs->prepare($sqlBatal);
    $stmtBatal->execute($params);
    $batal = (int)$stmtBatal->fetchColumn();
    
} catch (PDOException $e) {
    $total_pasien = $sudah_dilayani = $menunggu = $batal = 0;
}

// === AMBIL DATA REGISTRASI POLI ===
try {
    $placeholders = implode(',', array_fill(0, count($exclude_poli), '?'));
    $sql = "
        SELECT r.no_reg, r.no_rawat, r.no_rkm_medis, r.kd_dokter, r.kd_poli,
               r.tgl_registrasi, r.jam_reg, r.stts, r.status_lanjut,
               d.nm_dokter, p.nm_poli, ps.nm_pasien
        FROM reg_periksa r
        LEFT JOIN dokter d ON r.kd_dokter = d.kd_dokter
        LEFT JOIN poliklinik p ON r.kd_poli = p.kd_poli
        LEFT JOIN pasien ps ON r.no_rkm_medis = ps.no_rkm_medis
        WHERE r.tgl_registrasi = ?
          AND p.status = '1'
          AND r.kd_poli NOT IN ($placeholders)
    ";

    $params = [$today];
    $params = array_merge($params, $exclude_poli);

    if (!empty($filter_poli) && !in_array($filter_poli, $exclude_poli)) {
        $sql .= " AND r.kd_poli = ?";
        $params[] = $filter_poli;
    }

    if (!empty($filter_dokter)) {
        $sql .= " AND r.kd_dokter = ?";
        $params[] = $filter_dokter;
    }

    $sql .= " ORDER BY r.jam_reg ASC LIMIT " . intval($limit) . " OFFSET " . intval($offset);

    $stmt = $pdo_simrs->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // === HITUNG TOTAL ===
    $countSql = "
        SELECT COUNT(*) FROM reg_periksa r
        LEFT JOIN poliklinik p ON r.kd_poli = p.kd_poli
        WHERE r.tgl_registrasi = ?
          AND p.status='1'
          AND r.kd_poli NOT IN ($placeholders)
    ";
    $paramsCount = [$today];
    $paramsCount = array_merge($paramsCount, $exclude_poli);

    if (!empty($filter_poli) && !in_array($filter_poli, $exclude_poli)) {
        $countSql .= " AND r.kd_poli = ?";
        $paramsCount[] = $filter_poli;
    }

    if (!empty($filter_dokter)) {
        $countSql .= " AND r.kd_dokter = ?";
        $paramsCount[] = $filter_dokter;
    }

    $countStmt = $pdo_simrs->prepare($countSql);
    $countStmt->execute($paramsCount);
    $total = (int)$countStmt->fetchColumn();
    $total_pages = max(1, ceil($total / $limit));
} catch (PDOException $e) {
    die("Gagal mengambil data: " . $e->getMessage());
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Antrian Poliklinik - MediFix</title>
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
    font-size: 13px;
    overflow: hidden;
}

/* Top Bar */
.top-bar {
    background: rgba(255, 255, 255, 0.98);
    backdrop-filter: blur(20px);
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    padding: 10px 0;
    position: sticky;
    top: 0;
    z-index: 1000;
}

.brand {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 16px;
    font-weight: 800;
    color: #1e293b;
}

.brand-icon {
    width: 32px;
    height: 32px;
    background: linear-gradient(135deg, #8b5cf6, #a78bfa);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 16px;
}

.user-info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.user-badge {
    display: flex;
    align-items: center;
    gap: 6px;
    background: white;
    padding: 6px 12px;
    border-radius: 50px;
    border: 2px solid #e2e8f0;
    font-weight: 600;
    color: #1e293b;
    font-size: 12px;
}

.btn-back {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
    border: none;
    padding: 6px 16px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 12px;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.btn-back:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(239, 68, 68, 0.4);
    color: white;
}

/* Stats Cards */
.stats-container {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin: 12px 0;
}

.stat-card {
    background: white;
    border-radius: 12px;
    padding: 16px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    position: relative;
    overflow: hidden;
    transition: all 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: var(--card-color);
}

.stat-icon {
    width: 40px;
    height: 40px;
    background: var(--card-bg);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 10px;
}

.stat-icon i {
    font-size: 20px;
    color: var(--card-color);
}

.stat-value {
    font-size: 24px;
    font-weight: 800;
    color: #1e293b;
    margin-bottom: 2px;
}

.stat-label {
    font-size: 12px;
    color: #64748b;
    font-weight: 600;
}

.stat-card-total {
    --card-color: #3b82f6;
    --card-bg: #eff6ff;
}

.stat-card-selesai {
    --card-color: #10b981;
    --card-bg: #ecfdf5;
}

.stat-card-menunggu {
    --card-color: #f59e0b;
    --card-bg: #fffbeb;
}

.stat-card-batal {
    --card-color: #ef4444;
    --card-bg: #fef2f2;
}

/* Content Container */
.content-container {
    background: white;
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
    margin-bottom: 20px;
    max-height: calc(100vh - 240px);
    overflow-y: auto;
}

.content-container::-webkit-scrollbar {
    width: 6px;
}

.content-container::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 10px;
}

.content-container::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 10px;
}

.page-title {
    font-size: 18px;
    font-weight: 800;
    color: #1e293b;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.page-subtitle {
    color: #64748b;
    font-size: 12px;
    margin-bottom: 16px;
}

/* Filter Section */
.filter-section {
    background: #f8fafc;
    border-radius: 10px;
    padding: 14px;
    margin-bottom: 16px;
    border: 1px solid #e2e8f0;
}

.filter-label {
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 6px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.form-select {
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    padding: 8px 12px;
    font-size: 13px;
    font-weight: 500;
    transition: all 0.3s ease;
}

.form-select:focus {
    border-color: #8b5cf6;
    box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
}

.btn-filter {
    background: linear-gradient(135deg, #8b5cf6, #a78bfa);
    color: white;
    border: none;
    padding: 8px 18px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 13px;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.btn-filter:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(139, 92, 246, 0.4);
}

/* Table */
.table-wrapper {
    border-radius: 10px;
    overflow: hidden;
    border: 1px solid #e2e8f0;
}

.table {
    margin-bottom: 0;
    font-size: 13px;
}

.table thead th {
    background: linear-gradient(135deg, #8b5cf6, #a78bfa);
    color: white;
    font-weight: 700;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 10px 8px;
    border: none;
}

.table tbody td {
    padding: 10px 8px;
    vertical-align: middle;
    font-size: 13px;
    color: #1e293b;
    border-bottom: 1px solid #f1f5f9;
}

.table tbody tr:hover {
    background: #f8fafc;
}

.table tbody tr:last-child td {
    border-bottom: none;
}

/* Status Badges */
.status-badge {
    padding: 4px 10px;
    border-radius: 12px;
    font-weight: 700;
    font-size: 11px;
    display: inline-block;
}

.status-sudah {
    background: #d1fae5;
    color: #065f46;
}

.status-menunggu {
    background: #fef3c7;
    color: #92400e;
}

.status-batal {
    background: #fee2e2;
    color: #991b1b;
}

.status-lain {
    background: #f1f5f9;
    color: #475569;
}

/* Call Button */
.btn-call {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    cursor: pointer;
    position: relative;
}

.btn-call:hover {
    transform: scale(1.05);
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
}

.btn-call i {
    font-size: 16px;
}

.btn-call.called {
    background: linear-gradient(135deg, #10b981, #059669);
}

.btn-call.called:hover {
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
}

/* Counter panggilan */
.call-counter {
    position: absolute;
    top: -4px;
    right: -4px;
    background: #fbbf24;
    color: #78350f;
    font-size: 9px;
    font-weight: 800;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid white;
}

/* Row yang sudah dipanggil */
.row-called {
    background: #f0fdf4 !important;
}

.row-called td {
    opacity: 0.9;
}

/* Badge dipanggil */
.badge-called {
    background: #d1fae5;
    color: #065f46;
    padding: 3px 8px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 700;
    margin-left: 6px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.badge-called i {
    font-size: 10px;
}

/* Pagination */
.pagination {
    gap: 6px;
}

.page-item .page-link {
    border: 2px solid #e2e8f0;
    border-radius: 6px;
    color: #1e293b;
    font-weight: 600;
    font-size: 13px;
    padding: 6px 10px;
    transition: all 0.3s ease;
}

.page-item.active .page-link {
    background: linear-gradient(135deg, #8b5cf6, #a78bfa);
    border-color: #8b5cf6;
    color: white;
}

.page-item .page-link:hover {
    background: #f8fafc;
    border-color: #8b5cf6;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 40px 20px;
}

.empty-icon {
    width: 70px;
    height: 70px;
    background: #f1f5f9;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 14px;
}

.empty-icon i {
    font-size: 32px;
    color: #cbd5e1;
}

.empty-title {
    font-size: 16px;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 6px;
}

.empty-text {
    color: #64748b;
    font-size: 13px;
}

/* Live Clock */
.live-clock {
    background: linear-gradient(135deg, #8b5cf6, #a78bfa);
    color: white;
    padding: 6px 12px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 12px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

/* Responsive */
@media (max-width: 1200px) {
    .stats-container {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-container {
        grid-template-columns: 1fr;
    }
    
    .filter-section .row > div {
        margin-bottom: 15px;
    }
    
    .table-wrapper {
        overflow-x: auto;
    }
}
</style>
</head>
<body>

<!-- Top Bar -->
<div class="top-bar">
    <div class="container-fluid px-4">
        <div class="d-flex align-items-center justify-content-between">
            <div class="brand">
                <div class="brand-icon">
                    <i class="bi bi-hospital"></i>
                </div>
                <span>MediFix - Antrian Poliklinik</span>
            </div>
            
            <div class="user-info">
                <div class="live-clock" id="liveClock">
                    <i class="bi bi-clock-fill"></i>
                    <span id="clockDisplay"></span>
                </div>
                
                <div class="user-badge">
                    <i class="bi bi-person-circle"></i>
                    <span><?= htmlspecialchars($nama) ?></span>
                </div>
                
                <a href="poli_dashboard.php" class="btn-back">
                    <i class="bi bi-arrow-left-circle-fill"></i>
                    Kembali
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<div class="container-fluid px-4">
    
    <!-- Stats Cards -->
    <div class="stats-container">
        <div class="stat-card stat-card-total">
            <div class="stat-icon">
                <i class="bi bi-people-fill"></i>
            </div>
            <div class="stat-value"><?= $total_pasien ?></div>
            <div class="stat-label">Total Pasien</div>
        </div>
        
        <div class="stat-card stat-card-selesai">
            <div class="stat-icon">
                <i class="bi bi-check-circle-fill"></i>
            </div>
            <div class="stat-value"><?= $sudah_dilayani ?></div>
            <div class="stat-label">Sudah Dilayani</div>
        </div>
        
        <div class="stat-card stat-card-menunggu">
            <div class="stat-icon">
                <i class="bi bi-clock-history"></i>
            </div>
            <div class="stat-value"><?= $menunggu ?></div>
            <div class="stat-label">Sedang Menunggu</div>
        </div>
        
        <div class="stat-card stat-card-batal">
            <div class="stat-icon">
                <i class="bi bi-x-circle-fill"></i>
            </div>
            <div class="stat-value"><?= $batal ?></div>
            <div class="stat-label">Batal/Tidak Hadir</div>
        </div>
    </div>
    
    <!-- Content Container -->
    <div class="content-container">
        <div class="page-title">
            <i class="bi bi-clipboard2-pulse"></i>
            Data Antrian Poliklinik
        </div>
        <div class="page-subtitle">
            Tanggal: <?= date('l, d F Y', strtotime($today)) ?> • Menampilkan <?= count($data) ?> dari <?= $total ?> pasien
        </div>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="filter-label">Pilih Poliklinik</label>
                    <select name="poli" class="form-select">
                        <option value="">Semua Poliklinik</option>
                        <?php foreach ($poliklinik as $p): ?>
                        <option value="<?= htmlspecialchars($p['kd_poli']) ?>" <?= $filter_poli==$p['kd_poli']?'selected':'' ?>>
                            <?= htmlspecialchars($p['nm_poli']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-4">
                    <label class="filter-label">Pilih Dokter</label>
                    <select name="dokter" class="form-select">
                        <option value="">Semua Dokter</option>
                        <?php foreach ($dokterList as $d): ?>
                        <option value="<?= htmlspecialchars($d['kd_dokter']) ?>" <?= $filter_dokter==$d['kd_dokter']?'selected':'' ?>>
                            <?= htmlspecialchars($d['nm_dokter']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="filter-label">Tampilkan</label>
                    <select name="limit" class="form-select">
                        <option value="20" <?= $limit==20?'selected':'' ?>>20</option>
                        <option value="50" <?= $limit==50?'selected':'' ?>>50</option>
                        <option value="100" <?= $limit==100?'selected':'' ?>>100</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <button type="submit" class="btn-filter w-100">
                        <i class="bi bi-funnel-fill"></i>
                        Filter Data
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Table -->
        <?php if ($total > 0): ?>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th width="80">Panggil</th>
                        <th width="100">No Antrian</th>
                        <th>No Rawat</th>
                        <th>No RM</th>
                        <th>Nama Pasien</th>
                        <th>Dokter</th>
                        <th>Poliklinik</th>
                        <th width="120">Status</th>
                        <th width="100">Lanjut</th>
                        <th width="100">Jam Reg</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data as $row): 
                        $statusClass = match (strtolower($row['stts'])) {
                            'sudah' => 'status-sudah',
                            'menunggu', 'belum' => 'status-menunggu',
                            'batal' => 'status-batal',
                            default => 'status-lain'
                        };
                        $rowId = 'row-' . md5($row['no_rawat']);
                        $btnId = 'btn-' . md5($row['no_rawat']);
                    ?>
                    <tr id="<?= $rowId ?>">
                        <td>
                            <button class="btn-call" id="<?= $btnId ?>"
                                data-no-rawat="<?= htmlspecialchars($row['no_rawat']) ?>"
                                data-kd-dokter="<?= htmlspecialchars($row['kd_dokter']) ?>"
                                data-nm-dokter="<?= htmlspecialchars($row['nm_dokter']) ?>"
                                data-nm-pasien="<?= htmlspecialchars($row['nm_pasien']) ?>"
                                onclick="panggilPasien('<?= addslashes($row['kd_poli'].'-'.$row['no_reg']) ?>','<?= addslashes($row['nm_poli']) ?>', '<?= htmlspecialchars($row['no_rawat']) ?>', this)">
                                <i class="bi bi-megaphone-fill"></i>
                            </button>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars($row['kd_poli'].'-'.$row['no_reg']) ?></strong>
                            <span class="badge-called" id="badge-<?= htmlspecialchars($row['no_rawat']) ?>" style="display:none;">
                                <i class="bi bi-check-circle-fill"></i> Dipanggil
                            </span>
                        </td>
                        <td><?= htmlspecialchars($row['no_rawat']) ?></td>
                        <td><?= htmlspecialchars($row['no_rkm_medis']) ?></td>
                        <td><strong><?= htmlspecialchars($row['nm_pasien']) ?></strong></td>
                        <td><?= htmlspecialchars($row['nm_dokter']) ?></td>
                        <td><?= htmlspecialchars($row['nm_poli']) ?></td>
                        <td>
                            <span class="status-badge <?= $statusClass ?>">
                                <?= htmlspecialchars($row['stts']) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($row['status_lanjut']) ?></td>
                        <td><?= date('H:i', strtotime($row['jam_reg'])) ?> WIB</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <nav class="mt-4">
            <ul class="pagination justify-content-center">
                <?php if ($page > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=<?= $page-1 ?>&poli=<?= urlencode($filter_poli) ?>&dokter=<?= urlencode($filter_dokter) ?>&limit=<?= $limit ?>">
                        <i class="bi bi-chevron-left"></i> Prev
                    </a>
                </li>
                <?php endif; ?>
                
                <?php 
                $start = max(1, $page - 2);
                $end = min($total_pages, $page + 2);
                for ($i=$start; $i<=$end; $i++): 
                ?>
                <li class="page-item <?=($i==$page)?'active':''?>">
                    <a class="page-link" href="?page=<?=$i?>&poli=<?=urlencode($filter_poli)?>&dokter=<?=urlencode($filter_dokter)?>&limit=<?=$limit?>">
                        <?=$i?>
                    </a>
                </li>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=<?= $page+1 ?>&poli=<?= urlencode($filter_poli) ?>&dokter=<?= urlencode($filter_dokter) ?>&limit=<?= $limit ?>">
                        Next <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>
        <?php endif; ?>
        
        <?php else: ?>
        <!-- Empty State -->
        <div class="empty-state">
            <div class="empty-icon">
                <i class="bi bi-inbox"></i>
            </div>
            <div class="empty-title">Belum Ada Data Antrian</div>
            <div class="empty-text">Tidak ada pasien yang terdaftar pada poliklinik hari ini</div>
        </div>
        <?php endif; ?>
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

// Load status panggilan dari localStorage saat page load
window.addEventListener('DOMContentLoaded', function() {
    const today = '<?= $today ?>';
    const calledPatients = JSON.parse(localStorage.getItem('calledPatients_' + today) || '{}');
    
    Object.keys(calledPatients).forEach(function(noRawat) {
        markAsCalled(noRawat, calledPatients[noRawat]);
    });
});

// Fungsi untuk menandai pasien yang sudah dipanggil
function markAsCalled(noRawat, count) {
    // Cari button berdasarkan data-no-rawat
    const button = document.querySelector(`[data-no-rawat="${noRawat}"]`);
    if (button) {
        button.classList.add('called');
        
        // Tambahkan counter jika sudah dipanggil lebih dari 1x
        let counterEl = button.querySelector('.call-counter');
        if (count > 1) {
            if (!counterEl) {
                counterEl = document.createElement('span');
                counterEl.className = 'call-counter';
                button.appendChild(counterEl);
            }
            counterEl.textContent = count;
        } else if (counterEl) {
            counterEl.remove();
        }
        
        // Tandai row
        const row = button.closest('tr');
        if (row) {
            row.classList.add('row-called');
        }
        
        // Tampilkan badge dipanggil
        const badge = document.getElementById('badge-' + noRawat);
        if (badge) {
            badge.style.display = 'inline-flex';
            if (count > 1) {
                badge.innerHTML = `<i class="bi bi-check-circle-fill"></i> Dipanggil ${count}x`;
            } else {
                badge.innerHTML = `<i class="bi bi-check-circle-fill"></i> Dipanggil`;
            }
        }
    }
}

// Call Patient Function
function panggilPasien(noAntri, poli, noRawat, buttonElement) {
    // Ambil data dari data attributes button
    const kdDokter = buttonElement.getAttribute('data-kd-dokter') || '';
    const nmDokter = buttonElement.getAttribute('data-nm-dokter') || '';
    const nmPasien = buttonElement.getAttribute('data-nm-pasien') || '';
    
    const [kodePoli, nomor] = noAntri.split('-');
    
    console.log('Mengirim data panggilan:', {
        no_antrian: noAntri,
        nm_poli: poli,
        nm_pasien: nmPasien,
        no_rawat: noRawat,
        kd_poli: kodePoli,
        kd_dokter: kdDokter,
        nm_dokter: nmDokter
    });
    
    // Kirim data panggilan ke server untuk display SEBELUM suara
    fetch('save_call.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            no_antrian: noAntri,
            nm_poli: poli,
            nm_pasien: nmPasien,
            no_rawat: noRawat,
            kd_poli: kodePoli,
            kd_dokter: kdDokter,
            nm_dokter: nmDokter
        })
    })
    .then(response => response.json())
    .then(data => {
        console.log('Response dari server:', data);
        if (data.success) {
            console.log('✅ Data panggilan tersimpan:', data);
        } else {
            console.error('❌ Gagal simpan:', data.message);
        }
    })
    .catch(error => {
        console.error('❌ Error saving call data:', error);
        alert('Gagal menyimpan data panggilan ke display. Pastikan file save_call.php ada dan folder data/ writable.');
    });
    
    // Kemudian play suara
    const synth = window.speechSynthesis;
    synth.cancel();

    const prefix = kodePoli.split('').join(' ');

    function angkaToSuara(num) {
        num = parseInt(num);
        const angka = ["","satu","dua","tiga","empat","lima","enam","tujuh","delapan","sembilan","sepuluh","sebelas"];

        if (num < 12) return angka[num];
        if (num < 20) return angka[num - 10] + " belas";
        if (num < 100) {
            const puluh = Math.floor(num / 10);
            const satuan = num % 10;
            return angka[puluh] + " puluh " + (satuan > 0 ? angka[satuan] : "");
        }
        return num.toString();
    }

    const nomorSuara = angkaToSuara(nomor);
    const kalimat = `Nomor antrian ${prefix} ${nomorSuara}, silakan menuju ${poli}.`;

    const bell = new Audio('sound/opening.mp3');
    bell.volume = 1.0;

    bell.play().then(() => {
        bell.addEventListener('ended', () => {
            speak(kalimat);
        });
    }).catch(() => {
        speak(kalimat);
    });

    function speak(text) {
        const utter = new SpeechSynthesisUtterance(text);
        utter.lang = 'id-ID';
        utter.pitch = 1.0;
        utter.rate = 0.9;
        utter.volume = 1.0;

        const voices = speechSynthesis.getVoices();
        const voiceID = voices.find(v => v.lang.includes("id"));
        if (voiceID) utter.voice = voiceID;

        synth.speak(utter);
    }
    
    // Simpan/update status ke localStorage dengan counter
    const today = '<?= $today ?>';
    let calledPatients = JSON.parse(localStorage.getItem('calledPatients_' + today) || '{}');
    
    // Increment counter
    if (calledPatients[noRawat]) {
        calledPatients[noRawat]++;
    } else {
        calledPatients[noRawat] = 1;
    }
    
    localStorage.setItem('calledPatients_' + today, JSON.stringify(calledPatients));
    
    // Tandai sebagai sudah dipanggil dengan counter
    markAsCalled(noRawat, calledPatients[noRawat]);
}

// Bersihkan localStorage untuk hari sebelumnya (opsional)
function cleanOldData() {
    const today = '<?= $today ?>';
    Object.keys(localStorage).forEach(key => {
        if (key.startsWith('calledPatients_') && !key.includes(today)) {
            localStorage.removeItem(key);
        }
    });
}
cleanOldData();
</script>
</body>
</html>