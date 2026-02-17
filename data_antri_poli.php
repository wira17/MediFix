<?php
session_start();
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$nama = $_SESSION['nama'] ?? 'Pengguna';
$today = date('Y-m-d');

// === POLI YANG DISEMBUNYIKAN ===
$exclude_poli = ['IGDK','MCU01','PL010','PL011','PL013','PL014','PL015','PL016','PL017','U0022','U0026','U0028','U0030'];

// === PAGINATION ===
$limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 20;
$page  = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

// === FILTER POLI & DOKTER ===
$filter_poli   = $_GET['poli'] ?? '';
$filter_dokter = $_GET['dokter'] ?? '';

// === AMBIL DAFTAR POLI ===
try {
    $placeholders = implode(',', array_fill(0, count($exclude_poli), '?'));
    $sqlPoli = "SELECT kd_poli, nm_poli FROM poliklinik 
                WHERE status='1' AND kd_poli NOT IN ($placeholders)
                ORDER BY nm_poli ASC";
    $stmtPoli = $pdo_simrs->prepare($sqlPoli);
    $stmtPoli->execute($exclude_poli);
    $poliklinik = $stmtPoli->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Gagal ambil daftar poli: ".$e->getMessage());
}

// === AMBIL DAFTAR DOKTER ===
try {
    $sqlDokter = "SELECT kd_dokter, nm_dokter FROM dokter ORDER BY nm_dokter ASC";
    $stmtDokter = $pdo_simrs->query($sqlDokter);
    $dokterList = $stmtDokter->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Gagal ambil daftar dokter: ".$e->getMessage());
}

// === AMBIL STATISTIK ===
try {
    $placeholders = implode(',', array_fill(0, count($exclude_poli), '?'));
    
    $sqlTotal = "SELECT COUNT(*) FROM reg_periksa r
                 LEFT JOIN poliklinik p ON r.kd_poli = p.kd_poli
                 WHERE r.tgl_registrasi = ? AND p.status='1' AND r.kd_poli NOT IN ($placeholders)";
    $stmtTotal = $pdo_simrs->prepare($sqlTotal);
    $params = [$today];
    $params = array_merge($params, $exclude_poli);
    $stmtTotal->execute($params);
    $total_pasien = (int)$stmtTotal->fetchColumn();
    
    $sqlSudah = "SELECT COUNT(*) FROM reg_periksa r
                 LEFT JOIN poliklinik p ON r.kd_poli = p.kd_poli
                 WHERE r.tgl_registrasi = ? AND r.stts='Sudah' AND p.status='1' AND r.kd_poli NOT IN ($placeholders)";
    $stmtSudah = $pdo_simrs->prepare($sqlSudah);
    $stmtSudah->execute($params);
    $sudah_dilayani = (int)$stmtSudah->fetchColumn();
    
    $sqlMenunggu = "SELECT COUNT(*) FROM reg_periksa r
                    LEFT JOIN poliklinik p ON r.kd_poli = p.kd_poli
                    WHERE r.tgl_registrasi = ? AND r.stts IN ('Menunggu','Belum') AND p.status='1' AND r.kd_poli NOT IN ($placeholders)";
    $stmtMenunggu = $pdo_simrs->prepare($sqlMenunggu);
    $stmtMenunggu->execute($params);
    $menunggu = (int)$stmtMenunggu->fetchColumn();
    
    $sqlBatal = "SELECT COUNT(*) FROM reg_periksa r
                 LEFT JOIN poliklinik p ON r.kd_poli = p.kd_poli
                 WHERE r.tgl_registrasi = ? AND r.stts='Batal' AND p.status='1' AND r.kd_poli NOT IN ($placeholders)";
    $stmtBatal = $pdo_simrs->prepare($sqlBatal);
    $stmtBatal->execute($params);
    $batal = (int)$stmtBatal->fetchColumn();
    
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
        LEFT JOIN dokter d ON r.kd_dokter = d.kd_dokter
        LEFT JOIN poliklinik p ON r.kd_poli = p.kd_poli
        LEFT JOIN pasien ps ON r.no_rkm_medis = ps.no_rkm_medis
        WHERE r.tgl_registrasi = ?
          AND p.status = '1'
          AND r.kd_poli NOT IN ($placeholders)
    ";

    $params = [$today];
    $params = array_merge($params, $exclude_poli);

    if (!empty($filter_poli) && !in_array($filter_poli, $exclude_poli)) {
        $sql .= " AND r.kd_poli = ?";
        $params[] = $filter_poli;
    }

    if (!empty($filter_dokter)) {
        $sql .= " AND r.kd_dokter = ?";
        $params[] = $filter_dokter;
    }

    $sql .= " ORDER BY r.jam_reg ASC LIMIT " . intval($limit) . " OFFSET " . intval($offset);

    $stmt = $pdo_simrs->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // === HITUNG TOTAL ===
    $countSql = "
        SELECT COUNT(*) FROM reg_periksa r
        LEFT JOIN poliklinik p ON r.kd_poli = p.kd_poli
        WHERE r.tgl_registrasi = ?
          AND p.status='1'
          AND r.kd_poli NOT IN ($placeholders)
    ";
    $paramsCount = [$today];
    $paramsCount = array_merge($paramsCount, $exclude_poli);

    if (!empty($filter_poli) && !in_array($filter_poli, $exclude_poli)) {
        $countSql .= " AND r.kd_poli = ?";
        $paramsCount[] = $filter_poli;
    }

    if (!empty($filter_dokter)) {
        $countSql .= " AND r.kd_dokter = ?";
        $paramsCount[] = $filter_dokter;
    }

    $countStmt = $pdo_simrs->prepare($countSql);
    $countStmt->execute($paramsCount);
    $total = (int)$countStmt->fetchColumn();
    $total_pages = max(1, ceil($total / $limit));
} catch (PDOException $e) {
    die("Gagal mengambil data: " . $e->getMessage());
}

