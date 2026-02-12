<?php
// save_call.php - API endpoint untuk menyimpan data panggilan
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Log untuk debugging
$log_file = 'data/call_log.txt';

function writeLog($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Buat folder data jika belum ada
    $data_dir = 'data';
    if (!is_dir($data_dir)) {
        if (!mkdir($data_dir, 0777, true)) {
            writeLog('ERROR: Gagal membuat folder data/');
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Gagal membuat folder data']);
            exit;
        }
        chmod($data_dir, 0777);
    }
    
    // Ambil input
    $input = json_decode(file_get_contents('php://input'), true);
    
    writeLog('Menerima request: ' . json_encode($input));
    
    if ($input && isset($input['no_antrian'], $input['nm_poli'], $input['no_rawat'])) {
        
        // Simpan data dengan timestamp
        $call_data = [
            'no_antrian' => $input['no_antrian'],
            'nm_poli' => $input['nm_poli'],
            'nm_pasien' => $input['nm_pasien'] ?? 'Pasien',
            'no_rawat' => $input['no_rawat'],
            'kd_poli' => $input['kd_poli'] ?? '',
            'kd_dokter' => $input['kd_dokter'] ?? '',
            'nm_dokter' => $input['nm_dokter'] ?? '',
            'timestamp' => time(),
            'datetime' => date('Y-m-d H:i:s')
        ];
        
        $file_path = $data_dir . '/current_call.json';
        
        // Simpan ke file
        if (file_put_contents($file_path, json_encode($call_data, JSON_PRETTY_PRINT))) {
            chmod($file_path, 0666);
            writeLog('SUCCESS: Data tersimpan ke ' . $file_path);
            echo json_encode([
                'success' => true, 
                'message' => 'Data panggilan tersimpan',
                'data' => $call_data
            ]);
        } else {
            writeLog('ERROR: Gagal menulis file ' . $file_path);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Gagal menulis file']);
        }
        
    } else {
        writeLog('ERROR: Data tidak lengkap');
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => 'Data tidak lengkap',
            'received' => $input
        ]);
    }
    
} else if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    
    // Endpoint untuk cek data (debugging)
    $file_path = 'data/current_call.json';
    if (file_exists($file_path)) {
        $data = json_decode(file_get_contents($file_path), true);
        echo json_encode([
            'success' => true,
            'data' => $data,
            'file_exists' => true,
            'file_path' => $file_path,
            'timestamp' => $data['timestamp'] ?? null,
            'age_seconds' => isset($data['timestamp']) ? (time() - $data['timestamp']) : null
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'File tidak ditemukan',
            'file_exists' => false,
            'file_path' => $file_path
        ]);
    }
    
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>