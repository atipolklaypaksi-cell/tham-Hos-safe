<?php
/**
 * นิยามวิธีใช้ยาสำหรับ Monitor จำนวนสั่งยา OPD
 */

function moq_method_str_contains_ci($haystack, $needle) {
    if ($needle === '') {
        return false;
    }
    if (function_exists('mb_stripos')) {
        return mb_stripos((string)$haystack, (string)$needle) !== false;
    }
    return stripos((string)$haystack, (string)$needle) !== false;
}

function moq_method_str_contains($haystack, $needle) {
    if ($needle === '') {
        return false;
    }
    if (function_exists('mb_strpos')) {
        return mb_strpos((string)$haystack, (string)$needle) !== false;
    }
    return strpos((string)$haystack, (string)$needle) !== false;
}

function moq_method_thai_weekday_map() {
    return [
        'จันทร์' => 1,
        'อังคาร' => 2,
        'พุธ' => 3,
        'พฤหัสบดี' => 4,
        'พฤหัส' => 4,
        'ศุกร์' => 5,
        'เสาร์' => 6,
        'อาทิตย์' => 7,
    ];
}

function moq_method_ensure_table($con) {
    $q = mysqli_query($con, "SHOW TABLES LIKE 'monitor_opd_order_qty_method_rules'");
    if ($q && mysqli_num_rows($q) > 0) {
        return true;
    }
    $sql = @file_get_contents(__DIR__ . '/sql/monitor_opd_order_qty_method.sql');
    if ($sql === false || trim($sql) === '') {
        return false;
    }
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt !== '' && !mysqli_query($con, $stmt)) {
            return false;
        }
    }
    return true;
}

function moq_method_seed_defaults($con) {
    moq_method_ensure_table($con);
    $q = mysqli_query($con, "SELECT COUNT(*) AS c FROM monitor_opd_order_qty_method_rules");
    $row = $q ? mysqli_fetch_assoc($q) : null;
    if ((int)($row['c'] ?? 0) > 0) {
        return 0;
    }
    $defaults = [
        ['skip', 'ทันที', 'contains', 'ข้าม — ทันที (PRN/ครั้งเดียว)', 100, 'รับประทานครั้งละ 1 เม็ด ทันที'],
        ['skip', 'เมื่อมีอาการ', 'contains', 'ข้าม — เมื่อมีอาการ', 99, ''],
        ['skip', 'ตามอาการ', 'contains', 'ข้าม — ตามอาการ', 98, ''],
        ['skip', 'PRN', 'contains', 'ข้าม — PRN', 97, ''],
        ['per_week', 'สัปดาห์ละ', 'contains', 'สัปดาห์ละ N ครั้ง (ดึงจำนวนจากข้อความ)', 80, 'รับประทานครั้งละ 2 เม็ด สัปดาห์ละ 2 ครั้ง หลังฟอกเลือด'],
        ['weekdays', 'ทุกวัน', 'contains', 'ทุกวันX (ดึงวันจากข้อความ)', 70, '1 เม็ด ทุกวันอังคาร พฤหัส และวันเสาร์'],
        ['weekdays', 'วันจันทร์', 'contains', 'วันจันทร์ พุธ ศุกร์ (ดึงวันจากข้อความ)', 69, 'รับประทานครั้งละ 1 เม็ด หลังอาหารเช้า วันจันทร์ พุธ ศุกร์'],
    ];
    $n = 0;
    foreach ($defaults as $d) {
        list($mode, $pat, $mtype, $label, $pri, $sample) = $d;
        $mode_e = mysqli_real_escape_string($con, $mode);
        $pat_e = mysqli_real_escape_string($con, $pat);
        $mtype_e = mysqli_real_escape_string($con, $mtype);
        $label_e = mysqli_real_escape_string($con, $label);
        $sample_e = mysqli_real_escape_string($con, $sample);
        $sql = "INSERT INTO monitor_opd_order_qty_method_rules
            (rule_mode, match_pattern, match_type, label, priority, sample_method, source, active)
            VALUES ('$mode_e','$pat_e','$mtype_e','$label_e',$pri,'$sample_e','auto',1)";
        if (mysqli_query($con, $sql)) {
            $n++;
        }
    }
    return $n;
}

