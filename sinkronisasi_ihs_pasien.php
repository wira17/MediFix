<?php
/**
 * sinkronisasi_ihs_pasien.php
 * Halaman sinkronisasi IHS Number pasien dari Satu Sehat
 * Taruh di root MediFix
 */

session_start();
include 'koneksi.php';
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// ── AJAX ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['sync_satu', 'sync_semua', 'cek_status'])) {
        include __DIR__ . '/api/sync_ihs_pasien.php';
        exit;
    }
}

// ── Data statistik ────────────────────────────────────────────────
try {
    $stats = $pdo_simrs->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN ihs_number IS NOT NULL AND ihs_number != '' THEN 1 ELSE 0 END) AS ditemukan,
            SUM(CASE WHEN status_sync = 'tidak_ditemukan' THEN 1 ELSE 0 END) AS tidak_ditemukan,
            SUM(CASE WHEN status_sync = 'error' THEN 1 ELSE 0 END) AS error_sync,
            SUM(CASE WHEN status_sync = 'pending' OR ihs_number IS NULL THEN 1 ELSE 0 END) AS pending
        FROM medifix_ss_pasien
    ")->fetch(PDO::FETCH_ASSOC);

    // ── Data tabel pasien ─────────────────────────────────────────
    $cari        = $_GET['cari']   ?? '';
    $filter_sync = $_GET['sync']   ?? '';
    $limit       = (int)($_GET['limit'] ?? 20);
    $page        = max(1, (int)($_GET['page'] ?? 1));
    $offset      = ($page - 1) * $limit;

    $wheres = ['1=1'];
    $params = [];

    if (!empty($cari)) {
        $wheres[] = "(p.nm_pasien LIKE ? OR p.no_rkm_medis LIKE ? OR p.no_ktp LIKE ? OR m.ihs_number LIKE ?)";
        $params   = array_merge($params, ["%$cari%","%$cari%","%$cari%","%$cari%"]);
    }
    if ($filter_sync === 'ditemukan') {
        $wheres[] = "m.ihs_number IS NOT NULL AND m.ihs_number != ''";
    } elseif ($filter_sync === 'pending') {
        $wheres[] = "(m.ihs_number IS NULL OR m.ihs_number = '') AND m.status_sync = 'pending'";
    } elseif ($filter_sync === 'tidak_ditemukan') {
        $wheres[] = "m.status_sync = 'tidak_ditemukan'";
    } elseif ($filter_sync === 'error') {
        $wheres[] = "m.status_sync = 'error'";
    }

    $where_sql = "WHERE " . implode(" AND ", $wheres);

    $total = (int)$pdo_simrs->prepare("
        SELECT COUNT(*) FROM medifix_ss_pasien m
        JOIN pasien p ON m.no_rkm_medis = p.no_rkm_medis
        $where_sql
    ")->execute($params) ? $pdo_simrs->prepare("
        SELECT COUNT(*) FROM medifix_ss_pasien m
        JOIN pasien p ON m.no_rkm_medis = p.no_rkm_medis
        $where_sql
    ")->fetchColumn() : 0;

    // Ulangi query dengan benar
    $stmtCount = $pdo_simrs->prepare("SELECT COUNT(*) FROM medifix_ss_pasien m JOIN pasien p ON m.no_rkm_medis = p.no_rkm_medis $where_sql");
    $stmtCount->execute($params);
    $total       = (int)$stmtCount->fetchColumn();
    $total_pages = max(1, ceil($total / $limit));

    $stmtData = $pdo_simrs->prepare("
        SELECT m.no_rkm_medis, m.ihs_number, m.nik, m.tgl_sync, m.status_sync, m.error_msg,
               p.nm_pasien, p.no_ktp, p.tgl_lahir, p.jk
        FROM medifix_ss_pasien m
        JOIN pasien p ON m.no_rkm_medis = p.no_rkm_medis
        $where_sql
        ORDER BY m.status_sync ASC, p.nm_pasien ASC
        LIMIT " . intval($limit) . " OFFSET " . intval($offset)
    );
    $stmtData->execute($params);
    $data    = $stmtData->fetchAll(PDO::FETCH_ASSOC);
    $dbError = null;
} catch (Exception $e) {
    $stats = ['total'=>0,'ditemukan'=>0,'tidak_ditemukan'=>0,'error_sync'=>0,'pending'=>0];
    $data  = []; $total = 0; $total_pages = 1;
    $dbError = $e->getMessage();
}

