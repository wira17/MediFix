<?php
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

try {
    $excludedKamar  = [];
    $excludedBangsal = [
        'B0213','K302','B0114','B0115','B0112','B0113','RR01','RR02','RR03','RR04','B0219',
        'B0073','VK1','VK2','OM','OK1','OK2','OK3','OK4','B0081','B0082','B0083','B0084','P001',
        'B0096','K019','K020','K021','B0102','ISOC1','K308','M9B','NICU','B0100','B0212','TES','B0118'
    ];

    $excludedKamar   = array_map(fn($v) => strtoupper(trim($v)), $excludedKamar);
    $excludedBangsal = array_map(fn($v) => strtoupper(trim($v)), $excludedBangsal);

    $excludedKamarList   = "'" . implode("','", $excludedKamar ?: ['__NONE__']) . "'";
    $excludedBangsalList = "'" . implode("','", $excludedBangsal) . "'";

    $sql = "
        SELECT kamar.kd_kamar, bangsal.nm_bangsal, kamar.kelas, kamar.status
        FROM kamar
        INNER JOIN bangsal ON kamar.kd_bangsal = bangsal.kd_bangsal
        WHERE kamar.status IN ('KOSONG','ISI')
          AND UPPER(TRIM(kamar.kd_kamar))   NOT IN ($excludedKamarList)
          AND UPPER(TRIM(kamar.kd_bangsal)) NOT IN ($excludedBangsalList)
        ORDER BY kamar.kelas, bangsal.nm_bangsal, kamar.kd_kamar
    ";

    $stmt  = $pdo_simrs->query($sql);
    $kamar = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rekap = []; $totalIsi = 0; $totalKosong = 0;
    foreach ($kamar as $k) {
        $kl = $k['kelas']; $st = $k['status'];
        if (!isset($rekap[$kl])) $rekap[$kl] = ['ISI'=>0,'KOSONG'=>0];
        $rekap[$kl][$st]++;
        if ($st === 'ISI')    $totalIsi++;
        if ($st === 'KOSONG') $totalKosong++;
    }
    $totalKamar = count($kamar);

} catch (PDOException $e) {
    die('Terjadi kesalahan: ' . $e->getMessage());
}

// 20 kartu per halaman (5 kolom × 4 baris)
$perPage = 20;
$pages   = array_chunk($kamar, $perPage);
$nPages  = count($pages);
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ketersediaan Kamar — MediFix</title>
<link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
* { margin:0; padding:0; box-sizing:border-box; }

:root {
    --primary:  #00d4aa;
    --secondary:#0088ff;
    --success:  #00e676;
    --danger:   #ff5252;
    --warning:  #fbbf24;
    --dark:     #0a1929;
    --card-bg:  rgba(255,255,255,0.98);
    --shadow:   rgba(10,25,41,0.10);
}