function moq_method_load_rules($con, $active_only = true) {
    static $cache = null;
    if ($cache !== null && $active_only) {
        return $cache;
    }
    moq_method_ensure_table($con);
    $where = $active_only ? 'WHERE active = 1' : '';
    $rules = [];
    $q = mysqli_query($con, "SELECT * FROM monitor_opd_order_qty_method_rules $where ORDER BY priority DESC, id ASC");
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $rules[] = $row;
        }
    }
    if ($active_only) {
        $cache = $rules;
    }
    return $rules;
}

function moq_method_matches($method, array $rule) {
    $method = trim((string)$method);
    $pattern = trim((string)($rule['match_pattern'] ?? ''));
    if ($method === '' || $pattern === '') {
        return false;
    }
    $type = strtolower(trim((string)($rule['match_type'] ?? 'contains')));
    if ($type === 'regex') {
        $regex = $pattern;
        if ($regex !== '' && $regex[0] !== '/') {
            $regex = '/' . str_replace('/', '\/', $regex) . '/ui';
        }
        return @preg_match($regex, $method) === 1;
    }
    return moq_method_str_contains_ci($method, $pattern);
}

function moq_method_parse_thai_weekdays($method) {
    $map = moq_method_thai_weekday_map();
    $found = [];
    foreach ($map as $name => $num) {
        if (moq_method_str_contains_ci($method, $name)) {
            $found[$num] = true;
        }
    }
    if (empty($found)) {
        return [];
    }
    $days = array_keys($found);
    sort($days);
    return $days;
}

function moq_method_build_weekdays_usage($method, $label = 'อัตโนมัติ — วันในสัปดาห์') {
    $days = moq_method_parse_thai_weekdays($method);
    if (empty($days)) {
        return null;
    }
    $upd = moq_method_extract_units_per_dose($method, []);
    return [
        'mode' => 'weekdays',
        'label' => $label,
        'units_per_dose' => $upd,
        'weekdays' => $days,
    ];
}

function moq_method_should_use_weekdays($method) {
    $days = moq_method_parse_thai_weekdays($method);
    if (empty($days)) {
        return false;
    }
    if (count($days) >= 2) {
        return true;
    }
    if (preg_match('/สัปดาห์ละ/ui', $method)) {
        return false;
    }
    return (bool)preg_match('/วัน(?:จันทร์|อังคาร|พุธ|พฤหัส|ศุกร์|เสาร์|อาทิตย์)/ui', $method);
}

function moq_method_extract_units_per_dose($method, array $rule) {
    $override = $rule['units_per_dose'] ?? null;
    if ($override !== null && $override !== '' && (float)$override > 0) {
        return (float)$override;
    }
    $per = hosxp_parse_per_time_dose($method);
    return $per !== null && $per > 0 ? (float)$per : 1.0;
}

/**
 * @return array{mode:string, rule_id?:int, label?:string, daily_units?:float, units_per_dose?:float, times_per_day?:float, times_per_week?:float, weekdays?:int[]}
 */
