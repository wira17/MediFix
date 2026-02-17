<?php
session_start();
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');

// PENTING: Disable cache untuk mencegah browser mengcache response
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate, max-age=0');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Pragma: no-cache');

function getLastCall() {
    $locations = [
        __DIR__ . '/data/last_farmasi.json',
        __DIR__ . '/last_farmasi.json',
        sys_get_temp_dir() . '/last_farmasi.json',
        '/tmp/last_farmasi.json'
    ];
    
    $foundFiles = [];
    $latestData = null;
    $latestTime = 0;
    
    foreach ($locations as $file) {
        if (file_exists($file) && is_readable($file)) {
            $content = @file_get_contents($file);
            
            if ($content === false) {
                continue;
            }
            
            $data = json_decode($content, true);
            
            if (!$data || !isset($data['waktu'])) {
                continue;
            }
            
            $callTime = strtotime($data['waktu']);
            $currentTime = time();
            $diff = $currentTime - $callTime;
            
            // Data valid jika kurang dari 2 jam (7200 detik)
            if ($diff > 7200) {
                continue;
            }
            
            $foundFiles[] = [
                'file' => $file,
                'waktu' => $data['waktu'],
                'age_seconds' => $diff
            ];
            
            // Ambil data terbaru berdasarkan waktu
            if ($callTime > $latestTime) {
                $latestTime = $callTime;
                $latestData = $data;
                $latestData['_source_file'] = $file;
                $latestData['_age_seconds'] = $diff;
            }
        }
    }
    
    // Debug mode
    if (isset($_GET['debug'])) {
        return [
            'debug_mode' => true,
            'found_files' => $foundFiles,
            'latest_data' => $latestData,
            'current_time' => date('Y-m-d H:i:s'),
            'checked_locations' => $locations,
            'total_found' => count($foundFiles)
        ];
    }
    
    return $latestData;
}

try {
    // Check for debug mode
    if (isset($_GET['debug'])) {
        $debugData = getLastCall();
        echo json_encode($debugData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $lastCall = getLastCall();
    $sedangDilayani = null;
    
    if ($lastCall && isset($lastCall['no_resep'])) {
        $sedangDilayani = [
            'no_resep' => $lastCall['no_resep'],
            'nm_pasien' => $lastCall['nm_pasien'] ?? '-',
            'nm_poli' => $lastCall['nm_poli'] ?? 'Instalasi Farmasi',
            'jenis_resep' => $lastCall['jenis_resep'] ?? 'Non Racikan',
            'waktu' => $lastCall['waktu'] ?? date('Y-m-d H:i:s'),
            '_age_seconds' => $lastCall['_age_seconds'] ?? 0
        ];
    }
    
    function sensorNama($nama) {
        if (empty($nama) || $nama === '-') {
            return $nama;
        }
        
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
        'has_data' => $sedangDilayani !== null,
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
        $nama = sensorNama($sedangDilayani['nm_pasien']);
        $poli = $sedangDilayani['nm_poli'] ?? 'Instalasi Farmasi';
        $waktu = $sedangDilayani['waktu'] ?? '-';
        $age = $sedangDilayani['_age_seconds'] ?? 0;
        
        $queueData = [
            'has_data' => true,
            'nomor' => $nomor,
            'nama' => $nama,
            'poli' => $poli,
            'waktu_panggil' => $waktu,
            'usia_data' => $age . ' detik'
        ];
        
        if ($sedangDilayani['jenis_resep'] === 'Non Racikan') {
            $response['non_racikan'] = $queueData;
        } else {
            $response['racikan'] = $queueData;
        }
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
}
?>