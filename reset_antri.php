<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
include 'koneksi2.php'; // dapat $pdo (lokal) + $pdo_simrs (SIMRS)
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$nama    = $_SESSION['nama'] ?? 'Pengguna';
$today   = date('Y-m-d');
$success = '';
$error   = '';
$deleted_count = 0;

// ============================================================
//  EKSEKUSI RESET
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    $action    = $_POST['action'] ?? '';
    $tgl_reset = $_POST['tgl_reset'] ?? $today;

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_reset)) {
        $error = "Format tanggal tidak valid.";
    } elseif ($action === 'reset_admisi') {
        try {
            $stmt = $pdo_simrs->prepare(
                "DELETE FROM antrian_wira WHERE jenis = 'ADMISI' AND DATE(created_at) = ?"
            );
            $stmt->execute([$tgl_reset]);
            $deleted_count = $stmt->rowCount();

            $label_tgl = date('d F Y', strtotime($tgl_reset));
            $success   = "Reset berhasil! <strong>$deleted_count data</strong> antrian Admisi tanggal <strong>$label_tgl</strong> telah dihapus. Besok nomor antrian akan mulai dari <strong>A001</strong> lagi.";

            // Simpan log
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS log_reset_antrian (
                    id             INT AUTO_INCREMENT PRIMARY KEY,
                    jenis          VARCHAR(50)  NOT NULL,
                    tgl_antrian    DATE         NOT NULL,
                    jumlah_dihapus INT          DEFAULT 0,
                    direset_oleh   VARCHAR(100),
                    waktu_reset    DATETIME     DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $pdo->prepare(
                    "INSERT INTO log_reset_antrian (jenis, tgl_antrian, jumlah_dihapus, direset_oleh) VALUES (?, ?, ?, ?)"
                )->execute(['Antrian Admisi', $tgl_reset, $deleted_count, $nama]);
            } catch (PDOException $eLog) { /* log gagal tidak masalah */ }

        } catch (PDOException $e) {
            $error = "Gagal melakukan reset: " . $e->getMessage();
        }
    }
}

// ============================================================
//  STATISTIK
// ============================================================
function getStatAdmisi($pdo_simrs, $tgl) {
    $s = ['total'=>0,'selesai'=>0,'dipanggil'=>0,'menunggu'=>0,'no_pertama'=>'-','no_terakhir'=>'-'];
    try {
        $q = $pdo_simrs->prepare("SELECT COUNT(*) FROM antrian_wira WHERE jenis='ADMISI' AND DATE(created_at)=?");
        $q->execute([$tgl]); $s['total'] = (int)$q->fetchColumn();

        $q = $pdo_simrs->prepare("SELECT COUNT(*) FROM antrian_wira WHERE jenis='ADMISI' AND DATE(created_at)=? AND status='Selesai'");
        $q->execute([$tgl]); $s['selesai'] = (int)$q->fetchColumn();

        $q = $pdo_simrs->prepare("SELECT COUNT(*) FROM antrian_wira WHERE jenis='ADMISI' AND DATE(created_at)=? AND status='Dipanggil'");
        $q->execute([$tgl]); $s['dipanggil'] = (int)$q->fetchColumn();

        $q = $pdo_simrs->prepare("SELECT COUNT(*) FROM antrian_wira WHERE jenis='ADMISI' AND DATE(created_at)=? AND status='Menunggu'");
        $q->execute([$tgl]); $s['menunggu'] = (int)$q->fetchColumn();

        $q = $pdo_simrs->prepare("SELECT nomor FROM antrian_wira WHERE jenis='ADMISI' AND DATE(created_at)=? ORDER BY id ASC LIMIT 1");
        $q->execute([$tgl]); $s['no_pertama'] = $q->fetchColumn() ?: '-';

        $q = $pdo_simrs->prepare("SELECT nomor FROM antrian_wira WHERE jenis='ADMISI' AND DATE(created_at)=? ORDER BY id DESC LIMIT 1");
        $q->execute([$tgl]); $s['no_terakhir'] = $q->fetchColumn() ?: '-';
    } catch (PDOException $e) {}
    return $s;
}

$stat_today     = getStatAdmisi($pdo_simrs, $today);
$stat_yesterday = getStatAdmisi($pdo_simrs, date('Y-m-d', strtotime('-1 day')));

