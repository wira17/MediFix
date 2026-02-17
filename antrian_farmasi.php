<?php
session_start();
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

$printData   = null;
$errorMsg    = null;
$searchResult = null;
$showSearch  = true;

$hari_map = ['Sunday'=>'Minggu','Monday'=>'Senin','Tuesday'=>'Selasa','Wednesday'=>'Rabu','Thursday'=>'Kamis','Friday'=>'Jumat','Saturday'=>'Sabtu'];
$bulan_map = ['January'=>'Januari','February'=>'Februari','March'=>'Maret','April'=>'April','May'=>'Mei','June'=>'Juni','July'=>'Juli','August'=>'Agustus','September'=>'September','October'=>'Oktober','November'=>'November','December'=>'Desember'];
$hariIni    = $hari_map[date('l')]  ?? date('l');
$bulanIni   = $bulan_map[date('F')] ?? date('F');
$tanggalLengkap = $hariIni . ', ' . date('d') . ' ' . $bulanIni . ' ' . date('Y');

// Ambil identitas rumah sakit
try {
    $stmt = $pdo_simrs->query("SELECT nama_instansi, alamat_instansi, kabupaten, propinsi, kontak, email FROM setting LIMIT 1");
    $setting = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $setting = [
        'nama_instansi'   => 'RS Permata Hati',
        'alamat_instansi' => 'Jl. Kesehatan No. 123',
        'kabupaten'       => 'Kota Sehat',
        'propinsi'        => 'Provinsi',
        'kontak'          => '(021) 1234567',
        'email'           => 'info@rspermatahati.com'
    ];
}

// ===== PENCARIAN =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cari'])) {
    $keyword = trim($_POST['keyword'] ?? '');
    if (empty($keyword)) {
        $errorMsg = "Mohon masukkan No. RM, Nama Pasien, atau No. Rawat.";
    } else {
        try {
            $stmt = $pdo_simrs->prepare("
                SELECT ro.no_resep, ro.no_rawat, ro.tgl_peresepan, ro.jam_peresepan,
                       r.no_rkm_medis, p.nm_pasien, pl.nm_poli,
                       CASE
                           WHEN EXISTS (SELECT 1 FROM resep_dokter_racikan rr WHERE rr.no_resep = ro.no_resep)
                           THEN 'Racikan'
                           ELSE 'Non Racikan'
                       END AS jenis_resep
                FROM resep_obat ro
                LEFT JOIN reg_periksa r  ON ro.no_rawat   = r.no_rawat
                LEFT JOIN pasien p       ON r.no_rkm_medis = p.no_rkm_medis
                LEFT JOIN poliklinik pl  ON r.kd_poli      = pl.kd_poli
                WHERE ro.tgl_peresepan = CURDATE()
                  AND ro.status = 'ralan'
                  AND (r.no_rkm_medis LIKE ? OR p.nm_pasien LIKE ? OR ro.no_rawat LIKE ?)
                ORDER BY ro.tgl_peresepan DESC, ro.jam_peresepan DESC
                LIMIT 10
            ");
            $pat = "%$keyword%";
            $stmt->execute([$pat, $pat, $pat]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($results) > 0) {
                $searchResult = $results;
                $showSearch   = false;
            } else {
                $errorMsg = "Data tidak ditemukan. Pastikan Anda sudah memiliki resep hari ini.";
            }
        } catch (PDOException $e) {
            $errorMsg = "Terjadi kesalahan: " . $e->getMessage();
        }
    }
}

// ===== CETAK =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cetak'])) {
    $no_rawat = trim($_POST['no_rawat'] ?? '');
    if (!empty($no_rawat)) {
        try {
            $stmt = $pdo_simrs->prepare("
                SELECT ro.no_resep, ro.tgl_peresepan, ro.jam_peresepan,
                       r.no_rkm_medis, p.nm_pasien, pl.nm_poli,
                       CASE
                           WHEN EXISTS (SELECT 1 FROM resep_dokter_racikan rr WHERE rr.no_resep = ro.no_resep)
                           THEN 'Racikan'
                           ELSE 'Non Racikan'
                       END AS jenis_resep
                FROM resep_obat ro
                LEFT JOIN reg_periksa r  ON ro.no_rawat   = r.no_rawat
                LEFT JOIN pasien p       ON r.no_rkm_medis = p.no_rkm_medis
                LEFT JOIN poliklinik pl  ON r.kd_poli      = pl.kd_poli
                WHERE ro.no_rawat = ?
                ORDER BY ro.tgl_peresepan DESC, ro.jam_peresepan DESC
                LIMIT 1
            ");
            $stmt->execute([$no_rawat]);
            $resep = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($resep) {
                $resep['no_antrian_farmasi'] = 'F' . str_pad(substr($resep['no_resep'], -4), 4, '0', STR_PAD_LEFT);
                $printData  = $resep;
                $showSearch = false;
            } else {
                $errorMsg = "Data resep tidak ditemukan.";
            }
        } catch (PDOException $e) {
            $errorMsg = "Terjadi kesalahan: " . $e->getMessage();
        }
    }
}

