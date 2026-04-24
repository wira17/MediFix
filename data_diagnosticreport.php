<?php
session_start();
include 'koneksi.php';
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$nama = $_SESSION['nama'] ?? 'Pengguna';

// ── AJAX POST ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['kirim', 'kirim_semua', 'status'])) {
        include __DIR__ . '/api/kirim_diagnosticreport.php';
        exit;
    }
}

// ── Filter & Pagination ──────────────────────────────────────────
$tgl_dari      = $_GET['tgl_dari']   ?? date('Y-m-d');
$tgl_sampai    = $_GET['tgl_sampai'] ?? date('Y-m-d');
$cari          = $_GET['cari']       ?? '';
$filter_status = $_GET['status']     ?? '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_dari))   $tgl_dari   = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_sampai)) $tgl_sampai = date('Y-m-d');
if ($tgl_sampai < $tgl_dari) $tgl_sampai = $tgl_dari;
$limit  = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 20;
$page   = isset($_GET['page'])  ? max(1, intval($_GET['page']))  : 1;
$offset = ($page - 1) * $limit;

// ── AUTO-SYNC: Insert + Update medifix_ss_diagnosticreport_radiologi ────────
try {
    // 1) Insert row baru yang belum ada sama sekali
    $pdo_simrs->exec("
        INSERT IGNORE INTO medifix_ss_diagnosticreport_radiologi
            (noorder, kd_jenis_prw, no_rawat,
             id_diagnosticreport, id_servicerequest, id_imagingstudy,
             status_kirim)
        SELECT
            sdr.noorder,
            sdr.kd_jenis_prw,
            pr.no_rawat,
            sdr.id_diagnosticreport,
            COALESCE(msr.id_servicerequest, ssr.id_servicerequest),
            msr.id_imagingstudy,
            CASE
                WHEN sdr.id_diagnosticreport IS NOT NULL AND sdr.id_diagnosticreport != ''
                THEN 'terkirim'
                ELSE 'pending'
            END
        FROM satu_sehat_diagnosticreport_radiologi sdr
        JOIN permintaan_radiologi pr          ON sdr.noorder = pr.noorder
        JOIN hasil_radiologi hr               ON pr.no_rawat = hr.no_rawat
        LEFT JOIN medifix_ss_radiologi msr    ON sdr.noorder = msr.noorder
        LEFT JOIN satu_sehat_servicerequest_radiologi ssr ON sdr.noorder = ssr.noorder
        WHERE NOT EXISTS (
            SELECT 1 FROM medifix_ss_diagnosticreport_radiologi mdr
            WHERE mdr.noorder = sdr.noorder
        )
    ");

    // 2) Update row yang sudah ada tapi id_diagnosticreport masih NULL
    //    Khanza sudah kirim duluan — ambil ID-nya, tandai terkirim
    $pdo_simrs->exec("
        UPDATE medifix_ss_diagnosticreport_radiologi mdr
        JOIN satu_sehat_diagnosticreport_radiologi sdr ON mdr.noorder = sdr.noorder
        SET
            mdr.id_diagnosticreport = sdr.id_diagnosticreport,
            mdr.status_kirim        = 'terkirim',
            mdr.updated_at          = NOW()
        WHERE sdr.id_diagnosticreport IS NOT NULL
          AND sdr.id_diagnosticreport != ''
          AND (mdr.id_diagnosticreport IS NULL OR mdr.id_diagnosticreport = '')
    ");

} catch (Exception $e) {
    error_log('Auto-sync medifix_ss_diagnosticreport: ' . $e->getMessage());
}

// ── Ambil Data ───────────────────────────────────────────────────
try {
    $wheres = ["hr.tgl_periksa BETWEEN ? AND ?"];
    $params = [$tgl_dari, $tgl_sampai];

    if (!empty($cari)) {
        $wheres[] = "(sdr.noorder LIKE ? OR p.nm_pasien LIKE ?
                      OR d.nm_dokter LIKE ? OR hr.hasil LIKE ?
                      OR COALESCE(mdr.id_diagnosticreport, sdr.id_diagnosticreport) LIKE ?)";
        $params = array_merge($params, array_fill(0, 5, "%$cari%"));
    }

    if ($filter_status === 'terkirim') {
        $wheres[] = "(mdr.status_kirim = 'terkirim'
                      OR (mdr.status_kirim IS NULL AND sdr.id_diagnosticreport IS NOT NULL AND sdr.id_diagnosticreport != ''))";
    } elseif ($filter_status === 'pending') {
        $wheres[] = "(mdr.status_kirim = 'pending'
                      OR (mdr.status_kirim IS NULL AND (sdr.id_diagnosticreport IS NULL OR sdr.id_diagnosticreport = '')))";
    } elseif ($filter_status === 'error') {
        $wheres[] = "mdr.status_kirim = 'error'";
    } elseif ($filter_status === 'no_hasil') {
        // Filter: sudah ada di satu_sehat_dr tapi belum ada hasil bacaan
        $wheres[] = "hr.hasil IS NULL";
    }

    $where_sql = "WHERE " . implode(" AND ", $wheres);

    $base_join = "
        FROM satu_sehat_diagnosticreport_radiologi sdr
        LEFT JOIN medifix_ss_diagnosticreport_radiologi mdr ON sdr.noorder = mdr.noorder
        JOIN permintaan_radiologi pr          ON sdr.noorder  = pr.noorder
        JOIN hasil_radiologi hr               ON pr.no_rawat  = hr.no_rawat
        JOIN reg_periksa r                    ON pr.no_rawat  = r.no_rawat
        JOIN pasien p                         ON r.no_rkm_medis = p.no_rkm_medis
        LEFT JOIN dokter d                    ON pr.dokter_perujuk = d.kd_dokter
        LEFT JOIN poliklinik pl               ON r.kd_poli    = pl.kd_poli
        LEFT JOIN medifix_ss_radiologi msr    ON sdr.noorder  = msr.noorder
    ";

    $stmtCount = $pdo_simrs->prepare("SELECT COUNT(*) $base_join $where_sql");
    $stmtCount->execute($params);
    $total       = (int)$stmtCount->fetchColumn();
    $total_pages = max(1, ceil($total / $limit));

    $stmtData = $pdo_simrs->prepare(
        "SELECT
            sdr.noorder,
            sdr.kd_jenis_prw,
            COALESCE(mdr.id_diagnosticreport, sdr.id_diagnosticreport) AS id_diagnosticreport,
            COALESCE(
                mdr.status_kirim,
                CASE
                    WHEN sdr.id_diagnosticreport IS NOT NULL AND sdr.id_diagnosticreport != ''
                    THEN 'terkirim'
                    ELSE 'pending'
                END
            ) AS status_kirim,
            mdr.tgl_kirim,
            mdr.error_msg,
            mdr.id_servicerequest,
            mdr.id_imagingstudy,
            msr.status_kirim_sr,
            msr.status_kirim_is,
            pr.no_rawat, pr.tgl_permintaan, pr.jam_permintaan,
            pr.diagnosa_klinis,
            hr.tgl_periksa, hr.jam  AS jam_periksa,
            hr.hasil,
            p.no_rkm_medis, p.nm_pasien, p.jk, p.tgl_lahir,
            d.nm_dokter,
            pl.nm_poli,
            r.no_rawat AS no_rawat_reg
         $base_join $where_sql
         ORDER BY hr.tgl_periksa DESC, hr.jam DESC
         LIMIT " . intval($limit) . " OFFSET " . intval($offset)
    );
    $stmtData->execute($params);
    $data = $stmtData->fetchAll(PDO::FETCH_ASSOC);

    // Stats
    $stmtStats = $pdo_simrs->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE
                WHEN mdr.status_kirim = 'terkirim'
                  OR (mdr.status_kirim IS NULL AND sdr.id_diagnosticreport IS NOT NULL AND sdr.id_diagnosticreport != '')
                THEN 1 ELSE 0
            END) AS terkirim,
            SUM(CASE WHEN mdr.status_kirim = 'error' THEN 1 ELSE 0 END) AS error,
            SUM(CASE
                WHEN mdr.status_kirim = 'pending'
                  OR (mdr.status_kirim IS NULL AND (sdr.id_diagnosticreport IS NULL OR sdr.id_diagnosticreport = ''))
                THEN 1 ELSE 0
            END) AS pending
        FROM satu_sehat_diagnosticreport_radiologi sdr
        LEFT JOIN medifix_ss_diagnosticreport_radiologi mdr ON sdr.noorder = mdr.noorder
        JOIN permintaan_radiologi pr   ON sdr.noorder = pr.noorder
        JOIN hasil_radiologi hr        ON pr.no_rawat = hr.no_rawat
        WHERE hr.tgl_periksa BETWEEN ? AND ?
    ");
    $stmtStats->execute([$tgl_dari, $tgl_sampai]);
    $stats       = $stmtStats->fetch(PDO::FETCH_ASSOC);
    $st_total    = (int)($stats['total']    ?? 0);
    $st_terkirim = (int)($stats['terkirim'] ?? 0);
    $st_error    = (int)($stats['error']    ?? 0);
    $st_pending  = (int)($stats['pending']  ?? 0);
    $dbError     = null;

} catch (Exception $e) {
    $data = []; $total = 0; $total_pages = 1;
    $st_total = $st_terkirim = $st_error = $st_pending = 0;
    $dbError = $e->getMessage();
}

