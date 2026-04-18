<?php
/**
 * config/env.php
 * Konfigurasi Satu Sehat — dibaca dari tabel setting_satusehat
 * TIDAK ADA hardcode kredensial di sini!
 *
 * Cara pakai: include setelah koneksi.php dan koneksi2.php
 *   require_once __DIR__ . '/../config/env.php';
 */

function loadSatuSehatConfig(PDO $pdo): void {
    static $loaded = false;
    if ($loaded) return;

    try {
        $row = $pdo->query("SELECT * FROM setting_satusehat LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $row = null; // tabel belum ada
    }

    if ($row) {
        define('SS_CLIENT_ID',     $row['client_id']);
        define('SS_CLIENT_SECRET', $row['client_secret']);
        define('SS_ORG_ID',        $row['org_id']);
        define('SS_BASE_URL',      rtrim($row['base_url'], '/'));
        define('ORTHANC_SECRET',   $row['orthanc_secret'] ?? '');
        define('MEDIFIX_BASE_URL', $row['medifix_url']    ?? '');
        define('SS_DEBUG',         (bool)($row['debug_mode'] ?? false));
    } else {
        // Fallback kosong — tampilkan peringatan
        define('SS_CLIENT_ID',     '');
        define('SS_CLIENT_SECRET', '');
        define('SS_ORG_ID',        '');
        define('SS_BASE_URL',      'https://api-satusehat.kemkes.go.id');
        define('ORTHANC_SECRET',   '');
        define('MEDIFIX_BASE_URL', '');
        define('SS_DEBUG',         false);
    }

    define('SS_FHIR_URL',       SS_BASE_URL . '/fhir-r4/v1');
    define('SS_OAUTH_URL',      SS_BASE_URL . '/oauth2/v1/accesstoken?grant_type=client_credentials');
    define('SS_CURL_TIMEOUT',   15);
    define('SS_TOKEN_CACHE_FILE', sys_get_temp_dir() . '/ss_token_cache.json');

    $loaded = true;
}

// Auto-load — $pdo = koneksi MediFix (yang punya tabel setting_satusehat)
if (isset($pdo)) {
    loadSatuSehatConfig($pdo);
} elseif (isset($pdo_simrs)) {
    loadSatuSehatConfig($pdo_simrs);
}