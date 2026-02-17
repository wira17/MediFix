<?php
session_start();
date_default_timezone_set('Asia/Jakarta');

// Koneksi ke DB anjungan
include 'koneksi.php';

// Auto-detect variabel PDO yang tersedia
if      (isset($pdo))       $db = $pdo;
elseif  (isset($pdo_simrs)) $db = $pdo_simrs;
elseif  (isset($conn))      $db = $conn;
else    die('<div style="padding:30px;color:red;font-family:sans-serif"><h3>Error: Variabel koneksi tidak ditemukan di koneksi.php</h3><p>Pastikan file koneksi.php mengekspor variabel $pdo, $pdo_simrs, atau $conn</p></div>');

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$msg      = '';
$msg_type = '';

// ================================================================
// PROSES POST
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'tambah') {
        $nik   = trim($_POST['nik']      ?? '');
        $nama  = trim($_POST['nama']     ?? '');
        $email = trim($_POST['email']    ?? '');
        $hp    = trim($_POST['hp']       ?? '');
        $pw    = trim($_POST['password'] ?? '');

        if (!$nik || !$nama || !$email || !$pw) {
            $msg = 'NIK, Nama, Email, dan Password wajib diisi.';
            $msg_type = 'danger';
        } else {
            $cek = $db->prepare("SELECT id FROM users WHERE nik=? OR email=?");
            $cek->execute([$nik, $email]);
            if ($cek->fetch()) {
                $msg = 'NIK atau Email sudah terdaftar.';
                $msg_type = 'warning';
            } else {
                $db->prepare("INSERT INTO users (nik,nama,email,password,hp) VALUES (?,?,?,?,?)")
                   ->execute([$nik, $nama, $email, password_hash($pw, PASSWORD_BCRYPT), $hp]);
                $msg = 'Pengguna <strong>'.htmlspecialchars($nama).'</strong> berhasil ditambahkan.';
                $msg_type = 'success';
            }
        }
    }

    elseif ($action === 'edit') {
        $id    = intval($_POST['id']    ?? 0);
        $nik   = trim($_POST['nik']     ?? '');
        $nama  = trim($_POST['nama']    ?? '');
        $email = trim($_POST['email']   ?? '');
        $hp    = trim($_POST['hp']      ?? '');

        if (!$id || !$nik || !$nama || !$email) {
            $msg = 'Data tidak lengkap.'; $msg_type = 'danger';
        } else {
            $cek = $db->prepare("SELECT id FROM users WHERE (nik=? OR email=?) AND id!=?");
            $cek->execute([$nik,$email,$id]);
            if ($cek->fetch()) {
                $msg = 'NIK atau Email sudah digunakan pengguna lain.'; $msg_type = 'warning';
            } else {
                $db->prepare("UPDATE users SET nik=?,nama=?,email=?,hp=?,updated_at=NOW() WHERE id=?")
                   ->execute([$nik,$nama,$email,$hp,$id]);
                $msg = 'Data berhasil diperbarui.'; $msg_type = 'success';
            }
        }
    }

    elseif ($action === 'reset_password') {
        $id   = intval($_POST['id']               ?? 0);
        $pw   = trim($_POST['new_password']        ?? '');
        $cpw  = trim($_POST['confirm_password']    ?? '');

        if (!$id || !$pw)          { $msg='Password wajib diisi.';             $msg_type='danger'; }
        elseif ($pw !== $cpw)      { $msg='Konfirmasi password tidak cocok.';  $msg_type='danger'; }
        elseif (strlen($pw) < 6)   { $msg='Password minimal 6 karakter.';      $msg_type='danger'; }
        else {
            $db->prepare("UPDATE users SET password=?,updated_at=NOW() WHERE id=?")
               ->execute([password_hash($pw, PASSWORD_BCRYPT), $id]);
            $msg='Password berhasil direset.'; $msg_type='success';
        }
    }

    elseif ($action === 'hapus') {
        $id = intval($_POST['id'] ?? 0);
        if ($id === intval($_SESSION['user_id'])) {
            $msg='Tidak bisa menghapus akun yang sedang login.'; $msg_type='warning';
        } elseif ($id) {
            $db->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
            $msg='Pengguna berhasil dihapus.'; $msg_type='success';
        }
    }
}

// ================================================================
// QUERY DATA
// ================================================================
$cari   = trim($_GET['cari']  ?? '');
$limit  = max(1, intval($_GET['limit'] ?? 15));
$page   = max(1, intval($_GET['page']  ?? 1));
$offset = ($page-1)*$limit;

