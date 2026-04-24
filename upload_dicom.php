<?php
session_start();
include 'koneksi.php';
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/config/env.php';
if (isset($pdo)) loadSatuSehatConfig($pdo);
elseif (isset($pdo_simrs)) loadSatuSehatConfig($pdo_simrs);

// ── AJAX POST ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'upload_dicom') {
        include __DIR__ . '/api/upload_dicom.php';
        exit;
    }
}

// ── GET: riwayat upload ───────────────────────────────────────────
if (isset($_GET['get_history'])) {
    header('Content-Type: application/json');
    $noorder = trim($_GET['noorder'] ?? '');
    try {
        $sql = "
            SELECT du.id, du.noorder, du.filename_ori, du.instance_uid,
                   du.status, du.error_msg, du.uploaded_by,
                   DATE_FORMAT(du.created_at,'%d/%m %H:%i') AS created_at
            FROM medifix_dicom_uploads du
            WHERE 1=1 " . ($noorder ? "AND du.noorder = ?" : "AND DATE(du.created_at) = CURDATE()") . "
            ORDER BY du.created_at DESC LIMIT 100";
        $stmt = $pdo_simrs->prepare($sql);
        $stmt->execute($noorder ? [$noorder] : []);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) { echo json_encode([]); }
    exit;
}

