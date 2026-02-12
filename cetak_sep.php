<?php
session_start();
include 'koneksi.php';  // gunakan koneksi utama karena setting_vclaim ada di sini
include 'koneksi2.php'; // koneksi SIMRS / database pasien
date_default_timezone_set('Asia/Jakarta');
?>

<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cetak SEP</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
<style>
    .form-group { margin-bottom: 0.5rem; font-size: 0.85rem; }
    .modal-body { max-height: 75vh; overflow-y: auto; }
    label i { margin-right: 5px; }
    label { font-weight: 500; margin-bottom: 0.25rem; }
</style>
</head>
<body class="bg-light">
<div class="container py-4">
    <h3><i class="bi bi-card-checklist"></i> Buat SEP</h3>

    <!-- Form Pencarian -->
    <form method="get" class="mb-4 mt-3">
        <div class="input-group">
            <input type="text" name="cari" class="form-control" placeholder="Masukkan No. Kartu / NIK / No. RM" value="<?= htmlspecialchars($_GET['cari'] ?? '') ?>" required>
            <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i> Cari</button>
        </div>
    </form>

    <?php
    if (isset($_GET['cari'])) {
        $keyword = $_GET['cari'];
        $stmt = $pdo_simrs->prepare("
            SELECT no_rkm_medis, nm_pasien, no_ktp, jk, tgl_lahir 
            FROM pasien 
            WHERE no_rkm_medis LIKE ? OR no_ktp LIKE ? OR no_peserta LIKE ?
            LIMIT 20
        ");
        $stmt->execute(["%$keyword%", "%$keyword%", "%$keyword%"]);
        $pasien = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$pasien) {
            echo "<div class='alert alert-warning'>Data tidak ditemukan.</div>";
        } else {
            echo "<table class='table table-bordered table-hover'>";
            echo "<thead class='table-dark'><tr>
                    <th>No. RM</th>
                    <th>Nama</th>
                    <th>No. KTP</th>
                    <th>JK</th>
                    <th>Tgl Lahir</th>
                    <th>Aksi</th>
                  </tr></thead><tbody>";
            foreach ($pasien as $p) {
                echo "<tr>
                    <td>{$p['no_rkm_medis']}</td>
                    <td>{$p['nm_pasien']}</td>
                    <td>{$p['no_ktp']}</td>
                    <td>{$p['jk']}</td>
                    <td>{$p['tgl_lahir']}</td>
                    <td>
                        <button type='button' class='btn btn-success btn-sm btn-cetak' 
                            data-bs-toggle='modal' data-bs-target='#modalCetak' 
                            data-norm='{$p['no_rkm_medis']}' 
                            data-nm='{$p['nm_pasien']}' 
                            data-jk='{$p['jk']}' 
                            data-tgllahir='{$p['tgl_lahir']}'>
                            <i class='bi bi-printer'></i> Buat SEP
                        </button>
                    </td>
                </tr>";
            }
            echo "</tbody></table>";
        }
    }
    ?>
</div>