$where  = '1=1';
$params = [];
if ($cari !== '') {
    $where   .= ' AND (nama LIKE ? OR nik LIKE ? OR email LIKE ?)';
    $params[]= "%$cari%"; $params[]="%$cari%"; $params[]="%$cari%";
}

$total       = (int)$db->prepare("SELECT COUNT(*) FROM users WHERE $where")->execute($params) ?
               (function($d,$p){$d->execute($p);return (int)$d->fetchColumn();})(
                   $db->prepare("SELECT COUNT(*) FROM users WHERE $where"), $params) : 0;
$total_pages = max(1, ceil($total / $limit));

$stmtD = $db->prepare("SELECT * FROM users WHERE $where ORDER BY created_at DESC LIMIT ".intval($limit)." OFFSET ".intval($offset));
$stmtD->execute($params);
$users = $stmtD->fetchAll(PDO::FETCH_ASSOC);

$total_all = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();

// ================================================================
// RENDER
// ================================================================
$page_title = 'Data Pengguna - MediFix';
$extra_css  = '
.stats-row{display:flex;gap:16px;margin-bottom:20px;flex-wrap:wrap}
.sbox{flex:1;min-width:150px;background:#fff;border-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,.1);border-top:3px solid #605ca8;padding:16px 18px;display:flex;align-items:center;gap:12px}
.sbox-icon{width:50px;height:50px;border-radius:8px;background:#605ca8;display:flex;align-items:center;justify-content:center;font-size:24px;color:#fff;flex-shrink:0}
.sbox-val{font-size:26px;font-weight:700;color:#333;line-height:1}
.sbox-lbl{font-size:12px;color:#888;margin-top:2px}

/* Tombol aksi tabel */
.tbl-act{display:inline-flex;gap:3px;flex-wrap:nowrap}
.bxa{width:28px;height:28px;border-radius:5px;border:none;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:13px;color:#fff;transition:opacity .2s}
.bxa:hover{opacity:.8}
.bxa.e{background:#f39c12}
.bxa.k{background:#3c8dbc}
.bxa.h{background:#dd4b39}
.bxa.d{background:#aaa;cursor:not-allowed}

/* Avatar inisial */
.av{width:34px;height:34px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:#fff;flex-shrink:0}

/* Badge "Anda" */
.anda-badge{background:#00a65a;color:#fff;font-size:9px;padding:1px 5px;border-radius:3px;margin-left:4px;vertical-align:middle;font-weight:700}

/* Eye toggle di field password */
.pw-wrap{position:relative}
.pw-wrap .form-control{padding-right:38px}
.pw-eye{position:absolute;right:0;top:0;height:100%;width:36px;background:none;border:none;cursor:pointer;color:#888;display:flex;align-items:center;justify-content:center}
.pw-eye:hover{color:#333}

/* Strength bar */
.str-wrap{margin-top:5px}
.str-outer{height:4px;background:#e8e8e8;border-radius:2px;overflow:hidden}
.str-inner{height:100%;border-radius:2px;width:0;transition:all .3s}
.str-label{font-size:11px;margin-top:3px}
';

$extra_js = '
/* Buka modal Tambah */
function openTambah(){
    document.getElementById("fTambah").reset();
    document.getElementById("tPw").type="password";
    $("#modalTambah").modal("show");
}

/* Isi dan buka modal Edit */
function openEdit(el){
    document.getElementById("eId").value    = el.dataset.id;
    document.getElementById("eNik").value   = el.dataset.nik;
    document.getElementById("eNama").value  = el.dataset.nama;
    document.getElementById("eEmail").value = el.dataset.email;
    document.getElementById("eHp").value    = el.dataset.hp;
    $("#modalEdit").modal("show");
}

/* Isi dan buka modal Reset */
function openReset(el){
    document.getElementById("rId").value = el.dataset.id;
    document.getElementById("rNama").textContent = el.dataset.nama;
    document.getElementById("rNewPw").value  = "";
    document.getElementById("rConfPw").value = "";
    setStrength("",0);
    $("#modalReset").modal("show");
}

/* Isi dan buka modal Hapus */
function openHapus(el){
    document.getElementById("hId").value = el.dataset.id;
    document.getElementById("hNama").textContent = el.dataset.nama;
    $("#modalHapus").modal("show");
}

/* Toggle visibility password */
function eyeToggle(inputId, btn){
    var inp = document.getElementById(inputId);
    var ico = btn.querySelector("i");
    if(inp.type==="password"){
        inp.type="text";
        ico.className="fa fa-eye-slash";
    } else {
        inp.type="password";
        ico.className="fa fa-eye";
    }
}

/* Strength meter */
function setStrength(v, score){
    var inner = document.getElementById("strInner");
    var label = document.getElementById("strLabel");
    if(!inner) return;
    if(!v){ inner.style.width="0"; label.textContent=""; return; }
    var lvl = [
        {w:"20%",c:"#dd4b39",t:"Sangat Lemah"},
        {w:"40%",c:"#f39c12",t:"Lemah"},
        {w:"60%",c:"#e8a838",t:"Cukup"},
        {w:"80%",c:"#00a65a",t:"Kuat"},
        {w:"100%",c:"#00a65a",t:"Sangat Kuat"}
    ][Math.max(0, score-1)];
    inner.style.width=lvl.w; inner.style.background=lvl.c;
    label.style.color=lvl.c; label.textContent=lvl.t;
}

document.addEventListener("DOMContentLoaded", function(){
    var pw = document.getElementById("rNewPw");
    if(!pw) return;
    pw.addEventListener("input", function(){
        var v=this.value, s=0;
        if(v.length>=6)  s++;
        if(v.length>=10) s++;
        if(/[A-Z]/.test(v)) s++;
        if(/[0-9]/.test(v)) s++;
        if(/[^A-Za-z0-9]/.test(v)) s++;
        setStrength(v, s);
    });
});
';

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="content-wrapper">
  <section class="content-header">
    <h1>Data Pengguna <small>Manajemen akun sistem</small></h1>
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
    <div class="stats-row">
      <div class="sbox">
        <div class="sbox-icon"><i class="fa fa-users"></i></div>
        <div><div class="sbox-val"><?= $total_all ?></div><div class="sbox-lbl">Total Pengguna</div></div>
      </div>
    </div>

    <!-- Filter + Tambah -->
    <div class="box">
      <div class="box-header with-border">
        <h3 class="box-title">Filter Pengguna</h3>
        <div class="box-tools pull-right">
          <button class="btn btn-primary btn-sm" onclick="openTambah()">
            <i class="fa fa-user-plus"></i> Tambah Pengguna
          </button>
        </div>
      </div>
      <div class="box-body">
        <form method="GET" class="form-inline">
          <div class="form-group" style="margin-right:8px">
            <input type="text" name="cari" class="form-control"
                   placeholder="Cari nama / NIK / email..."
                   value="<?= htmlspecialchars($cari) ?>" style="width:260px">
          </div>
          <div class="form-group" style="margin-right:8px">
            <select name="limit" class="form-control">
              <option value="15" <?= $limit==15?'selected':'' ?>>15 / halaman</option>
              <option value="30" <?= $limit==30?'selected':'' ?>>30 / halaman</option>
              <option value="50" <?= $limit==50?'selected':'' ?>>50 / halaman</option>
            </select>
          </div>
          <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i> Cari</button>
          <a href="data_pengguna.php" class="btn btn-default" style="margin-left:6px">
            <i class="fa fa-refresh"></i> Reset
          </a>
        </form>
      </div>
    </div>

    <!-- Tabel -->
    <div class="box">
      <div class="box-header">
        <h3 class="box-title">
          Daftar Pengguna &nbsp;
          <small class="text-muted"><?= $total ?> data ditemukan</small>
        </h3>
      </div>
      <div class="box-body">
        <?php if ($users): ?>
        <div class="table-responsive">
          <table class="table table-bordered table-striped table-hover" style="white-space:nowrap">
            <thead style="background:#605ca8;color:#fff">
              <tr>
                <th width="45">No</th>
                <th width="105">Aksi</th>
                <th>Nama</th>
                <th width="140">NIK</th>
                <th>Email</th>
                <th width="135">No. HP</th>
                <th width="145">Terdaftar</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $palette = ['#605ca8','#00a65a','#dd4b39','#f39c12','#3c8dbc','#e91e63'];
              $no      = ($page-1)*$limit + 1;
              foreach ($users as $u):
                  $initial = mb_strtoupper(mb_substr($u['nama'],0,1,'UTF-8'));
                  $color   = $palette[abs(crc32($u['nama'])) % count($palette)];
                  $isSelf  = ((int)$u['id'] === (int)$_SESSION['user_id']);
              ?>
              <tr>
                <td><?= $no++ ?></td>
                <td>
                  <div class="tbl-act">
                    <button class="bxa e" title="Edit"
                            data-id="<?= $u['id'] ?>"
                            data-nik="<?= htmlspecialchars($u['nik']) ?>"
                            data-nama="<?= htmlspecialchars($u['nama']) ?>"
                            data-email="<?= htmlspecialchars($u['email']) ?>"
                            data-hp="<?= htmlspecialchars($u['hp'] ?? '') ?>"
                            onclick="openEdit(this)">
                      <i class="fa fa-pencil"></i>
                    </button>
                    <button class="bxa k" title="Reset Password"
                            data-id="<?= $u['id'] ?>"
                            data-nama="<?= htmlspecialchars($u['nama']) ?>"
                            onclick="openReset(this)">
                      <i class="fa fa-key"></i>
                    </button>
                    <?php if (!$isSelf): ?>
                    <button class="bxa h" title="Hapus"
                            data-id="<?= $u['id'] ?>"
                            data-nama="<?= htmlspecialchars($u['nama']) ?>"
                            onclick="openHapus(this)">
                      <i class="fa fa-trash"></i>
                    </button>
                    <?php else: ?>
                    <button class="bxa d" title="Akun aktif - tidak bisa dihapus" disabled>
                      <i class="fa fa-lock"></i>
                    </button>
                    <?php endif; ?>
                  </div>
                </td>
                <td>
                  <div style="display:flex;align-items:center;gap:8px">
                    <div class="av" style="background:<?= $color ?>"><?= $initial ?></div>
                    <span>
                      <strong><?= htmlspecialchars($u['nama']) ?></strong>
                      <?php if ($isSelf): ?>
                        <span class="anda-badge">Anda</span>
                      <?php endif; ?>
                    </span>
                  </div>
                </td>
                <td><?= htmlspecialchars($u['nik']) ?></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td><?= htmlspecialchars($u['hp'] ?? '-') ?></td>
                <td><small><?= date('d/m/Y H:i', strtotime($u['created_at'])) ?></small></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="clearfix" style="margin-top:12px">
          <ul class="pagination pagination-sm no-margin pull-right">
            <?php if ($page > 1): ?>
            <li><a href="?page=<?=$page-1?>&cari=<?=urlencode($cari)?>&limit=<?=$limit?>">«</a></li>
            <?php endif; ?>
            <?php for ($i=max(1,$page-2); $i<=min($total_pages,$page+2); $i++): ?>
            <li <?=$i==$page?'class="active"':''?>>
              <a href="?page=<?=$i?>&cari=<?=urlencode($cari)?>&limit=<?=$limit?>"><?=$i?></a>
            </li>
            <?php endfor; ?>
            <?php if ($page < $total_pages): ?>
            <li><a href="?page=<?=$page+1?>&cari=<?=urlencode($cari)?>&limit=<?=$limit?>">»</a></li>
            <?php endif; ?>
          </ul>
          <p class="text-muted" style="padding-top:7px;font-size:12px">
            <?= ($page-1)*$limit+1 ?>–<?= min($page*$limit,$total) ?> dari <?= $total ?> data
          </p>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="callout callout-info">
          <i class="fa fa-info-circle"></i>
          Tidak ada pengguna<?= $cari ? ' yang cocok dengan "<strong>'.htmlspecialchars($cari).'</strong>"' : '' ?>.
        </div>
        <?php endif; ?>
      </div>
    </div>

  </section>
</div>

<!-- ============================================================ -->
<!-- MODAL TAMBAH                                                  -->
<!-- ============================================================ -->
<div class="modal fade" id="modalTambah" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST" id="fTambah">
      <input type="hidden" name="action" value="tambah">
      <div class="modal-content">
        <div class="modal-header" style="background:#605ca8;border-radius:4px 4px 0 0">
          <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:1;font-size:22px">×</button>
          <h4 class="modal-title" style="color:#fff"><i class="fa fa-user-plus"></i> Tambah Pengguna</h4>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label>NIK <span class="text-danger">*</span></label>
            <input type="text" name="nik" class="form-control" placeholder="Nomor Induk Karyawan" required>
          </div>
          <div class="form-group">
            <label>Nama Lengkap <span class="text-danger">*</span></label>
            <input type="text" name="nama" class="form-control" placeholder="Nama lengkap" required>
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
            <div class="pw-wrap">
              <input type="password" id="tPw" name="password" class="form-control" placeholder="Minimal 6 karakter" required>
              <button type="button" class="pw-eye" onclick="eyeToggle('tPw',this)">
                <i class="fa fa-eye"></i>
              </button>
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

<!-- ============================================================ -->
<!-- MODAL EDIT                                                    -->
<!-- ============================================================ -->
<div class="modal fade" id="modalEdit" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="eId">
      <div class="modal-content">
        <div class="modal-header" style="background:#f39c12;border-radius:4px 4px 0 0">
          <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:1;font-size:22px">×</button>
          <h4 class="modal-title" style="color:#fff"><i class="fa fa-pencil"></i> Edit Pengguna</h4>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label>NIK <span class="text-danger">*</span></label>
            <input type="text" name="nik" id="eNik" class="form-control" required>
          </div>
          <div class="form-group">
            <label>Nama Lengkap <span class="text-danger">*</span></label>
            <input type="text" name="nama" id="eNama" class="form-control" required>
          </div>
          <div class="form-group">
            <label>Email <span class="text-danger">*</span></label>
            <input type="email" name="email" id="eEmail" class="form-control" required>
          </div>
          <div class="form-group">
            <label>No. HP</label>
            <input type="text" name="hp" id="eHp" class="form-control" placeholder="08xxxxxxxxxx">
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

<!-- ============================================================ -->
<!-- MODAL RESET PASSWORD                                          -->
<!-- ============================================================ -->
<div class="modal fade" id="modalReset" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <form method="POST">
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="id" id="rId">
      <div class="modal-content">
        <div class="modal-header" style="background:#3c8dbc;border-radius:4px 4px 0 0">
          <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:1;font-size:22px">×</button>
          <h4 class="modal-title" style="color:#fff"><i class="fa fa-key"></i> Reset Password</h4>
        </div>
        <div class="modal-body">
          <p class="text-muted" style="margin-bottom:15px">
            Reset password untuk: <strong id="rNama"></strong>
          </p>
          <div class="form-group">
            <label>Password Baru <span class="text-danger">*</span></label>
            <div class="pw-wrap">
              <input type="password" id="rNewPw" name="new_password" class="form-control" placeholder="Min. 6 karakter" required>
              <button type="button" class="pw-eye" onclick="eyeToggle('rNewPw',this)">
                <i class="fa fa-eye"></i>
              </button>
            </div>
            <div class="str-wrap">
              <div class="str-outer"><div id="strInner" class="str-inner"></div></div>
              <div id="strLabel" class="str-label"></div>
            </div>
          </div>
          <div class="form-group">
            <label>Konfirmasi Password <span class="text-danger">*</span></label>
            <div class="pw-wrap">
              <input type="password" id="rConfPw" name="confirm_password" class="form-control" placeholder="Ulangi password" required>
              <button type="button" class="pw-eye" onclick="eyeToggle('rConfPw',this)">
                <i class="fa fa-eye"></i>
              </button>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-default" data-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-info"><i class="fa fa-key"></i> Reset Password</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ============================================================ -->
<!-- MODAL HAPUS                                                   -->
<!-- ============================================================ -->
<div class="modal fade" id="modalHapus" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <form method="POST">
      <input type="hidden" name="action" value="hapus">
      <input type="hidden" name="id" id="hId">
      <div class="modal-content">
        <div class="modal-header" style="background:#dd4b39;border-radius:4px 4px 0 0">
          <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:1;font-size:22px">×</button>
          <h4 class="modal-title" style="color:#fff"><i class="fa fa-trash"></i> Konfirmasi Hapus</h4>
        </div>
        <div class="modal-body text-center" style="padding:30px 20px">
          <i class="fa fa-exclamation-triangle" style="font-size:52px;color:#f39c12;display:block;margin-bottom:15px"></i>
          <p style="font-size:15px">Yakin ingin menghapus pengguna:</p>
          <strong id="hNama" style="font-size:17px"></strong>
          <p class="text-muted" style="margin-top:12px;font-size:12px">Tindakan ini tidak dapat dibatalkan.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-default" data-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-danger"><i class="fa fa-trash"></i> Ya, Hapus</button>
        </div>
      </div>
    </form>
  </div>
</div>

<?php include 'includes/footer.php'; ?>