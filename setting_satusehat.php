<?php
session_start();
include 'koneksi.php';
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$nama = $_SESSION['nama'] ?? 'Pengguna';

// ── Pastikan tabel ada ────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS setting_satusehat (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        client_id       VARCHAR(200) NOT NULL DEFAULT '',
        client_secret   VARCHAR(300) NOT NULL DEFAULT '',
        org_id          VARCHAR(50)  NOT NULL DEFAULT '',
        base_url        VARCHAR(200) NOT NULL DEFAULT 'https://api-satusehat.kemkes.go.id',
        orthanc_secret  VARCHAR(100) NOT NULL DEFAULT '',
        medifix_url     VARCHAR(200) NOT NULL DEFAULT '',
        debug_mode      TINYINT(1)   NOT NULL DEFAULT 0,
        updated_at      DATETIME     NULL,
        updated_by      VARCHAR(50)  NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── Ambil Data ────────────────────────────────────────────────────
$data = $pdo->query("SELECT * FROM setting_satusehat LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$success = $error = '';

// ── HAPUS DATA ────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'hapus' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        $pdo->prepare("DELETE FROM setting_satusehat WHERE id = ?")->execute([$id]);
        $cache = sys_get_temp_dir() . '/ss_token_cache.json';
        if (file_exists($cache)) unlink($cache);
        $success = '✔ Setting Satu Sehat berhasil dihapus!';
        $data = null;
    } catch (PDOException $e) {
        $error = '⚠ Gagal menghapus: ' . $e->getMessage();
    }
}

// ── TEST TOKEN (AJAX) ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_token') {
    header('Content-Type: application/json');
    $ci  = trim($_POST['client_id']     ?? '');
    $cs  = trim($_POST['client_secret'] ?? '');
    $bu  = rtrim(trim($_POST['base_url'] ?? 'https://api-satusehat.kemkes.go.id'), '/');
    $url = $bu . '/oauth2/v1/accesstoken?grant_type=client_credentials';

    if (!$ci || !$cs) {
        echo json_encode(['status'=>'error','message'=>'Client ID dan Client Secret wajib diisi dulu']);
        exit;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['client_id'=>$ci, 'client_secret'=>$cs]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        echo json_encode(['status'=>'error','message'=>'cURL error: '.$curlErr]);
        exit;
    }

    $json = json_decode($resp, true);
    if ($httpCode === 200 && !empty($json['access_token'])) {
        $exp = date('H:i:s', time() + (int)($json['expires_in'] ?? 3600));
        echo json_encode([
            'status'  => 'ok',
            'message' => 'Token berhasil diperoleh! Berlaku sampai jam ' . $exp,
            'preview' => substr($json['access_token'], 0, 30) . '...',
        ]);
    } else {
        $msg = $json['error_description'] ?? $json['message'] ?? $resp;
        echo json_encode(['status'=>'error','message'=>"Gagal (HTTP $httpCode): $msg"]);
    }
    exit;
}

