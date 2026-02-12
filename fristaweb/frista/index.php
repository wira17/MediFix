<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verifikasi Wajah BPJS</title>
<link rel="icon" type="image/png" href="http://localhost/anjunganrsph/logobpjs.png">
<style>
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100vh;
    margin: 0;
    background: linear-gradient(135deg, #56ab2f, #3b8d99);
}

.container {
    background: #ffffff;
    padding: 40px 50px;
    border-radius: 16px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
    text-align: center;
    max-width: 480px;
    width: 100%;
    animation: fadeIn 0.7s ease-in-out;
}

@keyframes fadeIn {
    from {opacity: 0; transform: translateY(-10px);}
    to {opacity: 1; transform: translateY(0);}
}

/* Header logo kiri & kanan */
.logo-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.logo-bpjs {
    width: 90px;
    height: auto;
}

.logo-rs {
    width: 130px;
    height: auto;
}

h2 {
    color: #1b5e20;
    margin-bottom: 10px;
    font-weight: 600;
}

p {
    color: #555;
    font-size: 15px;
    margin-bottom: 25px;
}

.input-group {
    position: relative;
    width: 100%;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.input-group i {
    margin-right: 8px;
    color: #00796b;
    font-size: 22px;
    cursor: pointer;
}

input {
    flex: 1;
    padding: 12px;
    font-size: 18px;
    border: 2px solid #c8e6c9;
    border-radius: 8px;
    outline: none;
    transition: border 0.3s, box-shadow 0.3s;
    text-align: center;
}

input:focus {
    border-color: #2196f3;
    box-shadow: 0 0 5px rgba(33, 150, 243, 0.3);
}

button {
    background: linear-gradient(90deg, #2196f3, #4caf50);
    color: white;
    border: none;
    padding: 12px;
    border-radius: 8px;
    font-size: 16px;
    cursor: pointer;
    transition: all 0.3s ease;
    width: 100%;
    margin-bottom: 12px;
}

button:hover {
    background: linear-gradient(90deg, #1976d2, #388e3c);
    transform: scale(1.03);
}

/* Tombol Batal */
.btn-cancel {
    background: #f44336;
    color: white;
}

.btn-cancel:hover {
    background: #c62828;
}

.footer {
    margin-top: 25px;
    font-size: 13px;
    color: #666;
}

.footer i {
    color: #4caf50;
}

/* Keyboard Virtual */
.keyboard {
    display: block;
    margin-top: 15px;
    text-align: center;
}

.keyboard button {
    width: 80px;
    height: 60px;
    margin: 5px;
    font-size: 24px;
    font-weight: 600;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    background: #e0e7ff;
}

.keyboard button:hover {
    background: #c7d2fe;
}

.keyboard .control {
    background: #ff7043;
    color: white;
}

.keyboard .control:hover {
    background: #e64a19;
}
</style>
</head>
<body>

<div class="container">
    <div class="logo-header">
        <img src="http://localhost/fristaweb/logobpjs.png" alt="Logo BPJS" class="logo-bpjs">
        <img src="http://localhost/fristaweb/logors.png" alt="Logo RS" class="logo-rs">
    </div>

    <h2>Verifikasi Wajah BPJS</h2>
    <p>Masukkan NIK Anda untuk melanjutkan proses verifikasi wajah.</p>

    <div class="input-group">
        <i onclick="input.focus()">⌨️</i>
        <input type="text" id="nikInput" placeholder="Masukkan NIK (16 digit)" maxlength="16" readonly>
    </div>

    <div id="keyboard" class="keyboard">
        <div>
            <button type="button" onclick="typeKey('1')">1</button>
            <button type="button" onclick="typeKey('2')">2</button>
            <button type="button" onclick="typeKey('3')">3</button>
        </div>
        <div>
            <button type="button" onclick="typeKey('4')">4</button>
            <button type="button" onclick="typeKey('5')">5</button>
            <button type="button" onclick="typeKey('6')">6</button>
        </div>
        <div>
            <button type="button" onclick="typeKey('7')">7</button>
            <button type="button" onclick="typeKey('8')">8</button>
            <button type="button" onclick="typeKey('9')">9</button>
        </div>
        <div>
            <button type="button" onclick="typeKey('0')">0</button>
            <button type="button" class="control" onclick="backspaceKey()">⌫</button>
            <button type="button" class="control" onclick="clearInput()">🧹</button>
        </div>
    </div>

    <button onclick="goToFrista()">📷 Lanjutkan Verifikasi</button>
    <button class="btn-cancel" onclick="goBack()">⬅️ Batal / Kembali</button>

    <div class="footer">
        <i>©</i> 2025 BPJS Kesehatan | Sistem Verifikasi <b>FRISTA</b>
    </div>
</div>

<script>
const input = document.getElementById('nikInput');

function typeKey(k) {
    if(input.value.length < 16) {
        input.value += k;
    }
}

function backspaceKey() {
    input.value = input.value.slice(0, -1);
}

function clearInput() {
    input.value = '';
}

function goToFrista() {
    const nik = input.value.trim();
    if (nik === '') {
        alert('⚠️ NIK tidak boleh kosong.');
        return;
    }
    if (!/^[0-9]{16}$/.test(nik)) {
        alert('❌ Format NIK harus 16 digit angka.');
        return;
    }
    window.location.href = `frista.php?nik=${encodeURIComponent(nik)}`;
}

function goBack() {
    window.location.href = "http://172.16.10.90/anjunganrsph/anjungan.php";
}
</script>

</body>
</html>
