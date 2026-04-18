<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
date_default_timezone_set('Asia/Jakarta');
include 'koneksi2.php';
include 'koneksi.php';

// ── Feature Control ──────────────────────────────────────────────────────────
$fiturAktif = [];
try {
    $stmt = $pdo->query("SELECT kode_fitur, status FROM feature_control");
    foreach ($stmt as $row) $fiturAktif[$row['kode_fitur']] = $row['status'] == 1;
} catch (Exception $e) {}

// ── Simpan Visitor ────────────────────────────────────────────────────────────
if (isset($_POST['saveVisitor'])) {
    $nik  = trim($_POST['nik']  ?? '');
    $nama = trim($_POST['nama'] ?? '');
    if ($nik !== '' && $nama !== '') {
        try {
            $pdo->prepare("INSERT INTO visitor_log (nik, nama) VALUES (?,?)")->execute([$nik, $nama]);
            $_SESSION['visitor_verified'] = true;
            $_SESSION['visitor_name']     = $nama;
            header("Location: cari_pasien.php");
            exit;
        } catch (Exception $e) {}
    }
}

// ── Tanggal Indonesia ─────────────────────────────────────────────────────────
$hari_map  = ['Sunday'=>'Minggu','Monday'=>'Senin','Tuesday'=>'Selasa','Wednesday'=>'Rabu',
              'Thursday'=>'Kamis','Friday'=>'Jumat','Saturday'=>'Sabtu'];
$bulan_map = ['January'=>'Januari','February'=>'Februari','March'=>'Maret','April'=>'April',
              'May'=>'Mei','June'=>'Juni','July'=>'Juli','August'=>'Agustus',
              'September'=>'September','October'=>'Oktober','November'=>'November','December'=>'Desember'];
$tanggalLengkap = ($hari_map[date('l')] ?? date('l')).', '.date('d').' '.($bulan_map[date('F')] ?? date('F')).' '.date('Y');

// ── Nama RS ───────────────────────────────────────────────────────────────────
try {
    $namaRS = $pdo_simrs->query("SELECT nama_instansi FROM setting LIMIT 1")->fetchColumn() ?: 'Nama Rumah Sakit';
} catch (Exception $e) { $namaRS = 'Nama Rumah Sakit'; }

