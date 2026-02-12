<?php
include 'koneksi.php'; 
include 'koneksi2.php'; 
date_default_timezone_set('Asia/Jakarta');

// Ambil parameter
$no_reg     = $_GET['no_reg']     ?? '';
$no_rawat   = $_GET['no_rawat']   ?? '';
$nm_pasien  = $_GET['nm_pasien']  ?? '';

// Ambil data detail poli dan dokter
$stmt = $pdo_simrs->prepare("
    SELECT 
        r.no_reg, r.no_rawat, r.tgl_registrasi, r.jam_reg,
        p.nm_pasien, p.no_rkm_medis,
        pl.nm_poli, d.nm_dokter
    FROM reg_periksa r
    JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
    JOIN poliklinik pl ON r.kd_poli = pl.kd_poli
    JOIN dokter d ON r.kd_dokter = d.kd_dokter
    WHERE r.no_rawat = ?
");
$stmt->execute([$no_rawat]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) {
    die("Data tidak ditemukan!");
}

// Ambil nama RS dari tabel setting
$setting = $pdo_simrs->query("SELECT nama_instansi, alamat_instansi FROM setting LIMIT 1")->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Cetak Antrian</title>

<style>
body {
    font-family: Arial, sans-serif;
    width: 280px; /* ukuran kertas thermal 80mm */
    margin: 0 auto;
    text-align: center;
}

.header {
    font-size: 16px;
    font-weight: bold;
    margin-bottom: 4px;
}

.small {
    font-size: 12px;
}

.line {
    border-top: 1px dashed #000;
    margin: 8px 0;
}

.qr {
    margin: 10px 0;
}

/* Nomor Antrian BESAR */
.antrian {
    font-size: 48px;
    font-weight: bold;
    margin: 10px 0;
}

.info {
    font-size: 14px;
    text-align: left;
    margin-top: 8px;
}

@media print {
    @page {
        size: auto;
        margin: 0;
    }
}
</style>
</head>
<body>

<div class="header"><?= strtoupper($setting['nama_instansi']) ?></div>
<div class="small"><?= $setting['alamat_instansi'] ?></div>

<div class="line"></div>

<div class="small">
    <?= date('d-m-Y') ?> / <?= date('H:i') ?>
</div>

<div class="line"></div>

<div class="info">
    <strong>Nama :</strong> <?= strtoupper($data['nm_pasien']) ?><br>
    <strong>No. RM :</strong> <?= $data['no_rkm_medis'] ?><br>
    <strong>Poli :</strong> <?= strtoupper($data['nm_poli']) ?><br>
    <strong>Dokter :</strong> <?= strtoupper($data['nm_dokter']) ?><br>
</div>

<div class="line"></div>

<div class="small">NOMOR ANTRIAN</div>
<div class="antrian"><?= $data['no_reg'] ?></div>

<div class="small">NO. RAWAT</div>
<div style="font-size:14px; font-weight:bold;"><?= $data['no_rawat'] ?></div>

<div class="line"></div>

<div class="small">Silakan menunggu panggilan</div>

<script>
    window.onload = function () {
        window.print();
        setTimeout(() => window.close(), 300);
    }
</script>

</body>
</html>
