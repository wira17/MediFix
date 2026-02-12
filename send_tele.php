<?php
// TOKEN & CHAT ID TELE-MU
$TOKEN  = "";
$CHATID = "";

// Ambil semua parameter yang dikirim
$d = $_GET;

// Helper angka → format Rupiah
function rp($n){
    return number_format($n,0,',','.');
}

// Icon status
$status_icon = ($d['status'] == "Tidak Aman") ? "❗" : "✅";

// Diagnosa Utama (jika kosong → "-")
$diag_utama = isset($d['diag_utama']) && trim($d['diag_utama']) != "" 
              ? $d['diag_utama'] 
              : "-";

$msg = "
🏥 <b>MEDIFIX - PERINGATAN BIAYA RAWAT INAP</b>
━━━━━━━━━━━━━━━━━━━━━━

🆔 <b>No. Rawat:</b> {$d['no_rawat']}
📄 <b>No. RM:</b> {$d['no_rm']}
👤 <b>Nama:</b> {$d['nama']}
🚪 <b>Kamar:</b> {$d['kamar']}

🧬 <b>Diagnosa Utama:</b> $diag_utama

━━━━━━━━━━━━━━━━━━━━━━
💰 <b>RINCIAN BIAYA</b>
💳 Registrasi    : Rp ".rp($d['registrasi'])."
🛏 Ranap/Ralan   : Rp ".rp($d['ranap_ralan'])."
💊 Obat           : Rp ".rp($d['obat'])."
🧪 Laboratorium   : Rp ".rp($d['laborat'])."
🩻 Radiologi      : Rp ".rp($d['radiologi'])."
🚪 Kamar          : Rp ".rp($d['kamarbiaya'])."
🔪 Operasi        : Rp ".rp($d['operasi'])."
📅 Harian         : Rp ".rp($d['harian'])."

━━━━━━━━━━━━━━━━━━━━━━
💵 <b>Total Biaya:</b> <b>Rp ".rp($d['total'])."</b>
🏦 <b>Deposit:</b> Rp ".rp($d['deposit'])."
⚖️ <b>Sisa Deposit:</b> <b>Rp ".rp($d['sisa'])."</b>

━━━━━━━━━━━━━━━━━━━━━━
🔔 <b>Status:</b> $status_icon <b>{$d['status']}</b>
";

// Kirim ke Telegram
$url = "https://api.telegram.org/bot$TOKEN/sendMessage";
$post = [
    'chat_id' => $CHATID,
    'text' => $msg,
    'parse_mode' => 'HTML'
];

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $post,
    CURLOPT_RETURNTRANSFER => true
]);
$response = curl_exec($ch);
curl_close($ch);

// Redirect kembali
header("Location: perkiraan_biaya_ranap.php");
exit;
?>
