<?php
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

$today = date('Y-m-d');

// Ambil data terakhir yang dipanggil hari ini
$stmt = $pdo_simrs->prepare("
    SELECT a.*, l.nama_loket 
    FROM antrian_wira a
    LEFT JOIN loket_admisi_wira l ON a.loket_id = l.id
    WHERE DATE(a.created_at) = ? AND a.status = 'Dipanggil'
    ORDER BY a.waktu_panggil DESC LIMIT 1
");
$stmt->execute([$today]);
$current = $stmt->fetch(PDO::FETCH_ASSOC);

// Ambil statistik
$stmt2 = $pdo_simrs->prepare("SELECT COUNT(*) FROM antrian_wira WHERE DATE(created_at) = ?");
$stmt2->execute([$today]);
$total = $stmt2->fetchColumn();

$stmt3 = $pdo_simrs->prepare("SELECT COUNT(*) FROM antrian_wira WHERE DATE(created_at) = ? AND status='Menunggu'");
$stmt3->execute([$today]);
$menunggu = $stmt3->fetchColumn();

// Return dalam format JSON agar mudah diproses JS
header('Content-Type: application/json');
echo json_encode([
    'nomor' => $current['nomor'] ?? '',
    'loket' => $current['nama_loket'] ?? '',
    'total' => $total,
    'menunggu' => $menunggu
]);
?>
