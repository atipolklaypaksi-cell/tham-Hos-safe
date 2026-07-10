ALTER TABLE monitor_opd_order_qty_settings
    ADD COLUMN exclude_icodes TEXT NULL DEFAULT NULL COMMENT 'JSON array of icode to skip for continuous group' AFTER detail_text;
