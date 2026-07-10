<?php
/**
 * Helper: ดึงรายการยา HOSxP พร้อมชื่อจาก TMT (tmt_tpu_code)
 */

function hosxp_load_config($con) {
    $hos_cfg = [];
    $q = mysqli_query($con, "SELECT config_name, config_value FROM sys_config WHERE config_name IN ('h_host', 'h_user', 'h_pass', 'h_db')");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $hos_cfg[$r['config_name']] = $r['config_value'];
        }
    }
    return $hos_cfg;
}

function hosxp_connect($con, $read_timeout = 120) {
    $cfg = hosxp_load_config($con);
    mysqli_report(MYSQLI_REPORT_OFF);
    $c = mysqli_init();
    if (!$c) {
        return null;
    }
    $read_timeout = max(30, min(900, (int)$read_timeout));
    mysqli_options($c, MYSQLI_OPT_CONNECT_TIMEOUT, 8);
    if (defined('MYSQLI_OPT_READ_TIMEOUT')) {
        mysqli_options($c, MYSQLI_OPT_READ_TIMEOUT, $read_timeout);
    }
    $ok = @mysqli_real_connect(
        $c,
        $cfg['h_host'] ?? '',
        $cfg['h_user'] ?? '',
        $cfg['h_pass'] ?? '',
        $cfg['h_db'] ?? ''
    );
    if (!$ok) {
        return null;
    }
    mysqli_set_charset($c, 'utf8');
    return $c;
}

/** ต่อ HOSxP ใหม่ถ้า connection หลุด (กันคิวรีช้าแล้ว MySQL server has gone away) */
function hosxp_ensure_connection($con, &$hos, $read_timeout = 600) {
    if ($hos && @mysqli_ping($hos)) {
        return $hos;
    }
    if ($hos) {
        @mysqli_close($hos);
    }
    $hos = hosxp_connect($con, $read_timeout);
    if ($hos) {
        hosxp_extend_read_timeout($hos, $read_timeout);
    }
    return $hos;
}

function hosxp_extend_read_timeout($hos, $seconds = 180) {
    if (!$hos) {
        return;
    }
    $seconds = max(30, min(900, (int)$seconds));
    @mysqli_query($hos, 'SET SESSION max_statement_time = ' . $seconds);
    @mysqli_query($hos, 'SET SESSION wait_timeout = ' . $seconds);
    @mysqli_query($hos, 'SET SESSION net_read_timeout = ' . $seconds);
    @mysqli_query($hos, 'SET SESSION net_write_timeout = ' . $seconds);
}

function hosxp_sql_tmt_drug_name($d = 'd', $tm = 'tm') {
    return "CAST(CONVERT(COALESCE(NULLIF(TRIM(CONCAT(IFNULL($tm.active_ingredient, ''), ' ', IFNULL($tm.strength, ''))), ''), $d.name) USING utf8) AS CHAR)";
}

function hosxp_sql_tmt_join($d = 'd', $tm = 'tm') {
    return "LEFT JOIN tmt_tpu_code $tm ON $d.tpu_code_list = $tm.tpu_code";
}

/** strength จาก TMT ก่อน ถ้าไม่มีใช้ drugitems.strength */
function hosxp_sql_tmt_strength($d = 'd', $tm = 'tm') {
    return "COALESCE(NULLIF(TRIM($tm.strength), ''), $d.strength)";
}

function hosxp_search_drugs_tmt($con_hosxp, $term = '', $limit = 30) {
    $tmt_expr = hosxp_sql_tmt_drug_name('d', 'tm');
    $where = "d.istatus = 'Y' AND d.icode IS NOT NULL AND TRIM(d.icode) <> ''";
    $term = trim($term);
    if ($term !== '') {
        $t_esc = mysqli_real_escape_string($con_hosxp, $term);
        $where .= " AND (d.icode LIKE '%$t_esc%' OR d.name LIKE '%$t_esc%' OR $tmt_expr LIKE '%$t_esc%')";
    }
    $limit = max(1, min(50, (int)$limit));
    $sql = "
        SELECT d.icode,
               CAST(CONVERT(d.name USING utf8) AS CHAR) AS name_local,
               $tmt_expr AS name_tmt
        FROM drugitems d
        " . hosxp_sql_tmt_join('d', 'tm') . "
        WHERE $where
        ORDER BY name_local ASC
        LIMIT $limit
    ";
    $list = [];
    $q = mysqli_query($con_hosxp, $sql);
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $local = trim($r['name_local'] ?? '');
            $tmt = trim($r['name_tmt'] ?? '');
            if ($tmt === '') {
                $tmt = $local;
            }
            $list[] = [
                'icode' => trim($r['icode']),
                'name_local' => $local,
                'name_tmt' => $tmt,
            ];
        }
    }
    return $list;
}

function hosxp_fetch_active_drugs_tmt($con_hosxp) {
    $tmt_expr = hosxp_sql_tmt_drug_name('d', 'tm');
    $sql = "
        SELECT d.icode,
               CAST(CONVERT(d.name USING utf8) AS CHAR) AS name_local,
               $tmt_expr AS name_tmt
        FROM drugitems d
        " . hosxp_sql_tmt_join('d', 'tm') . "
        WHERE d.istatus = 'Y'
          AND d.icode IS NOT NULL
          AND TRIM(d.icode) <> ''
        ORDER BY name_local ASC
    ";
    $list = [];
    $q = mysqli_query($con_hosxp, $sql);
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $local = trim($r['name_local'] ?? '');
            $tmt = trim($r['name_tmt'] ?? '');
            if ($tmt === '') {
                $tmt = $local;
            }
            $list[] = [
                'icode' => trim($r['icode']),
                'name_local' => $local,
                'name_tmt' => $tmt,
            ];
        }
    }
    return $list;
}

function hosxp_build_drug_options_html($drugs, $placeholder) {
    $html = "<option value=''>" . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . "</option>";
    foreach ($drugs as $d) {
        $icode = $d['icode'];
        $local = $d['name_local'];
        $tmt = $d['name_tmt'];
        $val = htmlspecialchars($icode . '|' . $tmt, ENT_QUOTES, 'UTF-8');
        $label = htmlspecialchars($local, ENT_QUOTES, 'UTF-8');
        if ($tmt !== '' && $tmt !== $local) {
            $label .= ' — ' . htmlspecialchars($tmt, ENT_QUOTES, 'UTF-8');
        }
        $label .= ' (icode: ' . htmlspecialchars($icode, ENT_QUOTES, 'UTF-8') . ')';
        $html .= "<option value=\"{$val}\">{$label}</option>";
    }
    return $html;
}

function hosxp_parse_lab_option($val) {
    $val = trim((string)$val);
    if ($val === '') {
        return ['code' => '', 'name' => ''];
    }
    $parts = explode('|', $val, 2);
    return [
        'code' => trim($parts[0]),
        'name' => trim($parts[1] ?? ''),
    ];
}

function hosxp_search_lab_items($con_hosxp, $term = '', $limit = 40) {
    $limit = max(1, min(80, (int)$limit));
    $where = "lab_items_code IS NOT NULL AND TRIM(lab_items_code) <> ''";
    $term = trim($term);
    if ($term !== '') {
        $t_esc = mysqli_real_escape_string($con_hosxp, $term);
        $where .= " AND (lab_items_code LIKE '%$t_esc%' OR lab_items_name LIKE '%$t_esc%')";
    }
    $sql = "
        SELECT lab_items_code,
               CAST(CONVERT(lab_items_name USING utf8) AS CHAR) AS lab_items_name
        FROM lab_items
        WHERE $where
        ORDER BY lab_items_name ASC
        LIMIT $limit
    ";
    $list = [];
    $q = mysqli_query($con_hosxp, $sql);
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $code = trim($r['lab_items_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $list[] = [
                'code' => $code,
                'name' => trim($r['lab_items_name'] ?? ''),
            ];
        }
    }
    return $list;
}

/** ตรวจเงื่อนไข Lab แบบข้อความ/ตัวเลข — ใช้ร่วม Monitor UA และ Monitor Lab อื่นๆ */
function monitor_lab_match_condition($cond_type, $cond_val, $lab_result) {
    $cond_type = trim((string)$cond_type);
    $cond_val = trim((string)$cond_val);
    $lab_result = trim((string)$lab_result);
    if ($cond_type === '' || $cond_type === 'range' || $lab_result === '') {
        return false;
    }
    if ($cond_type === 'regex') {
        return @preg_match('/' . $cond_val . '/i', $lab_result) === 1;
    }
    if ($cond_type === 'contains') {
        return stripos($lab_result, $cond_val) !== false;
    }
    if ($cond_type === 'less_than') {
        return floatval($lab_result) < floatval($cond_val);
    }
    if ($cond_type === 'greater_than') {
        return floatval($lab_result) > floatval($cond_val);
    }
    return false;
}

