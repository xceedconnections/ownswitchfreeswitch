-- 012_prefixes: Magnus-style global Prefixes (prefix + destination name only).
-- Tariffs / provider rates keep their own rate rows and reference these codes.

CREATE TABLE IF NOT EXISTS prefixes (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    prefix      VARCHAR(20)  NOT NULL,
    destination VARCHAR(120) NOT NULL DEFAULT '',
    created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_prefixes_prefix (prefix),
    KEY ix_prefixes_destination (destination)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed from existing sell + buy tariff rows (longest destination name wins ties).
INSERT INTO prefixes (prefix, destination)
SELECT x.prefix, COALESCE(NULLIF(TRIM(x.destination), ''), CONCAT('Prefix ', x.prefix))
FROM (
    SELECT prefix, MAX(destination) AS destination
    FROM (
        SELECT prefix, destination FROM rate_plan_tariffs
        UNION ALL
        SELECT prefix, destination FROM carrier_tariffs
    ) u
    WHERE prefix IS NOT NULL AND prefix <> ''
    GROUP BY prefix
) x
ON DUPLICATE KEY UPDATE
    destination = IF(TRIM(prefixes.destination) = '' OR prefixes.destination LIKE 'Prefix %',
                     VALUES(destination), prefixes.destination);
