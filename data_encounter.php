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

// ── Filter & Pagination
$filter_tanggal = $_GET['tanggal'] ?? date('Y-m-d');
$cari           = $_GET['cari']    ?? '';
$filter_status  = $_GET['status']  ?? '';
$filter_lanjut  = $_GET['lanjut']  ?? '';   // 'Ralan' | 'Ranap' | ''
$limit          = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 20;
$page           = isset($_GET['page'])  ? max(1, intval($_GET['page']))  : 1;
$offset         = ($page - 1) * $limit;

// ── AJAX: Kirim satu encounter
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'kirim') {
    header('Content-Type: application/json');
    $no_rawat = trim($_POST['no_rawat'] ?? '');
    if (!$no_rawat) { echo json_encode(['status'=>'error','message'=>'No. Rawat kosong']); exit; }
    try {
        // TODO: callSatuSehatEncounterAPI($no_rawat)
        // UPDATE satu_sehat_encounter SET id_encounter=? WHERE no_rawat=?
        echo json_encode(['status'=>'ok','message'=>'Permintaan dikirim.','no_rawat'=>$no_rawat]);
    } catch (Exception $e) { echo json_encode(['status'=>'error','message'=>$e->getMessage()]); }
    exit;
}

// ── AJAX: Kirim semua pending
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'kirim_semua') {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo_simrs->prepare(
            "SELECT COUNT(*) FROM satu_sehat_encounter e
             JOIN reg_periksa r ON e.no_rawat = r.no_rawat
             WHERE r.tgl_registrasi = ?
               AND (e.id_encounter IS NULL OR e.id_encounter = '')"
        );
        $stmt->execute([$filter_tanggal]);
        echo json_encode(['status'=>'ok','jumlah'=>(int)$stmt->fetchColumn()]);
    } catch (Exception $e) { echo json_encode(['status'=>'error','message'=>$e->getMessage()]); }
    exit;
}

