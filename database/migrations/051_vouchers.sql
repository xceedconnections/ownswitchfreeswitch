-- 051: prepaid vouchers (staff-issued codes) + customer redemptions (credit posted via ledger topup)

CREATE TABLE IF NOT EXISTS vouchers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(32) NOT NULL,
  amount DECIMAL(18,6) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  status ENUM('unused','used','disabled') NOT NULL DEFAULT 'unused',
  created_by BIGINT UNSIGNED NULL,
  used_by_customer_id BIGINT UNSIGNED NULL,
  used_at DATETIME NULL,
  expires_at DATETIME NULL,
  notes VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vouchers_code (code),
  KEY idx_vouchers_status (status),
  KEY idx_vouchers_customer (used_by_customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voucher_redemptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  voucher_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  ledger_entry_id BIGINT UNSIGNED NULL,
  amount DECIMAL(18,6) NOT NULL,
  currency CHAR(3) NOT NULL,
  redeemed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  redeemed_by_user_id BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_voucher_redemption_voucher (voucher_id),
  KEY idx_voucher_redemptions_customer (customer_id, redeemed_at),
  KEY idx_voucher_redemptions_ledger (ledger_entry_id),
  CONSTRAINT fk_voucher_redemption_voucher FOREIGN KEY (voucher_id) REFERENCES vouchers (id),
  CONSTRAINT fk_voucher_redemption_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
