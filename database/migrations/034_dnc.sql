-- 034: DNC (Do Not Call) groups + numbers; optional per-trunk assignment

CREATE TABLE IF NOT EXISTS dnc_groups (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  notes VARCHAR(255) NULL,
  number_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dnc_groups_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Digits-only E.164 without '+'. Composite unique keeps group lookups O(log n).
CREATE TABLE IF NOT EXISTS dnc_numbers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dnc_group_id BIGINT UNSIGNED NOT NULL,
  number_digits VARCHAR(32) NOT NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dnc_group_number (dnc_group_id, number_digits),
  KEY idx_dnc_number_digits (number_digits),
  CONSTRAINT fk_dnc_numbers_group FOREIGN KEY (dnc_group_id) REFERENCES dnc_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'carrier_trunks' AND COLUMN_NAME = 'dnc_group_id') = 0,
    'ALTER TABLE carrier_trunks ADD COLUMN dnc_group_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER rate_cap, ADD KEY idx_carrier_trunks_dnc (dnc_group_id)',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
