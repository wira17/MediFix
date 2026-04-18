<?php
/**
 * display_tv.php
 * Tampilan TV gabungan: Ketersediaan Kamar + Jadwal Dokter
 * Bergantian otomatis — satu layar, satu TV
 */
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

// ════════════════════════════════════════════════════
// DATA: KETERSEDIAAN KAMAR
// ════════════════════════════════════════════════════
$kamar_data = []; $rekapKamar = []; $totalIsi = 0; $totalKosong = 0;
try {
    $excludedBangsal = [
        'B0213','K302','B0114','B0115','B0112','B0113','RR01','RR02','RR03','RR04','B0219',
        'B0073','VK1','VK2','OM','OK1','OK2','OK3','OK4','B0081','B0082','B0083','B0084','P001',
        'B0096','K019','K020','K021','B0102','ISOC1','K308','M9B','NICU','B0100','B0212','TES','B0118'
    ];
    $excludedBangsal = array_map(fn($v) => strtoupper(trim($v)), $excludedBangsal);
    $exList = "'" . implode("','", $excludedBangsal) . "'";

    $stmt = $pdo_simrs->query("
        SELECT kamar.kd_kamar, bangsal.nm_bangsal, kamar.kelas, kamar.status
        FROM kamar
        INNER JOIN bangsal ON kamar.kd_bangsal = bangsal.kd_bangsal
        WHERE kamar.status IN ('KOSONG','ISI')
          AND UPPER(TRIM(kamar.kd_bangsal)) NOT IN ($exList)
        ORDER BY kamar.kelas, bangsal.nm_bangsal, kamar.kd_kamar
    ");
    $kamar_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($kamar_data as $k) {
        $kl = $k['kelas']; $st = $k['status'];
        if (!isset($rekapKamar[$kl])) $rekapKamar[$kl] = ['ISI'=>0,'KOSONG'=>0];
        $rekapKamar[$kl][$st]++;
        if ($st === 'ISI')    $totalIsi++;
        if ($st === 'KOSONG') $totalKosong++;
    }
} catch (PDOException $e) {}
$totalKamar   = count($kamar_data);
$kamarPages   = array_chunk($kamar_data, 20);   // 5×4 = 20 per page

// ════════════════════════════════════════════════════
// DATA: JADWAL DOKTER
// ════════════════════════════════════════════════════
$jadwal_data = []; $jumlah_pasien = [];
try {
    $hari_indo = ['MONDAY'=>'SENIN','TUESDAY'=>'SELASA','WEDNESDAY'=>'RABU',
                  'THURSDAY'=>'KAMIS','FRIDAY'=>'JUMAT','SATURDAY'=>'SABTU','SUNDAY'=>'MINGGU']
                 [strtoupper(date('l'))] ?? 'SENIN';

    $jadwal_data = $pdo_simrs->query("
        SELECT j.kd_dokter, d.nm_dokter, p.nm_poli,
               j.hari_kerja, j.jam_mulai, j.jam_selesai, j.kuota
        FROM jadwal j
        INNER JOIN dokter     d ON j.kd_dokter = d.kd_dokter
        INNER JOIN poliklinik p ON j.kd_poli   = p.kd_poli
        WHERE j.hari_kerja = '$hari_indo'
        ORDER BY p.nm_poli, d.nm_dokter
    ")->fetchAll(PDO::FETCH_ASSOC);

    $sq = $pdo_simrs->prepare("SELECT kd_dokter, COUNT(no_rawat) AS total FROM reg_periksa WHERE tgl_registrasi=? GROUP BY kd_dokter");
    $sq->execute([date('Y-m-d')]);
    foreach ($sq->fetchAll(PDO::FETCH_ASSOC) as $r)
        $jumlah_pasien[$r['kd_dokter']] = (int)$r['total'];
} catch (PDOException $e) {}

$jadwalPages = array_chunk($jadwal_data, 8);
$cols_map    = [1=>1,2=>2,3=>3,4=>4,5=>5,6=>3,7=>4,8=>4];

// ════════════════════════════════════════════════════
// TOTAL SLIDES GLOBAL
// slot 0…(n-1)  → kamar pages
// slot n…(n+m-1) → jadwal pages
// ════════════════════════════════════════════════════
$nKamarPages  = max(1, count($kamarPages));
$nJadwalPages = max(1, count($jadwalPages));
$totalSlides  = $nKamarPages + $nJadwalPages;

// Durasi tiap slide (ms)
$DUR_KAMAR  = 9000;   // 9 detik per halaman kamar
$DUR_JADWAL = 12000;  // 12 detik per halaman jadwal
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Display TV — RS Permata Hati</title>
<link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=DM+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&family=Syne:wght@700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
/* ════════════════════════════════════════════════════
   RESET & ROOT
════════════════════════════════════════════════════ */
*,*::before,*::after { margin:0; padding:0; box-sizing:border-box; }
:root {
    --primary:  #00d4aa;
    --gold:     #f0b429;
    --teal:     #06b6d4;
    --teal2:    #22d3ee;
    --emerald:  #10b981;
    --rose:     #f43f5e;
    --amber:    #f59e0b;
    --success:  #00e676;
    --danger:   #ff5252;
    --dark:     #0a1929;
    --card-bg:  rgba(255,255,255,0.98);
    --bg:       #07101f;
    --hdr:      72px;
    --ftr:      52px;
    --shadow:   rgba(10,25,41,0.12);
}
html, body {
    height: 100vh; overflow: hidden;
    font-family: 'DM Sans', sans-serif;
    background: var(--bg); color: #e2eaf6;
}

/* Grid BG pattern */
body::before {
    content:''; position:fixed; inset:0; z-index:0; pointer-events:none;
    background-image:
        linear-gradient(rgba(0,212,170,.02) 1px, transparent 1px),
        linear-gradient(90deg, rgba(0,212,170,.02) 1px, transparent 1px);
    background-size: 48px 48px;
}
/* Glow orbs */
.orb1,.orb2 { position:fixed; border-radius:50%; pointer-events:none; z-index:0; }
.orb1 { width:700px; height:700px; top:-300px; left:-200px;
        background:radial-gradient(circle,rgba(0,212,170,.07) 0%,transparent 65%); }
.orb2 { width:500px; height:500px; bottom:-200px; right:-150px;
        background:radial-gradient(circle,rgba(6,182,212,.06) 0%,transparent 65%); }

/* ════════════════════════════════════════════════════
   HEADER
════════════════════════════════════════════════════ */
.hdr {
    position: relative; z-index: 100;
    height: var(--hdr);
    background: rgba(7,16,31,.97);
    border-bottom: 1px solid rgba(0,212,170,.18);
    box-shadow: 0 4px 30px rgba(0,0,0,.5);
    display: flex; align-items: center;
    justify-content: space-between;
    padding: 0 24px; gap: 16px;
}
.hdr::after {
    content:''; position:absolute; bottom:0; left:0; right:0; height:2px;
    background: linear-gradient(90deg, transparent, var(--primary) 30%, var(--gold) 70%, transparent);
    opacity: .55;
}
/* Brand */
.hbrand { display:flex; align-items:center; gap:13px; flex-shrink:0; }
.hicon {
    width:44px; height:44px; min-width:44px;
    background:linear-gradient(135deg,var(--primary),#00aa88);
    border-radius:11px; display:flex; align-items:center; justify-content:center;
    font-size:22px; box-shadow:0 0 20px rgba(0,212,170,.4);
}
.hbrand-text h1 {
    font-family:'Archivo Black',sans-serif; font-size:17px;
    background:linear-gradient(90deg,#fff 55%,var(--teal2));
    -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text;
    text-transform:uppercase; letter-spacing:-.01em;
}
.hbrand-text p { font-size:10.5px; color:rgba(255,255,255,.5); font-weight:600; margin-top:2px; letter-spacing:.04em; }

/* Stats strip (tengah) */
.hstats { display:flex; gap:10px; }
.hstat {
    display:flex; align-items:center; gap:9px;
    padding:7px 13px;
    background:rgba(255,255,255,.06);
    border:1px solid rgba(255,255,255,.09);
    border-radius:10px;
}
.hstat-icon {
    width:30px; height:30px; border-radius:7px;
    display:flex; align-items:center; justify-content:center;
    font-size:14px; flex-shrink:0;
}
.hi-blue   { background:linear-gradient(135deg,#0088ff,#0055cc); }
.hi-green  { background:linear-gradient(135deg,var(--success),#00c853); }
.hi-red    { background:linear-gradient(135deg,var(--danger),#c62828); }
.hi-teal   { background:linear-gradient(135deg,var(--teal),#0891b2); }
.hstat-lbl { font-size:9.5px; color:rgba(255,255,255,.5); font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
.hstat-val { font-family:'Archivo Black',sans-serif; font-size:20px; color:#fff; line-height:1; }

/* Mode badge (tampilan sekarang) */
.mode-badge {
    display:flex; align-items:center; gap:7px;
    padding:7px 14px;
    border-radius:50px; font-size:11.5px; font-weight:700;
    letter-spacing:.4px; text-transform:uppercase;
    transition: all .5s ease;
    border: 1px solid;
}
.mode-badge.kamar { background:rgba(0,230,118,.1); border-color:rgba(0,230,118,.3); color:#00e676; }
.mode-badge.jadwal { background:rgba(6,182,212,.1); border-color:rgba(6,182,212,.3); color:var(--teal2); }
.mode-dot { width:7px; height:7px; border-radius:50%; animation:pdot 2s infinite; }
.mode-badge.kamar .mode-dot { background:#00e676; box-shadow:0 0 8px #00e676; }
.mode-badge.jadwal .mode-dot { background:var(--teal2); box-shadow:0 0 8px var(--teal2); }
@keyframes pdot { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.4;transform:scale(1.5)} }

/* Clock */
.hclock { text-align:right; flex-shrink:0; }
.clock-time {
    font-family:'Archivo Black',sans-serif; font-size:28px;
    color:var(--gold); letter-spacing:1px;
    text-shadow:0 0 22px rgba(240,180,41,.45);
    line-height:1;
}
.clock-wib { font-family:'Archivo Black',sans-serif; font-size:11px; color:var(--gold); opacity:.6; letter-spacing:.1em; }
.clock-date { font-size:10px; color:rgba(255,255,255,.6); font-weight:600; margin-top:3px; }

/* ════════════════════════════════════════════════════
   STAGE (area konten utama)
════════════════════════════════════════════════════ */
.stage {
    position: relative; z-index:10;
    height: calc(100vh - var(--hdr) - var(--ftr));
    overflow: hidden;
}

/* ════════════════════════════════════════════════════
   SLIDE SYSTEM — KAMAR
════════════════════════════════════════════════════ */
.scene {
    position: absolute; inset:0;
    opacity:0; visibility:hidden;
    transition: opacity .65s ease, visibility .65s ease;
    pointer-events:none;
}
.scene.active {
    opacity:1; visibility:visible; pointer-events:auto;
    position:relative;
}

/* ── KAMAR scene ── */
.scene-kamar {
    padding: 18px 20px;
    height: 100%;
}
.kamar-grid {
    display: grid;
    grid-template-columns: repeat(5,1fr);
    grid-template-rows: repeat(4,1fr);
    gap: 13px;
    width:100%; height:100%;
}
.kamar-card {
    background: var(--card-bg);
    border-radius: 14px; overflow: hidden;
    display: flex; flex-direction:column;
    align-items:center; justify-content:space-evenly;
    box-shadow: 0 4px 18px var(--shadow);
    border: 2px solid transparent;
    padding: 8px 8px 6px;
    position:relative;
}
.kamar-card::before {
    content:''; position:absolute; top:0; left:0; right:0; height:4px;
}
.kamar-card.kosong::before { background:linear-gradient(90deg,#00e676,#00c853); }
.kamar-card.isi::before    { background:linear-gradient(90deg,#ff5252,#c62828); }
.kamar-card.kosong { border-color:rgba(0,230,118,.18); }
.kamar-card.isi    { border-color:rgba(255,82,82,.18); }

.bed-wrap {
    width: 36px; height:36px; border-radius:8px;
    display:flex; align-items:center; justify-content:center;
    flex-shrink:0;
}
.kamar-card.kosong .bed-wrap { background:linear-gradient(135deg,#00e676,#00c853); box-shadow:0 2px 10px rgba(0,230,118,.35); }
.kamar-card.isi    .bed-wrap { background:linear-gradient(135deg,#ff5252,#c62828); box-shadow:0 2px 10px rgba(255,82,82,.35); }

.kbangsal { font-size:.82vw; font-weight:800; color:var(--dark); text-align:center; line-height:1.25; width:100%; padding:0 3px;
             display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.kkode    { font-family:'Archivo Black',sans-serif; font-size:.65vw; color:rgba(0,0,0,.38); letter-spacing:.04em; }
.kkelas   { padding:3px 9px; border-radius:5px; font-size:.6vw; font-weight:800; color:#fff; text-transform:uppercase; letter-spacing:.04em; }
.kl-vip   { background:linear-gradient(135deg,#ff6b9d,#c44569); }
.kl-1     { background:linear-gradient(135deg,#0088ff,#0055cc); }
.kl-2     { background:linear-gradient(135deg,#ffa726,#ef6c00); }
.kl-3     { background:linear-gradient(135deg,#ab47bc,#7b1fa2); }
.kl-utama { background:linear-gradient(135deg,#ec4899,#9d174d); }
.kstatus  { font-size:.7vw; font-weight:800; display:flex; align-items:center; gap:4px; text-transform:uppercase; letter-spacing:.04em; }
.kstatus.kosong { color:#00e676; }
.kstatus.isi    { color:#ff5252; }

/* ── JADWAL scene ── */
.scene-jadwal {
    padding: 16px 20px;
    height: 100%;
    display:grid;
    grid-auto-rows: 240px;
    align-content:start;
    gap:13px;
    overflow:hidden;
}
/* Judul mode jadwal */
.jadwal-mode-title {
    display:none; /* dihapus — info ada di header badge */
}

.doc-card {
    background: #0f1e33;
    border:1px solid rgba(255,255,255,.07);
    border-radius:16px; overflow:hidden;
    display:flex; flex-direction:column;
    position:relative; transition:transform .3s;
}
.doc-card::before {
    content:''; position:absolute; top:0; left:0; right:0; height:2px;
    background:linear-gradient(90deg,var(--teal),var(--gold)); opacity:.7;
}
.doc-card:hover { transform:translateY(-2px); }
.poli-strip {
    padding:8px 14px; flex-shrink:0;
    background:linear-gradient(135deg,rgba(6,182,212,.1),rgba(6,182,212,.03));
    border-bottom:1px solid rgba(6,182,212,.12);
    display:flex; align-items:center; gap:8px;
}
.poli-strip .pico { font-size:12px; }
.poli-strip .pnm  { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.7px; color:var(--teal2); }
.cbody { flex:1; padding:10px 14px; display:flex; flex-direction:column; gap:8px; overflow:hidden; min-height:0; }
.doc-name { font-family:'Syne',sans-serif; font-size:14px; font-weight:700; color:#fff; line-height:1.3;
             display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.stats { display:grid; grid-template-columns:repeat(2,1fr); gap:6px; margin-top:auto; }
.stat { border-radius:9px; padding:7px 10px; display:flex; flex-direction:column; gap:2px; }
.stat.jam    { background:rgba(6,182,212,.08);  border:1px solid rgba(6,182,212,.14); }
.stat.kuota  { background:rgba(16,185,129,.08); border:1px solid rgba(16,185,129,.14); }
.stat.reg    { background:rgba(245,158,11,.08); border:1px solid rgba(245,158,11,.14); }
.stat.sisa   { background:rgba(244,63,94,.08);  border:1px solid rgba(244,63,94,.14); }
.slbl { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#6b7f9a; }
.sval { font-family:'Syne',sans-serif; font-size:18px; font-weight:800; line-height:1.1; }
.stat.jam   .sval { color:var(--teal2);   font-family:'DM Sans',sans-serif; font-size:11px; font-weight:600; }
.stat.kuota .sval { color:var(--emerald); }
.stat.reg   .sval { color:var(--amber); }
.stat.sisa  .sval { color:var(--rose); }
.kbar  { height:3px; border-radius:2px; background:rgba(255,255,255,.06); margin-top:3px; overflow:hidden; }
.kfill { height:100%; border-radius:2px; background:linear-gradient(90deg,var(--emerald),var(--teal)); }

/* Empty state jadwal */
.jadwal-empty {
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    height:100%; gap:14px; color:rgba(255,255,255,.3);
}
.jadwal-empty .ei {
    width:70px; height:70px; background:rgba(6,182,212,.06);
    border:1px solid rgba(6,182,212,.15); border-radius:50%;
    display:flex; align-items:center; justify-content:center; font-size:32px;
}
.jadwal-empty h2 { font-family:'Syne',sans-serif; font-size:18px; color:rgba(255,255,255,.6); }

/* ════════════════════════════════════════════════════
   FOOTER
════════════════════════════════════════════════════ */
.ftr {
    position: fixed; bottom:0; left:0; right:0; z-index:100;
    height: var(--ftr);
    background: rgba(7,16,31,.97);
    border-top:1px solid rgba(0,212,170,.15);
    display:flex; align-items:center; overflow:hidden;
}
.ftr::before {
    content:''; position:absolute; top:0; left:0; right:0; height:1px;
    background:linear-gradient(90deg,transparent,var(--primary) 30%,var(--gold) 70%,transparent);
    opacity:.4;
}
.fside { flex-shrink:0; padding:0 16px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; white-space:nowrap; }
.fside.left  { color:var(--primary); border-right:1px solid rgba(255,255,255,.07); }
.fside.right {
    display:flex; align-items:center; gap:10px;
    border-left:1px solid rgba(255,255,255,.07);
    color:var(--gold);
}
.mwrap { flex:1; overflow:hidden; display:flex; align-items:center; }
.mtrack {
    display:flex; gap:56px; white-space:nowrap;
    animation:mscroll 50s linear infinite;
}
@keyframes mscroll { 0%{transform:translateX(0)} 100%{transform:translateX(-50%)} }
.mitem { display:flex; align-items:center; gap:7px; font-size:12.5px; color:#7a96b8; font-weight:500; flex-shrink:0; }
.mitem .mi { color:var(--gold); }
/* Rekap marquee kamar */
.mq-kelas { color:var(--amber); font-weight:800; }
.mq-avail { color:var(--success); font-weight:800; }
.mq-occ   { color:var(--danger); font-weight:800; }

/* Dots + progress */
.fdots { display:flex; gap:5px; align-items:center; }
.fdot  { width:6px; height:6px; border-radius:50%; background:rgba(255,255,255,.12); transition:all .4s; }
.fdot.on { background:var(--gold); box-shadow:0 0 8px var(--gold); width:16px; border-radius:3px; }
.fpgnum  { font-size:11px; color:rgba(255,255,255,.45); font-weight:600; }

/* Progress bar */
.pbar {
    position:absolute; bottom:0; left:0; height:2px;
    background:linear-gradient(90deg,var(--primary),var(--gold));
    width:0%;
}

/* ════════════════════════════════════════════════════
   TRANSISI ANTAR MODE (kamar ↔ jadwal)
════════════════════════════════════════════════════ */
.mode-overlay {
    position:fixed; inset:0; z-index:500;
    display:flex; align-items:center; justify-content:center;
    background: var(--bg);
    opacity:0; pointer-events:none;
    transition: opacity .4s ease;
}
.mode-overlay.show { opacity:1; pointer-events:auto; }
.mode-overlay-content {
    display:flex; flex-direction:column; align-items:center; gap:14px;
    transform:scale(.9); transition:transform .4s ease;
}
.mode-overlay.show .mode-overlay-content { transform:scale(1); }
.moc-icon { font-size:52px; }
.moc-title { font-family:'Archivo Black',sans-serif; font-size:26px; color:#fff; text-align:center; }
.moc-sub   { font-size:14px; color:rgba(255,255,255,.4); font-weight:600; text-align:center; }
</style>
</head>
<body>
<div class="orb1"></div>
<div class="orb2"></div>

<!-- ════════════ MODE TRANSITION OVERLAY ════════════ -->
<div class="mode-overlay" id="modeOverlay">
    <div class="mode-overlay-content">
        <div class="moc-icon" id="mocIcon">🏨</div>
        <div class="moc-title" id="mocTitle">Ketersediaan Kamar</div>
        <div class="moc-sub" id="mocSub">RS Permata Hati</div>
    </div>
</div>

<!-- ════════════ HEADER ════════════ -->
<div class="hdr">
    <!-- Brand -->
    <div class="hbrand">
        <div class="hicon">🏥</div>
        <div class="hbrand-text">
            <h1>RS Permata Hati</h1>
            <p>Informasi Layanan Rumah Sakit</p>
        </div>
    </div>

    <!-- Stats kamar -->
    <div class="hstats" id="hstatsKamar">
        <div class="hstat">
            <div class="hstat-icon hi-blue"><i class="bi bi-grid-3x3-gap-fill"></i></div>
            <div>
                <div class="hstat-lbl">Total TT</div>
                <div class="hstat-val" id="hTotal"><?= $totalKamar ?></div>
            </div>
        </div>
        <div class="hstat">
            <div class="hstat-icon hi-green"><i class="bi bi-check-circle-fill"></i></div>
            <div>
                <div class="hstat-lbl">Tersedia</div>
                <div class="hstat-val" id="hKosong"><?= $totalKosong ?></div>
            </div>
        </div>
        <div class="hstat">
            <div class="hstat-icon hi-red"><i class="bi bi-x-circle-fill"></i></div>
            <div>
                <div class="hstat-lbl">Terisi</div>
                <div class="hstat-val" id="hIsi"><?= $totalIsi ?></div>
            </div>
        </div>
    </div>

    <!-- Stats jadwal -->
    <div class="hstats" id="hstatsJadwal" style="display:none;">
        <div class="hstat">
            <div class="hstat-icon hi-teal"><i class="bi bi-calendar2-check-fill"></i></div>
            <div>
                <div class="hstat-lbl">Jadwal Hari Ini</div>
                <div class="hstat-val"><?= count($jadwal_data) ?></div>
            </div>
        </div>
        <div class="hstat">
            <div class="hstat-icon hi-blue" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9);"><i class="bi bi-person-fill"></i></div>
            <div>
                <div class="hstat-lbl">Total Pasien</div>
                <div class="hstat-val"><?= array_sum($jumlah_pasien) ?></div>
            </div>
        </div>
    </div>

    <!-- Mode badge -->
    <div class="mode-badge kamar" id="modeBadge">
        <span class="mode-dot"></span>
        <span id="modeBadgeText">Ketersediaan Kamar</span>
    </div>

    <!-- Clock -->
    <div class="hclock">
        <div style="display:flex;align-items:flex-end;gap:5px;justify-content:flex-end;line-height:1;">
            <div class="clock-time" id="liveTime">00:00:00</div>
            <div class="clock-wib">WIB</div>
        </div>
        <div class="clock-date" id="liveDate"></div>
    </div>
</div>

<!-- ════════════ STAGE ════════════ -->
<div class="stage" id="stage">

    <!-- ── KAMAR SCENES ── -->
    <?php if (empty($kamarPages)): ?>
    <div class="scene scene-kamar" id="scene-kamar-0">
        <div style="display:flex;height:100%;align-items:center;justify-content:center;color:rgba(255,255,255,.3);font-size:18px;">
            Tidak ada data kamar
        </div>
    </div>
    <?php else: foreach ($kamarPages as $pi => $pageKamar): ?>
    <div class="scene scene-kamar <?= $pi===0?'active':'' ?>" id="scene-kamar-<?= $pi ?>">
        <div class="kamar-grid">
        <?php foreach ($pageKamar as $k):
            $st = $k['status'];
            $stClass = $st==='KOSONG'?'kosong':'isi';
            $stLabel = $st==='KOSONG'?'Tersedia':'Terisi';
            $stIcon  = $st==='KOSONG'?'bi-check-circle-fill':'bi-x-circle-fill';
            $kl = $k['kelas'];
            if      (stripos($kl,'VIP')   !==false) $klCls='kl-vip';
            elseif  (stripos($kl,'UTAMA') !==false) $klCls='kl-utama';
            elseif  (stripos($kl,'1')     !==false) $klCls='kl-1';
            elseif  (stripos($kl,'2')     !==false) $klCls='kl-2';
            else                                    $klCls='kl-3';
        ?>
        <div class="kamar-card <?= $stClass ?>">
            <div class="bed-wrap">
                <svg viewBox="0 0 24 24" fill="white" width="55%" height="55%">
                    <path d="M2 3v2h1v11H2v2h1v1h2v-1h14v1h2v-1h1v-2h-1V5h1V3H2zm2 2h16v7h-6V9a1 1 0 0 0-1-1H7a1 1 0 0 0-1 1v3H4V5zm3 4h4v3H7V9zm-3 5h16v2H4v-2z"/>
                </svg>
            </div>
            <div class="kbangsal"><?= htmlspecialchars($k['nm_bangsal']) ?></div>
            <div class="kkode"><?= htmlspecialchars($k['kd_kamar']) ?></div>
            <div class="kkelas <?= $klCls ?>"><?= htmlspecialchars($kl) ?></div>
            <div class="kstatus <?= $stClass ?>">
                <i class="bi <?= $stIcon ?>"></i><?= $stLabel ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; endif; ?>

    <!-- ── JADWAL SCENES ── -->
    <?php if (empty($jadwal_data)): ?>
    <div class="scene scene-jadwal" id="scene-jadwal-0">
        <div class="jadwal-empty">
            <div class="ei">📅</div>
            <h2>Tidak Ada Jadwal</h2>
            <p>Tidak ada jadwal dokter untuk hari ini</p>
        </div>
    </div>
    <?php else: foreach ($jadwalPages as $pi => $page):
        $cnt  = count($page);
        $cols = $cols_map[$cnt] ?? 4;
    ?>
    <div class="scene scene-jadwal" id="scene-jadwal-<?= $pi ?>"
         style="grid-template-columns:repeat(<?= $cols ?>,1fr);">
        <?php foreach ($page as $j):
            $pasien = $jumlah_pasien[$j['kd_dokter']] ?? 0;
            $kuota  = max(1,(int)$j['kuota']);
            $sisa   = max(0,$kuota-$pasien);
            $pct    = min(100,round($pasien/$kuota*100));
        ?>
        <div class="doc-card">
            <div class="poli-strip">
                <span class="pico">🏨</span>
                <span class="pnm"><?= htmlspecialchars($j['nm_poli']) ?></span>
            </div>
            <div class="cbody">
                <div class="doc-name"><?= htmlspecialchars($j['nm_dokter']) ?></div>
                <div class="stats">
                    <div class="stat jam">
                        <div class="slbl">⏰ Jam Praktik</div>
                        <div class="sval"><?= substr($j['jam_mulai'],0,5).' – '.substr($j['jam_selesai'],0,5) ?></div>
                    </div>
                    <div class="stat kuota">
                        <div class="slbl">👥 Kuota</div>
                        <div class="sval"><?= $kuota ?></div>
                        <div class="kbar"><div class="kfill" style="width:<?= $pct ?>%"></div></div>
                    </div>
                    <div class="stat reg">
                        <div class="slbl">✅ Terdaftar</div>
                        <div class="sval"><?= $pasien ?></div>
                    </div>
                    <div class="stat sisa">
                        <div class="slbl">⏳ Sisa Kuota</div>
                        <div class="sval"><?= $sisa ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; endif; ?>

</div><!-- /stage -->

<!-- ════════════ FOOTER ════════════ -->
<div class="ftr">
    <div class="fside left">🏥 RS Permata Hati</div>
    <div class="mwrap">
        <div class="mtrack" id="marqueeTrack">
            <?php
            $mq = '';
            // Rekap kamar per kelas
            foreach ($rekapKamar as $kelas => $j) {
                $tot = $j['ISI'] + $j['KOSONG'];
                $mq .= "<div class='mitem'><span class='mi'>🛏</span><span class='mq-kelas'>".htmlspecialchars($kelas)."</span>"
                     . "<span style='color:rgba(255,255,255,.2);margin:0 4px'>:</span>"
                     . "<span class='mq-avail'>{$j['KOSONG']} Tersedia</span>"
                     . "<span style='color:rgba(255,255,255,.15);margin:0 4px'>•</span>"
                     . "<span class='mq-occ'>{$j['ISI']} Terisi</span>"
                     . "<span style='color:rgba(255,255,255,.15);margin:0 4px'>•</span>"
                     . "Total {$tot}</div>";
            }
            // Pesan umum
            $msgs = [
                "📋 Bawa kartu berobat dan dokumen pendukung saat berobat",
                "❤️ Selamat datang, semoga lekas sembuh",
                "✦ Mohon hadir 15 menit sebelum waktu pemeriksaan",
                "✦ Jadwal diperbarui otomatis dari SIMRS",
                "🏥 RS Permata Hati melayani dengan sepenuh hati",
            ];
            foreach ($msgs as $m) $mq .= "<div class='mitem'>{$m}</div>";
            echo $mq . $mq; // duplikasi untuk marquee seamless
            ?>
        </div>
    </div>
    <div class="fside right">
        <span class="fpgnum">Hal <span id="fpgNow">1</span>/<span id="fpgTotal"><?= $nKamarPages + $nJadwalPages ?></span></span>
        <div class="fdots" id="fdots">
            <?php for ($i=0; $i<($nKamarPages+$nJadwalPages); $i++): ?>
            <div class="fdot <?= $i===0?'on':'' ?>"></div>
            <?php endfor; ?>
        </div>
    </div>
    <div class="pbar" id="pbar"></div>
</div>

<script>
// ════════════════════════════════════════════════════
// KONFIGURASI
// ════════════════════════════════════════════════════
const N_KAMAR  = <?= $nKamarPages ?>;
const N_JADWAL = <?= $nJadwalPages ?>;
const TOTAL    = N_KAMAR + N_JADWAL;
const DUR_KAMAR  = <?= $DUR_KAMAR ?>;
const DUR_JADWAL = <?= $DUR_JADWAL ?>;

// ════════════════════════════════════════════════════
// JAM
// ════════════════════════════════════════════════════
const HARI  = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
const BULAN = ['Januari','Februari','Maret','April','Mei','Juni',
               'Juli','Agustus','September','Oktober','November','Desember'];
function pad2(n){ return String(n).padStart(2,'0'); }
function updateClock(){
    const now = new Date();
    document.getElementById('liveTime').textContent =
        pad2(now.getHours())+':'+pad2(now.getMinutes())+':'+pad2(now.getSeconds());
    document.getElementById('liveDate').textContent =
        HARI[now.getDay()]+', '+now.getDate()+' '+BULAN[now.getMonth()]+' '+now.getFullYear();
}
updateClock(); setInterval(updateClock, 1000);

// ════════════════════════════════════════════════════
// SLIDE ENGINE
// ════════════════════════════════════════════════════
let curSlide = 0;
let slideTimer = null;

// Kumpulkan semua scene: [scene-kamar-0, ..., scene-jadwal-0, ...]
function getScenes() {
    const list = [];
    for (let i=0; i<N_KAMAR; i++)  list.push(document.getElementById('scene-kamar-'+i));
    for (let i=0; i<N_JADWAL; i++) list.push(document.getElementById('scene-jadwal-'+i));
    return list;
}
function getDots() { return document.querySelectorAll('.fdot'); }

function getDuration(idx) {
    return idx < N_KAMAR ? DUR_KAMAR : DUR_JADWAL;
}

function isKamar(idx)  { return idx < N_KAMAR; }

function updateHeader(idx) {
    const kamar  = isKamar(idx);
    const badge  = document.getElementById('modeBadge');
    const btext  = document.getElementById('modeBadgeText');
    const hk     = document.getElementById('hstatsKamar');
    const hj     = document.getElementById('hstatsJadwal');

    if (kamar) {
        badge.className  = 'mode-badge kamar';
        btext.textContent = 'Ketersediaan Kamar';
        hk.style.display = 'flex';
        hj.style.display = 'none';
    } else {
        badge.className  = 'mode-badge jadwal';
        btext.textContent = 'Jadwal Dokter';
        hk.style.display = 'none';
        hj.style.display = 'flex';
    }
}

function startBar(dur) {
    const pbar = document.getElementById('pbar');
    pbar.style.transition = 'none';
    pbar.style.width = '0%';
    requestAnimationFrame(() => {
        pbar.style.transition = 'width '+dur+'ms linear';
        pbar.style.width = '100%';
    });
}

function showSlide(idx) {
    clearTimeout(slideTimer);

    const scenes = getScenes();
    const dots   = getDots();

    // Cek apakah ganti mode (kamar→jadwal atau jadwal→kamar)
    const prevIsKamar = isKamar(curSlide);
    const nextIsKamar = isKamar(idx);
    const modeChange  = (prevIsKamar !== nextIsKamar);

    function doShow() {
        // Sembunyikan semua
        scenes.forEach(s => { if(s) s.classList.remove('active'); });
        dots.forEach((d,i) => d.classList.toggle('on', i===idx));

        // Tampilkan target
        if (scenes[idx]) scenes[idx].classList.add('active');

        // Update header & page counter
        updateHeader(idx);
        const fpg = document.getElementById('fpgNow');
        if (fpg) fpg.textContent = idx + 1;

        curSlide = idx;

        // Progress bar
        const dur = getDuration(idx);
        startBar(dur);

        // Jadwalkan berikutnya
        if (TOTAL > 1) {
            slideTimer = setTimeout(() => showSlide((curSlide+1) % TOTAL), dur);
        }
    }

    if (modeChange) {
        // Tampilkan overlay transisi
        const ov = document.getElementById('modeOverlay');
        const ic = document.getElementById('mocIcon');
        const ti = document.getElementById('mocTitle');
        ic.textContent = nextIsKamar ? '🛏' : '👨‍⚕️';
        ti.textContent = nextIsKamar ? 'Ketersediaan Tempat Tidur' : 'Jadwal Dokter Hari Ini';
        ov.classList.add('show');
        setTimeout(() => {
            ov.classList.remove('show');
            doShow();
        }, 900);
    } else {
        doShow();
    }
}

// Mulai
showSlide(0);

// ════════════════════════════════════════════════════
// SOFT REFRESH (tiap 30 detik — update data tanpa reload)
// ════════════════════════════════════════════════════
function refreshData() {
    fetch(window.location.href, { headers:{'X-Requested-With':'XMLHttpRequest'} })
    .then(r => r.text())
    .then(html => {
        const doc = new DOMParser().parseFromString(html,'text/html');

        // Update grid kamar
        for (let i=0; i<N_KAMAR; i++) {
            const ns = doc.getElementById('scene-kamar-'+i);
            const os = document.getElementById('scene-kamar-'+i);
            if (ns && os) {
                const ng = ns.querySelector('.kamar-grid');
                const og = os.querySelector('.kamar-grid');
                if (ng && og) og.innerHTML = ng.innerHTML;
            }
        }

        // Update kartu jadwal
        for (let i=0; i<N_JADWAL; i++) {
            const ns = doc.getElementById('scene-jadwal-'+i);
            const os = document.getElementById('scene-jadwal-'+i);
            if (ns && os) os.innerHTML = ns.innerHTML;
        }

        // Update header stats
        ['hTotal','hKosong','hIsi'].forEach(id => {
            const n = doc.getElementById(id);
            const o = document.getElementById(id);
            if (n && o) o.textContent = n.textContent;
        });

        // Update marquee
        const nm = doc.getElementById('marqueeTrack');
        const om = document.getElementById('marqueeTrack');
        if (nm && om) om.innerHTML = nm.innerHTML;
    })
    .catch(e => console.warn('Refresh error:', e));
}
setInterval(refreshData, 30000);

// Full reload tiap 10 menit (untuk update jadwal dokter hari baru)
setTimeout(() => location.reload(), 10 * 60 * 1000);
</script>
</body>
</html>