// ── Ambil Data
try {
    $wheres = ["r.tgl_registrasi = ?"];
    $params = [$filter_tanggal];

    if (!empty($cari)) {
        $wheres[] = "(e.no_rawat LIKE ? OR p.nm_pasien LIKE ? OR p.no_rkm_medis LIKE ?
                      OR d.nm_dokter LIKE ? OR pl.nm_poli LIKE ? OR e.id_encounter LIKE ?)";
        $params   = array_merge($params, ["%$cari%","%$cari%","%$cari%","%$cari%","%$cari%","%$cari%"]);
    }
    if ($filter_status === 'terkirim') {
        $wheres[] = "(e.id_encounter IS NOT NULL AND e.id_encounter != '')";
    } elseif ($filter_status === 'pending') {
        $wheres[] = "(e.id_encounter IS NULL OR e.id_encounter = '')";
    }
    if (!empty($filter_lanjut)) {
        $wheres[] = "r.status_lanjut = ?";
        $params[] = $filter_lanjut;
    }

    $where_sql = "WHERE " . implode(" AND ", $wheres);

    $base_join = "
        FROM satu_sehat_encounter e
        JOIN reg_periksa r   ON e.no_rawat      = r.no_rawat
        JOIN pasien p        ON r.no_rkm_medis   = p.no_rkm_medis
        LEFT JOIN dokter d   ON r.kd_dokter      = d.kd_dokter
        LEFT JOIN poliklinik pl ON r.kd_poli     = pl.kd_poli
        LEFT JOIN penjab pj  ON r.kd_pj          = pj.kd_pj
    ";

    // Total
    $stmtCount = $pdo_simrs->prepare("SELECT COUNT(*) $base_join $where_sql");
    $stmtCount->execute($params);
    $total       = (int)$stmtCount->fetchColumn();
    $total_pages = max(1, ceil($total / $limit));

    // Data
    $stmtData = $pdo_simrs->prepare(
        "SELECT
            e.no_rawat, e.id_encounter,
            r.no_reg, r.tgl_registrasi, r.jam_reg,
            r.status_lanjut, r.stts_daftar, r.status_bayar,
            r.stts AS stts_periksa, r.kd_pj,
            r.umurdaftar, r.sttsumur,
            p.no_rkm_medis, p.nm_pasien, p.jk, p.tgl_lahir,
            d.nm_dokter,
            pl.nm_poli,
            pj.png_jawab
         $base_join $where_sql
         ORDER BY r.jam_reg DESC
         LIMIT ".intval($limit)." OFFSET ".intval($offset)
    );
    $stmtData->execute($params);
    $data = $stmtData->fetchAll(PDO::FETCH_ASSOC);

    // Stats untuk tanggal yang dipilih
    $stmtStats = $pdo_simrs->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN e.id_encounter IS NOT NULL AND e.id_encounter!='' THEN 1 ELSE 0 END) AS terkirim,
            SUM(CASE WHEN e.id_encounter IS NULL OR e.id_encounter='' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN r.status_lanjut='Ralan' THEN 1 ELSE 0 END) AS ralan,
            SUM(CASE WHEN r.status_lanjut='Ranap' THEN 1 ELSE 0 END) AS ranap
         FROM satu_sehat_encounter e
         JOIN reg_periksa r ON e.no_rawat = r.no_rawat
         WHERE r.tgl_registrasi = ?"
    );
    $stmtStats->execute([$filter_tanggal]);
    $stats       = $stmtStats->fetch(PDO::FETCH_ASSOC);
    $st_total    = (int)($stats['total']    ?? 0);
    $st_terkirim = (int)($stats['terkirim'] ?? 0);
    $st_pending  = (int)($stats['pending']  ?? 0);
    $st_ralan    = (int)($stats['ralan']    ?? 0);
    $st_ranap    = (int)($stats['ranap']    ?? 0);
    $dbError     = null;

} catch (Exception $e) {
    $data=[]; $total=0; $total_pages=1;
    $st_total=$st_terkirim=$st_pending=$st_ralan=$st_ranap=0;
    $dbError=$e->getMessage();
}

$pct = $st_total > 0 ? round(($st_terkirim / $st_total) * 100) : 0;

$page_title = 'Encounter - MediFix';

$extra_css = '
/* ── Warna tema Encounter: teal/hijau ── */
:root {
    --enc-primary: #00897b;
    --enc-dark:    #00695c;
    --enc-soft:    #e0f2f1;
    --enc-border:  #b2dfdb;
}

