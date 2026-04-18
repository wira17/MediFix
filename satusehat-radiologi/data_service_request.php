<?php
/**
 * data_service_request.php  — VERSI BARU (lengkap dengan Satu Sehat)
 * Halaman monitoring & pengiriman Service Request Radiologi ke Satu Sehat
 */

session_start();
include 'koneksi.php';
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$nama = $_SESSION['nama'] ?? 'Pengguna';

// ── AJAX: Teruskan ke api/kirim_service_request.php ──────────────
// (semua logic kirim sudah dipindah ke sana)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['kirim', 'kirim_semua', 'status'])) {
        include __DIR__ . '/api/kirim_service_request.php';
        exit;
    }
}

// ── Filter & Pagination ──────────────────────────────────────────
$filter_tanggal = $_GET['tanggal'] ?? date('Y-m-d');
$cari           = $_GET['cari']    ?? '';
$filter_status  = $_GET['status']  ?? '';       // all | terkirim | pending | error
$filter_is      = $_GET['is']      ?? '';        // '' | terkirim | pending
$limit          = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 20;
$page           = isset($_GET['page'])  ? max(1, intval($_GET['page']))  : 1;
$offset         = ($page - 1) * $limit;

// ── Ambil Data ───────────────────────────────────────────────────
try {
    $wheres = ["pr.tgl_permintaan = ?"];
    $params = [$filter_tanggal];

    if (!empty($cari)) {
        $wheres[] = "(s.noorder LIKE ? OR s.kd_jenis_prw LIKE ? OR s.id_servicerequest LIKE ?
                      OR s.id_imagingstudy LIKE ? OR p.nm_pasien LIKE ?
                      OR d.nm_dokter LIKE ? OR pr.diagnosa_klinis LIKE ?)";
        $params   = array_merge($params, array_fill(0, 7, "%$cari%"));
    }
    if ($filter_status === 'terkirim') {
        $wheres[] = "s.status_kirim_sr = 'terkirim'";
    } elseif ($filter_status === 'pending') {
        $wheres[] = "(s.status_kirim_sr IS NULL OR s.status_kirim_sr = 'pending')";
    } elseif ($filter_status === 'error') {
        $wheres[] = "s.status_kirim_sr = 'error'";
    }
    if ($filter_is === 'terkirim') {
        $wheres[] = "s.status_kirim_is = 'terkirim'";
    } elseif ($filter_is === 'pending') {
        $wheres[] = "(s.status_kirim_is IS NULL OR s.status_kirim_is != 'terkirim')";
    }

    $where_sql = "WHERE " . implode(" AND ", $wheres);

    $base_join = "
        FROM satu_sehat_servicerequest_radiologi s
        JOIN permintaan_radiologi pr ON s.noorder      = pr.noorder
        JOIN reg_periksa r           ON pr.no_rawat    = r.no_rawat
        JOIN pasien p               ON r.no_rkm_medis  = p.no_rkm_medis
        LEFT JOIN dokter d          ON pr.dokter_perujuk = d.kd_dokter
        LEFT JOIN poliklinik pl     ON r.kd_poli       = pl.kd_poli
        LEFT JOIN jenis_perawatan j ON s.kd_jenis_prw  = j.kd_jenis_prw
    ";

    $stmtCount = $pdo_simrs->prepare("SELECT COUNT(*) $base_join $where_sql");
    $stmtCount->execute($params);
    $total       = (int)$stmtCount->fetchColumn();
    $total_pages = max(1, ceil($total / $limit));

    $stmtData = $pdo_simrs->prepare(
        "SELECT s.noorder, s.kd_jenis_prw, s.id_servicerequest, s.id_imagingstudy,
                s.status_kirim_sr, s.tgl_kirim_sr, s.error_msg_sr,
                s.status_kirim_is, s.tgl_kirim_is, s.error_msg_is, s.study_uid_dicom,
                pr.no_rawat, pr.tgl_permintaan, pr.jam_permintaan,
                pr.tgl_hasil, pr.jam_hasil, pr.status AS status_rawat,
                pr.informasi_tambahan, pr.diagnosa_klinis,
                p.no_rkm_medis, p.nm_pasien, p.jk, p.tgl_lahir,
                p.ihs_number,
                r.id_encounter,
                d.nm_dokter, d.ihs_dokter,
                pl.nm_poli, j.nm_jenis_prw
         $base_join $where_sql
         ORDER BY pr.jam_permintaan DESC
         LIMIT " . intval($limit) . " OFFSET " . intval($offset)
    );
    $stmtData->execute($params);
    $data = $stmtData->fetchAll(PDO::FETCH_ASSOC);

    // Stats bar
    $stmtStats = $pdo_simrs->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN s.status_kirim_sr = 'terkirim' THEN 1 ELSE 0 END) AS sr_terkirim,
            SUM(CASE WHEN s.status_kirim_sr = 'error'    THEN 1 ELSE 0 END) AS sr_error,
            SUM(CASE WHEN s.status_kirim_sr IS NULL OR s.status_kirim_sr='pending' THEN 1 ELSE 0 END) AS sr_pending,
            SUM(CASE WHEN s.status_kirim_is = 'terkirim' THEN 1 ELSE 0 END) AS is_terkirim,
            SUM(CASE WHEN s.status_kirim_is = 'error'    THEN 1 ELSE 0 END) AS is_error
         FROM satu_sehat_servicerequest_radiologi s
         JOIN permintaan_radiologi pr ON s.noorder = pr.noorder
         WHERE pr.tgl_permintaan = ?"
    );
    $stmtStats->execute([$filter_tanggal]);
    $stats       = $stmtStats->fetch(PDO::FETCH_ASSOC);
    $st_total    = (int)($stats['total']      ?? 0);
    $st_terkirim = (int)($stats['sr_terkirim'] ?? 0);
    $st_error    = (int)($stats['sr_error']    ?? 0);
    $st_pending  = (int)($stats['sr_pending']  ?? 0);
    $st_is       = (int)($stats['is_terkirim'] ?? 0);
    $st_is_err   = (int)($stats['is_error']    ?? 0);
    $dbError     = null;
} catch (Exception $e) {
    $data=[]; $total=0; $total_pages=1;
    $st_total=$st_terkirim=$st_error=$st_pending=$st_is=$st_is_err=0;
    $dbError=$e->getMessage();
}
$pct_sr = $st_total > 0 ? round(($st_terkirim / $st_total) * 100) : 0;
$pct_is = $st_total > 0 ? round(($st_is / $st_total) * 100) : 0;

