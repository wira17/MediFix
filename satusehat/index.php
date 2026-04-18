<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
date_default_timezone_set('Asia/Jakarta');
include '../koneksi2.php';
include '../koneksi.php';

// ── Nama RS ───────────────────────────────────────────────────────────────────
try {
    $namaRS = $pdo_simrs->query("SELECT nama_instansi FROM setting LIMIT 1")->fetchColumn() ?: 'Nama Rumah Sakit';
} catch (Exception $e) { $namaRS = 'Nama Rumah Sakit'; }

// ── Tanggal Indonesia ─────────────────────────────────────────────────────────
$hari_map  = ['Sunday'=>'Minggu','Monday'=>'Senin','Tuesday'=>'Selasa','Wednesday'=>'Rabu',
              'Thursday'=>'Kamis','Friday'=>'Jumat','Saturday'=>'Sabtu'];
$bulan_map = ['January'=>'Januari','February'=>'Februari','March'=>'Maret','April'=>'April',
              'May'=>'Mei','June'=>'Juni','July'=>'Juli','August'=>'Agustus',
              'September'=>'September','October'=>'Oktober','November'=>'November','December'=>'Desember'];
$tanggalLengkap = ($hari_map[date('l')] ?? date('l')).', '.date('d').' '.($bulan_map[date('F')] ?? date('F')).' '.date('Y');

