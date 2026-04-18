<?php
session_start();
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$nama = $_SESSION['nama'] ?? 'Pengguna';

// ===== FILTER & PENCARIAN =====
$search     = isset($_GET['search'])  ? trim($_GET['search'])  : '';
$filterKelas = isset($_GET['kelas'])  ? trim($_GET['kelas'])   : '';
$filterStatus = isset($_GET['status']) ? trim($_GET['status']) : '';

// ===== EXCLUDED BANGSAL (sama seperti ketersediaan_kamar.php) =====
$excludedBangsal = [
    'B0213','K302','B0114','B0115','B0112','B0113','RR01','RR02','RR03','RR04','B0219',
    'B0073','VK1','VK2','OM','OK1','OK2','OK3','OK4','B0081','B0082','B0083','B0084','P001',
    'B0096','K019','K020','K021','B0102','ISOC1','K308','M9B','NICU','B0100','B0212','TES','B0118'
];
$excludedBangsal     = array_map(fn($v) => strtoupper(trim($v)), $excludedBangsal);
$excludedBangsalList = "'" . implode("','", $excludedBangsal) . "'";

// ===== PAGINATION =====
$limit  = 20;
$page   = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

try {
    // Bangun WHERE dinamis
    $where  = "WHERE kamar.status IN ('KOSONG','ISI')
               AND UPPER(TRIM(kamar.kd_bangsal)) NOT IN ($excludedBangsalList)";
    $params = [];

    if ($search !== '') {
        $where   .= " AND (UPPER(kamar.kd_kamar) LIKE UPPER(:search) OR UPPER(bangsal.nm_bangsal) LIKE UPPER(:search2))";
        $params[':search']  = '%' . $search . '%';
        $params[':search2'] = '%' . $search . '%';
    }
    if ($filterKelas !== '') {
        $where   .= " AND kamar.kelas = :kelas";
        $params[':kelas'] = $filterKelas;
    }
    if ($filterStatus !== '') {
        $where   .= " AND kamar.status = :status";
        $params[':status'] = $filterStatus;
    }

    // Total untuk pagination
    $count_sql  = "SELECT COUNT(*) FROM kamar INNER JOIN bangsal ON kamar.kd_bangsal = bangsal.kd_bangsal $where";
    $count_stmt = $pdo_simrs->prepare($count_sql);
    $count_stmt->execute($params);
    $total       = $count_stmt->fetchColumn();
    $total_pages = ceil($total / $limit);

    // Data kamar
    $sql = "SELECT kamar.kd_kamar, bangsal.nm_bangsal, bangsal.kd_bangsal, kamar.kelas, kamar.status
            FROM kamar
            INNER JOIN bangsal ON kamar.kd_bangsal = bangsal.kd_bangsal
            $where
            ORDER BY kamar.kelas, bangsal.nm_bangsal, kamar.kd_kamar
            LIMIT $limit OFFSET $offset";
    $stmt  = $pdo_simrs->prepare($sql);
    $stmt->execute($params);
    $kamar = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Summary cards — tanpa filter agar selalu menampilkan total keseluruhan
    $sum_sql = "SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN kamar.status='KOSONG' THEN 1 ELSE 0 END) AS kosong,
                    SUM(CASE WHEN kamar.status='ISI'    THEN 1 ELSE 0 END) AS isi
                FROM kamar
                INNER JOIN bangsal ON kamar.kd_bangsal = bangsal.kd_bangsal
                WHERE kamar.status IN ('KOSONG','ISI')
                  AND UPPER(TRIM(kamar.kd_bangsal)) NOT IN ($excludedBangsalList)";
    $sum_stmt  = $pdo_simrs->query($sum_sql);
    $summary   = $sum_stmt->fetch(PDO::FETCH_ASSOC);
    $totalAll  = $summary['total']  ?? 0;
    $totalKosong = $summary['kosong'] ?? 0;
    $totalIsi    = $summary['isi']    ?? 0;
    $pctKosong   = $totalAll > 0 ? round(($totalKosong / $totalAll) * 100) : 0;

    // Rekap per kelas (untuk filter dropdown)
    $kelas_stmt  = $pdo_simrs->query("SELECT DISTINCT kelas FROM kamar WHERE status IN ('KOSONG','ISI') ORDER BY kelas");
    $daftar_kelas = $kelas_stmt->fetchAll(PDO::FETCH_COLUMN);

    // Rekap per kelas (untuk tabel ringkasan)
    $rekap_sql  = "SELECT kamar.kelas,
                          SUM(CASE WHEN kamar.status='KOSONG' THEN 1 ELSE 0 END) AS kosong,
                          SUM(CASE WHEN kamar.status='ISI'    THEN 1 ELSE 0 END) AS isi,
                          COUNT(*) AS total
                   FROM kamar
                   INNER JOIN bangsal ON kamar.kd_bangsal = bangsal.kd_bangsal
                   WHERE kamar.status IN ('KOSONG','ISI')
                     AND UPPER(TRIM(kamar.kd_bangsal)) NOT IN ($excludedBangsalList)
                   GROUP BY kamar.kelas
                   ORDER BY kamar.kelas";
    $rekap_stmt = $pdo_simrs->query($rekap_sql);
    $rekap      = $rekap_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Gagal mengambil data: " . $e->getMessage());
}

