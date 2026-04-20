<?php
/**
 * mapping_obat.php
 * Halaman mapping obat databarang → satu_sehat_mapping_obat
 * Memudahkan petugas memetakan obat SIMRS ke kode Satu Sehat
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

// ── AJAX: Simpan mapping satu obat ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'simpan') {
    header('Content-Type: application/json');
    $kode_brng       = trim($_POST['kode_brng']       ?? '');
    $obat_code       = trim($_POST['obat_code']        ?? '');
    $obat_system     = trim($_POST['obat_system']      ?? '');
    $obat_display    = trim($_POST['obat_display']     ?? '');
    $form_code       = trim($_POST['form_code']        ?? '');
    $form_system     = trim($_POST['form_system']      ?? '');
    $form_display    = trim($_POST['form_display']     ?? '');
    $numerator_code  = trim($_POST['numerator_code']   ?? '');
    $numerator_system= trim($_POST['numerator_system'] ?? '');
    $denominator_code= trim($_POST['denominator_code'] ?? '');
    $denominator_system=trim($_POST['denominator_system']?? '');
    $route_code      = trim($_POST['route_code']       ?? '');
    $route_system    = trim($_POST['route_system']     ?? '');
    $route_display   = trim($_POST['route_display']    ?? '');

    if (!$kode_brng) {
        echo json_encode(['status'=>'error','message'=>'kode_brng wajib']);
        exit;
    }

    try {
        $pdo_simrs->prepare("
            INSERT INTO satu_sehat_mapping_obat
                (kode_brng,obat_code,obat_system,obat_display,
                 form_code,form_system,form_display,
                 numerator_code,numerator_system,
                 denominator_code,denominator_system,
                 route_code,route_system,route_display)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                obat_code=VALUES(obat_code), obat_system=VALUES(obat_system),
                obat_display=VALUES(obat_display),
                form_code=VALUES(form_code), form_system=VALUES(form_system),
                form_display=VALUES(form_display),
                numerator_code=VALUES(numerator_code), numerator_system=VALUES(numerator_system),
                denominator_code=VALUES(denominator_code), denominator_system=VALUES(denominator_system),
                route_code=VALUES(route_code), route_system=VALUES(route_system),
                route_display=VALUES(route_display)
        ")->execute([
            $kode_brng,$obat_code,$obat_system,$obat_display,
            $form_code,$form_system,$form_display,
            $numerator_code,$numerator_system,
            $denominator_code,$denominator_system,
            $route_code,$route_system,$route_display
        ]);
        echo json_encode(['status'=>'ok','message'=>'Mapping berhasil disimpan']);
    } catch (Exception $e) {
        echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
    }
    exit;
}

// ── AJAX: Hapus mapping ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hapus') {
    header('Content-Type: application/json');
    $kode_brng = trim($_POST['kode_brng'] ?? '');
    try {
        $pdo_simrs->prepare("DELETE FROM satu_sehat_mapping_obat WHERE kode_brng = ?")
                  ->execute([$kode_brng]);
        echo json_encode(['status'=>'ok','message'=>'Mapping dihapus']);
    } catch (Exception $e) {
        echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
    }
    exit;
}

// ── Filter & Pagination ──────────────────────────────────────────
$cari          = $_GET['cari']   ?? '';
$filter_status = $_GET['status'] ?? ''; // 'mapped' | 'unmapped' | ''
$filter_jns    = $_GET['jns']    ?? '';
$limit         = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 20;
$page          = isset($_GET['page'])  ? max(1, intval($_GET['page']))  : 1;
$offset        = ($page - 1) * $limit;

// ── Ambil Data ───────────────────────────────────────────────────
try {
    $wheres = ['db.status = "1"']; // hanya obat aktif
    $params = [];

    if (!empty($cari)) {
        $wheres[] = "(db.kode_brng LIKE ? OR db.nama_brng LIKE ? OR m.obat_code LIKE ? OR m.obat_display LIKE ?)";
        $params   = array_merge($params, ["%$cari%","%$cari%","%$cari%","%$cari%"]);
    }
    if ($filter_status === 'mapped') {
        $wheres[] = "m.kode_brng IS NOT NULL";
    } elseif ($filter_status === 'unmapped') {
        $wheres[] = "m.kode_brng IS NULL";
    }
    if (!empty($filter_jns)) {
        $wheres[] = "db.kdjns = ?";
        $params[] = $filter_jns;
    }

    $where_sql = "WHERE " . implode(" AND ", $wheres);
    $base_join = "
        FROM databarang db
        LEFT JOIN satu_sehat_mapping_obat m ON db.kode_brng = m.kode_brng
    ";

    $stmtCount = $pdo_simrs->prepare("SELECT COUNT(*) $base_join $where_sql");
    $stmtCount->execute(array_values($params));
    $total       = (int)$stmtCount->fetchColumn();
    $total_pages = max(1, ceil($total / $limit));

    $stmtData = $pdo_simrs->prepare(
        "SELECT db.kode_brng, db.nama_brng, db.kdjns, db.kode_satbesar,
                db.kode_golongan, db.kode_industri, db.isi, db.status AS status_brng,
                m.obat_code, m.obat_system, m.obat_display,
                m.form_code, m.form_system, m.form_display,
                m.numerator_code, m.numerator_system,
                m.denominator_code, m.denominator_system,
                m.route_code, m.route_system, m.route_display
         $base_join $where_sql
         ORDER BY m.kode_brng IS NULL DESC, db.nama_brng ASC
         LIMIT " . intval($limit) . " OFFSET " . intval($offset)
    );
    $stmtData->execute(array_values($params));
    $data = $stmtData->fetchAll(PDO::FETCH_ASSOC);

    // Stats
    $stats = $pdo_simrs->query("
        SELECT
            COUNT(db.kode_brng) AS total,
            SUM(CASE WHEN m.kode_brng IS NOT NULL THEN 1 ELSE 0 END) AS mapped,
            SUM(CASE WHEN m.kode_brng IS NULL THEN 1 ELSE 0 END) AS unmapped
        FROM databarang db
        LEFT JOIN satu_sehat_mapping_obat m ON db.kode_brng = m.kode_brng
        WHERE db.status = '1'
    ")->fetch(PDO::FETCH_ASSOC);

    $st_total   = (int)($stats['total']   ?? 0);
    $st_mapped  = (int)($stats['mapped']  ?? 0);
    $st_unmapped= (int)($stats['unmapped']?? 0);
    $dbError    = null;

} catch (Exception $e) {
    $data = []; $total = 0; $total_pages = 1;
    $st_total = $st_mapped = $st_unmapped = 0;
    $dbError = $e->getMessage();
}

$pct     = $st_total > 0 ? round(($st_mapped / $st_total) * 100) : 0;
$qBase   = http_build_query(['cari'=>$cari,'status'=>$filter_status,'jns'=>$filter_jns,'limit'=>$limit]);

// Sistem kode yang umum dipakai
$obat_systems = [
    'http://sys-ids.kemkes.go.id/kfa' => 'KFA (Katalog Farmasi)',
    'http://www.whocc.no/atc'         => 'ATC (WHO)',
    'http://snomed.info/sct'          => 'SNOMED CT',
];
$form_systems = [
    'http://terminology.hl7.org/CodeSystem/v3-orderableDrugForm' => 'HL7 Drug Form',
    'http://snomed.info/sct'                                      => 'SNOMED CT',
];
$route_systems = [
    'http://www.fda.gov/Drugs/DevelopmentApprovalProcess/UCM070826' => 'FDA Route',
    'http://snomed.info/sct'                                         => 'SNOMED CT',
];

// Kode route yang umum
$route_options = [
    'C38288' => 'Oral (PO)',
    'C38276' => 'Intravena (IV)',
    'C28161' => 'Intramuskular (IM)',
    'C38299' => 'Subkutan (SC)',
    'C38305' => 'Topikal',
    'C38287' => 'Inhalasi',
    'C38193' => 'Rektal',
    'C38197' => 'Sublingual',
    'C38208' => 'Mata (Optalmik)',
    'C38192' => 'Telinga (Otik)',
    'C38289' => 'Nasal',
    'C38209' => 'Vaginal',
];

// Form sediaan yang umum
$form_options = [
    'TAB'   => 'Tablet',
    'CAP'   => 'Kapsul',
    'SYR'   => 'Sirup',
    'INJ'   => 'Injeksi',
    'CREAM' => 'Krim',
    'OINT'  => 'Salep',
    'DROP'  => 'Tetes',
    'SUPP'  => 'Supositoria',
    'POWD'  => 'Serbuk',
    'GRAN'  => 'Granul',
    'SOLN'  => 'Larutan',
    'SUSP'  => 'Suspensi',
    'INHL'  => 'Inhaler',
    'PATCH' => 'Patch/Plester',
];

$page_title = 'Mapping Obat — Satu Sehat';

$extra_css = '
:root{--mp-primary:#0277bd;--mp-dark:#01579b;--mp-soft:#e1f5fe;--mp-border:#b3e5fc}

.stats-bar{display:flex;gap:16px;padding:10px 16px;background:var(--mp-soft);border-bottom:1px solid var(--mp-border);font-size:12.5px;flex-wrap:wrap;align-items:center}
.sb-item{display:flex;align-items:center;gap:6px;color:#555}
.sb-val{font-weight:700;font-size:15px}
.sb-sep{color:#b0d4e8}

.prog-row{display:flex;align-items:center;gap:10px;font-size:12px;padding:8px 16px;background:#f9f9f9;border-bottom:1px solid #e5e5e5}
.prog-bar{flex:1;height:8px;border-radius:4px;background:#e0e0e0;overflow:hidden}
.prog-fill{height:100%;border-radius:4px;background:linear-gradient(90deg,var(--mp-primary),#00a65a);transition:width .6s}
.prog-pct{width:40px;text-align:right;font-weight:700;color:#555}

.tbl-map thead tr th{background:var(--mp-primary);color:#fff!important;font-size:12px;white-space:nowrap}
.tbl-map tbody td{vertical-align:middle;font-size:12.5px}

.badge-mapped{background:#dff0d8;color:#3c763d;border:1px solid #c3e6cb;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700}
.badge-unmapped{background:#f2dede;color:#a94442;border:1px solid #f5c6cb;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700}
.row-mapped{background:#f0fff4!important}

.kode-lbl{font-family:"Courier New",monospace;font-size:11px;color:var(--mp-primary);font-weight:700}
.nama-brng{font-weight:600;color:#333;font-size:13px}
.sub-lbl{font-size:10.5px;color:#aaa}

.btn-edit{width:30px;height:30px;border-radius:5px;padding:0;display:inline-flex;align-items:center;justify-content:center;background:var(--mp-primary);border:none;color:#fff;cursor:pointer;transition:all .2s;font-size:12px}
.btn-edit:hover{background:var(--mp-dark);transform:scale(1.1)}
.btn-del{width:30px;height:30px;border-radius:5px;padding:0;display:inline-flex;align-items:center;justify-content:center;background:#dd4b39;border:none;color:#fff;cursor:pointer;transition:all .2s;font-size:12px}
.btn-del:hover{background:#c23321;transform:scale(1.1)}

/* Modal */
.modal-map .modal-header{background:linear-gradient(135deg,var(--mp-primary),var(--mp-dark));color:#fff}
.modal-map .modal-title{font-weight:700}
.modal-map .close{color:#fff;opacity:.8}
.modal-map .close:hover{opacity:1}
.section-lbl{font-size:11px;font-weight:700;color:var(--mp-primary);text-transform:uppercase;letter-spacing:.5px;border-bottom:2px dashed var(--mp-border);padding-bottom:4px;margin-bottom:10px;margin-top:12px}
.form-control-sm{height:32px;font-size:12.5px;padding:4px 8px}
.form-control:focus{border-color:var(--mp-primary);box-shadow:0 0 0 2px rgba(2,119,189,.15)}
.kfa-note{background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:6px 10px;font-size:11.5px;color:#856404;margin-top:6px}

.tag-mapped{display:inline-block;background:var(--mp-soft);border:1px solid var(--mp-border);color:var(--mp-primary);padding:1px 6px;border-radius:4px;font-size:10.5px;font-family:"Courier New",monospace}
.tag-empty{font-style:italic;color:#ccc;font-size:11px}


.btn-auto-map{background:linear-gradient(135deg,#f57c00,#e65100);border:none;color:#fff;padding:7px 16px;border-radius:5px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .25s;font-size:13px}
.btn-auto-map:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(230,81,0,.4);color:#fff}
.btn-auto-map:disabled{opacity:.6;cursor:not-allowed;transform:none}
.btn-cari-kfa{background:linear-gradient(135deg,#0277bd,#01579b);border:none;color:#fff;padding:3px 10px;border-radius:4px;font-weight:600;cursor:pointer;font-size:11px;transition:all .2s}
.btn-cari-kfa:hover{transform:scale(1.05);color:#fff}
.kfa-item{border:1px solid #dee2e6;border-radius:6px;padding:10px 12px;margin-bottom:8px;cursor:pointer;transition:all .2s;background:#fff}
.kfa-item:hover{border-color:#0277bd;background:#e1f5fe;transform:translateX(3px)}
.kfa-item.selected{border-color:#00a65a;background:#dff0d8}
.kfa-item .kfa-code{font-family:"Courier New",monospace;font-size:11px;font-weight:700;color:#0277bd}
.kfa-item .kfa-name{font-weight:600;font-size:13px;color:#333;margin:3px 0}
.kfa-item .kfa-detail{font-size:11px;color:#888}
.auto-progress{background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;padding:12px 15px;margin-top:10px}
.auto-progress .prog-bar{height:10px;border-radius:5px;background:#e0e0e0;overflow:hidden;margin:8px 0}
.auto-progress .prog-fill{height:100%;background:linear-gradient(90deg,#f57c00,#00a65a);transition:width .4s}

#toast-container{position:fixed;bottom:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.toast-msg{padding:10px 18px;border-radius:6px;font-size:13px;font-weight:600;color:#fff;display:flex;align-items:center;gap:8px;box-shadow:0 4px 12px rgba(0,0,0,.2);pointer-events:auto;animation:toastIn .3s ease}
.toast-success{background:#00a65a}.toast-error{background:#dd4b39}.toast-warn{background:#f39c12}
@keyframes toastIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
';

$extra_js = <<<'ENDJS'

// ── Auto-Map Semua ────────────────────────────────────────────────
let autoRunning = false;
let totalBerhasil = 0, totalGagal = 0, totalSkip = 0;

function autoMapSemua() {
    if (autoRunning) return;
    if (!confirm('Auto-mapping akan mencari kode KFA untuk semua obat yang belum di-mapping.\n\nProses berjalan per 10 obat. Jangan tutup halaman ini!\n\nLanjutkan?')) return;
    autoRunning = true;
    totalBerhasil = 0; totalGagal = 0; totalSkip = 0;
    document.getElementById('autoProgress').style.display = 'block';
    document.getElementById('btnAutoMap').disabled = true;
    document.getElementById('btnAutoMap').innerHTML = '<i class="fa fa-spinner fa-spin"></i> Auto-Map Berjalan...';
    jalankanBatch(0);
}

function jalankanBatch(offset) {
    fetch('api/auto_mapping_obat.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({action:'auto_semua', offset})
    })
    .then(r => r.json())
    .then(resp => {
        totalBerhasil += resp.berhasil || 0;
        totalGagal    += resp.gagal    || 0;
        totalSkip     += resp.skip     || 0;

        document.getElementById('autoLog').innerHTML =
            `<i class="fa fa-check-circle" style="color:#00a65a"></i> Mapped: <b>${totalBerhasil}</b> &nbsp;|&nbsp; `+
            `<i class="fa fa-forward" style="color:#f39c12"></i> Skip: <b>${totalSkip}</b> &nbsp;|&nbsp; `+
            `<i class="fa fa-times-circle" style="color:#dd4b39"></i> Error: <b>${totalGagal}</b>`;

        const sisa = resp.total_sisa || 0;
        if (resp.status === 'lanjut' && sisa > 0) {
            document.getElementById('autoStatus').textContent = `Sisa ${sisa} obat, sedang memproses...`;
            setTimeout(() => jalankanBatch(resp.next_offset), 500);
        } else {
            autoRunning = false;
            document.getElementById('btnAutoMap').disabled = false;
            document.getElementById('btnAutoMap').innerHTML = '<i class="fa fa-magic"></i> Auto-Map KFA';
            document.getElementById('autoStatus').textContent = 'Selesai!';
            showToast(`Auto-map selesai: ${totalBerhasil} berhasil, ${totalSkip} tidak ditemukan, ${totalGagal} error`, 'success');
            setTimeout(() => location.reload(), 2500);
        }
    })
    .catch(() => {
        autoRunning = false;
        document.getElementById('btnAutoMap').disabled = false;
        document.getElementById('btnAutoMap').innerHTML = '<i class="fa fa-magic"></i> Auto-Map KFA';
        showToast('Koneksi gagal saat auto-map', 'error');
    });
}

// ── Cari KFA satu obat ────────────────────────────────────────────
let selectedItem = null;
let selectedKode = '';

function cariKFA(kodeBrng, btnEl) {
    btnEl.disabled = true;
    const orig = btnEl.innerHTML;
    btnEl.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';

    fetch('api/auto_mapping_obat.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({action:'cari_satu', kode_brng: kodeBrng})
    })
    .then(r => r.json())
    .then(resp => {
        btnEl.disabled = false; btnEl.innerHTML = orig;
        if (resp.status !== 'ok') { showToast('Gagal cari KFA: ' + resp.message, 'error'); return; }

        selectedKode = kodeBrng;
        selectedItem = null;
        document.getElementById('kfaNamaObat').textContent = resp.nama_brng;
        document.getElementById('kfaKeyword').textContent = resp.keyword;

        const list = document.getElementById('kfaList');
        list.innerHTML = '';

        document.getElementById('kfaSearchInput').value = '';
        renderKFAList(resp.items || []);
        $('#modalKFA').modal('show');
    })
    .catch(() => { btnEl.disabled = false; btnEl.innerHTML = orig; showToast('Koneksi gagal', 'error'); });
}

function cariKFAManual() {
    const keyword = document.getElementById('kfaSearchInput').value.trim();
    if (!keyword) { alert('Masukkan keyword pencarian'); return; }
    if (!selectedKode) return;

    const btn = document.querySelector('#modalKFA .modal-body button');
    btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';

    fetch('api/auto_mapping_obat.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({action:'cari_manual', kode_brng: selectedKode, keyword})
    })
    .then(r => r.json())
    .then(resp => {
        btn.disabled = false; btn.innerHTML = '<i class="fa fa-search"></i> Cari';
        if (resp.status !== 'ok') { showToast('Gagal: ' + resp.message, 'error'); return; }
        document.getElementById('kfaKeyword').textContent = keyword;
        renderKFAList(resp.items || []);
    })
    .catch(() => { btn.disabled = false; btn.innerHTML = '<i class="fa fa-search"></i> Cari'; showToast('Koneksi gagal', 'error'); });
}

function renderKFAList(items) {
    selectedItem = null;
    document.getElementById('btnPilihKFA').disabled = true;
    const list = document.getElementById('kfaList');
    list.innerHTML = '';
    if (!items || items.length === 0) {
        list.innerHTML = '<div style="padding:20px;text-align:center;color:#aaa;"><i class="fa fa-search"></i><br>Tidak ditemukan.<br><small>Coba kata kunci lain.</small></div>';
        return;
    }
    items.forEach(item => {
        const div = document.createElement('div');
        div.className = 'kfa-item';
        div.onclick = function() {
            document.querySelectorAll('.kfa-item').forEach(d => d.classList.remove('selected'));
            this.classList.add('selected');
            selectedItem = item;
            document.getElementById('btnPilihKFA').disabled = false;
        };
        const skor = item._skor || 0;
        const skorWarna = skor >= 50 ? '#00a65a' : skor >= 30 ? '#f39c12' : '#777';
        const skorLabel = skor >= 50 ? '✓ Cocok '+skor+'%' : skor >= 30 ? '~ Mungkin '+skor+'%' : '';
        div.innerHTML = `
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <span class="kfa-code">${item.kfa_code || '-'}</span>
              ${skorLabel ? `<span style="font-size:10px;font-weight:700;color:${skorWarna};background:${skorWarna}22;padding:1px 7px;border-radius:10px;">${skorLabel}</span>` : ''}
            </div>
            <div class="kfa-name">${item.name || '-'}</div>
            <div class="kfa-detail">
                ${item.dosage_form ? '📋 '+item.dosage_form.name+' &nbsp;' : ''}
                ${item.rute_pemberian ? '💊 '+item.rute_pemberian.name+' &nbsp;' : ''}
                ${item.net_weight_uom_name ? '⚖️ '+item.net_weight_uom_name+' &nbsp;' : ''}
                ${item.manufacturer ? '🏭 '+item.manufacturer : ''}
            </div>
        `;
        list.appendChild(div);
    });
}

function pilihKFA() {
    if (!selectedItem || !selectedKode) return;
    const btn = document.getElementById('btnPilihKFA');
    btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Menyimpan...';

    fetch('api/auto_mapping_obat.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            action: 'simpan_pilih',
            kode_brng: selectedKode,
            item_json: JSON.stringify(selectedItem)
        })
    })
    .then(r => r.json())
    .then(resp => {
        btn.disabled = false; btn.innerHTML = '<i class="fa fa-check"></i> Pilih & Simpan';
        if (resp.status === 'ok') {
            showToast('Mapping KFA ' + resp.kfa_code + ' berhasil disimpan!', 'success');
            $('#modalKFA').modal('hide');
            setTimeout(() => location.reload(), 1200);
        } else {
            showToast('Gagal: ' + resp.message, 'error');
        }
    });
}

function showToast(msg, type) {
    const c = document.getElementById("toast-container"); if(!c) return;
    const d = document.createElement("div");
    d.className = "toast-msg toast-" + (type||"success");
    const icons = {success:"check-circle",error:"times-circle",warn:"exclamation-triangle"};
    d.innerHTML = `<i class="fa fa-${icons[type]||"check-circle"}"></i> ${msg}`;
    c.appendChild(d); setTimeout(()=>d.remove(), 4000);
}

function bukaModal(data) {
    // Reset form
    document.getElementById("formMapping").reset();
    // Isi data obat
    document.getElementById("m_kode_brng").value    = data.kode_brng || '';
    document.getElementById("m_nama_brng").value    = data.nama_brng || '';
    document.getElementById("m_info_brng").textContent =
        [data.kdjns, data.kode_satbesar, data.kode_golongan].filter(Boolean).join(' · ');
    // Isi mapping jika sudah ada
    document.getElementById("m_obat_code").value        = data.obat_code    || '';
    document.getElementById("m_obat_system").value      = data.obat_system  || 'http://sys-ids.kemkes.go.id/kfa';
    document.getElementById("m_obat_display").value     = data.obat_display || data.nama_brng || '';
    document.getElementById("m_form_code").value        = data.form_code    || '';
    document.getElementById("m_form_system").value      = data.form_system  || 'http://terminology.hl7.org/CodeSystem/v3-orderableDrugForm';
    document.getElementById("m_form_display").value     = data.form_display || '';
    document.getElementById("m_numerator_code").value   = data.numerator_code   || '';
    document.getElementById("m_numerator_system").value = data.numerator_system || 'http://unitsofmeasure.org';
    document.getElementById("m_denominator_code").value = data.denominator_code || '';
    document.getElementById("m_denominator_system").value = data.denominator_system || 'http://unitsofmeasure.org';
    document.getElementById("m_route_code").value       = data.route_code    || '';
    document.getElementById("m_route_system").value     = data.route_system  || 'http://www.fda.gov/Drugs/DevelopmentApprovalProcess/UCM070826';
    document.getElementById("m_route_display").value    = data.route_display || '';

    // Update judul modal
    document.getElementById("modalJudul").textContent = 'Mapping: ' + data.nama_brng;

    $('#modalMapping').modal('show');
}

function simpanMapping() {
    const btn = document.getElementById("btnSimpan");
    btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Menyimpan...';

    const form = document.getElementById("formMapping");
    const body = new URLSearchParams(new FormData(form));
    body.set('action', 'simpan');

    fetch(window.location.href, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: body.toString()
    })
    .then(r => r.json())
    .then(resp => {
        btn.disabled = false; btn.innerHTML = '<i class="fa fa-save"></i> Simpan Mapping';
        if (resp.status === 'ok') {
            showToast(resp.message, 'success');
            $('#modalMapping').modal('hide');
            setTimeout(() => location.reload(), 1200);
        } else {
            showToast('Gagal: ' + resp.message, 'error');
        }
    })
    .catch(() => {
        btn.disabled = false; btn.innerHTML = '<i class="fa fa-save"></i> Simpan Mapping';
        showToast('Koneksi gagal', 'error');
    });
}

function hapusMapping(kodeBrng, namabrng) {
    if (!confirm('Hapus mapping obat:\n' + namabrng + ' (' + kodeBrng + ')?\n\nData Satu Sehat di tabel Khanza tidak akan dihapus.')) return;
    fetch(window.location.href, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({action:'hapus', kode_brng: kodeBrng})
    })
    .then(r => r.json())
    .then(resp => {
        if (resp.status === 'ok') { showToast('Mapping dihapus', 'warn'); setTimeout(() => location.reload(), 1200); }
        else showToast('Gagal: ' + resp.message, 'error');
    });
}

// Update route display saat pilih route code
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('m_route_code')?.addEventListener('change', function() {
        const map = {
            'C38288':'Oral','C38276':'Intravena','C28161':'Intramuskular',
            'C38299':'Subkutan','C38305':'Topikal','C38287':'Inhalasi',
            'C38193':'Rektal','C38197':'Sublingual','C38208':'Optalmik',
            'C38192':'Otik','C38289':'Nasal','C38209':'Vaginal'
        };
        const disp = document.getElementById('m_route_display');
        if (disp && map[this.value]) disp.value = map[this.value];
    });
    document.getElementById('m_form_code')?.addEventListener('change', function() {
        const map = {
            'TAB':'Tablet','CAP':'Kapsul','SYR':'Sirup','INJ':'Injeksi',
            'CREAM':'Krim','OINT':'Salep','DROP':'Tetes','SUPP':'Supositoria',
            'POWD':'Serbuk','GRAN':'Granul','SOLN':'Larutan','SUSP':'Suspensi',
            'INHL':'Inhaler','PATCH':'Patch'
        };
        const disp = document.getElementById('m_form_display');
        if (disp && map[this.value]) disp.value = map[this.value];
    });
});
ENDJS;

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div id="toast-container"></div>


<!-- Modal Pilih KFA -->
<div class="modal fade" id="modalKFA" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#f57c00,#e65100);color:#fff;">
        <button type="button" class="close" data-dismiss="modal" style="color:#fff;"><span>&times;</span></button>
        <h4 class="modal-title"><i class="fa fa-search"></i> Hasil Pencarian KFA</h4>
      </div>
      <div class="modal-body">
        <div style="background:#fff3e0;border:1px solid #ffcc02;border-radius:5px;padding:8px 12px;margin-bottom:12px;font-size:13px;">
          <strong>Obat SIMRS:</strong> <span id="kfaNamaObat" style="font-weight:700;"></span>
          <br><small style="color:#888;">Keyword otomatis: <em id="kfaKeyword"></em></small>
        </div>
        <div style="display:flex;gap:8px;margin-bottom:12px;">
          <input type="text" id="kfaSearchInput" class="form-control"
                 placeholder="Ketik nama zat aktif jika hasil tidak sesuai (contoh: Amlodipine)..."
                 style="font-size:13px;">
          <button onclick="cariKFAManual()" class="btn btn-primary" style="white-space:nowrap;font-size:13px;">
            <i class="fa fa-search"></i> Cari
          </button>
        </div>
        <p style="font-size:12px;color:#888;margin-bottom:8px;"><i class="fa fa-hand-pointer-o"></i> Klik salah satu hasil untuk memilih, lalu klik <strong>Pilih & Simpan</strong>.</p>
        <div id="kfaList" style="max-height:320px;overflow-y:auto;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">Batal</button>
        <button type="button" id="btnPilihKFA" class="btn btn-success" onclick="pilihKFA()" disabled>
          <i class="fa fa-check"></i> Pilih & Simpan
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal Mapping -->
<div class="modal fade modal-map" id="modalMapping" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
        <h4 class="modal-title" id="modalJudul"><i class="fa fa-medkit"></i> Mapping Obat</h4>
      </div>
      <div class="modal-body">
        <form id="formMapping">
          <input type="hidden" name="kode_brng" id="m_kode_brng">

          <!-- Info Obat -->
          <div style="background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;padding:10px 14px;margin-bottom:12px;">
            <div style="font-weight:700;font-size:14px;color:#333;" id="m_nama_brng"></div>
            <div style="font-size:11.5px;color:#888;margin-top:3px;" id="m_info_brng"></div>
          </div>

          <!-- Kode Obat -->
          <div class="section-lbl"><i class="fa fa-key"></i> Kode Obat Satu Sehat</div>
          <div class="row">
            <div class="col-md-4">
              <div class="form-group">
                <label style="font-size:12px;">Kode Obat <span class="text-danger">*</span></label>
                <input type="text" id="m_obat_code" name="obat_code" class="form-control"
                       placeholder="Kode KFA / ATC">
                <div class="kfa-note"><i class="fa fa-info-circle"></i> Cari kode KFA di <strong>kfa.kemkes.go.id</strong></div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="form-group">
                <label style="font-size:12px;">Sistem Kode</label>
                <select id="m_obat_system" name="obat_system" class="form-control">
                  <?php foreach ($obat_systems as $val => $lbl): ?>
                  <option value="<?= $val ?>"><?= $lbl ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="col-md-4">
              <div class="form-group">
                <label style="font-size:12px;">Nama Display</label>
                <input type="text" id="m_obat_display" name="obat_display" class="form-control"
                       placeholder="Nama obat di Satu Sehat">
              </div>
            </div>
          </div>

          <!-- Bentuk Sediaan -->
          <div class="section-lbl"><i class="fa fa-pills"></i> Bentuk Sediaan</div>
          <div class="row">
            <div class="col-md-4">
              <div class="form-group">
                <label style="font-size:12px;">Kode Sediaan</label>
                <select id="m_form_code" name="form_code" class="form-control">
                  <option value="">-- Pilih --</option>
                  <?php foreach ($form_options as $val => $lbl): ?>
                  <option value="<?= $val ?>"><?= $lbl ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="col-md-4">
              <div class="form-group">
                <label style="font-size:12px;">Sistem</label>
                <select id="m_form_system" name="form_system" class="form-control">
                  <?php foreach ($form_systems as $val => $lbl): ?>
                  <option value="<?= $val ?>"><?= $lbl ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="col-md-4">
              <div class="form-group">
                <label style="font-size:12px;">Display Sediaan</label>
                <input type="text" id="m_form_display" name="form_display" class="form-control"
                       placeholder="Otomatis terisi">
              </div>
            </div>
          </div>

          <!-- Numerator / Denominator -->
          <div class="section-lbl"><i class="fa fa-balance-scale"></i> Kekuatan / Dosis</div>
          <div class="row">
            <div class="col-md-3">
              <div class="form-group">
                <label style="font-size:12px;">Satuan Numerator</label>
                <input type="text" id="m_numerator_code" name="numerator_code" class="form-control"
                       placeholder="mg / mcg / IU">
                <small class="text-muted">Contoh: mg, mcg, IU, mL</small>
              </div>
            </div>
            <div class="col-md-3">
              <div class="form-group">
                <label style="font-size:12px;">Sistem Numerator</label>
                <input type="text" id="m_numerator_system" name="numerator_system" class="form-control"
                       value="http://unitsofmeasure.org">
              </div>
            </div>
            <div class="col-md-3">
              <div class="form-group">
                <label style="font-size:12px;">Satuan Denominator</label>
                <input type="text" id="m_denominator_code" name="denominator_code" class="form-control"
                       placeholder="tab / cap / mL">
                <small class="text-muted">Contoh: Tab, Cap, mL</small>
              </div>
            </div>
            <div class="col-md-3">
              <div class="form-group">
                <label style="font-size:12px;">Sistem Denominator</label>
                <input type="text" id="m_denominator_system" name="denominator_system" class="form-control"
                       value="http://unitsofmeasure.org">
              </div>
            </div>
          </div>

          <!-- Route -->
          <div class="section-lbl"><i class="fa fa-road"></i> Rute Pemberian</div>
          <div class="row">
            <div class="col-md-4">
              <div class="form-group">
                <label style="font-size:12px;">Kode Route</label>
                <select id="m_route_code" name="route_code" class="form-control">
                  <option value="">-- Pilih --</option>
                  <?php foreach ($route_options as $val => $lbl): ?>
                  <option value="<?= $val ?>"><?= $lbl ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="col-md-4">
              <div class="form-group">
                <label style="font-size:12px;">Sistem Route</label>
                <select id="m_route_system" name="route_system" class="form-control">
                  <?php foreach ($route_systems as $val => $lbl): ?>
                  <option value="<?= $val ?>"><?= $lbl ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="col-md-4">
              <div class="form-group">
                <label style="font-size:12px;">Display Route</label>
                <input type="text" id="m_route_display" name="route_display" class="form-control"
                       placeholder="Otomatis terisi">
              </div>
            </div>
          </div>

        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">Batal</button>
        <button type="button" id="btnSimpan" class="btn btn-primary" onclick="simpanMapping()">
          <i class="fa fa-save"></i> Simpan Mapping
        </button>
      </div>
    </div>
  </div>
</div>

<div class="content-wrapper">
  <section class="content-header">
    <h1>
      <i class="fa fa-medkit" style="color:#0277bd;"></i>
      Mapping Obat
      <small>Satu Sehat — Pemetaan Kode Obat</small>
    </h1>
    <ol class="breadcrumb">
      <li><a href="dashboard.php"><i class="fa fa-dashboard"></i> Home</a></li>
      <li>Mapping Satu Sehat</li>
      <li class="active">Mapping Obat</li>
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
          <div class="box-tools pull-right">
            <button type="button" class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-minus"></i></button>
          </div>
        </div>
        <div class="box-body">
          <form method="GET" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
            <div class="form-group">
              <label>Cari:</label>
              <input type="text" name="cari" class="form-control" style="width:220px;"
                     placeholder="Kode / Nama Obat / Kode KFA…"
                     value="<?= htmlspecialchars($cari) ?>">
            </div>
            <div class="form-group">
              <label>Status Mapping:</label>
              <select name="status" class="form-control">
                <option value=""        <?= $filter_status===''        ?'selected':'' ?>>Semua</option>
                <option value="mapped"  <?= $filter_status==='mapped'  ?'selected':'' ?>>Sudah Mapping</option>
                <option value="unmapped"<?= $filter_status==='unmapped'?'selected':'' ?>>Belum Mapping</option>
              </select>
            </div>
            <div class="form-group">
              <label>Per halaman:</label>
              <select name="limit" class="form-control">
                <?php foreach ([20,50,100,200] as $l): ?>
                <option value="<?=$l?>" <?=$limit==$l?'selected':''?>><?=$l?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i> Tampilkan</button>
            <a href="mapping_obat.php" class="btn btn-default"><i class="fa fa-refresh"></i> Reset</a>
          </form>
        </div>
      </div>

      <!-- Tabel -->
      <div class="box" style="border-top:3px solid #0277bd;">
        <div class="box-header with-border">
          <h3 class="box-title" style="color:#0277bd;">
            <i class="fa fa-table"></i> Data Obat
            <span class="badge" style="background:#0277bd;"><?= number_format($total) ?></span>
          </h3>
          <div class="box-tools pull-right" style="display:flex;gap:8px;align-items:center;">
            <button id="btnAutoMap" onclick="autoMapSemua()" class="btn-auto-map"
                    title="Auto cari kode KFA untuk semua obat yang belum mapping">
              <i class="fa fa-magic"></i> Auto-Map KFA
              <?php if ($st_unmapped > 0): ?>
              <span class="badge" style="background:#fff;color:#e65100;"><?= number_format($st_unmapped) ?></span>
              <?php endif; ?>
            </button>
            <a href="?<?= $qBase ?>" class="btn btn-default btn-sm" title="Refresh"><i class="fa fa-refresh"></i></a>
          </div>

          <!-- Auto-map progress -->
          <div id="autoProgress" class="auto-progress" style="display:none;border-top:1px solid #ffe0b2;border-radius:0;margin:0;">
            <div style="display:flex;justify-content:space-between;align-items:center;font-size:12.5px;">
              <div><i class="fa fa-cog fa-spin" style="color:#f57c00;"></i> <span id="autoStatus">Memulai...</span></div>
              <div id="autoLog" style="color:#555;"></div>
            </div>
            <div class="prog-bar"><div class="prog-fill" id="autoBar" style="width:5%;"></div></div>
          </div>
        </div>

        <!-- Stats -->
        <div class="stats-bar">
          <div class="sb-item"><i class="fa fa-database" style="color:#0277bd;"></i> Total Aktif: <span class="sb-val" style="color:#0277bd;"><?= number_format($st_total) ?></span></div>
          <span class="sb-sep">|</span>
          <div class="sb-item"><i class="fa fa-check-circle" style="color:#00a65a;"></i> Sudah Mapping: <span class="sb-val" style="color:#00a65a;"><?= number_format($st_mapped) ?></span></div>
          <div class="sb-item"><i class="fa fa-clock-o" style="color:#dd4b39;"></i> Belum Mapping: <span class="sb-val" style="color:#dd4b39;"><?= number_format($st_unmapped) ?></span></div>
        </div>
        <div class="prog-row">
          <span style="width:110px;font-size:12px;color:#555;flex-shrink:0;"><i class="fa fa-medkit"></i> Progress</span>
          <div class="prog-bar"><div class="prog-fill" style="width:<?= $pct ?>%;"></div></div>
          <span class="prog-pct"><?= $pct ?>%</span>
        </div>

        <div class="box-body" style="padding:0;">
          <?php if (!empty($data)): ?>
          <div class="table-responsive">
            <table class="table table-bordered table-striped table-hover tbl-map" style="margin-bottom:0;">
              <thead>
                <tr>
                  <th width="36"  class="text-center">#</th>
                  <th width="120">Kode Barang</th>
                  <th>Nama Obat</th>
                  <th width="60"  class="text-center">Jenis</th>
                  <th width="140">Kode KFA / Obat</th>
                  <th width="100">Sediaan</th>
                  <th width="100">Route</th>
                  <th width="100" class="text-center">Status</th>
                  <th width="80"  class="text-center">Aksi</th>
                </tr>
              </thead>
              <tbody>
              <?php $no = $offset + 1; foreach ($data as $r): $isMapped = !empty($r['obat_code']); ?>
              <tr class="<?= $isMapped?'row-mapped':'' ?>">
                <td class="text-center" style="color:#aaa;font-size:11px;"><?= $no++ ?></td>
                <td><span class="kode-lbl"><?= htmlspecialchars($r['kode_brng']) ?></span></td>
                <td>
                  <div class="nama-brng"><?= htmlspecialchars($r['nama_brng'] ?: '-') ?></div>
                  <div class="sub-lbl">
                    <?= htmlspecialchars($r['kode_satbesar'] ?: '') ?>
                    <?= !empty($r['kode_golongan']) ? ' · Gol: '.htmlspecialchars($r['kode_golongan']) : '' ?>
                  </div>
                </td>
                <td class="text-center">
                  <small style="background:#e8eaf6;color:#3949ab;padding:1px 5px;border-radius:3px;font-size:10px;">
                    <?= htmlspecialchars($r['kdjns'] ?: '-') ?>
                  </small>
                </td>
                <td>
                  <?php if ($isMapped): ?>
                    <span class="tag-mapped"><?= htmlspecialchars($r['obat_code']) ?></span>
                    <?php if (!empty($r['obat_display'])): ?>
                    <div class="sub-lbl" style="margin-top:2px;"><?= htmlspecialchars($r['obat_display']) ?></div>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="tag-empty"><i class="fa fa-minus-circle"></i> Belum diisi</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!empty($r['form_code'])): ?>
                    <span class="tag-mapped"><?= htmlspecialchars($r['form_code']) ?></span>
                    <div class="sub-lbl"><?= htmlspecialchars($r['form_display'] ?: '') ?></div>
                  <?php else: ?>
                    <span class="tag-empty">-</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!empty($r['route_code'])): ?>
                    <div style="font-size:11.5px;color:#444;"><?= htmlspecialchars($r['route_display'] ?: $r['route_code']) ?></div>
                  <?php else: ?>
                    <span class="tag-empty">-</span>
                  <?php endif; ?>
                </td>
                <td class="text-center">
                  <span class="<?= $isMapped?'badge-mapped':'badge-unmapped' ?>">
                    <i class="fa fa-<?= $isMapped?'check-circle':'clock-o' ?>"></i>
                    <?= $isMapped?'Mapped':'Belum' ?>
                  </span>
                </td>
                <td class="text-center">
                  <?php if (!$isMapped): ?>
                  <button class="btn-cari-kfa" title="Cari otomatis di KFA"
                          onclick="cariKFA('<?= addslashes($r['kode_brng']) ?>',this)">
                    <i class="fa fa-search"></i>
                  </button>
                  <?php endif; ?>
                  <button class="btn-edit" title="<?= $isMapped?'Edit':'Input Manual' ?> Mapping"
                          onclick='bukaModal(<?= json_encode($r, JSON_HEX_APOS) ?>)'>
                    <i class="fa fa-<?= $isMapped?'edit':'plus' ?>"></i>
                  </button>
                  <?php if ($isMapped): ?>
                  <button class="btn-del" title="Hapus Mapping"
                          onclick="hapusMapping('<?= addslashes($r['kode_brng']) ?>','<?= addslashes($r['nama_brng']) ?>')">
                    <i class="fa fa-trash"></i>
                  </button>
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
            </div>
            <ul class="pagination pagination-sm no-margin pull-right">
              <?php if ($page>1): ?><li><a href="?page=<?=$page-1?>&<?=$qBase?>">«</a></li><?php endif; ?>
              <?php for ($i=max(1,$page-3);$i<=min($total_pages,$page+3);$i++): ?>
              <li <?=$i==$page?'class="active"':''?>><a href="?page=<?=$i?>&<?=$qBase?>"><?=$i?></a></li>
              <?php endfor; ?>
              <?php if ($page<$total_pages): ?><li><a href="?page=<?=$page+1?>&<?=$qBase?>">»</a></li><?php endif; ?>
            </ul>
          </div>
          <?php endif; ?>

          <?php else: ?>
          <div style="padding:50px;text-align:center;">
            <i class="fa fa-medkit" style="font-size:52px;color:#ddd;display:block;margin-bottom:14px;"></i>
            <h4 style="color:#aaa;font-weight:400;">Tidak ada data untuk filter yang dipilih</h4>
            <a href="mapping_obat.php" class="btn btn-default btn-sm"><i class="fa fa-refresh"></i> Reset Filter</a>
          </div>
          <?php endif; ?>
        </div>
      </div>

    </div></div>
  </section>
</div>

<?php include 'includes/footer.php'; ?>