$riwayat = [];
try {
    $riwayat = $pdo->query(
        "SELECT * FROM log_reset_antrian ORDER BY waktu_reset DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$page_title = 'Reset Antrian - MediFix';
$extra_css = '
.page-hero {
  background: linear-gradient(135deg, #1e3a5f 0%, #0f2744 50%, #1a3a6b 100%);
  color:#fff; border-radius:8px; padding:28px 30px;
  margin-bottom:24px; position:relative; overflow:hidden;
  box-shadow:0 4px 20px rgba(0,0,0,0.25);
}
.page-hero::before {
  content:""; position:absolute; top:-60px; right:-60px;
  width:200px; height:200px; border-radius:50%;
  background:rgba(255,255,255,0.04);
}
.page-hero h3 { margin:0 0 6px; font-size:22px; font-weight:700; }
.page-hero p  { margin:0; opacity:.78; font-size:13px; }
.page-hero .hero-icon {
  position:absolute; right:30px; top:50%; transform:translateY(-50%);
  font-size:80px; opacity:.07;
}
.warn-banner {
  background:#fff8ed; border:1px solid #fde68a;
  border-left:4px solid #f59e0b; border-radius:6px;
  padding:12px 16px; margin-bottom:20px;
  display:flex; align-items:flex-start; gap:10px;
}
.warn-banner i { color:#d97706; margin-top:3px; font-size:15px; flex-shrink:0; }
.warn-banner div { font-size:13px; color:#78350f; line-height:1.7; }
.stat-grid { display:grid; gap:12px; }
.stat-mini {
  background:#f8fafc; border:1px solid #e2e8f0;
  border-radius:8px; padding:14px 10px; text-align:center;
}
.stat-mini .val { font-size:26px; font-weight:800; color:#1e3a5f; line-height:1; }
.stat-mini .lbl { font-size:10px; color:#94a3b8; font-weight:600; text-transform:uppercase; letter-spacing:.5px; margin-top:5px; }
.stat-mini.ok     .val { color:#16a34a; }
.stat-mini.warn   .val { color:#d97706; }
.stat-mini.info   .val { color:#2563eb; }
.stat-mini.purple .val { color:#7c3aed; }
.stat-mini.muted  .val { color:#64748b; font-size:18px; }
.section-label {
  font-size:13px; font-weight:700; color:#1e3a5f;
  margin-bottom:10px; padding-bottom:7px;
  border-bottom:2px solid #e2e8f0;
}
.reset-card {
  background: linear-gradient(135deg, #667eea 0%, #4c5ec7 100%);
  border-radius:12px; padding:28px; color:#fff;
  position:relative; overflow:hidden;
  box-shadow:0 6px 24px rgba(76,94,199,0.35);
  height:100%;
}
.reset-card::after {
  content:""; position:absolute; bottom:-40px; right:-40px;
  width:140px; height:140px; border-radius:50%;
  background:rgba(255,255,255,0.08);
}
.reset-card .card-icon { font-size:40px; opacity:.8; margin-bottom:14px; }
.reset-card h4 { margin:0 0 8px; font-size:19px; font-weight:700; }
.reset-card p  { margin:0 0 18px; font-size:13px; opacity:.82; line-height:1.65; }
.date-group { margin-bottom:16px; position:relative; z-index:1; }
.date-group label {
  font-size:11px; font-weight:700; opacity:.85;
  letter-spacing:.5px; text-transform:uppercase;
  display:block; margin-bottom:6px;
}
.date-group input[type="date"] {
  width:100%; background:rgba(255,255,255,0.18);
  border:1.5px solid rgba(255,255,255,0.45); border-radius:7px;
  color:#fff; padding:9px 12px; font-size:14px; outline:none;
}
.date-group input[type="date"]::-webkit-calendar-picker-indicator { filter:invert(1); }
.count-preview {
  background:rgba(255,255,255,0.15); border-radius:7px;
  padding:10px 14px; margin-bottom:16px;
  font-size:13px; position:relative; z-index:1;
}
/* Tombol submit langsung — tidak pakai JS konfirmasi */
.btn-reset {
  display:block; width:100%;
  background:#dc2626; border:none; color:#fff;
  padding:12px 20px; border-radius:7px;
  font-weight:700; font-size:15px; cursor:pointer;
  transition:all .2s; letter-spacing:.3px;
  position:relative; z-index:1;
  text-align:center;
}
.btn-reset:hover {
  background:#b91c1c;
  transform:translateY(-2px); box-shadow:0 4px 14px rgba(0,0,0,0.25);
}
.tip-box {
  margin-top:16px; border-radius:7px; padding:11px 14px;
  font-size:12px; line-height:1.7; position:relative; z-index:1;
  background:rgba(255,255,255,0.12); border:1px solid rgba(255,255,255,0.22);
  color:#e0e7ff;
}
.table-log thead {
  background: linear-gradient(135deg, #1e3a5f, #0f2744); color:#fff;
}
.table-log thead th { padding:12px; font-size:12px; font-weight:600; border:none; }
.table-log tbody td { font-size:13px; vertical-align:middle; padding:11px 12px; }
.badge-log {
  background:#ddd6fe; color:#5b21b6;
  padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700;
}
';

include 'includes/header.php';
include 'includes/sidebar.php';
?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Reset Nomor Antrian</h1>
      <ol class="breadcrumb">
        <li><a href="dashboard.php"><i class="fa fa-dashboard"></i> Home</a></li>
        <li><a href="#">Setting</a></li>
        <li class="active">Reset Antrian</li>
      </ol>
    </section>

    <section class="content">

      <div class="page-hero">
        <i class="fa fa-refresh hero-icon"></i>
        <h3><i class="fa fa-refresh"></i> Reset Nomor Antrian Admisi</h3>
        <p>
          Hapus data antrian lama di tabel
          <code style="background:rgba(255,255,255,0.2);padding:1px 7px;border-radius:4px;">antrian_wira</code>
          agar nomor antrian mulai dari <strong>A001</strong> kembali esok hari.
        </p>
      </div>

      <?php if ($success): ?>
      <div class="alert alert-success alert-dismissible">
        <button type="button" class="close" data-dismiss="alert">&times;</button>
        <i class="fa fa-check-circle"></i> <?= $success ?>
      </div>
      <?php endif; ?>

      <?php if ($error): ?>
      <div class="alert alert-danger alert-dismissible">
        <button type="button" class="close" data-dismiss="alert">&times;</button>
        <i class="fa fa-exclamation-triangle"></i> <?= $error ?>
      </div>
      <?php endif; ?>

      <div class="warn-banner">
        <i class="fa fa-exclamation-triangle"></i>
        <div>
          <strong>Lakukan reset setelah jam operasional selesai.</strong>
          Reset menghapus semua data <code>antrian_wira</code> jenis ADMISI pada tanggal dipilih.
          Besok nomor antrian otomatis mulai dari <strong>A001</strong>.
          Data rekam medis &amp; registrasi SIMRS <strong>tidak terpengaruh</strong>.
        </div>
      </div>

      <div class="row">

        <!-- Statistik -->
        <div class="col-md-7">
          <div class="box">
            <div class="box-header with-border">
              <h3 class="box-title"><i class="fa fa-bar-chart"></i> Kondisi Antrian Admisi</h3>
            </div>
            <div class="box-body">

              <div class="section-label">
                <i class="fa fa-calendar-check-o"></i> Hari Ini &mdash; <?= date('d F Y') ?>
              </div>
              <div class="stat-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:22px;">
                <div class="stat-mini">
                  <div class="val"><?= $stat_today['total'] ?></div>
                  <div class="lbl">Total Antrian</div>
                </div>
                <div class="stat-mini ok">
                  <div class="val"><?= $stat_today['selesai'] ?></div>
                  <div class="lbl">Selesai</div>
                </div>
                <div class="stat-mini warn">
                  <div class="val"><?= $stat_today['menunggu'] ?></div>
                  <div class="lbl">Menunggu</div>
                </div>
                <div class="stat-mini info">
                  <div class="val"><?= $stat_today['dipanggil'] ?></div>
                  <div class="lbl">Dipanggil</div>
                </div>
                <div class="stat-mini muted">
                  <div class="val"><?= $stat_today['no_pertama'] ?></div>
                  <div class="lbl">No. Pertama</div>
                </div>
                <div class="stat-mini purple">
                  <div class="val"><?= $stat_today['no_terakhir'] ?></div>
                  <div class="lbl">No. Terakhir</div>
                </div>
              </div>

              <div class="section-label">
                <i class="fa fa-calendar-o"></i> Kemarin &mdash; <?= date('d F Y', strtotime('-1 day')) ?>
              </div>
              <div class="stat-grid" style="grid-template-columns:repeat(3,1fr);">
                <div class="stat-mini">
                  <div class="val"><?= $stat_yesterday['total'] ?></div>
                  <div class="lbl">Total Antrian</div>
                </div>
                <div class="stat-mini ok">
                  <div class="val"><?= $stat_yesterday['selesai'] ?></div>
                  <div class="lbl">Selesai</div>
                </div>
                <div class="stat-mini warn">
                  <div class="val"><?= $stat_yesterday['menunggu'] ?></div>
                  <div class="lbl">Menunggu</div>
                </div>
                <div class="stat-mini info">
                  <div class="val"><?= $stat_yesterday['dipanggil'] ?></div>
                  <div class="lbl">Dipanggil</div>
                </div>
                <div class="stat-mini muted">
                  <div class="val"><?= $stat_yesterday['no_pertama'] ?></div>
                  <div class="lbl">No. Pertama</div>
                </div>
                <div class="stat-mini purple">
                  <div class="val"><?= $stat_yesterday['no_terakhir'] ?></div>
                  <div class="lbl">No. Terakhir</div>
                </div>
              </div>

            </div>
          </div>
        </div>

        <!-- Form Reset — submit LANGSUNG, tidak pakai JS -->
        <div class="col-md-5">
          <div class="reset-card">
            <div class="card-icon"><i class="fa fa-clipboard"></i></div>
            <h4>Reset Antrian Admisi</h4>
            <p>
              Pilih tanggal, lalu klik <strong>Reset</strong>.
              Besok nomor antrian mulai dari <strong>A001</strong> otomatis.
            </p>

            <form method="POST" action="reset_antri.php"
                  onsubmit="return confirm('Reset antrian admisi pada tanggal ' + document.getElementById('tgl_reset').value + '?\n\nAksi ini tidak dapat dibatalkan.');">
              <input type="hidden" name="action" value="reset_admisi">

              <div class="date-group">
                <label><i class="fa fa-calendar"></i> Tanggal yang Direset</label>
                <input type="date" name="tgl_reset" id="tgl_reset"
                       value="<?= $today ?>" max="<?= $today ?>">
              </div>

              <div class="count-preview">
                <i class="fa fa-info-circle"></i>
                Hari ini ada <strong><?= $stat_today['total'] ?></strong> antrian
                (<?= $stat_today['menunggu'] ?> menunggu &middot; <?= $stat_today['selesai'] ?> selesai)
              </div>

              <button type="submit" class="btn-reset">
                <i class="fa fa-trash"></i>&nbsp; Reset Sekarang
              </button>
            </form>

            <div class="tip-box">
              <i class="fa fa-lightbulb-o"></i>
              <strong>Tips:</strong> Reset malam hari setelah pelayanan selesai,
              atau pagi sebelum pasien pertama ambil antrian.
            </div>
          </div>
        </div>

      </div>

      <!-- Riwayat -->
      <div class="box">
        <div class="box-header with-border">
          <h3 class="box-title"><i class="fa fa-history"></i> Riwayat Reset (10 Terakhir)</h3>
        </div>
        <div class="box-body" style="padding:0;">
          <?php if (empty($riwayat)): ?>
          <div class="callout callout-info" style="margin:15px;">
            <h4><i class="fa fa-info"></i> Belum Ada Riwayat</h4>
            <p>Belum ada aktivitas reset yang tercatat.</p>
          </div>
          <?php else: ?>
          <table class="table table-bordered table-striped table-log" style="margin:0;">
            <thead>
              <tr>
                <th width="40">#</th>
                <th>Jenis</th>
                <th>Tanggal Antrian</th>
                <th>Jumlah Dihapus</th>
                <th>Direset Oleh</th>
                <th>Waktu Eksekusi</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($riwayat as $i => $r): ?>
              <tr>
                <td class="text-center" style="color:#94a3b8;font-weight:600;"><?= $i + 1 ?></td>
                <td><span class="badge-log"><i class="fa fa-clipboard"></i> <?= htmlspecialchars($r['jenis']) ?></span></td>
                <td><?= date('d F Y', strtotime($r['tgl_antrian'])) ?></td>
                <td><span style="font-weight:700;color:#dc2626;"><?= (int)($r['jumlah_dihapus'] ?? 0) ?></span> antrian</td>
                <td><i class="fa fa-user" style="color:#94a3b8;"></i> <?= htmlspecialchars($r['direset_oleh']) ?></td>
                <td><?= date('d/m/Y H:i:s', strtotime($r['waktu_reset'])) ?> WIB</td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </div>

    </section>
  </div>

<?php include 'includes/footer.php'; ?>