<?php
session_start();
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

$printData    = null;
$errorMsg     = null;
$searchResult = null;
$showSearch   = true;

$hari_map  = ['Sunday'=>'Minggu','Monday'=>'Senin','Tuesday'=>'Selasa','Wednesday'=>'Rabu','Thursday'=>'Kamis','Friday'=>'Jumat','Saturday'=>'Sabtu'];
$bulan_map = ['January'=>'Januari','February'=>'Februari','March'=>'Maret','April'=>'April','May'=>'Mei','June'=>'Juni','July'=>'Juli','August'=>'Agustus','September'=>'September','October'=>'Oktober','November'=>'November','December'=>'Desember'];
$hariIni       = $hari_map[date('l')]  ?? date('l');
$bulanIni      = $bulan_map[date('F')] ?? date('F');
$tanggalLengkap = $hariIni.', '.date('d').' '.$bulanIni.' '.date('Y');

try {
    $stmt    = $pdo_simrs->query("SELECT nama_instansi, alamat_instansi, kabupaten, propinsi, kontak, email FROM setting LIMIT 1");
    $setting = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $setting = ['nama_instansi'=>'RS Permata Hati','alamat_instansi'=>'Jl. Kesehatan No. 123','kabupaten'=>'Kota Sehat','propinsi'=>'Provinsi','kontak'=>'(021) 1234567','email'=>'info@rspermatahati.com'];
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['cari'])) {
    $keyword = trim($_POST['keyword'] ?? '');
    if (empty($keyword)) {
        $errorMsg = "Mohon masukkan No. RM, Nama Pasien, atau No. Rawat.";
    } else {
        try {
            $stmt = $pdo_simrs->prepare("
                SELECT ro.no_resep, ro.no_rawat, ro.tgl_peresepan, ro.jam_peresepan,
                       r.no_rkm_medis, p.nm_pasien, pl.nm_poli,
                       CASE WHEN EXISTS (SELECT 1 FROM resep_dokter_racikan rr WHERE rr.no_resep=ro.no_resep) THEN 'Racikan' ELSE 'Non Racikan' END AS jenis_resep
                FROM resep_obat ro
                LEFT JOIN reg_periksa r  ON ro.no_rawat    = r.no_rawat
                LEFT JOIN pasien p       ON r.no_rkm_medis = p.no_rkm_medis
                LEFT JOIN poliklinik pl  ON r.kd_poli      = pl.kd_poli
                WHERE ro.tgl_peresepan = CURDATE() AND ro.status = 'ralan'
                  AND (r.no_rkm_medis LIKE ? OR p.nm_pasien LIKE ? OR ro.no_rawat LIKE ?)
                ORDER BY ro.tgl_peresepan DESC, ro.jam_peresepan DESC LIMIT 10
            ");
            $pat = "%$keyword%";
            $stmt->execute([$pat,$pat,$pat]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($results) > 0) { $searchResult = $results; $showSearch = false; }
            else { $errorMsg = "Data tidak ditemukan. Pastikan Anda sudah memiliki resep hari ini."; }
        } catch (PDOException $e) { $errorMsg = "Terjadi kesalahan: ".$e->getMessage(); }
    }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['cetak'])) {
    $no_rawat = trim($_POST['no_rawat'] ?? '');
    if (!empty($no_rawat)) {
        try {
            $stmt = $pdo_simrs->prepare("
                SELECT ro.no_resep, ro.tgl_peresepan, ro.jam_peresepan,
                       r.no_rkm_medis, p.nm_pasien, pl.nm_poli,
                       CASE WHEN EXISTS (SELECT 1 FROM resep_dokter_racikan rr WHERE rr.no_resep=ro.no_resep) THEN 'Racikan' ELSE 'Non Racikan' END AS jenis_resep
                FROM resep_obat ro
                LEFT JOIN reg_periksa r  ON ro.no_rawat    = r.no_rawat
                LEFT JOIN pasien p       ON r.no_rkm_medis = p.no_rkm_medis
                LEFT JOIN poliklinik pl  ON r.kd_poli      = pl.kd_poli
                WHERE ro.no_rawat = ?
                ORDER BY ro.tgl_peresepan DESC, ro.jam_peresepan DESC LIMIT 1
            ");
            $stmt->execute([$no_rawat]);
            $resep = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($resep) {
                $resep['no_antrian_farmasi'] = 'F'.str_pad(substr($resep['no_resep'],-4),4,'0',STR_PAD_LEFT);
                $printData = $resep; $showSearch = false;
            } else { $errorMsg = "Data resep tidak ditemukan."; }
        } catch (PDOException $e) { $errorMsg = "Terjadi kesalahan: ".$e->getMessage(); }
    }
}

$jsState = 'welcome';
if ($searchResult)         $jsState = 'found';
elseif (!empty($errorMsg)) $jsState = 'notFound';
elseif ($printData)        $jsState = 'print';
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Antrian Farmasi – <?= htmlspecialchars($setting['nama_instansi']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=DM+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
/* ═══════════════════════════════════════════════════
   ROOT & RESET
═══════════════════════════════════════════════════ */
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

:root {
    --navy:        #0b1623;
    --navy-mid:    #122033;
    --navy-light:  #1a3045;
    --teal:        #0ea5a0;
    --teal-bright: #14c9c3;
    --teal-soft:   rgba(14,165,160,.12);
    --teal-glow:   rgba(14,165,160,.25);
    --gold:        #c9a84c;
    --gold-light:  #e2c97a;
    --gold-soft:   rgba(201,168,76,.12);
    --white:       #f5f8fa;
    --muted:       rgba(245,248,250,.45);
    --muted2:      rgba(245,248,250,.25);
    --border:      rgba(245,248,250,.08);
    --card:        rgba(255,255,255,.035);
    --card-hover:  rgba(255,255,255,.065);
    --danger:      #e05555;
    --radius:      16px;
    --radius-sm:   10px;
    --hdr-h:       76px;
    --ftr-h:       50px;
    --trans:       .25s cubic-bezier(.4,0,.2,1);
}

html, body {
    height: 100vh; overflow: hidden;
    font-family: 'DM Sans', sans-serif;
    background: var(--navy);
    color: var(--white);
}

/* ═══════════════════════════════════════════════════
   BACKGROUND — layered mesh + noise
═══════════════════════════════════════════════════ */
body::before {
    content:''; position:fixed; inset:0; z-index:0; pointer-events:none;
    background:
        radial-gradient(ellipse 80% 60% at 0% 0%, rgba(14,165,160,.12) 0%, transparent 60%),
        radial-gradient(ellipse 60% 70% at 100% 100%, rgba(201,168,76,.07) 0%, transparent 55%),
        radial-gradient(ellipse 50% 50% at 55% 50%, rgba(14,165,160,.05) 0%, transparent 65%);
}
/* Fine grid texture */
body::after {
    content:''; position:fixed; inset:0; z-index:0; pointer-events:none;
    background-image:
        linear-gradient(var(--border) 1px, transparent 1px),
        linear-gradient(90deg, var(--border) 1px, transparent 1px);
    background-size: 52px 52px;
    opacity: .6;
}

/* ═══════════════════════════════════════════════════
   LAYOUT SHELL
═══════════════════════════════════════════════════ */
.shell { position:relative; z-index:1; height:100vh; display:flex; flex-direction:column; }

/* ═══════════════════════════════════════════════════
   HEADER
═══════════════════════════════════════════════════ */
.hdr {
    height: var(--hdr-h); flex-shrink:0;
    background: rgba(11,22,35,.92);
    backdrop-filter: blur(24px);
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center;
    padding: 0 36px; gap: 20px;
    position: relative;
}
/* Gold accent line at bottom */
.hdr::after {
    content:''; position:absolute; bottom:0; left:0; right:0; height:1px;
    background: linear-gradient(90deg, transparent 0%, var(--gold) 30%, var(--teal-bright) 70%, transparent 100%);
    opacity: .5;
}

.hdr-brand { display:flex; align-items:center; gap:14px; }
.hdr-emblem {
    width: 46px; height:46px; flex-shrink:0;
    background: linear-gradient(135deg, var(--teal), #097571);
    border-radius: 13px;
    display: flex; align-items:center; justify-content:center;
    box-shadow: 0 0 22px var(--teal-glow);
    font-size: 22px;
}
.hdr-title {
    font-family: 'Archivo Black', sans-serif;
    font-size: 19px; font-weight: 700;
    color: var(--white); line-height: 1.1;
    letter-spacing: -.02em;
}
.hdr-sub { font-size: 11px; color: var(--muted); font-weight: 500; margin-top:3px; letter-spacing:.02em; }

.hdr-divider { width:1px; height:32px; background:var(--border); flex-shrink:0; }

.hdr-meta { display:flex; align-items:center; gap:10px; }
.hdr-pill {
    display: flex; align-items:center; gap:7px;
    padding: 6px 14px;
    background: var(--teal-soft);
    border: 1px solid var(--teal-glow);
    border-radius: 50px;
    font-size: 11.5px; font-weight:600; color: var(--teal-bright);
}
.pulse-dot {
    width:6px; height:6px; border-radius:50%;
    background: var(--teal-bright);
    box-shadow: 0 0 8px var(--teal-bright);
    animation: pulse-anim 2.2s ease-in-out infinite;
}
@keyframes pulse-anim { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.4;transform:scale(1.6)} }

/* Clock (far right) */
.hdr-clock { margin-left:auto; text-align:right; }
.clock-val {
    font-family: 'Archivo Black', sans-serif;
    font-size: 26px; font-weight:700;
    color: var(--gold-light);
    letter-spacing: 2px; line-height:1;
    text-shadow: 0 0 20px rgba(201,168,76,.35);
}
.clock-date { font-size:11px; color:var(--muted); font-weight:500; margin-top:3px; }

/* ═══════════════════════════════════════════════════
   CONTENT
═══════════════════════════════════════════════════ */
.content {
    flex:1; overflow-y:auto;
    padding: 28px 40px;
    display: flex; align-items:center; justify-content:center;
}
.content::-webkit-scrollbar { width:4px; }
.content::-webkit-scrollbar-thumb { background:rgba(14,165,160,.3); border-radius:4px; }

.grid { display:grid; grid-template-columns:1fr 1fr; gap:28px; max-width:1400px; width:100%; align-items:center; }

/* ═══════════════════════════════════════════════════
   LEFT COLUMN
═══════════════════════════════════════════════════ */
.left { display:flex; flex-direction:column; gap:18px; }

.hero-block {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 28px 28px 24px;
    position: relative; overflow:hidden;
    animation: fadeUp .6s var(--trans) both;
}
.hero-block::before {
    content:''; position:absolute; top:-1px; left:0; right:0; height:2px;
    background: linear-gradient(90deg, var(--teal), var(--gold));
    border-radius: 2px 2px 0 0;
}
.hero-block::after {
    content:''; position:absolute; bottom:-40px; right:-40px;
    width:180px; height:180px; border-radius:50%;
    background: radial-gradient(circle, var(--teal-soft) 0%, transparent 70%);
    pointer-events:none;
}
.hero-eyebrow {
    display:inline-flex; align-items:center; gap:7px;
    padding: 4px 12px;
    background: var(--gold-soft);
    border: 1px solid rgba(201,168,76,.25);
    border-radius: 50px;
    font-size:10.5px; font-weight:700; color:var(--gold-light);
    text-transform:uppercase; letter-spacing:.08em;
    margin-bottom:14px;
}
.hero-title {
    font-family: 'Archivo Black', sans-serif;
    font-size: clamp(26px,3vw,40px);
    font-weight:800; line-height:1.15;
    color: var(--white); margin-bottom:12px;
    position: relative; z-index:1;
}
.hero-title span { color: var(--teal-bright); }
.hero-body { font-size:14px; color:var(--muted); line-height:1.7; font-weight:400; position:relative;z-index:1; }
.hero-body strong { color:var(--white); font-weight:600; }

/* DateTime strip */
.dt-strip {
    display: grid; grid-template-columns:1fr 1fr; gap:12px;
    animation: fadeUp .6s .1s var(--trans) both;
}
.dt-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 16px 18px;
    display: flex; align-items:center; gap:14px;
    transition: background var(--trans), border-color var(--trans);
}
.dt-card:hover { background:var(--card-hover); border-color:var(--teal-glow); }
.dt-icon {
    width:40px; height:40px; border-radius:10px;
    background: var(--teal-soft); border:1px solid var(--teal-glow);
    display:flex; align-items:center; justify-content:center;
    color:var(--teal-bright); font-size:18px; flex-shrink:0;
}
.dt-lbl { font-size:10px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; margin-bottom:4px; }
.dt-val { font-size:14px; font-weight:700; color:var(--white); line-height:1; }

/* Notice card */
.notice {
    display:flex; align-items:flex-start; gap:14px;
    background: var(--gold-soft);
    border: 1px solid rgba(201,168,76,.2);
    border-radius: var(--radius-sm);
    padding:16px 18px;
    animation: fadeUp .6s .2s var(--trans) both;
}
.notice i { font-size:18px; color:var(--gold-light); flex-shrink:0; margin-top:1px; }
.notice p { font-size:12.5px; color:rgba(226,201,122,.85); line-height:1.65; font-weight:400; }
.notice p strong { color:var(--gold-light); font-weight:600; }

/* ═══════════════════════════════════════════════════
   RIGHT COLUMN
═══════════════════════════════════════════════════ */
.right { display:flex; flex-direction:column; align-items:center; gap:22px; }

/* Icon orb */
.icon-orb {
    width: clamp(110px,12vw,155px); height:clamp(110px,12vw,155px);
    border-radius:50%;
    background: linear-gradient(135deg, var(--teal) 0%, #075e5b 100%);
    display:flex; align-items:center; justify-content:center;
    box-shadow: 0 20px 55px rgba(14,165,160,.35), 0 0 0 1px rgba(14,165,160,.2);
    position:relative;
    animation: float 5s ease-in-out infinite;
}
@keyframes float { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-12px)} }
.icon-orb::before {
    content:''; position:absolute; inset:-12px; border-radius:50%;
    border:1px solid rgba(14,165,160,.2); animation:ripple 3.5s ease-out infinite;
}
.icon-orb::after {
    content:''; position:absolute; inset:-24px; border-radius:50%;
    border:1px solid rgba(14,165,160,.1); animation:ripple 3.5s .8s ease-out infinite;
}
@keyframes ripple { 0%{opacity:.7;transform:scale(1)} 100%{opacity:0;transform:scale(1.25)} }
.icon-orb i { font-size:clamp(46px,5.5vw,68px); color:#fff; filter:drop-shadow(0 3px 10px rgba(0,0,0,.25)); position:relative;z-index:1; }

/* ── Search card ── */
.search-card {
    background: rgba(255,255,255,.04);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 26px 26px 22px;
    width:100%; max-width:500px;
    animation: fadeUp .5s .15s var(--trans) both;
    position:relative;
}
.search-card::before {
    content:''; position:absolute; top:-1px; left:20%; right:20%; height:1px;
    background:linear-gradient(90deg,transparent,var(--teal),transparent);
}
.sc-title {
    display:flex; align-items:center; gap:10px;
    font-family:'Archivo Black',sans-serif;
    font-size:17px; font-weight:700; color:var(--white);
    margin-bottom:20px;
}
.sc-title i { color:var(--teal-bright); }

.field-lbl { font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; display:block; margin-bottom:8px; }
.field-inp {
    width:100%; height:50px;
    background: rgba(255,255,255,.06);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 0 16px;
    font-size:14px; font-weight:500; color:var(--white);
    font-family:'DM Sans',sans-serif;
    outline:none; transition:all var(--trans);
    margin-bottom:16px;
}
.field-inp::placeholder { color:var(--muted2); }
.field-inp:focus { border-color:var(--teal); background:rgba(14,165,160,.08); box-shadow:0 0 0 3px var(--teal-soft); }

/* ── Buttons ── */
.btn-stack { display:flex; flex-direction:column; gap:10px; }
.btn {
    height:48px; border:none; border-radius:var(--radius-sm);
    font-family:'DM Sans',sans-serif; font-size:14px; font-weight:700;
    display:flex; align-items:center; justify-content:center; gap:9px;
    cursor:pointer; transition:all var(--trans);
    position:relative; overflow:hidden; letter-spacing:.01em;
    text-decoration:none;
}
.btn::after {
    content:''; position:absolute;
    inset:0; background:rgba(255,255,255,.08);
    opacity:0; transition:opacity var(--trans);
}
.btn:hover::after { opacity:1; }
.btn:hover { transform:translateY(-2px); }
.btn:active { transform:translateY(0); }

.btn-primary {
    background:linear-gradient(135deg,var(--teal) 0%,#097571 100%);
    color:#fff; box-shadow:0 8px 24px rgba(14,165,160,.3);
}
.btn-primary:hover { box-shadow:0 12px 32px rgba(14,165,160,.45); color:#fff; }

.btn-secondary {
    background:var(--gold-soft);
    border:1px solid rgba(201,168,76,.25);
    color:var(--gold-light);
}
.btn-secondary:hover { background:rgba(201,168,76,.18); border-color:rgba(201,168,76,.4); color:var(--gold-light); }

.btn-ghost {
    background:var(--card);
    border:1px solid var(--border);
    color:var(--muted);
}
.btn-ghost:hover { background:var(--card-hover); color:var(--white); border-color:rgba(245,248,250,.15); }

/* ── Alert ── */
.alert-error {
    display:flex; align-items:flex-start; gap:12px;
    background:rgba(224,85,85,.1); border:1px solid rgba(224,85,85,.3);
    border-radius:var(--radius-sm); padding:14px 16px;
    width:100%; max-width:500px;
    animation:shake .45s var(--trans);
}
@keyframes shake { 0%,100%{transform:translateX(0)} 25%{transform:translateX(-8px)} 75%{transform:translateX(8px)} }
.alert-error i { color:var(--danger); font-size:17px; flex-shrink:0; margin-top:1px; }
.alert-error p { font-size:13px; color:rgba(240,160,160,.9); font-weight:500; line-height:1.5; }

/* ── Results list ── */
.results-wrap {
    background:rgba(255,255,255,.04); border:1px solid var(--border);
    border-radius:var(--radius); padding:22px;
    width:100%; max-width:520px;
    max-height:60vh; overflow-y:auto;
    animation:fadeUp .4s var(--trans) both;
}
.results-wrap::-webkit-scrollbar { width:4px; }
.results-wrap::-webkit-scrollbar-thumb { background:var(--teal-glow); border-radius:4px; }
.results-wrap h3 {
    font-family:'Archivo Black',sans-serif;
    font-size:16px; font-weight:700; color:var(--white);
    margin-bottom:16px; text-align:center;
}
.result-btn {
    width:100%; text-align:left; cursor:pointer;
    background:rgba(255,255,255,.04); border:1px solid var(--border);
    border-radius:var(--radius-sm); padding:14px 16px;
    margin-bottom:10px; transition:all var(--trans);
    display:block;
}
.result-btn:hover {
    background:rgba(14,165,160,.1); border-color:var(--teal-glow);
    transform:translateX(4px);
}
.rb-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; }
.rb-name { font-size:14px; font-weight:700; color:var(--white); }
.rb-badge {
    font-size:10px; font-weight:700; padding:3px 10px;
    border-radius:20px; letter-spacing:.04em;
}
.rb-badge.racikan  { background:rgba(224,85,85,.2); color:#f48686; border:1px solid rgba(224,85,85,.3); }
.rb-badge.non      { background:var(--teal-soft); color:var(--teal-bright); border:1px solid var(--teal-glow); }
.rb-meta { display:grid; grid-template-columns:auto 1fr; gap:3px 10px; font-size:11.5px; }
.rb-lbl  { color:var(--muted); font-weight:600; }
.rb-val  { color:rgba(245,248,250,.75); }

/* ═══════════════════════════════════════════════════
   FOOTER
═══════════════════════════════════════════════════ */
.ftr {
    height:var(--ftr-h); flex-shrink:0;
    background:rgba(11,22,35,.9); backdrop-filter:blur(16px);
    border-top:1px solid var(--border);
    display:flex; align-items:center;
    padding:0 36px; gap:28px;
}
.ftr-item { display:flex; align-items:center; gap:8px; font-size:11.5px; color:var(--muted); font-weight:500; }
.ftr-item i { font-size:13px; color:var(--teal); }
.ftr-item strong { color:rgba(245,248,250,.7); font-weight:600; }
.ftr-sep { width:1px; height:18px; background:var(--border); }
.ftr-copy { margin-left:auto; font-size:11px; color:var(--muted2); }
.ftr-copy span { color:var(--teal-bright); font-weight:600; }

/* ═══════════════════════════════════════════════════
   VIRTUAL KEYBOARD
═══════════════════════════════════════════════════ */
.vkbd {
    position:fixed; bottom:0; left:0; right:0; z-index:9000;
    background:rgba(11,22,35,.97); backdrop-filter:blur(24px);
    border-top:1px solid var(--border);
    padding:14px 24px 20px;
    box-shadow:0 -16px 50px rgba(0,0,0,.5);
    display:none;
    animation:slideUp .3s var(--trans);
}
@keyframes slideUp { from{transform:translateY(100%)} to{transform:translateY(0)} }
.vkbd::before {
    content:''; position:absolute; top:0; left:15%; right:15%; height:1px;
    background:linear-gradient(90deg,transparent,var(--teal),var(--gold),transparent);
    opacity:.4;
}

.vkbd-bar {
    display:flex; align-items:center; justify-content:space-between;
    margin-bottom:12px;
}
.vkbd-title { font-size:13px; font-weight:700; color:var(--teal-bright); display:flex; align-items:center; gap:8px; }
.vkbd-close {
    width:34px; height:34px; border-radius:8px;
    background:rgba(224,85,85,.15); border:1px solid rgba(224,85,85,.3);
    color:var(--danger); font-size:15px; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    transition:all var(--trans);
}
.vkbd-close:hover { background:var(--danger); color:#fff; }

/* Preview */
.vkbd-preview {
    background:rgba(255,255,255,.05); border:1px solid var(--border);
    border-radius:var(--radius-sm); padding:10px 16px;
    margin-bottom:12px; display:flex; align-items:center; gap:12px; min-height:46px;
}
.vp-label { font-size:10px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.07em; flex-shrink:0; }
.vp-text  { font-size:16px; font-weight:600; color:var(--white); flex:1; word-break:break-all; }
.vp-cursor { display:inline-block; width:2px; height:1.1em; background:var(--teal-bright); margin-left:2px; vertical-align:middle; animation:blink .8s step-end infinite; }
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:0} }

/* Keys */
.key-row { display:flex; justify-content:center; gap:6px; margin-bottom:6px; }
.key {
    min-width:50px; height:46px; border-radius:9px;
    background:rgba(255,255,255,.07); border:1px solid var(--border);
    color:var(--white); font-size:15px; font-weight:700;
    cursor:pointer; transition:all var(--trans);
    font-family:'DM Sans',sans-serif;
    display:flex; align-items:center; justify-content:center; gap:5px;
}
.key:hover  { background:var(--teal-soft); border-color:var(--teal-glow); color:var(--teal-bright); transform:translateY(-2px); }
.key:active { transform:translateY(0); }
.key-space { min-width:140px; background:rgba(14,165,160,.12); border-color:var(--teal-glow); color:var(--teal-bright); font-size:12px; }
.key-del   { min-width:100px; background:rgba(224,85,85,.12); border-color:rgba(224,85,85,.25); color:#f48686; font-size:12px; }
.key-del:hover { background:rgba(224,85,85,.22); border-color:rgba(224,85,85,.4); color:#ffa0a0; }
.key-clr   { min-width:90px; background:rgba(201,168,76,.1); border-color:rgba(201,168,76,.2); color:var(--gold-light); font-size:12px; }
.key-clr:hover { background:rgba(201,168,76,.2); }

/* body padding when keyboard open */
body.kbd-open .content { padding-bottom:300px; }

/* ═══════════════════════════════════════════════════
   ANIMATIONS
═══════════════════════════════════════════════════ */
@keyframes fadeUp {
    from { opacity:0; transform:translateY(18px); }
    to   { opacity:1; transform:translateY(0); }
}

/* ═══════════════════════════════════════════════════
   PRINT AREA
═══════════════════════════════════════════════════ */
.print-area { display:none; }

@media print {
    @page { size:7.5cm 11cm; margin:0; }
    body > *:not(.print-area) { display:none !important; }
    .print-area { display:block !important; width:7.5cm; height:11cm; }
}

/* ═══════════════════════════════════════════════════
   RESPONSIVE
═══════════════════════════════════════════════════ */
@media(max-width:1024px) {
    .grid { grid-template-columns:1fr; }
    .left { display:none; }
    .content { padding:20px; }
}
@media(max-width:600px) {
    .hdr { padding:0 16px; }
    .hdr-meta { display:none; }
    .content { padding:16px; }
    .ftr { gap:14px; padding:0 16px; }
}
</style>
</head>
<body>

<div class="shell">

  <!-- ════════════ HEADER ════════════ -->
  <header class="hdr">
    <div class="hdr-brand">
      <div class="hdr-emblem">💊</div>
      <div>
        <div class="hdr-title"><?= htmlspecialchars($setting['nama_instansi']) ?></div>
        <div class="hdr-sub"><?= htmlspecialchars($setting['alamat_instansi']) ?> &middot; <?= htmlspecialchars($setting['kabupaten']) ?></div>
      </div>
    </div>

    <div class="hdr-divider"></div>

    <div class="hdr-meta">
      <div class="hdr-pill"><span class="pulse-dot"></span>Sistem Online</div>
      <div class="hdr-pill" style="background:var(--gold-soft);border-color:rgba(201,168,76,.25);color:var(--gold-light);">
        <i class="bi bi-capsule-pill" style="font-size:12px;"></i> Farmasi Rawat Jalan
      </div>
    </div>

    <div class="hdr-clock">
      <div class="clock-val" id="elTime">00:00:00</div>
      <div class="clock-date" id="elDate">&mdash;</div>
    </div>
  </header>

  <!-- ════════════ CONTENT ════════════ -->
  <main class="content">
    <div class="grid">

      <!-- LEFT ─────────────────────── -->
      <div class="left">
        <div class="hero-block">
          <div class="hero-eyebrow"><i class="bi bi-award-fill"></i> Layanan Digital Mandiri</div>
          <h1 class="hero-title">Antrian<br><span>Farmasi</span></h1>
          <p class="hero-body">
            Masukkan <strong>No. RM</strong>, <strong>Nama Pasien</strong>, atau <strong>No. Rawat</strong>
            untuk mendapatkan nomor antrian farmasi Anda hari ini secara mandiri.
          </p>
        </div>

        <div class="dt-strip">
          <div class="dt-card">
            <div class="dt-icon"><i class="bi bi-calendar3"></i></div>
            <div>
              <div class="dt-lbl">Tanggal</div>
              <div class="dt-val" id="elDateCard">—</div>
            </div>
          </div>
          <div class="dt-card">
            <div class="dt-icon"><i class="bi bi-clock"></i></div>
            <div>
              <div class="dt-lbl">Waktu</div>
              <div class="dt-val" id="elTimeCard">—</div>
            </div>
          </div>
        </div>

        <div class="notice">
          <i class="bi bi-info-circle-fill"></i>
          <p><strong>Petunjuk:</strong> Gunakan keyboard virtual di layar sentuh, lalu klik <em>Cari Data</em>. Pilih nama Anda dan cetak karcis antrian.</p>
        </div>
      </div>

      <!-- RIGHT ────────────────────── -->
      <div class="right">

        <?php if (!$printData): ?>

        <div class="icon-orb"><i class="bi bi-capsule"></i></div>

        <?php if (!empty($errorMsg)): ?>
        <div class="alert-error">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <p><?= htmlspecialchars($errorMsg) ?></p>
        </div>
        <?php endif; ?>

        <!-- Form Pencarian -->
        <?php if ($showSearch): ?>
        <form method="post" class="search-card" id="formCari">
          <div class="sc-title"><i class="bi bi-search"></i> Cari Data Pasien</div>
          <label class="field-lbl">No. RM / Nama Pasien / No. Rawat</label>
          <input type="text" name="keyword" id="inputCari" class="field-inp"
                 placeholder="Contoh: 000057 atau Budi Santoso"
                 value="<?= htmlspecialchars($_POST['keyword'] ?? '') ?>"
                 autocomplete="off" autofocus>
          <div class="btn-stack">
            <button type="submit" name="cari" class="btn btn-primary">
              <i class="bi bi-search"></i> Cari Data
            </button>
            <button type="button" class="btn btn-secondary" onclick="toggleKeyboard()">
              <i class="bi bi-keyboard-fill"></i> Keyboard Virtual
            </button>
            <a href="anjungan.php" class="btn btn-ghost">
              <i class="bi bi-arrow-left-circle"></i> Kembali ke Menu
            </a>
          </div>
        </form>
        <?php endif; ?>

        <!-- Hasil Pencarian -->
        <?php if ($searchResult): ?>
        <div class="results-wrap">
          <h3>📋 Pilih Nama Anda <span style="font-size:13px;font-weight:500;color:var(--muted);">(<?= count($searchResult) ?> ditemukan)</span></h3>
          <?php foreach ($searchResult as $item): ?>
          <form method="post" style="margin:0;">
            <input type="hidden" name="no_rawat" value="<?= htmlspecialchars($item['no_rawat']) ?>">
            <button type="submit" name="cetak" class="result-btn">
              <div class="rb-head">
                <div class="rb-name"><?= htmlspecialchars($item['nm_pasien']) ?></div>
                <div class="rb-badge <?= $item['jenis_resep']==='Racikan'?'racikan':'non' ?>">
                  <?= htmlspecialchars($item['jenis_resep']) ?>
                </div>
              </div>
              <div class="rb-meta">
                <div class="rb-lbl">No. RM</div><div class="rb-val"><?= htmlspecialchars($item['no_rkm_medis']) ?></div>
                <div class="rb-lbl">No. Resep</div><div class="rb-val"><?= htmlspecialchars($item['no_resep']) ?></div>
                <div class="rb-lbl">Poli</div><div class="rb-val"><?= htmlspecialchars($item['nm_poli']) ?></div>
                <div class="rb-lbl">Jam</div><div class="rb-val"><?= date('H:i',strtotime($item['jam_peresepan'])) ?> WIB</div>
              </div>
            </button>
          </form>
          <?php endforeach; ?>
          <div class="btn-stack" style="margin-top:14px;">
            <a href="antrian_farmasi.php" class="btn btn-secondary"><i class="bi bi-arrow-counterclockwise"></i> Cari Lagi</a>
            <a href="anjungan.php" class="btn btn-ghost"><i class="bi bi-arrow-left-circle"></i> Kembali ke Menu</a>
          </div>
        </div>
        <?php endif; ?>

        <?php endif; // !printData ?>

      </div><!-- /right -->
    </div><!-- /grid -->
  </main>

  <!-- ════════════ FOOTER ════════════ -->
  <footer class="ftr">
    <div class="ftr-item"><i class="bi bi-telephone-fill"></i><strong><?= htmlspecialchars($setting['kontak']) ?></strong></div>
    <div class="ftr-sep"></div>
    <div class="ftr-item"><i class="bi bi-envelope-fill"></i><?= htmlspecialchars($setting['email']) ?></div>
    <div class="ftr-sep"></div>
    <div class="ftr-item"><i class="bi bi-geo-alt-fill"></i><?= htmlspecialchars($setting['kabupaten']) ?>, <?= htmlspecialchars($setting['propinsi']) ?></div>
    <div class="ftr-copy">&copy; <?= date('Y') ?> &nbsp;<span><?= htmlspecialchars($setting['nama_instansi']) ?></span>&nbsp; &middot; Powered by <span>MediFix</span></div>
  </footer>

</div><!-- /shell -->

<!-- ════════════ VIRTUAL KEYBOARD ════════════ -->
<div class="vkbd" id="vKbd">
  <div class="vkbd-bar">
    <div class="vkbd-title"><i class="bi bi-keyboard-fill"></i> Keyboard Virtual</div>
    <button class="vkbd-close" onclick="closeKeyboard()"><i class="bi bi-x-lg"></i></button>
  </div>
  <div class="vkbd-preview">
    <span class="vp-label">Input:</span>
    <span class="vp-text" id="kbdPreview"><span class="vp-cursor"></span></span>
  </div>
  <div id="kr1" class="key-row"></div>
  <div id="kr2" class="key-row"></div>
  <div id="kr3" class="key-row"></div>
  <div id="kr4" class="key-row"></div>
  <div id="kr5" class="key-row"></div>
</div>

<!-- ════════════ PRINT AREA ════════════ -->
<?php if ($printData): ?>
<div class="print-area" id="printArea">
  <div style="width:7.5cm;height:11cm;padding:.38cm .3cm;font-family:'Courier New',monospace;background:#fff;display:flex;flex-direction:column;justify-content:space-between;">

    <div style="text-align:center;">
      <div style="display:inline-block;width:36px;height:36px;border-radius:50%;background:#0ea5a0;margin-bottom:.2cm;font-size:18px;line-height:36px;color:#fff;">💊</div>
      <h2 style="font-size:14px;font-weight:900;margin:0 0 .12cm;color:#000;text-transform:uppercase;letter-spacing:.3px;line-height:1.2;"><?= htmlspecialchars($setting['nama_instansi']) ?></h2>
      <p style="font-size:8px;margin:.06cm 0;color:#444;line-height:1.3;"><?= htmlspecialchars($setting['alamat_instansi']) ?></p>
      <p style="font-size:8px;margin:.04cm 0;color:#444;">Telp: <?= htmlspecialchars($setting['kontak']) ?></p>
    </div>

    <div style="border-top:1px dashed #999;margin:.14cm 0;"></div>

    <div style="text-align:center;margin:.2cm 0;">
      <p style="font-size:10px;font-weight:700;margin:0 0 .15cm;color:#000;text-transform:uppercase;letter-spacing:.5px;">Nomor Antrian Farmasi</p>
      <div style="background:linear-gradient(135deg,#0ea5a0,#075e5b);padding:.35cm .2cm;border-radius:8px;margin:.08cm 0;box-shadow:0 2px 6px rgba(0,0,0,.15);">
        <h1 style="font-size:52px;margin:0;font-weight:900;color:#fff;letter-spacing:4px;text-shadow:0 2px 6px rgba(0,0,0,.25);"><?= htmlspecialchars($printData['no_antrian_farmasi']) ?></h1>
      </div>
      <span style="display:inline-block;padding:.1cm .35cm;margin:.12cm 0;border-radius:20px;font-size:9.5px;font-weight:800;<?= $printData['jenis_resep']==='Racikan'?'background:#c0392b':'background:#0ea5a0' ?>;color:#fff;">
        <?= htmlspecialchars($printData['jenis_resep']) ?>
      </span>
    </div>

    <div style="border-top:1px dashed #999;margin:.14cm 0;"></div>

    <div style="margin:.1cm 0;padding:0 .05cm;">
      <p style="font-size:9px;margin:.09cm 0;color:#333;line-height:1.3;"><strong>No. RM :</strong> <?= htmlspecialchars($printData['no_rkm_medis']) ?></p>
      <p style="font-size:9px;margin:.09cm 0;color:#333;line-height:1.3;"><strong>Nama   :</strong> <?= htmlspecialchars($printData['nm_pasien']) ?></p>
      <p style="font-size:9px;margin:.09cm 0;color:#333;line-height:1.3;"><strong>Resep  :</strong> <?= htmlspecialchars($printData['no_resep']) ?></p>
      <p style="font-size:9px;margin:.09cm 0;color:#333;line-height:1.3;"><strong>Poli   :</strong> <?= htmlspecialchars($printData['nm_poli']) ?></p>
    </div>

    <div style="border-top:1px dashed #999;margin:.12cm 0;"></div>

    <div style="text-align:center;">
      <p style="font-size:8.5px;margin:.1cm 0;color:#333;line-height:1.4;">Silakan tunggu panggilan di layar display antrian</p>
      <?php if ($printData['jenis_resep']==='Racikan'): ?>
      <p style="font-size:8px;margin:.1cm 0;color:#c0392b;font-weight:700;">⚠ Resep racikan ± 15–60 menit</p>
      <?php endif; ?>
      <div style="border-top:1px solid #ddd;margin:.1cm 0;"></div>
      <p style="font-size:7.5px;margin:.06cm 0;color:#777;">Dicetak: <?= date('d/m/Y H:i:s') ?></p>
      <p style="font-size:9px;margin:.08cm 0 0;font-weight:700;color:#000;letter-spacing:.3px;">SEMOGA LEKAS SEMBUH</p>
    </div>

  </div>
</div>
<?php endif; ?>

<script>
// ════════════ CLOCK ════════════
const HARI  = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
const BULAN = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
function pad2(n){ return String(n).padStart(2,'0'); }

function updateClock(){
    const now = new Date();
    const timeStr = pad2(now.getHours())+':'+pad2(now.getMinutes())+':'+pad2(now.getSeconds());
    const dateStr = HARI[now.getDay()]+', '+now.getDate()+' '+BULAN[now.getMonth()]+' '+now.getFullYear();

    const et = document.getElementById('elTime');    if(et) et.textContent = timeStr;
    const ed = document.getElementById('elDate');    if(ed) ed.textContent = dateStr;
    const ec = document.getElementById('elTimeCard');if(ec) ec.textContent = timeStr;
    const ef = document.getElementById('elDateCard');if(ef) ef.textContent = dateStr;
}
updateClock(); setInterval(updateClock, 1000);

// ════════════ VOICE ════════════
const jsState = "<?= $jsState ?>";
<?php if ($printData): ?>
const printNomor = "<?= htmlspecialchars($printData['no_antrian_farmasi']) ?>";
const printNama  = "<?= htmlspecialchars(addslashes($printData['nm_pasien'])) ?>";
const printJenis = "<?= htmlspecialchars($printData['jenis_resep']) ?>";
<?php else: ?>
const printNomor=''; const printNama=''; const printJenis='';
<?php endif; ?>
<?php if ($searchResult): ?>
const foundCount = <?= count($searchResult) ?>;
<?php else: ?>
const foundCount = 0;
<?php endif; ?>

function speak(text, delay=0){
    if (!('speechSynthesis' in window)) return;
    const go = () => {
        window.speechSynthesis.cancel();
        const utt = new SpeechSynthesisUtterance(text);
        utt.lang='id-ID'; utt.rate=0.9; utt.pitch=1.0; utt.volume=1.0;
        const voices = window.speechSynthesis.getVoices();
        const v = voices.find(v => v.lang.includes('id'));
        if (v) utt.voice = v;
        window.speechSynthesis.speak(utt);
    };
    delay > 0 ? setTimeout(go, delay) : go();
}
if ('speechSynthesis' in window) window.speechSynthesis.onvoiceschanged = () => window.speechSynthesis.getVoices();

window.addEventListener('load', () => {
    const skip = sessionStorage.getItem('skipVoiceAfterPrint') === '1';
    if (skip){ sessionStorage.removeItem('skipVoiceAfterPrint'); const inp=document.getElementById('inputCari'); if(inp) inp.focus(); return; }

    setTimeout(() => {
        if      (jsState === 'welcome')  speak("Selamat datang di layanan antrian farmasi. Silakan ketik Nomor Rekam Medis, Nama Pasien, atau Nomor Rawat, kemudian klik tombol Cari Data.");
        else if (jsState === 'found')    speak(`Data ditemukan. Terdapat ${foundCount} data. Silakan klik nama Anda untuk mencetak karcis antrian farmasi.`);
        else if (jsState === 'notFound') speak("Maaf, data tidak ditemukan. Pastikan Anda sudah mendapatkan resep dari dokter hari ini.");
        else if (jsState === 'print')    speak(`Nomor antrian farmasi Anda adalah ${printNomor.split('').join(' ')}. Untuk pasien ${printNama} resep ${printJenis}. Silakan tunggu panggilan. Semoga lekas sembuh.`);
        const inp=document.getElementById('inputCari'); if(inp) inp.focus();
    }, 600);
});

// ════════════ VIRTUAL KEYBOARD ════════════
const vKbd   = document.getElementById('vKbd');
const inputEl = document.getElementById('inputCari');

const rows = [
    ['1','2','3','4','5','6','7','8','9','0'],
    ['Q','W','E','R','T','Y','U','I','O','P'],
    ['A','S','D','F','G','H','J','K','L'],
    ['Z','X','C','V','B','N','M'],
    ['SPASI','HAPUS','BERSIHKAN']
];

function buildKbd(){
    const ids=['kr1','kr2','kr3','kr4','kr5'];
    rows.forEach((keys,i)=>{
        const row=document.getElementById(ids[i]); if(!row) return;
        keys.forEach(k=>{
            const b=document.createElement('button');
            b.type='button';
            if (k==='SPASI')      { b.className='key key-space'; b.innerHTML='<i class="bi bi-space"></i> Spasi'; b.style.minWidth='140px'; }
            else if (k==='HAPUS') { b.className='key key-del';   b.innerHTML='<i class="bi bi-backspace-fill"></i> Hapus'; }
            else if (k==='BERSIHKAN') { b.className='key key-clr'; b.innerHTML='<i class="bi bi-x-lg"></i> Bersihkan'; }
            else                  { b.className='key'; b.textContent=k; }
            b.addEventListener('click',()=>pressKey(k));
            row.appendChild(b);
        });
    });
}

function pressKey(k){
    if (!inputEl) return;
    if      (k==='SPASI')     inputEl.value += ' ';
    else if (k==='HAPUS')     inputEl.value  = inputEl.value.slice(0,-1);
    else if (k==='BERSIHKAN') { inputEl.value=''; speak("Kolom dibersihkan."); }
    else                      inputEl.value += k;
    inputEl.focus(); updatePreview();
}

function updatePreview(){
    const p = document.getElementById('kbdPreview'); if(!p) return;
    const v = inputEl ? inputEl.value : '';
    p.innerHTML = v
        ? `<span style="color:var(--white)">${v}</span><span class="vp-cursor"></span>`
        : `<span class="vp-cursor"></span>`;
}

function toggleKeyboard(){
    vKbd.style.display = vKbd.style.display==='block' ? 'none' : 'block';
    if (vKbd.style.display==='block'){
        document.body.classList.add('kbd-open');
        requestAnimationFrame(()=>{ if(inputEl){ inputEl.focus(); setTimeout(()=>inputEl.scrollIntoView({behavior:'smooth',block:'center'}),300); } updatePreview(); });
        speak("Keyboard virtual dibuka.");
    } else { closeKeyboard(); }
}

function closeKeyboard(){
    vKbd.style.display='none';
    document.body.classList.remove('kbd-open');
    speak("Keyboard ditutup.");
}

if (inputEl) inputEl.addEventListener('input', updatePreview);
buildKbd();

// ════════════ AUTO PRINT ════════════
<?php if ($printData): ?>
window.addEventListener('load', () => {
    setTimeout(() => {
        window.print();
        const doRedirect = () => {
            sessionStorage.setItem('skipVoiceAfterPrint','1');
            window.speechSynthesis.cancel();
            window.location.href = 'antrian_farmasi.php';
        };
        if ('onafterprint' in window) window.onafterprint = doRedirect;
        else setTimeout(doRedirect, 2000);
    }, 1800);
});
<?php endif; ?>
</script>
</body>
</html>