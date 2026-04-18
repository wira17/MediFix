<?php
/**
 * data_medication.php
 * Monitoring & pengiriman Medication ke Satu Sehat FHIR API
 * Tabel: satu_sehat_medication (Khanza) + databarang
 * Medication = data master obat/alkes ke Satu Sehat
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
        include __DIR__ . '/api/kirim_medication.php';
        exit;
    }
}

// ── Filter & Pagination ──────────────────────────────────────────
$cari          = $_GET['cari']   ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_jns    = $_GET['jns']    ?? '';
$limit         = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 20;
$page          = isset($_GET['page'])  ? max(1, intval($_GET['page']))  : 1;
$offset        = ($page - 1) * $limit;

// ── Ambil Data ───────────────────────────────────────────────────
try {
    $wheres = ['1=1'];
    $params = [];

    if (!empty($cari)) {
        $wheres[] = "(m.kode_brng LIKE ? OR db.nama_brng LIKE ? OR m.id_medication LIKE ?)";
        $params   = array_merge($params, ["%$cari%", "%$cari%", "%$cari%"]);
    }
    if ($filter_status === 'terkirim') {
        $wheres[] = "(m.id_medication IS NOT NULL AND m.id_medication != '')";
    } elseif ($filter_status === 'pending') {
        $wheres[] = "(m.id_medication IS NULL OR m.id_medication = '')";
    }
    if (!empty($filter_jns)) {
        $wheres[] = "db.kdjns = ?";
        $params[] = $filter_jns;
    }

    $where_sql = "WHERE " . implode(" AND ", $wheres);

    $base_join = "
        FROM satu_sehat_medication m
        LEFT JOIN databarang db ON m.kode_brng = db.kode_brng
    ";

    $stmtCount = $pdo_simrs->prepare("SELECT COUNT(*) $base_join $where_sql");
    $stmtCount->execute($params);
    $total       = (int)$stmtCount->fetchColumn();
    $total_pages = max(1, ceil($total / $limit));

    $stmtData = $pdo_simrs->prepare(
        "SELECT
            m.kode_brng, m.id_medication,
            db.nama_brng, db.kdjns, db.kode_satbesar, db.kode_sat,
            db.isi, db.kapasitas, db.status AS status_brng,
            db.kode_industri, db.kode_kategori, db.kode_golongan,
            db.ralan, db.h_beli, db.expire
         $base_join $where_sql
         ORDER BY db.nama_brng ASC
         LIMIT " . intval($limit) . " OFFSET " . intval($offset)
    );
    $stmtData->execute($params);
    $data = $stmtData->fetchAll(PDO::FETCH_ASSOC);

    // Stats
    $stmtStats = $pdo_simrs->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN id_medication IS NOT NULL AND id_medication != '' THEN 1 ELSE 0 END) AS terkirim,
            SUM(CASE WHEN id_medication IS NULL OR id_medication = '' THEN 1 ELSE 0 END) AS pending
        FROM satu_sehat_medication
    ");
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
$page_title = 'Medication — Satu Sehat';

$extra_css = '
:root { --med-primary:#2e7d32; --med-dark:#1b5e20; --med-soft:#e8f5e9; --med-border:#c8e6c9; }

.btn-send{width:32px;height:32px;border-radius:6px;padding:0;display:inline-flex;align-items:center;justify-content:center;border:1px solid transparent;cursor:pointer;transition:all .25s;font-size:12px;background:var(--med-primary);border-color:var(--med-dark);color:#fff}
.btn-send:hover{background:var(--med-dark);transform:scale(1.12);box-shadow:0 3px 10px rgba(46,125,50,.4);color:#fff}
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
.badge-aktif{background:#dff0d8;color:#3c763d;border:1px solid #c3e6cb;padding:2px 7px;border-radius:6px;font-size:10px;font-weight:700}
.badge-nonaktif{background:#f5f5f5;color:#777;border:1px solid #ddd;padding:2px 7px;border-radius:6px;font-size:10px;font-weight:700}
.row-sent{background:#f0fff4!important}

.kode-lbl{font-weight:700;color:var(--med-primary);font-size:12px;font-family:"Courier New",monospace}
.nama-brng{font-weight:700;color:#333;font-size:13px}
.sub-lbl{font-size:10.5px;color:#aaa;margin-top:2px}
.sat-lbl{display:inline-block;background:var(--med-soft);border:1px solid var(--med-border);color:var(--med-primary);padding:1px 6px;border-radius:4px;font-size:10px;font-weight:700}

.action-bar{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.btn-kirim-semua{background:linear-gradient(135deg,var(--med-primary),var(--med-dark));border:none;color:#fff;padding:6px 14px;border-radius:5px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .25s}
.btn-kirim-semua:hover{transform:translateY(-2px);box-shadow:0 4px 14px rgba(46,125,50,.4);color:#fff}
.btn-kirim-semua:disabled{opacity:.6;cursor:not-allowed;transform:none}

.info-bar{display:flex;gap:16px;padding:8px 15px;background:#f1f8f1;border-bottom:1px solid var(--med-border);font-size:12px;flex-wrap:wrap;align-items:center}
.ibs-item{display:flex;align-items:center;gap:5px;color:#555}
.ibs-val{font-weight:700;font-size:14px}
.ibs-sep{color:#ddd}

.prog-row{display:flex;align-items:center;gap:10px;font-size:12px;padding:8px 15px;background:#f9f9f9;border-bottom:1px solid #eee}
.prog-bar{flex:1;height:7px;border-radius:4px;background:#e5e5e5;overflow:hidden}
.prog-fill{height:100%;border-radius:4px;background:linear-gradient(90deg,var(--med-primary),#00c0ef);transition:width .6s}
.prog-pct{width:38px;text-align:right;font-weight:700;color:#555}

.tbl-med thead tr th{background:var(--med-primary);color:#fff!important;white-space:nowrap;font-size:12px}
.tbl-med tbody td{vertical-align:middle}

.callout-info-med{background:var(--med-soft);border-left:4px solid var(--med-primary);padding:10px 15px;border-radius:4px;margin-bottom:15px;font-size:13px;color:#333}

#toast-container{position:fixed;bottom:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.toast-msg{padding:10px 18px;border-radius:6px;font-size:13px;font-weight:600;color:#fff;display:flex;align-items:center;gap:8px;box-shadow:0 4px 12px rgba(0,0,0,.2);pointer-events:auto;animation:toastIn .3s ease}
.toast-success{background:#00a65a}.toast-error{background:#dd4b39}.toast-info{background:#00c0ef}.toast-warn{background:#f39c12}
@keyframes toastIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
';

$extra_js = '
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
function kirimSatu(kodeBrng, btnEl) {
    btnEl.disabled = true;
    const origHTML = btnEl.innerHTML;
    btnEl.classList.add("spin");
    btnEl.innerHTML = `<i class="fa fa-spinner"></i>`;
    fetch(window.location.href, {
        method: "POST",
        headers: {"Content-Type":"application/x-www-form-urlencoded"},
        body: new URLSearchParams({action:"kirim", kode_brng: kodeBrng})
    })
    .then(r => r.json())
    .then(resp => {
        btnEl.classList.remove("spin");
        if (resp.status === "ok") {
            btnEl.classList.add("sent");
            btnEl.innerHTML = `<i class="fa fa-check"></i>`;
            btnEl.title = "Terkirim — " + (resp.id_medication || "");
            showToast("Medication berhasil dikirim!", "success");
            const row = btnEl.closest("tr");
            if (row) {
                const b = row.querySelector(".badge-status");
                if (b) { b.className = "badge-status badge-sent"; b.innerHTML = `<i class="fa fa-check-circle"></i> Terkirim`; }
                row.classList.add("row-sent");
                const idCell = row.querySelector(".id-med-cell");
                if (idCell && resp.id_medication) {
                    idCell.innerHTML = `<span class="id-cell" title="${resp.id_medication}">${resp.id_medication.substring(0,36)}</span>
                        <button class="btn-copy" onclick="copyText(\'${resp.id_medication}\')" title="Salin"><i class="fa fa-copy"></i></button>`;
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
    if (!confirm("Kirim semua Medication yang pending ke Satu Sehat?\n\nProses ini mungkin butuh beberapa menit jika data banyak.")) return;
    const btn = document.getElementById("btnKirimSemua");
    if (btn) { btn.disabled = true; btn.innerHTML = `<i class="fa fa-spinner fa-spin"></i> Mengirim…`; }
    fetch(window.location.href, {
        method: "POST",
        headers: {"Content-Type":"application/x-www-form-urlencoded"},
        body: new URLSearchParams({action:"kirim_semua"})
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
      <i class="fa fa-pills" style="color:#2e7d32;"></i>
      Medication
      <small>Satu Sehat &mdash; Master Data Obat &amp; Alkes</small>
    </h1>
    <ol class="breadcrumb">
      <li><a href="dashboard.php"><i class="fa fa-dashboard"></i> Home</a></li>
      <li>Satu Sehat</li>
      <li class="active">Medication</li>
    </ol>
  </section>

  <section class="content">

    <?php if ($dbError): ?>
    <div class="callout callout-danger">
      <h4><i class="fa fa-ban"></i> Error Database</h4>
      <p><?= htmlspecialchars($dbError) ?></p>
    </div>
    <?php endif; ?>

    <div class="callout-info-med">
      <i class="fa fa-info-circle" style="color:#2e7d32;"></i>
      <strong>Medication</strong> adalah resource master data obat/alkes yang dikirim ke Satu Sehat.
      Berbeda dengan Medication Request/Dispense — Medication berisi informasi <em>produk obat</em>, bukan transaksi pemberian obat ke pasien.
    </div>

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
                <label style="margin-right:5px;">Cari:</label>
                <input type="text" name="cari" class="form-control" placeholder="Kode / Nama Barang / ID Medication…" value="<?= htmlspecialchars($cari) ?>" style="width:240px;">
              </div>
              <div class="form-group">
                <label style="margin-right:5px;">Status:</label>
                <select name="status" class="form-control">
                  <option value=""         <?= $filter_status===''         ?'selected':'' ?>>Semua</option>
                  <option value="terkirim" <?= $filter_status==='terkirim' ?'selected':'' ?>>Terkirim</option>
                  <option value="pending"  <?= $filter_status==='pending'  ?'selected':'' ?>>Pending</option>
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
              <button type="submit" class="btn btn-success"><i class="fa fa-search"></i> Tampilkan</button>
              <a href="data_medication.php" class="btn btn-default"><i class="fa fa-refresh"></i> Reset</a>
            </form>
          </div>
        </div>

        <!-- Tabel -->
        <div class="box" style="border-top:3px solid var(--med-primary);">
          <div class="box-header with-border">
            <h3 class="box-title" style="color:var(--med-primary);">
              <i class="fa fa-table"></i> Data Medication
              <span class="badge" style="background:var(--med-primary);"><?= number_format($total) ?></span>
            </h3>
            <div class="box-tools pull-right">
              <div class="action-bar">
                <button id="btnKirimSemua" onclick="kirimSemua()" class="btn-kirim-semua" <?= $st_pending===0?'disabled':'' ?>>
                  <i class="fa fa-send"></i> Kirim Semua Pending
                  <?php if ($st_pending > 0): ?>
                  <span class="badge" style="background:#dd4b39;"><?= number_format($st_pending) ?></span>
                  <?php endif; ?>
                </button>
                <a href="?cari=<?= urlencode($cari) ?>&status=<?= urlencode($filter_status) ?>&limit=<?= $limit ?>"
                   class="btn btn-default btn-sm" title="Refresh"><i class="fa fa-refresh"></i></a>
              </div>
            </div>
          </div>

          <!-- Stats bar -->
          <div class="info-bar">
            <div class="ibs-item"><i class="fa fa-database" style="color:var(--med-primary);"></i> Total: <span class="ibs-val" style="color:var(--med-primary);"><?= number_format($st_total) ?></span></div>
            <span class="ibs-sep">|</span>
            <div class="ibs-item"><i class="fa fa-check-circle" style="color:#00a65a;"></i> Terkirim: <span class="ibs-val" style="color:#00a65a;" id="ibsTerkirim"><?= number_format($st_terkirim) ?></span></div>
            <div class="ibs-item"><i class="fa fa-clock-o" style="color:#f39c12;"></i> Pending: <span class="ibs-val" style="color:#f39c12;" id="ibsPending"><?= number_format($st_pending) ?></span></div>
          </div>

          <!-- Progress bar -->
          <div class="prog-row">
            <span style="width:100px;font-size:12px;color:#555;flex-shrink:0;"><i class="fa fa-medkit"></i> Medication</span>
            <div class="prog-bar"><div class="prog-fill" style="width:<?= $pct ?>%;"></div></div>
            <span class="prog-pct"><?= $pct ?>%</span>
          </div>

          <div class="box-body" style="padding:0;">
            <?php if (!empty($data)): ?>
            <div class="table-responsive">
              <table class="table table-bordered table-striped table-hover tbl-med" style="margin-bottom:0;font-size:12.5px;">
                <thead>
                  <tr>
                    <th width="36"  class="text-center">#</th>
                    <th width="40"  class="text-center">Kirim</th>
                    <th width="130">Kode Barang</th>
                    <th>Nama Barang</th>
                    <th width="80"  class="text-center">Satuan</th>
                    <th width="80"  class="text-center">Isi</th>
                    <th width="80"  class="text-center">Status</th>
                    <th>ID Medication</th>
                    <th width="120" class="text-center">Status Kirim</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $no = $offset + 1;
                  foreach ($data as $r):
                      $isSent  = !empty($r['id_medication']);
                      $shortId = $isSent ? mb_strimwidth($r['id_medication'], 0, 36, '…') : '';
                      $aktif   = ($r['status_brng'] ?? '1') === '1';
                  ?>
                  <tr class="<?= $isSent ? 'row-sent' : '' ?>">

                    <td class="text-center" style="color:#aaa;font-size:11px;font-weight:600;"><?= $no++ ?></td>

                    <td class="text-center">
                      <button class="btn-send <?= $isSent?'sent':'' ?>"
                              onclick="kirimSatu('<?= addslashes($r['kode_brng']) ?>',this)"
                              title="<?= $isSent?'Kirim Ulang':'Kirim ke Satu Sehat' ?>">
                        <i class="fa fa-<?= $isSent?'refresh':'send' ?>"></i>
                      </button>
                    </td>

                    <td>
                      <div class="kode-lbl"><?= htmlspecialchars($r['kode_brng']) ?></div>
                      <?php if (!empty($r['kode_golongan'])): ?>
                      <div class="sub-lbl">Gol: <?= htmlspecialchars($r['kode_golongan']) ?></div>
                      <?php endif; ?>
                    </td>

                    <td>
                      <div class="nama-brng"><?= htmlspecialchars($r['nama_brng'] ?: '-') ?></div>
                      <?php if (!empty($r['kode_industri'])): ?>
                      <div class="sub-lbl"><i class="fa fa-industry"></i> <?= htmlspecialchars($r['kode_industri']) ?></div>
                      <?php endif; ?>
                    </td>

                    <td class="text-center">
                      <span class="sat-lbl"><?= htmlspecialchars($r['kode_satbesar'] ?: '-') ?></span>
                      <?php if (!empty($r['kode_sat']) && $r['kode_sat'] !== $r['kode_satbesar']): ?>
                      <div class="sub-lbl"><?= htmlspecialchars($r['kode_sat']) ?></div>
                      <?php endif; ?>
                    </td>

                    <td class="text-center" style="font-size:12px;color:#555;">
                      <?= !empty($r['isi']) ? number_format($r['isi'],0) : '-' ?>
                    </td>

                    <td class="text-center">
                      <span class="<?= $aktif ? 'badge-aktif' : 'badge-nonaktif' ?>">
                        <?= $aktif ? 'Aktif' : 'Non-aktif' ?>
                      </span>
                    </td>

                    <td class="id-med-cell">
                      <?php if ($isSent): ?>
                        <span class="id-cell" title="<?= htmlspecialchars($r['id_medication']) ?>"><?= htmlspecialchars($shortId) ?></span>
                        <button class="btn-copy" onclick="copyText('<?= addslashes($r['id_medication']) ?>')" title="Salin"><i class="fa fa-copy"></i></button>
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
              </div>
              <ul class="pagination pagination-sm no-margin pull-right">
                <?php
                $qBase = "cari=".urlencode($cari)."&status=".urlencode($filter_status)."&limit=$limit";
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
              <h4 style="color:#aaa;font-weight:400;">
                <?= ($cari||$filter_status) ? "Tidak ada data untuk filter yang dipilih" : "Tidak ada data Medication" ?>
              </h4>
              <?php if ($cari||$filter_status): ?>
              <a href="data_medication.php" class="btn btn-default btn-sm"><i class="fa fa-refresh"></i> Reset Filter</a>
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