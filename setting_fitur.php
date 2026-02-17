<?php
session_start();
include 'koneksi.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$nama = $_SESSION['nama'] ?? 'Pengguna';

$success = $error = "";

// ==== Simpan Data ====
if(isset($_POST['save'])){
    try {
        foreach($_POST['fitur'] as $kode => $status){
            $stmt = $pdo->prepare("UPDATE feature_control SET status=? WHERE kode_fitur=?");
            $stmt->execute([$status, $kode]);
        }
        $success = "✔ Pengaturan fitur berhasil diperbarui!";
    } catch (PDOException $e) {
        $error = "⚠ Gagal menyimpan: " . $e->getMessage();
    }
}

$data = $pdo->query("SELECT * FROM feature_control ORDER BY id")->fetchAll();

// Map ikon dan warna per kode fitur agar sesuai tampilan di anjungan
$fiturMeta = [
    'admisi'        => ['icon' => 'fa-clipboard',        'gradient' => 'linear-gradient(135deg, #667eea, #764ba2)', 'label' => 'Antri Admisi'],
    'daftar_poli'   => ['icon' => 'fa-stethoscope',      'gradient' => 'linear-gradient(135deg, #f093fb, #f5576c)', 'label' => 'Daftar Poli'],
    'cek_bpjs'      => ['icon' => 'fa-id-card',          'gradient' => 'linear-gradient(135deg, #4facfe, #00f2fe)', 'label' => 'Cek Kepesertaan BPJS'],
    'checkin'       => ['icon' => 'fa-qrcode',           'gradient' => 'linear-gradient(135deg, #43e97b, #38f9d7)', 'label' => 'Check-in JKN'],
    'frista'        => ['icon' => 'fa-heart-o',          'gradient' => 'linear-gradient(135deg, #fa709a, #fee140)', 'label' => 'Frista'],
    'cari_ranap'    => ['icon' => 'fa-search',           'gradient' => 'linear-gradient(135deg, #4c8bff, #1a56db)', 'label' => 'Cari Pasien Rawat Inap'],
    'rujukan'       => ['icon' => 'fa-exchange',         'gradient' => 'linear-gradient(135deg, #a8edea, #3bbfa1)', 'label' => 'SEP Rujukan FKTP'],
    'sep_poli'      => ['icon' => 'fa-calendar-check-o', 'gradient' => 'linear-gradient(135deg, #ff9a9e, #e05c7a)', 'label' => 'SEP Poli'],
    'kontrol_rajal' => ['icon' => 'fa-file-text',        'gradient' => 'linear-gradient(135deg, #f59e0b, #d97706)', 'label' => 'Surat Kontrol Rajal'],
    'kontrol_ranap' => ['icon' => 'fa-hospital-o',       'gradient' => 'linear-gradient(135deg, #14b8a6, #0d9488)', 'label' => 'Surat Kontrol Ranap'],
    'antri_farmasi' => ['icon' => 'fa-medkit',           'gradient' => 'linear-gradient(135deg, #10b981, #059669)', 'label' => 'Antri Farmasi'],
];

// Set page title dan extra CSS
$page_title = 'Setting Fitur Anjungan - MediFix';
$extra_css = '
/* Welcome Box */
.welcome-box {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
  border-radius: 5px;
  padding: 25px;
  margin-bottom: 20px;
  box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}
.welcome-box h3 {
  margin: 0 0 10px 0;
  font-size: 24px;
  font-weight: 700;
}
.welcome-box p {
  margin: 0;
  opacity: 0.9;
  font-size: 14px;
}

/* Table Custom */
.table-features {
  margin-bottom: 0;
}
.table-features thead {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
}
.table-features thead th {
  font-weight: 600;
  border: none;
  padding: 14px 12px;
  font-size: 13px;
}
.table-features tbody td {
  vertical-align: middle;
  padding: 13px 12px;
}
.table-features tbody tr:hover {
  background-color: #f5f3ff;
}
.table-features tbody tr {
  transition: background 0.2s ease;
}

/* Feature Name */
.feature-name {
  display: flex;
  align-items: center;
  gap: 12px;
  font-weight: 600;
  color: #2d3748;
  font-size: 14px;
}
.feature-kode {
  font-size: 11px;
  color: #94a3b8;
  font-weight: 500;
  font-family: monospace;
  background: #f1f5f9;
  padding: 2px 7px;
  border-radius: 4px;
  margin-left: 4px;
}
.feature-icon {
  width: 38px;
  height: 38px;
  border-radius: 9px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: white;
  font-size: 16px;
  flex-shrink: 0;
  box-shadow: 0 3px 8px rgba(0,0,0,0.15);
}

/* Badge status */
.badge-aktif {
  background: #dcfce7;
  color: #16a34a;
  padding: 3px 10px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 700;
}
.badge-nonaktif {
  background: #fee2e2;
  color: #dc2626;
  padding: 3px 10px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 700;
}

