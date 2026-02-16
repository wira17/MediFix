<?php
session_start();
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$nama = $_SESSION['nama'] ?? 'Pengguna';

// === Inisialisasi session panggilan ===
if (!isset($_SESSION['farmasi_called'])) $_SESSION['farmasi_called'] = [];

// === Filter ===
$filter_tanggal = $_GET['tanggal'] ?? date('Y-m-d');
$cari = $_GET['cari'] ?? '';

// === PAGINATION ===
$limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 20;
$page  = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

// === AMBIL DATA RESEP ===
try {
    $sql = "
        SELECT 
            ro.no_resep, ro.tgl_peresepan, ro.jam_peresepan, ro.status as status_resep,
            r.no_rkm_medis, p.nm_pasien, d.nm_dokter, pl.nm_poli, pl.kd_poli,
            r.status_lanjut,
            CASE 
                WHEN EXISTS (SELECT 1 FROM resep_dokter_racikan rr WHERE rr.no_resep = ro.no_resep)
                THEN 'Racikan'
                ELSE 'Non Racikan'
            END AS jenis_resep
        FROM resep_obat ro
        INNER JOIN reg_periksa r ON ro.no_rawat = r.no_rawat
        INNER JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
        INNER JOIN dokter d ON ro.kd_dokter = d.kd_dokter
        LEFT JOIN poliklinik pl ON r.kd_poli = pl.kd_poli
        WHERE ro.tgl_peresepan = ?
          AND ro.status = 'ralan'
    ";

    $params = [$filter_tanggal];

    if (!empty($cari)) {
        $sql .= " AND p.nm_pasien LIKE ?";
        $params[] = "%$cari%";
    }

    $sql .= " ORDER BY ro.tgl_peresepan DESC, ro.jam_peresepan DESC LIMIT " . intval($limit) . " OFFSET " . intval($offset);

    $stmt = $pdo_simrs->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // === HITUNG TOTAL ===
    $countSql = "
        SELECT COUNT(*) FROM resep_obat ro
        INNER JOIN reg_periksa r ON ro.no_rawat = r.no_rawat
        INNER JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
        INNER JOIN dokter d ON ro.kd_dokter = d.kd_dokter
        LEFT JOIN poliklinik pl ON r.kd_poli = pl.kd_poli
        WHERE ro.tgl_peresepan = ?
          AND ro.status = 'ralan'
    ";
    $paramsCount = [$filter_tanggal];

    if (!empty($cari)) {
        $countSql .= " AND p.nm_pasien LIKE ?";
        $paramsCount[] = "%$cari%";
    }

    $countStmt = $pdo_simrs->prepare($countSql);
    $countStmt->execute($paramsCount);
    $total = (int)$countStmt->fetchColumn();
    $total_pages = max(1, ceil($total / $limit));
    
    // === STATISTIK ===
    $statsSql = "
        SELECT 
            COUNT(*) as total_resep,
            SUM(CASE 
                WHEN EXISTS (SELECT 1 FROM resep_dokter_racikan rr WHERE rr.no_resep = ro.no_resep)
                THEN 1 ELSE 0
            END) AS racikan,
            SUM(CASE 
                WHEN NOT EXISTS (SELECT 1 FROM resep_dokter_racikan rr WHERE rr.no_resep = ro.no_resep)
                THEN 1 ELSE 0
            END) AS non_racikan
        FROM resep_obat ro
        INNER JOIN reg_periksa r ON ro.no_rawat = r.no_rawat
        INNER JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
        WHERE ro.tgl_peresepan = ?
          AND ro.status = 'ralan'
    ";
    
    $statsParams = [$filter_tanggal];
    if (!empty($cari)) {
        $statsSql .= " AND p.nm_pasien LIKE ?";
        $statsParams[] = "%$cari%";
    }
    
    $statsStmt = $pdo_simrs->prepare($statsSql);
    $statsStmt->execute($statsParams);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    $total_resep = $stats['total_resep'] ?? 0;
    $racikan = $stats['racikan'] ?? 0;
    $non_racikan = $stats['non_racikan'] ?? 0;
    
} catch (PDOException $e) {
    die("Gagal mengambil data: " . $e->getMessage());
}

