<?php
session_start();
include 'koneksi.php';
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// ── Serve log ─────────────────────────────────────────────────────
if (isset($_GET['_log']) && $_GET['_log'] === 'ihs') {
    header('Content-Type: text/plain; charset=utf-8');
    $logFile = __DIR__ . '/logs/ihs_' . date('Y-m') . '.log';
    echo file_exists($logFile)
        ? implode("\n", array_slice(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -500))
        : '(Log belum ada)';
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

// ── Filter & Pagination ──────────────────────────────────────────
$tgl_dari    = $_GET['tgl_dari']   ?? date('Y-m-d');
$tgl_sampai  = $_GET['tgl_sampai'] ?? date('Y-m-d');
$cari        = $_GET['cari']       ?? '';
$filter_sync = $_GET['sync']       ?? '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_dari))   $tgl_dari   = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_sampai)) $tgl_sampai = date('Y-m-d');
if ($tgl_sampai < $tgl_dari) $tgl_sampai = $tgl_dari;
$limit  = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 20;
$page   = isset($_GET['page'])  ? max(1, intval($_GET['page']))  : 1;
$offset = ($page - 1) * $limit;

$tgl_dari_fmt  = date('d F Y', strtotime($tgl_dari));
$tgl_sampai_fmt= date('d F Y', strtotime($tgl_sampai));
$periode_label = $tgl_dari === $tgl_sampai ? $tgl_dari_fmt : "$tgl_dari_fmt s/d $tgl_sampai_fmt";

// ── Query ────────────────────────────────────────────────────────
try {
    // Base: pasien unik dari nota_jalan + nota_inap di periode terpilih
    $base_join = "
        FROM (
            SELECT DISTINCT rp.no_rkm_medis
            FROM reg_periksa rp
            JOIN (
                SELECT no_rawat FROM nota_jalan WHERE tanggal BETWEEN ? AND ?
                UNION
                SELECT no_rawat FROM nota_inap  WHERE tanggal BETWEEN ? AND ?
            ) nj ON nj.no_rawat = rp.no_rawat
        ) unik
        JOIN pasien p                 ON unik.no_rkm_medis = p.no_rkm_medis
        LEFT JOIN medifix_ss_pasien m ON p.no_rkm_medis    = m.no_rkm_medis
    ";
    $base_params = [$tgl_dari, $tgl_sampai, $tgl_dari, $tgl_sampai];

    // Filter tambahan
    $extra_wheres = [];
    $extra_params = [];
    if (!empty($cari)) {
        $extra_wheres[] = "(p.nm_pasien LIKE ? OR p.no_rkm_medis LIKE ? OR p.no_ktp LIKE ? OR m.ihs_number LIKE ?)";
        $extra_params   = array_merge($extra_params, ["%$cari%", "%$cari%", "%$cari%", "%$cari%"]);
    }
    if ($filter_sync === 'ditemukan') {
        $extra_wheres[] = "m.ihs_number IS NOT NULL AND m.ihs_number != ''";
    } elseif ($filter_sync === 'pending') {
        $extra_wheres[] = "(m.ihs_number IS NULL OR m.ihs_number = '' OR m.no_rkm_medis IS NULL) AND (m.status_sync IS NULL OR m.status_sync NOT IN ('tidak_ditemukan','error'))";
    } elseif ($filter_sync === 'tidak_ditemukan') {
        $extra_wheres[] = "m.status_sync = 'tidak_ditemukan'";
    } elseif ($filter_sync === 'error') {
        $extra_wheres[] = "m.status_sync = 'error'";
    }

    $extra_where_sql = !empty($extra_wheres) ? "WHERE " . implode(" AND ", $extra_wheres) : "";
    $all_params      = array_merge($base_params, $extra_params);

    // Count
    $stmtCount = $pdo_simrs->prepare("SELECT COUNT(*) $base_join $extra_where_sql");
    $stmtCount->execute($all_params);
    $total       = (int)$stmtCount->fetchColumn();
    $total_pages = max(1, ceil($total / $limit));

    // Data
    $stmtData = $pdo_simrs->prepare("
        SELECT
            p.no_rkm_medis, p.nm_pasien, p.no_ktp, p.tgl_lahir, p.jk,
            m.ihs_number, m.tgl_sync, m.status_sync, m.error_msg
        $base_join $extra_where_sql
        ORDER BY
            CASE WHEN m.ihs_number IS NULL OR m.ihs_number = '' THEN 0 ELSE 1 END ASC,
            p.nm_pasien ASC
        LIMIT " . intval($limit) . " OFFSET " . intval($offset)
    );
    $stmtData->execute($all_params);
    $data = $stmtData->fetchAll(PDO::FETCH_ASSOC);

    // Stats
    $stmtStats = $pdo_simrs->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN m.ihs_number IS NOT NULL AND m.ihs_number != '' THEN 1 ELSE 0 END) AS ditemukan,
            SUM(CASE WHEN m.status_sync = 'tidak_ditemukan' THEN 1 ELSE 0 END) AS tidak_ditemukan,
            SUM(CASE WHEN m.status_sync = 'error' THEN 1 ELSE 0 END) AS error_sync,
            SUM(CASE WHEN (m.ihs_number IS NULL OR m.ihs_number = '' OR m.no_rkm_medis IS NULL)
                         AND (m.status_sync IS NULL OR m.status_sync NOT IN ('tidak_ditemukan','error'))
                    THEN 1 ELSE 0 END) AS pending
        $base_join
    ");
    $stmtStats->execute($base_params);
    $stats   = $stmtStats->fetch(PDO::FETCH_ASSOC);
    $dbError = null;

} catch (Exception $e) {
    $stats = ['total'=>0,'ditemukan'=>0,'tidak_ditemukan'=>0,'error_sync'=>0,'pending'=>0];
    $data  = []; $total = 0; $total_pages = 1;
    $dbError = $e->getMessage();
}