// ── Kumpulkan noorder yang ihs_number/encounter-nya kosong ───────
$dataNotReady = array_filter($data, fn($r) => empty($r['ihs_number']) || empty($r['id_encounter']));

$page_title = 'Service Request Radiologi — Satu Sehat';

$extra_css = '
/* ── Tombol kirim ──────────────────────────────────────────── */
.btn-send{width:32px;height:32px;border-radius:6px;padding:0;display:inline-flex;align-items:center;justify-content:center;border:1px solid transparent;cursor:pointer;transition:all .25s;font-size:12px;background:#605ca8;border-color:#564fa5;color:#fff}
.btn-send:hover{background:#564fa5;transform:scale(1.12);box-shadow:0 3px 10px rgba(96,92,168,.45);color:#fff}
.btn-send:disabled{opacity:.5;cursor:not-allowed;transform:none}
.btn-send.sent{background:#00a65a;border-color:#008d4c}
.btn-send.sent:hover{background:#008d4c}
.btn-send.error-st{background:#dd4b39;border-color:#c23321}
.btn-send.spin i{animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── Badge status ─────────────────────────────────────────── */
.badge-sr-ok {background:#dff0d8;color:#3c763d;border:1px solid #c3e6cb;padding:3px 9px;border-radius:10px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px}
.badge-sr-err{background:#f2dede;color:#a94442;border:1px solid #f5c6cb;padding:3px 9px;border-radius:10px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px}
.badge-sr-pend{background:#fcf8e3;color:#8a6d3b;border:1px solid #faebcc;padding:3px 9px;border-radius:10px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px}
.badge-is-ok {background:#d9edf7;color:#31708f;border:1px solid #bce8f1;padding:3px 9px;border-radius:10px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px;margin-top:3px}
.badge-is-pend{background:#f5f5f5;color:#777;border:1px solid #e5e5e5;padding:3px 9px;border-radius:10px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px;margin-top:3px}
.badge-is-err {background:#f2dede;color:#a94442;border:1px solid #f5c6cb;padding:3px 9px;border-radius:10px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px;margin-top:3px}
.badge-ralan{background:#d9edf7;color:#31708f;border:1px solid #bce8f1;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700}
.badge-ranap{background:#fcf8e3;color:#8a6d3b;border:1px solid #faebcc;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700}
.badge-warn {background:#fff3cd;color:#856404;border:1px solid #ffc107;padding:3px 9px;border-radius:8px;font-size:10px;font-weight:700}

/* ── Row highlight ────────────────────────────────────────── */
.row-sent{background:#f0fff4!important}
.row-error{background:#fff5f5!important}
.row-notready{background:#fffdf0!important}

/* ── Copy button ─────────────────────────────────────────── */
.btn-copy{width:22px;height:22px;border-radius:4px;padding:0;display:inline-flex;align-items:center;justify-content:center;background:#ecf0f1;border:1px solid #ddd;color:#777;cursor:pointer;transition:all .2s;font-size:10px;vertical-align:middle;margin-left:3px}
.btn-copy:hover{background:#d5d8dc;color:#333}

/* ── Cell typography ─────────────────────────────────────── */
.id-cell{font-family:"Courier New",monospace;font-size:10.5px;color:#555;word-break:break-all}
.no-id{font-style:italic;color:#ccc;font-size:11px}
.noorder-lbl{font-weight:700;color:#605ca8;font-size:13px;font-family:"Courier New",monospace}
.rm-lbl{font-size:10.5px;color:#aaa;font-family:"Courier New",monospace}
.kd-lbl{display:inline-block;background:#f0f0ff;border:1px solid #d6d0f8;color:#605ca8;padding:2px 7px;border-radius:4px;font-size:11px;font-weight:700;font-family:"Courier New",monospace}
.nm-pasien{font-weight:700;color:#333;font-size:13px}
.ihs-lbl{font-size:10px;color:#aaa;font-family:"Courier New",monospace}
.ihs-missing{font-size:10px;color:#dd4b39;font-style:italic}
.waktu-lbl{font-size:13px;font-weight:600;color:#333}
.waktu-sub{font-size:10.5px;color:#aaa;margin-top:2px}

/* ── Kirim semua & action bar ────────────────────────────── */
.action-bar{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.btn-kirim-semua{background:linear-gradient(135deg,#605ca8,#4a4789);border:none;color:#fff;padding:6px 14px;border-radius:5px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .25s}
.btn-kirim-semua:hover{transform:translateY(-2px);box-shadow:0 4px 14px rgba(96,92,168,.4);color:#fff}
.btn-kirim-semua:disabled{opacity:.6;cursor:not-allowed;transform:none}

/* ── Dual progress bar ────────────────────────────────────── */
.prog-dual{padding:10px 15px 6px;background:#f9f9f9;border-bottom:1px solid #e5e5e5}
.prog-row{display:flex;align-items:center;gap:10px;font-size:12px;margin-bottom:5px}
.prog-label{width:110px;color:#555;flex-shrink:0}
.prog-bar{flex:1;height:7px;border-radius:4px;background:#e5e5e5;overflow:hidden}
.prog-fill-sr{height:100%;border-radius:4px;background:linear-gradient(90deg,#605ca8,#00a65a);transition:width .6s}
.prog-fill-is{height:100%;border-radius:4px;background:linear-gradient(90deg,#00c0ef,#0073b7);transition:width .6s}
.prog-pct{width:38px;text-align:right;font-weight:700;color:#555;flex-shrink:0}

/* ── Stats bar ────────────────────────────────────────────── */
.info-bar-stats{display:flex;gap:20px;padding:8px 15px;background:#f0f0ff;border-bottom:1px solid #e5e5e5;font-size:12px;flex-wrap:wrap;align-items:center}
.ibs-item{display:flex;align-items:center;gap:5px;color:#555}
.ibs-val{font-weight:700;font-size:14px}
.ibs-sep{color:#ddd}

/* ── Tabel header ─────────────────────────────────────────── */
.tbl-sr thead tr th{background:#605ca8;color:#fff!important;white-space:nowrap;font-size:12px}
.tbl-sr tbody td{vertical-align:middle}

/* ── Error tooltip ────────────────────────────────────────── */
.err-tooltip{cursor:help;border-bottom:1px dashed #dd4b39;font-size:10.5px;color:#dd4b39}

/* ── Toast ────────────────────────────────────────────────── */
#toast-container{position:fixed;bottom:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.toast-msg{padding:10px 18px;border-radius:6px;font-size:13px;font-weight:600;color:#fff;display:flex;align-items:center;gap:8px;box-shadow:0 4px 12px rgba(0,0,0,.2);pointer-events:auto;animation:toastIn .3s ease}
.toast-success{background:#00a65a}.toast-error{background:#dd4b39}.toast-info{background:#00c0ef}.toast-warn{background:#f39c12}
@keyframes toastIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}

/* ── Kolom status SR+IS ───────────────────────────────────── */
.status-col{display:flex;flex-direction:column;gap:2px}
';

$extra_js = '
function showToast(msg, type) {
    type = type || "success";
    const c = document.getElementById("toast-container");
    if (!c) return;
    const icons = { success:"check-circle", error:"times-circle", info:"info-circle", warn:"exclamation-triangle" };
    const d = document.createElement("div");
    d.className = "toast-msg toast-" + type;
    d.innerHTML = `<i class="fa fa-${icons[type] || "info-circle"}"></i> ${msg}`;
    c.appendChild(d);
    setTimeout(() => d.remove(), 4000);
}

function copyText(text) {
    navigator.clipboard.writeText(text)
        .then(() => showToast("Disalin!", "success"))
        .catch(() => {
            const ta = document.createElement("textarea");
            ta.value = text; document.body.appendChild(ta); ta.select();
            document.execCommand("copy"); document.body.removeChild(ta);
            showToast("Disalin!", "success");
        });
}

// Kirim satu noorder ke Satu Sehat
function kirimSatu(noorder, kdJenisPrw, btnEl) {
    btnEl.disabled = true;
    const origHTML = btnEl.innerHTML;
    btnEl.classList.add("spin");
    btnEl.innerHTML = `<i class="fa fa-spinner"></i>`;
    btnEl.title = "Mengirim…";

    const fd = new URLSearchParams({ action: "kirim", noorder, kd_jenis_prw: kdJenisPrw });
    fetch(window.location.href, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: fd
    })
    .then(r => r.json())
    .then(resp => {
        btnEl.classList.remove("spin");
        if (resp.status === "ok") {
            btnEl.classList.add("sent");
            btnEl.innerHTML = `<i class="fa fa-check"></i>`;
            btnEl.title = "Terkirim — " + (resp.id_sr || "");
            showToast("✓ No. Order " + noorder + " berhasil dikirim!", "success");

            const row = btnEl.closest("tr");
            if (row) {
                const badgeSR = row.querySelector(".badge-sr-status");
                if (badgeSR) {
                    badgeSR.className = "badge-sr-status badge-sr-ok";
                    badgeSR.innerHTML = `<i class="fa fa-check-circle"></i> SR Terkirim`;
                }
                row.classList.remove("row-error", "row-notready");
                row.classList.add("row-sent");

                // Tampilkan id SR di kolom
                const idCell = row.querySelector(".id-sr-cell");
                if (idCell && resp.id_sr) {
                    idCell.innerHTML = `<span class="id-cell" title="${resp.id_sr}">${resp.id_sr.substring(0,36)}</span>
                        <button class="btn-copy" onclick="copyText(\'${resp.id_sr}\')" title="Salin"><i class="fa fa-copy"></i></button>`;
                }
            }

            // Update counter
            const ep = document.getElementById("ibsPending");
            const et = document.getElementById("ibsTerkirim");
            if (ep) { const v = parseInt(ep.textContent) - 1; ep.textContent = Math.max(0, v); }
            if (et) et.textContent = parseInt(et.textContent || 0) + 1;
        } else {
            btnEl.disabled = false;
            btnEl.classList.add("error-st");
            btnEl.innerHTML = `<i class="fa fa-exclamation-triangle"></i>`;
            btnEl.title = "Gagal: " + (resp.message || "");
            showToast("Gagal: " + (resp.message || "Error tidak diketahui"), "error");

            const row = btnEl.closest("tr");
            if (row) {
                const badgeSR = row.querySelector(".badge-sr-status");
                if (badgeSR) {
                    badgeSR.className = "badge-sr-status badge-sr-err";
                    badgeSR.innerHTML = `<i class="fa fa-times-circle"></i> Error`;
                }
                row.classList.add("row-error");
            }
        }
    })
    .catch(() => {
        btnEl.disabled = false;
        btnEl.innerHTML = origHTML;
        btnEl.classList.remove("spin");
        showToast("Koneksi ke server gagal", "error");
    });
}

// Kirim semua pending
function kirimSemua() {
    const tanggal = document.getElementById("inputTanggal")?.value || "";
    if (!confirm("Kirim semua ServiceRequest yang pending ke Satu Sehat?\nTanggal: " + tanggal)) return;

    const btn = document.getElementById("btnKirimSemua");
    if (btn) { btn.disabled = true; btn.innerHTML = `<i class="fa fa-spinner fa-spin"></i> Mengirim…`; }

    fetch(window.location.href, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({ action: "kirim_semua", tanggal })
    })
    .then(r => r.json())
    .then(resp => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = `<i class="fa fa-send"></i> Kirim Semua Pending`;
        }
        if (resp.status === "ok") {
            const msg = `Proses selesai: ${resp.berhasil} berhasil, ${resp.gagal} gagal dari ${resp.jumlah} data.`;
            showToast(msg, resp.gagal > 0 ? "warn" : "success");
            if (resp.errors && resp.errors.length) {
                console.warn("Errors:", resp.errors);
            }
            // Reload tabel setelah 2 detik
            setTimeout(() => location.reload(), 2000);
        } else {
            showToast("Gagal: " + (resp.message || ""), "error");
        }
    })
    .catch(() => {
        if (btn) { btn.disabled = false; btn.innerHTML = `<i class="fa fa-send"></i> Kirim Semua Pending`; }
        showToast("Koneksi ke server gagal", "error");
    });
}
';

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div id="toast-container"></div>

<div class="content-wrapper">
  <section class="content-header">
    <h1>
      <i class="fa fa-heartbeat" style="color:#605ca8;"></i>
      Service Request Radiologi
      <small>Satu Sehat &mdash; <?= date('d F Y', strtotime($filter_tanggal)) ?></small>
    </h1>
    <ol class="breadcrumb">
      <li><a href="dashboard.php"><i class="fa fa-dashboard"></i> Home</a></li>
      <li>Satu Sehat</li>
      <li class="active">Service Request Radiologi</li>
    </ol>
  </section>

  <section class="content">

    <?php if ($dbError): ?>
    <div class="callout callout-danger">
      <h4><i class="fa fa-ban"></i> Error Database</h4>
      <p><?= htmlspecialchars($dbError) ?></p>
    </div>
    <?php endif; ?>

    <?php if (count($dataNotReady) > 0): ?>
    <div class="callout callout-warning">
      <h4><i class="fa fa-exclamation-triangle"></i> <?= count($dataNotReady) ?> data belum siap dikirim</h4>
      <p>Beberapa pasien/dokter belum memiliki <strong>IHS Number</strong> atau <strong>Encounter ID</strong> Satu Sehat.
         Pastikan sinkronisasi data pasien dan dokter sudah dilakukan.</p>
    </div>
    <?php endif; ?>

    <div class="row">
      <div class="col-xs-12">

        <!-- Filter -->
        <div class="box box-default">
          <div class="box-header with-border">
            <h3 class="box-title"><i class="fa fa-filter"></i> Filter</h3>
            <div class="box-tools pull-right">
              <button type="button" class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-minus"></i></button>
            </div>
          </div>
          <div class="box-body">
            <form method="GET" class="form-inline" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
              <div class="form-group">
                <label style="margin-right:5px;"><i class="fa fa-calendar"></i> Tanggal:</label>
                <input type="date" name="tanggal" id="inputTanggal" class="form-control" value="<?= htmlspecialchars($filter_tanggal) ?>">
              </div>
              <div class="form-group">
                <label style="margin-right:5px;">Cari:</label>
                <input type="text" name="cari" class="form-control" placeholder="Pasien / Dokter / No.Order…" value="<?= htmlspecialchars($cari) ?>" style="width:220px;">
              </div>
              <div class="form-group">
                <label style="margin-right:5px;">Status SR:</label>
                <select name="status" class="form-control">
                  <option value=""         <?= $filter_status===''         ?'selected':'' ?>>Semua</option>
                  <option value="terkirim" <?= $filter_status==='terkirim' ?'selected':'' ?>>Terkirim</option>
                  <option value="pending"  <?= $filter_status==='pending'  ?'selected':'' ?>>Pending</option>
                  <option value="error"    <?= $filter_status==='error'    ?'selected':'' ?>>Error</option>
                </select>
              </div>
              <div class="form-group">
                <label style="margin-right:5px;">Imaging:</label>
                <select name="is" class="form-control">
                  <option value=""         <?= $filter_is===''         ?'selected':'' ?>>Semua</option>
                  <option value="terkirim" <?= $filter_is==='terkirim' ?'selected':'' ?>>IS Terkirim</option>
                  <option value="pending"  <?= $filter_is==='pending'  ?'selected':'' ?>>IS Pending</option>
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
              <a href="data_service_request.php" class="btn btn-default"><i class="fa fa-refresh"></i> Reset</a>
            </form>
          </div>
        </div>

        <!-- Tabel utama -->
        <div class="box box-primary">
          <div class="box-header with-border">
            <h3 class="box-title">
              <i class="fa fa-table"></i> Data Service Request
              <span class="badge" style="background:#605ca8;"><?= number_format($total) ?></span>
            </h3>
            <div class="box-tools pull-right">
              <div class="action-bar">
                <button id="btnKirimSemua" onclick="kirimSemua()" class="btn-kirim-semua" <?= $st_pending===0?'disabled':'' ?>>
                  <i class="fa fa-send"></i> Kirim Semua Pending
                  <?php if ($st_pending > 0): ?>
                  <span class="badge" style="background:#dd4b39;"><?= number_format($st_pending) ?></span>
                  <?php endif; ?>
                </button>
                <a href="?tanggal=<?= urlencode($filter_tanggal) ?>&cari=<?= urlencode($cari) ?>&status=<?= urlencode($filter_status) ?>&is=<?= urlencode($filter_is) ?>&limit=<?= $limit ?>"
                   class="btn btn-default btn-sm" title="Refresh"><i class="fa fa-refresh"></i></a>
              </div>
            </div>
          </div>

          <!-- Stats bar -->
          <div class="info-bar-stats">
            <div class="ibs-item"><i class="fa fa-database" style="color:#605ca8;"></i> Total: <span class="ibs-val" style="color:#605ca8;"><?= number_format($st_total) ?></span></div>
            <span class="ibs-sep">|</span>
            <div class="ibs-item"><i class="fa fa-check-circle" style="color:#00a65a;"></i> SR Terkirim: <span class="ibs-val" style="color:#00a65a;" id="ibsTerkirim"><?= number_format($st_terkirim) ?></span></div>
            <div class="ibs-item"><i class="fa fa-clock-o" style="color:#f39c12;"></i> SR Pending: <span class="ibs-val" style="color:#f39c12;" id="ibsPending"><?= number_format($st_pending) ?></span></div>
            <?php if ($st_error > 0): ?>
            <div class="ibs-item"><i class="fa fa-times-circle" style="color:#dd4b39;"></i> SR Error: <span class="ibs-val" style="color:#dd4b39;"><?= number_format($st_error) ?></span></div>
            <?php endif; ?>
            <span class="ibs-sep">|</span>
            <div class="ibs-item"><i class="fa fa-image" style="color:#0073b7;"></i> IS Terkirim: <span class="ibs-val" style="color:#0073b7;"><?= number_format($st_is) ?></span></div>
            <?php if ($st_is_err > 0): ?>
            <div class="ibs-item"><i class="fa fa-times-circle" style="color:#dd4b39;"></i> IS Error: <span class="ibs-val" style="color:#dd4b39;"><?= number_format($st_is_err) ?></span></div>
            <?php endif; ?>
          </div>

          <!-- Dual progress bar -->
          <div class="prog-dual">
            <div class="prog-row">
              <span class="prog-label"><i class="fa fa-file-text-o"></i> Service Request</span>
              <div class="prog-bar"><div class="prog-fill-sr" style="width:<?= $pct_sr ?>%;"></div></div>
              <span class="prog-pct"><?= $pct_sr ?>%</span>
            </div>
            <div class="prog-row">
              <span class="prog-label"><i class="fa fa-image"></i> Imaging Study</span>
              <div class="prog-bar"><div class="prog-fill-is" style="width:<?= $pct_is ?>%;"></div></div>
              <span class="prog-pct"><?= $pct_is ?>%</span>
            </div>
          </div>

          <div class="box-body" style="padding:0;">
            <?php if (!empty($data)): ?>
            <div class="table-responsive">
              <table class="table table-bordered table-striped table-hover tbl-sr" style="margin-bottom:0;font-size:12.5px;">
                <thead>
                  <tr>
                    <th width="36" class="text-center">#</th>
                    <th width="40" class="text-center">Kirim</th>
                    <th width="140">No. Order</th>
                    <th width="110">Kd. Jenis Prw</th>
                    <th width="170">Pasien</th>
                    <th width="140">Dokter / Poli</th>
                    <th>Diagnosa</th>
                    <th width="100">Waktu</th>
                    <th width="65" class="text-center">Rawat</th>
                    <th width="200">ID ServiceRequest</th>
                    <th width="200">ID ImagingStudy</th>
                    <th width="130" class="text-center">Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $no = $offset + 1;
                  foreach ($data as $r):
                      $srOk      = ($r['status_kirim_sr'] ?? '') === 'terkirim';
                      $srErr     = ($r['status_kirim_sr'] ?? '') === 'error';
                      $isOk      = ($r['status_kirim_is'] ?? '') === 'terkirim';
                      $isErr     = ($r['status_kirim_is'] ?? '') === 'error';
                      $notReady  = empty($r['ihs_number']) || empty($r['id_encounter']);
                      $rowClass  = $srErr ? 'row-error' : ($notReady && !$srOk ? 'row-notready' : ($srOk ? 'row-sent' : ''));
                      $umur = '';
                      if (!empty($r['tgl_lahir'])) {
                          $umur = (new DateTime())->diff(new DateTime($r['tgl_lahir']))->y . ' th';
                      }
                      $shortSR = !empty($r['id_servicerequest']) ? mb_strimwidth($r['id_servicerequest'], 0, 36, '…') : '';
                      $shortIS = !empty($r['id_imagingstudy'])   ? mb_strimwidth($r['id_imagingstudy'],   0, 36, '…') : '';
                  ?>
                  <tr class="<?= $rowClass ?>">

                    <td class="text-center" style="color:#aaa;font-size:11px;font-weight:600;"><?= $no++ ?></td>

                    <!-- Tombol kirim -->
                    <td class="text-center">
                      <?php if ($notReady && !$srOk): ?>
                        <span class="btn-send" style="background:#f39c12;border-color:#e67e22;cursor:default;"
                              title="IHS/Encounter belum ada — lengkapi data pasien &amp; dokter terlebih dahulu">
                          <i class="fa fa-exclamation"></i>
                        </span>
                      <?php else: ?>
                        <button class="btn-send <?= $srOk ? 'sent' : ($srErr ? 'error-st' : '') ?>"
                                onclick="kirimSatu('<?= addslashes($r['noorder']) ?>','<?= addslashes($r['kd_jenis_prw']) ?>',this)"
                                title="<?= $srOk ? 'Kirim Ulang SR' : ($srErr ? 'Retry — Error: '.htmlspecialchars($r['error_msg_sr']??'') : 'Kirim ke Satu Sehat') ?>">
                          <i class="fa fa-<?= $srOk ? 'refresh' : ($srErr ? 'exclamation-triangle' : 'send') ?>"></i>
                        </button>
                      <?php endif; ?>
                    </td>

                    <!-- No. Order -->
                    <td>
                      <div class="noorder-lbl"><?= htmlspecialchars($r['noorder']) ?></div>
                      <div class="rm-lbl"><?= htmlspecialchars($r['no_rawat']) ?></div>
                    </td>

                    <!-- Kd Jenis Prw -->
                    <td>
                      <span class="kd-lbl"><?= htmlspecialchars($r['kd_jenis_prw']) ?></span>
                      <?php if (!empty($r['nm_jenis_prw'])): ?>
                      <div style="font-size:10px;color:#999;margin-top:2px;"><?= htmlspecialchars($r['nm_jenis_prw']) ?></div>
                      <?php endif; ?>
                    </td>

                    <!-- Pasien -->
                    <td>
                      <div class="nm-pasien"><?= htmlspecialchars($r['nm_pasien']) ?></div>
                      <div class="rm-lbl">
                        <?= htmlspecialchars($r['no_rkm_medis']) ?>
                        <?= $umur ? " · $umur" : '' ?>
                        <?= $r['jk'] ? " · ".htmlspecialchars($r['jk']) : '' ?>
                      </div>
                      <?php if (!empty($r['ihs_number'])): ?>
                        <div class="ihs-lbl"><i class="fa fa-id-card-o"></i> <?= htmlspecialchars($r['ihs_number']) ?></div>
                      <?php else: ?>
                        <div class="ihs-missing"><i class="fa fa-warning"></i> IHS belum ada</div>
                      <?php endif; ?>
                    </td>

                    <!-- Dokter / Poli -->
                    <td>
                      <div style="font-size:12px;font-weight:600;color:#444;"><?= htmlspecialchars($r['nm_dokter'] ?: '-') ?></div>
                      <div class="rm-lbl"><?= htmlspecialchars($r['nm_poli'] ?: '-') ?></div>
                      <?php if (empty($r['ihs_dokter'])): ?>
                        <div class="ihs-missing"><i class="fa fa-warning"></i> IHS dokter kosong</div>
                      <?php endif; ?>
                    </td>

                    <!-- Diagnosa -->
                    <td>
                      <div style="font-size:12px;color:#555;line-height:1.4;"><?= htmlspecialchars($r['diagnosa_klinis'] ?: '-') ?></div>
                      <?php if (!empty($r['informasi_tambahan'])): ?>
                        <div style="font-size:10.5px;color:#aaa;margin-top:2px;"><i class="fa fa-info-circle"></i> <?= htmlspecialchars($r['informasi_tambahan']) ?></div>
                      <?php endif; ?>
                    </td>

                    <!-- Waktu -->
                    <td>
                      <div class="waktu-lbl"><?= date('H:i', strtotime($r['jam_permintaan'])) ?> WIB</div>
                      <div class="waktu-sub"><?= date('d/m/Y', strtotime($r['tgl_permintaan'])) ?></div>
                      <?php if (!empty($r['tgl_hasil']) && $r['tgl_hasil'] !== '0000-00-00'): ?>
                        <div style="font-size:10.5px;color:#00a65a;margin-top:2px;">
                          <i class="fa fa-check"></i> <?= date('d/m H:i', strtotime($r['tgl_hasil'].' '.$r['jam_hasil'])) ?>
                        </div>
                      <?php endif; ?>
                    </td>

                    <!-- Rawat -->
                    <td class="text-center">
                      <span class="badge-<?= strtolower($r['status_rawat'] ?? '') === 'ranap' ? 'ranap' : 'ralan' ?>">
                        <?= strtoupper($r['status_rawat'] ?? '-') ?>
                      </span>
                    </td>

                    <!-- ID ServiceRequest -->
                    <td class="id-sr-cell">
                      <?php if (!empty($r['id_servicerequest'])): ?>
                        <span class="id-cell" title="<?= htmlspecialchars($r['id_servicerequest']) ?>"><?= htmlspecialchars($shortSR) ?></span>
                        <button class="btn-copy" onclick="copyText('<?= addslashes($r['id_servicerequest']) ?>')" title="Salin ID SR"><i class="fa fa-copy"></i></button>
                        <?php if (!empty($r['tgl_kirim_sr'])): ?>
                          <div style="font-size:10px;color:#aaa;margin-top:2px;"><?= date('d/m H:i', strtotime($r['tgl_kirim_sr'])) ?></div>
                        <?php endif; ?>
                      <?php elseif ($srErr): ?>
                        <span class="err-tooltip" title="<?= htmlspecialchars($r['error_msg_sr'] ?? '') ?>">
                          <i class="fa fa-times-circle text-red"></i> Error
                        </span>
                      <?php else: ?>
                        <span class="no-id"><i class="fa fa-minus-circle"></i> Belum dikirim</span>
                      <?php endif; ?>
                    </td>

                    <!-- ID ImagingStudy -->
                    <td>
                      <?php if (!empty($r['id_imagingstudy'])): ?>
                        <span class="id-cell" title="<?= htmlspecialchars($r['id_imagingstudy']) ?>"><?= htmlspecialchars($shortIS) ?></span>
                        <button class="btn-copy" onclick="copyText('<?= addslashes($r['id_imagingstudy']) ?>')" title="Salin ID IS"><i class="fa fa-copy"></i></button>
                        <?php if (!empty($r['tgl_kirim_is'])): ?>
                          <div style="font-size:10px;color:#aaa;margin-top:2px;"><?= date('d/m H:i', strtotime($r['tgl_kirim_is'])) ?></div>
                        <?php endif; ?>
                      <?php elseif ($isErr): ?>
                        <span class="err-tooltip" title="<?= htmlspecialchars($r['error_msg_is'] ?? '') ?>">
                          <i class="fa fa-times-circle text-red"></i> Error IS
                        </span>
                      <?php elseif (!empty($r['study_uid_dicom'])): ?>
                        <span style="font-size:10.5px;color:#f39c12;"><i class="fa fa-clock-o"></i> DICOM ada, menunggu SR</span>
                      <?php else: ?>
                        <span class="no-id"><i class="fa fa-minus-circle"></i> Belum ada DICOM</span>
                      <?php endif; ?>
                    </td>

                    <!-- Status kolom gabungan -->
                    <td class="text-center">
                      <div class="status-col">
                        <span class="badge-sr-status <?= $srOk ? 'badge-sr-ok' : ($srErr ? 'badge-sr-err' : 'badge-sr-pend') ?>">
                          <i class="fa fa-<?= $srOk ? 'check-circle' : ($srErr ? 'times-circle' : 'clock-o') ?>"></i>
                          SR <?= $srOk ? 'OK' : ($srErr ? 'Error' : 'Pending') ?>
                        </span>
                        <span class="<?= $isOk ? 'badge-is-ok' : ($isErr ? 'badge-is-err' : 'badge-is-pend') ?>">
                          <i class="fa fa-<?= $isOk ? 'image' : ($isErr ? 'times-circle' : 'clock-o') ?>"></i>
                          IS <?= $isOk ? 'OK' : ($isErr ? 'Error' : 'Pending') ?>
                        </span>
                      </div>
                    </td>

                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="box-footer clearfix">
              <div class="pull-left" style="font-size:13px;color:#666;padding:7px 0;">
                Menampilkan <strong><?= number_format($offset+1) ?></strong>–<strong><?= number_format(min($offset+$limit,$total)) ?></strong> dari <strong><?= number_format($total) ?></strong> data
              </div>
              <ul class="pagination pagination-sm no-margin pull-right">
                <?php
                $qBase = "tanggal=".urlencode($filter_tanggal)."&cari=".urlencode($cari)."&status=".urlencode($filter_status)."&is=".urlencode($filter_is)."&limit=$limit";
                if ($page > 1): ?>
                <li><a href="?page=<?= $page-1 ?>&<?= $qBase ?>">«</a></li>
                <?php endif;
                for ($i = max(1,$page-3); $i <= min($total_pages,$page+3); $i++): ?>
                <li <?= $i==$page?'class="active"':'' ?>><a href="?page=<?= $i ?>&<?= $qBase ?>"><?= $i ?></a></li>
                <?php endfor;
                if ($page < $total_pages): ?>
                <li><a href="?page=<?= $page+1 ?>&<?= $qBase ?>">»</a></li>
                <?php endif; ?>
              </ul>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <div style="padding:50px;text-align:center;">
              <i class="fa fa-inbox" style="font-size:52px;color:#ddd;display:block;margin-bottom:14px;"></i>
              <h4 style="color:#aaa;font-weight:400;">
                <?= ($cari||$filter_status||$filter_is) ? "Tidak ada data untuk filter yang dipilih" : "Tidak ada permintaan radiologi pada <strong>".date('d F Y',strtotime($filter_tanggal))."</strong>" ?>
              </h4>
              <?php if ($cari||$filter_status||$filter_is): ?>
              <a href="?tanggal=<?= urlencode($filter_tanggal) ?>" class="btn btn-default btn-sm"><i class="fa fa-refresh"></i> Reset Filter</a>
              <?php endif; ?>
            </div>
            <?php endif; ?>

          </div>
        </div>

      </div>
    </div>
  </section>
</div>

<?php include 'includes/footer.php'; ?>