// === AJAX Handler Pemanggilan ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'panggil') {
    $no_resep = $_POST['no_resep'] ?? '';

    if (!$no_resep) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Nomor resep kosong']);
        exit;
    }

    if (!in_array($no_resep, $_SESSION['farmasi_called'])) {
        $_SESSION['farmasi_called'][] = $no_resep;
    }

    $stmt = $pdo_simrs->prepare("
        SELECT ro.no_resep, p.nm_pasien, pl.nm_poli,
               CASE 
                   WHEN EXISTS (SELECT 1 FROM resep_dokter_racikan rr WHERE rr.no_resep = ro.no_resep)
                   THEN 'Racikan'
                   ELSE 'Non Racikan'
               END AS jenis_resep
        FROM resep_obat ro
        LEFT JOIN reg_periksa r ON ro.no_rawat = r.no_rawat
        LEFT JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
        LEFT JOIN poliklinik pl ON r.kd_poli = pl.kd_poli
        WHERE ro.no_resep = ?
    ");
    $stmt->execute([$no_resep]);
    $data_resep = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($data_resep) {
        $dataDir = __DIR__ . '/data';
        if (!file_exists($dataDir)) {
            @mkdir($dataDir, 0777, true);
        }
        
        $file = $dataDir . '/last_farmasi.json';
        if (!is_writable($dataDir)) {
            $file = __DIR__ . '/last_farmasi.json';
        }
        
        $jsonData = [
            'no_resep' => $data_resep['no_resep'],
            'nm_pasien' => $data_resep['nm_pasien'],
            'nm_poli' => $data_resep['nm_poli'] ?? '-',
            'jenis_resep' => $data_resep['jenis_resep'],
            'waktu' => date('Y-m-d H:i:s')
        ];
        
        @file_put_contents($file, json_encode($jsonData, JSON_PRETTY_PRINT));
    }

    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'data' => $data_resep]);
    exit;
}

// Set page title dan extra CSS
$page_title = 'Data Antrian Farmasi - MediFix';
$extra_css = '
/* Stats Cards */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
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