// ── Menu items ────────────────────────────────────────────────────────────────
$menus = [
    [
        'kode'  => 'admisi',
        'href'  => 'antrian_admisi.php',
        'icon'  => 'bi-clipboard-check-fill',
        'grad'  => 'linear-gradient(135deg,#00d4aa,#0088ff)',
        'glow'  => 'rgba(0,212,170,.35)',
        'label' => 'ANTRI ADMISI',
        'sub'   => 'Ambil nomor antrian',
    ],
    [
        'kode'  => 'daftar_poli',
        'href'  => 'daftar_poli.php',
        'icon'  => 'bi-journal-medical',
        'grad'  => 'linear-gradient(135deg,#0088ff,#0055cc)',
        'glow'  => 'rgba(0,136,255,.35)',
        'label' => 'DAFTAR POLI',
        'sub'   => 'Pendaftaran poliklinik',
    ],
    [
        'kode'  => 'antri_farmasi',
        'href'  => 'antrian_farmasi.php',
        'icon'  => 'bi-capsule-pill',
        'grad'  => 'linear-gradient(135deg,#10b981,#059669)',
        'glow'  => 'rgba(16,185,129,.35)',
        'label' => 'ANTRI FARMASI',
        'sub'   => 'Ambil nomor antrian obat',
    ],
    [
        'kode'  => 'cek_bpjs',
        'href'  => 'cek_peserta.php',
        'icon'  => 'bi-person-check-fill',
        'grad'  => 'linear-gradient(135deg,#4facfe,#00f2fe)',
        'glow'  => 'rgba(79,172,254,.35)',
        'label' => 'CEK BPJS',
        'sub'   => 'Verifikasi kepesertaan',
    ],
    [
        'kode'  => 'checkin',
        'href'  => 'checkin_jkn.php',
        'icon'  => 'bi-qr-code-scan',
        'grad'  => 'linear-gradient(135deg,#43e97b,#38f9d7)',
        'glow'  => 'rgba(67,233,123,.35)',
        'label' => 'CHECK-IN JKN',
        'sub'   => 'Konfirmasi kehadiran',
        'blank' => true,
    ],
    [
        'kode'    => 'cari_ranap',
        'href'    => 'javascript:void(0)',
        'icon'    => 'bi-search-heart',
        'grad'    => 'linear-gradient(135deg,#f59e0b,#d97706)',
        'glow'    => 'rgba(245,158,11,.35)',
        'label'   => 'CARI PASIEN R. INAP',
        'sub'     => 'Pencarian data rawat inap',
        'privacy' => true,
    ],
    [
        'kode'  => 'rujukan',
        'href'  => 'cek_rujukan_fktp.php',
        'icon'  => 'bi-arrow-left-right',
        'grad'  => 'linear-gradient(135deg,#a78bfa,#7c3aed)',
        'glow'  => 'rgba(167,139,250,.35)',
        'label' => 'SEP RUJUKAN FKTP',
        'sub'   => 'Surat eligibilitas peserta',
    ],
    [
        'kode'  => 'sep_poli',
        'href'  => 'cek_pasien_poli.php',
        'icon'  => 'bi-calendar2-check-fill',
        'grad'  => 'linear-gradient(135deg,#f43f5e,#e11d48)',
        'glow'  => 'rgba(244,63,94,.35)',
        'label' => 'SEP POLI',
        'sub'   => 'Layanan poliklinik',
    ],
    [
        'kode'  => 'kontrol_rajal',
        'href'  => 'surat_kontrol_rajal.php',
        'icon'  => 'bi-clipboard2-pulse-fill',
        'grad'  => 'linear-gradient(135deg,#fbbf24,#d97706)',
        'glow'  => 'rgba(251,191,36,.35)',
        'label' => 'SURAT KONTROL RAJAL',
        'sub'   => 'Rawat Jalan',
    ],
    [
        'kode'  => 'kontrol_ranap',
        'href'  => 'surat_kontrol_ranap.php',
        'icon'  => 'bi-hospital-fill',
        'grad'  => 'linear-gradient(135deg,#14b8a6,#0d9488)',
        'glow'  => 'rgba(20,184,166,.35)',
        'label' => 'SURAT KONTROL RANAP',
        'sub'   => 'Rawat Inap',
    ],

     [
        'kode'  => 'kyc_satusehat',
        'href'  => 'satusehat/index.php',
        'icon'  => 'bi-person-vcard-fill',
        'grad'  => 'linear-gradient(135deg,#009DE0,#00C48C)',
        'glow'  => 'rgba(0,157,224,.35)',
        'label' => 'VERIFIKASI KYC',
        'sub'   => 'Validasi identitas SatuSehat',
    ],
];
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Anjungan Mandiri — <?= htmlspecialchars($namaRS) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<!-- Font seragam MediFix: Archivo Black + DM Sans -->
<link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
/* ═══════════════════════════════════════════════════
   RESET & ROOT
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
    /* Tinggi footer untuk kompensasi padding menu-area */
    --ftr-height: 72px;
}

