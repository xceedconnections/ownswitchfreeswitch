-- CLI restriction lists. A SIP user can be assigned several lists.
-- If the caller ID they present is on an assigned list, the call is refused.

CREATE TABLE IF NOT EXISTS cli_restrictions (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(120) NOT NULL,
    status     VARCHAR(16)  NOT NULL DEFAULT 'active',
    notes      VARCHAR(255) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_cli_restrictions_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cli_restriction_numbers (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    cli_restriction_id  BIGINT UNSIGNED NOT NULL,
    cli_digits          VARCHAR(32) NOT NULL,
    note                VARCHAR(120) NULL,
    created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_cli_restriction_digits (cli_restriction_id, cli_digits),
    KEY ix_cli_restriction_digits (cli_digits),
    CONSTRAINT fk_cli_restriction_numbers FOREIGN KEY (cli_restriction_id) REFERENCES cli_restrictions (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sip_account_cli_restrictions (
    sip_account_id     BIGINT UNSIGNED NOT NULL,
    cli_restriction_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (sip_account_id, cli_restriction_id),
    KEY ix_sip_cli_restriction (cli_restriction_id),
    CONSTRAINT fk_sip_cli_account FOREIGN KEY (sip_account_id) REFERENCES sip_accounts (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_sip_cli_list FOREIGN KEY (cli_restriction_id) REFERENCES cli_restrictions (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
