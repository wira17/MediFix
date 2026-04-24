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

// ── AJAX: teruskan ke api/kirim_service_request.php ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['kirim', 'kirim_semua', 'kirim_is', 'status', 'sync_ihs'])) {
        include __DIR__ . '/api/kirim_service_request.php';
        exit;
    }
    if ($action === 'status_detail') {
        $noorder = trim($_POST['noorder'] ?? '');
        if (!$noorder) { echo json_encode(['status'=>'error','message'=>'noorder kosong']); exit; }
        try {
            $stmt = $pdo_simrs->prepare("
                SELECT
                    s.noorder,
                    s.kd_jenis_prw,
                    COALESCE(m.id_servicerequest, s.id_servicerequest) AS id_servicerequest,
                    COALESCE(m.status_kirim_sr,
                        CASE WHEN s.id_servicerequest IS NOT NULL AND s.id_servicerequest != ''
                             THEN 'terkirim' ELSE 'pending' END
                    ) AS status_kirim_sr,
                    m.id_imagingstudy,
                    m.status_kirim_is,
                    m.study_uid_dicom,
                    COALESCE(mdr.id_diagnosticreport, sdr.id_diagnosticreport) AS id_diagnosticreport,
                    COALESCE(mdr.status_kirim,
                        CASE WHEN sdr.id_diagnosticreport IS NOT NULL AND sdr.id_diagnosticreport != ''
                             THEN 'terkirim' ELSE 'pending' END
                    ) AS status_kirim_dr,
                    msp.ihs_number,
                    pr.no_rawat
                FROM satu_sehat_servicerequest_radiologi s
                LEFT JOIN medifix_ss_radiologi m               ON s.noorder = m.noorder
                LEFT JOIN medifix_ss_diagnosticreport_radiologi mdr ON s.noorder = mdr.noorder
                LEFT JOIN satu_sehat_diagnosticreport_radiologi sdr ON s.noorder = sdr.noorder
                LEFT JOIN permintaan_radiologi pr              ON s.noorder = pr.noorder
                LEFT JOIN reg_periksa reg                      ON pr.no_rawat = reg.no_rawat
                LEFT JOIN medifix_ss_pasien msp                ON reg.no_rkm_medis = msp.no_rkm_medis
                WHERE s.noorder = ?
                LIMIT 1
            ");
            $stmt->execute([$noorder]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['status'=>'error','message'=>'Data tidak ditemukan']); exit; }
            echo json_encode(['status'=>'ok','data'=>$row]);
        } catch (Exception $e) {
            echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
        }
        exit;
    }
}

// ── Filter & Pagination ──────────────────────────────────────────
$tgl_dari      = $_GET['tgl_dari']   ?? date('Y-m-d');
$tgl_sampai    = $_GET['tgl_sampai'] ?? date('Y-m-d');
$cari          = $_GET['cari']       ?? '';
$filter_status = $_GET['status']     ?? '';
$filter_is     = $_GET['is']         ?? '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_dari))   $tgl_dari   = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_sampai)) $tgl_sampai = date('Y-m-d');
if ($tgl_sampai < $tgl_dari) $tgl_sampai = $tgl_dari;
$limit  = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 20;
$page   = isset($_GET['page'])  ? max(1, intval($_GET['page']))  : 1;
$offset = ($page - 1) * $limit;