// ── SatuSehat KYC ─────────────────────────────────────────────────────────────
$kyc_url  = '';
$kyc_error = '';
try {
    $init = parse_ini_file("satusehat.ini");
    $client_id     = $init["client_id"];
    $client_secret = $init["client_secret"];
    $auth_url      = $init["auth_url"];
    $api_url       = $init["api_url"];
    $environment   = $init["environment"];
    include('auth.php');
    include('function.php');
    $agent_name = 'ISI NAMA PETUGAS';
    $agent_nik  = 'ISI NIK KTP PETUGAS';
    $auth_result = authenticateWithOAuth2($client_id, $client_secret, $auth_url);
    $json = generateUrl($agent_name, $agent_nik, $auth_result, $api_url, $environment);
    $validation_web = json_decode($json, TRUE);
    $kyc_url = $validation_web["data"]["url"] ?? '';
} catch (Exception $e) {
    $kyc_error = $e->getMessage();
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Verifikasi KYC — SatuSehat | <?= htmlspecialchars($namaRS) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
/* ═══════════════════════════════════════════════════
   RESET & ROOT — seragam anjungan.php
═══════════════════════════════════════════════════ */
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

:root {
    --primary:  #00d4aa;
    --secondary:#0088ff;
    --dark:     #0a1929;
    --card-bg:  rgba(255,255,255,0.97);
    --shadow:   rgba(10,25,41,0.14);
    --font-display: 'Archivo Black', sans-serif;
    --font-body:    'DM Sans', sans-serif;
    --ftr-height: 72px;

    /* SatuSehat accent — biru BPJS/Kemenkes */
    --ss-blue:   #009DE0;
    --ss-green:  #00C48C;
    --ss-grad:   linear-gradient(135deg,#009DE0,#00C48C);
    --ss-glow:   rgba(0,157,224,.35);
}

html, body {
    height:100vh; overflow:hidden;
    font-family:var(--font-body);
    background:linear-gradient(160deg,#0a1929 0%,#132f4c 50%,#1e4976 100%);
    position:relative;
    display:flex; flex-direction:column;
}

/* ── Ambient glows ── */
body::before {
    content:''; position:fixed; top:-40%; right:-15%;
    width:75%; height:75%; border-radius:50%;
    background:radial-gradient(circle,rgba(0,157,224,.12) 0%,transparent 65%);
    animation:bgPulse 18s ease-in-out infinite; pointer-events:none; z-index:0;
}
body::after {
    content:''; position:fixed; bottom:-25%; left:-12%;
    width:55%; height:55%; border-radius:50%;
    background:radial-gradient(circle,rgba(0,196,140,.09) 0%,transparent 65%);
    animation:bgPulse 22s ease-in-out infinite reverse; pointer-events:none; z-index:0;
}
@keyframes bgPulse { 0%,100%{transform:scale(1);opacity:1} 50%{transform:scale(1.1);opacity:.7} }

.grid-lines {
    position:fixed; inset:0; z-index:0; pointer-events:none;
    background-image:
        linear-gradient(rgba(255,255,255,.025) 1px,transparent 1px),
        linear-gradient(90deg,rgba(255,255,255,.025) 1px,transparent 1px);
    background-size:52px 52px;
}

/* ═══════════════════════════════════════════════════
   HEADER — identik anjungan.php
═══════════════════════════════════════════════════ */
.header {
    position:relative; z-index:10;
    background:rgba(10,25,41,.95);
    backdrop-filter:blur(20px);
    border-bottom:3px solid var(--primary);
    padding:1.2vh 3vw;
    display:grid; grid-template-columns:auto 1fr auto;
    gap:2vw; align-items:center;
    box-shadow:0 4px 30px rgba(0,212,170,.2);
    flex-shrink:0;
}

.brand-section { display:flex; align-items:center; gap:1.2vw; }
.brand-icon {
    width:clamp(52px,4.5vw,70px); height:clamp(52px,4.5vw,70px);
    background:linear-gradient(135deg,var(--primary),#00aa88);
    border-radius:clamp(10px,.9vw,14px);
    display:flex; align-items:center; justify-content:center;
    font-size:clamp(22px,2.2vw,30px);
    box-shadow:0 8px 24px rgba(0,212,170,.4);
    flex-shrink:0;
}
.brand-icon i { color:#fff; }
.brand-title {
    font-family:var(--font-display);
    font-size:clamp(16px,1.8vw,26px); color:#fff; line-height:1.1;
    background:linear-gradient(135deg,#fff 0%,var(--primary) 100%);
    -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text;
}
.brand-sub {
    font-family:var(--font-body);
    font-size:clamp(10px,.85vw,13px); color:rgba(255,255,255,.55);
    margin-top:.3vh; font-weight:500;
}

/* Back button */
.btn-back {
    display:flex; align-items:center; gap:.6vw;
    background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.12);
    border-radius:50px; padding:.7vh 1.4vw;
    color:rgba(255,255,255,.8); text-decoration:none;
    font-family:var(--font-body); font-size:clamp(11px,1.1vw,14px); font-weight:600;
    transition:all .22s; flex-shrink:0;
}
.btn-back:hover { background:rgba(0,212,170,.15); border-color:var(--primary); color:var(--primary); }
.btn-back i { font-size:clamp(13px,1.2vw,16px); }

.header-center {
    display:flex; align-items:center; justify-content:center; gap:1.5vw; flex-wrap:wrap;
}
.hc-pill {
    display:flex; align-items:center; gap:.6vw;
    background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.1);
    border-radius:50px; padding:.7vh 1.4vw;
    font-family:var(--font-body); font-size:clamp(11px,1.1vw,15px);
    font-weight:600; color:rgba(255,255,255,.85);
}
.hc-pill i { color:var(--primary); font-size:clamp(13px,1.2vw,17px); }

/* ═══════════════════════════════════════════════════
   MAIN CONTENT AREA
═══════════════════════════════════════════════════ */
.main-area {
    position:relative; z-index:1;
    flex:1; min-height:0;
    display:flex; align-items:center; justify-content:center;
    padding:2vh 3vw;
    padding-bottom:calc(var(--ftr-height) + 2vh);
    overflow:hidden;
}

.kyc-wrapper {
    width:100%; max-width:860px;
    animation:fadeInUp .7s cubic-bezier(.4,0,.2,1) both;
}
@keyframes fadeInUp { from{opacity:0;transform:translateY(28px)} to{opacity:1;transform:translateY(0)} }

/* ── SatuSehat Logo badge ── */
.ss-badge {
    display:flex; align-items:center; justify-content:center;
    gap:1.2vw; margin-bottom:2.5vh;
}
.ss-logo-orb {
    width:clamp(60px,5.5vw,80px); height:clamp(60px,5.5vw,80px);
    background:var(--ss-grad);
    border-radius:clamp(14px,1.2vw,20px);
    display:flex; align-items:center; justify-content:center;
    font-size:clamp(26px,2.6vw,38px);
    box-shadow:0 12px 35px var(--ss-glow);
    flex-shrink:0;
}
.ss-logo-orb i { color:#fff; }
.ss-title-block {}
.ss-title {
    font-family:var(--font-display);
    font-size:clamp(18px,2.2vw,30px); color:#fff; line-height:1.1;
    background:linear-gradient(135deg,#fff 0%,var(--ss-blue) 100%);
    -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text;
}
.ss-subtitle {
    font-family:var(--font-body);
    font-size:clamp(10px,.9vw,13px); color:rgba(255,255,255,.5);
    margin-top:.3vh; font-weight:500;
}

/* ── Main KYC Card ── */
.kyc-card {
    background:rgba(15,30,50,.92);
    border:1px solid rgba(255,255,255,.08);
    border-radius:clamp(16px,1.5vw,24px);
    overflow:hidden;
    box-shadow:0 24px 60px rgba(0,0,0,.45), 0 0 0 1px rgba(0,157,224,.08);
}

.kyc-card-header {
    padding:clamp(18px,2vh,28px) clamp(20px,2.5vw,36px);
    background:rgba(0,157,224,.07);
    border-bottom:1px solid rgba(255,255,255,.07);
    display:flex; align-items:center; gap:1.2vw;
}
.kyc-card-header-icon {
    width:42px; height:42px; border-radius:10px;
    background:rgba(0,157,224,.12); border:1px solid rgba(0,157,224,.28);
    display:flex; align-items:center; justify-content:center;
    font-size:20px; color:var(--ss-blue); flex-shrink:0;
}
.kyc-card-header-title {
    font-family:var(--font-display); font-size:clamp(13px,1.2vw,17px); color:#f5f8fa;
}
.kyc-card-header-sub {
    font-family:var(--font-body); font-size:clamp(10px,.85vw,12px);
    color:rgba(245,248,250,.4); margin-top:3px;
}

/* KYC info grid */
.kyc-info-grid {
    display:grid; grid-template-columns:1fr 1fr;
    gap:clamp(12px,1.2vw,18px);
    padding:clamp(18px,2vh,28px) clamp(20px,2.5vw,36px);
    border-bottom:1px solid rgba(255,255,255,.06);
}
.info-item {
    background:rgba(255,255,255,.04);
    border:1px solid rgba(255,255,255,.07);
    border-radius:12px; padding:14px 16px;
    display:flex; align-items:center; gap:12px;
}
.info-item-icon {
    width:36px; height:36px; border-radius:8px;
    display:flex; align-items:center; justify-content:center;
    font-size:17px; flex-shrink:0;
}
.info-item-label {
    font-family:var(--font-body); font-size:10px; font-weight:700;
    color:rgba(245,248,250,.35); text-transform:uppercase; letter-spacing:.07em;
}
.info-item-val {
    font-family:var(--font-body); font-size:clamp(11px,1vw,14px); font-weight:600;
    color:#f5f8fa; margin-top:3px;
}

/* Notice box */
.kyc-notice {
    margin:0 clamp(20px,2.5vw,36px) clamp(16px,1.5vh,22px);
    background:rgba(0,196,140,.07); border:1px solid rgba(0,196,140,.2);
    border-radius:12px; padding:14px 18px;
    display:flex; gap:12px; align-items:flex-start;
}
.kyc-notice i { color:var(--ss-green); font-size:16px; flex-shrink:0; margin-top:2px; }
.kyc-notice p { font-family:var(--font-body); font-size:clamp(11px,.95vw,13px); color:rgba(0,196,140,.85); line-height:1.6; }

/* Buttons area */
.kyc-actions {
    padding:clamp(18px,2vh,28px) clamp(20px,2.5vw,36px);
    display:flex; gap:clamp(12px,1.2vw,18px); flex-wrap:wrap; justify-content:center;
}

.kyc-btn {
    display:flex; align-items:center; justify-content:center; gap:10px;
    padding:0 clamp(24px,2.5vw,40px);
    height:clamp(52px,5.5vh,66px);
    border-radius:clamp(12px,1vw,16px); border:none; cursor:pointer;
    font-family:var(--font-display); font-size:clamp(12px,1.1vw,15px);
    transition:all .3s cubic-bezier(.175,.885,.32,1.275);
    text-decoration:none; position:relative; overflow:hidden;
    flex:1; min-width:220px;
}
.kyc-btn::after {
    content:''; position:absolute; inset:0;
    background:linear-gradient(90deg,transparent,rgba(255,255,255,.15),transparent);
    transform:translateX(-100%); transition:transform .5s;
}
.kyc-btn:hover::after { transform:translateX(100%); }
.kyc-btn:hover { transform:translateY(-4px); }

.kyc-btn-popup {
    background:var(--ss-grad);
    color:#fff;
    box-shadow:0 8px 28px var(--ss-glow);
}
.kyc-btn-popup:hover { box-shadow:0 14px 40px var(--ss-glow); }

.kyc-btn-newtab {
    background:rgba(0,157,224,.12);
    border:1.5px solid rgba(0,157,224,.35);
    color:var(--ss-blue);
}
.kyc-btn-newtab:hover { background:rgba(0,157,224,.22); border-color:var(--ss-blue); }

.kyc-btn i { font-size:clamp(18px,1.8vw,24px); }
.kyc-btn-label {}
.kyc-btn-sublabel {
    font-family:var(--font-body); font-size:clamp(9px,.75vw,11px);
    opacity:.7; font-weight:500; margin-top:1px;
}

/* Error state */
.kyc-error {
    margin:0 clamp(20px,2.5vw,36px) clamp(16px,1.5vh,22px);
    background:rgba(224,85,85,.07); border:1px solid rgba(224,85,85,.2);
    border-radius:12px; padding:16px 18px;
    display:flex; gap:12px; align-items:flex-start;
}
.kyc-error i { color:#f48686; font-size:18px; flex-shrink:0; margin-top:1px; }
.kyc-error p { font-family:var(--font-body); font-size:13px; color:rgba(244,134,134,.85); line-height:1.6; }

/* Step guide */
.kyc-steps {
    display:grid; grid-template-columns:repeat(3,1fr);
    gap:clamp(10px,1vw,16px);
    margin:0 clamp(20px,2.5vw,36px) clamp(18px,1.8vh,26px);
}
.step-item {
    background:rgba(255,255,255,.03);
    border:1px solid rgba(255,255,255,.06);
    border-radius:10px; padding:14px;
    text-align:center;
}
.step-num {
    width:30px; height:30px; border-radius:50%;
    background:var(--ss-grad);
    font-family:var(--font-display); font-size:13px; color:#fff;
    display:flex; align-items:center; justify-content:center;
    margin:0 auto 8px;
    box-shadow:0 4px 14px var(--ss-glow);
}
.step-text {
    font-family:var(--font-body); font-size:clamp(10px,.85vw,12px);
    color:rgba(245,248,250,.55); line-height:1.5;
}

/* ═══════════════════════════════════════════════════
   FOOTER — identik anjungan.php
═══════════════════════════════════════════════════ */
.footer {
    position:fixed; bottom:0; left:0; right:0; z-index:10;
    background:rgba(10,25,41,.97);
    backdrop-filter:blur(20px);
    border-top:3px solid var(--primary);
    overflow:hidden;
    box-shadow:0 -4px 30px rgba(0,212,170,.2);
}
.marquee-row { padding:.55vh 0; overflow:hidden; border-bottom:1px solid rgba(255,255,255,.07); }
.marquee-content {
    display:inline-flex; white-space:nowrap; gap:0;
    font-family:var(--font-body); font-size:clamp(11px,.95vw,14px);
    font-weight:500; color:rgba(255,255,255,.8);
    animation:mqScroll 80s linear infinite;
}
@keyframes mqScroll { 0%{transform:translateX(0)} 100%{transform:translateX(-50%)} }
.mq-item { display:inline-flex; align-items:center; gap:.5vw; padding:0 3vw 0 0; }
.mq-item i { color:var(--primary); }
.footer-copy {
    padding:.38vh 2vw;
    display:flex; align-items:center; justify-content:space-between;
    background:rgba(0,0,0,.38);
}
.footer-copy-left {
    font-family:var(--font-body);
    font-size:.7vw; color:rgba(255,255,255,.4); font-weight:500;
    display:flex; align-items:center; gap:.5vw; flex-wrap:wrap;
}
.footer-copy-left i { color:var(--primary); }
.footer-copy-right {
    font-family:var(--font-body);
    font-size:.7vw; color:rgba(255,255,255,.3); font-weight:500; letter-spacing:.03em;
}
.footer-copy-right span { color:var(--primary); font-weight:700; }

@media(max-width:768px) {
    .header { grid-template-columns:auto auto; }
    .header-center { display:none; }
    .kyc-info-grid { grid-template-columns:1fr; }
    .kyc-steps { grid-template-columns:1fr; }
    .kyc-actions { flex-direction:column; }
}
</style>
</head>
<body>
<div class="grid-lines"></div>

<!-- ═══════════ HEADER ═══════════ -->
<div class="header">
    <div class="brand-section">
        <div class="brand-icon"><i class="bi bi-hospital-fill"></i></div>
        <div>
            <div class="brand-title">ANJUNGAN PASIEN MANDIRI</div>
            <div class="brand-sub"><?= htmlspecialchars($namaRS) ?></div>
        </div>
    </div>

    <div class="header-center">
        <div class="hc-pill">
            <i class="bi bi-calendar-event"></i>
            <span><?= $tanggalLengkap ?></span>
        </div>
        <div class="hc-pill">
            <i class="bi bi-clock-history"></i>
            <span id="elTime">00:00:00</span>
            <span style="font-weight:700;color:rgba(0,212,170,.7);">WIB</span>
        </div>
    </div>

    <a href="../anjungan.php" class="btn-back">
        <i class="bi bi-arrow-left-circle-fill"></i>
        Kembali ke Menu
    </a>
</div>

<!-- ═══════════ MAIN ═══════════ -->
<div class="main-area">
    <div class="kyc-wrapper">

        <!-- Badge -->
        <div class="ss-badge">
            <div class="ss-logo-orb"><i class="bi bi-person-vcard-fill"></i></div>
            <div class="ss-title-block">
                <div class="ss-title">Verifikasi KYC SatuSehat</div>
                <div class="ss-subtitle">Know Your Customer — Platform Kesehatan Nasional</div>
            </div>
        </div>

        <!-- KYC Card -->
        <div class="kyc-card">

            <!-- Card header -->
            <div class="kyc-card-header">
                <div class="kyc-card-header-icon"><i class="bi bi-shield-check-fill"></i></div>
                <div>
                    <div class="kyc-card-header-title">Validasi Identitas Pasien</div>
                    <div class="kyc-card-header-sub">Verifikasi data melalui portal resmi SatuSehat Kemenkes RI</div>
                </div>
            </div>

            <!-- Info grid -->
            <div class="kyc-info-grid">
               
                <div class="info-item">
                    <div class="info-item-icon" style="background:rgba(0,196,140,.12);border:1px solid rgba(0,196,140,.28);color:var(--ss-green);">
                        <i class="bi bi-building-fill-check"></i>
                    </div>
                    <div>
                        <div class="info-item-label">Fasilitas Kesehatan</div>
                        <div class="info-item-val"><?= htmlspecialchars($namaRS) ?></div>
                    </div>
                </div>
             
                <div class="info-item">
                    <div class="info-item-icon" style="background:rgba(167,139,250,.12);border:1px solid rgba(167,139,250,.28);color:#a78bfa;">
                        <i class="bi bi-cloud-check-fill"></i>
                    </div>
                    <div>
                        <div class="info-item-label">Status Koneksi</div>
                        <div class="info-item-val" style="color:<?= $kyc_url?'var(--ss-green)':'#f48686' ?>;">
                            <?= $kyc_url ? '✓ Terhubung' : '✗ Tidak terhubung' ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($kyc_error): ?>
            <!-- Error notice -->
            <div class="kyc-error">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <p>Gagal terhubung ke layanan SatuSehat. Pastikan konfigurasi <code>satusehat.ini</code> sudah benar dan koneksi internet tersedia.<br>
                <small style="opacity:.6">Detail: <?= htmlspecialchars($kyc_error) ?></small></p>
            </div>
            <?php else: ?>
            <!-- Info notice -->
            <div class="kyc-notice">
                <i class="bi bi-info-circle-fill"></i>
                <p>URL validasi berhasil digenerate. Pilih metode tampilan di bawah — <strong>Popup</strong> untuk tampilan jendela kecil, atau <strong>Tab Baru</strong> untuk layar penuh. Pastikan browser mengizinkan popup dari halaman ini.</p>
            </div>
            <?php endif; ?>

            <!-- Step guide -->
            <div class="kyc-steps">
                <div class="step-item">
                    <div class="step-num">1</div>
                    <div class="step-text">Klik tombol KYC di bawah untuk membuka portal verifikasi</div>
                </div>
                <div class="step-item">
                    <div class="step-num">2</div>
                    <div class="step-text">Masukkan NIK pasien yang akan diverifikasi di portal</div>
                </div>
                <div class="step-item">
                    <div class="step-num">3</div>
                    <div class="step-text">Verifikasi selesai — data terekam di sistem SatuSehat</div>
                </div>
            </div>

            <!-- Action buttons -->
            <div class="kyc-actions">
                <?php if ($kyc_url): ?>
                <button class="kyc-btn kyc-btn-popup" onclick="loadFormPopup()">
                    <i class="bi bi-window-fullscreen"></i>
                    <div>
                        <div class="kyc-btn-label">KYC Pasien — Popup</div>
                        <div class="kyc-btn-sublabel">Buka di jendela kecil</div>
                    </div>
                </button>
                <button class="kyc-btn kyc-btn-newtab" onclick="loadFormNewTab()">
                    <i class="bi bi-box-arrow-up-right"></i>
                    <div>
                        <div class="kyc-btn-label">KYC Pasien — Tab Baru</div>
                        <div class="kyc-btn-sublabel">Buka di tab browser</div>
                    </div>
                </button>
                <?php else: ?>
                <button class="kyc-btn" style="background:rgba(255,255,255,.06);color:rgba(245,248,250,.3);cursor:not-allowed;flex:1;" disabled>
                    <i class="bi bi-x-circle-fill"></i>
                    <div>
                        <div class="kyc-btn-label">Layanan Tidak Tersedia</div>
                        <div class="kyc-btn-sublabel">Periksa konfigurasi SatuSehat</div>
                    </div>
                </button>
                <?php endif; ?>
            </div>

        </div><!-- /kyc-card -->
    </div><!-- /kyc-wrapper -->
</div><!-- /main-area -->

<!-- ═══════════ FOOTER ═══════════ -->
<div class="footer">
    <div class="marquee-row">
        <div class="marquee-content">
            <?php
            $items = [
                ['bi-shield-check-fill',    'Verifikasi KYC menggunakan platform resmi SatuSehat — Kementerian Kesehatan RI'],
                ['bi-person-check-fill',     'Pastikan data pasien valid sebelum melakukan tindakan medis'],
                ['bi-lock-fill',             'Data identitas pasien bersifat rahasia dan dilindungi regulasi'],
                ['bi-hospital-fill',         'Terima kasih telah mempercayakan kesehatan Anda kepada '.htmlspecialchars($namaRS)],
                ['bi-info-circle-fill',      'Hubungi petugas jika mengalami kendala verifikasi'],
            ];
            $mq = '';
            foreach ($items as $it)
                $mq .= "<span class='mq-item'><i class='bi {$it[0]}'></i><span>{$it[1]}</span></span>";
            echo $mq.$mq;
            ?>
        </div>
    </div>
    <div class="footer-copy">
        <div class="footer-copy-left">
            <i class="bi bi-shield-check-fill"></i>
            &copy; <?= date('Y') ?>
            <span style="color:var(--primary);font-weight:700;margin:0 .2vw">MediFix</span>
            &mdash; Anjungan Pasien Mandiri &amp; Sistem Antrian
            &nbsp;<span style="color:rgba(255,255,255,.15)">|</span>&nbsp;
            <i class="bi bi-person-fill"></i>
            <span style="color:var(--primary);font-weight:700">M. Wira Satria Buana</span>
            &nbsp;<i class="bi bi-whatsapp" style="color:#25D366;"></i>
            <span style="color:#25D366;font-weight:700">082177846209</span>
        </div>
        <div class="footer-copy-right">Powered by <span>MediFix</span> &middot; v1.0</div>
    </div>
</div>

<script>
// ── Clock ──
function pad2(n){ return String(n).padStart(2,'0'); }
function updateClock(){
    const now = new Date();
    const ts  = pad2(now.getHours())+':'+pad2(now.getMinutes())+':'+pad2(now.getSeconds());
    const el  = document.getElementById('elTime');
    if(el) el.textContent = ts;
}
updateClock(); setInterval(updateClock,1000);

// ── KYC URL ──
const kycUrl = <?= json_encode($kyc_url) ?>;

function loadFormPopup() {
    if (!kycUrl) { alert('URL KYC tidak tersedia.'); return; }
    const params = 'scrollbars=yes,resizable=yes,status=no,location=no,toolbar=no,menubar=no,width=480,height=700,left=200,top=80';
    window.open(kycUrl, 'KYC_SatuSehat', params);
}

function loadFormNewTab() {
    if (!kycUrl) { alert('URL KYC tidak tersedia.'); return; }
    window.open(kycUrl, '_blank');
}

// Set footer height CSS var
(function(){
    const ftr = document.querySelector('.footer');
    if (ftr) document.documentElement.style.setProperty('--ftr-height', ftr.offsetHeight + 'px');
})();

// Auto reload tiap 1 jam
setTimeout(()=>location.reload(), 3600000);
</script>
</body>
</html>