function monitor_lab_condition_type_label($type) {
    $labels = [
        'range' => 'ช่วงตัวเลข',
        'regex' => 'Regex (ข้อความ)',
        'contains' => 'มีคำว่า... (Text)',
        'less_than' => 'น้อยกว่า <',
        'greater_than' => 'มากกว่า >',
    ];
    return $labels[$type] ?? $type;
}

function monitor_lab_alert_level_from_rule($rule) {
    if (!empty($rule['alert_level']) && in_array($rule['alert_level'], ['hard', 'soft'], true)) {
        return $rule['alert_level'];
    }
    if (($rule['action_type'] ?? '') === 'omit') {
        return 'hard';
    }
    return 'soft';
}

function ensure_drug_safety_rules_alert_level_column($con) {
    $chk = mysqli_query($con, "SHOW COLUMNS FROM drug_safety_rules LIKE 'alert_level'");
    if ($chk && mysqli_num_rows($chk) > 0) {
        return true;
    }

    $prev_mode = mysqli_report(MYSQLI_REPORT_OFF);
    try {
        $altered = mysqli_query(
            $con,
            "ALTER TABLE drug_safety_rules
                ADD COLUMN alert_level VARCHAR(8) NOT NULL DEFAULT 'soft' COMMENT 'hard | soft'
                AFTER suggestion_msg"
        );
        if (!$altered) {
            return false;
        }

        mysqli_query(
            $con,
            "UPDATE drug_safety_rules
             SET alert_level = IF(action_type = 'omit', 'hard', 'soft')
             WHERE alert_level IS NULL OR TRIM(alert_level) = ''"
        );

        $chk2 = mysqli_query($con, "SHOW COLUMNS FROM drug_safety_rules LIKE 'alert_level'");
        return $chk2 && mysqli_num_rows($chk2) > 0;
    } catch (mysqli_sql_exception $e) {
        return false;
    } finally {
        mysqli_report($prev_mode);
    }
}

function ensure_drug_safety_rules_hosxp_columns($con) {
    $chk = mysqli_query($con, "SHOW COLUMNS FROM drug_safety_rules LIKE 'drug_icode'");
    if ($chk && mysqli_num_rows($chk) > 0) {
        return true;
    }

    $prev_mode = mysqli_report(MYSQLI_REPORT_OFF);
    try {
        $altered = mysqli_query(
            $con,
            "ALTER TABLE drug_safety_rules
                ADD COLUMN drug_icode VARCHAR(20) NULL DEFAULT NULL,
                ADD COLUMN drug_name VARCHAR(255) NULL DEFAULT NULL"
        );
        if (!$altered) {
            return false;
        }

        mysqli_query(
            $con,
            "UPDATE drug_safety_rules r
             INNER JOIN drugitems d ON r.drug_id = d.id
             SET r.drug_icode = d.icode,
                 r.drug_name = d.name
             WHERE (r.drug_icode IS NULL OR TRIM(r.drug_icode) = '')
               AND d.icode IS NOT NULL
               AND TRIM(d.icode) <> ''"
        );

        $chk2 = mysqli_query($con, "SHOW COLUMNS FROM drug_safety_rules LIKE 'drug_icode'");
        return $chk2 && mysqli_num_rows($chk2) > 0;
    } catch (mysqli_sql_exception $e) {
        return false;
    } finally {
        mysqli_report($prev_mode);
    }
}

function ensure_drug_safety_rules_lab_condition_columns($con) {
    $chk = mysqli_query($con, "SHOW COLUMNS FROM drug_safety_rules LIKE 'lab_condition_type'");
    if ($chk && mysqli_num_rows($chk) > 0) {
        return true;
    }

    $prev_mode = mysqli_report(MYSQLI_REPORT_OFF);
    try {
        $altered = mysqli_query(
            $con,
            "ALTER TABLE drug_safety_rules
                ADD COLUMN lab_condition_type VARCHAR(32) NOT NULL DEFAULT 'range' AFTER lab_items_name,
                ADD COLUMN lab_condition_value VARCHAR(255) NULL DEFAULT NULL AFTER lab_condition_type"
        );
        if (!$altered) {
            return false;
        }
        $chk2 = mysqli_query($con, "SHOW COLUMNS FROM drug_safety_rules LIKE 'lab_condition_type'");
        return $chk2 && mysqli_num_rows($chk2) > 0;
    } catch (mysqli_sql_exception $e) {
        return false;
    } finally {
        mysqli_report($prev_mode);
    }
}

function ensure_drug_safety_rules_lab_items_columns($con) {
    $chk = mysqli_query($con, "SHOW COLUMNS FROM drug_safety_rules LIKE 'lab_items_code'");
    if ($chk && mysqli_num_rows($chk) > 0) {
        return true;
    }

    $prev_mode = mysqli_report(MYSQLI_REPORT_OFF);
    try {
        $altered = mysqli_query(
            $con,
            "ALTER TABLE drug_safety_rules
                ADD COLUMN lab_items_code VARCHAR(20) NULL DEFAULT NULL AFTER lab_type,
                ADD COLUMN lab_items_name VARCHAR(255) NULL DEFAULT NULL AFTER lab_items_code"
        );
        if (!$altered) {
            return false;
        }
        $chk2 = mysqli_query($con, "SHOW COLUMNS FROM drug_safety_rules LIKE 'lab_items_code'");
        return $chk2 && mysqli_num_rows($chk2) > 0;
    } catch (mysqli_sql_exception $e) {
        return false;
    } finally {
        mysqli_report($prev_mode);
    }
}

function ensure_drug_safety_rules_patient_extra_columns($con) {
    $chk = mysqli_query($con, "SHOW COLUMNS FROM drug_safety_rules LIKE 'use_age_condition'");
    if ($chk && mysqli_num_rows($chk) > 0) {
        return true;
    }

    $prev_mode = mysqli_report(MYSQLI_REPORT_OFF);
    try {
        $altered = mysqli_query(
            $con,
            "ALTER TABLE drug_safety_rules
                ADD COLUMN use_age_condition TINYINT(1) NOT NULL DEFAULT 0 AFTER dose_unit,
                ADD COLUMN age_min DECIMAL(10,2) NULL DEFAULT NULL AFTER use_age_condition,
                ADD COLUMN age_max DECIMAL(10,2) NULL DEFAULT NULL AFTER age_min,
                ADD COLUMN use_weight_condition TINYINT(1) NOT NULL DEFAULT 0 AFTER age_max,
                ADD COLUMN weight_min DECIMAL(10,2) NULL DEFAULT NULL AFTER use_weight_condition,
                ADD COLUMN weight_max DECIMAL(10,2) NULL DEFAULT NULL AFTER weight_min"
        );
        if (!$altered) {
            return false;
        }
        $chk2 = mysqli_query($con, "SHOW COLUMNS FROM drug_safety_rules LIKE 'use_age_condition'");
        return $chk2 && mysqli_num_rows($chk2) > 0;
    } catch (mysqli_sql_exception $e) {
        return false;
    } finally {
        mysqli_report($prev_mode);
    }
}

function ensure_di_dose_columns($con) {
    $chk = mysqli_query($con, "SHOW COLUMNS FROM monitor_drug_interaction_settings LIKE 'use_dose1_condition'");
    if ($chk && mysqli_num_rows($chk) > 0) {
        return true;
    }

    $prev_mode = mysqli_report(MYSQLI_REPORT_OFF);
    try {
        $altered = mysqli_query(
            $con,
            "ALTER TABLE monitor_drug_interaction_settings
                ADD COLUMN use_dose1_condition TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'เงื่อนไขขนาดยา 1' AFTER lookback_days,
                ADD COLUMN dose1_min DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'mg/วัน ยา 1' AFTER use_dose1_condition,
                ADD COLUMN dose1_max DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'mg/วัน ยา 1' AFTER dose1_min,
                ADD COLUMN use_dose2_condition TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'เงื่อนไขขนาดยา 2' AFTER dose1_max,
                ADD COLUMN dose2_min DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'mg/วัน ยา 2' AFTER use_dose2_condition,
                ADD COLUMN dose2_max DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'mg/วัน ยา 2' AFTER dose2_min"
        );
        if (!$altered) {
            return false;
        }
        $chk2 = mysqli_query($con, "SHOW COLUMNS FROM monitor_drug_interaction_settings LIKE 'use_dose1_condition'");
        return $chk2 && mysqli_num_rows($chk2) > 0;
    } catch (mysqli_sql_exception $e) {
        return false;
    } finally {
        mysqli_report($prev_mode);
    }
}

/**
 * ตรวจเงื่อนไข mg/วันรวม ตามกฎ ($which = 1|2) — อิงวิธีใช้ (strength × จำนวนเม็ด/วัน)
 * ตัวอย่าง simvastatin 10 mg: 1 เม็ด/วัน = 10 mg, 2 เม็ดวันละ 1 ครั้ง = 20 mg
 * - ตั้งช่วงต่ำสุด/สูงสุด → เปรียบเทียบ mg/วันรวมกับช่วงนั้นเท่านั้น
 * - ไม่ตั้งช่วง → ใช้ strength ฉลากยาในกฎ ±15%
 */
