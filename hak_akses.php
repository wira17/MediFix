<?php
session_start();
include 'koneksi.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$nama = $_SESSION['nama'] ?? 'Pengguna';
date_default_timezone_set('Asia/Jakarta');

// Ambil data
$menus = $pdo->query("SELECT * FROM menu_list ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$users = $pdo->query("SELECT * FROM users ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

// User yg dipilih
$selectedUser = $_GET['user_id'] ?? $users[0]['id'];

// Simpan akses
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = $_POST['user_id'];

    $pdo->prepare("DELETE FROM hak_akses WHERE user_id=?")->execute([$user_id]);

    foreach ($menus as $m) {
        $izin = isset($_POST['akses'][$m['kode']]) ? 1 : 0;
        $stmt = $pdo->prepare("INSERT INTO hak_akses (user_id, menu, izin) VALUES (?, ?, ?)");
        $stmt->execute([$user_id, $m['kode'], $izin]);
    }

    // Sync session jika edit dirinya sendiri
    if ($_SESSION['user_id'] == $user_id) {
        $_SESSION['akses'] = [];
        $reload = $pdo->prepare("SELECT menu, izin FROM hak_akses WHERE user_id=?");
        $reload->execute([$user_id]);
        foreach ($reload->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $_SESSION['akses'][$row['menu']] = $row['izin'];
        }
    }

    $success = true;
}

// Load akses user terpilih
$currentAccess = [];
$load = $pdo->prepare("SELECT menu, izin FROM hak_akses WHERE user_id=?");
$load->execute([$selectedUser]);
foreach ($load->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $currentAccess[$r['menu']] = $r['izin'];
}

