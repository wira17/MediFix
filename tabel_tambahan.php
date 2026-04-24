CREATE TABLE IF NOT EXISTS `medifix_ss_encounter` (
  `no_rawat`    varchar(25) NOT NULL,
  `id_encounter` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`no_rawat`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Isi dari satu_sehat_encounter yang sudah ada
INSERT IGNORE INTO medifix_ss_encounter (no_rawat, id_encounter)
SELECT no_rawat, id_encounter FROM satu_sehat_encounter
WHERE id_encounter IS NOT NULL AND id_encounter != '';



-- ============================================================
-- Tabel tracking DiagnosticReport Radiologi untuk Satu Sehat
-- Jalankan query ini di database SIMRS Anda
-- ============================================================

CREATE TABLE IF NOT EXISTS `medifix_ss_diagnosticreport_radiologi` (
  `noorder`             varchar(15)   NOT NULL,
  `kd_jenis_prw`        varchar(15)   NOT NULL,
  `no_rawat`            varchar(25)   NOT NULL,
  `id_diagnosticreport` varchar(100)  DEFAULT NULL,
  `id_servicerequest`   varchar(100)  DEFAULT NULL COMMENT 'Referensi SR yang sudah terkirim',
  `id_imagingstudy`     varchar(100)  DEFAULT NULL COMMENT 'Referensi IS jika sudah ada',
  `status_kirim`        enum('pending','terkirim','error') NOT NULL DEFAULT 'pending',
  `tgl_kirim`           datetime      DEFAULT NULL,
  `error_msg`           varchar(500)  DEFAULT NULL,
  `created_at`          datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`noorder`),
  KEY `idx_status`      (`status_kirim`),
  KEY `idx_id_dr`       (`id_diagnosticreport`),
  KEY `idx_no_rawat`    (`no_rawat`),
  KEY `idx_updated`     (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Tracking pengiriman DiagnosticReport Radiologi ke Satu Sehat';



  -- ============================================================
-- Tabel tracking EpisodeOfCare ke Satu Sehat
-- Jalankan di database SIMRS
-- ============================================================
CREATE TABLE IF NOT EXISTS `medifix_ss_episode_of_care` (
  `id`                  INT AUTO_INCREMENT PRIMARY KEY,
  `no_rawat`            VARCHAR(25)  NOT NULL,
  `kd_penyakit`         VARCHAR(10)  NOT NULL,
  `status_diagnosa`     VARCHAR(10)  NOT NULL DEFAULT 'Aktif',
  `id_episode_of_care`  VARCHAR(100) DEFAULT NULL,
  `id_encounter`        VARCHAR(100) DEFAULT NULL,
  `status_kirim`        ENUM('pending','terkirim','error') NOT NULL DEFAULT 'pending',
  `tgl_kirim`           DATETIME     DEFAULT NULL,
  `error_msg`           VARCHAR(500) DEFAULT NULL,
  `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_rawat_penyakit` (`no_rawat`, `kd_penyakit`, `status_diagnosa`),
  KEY `idx_status`      (`status_kirim`),
  KEY `idx_id_eoc`      (`id_episode_of_care`),
  KEY `idx_no_rawat`    (`no_rawat`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Tracking pengiriman EpisodeOfCare (ANC) ke Satu Sehat';