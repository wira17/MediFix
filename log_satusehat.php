<?php
session_start();
include 'koneksi.php';
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// ── Definisi file log ─────────────────────────────────────────────
$log_dir = __DIR__ . '/logs';
$bulan   = $_GET['bulan'] ?? date('Y-m');

// Validasi format bulan
if (!preg_match('/^\d{4}-\d{2}$/', $bulan)) $bulan = date('Y-m');

$log_files = [
    'diagnosticreport' => [
        'label' => 'Diagnostic Report',
        'icon'  => 'fa-file-text-o',
        'color' => '#0073b7',
        'file'  => $log_dir . '/diagnosticreport_' . $bulan . '.log',
    ],
    'dicom_upload' => [
        'label' => 'Upload DICOM',
        'icon'  => 'fa-cloud-upload',
        'color' => '#00a65a',
        'file'  => $log_dir . '/dicom_upload_' . $bulan . '.log',
    ],
    'ihs_sync' => [
        'label' => 'Sinkronisasi IHS',
        'icon'  => 'fa-users',
        'color' => '#605ca8',
        'file'  => $log_dir . '/ihs_sync_' . $bulan . '.log',
    ],
    'servicerequest' => [
        'label' => 'Service Request',
        'icon'  => 'fa-heartbeat',
        'color' => '#605ca8',
        'file'  => $log_dir . '/servicerequest_' . $bulan . '.log',
    ],
];

$active_log = $_GET['log'] ?? 'diagnosticreport';
if (!array_key_exists($active_log, $log_files)) $active_log = 'diagnosticreport';

$filter_type = $_GET['filter'] ?? '';  // ok, error, warn, all
$cari        = $_GET['cari'] ?? '';
$limit_lines = (int)($_GET['lines'] ?? 200);

// ── Baca log ──────────────────────────────────────────────────────
function bacaLog(string $file, string $filter, string $cari, int $limit): array {
    if (!file_exists($file)) return [];

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return [];

    // Ambil dari belakang (terbaru dulu)
    $lines = array_reverse($lines);

    $result = [];
    foreach ($lines as $line) {
        // Filter tipe
        if ($filter === 'ok'    && stripos($line, ' OK ')    === false && stripos($line, 'SUKSES') === false) continue;
        if ($filter === 'error' && stripos($line, 'ERROR')   === false && stripos($line, 'GAGAL')  === false && stripos($line, 'EXCEPTION') === false) continue;
        if ($filter === 'warn'  && stripos($line, 'NOT_FOUND') === false && stripos($line, 'WARN') === false) continue;

        // Filter cari
        if (!empty($cari) && stripos($line, $cari) === false) continue;

        $result[] = $line;
        if (count($result) >= $limit) break;
    }
    return $result;
}

function klasifikasiLog(string $line): string {
    $upper = strtoupper($line);
    if (strpos($upper, 'ERROR') !== false || strpos($upper, 'EXCEPTION') !== false || strpos($upper, 'GAGAL') !== false) return 'error';
    if (strpos($upper, 'OK') !== false || strpos($upper, 'SUKSES') !== false || strpos($upper, 'BERHASIL') !== false) return 'ok';
    if (strpos($upper, 'NOT_FOUND') !== false || strpos($upper, 'WARN') !== false || strpos($upper, 'PENDING') !== false) return 'warn';
    return 'info';
}

// Hitung stats tiap log
function hitungStats(string $file): array {
    if (!file_exists($file)) return ['total' => 0, 'ok' => 0, 'error' => 0, 'warn' => 0];
    $lines  = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $ok = $error = $warn = 0;
    foreach ($lines as $l) {
        $u = strtoupper($l);
        if (strpos($u,'ERROR')!==false || strpos($u,'EXCEPTION')!==false) $error++;
        elseif (strpos($u,'OK')!==false || strpos($u,'SUKSES')!==false)   $ok++;
        elseif (strpos($u,'NOT_FOUND')!==false || strpos($u,'WARN')!==false) $warn++;
    }
    return ['total' => count($lines), 'ok' => $ok, 'error' => $error, 'warn' => $warn];
}