$pct = $st_total > 0 ? round(($st_terkirim / $st_total) * 100) : 0;

$tgl_dari_fmt   = date('d F Y', strtotime($tgl_dari));
$tgl_sampai_fmt = date('d F Y', strtotime($tgl_sampai));
$periode_label  = $tgl_dari === $tgl_sampai ? $tgl_dari_fmt : "$tgl_dari_fmt s/d $tgl_sampai_fmt";

$page_title = 'Diagnostic Report Radiologi — Satu Sehat';

$extra_css = '
.btn-send{width:32px;height:32px;border-radius:6px;padding:0;display:inline-flex;align-items:center;justify-content:center;border:1px solid transparent;cursor:pointer;transition:all .25s;font-size:12px;background:#00c0ef;border-color:#00a9d4;color:#fff}
.btn-send:hover{background:#00a9d4;transform:scale(1.12);box-shadow:0 3px 10px rgba(0,192,239,.4);color:#fff}
.btn-send:disabled{opacity:.45;cursor:not-allowed;transform:none}
.btn-send.sent{background:#00a65a;border-color:#008d4c}
.btn-send.sent:hover{background:#008d4c}
.btn-send.error-st{background:#dd4b39;border-color:#c23321}
.btn-send.spin i{animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

.badge-ok{background:#dff0d8;color:#3c763d;border:1px solid #c3e6cb;padding:3px 9px;border-radius:10px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px}
.badge-err{background:#f2dede;color:#a94442;border:1px solid #f5c6cb;padding:3px 9px;border-radius:10px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px}
.badge-pend{background:#fcf8e3;color:#8a6d3b;border:1px solid #faebcc;padding:3px 9px;border-radius:10px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px}
.badge-sr-ref{background:#f0f0ff;color:#605ca8;border:1px solid #d6d0f8;padding:2px 7px;border-radius:6px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px;margin-top:3px}
.badge-ralan{background:#d9edf7;color:#31708f;border:1px solid #bce8f1;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700}
.badge-ranap{background:#fcf8e3;color:#8a6d3b;border:1px solid #faebcc;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700}

.row-sent{background:#f0fff4!important}
.row-error{background:#fff5f5!important}
.row-no-sr{background:#fafafa!important}

.btn-copy{width:22px;height:22px;border-radius:4px;padding:0;display:inline-flex;align-items:center;justify-content:center;background:#ecf0f1;border:1px solid #ddd;color:#777;cursor:pointer;transition:all .2s;font-size:10px;vertical-align:middle;margin-left:3px}
.btn-copy:hover{background:#d5d8dc;color:#333}

.id-cell{font-family:"Courier New",monospace;font-size:10.5px;color:#555;word-break:break-all}
.no-id{font-style:italic;color:#ccc;font-size:11px}
.noorder-lbl{font-weight:700;color:#00a9d4;font-size:13px;font-family:"Courier New",monospace}
.rm-lbl{font-size:10.5px;color:#aaa;font-family:"Courier New",monospace}
.kd-lbl{display:inline-block;background:#e8f8fd;border:1px solid #b8e8f8;color:#0073b7;padding:2px 7px;border-radius:4px;font-size:11px;font-weight:700;font-family:"Courier New",monospace}
.nm-pasien{font-weight:700;color:#333;font-size:13px}
.hasil-text{font-size:12px;color:#444;line-height:1.5;max-height:70px;overflow:hidden;position:relative}
.hasil-text::after{content:"";position:absolute;bottom:0;left:0;right:0;height:20px;background:linear-gradient(transparent,#fff)}
.waktu-lbl{font-size:13px;font-weight:600;color:#333}
.waktu-sub{font-size:10.5px;color:#aaa;margin-top:2px}
.err-tooltip{cursor:help;border-bottom:1px dashed #dd4b39;font-size:10.5px;color:#dd4b39}

.action-bar{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.btn-kirim-semua{background:linear-gradient(135deg,#00c0ef,#0073b7);border:none;color:#fff;padding:6px 14px;border-radius:5px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .25s}
.btn-kirim-semua:hover{transform:translateY(-2px);box-shadow:0 4px 14px rgba(0,115,183,.35);color:#fff}
.btn-kirim-semua:disabled{opacity:.6;cursor:not-allowed;transform:none}

.info-bar-stats{display:flex;gap:16px;padding:8px 15px;background:#e8f8fd;border-bottom:1px solid #b8e8f8;font-size:12px;flex-wrap:wrap;align-items:center}
.ibs-item{display:flex;align-items:center;gap:5px;color:#555}
.ibs-val{font-weight:700;font-size:14px}
.ibs-sep{color:#ddd}

.prog-row{display:flex;align-items:center;gap:10px;font-size:12px;padding:10px 15px 6px;background:#f9f9f9;border-bottom:1px solid #e5e5e5}
.prog-label{width:140px;color:#555;flex-shrink:0}
.prog-bar{flex:1;height:7px;border-radius:4px;background:#e5e5e5;overflow:hidden}
.prog-fill{height:100%;border-radius:4px;background:linear-gradient(90deg,#00c0ef,#00a65a);transition:width .6s}
.prog-pct{width:38px;text-align:right;font-weight:700;color:#555;flex-shrink:0}

.tbl-dr thead tr th{background:#0073b7;color:#fff!important;white-space:nowrap;font-size:12px}
.tbl-dr tbody td{vertical-align:middle}

.date-range-wrap{display:flex;align-items:center;gap:6px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;padding:5px 10px}
.date-range-wrap label{font-size:12px;color:#666;margin:0;white-space:nowrap}
.date-range-wrap input{border:none;background:transparent;font-size:13px;color:#333;padding:0;outline:none;width:130px}
.date-range-sep{color:#aaa;font-size:12px}
.btn-period{padding:3px 10px;border-radius:4px;font-size:11px;border:1px solid #dee2e6;background:#fff;color:#555;cursor:pointer;transition:all .2s}
.btn-period:hover{background:#0073b7;color:#fff;border-color:#0073b7}

.sr-link{font-size:11px;color:#605ca8;text-decoration:none;display:inline-flex;align-items:center;gap:3px}
.sr-link:hover{text-decoration:underline}

#toast-container{position:fixed;bottom:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.toast-msg{padding:10px 18px;border-radius:6px;font-size:13px;font-weight:600;color:#fff;display:flex;align-items:center;gap:8px;box-shadow:0 4px 12px rgba(0,0,0,.2);pointer-events:auto;animation:toastIn .3s ease}
.toast-success{background:#00a65a}.toast-error{background:#dd4b39}.toast-info{background:#00c0ef}.toast-warn{background:#f39c12}
@keyframes toastIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
';

$extra_js = <<<'ENDJS'
function setPeriode(jenis) {
    const now = new Date();
    let dari, sampai;
    sampai = now.toISOString().split("T")[0];
    if (jenis === "hari")        { dari = sampai; }
    else if (jenis === "minggu") { const d=new Date(now);d.setDate(d.getDate()-6);dari=d.toISOString().split("T")[0]; }
    else if (jenis === "bulan")  { dari=new Date(now.getFullYear(),now.getMonth(),1).toISOString().split("T")[0]; }
    else if (jenis === "bulan_lalu") {
        dari=new Date(now.getFullYear(),now.getMonth()-1,1).toISOString().split("T")[0];
        sampai=new Date(now.getFullYear(),now.getMonth(),0).toISOString().split("T")[0];
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
    setTimeout(() => d.remove(), 4500);
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

function kirimSatu(noorder, kdJenisPrw, noRawat, btnEl) {
    // Cek apakah SR sudah terkirim (dari data attribute)
    const row = btnEl.closest("tr");
    const srStatus = row?.dataset?.srStatus || '';
    if (srStatus !== 'terkirim') {
        if (!confirm("ServiceRequest untuk order ini belum terkirim.\nTetap kirim DiagnosticReport sekarang?")) return;
    }

    btnEl.disabled = true;
    const origHTML = btnEl.innerHTML;
    btnEl.classList.add("spin");
    btnEl.innerHTML = `<i class="fa fa-spinner"></i>`;

    fetch(window.location.href, {
        method: "POST",
        headers: {"Content-Type":"application/x-www-form-urlencoded"},
        body: new URLSearchParams({
            action: "kirim",
            noorder,
            kd_jenis_prw: kdJenisPrw,
            no_rawat: noRawat
        })
    })
    .then(r => r.json())
    .then(resp => {
        btnEl.classList.remove("spin");
        if (resp.status === "ok") {
            btnEl.classList.add("sent");
            btnEl.innerHTML = `<i class="fa fa-check"></i>`;
            btnEl.title = "Terkirim — " + (resp.id_dr || "");
            showToast("✓ DiagnosticReport " + noorder + " berhasil dikirim!", "success");

            if (row) {
                const badge = row.querySelector(".badge-status");
                if (badge) {
                    badge.className = "badge-status badge-ok";
                    badge.innerHTML = `<i class="fa fa-check-circle"></i> Terkirim`;
                }
                row.classList.remove("row-error");
                row.classList.add("row-sent");

                const idCell = row.querySelector(".id-dr-cell");
                if (idCell && resp.id_dr) {
                    idCell.innerHTML = `<span class="id-cell" title="${resp.id_dr}">${resp.id_dr.substring(0,36)}</span>
                        <button class="btn-copy" onclick="copyText('${resp.id_dr}')" title="Salin"><i class="fa fa-copy"></i></button>`;
                }
            }

            const ep = document.getElementById("ibsPending");
            const et = document.getElementById("ibsTerkirim");
            if (ep) ep.textContent = Math.max(0, parseInt(ep.textContent) - 1);
            if (et) et.textContent = parseInt(et.textContent || 0) + 1;
        } else {
            btnEl.disabled = false;
            btnEl.classList.add("error-st");
            btnEl.innerHTML = `<i class="fa fa-exclamation-triangle"></i>`;
            btnEl.title = "Gagal: " + (resp.message || "");
            showToast("Gagal: " + (resp.message || ""), "error");
            if (row) row.classList.add("row-error");
        }
    })
    .catch(() => {
        btnEl.disabled = false;
        btnEl.innerHTML = origHTML;
        btnEl.classList.remove("spin");
        showToast("Koneksi ke server gagal", "error");
    });
}

function kirimSemua() {
    const dari    = document.getElementById("tgl_dari")?.value || "";
    const sampai  = document.getElementById("tgl_sampai")?.value || "";
    const tanggal = dari === sampai ? dari : dari + " s/d " + sampai;

    if (!confirm("Kirim semua DiagnosticReport pending ke Satu Sehat?\nPeriode: " + tanggal)) return;

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
            showToast(
                `Selesai: ${resp.berhasil} berhasil, ${resp.gagal} gagal dari ${resp.jumlah} data.`,
                resp.gagal > 0 ? "warn" : "success"
            );
            setTimeout(() => location.reload(), 2200);
        } else {
            showToast("Gagal: " + (resp.message || ""), "error");
        }
    })
    .catch(() => {
        if (btn) { btn.disabled = false; btn.innerHTML = `<i class="fa fa-send"></i> Kirim Semua Pending`; }
        showToast("Koneksi gagal", "error");
    });
}
ENDJS;

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div id="toast-container"></div>

<div class="content-wrapper">
  <section class="content-header">
    <h1>
      <i class="fa fa-file-medical-alt" style="color:#0073b7;"></i>
      Diagnostic Report Radiologi
      <small>Satu Sehat &mdash; <?= $periode_label ?></small>
    </h1>
    <ol class="breadcrumb">
      <li><a href="dashboard.php"><i class="fa fa-dashboard"></i> Home</a></li>
      <li>Satu Sehat</li>
      <li class="active">Diagnostic Report Radiologi</li>
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

        <!-- Info link ke SR -->
        <div class="callout callout-info" style="padding:8px 15px;margin-bottom:12px;">
          <p style="margin:0;font-size:13px;">
            <i class="fa fa-info-circle"></i>
            DiagnosticReport dikirim setelah dokter radiolog mengisi hasil bacaan.
            Pastikan <strong>ServiceRequest</strong> sudah terkirim terlebih dahulu.
            <a href="data_service_request.php" class="sr-link" style="margin-left:8px;">
              <i class="fa fa-external-link"></i> Lihat Service Request
            </a>
          </p>
        </div>

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
                <label style="display:block;margin-bottom:4px;font-size:12px;"><i class="fa fa-calendar"></i> Tanggal Hasil:</label>
                <div class="date-range-wrap">
                  <label>Dari</label>
                  <input type="date" name="tgl_dari" id="tgl_dari" value="<?= htmlspecialchars($tgl_dari) ?>">
                  <span class="date-range-sep">—</span>
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
                <input type="text" name="cari" class="form-control" placeholder="Pasien / No.Order / Hasil…"
                       value="<?= htmlspecialchars($cari) ?>" style="width:220px;">
              </div>
              <div class="form-group">
                <label style="margin-right:5px;">Status:</label>
                <select name="status" class="form-control">
                  <option value=""         <?= $filter_status===''         ?'selected':'' ?>>Semua</option>
                  <option value="terkirim" <?= $filter_status==='terkirim' ?'selected':'' ?>>Terkirim</option>
                  <option value="pending"  <?= $filter_status==='pending'  ?'selected':'' ?>>Pending</option>
                  <option value="error"    <?= $filter_status==='error'    ?'selected':'' ?>>Error</option>
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
              <a href="data_diagnosticreport.php" class="btn btn-default"><i class="fa fa-refresh"></i> Reset</a>
            </form>
          </div>
        </div>

        <!-- Tabel -->
        <div class="box box-info">
          <div class="box-header with-border">
            <h3 class="box-title">
              <i class="fa fa-table"></i> Data Diagnostic Report
              <span class="badge" style="background:#0073b7;"><?= number_format($total) ?></span>
            </h3>
            <div class="box-tools pull-right">
              <div class="action-bar">
                <button id="btnKirimSemua" onclick="kirimSemua()" class="btn-kirim-semua"
                        <?= $st_pending === 0 ? 'disabled' : '' ?>>
                  <i class="fa fa-send"></i> Kirim Semua Pending
                  <?php if ($st_pending > 0): ?>
                  <span class="badge" style="background:#dd4b39;"><?= number_format($st_pending) ?></span>
                  <?php endif; ?>
                </button>
                <a href="?tgl_dari=<?= urlencode($tgl_dari) ?>&tgl_sampai=<?= urlencode($tgl_sampai) ?>&cari=<?= urlencode($cari) ?>&status=<?= urlencode($filter_status) ?>&limit=<?= $limit ?>"
                   class="btn btn-default btn-sm" title="Refresh"><i class="fa fa-refresh"></i></a>
              </div>
            </div>
          </div>

          <!-- Stats -->
          <div class="info-bar-stats">
            <div class="ibs-item">
              <i class="fa fa-database" style="color:#0073b7;"></i>
              Total: <span class="ibs-val" style="color:#0073b7;"><?= number_format($st_total) ?></span>
            </div>
            <span class="ibs-sep">|</span>
            <div class="ibs-item">
              <i class="fa fa-check-circle" style="color:#00a65a;"></i>
              Terkirim: <span class="ibs-val" style="color:#00a65a;" id="ibsTerkirim"><?= number_format($st_terkirim) ?></span>
            </div>
            <div class="ibs-item">
              <i class="fa fa-clock-o" style="color:#f39c12;"></i>
              Pending: <span class="ibs-val" style="color:#f39c12;" id="ibsPending"><?= number_format($st_pending) ?></span>
            </div>
            <?php if ($st_error > 0): ?>
            <div class="ibs-item">
              <i class="fa fa-times-circle" style="color:#dd4b39;"></i>
              Error: <span class="ibs-val" style="color:#dd4b39;"><?= number_format($st_error) ?></span>
            </div>
            <?php endif; ?>
            <span class="ibs-sep">|</span>
            <div class="ibs-item">
              <i class="fa fa-percent" style="color:#00a65a;"></i>
              Progress: <span class="ibs-val" style="color:#00a65a;"><?= $pct ?>%</span>
            </div>
          </div>

          <!-- Progress bar -->
          <div class="prog-row">
            <span class="prog-label"><i class="fa fa-file-text-o"></i> Diagnostic Report</span>
            <div class="prog-bar"><div class="prog-fill" style="width:<?= $pct ?>%;"></div></div>
            <span class="prog-pct"><?= $pct ?>%</span>
          </div>

          <div class="box-body" style="padding:0;">
            <?php if (!empty($data)): ?>
            <div class="table-responsive">
              <table class="table table-bordered table-striped table-hover tbl-dr"
                     style="margin-bottom:0;font-size:12.5px;">
                <thead>
                  <tr>
                    <th width="36"  class="text-center">#</th>
                    <th width="40"  class="text-center">Kirim</th>
                    <th width="140">No. Order</th>
                    <th width="100">Kd. Jenis Prw</th>
                    <th width="170">Pasien</th>
                    <th width="130">Dokter / Poli</th>
                    <th>Hasil Bacaan</th>
                    <th width="105">Tgl Hasil</th>
                    <th width="220">ID DiagnosticReport</th>
                    <th width="130" class="text-center">Status</th>
                    <th width="130" class="text-center">Ref. SR / IS</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $no = $offset + 1;
                  foreach ($data as $r):
                      $ok    = ($r['status_kirim'] ?? '') === 'terkirim';
                      $err   = ($r['status_kirim'] ?? '') === 'error';
                      $srOk  = ($r['status_kirim_sr'] ?? '') === 'terkirim';
                      $isOk  = ($r['status_kirim_is'] ?? '') === 'terkirim';
                      $rowClass = $err ? 'row-error' : ($ok ? 'row-sent' : (!$srOk ? 'row-no-sr' : ''));
                      $umur = '';
                      if (!empty($r['tgl_lahir'])) {
                          try { $umur = (new DateTime())->diff(new DateTime($r['tgl_lahir']))->y . ' th'; }
                          catch(Exception $e){}
                      }
                      $shortDR = !empty($r['id_diagnosticreport'])
                          ? mb_strimwidth($r['id_diagnosticreport'], 0, 36, '…') : '';
                  ?>
                  <tr class="<?= $rowClass ?>" data-sr-status="<?= htmlspecialchars($r['status_kirim_sr'] ?? '') ?>">

                    <td class="text-center" style="color:#aaa;font-size:11px;font-weight:600;"><?= $no++ ?></td>

                    <td class="text-center">
                      <button class="btn-send <?= $ok?'sent':($err?'error-st':'') ?>"
                              onclick="kirimSatu('<?= addslashes($r['noorder']) ?>',
                                                 '<?= addslashes($r['kd_jenis_prw']) ?>',
                                                 '<?= addslashes($r['no_rawat']) ?>',
                                                 this)"
                              title="<?= $ok?'Kirim Ulang DR':($err?'Retry':'Kirim DiagnosticReport') ?>"
                              <?= (!$srOk && !$ok) ? 'title="Kirim SR dulu sebelum DR"' : '' ?>>
                        <i class="fa fa-<?= $ok?'refresh':($err?'exclamation-triangle':'file-text-o') ?>"></i>
                      </button>
                      <?php if (!$srOk && !$ok): ?>
                      <div style="font-size:9px;color:#f39c12;margin-top:2px;">SR belum kirim</div>
                      <?php endif; ?>
                    </td>

                    <td>
                      <div class="noorder-lbl"><?= htmlspecialchars($r['noorder']) ?></div>
                      <div class="rm-lbl"><?= htmlspecialchars($r['no_rawat']) ?></div>
                    </td>

                    <td><span class="kd-lbl"><?= htmlspecialchars($r['kd_jenis_prw']) ?></span></td>

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
                      <?php if (!empty($r['hasil'])): ?>
                        <div class="hasil-text"><?= nl2br(htmlspecialchars($r['hasil'])) ?></div>
                      <?php else: ?>
                        <span class="no-id"><i class="fa fa-minus-circle"></i> Belum ada hasil</span>
                      <?php endif; ?>
                    </td>

                    <td>
                      <?php if (!empty($r['tgl_periksa'])): ?>
                        <div class="waktu-lbl"><?= date('H:i', strtotime($r['jam_periksa'])) ?> WIB</div>
                        <div class="waktu-sub"><?= date('d/m/Y', strtotime($r['tgl_periksa'])) ?></div>
                      <?php else: ?>
                        <span class="no-id">-</span>
                      <?php endif; ?>
                    </td>

                    <td class="id-dr-cell">
                      <?php if (!empty($r['id_diagnosticreport'])): ?>
                        <span class="id-cell" title="<?= htmlspecialchars($r['id_diagnosticreport']) ?>"><?= htmlspecialchars($shortDR) ?></span>
                        <button class="btn-copy" onclick="copyText('<?= addslashes($r['id_diagnosticreport']) ?>')" title="Salin"><i class="fa fa-copy"></i></button>
                        <?php if (!empty($r['tgl_kirim'])): ?>
                        <div style="font-size:10px;color:#aaa;margin-top:2px;"><?= date('d/m H:i', strtotime($r['tgl_kirim'])) ?></div>
                        <?php endif; ?>
                      <?php elseif ($err): ?>
                        <span class="err-tooltip" title="<?= htmlspecialchars($r['error_msg']??'') ?>">
                          <i class="fa fa-times-circle text-red"></i> Error
                        </span>
                      <?php else: ?>
                        <span class="no-id"><i class="fa fa-minus-circle"></i> Belum dikirim</span>
                      <?php endif; ?>
                    </td>

                    <td class="text-center">
                      <span class="badge-status <?= $ok?'badge-ok':($err?'badge-err':'badge-pend') ?>">
                        <i class="fa fa-<?= $ok?'check-circle':($err?'times-circle':'clock-o') ?>"></i>
                        <?= $ok?'Terkirim':($err?'Error':'Pending') ?>
                      </span>
                    </td>

                    <td class="text-center">
                      <!-- Referensi SR -->
                      <?php if ($srOk): ?>
                        <span class="badge-sr-ref" title="SR sudah terkirim">
                          <i class="fa fa-check-circle" style="color:#00a65a;"></i> SR OK
                        </span>
                      <?php else: ?>
                        <span class="badge-sr-ref" style="background:#fff5f5;color:#dd4b39;border-color:#f5c6cb;"
                              title="SR belum terkirim">
                          <i class="fa fa-times-circle"></i> SR Pending
                        </span>
                      <?php endif; ?>
                      <!-- Referensi IS -->
                      <?php if ($isOk): ?>
                        <span class="badge-sr-ref" style="background:#d9edf7;color:#31708f;border-color:#bce8f1;margin-top:3px;display:inline-flex;"
                              title="IS sudah terkirim">
                          <i class="fa fa-image"></i> IS OK
                        </span>
                      <?php else: ?>
                        <span class="badge-sr-ref" style="background:#f5f5f5;color:#aaa;border-color:#e5e5e5;margin-top:3px;display:inline-flex;">
                          <i class="fa fa-image"></i> IS -
                        </span>
                      <?php endif; ?>
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
                $qBase = "tgl_dari=".urlencode($tgl_dari)."&tgl_sampai=".urlencode($tgl_sampai)
                        ."&cari=".urlencode($cari)."&status=".urlencode($filter_status)."&limit=$limit";
                if ($page > 1): ?><li><a href="?page=<?=$page-1?>&<?=$qBase?>">«</a></li><?php endif;
                for ($i = max(1,$page-3); $i <= min($total_pages,$page+3); $i++): ?>
                <li <?=$i==$page?'class="active"':''?>><a href="?page=<?=$i?>&<?=$qBase?>"><?=$i?></a></li>
                <?php endfor;
                if ($page < $total_pages): ?><li><a href="?page=<?=$page+1?>&<?=$qBase?>">»</a></li><?php endif; ?>
              </ul>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <div style="padding:50px;text-align:center;">
              <i class="fa fa-inbox" style="font-size:52px;color:#ddd;display:block;margin-bottom:14px;"></i>
              <h4 style="color:#aaa;font-weight:400;">
                <?= ($cari || $filter_status)
                    ? "Tidak ada data untuk filter yang dipilih"
                    : "Tidak ada hasil bacaan radiologi pada periode <strong>$periode_label</strong>" ?>
              </h4>
              <?php if ($cari || $filter_status): ?>
              <a href="?tgl_dari=<?= urlencode($tgl_dari) ?>&tgl_sampai=<?= urlencode($tgl_sampai) ?>"
                 class="btn btn-default btn-sm"><i class="fa fa-refresh"></i> Reset Filter</a>
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