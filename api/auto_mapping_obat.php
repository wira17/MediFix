<?php
/**
 * api/auto_mapping_obat.php
 * Auto-mapping obat SIMRS → KFA Satu Sehat berdasarkan pencarian nama
 *
 * Endpoint KFA: GET /kfa-v2/products/all?search=NAMA&product_type=farmasi
 * Response KFA menyediakan: kfa_code, name, dosage_form, rute_pemberian, ucum
 *
 * Actions:
 *   cari_satu    — cari KFA untuk 1 obat (AJAX, tampilkan pilihan ke user)
 *   simpan_pilih — simpan pilihan user dari hasil pencarian
 *   auto_semua   — auto-map semua obat yang belum mapping (batch, pakai cron/background)
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['status'=>'error','message'=>'Sesi habis']); exit;
}

require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../koneksi2.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../includes/satusehat_api.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Cek config sudah ter-load ─────────────────────────────────────
if (!defined('SS_CLIENT_ID') || SS_CLIENT_ID === '') {
    echo json_encode(['status'=>'error','message'=>'Kredensial Satu Sehat belum diisi. Buka Setting → Setting Satu Sehat.']);
    exit;
}

// ── Helper: bersihkan nama obat untuk search ──────────────────────
function bersihkanNama(string $nama): string {
    $nama = preg_replace('/\s+\d+[\.,]?\d*\s*(mg|mcg|g|ml|iu|ui|%|cc)\b.*/i', '', $nama);
    $hapus = ['tablet','tab','kapsul','kap','cap','sirup','syr','injeksi','inj',
              'infus','tetes','drop','salep','krim','cream','suppositoria','supp',
              'larutan','suspensi','serbuk','granul','inhaler','patch','ampul'];
    foreach ($hapus as $h) {
        $nama = preg_replace('/\b'.preg_quote($h,'/').'\.?\b/i', '', $nama);
    }
    return trim(preg_replace('/\s+/', ' ', $nama));
}

// ── Helper: hitung skor kecocokan nama ───────────────────────────
function skorKesesuaian(string $namaSIMRS, string $namaKFA): float {
    $a = strtolower(trim($namaSIMRS));
    $b = strtolower(trim($namaKFA));

    // Exact match
    if ($a === $b) return 1.0;

    // Cek apakah kata pertama nama SIMRS ada di nama KFA
    $kataA = explode(' ', $a);
    $kataB = explode(' ', $b);
    $kataUtama = $kataA[0]; // kata pertama = nama zat aktif biasanya

    if (!str_contains($b, $kataUtama)) return 0.0; // nama utama tidak ada = tidak cocok

    // Hitung berapa kata SIMRS yang ada di nama KFA
    $cocok = 0;
    foreach ($kataA as $k) {
        if (strlen($k) >= 3 && str_contains($b, $k)) $cocok++;
    }

    return count($kataA) > 0 ? ($cocok / count($kataA)) : 0.0;
}

// ── Helper: bersihkan nama nama dagang → zat aktif ───────────────
function ekstrakZatAktif(string $nama): string {
    // Hapus nama dagang dalam kurung: "AB-Vask (Otsus)" → "AB-Vask"
    $nama = preg_replace('/\s*\(.*?\)/', '', $nama);
    // Hapus kode internal faskes di awal/akhir
    $nama = preg_replace('/^[A-Z]{2,}-/', '', $nama); // AB-Vask → Vask (coba dua versi)
    return trim($nama);
}
function searchKFA(string $keyword, int $size = 5): array {
    try {
        $token = getSatuSehatToken();
    } catch (Exception $e) {
        throw new RuntimeException('Token gagal: ' . $e->getMessage());
    }

    // KFA endpoint — domain sama, path /kfa-v2/
    // SS_BASE_URL = https://api-satusehat.kemkes.go.id
    $kfaBase = rtrim(SS_BASE_URL, '/');
    $url = $kfaBase . '/kfa-v2/products/all?' . http_build_query([
        'search'       => $keyword,
        'product_type' => 'farmasi',
        'page'         => 1,
        'size'         => max($size * 3, 15), // minta lebih banyak, nanti difilter
        'active'       => 'true',
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ],
    ]);
    $resp    = curl_exec($ch);
    $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) throw new RuntimeException('cURL error: ' . $curlErr);

    if ($code === 401) throw new RuntimeException('Token expired atau tidak valid');
    if ($code === 404) {
        // Coba endpoint alternatif kfa-v1
        return searchKFAv1($keyword, $size);
    }
    if ($code !== 200) return [];

    $json = json_decode($resp, true);
    // Response KFA: {"data": [...], "total": N}
    $items = $json['data'] ?? $json['items'] ?? $json['result'] ?? [];
    if (isset($items['data'])) $items = $items['data'];
    return is_array($items) ? array_values($items) : [];
}

