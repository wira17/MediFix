<?php
session_start();
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id'])) {
    die('Unauthorized');
}

$no_rawat = $_GET['no_rawat'] ?? '';

if (empty($no_rawat)) {
    die('No rawat tidak ditemukan');
}

// Ambil data resep
try {
    $stmt = $pdo_simrs->prepare("
        SELECT ro.no_resep, ro.tgl_peresepan, ro.jam_peresepan,
               r.no_rkm_medis, p.nm_pasien, pl.nm_poli,
               CASE 
                   WHEN EXISTS (SELECT 1 FROM resep_dokter_racikan rr WHERE rr.no_resep = ro.no_resep)
                   THEN 'Racikan'
                   ELSE 'Non Racikan'
               END AS jenis_resep
        FROM resep_obat ro
        LEFT JOIN reg_periksa r ON ro.no_rawat = r.no_rawat
        LEFT JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
        LEFT JOIN poliklinik pl ON r.kd_poli = pl.kd_poli
        WHERE ro.no_rawat = ?
        ORDER BY ro.tgl_peresepan DESC, ro.jam_peresepan DESC
        LIMIT 1
    ");
    $stmt->execute([$no_rawat]);
    $resep = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$resep) {
        die('Data resep tidak ditemukan untuk pasien ini');
    }
    
    // Generate nomor antrian farmasi dari no_resep
    $no_antrian_farmasi = 'F' . str_pad(substr($resep['no_resep'], -4), 4, '0', STR_PAD_LEFT);
    
} catch (PDOException $e) {
    die('Error: ' . $e->getMessage());
}

// Ambil info RS
try {
    $stmt_rs = $pdo_simrs->query("SELECT * FROM setting LIMIT 1");
    $rs = $stmt_rs->fetch(PDO::FETCH_ASSOC);
    $namaRS = $rs['nama_instansi'] ?? 'RS Permata Hati';
} catch (PDOException $e) {
    $namaRS = 'RS Permata Hati';
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Karcis Antrian Farmasi</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        @page {
            size: 80mm auto;
            margin: 0;
        }
        
        body {
            font-family: 'Courier New', monospace;
            width: 80mm;
            padding: 10mm;
            background: white;
        }
        
        .karcis {
            border: 2px dashed #333;
            padding: 15px;
            text-align: center;
        }
        
        .header {
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        
        .header h2 {
            font-size: 18px;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        .header p {
            font-size: 12px;
            margin: 3px 0;
        }
        
        .nomor-antrian {
            background: #000;
            color: white;
            padding: 20px;
            margin: 15px 0;
            border-radius: 8px;
        }
        
        .nomor-antrian .label {
            font-size: 14px;
            margin-bottom: 5px;
        }
        
        .nomor-antrian .nomor {
            font-size: 48px;
            font-weight: bold;
            letter-spacing: 3px;
        }
        
        .info-pasien {
            text-align: left;
            margin: 15px 0;
            border-top: 1px dashed #666;
            border-bottom: 1px dashed #666;
            padding: 10px 0;
        }
        
        .info-pasien .row {
            display: flex;
            margin: 5px 0;
            font-size: 11px;
        }
        
        .info-pasien .label {
            width: 80px;
            font-weight: bold;
        }
        
        .info-pasien .value {
            flex: 1;
        }
        
        .jenis-resep {
            display: inline-block;
            padding: 5px 15px;
            margin: 10px 0;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }
        
        .jenis-racikan {
            background: #dd4b39;
            color: white;
        }
        
        .jenis-non-racikan {
            background: #00a65a;
            color: white;
        }
        
        .footer {
            margin-top: 15px;
            font-size: 10px;
            border-top: 1px solid #333;
            padding-top: 10px;
        }
        
        .waktu-cetak {
            font-size: 9px;
            color: #666;
            margin-top: 10px;
        }
        
        @media print {
            body {
                padding: 5mm;
            }
            
            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body onload="window.print();">
    <div class="karcis">
        <div class="header">
            <h2><?= htmlspecialchars($namaRS) ?></h2>
            <p>ANTRIAN FARMASI</p>
        </div>
        
        <div class="nomor-antrian">
            <div class="label">NOMOR ANTRIAN</div>
            <div class="nomor"><?= htmlspecialchars($no_antrian_farmasi) ?></div>
        </div>
        
        <div class="info-pasien">
            <div class="row">
                <div class="label">No. RM</div>
                <div class="value">: <?= htmlspecialchars($resep['no_rkm_medis']) ?></div>
            </div>
            <div class="row">
                <div class="label">Nama</div>
                <div class="value">: <?= htmlspecialchars($resep['nm_pasien']) ?></div>
            </div>
            <div class="row">
                <div class="label">No. Resep</div>
                <div class="value">: <?= htmlspecialchars($resep['no_resep']) ?></div>
            </div>
            <div class="row">
                <div class="label">Dari Poli</div>
                <div class="value">: <?= htmlspecialchars($resep['nm_poli']) ?></div>
            </div>
        </div>
        
        <div class="jenis-resep <?= $resep['jenis_resep'] === 'Racikan' ? 'jenis-racikan' : 'jenis-non-racikan' ?>">
            <?= htmlspecialchars($resep['jenis_resep']) ?>
        </div>
        
        <div class="footer">
            <p>Mohon tunggu panggilan nomor Anda</p>
            <p>di layar display antrian farmasi</p>
            <?php if ($resep['jenis_resep'] === 'Racikan'): ?>
            <p style="margin-top: 5px; font-size: 9px; color: #dd4b39;">
                * Resep racikan membutuhkan waktu ± 15-60 menit
            </p>
            <?php endif; ?>
        </div>
        
        <div class="waktu-cetak">
            Dicetak: <?= date('d/m/Y H:i:s') ?>
        </div>
    </div>
</body>
</html>