<?php
session_start();
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

$today = date('Y-m-d');

try {
    // Ambil data terakhir yang statusnya "Dipanggil"
    $stmt = $pdo_simrs->prepare("
        SELECT a.*, l.nama_loket 
        FROM antrian_wira a
        LEFT JOIN loket_admisi_wira l ON a.loket_id = l.id
        WHERE DATE(a.created_at) = ? AND a.status = 'Dipanggil'
        ORDER BY a.waktu_panggil DESC LIMIT 1
    ");
    $stmt->execute([$today]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);

    // Statistik
    $stmt2 = $pdo_simrs->prepare("SELECT COUNT(*) FROM antrian_wira WHERE DATE(created_at) = ?");
    $stmt2->execute([$today]);
    $total = $stmt2->fetchColumn();

    $stmt3 = $pdo_simrs->prepare("SELECT COUNT(*) FROM antrian_wira WHERE DATE(created_at) = ? AND status='Menunggu'");
    $stmt3->execute([$today]);
    $menunggu = $stmt3->fetchColumn();

} catch (PDOException $e) {
    die("Gagal mengambil data: " . $e->getMessage());
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Display Antrian Admisi - RS Permata Hati</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Inter', sans-serif;
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
    overflow: hidden;
    height: 100vh;
}

/* Animated Background Particles */
.particles {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    overflow: hidden;
    z-index: 0;
}

.particle {
    position: absolute;
    background: rgba(255, 255, 255, 0.03);
    border-radius: 50%;
    animation: float linear infinite;
}

@keyframes float {
    0% {
        transform: translateY(100vh) scale(0);
        opacity: 0;
    }
    10% {
        opacity: 1;
    }
    90% {
        opacity: 1;
    }
    100% {
        transform: translateY(-100vh) scale(1);
        opacity: 0;
    }
}

/* Header */
.header {
    position: relative;
    z-index: 10;
    background: linear-gradient(135deg, #0ea5e9, #06b6d4, #0891b2);
    padding: 20px 40px;
    box-shadow: 0 8px 32px rgba(14, 165, 233, 0.3);
}

.header-content {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.logo-section {
    display: flex;
    align-items: center;
    gap: 20px;
}

.logo {
    width: 70px;
    height: 70px;
    background: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
}

.logo i {
    font-size: 36px;
    background: linear-gradient(135deg, #0ea5e9, #06b6d4);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.header-text h1 {
    font-size: 32px;
    font-weight: 900;
    color: white;
    margin: 0;
    text-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
    letter-spacing: -0.5px;
}

.header-text p {
    font-size: 16px;
    color: rgba(255, 255, 255, 0.9);
    margin: 0;
    font-weight: 600;
}

.datetime {
    background: rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(10px);
    padding: 12px 24px;
    border-radius: 50px;
    border: 2px solid rgba(255, 255, 255, 0.2);
}

.datetime-item {
    color: white;
    font-weight: 700;
    font-size: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.datetime-item i {
    font-size: 18px;
}

/* Main Layout */
.main-layout {
    position: relative;
    z-index: 5;
    display: grid;
    grid-template-columns: 1.4fr 1fr;
    gap: 30px;
    padding: 30px 40px;
    height: calc(100vh - 200px);
}

/* Video Panel */
.video-panel {
    background: rgba(255, 255, 255, 0.05);
    backdrop-filter: blur(10px);
    border-radius: 30px;
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, 0.1);
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    position: relative;
}

.video-panel::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #0ea5e9, #06b6d4, #0891b2);
}

.video-panel iframe {
    width: 100%;
    height: 100%;
    border: none;
}

/* Queue Panel */
.queue-panel {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

/* Current Queue Card */
.current-queue {
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05));
    backdrop-filter: blur(20px);
    border-radius: 30px;
    padding: 40px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    text-align: center;
    position: relative;
    overflow: hidden;
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.current-queue::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #0ea5e9, #06b6d4, #0891b2);
}

.queue-label {
    font-size: 18px;
    font-weight: 700;
    color: rgba(255, 255, 255, 0.7);
    text-transform: uppercase;
    letter-spacing: 2px;
    margin-bottom: 20px;
}

.queue-number {
    font-size: 120px;
    font-weight: 900;
    background: linear-gradient(135deg, #0ea5e9, #38bdf8, #7dd3fc);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    line-height: 1;
    margin-bottom: 30px;
    text-shadow: 0 10px 30px rgba(14, 165, 233, 0.5);
    animation: pulse 2s ease-in-out infinite;
}

@keyframes pulse {
    0%, 100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.05);
    }
}

.queue-loket {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: white;
    padding: 16px 32px;
    border-radius: 50px;
    font-size: 28px;
    font-weight: 800;
    display: inline-block;
    box-shadow: 0 8px 24px rgba(245, 158, 11, 0.4);
    margin: 0 auto;
}

.no-queue {
    color: rgba(255, 255, 255, 0.3);
}

/* Stats Cards */
.stats-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.stat-card {
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05));
    backdrop-filter: blur(20px);
    border-radius: 20px;
    padding: 30px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
    position: relative;
    overflow: hidden;
    transition: all 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.3);
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
}