.stat-total { border-top-color: #f39c12; }
.stat-total .stat-icon { background: #f39c12; }
.stat-racikan { border-top-color: #dd4b39; }
.stat-racikan .stat-icon { background: #dd4b39; }
.stat-nonracikan { border-top-color: #00a65a; }
.stat-nonracikan .stat-icon { background: #00a65a; }

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
  background-color: #fff3e0 !important;
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
';

$extra_js = '
function angkaKeKata(n) {
  const satuan = ["", "satu", "dua", "tiga", "empat", "lima", "enam", "tujuh", "delapan", "sembilan"];
  const belasan = ["sepuluh", "sebelas", "dua belas", "tiga belas", "empat belas", "lima belas", 
                   "enam belas", "tujuh belas", "delapan belas", "sembilan belas"];
  
  if (n === 0) return "nol";
  if (n < 10) return satuan[n];
  if (n >= 10 && n < 20) return belasan[n - 10];
  if (n >= 20 && n < 100) {
    const puluhan = Math.floor(n / 10);
    const sisa = n % 10;
    return satuan[puluhan] + " puluh" + (sisa > 0 ? " " + satuan[sisa] : "");
  }
  if (n >= 100 && n < 200) {
    const sisa = n - 100;
    return "seratus" + (sisa > 0 ? " " + angkaKeKata(sisa) : "");
  }
  if (n >= 200 && n < 1000) {
    const ratusan = Math.floor(n / 100);
    const sisa = n % 100;
    return satuan[ratusan] + " ratus" + (sisa > 0 ? " " + angkaKeKata(sisa) : "");
  }
  if (n >= 1000 && n < 2000) {
    const sisa = n - 1000;
    return "seribu" + (sisa > 0 ? " " + angkaKeKata(sisa) : "");
  }
  if (n >= 2000 && n < 10000) {
    const ribuan = Math.floor(n / 1000);
    const sisa = n % 1000;
    return satuan[ribuan] + " ribu" + (sisa > 0 ? " " + angkaKeKata(sisa) : "");
  }
  return n.toString();
}

window.addEventListener("DOMContentLoaded", function() {
    const today = "'.$filter_tanggal.'";
    const calledPatients = JSON.parse(localStorage.getItem("calledFarmasi_" + today) || "{}");
    
    Object.keys(calledPatients).forEach(function(noResep) {
        markAsCalled(noResep, calledPatients[noResep]);
    });
});

function markAsCalled(noResep, count) {
    const button = document.querySelector(`[data-no-resep="${noResep}"]`);
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
        
        const badge = document.getElementById("badge-" + noResep);
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

function panggil(no_resep, nm_pasien, buttonElement) {
    buttonElement.disabled = true;
    const originalHTML = buttonElement.innerHTML;
    buttonElement.innerHTML = "<i class=\"fa fa-spinner fa-spin\"></i>";
    
    fetch(window.location.href, {
        method: "POST",
        headers: {"Content-Type":"application/x-www-form-urlencoded"},
        body: `action=panggil&no_resep=${encodeURIComponent(no_resep)}`
    })
    .then(r => r.json())
    .then(resp => {
        if (resp.status !== "ok") {
            alert("Gagal memanggil: " + resp.message);
            buttonElement.disabled = false;
            buttonElement.innerHTML = originalHTML;
            return;
        }

        const data = resp.data;
        const raw = data.no_resep.slice(-4);
        const angka = parseInt(raw, 10);
        const nomorKata = angkaKeKata(angka);
        const namaPasien = data.nm_pasien
            .toLowerCase()
            .split(" ")
            .map(kata => kata.charAt(0).toUpperCase() + kata.slice(1))
            .join(" ");

        const teks = `Nomor antrian farmasi, F ${nomorKata}. Atas nama, ${namaPasien}. Silakan menuju loket farmasi.`;

        function playSoundWithRetry(callback, retries = 3) {
            const bell = new Audio("sound/opening.mp3");
            bell.volume = 1;
            
            bell.play().then(() => {
                bell.addEventListener("ended", callback);
            }).catch(err => {
                if (retries > 0) {
                    setTimeout(() => playSoundWithRetry(callback, retries - 1), 500);
                } else {
                    callback();
                }
            });
        }

        playSoundWithRetry(() => {
            const utterance = new SpeechSynthesisUtterance(teks);
            utterance.lang = "id-ID";
            utterance.rate = 0.85;
            utterance.pitch = 1.1;
            utterance.volume = 1;
            
            const voices = window.speechSynthesis.getVoices();
            const indonesianVoice = voices.find(v => 
                v.lang === "id-ID" || 
                v.lang === "id_ID" || 
                v.name.includes("Indonesia")
            );
            
            if (indonesianVoice) {
                utterance.voice = indonesianVoice;
            }
            
            utterance.onend = () => {
                setTimeout(() => location.reload(), 1000);
            };
            
            utterance.onerror = () => {
                setTimeout(() => location.reload(), 1000);
            };
            
            window.speechSynthesis.cancel();
            window.speechSynthesis.speak(utterance);
        });
        
        const today = "'.$filter_tanggal.'";
        let calledPatients = JSON.parse(localStorage.getItem("calledFarmasi_" + today) || "{}");
        
        if (calledPatients[no_resep]) {
            calledPatients[no_resep]++;
        } else {
            calledPatients[no_resep] = 1;
        }
        
        localStorage.setItem("calledFarmasi_" + today, JSON.stringify(calledPatients));
        markAsCalled(no_resep, calledPatients[no_resep]);
    })
    .catch(err => {
        alert("Koneksi gagal. Silakan coba lagi.");
        buttonElement.disabled = false;
        buttonElement.innerHTML = originalHTML;
    });
}

if ("speechSynthesis" in window) {
    speechSynthesis.onvoiceschanged = () => {
        speechSynthesis.getVoices();
    };
    speechSynthesis.getVoices();
}

function cleanOldData() {
    const today = "'.$filter_tanggal.'";
    Object.keys(localStorage).forEach(key => {
        if (key.startsWith("calledFarmasi_") && !key.includes(today)) {
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
      <h1>Data Antrian Farmasi</h1>
      <ol class="breadcrumb">
        <li><a href="dashboard.php"><i class="fa fa-dashboard"></i> Home</a></li>
        <li><a href="#">Farmasi</a></li>
        <li class="active">Data Antrian</li>
      </ol>
    </section>

    <section class="content">

      <!-- Stats Cards -->
      <div class="stats-grid">
        <div class="stat-card stat-total">
          <div class="stat-card-content">
            <div class="stat-icon">
              <i class="fa fa-list-ol"></i>
            </div>
            <div class="stat-info">
              <div class="stat-label">Total Resep</div>
              <div class="stat-value"><?= $total_resep ?></div>
            </div>
          </div>
        </div>

        <div class="stat-card stat-racikan">
          <div class="stat-card-content">
            <div class="stat-icon">
              <i class="fa fa-flask"></i>
            </div>
            <div class="stat-info">
              <div class="stat-label">Racikan</div>
              <div class="stat-value"><?= $racikan ?></div>
            </div>
          </div>
        </div>

        <div class="stat-card stat-nonracikan">
          <div class="stat-card-content">
            <div class="stat-icon">
              <i class="fa fa-plus-square"></i>
            </div>
            <div class="stat-info">
              <div class="stat-label">Non Racikan</div>
              <div class="stat-value"><?= $non_racikan ?></div>
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
                  <label>Tanggal:</label>
                  <input type="date" name="tanggal" class="form-control" 
                         value="<?= htmlspecialchars($filter_tanggal) ?>">
                </div>
                
                <div class="form-group">
                  <label>Cari Nama Pasien:</label>
                  <input type="text" name="cari" class="form-control" 
                         placeholder="Ketik nama pasien..." 
                         value="<?= htmlspecialchars($cari) ?>">
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
                
                <a href="data_antri_farmasi.php" class="btn btn-default">
                  <i class="fa fa-refresh"></i> Reset
                </a>
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
                  <thead style="background: #f39c12; color: white;">
                    <tr>
                      <th width="50">No</th>
                      <th width="80">Panggil</th>
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
                        $no_antrian = 'F' . str_pad(substr($r['no_resep'], -4), 4, '0', STR_PAD_LEFT);
                        $called = in_array($r['no_resep'], $_SESSION['farmasi_called']);
                        $rowId = 'row-' . md5($r['no_resep']);
                        $btnId = 'btn-' . md5($r['no_resep']);
                    ?>
                    <tr id="<?= $rowId ?>" class="<?= $called ? 'row-called' : ''; ?>">
                      <td><?= $no++; ?></td>
                      <td>
                        <button class="btn btn-warning btn-call <?= $called ? 'called' : ''; ?>" 
                                id="<?= $btnId ?>"
                                data-no-resep="<?= htmlspecialchars($r['no_resep']) ?>"
                                data-nm-pasien="<?= htmlspecialchars($r['nm_pasien']) ?>"
                                onclick="panggil('<?= addslashes($r['no_resep']) ?>', '<?= addslashes($r['nm_pasien']) ?>', this)">
                          <i class="fa fa-phone"></i>
                        </button>
                      </td>
                      <td>
                        <strong><?= htmlspecialchars($no_antrian) ?></strong>
                        <span class="badge-called" id="badge-<?= htmlspecialchars($r['no_resep']) ?>" style="display:<?= $called ? 'inline-flex' : 'none' ?>;">
                          <i class="fa fa-check-circle"></i> Dipanggil
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
                  
                  <?php 
                  $start = max(1, $page - 2);
                  $end = min($total_pages, $page + 2);
                  for ($i=$start; $i<=$end; $i++): 
                  ?>
                  <li <?=($i==$page)?'class="active"':''?>>
                    <a href="?page=<?=$i?>&tanggal=<?=urlencode($filter_tanggal)?>&cari=<?=urlencode($cari)?>&limit=<?=$limit?>"><?=$i?></a>
                  </li>
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

<?php
// Include footer
include 'includes/footer.php';
?>