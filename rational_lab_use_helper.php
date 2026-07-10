<?php
/**
 * Rational Lab Use — สถิติการใช้ Lab (cache รายเดือน + เปรียบเทียบช่วง)
 */

function rlu_format_number($n, $decimals = 0) {
    $n = (float)$n;
    if ($decimals <= 0) {
        return number_format($n, 0, '.', ',');
    }
    return rtrim(rtrim(number_format($n, $decimals, '.', ','), '0'), '.');
}

function rlu_format_money($n) {
    return rlu_format_number($n, 2);
}

function rlu_format_pct_change($base, $current) {
    $base = (float)$base;
    $current = (float)$current;
    if ($base == 0.0) {
        return $current > 0 ? '+100%' : '0%';
    }
    $pct = (($current - $base) / $base) * 100;
    $sign = $pct > 0 ? '+' : '';
    return $sign . rlu_format_number($pct, 1) . '%';
}

function rlu_ensure_cache_table($con) {
    $q = mysqli_query($con, "SHOW TABLES LIKE 'rational_lab_usage_monthly'");
    if ($q && mysqli_num_rows($q) > 0) {
        rlu_ensure_item_cache_table($con);
        rlu_ensure_doctor_cache_table($con);
        rlu_ensure_hipdata_cache_schema($con);
        return true;
    }
    $sql = @file_get_contents(__DIR__ . '/sql/rational_lab_usage_monthly.sql');
    if ($sql === false || trim($sql) === '') {
        return false;
    }
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt !== '' && !mysqli_query($con, $stmt)) {
            return false;
        }
    }
    rlu_ensure_item_cache_table($con);
    rlu_ensure_doctor_cache_table($con);
    rlu_ensure_hipdata_cache_schema($con);
    return true;
}

define('RLU_HIPDATA_CACHE_SCHEMA_VER', 3);

/**
 * แยก cache ตาม hipdata_code หรือไม่
 * false (ค่าเริ่มต้น) = โหมดเร็วเหมือนก่อนมีตัวกรองสิทธิ — ไม่ join ovst/pttype ตอนสร้าง cache
 * เปิดได้ที่ sys_config: rlu_cache_split_hipdata = 1
 */
function rlu_cache_split_hipdata_enabled($con = null) {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    if ($con) {
        $q = @mysqli_query($con, "SELECT config_value FROM sys_config WHERE config_name='rlu_cache_split_hipdata' LIMIT 1");
        if ($q && ($r = mysqli_fetch_assoc($q))) {
            $v = strtolower(trim((string)($r['config_value'] ?? '')));
            if (in_array($v, ['1', 'true', 'yes', 'on'], true)) {
                $cached = true;
                return true;
            }
            if (in_array($v, ['0', 'false', 'no', 'off'], true)) {
                $cached = false;
                return false;
            }
        }
    }
    $cached = false;
    return false;
}

function rlu_sql_build_hipdata_select_sql() {
    if (!rlu_cache_split_hipdata_enabled()) {
        return "'' AS hipdata_code";
    }
    return "COALESCE(NULLIF(TRIM(pt.hipdata_code), ''), '__none__') AS hipdata_code";
}

function rlu_sql_build_hipdata_join_sql() {
    if (!rlu_cache_split_hipdata_enabled()) {
        return '';
    }
    return rlu_sql_lab_hipdata_joins();
}

function rlu_sql_build_hipdata_group_prefix() {
    if (!rlu_cache_split_hipdata_enabled()) {
        return '';
    }
    return 'hipdata_code, ';
}

function rlu_parse_hipdata_filter_opts($con, $raw) {
    if (!rlu_cache_split_hipdata_enabled($con)) {
        return [];
    }
    return rlu_parse_hipdata_codes($raw);
}

/** เพิ่ม hipdata_code ใน cache Lab — รองรับกรอง UCS โดยไม่ query HOSxP ซ้ำ */
function rlu_ensure_hipdata_cache_schema($con) {
    static $done = false;
    if ($done) {
        return true;
    }
    $q = mysqli_query($con, "SHOW COLUMNS FROM rational_lab_usage_monthly LIKE 'hipdata_code'");
    if ($q && mysqli_num_rows($q) > 0) {
        $done = true;
        return true;
    }
    $alters = [
        "ALTER TABLE rational_lab_usage_monthly DROP INDEX uk_month_group",
        "ALTER TABLE rational_lab_usage_monthly ADD COLUMN hipdata_code VARCHAR(12) NOT NULL DEFAULT '' AFTER cache_ym",
        "ALTER TABLE rational_lab_usage_monthly ADD UNIQUE KEY uk_month_group (cache_ym, hipdata_code, group_code)",
        "ALTER TABLE rational_lab_item_monthly DROP INDEX uk_month_item",
        "ALTER TABLE rational_lab_item_monthly ADD COLUMN hipdata_code VARCHAR(12) NOT NULL DEFAULT '' AFTER cache_ym",
        "ALTER TABLE rational_lab_item_monthly ADD UNIQUE KEY uk_month_item (cache_ym, hipdata_code, item_code)",
        "ALTER TABLE rational_lab_doctor_monthly DROP INDEX uk_month_doctor",
        "ALTER TABLE rational_lab_doctor_monthly ADD COLUMN hipdata_code VARCHAR(12) NOT NULL DEFAULT '' AFTER cache_ym",
        "ALTER TABLE rational_lab_doctor_monthly ADD UNIQUE KEY uk_month_doctor (cache_ym, hipdata_code, doctor_code)",
        "ALTER TABLE rational_lab_dept_monthly DROP INDEX uk_month_dept_income",
        "ALTER TABLE rational_lab_dept_monthly ADD COLUMN hipdata_code VARCHAR(12) NOT NULL DEFAULT '' AFTER cache_ym",
        "ALTER TABLE rational_lab_dept_monthly ADD UNIQUE KEY uk_month_dept_income (cache_ym, hipdata_code, dept_code, income_group2)",
    ];
    foreach ($alters as $sql) {
        @mysqli_query($con, $sql);
    }
    foreach (['rational_lab_usage_monthly', 'rational_lab_item_monthly', 'rational_lab_doctor_monthly', 'rational_lab_dept_monthly'] as $tbl) {
        @mysqli_query($con, "DELETE FROM $tbl");
    }
    $done = true;
    return true;
}

function rlu_sql_cache_hipdata_filter($con, array $codes) {
    if (!rlu_cache_split_hipdata_enabled($con)) {
        return '';
    }
    $codes = rlu_parse_hipdata_codes($codes);
    if (empty($codes)) {
        return '';
    }
    $escaped = [];
    foreach ($codes as $code) {
        $escaped[] = "'" . rlu_escape($con, $code) . "'";
    }
    return ' AND hipdata_code IN (' . implode(',', $escaped) . ')';
}

function rlu_ensure_doctor_cache_table($con) {
    $q = mysqli_query($con, "SHOW TABLES LIKE 'rational_lab_doctor_monthly'");
    if (!$q || mysqli_num_rows($q) === 0) {
        $sql = @file_get_contents(__DIR__ . '/sql/rational_lab_doctor_monthly.sql');
        if ($sql === false || trim($sql) === '') {
            return false;
        }
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt !== '' && !mysqli_query($con, $stmt)) {
                return false;
            }
        }
    }
    return rlu_ensure_doctor_cache_columns($con);
}

define('RLU_DOCTOR_CACHE_SCHEMA_VER', 2);

function rlu_ensure_doctor_cache_columns($con) {
    $needed = [
        'item_count_vn' => "ALTER TABLE rational_lab_doctor_monthly ADD COLUMN item_count_vn INT NOT NULL DEFAULT 0 AFTER total_price",
        'total_price_vn' => "ALTER TABLE rational_lab_doctor_monthly ADD COLUMN total_price_vn DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER item_count_vn",
        'item_count_an' => "ALTER TABLE rational_lab_doctor_monthly ADD COLUMN item_count_an INT NOT NULL DEFAULT 0 AFTER total_price_vn",
        'total_price_an' => "ALTER TABLE rational_lab_doctor_monthly ADD COLUMN total_price_an DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER item_count_an",
        'cache_schema_ver' => "ALTER TABLE rational_lab_doctor_monthly ADD COLUMN cache_schema_ver TINYINT NOT NULL DEFAULT 1 AFTER an_count",
    ];
    foreach ($needed as $col => $ddl) {
        $q = mysqli_query($con, "SHOW COLUMNS FROM rational_lab_doctor_monthly LIKE '" . rlu_escape($con, $col) . "'");
        if ($q && mysqli_num_rows($q) > 0) {
            continue;
        }
        if (!mysqli_query($con, $ddl)) {
            return false;
        }
    }
    return true;
}

/** แปลงโหมดสรุปแพทย์ → vn | an | '' */
function rlu_normalize_visit_scope($scope) {
    $s = trim((string)$scope);
    if (in_array($s, ['vn', 'vn_only', 'avg_vn'], true)) {
        return 'vn';
    }
    if (in_array($s, ['an', 'an_only', 'avg_an'], true)) {
        return 'an';
    }
    return '';
}

/** กรอง Lab ตามประเภท visit — เฉพาะ VN (OPD) หรือเฉพาะ AN (IPD) */
function rlu_sql_lab_visit_scope_sql($scope) {
    $scope = rlu_normalize_visit_scope($scope);
    if ($scope === 'vn') {
        return " AND lh.vn IS NOT NULL AND TRIM(lh.vn) <> '' AND (ip.an IS NULL OR TRIM(ip.an) = '')";
    }
    if ($scope === 'an') {
        return " AND ip.an IS NOT NULL AND TRIM(ip.an) <> ''";
    }
    return '';
}

function rlu_ensure_dept_cache_table($con) {
    $q = mysqli_query($con, "SHOW TABLES LIKE 'rational_lab_dept_monthly'");
    if ($q && mysqli_num_rows($q) > 0) {
        return true;
    }
    $sql = @file_get_contents(__DIR__ . '/sql/rational_lab_dept_monthly.sql');
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

function rlu_ensure_item_cache_table($con) {
    $q = mysqli_query($con, "SHOW TABLES LIKE 'rational_lab_item_monthly'");
    if ($q && mysqli_num_rows($q) > 0) {
        return true;
    }
    $sql = @file_get_contents(__DIR__ . '/sql/rational_lab_item_monthly.sql');
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

function rlu_ensure_item_doctor_cache_table($con) {
    $q = mysqli_query($con, "SHOW TABLES LIKE 'rational_lab_item_doctor_monthly'");
    if ($q && mysqli_num_rows($q) > 0) {
        return true;
    }
    $sql = @file_get_contents(__DIR__ . '/sql/rational_lab_item_doctor_monthly.sql');
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

function rlu_item_doctor_cache_month_ready($con, $year_month, $item_code) {
    rlu_ensure_item_doctor_cache_table($con);
    $ym = rlu_escape($con, $year_month);
    $ic = rlu_escape($con, trim((string)$item_code));
    if ($ic === '') {
        return false;
    }
    $q = mysqli_query($con, "SELECT COUNT(*) AS c FROM rational_lab_item_doctor_monthly WHERE cache_ym='$ym' AND item_code='$ic' LIMIT 1");
    if (!$q) {
        return false;
    }
    $r = mysqli_fetch_assoc($q);
    return (int)($r['c'] ?? 0) > 0;
}

function rlu_save_item_doctor_month_cache($con, $year_month, $item_code, array $rows) {
    rlu_ensure_item_doctor_cache_table($con);
    $ym = rlu_escape($con, $year_month);
    $ic = rlu_escape($con, trim((string)$item_code));
    mysqli_query($con, "DELETE FROM rational_lab_item_doctor_monthly WHERE cache_ym='$ym' AND item_code='$ic'");
    if (empty($rows)) {
        return true;
    }
    $by_code = [];
    foreach ($rows as $row) {
        $code = trim($row['row_code'] ?? $row['doctor_code'] ?? '');
        if ($code === '__none__') {
            $code = '';
        }
        $key = $code !== '' ? $code : '__none__';
        $name = trim($row['row_name'] ?? $row['doctor_name'] ?? '');
        $cnt = (int)($row['item_count'] ?? 0);
        $price = (float)($row['total_price'] ?? 0);
        if (!isset($by_code[$key])) {
            $by_code[$key] = ['code' => $code, 'name' => $name, 'cnt' => $cnt, 'price' => $price];
        } else {
            $by_code[$key]['cnt'] += $cnt;
            $by_code[$key]['price'] += $price;
            if ($by_code[$key]['name'] === '' && $name !== '') {
                $by_code[$key]['name'] = $name;
            }
        }
    }
    $tuples = [];
    foreach ($by_code as $entry) {
        $tuples[] = "('$ym','$ic','" . rlu_escape($con, $entry['code']) . "','" . rlu_escape($con, $entry['name']) . "',"
            . (int)$entry['cnt'] . ',' . (float)$entry['price'] . ')';
    }
    return rlu_bulk_insert_values($con, 'rational_lab_item_doctor_monthly', [
        'cache_ym', 'item_code', 'doctor_code', 'doctor_name', 'item_count', 'total_price',
    ], $tuples);
}

/** รวมแพทย์ที่สั่งรายการ Lab หนึ่งรายการจาก cache รายเดือน */
function rlu_fetch_item_doctors_period_from_cache($con, $start_date, $end_date, $item_code, $limit = 200) {
    rlu_ensure_item_doctor_cache_table($con);
    $months = rlu_month_keys_in_period($start_date, $end_date);
    if (empty($months)) {
        return ['rows' => [], 'error' => '', 'elapsed_ms' => 0];
    }
    $ym_min = rlu_escape($con, min($months));
    $ym_max = rlu_escape($con, max($months));
    $ic = rlu_escape($con, trim((string)$item_code));
    $limit = max(1, min(500, (int)$limit));
    $sql = "
        SELECT
            doctor_code AS row_code,
            MAX(doctor_name) AS row_name,
            SUM(item_count) AS item_count,
            SUM(total_price) AS total_price
        FROM rational_lab_item_doctor_monthly
        WHERE cache_ym >= '$ym_min' AND cache_ym <= '$ym_max'
          AND item_code = '$ic'
        GROUP BY doctor_code
        HAVING item_count > 0
        ORDER BY item_count DESC, row_name ASC
        LIMIT $limit
    ";
    $t0 = microtime(true);
    $q = mysqli_query($con, $sql);
    if (!$q) {
        return ['rows' => [], 'error' => mysqli_error($con), 'elapsed_ms' => 0];
    }
    $rows = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $code = trim($row['row_code'] ?? '');
        $rows[] = [
            'row_code' => $code,
            'row_name' => trim($row['row_name'] ?? ''),
            'item_code' => $code,
            'item_name' => trim($row['row_name'] ?? ''),
            'item_count' => (int)($row['item_count'] ?? 0),
            'total_price' => (float)($row['total_price'] ?? 0),
        ];
    }
    return [
        'rows' => $rows,
        'error' => '',
        'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
    ];
}

function rlu_ensure_dept_doctor_cache_table($con) {
    $q = mysqli_query($con, "SHOW TABLES LIKE 'rational_lab_dept_doctor_monthly'");
    if ($q && mysqli_num_rows($q) > 0) {
        return true;
    }
    $sql = @file_get_contents(__DIR__ . '/sql/rational_lab_dept_doctor_monthly.sql');
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

function rlu_dept_doctor_cache_month_ready($con, $year_month, $dept_code) {
    rlu_ensure_dept_doctor_cache_table($con);
    $ym = rlu_escape($con, $year_month);
    $dc = rlu_escape($con, trim((string)$dept_code));
    $q = mysqli_query($con, "SELECT COUNT(*) AS c FROM rational_lab_dept_doctor_monthly WHERE cache_ym='$ym' AND dept_code='$dc' LIMIT 1");
    if (!$q) {
        return false;
    }
    $r = mysqli_fetch_assoc($q);
    return (int)($r['c'] ?? 0) > 0;
}

function rlu_save_dept_doctor_month_cache($con, $year_month, $dept_code, array $rows) {
    rlu_ensure_dept_doctor_cache_table($con);
    $ym = rlu_escape($con, $year_month);
    $dc = rlu_escape($con, trim((string)$dept_code));
    mysqli_query($con, "DELETE FROM rational_lab_dept_doctor_monthly WHERE cache_ym='$ym' AND dept_code='$dc'");
    if (empty($rows)) {
        return true;
    }
    $by_code = [];
    foreach ($rows as $row) {
        $code = trim($row['row_code'] ?? $row['doctor_code'] ?? '');
        if ($code === '__none__') {
            $code = '';
        }
        $key = $code !== '' ? $code : '__none__';
        $name = trim($row['row_name'] ?? $row['doctor_name'] ?? '');
        $cnt = (int)($row['item_count'] ?? 0);
        $price = (float)($row['total_price'] ?? 0);
        if (!isset($by_code[$key])) {
            $by_code[$key] = ['code' => $code, 'name' => $name, 'cnt' => $cnt, 'price' => $price];
        } else {
            $by_code[$key]['cnt'] += $cnt;
            $by_code[$key]['price'] += $price;
            if ($by_code[$key]['name'] === '' && $name !== '') {
                $by_code[$key]['name'] = $name;
            }
        }
    }
    $tuples = [];
    foreach ($by_code as $entry) {
        $tuples[] = "('$ym','$dc','" . rlu_escape($con, $entry['code']) . "','" . rlu_escape($con, $entry['name']) . "',"
            . (int)$entry['cnt'] . ',' . (float)$entry['price'] . ')';
    }
    return rlu_bulk_insert_values($con, 'rational_lab_dept_doctor_monthly', [
        'cache_ym', 'dept_code', 'doctor_code', 'doctor_name', 'item_count', 'total_price',
    ], $tuples);
}

function rlu_fetch_dept_doctors_period_from_cache($con, $start_date, $end_date, $dept_code, $limit = 200) {
    rlu_ensure_dept_doctor_cache_table($con);
    $months = rlu_month_keys_in_period($start_date, $end_date);
    if (empty($months)) {
        return ['rows' => [], 'error' => '', 'elapsed_ms' => 0];
    }
    $ym_min = rlu_escape($con, min($months));
    $ym_max = rlu_escape($con, max($months));
    $dc = rlu_escape($con, trim((string)$dept_code));
    $limit = max(1, min(500, (int)$limit));
    $sql = "
        SELECT
            doctor_code AS row_code,
            MAX(doctor_name) AS row_name,
            SUM(item_count) AS item_count,
            SUM(total_price) AS total_price
        FROM rational_lab_dept_doctor_monthly
        WHERE cache_ym >= '$ym_min' AND cache_ym <= '$ym_max'
          AND dept_code = '$dc'
        GROUP BY doctor_code
        HAVING item_count > 0
        ORDER BY item_count DESC, row_name ASC
        LIMIT $limit
    ";
    $t0 = microtime(true);
    $q = mysqli_query($con, $sql);
    if (!$q) {
        return ['rows' => [], 'error' => mysqli_error($con), 'elapsed_ms' => 0];
    }
    $rows = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $code = trim($row['row_code'] ?? '');
        $rows[] = [
            'row_code' => $code,
            'row_name' => trim($row['row_name'] ?? ''),
            'item_code' => $code,
            'item_name' => trim($row['row_name'] ?? ''),
            'item_count' => (int)($row['item_count'] ?? 0),
            'total_price' => (float)($row['total_price'] ?? 0),
        ];
    }
    return [
        'rows' => $rows,
        'error' => '',
        'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
    ];
}

function rlu_pack_dimension_detail_rows(array $item_map, array $periods, $dimension, $elapsed, $data_source = 'hosxp', array $options = []) {
    $period_count = count($periods);
    $rows = [];
    foreach ($item_map as $item) {
        $base = $item['periods'][0] ?? ['item_count' => 0, 'total_price' => 0.0];
        $period_rows = [];
        for ($pi = 1; $pi < $period_count; $pi++) {
            $p = $item['periods'][$pi] ?? ['item_count' => 0, 'total_price' => 0.0];
            $diff_cnt = (int)$p['item_count'] - (int)$base['item_count'];
            $diff_price = (float)$p['total_price'] - (float)$base['total_price'];
            $pr = [
                'period_index' => $pi,
                'item_count' => (int)$p['item_count'],
                'total_price' => (float)$p['total_price'],
                'item_count_display' => $p['item_count_display'] ?? rlu_format_number($p['item_count']),
                'total_price_display' => $p['total_price_display'] ?? rlu_format_money($p['total_price']),
                'diff_item_count' => $diff_cnt,
                'diff_total_price' => $diff_price,
                'diff_item_count_display' => ($diff_cnt >= 0 ? '+' : '') . rlu_format_number($diff_cnt),
                'diff_total_price_display' => ($diff_price >= 0 ? '+' : '') . rlu_format_money(abs($diff_price)),
                'pct_item_count' => rlu_format_pct_change($base['item_count'], $p['item_count']),
                'pct_total_price' => rlu_format_pct_change($base['total_price'], $p['total_price']),
            ];
            if ($dimension === 'doctor') {
                $pr['visit_count'] = (int)($p['visit_count'] ?? 0);
                $pr['an_count'] = (int)($p['an_count'] ?? 0);
                $pr['item_count_vn'] = (int)($p['item_count_vn'] ?? 0);
                $pr['total_price_vn'] = (float)($p['total_price_vn'] ?? 0);
                $pr['item_count_an'] = (int)($p['item_count_an'] ?? 0);
                $pr['total_price_an'] = (float)($p['total_price_an'] ?? 0);
            }
            $period_rows[] = $pr;
        }
        $row_out = [
            'row_code' => $item['row_code'],
            'row_name' => $item['row_name'],
            'item_code' => $item['item_code'],
            'item_name' => $item['item_name'],
            'a_item_count' => (int)($base['item_count'] ?? 0),
            'a_total_price' => (float)($base['total_price'] ?? 0.0),
            'a_item_count_display' => $base['item_count_display'] ?? rlu_format_number($base['item_count'] ?? 0),
            'a_total_price_display' => $base['total_price_display'] ?? rlu_format_money($base['total_price'] ?? 0.0),
            'periods' => $period_rows,
        ];
        if ($dimension === 'doctor') {
            $row_out['a_visit_count'] = (int)($base['visit_count'] ?? 0);
            $row_out['a_an_count'] = (int)($base['an_count'] ?? 0);
            $row_out['a_item_count_vn'] = (int)($base['item_count_vn'] ?? 0);
            $row_out['a_total_price_vn'] = (float)($base['total_price_vn'] ?? 0);
            $row_out['a_item_count_an'] = (int)($base['item_count_an'] ?? 0);
            $row_out['a_total_price_an'] = (float)($base['total_price_an'] ?? 0);
        }
        $rows[] = $row_out;
    }

    usort($rows, function ($a, $b) {
        return ($b['a_item_count'] <=> $a['a_item_count']);
    });

    $period_meta = [];
    foreach ($periods as $p) {
        $period_meta[] = [
            'label' => $p['label'] ?? '',
            'start_date' => $p['start_date'] ?? '',
            'end_date' => $p['end_date'] ?? '',
        ];
    }

    $out = [
        'rows' => $rows,
        'periods' => $period_meta,
        'multi_period' => $period_count > 1,
        'dimension' => $dimension,
        'error' => '',
        'elapsed_ms' => (int)$elapsed,
    ];
    if (!empty($options['doctor_code'])) {
        $out['doctor_code'] = trim((string)$options['doctor_code']);
    }
    if ($data_source === 'cache') {
        $out['data_source'] = 'cache';
    }
    return $out;
}

function rlu_escape($con, $v) {
    return mysqli_real_escape_string($con, $v ?? '');
}

/** แทรกหลายแถวต่อคำสั่ง — ลด round-trip ตอนบันทึก cache */
function rlu_bulk_insert_values($con, $table, array $columns, array $value_tuples, $chunk_size = 400) {
    if (empty($value_tuples)) {
        return true;
    }
    $cols = '`' . implode('`,`', $columns) . '`';
    $chunk_size = max(1, min(500, (int)$chunk_size));
    for ($i = 0; $i < count($value_tuples); $i += $chunk_size) {
        $chunk = array_slice($value_tuples, $i, $chunk_size);
        $sql = 'INSERT INTO `' . $table . '` (' . $cols . ') VALUES ' . implode(',', $chunk);
        if (!mysqli_query($con, $sql)) {
            error_log('rlu_bulk_insert_values failed: ' . mysqli_error($con) . ' table=' . $table);
            return false;
        }
    }
    return true;
}

function rlu_normalize_build_scope($scope) {
    $scope = strtolower(trim((string)$scope));
    return in_array($scope, ['usage', 'item', 'doctor', 'dept', 'all'], true) ? $scope : 'usage';
}

/** จำนวนเดือนสูงสุดต่อ 1 query HOSxP — ปรับตามมิติ (doctor/dept ใช้ 1 เดือนเพื่อกัน timeout) */
function rlu_build_batch_chunk_months($scope) {
    $scope = strtolower(trim((string)$scope));
    if ($scope === 'usage') {
        return 24;
    }
    if ($scope === 'doctor' || $scope === 'dept') {
        return 1;
    }
    if ($scope === 'item') {
        return 3;
    }
    return 6;
}

function rlu_parse_year_month_list($raw) {
    if (is_array($raw)) {
        $parts = $raw;
    } else {
        $parts = preg_split('/[,\s]+/', trim((string)$raw), -1, PREG_SPLIT_NO_EMPTY);
    }
    $months = [];
    foreach ($parts as $p) {
        $p = trim((string)$p);
        if (preg_match('/^\d{4}-\d{2}$/', $p)) {
            $months[] = $p;
        }
    }
    $months = array_values(array_unique($months));
    sort($months);
    return $months;
}

function rlu_months_date_bounds(array $months) {
    if (empty($months)) {
        return null;
    }
    sort($months);
    $first = rlu_month_bounds($months[0]);
    $last = rlu_month_bounds($months[count($months) - 1]);
    if ($first === null || $last === null) {
        return null;
    }
    return ['start' => $first['start'], 'end' => $last['end']];
}

/** แยกแถวจาก query ช่วงหลายเดือน */
function rlu_partition_rows_by_cache_ym(array $rows) {
    $by = [];
    foreach ($rows as $row) {
        $ym = trim($row['cache_ym'] ?? '');
        if ($ym === '') {
            continue;
        }
        unset($row['cache_ym']);
        $by[$ym][] = $row;
    }
    return $by;
}

function rlu_parse_date($s, $fallback = null) {
    $s = trim((string)$s);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        return $s;
    }
    return $fallback;
}

function rlu_parse_period($start_date, $end_date) {
    $end_default = date('Y-m-d');
    $start_default = date('Y-m-01');
    $start = rlu_parse_date($start_date, $start_default);
    $end = rlu_parse_date($end_date, $end_default);
    if (strtotime($start) > strtotime($end)) {
        $tmp = $start;
        $start = $end;
        $end = $tmp;
    }
    $max_span_days = 366 * 3;
    if ((strtotime($end) - strtotime($start)) / 86400 > $max_span_days) {
        $start = date('Y-m-d', strtotime($end . " -{$max_span_days} days"));
    }
    return [
        'start_date' => $start,
        'end_date' => $end,
        'label' => date('d/m/Y', strtotime($start)) . ' – ' . date('d/m/Y', strtotime($end)),
    ];
}

/** รายการ YYYY-MM ในช่วงวันที่ */
function rlu_month_keys_in_period($start_date, $end_date) {
    $start_ts = strtotime(date('Y-m-01', strtotime($start_date)));
    $end_ts = strtotime(date('Y-m-01', strtotime($end_date)));
    if ($start_ts === false || $end_ts === false) {
        return [];
    }
    $months = [];
    $cur = $start_ts;
    while ($cur <= $end_ts) {
        $months[] = date('Y-m', $cur);
        $cur = strtotime('+1 month', $cur);
    }
    return $months;
}

function rlu_month_bounds($year_month) {
    $year_month = trim((string)$year_month);
    if (!preg_match('/^\d{4}-\d{2}$/', $year_month)) {
        return null;
    }
    $start = $year_month . '-01';
    $end = date('Y-m-d', strtotime($start . ' +1 month -1 day'));
    return ['start' => $start, 'end' => $end];
}

/** ราคาต่อรายการ lab_order — ใช้ item_cost ก่อน แล้ว fallback service_price */
function rlu_sql_lab_line_price_expr() {
    return "(
        CASE
            WHEN lo.item_cost IS NOT NULL
                 AND TRIM(CAST(lo.item_cost AS CHAR)) <> ''
                 AND CAST(lo.item_cost AS DECIMAL(16,4)) > 0
                THEN CAST(lo.item_cost AS DECIMAL(16,4))
            WHEN li.service_price IS NOT NULL
                 AND CAST(li.service_price AS DECIMAL(16,4)) > 0
                THEN CAST(li.service_price AS DECIMAL(16,4))
            ELSE 0
        END
    )";
}