// ── SIMPAN / EDIT DATA ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'test_token') {
    $id             = !empty($_POST['id']) ? (int)$_POST['id'] : null;
    $client_id      = trim($_POST['client_id']      ?? '');
    $client_secret  = trim($_POST['client_secret']  ?? '');
    $org_id         = trim($_POST['org_id']          ?? '');
    $base_url       = rtrim(trim($_POST['base_url']  ?? ''), '/');
    $orthanc_secret = trim($_POST['orthanc_secret']  ?? '');
    $medifix_url    = rtrim(trim($_POST['medifix_url'] ?? ''), '/');
    $debug_mode     = isset($_POST['debug_mode']) ? 1 : 0;

    if (!$client_id || !$org_id || !$base_url) {
        $error = '⚠ Client ID, Org ID, dan Base URL wajib diisi.';
    } else {
        try {
            if ($id) {
                if ($client_secret !== '') {
                    $pdo->prepare("UPDATE setting_satusehat SET client_id=?,client_secret=?,org_id=?,base_url=?,orthanc_secret=?,medifix_url=?,debug_mode=?,updated_at=NOW(),updated_by=? WHERE id=?")
                        ->execute([$client_id,$client_secret,$org_id,$base_url,$orthanc_secret,$medifix_url,$debug_mode,$nama,$id]);
                } else {
                    $pdo->prepare("UPDATE setting_satusehat SET client_id=?,org_id=?,base_url=?,orthanc_secret=?,medifix_url=?,debug_mode=?,updated_at=NOW(),updated_by=? WHERE id=?")
                        ->execute([$client_id,$org_id,$base_url,$orthanc_secret,$medifix_url,$debug_mode,$nama,$id]);
                }
                $success = '✔ Setting Satu Sehat berhasil diperbarui!';
            } else {
                $pdo->prepare("INSERT INTO setting_satusehat (client_id,client_secret,org_id,base_url,orthanc_secret,medifix_url,debug_mode,updated_at,updated_by) VALUES (?,?,?,?,?,?,?,NOW(),?)")
                    ->execute([$client_id,$client_secret,$org_id,$base_url,$orthanc_secret,$medifix_url,$debug_mode,$nama]);
                $success = '✔ Setting Satu Sehat berhasil disimpan!';
            }

            $cache = sys_get_temp_dir() . '/ss_token_cache.json';
            if (file_exists($cache)) unlink($cache);

            $data = $pdo->query("SELECT * FROM setting_satusehat LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $error = '⚠ Database Error: ' . $e->getMessage();
        }
    }
}

$isProduction = $data && str_contains($data['base_url'] ?? '', 'api-satusehat.kemkes.go.id');
$page_title   = 'Setting Satu Sehat — MediFix';

