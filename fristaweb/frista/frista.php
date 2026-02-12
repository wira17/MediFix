<?php
/**
 * BPJS Face Recognition - Auto Camera + Auto Verify
 * NIK otomatis diambil dari QR scanner (query string)
 */

define('BPJS_USERNAME', '');
define('BPJS_PASSWORD', '*');

// Ambil NIK dari QR scanner jika ada, pakai default jika tidak
$nikFromQR = $_GET['nik'] ?? '';
define('NIK', $nikFromQR);

// === BACKEND (untuk verifikasi) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    error_reporting(0);
    require __DIR__ . '/../vendor/autoload.php'; // path ke PHPFrista

    $frista = new PHPFrista\FacialRecognition();
    $frista->init(BPJS_USERNAME, BPJS_PASSWORD);

    $input = json_decode(file_get_contents('php://input'), true);

    if ($_GET['action'] === 'verify') {
        $result = $frista->verify($input['nik'] ?? NIK, $input['encoding']);
        echo json_encode([
            'success' => in_array($result['status'] ?? null, [
                PHPFrista\StatusCode::OK,
                PHPFrista\StatusCode::ALREADY_REGISTERED
            ]),
            'message' => $result['message'] ?? '',
            'status_code' => $result['status'] ?? null,
            'confidence' => $result['confidence'] ?? null
        ]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>FRISTA - Verifikasi Wajah BPJS</title>
<script src="https://cdn.jsdelivr.net/npm/@vladmandic/face-api/dist/face-api.min.js"></script>
<style>
body {
    margin: 0;
    padding: 0;
    background: linear-gradient(180deg, #0f172a 0%, #1e293b 100%);
    font-family: 'Segoe UI', Arial, sans-serif;
    color: #f1f5f9;
    display: flex;
    flex-direction: column;
    align-items: center;
    min-height: 100vh;
}

.header {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-top: 20px;
}

.header img {
    width: 60px;
    height: 60px;
}

.header h1 {
    font-size: 22px;
    font-weight: 600;
    color: #38bdf8;
}

.card {
    background: rgba(255, 255, 255, 0.08);
    padding: 25px;
    border-radius: 16px;
    width: 480px;
    max-width: 90%;
    margin-top: 25px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.4);
    text-align: center;
}

.camera-wrapper {
    position: relative;
    width: 100%;
    aspect-ratio: 4 / 3;
    border-radius: 12px;
    overflow: hidden;
    margin-top: 10px;
}

video, canvas {
    position: absolute;
    top: 0; left: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
}

select {
    margin-top: 10px;
    padding: 8px;
    font-size: 14px;
    border-radius: 8px;
    border: none;
    outline: none;
    background: #334155;
    color: white;
    width: 100%;
}

.status {
    margin-top: 15px;
    font-size: 15px;
    text-align: center;
    background: rgba(255,255,255,0.1);
    padding: 10px;
    border-radius: 8px;
}

.btn-back {
    margin-top: 20px;
    display: inline-block;
    background: #38bdf8;
    color: #0f172a;
    padding: 10px 18px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    transition: 0.3s;
}

.btn-back:hover {
    background: #7dd3fc;
}

.footer {
    margin-top: 25px;
    font-size: 13px;
    color: #94a3b8;
}
</style>
</head>
<body>

<div class="header">
    <img src="http://localhost/fristaweb/logobpjs.png" alt="BPJS Logo">
    <h1>FRISTA - Face Recognition BPJS</h1>
</div>

<div class="card">
    <h2>🔍 Verifikasi Wajah Peserta</h2>
    <select id="cameraSelect"></select>
    <div class="camera-wrapper">
        <video id="video" autoplay muted playsinline></video>
        <canvas id="overlay"></canvas>
    </div>
    <div id="status" class="status">⏳ Memuat model AI...</div>
    <a href="index.php" class="btn-back">⬅️ Kembali</a>
</div>

<div class="footer">
    © 2025 BPJS Kesehatan — Sistem Verifikasi <b>FRISTA</b>
</div>

<script>
const video = document.getElementById('video');
const overlay = document.getElementById('overlay');
const statusBox = document.getElementById('status');
const cameraSelect = document.getElementById('cameraSelect');
const NIK = '<?php echo NIK; ?>'; // NIK otomatis dari QR

let modelsLoaded = false;
let faceCaptured = false;
let currentStream = null;

async function loadModels() {
    const MODEL_URL = 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api/model/';
    await faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL);
    await faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL);
    await faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL);
    modelsLoaded = true;
    statusBox.innerText = '✅ Model siap, mencari kamera...';
    await listCameras();
}

