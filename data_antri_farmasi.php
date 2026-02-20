<?php
session_start();
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$nama = $_SESSION['nama'] ?? 'Pengguna';

$filter_tanggal = $_GET['tanggal'] ?? date('Y-m-d');
$cari           = $_GET['cari']    ?? '';

$limit  = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 20;
$page   = isset($_GET['page'])  ? max(1, intval($_GET['page']))  : 1;
$offset = ($page - 1) * $limit;

// ============================================================
// AJAX HANDLER: Simpan panggilan ke DB
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'panggil') {
    header('Content-Type: application/json');

    $no_resep    = trim($_POST['no_resep']    ?? '');
    $no_rawat    = trim($_POST['no_rawat']    ?? '');
    $no_rkm_medis= trim($_POST['no_rkm_medis']?? '');
    $no_antrian  = trim($_POST['no_antrian']  ?? '');
    $nm_pasien   = trim($_POST['nm_pasien']   ?? '');
    $nm_poli     = trim($_POST['nm_poli']     ?? '');
    $nm_dokter   = trim($_POST['nm_dokter']   ?? '');
    $jenis_resep = trim($_POST['jenis_resep'] ?? '');
    $tgl         = date('Y-m-d');

    if (!$no_resep) {
        echo json_encode(['status' => 'error', 'message' => 'Nomor resep kosong']);
        exit;
    }

    try {
        $sql = "INSERT INTO simpan_antrian_farmasi_wira
                    (no_resep, no_rawat, no_rkm_medis, no_antrian, nm_pasien, nm_poli, nm_dokter, jenis_resep, tgl_panggil, jml_panggil)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE
                    jml_panggil  = jml_panggil + 1,
                    nm_pasien    = VALUES(nm_pasien),
                    nm_poli      = VALUES(nm_poli),
                    nm_dokter    = VALUES(nm_dokter),
                    jenis_resep  = VALUES(jenis_resep)";

        $stmt = $pdo_simrs->prepare($sql);
        $stmt->execute([$no_resep, $no_rawat, $no_rkm_medis, $no_antrian, $nm_pasien, $nm_poli, $nm_dokter, $jenis_resep, $tgl]);

        $stmt2 = $pdo_simrs->prepare("SELECT jml_panggil FROM simpan_antrian_farmasi_wira WHERE no_resep = ? AND tgl_panggil = ?");
        $stmt2->execute([$no_resep, $tgl]);
        $jml = (int)$stmt2->fetchColumn();

        $jsonData = [
            'no_resep'    => $no_resep,
            'no_antrian'  => $no_antrian,
            'nm_pasien'   => $nm_pasien,
            'nm_poli'     => $nm_poli ?: 'Instalasi Farmasi',
            'jenis_resep' => $jenis_resep,
            'waktu'       => date('Y-m-d H:i:s')
        ];

        $file_saved = false;
        $file_path  = '';
        foreach ([__DIR__.'/data/last_farmasi.json', __DIR__.'/last_farmasi.json', sys_get_temp_dir().'/last_farmasi.json'] as $file) {
            $dir = dirname($file);
            if (!file_exists($dir)) @mkdir($dir, 0777, true);
            $bytes = @file_put_contents($file, json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            if ($bytes !== false && $bytes > 0) {
                @chmod($file, 0666);
                $file_saved = true;
                $file_path  = $file;
                break;
            }
        }

        echo json_encode([
            'status'      => 'ok',
            'jml_panggil' => $jml,
            'file_saved'  => $file_saved,
            'file_path'   => $file_path,
            'data'        => $jsonData,
        ]);

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// AJAX HANDLER: Update antriapotek3 sebelum penyerahan resep
// (Mengikuti logika Khanza: delete lalu insert)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'serah') {
    header('Content-Type: application/json');

    $no_resep = trim($_POST['no_resep'] ?? '');
    $no_rawat = trim($_POST['no_rawat'] ?? '');

    if (!$no_resep) {
        echo json_encode(['status' => 'error', 'message' => 'Nomor resep kosong']);
        exit;
    }

    try {
        // Ikuti persis logika Khanza:
        // "delete from antriapotek3"
        // "insert into antriapotek3 values('no_resep','1','no_rawat')"
        $pdo_simrs->exec("DELETE FROM antriapotek3");
        $stmt = $pdo_simrs->prepare("INSERT INTO antriapotek3 VALUES (?, '1', ?)");
        $stmt->execute([$no_resep, $no_rawat]);

        echo json_encode(['status' => 'ok']);

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// AMBIL DATA RESEP
// ============================================================
try {
    $baseSql = "
        SELECT
            ro.no_resep, ro.no_rawat, ro.tgl_peresepan, ro.jam_peresepan,
            r.no_rkm_medis, p.nm_pasien, d.nm_dokter,
            pl.nm_poli, pl.kd_poli, r.status_lanjut,
            CASE
                WHEN EXISTS (SELECT 1 FROM resep_dokter_racikan rr WHERE rr.no_resep = ro.no_resep)
                THEN 'Racikan'
                ELSE 'Non Racikan'
            END AS jenis_resep
        FROM resep_obat ro
        INNER JOIN reg_periksa r  ON ro.no_rawat     = r.no_rawat
        INNER JOIN pasien      p  ON r.no_rkm_medis  = p.no_rkm_medis
        INNER JOIN dokter      d  ON ro.kd_dokter     = d.kd_dokter
        LEFT  JOIN poliklinik  pl ON r.kd_poli        = pl.kd_poli
        WHERE ro.tgl_peresepan = ? AND ro.status = 'ralan'
    ";
    $params = [$filter_tanggal];
    if (!empty($cari)) { $baseSql .= " AND p.nm_pasien LIKE ?"; $params[] = "%$cari%"; }

    $stmt = $pdo_simrs->prepare($baseSql . " ORDER BY ro.tgl_peresepan DESC, ro.jam_peresepan DESC LIMIT " . intval($limit) . " OFFSET " . intval($offset));
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $countSql = "SELECT COUNT(*) FROM resep_obat ro INNER JOIN reg_periksa r ON ro.no_rawat=r.no_rawat INNER JOIN pasien p ON r.no_rkm_medis=p.no_rkm_medis INNER JOIN dokter d ON ro.kd_dokter=d.kd_dokter LEFT JOIN poliklinik pl ON r.kd_poli=pl.kd_poli WHERE ro.tgl_peresepan=? AND ro.status='ralan'";
    $paramsCount = [$filter_tanggal];
    if (!empty($cari)) { $countSql .= " AND p.nm_pasien LIKE ?"; $paramsCount[] = "%$cari%"; }
    $countStmt = $pdo_simrs->prepare($countSql);
    $countStmt->execute($paramsCount);
    $total       = (int)$countStmt->fetchColumn();
    $total_pages = max(1, ceil($total / $limit));

    $statsSql = "SELECT COUNT(*) as total_resep,
        SUM(CASE WHEN EXISTS (SELECT 1 FROM resep_dokter_racikan rr WHERE rr.no_resep=ro.no_resep) THEN 1 ELSE 0 END) AS racikan,
        SUM(CASE WHEN NOT EXISTS (SELECT 1 FROM resep_dokter_racikan rr WHERE rr.no_resep=ro.no_resep) THEN 1 ELSE 0 END) AS non_racikan
        FROM resep_obat ro INNER JOIN reg_periksa r ON ro.no_rawat=r.no_rawat INNER JOIN pasien p ON r.no_rkm_medis=p.no_rkm_medis
        WHERE ro.tgl_peresepan=? AND ro.status='ralan'";
    $statsParams = [$filter_tanggal];
    if (!empty($cari)) { $statsSql .= " AND p.nm_pasien LIKE ?"; $statsParams[] = "%$cari%"; }
    $statsStmt = $pdo_simrs->prepare($statsSql);
    $statsStmt->execute($statsParams);
    $stats       = $statsStmt->fetch(PDO::FETCH_ASSOC);
    $total_resep = $stats['total_resep'] ?? 0;
    $racikan     = $stats['racikan']     ?? 0;
    $non_racikan = $stats['non_racikan'] ?? 0;

} catch (PDOException $e) {
    die("Gagal mengambil data: " . $e->getMessage());
}

// ============================================================
// BACA STATUS PANGGILAN DARI DATABASE
// ============================================================
$calledMap = [];
if (!empty($data)) {
    try {
        $noResepList = array_column($data, 'no_resep');
        $ph          = implode(',', array_fill(0, count($noResepList), '?'));
        $stmtCalled  = $pdo_simrs->prepare(
            "SELECT no_resep, jml_panggil FROM simpan_antrian_farmasi_wira WHERE tgl_panggil = ? AND no_resep IN ($ph)"
        );
        $stmtCalled->execute(array_merge([$filter_tanggal], $noResepList));
        foreach ($stmtCalled->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $calledMap[$row['no_resep']] = (int)$row['jml_panggil'];
        }
    } catch (PDOException $e) {
        $calledMap = [];
    }
}

// ============================================================
// URL SERVER PENYERAHAN RESEP
// Sesuaikan dengan alamat server Anda
// ============================================================
define('URL_PENYERAHAN', 'http://ipserver/webapps/penyerahanresep/index.php');

// ============================================================
// CSS & JS
// ============================================================
$page_title = 'Data Antrian Farmasi - MediFix';
$extra_css = '
.stats-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:20px; margin-bottom:20px; }
.stat-card { background:#fff; border-radius:5px; box-shadow:0 1px 3px rgba(0,0,0,.12); transition:all .3s; border-top:3px solid; overflow:hidden; }
.stat-card:hover { transform:translateY(-5px); box-shadow:0 5px 15px rgba(0,0,0,.2); }
.stat-card-content { padding:20px; display:flex; align-items:center; gap:15px; }
.stat-icon { width:60px; height:60px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:30px; color:#fff; }
.stat-info { flex:1; }
.stat-label { font-size:13px; color:#666; margin-bottom:5px; }
.stat-value { font-size:28px; font-weight:700; color:#333; }
.stat-total .stat-icon    { background:#f39c12; } .stat-total    { border-top-color:#f39c12; }
.stat-racikan .stat-icon  { background:#dd4b39; } .stat-racikan  { border-top-color:#dd4b39; }
.stat-nonracikan .stat-icon { background:#00a65a; } .stat-nonracikan { border-top-color:#00a65a; }

/* ── Tombol Panggil ── */
.btn-call { width:32px; height:32px; border-radius:6px; padding:0; display:inline-flex; align-items:center; justify-content:center; position:relative; transition:all .3s; }
.btn-call.not-called { background-color:#f39c12 !important; border-color:#e08e0b !important; color:#fff !important; }
.btn-call.called     { background-color:#00a65a !important; border-color:#008d4c !important; color:#fff !important; }
.call-counter { position:absolute; top:-6px; right:-6px; background:#dd4b39; color:#fff; font-size:9px; font-weight:800; width:16px; height:16px; border-radius:50%; display:flex; align-items:center; justify-content:center; border:2px solid #fff; }
.row-called { background-color:#fff3e0 !important; }
.badge-called { background:#00a65a; color:#fff; padding:3px 8px; border-radius:10px; font-size:10px; font-weight:700; margin-left:6px; }

/* ── Tombol Penyerahan (kamera) ── */
.btn-serah {
    width: 32px;
    height: 32px;
    border-radius: 6px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background-color: #3c8dbc !important;
    border-color: #367fa9 !important;
    color: #fff !important;
    transition: all .25s;
    border: 1px solid transparent;
    cursor: pointer;
    /* Hapus properti text-decoration karena bukan <a> lagi */
}
.btn-serah:hover {
    background-color: #367fa9 !important;
    color: #fff !important;
    transform: scale(1.12);
    box-shadow: 0 3px 8px rgba(54,127,169,.45);
}
.btn-serah:focus { outline: none; color:#fff !important; }
.btn-serah:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

/* Biru muda saat pasien sudah dipanggil — siap serah */
.btn-serah.siap-serah {
    background-color: #00c0ef !important;
    border-color: #00acd6 !important;
    animation: pulseSerah 1.8s infinite;
}
@keyframes pulseSerah {
    0%,100% { box-shadow: 0 0 0 0 rgba(0,192,239,.5); }
    50%      { box-shadow: 0 0 0 5px rgba(0,192,239,0); }
}

@media (max-width:992px) { .stats-grid { grid-template-columns:repeat(2,1fr); } }
@media (max-width:576px) { .stats-grid { grid-template-columns:1fr; } }
';

$extra_js = '
function angkaKeKata(n) {
    const satuan  = ["","satu","dua","tiga","empat","lima","enam","tujuh","delapan","sembilan"];
    const belasan = ["sepuluh","sebelas","dua belas","tiga belas","empat belas","lima belas",
                     "enam belas","tujuh belas","delapan belas","sembilan belas"];
    if (n === 0) return "nol";
    if (n < 10)  return satuan[n];
    if (n < 20)  return belasan[n - 10];
    if (n < 100) { const p=Math.floor(n/10),s=n%10; return satuan[p]+" puluh"+(s>0?" "+satuan[s]:""); }
    if (n < 200) { const s=n-100; return "seratus"+(s>0?" "+angkaKeKata(s):""); }
    if (n < 1000){ const r=Math.floor(n/100),s=n%100; return satuan[r]+" ratus"+(s>0?" "+angkaKeKata(s):""); }
    if (n < 2000){ const s=n-1000; return "seribu"+(s>0?" "+angkaKeKata(s):""); }
    const rb=Math.floor(n/1000),s=n%1000;
    return satuan[rb]+" ribu"+(s>0?" "+angkaKeKata(s):"");
}

function markAsCalled(noResep, count) {
    // Update tombol Panggil
    const btn = document.querySelector(`button[data-no-resep="${noResep}"]`);
    if (btn) {
        btn.classList.remove("not-called");
        btn.classList.add("called");
        let counterEl = btn.querySelector(".call-counter");
        if (count > 1) {
            if (!counterEl) { counterEl = document.createElement("span"); counterEl.className="call-counter"; btn.appendChild(counterEl); }
            counterEl.textContent = count;
        } else if (counterEl) {
            counterEl.remove();
        }
        const row = btn.closest("tr");
        if (row) row.classList.add("row-called");
    }

    // Badge antrian
    const safeId = noResep.replace(/[^a-zA-Z0-9]/g, "_");
    const badge  = document.getElementById("badge-" + safeId);
    if (badge) {
        badge.style.display = "inline-flex";
        badge.innerHTML = count > 1
            ? `<i class="fa fa-check-circle"></i> Dipanggil ${count}x`
            : `<i class="fa fa-check-circle"></i> Dipanggil`;
    }

    // Tombol Serah: aktifkan animasi siap-serah
    const btnSerah = document.querySelector(`button.btn-serah[data-no-resep="${noResep}"]`);
    if (btnSerah) btnSerah.classList.add("siap-serah");
}

function panggil(noResep, buttonElement) {
    buttonElement.disabled = true;
    const origHTML = buttonElement.innerHTML;
    buttonElement.innerHTML = "<i class=\"fa fa-spinner fa-spin\"></i>";

    const noRawat    = buttonElement.getAttribute("data-no-rawat")    || "";
    const noRkmMedis = buttonElement.getAttribute("data-no-rkm-medis")|| "";
    const noAntrian  = buttonElement.getAttribute("data-no-antrian")  || "";
    const nmPasien   = buttonElement.getAttribute("data-nm-pasien")   || "";
    const nmPoli     = buttonElement.getAttribute("data-nm-poli")     || "";
    const nmDokter   = buttonElement.getAttribute("data-nm-dokter")   || "";
    const jenisResep = buttonElement.getAttribute("data-jenis-resep") || "";

    fetch(window.location.href, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({
            action:       "panggil",
            no_resep:     noResep,
            no_rawat:     noRawat,
            no_rkm_medis: noRkmMedis,
            no_antrian:   noAntrian,
            nm_pasien:    nmPasien,
            nm_poli:      nmPoli,
            nm_dokter:    nmDokter,
            jenis_resep:  jenisResep
        })
    })
    .then(r => r.json())
    .then(resp => {
        if (resp.status !== "ok") {
            alert("Gagal memanggil: " + (resp.message || ""));
            buttonElement.disabled = false;
            buttonElement.innerHTML = origHTML;
            return;
        }

        markAsCalled(noResep, resp.jml_panggil);
        buttonElement.disabled = false;
        buttonElement.innerHTML = "<i class=\"fa fa-check\"></i>";

        const raw           = noResep.slice(-4);
        const angka         = parseInt(raw, 10);
        const nomorKata     = angkaKeKata(angka);
        const namaTitleCase = nmPasien.toLowerCase().replace(/\b\w/g, c => c.toUpperCase());
        const teks = `Nomor antrian farmasi, F ${nomorKata}. Atas nama, ${namaTitleCase}. Silakan menuju loket farmasi.`;

        window.speechSynthesis.cancel();

        function speak(text) {
            const u = new SpeechSynthesisUtterance(text);
            u.lang="id-ID"; u.rate=0.85; u.pitch=1.1; u.volume=1.0;
            const idv = window.speechSynthesis.getVoices().find(v => v.lang.includes("id"));
            if (idv) u.voice = idv;
            window.speechSynthesis.speak(u);
        }

        const bell = new Audio("sound/opening.mp3");
        bell.volume = 1.0;
        bell.play()
            .then(() => { bell.addEventListener("ended", () => speak(teks)); })
            .catch(()  => speak(teks));
    })
    .catch(err => {
        console.error("Error:", err);
        alert("Koneksi gagal. Silakan coba lagi.");
        buttonElement.disabled = false;
        buttonElement.innerHTML = origHTML;
    });
}

// ============================================================
// Fungsi Penyerahan Resep
// Update antriapotek3 terlebih dahulu (ikuti logika Khanza),
// baru buka halaman kamera penyerahan
// ============================================================
function serahResep(noResep, noRawat, noRm, nmPasien, noAntrian, nmPoli, buttonElement) {
    buttonElement.disabled = true;
    const origHTML = buttonElement.innerHTML;
    buttonElement.innerHTML = "<i class=\"fa fa-spinner fa-spin\"></i>";

    fetch(window.location.href, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({
            action:   "serah",
            no_resep: noResep,
            no_rawat: noRawat
        })
    })
    .then(r => r.json())
    .then(resp => {
        buttonElement.disabled = false;
        buttonElement.innerHTML = origHTML;

        if (resp.status !== "ok") {
            alert("Gagal menyiapkan penyerahan: " + (resp.message || ""));
            return;
        }

        // Bangun URL penyerahan dengan parameter lengkap
        const baseUrl = urlPenyerahan; // variabel JS yang di-set dari PHP di bawah
        const params  = new URLSearchParams({
            act:        "Kamera",
            no_resep:   noResep,
            no_rawat:   noRawat,
            no_rm:      noRm,
            nm_pasien:  nmPasien,
            no_antrian: noAntrian,
            nm_poli:    nmPoli || "Instalasi Farmasi"
        });

        window.open(baseUrl + "?" + params.toString(), "_blank");
    })
    .catch(err => {
        console.error("Error:", err);
        alert("Koneksi gagal. Silakan coba lagi.");
        buttonElement.disabled = false;
        buttonElement.innerHTML = origHTML;
    });
}

if ("speechSynthesis" in window) {
    speechSynthesis.onvoiceschanged = () => speechSynthesis.getVoices();
    speechSynthesis.getVoices();
}
';

// Inject URL penyerahan ke JS sebagai variabel global
// Cara ini aman karena PHP langsung echo nilai string ke output HTML
$extra_js_inline = '<script>var urlPenyerahan = ' . json_encode(URL_PENYERAHAN) . ';</script>';

include 'includes/header.php';
include 'includes/sidebar.php';

// Cetak variabel JS inline setelah header
echo $extra_js_inline;
?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Data Antrian Farmasi</h1>
      <ol class="breadcrumb">
        <li><a href="dashboard.php"><i class="fa fa-dashboard"></i> Home</a></li>
        <li><a href="#">Farmasi</a></li>
        <li class="active">Data Antrian</li>
      </ol>
    </section>

    <section class="content">

      <!-- Stats -->
      <div class="stats-grid">
        <div class="stat-card stat-total">
          <div class="stat-card-content">
            <div class="stat-icon"><i class="fa fa-list-ol"></i></div>
            <div class="stat-info"><div class="stat-label">Total Resep</div><div class="stat-value"><?= $total_resep ?></div></div>
          </div>
        </div>
        <div class="stat-card stat-racikan">
          <div class="stat-card-content">
            <div class="stat-icon"><i class="fa fa-flask"></i></div>
            <div class="stat-info"><div class="stat-label">Racikan</div><div class="stat-value"><?= $racikan ?></div></div>
          </div>
        </div>
        <div class="stat-card stat-nonracikan">
          <div class="stat-card-content">
            <div class="stat-icon"><i class="fa fa-plus-square"></i></div>
            <div class="stat-info"><div class="stat-label">Non Racikan</div><div class="stat-value"><?= $non_racikan ?></div></div>
          </div>
        </div>
      </div>

      <div class="row">
        <div class="col-xs-12">

          <!-- Filter -->
          <div class="box">
            <div class="box-header"><h3 class="box-title">Filter Data</h3></div>
            <div class="box-body">
              <form method="GET" class="form-inline">
                <div class="form-group">
                  <label>Tanggal:</label>
                  <input type="date" name="tanggal" class="form-control" value="<?= htmlspecialchars($filter_tanggal) ?>">
                </div>
                <div class="form-group">
                  <label>Cari Nama Pasien:</label>
                  <input type="text" name="cari" class="form-control" placeholder="Ketik nama pasien..." value="<?= htmlspecialchars($cari) ?>">
                </div>
                <div class="form-group">
                  <label>Tampilkan:</label>
                  <select name="limit" class="form-control">
                    <option value="20" <?= $limit==20?'selected':'' ?>>20</option>
                    <option value="50" <?= $limit==50?'selected':'' ?>>50</option>
                    <option value="100" <?= $limit==100?'selected':'' ?>>100</option>
                  </select>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fa fa-filter"></i> Filter</button>
                <a href="data_antri_farmasi.php" class="btn btn-default"><i class="fa fa-refresh"></i> Reset</a>
              </form>
            </div>
          </div>

          <!-- Tabel -->
          <div class="box">
            <div class="box-header">
              <h3 class="box-title">Daftar Antrian (<?= count($data) ?> dari <?= $total ?>)</h3>
            </div>
            <div class="box-body">

              <?php if ($total > 0): ?>
              <div class="table-responsive">
                <table class="table table-bordered table-striped table-hover">
                  <thead style="background:#f39c12;color:#fff;">
                    <tr>
                      <th width="50">No</th>
                      <th width="50">Panggil</th>
                      <th width="50">Serah</th>
                      <th width="120">No. Antrian</th>
                      <th width="150">No. Resep</th>
                      <th width="120">No. RM</th>
                      <th>Nama Pasien</th>
                      <th width="150">Poli</th>
                      <th width="180">Dokter</th>
                      <th width="120">Jenis Resep</th>
                      <th width="100">Waktu</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php
                    $no = ($page - 1) * $limit + 1;
                    foreach ($data as $r):
                        $no_antrian     = 'F' . str_pad(substr($r['no_resep'], -4), 4, '0', STR_PAD_LEFT);
                        $jmlPanggil     = $calledMap[$r['no_resep']] ?? 0;
                        $sudahDipanggil = $jmlPanggil > 0;
                        $safeId         = preg_replace('/[^a-zA-Z0-9]/', '_', $r['no_resep']);
                    ?>
                    <tr class="<?= $sudahDipanggil ? 'row-called' : '' ?>">
                      <td><?= $no++ ?></td>

                      <!-- Tombol Panggil -->
                      <td style="text-align:center;vertical-align:middle;">
                        <button class="btn btn-call <?= $sudahDipanggil ? 'called' : 'not-called' ?>"
                                data-no-resep="<?= htmlspecialchars($r['no_resep']) ?>"
                                data-no-rawat="<?= htmlspecialchars($r['no_rawat']) ?>"
                                data-no-rkm-medis="<?= htmlspecialchars($r['no_rkm_medis']) ?>"
                                data-no-antrian="<?= htmlspecialchars($no_antrian) ?>"
                                data-nm-pasien="<?= htmlspecialchars($r['nm_pasien']) ?>"
                                data-nm-poli="<?= htmlspecialchars($r['nm_poli'] ?? '') ?>"
                                data-nm-dokter="<?= htmlspecialchars($r['nm_dokter']) ?>"
                                data-jenis-resep="<?= htmlspecialchars($r['jenis_resep']) ?>"
                                onclick="panggil('<?= addslashes($r['no_resep']) ?>', this)"
                                title="Panggil <?= htmlspecialchars($r['nm_pasien']) ?>">
                          <i class="fa fa-<?= $sudahDipanggil ? 'check' : 'phone' ?>"></i>
                          <?php if ($jmlPanggil > 1): ?>
                          <span class="call-counter"><?= $jmlPanggil ?></span>
                          <?php endif; ?>
                        </button>
                      </td>

                      <!-- Tombol Penyerahan (Kamera) -->
                      <!-- Klik → update antriapotek3 dulu (ikuti logika Khanza) → buka halaman kamera -->
                      <td style="text-align:center;vertical-align:middle;">
                        <button class="btn-serah<?= $sudahDipanggil ? ' siap-serah' : '' ?>"
                                data-no-resep="<?= htmlspecialchars($r['no_resep']) ?>"
                                onclick="serahResep(
                                    '<?= addslashes($r['no_resep']) ?>',
                                    '<?= addslashes($r['no_rawat']) ?>',
                                    '<?= addslashes($r['no_rkm_medis']) ?>',
                                    '<?= addslashes($r['nm_pasien']) ?>',
                                    '<?= addslashes($no_antrian) ?>',
                                    '<?= addslashes($r['nm_poli'] ?? '') ?>',
                                    this
                                )"
                                title="Penyerahan Resep: <?= htmlspecialchars($r['nm_pasien']) ?> (<?= htmlspecialchars($no_antrian) ?>)">
                          <i class="fa fa-camera"></i>
                        </button>
                      </td>

                      <!-- No Antrian + Badge -->
                      <td>
                        <strong><?= htmlspecialchars($no_antrian) ?></strong>
                        <span class="badge-called" id="badge-<?= $safeId ?>"
                              style="display:<?= $sudahDipanggil ? 'inline-flex' : 'none' ?>;">
                          <i class="fa fa-check-circle"></i>
                          <?= $jmlPanggil > 1 ? "Dipanggil {$jmlPanggil}x" : 'Dipanggil' ?>
                        </span>
                      </td>

                      <td><?= htmlspecialchars($r['no_resep']) ?></td>
                      <td><?= htmlspecialchars($r['no_rkm_medis']) ?></td>
                      <td><strong><?= htmlspecialchars($r['nm_pasien']) ?></strong></td>
                      <td><?= htmlspecialchars($r['nm_poli'] ?? '-') ?></td>
                      <td><?= htmlspecialchars($r['nm_dokter']) ?></td>
                      <td>
                        <?php if ($r['jenis_resep'] === 'Racikan'): ?>
                          <span class="label label-danger"><?= $r['jenis_resep'] ?></span>
                        <?php else: ?>
                          <span class="label label-success"><?= $r['jenis_resep'] ?></span>
                        <?php endif; ?>
                      </td>
                      <td><?= date('H:i', strtotime($r['jam_peresepan'])) ?> WIB</td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>

              <?php if ($total_pages > 1): ?>
              <div class="box-footer clearfix">
                <ul class="pagination pagination-sm no-margin pull-right">
                  <?php if ($page > 1): ?>
                  <li><a href="?page=<?= $page-1 ?>&tanggal=<?= urlencode($filter_tanggal) ?>&cari=<?= urlencode($cari) ?>&limit=<?= $limit ?>">«</a></li>
                  <?php endif; ?>
                  <?php for ($i = max(1,$page-2); $i <= min($total_pages,$page+2); $i++): ?>
                  <li <?= $i==$page?'class="active"':'' ?>><a href="?page=<?=$i?>&tanggal=<?=urlencode($filter_tanggal)?>&cari=<?=urlencode($cari)?>&limit=<?=$limit?>"><?=$i?></a></li>
                  <?php endfor; ?>
                  <?php if ($page < $total_pages): ?>
                  <li><a href="?page=<?= $page+1 ?>&tanggal=<?= urlencode($filter_tanggal) ?>&cari=<?= urlencode($cari) ?>&limit=<?= $limit ?>">»</a></li>
                  <?php endif; ?>
                </ul>
              </div>
              <?php endif; ?>

              <?php else: ?>
              <div class="callout callout-info">
                <h4><i class="fa fa-info"></i> Informasi</h4>
                <p>Tidak ada resep untuk tanggal <?= date('d F Y', strtotime($filter_tanggal)) ?></p>
              </div>
              <?php endif; ?>

            </div>
          </div>

        </div>
      </div>
    </section>
  </div>

<?php include 'includes/footer.php'; ?>