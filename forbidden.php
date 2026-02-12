<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$showAlert = false;

if (!empty($_SESSION['forbidden'])) {
    $showAlert = true;
    unset($_SESSION['forbidden']); // supaya tidak muncul lagi reload
}
?>

<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Akses Ditolak</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
body {
    background: linear-gradient(120deg, #ff9800, #f44336, #2196f3, #ffeb3b);
    background-size: 300% 300%;
    animation: bgMove 10s infinite alternate ease-in-out;
    height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
    font-family: 'Segoe UI', sans-serif;
}

@keyframes bgMove {
    0% { background-position: left; }
    100% { background-position: right; }
}

.card-box {
    background: rgba(255, 255, 255, 0.92);
    padding: 40px;
    border-radius: 15px;
    text-align: center;
    backdrop-filter: blur(10px);
    box-shadow: 0 10px 30px rgba(0,0,0,0.15);
    animation: fadeDown .8s ease;
}

@keyframes fadeDown {
    from { opacity:0; transform: translateY(-20px); }
    to { opacity:1; transform: translateY(0); }
}

.icon-ban {
    font-size: 90px;
    color: red;
}
</style>
</head>

<body>

<div class="card-box">
    <i class="bi bi-shield-x icon-ban"></i>
    <h3 class="fw-bold mt-3 text-danger">Akses Ditolak</h3>
    <p class="text-muted">Maaf, Anda tidak memiliki izin membuka menu ini.</p>

    <a href="dashboard.php" class="btn btn-primary">
        <i class="bi bi-arrow-left-circle"></i> Baiklah
    </a>
</div>

<?php if ($showAlert): ?>
<script>
Swal.fire({
    icon: 'error',
    title: 'Akses Ditolak!',
    text: 'Anda tidak memiliki izin untuk membuka halaman ini.',
    confirmButtonText: 'Mengerti'
});
</script>
<?php endif; ?>

</body>
</html>
