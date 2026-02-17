<?php
session_start();
include 'koneksi.php';  // koneksi ke DB anjungan (tabel users)
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$nama_login = $_SESSION['nama'] ?? 'Admin';
$msg        = '';
$msg_type   = '';

// ================================================================
// PROSES FORM
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── TAMBAH ──────────────────────────────────────────────────
    if ($action === 'tambah') {
        $nik      = trim($_POST['nik']   ?? '');
        $nama     = trim($_POST['nama']  ?? '');
        $email    = trim($_POST['email'] ?? '');
        $hp       = trim($_POST['hp']    ?? '');
        $role     = trim($_POST['role']  ?? 'Operator');
        $password = trim($_POST['password'] ?? '');

        if (!$nik || !$nama || !$email || !$password) {
            $msg = 'NIK, Nama, Email, dan Password wajib diisi.';
            $msg_type = 'danger';
        } else {
            // Cek NIK/email duplikat
            $cek = $pdo->prepare("SELECT id FROM users WHERE nik = ? OR email = ?");
            $cek->execute([$nik, $email]);
            if ($cek->fetch()) {
                $msg = 'NIK atau Email sudah terdaftar.';
                $msg_type = 'warning';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $ins  = $pdo->prepare(
                    "INSERT INTO users (nik, nama, email, password, hp, role, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())"
                );
                $ins->execute([$nik, $nama, $email, $hash, $hp, $role]);
                $msg = 'Pengguna <strong>' . htmlspecialchars($nama) . '</strong> berhasil ditambahkan.';
                $msg_type = 'success';
            }
        }
    }

    // ── EDIT ─────────────────────────────────────────────────────
    elseif ($action === 'edit') {
        $id    = intval($_POST['id']     ?? 0);
        $nik   = trim($_POST['nik']      ?? '');
        $nama  = trim($_POST['nama']     ?? '');
        $email = trim($_POST['email']    ?? '');
        $hp    = trim($_POST['hp']       ?? '');
        $role  = trim($_POST['role']     ?? 'Operator');

        if (!$id || !$nik || !$nama || !$email) {
            $msg = 'Data tidak lengkap.';
            $msg_type = 'danger';
        } else {
            // Cek duplikat (kecuali diri sendiri)
            $cek = $pdo->prepare("SELECT id FROM users WHERE (nik = ? OR email = ?) AND id != ?");
            $cek->execute([$nik, $email, $id]);
            if ($cek->fetch()) {
                $msg = 'NIK atau Email sudah digunakan pengguna lain.';
                $msg_type = 'warning';
            } else {
                $upd = $pdo->prepare(
                    "UPDATE users SET nik=?, nama=?, email=?, hp=?, role=?, updated_at=NOW() WHERE id=?"
                );
                $upd->execute([$nik, $nama, $email, $hp, $role, $id]);
                $msg = 'Data pengguna berhasil diperbarui.';
                $msg_type = 'success';
            }
        }
    }

    // ── RESET PASSWORD ───────────────────────────────────────────
    elseif ($action === 'reset_password') {
        $id          = intval($_POST['id']           ?? 0);
        $new_pass    = trim($_POST['new_password']   ?? '');
        $confirm_pass= trim($_POST['confirm_password']?? '');

        if (!$id || !$new_pass) {
            $msg = 'ID dan password baru wajib diisi.';
            $msg_type = 'danger';
        } elseif ($new_pass !== $confirm_pass) {
            $msg = 'Konfirmasi password tidak cocok.';
            $msg_type = 'danger';
        } elseif (strlen($new_pass) < 6) {
            $msg = 'Password minimal 6 karakter.';
            $msg_type = 'danger';
        } else {
            $hash = password_hash($new_pass, PASSWORD_BCRYPT);
            $upd  = $pdo->prepare("UPDATE users SET password=?, updated_at=NOW() WHERE id=?");
            $upd->execute([$hash, $id]);
            $msg = 'Password berhasil direset.';
            $msg_type = 'success';
        }
    }

    // ── HAPUS ────────────────────────────────────────────────────
    elseif ($action === 'hapus') {
        $id = intval($_POST['id'] ?? 0);
        // Jangan hapus diri sendiri
        if ($id === intval($_SESSION['user_id'])) {
            $msg = 'Tidak bisa menghapus akun yang sedang login.';
            $msg_type = 'warning';
        } elseif ($id) {
            $del = $pdo->prepare("DELETE FROM users WHERE id=?");
            $del->execute([$id]);
            $msg = 'Pengguna berhasil dihapus.';
            $msg_type = 'success';
        }
    }
}

