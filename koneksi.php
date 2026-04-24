<?php
/**
 * KONEKSI DATABASE ANJUNGAN & SETTING VCLAIM
 * File: koneksi.php
 * 
 * Database ini berisi:
 * - Tabel setting_vclaim (konfigurasi BPJS)
 * - Data anjungan/kiosk
 */

$host = 'localhost';
$dbname = 'anjungan'; // Sesuaikan dengan nama database Anda
$username = 'root';          // Sesuaikan dengan username database Anda
$password = '';              // Sesuaikan dengan password database Anda

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch (PDOException $e) {
    die("❌ Koneksi database anjungan gagal: " . $e->getMessage());
}
?>