function di_dose_condition_matches(array $rule, array $order, $which = 1) {
    $which = (int)$which;
    $use_key = $which === 2 ? 'use_dose2_condition' : 'use_dose1_condition';
    $min_key = $which === 2 ? 'dose2_min' : 'dose1_min';
    $max_key = $which === 2 ? 'dose2_max' : 'dose1_max';
    $name_key = $which === 2 ? 'hosxp_drug_name' : 'drug_name';

    if (empty($rule[$use_key])) {
        return true;
    }

    if (array_key_exists('_daily_mg', $order)) {
        $mg = $order['_daily_mg'];
    } else {
        $parsed = hosxp_parse_drug_daily_dose_mg([
            'qty' => $order['qty'] ?? 0,
            'strength' => $order['strength'] ?? '',
            'units' => $order['units'] ?? '',
            'method' => $order['method'] ?? '',
        ]);
        $mg = $parsed['mg_dose'] ?? null;
    }
    if ($mg === null || $mg <= 0) {
        return false;
    }

    $has_min = isset($rule[$min_key]) && $rule[$min_key] !== '' && $rule[$min_key] !== null;
    $has_max = isset($rule[$max_key]) && $rule[$max_key] !== '' && $rule[$max_key] !== null;

    if ($has_min || $has_max) {
        $dose_min = $has_min ? (float)$rule[$min_key] : 0;
        $dose_max = $has_max ? (float)$rule[$max_key] : PHP_FLOAT_MAX;
        return $mg >= $dose_min && $mg <= $dose_max;
    }

    $label_strength = di_strength_mg_from_drug_name($rule[$name_key] ?? '');
    if ($label_strength !== null && $label_strength > 0) {
        $tolerance = max(1.0, $label_strength * 0.15);
        return abs($mg - $label_strength) <= $tolerance;
    }

    return true;
}

function di_format_daily_mg_display(array $order) {
    if (array_key_exists('_daily_mg', $order)) {
        $mg_val = $order['_daily_mg'];
        $note = trim((string)($order['_daily_mg_note'] ?? ''));
    } else {
        $parsed = hosxp_parse_drug_daily_dose_mg([
            'qty' => $order['qty'] ?? 0,
            'strength' => $order['strength'] ?? '',
            'units' => $order['units'] ?? '',
            'method' => $order['method'] ?? '',
        ]);
        $mg_val = $parsed['mg_dose'] ?? null;
        $note = trim((string)($parsed['mg_note'] ?? ''));
    }
    if ($mg_val === null || $mg_val <= 0) {
        return null;
    }
    $mg = round((float)$mg_val, 2);
    return $note !== '' ? "{$mg} mg/วัน ({$note})" : "{$mg} mg/วัน";
}

function hosxp_parse_drug_option($raw) {
    $parts = explode('|', $raw ?? '', 2);
    return [
        'icode' => trim($parts[0] ?? ''),
        'name' => trim($parts[1] ?? ''),
    ];
}

/** อันดับเวลาสั่งยา — ใช้เลือก order ล่าสุดเมื่อซ้อนกันวันเดียวกัน */
function di_order_rank($row) {
    $date = $row['rxdate'] ?? $row['vstdate'] ?? '1970-01-01';
    $time = trim((string)($row['rxtime'] ?? $row['vsttime'] ?? '00:00:00'));
    if (strlen($time) === 5) {
        $time .= ':00';
    }
    $ts = strtotime($date . ' ' . $time);
    if ($ts === false) {
        $ts = strtotime($date) ?: 0;
    }
    $item = (int)($row['item_no'] ?? 0);
    return ($ts * 1000) + $item;
}

function di_order_qty_valid($row) {
    return (float)($row['qty'] ?? 0) > 0;
}

/** คง order ล่าสุดต่อ HN + วันที่ + icode (กรณี IPD สั่งซ้อนหลายครั้ง/วัน) — ข้าม qty ≤ 0 */
function di_dedupe_latest_orders(array $rows) {
    $best = [];
    foreach ($rows as $row) {
        if (!di_order_qty_valid($row)) {
            continue;
        }
        $key = trim((string)($row['hn'] ?? '')) . '|' . ($row['vstdate'] ?? '') . '|' . trim((string)($row['icode'] ?? ''));
        if (!isset($best[$key]) || di_order_rank($row) >= di_order_rank($best[$key])) {
            $best[$key] = $row;
        }
    }
    return array_values($best);
}

function di_reindex_orders(array $rows) {
    $by_icode = [];
    $by_vn = [];
    $by_hn = [];
    foreach ($rows as $row) {
        $icode = trim((string)($row['icode'] ?? ''));
        $vn = (string)($row['vn'] ?? '');
        $hn = $row['hn'] ?? '';
        $by_icode[$icode][] = $row;
        $by_vn[$vn][] = $row;
        $by_hn[$hn][] = $row;
    }
    return [$by_icode, $by_vn, $by_hn];
}

/** คำนวณช่วงวันที่ query HOSxP — ไม่ขยาย +365 วันถ้าเป็น Visit เดียวกันทั้งหมด */
function di_compute_query_window($start_date, $end_date, array $rules) {
    $max_lookback = 0;
    $has_lookback = false;
    foreach ($rules as $rule) {
        if (($rule['check_mode'] ?? 'same_visit') === 'lookback') {
            $has_lookback = true;
            $max_lookback = max($max_lookback, max(1, (int)($rule['lookback_days'] ?? 7)));
        }
    }

    if (!$has_lookback) {
        return [
            'query_start' => $start_date,
            'query_end' => $end_date,
            'has_lookback' => false,
            'max_lookback' => 0,
        ];
    }

    $user_span = max(1, (int)round((strtotime($end_date) - strtotime($start_date)) / 86400) + 1);
    $coverage_pad = (int)min(90, max(21, (int)ceil($user_span * 4)));
    $back_days = $max_lookback + $coverage_pad;
    $query_start = date('Y-m-d', strtotime($start_date . " -{$back_days} days"));
    $query_end = date('Y-m-d', strtotime($end_date . " +{$coverage_pad} days"));

    return [
        'query_start' => $query_start,
        'query_end' => $query_end,
        'has_lookback' => true,
        'max_lookback' => $max_lookback,
        'coverage_pad' => $coverage_pad,
        'user_span' => $user_span,
    ];
}

function di_rules_need_dose_mg(array $rules) {
    foreach ($rules as $rule) {
        if (!empty($rule['use_dose1_condition']) || !empty($rule['use_dose2_condition'])) {
            return true;
        }
    }
    return false;
}

/** เตรียม coverage / mg ต่อ order ครั้งเดียว */
function di_prepare_orders(array $rows, $need_dose_mg = false) {
    $prepared = [];
    foreach ($rows as $row) {
        $method = trim((string)($row['method'] ?? ''));
        $units = trim((string)($row['units'] ?? ''));
        $qty = (float)($row['qty'] ?? 0);
        $vstdate = $row['vstdate'] ?? '';

        $row['_c'] = di_calculate_coverage_days($qty, $method, $units);
        $row['_end'] = di_period_end_date($vstdate, $row['_c']);
        $row['_daily'] = di_parse_daily_dose($method);

        if ($need_dose_mg) {
            $parsed = hosxp_parse_drug_daily_dose_mg([
                'qty' => $qty,
                'strength' => $row['strength'] ?? '',
                'units' => $units,
                'method' => $method,
            ]);
            $row['_daily_mg'] = $parsed['mg_dose'] ?? null;
            $row['_daily_mg_note'] = trim((string)($parsed['mg_note'] ?? ''));
        }

        $prepared[] = $row;
    }
    return $prepared;
}

/** index แบบจับคู่เร็ว — by icode, vn+icode, hn+icode */
function di_reindex_orders_fast(array $rows) {
    $by_icode = [];
    $by_vn_icode = [];
    $by_hn_icode = [];
    foreach ($rows as $row) {
        $icode = trim((string)($row['icode'] ?? ''));
        if ($icode === '') {
            continue;
        }
        $vn = trim((string)($row['vn'] ?? ''));
        $hn = trim((string)($row['hn'] ?? ''));

        $by_icode[$icode][] = $row;
        if ($vn !== '') {
            $by_vn_icode[$vn][$icode][] = $row;
        }
        if ($hn !== '') {
            $by_hn_icode[$hn][$icode][] = $row;
        }
    }
    return [$by_icode, $by_vn_icode, $by_hn_icode];
}

function di_rules_have_lookback(array $rules) {
    foreach ($rules as $rule) {
        if (($rule['check_mode'] ?? 'same_visit') === 'lookback') {
            return true;
        }
    }
    return false;
}

