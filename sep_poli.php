<?php
// DEBUG MODE
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();
include 'koneksi.php';      // koneksi anjungan (vclaim/setting)
include 'koneksi2.php';     // koneksi SIMRS ($pdo_simrs)
require_once __DIR__ . '/vendor/autoload.php';
use LZCompressor\LZString;

date_default_timezone_set('Asia/Jakarta');

// Fungsi enkripsi/dekripsi
function stringEncrypt($key, $data) {
    $method = 'AES-256-CBC';
    $iv = substr(hash('sha256', $key), 0, 16);
    $encrypted = openssl_encrypt($data, $method, hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
    return base64_encode($encrypted);
}

function stringDecrypt($key, $string) {
    $method = 'AES-256-CBC';
    $iv = substr(hash('sha256', $key), 0, 16);
    return openssl_decrypt(base64_decode($string), $method, hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
}

function decompress($string) {
    return LZString::decompressFromEncodedURIComponent($string);
}

// Tangkap input pencarian
$keyword = isset($_POST['keyword']) ? trim($_POST['keyword']) : '';
$results = [];

if ($keyword != '') {
    $today = date('Y-m-d');
    $sql = "SELECT r.no_rawat, r.no_rkm_medis, r.kd_poli, r.kd_dokter, r.tgl_registrasi,
                   p.nm_pasien, p.no_peserta, p.jk, p.tmp_lahir, p.tgl_lahir, p.pekerjaan, p.agama
            FROM reg_periksa r
            INNER JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
            WHERE r.tgl_registrasi = :today
            AND (r.no_rkm_medis LIKE :keyword OR p.nm_pasien LIKE :keyword OR p.no_peserta LIKE :keyword)
            ORDER BY r.jam_reg ASC";

    // PENTING: gunakan koneksi SIMRS ($pdo_simrs)
    $stmt = $pdo_simrs->prepare($sql);
    $stmt->execute([
        ':today' => $today,
        ':keyword' => "%$keyword%"
    ]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>SEP Poli</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body>
<div class="container mt-5">
    <h3>Form Pencarian Pasien untuk SEP</h3>
    <form method="post" class="mb-4">
        <div class="input-group">
            <input type="text" name="keyword" class="form-control" placeholder="Masukkan No. RM / Nama / No. BPJS" value="<?= htmlspecialchars($keyword) ?>">
            <button class="btn btn-primary" type="submit">Cari</button>
        </div>
    </form>

    <?php if ($keyword != ''): ?>
        <?php if (count($results) > 0): ?>
            <table class="table table-bordered table-striped">
                <thead class="table-light">
                    <tr>
                        <th>No. Rawat</th>
                        <th>No. RM</th>
                        <th>Nama Pasien</th>
                        <th>No. BPJS</th>
                        <th>JK</th>
                        <th>Tmp / Tgl Lahir</th>
                        <th>Pekerjaan</th>
                        <th>Agama</th>
                        <th>Poli</th>
                        <th>Dokter</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($results as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['no_rawat']) ?></td>
                        <td><?= htmlspecialchars($row['no_rkm_medis']) ?></td>
                        <td><?= htmlspecialchars($row['nm_pasien']) ?></td>
                        <td><?= htmlspecialchars($row['no_peserta']) ?></td>
                        <td><?= htmlspecialchars($row['jk']) ?></td>
                        <td><?= htmlspecialchars($row['tmp_lahir']) ?> / <?= date('d-m-Y', strtotime($row['tgl_lahir'])) ?></td>
                        <td><?= htmlspecialchars($row['pekerjaan']) ?></td>
                        <td><?= htmlspecialchars($row['agama']) ?></td>
                        <td><?= htmlspecialchars($row['kd_poli']) ?></td>
                        <td><?= htmlspecialchars($row['kd_dokter']) ?></td>
                        <td>
                            <a href="buat_sep.php?no_rawat=<?= urlencode($row['no_rawat']) ?>" class="btn btn-success btn-sm">
                                Buat SEP
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="alert alert-warning">Pasien tidak ditemukan untuk tanggal hari ini.</div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