/** ดึงสถิติ 1 เดือนจาก HOSxP */
function rlu_fetch_hosxp_month_stats($hos, $year_month) {
    $bounds = rlu_month_bounds($year_month);
    if ($bounds === null) {
        return ['rows' => [], 'error' => 'invalid month'];
    }
    $start_esc = mysqli_real_escape_string($hos, $bounds['start']);
    $end_esc = mysqli_real_escape_string($hos, $bounds['end']);

    $sql = "
        SELECT
            " . rlu_sql_build_hipdata_select_sql() . ",
            COALESCE(NULLIF(TRIM(li.lab_items_group), ''), '__none__') AS group_code,
            CAST(CONVERT(COALESCE(lig.lab_items_group_name, 'ไม่ระบุกลุ่ม') USING utf8) AS CHAR) AS group_name,
            COUNT(*) AS item_count,
            SUM(" . rlu_sql_lab_line_price_expr() . ") AS total_price
        FROM lab_head lh
        INNER JOIN lab_order lo ON lo.lab_order_number = lh.lab_order_number
        LEFT JOIN lab_items li ON li.lab_items_code = lo.lab_items_code
        LEFT JOIN lab_items_group lig ON lig.lab_items_group_code = li.lab_items_group
        " . rlu_sql_build_hipdata_join_sql() . "
        WHERE lh.order_date >= '$start_esc'
          AND lh.order_date <= '$end_esc'
          AND lh.order_date <> '0000-00-00'
        GROUP BY " . rlu_sql_build_hipdata_group_prefix() . "group_code, group_name
    ";

    $t0 = microtime(true);
    $q = mysqli_query($hos, $sql);
    if (!$q) {
        return ['rows' => [], 'error' => mysqli_error($hos), 'elapsed_ms' => 0];
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $code = ($row['group_code'] ?? '') === '__none__' ? '' : trim($row['group_code'] ?? '');
        $rows[] = [
            'hipdata_code' => trim($row['hipdata_code'] ?? ''),
            'group_code' => $code,
            'group_name' => trim($row['group_name'] ?? 'ไม่ระบุกลุ่ม'),
            'item_count' => (int)($row['item_count'] ?? 0),
            'total_price' => (float)($row['total_price'] ?? 0),
        ];
    }

    return [
        'rows' => $rows,
        'error' => '',
        'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
    ];
}

/** ดึงสถิติหลายเดือนในครั้งเดียว — สแกน lab_head รอบเดียว */
function rlu_fetch_hosxp_range_stats($hos, $start_date, $end_date) {
    $start_esc = mysqli_real_escape_string($hos, $start_date);
    $end_esc = mysqli_real_escape_string($hos, $end_date);
    $sql = "
        SELECT
            DATE_FORMAT(lh.order_date, '%Y-%m') AS cache_ym,
            " . rlu_sql_build_hipdata_select_sql() . ",
            COALESCE(NULLIF(TRIM(li.lab_items_group), ''), '__none__') AS group_code,
            CAST(CONVERT(COALESCE(lig.lab_items_group_name, 'ไม่ระบุกลุ่ม') USING utf8) AS CHAR) AS group_name,
            COUNT(*) AS item_count,
            SUM(" . rlu_sql_lab_line_price_expr() . ") AS total_price
        FROM lab_head lh
        INNER JOIN lab_order lo ON lo.lab_order_number = lh.lab_order_number
        LEFT JOIN lab_items li ON li.lab_items_code = lo.lab_items_code
        LEFT JOIN lab_items_group lig ON lig.lab_items_group_code = li.lab_items_group
        " . rlu_sql_build_hipdata_join_sql() . "
        WHERE lh.order_date >= '$start_esc'
          AND lh.order_date <= '$end_esc'
          AND lh.order_date <> '0000-00-00'
        GROUP BY cache_ym, " . rlu_sql_build_hipdata_group_prefix() . "group_code, group_name
    ";
    $t0 = microtime(true);
    $q = mysqli_query($hos, $sql);
    if (!$q) {
        return ['rows' => [], 'error' => mysqli_error($hos), 'elapsed_ms' => 0];
    }
    $rows = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $code = ($row['group_code'] ?? '') === '__none__' ? '' : trim($row['group_code'] ?? '');
        $rows[] = [
            'cache_ym' => trim($row['cache_ym'] ?? ''),
            'hipdata_code' => trim($row['hipdata_code'] ?? ''),
            'group_code' => $code,
            'group_name' => trim($row['group_name'] ?? 'ไม่ระบุกลุ่ม'),
            'item_count' => (int)($row['item_count'] ?? 0),
            'total_price' => (float)($row['total_price'] ?? 0),
        ];
    }
    return [
        'rows' => $rows,
        'error' => '',
        'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
    ];
}

function rlu_cache_month_exists($con, $year_month) {
    $ym = rlu_escape($con, $year_month);
    $q = mysqli_query($con, "SELECT COUNT(*) AS c FROM rational_lab_usage_monthly WHERE cache_ym='$ym' LIMIT 1");
    if (!$q) {
        return false;
    }
    $r = mysqli_fetch_assoc($q);
    return (int)($r['c'] ?? 0) > 0;
}

function rlu_item_cache_month_exists($con, $year_month) {
    rlu_ensure_item_cache_table($con);
    $ym = rlu_escape($con, $year_month);
    $q = mysqli_query($con, "SELECT COUNT(*) AS c FROM rational_lab_item_monthly WHERE cache_ym='$ym' LIMIT 1");
    if (!$q) {
        return false;
    }
    $r = mysqli_fetch_assoc($q);
    return (int)($r['c'] ?? 0) > 0;
}

/** cache รายการย่อยมีราคารวมหรือไม่ (กัน cache เก่าที่มีแต่จำนวน) */
function rlu_item_cache_month_has_prices($con, $year_month) {
    rlu_ensure_item_cache_table($con);
    $ym = rlu_escape($con, $year_month);
    $q = mysqli_query($con, "SELECT COALESCE(SUM(item_count),0) AS c, COALESCE(SUM(total_price),0) AS p
        FROM rational_lab_item_monthly WHERE cache_ym='$ym' AND item_code <> ''");
    if (!$q) {
        return false;
    }
    $r = mysqli_fetch_assoc($q);
    $c = (int)($r['c'] ?? 0);
    $p = (float)($r['p'] ?? 0);
    if ($c <= 0) {
        return true;
    }
    return $p > 0;
}

/** ดึงสถิติรายการ Lab ย่อย 1 เดือนจาก HOSxP (สำหรับ cache) */
function rlu_fetch_hosxp_month_item_stats($hos, $year_month) {
    $bounds = rlu_month_bounds($year_month);
    if ($bounds === null) {
        return ['rows' => [], 'error' => 'invalid month'];
    }
    $start_esc = mysqli_real_escape_string($hos, $bounds['start']);
    $end_esc = mysqli_real_escape_string($hos, $bounds['end']);
    $price_expr = rlu_sql_lab_line_price_expr();

    $sql = "
        SELECT
            " . rlu_sql_build_hipdata_select_sql() . ",
            lo.lab_items_code AS item_code,
            CAST(CONVERT(COALESCE(NULLIF(TRIM(lo.lab_items_name_ref), ''), li.lab_items_name, lo.lab_items_code) USING utf8) AS CHAR) AS item_name,
            COALESCE(NULLIF(TRIM(li.lab_items_group), ''), '') AS group_code,
            COUNT(*) AS item_count,
            SUM($price_expr) AS total_price
        FROM lab_head lh
        INNER JOIN lab_order lo ON lo.lab_order_number = lh.lab_order_number
        LEFT JOIN lab_items li ON li.lab_items_code = lo.lab_items_code
        " . rlu_sql_build_hipdata_join_sql() . "
        WHERE lh.order_date >= '$start_esc'
          AND lh.order_date <= '$end_esc'
          AND lh.order_date <> '0000-00-00'
        GROUP BY " . rlu_sql_build_hipdata_group_prefix() . "lo.lab_items_code, item_name, group_code
    ";

    $t0 = microtime(true);
    $q = mysqli_query($hos, $sql);
    if (!$q) {
        return ['rows' => [], 'error' => mysqli_error($hos), 'elapsed_ms' => 0];
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $rows[] = [
            'hipdata_code' => trim($row['hipdata_code'] ?? ''),
            'item_code' => trim($row['item_code'] ?? ''),
            'item_name' => trim($row['item_name'] ?? ''),
            'group_code' => trim($row['group_code'] ?? ''),
            'item_count' => (int)($row['item_count'] ?? 0),
            'total_price' => (float)($row['total_price'] ?? 0),
        ];
    }

    return [
        'rows' => $rows,
        'error' => '',
        'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
    ];
}

function rlu_fetch_hosxp_range_item_stats($hos, $start_date, $end_date) {
    $start_esc = mysqli_real_escape_string($hos, $start_date);
    $end_esc = mysqli_real_escape_string($hos, $end_date);
    $price_expr = rlu_sql_lab_line_price_expr();
    $sql = "
        SELECT
            DATE_FORMAT(lh.order_date, '%Y-%m') AS cache_ym,
            " . rlu_sql_build_hipdata_select_sql() . ",
            lo.lab_items_code AS item_code,
            CAST(CONVERT(COALESCE(NULLIF(TRIM(lo.lab_items_name_ref), ''), li.lab_items_name, lo.lab_items_code) USING utf8) AS CHAR) AS item_name,
            COALESCE(NULLIF(TRIM(li.lab_items_group), ''), '') AS group_code,
            COUNT(*) AS item_count,
            SUM($price_expr) AS total_price
        FROM lab_head lh
        INNER JOIN lab_order lo ON lo.lab_order_number = lh.lab_order_number
        LEFT JOIN lab_items li ON li.lab_items_code = lo.lab_items_code
        " . rlu_sql_build_hipdata_join_sql() . "
        WHERE lh.order_date >= '$start_esc'
          AND lh.order_date <= '$end_esc'
          AND lh.order_date <> '0000-00-00'
        GROUP BY cache_ym, " . rlu_sql_build_hipdata_group_prefix() . "lo.lab_items_code, item_name, group_code
    ";
    $t0 = microtime(true);
    $q = mysqli_query($hos, $sql);
    if (!$q) {
        return ['rows' => [], 'error' => mysqli_error($hos), 'elapsed_ms' => 0];
    }
    $rows = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $rows[] = [
            'cache_ym' => trim($row['cache_ym'] ?? ''),
            'hipdata_code' => trim($row['hipdata_code'] ?? ''),
            'item_code' => trim($row['item_code'] ?? ''),
            'item_name' => trim($row['item_name'] ?? ''),
            'group_code' => trim($row['group_code'] ?? ''),
            'item_count' => (int)($row['item_count'] ?? 0),
            'total_price' => (float)($row['total_price'] ?? 0),
        ];
    }
    return [
        'rows' => $rows,
        'error' => '',
        'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
    ];
}

function rlu_save_month_item_cache($con, $year_month, array $rows) {
    rlu_ensure_item_cache_table($con);
    $ym = rlu_escape($con, $year_month);
    mysqli_query($con, "DELETE FROM rational_lab_item_monthly WHERE cache_ym='$ym'");

    if (empty($rows)) {
        mysqli_query($con, "INSERT INTO rational_lab_item_monthly (cache_ym, hipdata_code, item_code, item_name, group_code, item_count, total_price)
            VALUES ('$ym', '', '', 'ไม่มีรายการ', '', 0, 0)");
        return true;
    }

    $tuples = [];
    foreach ($rows as $row) {
        $hip = rlu_escape($con, trim($row['hipdata_code'] ?? ''));
        $code = rlu_escape($con, $row['item_code'] ?? '');
        $name = rlu_escape($con, $row['item_name'] ?? '');
        $gc = rlu_escape($con, $row['group_code'] ?? '');
        $cnt = (int)($row['item_count'] ?? 0);
        $price = (float)($row['total_price'] ?? 0);
        $tuples[] = "('$ym', '$hip', '$code', '$name', '$gc', $cnt, $price)";
    }
    return rlu_bulk_insert_values($con, 'rational_lab_item_monthly',
        ['cache_ym', 'hipdata_code', 'item_code', 'item_name', 'group_code', 'item_count', 'total_price'],
        $tuples);
}

function rlu_build_month_item_cache($con, $hos, $year_month, $force = false) {
    rlu_ensure_item_cache_table($con);
    if (!$force && rlu_item_cache_month_exists($con, $year_month) && rlu_item_cache_month_has_prices($con, $year_month)) {
        return ['built' => false, 'cached' => true, 'year_month' => $year_month, 'elapsed_ms' => 0];
    }
    $stats = rlu_fetch_hosxp_month_item_stats($hos, $year_month);
    if ($stats['error'] !== '') {
        return ['built' => false, 'cached' => false, 'year_month' => $year_month, 'error' => $stats['error']];
    }
    rlu_save_month_item_cache($con, $year_month, $stats['rows']);
    return [
        'built' => true,
        'cached' => true,
        'year_month' => $year_month,
        'row_count' => count($stats['rows']),
        'elapsed_ms' => $stats['elapsed_ms'],
    ];
}

/** รวมรายการ Lab ย่อยจาก cache รายเดือน */
function rlu_fetch_item_stats_from_cache($con, $start_date, $end_date, $group_code = '__all__', array $options = []) {
    rlu_ensure_item_cache_table($con);
    $months = rlu_month_keys_in_period($start_date, $end_date);
    if (empty($months)) {
        return ['rows' => [], 'error' => '', 'elapsed_ms' => 0, 'source' => 'cache'];
    }

    $ym_min = rlu_escape($con, min($months));
    $ym_max = rlu_escape($con, max($months));
    $gc = trim((string)$group_code);
    $group_sql = '';
    if ($gc !== '' && $gc !== '__all__' && $gc !== '*') {
        if ($gc === '__none__') {
            $group_sql = " AND (group_code IS NULL OR TRIM(group_code) = '')";
        } else {
            $group_sql = " AND TRIM(group_code) = '" . rlu_escape($con, $gc) . "'";
        }
    }
    $hipdata_filter = rlu_sql_cache_hipdata_filter($con, $options['hipdata_codes'] ?? []);

    $sql = "
        SELECT
            item_code AS row_code,
            MAX(item_name) AS row_name,
            SUM(item_count) AS item_count,
            SUM(total_price) AS total_price
        FROM rational_lab_item_monthly
        WHERE cache_ym >= '$ym_min' AND cache_ym <= '$ym_max'
          AND item_code <> ''
          $group_sql
          $hipdata_filter
        GROUP BY item_code
        ORDER BY item_count DESC, row_name ASC
    ";
    $limit = (int)($options['limit'] ?? 0);
    if ($limit > 0) {
        $sql .= ' LIMIT ' . max(1, min(2000, $limit));
    }

    $t0 = microtime(true);
    $q = mysqli_query($con, $sql);
    if (!$q) {
        return ['rows' => [], 'error' => mysqli_error($con), 'elapsed_ms' => 0, 'source' => 'cache'];
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $code = trim($row['row_code'] ?? '');
        $rows[] = [
            'row_code' => $code,
            'row_name' => trim($row['row_name'] ?? ''),
            'item_code' => $code,
            'item_name' => trim($row['row_name'] ?? ''),
            'item_count' => (int)($row['item_count'] ?? 0),
            'total_price' => (float)($row['total_price'] ?? 0),
        ];
    }

    return [
        'rows' => $rows,
        'error' => '',
        'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
        'source' => 'cache',
        'dimension' => 'item',
    ];
}

function rlu_can_use_item_cache($group_code, array $options = []) {
    if (!empty($options['income_group_ids'])) {
        return false;
    }
    if (trim((string)($options['doctor_code'] ?? '')) !== '') {
        return false;
    }
    return true;
}

function rlu_item_cache_ready_for_period($con, $start_date, $end_date) {
    $months = rlu_month_keys_in_period($start_date, $end_date);
    foreach ($months as $ym) {
        if (!rlu_item_cache_month_exists($con, $ym)) {
            return false;
        }
        if (!rlu_item_cache_month_has_prices($con, $ym)) {
            return false;
        }
    }
    return !empty($months);
}

function rlu_list_missing_item_months($con, array $months) {
    rlu_ensure_item_cache_table($con);
    if (empty($months)) {
        return [];
    }
    $months = array_values(array_unique($months));
    sort($months);
    $escaped = [];
    foreach ($months as $ym) {
        $escaped[] = "'" . rlu_escape($con, $ym) . "'";
    }
    $existing = [];
    $q = mysqli_query($con, 'SELECT cache_ym FROM rational_lab_item_monthly WHERE cache_ym IN (' . implode(',', $escaped) . ') GROUP BY cache_ym');
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $existing[] = trim($row['cache_ym'] ?? '');
        }
    }
    $missing = [];
    foreach ($months as $ym) {
        if (!in_array($ym, $existing, true)) {
            $missing[] = $ym;
            continue;
        }
        if (!rlu_item_cache_month_has_prices($con, $ym)) {
            $missing[] = $ym;
        }
    }
    return $missing;
}

