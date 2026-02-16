<?php
session_start();
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$nama = $_SESSION['nama'] ?? 'Pengguna';
$today = date('Y-m-d');

try {
    $excluded_poli = ['IGDK','PL013','PL014','PL015','PL016','PL017','U0022','U0030'];
    $excluded_list = "'" . implode("','", $excluded_poli) . "'";

    // Ambil daftar poli dan dokter yang aktif hari ini
    $sql_poli = "
        SELECT 
            p.kd_poli, 
            p.nm_poli,
            d.kd_dokter,
            d.nm_dokter,
            COUNT(r.no_reg) as total_pasien,
            SUM(CASE WHEN r.stts = 'Sudah' THEN 1 ELSE 0 END) as sudah_dilayani,
            SUM(CASE WHEN r.stts IN ('Menunggu','Belum') THEN 1 ELSE 0 END) as menunggu
        FROM reg_periksa r
        JOIN poliklinik p ON r.kd_poli = p.kd_poli
        JOIN dokter d ON r.kd_dokter = d.kd_dokter
        WHERE r.tgl_registrasi = :tgl
          AND r.kd_poli NOT IN ($excluded_list)
        GROUP BY p.kd_poli, p.nm_poli, d.kd_dokter, d.nm_dokter
        ORDER BY p.nm_poli ASC, d.nm_dokter ASC";
    $stmt_poli = $pdo_simrs->prepare($sql_poli);
    $stmt_poli->bindValue(':tgl', $today);
    $stmt_poli->execute();
    $daftar_poli = $stmt_poli->fetchAll(PDO::FETCH_ASSOC);
    
    // Group by poli
    $poli_groups = [];
    $total_semua = 0;
    $sudah_semua = 0;
    $menunggu_semua = 0;
    $batal_semua = 0;
    
    foreach ($daftar_poli as $item) {
        $kd_poli = $item['kd_poli'];
        if (!isset($poli_groups[$kd_poli])) {
            $poli_groups[$kd_poli] = [
                'nm_poli' => $item['nm_poli'],
                'dokters' => [],
                'total_pasien' => 0,
                'sudah_dilayani' => 0,
                'menunggu' => 0
            ];
        }
        $poli_groups[$kd_poli]['dokters'][] = $item;
        $poli_groups[$kd_poli]['total_pasien'] += $item['total_pasien'];
        $poli_groups[$kd_poli]['sudah_dilayani'] += $item['sudah_dilayani'];
        $poli_groups[$kd_poli]['menunggu'] += $item['menunggu'];
        
        $total_semua += $item['total_pasien'];
        $sudah_semua += $item['sudah_dilayani'];
        $menunggu_semua += $item['menunggu'];
    }
} catch (PDOException $e) {
    die("Gagal mengambil data: " . $e->getMessage());
}

// Set page title dan extra CSS
$page_title = 'Display Per Poli - MediFix';
$extra_css = '
/* Stats Cards */
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

.stat-card:hover {
  transform: translateY(-5px);
  box-shadow: 0 5px 15px rgba(0,0,0,0.2);
}

.stat-card-content {
  padding: 20px;
  display: flex;
  align-items: center;
  gap: 15px;
}

.stat-icon {
  width: 60px;
  height: 60px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 30px;
  color: white;
}

.stat-info {
  flex: 1;
}

.stat-label {
  font-size: 13px;
  color: #666;
  margin-bottom: 5px;
}

.stat-value {
  font-size: 28px;
  font-weight: 700;
  color: #333;
}

