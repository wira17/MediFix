<?php
session_start();
include 'koneksi2.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Unauthorized');
}

$id = $_POST['id'] ?? '';
$rm = $_POST['rm'] ?? '';

if (empty($id) || empty($rm)) {
    http_response_code(400);
    exit('Invalid data');
}

try {
    $stmt = $pdo_simrs->prepare("UPDATE antrian_wira SET no_rkm_medis = ?, status = 'Selesai' WHERE id = ?");
    $stmt->execute([$rm, $id]);
    
    echo 'OK';
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database error: ' . $e->getMessage());
}