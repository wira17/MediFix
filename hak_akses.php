<?php
session_start();
include 'koneksi.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$nama = $_SESSION['nama'] ?? 'Pengguna';

// ── Icon mapping per kode menu ──────────────────────────────────────────────
$menu_icons = [
    'dashboard'              => 'fa-dashboard',
    'anjungan'               => 'fa-building',
    'admisi'                 => 'fa-user-plus',
    'poliklinik'             => 'fa-hospital-o',
    'farmasi'                => 'fa-medkit',
    'satusehat'              => 'fa-heartbeat',
    'casemix'                => 'fa-bar-chart',
    'bridging'               => 'fa-exchange',
    'pengguna'               => 'fa-users',
    'setting'                => 'fa-cog',
    'service_request'        => 'fa-send',
    'encounter'              => 'fa-stethoscope',
    'condition'              => 'fa-heartbeat',
    'procedure'              => 'fa-scissors',
    'medication'             => 'fa-pills',
    'medication_dispense'    => 'fa-flask',
    'medication_request'     => 'fa-file-medical',
    'immunisasi'             => 'fa-syringe',
    // ── Tambahan baru ──
    'diagnosticreport'       => 'fa-file-text-o',
    'upload_dicom'           => 'fa-cloud-upload',
];

// ── Ambil data ───────────────────────────────────────────────────────────────
$menus = $pdo->query("SELECT * FROM menu_list ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$users = $pdo->query("SELECT * FROM users ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

$selectedUser = $_GET['user_id'] ?? $users[0]['id'];
$success = $error = "";

// ── Simpan akses ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = $_POST['user_id'];
    try {
        $pdo->prepare("DELETE FROM hak_akses WHERE user_id=?")->execute([$user_id]);
        foreach ($menus as $m) {
            $izin = isset($_POST['akses'][$m['kode']]) ? 1 : 0;
            $pdo->prepare("INSERT INTO hak_akses (user_id, menu, izin) VALUES (?, ?, ?)")
                ->execute([$user_id, $m['kode'], $izin]);
        }
        // Sync session jika edit dirinya sendiri
        if ($_SESSION['user_id'] == $user_id) {
            $_SESSION['akses'] = [];
            $reload = $pdo->prepare("SELECT menu, izin FROM hak_akses WHERE user_id=?");
            $reload->execute([$user_id]);
            foreach ($reload->fetchAll(PDO::FETCH_ASSOC) as $row)
                $_SESSION['akses'][$row['menu']] = $row['izin'];
        }
        $success = "Hak akses berhasil diperbarui!";
    } catch (PDOException $e) {
        $error = "Gagal menyimpan: " . $e->getMessage();
    }
}

// ── Load akses user terpilih ─────────────────────────────────────────────────
$currentAccess = [];
$load = $pdo->prepare("SELECT menu, izin FROM hak_akses WHERE user_id=?");
$load->execute([$selectedUser]);
foreach ($load->fetchAll(PDO::FETCH_ASSOC) as $r)
    $currentAccess[$r['menu']] = $r['izin'];

// ── Nama user terpilih ───────────────────────────────────────────────────────
$selectedUserName = '';
foreach ($users as $u)
    if ($u['id'] == $selectedUser) { $selectedUserName = $u['nama']; break; }

// ── Kelompokkan menu berdasarkan grup ─────────────────────────────────────────
$menu_groups = [
    'Sistem'       => ['dashboard', 'anjungan'],
    'Pelayanan'    => ['admisi', 'poliklinik', 'farmasi'],
    'Satu Sehat'   => [
        'satusehat',
        'service_request',
        'diagnosticreport',   // ← baru
        'upload_dicom',       // ← baru
        'encounter',
        'condition',
        'procedure',
        'medication',
        'medication_dispense',
        'medication_request',
        'immunisasi',
    ],
    'BPJS'         => ['casemix', 'bridging'],
    'Administrasi' => ['pengguna', 'setting'],
];

// Buat lookup kode → menu row
$menuByKode = [];
foreach ($menus as $m) $menuByKode[$m['kode']] = $m;

// Kelompokkan yang ada di DB
$grouped   = [];
$ungrouped = [];
foreach ($menus as $m) {
    $found = false;
    foreach ($menu_groups as $grpName => $codes) {
        if (in_array($m['kode'], $codes)) {
            $grouped[$grpName][] = $m;
            $found = true; break;
        }
    }
    if (!$found) $ungrouped[] = $m;
}
if ($ungrouped) $grouped['Lainnya'] = $ungrouped;