// ===== PAGE TITLE & CSS =====
$page_title = 'Ketersediaan Kamar - MediFix';
$extra_css  = '
.stats-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 20px;
  margin-bottom: 20px;
}
.stat-card {
  background: #fff;
  border-radius: 5px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.12);
  transition: all 0.3s ease;
  border-top: 3px solid;
  overflow: hidden;
}
.stat-card:hover { transform: translateY(-5px); box-shadow: 0 5px 15px rgba(0,0,0,0.2); }
.stat-card-content { padding: 20px; display: flex; align-items: center; gap: 15px; }
.stat-icon {
  width: 60px; height: 60px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 30px; color: white; flex-shrink: 0;
}
.stat-info { flex: 1; }
.stat-label { font-size: 13px; color: #666; margin-bottom: 5px; }
.stat-value { font-size: 28px; font-weight: 700; color: #333; }

.stat-total    { border-top-color: #3c8dbc; }
.stat-total    .stat-icon { background: #3c8dbc; }
.stat-kosong   { border-top-color: #00a65a; }
.stat-kosong   .stat-icon { background: #00a65a; }
.stat-isi      { border-top-color: #dd4b39; }
.stat-isi      .stat-icon { background: #dd4b39; }
.stat-pct      { border-top-color: #00c0ef; }
.stat-pct      .stat-icon { background: #00c0ef; }

@media (max-width:992px) { .stats-grid { grid-template-columns: repeat(2,1fr); } }
@media (max-width:576px) { .stats-grid { grid-template-columns: 1fr; } }

/* Filter bar */
.filter-bar {
  display: flex; flex-wrap: wrap; gap: 10px;
  align-items: flex-end; margin-bottom: 15px;
}
.filter-bar .form-group { margin: 0; }
.filter-bar label { font-size: 12px; color: #555; font-weight: 600; margin-bottom: 3px; display:block; }

/* Badge kelas */
.badge-kelas {
  display: inline-block; padding: 3px 8px;
  border-radius: 4px; font-size: 11px; font-weight: 700;
  color: #fff; text-transform: uppercase;
}
.kl-vip   { background: #9b59b6; }
.kl-utama { background: #e91e8c; }
.kl-1     { background: #3c8dbc; }
.kl-2     { background: #f39c12; }
.kl-3     { background: #8e44ad; }
.kl-def   { background: #95a5a6; }

/* Status badges */
.badge-kosong { background: #00a65a; color:#fff; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:700; }
.badge-isi    { background: #dd4b39; color:#fff; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:700; }

/* Rekap kelas table */
.rekap-wrap { margin-bottom: 20px; }
.rekap-table th { background: #3c8dbc; color: #fff; }
.rekap-table td { vertical-align: middle !important; }

/* Progress bar di tabel rekap */
.mini-bar { height: 8px; border-radius: 4px; background: #eee; overflow: hidden; margin-top: 4px; }
.mini-bar-fill { height: 100%; border-radius: 4px; background: #00a65a; }

/* Timestamp badge */
.ts-badge {
  background: #f4f4f4; border: 1px solid #ddd;
  border-radius: 4px; padding: 2px 7px; font-size: 11px; color: #888;
}

/* Refresh btn spinner */
@keyframes spin { 0%{transform:rotate(0)} 100%{transform:rotate(360deg)} }
.spinning { animation: spin .8s linear infinite; }
';

$extra_js = '
function updateClock() {
    const now = new Date();
    const opt = { weekday:"long", year:"numeric", month:"long", day:"numeric" };
    const el = document.getElementById("clockBadge");
    if (el) el.textContent = now.toLocaleDateString("id-ID", opt) + " — " + now.toLocaleTimeString("id-ID");
}
setInterval(updateClock, 1000);
updateClock();

function doRefresh() {
    const icon = document.getElementById("refreshIcon");
    if (icon) icon.classList.add("spinning");
    setTimeout(() => location.reload(), 300);
}

// Auto-refresh tiap 60 detik
setInterval(() => location.reload(), 60000);

// Search on Enter
document.addEventListener("DOMContentLoaded", function() {
    const inp = document.getElementById("inputSearch");
    if (inp) inp.addEventListener("keydown", function(e){ if(e.key==="Enter") this.form.submit(); });
});
';

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="content-wrapper">
  <section class="content-header">
    <h1>
      Ketersediaan Kamar
      <small>Data real-time tempat tidur rawat inap</small>
    </h1>
    <ol class="breadcrumb">
      <li><a href="dashboard.php"><i class="fa fa-dashboard"></i> Home</a></li>
      <li class="active">Ketersediaan Kamar</li>
    </ol>
  </section>

  <section class="content">

    <!-- ===== STATS CARDS ===== -->
    <div class="stats-grid">
      <div class="stat-card stat-total">
        <div class="stat-card-content">
          <div class="stat-icon"><i class="fa fa-bed"></i></div>
          <div class="stat-info">
            <div class="stat-label">Total Tempat Tidur</div>
            <div class="stat-value"><?= $totalAll ?></div>
          </div>
        </div>
      </div>
      <div class="stat-card stat-kosong">
        <div class="stat-card-content">
          <div class="stat-icon"><i class="fa fa-check-circle"></i></div>
          <div class="stat-info">
            <div class="stat-label">Tersedia</div>
            <div class="stat-value"><?= $totalKosong ?></div>
          </div>
        </div>
      </div>
      <div class="stat-card stat-isi">
        <div class="stat-card-content">
          <div class="stat-icon"><i class="fa fa-times-circle"></i></div>
          <div class="stat-info">
            <div class="stat-label">Terisi</div>
            <div class="stat-value"><?= $totalIsi ?></div>
          </div>
        </div>
      </div>
      <div class="stat-card stat-pct">
        <div class="stat-card-content">
          <div class="stat-icon"><i class="fa fa-bar-chart"></i></div>
          <div class="stat-info">
            <div class="stat-label">Ketersediaan</div>
            <div class="stat-value"><?= $pctKosong ?>%</div>
          </div>
        </div>
      </div>
    </div>

    <!-- ===== REKAP PER KELAS ===== -->
    <div class="rekap-wrap">
      <div class="box box-info collapsed-box">
        <div class="box-header with-border" style="cursor:pointer;" data-widget="collapse">
          <h3 class="box-title"><i class="fa fa-table"></i> Rekap per Kelas</h3>
          <div class="box-tools pull-right">
            <button type="button" class="btn btn-box-tool" data-widget="collapse">
              <i class="fa fa-plus"></i>
            </button>
          </div>
        </div>
        <div class="box-body">
          <div class="table-responsive">
            <table class="table table-bordered table-condensed rekap-table">
              <thead>
                <tr>
                  <th>Kelas</th>
                  <th class="text-center">Total TT</th>
                  <th class="text-center">Tersedia</th>
                  <th class="text-center">Terisi</th>
                  <th style="min-width:140px;">Ketersediaan</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rekap as $r):
                    $pct = $r['total'] > 0 ? round(($r['kosong'] / $r['total']) * 100) : 0;
                    $kl  = strtoupper($r['kelas']);
                    if     (str_contains($kl,'VIP'))   $klCls = 'kl-vip';
                    elseif (str_contains($kl,'UTAMA')) $klCls = 'kl-utama';
                    elseif (str_contains($kl,'1'))     $klCls = 'kl-1';
                    elseif (str_contains($kl,'2'))     $klCls = 'kl-2';
                    elseif (str_contains($kl,'3'))     $klCls = 'kl-3';
                    else                               $klCls = 'kl-def';
                ?>
                <tr>
                  <td><span class="badge-kelas <?= $klCls ?>"><?= htmlspecialchars($r['kelas']) ?></span></td>
                  <td class="text-center"><strong><?= $r['total'] ?></strong></td>
                  <td class="text-center"><span class="badge-kosong"><?= $r['kosong'] ?></span></td>
                  <td class="text-center"><span class="badge-isi"><?= $r['isi'] ?></span></td>
                  <td>
                    <span style="font-size:11px;color:#555;"><?= $pct ?>% tersedia</span>
                    <div class="mini-bar">
                      <div class="mini-bar-fill" style="width:<?= $pct ?>%;"></div>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- ===== TABEL UTAMA ===== -->
    <div class="row">
      <div class="col-xs-12">
        <div class="box">

          <div class="box-header with-border">
            <h3 class="box-title">
              <i class="fa fa-list"></i> Detail Kamar
              <span class="label label-primary" style="margin-left:8px;font-size:12px;">
                <?= $total ?> kamar
              </span>
            </h3>
            <div class="box-tools pull-right" style="display:flex;align-items:center;gap:8px;">
              <span class="ts-badge" id="clockBadge"></span>
              <button class="btn btn-default btn-sm" onclick="doRefresh()" title="Refresh data">
                <i class="fa fa-refresh" id="refreshIcon"></i> Refresh
              </button>
            </div>
          </div>

          <div class="box-body">

            <!-- Filter Bar -->
            <form method="GET" action="">
              <div class="filter-bar">
                <div class="form-group">
                  <label>Cari Kamar / Bangsal</label>
                  <input type="text" id="inputSearch" name="search" class="form-control input-sm"
                         placeholder="Kode kamar atau nama bangsal..."
                         value="<?= htmlspecialchars($search) ?>" style="width:220px;">
                </div>
                <div class="form-group">
                  <label>Kelas</label>
                  <select name="kelas" class="form-control input-sm" style="width:140px;">
                    <option value="">Semua Kelas</option>
                    <?php foreach ($daftar_kelas as $kl): ?>
                    <option value="<?= htmlspecialchars($kl) ?>" <?= ($filterKelas === $kl) ? 'selected' : '' ?>>
                      <?= htmlspecialchars($kl) ?>
                    </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group">
                  <label>Status</label>
                  <select name="status" class="form-control input-sm" style="width:130px;">
                    <option value="">Semua Status</option>
                    <option value="KOSONG" <?= ($filterStatus === 'KOSONG') ? 'selected' : '' ?>>Tersedia</option>
                    <option value="ISI"    <?= ($filterStatus === 'ISI')    ? 'selected' : '' ?>>Terisi</option>
                  </select>
                </div>
                <div class="form-group">
                  <label>&nbsp;</label>
                  <div style="display:flex;gap:5px;">
                    <button type="submit" class="btn btn-primary btn-sm">
                      <i class="fa fa-search"></i> Cari
                    </button>
                    <a href="data_ketersediaan_kamar.php" class="btn btn-default btn-sm">
                      <i class="fa fa-times"></i> Reset
                    </a>
                  </div>
                </div>
              </div>
            </form>

            <?php if (count($kamar) > 0): ?>
            <div class="table-responsive">
              <table class="table table-bordered table-striped table-hover" id="tblKamar">
                <thead style="background:#3c8dbc; color:#fff;">
                  <tr>
                    <th width="40" class="text-center">No</th>
                    <th width="110">Kode Kamar</th>
                    <th>Nama Bangsal</th>
                    <th width="110" class="text-center">Kelas</th>
                    <th width="120" class="text-center">Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $no = $offset + 1;
                  foreach ($kamar as $k):
                    $st    = $k['status'];
                    $kl    = strtoupper($k['kelas']);
                    if     (str_contains($kl,'VIP'))   $klCls = 'kl-vip';
                    elseif (str_contains($kl,'UTAMA')) $klCls = 'kl-utama';
                    elseif (str_contains($kl,'1'))     $klCls = 'kl-1';
                    elseif (str_contains($kl,'2'))     $klCls = 'kl-2';
                    elseif (str_contains($kl,'3'))     $klCls = 'kl-3';
                    else                               $klCls = 'kl-def';
                    $rowStyle = ($st === 'KOSONG') ? 'background:#f6fffa;' : '';
                  ?>
                  <tr style="<?= $rowStyle ?>">
                    <td class="text-center"><?= $no++ ?></td>
                    <td>
                      <strong style="color:#3c8dbc; font-size:13px; letter-spacing:.5px;">
                        <?= htmlspecialchars($k['kd_kamar']) ?>
                      </strong>
                    </td>
                    <td><?= htmlspecialchars($k['nm_bangsal']) ?></td>
                    <td class="text-center">
                      <span class="badge-kelas <?= $klCls ?>">
                        <?= htmlspecialchars($k['kelas']) ?>
                      </span>
                    </td>
                    <td class="text-center">
                      <?php if ($st === 'KOSONG'): ?>
                        <span class="badge-kosong"><i class="fa fa-check"></i> Tersedia</span>
                      <?php else: ?>
                        <span class="badge-isi"><i class="fa fa-times"></i> Terisi</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="box-footer clearfix" style="border-top:1px solid #eee; padding-top:12px;">
              <small class="text-muted">
                Menampilkan <?= $offset + 1 ?>–<?= min($offset + $limit, $total) ?> dari <?= $total ?> kamar
              </small>
              <ul class="pagination pagination-sm no-margin pull-right">
                <li <?= ($page <= 1) ? 'class="disabled"' : '' ?>>
                  <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&kelas=<?= urlencode($filterKelas) ?>&status=<?= urlencode($filterStatus) ?>">«</a>
                </li>
                <?php
                // Tampilkan maks 7 halaman di sekitar halaman aktif
                $start = max(1, $page - 3);
                $end   = min($total_pages, $page + 3);
                if ($start > 1): ?><li class="disabled"><a>…</a></li><?php endif;
                for ($i = $start; $i <= $end; $i++): ?>
                  <li <?= ($i == $page) ? 'class="active"' : '' ?>>
                    <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&kelas=<?= urlencode($filterKelas) ?>&status=<?= urlencode($filterStatus) ?>"><?= $i ?></a>
                  </li>
                <?php endfor;
                if ($end < $total_pages): ?><li class="disabled"><a>…</a></li><?php endif; ?>
                <li <?= ($page >= $total_pages) ? 'class="disabled"' : '' ?>>
                  <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&kelas=<?= urlencode($filterKelas) ?>&status=<?= urlencode($filterStatus) ?>">»</a>
                </li>
              </ul>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <div class="callout callout-info">
              <h4><i class="fa fa-info-circle"></i> Tidak Ada Data</h4>
              <p>Tidak ditemukan kamar yang sesuai filter yang dipilih.</p>
            </div>
            <?php endif; ?>

          </div><!-- /box-body -->
        </div><!-- /box -->
      </div>
    </div>

  </section>
</div>

<?php include 'includes/footer.php'; ?>