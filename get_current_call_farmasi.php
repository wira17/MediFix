<?php
/**
 * get_current_call_farmasi.php
 * Endpoint ringan untuk polling display antrian farmasi.
 * Mengembalikan JSON panggilan terbaru hari ini per jenis resep.
 *
 * Query params:
 *   ?since_nr=  unix timestamp — Non Racikan terakhir yang diketahui client
 *   ?since_r=   unix timestamp — Racikan terakhir yang diketahui client
 */
include 'koneksi2.php';
date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json');
header('Cache-Control: no-store');

$tgl      = date('Y-m-d');
$since_nr = intval($_GET['since_nr'] ?? 0);
$since_r  = intval($_GET['since_r']  ?? 0);

function getLatestCall($pdo, $tgl, $jenis) {
    $stmt = $pdo->prepare("
        SELECT
            no_resep, no_rawat, no_rkm_medis, no_antrian,
            nm_pasien, nm_poli, nm_dokter, jenis_resep, jml_panggil,
            UNIX_TIMESTAMP(updated_at) AS ts
        FROM simpan_antrian_farmasi_wira
        WHERE tgl_panggil = ? AND jenis_resep = ?
        ORDER BY updated_at DESC
        LIMIT 1
    ");
    $stmt->execute([$tgl, $jenis]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

try {
    $nr = getLatestCall($pdo_simrs, $tgl, 'Non Racikan');
    $r  = getLatestCall($pdo_simrs, $tgl, 'Racikan');

    $ts_nr = $nr ? (int)$nr['ts'] : 0;
    $ts_r  = $r  ? (int)$r['ts']  : 0;

    // Kalau keduanya tidak berubah sejak terakhir client tahu — balas ringkas
    if ($since_nr > 0 && $since_r > 0 && $ts_nr <= $since_nr && $ts_r <= $since_r) {
        echo json_encode(['changed' => false, 'ts_nr' => $ts_nr, 'ts_r' => $ts_r]);
        exit;
    }

    // Helper: format data untuk response
    $fmt = function($row) {
        if (!$row) return ['has_data' => false];
        return [
            'has_data'    => true,
            'no_antrian'  => $row['no_antrian'],
            'no_resep'    => $row['no_resep'],
            'no_rawat'    => $row['no_rawat'],
            'nm_pasien'   => $row['nm_pasien'],
            'nm_poli'     => $row['nm_poli']    ?: 'Instalasi Farmasi',
            'nm_dokter'   => $row['nm_dokter']  ?: '',
            'jml_panggil' => (int)$row['jml_panggil'],
            'ts'          => (int)$row['ts'],
        ];
    };

    echo json_encode([
        'changed'     => true,
        'ts_nr'       => $ts_nr,
        'ts_r'        => $ts_r,
        'non_racikan' => $fmt($nr),
        'racikan'     => $fmt($r),
    ]);

} catch (PDOException $e) {
    echo json_encode(['changed' => false, 'error' => $e->getMessage()]);
}