.stat-total { border-top-color: #3c8dbc; }
.stat-total .stat-icon { background: #3c8dbc; }
.stat-sudah { border-top-color: #00a65a; }
.stat-sudah .stat-icon { background: #00a65a; }
.stat-menunggu { border-top-color: #f39c12; }
.stat-menunggu .stat-icon { background: #f39c12; }
.stat-batal { border-top-color: #dd4b39; }
.stat-batal .stat-icon { background: #dd4b39; }

/* Poli Grid */
.poli-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 15px;
}

.poli-card {
  background: #f8f9fa;
  border: 2px solid #e9ecef;
  border-radius: 6px;
  padding: 15px;
  cursor: pointer;
  transition: all 0.3s;
  text-decoration: none;
  color: inherit;
  display: block;
}

.poli-card:hover {
  border-color: #605ca8;
  box-shadow: 0 4px 12px rgba(96, 92, 168, 0.15);
  transform: translateY(-2px);
  text-decoration: none;
}

.poli-header {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 12px;
}

.poli-icon {
  width: 45px;
  height: 45px;
  background: linear-gradient(135deg, #605ca8, #9491c4);
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: white;
  font-size: 20px;
}

.poli-name {
  font-size: 15px;
  font-weight: 700;
  color: #2c3e50;
}

.dokter-count {
  display: inline-block;
  background: #e3f2fd;
  color: #1976d2;
  padding: 4px 10px;
  border-radius: 12px;
  font-size: 11px;
  font-weight: 600;
  margin-top: 5px;
}

.poli-stats {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
  margin-top: 12px;
}

.mini-stat {
  text-align: center;
  padding: 8px;
  background: white;
  border-radius: 4px;
}

.mini-stat-label {
  font-size: 10px;
  color: #999;
  text-transform: uppercase;
}

.mini-stat-value {
  font-size: 16px;
  font-weight: 700;
  margin-top: 3px;
}

.mini-stat-value.blue { color: #3c8dbc; }
.mini-stat-value.green { color: #00a65a; }
.mini-stat-value.orange { color: #f39c12; }

.poli-action {
  margin-top: 12px;
  padding: 10px;
  background: linear-gradient(135deg, #605ca8, #9491c4);
  color: white;
  text-align: center;
  border-radius: 4px;
  font-size: 13px;
  font-weight: 600;
  display: block;
  text-decoration: none;
  transition: all 0.3s;
}

.poli-action:hover {
  background: linear-gradient(135deg, #4e4a8f, #7d79a8);
  color: white;
  text-decoration: none;
}

.dokter-list {
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px dashed #dee2e6;
}

.dokter-item {
  padding: 8px 10px;
  background: white;
  border-radius: 4px;
  margin-bottom: 6px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 13px;
  cursor: pointer;
  transition: all 0.3s;
  text-decoration: none;
  color: inherit;
}

.dokter-item:hover {
  background: #e8f5e9;
  transform: translateX(5px);
  text-decoration: none;
}

.dokter-name {
  font-weight: 600;
  color: #2c3e50;
}

.dokter-waiting {
  color: #f39c12;
  font-size: 11px;
  font-weight: 600;
}

@media (max-width: 992px) {
  .stats-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 576px) {
  .stats-grid {
    grid-template-columns: 1fr;
  }
  
  .poli-grid {
    grid-template-columns: 1fr;
  }
}
';

$extra_js = '
// Auto refresh setiap 30 detik
setTimeout(() => location.reload(), 30000);
';

// Include header
include 'includes/header.php';

// Include sidebar
include 'includes/sidebar.php';
?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Pilih Display Poliklinik</h1>
      <ol class="breadcrumb">
        <li><a href="dashboard.php"><i class="fa fa-dashboard"></i> Home</a></li>
        <li><a href="#">Poliklinik</a></li>
        <li class="active">Display Per Poli</li>
      </ol>
    </section>

    <section class="content">

      <!-- Stats Cards -->
      <div class="stats-grid">
        <div class="stat-card stat-total">
          <div class="stat-card-content">
            <div class="stat-icon">
              <i class="fa fa-users"></i>
            </div>
            <div class="stat-info">
              <div class="stat-label">Total Pasien</div>
              <div class="stat-value"><?= $total_semua ?></div>
            </div>
          </div>
        </div>

        <div class="stat-card stat-sudah">
          <div class="stat-card-content">
            <div class="stat-icon">
              <i class="fa fa-check-circle"></i>
            </div>
            <div class="stat-info">
              <div class="stat-label">Sudah Dilayani</div>
              <div class="stat-value"><?= $sudah_semua ?></div>
            </div>
          </div>
        </div>

        <div class="stat-card stat-menunggu">
          <div class="stat-card-content">
            <div class="stat-icon">
              <i class="fa fa-clock-o"></i>
            </div>
            <div class="stat-info">
              <div class="stat-label">Sedang Menunggu</div>
              <div class="stat-value"><?= $menunggu_semua ?></div>
            </div>
          </div>
        </div>

        <div class="stat-card stat-batal">
          <div class="stat-card-content">
            <div class="stat-icon">
              <i class="fa fa-times-circle"></i>
            </div>
            <div class="stat-info">
              <div class="stat-label">Batal</div>
              <div class="stat-value"><?= $batal_semua ?></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Poli List -->
      <div class="row">
        <div class="col-xs-12">
          <div class="box">
            <div class="box-header">
              <h3 class="box-title">Daftar Poliklinik (<?= count($poli_groups) ?> Poli Aktif)</h3>
            </div>
            <div class="box-body">
              
              <?php if (count($poli_groups) > 0): ?>
              <div class="poli-grid">
                <?php foreach ($poli_groups as $kd_poli => $poli): ?>
                
                <div class="poli-card">
                  <div class="poli-header">
                    <div class="poli-icon">
                      <i class="fa fa-hospital"></i>
                    </div>
                    <div>
                      <div class="poli-name"><?= htmlspecialchars($poli['nm_poli']) ?></div>
                      <div class="dokter-count">
                        <i class="fa fa-user-md"></i>
                        <?= count($poli['dokters']) ?> Dokter
                      </div>
                    </div>
                  </div>
                  
                  <div class="poli-stats">
                    <div class="mini-stat">
                      <div class="mini-stat-label">Total</div>
                      <div class="mini-stat-value blue"><?= $poli['total_pasien'] ?></div>
                    </div>
                    <div class="mini-stat">
                      <div class="mini-stat-label">Selesai</div>
                      <div class="mini-stat-value green"><?= $poli['sudah_dilayani'] ?></div>
                    </div>
                    <div class="mini-stat">
                      <div class="mini-stat-label">Tunggu</div>
                      <div class="mini-stat-value orange"><?= $poli['menunggu'] ?></div>
                    </div>
                  </div>
                  
                  <?php if (count($poli['dokters']) > 1): ?>
                  <div class="dokter-list">
                    <?php foreach ($poli['dokters'] as $dok): ?>
                    <a href="display_poli.php?poli=<?= htmlspecialchars($kd_poli) ?>&dokter=<?= htmlspecialchars($dok['kd_dokter']) ?>" 
                       target="_blank"
                       class="dokter-item">
                      <span class="dokter-name">
                        <i class="fa fa-user-md"></i>
                        <?= htmlspecialchars($dok['nm_dokter']) ?>
                      </span>
                      <span class="dokter-waiting">
                        <i class="fa fa-clock-o"></i> <?= $dok['menunggu'] ?>
                      </span>
                    </a>
                    <?php endforeach; ?>
                  </div>
                  
                  <a href="display_poli.php?poli=<?= htmlspecialchars($kd_poli) ?>" 
                     target="_blank"
                     class="poli-action">
                    <i class="fa fa-tv"></i> Tampilkan Semua Dokter
                  </a>
                  <?php else: ?>
                  <?php $dok = $poli['dokters'][0]; ?>
                  <a href="display_poli.php?poli=<?= htmlspecialchars($kd_poli) ?>&dokter=<?= htmlspecialchars($dok['kd_dokter']) ?>" 
                     target="_blank"
                     class="poli-action">
                    <i class="fa fa-tv"></i> Tampilkan Display
                  </a>
                  <?php endif; ?>
                </div>
                
                <?php endforeach; ?>
              </div>
              <?php else: ?>
              <div class="callout callout-info">
                <h4><i class="fa fa-info"></i> Informasi</h4>
                <p>Belum ada poliklinik yang memiliki pasien hari ini</p>
              </div>
              <?php endif; ?>
              
            </div>
          </div>
        </div>
      </div>

    </section>
  </div>

<?php
// Include footer
include 'includes/footer.php';
?>