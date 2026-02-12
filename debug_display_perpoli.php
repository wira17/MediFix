<?php
session_start();
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

$today = date('Y-m-d');

echo "<h2>🔍 Debug Display Perpoli - " . date('d F Y') . "</h2>";
echo "<hr>";

// 1. TEST KONEKSI DATABASE
echo "<h3>1. Test Koneksi Database</h3>";
try {
    echo "✅ Koneksi database berhasil<br>";
    echo "Database: " . $pdo_simrs->getAttribute(PDO::ATTR_CONNECTION_STATUS) . "<br>";
} catch (PDOException $e) {
    echo "❌ Koneksi gagal: " . $e->getMessage() . "<br>";
}
echo "<hr>";

// 2. CEK DATA REG_PERIKSA HARI INI
echo "<h3>2. Data Registrasi Hari Ini (reg_periksa)</h3>";
try {
    $sql_check = "SELECT * FROM reg_periksa WHERE tgl_registrasi = :tgl LIMIT 10";
    $stmt_check = $pdo_simrs->prepare($sql_check);
    $stmt_check->bindValue(':tgl', $today);
    $stmt_check->execute();
    $data_reg = $stmt_check->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($data_reg) > 0) {
        echo "✅ Ditemukan " . count($data_reg) . " registrasi<br><br>";
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
        echo "<tr style='background: #f0f0f0;'>";
        echo "<th>No Reg</th><th>No RM</th><th>Kd Poli</th><th>Kd Dokter</th><th>Tgl Reg</th><th>Status</th>";
        echo "</tr>";
        foreach ($data_reg as $row) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['no_reg'] ?? '-') . "</td>";
            echo "<td>" . htmlspecialchars($row['no_rkm_medis'] ?? '-') . "</td>";
            echo "<td>" . htmlspecialchars($row['kd_poli'] ?? '-') . "</td>";
            echo "<td>" . htmlspecialchars($row['kd_dokter'] ?? '-') . "</td>";
            echo "<td>" . htmlspecialchars($row['tgl_registrasi'] ?? '-') . "</td>";
            echo "<td>" . htmlspecialchars($row['stts'] ?? '-') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "⚠️ Tidak ada registrasi hari ini<br>";
        echo "Tanggal yang dicek: " . $today . "<br>";
    }
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}
echo "<hr>";