// ================================================================
// AMBIL DATA
// ================================================================
$cari  = trim($_GET['cari']  ?? '');
$role_filter = trim($_GET['role']  ?? '');
$limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 15;
$page  = isset($_GET['page'])  ? max(1, intval($_GET['page']))  : 1;
$offset = ($page - 1) * $limit;

$sql  = "SELECT * FROM users WHERE 1=1";
$params = [];

if ($cari !== '') {
    $sql .= " AND (nama LIKE ? OR nik LIKE ? OR email LIKE ?)";
    $params[] = "%$cari%"; $params[] = "%$cari%"; $params[] = "%$cari%";
}
if ($role_filter !== '') {
    $sql .= " AND role = ?";
    $params[] = $role_filter;
}

$countStmt = $pdo->prepare(str_replace("SELECT *", "SELECT COUNT(*)", $sql));
$countStmt->execute($params);
$total       = (int)$countStmt->fetchColumn();
$total_pages = max(1, ceil($total / $limit));

$sql .= " ORDER BY created_at DESC LIMIT " . intval($limit) . " OFFSET " . intval($offset);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistik
$statStmt = $pdo->query("SELECT role, COUNT(*) as jml FROM users GROUP BY role");
$statRows = $statStmt->fetchAll(PDO::FETCH_ASSOC);
$statMap  = array_column($statRows, 'jml', 'role');
$total_all   = array_sum($statMap);
$total_admin = $statMap['Admin']    ?? 0;
$total_op    = $statMap['Operator'] ?? 0;

