-- ================================================================
--  sql/migration_satusehat_radiologi.sql
--  Tambahkan kolom-kolom baru ke tabel yang sudah ada di SIMRS Khanza
--  Jalankan sekali saja di database SIMRS Anda.
-- ================================================================

-- ── 1. Tambah kolom ke tabel satu_sehat_servicerequest_radiologi ─
--       (jalankan satu per satu jika ada yang sudah exist)

ALTER TABLE satu_sehat_servicerequest_radiologi
    ADD COLUMN IF NOT EXISTS status_kirim_sr  ENUM('pending','terkirim','error') DEFAULT 'pending'   COMMENT 'Status pengiriman ServiceRequest',
    ADD COLUMN IF NOT EXISTS tgl_kirim_sr     DATETIME NULL                                          COMMENT 'Waktu berhasil kirim ServiceRequest',
    ADD COLUMN IF NOT EXISTS error_msg_sr     VARCHAR(500) NULL                                      COMMENT 'Pesan error ServiceRequest terakhir',
    ADD COLUMN IF NOT EXISTS id_imagingstudy  VARCHAR(100) NULL                                      COMMENT 'ID ImagingStudy dari Satu Sehat',
    ADD COLUMN IF NOT EXISTS study_uid_dicom  VARCHAR(100) NULL                                      COMMENT 'StudyInstanceUID dari DICOM (Orthanc)',
    ADD COLUMN IF NOT EXISTS status_kirim_is  ENUM('pending','pending_sr','terkirim','error') DEFAULT 'pending' COMMENT 'Status pengiriman ImagingStudy',
    ADD COLUMN IF NOT EXISTS tgl_kirim_is     DATETIME NULL                                          COMMENT 'Waktu berhasil kirim ImagingStudy',
    ADD COLUMN IF NOT EXISTS error_msg_is     VARCHAR(500) NULL                                      COMMENT 'Pesan error ImagingStudy terakhir';

-- ── 2. Tambah index untuk performa query ─────────────────────────
CREATE INDEX IF NOT EXISTS idx_ssr_status_sr ON satu_sehat_servicerequest_radiologi (status_kirim_sr);
CREATE INDEX IF NOT EXISTS idx_ssr_status_is ON satu_sehat_servicerequest_radiologi (status_kirim_is);
CREATE INDEX IF NOT EXISTS idx_ssr_study_uid ON satu_sehat_servicerequest_radiologi (study_uid_dicom);

-- ── 3. Pastikan tabel pasien punya kolom ihs_number ──────────────
ALTER TABLE pasien
    ADD COLUMN IF NOT EXISTS ihs_number VARCHAR(50) NULL COMMENT 'No IHS Satu Sehat' AFTER no_rkm_medis;

CREATE INDEX IF NOT EXISTS idx_pasien_ihs ON pasien (ihs_number);

-- ── 4. Pastikan tabel dokter punya kolom ihs_dokter ──────────────
ALTER TABLE dokter
    ADD COLUMN IF NOT EXISTS ihs_dokter VARCHAR(50) NULL COMMENT 'IHS Practitioner ID Satu Sehat';

CREATE INDEX IF NOT EXISTS idx_dokter_ihs ON dokter (ihs_dokter);

-- ── 5. Pastikan tabel reg_periksa punya kolom id_encounter ────────
ALTER TABLE reg_periksa
    ADD COLUMN IF NOT EXISTS id_encounter VARCHAR(100) NULL COMMENT 'Encounter ID Satu Sehat';

CREATE INDEX IF NOT EXISTS idx_reg_encounter ON reg_periksa (id_encounter);

-- ── 6. (Opsional) Tambah kolom dokter radiologi jika belum ada ───
ALTER TABLE permintaan_radiologi
    ADD COLUMN IF NOT EXISTS dokter_radiologi VARCHAR(20) NULL COMMENT 'Kd dokter radiologi/spesialis';

-- ── 7. Buat tabel log pengiriman (opsional tapi direkomendasikan) ─
CREATE TABLE IF NOT EXISTS log_satusehat_radiologi (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    noorder       VARCHAR(30) NOT NULL,
    tipe          ENUM('ServiceRequest','ImagingStudy') NOT NULL,
    aksi          ENUM('kirim','kirim_ulang','error') NOT NULL,
    id_resource   VARCHAR(100) NULL     COMMENT 'id dari Satu Sehat jika berhasil',
    http_code     SMALLINT NULL,
    pesan         TEXT NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_by    VARCHAR(50) NULL      COMMENT 'user_id yang melakukan kirim',
    INDEX (noorder),
    INDEX (tipe, aksi),
    INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Log pengiriman Satu Sehat radiologi';

-- Selesai.
SELECT 'Migration selesai!' AS hasil;