.stat-card.total::before {
    background: linear-gradient(90deg, #3b82f6, #2563eb);
}

.stat-card.waiting::before {
    background: linear-gradient(90deg, #f59e0b, #d97706);
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 16px;
    font-size: 28px;
}

.stat-card.total .stat-icon {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    box-shadow: 0 8px 24px rgba(59, 130, 246, 0.4);
}

.stat-card.waiting .stat-icon {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: white;
    box-shadow: 0 8px 24px rgba(245, 158, 11, 0.4);
}

.stat-label {
    font-size: 14px;
    font-weight: 700;
    color: rgba(255, 255, 255, 0.7);
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 8px;
}

.stat-value {
    font-size: 48px;
    font-weight: 900;
    color: white;
    line-height: 1;
}

/* Footer */
.footer {
    position: relative;
    z-index: 10;
    background: linear-gradient(135deg, #0ea5e9, #06b6d4, #0891b2);
    padding: 16px 40px;
    box-shadow: 0 -8px 32px rgba(14, 165, 233, 0.3);
    overflow: hidden;
}

.marquee-container {
    overflow: hidden;
    white-space: nowrap;
}

.marquee {
    display: inline-block;
    animation: marquee 30s linear infinite;
    color: white;
    font-size: 18px;
    font-weight: 700;
    letter-spacing: 0.5px;
}

@keyframes marquee {
    0% {
        transform: translateX(100%);
    }
    100% {
        transform: translateX(-100%);
    }
}

.marquee i {
    margin: 0 10px;
    color: #fbbf24;
}

/* Responsive */
@media (max-width: 1400px) {
    .queue-number {
        font-size: 100px;
    }
    
    .stat-value {
        font-size: 40px;
    }
}

@media (max-width: 1200px) {
    .main-layout {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .queue-panel {
        flex-direction: row;
    }
    
    .current-queue {
        flex: 1.5;
    }
    
    .stats-grid {
        flex: 1;
    }
}

@media (max-width: 768px) {
    .header-content {
        flex-direction: column;
        text-align: center;
        gap: 15px;
    }
    
    .queue-number {
        font-size: 80px;
    }
    
    .queue-loket {
        font-size: 22px;
    }
}
</style>
</head>
<body>

<!-- Animated Particles Background -->
<div class="particles" id="particles"></div>

<!-- Header -->
<div class="header">
    <div class="header-content">
        <div class="logo-section">
            <div class="logo">
                <i class="bi bi-hospital-fill"></i>
            </div>
            <div class="header-text">
                <h1>DISPLAY ANTRIAN ADMISI</h1>
                <p>RS Permata Hati - Melayani Dengan Sepenuh Hati</p>
            </div>
        </div>
        
        <div class="datetime">
            <div class="datetime-item">
                <i class="bi bi-clock-fill"></i>
                <span id="timeDisplay"></span>
            </div>
        </div>
    </div>
</div>

<!-- Main Layout -->
<div class="main-layout">
    
    <!-- Video Panel -->
    <div class="video-panel">
        <iframe 
            src="https://www.youtube.com/embed/9NfAMjbfH5o?autoplay=1&mute=1&loop=1&playlist=9NfAMjbfH5o&controls=0&showinfo=0&rel=0"
            title="Video Promosi RS Permata Hati"
            allow="autoplay; fullscreen; picture-in-picture"
            allowfullscreen>
        </iframe>
    </div>
    
    <!-- Queue Panel -->
    <div class="queue-panel">
        
        <!-- Current Queue -->
        <div class="current-queue" id="queueDisplay">
            <?php if ($current): ?>
                <div class="queue-label">Nomor Antrian</div>
                <div class="queue-number"><?= htmlspecialchars($current['nomor']) ?></div>
                <div class="queue-loket">
                    <i class="bi bi-arrow-right-circle-fill"></i>
                    <?= htmlspecialchars($current['nama_loket'] ?? 'Loket Tidak Dikenal') ?>
                </div>
            <?php else: ?>
                <div class="queue-label">Nomor Antrian</div>
                <div class="queue-number no-queue">---</div>
                <div class="queue-loket" style="background: rgba(255,255,255,0.1);">
                    <i class="bi bi-hourglass-split"></i>
                    Menunggu Panggilan
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-icon">
                    <i class="bi bi-list-ol"></i>
                </div>
                <div class="stat-label">Total Antrian</div>
                <div class="stat-value" id="totalCount"><?= $total ?></div>
            </div>
            
            <div class="stat-card waiting">
                <div class="stat-icon">
                    <i class="bi bi-hourglass-split"></i>
                </div>
                <div class="stat-label">Menunggu</div>
                <div class="stat-value" id="waitingCount"><?= $menunggu ?></div>
            </div>
        </div>
        
    </div>
</div>

<!-- Footer -->
<div class="footer">
    <div class="marquee-container">
        <div class="marquee">
            <i class="bi bi-megaphone-fill"></i>
            Selamat datang di RS Permata Hati
            <i class="bi bi-heart-pulse-fill"></i>
            Harap menunggu panggilan dengan tertib
            <i class="bi bi-clock-history"></i>
            Total Antrian Hari Ini: <span id="footerTotal"><?= $total ?></span>
            <i class="bi bi-hourglass-bottom"></i>
            Menunggu: <span id="footerWaiting"><?= $menunggu ?></span>
            <i class="bi bi-calendar-check"></i>
            <?= date('d F Y') ?>
        </div>
    </div>
</div>

<script>
// ===== Create Particles =====
function createParticles() {
    const container = document.getElementById('particles');
    const particleCount = 30;
    
    for (let i = 0; i < particleCount; i++) {
        const particle = document.createElement('div');
        particle.className = 'particle';
        
        const size = Math.random() * 100 + 50;
        particle.style.width = size + 'px';
        particle.style.height = size + 'px';
        particle.style.left = Math.random() * 100 + '%';
        particle.style.animationDuration = (Math.random() * 20 + 15) + 's';
        particle.style.animationDelay = Math.random() * 5 + 's';
        
        container.appendChild(particle);
    }
}

createParticles();

// ===== Update Time =====
function updateTime() {
    const now = new Date();
    const timeStr = now.toLocaleTimeString('id-ID', { 
        hour: '2-digit', 
        minute: '2-digit',
        second: '2-digit'
    });
    document.getElementById('timeDisplay').textContent = timeStr;
}

setInterval(updateTime, 1000);
updateTime();

// ===== Auto Refresh Queue Data =====
let lastNomor = '<?= $current['nomor'] ?? '' ?>';

setInterval(() => {
    fetch('get_antrian.php')
        .then(res => res.json())
        .then(data => {
            // Update display
            const queueDisplay = document.getElementById('queueDisplay');
            
            if (data.nomor) {
                queueDisplay.innerHTML = `
                    <div class="queue-label">Nomor Antrian</div>
                    <div class="queue-number">${data.nomor}</div>
                    <div class="queue-loket">
                        <i class="bi bi-arrow-right-circle-fill"></i>
                        ${data.loket}
                    </div>
                `;
                
                // Deteksi panggilan baru
                if (data.nomor !== lastNomor && lastNomor !== '') {
                    playSound(data.nomor, data.loket);
                }
                lastNomor = data.nomor;
            } else {
                queueDisplay.innerHTML = `
                    <div class="queue-label">Nomor Antrian</div>
                    <div class="queue-number no-queue">---</div>
                    <div class="queue-loket" style="background: rgba(255,255,255,0.1);">
                        <i class="bi bi-hourglass-split"></i>
                        Menunggu Panggilan
                    </div>
                `;
            }
            
            // Update stats
            document.getElementById('totalCount').textContent = data.total || 0;
            document.getElementById('waitingCount').textContent = data.menunggu || 0;
            document.getElementById('footerTotal').textContent = data.total || 0;
            document.getElementById('footerWaiting').textContent = data.menunggu || 0;
        })
        .catch(err => console.error('Error fetching data:', err));
}, 3000);

// ===== Sound Functions =====
function playSound(nomor, loket) {
    const sounds = [
        'sound/opening.mp3',
        'sound/nomor antrian.mp3'
    ];

    const huruf = nomor.charAt(0);
    const angka = nomor.substring(1);

    sounds.push('sound/' + huruf + '.mp3');
    const angkaSounds = angkaToSound(angka);
    sounds.push(...angkaSounds);
    sounds.push('sound/silahkan menuju loket.mp3');
    
    // Extract loket number
    const loketMatch = loket.match(/\d+/);
    if (loketMatch) {
        const loketNum = loketMatch[0];
        const loketSounds = angkaToSound(loketNum);
        sounds.push(...loketSounds);
    }

    playSequentialAudio(sounds);
}

function angkaToSound(angka) {
    const files = {
        '0': 'nol.mp3',
        '1': 'satu.mp3',
        '2': 'dua.mp3',
        '3': 'tiga.mp3',
        '4': 'empat.mp3',
        '5': 'lima.mp3',
        '6': 'enam.mp3',
        '7': 'tujuh.mp3',
        '8': 'delapan.mp3',
        '9': 'sembilan.mp3',
        '10': 'sepuluh.mp3',
        '11': 'sebelas.mp3'
    };
    const arr = [];
    let n = parseInt(angka);

    if (n <= 11) {
        arr.push('sound/' + files[n]);
    } else if (n < 20) {
        arr.push('sound/' + files[n - 10]);
        arr.push('sound/belas.mp3');
    } else if (n < 100) {
        const puluh = Math.floor(n / 10);
        const satuan = n % 10;
        arr.push('sound/' + files[puluh]);
        arr.push('sound/puluh.mp3');
        if (satuan > 0) arr.push('sound/' + files[satuan]);
    } else if (n < 1000) {
        const ratus = Math.floor(n / 100);
        const sisa = n % 100;
        if (ratus === 1) {
            arr.push('sound/seratus.mp3');
        } else {
            arr.push('sound/' + files[ratus]);
            arr.push('sound/ratus.mp3');
        }
        if (sisa > 0) arr.push(...angkaToSound(sisa.toString()));
    }
    return arr;
}

function playSequentialAudio(sources) {
    if (sources.length === 0) return;
    const audio = new Audio(sources[0]);
    audio.addEventListener('ended', function() {
        playSequentialAudio(sources.slice(1));
    });
    audio.play().catch(err => {
        console.warn('Autoplay blocked. Click screen to enable sound.');
    });
}

// Enable sound on first user interaction
document.addEventListener('click', function enableSound() {
    const audio = new Audio('sound/opening.mp3');
    audio.volume = 0;
    audio.play().then(() => {
        console.log('Sound enabled');
    }).catch(() => {
        console.log('Sound still blocked');
    });
    document.removeEventListener('click', enableSound);
}, { once: true });
</script>

</body>
</html>