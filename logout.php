<?php
// logout.php - pastikan file ini tidak mengandung spasi/keterangan sebelum <?php
session_start();

// Pastikan session benar-benar aktif
if (session_status() === PHP_SESSION_ACTIVE) {
    // Bersihkan semua variabel session
    $_SESSION = [];

    // Jika ingin, hapus juga session dari storage (ID)
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();

        // setcookie dengan waktu lampau untuk menghapus cookie session pada client
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            isset($params['secure']) ? (bool)$params['secure'] : false,
            isset($params['httponly']) ? (bool)$params['httponly'] : false
        );
    }

    // Hapus session di server
    session_unset();
    session_destroy();
}

// Tambahan: pastikan browser tidak menyajikan halaman dari cache
header('Cache-Control: no-cache, no-store, must-revalidate'); // HTTP/1.1
header('Pragma: no-cache'); // HTTP/1.0
header('Expires: 0'); // Proxies

// Redirect ke login (ubah path jika perlu)
header('Location: login.php');
exit;