// ── Auto-create tabel ─────────────────────────────────────────────
try {
    $pdo_simrs->exec("
        CREATE TABLE IF NOT EXISTS medifix_dicom_uploads (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            noorder       VARCHAR(15) NOT NULL,
            kd_jenis_prw  VARCHAR(15),
            no_rawat      VARCHAR(25),
            filename_ori  VARCHAR(255),
            instance_uid  VARCHAR(200),
            status        ENUM('pending','terkirim','error') DEFAULT 'pending',
            error_msg     VARCHAR(500),
            uploaded_by   VARCHAR(100),
            created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_noorder (noorder),
            KEY idx_status  (status),
            KEY idx_date    (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {}

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

// ── Ambil Data ────────────────────────────────────────────────────
try {
    $wheres = ["pr.tgl_permintaan BETWEEN ? AND ?"];
    $params = [$tgl_dari, $tgl_sampai];

    if (!empty($cari)) {
        $wheres[] = "(pr.noorder LIKE ? OR p.nm_pasien LIKE ? OR p.no_rkm_medis LIKE ? OR d.nm_dokter LIKE ?)";
        $params = array_merge($params, array_fill(0, 4, "%$cari%"));
    }
    if ($filter_status === 'uploaded') {
        $wheres[] = "jml_gambar > 0";
    } elseif ($filter_status === 'belum') {
        $wheres[] = "jml_gambar = 0";
    }

    $where_sql = "WHERE " . implode(" AND ", $wheres);

    $base_query = "
        FROM permintaan_radiologi pr
        JOIN reg_periksa r              ON pr.no_rawat     = r.no_rawat
        JOIN pasien p                   ON r.no_rkm_medis  = p.no_rkm_medis
        LEFT JOIN dokter d              ON pr.dokter_perujuk = d.kd_dokter
        LEFT JOIN poliklinik pl         ON r.kd_poli        = pl.kd_poli
        LEFT JOIN medifix_ss_radiologi m ON pr.noorder      = m.noorder
        LEFT JOIN medifix_ss_pasien msp ON p.no_rkm_medis  = msp.no_rkm_medis
        LEFT JOIN (
            SELECT noorder,
                   COUNT(*) AS jml_gambar,
                   SUM(CASE WHEN status='terkirim' THEN 1 ELSE 0 END) AS jml_ok,
                   MAX(created_at) AS last_upload
            FROM medifix_dicom_uploads
            GROUP BY noorder
        ) du ON pr.noorder = du.noorder
    ";

    $stmtCount = $pdo_simrs->prepare("SELECT COUNT(*) $base_query $where_sql");
    $stmtCount->execute($params);
    $total       = (int)$stmtCount->fetchColumn();
    $total_pages = max(1, ceil($total / $limit));

    $stmtData = $pdo_simrs->prepare("
        SELECT
            pr.noorder, pr.no_rawat, pr.tgl_permintaan, pr.jam_permintaan,
            pr.tgl_hasil, pr.jam_hasil, pr.status AS status_rawat,
            pr.diagnosa_klinis,
            p.no_rkm_medis, p.nm_pasien, p.jk, p.tgl_lahir,
            d.nm_dokter, pl.nm_poli,
            m.kd_jenis_prw, m.id_servicerequest, m.id_imagingstudy,
            m.study_uid_dicom, m.status_kirim_sr, m.status_kirim_is,
            msp.ihs_number,
            COALESCE(du.jml_gambar, 0) AS jml_gambar,
            COALESCE(du.jml_ok, 0) AS jml_ok,
            du.last_upload
        $base_query $where_sql
        ORDER BY pr.tgl_permintaan DESC, pr.jam_permintaan DESC
        LIMIT " . intval($limit) . " OFFSET " . intval($offset)
    );
    $stmtData->execute($params);
    $data = $stmtData->fetchAll(PDO::FETCH_ASSOC);

    // Stats
    $stmtStats = $pdo_simrs->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN COALESCE(du.jml_ok,0) > 0 THEN 1 ELSE 0 END) AS sudah_upload,
            SUM(CASE WHEN COALESCE(du.jml_ok,0) = 0 THEN 1 ELSE 0 END) AS belum_upload,
            SUM(COALESCE(du.jml_ok,0)) AS total_gambar
        FROM permintaan_radiologi pr
        LEFT JOIN (
            SELECT noorder, SUM(CASE WHEN status='terkirim' THEN 1 ELSE 0 END) AS jml_ok
            FROM medifix_dicom_uploads GROUP BY noorder
        ) du ON pr.noorder = du.noorder
        WHERE pr.tgl_permintaan BETWEEN ? AND ?
    ");
    $stmtStats->execute([$tgl_dari, $tgl_sampai]);
    $stats = $stmtStats->fetch(PDO::FETCH_ASSOC);
    $st_total   = (int)($stats['total']        ?? 0);
    $st_upload  = (int)($stats['sudah_upload'] ?? 0);
    $st_belum   = (int)($stats['belum_upload'] ?? 0);
    $st_gambar  = (int)($stats['total_gambar'] ?? 0);
    $dbError    = null;

} catch (Exception $e) {
    $data = []; $total = 0; $total_pages = 1;
    $st_total = $st_upload = $st_belum = $st_gambar = 0;
    $dbError = $e->getMessage();
}

$pct = $st_total > 0 ? round(($st_upload / $st_total) * 100) : 0;
$tgl_dari_fmt   = date('d F Y', strtotime($tgl_dari));
$tgl_sampai_fmt = date('d F Y', strtotime($tgl_sampai));
$periode_label  = $tgl_dari === $tgl_sampai ? $tgl_dari_fmt : "$tgl_dari_fmt s/d $tgl_sampai_fmt";
$page_title     = 'Upload Gambar DICOM — Radiologi';

$extra_css = '
.tbl-dicom thead tr th{background:#00a65a;color:#fff!important;white-space:nowrap;font-size:12px}
.tbl-dicom tbody td{vertical-align:middle}

.badge-sr-ok{background:#dff0d8;color:#3c763d;border:1px solid #c3e6cb;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700}
.badge-sr-pend{background:#fcf8e3;color:#8a6d3b;border:1px solid #faebcc;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700}
.badge-gambar-ok{background:#d9edf7;color:#31708f;border:1px solid #bce8f1;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700}
.badge-gambar-none{background:#f5f5f5;color:#999;border:1px solid #e5e5e5;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700}
.badge-ralan{background:#d9edf7;color:#31708f;border:1px solid #bce8f1;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700}
.badge-ranap{background:#fcf8e3;color:#8a6d3b;border:1px solid #faebcc;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700}

.btn-upload{width:32px;height:32px;border-radius:6px;padding:0;display:inline-flex;align-items:center;justify-content:center;border:none;cursor:pointer;transition:all .25s;font-size:13px;background:#00a65a;color:#fff}
.btn-upload:hover{background:#008d4c;transform:scale(1.12);box-shadow:0 3px 10px rgba(0,166,90,.4)}
.btn-upload.has-gambar{background:#0073b7}
.btn-upload.has-gambar:hover{background:#005b99}

.noorder-lbl{font-weight:700;color:#00a65a;font-size:13px;font-family:"Courier New",monospace}
.rm-lbl{font-size:10.5px;color:#aaa;font-family:"Courier New",monospace}
.nm-pasien{font-weight:700;color:#333;font-size:13px}
.waktu-lbl{font-size:13px;font-weight:600;color:#333}
.waktu-sub{font-size:10.5px;color:#aaa;margin-top:2px}

.info-bar-stats{display:flex;gap:16px;padding:8px 15px;background:#f0fff4;border-bottom:1px solid #c3e6cb;font-size:12px;flex-wrap:wrap;align-items:center}
.ibs-item{display:flex;align-items:center;gap:5px;color:#555}
.ibs-val{font-weight:700;font-size:14px}
.ibs-sep{color:#ddd}

.prog-row{display:flex;align-items:center;gap:10px;font-size:12px;padding:10px 15px 8px;background:#f9f9f9;border-bottom:1px solid #e5e5e5}
.prog-label{width:140px;color:#555;flex-shrink:0}
.prog-bar{flex:1;height:7px;border-radius:4px;background:#e5e5e5;overflow:hidden}
.prog-fill{height:100%;border-radius:4px;background:linear-gradient(90deg,#00a65a,#0073b7);transition:width .6s}
.prog-pct{width:38px;text-align:right;font-weight:700;color:#555;flex-shrink:0}

.date-range-wrap{display:flex;align-items:center;gap:6px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;padding:5px 10px}
.date-range-wrap label{font-size:12px;color:#666;margin:0;white-space:nowrap}
.date-range-wrap input{border:none;background:transparent;font-size:13px;color:#333;padding:0;outline:none;width:130px}
.btn-period{padding:3px 10px;border-radius:4px;font-size:11px;border:1px solid #dee2e6;background:#fff;color:#555;cursor:pointer;transition:all .2s}
.btn-period:hover{background:#00a65a;color:#fff;border-color:#00a65a}

/* Modal Upload */
.modal-upload-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9998;display:flex;align-items:center;justify-content:center;padding:16px}
.modal-upload{background:#fff;border-radius:10px;width:700px;max-width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,.2)}
.modal-upload-header{background:#00a65a;color:#fff;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:1}
.modal-upload-header h4{margin:0;font-size:15px;font-weight:700}
.modal-close{background:none;border:none;color:#fff;font-size:22px;cursor:pointer;line-height:1;padding:0}
.modal-upload-body{padding:18px}

.drop-zone{border:2.5px dashed #bbb;border-radius:10px;padding:36px 20px;text-align:center;cursor:pointer;transition:all .25s;background:#fafafa}
.drop-zone:hover,.drop-zone.dragover{border-color:#00a65a;background:#f0fff4}
.drop-zone.has-files{border-color:#0073b7;background:#e8f4fd}
.drop-zone i{font-size:36px;color:#ccc;display:block;margin-bottom:10px}

.preview-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px;margin-top:14px}
.preview-item{position:relative;border-radius:8px;overflow:hidden;border:1px solid #e5e5e5;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.08)}
.preview-item img{width:100%;height:110px;object-fit:cover;display:block}
.pi-name{font-size:10px;padding:4px 6px;color:#555;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;background:#fff}
.pi-remove{position:absolute;top:4px;right:4px;width:20px;height:20px;background:rgba(0,0,0,.5);border:none;border-radius:50%;color:#fff;cursor:pointer;font-size:11px;display:flex;align-items:center;justify-content:center;line-height:1}
.pi-status{position:absolute;bottom:24px;left:0;right:0;padding:3px 6px;font-size:10px;font-weight:700;text-align:center}
.pi-status.uploading{background:rgba(0,166,90,.85);color:#fff}
.pi-status.ok{background:rgba(0,115,183,.9);color:#fff}
.pi-status.err{background:rgba(221,75,57,.9);color:#fff}

.history-list{margin-top:14px}
.history-item{display:flex;align-items:center;gap:8px;padding:7px 10px;border-radius:6px;border:1px solid #f0f0f0;margin-bottom:5px;background:#fafafa}
.history-info{flex:1;min-width:0}
.history-name{font-size:12px;font-weight:600;color:#333;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.history-sub{font-size:10px;color:#aaa}

#toast-container{position:fixed;bottom:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.toast-msg{padding:10px 18px;border-radius:6px;font-size:13px;font-weight:600;color:#fff;display:flex;align-items:center;gap:8px;box-shadow:0 4px 12px rgba(0,0,0,.2);pointer-events:auto;animation:toastIn .3s ease}
.toast-success{background:#00a65a}.toast-error{background:#dd4b39}.toast-warn{background:#f39c12}.toast-info{background:#00c0ef}
@keyframes toastIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
';

$extra_js = <<<'ENDJS'
function setPeriode(jenis) {
    const now = new Date(), iso = d => d.toISOString().split("T")[0];
    let dari, sampai = iso(now);
    if (jenis==="hari")       { dari = sampai; }
    else if (jenis==="minggu"){ const d=new Date(now);d.setDate(d.getDate()-6);dari=iso(d); }
    else if (jenis==="bulan") { dari=iso(new Date(now.getFullYear(),now.getMonth(),1)); }
    else if (jenis==="bulan_lalu"){
        dari  = iso(new Date(now.getFullYear(),now.getMonth()-1,1));
        sampai= iso(new Date(now.getFullYear(),now.getMonth(),0));
    }
    document.getElementById("tgl_dari").value=dari;
    document.getElementById("tgl_sampai").value=sampai;
    document.querySelector("form").submit();
}

function showToast(msg, type) {
    const icons={success:"check-circle",error:"times-circle",warn:"exclamation-triangle",info:"info-circle"};
    const d=document.createElement("div");
    d.className="toast-msg toast-"+(type||"success");
    d.innerHTML=`<i class="fa fa-${icons[type]||"info-circle"}"></i> ${msg}`;
    document.getElementById("toast-container").appendChild(d);
    setTimeout(()=>d.remove(), 4500);
}

// ── State modal ───────────────────────────────────────────────────
let currentOrder = null;
let fileList     = [];

function bukaModal(orderJson) {
    currentOrder = JSON.parse(orderJson);
    fileList = [];

    // Render modal
    const hasIS = currentOrder.status_kirim_is === 'terkirim';
    const hasSR = currentOrder.status_kirim_sr === 'terkirim';
    const jml   = parseInt(currentOrder.jml_ok) || 0;

    const html = `
    <div class="modal-upload-overlay" id="modalOverlay" onclick="if(event.target===this)tutupModal()">
      <div class="modal-upload">
        <div class="modal-upload-header">
          <h4><i class="fa fa-cloud-upload"></i> Upload Gambar DICOM &mdash; ${currentOrder.noorder}</h4>
          <button class="modal-close" onclick="tutupModal()">&times;</button>
        </div>
        <div class="modal-upload-body">

          <!-- Info Pasien -->
          <div style="background:#f8f9ff;border:1px solid #e0e0f8;border-radius:8px;padding:12px 16px;margin-bottom:16px;display:flex;gap:16px;flex-wrap:wrap;align-items:center">
            <div>
              <div style="font-weight:700;font-size:14px;color:#333">${currentOrder.nm_pasien}</div>
              <div style="font-size:11px;color:#aaa;font-family:'Courier New',monospace">${currentOrder.no_rkm_medis} · ${currentOrder.noorder}</div>
              <div style="font-size:11px;color:#777;margin-top:2px">${currentOrder.nm_dokter||'-'} · ${currentOrder.nm_poli||'-'} · ${currentOrder.tgl_permintaan}</div>
            </div>
            <div style="margin-left:auto;display:flex;gap:6px;flex-wrap:wrap">
              ${hasSR ? '<span style="background:#dff0d8;color:#3c763d;border:1px solid #c3e6cb;padding:3px 8px;border-radius:8px;font-size:11px;font-weight:700"><i class="fa fa-check-circle"></i> SR OK</span>' : '<span style="background:#fcf8e3;color:#8a6d3b;border:1px solid #faebcc;padding:3px 8px;border-radius:8px;font-size:11px;font-weight:700"><i class="fa fa-clock-o"></i> SR Pending</span>'}
              ${hasIS ? '<span style="background:#d9edf7;color:#31708f;border:1px solid #bce8f1;padding:3px 8px;border-radius:8px;font-size:11px;font-weight:700"><i class="fa fa-image"></i> IS OK</span>' : '<span style="background:#f5f5f5;color:#999;border:1px solid #e5e5e5;padding:3px 8px;border-radius:8px;font-size:11px;font-weight:700"><i class="fa fa-clock-o"></i> IS Pending</span>'}
              ${currentOrder.ihs_number ? '<span style="background:#e8f8fd;color:#0073b7;border:1px solid #bce8f1;padding:3px 8px;border-radius:8px;font-size:11px;font-weight:700"><i class="fa fa-id-card"></i> IHS OK</span>' : '<span style="background:#fff5f5;color:#dd4b39;border:1px solid #f5c6cb;padding:3px 8px;border-radius:8px;font-size:11px;font-weight:700"><i class="fa fa-exclamation-triangle"></i> No IHS</span>'}
            </div>
          </div>

          <!-- Drop Zone -->
          <div class="drop-zone" id="dropZone" onclick="document.getElementById('fileInput').click()">
            <i class="fa fa-cloud-upload"></i>
            <p style="font-size:14px;margin:0"><strong>Klik untuk pilih gambar</strong> atau seret ke sini</p>
            <p style="font-size:12px;color:#aaa;margin-top:6px">JPG, PNG, BMP · Bisa pilih banyak sekaligus</p>
          </div>
          <input type="file" id="fileInput" accept="image/jpeg,image/png,image/bmp" multiple style="display:none">

          <div class="preview-grid" id="previewGrid"></div>

          <!-- Footer aksi -->
          <div id="uploadFooter" style="display:none;margin-top:16px;padding-top:14px;border-top:1px solid #f0f0f0;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <button id="btnKirim"
              style="background:linear-gradient(135deg,#00a65a,#008d4c);border:none;color:#fff;padding:9px 22px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:7px"
              onclick="uploadSemua()">
              <i class="fa fa-send"></i> Kirim Semua ke Satu Sehat
            </button>
            <span id="uploadProgress" style="font-size:13px;color:#888"></span>
          </div>

          <!-- Riwayat gambar order ini -->
          <div id="historySection" style="margin-top:18px">
            <div style="font-size:12px;font-weight:700;color:#555;margin-bottom:8px;display:flex;align-items:center;gap:6px">
              <i class="fa fa-history"></i> Gambar sudah terkirim untuk order ini
            </div>
            <div id="historyList" class="history-list">
              <div style="color:#aaa;font-size:12px;font-style:italic">Memuat...</div>
            </div>
          </div>

        </div>
      </div>
    </div>`;

    document.body.insertAdjacentHTML('beforeend', html);

    // Event drop zone
    const dz = document.getElementById('dropZone');
    dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('dragover'); });
    dz.addEventListener('dragleave', () => dz.classList.remove('dragover'));
    dz.addEventListener('drop', e => { e.preventDefault(); dz.classList.remove('dragover'); tambahFile([...e.dataTransfer.files]); });
    document.getElementById('fileInput').addEventListener('change', e => tambahFile([...e.target.files]));

    // Load riwayat
    loadHistory(currentOrder.noorder);
}

function tutupModal() {
    const el = document.getElementById('modalOverlay');
    if (el) el.remove();
    fileList = [];
}

function tambahFile(files) {
    files.forEach(f => {
        if (!f.type.startsWith('image/')) return;
        if (fileList.find(x => x && x.name === f.name && x.size === f.size)) return;
        const idx = fileList.length;
        fileList.push(f);
        const reader = new FileReader();
        reader.onload = e => renderPreview(f, e.target.result, idx);
        reader.readAsDataURL(f);
    });
}

function renderPreview(file, src, idx) {
    const grid = document.getElementById('previewGrid');
    const div  = document.createElement('div');
    div.className = 'preview-item';
    div.id = 'prev-' + idx;
    div.innerHTML = `
        <img src="${src}" alt="">
        <div class="pi-name" title="${file.name}">${file.name}</div>
        <button class="pi-remove" onclick="hapusFile(${idx})">&times;</button>`;
    grid.appendChild(div);
    document.getElementById('dropZone')?.classList.add('has-files');
    updateFooter();
}

function hapusFile(idx) {
    fileList[idx] = null;
    document.getElementById('prev-' + idx)?.remove();
    updateFooter();
}

function updateFooter() {
    const aktif = fileList.filter(f => f !== null).length;
    const footer = document.getElementById('uploadFooter');
    if (footer) footer.style.display = aktif > 0 ? 'flex' : 'none';
}

async function uploadSemua() {
    if (!currentOrder) return;
    const aktif = fileList.filter(f => f !== null);
    if (!aktif.length) { showToast('Pilih gambar dulu', 'warn'); return; }

    const btn = document.getElementById('btnKirim');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Mengirim…';

    let ok = 0, fail = 0;
    for (let i = 0; i < fileList.length; i++) {
        const f = fileList[i]; if (!f) continue;
        const prev = document.getElementById('prev-' + i);

        // Tambah status overlay
        let st = prev?.querySelector('.pi-status');
        if (!st) { st = document.createElement('div'); prev?.appendChild(st); }
        st.className = 'pi-status uploading'; st.textContent = 'Mengirim…';
        document.getElementById('uploadProgress').textContent = `${ok+fail+1} / ${aktif.length}`;

        const fd = new FormData();
        fd.append('action',          'upload_dicom');
        fd.append('noorder',         currentOrder.noorder);
        fd.append('kd_jenis_prw',    currentOrder.kd_jenis_prw || '');
        fd.append('no_rawat',        currentOrder.no_rawat);
        fd.append('ihs_number',      currentOrder.ihs_number || '');
        fd.append('study_uid',       currentOrder.study_uid_dicom || '');
        fd.append('id_imagingstudy', currentOrder.id_imagingstudy || '');
        fd.append('gambar',          f, f.name);

        try {
            const r    = await fetch(window.location.href, { method:'POST', body: fd });
            const json = await r.json();
            if (json.status === 'ok') {
                ok++;
                st.className = 'pi-status ok';
                st.textContent = '✓ Terkirim';
                // Update study_uid di order object jika belum ada
                if (!currentOrder.study_uid_dicom && json.study_uid) {
                    currentOrder.study_uid_dicom = json.study_uid;
                }
            } else {
                fail++;
                st.className = 'pi-status err';
                st.textContent = '✗ Gagal';
                console.error(f.name, json.message);
            }
        } catch(e) {
            fail++;
            if (st) { st.className='pi-status err'; st.textContent='✗ Error'; }
        }
        await new Promise(r => setTimeout(r, 400));
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="fa fa-send"></i> Kirim Semua ke Satu Sehat';
    document.getElementById('uploadProgress').textContent = '';

    const type = fail===0 ? 'success' : ok===0 ? 'error' : 'warn';
    showToast(`Selesai: ${ok} berhasil, ${fail} gagal dari ${aktif.length} gambar`, type);

    if (ok > 0) {
        loadHistory(currentOrder.noorder);
        // Update badge di tabel utama
        const badge = document.querySelector(`[data-noorder="${currentOrder.noorder}"] .badge-gambar`);
        if (badge) {
            const newTotal = (parseInt(currentOrder.jml_ok)||0) + ok;
            badge.className = 'badge-gambar badge-gambar-ok';
            badge.innerHTML = `<i class="fa fa-image"></i> ${newTotal} gambar`;
        }
    }
}

function loadHistory(noorder) {
    const el = document.getElementById('historyList');
    if (!el) return;
    fetch(window.location.href + '?get_history=1&noorder=' + encodeURIComponent(noorder))
    .then(r => r.json())
    .then(data => {
        if (!data.length) {
            el.innerHTML = '<div style="color:#aaa;font-size:12px;font-style:italic">Belum ada gambar terkirim</div>';
            return;
        }
        el.innerHTML = data.map(d => `
            <div class="history-item">
                <i class="fa fa-file-image-o" style="color:#0073b7;font-size:18px;flex-shrink:0"></i>
                <div class="history-info">
                    <div class="history-name">${d.filename_ori || '-'}</div>
                    <div class="history-sub">${d.created_at} · ${d.uploaded_by||''}</div>
                </div>
                <span style="background:${d.status==='terkirim'?'#dff0d8':'#f2dede'};color:${d.status==='terkirim'?'#3c763d':'#a94442'};border:1px solid ${d.status==='terkirim'?'#c3e6cb':'#f5c6cb'};padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;white-space:nowrap">
                    ${d.status==='terkirim'?'✓ OK':'✗ Error'}
                </span>
            </div>`).join('');
    }).catch(() => { if(el) el.innerHTML = '<div style="color:#aaa;font-size:12px">Gagal memuat riwayat</div>'; });
}
ENDJS;

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div id="toast-container"></div>

<div class="content-wrapper">
  <section class="content-header">
    <h1>
      <i class="fa fa-cloud-upload" style="color:#00a65a;"></i>
      Upload Gambar DICOM
      <small>Radiologi Satu Sehat &mdash; <?= $periode_label ?></small>
    </h1>
    <ol class="breadcrumb">
      <li><a href="dashboard.php"><i class="fa fa-dashboard"></i> Home</a></li>
      <li>Satu Sehat</li>
      <li class="active">Upload Gambar DICOM</li>
    </ol>
  </section>

  <section class="content">

    <?php if ($dbError): ?>
    <div class="callout callout-danger"><h4><i class="fa fa-ban"></i> Error</h4><p><?= htmlspecialchars($dbError) ?></p></div>
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
                <label style="display:block;margin-bottom:4px;font-size:12px;"><i class="fa fa-calendar"></i> Periode Permintaan:</label>
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
                <input type="text" name="cari" class="form-control"
                       placeholder="Pasien / No. RM / No. Order / Dokter…"
                       value="<?= htmlspecialchars($cari) ?>" style="width:240px;">
              </div>
              <div class="form-group">
                <label style="margin-right:5px;">Gambar:</label>
                <select name="status" class="form-control">
                  <option value=""        <?= $filter_status===''       ?'selected':'' ?>>Semua</option>
                  <option value="uploaded"<?= $filter_status==='uploaded'?'selected':'' ?>>Sudah Upload</option>
                  <option value="belum"   <?= $filter_status==='belum'   ?'selected':'' ?>>Belum Upload</option>
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
              <a href="upload_dicom.php" class="btn btn-default"><i class="fa fa-refresh"></i> Reset</a>
            </form>
          </div>
        </div>

        <!-- Tabel -->
        <div class="box box-success">
          <div class="box-header with-border">
            <h3 class="box-title">
              <i class="fa fa-table"></i> Data Permintaan Radiologi
              <span class="badge" style="background:#00a65a;"><?= number_format($total) ?></span>
            </h3>
            <div class="box-tools pull-right">
              <a href="?tgl_dari=<?= urlencode($tgl_dari) ?>&tgl_sampai=<?= urlencode($tgl_sampai) ?>&cari=<?= urlencode($cari) ?>&status=<?= urlencode($filter_status) ?>&limit=<?= $limit ?>"
                 class="btn btn-default btn-sm" title="Refresh"><i class="fa fa-refresh"></i></a>
            </div>
          </div>

          <!-- Stats -->
          <div class="info-bar-stats">
            <div class="ibs-item"><i class="fa fa-list" style="color:#00a65a;"></i> Total: <span class="ibs-val" style="color:#00a65a;"><?= number_format($st_total) ?></span></div>
            <span class="ibs-sep">|</span>
            <div class="ibs-item"><i class="fa fa-check-circle" style="color:#0073b7;"></i> Sudah upload: <span class="ibs-val" style="color:#0073b7;"><?= number_format($st_upload) ?></span></div>
            <div class="ibs-item"><i class="fa fa-clock-o" style="color:#f39c12;"></i> Belum upload: <span class="ibs-val" style="color:#f39c12;"><?= number_format($st_belum) ?></span></div>
            <span class="ibs-sep">|</span>
            <div class="ibs-item"><i class="fa fa-image" style="color:#605ca8;"></i> Total gambar terkirim: <span class="ibs-val" style="color:#605ca8;"><?= number_format($st_gambar) ?></span></div>
          </div>

          <!-- Progress -->
          <div class="prog-row">
            <span class="prog-label"><i class="fa fa-cloud-upload"></i> Progress upload</span>
            <div class="prog-bar"><div class="prog-fill" style="width:<?= $pct ?>%;"></div></div>
            <span class="prog-pct"><?= $pct ?>%</span>
          </div>

          <div class="box-body" style="padding:0;">
            <?php if (!empty($data)): ?>
            <div class="table-responsive">
              <table class="table table-bordered table-striped table-hover tbl-dicom" style="margin-bottom:0;font-size:12.5px;">
                <thead>
                  <tr>
                    <th width="36"  class="text-center">#</th>
                    <th width="44"  class="text-center">Upload</th>
                    <th width="150">No. Order</th>
                    <th width="180">Pasien</th>
                    <th width="150">Dokter / Poli</th>
                    <th>Diagnosa</th>
                    <th width="105">Tgl Permintaan</th>
                    <th width="65"  class="text-center">Rawat</th>
                    <th width="130" class="text-center">Status SR / IS</th>
                    <th width="130" class="text-center">Gambar</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $no = $offset + 1;
                  foreach ($data as $r):
                      $srOk  = ($r['status_kirim_sr'] ?? '') === 'terkirim';
                      $isOk  = ($r['status_kirim_is'] ?? '') === 'terkirim';
                      $jmlOk = (int)($r['jml_ok'] ?? 0);
                      $umur  = '';
                      if (!empty($r['tgl_lahir'])) {
                          try { $umur = (new DateTime())->diff(new DateTime($r['tgl_lahir']))->y . ' th'; } catch(Exception $e){}
                      }
                      // Siapkan data untuk JS
                      $orderData = json_encode([
                          'noorder'         => $r['noorder'],
                          'kd_jenis_prw'    => $r['kd_jenis_prw'] ?? '',
                          'no_rawat'        => $r['no_rawat'],
                          'nm_pasien'       => $r['nm_pasien'],
                          'no_rkm_medis'    => $r['no_rkm_medis'],
                          'nm_dokter'       => $r['nm_dokter'] ?? '',
                          'nm_poli'         => $r['nm_poli'] ?? '',
                          'tgl_permintaan'  => $r['tgl_permintaan'],
                          'ihs_number'      => $r['ihs_number'] ?? '',
                          'study_uid_dicom' => $r['study_uid_dicom'] ?? '',
                          'id_imagingstudy' => $r['id_imagingstudy'] ?? '',
                          'status_kirim_sr' => $r['status_kirim_sr'] ?? '',
                          'status_kirim_is' => $r['status_kirim_is'] ?? '',
                          'jml_ok'          => $jmlOk,
                      ]);
                  ?>
                  <tr data-noorder="<?= htmlspecialchars($r['noorder']) ?>">

                    <td class="text-center" style="color:#aaa;font-size:11px;font-weight:600;"><?= $no++ ?></td>

                    <td class="text-center">
                      <button class="btn-upload <?= $jmlOk > 0 ? 'has-gambar' : '' ?>"
                              onclick="bukaModal(<?= htmlspecialchars(json_encode($orderData), ENT_QUOTES) ?>)"
                              title="<?= $jmlOk > 0 ? "Tambah/lihat gambar ($jmlOk sudah ada)" : 'Upload gambar DICOM' ?>">
                        <i class="fa fa-<?= $jmlOk > 0 ? 'image' : 'upload' ?>"></i>
                      </button>
                    </td>

                    <td>
                      <div class="noorder-lbl"><?= htmlspecialchars($r['noorder']) ?></div>
                      <div class="rm-lbl"><?= htmlspecialchars($r['no_rawat']) ?></div>
                      <?php if (!empty($r['kd_jenis_prw'])): ?>
                      <div style="margin-top:3px;">
                        <span style="background:#f0fff4;border:1px solid #c3e6cb;color:#3c763d;padding:1px 6px;border-radius:4px;font-size:10px;font-family:'Courier New',monospace;font-weight:700;">
                          <?= htmlspecialchars($r['kd_jenis_prw']) ?>
                        </span>
                      </div>
                      <?php endif; ?>
                    </td>

                    <td>
                      <div class="nm-pasien"><?= htmlspecialchars($r['nm_pasien']) ?></div>
                      <div class="rm-lbl">
                        <?= htmlspecialchars($r['no_rkm_medis']) ?>
                        <?= $umur ? " · $umur" : '' ?>
                        <?= !empty($r['jk']) ? ' · '.htmlspecialchars($r['jk']) : '' ?>
                      </div>
                      <?php if (!empty($r['ihs_number'])): ?>
                      <div style="font-size:10px;color:#00a65a;margin-top:2px;font-weight:700;">
                        <i class="fa fa-id-card"></i> IHS OK
                      </div>
                      <?php else: ?>
                      <div style="font-size:10px;color:#dd4b39;margin-top:2px;">
                        <i class="fa fa-exclamation-triangle"></i> No IHS
                      </div>
                      <?php endif; ?>
                    </td>

                    <td>
                      <div style="font-size:12px;font-weight:600;color:#444;"><?= htmlspecialchars($r['nm_dokter'] ?: '-') ?></div>
                      <div class="rm-lbl"><?= htmlspecialchars($r['nm_poli'] ?: '-') ?></div>
                    </td>

                    <td>
                      <div style="font-size:12px;color:#555;line-height:1.4;"><?= htmlspecialchars($r['diagnosa_klinis'] ?: '-') ?></div>
                    </td>

                    <td>
                      <div class="waktu-lbl"><?= date('H:i', strtotime($r['jam_permintaan'])) ?> WIB</div>
                      <div class="waktu-sub"><?= date('d/m/Y', strtotime($r['tgl_permintaan'])) ?></div>
                      <?php if (!empty($r['tgl_hasil']) && $r['tgl_hasil'] !== '0000-00-00'): ?>
                      <div style="font-size:10.5px;color:#00a65a;margin-top:2px;">
                        <i class="fa fa-check"></i> Hasil: <?= date('d/m H:i', strtotime($r['tgl_hasil'].' '.$r['jam_hasil'])) ?>
                      </div>
                      <?php endif; ?>
                    </td>

                    <td class="text-center">
                      <span class="badge-<?= strtolower($r['status_rawat']??'')==='ranap'?'ranap':'ralan' ?>">
                        <?= strtoupper($r['status_rawat'] ?? '-') ?>
                      </span>
                    </td>

                    <td class="text-center">
                      <div style="display:flex;flex-direction:column;gap:3px;align-items:center">
                        <span class="badge-sr-<?= $srOk?'ok':'pend' ?>">
                          <i class="fa fa-<?= $srOk?'check-circle':'clock-o' ?>"></i> SR <?= $srOk?'OK':'Pending' ?>
                        </span>
                        <span class="badge-sr-<?= $isOk?'ok':'pend' ?>" style="<?= $isOk?'background:#d9edf7;color:#31708f;border-color:#bce8f1':'' ?>">
                          <i class="fa fa-<?= $isOk?'image':'clock-o' ?>"></i> IS <?= $isOk?'OK':'Pending' ?>
                        </span>
                      </div>
                    </td>

                    <td class="text-center">
                      <?php if ($jmlOk > 0): ?>
                        <span class="badge-gambar badge-gambar-ok">
                          <i class="fa fa-image"></i> <?= $jmlOk ?> gambar
                        </span>
                        <?php if (!empty($r['last_upload'])): ?>
                        <div style="font-size:10px;color:#aaa;margin-top:3px;">
                          <?= date('d/m H:i', strtotime($r['last_upload'])) ?>
                        </div>
                        <?php endif; ?>
                      <?php else: ?>
                        <span class="badge-gambar badge-gambar-none">
                          <i class="fa fa-minus-circle"></i> Belum ada
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
                $qBase = "tgl_dari=".urlencode($tgl_dari)."&tgl_sampai=".urlencode($tgl_sampai)."&cari=".urlencode($cari)."&status=".urlencode($filter_status)."&limit=$limit";
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
                <?= ($cari||$filter_status)
                    ? "Tidak ada data untuk filter yang dipilih"
                    : "Tidak ada permintaan radiologi pada periode <strong>$periode_label</strong>" ?>
              </h4>
              <?php if ($cari||$filter_status): ?>
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