$page_title = 'Data Pengguna - MediFix';
$extra_css  = '
.stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:20px}
.stat-card{background:#fff;border-radius:5px;box-shadow:0 1px 3px rgba(0,0,0,.12);border-top:3px solid;overflow:hidden;transition:all .3s}
.stat-card:hover{transform:translateY(-4px);box-shadow:0 5px 15px rgba(0,0,0,.18)}
.stat-card-content{padding:20px;display:flex;align-items:center;gap:15px}
.stat-icon{width:60px;height:60px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:28px;color:#fff;flex-shrink:0}
.stat-info{flex:1}
.stat-label{font-size:13px;color:#666;margin-bottom:4px}
.stat-value{font-size:28px;font-weight:700;color:#333}
.stat-total   .stat-icon{background:#605ca8}.stat-total   {border-top-color:#605ca8}
.stat-admin   .stat-icon{background:#dd4b39}.stat-admin   {border-top-color:#dd4b39}
.stat-operator.stat-icon{background:#00a65a}.stat-operator{border-top-color:#00a65a}

/* Table action buttons */
.btn-act{width:32px;height:32px;border-radius:6px;padding:0;display:inline-flex;align-items:center;justify-content:center;margin:1px}

/* Avatar */
.user-avatar{width:36px;height:36px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:#fff;flex-shrink:0}

/* Password strength */
#strengthBar{height:5px;border-radius:3px;transition:all .3s;margin-top:4px}
#strengthText{font-size:11px;margin-top:2px}

@media(max-width:992px){.stats-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:576px){.stats-grid{grid-template-columns:1fr}}
';

$extra_js = '
// Password strength meter
document.addEventListener("DOMContentLoaded", function() {
    const pwField = document.getElementById("newPassword");
    if (!pwField) return;
    pwField.addEventListener("input", function() {
        const val = this.value;
        const bar = document.getElementById("strengthBar");
        const txt = document.getElementById("strengthText");
        if (!val) { bar.style.width="0"; txt.textContent=""; return; }
        let score = 0;
        if (val.length >= 6)  score++;
        if (val.length >= 10) score++;
        if (/[A-Z]/.test(val)) score++;
        if (/[0-9]/.test(val)) score++;
        if (/[^A-Za-z0-9]/.test(val)) score++;
        const levels = [
            {w:"20%", c:"#dd4b39", t:"Sangat Lemah"},
            {w:"40%", c:"#f39c12", t:"Lemah"},
            {w:"60%", c:"#f39c12", t:"Cukup"},
            {w:"80%", c:"#00a65a", t:"Kuat"},
            {w:"100%",c:"#00a65a", t:"Sangat Kuat"},
        ];
        const lv = levels[Math.max(0, score-1)];
        bar.style.width     = lv.w;
        bar.style.background= lv.c;
        txt.style.color     = lv.c;
        txt.textContent     = lv.t;
    });
});

// Isi modal Edit dari data-* attribute tombol
function isiModalEdit(el) {
    document.getElementById("editId").value    = el.dataset.id;
    document.getElementById("editNik").value   = el.dataset.nik;
    document.getElementById("editNama").value  = el.dataset.nama;
    document.getElementById("editEmail").value = el.dataset.email;
    document.getElementById("editHp").value    = el.dataset.hp;
    document.getElementById("editRole").value  = el.dataset.role;
}

// Isi modal Reset Password
function isiModalReset(el) {
    document.getElementById("resetId").value   = el.dataset.id;
    document.getElementById("resetNama").textContent = el.dataset.nama;
}

// Isi modal Hapus
function isiModalHapus(el) {
    document.getElementById("hapusId").value   = el.dataset.id;
    document.getElementById("hapusNama").textContent = el.dataset.nama;
}

// Toggle show/hide password
function togglePw(inputId, iconEl) {
    const inp = document.getElementById(inputId);
    if (inp.type === "password") {
        inp.type = "text";
        iconEl.classList.replace("fa-eye","fa-eye-slash");
    } else {
        inp.type = "password";
        iconEl.classList.replace("fa-eye-slash","fa-eye");
    }
}
';

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="content-wrapper">
  <section class="content-header">
    <h1>Data Pengguna</h1>
    <ol class="breadcrumb">
      <li><a href="dashboard.php"><i class="fa fa-dashboard"></i> Home</a></li>
      <li class="active">Data Pengguna</li>
    </ol>
  </section>

  <section class="content">

    <?php if ($msg): ?>
    <div class="alert alert-<?= $msg_type ?> alert-dismissible">
      <button type="button" class="close" data-dismiss="alert">×</button>
      <?= $msg ?>
    </div>
    <?php endif; ?>

    <!-- Statistik -->
    <div class="stats-grid">
      <div class="stat-card stat-total">
        <div class="stat-card-content">
          <div class="stat-icon"><i class="fa fa-users"></i></div>
          <div class="stat-info"><div class="stat-label">Total Pengguna</div><div class="stat-value"><?= $total_all ?></div></div>
        </div>
      </div>
      <div class="stat-card stat-admin">
        <div class="stat-card-content">
          <div class="stat-icon"><i class="fa fa-user-secret"></i></div>
          <div class="stat-info"><div class="stat-label">Admin</div><div class="stat-value"><?= $total_admin ?></div></div>
        </div>
      </div>
      <div class="stat-card stat-operator" style="border-top-color:#00a65a">
        <div class="stat-card-content">
          <div class="stat-icon" style="background:#00a65a"><i class="fa fa-user"></i></div>
          <div class="stat-info"><div class="stat-label">Operator</div><div class="stat-value"><?= $total_op ?></div></div>
        </div>
      </div>
    </div>

    <!-- Filter & Tabel -->
    <div class="row">
      <div class="col-xs-12">

        <!-- Filter -->
        <div class="box">
          <div class="box-header with-border">
            <h3 class="box-title">Filter & Pencarian</h3>
            <div class="box-tools pull-right">
              <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#modalTambah">
                <i class="fa fa-plus"></i> Tambah Pengguna
              </button>
            </div>
          </div>
          <div class="box-body">
            <form method="GET" class="form-inline">
              <div class="form-group" style="margin-right:10px">
                <label style="margin-right:6px">Cari:</label>
                <input type="text" name="cari" class="form-control" placeholder="Nama / NIK / Email..."
                       value="<?= htmlspecialchars($cari) ?>" style="width:220px">
              </div>
              <div class="form-group" style="margin-right:10px">
                <label style="margin-right:6px">Role:</label>
                <select name="role" class="form-control">
                  <option value="">Semua Role</option>
                  <option value="Admin"    <?= $role_filter==='Admin'    ?'selected':'' ?>>Admin</option>
                  <option value="Operator" <?= $role_filter==='Operator' ?'selected':'' ?>>Operator</option>
                </select>
              </div>
              <div class="form-group" style="margin-right:10px">
                <label style="margin-right:6px">Tampilkan:</label>
                <select name="limit" class="form-control">
                  <option value="15"  <?= $limit==15 ?'selected':'' ?>>15</option>
                  <option value="30"  <?= $limit==30 ?'selected':'' ?>>30</option>
                  <option value="50"  <?= $limit==50 ?'selected':'' ?>>50</option>
                </select>
              </div>
              <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i> Cari</button>
              <a href="data_pengguna.php" class="btn btn-default" style="margin-left:6px"><i class="fa fa-refresh"></i> Reset</a>
            </form>
          </div>
        </div>

        <!-- Tabel -->
        <div class="box">
          <div class="box-header">
            <h3 class="box-title">
              Daftar Pengguna
              <span class="label label-default" style="font-size:12px;margin-left:6px"><?= $total ?> data</span>
            </h3>
          </div>
          <div class="box-body">
            <?php if ($users): ?>
            <div class="table-responsive">
              <table class="table table-bordered table-striped table-hover">
                <thead style="background:#605ca8;color:#fff">
                  <tr>
                    <th width="50">No</th>
                    <th width="110">Aksi</th>
                    <th>Nama / NIK</th>
                    <th>Email</th>
                    <th width="110">No. HP</th>
                    <th width="100">Role</th>
                    <th width="140">Terdaftar</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $no = ($page-1)*$limit + 1;
                  $colors = ['#605ca8','#00a65a','#dd4b39','#f39c12','#3c8dbc'];
                  foreach ($users as $u):
                      $initial = mb_strtoupper(mb_substr($u['nama'],0,1));
                      $color   = $colors[abs(crc32($u['nama'])) % count($colors)];
                      $isSelf  = $u['id'] == $_SESSION['user_id'];
                  ?>
                  <tr>
                    <td><?= $no++ ?></td>
                    <td>
                      <!-- Edit -->
                      <button class="btn btn-warning btn-act" title="Edit"
                              data-toggle="modal" data-target="#modalEdit"
                              data-id="<?= $u['id'] ?>"
                              data-nik="<?= htmlspecialchars($u['nik']) ?>"
                              data-nama="<?= htmlspecialchars($u['nama']) ?>"
                              data-email="<?= htmlspecialchars($u['email']) ?>"
                              data-hp="<?= htmlspecialchars($u['hp'] ?? '') ?>"
                              data-role="<?= htmlspecialchars($u['role'] ?? 'Operator') ?>"
                              onclick="isiModalEdit(this)">
                        <i class="fa fa-pencil"></i>
                      </button>
                      <!-- Reset Password -->
                      <button class="btn btn-info btn-act" title="Reset Password"
                              data-toggle="modal" data-target="#modalReset"
                              data-id="<?= $u['id'] ?>"
                              data-nama="<?= htmlspecialchars($u['nama']) ?>"
                              onclick="isiModalReset(this)">
                        <i class="fa fa-key"></i>
                      </button>
                      <!-- Hapus -->
                      <?php if (!$isSelf): ?>
                      <button class="btn btn-danger btn-act" title="Hapus"
                              data-toggle="modal" data-target="#modalHapus"
                              data-id="<?= $u['id'] ?>"
                              data-nama="<?= htmlspecialchars($u['nama']) ?>"
                              onclick="isiModalHapus(this)">
                        <i class="fa fa-trash"></i>
                      </button>
                      <?php else: ?>
                      <button class="btn btn-default btn-act" title="Akun aktif" disabled>
                        <i class="fa fa-lock"></i>
                      </button>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div style="display:flex;align-items:center;gap:10px">
                        <div class="user-avatar" style="background:<?= $color ?>">
                          <?= $initial ?>
                        </div>
                        <div>
                          <strong><?= htmlspecialchars($u['nama']) ?></strong>
                          <?php if ($isSelf): ?>
                            <span class="label label-success" style="font-size:9px;margin-left:4px">Anda</span>
                          <?php endif; ?>
                          <br><small class="text-muted"><?= htmlspecialchars($u['nik']) ?></small>
                        </div>
                      </div>
                    </td>
                    <td><?= htmlspecialchars($u['email']) ?></td>
                    <td><?= htmlspecialchars($u['hp'] ?? '-') ?></td>
                    <td>
                      <?php if (($u['role'] ?? '') === 'Admin'): ?>
                        <span class="label label-danger"><i class="fa fa-user-secret"></i> Admin</span>
                      <?php else: ?>
                        <span class="label label-success"><i class="fa fa-user"></i> Operator</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <small><?= date('d/m/Y H:i', strtotime($u['created_at'])) ?></small>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="box-footer clearfix">
              <ul class="pagination pagination-sm no-margin pull-right">
                <?php if ($page > 1): ?>
                <li><a href="?page=<?=$page-1?>&cari=<?=urlencode($cari)?>&role=<?=urlencode($role_filter)?>&limit=<?=$limit?>">«</a></li>
                <?php endif; ?>
                <?php for ($i=max(1,$page-2); $i<=min($total_pages,$page+2); $i++): ?>
                <li <?=$i==$page?'class="active"':''?>>
                  <a href="?page=<?=$i?>&cari=<?=urlencode($cari)?>&role=<?=urlencode($role_filter)?>&limit=<?=$limit?>"><?=$i?></a>
                </li>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                <li><a href="?page=<?=$page+1?>&cari=<?=urlencode($cari)?>&role=<?=urlencode($role_filter)?>&limit=<?=$limit?>">»</a></li>
                <?php endif; ?>
              </ul>
              <p class="text-muted" style="margin-top:8px;font-size:13px">
                Menampilkan <?= (($page-1)*$limit)+1 ?>–<?= min($page*$limit,$total) ?> dari <?= $total ?> pengguna
              </p>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <div class="callout callout-info">
              <h4><i class="fa fa-info-circle"></i> Tidak Ada Data</h4>
              <p>Tidak ada pengguna yang sesuai dengan pencarian.</p>
            </div>
            <?php endif; ?>
          </div>
        </div>

      </div>
    </div>

  </section>
</div>

<!-- ================================================================ -->
<!-- MODAL TAMBAH                                                       -->
<!-- ================================================================ -->
<div class="modal fade" id="modalTambah" tabindex="-1">
  <div class="modal-dialog modal-md">
    <form method="POST">
      <input type="hidden" name="action" value="tambah">
      <div class="modal-content">
        <div class="modal-header" style="background:#605ca8;color:#fff;border-radius:4px 4px 0 0">
          <button type="button" class="close" data-dismiss="modal" style="color:#fff"><span>×</span></button>
          <h4 class="modal-title"><i class="fa fa-user-plus"></i> Tambah Pengguna</h4>
        </div>
        <div class="modal-body">
          <div class="row">
            <div class="col-sm-6">
              <div class="form-group">
                <label>NIK <span class="text-danger">*</span></label>
                <input type="text" name="nik" class="form-control" placeholder="Nomor Induk..." required>
              </div>
            </div>
            <div class="col-sm-6">
              <div class="form-group">
                <label>Role <span class="text-danger">*</span></label>
                <select name="role" class="form-control" required>
                  <option value="Operator">Operator</option>
                  <option value="Admin">Admin</option>
                </select>
              </div>
            </div>
          </div>
          <div class="form-group">
            <label>Nama Lengkap <span class="text-danger">*</span></label>
            <input type="text" name="nama" class="form-control" placeholder="Nama lengkap..." required>
          </div>
          <div class="form-group">
            <label>Email <span class="text-danger">*</span></label>
            <input type="email" name="email" class="form-control" placeholder="email@domain.com" required>
          </div>
          <div class="form-group">
            <label>No. HP</label>
            <input type="text" name="hp" class="form-control" placeholder="08xxxxxxxxxx">
          </div>
          <div class="form-group">
            <label>Password <span class="text-danger">*</span></label>
            <div class="input-group">
              <input type="password" id="tambahPw" name="password" class="form-control" placeholder="Min. 6 karakter" required>
              <span class="input-group-btn">
                <button class="btn btn-default" type="button" onclick="togglePw('tambahPw', this.querySelector('i'))">
                  <i class="fa fa-eye"></i>
                </button>
              </span>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-default" data-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Simpan</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ================================================================ -->
<!-- MODAL EDIT                                                         -->
<!-- ================================================================ -->
<div class="modal fade" id="modalEdit" tabindex="-1">
  <div class="modal-dialog modal-md">
    <form method="POST">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="editId">
      <div class="modal-content">
        <div class="modal-header" style="background:#f39c12;color:#fff;border-radius:4px 4px 0 0">
          <button type="button" class="close" data-dismiss="modal" style="color:#fff"><span>×</span></button>
          <h4 class="modal-title"><i class="fa fa-pencil"></i> Edit Pengguna</h4>
        </div>
        <div class="modal-body">
          <div class="row">
            <div class="col-sm-6">
              <div class="form-group">
                <label>NIK <span class="text-danger">*</span></label>
                <input type="text" name="nik" id="editNik" class="form-control" required>
              </div>
            </div>
            <div class="col-sm-6">
              <div class="form-group">
                <label>Role <span class="text-danger">*</span></label>
                <select name="role" id="editRole" class="form-control" required>
                  <option value="Operator">Operator</option>
                  <option value="Admin">Admin</option>
                </select>
              </div>
            </div>
          </div>
          <div class="form-group">
            <label>Nama Lengkap <span class="text-danger">*</span></label>
            <input type="text" name="nama" id="editNama" class="form-control" required>
          </div>
          <div class="form-group">
            <label>Email <span class="text-danger">*</span></label>
            <input type="email" name="email" id="editEmail" class="form-control" required>
          </div>
          <div class="form-group">
            <label>No. HP</label>
            <input type="text" name="hp" id="editHp" class="form-control">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-default" data-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-warning"><i class="fa fa-save"></i> Update</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ================================================================ -->
<!-- MODAL RESET PASSWORD                                               -->
<!-- ================================================================ -->
<div class="modal fade" id="modalReset" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <form method="POST">
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="id" id="resetId">
      <div class="modal-content">
        <div class="modal-header" style="background:#3c8dbc;color:#fff;border-radius:4px 4px 0 0">
          <button type="button" class="close" data-dismiss="modal" style="color:#fff"><span>×</span></button>
          <h4 class="modal-title"><i class="fa fa-key"></i> Reset Password</h4>
        </div>
        <div class="modal-body">
          <p>Reset password untuk: <strong id="resetNama"></strong></p>
          <div class="form-group">
            <label>Password Baru <span class="text-danger">*</span></label>
            <div class="input-group">
              <input type="password" id="newPassword" name="new_password" class="form-control" placeholder="Min. 6 karakter" required>
              <span class="input-group-btn">
                <button class="btn btn-default" type="button" onclick="togglePw('newPassword', this.querySelector('i'))">
                  <i class="fa fa-eye"></i>
                </button>
              </span>
            </div>
            <div id="strengthBar" style="width:0;background:#eee"></div>
            <div id="strengthText"></div>
          </div>
          <div class="form-group">
            <label>Konfirmasi Password <span class="text-danger">*</span></label>
            <div class="input-group">
              <input type="password" id="confirmPassword" name="confirm_password" class="form-control" placeholder="Ulangi password" required>
              <span class="input-group-btn">
                <button class="btn btn-default" type="button" onclick="togglePw('confirmPassword', this.querySelector('i'))">
                  <i class="fa fa-eye"></i>
                </button>
              </span>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-default" data-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-info"><i class="fa fa-key"></i> Reset</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ================================================================ -->
<!-- MODAL HAPUS                                                        -->
<!-- ================================================================ -->
<div class="modal fade" id="modalHapus" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <form method="POST">
      <input type="hidden" name="action" value="hapus">
      <input type="hidden" name="id" id="hapusId">
      <div class="modal-content">
        <div class="modal-header" style="background:#dd4b39;color:#fff;border-radius:4px 4px 0 0">
          <button type="button" class="close" data-dismiss="modal" style="color:#fff"><span>×</span></button>
          <h4 class="modal-title"><i class="fa fa-trash"></i> Konfirmasi Hapus</h4>
        </div>
        <div class="modal-body text-center">
          <i class="fa fa-exclamation-triangle" style="font-size:48px;color:#f39c12;margin-bottom:15px;display:block"></i>
          <p>Yakin ingin menghapus pengguna:</p>
          <strong id="hapusNama" style="font-size:16px"></strong>
          <p class="text-muted" style="margin-top:10px;font-size:12px">Tindakan ini tidak dapat dibatalkan.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-default" data-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-danger"><i class="fa fa-trash"></i> Hapus</button>
        </div>
      </div>
    </form>
  </div>
</div>

<?php include 'includes/footer.php'; ?>