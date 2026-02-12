<?php
session_start();
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$nama = $_SESSION['nama'] ?? 'Pengguna';

try {
    $today = date('Y-m-d');
    $check_today = $pdo_simrs->prepare("SELECT COUNT(*) FROM antrian_wira WHERE DATE(created_at)=?");
    $check_today->execute([$today]);
    if ($check_today->fetchColumn() == 0) {
        $pdo_simrs->exec("ALTER TABLE antrian_wira AUTO_INCREMENT = 1");
    }
} catch (PDOException $e) {
    die("Gagal reset nomor: " . $e->getMessage());
}

$limit = 20;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

try {
    $count_stmt = $pdo_simrs->prepare("SELECT COUNT(*) FROM antrian_wira WHERE DATE(created_at) = ?");
    $count_stmt->execute([$today]);
    $total = $count_stmt->fetchColumn();
    $total_pages = ceil($total / $limit);
} catch (PDOException $e) {
    die("Gagal menghitung data: " . $e->getMessage());
}

try {
    $stmt = $pdo_simrs->prepare("
        SELECT a.*, l.nama_loket
        FROM antrian_wira a
        LEFT JOIN loket_admisi_wira l ON a.loket_id = l.id
        WHERE DATE(a.created_at) = ?
        ORDER BY a.created_at ASC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute([$today]);
    $antrian = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $status_stmt = $pdo_simrs->prepare("
        SELECT 
            SUM(CASE WHEN status='Menunggu' THEN 1 ELSE 0 END) AS menunggu,
            SUM(CASE WHEN status='Dipanggil' THEN 1 ELSE 0 END) AS dipanggil,
            SUM(CASE WHEN status='Selesai' THEN 1 ELSE 0 END) AS selesai
        FROM antrian_wira
        WHERE DATE(created_at) = ?
    ");
    $status_stmt->execute([$today]);
    $status_count = $status_stmt->fetch(PDO::FETCH_ASSOC);
    $menunggu = $status_count['menunggu'] ?? 0;
    $dipanggil = $status_count['dipanggil'] ?? 0;
    $selesai = $status_count['selesai'] ?? 0;

    $loket_stmt = $pdo_simrs->query("SELECT id, nama_loket FROM loket_admisi_wira ORDER BY id ASC");
    $daftar_loket = $loket_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Gagal mengambil data antrian: " . $e->getMessage());
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Antrian Admisi - MediFix</title>
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
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
    overflow-x: hidden;
    padding-bottom: 60px;
}

/* Top Bar */
.top-bar {
    background: rgba(255, 255, 255, 0.98);
    backdrop-filter: blur(20px);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    padding: 10px 0;
    position: sticky;
    top: 0;
    z-index: 100;
    border-bottom: 1px solid rgba(0, 0, 0, 0.05);
}

.brand {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 16px;
    font-weight: 800;
    color: var(--dark);
}

.brand-icon {
    width: 32px;
    height: 32px;
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 16px;
}

.top-bar-right {
    display: flex;
    align-items: center;
    gap: 12px;
}

.live-clock {
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    color: white;
    padding: 6px 12px;
    border-radius: 6px;
    font-weight: 700;
    font-size: 11px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.user-badge {
    display: flex;
    align-items: center;
    gap: 6px;
    background: white;
    padding: 4px 4px 4px 10px;
    border-radius: 50px;
    border: 2px solid #e2e8f0;
    font-weight: 600;
    color: var(--dark);
    font-size: 11px;
}

.user-avatar {
    width: 24px;
    height: 24px;
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 11px;
}

.btn-back {
    background: linear-gradient(135deg, #64748b, #475569);
    color: white;
    border: none;
    padding: 6px 12px;
    border-radius: 6px;
    font-weight: 600;
    font-size: 11px;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.btn-back:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(100, 116, 139, 0.4);
    color: white;
}

/* Container */
.container-fluid {
    max-width: 1800px;
    margin: 0 auto;
}

.content-container {
    background: white;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    margin-top: 15px;
    border: 1px solid rgba(0, 0, 0, 0.05);
}

/* Page Header */
.page-title {
    font-size: 18px;
    font-weight: 800;
    color: var(--dark);
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.page-title i {
    font-size: 20px;
    color: var(--primary);
}

.page-subtitle {
    color: #64748b;
    font-size: 11px;
    margin-bottom: 15px;
}

/* Stats Container */
.stats-container {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 15px;
}

.stat-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 12px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.stat-icon {
    width: 36px;
    height: 36px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
}

.stat-icon.total {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
}

.stat-icon.menunggu {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: white;
}

.stat-icon.dipanggil {
    background: linear-gradient(135deg, #06b6d4, #0891b2);
    color: white;
}

.stat-icon.selesai {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
}

.stat-info {
    flex: 1;
}

.stat-label {
    font-size: 10px;
    color: #64748b;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    margin-bottom: 2px;
}

.stat-value {
    font-size: 20px;
    font-weight: 800;
    color: var(--dark);
}

/* Table */
.table-wrapper {
    border-radius: 6px;
    overflow: hidden;
    border: 1px solid #e2e8f0;
    margin-bottom: 15px;
}

.table {
    margin-bottom: 0;
    font-size: 11px;
}

.table thead th {
    background: linear-gradient(135deg, #1e293b, #334155);
    color: white;
    font-weight: 700;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    padding: 10px 8px;
    border: none;
    white-space: nowrap;
}

.table tbody td {
    padding: 8px;
    vertical-align: middle;
    font-size: 11px;
    color: #1e293b;
    border-bottom: 1px solid #f1f5f9;
}

.table tbody tr:hover {
    background: #f8fafc;
}

.table tbody tr:last-child td {
    border-bottom: none;
}

/* Badges */
.badge-status {
    padding: 4px 8px;
    border-radius: 4px;
    font-weight: 700;
    font-size: 9px;
    display: inline-block;
    text-transform: uppercase;
}

.badge-menunggu {
    background: #fef3c7;
    color: #92400e;
}

.badge-dipanggil {
    background: #dbeafe;
    color: #1e40af;
}

.badge-selesai {
    background: #d1fae5;
    color: #065f46;
}

.badge-loket {
    background: linear-gradient(135deg, var(--secondary), #0891b2);
    color: white;
    padding: 4px 10px;
    border-radius: 4px;
    font-weight: 700;
    font-size: 10px;
}

/* Form Elements */
.form-select-sm {
    font-size: 11px;
    padding: 4px 8px;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    font-weight: 600;
}

.form-control-sm {
    font-size: 11px;
    padding: 4px 8px;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    font-weight: 500;
}

.form-control-sm:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 2px rgba(14, 165, 233, 0.1);
}

/* Buttons */
.btn-action {
    padding: 5px 12px;
    border-radius: 4px;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-weight: 700;
    font-size: 10px;
    transition: all 0.3s ease;
    cursor: pointer;
    text-transform: uppercase;
}

.btn-call {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: white;
}

.btn-call:hover:not(:disabled) {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(245, 158, 11, 0.3);
}

.btn-save {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
}

.btn-save:hover:not(:disabled) {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(16, 185, 129, 0.3);
}

.btn-recall {
    background: linear-gradient(135deg, #06b6d4, #0891b2);
    color: white;
}

.btn-recall:hover:not(:disabled) {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(6, 182, 212, 0.3);
}

.btn-done {
    background: #e2e8f0;
    color: #64748b;
}

.btn-action:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

/* Action Group */
.action-group {
    display: flex;
    gap: 6px;
    align-items: center;
    justify-content: center;
}

/* Pagination */
.pagination-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px;
    background: #f8fafc;
    border-radius: 6px;
}

.pagination-info {
    color: #64748b;
    font-size: 11px;
    font-weight: 600;
}

.pagination {
    display: flex;
    gap: 4px;
    margin: 0;
}

.page-link {
    border: 1px solid #e2e8f0;
    color: var(--dark);
    padding: 6px 10px;
    border-radius: 4px;
    font-weight: 600;
    font-size: 11px;
    transition: all 0.3s ease;
    text-decoration: none;
}

.page-link:hover {
    background: #f8fafc;
    border-color: var(--primary);
    color: var(--primary);
}

.page-item.active .page-link {
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    border-color: var(--primary);
    color: white;
}

.page-item.disabled .page-link {
    opacity: 0.5;
    cursor: not-allowed;
    pointer-events: none;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 40px 20px;
}

.empty-icon {
    width: 60px;
    height: 60px;
    background: #f1f5f9;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 12px;
}

.empty-icon i {
    font-size: 28px;
    color: #cbd5e1;
}

.empty-title {
    font-size: 14px;
    font-weight: 700;
    color: var(--dark);
    margin-bottom: 4px;
}

.empty-text {
    color: #64748b;
    font-size: 11px;
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
    
    .table-wrapper {
        overflow-x: auto;
    }
    
    .pagination-container {
        flex-direction: column;
        gap: 10px;
    }
}
</style>
</head>
<body>

<div class="top-bar">
    <div class="container-fluid px-4">
        <div class="d-flex align-items-center justify-content-between">
            <div class="brand">
                <div class="brand-icon"><i class="bi bi-person-vcard-fill"></i></div>
                <span>Antrian Admisi</span>
            </div>
            <div class="top-bar-right">
                <div class="live-clock"><i class="bi bi-clock-fill"></i><span id="clockDisplay"></span></div>
                <div class="user-badge">
                    <span><?= htmlspecialchars($nama) ?></span>
                    <div class="user-avatar"><i class="bi bi-person-fill"></i></div>
                </div>
                <a href="admisi_dashboard.php" class="btn-back"><i class="bi bi-arrow-left-circle-fill"></i>Kembali</a>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid px-4">
    <div class="content-container">
        <div class="page-title">
            <i class="bi bi-clipboard-data"></i>
            Data Antrian Admisi
        </div>
        <div class="page-subtitle">
            <?= date('l, d F Y') ?> • Menampilkan <?= count($antrian) ?> dari <?= $total ?> antrian
        </div>
        
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-icon total"><i class="bi bi-list-ol"></i></div>
                <div class="stat-info">
                    <div class="stat-label">Total</div>
                    <div class="stat-value"><?= $total ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon menunggu"><i class="bi bi-hourglass-split"></i></div>
                <div class="stat-info">
                    <div class="stat-label">Menunggu</div>
                    <div class="stat-value"><?= $menunggu ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon dipanggil"><i class="bi bi-megaphone"></i></div>
                <div class="stat-info">
                    <div class="stat-label">Dipanggil</div>
                    <div class="stat-value"><?= $dipanggil ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon selesai"><i class="bi bi-check-circle"></i></div>
                <div class="stat-info">
                    <div class="stat-label">Selesai</div>
                    <div class="stat-value"><?= $selesai ?></div>
                </div>
            </div>
        </div>
        
        <?php if ($total > 0): ?>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th width="30">No</th>
                        <th width="80">No. Antrian</th>
                        <th width="100">No. RM</th>
                        <th width="80">Status</th>
                        <th width="120">Waktu Ambil</th>
                        <th width="100">Waktu Panggil</th>
                        <th width="120">Loket</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $no = $offset + 1; 
                    foreach ($antrian as $row): 
                        $statusClass = ['Menunggu'=>'menunggu','Dipanggil'=>'dipanggil','Selesai'=>'selesai'][$row['status']] ?? '';
                    ?>
                    <tr data-id="<?= $row['id']; ?>">
                        <td class="text-center fw-bold" style="color: #64748b;"><?= $no++; ?></td>
                        <td class="text-center">
                            <span class="fw-bold" style="color: #0ea5e9; font-size: 12px;">
                                <?= htmlspecialchars($row['nomor']); ?>
                            </span>
                        </td>
                        <td class="text-center no-rm-cell" data-rm="<?= htmlspecialchars($row['no_rkm_medis'] ?? '') ?>">
                            <span class="fw-bold" style="font-size: 11px;">
                                <?= !empty($row['no_rkm_medis']) ? htmlspecialchars($row['no_rkm_medis']) : '-' ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <span class="badge-status badge-<?= $statusClass ?>">
                                <?= htmlspecialchars($row['status']); ?>
                            </span>
                        </td>
                        <td class="text-center" style="font-size: 10px;">
                            <?= date('d-m-Y H:i', strtotime($row['created_at'])); ?>
                        </td>
                        <td class="text-center" style="font-size: 10px;">
                            <?= $row['waktu_panggil'] ? date('H:i:s', strtotime($row['waktu_panggil'])) : '-'; ?>
                        </td>
                        <td class="text-center">
                            <?php if (!empty($row['nama_loket'])): ?>
                                <span class="badge-loket"><?= htmlspecialchars($row['nama_loket']); ?></span>
                            <?php else: ?>
                                <select id="loket<?= $row['id']; ?>" class="form-select form-select-sm">
                                    <?php foreach ($daftar_loket as $l): ?>
                                        <option value="<?= $l['id']; ?>"><?= htmlspecialchars($l['nama_loket']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['status'] == 'Menunggu'): ?>
                                <div class="action-group">
                                    <button class="btn-action btn-call" id="btn<?= $row['id']; ?>" 
                                            onclick="panggilAntrian('<?= $row['id']; ?>','<?= $row['nomor']; ?>')">
                                        <i class="bi bi-megaphone-fill"></i> Panggil
                                    </button>
                                </div>
                            <?php elseif ($row['status'] == 'Dipanggil'): ?>
                                <div class="action-group">
                                    <input type="text" id="rm<?= $row['id']; ?>" class="form-control form-control-sm" 
                                           maxlength="15" placeholder="No. RM" 
                                           value="<?= htmlspecialchars($row['no_rkm_medis'] ?? '') ?>" 
                                           style="width: 90px;">
                                    <button class="btn-action btn-save" id="btnSave<?= $row['id']; ?>" 
                                            onclick="simpanRM('<?= $row['id']; ?>')">
                                        <i class="bi bi-check2"></i> Simpan
                                    </button>
                                    <button class="btn-action btn-recall" id="btnRecall<?= $row['id']; ?>" 
                                            onclick="panggilUlang('<?= $row['id']; ?>','<?= $row['nomor']; ?>','<?= $row['loket_id']; ?>')">
                                        <i class="bi bi-repeat"></i> Ulang
                                    </button>
                                </div>
                            <?php else: ?>
                                <button class="btn-action btn-done" disabled>
                                    <i class="bi bi-check2-circle"></i> Selesai
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="pagination-container">
            <div class="pagination-info">
                Halaman <?= $page ?> dari <?= $total_pages ?> (Total: <?= $total ?> antrian)
            </div>
            <ul class="pagination">
                <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= $page - 1 ?>">
                        <i class="bi bi-chevron-left"></i> Prev
                    </a>
                </li>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= $page + 1 ?>">
                        Next <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
            </ul>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="empty-state">
            <div class="empty-icon"><i class="bi bi-inbox"></i></div>
            <div class="empty-title">Belum Ada Antrian</div>
            <div class="empty-text">Tidak ada antrian admisi untuk hari ini</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const SOUND_BASE_PATH = './sound/';

function updateClock() {
    const now = new Date();
    document.getElementById('clockDisplay').textContent = now.toLocaleTimeString('id-ID', { 
        hour: '2-digit', 
        minute: '2-digit', 
        second: '2-digit' 
    });
}
setInterval(updateClock, 1000);
updateClock();

function playSequentialSounds(files, callback) {
    if (files.length === 0) {
        if (callback) callback();
        return;
    }
    const audio = new Audio(SOUND_BASE_PATH + files[0]);
    audio.playbackRate = files[0].includes('opening') ? 1.0 : 1.35;
    audio.play().catch(e => console.error('Play error:', e));
    audio.onended = () => setTimeout(() => playSequentialSounds(files.slice(1), callback), 80);
}

function numberToSoundFiles(num) {
    const files = [];
    // PERBAIKAN: Ubah dari if (num < 12) menjadi if (num < 11)
    // Sehingga angka 11 akan masuk ke kondisi khusus "sebelas"
    if (num < 11) {
        files.push(`${numberToWords(num)}.mp3`);
    } else if (num < 20) {
        if (num === 11) {
            files.push(`sebelas.mp3`);
        } else {
            files.push(`${numberToWords(num - 10)}.mp3`);
            files.push(`belas.mp3`);
        }
    } else if (num < 100) {
        const puluh = Math.floor(num / 10);
        const satuan = num % 10;
        files.push(`${numberToWords(puluh)}.mp3`);
        files.push(`puluh.mp3`);
        if (satuan > 0) files.push(...numberToSoundFiles(satuan));
    } else if (num < 200) {
        files.push(`seratus.mp3`);
        const sisa = num - 100;
        if (sisa > 0) files.push(...numberToSoundFiles(sisa));
    } else if (num < 1000) {
        const ratus = Math.floor(num / 100);
        const sisa = num % 100;
        files.push(`${numberToWords(ratus)}.mp3`);
        files.push(`ratus.mp3`);
        if (sisa > 0) files.push(...numberToSoundFiles(sisa));
    }
    return files;
}

function numberToWords(num) {
    const words = ["", "satu", "dua", "tiga", "empat", "lima", "enam", "tujuh", "delapan", "sembilan", "sepuluh"];
    return words[num] || num.toString();
}

function cekRMSebelumnya(currentId) {
    const rows = Array.from(document.querySelectorAll('table tbody tr'));
    for (const row of rows) {
        const rowId = row.getAttribute('data-id');
        if (rowId === currentId) break;
        
        const noRmCell = row.querySelector('.no-rm-cell');
        if (noRmCell) {
            const rm = (noRmCell.getAttribute('data-rm') || '').trim();
            if (rm === '' || rm === '-') {
                alert('⚠️ Harap isi No. RM antrian sebelumnya terlebih dahulu!');
                return false;
            }
        }
    }
    return true;
}

function panggilAntrian(id, nomor) {
    if (!cekRMSebelumnya(id)) return;
    
    const btn = document.getElementById('btn' + id);
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-volume-up-fill"></i> Memanggil...';
    }

    const loketEl = document.getElementById('loket' + id);
    const loket = loketEl ? loketEl.value : '';

    fetch('panggil_antrian.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${encodeURIComponent(id)}&loket_id=${encodeURIComponent(loket)}&ulang=0`
    })
    .then(res => res.text())
    .then(() => {
        const huruf = nomor.substring(0, 1).toUpperCase();
        const angka = parseInt(nomor.substring(1));
        const files = ['opening.mp3', 'nomor antrian.mp3', `${huruf}.mp3`];
        files.push(...numberToSoundFiles(angka));
        files.push('silahkan menuju loket.mp3');
        if (loket && !isNaN(parseInt(loket))) {
            files.push(...numberToSoundFiles(parseInt(loket)));
        }
        console.log('🔊 Playing:', files);
        playSequentialSounds(files, () => setTimeout(() => location.reload(), 500));
    })
    .catch(err => {
        console.error('❌ Error:', err);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-megaphone-fill"></i> Panggil';
        }
    });
}

function simpanRM(id) {
    const rmInput = document.getElementById('rm' + id);
    const rm = rmInput ? rmInput.value.trim() : '';
    
    if (rm === '') {
        alert('⚠️ No. RM tidak boleh kosong!');
        return;
    }

    const btnSave = document.getElementById('btnSave' + id);
    if (btnSave) {
        btnSave.disabled = true;
        btnSave.innerHTML = '<i class="bi bi-hourglass-split"></i> Simpan...';
    }

    fetch('simpan_rm.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${encodeURIComponent(id)}&rm=${encodeURIComponent(rm)}`
    })
    .then(res => res.text())
    .then(() => {
        alert('✅ No. RM berhasil disimpan!');
        location.reload();
    })
    .catch(err => {
        console.error('❌ Error:', err);
        alert('❌ Gagal menyimpan No. RM!');
        if (btnSave) {
            btnSave.disabled = false;
            btnSave.innerHTML = '<i class="bi bi-check2"></i> Simpan';
        }
    });
}

function panggilUlang(id, nomor, loket) {
    const btn = document.getElementById('btnRecall' + id);
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-volume-up-fill"></i> Memanggil...';
    }

    fetch('panggil_antrian.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${encodeURIComponent(id)}&loket_id=${encodeURIComponent(loket)}&ulang=1`
    })
    .then(res => res.text())
    .then(() => {
        const huruf = nomor.substring(0, 1).toUpperCase();
        const angka = parseInt(nomor.substring(1));
        const files = ['opening.mp3', 'nomor antrian.mp3', `${huruf}.mp3`];
        files.push(...numberToSoundFiles(angka));
        files.push('silahkan menuju loket.mp3');
        if (loket && !isNaN(parseInt(loket))) {
            files.push(...numberToSoundFiles(parseInt(loket)));
        }
        console.log('🔊 Playing:', files);
        playSequentialSounds(files, () => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-repeat"></i> Ulang';
            }
        });
    })
    .catch(err => {
        console.error('❌ Error:', err);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-repeat"></i> Ulang';
        }
    });
}

// Silent test
window.addEventListener('load', () => {
    console.log('🔍 Sound System Check');
    console.log('📍 Path:', SOUND_BASE_PATH);
    const testAudio = new Audio(SOUND_BASE_PATH + 'opening.mp3');
    testAudio.play()
        .then(() => {
            console.log('✅ Sound system OK');
            testAudio.pause();
        })
        .catch(() => {
            console.log('⚠️ Autoplay blocked (normal)');
        });
});
</script>
</body>
</html>