// 3. CEK SEMUA KODE POLI YANG ADA
echo "<h3>3. Semua Kode Poli di Database</h3>";
try {
    $sql_poli = "SELECT kd_poli, nm_poli FROM poliklinik ORDER BY kd_poli";
    $stmt_poli = $pdo_simrs->query($sql_poli);
    $all_poli = $stmt_poli->fetchAll(PDO::FETCH_ASSOC);
    
    echo "✅ Ditemukan " . count($all_poli) . " poliklinik<br><br>";
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr style='background: #f0f0f0;'><th>Kode Poli</th><th>Nama Poli</th></tr>";
    foreach ($all_poli as $poli) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($poli['kd_poli']) . "</td>";
        echo "<td>" . htmlspecialchars($poli['nm_poli']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}
echo "<hr>";

// 4. CEK EXCLUDED POLI
echo "<h3>4. Poli yang Dikecualikan (Excluded)</h3>";
$excluded_poli = ['IGDK','PL013','PL014','PL015','PL016','PL017','U0022','U0030'];
echo "Poli yang dikecualikan: <br>";
echo "<ul>";
foreach ($excluded_poli as $ex) {
    echo "<li>" . $ex . "</li>";
}
echo "</ul>";
echo "<hr>";

// 5. QUERY UTAMA YANG DIPAKAI DI DISPLAY_PERPOLI
echo "<h3>5. Test Query Utama Display Perpoli</h3>";
try {
    $excluded_list = "'" . implode("','", $excluded_poli) . "'";
    
    $sql_main = "
        SELECT 
            p.kd_poli, 
            p.nm_poli,
            d.kd_dokter,
            d.nm_dokter,
            COUNT(r.no_reg) as total_pasien,
            SUM(CASE WHEN r.stts = 'Sudah' THEN 1 ELSE 0 END) as sudah_dilayani,
            SUM(CASE WHEN r.stts IN ('Menunggu','Belum') THEN 1 ELSE 0 END) as menunggu
        FROM reg_periksa r
        JOIN poliklinik p ON r.kd_poli = p.kd_poli
        JOIN dokter d ON r.kd_dokter = d.kd_dokter
        WHERE r.tgl_registrasi = :tgl
          AND r.kd_poli NOT IN ($excluded_list)
        GROUP BY p.kd_poli, p.nm_poli, d.kd_dokter, d.nm_dokter
        ORDER BY p.nm_poli ASC, d.nm_dokter ASC";
    
    $stmt_main = $pdo_simrs->prepare($sql_main);
    $stmt_main->bindValue(':tgl', $today);
    $stmt_main->execute();
    $hasil = $stmt_main->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<strong>Query berhasil dijalankan!</strong><br>";
    echo "Hasil: " . count($hasil) . " baris data<br><br>";
    
    if (count($hasil) > 0) {
        echo "✅ <strong style='color: green;'>DATA DITEMUKAN!</strong><br><br>";
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
        echo "<tr style='background: #f0f0f0;'>";
        echo "<th>Kd Poli</th><th>Nama Poli</th><th>Kd Dokter</th><th>Nama Dokter</th><th>Total</th><th>Selesai</th><th>Menunggu</th>";
        echo "</tr>";
        foreach ($hasil as $row) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['kd_poli']) . "</td>";
            echo "<td>" . htmlspecialchars($row['nm_poli']) . "</td>";
            echo "<td>" . htmlspecialchars($row['kd_dokter']) . "</td>";
            echo "<td>" . htmlspecialchars($row['nm_dokter']) . "</td>";
            echo "<td>" . htmlspecialchars($row['total_pasien']) . "</td>";
            echo "<td>" . htmlspecialchars($row['sudah_dilayani']) . "</td>";
            echo "<td>" . htmlspecialchars($row['menunggu']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "⚠️ <strong style='color: red;'>TIDAK ADA DATA YANG COCOK</strong><br><br>";
        echo "<strong>Kemungkinan penyebab:</strong><br>";
        echo "<ol>";
        echo "<li>Tidak ada registrasi pasien hari ini</li>";
        echo "<li>Semua kode poli masuk dalam excluded list</li>";
        echo "<li>JOIN dengan tabel poliklinik atau dokter gagal (kode tidak match)</li>";
        echo "<li>Format tanggal tidak sesuai</li>";
        echo "</ol>";
    }
} catch (PDOException $e) {
    echo "❌ <strong>Query Error:</strong> " . $e->getMessage() . "<br>";
}
echo "<hr>";

// 6. CEK REGISTRASI PER POLI (TANPA EXCLUDE)
echo "<h3>6. Registrasi Per Poli (Tanpa Filter Exclude)</h3>";
try {
    $sql_all = "
        SELECT 
            r.kd_poli,
            p.nm_poli,
            COUNT(*) as jumlah
        FROM reg_periksa r
        LEFT JOIN poliklinik p ON r.kd_poli = p.kd_poli
        WHERE r.tgl_registrasi = :tgl
        GROUP BY r.kd_poli, p.nm_poli
        ORDER BY jumlah DESC";
    
    $stmt_all = $pdo_simrs->prepare($sql_all);
    $stmt_all->bindValue(':tgl', $today);
    $stmt_all->execute();
    $all_reg = $stmt_all->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($all_reg) > 0) {
        echo "✅ Ditemukan " . count($all_reg) . " poli dengan registrasi<br><br>";
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
        echo "<tr style='background: #f0f0f0;'><th>Kode Poli</th><th>Nama Poli</th><th>Jumlah Pasien</th></tr>";
        foreach ($all_reg as $row) {
            $is_excluded = in_array($row['kd_poli'], $excluded_poli);
            $style = $is_excluded ? "background: #ffe0e0;" : "";
            echo "<tr style='$style'>";
            echo "<td>" . htmlspecialchars($row['kd_poli']) . "</td>";
            echo "<td>" . htmlspecialchars($row['nm_poli'] ?? 'NULL') . ($is_excluded ? ' <strong>(EXCLUDED)</strong>' : '') . "</td>";
            echo "<td>" . htmlspecialchars($row['jumlah']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "<br><small>* Baris merah = poli yang dikecualikan</small>";
    } else {
        echo "⚠️ Tidak ada registrasi sama sekali<br>";
    }
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}
echo "<hr>";

// 7. CEK STRUKTUR KOLOM TABEL
echo "<h3>7. Struktur Kolom Tabel reg_periksa</h3>";
try {
    $sql_cols = "DESCRIBE reg_periksa";
    $stmt_cols = $pdo_simrs->query($sql_cols);
    $columns = $stmt_cols->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr style='background: #f0f0f0;'><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>";
    foreach ($columns as $col) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($col['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Key']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}

echo "<hr>";
echo "<h3>💡 Kesimpulan & Solusi</h3>";
echo "<p>Setelah melihat hasil debug di atas, kemungkinan masalah:</p>";
echo "<ol>";
echo "<li><strong>Tidak ada data registrasi hari ini</strong> → Pastikan ada pasien yang registrasi</li>";
echo "<li><strong>Semua poli masuk excluded list</strong> → Kurangi daftar excluded poli</li>";
echo "<li><strong>Nama kolom tidak sesuai</strong> → Cek apakah kolom 'tgl_registrasi', 'stts', 'kd_poli', 'kd_dokter' ada</li>";
echo "<li><strong>Format tanggal berbeda</strong> → Cek format tanggal di database (DATE vs DATETIME)</li>";
echo "</ol>";

echo "<style>
    body { font-family: Arial; padding: 20px; }
    table { margin: 10px 0; }
    h2 { color: #333; }
    h3 { color: #666; margin-top: 20px; }
</style>";
?>