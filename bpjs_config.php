<?php
/**
 * Konfigurasi Bridging BPJS V-Claim
 * File: bpjs_config.php
 */

// Konfigurasi BPJS
define('BPJS_CONSUMER_ID', 'YOUR_CONSUMER_ID');      // Ganti dengan Consumer ID Anda
define('BPJS_CONSUMER_SECRET', 'YOUR_CONSUMER_SECRET'); // Ganti dengan Consumer Secret Anda
define('BPJS_USER_KEY', 'YOUR_USER_KEY');            // Ganti dengan User Key Anda

// URL API BPJS
define('BPJS_BASE_URL_DEV', 'https://apijkndev.bpjs-kesehatan.go.id');
define('BPJS_BASE_URL_PROD', 'https://apijkn.bpjs-kesehatan.go.id');

// Pilih environment (dev/prod)
define('BPJS_ENV', 'dev'); // Ubah ke 'prod' untuk production

// Set base URL sesuai environment
define('BPJS_BASE_URL', BPJS_ENV === 'prod' ? BPJS_BASE_URL_PROD : BPJS_BASE_URL_DEV);

// Endpoint API
define('BPJS_ENDPOINT_SEP_INSERT', '/SEP/2.0/insert');
define('BPJS_ENDPOINT_SEP_UPDATE', '/SEP/2.0/update');
define('BPJS_ENDPOINT_SEP_DELETE', '/SEP/2.0/delete');
define('BPJS_ENDPOINT_SEP_GET', '/SEP/2.0/');

// Timezone
date_default_timezone_set('Asia/Jakarta');

/**
 * Class untuk handle bridging BPJS
 */
class BPJSBridge {
    
    private $consumerID;
    private $consumerSecret;
    private $userKey;
    private $baseUrl;
    
    public function __construct() {
        $this->consumerID = BPJS_CONSUMER_ID;
        $this->consumerSecret = BPJS_CONSUMER_SECRET;
        $this->userKey = BPJS_USER_KEY;
        $this->baseUrl = BPJS_BASE_URL;
    }
    
    /**
     * Generate Signature untuk Header
     */
    private function generateSignature() {
        $timestamp = strval(time());
        $data = $this->consumerID . "&" . $timestamp;
        
        $signature = hash_hmac('sha256', $data, $this->consumerSecret, true);
        $encodedSignature = base64_encode($signature);
        
        return [
            'timestamp' => $timestamp,
            'signature' => $encodedSignature
        ];
    }
    
    /**
     * Generate Headers untuk Request
     */
    private function generateHeaders() {
        $auth = $this->generateSignature();
        
        return [
            'X-cons-id: ' . $this->consumerID,
            'X-timestamp: ' . $auth['timestamp'],
            'X-signature: ' . $auth['signature'],
            'user_key: ' . $this->userKey,
            'Content-Type: application/json'
        ];
    }
    
    /**
     * Decompress Response dari BPJS (LZ String)
     */
    private function stringDecompress($string) {
        // Implementasi LZ String decompression
        // Anda bisa menggunakan library JavaScript di PHP atau
        // menggunakan cara alternatif seperti base64_decode
        
        // Untuk sementara return as is
        // Jika BPJS menggunakan compression, perlu library tambahan
        return $string;
    }
    
    /**
     * Send Request ke BPJS
     */
    public function sendRequest($endpoint, $method = 'GET', $data = null) {
        $url = $this->baseUrl . $endpoint;
        $headers = $this->generateHeaders();
        
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'message' => 'cURL Error: ' . $error,
                'code' => 0
            ];
        }
        
        $result = json_decode($response, true);
        
        // Log request dan response
        $this->logRequest($endpoint, $method, $data, $result, $httpCode);
        
        return [
            'success' => ($httpCode == 200 && isset($result['metaData']['code']) && $result['metaData']['code'] == 200),
            'data' => $result,
            'http_code' => $httpCode,
            'message' => isset($result['metaData']['message']) ? $result['metaData']['message'] : 'Unknown error'
        ];
    }
    
    /**
     * Insert SEP ke BPJS
     */
    public function insertSEP($dataSEP) {
        $endpoint = BPJS_ENDPOINT_SEP_INSERT;
        
        // Format data sesuai dengan format BPJS
        $request = [
            'request' => [
                't_sep' => $dataSEP
            ]
        ];
        
        return $this->sendRequest($endpoint, 'POST', $request);
    }
    
    /**
     * Update SEP di BPJS
     */
    public function updateSEP($dataSEP) {
        $endpoint = BPJS_ENDPOINT_SEP_UPDATE;
        
        $request = [
            'request' => [
                't_sep' => $dataSEP
            ]
        ];
        
        return $this->sendRequest($endpoint, 'PUT', $request);
    }
    
    /**
     * Delete SEP di BPJS
     */
    public function deleteSEP($noSep, $user) {
        $endpoint = BPJS_ENDPOINT_SEP_DELETE;
        
        $request = [
            'request' => [
                't_sep' => [
                    'noSep' => $noSep,
                    'user' => $user
                ]
            ]
        ];
        
        return $this->sendRequest($endpoint, 'DELETE', $request);
    }
    
    /**
     * Get SEP dari BPJS
     */
    public function getSEP($noSep) {
        $endpoint = BPJS_ENDPOINT_SEP_GET . $noSep;
        return $this->sendRequest($endpoint, 'GET');
    }
    
    /**
     * Log Request dan Response
     */
    private function logRequest($endpoint, $method, $request, $response, $httpCode) {
        $logFile = __DIR__ . '/logs/bpjs_' . date('Y-m-d') . '.log';
        
        // Buat folder logs jika belum ada
        if (!file_exists(__DIR__ . '/logs')) {
            mkdir(__DIR__ . '/logs', 0777, true);
        }
        
        $logData = [
            'datetime' => date('Y-m-d H:i:s'),
            'endpoint' => $endpoint,
            'method' => $method,
            'request' => $request,
            'response' => $response,
            'http_code' => $httpCode
        ];
        
        file_put_contents(
            $logFile, 
            json_encode($logData, JSON_PRETTY_PRINT) . "\n\n", 
            FILE_APPEND
        );
    }
}

// Fungsi helper untuk format tanggal
function formatTanggalBPJS($tanggal) {
    if (empty($tanggal) || $tanggal == '0000-00-00') {
        return null;
    }
    return date('Y-m-d', strtotime($tanggal));
}

// Fungsi helper untuk format datetime
function formatDateTimeBPJS($datetime) {
    if (empty($datetime) || $datetime == '0000-00-00 00:00:00') {
        return null;
    }
    return date('Y-m-d H:i:s', strtotime($datetime));
}