function rlu_doctor_cache_month_exists($con, $year_month) {
    rlu_ensure_doctor_cache_table($con);
    $ym = rlu_escape($con, $year_month);
    $q = mysqli_query($con, "SELECT COUNT(*) AS c FROM rational_lab_doctor_monthly WHERE cache_ym='$ym' LIMIT 1");
    if (!$q) {
        return false;
    }
    $r = mysqli_fetch_assoc($q);
    return (int)($r['c'] ?? 0) > 0;
}

/** cache แพทย์มีข้อมูลแยก VN/AN และ schema ล่าสุดหรือไม่ */
function rlu_doctor_cache_month_has_scopes($con, $year_month) {
    rlu_ensure_doctor_cache_table($con);
    $q = mysqli_query($con, "SHOW COLUMNS FROM rational_lab_doctor_monthly LIKE 'item_count_vn'");
    if (!$q || mysqli_num_rows($q) === 0) {
        return false;
    }
    $ym = rlu_escape($con, $year_month);
    $q2 = mysqli_query($con, "SELECT COALESCE(SUM(item_count),0) AS c,
        COALESCE(SUM(item_count_vn),0) + COALESCE(SUM(item_count_an),0) AS split_c,
        COALESCE(MAX(cache_schema_ver),0) AS schema_ver
        FROM rational_lab_doctor_monthly WHERE cache_ym='$ym'");
    if (!$q2) {
        return false;
    }
    $r = mysqli_fetch_assoc($q2);
    $c = (int)($r['c'] ?? 0);
    $split = (int)($r['split_c'] ?? 0);
    $schema_ver = (int)($r['schema_ver'] ?? 0);
    if ($schema_ver < RLU_DOCTOR_CACHE_SCHEMA_VER) {
        return false;
    }
    if ($c <= 0) {
        return true;
    }
    return $split > 0;
}

/** ดึงสถิติแพทย์ 1 เดือนจาก HOSxP (สำหรับ cache) — รวมแยก VN/AN */
function rlu_fetch_hosxp_month_doctor_stats($hos, $year_month) {
    $bounds = rlu_month_bounds($year_month);
    if ($bounds === null) {
        return ['rows' => [], 'error' => 'invalid month'];
    }
    $start_esc = mysqli_real_escape_string($hos, $bounds['start']);
    $end_esc = mysqli_real_escape_string($hos, $bounds['end']);
    $price_expr = rlu_sql_lab_line_price_expr();
    $doctor_code = rlu_sql_lab_doctor_code_expr();
    $vn_case = "lh.vn IS NOT NULL AND TRIM(lh.vn) <> '' AND (ip.an IS NULL OR TRIM(ip.an) = '')";
    $an_case = "ip.an IS NOT NULL AND TRIM(ip.an) <> ''";

    $doctor_name_expr = "CAST(CONVERT(
                COALESCE(
                    NULLIF(TRIM(doc.name), ''),
                    NULLIF(TRIM($doctor_code), ''),
                    'ไม่ระบุแพทย์'
                ) USING utf8) AS CHAR)";

    $sql = "
        SELECT
            " . rlu_sql_build_hipdata_select_sql() . ",
            COALESCE(NULLIF(TRIM($doctor_code), ''), '__none__') AS row_code,
            MAX($doctor_name_expr) AS row_name,
            COUNT(*) AS item_count,
            SUM($price_expr) AS total_price,
            SUM(CASE WHEN $vn_case THEN 1 ELSE 0 END) AS item_count_vn,
            SUM(CASE WHEN $vn_case THEN $price_expr ELSE 0 END) AS total_price_vn,
            SUM(CASE WHEN $an_case THEN 1 ELSE 0 END) AS item_count_an,
            SUM(CASE WHEN $an_case THEN $price_expr ELSE 0 END) AS total_price_an,
            COUNT(DISTINCT NULLIF(TRIM(lh.vn), '')) AS visit_count,
            COUNT(DISTINCT NULLIF(TRIM(ip.an), '')) AS an_count
        FROM lab_head lh
        INNER JOIN lab_order lo ON lo.lab_order_number = lh.lab_order_number
        LEFT JOIN lab_items li ON li.lab_items_code = lo.lab_items_code
        " . rlu_sql_lab_visit_joins() . "
        " . rlu_sql_lab_ipt_join() . "
        WHERE lh.order_date >= '$start_esc'
          AND lh.order_date <= '$end_esc'
          AND lh.order_date <> '0000-00-00'
        GROUP BY " . rlu_sql_build_hipdata_group_prefix() . "row_code
        ORDER BY item_count DESC, row_name ASC
    ";

    $t0 = microtime(true);
    $q = mysqli_query($hos, $sql);
    if (!$q) {
        return ['rows' => [], 'error' => mysqli_error($hos), 'elapsed_ms' => 0];
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $code = trim($row['row_code'] ?? '');
        if ($code === '__none__') {
            $code = '';
        }
        $rows[] = [
            'hipdata_code' => trim($row['hipdata_code'] ?? ''),
            'row_code' => $code,
            'row_name' => trim($row['row_name'] ?? ''),
            'item_count' => (int)($row['item_count'] ?? 0),
            'total_price' => (float)($row['total_price'] ?? 0),
            'item_count_vn' => (int)($row['item_count_vn'] ?? 0),
            'total_price_vn' => (float)($row['total_price_vn'] ?? 0),
            'item_count_an' => (int)($row['item_count_an'] ?? 0),
            'total_price_an' => (float)($row['total_price_an'] ?? 0),
            'visit_count' => (int)($row['visit_count'] ?? 0),
            'an_count' => (int)($row['an_count'] ?? 0),
        ];
    }

    return [
        'rows' => $rows,
        'error' => '',
        'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
        'dimension' => 'doctor',
    ];
}

function rlu_fetch_hosxp_range_doctor_stats($hos, $start_date, $end_date) {
    $start_esc = mysqli_real_escape_string($hos, $start_date);
    $end_esc = mysqli_real_escape_string($hos, $end_date);
    $price_expr = rlu_sql_lab_line_price_expr();
    $doctor_code = rlu_sql_lab_doctor_code_expr();
    $vn_case = "lh.vn IS NOT NULL AND TRIM(lh.vn) <> '' AND (ip.an IS NULL OR TRIM(ip.an) = '')";
    $an_case = "ip.an IS NOT NULL AND TRIM(ip.an) <> ''";
    $doctor_name_expr = "CAST(CONVERT(
                COALESCE(
                    NULLIF(TRIM(doc.name), ''),
                    NULLIF(TRIM($doctor_code), ''),
                    'ไม่ระบุแพทย์'
                ) USING utf8) AS CHAR)";
    $sql = "
        SELECT
            DATE_FORMAT(lh.order_date, '%Y-%m') AS cache_ym,
            " . rlu_sql_build_hipdata_select_sql() . ",
            COALESCE(NULLIF(TRIM($doctor_code), ''), '__none__') AS row_code,
            MAX($doctor_name_expr) AS row_name,
            COUNT(*) AS item_count,
            SUM($price_expr) AS total_price,
            SUM(CASE WHEN $vn_case THEN 1 ELSE 0 END) AS item_count_vn,
            SUM(CASE WHEN $vn_case THEN $price_expr ELSE 0 END) AS total_price_vn,
            SUM(CASE WHEN $an_case THEN 1 ELSE 0 END) AS item_count_an,
            SUM(CASE WHEN $an_case THEN $price_expr ELSE 0 END) AS total_price_an,
            COUNT(DISTINCT NULLIF(TRIM(lh.vn), '')) AS visit_count,
            COUNT(DISTINCT NULLIF(TRIM(ip.an), '')) AS an_count
        FROM lab_head lh
        INNER JOIN lab_order lo ON lo.lab_order_number = lh.lab_order_number
        LEFT JOIN lab_items li ON li.lab_items_code = lo.lab_items_code
        " . rlu_sql_lab_visit_joins() . "
        " . rlu_sql_lab_ipt_join() . "
        WHERE lh.order_date >= '$start_esc'
          AND lh.order_date <= '$end_esc'
          AND lh.order_date <> '0000-00-00'
        GROUP BY cache_ym, " . rlu_sql_build_hipdata_group_prefix() . "row_code
    ";
    $t0 = microtime(true);
    $q = mysqli_query($hos, $sql);
    if (!$q) {
        return ['rows' => [], 'error' => mysqli_error($hos), 'elapsed_ms' => 0];
    }
    $rows = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $code = trim($row['row_code'] ?? '');
        if ($code === '__none__') {
            $code = '';
        }
        $rows[] = [
            'cache_ym' => trim($row['cache_ym'] ?? ''),
            'hipdata_code' => trim($row['hipdata_code'] ?? ''),
            'row_code' => $code,
            'row_name' => trim($row['row_name'] ?? ''),
            'item_count' => (int)($row['item_count'] ?? 0),
            'total_price' => (float)($row['total_price'] ?? 0),
            'item_count_vn' => (int)($row['item_count_vn'] ?? 0),
            'total_price_vn' => (float)($row['total_price_vn'] ?? 0),
            'item_count_an' => (int)($row['item_count_an'] ?? 0),
            'total_price_an' => (float)($row['total_price_an'] ?? 0),
            'visit_count' => (int)($row['visit_count'] ?? 0),
            'an_count' => (int)($row['an_count'] ?? 0),
        ];
    }
    return [
        'rows' => $rows,
        'error' => '',
        'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
        'dimension' => 'doctor',
    ];
}

function rlu_save_month_doctor_cache($con, $year_month, array $rows) {
    rlu_ensure_doctor_cache_table($con);
    $ym = rlu_escape($con, $year_month);
    mysqli_query($con, "DELETE FROM rational_lab_doctor_monthly WHERE cache_ym='$ym'");

    if (empty($rows)) {
        mysqli_query($con, "INSERT INTO rational_lab_doctor_monthly
            (cache_ym, hipdata_code, doctor_code, doctor_name, item_count, total_price, item_count_vn, total_price_vn, item_count_an, total_price_an, visit_count, an_count, cache_schema_ver)
            VALUES ('$ym', '', '', 'ไม่มีแพทย์', 0, 0, 0, 0, 0, 0, 0, 0, " . RLU_DOCTOR_CACHE_SCHEMA_VER . ")");
        return true;
    }

    $schema_ver = RLU_DOCTOR_CACHE_SCHEMA_VER;
    $tuples = [];
    foreach ($rows as $row) {
        $hip = rlu_escape($con, trim($row['hipdata_code'] ?? ''));
        $code = rlu_escape($con, $row['row_code'] ?? '');
        $name = rlu_escape($con, $row['row_name'] ?? '');
        $cnt = (int)($row['item_count'] ?? 0);
        $price = (float)($row['total_price'] ?? 0);
        $cnt_vn = (int)($row['item_count_vn'] ?? 0);
        $price_vn = (float)($row['total_price_vn'] ?? 0);
        $cnt_an = (int)($row['item_count_an'] ?? 0);
        $price_an = (float)($row['total_price_an'] ?? 0);
        $vn = (int)($row['visit_count'] ?? 0);
        $an = (int)($row['an_count'] ?? 0);
        $tuples[] = "('$ym', '$hip', '$code', '$name', $cnt, $price, $cnt_vn, $price_vn, $cnt_an, $price_an, $vn, $an, $schema_ver)";
    }
    return rlu_bulk_insert_values($con, 'rational_lab_doctor_monthly',
        ['cache_ym', 'hipdata_code', 'doctor_code', 'doctor_name', 'item_count', 'total_price', 'item_count_vn', 'total_price_vn', 'item_count_an', 'total_price_an', 'visit_count', 'an_count', 'cache_schema_ver'],
        $tuples);
}

function rlu_build_month_doctor_cache($con, $hos, $year_month, $force = false) {
    rlu_ensure_doctor_cache_table($con);
    if (!$force && rlu_doctor_cache_month_exists($con, $year_month) && rlu_doctor_cache_month_has_scopes($con, $year_month)) {
        return ['built' => false, 'cached' => true, 'year_month' => $year_month, 'elapsed_ms' => 0];
    }
    $stats = rlu_fetch_hosxp_month_doctor_stats($hos, $year_month);
    if (($stats['error'] ?? '') !== '') {
        return ['built' => false, 'cached' => false, 'year_month' => $year_month, 'error' => $stats['error']];
    }
    rlu_save_month_doctor_cache($con, $year_month, $stats['rows']);
    return [
        'built' => true,
        'cached' => true,
        'year_month' => $year_month,
        'row_count' => count($stats['rows']),
        'elapsed_ms' => (int)($stats['elapsed_ms'] ?? 0),
    ];
}

/** รวมแพทย์จาก cache รายเดือน */
function rlu_fetch_doctor_stats_from_cache($con, $start_date, $end_date, array $options = []) {
    rlu_ensure_doctor_cache_table($con);
    $months = rlu_month_keys_in_period($start_date, $end_date);
    if (empty($months)) {
        return ['rows' => [], 'error' => '', 'elapsed_ms' => 0, 'source' => 'cache', 'dimension' => 'doctor'];
    }

    $ym_min = rlu_escape($con, min($months));
    $ym_max = rlu_escape($con, max($months));
    $scope = rlu_normalize_visit_scope($options['visit_scope'] ?? '');
    if ($scope === 'vn') {
        $cnt_expr = 'SUM(item_count_vn)';
        $price_expr = 'SUM(total_price_vn)';
    } elseif ($scope === 'an') {
        $cnt_expr = 'SUM(item_count_an)';
        $price_expr = 'SUM(total_price_an)';
    } else {
        $cnt_expr = 'SUM(item_count)';
        $price_expr = 'SUM(total_price)';
    }
    $hipdata_filter = rlu_sql_cache_hipdata_filter($con, $options['hipdata_codes'] ?? []);
    $sql = "
        SELECT
            doctor_code AS row_code,
            MAX(doctor_name) AS row_name,
            $cnt_expr AS item_count,
            $price_expr AS total_price,
            SUM(visit_count) AS visit_count,
            SUM(an_count) AS an_count
        FROM rational_lab_doctor_monthly
        WHERE cache_ym >= '$ym_min' AND cache_ym <= '$ym_max'
          $hipdata_filter
        GROUP BY doctor_code
        HAVING item_count > 0 OR total_price > 0
        ORDER BY item_count DESC, row_name ASC
    ";
    $limit = (int)($options['limit'] ?? 0);
    if ($limit > 0) {
        $sql .= ' LIMIT ' . max(1, min(2000, $limit));
    }

    $t0 = microtime(true);
    $q = mysqli_query($con, $sql);
    if (!$q) {
        return ['rows' => [], 'error' => mysqli_error($con), 'elapsed_ms' => 0, 'source' => 'cache', 'dimension' => 'doctor'];
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $code = trim($row['row_code'] ?? '');
        $rows[] = [
            'row_code' => $code,
            'row_name' => trim($row['row_name'] ?? ''),
            'item_code' => $code,
            'item_name' => trim($row['row_name'] ?? ''),
            'item_count' => (int)($row['item_count'] ?? 0),
            'total_price' => (float)($row['total_price'] ?? 0),
            'visit_count' => (int)($row['visit_count'] ?? 0),
            'an_count' => (int)($row['an_count'] ?? 0),
        ];
    }

    return [
        'rows' => $rows,
        'error' => '',
        'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
        'source' => 'cache',
        'dimension' => 'doctor',
    ];
}

function rlu_is_all_lab_groups($group_code) {
    $gc = trim((string)$group_code);
    return $gc === '' || $gc === '__all__' || $gc === '*';
}

/** เปิดกลุ่ม Lab เฉพาะ — cache แผนก/แพทย์ไม่แยกกลุ่ม ต้องดึงจาก HOSxP */
function rlu_group_detail_needs_hosxp($group_code, array $options = []) {
    if (rlu_is_all_lab_groups($group_code)) {
        return false;
    }
    if (trim((string)($options['doctor_code'] ?? '')) !== '') {
        return true;
    }
    if (trim((string)($options['item_code'] ?? '')) !== '') {
        return true;
    }
    return true;
}

function rlu_can_use_doctor_cache($group_code, array $options = []) {
    if (!empty($options['income_group_ids'])) {
        return false;
    }
    if (trim((string)($options['doctor_code'] ?? '')) !== '') {
        return false;
    }
    if (trim((string)($options['item_code'] ?? '')) !== '') {
        return false;
    }
    $gc = trim((string)$group_code);
    return $gc === '__all__' || $gc === '*' || $gc === '';
}

function rlu_doctor_cache_ready_for_period($con, $start_date, $end_date) {
    $months = rlu_month_keys_in_period($start_date, $end_date);
    foreach ($months as $ym) {
        if (!rlu_doctor_cache_month_exists($con, $ym)) {
            return false;
        }
    }
    return !empty($months);
}

function rlu_list_missing_doctor_months($con, array $months) {
    rlu_ensure_doctor_cache_table($con);
    if (empty($months)) {
        return [];
    }
    $months = array_values(array_unique($months));
    sort($months);
    $escaped = [];
    foreach ($months as $ym) {
        $escaped[] = "'" . rlu_escape($con, $ym) . "'";
    }
    $existing = [];
    $q = mysqli_query($con, 'SELECT cache_ym FROM rational_lab_doctor_monthly WHERE cache_ym IN (' . implode(',', $escaped) . ') GROUP BY cache_ym');
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $existing[] = trim($row['cache_ym'] ?? '');
        }
    }
    $missing = [];
    foreach ($months as $ym) {
        if (!in_array($ym, $existing, true)) {
            $missing[] = $ym;
        }
    }
    return $missing;
}

function rlu_dept_cache_month_exists($con, $year_month) {
    rlu_ensure_dept_cache_table($con);
    $ym = rlu_escape($con, $year_month);
    $q = mysqli_query($con, "SELECT COUNT(*) AS c FROM rational_lab_dept_monthly WHERE cache_ym='$ym' LIMIT 1");
    if (!$q) {
        return false;
    }
    $r = mysqli_fetch_assoc($q);
    return (int)($r['c'] ?? 0) > 0;
}

/** ดึงสถิติแผนก 1 เดือนจาก HOSxP (สำหรับ cache) */
function rlu_fetch_hosxp_month_dept_stats($hos, $year_month) {
    $bounds = rlu_month_bounds($year_month);
    if ($bounds === null) {
        return ['rows' => [], 'error' => 'invalid month'];
    }
    $start_esc = mysqli_real_escape_string($hos, $bounds['start']);
    $end_esc = mysqli_real_escape_string($hos, $bounds['end']);
    $price_expr = rlu_sql_lab_line_price_expr();
    $dept_code = rlu_sql_lab_dept_code_expr();
    $dept_name = rlu_sql_lab_dept_name_expr();

    $sql = "
        SELECT
            " . rlu_sql_build_hipdata_select_sql() . ",
            COALESCE(NULLIF(TRIM($dept_code), ''), '__none__') AS row_code,
            MAX($dept_name) AS row_name,
            0 AS income_group2,
            COUNT(*) AS item_count,
            SUM($price_expr) AS total_price
        FROM lab_head lh
        INNER JOIN lab_order lo ON lo.lab_order_number = lh.lab_order_number
        LEFT JOIN lab_items li ON li.lab_items_code = lo.lab_items_code
        " . rlu_sql_lab_dept_visit_joins() . "
        WHERE lh.order_date >= '$start_esc'
          AND lh.order_date <= '$end_esc'
          AND lh.order_date <> '0000-00-00'
        GROUP BY " . rlu_sql_build_hipdata_group_prefix() . "row_code
        ORDER BY item_count DESC, row_name ASC
    ";

    $t0 = microtime(true);
    $q = mysqli_query($hos, $sql);
    if (!$q) {
        return ['rows' => [], 'error' => mysqli_error($hos), 'elapsed_ms' => 0];
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $code = trim($row['row_code'] ?? '');
        if ($code === '__none__') {
            $code = '';
        }
        $rows[] = [
            'hipdata_code' => trim($row['hipdata_code'] ?? ''),
            'row_code' => $code,
            'row_name' => trim($row['row_name'] ?? ''),
            'income_group2' => (int)($row['income_group2'] ?? 0),
            'item_count' => (int)($row['item_count'] ?? 0),
            'total_price' => (float)($row['total_price'] ?? 0),
        ];
    }

    return [
        'rows' => $rows,
        'error' => '',
        'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
        'dimension' => 'dept',
    ];
}

function rlu_fetch_hosxp_range_dept_stats($hos, $start_date, $end_date) {
    $start_esc = mysqli_real_escape_string($hos, $start_date);
    $end_esc = mysqli_real_escape_string($hos, $end_date);
    $price_expr = rlu_sql_lab_line_price_expr();
    $dept_code = rlu_sql_lab_dept_code_expr();
    $dept_name = rlu_sql_lab_dept_name_expr();
    $sql = "
        SELECT
            DATE_FORMAT(lh.order_date, '%Y-%m') AS cache_ym,
            " . rlu_sql_build_hipdata_select_sql() . ",
            COALESCE(NULLIF(TRIM($dept_code), ''), '__none__') AS row_code,
            MAX($dept_name) AS row_name,
            0 AS income_group2,
            COUNT(*) AS item_count,
            SUM($price_expr) AS total_price
        FROM lab_head lh
        INNER JOIN lab_order lo ON lo.lab_order_number = lh.lab_order_number
        LEFT JOIN lab_items li ON li.lab_items_code = lo.lab_items_code
        " . rlu_sql_lab_dept_visit_joins() . "
        WHERE lh.order_date >= '$start_esc'
          AND lh.order_date <= '$end_esc'
          AND lh.order_date <> '0000-00-00'
        GROUP BY cache_ym, " . rlu_sql_build_hipdata_group_prefix() . "row_code
    ";
    $t0 = microtime(true);
    $q = mysqli_query($hos, $sql);
    if (!$q) {
        return ['rows' => [], 'error' => mysqli_error($hos), 'elapsed_ms' => 0];
    }
    $rows = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $code = trim($row['row_code'] ?? '');
        if ($code === '__none__') {
            $code = '';
        }
        $rows[] = [
            'cache_ym' => trim($row['cache_ym'] ?? ''),
            'hipdata_code' => trim($row['hipdata_code'] ?? ''),
            'row_code' => $code,
            'row_name' => trim($row['row_name'] ?? ''),
            'income_group2' => (int)($row['income_group2'] ?? 0),
            'item_count' => (int)($row['item_count'] ?? 0),
            'total_price' => (float)($row['total_price'] ?? 0),
        ];
    }
    return [
        'rows' => $rows,
        'error' => '',
        'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
        'dimension' => 'dept',
    ];
}

