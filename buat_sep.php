<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'koneksi.php';      // untuk setting_vclaim
include 'koneksi2.php';     // untuk pasien, reg_periksa, dokter, poli

// === CEK INPUT NO_RAWAT ===
if (!isset($_GET['no_rawat'])) {
    die("No Rawat belum dipilih.");
}
$no_rawat = $_GET['no_rawat'];

// === AMBIL DATA SETTING VCLAIM ===
$stmt = $pdo->query("SELECT * FROM setting_vclaim LIMIT 1");
$setting = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$setting) die("Setting VClaim belum ada.");

// === AMBIL DATA PASIEN & REGISTRASI ===
$stmt = $pdo2->prepare("
    SELECT r.no_rawat, r.tgl_registrasi, r.kd_dokter, r.kd_poli,
           p.no_rkm_medis, p.nm_pasien, p.no_ktp, p.jk, p.tmp_lahir, p.tgl_lahir, p.no_tlp
    FROM reg_periksa r
    JOIN pasien p ON p.no_rkm_medis = r.no_rkm_medis
    WHERE r.no_rawat = ?
");
$stmt->execute([$no_rawat]);
$pasien = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$pasien) die("Data pasien tidak ditemukan.");

// === AMBIL DATA DOKTER ===
$stmt = $pdo2->prepare("SELECT kd_dokter, nm_dokter FROM dokter WHERE kd_dokter=?");
$stmt->execute([$pasien['kd_dokter']]);
$dokter = $stmt->fetch(PDO::FETCH_ASSOC);

// === AMBIL DATA POLI ===
$stmt = $pdo2->prepare("SELECT kd_poli, nm_poli FROM poliklinik WHERE kd_poli=?");
$stmt->execute([$pasien['kd_poli']]);
$poli = $stmt->fetch(PDO::FETCH_ASSOC);

// === CONSTRUCT PAYLOAD SEP 2.0 ===
$payload = [
    "request" => [
        "t_sep" => [
            "noKartu" => $pasien['no_ktp'], // ganti sesuai kolom no_kartu BPJS jika ada
            "tglSep" => date('Y-m-d'),
            "ppkPelayanan" => $setting['kd_ppk'],
            "jnsPelayanan" => "1", // default rawat inap
            "klsRawat" => [
                "klsRawatHak" => "2", // contoh kelas
                "klsRawatNaik" => "",
                "pembiayaan" => "",
                "penanggungJawab" => ""
            ],
            "noMR" => $pasien['no_rkm_medis'],
            "rujukan" => [
                "asalRujukan" => "",
                "tglRujukan" => "",
                "noRujukan" => "",
                "ppkRujukan" => ""
            ],
            "catatan" => "",
            "diagAwal" => "",
            "poli" => [
                "tujuan" => $poli['kd_poli'],
                "eksekutif" => "0"
            ],
            "cob" => [
                "cob" => "0"
            ],
            "katarak" => [
                "katarak" => "0"
            ],
            "jaminan" => [
                "lakaLantas" => "0",
                "noLP" => "",
                "penjamin" => [
                    "tglKejadian" => "",
                    "keterangan" => "",
                    "suplesi" => [
                        "suplesi" => "0",
                        "noSepSuplesi" => "",
                        "lokasiLaka" => [
                            "kdPropinsi" => "",
                            "kdKabupaten" => "",
                            "kdKecamatan" => ""
                        ]
                    ]
                ]
            ],
            "tujuanKunj" => "0",
            "flagProcedure" => "",
            "kdPenunjang" => "",
            "assesmentPel" => "",
            "skdp" => [
                "noSurat" => "",
                "kodeDPJP" => $dokter['kd_dokter'] ?? ""
            ],
            "dpjpLayan" => "",
            "noTelp" => $pasien['no_tlp'],
            "user" => "System SEP"
        ]
    ]
];

// === KONVERSI KE JSON ===
$jsonPayload = json_encode($payload);

// === KIRIM REQUEST KE BPJS ===
$url = rtrim($setting['base_url'], '/') . '/SEP/2.0/insert';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/x-www-form-urlencoded'
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['data' => $jsonPayload]));

$response = curl_exec($ch);
if (curl_errno($ch)) {
    die("Curl error: " . curl_error($ch));
}
curl_close($ch);

// === TAMPILKAN RESPONSE ===
echo "<pre>";
print_r(json_decode($response, true));
echo "</pre>";
