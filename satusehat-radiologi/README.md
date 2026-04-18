# Integrasi Satu Sehat — Radiologi MediFix
## Panduan Instalasi & Konfigurasi

---

## Struktur File

```
satusehat-radiologi/
├── config/
│   ├── env.php                    # Konfigurasi credential Satu Sehat
│   └── loinc_mapping.php          # Mapping kd_jenis_prw → kode LOINC
├── includes/
│   ├── satusehat_api.php          # HTTP client & token manager
│   ├── service_request.php        # Builder & sender ServiceRequest
│   └── imaging_study.php          # Builder & sender ImagingStudy
├── api/
│   ├── kirim_service_request.php  # AJAX endpoint (dipanggil dari halaman)
│   └── push_imagingstudy.php      # Webhook endpoint (dipanggil Orthanc)
├── lua/
│   └── orthanc_satusehat.lua      # Script Orthanc webhook
├── sql/
│   └── migration_satusehat_radiologi.sql  # Migrasi database
├── logs/                          # Log pengiriman (buat folder ini, writable)
└── data_service_request.php       # Halaman monitoring (ganti file lama)
```

---

## Langkah Instalasi

### 1. Jalankan Migrasi Database

Login ke MySQL/MariaDB SIMRS Anda, lalu:

```sql
SOURCE /path/to/sql/migration_satusehat_radiologi.sql;
```

Atau copy-paste isi file SQL ke phpMyAdmin.

> **Penting:** Migrasi menambahkan kolom baru saja (IF NOT EXISTS), tidak
> menghapus data yang sudah ada.

---

### 2. Copy File ke MediFix

Salin semua file ke root folder MediFix Anda:

```bash
cp -r config/     /var/www/html/medifix/config/
cp -r includes/   /var/www/html/medifix/includes/
cp -r api/        /var/www/html/medifix/api/
cp data_service_request.php /var/www/html/medifix/
mkdir -p /var/www/html/medifix/logs
chmod 775 /var/www/html/medifix/logs
```

---

### 3. Konfigurasi Credential Satu Sehat

Edit file `config/env.php`:

```php
define('SS_CLIENT_ID',     'ISI_CLIENT_ID_ANDA');
define('SS_CLIENT_SECRET', 'ISI_CLIENT_SECRET_ANDA');
define('SS_ORG_ID',        'ISI_ORG_ID_ANDA');
```

Atau lebih aman gunakan environment variable di `.htaccess`:

```apache
SetEnv SATUSEHAT_CLIENT_ID     your_client_id
SetEnv SATUSEHAT_CLIENT_SECRET your_client_secret
SetEnv SATUSEHAT_ORG_ID        your_org_id
SetEnv ORTHANC_WEBHOOK_SECRET  rahasia_bersama_orthanc
```

> Ganti `SS_BASE_URL` ke production URL setelah pengujian staging selesai.

---

### 4. Konfigurasi Orthanc

#### a. Copy Lua script ke server Orthanc

```bash
cp lua/orthanc_satusehat.lua /etc/orthanc/orthanc_satusehat.lua
```

#### b. Edit konfigurasi Lua di awal script

```lua
local MEDIFIX_URL = 'http://IP_SERVER_MEDIFIX/medifix/api/push_imagingstudy.php'
local SECRET_KEY  = 'rahasia_bersama_orthanc'   -- harus sama dengan ORTHANC_WEBHOOK_SECRET
```

#### c. Daftarkan script di orthanc.json

```json
{
  "LuaScripts": [
    "/etc/orthanc/orthanc_satusehat.lua"
  ]
}
```

#### d. Restart Orthanc

```bash
sudo systemctl restart orthanc
# Cek log:
sudo journalctl -u orthanc -f
```

Jika berhasil, akan muncul baris:
```
[SatuSehat] Orthanc Satu Sehat Lua script dimuat. Target: http://...
```

---

### 5. Mapping kd_jenis_prw → LOINC

Edit file `config/loinc_mapping.php` dan tambahkan kode jenis perawatan
radiologi sesuai yang ada di SIMRS Anda.

Contoh format:
```php
'KODE_DI_SIMRS' => ['KODE_LOINC', 'Nama Pemeriksaan'],
```