// Set page title dan extra CSS
$page_title = 'Data Antrian Poli - MediFix';
$extra_css = '
/* Welcome Box */
.welcome-box {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
  border-radius: 5px;
  padding: 25px;
  margin-bottom: 20px;
  box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.welcome-box h3 {
  margin: 0 0 10px 0;
  font-size: 24px;
  font-weight: 700;
}

.welcome-box p {
  margin: 0;
  opacity: 0.9;
  font-size: 14px;
}

.welcome-box .date-time {
  margin-top: 10px;
  font-size: 13px;
  opacity: 0.8;
}

/* Stats Cards */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 20px;
  margin-bottom: 20px;
}

.stat-card {
  background: #fff;
  border-radius: 5px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.12);
  transition: all 0.3s ease;
  border-top: 3px solid;
  overflow: hidden;
}

.stat-card:hover {
  transform: translateY(-5px);
  box-shadow: 0 5px 15px rgba(0,0,0,0.2);
}

.stat-card-content {
  padding: 20px;
  display: flex;
  align-items: center;
  gap: 15px;
}

.stat-icon {
  width: 60px;
  height: 60px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 30px;
  color: white;
}

.stat-info {
  flex: 1;
}

.stat-label {
  font-size: 13px;
  color: #666;
  margin-bottom: 5px;
}

.stat-value {
  font-size: 28px;
  font-weight: 700;
  color: #333;
}