function moq_method_parse_usage($method, array $rules) {
    $method = trim((string)$method);
    if ($method === '') {
        return ['mode' => 'unknown'];
    }

    foreach ($rules as $rule) {
        if (empty($rule['active'])) {
            continue;
        }
        if (!moq_method_matches($method, $rule)) {
            continue;
        }

        $mode = trim((string)($rule['rule_mode'] ?? ''));
        $base = [
            'mode' => $mode,
            'rule_id' => (int)($rule['id'] ?? 0),
            'label' => trim((string)($rule['label'] ?? '')),
        ];

        if ($mode === 'skip') {
            return $base;
        }

        if ($mode === 'daily') {
            $tpd = (float)($rule['times_per_day'] ?? 0);
            if ($tpd <= 0) {
                $tpd = hosxp_parse_times_per_day($method);
            }
            if ($tpd === null || $tpd <= 0) {
                $tpd = 1.0;
            }
            $upd = moq_method_extract_units_per_dose($method, $rule);
            return $base + [
                'units_per_dose' => $upd,
                'times_per_day' => $tpd,
                'daily_units' => max(0.01, $upd * $tpd),
            ];
        }

        if ($mode === 'per_week') {
            $tpw = (float)($rule['times_per_week'] ?? 0);
            if ($tpw <= 0 && preg_match('/สัปดาห์ละ\s*(\d+(?:\.\d+)?)\s*ครั้ง/ui', $method, $mm)) {
                $tpw = (float)$mm[1];
            }
            if ($tpw <= 0) {
                $tpw = 1.0;
            }
            $upd = moq_method_extract_units_per_dose($method, $rule);
            return $base + [
                'units_per_dose' => $upd,
                'times_per_week' => $tpw,
                'daily_units' => max(0.01, ($upd * $tpw) / 7),
            ];
        }

        if ($mode === 'weekdays') {
            $days = [];
            $wj = trim((string)($rule['weekdays_json'] ?? ''));
            if ($wj !== '') {
                $decoded = json_decode($wj, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $d) {
                        $d = (int)$d;
                        if ($d >= 1 && $d <= 7) {
                            $days[$d] = true;
                        }
                    }
                }
            }
            if (empty($days)) {
                foreach (moq_method_parse_thai_weekdays($method) as $d) {
                    $days[$d] = true;
                }
            }
            if (empty($days)) {
                continue;
            }
            $upd = moq_method_extract_units_per_dose($method, $rule);
            return $base + [
                'units_per_dose' => $upd,
                'weekdays' => array_values(array_map('intval', array_keys($days))),
            ];
        }
    }

    if (moq_method_should_use_weekdays($method)) {
        $auto = moq_method_build_weekdays_usage($method);
        if ($auto !== null) {
            return $auto;
        }
    }

    $daily = di_parse_daily_dose($method);
    if ($daily !== null && $daily > 0) {
        return ['mode' => 'daily', 'daily_units' => (float)$daily, 'label' => 'มาตรฐาน'];
    }
    return ['mode' => 'unknown'];
}

function moq_method_count_weekday_doses($order_date, $end_date, array $weekdays) {
    $order_ts = strtotime((string)$order_date);
    $end_ts = strtotime((string)$end_date);
    if (!$order_ts || !$end_ts || $end_ts < $order_ts || empty($weekdays)) {
        return 0;
    }
    $set = [];
    foreach ($weekdays as $d) {
        $set[(int)$d] = true;
    }
    $count = 0;
    $ts = $order_ts;
    while ($ts <= $end_ts) {
        $dow = (int)date('N', $ts);
        if (isset($set[$dow])) {
            $count++;
        }
        $ts = strtotime('+1 day', $ts);
    }
    return $count;
}

function moq_method_units_needed_for_period($order_date, $nextdate, array $usage) {
    $days_needed = moq_days_needed_until_appointment($order_date, $nextdate);
    if ($days_needed === null) {
        return null;
    }

    $mode = $usage['mode'] ?? 'unknown';
    if ($mode === 'daily') {
        $daily = (float)($usage['daily_units'] ?? 0);
        if ($daily <= 0) {
            $upd = (float)($usage['units_per_dose'] ?? 1);
            $tpd = (float)($usage['times_per_day'] ?? 1);
            $daily = $upd * $tpd;
        }
        return max(0.01, $daily * $days_needed);
    }

    if ($mode === 'per_week') {
        $upd = (float)($usage['units_per_dose'] ?? 1);
        $tpw = (float)($usage['times_per_week'] ?? 1);
        $weeks = max(1, (int)ceil($days_needed / 7));
        return max(0.01, $upd * $tpw * $weeks);
    }

    if ($mode === 'weekdays') {
        $upd = (float)($usage['units_per_dose'] ?? 1);
        $dose_days = moq_method_count_weekday_doses($order_date, $nextdate, $usage['weekdays'] ?? []);
        return max(0.01, $upd * max(1, $dose_days));
    }

    return null;
}