// State untuk JS
$jsState = 'welcome'; // welcome | found | notFound | print
if ($searchResult)          $jsState = 'found';
elseif (!empty($errorMsg))  $jsState = 'notFound';
elseif ($printData)         $jsState = 'print';
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Antrian Farmasi – <?= htmlspecialchars($setting['nama_instansi']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&family=Inter:wght@600;700;800;900&display=swap" rel="stylesheet">
<style>
/* ========== RESET & BASE ========== */
*{margin:0;padding:0;box-sizing:border-box}
html,body{height:100vh;overflow:hidden;font-family:'Poppins',sans-serif}
body{background:linear-gradient(135deg,#00a65a 0%,#008d4c 50%,#00c073 100%);position:relative}

/* ========== ANIMATED BG ========== */
.bg-animated{position:fixed;top:0;left:0;width:100%;height:100%;overflow:hidden;z-index:0}
.particle{position:absolute;background:rgba(255,255,255,.1);border-radius:50%;animation:floatP 20s infinite ease-in-out}
.particle:nth-child(1){width:120px;height:120px;top:20%;left:15%;animation-delay:0s}
.particle:nth-child(2){width:80px;height:80px;top:60%;left:75%;animation-delay:4s}
.particle:nth-child(3){width:100px;height:100px;top:75%;left:25%;animation-delay:8s}
.particle:nth-child(4){width:60px;height:60px;top:35%;left:80%;animation-delay:12s}
@keyframes floatP{0%,100%{transform:translate(0,0) scale(1);opacity:.3}33%{transform:translate(40px,-60px) scale(1.2);opacity:.5}66%{transform:translate(-30px,40px) scale(.8);opacity:.4}}

/* ========== LAYOUT ========== */
.main-wrapper{position:relative;z-index:1;height:100vh;display:flex;flex-direction:column}

/* ========== HEADER ========== */
.header-bar{background:rgba(255,255,255,.98);backdrop-filter:blur(20px);padding:1.2vh 3vw;box-shadow:0 4px 30px rgba(0,0,0,.1);border-bottom:3px solid transparent;border-image:linear-gradient(90deg,#00a65a,#008d4c,#00c073);border-image-slice:1}
.header-content{display:flex;align-items:center;justify-content:space-between;max-width:1600px;margin:0 auto;gap:1.5vw;flex-wrap:wrap}
.logo-section{display:flex;align-items:center;gap:1.5vw}
.logo-icon{width:clamp(50px,5vw,70px);height:clamp(50px,5vw,70px);background:linear-gradient(135deg,#00a65a,#008d4c);border-radius:16px;display:flex;align-items:center;justify-content:center;box-shadow:0 8px 24px rgba(0,166,90,.4);animation:pulseLogo 3s ease-in-out infinite;flex-shrink:0}
@keyframes pulseLogo{0%,100%{transform:scale(1)}50%{transform:scale(1.05)}}
.logo-icon i{font-size:clamp(22px,2.2vw,32px);color:#fff}
.hospital-info h1{font-size:clamp(16px,1.8vw,26px);font-weight:900;background:linear-gradient(135deg,#00a65a,#008d4c);-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin:0;line-height:1.2}
.hospital-info p{font-size:clamp(10px,.9vw,13px);color:#64748b;margin:.3vh 0 0;font-weight:600}
.status-badge{display:flex;align-items:center;gap:.8vw;background:linear-gradient(135deg,#10b981,#059669);padding:.8vh 1.5vw;border-radius:50px;box-shadow:0 4px 16px rgba(16,185,129,.3);flex-shrink:0}
.status-badge i{color:#fff;font-size:clamp(14px,1.4vw,20px);animation:pulseDot 2s infinite}
@keyframes pulseDot{0%,100%{opacity:1}50%{opacity:.5}}
.status-badge span{color:#fff;font-weight:700;font-size:clamp(11px,1.1vw,15px)}

/* ========== CONTENT ========== */
.content-area{flex:1;display:flex;align-items:center;justify-content:center;padding:2vh 3vw;overflow-y:auto}
.content-grid{display:grid;grid-template-columns:1fr 1fr;gap:3vw;max-width:1600px;width:100%;align-items:center}

/* ========== LEFT SECTION ========== */
.left-section{display:flex;flex-direction:column;gap:2vh;height:100%;justify-content:center}
.welcome-card{background:rgba(255,255,255,.95);backdrop-filter:blur(20px);border-radius:24px;padding:3vh 2.5vw;box-shadow:0 20px 60px rgba(0,0,0,.15);border:2px solid rgba(255,255,255,.5);position:relative;overflow:hidden}
.welcome-card::before{content:'';position:absolute;top:-50%;right:-20%;width:300px;height:300px;background:radial-gradient(circle,rgba(0,166,90,.1) 0%,transparent 70%);border-radius:50%}
.welcome-card h2{font-size:clamp(22px,2.8vw,40px);font-weight:900;background:linear-gradient(135deg,#00a65a,#008d4c);-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin:0 0 1.5vh;position:relative;z-index:1}
.welcome-card p{color:#475569;font-size:clamp(12px,1.2vw,17px);font-weight:600;line-height:1.6;margin:0;position:relative;z-index:1}
.datetime-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.5vw}
.datetime-card{background:rgba(255,255,255,.95);backdrop-filter:blur(20px);border-radius:20px;padding:2.5vh 1.5vw;box-shadow:0 8px 32px rgba(0,0,0,.1);border:2px solid rgba(255,255,255,.5);transition:all .3s;position:relative;overflow:hidden}
.datetime-card::before{content:'';position:absolute;top:0;left:0;width:5px;height:100%;background:linear-gradient(180deg,#00a65a,#008d4c);transition:width .3s}
.datetime-card:hover{transform:translateY(-5px);box-shadow:0 12px 40px rgba(0,0,0,.15)}
.dt-icon{width:clamp(38px,4vw,54px);height:clamp(38px,4vw,54px);background:linear-gradient(135deg,#d1fae5,#a7f3d0);border-radius:14px;display:flex;align-items:center;justify-content:center;margin-bottom:1.5vh}
.dt-icon i{font-size:clamp(18px,2vw,26px);background:linear-gradient(135deg,#00a65a,#008d4c);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.dt-label{font-size:clamp(9px,.9vw,12px);color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:1px;margin-bottom:.8vh}
.dt-value{font-size:clamp(14px,1.6vw,22px);font-weight:800;color:#1e293b;font-family:'Inter',sans-serif}
.info-card{background:linear-gradient(135deg,rgba(251,191,36,.2),rgba(245,158,11,.2));backdrop-filter:blur(20px);border-radius:20px;padding:2vh 2vw;border:2px solid rgba(251,191,36,.4);display:flex;align-items:center;gap:1.5vw;box-shadow:0 8px 32px rgba(251,191,36,.2)}
.info-card i{font-size:clamp(22px,2.4vw,34px);color:#d97706;flex-shrink:0}
.info-card p{color:#92400e;font-weight:700;font-size:clamp(11px,1.1vw,15px);margin:0;line-height:1.5}

/* ========== RIGHT SECTION ========== */
.right-section{display:flex;flex-direction:column;gap:2.5vh;height:100%;justify-content:center;align-items:center}

/* Ticket Showcase */
.ticket-circle{width:clamp(130px,14vw,190px);height:clamp(130px,14vw,190px);background:linear-gradient(135deg,#00a65a 0%,#008d4c 50%,#00c073 100%);border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 30px 80px rgba(0,166,90,.5);animation:floatTicket 4s ease-in-out infinite;position:relative;margin-bottom:.5vh}
@keyframes floatTicket{0%,100%{transform:translateY(0) rotate(0deg)}50%{transform:translateY(-15px) rotate(5deg)}}
.ticket-circle::before,.ticket-circle::after{content:'';position:absolute;border-radius:50%;border:3px solid rgba(0,166,90,.3);animation:rippleOut 3s ease-out infinite}
.ticket-circle::before{width:120%;height:120%}
.ticket-circle::after{width:140%;height:140%;animation-delay:1.5s}
@keyframes rippleOut{0%{transform:scale(1);opacity:.6}100%{transform:scale(1.3);opacity:0}}
.ticket-circle i{font-size:clamp(55px,6.5vw,90px);color:#fff;position:relative;z-index:1;filter:drop-shadow(0 4px 8px rgba(0,0,0,.2))}

/* Search Card */
.search-card{background:rgba(255,255,255,.95);backdrop-filter:blur(20px);border-radius:20px;padding:2.5vh 2vw;box-shadow:0 12px 40px rgba(0,0,0,.15);border:2px solid rgba(255,255,255,.5);width:100%;max-width:520px}
.search-card h3{font-size:clamp(16px,1.8vw,22px);font-weight:800;color:#1e293b;margin:0 0 2vh;text-align:center;display:flex;align-items:center;justify-content:center;gap:.8vw}
.search-card h3 i{color:#00a65a}
.form-lbl{display:block;font-size:clamp(11px,1.1vw,13px);font-weight:700;color:#475569;margin-bottom:.8vh;text-transform:uppercase;letter-spacing:.5px}
.form-inp{width:100%;height:clamp(44px,6vh,54px);border:2px solid #e2e8f0;border-radius:12px;padding:0 1.5vw;font-size:clamp(13px,1.3vw,17px);font-weight:600;transition:all .3s;background:#fff;font-family:'Poppins',sans-serif}
.form-inp:focus{outline:none;border-color:#00a65a;box-shadow:0 0 0 4px rgba(0,166,90,.1)}

/* Buttons */
.btn-wrap{display:flex;flex-direction:column;gap:1.2vh;width:100%}
.btn-farm{height:clamp(48px,5.5vh,62px);border:none;border-radius:16px;font-weight:800;font-size:clamp(13px,1.4vw,17px);display:flex;align-items:center;justify-content:center;gap:1vw;transition:all .4s cubic-bezier(.175,.885,.32,1.275);box-shadow:0 10px 30px rgba(0,0,0,.2);cursor:pointer;text-decoration:none;position:relative;overflow:hidden;padding:0 1.5vw}
.btn-farm::before{content:'';position:absolute;top:50%;left:50%;width:0;height:0;border-radius:50%;background:rgba(255,255,255,.3);transform:translate(-50%,-50%);transition:width .6s,height .6s}
.btn-farm:hover::before{width:600px;height:600px}
.btn-farm i,.btn-farm span{position:relative;z-index:1}
.btn-farm i{font-size:clamp(18px,2vw,24px)}
.btn-green{background:linear-gradient(135deg,#00a65a 0%,#008d4c 100%);color:#fff}
.btn-green:hover{transform:translateY(-5px) scale(1.02);box-shadow:0 15px 50px rgba(0,166,90,.5);color:#fff}
.btn-amber{background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff}
.btn-amber:hover{transform:translateY(-5px) scale(1.02);box-shadow:0 15px 50px rgba(245,158,11,.4);color:#fff}
.btn-slate{background:linear-gradient(135deg,#64748b,#475569);color:#fff}
.btn-slate:hover{transform:translateY(-5px) scale(1.02);box-shadow:0 15px 50px rgba(100,116,139,.4);color:#fff}

/* Alert */
.alert-box{background:linear-gradient(135deg,#fee2e2,#fecaca);border:2px solid #fca5a5;border-radius:16px;padding:1.8vh 2vw;display:flex;align-items:center;gap:1.2vw;box-shadow:0 8px 24px rgba(220,38,38,.2);animation:shake .5s ease;width:100%;max-width:520px}
@keyframes shake{0%,100%{transform:translateX(0)}25%{transform:translateX(-10px)}75%{transform:translateX(10px)}}
.alert-box i{font-size:clamp(20px,2vw,28px);color:#991b1b;flex-shrink:0}
.alert-box div{color:#991b1b;font-weight:700;font-size:clamp(12px,1.2vw,15px)}

/* Results */
.results-list{background:rgba(255,255,255,.95);backdrop-filter:blur(20px);border-radius:20px;padding:2vh 1.5vw;box-shadow:0 12px 40px rgba(0,0,0,.15);border:2px solid rgba(255,255,255,.5);width:100%;max-width:560px;max-height:58vh;overflow-y:auto}
.results-list::-webkit-scrollbar{width:6px}
.results-list::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:10px}
.results-list h3{font-size:clamp(15px,1.6vw,20px);font-weight:800;color:#1e293b;margin:0 0 1.5vh;text-align:center}
.result-item-btn{width:100%;text-align:left;background:#fff;border:2px solid #e2e8f0;border-radius:12px;padding:1.5vh 1.5vw;margin-bottom:1.2vh;cursor:pointer;transition:all .3s;display:block}
.result-item-btn:hover{border-color:#00a65a;box-shadow:0 4px 12px rgba(0,166,90,.2);transform:translateY(-2px)}
.ri-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:.8vh}
.ri-name{font-size:clamp(13px,1.4vw,17px);font-weight:800;color:#1e293b}
.ri-badge{display:inline-block;padding:.3vh .8vw;border-radius:8px;font-size:clamp(9px,.9vw,11px);font-weight:700;color:#fff}
.badge-racikan{background:#dd4b39}
.badge-non{background:#00a65a}
.ri-info{display:grid;grid-template-columns:auto 1fr;gap:.4vh .8vw;font-size:clamp(10px,1vw,12px);color:#64748b}
.ri-lbl{font-weight:700}

/* ========== VIRTUAL KEYBOARD ========== */
.vkbd{position:fixed;bottom:0;left:0;right:0;background:linear-gradient(135deg,#1e293b,#334155);padding:14px 20px 20px;box-shadow:0 -10px 40px rgba(0,0,0,.5);z-index:9999;display:none;animation:slideUp .3s ease-out}
@keyframes slideUp{from{transform:translateY(100%)}to{transform:translateY(0)}}

/* Preview bar — menampilkan apa yang diketik di atas keyboard */
.vkbd-preview{
  background:rgba(255,255,255,.08);
  border:2px solid rgba(255,255,255,.18);
  border-radius:12px;
  padding:10px 18px;
  margin-bottom:12px;
  display:flex;
  align-items:center;
  gap:12px;
  min-height:48px;
}
.vkbd-preview-label{
  font-size:11px;
  font-weight:700;
  color:#94a3b8;
  text-transform:uppercase;
  letter-spacing:.5px;
  white-space:nowrap;
  flex-shrink:0;
}
.vkbd-preview-text{
  font-size:clamp(15px,1.8vw,20px);
  font-weight:700;
  color:#f1f5f9;
  font-family:'Inter',sans-serif;
  letter-spacing:.5px;
  flex:1;
  min-height:28px;
  word-break:break-all;
}
.vkbd-preview-cursor{
  display:inline-block;
  width:2px;
  height:1.2em;
  background:#10b981;
  margin-left:2px;
  vertical-align:middle;
  animation:blink .8s step-end infinite;
}
@keyframes blink{0%,100%{opacity:1}50%{opacity:0}}

.vkbd-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;padding-bottom:10px;border-bottom:2px solid rgba(255,255,255,.15)}
.vkbd-title{font-size:clamp(13px,1.4vw,17px);font-weight:700;color:#f1f5f9;display:flex;align-items:center;gap:10px}
.vkbd-title i{color:#10b981}
.vkbd-close{width:36px;height:36px;border-radius:8px;background:#ef4444;color:#fff;border:0;font-size:18px;cursor:pointer;font-weight:700;transition:all .2s;display:flex;align-items:center;justify-content:center}
.vkbd-close:hover{background:#dc2626;transform:scale(1.08)}

/* Saat keyboard terbuka, content-area dapat scroll dan tidak tertutup */
body.kbd-open{
  overflow: auto;
}
body.kbd-open .content-area{
  overflow-y:auto;
}
body.kbd-open .main-wrapper{
  padding-bottom:var(--kbd-h, 320px);
  height:auto;
  min-height:100vh;
}
.key-row{display:flex;justify-content:center;gap:7px;margin-bottom:7px}
.key{min-width:clamp(40px,5vw,56px);height:clamp(42px,5.5vh,52px);background:#fff;color:#1e293b;border:2px solid #e5e7eb;border-radius:10px;font-size:clamp(14px,1.5vw,17px);font-weight:700;cursor:pointer;transition:all .2s;box-shadow:0 2px 4px rgba(0,0,0,.1);font-family:'Poppins',sans-serif}
.key:hover{background:#f0fdf4;border-color:#00a65a;transform:translateY(-2px);box-shadow:0 4px 8px rgba(0,166,90,.2)}
.key:active{transform:translateY(0)}
.key.k-special{background:linear-gradient(135deg,#00a65a,#008d4c);color:#fff;border-color:#00a65a;min-width:clamp(80px,10vw,120px);font-size:clamp(12px,1.2vw,14px)}
.key.k-special:hover{background:linear-gradient(135deg,#008d4c,#00703c)}
.key.k-del{background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;border-color:#ef4444;min-width:clamp(80px,10vw,110px)}
.key.k-del:hover{background:linear-gradient(135deg,#dc2626,#b91c1c)}
.key.k-clear{background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border-color:#f59e0b;min-width:clamp(70px,8vw,100px)}
.key.k-clear:hover{background:linear-gradient(135deg,#d97706,#b45309)}

/* ========== FOOTER ========== */
.footer-bar{background:rgba(255,255,255,.95);backdrop-filter:blur(20px);padding:1.2vh 3vw;border-top:2px solid rgba(0,166,90,.2);box-shadow:0 -4px 30px rgba(0,0,0,.1)}
.footer-content{display:flex;align-items:center;justify-content:space-between;max-width:1600px;margin:0 auto;flex-wrap:wrap;gap:1vh 2vw}
.footer-item{display:flex;align-items:center;gap:.6vw;color:#64748b;font-size:clamp(9px,.9vw,12px);font-weight:600}
.footer-item i{font-size:clamp(12px,1.1vw,16px);color:#00a65a}
.footer-item .hl{color:#1e293b;font-weight:800}

/* ========== PRINT ========== */
@media print{
  @page{size:7.5cm 11cm;margin:0}
  body>*:not(.print-area){display:none!important}
  .print-area{display:block!important;position:static!important;width:7.5cm!important;height:11cm!important;padding:0!important;margin:0!important;background:#fff!important;overflow:hidden!important}
  .print-area *{visibility:visible!important}
}
.print-area{display:none}

/* ========== RESPONSIVE ========== */
@media(max-width:1024px){
  .content-grid{grid-template-columns:1fr;gap:3vh}
  .datetime-grid{grid-template-columns:1fr}
  .header-content{flex-direction:column}
  .footer-content{flex-direction:column;text-align:center}
}
@media(max-width:768px){
  .logo-section{flex-direction:column;text-align:center}
  .status-badge{width:100%;justify-content:center}
}
</style>
</head>
<body>

<!-- Animated Background -->
<div class="bg-animated">
  <div class="particle"></div>
  <div class="particle"></div>
  <div class="particle"></div>
  <div class="particle"></div>
</div>

<div class="main-wrapper">

  <!-- ===== HEADER ===== -->
  <div class="header-bar">
    <div class="header-content">
      <div class="logo-section">
        <div class="logo-icon">
          <i class="bi bi-capsule-pill"></i>
        </div>
        <div class="hospital-info">
          <h1><?= htmlspecialchars($setting['nama_instansi']) ?></h1>
          <p><?= htmlspecialchars($setting['alamat_instansi']) ?> &bull; <?= htmlspecialchars($setting['kabupaten']) ?>, <?= htmlspecialchars($setting['propinsi']) ?></p>
        </div>
      </div>
      <div class="status-badge">
        <i class="bi bi-circle-fill"></i>
        <span>Sistem Online</span>
      </div>
    </div>
  </div>

  <!-- ===== CONTENT ===== -->
  <div class="content-area">
    <div class="content-grid">

      <!-- LEFT -->
      <div class="left-section">
        <div class="welcome-card">
          <h2>💊 Antrian Farmasi</h2>
          <p>Silakan masukkan <strong>No. RM</strong>, <strong>Nama Pasien</strong>, atau <strong>No. Rawat</strong> untuk mengambil nomor antrian farmasi Anda hari ini.</p>
        </div>

        <div class="datetime-grid">
          <div class="datetime-card">
            <div class="dt-icon"><i class="bi bi-calendar-event-fill"></i></div>
            <div class="dt-label">Tanggal Hari Ini</div>
            <div class="dt-value" id="elTanggal">–</div>
          </div>
          <div class="datetime-card">
            <div class="dt-icon"><i class="bi bi-clock-history"></i></div>
            <div class="dt-label">Waktu Sekarang</div>
            <div class="dt-value" id="elWaktu">–</div>
          </div>
        </div>

        <div class="info-card">
          <i class="bi bi-info-circle-fill"></i>
          <p><strong>Petunjuk:</strong> Gunakan keyboard virtual atau ketik langsung, kemudian klik <em>Cari Data</em>. Pilih nama Anda dari hasil pencarian untuk mencetak karcis antrian.</p>
        </div>
      </div>

      <!-- RIGHT -->
      <div class="right-section">

        <?php if (!$printData): ?>

          <!-- Ikon Kapsul -->
          <div class="ticket-circle" style="margin-bottom:.5vh">
            <i class="bi bi-capsule"></i>
          </div>

          <!-- Alert Error -->
          <?php if (!empty($errorMsg)): ?>
          <div class="alert-box">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <div><?= htmlspecialchars($errorMsg) ?></div>
          </div>
          <?php endif; ?>

          <!-- Form Pencarian -->
          <?php if ($showSearch): ?>
          <form method="post" class="search-card" id="formCari">
            <h3><i class="bi bi-search"></i> Cari Data Pasien</h3>
            <div style="margin-bottom:1.5vh">
              <label class="form-lbl">No. RM / Nama Pasien / No. Rawat</label>
              <input type="text" name="keyword" id="inputCari" class="form-inp"
                     placeholder="Contoh: 000057 atau M Wira Satria Buana"
                     value="<?= htmlspecialchars($_POST['keyword'] ?? '') ?>"
                     autocomplete="off" autofocus>
            </div>
            <div class="btn-wrap">
              <button type="submit" name="cari" class="btn-farm btn-green">
                <i class="bi bi-search"></i><span>Cari Data</span>
              </button>
              <button type="button" class="btn-farm btn-amber" onclick="toggleKeyboard()">
                <i class="bi bi-keyboard-fill"></i><span>Keyboard Virtual</span>
              </button>
              <a href="anjungan.php" class="btn-farm btn-slate">
                <i class="bi bi-arrow-left-circle-fill"></i><span>Kembali ke Menu</span>
              </a>
            </div>
          </form>
          <?php endif; ?>

          <!-- Hasil Pencarian -->
          <?php if ($searchResult): ?>
          <div class="results-list">
            <h3>📋 Hasil Pencarian (<?= count($searchResult) ?> data)</h3>
            <?php foreach ($searchResult as $item): ?>
            <form method="post" style="margin:0">
              <input type="hidden" name="no_rawat" value="<?= htmlspecialchars($item['no_rawat']) ?>">
              <button type="submit" name="cetak" class="result-item-btn">
                <div class="ri-head">
                  <div class="ri-name"><?= htmlspecialchars($item['nm_pasien']) ?></div>
                  <div class="ri-badge <?= $item['jenis_resep'] === 'Racikan' ? 'badge-racikan' : 'badge-non' ?>">
                    <?= htmlspecialchars($item['jenis_resep']) ?>
                  </div>
                </div>
                <div class="ri-info">
                  <div class="ri-lbl">No. RM:</div><div><?= htmlspecialchars($item['no_rkm_medis']) ?></div>
                  <div class="ri-lbl">No. Resep:</div><div><?= htmlspecialchars($item['no_resep']) ?></div>
                  <div class="ri-lbl">Poli:</div><div><?= htmlspecialchars($item['nm_poli']) ?></div>
                  <div class="ri-lbl">Jam:</div><div><?= date('H:i', strtotime($item['jam_peresepan'])) ?> WIB</div>
                </div>
              </button>
            </form>
            <?php endforeach; ?>
            <div class="btn-wrap" style="margin-top:1.5vh">
              <a href="antrian_farmasi.php" class="btn-farm btn-slate">
                <i class="bi bi-arrow-counterclockwise"></i><span>Cari Lagi</span>
              </a>
              <a href="anjungan.php" class="btn-farm btn-slate" style="opacity:.8">
                <i class="bi bi-arrow-left-circle-fill"></i><span>Kembali ke Menu</span>
              </a>
            </div>
          </div>
          <?php endif; ?>

        <?php endif; // end !printData ?>

      </div><!-- end right -->

    </div><!-- end content-grid -->
  </div><!-- end content-area -->

  <!-- ===== FOOTER ===== -->
  <div class="footer-bar">
    <div class="footer-content">
      <div class="footer-item"><i class="bi bi-telephone-fill"></i><span class="hl"><?= htmlspecialchars($setting['kontak']) ?></span></div>
      <div class="footer-item"><i class="bi bi-envelope-fill"></i><span><?= htmlspecialchars($setting['email']) ?></span></div>
      <div class="footer-item"><i class="bi bi-c-circle"></i><span><?= date('Y') ?> <span class="hl"><?= htmlspecialchars($setting['nama_instansi']) ?></span></span></div>
      <div class="footer-item"><i class="bi bi-code-slash"></i><span>Powered by <span class="hl">MediFix</span></span></div>
    </div>
  </div>

</div><!-- end main-wrapper -->

<!-- ===== VIRTUAL KEYBOARD ===== -->
<div id="vKbd" class="vkbd">
  <div class="vkbd-head">
    <div class="vkbd-title"><i class="bi bi-keyboard-fill"></i> KEYBOARD VIRTUAL</div>
    <button class="vkbd-close" onclick="closeKeyboard()" title="Tutup"><i class="bi bi-x-lg"></i></button>
  </div>
  <!-- Preview: menampilkan apa yang diketik agar tidak tertutup keyboard -->
  <div class="vkbd-preview">
    <span class="vkbd-preview-label">Input:</span>
    <span class="vkbd-preview-text" id="kbdPreview"><span class="vkbd-preview-cursor"></span></span>
  </div>
  <div id="kbdRow1" class="key-row"></div>
  <div id="kbdRow2" class="key-row"></div>
  <div id="kbdRow3" class="key-row"></div>
  <div id="kbdRow4" class="key-row"></div>
  <div id="kbdRow5" class="key-row"></div>
</div>

<!-- ===== PRINT AREA ===== -->
<?php if ($printData): ?>
<div class="print-area" id="printArea">
  <div style="width:7.5cm;height:11cm;padding:.4cm .3cm;font-family:'Courier New',monospace;background:#fff;display:flex;flex-direction:column;justify-content:space-between">

    <!-- Header -->
    <div style="text-align:center;margin-bottom:.2cm">
      <h2 style="font-size:16px;font-weight:900;margin:0 0 .15cm;color:#000;text-transform:uppercase;letter-spacing:.3px;line-height:1.2">
        <?= htmlspecialchars($setting['nama_instansi']) ?>
      </h2>
      <p style="font-size:8.5px;margin:.1cm 0;color:#333;line-height:1.3"><?= htmlspecialchars($setting['alamat_instansi']) ?></p>
      <p style="font-size:8.5px;margin:.05cm 0;color:#333;line-height:1.2"><?= htmlspecialchars($setting['kabupaten']) ?>, <?= htmlspecialchars($setting['propinsi']) ?></p>
      <p style="font-size:8px;margin:.05cm 0;color:#333;line-height:1.2">Telp: <?= htmlspecialchars($setting['kontak']) ?></p>
    </div>

    <div style="border-top:1.5px dashed #333;margin:.15cm 0"></div>

    <!-- Nomor Antrian -->
    <div style="text-align:center;margin:.25cm 0">
      <p style="font-size:11px;font-weight:700;margin:0 0 .2cm;color:#000;text-transform:uppercase;letter-spacing:.5px">Nomor Antrian Farmasi</p>
      <div style="background:linear-gradient(135deg,#00a65a,#008d4c);padding:.4cm .3cm;border-radius:8px;margin:.1cm 0;box-shadow:0 2px 6px rgba(0,0,0,.15)">
        <h1 style="font-size:56px;margin:0;font-weight:900;color:#fff;letter-spacing:4px;text-shadow:0 3px 8px rgba(0,0,0,.3)">
          <?= htmlspecialchars($printData['no_antrian_farmasi']) ?>
        </h1>
      </div>
      <span style="display:inline-block;padding:.15cm .4cm;margin:.15cm 0;border-radius:20px;font-size:10px;font-weight:800;<?= $printData['jenis_resep']==='Racikan'?'background:#dd4b39':'background:#00a65a' ?>;color:#fff">
        <?= htmlspecialchars($printData['jenis_resep']) ?>
      </span>
    </div>

    <div style="border-top:1.5px dashed #333;margin:.15cm 0"></div>

    <!-- Detail -->
    <div style="margin:.15cm 0;text-align:left;padding:0 .1cm">
      <p style="font-size:9.5px;margin:.1cm 0;color:#333;line-height:1.3"><strong>No. RM:</strong> <?= htmlspecialchars($printData['no_rkm_medis']) ?></p>
      <p style="font-size:9.5px;margin:.1cm 0;color:#333;line-height:1.3"><strong>Nama:</strong> <?= htmlspecialchars($printData['nm_pasien']) ?></p>
      <p style="font-size:9.5px;margin:.1cm 0;color:#333;line-height:1.3"><strong>No. Resep:</strong> <?= htmlspecialchars($printData['no_resep']) ?></p>
      <p style="font-size:9.5px;margin:.1cm 0;color:#333;line-height:1.3"><strong>Dari Poli:</strong> <?= htmlspecialchars($printData['nm_poli']) ?></p>
    </div>

    <div style="border-top:1.5px dashed #333;margin:.15cm 0"></div>

    <!-- Instruksi -->
    <div style="margin:.1cm 0;text-align:center">
      <p style="font-size:8.5px;margin:.12cm 0;color:#333;line-height:1.4"><strong>Terima kasih</strong>, silakan tunggu panggilan</p>
      <p style="font-size:8.5px;margin:.08cm 0;color:#333;line-height:1.4">di layar display antrian farmasi</p>
      <?php if ($printData['jenis_resep'] === 'Racikan'): ?>
      <p style="font-size:8px;margin:.12cm 0;color:#dd4b39;line-height:1.3;font-weight:700">⚠ Resep racikan ± 15–60 menit</p>
      <?php endif; ?>
    </div>

    <div style="border-top:1px dashed #ccc;margin:.1cm 0"></div>

    <!-- Footer Karcis -->
    <div style="text-align:center">
      <p style="font-size:7.5px;margin:.07cm 0;color:#666;line-height:1.2">Dicetak: <?= date('d/m/Y H:i:s') ?></p>
      <p style="font-size:7.5px;margin:.07cm 0;color:#666;line-height:1.2">Sistem MediFix | 082177846209</p>
      <p style="font-size:9px;margin:.1cm 0 0;font-weight:700;color:#000;letter-spacing:.3px">SEMOGA LEKAS SEMBUH</p>
    </div>

  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ===================================================
//  VOICE ENGINE
// ===================================================
const jsState = "<?= $jsState ?>";
<?php if ($printData): ?>
const printNomor    = "<?= htmlspecialchars($printData['no_antrian_farmasi']) ?>";
const printNama     = "<?= htmlspecialchars(addslashes($printData['nm_pasien'])) ?>";
const printJenis    = "<?= htmlspecialchars($printData['jenis_resep']) ?>";
<?php else: ?>
const printNomor = "";
const printNama  = "";
const printJenis = "";
<?php endif; ?>
<?php if ($searchResult): ?>
const foundCount = <?= count($searchResult) ?>;
<?php else: ?>
const foundCount = 0;
<?php endif; ?>

let voiceReady = false;

function speak(text, delay = 0) {
  if (!('speechSynthesis' in window)) return;
  const doSpeak = () => {
    window.speechSynthesis.cancel();
    const utt = new SpeechSynthesisUtterance(text);
    utt.lang    = 'id-ID';
    utt.rate    = 0.9;
    utt.pitch   = 1.0;
    utt.volume  = 1.0;
    const voices = window.speechSynthesis.getVoices();
    const idVoice = voices.find(v => v.lang.includes('id'));
    if (idVoice) utt.voice = idVoice;
    window.speechSynthesis.speak(utt);
  };
  if (delay > 0) setTimeout(doSpeak, delay);
  else doSpeak();
}

// Pre-load voices
if ('speechSynthesis' in window) {
  window.speechSynthesis.onvoiceschanged = () => {
    window.speechSynthesis.getVoices();
    voiceReady = true;
  };
  window.speechSynthesis.getVoices();
}

// Voice on page load
window.addEventListener('load', () => {
  // Cek flag: jika baru redirect dari halaman cetak, skip suara
  const skipVoice = sessionStorage.getItem('skipVoiceAfterPrint') === '1';
  if (skipVoice) {
    sessionStorage.removeItem('skipVoiceAfterPrint');
    const inp = document.getElementById('inputCari');
    if (inp) inp.focus();
    return;
  }

  setTimeout(() => {
    if (jsState === 'welcome') {
      speak("Selamat datang di layanan antrian farmasi. Silakan ketik Nomor Rekam Medis, Nama Pasien, atau Nomor Rawat Anda, kemudian klik tombol Cari Data. Anda juga dapat menggunakan tombol Keyboard Virtual.");
    } else if (jsState === 'found') {
      speak(`Data ditemukan. Terdapat ${foundCount} data yang sesuai. Silakan klik nama Anda untuk mencetak karcis antrian farmasi.`);
    } else if (jsState === 'notFound') {
      speak("Maaf, data tidak ditemukan. Pastikan Anda sudah mendapatkan resep dari dokter hari ini. Silakan periksa kembali Nomor Rekam Medis atau nama Anda.");
    } else if (jsState === 'print') {
      const nomorSpell = printNomor.split('').join(' ');
      speak(`Nomor antrian farmasi Anda adalah ${nomorSpell}. Untuk pasien ${printNama} dengan resep ${printJenis}. Silakan tunggu panggilan di layar display. Karcis sedang dicetak. Semoga lekas sembuh.`);
    }
    const inp = document.getElementById('inputCari');
    if (inp) inp.focus();
  }, 600);
});

// ===================================================
//  VIRTUAL KEYBOARD
// ===================================================
const vKbd   = document.getElementById('vKbd');
const inputEl = document.getElementById('inputCari');

const kbdRows = [
  ['1','2','3','4','5','6','7','8','9','0'],
  ['Q','W','E','R','T','Y','U','I','O','P'],
  ['A','S','D','F','G','H','J','K','L'],
  ['Z','X','C','V','B','N','M'],
  ['SPASI','HAPUS','BERSIHKAN']
];

function buildKeyboard() {
  const rowIds = ['kbdRow1','kbdRow2','kbdRow3','kbdRow4','kbdRow5'];
  kbdRows.forEach((keys, i) => {
    const row = document.getElementById(rowIds[i]);
    if (!row) return;
    keys.forEach(k => {
      const btn = document.createElement('button');
      btn.type = 'button';
      if (k === 'SPASI')    { btn.className = 'key k-special'; btn.innerHTML = '<i class="bi bi-space"></i> SPASI';   btn.style.minWidth='clamp(120px,14vw,200px)'; }
      else if (k === 'HAPUS')    { btn.className = 'key k-del';     btn.innerHTML = '<i class="bi bi-backspace-fill"></i> HAPUS'; }
      else if (k === 'BERSIHKAN'){ btn.className = 'key k-clear';   btn.innerHTML = '<i class="bi bi-x-lg"></i> BERSIHKAN'; }
      else                       { btn.className = 'key'; btn.textContent = k; }
      btn.addEventListener('click', () => pressKey(k));
      row.appendChild(btn);
    });
  });
}

function pressKey(k) {
  if (!inputEl) return;
  if (k === 'SPASI')     inputEl.value += ' ';
  else if (k === 'HAPUS')     inputEl.value = inputEl.value.slice(0, -1);
  else if (k === 'BERSIHKAN') { inputEl.value = ''; speak("Kolom dibersihkan."); }
  else                        inputEl.value += k;
  inputEl.focus();
  updatePreview();
}

function updatePreview() {
  const preview = document.getElementById('kbdPreview');
  if (!preview || !inputEl) return;
  const val = inputEl.value || '';
  preview.innerHTML = val
    ? `<span style="color:#f1f5f9">${val}</span><span class="vkbd-preview-cursor"></span>`
    : `<span class="vkbd-preview-cursor"></span>`;
}

function toggleKeyboard() {
  if (vKbd.style.display === 'block') {
    closeKeyboard();
  } else {
    vKbd.style.display = 'block';
    // Ukur tinggi keyboard lalu beri padding agar konten tidak tertutup
    requestAnimationFrame(() => {
      const kbdH = vKbd.offsetHeight;
      document.documentElement.style.setProperty('--kbd-h', kbdH + 'px');
      document.body.classList.add('kbd-open');
      // Scroll input ke dalam tampilan
      if (inputEl) {
        inputEl.focus();
        setTimeout(() => {
          inputEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 350);
      }
      updatePreview();
    });
    speak("Keyboard virtual dibuka. Silakan ketik Nomor Rekam Medis atau nama pasien.");
  }
}

function closeKeyboard() {
  vKbd.style.display = 'none';
  document.body.classList.remove('kbd-open');
  document.documentElement.style.removeProperty('--kbd-h');
  speak("Keyboard ditutup.");
}

buildKeyboard();

// Sinkronisasi preview saat user mengetik langsung (keyboard fisik)
if (inputEl) {
  inputEl.addEventListener('input', updatePreview);
}

// ===================================================
//  DATE & TIME CLOCK
// ===================================================
function updateClock() {
  const now = new Date();
  const optsDate = { day:'2-digit', month:'short', year:'numeric' };
  const optsTime = { hour:'2-digit', minute:'2-digit', second:'2-digit', hour12:false };
  document.getElementById('elTanggal').textContent = now.toLocaleDateString('id-ID', optsDate);
  document.getElementById('elWaktu').textContent   = now.toLocaleTimeString('id-ID', optsTime);
}
setInterval(updateClock, 1000);
updateClock();

// ===================================================
//  AUTO PRINT (saat printData tersedia)
// ===================================================
<?php if ($printData): ?>
window.addEventListener('load', () => {
  setTimeout(() => {
    window.print();

    // Dengarkan event afterprint: dipanggil saat dialog print ditutup (cetak/batal)
    // Ini mencegah suara welcome berbunyi lagi setelah redirect
    const doRedirect = () => {
      sessionStorage.setItem('skipVoiceAfterPrint', '1');
      window.speechSynthesis.cancel(); // hentikan suara yang mungkin masih berjalan
      window.location.href = 'antrian_farmasi.php';
    };

    if ('onafterprint' in window) {
      window.onafterprint = doRedirect;
    } else {
      // Fallback untuk browser yang tidak support afterprint
      setTimeout(doRedirect, 2000);
    }
  }, 1800);
});
<?php endif; ?>
</script>
</body>
</html>