function di_build_hosxp_orders_sql($con_hosxp, array $icodes, $date_from, $date_to, $need_dose_mg, array $hn_filter = []) {
    $icode_sql = [];
    foreach ($icodes as $ic) {
        $ic = trim((string)$ic);
        if ($ic === '') {
            continue;
        }
        $icode_sql[] = "'" . mysqli_real_escape_string($con_hosxp, $ic) . "'";
    }
    if (empty($icode_sql)) {
        return '';
    }

    $start_esc = mysqli_real_escape_string($con_hosxp, $date_from);
    $end_esc = mysqli_real_escape_string($con_hosxp, $date_to);
    $method_expr = di_sql_method_expr('o');
    $strength_expr = hosxp_sql_tmt_strength('d', 'tm');
    $tmt_name_expr = hosxp_sql_tmt_drug_name('d', 'tm');
    $strength_col = "CAST(CONVERT($strength_expr USING utf8) AS CHAR) AS strength,";

    $hn_clause = '';
    if (!empty($hn_filter)) {
        $hn_sql = [];
        foreach ($hn_filter as $hn) {
            $hn = trim((string)$hn);
            if ($hn === '') {
                continue;
            }
            $hn_sql[] = "'" . mysqli_real_escape_string($con_hosxp, $hn) . "'";
        }
        if (!empty($hn_sql)) {
            $hn_clause = ' AND o.hn IN (' . implode(',', $hn_sql) . ')';
        }
    }

    return "
        SELECT o.hn, o.vn, o.vstdate, o.vsttime, o.rxdate, o.rxtime, o.item_no, o.icode, o.qty,
               CAST(CONVERT(CONCAT(IFNULL(p.pname, ''), IFNULL(p.fname, ''), ' ', IFNULL(p.lname, '')) USING utf8) AS CHAR) AS ptname,
               $method_expr AS method,
               $strength_col
               CAST(CONVERT(d.units USING utf8) AS CHAR) AS units,
               $tmt_name_expr AS drug_name
        FROM opitemrece o
        INNER JOIN patient p ON p.hn = o.hn
        INNER JOIN drugitems d ON d.icode = o.icode
        " . hosxp_sql_tmt_join('d', 'tm') . "
        " . di_sql_method_joins('o') . "
        WHERE o.icode IN (" . implode(',', $icode_sql) . ")
          AND o.qty > 0
          AND o.vstdate >= '$start_esc'
          AND o.vstdate <= '$end_esc'
          $hn_clause
        ORDER BY o.vstdate DESC, o.rxdate DESC, o.rxtime DESC, o.vsttime DESC, o.item_no DESC
    ";
}

function di_fetch_hosxp_orders($con_hosxp, array $icodes, $date_from, $date_to, $need_dose_mg, array $hn_filter = [], $limit = 12000) {
    $sql = di_build_hosxp_orders_sql($con_hosxp, $icodes, $date_from, $date_to, $need_dose_mg, $hn_filter);
    if ($sql === '') {
        return [[], 0, false, null];
    }

    $sql .= " LIMIT " . (int)$limit;
    $q = mysqli_query($con_hosxp, $sql);
    if (!$q) {
        return [[], 0, false, mysqli_error($con_hosxp)];
    }

    $rows = [];
    $raw_count = 0;
    while ($row = mysqli_fetch_assoc($q)) {
        if (!di_order_qty_valid($row)) {
            continue;
        }
        $row['method'] = trim((string)($row['method'] ?? ''));
        $raw_count++;
        $rows[] = $row;
    }

    return [$rows, $raw_count, ($raw_count >= $limit), null];
}

/** รายการยา 2 ที่อยู่ visit เดียวกับ o1 */
function di_same_visit_candidates(array $o1, $icode2, array $by_vn_icode, array $by_hn_icode) {
    $icode2 = trim((string)$icode2);
    $vn1 = trim((string)($o1['vn'] ?? ''));
    $vst1 = $o1['vstdate'] ?? '';
    $hn = trim((string)($o1['hn'] ?? ''));

    if ($vn1 !== '' && isset($by_vn_icode[$vn1][$icode2])) {
        return $by_vn_icode[$vn1][$icode2];
    }

    $out = [];
    foreach ($by_hn_icode[$hn][$icode2] ?? [] as $o2) {
        if (($o2['vstdate'] ?? '') === $vst1) {
            $out[] = $o2;
        }
    }
    return $out;
}

function di_build_alert($rule, $o1, $o2, $alert_level, $check_mode, $lookback_days, $ptname, $drug1_name, $drug2_name, $c1 = null, $c2 = null) {
    if ($c1 === null) {
        $c1 = $o1['_c'] ?? di_calculate_coverage_days($o1['qty'], $o1['method'] ?? '', $o1['units'] ?? '');
    }
    if ($c2 === null) {
        $c2 = $o2['_c'] ?? di_calculate_coverage_days($o2['qty'], $o2['method'] ?? '', $o2['units'] ?? '');
    }

    $check_lbl = $check_mode === 'lookback'
        ? di_build_lookback_label($lookback_days, $c1, $c2, $o1['vstdate'], $o2['vstdate'])
        : 'Visit เดียวกัน · ทับช่วงให้ยา ' . $c1 . '/' . $c2 . ' วัน';

    $fmtDate = function ($d) {
        return !empty($d) ? date('d/m/Y', strtotime($d)) : '';
    };

    return [
        'rule_id' => (int)$rule['id'],
        'hn' => $o1['hn'],
        'ptname' => $ptname,
        'vn' => trim((string)($o1['vn'] ?? '')),
        'vstdate' => $fmtDate($o1['vstdate'] ?? ''),
        'drug1_name' => di_format_drug_display_name($drug1_name, $o1['strength'] ?? ''),
        'drug2_name' => di_format_drug_display_name($drug2_name, $o2['strength'] ?? ''),
        'drug1_date' => $fmtDate($o1['vstdate'] ?? ''),
        'drug2_date' => $fmtDate($o2['vstdate'] ?? ''),
        'drug1_time' => di_format_time($o1['vsttime'] ?? ''),
        'drug2_time' => di_format_time($o2['vsttime'] ?? ''),
        'drug1_vn' => trim((string)($o1['vn'] ?? '')),
        'drug2_vn' => trim((string)($o2['vn'] ?? '')),
        'dose1_qty' => $o1['qty'],
        'dose2_qty' => $o2['qty'],
        'drug1_method' => trim((string)($o1['method'] ?? '')),
        'drug2_method' => trim((string)($o2['method'] ?? '')),
        'drug1_daily_dose' => $o1['_daily'] ?? di_parse_daily_dose($o1['method'] ?? ''),
        'drug2_daily_dose' => $o2['_daily'] ?? di_parse_daily_dose($o2['method'] ?? ''),
        'drug1_daily_mg' => di_format_daily_mg_display($o1),
        'drug2_daily_mg' => di_format_daily_mg_display($o2),
        'drug1_coverage_days' => $c1,
        'drug2_coverage_days' => $c2,
        'drug1_coverage_end' => $fmtDate($o1['_end'] ?? di_period_end_date($o1['vstdate'], $c1)),
        'drug2_coverage_end' => $fmtDate($o2['_end'] ?? di_period_end_date($o2['vstdate'], $c2)),
        'alert_level' => $alert_level,
        'interaction_type' => $rule['interaction_type'],
        'detail_text' => $rule['detail_text'] ?? '',
        'check_mode_label' => $check_lbl,
    ];
}

function di_append_alert($alert_data, $alert_level, &$block_alerts, &$warning_alerts, &$block_count, &$warning_count) {
    if ($alert_level === 'hard') {
        $block_alerts[] = $alert_data;
        $block_count++;
    } else {
        $warning_alerts[] = $alert_data;
        $warning_count++;
    }
}

function di_format_alert_summary_line(array $alert) {
    $d1 = trim((string)($alert['drug1_name'] ?? ''));
    $d2 = trim((string)($alert['drug2_name'] ?? ''));
    $pair = ($d1 !== '' && $d2 !== '') ? "{$d1} + {$d2}" : ($d1 !== '' ? $d1 : ($d2 !== '' ? $d2 : 'คู่ยา'));
    $detail = trim((string)($alert['detail_text'] ?? ''));
    if ($detail === '') {
        $detail = trim((string)($alert['interaction_type'] ?? ''));
    }
    if ($detail === '') {
        $detail = 'พบปฏิกิริยาระหว่างยา';
    }
    $line = $pair . ' — ' . $detail;
    $meta = trim((string)($alert['check_mode_label'] ?? ''));
    if ($meta !== '') {
        $line .= ' (' . $meta . ')';
    }
    return $line;
}

/**
 * ประเมิน Drug Interaction สำหรับผู้ป่วย 1 visit (logic เดียวกับ Monitor DI)
 */
