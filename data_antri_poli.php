<?php
session_start();
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$nama  = $_SESSION['nama'] ?? 'Pengguna';
$today = date('Y-m-d');

// === POLI YANG DISEMBUNYIKAN ===
$exclude_poli = ['IGDK','MCU01','PL010','PL011','PL013','PL014','PL015','PL016','PL017','U0022','U0026','U0028','U0030'];

// === PAGINATION ===
$limit  = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 20;
$page   = isset($_GET['page'])  ? max(1, intval($_GET['page']))  : 1;
$offset = ($page - 1) * $limit;

// === FILTER POLI & DOKTER ===
$filter_poli   = $_GET['poli']   ?? '';
$filter_dokter = $_GET['dokter'] ?? '';

// === AMBIL DAFTAR POLI ===
try {
    $placeholders = implode(',', array_fill(0, count($exclude_poli), '?'));
    $stmtPoli = $pdo_simrs->prepare("SELECT kd_poli, nm_poli FROM poliklinik WHERE status='1' AND kd_poli NOT IN ($placeholders) ORDER BY nm_poli ASC");
    $stmtPoli->execute($exclude_poli);
    $poliklinik = $stmtPoli->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { die("Gagal ambil daftar poli: " . $e->getMessage()); }

// === AMBIL DAFTAR DOKTER ===
try {
    $stmtDokter = $pdo_simrs->query("SELECT kd_dokter, nm_dokter FROM dokter ORDER BY nm_dokter ASC");
    $dokterList = $stmtDokter->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { die("Gagal ambil daftar dokter: " . $e->getMessage()); }

// === AMBIL STATISTIK ===
try {
    $placeholders = implode(',', array_fill(0, count($exclude_poli), '?'));
    $baseWhere = "r.tgl_registrasi = ? AND p.status='1' AND r.kd_poli NOT IN ($placeholders)";
    $baseParams = array_merge([$today], $exclude_poli);

    $total_pasien    = (int)$pdo_simrs->prepare("SELECT COUNT(*) FROM reg_periksa r LEFT JOIN poliklinik p ON r.kd_poli=p.kd_poli WHERE $baseWhere")->execute($baseParams) ? $pdo_simrs->prepare("SELECT COUNT(*) FROM reg_periksa r LEFT JOIN poliklinik p ON r.kd_poli=p.kd_poli WHERE $baseWhere")->execute($baseParams) : 0;

    $stmtSt = function($extra) use ($pdo_simrs, $baseWhere, $baseParams) {
        $s = $pdo_simrs->prepare("SELECT COUNT(*) FROM reg_periksa r LEFT JOIN poliklinik p ON r.kd_poli=p.kd_poli WHERE $baseWhere $extra");
        $s->execute($baseParams);
        return (int)$s->fetchColumn();
    };

    $total_pasien    = $stmtSt("");
    $sudah_dilayani  = $stmtSt("AND r.stts='Sudah'");
    $menunggu        = $stmtSt("AND r.stts IN ('Menunggu','Belum')");
    $batal           = $stmtSt("AND r.stts='Batal'");

} catch (PDOException $e) {
    $total_pasien = $sudah_dilayani = $menunggu = $batal = 0;
}

// === AMBIL DATA REGISTRASI POLI ===
try {
    $placeholders = implode(',', array_fill(0, count($exclude_poli), '?'));
    $sql = "
        SELECT r.no_reg, r.no_rawat, r.no_rkm_medis, r.kd_dokter, r.kd_poli,
               r.tgl_registrasi, r.jam_reg, r.stts, r.status_lanjut,
               d.nm_dokter, p.nm_poli, ps.nm_pasien
        FROM reg_periksa r
        LEFT JOIN dokter     d  ON r.kd_dokter    = d.kd_dokter
        LEFT JOIN poliklinik p  ON r.kd_poli       = p.kd_poli
        LEFT JOIN pasien     ps ON r.no_rkm_medis  = ps.no_rkm_medis
        WHERE r.tgl_registrasi = ? AND p.status = '1' AND r.kd_poli NOT IN ($placeholders)
    ";
    $params = array_merge([$today], $exclude_poli);

    if (!empty($filter_poli) && !in_array($filter_poli, $exclude_poli)) {
        $sql .= " AND r.kd_poli = ?";  $params[] = $filter_poli;
    }
    if (!empty($filter_dokter)) {
        $sql .= " AND r.kd_dokter = ?"; $params[] = $filter_dokter;
    }
    $sql .= " ORDER BY r.jam_reg ASC LIMIT " . intval($limit) . " OFFSET " . intval($offset);

    $stmt = $pdo_simrs->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Hitung total
    $countSql = "SELECT COUNT(*) FROM reg_periksa r LEFT JOIN poliklinik p ON r.kd_poli=p.kd_poli WHERE r.tgl_registrasi=? AND p.status='1' AND r.kd_poli NOT IN ($placeholders)";
    $paramsCount = array_merge([$today], $exclude_poli);
    if (!empty($filter_poli) && !in_array($filter_poli, $exclude_poli)) { $countSql .= " AND r.kd_poli=?"; $paramsCount[] = $filter_poli; }
    if (!empty($filter_dokter)) { $countSql .= " AND r.kd_dokter=?"; $paramsCount[] = $filter_dokter; }
    $countStmt = $pdo_simrs->prepare($countSql);
    $countStmt->execute($paramsCount);
    $total       = (int)$countStmt->fetchColumn();
    $total_pages = max(1, ceil($total / $limit));

} catch (PDOException $e) { die("Gagal mengambil data: " . $e->getMessage()); }

// =============================================================
// BACA STATUS PANGGILAN DARI DATABASE (bukan localStorage!)
// Ini yang membuat status konsisten di semua komputer & refresh
// =============================================================
$calledMap = []; // [no_rawat => jml_panggil]
if (!empty($data)) {
    try {
        $noRawatList  = array_column($data, 'no_rawat');
        $phCalled     = implode(',', array_fill(0, count($noRawatList), '?'));
        $stmtCalled   = $pdo_simrs->prepare(
            "SELECT no_rawat, jml_panggil FROM simpan_antrian_poli_wira WHERE tgl_panggil = ? AND no_rawat IN ($phCalled)"
        );
        $stmtCalled->execute(array_merge([$today], $noRawatList));
        foreach ($stmtCalled->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $calledMap[$row['no_rawat']] = (int)$row['jml_panggil'];
        }
    } catch (PDOException $e) {
        // Tabel belum dibuat — tidak masalah, abaikan
        $calledMap = [];
    }
}

$page_title = 'Data Antrian Poli - MediFix';
$extra_css = '
.stats-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:20px; margin-bottom:20px; }
.stat-card { background:#fff; border-radius:5px; box-shadow:0 1px 3px rgba(0,0,0,.12); transition:all .3s; border-top:3px solid; overflow:hidden; }
.stat-card:hover { transform:translateY(-5px); box-shadow:0 5px 15px rgba(0,0,0,.2); }
.stat-card-content { padding:20px; display:flex; align-items:center; gap:15px; }
.stat-icon { width:60px; height:60px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:30px; color:#fff; }
.stat-info { flex:1; }
.stat-label { font-size:13px; color:#666; margin-bottom:5px; }
.stat-value { font-size:28px; font-weight:700; color:#333; }
.stat-total .stat-icon    { background:#3c8dbc; } .stat-total    { border-top-color:#3c8dbc; }
.stat-sudah .stat-icon    { background:#00a65a; } .stat-sudah    { border-top-color:#00a65a; }
.stat-menunggu .stat-icon { background:#f39c12; } .stat-menunggu { border-top-color:#f39c12; }
.stat-batal .stat-icon    { background:#dd4b39; } .stat-batal    { border-top-color:#dd4b39; }

/* Tombol panggil */
.btn-call {
  width:32px; height:32px; border-radius:6px; padding:0;
  display:inline-flex; align-items:center; justify-content:center;
  position:relative; transition:all .3s;
}
/* MERAH = belum pernah dipanggil */
.btn-call.not-called {
  background-color:#dd4b39 !important;
  border-color:#d33724 !important;
  color:#fff !important;
}
/* HIJAU = sudah dipanggil (persisten dari DB) */
.btn-call.called {
  background-color:#00a65a !important;
  border-color:#008d4c !important;
  color:#fff !important;
}

.call-counter {
  position:absolute; top:-6px; right:-6px;
  background:#f39c12; color:#fff;
  font-size:9px; font-weight:800;
  width:16px; height:16px; border-radius:50%;
  display:flex; align-items:center; justify-content:center;
  border:2px solid #fff;
}
.row-called { background-color:#f0fdf4 !important; }
.badge-called {
  background:#00a65a; color:#fff;
  padding:3px 8px; border-radius:10px;
  font-size:10px; font-weight:700; margin-left:6px;
}

.btn-print { width:32px; height:32px; border-radius:6px; padding:0; display:inline-flex; align-items:center; justify-content:center; }
@media (max-width:992px) { .stats-grid { grid-template-columns:repeat(2,1fr); } }
@media (max-width:576px) { .stats-grid { grid-template-columns:1fr; } }
@media print {
  body * { visibility:hidden; }
  #printArea, #printArea * { visibility:visible; }
  #printArea { position:absolute; left:0; top:0; width:80mm; }
}
';

$extra_js = '
// -------------------------------------------------------
//  PRELOAD AUDIO & VOICES saat halaman load
//  Menghilangkan delay saat tombol diklik pertama kali
// -------------------------------------------------------
let bell        = null;
let cachedVoice = null;

document.addEventListener("DOMContentLoaded", function() {
    // Preload audio — browser download sekarang, bukan saat klik
    bell = new Audio("sound/opening.mp3");
    bell.preload = "auto";
    bell.load();
    bell.volume  = 1.0;

    // Preload voice list
    const loadVoices = () => {
        const voices = window.speechSynthesis.getVoices();
        if (voices.length > 0) {
            cachedVoice = voices.find(v => v.lang.includes("id")) || null;
        }
    };
    loadVoices();
    window.speechSynthesis.onvoiceschanged = loadVoices;
});

function updateClock() {
    const now = new Date();
    document.getElementById("tanggalSekarang").innerHTML = now.toLocaleDateString("id-ID", { weekday:"long", year:"numeric", month:"long", day:"numeric" });
    document.getElementById("clockDisplay").innerHTML    = now.toLocaleTimeString("id-ID");
}
setInterval(updateClock, 1000);
updateClock();

function markAsCalled(noRawat, count) {
    const button = document.querySelector(`button[data-no-rawat="${noRawat}"]`);
    if (!button) return;
    button.classList.remove("not-called");
    button.classList.add("called");
    let counterEl = button.querySelector(".call-counter");
    if (count > 1) {
        if (!counterEl) { counterEl = document.createElement("span"); counterEl.className="call-counter"; button.appendChild(counterEl); }
        counterEl.textContent = count;
    } else if (counterEl) {
        counterEl.remove();
    }
    const row = button.closest("tr");
    if (row) row.classList.add("row-called");
    const badge = document.getElementById("badge-" + noRawat.replace(/\//g,"_"));
    if (badge) {
        badge.style.display = "inline-flex";
        badge.innerHTML = count > 1
            ? `<i class="fa fa-check-circle"></i> Dipanggil ${count}x`
            : `<i class="fa fa-check-circle"></i> Dipanggil`;
    }
}

// speak() pakai cachedVoice — tidak ada delay lookup
function speak(text) {
    const u = new SpeechSynthesisUtterance(text);
    u.lang="id-ID"; u.pitch=1.0; u.rate=0.9; u.volume=1.0;
    if (cachedVoice) u.voice = cachedVoice;
    window.speechSynthesis.speak(u);
}

function angkaToSuara(num) {
    num = parseInt(num);
    const a = ["","satu","dua","tiga","empat","lima","enam","tujuh","delapan","sembilan","sepuluh","sebelas"];
    if (num < 12) return a[num];
    if (num < 20) return a[num-10] + " belas";
    if (num < 100) { const p=Math.floor(num/10),s=num%10; return a[p]+" puluh"+(s>0?" "+a[s]:""); }
    return num.toString();
}

// -------------------------------------------------------
//  PANGGIL PASIEN
//  Suara langsung saat klik — fetch ke server di background
// -------------------------------------------------------
function panggilPasien(noAntri, poli, noRawat, buttonElement) {
    const kdDokter   = buttonElement.getAttribute("data-kd-dokter")   || "";
    const nmDokter   = buttonElement.getAttribute("data-nm-dokter")   || "";
    const nmPasien   = buttonElement.getAttribute("data-nm-pasien")   || "";
    const noRkmMedis = buttonElement.getAttribute("data-no-rkm-medis")|| "";

    const parts    = noAntri.split("-");
    const kodePoli = parts[0];
    const nomor    = parts.slice(1).join("-");
    const kalimat  = `Nomor antrian ${kodePoli.split("").join(" ")} ${angkaToSuara(nomor)}, silakan menuju ${poli}.`;

    // 1. SUARA LANGSUNG — tidak tunggu fetch sama sekali
    window.speechSynthesis.cancel();
    if (bell) {
        bell.currentTime = 0;
        bell.play()
            .then(() => { bell.onended = () => speak(kalimat); })
            .catch(() => speak(kalimat));
    } else {
        speak(kalimat);
    }

    // 2. UI update langsung
    markAsCalled(noRawat, 1);

    // 3. Fetch ke server di background (tidak memblokir suara)
    fetch("simpan_panggil.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            no_antrian: noAntri, nm_poli: poli, nm_pasien: nmPasien,
            no_rawat: noRawat, no_rkm_medis: noRkmMedis,
            kd_poli: kodePoli, kd_dokter: kdDokter, nm_dokter: nmDokter
        })
    })
    .then(r => r.json())
    .then(result => {
        if (result.success && result.jml_panggil > 1) {
            markAsCalled(noRawat, result.jml_panggil);
        }
    })
    .catch(err => console.error("Save call error:", err));
}

function cetakKarcisFarmasi(noRawat, nmPasien) {
    window.open(`cetak_karcis_farmasi.php?no_rawat=${encodeURIComponent(noRawat)}`, "_blank", "width=400,height=600");
}
';

include 'includes/header.php';
include 'includes/sidebar.php';
?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Data Antrian Poliklinik</h1>
      <ol class="breadcrumb">
        <li><a href="dashboard.php"><i class="fa fa-dashboard"></i> Home</a></li>
        <li><a href="#">Poliklinik</a></li>
        <li class="active">Data Antrian</li>
      </ol>
    </section>

    <section class="content">

      <!-- Stats Cards -->
      <div class="stats-grid">
        <div class="stat-card stat-total">
          <div class="stat-card-content">
            <div class="stat-icon"><i class="fa fa-users"></i></div>
            <div class="stat-info">
              <div class="stat-label">Total Pasien</div>
              <div class="stat-value"><?= $total_pasien ?></div>
            </div>
          </div>
        </div>
        <div class="stat-card stat-sudah">
          <div class="stat-card-content">
            <div class="stat-icon"><i class="fa fa-check-circle"></i></div>
            <div class="stat-info">
              <div class="stat-label">Sudah Dilayani</div>
              <div class="stat-value"><?= $sudah_dilayani ?></div>
            </div>
          </div>
        </div>
        <div class="stat-card stat-menunggu">
          <div class="stat-card-content">
            <div class="stat-icon"><i class="fa fa-clock-o"></i></div>
            <div class="stat-info">
              <div class="stat-label">Sedang Menunggu</div>
              <div class="stat-value"><?= $menunggu ?></div>
            </div>
          </div>
        </div>
        <div class="stat-card stat-batal">
          <div class="stat-card-content">
            <div class="stat-icon"><i class="fa fa-times-circle"></i></div>
            <div class="stat-info">
              <div class="stat-label">Batal</div>
              <div class="stat-value"><?= $batal ?></div>
            </div>
          </div>
        </div>
      </div>

      <div class="row">
        <div class="col-xs-12">

          <!-- Filter -->
          <div class="box">
            <div class="box-header">
              <h3 class="box-title">Filter Data</h3>
            </div>
            <div class="box-body">
              <form method="GET" class="form-inline">
                <div class="form-group">
                  <label>Poliklinik:</label>
                  <select name="poli" class="form-control">
                    <option value="">Semua Poliklinik</option>
                    <?php foreach ($poliklinik as $p): ?>
                    <option value="<?= htmlspecialchars($p['kd_poli']) ?>" <?= $filter_poli==$p['kd_poli']?'selected':'' ?>>
                      <?= htmlspecialchars($p['nm_poli']) ?>
                    </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group">
                  <label>Dokter:</label>
                  <select name="dokter" class="form-control">
                    <option value="">Semua Dokter</option>
                    <?php foreach ($dokterList as $d): ?>
                    <option value="<?= htmlspecialchars($d['kd_dokter']) ?>" <?= $filter_dokter==$d['kd_dokter']?'selected':'' ?>>
                      <?= htmlspecialchars($d['nm_dokter']) ?>
                    </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group">
                  <label>Tampilkan:</label>
                  <select name="limit" class="form-control">
                    <option value="20" <?= $limit==20?'selected':'' ?>>20</option>
                    <option value="50" <?= $limit==50?'selected':'' ?>>50</option>
                    <option value="100" <?= $limit==100?'selected':'' ?>>100</option>
                  </select>
                </div>
                <button type="submit" class="btn btn-primary">
                  <i class="fa fa-filter"></i> Filter
                </button>
              </form>
            </div>
          </div>

          <!-- Tabel Data -->
          <div class="box">
            <div class="box-header">
              <h3 class="box-title">
                Daftar Antrian (<?= count($data) ?> dari <?= $total ?>)
              </h3>
            </div>
            <div class="box-body">

              <?php if ($total > 0): ?>
              <div class="table-responsive">
                <table class="table table-bordered table-striped table-hover">
                  <thead style="background:#605ca8;color:#fff;">
                    <tr>
                      <th width="80">Panggil</th>
                      <th width="80">Cetak</th>
                      <th width="110">No Antrian</th>
                      <th>No Rawat</th>
                      <th>No RM</th>
                      <th>Nama Pasien</th>
                      <th>Dokter</th>
                      <th>Poliklinik</th>
                      <th width="120">Status</th>
                      <th width="100">Lanjut</th>
                      <th width="100">Jam Reg</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($data as $row):
                        $noRawat     = $row['no_rawat'];
                        $jmlPanggil  = $calledMap[$noRawat] ?? 0;  // 0 = belum pernah dipanggil
                        $sudahDipanggil = $jmlPanggil > 0;

                        $statusClass = match (strtolower($row['stts'])) {
                            'sudah'             => 'success',
                            'menunggu','belum'  => 'warning',
                            'batal'             => 'danger',
                            default             => 'default'
                        };
                        // ID aman untuk HTML (ganti / jadi _)
                        $badgeId = 'badge-' . str_replace('/', '_', $noRawat);
                    ?>
                    <tr class="<?= $sudahDipanggil ? 'row-called' : '' ?>">

                      <!-- Tombol Panggil — warna dari DB, bukan localStorage -->
                      <td>
                        <button class="btn btn-call <?= $sudahDipanggil ? 'called' : 'not-called' ?>"
                                data-no-rawat="<?= htmlspecialchars($noRawat) ?>"
                                data-no-rkm-medis="<?= htmlspecialchars($row['no_rkm_medis']) ?>"
                                data-kd-dokter="<?= htmlspecialchars($row['kd_dokter']) ?>"
                                data-nm-dokter="<?= htmlspecialchars($row['nm_dokter']) ?>"
                                data-nm-pasien="<?= htmlspecialchars($row['nm_pasien']) ?>"
                                onclick="panggilPasien(
                                    '<?= addslashes($row['kd_poli'].'-'.$row['no_reg']) ?>',
                                    '<?= addslashes($row['nm_poli']) ?>',
                                    '<?= htmlspecialchars($noRawat) ?>',
                                    this
                                )">
                          <i class="fa fa-<?= $sudahDipanggil ? 'check' : 'phone' ?>"></i>
                          <?php if ($jmlPanggil > 1): ?>
                          <span class="call-counter"><?= $jmlPanggil ?></span>
                          <?php endif; ?>
                        </button>
                      </td>

                      <!-- Tombol Cetak -->
                      <td>
                        <button class="btn btn-info btn-print"
                                onclick="cetakKarcisFarmasi('<?= htmlspecialchars($noRawat) ?>','<?= addslashes($row['nm_pasien']) ?>')">
                          <i class="fa fa-print"></i>
                        </button>
                      </td>

                      <!-- No Antrian + Badge -->
                      <td>
                        <strong><?= htmlspecialchars($row['kd_poli'].'-'.$row['no_reg']) ?></strong>
                        <span class="badge-called" id="<?= $badgeId ?>"
                              style="display:<?= $sudahDipanggil ? 'inline-flex' : 'none' ?>;">
                          <i class="fa fa-check-circle"></i>
                          <?php if ($jmlPanggil > 1): ?>
                            Dipanggil <?= $jmlPanggil ?>x
                          <?php else: ?>
                            Dipanggil
                          <?php endif; ?>
                        </span>
                      </td>

                      <td><?= htmlspecialchars($row['no_rawat']) ?></td>
                      <td><?= htmlspecialchars($row['no_rkm_medis']) ?></td>
                      <td><strong><?= htmlspecialchars($row['nm_pasien']) ?></strong></td>
                      <td><?= htmlspecialchars($row['nm_dokter']) ?></td>
                      <td><?= htmlspecialchars($row['nm_poli']) ?></td>
                      <td><span class="label label-<?= $statusClass ?>"><?= htmlspecialchars($row['stts']) ?></span></td>
                      <td><?= htmlspecialchars($row['status_lanjut']) ?></td>
                      <td><?= date('H:i', strtotime($row['jam_reg'])) ?> WIB</td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>

              <?php if ($total_pages > 1): ?>
              <div class="box-footer clearfix">
                <ul class="pagination pagination-sm no-margin pull-right">
                  <?php if ($page > 1): ?>
                  <li><a href="?page=<?= $page-1 ?>&poli=<?= urlencode($filter_poli) ?>&dokter=<?= urlencode($filter_dokter) ?>&limit=<?= $limit ?>">«</a></li>
                  <?php endif; ?>
                  <?php for ($i = max(1,$page-2); $i <= min($total_pages,$page+2); $i++): ?>
                  <li <?= $i==$page ? 'class="active"' : '' ?>>
                    <a href="?page=<?=$i?>&poli=<?=urlencode($filter_poli)?>&dokter=<?=urlencode($filter_dokter)?>&limit=<?=$limit?>"><?=$i?></a>
                  </li>
                  <?php endfor; ?>
                  <?php if ($page < $total_pages): ?>
                  <li><a href="?page=<?= $page+1 ?>&poli=<?= urlencode($filter_poli) ?>&dokter=<?= urlencode($filter_dokter) ?>&limit=<?= $limit ?>">»</a></li>
                  <?php endif; ?>
                </ul>
              </div>
              <?php endif; ?>

              <?php else: ?>
              <div class="callout callout-info">
                <h4><i class="fa fa-info"></i> Informasi</h4>
                <p>Tidak ada pasien yang terdaftar pada poliklinik hari ini</p>
              </div>
              <?php endif; ?>

            </div>
          </div>

        </div>
      </div>
    </section>
  </div>

<?php include 'includes/footer.php'; ?>