// List kamera
async function listCameras() {
    const devices = await navigator.mediaDevices.enumerateDevices();
    const cameras = devices.filter(d => d.kind === 'videoinput');
    cameraSelect.innerHTML = '';

    if (cameras.length === 0) {
        statusBox.innerText = '❌ Tidak ada kamera terdeteksi.';
        return;
    }

    cameras.forEach((cam, i) => {
        const option = document.createElement('option');
        option.value = cam.deviceId;
        option.text = cam.label || `Kamera ${i + 1}`;
        cameraSelect.appendChild(option);
    });

    const savedCam = localStorage.getItem('selectedCamera');
    if (savedCam && cameras.some(c => c.deviceId === savedCam)) {
        cameraSelect.value = savedCam;
    }

    cameraSelect.onchange = () => {
        localStorage.setItem('selectedCamera', cameraSelect.value);
        startCamera(cameraSelect.value);
    };

    startCamera(cameraSelect.value || cameras[0].deviceId);
}

// Start kamera
async function startCamera(deviceId) {
    try {
        if (currentStream) currentStream.getTracks().forEach(track => track.stop());

        const constraints = {
            video: deviceId
                ? { deviceId: { exact: deviceId } }
                : { facingMode: 'user' }
        };

        const stream = await navigator.mediaDevices.getUserMedia(constraints);
        currentStream = stream;
        video.srcObject = stream;
        await new Promise(res => video.onloadedmetadata = res);

        overlay.width = video.videoWidth;
        overlay.height = video.videoHeight;

        statusBox.innerText = '📷 Kamera aktif. Deteksi wajah dimulai...';
        detectAndVerify();
    } catch (err) {
        statusBox.innerText = '❌ Tidak dapat membuka kamera: ' + err.message;
    }
}

// Deteksi wajah
async function detectAndVerify() {
    const ctx = overlay.getContext('2d');
    const size = { width: overlay.width, height: overlay.height };
    faceapi.matchDimensions(overlay, size);

    setInterval(async () => {
        if (faceCaptured) return;
        const detection = await faceapi
            .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions())
            .withFaceLandmarks()
            .withFaceDescriptor();

        ctx.clearRect(0, 0, overlay.width, overlay.height);

        if (detection) {
            const resized = faceapi.resizeResults(detection, size);
            faceapi.draw.drawDetections(overlay, resized);
            faceapi.draw.drawFaceLandmarks(overlay, resized);

            const conf = detection.detection.score.toFixed(2);
            statusBox.innerText = `😎 Wajah terdeteksi (confidence: ${conf})`;

            if (conf > 0.6) {
                faceCaptured = true;
                statusBox.innerText = '🔄 Mengirim data ke BPJS untuk verifikasi...';
                verifyBPJS(Array.from(detection.descriptor));
            }
        } else {
            statusBox.innerText = '🔍 Arahkan wajah ke kamera...';
        }
    }, 250);
}

// Verifikasi ke backend
async function verifyBPJS(encoding) {
    try {
        const res = await fetch('?action=verify', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ nik: NIK, encoding })
        });
        const data = await res.json();
        if (data.success) {
            statusBox.innerText = `✅ Verifikasi Berhasil!\nPesan: ${data.message}\nConfidence BPJS: ${data.confidence || '-'}`;
        } else {
            statusBox.innerText = `❌ Verifikasi Gagal!\nPesan: ${data.message}`;
        }
    } catch (err) {
        statusBox.innerText = '❌ Error: ' + err.message;
    }
}

loadModels();
</script>
</body>
</html>