function moq_method_calculate_coverage_days($qty, array $usage, $order_date = null) {
    $qty = (float)$qty;
    if ($qty <= 0) {
        return 1;
    }

    $mode = $usage['mode'] ?? 'unknown';
    if ($mode === 'skip') {
        return null;
    }

    if ($mode === 'daily') {
        $daily = (float)($usage['daily_units'] ?? 0);
        if ($daily <= 0) {
            return null;
        }
        return max(1, (int)ceil($qty / $daily));
    }

    if ($mode === 'per_week') {
        $upd = (float)($usage['units_per_dose'] ?? 1);
        $tpw = (float)($usage['times_per_week'] ?? 1);
        $daily_equiv = max(0.01, ($upd * $tpw) / 7);
        return max(1, (int)ceil($qty / $daily_equiv));
    }

    if ($mode === 'weekdays') {
        $upd = (float)($usage['units_per_dose'] ?? 1);
        $weekdays = $usage['weekdays'] ?? [];
        if (empty($weekdays)) {
            return null;
        }
        $remaining = $qty;
        $days = 1;
        $start = $order_date ? strtotime((string)$order_date) : strtotime('today');
        if (!$start) {
            return max(1, (int)ceil($qty / $upd));
        }
        $ts = $start;
        while ($remaining > 0 && $days < 3650) {
            $dow = (int)date('N', $ts);
            if (in_array($dow, $weekdays, true)) {
                $remaining -= $upd;
            }
            if ($remaining <= 0) {
                return $days;
            }
            $ts = strtotime('+1 day', $ts);
            $days++;
        }
        return $days;
    }

    if (!empty($usage['daily_units'])) {
        return max(1, (int)ceil($qty / (float)$usage['daily_units']));
    }
    return null;
}

function moq_method_coverage_end_date($order_date, $coverage_days) {
    return di_period_end_date($order_date, $coverage_days);
}

/**
 * @return array{status:string, usage?:array, skip_reason?:string}
 */
function moq_method_analyze($con, $method, $qty, $units, $order_date, $nextdate) {
    $rules = moq_method_load_rules($con);
    $usage = moq_method_parse_usage($method, $rules);

    if (($usage['mode'] ?? '') === 'skip') {
        return [
            'status' => 'skip',
            'usage' => $usage,
            'skip_reason' => $usage['label'] !== '' ? $usage['label'] : 'ข้ามตามนิยามวิธีใช้',
        ];
    }

    if (($usage['mode'] ?? '') === 'unknown') {
        return ['status' => 'unknown', 'usage' => $usage];
    }

    $coverage_days = moq_method_calculate_coverage_days($qty, $usage, $order_date);
    if ($coverage_days === null) {
        return ['status' => 'unknown', 'usage' => $usage];
    }

    $units_needed = moq_method_units_needed_for_period($order_date, $nextdate, $usage);
    $daily_units = null;
    if ($usage['mode'] === 'daily') {
        $daily_units = (float)($usage['daily_units'] ?? 0);
    } elseif ($units_needed !== null && ($dn = moq_days_needed_until_appointment($order_date, $nextdate)) > 0) {
        $daily_units = $units_needed / $dn;
    }

    return [
        'status' => 'ok',
        'usage' => $usage,
        'coverage_days' => $coverage_days,
        'coverage_end' => moq_method_coverage_end_date($order_date, $coverage_days),
        'units_needed' => $units_needed,
        'daily_units' => $daily_units,
    ];
}