function rlu_save_month_dept_cache($con, $year_month, array $rows) {
    rlu_ensure_dept_cache_table($con);
    $ym = rlu_escape($con, $year_month);
    mysqli_query($con, "DELETE FROM rational_lab_dept_monthly WHERE cache_ym='$ym'");

    if (empty($rows)) {
        mysqli_query($con, "INSERT INTO rational_lab_dept_monthly
            (cache_ym, hipdata_code, dept_code, dept_name, income_group2, item_count, total_price)
            VALUES ('$ym', '', '', 'ไม่ระบุแผนก', 0, 0, 0)");
        return true;
    }

    $tuples = [];
    foreach ($rows as $row) {
        $hip = rlu_escape($con, trim($row['hipdata_code'] ?? ''));
        $code = rlu_escape($con, $row['row_code'] ?? '');
        $name = rlu_escape($con, $row['row_name'] ?? '');
        $income = (int)($row['income_group2'] ?? 0);
        $cnt = (int)($row['item_count'] ?? 0);
        $price = (float)($row['total_price'] ?? 0);
        $tuples[] = "('$ym', '$hip', '$code', '$name', $income, $cnt, $price)";
    }
    return rlu_bulk_insert_values($con, 'rational_lab_dept_monthly',
        ['cache_ym', 'hipdata_code', 'dept_code', 'dept_name', 'income_group2', 'item_count', 'total_price'],
        $tuples);
}

function rlu_build_month_dept_cache($con, $hos, $year_month, $force = false) {
    rlu_ensure_dept_cache_table($con);
    if (!$force && rlu_dept_cache_month_exists($con, $year_month)) {
        return ['built' => false, 'cached' => true, 'year_month' => $year_month, 'elapsed_ms' => 0];
    }
    $stats = rlu_fetch_hosxp_month_dept_stats($hos, $year_month);
    if (($stats['error'] ?? '') !== '') {
        return ['built' => false, 'cached' => false, 'year_month' => $year_month, 'error' => $stats['error']];
    }
    rlu_save_month_dept_cache($con, $year_month, $stats['rows']);
    return [
        'built' => true,
        'cached' => true,
        'year_month' => $year_month,
        'row_count' => count($stats['rows']),
        'elapsed_ms' => (int)($stats['elapsed_ms'] ?? 0),
    ];
}

/** รวมแผนกจาก cache รายเดือน — รองรับกรอง income.group2 */
function rlu_fetch_dept_stats_from_cache($con, $start_date, $end_date, array $options = []) {
    rlu_ensure_dept_cache_table($con);
    $months = rlu_month_keys_in_period($start_date, $end_date);
    if (empty($months)) {
        return ['rows' => [], 'error' => '', 'elapsed_ms' => 0, 'source' => 'cache', 'dimension' => 'dept'];
    }

    $ym_min = rlu_escape($con, min($months));
    $ym_max = rlu_escape($con, max($months));
    $income_ids = rlu_parse_income_group_ids($options['income_group_ids'] ?? []);
    $income_filter = '';
    if (!empty($income_ids)) {
        $income_filter = ' AND income_group2 IN (' . implode(',', $income_ids) . ')';
    }
    $hipdata_filter = rlu_sql_cache_hipdata_filter($con, $options['hipdata_codes'] ?? []);

    $sql = "
        SELECT
            dept_code AS row_code,
            MAX(dept_name) AS row_name,
            SUM(item_count) AS item_count,
            SUM(total_price) AS total_price
        FROM rational_lab_dept_monthly
        WHERE cache_ym >= '$ym_min' AND cache_ym <= '$ym_max'
        $income_filter
        $hipdata_filter
        GROUP BY dept_code
        HAVING item_count > 0 OR total_price > 0
        ORDER BY item_count DESC, row_name ASC
    ";
    $limit = (int)($options['limit'] ?? 0);
    if ($limit > 0) {
        $sql .= ' LIMIT ' . max(1, min(2000, $limit));
    }

    $t0 = microtime(true);
    $q = mysqli_query($con, $sql);
    if (!$q) {
        return ['rows' => [], 'error' => mysqli_error($con), 'elapsed_ms' => 0, 'source' => 'cache', 'dimension' => 'dept'];
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $code = trim($row['row_code'] ?? '');
        $rows[] = [
            'row_code' => $code,
            'row_name' => trim($row['row_name'] ?? ''),
            'item_code' => $code,
            'item_name' => trim($row['row_name'] ?? ''),
            'item_count' => (int)($row['item_count'] ?? 0),
            'total_price' => (float)($row['total_price'] ?? 0),
        ];
    }

    return [
        'rows' => $rows,
        'error' => '',
        'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
        'source' => 'cache',
        'dimension' => 'dept',
    ];
}

function rlu_can_use_dept_cache($group_code, array $options = []) {
    if (trim((string)($options['doctor_code'] ?? '')) !== '') {
        return false;
    }
    if (trim((string)($options['item_code'] ?? '')) !== '') {
        return false;
    }
    $gc = trim((string)$group_code);
    return $gc === '__all__' || $gc === '*' || $gc === '';
}

function rlu_dept_cache_ready_for_period($con, $start_date, $end_date) {
    $months = rlu_month_keys_in_period($start_date, $end_date);
    foreach ($months as $ym) {
        if (!rlu_dept_cache_month_exists($con, $ym)) {
            return false;
        }
    }
    return !empty($months);
}

function rlu_list_missing_dept_months($con, array $months) {
    rlu_ensure_dept_cache_table($con);
    if (empty($months)) {
        return [];
    }
    $months = array_values(array_unique($months));
    sort($months);
    $escaped = [];
    foreach ($months as $ym) {
        $escaped[] = "'" . rlu_escape($con, $ym) . "'";
    }
    $existing = [];
    $q = mysqli_query($con, 'SELECT cache_ym FROM rational_lab_dept_monthly WHERE cache_ym IN (' . implode(',', $escaped) . ') GROUP BY cache_ym');
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $existing[] = trim($row['cache_ym'] ?? '');
        }
    }
    $missing = [];
    foreach ($months as $ym) {
        if (!in_array($ym, $existing, true)) {
            $missing[] = $ym;
        }
    }
    return $missing;
}

