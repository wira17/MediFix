<?php
session_start();
include 'koneksi.php';
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

$hari_ini  = strtoupper(date('l'));
$hari_map  = ['SUNDAY'=>'MINGGU','MONDAY'=>'SENIN','TUESDAY'=>'SELASA','WEDNESDAY'=>'RABU','THURSDAY'=>'KAMIS','FRIDAY'=>'JUMAT','SATURDAY'=>'SABTU'];
$hari_indo = $hari_map[$hari_ini] ?? 'SENIN';
$swal_data = null;

try {
    $stmtRS  = $pdo_simrs->query("SELECT nama_instansi, alamat_instansi, kabupaten, propinsi, kontak, email FROM setting LIMIT 1");
    $setting = $stmtRS->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $setting = ['nama_instansi'=>'Nama Rumah Sakit','alamat_instansi'=>'','kabupaten'=>'','propinsi'=>'','kontak'=>'','email'=>''];
}

// ── PROSES SIMPAN PENDAFTARAN ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['no_rkm_medis'])) {
    try {
        $no_rkm_medis = trim($_POST['no_rkm_medis']);
        $kd_poli      = trim($_POST['kd_poli']);
        $kd_dokter    = trim($_POST['kd_dokter']);
        $kd_pj        = trim($_POST['kd_pj']);

        if (!$no_rkm_medis || !$kd_poli || !$kd_dokter || !$kd_pj)
            throw new Exception("Data tidak lengkap!");

        $tgl = date('Y-m-d');
        $jam = date('H:i:s');

        $stmtCekDaftar = $pdo_simrs->prepare("SELECT COUNT(*) FROM reg_periksa WHERE no_rkm_medis=?");
        $stmtCekDaftar->execute([$no_rkm_medis]);
        $stts_daftar = ($stmtCekDaftar->fetchColumn() > 0) ? "Lama" : "Baru";

        $cekStatus = $pdo_simrs->prepare("SELECT COUNT(*) FROM reg_periksa WHERE no_rkm_medis=? AND kd_poli=?");
        $cekStatus->execute([$no_rkm_medis, $kd_poli]);
        $status_poli = ($cekStatus->fetchColumn() > 0) ? "Lama" : "Baru";

        $stmt_inap = $pdo_simrs->prepare("SELECT COUNT(*) FROM reg_periksa r JOIN kamar_inap k ON r.no_rawat=k.no_rawat WHERE r.no_rkm_medis=? AND k.stts_pulang='-'");
        $stmt_inap->execute([$no_rkm_medis]);
        if ($stmt_inap->fetchColumn() > 0) throw new Exception("Pasien sedang dalam perawatan inap!");

        $cek = $pdo_simrs->prepare("SELECT COUNT(*) FROM reg_periksa WHERE no_rkm_medis=? AND kd_poli=? AND kd_dokter=? AND tgl_registrasi=?");
        $cek->execute([$no_rkm_medis, $kd_poli, $kd_dokter, $tgl]);
        if ($cek->fetchColumn() > 0) throw new Exception("Pasien sudah terdaftar hari ini!");

        $stmt_no = $pdo_simrs->prepare("SELECT MAX(CAST(no_reg AS UNSIGNED)) FROM reg_periksa WHERE tgl_registrasi=?");
        $stmt_no->execute([$tgl]);
        $no_reg = str_pad((($stmt_no->fetchColumn() ?: 0) + 1), 3, '0', STR_PAD_LEFT);

        $stmt_rawat = $pdo_simrs->prepare("SELECT MAX(CAST(SUBSTRING(no_rawat,12,6) AS UNSIGNED)) FROM reg_periksa WHERE tgl_registrasi=?");
        $stmt_rawat->execute([$tgl]);
        $no_rawat = date('Y/m/d/').str_pad(($stmt_rawat->fetchColumn() ?: 0)+1, 6, '0', STR_PAD_LEFT);

        $stmt_pasien = $pdo_simrs->prepare("SELECT nm_pasien, alamat, tgl_lahir, keluarga AS hubunganpj, namakeluarga AS p_jawab, alamatpj FROM pasien WHERE no_rkm_medis=?");
        $stmt_pasien->execute([$no_rkm_medis]);
        $pasien = $stmt_pasien->fetch(PDO::FETCH_ASSOC);

        if (!$pasien) throw new Exception("Data pasien tidak ditemukan!");
        if (empty($pasien['tgl_lahir'])) throw new Exception("Tanggal lahir belum diinput!");

        $p_jawab    = $pasien['p_jawab']    ?: $pasien['nm_pasien'];
        $almt_pj    = $pasien['alamatpj']   ?: $pasien['alamat'];
        $hubunganpj = $pasien['hubunganpj'] ?: "-";
        $umur       = (new DateTime())->diff(new DateTime($pasien['tgl_lahir']))->y;

        $stmt_biaya = $pdo_simrs->prepare("SELECT registrasi, registrasilama FROM poliklinik WHERE kd_poli=?");
        $stmt_biaya->execute([$kd_poli]);
        $biaya     = $stmt_biaya->fetch(PDO::FETCH_ASSOC);
        $biaya_reg = ($stts_daftar == "Lama") ? $biaya['registrasilama'] : $biaya['registrasi'];

        $stmt = $pdo_simrs->prepare("INSERT INTO reg_periksa (no_reg,no_rawat,tgl_registrasi,jam_reg,kd_dokter,no_rkm_medis,kd_poli,p_jawab,almt_pj,hubunganpj,biaya_reg,stts,stts_daftar,status_lanjut,kd_pj,umurdaftar,sttsumur,status_bayar,status_poli) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$no_reg,$no_rawat,$tgl,$jam,$kd_dokter,$no_rkm_medis,$kd_poli,$p_jawab,$almt_pj,$hubunganpj,$biaya_reg,'Belum',$stts_daftar,'Ralan',$kd_pj,$umur,'Th','Belum Bayar',$status_poli]);

        $stmtNmPoli = $pdo_simrs->prepare("SELECT nm_poli FROM poliklinik WHERE kd_poli=?");
        $stmtNmPoli->execute([$kd_poli]);
        $nmPoli = $stmtNmPoli->fetchColumn() ?: $kd_poli;

        $stmtNmDok = $pdo_simrs->prepare("SELECT nm_dokter FROM dokter WHERE kd_dokter=?");
        $stmtNmDok->execute([$kd_dokter]);
        $nmDokter = $stmtNmDok->fetchColumn() ?: $kd_dokter;

        $_SESSION['print_poli'] = [
            'no_reg'       => $no_reg,
            'no_rawat'     => $no_rawat,
            'no_antrian'   => $kd_poli.'-'.$no_reg,
            'nm_poli'      => $nmPoli,
            'nm_dokter'    => $nmDokter,
            'nm_pasien'    => $pasien['nm_pasien'],
            'no_rkm_medis' => $no_rkm_medis,
            'tgl_cetak'    => date('d/m/Y H:i:s'),
        ];

        $swal_data = [
            'icon'       => 'success',
            'title'      => 'Pendaftaran Berhasil!',
            'html'       => "<strong>No. Rawat:</strong> {$no_rawat}<br><strong>No Antri:</strong> {$kd_poli}-{$no_reg}",
            'confirmText'=> 'Cetak Karcis',
            'cancelText' => 'Tutup',
            'redirect'   => 'anjungan.php',
            'nm_pasien'  => $pasien['nm_pasien'],
            'no_antrian' => $kd_poli.'-'.$no_reg,
            'nm_poli'    => $nmPoli,
            'nm_dokter'  => $nmDokter,
        ];

    } catch (Exception $e) {
        $swal_data = ['icon'=>'error','title'=>'Pendaftaran Gagal','text'=>$e->getMessage(),'confirmText'=>'OK','redirect'=>'daftar_poli.php'];
    }
}

