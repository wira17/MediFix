<?php
session_start();
include 'koneksi.php';
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

$hari_ini = strtoupper(date('l'));
$hari_map = ['SUNDAY'=>'MINGGU','MONDAY'=>'SENIN','TUESDAY'=>'SELASA','WEDNESDAY'=>'RABU','THURSDAY'=>'KAMIS','FRIDAY'=>'JUMAT','SATURDAY'=>'SABTU'];
$hari_indo = $hari_map[$hari_ini] ?? 'SENIN';
$swal_data = null;

// Ambil nama RS
try {
    $stmtRS = $pdo_simrs->query("SELECT nama_instansi, alamat_instansi, kabupaten, propinsi, kontak, email FROM setting LIMIT 1");
    $setting = $stmtRS->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $setting = ['nama_instansi'=>'Nama Rumah Sakit','alamat_instansi'=>'','kabupaten'=>'','propinsi'=>'','kontak'=>'','email'=>''];
}

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

        $stmt_inap = $pdo_simrs->prepare("SELECT COUNT(*) FROM reg_periksa r JOIN kamar_inap k ON r.no_rawat = k.no_rawat WHERE r.no_rkm_medis=? AND k.stts_pulang='-'");
        $stmt_inap->execute([$no_rkm_medis]);
        if ($stmt_inap->fetchColumn() > 0)
            throw new Exception("Pasien sedang dalam perawatan inap!");

        $cek = $pdo_simrs->prepare("SELECT COUNT(*) FROM reg_periksa WHERE no_rkm_medis=? AND kd_poli=? AND kd_dokter=? AND tgl_registrasi=?");
        $cek->execute([$no_rkm_medis, $kd_poli, $kd_dokter, $tgl]);
        if ($cek->fetchColumn() > 0)
            throw new Exception("Pasien sudah terdaftar hari ini!");

        $stmt_no = $pdo_simrs->prepare("SELECT MAX(CAST(no_reg AS UNSIGNED)) FROM reg_periksa WHERE tgl_registrasi=?");
        $stmt_no->execute([$tgl]);
        $no_reg = str_pad((($stmt_no->fetchColumn() ?: 0) + 1), 3, '0', STR_PAD_LEFT);

        $stmt_rawat = $pdo_simrs->prepare("SELECT MAX(CAST(SUBSTRING(no_rawat, 12, 6) AS UNSIGNED)) FROM reg_periksa WHERE tgl_registrasi=?");
        $stmt_rawat->execute([$tgl]);
        $no_rawat = date('Y/m/d/') . str_pad(($stmt_rawat->fetchColumn() ?: 0) + 1, 6, '0', STR_PAD_LEFT);

        $stmt_pasien = $pdo_simrs->prepare("SELECT nm_pasien, alamat, tgl_lahir, keluarga AS hubunganpj, namakeluarga AS p_jawab, alamatpj FROM pasien WHERE no_rkm_medis=?");
        $stmt_pasien->execute([$no_rkm_medis]);
        $pasien = $stmt_pasien->fetch(PDO::FETCH_ASSOC);

        if (!$pasien) throw new Exception("Data pasien tidak ditemukan!");
        if (empty($pasien['tgl_lahir'])) throw new Exception("Tanggal lahir belum diinput!");

        $p_jawab    = $pasien['p_jawab']    ?: $pasien['nm_pasien'];
        $almt_pj    = $pasien['alamatpj']   ?: $pasien['alamat'];
        $hubunganpj = $pasien['hubunganpj'] ?: "-";

        $lahir    = new DateTime($pasien['tgl_lahir']);
        $umur     = (new DateTime())->diff($lahir)->y;

        $stmt_biaya = $pdo_simrs->prepare("SELECT registrasi, registrasilama FROM poliklinik WHERE kd_poli=?");
        $stmt_biaya->execute([$kd_poli]);
        $biaya     = $stmt_biaya->fetch(PDO::FETCH_ASSOC);
        $biaya_reg = ($stts_daftar == "Lama") ? $biaya['registrasilama'] : $biaya['registrasi'];

        $stmt = $pdo_simrs->prepare("INSERT INTO reg_periksa (no_reg,no_rawat,tgl_registrasi,jam_reg,kd_dokter,no_rkm_medis,kd_poli,p_jawab,almt_pj,hubunganpj,biaya_reg,stts,stts_daftar,status_lanjut,kd_pj,umurdaftar,sttsumur,status_bayar,status_poli) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$no_reg,$no_rawat,$tgl,$jam,$kd_dokter,$no_rkm_medis,$kd_poli,$p_jawab,$almt_pj,$hubunganpj,$biaya_reg,'Belum',$stts_daftar,'Ralan',$kd_pj,$umur,'Th','Belum Bayar',$status_poli]);

        // Ambil nama poli & dokter untuk karcis
        $stmtNmPoli = $pdo_simrs->prepare("SELECT nm_poli FROM poliklinik WHERE kd_poli=?");
        $stmtNmPoli->execute([$kd_poli]);
        $nmPoli = $stmtNmPoli->fetchColumn() ?: $kd_poli;

        $stmtNmDok = $pdo_simrs->prepare("SELECT nm_dokter FROM dokter WHERE kd_dokter=?");
        $stmtNmDok->execute([$kd_dokter]);
        $nmDokter = $stmtNmDok->fetchColumn() ?: $kd_dokter;

        // Simpan data print ke session untuk inline print
        $_SESSION['print_poli'] = [
            'no_reg'      => $no_reg,
            'no_rawat'    => $no_rawat,
            'no_antrian'  => $kd_poli . '-' . $no_reg,
            'kd_poli'     => $kd_poli,
            'nm_poli'     => $nmPoli,
            'nm_dokter'   => $nmDokter,
            'nm_pasien'   => $pasien['nm_pasien'],
            'no_rkm_medis'=> $no_rkm_medis,
            'tgl_cetak'   => date('d/m/Y H:i:s'),
        ];

        $swal_data = [
            'icon'       => 'success',
            'title'      => 'Pendaftaran Berhasil!',
            'html'       => "<strong>No. Rawat:</strong> {$no_rawat}<br><strong>No Antri:</strong> {$kd_poli}-{$no_reg}",
            'confirmText'=> 'Cetak',
            'cancelText' => 'Tutup',
            'redirect'   => 'anjungan.php',
            // Data untuk JS voice
            'nm_pasien'  => $pasien['nm_pasien'],
            'no_antrian' => $kd_poli . '-' . $no_reg,
            'nm_poli'    => $nmPoli,
            'nm_dokter'  => $nmDokter,
        ];

    } catch (Exception $e) {
        $swal_data = ['icon'=>'error','title'=>'Gagal!','text'=>$e->getMessage(),'confirmText'=>'OK','redirect'=>'daftar_poli.php'];
    }
}

// Proses pencarian
$searchResultVoice = "";
$pasienList = [];