.stat-total { border-top-color: #3c8dbc; }
.stat-total .stat-icon { background: #3c8dbc; }
.stat-sudah { border-top-color: #00a65a; }
.stat-sudah .stat-icon { background: #00a65a; }
.stat-menunggu { border-top-color: #f39c12; }
.stat-menunggu .stat-icon { background: #f39c12; }
.stat-batal { border-top-color: #dd4b39; }
.stat-batal .stat-icon { background: #dd4b39; }

.btn-call {
  width: 32px;
  height: 32px;
  border-radius: 6px;
  padding: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  position: relative;
}

.btn-call.called {
  background-color: #00a65a !important;
  border-color: #008d4c !important;
}

.btn-print {
  width: 32px;
  height: 32px;
  border-radius: 6px;
  padding: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  position: relative;
}

.call-counter {
  position: absolute;
  top: -6px;
  right: -6px;
  background: #f39c12;
  color: white;
  font-size: 9px;
  font-weight: 800;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 2px solid white;
}

.row-called {
  background-color: #f0fdf4 !important;
}

.badge-called {
  background-color: #00a65a;
  color: white;
  padding: 3px 8px;
  border-radius: 10px;
  font-size: 10px;
  font-weight: 700;
  margin-left: 6px;
}

@media (max-width: 992px) {
  .stats-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 576px) {
  .stats-grid {
    grid-template-columns: 1fr;
  }
}

/* Print Styles */
@media print {
  body * {
    visibility: hidden;
  }
  
  #printArea, #printArea * {
    visibility: visible;
  }
  
  #printArea {
    position: absolute;
    left: 0;
    top: 0;
    width: 80mm;
  }
}
';

$extra_js = '
function updateClock() {
    const now = new Date();
    const options = { weekday: "long", year: "numeric", month: "long", day: "numeric" };
    const tanggal = now.toLocaleDateString("id-ID", options);
    const waktu = now.toLocaleTimeString("id-ID");
    
    document.getElementById("tanggalSekarang").innerHTML = tanggal;
    document.getElementById("clockDisplay").innerHTML = waktu;
}

setInterval(updateClock, 1000);
updateClock();

// Load status panggilan dari localStorage saat page load
window.addEventListener("DOMContentLoaded", function() {
    const today = "'.$today.'";
    const calledPatients = JSON.parse(localStorage.getItem("calledPatients_" + today) || "{}");
    
    Object.keys(calledPatients).forEach(function(noRawat) {
        markAsCalled(noRawat, calledPatients[noRawat]);
    });
});

// Fungsi untuk menandai pasien yang sudah dipanggil
function markAsCalled(noRawat, count) {
    const button = document.querySelector(`[data-no-rawat="${noRawat}"]`);
    if (button) {
        button.classList.add("called");
        
        let counterEl = button.querySelector(".call-counter");
        if (count > 1) {
            if (!counterEl) {
                counterEl = document.createElement("span");
                counterEl.className = "call-counter";
                button.appendChild(counterEl);
            }
            counterEl.textContent = count;
        } else if (counterEl) {
            counterEl.remove();
        }
        
        const row = button.closest("tr");
        if (row) {
            row.classList.add("row-called");
        }
        
        const badge = document.getElementById("badge-" + noRawat);
        if (badge) {
            badge.style.display = "inline-flex";
            if (count > 1) {
                badge.innerHTML = `<i class="fa fa-check-circle"></i> Dipanggil ${count}x`;
            } else {
                badge.innerHTML = `<i class="fa fa-check-circle"></i> Dipanggil`;
            }
        }
    }
}

// Call Patient Function
function panggilPasien(noAntri, poli, noRawat, buttonElement) {
    const kdDokter = buttonElement.getAttribute("data-kd-dokter") || "";
    const nmDokter = buttonElement.getAttribute("data-nm-dokter") || "";
    const nmPasien = buttonElement.getAttribute("data-nm-pasien") || "";
    
    const [kodePoli, nomor] = noAntri.split("-");
    
    console.log("Mengirim data panggilan:", {
        no_antrian: noAntri,
        nm_poli: poli,
        nm_pasien: nmPasien,
        no_rawat: noRawat,
        kd_poli: kodePoli,
        kd_dokter: kdDokter,
        nm_dokter: nmDokter
    });
    
    // Kirim data panggilan ke server
    fetch("save_call.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/json"
        },
        body: JSON.stringify({
            no_antrian: noAntri,
            nm_poli: poli,
            nm_pasien: nmPasien,
            no_rawat: noRawat,
            kd_poli: kodePoli,
            kd_dokter: kdDokter,
            nm_dokter: nmDokter
        })
    })
    .then(response => response.json())
    .then(data => {
        console.log("Response dari server:", data);
        if (data.success) {
            console.log("✅ Data panggilan tersimpan:", data);
        } else {
            console.error("❌ Gagal simpan:", data.message);
        }
    })
    .catch(error => {
        console.error("❌ Error saving call data:", error);
    });
    
    // Play suara
    const synth = window.speechSynthesis;
    synth.cancel();

    const prefix = kodePoli.split("").join(" ");

    function angkaToSuara(num) {
        num = parseInt(num);
        const angka = ["","satu","dua","tiga","empat","lima","enam","tujuh","delapan","sembilan","sepuluh","sebelas"];

        if (num < 12) return angka[num];
        if (num < 20) return angka[num - 10] + " belas";
        if (num < 100) {
            const puluh = Math.floor(num / 10);
            const satuan = num % 10;
            return angka[puluh] + " puluh " + (satuan > 0 ? angka[satuan] : "");
        }
        return num.toString();
    }

    const nomorSuara = angkaToSuara(nomor);
    const kalimat = `Nomor antrian ${prefix} ${nomorSuara}, silakan menuju ${poli}.`;

    const bell = new Audio("sound/opening.mp3");
    bell.volume = 1.0;

    bell.play().then(() => {
        bell.addEventListener("ended", () => {
            speak(kalimat);
        });
    }).catch(() => {
        speak(kalimat);
    });

    function speak(text) {
        const utter = new SpeechSynthesisUtterance(text);
        utter.lang = "id-ID";
        utter.pitch = 1.0;
        utter.rate = 0.9;
        utter.volume = 1.0;

        const voices = speechSynthesis.getVoices();
        const voiceID = voices.find(v => v.lang.includes("id"));
        if (voiceID) utter.voice = voiceID;

        synth.speak(utter);
    }
    
    // Simpan status ke localStorage
    const today = "'.$today.'";
    let calledPatients = JSON.parse(localStorage.getItem("calledPatients_" + today) || "{}");
    
    if (calledPatients[noRawat]) {
        calledPatients[noRawat]++;
    } else {
        calledPatients[noRawat] = 1;
    }
    
    localStorage.setItem("calledPatients_" + today, JSON.stringify(calledPatients));
    
    markAsCalled(noRawat, calledPatients[noRawat]);
}

// Fungsi Cetak Karcis Farmasi
function cetakKarcisFarmasi(noRawat, nmPasien) {
    // Buka halaman cetak di tab baru
    const url = `cetak_karcis_farmasi.php?no_rawat=${encodeURIComponent(noRawat)}`;
    window.open(url, "_blank", "width=400,height=600");
}

// Bersihkan localStorage untuk hari sebelumnya
function cleanOldData() {
    const today = "'.$today.'";
    Object.keys(localStorage).forEach(key => {
        if (key.startsWith("calledPatients_") && !key.includes(today)) {
            localStorage.removeItem(key);
        }
    });
}
cleanOldData();
';

// Include header
include 'includes/header.php';

// Include sidebar
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
            <div class="stat-icon">
              <i class="fa fa-users"></i>
            </div>
            <div class="stat-info">
              <div class="stat-label">Total Pasien</div>
              <div class="stat-value"><?= $total_pasien ?></div>
            </div>
          </div>
        </div>

        <div class="stat-card stat-sudah">
          <div class="stat-card-content">
            <div class="stat-icon">
              <i class="fa fa-check-circle"></i>
            </div>
            <div class="stat-info">
              <div class="stat-label">Sudah Dilayani</div>
              <div class="stat-value"><?= $sudah_dilayani ?></div>
            </div>
          </div>
        </div>

        <div class="stat-card stat-menunggu">
          <div class="stat-card-content">
            <div class="stat-icon">
              <i class="fa fa-clock-o"></i>
            </div>
            <div class="stat-info">
              <div class="stat-label">Sedang Menunggu</div>
              <div class="stat-value"><?= $menunggu ?></div>
            </div>
          </div>
        </div>

        <div class="stat-card stat-batal">
          <div class="stat-card-content">
            <div class="stat-icon">
              <i class="fa fa-times-circle"></i>
            </div>
            <div class="stat-info">
              <div class="stat-label">Batal</div>
              <div class="stat-value"><?= $batal ?></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Filter & Table Section -->
      <div class="row">
        <div class="col-xs-12">
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

          <div class="box">
            <div class="box-header">
              <h3 class="box-title">Daftar Antrian (<?= count($data) ?> dari <?= $total ?>)</h3>
            </div>
            <div class="box-body">
              
              <?php if ($total > 0): ?>
              <div class="table-responsive">
                <table class="table table-bordered table-striped table-hover">
                  <thead style="background: #605ca8; color: white;">
                    <tr>
                      <th width="80">Panggil</th>
                      <th width="80">Cetak</th>
                      <th width="100">No Antrian</th>
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
                        $statusClass = match (strtolower($row['stts'])) {
                            'sudah' => 'success',
                            'menunggu', 'belum' => 'warning',
                            'batal' => 'danger',
                            default => 'default'
                        };
                        $rowId = 'row-' . md5($row['no_rawat']);
                        $btnId = 'btn-' . md5($row['no_rawat']);
                    ?>
                    <tr id="<?= $rowId ?>">
                      <td>
                        <button class="btn btn-danger btn-call" id="<?= $btnId ?>"
                            data-no-rawat="<?= htmlspecialchars($row['no_rawat']) ?>"
                            data-kd-dokter="<?= htmlspecialchars($row['kd_dokter']) ?>"
                            data-nm-dokter="<?= htmlspecialchars($row['nm_dokter']) ?>"
                            data-nm-pasien="<?= htmlspecialchars($row['nm_pasien']) ?>"
                            onclick="panggilPasien('<?= addslashes($row['kd_poli'].'-'.$row['no_reg']) ?>','<?= addslashes($row['nm_poli']) ?>', '<?= htmlspecialchars($row['no_rawat']) ?>', this)">
                          <i class="fa fa-phone"></i>
                        </button>
                      </td>
                      <td>
                        <button class="btn btn-info btn-print" 
                            onclick="cetakKarcisFarmasi('<?= htmlspecialchars($row['no_rawat']) ?>', '<?= addslashes($row['nm_pasien']) ?>')">
                          <i class="fa fa-print"></i>
                        </button>
                      </td>
                      <td>
                        <strong><?= htmlspecialchars($row['kd_poli'].'-'.$row['no_reg']) ?></strong>
                        <span class="badge-called" id="badge-<?= htmlspecialchars($row['no_rawat']) ?>" style="display:none;">
                          <i class="fa fa-check-circle"></i> Dipanggil
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
                  
                  <?php 
                  $start = max(1, $page - 2);
                  $end = min($total_pages, $page + 2);
                  for ($i=$start; $i<=$end; $i++): 
                  ?>
                  <li <?=($i==$page)?'class="active"':''?>>
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

<?php
// Include footer
include 'includes/footer.php';
?>