// ── PENCARIAN ─────────────────────────────────────────────────────────────
$searchResultVoice = "";
$pasienList = [];
$printPoli  = null;

if (isset($_SESSION['print_poli'])) {
    $printPoli = $_SESSION['print_poli'];
    unset($_SESSION['print_poli']);
}

if (isset($_GET['cari']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $keyword = trim($_GET['cari']);
    $stmt = $pdo_simrs->prepare("SELECT no_rkm_medis,nm_pasien,jk,tgl_lahir,alamat FROM pasien WHERE no_rkm_medis LIKE ? OR nm_pasien LIKE ? LIMIT 20");
    $stmt->execute(["%$keyword%","%$keyword%"]);
    $pasienList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $searchResultVoice = count($pasienList) > 0 ? "found" : "notFound";
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Daftar Poli – <?= htmlspecialchars($setting['nama_instansi']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=DM+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
/* ═══════════════════════════════════════════
   ROOT & RESET
═══════════════════════════════════════════ */
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

:root {
    --navy:       #0b1623;
    --navy-mid:   #122033;
    --navy-card:  rgba(255,255,255,.035);
    --navy-card2: rgba(255,255,255,.06);
    --teal:       #0ea5a0;
    --teal2:      #14c9c3;
    --teal-soft:  rgba(14,165,160,.12);
    --teal-glow:  rgba(14,165,160,.28);
    --gold:       #c9a84c;
    --gold2:      #e2c97a;
    --gold-soft:  rgba(201,168,76,.1);
    --gold-glow:  rgba(201,168,76,.25);
    --white:      #f5f8fa;
    --muted:      rgba(245,248,250,.45);
    --muted2:     rgba(245,248,250,.22);
    --border:     rgba(245,248,250,.07);
    --border2:    rgba(245,248,250,.12);
    --danger:     #e05555;
    --emerald:    #10b981;
    --hdr:        72px;
    --ftr:        48px;
    --radius:     16px;
    --radius-sm:  10px;
    --trans:      .22s cubic-bezier(.4,0,.2,1);
}

html, body {
    height: 100vh; overflow: hidden;
    font-family: 'DM Sans', sans-serif;
    background: var(--navy);
    color: var(--white);
}

/* ── Background layers ── */
body::before {
    content:''; position:fixed; inset:0; z-index:0; pointer-events:none;
    background:
        radial-gradient(ellipse 70% 55% at 0% 0%,   rgba(14,165,160,.10) 0%,transparent 55%),
        radial-gradient(ellipse 55% 65% at 100% 100%,rgba(201,168,76,.07) 0%,transparent 55%),
        radial-gradient(ellipse 45% 45% at 50% 60%,  rgba(14,165,160,.04) 0%,transparent 60%);
}
body::after {
    content:''; position:fixed; inset:0; z-index:0; pointer-events:none;
    background-image:
        linear-gradient(var(--border) 1px, transparent 1px),
        linear-gradient(90deg, var(--border) 1px, transparent 1px);
    background-size: 52px 52px;
    opacity: .55;
}

/* ═══════════════════════════════════════════
   SHELL
═══════════════════════════════════════════ */
.shell { position:relative; z-index:1; height:100vh; display:flex; flex-direction:column; }

/* ═══════════════════════════════════════════
   HEADER
═══════════════════════════════════════════ */
.hdr {
    height: var(--hdr); flex-shrink:0;
    background: rgba(11,22,35,.94);
    backdrop-filter: blur(28px);
    border-bottom: 1px solid var(--border2);
    display: flex; align-items:center;
    padding: 0 32px; gap: 18px;
    position: relative;
}
.hdr::after {
    content:''; position:absolute; bottom:0; left:0; right:0; height:1px;
    background: linear-gradient(90deg,transparent,var(--teal) 30%,var(--gold) 70%,transparent);
    opacity: .5;
}

.hdr-brand { display:flex; align-items:center; gap:13px; }
.hdr-emblem {
    width:44px; height:44px; flex-shrink:0;
    background: linear-gradient(135deg,var(--teal),#075e5b);
    border-radius:12px;
    display:flex; align-items:center; justify-content:center;
    font-size:21px; box-shadow:0 0 22px var(--teal-glow);
}
.hdr-title {
    font-family:'Archivo Black',sans-serif;
    font-size:18px; font-weight:700;
    color:var(--white); line-height:1.15;
    letter-spacing:-.02em;
}
.hdr-sub { font-size:11px; color:var(--muted); font-weight:500; margin-top:2px; letter-spacing:.02em; }

.hdr-sep { width:1px; height:30px; background:var(--border2); flex-shrink:0; }

/* Pills */
.hdr-pill {
    display:flex; align-items:center; gap:7px;
    padding:5px 13px;
    background:var(--teal-soft); border:1px solid var(--teal-glow);
    border-radius:50px;
    font-size:11.5px; font-weight:600; color:var(--teal2);
}
.hdr-pill.gold { background:var(--gold-soft); border-color:var(--gold-glow); color:var(--gold2); }
.pulse { width:6px; height:6px; border-radius:50%; background:var(--teal2); box-shadow:0 0 8px var(--teal2); animation:pdot 2.2s ease-in-out infinite; }
@keyframes pdot { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.35;transform:scale(1.6)} }

.hdr-clock { margin-left:auto; text-align:right; flex-shrink:0; }
.clock-v {
    font-family:'Archivo Black',sans-serif;
    font-size:25px; font-weight:700; color:var(--gold2);
    letter-spacing:2px; line-height:1;
    text-shadow:0 0 18px rgba(201,168,76,.3);
}
.clock-d { font-size:10.5px; color:var(--muted); font-weight:500; margin-top:3px; }

/* ═══════════════════════════════════════════
   CONTENT AREA
═══════════════════════════════════════════ */
.content {
    flex:1; display:flex; padding:22px 32px;
    gap:24px; overflow:hidden; min-height:0;
}

/* ═══════════════════════════════════════════
   LEFT PANEL
═══════════════════════════════════════════ */
.left {
    width: clamp(240px,26vw,340px); flex-shrink:0;
    display:flex; flex-direction:column; gap:16px;
}

.hero {
    background:var(--navy-card); border:1px solid var(--border2);
    border-radius:var(--radius); padding:22px 22px 18px;
    position:relative; overflow:hidden;
}
.hero::before {
    content:''; position:absolute; top:-1px; left:0; right:0; height:2px;
    background:linear-gradient(90deg,var(--teal),var(--gold));
}
.hero::after {
    content:''; position:absolute; bottom:-40px; right:-40px;
    width:160px; height:160px; border-radius:50%;
    background:radial-gradient(circle,var(--teal-soft) 0%,transparent 70%);
    pointer-events:none;
}
.hero-tag {
    display:inline-flex; align-items:center; gap:6px;
    background:var(--gold-soft); border:1px solid var(--gold-glow);
    border-radius:50px; padding:3px 11px;
    font-size:10px; font-weight:700; color:var(--gold2);
    text-transform:uppercase; letter-spacing:.08em;
    margin-bottom:12px;
}
.hero-title {
    font-family:'Archivo Black',sans-serif;
    font-size:clamp(22px,2.5vw,34px); font-weight:800;
    color:var(--white); line-height:1.15; margin-bottom:10px;
    position:relative; z-index:1;
}
.hero-title span { color:var(--teal2); }
.hero-body { font-size:13px; color:var(--muted); line-height:1.7; font-weight:400; position:relative;z-index:1; }
.hero-body strong { color:var(--white); font-weight:600; }

/* DT strip */
.dt-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.dt-card {
    background:var(--navy-card); border:1px solid var(--border2);
    border-radius:var(--radius-sm); padding:14px 15px;
    display:flex; align-items:center; gap:12px;
    transition:background var(--trans),border-color var(--trans);
}
.dt-card:hover { background:var(--navy-card2); border-color:var(--teal-glow); }
.dt-ico {
    width:38px; height:38px; flex-shrink:0;
    background:var(--teal-soft); border:1px solid var(--teal-glow);
    border-radius:9px; display:flex; align-items:center; justify-content:center;
    color:var(--teal2); font-size:17px;
}
.dt-lbl { font-size:9.5px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.07em; margin-bottom:3px; }
.dt-val { font-size:13px; font-weight:700; color:var(--white); line-height:1; }

/* Notice */
.notice {
    background:var(--gold-soft); border:1px solid var(--gold-glow);
    border-radius:var(--radius-sm); padding:14px 16px;
    display:flex; align-items:flex-start; gap:12px;
}
.notice i { font-size:17px; color:var(--gold2); flex-shrink:0; margin-top:1px; }
.notice p { font-size:12px; color:rgba(226,201,122,.8); line-height:1.65; font-weight:400; }
.notice p strong { color:var(--gold2); font-weight:600; }

/* ═══════════════════════════════════════════
   RIGHT PANEL
═══════════════════════════════════════════ */
.right { flex:1; min-width:0; display:flex; flex-direction:column; gap:16px; overflow:hidden; }

/* Search bar */
.search-card {
    background:var(--navy-card); border:1px solid var(--border2);
    border-radius:var(--radius); padding:18px 20px;
    flex-shrink:0;
}
.search-form { display:flex; gap:10px; margin-bottom:12px; }
.field-inp {
    flex:1; height:48px;
    background:rgba(255,255,255,.06); border:1px solid var(--border2);
    border-radius:var(--radius-sm); padding:0 16px;
    font-size:14px; font-weight:500; color:var(--white);
    font-family:'DM Sans',sans-serif; outline:none;
    transition:all var(--trans);
}
.field-inp::placeholder { color:var(--muted2); }
.field-inp:focus { border-color:var(--teal); background:rgba(14,165,160,.08); box-shadow:0 0 0 3px var(--teal-soft); }

.btn { /* base */
    height:48px; border:none; border-radius:var(--radius-sm);
    font-family:'DM Sans',sans-serif; font-size:13.5px; font-weight:700;
    display:inline-flex; align-items:center; justify-content:center; gap:8px;
    cursor:pointer; transition:all var(--trans);
    padding:0 20px; white-space:nowrap; text-decoration:none;
    position:relative; overflow:hidden;
}
.btn::after { content:''; position:absolute; inset:0; background:rgba(255,255,255,.08); opacity:0; transition:opacity var(--trans); }
.btn:hover::after { opacity:1; }
.btn:hover { transform:translateY(-2px); }
.btn:active { transform:translateY(0); }

.btn-teal { background:linear-gradient(135deg,var(--teal),#075e5b); color:#fff; box-shadow:0 6px 20px rgba(14,165,160,.28); }
.btn-teal:hover { box-shadow:0 10px 28px rgba(14,165,160,.42); color:#fff; }
.btn-gold { background:var(--gold-soft); border:1px solid var(--gold-glow); color:var(--gold2); }
.btn-gold:hover { background:rgba(201,168,76,.18); color:var(--gold2); }
.btn-ghost { background:var(--navy-card); border:1px solid var(--border2); color:var(--muted); }
.btn-ghost:hover { background:var(--navy-card2); color:var(--white); border-color:var(--border2); }
.btn-danger { background:rgba(224,85,85,.15); border:1px solid rgba(224,85,85,.3); color:#f48686; }
.btn-danger:hover { background:rgba(224,85,85,.25); color:#ffa0a0; }
.btn-emerald { background:linear-gradient(135deg,var(--emerald),#059669); color:#fff; box-shadow:0 4px 14px rgba(16,185,129,.25); }
.btn-emerald:hover { box-shadow:0 8px 22px rgba(16,185,129,.4); color:#fff; }

.action-row { display:flex; gap:10px; }

/* Results card */
.results-card {
    background:var(--navy-card); border:1px solid var(--border2);
    border-radius:var(--radius); flex:1; min-height:0;
    display:flex; flex-direction:column; overflow:hidden;
}
.results-head {
    padding:14px 20px; border-bottom:1px solid var(--border2);
    display:flex; align-items:center; gap:10px; flex-shrink:0;
}
.results-head-title {
    font-size:13px; font-weight:700; color:var(--white);
    display:flex; align-items:center; gap:8px;
}
.results-head-title i { color:var(--teal2); }
.results-count {
    margin-left:auto; font-size:11px; font-weight:600; color:var(--muted);
    background:var(--teal-soft); border:1px solid var(--teal-glow);
    padding:3px 10px; border-radius:20px; color:var(--teal2);
}

.table-wrap {
    flex:1; overflow-y:auto; padding:0;
}
.table-wrap::-webkit-scrollbar { width:4px; }
.table-wrap::-webkit-scrollbar-thumb { background:var(--teal-glow); border-radius:4px; }

table.tbl { width:100%; border-collapse:collapse; }
table.tbl thead th {
    padding:11px 16px; background:rgba(11,22,35,.8);
    font-size:10px; font-weight:700; text-transform:uppercase;
    letter-spacing:.06em; color:var(--muted); white-space:nowrap;
    border-bottom:1px solid var(--border2); position:sticky; top:0; z-index:2;
}
table.tbl thead th:first-child { border-radius:0; }
table.tbl tbody td {
    padding:12px 16px; border-bottom:1px solid var(--border);
    font-size:13px; font-weight:500; color:rgba(245,248,250,.75);
    vertical-align:middle;
}
table.tbl tbody tr { transition:background var(--trans); }
table.tbl tbody tr:hover td { background:rgba(14,165,160,.06); }
table.tbl tbody tr:last-child td { border-bottom:none; }

.td-rm   { font-weight:800; color:var(--teal2); font-family:'DM Sans',sans-serif; letter-spacing:.02em; }
.td-nama { font-weight:700; color:var(--white); }
.badge-jk {
    display:inline-flex; align-items:center; justify-content:center;
    width:28px; height:24px; border-radius:6px;
    font-size:10px; font-weight:800; color:#fff;
    background:var(--teal-soft); border:1px solid var(--teal-glow); color:var(--teal2);
}

/* Empty / notfound states */
.state {
    flex:1; display:flex; flex-direction:column;
    align-items:center; justify-content:center;
    gap:14px; text-align:center; padding:32px;
}
.state-icon {
    width:80px; height:80px; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    font-size:36px;
}
.state.empty .state-icon { background:var(--teal-soft); border:1px solid var(--teal-glow); color:var(--teal2); }
.state.notfound .state-icon { background:rgba(224,85,85,.1); border:1px solid rgba(224,85,85,.2); color:var(--danger); }
.state h4 { font-family:'Archivo Black',sans-serif; font-size:18px; font-weight:700; color:var(--white); }
.state p  { font-size:13px; color:var(--muted); font-weight:400; max-width:280px; line-height:1.6; }

/* ═══════════════════════════════════════════
   MODAL PENDAFTARAN
═══════════════════════════════════════════ */
/* Overlay */
.modal-overlay {
    display:none; position:fixed; inset:0; z-index:8000;
    background:rgba(7,16,31,.85); backdrop-filter:blur(8px);
    align-items:center; justify-content:center; padding:20px;
}
.modal-overlay.show { display:flex; }
.modal-box {
    background:#0f1e33; border:1px solid var(--border2);
    border-radius:20px; width:100%; max-width:620px;
    overflow:hidden; box-shadow:0 40px 100px rgba(0,0,0,.6);
    animation:mIn .3s cubic-bezier(.4,0,.2,1);
}
@keyframes mIn { from{opacity:0;transform:translateY(20px) scale(.97)} to{opacity:1;transform:translateY(0) scale(1)} }

.modal-hdr {
    padding:18px 24px; background:rgba(14,165,160,.08);
    border-bottom:1px solid var(--border2);
    display:flex; align-items:center; gap:12px;
}
.modal-hdr-icon {
    width:38px; height:38px; border-radius:9px;
    background:var(--teal-soft); border:1px solid var(--teal-glow);
    display:flex; align-items:center; justify-content:center;
    color:var(--teal2); font-size:18px; flex-shrink:0;
}
.modal-hdr-title { font-family:'Archivo Black',sans-serif; font-size:16px; font-weight:700; color:var(--white); }
.modal-hdr-sub   { font-size:11px; color:var(--muted); margin-top:2px; }
.modal-close {
    margin-left:auto; width:32px; height:32px; border-radius:8px;
    background:rgba(224,85,85,.12); border:1px solid rgba(224,85,85,.25);
    color:#f48686; font-size:14px; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    transition:all var(--trans); flex-shrink:0;
}
.modal-close:hover { background:var(--danger); color:#fff; }

.modal-bdy { padding:22px 24px; display:flex; flex-direction:column; gap:16px; }

.form-row   { display:grid; gap:16px; }
.form-row-2 { grid-template-columns:1fr 1fr; }
.form-grp   { display:flex; flex-direction:column; gap:7px; }
.form-lbl   {
    font-size:10.5px; font-weight:700; color:var(--muted);
    text-transform:uppercase; letter-spacing:.07em;
    display:flex; align-items:center; gap:7px;
}
.form-lbl i { color:var(--teal); font-size:13px; }
.form-ctrl {
    height:46px; background:rgba(255,255,255,.06);
    border:1px solid var(--border2); border-radius:var(--radius-sm);
    padding:0 14px; font-size:13.5px; font-weight:500;
    color:var(--white); font-family:'DM Sans',sans-serif;
    outline:none; transition:all var(--trans);
    -webkit-appearance:none; appearance:none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%230ea5a0' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 14px center;
    padding-right: 36px;
}
.form-ctrl:not(select) { background-image:none; padding-right:14px; }
.form-ctrl:focus { border-color:var(--teal); background:rgba(14,165,160,.1); box-shadow:0 0 0 3px var(--teal-soft); }
.form-ctrl[readonly] { background:rgba(255,255,255,.03); color:var(--muted); cursor:default; }
select.form-ctrl option { background:#0f1e33; color:var(--white); }

.modal-info {
    background:rgba(14,165,160,.07); border:1px solid var(--teal-glow);
    border-radius:var(--radius-sm); padding:12px 16px;
    display:flex; align-items:flex-start; gap:10px;
}
.modal-info i { color:var(--teal2); font-size:15px; flex-shrink:0; margin-top:1px; }
.modal-info p { font-size:12px; color:rgba(20,201,195,.8); line-height:1.6; font-weight:500; }

.modal-ftr {
    padding:16px 24px; border-top:1px solid var(--border2);
    display:flex; align-items:center; gap:10px; justify-content:flex-end;
    background:rgba(11,22,35,.5);
}

/* ═══════════════════════════════════════════
   VIRTUAL KEYBOARD
═══════════════════════════════════════════ */
.vkbd {
    position:fixed; bottom:0; left:0; right:0; z-index:9500;
    background:rgba(11,22,35,.97); backdrop-filter:blur(28px);
    border-top:1px solid var(--border2);
    padding:14px 24px 20px;
    box-shadow:0 -20px 55px rgba(0,0,0,.55);
    display:none;
    animation:slideUp .3s var(--trans);
}
@keyframes slideUp { from{transform:translateY(100%)} to{transform:translateY(0)} }
.vkbd::before {
    content:''; position:absolute; top:0; left:12%; right:12%; height:1px;
    background:linear-gradient(90deg,transparent,var(--teal),var(--gold),transparent); opacity:.4;
}

.vkbd-bar { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
.vkbd-title { font-size:12.5px; font-weight:700; color:var(--teal2); display:flex; align-items:center; gap:8px; }
.vkbd-close {
    width:32px; height:32px; border-radius:7px;
    background:rgba(224,85,85,.12); border:1px solid rgba(224,85,85,.28);
    color:#f48686; font-size:14px; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    transition:all var(--trans);
}
.vkbd-close:hover { background:var(--danger); color:#fff; }

.vkbd-preview {
    background:rgba(255,255,255,.05); border:1px solid var(--border2);
    border-radius:var(--radius-sm); padding:10px 16px;
    margin-bottom:12px; display:flex; align-items:center; gap:12px; min-height:44px;
}
.vp-lbl  { font-size:9.5px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.07em; flex-shrink:0; }
.vp-text { font-size:16px; font-weight:600; color:var(--white); flex:1; word-break:break-all; }
.vp-cur  { display:inline-block; width:2px; height:1.1em; background:var(--teal2); margin-left:2px; vertical-align:middle; animation:blink .8s step-end infinite; }
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:0} }

.key-row { display:flex; justify-content:center; gap:6px; margin-bottom:6px; }
.key {
    min-width:48px; height:44px; border-radius:8px;
    background:rgba(255,255,255,.07); border:1px solid var(--border2);
    color:var(--white); font-size:14.5px; font-weight:700;
    cursor:pointer; transition:all var(--trans);
    font-family:'DM Sans',sans-serif;
    display:flex; align-items:center; justify-content:center; gap:5px;
}
.key:hover  { background:var(--teal-soft); border-color:var(--teal-glow); color:var(--teal2); transform:translateY(-2px); }
.key:active { transform:translateY(0); }
.key-sp  { min-width:140px; background:rgba(14,165,160,.1); border-color:var(--teal-glow); color:var(--teal2); font-size:12px; }
.key-del { min-width:100px; background:rgba(224,85,85,.1); border-color:rgba(224,85,85,.25); color:#f48686; font-size:12px; }
.key-del:hover { background:rgba(224,85,85,.2); color:#ffa0a0; border-color:rgba(224,85,85,.4); }
.key-clr { min-width:88px; background:rgba(201,168,76,.1); border-color:var(--gold-glow); color:var(--gold2); font-size:12px; }
.key-clr:hover { background:rgba(201,168,76,.2); }

body.kbd-open .content { overflow-y:auto; }
body.kbd-open .shell   { height:auto; min-height:100vh; padding-bottom:var(--kbd-h,310px); }

/* ═══════════════════════════════════════════
   FOOTER
═══════════════════════════════════════════ */
.ftr {
    height:var(--ftr); flex-shrink:0;
    background:rgba(11,22,35,.92); backdrop-filter:blur(16px);
    border-top:1px solid var(--border2);
    display:flex; align-items:center; padding:0 32px; gap:24px;
}
.ftr-item { display:flex; align-items:center; gap:7px; font-size:11.5px; color:var(--muted); font-weight:500; }
.ftr-item i { font-size:13px; color:var(--teal); }
.ftr-item strong { color:rgba(245,248,250,.65); font-weight:600; }
.ftr-sep { width:1px; height:16px; background:var(--border2); }
.ftr-copy { margin-left:auto; font-size:11px; color:var(--muted2); }
.ftr-copy span { color:var(--teal2); font-weight:600; }

/* ═══════════════════════════════════════════
   PRINT
═══════════════════════════════════════════ */
.print-area { display:none; }
@media print {
    @page { size:7.5cm 11cm; margin:0; }
    body > *:not(.print-area) { display:none !important; }
    .print-area { display:block !important; width:7.5cm; height:11cm; }
}

/* ═══════════════════════════════════════════
   RESPONSIVE
═══════════════════════════════════════════ */
@media(max-width:1024px) {
    .content { flex-direction:column; overflow-y:auto; }
    .left { width:100%; flex-direction:row; flex-wrap:wrap; }
    .hero { flex:1; min-width:260px; }
    .dt-row,.notice { flex:1; min-width:200px; }
}
@media(max-width:700px) {
    .hdr { padding:0 16px; }
    .hdr-sep,.hdr-pill.gold { display:none; }
    .content { padding:14px 16px; }
    .ftr { padding:0 16px; gap:14px; }
    .form-row-2 { grid-template-columns:1fr; }
}
</style>
</head>
<body>
<div class="shell">

  <!-- ════ HEADER ════ -->
  <header class="hdr">
    <div class="hdr-brand">
      <div class="hdr-emblem">🏥</div>
      <div>
        <div class="hdr-title"><?= htmlspecialchars($setting['nama_instansi']) ?></div>
        <div class="hdr-sub"><?= htmlspecialchars($setting['alamat_instansi']) ?> &middot; <?= htmlspecialchars($setting['kabupaten']) ?></div>
      </div>
    </div>

    <div class="hdr-sep"></div>

    <div class="hdr-pill"><span class="pulse"></span> Sistem Online</div>
    <div class="hdr-pill gold"><i class="bi bi-clipboard2-pulse" style="font-size:12px;"></i> Daftar Poliklinik</div>

    <div class="hdr-clock">
      <div class="clock-v" id="elTime">00:00:00</div>
      <div class="clock-d" id="elDate">&mdash;</div>
    </div>
  </header>

  <!-- ════ CONTENT ════ -->
  <div class="content">

    <!-- LEFT -->
    <div class="left">
      <div class="hero">
        <div class="hero-tag"><i class="bi bi-award-fill"></i> Anjungan Mandiri</div>
        <h1 class="hero-title">Daftar<br><span>Poliklinik</span></h1>
        <p class="hero-body">Cari pasien dengan <strong>No. RM</strong> atau <strong>Nama</strong>, lalu pilih poliklinik &amp; dokter yang dituju.</p>
      </div>

      <div class="dt-row">
        <div class="dt-card">
          <div class="dt-ico"><i class="bi bi-calendar3"></i></div>
          <div>
            <div class="dt-lbl">Tanggal</div>
            <div class="dt-val" id="elDateCard">—</div>
          </div>
        </div>
        <div class="dt-card">
          <div class="dt-ico"><i class="bi bi-clock"></i></div>
          <div>
            <div class="dt-lbl">Waktu</div>
            <div class="dt-val" id="elTimeCard">—</div>
          </div>
        </div>
      </div>

      <div class="notice">
        <i class="bi bi-info-circle-fill"></i>
        <p>Gunakan <strong>keyboard virtual</strong> di layar sentuh. Klik <strong>Pilih</strong> pada pasien yang sesuai untuk membuka form pendaftaran.</p>
      </div>
    </div>

    <!-- RIGHT -->
    <div class="right">

      <!-- Search -->
      <div class="search-card">
        <form method="get" id="formSearch" class="search-form">
          <input type="text" id="inputCari" name="cari" class="field-inp"
                 placeholder="Ketik No. RM atau Nama Pasien…"
                 value="<?= htmlspecialchars($_GET['cari'] ?? '') ?>"
                 autocomplete="off">
          <button type="submit" class="btn btn-teal">
            <i class="bi bi-search"></i> Cari
          </button>
        </form>
        <div class="action-row">
          <button type="button" class="btn btn-gold" onclick="toggleKeyboard()">
            <i class="bi bi-keyboard-fill"></i> Keyboard Virtual
          </button>
          <a href="anjungan.php" class="btn btn-danger" style="margin-left:auto;">
            <i class="bi bi-box-arrow-left"></i> Keluar
          </a>
        </div>
      </div>

      <!-- Results -->
      <div class="results-card">

        <?php if (empty($_GET['cari'])): ?>
        <div class="state empty">
          <div class="state-icon"><i class="bi bi-search"></i></div>
          <h4>Cari Pasien</h4>
          <p>Masukkan No. RM atau nama pasien di kolom pencarian di atas untuk memulai</p>
        </div>

        <?php elseif ($searchResultVoice === 'notFound'): ?>
        <div class="state notfound">
          <div class="state-icon"><i class="bi bi-person-x-fill"></i></div>
          <h4>Data Tidak Ditemukan</h4>
          <p>Periksa kembali No. RM atau nama pasien yang Anda masukkan</p>
        </div>

        <?php else: ?>
        <div class="results-head">
          <div class="results-head-title"><i class="bi bi-people-fill"></i> Hasil Pencarian</div>
          <div class="results-count"><?= count($pasienList) ?> data</div>
        </div>
        <div class="table-wrap">
          <table class="tbl">
            <thead>
              <tr>
                <th>No. RM</th>
                <th>Nama Pasien</th>
                <th style="text-align:center">JK</th>
                <th>Tgl Lahir</th>
                <th>Alamat</th>
                <th style="text-align:center">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pasienList as $p): ?>
              <tr>
                <td><span class="td-rm"><?= htmlspecialchars($p['no_rkm_medis']) ?></span></td>
                <td><span class="td-nama"><?= htmlspecialchars($p['nm_pasien']) ?></span></td>
                <td style="text-align:center"><span class="badge-jk"><?= htmlspecialchars($p['jk']) ?></span></td>
                <td><?= date('d/m/Y',strtotime($p['tgl_lahir'])) ?></td>
                <td style="font-size:12px;color:var(--muted);"><?= htmlspecialchars(mb_strimwidth($p['alamat'],0,36,'…')) ?></td>
                <td style="text-align:center;">
                  <button type="button" class="btn btn-emerald" style="height:36px;padding:0 14px;font-size:12px;"
                          onclick="openModal('<?= htmlspecialchars($p['no_rkm_medis'],ENT_QUOTES) ?>','<?= htmlspecialchars($p['nm_pasien'],ENT_QUOTES) ?>')">
                    <i class="bi bi-person-check-fill"></i> Pilih
                  </button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>

      </div><!-- /results-card -->
    </div><!-- /right -->
  </div><!-- /content -->

  <!-- ════ FOOTER ════ -->
  <footer class="ftr">
    <div class="ftr-item"><i class="bi bi-telephone-fill"></i><strong><?= htmlspecialchars($setting['kontak']) ?></strong></div>
    <div class="ftr-sep"></div>
    <div class="ftr-item"><i class="bi bi-envelope-fill"></i><?= htmlspecialchars($setting['email']) ?></div>
    <div class="ftr-sep"></div>
    <div class="ftr-item"><i class="bi bi-geo-alt-fill"></i><?= htmlspecialchars($setting['kabupaten']) ?></div>
    <div class="ftr-copy">&copy; <?= date('Y') ?> &nbsp;<span><?= htmlspecialchars($setting['nama_instansi']) ?></span> &middot; <span>MediFix</span></div>
  </footer>

</div><!-- /shell -->

<!-- ════ MODAL PENDAFTARAN (custom, bukan Bootstrap modal) ════ -->
<div class="modal-overlay" id="modalOverlay">
  <form method="post" id="formDaftar">
    <div class="modal-box">
      <div class="modal-hdr">
        <div class="modal-hdr-icon"><i class="bi bi-clipboard2-pulse-fill"></i></div>
        <div>
          <div class="modal-hdr-title">Formulir Pendaftaran</div>
          <div class="modal-hdr-sub">Rawat Jalan / Poliklinik</div>
        </div>
        <button type="button" class="modal-close" onclick="closeModal()"><i class="bi bi-x-lg"></i></button>
      </div>

      <div class="modal-bdy">
        <input type="hidden" name="no_rkm_medis" id="no_rkm_medis">

        <div class="form-row form-row-2">
          <div class="form-grp">
            <label class="form-lbl"><i class="bi bi-person-circle"></i> Nama Pasien</label>
            <input type="text" id="nama_pasien" class="form-ctrl" readonly>
          </div>
          <div class="form-grp">
            <label class="form-lbl"><i class="bi bi-building-fill-add"></i> Poliklinik Tujuan</label>
            <select name="kd_poli" id="kd_poli" class="form-ctrl" required>
              <option value="">— Pilih Poliklinik —</option>
              <?php
              $poli = $pdo_simrs->prepare("SELECT DISTINCT j.kd_poli, p.nm_poli FROM jadwal j JOIN poliklinik p ON j.kd_poli=p.kd_poli WHERE j.hari_kerja=? ORDER BY p.nm_poli");
              $poli->execute([$hari_indo]);
              foreach ($poli as $pl)
                  echo "<option value='".htmlspecialchars($pl['kd_poli'])."'>".htmlspecialchars($pl['nm_poli'])."</option>";
              ?>
            </select>
          </div>
        </div>

        <div class="form-row form-row-2">
          <div class="form-grp">
            <label class="form-lbl"><i class="bi bi-person-badge-fill"></i> Dokter Pemeriksa</label>
            <select name="kd_dokter" id="kd_dokter" class="form-ctrl" required disabled>
              <option value="">— Pilih Poli Dahulu —</option>
            </select>
          </div>
          <div class="form-grp">
            <label class="form-lbl"><i class="bi bi-credit-card-fill"></i> Cara Pembayaran</label>
            <select name="kd_pj" id="kd_pj" class="form-ctrl" required>
              <option value="">— Pilih Cara Bayar —</option>
              <?php
              $penjab = $pdo_simrs->query("SELECT kd_pj, png_jawab FROM penjab ORDER BY png_jawab");
              foreach ($penjab as $pj)
                  echo "<option value='".htmlspecialchars($pj['kd_pj'])."'>".htmlspecialchars($pj['png_jawab'])."</option>";
              ?>
            </select>
          </div>
        </div>

        <div class="modal-info">
          <i class="bi bi-shield-check-fill"></i>
          <p>Pastikan semua data sudah benar sebelum menyimpan. Pendaftaran yang sudah disimpan tidak dapat diubah melalui anjungan ini.</p>
        </div>
      </div>

      <div class="modal-ftr">
        <button type="button" class="btn btn-ghost" onclick="closeModal()">
          <i class="bi bi-x-circle"></i> Batal
        </button>
        <button type="submit" class="btn btn-teal">
          <i class="bi bi-check-circle-fill"></i> Simpan Pendaftaran
        </button>
      </div>
    </div>
  </form>
</div>

<!-- ════ VIRTUAL KEYBOARD ════ -->
<div class="vkbd" id="vKbd">
  <div class="vkbd-bar">
    <div class="vkbd-title"><i class="bi bi-keyboard-fill"></i> Keyboard Virtual</div>
    <button class="vkbd-close" onclick="closeKeyboard()"><i class="bi bi-x-lg"></i></button>
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

<!-- ════ PRINT AREA ════ -->
<?php if ($printPoli): ?>
<div class="print-area">
  <div style="width:7.5cm;height:11cm;padding:.4cm .3cm;font-family:'Courier New',monospace;background:#fff;display:flex;flex-direction:column;justify-content:space-between;">

    <div style="text-align:center;">
      <h2 style="font-size:14px;font-weight:900;margin:0 0 .1cm;color:#000;text-transform:uppercase;line-height:1.2;">
        <?= htmlspecialchars($setting['nama_instansi']) ?>
      </h2>
      <p style="font-size:8px;margin:.06cm 0;color:#444;line-height:1.3;"><?= htmlspecialchars($setting['alamat_instansi']) ?></p>
      <p style="font-size:8px;margin:.04cm 0;color:#444;">Telp: <?= htmlspecialchars($setting['kontak']) ?></p>
    </div>

    <div style="border-top:1px dashed #999;margin:.12cm 0;"></div>

    <div style="text-align:center;margin:.18cm 0;">
      <p style="font-size:10px;font-weight:700;margin:0 0 .12cm;color:#000;text-transform:uppercase;letter-spacing:.5px;">Nomor Antrian Poliklinik</p>
      <div style="background:linear-gradient(135deg,#0ea5a0,#075e5b);padding:.32cm .2cm;border-radius:8px;box-shadow:0 2px 6px rgba(0,0,0,.15);">
        <h1 style="font-size:48px;margin:0;font-weight:900;color:#fff;letter-spacing:3px;"><?= htmlspecialchars($printPoli['no_antrian']) ?></h1>
      </div>
      <span style="display:inline-block;padding:.1cm .32cm;margin:.1cm 0;border-radius:20px;font-size:9px;font-weight:800;background:#0ea5a0;color:#fff;">RAWAT JALAN</span>
    </div>

    <div style="border-top:1px dashed #999;margin:.12cm 0;"></div>

    <div style="margin:.1cm 0;padding:0 .05cm;">
      <p style="font-size:9px;margin:.08cm 0;color:#333;"><strong>No. RM :</strong> <?= htmlspecialchars($printPoli['no_rkm_medis']) ?></p>
      <p style="font-size:9px;margin:.08cm 0;color:#333;"><strong>Nama   :</strong> <?= htmlspecialchars($printPoli['nm_pasien']) ?></p>
      <p style="font-size:9px;margin:.08cm 0;color:#333;"><strong>Poli   :</strong> <?= htmlspecialchars($printPoli['nm_poli']) ?></p>
      <p style="font-size:9px;margin:.08cm 0;color:#333;"><strong>Dokter :</strong> <?= htmlspecialchars($printPoli['nm_dokter']) ?></p>
      <p style="font-size:9px;margin:.08cm 0;color:#333;"><strong>Rawat  :</strong> <?= htmlspecialchars($printPoli['no_rawat']) ?></p>
    </div>

    <div style="border-top:1px dashed #999;margin:.1cm 0;"></div>

    <div style="text-align:center;">
      <p style="font-size:8.5px;margin:.08cm 0;color:#333;line-height:1.4;">Silakan menuju ruang tunggu <strong><?= htmlspecialchars($printPoli['nm_poli']) ?></strong></p>
      <p style="font-size:8px;margin:.07cm 0;color:#0ea5a0;font-weight:700;">dan tunggu panggilan nomor antrian</p>
      <div style="border-top:1px solid #ddd;margin:.08cm 0;"></div>
      <p style="font-size:7.5px;margin:.05cm 0;color:#777;">Dicetak: <?= $printPoli['tgl_cetak'] ?></p>
      <p style="font-size:9px;margin:.07cm 0 0;font-weight:700;color:#000;">SEMOGA LEKAS SEMBUH</p>
    </div>

  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// ════ CLOCK ════
const HARI  = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
const BULAN = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
function pad2(n){ return String(n).padStart(2,'0'); }

function updateClock(){
    const now = new Date();
    const ts = pad2(now.getHours())+':'+pad2(now.getMinutes())+':'+pad2(now.getSeconds());
    const ds = HARI[now.getDay()]+', '+now.getDate()+' '+BULAN[now.getMonth()]+' '+now.getFullYear();
    ['elTime','elTimeCard'].forEach(id=>{ const el=document.getElementById(id); if(el) el.textContent=ts; });
    ['elDate','elDateCard'].forEach(id=>{ const el=document.getElementById(id); if(el) el.textContent=ds; });
}
updateClock(); setInterval(updateClock,1000);

// ════ VOICE ════
const srVoice = "<?= $searchResultVoice ?>";
const hasSwalSuccess = <?= ($swal_data && $swal_data['icon']==='success')?'true':'false' ?>;
const voiceFlags = {};

function speak(text){
    if (!('speechSynthesis' in window)) return;
    speechSynthesis.cancel();
    const u = new SpeechSynthesisUtterance(text);
    u.lang='id-ID'; u.rate=0.9; u.pitch=1.0; u.volume=1.0;
    const v = speechSynthesis.getVoices().find(v=>v.lang.includes('id'));
    if(v) u.voice=v;
    speechSynthesis.speak(u);
}
if ('speechSynthesis' in window) { speechSynthesis.onvoiceschanged=()=>speechSynthesis.getVoices(); speechSynthesis.getVoices(); }

const inputCari = document.getElementById('inputCari');

window.addEventListener('load',()=>{
    if (sessionStorage.getItem('skipVoiceAfterPrint')==='1'){ sessionStorage.removeItem('skipVoiceAfterPrint'); if(inputCari) inputCari.focus(); return; }
    if (hasSwalSuccess) return;
    setTimeout(()=>{
        if (!srVoice) speak("Selamat datang. Silakan cari nomor rekam medis atau nama pasien, kemudian klik tombol pilih untuk mendaftar.");
        else if (srVoice==='found') speak("Data ditemukan. Silakan klik tombol pilih pada nama Anda.");
        else speak("Data tidak ditemukan. Periksa kembali nomor rekam medis atau nama pasien.");
        if(inputCari) inputCari.focus();
    },500);
});

// ════ MODAL ════
function openModal(norm, nama){
    document.getElementById('no_rkm_medis').value = norm;
    document.getElementById('nama_pasien').value  = nama;
    document.getElementById('kd_poli').value    = '';
    document.getElementById('kd_dokter').innerHTML = '<option value="">— Pilih Poli Dahulu —</option>';
    document.getElementById('kd_dokter').disabled = true;
    document.getElementById('kd_pj').value = '';
    document.getElementById('modalOverlay').classList.add('show');
    setTimeout(()=>speak("Silakan pilih poliklinik tujuan."),300);
}
function closeModal(){
    document.getElementById('modalOverlay').classList.remove('show');
}
document.getElementById('modalOverlay').addEventListener('click',function(e){ if(e.target===this) closeModal(); });

// Dokter by poli
document.getElementById('kd_poli').addEventListener('change',function(){
    const val = this.value, txt = this.options[this.selectedIndex].text;
    if (!val) return;
    speak("Anda memilih "+txt+". Silakan pilih dokter pemeriksa.");
    const sel = document.getElementById('kd_dokter');
    sel.innerHTML='<option>— Memuat… —</option>'; sel.disabled=true;
    fetch('get_dokter_by_poli.php?kd_poli='+encodeURIComponent(val))
        .then(r=>r.json())
        .then(data=>{
            sel.innerHTML='<option value="">— Pilih Dokter —</option>';
            data.forEach(d=>{ sel.innerHTML+=`<option value="${d.kd_dokter}">${d.nm_dokter}</option>`; });
            sel.disabled=false;
        })
        .catch(()=>{ sel.innerHTML='<option>— Gagal memuat —</option>'; });
});

document.getElementById('kd_dokter').addEventListener('change',function(){
    if (this.value) speak("Anda memilih "+this.options[this.selectedIndex].text+". Silakan pilih cara pembayaran.");
});
document.getElementById('kd_pj').addEventListener('change',function(){
    if (this.value) speak("Anda memilih "+this.options[this.selectedIndex].text+". Klik simpan pendaftaran jika semua data sudah benar.");
});
document.getElementById('formDaftar').addEventListener('submit',()=>speak("Menyimpan pendaftaran. Mohon tunggu."));

// ════ VIRTUAL KEYBOARD ════
const vKbd = document.getElementById('vKbd');
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
    if (!inputCari) return;
    if      (k==='SPASI')     inputCari.value += ' ';
    else if (k==='HAPUS')     inputCari.value  = inputCari.value.slice(0,-1);
    else if (k==='BERSIHKAN') { inputCari.value=''; speak("Kolom dibersihkan."); }
    else                      inputCari.value += k;
    inputCari.focus(); updatePreview();
}

function updatePreview(){
    const p=document.getElementById('kbdPreview'); if(!p) return;
    const v=inputCari?inputCari.value:'';
    p.innerHTML=v?`<span>${v}</span><span class="vp-cur"></span>`:'<span class="vp-cur"></span>';
}

function toggleKeyboard(){
    if (vKbd.style.display==='block'){ closeKeyboard(); return; }
    vKbd.style.display='block';
    requestAnimationFrame(()=>{
        document.documentElement.style.setProperty('--kbd-h',vKbd.offsetHeight+'px');
        document.body.classList.add('kbd-open');
        if(inputCari){ inputCari.focus(); setTimeout(()=>inputCari.scrollIntoView({behavior:'smooth',block:'center'}),300); }
        updatePreview();
    });
    speak("Keyboard virtual dibuka. Silakan ketik nomor rekam medis atau nama pasien.");
}
function closeKeyboard(){
    vKbd.style.display='none';
    document.body.classList.remove('kbd-open');
    document.documentElement.style.removeProperty('--kbd-h');
    speak("Keyboard ditutup.");
}

if(inputCari) inputCari.addEventListener('input',updatePreview);
buildKbd();
</script>

<?php if ($swal_data): ?>
<script>
document.addEventListener('DOMContentLoaded',function(){
    const data = <?= json_encode($swal_data, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;

    if (data.icon==='success'){
        speechSynthesis.cancel();
        const noSpell = (data.no_antrian||'').split('').join(' ');
        setTimeout(()=>{
            speak(`Pendaftaran berhasil. Nomor antrian Anda adalah ${noSpell}. Poliklinik ${data.nm_poli}. Dokter ${data.nm_dokter}. Untuk ${data.nm_pasien}. Silakan cetak karcis. Semoga lekas sembuh.`);
        },800);

        Swal.fire({
            icon:'success',
            title:data.title,
            html:`<div style="font-size:15px;line-height:1.8;font-weight:600">${data.html||''}</div>`,
            showCancelButton:true,
            confirmButtonText:'<i class="bi bi-printer-fill me-2"></i>'+(data.confirmText||'Cetak'),
            cancelButtonText: '<i class="bi bi-x-circle me-2"></i>'   +(data.cancelText||'Tutup'),
            allowOutsideClick:false, allowEscapeKey:false,
            background:'#0f1e33', color:'#f5f8fa',
            customClass:{ popup:'swal-dark', confirmButton:'swal-btn-ok', cancelButton:'swal-btn-cancel' },
            buttonsStyling:false
        }).then(result=>{
            speechSynthesis.cancel();
            if (result.isConfirmed){
                sessionStorage.setItem('skipVoiceAfterPrint','1');
                window.print();
                if ('onafterprint' in window) window.onafterprint=()=>{ window.location=data.redirect||'daftar_poli.php'; };
                else setTimeout(()=>{ window.location=data.redirect||'daftar_poli.php'; },2000);
            } else { window.location=data.redirect||'daftar_poli.php'; }
        });

    } else {
        speak("Maaf, terjadi kesalahan. "+(data.text||''));
        Swal.fire({
            icon:data.icon||'error',
            title:data.title||'Gagal',
            html:`<div style="font-size:15px;font-weight:600">${data.text||''}</div>`,
            confirmButtonText:'<i class="bi bi-check-circle me-2"></i>'+(data.confirmText||'OK'),
            allowOutsideClick:false, allowEscapeKey:false,
            background:'#0f1e33', color:'#f5f8fa',
            customClass:{ popup:'swal-dark', confirmButton:'swal-btn-ok' },
            buttonsStyling:false
        }).then(()=>{ window.location=data.redirect||'daftar_poli.php'; });
    }
});
</script>
<style>
/* SweetAlert2 dark theme seragam */
.swal-dark { border:1px solid rgba(245,248,250,.08) !important; border-radius:18px !important; }
.swal-btn-ok {
    background:linear-gradient(135deg,#0ea5a0,#075e5b) !important; color:#fff !important;
    border:none !important; padding:10px 22px !important; border-radius:10px !important;
    font-family:'DM Sans',sans-serif !important; font-size:14px !important; font-weight:700 !important;
    cursor:pointer;
}
.swal-btn-cancel {
    background:rgba(255,255,255,.08) !important; color:rgba(245,248,250,.6) !important;
    border:1px solid rgba(245,248,250,.1) !important;
    padding:10px 22px !important; border-radius:10px !important;
    font-family:'DM Sans',sans-serif !important; font-size:14px !important; font-weight:700 !important;
    cursor:pointer;
}
</style>
<?php endif; ?>

</body>
</html>