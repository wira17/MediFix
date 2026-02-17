<?php
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

$today  = date('Y-m-d');
$poli   = $_GET['poli']   ?? '';
$dokter = $_GET['dokter'] ?? '';

// === POLI YANG DISEMBUNYIKAN ===
$excluded_poli = ['IGDK','PL013','PL014','PL015','PL016','PL017','U0022','U0030'];
$excluded_list = "'" . implode("','", $excluded_poli) . "'";

try {
    // === PANGGILAN TERBARU DARI DB ===
    $sqlCall = "
        SELECT no_rawat, no_rkm_medis, no_antrian, nm_pasien, nm_poli,
               nm_dokter, kd_poli, kd_dokter, jml_panggil,
               UNIX_TIMESTAMP(updated_at) AS ts
        FROM simpan_antrian_poli_wira
        WHERE tgl_panggil = ?
    ";
    $paramsCall = [$today];
    if (!empty($poli))   { $sqlCall .= " AND kd_poli = ?";   $paramsCall[] = $poli; }
    if (!empty($dokter)) { $sqlCall .= " AND kd_dokter = ?"; $paramsCall[] = $dokter; }
    $sqlCall .= " ORDER BY updated_at DESC LIMIT 1";

    $stmtCall = $pdo_simrs->prepare($sqlCall);
    $stmtCall->execute($paramsCall);
    $current_call = $stmtCall->fetch(PDO::FETCH_ASSOC) ?: null;

    // === SEMUA DATA ANTRIAN HARI INI ===
    $sqlQ = "
        SELECT r.no_reg, r.kd_poli, r.kd_dokter, r.no_rawat,
               ps.nm_pasien, p.nm_poli, d.nm_dokter, r.stts
        FROM reg_periksa r
        LEFT JOIN pasien     ps ON r.no_rkm_medis = ps.no_rkm_medis
        LEFT JOIN poliklinik p  ON r.kd_poli      = p.kd_poli
        LEFT JOIN dokter     d  ON r.kd_dokter    = d.kd_dokter
        WHERE r.tgl_registrasi = ? AND r.kd_poli NOT IN ($excluded_list)
    ";
    $paramsQ = [$today];
    if (!empty($poli))   { $sqlQ .= " AND r.kd_poli = ?";   $paramsQ[] = $poli; }
    if (!empty($dokter)) { $sqlQ .= " AND r.kd_dokter = ?"; $paramsQ[] = $dokter; }
    $sqlQ .= " ORDER BY r.no_reg+0 ASC";

    $stmtQ = $pdo_simrs->prepare($sqlQ);
    $stmtQ->execute($paramsQ);
    $data = $stmtQ->fetchAll(PDO::FETCH_ASSOC);

    $total    = count($data);
    $sudah    = count(array_filter($data, fn($d) => $d['stts'] === 'Sudah'));
    $menunggu = count(array_filter($data, fn($d) => in_array($d['stts'], ['Menunggu','Belum'])));

    // Nama poli/dokter untuk header
    $nama_poli   = $data[0]['nm_poli']   ?? '';
    $nama_dokter = $data[0]['nm_dokter'] ?? '';

    function sensorNama($nama) {
        return implode(' ', array_map(fn($k) => mb_substr($k,0,1).str_repeat('*', max(0, mb_strlen($k)-1)), explode(' ', $nama)));
    }

} catch (PDOException $e) {
    die("Gagal mengambil data: " . $e->getMessage());
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Display Antrian Poliklinik - MediFix</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',sans-serif;background:linear-gradient(135deg,#1e3c72 0%,#2a5298 50%,#7e22ce 100%);color:#fff;overflow:hidden;height:100vh;display:flex;flex-direction:column}

.bg-animated{position:fixed;width:100%;height:100%;overflow:hidden;z-index:0;pointer-events:none}
.bg-animated::before,.bg-animated::after{content:'';position:absolute;border-radius:50%;opacity:.05}
.bg-animated::before{width:800px;height:800px;background:radial-gradient(circle,#fff 0%,transparent 70%);top:-400px;right:-200px;animation:float1 15s ease-in-out infinite}
.bg-animated::after{width:600px;height:600px;background:radial-gradient(circle,#fff 0%,transparent 70%);bottom:-300px;left:-200px;animation:float2 20s ease-in-out infinite}
@keyframes float1{0%,100%{transform:translate(0,0) rotate(0deg)}50%{transform:translate(50px,-50px) rotate(10deg)}}
@keyframes float2{0%,100%{transform:translate(0,0) rotate(0deg)}50%{transform:translate(-30px,30px) rotate(-10deg)}}

.header{background:rgba(255,255,255,.1);backdrop-filter:blur(10px);border-bottom:2px solid rgba(255,255,255,.2);padding:20px 40px;position:relative;z-index:10;box-shadow:0 4px 20px rgba(0,0,0,.2)}
.header-content{display:flex;justify-content:space-between;align-items:center}
.header-left h1{font-size:2rem;font-weight:900;margin:0;text-shadow:2px 2px 4px rgba(0,0,0,.3)}
.header-subtitle{font-size:1.2rem;font-weight:600;opacity:.9;margin-top:5px}
.header-right{text-align:right}
.live-date{font-size:1rem;font-weight:600;opacity:.9}
.live-clock{font-size:2.5rem;font-weight:900;margin-top:5px;text-shadow:2px 2px 4px rgba(0,0,0,.3)}

.main-content{flex:1;display:grid;grid-template-columns:1fr 1fr;gap:30px;padding:30px 40px;position:relative;z-index:1;overflow:hidden}

/* Panel kiri — panggilan aktif */
.panel-calling{background:rgba(255,255,255,.15);backdrop-filter:blur(15px);border-radius:30px;padding:40px;display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center;box-shadow:0 8px 32px rgba(0,0,0,.3);border:2px solid rgba(255,255,255,.2);position:relative;overflow:hidden;transition:border-color .4s,box-shadow .4s}
.panel-calling::before{content:'';position:absolute;top:-50%;left:-50%;width:200%;height:200%;background:radial-gradient(circle,rgba(255,255,255,.1) 0%,transparent 70%);animation:rotate 20s linear infinite}
@keyframes rotate{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}

.calling-label{font-size:1.4rem;font-weight:700;opacity:.9;margin-bottom:20px;position:relative;z-index:1}
.badge-new{display:inline-block;background:#ef4444;color:#fff;padding:3px 10px;border-radius:20px;font-size:.85rem;margin-left:8px;animation:blinkBadge 1.2s ease-in-out infinite}
@keyframes blinkBadge{0%,100%{opacity:1}50%{opacity:.4}}
.calling-number{font-size:7.5rem;font-weight:900;color:#fbbf24;text-shadow:4px 4px 8px rgba(0,0,0,.5);margin:20px 0;position:relative;z-index:1;animation:pulse 2s ease-in-out infinite}
@keyframes pulse{0%,100%{transform:scale(1)}50%{transform:scale(1.05)}}
.calling-name{font-size:2rem;font-weight:700;margin:15px 0;position:relative;z-index:1}
.calling-poli{font-size:1.3rem;font-weight:600;opacity:.9;position:relative;z-index:1}
.calling-doctor{font-size:1.1rem;font-weight:600;color:#fbbf24;margin-top:10px;position:relative;z-index:1}
.calling-count{font-size:.95rem;opacity:.75;margin-top:8px;position:relative;z-index:1}
.no-calling{font-size:6rem;color:rgba(255,255,255,.3);font-weight:900}
.no-calling-text{font-size:1.5rem;opacity:.5;margin-top:20px}
.sync-dot{width:10px;height:10px;border-radius:50%;background:#10b981;display:inline-block;margin-left:8px;animation:blinkDot 2s ease-in-out infinite;vertical-align:middle}
@keyframes blinkDot{0%,100%{opacity:1}50%{opacity:.3}}

/* Alert saat ada panggilan baru */
@keyframes callAlert{
  0%,100%{box-shadow:0 8px 32px rgba(0,0,0,.3);border-color:rgba(255,255,255,.2)}
  50%{box-shadow:0 8px 32px rgba(251,191,36,.9),0 0 70px rgba(251,191,36,.7);border-color:#fbbf24}
}
.panel-calling.alert-calling{animation:callAlert 1.2s ease-in-out 4}

/* Panel kanan — daftar antrian */
.panel-queue{background:rgba(255,255,255,.15);backdrop-filter:blur(15px);border-radius:30px;padding:30px;box-shadow:0 8px 32px rgba(0,0,0,.3);border:2px solid rgba(255,255,255,.2);display:flex;flex-direction:column;overflow:hidden}
.queue-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;padding-bottom:15px;border-bottom:2px solid rgba(255,255,255,.2)}
.queue-title{font-size:1.5rem;font-weight:800}
.queue-stats{display:flex;gap:12px}
.stat-badge{background:rgba(255,255,255,.2);padding:5px 12px;border-radius:15px;font-size:.9rem;font-weight:700}
.queue-list{flex:1;overflow-y:auto;padding-right:8px}
.queue-list::-webkit-scrollbar{width:7px}
.queue-list::-webkit-scrollbar-track{background:rgba(255,255,255,.1);border-radius:10px}
.queue-list::-webkit-scrollbar-thumb{background:rgba(255,255,255,.3);border-radius:10px}
.queue-item{display:grid;grid-template-columns:.6fr 1.3fr 1fr;gap:15px;align-items:center;background:rgba(255,255,255,.2);border-radius:15px;padding:15px 20px;margin-bottom:12px;transition:all .3s;border-left:4px solid transparent}
.queue-item.active{background:rgba(251,191,36,.3);border-left-color:#fbbf24;animation:blink 2s ease-in-out infinite}
.queue-item.done{opacity:.5;background:rgba(16,185,129,.2);border-left-color:#10b981}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.7}}
.queue-number{font-size:1.8rem;font-weight:900;color:#fbbf24}
.queue-name{font-size:1.3rem;font-weight:600}
.queue-poli{font-size:1.1rem;opacity:.9;text-align:right}
.queue-empty{text-align:center;padding:60px 20px;opacity:.5}
.queue-empty i{font-size:4rem;margin-bottom:20px;opacity:.3}

.footer{background:rgba(255,255,255,.1);backdrop-filter:blur(10px);border-top:2px solid rgba(255,255,255,.2);padding:15px 40px;text-align:center;font-size:1rem;font-weight:600;position:relative;z-index:10}
</style>
</head>
<body>
<div class="bg-animated"></div>

<!-- HEADER -->
<div class="header">
  <div class="header-content">
    <div class="header-left">
      <h1><i class="bi bi-tv-fill"></i> DISPLAY ANTRIAN POLIKLINIK <span class="sync-dot" id="syncDot" title="Live sync aktif"></span></h1>
      <div class="header-subtitle">
        <i class="bi bi-hospital"></i>
        <?= !empty($nama_poli) ? htmlspecialchars($nama_poli) . (!empty($nama_dokter) ? ' — '.htmlspecialchars($nama_dokter) : '') : 'Semua Poliklinik' ?>
      </div>
    </div>
    <div class="header-right">
      <div class="live-date" id="liveDate"></div>
      <div class="live-clock" id="liveClock"></div>
    </div>
  </div>
</div>

<!-- MAIN -->
<div class="main-content">

  <!-- Panel Kiri: Panggilan Aktif -->
  <div class="panel-calling" id="panelCalling">
    <?php if ($current_call): ?>
      <div class="calling-label"><i class="bi bi-megaphone-fill"></i> NOMOR ANTRIAN DIPANGGIL <span class="badge-new">🔴 LIVE</span></div>
      <div class="calling-number" id="callingNumber"><?= htmlspecialchars($current_call['no_antrian']) ?></div>
      <div class="calling-name"><?= htmlspecialchars(sensorNama($current_call['nm_pasien'])) ?></div>
      <div class="calling-poli"><i class="bi bi-geo-alt-fill"></i> Menuju <?= htmlspecialchars($current_call['nm_poli']) ?></div>
      <?php if (!empty($current_call['nm_dokter'])): ?>
      <div class="calling-doctor"><i class="bi bi-person-badge-fill"></i> <?= htmlspecialchars($current_call['nm_dokter']) ?></div>
      <?php endif; ?>
      <?php if ($current_call['jml_panggil'] > 1): ?>
      <div class="calling-count"><i class="bi bi-arrow-repeat"></i> Dipanggil <?= $current_call['jml_panggil'] ?>x</div>
      <?php endif; ?>
    <?php else: ?>
      <div class="no-calling"><i class="bi bi-hourglass-split"></i></div>
      <div class="no-calling-text">Menunggu Panggilan</div>
    <?php endif; ?>
  </div>

  <!-- Panel Kanan: Daftar Antrian -->
  <div class="panel-queue">
    <div class="queue-header">
      <div class="queue-title"><i class="bi bi-list-ol"></i> Daftar Antrian</div>
      <div class="queue-stats">
        <div class="stat-badge"><i class="bi bi-people-fill"></i> Total: <?= $total ?></div>
        <div class="stat-badge"><i class="bi bi-check-circle-fill"></i> Selesai: <?= $sudah ?></div>
        <div class="stat-badge"><i class="bi bi-clock-history"></i> Tunggu: <?= $menunggu ?></div>
      </div>
    </div>
    <div class="queue-list" id="queueList">
      <?php if ($data): ?>
        <?php foreach ($data as $row):
          $no_ant   = $row['kd_poli'].'-'.str_pad($row['no_reg'], 2, '0', STR_PAD_LEFT);
          $is_active = $current_call && $current_call['no_rawat'] === $row['no_rawat'];
          $is_done   = $row['stts'] === 'Sudah';
          $cls       = $is_active ? 'active' : ($is_done ? 'done' : '');
        ?>
        <div class="queue-item <?= $cls ?>" data-no-rawat="<?= htmlspecialchars($row['no_rawat']) ?>">
          <div class="queue-number"><?= htmlspecialchars($no_ant) ?></div>
          <div class="queue-name"><?= htmlspecialchars(sensorNama($row['nm_pasien'])) ?></div>
          <div class="queue-poli"><?= htmlspecialchars($row['nm_poli']) ?></div>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="queue-empty"><i class="bi bi-inbox"></i><div>Belum ada antrian hari ini</div></div>
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- FOOTER -->
<div class="footer">
  <i class="bi bi-hospital-fill"></i> MediFix — Sistem Antrian Poliklinik |
  <i class="bi bi-calendar-check"></i> <?= date('d F Y') ?>
</div>

<script>
// ============================================================
//  CLOCK
// ============================================================
function updateClock() {
  const now  = new Date();
  document.getElementById('liveClock').textContent = now.toLocaleTimeString('id-ID');
  document.getElementById('liveDate').textContent  = now.toLocaleDateString('id-ID', {weekday:'long',day:'numeric',month:'long',year:'numeric'});
}
setInterval(updateClock, 1000);
updateClock();

// ============================================================
//  POLLING RINGAN — baca dari DB via get_current_call.php
//  Setiap 2 detik, hanya kirim timestamp terakhir
//  Tidak perlu reload halaman penuh
// ============================================================
const POLI    = '<?= addslashes($poli) ?>';
const DOKTER  = '<?= addslashes($dokter) ?>';
let lastTs    = <?= $current_call ? (int)$current_call['ts'] : 0 ?>;
let lastNoAntrian = '<?= $current_call ? addslashes($current_call['no_antrian']) : '' ?>';

// Preload suara notifikasi
const bellAudio = new Audio('sound/opening.mp3');
bellAudio.preload = 'auto';
bellAudio.load();

function sensorNama(nama) {
  return nama.split(' ').map(k => k.charAt(0) + '*'.repeat(Math.max(0, k.length - 1))).join(' ');
}

function renderCallingPanel(d) {
  const panel = document.getElementById('panelCalling');
  const count = d.jml_panggil > 1 ? `<div class="calling-count"><i class="bi bi-arrow-repeat"></i> Dipanggil ${d.jml_panggil}x</div>` : '';
  const dok   = d.nm_dokter ? `<div class="calling-doctor"><i class="bi bi-person-badge-fill"></i> ${d.nm_dokter}</div>` : '';
  panel.innerHTML = `
    <div class="calling-label"><i class="bi bi-megaphone-fill"></i> NOMOR ANTRIAN DIPANGGIL <span class="badge-new">🔴 LIVE</span></div>
    <div class="calling-number" id="callingNumber">${d.no_antrian}</div>
    <div class="calling-name">${sensorNama(d.nm_pasien)}</div>
    <div class="calling-poli"><i class="bi bi-geo-alt-fill"></i> Menuju ${d.nm_poli}</div>
    ${dok}${count}
  `;
}

function updateQueueHighlight(noRawat) {
  document.querySelectorAll('.queue-item').forEach(el => {
    if (el.dataset.noRawat === noRawat) {
      el.classList.add('active');
      el.classList.remove('done');
      el.scrollIntoView({behavior:'smooth', block:'nearest'});
    } else if (!el.classList.contains('done')) {
      el.classList.remove('active');
    }
  });
}

function pollCall() {
  const url = `get_current_call.php?poli=${encodeURIComponent(POLI)}&dokter=${encodeURIComponent(DOKTER)}&since=${lastTs}`;

  fetch(url)
    .then(r => r.json())
    .then(data => {
      // Sync dot tetap hijau = koneksi OK
      document.getElementById('syncDot').style.background = '#10b981';

      if (!data.has_call || !data.changed) return; // Tidak ada perubahan

      lastTs = data.ts;

      // Ada panggilan BARU
      if (data.no_antrian !== lastNoAntrian) {
        lastNoAntrian = data.no_antrian;

        // Update panel
        renderCallingPanel(data);

        // Highlight baris di daftar
        updateQueueHighlight(data.no_rawat);

        // Animasi border kuning
        const panel = document.getElementById('panelCalling');
        panel.classList.remove('alert-calling');
        void panel.offsetWidth; // force reflow
        panel.classList.add('alert-calling');
        setTimeout(() => panel.classList.remove('alert-calling'), 5000);

        // Bunyi notifikasi di display (bukan suara TTS, hanya bel)
        bellAudio.currentTime = 0;
        bellAudio.play().catch(() => {});

      } else {
        // Nomor sama tapi jml_panggil bertambah — update counter saja
        renderCallingPanel(data);
      }
    })
    .catch(() => {
      // Koneksi gagal — sync dot merah
      document.getElementById('syncDot').style.background = '#ef4444';
    });
}

// Polling setiap 2 detik
setInterval(pollCall, 2000);

// Full reload setiap 5 menit untuk refresh daftar antrian
setInterval(() => location.reload(), 300000);
</script>
</body>
</html>