// Ambil data print dari session jika ada
$printPoli = null;
if (isset($_SESSION['print_poli'])) {
    $printPoli = $_SESSION['print_poli'];
    unset($_SESSION['print_poli']); // hapus setelah dibaca sekali
}
// Hanya jalankan pencarian jika BUKAN proses simpan pendaftaran (POST)
// Ini mencegah suara "data ditemukan" muncul bersamaan dengan suara "berhasil mendaftar"
if (isset($_GET['cari']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $keyword = trim($_GET['cari']);
    $stmt = $pdo_simrs->prepare("SELECT no_rkm_medis,nm_pasien,jk,tgl_lahir,alamat FROM pasien WHERE no_rkm_medis LIKE ? OR nm_pasien LIKE ? LIMIT 20");
    $stmt->execute(["%$keyword%", "%$keyword%"]);
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
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=DM+Sans:wght@600;700;800;900&display=swap" rel="stylesheet">

<style>
/* ===== RESET & BASE ===== */
*{margin:0;padding:0;box-sizing:border-box}
:root{
  --blue-1:#1e40af;
  --blue-2:#2563eb;
  --blue-3:#3b82f6;
  --blue-4:#93c5fd;
  --blue-light:#dbeafe;
  --blue-xlight:#eff6ff;
  --dark:#0f172a;
  --mid:#334155;
  --muted:#64748b;
  --light:#f1f5f9;
  --white:#ffffff;
}
html,body{height:100vh;overflow:hidden;font-family:'Plus Jakarta Sans',sans-serif}
body{background:linear-gradient(135deg, var(--blue-1) 0%, var(--blue-2) 45%, #1d4ed8 100%);position:relative}

/* ===== ANIMATED BG ===== */
.bg-layer{position:fixed;inset:0;overflow:hidden;z-index:0;pointer-events:none}
.orb{position:absolute;border-radius:50%;filter:blur(60px);opacity:.25;animation:drift 18s ease-in-out infinite}
.orb-1{width:500px;height:500px;background:#60a5fa;top:-15%;left:-10%;animation-delay:0s}
.orb-2{width:380px;height:380px;background:#a78bfa;top:50%;right:-8%;animation-delay:5s}
.orb-3{width:300px;height:300px;background:#34d399;bottom:-10%;left:30%;animation-delay:10s}
.grid-lines{position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.04) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.04) 1px,transparent 1px);background-size:50px 50px}
@keyframes drift{0%,100%{transform:translate(0,0) scale(1)}33%{transform:translate(40px,-60px) scale(1.1)}66%{transform:translate(-30px,30px) scale(.9)}}

/* ===== MAIN WRAPPER ===== */
.main-wrapper{position:relative;z-index:1;height:100vh;display:flex;flex-direction:column}

/* ===== HEADER ===== */
.hdr{background:rgba(255,255,255,.97);backdrop-filter:blur(24px);padding:1.2vh 3vw;box-shadow:0 4px 30px rgba(0,0,0,.12);border-bottom:3px solid transparent;border-image:linear-gradient(90deg,#1e40af,#3b82f6,#60a5fa) 1}
.hdr-inner{display:flex;align-items:center;justify-content:space-between;max-width:1600px;margin:0 auto;gap:1.5vw;flex-wrap:wrap}
.hdr-brand{display:flex;align-items:center;gap:1.2vw}
.hdr-logo{width:clamp(48px,5vw,66px);height:clamp(48px,5vw,66px);background:linear-gradient(135deg,var(--blue-1),var(--blue-2));border-radius:16px;display:flex;align-items:center;justify-content:center;box-shadow:0 8px 24px rgba(37,99,235,.35);animation:pulse-logo 3s ease-in-out infinite;flex-shrink:0}
@keyframes pulse-logo{0%,100%{transform:scale(1)}50%{transform:scale(1.05)}}
.hdr-logo i{font-size:clamp(22px,2.2vw,30px);color:#fff}
.hdr-name h1{font-size:clamp(15px,1.7vw,24px);font-weight:900;background:linear-gradient(135deg,var(--blue-1),var(--blue-2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin:0;line-height:1.2;font-family:'DM Sans',sans-serif}
.hdr-name p{font-size:clamp(9px,.85vw,12px);color:var(--muted);margin:.2vh 0 0;font-weight:600}
.hdr-status{display:flex;align-items:center;gap:.7vw;background:linear-gradient(135deg,#10b981,#059669);padding:.7vh 1.4vw;border-radius:50px;box-shadow:0 4px 16px rgba(16,185,129,.3);flex-shrink:0}
.hdr-status i{color:#fff;font-size:clamp(12px,1.2vw,18px);animation:blink-dot 2s infinite}
@keyframes blink-dot{0%,100%{opacity:1}50%{opacity:.4}}
.hdr-status span{color:#fff;font-weight:700;font-size:clamp(10px,1vw,14px)}

/* ===== CONTENT ===== */
.content-area{flex:1;display:flex;padding:2vh 3vw 2vh;overflow:hidden;gap:2.5vw;max-width:1600px;margin:0 auto;width:100%}

/* ===== LEFT PANEL ===== */
.left-panel{width:clamp(260px,28vw,380px);display:flex;flex-direction:column;gap:2vh;flex-shrink:0}

.info-card{background:rgba(255,255,255,.95);backdrop-filter:blur(20px);border-radius:22px;padding:2.5vh 2vw;box-shadow:0 16px 50px rgba(0,0,0,.12);border:1.5px solid rgba(255,255,255,.6);position:relative;overflow:hidden}
.info-card::after{content:'';position:absolute;bottom:-30px;right:-30px;width:120px;height:120px;background:radial-gradient(circle,rgba(37,99,235,.12) 0%,transparent 70%);border-radius:50%}
.info-card h2{font-size:clamp(20px,2.5vw,34px);font-weight:900;background:linear-gradient(135deg,var(--blue-1),var(--blue-3));-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin:0 0 1vh;font-family:'DM Sans',sans-serif;position:relative;z-index:1}
.info-card p{color:var(--mid);font-size:clamp(11px,1.1vw,15px);font-weight:600;line-height:1.6;margin:0;position:relative;z-index:1}

.dt-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.2vw}
.dt-card{background:rgba(255,255,255,.95);backdrop-filter:blur(20px);border-radius:18px;padding:2vh 1.2vw;box-shadow:0 8px 28px rgba(0,0,0,.09);border:1.5px solid rgba(255,255,255,.6);position:relative;overflow:hidden;transition:transform .3s}
.dt-card:hover{transform:translateY(-4px)}
.dt-card::before{content:'';position:absolute;top:0;left:0;width:4px;height:100%;background:linear-gradient(180deg,var(--blue-2),var(--blue-3))}
.dt-ico{width:clamp(34px,3.5vw,48px);height:clamp(34px,3.5vw,48px);background:linear-gradient(135deg,var(--blue-light),#bfdbfe);border-radius:12px;display:flex;align-items:center;justify-content:center;margin-bottom:1.2vh}
.dt-ico i{font-size:clamp(16px,1.8vw,24px);background:linear-gradient(135deg,var(--blue-1),var(--blue-2));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.dt-lbl{font-size:clamp(8px,.8vw,11px);color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:1px;margin-bottom:.6vh}
.dt-val{font-size:clamp(13px,1.4vw,20px);font-weight:800;color:var(--dark);font-family:'DM Sans',sans-serif}

.tip-card{background:linear-gradient(135deg,rgba(254,249,195,.9),rgba(253,230,138,.9));backdrop-filter:blur(20px);border-radius:18px;padding:1.8vh 1.5vw;border:1.5px solid rgba(251,191,36,.5);display:flex;align-items:flex-start;gap:1vw;box-shadow:0 8px 28px rgba(251,191,36,.2)}
.tip-card i{font-size:clamp(20px,2.2vw,30px);color:#d97706;flex-shrink:0;margin-top:.2vh}
.tip-card p{color:#92400e;font-weight:700;font-size:clamp(10px,1vw,14px);margin:0;line-height:1.5}

/* ===== RIGHT PANEL ===== */
.right-panel{flex:1;display:flex;flex-direction:column;gap:1.8vh;overflow:hidden;min-width:0}

/* Search bar */
.search-wrap{background:rgba(255,255,255,.95);backdrop-filter:blur(20px);border-radius:20px;padding:2vh 2vw;box-shadow:0 12px 40px rgba(0,0,0,.1);border:1.5px solid rgba(255,255,255,.6);flex-shrink:0}
.search-inner{position:relative;margin-bottom:1.5vh}
.search-inner input{width:100%;height:clamp(48px,6.5vh,60px);border:2.5px solid #e2e8f0;border-radius:14px;font-size:clamp(13px,1.4vw,17px);font-weight:600;padding:0 clamp(90px,10vw,130px) 0 1.5vw;background:#fff;transition:all .3s;font-family:'Plus Jakarta Sans',sans-serif;color:var(--dark)}
.search-inner input:focus{border-color:var(--blue-2);box-shadow:0 0 0 4px rgba(37,99,235,.12);outline:0}
.search-inner input::placeholder{color:#94a3b8;font-weight:500}
.btn-srch{position:absolute;right:6px;top:50%;transform:translateY(-50%);height:clamp(36px,5vh,46px);padding:0 clamp(14px,1.5vw,22px);border-radius:10px;background:linear-gradient(135deg,var(--blue-2),var(--blue-1));border:0;color:#fff;font-weight:700;font-size:clamp(12px,1.2vw,15px);display:flex;align-items:center;gap:.5vw;transition:all .3s;white-space:nowrap}
.btn-srch:hover{transform:translateY(calc(-50% - 2px));box-shadow:0 6px 18px rgba(37,99,235,.4)}
.btn-srch i{font-size:clamp(14px,1.4vw,18px)}
.action-row{display:flex;gap:1vw;flex-wrap:wrap}
.btn-act{height:clamp(42px,5.5vh,52px);padding:0 clamp(14px,1.5vw,22px);border-radius:12px;font-weight:700;font-size:clamp(11px,1.1vw,14px);border:0;display:inline-flex;align-items:center;gap:.6vw;transition:all .35s cubic-bezier(.175,.885,.32,1.275);cursor:pointer;text-decoration:none;white-space:nowrap;box-shadow:0 4px 14px rgba(0,0,0,.15)}
.btn-act:hover{transform:translateY(-3px)}
.btn-act i{font-size:clamp(14px,1.5vw,18px)}
.btn-kbd{background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff}
.btn-kbd:hover{box-shadow:0 8px 20px rgba(245,158,11,.4);color:#fff}
.btn-exit{background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff}
.btn-exit:hover{box-shadow:0 8px 20px rgba(220,38,38,.4);color:#fff}

/* Results / Table */
.results-box{background:rgba(255,255,255,.95);backdrop-filter:blur(20px);border-radius:20px;padding:2vh 2vw;box-shadow:0 12px 40px rgba(0,0,0,.1);border:1.5px solid rgba(255,255,255,.6);flex:1;overflow:hidden;display:flex;flex-direction:column}
.results-hdr{font-size:clamp(12px,1.3vw,16px);font-weight:800;color:var(--dark);margin-bottom:1.5vh;display:flex;align-items:center;gap:.8vw}
.results-hdr i{color:var(--blue-2)}
.table-wrap{flex:1;overflow-y:auto}
.table-wrap::-webkit-scrollbar{width:5px}
.table-wrap::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:10px}
table.tbl{width:100%;border-collapse:collapse;font-size:clamp(11px,1.1vw,14px)}
table.tbl thead th{background:linear-gradient(135deg,var(--blue-1),var(--blue-2));color:#fff;padding:clamp(10px,1.3vh,14px) clamp(10px,1vw,14px);font-weight:700;font-size:clamp(10px,1vw,13px);text-transform:uppercase;letter-spacing:.4px;white-space:nowrap}
table.tbl thead th:first-child{border-radius:10px 0 0 0}
table.tbl thead th:last-child{border-radius:0 10px 0 0}
table.tbl tbody td{padding:clamp(10px,1.3vh,14px) clamp(10px,1vw,14px);vertical-align:middle;color:var(--mid);font-weight:600;border-bottom:1px solid #f1f5f9}
table.tbl tbody tr{transition:background .2s}
table.tbl tbody tr:hover{background:var(--blue-xlight)}
.no-rm{font-weight:800;color:var(--blue-1);font-family:'DM Sans',sans-serif}
.nm-pasien{font-weight:800;color:var(--dark)}
.badge-jk{display:inline-block;padding:3px 10px;border-radius:6px;font-size:clamp(9px,.9vw,11px);font-weight:800;color:#fff;background:var(--blue-2)}
.btn-pilih{background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:0;padding:clamp(6px,.8vh,10px) clamp(12px,1.2vw,18px);border-radius:10px;font-weight:700;font-size:clamp(11px,1.1vw,13px);display:inline-flex;align-items:center;gap:.4vw;transition:all .3s;box-shadow:0 3px 10px rgba(16,185,129,.25);white-space:nowrap}
.btn-pilih:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(16,185,129,.4)}
.btn-pilih i{font-size:clamp(13px,1.3vw,16px)}

/* Empty/Alert states */
.state-box{display:flex;flex-direction:column;align-items:center;justify-content:center;flex:1;gap:2vh;text-align:center;padding:3vh}
.state-icon-wrap{width:clamp(70px,8vw,100px);height:clamp(70px,8vw,100px);border-radius:50%;display:flex;align-items:center;justify-content:center}
.state-icon-wrap i{font-size:clamp(32px,4vw,52px)}
.state-empty .state-icon-wrap{background:linear-gradient(135deg,var(--blue-xlight),var(--blue-light))}
.state-empty .state-icon-wrap i{background:linear-gradient(135deg,var(--blue-1),var(--blue-3));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.state-empty h4{font-size:clamp(16px,1.8vw,22px);font-weight:800;color:var(--dark);margin:0}
.state-empty p{font-size:clamp(11px,1.1vw,14px);color:var(--muted);font-weight:600;margin:0}
.state-notfound .state-icon-wrap{background:linear-gradient(135deg,#fee2e2,#fecaca)}
.state-notfound .state-icon-wrap i{color:#ef4444}
.state-notfound h4{color:#991b1b;font-size:clamp(15px,1.6vw,20px);font-weight:800;margin:0}
.state-notfound p{color:#b91c1c;font-size:clamp(11px,1.1vw,14px);font-weight:600;margin:0}

/* ===== MODAL ===== */
.modal-content{border:0;border-radius:20px;overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,.25)}
.modal-hdr{background:linear-gradient(135deg,var(--blue-1),var(--blue-2));color:#fff;padding:2.2vh 2.5vw;border:0;display:flex;align-items:center;justify-content:space-between}
.modal-ttl{font-size:clamp(16px,1.8vw,22px);font-weight:800;display:flex;align-items:center;gap:.8vw;font-family:'DM Sans',sans-serif}
.modal-ttl i{font-size:clamp(18px,2vw,26px)}
.modal-bdy{padding:2.5vh 2.5vw;background:#f8fafc}
.form-lbl{font-weight:700;color:var(--mid);margin-bottom:.8vh;font-size:clamp(12px,1.2vw,14px);display:flex;align-items:center;gap:.5vw;text-transform:uppercase;letter-spacing:.4px}
.form-lbl i{color:var(--blue-2);font-size:clamp(14px,1.4vw,18px)}
.form-ctrl{width:100%;height:clamp(44px,6vh,52px);border:2.5px solid #e2e8f0;border-radius:12px;font-size:clamp(13px,1.3vw,15px);font-weight:600;padding:0 1.2vw;background:#fff;transition:all .3s;font-family:'Plus Jakarta Sans',sans-serif;color:var(--dark)}
.form-ctrl:focus{border-color:var(--blue-2);box-shadow:0 0 0 4px rgba(37,99,235,.12);outline:0}
.form-ctrl[readonly]{background:#f1f5f9;color:var(--muted)}
.modal-ftr{padding:2vh 2.5vw;border:0;background:#fff;justify-content:center;gap:1.2vw}
.btn-m{height:clamp(44px,6vh,52px);padding:0 clamp(20px,2.5vw,36px);border-radius:12px;font-weight:700;font-size:clamp(13px,1.3vw,16px);border:0;display:inline-flex;align-items:center;gap:.6vw;transition:all .3s}
.btn-m i{font-size:clamp(15px,1.5vw,20px)}
.btn-m-ok{background:linear-gradient(135deg,#10b981,#059669);color:#fff;box-shadow:0 4px 14px rgba(16,185,129,.3)}
.btn-m-ok:hover{transform:translateY(-2px);box-shadow:0 8px 22px rgba(16,185,129,.4);color:#fff}
.btn-m-cancel{background:#e5e7eb;color:#4b5563;font-weight:700}
.btn-m-cancel:hover{background:#d1d5db}
.alert-info-mod{background:linear-gradient(135deg,#dbeafe,#eff6ff);border:0;border-left:4px solid var(--blue-2);color:#1e40af;font-weight:700;border-radius:10px;padding:1.2vh 1.5vw;font-size:clamp(11px,1.1vw,14px)}

/* ===== VIRTUAL KEYBOARD ===== */
.vkbd{position:fixed;bottom:0;left:0;right:0;background:linear-gradient(135deg,#0f172a,#1e293b);padding:14px 20px 20px;box-shadow:0 -10px 50px rgba(0,0,0,.55);z-index:9999;display:none;animation:slideUp .3s ease-out}
@keyframes slideUp{from{transform:translateY(100%)}to{transform:translateY(0)}}
.vkbd-preview{background:rgba(255,255,255,.07);border:2px solid rgba(255,255,255,.15);border-radius:12px;padding:10px 18px;margin-bottom:12px;display:flex;align-items:center;gap:12px;min-height:48px}
.vkbd-prev-lbl{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;flex-shrink:0}
.vkbd-prev-txt{font-size:clamp(15px,1.8vw,20px);font-weight:700;color:#f1f5f9;font-family:'DM Sans',sans-serif;letter-spacing:.5px;flex:1;min-height:28px;word-break:break-all}
.vkbd-cursor{display:inline-block;width:2px;height:1.2em;background:var(--blue-3);margin-left:2px;vertical-align:middle;animation:blink .8s step-end infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:0}}
.vkbd-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;padding-bottom:10px;border-bottom:2px solid rgba(255,255,255,.1)}
.vkbd-title{font-size:clamp(13px,1.4vw,17px);font-weight:700;color:#f1f5f9;display:flex;align-items:center;gap:10px}
.vkbd-title i{color:var(--blue-3)}
.vkbd-close{width:36px;height:36px;border-radius:8px;background:#ef4444;color:#fff;border:0;font-size:18px;cursor:pointer;font-weight:700;transition:all .2s;display:flex;align-items:center;justify-content:center}
.vkbd-close:hover{background:#dc2626;transform:scale(1.08)}
.key-row{display:flex;justify-content:center;gap:6px;margin-bottom:6px}
.key{min-width:clamp(38px,4.8vw,54px);height:clamp(40px,5.5vh,50px);background:#1e293b;color:#f1f5f9;border:2px solid #334155;border-radius:10px;font-size:clamp(13px,1.4vw,16px);font-weight:700;cursor:pointer;transition:all .2s;font-family:'DM Sans',sans-serif}
.key:hover{background:var(--blue-2);border-color:var(--blue-2);transform:translateY(-2px);box-shadow:0 4px 12px rgba(37,99,235,.35)}
.key:active{transform:translateY(0)}
.key.k-sp{background:linear-gradient(135deg,var(--blue-2),var(--blue-1));color:#fff;border-color:var(--blue-2);min-width:clamp(110px,14vw,190px);font-size:clamp(11px,1.1vw,14px)}
.key.k-del{background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;border-color:#ef4444;min-width:clamp(80px,10vw,110px);font-size:clamp(11px,1.1vw,13px)}
.key.k-clr{background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border-color:#f59e0b;min-width:clamp(70px,9vw,100px);font-size:clamp(11px,1.1vw,13px)}

/* Saat keyboard buka */
body.kbd-open{overflow:auto}
body.kbd-open .main-wrapper{padding-bottom:var(--kbd-h,320px);height:auto;min-height:100vh}
body.kbd-open .content-area{overflow-y:auto}

/* ===== FOOTER ===== */
.ftr{background:rgba(255,255,255,.95);backdrop-filter:blur(20px);padding:1.1vh 3vw;border-top:2px solid rgba(37,99,235,.15);box-shadow:0 -4px 24px rgba(0,0,0,.08)}
.ftr-inner{display:flex;align-items:center;justify-content:space-between;max-width:1600px;margin:0 auto;flex-wrap:wrap;gap:.8vh 2vw}
.ftr-item{display:flex;align-items:center;gap:.5vw;color:var(--muted);font-size:clamp(9px,.9vw,12px);font-weight:600}
.ftr-item i{font-size:clamp(12px,1.1vw,15px);color:var(--blue-2)}
.ftr-item .hl{color:var(--dark);font-weight:800}

/* ===== RESPONSIVE ===== */
@media(max-width:1100px){.content-area{flex-direction:column;overflow-y:auto}.left-panel{width:100%;flex-direction:row;flex-wrap:wrap}.dt-grid{flex:1;min-width:280px}}
@media(max-width:768px){.hdr-inner{flex-direction:column}.action-row{flex-wrap:wrap}.dt-grid{grid-template-columns:1fr}}

/* ===== PRINT ===== */
@media print{
  @page{size:7.5cm 11cm;margin:0}
  body>*:not(.print-area){display:none!important}
  .print-area{display:block!important;position:static!important;width:7.5cm!important;height:11cm!important;padding:0!important;margin:0!important;background:#fff!important;overflow:hidden!important}
  .print-area *{visibility:visible!important}
}
.print-area{display:none}
</style>
</head>
<body>

<!-- BG Layer -->
<div class="bg-layer">
  <div class="orb orb-1"></div>
  <div class="orb orb-2"></div>
  <div class="orb orb-3"></div>
  <div class="grid-lines"></div>
</div>

<div class="main-wrapper">

  <!-- HEADER -->
  <div class="hdr">
    <div class="hdr-inner">
      <div class="hdr-brand">
        <div class="hdr-logo"><i class="bi bi-hospital-fill"></i></div>
        <div class="hdr-name">
          <h1><?= htmlspecialchars($setting['nama_instansi']) ?></h1>
          <p><?= htmlspecialchars($setting['alamat_instansi']) ?> &bull; <?= htmlspecialchars($setting['kabupaten']) ?>, <?= htmlspecialchars($setting['propinsi']) ?></p>
        </div>
      </div>
      <div class="hdr-status">
        <i class="bi bi-circle-fill"></i>
        <span>Sistem Online</span>
      </div>
    </div>
  </div>

  <!-- CONTENT -->
  <div class="content-area">

    <!-- LEFT PANEL -->
    <div class="left-panel">
      <div class="info-card">
        <h2>🏥 Daftar Poli</h2>
        <p>Cari pasien menggunakan <strong>No. RM</strong> atau <strong>Nama Pasien</strong>, lalu pilih poliklinik dan dokter yang dituju.</p>
      </div>

      <div class="dt-grid">
        <div class="dt-card">
          <div class="dt-ico"><i class="bi bi-calendar-event-fill"></i></div>
          <div class="dt-lbl">Tanggal</div>
          <div class="dt-val" id="elTgl">–</div>
        </div>
        <div class="dt-card">
          <div class="dt-ico"><i class="bi bi-clock-history"></i></div>
          <div class="dt-lbl">Waktu</div>
          <div class="dt-val" id="elWkt">–</div>
        </div>
      </div>

      <div class="tip-card">
        <i class="bi bi-lightbulb-fill"></i>
        <p>Gunakan keyboard virtual di layar sentuh. Klik <strong>PILIH</strong> pada nama pasien untuk membuka formulir pendaftaran.</p>
      </div>
    </div>

    <!-- RIGHT PANEL -->
    <div class="right-panel">

      <!-- Search -->
      <div class="search-wrap">
        <form method="get" id="formSearch">
          <div class="search-inner">
            <input type="text" id="inputCari" name="cari"
                   placeholder="Ketik No. RM atau Nama Pasien..."
                   value="<?= htmlspecialchars($_GET['cari'] ?? '') ?>"
                   autocomplete="off">
            <button class="btn-srch" type="submit">
              <i class="bi bi-search"></i> CARI
            </button>
          </div>
        </form>
        <div class="action-row">
          <button type="button" class="btn-act btn-kbd" onclick="toggleKeyboard()">
            <i class="bi bi-keyboard-fill"></i> KEYBOARD VIRTUAL
          </button>
          <a href="anjungan.php" class="btn-act btn-exit">
            <i class="bi bi-box-arrow-left"></i> KELUAR
          </a>
        </div>
      </div>

      <!-- Results -->
      <div class="results-box">
        <?php if (empty($_GET['cari'])): ?>
          <!-- Empty state -->
          <div class="state-box state-empty">
            <div class="state-icon-wrap"><i class="bi bi-search"></i></div>
            <h4>Silakan Cari Pasien</h4>
            <p>Masukkan No. RM atau nama pasien di kolom pencarian di atas</p>
          </div>

        <?php elseif ($searchResultVoice === 'notFound'): ?>
          <!-- Not found -->
          <div class="state-box state-notfound">
            <div class="state-icon-wrap"><i class="bi bi-person-x-fill"></i></div>
            <h4>Data Tidak Ditemukan</h4>
            <p>Silakan periksa kembali No. RM atau nama pasien yang Anda masukkan</p>
          </div>

        <?php else: ?>
          <!-- Table results -->
          <div class="results-hdr">
            <i class="bi bi-people-fill"></i>
            Ditemukan <?= count($pasienList) ?> data pasien
          </div>
          <div class="table-wrap">
            <table class="tbl">
              <thead>
                <tr>
                  <th>No. RM</th>
                  <th>Nama Pasien</th>
                  <th style="text-align:center">JK</th>
                  <th style="text-align:center">Tgl Lahir</th>
                  <th>Alamat</th>
                  <th style="text-align:center">Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($pasienList as $p): ?>
                <tr>
                  <td><span class="no-rm"><?= htmlspecialchars($p['no_rkm_medis']) ?></span></td>
                  <td><span class="nm-pasien"><?= htmlspecialchars($p['nm_pasien']) ?></span></td>
                  <td style="text-align:center"><span class="badge-jk"><?= htmlspecialchars($p['jk']) ?></span></td>
                  <td style="text-align:center"><?= date('d/m/Y', strtotime($p['tgl_lahir'])) ?></td>
                  <td><?= htmlspecialchars($p['alamat']) ?></td>
                  <td style="text-align:center">
                    <button type="button" class="btn-pilih"
                            data-bs-toggle="modal" data-bs-target="#modalDaftar"
                            data-norm="<?= htmlspecialchars($p['no_rkm_medis']) ?>"
                            data-nama="<?= htmlspecialchars($p['nm_pasien']) ?>">
                      <i class="bi bi-person-check-fill"></i> PILIH
                    </button>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

    </div><!-- end right-panel -->

  </div><!-- end content-area -->

  <!-- FOOTER -->
  <div class="ftr">
    <div class="ftr-inner">
      <div class="ftr-item"><i class="bi bi-telephone-fill"></i><span class="hl"><?= htmlspecialchars($setting['kontak']) ?></span></div>
      <div class="ftr-item"><i class="bi bi-envelope-fill"></i><span><?= htmlspecialchars($setting['email']) ?></span></div>
      <div class="ftr-item"><i class="bi bi-c-circle"></i><span><?= date('Y') ?> <span class="hl"><?= htmlspecialchars($setting['nama_instansi']) ?></span></span></div>
      <div class="ftr-item"><i class="bi bi-code-slash"></i><span>Powered by <span class="hl">MediFix</span></span></div>
    </div>
  </div>

</div><!-- end main-wrapper -->

<!-- ===== MODAL DAFTAR POLI ===== -->
<div class="modal fade" id="modalDaftar" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <form method="post" id="formDaftar">
      <div class="modal-content">
        <div class="modal-hdr">
          <div class="modal-ttl">
            <i class="bi bi-clipboard2-pulse-fill"></i> FORMULIR PENDAFTARAN POLIKLINIK
          </div>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-bdy">
          <input type="hidden" name="no_rkm_medis" id="no_rkm_medis">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-lbl"><i class="bi bi-person-circle"></i> NAMA PASIEN</label>
              <input type="text" id="nama_pasien" class="form-ctrl" readonly>
            </div>
            <div class="col-md-6">
              <label class="form-lbl"><i class="bi bi-building-fill-add"></i> POLIKLINIK TUJUAN</label>
              <select name="kd_poli" id="kd_poli" class="form-ctrl" required>
                <option value="">-- Pilih Poliklinik --</option>
                <?php
                $poli = $pdo_simrs->prepare("SELECT DISTINCT j.kd_poli, p.nm_poli FROM jadwal j JOIN poliklinik p ON j.kd_poli=p.kd_poli WHERE j.hari_kerja=? ORDER BY p.nm_poli");
                $poli->execute([$hari_indo]);
                foreach ($poli as $pl) {
                    echo "<option value='".htmlspecialchars($pl['kd_poli'])."'>".htmlspecialchars($pl['nm_poli'])."</option>";
                }
                ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-lbl"><i class="bi bi-person-badge-fill"></i> DOKTER PEMERIKSA</label>
              <select name="kd_dokter" id="kd_dokter" class="form-ctrl" required disabled>
                <option value="">-- Pilih Poli Terlebih Dahulu --</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-lbl"><i class="bi bi-credit-card-fill"></i> CARA PEMBAYARAN</label>
              <select name="kd_pj" id="kd_pj" class="form-ctrl" required>
                <option value="">-- Pilih Cara Bayar --</option>
                <?php
                $penjab = $pdo_simrs->query("SELECT kd_pj, png_jawab FROM penjab ORDER BY png_jawab");
                foreach ($penjab as $pj) {
                    echo "<option value='".htmlspecialchars($pj['kd_pj'])."'>".htmlspecialchars($pj['png_jawab'])."</option>";
                }
                ?>
              </select>
            </div>
            <div class="col-12">
              <div class="alert-info-mod">
                <i class="bi bi-info-circle-fill me-2"></i>
                <strong>Perhatian:</strong> Pastikan semua data sudah benar sebelum menyimpan pendaftaran.
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer modal-ftr">
          <button type="submit" class="btn-m btn-m-ok">
            <i class="bi bi-check-circle-fill"></i> SIMPAN PENDAFTARAN
          </button>
          <button type="button" class="btn-m btn-m-cancel" data-bs-dismiss="modal">
            <i class="bi bi-x-circle-fill"></i> BATAL
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ===== VIRTUAL KEYBOARD ===== -->
<div id="vKbd" class="vkbd">
  <div class="vkbd-head">
    <div class="vkbd-title"><i class="bi bi-keyboard-fill"></i> KEYBOARD VIRTUAL</div>
    <button class="vkbd-close" onclick="closeKeyboard()" title="Tutup"><i class="bi bi-x-lg"></i></button>
  </div>
  <div class="vkbd-preview">
    <span class="vkbd-prev-lbl">Input:</span>
    <span class="vkbd-prev-txt" id="kbdPreview"><span class="vkbd-cursor"></span></span>
  </div>
  <div id="kbdR1" class="key-row"></div>
  <div id="kbdR2" class="key-row"></div>
  <div id="kbdR3" class="key-row"></div>
  <div id="kbdR4" class="key-row"></div>
  <div id="kbdR5" class="key-row"></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ===== VOICE =====
const searchResultVoice = "<?= $searchResultVoice ?>";
const voiceFlags = {welcome:false,keyboard:false,result:false,modal:false,poli:false,dokter:false,payment:false,submit:false};

function speak(text) {
  if (!('speechSynthesis' in window)) return;
  window.speechSynthesis.cancel();
  const u = new SpeechSynthesisUtterance(text);
  u.lang='id-ID'; u.rate=0.9; u.pitch=1.0; u.volume=1.0;
  const voices = window.speechSynthesis.getVoices();
  const idv = voices.find(v => v.lang.includes('id'));
  if (idv) u.voice = idv;
  window.speechSynthesis.speak(u);
}

if ('speechSynthesis' in window) {
  speechSynthesis.onvoiceschanged = () => speechSynthesis.getVoices();
  speechSynthesis.getVoices();
}

const inputCari = document.getElementById('inputCari');

window.addEventListener('load', () => {
  // Skip suara jika baru redirect dari cetak karcis
  const skipVoice = sessionStorage.getItem('skipVoiceAfterPrint') === '1';
  if (skipVoice) {
    sessionStorage.removeItem('skipVoiceAfterPrint');
    if (inputCari) inputCari.focus();
    return;
  }
  // Skip welcome jika sedang ada notifikasi pendaftaran berhasil
  if (hasSwalSuccess) return;

  setTimeout(() => {
    if (!voiceFlags.welcome && !searchResultVoice) {
      speak("Selamat datang di anjungan pendaftaran pasien mandiri. Silakan cari nama pasien atau nomor rekam medis dengan mengetik atau klik tombol keyboard.");
      voiceFlags.welcome = true;
    }
    if (inputCari) inputCari.focus();
  }, 500);
});

// Suara hasil pencarian — hanya jika TIDAK ada pendaftaran berhasil yang sedang diproses
const hasSwalSuccess = <?= ($swal_data && $swal_data['icon'] === 'success') ? 'true' : 'false' ?>;

if (!hasSwalSuccess && searchResultVoice === "found" && !voiceFlags.result) {
  setTimeout(() => {
    speak("Data ditemukan. Silakan klik tombol pilih pada nama Anda.");
    voiceFlags.result = true;
  }, 600);
} else if (!hasSwalSuccess && searchResultVoice === "notFound" && !voiceFlags.result) {
  setTimeout(() => {
    speak("Data tidak ditemukan. Silakan periksa kembali nomor rekam medis atau nama pasien Anda.");
    voiceFlags.result = true;
  }, 600);
}

// Modal
const modalEl = document.getElementById('modalDaftar');
modalEl.addEventListener('show.bs.modal', e => {
  const btn = e.relatedTarget;
  document.getElementById('no_rkm_medis').value = btn.dataset.norm;
  document.getElementById('nama_pasien').value  = btn.dataset.nama;
  if (!voiceFlags.modal) {
    setTimeout(() => { speak("Silakan pilih poliklinik tujuan."); voiceFlags.modal = true; }, 700);
  }
});

const kdPoli   = document.getElementById('kd_poli');
const kdDokter = document.getElementById('kd_dokter');
const kdPj     = document.getElementById('kd_pj');

kdPoli.addEventListener('change', function() {
  const val = this.value, txt = this.options[this.selectedIndex].text;
  if (val) {
    if (!voiceFlags.poli) { speak(`Anda memilih ${txt}. Silakan pilih dokter pemeriksa.`); voiceFlags.poli = true; }
    kdDokter.innerHTML = '<option value="">-- Memuat data dokter... --</option>';
    kdDokter.disabled = true;
    fetch('get_dokter_by_poli.php?kd_poli=' + encodeURIComponent(val))
      .then(r => r.json())
      .then(data => {
        kdDokter.innerHTML = '<option value="">-- Pilih Dokter --</option>';
        data.forEach(d => { kdDokter.innerHTML += `<option value="${d.kd_dokter}">${d.nm_dokter}</option>`; });
        kdDokter.disabled = false;
      })
      .catch(() => { kdDokter.innerHTML = '<option value="">-- Gagal memuat --</option>'; });
  }
});

kdDokter.addEventListener('change', function() {
  if (this.value && !voiceFlags.dokter) {
    speak(`Anda memilih ${this.options[this.selectedIndex].text}. Silakan pilih cara pembayaran.`);
    voiceFlags.dokter = true;
  }
});

kdPj.addEventListener('change', function() {
  if (this.value && !voiceFlags.payment) {
    speak(`Anda memilih ${this.options[this.selectedIndex].text}. Pastikan semua data sudah benar, kemudian klik simpan pendaftaran.`);
    voiceFlags.payment = true;
  }
});

document.getElementById('formDaftar').addEventListener('submit', () => {
  if (!voiceFlags.submit) { speak("Menyimpan data pendaftaran. Mohon tunggu sebentar."); voiceFlags.submit = true; }
});

// ===== VIRTUAL KEYBOARD =====
const vKbd   = document.getElementById('vKbd');

const kbdRows = [
  ['1','2','3','4','5','6','7','8','9','0'],
  ['Q','W','E','R','T','Y','U','I','O','P'],
  ['A','S','D','F','G','H','J','K','L'],
  ['Z','X','C','V','B','N','M'],
  ['SPASI','HAPUS','BERSIHKAN']
];

function buildKeyboard() {
  const ids = ['kbdR1','kbdR2','kbdR3','kbdR4','kbdR5'];
  kbdRows.forEach((keys, i) => {
    const row = document.getElementById(ids[i]);
    if (!row) return;
    keys.forEach(k => {
      const btn = document.createElement('button');
      btn.type = 'button';
      if (k === 'SPASI')      { btn.className='key k-sp';  btn.innerHTML='<i class="bi bi-space"></i> SPASI'; }
      else if (k === 'HAPUS') { btn.className='key k-del'; btn.innerHTML='<i class="bi bi-backspace-fill"></i> HAPUS'; }
      else if (k === 'BERSIHKAN') { btn.className='key k-clr'; btn.innerHTML='<i class="bi bi-x-lg"></i> BERSIHKAN'; }
      else { btn.className='key'; btn.textContent=k; }
      btn.addEventListener('click', () => pressKey(k));
      row.appendChild(btn);
    });
  });
}

function pressKey(k) {
  if (!inputCari) return;
  if (k === 'SPASI')      inputCari.value += ' ';
  else if (k === 'HAPUS')      inputCari.value = inputCari.value.slice(0, -1);
  else if (k === 'BERSIHKAN') { inputCari.value = ''; speak("Kolom dibersihkan."); }
  else inputCari.value += k;
  inputCari.focus();
  updatePreview();
}

function updatePreview() {
  const p = document.getElementById('kbdPreview');
  if (!p || !inputCari) return;
  const v = inputCari.value || '';
  p.innerHTML = v
    ? `<span style="color:#f1f5f9">${v}</span><span class="vkbd-cursor"></span>`
    : `<span class="vkbd-cursor"></span>`;
}

function toggleKeyboard() {
  if (vKbd.style.display === 'block') { closeKeyboard(); return; }
  vKbd.style.display = 'block';
  requestAnimationFrame(() => {
    document.documentElement.style.setProperty('--kbd-h', vKbd.offsetHeight + 'px');
    document.body.classList.add('kbd-open');
    if (inputCari) {
      inputCari.focus();
      setTimeout(() => inputCari.scrollIntoView({ behavior:'smooth', block:'center' }), 350);
    }
    updatePreview();
  });
  if (!voiceFlags.keyboard) {
    speak("Keyboard dibuka. Silakan ketik nomor rekam medis atau nama pasien, lalu klik tombol cari.");
    voiceFlags.keyboard = true;
  }
}

function closeKeyboard() {
  vKbd.style.display = 'none';
  document.body.classList.remove('kbd-open');
  document.documentElement.style.removeProperty('--kbd-h');
  speak("Keyboard ditutup.");
}

if (inputCari) inputCari.addEventListener('input', updatePreview);
buildKeyboard();

// ===== CLOCK =====
function updateClock() {
  const now = new Date();
  document.getElementById('elTgl').textContent = now.toLocaleDateString('id-ID', {day:'2-digit',month:'short',year:'numeric'});
  document.getElementById('elWkt').textContent = now.toLocaleTimeString('id-ID', {hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false});
}
setInterval(updateClock, 1000);
updateClock();
</script>

<?php if ($swal_data): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  const data = <?= json_encode($swal_data, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  if (data.icon === 'success') {

    // Batalkan semua suara yang mungkin masih berjalan, lalu ucapkan info pendaftaran
    window.speechSynthesis.cancel();
    const noAntrian = data.no_antrian || '';
    const noSpell   = noAntrian.split('').join(' ');
    setTimeout(() => {
      speak(`Pendaftaran berhasil. Nomor antrian Anda adalah ${noSpell}.  ${data.nm_poli}.  ${data.nm_dokter}. Untuk  ${data.nm_pasien}. Silakan cetak karcis dan tunggu panggilan. Semoga lekas sembuh.`);
    }, 800);

    Swal.fire({
      icon: data.icon,
      title: data.title,
      html: `<div style="font-size:16px;line-height:1.8;font-weight:600">${data.html||''}</div>`,
      showCancelButton: true,
      confirmButtonText: '<i class="bi bi-printer-fill me-2"></i>' + (data.confirmText||'Cetak'),
      cancelButtonText:  '<i class="bi bi-x-circle me-2"></i>'    + (data.cancelText||'Tutup'),
      allowOutsideClick: false,
      allowEscapeKey: false,
      customClass: {confirmButton:'btn-m btn-m-ok', cancelButton:'btn-m btn-m-cancel'},
      buttonsStyling: false
    }).then(result => {
      window.speechSynthesis.cancel();
      if (result.isConfirmed) {
        // Inline print seperti farmasi
        sessionStorage.setItem('skipVoiceAfterPrint', '1');
        window.print();
        if ('onafterprint' in window) {
          window.onafterprint = () => { window.location = data.redirect || 'daftar_poli.php'; };
        } else {
          setTimeout(() => { window.location = data.redirect || 'daftar_poli.php'; }, 2000);
        }
      } else {
        window.location = data.redirect || 'daftar_poli.php';
      }
    });

  } else {
    speak("Maaf, terjadi kesalahan. " + (data.text || ''));
    Swal.fire({
      icon: data.icon||'error',
      title: data.title||'Perhatian',
      html: `<div style="font-size:16px;font-weight:600">${data.text||''}</div>`,
      confirmButtonText: '<i class="bi bi-check-circle me-2"></i>' + (data.confirmText||'OK'),
      allowOutsideClick: false,
      allowEscapeKey: false,
      customClass: {confirmButton:'btn-m btn-m-ok'},
      buttonsStyling: false
    }).then(() => { window.location = data.redirect || 'daftar_poli.php'; });
  }
});
</script>
<?php endif; ?>

<!-- ===== PRINT AREA KARCIS 7.5cm x 11cm ===== -->
<?php if ($printPoli): ?>
<div class="print-area" id="printArea">
  <div style="width:7.5cm;height:11cm;padding:.4cm .3cm;font-family:'Courier New',monospace;background:#fff;display:flex;flex-direction:column;justify-content:space-between">

    <!-- Header RS -->
    <div style="text-align:center;margin-bottom:.15cm">
      <h2 style="font-size:15px;font-weight:900;margin:0 0 .12cm;color:#000;text-transform:uppercase;letter-spacing:.3px;line-height:1.2">
        <?= htmlspecialchars($setting['nama_instansi']) ?>
      </h2>
      <p style="font-size:8.5px;margin:.08cm 0;color:#333;line-height:1.3"><?= htmlspecialchars($setting['alamat_instansi']) ?></p>
      <p style="font-size:8.5px;margin:.05cm 0;color:#333;line-height:1.2"><?= htmlspecialchars($setting['kabupaten']) ?>, <?= htmlspecialchars($setting['propinsi']) ?></p>
      <p style="font-size:8px;margin:.05cm 0;color:#333;line-height:1.2">Telp: <?= htmlspecialchars($setting['kontak']) ?></p>
    </div>

    <div style="border-top:1.5px dashed #333;margin:.12cm 0"></div>

    <!-- Nomor Antrian -->
    <div style="text-align:center;margin:.2cm 0">
      <p style="font-size:11px;font-weight:700;margin:0 0 .15cm;color:#000;text-transform:uppercase;letter-spacing:.5px">Nomor Antrian Poliklinik</p>
      <div style="background:linear-gradient(135deg,#1e40af,#2563eb);padding:.35cm .3cm;border-radius:8px;margin:.08cm 0;box-shadow:0 2px 6px rgba(0,0,0,.15)">
        <h1 style="font-size:52px;margin:0;font-weight:900;color:#fff;letter-spacing:3px;text-shadow:0 3px 8px rgba(0,0,0,.3)">
          <?= htmlspecialchars($printPoli['no_antrian']) ?>
        </h1>
      </div>
      <span style="display:inline-block;padding:.12cm .35cm;margin:.12cm 0;border-radius:20px;font-size:10px;font-weight:800;background:#2563eb;color:#fff">
        RAWAT JALAN
      </span>
    </div>

    <div style="border-top:1.5px dashed #333;margin:.12cm 0"></div>

    <!-- Detail Pasien -->
    <div style="margin:.12cm 0;text-align:left;padding:0 .1cm">
      <p style="font-size:9.5px;margin:.08cm 0;color:#333;line-height:1.3"><strong>No. RM:</strong> <?= htmlspecialchars($printPoli['no_rkm_medis']) ?></p>
      <p style="font-size:9.5px;margin:.08cm 0;color:#333;line-height:1.3"><strong>Nama:</strong> <?= htmlspecialchars($printPoli['nm_pasien']) ?></p>
      <p style="font-size:9.5px;margin:.08cm 0;color:#333;line-height:1.3"><strong>Poli:</strong> <?= htmlspecialchars($printPoli['nm_poli']) ?></p>
      <p style="font-size:9.5px;margin:.08cm 0;color:#333;line-height:1.3"><strong>Dokter:</strong> <?= htmlspecialchars($printPoli['nm_dokter']) ?></p>
      <p style="font-size:9.5px;margin:.08cm 0;color:#333;line-height:1.3"><strong>No. Rawat:</strong> <?= htmlspecialchars($printPoli['no_rawat']) ?></p>
    </div>

    <div style="border-top:1.5px dashed #333;margin:.12cm 0"></div>

    <!-- Instruksi -->
    <div style="margin:.1cm 0;text-align:center">
      <p style="font-size:8.5px;margin:.1cm 0;color:#333;line-height:1.4"><strong>Terima kasih</strong>, silakan menuju</p>
      <p style="font-size:8.5px;margin:.06cm 0;color:#333;line-height:1.4">ruang tunggu <strong><?= htmlspecialchars($printPoli['nm_poli']) ?></strong></p>
      <p style="font-size:8px;margin:.08cm 0;color:#1e40af;line-height:1.3;font-weight:700">dan tunggu panggilan nomor antrian</p>
    </div>

    <div style="border-top:1px dashed #ccc;margin:.1cm 0"></div>

    <!-- Footer Karcis -->
    <div style="text-align:center">
      <p style="font-size:7.5px;margin:.06cm 0;color:#666;line-height:1.2">Dicetak: <?= $printPoli['tgl_cetak'] ?></p>
      <p style="font-size:7.5px;margin:.06cm 0;color:#666;line-height:1.2">Sistem MediFix | 082177846209</p>
      <p style="font-size:9px;margin:.08cm 0 0;font-weight:700;color:#000;letter-spacing:.3px">SEMOGA LEKAS SEMBUH</p>
    </div>

  </div>
</div>
<?php endif; ?>

</body>
</html>