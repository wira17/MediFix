<?php
/**
 * includes/satusehat_api.php
 * HTTP client & token manager untuk Satu Sehat FHIR API
 */

require_once __DIR__ . '/../config/env.php';

// ══════════════════════════════════════════════════════════════════
//  TOKEN MANAGER
// ══════════════════════════════════════════════════════════════════

/**
 * Dapatkan access token Satu Sehat.
 * Token di-cache ke file selama masa berlaku (biasanya 1 jam).
 */
function getSatuSehatToken(): string {
    // Cek cache
    if (file_exists(SS_TOKEN_CACHE_FILE)) {
        $cache = json_decode(file_get_contents(SS_TOKEN_CACHE_FILE), true);
        if (!empty($cache['token']) && isset($cache['expires_at']) && time() < $cache['expires_at'] - 60) {
            return $cache['token'];
        }
    }

    $ch = curl_init(SS_OAUTH_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => SS_CLIENT_ID,
            'client_secret' => SS_CLIENT_SECRET,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => SS_CURL_TIMEOUT,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) throw new RuntimeException("cURL OAuth error: $err");

    $data = json_decode($resp, true);
    if ($code !== 200 || empty($data['access_token'])) {
        $msg = $data['error_description'] ?? $data['message'] ?? $resp;
        throw new RuntimeException("Token Satu Sehat gagal (HTTP $code): $msg");
    }

    // Simpan cache
    $expiresIn = (int)($data['expires_in'] ?? 3600);
    file_put_contents(SS_TOKEN_CACHE_FILE, json_encode([
        'token'      => $data['access_token'],
        'expires_at' => time() + $expiresIn,
    ]));

    return $data['access_token'];
}

// ══════════════════════════════════════════════════════════════════
//  HTTP CLIENT
// ══════════════════════════════════════════════════════════════════

/**
 * Kirim request ke FHIR endpoint Satu Sehat.
 * Returns: ['code' => int, 'body' => array, 'raw' => string]
 */
function ssFhirRequest(string $method, string $path, ?array $body = null): array {
    $token = getSatuSehatToken();
    $url   = SS_FHIR_URL . '/' . ltrim($path, '/');

    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    if (SS_ORG_ID) {
        $headers[] = 'X-Organization-Id: ' . SS_ORG_ID;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => SS_CURL_TIMEOUT,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }

    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) throw new RuntimeException("cURL FHIR error: $err");

    if (SS_DEBUG) {
        error_log("[SatuSehat] $method $url → HTTP $code | " . substr($raw, 0, 300));
    }

    $parsed = json_decode($raw, true) ?? [];
    return ['code' => $code, 'body' => $parsed, 'raw' => $raw];
}

/**
 * POST resource baru ke Satu Sehat. Lempar exception jika bukan 201.
 * Returns: array body response (resource yang dibuat)
 */
function ssPost(string $resourceType, array $body): array {
    $res = ssFhirRequest('POST', $resourceType, $body);
    if ($res['code'] !== 201) {
        $detail = $res['body']['issue'][0]['diagnostics']
               ?? $res['body']['issue'][0]['details']['text']
               ?? $res['raw'];
        throw new RuntimeException("POST $resourceType gagal (HTTP {$res['code']}): $detail");
    }
    return $res['body'];
}

/**
 * PUT update resource ke Satu Sehat. Returns body response.
 */
function ssPut(string $resourceType, string $id, array $body): array {
    $res = ssFhirRequest('PUT', "$resourceType/$id", $body);
    if (!in_array($res['code'], [200, 201])) {
        $detail = $res['body']['issue'][0]['diagnostics'] ?? $res['raw'];
        throw new RuntimeException("PUT $resourceType/$id gagal (HTTP {$res['code']}): $detail");
    }
    return $res['body'];
}

/**
 * GET resource dari Satu Sehat.
 */
function ssGet(string $path): array {
    $res = ssFhirRequest('GET', $path);
    if ($res['code'] !== 200) {
        throw new RuntimeException("GET $path gagal (HTTP {$res['code']})");
    }
    return $res['body'];
}