function moq_method_scan_from_hosxp($hos, $con, $days = 180, $limit = 5000) {
    if (!$hos) {
        return ['inserted' => 0, 'message' => 'ไม่สามารถเชื่อมต่อ HOSxP'];
    }
    moq_method_ensure_table($con);
    $expr = di_sql_method_expr('o');
    $joins = di_sql_method_joins('o');
    $days = max(30, min(365, (int)$days));
    $limit = max(500, min(10000, (int)$limit));
    $sql = "
        SELECT DISTINCT $expr AS method
        FROM opitemre o
        $joins
        WHERE o.rxdate >= DATE_SUB(CURDATE(), INTERVAL $days DAY)
          AND o.qty > 0
          AND (o.an IS NULL OR o.an = '')
        HAVING method IS NOT NULL AND TRIM(method) <> ''
        LIMIT $limit
    ";
    $q = mysqli_query($hos, $sql);
    if (!$q) {
        return ['inserted' => 0, 'message' => 'SQL: ' . mysqli_error($hos)];
    }

    $existing = [];
    $eq = mysqli_query($con, "SELECT match_pattern, rule_mode FROM monitor_opd_order_qty_method_rules");
    if ($eq) {
        while ($r = mysqli_fetch_assoc($eq)) {
            $existing[strtolower($r['rule_mode'] . '|' . $r['match_pattern'])] = true;
        }
    }

    $candidates = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $m = trim((string)($row['method'] ?? ''));
        if ($m === '') {
            continue;
        }
        if (moq_method_str_contains_ci($m, 'ทันที')) {
            $candidates['skip|ทันที'] = ['skip', 'ทันที', 'ข้าม — ทันที', $m];
        }
        if (preg_match('/สัปดาห์ละ\s*\d+/u', $m)) {
            $candidates['per_week|สัปดาห์ละ'] = ['per_week', 'สัปดาห์ละ', 'สัปดาห์ละ N ครั้ง', $m];
        }
        if (moq_method_str_contains_ci($m, 'ทุกวัน') !== false && !empty(moq_method_parse_thai_weekdays($m))) {
            $candidates['weekdays|ทุกวัน'] = ['weekdays', 'ทุกวัน', 'ทุกวันX (ดึงวันจากข้อความ)', $m];
        }
        if (!moq_method_str_contains_ci($m, 'ทุกวัน') && count(moq_method_parse_thai_weekdays($m)) >= 2) {
            $candidates['weekdays|วันจันทร์'] = ['weekdays', 'วันจันทร์', 'วันจันทร์ พุธ ศุกร์ (ดึงวันจากข้อความ)', $m];
        }
        if (moq_method_str_contains_ci($m, 'ตามอาการ')) {
            $candidates['skip|ตามอาการ'] = ['skip', 'ตามอาการ', 'ข้าม — ตามอาการ', $m];
        }
        if (moq_method_str_contains_ci($m, 'เมื่อมีอาการ')) {
            $candidates['skip|เมื่อมีอาการ'] = ['skip', 'เมื่อมีอาการ', 'ข้าม — เมื่อมีอาการ', $m];
        }
        if (preg_match('/\bPRN\b/i', $m)) {
            $candidates['skip|PRN'] = ['skip', 'PRN', 'ข้าม — PRN', $m];
        }
    }

    $inserted = 0;
    foreach ($candidates as $key => $c) {
        if (isset($existing[strtolower($key)])) {
            continue;
        }
        list($mode, $pat, $label, $sample) = $c;
        $mode_e = mysqli_real_escape_string($con, $mode);
        $pat_e = mysqli_real_escape_string($con, $pat);
        $label_e = mysqli_real_escape_string($con, $label);
        $sample_e = mysqli_real_escape_string($con, function_exists('mb_substr') ? mb_substr($sample, 0, 500) : substr($sample, 0, 500));
        $sql_ins = "INSERT INTO monitor_opd_order_qty_method_rules
            (rule_mode, match_pattern, match_type, label, priority, sample_method, source, active)
            VALUES ('$mode_e','$pat_e','contains','$label_e',50,'$sample_e','auto',1)";
        if (mysqli_query($con, $sql_ins)) {
            $inserted++;
            $existing[strtolower($key)] = true;
        }
    }
    return ['inserted' => $inserted, 'scanned' => mysqli_num_rows($q), 'message' => 'ok'];
}

function moq_method_rule_mode_label($mode) {
    $map = [
        'skip' => 'ข้าม (ไม่แจ้งเตือน)',
        'daily' => 'วันละ N ครั้ง',
        'per_week' => 'สัปดาห์ละ N ครั้ง',
        'weekdays' => 'เฉพาะวันในสัปดาห์',
    ];
    return $map[$mode] ?? $mode;
}
