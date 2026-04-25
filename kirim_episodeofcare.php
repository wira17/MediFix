<?php
session_start();
include 'koneksi.php';
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// ── AJAX POST ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['kirim', 'kirim_semua'])) {
        include __DIR__ . '/api/kirim_episode_of_care.php';
        exit;
    }
    if ($action === 'kirim_encounter') {
        include __DIR__ . '/api/kirim_encounter.php';
        exit;
    }
    if ($action === 'sync_ihs') {
        $_POST['action'] = 'sync_satu';
        include __DIR__ . '/api/sync_ihs_pasien.php';
        exit;
    }
}

// ── Filter ────────────────────────────────────────────────────────
$tgl_dari     = $_GET['tgl_dari']   ?? date('Y-m-d');
$tgl_sampai   = $_GET['tgl_sampai'] ?? date('Y-m-d');
$cari         = $_GET['cari']       ?? '';
$filter_rawat = $_GET['rawat']      ?? '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_dari))   $tgl_dari   = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_sampai)) $tgl_sampai = date('Y-m-d');
if ($tgl_sampai < $tgl_dari) $tgl_sampai = $tgl_dari;
$limit  = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 20;
$page   = isset($_GET['page'])  ? max(1, intval($_GET['page']))  : 1;
$offset = ($page - 1) * $limit;

