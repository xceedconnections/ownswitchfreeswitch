-- Plan prefixes: dial digits (1, 221) plus a name (CC, Convo route) on a rate plan.
-- Each prefix has its own tariff codes. The code row still holds the trunk group.
-- Legacy tariffs keep plan_prefix_id NULL and match the full dialled number.

CREATE TABLE IF NOT EXISTS plan_prefixes (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    rate_plan_id BIGINT UNSIGNED NOT NULL,
    prefix       VARCHAR(20)  NOT NULL,
    name         VARCHAR(120) NOT NULL,
    status       VARCHAR(16)  NOT NULL DEFAULT 'active',
    created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_plan_prefixes_plan_prefix (rate_plan_id, prefix),
    KEY ix_plan_prefixes_plan (rate_plan_id),
    CONSTRAINT fk_plan_prefixes_plan FOREIGN KEY (rate_plan_id) REFERENCES rate_plans (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE rate_plan_tariffs
    ADD COLUMN plan_prefix_id BIGINT UNSIGNED NULL AFTER rate_plan_id;

ALTER TABLE rate_plan_tariffs
    ADD COLUMN plan_prefix_key BIGINT UNSIGNED
        GENERATED ALWAYS AS (IFNULL(plan_prefix_id, 0)) STORED AFTER plan_prefix_id;

-- fk_rpt_plan uses uq_plan_prefix (the only index that starts with rate_plan_id).
-- Add a replacement index first, then drop the old unique key.
ALTER TABLE rate_plan_tariffs
    ADD KEY ix_rpt_rate_plan (rate_plan_id);

ALTER TABLE rate_plan_tariffs
    ADD UNIQUE KEY uq_plan_prefix_code (rate_plan_id, plan_prefix_key, prefix);

ALTER TABLE rate_plan_tariffs
    DROP INDEX uq_plan_prefix;

ALTER TABLE rate_plan_tariffs
    ADD KEY ix_rpt_plan_prefix (plan_prefix_id);

ALTER TABLE rate_plan_tariffs
    ADD CONSTRAINT fk_rpt_plan_prefix FOREIGN KEY (plan_prefix_id) REFERENCES plan_prefixes (id);
