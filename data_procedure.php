<?php
/**
 * data_procedure.php
 * Monitoring & pengiriman Procedure ke Satu Sehat FHIR API
 * Tabel: satu_sehat_procedure (Khanza) + reg_periksa
 * Filter: tanggal dari - sampai
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

// ── AJAX ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['kirim', 'kirim_semua'])) {
        include __DIR__ . '/api/kirim_procedure.php';
        exit;
    }
}

// ── Filter & Pagination ──────────────────────────────────────────
$tgl_dari      = $_GET['tgl_dari']  ?? date('Y-m-d');
$tgl_sampai    = $_GET['tgl_sampai']?? date('Y-m-d');
$cari          = $_GET['cari']      ?? '';
$filter_status = $_GET['status']    ?? '';
$filter_lanjut = $_GET['lanjut']    ?? '';
$limit         = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 20;
$page          = isset($_GET['page'])  ? max(1, intval($_GET['page']))  : 1;
$offset        = ($page - 1) * $limit;

// Validasi tanggal
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_dari))   $tgl_dari   = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_sampai)) $tgl_sampai = date('Y-m-d');
if ($tgl_sampai < $tgl_dari) $tgl_sampai = $tgl_dari;

// ── Ambil Data ───────────────────────────────────────────────────
try {
    $wheres = ["r.tgl_registrasi BETWEEN ? AND ?"];
    $params = [$tgl_dari, $tgl_sampai];

    if (!empty($cari)) {
        $wheres[] = "(sp.no_rawat LIKE ? OR sp.kode LIKE ? OR sp.id_procedure LIKE ?
                      OR p.nm_pasien LIKE ? OR p.no_rkm_medis LIKE ? OR d.nm_dokter LIKE ?)";
        $params = array_merge($params, array_fill(0, 6, "%$cari%"));
    }
    if ($filter_status === 'terkirim') {
        $wheres[] = "(sp.id_procedure IS NOT NULL AND sp.id_procedure != '')";
    } elseif ($filter_status === 'pending') {
        $wheres[] = "(sp.id_procedure IS NULL OR sp.id_procedure = '')";
    }
    if (!empty($filter_lanjut)) {
        $wheres[] = "sp.status = ?";
        $params[] = $filter_lanjut;
    }

    $where_sql = "WHERE " . implode(" AND ", $wheres);

    $base_join = "
        FROM satu_sehat_procedure sp
        JOIN reg_periksa r       ON sp.no_rawat     = r.no_rawat
        JOIN pasien p            ON r.no_rkm_medis   = p.no_rkm_medis
        LEFT JOIN dokter d       ON r.kd_dokter      = d.kd_dokter
        LEFT JOIN poliklinik pl  ON r.kd_poli        = pl.kd_poli
        LEFT JOIN penjab pj      ON r.kd_pj          = pj.kd_pj
    ";

    $stmtCount = $pdo_simrs->prepare("SELECT COUNT(*) $base_join $where_sql");
    $stmtCount->execute($params);
    $total       = (int)$stmtCount->fetchColumn();
    $total_pages = max(1, ceil($total / $limit));

    $stmtData = $pdo_simrs->prepare(
        "SELECT
            sp.no_rawat, sp.kode, sp.status AS status_rawat, sp.id_procedure,
            r.no_reg, r.tgl_registrasi, r.jam_reg,
            r.status_lanjut, r.stts_daftar, r.kd_pj,
            r.umurdaftar, r.sttsumur,
            p.no_rkm_medis, p.nm_pasien, p.jk, p.tgl_lahir,
            d.nm_dokter,
            pl.nm_poli,
            pj.png_jawab
         $base_join $where_sql
         ORDER BY r.tgl_registrasi DESC, r.jam_reg DESC
         LIMIT " . intval($limit) . " OFFSET " . intval($offset)
    );
    $stmtData->execute($params);
    $data = $stmtData->fetchAll(PDO::FETCH_ASSOC);

    // Stats
    $stmtStats = $pdo_simrs->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN sp.id_procedure IS NOT NULL AND sp.id_procedure != '' THEN 1 ELSE 0 END) AS terkirim,
            SUM(CASE WHEN sp.id_procedure IS NULL OR sp.id_procedure = '' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN sp.status = 'Ralan' THEN 1 ELSE 0 END) AS ralan,
            SUM(CASE WHEN sp.status = 'Ranap' THEN 1 ELSE 0 END) AS ranap
        FROM satu_sehat_procedure sp
        JOIN reg_periksa r ON sp.no_rawat = r.no_rawat
        WHERE r.tgl_registrasi BETWEEN ? AND ?
    ");
    $stmtStats->execute([$tgl_dari, $tgl_sampai]);
    $stats       = $stmtStats->fetch(PDO::FETCH_ASSOC);
    $st_total    = (int)($stats['total']    ?? 0);
    $st_terkirim = (int)($stats['terkirim'] ?? 0);
    $st_pending  = (int)($stats['pending']  ?? 0);
    $st_ralan    = (int)($stats['ralan']    ?? 0);
    $st_ranap    = (int)($stats['ranap']    ?? 0);
    $dbError     = null;

} catch (Exception $e) {
    $data = []; $total = 0; $total_pages = 1;
    $st_total = $st_terkirim = $st_pending = $st_ralan = $st_ranap = 0;
    $dbError = $e->getMessage();
}

$pct = $st_total > 0 ? round(($st_terkirim / $st_total) * 100) : 0;
$page_title = 'Procedure — Satu Sehat';

$tgl_dari_fmt    = date('d F Y', strtotime($tgl_dari));
$tgl_sampai_fmt  = date('d F Y', strtotime($tgl_sampai));
$periode_label   = $tgl_dari === $tgl_sampai ? $tgl_dari_fmt : "$tgl_dari_fmt s/d $tgl_sampai_fmt";

$extra_css = '
:root { --pro-primary:#1565c0; --pro-dark:#0d47a1; --pro-soft:#e3f2fd; --pro-border:#bbdefb; }

.btn-send{width:32px;height:32px;border-radius:6px;padding:0;display:inline-flex;align-items:center;justify-content:center;border:1px solid transparent;cursor:pointer;transition:all .25s;font-size:12px;background:var(--pro-primary);border-color:var(--pro-dark);color:#fff}
.btn-send:hover{background:var(--pro-dark);transform:scale(1.12);box-shadow:0 3px 10px rgba(21,101,192,.4);color:#fff}
.btn-send:disabled{opacity:.5;cursor:not-allowed;transform:none}
.btn-send.sent{background:#00a65a;border-color:#008d4c}
.btn-send.sent:hover{background:#008d4c}
.btn-send.spin i{animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

.btn-copy{width:22px;height:22px;border-radius:4px;padding:0;display:inline-flex;align-items:center;justify-content:center;background:#ecf0f1;border:1px solid #ddd;color:#777;cursor:pointer;transition:all .2s;font-size:10px;vertical-align:middle;margin-left:3px}
.btn-copy:hover{background:#d5d8dc;color:#333}

.id-cell{font-family:"Courier New",monospace;font-size:10.5px;color:#555;word-break:break-all}
.no-id{font-style:italic;color:#ccc;font-size:11px}

.badge-sent{background:#dff0d8;color:#3c763d;border:1px solid #c3e6cb;padding:3px 9px;border-radius:10px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px}
.badge-pending{background:#f2dede;color:#a94442;border:1px solid #f5c6cb;padding:3px 9px;border-radius:10px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px}
.badge-ralan{background:#d9edf7;color:#31708f;border:1px solid #bce8f1;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700}
.badge-ranap{background:#fcf8e3;color:#8a6d3b;border:1px solid #faebcc;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700}
.row-sent{background:#f0fff4!important}

.norawat-lbl{font-weight:700;color:var(--pro-primary);font-size:13px;font-family:"Courier New",monospace}
.rm-lbl{font-size:10.5px;color:#aaa;font-family:"Courier New",monospace}
.nm-pasien{font-weight:700;color:#333;font-size:13px}
.kode-lbl{display:inline-block;background:var(--pro-soft);border:1px solid var(--pro-border);color:var(--pro-primary);padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;font-family:"Courier New",monospace}
.waktu-lbl{font-size:13px;font-weight:600;color:#333}
.waktu-sub{font-size:10.5px;color:#aaa;margin-top:2px}

.action-bar{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.btn-kirim-semua{background:linear-gradient(135deg,var(--pro-primary),var(--pro-dark));border:none;color:#fff;padding:6px 14px;border-radius:5px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .25s}
.btn-kirim-semua:hover{transform:translateY(-2px);box-shadow:0 4px 14px rgba(21,101,192,.4);color:#fff}
.btn-kirim-semua:disabled{opacity:.6;cursor:not-allowed;transform:none}

.info-bar{display:flex;gap:16px;padding:8px 15px;background:#f0f8ff;border-bottom:1px solid var(--pro-border);font-size:12px;flex-wrap:wrap;align-items:center}
.ibs-item{display:flex;align-items:center;gap:5px;color:#555}
.ibs-val{font-weight:700;font-size:14px}
.ibs-sep{color:#ddd}

.prog-row{display:flex;align-items:center;gap:10px;font-size:12px;padding:8px 15px;background:#f9f9f9;border-bottom:1px solid #eee}
.prog-bar{flex:1;height:7px;border-radius:4px;background:#e5e5e5;overflow:hidden}
.prog-fill{height:100%;border-radius:4px;background:linear-gradient(90deg,var(--pro-primary),#00a65a);transition:width .6s}
.prog-pct{width:38px;text-align:right;font-weight:700;color:#555}

.tbl-pro thead tr th{background:var(--pro-primary);color:#fff!important;white-space:nowrap;font-size:12px}
.tbl-pro tbody td{vertical-align:middle}

/* Date range picker styling */
.date-range-wrap{display:flex;align-items:center;gap:6px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;padding:5px 10px}
.date-range-wrap label{font-size:12px;color:#666;margin:0;white-space:nowrap}
.date-range-wrap input{border:none;background:transparent;font-size:13px;color:#333;padding:0;outline:none;width:130px}
.date-range-sep{color:#aaa;font-size:12px}

/* Shortcut buttons */
.btn-period{padding:3px 10px;border-radius:4px;font-size:11px;border:1px solid #dee2e6;background:#fff;color:#555;cursor:pointer;transition:all .2s}
.btn-period:hover,.btn-period.active{background:var(--pro-primary);color:#fff;border-color:var(--pro-primary)}

#toast-container{position:fixed;bottom:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.toast-msg{padding:10px 18px;border-radius:6px;font-size:13px;font-weight:600;color:#fff;display:flex;align-items:center;gap:8px;box-shadow:0 4px 12px rgba(0,0,0,.2);pointer-events:auto;animation:toastIn .3s ease}
.toast-success{background:#00a65a}.toast-error{background:#dd4b39}.toast-info{background:#00c0ef}.toast-warn{background:#f39c12}
@keyframes toastIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
';

$extra_js = '
// Shortcut periode
function setPeriode(jenis) {
    const now = new Date();
    let dari, sampai;
    sampai = now.toISOString().split("T")[0];
    if (jenis === "hari") {
        dari = sampai;
    } else if (jenis === "minggu") {
        const d = new Date(now); d.setDate(d.getDate() - 6);
        dari = d.toISOString().split("T")[0];
    } else if (jenis === "bulan") {
        dari = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().split("T")[0];
    } else if (jenis === "bulan_lalu") {
        const d = new Date(now.getFullYear(), now.getMonth() - 1, 1);
        dari = d.toISOString().split("T")[0];
        sampai = new Date(now.getFullYear(), now.getMonth(), 0).toISOString().split("T")[0];
    }
    document.getElementById("tgl_dari").value = dari;
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

function copyText(text) {
    navigator.clipboard.writeText(text)
        .then(() => showToast("ID disalin!", "success"))
        .catch(() => {
            const ta = document.createElement("textarea");
            ta.value = text; document.body.appendChild(ta); ta.select();
            document.execCommand("copy"); document.body.removeChild(ta);
            showToast("ID disalin!", "success");
        });
}

function kirimSatu(noRawat, kode, btnEl) {
    btnEl.disabled = true;
    const origHTML = btnEl.innerHTML;
    btnEl.classList.add("spin");
    btnEl.innerHTML = `<i class="fa fa-spinner"></i>`;
    fetch(window.location.href, {
        method: "POST",
        headers: {"Content-Type":"application/x-www-form-urlencoded"},
        body: new URLSearchParams({action:"kirim", no_rawat: noRawat, kode})
    })
    .then(r => r.json())
    .then(resp => {
        btnEl.classList.remove("spin");
        if (resp.status === "ok") {
            btnEl.classList.add("sent");
            btnEl.innerHTML = `<i class="fa fa-check"></i>`;
            btnEl.title = "Terkirim — " + (resp.id_procedure || "");
            showToast("Procedure berhasil dikirim!", "success");
            const row = btnEl.closest("tr");
            if (row) {
                const b = row.querySelector(".badge-status");
                if (b) { b.className = "badge-status badge-sent"; b.innerHTML = `<i class="fa fa-check-circle"></i> Terkirim`; }
                row.classList.add("row-sent");
                const idCell = row.querySelector(".id-pro-cell");
                if (idCell && resp.id_procedure) {
                    idCell.innerHTML = `<span class="id-cell" title="${resp.id_procedure}">${resp.id_procedure.substring(0,36)}</span>
                        <button class="btn-copy" onclick="copyText(\'${resp.id_procedure}\')" title="Salin"><i class="fa fa-copy"></i></button>`;
                }
            }
            const ep = document.getElementById("ibsPending");
            const et = document.getElementById("ibsTerkirim");
            if (ep) ep.textContent = Math.max(0, parseInt(ep.textContent) - 1);
            if (et) et.textContent = parseInt(et.textContent || 0) + 1;
        } else {
            btnEl.disabled = false;
            btnEl.innerHTML = origHTML;
            showToast("Gagal: " + (resp.message || ""), "error");
        }
    })
    .catch(() => {
        btnEl.disabled = false; btnEl.innerHTML = origHTML; btnEl.classList.remove("spin");
        showToast("Koneksi gagal", "error");
    });
}

function kirimSemua() {
    const dari    = document.getElementById("tgl_dari")?.value || "";
    const sampai  = document.getElementById("tgl_sampai")?.value || "";
    if (!confirm(`Kirim semua Procedure pending ke Satu Sehat?\nPeriode: ${dari} s/d ${sampai}`)) return;
    const btn = document.getElementById("btnKirimSemua");
    if (btn) { btn.disabled = true; btn.innerHTML = `<i class="fa fa-spinner fa-spin"></i> Mengirim…`; }
    fetch(window.location.href, {
        method: "POST",
        headers: {"Content-Type":"application/x-www-form-urlencoded"},
        body: new URLSearchParams({action:"kirim_semua", tgl_dari: dari, tgl_sampai: sampai})
    })
    .then(r => r.json())
    .then(resp => {
        if (btn) { btn.disabled = false; btn.innerHTML = `<i class="fa fa-send"></i> Kirim Semua Pending`; }
        if (resp.status === "ok") {
            showToast(`Selesai: ${resp.berhasil} berhasil, ${resp.gagal} gagal dari ${resp.jumlah} data.`, resp.gagal > 0 ? "warn" : "success");
            setTimeout(() => location.reload(), 2000);
        } else {
            showToast("Gagal: " + (resp.message || ""), "error");
        }
    })
    .catch(() => {
        if (btn) { btn.disabled = false; btn.innerHTML = `<i class="fa fa-send"></i> Kirim Semua Pending`; }
        showToast("Koneksi gagal", "error");
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
      <i class="fa fa-plus-square" style="color:#1565c0;"></i>
      Procedure
      <small>Satu Sehat &mdash; <?= $periode_label ?></small>
    </h1>
    <ol class="breadcrumb">
      <li><a href="dashboard.php"><i class="fa fa-dashboard"></i> Home</a></li>
      <li>Satu Sehat</li>
      <li class="active">Procedure</li>
    </ol>
  </section>

  <section class="content">

    <?php if ($dbError): ?>
    <div class="callout callout-danger">
      <h4><i class="fa fa-ban"></i> Error Database</h4>
      <p><?= htmlspecialchars($dbError) ?></p>
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
            <form method="GET" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">

              <!-- Date range -->
              <div class="form-group">
                <label style="display:block;margin-bottom:4px;font-size:12px;"><i class="fa fa-calendar"></i> Periode:</label>
                <div class="date-range-wrap">
                  <label>Dari</label>
                  <input type="date" name="tgl_dari" id="tgl_dari" value="<?= htmlspecialchars($tgl_dari) ?>">
                  <span class="date-range-sep">—</span>
                  <label>Sampai</label>
                  <input type="date" name="tgl_sampai" id="tgl_sampai" value="<?= htmlspecialchars($tgl_sampai) ?>">
                </div>
              </div>

              <!-- Shortcut periode -->
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
                <label style="display:block;margin-bottom:4px;font-size:12px;">Cari:</label>
                <input type="text" name="cari" class="form-control" placeholder="Pasien / Dokter / Kode Procedure…" value="<?= htmlspecialchars($cari) ?>" style="width:210px;">
              </div>

              <div class="form-group">
                <label style="display:block;margin-bottom:4px;font-size:12px;">Status:</label>
                <select name="status" class="form-control">
                  <option value=""         <?= $filter_status===''         ?'selected':'' ?>>Semua</option>
                  <option value="terkirim" <?= $filter_status==='terkirim' ?'selected':'' ?>>Terkirim</option>
                  <option value="pending"  <?= $filter_status==='pending'  ?'selected':'' ?>>Pending</option>
                </select>
              </div>

              <div class="form-group">
                <label style="display:block;margin-bottom:4px;font-size:12px;">Jenis:</label>
                <select name="lanjut" class="form-control">
                  <option value=""      <?= $filter_lanjut===''      ?'selected':'' ?>>Semua</option>
                  <option value="Ralan" <?= $filter_lanjut==='Ralan' ?'selected':'' ?>>Ralan</option>
                  <option value="Ranap" <?= $filter_lanjut==='Ranap' ?'selected':'' ?>>Ranap</option>
                </select>
              </div>

              <div class="form-group">
                <label style="display:block;margin-bottom:4px;font-size:12px;">Per halaman:</label>
                <select name="limit" class="form-control">
                  <?php foreach ([20,50,100,200] as $l): ?>
                  <option value="<?=$l?>" <?=$limit==$l?'selected':''?>><?=$l?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="form-group" style="align-self:flex-end;">
                <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i> Tampilkan</button>
                <a href="data_procedure.php" class="btn btn-default"><i class="fa fa-refresh"></i> Reset</a>
              </div>

            </form>
          </div>
        </div>

        <!-- Tabel -->
        <div class="box box-primary">
          <div class="box-header with-border" style="border-top:3px solid var(--pro-primary);">
            <h3 class="box-title" style="color:var(--pro-primary);">
              <i class="fa fa-table"></i> Data Procedure
              <span class="badge" style="background:var(--pro-primary);"><?= number_format($total) ?></span>
            </h3>
            <div class="box-tools pull-right">
              <div class="action-bar">
                <button id="btnKirimSemua" onclick="kirimSemua()" class="btn-kirim-semua" <?= $st_pending===0?'disabled':'' ?>>
                  <i class="fa fa-send"></i> Kirim Semua Pending
                  <?php if ($st_pending > 0): ?>
                  <span class="badge" style="background:#dd4b39;"><?= number_format($st_pending) ?></span>
                  <?php endif; ?>
                </button>
                <a href="?tgl_dari=<?= urlencode($tgl_dari) ?>&tgl_sampai=<?= urlencode($tgl_sampai) ?>&cari=<?= urlencode($cari) ?>&status=<?= urlencode($filter_status) ?>&lanjut=<?= urlencode($filter_lanjut) ?>&limit=<?= $limit ?>"
                   class="btn btn-default btn-sm" title="Refresh"><i class="fa fa-refresh"></i></a>
              </div>
            </div>
          </div>

          <!-- Stats bar -->
          <div class="info-bar">
            <div class="ibs-item"><i class="fa fa-database" style="color:var(--pro-primary);"></i> Total: <span class="ibs-val" style="color:var(--pro-primary);"><?= number_format($st_total) ?></span></div>
            <span class="ibs-sep">|</span>
            <div class="ibs-item"><i class="fa fa-check-circle" style="color:#00a65a;"></i> Terkirim: <span class="ibs-val" style="color:#00a65a;" id="ibsTerkirim"><?= number_format($st_terkirim) ?></span></div>
            <div class="ibs-item"><i class="fa fa-clock-o" style="color:#f39c12;"></i> Pending: <span class="ibs-val" style="color:#f39c12;" id="ibsPending"><?= number_format($st_pending) ?></span></div>
            <span class="ibs-sep">|</span>
            <div class="ibs-item"><i class="fa fa-ambulance" style="color:#31708f;"></i> Ralan: <span class="ibs-val" style="color:#31708f;"><?= number_format($st_ralan) ?></span></div>
            <div class="ibs-item"><i class="fa fa-bed" style="color:#8a6d3b;"></i> Ranap: <span class="ibs-val" style="color:#8a6d3b;"><?= number_format($st_ranap) ?></span></div>
          </div>

          <!-- Progress bar -->
          <div class="prog-row">
            <span style="width:100px;font-size:12px;color:#555;flex-shrink:0;"><i class="fa fa-plus-square"></i> Procedure</span>
            <div class="prog-bar"><div class="prog-fill" style="width:<?= $pct ?>%;"></div></div>
            <span class="prog-pct"><?= $pct ?>%</span>
          </div>

          <div class="box-body" style="padding:0;">
            <?php if (!empty($data)): ?>
            <div class="table-responsive">
              <table class="table table-bordered table-striped table-hover tbl-pro" style="margin-bottom:0;font-size:12.5px;">
                <thead>
                  <tr>
                    <th width="36"  class="text-center">#</th>
                    <th width="40"  class="text-center">Kirim</th>
                    <th width="160">No. Rawat</th>
                    <th width="170">Pasien</th>
                    <th width="150">Dokter / Poli</th>
                    <th width="140">Kode Procedure</th>
                    <th width="110">Tanggal</th>
                    <th width="70"  class="text-center">Jenis</th>
                    <th>ID Procedure</th>
                    <th width="120" class="text-center">Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $no = $offset + 1;
                  foreach ($data as $r):
                      $isSent  = !empty($r['id_procedure']);
                      $shortId = $isSent ? mb_strimwidth($r['id_procedure'], 0, 36, '…') : '';
                      $umur    = !empty($r['umurdaftar']) ? $r['umurdaftar'] . ' ' . ($r['sttsumur'] ?? 'Th') : '';
                  ?>
                  <tr class="<?= $isSent ? 'row-sent' : '' ?>">

                    <td class="text-center" style="color:#aaa;font-size:11px;font-weight:600;"><?= $no++ ?></td>

                    <td class="text-center">
                      <button class="btn-send <?= $isSent?'sent':'' ?>"
                              onclick="kirimSatu('<?= addslashes($r['no_rawat']) ?>','<?= addslashes($r['kode']) ?>',this)"
                              title="<?= $isSent?'Kirim Ulang':'Kirim ke Satu Sehat' ?>">
                        <i class="fa fa-<?= $isSent?'refresh':'send' ?>"></i>
                      </button>
                    </td>

                    <td>
                      <div class="norawat-lbl"><?= htmlspecialchars($r['no_rawat']) ?></div>
                      <div class="rm-lbl">No.Reg: <?= htmlspecialchars($r['no_reg'] ?? '-') ?></div>
                    </td>

                    <td>
                      <div class="nm-pasien"><?= htmlspecialchars($r['nm_pasien']) ?></div>
                      <div class="rm-lbl">
                        <?= htmlspecialchars($r['no_rkm_medis']) ?>
                        <?= $umur ? " · $umur" : '' ?>
                        <?= !empty($r['jk']) ? ' · '.htmlspecialchars($r['jk']) : '' ?>
                      </div>
                    </td>

                    <td>
                      <div style="font-size:12px;font-weight:600;color:#444;"><?= htmlspecialchars($r['nm_dokter'] ?: '-') ?></div>
                      <div class="rm-lbl"><?= htmlspecialchars($r['nm_poli'] ?: '-') ?></div>
                    </td>

                    <td>
                      <span class="kode-lbl"><?= htmlspecialchars($r['kode']) ?></span>
                    </td>

                    <td>
                      <div class="waktu-lbl"><?= date('d/m/Y', strtotime($r['tgl_registrasi'])) ?></div>
                      <div class="waktu-sub"><?= date('H:i', strtotime($r['jam_reg'])) ?> WIB</div>
                    </td>

                    <td class="text-center">
                      <span class="badge-<?= strtolower($r['status_rawat']) === 'ranap' ? 'ranap' : 'ralan' ?>">
                        <?= htmlspecialchars($r['status_rawat']) ?>
                      </span>
                    </td>

                    <td class="id-pro-cell">
                      <?php if ($isSent): ?>
                        <span class="id-cell" title="<?= htmlspecialchars($r['id_procedure']) ?>"><?= htmlspecialchars($shortId) ?></span>
                        <button class="btn-copy" onclick="copyText('<?= addslashes($r['id_procedure']) ?>')" title="Salin"><i class="fa fa-copy"></i></button>
                      <?php else: ?>
                        <span class="no-id"><i class="fa fa-minus-circle"></i> Belum dikirim</span>
                      <?php endif; ?>
                    </td>

                    <td class="text-center">
                      <span class="badge-status <?= $isSent?'badge-sent':'badge-pending' ?>">
                        <i class="fa fa-<?= $isSent?'check-circle':'clock-o' ?>"></i>
                        <?= $isSent?'Terkirim':'Pending' ?>
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
                dari <strong><?= number_format($total) ?></strong> data
                &nbsp;|&nbsp; Periode: <strong><?= $periode_label ?></strong>
              </div>
              <ul class="pagination pagination-sm no-margin pull-right">
                <?php
                $qBase = "tgl_dari=".urlencode($tgl_dari)."&tgl_sampai=".urlencode($tgl_sampai)."&cari=".urlencode($cari)."&status=".urlencode($filter_status)."&lanjut=".urlencode($filter_lanjut)."&limit=$limit";
                if ($page>1): ?><li><a href="?page=<?=$page-1?>&<?=$qBase?>">«</a></li><?php endif;
                for ($i=max(1,$page-3); $i<=min($total_pages,$page+3); $i++):?>
                <li <?=$i==$page?'class="active"':''?>><a href="?page=<?=$i?>&<?=$qBase?>"><?=$i?></a></li>
                <?php endfor;
                if ($page<$total_pages): ?><li><a href="?page=<?=$page+1?>&<?=$qBase?>">»</a></li><?php endif; ?>
              </ul>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <div style="padding:50px;text-align:center;">
              <i class="fa fa-plus-square" style="font-size:52px;color:#ddd;display:block;margin-bottom:14px;"></i>
              <h4 style="color:#aaa;font-weight:400;">
                <?= ($cari||$filter_status||$filter_lanjut)
                    ? "Tidak ada data untuk filter yang dipilih"
                    : "Tidak ada Procedure pada periode <strong>$periode_label</strong>" ?>
              </h4>
              <?php if ($cari||$filter_status||$filter_lanjut): ?>
              <a href="?tgl_dari=<?= urlencode($tgl_dari) ?>&tgl_sampai=<?= urlencode($tgl_sampai) ?>" class="btn btn-default btn-sm"><i class="fa fa-refresh"></i> Reset Filter</a>
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