<?php
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

try {
    // 🔹 Daftar KODE KAMAR yang tidak ingin ditampilkan
    $excludedKamar = [

    ];

    // 🔹 (Opsional) Daftar KODE BANGSAL yang mau disembunyikan seluruhnya
    $excludedBangsal = [
       'B0213', 'K302','B0114','B0115','B0112','B0113','RR01','RR02','RR03','RR04','B0219'
      ,'B0073','VK1','VK2','OM','OK1','OK2','OK3','OK4','B0081','B0082','B0083','B0084','P001'
      ,'B0096','K019','K020','K021','B0102','ISOC1','K308','M9B','NICU','B0100','B0212','TES','B0118'
    ];

    // 🔹 Normalisasi ke huruf besar & hapus spasi
    $excludedKamar = array_map(fn($v) => strtoupper(trim($v)), $excludedKamar);
    $excludedBangsal = array_map(fn($v) => strtoupper(trim($v)), $excludedBangsal);

    // 🔹 Siapkan untuk query SQL
    $excludedKamarList = "'" . implode("','", $excludedKamar) . "'";
    $excludedBangsalList = !empty($excludedBangsal)
        ? "'" . implode("','", $excludedBangsal) . "'"
        : '';

    // 🔹 Query utama
    $sql = "
        SELECT 
            kamar.kd_kamar, 
            bangsal.nm_bangsal, 
            kamar.kelas, 
            kamar.status 
        FROM kamar 
        INNER JOIN bangsal ON kamar.kd_bangsal = bangsal.kd_bangsal 
        WHERE kamar.status IN ('KOSONG', 'ISI')
          AND UPPER(TRIM(kamar.kd_kamar)) NOT IN ($excludedKamarList)
    ";

    // 🔹 Jika ada pengecualian berdasarkan bangsal
    if (!empty($excludedBangsalList)) {
        $sql .= " AND UPPER(TRIM(kamar.kd_bangsal)) NOT IN ($excludedBangsalList)";
    }

    $sql .= " ORDER BY kamar.kelas, bangsal.nm_bangsal, kamar.kd_kamar";

    // 🔹 Jalankan query
    $stmt = $pdo_simrs->query($sql);
    $kamar = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 🔹 Hitung total
    $rekap = [];
    $totalIsi = 0; 
    $totalKosong = 0;
    foreach ($kamar as $k) {
        $kelas = $k['kelas'];
        $status = $k['status'];
        if (!isset($rekap[$kelas])) {
            $rekap[$kelas] = ['ISI' => 0, 'KOSONG' => 0];
        }
        $rekap[$kelas][$status]++;
        if ($status == 'ISI') $totalIsi++;
        if ($status == 'KOSONG') $totalKosong++;
    }
} catch (PDOException $e) {
    die('Terjadi kesalahan: ' . $e->getMessage());
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ketersediaan Kamar - RS Permata Hati</title>
<link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

:root {
    --primary: #00d4aa;
    --secondary: #0088ff;
    --success: #00e676;
    --danger: #ff5252;
    --dark: #0a1929;
    --light: #f8fafb;
    --card-bg: rgba(255, 255, 255, 0.98);
    --shadow: rgba(10, 25, 41, 0.08);
}

body {
    font-family: 'DM Sans', -apple-system, sans-serif;
    background: linear-gradient(160deg, #0a1929 0%, #132f4c 50%, #1e4976 100%);
    min-height: 100vh;
    overflow: hidden;
    position: relative;
}

/* Decorative Background Elements */
body::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 80%;
    height: 80%;
    background: radial-gradient(circle, rgba(0, 212, 170, 0.15) 0%, transparent 70%);
    border-radius: 50%;
    animation: pulse 15s ease-in-out infinite;
}

body::after {
    content: '';
    position: absolute;
    bottom: -30%;
    left: -10%;
    width: 60%;
    height: 60%;
    background: radial-gradient(circle, rgba(0, 136, 255, 0.12) 0%, transparent 70%);
    border-radius: 50%;
    animation: pulse 12s ease-in-out infinite 2s;
}

@keyframes pulse {
    0%, 100% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.1); opacity: 0.8; }
}

/* Header */
.header {
    position: relative;
    z-index: 10;
    background: rgba(10, 25, 41, 0.95);
    backdrop-filter: blur(20px);
    border-bottom: 3px solid var(--primary);
    padding: 1.8vh 3vw;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 4px 30px rgba(0, 212, 170, 0.2);
}

.brand-section {
    display: flex;
    align-items: center;
    gap: 1.5vw;
}