.btn-send { width:32px;height:32px;border-radius:6px;padding:0;display:inline-flex;align-items:center;justify-content:center;border:1px solid transparent;cursor:pointer;transition:all .25s;font-size:13px;background-color:var(--enc-primary);border-color:var(--enc-dark);color:#fff; }
.btn-send:hover { background-color:var(--enc-dark);transform:scale(1.12);box-shadow:0 3px 10px rgba(0,137,123,.45);color:#fff; }
.btn-send:disabled { opacity:.5;cursor:not-allowed;transform:none; }
.btn-send.sent { background-color:#00a65a;border-color:#008d4c; }
.btn-send.sent:hover { background-color:#008d4c; }
.btn-send.spin i { animation:spin .7s linear infinite; }
@keyframes spin { to{transform:rotate(360deg);} }

.btn-copy { width:24px;height:24px;border-radius:4px;padding:0;display:inline-flex;align-items:center;justify-content:center;background:#ecf0f1;border:1px solid #ddd;color:#777;cursor:pointer;transition:all .2s;font-size:11px;vertical-align:middle;margin-left:4px; }
.btn-copy:hover { background:#d5d8dc;color:#333;transform:scale(1.1); }

.id-cell { font-family:"Courier New",monospace;font-size:11px;color:#555;word-break:break-all; }
.no-id   { font-style:italic;color:#bbb;font-size:11.5px; }

/* Status badges */
.badge-sent    { background:#dff0d8;color:#3c763d;border:1px solid #c3e6cb;padding:4px 10px;border-radius:10px;font-size:10.5px;font-weight:700;display:inline-flex;align-items:center;gap:4px; }
.badge-pending { background:#f2dede;color:#a94442;border:1px solid #f5c6cb;padding:4px 10px;border-radius:10px;font-size:10.5px;font-weight:700;display:inline-flex;align-items:center;gap:4px; }
.badge-ralan   { background:#d9edf7;color:#31708f;border:1px solid #bce8f1;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700; }
.badge-ranap   { background:#fcf8e3;color:#8a6d3b;border:1px solid #faebcc;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700; }
.badge-baru    { background:#e8f5e9;color:#2e7d32;border:1px solid #c8e6c9;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700; }
.badge-lama    { background:#f3e5f5;color:#6a1b9a;border:1px solid #e1bee7;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700; }

/* Status bayar */
.badge-bayar   { background:#dff0d8;color:#3c763d;border:1px solid #c3e6cb;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700; }
.badge-blm-bayar { background:#f2dede;color:#a94442;border:1px solid #f5c6cb;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700; }

.row-sent { background-color:#f0fff4 !important; }

.norawat-lbl { font-weight:700;color:var(--enc-primary);font-size:13px;font-family:"Courier New",monospace; }
.noreg-lbl   { font-size:11px;color:#aaa;font-family:"Courier New",monospace; }
.rm-lbl      { font-size:11px;color:#999;font-family:"Courier New",monospace; }
.kd-lbl      { display:inline-block;background:var(--enc-soft);border:1px solid var(--enc-border);color:var(--enc-primary);padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700; }
.nm-pasien   { font-weight:700;color:#333;font-size:13px; }
.waktu-lbl   { font-size:13px;font-weight:600;color:#333; }
.waktu-sub   { font-size:10.5px;color:#aaa;margin-top:2px; }

.action-bar { display:flex;align-items:center;gap:8px;flex-wrap:wrap; }
.btn-kirim-semua { background:linear-gradient(135deg,var(--enc-primary),var(--enc-dark));border:none;color:#fff;padding:6px 16px;border-radius:5px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .25s; }
.btn-kirim-semua:hover { transform:translateY(-2px);box-shadow:0 4px 14px rgba(0,137,123,.4);color:#fff; }
.btn-kirim-semua:disabled { opacity:.6;cursor:not-allowed;transform:none; }

/* Info bar */
.info-bar-stats { display:flex;gap:20px;padding:10px 15px;background:#f9f9f9;border-bottom:1px solid #e5e5e5;font-size:12.5px;flex-wrap:wrap;align-items:center; }
.ibs-item { display:flex;align-items:center;gap:6px;color:#555; }
.ibs-val  { font-weight:700;font-size:15px; }
.ibs-val.teal   { color:var(--enc-primary); }
.ibs-val.green  { color:#00a65a; }
.ibs-val.red    { color:#dd4b39; }
.ibs-val.blue   { color:#31708f; }
.ibs-val.orange { color:#8a6d3b; }
.ibs-sep  { color:#ddd; }
.ibs-prog { flex:1;min-width:120px; }
.ibs-prog-bar  { height:6px;border-radius:3px;background:#e0e0e0;overflow:hidden;margin-top:3px; }
.ibs-prog-fill { height:100%;border-radius:3px;background:linear-gradient(90deg,var(--enc-primary),#00a65a);transition:width .6s; }

.prog-strip { background:#ecf0f1;height:4px;overflow:hidden; }
.prog-fill  { height:100%;background:linear-gradient(90deg,var(--enc-primary),#00a65a);transition:width .6s; }

/* Table */
.tbl-enc thead tr th { background:var(--enc-primary);color:#fff !important;white-space:nowrap; }
.tbl-enc tbody td { vertical-align:middle; }
.tbl-enc .box-primary>.box-header { border-color:var(--enc-primary); }

/* Box override warna teal */
.box-encounter.box-primary { border-top-color:var(--enc-primary); }
.box-encounter .box-header.with-border { background:var(--enc-soft);border-bottom-color:var(--enc-border); }
.box-encounter .box-header .box-title { color:var(--enc-primary); }

/* Toast */
#toast-container { position:fixed;bottom:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none; }
.toast-msg { padding:10px 18px;border-radius:6px;font-size:13px;font-weight:600;color:#fff;display:flex;align-items:center;gap:8px;box-shadow:0 4px 12px rgba(0,0,0,.2);pointer-events:auto;animation:toastIn .3s ease; }
.toast-success { background:#00a65a; }
.toast-error   { background:#dd4b39; }
.toast-info    { background:#00c0ef; }
@keyframes toastIn { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
';

$extra_js = '
function showToast(msg,type){
    type=type||"success"; const c=document.getElementById("toast-container"); if(!c)return;
    const d=document.createElement("div"); d.className="toast-msg toast-"+type;
    const icons={success:"check-circle",error:"times-circle",info:"info-circle"};
    d.innerHTML=`<i class="fa fa-${icons[type]||"info-circle"}"></i> ${msg}`;
    c.appendChild(d); setTimeout(()=>d.remove(),3500);
}
function copyId(text){
    navigator.clipboard.writeText(text).then(()=>showToast("ID Encounter disalin!","success")).catch(()=>{
        const ta=document.createElement("textarea"); ta.value=text; document.body.appendChild(ta); ta.select(); document.execCommand("copy"); document.body.removeChild(ta); showToast("ID Encounter disalin!","success");
    });
}
function kirimSatu(noRawat,btnEl){
    btnEl.disabled=true; const origHTML=btnEl.innerHTML; btnEl.classList.add("spin"); btnEl.innerHTML="<i class=\"fa fa-spinner\"></i>";
    fetch(window.location.href,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:new URLSearchParams({action:"kirim",no_rawat:noRawat})})
    .then(r=>r.json()).then(resp=>{
        btnEl.classList.remove("spin");
        if(resp.status==="ok"){
            btnEl.classList.add("sent"); btnEl.innerHTML="<i class=\"fa fa-check\"></i>"; btnEl.title="Terkirim";
            showToast("No. Rawat "+noRawat+" berhasil dikirim!","success");
            const row=btnEl.closest("tr");
            if(row){ const b=row.querySelector(".badge-status"); if(b){b.className="badge-status badge-sent";b.innerHTML="<i class=\"fa fa-check-circle\"></i> Terkirim";} row.classList.add("row-sent"); }
            const ep=document.getElementById("ibsPending"); if(ep){const v=parseInt(ep.textContent)-1;if(!isNaN(v)&&v>=0)ep.textContent=v;}
            const et=document.getElementById("ibsTerkirim"); if(et)et.textContent=parseInt(et.textContent||0)+1;
        } else { btnEl.disabled=false; btnEl.innerHTML=origHTML; showToast("Gagal: "+(resp.message||""),"error"); }
    }).catch(()=>{ btnEl.disabled=false; btnEl.innerHTML=origHTML; btnEl.classList.remove("spin"); showToast("Koneksi gagal.","error"); });
}
function kirimSemua(){
    if(!confirm("Kirim semua Encounter yang belum terkirim ke Satu Sehat?")) return;
    const btn=document.getElementById("btnKirimSemua");
    if(btn){btn.disabled=true;btn.innerHTML="<i class=\"fa fa-spinner fa-spin\"></i> Mengirim\u2026";}
    fetch(window.location.href,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:new URLSearchParams({action:"kirim_semua"})})
    .then(r=>r.json()).then(resp=>{
        if(btn){btn.disabled=false;btn.innerHTML="<i class=\"fa fa-send\"></i> Kirim Semua Pending";}
        if(resp.status==="ok") showToast("Proses kirim "+(resp.jumlah||0)+" data dimulai.","info");
        else showToast("Gagal: "+(resp.message||""),"error");
    }).catch(()=>{ if(btn){btn.disabled=false;btn.innerHTML="<i class=\"fa fa-send\"></i> Kirim Semua Pending";} showToast("Koneksi gagal.","error"); });
}
';

include 'includes/header.php';
include 'includes/sidebar.php';
?>

  <div id="toast-container"></div>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>
        <i class="fa fa-stethoscope" style="color:#00897b;"></i>
        Encounter
        <small>Satu Sehat &mdash; <?= date('d F Y', strtotime($filter_tanggal)) ?></small>
      </h1>
      <ol class="breadcrumb">
        <li><a href="dashboard.php"><i class="fa fa-dashboard"></i> Home</a></li>
        <li>Satu Sehat</li>
        <li class="active">Encounter</li>
      </ol>
    </section>

    <section class="content">

      <?php if ($dbError): ?>
      <div class="callout callout-danger">
        <h4><i class="fa fa-ban"></i> Koneksi Database Gagal</h4>
        <p><?= htmlspecialchars($dbError) ?></p>
      </div>
      <?php endif; ?>

      <div class="row">
        <div class="col-xs-12">

          <!-- Filter -->
          <div class="box box-default">
            <div class="box-header with-border">
              <h3 class="box-title"><i class="fa fa-filter"></i> Filter Data</h3>
              <div class="box-tools pull-right">
                <button type="button" class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-minus"></i></button>
              </div>
            </div>
            <div class="box-body">
              <form method="GET" class="form-inline" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
                <div class="form-group">
                  <label style="margin-right:6px;"><i class="fa fa-calendar"></i> Tanggal:</label>
                  <input type="date" name="tanggal" class="form-control" value="<?= htmlspecialchars($filter_tanggal) ?>">
                </div>
                <div class="form-group">
                  <label style="margin-right:6px;">Cari:</label>
                  <input type="text" name="cari" class="form-control"
                         placeholder="Pasien / Dokter / No. Rawat / ID…"
                         value="<?= htmlspecialchars($cari) ?>" style="width:230px;">
                </div>
                <div class="form-group">
                  <label style="margin-right:6px;">Status Kirim:</label>
                  <select name="status" class="form-control">
                    <option value=""         <?= $filter_status===''         ?'selected':'' ?>>Semua</option>
                    <option value="terkirim" <?= $filter_status==='terkirim' ?'selected':'' ?>>Terkirim</option>
                    <option value="pending"  <?= $filter_status==='pending'  ?'selected':'' ?>>Belum Terkirim</option>
                  </select>
                </div>
                <div class="form-group">
                  <label style="margin-right:6px;">Jenis:</label>
                  <select name="lanjut" class="form-control">
                    <option value=""      <?= $filter_lanjut===''      ?'selected':'' ?>>Semua</option>
                    <option value="Ralan" <?= $filter_lanjut==='Ralan' ?'selected':'' ?>>Ralan</option>
                    <option value="Ranap" <?= $filter_lanjut==='Ranap' ?'selected':'' ?>>Ranap</option>
                  </select>
                </div>
                <div class="form-group">
                  <label style="margin-right:6px;">Tampilkan:</label>
                  <select name="limit" class="form-control">
                    <option value="20"  <?= $limit==20 ?'selected':''?>>20</option>
                    <option value="50"  <?= $limit==50 ?'selected':''?>>50</option>
                    <option value="100" <?= $limit==100?'selected':''?>>100</option>
                    <option value="200" <?= $limit==200?'selected':''?>>200</option>
                  </select>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i> Tampilkan</button>
                <a href="data_encounter.php" class="btn btn-default"><i class="fa fa-refresh"></i> Reset</a>
              </form>
            </div>
          </div>

          <!-- Tabel -->
          <div class="box box-primary box-encounter">
            <div class="box-header with-border">
              <h3 class="box-title">
                <i class="fa fa-table"></i>
                Data Encounter
                <span class="badge" style="background:#00897b;margin-left:6px;"><?= number_format($total) ?></span>
                <?php if ($filter_status==='pending'):   ?><span class="badge" style="background:#dd4b39;">Pending</span><?php endif; ?>
                <?php if ($filter_status==='terkirim'):  ?><span class="badge" style="background:#00a65a;">Terkirim</span><?php endif; ?>
                <?php if ($filter_lanjut):               ?><span class="badge" style="background:#31708f;"><?= $filter_lanjut ?></span><?php endif; ?>
              </h3>
              <div class="box-tools pull-right">
                <div class="action-bar">
                  <button id="btnKirimSemua" onclick="kirimSemua()" class="btn-kirim-semua" <?= $st_pending===0?'disabled':'' ?>>
                    <i class="fa fa-send"></i> Kirim Semua Pending
                    <?php if ($st_pending > 0): ?>
                    <span class="badge" style="background:#dd4b39;margin-left:3px;"><?= number_format($st_pending) ?></span>
                    <?php endif; ?>
                  </button>
                  <a href="?tanggal=<?= urlencode($filter_tanggal) ?>&cari=<?= urlencode($cari) ?>&status=<?= urlencode($filter_status) ?>&lanjut=<?= urlencode($filter_lanjut) ?>&limit=<?= $limit ?>"
                     class="btn btn-default btn-sm" title="Refresh"><i class="fa fa-refresh"></i></a>
                </div>
              </div>
            </div>

            <!-- Info bar stats -->
            <div class="info-bar-stats">
              <div class="ibs-item"><i class="fa fa-database" style="color:#00897b;"></i> Total: <span class="ibs-val teal"><?= number_format($st_total) ?></span></div>
              <span class="ibs-sep">|</span>
              <div class="ibs-item"><i class="fa fa-check-circle" style="color:#00a65a;"></i> Terkirim: <span class="ibs-val green" id="ibsTerkirim"><?= number_format($st_terkirim) ?></span></div>
              <span class="ibs-sep">|</span>
              <div class="ibs-item"><i class="fa fa-clock-o" style="color:#dd4b39;"></i> Pending: <span class="ibs-val red" id="ibsPending"><?= number_format($st_pending) ?></span></div>
              <span class="ibs-sep">|</span>
              <div class="ibs-item"><i class="fa fa-ambulance" style="color:#31708f;"></i> Ralan: <span class="ibs-val blue"><?= number_format($st_ralan) ?></span></div>
              <span class="ibs-sep">|</span>
              <div class="ibs-item"><i class="fa fa-bed" style="color:#8a6d3b;"></i> Ranap: <span class="ibs-val orange"><?= number_format($st_ranap) ?></span></div>
              <div class="ibs-prog">
                <div style="font-size:10px;color:#aaa;"><?= $pct ?>% terkirim</div>
                <div class="ibs-prog-bar"><div class="ibs-prog-fill" style="width:<?= $pct ?>%;"></div></div>
              </div>
            </div>
            <div class="prog-strip"><div class="prog-fill" style="width:<?= $pct ?>%;"></div></div>

            <div class="box-body" style="padding:0;">
              <?php if (!empty($data)): ?>
              <div class="table-responsive">
                <table class="table table-bordered table-striped table-hover tbl-enc" style="margin-bottom:0;">
                  <thead>
                    <tr>
                      <th width="40"  class="text-center">#</th>
                      <th width="46"  class="text-center">Kirim</th>
                      <th width="165">No. Rawat</th>
                      <th width="190">Pasien</th>
                      <th width="155">Dokter / Poli</th>
                      <th width="130">Cara Bayar</th>
                      <th width="100" class="text-center">Jenis</th>
                      <th width="90"  class="text-center">Daftar</th>
                      <th width="110">Waktu Registrasi</th>
                      <th width="110" class="text-center">Status Bayar</th>
                      <th>ID Encounter</th>
                      <th width="120" class="text-center">Status Kirim</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php
                    $no = $offset + 1;
                    foreach ($data as $r):
                        $isSent  = !empty($r['id_encounter']);
                        $shortId = $isSent ? mb_strimwidth($r['id_encounter'], 0, 36, '…') : '';
                        $umur    = '';
                        if (!empty($r['tgl_lahir'])) {
                            $tglLahir = new DateTime($r['tgl_lahir']);
                            $age      = (new DateTime())->diff($tglLahir);
                            $umur     = $r['umurdaftar'] . ' ' . ($r['sttsumur'] ?? 'Th');
                        }
                    ?>
                    <tr class="<?= $isSent ? 'row-sent' : '' ?>">

                      <td class="text-center" style="color:#aaa;font-size:12px;font-weight:600;"><?= $no++ ?></td>

                      <!-- Kirim -->
                      <td class="text-center">
                        <button class="btn-send <?= $isSent?'sent':'' ?>"
                                onclick="kirimSatu('<?= addslashes($r['no_rawat']) ?>',this)"
                                title="<?= $isSent?'Kirim Ulang':'Kirim ke Satu Sehat' ?>">
                          <i class="fa fa-<?= $isSent?'refresh':'send' ?>"></i>
                        </button>
                      </td>

                      <!-- No. Rawat + No. Reg -->
                      <td>
                        <div class="norawat-lbl"><?= htmlspecialchars($r['no_rawat']) ?></div>
                        <div class="noreg-lbl">No.Reg: <?= htmlspecialchars($r['no_reg'] ?? '-') ?></div>
                      </td>

                      <!-- Pasien -->
                      <td>
                        <div class="nm-pasien"><?= htmlspecialchars($r['nm_pasien']) ?></div>
                        <div class="rm-lbl">
                          <?= htmlspecialchars($r['no_rkm_medis']) ?>
                          <?= $umur ? ' &middot; '.$umur : '' ?>
                          <?= $r['jk'] ? ' &middot; '.htmlspecialchars($r['jk']) : '' ?>
                        </div>
                      </td>

                      <!-- Dokter / Poli -->
                      <td>
                        <div style="font-size:12.5px;font-weight:600;color:#444;"><?= htmlspecialchars($r['nm_dokter'] ?: '-') ?></div>
                        <div class="rm-lbl"><?= htmlspecialchars($r['nm_poli'] ?: '-') ?></div>
                      </td>

                      <!-- Cara Bayar -->
                      <td>
                        <span class="kd-lbl"><?= htmlspecialchars($r['kd_pj']) ?></span>
                        <div class="rm-lbl" style="margin-top:3px;"><?= htmlspecialchars($r['png_jawab'] ?: '-') ?></div>
                      </td>

                      <!-- Jenis Rawat -->
                      <td class="text-center">
                        <span class="badge-<?= strtolower($r['status_lanjut']==='Ranap'?'ranap':'ralan') ?>">
                          <?= htmlspecialchars($r['status_lanjut']) ?>
                        </span>
                      </td>

                      <!-- Status Daftar (Baru/Lama) -->
                      <td class="text-center">
                        <span class="badge-<?= $r['stts_daftar']==='Baru'?'baru':'lama' ?>">
                          <?= htmlspecialchars($r['stts_daftar']) ?>
                        </span>
                      </td>

                      <!-- Waktu Registrasi -->
                      <td>
                        <div class="waktu-lbl"><?= date('H:i', strtotime($r['jam_reg'])) ?> WIB</div>
                        <div class="waktu-sub"><?= date('d/m/Y', strtotime($r['tgl_registrasi'])) ?></div>
                      </td>

                      <!-- Status Bayar -->
                      <td class="text-center">
                        <?php if ($r['status_bayar'] === 'Sudah Bayar'): ?>
                          <span class="badge-bayar"><i class="fa fa-check"></i> Sudah Bayar</span>
                        <?php else: ?>
                          <span class="badge-blm-bayar"><i class="fa fa-clock-o"></i> Belum Bayar</span>
                        <?php endif; ?>
                      </td>

                      <!-- ID Encounter -->
                      <td>
                        <?php if ($isSent): ?>
                          <span class="id-cell" title="<?= htmlspecialchars($r['id_encounter']) ?>"><?= htmlspecialchars($shortId) ?></span>
                          <button class="btn-copy" onclick="copyId('<?= addslashes($r['id_encounter']) ?>')" title="Salin ID"><i class="fa fa-copy"></i></button>
                        <?php else: ?>
                          <span class="no-id"><i class="fa fa-minus-circle"></i> Belum dikirim</span>
                        <?php endif; ?>
                      </td>

                      <!-- Status Kirim -->
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

              <!-- Pagination -->
              <?php if ($total_pages > 1): ?>
              <div class="box-footer clearfix">
                <div class="pull-left" style="font-size:13px;color:#666;padding:7px 0;">
                  Menampilkan <strong><?= number_format($offset+1) ?></strong>–<strong><?= number_format(min($offset+$limit,$total)) ?></strong>
                  dari <strong><?= number_format($total) ?></strong> data
                </div>
                <ul class="pagination pagination-sm no-margin pull-right">
                  <?php if ($page>1): ?>
                  <li><a href="?page=<?=$page-1?>&tanggal=<?=urlencode($filter_tanggal)?>&cari=<?=urlencode($cari)?>&status=<?=urlencode($filter_status)?>&lanjut=<?=urlencode($filter_lanjut)?>&limit=<?=$limit?>">«</a></li>
                  <?php endif; ?>
                  <?php for($i=max(1,$page-3);$i<=min($total_pages,$page+3);$i++): ?>
                  <li <?=$i==$page?'class="active"':''?>><a href="?page=<?=$i?>&tanggal=<?=urlencode($filter_tanggal)?>&cari=<?=urlencode($cari)?>&status=<?=urlencode($filter_status)?>&lanjut=<?=urlencode($filter_lanjut)?>&limit=<?=$limit?>"><?=$i?></a></li>
                  <?php endfor; ?>
                  <?php if ($page<$total_pages): ?>
                  <li><a href="?page=<?=$page+1?>&tanggal=<?=urlencode($filter_tanggal)?>&cari=<?=urlencode($cari)?>&status=<?=urlencode($filter_status)?>&lanjut=<?=urlencode($filter_lanjut)?>&limit=<?=$limit?>">»</a></li>
                  <?php endif; ?>
                </ul>
              </div>
              <?php endif; ?>

              <?php else: ?>
              <div style="padding:50px;text-align:center;">
                <i class="fa fa-inbox" style="font-size:52px;color:#ddd;display:block;margin-bottom:14px;"></i>
                <h4 style="color:#aaa;font-weight:400;margin-bottom:10px;">
                  <?= ($cari||$filter_status||$filter_lanjut)
                      ? "Tidak ada data untuk filter yang dipilih"
                      : "Tidak ada Encounter pada <strong>".date('d F Y',strtotime($filter_tanggal))."</strong>" ?>
                </h4>
                <?php if ($cari||$filter_status||$filter_lanjut): ?>
                <a href="?tanggal=<?= urlencode($filter_tanggal) ?>" class="btn btn-default btn-sm">
                  <i class="fa fa-refresh"></i> Reset Filter
                </a>
                <?php endif; ?>
              </div>
              <?php endif; ?>

            </div><!-- /box-body -->
          </div><!-- /box -->

        </div>
      </div>
    </section>
  </div>

<?php include 'includes/footer.php'; ?>