// ── AUTO-SYNC: Insert missing rows ke medifix_ss_radiologi ───────
// Setiap noorder di satu_sehat_servicerequest_radiologi yang belum
// ada di medifix_ss_radiologi akan di-INSERT dengan status default.
// Jika id_servicerequest sudah ada di tabel sumber, langsung 'terkirim'.
try {
    $pdo_simrs->exec("
        INSERT IGNORE INTO medifix_ss_radiologi
            (noorder, kd_jenis_prw, id_servicerequest,
             status_kirim_sr, status_kirim_is)
        SELECT
            s.noorder,
            s.kd_jenis_prw,
            s.id_servicerequest,
            CASE
                WHEN s.id_servicerequest IS NOT NULL AND s.id_servicerequest != ''
                THEN 'terkirim'
                ELSE 'pending'
            END,
            'pending'
        FROM satu_sehat_servicerequest_radiologi s
        WHERE NOT EXISTS (
            SELECT 1 FROM medifix_ss_radiologi m
            WHERE m.noorder = s.noorder
        )
    ");
} catch (Exception $e) {
    error_log('Auto-sync medifix_ss_radiologi: ' . $e->getMessage());
}

// ── Ambil Data ───────────────────────────────────────────────────
try {
    $wheres = ["pr.tgl_permintaan BETWEEN ? AND ?"];
    $params = [$tgl_dari, $tgl_sampai];

    if (!empty($cari)) {
        $wheres[] = "(s.noorder LIKE ? OR s.kd_jenis_prw LIKE ?
                      OR COALESCE(m.id_servicerequest, s.id_servicerequest) LIKE ?
                      OR m.id_imagingstudy LIKE ? OR p.nm_pasien LIKE ?
                      OR d.nm_dokter LIKE ? OR pr.diagnosa_klinis LIKE ?)";
        $params = array_merge($params, array_fill(0, 7, "%$cari%"));
    }
    if ($filter_status === 'terkirim') {
        $wheres[] = "(m.status_kirim_sr = 'terkirim' OR (m.status_kirim_sr IS NULL AND s.id_servicerequest IS NOT NULL AND s.id_servicerequest != ''))";
    } elseif ($filter_status === 'pending') {
        $wheres[] = "(m.status_kirim_sr = 'pending' OR (m.status_kirim_sr IS NULL AND (s.id_servicerequest IS NULL OR s.id_servicerequest = '')))";
    } elseif ($filter_status === 'error') {
        $wheres[] = "m.status_kirim_sr = 'error'";
    }
    if ($filter_is === 'terkirim') {
        $wheres[] = "m.status_kirim_is = 'terkirim'";
    } elseif ($filter_is === 'pending') {
        $wheres[] = "(m.status_kirim_is != 'terkirim' OR m.status_kirim_is IS NULL)";
    }

    $where_sql = "WHERE " . implode(" AND ", $wheres);

    // JOIN ke tabel Khanza (read only) + tabel MediFix baru
    $base_join = "
        FROM satu_sehat_servicerequest_radiologi s
        LEFT JOIN medifix_ss_radiologi m        ON s.noorder       = m.noorder
        JOIN permintaan_radiologi pr       ON s.noorder       = pr.noorder
        JOIN reg_periksa r                 ON pr.no_rawat     = r.no_rawat
        JOIN pasien p                      ON r.no_rkm_medis  = p.no_rkm_medis
        LEFT JOIN dokter d                 ON pr.dokter_perujuk = d.kd_dokter
        LEFT JOIN poliklinik pl            ON r.kd_poli       = pl.kd_poli
    ";

    $stmtCount = $pdo_simrs->prepare("SELECT COUNT(*) $base_join $where_sql");
    $stmtCount->execute($params);
    $total       = (int)$stmtCount->fetchColumn();
    $total_pages = max(1, ceil($total / $limit));

    $stmtData = $pdo_simrs->prepare(
        "SELECT
                s.noorder,
                s.kd_jenis_prw,
                COALESCE(m.id_servicerequest, s.id_servicerequest) AS id_servicerequest,
                m.id_imagingstudy,
                COALESCE(
                    m.status_kirim_sr,
                    CASE
                        WHEN s.id_servicerequest IS NOT NULL AND s.id_servicerequest != ''
                        THEN 'terkirim'
                        ELSE 'pending'
                    END
                ) AS status_kirim_sr,
                m.tgl_kirim_sr,
                m.error_msg_sr,
                m.status_kirim_is,
                m.tgl_kirim_is,
                m.error_msg_is,
                m.study_uid_dicom,
                pr.no_rawat, pr.tgl_permintaan, pr.jam_permintaan,
                pr.tgl_hasil, pr.jam_hasil,
                pr.status AS status_rawat,
                pr.informasi_tambahan, pr.diagnosa_klinis,
                p.no_rkm_medis, p.nm_pasien, p.jk, p.tgl_lahir,
                r.no_rawat AS no_rawat_reg,
                d.nm_dokter,
                pl.nm_poli
         $base_join $where_sql
         ORDER BY pr.tgl_permintaan DESC, pr.jam_permintaan DESC
         LIMIT " . intval($limit) . " OFFSET " . intval($offset)
    );
    $stmtData->execute($params);
    $data = $stmtData->fetchAll(PDO::FETCH_ASSOC);

    // Stats — hitung dari gabungan kedua tabel
    $stmtStats = $pdo_simrs->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE
                WHEN m.status_kirim_sr = 'terkirim'
                  OR (m.status_kirim_sr IS NULL AND s.id_servicerequest IS NOT NULL AND s.id_servicerequest != '')
                THEN 1 ELSE 0
            END) AS sr_terkirim,
            SUM(CASE WHEN m.status_kirim_sr = 'error' THEN 1 ELSE 0 END) AS sr_error,
            SUM(CASE
                WHEN m.status_kirim_sr = 'pending'
                  OR (m.status_kirim_sr IS NULL AND (s.id_servicerequest IS NULL OR s.id_servicerequest = ''))
                THEN 1 ELSE 0
            END) AS sr_pending,
            SUM(CASE WHEN m.status_kirim_is = 'terkirim' THEN 1 ELSE 0 END) AS is_terkirim,
            SUM(CASE WHEN m.status_kirim_is = 'error'    THEN 1 ELSE 0 END) AS is_error
        FROM satu_sehat_servicerequest_radiologi s
        LEFT JOIN medifix_ss_radiologi m    ON s.noorder = m.noorder
        JOIN permintaan_radiologi pr        ON s.noorder = pr.noorder
        WHERE pr.tgl_permintaan BETWEEN ? AND ?
    ");
    $stmtStats->execute([$tgl_dari, $tgl_sampai]);
    $stats       = $stmtStats->fetch(PDO::FETCH_ASSOC);
    $st_total    = (int)($stats['total']       ?? 0);
    $st_terkirim = (int)($stats['sr_terkirim'] ?? 0);
    $st_error    = (int)($stats['sr_error']    ?? 0);
    $st_pending  = (int)($stats['sr_pending']  ?? 0);
    $st_is       = (int)($stats['is_terkirim'] ?? 0);
    $st_is_err   = (int)($stats['is_error']    ?? 0);
    $dbError     = null;

} catch (Exception $e) {
    $data = []; $total = 0; $total_pages = 1;
    $st_total = $st_terkirim = $st_error = $st_pending = $st_is = $st_is_err = 0;
    $dbError = $e->getMessage();
}

$pct_sr = $st_total > 0 ? round(($st_terkirim / $st_total) * 100) : 0;
$pct_is = $st_total > 0 ? round(($st_is / $st_total) * 100) : 0;

$tgl_dari_fmt   = date('d F Y', strtotime($tgl_dari));
$tgl_sampai_fmt = date('d F Y', strtotime($tgl_sampai));
$periode_label  = $tgl_dari === $tgl_sampai ? $tgl_dari_fmt : "$tgl_dari_fmt s/d $tgl_sampai_fmt";

$page_title = 'Service Request Radiologi — Satu Sehat';