// ── Fallback ke KFA v1 jika v2 tidak ada ─────────────────────────
function searchKFAv1(string $keyword, int $size = 5): array {
    try { $token = getSatuSehatToken(); } catch (Exception $e) { return []; }

    $url = rtrim(SS_BASE_URL, '/') . '/kfa/products/all?' . http_build_query([
        'search' => $keyword,
        'page'   => 1,
        'size'   => $size,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) return [];
    $json  = json_decode($resp, true);
    $items = $json['data'] ?? $json['items'] ?? $json['result'] ?? [];
    if (isset($items['data'])) $items = $items['data'];
    return is_array($items) ? array_values($items) : [];
}

// ── Helper: simpan mapping ke DB ──────────────────────────────────
function simpanMappingKFA(PDO $pdo, string $kodeBrng, array $item): void {
    $obatCode    = $item['kfa_code']               ?? '';
    $obatDisplay = $item['name']                   ?? $item['nama_dagang'] ?? '';
    $formCode    = $item['dosage_form']['code']     ?? '';
    $formDisplay = $item['dosage_form']['name']     ?? '';
    $uomName     = $item['uom']['name']            ?? ''; // Tablet, Kapsul, dll

    // Satuan numerator dari KFA (net_weight_uom_name = mg, mcg, g, dll)
    $numerCode   = $item['net_weight_uom_name']    ?? 'mg';

    // Denominator = satuan sediaan dalam bentuk kode singkat
    // Contoh: Tablet→TAB, Kapsul→CAP, Sirup→SYR, dll
    $denomMap = [
        'tablet'       => 'TAB', 'kapsul'      => 'CAP', 'capsule'     => 'CAP',
        'sirup'        => 'SYR', 'syrup'        => 'SYR', 'suspensi'    => 'SUSP',
        'injeksi'      => 'INJ', 'injection'    => 'INJ', 'ampul'       => 'AMP',
        'vial'         => 'VIAL','salep'         => 'OINT','krim'        => 'CREAM',
        'cream'        => 'CREAM','tetes'        => 'DROP','drop'        => 'DROP',
        'suppositoria' => 'SUPP','inhaler'       => 'INHL','serbuk'      => 'POWD',
        'granul'       => 'GRAN','larutan'       => 'SOLN','solution'    => 'SOLN',
        'patch'        => 'PATCH','plester'      => 'PATCH',
    ];
    $uomLower  = strtolower($uomName ?: $formDisplay);
    $denomCode = 'TAB'; // default
    foreach ($denomMap as $kata => $kode) {
        if (str_contains($uomLower, $kata)) { $denomCode = $kode; break; }
    }

    // Route — ambil dari KFA jika ada, default Oral
    $routeCode    = $item['rute_pemberian']['code'] ?? 'O';
    $routeDisplay = $item['rute_pemberian']['name'] ?? 'Oral';
    // Normalize kode route ke format ATC (huruf kapital)
    if (empty($routeCode)) $routeCode = 'O';

    $pdo->prepare("
        INSERT INTO satu_sehat_mapping_obat
            (kode_brng, obat_code, obat_system, obat_display,
             form_code, form_system, form_display,
             numerator_code, numerator_system,
             denominator_code, denominator_system,
             route_code, route_system, route_display)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            obat_code=VALUES(obat_code), obat_system=VALUES(obat_system),
            obat_display=VALUES(obat_display),
            form_code=VALUES(form_code), form_system=VALUES(form_system),
            form_display=VALUES(form_display),
            numerator_code=VALUES(numerator_code), numerator_system=VALUES(numerator_system),
            denominator_code=VALUES(denominator_code), denominator_system=VALUES(denominator_system),
            route_code=VALUES(route_code), route_system=VALUES(route_system),
            route_display=VALUES(route_display)
    ")->execute([
        $kodeBrng,
        $obatCode,
        'http://sys-ids.kemkes.go.id/kfa',
        $obatDisplay,
        $formCode,
        'http://terminology.kemkes.go.id/CodeSystem/medication-form',  // ← sesuai Khanza
        $formDisplay,
        $numerCode,                                                      // mg, mcg, g
        'http://unitsofmeasure.org',
        $denomCode,                                                      // TAB, CAP, SYR
        'http://terminology.hl7.org/CodeSystem/v3-orderableDrugForm',   // ← sesuai Khanza
        $routeCode,                                                      // O, IV, IM
        'http://www.whocc.no/atc',                                       // ← sesuai Khanza
        $routeDisplay,
    ]);
}

