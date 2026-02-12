<?php
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

$today = date('Y-m-d');
$poli = $_GET['poli'] ?? '';
$dokter = $_GET['dokter'] ?? ''; // Tambahan filter dokter

try {
    $excluded_poli = ['IGDK','PL013','PL014','PL015','PL016','PL017','U0022','U0030'];
    $excluded_list = "'" . implode("','", $excluded_poli) . "'";

    // === AMBIL NOMOR ANTRIAN YANG SEDANG DIPANGGIL DARI FILE ===
    $current_call_file = 'data/current_call.json';
    $current_call = null;
    
    if (file_exists($current_call_file)) {
        $file_content = @file_get_contents($current_call_file);
        if ($file_content) {
            $call_data = json_decode($file_content, true);
            
            // Debug log
            error_log("Display membaca file: " . print_r($call_data, true));
            
            // Tampilkan data tanpa pengecekan timeout - tetap tampil sampai ada panggilan baru
            if ($call_data && isset($call_data['no_antrian'])) {
                // Filter berdasarkan poli dan dokter
                $show_call = true;
                
                if (!empty($poli) && isset($call_data['kd_poli']) && $call_data['kd_poli'] != $poli) {
                    $show_call = false;
                }
                
                if (!empty($dokter) && isset($call_data['kd_dokter']) && $call_data['kd_dokter'] != $dokter) {
                    $show_call = false;
                }
                
                if ($show_call) {
                    $current_call = $call_data;
                    error_log("Display menampilkan panggilan: " . $call_data['no_antrian']);
                }
            }
        } else {
            error_log("File current_call.json kosong atau tidak bisa dibaca");
        }
    } else {
        error_log("File current_call.json tidak ditemukan di: " . realpath('.') . '/data/current_call.json');
    }

    // === PASIEN TERAKHIR YANG SUDAH DILAYANI ===
    $sql_layani = "
        SELECT 
            r.no_reg, r.kd_poli, r.kd_dokter, ps.nm_pasien, p.nm_poli, d.nm_dokter
        FROM reg_periksa r
        LEFT JOIN pasien ps ON r.no_rkm_medis = ps.no_rkm_medis
        LEFT JOIN poliklinik p ON r.kd_poli = p.kd_poli
        LEFT JOIN dokter d ON r.kd_dokter = d.kd_dokter
        WHERE r.tgl_registrasi = :tgl 
          AND r.stts = 'Sudah'
          AND r.kd_poli NOT IN ($excluded_list)";
    if (!empty($poli)) {
        $sql_layani .= " AND r.kd_poli = :poli";
    }
    if (!empty($dokter)) {
        $sql_layani .= " AND r.kd_dokter = :dokter";
    }
    $sql_layani .= " ORDER BY r.no_reg+0 DESC LIMIT 1";
    $stmt_layani = $pdo_simrs->prepare($sql_layani);
    $stmt_layani->bindValue(':tgl', $today);
    if (!empty($poli)) $stmt_layani->bindValue(':poli', $poli);
    if (!empty($dokter)) $stmt_layani->bindValue(':dokter', $dokter);
    $stmt_layani->execute();
    $layani = $stmt_layani->fetch(PDO::FETCH_ASSOC);

    // === SEMUA DATA PASIEN HARI INI ===
    $sql = "
        SELECT 
            r.no_reg,
            r.kd_poli,
            r.kd_dokter,
            r.no_rawat,
            ps.nm_pasien,
            p.nm_poli,
            d.nm_dokter,
            r.stts
        FROM reg_periksa r
        LEFT JOIN pasien ps ON r.no_rkm_medis = ps.no_rkm_medis
        LEFT JOIN poliklinik p ON r.kd_poli = p.kd_poli
        LEFT JOIN dokter d ON r.kd_dokter = d.kd_dokter
        WHERE r.tgl_registrasi = :tgl
          AND r.kd_poli NOT IN ($excluded_list)";
    if (!empty($poli)) {
        $sql .= " AND r.kd_poli = :poli";
    }
    if (!empty($dokter)) {
        $sql .= " AND r.kd_dokter = :dokter";
    }
    $sql .= " ORDER BY r.no_reg+0 ASC";
    $stmt = $pdo_simrs->prepare($sql);
    $stmt->bindValue(':tgl', $today);
    if (!empty($poli)) $stmt->bindValue(':poli', $poli);
    if (!empty($dokter)) $stmt->bindValue(':dokter', $dokter);
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Hitung statistik
    $total = count($data);
    $sudah = count(array_filter($data, fn($d) => $d['stts'] == 'Sudah'));
    $menunggu = count(array_filter($data, fn($d) => in_array($d['stts'], ['Menunggu', 'Belum'])));
    
    // Ambil nama dokter untuk header
    $nama_dokter = '';
    if (!empty($dokter) && count($data) > 0) {
        $nama_dokter = $data[0]['nm_dokter'] ?? '';
    }

    // === Fungsi sensor nama ===
    function sensorNama($nama) {
        $kata = explode(' ', $nama);
        $hasil = [];
        foreach ($kata as $k) {
            $hasil[] = mb_substr($k, 0, 1) . str_repeat('*', max(0, mb_strlen($k) - 1));
        }
        return implode(' ', $hasil);
    }

} catch (PDOException $e) {
    die("Gagal mengambil data: " . $e->getMessage());
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Display Antrian Poliklinik - MediFix</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Inter', sans-serif;
    background: linear-gradient(135deg, #1e3c72 0%, #2a5298 50%, #7e22ce 100%);
    color: white;
    overflow: hidden;
    height: 100vh;
    display: flex;
    flex-direction: column;
}

/* Animated Background */
.bg-animated {
    position: fixed;
    width: 100%;
    height: 100%;
    overflow: hidden;
    z-index: 0;
}

.bg-animated::before,
.bg-animated::after {
    content: '';
    position: absolute;
    border-radius: 50%;
    opacity: 0.05;
}

.bg-animated::before {
    width: 800px;
    height: 800px;
    background: radial-gradient(circle, #fff 0%, transparent 70%);
    top: -400px;
    right: -200px;
    animation: float1 15s ease-in-out infinite;
}

.bg-animated::after {
    width: 600px;
    height: 600px;
    background: radial-gradient(circle, #fff 0%, transparent 70%);
    bottom: -300px;
    left: -200px;
    animation: float2 20s ease-in-out infinite;
}

@keyframes float1 {
    0%, 100% { transform: translate(0, 0) rotate(0deg); }
    50% { transform: translate(50px, -50px) rotate(10deg); }
}

@keyframes float2 {
    0%, 100% { transform: translate(0, 0) rotate(0deg); }
    50% { transform: translate(-30px, 30px) rotate(-10deg); }
}

/* Header */
.header {
    background: rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(10px);
    border-bottom: 2px solid rgba(255, 255, 255, 0.2);
    padding: 20px 40px;
    position: relative;
    z-index: 10;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
}

.header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.header-left h1 {
    font-size: 2rem;
    font-weight: 900;
    margin: 0;
    text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
}

.header-subtitle {
    font-size: 1.2rem;
    font-weight: 600;
    opacity: 0.9;
    margin-top: 5px;
}

.header-right {
    text-align: right;
}

.live-date {
    font-size: 1rem;
    font-weight: 600;
    opacity: 0.9;
}

.live-clock {
    font-size: 2.5rem;
    font-weight: 900;
    margin-top: 5px;
    text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
}

/* Main Content */
.main-content {
    flex: 1;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 30px;
    padding: 30px 40px;
    position: relative;
    z-index: 1;
    overflow: hidden;
}

/* Left Panel - Calling Now */
.panel-calling {
    background: rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(15px);
    border-radius: 30px;
    padding: 40px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    text-align: center;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
    border: 2px solid rgba(255, 255, 255, 0.2);
    position: relative;
    overflow: hidden;
}

.panel-calling::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
    animation: rotate 20s linear infinite;
}

@keyframes rotate {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

.calling-label {
    font-size: 1.5rem;
    font-weight: 700;
    opacity: 0.9;
    margin-bottom: 20px;
    position: relative;
    z-index: 1;
}

.badge-new-call {
    display: inline-block;
    background: #ef4444;
    color: white;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.9rem;
    margin-left: 10px;
    animation: blinkBadge 1.5s ease-in-out infinite;
}

@keyframes blinkBadge {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

.calling-time {
    font-size: 1rem;
    font-weight: 600;
    opacity: 0.8;
    margin-top: 15px;
    position: relative;
    z-index: 1;
}

.calling-number {
    font-size: 8rem;
    font-weight: 900;
    color: #fbbf24;
    text-shadow: 4px 4px 8px rgba(0, 0, 0, 0.5);
    margin: 20px 0;
    position: relative;
    z-index: 1;
    animation: pulse 2s ease-in-out infinite;
}

@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}

.calling-name {
    font-size: 2rem;
    font-weight: 700;
    margin: 15px 0;
    position: relative;
    z-index: 1;
}

.calling-poli {
    font-size: 1.3rem;
    font-weight: 600;
    opacity: 0.9;
    position: relative;
    z-index: 1;
}

.no-calling {
    font-size: 6rem;
    color: rgba(255, 255, 255, 0.3);
    font-weight: 900;
}

.no-calling-text {
    font-size: 1.5rem;
    opacity: 0.5;
    margin-top: 20px;
}

/* Right Panel - Queue List */
.panel-queue {
    background: rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(15px);
    border-radius: 30px;
    padding: 30px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
    border: 2px solid rgba(255, 255, 255, 0.2);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.queue-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid rgba(255, 255, 255, 0.2);
}

.queue-title {
    font-size: 1.5rem;
    font-weight: 800;
}

.queue-stats {
    display: flex;
    gap: 15px;
}

.stat-badge {
    background: rgba(255, 255, 255, 0.2);
    padding: 5px 12px;
    border-radius: 15px;
    font-size: 0.9rem;
    font-weight: 700;
}

.queue-list {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    position: relative;
    padding-right: 10px;
}

.queue-list::-webkit-scrollbar {
    width: 8px;
}

.queue-list::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 10px;
}

.queue-list::-webkit-scrollbar-thumb {
    background: rgba(255, 255, 255, 0.3);
    border-radius: 10px;
}

.queue-list::-webkit-scrollbar-thumb:hover {
    background: rgba(255, 255, 255, 0.5);
}

.queue-item {
    display: grid;
    grid-template-columns: 0.6fr 1.3fr 1fr;
    gap: 15px;
    align-items: center;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 15px;
    padding: 15px 20px;
    margin-bottom: 12px;
    transition: all 0.3s ease;
    border-left: 4px solid transparent;
}

.queue-item.active {
    background: rgba(251, 191, 36, 0.3);
    border-left-color: #fbbf24;
    animation: blink 2s ease-in-out infinite;
}

@keyframes blink {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}

.queue-item.done {
    opacity: 0.5;
    background: rgba(16, 185, 129, 0.2);
    border-left-color: #10b981;
}

.queue-number {
    font-size: 1.8rem;
    font-weight: 900;
    color: #fbbf24;
}

.queue-name {
    font-size: 1.3rem;
    font-weight: 600;
}

.queue-poli {
    font-size: 1.1rem;
    opacity: 0.9;
    text-align: right;
}

.queue-empty {
    text-align: center;
    padding: 60px 20px;
    opacity: 0.5;
}

.queue-empty i {
    font-size: 4rem;
    margin-bottom: 20px;
    opacity: 0.3;
}

/* Footer */
.footer {
    background: rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(10px);
    border-top: 2px solid rgba(255, 255, 255, 0.2);
    padding: 15px 40px;
    text-align: center;
    font-size: 1rem;
    font-weight: 600;
    position: relative;
    z-index: 10;
}

/* Calling Alert Animation */
@keyframes callAlert {
    0%, 100% {
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        border-color: rgba(255, 255, 255, 0.2);
    }
    50% {
        box-shadow: 0 8px 32px rgba(251, 191, 36, 0.8), 0 0 60px rgba(251, 191, 36, 0.6);
        border-color: #fbbf24;
    }
}

.panel-calling.alert-calling {
    animation: callAlert 1.5s ease-in-out 3;
}
</style>
</head>
<body>

<div class="bg-animated"></div>

<!-- Header -->
<div class="header">
    <div class="header-content">
        <div class="header-left">
            <h1><i class="bi bi-tv-fill"></i> DISPLAY ANTRIAN POLIKLINIK</h1>
            <div class="header-subtitle">
                <i class="bi bi-hospital"></i> 
                <?php 
                $subtitle = $layani['nm_poli'] ?? ($data[0]['nm_poli'] ?? 'Semua Poliklinik');
                if (!empty($nama_dokter)) {
                    $subtitle .= ' - ' . $nama_dokter;
                }
                echo htmlspecialchars($subtitle);
                ?>
            </div>
        </div>
        <div class="header-right">
            <div class="live-date" id="liveDate"></div>
            <div class="live-clock" id="liveClock"></div>
        </div>
    </div>
</div>

<!-- Main Content -->
<div class="main-content">
    
    <!-- Left Panel - Calling Now -->
    <div class="panel-calling" id="panelCalling">
        <?php if ($current_call): ?>
            <?php 
            // Cek apakah panggilan masih baru (kurang dari 30 detik)
            $is_new = isset($current_call['timestamp']) && (time() - $current_call['timestamp']) < 30;
            ?>
            <div class="calling-label">
                <i class="bi bi-megaphone-fill"></i> NOMOR ANTRIAN DIPANGGIL
                <?php if ($is_new): ?>
                <span class="badge-new-call">🔴 BARU</span>
                <?php endif; ?>
            </div>
            <div class="calling-number" id="callingNumber">
                <?= htmlspecialchars($current_call['no_antrian']) ?>
            </div>
            <div class="calling-name">
                <?= htmlspecialchars(sensorNama($current_call['nm_pasien'])) ?>
            </div>
            <div class="calling-poli">
                <i class="bi bi-geo-alt-fill"></i> Menuju <?= htmlspecialchars($current_call['nm_poli']) ?>
            </div>
            <?php if (isset($current_call['datetime'])): ?>
            <div class="calling-time">
                <i class="bi bi-clock-fill"></i> Dipanggil: <?= date('H:i:s', strtotime($current_call['datetime'])) ?> WIB
            </div>
            <?php endif; ?>
        <?php elseif ($layani): ?>
            <div class="calling-label">
                <i class="bi bi-person-check-fill"></i> SEDANG DILAYANI
            </div>
            <div class="calling-number">
                <?= htmlspecialchars($layani['kd_poli'].'-'.str_pad($layani['no_reg'], 2, '0', STR_PAD_LEFT)) ?>
            </div>
            <div class="calling-name">
                <?= htmlspecialchars(sensorNama($layani['nm_pasien'])) ?>
            </div>
            <div class="calling-poli">
                <i class="bi bi-hospital"></i> <?= htmlspecialchars($layani['nm_poli']) ?>
            </div>
        <?php else: ?>
            <div class="no-calling">
                <i class="bi bi-hourglass-split"></i>
            </div>
            <div class="no-calling-text">Menunggu Panggilan</div>
        <?php endif; ?>
    </div>
    
    <!-- Right Panel - Queue List -->
    <div class="panel-queue">
        <div class="queue-header">
            <div class="queue-title">
                <i class="bi bi-list-ol"></i> Daftar Antrian
            </div>
            <div class="queue-stats">
                <div class="stat-badge">
                    <i class="bi bi-people-fill"></i> Total: <?= $total ?>
                </div>
                <div class="stat-badge">
                    <i class="bi bi-check-circle-fill"></i> Selesai: <?= $sudah ?>
                </div>
                <div class="stat-badge">
                    <i class="bi bi-clock-history"></i> Tunggu: <?= $menunggu ?>
                </div>
            </div>
        </div>
        
        <div class="queue-list">
            <?php if (count($data) > 0): ?>
                <?php 
                // PERBAIKAN: Tidak menggandakan data lagi
                foreach ($data as $row): 
                    $no_antrian = $row['kd_poli'].'-'.str_pad($row['no_reg'], 2, '0', STR_PAD_LEFT);
                    $is_active = $current_call && $current_call['no_rawat'] == $row['no_rawat'];
                    $is_done = $row['stts'] == 'Sudah';
                    $class = $is_active ? 'active' : ($is_done ? 'done' : '');
                ?>
                <div class="queue-item <?= $class ?>" data-no-rawat="<?= htmlspecialchars($row['no_rawat']) ?>">
                    <div class="queue-number"><?= htmlspecialchars($no_antrian) ?></div>
                    <div class="queue-name"><?= htmlspecialchars(sensorNama($row['nm_pasien'])) ?></div>
                    <div class="queue-poli"><?= htmlspecialchars($row['nm_poli']) ?></div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="queue-empty">
                    <i class="bi bi-inbox"></i>
                    <div>Belum ada antrian hari ini</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
</div>

<!-- Footer -->
<div class="footer">
    <i class="bi bi-hospital-fill"></i> MediFix - Sistem Antrian Poliklinik | 
    <i class="bi bi-calendar-check"></i> <?= date('d F Y') ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Update Clock & Date
function updateClock() {
    const now = new Date();
    const time = now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const date = now.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    
    document.getElementById('liveClock').textContent = time;
    document.getElementById('liveDate').textContent = date;
}

setInterval(updateClock, 1000);
updateClock();

// Auto refresh setiap 5 detik untuk cek panggilan baru
let lastCallNumber = '<?= $current_call ? $current_call['no_antrian'] : "" ?>';

setInterval(() => {
    fetch(window.location.href)
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            
            // Update panel calling
            const newCallingPanel = doc.querySelector('#panelCalling');
            const currentCallingPanel = document.querySelector('#panelCalling');
            
            if (newCallingPanel && currentCallingPanel) {
                const newContent = newCallingPanel.innerHTML;
                const currentContent = currentCallingPanel.innerHTML;
                
                // Cek nomor yang dipanggil
                const newNumberEl = doc.querySelector('#callingNumber');
                const newNumber = newNumberEl ? newNumberEl.textContent.trim() : '';
                
                // Hanya update dan animate jika nomor BERUBAH
                if (newNumber && newNumber !== lastCallNumber) {
                    currentCallingPanel.innerHTML = newContent;
                    lastCallNumber = newNumber;
                    
                    // Trigger alert animation untuk nomor baru
                    currentCallingPanel.classList.add('alert-calling');
                    setTimeout(() => {
                        currentCallingPanel.classList.remove('alert-calling');
                    }, 4500);
                    
                    // Play sound notification
                    playNotificationSound();
                    
                    console.log('Nomor baru dipanggil:', newNumber);
                } else if (newContent !== currentContent) {
                    // Update konten tanpa animasi (untuk perubahan kecil seperti waktu)
                    currentCallingPanel.innerHTML = newContent;
                }
            }
            
            // Update queue list active status
            const queueItems = document.querySelectorAll('.queue-item');
            const newQueueItems = doc.querySelectorAll('.queue-item');
            
            queueItems.forEach((item, index) => {
                if (newQueueItems[index]) {
                    item.className = newQueueItems[index].className;
                }
            });
        })
        .catch(error => console.log('Refresh error:', error));
}, 5000);

// Play notification sound
function playNotificationSound() {
    // Create beep sound using Web Audio API
    const audioContext = new (window.AudioContext || window.webkitAudioContext)();
    const oscillator = audioContext.createOscillator();
    const gainNode = audioContext.createGain();
    
    oscillator.connect(gainNode);
    gainNode.connect(audioContext.destination);
    
    oscillator.frequency.value = 800;
    oscillator.type = 'sine';
    
    gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
    gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.5);
    
    oscillator.start(audioContext.currentTime);
    oscillator.stop(audioContext.currentTime + 0.5);
}

// Full page reload setiap 60 detik untuk refresh data lengkap
setInterval(() => {
    location.reload();
}, 60000);
</script>
</body>
</html>