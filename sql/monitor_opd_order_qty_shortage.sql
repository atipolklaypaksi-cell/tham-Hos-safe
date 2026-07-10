ALTER TABLE monitor_opd_order_qty_settings
    ADD COLUMN shortage_pills_allowed INT NOT NULL DEFAULT 0 AFTER excess_pills_allowed;