/** บันทึก cache 1 เดือน — ลบเดิมแล้ว insert ใหม่ */
function rlu_save_month_cache($con, $year_month, array $rows) {
    rlu_ensure_cache_table($con);
    $ym = rlu_escape($con, $year_month);
    mysqli_query($con, "DELETE FROM rational_lab_usage_monthly WHERE cache_ym='$ym'");

    if (empty($rows)) {
        mysqli_query($con, "INSERT INTO rational_lab_usage_monthly (cache_ym, hipdata_code, group_code, group_name, item_count, total_price)
            VALUES ('$ym', '', '', 'ไม่มีรายการ', 0, 0)");
        return true;
    }

    $tuples = [];
    foreach ($rows as $row) {
        $hip = rlu_escape($con, trim($row['hipdata_code'] ?? ''));
        $code = rlu_escape($con, $row['group_code'] ?? '');
        $name = rlu_escape($con, $row['group_name'] ?? 'ไม่ระบุกลุ่ม');
        $cnt = (int)($row['item_count'] ?? 0);
        $price = (float)($row['total_price'] ?? 0);
        $tuples[] = "('$ym', '$hip', '$code', '$name', $cnt, $price)";
    }
    return rlu_bulk_insert_values($con, 'rational_lab_usage_monthly',
        ['cache_ym', 'hipdata_code', 'group_code', 'group_name', 'item_count', 'total_price'],
        $tuples);
}

/** สร้าง cache สรุปกลุ่ม Lab รายเดือน (โหลดหน้าแรกใช้เฉพาะส่วนนี้) */
function rlu_build_month_usage_cache($con, $hos, $year_month, $force = false) {
    rlu_ensure_cache_table($con);
    if (!$force && rlu_cache_month_exists($con, $year_month)) {
        return ['built' => false, 'cached' => true, 'year_month' => $year_month, 'elapsed_ms' => 0, 'scope' => 'usage'];
    }
    $stats = rlu_fetch_hosxp_month_stats($hos, $year_month);
    if ($stats['error'] !== '') {
        return ['built' => false, 'cached' => false, 'year_month' => $year_month, 'error' => $stats['error'], 'scope' => 'usage'];
    }
    rlu_save_month_cache($con, $year_month, $stats['rows']);
    return [
        'built' => true,
        'cached' => true,
        'year_month' => $year_month,
        'row_count' => count($stats['rows']),
        'elapsed_ms' => (int)($stats['elapsed_ms'] ?? 0),
        'scope' => 'usage',
    ];
}

/** สร้าง cache เดือนเดียวจาก HOSxP — scope: usage|item|doctor|dept|all (default usage) */
function rlu_build_month_cache($con, $hos, $year_month, $force = false, $scope = 'usage') {
    $scope = rlu_normalize_build_scope($scope);
    if ($scope === 'usage') {
        return rlu_build_month_usage_cache($con, $hos, $year_month, $force);
    }
    if ($scope === 'item') {
        $result = rlu_build_month_item_cache($con, $hos, $year_month, $force);
        $result['scope'] = 'item';
        return $result;
    }
    if ($scope === 'doctor') {
        $result = rlu_build_month_doctor_cache($con, $hos, $year_month, $force);
        $result['scope'] = 'doctor';
        return $result;
    }
    if ($scope === 'dept') {
        $result = rlu_build_month_dept_cache($con, $hos, $year_month, $force);
        $result['scope'] = 'dept';
        return $result;
    }

    rlu_ensure_cache_table($con);
    if (!$force && rlu_cache_month_exists($con, $year_month) && rlu_item_cache_month_exists($con, $year_month) && rlu_item_cache_month_has_prices($con, $year_month) && rlu_doctor_cache_month_exists($con, $year_month) && rlu_doctor_cache_month_has_scopes($con, $year_month) && rlu_dept_cache_month_exists($con, $year_month)) {
        return ['built' => false, 'cached' => true, 'year_month' => $year_month, 'elapsed_ms' => 0, 'scope' => 'all'];
    }
    if (!$force && rlu_cache_month_exists($con, $year_month) && (!rlu_item_cache_month_exists($con, $year_month) || !rlu_item_cache_month_has_prices($con, $year_month))) {
        $item_result = rlu_build_month_item_cache($con, $hos, $year_month, false);
        if (!empty($item_result['error'])) {
            return ['built' => false, 'cached' => false, 'year_month' => $year_month, 'error' => $item_result['error']];
        }
        return [
            'built' => (bool)($item_result['built'] ?? false),
            'cached' => true,
            'year_month' => $year_month,
            'item_row_count' => (int)($item_result['row_count'] ?? 0),
            'elapsed_ms' => (int)($item_result['elapsed_ms'] ?? 0),
            'item_only' => true,
        ];
    }
    if (!$force && rlu_cache_month_exists($con, $year_month) && !rlu_doctor_cache_month_exists($con, $year_month)) {
        $doctor_result = rlu_build_month_doctor_cache($con, $hos, $year_month, false);
        if (!empty($doctor_result['error'])) {
            return ['built' => false, 'cached' => false, 'year_month' => $year_month, 'error' => $doctor_result['error']];
        }
        return [
            'built' => (bool)($doctor_result['built'] ?? false),
            'cached' => true,
            'year_month' => $year_month,
            'doctor_row_count' => (int)($doctor_result['row_count'] ?? 0),
            'elapsed_ms' => (int)($doctor_result['elapsed_ms'] ?? 0),
            'doctor_only' => true,
        ];
    }
    if (!$force && rlu_cache_month_exists($con, $year_month) && !rlu_dept_cache_month_exists($con, $year_month)) {
        $dept_result = rlu_build_month_dept_cache($con, $hos, $year_month, false);
        if (!empty($dept_result['error'])) {
            return ['built' => false, 'cached' => false, 'year_month' => $year_month, 'error' => $dept_result['error']];
        }
        return [
            'built' => (bool)($dept_result['built'] ?? false),
            'cached' => true,
            'year_month' => $year_month,
            'dept_row_count' => (int)($dept_result['row_count'] ?? 0),
            'elapsed_ms' => (int)($dept_result['elapsed_ms'] ?? 0),
            'dept_only' => true,
        ];
    }
    $stats = rlu_fetch_hosxp_month_stats($hos, $year_month);
    if ($stats['error'] !== '') {
        return ['built' => false, 'cached' => false, 'year_month' => $year_month, 'error' => $stats['error']];
    }
    rlu_save_month_cache($con, $year_month, $stats['rows']);
    $item_result = rlu_build_month_item_cache($con, $hos, $year_month, $force);
    $doctor_result = rlu_build_month_doctor_cache($con, $hos, $year_month, $force);
    $dept_result = rlu_build_month_dept_cache($con, $hos, $year_month, $force);
    return [
        'built' => true,
        'cached' => true,
        'year_month' => $year_month,
        'row_count' => count($stats['rows']),
        'item_row_count' => (int)($item_result['row_count'] ?? 0),
        'doctor_row_count' => (int)($doctor_result['row_count'] ?? 0),
        'dept_row_count' => (int)($dept_result['row_count'] ?? 0),
        'elapsed_ms' => (int)($stats['elapsed_ms'] ?? 0) + (int)($item_result['elapsed_ms'] ?? 0) + (int)($doctor_result['elapsed_ms'] ?? 0) + (int)($dept_result['elapsed_ms'] ?? 0),
        'item_error' => $item_result['error'] ?? '',
        'doctor_error' => $doctor_result['error'] ?? '',
        'dept_error' => $dept_result['error'] ?? '',
        'scope' => 'all',
    ];
}

/** เดือนที่ยังไม่มี cache สรุปกลุ่ม Lab (ใช้ก่อนโหลดหน้าแรก — ไม่รอ item/doctor/dept) */
function rlu_list_missing_usage_months($con, array $months) {
    rlu_ensure_cache_table($con);
    if (empty($months)) {
        return [];
    }
    $months = array_values(array_unique($months));
    sort($months);
    $missing = [];
    foreach ($months as $ym) {
        if (!rlu_cache_month_exists($con, $ym)) {
            $missing[] = $ym;
        }
    }
    return $missing;
}

function rlu_list_missing_months($con, array $months) {
    return rlu_list_missing_usage_months($con, $months);
}

function rlu_months_needing_build($con, array $months, $scope, $force = false) {
    $scope = rlu_normalize_build_scope($scope);
    if ($force) {
        return array_values(array_unique($months));
    }
    if ($scope === 'item') {
        return rlu_list_missing_item_months($con, $months);
    }
    if ($scope === 'doctor') {
        return rlu_list_missing_doctor_months($con, $months);
    }
    if ($scope === 'dept') {
        return rlu_list_missing_dept_months($con, $months);
    }
    return rlu_list_missing_usage_months($con, $months);
}

/** ดึง HOSxP ช่วงวันที่แล้วแยกบันทึก cache หลายเดือนในครั้งเดียว */
function rlu_build_months_cache_batch($con, &$hos, array $months, $force = false, $scope = 'usage') {
    $scope = rlu_normalize_build_scope($scope);
    $months = rlu_parse_year_month_list($months);
    if (empty($months)) {
        return ['built' => false, 'cached' => false, 'error' => 'ไม่มีเดือนที่ต้องประมวลผล', 'scope' => $scope];
    }
    $to_build = rlu_months_needing_build($con, $months, $scope, $force);
    if (empty($to_build)) {
        return [
            'built' => false,
            'cached' => true,
            'months' => $months,
            'built_months' => [],
            'skipped_months' => $months,
            'elapsed_ms' => 0,
            'scope' => $scope,
            'batch' => true,
        ];
    }

    $t0 = microtime(true);
    $built_months = [];
    $chunk_size = rlu_build_batch_chunk_months($scope);
    $fetch_map = [
        'usage' => 'rlu_fetch_hosxp_range_stats',
        'item' => 'rlu_fetch_hosxp_range_item_stats',
        'doctor' => 'rlu_fetch_hosxp_range_doctor_stats',
        'dept' => 'rlu_fetch_hosxp_range_dept_stats',
    ];
    $save_map = [
        'usage' => 'rlu_save_month_cache',
        'item' => 'rlu_save_month_item_cache',
        'doctor' => 'rlu_save_month_doctor_cache',
        'dept' => 'rlu_save_month_dept_cache',
    ];
    $fetch_fn = $fetch_map[$scope] ?? 'rlu_fetch_hosxp_range_stats';
    $save_fn = $save_map[$scope] ?? 'rlu_save_month_cache';

    foreach (array_chunk($to_build, $chunk_size) as $chunk) {
        if (!hosxp_ensure_connection($con, $hos, 600)) {
            return ['built' => false, 'cached' => false, 'error' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้', 'built_months' => $built_months, 'scope' => $scope, 'batch' => true];
        }
        $bounds = rlu_months_date_bounds($chunk);
        if ($bounds === null) {
            return ['built' => false, 'cached' => false, 'error' => 'รูปแบบเดือนไม่ถูกต้อง', 'scope' => $scope];
        }
        $stats = $fetch_fn($hos, $bounds['start'], $bounds['end']);
        if (($stats['error'] ?? '') !== '') {
            if (stripos($stats['error'], 'gone away') !== false && hosxp_ensure_connection($con, $hos, 600)) {
                $stats = $fetch_fn($hos, $bounds['start'], $bounds['end']);
            }
        }
        if (($stats['error'] ?? '') !== '') {
            return [
                'built' => false,
                'cached' => false,
                'error' => $stats['error'],
                'built_months' => $built_months,
                'scope' => $scope,
                'batch' => true,
            ];
        }
        $by_ym = rlu_partition_rows_by_cache_ym($stats['rows'] ?? []);
        unset($stats);
        foreach ($chunk as $ym) {
            $month_rows = $by_ym[$ym] ?? [];
            $saved = $save_fn($con, $ym, $month_rows);
            if ($saved === false) {
                return [
                    'built' => false,
                    'cached' => false,
                    'error' => 'บันทึก cache ไม่สำเร็จ: ' . $ym . ' (' . mysqli_error($con) . ')',
                    'built_months' => $built_months,
                    'scope' => $scope,
                    'batch' => true,
                ];
            }
            $built_months[] = $ym;
        }
        unset($by_ym);
    }

    return [
        'built' => true,
        'cached' => true,
        'months' => $months,
        'built_months' => $built_months,
        'built_count' => count($built_months),
        'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
        'scope' => $scope,
        'batch' => true,
    ];
}

/** รวมเดือนที่ต้องมี cache ทั้งช่วงหลักและช่วงเปรียบเทียบ */
function rlu_is_peer_compare_mode($compare_mode, array $params = []) {
    $compare_mode = trim((string)$compare_mode);
    if ($compare_mode === 'month') {
        $raw = $params['compare_months'] ?? '';
        if ($raw === '' && !empty($params['compare_month'])) {
            $raw = (string)$params['compare_month'];
        }
        $parts = $raw === '' ? [] : preg_split('/[,\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        return count($parts) >= 2;
    }
    if ($compare_mode === 'year') {
        $raw = $params['compare_years'] ?? '';
        if ($raw === '' && !empty($params['compare_year'])) {
            $raw = (string)$params['compare_year'];
        }
        $parts = $raw === '' ? [] : preg_split('/[,\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        return count($parts) >= 2;
    }
    return false;
}

function rlu_collect_all_required_months($start_date, $end_date, $compare_enabled, $compare_mode, array $params = []) {
    $peer = $compare_enabled && rlu_is_peer_compare_mode($compare_mode, $params);
    $months = $peer ? [] : rlu_month_keys_in_period($start_date, $end_date);
    if (!$compare_enabled) {
        return rlu_month_keys_in_period($start_date, $end_date);
    }
    $compare_mode = trim((string)$compare_mode);
    if ($compare_mode === 'year') {
        $raw = $params['compare_years'] ?? '';
        if ($raw === '' && !empty($params['compare_year'])) {
            $raw = (string)$params['compare_year'];
        }
        foreach (rlu_parse_compare_years($raw, $start_date) as $yr) {
            $period_b = rlu_shift_period_to_year($start_date, $end_date, $yr);
            $months = array_merge($months, rlu_month_keys_in_period($period_b['start_date'], $period_b['end_date']));
        }
    } elseif ($compare_mode === 'month') {
        $raw = $params['compare_months'] ?? '';
        if ($raw === '' && !empty($params['compare_month'])) {
            $raw = (string)$params['compare_month'];
        }
        foreach (rlu_parse_compare_months($raw, $end_date) as $ym) {
            $period_b = rlu_shift_period_to_month($start_date, $end_date, $ym);
            $months = array_merge($months, rlu_month_keys_in_period($period_b['start_date'], $period_b['end_date']));
        }
    } elseif ($compare_mode === 'custom' && !empty($params['compare_start']) && !empty($params['compare_end'])) {
        $period_b = rlu_parse_period($params['compare_start'], $params['compare_end']);
        $months = array_merge($months, rlu_month_keys_in_period($period_b['start_date'], $period_b['end_date']));
    } else {
        $period_b = rlu_shift_period_prev_year($start_date, $end_date);
        $months = array_merge($months, rlu_month_keys_in_period($period_b['start_date'], $period_b['end_date']));
    }
    $months = array_values(array_unique($months));
    sort($months);
    return $months;
}

/** หาช่วงเปรียบเทียบตามโหมด */
function rlu_resolve_compare_period($compare_mode, $start_date, $end_date, array $params = []) {
    $compare_mode = trim((string)$compare_mode);
    if ($compare_mode === 'year') {
        $yr = (int)($params['compare_year'] ?? 0);
        return rlu_shift_period_to_year($start_date, $end_date, $yr);
    }
    if ($compare_mode === 'month') {
        $ym = trim((string)($params['compare_month'] ?? ''));
        return rlu_shift_period_to_month($start_date, $end_date, $ym);
    }
    if ($compare_mode === 'custom' && !empty($params['compare_start']) && !empty($params['compare_end'])) {
        return rlu_parse_period($params['compare_start'], $params['compare_end']);
    }
    return rlu_shift_period_prev_year($start_date, $end_date);
}

/** รวมจาก cache ตามช่วงเดือน */
function rlu_aggregate_from_cache($con, $start_date, $end_date, array $options = []) {
    rlu_ensure_cache_table($con);
    $months = rlu_month_keys_in_period($start_date, $end_date);
    if (empty($months)) {
        return rlu_empty_report($start_date, $end_date);
    }

    $ym_min = rlu_escape($con, min($months));
    $ym_max = rlu_escape($con, max($months));
    $hipdata_filter = rlu_sql_cache_hipdata_filter($con, $options['hipdata_codes'] ?? []);

    $sql = "
        SELECT cache_ym, group_code, group_name, item_count, total_price
        FROM rational_lab_usage_monthly
        WHERE cache_ym >= '$ym_min' AND cache_ym <= '$ym_max'
          $hipdata_filter
        ORDER BY cache_ym ASC, item_count DESC
    ";
    $q = mysqli_query($con, $sql);
    if (!$q) {
        return rlu_empty_report($start_date, $end_date, mysqli_error($con));
    }

    $by_year = [];
    $by_month = [];
    $by_group = [];
    $detail = [];
    $total_items = 0;
    $total_price = 0.0;

    while ($row = mysqli_fetch_assoc($q)) {
        $ym = trim($row['cache_ym'] ?? '');
        if ($ym === '' || $ym < min($months) || $ym > max($months)) {
            continue;
        }
        $yr = (int)substr($ym, 0, 4);
        $cnt = (int)($row['item_count'] ?? 0);
        $price = (float)($row['total_price'] ?? 0);
        $code = trim($row['group_code'] ?? '');
        $name = trim($row['group_name'] ?? 'ไม่ระบุกลุ่ม');
        if ($name === 'ไม่มีรายการ' && $cnt === 0 && $code === '') {
            continue;
        }

        $total_items += $cnt;
        $total_price += $price;

        if (!isset($by_year[$yr])) {
            $by_year[$yr] = ['year' => $yr, 'item_count' => 0, 'total_price' => 0.0];
        }
        $by_year[$yr]['item_count'] += $cnt;
        $by_year[$yr]['total_price'] += $price;

        if (!isset($by_month[$ym])) {
            $by_month[$ym] = ['year_month' => $ym, 'item_count' => 0, 'total_price' => 0.0];
        }
        $by_month[$ym]['item_count'] += $cnt;
        $by_month[$ym]['total_price'] += $price;

        $gk = $yr . '|' . $code;
        if (!isset($by_group[$gk])) {
            $by_group[$gk] = [
                'year' => $yr,
                'group_code' => $code,
                'group_name' => $name,
                'item_count' => 0,
                'total_price' => 0.0,
            ];
        }
        $by_group[$gk]['item_count'] += $cnt;
        $by_group[$gk]['total_price'] += $price;
    }

    $detail = array_values($by_group);
    usort($detail, function ($a, $b) {
        if ($a['year'] !== $b['year']) {
            return $b['year'] <=> $a['year'];
        }
        return $b['item_count'] <=> $a['item_count'];
    });

    foreach ($detail as &$d) {
        $d['item_count_display'] = rlu_format_number($d['item_count']);
        $d['total_price_display'] = rlu_format_money($d['total_price']);
    }
    unset($d);

    $year_summary = array_values($by_year);
    usort($year_summary, function ($a, $b) {
        return $b['year'] <=> $a['year'];
    });
    foreach ($year_summary as &$ys) {
        $ys['item_count_display'] = rlu_format_number($ys['item_count']);
        $ys['total_price_display'] = rlu_format_money($ys['total_price']);
    }
    unset($ys);

    $month_summary = array_values($by_month);
    usort($month_summary, function ($a, $b) {
        return strcmp($a['year_month'], $b['year_month']);
    });
    foreach ($month_summary as &$ms) {
        $ms['item_count_display'] = rlu_format_number($ms['item_count']);
        $ms['total_price_display'] = rlu_format_money($ms['total_price']);
    }
    unset($ms);

    $period = rlu_parse_period($start_date, $end_date);

    return [
        'rows' => $detail,
        'summary' => [
            'total_items' => $total_items,
            'total_price' => $total_price,
            'total_items_display' => rlu_format_number($total_items),
            'total_price_display' => rlu_format_money($total_price),
            'by_year' => $year_summary,
            'by_month' => $month_summary,
        ],
        'meta' => [
            'filter' => $period,
            'months' => $months,
            'row_count' => count($detail),
            'source' => 'cache',
        ],
    ];
}

function rlu_empty_report($start_date, $end_date, $error = '') {
    $period = rlu_parse_period($start_date, $end_date);
    return [
        'rows' => [],
        'summary' => [
            'total_items' => 0,
            'total_price' => 0,
            'total_items_display' => '0',
            'total_price_display' => '0',
            'by_year' => [],
            'by_month' => [],
        ],
        'meta' => [
            'filter' => $period,
            'months' => rlu_month_keys_in_period($start_date, $end_date),
            'row_count' => 0,
            'source' => 'cache',
            'error' => $error,
        ],
    ];
}

/** รวม rows ตาม group */
function rlu_aggregate_rows_by_group($report) {
    $map = [];
    foreach ($report['rows'] ?? [] as $row) {
        $key = trim($row['group_code'] ?? '') . '|' . trim($row['group_name'] ?? '');
        if (!isset($map[$key])) {
            $map[$key] = [
                'group_code' => $row['group_code'] ?? '',
                'group_name' => $row['group_name'] ?? '',
                'item_count' => 0,
                'total_price' => 0.0,
            ];
        }
        $map[$key]['item_count'] += (int)($row['item_count'] ?? 0);
        $map[$key]['total_price'] += (float)($row['total_price'] ?? 0);
    }
    return $map;
}

/** เปรียบเทียบ 2 ช่วง — รวมตาม group (ไม่แยกปี) */
function rlu_build_comparison($report_a, $report_b, $label_a, $label_b) {
    $map_a = rlu_aggregate_rows_by_group($report_a);

    $map_b = rlu_aggregate_rows_by_group($report_b);

    $keys = array_unique(array_merge(array_keys($map_a), array_keys($map_b)));
    $rows = [];
    foreach ($keys as $key) {
        $a = $map_a[$key] ?? ['group_code' => '', 'group_name' => 'ไม่ระบุกลุ่ม', 'item_count' => 0, 'total_price' => 0.0];
        $b = $map_b[$key] ?? ['group_code' => '', 'group_name' => $a['group_name'], 'item_count' => 0, 'total_price' => 0.0];
        $diff_cnt = $b['item_count'] - $a['item_count'];
        $diff_price = $b['total_price'] - $a['total_price'];
        $rows[] = [
            'group_code' => $a['group_code'] ?: $b['group_code'],
            'group_name' => $a['group_name'] ?: $b['group_name'],
            'a_item_count' => $a['item_count'],
            'a_total_price' => $a['total_price'],
            'b_item_count' => $b['item_count'],
            'b_total_price' => $b['total_price'],
            'diff_item_count' => $diff_cnt,
            'diff_total_price' => $diff_price,
            'pct_item_count' => rlu_format_pct_change($a['item_count'], $b['item_count']),
            'pct_total_price' => rlu_format_pct_change($a['total_price'], $b['total_price']),
            'a_item_count_display' => rlu_format_number($a['item_count']),
            'a_total_price_display' => rlu_format_money($a['total_price']),
            'b_item_count_display' => rlu_format_number($b['item_count']),
            'b_total_price_display' => rlu_format_money($b['total_price']),
            'diff_item_count_display' => ($diff_cnt >= 0 ? '+' : '') . rlu_format_number($diff_cnt),
            'diff_total_price_display' => ($diff_price >= 0 ? '+' : '') . rlu_format_money(abs($diff_price)),
        ];
    }

    usort($rows, function ($x, $y) {
        return abs($y['diff_item_count']) <=> abs($x['diff_item_count']);
    });

    $sum_a = $report_a['summary'] ?? [];
    $sum_b = $report_b['summary'] ?? [];

    return [
        'label_a' => $label_a,
        'label_b' => $label_b,
        'rows' => $rows,
        'summary' => [
            'a_total_items' => (int)($sum_a['total_items'] ?? 0),
            'a_total_price' => (float)($sum_a['total_price'] ?? 0),
            'b_total_items' => (int)($sum_b['total_items'] ?? 0),
            'b_total_price' => (float)($sum_b['total_price'] ?? 0),
            'a_total_items_display' => $sum_a['total_items_display'] ?? '0',
            'a_total_price_display' => $sum_a['total_price_display'] ?? '0',
            'b_total_items_display' => $sum_b['total_items_display'] ?? '0',
            'b_total_price_display' => $sum_b['total_price_display'] ?? '0',
            'diff_items_display' => rlu_format_number((int)($sum_b['total_items'] ?? 0) - (int)($sum_a['total_items'] ?? 0)),
            'diff_price_display' => rlu_format_money((float)($sum_b['total_price'] ?? 0) - (float)($sum_a['total_price'] ?? 0)),
            'pct_items' => rlu_format_pct_change($sum_a['total_items'] ?? 0, $sum_b['total_items'] ?? 0),
            'pct_price' => rlu_format_pct_change($sum_a['total_price'] ?? 0, $sum_b['total_price'] ?? 0),
        ],
    ];
}

function rlu_shift_period_prev_year($start_date, $end_date) {
    $start = date('Y-m-d', strtotime($start_date . ' -1 year'));
    $end = date('Y-m-d', strtotime($end_date . ' -1 year'));
    return rlu_parse_period($start, $end);
}

/** วันเดือนเดียวกันในปีที่กำหนด (รองรับ 29 ก.พ.) */
function rlu_date_in_year($month, $day, $year) {
    $year = (int)$year;
    $month = max(1, min(12, (int)$month));
    $day = max(1, (int)$day);
    $last = (int)date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
    if ($day > $last) {
        $day = $last;
    }
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

/** ช่วงหลัก → ช่วงเดียวกันในปีที่เลือก (เช่น 1 ม.ค.–18 มิ.ย. 2024 → 2023) */
function rlu_shift_period_to_year($start_date, $end_date, $target_year) {
    $target_year = (int)$target_year;
    $start_year = (int)date('Y', strtotime($start_date));
    if ($target_year < 2000 || $target_year > 2100) {
        $target_year = $start_year - 1;
    }
    $start_ts = strtotime($start_date);
    $end_ts = strtotime($end_date);
    $new_start = rlu_date_in_year(date('n', $start_ts), date('j', $start_ts), $target_year);
    $new_end = rlu_date_in_year(date('n', $end_ts), date('j', $end_ts), $target_year);
    return rlu_parse_period($new_start, $new_end);
}

/** แปลงรายการเดือนเปรียบเทียบ YYYY-MM (1–3 เดือน) */
function rlu_parse_compare_months($raw, $end_date, $max = 3, $preserve_order = false) {
    $max = max(1, min(3, (int)$max));
    $months = [];
    if (is_array($raw)) {
        $parts = $raw;
    } else {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return [date('Y-m', strtotime($end_date . ' -1 month'))];
        }
        $parts = preg_split('/[,\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
    }
    foreach ($parts as $p) {
        $p = trim((string)$p);
        if (preg_match('/^\d{4}-\d{2}$/', $p) && !in_array($p, $months, true)) {
            $months[] = $p;
        }
        if (count($months) >= $max) {
            break;
        }
    }
    if (empty($months)) {
        $months[] = date('Y-m', strtotime($end_date . ' -1 month'));
    }
    if (!$preserve_order) {
        rsort($months, SORT_STRING);
    }
    return $months;
}

/** ช่วงหลัก → ช่วงวันเดียวกันในเดือนที่เลือก (เช่น 1–18 มิ.ย. 2024 → 1–18 พ.ค. 2024) */
function rlu_shift_period_to_month($start_date, $end_date, $target_ym) {
    $target_ym = trim((string)$target_ym);
    if (!preg_match('/^\d{4}-\d{2}$/', $target_ym)) {
        $target_ym = date('Y-m', strtotime($start_date . ' -1 month'));
    }
    $year = (int)substr($target_ym, 0, 4);
    $month = (int)substr($target_ym, 5, 2);
    $start_ts = strtotime($start_date);
    $end_ts = strtotime($end_date);
    $new_start = rlu_date_in_year($month, date('j', $start_ts), $year);
    $new_end = rlu_date_in_year($month, date('j', $end_ts), $year);
    if (strtotime($new_end) < strtotime($new_start)) {
        $new_end = $new_start;
    }
    return rlu_parse_period($new_start, $new_end);
}

/** แปลงรายการปีเปรียบเทียบ (1–3 ปี) */
function rlu_parse_compare_years($raw, $start_date, $max = 3, $preserve_order = false) {
    $max = max(1, min(3, (int)$max));
    $years = [];
    if (is_array($raw)) {
        $parts = $raw;
    } else {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return [(int)date('Y', strtotime($start_date)) - 1];
        }
        $parts = preg_split('/[,\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
    }
    foreach ($parts as $p) {
        $y = (int)$p;
        if ($y >= 2000 && $y <= 2100 && !in_array($y, $years, true)) {
            $years[] = $y;
        }
        if (count($years) >= $max) {
            break;
        }
    }
    if (empty($years)) {
        $years[] = (int)date('Y', strtotime($start_date)) - 1;
    }
    if (!$preserve_order) {
        rsort($years, SORT_NUMERIC);
    }
    return $years;
}

/** เปรียบเทียบช่วงหลักกับหลายปี (1–3 ปี) */
function rlu_build_multi_year_comparison($report_a, $periods_b_data, $label_a) {
    $map_a = rlu_aggregate_rows_by_group($report_a);
    $maps_b = [];
    foreach ($periods_b_data as $pb) {
        $maps_b[(int)$pb['year']] = rlu_aggregate_rows_by_group($pb['report']);
    }

    $keys = array_keys($map_a);
    foreach ($maps_b as $mb) {
        $keys = array_unique(array_merge($keys, array_keys($mb)));
    }

    $rows = [];
    foreach ($keys as $key) {
        $a = $map_a[$key] ?? ['group_code' => '', 'group_name' => 'ไม่ระบุกลุ่ม', 'item_count' => 0, 'total_price' => 0.0];
        $period_rows = [];
        foreach ($periods_b_data as $pb) {
            $yr = (int)$pb['year'];
            $b = $maps_b[$yr][$key] ?? ['group_code' => '', 'group_name' => $a['group_name'], 'item_count' => 0, 'total_price' => 0.0];
            $diff_cnt = $b['item_count'] - $a['item_count'];
            $diff_price = $b['total_price'] - $a['total_price'];
            $period_rows[] = [
                'year' => $yr,
                'label' => $pb['label'],
                'item_count' => $b['item_count'],
                'total_price' => $b['total_price'],
                'diff_item_count' => $diff_cnt,
                'diff_total_price' => $diff_price,
                'pct_item_count' => rlu_format_pct_change($a['item_count'], $b['item_count']),
                'pct_total_price' => rlu_format_pct_change($a['total_price'], $b['total_price']),
                'item_count_display' => rlu_format_number($b['item_count']),
                'total_price_display' => rlu_format_money($b['total_price']),
                'diff_item_count_display' => ($diff_cnt >= 0 ? '+' : '') . rlu_format_number($diff_cnt),
                'diff_total_price_display' => ($diff_price >= 0 ? '+' : '') . rlu_format_money(abs($diff_price)),
            ];
        }
        $rows[] = [
            'group_code' => $a['group_code'],
            'group_name' => $a['group_name'],
            'a_item_count' => $a['item_count'],
            'a_total_price' => $a['total_price'],
            'a_item_count_display' => rlu_format_number($a['item_count']),
            'a_total_price_display' => rlu_format_money($a['total_price']),
            'periods' => $period_rows,
        ];
    }

    usort($rows, function ($x, $y) {
        $ax = 0;
        $ay = 0;
        foreach ($x['periods'] ?? [] as $p) {
            $ax += abs((int)($p['diff_item_count'] ?? 0));
        }
        foreach ($y['periods'] ?? [] as $p) {
            $ay += abs((int)($p['diff_item_count'] ?? 0));
        }
        return $ay <=> $ax;
    });

    $sum_a = $report_a['summary'] ?? [];
    $summary_periods = [];
    foreach ($periods_b_data as $pb) {
        $sum_b = $pb['report']['summary'] ?? [];
        $diff_items = (int)($sum_b['total_items'] ?? 0) - (int)($sum_a['total_items'] ?? 0);
        $diff_price = (float)($sum_b['total_price'] ?? 0) - (float)($sum_a['total_price'] ?? 0);
        $summary_periods[] = [
            'year' => (int)$pb['year'],
            'label' => $pb['label'],
            'total_items' => (int)($sum_b['total_items'] ?? 0),
            'total_price' => (float)($sum_b['total_price'] ?? 0),
            'total_items_display' => $sum_b['total_items_display'] ?? '0',
            'total_price_display' => $sum_b['total_price_display'] ?? '0',
            'diff_items_display' => rlu_format_number($diff_items),
            'diff_price_display' => rlu_format_money($diff_price),
            'pct_items' => rlu_format_pct_change($sum_a['total_items'] ?? 0, $sum_b['total_items'] ?? 0),
            'pct_price' => rlu_format_pct_change($sum_a['total_price'] ?? 0, $sum_b['total_price'] ?? 0),
        ];
    }

    $periods_b = [];
    foreach ($periods_b_data as $pb) {
        $periods_b[] = [
            'year' => (int)$pb['year'],
            'label' => $pb['label'],
            'period' => $pb['period'],
        ];
    }

    return [
        'multi_year' => true,
        'label_a' => $label_a,
        'periods_b' => $periods_b,
        'rows' => $rows,
        'summary' => [
            'a_total_items_display' => $sum_a['total_items_display'] ?? '0',
            'a_total_price_display' => $sum_a['total_price_display'] ?? '0',
            'periods' => $summary_periods,
        ],
    ];
}

/** เปรียบเทียบหลายช่วงเท่าๆ กัน (เดือน/ปีที่เลือก) — ช่วงแรกเป็นฐาน ไม่ดึงช่วง filter หลัก */
function rlu_build_peer_comparison(array $slices_data, $granularity = 'month') {
    if (count($slices_data) < 2) {
        return null;
    }
    $baseline = $slices_data[0];
    $map_a = rlu_aggregate_rows_by_group($baseline['report']);
    $label_a = $baseline['label'];
    $key_a = (string)$baseline['key'];
    $others = array_slice($slices_data, 1);

    $maps_b = [];
    foreach ($others as $s) {
        $maps_b[(string)$s['key']] = rlu_aggregate_rows_by_group($s['report']);
    }

    $keys = array_keys($map_a);
    foreach ($maps_b as $mb) {
        $keys = array_unique(array_merge($keys, array_keys($mb)));
    }

    $rows = [];
    foreach ($keys as $key) {
        $a = $map_a[$key] ?? ['group_code' => '', 'group_name' => 'ไม่ระบุกลุ่ม', 'item_count' => 0, 'total_price' => 0.0];
        $period_rows = [];
        foreach ($others as $s) {
            $sk = (string)$s['key'];
            $b = $maps_b[$sk][$key] ?? ['group_code' => '', 'group_name' => $a['group_name'], 'item_count' => 0, 'total_price' => 0.0];
            $diff_cnt = $b['item_count'] - $a['item_count'];
            $diff_price = $b['total_price'] - $a['total_price'];
            $period_rows[] = [
                'year' => $sk,
                'label' => $s['label'],
                'item_count' => $b['item_count'],
                'total_price' => $b['total_price'],
                'diff_item_count' => $diff_cnt,
                'diff_total_price' => $diff_price,
                'pct_item_count' => rlu_format_pct_change($a['item_count'], $b['item_count']),
                'pct_total_price' => rlu_format_pct_change($a['total_price'], $b['total_price']),
                'item_count_display' => rlu_format_number($b['item_count']),
                'total_price_display' => rlu_format_money($b['total_price']),
                'diff_item_count_display' => ($diff_cnt >= 0 ? '+' : '') . rlu_format_number($diff_cnt),
                'diff_total_price_display' => ($diff_price >= 0 ? '+' : '') . rlu_format_money(abs($diff_price)),
            ];
        }
        $rows[] = [
            'group_code' => $a['group_code'],
            'group_name' => $a['group_name'],
            'a_item_count' => $a['item_count'],
            'a_total_price' => $a['total_price'],
            'a_item_count_display' => rlu_format_number($a['item_count']),
            'a_total_price_display' => rlu_format_money($a['total_price']),
            'periods' => $period_rows,
        ];
    }

    usort($rows, function ($x, $y) {
        $ax = 0;
        $ay = 0;
        foreach ($x['periods'] ?? [] as $p) {
            $ax += abs((int)($p['diff_item_count'] ?? 0));
        }
        foreach ($y['periods'] ?? [] as $p) {
            $ay += abs((int)($p['diff_item_count'] ?? 0));
        }
        return $ay <=> $ax;
    });

    $sum_a = $baseline['report']['summary'] ?? [];
    $summary_periods = [];
    foreach ($others as $s) {
        $sum_b = $s['report']['summary'] ?? [];
        $diff_items = (int)($sum_b['total_items'] ?? 0) - (int)($sum_a['total_items'] ?? 0);
        $diff_price = (float)($sum_b['total_price'] ?? 0) - (float)($sum_a['total_price'] ?? 0);
        $summary_periods[] = [
            'year' => (string)$s['key'],
            'label' => $s['label'],
            'total_items' => (int)($sum_b['total_items'] ?? 0),
            'total_price' => (float)($sum_b['total_price'] ?? 0),
            'total_items_display' => $sum_b['total_items_display'] ?? '0',
            'total_price_display' => $sum_b['total_price_display'] ?? '0',
            'diff_items_display' => rlu_format_number($diff_items),
            'diff_price_display' => rlu_format_money($diff_price),
            'pct_items' => rlu_format_pct_change($sum_a['total_items'] ?? 0, $sum_b['total_items'] ?? 0),
            'pct_price' => rlu_format_pct_change($sum_a['total_price'] ?? 0, $sum_b['total_price'] ?? 0),
        ];
    }

    $periods_b = [];
    $periods_all = [];
    foreach ($slices_data as $s) {
        $entry = [
            'year' => (string)$s['key'],
            'label' => $s['label'],
            'period' => $s['period'],
        ];
        $periods_all[] = $entry;
        if ((string)$s['key'] !== $key_a) {
            $periods_b[] = $entry;
        }
    }

    $baseline_summary = [
        'year' => $key_a,
        'label' => $label_a,
        'total_items_display' => $sum_a['total_items_display'] ?? '0',
        'total_price_display' => $sum_a['total_price_display'] ?? '0',
    ];

    $result = [
        'multi_year' => true,
        'peer_compare' => true,
        'compare_granularity' => $granularity,
        'label_a' => $label_a,
        'baseline_key' => $key_a,
        'periods_b' => $periods_b,
        'periods_all' => $periods_all,
        'rows' => $rows,
        'summary' => [
            'a_total_items_display' => $sum_a['total_items_display'] ?? '0',
            'a_total_price_display' => $sum_a['total_price_display'] ?? '0',
            'baseline' => $baseline_summary,
            'periods' => $summary_periods,
        ],
    ];

    // เปรียบเทียบ 2 ช่วง — แปลงเป็นรูปแบบ A vs B ให้กราฟ/ตารางอ่านง่าย
    if (count($slices_data) === 2 && count($rows) > 0) {
        $result['multi_year'] = false;
        $result['peer_compare'] = false;
        $result['label_b'] = $slices_data[1]['label'] ?? '';
        foreach ($result['rows'] as &$row) {
            $p = $row['periods'][0] ?? [];
            $row['b_item_count'] = (int)($p['item_count'] ?? 0);
            $row['b_total_price'] = (float)($p['total_price'] ?? 0);
            $row['diff_item_count'] = (int)($p['diff_item_count'] ?? 0);
            $row['diff_total_price'] = (float)($p['diff_total_price'] ?? 0);
            $row['b_item_count_display'] = $p['item_count_display'] ?? rlu_format_number($row['b_item_count']);
            $row['b_total_price_display'] = $p['total_price_display'] ?? rlu_format_money($row['b_total_price']);
            $row['diff_item_count_display'] = $p['diff_item_count_display'] ?? rlu_format_number($row['diff_item_count']);
            $row['diff_total_price_display'] = $p['diff_total_price_display'] ?? rlu_format_money(abs($row['diff_total_price']));
            $row['pct_item_count'] = $p['pct_item_count'] ?? rlu_format_pct_change($row['a_item_count'], $row['b_item_count']);
            $row['pct_total_price'] = $p['pct_total_price'] ?? rlu_format_pct_change($row['a_total_price'], $row['b_total_price']);
        }
        unset($row);
        $sp = $summary_periods[0] ?? [];
        $result['summary']['b_total_items_display'] = $sp['total_items_display'] ?? '0';
        $result['summary']['b_total_price_display'] = $sp['total_price_display'] ?? '0';
        $result['summary']['diff_items_display'] = $sp['diff_items_display'] ?? '0';
        $result['summary']['diff_price_display'] = $sp['diff_price_display'] ?? '0';
        $result['summary']['pct_items'] = $sp['pct_items'] ?? '0%';
        $result['summary']['pct_price'] = $sp['pct_price'] ?? '0%';
    }

    return $result;
}

function rlu_dimension_row_key(array $row, $dimension = 'item') {
    $code = trim($row['row_code'] ?? '');
    $dimension = trim((string)$dimension);
    if ($dimension === 'item' && $code !== '') {
        return 'item:' . $code;
    }
    return $code . '|' . trim($row['row_name'] ?? '');
}

/** legacy — เรียกแบบปีเดียวผ่าน cache */
function rlu_group_filter_sql($hos, $group_code) {
    $gc = trim((string)$group_code);
    if ($gc === '__all__' || $gc === '*') {
        return '1=1';
    }
    if ($gc === '' || $gc === '__none__') {
        return '(li.lab_items_group IS NULL OR TRIM(li.lab_items_group) = \'\')';
    }
    $gc_esc = mysqli_real_escape_string($hos, $gc);
    return "TRIM(li.lab_items_group) = '$gc_esc'";
}

/** JOIN hipdata (pttype) — เบากว่า visit joins เต็ม */
function rlu_sql_lab_hipdata_joins() {
    return "
        LEFT JOIN ovst ov ON ov.vn = lh.vn AND lh.vn IS NOT NULL AND TRIM(lh.vn) <> ''
        LEFT JOIN pttype pt ON pt.pttype = ov.pttype
    ";
}

/** JOIN แผนกจาก ovst — ไม่รวม ovstdiag/doctor (เร็วกว่า visit joins เต็ม) */
function rlu_sql_lab_dept_visit_joins() {
    return "
        LEFT JOIN ovst ov ON ov.vn = lh.vn AND lh.vn IS NOT NULL AND TRIM(lh.vn) <> ''
        LEFT JOIN spclty sp ON sp.spclty = ov.spclty
        LEFT JOIN kskdepartment kdep ON kdep.depcode = ov.main_dep
        LEFT JOIN pttype pt ON pt.pttype = ov.pttype
    ";
}

/** JOIN แผนก/แพทย์จาก ovst — lab_head.vn → ovst (แพทย์จาก ovstdiag diagtype=1 ก่อน แล้ว ovst.doctor) */
function rlu_sql_lab_visit_joins() {
    return "
        LEFT JOIN ovst ov ON ov.vn = lh.vn AND lh.vn IS NOT NULL AND TRIM(lh.vn) <> ''
        LEFT JOIN ovstdiag ovdiag ON ov.vn = ovdiag.vn AND ovdiag.diagtype = '1'
        LEFT JOIN spclty sp ON sp.spclty = ov.spclty
        LEFT JOIN kskdepartment kdep ON kdep.depcode = ov.main_dep
        LEFT JOIN doctor doc ON doc.code = COALESCE(NULLIF(TRIM(ovdiag.doctor), ''), NULLIF(TRIM(ov.doctor), ''))
        LEFT JOIN pttype pt ON pt.pttype = ov.pttype
    ";
}

/** JOIN ipt ผ่าน vn — ใช้นับ AN สำหรับค่าเฉลี่ยต่อ admission */
function rlu_sql_lab_ipt_join() {
    return "
        LEFT JOIN ipt ip ON ip.vn = lh.vn AND lh.vn IS NOT NULL AND TRIM(lh.vn) <> ''
    ";
}

/** เงื่อนไขกรองตามรหัสแพทย์ (ใช้กับ item drill-down) */
function rlu_sql_doctor_filter_sql($hos, $doctor_code, $doctor_code_expr) {
    $dc = trim((string)$doctor_code);
    if ($dc === '__none__') {
        $dc = '';
    }
    if ($dc === '') {
        return "($doctor_code_expr IS NULL OR TRIM($doctor_code_expr) = '')";
    }
    $dc_esc = mysqli_real_escape_string($hos, $dc);
    return "TRIM($doctor_code_expr) = '$dc_esc'";
}

/** เงื่อนไขกรองตามรหัสแผนก (ใช้กับ dept drill-down) */
function rlu_sql_dept_filter_sql($hos, $dept_filter_code, $dept_code_expr) {
    $dc = trim((string)$dept_filter_code);
    if ($dc === '__none__') {
        $dc = '';
    }
    if ($dc === '') {
        return "($dept_code_expr IS NULL OR TRIM($dept_code_expr) = '')";
    }
    $dc_esc = mysqli_real_escape_string($hos, $dc);
    return "TRIM($dept_code_expr) = '$dc_esc'";
}

function rlu_sql_lab_spclty_code_expr() {
    return "NULLIF(TRIM(ov.spclty), '')";
}

/** รหัสแผนก — main_dep ก่อน แล้ว spclty */
function rlu_sql_lab_dept_code_expr() {
    return "COALESCE(NULLIF(TRIM(ov.main_dep), ''), NULLIF(TRIM(ov.spclty), ''))";
}

/** ชื่อแผนก — map จาก kskdepartment (เหมือนรายงาน OPD) แล้ว fallback spclty.name */
function rlu_sql_lab_dept_name_expr() {
    $kdep = "CAST(CONVERT(COALESCE(kdep.department, '') USING utf8) AS CHAR)";
    $spname = "CAST(CONVERT(COALESCE(NULLIF(TRIM(sp.name), ''), NULLIF(TRIM(ov.spclty), ''), 'ไม่ระบุแผนก') USING utf8) AS CHAR)";
    return "CAST(CONVERT(
        CASE
            WHEN $kdep LIKE '%ปฐมภูมิ%' THEN 'ปฐมภูมิ'
            WHEN $kdep LIKE '%ศูนย์ตรวจสุขภาพและบริการชาวต่างชาติ%' THEN 'ศูนย์ตรวจต่างชาติ'
            WHEN $kdep LIKE '%จุดซักประวัติ โรคทั่วไป (OPD )%' THEN 'โรคทั่วไป (OPD)'
            WHEN $kdep LIKE '%จุดซักประวัติ OPD นอกเวลา%' THEN 'OPD นอกเวลา'
            WHEN $kdep LIKE '%ตึกอุบัติเหตุ%' THEN 'ตึกอุบัติเหตุ'
            WHEN $kdep LIKE '%จุดซักประวัติ คลินิกพิเศษเฉพาะทางนอกเวลา (SMC)%' THEN 'คลินิก SMC'
            ELSE $spname
        END
    USING utf8) AS CHAR)";
}

/** รหัสแพทย์ — ovstdiag (diagtype=1) ก่อน แล้ว ovst.doctor (ไม่นับ 00000) */
function rlu_sql_lab_doctor_code_expr() {
    return "NULLIF(TRIM(
        COALESCE(
            NULLIF(NULLIF(TRIM(ovdiag.doctor), ''), '00000'),
            NULLIF(NULLIF(TRIM(ov.doctor), ''), '00000')
        )
    ), '')";
}

/** รายการ Lab ย่อยในประเภท (group) ช่วงวันที่ — dimension: item | dept | doctor */
function rlu_fetch_hosxp_group_dimension_stats($hos, $start_date, $end_date, $group_code, $dimension = 'item', array $options = []) {
    $start_esc = mysqli_real_escape_string($hos, $start_date);
    $end_esc = mysqli_real_escape_string($hos, $end_date);
    $group_filter = rlu_group_filter_sql($hos, $group_code);
    $price_expr = rlu_sql_lab_line_price_expr();
    $dimension = trim((string)$dimension);
    if (!in_array($dimension, ['item', 'dept', 'doctor'], true)) {
        $dimension = 'item';
    }
    $filter_doctor = trim((string)($options['doctor_code'] ?? ''));
    $filter_item = trim((string)($options['item_code'] ?? ''));
    $filter_dept = trim((string)($options['dept_code'] ?? ''));
    $visit_scope = rlu_normalize_visit_scope($options['visit_scope'] ?? '');

    $doctor_code = rlu_sql_lab_doctor_code_expr();
    $dept_code = rlu_sql_lab_dept_code_expr();
    $dept_name = rlu_sql_lab_dept_name_expr();

    $joins = "
        FROM lab_head lh
        INNER JOIN lab_order lo ON lo.lab_order_number = lh.lab_order_number
        LEFT JOIN lab_items li ON li.lab_items_code = lo.lab_items_code
    ";
    $income_group_ids = $options['income_group_ids'] ?? [];
    $hipdata_codes = rlu_parse_hipdata_codes($options['hipdata_codes'] ?? []);
    if (!empty($income_group_ids)) {
        $joins .= rlu_sql_income_joins();
    }
    if ($dimension === 'doctor' || $dimension === 'dept' || $filter_doctor !== '' || $filter_dept !== '' || $visit_scope !== '') {
        $joins .= rlu_sql_lab_visit_joins();
    } elseif (!empty($hipdata_codes) && rlu_cache_split_hipdata_enabled()) {
        $joins .= rlu_sql_lab_hipdata_joins();
    }
    $skip_visit_stats = !empty($options['skip_doctor_visit_stats'])
        || ($filter_item !== '' && $dimension === 'doctor')
        || ($filter_dept !== '' && $dimension === 'doctor');
    if ($dimension === 'doctor' && !$skip_visit_stats) {
        $joins .= rlu_sql_lab_ipt_join();
    } elseif ($visit_scope !== '') {
        $joins .= rlu_sql_lab_ipt_join();
    }

    $extra_where = '';
    if ($filter_doctor !== '') {
        $extra_where = ' AND ' . rlu_sql_doctor_filter_sql($hos, $filter_doctor, $doctor_code);
    }
    if ($filter_item !== '') {
        $item_esc = mysqli_real_escape_string($hos, $filter_item);
        $extra_where .= " AND TRIM(lo.lab_items_code) = '$item_esc'";
    }
    if ($filter_dept !== '') {
        $extra_where .= ' AND ' . rlu_sql_dept_filter_sql($hos, $filter_dept, $dept_code);
    }
    if ($visit_scope !== '') {
        $extra_where .= rlu_sql_lab_visit_scope_sql($visit_scope);
    }
    if (!empty($income_group_ids)) {
        $extra_where .= rlu_sql_income_group_filter($hos, $income_group_ids);
    }
    if (!empty($hipdata_codes) && rlu_cache_split_hipdata_enabled()) {
        $extra_where .= rlu_sql_hipdata_filter($hos, $hipdata_codes);
    }

    if ($dimension === 'dept') {
        $select = "
            COALESCE(NULLIF(TRIM($dept_code), ''), '__none__') AS row_code,
            $dept_name AS row_name
        ";
        $group_by = 'row_code, row_name';
    } elseif ($dimension === 'doctor') {
        $select = "
            COALESCE(NULLIF(TRIM($doctor_code), ''), '__none__') AS row_code,
            CAST(CONVERT(
                COALESCE(
                    NULLIF(TRIM(doc.name), ''),
                    NULLIF(TRIM($doctor_code), ''),
                    'ไม่ระบุแพทย์'
                ) USING utf8) AS CHAR) AS row_name
        ";
        $group_by = 'row_code, row_name';
    } else {
        $select = "
            lo.lab_items_code AS row_code,
            CAST(CONVERT(COALESCE(NULLIF(TRIM(lo.lab_items_name_ref), ''), li.lab_items_name, lo.lab_items_code) USING utf8) AS CHAR) AS row_name
        ";
        $group_by = 'lo.lab_items_code, row_name';
    }

    $visit_select = ($dimension === 'doctor' && empty($skip_visit_stats))
        ? ", COUNT(DISTINCT NULLIF(TRIM(lh.vn), '')) AS visit_count, COUNT(DISTINCT NULLIF(TRIM(ip.an), '')) AS an_count"
        : '';

    $sql = "
        SELECT
            $select,
            COUNT(*) AS item_count,
            SUM($price_expr) AS total_price
            $visit_select
        $joins
        WHERE lh.order_date >= '$start_esc'
          AND lh.order_date <= '$end_esc'
          AND lh.order_date <> '0000-00-00'
          AND $group_filter
          $extra_where
        GROUP BY $group_by
        ORDER BY item_count DESC, row_name ASC
    ";
    $limit = (int)($options['limit'] ?? 0);
    if ($limit > 0) {
        $sql .= ' LIMIT ' . max(1, min(2000, $limit));
    }

    $t0 = microtime(true);
    $q = mysqli_query($hos, $sql);
    if (!$q) {
        return ['rows' => [], 'error' => mysqli_error($hos), 'elapsed_ms' => 0];
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $code = trim($row['row_code'] ?? '');
        if ($code === '__none__') {
            $code = '';
        }
        $entry = [
            'row_code' => $code,
            'row_name' => trim($row['row_name'] ?? ''),
            'item_code' => $code,
            'item_name' => trim($row['row_name'] ?? ''),
            'item_count' => (int)($row['item_count'] ?? 0),
            'total_price' => (float)($row['total_price'] ?? 0),
        ];
        if ($dimension === 'doctor') {
            $entry['visit_count'] = (int)($row['visit_count'] ?? 0);
            $entry['an_count'] = (int)($row['an_count'] ?? 0);
        }
        $rows[] = $entry;
    }

    return [
        'rows' => $rows,
        'error' => '',
        'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
        'dimension' => $dimension,
        'doctor_code' => $filter_doctor !== '' ? $filter_doctor : null,
    ];
}

/** @deprecated use rlu_fetch_hosxp_group_dimension_stats */
function rlu_fetch_hosxp_group_item_stats($hos, $start_date, $end_date, $group_code) {
    return rlu_fetch_hosxp_group_dimension_stats($hos, $start_date, $end_date, $group_code, 'item');
}

/** ช่วงวันที่สำหรับ drill-down ตามโหมดรายงาน */
function rlu_resolve_detail_periods($start_date, $end_date, $compare_enabled, $compare_mode, array $params = []) {
    $compare_mode = trim((string)$compare_mode);
    $peer = $compare_enabled && rlu_is_peer_compare_mode($compare_mode, $params);

    if ($peer) {
        $periods = [];
        if ($compare_mode === 'month') {
            foreach (rlu_parse_compare_months($params['compare_months'] ?? '', $end_date, 3, true) as $cm) {
                $periods[] = rlu_shift_period_to_month($start_date, $end_date, $cm);
            }
        } else {
            foreach (rlu_parse_compare_years($params['compare_years'] ?? '', $start_date, 3, true) as $yr) {
                $periods[] = rlu_shift_period_to_year($start_date, $end_date, $yr);
            }
        }
        return $periods;
    }

    if (!$compare_enabled) {
        return [rlu_parse_period($start_date, $end_date)];
    }

    $periods = [rlu_parse_period($start_date, $end_date)];
    if ($compare_mode === 'year') {
        foreach (rlu_parse_compare_years($params['compare_years'] ?? '', $start_date) as $yr) {
            $periods[] = rlu_shift_period_to_year($start_date, $end_date, $yr);
        }
    } elseif ($compare_mode === 'month') {
        foreach (rlu_parse_compare_months($params['compare_months'] ?? '', $end_date) as $ym) {
            $periods[] = rlu_shift_period_to_month($start_date, $end_date, $ym);
        }
    } elseif ($compare_mode === 'custom' && !empty($params['compare_start']) && !empty($params['compare_end'])) {
        $periods[] = rlu_parse_period($params['compare_start'], $params['compare_end']);
    } else {
        $periods[] = rlu_shift_period_prev_year($start_date, $end_date);
    }
    return $periods;
}

function rlu_clip_period_to_year(array $period, $year) {
    $year = (int)$year;
    if ($year <= 0) {
        return $period;
    }
    $start = max($period['start_date'], sprintf('%04d-01-01', $year));
    $end = min($period['end_date'], sprintf('%04d-12-31', $year));
    if (strtotime($start) > strtotime($end)) {
        return null;
    }
    $p = rlu_parse_period($start, $end);
    $p['label'] = (string)$year;
    return $p;
}

function rlu_cache_period_case_sql($con, array $periods, $cnt_col, $price_col) {
    $case_cnt = [];
    $case_price = [];
    foreach ($periods as $pi => $period) {
        $months = rlu_month_keys_in_period($period['start_date'], $period['end_date']);
        if (empty($months)) {
            $case_cnt[] = "0 AS p{$pi}_cnt";
            $case_price[] = "0 AS p{$pi}_price";
            continue;
        }
        $escaped = [];
        foreach ($months as $ym) {
            $escaped[] = "'" . rlu_escape($con, $ym) . "'";
        }
        $in = implode(',', $escaped);
        $case_cnt[] = "SUM(CASE WHEN cache_ym IN ($in) THEN $cnt_col ELSE 0 END) AS p{$pi}_cnt";
        $case_price[] = "SUM(CASE WHEN cache_ym IN ($in) THEN $price_col ELSE 0 END) AS p{$pi}_price";
    }
    return [implode(",\n            ", $case_cnt), implode(",\n            ", $case_price)];
}

function rlu_lab_cache_scope_exprs($scope) {
    $scope = rlu_normalize_visit_scope($scope);
    if ($scope === 'vn') {
        return ['item_count_vn', 'total_price_vn'];
    }
    if ($scope === 'an') {
        return ['item_count_an', 'total_price_an'];
    }
    return ['item_count', 'total_price'];
}

function rlu_lab_dimension_cache_ready_all_periods($con, array $periods, $group_code, $dimension, array $options = []) {
    $dimension = trim((string)$dimension);
    foreach ($periods as $period) {
        $months = rlu_month_keys_in_period($period['start_date'], $period['end_date']);
        foreach ($months as $ym) {
            if ($dimension === 'item') {
                if (!rlu_can_use_item_cache($group_code, $options) || !rlu_item_cache_month_exists($con, $ym) || !rlu_item_cache_month_has_prices($con, $ym)) {
                    return false;
                }
            } elseif ($dimension === 'doctor') {
                if (!rlu_can_use_doctor_cache($group_code, $options) || !rlu_doctor_cache_month_exists($con, $ym)) {
                    return false;
                }
            } elseif ($dimension === 'dept') {
                if (!rlu_can_use_dept_cache($group_code, $options) || !rlu_dept_cache_month_exists($con, $ym)) {
                    return false;
                }
            } else {
                return false;
            }
        }
    }
    return true;
}

/**
 * อ่าน cache หลายช่วงในครั้งเดียว (1 query) — เร็วกว่าวนละช่วง
 * @return array|null null = ให้ fallback รายช่วง
 */
function rlu_fetch_lab_dimension_stats_multi_period_from_cache($con, array $periods, $group_code, $dimension, array $options = []) {
    $period_count = count($periods);
    if ($period_count < 2 || $period_count > 4) {
        return null;
    }
    $dimension = trim((string)$dimension);
    if (!in_array($dimension, ['item', 'doctor', 'dept'], true)) {
        return null;
    }
    if (!rlu_lab_dimension_cache_ready_all_periods($con, $periods, $group_code, $dimension, $options)) {
        return null;
    }

    $all_months = [];
    foreach ($periods as $period) {
        $all_months = array_merge($all_months, rlu_month_keys_in_period($period['start_date'], $period['end_date']));
    }
    $all_months = array_values(array_unique($all_months));
    if (empty($all_months)) {
        return ['rows_by_period' => [], 'error' => '', 'elapsed_ms' => 0, 'dimension' => $dimension, 'source' => 'cache'];
    }

    $ym_min = rlu_escape($con, min($all_months));
    $ym_max = rlu_escape($con, max($all_months));
    $hipdata_filter = rlu_sql_cache_hipdata_filter($con, $options['hipdata_codes'] ?? []);
    [$cnt_col, $price_col] = rlu_lab_cache_scope_exprs($options['visit_scope'] ?? '');
    [$case_cnt, $case_price] = rlu_cache_period_case_sql($con, $periods, $cnt_col, $price_col);
    $limit = (int)($options['limit'] ?? 0);

    if ($dimension === 'item') {
        rlu_ensure_item_cache_table($con);
        $gc = trim((string)$group_code);
        $group_sql = '';
        if ($gc !== '' && $gc !== '__all__' && $gc !== '*') {
            if ($gc === '__none__') {
                $group_sql = " AND (group_code IS NULL OR TRIM(group_code) = '')";
            } else {
                $group_sql = " AND TRIM(group_code) = '" . rlu_escape($con, $gc) . "'";
            }
        }
        $sql = "
            SELECT item_code AS row_code, MAX(item_name) AS row_name,
                $case_cnt,
                $case_price
            FROM rational_lab_item_monthly
            WHERE cache_ym >= '$ym_min' AND cache_ym <= '$ym_max'
              AND item_code <> ''
              $group_sql
              $hipdata_filter
            GROUP BY item_code
            HAVING p0_cnt > 0
            ORDER BY p0_cnt DESC, row_name ASC
        ";
    } elseif ($dimension === 'doctor') {
        rlu_ensure_doctor_cache_table($con);
        $case_vis = [];
        $case_an = [];
        foreach ($periods as $pi => $period) {
            $months = rlu_month_keys_in_period($period['start_date'], $period['end_date']);
            if (empty($months)) {
                $case_vis[] = "0 AS p{$pi}_vis";
                $case_an[] = "0 AS p{$pi}_anc";
                continue;
            }
            $escaped = [];
            foreach ($months as $ym) {
                $escaped[] = "'" . rlu_escape($con, $ym) . "'";
            }
            $in = implode(',', $escaped);
            $case_vis[] = "SUM(CASE WHEN cache_ym IN ($in) THEN visit_count ELSE 0 END) AS p{$pi}_vis";
            $case_an[] = "SUM(CASE WHEN cache_ym IN ($in) THEN an_count ELSE 0 END) AS p{$pi}_anc";
        }
        $sql = "
            SELECT doctor_code AS row_code, MAX(doctor_name) AS row_name,
                $case_cnt,
                $case_price,
                " . implode(",\n                ", $case_vis) . ",
                " . implode(",\n                ", $case_an) . "
            FROM rational_lab_doctor_monthly
            WHERE cache_ym >= '$ym_min' AND cache_ym <= '$ym_max'
              AND doctor_code <> ''
              $hipdata_filter
            GROUP BY doctor_code
            HAVING p0_cnt > 0
            ORDER BY p0_cnt DESC, row_name ASC
        ";
    } else {
        rlu_ensure_dept_cache_table($con);
        $income_ids = rlu_parse_income_group_ids($options['income_group_ids'] ?? []);
        $income_filter = '';
        if (!empty($income_ids)) {
            $income_filter = ' AND income_group2 IN (' . implode(',', $income_ids) . ')';
        }
        $sql = "
            SELECT dept_code AS row_code, MAX(dept_name) AS row_name,
                $case_cnt,
                $case_price
            FROM rational_lab_dept_monthly
            WHERE cache_ym >= '$ym_min' AND cache_ym <= '$ym_max'
              AND dept_code <> ''
              $income_filter
              $hipdata_filter
            GROUP BY dept_code
            HAVING p0_cnt > 0
            ORDER BY p0_cnt DESC, row_name ASC
        ";
    }

    if ($limit > 0) {
        $sql .= ' LIMIT ' . max(1, min(2000, $limit));
    }

    $t0 = microtime(true);
    $q = mysqli_query($con, $sql);
    if (!$q) {
        return ['rows_by_period' => [], 'error' => mysqli_error($con), 'elapsed_ms' => 0, 'dimension' => $dimension, 'source' => 'cache'];
    }

    $rows_by_period = [];
    for ($pi = 0; $pi < $period_count; $pi++) {
        $rows_by_period[$pi] = [];
    }
    while ($row = mysqli_fetch_assoc($q)) {
        $code = trim($row['row_code'] ?? '');
        $name = trim($row['row_name'] ?? '');
        for ($pi = 0; $pi < $period_count; $pi++) {
            $cnt = (int)($row["p{$pi}_cnt"] ?? 0);
            $price = (float)($row["p{$pi}_price"] ?? 0);
            if ($cnt <= 0 && $price <= 0) {
                continue;
            }
            $entry = [
                'row_code' => $code,
                'row_name' => $name,
                'item_code' => $code,
                'item_name' => $name,
                'item_count' => $cnt,
                'total_price' => $price,
            ];
            if ($dimension === 'doctor') {
                $entry['visit_count'] = (int)($row["p{$pi}_vis"] ?? 0);
                $entry['an_count'] = (int)($row["p{$pi}_anc"] ?? 0);
            }
            $rows_by_period[$pi][] = $entry;
        }
    }

    return [
        'rows_by_period' => $rows_by_period,
        'error' => '',
        'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
        'dimension' => $dimension,
        'source' => 'cache',
    ];
}

/** รวมแถวสถิติมิติเข้า map เปรียบเทียบ */
function rlu_merge_dimension_period_rows(array &$item_map, array $rows, $pi, $dimension) {
    foreach ($rows as $row) {
        $key = rlu_dimension_row_key($row, $dimension);
        if (!isset($item_map[$key])) {
            $item_map[$key] = [
                'row_code' => $row['row_code'],
                'row_name' => $row['row_name'],
                'item_code' => $row['item_code'],
                'item_name' => $row['item_name'],
                'periods' => [],
            ];
        }
        if (strlen(trim($row['row_name'] ?? '')) > strlen(trim($item_map[$key]['row_name'] ?? ''))) {
            $item_map[$key]['row_name'] = $row['row_name'];
            $item_map[$key]['item_name'] = $row['item_name'];
        }
        $cnt = (int)$row['item_count'];
        $price = (float)$row['total_price'];
        $period_entry = [
            'item_count' => $cnt,
            'total_price' => $price,
            'item_count_display' => rlu_format_number($cnt),
            'total_price_display' => rlu_format_money($price),
        ];
        if ($dimension === 'doctor') {
            $period_entry['visit_count'] = (int)($row['visit_count'] ?? 0);
            $period_entry['an_count'] = (int)($row['an_count'] ?? 0);
            $period_entry['item_count_vn'] = (int)($row['item_count_vn'] ?? 0);
            $period_entry['total_price_vn'] = (float)($row['total_price_vn'] ?? 0);
            $period_entry['item_count_an'] = (int)($row['item_count_an'] ?? 0);
            $period_entry['total_price_an'] = (float)($row['total_price_an'] ?? 0);
        }
        $item_map[$key]['periods'][$pi] = $period_entry;
    }
}

/** สรุป Lab ย่อยหลายช่วง — ช่วงแรกเป็นฐานอ้างอิง */
function rlu_build_group_dimension_detail($hos, array $periods, $group_code, $dimension = 'item', array $options = [], $con = null) {
    if (empty($periods)) {
        return ['rows' => [], 'periods' => [], 'error' => 'ไม่มีช่วงวันที่', 'dimension' => $dimension];
    }

    $item_map = [];
    $elapsed = 0;
    $data_source = 'hosxp';

    $multi = $con
        ? rlu_fetch_lab_dimension_stats_multi_period_from_cache($con, $periods, $group_code, $dimension, $options)
        : null;
    if (is_array($multi) && ($multi['error'] ?? '') === '' && isset($multi['rows_by_period'])) {
        $elapsed = (int)($multi['elapsed_ms'] ?? 0);
        $data_source = 'cache';
        foreach ($multi['rows_by_period'] as $pi => $period_rows) {
            rlu_merge_dimension_period_rows($item_map, $period_rows, $pi, $dimension);
        }
    } else {
        if (is_array($multi) && ($multi['error'] ?? '') !== '') {
            return ['rows' => [], 'periods' => $periods, 'error' => $multi['error'], 'elapsed_ms' => $elapsed, 'dimension' => $dimension];
        }
        foreach ($periods as $pi => $period) {
            $use_item_cache = $con
                && $dimension === 'item'
                && rlu_can_use_item_cache($group_code, $options)
                && rlu_item_cache_ready_for_period($con, $period['start_date'], $period['end_date']);
            $use_doctor_cache = $con
                && $dimension === 'doctor'
                && rlu_can_use_doctor_cache($group_code, $options)
                && rlu_doctor_cache_ready_for_period($con, $period['start_date'], $period['end_date']);
            $use_dept_cache = $con
                && $dimension === 'dept'
                && rlu_can_use_dept_cache($group_code, $options)
                && rlu_dept_cache_ready_for_period($con, $period['start_date'], $period['end_date']);
            if ($use_item_cache) {
                $stats = rlu_fetch_item_stats_from_cache($con, $period['start_date'], $period['end_date'], $group_code, $options);
                $data_source = 'cache';
            } elseif ($use_doctor_cache) {
                $stats = rlu_fetch_doctor_stats_from_cache($con, $period['start_date'], $period['end_date'], $options);
                $data_source = 'cache';
            } elseif ($use_dept_cache) {
                $stats = rlu_fetch_dept_stats_from_cache($con, $period['start_date'], $period['end_date'], $options);
                $data_source = 'cache';
            } else {
                if (!$hos) {
                    $dim_label = $dimension === 'dept' ? 'แผนก' : ($dimension === 'doctor' ? 'แพทย์' : 'รายการ Lab');
                    return [
                        'rows' => [],
                        'periods' => $periods,
                        'error' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้ — แท็บ ' . $dim_label . ' ต้องใช้ข้อมูลสด',
                        'elapsed_ms' => $elapsed,
                        'dimension' => $dimension,
                        'data_source' => 'unavailable',
                    ];
                }
                $stats = rlu_fetch_hosxp_group_dimension_stats($hos, $period['start_date'], $period['end_date'], $group_code, $dimension, $options);
            }
            $elapsed += (int)($stats['elapsed_ms'] ?? 0);
            if (($stats['error'] ?? '') !== '') {
                return ['rows' => [], 'periods' => $periods, 'error' => $stats['error'], 'elapsed_ms' => $elapsed, 'dimension' => $dimension];
            }
            rlu_merge_dimension_period_rows($item_map, $stats['rows'], $pi, $dimension);
        }
    }

    $period_count = count($periods);
    $packed = rlu_pack_dimension_detail_rows($item_map, $periods, $dimension, $elapsed, $data_source, $options);
    if (!empty($options['item_code'])) {
        $packed['item_code'] = trim((string)$options['item_code']);
    }
    return $packed;
}

function rlu_build_group_items_detail($hos, array $periods, $group_code) {
    return rlu_build_group_dimension_detail($hos, $periods, $group_code, 'item');
}

/** รายการ Lab ย่อยของแพทย์คนเดียวในประเภท */
function rlu_build_group_doctor_items_detail($hos, array $periods, $group_code, $doctor_code, array $options = []) {
    return rlu_build_group_dimension_detail($hos, $periods, $group_code, 'item', array_merge([
        'doctor_code' => trim((string)$doctor_code),
    ], $options));
}

/** แพทย์ที่สั่งรายการ Lab ย่อยหนึ่งรายการ — เรียงตามจำนวนมากไปน้อย */
function rlu_build_item_doctors_detail($hos, array $periods, $item_code, array $options = [], $con = null) {
    $item_code = trim((string)$item_code);
    if ($item_code === '') {
        return ['rows' => [], 'periods' => $periods, 'error' => 'ไม่ระบุรหัสรายการ Lab', 'dimension' => 'doctor'];
    }
    $group_code = trim((string)($options['group_code'] ?? '__all__'));
    if ($group_code === '') {
        $group_code = '__all__';
    }
    $opt = array_merge([
        'item_code' => $item_code,
        'limit' => 200,
        'skip_doctor_visit_stats' => true,
    ], $options);

    if (!$hos) {
        return ['rows' => [], 'periods' => $periods, 'error' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้', 'dimension' => 'doctor'];
    }

    if (!$con) {
        return rlu_build_group_dimension_detail($hos, $periods, $group_code, 'doctor', $opt, $con);
    }

    $elapsed = 0;
    $item_map = [];
    foreach ($periods as $pi => $period) {
        $months = rlu_month_keys_in_period($period['start_date'], $period['end_date']);
        foreach ($months as $ym) {
            if (rlu_item_doctor_cache_month_ready($con, $ym, $item_code)) {
                continue;
            }
            $bounds = rlu_month_bounds($ym);
            if ($bounds === null) {
                continue;
            }
            $stats = rlu_fetch_hosxp_group_dimension_stats(
                $hos,
                $bounds['start'],
                $bounds['end'],
                $group_code,
                'doctor',
                $opt
            );
            $elapsed += (int)($stats['elapsed_ms'] ?? 0);
            if (($stats['error'] ?? '') !== '') {
                return [
                    'rows' => [],
                    'periods' => $periods,
                    'error' => $stats['error'],
                    'elapsed_ms' => $elapsed,
                    'dimension' => 'doctor',
                ];
            }
            if (!rlu_save_item_doctor_month_cache($con, $ym, $item_code, $stats['rows'])) {
                return [
                    'rows' => [],
                    'periods' => $periods,
                    'error' => 'บันทึก cache แพทย์ต่อรายการไม่สำเร็จ',
                    'elapsed_ms' => $elapsed,
                    'dimension' => 'doctor',
                ];
            }
        }
        $cached = rlu_fetch_item_doctors_period_from_cache($con, $period['start_date'], $period['end_date'], $item_code, 200);
        $elapsed += (int)($cached['elapsed_ms'] ?? 0);
        if (($cached['error'] ?? '') !== '') {
            return [
                'rows' => [],
                'periods' => $periods,
                'error' => $cached['error'],
                'elapsed_ms' => $elapsed,
                'dimension' => 'doctor',
            ];
        }
        rlu_merge_dimension_period_rows($item_map, $cached['rows'], $pi, 'doctor');
    }

    $packed = rlu_pack_dimension_detail_rows($item_map, $periods, 'doctor', $elapsed, 'cache', $opt);
    $packed['item_code'] = $item_code;
    return $packed;
}

/** แพทย์ในแผนกหนึ่งแผนก — เรียงตามจำนวนมากไปน้อย */
function rlu_build_dept_doctors_detail($hos, array $periods, $dept_code, array $options = [], $con = null) {
    $dept_code = trim((string)$dept_code);
    if ($dept_code === '__none__') {
        $dept_code = '';
    }
    $group_code = trim((string)($options['group_code'] ?? '__all__'));
    if ($group_code === '') {
        $group_code = '__all__';
    }
    $opt = array_merge([
        'dept_code' => $dept_code,
        'limit' => 200,
        'skip_doctor_visit_stats' => true,
    ], $options);

    if (!$hos) {
        return ['rows' => [], 'periods' => $periods, 'error' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้', 'dimension' => 'doctor'];
    }

    if (!$con) {
        return rlu_build_group_dimension_detail($hos, $periods, $group_code, 'doctor', $opt, $con);
    }

    $elapsed = 0;
    $item_map = [];
    foreach ($periods as $pi => $period) {
        $months = rlu_month_keys_in_period($period['start_date'], $period['end_date']);
        foreach ($months as $ym) {
            if (rlu_dept_doctor_cache_month_ready($con, $ym, $dept_code)) {
                continue;
            }
            $bounds = rlu_month_bounds($ym);
            if ($bounds === null) {
                continue;
            }
            $stats = rlu_fetch_hosxp_group_dimension_stats(
                $hos,
                $bounds['start'],
                $bounds['end'],
                $group_code,
                'doctor',
                $opt
            );
            $elapsed += (int)($stats['elapsed_ms'] ?? 0);
            if (($stats['error'] ?? '') !== '') {
                return [
                    'rows' => [],
                    'periods' => $periods,
                    'error' => $stats['error'],
                    'elapsed_ms' => $elapsed,
                    'dimension' => 'doctor',
                ];
            }
            if (!rlu_save_dept_doctor_month_cache($con, $ym, $dept_code, $stats['rows'])) {
                return [
                    'rows' => [],
                    'periods' => $periods,
                    'error' => 'บันทึก cache แพทย์ต่อแผนกไม่สำเร็จ',
                    'elapsed_ms' => $elapsed,
                    'dimension' => 'doctor',
                ];
            }
        }
        $cached = rlu_fetch_dept_doctors_period_from_cache($con, $period['start_date'], $period['end_date'], $dept_code, 200);
        $elapsed += (int)($cached['elapsed_ms'] ?? 0);
        if (($cached['error'] ?? '') !== '') {
            return [
                'rows' => [],
                'periods' => $periods,
                'error' => $cached['error'],
                'elapsed_ms' => $elapsed,
                'dimension' => 'doctor',
            ];
        }
        rlu_merge_dimension_period_rows($item_map, $cached['rows'], $pi, 'doctor');
    }

    $packed = rlu_pack_dimension_detail_rows($item_map, $periods, 'doctor', $elapsed, 'cache', $opt);
    $packed['dept_code'] = $dept_code;
    return $packed;
}

/** จำกัดจำนวนแถวเมื่อดึงทั้งระบบ (ไม่กรองประเภท Lab) */
function rlu_global_dimension_limit($dimension) {
    if ($dimension === 'item') {
        return 500;
    }
    if (in_array($dimension, ['dept', 'doctor'], true)) {
        return 300;
    }
    return 0;
}

/** เปรียบเทียบหลายช่วงตามมิติ (item / dept / doctor) ทุกประเภท Lab */
function rlu_build_global_dimension_comparison($hos, $con, array $periods, $dimension = 'item', array $options = []) {
    $dimension = trim((string)$dimension);
    if (!in_array($dimension, ['item', 'dept', 'doctor'], true)) {
        $dimension = 'item';
    }
    $detail = rlu_build_group_dimension_detail($hos, $periods, '__all__', $dimension, [
        'limit' => rlu_global_dimension_limit($dimension),
        'visit_scope' => rlu_normalize_visit_scope($options['visit_scope'] ?? ''),
        'income_group_ids' => $options['income_group_ids'] ?? [],
        'hipdata_codes' => $options['hipdata_codes'] ?? [],
    ], $con);
    if (($detail['error'] ?? '') !== '') {
        return ['error' => $detail['error'], 'elapsed_ms' => (int)($detail['elapsed_ms'] ?? 0)];
    }
    $comp = rlu_dimension_detail_to_comparison($detail, $options);
    if (!empty($detail['data_source'])) {
        $comp['data_source'] = $detail['data_source'];
    }
    return $comp;
}

/** แปลงผล dimension detail → รูปแบบ comparison ของ dashboard */
function rlu_dimension_detail_to_comparison(array $detail, array $options = []) {
    $periods = $detail['periods'] ?? [];
    $period_count = count($periods);
    $dimension = $detail['dimension'] ?? 'item';
    $peer = !empty($options['peer_compare']);

    $rows = [];
    foreach ($detail['rows'] ?? [] as $r) {
        $rows[] = [
            'group_code' => $r['row_code'] ?? '',
            'group_name' => $r['row_name'] ?? '',
            'row_code' => $r['row_code'] ?? '',
            'row_name' => $r['row_name'] ?? '',
            'a_item_count' => (int)($r['a_item_count'] ?? 0),
            'a_total_price' => (float)($r['a_total_price'] ?? 0.0),
            'a_item_count_display' => $r['a_item_count_display'] ?? rlu_format_number($r['a_item_count'] ?? 0),
            'a_total_price_display' => $r['a_total_price_display'] ?? rlu_format_money($r['a_total_price'] ?? 0.0),
            'a_visit_count' => (int)($r['a_visit_count'] ?? 0),
            'a_an_count' => (int)($r['a_an_count'] ?? 0),
            'periods' => $r['periods'] ?? [],
        ];
    }

    $sum_a_cnt = 0;
    $sum_a_price = 0.0;
    foreach ($rows as $r) {
        $sum_a_cnt += (int)$r['a_item_count'];
        $sum_a_price += (float)$r['a_total_price'];
    }

    $summary_periods = [];
    $periods_b = [];
    for ($pi = 1; $pi < $period_count; $pi++) {
        $sum_b_cnt = 0;
        $sum_b_price = 0.0;
        foreach ($rows as $r) {
            $p = $r['periods'][$pi - 1] ?? null;
            if ($p) {
                $sum_b_cnt += (int)($p['item_count'] ?? 0);
                $sum_b_price += (float)($p['total_price'] ?? 0);
            }
        }
        $diff_items = $sum_b_cnt - $sum_a_cnt;
        $diff_price = $sum_b_price - $sum_a_price;
        $label = $periods[$pi]['label'] ?? '';
        $summary_periods[] = [
            'label' => $label,
            'total_items' => $sum_b_cnt,
            'total_price' => $sum_b_price,
            'total_items_display' => rlu_format_number($sum_b_cnt),
            'total_price_display' => rlu_format_money($sum_b_price),
            'diff_items_display' => rlu_format_number($diff_items),
            'diff_price_display' => rlu_format_money($diff_price),
            'pct_items' => rlu_format_pct_change($sum_a_cnt, $sum_b_cnt),
            'pct_price' => rlu_format_pct_change($sum_a_price, $sum_b_price),
        ];
        $periods_b[] = $periods[$pi];
    }

    $comp = [
        'multi_year' => $period_count > 1,
        'dimension' => $dimension,
        'data_source' => 'hosxp',
        'label_a' => $periods[0]['label'] ?? '',
        'periods_b' => $periods_b,
        'rows' => $rows,
        'summary' => [
            'a_total_items_display' => rlu_format_number($sum_a_cnt),
            'a_total_price_display' => rlu_format_money($sum_a_price),
            'periods' => $summary_periods,
        ],
        'error' => '',
        'elapsed_ms' => (int)($detail['elapsed_ms'] ?? 0),
        'row_limit' => rlu_global_dimension_limit($dimension),
    ];

    if ($peer) {
        $comp['peer_compare'] = true;
        $comp['periods_all'] = $periods;
        $comp['compare_granularity'] = $options['granularity'] ?? 'month';
        $comp['baseline_key'] = $options['baseline_key'] ?? '';
    }

    if ($period_count === 2 && !empty($rows)) {
        $comp['multi_year'] = false;
        $comp['peer_compare'] = false;
        $comp['label_b'] = $periods[1]['label'] ?? '';
        foreach ($rows as &$row) {
            $p = $row['periods'][0] ?? [];
            $row['b_item_count'] = (int)($p['item_count'] ?? 0);
            $row['b_total_price'] = (float)($p['total_price'] ?? 0);
            $row['diff_item_count'] = (int)($p['diff_item_count'] ?? 0);
            $row['diff_total_price'] = (float)($p['diff_total_price'] ?? 0);
            $row['b_item_count_display'] = $p['item_count_display'] ?? rlu_format_number($row['b_item_count']);
            $row['b_total_price_display'] = $p['total_price_display'] ?? rlu_format_money($row['b_total_price']);
            $row['diff_item_count_display'] = $p['diff_item_count_display'] ?? rlu_format_number($row['diff_item_count']);
            $row['diff_total_price_display'] = $p['diff_total_price_display'] ?? rlu_format_money(abs($row['diff_total_price']));
            $row['pct_item_count'] = $p['pct_item_count'] ?? rlu_format_pct_change($row['a_item_count'], $row['b_item_count']);
            $row['pct_total_price'] = $p['pct_total_price'] ?? rlu_format_pct_change($row['a_total_price'], $row['b_total_price']);
        }
        unset($row);
        $cs = $comp['summary']['periods'][0] ?? [];
        $comp['summary']['b_total_items_display'] = $cs['total_items_display'] ?? '0';
        $comp['summary']['b_total_price_display'] = $cs['total_price_display'] ?? '0';
        $comp['summary']['diff_items_display'] = $cs['diff_items_display'] ?? '0';
        $comp['summary']['diff_price_display'] = $cs['diff_price_display'] ?? '0';
        $comp['summary']['pct_items'] = $cs['pct_items'] ?? '0%';
        $comp['summary']['pct_price'] = $cs['pct_price'] ?? '0%';
    }

    return $comp;
}

function rlu_build_group_full_detail($hos, array $periods, $group_code, array $options = [], $con = null) {
    $items = rlu_build_group_dimension_detail($hos, $periods, $group_code, 'item', $options, $con);
    if (($items['error'] ?? '') !== '') {
        return [
            'items' => $items,
            'departments' => ['rows' => [], 'periods' => $items['periods'] ?? [], 'error' => $items['error']],
            'doctors' => ['rows' => [], 'periods' => $items['periods'] ?? [], 'error' => $items['error']],
            'error' => $items['error'],
            'elapsed_ms' => (int)($items['elapsed_ms'] ?? 0),
        ];
    }
    $departments = rlu_build_group_dimension_detail($hos, $periods, $group_code, 'dept', $options, $con);
    $doctors = rlu_build_group_dimension_detail($hos, $periods, $group_code, 'doctor', $options, $con);
    $warnings = [];
    if (($departments['error'] ?? '') !== '') {
        $warnings[] = $departments['error'];
    }
    if (($doctors['error'] ?? '') !== '') {
        $warnings[] = $doctors['error'];
    }
    return [
        'items' => $items,
        'departments' => $departments,
        'doctors' => $doctors,
        'error' => '',
        'warnings' => $warnings,
        'elapsed_ms' => (int)($items['elapsed_ms'] ?? 0) + (int)($departments['elapsed_ms'] ?? 0) + (int)($doctors['elapsed_ms'] ?? 0),
    ];
}

/** เดือนที่ยังไม่มี cache มิติย่อย (item → doctor → dept) */
function rlu_first_missing_dimension_cache($con, array $months, array $scopes = null) {
    $dims = $scopes ?? ['item', 'doctor', 'dept'];
    foreach ($dims as $dim) {
        $missing = rlu_months_needing_build($con, $months, $dim, false);
        if (!empty($missing)) {
            return ['scope' => $dim, 'months' => $missing];
        }
    }
    return null;
}

function rlu_dimension_cache_scope_label($scope) {
    $scope = trim((string)$scope);
    if ($scope === 'doctor') {
        return 'แพทย์';
    }
    if ($scope === 'dept') {
        return 'แผนก';
    }
    if ($scope === 'item') {
        return 'รายการ Lab ย่อย';
    }
    return $scope;
}

function rlu_fetch_usage_stats($hos, $con, $year = '') {
    $year = trim((string)$year);
    if ($year === '' || $year === 'all') {
        $end_year = (int)date('Y');
        $start_year = $end_year - 4;
        $start_date = sprintf('%04d-01-01', $start_year);
        $end_date = sprintf('%04d-12-31', $end_year);
    } else {
        $yr = (int)$year;
        $start_date = sprintf('%04d-01-01', $yr);
        $end_date = sprintf('%04d-12-31', $yr);
    }
    $months = rlu_month_keys_in_period($start_date, $end_date);
    foreach ($months as $ym) {
        rlu_build_month_cache($con, $hos, $ym, false, 'usage');
    }
    return rlu_aggregate_from_cache($con, $start_date, $end_date);
}

function rlu_fetch_available_years($hos, $limit = 12) {
    $limit = max(1, min(20, (int)$limit));
    $sql = "SELECT DISTINCT YEAR(order_date) AS yr
        FROM lab_head
        WHERE order_date IS NOT NULL
          AND order_date <> '0000-00-00'
          AND order_date >= '2000-01-01'
        ORDER BY yr DESC
        LIMIT $limit";
    $q = mysqli_query($hos, $sql);
    $years = [];
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $yr = (int)($row['yr'] ?? 0);
            if ($yr > 0) {
                $years[] = $yr;
            }
        }
    }
    return $years;
}

/** หมวดรายได้มาตรฐาน (income_report2 / income.group2) — ตรง Grafana */
function rlu_income_group_catalog() {
    return [
        2 => 'อวัยวะเทียม/อุปกรณ์ในการบำบัดรักษา',
        3 => 'ยาและสารอาหารทางเส้นเลือดที่ใช้ใน รพ.',
        4 => 'ยาที่นำไปใช้ต่อที่บ้าน',
        5 => 'เวชภัณฑ์ที่มิใช่ยา',
        6 => 'บริการโลหิตและส่วนประกอบของโลหิต',
        7 => 'ตรวจวินิจฉัยทางเทคนิคการแพทย์และพยาธิวิทยา',
        8 => 'ตรวจวินิจฉัยและรักษาทางรังสีวิทยา',
        9 => 'ตรวจวินิจฉัยโดยวิธีพิเศษอื่นๆ',
        10 => 'อุปกรณ์ของใช้และเครื่องมือทางการแพทย์',
        11 => 'ทำหัตถการและบริการวิสัญญี',
        12 => 'ค่าบริการทางการพยาบาล',
        13 => 'บริการทางทันตกรรม',
        14 => 'บริการทางกายภาพบำบัดและเวชกรรมฟื้นฟู',
        15 => 'บริการฝังเข็ม/การบำบัดของผู้ประกอบโรคศิลปะอื่นๆ',
        16 => 'ค่าห้องผ่าตัด/ห้องคลอด',
    ];
}

/** ดึงหมวดรายได้จาก HOSxP (fallback catalog) */
function rlu_fetch_income_group_options($hos) {
    $catalog = rlu_income_group_catalog();
    if (!$hos) {
        $out = [];
        foreach ($catalog as $id => $name) {
            $out[] = ['group_id' => (int)$id, 'group_name' => $name];
        }
        return $out;
    }
    $q = mysqli_query($hos, "SELECT group_id, CAST(CONVERT(group_name USING utf8) AS CHAR) AS group_name
        FROM income_report2
        WHERE group_id IS NOT NULL
        ORDER BY group_id ASC");
    if (!$q) {
        $out = [];
        foreach ($catalog as $id => $name) {
            $out[] = ['group_id' => (int)$id, 'group_name' => $name];
        }
        return $out;
    }
    $out = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $id = (int)($row['group_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $name = trim($row['group_name'] ?? '');
        if ($name === '' && isset($catalog[$id])) {
            $name = $catalog[$id];
        }
        $out[] = ['group_id' => $id, 'group_name' => $name !== '' ? $name : ('หมวด ' . $id)];
    }
    if (empty($out)) {
        foreach ($catalog as $id => $name) {
            $out[] = ['group_id' => (int)$id, 'group_name' => $name];
        }
    }
    return $out;
}

function rlu_parse_income_group_ids($raw) {
    if (is_array($raw)) {
        $parts = $raw;
    } else {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/\s*,\s*/', $raw);
    }
    $ids = [];
    foreach ($parts as $p) {
        $id = (int)$p;
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    return array_values(array_unique($ids));
}

function rlu_sql_income_joins() {
    return "
        LEFT JOIN nondrugitems nd ON nd.icode = NULLIF(TRIM(li.icode), '')
        LEFT JOIN drugitems dd ON dd.icode = NULLIF(TRIM(li.icode), '')
        LEFT JOIN income ic ON ic.income = COALESCE(NULLIF(TRIM(nd.income), ''), NULLIF(TRIM(dd.income), ''))
    ";
}

function rlu_sql_income_group_filter($hos, array $group_ids) {
    $ids = rlu_parse_income_group_ids($group_ids);
    if (empty($ids)) {
        return '';
    }
    return ' AND ic.group2 IN (' . implode(',', $ids) . ')';
}

/** ใช้ cache รายเดือนเสมอ (รองรับกรอง hipdata ใน cache แล้ว) */
function rlu_api_needs_cache(array $options = []) {
    return true;
}

/** สรุปช่วงวันที่ — จาก cache รายเดือน (รองรับกรอง hipdata) */
function rlu_api_aggregate_period($con, $hos, $start_date, $end_date, array $options = []) {
    return rlu_aggregate_from_cache($con, $start_date, $end_date, $options);
}

/** กลุ่มสิทธิการรักษา (pttype.hipdata_code) — ตรง Grafana */
function rlu_hipdata_group_catalog() {
    return ['A2', 'BKK', 'BMT', 'CSH', 'LGO', 'NHS', 'NRD', 'OFC', 'PTY', 'SRT', 'SSS', 'STP', 'UCS'];
}

function rlu_fetch_hipdata_group_options($hos) {
    $catalog = rlu_hipdata_group_catalog();
    if (!$hos) {
        $out = [];
        foreach ($catalog as $code) {
            $out[] = ['hipdata_code' => $code, 'label' => $code];
        }
        return $out;
    }
    $q = mysqli_query($hos, "SELECT DISTINCT
            CAST(TRIM(hipdata_code) AS CHAR) AS hipdata_code,
            CAST(CONVERT(COALESCE(NULLIF(TRIM(name), ''), hipdata_code) USING utf8) AS CHAR) AS pttype_name
        FROM pttype
        WHERE hipdata_code IS NOT NULL AND TRIM(hipdata_code) <> ''
        ORDER BY hipdata_code ASC");
    if (!$q) {
        $out = [];
        foreach ($catalog as $code) {
            $out[] = ['hipdata_code' => $code, 'label' => $code];
        }
        return $out;
    }
    $out = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $code = trim($row['hipdata_code'] ?? '');
        if ($code === '') {
            continue;
        }
        $name = trim($row['pttype_name'] ?? '');
        $out[] = [
            'hipdata_code' => $code,
            'label' => $name !== '' && $name !== $code ? ($code . ' — ' . $name) : $code,
        ];
    }
    if (empty($out)) {
        foreach ($catalog as $code) {
            $out[] = ['hipdata_code' => $code, 'label' => $code];
        }
    }
    return $out;
}

function rlu_parse_hipdata_codes($raw) {
    if (is_array($raw)) {
        $parts = $raw;
    } else {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/\s*,\s*/', $raw);
    }
    $codes = [];
    foreach ($parts as $p) {
        $c = strtoupper(trim((string)$p));
        if ($c !== '') {
            $codes[] = $c;
        }
    }
    return array_values(array_unique($codes));
}

function rlu_sql_hipdata_filter($hos, array $codes) {
    $codes = rlu_parse_hipdata_codes($codes);
    if (empty($codes)) {
        return '';
    }
    $escaped = [];
    foreach ($codes as $c) {
        $escaped[] = "'" . mysqli_real_escape_string($hos, $c) . "'";
    }
    return ' AND pt.hipdata_code IN (' . implode(',', $escaped) . ')';
}

/** รายงาน Lab จาก HOSxP กรองกลุ่มสิทธิ (ไม่ใช้ cache) */
function rlu_aggregate_from_hosxp_lab($hos, $start_date, $end_date, array $options = []) {
    $hipdata = rlu_parse_hipdata_codes($options['hipdata_codes'] ?? []);
    if (empty($hipdata)) {
        return rlu_empty_report($start_date, $end_date, 'ไม่ระบุกลุ่มสิทธิการรักษา');
    }
    $start_esc = mysqli_real_escape_string($hos, $start_date);
    $end_esc = mysqli_real_escape_string($hos, $end_date);
    $price_expr = rlu_sql_lab_line_price_expr();
    $hipdata_filter = rlu_sql_hipdata_filter($hos, $hipdata);
    $base_from = "
        FROM lab_head lh
        INNER JOIN lab_order lo ON lo.lab_order_number = lh.lab_order_number
        LEFT JOIN lab_items li ON li.lab_items_code = lo.lab_items_code
        LEFT JOIN lab_items_group lig ON lig.lab_items_group_code = li.lab_items_group
        " . rlu_sql_lab_hipdata_joins() . "
        WHERE lh.order_date >= '$start_esc'
          AND lh.order_date <= '$end_esc'
          AND lh.order_date <> '0000-00-00'
          $hipdata_filter
    ";

    $t0 = microtime(true);

    $sql_month = "
        SELECT DATE_FORMAT(lh.order_date, '%Y-%m') AS year_month,
            COUNT(*) AS item_count,
            SUM($price_expr) AS total_price
        $base_from
        GROUP BY year_month
        ORDER BY year_month ASC
    ";
    $q = mysqli_query($hos, $sql_month);
    if (!$q) {
        return rlu_empty_report($start_date, $end_date, mysqli_error($hos));
    }
    $by_month = [];
    $total_items = 0;
    $total_price = 0.0;
    while ($row = mysqli_fetch_assoc($q)) {
        $ym = trim($row['year_month'] ?? '');
        $cnt = (int)($row['item_count'] ?? 0);
        $price = (float)($row['total_price'] ?? 0);
        $by_month[$ym] = ['year_month' => $ym, 'item_count' => $cnt, 'total_price' => $price];
        $total_items += $cnt;
        $total_price += $price;
    }

    $sql_year = "
        SELECT YEAR(lh.order_date) AS yr,
            COUNT(*) AS item_count,
            SUM($price_expr) AS total_price
        $base_from
        GROUP BY yr
        ORDER BY yr DESC
    ";
    $q = mysqli_query($hos, $sql_year);
    if (!$q) {
        return rlu_empty_report($start_date, $end_date, mysqli_error($hos));
    }
    $by_year = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $yr = (int)($row['yr'] ?? 0);
        $by_year[$yr] = [
            'year' => $yr,
            'item_count' => (int)($row['item_count'] ?? 0),
            'total_price' => (float)($row['total_price'] ?? 0),
        ];
    }

    $sql_detail = "
        SELECT YEAR(lh.order_date) AS yr,
            COALESCE(NULLIF(TRIM(li.lab_items_group), ''), '') AS group_code,
            CAST(CONVERT(COALESCE(lig.lab_items_group_name, 'ไม่ระบุกลุ่ม') USING utf8) AS CHAR) AS group_name,
            COUNT(*) AS item_count,
            SUM($price_expr) AS total_price
        $base_from
        GROUP BY yr, group_code, group_name
        ORDER BY yr DESC, item_count DESC
        LIMIT 2000
    ";
    $q = mysqli_query($hos, $sql_detail);
    if (!$q) {
        return rlu_empty_report($start_date, $end_date, mysqli_error($hos));
    }
    $detail = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $cnt = (int)($row['item_count'] ?? 0);
        $price = (float)($row['total_price'] ?? 0);
        $detail[] = [
            'year' => (int)($row['yr'] ?? 0),
            'group_code' => trim($row['group_code'] ?? ''),
            'group_name' => trim($row['group_name'] ?? 'ไม่ระบุกลุ่ม'),
            'item_count' => $cnt,
            'total_price' => $price,
            'item_count_display' => rlu_format_number($cnt),
            'total_price_display' => rlu_format_money($price),
        ];
    }

    $year_summary = array_values($by_year);
    usort($year_summary, function ($a, $b) {
        return $b['year'] <=> $a['year'];
    });
    foreach ($year_summary as &$ys) {
        $ys['item_count_display'] = rlu_format_number($ys['item_count']);
        $ys['total_price_display'] = rlu_format_money($ys['total_price']);
    }
    unset($ys);

    $month_summary = array_values($by_month);
    usort($month_summary, function ($a, $b) {
        return strcmp($a['year_month'], $b['year_month']);
    });
    foreach ($month_summary as &$ms) {
        $ms['item_count_display'] = rlu_format_number($ms['item_count']);
        $ms['total_price_display'] = rlu_format_money($ms['total_price']);
    }
    unset($ms);

    $period = rlu_parse_period($start_date, $end_date);
    return [
        'rows' => $detail,
        'summary' => [
            'total_items' => $total_items,
            'total_price' => $total_price,
            'total_items_display' => rlu_format_number($total_items),
            'total_price_display' => rlu_format_money($total_price),
            'by_year' => $year_summary,
            'by_month' => $month_summary,
        ],
        'meta' => [
            'filter' => $period,
            'source' => 'hosxp',
            'hipdata_codes' => $hipdata,
            'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
        ],
        'period' => $period,
    ];
}

/** รายงานสรุปจาก HOSxP กรองตามหมวดรายได้ (ไม่ใช้ cache กลุ่ม Lab) */
function rlu_aggregate_from_hosxp_income($hos, $start_date, $end_date, array $income_group_ids) {
    $ids = rlu_parse_income_group_ids($income_group_ids);
    if (empty($ids)) {
        return rlu_empty_report($start_date, $end_date, 'กรุณาเลือกหมวดรายได้อย่างน้อย 1 หมวด');
    }
    $start_esc = mysqli_real_escape_string($hos, $start_date);
    $end_esc = mysqli_real_escape_string($hos, $end_date);
    $price_expr = rlu_sql_lab_line_price_expr();
    $income_filter = rlu_sql_income_group_filter($hos, $ids);
    $base_from = "
        FROM lab_head lh
        INNER JOIN lab_order lo ON lo.lab_order_number = lh.lab_order_number
        LEFT JOIN lab_items li ON li.lab_items_code = lo.lab_items_code
        " . rlu_sql_income_joins() . "
        WHERE lh.order_date >= '$start_esc'
          AND lh.order_date <= '$end_esc'
          AND lh.order_date <> '0000-00-00'
          $income_filter
    ";

    $t0 = microtime(true);

    $sql_month = "
        SELECT DATE_FORMAT(lh.order_date, '%Y-%m') AS year_month,
            COUNT(*) AS item_count,
            SUM($price_expr) AS total_price
        $base_from
        GROUP BY year_month
        ORDER BY year_month ASC
    ";
    $q = mysqli_query($hos, $sql_month);
    if (!$q) {
        return rlu_empty_report($start_date, $end_date, mysqli_error($hos));
    }
    $by_month = [];
    $total_items = 0;
    $total_price = 0.0;
    while ($row = mysqli_fetch_assoc($q)) {
        $ym = trim($row['year_month'] ?? '');
        $cnt = (int)($row['item_count'] ?? 0);
        $price = (float)($row['total_price'] ?? 0);
        $by_month[$ym] = ['year_month' => $ym, 'item_count' => $cnt, 'total_price' => $price];
        $total_items += $cnt;
        $total_price += $price;
    }

    $sql_year = "
        SELECT YEAR(lh.order_date) AS yr,
            COUNT(*) AS item_count,
            SUM($price_expr) AS total_price
        $base_from
        GROUP BY yr
        ORDER BY yr DESC
    ";
    $q = mysqli_query($hos, $sql_year);
    if (!$q) {
        return rlu_empty_report($start_date, $end_date, mysqli_error($hos));
    }
    $by_year = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $yr = (int)($row['yr'] ?? 0);
        $by_year[$yr] = [
            'year' => $yr,
            'item_count' => (int)($row['item_count'] ?? 0),
            'total_price' => (float)($row['total_price'] ?? 0),
        ];
    }

    $sql_detail = "
        SELECT YEAR(lh.order_date) AS yr,
            lo.lab_items_code AS item_code,
            CAST(CONVERT(COALESCE(NULLIF(TRIM(lo.lab_items_name_ref), ''), li.lab_items_name, lo.lab_items_code) USING utf8) AS CHAR) AS item_name,
            COUNT(*) AS item_count,
            SUM($price_expr) AS total_price
        $base_from
        GROUP BY yr, lo.lab_items_code, item_name
        ORDER BY yr DESC, item_count DESC
        LIMIT 2000
    ";
    $q = mysqli_query($hos, $sql_detail);
    if (!$q) {
        return rlu_empty_report($start_date, $end_date, mysqli_error($hos));
    }
    $detail = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $cnt = (int)($row['item_count'] ?? 0);
        $price = (float)($row['total_price'] ?? 0);
        $detail[] = [
            'year' => (int)($row['yr'] ?? 0),
            'item_code' => trim($row['item_code'] ?? ''),
            'item_name' => trim($row['item_name'] ?? ''),
            'group_code' => trim($row['item_code'] ?? ''),
            'group_name' => trim($row['item_name'] ?? ''),
            'item_count' => $cnt,
            'total_price' => $price,
            'item_count_display' => rlu_format_number($cnt),
            'total_price_display' => rlu_format_money($price),
        ];
    }

    $year_summary = array_values($by_year);
    usort($year_summary, function ($a, $b) {
        return $b['year'] <=> $a['year'];
    });
    foreach ($year_summary as &$ys) {
        $ys['item_count_display'] = rlu_format_number($ys['item_count']);
        $ys['total_price_display'] = rlu_format_money($ys['total_price']);
    }
    unset($ys);

    $month_summary = array_values($by_month);
    usort($month_summary, function ($a, $b) {
        return strcmp($a['year_month'], $b['year_month']);
    });
    foreach ($month_summary as &$ms) {
        $ms['item_count_display'] = rlu_format_number($ms['item_count']);
        $ms['total_price_display'] = rlu_format_money($ms['total_price']);
    }
    unset($ms);

    $period = rlu_parse_period($start_date, $end_date);
    return [
        'rows' => $detail,
        'summary' => [
            'total_items' => $total_items,
            'total_price' => $total_price,
            'total_items_display' => rlu_format_number($total_items),
            'total_price_display' => rlu_format_money($total_price),
            'by_year' => $year_summary,
            'by_month' => $month_summary,
        ],
        'meta' => [
            'filter' => $period,
            'source' => 'hosxp',
            'income_group_ids' => $ids,
            'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
        ],
        'period' => $period,
    ];
}
