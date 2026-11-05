-- 005_billing: balances, reservations, append-only ledger, invoices, payments
-- Sign convention: ledger amount > 0 credits the customer (topup/payment), < 0 debits (call charge).

CREATE TABLE IF NOT EXISTS balances (
    customer_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    balance     DECIMAL(18,6) NOT NULL DEFAULT 0,     -- derived from ledger (affects_balance = 1)
    reserved    DECIMAL(18,6) NOT NULL DEFAULT 0,     -- open prepaid reservations
    updated_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    CONSTRAINT fk_balance_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS balance_reservations (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    call_id     VARCHAR(190) NOT NULL,
    amount      DECIMAL(18,6) NOT NULL,
    max_seconds INT UNSIGNED NOT NULL,
    status      VARCHAR(12) NOT NULL DEFAULT 'open',   -- open | settled | released | expired
    expires_at  DATETIME NOT NULL,
    created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    closed_at   DATETIME NULL,
    UNIQUE KEY uq_reservation_call (call_id),
    KEY ix_reservation_customer (customer_id, status),
    CONSTRAINT fk_resv_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ledger_entries (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reseller_id        BIGINT UNSIGNED NULL,
    customer_id        BIGINT UNSIGNED NOT NULL,
    entry_type         VARCHAR(24) NOT NULL,   -- initial_credit,topup,adjustment,reservation,reservation_release,call_charge,refund,invoice_payment,reversal
    amount             DECIMAL(18,6) NOT NULL,
    currency           CHAR(3) NOT NULL,
    affects_balance    TINYINT(1) NOT NULL DEFAULT 1,
    reference_entry_id BIGINT UNSIGNED NULL,   -- reversals point at the original
    cdr_id             BIGINT UNSIGNED NULL,
    source_system      VARCHAR(32) NULL,
    external_ref       VARCHAR(191) NULL,
    run_type           VARCHAR(16) NULL,       -- customer | carrier
    idempotency_key    VARCHAR(191) NOT NULL,
    description        VARCHAR(255) NULL,
    created_by         BIGINT UNSIGNED NULL,
    created_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_ledger_idem (idempotency_key),
    KEY ix_ledger_customer (customer_id, created_at),
    KEY ix_ledger_reseller (reseller_id, created_at),
    KEY ix_ledger_type (entry_type, created_at),
    KEY ix_ledger_ref (reference_entry_id),
    CONSTRAINT fk_ledger_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoices (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number       VARCHAR(32) NOT NULL,
    reseller_id  BIGINT UNSIGNED NULL,
    customer_id  BIGINT UNSIGNED NOT NULL,
    period_start DATE NOT NULL,
    period_end   DATE NOT NULL,
    currency     CHAR(3) NOT NULL,
    subtotal     DECIMAL(18,4) NOT NULL DEFAULT 0,
    tax          DECIMAL(18,4) NOT NULL DEFAULT 0,
    total        DECIMAL(18,4) NOT NULL DEFAULT 0,
    status       VARCHAR(12) NOT NULL DEFAULT 'issued',   -- draft | issued | paid | void
    issued_at    DATETIME NULL,
    created_by   BIGINT UNSIGNED NULL,
    created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_invoice_number (number),
    UNIQUE KEY uq_invoice_period (customer_id, period_start, period_end),
    KEY ix_invoice_reseller (reseller_id),
    CONSTRAINT fk_invoice_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_lines (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    invoice_id  BIGINT UNSIGNED NOT NULL,
    line_no     INT UNSIGNED NOT NULL,
    description VARCHAR(255) NOT NULL,
    quantity    BIGINT UNSIGNED NOT NULL DEFAULT 0,       -- calls
    seconds     BIGINT UNSIGNED NOT NULL DEFAULT 0,       -- billable seconds
    amount      DECIMAL(18,6) NOT NULL,
    KEY ix_invline_invoice (invoice_id),
    CONSTRAINT fk_invline_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    customer_id     BIGINT UNSIGNED NOT NULL,
    invoice_id      BIGINT UNSIGNED NULL,
    amount          DECIMAL(18,6) NOT NULL,
    method          VARCHAR(32) NULL,
    reference       VARCHAR(190) NULL,
    ledger_entry_id BIGINT UNSIGNED NULL,
    created_by      BIGINT UNSIGNED NULL,
    created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY ix_payment_customer (customer_id),
    CONSTRAINT fk_payment_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