/* Switch Toggle */
.switch {
  position: relative;
  display: inline-block;
  width: 54px;
  height: 26px;
}
.switch input {
  opacity: 0;
  width: 0;
  height: 0;
}
.slider {
  position: absolute;
  cursor: pointer;
  top: 0; left: 0; right: 0; bottom: 0;
  background-color: #cbd5e1;
  transition: .35s;
  border-radius: 26px;
}
.slider:before {
  position: absolute;
  content: "";
  height: 20px;
  width: 20px;
  left: 3px;
  bottom: 3px;
  background-color: white;
  transition: .35s;
  border-radius: 50%;
  box-shadow: 0 2px 5px rgba(0,0,0,0.2);
}
input:checked + .slider {
  background: linear-gradient(135deg, #10b981, #059669);
}
input:checked + .slider:before {
  transform: translateX(28px);
}

/* Buttons */
.btn-save-custom {
  background: linear-gradient(135deg, #10b981, #059669);
  border: none;
  color: white;
  padding: 11px 28px;
  border-radius: 6px;
  font-weight: 600;
  font-size: 14px;
  transition: all 0.3s ease;
}
.btn-save-custom:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 14px rgba(16, 185, 129, 0.4);
  color: white;
}
.btn-all-on {
  background: linear-gradient(135deg, #3b82f6, #1d4ed8);
  border: none;
  color: white;
  padding: 8px 18px;
  border-radius: 6px;
  font-weight: 600;
  font-size: 12px;
  transition: all 0.3s ease;
  cursor: pointer;
}
.btn-all-on:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(59,130,246,0.4);
}
.btn-all-off {
  background: linear-gradient(135deg, #ef4444, #dc2626);
  border: none;
  color: white;
  padding: 8px 18px;
  border-radius: 6px;
  font-weight: 600;
  font-size: 12px;
  transition: all 0.3s ease;
  cursor: pointer;
}
.btn-all-off:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(239,68,68,0.4);
}

/* Info bar */
.info-count {
  display: flex;
  gap: 16px;
  margin-bottom: 18px;
  flex-wrap: wrap;
}
.count-card {
  background: #f8fafc;
  border-radius: 8px;
  padding: 10px 18px;
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  font-weight: 600;
  color: #475569;
  border: 1px solid #e2e8f0;
}
.count-card .dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
}
.dot-aktif   { background: #10b981; }
.dot-nonaktif { background: #ef4444; }
';

$extra_js = '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>';

include 'includes/header.php';
include 'includes/sidebar.php';
?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Setting Fitur Anjungan</h1>
      <ol class="breadcrumb">
        <li><a href="dashboard.php"><i class="fa fa-dashboard"></i> Home</a></li>
        <li><a href="#">Setting</a></li>
        <li class="active">Fitur Anjungan</li>
      </ol>
    </section>

    <section class="content">

      <!-- Welcome Box -->
     

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

      <div class="row">
        <div class="col-md-12">
          <div class="box">
            <div class="box-header with-border" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
              <h3 class="box-title"><i class="fa fa-gear"></i> Daftar Fitur Anjungan</h3>
              <div style="display:flex; gap:8px;">
                <button type="button" onclick="setAll(1)" class="btn-all-on">
                  <i class="fa fa-toggle-on"></i> Aktifkan Semua
                </button>
                <button type="button" onclick="setAll(0)" class="btn-all-off">
                  <i class="fa fa-toggle-off"></i> Nonaktifkan Semua
                </button>
              </div>
            </div>
            <div class="box-body">

              <?php
                $jumlahAktif = count(array_filter($data, fn($r) => $r['status'] == 1));
                $jumlahNonaktif = count($data) - $jumlahAktif;
              ?>
              <!-- Counter info -->
              <div class="info-count">
                <div class="count-card">
                  <span class="dot dot-aktif"></span>
                  <span><?= $jumlahAktif ?> Fitur Aktif</span>
                </div>
                <div class="count-card">
                  <span class="dot dot-nonaktif"></span>
                  <span><?= $jumlahNonaktif ?> Fitur Nonaktif</span>
                </div>
                <div class="count-card">
                  <i class="fa fa-list" style="color:#667eea;"></i>
                  <span><?= count($data) ?> Total Fitur</span>
                </div>
              </div>

              <form method="POST" id="formFitur">
                <div class="table-responsive">
                  <table class="table table-bordered table-striped table-features">
                    <thead>
                      <tr>
                        <th width="50" class="text-center">#</th>
                        <th>Nama Fitur</th>
                        <th width="140" class="text-center">Status</th>
                        <th width="110" class="text-center">Keterangan</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php 
                      $no = 1;
                      foreach($data as $row):
                        $kode = $row['kode_fitur'];
                        $meta = $fiturMeta[$kode] ?? [
                            'icon'     => 'fa-gear',
                            'gradient' => 'linear-gradient(135deg, #94a3b8, #64748b)',
                            'label'    => $row['nama_fitur'],
                        ];
                        $isAktif = $row['status'] == 1;
                      ?>
                      <tr>
                        <td class="text-center" style="color:#94a3b8; font-weight:600;"><?= $no++ ?></td>
                        <td>
                          <div class="feature-name">
                            <div class="feature-icon" style="background: <?= $meta['gradient'] ?>;">
                              <i class="fa <?= $meta['icon'] ?>"></i>
                            </div>
                            <div>
                              <span><?= htmlspecialchars($meta['label']) ?></span>
                              <span class="feature-kode"><?= htmlspecialchars($kode) ?></span>
                            </div>
                          </div>
                        </td>
                        <td class="text-center">
                          <!-- Hidden value 0, di-override oleh checkbox jika dicentang -->
                          <input type="hidden" name="fitur[<?= $kode ?>]" value="0">
                          <label class="switch" title="<?= $isAktif ? 'Klik untuk nonaktifkan' : 'Klik untuk aktifkan' ?>">
                            <input type="checkbox"
                                   class="toggle-switch"
                                   name="fitur[<?= $kode ?>]"
                                   value="1"
                                   <?= $isAktif ? 'checked' : '' ?>>
                            <span class="slider"></span>
                          </label>
                        </td>
                        <td class="text-center">
                          <span class="badge-status <?= $isAktif ? 'badge-aktif' : 'badge-nonaktif' ?>">
                            <?= $isAktif ? '✔ Aktif' : '✖ Nonaktif' ?>
                          </span>
                        </td>
                      </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>

                <div style="margin-top:20px; display:flex; gap:10px; justify-content:flex-end;">
                  <button type="submit" name="save" class="btn btn-primary btn-save-custom">
                    <i class="fa fa-save"></i> Simpan Perubahan
                  </button>
                </div>

              </form>
            </div>
          </div>
        </div>
      </div>

      <!-- Info SQL untuk fitur yang belum ada di DB -->
      <div class="row">
        <div class="col-md-12">
          <div class="box box-warning collapsed-box">
            <div class="box-header with-border" style="cursor:pointer;" data-widget="collapse">
              <h3 class="box-title"><i class="fa fa-database"></i> Panduan: Pastikan semua fitur terdaftar di database</h3>
              <div class="box-tools pull-right">
                <button type="button" class="btn btn-box-tool" data-widget="collapse">
                  <i class="fa fa-plus"></i>
                </button>
              </div>
            </div>
            <div class="box-body" style="display:none;">
              <p style="font-size:13px; color:#475569; margin-bottom:10px;">
                Jalankan SQL berikut jika fitur <code>kontrol_rajal</code>, <code>kontrol_ranap</code>, atau <code>antri_farmasi</code> belum ada di tabel <code>feature_control</code>:
              </p>
              <pre style="background:#1e293b; color:#e2e8f0; padding:16px; border-radius:8px; font-size:13px; overflow-x:auto;">INSERT IGNORE INTO feature_control (kode_fitur, nama_fitur, status) VALUES
('kontrol_rajal', 'Surat Kontrol Rajal', 0),
('kontrol_ranap', 'Surat Kontrol Ranap', 0),
('antri_farmasi', 'Antri Farmasi', 0);</pre>
            </div>
          </div>
        </div>
      </div>

    </section>
  </div>

<?php if (!empty($success)): ?>
<script>
Swal.fire({
    icon: 'success',
    title: 'Berhasil!',
    text: '<?= $success ?>',
    timer: 2000,
    showConfirmButton: false
});
</script>
<?php endif; ?>

<?php if (!empty($error)): ?>
<script>
Swal.fire({
    icon: 'error',
    title: 'Gagal!',
    text: '<?= $error ?>',
    confirmButtonText: 'OK'
});
</script>
<?php endif; ?>

<script>
// Update badge keterangan realtime saat toggle diubah
document.querySelectorAll('.toggle-switch').forEach(function(toggle) {
    toggle.addEventListener('change', function() {
        const badgeCell = this.closest('tr').querySelector('.badge-status');
        if (this.checked) {
            badgeCell.className = 'badge-status badge-aktif';
            badgeCell.textContent = '✔ Aktif';
        } else {
            badgeCell.className = 'badge-status badge-nonaktif';
            badgeCell.textContent = '✖ Nonaktif';
        }
    });
});

// Aktifkan / nonaktifkan semua toggle
function setAll(val) {
    document.querySelectorAll('.toggle-switch').forEach(function(toggle) {
        toggle.checked = val == 1;
        toggle.dispatchEvent(new Event('change'));
    });
}
</script>

<?php include 'includes/footer.php'; ?>