Referensi kode LOINC radiologi: https://loinc.org/radiology/

---

### 6. Lengkapi Data Wajib di SIMRS

Sebelum bisa mengirim ke Satu Sehat, data berikut HARUS sudah ada:

| Data | Sumber | Kolom Database |
|------|--------|----------------|
| IHS Number pasien | Sinkron dari Satu Sehat API | `pasien.ihs_number` |
| Encounter ID | POST Encounter ke Satu Sehat | `reg_periksa.id_encounter` |
| IHS Dokter | Master dokter Satu Sehat | `dokter.ihs_dokter` |

Jika data ini belum ada, tombol kirim akan menampilkan ikon peringatan
(warna kuning) dan tidak bisa dikirim.

---

### 7. Konfigurasi AccessionNumber di Modality

Karena modality tidak support DICOM Worklist (MWL), AccessionNumber (0008,0050)
harus diisi **manual** saat melakukan scanning:

- Isi AccessionNumber dengan **No. Order** dari SIMRS (nilai di kolom `noorder`)
- Hal ini penting agar Orthanc bisa mencocokkan DICOM yang masuk dengan
  data ServiceRequest di SIMRS

Cara alternatif (tanpa input manual):
- Gunakan DICOM Router (dcm4che `dcmqrscp` atau `storescu`) yang bisa
  menambahkan/mengubah tag sebelum diteruskan ke Orthanc
- Konfigurasi DICOM worklist di router meskipun modality tidak support MWL

---

## Alur Kerja Lengkap

```
1. Dokter buat permintaan radiologi di SIMRS
   → Data masuk ke permintaan_radiologi & satu_sehat_servicerequest_radiologi

2. Petugas klik "Kirim" di halaman Service Request
   → POST ServiceRequest ke Satu Sehat API
   → Dapat id_servicerequest, disimpan ke DB

3. Radiografer scan pasien, isi AccessionNumber = noorder
   → Push DICOM ke Orthanc (C-STORE dari modality atau import manual)

4. Orthanc terima DICOM
   → Lua script OnStoredInstance terpicu
   → POST ke api/push_imagingstudy.php dengan data DICOM

5. push_imagingstudy.php
   → Cari id_servicerequest dari DB berdasarkan AccessionNumber
   → POST ImagingStudy ke Satu Sehat API
   → Simpan id_imagingstudy ke DB

6. Halaman monitoring menampilkan status SR + IS real-time
```

---

## Troubleshooting

### Token Satu Sehat gagal
- Cek CLIENT_ID dan CLIENT_SECRET di `config/env.php`
- Pastikan server bisa akses internet ke `api-satusehat-stg.dto.kemkes.go.id`
- Cek file cache token: `ls -la /tmp/ss_token_cache.json`

### ImagingStudy tidak terkirim setelah DICOM masuk
- Cek log Orthanc: `journalctl -u orthanc | grep SatuSehat`
- Cek log MediFix: `tail -f logs/imagingstudy.log`
- Pastikan ORTHANC_WEBHOOK_SECRET sama di kedua file
- Pastikan Orthanc bisa akses IP MediFix (tidak diblokir firewall)
- Cek AccessionNumber sudah diisi dengan benar (= noorder SIMRS)

### Status "DICOM ada, menunggu SR"
- Artinya DICOM sudah masuk ke Orthanc tapi ServiceRequest belum dikirim
- Kirim dulu ServiceRequest-nya, ImagingStudy akan otomatis terkirim
  saat retry berikutnya (atau kirim manual dari halaman)

### Error "ihs_number kosong"
- Data pasien belum disinkronkan ke Satu Sehat
- Jalankan fitur sinkronisasi pasien terlebih dahulu

### Error "id_encounter kosong"
- Encounter belum dibuat di Satu Sehat untuk kunjungan ini
- Pastikan modul Encounter SIMRS sudah terintegrasi dan berjalan

---

## Log Files

| File | Isi |
|------|-----|
| `logs/imagingstudy.log` | Log pengiriman ImagingStudy dari Orthanc |
| PHP error_log | Log umum termasuk token & ServiceRequest |

Untuk debug, aktifkan `SS_DEBUG = true` di `config/env.php`.
