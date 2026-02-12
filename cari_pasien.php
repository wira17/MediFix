<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
date_default_timezone_set('Asia/Jakarta');
include 'koneksi2.php';
include 'koneksi.php'; // Untuk tabel visitor_log

// Cek apakah sudah ada data pengunjung
if (!isset($_SESSION['visitor_verified']) || !isset($_SESSION['visitor_name'])) {
    header('Location: anjungan.php');
    exit;
}

// Simpan kunjungan pasien ke database
if (isset($_POST['simpan_kunjungan'])) {
    $visitor_name = $_SESSION['visitor_name'];
    $patient_name = trim($_POST['patient_name']);
    $patient_rm = trim($_POST['patient_rm']);
    
    if (!empty($patient_name) && !empty($patient_rm)) {
        try {
            // Update tabel visitor_log dengan nama pasien yang dituju
            $sql = "UPDATE visitor_log 
                    SET nama_pasien_tuju = ?, 
                        no_rm_pasien_tuju = ?,
                        waktu_kunjungan = NOW()
                    WHERE nama = ? 
                    ORDER BY id DESC 
                    LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$patient_name, $patient_rm, $visitor_name]);
            
            $success_msg = "Data kunjungan berhasil disimpan!";
            
        } catch(Exception $e) {
            $error_db = "Gagal menyimpan data kunjungan: " . $e->getMessage();
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

// Proses pencarian
$hasil = [];
$keyword = '';
$error = '';
if (isset($_POST['cari'])) {
    $keyword = trim($_POST['keyword']);
    
    if (!empty($keyword)) {
        try {
            $sql = "SELECT 
                        ki.no_rawat,
                        ki.kd_kamar,
                        ki.tgl_masuk,
                        ki.jam_masuk,
                        ki.diagnosa_awal,
                        ki.stts_pulang,
                        p.no_rkm_medis,
                        p.nm_pasien,
                        p.jk,
                        p.tgl_lahir,
                        p.alamat,
                        k.kd_bangsal,
                        b.nm_bangsal
                    FROM kamar_inap ki
                    INNER JOIN reg_periksa rp ON ki.no_rawat = rp.no_rawat
                    INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                    LEFT JOIN kamar k ON ki.kd_kamar = k.kd_kamar
                    LEFT JOIN bangsal b ON k.kd_bangsal = b.kd_bangsal
                    WHERE ki.stts_pulang = '-'
                    AND (ki.tgl_keluar = '0000-00-00' OR ki.tgl_keluar IS NULL)
                    AND (p.nm_pasien LIKE :keyword 
                         OR p.no_rkm_medis LIKE :keyword
                         OR ki.no_rawat LIKE :keyword)
                    ORDER BY ki.tgl_masuk DESC, ki.jam_masuk DESC";
            
            $stmt = $pdo_simrs->prepare($sql);
            $stmt->execute([':keyword' => "%$keyword%"]);
            $hasil = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $error = "Terjadi kesalahan: " . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cari Pasien Rawat Inap - <?= htmlspecialchars($namaRS) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<link rel="stylesheet" href="cari_pasien.css">
<style>
/* Virtual Keyboard Styles */
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

.active-input-keyboard {
    border-color: #667eea !important;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2) !important;
}

.visitor-info-box {
    background: linear-gradient(135deg, #e0e7ff, #fce7f3);
    padding: 20px;
    border-radius: 15px;
    margin-bottom: 25px;
    border-left: 5px solid #667eea;
}

.visitor-info-box h5 {
    color: #4c1d95;
    font-weight: 700;
    margin-bottom: 10px;
}

.visitor-info-box p {
    color: #5b21b6;
    margin: 5px 0;
    font-size: 1rem;
}

.visitor-info-box i {
    color: #667eea;
    margin-right: 8px;
}
</style>
</head>
<body>

<div class="container-main">
    <!-- Tombol Kembali -->
    <a href="anjungan.php" class="back-button">
        <i class="bi bi-arrow-left-circle"></i>
        Kembali ke Menu Utama
    </a>

    <!-- Info Pengunjung -->
    <div class="visitor-info-box">
        <h5><i class="bi bi-person-check-fill"></i> Identitas Pengunjung</h5>
        <p><i class="bi bi-person-fill"></i> <strong>Nama:</strong> <?= htmlspecialchars($_SESSION['visitor_name']) ?></p>
        <p style="font-size:0.9rem; color:#7c3aed; margin-top:10px;">
            <i class="bi bi-info-circle-fill"></i> Silakan cari pasien yang ingin Anda kunjungi
        </p>
    </div>

    <!-- Form Pencarian -->
    <div class="search-card">
        <div class="search-title">
            <i class="bi bi-search"></i>
            Pencarian Pasien Rawat Inap
        </div>
        <p class="search-subtitle">Masukkan nama pasien, nomor rekam medis, atau nomor rawat</p>
        
        <?php if (isset($error_db)): ?>
        <div class="alert-error">
            <i class="bi bi-exclamation-triangle"></i>
            <strong>Error:</strong> <?= htmlspecialchars($error_db) ?>
        </div>
        <?php endif; ?>

        <?php if (isset($success_msg)): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle-fill"></i>
            <?= htmlspecialchars($success_msg) ?>
        </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="alert-error">
            <i class="bi bi-exclamation-triangle"></i>
            <strong>Error:</strong> <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="search-box">
                <input 
                    type="text" 
                    name="keyword" 
                    id="searchInput"
                    class="virtual-keyboard-trigger"
                    placeholder="Ketik nama pasien atau nomor rekam medis..." 
                    value="<?= htmlspecialchars($keyword) ?>"
                    autocomplete="off"
                    required
                    readonly
                >
                <button type="submit" name="cari" class="btn-search">
                    <i class="bi bi-search"></i> Cari
                </button>
            </div>
        </form>

        <div class="search-info">
            <i class="bi bi-info-circle"></i>
            <strong>Tips:</strong> Anda dapat mencari dengan nama lengkap, nama sebagian, nomor rekam medis, atau nomor rawat pasien
        </div>

        <div class="keyboard-helper">
            <i class="bi bi-keyboard"></i> Klik pada kolom pencarian untuk memunculkan keyboard virtual
        </div>
    </div>

    <!-- Hasil Pencarian -->
    <?php if (isset($_POST['cari'])): ?>

    <!-- Modal Hasil Pencarian -->
    <div class="modal fade" id="modalHasilCari" tabindex="-1" data-bs-backdrop="static">
      <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content" style="border-radius:20px; overflow:hidden; box-shadow: 0 25px 60px rgba(0,0,0,0.3);">

          <div class="modal-header" style="background: linear-gradient(135deg, #667eea, #764ba2); color:white; border:none; padding:25px 30px;">
            <h4 class="modal-title" style="font-weight:700;">
              <i class="bi bi-person-lines-fill"></i> Hasil Pencarian Pasien
            </h4>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>

          <div class="modal-body" style="padding:30px;">
            
            <?php if (!empty($hasil)): ?>
                <div style="background: linear-gradient(135deg, #d1fae5, #a7f3d0); padding:20px; border-radius:15px; margin-bottom:25px; border-left:5px solid #10b981;">
                    <h5 style="color:#065f46; font-weight:700; margin:0;">
                        <i class="bi bi-check-circle-fill"></i> 
                        Ditemukan <?= count($hasil) ?> Pasien Rawat Inap
                    </h5>
                </div>

                <?php foreach ($hasil as $row): ?>
                    <div class="patient-card" style="position:relative;">
                        <div class="patient-name">
                            <i class="bi bi-person-circle"></i>
                            <?= htmlspecialchars($row['nm_pasien']) ?>
                            <span class="status-badge status-ranap">
                                <i class="bi bi-activity"></i> Rawat Inap Aktif
                            </span>
                            <span class="gender-badge <?= $row['jk'] == 'L' ? 'gender-l' : 'gender-p' ?>">
                                <i class="bi bi-gender-<?= $row['jk'] == 'L' ? 'male' : 'female' ?>"></i>
                                <?= $row['jk'] == 'L' ? 'Laki-laki' : 'Perempuan' ?>
                            </span>
                        </div>

                        <div class="patient-info">
                         

                            <div class="info-item">
                                <i class="bi bi-building"></i>
                                <div>
                                    <div class="info-label">Ruang Rawat</div>
                                    <div class="info-value"><?= htmlspecialchars($row['nm_bangsal'] ?: 'Belum ditentukan') ?></div>
                                </div>
                            </div>

                            <div class="info-item">
                                <i class="bi bi-calendar-plus"></i>
                                <div>
                                    <div class="info-label">Tanggal Masuk</div>
                                    <div class="info-value">
                                        <?php
                                        try {
                                            $tgl = new DateTime($row['tgl_masuk'] . ' ' . $row['jam_masuk']);
                                            echo $tgl->format('d/m/Y H:i');
                                        } catch (Exception $e) {
                                            echo htmlspecialchars($row['tgl_masuk']);
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>

                     

                            <?php if (!empty($row['alamat'])): ?>
                            <div class="info-item" style="grid-column: 1 / -1;">
                                <i class="bi bi-geo-alt"></i>
                                <div>
                                    <div class="info-label">Alamat</div>
                                    <div class="info-value"><?= htmlspecialchars($row['alamat']) ?></div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Tombol Catat Kunjungan -->
                        <div style="margin-top:20px; text-align:right;">
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="patient_name" value="<?= htmlspecialchars($row['nm_pasien']) ?>">
                                <input type="hidden" name="patient_rm" value="<?= htmlspecialchars($row['no_rkm_medis']) ?>">
                                <button type="submit" name="simpan_kunjungan" class="btn btn-success" style="padding:12px 30px; border-radius:12px; font-weight:600;">
                                    <i class="bi bi-bookmark-check-fill"></i> Kunjungani Pasien Ini
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>

            <?php else: ?>
                <div class="no-result">
                    <i class="bi bi-search"></i>
                    <h3>Pasien Tidak Ditemukan</h3>
                    <p>Kata kunci: <strong>"<?= htmlspecialchars($keyword) ?>"</strong></p>
                    <p style="font-size:0.95rem; color:#64748b; margin-top:15px;">
                        Pastikan pasien sedang dalam status rawat inap dan ejaan nama sudah benar
                    </p>
                </div>
            <?php endif; ?>

          </div>

          <div class="modal-footer" style="background:#f9fafb; border:none; padding:20px 30px; justify-content:center;">
            <button class="btn btn-secondary" data-bs-dismiss="modal" style="padding:12px 30px; border-radius:10px; font-weight:600;">
              <i class="bi bi-x-circle"></i> Tutup
            </button>
          </div>

        </div>
      </div>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", ()=>{
        new bootstrap.Modal(document.getElementById('modalHasilCari')).show();
    });
    </script>

    <?php endif; ?>

</div>

<!-- Virtual Keyboard -->
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Virtual Keyboard Handler
let currentInput = null;
let isUpperCase = false;

document.addEventListener('DOMContentLoaded', function() {
    const keyboard = document.getElementById('virtualKeyboard');
    const searchInput = document.getElementById('searchInput');
    
    // Show keyboard when search input is clicked
    searchInput.addEventListener('click', function() {
        currentInput = this;
        keyboard.style.display = 'block';
        this.classList.add('active-input-keyboard');
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
                if (currentInput) {
                    currentInput.classList.remove('active-input-keyboard');
                }
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

// Auto clear hasil setelah 5 menit (untuk anjungan)
setTimeout(function() {
    window.location.href = 'cari_pasien.php';
}, 300000); // 5 menit
</script>
</body>
</html>