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
if (isset($_GET['_log']) && $_GET['_log'] === 'vaksin') {
    header('Content-Type: text/plain; charset=utf-8');
    $logFile = __DIR__ . '/logs/vaksin_' . date('Y-m') . '.log';
    echo file_exists($logFile)
        ? implode("\n", array_slice(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -500))
        : '(Log belum ada)';
    exit;
}

// ── AJAX POST ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['kirim_vaksin', 'kirim_semua_vaksin'])) {
        include __DIR__ . '/api/kirim_vaksin.php';
        exit;
    }
    if ($action === 'sync_ihs') {
        $_POST['action'] = 'sync_satu';
        include __DIR__ . '/api/sync_ihs_pasien.php';
        exit;
    }
}

// ── Filter ────────────────────────────────────────────────────────
$tgl_dari      = $_GET['tgl_dari']     ?? date('Y-m-d');
$tgl_sampai    = $_GET['tgl_sampai']   ?? date('Y-m-d');
$cari          = $_GET['cari']         ?? '';
$filter_status = $_GET['status_kirim'] ?? '';
$filter_lanjut = $_GET['lanjut']       ?? '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_dari))   $tgl_dari   = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_sampai)) $tgl_sampai = date('Y-m-d');
if ($tgl_sampai < $tgl_dari) $tgl_sampai = $tgl_dari;
$limit  = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 20;
$page   = isset($_GET['page'])  ? max(1, intval($_GET['page']))  : 1;
$offset = ($page - 1) * $limit;