// ══════════════════════════════════════════════════════════════════
//  ACTION: cari_satu — cari KFA 1 obat, tampilkan pilihan
// ══════════════════════════════════════════════════════════════════
if ($action === 'cari_satu') {
    $kodeBrng = trim($_POST['kode_brng'] ?? '');
    if (!$kodeBrng) { echo json_encode(['status'=>'error','message'=>'kode_brng wajib']); exit; }

    // Ambil nama dari databarang
    $stmt = $pdo_simrs->prepare("SELECT nama_brng FROM databarang WHERE kode_brng = ? LIMIT 1");
    $stmt->execute([$kodeBrng]);
    $brng = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$brng) { echo json_encode(['status'=>'error','message'=>'Barang tidak ditemukan']); exit; }

    $keyword = bersihkanNama($brng['nama_brng']);
    $keyword2 = bersihkanNama(ekstrakZatAktif($brng['nama_brng']));

    $items = searchKFA($keyword, 8);
    if (empty($items) && $keyword2 !== $keyword) {
        $items = searchKFA($keyword2, 8);
        $keyword = $keyword2;
    }
    if (empty($items)) {
        $kata1 = explode(' ', $keyword)[0];
        if (strlen($kata1) >= 4) {
            $items = searchKFA($kata1, 8);
        }
    }

    // Tambahkan skor kecocokan ke setiap item
    foreach ($items as &$item) {
        $item['_skor'] = round(skorKesesuaian($brng['nama_brng'], $item['name'] ?? '') * 100);
    }
    // Urutkan berdasarkan skor tertinggi
    usort($items, fn($a,$b) => ($b['_skor'] ?? 0) <=> ($a['_skor'] ?? 0));

    echo json_encode([
        'status'    => 'ok',
        'kode_brng' => $kodeBrng,
        'nama_brng' => $brng['nama_brng'],
        'keyword'   => $keyword,
        'items'     => $items,
        'jumlah'    => count($items),
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════════════
//  ACTION: simpan_pilih — simpan hasil pilihan user
// ══════════════════════════════════════════════════════════════════
if ($action === 'simpan_pilih') {
    $kodeBrng = trim($_POST['kode_brng'] ?? '');
    $itemJson  = $_POST['item_json']    ?? '';

    if (!$kodeBrng || !$itemJson) {
        echo json_encode(['status'=>'error','message'=>'Data tidak lengkap']); exit;
    }

    $item = json_decode($itemJson, true);
    if (!$item || empty($item['kfa_code'])) {
        echo json_encode(['status'=>'error','message'=>'Data KFA tidak valid']); exit;
    }

    try {
        simpanMappingKFA($pdo_simrs, $kodeBrng, $item);
        echo json_encode([
            'status'      => 'ok',
            'message'     => 'Mapping berhasil disimpan',
            'kfa_code'    => $item['kfa_code'],
            'obat_display'=> $item['name'] ?? '',
        ]);
    } catch (Exception $e) {
        echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════
//  ACTION: auto_semua — batch auto-mapping (kirim per halaman)
//  Proses per batch 20 obat, return progress
// ══════════════════════════════════════════════════════════════════
if ($action === 'auto_semua') {
    $offset    = max(0, (int)($_POST['offset'] ?? 0));
    $batchSize = 10; // 10 per batch agar tidak timeout

    // Ambil obat yang belum mapping
    $stmt = $pdo_simrs->prepare("
        SELECT db.kode_brng, db.nama_brng
        FROM databarang db
        LEFT JOIN satu_sehat_mapping_obat m ON db.kode_brng = m.kode_brng
        WHERE db.status = '1' AND m.kode_brng IS NULL
        ORDER BY db.nama_brng ASC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$batchSize, $offset]);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Total belum mapping
    $total = (int)$pdo_simrs->query("
        SELECT COUNT(*) FROM databarang db
        LEFT JOIN satu_sehat_mapping_obat m ON db.kode_brng = m.kode_brng
        WHERE db.status = '1' AND m.kode_brng IS NULL
    ")->fetchColumn();

    if (empty($list)) {
        echo json_encode([
            'status'   => 'selesai',
            'message'  => 'Semua obat sudah diproses',
            'berhasil' => 0,
            'gagal'    => 0,
            'skip'     => 0,
            'total_sisa' => 0,
        ]);
        exit;
    }

    $berhasil = 0; $gagal = 0; $skip = 0;

    foreach ($list as $brng) {
        $namaAsli = $brng['nama_brng'];

        // Strategi pencarian bertingkat
        $strategies = [
            bersihkanNama($namaAsli),                   // nama bersih penuh
            bersihkanNama(ekstrakZatAktif($namaAsli)),  // hapus kode faskes
            explode(' ', bersihkanNama($namaAsli))[0],  // kata pertama saja
        ];
        $strategies = array_unique(array_filter($strategies, fn($s) => strlen($s) >= 3));

        $bestItem  = null;
        $bestScore = 0.0;

        foreach ($strategies as $keyword) {
            try {
                $items = searchKFA($keyword, 5);
                foreach ($items as $item) {
                    $skor = skorKesesuaian($namaAsli, $item['name'] ?? '');
                    if ($skor > $bestScore) {
                        $bestScore = $skor;
                        $bestItem  = $item;
                    }
                }
                if ($bestScore >= 0.5) break; // cukup cocok, hentikan pencarian
                usleep(100000);
            } catch (Exception $e) {
                $gagal++;
                continue 2; // lanjut ke obat berikutnya
            }
        }

        if ($bestItem && $bestScore >= 0.3) {
            // Skor >= 0.3 = ada kecocokan minimal, simpan
            try {
                simpanMappingKFA($pdo_simrs, $brng['kode_brng'], $bestItem);
                $berhasil++;
            } catch (Exception $e) {
                $gagal++;
            }
        } else {
            // Tidak ada kecocokan cukup — skip, biarkan manual
            $skip++;
        }

        usleep(150000); // 150ms jeda antar obat
    }

    $nextOffset  = $offset + $batchSize;
    $sisaProses  = max(0, $total - $batchSize);

    echo json_encode([
        'status'      => $sisaProses > 0 ? 'lanjut' : 'selesai',
        'next_offset' => $nextOffset,
        'berhasil'    => $berhasil,
        'gagal'       => $gagal,
        'skip'        => $skip,
        'total_sisa'  => $sisaProses,
        'processed'   => count($list),
    ]);
    exit;
}

// ── test — debug koneksi ke KFA ──────────────────────────────────
if ($action === 'test') {
    try {
        $token   = getSatuSehatToken();
        $results = searchKFA('amoxicillin', 2);
        echo json_encode([
            'status'  => 'ok',
            'token'   => substr($token, 0, 20) . '...',
            'kfa_url' => rtrim(SS_BASE_URL, '/') . '/kfa-v2/products/all',
            'results' => $results,
            'jumlah'  => count($results),
        ]);
    } catch (Exception $e) {
        echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
    }
    exit;
}

// ── cari_manual — cari KFA dengan keyword custom dari user ────────
if ($action === 'cari_manual') {
    $kodeBrng = trim($_POST['kode_brng'] ?? '');
    $keyword  = trim($_POST['keyword']   ?? '');

    if (!$keyword) { echo json_encode(['status'=>'error','message'=>'keyword kosong']); exit; }

    // Ambil nama SIMRS untuk scoring
    $stmt = $pdo_simrs->prepare("SELECT nama_brng FROM databarang WHERE kode_brng = ? LIMIT 1");
    $stmt->execute([$kodeBrng]);
    $brng = $stmt->fetch(PDO::FETCH_ASSOC);
    $namaSimrs = $brng['nama_brng'] ?? $keyword;

    try {
        $items = searchKFA($keyword, 10);
        foreach ($items as &$item) {
            $item['_skor'] = round(skorKesesuaian($keyword, $item['name'] ?? '') * 100);
        }
        usort($items, fn($a,$b) => ($b['_skor']??0) <=> ($a['_skor']??0));
        echo json_encode(['status'=>'ok','items'=>$items,'jumlah'=>count($items)]);
    } catch (Exception $e) {
        echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
    }
    exit;
}

echo json_encode(['status'=>'error','message'=>"Action '$action' tidak dikenal"]);
