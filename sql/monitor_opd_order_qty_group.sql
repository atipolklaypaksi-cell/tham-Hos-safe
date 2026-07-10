ALTER TABLE monitor_opd_order_qty_settings
    ADD COLUMN rule_type VARCHAR(20) NOT NULL DEFAULT 'drug' COMMENT 'drug|continuous' AFTER id;

ALTER TABLE monitor_opd_order_qty_settings
    DROP INDEX uk_icode;

ALTER TABLE monitor_opd_order_qty_settings
    ADD UNIQUE KEY uk_rule_target (rule_type, icode);
