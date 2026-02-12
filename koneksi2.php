<?php
include 'koneksi.php'; // koneksi utama

try {
    // Ambil konfigurasi SIMRS
    $stmt = $pdo->query("SELECT * FROM setting_simrs LIMIT 1");
    $cfg  = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cfg) {
        die("<b style='color:red'>⚠ Konfigurasi SIMRS belum diatur. Silakan buka menu Setting SIMRS terlebih dahulu.</b>");
    }

    $host2 = $cfg['host'];
    $user2 = $cfg['username'];
    $pass2 = $cfg['password'];
    $db2   = $cfg['database_name'];

    // Buat koneksi SIMRS
    $pdo_simrs = new PDO(
        "mysql:host=$host2;dbname=$db2;charset=utf8",
        $user2,
        $pass2
    );

    $pdo_simrs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (PDOException $e) {
    die("<b style='color:red'>❌ Koneksi SIMRS gagal:</b> " . $e->getMessage());
}
?>
