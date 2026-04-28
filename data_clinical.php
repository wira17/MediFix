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
if (isset($_GET['_log']) && $_GET['_log'] === 'clinical') {
    header('Content-Type: text/plain; charset=utf-8');
    $logFile = __DIR__ . '/logs/clinical_' . date('Y-m') . '.log';
    echo file_exists($logFile)
        ? implode("\n", array_slice(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -500))
        : '(Log belum ada)';
    exit;
}

// ── AJAX POST ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['kirim_clinical', 'kirim_semua_clinical'])) {
        include __DIR__ . '/api/kirim_clinical.php';
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

// ── Query ─────────────────────────────────────────────────────────
try {
    $statusWhere = '';
    if ($filter_status === 'terkirim') {
        $statusWhere = " AND ci.id_clinicalimpression IS NOT NULL AND ci.id_clinicalimpression != ''";
    } elseif ($filter_status === 'pending') {
        $statusWhere = " AND (ci.id_clinicalimpression IS NULL OR ci.id_clinicalimpression = '')";
    }

    $cariWhere  = '';
    $cariParams = [];
    if (!empty($cari)) {
        $cariWhere  = " AND (rp.no_rawat LIKE ? OR rp.no_rkm_medis LIKE ? OR p.nm_pasien LIKE ? OR pr.penilaian LIKE ?)";
        $cariParams = ["%$cari%", "%$cari%", "%$cari%", "%$cari%"];
    }

    // Base query Ralan
    $sqlRalan = "
        SELECT
            rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis,
            p.nm_pasien, p.no_ktp,
            pg.nama AS nm_dokter, pg.no_ktp AS ktp_dokter,
            pr.tgl_perawatan, pr.jam_rawat,
            pr.keluhan, pr.pemeriksaan, pr.penilaian,
            'Ralan' AS jenis_rawat,
            IFNULL(msp.ihs_number,'') AS ihs_number,
            IFNULL(se.id_encounter,'') AS id_encounter,
            IFNULL(sc.kd_penyakit,'') AS kd_penyakit,
            IFNULL(py.nm_penyakit,'') AS nm_penyakit,
            IFNULL(sc.id_condition,'') AS id_condition,
            IFNULL(ci.id_clinicalimpression,'') AS id_clinicalimpression
        FROM pemeriksaan_ralan pr
        JOIN reg_periksa rp             ON rp.no_rawat      = pr.no_rawat
        JOIN pasien p                   ON p.no_rkm_medis   = rp.no_rkm_medis
        JOIN pegawai pg                 ON pg.nik            = pr.nip
        LEFT JOIN medifix_ss_pasien msp ON msp.no_rkm_medis = p.no_rkm_medis
        LEFT JOIN satu_sehat_encounter se ON se.no_rawat     = pr.no_rawat
        LEFT JOIN satu_sehat_condition sc ON sc.no_rawat     = pr.no_rawat AND sc.status = 'Ralan'
        LEFT JOIN penyakit py           ON py.kd_penyakit    = sc.kd_penyakit
        LEFT JOIN satu_sehat_clinicalimpression ci
            ON ci.no_rawat = pr.no_rawat AND ci.tgl_perawatan = pr.tgl_perawatan
            AND ci.jam_rawat = pr.jam_rawat AND ci.status = 'Ralan'
        WHERE rp.tgl_registrasi BETWEEN ? AND ?
          AND pr.penilaian IS NOT NULL AND pr.penilaian != ''
        $statusWhere $cariWhere
    ";

    // Base query Ranap
    $sqlRanap = "
        SELECT
            rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis,
            p.nm_pasien, p.no_ktp,
            pg.nama AS nm_dokter, pg.no_ktp AS ktp_dokter,
            pr.tgl_perawatan, pr.jam_rawat,
            pr.keluhan, pr.pemeriksaan, pr.penilaian,
            'Ranap' AS jenis_rawat,
            IFNULL(msp.ihs_number,'') AS ihs_number,
            IFNULL(se.id_encounter,'') AS id_encounter,
            IFNULL(sc.kd_penyakit,'') AS kd_penyakit,
            IFNULL(py.nm_penyakit,'') AS nm_penyakit,
            IFNULL(sc.id_condition,'') AS id_condition,
            IFNULL(ci.id_clinicalimpression,'') AS id_clinicalimpression
        FROM pemeriksaan_ranap pr
        JOIN reg_periksa rp             ON rp.no_rawat      = pr.no_rawat
        JOIN pasien p                   ON p.no_rkm_medis   = rp.no_rkm_medis
        JOIN pegawai pg                 ON pg.nik            = pr.nip
        LEFT JOIN medifix_ss_pasien msp ON msp.no_rkm_medis = p.no_rkm_medis
        LEFT JOIN satu_sehat_encounter se ON se.no_rawat     = pr.no_rawat
        LEFT JOIN satu_sehat_condition sc ON sc.no_rawat     = pr.no_rawat AND sc.status = 'Ranap'
        LEFT JOIN penyakit py           ON py.kd_penyakit    = sc.kd_penyakit
        LEFT JOIN satu_sehat_clinicalimpression ci
            ON ci.no_rawat = pr.no_rawat AND ci.tgl_perawatan = pr.tgl_perawatan
            AND ci.jam_rawat = pr.jam_rawat AND ci.status = 'Ranap'
        WHERE rp.tgl_registrasi BETWEEN ? AND ?
          AND pr.penilaian IS NOT NULL AND pr.penilaian != ''
        $statusWhere $cariWhere
    ";

    $paramsBase = array_merge([$tgl_dari, $tgl_sampai], $cariParams);

    if ($filter_lanjut === 'Ralan') {
        $unionSQL    = "($sqlRalan)";
        $unionParams = $paramsBase;
    } elseif ($filter_lanjut === 'Ranap') {
        $unionSQL    = "($sqlRanap)";
        $unionParams = $paramsBase;
    } else {
        $unionSQL    = "($sqlRalan) UNION ALL ($sqlRanap)";
        $unionParams = array_merge($paramsBase, $paramsBase);
    }

    $stmtCount = $pdo_simrs->prepare("SELECT COUNT(*) FROM ($unionSQL) AS u");
    $stmtCount->execute($unionParams);
    $total       = (int)$stmtCount->fetchColumn();
    $total_pages = max(1, ceil($total / $limit));

    $stmtData = $pdo_simrs->prepare("
        SELECT * FROM ($unionSQL) AS u
        ORDER BY tgl_registrasi DESC, tgl_perawatan DESC, jam_rawat DESC
        LIMIT " . intval($limit) . " OFFSET " . intval($offset)
    );
    $stmtData->execute($unionParams);
    $data = $stmtData->fetchAll(PDO::FETCH_ASSOC);

    // Stats
    $stmtStats = $pdo_simrs->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN id_clinicalimpression != '' THEN 1 ELSE 0 END) AS terkirim,
            SUM(CASE WHEN id_clinicalimpression  = '' THEN 1 ELSE 0 END) AS pending
        FROM (
            SELECT IFNULL(ci.id_clinicalimpression,'') AS id_clinicalimpression
            FROM pemeriksaan_ralan pr
            JOIN reg_periksa rp ON rp.no_rawat = pr.no_rawat
            LEFT JOIN satu_sehat_clinicalimpression ci
                ON ci.no_rawat = pr.no_rawat AND ci.tgl_perawatan = pr.tgl_perawatan
                AND ci.jam_rawat = pr.jam_rawat AND ci.status = 'Ralan'
            WHERE rp.tgl_registrasi BETWEEN ? AND ?
              AND pr.penilaian IS NOT NULL AND pr.penilaian != ''
            UNION ALL
            SELECT IFNULL(ci.id_clinicalimpression,'') AS id_clinicalimpression
            FROM pemeriksaan_ranap pr
            JOIN reg_periksa rp ON rp.no_rawat = pr.no_rawat
            LEFT JOIN satu_sehat_clinicalimpression ci
                ON ci.no_rawat = pr.no_rawat AND ci.tgl_perawatan = pr.tgl_perawatan
                AND ci.jam_rawat = pr.jam_rawat AND ci.status = 'Ranap'
            WHERE rp.tgl_registrasi BETWEEN ? AND ?
              AND pr.penilaian IS NOT NULL AND pr.penilaian != ''
        ) s
    ");
    $stmtStats->execute([$tgl_dari, $tgl_sampai, $tgl_dari, $tgl_sampai]);
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
$page_title     = 'Clinical Impression — Satu Sehat';

