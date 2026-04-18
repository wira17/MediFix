<?php
/**
 * config/env.php
 * Konfigurasi environment Satu Sehat
 * Sesuaikan nilai-nilai di bawah ini dengan kredensial faskes Anda
 */

// ── Satu Sehat Credentials ──────────────────────────────────────
define('SS_CLIENT_ID',     getenv('SATUSEHAT_CLIENT_ID')     ?: 'ISI_CLIENT_ID_ANDA');
define('SS_CLIENT_SECRET', getenv('SATUSEHAT_CLIENT_SECRET') ?: 'ISI_CLIENT_SECRET_ANDA');
define('SS_ORG_ID',        getenv('SATUSEHAT_ORG_ID')        ?: 'ISI_ORG_ID_ANDA');

// ── Endpoint (ganti ke production jika sudah siap) ───────────────
// Staging : https://api-satusehat-stg.dto.kemkes.go.id
// Production: https://api-satusehat.kemkes.go.id
define('SS_BASE_URL',   'https://api-satusehat-stg.dto.kemkes.go.id');
define('SS_FHIR_URL',   SS_BASE_URL . '/fhir-r4/v1');
define('SS_OAUTH_URL',  SS_BASE_URL . '/oauth2/v1/accesstoken?grant_type=client_credentials');

// ── URL MediFix (untuk dipanggil Orthanc) ───────────────────────
// Ganti dengan IP/domain server MediFix yang bisa diakses dari Orthanc
define('MEDIFIX_BASE_URL', 'http://192.168.1.10/medifix');   // sesuaikan

// ── Cache token (file-based) ─────────────────────────────────────
define('SS_TOKEN_CACHE_FILE', sys_get_temp_dir() . '/ss_token_cache.json');

// ── Timeout cURL (detik) ─────────────────────────────────────────
define('SS_CURL_TIMEOUT', 15);

// ── Mode debug (false di production!) ───────────────────────────
define('SS_DEBUG', true);