function di_evaluate_visit_alerts($con, $hos, $hn, $vstdate, $vn = '', $ptname = '', array $icodes = null) {
    ensure_di_dose_columns($con);
    $hn = trim((string)$hn);
    $vstdate = trim((string)$vstdate);
    if ($hn === '' || $vstdate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $vstdate)) {
        return ['alerts' => [], 'has_hard' => false];
    }

    if ($icodes === null) {
        $icodes = [];
        $hn_esc = mysqli_real_escape_string($hos, $hn);
        $date_esc = mysqli_real_escape_string($hos, $vstdate);
        $vn_clause = '';
        if (trim((string)$vn) !== '') {
            $vn_clause = "AND o.vn = '" . mysqli_real_escape_string($hos, $vn) . "'";
        }
        $q_ic = mysqli_query($hos, "SELECT DISTINCT o.icode FROM opitemrece o
            WHERE o.hn = '$hn_esc' AND o.vstdate = '$date_esc' AND o.qty > 0 $vn_clause");
        if ($q_ic) {
            while ($r = mysqli_fetch_assoc($q_ic)) {
                $c = trim($r['icode'] ?? '');
                if ($c !== '') {
                    $icodes[] = $c;
                }
            }
        }
    }

    if (empty($icodes)) {
        return ['alerts' => [], 'has_hard' => false];
    }

    $selected = array_flip($icodes);
    $q_rules = mysqli_query($con, "SELECT * FROM monitor_drug_interaction_settings WHERE active_status = 1");
    $rules = [];
    if ($q_rules) {
        while ($row = mysqli_fetch_assoc($q_rules)) {
            $d1 = trim($row['drug_icode'] ?? '');
            $d2 = trim($row['hosxp_icode'] ?? '');
            if ($d1 === '' || $d2 === '' || !isset($selected[$d1])) {
                continue;
            }
            $rules[] = $row;
        }
    }
    if (empty($rules)) {
        return ['alerts' => [], 'has_hard' => false];
    }

    $icode_set = [];
    $max_lb = 0;
    foreach ($rules as $rule) {
        $icode_set[trim($rule['drug_icode'])] = true;
        $icode_set[trim($rule['hosxp_icode'])] = true;
        if (($rule['check_mode'] ?? 'same_visit') === 'lookback') {
            $max_lb = max($max_lb, max(1, (int)($rule['lookback_days'] ?? 30)));
        }
    }
    $all_icodes = array_keys(array_filter($icode_set, function ($k) {
        return $k !== '';
    }));
    $need_dose_mg = di_rules_need_dose_mg($rules);
    $window = di_compute_query_window($vstdate, $vstdate, $rules);
    $query_start = $window['query_start'];
    $query_end = date('Y-m-d', strtotime($vstdate . ' + 30 days'));

    [$raw_rows] = di_fetch_hosxp_orders($hos, $all_icodes, $query_start, $query_end, $need_dose_mg, [$hn], 5000);
    $orders = di_dedupe_latest_orders($raw_rows);
    $prepared_rows = di_prepare_orders($orders, $need_dose_mg);
    [$by_icode, $by_vn_icode, $by_hn_icode] = di_reindex_orders_fast($prepared_rows);

    $drug_names = [];
    foreach ($prepared_rows as $row) {
        $ic = trim($row['icode'] ?? '');
        if ($ic !== '' && !isset($drug_names[$ic])) {
            $drug_names[$ic] = trim($row['drug_name'] ?? '') ?: $ic;
        }
    }

    $alerts = [];
    $seen = [];
    $has_hard = false;

    foreach ($rules as $rule) {
        $icode1 = trim($rule['drug_icode']);
        $icode2 = trim($rule['hosxp_icode']);
        $check_mode = (($rule['check_mode'] ?? 'same_visit') === 'lookback') ? 'lookback' : 'same_visit';
        $lookback_days = max(1, (int)($rule['lookback_days'] ?? 30));
        $alert_level = (!empty($rule['alert_level']) && $rule['alert_level'] === 'hard') ? 'hard' : 'soft';
        $drug1_name = $drug_names[$icode1] ?? ($rule['drug_name'] ?? $icode1);
        $drug2_name = $drug_names[$icode2] ?? ($rule['hosxp_drug_name'] ?? $icode2);

        foreach ($by_icode[$icode1] ?? [] as $o1) {
            if (!di_order_qty_valid($o1) || ($o1['vstdate'] ?? '') !== $vstdate) {
                continue;
            }
            $c1 = $o1['_c'];

            if ($check_mode === 'same_visit') {
                foreach (di_same_visit_candidates($o1, $icode2, $by_vn_icode, $by_hn_icode) as $o2) {
                    if (!di_order_qty_valid($o2)) {
                        continue;
                    }
                    $c2 = $o2['_c'];
                    if (!di_periods_overlap($vstdate, $c1, $o2['vstdate'], $c2)) {
                        continue;
                    }
                    if (!di_dose_condition_matches($rule, $o1, 1) || !di_dose_condition_matches($rule, $o2, 2)) {
                        continue;
                    }
                    $key = $rule['id'] . '|' . $o1['hn'] . '|' . $vstdate . '|' . ($o2['vstdate'] ?? '') . '|' . ($o2['vn'] ?? '');
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $alert_data = di_build_alert(
                        $rule, $o1, $o2, $alert_level, $check_mode, $lookback_days,
                        $ptname, $drug1_name, $drug2_name, $c1, $c2
                    );
                    $alerts[] = $alert_data;
                    if ($alert_level === 'hard') {
                        $has_hard = true;
                    }
                }
            } else {
                $lb_start = date('Y-m-d', strtotime($vstdate . " -{$lookback_days} days"));
                foreach ($by_hn_icode[$hn][$icode2] ?? [] as $o2) {
                    if (!di_order_qty_valid($o2)) {
                        continue;
                    }
                    $c2 = $o2['_c'];
                    if (!di_periods_overlap($vstdate, $c1, $o2['vstdate'], $c2)) {
                        continue;
                    }
                    if ($o2['_end'] < $lb_start) {
                        continue;
                    }
                    if (!di_dose_condition_matches($rule, $o1, 1) || !di_dose_condition_matches($rule, $o2, 2)) {
                        continue;
                    }
                    $key = $rule['id'] . '|' . $hn . '|' . $vstdate . '|' . ($o2['vstdate'] ?? '') . '|' . $c1 . '|' . $c2;
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $alert_data = di_build_alert(
                        $rule, $o1, $o2, $alert_level, $check_mode, $lookback_days,
                        $ptname, $drug1_name, $drug2_name, $c1, $c2
                    );
                    $alerts[] = $alert_data;
                    if ($alert_level === 'hard') {
                        $has_hard = true;
                    }
                }
            }
        }
    }

    return ['alerts' => $alerts, 'has_hard' => $has_hard];
}

function di_group_alerts_by_hn($alerts) {
    $grouped = [];
    foreach ($alerts as $a) {
        $hn = $a['hn'];
        if (!isset($grouped[$hn])) {
            $grouped[$hn] = ['hn' => $hn, 'ptname' => $a['ptname'], 'visits' => []];
        }
        $grouped[$hn]['visits'][] = $a;
    }
    return array_values($grouped);
}

/** ตรวจว่าข้อความมีตัวเลขพร้อมหน่วยความแรง/ปริมาณแล้ว */
function di_text_has_strength_info($text) {
    $text = trim((string)$text);
    if ($text === '') {
        return false;
    }
    if (preg_match('/\d+(?:\.\d+)?\s*(?:mg|mcg|µg|ug|g|gm|gram|grams|ml|l|unit|units|iu)\b/i', $text)) {
        return true;
    }
    return (bool)preg_match('/\d+(?:\.\d+)?(?:mg|mcg|g|gm|GM|ml|ML|unit|iu)\b/i', $text);
}

/** ชื่อยาในหน้ารายงาน — ต่อท้าย strength (TMT) ถ้าชื่อยังไม่มีหน่วยความแรง */
function di_format_drug_display_name($name, $strength = '') {
    $name = trim((string)$name);
    $strength = trim((string)$strength);
    if ($name === '') {
        return $strength !== '' ? $strength : '-';
    }
    if (di_text_has_strength_info($name)) {
        return $name;
    }
    if ($strength === '') {
        return $name;
    }
    if (di_text_has_strength_info($strength)) {
        return $name . ' ' . $strength;
    }
    if (preg_match('/^\d+(?:\.\d+)?$/', $strength)) {
        return $name . ' ' . $strength . ' mg';
    }
    return $name . ' ' . $strength;
}

/** ดึง strength (mg) จากชื่อยา เช่น simvastatin 10 mg */
function di_strength_mg_from_drug_name($name) {
    $name = trim((string)$name);
    if ($name === '') {
        return null;
    }
    if (preg_match('/(\d+(?:\.\d+)?)\s*mg\b/i', $name, $m)) {
        return (float)$m[1];
    }
    return null;
}