.brand-icon {
    width: 5vw;
    height: 5vw;
    min-width: 60px;
    min-height: 60px;
    max-width: 90px;
    max-height: 90px;
    background: linear-gradient(135deg, var(--primary) 0%, #00aa88 100%);
    border-radius: 1.2vw;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 8px 24px rgba(0, 212, 170, 0.4);
    position: relative;
}

.brand-icon::after {
    content: '';
    position: absolute;
    inset: -3px;
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    border-radius: 1.3vw;
    z-index: -1;
    opacity: 0.3;
    filter: blur(8px);
}

.brand-icon i {
    font-size: 2.8vw;
    color: white;
}

.brand-text h1 {
    font-family: 'Archivo Black', sans-serif;
    font-size: 2.8vw;
    color: white;
    margin: 0;
    line-height: 1;
    text-transform: uppercase;
    letter-spacing: -0.02em;
    background: linear-gradient(135deg, #ffffff 0%, var(--primary) 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.brand-text p {
    font-size: 1.2vw;
    color: rgba(255, 255, 255, 0.7);
    margin: 0.3vh 0 0 0;
    font-weight: 600;
    letter-spacing: 0.05em;
}

.header-info {
    text-align: right;
}

.live-time {
    font-family: 'Archivo Black', sans-serif;
    font-size: 3.5vw;
    color: var(--primary);
    font-variant-numeric: tabular-nums;
    line-height: 1;
    text-shadow: 0 0 30px rgba(0, 212, 170, 0.6);
    letter-spacing: -0.02em;
}

.live-date {
    font-size: 1.2vw;
    color: rgba(255, 255, 255, 0.8);
    font-weight: 600;
    margin-top: 0.5vh;
    letter-spacing: 0.03em;
}

/* Stats Bar */
.stats-bar {
    position: relative;
    z-index: 10;
    background: rgba(255, 255, 255, 0.08);
    backdrop-filter: blur(10px);
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    padding: 1.5vh 3vw;
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 2vw;
}

.stat-item {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 1vw;
    padding: 1vh 1.5vw;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 0.8vw;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.stat-icon {
    width: 3vw;
    height: 3vw;
    min-width: 40px;
    min-height: 40px;
    border-radius: 0.6vw;
    display: flex;
    align-items: center;
    justify-content: center;
}

.stat-icon.total {
    background: linear-gradient(135deg, var(--secondary), #0066cc);
}

.stat-icon.available {
    background: linear-gradient(135deg, var(--success), #00c853);
}

.stat-icon.occupied {
    background: linear-gradient(135deg, var(--danger), #d32f2f);
}

.stat-icon i {
    font-size: 1.5vw;
    color: white;
}

.stat-info {
    flex: 1;
    text-align: left;
}

.stat-label {
    font-size: 0.9vw;
    color: rgba(255, 255, 255, 0.6);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 0.2vh;
}

.stat-value {
    font-family: 'Archivo Black', sans-serif;
    font-size: 2.2vw;
    color: white;
    line-height: 1;
}

/* Main Content */
.main-content {
    position: relative;
    z-index: 10;
    padding: 2vh 3vw;
    height: calc(100vh - 20vh);
}

.rooms-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 1.5vw;
    height: 100%;
}

.room-card {
    background: var(--card-bg);
    border-radius: 1.2vw;
    padding: 1.8vh 1.2vw;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 4px 20px var(--shadow);
    border: 2px solid transparent;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}

.room-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 0.4vh;
    background: var(--room-accent);
}

.room-card.available {
    --room-accent: var(--success);
    border-color: rgba(0, 230, 118, 0.2);
}

.room-card.available:hover {
    transform: translateY(-0.5vh);
    box-shadow: 0 8px 32px rgba(0, 230, 118, 0.25);
    border-color: var(--success);
}

.room-card.occupied {
    --room-accent: var(--danger);
    border-color: rgba(255, 82, 82, 0.2);
}

.room-card.occupied:hover {
    transform: translateY(-0.5vh);
    box-shadow: 0 8px 32px rgba(255, 82, 82, 0.25);
    border-color: var(--danger);
}

.bed-icon {
    width: 4vw;
    height: 4vw;
    min-width: 50px;
    min-height: 50px;
    background: linear-gradient(135deg, var(--room-accent), var(--room-accent));
    border-radius: 1vw;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 1.2vh;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
}

.bed-icon svg {
    width: 60%;
    height: 60%;
    fill: white;
}

.room-name {
    font-size: 1.1vw;
    font-weight: 700;
    color: var(--dark);
    text-align: center;
    margin-bottom: 1vh;
    line-height: 1.3;
    min-height: 3vh;
    display: flex;
    align-items: center;
    justify-content: center;
}

.room-class {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.6vh 1.2vw;
    border-radius: 0.5vw;
    font-size: 0.9vw;
    font-weight: 800;
    color: white;
    margin-bottom: 1vh;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    min-width: 5vw;
}

.class-vip { background: linear-gradient(135deg, #ff6b9d, #c44569); }
.class-1 { background: linear-gradient(135deg, var(--secondary), #0066cc); }
.class-2 { background: linear-gradient(135deg, #ffa726, #ef6c00); }
.class-3 { background: linear-gradient(135deg, #ab47bc, #7b1fa2); }

.room-status {
    font-size: 1vw;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5vw;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.room-status.available {
    color: var(--success);
}

.room-status.occupied {
    color: var(--danger);
}

.room-status i {
    font-size: 1.2vw;
}

/* Footer */
.footer {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 10;
    background: rgba(10, 25, 41, 0.95);
    backdrop-filter: blur(20px);
    border-top: 3px solid var(--primary);
    padding: 1.2vh 0;
    box-shadow: 0 -4px 30px rgba(0, 212, 170, 0.2);
    overflow: hidden;
}

.marquee-container {
    width: 100%;
    overflow: hidden;
}

.marquee-content {
    display: inline-flex;
    white-space: nowrap;
    animation: marquee 40s linear infinite;
    font-size: 1.2vw;
    font-weight: 600;
    color: white;
    gap: 3vw;
}

@keyframes marquee {
    0% { transform: translateX(0); }
    100% { transform: translateX(-50%); }
}

.marquee-item {
    display: inline-flex;
    align-items: center;
    gap: 0.8vw;
}

.marquee-item i {
    color: var(--primary);
    font-size: 1.4vw;
}

.kelas {
    color: var(--primary);
    font-weight: 800;
}

.available {
    color: var(--success);
    font-weight: 800;
}

.occupied {
    color: var(--danger);
    font-weight: 800;
}

/* Page Indicator */
.page-indicator {
    position: fixed;
    bottom: 8vh;
    right: 3vw;
    z-index: 20;
    background: rgba(10, 25, 41, 0.95);
    backdrop-filter: blur(10px);
    padding: 1.5vh 2vw;
    border-radius: 1vw;
    border: 2px solid var(--primary);
    box-shadow: 0 4px 20px rgba(0, 212, 170, 0.3);
    font-size: 1.2vw;
    font-weight: 700;
    color: white;
}

.page-indicator .current {
    color: var(--primary);
    font-family: 'Archivo Black', sans-serif;
    font-size: 1.8vw;
}

/* Smooth Fade Transition */
.rooms-grid {
    animation: fadeIn 0.6s ease-in-out;
}

@keyframes fadeIn {
    0% {
        opacity: 0;
        transform: translateY(10px);
    }
    100% {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Responsive adjustments */
@media (max-height: 800px) {
    .header {
        padding: 1.2vh 3vw;
    }
    
    .stats-bar {
        padding: 1vh 3vw;
    }
    
    .main-content {
        padding: 1.5vh 3vw;
    }
    
    .rooms-grid {
        gap: 1.2vw;
    }
    
    .room-card {
        padding: 1.5vh 1vw;
    }
}

@media (max-width: 1366px) {
    .brand-text h1 {
        font-size: 3.2vw;
    }
    
    .live-time {
        font-size: 4vw;
    }
}
</style>
</head>
<body>

<!-- Header -->
<div class="header">
    <div class="brand-section">
        <div class="brand-icon">
            <i class="bi bi-hospital"></i>
        </div>
        <div class="brand-text">
            <h1>Ketersediaan Tempat Tidur</h1>
            <p>RS Permata Hati</p>
        </div>
    </div>
    <div class="header-info">
        <div class="live-time" id="liveTime">00:00:00</div>
        <div class="live-date" id="liveDate">-</div>
    </div>
</div>



<!-- Main Content -->
<div class="main-content">
    <div class="rooms-grid" id="roomsGrid">
        <?php foreach ($kamar as $k): ?>
            <?php
                $kelasClass = 'class-3';
                if (stripos($k['kelas'], 'VIP') !== false) $kelasClass = 'class-vip';
                elseif (stripos($k['kelas'], '1') !== false) $kelasClass = 'class-1';
                elseif (stripos($k['kelas'], '2') !== false) $kelasClass = 'class-2';
                
                $statusClass = ($k['status'] == 'KOSONG') ? 'available' : 'occupied';
            ?>
            <div class="room-card <?= $statusClass ?>" data-room>
                <div class="bed-icon">
                    <svg viewBox="0 0 16 16">
                        <path d="M0 7V3a1 1 0 0 1 1-1h1a2 2 0 1 1 4 0h4a2 2 0 1 1 4 0h1a1 1 0 0 1 1 1v4H0zm0 1h16v5h-1v-2h-1v2h-1v-2H3v2H2v-2H1v2H0V8z"/>
                    </svg>
                </div>
                <div class="room-name"><?= htmlspecialchars($k['nm_bangsal']) ?></div>
                <div class="room-class <?= $kelasClass ?>"><?= htmlspecialchars($k['kelas']) ?></div>
                <div class="room-status <?= $statusClass ?>">
                    <i class="bi bi-<?= $k['status'] == 'KOSONG' ? 'check-circle-fill' : 'x-circle-fill' ?>"></i>
                    <?= $k['status'] == 'KOSONG' ? 'Tersedia' : 'Terisi' ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>



<!-- Footer -->
<div class="footer">
    <div class="marquee-container">
        <div class="marquee-content">
            <?php
            $marqueeContent = [];
            foreach ($rekap as $kelas => $jumlah) {
                $marqueeContent[] = "<span class='marquee-item'>
                    <i class='bi bi-info-circle-fill'></i>
                    <span class='kelas'>" . htmlspecialchars($kelas) . "</span>: 
                    <span class='available'>{$jumlah['KOSONG']} tersedia</span> • 
                    <span class='occupied'>{$jumlah['ISI']} terisi</span>
                    (Total: " . ($jumlah['ISI'] + $jumlah['KOSONG']) . " TT)
                </span>";
            }
            $content = implode("", $marqueeContent);
            // Duplicate content for seamless loop
            echo $content . $content;
            ?>
        </div>
    </div>
</div>

<script>
// Clock Update
function updateClock() {
    const now = new Date();
    const time = now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
    const date = now.toLocaleDateString('id-ID', options);
    
    document.getElementById('liveTime').textContent = time;
    document.getElementById('liveDate').textContent = date;
}
setInterval(updateClock, 1000);
updateClock();

// Pagination System (15 items per page)
const ITEMS_PER_PAGE = 15;
const rooms = Array.from(document.querySelectorAll('[data-room]'));
const totalRooms = rooms.length;
const totalPages = Math.ceil(totalRooms / ITEMS_PER_PAGE);
let currentPage = 0;

document.getElementById('totalPages').textContent = totalPages;

function showPage(pageIndex) {
    const start = pageIndex * ITEMS_PER_PAGE;
    const end = start + ITEMS_PER_PAGE;
    
    rooms.forEach((room, index) => {
        if (index >= start && index < end) {
            room.style.display = 'flex';
        } else {
            room.style.display = 'none';
        }
    });
    
    document.getElementById('currentPage').textContent = pageIndex + 1;
}

// Initial page display
showPage(currentPage);

// Auto slide every 5 seconds
setInterval(() => {
    currentPage = (currentPage + 1) % totalPages;
    showPage(currentPage);
}, 5000);

// Smooth auto-refresh every 30 seconds WITHOUT page flicker
let refreshTimer = setInterval(() => {
    // Fetch new data via AJAX instead of full page reload
    fetch(window.location.href)
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            
            // Update only the rooms grid content
            const newGrid = doc.querySelector('#roomsGrid');
            const currentGrid = document.querySelector('#roomsGrid');
            
            if (newGrid && currentGrid) {
                // Smooth fade transition
                currentGrid.style.opacity = '0';
                currentGrid.style.transform = 'translateY(10px)';
                
                setTimeout(() => {
                    currentGrid.innerHTML = newGrid.innerHTML;
                    
                    // Re-initialize rooms array
                    const newRooms = Array.from(document.querySelectorAll('[data-room]'));
                    rooms.length = 0;
                    rooms.push(...newRooms);
                    
                    // Show current page
                    showPage(currentPage);
                    
                    // Fade back in
                    currentGrid.style.opacity = '1';
                    currentGrid.style.transform = 'translateY(0)';
                }, 300);
            }
            
            // Update stats
            const newStatsBar = doc.querySelector('.stats-bar');
            const currentStatsBar = document.querySelector('.stats-bar');
            if (newStatsBar && currentStatsBar) {
                currentStatsBar.innerHTML = newStatsBar.innerHTML;
            }
            
            // Update marquee
            const newMarquee = doc.querySelector('.marquee-content');
            const currentMarquee = document.querySelector('.marquee-content');
            if (newMarquee && currentMarquee) {
                currentMarquee.innerHTML = newMarquee.innerHTML;
            }
        })
        .catch(error => {
            console.error('Refresh error:', error);
        });
}, 30000);
</script>

</body>
</html>