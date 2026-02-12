<?php
session_start();
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

header('Content-Type: application/json');

function getLastCall() {
    $locations = [
        __DIR__ . '/data/last_farmasi.json',
        __DIR__ . '/last_farmasi.json',
        sys_get_temp_dir() . '/last_farmasi.json'
    ];
    
    foreach ($locations as $file) {
        if (file_exists($file)) {
            $content = file_get_contents($file);
            $data = json_decode($content, true);
            
            if (isset($data['waktu'])) {
                $callTime = strtotime($data['waktu']);
                $currentTime = time();
                $diff = $currentTime - $callTime;
                
                if ($diff > 7200) {
                    continue;
                }
            }
            
            return $data;
        }
    }
    
    return null;
}

try {
    $stmt = $pdo_simrs->prepare("
        SELECT 
            ro.no_resep, ro.tgl_peresepan, ro.jam_peresepan, ro.status as status_resep,
            r.no_rkm_medis, p.nm_pasien, d.nm_dokter, pl.kd_poli, pl.nm_poli,
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
        WHERE ro.tgl_peresepan = CURDATE()
          AND ro.status = 'ralan'
          AND ro.jam_peresepan <> '00:00:00'
        ORDER BY ro.tgl_peresepan DESC, ro.jam_peresepan DESC
    ");
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $lastCall = getLastCall();
    $sedangDilayani = null;
    
    if ($lastCall && isset($lastCall['no_resep'])) {
        $sedangDilayani = [
            'no_resep' => $lastCall['no_resep'],
            'nm_pasien' => $lastCall['nm_pasien'] ?? '-',
            'nm_poli' => $lastCall['nm_poli'] ?? '-',
            'jenis_resep' => $lastCall['jenis_resep'] ?? 'Non Racikan'
        ];
    }

    function sensorNama($nama) {
        $parts = explode(' ', $nama);
        $result = [];
        foreach ($parts as $p) {
            $len = mb_strlen($p);
            if ($len <= 2) {
                $result[] = str_repeat('*', $len);
            } else {
                $result[] = mb_substr($p, 0, 1) . str_repeat('*', $len - 2) . mb_substr($p, -1);
            }
        }
        return implode(' ', $result);
    }

    // Prepare response
    $response = [
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'non_racikan' => [
            'has_data' => false,
            'nomor' => '-',
            'nama' => '-',
            'poli' => '-'
        ],
        'racikan' => [
            'has_data' => false,
            'nomor' => '-',
            'nama' => '-',
            'poli' => '-'
        ]
    ];

    if ($sedangDilayani) {
        $nomor = 'F' . str_pad(substr($sedangDilayani['no_resep'], -4), 4, '0', STR_PAD_LEFT);
        $nama = sensorNama($sedangDilayani['nm_pasien'] ?? '-');
        $poli = $sedangDilayani['nm_poli'] ?? '-';
        
        if ($sedangDilayani['jenis_resep'] === 'Non Racikan') {
            $response['non_racikan'] = [
                'has_data' => true,
                'nomor' => $nomor,
                'nama' => $nama,
                'poli' => $poli
            ];
        } else {
            $response['racikan'] = [
                'has_data' => true,
                'nomor' => $nomor,
                'nama' => $nama,
                'poli' => $poli
            ];
        }
    }

    echo json_encode($response);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>