<!-- Modal Cetak SEP Lengkap -->
<div class="modal fade" id="modalCetak" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <form method="post" action="proses_cetak_sep.php" target="_blank">
      <div class="modal-content">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title"><i class="bi bi-printer"></i> Buat SEP</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <!-- Data Identitas -->
            <div class="row">
                <!-- Kolom 1: Data SEP & Rujukan -->
                <div class="col-md-3">
                    <h6 class="text-primary mb-3"><i class="bi bi-file-earmark-text"></i> Data SEP & Rujukan</h6>
                    
                    <div class="form-group">
                        <label><i class="bi bi-upc-scan"></i> No. SEP *</label>
                        <input type="text" class="form-control form-control-sm" name="no_sep" required>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-calendar"></i> Tgl SEP *</label>
                        <input type="date" class="form-control form-control-sm" name="tglsep" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-calendar-plus"></i> Tgl Rujukan</label>
                        <input type="date" class="form-control form-control-sm" name="tglrujukan" value="<?= date('Y-m-d') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-upc-scan"></i> No. Rujukan</label>
                        <input type="text" class="form-control form-control-sm" name="no_rujukan">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-building"></i> Asal Rujukan</label>
                        <select class="form-select form-select-sm" name="asal_rujukan">
                            <option value="1. Faskes 1">1. Faskes 1</option>
                            <option value="2. Faskes 2(RS)">2. Faskes 2 (RS)</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-building"></i> PPK Rujukan (Kode)</label>
                        <input type="text" class="form-control form-control-sm" name="kdppkrujukan">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-building"></i> PPK Rujukan (Nama)</label>
                        <input type="text" class="form-control form-control-sm" name="nmppkrujukan">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-clipboard-check"></i> Catatan</label>
                        <input type="text" class="form-control form-control-sm" name="catatan" placeholder="-">
                    </div>
                </div>

                <!-- Kolom 2: Data Pasien & Pelayanan -->
                <div class="col-md-3">
                    <h6 class="text-primary mb-3"><i class="bi bi-person"></i> Data Pasien</h6>
                    
                    <div class="form-group">
                        <label><i class="bi bi-card-list"></i> No. Rawat *</label>
                        <input type="text" class="form-control form-control-sm" name="no_rawat" id="no_rawat" required>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-person"></i> No. RM</label>
                        <input type="text" class="form-control form-control-sm" name="nomr" id="nomr">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-person"></i> Nama Pasien *</label>
                        <input type="text" class="form-control form-control-sm" name="nama_pasien" id="nama_pasien" required>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-gender-ambiguous"></i> Jenis Kelamin *</label>
                        <select class="form-select form-select-sm" name="jkel" id="jkel" required>
                            <option value="L">Laki-laki</option>
                            <option value="P">Perempuan</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-calendar2"></i> Tgl Lahir *</label>
                        <input type="date" class="form-control form-control-sm" name="tanggal_lahir" id="tanggal_lahir" required>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-telephone"></i> No. Kartu BPJS</label>
                        <input type="text" class="form-control form-control-sm" name="no_kartu">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-card-heading"></i> Jenis Peserta</label>
                        <input type="text" class="form-control form-control-sm" name="peserta">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-telephone"></i> No. Telp Peserta</label>
                        <input type="text" class="form-control form-control-sm" name="notelep">
                    </div>
                </div>

                <!-- Kolom 3: Data Pelayanan & Poli -->
                <div class="col-md-3">
                    <h6 class="text-primary mb-3"><i class="bi bi-hospital"></i> Data Pelayanan</h6>
                    
                    <div class="form-group">
                        <label><i class="bi bi-building"></i> PPK Pelayanan (Kode)</label>
                        <input type="text" class="form-control form-control-sm" name="kdppkpelayanan" value="0081R007">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-building"></i> PPK Pelayanan (Nama)</label>
                        <input type="text" class="form-control form-control-sm" name="nmppkpelayanan" value="RS Permata Hati">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-arrow-right-square"></i> Jenis Pelayanan</label>
                        <select class="form-select form-select-sm" name="jnspelayanan">
                            <option value="1">1 - Rawat Inap</option>
                            <option value="2" selected>2 - Rawat Jalan</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-hospital"></i> Poli Tujuan (Kode)</label>
                        <input type="text" class="form-control form-control-sm" name="kdpolitujuan">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-hospital"></i> Poli Tujuan (Nama)</label>
                        <input type="text" class="form-control form-control-sm" name="nmpolitujuan">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-hospital"></i> Kelas Rawat</label>
                        <select class="form-select form-select-sm" name="klsrawat">
                            <option value="1">1 - Kelas 1</option>
                            <option value="2">2 - Kelas 2</option>
                            <option value="3" selected>3 - Kelas 3</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-hospital"></i> Kelas Naik</label>
                        <select class="form-select form-select-sm" name="klsnaik">
                            <option value="">Tidak Naik Kelas</option>
                            <?php for($i=1;$i<=8;$i++) echo "<option value='$i'>$i</option>"; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-person-badge"></i> PJ Naik Kelas</label>
                        <input type="text" class="form-control form-control-sm" name="pjnaikkelas">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-cash-stack"></i> Pembiayaan</label>
                        <select class="form-select form-select-sm" name="pembiayaan">
                            <option value="">-</option>
                            <option value="1">1 - JKN</option>
                            <option value="2">2 - Non JKN</option>
                            <option value="3">3 - Lainnya</option>
                        </select>
                    </div>
                </div>

                <!-- Kolom 4: Diagnosa & Dokter -->
                <div class="col-md-3">
                    <h6 class="text-primary mb-3"><i class="bi bi-file-medical"></i> Diagnosa & DPJP</h6>
                    
                    <div class="form-group">
                        <label><i class="bi bi-file-medical"></i> Diagnosa Awal (Kode)</label>
                        <input type="text" class="form-control form-control-sm" name="diagawal">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-file-medical"></i> Diagnosa Awal (Nama)</label>
                        <input type="text" class="form-control form-control-sm" name="nmdiagnosaawal">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-person-check"></i> DPJP (Kode)</label>
                        <input type="text" class="form-control form-control-sm" name="kddpjp">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-person-check-fill"></i> DPJP (Nama)</label>
                        <input type="text" class="form-control form-control-sm" name="nmdpdjp">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-person-badge"></i> DPJP Pelayanan (Kode)</label>
                        <input type="text" class="form-control form-control-sm" name="kddpjplayanan">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-person-badge-fill"></i> DPJP Pelayanan (Nama)</label>
                        <input type="text" class="form-control form-control-sm" name="nmdpjplayanan">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-check2-square"></i> Tujuan Kunjungan</label>
                        <select class="form-select form-select-sm" name="tujuankunjungan">
                            <option value="0" selected>0 - Normal</option>
                            <option value="1">1 - Prosedur</option>
                            <option value="2">2 - Konsul</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-clipboard"></i> Flag Prosedur</label>
                        <select class="form-select form-select-sm" name="flagprosedur">
                            <option value="">-</option>
                            <option value="0">0 - Tidak</option>
                            <option value="1">1 - Ya</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-clipboard2-data"></i> Penunjang</label>
                        <select class="form-select form-select-sm" name="penunjang">
                            <option value="">-</option>
                            <?php for($i=1;$i<=12;$i++) echo "<option value='$i'>$i</option>"; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-file-text"></i> Asesmen Pelayanan</label>
                        <select class="form-select form-select-sm" name="asesmenpelayanan">
                            <option value="">-</option>
                            <?php for($i=1;$i<=5;$i++) echo "<option value='$i'>$i</option>"; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Row 2: Data Tambahan -->
            <hr class="my-3">
            <div class="row">
                <!-- Kolom 1: Kecelakaan & COB -->
                <div class="col-md-3">
                    <h6 class="text-primary mb-3"><i class="bi bi-exclamation-triangle"></i> Data Kecelakaan</h6>
                    
                    <div class="form-group">
                        <label><i class="bi bi-activity"></i> Lakalantas</label>
                        <select class="form-select form-select-sm" name="lakalantas">
                            <option value="0" selected>0 - Bukan Kecelakaan</option>
                            <option value="1">1 - KLL dan Bukan Keg. Kepolisian</option>
                            <option value="2">2 - KLL dan Keg. Kepolisian</option>
                            <option value="3">3 - KLB</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-calendar-event"></i> Tanggal KKL</label>
                        <input type="date" class="form-control form-control-sm" name="tglkkl" value="0000-00-00">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-chat-text"></i> Keterangan KKL</label>
                        <input type="text" class="form-control form-control-sm" name="keterangankkl">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-file-earmark"></i> No. SKDP</label>
                        <input type="text" class="form-control form-control-sm" name="noskdp">
                    </div>
                </div>

                <!-- Kolom 2: COB & Status Lainnya -->
                <div class="col-md-3">
                    <h6 class="text-primary mb-3"><i class="bi bi-toggle-on"></i> Status Kepesertaan</h6>
                    
                    <div class="form-group">
                        <label><i class="bi bi-cash"></i> COB (Koordinasi Benefit)</label>
                        <select class="form-select form-select-sm" name="cob">
                            <option value="0. Tidak" selected>0 - Tidak</option>
                            <option value="1.Ya">1 - Ya</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-eye"></i> Katarak</label>
                        <select class="form-select form-select-sm" name="katarak">
                            <option value="0. Tidak" selected>0 - Tidak</option>
                            <option value="1.Ya">1 - Ya</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-star"></i> Eksekutif</label>
                        <select class="form-select form-select-sm" name="eksekutif">
                            <option value="0. Tidak" selected>0 - Tidak</option>
                            <option value="1.Ya">1 - Ya</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-plus-circle"></i> Suplesi</label>
                        <select class="form-select form-select-sm" name="suplesi">
                            <option value="0. Tidak" selected>0 - Tidak</option>
                            <option value="1.Ya">1 - Ya</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-file-earmark-plus"></i> No. SEP Suplesi</label>
                        <input type="text" class="form-control form-control-sm" name="no_sep_suplesi">
                    </div>
                </div>

                <!-- Kolom 3: Wilayah -->
                <div class="col-md-3">
                    <h6 class="text-primary mb-3"><i class="bi bi-geo-alt"></i> Data Wilayah</h6>
                    
                    <div class="form-group">
                        <label><i class="bi bi-geo-alt"></i> Kode Propinsi</label>
                        <input type="text" class="form-control form-control-sm" name="kdprop">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-geo-alt"></i> Nama Propinsi</label>
                        <input type="text" class="form-control form-control-sm" name="nmprop">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-geo-alt-fill"></i> Kode Kabupaten</label>
                        <input type="text" class="form-control form-control-sm" name="kdkab">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-geo-alt-fill"></i> Nama Kabupaten</label>
                        <input type="text" class="form-control form-control-sm" name="nmkab">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-map"></i> Kode Kecamatan</label>
                        <input type="text" class="form-control form-control-sm" name="kdkec">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-map"></i> Nama Kecamatan</label>
                        <input type="text" class="form-control form-control-sm" name="nmkec">
                    </div>
                </div>

                <!-- Kolom 4: Data Sistem -->
                <div class="col-md-3">
                    <h6 class="text-primary mb-3"><i class="bi bi-gear"></i> Data Sistem</h6>
                    
                    <div class="form-group">
                        <label><i class="bi bi-person"></i> User Input</label>
                        <input type="text" class="form-control form-control-sm" name="user" value="<?= $_SESSION['username'] ?? 'admin' ?>" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-calendar-x"></i> Tgl Pulang</label>
                        <input type="datetime-local" class="form-control form-control-sm" name="tglpulang">
                    </div>
                </div>
            </div>
        </div>

        <div class="modal-footer">
          <button type="submit" class="btn btn-success"><i class="bi bi-printer"></i> Cetak SEP</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const modalCetak = document.getElementById('modalCetak');
modalCetak.addEventListener('show.bs.modal', e => {
    const btn = e.relatedTarget;
    document.querySelector('[name="nomr"]').value = btn.dataset.norm;
    document.querySelector('[name="nama_pasien"]').value = btn.dataset.nm;
    document.querySelector('[name="jkel"]').value = btn.dataset.jk;
    document.querySelector('[name="tanggal_lahir"]').value = btn.dataset.tgllahir;
});
</script>
</body>
</html>