$log_info  = $log_files[$active_log];
$log_lines = bacaLog($log_info['file'], $filter_type, $cari, $limit_lines);
$log_stats = hitungStats($log_info['file']);

// Daftar bulan yang tersedia (dari file yang ada)
$bulan_list = [];
for ($i = 0; $i < 12; $i++) {
    $b = date('Y-m', strtotime("-$i months"));
    foreach ($log_files as $lf) {
        $f = str_replace(date('Y-m'), $b, $lf['file']);
        $fname = $log_dir . '/' . basename($f);
        // rebuild path
    }
    $bulan_list[] = $b;
}

$page_title = 'Log Satu Sehat — MediFix';

$extra_css = '
.log-tabs{display:flex;gap:0;border-bottom:2px solid #e5e5e5;margin-bottom:0;flex-wrap:wrap}
.log-tab{padding:10px 18px;cursor:pointer;font-size:13px;font-weight:600;color:#888;border:1px solid transparent;border-bottom:none;border-radius:6px 6px 0 0;display:flex;align-items:center;gap:7px;transition:all .2s;background:#f9f9f9;margin-bottom:-2px}
.log-tab:hover{background:#f0f0ff;color:#605ca8}
.log-tab.active{background:#fff;color:#605ca8;border-color:#e5e5e5;border-bottom-color:#fff;z-index:1}
.log-tab .tab-badge{font-size:10px;padding:1px 6px;border-radius:8px;font-weight:700}
.tab-badge.err{background:#f2dede;color:#a94442}
.tab-badge.ok{background:#dff0d8;color:#3c763d}

.log-container{background:#1e1e2e;border-radius:0 0 8px 8px;padding:0;overflow:hidden}
.log-toolbar{background:#2d2d3f;padding:8px 14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;border-bottom:1px solid #3d3d50}
.log-toolbar input{background:#1e1e2e;border:1px solid #3d3d50;color:#ccc;padding:5px 10px;border-radius:4px;font-size:12px;width:200px;outline:none}
.log-toolbar input::placeholder{color:#666}
.log-toolbar select{background:#1e1e2e;border:1px solid #3d3d50;color:#ccc;padding:5px 8px;border-radius:4px;font-size:12px;outline:none}
.log-toolbar button{padding:5px 12px;border-radius:4px;font-size:12px;border:none;cursor:pointer;font-weight:600}
.btn-clear-log{background:#dd4b39;color:#fff}
.btn-refresh-log{background:#605ca8;color:#fff}
.btn-export-log{background:#00a65a;color:#fff}

.log-body{max-height:600px;overflow-y:auto;padding:12px 16px;font-family:"Courier New",monospace;font-size:12px;line-height:1.7}
.log-body::-webkit-scrollbar{width:6px}
.log-body::-webkit-scrollbar-track{background:#1e1e2e}
.log-body::-webkit-scrollbar-thumb{background:#3d3d50;border-radius:3px}

.log-line{padding:2px 0;border-bottom:1px solid #2d2d3f22}
.log-line:last-child{border-bottom:none}
.log-line.ok    .log-text{color:#89d185}
.log-line.error .log-text{color:#f48771}
.log-line.warn  .log-text{color:#dcdcaa}
.log-line.info  .log-text{color:#9cdcfe}
.log-time{color:#888;margin-right:8px;flex-shrink:0}
.log-text{word-break:break-all}

.log-empty{text-align:center;padding:60px;color:#555}
.log-empty i{font-size:40px;display:block;margin-bottom:12px}

.stat-cards{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px}
.stat-card{flex:1;min-width:120px;background:#fff;border-radius:8px;padding:12px 16px;border-left:4px solid #ccc;box-shadow:0 1px 4px rgba(0,0,0,.06)}
.stat-card.total{border-color:#605ca8}
.stat-card.ok{border-color:#00a65a}
.stat-card.error{border-color:#dd4b39}
.stat-card.warn{border-color:#f39c12}
.stat-num{font-size:22px;font-weight:700;color:#333}
.stat-lbl{font-size:11px;color:#aaa;margin-top:2px}

.log-count{font-size:12px;color:#666;padding:6px 16px;background:#2d2d3f;border-top:1px solid #3d3d50}

#toast-container{position:fixed;bottom:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.toast-msg{padding:10px 18px;border-radius:6px;font-size:13px;font-weight:600;color:#fff;display:flex;align-items:center;gap:8px;box-shadow:0 4px 12px rgba(0,0,0,.2);pointer-events:auto;animation:toastIn .3s ease}
.toast-success{background:#00a65a}.toast-error{background:#dd4b39}.toast-info{background:#00c0ef}
@keyframes toastIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
';

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div id="toast-container"></div>

<div class="content-wrapper">
  <section class="content-header">
    <h1>
      <i class="fa fa-terminal" style="color:#605ca8;"></i>
      Log Satu Sehat
      <small>Monitor aktivitas integrasi secara real-time</small>
    </h1>
    <ol class="breadcrumb">
      <li><a href="dashboard.php"><i class="fa fa-dashboard"></i> Home</a></li>
      <li>Satu Sehat</li>
      <li class="active">Log</li>
    </ol>
  </section>

  <section class="content">
    <div class="row">
      <div class="col-xs-12">

        <!-- Filter bulan + aksi -->
        <div class="box box-default">
          <div class="box-body" style="padding:10px 15px;">
            <form method="GET" class="form-inline" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
              <input type="hidden" name="log" value="<?= htmlspecialchars($active_log) ?>">
              <div class="form-group">
                <label style="margin-right:6px;font-size:13px;"><i class="fa fa-calendar"></i> Bulan:</label>
                <select name="bulan" class="form-control" onchange="this.form.submit()">
                  <?php foreach ($bulan_list as $b): ?>
                  <option value="<?= $b ?>" <?= $b === $bulan ? 'selected' : '' ?>>
                    <?= date('F Y', strtotime($b . '-01')) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label style="margin-right:6px;font-size:13px;">Filter:</label>
                <select name="filter" class="form-control" onchange="this.form.submit()">
                  <option value=""      <?= $filter_type===''      ?'selected':'' ?>>Semua</option>
                  <option value="ok"    <?= $filter_type==='ok'    ?'selected':'' ?>>OK / Sukses</option>
                  <option value="error" <?= $filter_type==='error' ?'selected':'' ?>>Error</option>
                  <option value="warn"  <?= $filter_type==='warn'  ?'selected':'' ?>>Warning</option>
                </select>
              </div>
              <div class="form-group">
                <input type="text" name="cari" class="form-control"
                       placeholder="Cari noorder / ID / kata kunci…"
                       value="<?= htmlspecialchars($cari) ?>" style="width:240px;">
              </div>
              <div class="form-group">
                <label style="margin-right:6px;font-size:13px;">Tampil:</label>
                <select name="lines" class="form-control" onchange="this.form.submit()">
                  <?php foreach ([100, 200, 500, 1000] as $n): ?>
                  <option value="<?= $n ?>" <?= $limit_lines===$n?'selected':'' ?>><?= $n ?> baris</option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button type="submit" class="btn btn-primary btn-sm"><i class="fa fa-search"></i> Tampilkan</button>
              <a href="?log=<?= urlencode($active_log) ?>&bulan=<?= urlencode($bulan) ?>"
                 class="btn btn-default btn-sm"><i class="fa fa-refresh"></i> Reset</a>
            </form>
          </div>
        </div>

        <!-- Stat cards per log aktif -->
        <div class="stat-cards">
          <div class="stat-card total">
            <div class="stat-num"><?= number_format($log_stats['total']) ?></div>
            <div class="stat-lbl"><i class="fa fa-list"></i> Total Baris</div>
          </div>
          <div class="stat-card ok">
            <div class="stat-num"><?= number_format($log_stats['ok']) ?></div>
            <div class="stat-lbl"><i class="fa fa-check-circle"></i> Sukses</div>
          </div>
          <div class="stat-card error">
            <div class="stat-num"><?= number_format($log_stats['error']) ?></div>
            <div class="stat-lbl"><i class="fa fa-times-circle"></i> Error</div>
          </div>
          <div class="stat-card warn">
            <div class="stat-num"><?= number_format($log_stats['warn']) ?></div>
            <div class="stat-lbl"><i class="fa fa-exclamation-triangle"></i> Warning</div>
          </div>
          <!-- Ringkasan semua log -->
          <?php foreach ($log_files as $key => $lf): ?>
          <?php $s = hitungStats($lf['file']); ?>
          <div class="stat-card" style="border-color:<?= $lf['color'] ?>;cursor:pointer"
               onclick="window.location='?log=<?= $key ?>&bulan=<?= urlencode($bulan) ?>'">
            <div style="display:flex;align-items:center;gap:6px">
              <i class="fa <?= $lf['icon'] ?>" style="color:<?= $lf['color'] ?>"></i>
              <div class="stat-num" style="font-size:16px;"><?= number_format($s['total']) ?></div>
              <?php if ($s['error'] > 0): ?>
              <span class="label label-danger" style="font-size:10px;"><?= $s['error'] ?> err</span>
              <?php endif; ?>
            </div>
            <div class="stat-lbl"><?= $lf['label'] ?></div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Tab log -->
        <div class="box" style="margin-bottom:0;border:none">
          <div class="box-body" style="padding:0;">

            <div class="log-tabs">
              <?php foreach ($log_files as $key => $lf):
                $s = hitungStats($lf['file']);
              ?>
              <a href="?log=<?= $key ?>&bulan=<?= urlencode($bulan) ?>&filter=<?= urlencode($filter_type) ?>&cari=<?= urlencode($cari) ?>&lines=<?= $limit_lines ?>"
                 class="log-tab <?= $key === $active_log ? 'active' : '' ?>">
                <i class="fa <?= $lf['icon'] ?>" style="color:<?= $lf['color'] ?>"></i>
                <?= $lf['label'] ?>
                <?php if ($s['error'] > 0): ?>
                <span class="tab-badge err"><?= $s['error'] ?> error</span>
                <?php elseif ($s['total'] > 0): ?>
                <span class="tab-badge ok"><?= $s['total'] ?></span>
                <?php endif; ?>
              </a>
              <?php endforeach; ?>
            </div>

            <div class="log-container">
              <div class="log-toolbar">
                <span style="color:#888;font-size:12px;"><i class="fa fa-file-text-o"></i>
                  <?= file_exists($log_info['file'])
                      ? basename($log_info['file']) . ' — ' . number_format(filesize($log_info['file'])/1024, 1) . ' KB'
                      : 'File tidak ditemukan' ?>
                </span>
                <span style="flex:1"></span>
                <button class="btn-refresh-log" onclick="location.reload()">
                  <i class="fa fa-refresh"></i> Refresh
                </button>
                <button class="btn-export-log" onclick="exportLog()">
                  <i class="fa fa-download"></i> Export
                </button>
                <?php if (file_exists($log_info['file'])): ?>
                <button class="btn-clear-log" onclick="clearLog('<?= htmlspecialchars($active_log) ?>')">
                  <i class="fa fa-trash"></i> Clear Log
                </button>
                <?php endif; ?>
              </div>

              <div class="log-body" id="logBody">
                <?php if (empty($log_lines)): ?>
                <div class="log-empty">
                  <i class="fa fa-inbox"></i>
                  <div style="color:#666;">
                    <?php if (!file_exists($log_info['file'])): ?>
                      File log belum ada — belum ada aktivitas di bulan ini
                    <?php elseif (!empty($cari) || !empty($filter_type)): ?>
                      Tidak ada baris yang cocok dengan filter
                    <?php else: ?>
                      Log kosong
                    <?php endif; ?>
                  </div>
                </div>
                <?php else: ?>
                <?php foreach ($log_lines as $line):
                    $kelas = klasifikasiLog($line);
                    // Pisah timestamp dan konten
                    preg_match('/^(\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\])\s*(.*)$/', $line, $m);
                    $timestamp = $m[1] ?? '';
                    $konten    = $m[2] ?? $line;
                    // Highlight kata kunci pencarian
                    if (!empty($cari)) {
                        $konten = str_ireplace(
                            htmlspecialchars($cari),
                            '<mark style="background:#ffeb3b;color:#333;border-radius:2px">' . htmlspecialchars($cari) . '</mark>',
                            htmlspecialchars($konten)
                        );
                    } else {
                        $konten = htmlspecialchars($konten);
                    }
                ?>
                <div class="log-line <?= $kelas ?>">
                  <span class="log-time"><?= htmlspecialchars($timestamp) ?></span><span class="log-text"><?= $konten ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
              </div>

              <div class="log-count">
                Menampilkan <?= count($log_lines) ?> dari <?= $log_stats['total'] ?> baris
                — Bulan: <?= date('F Y', strtotime($bulan . '-01')) ?>
                — Auto-refresh:
                <select id="autoRefreshSel" onchange="setAutoRefresh(this.value)" style="background:#1e1e2e;border:1px solid #3d3d50;color:#999;padding:2px 6px;border-radius:3px;font-size:11px;">
                  <option value="0">Off</option>
                  <option value="10">10 detik</option>
                  <option value="30">30 detik</option>
                  <option value="60">1 menit</option>
                </select>
              </div>
            </div>

          </div>
        </div>

      </div>
    </div>
  </section>
</div>

<!-- Clear log handler -->
<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_log'])) {
    header('Content-Type: application/json');
    $logKey = $_POST['log_key'] ?? '';
    if (array_key_exists($logKey, $log_files)) {
        $f = $log_files[$logKey]['file'];
        if (file_exists($f)) {
            file_put_contents($f, '');
            echo json_encode(['status' => 'ok']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'File tidak ditemukan']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Log tidak dikenal']);
    }
    exit;
}
?>

<script>
let autoRefreshTimer = null;

function setAutoRefresh(seconds) {
    clearInterval(autoRefreshTimer);
    if (parseInt(seconds) > 0) {
        autoRefreshTimer = setInterval(() => location.reload(), parseInt(seconds) * 1000);
    }
}

function clearLog(logKey) {
    if (!confirm('Hapus semua isi log ' + logKey + '?\nTidak bisa dibatalkan.')) return;
    fetch(window.location.href, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({action_log: '1', log_key: logKey})
    })
    .then(r => r.json())
    .then(resp => {
        if (resp.status === 'ok') {
            showToast('Log berhasil dikosongkan', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast('Gagal: ' + resp.message, 'error');
        }
    });
}

function exportLog() {
    const lines = document.querySelectorAll('.log-line');
    if (!lines.length) { showToast('Tidak ada data untuk diekspor', 'info'); return; }
    let txt = '';
    lines.forEach(l => {
        const ts   = l.querySelector('.log-time')?.textContent || '';
        const text = l.querySelector('.log-text')?.textContent || '';
        txt += ts + ' ' + text + '\n';
    });
    const a = document.createElement('a');
    a.href = 'data:text/plain;charset=utf-8,' + encodeURIComponent(txt);
    a.download = 'log_satusehat_<?= $active_log ?>_<?= $bulan ?>.txt';
    a.click();
    showToast('Log diekspor!', 'success');
}

function showToast(msg, type) {
    const icons = {success:'check-circle',error:'times-circle',info:'info-circle'};
    const d = document.createElement('div');
    d.className = 'toast-msg toast-' + (type||'info');
    d.innerHTML = `<i class="fa fa-${icons[type]||'info-circle'}"></i> ${msg}`;
    document.getElementById('toast-container').appendChild(d);
    setTimeout(() => d.remove(), 3500);
}

// Scroll log ke bawah saat load
document.addEventListener('DOMContentLoaded', () => {
    const lb = document.getElementById('logBody');
    // tidak auto-scroll karena kita tampilkan terbaru di atas
});
</script>

<?php include 'includes/footer.php'; ?>