$extra_css = '
.btn-send{width:32px;height:32px;border-radius:6px;padding:0;display:inline-flex;align-items:center;justify-content:center;border:1px solid transparent;cursor:pointer;transition:all .25s;font-size:12px;background:#8e44ad;border-color:#7d3c98;color:#fff}
.btn-send:hover{background:#7d3c98;transform:scale(1.12);box-shadow:0 3px 10px rgba(142,68,173,.45)}
.btn-send:disabled{opacity:.5;cursor:not-allowed;transform:none}
.btn-send.sent{background:#00a65a;border-color:#008d4c}
.btn-send.error-st{background:#dd4b39;border-color:#c23321}
.btn-send.spin i{animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.btn-copy{width:22px;height:22px;border-radius:4px;padding:0;display:inline-flex;align-items:center;justify-content:center;background:#ecf0f1;border:1px solid #ddd;color:#777;cursor:pointer;font-size:10px;vertical-align:middle;margin-left:3px}
.btn-copy:hover{background:#d5d8dc;color:#333}
.id-cell{font-family:"Courier New",monospace;font-size:10.5px;color:#555;word-break:break-all}
.no-id{font-style:italic;color:#ccc;font-size:11px}
.norawat-lbl{font-weight:700;color:#8e44ad;font-size:13px;font-family:"Courier New",monospace}
.rm-lbl{font-size:10.5px;color:#aaa;font-family:"Courier New",monospace}
.nm-pasien{font-weight:700;color:#333;font-size:13px}
.badge-sent{background:#dff0d8;color:#3c763d;border:1px solid #c3e6cb;padding:3px 9px;border-radius:10px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px}
.badge-pending{background:#fcf8e3;color:#8a6d3b;border:1px solid #faebcc;padding:3px 9px;border-radius:10px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px}
.badge-ralan{background:#d9edf7;color:#31708f;border:1px solid #bce8f1;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700}
.badge-ranap{background:#fcf8e3;color:#8a6d3b;border:1px solid #faebcc;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700}
.ihs-ok{color:#00a65a;font-size:10px;font-weight:700}
.ihs-miss{color:#dd4b39;font-size:10px}
.row-sent{background:#f0fff4!important}
.row-error{background:#fff5f5!important}
.action-bar{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.btn-kirim-semua{background:linear-gradient(135deg,#8e44ad,#7d3c98);border:none;color:#fff;padding:6px 14px;border-radius:5px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .25s}
.btn-kirim-semua:hover{transform:translateY(-2px);box-shadow:0 4px 14px rgba(142,68,173,.4);color:#fff}
.btn-kirim-semua:disabled{opacity:.6;cursor:not-allowed;transform:none}
.info-bar-stats{display:flex;gap:16px;padding:8px 15px;background:#f9f0ff;border-bottom:1px solid #e5e5e5;font-size:12px;flex-wrap:wrap;align-items:center}
.ibs-item{display:flex;align-items:center;gap:5px;color:#555}
.ibs-val{font-weight:700;font-size:14px}
.ibs-sep{color:#ddd}
.prog-dual{padding:10px 15px 6px;background:#f9f9f9;border-bottom:1px solid #e5e5e5}
.prog-row{display:flex;align-items:center;gap:10px;font-size:12px}
.prog-label{width:140px;color:#555;flex-shrink:0}
.prog-bar{flex:1;height:7px;border-radius:4px;background:#e5e5e5;overflow:hidden}
.prog-fill-ci{height:100%;border-radius:4px;background:linear-gradient(90deg,#8e44ad,#a569bd);transition:width .6s}
.prog-pct{width:38px;text-align:right;font-weight:700;color:#555;flex-shrink:0}
.tbl-ci thead tr th{background:#8e44ad;color:#fff!important;white-space:nowrap;font-size:12px}
.tbl-ci tbody td{vertical-align:middle}
.date-range-wrap{display:flex;align-items:center;gap:6px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;padding:5px 10px}
.date-range-wrap label{font-size:12px;color:#666;margin:0;white-space:nowrap}
.date-range-wrap input{border:none;background:transparent;font-size:13px;color:#333;padding:0;outline:none;width:130px}
.date-range-sep{color:#aaa;font-size:12px}
.btn-period{padding:3px 10px;border-radius:4px;font-size:11px;border:1px solid #dee2e6;background:#fff;color:#555;cursor:pointer;transition:all .2s}
.btn-period:hover{background:#8e44ad;color:#fff;border-color:#8e44ad}
.penilaian-lbl{font-size:11.5px;color:#2c3e50;line-height:1.5;max-width:200px;word-break:break-word}
.dx-badge{background:#f3e5f5;color:#6a1b9a;border:1px solid #e1bee7;padding:2px 6px;border-radius:5px;font-size:10px;font-weight:700;font-family:"Courier New",monospace}
.no-condition{font-size:10px;color:#bbb;font-style:italic}
#toast-container{position:fixed;bottom:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.toast-msg{padding:10px 18px;border-radius:6px;font-size:13px;font-weight:600;color:#fff;display:flex;align-items:center;gap:8px;box-shadow:0 4px 12px rgba(0,0,0,.2);pointer-events:auto;animation:toastIn .3s ease}
.toast-success{background:#00a65a}.toast-error{background:#dd4b39}.toast-info{background:#00c0ef}.toast-warn{background:#f39c12}
@keyframes toastIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
';

$extra_js = <<<'ENDJS'
function setPeriode(jenis) {
    const now = new Date(); let dari, sampai;
    sampai = now.toISOString().split("T")[0];
    if (jenis==="hari") { dari=sampai; }
    else if (jenis==="minggu") { const d=new Date(now);d.setDate(d.getDate()-6);dari=d.toISOString().split("T")[0]; }
    else if (jenis==="bulan")  { dari=new Date(now.getFullYear(),now.getMonth(),1).toISOString().split("T")[0]; }
    else if (jenis==="bulan_lalu") {
        dari=new Date(now.getFullYear(),now.getMonth()-1,1).toISOString().split("T")[0];
        sampai=new Date(now.getFullYear(),now.getMonth(),0).toISOString().split("T")[0];
    }
    document.getElementById("tgl_dari").value=dari;
    document.getElementById("tgl_sampai").value=sampai;
    document.querySelector("form").submit();
}
function showToast(msg,type) {
    type=type||"success";
    const c=document.getElementById("toast-container");
    if(!c)return;
    const icons={success:"check-circle",error:"times-circle",info:"info-circle",warn:"exclamation-triangle"};
    const d=document.createElement("div");
    d.className="toast-msg toast-"+type;
    d.innerHTML=`<i class="fa fa-${icons[type]||"info-circle"}"></i> ${msg}`;
    c.appendChild(d);
    setTimeout(()=>d.remove(),4000);
}
function copyText(text) {
    navigator.clipboard.writeText(text).then(()=>showToast("Disalin!","success")).catch(()=>{
        const ta=document.createElement("textarea");ta.value=text;document.body.appendChild(ta);ta.select();
        document.execCommand("copy");document.body.removeChild(ta);showToast("Disalin!","success");
    });
}
function kirimSatu(noRawat,tglPerawatan,jamRawat,status,btnEl) {
    btnEl.disabled=true;const origHTML=btnEl.innerHTML;
    btnEl.classList.add("spin");btnEl.innerHTML=`<i class="fa fa-spinner"></i>`;
    fetch(window.location.href,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},
        body:new URLSearchParams({action:"kirim_clinical",no_rawat:noRawat,tgl_perawatan:tglPerawatan,jam_rawat:jamRawat,status:status})})
    .then(r=>r.json()).then(resp=>{
        btnEl.classList.remove("spin");
        if(resp.status==="ok"){
            btnEl.classList.add("sent");btnEl.innerHTML=`<i class="fa fa-check"></i>`;
            showToast("✓ ClinicalImpression berhasil dikirim!","success");
            const row=btnEl.closest("tr");
            if(row){
                row.classList.add("row-sent");
                const badge=row.querySelector(".badge-ci-status");
                if(badge){badge.className="badge-ci-status badge-sent";badge.innerHTML=`<i class="fa fa-check-circle"></i> Terkirim`;}
                const idCell=row.querySelector(".id-ci-cell");
                if(idCell&&resp.id_clinical){
                    const short=resp.id_clinical.length>36?resp.id_clinical.substring(0,36)+'…':resp.id_clinical;
                    idCell.innerHTML=`<span class="id-cell" title="${resp.id_clinical}">${short}</span>
                        <button class="btn-copy" onclick="copyText('${resp.id_clinical}')"><i class="fa fa-copy"></i></button>`;
                }
            }
            const ep=document.getElementById("ibsPending");const et=document.getElementById("ibsTerkirim");
            if(ep)ep.textContent=Math.max(0,parseInt(ep.textContent)-1);
            if(et)et.textContent=parseInt(et.textContent||0)+1;
        } else {
            btnEl.disabled=false;btnEl.classList.add("error-st");
            btnEl.innerHTML=`<i class="fa fa-exclamation-triangle"></i>`;
            btnEl.title="Gagal: "+(resp.message||"");
            showToast("Gagal: "+(resp.message||""),"error");
            btnEl.closest("tr")?.classList.add("row-error");
        }
    }).catch(()=>{btnEl.disabled=false;btnEl.innerHTML=origHTML;btnEl.classList.remove("spin");showToast("Koneksi gagal","error");});
}
function kirimSemua() {
    const dari=document.getElementById("tgl_dari").value;
    const sampai=document.getElementById("tgl_sampai").value;
    const sisa=parseInt(document.getElementById("ibsPending")?.textContent?.replace(/\D/g,'')||"0");
    if(sisa===0){showToast("Semua sudah terkirim!","info");return;}
    if(!confirm(`Kirim ${sisa} data ClinicalImpression yang belum terkirim?\nPeriode: ${dari===sampai?dari:dari+" s/d "+sampai}`))return;
    const btn=document.getElementById("btnKirimSemua");
    if(btn){btn.disabled=true;btn.innerHTML=`<i class="fa fa-spinner fa-spin"></i> Mengirim…`;}
    fetch(window.location.href,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},
        body:new URLSearchParams({action:"kirim_semua_clinical",tgl_dari:dari,tgl_sampai:sampai})})
    .then(r=>r.json()).then(resp=>{
        if(btn){btn.disabled=false;btn.innerHTML=`<i class="fa fa-send"></i> Kirim Semua Pending`;}
        if(resp.status==="ok"){
            showToast(`Selesai: ${resp.berhasil} berhasil, ${resp.gagal} gagal dari ${resp.jumlah} data.`,resp.gagal>0?"warn":"success");
            setTimeout(()=>location.reload(),2000);
        } else { showToast("Gagal: "+(resp.message||""),"error"); }
    }).catch(()=>{
        if(btn){btn.disabled=false;btn.innerHTML=`<i class="fa fa-send"></i> Kirim Semua Pending`;}
        showToast("Koneksi gagal","error");
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
      <i class="fa fa-stethoscope" style="color:#8e44ad;"></i>
      Clinical Impression
      <small>Satu Sehat &mdash; <?= $periode_label ?></small>
    </h1>
    <ol class="breadcrumb">
      <li><a href="dashboard.php"><i class="fa fa-dashboard"></i> Home</a></li>
      <li>Satu Sehat</li>
      <li class="active">Clinical Impression</li>
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
              <input type="text" name="cari" class="form-control" placeholder="Pasien / No. Rawat / Penilaian…"
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
            <a href="data_clinical.php" class="btn btn-default"><i class="fa fa-refresh"></i> Reset</a>
          </form>
        </div>
      </div>

      <!-- Tabel -->
      <div class="box box-primary" style="border-top-color:#8e44ad;">
        <div class="box-header with-border">
          <h3 class="box-title">
            <i class="fa fa-stethoscope"></i> Data Clinical Impression
            <span class="badge" style="background:#8e44ad;"><?= number_format($total) ?></span>
          </h3>
          <div class="box-tools pull-right">
            <div class="action-bar">
              <button id="btnKirimSemua" onclick="kirimSemua()" class="btn-kirim-semua" <?= $st_pending===0?'disabled':'' ?>>
                <i class="fa fa-send"></i> Kirim Semua Pending
                <?php if ($st_pending > 0): ?><span class="badge" style="background:#dd4b39;"><?= number_format($st_pending) ?></span><?php endif; ?>
              </button>
              <button onclick="showLog()" class="btn btn-default btn-sm"><i class="fa fa-file-text-o"></i> Log</button>
              <a href="?tgl_dari=<?= urlencode($tgl_dari) ?>&tgl_sampai=<?= urlencode($tgl_sampai) ?>&cari=<?= urlencode($cari) ?>&status_kirim=<?= urlencode($filter_status) ?>&lanjut=<?= urlencode($filter_lanjut) ?>&limit=<?= $limit ?>"
                 class="btn btn-default btn-sm"><i class="fa fa-refresh"></i></a>
            </div>
          </div>
        </div>

        <div class="info-bar-stats">
          <div class="ibs-item"><i class="fa fa-database" style="color:#8e44ad;"></i> Total: <span class="ibs-val" style="color:#8e44ad;"><?= number_format($st_total) ?></span></div>
          <span class="ibs-sep">|</span>
          <div class="ibs-item"><i class="fa fa-check-circle" style="color:#00a65a;"></i> Terkirim: <span class="ibs-val" style="color:#00a65a;" id="ibsTerkirim"><?= number_format($st_terkirim) ?></span></div>
          <div class="ibs-item"><i class="fa fa-clock-o" style="color:#f39c12;"></i> Belum: <span class="ibs-val" style="color:#f39c12;" id="ibsPending"><?= number_format($st_pending) ?></span></div>
          <span class="ibs-sep">|</span>
          <div class="ibs-item" style="font-size:11px;color:#777;"><i class="fa fa-info-circle"></i> Data dari <strong>pemeriksaan_ralan</strong> &amp; <strong>pemeriksaan_ranap</strong> yang ada penilaian</div>
        </div>

        <div class="prog-dual">
          <div class="prog-row">
            <span class="prog-label"><i class="fa fa-stethoscope"></i> Clinical Impression</span>
            <div class="prog-bar"><div class="prog-fill-ci" style="width:<?= $pct ?>%;"></div></div>
            <span class="prog-pct"><?= $pct ?>%</span>
          </div>
        </div>

        <div class="box-body" style="padding:0;">
          <?php if (!empty($data)): ?>
          <div class="table-responsive">
            <table class="table table-bordered table-striped table-hover tbl-ci" style="margin-bottom:0;font-size:12px;">
              <thead>
                <tr>
                  <th width="36"  class="text-center">#</th>
                  <th width="40"  class="text-center">Kirim</th>
                  <th width="150">No. Rawat</th>
                  <th width="170">Pasien</th>
                  <th width="190">Penilaian / Klinis</th>
                  <th width="130">Diagnosis (ICD-10)</th>
                  <th width="70"  class="text-center">Jenis</th>
                  <th width="100">Tgl Perawatan</th>
                  <th width="120">Dokter</th>
                  <th>ID ClinicalImpression</th>
                  <th width="110" class="text-center">Status</th>
                </tr>
              </thead>
              <tbody>
              <?php $no = $offset + 1; foreach ($data as $r):
                  $isSent  = !empty($r['id_clinicalimpression']);
                  $shortId = $isSent ? mb_strimwidth($r['id_clinicalimpression'], 0, 36, '…') : '';
              ?>
              <tr class="<?= $isSent ? 'row-sent' : '' ?>">
                <td class="text-center" style="color:#aaa;font-size:11px;font-weight:600;"><?= $no++ ?></td>

                <td class="text-center">
                  <button class="btn-send <?= $isSent?'sent':'' ?>"
                          onclick="kirimSatu('<?= addslashes($r['no_rawat']) ?>','<?= htmlspecialchars($r['tgl_perawatan']) ?>','<?= htmlspecialchars($r['jam_rawat']) ?>','<?= $r['jenis_rawat'] ?>',this)"
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
                  <div class="rm-lbl"><?= htmlspecialchars($r['no_ktp'] ?: '-') ?></div>
                  <div style="margin-top:2px;">
                    <?php if (!empty($r['ihs_number'])): ?>
                      <span class="ihs-ok"><i class="fa fa-id-card"></i> IHS OK</span>
                    <?php else: ?>
                      <span class="ihs-miss"><i class="fa fa-exclamation-triangle"></i> No IHS</span>
                    <?php endif; ?>
                    <?php if (empty($r['id_encounter'])): ?>
                      <span style="font-size:10px;color:#e74c3c;margin-left:4px;"><i class="fa fa-warning"></i> No Enc</span>
                    <?php endif; ?>
                  </div>
                </td>

                <td>
                  <div class="penilaian-lbl">
                    <?= htmlspecialchars(mb_strimwidth($r['penilaian'] ?? '', 0, 100, '…')) ?>
                  </div>
                </td>

                <td>
                  <?php if (!empty($r['kd_penyakit'])): ?>
                    <div><span class="dx-badge"><?= htmlspecialchars($r['kd_penyakit']) ?></span></div>
                    <div style="font-size:10.5px;color:#777;margin-top:2px;"><?= htmlspecialchars(mb_strimwidth($r['nm_penyakit'] ?? '', 0, 40, '…')) ?></div>
                    <?php if (!empty($r['id_condition'])): ?>
                      <div style="font-size:9.5px;color:#aaa;margin-top:1px;font-family:'Courier New',monospace;">Cond ✓</div>
                    <?php else: ?>
                      <div class="no-condition">No Condition</div>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="no-condition">Belum ada diagnosis</span>
                  <?php endif; ?>
                </td>

                <td class="text-center">
                  <span class="badge-<?= strtolower($r['jenis_rawat']) === 'ranap' ? 'ranap' : 'ralan' ?>">
                    <?= htmlspecialchars($r['jenis_rawat']) ?>
                  </span>
                </td>

                <td>
                  <div><?= date('d/m/Y', strtotime($r['tgl_perawatan'])) ?></div>
                  <div style="font-size:10.5px;color:#aaa;"><?= substr($r['jam_rawat'],0,5) ?> WIB</div>
                </td>

                <td>
                  <div style="font-size:12px;font-weight:600;color:#444;"><?= htmlspecialchars($r['nm_dokter'] ?: '-') ?></div>
                </td>

                <td class="id-ci-cell">
                  <?php if ($isSent): ?>
                    <span class="id-cell" title="<?= htmlspecialchars($r['id_clinicalimpression']) ?>"><?= htmlspecialchars($shortId) ?></span>
                    <button class="btn-copy" onclick="copyText('<?= addslashes($r['id_clinicalimpression']) ?>')" title="Salin"><i class="fa fa-copy"></i></button>
                  <?php else: ?>
                    <span class="no-id"><i class="fa fa-minus-circle"></i> Belum dikirim</span>
                  <?php endif; ?>
                </td>

                <td class="text-center">
                  <span class="badge-ci-status <?= $isSent?'badge-sent':'badge-pending' ?>">
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
              dari <strong><?= number_format($total) ?></strong> data &nbsp;|&nbsp; Periode: <strong><?= $periode_label ?></strong>
            </div>
            <ul class="pagination pagination-sm no-margin pull-right">
              <?php
              $qBase="tgl_dari=".urlencode($tgl_dari)."&tgl_sampai=".urlencode($tgl_sampai)
                    ."&cari=".urlencode($cari)."&status_kirim=".urlencode($filter_status)
                    ."&lanjut=".urlencode($filter_lanjut)."&limit=$limit";
              if($page>1):?><li><a href="?page=<?=$page-1?>&<?=$qBase?>">«</a></li><?php endif;
              for($i=max(1,$page-3);$i<=min($total_pages,$page+3);$i++):?>
              <li <?=$i==$page?'class="active"':''?>><a href="?page=<?=$i?>&<?=$qBase?>"><?=$i?></a></li>
              <?php endfor;
              if($page<$total_pages):?><li><a href="?page=<?=$page+1?>&<?=$qBase?>">»</a></li><?php endif;?>
            </ul>
          </div>
          <?php endif; ?>

          <?php else: ?>
          <div style="padding:50px;text-align:center;">
            <i class="fa fa-stethoscope" style="font-size:52px;color:#ddd;display:block;margin-bottom:14px;"></i>
            <h4 style="color:#aaa;font-weight:400;">Tidak ada data Clinical Impression pada periode <strong><?= $periode_label ?></strong></h4>
            <p style="color:#bbb;font-size:12px;">
              <i class="fa fa-info-circle"></i>
              Data muncul jika kolom <strong>penilaian</strong> di <strong>pemeriksaan_ralan</strong> atau
              <strong>pemeriksaan_ranap</strong> terisi.
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
      <span style="color:#a569bd;font-weight:700;font-size:14px;font-family:'Courier New',monospace;">
        <i class="fa fa-file-text-o"></i> Log Clinical Impression — logs/clinical_<?= date('Y-m') ?>.log
      </span>
      <div style="display:flex;gap:8px;">
        <button onclick="refreshLog()" style="background:#8e44ad;border:none;color:#fff;padding:4px 12px;border-radius:4px;font-size:12px;cursor:pointer;"><i class="fa fa-refresh"></i> Refresh</button>
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
var _rawLog='';
function showLog(){document.getElementById('modal-log-overlay').style.display='flex';refreshLog();}
function closeLog(){document.getElementById('modal-log-overlay').style.display='none';}
function refreshLog(){
    const el=document.getElementById('log-content');
    el.innerHTML='<span style="color:#666;">Memuat...</span>';
    fetch('?_log=clinical&_t='+Date.now()).then(r=>r.text()).then(txt=>{_rawLog=txt;renderLog(txt);})
    .catch(()=>{el.innerHTML='<span style="color:#dd4b39;">Gagal memuat.</span>';});
}
function renderLog(txt){
    const filter=(document.getElementById('log-filter')?.value||'').toLowerCase();
    const errOnly=document.getElementById('chk-error-only')?.checked;
    let shown=0;
    const html=txt.split('\n').map(line=>{
        if(!line.trim())return'';
        if(errOnly&&!line.includes('ERROR'))return'';
        if(filter&&!line.toLowerCase().includes(filter))return'';
        shown++;
        let color='#ccc';
        if(line.includes('] ERROR'))color='#ff6b6b';
        else if(line.includes('] OK'))color='#69db7c';
        else if(line.includes('] SEND'))color='#74c0fc';
        else if(line.includes('] RESPONSE'))color='#ffd43b';
        else if(line.includes('] WARN'))color='#ffa94d';
        return`<span style="color:${color}">${line.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}</span>`;
    }).filter(Boolean).join('\n');
    const el=document.getElementById('log-content');
    el.innerHTML=html||'<span style="color:#666;">Tidak ada log cocok.</span>';
    el.scrollTop=el.scrollHeight;
    document.getElementById('log-count').textContent=shown+' baris';
}
function filterLog(){renderLog(_rawLog);}
function copyLog(){
    const filter=(document.getElementById('log-filter')?.value||'').toLowerCase();
    const errOnly=document.getElementById('chk-error-only')?.checked;
    const lines=_rawLog.split('\n').filter(l=>{
        if(!l.trim())return false;
        if(errOnly&&!l.includes('ERROR'))return false;
        if(filter&&!l.toLowerCase().includes(filter))return false;
        return true;
    });
    navigator.clipboard.writeText(lines.join('\n')).then(()=>showToast('Log disalin!','success'));
}
document.getElementById('modal-log-overlay').addEventListener('click',function(e){if(e.target===this)closeLog();});
</script>