// ── Query — SAMA PERSIS dengan versi yang sudah bisa jalan ────────
try {
    $extraWhere  = '';
    $extraParams = [];
    if (!empty($cari)) {
        $extraWhere  = " AND (rp.no_rawat LIKE ? OR p.nm_pasien LIKE ? OR p.no_ktp LIKE ? OR dp.kd_penyakit LIKE ? OR py.nm_penyakit LIKE ?)";
        $extraParams = array_fill(0, 5, "%$cari%");
    }

    // Ralan: belum ada di satu_sehat_episode_of_care
    $queryRalan = "
        SELECT
            rp.no_rawat, rp.no_rkm_medis, rp.tgl_registrasi, rp.jam_reg,
            rp.stts, rp.status_lanjut,
            p.nm_pasien, p.no_ktp, p.tgl_lahir, p.jk,
            CONCAT(pr.tgl_perawatan,'T',pr.jam_rawat,'+07:00') AS tgl_pulang,
            pr.tgl_perawatan AS tgl_acuan,
            se.id_encounter,
            dp.kd_penyakit, dp.status AS status_dp,
            py.nm_penyakit,
            msp.ihs_number,
            'Ralan' AS jenis_rawat
        FROM reg_periksa rp
        JOIN pasien p                     ON rp.no_rkm_medis = p.no_rkm_medis
        JOIN pemeriksaan_ralan pr         ON pr.no_rawat      = rp.no_rawat
        LEFT JOIN satu_sehat_encounter se ON se.no_rawat      = rp.no_rawat
        JOIN diagnosa_pasien dp           ON dp.no_rawat      = rp.no_rawat AND dp.kd_penyakit LIKE 'O%'
        JOIN penyakit py                  ON py.kd_penyakit   = dp.kd_penyakit
        LEFT JOIN medifix_ss_pasien msp   ON p.no_rkm_medis   = msp.no_rkm_medis
        LEFT JOIN satu_sehat_episode_of_care seoc
            ON seoc.no_rawat    = rp.no_rawat
           AND seoc.kd_penyakit = dp.kd_penyakit
           AND seoc.status      = dp.status
        WHERE pr.tgl_perawatan BETWEEN ? AND ?
          AND (seoc.id_episode_of_care IS NULL OR seoc.id_episode_of_care = '')
        $extraWhere
    ";

    // Ranap: belum ada di satu_sehat_episode_of_care
    $queryRanap = "
        SELECT
            rp.no_rawat, rp.no_rkm_medis, rp.tgl_registrasi, rp.jam_reg,
            rp.stts, rp.status_lanjut,
            p.nm_pasien, p.no_ktp, p.tgl_lahir, p.jk,
            CONCAT(ki.tgl_keluar,'T',ki.jam_keluar,'+07:00') AS tgl_pulang,
            ki.tgl_keluar AS tgl_acuan,
            se.id_encounter,
            dp.kd_penyakit, dp.status AS status_dp,
            py.nm_penyakit,
            msp.ihs_number,
            'Ranap' AS jenis_rawat
        FROM reg_periksa rp
        JOIN pasien p                     ON rp.no_rkm_medis = p.no_rkm_medis
        JOIN kamar_inap ki                ON ki.no_rawat      = rp.no_rawat
        LEFT JOIN satu_sehat_encounter se ON se.no_rawat      = rp.no_rawat
        JOIN diagnosa_pasien dp           ON dp.no_rawat      = rp.no_rawat AND dp.kd_penyakit LIKE 'O%'
        JOIN penyakit py                  ON py.kd_penyakit   = dp.kd_penyakit
        LEFT JOIN medifix_ss_pasien msp   ON p.no_rkm_medis   = msp.no_rkm_medis
        LEFT JOIN satu_sehat_episode_of_care seoc
            ON seoc.no_rawat    = rp.no_rawat
           AND seoc.kd_penyakit = dp.kd_penyakit
           AND seoc.status      = dp.status
        WHERE ki.tgl_keluar BETWEEN ? AND ?
          AND (seoc.id_episode_of_care IS NULL OR seoc.id_episode_of_care = '')
        $extraWhere
    ";

    $paramsRalan = array_merge([$tgl_dari, $tgl_sampai], $extraParams);
    $paramsRanap = array_merge([$tgl_dari, $tgl_sampai], $extraParams);

    if ($filter_rawat === 'Ralan') {
        $unionSQL  = "($queryRalan)";
        $allParams = $paramsRalan;
    } elseif ($filter_rawat === 'Ranap') {
        $unionSQL  = "($queryRanap)";
        $allParams = $paramsRanap;
    } else {
        $unionSQL  = "($queryRalan) UNION ALL ($queryRanap)";
        $allParams = array_merge($paramsRalan, $paramsRanap);
    }

    // Count
    $stmtCount = $pdo_simrs->prepare("SELECT COUNT(*) FROM ($unionSQL) AS u");
    $stmtCount->execute($allParams);
    $total       = (int)$stmtCount->fetchColumn();
    $total_pages = max(1, ceil($total / $limit));

    // Data
    $stmtData = $pdo_simrs->prepare("
        SELECT * FROM ($unionSQL) AS u
        ORDER BY tgl_acuan DESC, tgl_registrasi DESC
        LIMIT " . intval($limit) . " OFFSET " . intval($offset)
    );
    $stmtData->execute($allParams);
    $data = $stmtData->fetchAll(PDO::FETCH_ASSOC);

    // Stats total semua yang belum dikirim di periode ini
    $stmtStats = $pdo_simrs->prepare("
        SELECT COUNT(*) AS belum
        FROM (
            SELECT rp.no_rawat
            FROM reg_periksa rp
            JOIN pemeriksaan_ralan pr ON pr.no_rawat = rp.no_rawat
            JOIN diagnosa_pasien dp   ON dp.no_rawat = rp.no_rawat AND dp.kd_penyakit LIKE 'O%'
            LEFT JOIN satu_sehat_episode_of_care seoc
                ON seoc.no_rawat = rp.no_rawat AND seoc.kd_penyakit = dp.kd_penyakit AND seoc.status = dp.status
            WHERE pr.tgl_perawatan BETWEEN ? AND ?
              AND (seoc.id_episode_of_care IS NULL OR seoc.id_episode_of_care = '')
            UNION ALL
            SELECT rp.no_rawat
            FROM reg_periksa rp
            JOIN kamar_inap ki        ON ki.no_rawat = rp.no_rawat
            JOIN diagnosa_pasien dp   ON dp.no_rawat = rp.no_rawat AND dp.kd_penyakit LIKE 'O%'
            LEFT JOIN satu_sehat_episode_of_care seoc
                ON seoc.no_rawat = rp.no_rawat AND seoc.kd_penyakit = dp.kd_penyakit AND seoc.status = dp.status
            WHERE ki.tgl_keluar BETWEEN ? AND ?
              AND (seoc.id_episode_of_care IS NULL OR seoc.id_episode_of_care = '')
        ) AS s
    ");
    $stmtStats->execute([$tgl_dari, $tgl_sampai, $tgl_dari, $tgl_sampai]);
    $st_belum = (int)$stmtStats->fetchColumn();
    $dbError  = null;

} catch (Exception $e) {
    $data = []; $total = 0; $total_pages = 1; $st_belum = 0;
    $dbError = $e->getMessage();
}

$tgl_dari_fmt   = date('d F Y', strtotime($tgl_dari));
$tgl_sampai_fmt = date('d F Y', strtotime($tgl_sampai));
$periode_label  = $tgl_dari === $tgl_sampai ? $tgl_dari_fmt : "$tgl_dari_fmt s/d $tgl_sampai_fmt";
$page_title     = 'Episode of Care (ANC) — Satu Sehat';

$extra_css = '
/* ── Seragam dengan data_service_request.php ── */
.btn-send{width:32px;height:32px;border-radius:6px;padding:0;display:inline-flex;align-items:center;justify-content:center;border:1px solid transparent;cursor:pointer;transition:all .25s;font-size:12px;background:#00897b;border-color:#007a6e;color:#fff}
.btn-send:hover{background:#007a6e;transform:scale(1.12);box-shadow:0 3px 10px rgba(0,137,123,.45);color:#fff}
.btn-send:disabled{opacity:.5;cursor:not-allowed;transform:none}
.btn-send.sent{background:#00a65a;border-color:#008d4c}
.btn-send.sent:hover{background:#008d4c}
.btn-send.error-st{background:#dd4b39;border-color:#c23321}
.btn-send.spin i{animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

.badge-anc{background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;font-family:"Courier New",monospace}
.badge-ralan{background:#d9edf7;color:#31708f;border:1px solid #bce8f1;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700}
.badge-ranap{background:#fcf8e3;color:#8a6d3b;border:1px solid #faebcc;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700}
.badge-pend{background:#fcf8e3;color:#8a6d3b;border:1px solid #faebcc;padding:3px 9px;border-radius:10px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px}

.row-sent{background:#f0fff4!important}

.btn-copy{width:22px;height:22px;border-radius:4px;padding:0;display:inline-flex;align-items:center;justify-content:center;background:#ecf0f1;border:1px solid #ddd;color:#777;cursor:pointer;transition:all .2s;font-size:10px;vertical-align:middle;margin-left:3px}
.btn-copy:hover{background:#d5d8dc;color:#333}

.norawat-lbl{font-weight:700;color:#00897b;font-size:13px;font-family:"Courier New",monospace}
.rm-lbl{font-size:10.5px;color:#aaa;font-family:"Courier New",monospace}
.nm-pasien{font-weight:700;color:#333;font-size:13px}
.waktu-lbl{font-size:13px;font-weight:600;color:#333}
.waktu-sub{font-size:10.5px;color:#aaa;margin-top:2px}
.no-id{font-style:italic;color:#ccc;font-size:11px}
.id-cell{font-family:"Courier New",monospace;font-size:10.5px;color:#555;word-break:break-all}
.enc-ok{color:#1565c0;font-size:10px}
.enc-miss{color:#f39c12;font-size:10px}
.btn-kirim-enc{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:4px;font-size:10.5px;font-weight:600;cursor:pointer;background:linear-gradient(135deg,#e67e22,#d35400);border:1px solid #c0392b;color:#fff;transition:all .2s;white-space:nowrap}
.btn-kirim-enc:hover{background:linear-gradient(135deg,#d35400,#c0392b);transform:scale(1.05);box-shadow:0 2px 8px rgba(211,84,0,.4)}
.btn-kirim-enc:disabled{opacity:.5;cursor:not-allowed;transform:none}

.ihs-ok{color:#00a65a;font-size:10px;font-weight:700}
.ihs-miss{color:#dd4b39;font-size:10px}
.btn-ihs{width:22px;height:22px;border-radius:4px;padding:0;display:inline-flex;align-items:center;justify-content:center;background:#f39c12;border:1px solid #e67e22;color:#fff;cursor:pointer;transition:all .2s;font-size:10px;vertical-align:middle;margin-left:3px}
.btn-ihs:hover{background:#e67e22;transform:scale(1.1)}

.action-bar{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.btn-kirim-semua{background:linear-gradient(135deg,#00897b,#00695c);border:none;color:#fff;padding:6px 14px;border-radius:5px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .25s}
.btn-kirim-semua:hover{transform:translateY(-2px);box-shadow:0 4px 14px rgba(0,137,123,.4);color:#fff}
.btn-kirim-semua:disabled{opacity:.6;cursor:not-allowed;transform:none}

.info-bar-stats{display:flex;gap:16px;padding:8px 15px;background:#f0fff4;border-bottom:1px solid #e5e5e5;font-size:12px;flex-wrap:wrap;align-items:center}
.ibs-item{display:flex;align-items:center;gap:5px;color:#555}
.ibs-val{font-weight:700;font-size:14px}
.ibs-sep{color:#ddd}

.prog-dual{padding:10px 15px 6px;background:#f9f9f9;border-bottom:1px solid #e5e5e5}
.prog-row{display:flex;align-items:center;gap:10px;font-size:12px;margin-bottom:4px}
.prog-label{width:140px;color:#555;flex-shrink:0}
.prog-bar{flex:1;height:7px;border-radius:4px;background:#e5e5e5;overflow:hidden}
.prog-fill-eoc{height:100%;border-radius:4px;background:linear-gradient(90deg,#00897b,#00a65a);transition:width .6s}
.prog-pct{width:38px;text-align:right;font-weight:700;color:#555;flex-shrink:0}

.tbl-eoc thead tr th{background:#00897b;color:#fff!important;white-space:nowrap;font-size:12px}
.tbl-eoc tbody td{vertical-align:middle}

.date-range-wrap{display:flex;align-items:center;gap:6px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;padding:5px 10px}
.date-range-wrap label{font-size:12px;color:#666;margin:0;white-space:nowrap}
.date-range-wrap input{border:none;background:transparent;font-size:13px;color:#333;padding:0;outline:none;width:130px}
.date-range-sep{color:#aaa;font-size:12px}
.btn-period{padding:3px 10px;border-radius:4px;font-size:11px;border:1px solid #dee2e6;background:#fff;color:#555;cursor:pointer;transition:all .2s}
.btn-period:hover{background:#00897b;color:#fff;border-color:#00897b}

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
            btnEl.closest('.ihs-wrap').innerHTML =
                `<span class="ihs-ok" title="${resp.ihs_number}"><i class="fa fa-id-card"></i> IHS OK</span>`;
            showToast('IHS ditemukan: ' + resp.ihs_number, 'success');
        } else {
            btnEl.disabled = false;
            btnEl.innerHTML = origHTML;
            showToast('Gagal sync IHS: ' + (resp.message || ''), 'error');
        }
    })
    .catch(() => { btnEl.disabled = false; btnEl.innerHTML = origHTML; showToast('Koneksi gagal', 'error'); });
}

function kirimSatu(noRawat, kdPenyakit, statusDiagnosa, btnEl) {
    btnEl.disabled = true;
    const origHTML = btnEl.innerHTML;
    btnEl.classList.add("spin");
    btnEl.innerHTML = `<i class="fa fa-spinner"></i>`;

    fetch(window.location.href, {
        method: "POST",
        headers: {"Content-Type":"application/x-www-form-urlencoded"},
        body: new URLSearchParams({action:"kirim", no_rawat:noRawat, kd_penyakit:kdPenyakit, status_diagnosa:statusDiagnosa})
    })
    .then(r => r.json())
    .then(resp => {
        btnEl.classList.remove("spin");
        if (resp.status === "ok") {
            btnEl.classList.add("sent");
            btnEl.innerHTML = `<i class="fa fa-check"></i>`;
            btnEl.title = "Terkirim — " + (resp.id_eoc || "");
            showToast("✓ EpisodeOfCare berhasil dikirim!", "success");
            const row = btnEl.closest("tr");
            if (row) {
                row.classList.add("row-sent");
                const badge = row.querySelector(".badge-status-cell");
                if (badge && resp.id_eoc) {
                    badge.innerHTML = `<span style="background:#dff0d8;color:#3c763d;border:1px solid #c3e6cb;padding:3px 9px;border-radius:10px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px"><i class="fa fa-check-circle"></i> Terkirim</span>`;
                }
            }
            const ep = document.getElementById("cntBelum");
            if (ep) ep.textContent = Math.max(0, parseInt(ep.textContent.replace(/\D/g,'')) - 1);
        } else {
            btnEl.disabled = false;
            btnEl.classList.add("error-st");
            btnEl.innerHTML = `<i class="fa fa-exclamation-triangle"></i>`;
            btnEl.title = "Gagal: " + (resp.message || "");
            showToast("Gagal: " + (resp.message || ""), "error");
        }
    })
    .catch(() => {
        btnEl.disabled = false; btnEl.innerHTML = origHTML; btnEl.classList.remove("spin");
        showToast("Koneksi ke server gagal", "error");
    });
}

function kirimEncounter(noRawat, btnEl) {
    if (!confirm("Kirim Encounter untuk No. Rawat " + noRawat + "?\n\nEncounter harus ada sebelum EpisodeOfCare bisa dikirim.")) return;
    btnEl.disabled = true;
    const origHTML = btnEl.innerHTML;
    btnEl.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';

    fetch(window.location.href, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({action: 'kirim_encounter', no_rawat: noRawat})
    })
    .then(r => r.json())
    .then(resp => {
        if (resp.status === 'ok') {
            const idEnc = resp.id_encounter || '';
            // Update tampilan kolom encounter di baris ini
            btnEl.closest('.enc-wrap').innerHTML =
                `<div class="enc-ok"><i class="fa fa-check-circle"></i> Encounter OK</div>
                 <div style="font-family:'Courier New',monospace;font-size:9.5px;color:#aaa;margin-top:1px;">${idEnc.substring(0,20)}…</div>`;
            showToast('✓ Encounter berhasil dikirim! Sekarang bisa kirim EpisodeOfCare.', 'success');
        } else {
            btnEl.disabled = false;
            btnEl.innerHTML = origHTML;
            showToast('Gagal kirim Encounter: ' + (resp.message || ''), 'error');
        }
    })
    .catch(() => {
        btnEl.disabled = false;
        btnEl.innerHTML = origHTML;
        showToast('Koneksi gagal', 'error');
    });
}

function kirimSemua() {
    const dari   = document.getElementById("tgl_dari").value;
    const sampai = document.getElementById("tgl_sampai").value;
    const sisa   = parseInt(document.getElementById("cntBelum")?.textContent?.replace(/\D/g,'') || "0");
    if (sisa === 0) { showToast("Semua sudah terkirim!", "info"); return; }
    if (!confirm(`Kirim ${sisa} Episode of Care yang belum terkirim ke Satu Sehat?\nPeriode: ${dari === sampai ? dari : dari + " s/d " + sampai}`)) return;

    const btn = document.getElementById("btnKirimSemua");
    if (btn) { btn.disabled = true; btn.innerHTML = `<i class="fa fa-spinner fa-spin"></i> Mengirim…`; }

    fetch(window.location.href, {
        method: "POST",
        headers: {"Content-Type":"application/x-www-form-urlencoded"},
        body: new URLSearchParams({action:"kirim_semua", tgl_dari:dari, tgl_sampai:sampai})
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
      <i class="fa fa-heartbeat" style="color:#00897b;"></i>
      Episode of Care
      <small>ANC  &mdash; <?= $periode_label ?></small>
    </h1>
    <ol class="breadcrumb">
      <li><a href="dashboard.php"><i class="fa fa-dashboard"></i> Home</a></li>
      <li>Satu Sehat</li>
      <li class="active">Episode of Care</li>
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
                <input type="text" name="cari" class="form-control"
                       placeholder="Pasien / No. Rawat / Kode Dx…"
                       value="<?= htmlspecialchars($cari) ?>" style="width:220px;">
              </div>
              <div class="form-group">
                <label style="margin-right:5px;">Jenis:</label>
                <select name="rawat" class="form-control">
                  <option value=""      <?= $filter_rawat===''      ?'selected':'' ?>>Ralan + Ranap</option>
                  <option value="Ralan" <?= $filter_rawat==='Ralan' ?'selected':'' ?>>Ralan saja</option>
                  <option value="Ranap" <?= $filter_rawat==='Ranap' ?'selected':'' ?>>Ranap saja</option>
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
              <a href="kirim_episodeofcare.php" class="btn btn-default"><i class="fa fa-refresh"></i> Reset</a>
            </form>
          </div>
        </div>

        <!-- Tabel -->
        <div class="box box-primary" style="border-top-color:#00897b;">
          <div class="box-header with-border">
            <h3 class="box-title">
              <i class="fa fa-table"></i> Data Episode of Care — Belum Terkirim
              <span class="badge" style="background:#00897b;"><?= number_format($total) ?></span>
            </h3>
            <div class="box-tools pull-right">
              <div class="action-bar">
                <button id="btnKirimSemua" onclick="kirimSemua()" class="btn-kirim-semua" <?= $st_belum===0?'disabled':'' ?>>
                  <i class="fa fa-send"></i> Kirim Semua Pending
                  <?php if ($st_belum > 0): ?>
                  <span class="badge" style="background:#dd4b39;"><?= number_format($st_belum) ?></span>
                  <?php endif; ?>
                </button>
                <a href="?tgl_dari=<?= urlencode($tgl_dari) ?>&tgl_sampai=<?= urlencode($tgl_sampai) ?>&cari=<?= urlencode($cari) ?>&rawat=<?= urlencode($filter_rawat) ?>&limit=<?= $limit ?>"
                   class="btn btn-default btn-sm" title="Refresh"><i class="fa fa-refresh"></i></a>
              </div>
            </div>
          </div>

          <!-- Stats bar -->
          <div class="info-bar-stats">
            <div class="ibs-item">
              <i class="fa fa-clock-o" style="color:#f39c12;"></i>
              Belum Terkirim: <span class="ibs-val" style="color:#f39c12;" id="cntBelum"><?= number_format($st_belum) ?></span>
            </div>
            <span class="ibs-sep">|</span>
            <div class="ibs-item" style="color:#777;font-size:11px;">
              <i class="fa fa-info-circle"></i>
              Menampilkan pasien dengan diagnosa kode O (Kehamilan) yang belum dikirim ke Satu Sehat
            </div>
          </div>

          <!-- Progress bar -->
          <div class="prog-dual">
            <div class="prog-row">
              <span class="prog-label"><i class="fa fa-heartbeat"></i> Episode of Care</span>
              <div class="prog-bar">
                <?php
                  // Hitung total untuk progress (terkirim = total semua - belum)
                  $stmtTotalAll = $pdo_simrs->prepare("
                    SELECT COUNT(*) FROM (
                        SELECT rp.no_rawat FROM reg_periksa rp
                        JOIN pemeriksaan_ralan pr ON pr.no_rawat = rp.no_rawat
                        JOIN diagnosa_pasien dp   ON dp.no_rawat = rp.no_rawat AND dp.kd_penyakit LIKE 'O%'
                        WHERE pr.tgl_perawatan BETWEEN ? AND ?
                        UNION ALL
                        SELECT rp.no_rawat FROM reg_periksa rp
                        JOIN kamar_inap ki         ON ki.no_rawat = rp.no_rawat
                        JOIN diagnosa_pasien dp    ON dp.no_rawat = rp.no_rawat AND dp.kd_penyakit LIKE 'O%'
                        WHERE ki.tgl_keluar BETWEEN ? AND ?
                    ) AS t
                  ");
                  $stmtTotalAll->execute([$tgl_dari, $tgl_sampai, $tgl_dari, $tgl_sampai]);
                  $grand_total = (int)$stmtTotalAll->fetchColumn();
                  $st_terkirim = max(0, $grand_total - $st_belum);
                  $pct_eoc = $grand_total > 0 ? round(($st_terkirim / $grand_total) * 100) : 0;
                ?>
                <div class="prog-fill-eoc" style="width:<?= $pct_eoc ?>%;"></div>
              </div>
              <span class="prog-pct"><?= $pct_eoc ?>%</span>
            </div>
          </div>

          <div class="box-body" style="padding:0;">
            <?php if (!empty($data)): ?>
            <div class="table-responsive">
              <table class="table table-bordered table-striped table-hover tbl-eoc" style="margin-bottom:0;font-size:12.5px;">
                <thead>
                  <tr>
                    <th width="36"  class="text-center">#</th>
                    <th width="40"  class="text-center">Kirim</th>
                    <th width="155">No. Rawat</th>
                    <th width="185">Pasien</th>
                    <th width="165">Kode Dx / Diagnosa</th>
                    <th width="80"  class="text-center">Jenis</th>
                    <th width="80"  class="text-center">Status Dx</th>
                    <th width="100">Tgl Registrasi</th>
                    <th width="115">Tgl Perawatan</th>
                    <th width="120" class="text-center">Status</th>
                  </tr>
                </thead>
                <tbody>
                <?php $no = $offset + 1; foreach ($data as $r):
                    $umur = '';
                    if (!empty($r['tgl_lahir'])) {
                        try { $umur = (new DateTime())->diff(new DateTime($r['tgl_lahir']))->y . ' th'; } catch(Exception $e){}
                    }
                ?>
                <tr>
                  <td class="text-center" style="color:#aaa;font-size:11px;font-weight:600;"><?= $no++ ?></td>

                  <td class="text-center">
                    <button class="btn-send"
                            onclick="kirimSatu('<?= addslashes($r['no_rawat']) ?>','<?= addslashes($r['kd_penyakit']) ?>','<?= addslashes($r['status_dp']) ?>',this)"
                            title="Kirim EpisodeOfCare ke Satu Sehat">
                      <i class="fa fa-send"></i>
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
                      <?= !empty($r['jk']) ? ' · '.htmlspecialchars($r['jk']) : '' ?>
                    </div>
                    <div class="ihs-wrap" style="margin-top:2px;">
                      <?php if (!empty($r['ihs_number'])): ?>
                        <span class="ihs-ok" title="IHS: <?= htmlspecialchars($r['ihs_number']) ?>">
                          <i class="fa fa-id-card"></i> IHS OK
                        </span>
                      <?php else: ?>
                        <button class="btn-ihs"
                                onclick="syncIHS('<?= addslashes($r['no_rkm_medis']) ?>',this)"
                                title="Sync IHS Number">
                          <i class="fa fa-refresh"></i>
                        </button>
                        <span class="ihs-miss"><i class="fa fa-exclamation-triangle"></i> No IHS</span>
                      <?php endif; ?>
                    </div>
                  </td>

                  <td>
                    <span class="badge-anc"><?= htmlspecialchars($r['kd_penyakit']) ?></span>
                    <div style="font-size:10.5px;color:#555;margin-top:3px;line-height:1.3;">
                      <?= htmlspecialchars($r['nm_penyakit'] ?: '-') ?>
                    </div>
                  </td>

                  <td class="text-center">
                    <span class="badge-<?= strtolower($r['jenis_rawat']) === 'ranap' ? 'ranap' : 'ralan' ?>">
                      <?= htmlspecialchars($r['jenis_rawat']) ?>
                    </span>
                  </td>

                  <td class="text-center">
                    <span style="font-size:11px;color:#555;"><?= htmlspecialchars($r['status_dp'] ?: '-') ?></span>
                  </td>

                  <td>
                    <div class="waktu-lbl"><?= date('d/m/Y', strtotime($r['tgl_registrasi'])) ?></div>
                    <div class="waktu-sub"><?= date('H:i', strtotime($r['jam_reg'])) ?> WIB</div>
                  </td>

                  <td>
                    <?php if (!empty($r['tgl_acuan']) && $r['tgl_acuan'] !== '0000-00-00'): ?>
                    <div class="waktu-lbl"><?= date('d/m/Y', strtotime($r['tgl_acuan'])) ?></div>
                    <?php endif; ?>
                    <div class="enc-wrap" style="margin-top:2px;">
                      <?php if (!empty($r['id_encounter'])): ?>
                        <div class="enc-ok"><i class="fa fa-check-circle"></i> Encounter OK</div>
                        <div style="font-family:'Courier New',monospace;font-size:9.5px;color:#aaa;margin-top:1px;"><?= htmlspecialchars(substr($r['id_encounter'],0,20)) ?>…</div>
                      <?php else: ?>
                        <div class="enc-miss" style="margin-bottom:3px;"><i class="fa fa-warning"></i> No Encounter</div>
                        <button class="btn-kirim-enc"
                                onclick="kirimEncounter('<?= addslashes($r['no_rawat']) ?>',this)"
                                title="Kirim Encounter ke Satu Sehat terlebih dahulu">
                          <i class="fa fa-send"></i> Kirim Encounter
                        </button>
                      <?php endif; ?>
                    </div>
                  </td>

                  <td class="text-center badge-status-cell">
                    <span class="badge-pend">
                      <i class="fa fa-clock-o"></i> Belum Terkirim
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
                $qBase = "tgl_dari=".urlencode($tgl_dari)."&tgl_sampai=".urlencode($tgl_sampai)
                        ."&cari=".urlencode($cari)."&rawat=".urlencode($filter_rawat)."&limit=$limit";
                if ($page > 1): ?><li><a href="?page=<?=$page-1?>&<?=$qBase?>">«</a></li><?php endif;
                for ($i=max(1,$page-3); $i<=min($total_pages,$page+3); $i++): ?>
                <li <?=$i==$page?'class="active"':''?>><a href="?page=<?=$i?>&<?=$qBase?>"><?=$i?></a></li>
                <?php endfor;
                if ($page < $total_pages): ?><li><a href="?page=<?=$page+1?>&<?=$qBase?>">»</a></li><?php endif; ?>
              </ul>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <div style="padding:50px;text-align:center;">
              <i class="fa fa-check-circle" style="font-size:52px;color:#00a65a;display:block;margin-bottom:14px;"></i>
              <h4 style="color:#00a65a;font-weight:600;">Semua sudah terkirim!</h4>
              <p style="color:#aaa;">
                <?= ($cari || $filter_rawat)
                    ? "Tidak ada data untuk filter yang dipilih"
                    : "Tidak ada Episode of Care yang belum dikirim pada periode <strong>$periode_label</strong>" ?>
              </p>
              <?php if ($cari || $filter_rawat): ?>
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