function di_format_time($t) {
    $t = trim((string)$t);
    if ($t === '') {
        return '';
    }
    return strlen($t) >= 5 ? substr($t, 0, 5) : $t;
}

/** แปลงเวลา HOSxP (12:57, 12:57:00, 1257, 125700) เป็น HH:MM:SS */
function monitor_lab_normalize_hosxp_time($time) {
    $time = trim((string)$time);
    if ($time === '') {
        return '00:00:00';
    }
    if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $time)) {
        return strlen($time) === 5 ? $time . ':00' : $time;
    }
    $digits = preg_replace('/\D/', '', $time);
    if (strlen($digits) >= 4) {
        $digits = str_pad(substr($digits, 0, 6), 6, '0', STR_PAD_RIGHT);
        return substr($digits, 0, 2) . ':' . substr($digits, 2, 2) . ':' . substr($digits, 4, 2);
    }
    return '00:00:00';
}

function monitor_lab_datetime_to_ts($date, $time = '') {
    $date = trim((string)$date);
    if ($date === '' || $date === '-' || $date === '0000-00-00') {
        return null;
    }
    $time = monitor_lab_normalize_hosxp_time($time);
    $ts = strtotime($date . ' ' . $time);
    return $ts ?: null;
}

/** วันที่/เวลาสั่งยา — ใช้ rxdate/rxtime ก่อน vstdate/vsttime */
function monitor_lab_order_datetime(array $order_row) {
    $date = trim((string)($order_row['rxdate'] ?? ''));
    if ($date === '' || $date === '0000-00-00') {
        $date = trim((string)($order_row['vstdate'] ?? ''));
    }
    $time = trim((string)($order_row['rxtime'] ?? ''));
    if ($time === '') {
        $time = trim((string)($order_row['vsttime'] ?? ''));
    }
    return ['date' => $date, 'time' => $time];
}

/** แยกรหัส Lab หลายตัว — คั่นด้วย comma หรือ | (เช่น Potassium 132,1100) */
function monitor_lab_parse_codes($code) {
    if (is_array($code)) {
        $parts = $code;
    } else {
        $code = trim((string)$code);
        if ($code === '') {
            return [];
        }
        $parts = preg_split('/[,|;]+/', $code);
    }
    $out = [];
    foreach ($parts as $p) {
        $p = trim((string)$p);
        if ($p !== '') {
            $out[] = $p;
        }
    }
    return array_values(array_unique($out));
}

/** สร้าง IN (...) สำหรับ lab_items_code หลายรหัส */
function monitor_lab_codes_sql_in($hos, $code) {
    $codes = monitor_lab_parse_codes($code);
    if (empty($codes)) {
        return "''";
    }
    $escaped = [];
    foreach ($codes as $c) {
        $escaped[] = "'" . mysqli_real_escape_string($hos, $c) . "'";
    }
    return implode(',', $escaped);
}

/** วันที่/เวลา Lab ที่ใช้เทียบ — lab_head.order_date + order_time */
function monitor_lab_lab_row_datetime(array $lab_head_row) {
    return [
        'date' => trim((string)($lab_head_row['order_date'] ?? '')),
        'time' => trim((string)($lab_head_row['order_time'] ?? '')),
    ];
}

/** Lab ต้องมีเวลา (order_date + order_time) ก่อนเวลาสั่งยา (rxdate/rxtime) */
function monitor_lab_lab_before_order(array $lab_row, $order_date, $order_time = '') {
    $order_ts = monitor_lab_datetime_to_ts($order_date, $order_time);
    $lab_ts = monitor_lab_datetime_to_ts($lab_row['date'] ?? '', $lab_row['time'] ?? '');
    if ($order_ts !== null && $lab_ts !== null) {
        return $lab_ts < $order_ts;
    }
    if ($order_ts === null) {
        return ($lab_row['date'] ?? '') <= $order_date;
    }
    return ($lab_row['date'] ?? '') < $order_date;
}

/** HN ใน HOSxP อาจเป็น 000443606 หรือ 443606 — ใช้ทั้งสองแบบใน SQL */
function monitor_lab_hn_sql_in($hos, $hn) {
    $trimmed = ltrim((string)$hn, '0');
    if ($trimmed === '') {
        $trimmed = '0';
    }
    $variants = [
        mysqli_real_escape_string($hos, str_pad($trimmed, 9, '0', STR_PAD_LEFT)),
        mysqli_real_escape_string($hos, $trimmed),
    ];
    if ($hn !== $trimmed && $hn !== str_pad($trimmed, 9, '0', STR_PAD_LEFT)) {
        $variants[] = mysqli_real_escape_string($hos, (string)$hn);
    }
    $variants = array_values(array_unique(array_filter($variants)));
    return "'" . implode("','", $variants) . "'";
}

/**
 * ดึงผล Lab ล่าสุดก่อนเวลาสั่งยา:
 * lh.order_date <= vstdate, รองรับหลาย lab_items_code, ORDER BY lab_order_number DESC
 * เทียบเวลาและแสดงผลด้วย order_date + order_time
 */
function monitor_lab_fetch_latest_before_order($hos, $hn, $code, $order_date, $order_time = '', $min_date = null, $lab_cutoff_date = null) {
    $empty = ['value' => 0, 'raw' => '', 'date' => '-', 'time' => ''];
    $codes = monitor_lab_parse_codes($code);
    $order_date = trim((string)$order_date);
    $lab_cutoff_date = trim((string)($lab_cutoff_date ?? $order_date));
    if (empty($codes) || $order_date === '' || $order_date === '0000-00-00') {
        return $empty;
    }
    if ($lab_cutoff_date === '' || $lab_cutoff_date === '0000-00-00') {
        $lab_cutoff_date = $order_date;
    }

    $hn_in = monitor_lab_hn_sql_in($hos, $hn);
    $code_in = monitor_lab_codes_sql_in($hos, $codes);
    $cutoff_esc = mysqli_real_escape_string($hos, $lab_cutoff_date);
    $min_clause = '';
    if ($min_date !== null && $min_date !== '') {
        $min_clause = "AND lh.order_date >= '" . mysqli_real_escape_string($hos, $min_date) . "'";
    }

    $sql = "SELECT lh.lab_order_number,
               lh.order_date, lh.order_time,
               lo.lab_order_result, lo.lab_items_code
        FROM lab_head lh
        INNER JOIN lab_order lo ON lh.lab_order_number = lo.lab_order_number
        WHERE lh.hn IN ($hn_in)
          AND lo.lab_items_code IN ($code_in)
          AND lo.lab_order_result IS NOT NULL
          AND TRIM(lo.lab_order_result) != ''
          AND lh.order_date <= '$cutoff_esc'
          $min_clause
        ORDER BY lo.lab_order_number DESC
        LIMIT 120";

    $q = mysqli_query($hos, $sql);
    if (!$q) {
        return $empty;
    }

    while ($r = mysqli_fetch_assoc($q)) {
        $dt = monitor_lab_lab_row_datetime($r);
        $row = [
            'date' => $dt['date'],
            'time' => $dt['time'],
            'raw' => trim((string)$r['lab_order_result']),
            'value' => (float)preg_replace('/[^0-9.-]/', '', str_replace(',', '', $r['lab_order_result'])),
        ];
        if ($row['date'] === '' || $row['date'] === '0000-00-00') {
            continue;
        }
        if (!monitor_lab_lab_before_order($row, $order_date, $order_time)) {
            continue;
        }
        return [
            'value' => $row['value'],
            'raw' => $row['raw'],
            'date' => date('d/m/Y', strtotime($row['date'])),
            'time' => di_format_time(monitor_lab_normalize_hosxp_time($row['time'])),
        ];
    }

    return $empty;
}

/** Memo ต่อ request — ลด query ซ้ำเมื่อหลายยาใน visit เดียวกัน */
function monitor_lab_get_lab_for_order($hos, array &$memo, $hn, $code, $order_date, $order_time = '', $min_date = null, $lab_cutoff_date = null) {
    $cutoff = $lab_cutoff_date ?? $order_date;
    $code_key = implode(',', monitor_lab_parse_codes($code));
    $memo_key = ltrim((string)$hn, '0') . '|' . $code_key . '|' . trim((string)$order_date)
        . '|' . monitor_lab_normalize_hosxp_time($order_time) . '|' . trim((string)$cutoff);
    if (!isset($memo[$memo_key])) {
        $memo[$memo_key] = monitor_lab_fetch_latest_before_order($hos, $hn, $code, $order_date, $order_time, $min_date, $cutoff);
    }
    return $memo[$memo_key];
}

