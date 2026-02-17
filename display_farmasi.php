<?php
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

$tgl = date('Y-m-d');

// Baca panggilan terbaru dari DB saat halaman pertama load
function getLatestCall($pdo, $tgl, $jenis) {
    $stmt = $pdo->prepare("
        SELECT no_antrian, no_rawat, nm_pasien, nm_poli, nm_dokter, jml_panggil,
               UNIX_TIMESTAMP(updated_at) AS ts
        FROM simpan_antrian_farmasi_wira
        WHERE tgl_panggil = ? AND jenis_resep = ?
        ORDER BY updated_at DESC LIMIT 1
    ");
    $stmt->execute([$tgl, $jenis]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

try {
    $nr = getLatestCall($pdo_simrs, $tgl, 'Non Racikan');
    $r  = getLatestCall($pdo_simrs, $tgl, 'Racikan');
} catch (PDOException $e) {
    $nr = $r = null;
}

function tglIndonesia($tgl) {
    $bulan = [1=>'Januari','Februari','Maret','April','Mei','Juni',
              'Juli','Agustus','September','Oktober','November','Desember'];
    $hari  = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
    return $hari[date('w', strtotime($tgl))] . ', ' .
           date('j', strtotime($tgl)) . ' ' .
           $bulan[(int)date('n', strtotime($tgl))] . ' ' .
           date('Y', strtotime($tgl));
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Display Antrian Farmasi - MediFix</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{--primary:#2563eb;--secondary:#f59e0b;--accent:#06b6d4;--dark:#0f172a;--gray:#64748b}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Poppins',sans-serif;background:linear-gradient(135deg,#0f172a 0%,#1e293b 50%,#334155 100%);min-height:100vh;overflow-x:hidden;position:relative}
body::before{content:'';position:fixed;top:0;left:0;width:100%;height:100%;background:radial-gradient(circle at 20% 30%,rgba(59,130,246,.1) 0%,transparent 50%),radial-gradient(circle at 80% 70%,rgba(245,158,11,.1) 0%,transparent 50%);pointer-events:none;z-index:0}
.container{position:relative;z-index:1;max-width:1600px;margin:0 auto;padding:0 2rem}

/* Header */
.header{background:rgba(255,255,255,.05);backdrop-filter:blur(20px);border-bottom:1px solid rgba(255,255,255,.1);padding:1.5rem 0;margin-bottom:2rem;animation:slideDown .6s ease-out}
@keyframes slideDown{from{transform:translateY(-100%);opacity:0}to{transform:translateY(0);opacity:1}}
.header-content{display:grid;grid-template-columns:auto 1fr auto;gap:2rem;align-items:center}
.brand{display:flex;align-items:center;gap:1rem}
.brand-icon{width:56px;height:56px;background:linear-gradient(135deg,var(--primary),var(--accent));border-radius:16px;display:flex;align-items:center;justify-content:center;box-shadow:0 8px 32px rgba(37,99,235,.3)}
.brand-icon i{font-size:28px;color:#fff}
.brand-text h1{font-size:24px;font-weight:700;color:#fff;margin:0;letter-spacing:-.5px}
.brand-text p{font-size:14px;color:var(--gray);margin:0;font-weight:500}
.header-center{text-align:center}
.date-display{font-size:14px;color:var(--gray);font-weight:500;margin-bottom:.5rem}
.clock-display{font-size:36px;font-weight:700;background:linear-gradient(135deg,var(--primary),var(--accent));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;font-variant-numeric:tabular-nums;letter-spacing:2px}
/* Sync indicator */
.sync-dot{width:10px;height:10px;border-radius:50%;background:#10b981;display:inline-block;margin-left:6px;animation:blinkDot 2s ease-in-out infinite;vertical-align:middle}
@keyframes blinkDot{0%,100%{opacity:1}50%{opacity:.3}}

/* Main Grid */
.main-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:2rem;margin-bottom:2rem;animation:fadeIn .8s ease-out .2s both}
@keyframes fadeIn{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}

/* Queue Card */
.queue-card{background:rgba(255,255,255,.05);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,.1);border-radius:24px;overflow:hidden;transition:all .3s;position:relative}
.queue-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,var(--primary),var(--accent))}
.queue-card.racikan::before{background:linear-gradient(90deg,var(--secondary),#dc2626)}
.card-header{padding:1.5rem 2rem;background:rgba(255,255,255,.03);border-bottom:1px solid rgba(255,255,255,.1)}
.card-title{display:flex;align-items:center;gap:.75rem;font-size:18px;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:1px}
.card-title i{font-size:24px;color:var(--accent)}
.card-title.racikan i{color:var(--secondary)}
.card-body{padding:3rem 2rem;text-align:center;min-height:360px;display:flex;flex-direction:column;justify-content:center}
.status-label{font-size:13px;font-weight:600;color:var(--gray);text-transform:uppercase;letter-spacing:2px;margin-bottom:2rem}
.queue-number{font-size:120px;font-weight:900;line-height:1;margin-bottom:2rem;font-variant-numeric:tabular-nums;background:linear-gradient(135deg,var(--primary),var(--accent));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;transition:opacity .3s,transform .3s}
.queue-number.racikan{background:linear-gradient(135deg,var(--secondary),#dc2626);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.queue-number.empty{background:none;-webkit-text-fill-color:rgba(255,255,255,.15);font-size:80px}
.queue-number.active{animation:pulse 2s ease-in-out infinite}
@keyframes pulse{0%,100%{transform:scale(1)}50%{transform:scale(1.05)}}
.patient-info{background:rgba(255,255,255,.05);border-radius:16px;padding:1.5rem;margin-top:1.5rem;border:1px solid rgba(255,255,255,.1)}
.info-item{display:flex;align-items:center;justify-content:center;gap:.75rem;padding:.75rem 0;font-size:16px;font-weight:500;color:#fff}
.info-item i{font-size:20px;color:var(--accent)}
.info-item.empty{color:var(--gray)}
/* Badge dipanggil ulang */
.repeat-badge{display:inline-block;background:rgba(245,158,11,.2);border:1px solid rgba(245,158,11,.4);color:var(--secondary);padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;margin-top:8px}

/* Alert panel saat ada panggilan baru */
@keyframes panelAlert{
  0%,100%{border-color:rgba(255,255,255,.1);box-shadow:none}
  50%{border-color:#fbbf24;box-shadow:0 0 40px rgba(251,191,36,.5)}
}
.queue-card.alert-card{animation:panelAlert 1s ease-in-out 4}

/* Info Banner */
.info-banner{background:rgba(245,158,11,.1);backdrop-filter:blur(20px);border:1px solid rgba(245,158,11,.2);border-radius:20px;padding:2rem 2.5rem;margin-bottom:2rem}
.info-banner-header{display:flex;align-items:center;gap:1rem;margin-bottom:1rem}
.info-banner-header i{font-size:28px;color:var(--secondary)}
.info-banner-title{font-size:18px;font-weight:700;color:#fff}
.info-banner-content{font-size:15px;line-height:1.7;color:rgba(255,255,255,.8)}
.info-banner-content strong{color:var(--secondary);font-weight:600}

/* Footer */
.footer{position:fixed;bottom:0;left:0;right:0;background:rgba(15,23,42,.95);backdrop-filter:blur(20px);border-top:1px solid rgba(255,255,255,.1);padding:1rem 0;z-index:100}
.marquee-container{overflow:hidden;white-space:nowrap}
.marquee{display:inline-block;padding-left:100%;animation:scroll 30s linear infinite;color:#fff;font-size:16px;font-weight:500}
@keyframes scroll{0%{transform:translateX(0)}100%{transform:translateX(-100%)}}
.marquee i{color:var(--secondary);margin:0 .5rem}

@media(max-width:1024px){.main-grid{grid-template-columns:1fr}.header-content{grid-template-columns:1fr;text-align:center;gap:1rem}.header-center{order:-1}}
@media(max-width:768px){.queue-number{font-size:90px}.queue-number.empty{font-size:60px}.card-body{padding:2rem 1.5rem;min-height:280px}}
@media(min-width:1920px){.queue-number{font-size:180px}.queue-number.empty{font-size:120px}.card-body{min-height:460px}}
</style>
</head>
<body>

<!-- Header -->
<div class="header">
  <div class="container">
    <div class="header-content">
      <div class="brand">
        <div class="brand-icon"><i class="bi bi-capsule-pill"></i></div>
        <div class="brand-text">
          <h1>Antrian Farmasi <span class="sync-dot" id="syncDot" title="Live sync aktif"></span></h1>
          <p>RS Permata Hati</p>
        </div>
      </div>
      <div class="header-center">
        <div class="date-display"><i class="bi bi-calendar3"></i> <?= tglIndonesia($tgl) ?></div>
        <div class="clock-display" id="clock">00:00:00</div>
      </div>
      <div style="width:56px"></div>
    </div>
  </div>
</div>

<!-- Main Content -->
<div class="container" style="padding-bottom:100px">
  <div class="main-grid">

    <!-- Non Racikan -->
    <div class="queue-card" id="cardNonRacikan">
      <div class="card-header">
        <div class="card-title"><i class="bi bi-prescription2"></i><span>Non Racikan</span></div>
      </div>
      <div class="card-body">
        <div class="status-label">Sedang Dipanggil</div>
        <?php if ($nr): ?>
          <div class="queue-number active" id="nrNumber"><?= htmlspecialchars($nr['no_antrian']) ?></div>
          <div class="patient-info" id="nrInfo">
            <div class="info-item"><i class="bi bi-person-fill"></i><span><?= htmlspecialchars($nr['nm_pasien']) ?></span></div>
            <div class="info-item"><i class="bi bi-hospital-fill"></i><span><?= htmlspecialchars($nr['nm_poli'] ?: 'Instalasi Farmasi') ?></span></div>
            <?php if ($nr['jml_panggil'] > 1): ?>
            <div class="repeat-badge"><i class="bi bi-arrow-repeat"></i> Dipanggil <?= $nr['jml_panggil'] ?>x</div>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="queue-number empty" id="nrNumber">-</div>
          <div class="patient-info" id="nrInfo">
            <div class="info-item empty"><i class="bi bi-hourglass-split"></i><span>Menunggu Panggilan</span></div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Racikan -->
    <div class="queue-card racikan" id="cardRacikan">
      <div class="card-header">
        <div class="card-title racikan"><i class="bi bi-capsule"></i><span>Racikan</span></div>
      </div>
      <div class="card-body">
        <div class="status-label">Sedang Dipanggil</div>
        <?php if ($r): ?>
          <div class="queue-number racikan active" id="rNumber"><?= htmlspecialchars($r['no_antrian']) ?></div>
          <div class="patient-info" id="rInfo">
            <div class="info-item"><i class="bi bi-person-fill"></i><span><?= htmlspecialchars($r['nm_pasien']) ?></span></div>
            <div class="info-item"><i class="bi bi-hospital-fill"></i><span><?= htmlspecialchars($r['nm_poli'] ?: 'Instalasi Farmasi') ?></span></div>
            <?php if ($r['jml_panggil'] > 1): ?>
            <div class="repeat-badge"><i class="bi bi-arrow-repeat"></i> Dipanggil <?= $r['jml_panggil'] ?>x</div>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="queue-number racikan empty" id="rNumber">-</div>
          <div class="patient-info" id="rInfo">
            <div class="info-item empty"><i class="bi bi-hourglass-split"></i><span>Menunggu Panggilan</span></div>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- Info Banner -->
  <div class="info-banner">
    <div class="info-banner-header">
      <i class="bi bi-info-circle-fill"></i>
      <div class="info-banner-title">Informasi Waktu Tunggu Resep Racikan</div>
    </div>
    <div class="info-banner-content">
      Sesuai <strong>Permenkes 72/2016</strong>, obat racikan membutuhkan proses tambahan
      (penimbangan, peracikan, pelabelan & validasi apoteker).
      Estimasi waktu pelayanan: <strong>± 15 – 60 menit</strong>.
      Terima kasih atas kesabaran dan pengertian Anda 🙏
    </div>
  </div>
</div>

<!-- Footer -->
<div class="footer">
  <div class="marquee-container">
    <div class="marquee">
      <i class="bi bi-heart-pulse-fill"></i> Selamat datang di RS Permata Hati
      <i class="bi bi-dot"></i> Layanan Farmasi Siap Melayani Anda dengan Sepenuh Hati
      <i class="bi bi-dot"></i> Mohon ambil nomor antrian dan tunggu panggilan Anda
      <i class="bi bi-dot"></i> Terima kasih atas kepercayaan Anda
      <i class="bi bi-heart-pulse-fill"></i> &nbsp;&nbsp;&nbsp;
    </div>
  </div>
</div>

<script>
// ============================================================
//  CLOCK
// ============================================================
function updateClock() {
  const now = new Date();
  document.getElementById('clock').textContent =
    [now.getHours(), now.getMinutes(), now.getSeconds()]
      .map(n => String(n).padStart(2,'0')).join(':');
}
setInterval(updateClock, 1000);
updateClock();

// ============================================================
//  POLLING LANGSUNG KE DB via get_current_call_farmasi.php
//  Kirim timestamp terakhir → server hanya balas jika berubah
// ============================================================
let tsNR = <?= $nr ? (int)$nr['ts'] : 0 ?>;  // timestamp Non Racikan
let tsR  = <?= $r  ? (int)$r['ts']  : 0 ?>;  // timestamp Racikan

// Preload bel notifikasi
const bellAudio = new Audio('sound/opening.mp3');
bellAudio.preload = 'auto';
bellAudio.load();

function renderCard(type, d) {
  const isRacikan  = type === 'r';
  const numEl      = document.getElementById(isRacikan ? 'rNumber'  : 'nrNumber');
  const infoEl     = document.getElementById(isRacikan ? 'rInfo'    : 'nrInfo');
  const cardEl     = document.getElementById(isRacikan ? 'cardRacikan' : 'cardNonRacikan');
  const colorClass = isRacikan ? 'racikan' : '';
  const repeatBadge = d.jml_panggil > 1
    ? `<div class="repeat-badge"><i class="bi bi-arrow-repeat"></i> Dipanggil ${d.jml_panggil}x</div>`
    : '';

  // Fade out
  numEl.style.opacity = '0';
  numEl.style.transform = 'scale(.95)';

  setTimeout(() => {
    if (d.has_data) {
      numEl.className  = `queue-number ${colorClass} active`;
      numEl.textContent = d.no_antrian;
      infoEl.innerHTML = `
        <div class="info-item"><i class="bi bi-person-fill"></i><span>${d.nm_pasien}</span></div>
        <div class="info-item"><i class="bi bi-hospital-fill"></i><span>${d.nm_poli}</span></div>
        ${repeatBadge}
      `;
    } else {
      numEl.className  = `queue-number ${colorClass} empty`;
      numEl.textContent = '-';
      infoEl.innerHTML = `<div class="info-item empty"><i class="bi bi-hourglass-split"></i><span>Menunggu Panggilan</span></div>`;
    }
    // Fade in
    numEl.style.opacity  = '1';
    numEl.style.transform = 'scale(1)';

    // Alert animasi card
    cardEl.classList.remove('alert-card');
    void cardEl.offsetWidth;
    cardEl.classList.add('alert-card');
    setTimeout(() => cardEl.classList.remove('alert-card'), 5000);
  }, 280);
}

function pollFarmasi() {
  const url = `get_current_call_farmasi.php?since_nr=${tsNR}&since_r=${tsR}`;

  fetch(url)
    .then(r => r.json())
    .then(data => {
      // Sync dot hijau = koneksi OK
      document.getElementById('syncDot').style.background = '#10b981';

      if (!data.changed) return; // Tidak ada yang berubah

      let hasNew = false;

      // Non Racikan berubah?
      if (data.ts_nr > tsNR) {
        tsNR = data.ts_nr;
        renderCard('nr', data.non_racikan);
        hasNew = true;
      }

      // Racikan berubah?
      if (data.ts_r > tsR) {
        tsR = data.ts_r;
        renderCard('r', data.racikan);
        hasNew = true;
      }

      // Bunyi bel hanya jika ada panggilan baru
      if (hasNew) {
        bellAudio.currentTime = 0;
        bellAudio.play().catch(() => {});
      }
    })
    .catch(() => {
      document.getElementById('syncDot').style.background = '#ef4444';
    });
}

// Polling tiap 2 detik
let interval = setInterval(pollFarmasi, 2000);

// Pause saat tab tidak aktif, resume saat aktif kembali
document.addEventListener('visibilitychange', () => {
  if (document.hidden) {
    clearInterval(interval);
  } else {
    pollFarmasi();
    interval = setInterval(pollFarmasi, 2000);
  }
});

// Full reload tiap 5 menit (refresh tampilan secara keseluruhan)
setTimeout(() => location.reload(), 300000);
</script>
</body>
</html>