// ── Query utama: dari detail_pemberian_obat + satu_sehat_mapping_vaksin ──
try {
    $extraWhere  = '';
    $extraParams = [];
    if (!empty($cari)) {
        $extraWhere  = " AND (rp.no_rawat LIKE ? OR rp.no_rkm_medis LIKE ?
                          OR p.nm_pasien LIKE ? OR p.no_ktp LIKE ?
                          OR mv.vaksin_display LIKE ? OR br.nama_brng LIKE ?)";
        $extraParams = array_fill(0, 6, "%$cari%");
    }

    $statusWhere = '';
    if ($filter_status === 'terkirim') {
        $statusWhere = " AND ssv.id_imunisasi IS NOT NULL AND ssv.id_imunisasi != ''";
    } elseif ($filter_status === 'pending') {
        $statusWhere = " AND (ssv.id_imunisasi IS NULL OR ssv.id_imunisasi = '')";
    }

    $lanjutWhere = '';
    if ($filter_lanjut === 'Ralan') {
        $lanjutWhere = " AND dpo.status = 'Ralan'";
    } elseif ($filter_lanjut === 'Ranap') {
        $lanjutWhere = " AND dpo.status = 'Ranap'";
    }

    $baseSQL = "
        FROM detail_pemberian_obat dpo
        JOIN reg_periksa rp                 ON rp.no_rawat      = dpo.no_rawat
        JOIN pasien p                       ON p.no_rkm_medis   = rp.no_rkm_medis
        JOIN pegawai pg                     ON pg.nik            = rp.kd_dokter
        JOIN poliklinik pl                  ON pl.kd_poli        = rp.kd_poli
        JOIN satu_sehat_mapping_vaksin mv   ON mv.kode_brng      = dpo.kode_brng
        JOIN satu_sehat_encounter se        ON se.no_rawat       = dpo.no_rawat
        LEFT JOIN databarang br             ON br.kode_brng      = dpo.kode_brng
        LEFT JOIN penjab pj                 ON pj.kd_pj          = rp.kd_pj
        LEFT JOIN medifix_ss_pasien msp     ON msp.no_rkm_medis  = p.no_rkm_medis
        LEFT JOIN detailbeli db2            ON db2.no_faktur      = dpo.no_faktur
                                           AND db2.kode_brng      = dpo.kode_brng
                                           AND db2.no_batch       = dpo.no_batch
        LEFT JOIN satu_sehat_vaksin ssv     ON ssv.no_rawat       = dpo.no_rawat
                                           AND ssv.kode_brng      = dpo.kode_brng
                                           AND ssv.tgl_pemberian  = dpo.tgl_perawatan
                                           AND ssv.jam            = dpo.jam
        WHERE dpo.tgl_perawatan BETWEEN ? AND ?
        $statusWhere
        $lanjutWhere
        $extraWhere
    ";

    $baseParams = array_merge([$tgl_dari, $tgl_sampai], $extraParams);

    // Count
    $stmtCount = $pdo_simrs->prepare("SELECT COUNT(*) FROM (SELECT 1 $baseSQL) AS u");
    $stmtCount->execute($baseParams);
    $total       = (int)$stmtCount->fetchColumn();
    $total_pages = max(1, ceil($total / $limit));

    // Data
    $stmtData = $pdo_simrs->prepare("
        SELECT
            rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis,
            p.nm_pasien, p.no_ktp, p.tgl_lahir, p.jk,
            rp.kd_dokter, pg.nama AS nm_dokter,
            rp.kd_poli, pl.nm_poli,
            rp.stts, rp.status_lanjut, rp.stts_daftar, rp.status_bayar,
            rp.kd_pj, pj.png_jawab,
            rp.umurdaftar, rp.sttsumur,
            -- Data vaksin
            dpo.kode_brng, dpo.no_batch, dpo.no_faktur,
            dpo.tgl_perawatan, dpo.jam, dpo.jml, dpo.status AS jenis_rawat,
            -- Mapping kode KFA
            mv.vaksin_code, mv.vaksin_display,
            mv.route_code, mv.route_display,
            mv.dose_quantity_unit,
            IFNULL(br.nama_brng, mv.vaksin_display) AS nama_brng,
            -- Expired date
            db2.kadaluarsa,
            -- IHS & encounter
            IFNULL(msp.ihs_number,'') AS ihs_number,
            se.id_encounter,
            -- Status kirim
            IFNULL(ssv.id_imunisasi,'') AS id_imunisasi
        $baseSQL
        ORDER BY dpo.tgl_perawatan DESC, dpo.jam DESC
        LIMIT " . intval($limit) . " OFFSET " . intval($offset)
    );
    $stmtData->execute($baseParams);
    $data = $stmtData->fetchAll(PDO::FETCH_ASSOC);

    // Stats
    $stmtStats = $pdo_simrs->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN ssv.id_imunisasi IS NOT NULL AND ssv.id_imunisasi != '' THEN 1 ELSE 0 END) AS terkirim,
            SUM(CASE WHEN ssv.id_imunisasi IS NULL OR ssv.id_imunisasi = '' THEN 1 ELSE 0 END) AS pending
        FROM detail_pemberian_obat dpo
        JOIN satu_sehat_mapping_vaksin mv ON mv.kode_brng = dpo.kode_brng
        JOIN satu_sehat_encounter se       ON se.no_rawat = dpo.no_rawat
        LEFT JOIN satu_sehat_vaksin ssv    ON ssv.no_rawat      = dpo.no_rawat
                                          AND ssv.kode_brng     = dpo.kode_brng
                                          AND ssv.tgl_pemberian = dpo.tgl_perawatan
                                          AND ssv.jam           = dpo.jam
        WHERE dpo.tgl_perawatan BETWEEN ? AND ?
    ");
    $stmtStats->execute([$tgl_dari, $tgl_sampai]);
    $stats       = $stmtStats->fetch(PDO::FETCH_ASSOC);
    $st_total    = (int)($stats['total']    ?? 0);
    $st_terkirim = (int)($stats['terkirim'] ?? 0);
    $st_pending  = (int)($stats['pending']  ?? 0);
    $dbError     = null;

} catch (Exception $e) {
    $data = []; $total = 0; $total_pages = 1;
    $st_total = $st_terkirim = $st_pending = 0;
    $dbError = $e->getMessage();
}

$pct = $st_total > 0 ? round(($st_terkirim / $st_total) * 100) : 0;

$tgl_dari_fmt   = date('d F Y', strtotime($tgl_dari));
$tgl_sampai_fmt = date('d F Y', strtotime($tgl_sampai));
$periode_label  = $tgl_dari === $tgl_sampai ? $tgl_dari_fmt : "$tgl_dari_fmt s/d $tgl_sampai_fmt";
$page_title     = 'Vaksin/Imunisasi — Satu Sehat';