/** ดึงผล Lab ล่าสุด (timestamp สูงสุด) ที่ยังอยู่ก่อนเวลาสั่งยา — จาก cache (legacy) */
function monitor_lab_get_lab_from_cache(array $cache, $hn, $code, $order_date, $order_time = '') {
    $hn_key = ltrim((string)$hn, '0');
    if ($code === '' || $code === null || empty($cache[$hn_key][$code])) {
        return ['value' => 0, 'raw' => '', 'date' => '-', 'time' => ''];
    }
    $best = null;
    $best_ts = null;
    foreach ($cache[$hn_key][$code] as $row) {
        if (!monitor_lab_lab_before_order($row, $order_date, $order_time)) {
            continue;
        }
        $lab_ts = monitor_lab_datetime_to_ts($row['date'] ?? '', $row['time'] ?? '');
        if ($lab_ts === null) {
            continue;
        }
        if ($best_ts === null || $lab_ts > $best_ts) {
            $best_ts = $lab_ts;
            $best = $row;
        }
    }
    if ($best === null) {
        return ['value' => 0, 'raw' => '', 'date' => '-', 'time' => ''];
    }
    return [
        'value' => $best['value'],
        'raw' => $best['raw'] ?? '',
        'date' => $best['date'] ? date('d/m/Y', strtotime($best['date'])) : '-',
        'time' => di_format_time(monitor_lab_normalize_hosxp_time($best['time'] ?? '')),
    ];
}

/**
 * วิธีใช้ยา — ตามรูปแบบ HOSxP มาตรฐาน: sp_use → opi_dispense → drugusage
 * @see ตัวอย่าง query รพ. (opitemrece + opi_dispense + drugusage + sp_use)
 */
function di_sql_method_expr($o = 'o', $du = 'du', $sp = 'sp', $od = 'od') {
    $inner = "IF($o.sp_use <> '' AND $o.sp_use IS NOT NULL,
        CONCAT(IFNULL($sp.name1,''),' ',IFNULL($sp.name2,''),' ',IFNULL($sp.name3,'')),
        IF($od.usage_line1 IS NOT NULL AND $od.usage_line1 <> '',
            CONCAT(IFNULL($od.usage_line1,''),' ',IFNULL($od.usage_line2,''),' ',IFNULL($od.usage_line3,'')),
            CONCAT(IFNULL($du.name1,''),' ',IFNULL($du.name2,''),' ',IFNULL($du.name3,''))
        )
    )";
    return "CAST(CONVERT($inner USING utf8) AS CHAR)";
}

function di_sql_method_joins($o = 'o', $du = 'du', $sp = 'sp', $od = 'od') {
    return "LEFT JOIN drugusage $du ON $du.drugusage = $o.drugusage
            LEFT JOIN opi_dispense $od ON $od.hos_guid = $o.hos_guid
            LEFT JOIN sp_use $sp ON $sp.sp_use = $o.sp_use";
}

/** จำนวนต่อครั้งจากวิธีใช้ */
function hosxp_parse_per_time_dose($method) {
    $m = trim((string)$method);
    if ($m === '') {
        return null;
    }
    if (preg_match('/(\d+(?:\.\d+)?)\s*เม็ด\s*ครึ่ง/ui', $m, $hm)) {
        return (float)$hm[1] + 0.5;
    }
    if (preg_match('/เม็ด\s*ครึ่ง|ครึ่ง\s*เม็ด/ui', $m)) {
        return 0.5;
    }
    if (preg_match('/(?:ครั้งละ|รับประทานครั้งละ|ทานครั้งละ|ใช้ครั้งละ)?\s*(\d+(?:\.\d+)?)\s*เม็ด/ui', $m, $pm)) {
        return (float)$pm[1];
    }
    if (preg_match('/(\d+(?:\.\d+)?)\s*(?:cap|capsule|tab|tablet)/i', $m, $cm)) {
        return (float)$cm[1];
    }
    return null;
}

/** จำนวนครั้งต่อวันจากวิธีใช้ (วันละ N ครั้ง / bid / เช้า-เย็น) */
function hosxp_parse_times_per_day($method) {
    $m = trim((string)$method);
    if ($m === '') {
        return null;
    }
    if (preg_match('/วันละ\s*(\d+(?:\.\d+)?)\s*ครั้ง/ui', $m, $tp)) {
        return (float)$tp[1];
    }
    if (preg_match('/\b(qid|q\.i\.d\.)/i', $m)) {
        return 4.0;
    }
    if (preg_match('/\b(tid|t\.i\.d\.)/i', $m)) {
        return 3.0;
    }
    if (preg_match('/\b(bid|b\.i\.d\.)/i', $m)) {
        return 2.0;
    }
    if (preg_match('/\b(od|qd|q\.d\.|hs|h\.s\.)\b/i', $m)) {
        return 1.0;
    }
    $meal_count = 0;
    if (preg_match('/เช้า/ui', $m)) {
        $meal_count++;
    }
    if (preg_match('/เที่ยง/ui', $m)) {
        $meal_count++;
    }
    if (preg_match('/เย็น/ui', $m)) {
        $meal_count++;
    }
    if (preg_match('/ก่อนนอน/ui', $m)) {
        $meal_count++;
    }
    return $meal_count > 0 ? (float)$meal_count : null;
}

/**
 * รวมจำนวนเม็ด/วันเมื่อแต่ละมื้อต่างกัน
 * เช่น "2 เม็ด หลังอาหารเช้า และ 1 เม็ด หลังอาหารเย็น" → 3 เม็ด/วัน
 */
function hosxp_parse_daily_units_from_usage($method) {
    $m = trim((string)$method);
    if ($m === '') {
        return null;
    }

    $meals = ['เช้า', 'เที่ยง', 'เย็น', 'ก่อนนอน'];
    $meal_hits = 0;
    foreach ($meals as $meal) {
        if (preg_match('/' . preg_quote($meal, '/') . '/ui', $m)) {
            $meal_hits++;
        }
    }

    if ($meal_hits > 0) {
        $clauses = preg_split('/\s*(?:และ|,)\s*/u', $m);
        if (count($clauses) > 1) {
            $total = 0.0;
            $parsed = 0;
            foreach ($clauses as $clause) {
                $clause = trim($clause);
                if ($clause === '') {
                    continue;
                }
                $has_meal = false;
                foreach ($meals as $meal) {
                    if (preg_match('/' . preg_quote($meal, '/') . '/ui', $clause)) {
                        $has_meal = true;
                        break;
                    }
                }
                if (!$has_meal) {
                    continue;
                }
                $per = hosxp_parse_per_time_dose($clause);
                if ($per !== null) {
                    $total += $per;
                    $parsed++;
                }
            }
            if ($parsed > 0 && $total > 0) {
                return $total;
            }
        }

        $uniform = hosxp_parse_per_time_dose($m);
        if ($uniform !== null && $meal_hits > 0) {
            return max(0.01, $uniform * $meal_hits);
        }
    }

    $per_time = hosxp_parse_per_time_dose($m);
    $times_per_day = hosxp_parse_times_per_day($m);
    if ($per_time !== null && $times_per_day !== null) {
        return max(0.01, $per_time * $times_per_day);
    }
    if ($per_time !== null) {
        return max(0.01, $per_time);
    }
    return null;
}

/** ปริมาณยาต่อวันจากวิธีใช้ (เช่น 1 เม็ด วันละ 1 ครั้ง → 1) */
function di_parse_daily_dose($method) {
    $parsed = hosxp_parse_daily_units_from_usage($method);
    return $parsed !== null ? $parsed : null;
}

/** จำนวนวันที่ยาครอบคลุมจาก qty ÷ ปริมาณต่อวัน */
function di_calculate_coverage_days($qty, $method, $units = '') {
    $q = (float)$qty;
    if ($q <= 0) {
        return 1;
    }

    $daily = di_parse_daily_dose($method);
    if ($daily !== null && $daily > 0) {
        return max(1, (int)ceil($q / $daily));
    }

    return max(1, (int)ceil($q));
}

function di_period_end_date($start_date, $coverage_days) {
    $days = max(1, (int)$coverage_days);
    if ($days <= 1) {
        return $start_date;
    }
    $ts = strtotime($start_date . ' + ' . ($days - 1) . ' days');
    return $ts ? date('Y-m-d', $ts) : $start_date;
}

/** ช่วงวันให้ยาทับซ้อนกันหรือไม่ */
function di_periods_overlap($start1, $days1, $start2, $days2) {
    $end1 = di_period_end_date($start1, $days1);
    $end2 = di_period_end_date($start2, $days2);
    return $start1 <= $end2 && $start2 <= $end1;
}

function di_build_lookback_label($lookback_days, $c1, $c2, $d1, $d2) {
    $fmt = function ($d) {
        $ts = strtotime($d);
        return $ts ? date('d/m/Y', $ts) : $d;
    };
    $end1 = di_period_end_date($d1, $c1);
    $end2 = di_period_end_date($d2, $c2);
    return "ทับช่วงให้ยา · ยา1 {$c1} วัน (ถึง {$fmt($end1)}) · ยา2 {$c2} วัน (ถึง {$fmt($end2)}) · ค้นหาย้อนหลังสูงสุด {$lookback_days} วัน";
}