html, body {
    height: 100vh; overflow: hidden;
    font-family: var(--font-body);
    background: linear-gradient(160deg, #0a1929 0%, #132f4c 50%, #1e4976 100%);
    position: relative;
    display: flex; flex-direction: column;
}

/* ── Ambient glows ── */
body::before {
    content:''; position:fixed; top:-40%; right:-15%;
    width:75%; height:75%; border-radius:50%;
    background:radial-gradient(circle,rgba(0,212,170,.11) 0%,transparent 65%);
    animation:bgPulse 18s ease-in-out infinite; pointer-events:none; z-index:0;
}
body::after {
    content:''; position:fixed; bottom:-25%; left:-12%;
    width:55%; height:55%; border-radius:50%;
    background:radial-gradient(circle,rgba(0,136,255,.09) 0%,transparent 65%);
    animation:bgPulse 22s ease-in-out infinite reverse; pointer-events:none; z-index:0;
}
@keyframes bgPulse { 0%,100%{transform:scale(1);opacity:1} 50%{transform:scale(1.1);opacity:.7} }

/* Grid texture */
.grid-lines {
    position:fixed; inset:0; z-index:0; pointer-events:none;
    background-image:
        linear-gradient(rgba(255,255,255,.025) 1px,transparent 1px),
        linear-gradient(90deg,rgba(255,255,255,.025) 1px,transparent 1px);
    background-size:52px 52px;
}

/* ═══════════════════════════════════════════════════
   HEADER
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

/* Date/time center */
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

/* Clock */
.header-clock { text-align:right; flex-shrink:0; }
.clock-val {
    font-family:var(--font-display);
    font-size:clamp(22px,2.8vw,40px); color:var(--primary);
    letter-spacing:-.01em; line-height:1;
    text-shadow:0 0 30px rgba(0,212,170,.55);
}
.clock-wib {
    font-family:var(--font-body);
    font-size:clamp(10px,.9vw,13px); color:rgba(0,212,170,.7);
    letter-spacing:.1em; font-weight:700;
    margin-top:.3vh;
}

/* ═══════════════════════════════════════════════════
   MENU AREA
═══════════════════════════════════════════════════ */
.menu-area {
    position:relative; z-index:1;
    flex:1; min-height:0;
    display:flex; align-items:center; justify-content:center;
    /* Padding bawah = tinggi footer agar grid benar-benar center */
    padding:1.5vh 3vw;
    /* Kompensasi footer fixed */
    padding-bottom:calc(var(--ftr-height, 72px) + 1.5vh);
    overflow:hidden;
}

.menu-grid {
    display:grid;
    grid-template-columns:repeat(5,1fr);
    gap:clamp(10px,1.3vw,20px);
    width:100%; max-width:1580px;
    animation:fadeInUp .7s cubic-bezier(.4,0,.2,1) both;
    /* Baris 2 dari bawah: lebih kecil agar 10 kartu muat di layar */
    grid-auto-rows: minmax(0, 1fr);
}
@keyframes fadeInUp { from{opacity:0;transform:translateY(28px)} to{opacity:1;transform:translateY(0)} }

/* ── Menu card ── */
.card-menu {
    background:var(--card-bg);
    border-radius:clamp(12px,1.1vw,18px);
    padding:clamp(14px,1.8vh,22px) clamp(10px,1vw,18px);
    text-align:center; text-decoration:none; display:block;
    box-shadow:0 6px 22px var(--shadow);
    border:2px solid transparent;
    transition:transform .35s cubic-bezier(.175,.885,.32,1.275), box-shadow .35s, border-color .25s;
    position:relative; overflow:hidden;
    cursor:pointer;
    /* Pastikan kartu tidak melebihi tinggi baris grid */
    height:100%;
}
/* Top accent bar */
.card-menu::before {
    content:''; position:absolute; top:0; left:0; right:0; height:4px;
    background:var(--card-grad,linear-gradient(90deg,var(--primary),var(--secondary)));
    border-radius:0;
}
/* Shimmer on hover */
.card-menu::after {
    content:''; position:absolute; inset:0;
    background:linear-gradient(90deg,transparent 0%,rgba(255,255,255,.18) 50%,transparent 100%);
    transform:translateX(-100%); transition:transform .5s ease;
}
.card-menu:hover::after { transform:translateX(100%); }
.card-menu:hover {
    transform:translateY(-8px) scale(1.02);
    box-shadow:0 18px 45px var(--shadow), 0 0 0 1px rgba(0,212,170,.2);
    border-color:rgba(0,212,170,.3);
}
/* Disabled state */
.card-menu.disabled {
    opacity:.45; cursor:not-allowed;
}
.card-menu.disabled:hover { transform:none; box-shadow:0 8px 28px var(--shadow); border-color:transparent; }

/* Icon orb */
.icon-orb {
    width:clamp(52px,4.8vw,72px); height:clamp(52px,4.8vw,72px);
    border-radius:clamp(12px,1vw,17px);
    margin:0 auto clamp(8px,1vh,13px);
    display:flex; align-items:center; justify-content:center;
    font-size:clamp(22px,2.2vw,32px); color:#fff;
    box-shadow:0 5px 18px var(--orb-glow,rgba(0,0,0,.2));
    transition:transform .35s cubic-bezier(.175,.885,.32,1.275);
    background:var(--orb-grad);
}
.card-menu:hover .icon-orb { transform:scale(1.12) rotate(6deg); }

/* Labels */
.menu-label {
    font-family:var(--font-display);
    font-size:clamp(10px,.95vw,14px); color:var(--dark);
    line-height:1.2; margin-bottom:.35vh;
    letter-spacing:.02em;
}
.menu-sub {
    font-family:var(--font-body);
    font-size:clamp(9px,.78vw,11.5px); color:#64748b;
    font-weight:500; line-height:1.3;
}

/* ═══════════════════════════════════════════════════
   FOOTER
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

/* ═══════════════════════════════════════════════════
   MODAL — dark navy (seragam)
═══════════════════════════════════════════════════ */
.modal-overlay {
    display:none; position:fixed; inset:0; z-index:8000;
    background:rgba(7,16,31,.85); backdrop-filter:blur(8px);
    align-items:center; justify-content:center; padding:20px;
}
.modal-overlay.show { display:flex; }
.modal-box {
    background:#0f1e33; border:1px solid rgba(245,248,250,.07);
    border-radius:20px; width:100%; max-width:540px;
    overflow:hidden; box-shadow:0 40px 100px rgba(0,0,0,.6);
    animation:mIn .3s cubic-bezier(.4,0,.2,1);
}
@keyframes mIn { from{opacity:0;transform:translateY(18px) scale(.97)} to{opacity:1;transform:translateY(0) scale(1)} }

.modal-hdr {
    padding:18px 24px; background:rgba(14,165,160,.08);
    border-bottom:1px solid rgba(245,248,250,.07);
    display:flex; align-items:center; gap:12px;
}
.modal-hdr-icon {
    width:40px; height:40px; border-radius:10px;
    background:rgba(14,165,160,.12); border:1px solid rgba(14,165,160,.28);
    display:flex; align-items:center; justify-content:center;
    font-size:19px; flex-shrink:0;
}
.modal-hdr-title {
    font-family:var(--font-display); font-size:15px; font-weight:700;
    color:#f5f8fa; line-height:1.2;
}
.modal-hdr-sub { font-family:var(--font-body); font-size:11px; color:rgba(245,248,250,.4); margin-top:2px; }
.modal-close {
    margin-left:auto; width:32px; height:32px; border-radius:8px;
    background:rgba(224,85,85,.12); border:1px solid rgba(224,85,85,.25);
    color:#f48686; font-size:14px; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    transition:all .2s; flex-shrink:0;
}
.modal-close:hover { background:#e05555; color:#fff; }

.modal-bdy { padding:22px 24px; }
.modal-ftr {
    padding:16px 24px; border-top:1px solid rgba(245,248,250,.07);
    display:flex; gap:10px; justify-content:flex-end;
    background:rgba(11,22,35,.5);
}

/* Notice box inside modal */
.modal-notice {
    background:rgba(14,165,160,.07); border:1px solid rgba(14,165,160,.2);
    border-radius:10px; padding:14px 16px; margin-bottom:18px;
    display:flex; gap:10px;
}
.modal-notice i { color:#14c9c3; font-size:16px; flex-shrink:0; margin-top:1px; }
.modal-notice p { font-family:var(--font-body); font-size:13px; color:rgba(20,201,195,.85); line-height:1.6; }

.modal-warn {
    background:rgba(201,168,76,.07); border:1px solid rgba(201,168,76,.2);
    border-radius:10px; padding:14px 16px; margin-bottom:18px;
    display:flex; gap:10px;
}
.modal-warn i { color:#e2c97a; font-size:16px; flex-shrink:0; margin-top:1px; }
.modal-warn p { font-family:var(--font-body); font-size:13px; color:rgba(226,201,122,.85); line-height:1.6; }
.modal-warn p strong { color:#e2c97a; }

/* Form in modal */
.form-grp { margin-bottom:16px; }
.form-lbl {
    display:block; font-family:var(--font-body); font-size:11px; font-weight:700;
    color:rgba(245,248,250,.45); text-transform:uppercase; letter-spacing:.07em; margin-bottom:7px;
}
.form-ctrl {
    width:100%; height:48px;
    background:rgba(255,255,255,.06); border:1px solid rgba(245,248,250,.1);
    border-radius:10px; padding:0 14px;
    font-family:var(--font-body); font-size:14px; font-weight:500;
    color:#f5f8fa; outline:none; transition:all .22s;
}
.form-ctrl::placeholder { color:rgba(245,248,250,.25); }
.form-ctrl:focus { border-color:rgba(14,165,160,.5); background:rgba(14,165,160,.1); box-shadow:0 0 0 3px rgba(14,165,160,.1); }

/* Buttons */
.btn {
    height:44px; border:none; border-radius:10px;
    font-family:var(--font-body); font-size:13.5px; font-weight:700;
    display:inline-flex; align-items:center; justify-content:center; gap:8px;
    cursor:pointer; transition:all .22s; padding:0 20px; white-space:nowrap;
}
.btn-teal { background:linear-gradient(135deg,#0ea5a0,#075e5b); color:#fff; box-shadow:0 6px 18px rgba(14,165,160,.25); }
.btn-teal:hover { box-shadow:0 10px 28px rgba(14,165,160,.4); transform:translateY(-2px); }
.btn-ghost { background:rgba(255,255,255,.07); border:1px solid rgba(245,248,250,.1); color:rgba(245,248,250,.55); }
.btn-ghost:hover { background:rgba(255,255,255,.12); color:#f5f8fa; }

/* ═══════════════════════════════════════════════════
   VIRTUAL KEYBOARD
═══════════════════════════════════════════════════ */
.vkbd {
    position:fixed; bottom:0; left:0; right:0; z-index:9500;
    background:rgba(10,25,41,.97); backdrop-filter:blur(28px);
    border-top:1px solid rgba(245,248,250,.08);
    padding:14px 24px 20px;
    box-shadow:0 -18px 50px rgba(0,0,0,.55);
    display:none; animation:slideUp .3s cubic-bezier(.4,0,.2,1);
}
@keyframes slideUp { from{transform:translateY(100%)} to{transform:translateY(0)} }
.vkbd::before {
    content:''; position:absolute; top:0; left:12%; right:12%; height:1px;
    background:linear-gradient(90deg,transparent,var(--primary),rgba(0,136,255,.6),transparent);
    opacity:.5;
}
.vkbd-bar { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
.vkbd-title {
    font-family:var(--font-display); font-size:12px; color:#14c9c3;
    display:flex; align-items:center; gap:8px;
}
.vkbd-close {
    width:32px; height:32px; border-radius:7px;
    background:rgba(224,85,85,.12); border:1px solid rgba(224,85,85,.28);
    color:#f48686; font-size:14px; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    transition:all .2s;
}
.vkbd-close:hover { background:#e05555; color:#fff; }

/* Preview */
.vkbd-preview {
    background:rgba(255,255,255,.05); border:1px solid rgba(245,248,250,.08);
    border-radius:10px; padding:10px 16px;
    margin-bottom:12px; display:flex; align-items:center; gap:12px; min-height:44px;
}
.vp-lbl { font-family:var(--font-body); font-size:9px; font-weight:700; color:rgba(245,248,250,.35); text-transform:uppercase; letter-spacing:.07em; flex-shrink:0; }
.vp-text { font-family:var(--font-display); font-size:16px; color:#f5f8fa; flex:1; word-break:break-all; }
.vp-cur { display:inline-block; width:2px; height:1.1em; background:#14c9c3; margin-left:2px; vertical-align:middle; animation:blink .8s step-end infinite; }
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:0} }

.key-row { display:flex; justify-content:center; gap:6px; margin-bottom:6px; }
.key {
    min-width:48px; height:44px; border-radius:8px;
    background:rgba(255,255,255,.07); border:1px solid rgba(245,248,250,.08);
    color:#f5f8fa; font-family:var(--font-display); font-size:14px;
    cursor:pointer; transition:all .2s;
    display:flex; align-items:center; justify-content:center; gap:5px;
}
.key:hover { background:rgba(0,212,170,.12); border-color:rgba(0,212,170,.28); color:#14c9c3; transform:translateY(-2px); }
.key:active { transform:translateY(0); }
.key-sp  { min-width:140px; background:rgba(0,212,170,.1); border-color:rgba(0,212,170,.25); color:#14c9c3; font-size:12px; font-family:var(--font-body); font-weight:700; }
.key-del { min-width:100px; background:rgba(224,85,85,.1); border-color:rgba(224,85,85,.25); color:#f48686; font-size:12px; font-family:var(--font-body); font-weight:700; }
.key-del:hover { background:rgba(224,85,85,.2); color:#ffa0a0; }
.key-clr { min-width:90px; background:rgba(251,191,36,.1); border-color:rgba(251,191,36,.25); color:#fbbf24; font-size:12px; font-family:var(--font-body); font-weight:700; }

/* ═══════════════════════════════════════════════════
   RESPONSIVE
═══════════════════════════════════════════════════ */
@media(max-width:1280px) { .menu-grid { grid-template-columns:repeat(4,1fr); } }
@media(max-width:1024px) { .menu-grid { grid-template-columns:repeat(3,1fr); } }
@media(max-width:768px)  { .menu-grid { grid-template-columns:repeat(2,1fr); } .header { grid-template-columns:auto auto; } .header-center { display:none; } }
@media(max-width:480px)  { .menu-grid { grid-template-columns:1fr; } }
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
            <span id="elDate"><?= $tanggalLengkap ?></span>
        </div>
        <div class="hc-pill">
            <i class="bi bi-clock-history"></i>
            <span id="elTime">00:00:00</span>
            <span style="font-weight:700;color:rgba(0,212,170,.7);">WIB</span>
        </div>
    </div>

    <div class="header-clock">
        <div class="clock-val" id="bigClock">00:00:00</div>
        <div class="clock-wib">WIB</div>
    </div>
</div>

<!-- ═══════════ MENU ═══════════ -->
<div class="menu-area">
    <div class="menu-grid">
        <?php foreach ($menus as $m):
            $aktif = $fiturAktif[$m['kode']] ?? false;
            $isPrivacy = !empty($m['privacy']);

            if (!$aktif) {
                $href = 'javascript:void(0)';
                $extra = 'data-modal="notAvailable"';
            } elseif ($isPrivacy) {
                $href = 'javascript:void(0)';
                $extra = 'data-modal="privacy"';
            } else {
                $href = htmlspecialchars($m['href']);
                $extra = !empty($m['blank']) ? 'target="_blank"' : '';
            }
        ?>
        <a href="<?= $href ?>" class="card-menu <?= !$aktif?'disabled':'' ?>"
           style="--card-grad:<?= $m['grad'] ?>;"
           <?= $extra ?>>
            <div class="icon-orb"
                 style="--orb-grad:<?= $m['grad'] ?>;--orb-glow:<?= $m['glow'] ?>;">
                <i class="bi <?= $m['icon'] ?>"></i>
            </div>
            <div class="menu-label"><?= $m['label'] ?></div>
            <div class="menu-sub"><?= $m['sub'] ?></div>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- ═══════════ FOOTER ═══════════ -->
<div class="footer">
    <div class="marquee-row">
        <div class="marquee-content">
            <?php
            $items = [
                ['bi-hospital-fill',     'Selamat datang di Anjungan Pasien Mandiri — Silakan pilih layanan yang tersedia'],
                ['bi-person-check-fill', 'Siapkan kartu identitas dan dokumen pendukung sebelum menggunakan layanan'],
                ['bi-shield-check-fill', 'Data Anda dilindungi dan hanya digunakan untuk keperluan pelayanan kesehatan'],
                ['bi-heart-pulse-fill',  'Terima kasih telah mempercayakan kesehatan Anda kepada '.htmlspecialchars($namaRS)],
                ['bi-info-circle-fill',  'Informasi lebih lanjut silakan hubungi petugas di loket pelayanan'],
                ['bi-megaphone-fill',    'Semoga lekas sembuh dan sehat selalu'],
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

<!-- ═══════════ MODAL: PRIVASI ═══════════ -->
<div class="modal-overlay" id="modalPrivacy">
    <div class="modal-box">
        <div class="modal-hdr">
            <div class="modal-hdr-icon" style="color:#14c9c3;"><i class="bi bi-shield-lock-fill"></i></div>
            <div>
                <div class="modal-hdr-title">Kebijakan Privasi Pasien</div>
                <div class="modal-hdr-sub">Data Rawat Inap</div>
            </div>
            <button class="modal-close" onclick="closeModal('modalPrivacy')"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-bdy">
            <div class="modal-warn">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <p>Informasi pasien rawat inap bersifat <strong>rahasia dan dilindungi</strong>. Akses ini hanya diperuntukkan bagi <strong>keluarga atau kerabat dekat pasien yang sah</strong>.</p>
            </div>
            <div class="modal-notice">
                <i class="bi bi-info-circle-fill"></i>
                <p>Anda akan diminta mengisi identitas sebelum melanjutkan. Data kunjungan akan tercatat untuk keperluan audit dan keamanan.</p>
            </div>
        </div>
        <div class="modal-ftr">
            <button class="btn btn-ghost" onclick="closeModal('modalPrivacy')"><i class="bi bi-x-circle"></i> Batal</button>
            <button class="btn btn-teal" id="btnLanjutPrivasi"><i class="bi bi-check-circle-fill"></i> Saya Setuju & Lanjutkan</button>
        </div>
    </div>
</div>

<!-- ═══════════ MODAL: IDENTITAS PENGUNJUNG ═══════════ -->
<div class="modal-overlay" id="modalVisitor">
    <div class="modal-box">
        <div class="modal-hdr">
            <div class="modal-hdr-icon" style="color:#14c9c3;"><i class="bi bi-person-badge-fill"></i></div>
            <div>
                <div class="modal-hdr-title">Identitas Pengunjung</div>
                <div class="modal-hdr-sub">Wajib diisi sebelum melanjutkan</div>
            </div>
            <button class="modal-close" onclick="closeModal('modalVisitor')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="post">
            <div class="modal-bdy">
                <div class="modal-notice">
                    <i class="bi bi-info-circle-fill"></i>
                    <p>Mohon isi data dengan benar. Data akan digunakan sebagai log kunjungan untuk keperluan keamanan.</p>
                </div>
                <div class="form-grp">
                    <label class="form-lbl"><i class="bi bi-credit-card-2-front"></i> NIK (Nomor KTP)</label>
                    <input type="text" name="nik" id="inputNIK" class="form-ctrl vk-input"
                           placeholder="Masukkan NIK Anda" maxlength="20" autocomplete="off" required>
                </div>
                <div class="form-grp" style="margin-bottom:0;">
                    <label class="form-lbl"><i class="bi bi-person-fill"></i> Nama Lengkap</label>
                    <input type="text" name="nama" id="inputNama" class="form-ctrl vk-input"
                           placeholder="Masukkan nama lengkap Anda" autocomplete="off" required>
                </div>
            </div>
            <div class="modal-ftr">
                <button type="button" class="btn btn-ghost" onclick="closeModal('modalVisitor')"><i class="bi bi-x-circle"></i> Batal</button>
                <button type="button" class="btn btn-teal" id="btnOpenKbd"><i class="bi bi-keyboard-fill"></i> Keyboard Virtual</button>
                <button type="submit" name="saveVisitor" class="btn btn-teal" style="background:linear-gradient(135deg,#10b981,#059669);box-shadow:0 6px 18px rgba(16,185,129,.25);">
                    <i class="bi bi-check-circle-fill"></i> Simpan & Lanjutkan
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════ MODAL: TIDAK TERSEDIA ═══════════ -->
<div class="modal-overlay" id="modalNotAvailable">
    <div class="modal-box" style="max-width:400px;">
        <div class="modal-hdr">
            <div class="modal-hdr-icon" style="color:#fbbf24;"><i class="bi bi-hourglass-split"></i></div>
            <div>
                <div class="modal-hdr-title">Fitur Belum Tersedia</div>
                <div class="modal-hdr-sub">Dalam proses pengembangan</div>
            </div>
            <button class="modal-close" onclick="closeModal('modalNotAvailable')"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-bdy">
            <div class="modal-warn">
                <i class="bi bi-tools"></i>
                <p>Maaf, fitur ini sedang dalam proses pengembangan dan akan segera tersedia. Terima kasih atas kesabaran Anda.</p>
            </div>
        </div>
        <div class="modal-ftr">
            <button class="btn btn-teal" onclick="closeModal('modalNotAvailable')"><i class="bi bi-check-circle-fill"></i> Tutup</button>
        </div>
    </div>
</div>

<!-- ═══════════ VIRTUAL KEYBOARD ═══════════ -->
<div class="vkbd" id="vKbd">
    <div class="vkbd-bar">
        <div class="vkbd-title"><i class="bi bi-keyboard-fill"></i> Keyboard Virtual</div>
        <button class="vkbd-close" onclick="closeKbd()"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="vkbd-preview">
        <span class="vp-lbl">Input:</span>
        <span class="vp-text" id="kbdPreview"><span class="vp-cur"></span></span>
    </div>
    <div id="kr1" class="key-row"></div>
    <div id="kr2" class="key-row"></div>
    <div id="kr3" class="key-row"></div>
    <div id="kr4" class="key-row"></div>
    <div id="kr5" class="key-row"></div>
</div>

<script>
// ════ CLOCK ════
const HARI  = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
const BULAN = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
function pad2(n){ return String(n).padStart(2,'0'); }
function updateClock(){
    const now = new Date();
    const ts  = pad2(now.getHours())+':'+pad2(now.getMinutes())+':'+pad2(now.getSeconds());
    const ds  = HARI[now.getDay()]+', '+now.getDate()+' '+BULAN[now.getMonth()]+' '+now.getFullYear();
    const bc  = document.getElementById('bigClock');
    const et  = document.getElementById('elTime');
    const ed  = document.getElementById('elDate');
    if(bc) bc.textContent = ts;
    if(et) et.textContent = ts;
    if(ed) ed.textContent = ds;
}
updateClock(); setInterval(updateClock,1000);

// Set CSS variable --ftr-height agar padding menu-area akurat
(function(){
    const ftr = document.querySelector('.footer');
    if (ftr) {
        const h = ftr.offsetHeight;
        document.documentElement.style.setProperty('--ftr-height', h + 'px');
    }
})();

// ════ MODAL SYSTEM ════
function openModal(id){ document.getElementById(id).classList.add('show'); }
function closeModal(id){ document.getElementById(id).classList.remove('show'); }

// Klik overlay tutup modal
document.querySelectorAll('.modal-overlay').forEach(ov=>{
    ov.addEventListener('click', e=>{ if(e.target===ov) ov.classList.remove('show'); });
});

// Routing klik menu
document.querySelectorAll('.card-menu[data-modal]').forEach(el=>{
    el.addEventListener('click',()=>{
        const m = el.getAttribute('data-modal');
        if      (m==='privacy')      openModal('modalPrivacy');
        else if (m==='notAvailable') openModal('modalNotAvailable');
    });
});

// Privasi → Visitor
document.getElementById('btnLanjutPrivasi').addEventListener('click',()=>{
    closeModal('modalPrivacy');
    setTimeout(()=>openModal('modalVisitor'),260);
});

// ════ VIRTUAL KEYBOARD ════
const vKbd = document.getElementById('vKbd');
let currentInput = null;

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
            if      (k==='SPASI')     { b.className='key key-sp';  b.innerHTML='<i class="bi bi-space"></i> Spasi'; b.style.minWidth='140px'; }
            else if (k==='HAPUS')     { b.className='key key-del'; b.innerHTML='<i class="bi bi-backspace-fill"></i> Hapus'; }
            else if (k==='BERSIHKAN') { b.className='key key-clr'; b.innerHTML='<i class="bi bi-x-lg"></i> Bersihkan'; }
            else { b.className='key'; b.textContent=k; }
            b.addEventListener('click',()=>pressKey(k));
            row.appendChild(b);
        });
    });
}

function pressKey(k){
    if (!currentInput) return;
    if      (k==='SPASI')     currentInput.value += ' ';
    else if (k==='HAPUS')     currentInput.value  = currentInput.value.slice(0,-1);
    else if (k==='BERSIHKAN') currentInput.value  = '';
    else                      currentInput.value += k;
    currentInput.focus(); updatePreview();
}

function updatePreview(){
    const p=document.getElementById('kbdPreview'); if(!p) return;
    const v=currentInput?currentInput.value:'';
    p.innerHTML=v?`<span>${v}</span><span class="vp-cur"></span>`:'<span class="vp-cur"></span>';
}

function openKbd(inp){
    currentInput=inp; vKbd.style.display='block';
    // Beri padding pada modal agar tidak tertutup keyboard
    document.querySelectorAll('.modal-box').forEach(b=>{ b.style.marginBottom=(vKbd.offsetHeight+16)+'px'; });
    updatePreview();
}

function closeKbd(){
    vKbd.style.display='none';
    document.querySelectorAll('.modal-box').forEach(b=>{ b.style.marginBottom=''; });
}

// Input di modal klik → buka keyboard
document.querySelectorAll('.vk-input').forEach(inp=>{
    inp.setAttribute('readonly','');
    inp.addEventListener('click',()=>openKbd(inp));
});

// Tombol keyboard virtual di modal footer
document.getElementById('btnOpenKbd').addEventListener('click',()=>{
    const nik=document.getElementById('inputNIK');
    openKbd(nik.value===''?nik:document.getElementById('inputNama'));
});

buildKbd();

// Auto reload tiap 1 jam
setTimeout(()=>location.reload(), 3600000);
</script>
</body>
</html>