$st_total     = (int)($stats['total']          ?? 0);
$st_ditemukan = (int)($stats['ditemukan']       ?? 0);
$st_tidak     = (int)($stats['tidak_ditemukan'] ?? 0);
$st_error     = (int)($stats['error_sync']      ?? 0);
$st_pending   = (int)($stats['pending']         ?? 0);
$pct = $st_total > 0 ? round(($st_ditemukan / $st_total) * 100) : 0;

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
.info-bar-stats{display:flex;gap:16px;padding:8px 15px;background:#f0f0ff;border-bottom:1px solid #e5e5e5;font-size:12px;flex-wrap:wrap;align-items:center}
.ibs-item{display:flex;align-items:center;gap:5px;color:#555}
.ibs-val{font-weight:700;font-size:14px}
.ibs-sep{color:#ddd}
.prog-row{display:flex;align-items:center;gap:10px;font-size:12px;padding:10px 15px 8px;background:#f9f9f9;border-bottom:1px solid #eee}
.prog-label{width:140px;color:#555;flex-shrink:0}
.prog-bar{flex:1;height:7px;border-radius:4px;background:#e5e5e5;overflow:hidden}
.prog-fill{height:100%;border-radius:4px;background:linear-gradient(90deg,#605ca8,#00a65a);transition:width .6s}
.prog-pct{width:38px;text-align:right;font-weight:700;color:#555;flex-shrink:0}
.btn-sync-all{background:linear-gradient(135deg,#605ca8,#4a4789);border:none;color:#fff;padding:6px 14px;border-radius:5px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .25s}
.btn-sync-all:hover{transform:translateY(-2px);box-shadow:0 4px 14px rgba(96,92,168,.4);color:#fff}
.btn-sync-all:disabled{opacity:.6;cursor:not-allowed;transform:none}
.tbl-ihs thead tr th{background:#605ca8;color:#fff!important;white-space:nowrap;font-size:12px}
.tbl-ihs tbody td{vertical-align:middle}
.date-range-wrap{display:flex;align-items:center;gap:6px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;padding:5px 10px}
.date-range-wrap label{font-size:12px;color:#666;margin:0;white-space:nowrap}
.date-range-wrap input{border:none;background:transparent;font-size:13px;color:#333;padding:0;outline:none;width:130px}
.btn-period{padding:3px 10px;border-radius:4px;font-size:11px;border:1px solid #dee2e6;background:#fff;color:#555;cursor:pointer;transition:all .2s}
.btn-period:hover{background:#605ca8;color:#fff;border-color:#605ca8}
.progress-batch{display:none;padding:10px 15px;background:#fffdf0;border-bottom:1px solid #faebcc;font-size:12px;}
.progress-batch.show{display:block}
#toast-container{position:fixed;bottom:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.toast-msg{padding:10px 18px;border-radius:6px;font-size:13px;font-weight:600;color:#fff;display:flex;align-items:center;gap:8px;box-shadow:0 4px 12px rgba(0,0,0,.2);pointer-events:auto;animation:toastIn .3s ease}
.toast-success{background:#00a65a}.toast-error{background:#dd4b39}.toast-info{background:#00c0ef}.toast-warn{background:#f39c12}
@keyframes toastIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
';

$extra_js = <<<'ENDJS'
function setPeriode(jenis) {
    const now = new Date(), iso = d => d.toISOString().split("T")[0];
    let dari, sampai = iso(now);
    if (jenis==="hari")       { dari = sampai; }
    else if (jenis==="minggu"){ const d=new Date(now);d.setDate(d.getDate()-6);dari=iso(d); }
    else if (jenis==="bulan") { dari=iso(new Date(now.getFullYear(),now.getMonth(),1)); }
    else if (jenis==="bulan_lalu") {
        dari  = iso(new Date(now.getFullYear(),now.getMonth()-1,1));
        sampai= iso(new Date(now.getFullYear(),now.getMonth(),0));
    }
    document.getElementById("tgl_dari").value   = dari;
    document.getElementById("tgl_sampai").value = sampai;
    document.querySelector("form").submit();
}
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
            showToast("IHS ditemukan: " + resp.ihs_number, "success");
            if (row) {
                row.querySelector(".ihs-cell").innerHTML = `<span class="ihs-lbl">${resp.ihs_number}</span>`;
                const badge = row.querySelector(".badge-status");
                if (badge) { badge.className = "badge-status badge-found"; badge.innerHTML = `<i class="fa fa-check-circle"></i> IHS OK`; }
                row.style.background = "#f0fff4";
            }
            const pd = document.getElementById("cntPending");
            const fd = document.getElementById("cntFound");
            if (pd) pd.textContent = Math.max(0, parseInt(pd.textContent) - 1);
            if (fd) fd.textContent = parseInt(fd.textContent || 0) + 1;
        } else if (resp.status === "not_found") {
            btnEl.classList.add("notfound");
            btnEl.innerHTML = `<i class="fa fa-question"></i>`;
            showToast("NIK tidak ditemukan: " + (resp.nm_pasien || noRkm), "warn");
            if (row) {
                const badge = row.querySelector(".badge-status");
                if (badge) { badge.className = "badge-status badge-notfound"; badge.innerHTML = `<i class="fa fa-question-circle"></i> Tdk Ditemukan`; }
            }
        } else {
            btnEl.classList.add("err");
            btnEl.innerHTML = `<i class="fa fa-times"></i>`;
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
    const dari   = document.getElementById("tgl_dari").value;
    const sampai = document.getElementById("tgl_sampai").value;
    if (!confirm(`Sync IHS untuk ${sisa} pasien yang belum punya IHS Number?\nPeriode: ${dari === sampai ? dari : dari + " s/d " + sampai}`)) return;

    isSyncing = true;
    const btn = document.getElementById("btnSyncSemua");
    if (btn) { btn.disabled = true; btn.innerHTML = `<i class="fa fa-spinner fa-spin"></i> Menyinkronkan...`; }
    const progDiv = document.getElementById("progressBatch");
    if (progDiv) progDiv.classList.add("show");

    let totalProses = 0, totalDitemukan = 0, totalTidak = 0, totalError = 0;

    function batch() {
        fetch(window.location.href, {
            method: "POST",
            headers: {"Content-Type":"application/x-www-form-urlencoded"},
            body: new URLSearchParams({action:"sync_semua", limit: 20, tgl_dari: dari, tgl_sampai: sampai})
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
                    `Diproses: <b>${totalProses}</b> | IHS OK: <b style="color:#00a65a">${totalDitemukan}</b> | Tidak ditemukan: <b style="color:#f39c12">${totalTidak}</b> | Error: <b style="color:#dd4b39">${totalError}</b> | Sisa: <b>${resp.sisa || 0}</b>`;
                if (resp.sisa > 0 && resp.jumlah > 0) {
                    setTimeout(batch, 600);
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
ENDJS;

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div id="toast-container"></div>

<div class="content-wrapper">
  <section class="content-header">
    <h1>
      <i class="fa fa-users" style="color:#605ca8;"></i>
      Sinkronisasi IHS Pasien
      <small>Satu Sehat &mdash; <?= $periode_label ?></small>
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

    <div class="row"><div class="col-xs-12">

      <!-- Filter -->
      <div class="box box-default">
        <div class="box-header with-border">
          <h3 class="box-title"><i class="fa fa-filter"></i> Filter</h3>
          <div class="box-tools pull-right"><button type="button" class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-minus"></i></button></div>
        </div>
        <div class="box-body">
          <form method="GET" class="form-inline" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
            <div class="form-group">
              <label style="display:block;margin-bottom:4px;font-size:12px;"><i class="fa fa-calendar"></i> Periode (Nota Jalan / Nota Inap):</label>
              <div class="date-range-wrap">
                <label>Dari</label>
                <input type="date" name="tgl_dari" id="tgl_dari" value="<?= htmlspecialchars($tgl_dari) ?>">
                <span style="color:#aaa;font-size:12px">—</span>
                <label>Sampai</label>
                <input type="date" name="tgl_sampai" id="tgl_sampai" value="<?= htmlspecialchars($tgl_sampai) ?>">
              </div>
            </div>
            <div class="form-group">
              <label style="display:block;margin-bottom:4px;font-size:12px;">Shortcut:</label>
              <div style="display:flex;gap:4px;">
                <button type="button" class="btn-period" onclick="setPeriode('hari')">Hari ini</button>
                <button type="button" class="btn-period" onclick="setPeriode('minggu')">7 Hari</button>
                <button type="button" class="btn-period" onclick="setPeriode('bulan')">Bulan ini</button>
                <button type="button" class="btn-period" onclick="setPeriode('bulan_lalu')">Bulan lalu</button>
              </div>
            </div>
            <div class="form-group">
              <label style="margin-right:5px;">Cari:</label>
              <input type="text" name="cari" class="form-control" placeholder="Nama / No. RM / NIK / IHS…"
                     value="<?= htmlspecialchars($cari) ?>" style="width:220px;">
            </div>
            <div class="form-group">
              <label style="margin-right:5px;">Status IHS:</label>
              <select name="sync" class="form-control">
                <option value=""                <?= $filter_sync===''               ?'selected':'' ?>>Semua</option>
                <option value="ditemukan"       <?= $filter_sync==='ditemukan'       ?'selected':'' ?>>IHS Ditemukan</option>
                <option value="pending"         <?= $filter_sync==='pending'         ?'selected':'' ?>>Belum Sync</option>
                <option value="tidak_ditemukan" <?= $filter_sync==='tidak_ditemukan' ?'selected':'' ?>>Tidak Ditemukan</option>
                <option value="error"           <?= $filter_sync==='error'           ?'selected':'' ?>>Error</option>
              </select>
            </div>
            <div class="form-group">
              <label style="margin-right:5px;">Per halaman:</label>
              <select name="limit" class="form-control">
                <?php foreach ([20,50,100,200] as $l): ?>
                <option value="<?=$l?>" <?=$limit==$l?'selected':''?>><?=$l?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i> Tampilkan</button>
            <a href="sinkronisasi_ihs_pasien.php" class="btn btn-default"><i class="fa fa-refresh"></i> Reset</a>
          </form>
        </div>
      </div>

      <!-- Tabel -->
      <div class="box box-primary" style="border-top-color:#605ca8;">
        <div class="box-header with-border">
          <h3 class="box-title">
            <i class="fa fa-users"></i> Pasien Periode <?= $periode_label ?>
            <span class="badge" style="background:#605ca8;"><?= number_format($total) ?></span>
          </h3>
          <div class="box-tools pull-right" style="display:flex;gap:8px;align-items:center;">
            <button id="btnSyncSemua" onclick="syncSemua()" class="btn-sync-all" <?= $st_pending===0?'disabled':'' ?>>
              <i class="fa fa-refresh"></i> Sync Semua Pending
              <?php if ($st_pending > 0): ?>
              <span class="badge" style="background:#dd4b39;"><?= number_format($st_pending) ?></span>
              <?php endif; ?>
            </button>
            <button onclick="showLog()" class="btn btn-default btn-sm"><i class="fa fa-file-text-o"></i> Log</button>
            <a href="?tgl_dari=<?= urlencode($tgl_dari) ?>&tgl_sampai=<?= urlencode($tgl_sampai) ?>&cari=<?= urlencode($cari) ?>&sync=<?= urlencode($filter_sync) ?>&limit=<?= $limit ?>"
               class="btn btn-default btn-sm"><i class="fa fa-refresh"></i></a>
          </div>
        </div>

        <!-- Stats -->
        <div class="info-bar-stats">
          <div class="ibs-item"><i class="fa fa-users" style="color:#605ca8;"></i> Total: <span class="ibs-val" style="color:#605ca8;"><?= number_format($st_total) ?></span></div>
          <span class="ibs-sep">|</span>
          <div class="ibs-item"><i class="fa fa-check-circle" style="color:#00a65a;"></i> IHS OK: <span class="ibs-val" style="color:#00a65a;" id="cntFound"><?= number_format($st_ditemukan) ?></span></div>
          <div class="ibs-item"><i class="fa fa-clock-o" style="color:#f39c12;"></i> Belum sync: <span class="ibs-val" style="color:#f39c12;" id="cntPending"><?= number_format($st_pending) ?></span></div>
          <?php if ($st_tidak > 0): ?>
          <div class="ibs-item"><i class="fa fa-question-circle" style="color:#f39c12;"></i> Tidak ditemukan: <span class="ibs-val" style="color:#f39c12;"><?= number_format($st_tidak) ?></span></div>
          <?php endif; ?>
          <?php if ($st_error > 0): ?>
          <div class="ibs-item"><i class="fa fa-times-circle" style="color:#dd4b39;"></i> Error: <span class="ibs-val" style="color:#dd4b39;"><?= number_format($st_error) ?></span></div>
          <?php endif; ?>
        </div>

        <!-- Progress -->
        <div class="prog-row">
          <span class="prog-label"><i class="fa fa-bar-chart"></i> Progress IHS</span>
          <div class="prog-bar"><div class="prog-fill" style="width:<?= $pct ?>%;"></div></div>
          <span class="prog-pct"><?= $pct ?>%</span>
        </div>

        <!-- Progress batch -->
        <div id="progressBatch" class="progress-batch">
          <i class="fa fa-spinner fa-spin"></i> Sedang menyinkronkan IHS...
          <div id="progText" style="margin-top:5px;font-size:12px;color:#555;"></div>
        </div>

        <div class="box-body" style="padding:0;">
          <?php if (!empty($data)): ?>
          <div class="table-responsive">
            <table class="table table-bordered table-striped table-hover tbl-ihs" style="margin-bottom:0;font-size:12.5px;">
              <thead>
                <tr>
                  <th width="36"  class="text-center">#</th>
                  <th width="38"  class="text-center">Sync</th>
                  <th width="120">No. RM</th>
                  <th width="200">Nama Pasien</th>
                  <th width="155">NIK</th>
                  <th>IHS Number</th>
                  <th width="110">Tgl Sync</th>
                  <th width="145" class="text-center">Status</th>
                </tr>
              </thead>
              <tbody>
              <?php $no = $offset + 1; foreach ($data as $r):
                  $found  = !empty($r['ihs_number']);
                  $status = $r['status_sync'] ?? 'pending';
                  $umur   = '';
                  if (!empty($r['tgl_lahir']) && $r['tgl_lahir'] !== '0000-00-00') {
                      try { $umur = (new DateTime())->diff(new DateTime($r['tgl_lahir']))->y . ' th'; } catch(Exception $e){}
                  }
              ?>
              <tr style="<?= $found ? 'background:#f0fff4!important' : '' ?>">

                <td class="text-center" style="color:#aaa;font-size:11px;"><?= $no++ ?></td>

                <td class="text-center">
                  <button class="btn-sync <?= $found?'found':($status==='tidak_ditemukan'?'notfound':($status==='error'?'err':'')) ?>"
                          onclick="syncSatu('<?= addslashes($r['no_rkm_medis']) ?>',this)"
                          title="<?= $found?'Sync Ulang IHS':'Cari IHS Number' ?>">
                    <i class="fa fa-<?= $found?'refresh':($status==='tidak_ditemukan'?'question':($status==='error'?'times':'search')) ?>"></i>
                  </button>
                </td>

                <td>
                  <div style="font-size:12px;color:#605ca8;font-weight:700;font-family:'Courier New',monospace;"><?= htmlspecialchars($r['no_rkm_medis']) ?></div>
                </td>

                <td>
                  <div class="nm-pasien"><?= htmlspecialchars($r['nm_pasien']) ?></div>
                  <div class="rm-lbl">
                    <?= $umur ?: '' ?>
                    <?= !empty($r['jk']) ? ($umur?' · ':'').htmlspecialchars($r['jk']) : '' ?>
                  </div>
                </td>

                <td>
                  <?php if (!empty($r['no_ktp'])): ?>
                    <span class="nik-lbl"><?= htmlspecialchars($r['no_ktp']) ?></span>
                  <?php else: ?>
                    <span style="font-size:10.5px;color:#dd4b39;"><i class="fa fa-warning"></i> NIK kosong</span>
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
                    <?= $found?'IHS OK':($status==='tidak_ditemukan'?'Tdk Ditemukan':($status==='error'?'Error':'Pending')) ?>
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
              Menampilkan <strong><?= number_format($offset+1) ?></strong>–<strong><?= number_format(min($offset+$limit,$total)) ?></strong>
              dari <strong><?= number_format($total) ?></strong> pasien &nbsp;|&nbsp; Periode: <strong><?= $periode_label ?></strong>
            </div>
            <ul class="pagination pagination-sm no-margin pull-right">
              <?php
              $qBase = "tgl_dari=".urlencode($tgl_dari)."&tgl_sampai=".urlencode($tgl_sampai)."&cari=".urlencode($cari)."&sync=".urlencode($filter_sync)."&limit=$limit";
              if ($page>1): ?><li><a href="?page=<?=$page-1?>&<?=$qBase?>">«</a></li><?php endif;
              for ($i=max(1,$page-3); $i<=min($total_pages,$page+3); $i++): ?>
              <li <?=$i==$page?'class="active"':''?>><a href="?page=<?=$i?>&<?=$qBase?>"><?=$i?></a></li>
              <?php endfor;
              if ($page<$total_pages): ?><li><a href="?page=<?=$page+1?>&<?=$qBase?>">»</a></li><?php endif; ?>
            </ul>
          </div>
          <?php endif; ?>

          <?php else: ?>
          <div style="padding:50px;text-align:center;">
            <i class="fa fa-users" style="font-size:52px;color:#ddd;display:block;margin-bottom:14px;"></i>
            <h4 style="color:#aaa;font-weight:400;">
              <?= ($cari || $filter_sync)
                  ? "Tidak ada pasien untuk filter yang dipilih"
                  : "Tidak ada data pasien pada periode <strong>$periode_label</strong>" ?>
            </h4>
          </div>
          <?php endif; ?>
        </div>
      </div>

    </div></div>
  </section>
</div>

<?php include 'includes/footer.php'; ?>

<!-- Modal Log -->
<div id="modal-log-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9990;align-items:center;justify-content:center;">
  <div style="background:#1e1e1e;border-radius:8px;width:860px;max-width:95vw;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 8px 40px rgba(0,0,0,.5);">
    <div style="background:#2d2d2d;padding:12px 18px;border-radius:8px 8px 0 0;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #444;">
      <span style="color:#605ca8;font-weight:700;font-size:14px;font-family:'Courier New',monospace;">
        <i class="fa fa-file-text-o"></i> Log IHS Sync — logs/ihs_<?= date('Y-m') ?>.log
      </span>
      <div style="display:flex;gap:8px;">
        <button onclick="refreshLog()" style="background:#605ca8;border:none;color:#fff;padding:4px 12px;border-radius:4px;font-size:12px;cursor:pointer;"><i class="fa fa-refresh"></i> Refresh</button>
        <button onclick="copyLog()"   style="background:#555;border:none;color:#fff;padding:4px 12px;border-radius:4px;font-size:12px;cursor:pointer;"><i class="fa fa-copy"></i> Copy</button>
        <button onclick="closeLog()"  style="background:none;border:none;color:#aaa;font-size:20px;cursor:pointer;">&times;</button>
      </div>
    </div>
    <div style="background:#252525;padding:8px 18px;display:flex;gap:8px;align-items:center;border-bottom:1px solid #333;">
      <input id="log-filter" type="text" placeholder="Filter log..." oninput="filterLog()"
             style="flex:1;background:#1e1e1e;border:1px solid #444;color:#ccc;padding:5px 10px;border-radius:4px;font-family:'Courier New',monospace;font-size:12px;">
      <label style="color:#aaa;font-size:12px;cursor:pointer;"><input type="checkbox" id="chk-error-only" onchange="filterLog()"> Hanya ERROR</label>
      <span id="log-count" style="color:#666;font-size:11px;white-space:nowrap;"></span>
    </div>
    <div id="log-content" style="overflow-y:auto;flex:1;padding:14px 18px;font-family:'Courier New',monospace;font-size:12px;line-height:1.7;color:#ccc;white-space:pre-wrap;word-break:break-all;">
      <span style="color:#666;">Memuat log...</span>
    </div>
  </div>
</div>
<script>
var _rawLog = '';
function showLog(){ document.getElementById('modal-log-overlay').style.display='flex'; refreshLog(); }
function closeLog(){ document.getElementById('modal-log-overlay').style.display='none'; }
function refreshLog(){
    const el = document.getElementById('log-content');
    el.innerHTML = '<span style="color:#666;">Memuat...</span>';
    fetch('?_log=ihs&_t='+Date.now()).then(r=>r.text()).then(txt=>{ _rawLog=txt; renderLog(txt); })
    .catch(()=>{ el.innerHTML='<span style="color:#dd4b39;">Gagal memuat.</span>'; });
}
function renderLog(txt){
    const filter  = (document.getElementById('log-filter')?.value||'').toLowerCase();
    const errOnly = document.getElementById('chk-error-only')?.checked;
    let shown = 0;
    const html = txt.split('\n').map(line => {
        if (!line.trim()) return '';
        if (errOnly && !line.includes('ERROR') && !line.includes('NOT_FOUND')) return '';
        if (filter && !line.toLowerCase().includes(filter)) return '';
        shown++;
        let color = '#ccc';
        if (line.includes('] ERROR'))     color = '#ff6b6b';
        else if (line.includes('] OK'))   color = '#69db7c';
        else if (line.includes('] NOT_FOUND')) color = '#ffd43b';
        else if (line.includes('] SYNC_SEMUA')) color = '#74c0fc';
        return `<span style="color:${color}">${line.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}</span>`;
    }).filter(Boolean).join('\n');
    const el = document.getElementById('log-content');
    el.innerHTML = html || '<span style="color:#666;">Tidak ada log cocok.</span>';
    el.scrollTop = el.scrollHeight;
    document.getElementById('log-count').textContent = shown + ' baris';
}
function filterLog(){ renderLog(_rawLog); }
function copyLog(){
    const filter  = (document.getElementById('log-filter')?.value||'').toLowerCase();
    const errOnly = document.getElementById('chk-error-only')?.checked;
    const lines   = _rawLog.split('\n').filter(l => {
        if (!l.trim()) return false;
        if (errOnly && !l.includes('ERROR') && !l.includes('NOT_FOUND')) return false;
        if (filter && !l.toLowerCase().includes(filter)) return false;
        return true;
    });
    navigator.clipboard.writeText(lines.join('\n')).then(()=>showToast('Log disalin!','success'));
}
document.getElementById('modal-log-overlay').addEventListener('click', function(e){ if(e.target===this) closeLog(); });
</script>