/** แปลง strength/units เป็น mg ต่อหน่วย — ตรงกับ smart_drug.php parseStrengthMg */
function hosxp_parse_strength_mg($strength, $units = '') {
    $combined = strtolower(trim((string)$strength . ' ' . (string)$units));
    if ($combined === '') {
        return null;
    }
    if (!preg_match('/(\d+(?:\.\d+)?)/', $combined, $num_match)) {
        return null;
    }
    $num = (float)$num_match[1];
    if (preg_match('/\bmcg\b|µg|μg|\bug\b|ไมโครกรัม/ui', $combined)) {
        return $num / 1000;
    }
    if (preg_match('/\bmg\b|มิลลิกรัม|มก\./ui', $combined)) {
        return $num;
    }
    if (preg_match('/\b(?:g|gm|gram|กร\.?|กรัม)\b/ui', $combined)) {
        return $num * 1000;
    }
    return $num;
}

/** ดึง mg จากข้อความวิธีใช้ — ตรงกับ smart_drug.php extractMgFromText */
function hosxp_extract_mg_from_usage($text) {
    $text = trim((string)$text);
    if ($text === '') {
        return null;
    }
    if (preg_match('/(\d+(?:\.\d+)?)\s*(?:mcg|µg|μg|ug|ไมโครกรัม)/iu', $text, $mc)) {
        return (float)$mc[1] / 1000;
    }
    if (preg_match('/(\d+(?:\.\d+)?)\s*mg(?!cg)/i', $text, $mg)) {
        return (float)$mg[1];
    }
    if (preg_match('/(\d+(?:\.\d+)?)\s*(?:g|gm|gram|กร\.?|กรัม)(?![a-zA-Z])/iu', $text, $g)) {
        return (float)$g[1] * 1000;
    }
    return null;
}

/** จำนวนต่อครั้งจากวิธีใช้ — ตรงกับ smart_drug.php parseQtyFromUsage */
function hosxp_parse_qty_from_usage($text) {
    $text = trim((string)$text);
    if ($text === '') {
        return null;
    }
    if (preg_match('/(\d+(?:\.\d+)?)\s*เม็ด\s*ครึ่ง/ui', $text, $half)) {
        return ['qty' => (float)$half[1] + 0.5, 'unit' => 'เม็ด'];
    }
    if (preg_match('/เม็ด\s*ครึ่ง|ครึ่ง\s*เม็ด/ui', $text)) {
        return ['qty' => 0.5, 'unit' => 'เม็ด'];
    }
    if (preg_match('/(?:ครั้งละ|รับประทานครั้งละ|ทานครั้งละ|ใช้ครั้งละ)?\s*(\d+(?:\.\d+)?)\s*เม็ด/ui', $text, $pill)) {
        return ['qty' => (float)$pill[1], 'unit' => 'เม็ด'];
    }
    if (preg_match('/(\d+(?:\.\d+)?)\s*(?:cap|capsule|tab|tablet)/i', $text, $cap)) {
        return ['qty' => (float)$cap[1], 'unit' => 'เม็ด'];
    }
    if (preg_match('/(\d+(?:\.\d+)?)\s*ช้อน(?:โต๊ะ|tea)?/i', $text, $spoon)) {
        return ['qty' => (float)$spoon[1], 'unit' => 'ช้อน'];
    }
    if (preg_match('/(\d+(?:\.\d+)?)\s*(?:ซอง|sachet)/i', $text, $sachet)) {
        return ['qty' => (float)$sachet[1], 'unit' => 'ซอง'];
    }
    return null;
}

/**
 * คำนวณขนาดยา (mg) จาก order — ตรงกับ smart_drug.php parseDrugDose
 * @param array $item keys: qty, strength, units, method
 */
function hosxp_parse_drug_dose_mg(array $item) {
    $usage = trim((string)($item['method'] ?? ''));
    $order_qty = (float)($item['qty'] ?? 0);
    $strength_mg = hosxp_parse_strength_mg($item['strength'] ?? '', $item['units'] ?? '');

    $pill_qty = null;
    $pill_unit = 'หน่วย';
    $mg = hosxp_extract_mg_from_usage($usage);
    $mg_note = ($mg !== null) ? 'จากวิธีใช้' : '';

    $from_usage = hosxp_parse_qty_from_usage($usage);
    if ($from_usage !== null) {
        $pill_qty = $from_usage['qty'];
        $pill_unit = $from_usage['unit'];
    } elseif ($order_qty > 0) {
        $pill_qty = $order_qty;
        $u = trim((string)($item['units'] ?? ''));
        if (preg_match('/เม็ด|tab|cap/i', $u)) {
            $pill_unit = 'เม็ด';
        } elseif ($u !== '') {
            $pill_unit = $u;
        }
    }

    if ($mg === null && $strength_mg !== null && $pill_qty !== null) {
        $mg = $strength_mg * $pill_qty;
        $mg_note = 'strength × จำนวน';
    } elseif ($mg === null && $strength_mg !== null && $order_qty > 0) {
        $mg = $strength_mg * $order_qty;
        $mg_note = 'strength × qty';
    }

    return [
        'pill_qty' => $pill_qty,
        'pill_unit' => $pill_unit,
        'mg_dose' => $mg,
        'mg_note' => $mg_note,
    ];
}

/**
 * คำนวณขนาดยา (mg) รวมต่อวัน — สำหรับ Monitor Lab
 * strength × จำนวนต่อครั้ง × วันละ N ครั้ง (หรือนับจากเช้า-เย็น)
 */
function hosxp_daily_dose_note($usage, $daily_units) {
    $usage = trim((string)$usage);
    if (preg_match('/\s*(?:และ|,)\s*/u', $usage) && preg_match('/เช้า|เที่ยง|เย็น|ก่อนนอน/ui', $usage)) {
        return 'strength × รวมเม็ด/วัน (แยกมื้อ)';
    }
    $times_per_day = hosxp_parse_times_per_day($usage);
    if ($times_per_day !== null && $times_per_day > 1) {
        return 'strength × จำนวน × วันละ ' . hosxp_format_times_per_day_label($times_per_day);
    }
    return 'strength × จำนวน/วัน';
}

function hosxp_parse_drug_daily_dose_mg(array $item) {
    $usage = trim((string)($item['method'] ?? ''));
    $strength_mg = hosxp_parse_strength_mg($item['strength'] ?? '', $item['units'] ?? '');
    $base = hosxp_parse_drug_dose_mg($item);
    $times_per_day = hosxp_parse_times_per_day($usage);

    $mg_direct = hosxp_extract_mg_from_usage($usage);
    if ($mg_direct !== null) {
        $daily_units = hosxp_parse_daily_units_from_usage($usage);
        if ($daily_units !== null && $daily_units > 0 && $times_per_day !== null && $times_per_day > 1) {
            $base['mg_dose'] = $mg_direct * $times_per_day;
            $base['mg_note'] = 'จากวิธีใช้ × วันละ ' . hosxp_format_times_per_day_label($times_per_day);
        }
        return $base;
    }

    if ($strength_mg !== null) {
        $daily_units = hosxp_parse_daily_units_from_usage($usage);
        if ($daily_units !== null && $daily_units > 0) {
            $base['mg_dose'] = $strength_mg * $daily_units;
            $base['mg_note'] = hosxp_daily_dose_note($usage, $daily_units);
            $base['pill_qty'] = $daily_units;
            return $base;
        }
    }

    return $base;
}

function hosxp_format_times_per_day_label($times) {
    $times = (float)$times;
    if ($times == (int)$times) {
        return (int)$times . ' ครั้ง';
    }
    return rtrim(rtrim(number_format($times, 2, '.', ''), '0'), '.') . ' ครั้ง';
}

function hosxp_format_actual_dose_display(array $parsed) {
    if ($parsed['mg_dose'] !== null && $parsed['mg_dose'] > 0) {
        $display = (floor($parsed['mg_dose']) == $parsed['mg_dose'])
            ? (string)(int)$parsed['mg_dose']
            : rtrim(rtrim(number_format($parsed['mg_dose'], 2, '.', ''), '0'), '.');
        $out = $display . ' mg';
        if (!empty($parsed['mg_note'])) {
            $out .= ' <small class="text-muted">(' . htmlspecialchars($parsed['mg_note'], ENT_QUOTES, 'UTF-8') . ')</small>';
        }
        return $out;
    }
    if ($parsed['pill_qty'] !== null && $parsed['pill_qty'] > 0) {
        $display = (floor($parsed['pill_qty']) == $parsed['pill_qty'])
            ? (string)(int)$parsed['pill_qty']
            : rtrim(rtrim(number_format($parsed['pill_qty'], 2, '.', ''), '0'), '.');
        return $display . ' ' . htmlspecialchars($parsed['pill_unit'] ?: 'หน่วย', ENT_QUOTES, 'UTF-8')
            . ' <small class="text-muted">(ไม่สามารถคำนวณ mg)</small>';
    }
    return '-';
}