// Get selected user name
$selectedUserName = '';
foreach($users as $u) {
    if($u['id'] == $selectedUser) {
        $selectedUserName = $u['nama'];
        break;
    }
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Manajemen Hak Akses - MediFix</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

:root {
    --primary: #8b5cf6;
    --primary-dark: #7c3aed;
    --secondary: #a78bfa;
    --success: #10b981;
    --danger: #ef4444;
    --warning: #f59e0b;
    --info: #06b6d4;
    --dark: #1e293b;
    --light: #f8fafc;
}

body {
    font-family: 'Inter', sans-serif;
    background: #f0f4f8;
    height: 100vh;
    overflow: hidden;
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
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.bg-animated::before,
.bg-animated::after {
    content: '';
    position: absolute;
    border-radius: 50%;
    opacity: 0.1;
}

.bg-animated::before {
    width: 800px;
    height: 800px;
    background: radial-gradient(circle, #fff 0%, transparent 70%);
    top: -400px;
    right: -400px;
    animation: pulse 8s ease-in-out infinite;
}

.bg-animated::after {
    width: 600px;
    height: 600px;
    background: radial-gradient(circle, #fff 0%, transparent 70%);
    bottom: -300px;
    left: -300px;
    animation: pulse 10s ease-in-out infinite reverse;
}

@keyframes pulse {
    0%, 100% { transform: scale(1); opacity: 0.1; }
    50% { transform: scale(1.1); opacity: 0.15; }
}

/* Top Bar */
.top-bar {
    background: rgba(255, 255, 255, 0.98);
    backdrop-filter: blur(20px);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    padding: 12px 0;
    position: relative;
    z-index: 100;
    border-bottom: 1px solid rgba(0, 0, 0, 0.05);
}

.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 18px;
    font-weight: 800;
    color: var(--dark);
}

.brand-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 20px;
}

.top-bar-right {
    display: flex;
    align-items: center;
    gap: 20px;
}

.time-badge {
    display: flex;
    align-items: center;
    gap: 8px;
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    color: white;
    padding: 8px 16px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
}

.user-badge {
    display: flex;
    align-items: center;
    gap: 10px;
    background: white;
    padding: 6px 6px 6px 14px;
    border-radius: 50px;
    border: 2px solid #e2e8f0;
    font-size: 13px;
    font-weight: 600;
    color: var(--dark);
}

.user-avatar-small {
    width: 32px;
    height: 32px;
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 14px;
}

/* Main Content */
.main-content {
    height: calc(100vh - 130px);
    overflow-y: auto;
    position: relative;
    z-index: 1;
    padding: 20px 0;
}

.main-content::-webkit-scrollbar {
    width: 8px;
}

.main-content::-webkit-scrollbar-track {
    background: transparent;
}

.main-content::-webkit-scrollbar-thumb {
    background: rgba(148, 163, 184, 0.3);
    border-radius: 10px;
}

/* Page Header */
.page-header {
    background: white;
    border-radius: 16px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    border: 1px solid rgba(0, 0, 0, 0.05);
    position: relative;
    overflow: hidden;
}

.page-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, #f59e0b, #fbbf24, #fcd34d);
}

.page-title {
    font-size: 20px;
    font-weight: 700;
    color: var(--dark);
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.page-title i {
    font-size: 24px;
    background: linear-gradient(135deg, #f59e0b, #fbbf24);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.page-subtitle {
    color: #64748b;
    font-size: 12px;
    font-weight: 500;
}

/* Content Wrapper */
.content-wrapper {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 20px;
}

/* Sidebar */
.sidebar-card {
    background: white;
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    border: 1px solid rgba(0, 0, 0, 0.05);
    max-height: calc(100vh - 250px);
    overflow-y: auto;
}

.sidebar-card::-webkit-scrollbar {
    width: 6px;
}

.sidebar-card::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 10px;
}

.sidebar-card::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 10px;
}

.sidebar-title {
    font-size: 14px;
    font-weight: 700;
    color: var(--dark);
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.sidebar-title i {
    color: var(--primary);
}

.user-item {
    padding: 10px 12px;
    border-radius: 10px;
    margin-bottom: 6px;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 500;
    color: var(--dark);
    text-decoration: none;
}

.user-item:hover {
    background: #f1f5f9;
    color: var(--dark);
}

.user-item.active {
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    color: white;
    font-weight: 600;
}

.user-item i {
    font-size: 16px;
}

.user-badge-label {
    font-size: 10px;
    background: rgba(245, 158, 11, 0.2);
    color: #f59e0b;
    padding: 2px 6px;
    border-radius: 4px;
    margin-left: auto;
}

.user-item.active .user-badge-label {
    background: rgba(255, 255, 255, 0.2);
    color: white;
}

/* Main Card */
.main-card {
    background: white;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    border: 1px solid rgba(0, 0, 0, 0.05);
}

.main-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 2px solid #f1f5f9;
}

.main-card-title {
    font-size: 16px;
    font-weight: 700;
    color: var(--dark);
    display: flex;
    align-items: center;
    gap: 8px;
}

.main-card-title i {
    color: var(--warning);
    font-size: 20px;
}

.selected-user {
    font-size: 14px;
    font-weight: 600;
    color: var(--primary);
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 8px;
    margin-bottom: 16px;
}

.btn-action-sm {
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    border: none;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
}

.btn-back-sm {
    background: linear-gradient(135deg, #64748b, #475569);
    color: white;
    text-decoration: none;
}

.btn-check-all {
    background: linear-gradient(135deg, var(--success), #059669);
    color: white;
}

.btn-uncheck-all {
    background: linear-gradient(135deg, var(--danger), #dc2626);
    color: white;
}

.btn-action-sm:hover {
    transform: translateY(-1px);
}

/* Table */
.table-custom {
    font-size: 13px;
    margin-bottom: 20px;
}

.table-custom thead {
    background: linear-gradient(135deg, var(--warning), #fbbf24);
    color: white;
}

.table-custom thead th {
    font-weight: 600;
    font-size: 12px;
    padding: 12px;
    border: none;
}

.table-custom tbody td {
    padding: 12px;
    vertical-align: middle;
    border-bottom: 1px solid #f1f5f9;
}

.table-custom tbody tr:last-child td {
    border-bottom: none;
}

.table-custom tbody tr {
    transition: all 0.2s ease;
}

.table-custom tbody tr:hover {
    background: #fef3c7;
}

/* Checkbox Custom */
.form-check-input {
    width: 1.5em;
    height: 1.5em;
    border: 2px solid #cbd5e1;
    cursor: pointer;
}

.form-check-input:checked {
    background-color: var(--success);
    border-color: var(--success);
}

/* Submit Button */
.btn-submit {
    width: 100%;
    padding: 14px;
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    color: white;
    border: none;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 700;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(139, 92, 246, 0.4);
}

/* Bottom Bar */
.bottom-bar {
    background: rgba(255, 255, 255, 0.98);
    backdrop-filter: blur(20px);
    padding: 12px 0;
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 100;
    box-shadow: 0 -1px 3px rgba(0, 0, 0, 0.05);
    border-top: 1px solid rgba(0, 0, 0, 0.05);
}

.footer-content {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 16px;
    font-size: 12px;
    color: #64748b;
    font-weight: 600;
}

.footer-logo {
    height: 24px;
    border-radius: 6px;
}

.footer-divider {
    width: 1px;
    height: 20px;
    background: #e2e8f0;
}

.footer-contact {
    display: flex;
    align-items: center;
    gap: 6px;
    background: linear-gradient(135deg, #25D366, #128C7E);
    color: white;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 700;
}

/* Responsive */
@media (max-width: 992px) {
    .content-wrapper {
        grid-template-columns: 1fr;
    }
    
    .sidebar-card {
        max-height: 300px;
    }
    
    .user-badge span {
        display: none;
    }
}

@media (max-width: 768px) {
    .page-title {
        font-size: 18px;
    }
    
    .footer-content {
        flex-wrap: wrap;
        gap: 8px;
    }
}
</style>
</head>
<body>

<!-- Animated Background -->
<div class="bg-animated"></div>

<!-- Top Bar -->
<div class="top-bar">
    <div class="container">
        <div class="d-flex align-items-center justify-content-between">
            <div class="brand">
                <div class="brand-icon">
                    <i class="bi bi-hospital-fill"></i>
                </div>
                <span>MediFix</span>
            </div>
            
            <div class="top-bar-right">
                <div class="time-badge">
                    <i class="bi bi-clock-fill"></i>
                    <span id="clockDisplay"></span>
                </div>
                
                <div class="user-badge">
                    <span><?= htmlspecialchars($nama) ?></span>
                    <div class="user-avatar-small">
                        <i class="bi bi-person-fill"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<div class="main-content">
    <div class="container">
        
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-title">
                <i class="bi bi-shield-lock-fill"></i>
                Manajemen Hak Akses
            </div>
            <div class="page-subtitle">
                Kelola hak akses menu sistem untuk setiap pengguna
            </div>
        </div>
        
        <!-- Content -->
        <div class="content-wrapper">
            
            <!-- Sidebar - User List -->
            <div class="sidebar-card">
                <div class="sidebar-title">
                    <i class="bi bi-people-fill"></i>
                    Daftar Pengguna
                </div>
                
                <?php foreach($users as $u): ?>
                <a href="?user_id=<?= $u['id'] ?>" class="user-item <?= ($selectedUser == $u['id']) ? 'active' : '' ?>">
                    <i class="bi bi-person-circle"></i>
                    <span><?= htmlspecialchars($u['nama']) ?></span>
                    <?php if($u['id'] == $_SESSION['user_id']): ?>
                    <span class="user-badge-label">Anda</span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
            
            <!-- Main Content - Access Form -->
            <div class="main-card">
                <div class="main-card-header">
                    <div class="main-card-title">
                        <i class="bi bi-key-fill"></i>
                        Hak Akses Menu
                    </div>
                    <div class="selected-user">
                        <i class="bi bi-arrow-right"></i> <?= htmlspecialchars($selectedUserName) ?>
                    </div>
                </div>
                
                <form method="POST">
                    <input type="hidden" name="user_id" value="<?= $selectedUser ?>">
                    
                    <!-- Action Buttons -->
                    <div class="action-buttons">
                        <a href="setting_dashboard.php" class="btn-action-sm btn-back-sm">
                            <i class="bi bi-arrow-left"></i> Kembali
                        </a>
                        <button type="button" onclick="checkAll()" class="btn-action-sm btn-check-all">
                            <i class="bi bi-check-all"></i> Centang Semua
                        </button>
                        <button type="button" onclick="uncheckAll()" class="btn-action-sm btn-uncheck-all">
                            <i class="bi bi-x-lg"></i> Kosongkan
                        </button>
                    </div>
                    
                    <!-- Table -->
                    <div class="table-responsive">
                        <table class="table table-custom align-middle">
                            <thead>
                                <tr>
                                    <th>Nama Menu</th>
                                    <th width="100" class="text-center">Akses</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($menus as $m): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="bi bi-menu-button-wide" style="color: var(--warning);"></i>
                                            <span style="font-weight: 600; color: var(--dark);"><?= htmlspecialchars($m['nama_menu']) ?></span>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <input type="checkbox" class="form-check-input menuCheck"
                                               name="akses[<?= $m['kode'] ?>]"
                                               <?= (!empty($currentAccess[$m['kode']])) ? 'checked' : '' ?>>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Submit Button -->
                    <button type="submit" class="btn-submit">
                        <i class="bi bi-save"></i> Simpan Hak Akses
                    </button>
                </form>
            </div>
            
        </div>
        
    </div>
</div>

<div class="bottom-bar">
    <div class="container">
        <div class="footer-content">
            <img src="image/logo.png" class="footer-logo" alt="Logo">
            <div class="footer-divider"></div>
            <div class="footer-contact">
                <i class="bi bi-whatsapp"></i>
                <strong style="color: #1e293b;">082177846209 - © 2026 MediFix Apps - All Rights Reserved</strong>
            </div>
            <div class="footer-divider"></div>
            <img src="image/logo.png" class="footer-logo" alt="Logo">
        </div>
    </div>
</div>

<?php if (!empty($success)): ?>
<script>
Swal.fire({
    icon: 'success',
    title: 'Berhasil!',
    text: 'Hak akses berhasil diperbarui.',
    timer: 2000,
    showConfirmButton: false,
    customClass: {
        popup: 'animated-popup'
    }
});
</script>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function updateClock() {
    const now = new Date();
    const waktu = now.toLocaleTimeString('id-ID');
    document.getElementById('clockDisplay').innerHTML = waktu;
}

function checkAll() {
    document.querySelectorAll('.menuCheck').forEach(c => c.checked = true);
}

function uncheckAll() {
    document.querySelectorAll('.menuCheck').forEach(c => c.checked = false);
}

setInterval(updateClock, 1000);
updateClock();
</script>
</body>
</html>