$extra_css = '
.btn-send{width:32px;height:32px;border-radius:6px;padding:0;display:inline-flex;align-items:center;justify-content:center;border:1px solid transparent;cursor:pointer;transition:all .25s;font-size:12px;background:#7b1fa2;border-color:#6a1b9a;color:#fff}
.btn-send:hover{background:#6a1b9a;transform:scale(1.12);box-shadow:0 3px 10px rgba(123,31,162,.45);color:#fff}
.btn-send:disabled{opacity:.5;cursor:not-allowed;transform:none}
.btn-send.sent{background:#00a65a;border-color:#008d4c}
.btn-send.sent:hover{background:#008d4c}
.btn-send.error-st{background:#dd4b39;border-color:#c23321}
.btn-send.spin i{animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.btn-copy{width:22px;height:22px;border-radius:4px;padding:0;display:inline-flex;align-items:center;justify-content:center;background:#ecf0f1;border:1px solid #ddd;color:#777;cursor:pointer;transition:all .2s;font-size:10px;vertical-align:middle;margin-left:3px}
.btn-copy:hover{background:#d5d8dc;color:#333}
.id-cell{font-family:"Courier New",monospace;font-size:10.5px;color:#555;word-break:break-all}
.no-id{font-style:italic;color:#ccc;font-size:11px}
.norawat-lbl{font-weight:700;color:#7b1fa2;font-size:13px;font-family:"Courier New",monospace}
.rm-lbl{font-size:10.5px;color:#aaa;font-family:"Courier New",monospace}
.nm-pasien{font-weight:700;color:#333;font-size:13px}
.waktu-lbl{font-size:13px;font-weight:600;color:#333}
.waktu-sub{font-size:10.5px;color:#aaa;margin-top:2px}
.badge-sent{background:#dff0d8;color:#3c763d;border:1px solid #c3e6cb;padding:3px 9px;border-radius:10px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px}
.badge-pending{background:#fcf8e3;color:#8a6d3b;border:1px solid #faebcc;padding:3px 9px;border-radius:10px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px}
.badge-ralan{background:#d9edf7;color:#31708f;border:1px solid #bce8f1;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700}
.badge-ranap{background:#fcf8e3;color:#8a6d3b;border:1px solid #faebcc;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700}
.badge-bayar{background:#dff0d8;color:#3c763d;border:1px solid #c3e6cb;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700}
.badge-blm-bayar{background:#f2dede;color:#a94442;border:1px solid #f5c6cb;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700}
.ihs-ok{color:#00a65a;font-size:10px;font-weight:700}
.ihs-miss{color:#dd4b39;font-size:10px}
.btn-ihs{width:20px;height:20px;border-radius:4px;padding:0;display:inline-flex;align-items:center;justify-content:center;background:#f39c12;border:1px solid #e67e22;color:#fff;cursor:pointer;font-size:9px;vertical-align:middle;margin-left:2px}
.btn-ihs:hover{background:#e67e22}
.row-sent{background:#f0fff4!important}
.row-error{background:#fff5f5!important}
.action-bar{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.btn-kirim-semua{background:linear-gradient(135deg,#7b1fa2,#6a1b9a);border:none;color:#fff;padding:6px 14px;border-radius:5px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .25s}
.btn-kirim-semua:hover{transform:translateY(-2px);box-shadow:0 4px 14px rgba(123,31,162,.4);color:#fff}
.btn-kirim-semua:disabled{opacity:.6;cursor:not-allowed;transform:none}
.info-bar-stats{display:flex;gap:16px;padding:8px 15px;background:#fdf7ff;border-bottom:1px solid #e5e5e5;font-size:12px;flex-wrap:wrap;align-items:center}
.ibs-item{display:flex;align-items:center;gap:5px;color:#555}
.ibs-val{font-weight:700;font-size:14px}
.ibs-sep{color:#ddd}
.prog-dual{padding:10px 15px 6px;background:#f9f9f9;border-bottom:1px solid #e5e5e5}
.prog-row{display:flex;align-items:center;gap:10px;font-size:12px;margin-bottom:4px}
.prog-label{width:120px;color:#555;flex-shrink:0}
.prog-bar{flex:1;height:7px;border-radius:4px;background:#e5e5e5;overflow:hidden}
.prog-fill-vaksin{height:100%;border-radius:4px;background:linear-gradient(90deg,#7b1fa2,#ab47bc);transition:width .6s}
.prog-pct{width:38px;text-align:right;font-weight:700;color:#555;flex-shrink:0}
.tbl-vaksin thead tr th{background:#7b1fa2;color:#fff!important;white-space:nowrap;font-size:12px}
.tbl-vaksin tbody td{vertical-align:middle}
.date-range-wrap{display:flex;align-items:center;gap:6px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;padding:5px 10px}
.date-range-wrap label{font-size:12px;color:#666;margin:0;white-space:nowrap}
.date-range-wrap input{border:none;background:transparent;font-size:13px;color:#333;padding:0;outline:none;width:130px}
.date-range-sep{color:#aaa;font-size:12px}
.btn-period{padding:3px 10px;border-radius:4px;font-size:11px;border:1px solid #dee2e6;background:#fff;color:#555;cursor:pointer;transition:all .2s}
.btn-period:hover{background:#7b1fa2;color:#fff;border-color:#7b1fa2}
.vaksin-name{font-weight:700;color:#6a1b9a;font-size:12px}
.vaksin-sub{font-size:10.5px;color:#aaa;margin-top:2px}
.kfa-badge{background:#f3e5f5;color:#6a1b9a;border:1px solid #e1bee7;padding:2px 6px;border-radius:5px;font-size:10px;font-weight:700;font-family:"Courier New",monospace}
.lot-badge{background:#e3f2fd;color:#1565c0;border:1px solid #bbdefb;padding:2px 6px;border-radius:5px;font-size:10px;font-weight:700;font-family:"Courier New",monospace}
.exp-ok{color:#2e7d32;font-size:10px;font-weight:700}
.exp-warn{color:#e65100;font-size:10px;font-weight:700}
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

function syncIHS(noRkm, btnEl) {
    btnEl.disabled = true;
    const orig = btnEl.innerHTML;
    btnEl.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';
    fetch(window.location.href, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({action:'sync_ihs', no_rkm_medis: noRkm})
    })
    .then(r => r.json())
    .then(resp => {
        if (resp.status === 'ok') {
            btnEl.closest('.ihs-wrap').innerHTML =
                `<span class="ihs-ok" title="${resp.ihs_number}"><i class="fa fa-id-card"></i> IHS OK</span>`;
            showToast('IHS ditemukan: ' + resp.ihs_number, 'success');
        } else {
            btnEl.disabled = false; btnEl.innerHTML = orig;
            showToast('Gagal sync IHS: ' + (resp.message || ''), 'error');
        }
    })
    .catch(() => { btnEl.disabled = false; btnEl.innerHTML = orig; showToast('Koneksi gagal', 'error'); });
}

function kirimSatu(noRawat, kodeBrng, tglPemberian, jam, btnEl) {
    btnEl.disabled = true;
    const origHTML = btnEl.innerHTML;
    btnEl.classList.add("spin");
    btnEl.innerHTML = `<i class="fa fa-spinner"></i>`;

    fetch(window.location.href, {
        method: "POST",
        headers: {"Content-Type":"application/x-www-form-urlencoded"},
        body: new URLSearchParams({
            action: "kirim_vaksin",
            no_rawat: noRawat,
            kode_brng: kodeBrng,
            tgl_pemberian: tglPemberian,
            jam: jam
        })
    })
    .then(r => r.json())
    .then(resp => {
        btnEl.classList.remove("spin");
        if (resp.status === "ok") {
            btnEl.classList.add("sent");
            btnEl.innerHTML = `<i class="fa fa-check"></i>`;
            btnEl.title = "Terkirim — " + (resp.id_imunisasi || "");
            showToast("✓ Vaksin berhasil dikirim!", "success");
            const row = btnEl.closest("tr");
            if (row) {
                row.classList.add("row-sent");
                const badge = row.querySelector(".badge-vaksin-status");
                if (badge) {
                    badge.className = "badge-vaksin-status badge-sent";
                    badge.innerHTML = `<i class="fa fa-check-circle"></i> Terkirim`;
                }
                const idCell = row.querySelector(".id-vaksin-cell");
                if (idCell && resp.id_imunisasi) {
                    const short = resp.id_imunisasi.length > 36 ? resp.id_imunisasi.substring(0,36)+'…' : resp.id_imunisasi;
                    idCell.innerHTML = `<span class="id-cell" title="${resp.id_imunisasi}">${short}</span>
                        <button class="btn-copy" onclick="copyText('${resp.id_imunisasi}')" title="Salin"><i class="fa fa-copy"></i></button>`;
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
            btnEl.closest("tr")?.classList.add("row-error");
        }
    })
    .catch(() => {
        btnEl.disabled = false; btnEl.innerHTML = origHTML; btnEl.classList.remove("spin");
        showToast("Koneksi ke server gagal", "error");
    });
}

function kirimSemua() {
    const dari   = document.getElementById("tgl_dari").value;
    const sampai = document.getElementById("tgl_sampai").value;
    const sisa   = parseInt(document.getElementById("ibsPending")?.textContent?.replace(/\D/g,'') || "0");
    if (sisa === 0) { showToast("Semua sudah terkirim!", "info"); return; }
    if (!confirm(`Kirim ${sisa} Vaksin yang belum terkirim?\nPeriode: ${dari === sampai ? dari : dari + " s/d " + sampai}`)) return;

    const btn = document.getElementById("btnKirimSemua");
    if (btn) { btn.disabled = true; btn.innerHTML = `<i class="fa fa-spinner fa-spin"></i> Mengirim…`; }

    fetch(window.location.href, {
        method: "POST",
        headers: {"Content-Type":"application/x-www-form-urlencoded"},
        body: new URLSearchParams({action:"kirim_semua_vaksin", tgl_dari: dari, tgl_sampai: sampai})
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
ENDJS;

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div id="toast-container"></div>

<div class="content-wrapper">
  <section class="content-header">
    <h1>
      <i class="fa fa-medkit" style="color:#7b1fa2;"></i>
      Vaksin / Imunisasi
      <small>Satu Sehat &mdash; <?= $periode_label ?></small>
    </h1>
    <ol class="breadcrumb">
      <li><a href="dashboard.php"><i class="fa fa-dashboard"></i> Home</a></li>
      <li>Satu Sehat</li>
      <li class="active">Vaksin / Imunisasi</li>
    </ol>
  </section>

  <section class="content">

    <?php if ($dbError): ?>
    <div class="callout callout-danger">
      <h4><i class="fa fa-ban"></i> Error Database</h4>
      <p><?= htmlspecialchars($dbError) ?></p>
    </div>
    <?php endif; ?>

    <div class="row"><div class="col-xs-12">

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
              <label style="display:block;margin-bottom:4px;font-size:12px;"><i class="fa fa-calendar"></i> Periode:</label>
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
              <input type="text" name="cari" class="form-control"
                     placeholder="Pasien / No. Rawat / Nama Vaksin…"
                     value="<?= htmlspecialchars($cari) ?>" style="width:220px;">
            </div>
            <div class="form-group">
              <label style="margin-right:5px;">Status:</label>
              <select name="status_kirim" class="form-control">
                <option value=""         <?= $filter_status===''         ?'selected':'' ?>>Semua</option>
                <option value="terkirim" <?= $filter_status==='terkirim' ?'selected':'' ?>>Terkirim</option>
                <option value="pending"  <?= $filter_status==='pending'  ?'selected':'' ?>>Belum Terkirim</option>
              </select>
            </div>
            <div class="form-group">
              <label style="margin-right:5px;">Jenis:</label>
              <select name="lanjut" class="form-control">
                <option value=""      <?= $filter_lanjut===''      ?'selected':'' ?>>Ralan + Ranap</option>
                <option value="Ralan" <?= $filter_lanjut==='Ralan' ?'selected':'' ?>>Ralan saja</option>
                <option value="Ranap" <?= $filter_lanjut==='Ranap' ?'selected':'' ?>>Ranap saja</option>
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
            <a href="data_vaksin.php" class="btn btn-default"><i class="fa fa-refresh"></i> Reset</a>
          </form>
        </div>
      </div>

      <!-- Tabel -->
      <div class="box box-primary" style="border-top-color:#7b1fa2;">
        <div class="box-header with-border">
          <h3 class="box-title">
            <i class="fa fa-table"></i> Data Vaksin / Imunisasi
            <span class="badge" style="background:#7b1fa2;"><?= number_format($total) ?></span>
          </h3>
          <div class="box-tools pull-right">
            <div class="action-bar">
              <button id="btnKirimSemua" onclick="kirimSemua()" class="btn-kirim-semua" <?= $st_pending===0?'disabled':'' ?>>
                <i class="fa fa-send"></i> Kirim Semua Pending
                <?php if ($st_pending > 0): ?>
                <span class="badge" style="background:#dd4b39;"><?= number_format($st_pending) ?></span>
                <?php endif; ?>
              </button>
              <button onclick="showLog()" class="btn btn-default btn-sm"><i class="fa fa-file-text-o"></i> Log</button>
              <a href="?tgl_dari=<?= urlencode($tgl_dari) ?>&tgl_sampai=<?= urlencode($tgl_sampai) ?>&cari=<?= urlencode($cari) ?>&status_kirim=<?= urlencode($filter_status) ?>&lanjut=<?= urlencode($filter_lanjut) ?>&limit=<?= $limit ?>"
                 class="btn btn-default btn-sm"><i class="fa fa-refresh"></i></a>
            </div>
          </div>
        </div>

        <!-- Stats -->
        <div class="info-bar-stats">
          <div class="ibs-item">
            <i class="fa fa-database" style="color:#7b1fa2;"></i>
            Total: <span class="ibs-val" style="color:#7b1fa2;"><?= number_format($st_total) ?></span>
          </div>
          <span class="ibs-sep">|</span>
          <div class="ibs-item">
            <i class="fa fa-check-circle" style="color:#00a65a;"></i>
            Terkirim: <span class="ibs-val" style="color:#00a65a;" id="ibsTerkirim"><?= number_format($st_terkirim) ?></span>
          </div>
          <div class="ibs-item">
            <i class="fa fa-clock-o" style="color:#f39c12;"></i>
            Belum: <span class="ibs-val" style="color:#f39c12;" id="ibsPending"><?= number_format($st_pending) ?></span>
          </div>
          <span class="ibs-sep">|</span>
          <div class="ibs-item" style="font-size:11px;color:#777;">
            <i class="fa fa-info-circle"></i> Data dari pemberian obat yang sudah dimapping ke kode KFA vaksin
          </div>
        </div>

        <!-- Progress -->
        <div class="prog-dual">
          <div class="prog-row">
            <span class="prog-label"><i class="fa fa-medkit"></i> Imunisasi</span>
            <div class="prog-bar"><div class="prog-fill-vaksin" style="width:<?= $pct ?>%;"></div></div>
            <span class="prog-pct"><?= $pct ?>%</span>
          </div>
        </div>

        <div class="box-body" style="padding:0;">
          <?php if (!empty($data)): ?>
          <div class="table-responsive">
            <table class="table table-bordered table-striped table-hover tbl-vaksin" style="margin-bottom:0;font-size:12px;">
              <thead>
                <tr>
                  <th width="36"  class="text-center">#</th>
                  <th width="40"  class="text-center">Kirim</th>
                  <th width="150">No. Rawat</th>
                  <th width="175">Pasien</th>
                  <th width="175">Vaksin</th>
                  <th width="100">Lot / Batch</th>
                  <th width="90">Expired</th>
                  <th width="60"  class="text-center">Dosis</th>
                  <th width="75"  class="text-center">Jenis</th>
                  <th width="100">Tgl Pemberian</th>
                  <th width="130">Dokter</th>
                  <th>ID Imunisasi</th>
                  <th width="110" class="text-center">Status</th>
                </tr>
              </thead>
              <tbody>
              <?php $no = $offset + 1; foreach ($data as $r):
                  $isSent  = !empty($r['id_imunisasi']);
                  $shortId = $isSent ? mb_strimwidth($r['id_imunisasi'], 0, 36, '…') : '';
                  $umur    = !empty($r['umurdaftar']) ? $r['umurdaftar'].' '.($r['sttsumur']??'Th') : '';
                  // Cek expired
                  $isExpired = !empty($r['kadaluarsa']) && strtotime($r['kadaluarsa']) < time();
                  $tglPemberianEsc = htmlspecialchars($r['tgl_perawatan']);
                  $jamEsc          = htmlspecialchars($r['jam']);
                  $kodeBrngEsc     = addslashes($r['kode_brng']);
              ?>
              <tr class="<?= $isSent ? 'row-sent' : '' ?>">

                <td class="text-center" style="color:#aaa;font-size:11px;font-weight:600;"><?= $no++ ?></td>

                <td class="text-center">
                  <button class="btn-send <?= $isSent?'sent':'' ?>"
                          onclick="kirimSatu('<?= addslashes($r['no_rawat']) ?>','<?= $kodeBrngEsc ?>','<?= $tglPemberianEsc ?>','<?= $jamEsc ?>',this)"
                          title="<?= $isSent?'Kirim Ulang':'Kirim ke Satu Sehat' ?>">
                    <i class="fa fa-<?= $isSent?'refresh':'send' ?>"></i>
                  </button>
                </td>

                <td>
                  <div class="norawat-lbl"><?= htmlspecialchars($r['no_rawat']) ?></div>
                  <div class="rm-lbl"><?= htmlspecialchars($r['no_rkm_medis']) ?></div>
                </td>

                <td>
                  <div class="nm-pasien"><?= htmlspecialchars($r['nm_pasien']) ?></div>
                  <div class="rm-lbl">
                    <?= htmlspecialchars($r['no_ktp'] ?: '-') ?>
                    <?= $umur ? " · $umur" : '' ?>
                  </div>
                  <div class="ihs-wrap" style="margin-top:2px;">
                    <?php if (!empty($r['ihs_number'])): ?>
                      <span class="ihs-ok"><i class="fa fa-id-card"></i> IHS OK</span>
                    <?php else: ?>
                      <button class="btn-ihs" onclick="syncIHS('<?= addslashes($r['no_rkm_medis']) ?>',this)"><i class="fa fa-refresh"></i></button>
                      <span class="ihs-miss"><i class="fa fa-exclamation-triangle"></i> No IHS</span>
                    <?php endif; ?>
                  </div>
                </td>

                <td>
                  <div class="vaksin-name"><?= htmlspecialchars($r['nama_brng']) ?></div>
                  <div class="vaksin-sub">
                    <span class="kfa-badge"><?= htmlspecialchars($r['vaksin_code']) ?></span>
                    <?php if (!empty($r['route_display'])): ?>
                      <span style="color:#aaa;font-size:10px;"> · <?= htmlspecialchars($r['route_display']) ?></span>
                    <?php endif; ?>
                  </div>
                </td>

                <td>
                  <?php if (!empty($r['no_batch'])): ?>
                    <span class="lot-badge"><?= htmlspecialchars($r['no_batch']) ?></span>
                  <?php else: ?>
                    <span style="color:#ccc;font-size:10px;font-style:italic;">-</span>
                  <?php endif; ?>
                </td>

                <td class="text-center">
                  <?php if (!empty($r['kadaluarsa'])): ?>
                    <span class="<?= $isExpired?'exp-warn':'exp-ok' ?>" title="<?= htmlspecialchars($r['kadaluarsa']) ?>">
                      <i class="fa fa-<?= $isExpired?'exclamation-triangle':'check' ?>"></i>
                      <?= date('m/Y', strtotime($r['kadaluarsa'])) ?>
                    </span>
                  <?php else: ?>
                    <span style="color:#ccc;font-size:10px;">-</span>
                  <?php endif; ?>
                </td>

                <td class="text-center">
                  <strong><?= number_format($r['jml'],1) ?></strong>
                  <div style="font-size:10px;color:#aaa;"><?= htmlspecialchars($r['dose_quantity_unit'] ?: '') ?></div>
                </td>

                <td class="text-center">
                  <span class="badge-<?= strtolower($r['jenis_rawat']) === 'ranap' ? 'ranap' : 'ralan' ?>">
                    <?= htmlspecialchars($r['jenis_rawat']) ?>
                  </span>
                </td>

                <td>
                  <div class="waktu-lbl"><?= date('d/m/Y', strtotime($r['tgl_perawatan'])) ?></div>
                  <div class="waktu-sub"><?= substr($r['jam'],0,5) ?> WIB</div>
                </td>

                <td>
                  <div style="font-size:12px;font-weight:600;color:#444;"><?= htmlspecialchars($r['nm_dokter'] ?: '-') ?></div>
                  <div class="rm-lbl"><?= htmlspecialchars($r['nm_poli'] ?: '-') ?></div>
                </td>

                <td class="id-vaksin-cell">
                  <?php if ($isSent): ?>
                    <span class="id-cell" title="<?= htmlspecialchars($r['id_imunisasi']) ?>"><?= htmlspecialchars($shortId) ?></span>
                    <button class="btn-copy" onclick="copyText('<?= addslashes($r['id_imunisasi']) ?>')" title="Salin"><i class="fa fa-copy"></i></button>
                  <?php else: ?>
                    <span class="no-id"><i class="fa fa-minus-circle"></i> Belum dikirim</span>
                  <?php endif; ?>
                </td>

                <td class="text-center">
                  <span class="badge-vaksin-status <?= $isSent?'badge-sent':'badge-pending' ?>">
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
            </div>
            <ul class="pagination pagination-sm no-margin pull-right">
              <?php
              $qBase = "tgl_dari=".urlencode($tgl_dari)."&tgl_sampai=".urlencode($tgl_sampai)
                      ."&cari=".urlencode($cari)."&status_kirim=".urlencode($filter_status)
                      ."&lanjut=".urlencode($filter_lanjut)."&limit=$limit";
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
            <i class="fa fa-medkit" style="font-size:52px;color:#ddd;display:block;margin-bottom:14px;"></i>
            <h4 style="color:#aaa;font-weight:400;">Tidak ada data vaksin pada periode <strong><?= $periode_label ?></strong></h4>
            <p style="color:#bbb;font-size:12px;">
              <i class="fa fa-info-circle"></i>
              Data ditampilkan berdasarkan <strong>detail_pemberian_obat</strong> yang sudah ada mapping di
              <strong>satu_sehat_mapping_vaksin</strong> dan sudah memiliki Encounter.
            </p>
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
      <span style="color:#ab47bc;font-weight:700;font-size:14px;font-family:'Courier New',monospace;">
        <i class="fa fa-file-text-o"></i> Log Vaksin — logs/vaksin_<?= date('Y-m') ?>.log
      </span>
      <div style="display:flex;gap:8px;align-items:center;">
        <button onclick="refreshLog()" style="background:#7b1fa2;border:none;color:#fff;padding:4px 12px;border-radius:4px;font-size:12px;cursor:pointer;"><i class="fa fa-refresh"></i> Refresh</button>
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
function showLog()  { document.getElementById('modal-log-overlay').style.display='flex'; refreshLog(); }
function closeLog() { document.getElementById('modal-log-overlay').style.display='none'; }
function refreshLog() {
    const el = document.getElementById('log-content');
    el.innerHTML = '<span style="color:#666;">Memuat...</span>';
    fetch('?_log=vaksin&_t='+Date.now()).then(r=>r.text()).then(txt=>{ _rawLog=txt; renderLog(txt); })
    .catch(()=>{ el.innerHTML='<span style="color:#dd4b39;">Gagal memuat.</span>'; });
}
function renderLog(txt) {
    const filter  = (document.getElementById('log-filter')?.value||'').toLowerCase();
    const errOnly = document.getElementById('chk-error-only')?.checked;
    let shown = 0;
    const html = txt.split('\n').map(line => {
        if (!line.trim()) return '';
        if (errOnly && !line.includes('ERROR') && !line.includes('EXCEPTION')) return '';
        if (filter && !line.toLowerCase().includes(filter)) return '';
        shown++;
        let color='#ccc';
        if (line.includes('] ERROR')||line.includes('] EXCEPTION')) color='#ff6b6b';
        else if (line.includes('] OK'))     color='#69db7c';
        else if (line.includes('] SEND'))   color='#74c0fc';
        else if (line.includes('] RESPON')) color='#ffd43b';
        return `<span style="color:${color}">${line.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}</span>`;
    }).filter(Boolean).join('\n');
    const el = document.getElementById('log-content');
    el.innerHTML = html || '<span style="color:#666;">Tidak ada log cocok.</span>';
    el.scrollTop = el.scrollHeight;
    document.getElementById('log-count').textContent = shown + ' baris';
}
function filterLog() { renderLog(_rawLog); }
function copyLog() {
    const filter  = (document.getElementById('log-filter')?.value||'').toLowerCase();
    const errOnly = document.getElementById('chk-error-only')?.checked;
    const lines = _rawLog.split('\n').filter(l => {
        if (!l.trim()) return false;
        if (errOnly && !l.includes('ERROR') && !l.includes('EXCEPTION')) return false;
        if (filter && !l.toLowerCase().includes(filter)) return false;
        return true;
    });
    navigator.clipboard.writeText(lines.join('\n')).then(()=>showToast('Log disalin!','success'));
}
document.getElementById('modal-log-overlay').addEventListener('click',function(e){if(e.target===this)closeLog();});
</script>