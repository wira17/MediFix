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

// === Inisialisasi session panggilan ===
if (!isset($_SESSION['farmasi_called'])) $_SESSION['farmasi_called'] = [];

// === Ambil data resep hari ini - SESUAI KHANZA ASLI ===
try {
   $cari = $_GET['cari'] ?? '';
   
   // Pagination
   $items_per_page = 15; // 15 data per halaman
   $current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
   $current_page = max(1, $current_page); // Minimal halaman 1

$sql = "
    SELECT 
        ro.no_resep, ro.tgl_peresepan, ro.jam_peresepan, ro.status as status_resep,
        r.no_rkm_medis, p.nm_pasien, d.nm_dokter, pl.nm_poli, pl.kd_poli,
        r.status_lanjut,
        CASE 
            WHEN EXISTS (SELECT 1 FROM resep_dokter_racikan rr WHERE rr.no_resep = ro.no_resep)
            THEN 'Racikan'
            ELSE 'Non Racikan'
        END AS jenis_resep
    FROM resep_obat ro
    INNER JOIN reg_periksa r ON ro.no_rawat = r.no_rawat
    INNER JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
    INNER JOIN dokter d ON ro.kd_dokter = d.kd_dokter
    LEFT JOIN poliklinik pl ON r.kd_poli = pl.kd_poli
    WHERE ro.tgl_peresepan = CURDATE()
      AND ro.status = 'ralan'
      AND ro.jam_peresepan <> '00:00:00'
";

if (!empty($cari)) {
    $sql .= " AND p.nm_pasien LIKE :cari";
}

$sql .= " ORDER BY ro.tgl_peresepan DESC, ro.jam_peresepan DESC";

// Hitung total data
$stmt_count = $pdo_simrs->prepare($sql);
if (!empty($cari)) {
    $stmt_count->bindValue(':cari', "%$cari%");
}
$stmt_count->execute();
$total_items = $stmt_count->rowCount();
$total_pages = ceil($total_items / $items_per_page);

// Query dengan LIMIT untuk pagination
$offset = ($current_page - 1) * $items_per_page;
$sql .= " LIMIT :limit OFFSET :offset";

$stmt = $pdo_simrs->prepare($sql);

if (!empty($cari)) {
    $stmt->bindValue(':cari', "%$cari%");
}
$stmt->bindValue(':limit', $items_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();
$antrian = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Gagal mengambil data antrian: " . $e->getMessage());
}

// === AJAX Handler Pemanggilan ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'panggil') {
    $no_resep = $_POST['no_resep'] ?? '';

    if (!$no_resep) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Nomor resep kosong']);
        exit;
    }

    // === Simpan ke session ===
    if (!in_array($no_resep, $_SESSION['farmasi_called'])) {
        $_SESSION['farmasi_called'][] = $no_resep;
    }

    // === Ambil data lengkap resep ===
    $stmt = $pdo_simrs->prepare("
        SELECT ro.no_resep, p.nm_pasien, pl.nm_poli,
               CASE 
                   WHEN EXISTS (SELECT 1 FROM resep_dokter_racikan rr WHERE rr.no_resep = ro.no_resep)
                   THEN 'Racikan'
                   ELSE 'Non Racikan'
               END AS jenis_resep
        FROM resep_obat ro
        LEFT JOIN reg_periksa r ON ro.no_rawat = r.no_rawat
        LEFT JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
        LEFT JOIN poliklinik pl ON r.kd_poli = pl.kd_poli
        WHERE ro.no_resep = ?
    ");
    $stmt->execute([$no_resep]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($data) {
        // === Tentukan lokasi file - coba data/ dulu, fallback ke root ===
        $dataDir = __DIR__ . '/data';
        if (!file_exists($dataDir)) {
            @mkdir($dataDir, 0777, true);
            @chmod($dataDir, 0777);
        }
        
        // Coba tulis ke data/ dulu
        $file = $dataDir . '/last_farmasi.json';
        
        // Jika data/ tidak writable, gunakan root
        if (!is_writable($dataDir)) {
            $file = __DIR__ . '/last_farmasi.json';
        }
        
        $jsonData = [
            'no_resep' => $data['no_resep'],
            'nm_pasien' => $data['nm_pasien'],
            'nm_poli' => $data['nm_poli'] ?? '-',
            'jenis_resep' => $data['jenis_resep'],
            'waktu' => date('Y-m-d H:i:s')
        ];
        
        // Coba tulis file
        if (@file_put_contents($file, json_encode($jsonData, JSON_PRETTY_PRINT))) {
            error_log("✅ File berhasil ditulis: " . $file);
        } else {
            error_log("❌ Gagal menulis file: " . $file);
            // Fallback: coba pakai /tmp
            $tmpFile = sys_get_temp_dir() . '/last_farmasi.json';
            if (@file_put_contents($tmpFile, json_encode($jsonData, JSON_PRETTY_PRINT))) {
                error_log("✅ File ditulis ke temp: " . $tmpFile);
            }
        }
    }

    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'data' => $data]);
    exit;
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Antrian Farmasi - MediFix</title>
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
    background: linear-gradient(135deg, #ff9800, #ff6f00);
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

/* Content Container */
.content-container {
    background: white;
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
    margin-bottom: 20px;
    max-height: calc(100vh - 140px);
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

.form-control {
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    padding: 8px 12px;
    font-size: 13px;
    font-weight: 500;
    transition: all 0.3s ease;
}

.form-control:focus {
    border-color: #ff9800;
    box-shadow: 0 0 0 3px rgba(255, 152, 0, 0.1);
}

.btn-filter {
    background: linear-gradient(135deg, #ff9800, #ff6f00);
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
    box-shadow: 0 6px 16px rgba(255, 152, 0, 0.4);
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
    background: linear-gradient(135deg, #ff9800, #ff6f00);
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

/* Row yang sudah dipanggil */
.row-called {
    background: #fff7ed !important;
    border-left: 3px solid #ff9800;
}

/* Badge Styling */
.badge-racik {
    background: linear-gradient(135deg, #ff6f00, #ff5722);
    color: white;
    padding: 4px 10px;
    border-radius: 12px;
    font-weight: 700;
    font-size: 11px;
    display: inline-block;
}

.badge-non {
    background: linear-gradient(135deg, #4caf50, #388e3c);
    color: white;
    padding: 4px 10px;
    border-radius: 12px;
    font-weight: 700;
    font-size: 11px;
    display: inline-block;
}

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

/* Call Button */
.btn-call {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: linear-gradient(135deg, #ff9800, #ff6f00);
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
    box-shadow: 0 4px 12px rgba(255, 152, 0, 0.4);
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
    background: linear-gradient(135deg, #ff9800, #ff6f00);
    color: white;
    padding: 6px 12px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 12px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

/* Pagination */
.pagination-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 2px solid #f1f5f9;
}

.pagination-info {
    color: #64748b;
    font-size: 13px;
    font-weight: 600;
}

.pagination {
    display: flex;
    gap: 5px;
    margin: 0;
}

.pagination .page-link {
    border: 2px solid #e2e8f0;
    color: #1e293b;
    padding: 8px 14px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    transition: all 0.3s ease;
    text-decoration: none;
}

.pagination .page-link:hover {
    background: #f8fafc;
    border-color: #ff9800;
    color: #ff9800;
}

.pagination .page-item.active .page-link {
    background: linear-gradient(135deg, #ff9800, #ff6f00);
    border-color: #ff9800;
    color: white;
}

.pagination .page-item.disabled .page-link {
    opacity: 0.5;
    cursor: not-allowed;
    pointer-events: none;
}

/* Responsive */
@media (max-width: 768px) {
    .filter-section .row > div {
        margin-bottom: 15px;
    }
    
    .table-wrapper {
        overflow-x: auto;
    }
    
    .pagination-container {
        flex-direction: column;
        gap: 15px;
    }
    
    .pagination {
        flex-wrap: wrap;
        justify-content: center;
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
                    <i class="bi bi-capsule-pill"></i>
                </div>
                <span>MediFix - Antrian Farmasi</span>
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
                
                <a href="farmasi_dashboard.php" class="btn-back">
                    <i class="bi bi-arrow-left-circle-fill"></i>
                    Kembali
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<div class="container-fluid px-4" style="padding-top: 20px;">
    
    <!-- Content Container -->
    <div class="content-container">
        <div class="page-title">
            <i class="bi bi-clipboard2-pulse"></i>
            Data Antrian Farmasi
        </div>
        <div class="page-subtitle">
            Tanggal: <?= date('l, d F Y') ?> • Menampilkan <?= count($antrian) ?> dari <?= $total_items ?> resep hari ini
        </div>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-10">
                    <label class="filter-label">Cari Nama Pasien</label>
                    <input type="text" name="cari" class="form-control" 
                           placeholder="Ketik nama pasien..." 
                           value="<?= htmlspecialchars($_GET['cari'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn-filter w-100">
                        <i class="bi bi-search"></i>
                        Cari Data
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Table -->
        <?php if (count($antrian) > 0): ?>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th width="50">No</th>
                        <th width="80">Panggil</th>
                        <th width="120">No. Antrian</th>
                        <th width="150">No. Resep</th>
                        <th width="120">No. RM</th>
                        <th>Nama Pasien</th>
                        <th width="150">Poli</th>
                        <th width="180">Dokter</th>
                        <th width="120">Jenis Resep</th>
                        <th width="100">Waktu</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $no = ($current_page - 1) * $items_per_page + 1;
                    foreach ($antrian as $r): 
                        $no_antrian = 'F' . str_pad(substr($r['no_resep'], -4), 4, '0', STR_PAD_LEFT);
                        $called = in_array($r['no_resep'], $_SESSION['farmasi_called']);
                        $rowId = 'row-' . md5($r['no_resep']);
                        $btnId = 'btn-' . md5($r['no_resep']);
                    ?>
                    <tr id="<?= $rowId ?>" class="<?= $called ? 'row-called' : ''; ?>">
                        <td><?= $no++; ?></td>
                        <td>
                            <button class="btn-call <?= $called ? 'called' : ''; ?>" 
                                    id="<?= $btnId ?>"
                                    data-no-resep="<?= htmlspecialchars($r['no_resep']) ?>"
                                    data-nm-pasien="<?= htmlspecialchars($r['nm_pasien']) ?>"
                                    onclick="panggil('<?= addslashes($r['no_resep']) ?>', '<?= addslashes($r['nm_pasien']) ?>', this)">
                                <i class="bi bi-megaphone-fill"></i>
                            </button>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars($no_antrian) ?></strong>
                            <?php if ($called): ?>
                            <span class="badge-called">
                                <i class="bi bi-check-circle-fill"></i> Dipanggil
                            </span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($r['no_resep']) ?></td>
                        <td><?= htmlspecialchars($r['no_rkm_medis']) ?></td>
                        <td class="text-start"><strong><?= htmlspecialchars($r['nm_pasien']) ?></strong></td>
                        <td><?= htmlspecialchars($r['nm_poli'] ?? '-') ?></td>
                        <td class="text-start"><?= htmlspecialchars($r['nm_dokter']) ?></td>
                        <td>
                            <?php if ($r['jenis_resep'] === 'Racikan'): ?>
                                <span class="badge-racik">
                                    <i class="bi bi-capsule"></i> <?= $r['jenis_resep'] ?>
                                </span>
                            <?php else: ?>
                                <span class="badge-non">
                                    <i class="bi bi-prescription2"></i> <?= $r['jenis_resep'] ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><?= date('H:i', strtotime($r['jam_peresepan'])) ?> WIB</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination-container">
            <div class="pagination-info">
                Halaman <?= $current_page ?> dari <?= $total_pages ?> 
                (Total: <?= $total_items ?> resep)
            </div>
            
            <ul class="pagination">
                <!-- Previous Button -->
                <li class="page-item <?= ($current_page <= 1) ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= $current_page - 1 ?><?= !empty($cari) ? '&cari='.urlencode($cari) : '' ?>">
                        <i class="bi bi-chevron-left"></i> Prev
                    </a>
                </li>
                
                <?php
                // Tampilkan nomor halaman
                $start_page = max(1, $current_page - 2);
                $end_page = min($total_pages, $current_page + 2);
                
                // Halaman pertama
                if ($start_page > 1) {
                    echo '<li class="page-item"><a class="page-link" href="?page=1'.(!empty($cari) ? '&cari='.urlencode($cari) : '').'">1</a></li>';
                    if ($start_page > 2) {
                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                    }
                }
                
                // Halaman tengah
                for ($i = $start_page; $i <= $end_page; $i++) {
                    $active = ($i == $current_page) ? 'active' : '';
                    echo '<li class="page-item '.$active.'"><a class="page-link" href="?page='.$i.(!empty($cari) ? '&cari='.urlencode($cari) : '').'">'.$i.'</a></li>';
                }
                
                // Halaman terakhir
                if ($end_page < $total_pages) {
                    if ($end_page < $total_pages - 1) {
                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                    }
                    echo '<li class="page-item"><a class="page-link" href="?page='.$total_pages.(!empty($cari) ? '&cari='.urlencode($cari) : '').'">'.$total_pages.'</a></li>';
                }
                ?>
                
                <!-- Next Button -->
                <li class="page-item <?= ($current_page >= $total_pages) ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= $current_page + 1 ?><?= !empty($cari) ? '&cari='.urlencode($cari) : '' ?>">
                        Next <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
            </ul>
        </div>
        <?php endif; ?>
        
        <?php else: ?>
        <!-- Empty State -->
        <div class="empty-state">
            <div class="empty-icon">
                <i class="bi bi-inbox"></i>
            </div>
            <div class="empty-title">Belum Ada Antrian</div>
            <div class="empty-text">Tidak ada resep untuk hari ini</div>
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

// === Fungsi untuk mengkonversi angka ke teks Indonesia ===
function angkaKeKata(n) {
  const satuan = ["", "satu", "dua", "tiga", "empat", "lima", "enam", "tujuh", "delapan", "sembilan"];
  const belasan = ["sepuluh", "sebelas", "dua belas", "tiga belas", "empat belas", "lima belas", 
                   "enam belas", "tujuh belas", "delapan belas", "sembilan belas"];
  
  if (n === 0) return "nol";
  if (n < 10) return satuan[n];
  if (n >= 10 && n < 20) return belasan[n - 10];
  if (n >= 20 && n < 100) {
    const puluhan = Math.floor(n / 10);
    const sisa = n % 10;
    return satuan[puluhan] + " puluh" + (sisa > 0 ? " " + satuan[sisa] : "");
  }
  if (n >= 100 && n < 200) {
    const sisa = n - 100;
    return "seratus" + (sisa > 0 ? " " + angkaKeKata(sisa) : "");
  }
  if (n >= 200 && n < 1000) {
    const ratusan = Math.floor(n / 100);
    const sisa = n % 100;
    return satuan[ratusan] + " ratus" + (sisa > 0 ? " " + angkaKeKata(sisa) : "");
  }
  if (n >= 1000 && n < 2000) {
    const sisa = n - 1000;
    return "seribu" + (sisa > 0 ? " " + angkaKeKata(sisa) : "");
  }
  if (n >= 2000 && n < 10000) {
    const ribuan = Math.floor(n / 1000);
    const sisa = n % 1000;
    return satuan[ribuan] + " ribu" + (sisa > 0 ? " " + angkaKeKata(sisa) : "");
  }
  return n.toString();
}

// Load status panggilan dari localStorage saat page load
window.addEventListener('DOMContentLoaded', function() {
    const today = '<?= date('Y-m-d') ?>';
    const calledPatients = JSON.parse(localStorage.getItem('calledFarmasi_' + today) || '{}');
    
    Object.keys(calledPatients).forEach(function(noResep) {
        markAsCalled(noResep, calledPatients[noResep]);
    });
});

// Fungsi untuk menandai pasien yang sudah dipanggil
function markAsCalled(noResep, count) {
    const button = document.querySelector(`[data-no-resep="${noResep}"]`);
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
    }
}

// === Fungsi pemanggilan dengan suara yang lebih baik ===
function panggil(no_resep, nm_pasien, buttonElement) {
    console.log('=== PANGGIL FUNCTION CALLED ===');
    console.log('No Resep:', no_resep);
    console.log('Nama Pasien:', nm_pasien);
    
    // Tampilkan loading
    buttonElement.disabled = true;
    const originalHTML = buttonElement.innerHTML;
    buttonElement.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    
    // Kirim data ke server untuk disimpan
    console.log('Sending POST request...');
    fetch(window.location.href, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `action=panggil&no_resep=${encodeURIComponent(no_resep)}`
    })
    .then(r => {
        console.log('Response received:', r);
        return r.json();
    })
    .then(resp => {
        console.log('Response data:', resp);
        
        if (resp.status !== 'ok') {
            alert('Gagal memanggil: ' + resp.message);
            buttonElement.disabled = false;
            buttonElement.innerHTML = originalHTML;
            return;
        }

        const data = resp.data;
        console.log('Patient data:', data);
        
        // Ambil 4 digit terakhir dari nomor resep
        const raw = data.no_resep.slice(-4);
        const angka = parseInt(raw, 10); // Parse sebagai integer untuk hilangkan leading zero
        
        console.log('Raw number:', raw);
        console.log('Parsed number:', angka);
        
        // === Konversi nomor antrian ke kata (PERBAIKAN) ===
        const nomorKata = angkaKeKata(angka);
        
        console.log('Nomor kata:', nomorKata);

        // === Format nama pasien ===
        const namaPasien = data.nm_pasien
            .toLowerCase()
            .split(' ')
            .map(kata => kata.charAt(0).toUpperCase() + kata.slice(1))
            .join(' ');

        // === Buat teks pemanggilan ===
        const teks = `Nomor antrian farmasi, F ${nomorKata}. Atas nama, ${namaPasien}. Silakan menuju loket farmasi.`;
        console.log('Speech text:', teks);

        // === Fungsi untuk memutar suara dengan retry ===
        function playSoundWithRetry(callback, retries = 3) {
            const bell = new Audio('sound/opening.mp3');
            bell.volume = 1;
            
            bell.play().then(() => {
                console.log('Bell sound played');
                bell.addEventListener('ended', callback);
            }).catch(err => {
                console.warn('Gagal memutar bell audio:', err);
                if (retries > 0) {
                    setTimeout(() => playSoundWithRetry(callback, retries - 1), 500);
                } else {
                    callback();
                }
            });
        }

        // === Mulai pemanggilan ===
        playSoundWithRetry(() => {
            // Web Speech API dengan perbaikan
            const utterance = new SpeechSynthesisUtterance(teks);
            utterance.lang = 'id-ID';
            utterance.rate = 0.85;
            utterance.pitch = 1.1;
            utterance.volume = 1;
            
            const voices = window.speechSynthesis.getVoices();
            const indonesianVoice = voices.find(v => 
                v.lang === 'id-ID' || 
                v.lang === 'id_ID' || 
                v.name.includes('Indonesia')
            );
            
            if (indonesianVoice) {
                utterance.voice = indonesianVoice;
                console.log('Using Indonesian voice:', indonesianVoice.name);
            }
            
            utterance.onstart = () => {
                console.log('Speech started');
            };
            
            utterance.onend = () => {
                console.log('Speech ended, reloading page...');
                setTimeout(() => location.reload(), 1000);
            };
            
            utterance.onerror = (e) => {
                console.error('Speech error:', e);
                setTimeout(() => location.reload(), 1000);
            };
            
            window.speechSynthesis.cancel();
            window.speechSynthesis.speak(utterance);
        });
        
        // Simpan/update status ke localStorage dengan counter
        const today = '<?= date('Y-m-d') ?>';
        let calledPatients = JSON.parse(localStorage.getItem('calledFarmasi_' + today) || '{}');
        
        if (calledPatients[no_resep]) {
            calledPatients[no_resep]++;
        } else {
            calledPatients[no_resep] = 1;
        }
        
        localStorage.setItem('calledFarmasi_' + today, JSON.stringify(calledPatients));
        console.log('Saved to localStorage:', calledPatients);
        
        markAsCalled(no_resep, calledPatients[no_resep]);
    })
    .catch(err => {
        console.error('Fetch Error:', err);
        alert('Koneksi gagal. Silakan coba lagi.');
        buttonElement.disabled = false;
        buttonElement.innerHTML = originalHTML;
    });
}

// === Load voices saat halaman dimuat ===
if ('speechSynthesis' in window) {
    speechSynthesis.onvoiceschanged = () => {
        speechSynthesis.getVoices();
    };
    speechSynthesis.getVoices();
}

// Bersihkan localStorage untuk hari sebelumnya
function cleanOldData() {
    const today = '<?= date('Y-m-d') ?>';
    Object.keys(localStorage).forEach(key => {
        if (key.startsWith('calledFarmasi_') && !key.includes(today)) {
            localStorage.removeItem(key);
        }
    });
}
cleanOldData();

// Auto refresh setiap 30 detik
setInterval(() => {
    if (!document.querySelector('.btn-call:disabled')) {
        location.reload();
    }
}, 30000);
</script>
</body>
</html>