$pct = ($stats['total'] > 0) ? round(($stats['ditemukan'] / $stats['total']) * 100) : 0;
$page_title = 'Sinkronisasi IHS Pasien — Satu Sehat';

$extra_css = '
.btn-sync{width:30px;height:30px;border-radius:6px;padding:0;display:inline-flex;align-items:center;justify-content:center;border:none;cursor:pointer;transition:all .25s;font-size:12px;background:#605ca8;color:#fff}
.btn-sync:hover{background:#4a4789;transform:scale(1.1)}
.btn-sync:disabled{opacity:.5;cursor:not-allowed;transform:none}
.btn-sync.found{background:#00a65a}.btn-sync.notfound{background:#f39c12}.btn-sync.err{background:#dd4b39}
.btn-sync.spin i{animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

.badge-found{background:#dff0d8;color:#3c763d;border:1px solid #c3e6cb;padding:3px 9px;border-radius:10px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px}
.badge-notfound{background:#fcf8e3;color:#8a6d3b;border:1px solid #faebcc;padding:3px 9px;border-radius:10px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px}
.badge-err{background:#f2dede;color:#a94442;border:1px solid #f5c6cb;padding:3px 9px;border-radius:10px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px}
.badge-pend{background:#f5f5f5;color:#777;border:1px solid #ddd;padding:3px 9px;border-radius:10px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px}

.ihs-lbl{font-family:"Courier New",monospace;font-size:11px;color:#555;word-break:break-all}
.nm-pasien{font-weight:700;color:#333;font-size:13px}
.rm-lbl{font-size:10.5px;color:#aaa;font-family:"Courier New",monospace}
.nik-lbl{font-size:11px;color:#666;font-family:"Courier New",monospace}

.info-bar{display:flex;gap:16px;padding:8px 15px;background:#f0f0ff;border-bottom:1px solid #e5e5e5;font-size:12px;flex-wrap:wrap;align-items:center}
.ibs-item{display:flex;align-items:center;gap:5px;color:#555}
.ibs-val{font-weight:700;font-size:14px}
.ibs-sep{color:#ddd}

.prog-row{display:flex;align-items:center;gap:10px;font-size:12px;padding:8px 15px;background:#f9f9f9;border-bottom:1px solid #eee}
.prog-bar{flex:1;height:8px;border-radius:4px;background:#e5e5e5;overflow:hidden}
.prog-fill{height:100%;border-radius:4px;background:linear-gradient(90deg,#605ca8,#00a65a);transition:width .6s}

.btn-sync-all{background:linear-gradient(135deg,#605ca8,#4a4789);border:none;color:#fff;padding:6px 14px;border-radius:5px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .25s}
.btn-sync-all:hover{transform:translateY(-2px);box-shadow:0 4px 14px rgba(96,92,168,.4);color:#fff}
.btn-sync-all:disabled{opacity:.6;cursor:not-allowed;transform:none}

.tbl-ihs thead tr th{background:#605ca8;color:#fff!important;white-space:nowrap;font-size:12px}
.tbl-ihs tbody td{vertical-align:middle}

#toast-container{position:fixed;bottom:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.toast-msg{padding:10px 18px;border-radius:6px;font-size:13px;font-weight:600;color:#fff;display:flex;align-items:center;gap:8px;box-shadow:0 4px 12px rgba(0,0,0,.2);pointer-events:auto;animation:toastIn .3s ease}
.toast-success{background:#00a65a}.toast-error{background:#dd4b39}.toast-info{background:#00c0ef}.toast-warn{background:#f39c12}
@keyframes toastIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}

.progress-batch{display:none;padding:10px 15px;background:#fffdf0;border:1px solid #faebcc;border-radius:6px;margin-top:10px}
.progress-batch.show{display:block}
';

$extra_js = '
function showToast(msg, type) {
    type = type || "success";
    const c = document.getElementById("toast-container");
    if (!c) return;
    const icons = {success:"check-circle",error:"times-circle",info:"info-circle",warn:"exclamation-triangle"};
    const d = document.createElement("div");
    d.className = "toast-msg toast-" + type;
    d.innerHTML = `<i class="fa fa-${icons[type]||"info-circle"}"></i> ${msg}`;
    c.appendChild(d);
    setTimeout(() => d.remove(), 4000);
}

function syncSatu(noRkm, btnEl) {
    btnEl.disabled = true;
    const orig = btnEl.innerHTML;
    btnEl.classList.add("spin");
    btnEl.innerHTML = `<i class="fa fa-spinner"></i>`;

    fetch(window.location.href, {
        method: "POST",
        headers: {"Content-Type":"application/x-www-form-urlencoded"},
        body: new URLSearchParams({action:"sync_satu", no_rkm_medis: noRkm})
    })
    .then(r => r.json())
    .then(resp => {
        btnEl.classList.remove("spin");
        btnEl.disabled = false;
        const row = btnEl.closest("tr");

        if (resp.status === "ok") {
            btnEl.classList.add("found");
            btnEl.innerHTML = `<i class="fa fa-check"></i>`;
            btnEl.title = "IHS: " + resp.ihs_number;
            showToast("IHS ditemukan: " + resp.ihs_number, "success");
            if (row) {
                const ihsCell = row.querySelector(".ihs-cell");
                if (ihsCell) ihsCell.innerHTML = `<span class="ihs-lbl">${resp.ihs_number}</span>`;
                const badge = row.querySelector(".badge-status");
                if (badge) { badge.className = "badge-status badge-found"; badge.innerHTML = `<i class="fa fa-check-circle"></i> Ditemukan`; }
            }
            // Update counter
            const pd = document.getElementById("cntPending");
            const fd = document.getElementById("cntFound");
            if (pd) pd.textContent = Math.max(0, parseInt(pd.textContent) - 1);
            if (fd) fd.textContent = parseInt(fd.textContent || 0) + 1;
        } else if (resp.status === "not_found") {
            btnEl.classList.add("notfound");
            btnEl.innerHTML = `<i class="fa fa-question"></i>`;
            btnEl.title = "NIK tidak terdaftar di Satu Sehat";
            showToast("NIK tidak ditemukan di Satu Sehat untuk " + (resp.nm_pasien || noRkm), "warn");
            if (row) {
                const badge = row.querySelector(".badge-status");
                if (badge) { badge.className = "badge-status badge-notfound"; badge.innerHTML = `<i class="fa fa-question-circle"></i> Tidak ditemukan`; }
            }
        } else {
            btnEl.classList.add("err");
            btnEl.innerHTML = `<i class="fa fa-times"></i>`;
            btnEl.title = "Error: " + (resp.message || "");
            showToast("Error: " + (resp.message || ""), "error");
        }
    })
    .catch(() => {
        btnEl.disabled = false; btnEl.innerHTML = orig; btnEl.classList.remove("spin");
        showToast("Koneksi gagal", "error");
    });
}

let isSyncing = false;
function syncSemua() {
    if (isSyncing) return;
    const sisa = parseInt(document.getElementById("cntPending")?.textContent || 0);
    if (sisa === 0) { showToast("Semua pasien sudah tersync!", "info"); return; }
    if (!confirm("Sync " + sisa + " pasien yang belum punya IHS Number?\n\nProses ini akan memanggil API Satu Sehat satu per satu.\nMungkin butuh beberapa menit.")) return;

    isSyncing = true;
    const btn = document.getElementById("btnSyncSemua");
    if (btn) { btn.disabled = true; btn.innerHTML = `<i class="fa fa-spinner fa-spin"></i> Menyinkronkan...`; }

    const prog = document.getElementById("progressBatch");
    if (prog) prog.classList.add("show");

    let totalProses = 0, totalDitemukan = 0, totalTidak = 0, totalError = 0;

    function batch() {
        fetch(window.location.href, {
            method: "POST",
            headers: {"Content-Type":"application/x-www-form-urlencoded"},
            body: new URLSearchParams({action:"sync_semua", limit: 20})
        })
        .then(r => r.json())
        .then(resp => {
            if (resp.status === "ok") {
                totalProses    += resp.jumlah || 0;
                totalDitemukan += resp.ditemukan || 0;
                totalTidak     += resp.tidak_ditemukan || 0;
                totalError     += resp.error || 0;

                const progText = document.getElementById("progText");
                if (progText) progText.innerHTML =
                    `Diproses: <b>${totalProses}</b> | Ditemukan: <b style="color:#00a65a">${totalDitemukan}</b> | Tidak ditemukan: <b style="color:#f39c12">${totalTidak}</b> | Error: <b style="color:#dd4b39">${totalError}</b> | Sisa: <b>${resp.sisa || 0}</b>`;

                if (resp.sisa > 0 && resp.jumlah > 0) {
                    setTimeout(batch, 500); // lanjut batch berikutnya
                } else {
                    isSyncing = false;
                    if (btn) { btn.disabled = false; btn.innerHTML = `<i class="fa fa-refresh"></i> Sync Semua Pending`; }
                    showToast(`Selesai! ${totalDitemukan} IHS ditemukan, ${totalTidak} tidak ditemukan, ${totalError} error.`, totalError > 0 ? "warn" : "success");
                    setTimeout(() => location.reload(), 2000);
                }
            } else {
                isSyncing = false;
                if (btn) { btn.disabled = false; btn.innerHTML = `<i class="fa fa-refresh"></i> Sync Semua Pending`; }
                showToast("Error: " + (resp.message || ""), "error");
            }
        })
        .catch(() => {
            isSyncing = false;
            if (btn) { btn.disabled = false; btn.innerHTML = `<i class="fa fa-refresh"></i> Sync Semua Pending`; }
            showToast("Koneksi gagal", "error");
        });
    }
    batch();
}
';

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div id="toast-container"></div>

<div class="content-wrapper">
  <section class="content-header">
    <h1>
      <i class="fa fa-users" style="color:#605ca8;"></i>
      Sinkronisasi IHS Pasien
      <small>Satu Sehat &mdash; Ambil IHS Number dari NIK</small>
    </h1>
    <ol class="breadcrumb">
      <li><a href="dashboard.php"><i class="fa fa-dashboard"></i> Home</a></li>
      <li>Satu Sehat</li>
      <li class="active">Sinkronisasi IHS Pasien</li>
    </ol>
  </section>

  <section class="content">

    <?php if ($dbError): ?>
    <div class="callout callout-danger"><h4><i class="fa fa-ban"></i> Error</h4><p><?= htmlspecialchars($dbError) ?></p></div>
    <?php endif; ?>

    <div class="row">
      <div class="col-xs-12">

        <!-- Info Box -->
        <div class="callout callout-info">
          <h4><i class="fa fa-info-circle"></i> Cara Kerja</h4>
          <p>Sistem akan mengambil <strong>NIK</strong> dari data pasien SIMRS, lalu mencari <strong>IHS Number</strong> ke API Satu Sehat. IHS Number disimpan di tabel <code>medifix_ss_pasien</code> — tidak mengubah tabel Khanza.</p>
        </div>

        <!-- Statistik -->
        <div class="row">
          <div class="col-xs-6 col-md-3">
            <div class="info-box">
              <span class="info-box-icon bg-purple"><i class="fa fa-users"></i></span>
              <div class="info-box-content">
                <span class="info-box-text">Total Pasien</span>
                <span class="info-box-number"><?= number_format($stats['total']) ?></span>
              </div>
            </div>
          </div>
          <div class="col-xs-6 col-md-3">
            <div class="info-box">
              <span class="info-box-icon bg-green"><i class="fa fa-check-circle"></i></span>
              <div class="info-box-content">
                <span class="info-box-text">IHS Ditemukan</span>
                <span class="info-box-number" id="cntFound"><?= number_format($stats['ditemukan']) ?></span>
              </div>
            </div>
          </div>
          <div class="col-xs-6 col-md-3">
            <div class="info-box">
              <span class="info-box-icon bg-yellow"><i class="fa fa-clock-o"></i></span>
              <div class="info-box-content">
                <span class="info-box-text">Belum Sync</span>
                <span class="info-box-number" id="cntPending"><?= number_format($stats['pending']) ?></span>
              </div>
            </div>
          </div>
          <div class="col-xs-6 col-md-3">
            <div class="info-box">
              <span class="info-box-icon bg-red"><i class="fa fa-times-circle"></i></span>
              <div class="info-box-content">
                <span class="info-box-text">Tidak Ditemukan</span>
                <span class="info-box-number"><?= number_format($stats['tidak_ditemukan']) ?></span>
              </div>
            </div>
          </div>
        </div>

        <!-- Progress -->
        <div class="prog-row">
          <span style="width:120px;font-size:12px;color:#555;flex-shrink:0;"><i class="fa fa-bar-chart"></i> Progress Sync</span>
          <div class="prog-bar"><div class="prog-fill" style="width:<?= $pct ?>%;"></div></div>
          <span style="width:45px;text-align:right;font-weight:700;color:#555;"><?= $pct ?>%</span>
        </div>

        <!-- Tabel -->
        <div class="box box-primary">
          <div class="box-header with-border">
            <h3 class="box-title"><i class="fa fa-table"></i> Data Pasien <span class="badge" style="background:#605ca8;"><?= number_format($total) ?></span></h3>
            <div class="box-tools pull-right" style="display:flex;gap:8px;align-items:center;">
              <button id="btnSyncSemua" onclick="syncSemua()" class="btn-sync-all">
                <i class="fa fa-refresh"></i> Sync Semua Pending
                <?php if ($stats['pending'] > 0): ?>
                <span class="badge" style="background:#dd4b39;"><?= number_format($stats['pending']) ?></span>
                <?php endif; ?>
              </button>
              <a href="sinkronisasi_ihs_pasien.php" class="btn btn-default btn-sm"><i class="fa fa-refresh"></i></a>
            </div>
          </div>

          <!-- Progress batch -->
          <div id="progressBatch" class="progress-batch" style="margin:10px 15px;">
            <i class="fa fa-spinner fa-spin"></i> Sedang menyinkronkan...
            <div id="progText" style="margin-top:5px;font-size:12px;"></div>
          </div>

          <!-- Filter -->
          <div class="box-body" style="padding:10px 15px;border-bottom:1px solid #eee;">
            <form method="GET" class="form-inline" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
              <div class="form-group">
                <input type="text" name="cari" class="form-control" placeholder="Nama / No. RM / NIK / IHS…" value="<?= htmlspecialchars($cari) ?>" style="width:240px;">
              </div>
              <div class="form-group">
                <select name="sync" class="form-control">
                  <option value=""                <?= $filter_sync===''               ?'selected':'' ?>>Semua Status</option>
                  <option value="ditemukan"       <?= $filter_sync==='ditemukan'       ?'selected':'' ?>>IHS Ditemukan</option>
                  <option value="pending"         <?= $filter_sync==='pending'         ?'selected':'' ?>>Belum Sync</option>
                  <option value="tidak_ditemukan" <?= $filter_sync==='tidak_ditemukan' ?'selected':'' ?>>Tidak Ditemukan</option>
                  <option value="error"           <?= $filter_sync==='error'           ?'selected':'' ?>>Error</option>
                </select>
              </div>
              <div class="form-group">
                <select name="limit" class="form-control">
                  <?php foreach ([20,50,100,200] as $l): ?>
                  <option value="<?=$l?>" <?=$limit==$l?'selected':''?>><?=$l?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button type="submit" class="btn btn-primary btn-sm"><i class="fa fa-search"></i> Cari</button>
              <a href="sinkronisasi_ihs_pasien.php" class="btn btn-default btn-sm"><i class="fa fa-refresh"></i> Reset</a>
            </form>
          </div>

          <div class="box-body" style="padding:0;">
            <?php if (!empty($data)): ?>
            <div class="table-responsive">
              <table class="table table-bordered table-striped table-hover tbl-ihs" style="margin-bottom:0;font-size:12.5px;">
                <thead>
                  <tr>
                    <th width="36" class="text-center">#</th>
                    <th width="38" class="text-center">Sync</th>
                    <th width="120">No. RM</th>
                    <th width="180">Nama Pasien</th>
                    <th width="160">NIK</th>
                    <th>IHS Number</th>
                    <th width="120">Tgl Sync</th>
                    <th width="140" class="text-center">Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php $no = $offset + 1; foreach ($data as $r):
                      $found   = !empty($r['ihs_number']);
                      $status  = $r['status_sync'] ?? 'pending';
                  ?>
                  <tr class="<?= $found ? 'row-sent' : '' ?>" style="<?= $found ? 'background:#f0fff4!important' : '' ?>">

                    <td class="text-center" style="color:#aaa;font-size:11px;"><?= $no++ ?></td>

                    <td class="text-center">
                      <button class="btn-sync <?= $found?'found':($status==='tidak_ditemukan'?'notfound':($status==='error'?'err':'')) ?>"
                              onclick="syncSatu('<?= addslashes($r['no_rkm_medis']) ?>',this)"
                              title="<?= $found?'Sync Ulang':'Sync IHS Number' ?>">
                        <i class="fa fa-<?= $found?'refresh':($status==='tidak_ditemukan'?'question':($status==='error'?'times':'search')) ?>"></i>
                      </button>
                    </td>

                    <td>
                      <div class="rm-lbl" style="font-size:12px;color:#605ca8;font-weight:700;"><?= htmlspecialchars($r['no_rkm_medis']) ?></div>
                    </td>

                    <td>
                      <div class="nm-pasien"><?= htmlspecialchars($r['nm_pasien']) ?></div>
                      <div class="rm-lbl">
                        <?= !empty($r['tgl_lahir']) && $r['tgl_lahir'] !== '0000-00-00'
                            ? (new DateTime())->diff(new DateTime($r['tgl_lahir']))->y . ' th'
                            : '' ?>
                        <?= !empty($r['jk']) ? ' · '.htmlspecialchars($r['jk']) : '' ?>
                      </div>
                    </td>

                    <td>
                      <span class="nik-lbl"><?= htmlspecialchars($r['no_ktp'] ?: '-') ?></span>
                      <?php if (empty($r['no_ktp'])): ?>
                      <div style="font-size:10px;color:#dd4b39;"><i class="fa fa-warning"></i> NIK kosong</div>
                      <?php endif; ?>
                    </td>

                    <td class="ihs-cell">
                      <?php if ($found): ?>
                        <span class="ihs-lbl"><?= htmlspecialchars($r['ihs_number']) ?></span>
                      <?php elseif ($status === 'tidak_ditemukan'): ?>
                        <span style="font-size:11px;color:#f39c12;"><i class="fa fa-question-circle"></i> NIK tidak terdaftar</span>
                      <?php elseif ($status === 'error'): ?>
                        <span style="font-size:11px;color:#dd4b39;" title="<?= htmlspecialchars($r['error_msg']??'') ?>"><i class="fa fa-times-circle"></i> Error</span>
                      <?php else: ?>
                        <span style="font-size:11px;color:#aaa;"><i class="fa fa-minus-circle"></i> Belum disync</span>
                      <?php endif; ?>
                    </td>

                    <td>
                      <?php if (!empty($r['tgl_sync'])): ?>
                        <div style="font-size:11px;color:#555;"><?= date('d/m/Y H:i', strtotime($r['tgl_sync'])) ?></div>
                      <?php else: ?>
                        <span style="font-size:11px;color:#aaa;">-</span>
                      <?php endif; ?>
                    </td>

                    <td class="text-center">
                      <span class="badge-status <?= $found?'badge-found':($status==='tidak_ditemukan'?'badge-notfound':($status==='error'?'badge-err':'badge-pend')) ?>">
                        <i class="fa fa-<?= $found?'check-circle':($status==='tidak_ditemukan'?'question-circle':($status==='error'?'times-circle':'clock-o')) ?>"></i>
                        <?= $found?'Ditemukan':($status==='tidak_ditemukan'?'Tidak Ditemukan':($status==='error'?'Error':'Pending')) ?>
                      </span>
                    </td>

                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <?php if ($total_pages > 1): ?>
            <div class="box-footer clearfix">
              <div class="pull-left" style="font-size:13px;color:#666;padding:7px 0;">
                Menampilkan <strong><?= number_format($offset+1) ?></strong>–<strong><?= number_format(min($offset+$limit,$total)) ?></strong> dari <strong><?= number_format($total) ?></strong>
              </div>
              <ul class="pagination pagination-sm no-margin pull-right">
                <?php
                $qBase = "cari=".urlencode($cari)."&sync=".urlencode($filter_sync)."&limit=$limit";
                if ($page>1): ?><li><a href="?page=<?=$page-1?>&<?=$qBase?>">«</a></li><?php endif;
                for ($i=max(1,$page-3); $i<=min($total_pages,$page+3); $i++):?>
                <li <?=$i==$page?'class="active"':''?>><a href="?page=<?=$i?>&<?=$qBase?>"><?=$i?></a></li>
                <?php endfor;
                if ($page<$total_pages): ?><li><a href="?page=<?=$page+1?>&<?=$qBase?>">»</a></li><?php endif; ?>
              </ul>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <div style="padding:40px;text-align:center;">
              <i class="fa fa-users" style="font-size:48px;color:#ddd;display:block;margin-bottom:12px;"></i>
              <h4 style="color:#aaa;font-weight:400;">Tidak ada data</h4>
            </div>
            <?php endif; ?>
          </div>
        </div>

      </div>
    </div>
  </section>
</div>

<?php include 'includes/footer.php'; ?>