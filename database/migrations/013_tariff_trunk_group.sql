-- 013: Magnus-style Trunk Group on sell tariffs (pkg_rate.id_trunk_group).
-- NULL = fall back to the customer's assigned trunk group.

ALTER TABLE rate_plan_tariffs
    ADD COLUMN routing_plan_id BIGINT UNSIGNED NULL AFTER destination;

ALTER TABLE rate_plan_tariffs
    ADD KEY ix_rpt_routing_plan (routing_plan_id);

ALTER TABLE rate_plan_tariffs
    ADD CONSTRAINT fk_rpt_routing_plan FOREIGN KEY (routing_plan_id) REFERENCES routing_plans (id)
        ON DELETE SET NULL;