$extra_css = '
.info-list { display:flex; flex-direction:column; gap:12px; }
.info-row  { display:flex; align-items:center; gap:12px; padding:12px; background:#f8f9fc; border-radius:8px; border-left:4px solid #605ca8; }
.info-icon { width:40px; height:40px; background:linear-gradient(135deg,#605ca8,#4a4789); border-radius:8px; display:flex; align-items:center; justify-content:center; color:white; font-size:18px; flex-shrink:0; }
.info-content { flex:1; }
.info-label { font-size:11px; color:#718096; font-weight:600; text-transform:uppercase; letter-spacing:.5px; margin-bottom:2px; }
.info-value { font-size:14px; font-weight:700; color:#2d3748; word-break:break-all; }
.info-value.masked { letter-spacing:2px; }
.form-group label { font-weight:600; color:#2d3748; margin-bottom:5px; }
.form-group label i { color:#605ca8; margin-right:5px; }
.form-control { border:2px solid #e2e8f0; border-radius:5px; padding:8px 12px; }
.form-control:focus { border-color:#605ca8; box-shadow:0 0 0 3px rgba(96,92,168,.1); }
.btn-save-custom { background:linear-gradient(135deg,#605ca8,#4a4789); border:none; color:white; padding:10px 20px; border-radius:5px; font-weight:600; transition:all .3s; width:100%; }
.btn-save-custom:hover { transform:translateY(-2px); box-shadow:0 4px 12px rgba(96,92,168,.4); color:white; }
.btn-hapus-custom { background:linear-gradient(135deg,#ef4444,#dc2626); border:none; color:white; padding:5px 12px; border-radius:5px; font-weight:600; transition:all .3s; }
.btn-hapus-custom:hover { transform:translateY(-2px); box-shadow:0 4px 12px rgba(239,68,68,.4); color:white; }
.btn-test-token { background:linear-gradient(135deg,#00c0ef,#0073b7); border:none; color:#fff; padding:8px 16px; border-radius:5px; font-weight:600; cursor:pointer; transition:all .25s; font-size:13px; }
.btn-test-token:hover { transform:translateY(-2px); box-shadow:0 4px 12px rgba(0,115,183,.4); color:#fff; }
.btn-test-token:disabled { opacity:.6; cursor:not-allowed; transform:none; }
.empty-state { text-align:center; padding:40px 20px; color:#a0aec0; }
.empty-state i { font-size:48px; margin-bottom:15px; display:block; }
.empty-state p { font-size:14px; }
.toggle-secret { cursor:pointer; color:#605ca8; }
.badge-prod { background:#dff0d8; color:#3c763d; border:1px solid #c3e6cb; padding:3px 10px; border-radius:8px; font-size:12px; font-weight:700; }
.badge-stg  { background:#fcf8e3; color:#8a6d3b; border:1px solid #faebcc; padding:3px 10px; border-radius:8px; font-size:12px; font-weight:700; }
.badge-debug-on  { background:#f2dede; color:#a94442; border:1px solid #f5c6cb; padding:3px 10px; border-radius:8px; font-size:12px; font-weight:700; }
.badge-debug-off { background:#dff0d8; color:#3c763d; border:1px solid #c3e6cb; padding:3px 10px; border-radius:8px; font-size:12px; font-weight:700; }
.test-result { margin-top:10px; padding:10px 14px; border-radius:6px; font-size:13px; font-weight:600; display:none; }
.test-ok  { background:#dff0d8; color:#3c763d; border:1px solid #c3e6cb; }
.test-err { background:#f2dede; color:#a94442; border:1px solid #f5c6cb; }
.section-label { font-size:12px; font-weight:700; color:#605ca8; text-transform:uppercase; letter-spacing:.5px; margin-bottom:10px; margin-top:5px; border-bottom:2px dashed #e5e5e5; padding-bottom:5px; }
.callout-info-ss { background:#f0f0ff; border-left:4px solid #605ca8; padding:10px 14px; border-radius:4px; font-size:13px; color:#4a4789; margin-bottom:15px; }
';

$extra_js = '
<script>
function toggleVisibility(fieldId, iconId) {
    var f = document.getElementById(fieldId);
    var i = document.getElementById(iconId);
    if (f.type === "password") { f.type = "text";     i.className = "fa fa-eye-slash toggle-secret"; }
    else                       { f.type = "password"; i.className = "fa fa-eye toggle-secret"; }
}
function konfirmasiHapus(url) {
    if (confirm("⚠ Hapus setting Satu Sehat ini?\nSemua koneksi ke Satu Sehat akan berhenti!")) {
        window.location.href = url;
    }
}
function testToken() {
    var btn = document.getElementById("btnTestToken");
    var res = document.getElementById("testResult");
    var ci  = document.getElementById("f_client_id").value.trim();
    var cs  = document.getElementById("f_client_secret").value.trim();
    var bu  = document.getElementById("f_base_url").value.trim();
    if (!ci) { alert("Isi Client ID dulu"); return; }
    if (!cs) { alert("Isi Client Secret dulu"); return; }
    btn.disabled = true;
    btn.innerHTML = "<i class=\"fa fa-spinner fa-spin\"></i> Menguji...";
    res.style.display = "none";
    fetch(window.location.href, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({ action:"test_token", client_id:ci, client_secret:cs, base_url:bu })
    })
    .then(r => r.json())
    .then(resp => {
        btn.disabled = false;
        btn.innerHTML = "<i class=\"fa fa-plug\"></i> Test Koneksi Token";
        res.style.display = "block";
        if (resp.status === "ok") {
            res.className = "test-result test-ok";
            res.innerHTML = "<i class=\"fa fa-check-circle\"></i> " + resp.message
                          + "<br><small>Preview: " + resp.preview + "</small>";
        } else {
            res.className = "test-result test-err";
            res.innerHTML = "<i class=\"fa fa-times-circle\"></i> " + resp.message;
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = "<i class=\"fa fa-plug\"></i> Test Koneksi Token";
        res.style.display = "block";
        res.className = "test-result test-err";
        res.innerHTML = "<i class=\"fa fa-times-circle\"></i> Koneksi ke server gagal";
    });
}
document.addEventListener("DOMContentLoaded", function() {
    document.getElementById("f_env").addEventListener("change", function() {
        document.getElementById("f_base_url").value = this.value === "production"
            ? "https://api-satusehat.kemkes.go.id"
            : "https://api-satusehat-stg.dto.kemkes.go.id";
    });
});
</script>
';

include 'includes/header.php';
include 'includes/sidebar.php';
?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>
        <i class="fa fa-heartbeat" style="color:#605ca8;"></i> Setting Satu Sehat
      </h1>
      <ol class="breadcrumb">
        <li><a href="dashboard.php"><i class="fa fa-dashboard"></i> Home</a></li>
        <li><a href="#">Setting</a></li>
        <li class="active">Setting Satu Sehat</li>
      </ol>
    </section>

    <section class="content">

      <?php if ($success): ?>
      <div class="alert alert-success alert-dismissible">
        <button type="button" class="close" data-dismiss="alert">&times;</button>
        <i class="fa fa-check-circle"></i> <?= htmlspecialchars($success) ?>
      </div>
      <?php endif; ?>

      <?php if ($error): ?>
      <div class="alert alert-danger alert-dismissible">
        <button type="button" class="close" data-dismiss="alert">&times;</button>
        <i class="fa fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
      </div>
      <?php endif; ?>

      <div class="row">

        <!-- ═══ INFO CARD Kiri ═══ -->
        <div class="col-md-5">
          <div class="box">
            <div class="box-header with-border">
              <h3 class="box-title"><i class="fa fa-info-circle"></i> Konfigurasi Aktif</h3>
              <?php if ($data): ?>
              <div class="box-tools pull-right">
                <button class="btn btn-danger btn-xs btn-hapus-custom"
                        onclick="konfirmasiHapus('setting_satusehat.php?action=hapus&id=<?= $data['id'] ?>')">
                  <i class="fa fa-trash"></i> Hapus Data
                </button>
              </div>
              <?php endif; ?>
            </div>
            <div class="box-body">
              <?php if ($data): ?>
              <div class="info-list">

                <div class="info-row">
                  <div class="info-icon"><i class="fa fa-globe"></i></div>
                  <div class="info-content">
                    <div class="info-label">Environment</div>
                    <div class="info-value">
                      <?php if ($isProduction): ?>
                        <span class="badge-prod"><i class="fa fa-check-circle"></i> PRODUCTION</span>
                      <?php else: ?>
                        <span class="badge-stg"><i class="fa fa-flask"></i> STAGING / DEV</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>

                <div class="info-row">
                  <div class="info-icon"><i class="fa fa-key"></i></div>
                  <div class="info-content">
                    <div class="info-label">Client ID</div>
                    <div class="info-value" style="font-size:12px;">
                      <?= htmlspecialchars(mb_strimwidth($data['client_id'], 0, 38, '…')) ?>
                    </div>
                  </div>
                </div>

                <div class="info-row">
                  <div class="info-icon"><i class="fa fa-lock"></i></div>
                  <div class="info-content">
                    <div class="info-label">Client Secret</div>
                    <div class="info-value masked">••••••••••••••••••••</div>
                  </div>
                </div>

                <div class="info-row">
                  <div class="info-icon"><i class="fa fa-hospital-o"></i></div>
                  <div class="info-content">
                    <div class="info-label">Organization ID</div>
                    <div class="info-value"><?= htmlspecialchars($data['org_id']) ?></div>
                  </div>
                </div>

                <div class="info-row">
                  <div class="info-icon"><i class="fa fa-link"></i></div>
                  <div class="info-content">
                    <div class="info-label">Base URL</div>
                    <div class="info-value" style="font-size:11px;">
                      <?= htmlspecialchars($data['base_url']) ?>
                    </div>
                  </div>
                </div>

                <div class="info-row">
                  <div class="info-icon"><i class="fa fa-shield"></i></div>
                  <div class="info-content">
                    <div class="info-label">Orthanc Secret</div>
                    <div class="info-value masked">
                      <?= !empty($data['orthanc_secret'])
                          ? '••••••••••••'
                          : '<span style="color:#aaa;font-style:italic;letter-spacing:0;">Belum diisi</span>' ?>
                    </div>
                  </div>
                </div>

                <div class="info-row">
                  <div class="info-icon"><i class="fa fa-bug"></i></div>
                  <div class="info-content">
                    <div class="info-label">Debug Mode</div>
                    <div class="info-value">
                      <?php if ($data['debug_mode']): ?>
                        <span class="badge-debug-on"><i class="fa fa-toggle-on"></i> ON</span>
                        <small class="text-danger"> &mdash; Matikan di production!</small>
                      <?php else: ?>
                        <span class="badge-debug-off"><i class="fa fa-toggle-off"></i> OFF</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>

                <div class="info-row">
                  <div class="info-icon"><i class="fa fa-clock-o"></i></div>
                  <div class="info-content">
                    <div class="info-label">Terakhir Diperbarui</div>
                    <div class="info-value" style="font-size:13px;">
                      <?= $data['updated_at'] ? date('d M Y H:i', strtotime($data['updated_at'])) : '-' ?>
                      <?= $data['updated_by']
                          ? '<br><small style="color:#aaa;">oleh '.$data['updated_by'].'</small>'
                          : '' ?>
                    </div>
                  </div>
                </div>

              </div>
              <?php else: ?>
              <div class="empty-state">
                <i class="fa fa-heartbeat" style="color:#d6d0f8;"></i>
                <p><strong>Belum ada konfigurasi Satu Sehat.</strong><br>
                Silakan isi form di sebelah kanan.</p>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- ═══ FORM CARD Kanan ═══ -->
        <div class="col-md-7">
          <div class="box">
            <div class="box-header with-border">
              <h3 class="box-title">
                <i class="fa fa-<?= $data ? 'edit' : 'plus-circle' ?>"></i>
                <?= $data ? 'Edit Konfigurasi Satu Sehat' : 'Tambah Konfigurasi Satu Sehat' ?>
              </h3>
            </div>
            <div class="box-body">

              <div class="callout-info-ss">
                <i class="fa fa-info-circle"></i>
                Kredensial didapat dari <strong>portal.satusehat.kemkes.go.id</strong>
                &rarr; menu <strong>Aplikasi &rarr; Detail Aplikasi</strong>.
              </div>

              <form method="POST">
                <input type="hidden" name="id" value="<?= htmlspecialchars($data['id'] ?? '') ?>">

                <!-- Environment -->
                <div class="section-label"><i class="fa fa-globe"></i> Environment &amp; Endpoint</div>
                <div class="row">
                  <div class="col-md-4">
                    <div class="form-group">
                      <label><i class="fa fa-globe"></i> Environment</label>
                      <select id="f_env" class="form-control">
                        <option value="production" <?= ($isProduction || !$data) ? 'selected' : '' ?>>Production</option>
                        <option value="staging"    <?= ($data && !$isProduction) ? 'selected' : '' ?>>Staging / Dev</option>
                      </select>
                      <small class="text-muted">Pilih &rarr; URL otomatis terisi</small>
                    </div>
                  </div>
                  <div class="col-md-8">
                    <div class="form-group">
                      <label><i class="fa fa-link"></i> Base URL <span class="text-danger">*</span></label>
                      <input type="text" id="f_base_url" name="base_url" class="form-control"
                             value="<?= htmlspecialchars($data['base_url'] ?? 'https://api-satusehat.kemkes.go.id') ?>"
                             required>
                    </div>
                  </div>
                </div>

                <!-- Kredensial OAuth -->
                <div class="section-label"><i class="fa fa-key"></i> Kredensial OAuth2</div>

                <div class="form-group">
                  <label><i class="fa fa-key"></i> Client ID <span class="text-danger">*</span></label>
                  <input type="text" id="f_client_id" name="client_id" class="form-control"
                         value="<?= htmlspecialchars($data['client_id'] ?? '') ?>"
                         placeholder="Client ID dari portal Satu Sehat" required>
                </div>

                <div class="row">
                  <div class="col-md-7">
                    <div class="form-group">
                      <label><i class="fa fa-lock"></i> Client Secret</label>
                      <div class="input-group">
                        <input type="password" id="f_client_secret" name="client_secret" class="form-control"
                               value="<?= htmlspecialchars($data['client_secret'] ?? '') ?>"
                               placeholder="<?= $data ? 'Kosongkan jika tidak ingin mengubah' : 'Client Secret dari portal' ?>">
                        <span class="input-group-addon"
                              onclick="toggleVisibility('f_client_secret','icon_cs')"
                              style="cursor:pointer;">
                          <i id="icon_cs" class="fa fa-eye toggle-secret"></i>
                        </span>
                      </div>
                      <?php if ($data): ?>
                      <small class="text-muted">Kosongkan jika tidak ingin mengubah</small>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="col-md-5">
                    <div class="form-group">
                      <label><i class="fa fa-hospital-o"></i> Org ID <span class="text-danger">*</span></label>
                      <input type="text" name="org_id" class="form-control"
                             value="<?= htmlspecialchars($data['org_id'] ?? '') ?>"
                             placeholder="Organization ID" required>
                    </div>
                  </div>
                </div>

                <!-- Test Token -->
                <div class="form-group">
                  <button type="button" id="btnTestToken" class="btn-test-token" onclick="testToken()">
                    <i class="fa fa-plug"></i> Test Koneksi Token
                  </button>
                  <div id="testResult" class="test-result"></div>
                </div>

                <!-- Orthanc & MediFix -->
                <div class="section-label" style="margin-top:15px;">
                  <i class="fa fa-server"></i> Konfigurasi Orthanc &amp; MediFix
                </div>
                <div class="row">
                  <div class="col-md-6">
                    <div class="form-group">
                      <label><i class="fa fa-shield"></i> Orthanc Secret Key</label>
                      <div class="input-group">
                        <input type="password" id="f_orthanc_secret" name="orthanc_secret" class="form-control"
                               value="<?= htmlspecialchars($data['orthanc_secret'] ?? '') ?>"
                               placeholder="Secret key di Lua script Orthanc">
                        <span class="input-group-addon"
                              onclick="toggleVisibility('f_orthanc_secret','icon_os')"
                              style="cursor:pointer;">
                          <i id="icon_os" class="fa fa-eye toggle-secret"></i>
                        </span>
                      </div>
                      <small class="text-muted">Harus sama dengan <code>SECRET_KEY</code> di Lua script</small>
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="form-group">
                      <label><i class="fa fa-globe"></i> URL MediFix</label>
                      <input type="text" name="medifix_url" class="form-control"
                             value="<?= htmlspecialchars($data['medifix_url'] ?? '') ?>"
                             placeholder="http://IP_SERVER/medifix">
                      <small class="text-muted">URL yang bisa diakses dari server Orthanc</small>
                    </div>
                  </div>
                </div>

                <!-- Debug Mode -->
                <div class="section-label"><i class="fa fa-bug"></i> Mode Debug</div>
                <div class="form-group">
                  <label class="checkbox-inline">
                    <input type="checkbox" name="debug_mode" value="1"
                           <?= !empty($data['debug_mode']) ? 'checked' : '' ?>>
                    &nbsp; Aktifkan Debug Mode
                    <small class="text-danger">(matikan di production!)</small>
                  </label>
                  <div>
                    <small class="text-muted">
                      Jika aktif, semua request &amp; response ke Satu Sehat dicatat di PHP error_log
                    </small>
                  </div>
                </div>

                <div class="form-group" style="margin-top:20px;">
                  <button type="submit" class="btn btn-primary btn-save-custom">
                    <i class="fa fa-save"></i>
                    <?= $data ? 'Simpan Perubahan' : 'Simpan Setting' ?>
                  </button>
                </div>

              </form>
            </div>
          </div>
        </div>

      </div>
    </section>
  </div>

<?php
echo $extra_js;
include 'includes/footer.php';
?>