$extra_css = '
.btn-send{width:32px;height:32px;border-radius:6px;padding:0;display:inline-flex;align-items:center;justify-content:center;border:1px solid transparent;cursor:pointer;transition:all .25s;font-size:12px;background:#605ca8;border-color:#564fa5;color:#fff}
.btn-send:hover{background:#564fa5;transform:scale(1.12);box-shadow:0 3px 10px rgba(96,92,168,.45);color:#fff}
.btn-send:disabled{opacity:.5;cursor:not-allowed;transform:none}
.btn-send.sent{background:#00a65a;border-color:#008d4c}
.btn-send.sent:hover{background:#008d4c}
.btn-send.error-st{background:#dd4b39;border-color:#c23321}
.btn-send.spin i{animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

.badge-sr-ok{background:#dff0d8;color:#3c763d;border:1px solid #c3e6cb;padding:3px 9px;border-radius:10px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px}
.badge-sr-err{background:#f2dede;color:#a94442;border:1px solid #f5c6cb;padding:3px 9px;border-radius:10px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px}
.badge-sr-pend{background:#fcf8e3;color:#8a6d3b;border:1px solid #faebcc;padding:3px 9px;border-radius:10px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px}
.badge-is-ok{background:#d9edf7;color:#31708f;border:1px solid #bce8f1;padding:3px 9px;border-radius:10px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px;margin-top:3px}
.badge-is-pend{background:#f5f5f5;color:#777;border:1px solid #e5e5e5;padding:3px 9px;border-radius:10px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px;margin-top:3px}
.badge-is-err{background:#f2dede;color:#a94442;border:1px solid #f5c6cb;padding:3px 9px;border-radius:10px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px;margin-top:3px}
.badge-ralan{background:#d9edf7;color:#31708f;border:1px solid #bce8f1;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700}
.badge-ranap{background:#fcf8e3;color:#8a6d3b;border:1px solid #faebcc;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700}

.row-sent{background:#f0fff4!important}
.row-error{background:#fff5f5!important}

.btn-copy{width:22px;height:22px;border-radius:4px;padding:0;display:inline-flex;align-items:center;justify-content:center;background:#ecf0f1;border:1px solid #ddd;color:#777;cursor:pointer;transition:all .2s;font-size:10px;vertical-align:middle;margin-left:3px}
.btn-copy:hover{background:#d5d8dc;color:#333}

.id-cell{font-family:"Courier New",monospace;font-size:10.5px;color:#555;word-break:break-all}
.no-id{font-style:italic;color:#ccc;font-size:11px}
.noorder-lbl{font-weight:700;color:#605ca8;font-size:13px;font-family:"Courier New",monospace}
.rm-lbl{font-size:10.5px;color:#aaa;font-family:"Courier New",monospace}
.kd-lbl{display:inline-block;background:#f0f0ff;border:1px solid #d6d0f8;color:#605ca8;padding:2px 7px;border-radius:4px;font-size:11px;font-weight:700;font-family:"Courier New",monospace}
.nm-pasien{font-weight:700;color:#333;font-size:13px}
.waktu-lbl{font-size:13px;font-weight:600;color:#333}
.waktu-sub{font-size:10.5px;color:#aaa;margin-top:2px}
.err-tooltip{cursor:help;border-bottom:1px dashed #dd4b39;font-size:10.5px;color:#dd4b39}

.action-bar{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.btn-kirim-semua{background:linear-gradient(135deg,#605ca8,#4a4789);border:none;color:#fff;padding:6px 14px;border-radius:5px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .25s}
.btn-kirim-semua:hover{transform:translateY(-2px);box-shadow:0 4px 14px rgba(96,92,168,.4);color:#fff}
.btn-kirim-semua:disabled{opacity:.6;cursor:not-allowed;transform:none}

.info-bar-stats{display:flex;gap:16px;padding:8px 15px;background:#f0f0ff;border-bottom:1px solid #e5e5e5;font-size:12px;flex-wrap:wrap;align-items:center}
.ibs-item{display:flex;align-items:center;gap:5px;color:#555}
.ibs-val{font-weight:700;font-size:14px}
.ibs-sep{color:#ddd}

.prog-dual{padding:10px 15px 6px;background:#f9f9f9;border-bottom:1px solid #e5e5e5}
.prog-row{display:flex;align-items:center;gap:10px;font-size:12px;margin-bottom:5px}
.prog-label{width:120px;color:#555;flex-shrink:0}
.prog-bar{flex:1;height:7px;border-radius:4px;background:#e5e5e5;overflow:hidden}
.prog-fill-sr{height:100%;border-radius:4px;background:linear-gradient(90deg,#605ca8,#00a65a);transition:width .6s}
.prog-fill-is{height:100%;border-radius:4px;background:linear-gradient(90deg,#00c0ef,#0073b7);transition:width .6s}
.prog-pct{width:38px;text-align:right;font-weight:700;color:#555;flex-shrink:0}

.tbl-sr thead tr th{background:#605ca8;color:#fff!important;white-space:nowrap;font-size:12px}
.tbl-sr tbody td{vertical-align:middle}
.status-col{display:flex;flex-direction:column;gap:2px}

.date-range-wrap{display:flex;align-items:center;gap:6px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;padding:5px 10px}
.date-range-wrap label{font-size:12px;color:#666;margin:0;white-space:nowrap}
.date-range-wrap input{border:none;background:transparent;font-size:13px;color:#333;padding:0;outline:none;width:130px}
.date-range-sep{color:#aaa;font-size:12px}
.btn-period{padding:3px 10px;border-radius:4px;font-size:11px;border:1px solid #dee2e6;background:#fff;color:#555;cursor:pointer;transition:all .2s}
.btn-period:hover{background:#605ca8;color:#fff;border-color:#605ca8}

.btn-ihs{width:22px;height:22px;border-radius:4px;padding:0;display:inline-flex;align-items:center;justify-content:center;background:#f39c12;border:1px solid #e67e22;color:#fff;cursor:pointer;transition:all .2s;font-size:10px;vertical-align:middle;margin-left:3px}
.btn-ihs:hover{background:#e67e22;transform:scale(1.1)}
.btn-ihs:disabled{opacity:.5;cursor:not-allowed;transform:none}
.ihs-ok{color:#00a65a;font-size:10px;font-weight:700}
.ihs-miss{color:#dd4b39;font-size:10px;}

.modal-ss-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9998;display:flex;align-items:center;justify-content:center}
.modal-ss{background:#fff;border-radius:10px;width:560px;max-width:95vw;box-shadow:0 8px 32px rgba(0,0,0,.18);overflow:hidden}
.modal-ss-header{background:#605ca8;color:#fff;padding:14px 18px;display:flex;align-items:center;justify-content:space-between}
.modal-ss-header h4{margin:0;font-size:15px;font-weight:700}
.modal-ss-close{background:none;border:none;color:#fff;font-size:20px;cursor:pointer;line-height:1;padding:0}
.modal-ss-body{padding:18px}
.modal-ss-row{display:flex;flex-direction:column;gap:4px;margin-bottom:14px;padding-bottom:14px;border-bottom:1px solid #f0f0f0}
.modal-ss-row:last-child{border-bottom:none;margin-bottom:0;padding-bottom:0}
.modal-ss-label{font-size:11px;color:#aaa;font-weight:700;text-transform:uppercase;letter-spacing:.5px}
.modal-ss-val{font-family:"Courier New",monospace;font-size:12px;color:#333;word-break:break-all;display:flex;align-items:center;gap:6px}
.modal-ss-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:10px;font-size:11px;font-weight:700}
.modal-ss-badge.ok{background:#dff0d8;color:#3c763d;border:1px solid #c3e6cb}
.modal-ss-badge.pend{background:#fcf8e3;color:#8a6d3b;border:1px solid #faebcc}
.modal-ss-badge.err{background:#f2dede;color:#a94442;border:1px solid #f5c6cb}
.modal-ss-badge.info{background:#d9edf7;color:#31708f;border:1px solid #bce8f1}
.btn-status-detail{width:20px;height:20px;border-radius:4px;padding:0;display:inline-flex;align-items:center;justify-content:center;background:#f0f0ff;border:1px solid #d6d0f8;color:#605ca8;cursor:pointer;transition:all .2s;font-size:10px;margin-left:4px;vertical-align:middle}
.btn-status-detail:hover{background:#605ca8;color:#fff}
.modal-ss-loading{text-align:center;padding:30px;color:#aaa}

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
    if (jenis === "hari") { dari = sampai; }
    else if (jenis === "minggu") { const d=new Date(now);d.setDate(d.getDate()-6);dari=d.toISOString().split("T")[0]; }
    else if (jenis === "bulan") { dari=new Date(now.getFullYear(),now.getMonth(),1).toISOString().split("T")[0]; }
    else if (jenis === "bulan_lalu") {
        dari=new Date(now.getFullYear(),now.getMonth()-1,1).toISOString().split("T")[0];
        sampai=new Date(now.getFullYear(),now.getMonth(),0).toISOString().split("T")[0];
    }
    document.getElementById("tgl_dari").value = dari;
    document.getElementById("tgl_sampai").value = sampai;
    document.querySelector("form").submit();
}

function syncIHS(noRkm, btnEl) {
    btnEl.disabled = true;
    const origHTML = btnEl.innerHTML;
    btnEl.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';
    fetch(window.location.href, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({action:'sync_ihs', no_rkm_medis: noRkm})
    })
    .then(r => r.json())
    .then(resp => {
        if (resp.status === 'ok') {
            btnEl.outerHTML = '<span class="ihs-ok" title="' + resp.ihs_number + '"><i class="fa fa-check-circle"></i> IHS OK</span>';
            showToast('IHS ditemukan: ' + resp.ihs_number, 'success');
            const row = document.querySelector('[data-rkm="' + noRkm + '"]');
            if (row) {
                const btnSR = row.closest('tr').querySelector('.btn-send');
                if (btnSR) btnSR.disabled = false;
            }
        } else {
            btnEl.disabled = false;
            btnEl.innerHTML = origHTML;
            showToast('Gagal sync IHS: ' + (resp.message || ''), 'error');
        }
    })
    .catch(() => {
        btnEl.disabled = false;
        btnEl.innerHTML = origHTML;
        showToast('Koneksi gagal', 'error');
    });
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

function kirimSatu(noorder, kdJenisPrw, btnEl) {
    btnEl.disabled = true;
    const origHTML = btnEl.innerHTML;
    btnEl.classList.add("spin");
    btnEl.innerHTML = `<i class="fa fa-spinner"></i>`;
    fetch(window.location.href, {
        method: "POST",
        headers: {"Content-Type":"application/x-www-form-urlencoded"},
        body: new URLSearchParams({action:"kirim", noorder, kd_jenis_prw: kdJenisPrw})
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
                const b = row.querySelector(".badge-sr-status");
                if (b) { b.className = "badge-sr-status badge-sr-ok"; b.innerHTML = `<i class="fa fa-check-circle"></i> SR OK`; }
                row.classList.remove("row-error"); row.classList.add("row-sent");
                const idCell = row.querySelector(".id-sr-cell");
                if (idCell && resp.id_sr) {
                    idCell.innerHTML = `<span class="id-cell" title="${resp.id_sr}">${resp.id_sr.substring(0,36)}</span>
                        <button class="btn-copy" onclick="copyText('${resp.id_sr}')" title="Salin"><i class="fa fa-copy"></i></button>`;
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
            const row = btnEl.closest("tr");
            if (row) { row.classList.add("row-error"); }
        }
    })
    .catch(() => {
        btnEl.disabled = false; btnEl.innerHTML = origHTML; btnEl.classList.remove("spin");
        showToast("Koneksi ke server gagal", "error");
    });
}

function kirimIS(noorder, btnEl) {
    if (!confirm("Kirim ImagingStudy manual untuk No. Order " + noorder + "?\n\nCatatan: Gunakan ini jika DICOM sudah masuk Orthanc tapi IS belum terkirim otomatis.")) return;
    btnEl.disabled = true;
    const origHTML = btnEl.innerHTML;
    btnEl.innerHTML = `<i class="fa fa-spinner fa-spin"></i>`;
    fetch(window.location.href, {
        method: "POST",
        headers: {"Content-Type":"application/x-www-form-urlencoded"},
        body: new URLSearchParams({action:"kirim_is", noorder})
    })
    .then(r => r.json())
    .then(resp => {
        if (resp.status === "ok") {
            btnEl.innerHTML = `<i class="fa fa-check"></i>`;
            btnEl.style.background = "#0073b7";
            btnEl.title = "IS Terkirim — " + (resp.id_imagingstudy || "");
            showToast("ImagingStudy " + noorder + " berhasil dikirim!", "success");
            const row = btnEl.closest("tr");
            if (row) {
                const badgeIS = row.querySelector(".badge-is-status");
                if (badgeIS) { badgeIS.className = "badge-is-status badge-is-ok"; badgeIS.innerHTML = `<i class="fa fa-image"></i> IS OK`; }
                const idCell = row.querySelector(".id-is-cell");
                if (idCell && resp.id_imagingstudy) {
                    idCell.innerHTML = `<span class="id-cell" title="${resp.id_imagingstudy}">${resp.id_imagingstudy.substring(0,36)}</span>
                        <button class="btn-copy" onclick="copyText('${resp.id_imagingstudy}')" title="Salin"><i class="fa fa-copy"></i></button>`;
                }
            }
        } else {
            btnEl.disabled = false;
            btnEl.innerHTML = origHTML;
            showToast("Gagal IS: " + (resp.message || ""), "error");
        }
    })
    .catch(() => { btnEl.disabled = false; btnEl.innerHTML = origHTML; showToast("Koneksi gagal", "error"); });
}

function kirimSemua() {
    const tanggal = document.getElementById("inputTanggal")?.value || "";
    if (!confirm("Kirim semua ServiceRequest pending ke Satu Sehat?\nTanggal: " + tanggal)) return;
    const btn = document.getElementById("btnKirimSemua");
    if (btn) { btn.disabled = true; btn.innerHTML = `<i class="fa fa-spinner fa-spin"></i> Mengirim…`; }
    fetch(window.location.href, {
        method: "POST",
        headers: {"Content-Type":"application/x-www-form-urlencoded"},
        body: new URLSearchParams({action:"kirim_semua", tanggal})
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

function showStatusModal(noorder, nmPasien, noRkm) {
    // Buat overlay
    const overlay = document.createElement('div');
    overlay.className = 'modal-ss-overlay';
    overlay.id = 'modal-ss-overlay';
    overlay.innerHTML = `
        <div class="modal-ss">
            <div class="modal-ss-header">
                <h4><i class="fa fa-stethoscope"></i> Status Satu Sehat &mdash; ${noorder}</h4>
                <button class="modal-ss-close" onclick="closeStatusModal()">&times;</button>
            </div>
            <div class="modal-ss-body">
                <div class="modal-ss-loading"><i class="fa fa-spinner fa-spin"></i> Memuat data...</div>
            </div>
        </div>`;
    document.body.appendChild(overlay);
    overlay.addEventListener('click', function(e){ if(e.target===overlay) closeStatusModal(); });

    // Fetch data via AJAX
    fetch(window.location.href, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({action: 'status_detail', noorder})
    })
    .then(r => r.json())
    .then(d => {
        const body = document.querySelector('#modal-ss-overlay .modal-ss-body');
        if (d.status !== 'ok') {
            body.innerHTML = `<p class="text-danger"><i class="fa fa-times-circle"></i> ${d.message || 'Gagal memuat data'}</p>`;
            return;
        }
        const r = d.data;
        function badge(val, okVal) {
            const isOk  = val === okVal || val === 'terkirim';
            const isErr = val === 'error';
            const cls   = isOk ? 'ok' : (isErr ? 'err' : 'pend');
            const icon  = isOk ? 'check-circle' : (isErr ? 'times-circle' : 'clock-o');
            const label = isOk ? 'Terkirim' : (isErr ? 'Error' : (val || 'Pending'));
            return `<span class="modal-ss-badge ${cls}"><i class="fa fa-${icon}"></i> ${label}</span>`;
        }
        function idRow(label, id, tgl) {
            if (!id) return `<div class="modal-ss-row">
                <div class="modal-ss-label">${label}</div>
                <div class="modal-ss-val" style="color:#ccc;font-style:italic"><i class="fa fa-minus-circle"></i> Belum ada</div>
            </div>`;
            const short = id.length > 36 ? id.substring(0,36)+'…' : id;
            const tglStr = tgl ? `<span style="font-size:10px;color:#aaa;font-family:sans-serif">${tgl}</span>` : '';
            return `<div class="modal-ss-row">
                <div class="modal-ss-label">${label}</div>
                <div class="modal-ss-val">
                    <span title="${id}">${short}</span>
                    <button class="btn-copy" onclick="copyText('${id}')" title="Salin"><i class="fa fa-copy"></i></button>
                    ${tglStr}
                </div>
            </div>`;
        }
        body.innerHTML = `
            <div class="modal-ss-row" style="flex-direction:row;align-items:center;gap:12px;flex-wrap:wrap">
                <div>
                    <div class="modal-ss-label">Pasien</div>
                    <div style="font-weight:700;font-size:14px;color:#333">${nmPasien}</div>
                    <div style="font-size:11px;color:#aaa;font-family:'Courier New',monospace">${noRkm}</div>
                </div>
                <div style="margin-left:auto">
                    ${r.ihs_number
                        ? `<span class="modal-ss-badge info"><i class="fa fa-id-card"></i> IHS: ${r.ihs_number}</span>`
                        : `<span class="modal-ss-badge err"><i class="fa fa-exclamation-triangle"></i> No IHS</span>`}
                </div>
            </div>
            <div class="modal-ss-row">
                <div class="modal-ss-label">Service Request</div>
                <div class="modal-ss-val">${badge(r.status_kirim_sr)}</div>
                ${r.id_servicerequest ? `<div class="modal-ss-val" style="margin-top:4px">
                    <span style="font-size:11px" title="${r.id_servicerequest}">${r.id_servicerequest.substring(0,40)}…</span>
                    <button class="btn-copy" onclick="copyText('${r.id_servicerequest}')" title="Salin"><i class="fa fa-copy"></i></button>
                </div>` : ''}
            </div>
            <div class="modal-ss-row">
                <div class="modal-ss-label">Imaging Study (DICOM)</div>
                <div class="modal-ss-val">${badge(r.status_kirim_is)}</div>
                ${r.id_imagingstudy ? `<div class="modal-ss-val" style="margin-top:4px">
                    <span style="font-size:11px" title="${r.id_imagingstudy}">${r.id_imagingstudy.substring(0,40)}…</span>
                    <button class="btn-copy" onclick="copyText('${r.id_imagingstudy}')" title="Salin"><i class="fa fa-copy"></i></button>
                </div>` : ''}
                ${r.study_uid_dicom ? `<div style="font-size:10px;color:#888;margin-top:4px;font-family:'Courier New',monospace">
                    DICOM UID: ${r.study_uid_dicom}
                </div>` : ''}
            </div>
            <div class="modal-ss-row">
                <div class="modal-ss-label">Diagnostic Report</div>
                <div id="modal-dr-status">
                    <div class="modal-ss-val">${badge(r.status_kirim_dr)}</div>
                    ${r.id_diagnosticreport ? `<div class="modal-ss-val" style="margin-top:4px">
                        <span style="font-size:11px" title="${r.id_diagnosticreport}">${r.id_diagnosticreport.substring(0,40)}…</span>
                        <button class="btn-copy" onclick="copyText('${r.id_diagnosticreport}')" title="Salin"><i class="fa fa-copy"></i></button>
                    </div>` : `<div style="font-size:11px;color:#aaa;margin-top:4px;font-style:italic">Belum dikirim</div>`}
                </div>
                ${(r.status_kirim_dr !== 'terkirim' && r.status_kirim_sr === 'terkirim' && r.ihs_number) ? `
                <div style="margin-top:8px">
                    <button id="btn-kirim-dr-modal"
                        style="background:#0073b7;border:1px solid #005b99;color:#fff;padding:5px 14px;border-radius:5px;font-size:12px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px"
                        onclick="kirimDRdariModal('${r.noorder}','${r.kd_jenis_prw}','${r.no_rawat}',this)">
                        <i class="fa fa-send"></i> Kirim Diagnostic Report
                    </button>
                </div>` : ''}
                ${(r.status_kirim_dr !== 'terkirim' && !r.ihs_number) ? `
                <div style="font-size:11px;color:#f39c12;margin-top:6px"><i class="fa fa-exclamation-triangle"></i> Sync IHS dulu sebelum kirim DR</div>` : ''}
                ${(r.status_kirim_dr !== 'terkirim' && r.status_kirim_sr !== 'terkirim') ? `
                <div style="font-size:11px;color:#f39c12;margin-top:6px"><i class="fa fa-exclamation-triangle"></i> Kirim SR dulu sebelum DR</div>` : ''}
            </div>
            <div class="modal-ss-row" style="background:#f9f9f9;border-radius:6px;padding:10px;border:none">
                <div class="modal-ss-label" style="margin-bottom:8px">Ringkasan</div>
                <div id="modal-ringkasan" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                    <span class="modal-ss-badge ${r.status_kirim_sr==='terkirim'?'ok':'pend'}">SR</span>
                    <span style="color:#aaa;line-height:22px">→</span>
                    <span class="modal-ss-badge ${r.status_kirim_is==='terkirim'?'ok':'pend'}">IS</span>
                    <span style="color:#aaa;line-height:22px">→</span>
                    <span class="modal-ss-badge ${r.status_kirim_dr==='terkirim'?'ok':'pend'}">DR</span>
                    ${(r.status_kirim_sr==='terkirim'&&r.status_kirim_is==='terkirim'&&r.status_kirim_dr==='terkirim')
                        ? '<span class="modal-ss-badge ok" style="margin-left:8px"><i class="fa fa-mobile"></i> Siap di Satu Sehat Mobile</span>'
                        : '<span class="modal-ss-badge pend" style="margin-left:8px"><i class="fa fa-clock-o"></i> Belum lengkap</span>'}
                </div>
            </div>`;
    })
    .catch(() => {
        const body = document.querySelector('#modal-ss-overlay .modal-ss-body');
        if (body) body.innerHTML = '<p class="text-danger"><i class="fa fa-times-circle"></i> Koneksi gagal</p>';
    });
}

function closeStatusModal() {
    const el = document.getElementById('modal-ss-overlay');
    if (el) el.remove();
}

function kirimDRdariModal(noorder, kdJenisPrw, noRawat, btnEl) {
    if (!confirm("Kirim DiagnosticReport untuk No. Order " + noorder + "?\n\nObservation + DR akan dikirim ke Satu Sehat.")) return;
    btnEl.disabled = true;
    const origHTML = btnEl.innerHTML;
    btnEl.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Mengirim...';

    fetch('data_diagnosticreport.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            action:       'kirim',
            noorder:      noorder,
            kd_jenis_prw: kdJenisPrw,
            no_rawat:     noRawat
        })
    })
    .then(r => r.json())
    .then(resp => {
        if (resp.status === 'ok') {
            const idDR = resp.id_dr || '';
            btnEl.innerHTML = '<i class="fa fa-check"></i> Terkirim';
            btnEl.style.background = '#00a65a';
            btnEl.style.borderColor = '#008d4c';
            showToast('DiagnosticReport ' + noorder + ' berhasil dikirim!', 'success');

            // Update tampilan modal
            const drSection = document.getElementById('modal-dr-status');
            if (drSection) {
                drSection.innerHTML = `
                    <div class="modal-ss-val"><span class="modal-ss-badge ok"><i class="fa fa-check-circle"></i> Terkirim</span></div>
                    <div class="modal-ss-val" style="margin-top:4px">
                        <span style="font-size:11px" title="${idDR}">${idDR.substring(0,40)}…</span>
                        <button class="btn-copy" onclick="copyText('${idDR}')" title="Salin"><i class="fa fa-copy"></i></button>
                    </div>`;
            }

            // Update ringkasan
            const ringkasan = document.getElementById('modal-ringkasan');
            if (ringkasan) {
                ringkasan.innerHTML = `
                    <span class="modal-ss-badge ok">SR</span>
                    <span style="color:#aaa;line-height:22px">→</span>
                    <span class="modal-ss-badge ok">IS</span>
                    <span style="color:#aaa;line-height:22px">→</span>
                    <span class="modal-ss-badge ok">DR</span>
                    <span class="modal-ss-badge ok" style="margin-left:8px"><i class="fa fa-mobile"></i> Siap di Satu Sehat Mobile</span>`;
            }
        } else {
            btnEl.disabled = false;
            btnEl.innerHTML = origHTML;
            showToast('Gagal: ' + (resp.message || ''), 'error');
        }
    })
    .catch(() => {
        btnEl.disabled = false;
        btnEl.innerHTML = origHTML;
        showToast('Koneksi gagal', 'error');
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
      <i class="fa fa-heartbeat" style="color:#605ca8;"></i>
      Service Request Radiologi
      <small>Satu Sehat &mdash; <?= $periode_label ?></small>
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

        <!-- Tabel -->
        <div class="box box-primary">
          <div class="box-header with-border">
            <h3 class="box-title">
              <i class="fa fa-table"></i> Data Service Request
              <span class="badge" style="background:#605ca8;"><?= number_format($total) ?></span>
            </h3>
            <div class="box-tools pull-right">
              <div class="action-bar">
                <input type="hidden" id="inputTanggal" value="<?= htmlspecialchars($tgl_dari) ?>">
                <button id="btnKirimSemua" onclick="kirimSemua()" class="btn-kirim-semua" <?= $st_pending===0?'disabled':'' ?>>
                  <i class="fa fa-send"></i> Kirim Semua Pending
                  <?php if ($st_pending > 0): ?>
                  <span class="badge" style="background:#dd4b39;"><?= number_format($st_pending) ?></span>
                  <?php endif; ?>
                </button>
                <a href="?tgl_dari=<?= urlencode($tgl_dari) ?>&tgl_sampai=<?= urlencode($tgl_sampai) ?>&cari=<?= urlencode($cari) ?>&status=<?= urlencode($filter_status) ?>&is=<?= urlencode($filter_is) ?>&limit=<?= $limit ?>"
                   class="btn btn-default btn-sm" title="Refresh"><i class="fa fa-refresh"></i></a>
              </div>
            </div>
          </div>

          <!-- Stats -->
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
          </div>

          <!-- Progress bar -->
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
                    <th width="36"  class="text-center">#</th>
                    <th width="40"  class="text-center">SR</th>
                    <th width="40"  class="text-center">IS</th>
                    <th width="140">No. Order</th>
                    <th width="100">Kd. Jenis Prw</th>
                    <th width="170">Pasien</th>
                    <th width="140">Dokter / Poli</th>
                    <th>Diagnosa</th>
                    <th width="100">Waktu</th>
                    <th width="65"  class="text-center">Rawat</th>
                    <th width="210">ID ServiceRequest</th>
                    <th width="210">ID ImagingStudy</th>
                    <th width="130" class="text-center">Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $no = $offset + 1;
                  foreach ($data as $r):
                      $srOk  = ($r['status_kirim_sr'] ?? '') === 'terkirim';
                      $srErr = ($r['status_kirim_sr'] ?? '') === 'error';
                      $isOk  = ($r['status_kirim_is'] ?? '') === 'terkirim';
                      $isErr = ($r['status_kirim_is'] ?? '') === 'error';
                      $rowClass = $srErr ? 'row-error' : ($srOk ? 'row-sent' : '');
                      $umur = '';
                      if (!empty($r['tgl_lahir'])) {
                          try { $umur = (new DateTime())->diff(new DateTime($r['tgl_lahir']))->y . ' th'; } catch(Exception $e){}
                      }
                      $shortSR = !empty($r['id_servicerequest']) ? mb_strimwidth($r['id_servicerequest'], 0, 36, '…') : '';
                      $shortIS = !empty($r['id_imagingstudy'])   ? mb_strimwidth($r['id_imagingstudy'],   0, 36, '…') : '';
                  ?>
                  <tr class="<?= $rowClass ?>">

                    <td class="text-center" style="color:#aaa;font-size:11px;font-weight:600;"><?= $no++ ?></td>

                    <td class="text-center">
                      <button class="btn-send <?= $srOk?'sent':($srErr?'error-st':'') ?>"
                              onclick="kirimSatu('<?= addslashes($r['noorder']) ?>','<?= addslashes($r['kd_jenis_prw']) ?>',this)"
                              title="<?= $srOk?'Kirim Ulang SR':($srErr?'Retry SR':'Kirim SR ke Satu Sehat') ?>">
                        <i class="fa fa-<?= $srOk?'refresh':($srErr?'exclamation-triangle':'send') ?>"></i>
                      </button>
                    </td>

                    <td class="text-center">
                      <?php if ($srOk): ?>
                        <button class="btn-send <?= $isOk?'sent':($isErr?'error-st':'') ?>"
                                onclick="kirimIS('<?= addslashes($r['noorder']) ?>',this)"
                                style="<?= $isOk?'background:#0073b7;border-color:#005b99':'' ?>"
                                title="<?= $isOk?'Kirim Ulang IS':($isErr?'Retry IS':'Kirim ImagingStudy Manual') ?>">
                          <i class="fa fa-<?= $isOk?'refresh':($isErr?'exclamation-triangle':'image') ?>"></i>
                        </button>
                      <?php else: ?>
                        <span class="btn-send" style="background:#ccc;border-color:#bbb;cursor:not-allowed;" title="Kirim SR dulu">
                          <i class="fa fa-image"></i>
                        </span>
                      <?php endif; ?>
                    </td>

                    <td>
                      <div class="noorder-lbl"><?= htmlspecialchars($r['noorder']) ?></div>
                      <div class="rm-lbl"><?= htmlspecialchars($r['no_rawat']) ?></div>
                    </td>

                    <td><span class="kd-lbl"><?= htmlspecialchars($r['kd_jenis_prw']) ?></span></td>

                    <td data-rkm="<?= htmlspecialchars($r['no_rkm_medis']) ?>">
                      <div class="nm-pasien"><?= htmlspecialchars($r['nm_pasien']) ?></div>
                      <div class="rm-lbl">
                        <?= htmlspecialchars($r['no_rkm_medis']) ?>
                        <?= $umur ? " · $umur" : '' ?>
                        <?= !empty($r['jk']) ? ' · '.htmlspecialchars($r['jk']) : '' ?>
                      </div>
                      <?php
                      $ihsNum = '';
                      try {
                          $stmtIhs = $pdo_simrs->prepare("SELECT ihs_number FROM medifix_ss_pasien WHERE no_rkm_medis = ? LIMIT 1");
                          $stmtIhs->execute([$r['no_rkm_medis']]);
                          $ihsRow = $stmtIhs->fetch(PDO::FETCH_ASSOC);
                          $ihsNum = $ihsRow['ihs_number'] ?? '';
                      } catch(Exception $e) { $ihsNum = ''; }
                      ?>
                      <?php if (!empty($ihsNum)): ?>
                        <span class="ihs-ok" title="IHS: <?= htmlspecialchars($ihsNum) ?>">
                          <i class="fa fa-id-card"></i> IHS OK
                        </span>
                      <?php else: ?>
                        <button class="btn-ihs"
                                onclick="syncIHS('<?= addslashes($r['no_rkm_medis']) ?>',this)"
                                title="Klik untuk sync IHS Number pasien ini">
                          <i class="fa fa-refresh"></i>
                        </button>
                        <span class="ihs-miss" title="IHS belum ada, klik tombol untuk sync">
                          <i class="fa fa-exclamation-triangle"></i> No IHS
                        </span>
                      <?php endif; ?>
                      <button class="btn-status-detail"
                              onclick="showStatusModal('<?= addslashes($r['noorder']) ?>','<?= addslashes($r['nm_pasien']) ?>','<?= addslashes($r['no_rkm_medis']) ?>')"
                              title="Lihat status SR / IS / DR di Satu Sehat">
                        <i class="fa fa-info-circle"></i>
                      </button>
                    </td>

                    <td>
                      <div style="font-size:12px;font-weight:600;color:#444;"><?= htmlspecialchars($r['nm_dokter'] ?: '-') ?></div>
                      <div class="rm-lbl"><?= htmlspecialchars($r['nm_poli'] ?: '-') ?></div>
                    </td>

                    <td>
                      <div style="font-size:12px;color:#555;line-height:1.4;"><?= htmlspecialchars($r['diagnosa_klinis'] ?: '-') ?></div>
                      <?php if (!empty($r['informasi_tambahan'])): ?>
                      <div style="font-size:10.5px;color:#aaa;margin-top:2px;"><i class="fa fa-info-circle"></i> <?= htmlspecialchars($r['informasi_tambahan']) ?></div>
                      <?php endif; ?>
                    </td>

                    <td>
                      <div class="waktu-lbl"><?= date('H:i', strtotime($r['jam_permintaan'])) ?> WIB</div>
                      <div class="waktu-sub"><?= date('d/m/Y', strtotime($r['tgl_permintaan'])) ?></div>
                      <?php if (!empty($r['tgl_hasil']) && $r['tgl_hasil'] !== '0000-00-00'): ?>
                      <div style="font-size:10.5px;color:#00a65a;margin-top:2px;">
                        <i class="fa fa-check"></i> <?= date('d/m H:i', strtotime($r['tgl_hasil'].' '.$r['jam_hasil'])) ?>
                      </div>
                      <?php endif; ?>
                    </td>

                    <td class="text-center">
                      <span class="badge-<?= strtolower($r['status_rawat']??'')==='ranap'?'ranap':'ralan' ?>">
                        <?= strtoupper($r['status_rawat'] ?? '-') ?>
                      </span>
                    </td>

                    <td class="id-sr-cell">
                      <?php if (!empty($r['id_servicerequest'])): ?>
                        <span class="id-cell" title="<?= htmlspecialchars($r['id_servicerequest']) ?>"><?= htmlspecialchars($shortSR) ?></span>
                        <button class="btn-copy" onclick="copyText('<?= addslashes($r['id_servicerequest']) ?>')" title="Salin"><i class="fa fa-copy"></i></button>
                        <?php if (!empty($r['tgl_kirim_sr'])): ?>
                        <div style="font-size:10px;color:#aaa;margin-top:2px;"><?= date('d/m H:i', strtotime($r['tgl_kirim_sr'])) ?></div>
                        <?php endif; ?>
                      <?php elseif ($srErr): ?>
                        <span class="err-tooltip" title="<?= htmlspecialchars($r['error_msg_sr']??'') ?>"><i class="fa fa-times-circle text-red"></i> Error</span>
                      <?php else: ?>
                        <span class="no-id"><i class="fa fa-minus-circle"></i> Belum dikirim</span>
                      <?php endif; ?>
                    </td>

                    <td class="id-is-cell">
                      <?php if (!empty($r['id_imagingstudy'])): ?>
                        <span class="id-cell" title="<?= htmlspecialchars($r['id_imagingstudy']) ?>"><?= htmlspecialchars($shortIS) ?></span>
                        <button class="btn-copy" onclick="copyText('<?= addslashes($r['id_imagingstudy']) ?>')" title="Salin"><i class="fa fa-copy"></i></button>
                        <?php if (!empty($r['tgl_kirim_is'])): ?>
                        <div style="font-size:10px;color:#aaa;margin-top:2px;"><?= date('d/m H:i', strtotime($r['tgl_kirim_is'])) ?></div>
                        <?php endif; ?>
                      <?php elseif ($isErr): ?>
                        <span class="err-tooltip" title="<?= htmlspecialchars($r['error_msg_is']??'') ?>"><i class="fa fa-times-circle text-red"></i> Error IS</span>
                      <?php elseif (!empty($r['study_uid_dicom'])): ?>
                        <span style="font-size:10.5px;color:#f39c12;"><i class="fa fa-clock-o"></i> DICOM ada, menunggu SR</span>
                      <?php else: ?>
                        <span class="no-id"><i class="fa fa-minus-circle"></i> Belum ada DICOM</span>
                      <?php endif; ?>
                    </td>

                    <td class="text-center">
                      <div class="status-col">
                        <span class="badge-sr-status <?= $srOk?'badge-sr-ok':($srErr?'badge-sr-err':'badge-sr-pend') ?>">
                          <i class="fa fa-<?= $srOk?'check-circle':($srErr?'times-circle':'clock-o') ?>"></i>
                          SR <?= $srOk?'OK':($srErr?'Error':'Pending') ?>
                        </span>
                        <span class="badge-is-status <?= $isOk?'badge-is-ok':($isErr?'badge-is-err':'badge-is-pend') ?>">
                          <i class="fa fa-<?= $isOk?'image':($isErr?'times-circle':'clock-o') ?>"></i>
                          IS <?= $isOk?'OK':($isErr?'Error':'Pending') ?>
                        </span>
                      </div>
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
                $qBase = "tgl_dari=".urlencode($tgl_dari)."&tgl_sampai=".urlencode($tgl_sampai)."&cari=".urlencode($cari)."&status=".urlencode($filter_status)."&is=".urlencode($filter_is)."&limit=$limit";
                if ($page > 1): ?><li><a href="?page=<?=$page-1?>&<?=$qBase?>">«</a></li><?php endif;
                for ($i = max(1,$page-3); $i <= min($total_pages,$page+3); $i++):?>
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
                <?= ($cari||$filter_status||$filter_is)
                    ? "Tidak ada data untuk filter yang dipilih"
                    : "Tidak ada permintaan radiologi pada periode <strong>$periode_label</strong>" ?>
              </h4>
              <?php if ($cari||$filter_status||$filter_is): ?>
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