html, body {
    height:100vh; overflow:hidden;
    font-family:'DM Sans',sans-serif;
    background:linear-gradient(160deg, #0a1929 0%, #132f4c 50%, #1e4976 100%);
    position:relative;
}
body::before {
    content:''; position:absolute; top:-50%; right:-20%;
    width:80%; height:80%;
    background:radial-gradient(circle,rgba(0,212,170,.13) 0%,transparent 70%);
    border-radius:50%; animation:bgPulse 18s ease-in-out infinite; pointer-events:none;
}
body::after {
    content:''; position:absolute; bottom:-30%; left:-15%;
    width:60%; height:60%;
    background:radial-gradient(circle,rgba(0,136,255,.10) 0%,transparent 70%);
    border-radius:50%; animation:bgPulse 22s ease-in-out infinite reverse; pointer-events:none;
}
@keyframes bgPulse { 0%,100%{transform:scale(1);opacity:1} 50%{transform:scale(1.12);opacity:.7} }

/* ===== HEADER ===== */
.header {
    position:relative; z-index:10;
    background:rgba(10,25,41,.95);
    backdrop-filter:blur(20px);
    border-bottom:3px solid var(--primary);
    padding:1.2vh 3vw;
    display:grid; grid-template-columns:auto 1fr auto;
    gap:2vw; align-items:center;
    box-shadow:0 4px 30px rgba(0,212,170,.2);
}

.brand-section { display:flex; align-items:center; gap:1.2vw; }
.brand-icon {
    width:4.5vw; height:4.5vw;
    min-width:52px; min-height:52px; max-width:72px; max-height:72px;
    background:linear-gradient(135deg,var(--primary),#00aa88);
    border-radius:1vw; display:flex; align-items:center; justify-content:center;
    box-shadow:0 8px 24px rgba(0,212,170,.4);
}
.brand-icon i { font-size:2.4vw; color:#fff; }
.brand-text h1 {
    font-family:'Archivo Black',sans-serif;
    font-size:2vw; color:#fff; margin:0; line-height:1;
    text-transform:uppercase; letter-spacing:-.02em;
    background:linear-gradient(135deg,#fff 0%,var(--primary) 100%);
    -webkit-background-clip:text; -webkit-text-fill-color:transparent;
}
.brand-text p { font-size:.9vw; color:rgba(255,255,255,.7); margin:.3vh 0 0; font-weight:600; letter-spacing:.05em; }

.header-stats { display:flex; gap:1.2vw; justify-content:center; }
.header-stat-item {
    display:flex; align-items:center; gap:.8vw;
    padding:.9vh 1.3vw;
    background:rgba(255,255,255,.08);
    border-radius:.8vw; border:1px solid rgba(255,255,255,.1);
}
.header-stat-icon {
    width:2.4vw; height:2.4vw; min-width:32px; min-height:32px;
    border-radius:.5vw; display:flex; align-items:center; justify-content:center;
}
.hsi-blue  { background:linear-gradient(135deg,var(--secondary),#0066cc); }
.hsi-green { background:linear-gradient(135deg,var(--success),#00c853); }
.hsi-red   { background:linear-gradient(135deg,var(--danger),#d32f2f); }
.header-stat-icon i  { font-size:1.2vw; color:#fff; }
.header-stat-label   { font-size:.72vw; color:rgba(255,255,255,.6); font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
.header-stat-value   { font-family:'Archivo Black',sans-serif; font-size:1.8vw; color:#fff; line-height:1; }

/* Jam + WIB */
.header-info { text-align:right; }
.live-time-wrap { display:flex; align-items:flex-end; justify-content:flex-end; gap:.5vw; line-height:1; }
.live-time {
    font-family:'Archivo Black',sans-serif; font-size:2.8vw; color:var(--primary);
    letter-spacing:-.02em; text-shadow:0 0 30px rgba(0,212,170,.6);
}
.live-tz {
    font-family:'Archivo Black',sans-serif; font-size:1vw; color:var(--primary);
    opacity:.75; margin-bottom:.35vw; letter-spacing:.05em;
}
.live-date { font-size:.9vw; color:rgba(255,255,255,.8); font-weight:600; margin-top:.3vh; }

/* ===== MAIN CONTENT ===== */
.main-content {
    position:relative; z-index:1;
    height:calc(100vh - 11vh - 9.5vh);
    padding:2vh 3vw 0;
    overflow:hidden;
}

/* ===== PAGING — opacity transition ===== */
.page-wrapper { height:100%; position:relative; }

.page-slide {
    position:absolute; inset:0; height:100%;
    opacity:0; visibility:hidden;
    transition:opacity .7s ease, visibility .7s ease;
    pointer-events:none;
}
.page-slide.active {
    opacity:1; visibility:visible;
    pointer-events:auto; position:relative;
}

/* ===== GRID KAMAR — 5 kolom × 4 baris = 20 per halaman ===== */
.kamar-grid {
    display:grid;
    grid-template-columns:repeat(5,1fr) !important;
    grid-template-rows:repeat(4,1fr);
    gap:1.4vw;
    width:100%; height:100%;
}

/* ===== KARTU KAMAR ===== */
.kamar-card {
    background:var(--card-bg);
    border-radius:1.2vw; overflow:hidden;
    display:flex; flex-direction:column;
    align-items:center; justify-content:space-evenly;
    box-shadow:0 4px 20px var(--shadow);
    border:2px solid transparent;
    position:relative;
    padding:.8vh .8vw;
    transition:border-color .3s, box-shadow .3s;
}

.kamar-card::before {
    content:''; position:absolute; top:0; left:0; right:0; height:5px;
}
.kamar-card.kosong::before { background:linear-gradient(90deg,var(--success),#00c853); }
.kamar-card.isi::before    { background:linear-gradient(90deg,var(--danger),#d32f2f); }

.kamar-card.kosong { border-color:rgba(0,230,118,.2); }
.kamar-card.isi    { border-color:rgba(255,82,82,.2); }

.bed-wrap {
    width:2.8vw; height:2.8vw;
    min-width:34px; min-height:34px; max-width:48px; max-height:48px;
    border-radius:.7vw;
    display:flex; align-items:center; justify-content:center;
    flex-shrink:0;
}
.kamar-card.kosong .bed-wrap { background:linear-gradient(135deg,var(--success),#00c853); box-shadow:0 3px 12px rgba(0,230,118,.35); }
.kamar-card.isi    .bed-wrap { background:linear-gradient(135deg,var(--danger),#d32f2f);  box-shadow:0 3px 12px rgba(255,82,82,.35); }

.kamar-bangsal {
    font-size:.88vw; font-weight:800; color:var(--dark);
    text-align:center; line-height:1.25; width:100%;
    padding:0 .2vw;
    display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;
    overflow:hidden;
}

.kamar-kode {
    font-family:'Archivo Black',sans-serif;
    font-size:.72vw; color:rgba(0,0,0,.4);
    letter-spacing:.04em; text-align:center;
    white-space:nowrap;
}

.kamar-kelas {
    padding:.28vh .75vw;
    border-radius:.4vw;
    font-size:.66vw; font-weight:800; color:#fff;
    text-transform:uppercase; letter-spacing:.04em;
}
.kl-vip  { background:linear-gradient(135deg,#ff6b9d,#c44569); }
.kl-1    { background:linear-gradient(135deg,var(--secondary),#0066cc); }
.kl-2    { background:linear-gradient(135deg,#ffa726,#ef6c00); }
.kl-3    { background:linear-gradient(135deg,#ab47bc,#7b1fa2); }
.kl-utama{ background:linear-gradient(135deg,#ec4899,#9d174d); }

.kamar-status {
    font-size:.76vw; font-weight:800;
    display:flex; align-items:center; gap:.3vw;
    text-transform:uppercase; letter-spacing:.04em;
}
.kamar-status.kosong { color:var(--success); }
.kamar-status.isi    { color:var(--danger); }
.kamar-status i      { font-size:.8vw; }

/* ===== PAGE DOTS ===== */
.page-dots {
    position:fixed; bottom:11vh; left:50%; transform:translateX(-50%);
    display:flex; gap:.6vw; z-index:20; height:3vh; align-items:center;
}
.page-dot {
    width:.7vw; height:.7vw; min-width:8px; min-height:8px;
    border-radius:50%; background:rgba(255,255,255,.3);
    transition:background .3s, transform .3s;
    cursor:pointer;
}
.page-dot.active { background:var(--primary); transform:scale(1.4); }

/* ===== PAGE COUNTER ===== */
.page-counter {
    position:fixed; bottom:11vh; right:2.5vw; z-index:20;
    background:rgba(10,25,41,.9); backdrop-filter:blur(10px);
    padding:.6vh 1.2vw; border-radius:.7vw;
    border:1px solid rgba(0,212,170,.3);
    font-size:.85vw; color:rgba(255,255,255,.6); font-weight:600;
}
.page-counter span { font-family:'Archivo Black',sans-serif; color:var(--primary); font-size:1vw; }

/* ===== FOOTER ===== */
.footer {
    position:fixed; bottom:0; left:0; right:0; z-index:10;
    background:rgba(10,25,41,.97);
    backdrop-filter:blur(20px);
    border-top:3px solid var(--primary);
    overflow:hidden;
    box-shadow:0 -4px 30px rgba(0,212,170,.2);
}
.marquee-row {
    border-bottom:1px solid rgba(255,255,255,.08);
    padding:.6vh 0; overflow:hidden;
}
.marquee-content {
    display:inline-flex; white-space:nowrap;
    font-size:.95vw; font-weight:600; color:#fff;
    animation:mqScroll 60s linear infinite;
}
@keyframes mqScroll { 0%{transform:translateX(0)} 100%{transform:translateX(-50%)} }
.mq-item { display:inline-flex; align-items:center; gap:.5vw; padding:0 2.5vw 0 0; }
.mq-item i  { color:var(--primary); }
.mq-kelas   { color:var(--warning); font-weight:800; }
.mq-avail   { color:var(--success); font-weight:800; }
.mq-occ     { color:var(--danger); font-weight:800; }
.mq-sep     { color:rgba(255,255,255,.2); margin:0 .2vw; }

.footer-copy {
    padding:.4vh 2vw;
    display:flex; align-items:center; justify-content:space-between;
    background:rgba(0,0,0,.35);
}
.footer-copy-left {
    font-size:.68vw; color:rgba(255,255,255,.4); font-weight:500;
    display:flex; align-items:center; gap:.5vw;
}
.footer-copy-left i { color:var(--primary); font-size:.7vw; }
.footer-copy-right {
    font-size:.68vw; color:rgba(255,255,255,.35); font-weight:500; letter-spacing:.03em;
}
.footer-copy-right span { color:var(--primary); font-weight:700; }
</style>
</head>
<body>

<!-- ===== HEADER ===== -->
<div class="header">
    <div class="brand-section">
        <div class="brand-icon"><i class="bi bi-hospital-fill"></i></div>
        <div class="brand-text">
            <h1>Ketersediaan Tempat Tidur</h1>
            <p>RS Permata Hati</p>
        </div>
    </div>

    <div class="header-stats">
        <div class="header-stat-item">
            <div class="header-stat-icon hsi-blue"><i class="bi bi-grid-3x3-gap-fill"></i></div>
            <div>
                <div class="header-stat-label">Total TT</div>
                <div class="header-stat-value" id="hTotal"><?= $totalKamar ?></div>
            </div>
        </div>
        <div class="header-stat-item">
            <div class="header-stat-icon hsi-green"><i class="bi bi-check-circle-fill"></i></div>
            <div>
                <div class="header-stat-label">Tersedia</div>
                <div class="header-stat-value" id="hKosong"><?= $totalKosong ?></div>
            </div>
        </div>
        <div class="header-stat-item">
            <div class="header-stat-icon hsi-red"><i class="bi bi-x-circle-fill"></i></div>
            <div>
                <div class="header-stat-label">Terisi</div>
                <div class="header-stat-value" id="hIsi"><?= $totalIsi ?></div>
            </div>
        </div>
    </div>

    <div class="header-info">
        <div class="live-time-wrap">
            <div class="live-time" id="liveTime">00:00:00</div>
            <div class="live-tz">WIB</div>
        </div>
        <div class="live-date" id="liveDate">&mdash;</div>
    </div>
</div>

<!-- ===== MAIN ===== -->
<div class="main-content">
    <div class="page-wrapper" id="pageWrapper">

        <?php foreach ($pages as $pi => $pageKamar): ?>
        <div class="page-slide <?= $pi === 0 ? 'active' : '' ?>" id="slide-<?= $pi ?>">
            <div class="kamar-grid">
            <?php foreach ($pageKamar as $k):
                $st      = $k['status'];
                $stClass = ($st === 'KOSONG') ? 'kosong' : 'isi';
                $stLabel = ($st === 'KOSONG') ? 'Tersedia' : 'Terisi';
                $stIcon  = ($st === 'KOSONG') ? 'bi-check-circle-fill' : 'bi-x-circle-fill';

                $kl = $k['kelas'];
                if     (stripos($kl,'VIP')   !== false) $klCls = 'kl-vip';
                elseif (stripos($kl,'UTAMA') !== false) $klCls = 'kl-utama';
                elseif (stripos($kl,'1')     !== false) $klCls = 'kl-1';
                elseif (stripos($kl,'2')     !== false) $klCls = 'kl-2';
                else                                    $klCls = 'kl-3';
            ?>
            <div class="kamar-card <?= $stClass ?>">
                <div class="bed-wrap">
                    <svg viewBox="0 0 24 24" fill="white" width="55%" height="55%">
                        <path d="M2 3v2h1v11H2v2h1v1h2v-1h14v1h2v-1h1v-2h-1V5h1V3H2zm2 2h16v7h-6V9a1 1 0 0 0-1-1H7a1 1 0 0 0-1 1v3H4V5zm3 4h4v3H7V9zm-3 5h16v2H4v-2z"/>
                    </svg>
                </div>
                <div class="kamar-bangsal"><?= htmlspecialchars($k['nm_bangsal']) ?></div>
                <div class="kamar-kode"><?= htmlspecialchars($k['kd_kamar']) ?></div>
                <div class="kamar-kelas <?= $klCls ?>"><?= htmlspecialchars($kl) ?></div>
                <div class="kamar-status <?= $stClass ?>">
                    <i class="bi <?= $stIcon ?>"></i><?= $stLabel ?>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

    </div><!-- /page-wrapper -->
</div><!-- /main-content -->

<!-- ===== PAGE DOTS ===== -->
<div class="page-dots" id="pageDots">
    <?php for ($i = 0; $i < $nPages; $i++): ?>
    <div class="page-dot <?= $i === 0 ? 'active' : '' ?>" id="dot-<?= $i ?>" onclick="showSlide(<?= $i ?>)"></div>
    <?php endfor; ?>
</div>

<!-- ===== PAGE COUNTER ===== -->
<div class="page-counter">
    Hal <span id="curPage">1</span> / <span><?= $nPages ?></span>
</div>

<!-- ===== FOOTER ===== -->
<div class="footer">
    <div class="marquee-row">
        <div class="marquee-content" id="marqueeContent">
            <?php
            $mq = '';
            foreach ($rekap as $kelas => $j) {
                $tot = $j['ISI'] + $j['KOSONG'];
                $mq .= "<span class='mq-item'>"
                     . "<i class='bi bi-info-circle-fill'></i>"
                     . "<span class='mq-kelas'>".htmlspecialchars($kelas)."</span>"
                     . "<span class='mq-sep'>:</span>"
                     . "<span class='mq-avail'>{$j['KOSONG']} Tersedia</span>"
                     . "<span class='mq-sep'>&bull;</span>"
                     . "<span class='mq-occ'>{$j['ISI']} Terisi</span>"
                     . "<span class='mq-sep'>&bull;</span>"
                     . "Total {$tot} TT"
                     . "</span>";
            }
            echo $mq . $mq;
            ?>
        </div>
    </div>
    <div class="footer-copy">
        <div class="footer-copy-left">
            <i class="bi bi-shield-check-fill"></i>
            &copy; <?= date('Y') ?> <span style="color:var(--primary);font-weight:700;margin:0 .2vw">MediFix</span>
            &mdash; Anjungan Pasien Mandiri &amp; Sistem Antrian
            &nbsp;<span style="color:rgba(255,255,255,.2)">|</span>&nbsp;
            <i class="bi bi-person-fill"></i>
            <span style="color:var(--primary);font-weight:700">M. Wira Satria Buana</span>
        </div>
        <div class="footer-copy-right">
            Powered by <span>MediFix</span> &middot; v1.0
        </div>
    </div>
</div>

<script>
/* ===== CLOCK ===== */
var HARI  = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
var BULAN = ['Januari','Februari','Maret','April','Mei','Juni',
             'Juli','Agustus','September','Oktober','November','Desember'];
function pad2(n){ return String(n).padStart(2,'0'); }
function updateClock(){
    var now = new Date();
    document.getElementById('liveTime').textContent =
        pad2(now.getHours())+':'+pad2(now.getMinutes())+':'+pad2(now.getSeconds());
    document.getElementById('liveDate').textContent =
        HARI[now.getDay()]+', '+now.getDate()+' '+BULAN[now.getMonth()]+' '+now.getFullYear();
}
updateClock();
setInterval(updateClock, 1000);

/* ===== PAGING ===== */
var slides    = document.querySelectorAll('.page-slide');
var dots      = document.querySelectorAll('.page-dot');
var curPage   = 0;
var pageTimer = null;
var PAGE_DUR  = 8000; // 8 detik per halaman

function showSlide(idx) {
    // Hentikan timer lama agar tidak double-fire
    if (pageTimer) {
        clearTimeout(pageTimer);
        pageTimer = null;
    }

    // Sembunyikan semua slide & nonaktifkan semua dot
    slides.forEach(function(s) { s.classList.remove('active'); });
    dots.forEach(function(d)   { d.classList.remove('active'); });

    // Tampilkan slide & dot yang diminta
    slides[idx].classList.add('active');
    if (dots[idx]) dots[idx].classList.add('active');

    // Update page counter — AMAN: elemen sudah ada di HTML
    var elCur = document.getElementById('curPage');
    if (elCur) elCur.textContent = idx + 1;

    curPage = idx;

    // Jadwalkan slide berikutnya hanya jika ada lebih dari 1 halaman
    if (slides.length > 1) {
        pageTimer = setTimeout(function() {
            showSlide((curPage + 1) % slides.length);
        }, PAGE_DUR);
    }
}

// Mulai paging
showSlide(0);

/* ===== SOFT REFRESH DATA (tiap 30 detik, tanpa reload halaman) ===== */
function refreshData() {
    fetch(window.location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function(r) { return r.text(); })
    .then(function(html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');

        // Update grid semua halaman
        var newSlides = doc.querySelectorAll('.page-slide');
        var oldSlides = document.querySelectorAll('.page-slide');
        newSlides.forEach(function(ns, i) {
            if (oldSlides[i]) {
                var newGrid = ns.querySelector('.kamar-grid');
                var oldGrid = oldSlides[i].querySelector('.kamar-grid');
                if (newGrid && oldGrid) oldGrid.innerHTML = newGrid.innerHTML;
            }
        });

        // Update header stats
        ['hTotal','hKosong','hIsi'].forEach(function(id) {
            var nEl = doc.getElementById(id);
            var oEl = document.getElementById(id);
            if (nEl && oEl) oEl.textContent = nEl.textContent;
        });

        // Update marquee
        var nm = doc.getElementById('marqueeContent');
        var om = document.getElementById('marqueeContent');
        if (nm && om) om.innerHTML = nm.innerHTML;
    })
    .catch(function(e) { console.warn('Refresh error:', e); });
}

setInterval(refreshData, 30000);
</script>
</body>
</html>