// ── Page setup ───────────────────────────────────────────────────────────────
$page_title = 'Manajemen Hak Akses - MediFix';
$extra_css  = '
.welcome-box {
  background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
  color: white; border-radius: 5px; padding: 25px; margin-bottom: 20px;
  box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}
.welcome-box h3 { margin: 0 0 10px; font-size: 24px; font-weight: 700; }
.welcome-box p  { margin: 0; opacity: .9; font-size: 14px; }

.user-list-card {
  background: white; border-radius: 5px; padding: 15px;
  box-shadow: 0 1px 3px rgba(0,0,0,.12);
  max-height: calc(100vh - 300px); overflow-y: auto;
}
.user-list-card::-webkit-scrollbar { width:6px; }
.user-list-card::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:10px; }
.user-list-title { font-size:13px; font-weight:700; color:#2d3748; margin-bottom:10px; display:flex; align-items:center; gap:6px; }
.user-list-title i { color:#f59e0b; }
.user-item {
  padding:10px 12px; border-radius:6px; margin-bottom:6px; cursor:pointer;
  transition:all .2s; display:flex; align-items:center; gap:8px;
  font-size:13px; font-weight:500; color:#2d3748; text-decoration:none;
  border:1px solid transparent;
}
.user-item:hover { background:#fef3c7; color:#2d3748; border-color:#fbbf24; }
.user-item.active { background:linear-gradient(135deg,#f59e0b,#d97706); color:white; font-weight:600; border-color:#f59e0b; }
.user-badge-label {
  font-size:9px; background:rgba(245,158,11,.2); color:#f59e0b;
  padding:2px 6px; border-radius:3px; margin-left:auto; font-weight:700;
}
.user-item.active .user-badge-label { background:rgba(255,255,255,.2); color:white; }

.info-bar {
  background:#f8fafc; padding:12px 16px; border-radius:6px; margin-bottom:16px;
  display:flex; align-items:center; justify-content:space-between;
  border-left:4px solid #f59e0b;
}
.info-bar-left { display:flex; align-items:center; gap:8px; font-size:13px; font-weight:600; color:#2d3748; }
.info-bar-left i { color:#f59e0b; font-size:16px; }
.info-bar-user { color:#667eea; font-weight:700; }

.action-buttons { display:flex; gap:8px; margin-bottom:16px; flex-wrap:wrap; }
.btn-action-sm {
  padding:8px 16px; border-radius:6px; font-size:12px; font-weight:600;
  border:none; transition:all .2s; display:inline-flex; align-items:center; gap:6px; cursor:pointer;
}
.btn-check-all   { background:linear-gradient(135deg,#10b981,#059669); color:white; }
.btn-uncheck-all { background:linear-gradient(135deg,#ef4444,#dc2626); color:white; }
.btn-action-sm:hover { transform:translateY(-1px); box-shadow:0 2px 8px rgba(0,0,0,.15); }

.group-header td {
  background:linear-gradient(90deg,#f59e0b,#d97706) !important;
  color:white !important; font-size:11px; font-weight:700;
  text-transform:uppercase; letter-spacing:.06em;
  padding:8px 12px !important; border:none !important;
}
.group-header.satusehat td { background:linear-gradient(90deg,#8b5cf6,#6d28d9) !important; }
.group-header.pelayanan td { background:linear-gradient(90deg,#0ea5a0,#075e5b) !important; }
.group-header.bpjs td      { background:linear-gradient(90deg,#3b82f6,#1d4ed8) !important; }
.group-header.admin td     { background:linear-gradient(90deg,#64748b,#475569) !important; }

.table-access { margin-bottom:20px; }
.table-access thead { background:linear-gradient(135deg,#f59e0b,#d97706); color:white; }
.table-access thead th { font-weight:600; font-size:12px; padding:12px; border:none !important; }
.table-access tbody td { padding:11px 12px; vertical-align:middle; border-bottom:1px solid #f1f5f9 !important; }
.table-access tbody tr:hover td { background:#fef3c7; }
.table-access tbody tr:last-child td { border-bottom:none !important; }

.menu-name { display:flex; align-items:center; gap:10px; font-weight:600; color:#2d3748; font-size:13px; }
.menu-icon {
  width:30px; height:30px; border-radius:7px;
  display:flex; align-items:center; justify-content:center;
  color:white; font-size:13px; flex-shrink:0;
}
.menu-icon.default   { background:linear-gradient(135deg,#f59e0b,#d97706); }
.menu-icon.satusehat { background:linear-gradient(135deg,#8b5cf6,#6d28d9); }
.menu-icon.pelayanan { background:linear-gradient(135deg,#0ea5a0,#075e5b); }
.menu-icon.bpjs      { background:linear-gradient(135deg,#3b82f6,#1d4ed8); }
.menu-icon.admin     { background:linear-gradient(135deg,#64748b,#475569); }

.form-check-input { width:20px; height:20px; border:2px solid #cbd5e1; cursor:pointer; }
.form-check-input:checked { background-color:#10b981; border-color:#10b981; }

.group-toggle { cursor:pointer; display:flex; align-items:center; gap:6px; }
.group-toggle input { width:16px; height:16px; }

.btn-save-access {
  background:linear-gradient(135deg,#667eea,#764ba2); border:none; color:white;
  padding:12px 20px; border-radius:5px; font-weight:600; transition:all .3s; width:100%;
}
.btn-save-access:hover { transform:translateY(-2px); box-shadow:0 4px 12px rgba(102,126,234,.4); color:white; }

.access-counter {
  background:rgba(255,255,255,.2); padding:3px 10px; border-radius:20px;
  font-size:11px; font-weight:700; margin-left:auto;
}
';

$extra_js = '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>';

include 'includes/header.php';
include 'includes/sidebar.php';

function grpClass($grpName) {
    $map = ['Satu Sehat'=>'satusehat','Pelayanan'=>'pelayanan','BPJS'=>'bpjs','Administrasi'=>'admin'];
    return $map[$grpName] ?? 'default';
}
?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Manajemen Hak Akses</h1>
      <ol class="breadcrumb">
        <li><a href="dashboard.php"><i class="fa fa-dashboard"></i> Home</a></li>
        <li><a href="#">Setting</a></li>
        <li class="active">Hak Akses</li>
      </ol>
    </section>

    <section class="content">

      <div class="welcome-box">
        <h3><i class="fa fa-shield"></i> Manajemen Hak Akses Pengguna</h3>
        <p>Kelola hak akses menu sistem untuk setiap pengguna</p>
      </div>

      <div class="row">

        <!-- ── KIRI: Daftar User ── -->
        <div class="col-md-3">
          <div class="box">
            <div class="box-body" style="padding:10px;">
              <div class="user-list-card">
                <div class="user-list-title"><i class="fa fa-users"></i> Pilih Pengguna</div>
                <?php foreach ($users as $u): ?>
                <a href="?user_id=<?= $u['id'] ?>" class="user-item <?= ($selectedUser==$u['id'])?'active':'' ?>">
                  <i class="fa fa-user-circle"></i>
                  <span><?= htmlspecialchars($u['nama']) ?></span>
                  <?php if ($u['id']==$_SESSION['user_id']): ?>
                  <span class="user-badge-label">ANDA</span>
                  <?php endif; ?>
                </a>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>

        <!-- ── KANAN: Tabel Hak Akses ── -->
        <div class="col-md-9">
          <div class="box">
            <div class="box-header with-border">
              <h3 class="box-title"><i class="fa fa-key"></i> Pengaturan Hak Akses Menu</h3>
            </div>
            <div class="box-body">

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

              <form method="POST" id="formAkses">
                <input type="hidden" name="user_id" value="<?= $selectedUser ?>">

                <div class="info-bar">
                  <div class="info-bar-left">
                    <i class="fa fa-user-circle"></i>
                    Mengatur hak akses untuk:
                    <span class="info-bar-user"><?= htmlspecialchars($selectedUserName) ?></span>
                  </div>
                  <span style="font-size:11px;color:#94a3b8;" id="counterLabel">0 / <?= count($menus) ?> diaktifkan</span>
                </div>

                <div class="action-buttons">
                  <button type="button" onclick="checkAll()" class="btn-action-sm btn-check-all">
                    <i class="fa fa-check-square"></i> Centang Semua
                  </button>
                  <button type="button" onclick="uncheckAll()" class="btn-action-sm btn-uncheck-all">
                    <i class="fa fa-square-o"></i> Kosongkan Semua
                  </button>
                </div>

                <div class="table-responsive">
                  <table class="table table-bordered table-access">
                    <thead>
                      <tr>
                        <th width="46" class="text-center">#</th>
                        <th>Nama Menu</th>
                        <th width="160" class="text-center">Kode</th>
                        <th width="110" class="text-center">Hak Akses</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php
                      $no = 1;
                      foreach ($grouped as $grpName => $grpMenus):
                          $gc = grpClass($grpName);
                          $grpKodes  = array_column($grpMenus, 'kode');
                          $grpActive = count(array_filter($grpKodes, fn($k)=>!empty($currentAccess[$k])));
                      ?>
                      <tr class="group-header <?= $gc ?>">
                        <td colspan="4">
                          <div style="display:flex;align-items:center;gap:8px;">
                            <label class="group-toggle" title="Centang/kosongkan grup ini">
                              <input type="checkbox" class="grpCheck" data-grp="<?= $gc ?>"
                                     onchange="toggleGroup('<?= $gc ?>',this.checked)"
                                     <?= $grpActive===count($grpMenus)?'checked':'' ?>>
                            </label>
                            <i class="fa <?= $gc==='satusehat'?'fa-heartbeat':($gc==='pelayanan'?'fa-h-square':($gc==='bpjs'?'fa-exchange':($gc==='admin'?'fa-cog':'fa-th'))) ?>"></i>
                            <?= strtoupper($grpName) ?>
                            <span class="access-counter" id="cnt-<?= $gc ?>"><?= $grpActive ?>/<?= count($grpMenus) ?></span>
                          </div>
                        </td>
                      </tr>

                      <?php foreach ($grpMenus as $m):
                          $icon    = $menu_icons[$m['kode']] ?? 'fa-th-large';
                          $checked = !empty($currentAccess[$m['kode']]);
                      ?>
                      <tr>
                        <td class="text-center" style="color:#94a3b8;font-weight:600;font-size:12px;"><?= $no++ ?></td>
                        <td>
                          <div class="menu-name">
                            <div class="menu-icon <?= $gc ?>">
                              <i class="fa <?= $icon ?>"></i>
                            </div>
                            <?= htmlspecialchars($m['nama_menu']) ?>
                            <?php if (in_array($m['kode'], ['diagnosticreport','upload_dicom'])): ?>
                            <span style="background:#dff0d8;color:#3c763d;border:1px solid #c3e6cb;padding:1px 7px;border-radius:8px;font-size:10px;font-weight:700;margin-left:4px">Baru</span>
                            <?php endif; ?>
                          </div>
                        </td>
                        <td class="text-center">
                          <code style="font-size:10px;background:#f1f5f9;padding:2px 6px;border-radius:4px;color:#64748b;">
                            <?= htmlspecialchars($m['kode']) ?>
                          </code>
                        </td>
                        <td class="text-center">
                          <input type="checkbox" class="form-check-input menuCheck grp-<?= $gc ?>"
                                 name="akses[<?= $m['kode'] ?>]"
                                 <?= $checked?'checked':'' ?>
                                 onchange="updateCounter()">
                        </td>
                      </tr>
                      <?php endforeach; ?>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>

                <button type="submit" class="btn btn-save-access">
                  <i class="fa fa-save"></i> Simpan Hak Akses
                </button>
              </form>

            </div>
          </div>
        </div>

      </div>
    </section>
  </div>

<?php if (!empty($success)): ?>
<script>
Swal.fire({ icon:'success', title:'Berhasil!', text:'<?= $success ?>', timer:2000, showConfirmButton:false });
</script>
<?php endif; ?>
<?php if (!empty($error)): ?>
<script>
Swal.fire({ icon:'error', title:'Gagal!', text:'<?= addslashes($error) ?>', confirmButtonText:'OK' });
</script>
<?php endif; ?>

<script>
function updateCounter() {
    const all     = document.querySelectorAll('.menuCheck');
    const checked = document.querySelectorAll('.menuCheck:checked');
    const lbl     = document.getElementById('counterLabel');
    if (lbl) lbl.textContent = checked.length + ' / ' + all.length + ' diaktifkan';
    document.querySelectorAll('.grpCheck').forEach(gc => {
        const grp  = gc.dataset.grp;
        const items = document.querySelectorAll(`.grp-${grp}`);
        const on    = document.querySelectorAll(`.grp-${grp}:checked`);
        const cnt   = document.getElementById('cnt-' + grp);
        if (cnt) cnt.textContent = on.length + '/' + items.length;
        gc.indeterminate = (on.length > 0 && on.length < items.length);
        gc.checked       = (on.length === items.length && items.length > 0);
    });
}
function checkAll()   { document.querySelectorAll('.menuCheck').forEach(c=>c.checked=true);  updateCounter(); }
function uncheckAll() { document.querySelectorAll('.menuCheck').forEach(c=>c.checked=false); updateCounter(); }
function toggleGroup(grp, state) {
    document.querySelectorAll(`.grp-${grp}`).forEach(c => c.checked = state);
    updateCounter();
}
updateCounter();
</script>

<?php include 'includes/footer.php'; ?>