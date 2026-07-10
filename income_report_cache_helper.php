<?php
/**
 * Cache รายเดือน — Dashboard รายงาน (opitemrece + income.group2 + hipdata_code)
 * ประมวลผลครั้งเดียวต่อเดือน โหลดครั้งถัดไปจากตาราง local
 * (โหลดหลัง income_report_helper.php)
 */

function irpt_ensure_item_cache_table($con) {
    $q = mysqli_query($con, "SHOW TABLES LIKE 'income_report_item_monthly'");
    if ($q && mysqli_num_rows($q) > 0) {
        return true;
    }
    $sql = @file_get_contents(__DIR__ . '/sql/income_report_item_monthly.sql');
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

function irpt_ensure_dept_cache_table($con) {
    $q = mysqli_query($con, "SHOW TABLES LIKE 'income_report_dept_monthly'");
    if ($q && mysqli_num_rows($q) > 0) {
        return true;
    }
    $sql = @file_get_contents(__DIR__ . '/sql/income_report_dept_monthly.sql');
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

function irpt_ensure_doctor_cache_table($con) {
    $q = mysqli_query($con, "SHOW TABLES LIKE 'income_report_doctor_monthly'");
    if ($q && mysqli_num_rows($q) > 0) {
        return true;
    }
    $sql = @file_get_contents(__DIR__ . '/sql/income_report_doctor_monthly.sql');
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

function irpt_ensure_item_doctor_cache_table($con) {
    $q = mysqli_query($con, "SHOW TABLES LIKE 'income_report_item_doctor_monthly'");
    if ($q && mysqli_num_rows($q) > 0) {
        $col = mysqli_query($con, "SHOW COLUMNS FROM income_report_item_doctor_monthly LIKE 'hipdata_code'");
        if ($col && mysqli_num_rows($col) > 0) {
            mysqli_query($con, 'DROP TABLE income_report_item_doctor_monthly');
        } else {
            $vn_col = mysqli_query($con, "SHOW COLUMNS FROM income_report_item_doctor_monthly LIKE 'item_count_vn'");
            if ($vn_col && mysqli_num_rows($vn_col) > 0) {
                return true;
            }
            mysqli_query($con, 'DROP TABLE income_report_item_doctor_monthly');
        }
    }
    $sql = @file_get_contents(__DIR__ . '/sql/income_report_item_doctor_monthly.sql');
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

function irpt_ensure_cache_tables($con) {
    return irpt_ensure_item_cache_table($con)
        && irpt_ensure_dept_cache_table($con)
        && irpt_ensure_doctor_cache_table($con)
        && irpt_ensure_item_doctor_cache_table($con);
}

function irpt_cache_month_bounds($year_month) {
    return rlu_month_bounds($year_month);
}

function irpt_sql_cache_dimension_filter($con, array $income_group_ids, array $hipdata_codes) {
    $ids = rlu_parse_income_group_ids($income_group_ids);
    $parts = [];
    if (!empty($ids)) {
        $parts[] = 'income_group2 IN (' . implode(',', $ids) . ')';
    }
    $codes = rlu_parse_hipdata_codes($hipdata_codes);
    if (!empty($codes)) {
        $escaped = [];
        foreach ($codes as $code) {
            $escaped[] = "'" . rlu_escape($con, $code) . "'";
        }
        $parts[] = 'hipdata_code IN (' . implode(',', $escaped) . ')';
    }
    return empty($parts) ? '1=1' : implode(' AND ', $parts);
}

function irpt_cache_scope_exprs($scope) {
    $scope = rlu_normalize_visit_scope($scope);
    if ($scope === 'vn') {
        return ['item_count_vn', 'total_price_vn'];
    }
    if ($scope === 'an') {
        return ['item_count_an', 'total_price_an'];
    }
    return ['item_count', 'total_price'];
}

function irpt_item_cache_month_exists($con, $year_month) {
    irpt_ensure_item_cache_table($con);
    $ym = rlu_escape($con, $year_month);
    $q = mysqli_query($con, "SELECT COUNT(*) AS c FROM income_report_item_monthly WHERE cache_ym='$ym' LIMIT 1");
    if (!$q) {
        return false;
    }
    $row = mysqli_fetch_assoc($q);
    return (int)($row['c'] ?? 0) > 0;
}

function irpt_dept_cache_month_exists($con, $year_month) {
    irpt_ensure_dept_cache_table($con);
    $ym = rlu_escape($con, $year_month);
    $q = mysqli_query($con, "SELECT COUNT(*) AS c FROM income_report_dept_monthly WHERE cache_ym='$ym' LIMIT 1");
    if (!$q) {
        return false;
    }
    $row = mysqli_fetch_assoc($q);
    return (int)($row['c'] ?? 0) > 0;
}

function irpt_doctor_cache_month_exists($con, $year_month) {
    irpt_ensure_doctor_cache_table($con);
    $ym = rlu_escape($con, $year_month);
    $q = mysqli_query($con, "SELECT COUNT(*) AS c FROM income_report_doctor_monthly WHERE cache_ym='$ym' LIMIT 1");
    if (!$q) {
        return false;
    }
    $row = mysqli_fetch_assoc($q);
    return (int)($row['c'] ?? 0) > 0;
}

function irpt_cache_month_exists($con, $year_month) {
    return irpt_item_cache_month_exists($con, $year_month)
        && irpt_dept_cache_month_exists($con, $year_month)
        && irpt_doctor_cache_month_exists($con, $year_month);
}

function irpt_list_missing_cache_months($con, array $months) {
    return irpt_list_missing_item_cache_months($con, $months);
}

function irpt_can_use_cache(array $options = [], $dimension = 'item') {
    if (trim((string)($options['doctor_code'] ?? '')) !== '') {
        return false;
    }
    if (trim((string)($options['item_code'] ?? '')) !== '') {
        return false;
    }
    $dimension = trim((string)$dimension);
    return in_array($dimension, ['item', 'dept', 'doctor'], true);
}

function irpt_cache_ready_for_period($con, $start_date, $end_date, $dimension = 'item') {
    $months = rlu_month_keys_in_period($start_date, $end_date);
    if (empty($months)) {
        return false;
    }
    foreach ($months as $ym) {
        if ($dimension === 'item' && !irpt_item_cache_month_exists($con, $ym)) {
            return false;
        }
        if ($dimension === 'dept' && !irpt_dept_cache_month_exists($con, $ym)) {
            return false;
        }
        if ($dimension === 'doctor' && !irpt_doctor_cache_month_exists($con, $ym)) {
            return false;
        }
    }
    return true;
}

function irpt_fetch_hosxp_month_item_stats($hos, $year_month) {
    $bounds = irpt_cache_month_bounds($year_month);
    if (!$bounds) {
        return ['rows' => [], 'error' => 'รูปแบบเดือนไม่ถูกต้อง', 'elapsed_ms' => 0];
    }
    $start_esc = mysqli_real_escape_string($hos, $bounds['start']);
    $end_esc = mysqli_real_escape_string($hos, $bounds['end']);
    $item_name = irpt_sql_item_name_expr();
    $sql = "
        SELECT
            COALESCE(NULLIF(TRIM(pt.hipdata_code), ''), '__none__') AS hipdata_code,
            ic.group2 AS income_group2,
            o.icode AS item_code,
            $item_name AS item_name,
            COUNT(*) AS item_count,
            SUM(CAST(o.sum_price AS DECIMAL(16,4))) AS total_price,
            SUM(CASE WHEN o.an IS NULL OR TRIM(o.an) = '' THEN 1 ELSE 0 END) AS item_count_vn,
            SUM(CASE WHEN o.an IS NULL OR TRIM(o.an) = '' THEN CAST(o.sum_price AS DECIMAL(16,4)) ELSE 0 END) AS total_price_vn,
            SUM(CASE WHEN o.an IS NOT NULL AND TRIM(o.an) <> '' THEN 1 ELSE 0 END) AS item_count_an,
            SUM(CASE WHEN o.an IS NOT NULL AND TRIM(o.an) <> '' THEN CAST(o.sum_price AS DECIMAL(16,4)) ELSE 0 END) AS total_price_an
        FROM opitemrece o
        INNER JOIN income ic ON ic.income = o.income
        " . irpt_sql_item_catalog_joins() . "
        LEFT JOIN ovst ov ON ov.vn = o.vn AND o.vn IS NOT NULL AND TRIM(o.vn) <> ''
        LEFT JOIN pttype pt ON pt.pttype = ov.pttype
        WHERE o.vstdate >= '$start_esc'
          AND o.vstdate <= '$end_esc'
          AND o.vstdate <> '0000-00-00'
          AND o.qty > 0
          AND CAST(o.sum_price AS DECIMAL(16,4)) > 0
          AND ic.group2 IS NOT NULL
        GROUP BY hipdata_code, income_group2, o.icode, item_name
    ";
    $t0 = microtime(true);
    $q = mysqli_query($hos, $sql);
    if (!$q) {
        return ['rows' => [], 'error' => mysqli_error($hos), 'elapsed_ms' => 0];
    }
    $rows = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $rows[] = $row;
    }
    return ['rows' => $rows, 'error' => '', 'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000)];
}

function irpt_fetch_hosxp_range_item_stats($hos, $start_date, $end_date) {
    $start_esc = mysqli_real_escape_string($hos, $start_date);
    $end_esc = mysqli_real_escape_string($hos, $end_date);
    $item_name = irpt_sql_item_name_expr();
    $sql = "
        SELECT
            DATE_FORMAT(o.vstdate, '%Y-%m') AS cache_ym,
            COALESCE(NULLIF(TRIM(pt.hipdata_code), ''), '__none__') AS hipdata_code,
            ic.group2 AS income_group2,
            o.icode AS item_code,
            $item_name AS item_name,
            COUNT(*) AS item_count,
            SUM(CAST(o.sum_price AS DECIMAL(16,4))) AS total_price,
            SUM(CASE WHEN o.an IS NULL OR TRIM(o.an) = '' THEN 1 ELSE 0 END) AS item_count_vn,
            SUM(CASE WHEN o.an IS NULL OR TRIM(o.an) = '' THEN CAST(o.sum_price AS DECIMAL(16,4)) ELSE 0 END) AS total_price_vn,
            SUM(CASE WHEN o.an IS NOT NULL AND TRIM(o.an) <> '' THEN 1 ELSE 0 END) AS item_count_an,
            SUM(CASE WHEN o.an IS NOT NULL AND TRIM(o.an) <> '' THEN CAST(o.sum_price AS DECIMAL(16,4)) ELSE 0 END) AS total_price_an
        FROM opitemrece o
        INNER JOIN income ic ON ic.income = o.income
        " . irpt_sql_item_catalog_joins() . "
        LEFT JOIN ovst ov ON ov.vn = o.vn AND o.vn IS NOT NULL AND TRIM(o.vn) <> ''
        LEFT JOIN pttype pt ON pt.pttype = ov.pttype
        WHERE o.vstdate >= '$start_esc'
          AND o.vstdate <= '$end_esc'
          AND o.vstdate <> '0000-00-00'
          AND o.qty > 0
          AND CAST(o.sum_price AS DECIMAL(16,4)) > 0
          AND ic.group2 IS NOT NULL
        GROUP BY cache_ym, hipdata_code, income_group2, o.icode, item_name
    ";
    $t0 = microtime(true);
    $q = mysqli_query($hos, $sql);
    if (!$q) {
        return ['rows' => [], 'error' => mysqli_error($hos), 'elapsed_ms' => 0];
    }
    $rows = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $rows[] = $row;
    }
    return ['rows' => $rows, 'error' => '', 'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000)];
}

function irpt_save_month_item_cache($con, $year_month, array $rows) {
    irpt_ensure_item_cache_table($con);
    $ym = rlu_escape($con, $year_month);
    mysqli_query($con, "DELETE FROM income_report_item_monthly WHERE cache_ym='$ym'");
    $tuples = [];
    foreach ($rows as $row) {
        $hip = rlu_escape($con, trim($row['hipdata_code'] ?? ''));
        $gid = (int)($row['income_group2'] ?? 0);
        $code = rlu_escape($con, trim($row['item_code'] ?? ''));
        $name = rlu_escape($con, trim($row['item_name'] ?? ''));
        $cnt = (int)($row['item_count'] ?? 0);
        $price = (float)($row['total_price'] ?? 0);
        $cnt_vn = (int)($row['item_count_vn'] ?? 0);
        $price_vn = (float)($row['total_price_vn'] ?? 0);
        $cnt_an = (int)($row['item_count_an'] ?? 0);
        $price_an = (float)($row['total_price_an'] ?? 0);
        $tuples[] = "('$ym', '$hip', $gid, '$code', '$name', $cnt, $price, $cnt_vn, $price_vn, $cnt_an, $price_an)";
    }
    return rlu_bulk_insert_values($con, 'income_report_item_monthly',
        ['cache_ym', 'hipdata_code', 'income_group2', 'item_code', 'item_name', 'item_count', 'total_price', 'item_count_vn', 'total_price_vn', 'item_count_an', 'total_price_an'],
        $tuples);
}

function irpt_fetch_hosxp_month_dept_stats($hos, $year_month) {
    $bounds = irpt_cache_month_bounds($year_month);
    if (!$bounds) {
        return ['rows' => [], 'error' => 'รูปแบบเดือนไม่ถูกต้อง', 'elapsed_ms' => 0];
    }
    $start_esc = mysqli_real_escape_string($hos, $bounds['start']);
    $end_esc = mysqli_real_escape_string($hos, $bounds['end']);
    $dept_code = irpt_sql_dept_code_expr();
    $dept_name = irpt_sql_dept_name_expr();
    $sql = "
        SELECT
            COALESCE(NULLIF(TRIM(pt.hipdata_code), ''), '__none__') AS hipdata_code,
            ic.group2 AS income_group2,
            COALESCE(NULLIF(TRIM($dept_code), ''), '__none__') AS dept_code,
            $dept_name AS dept_name,
            COUNT(*) AS item_count,
            SUM(CAST(o.sum_price AS DECIMAL(16,4))) AS total_price
        FROM opitemrece o
        INNER JOIN income ic ON ic.income = o.income
        " . irpt_sql_visit_joins() . "
        WHERE o.vstdate >= '$start_esc'
          AND o.vstdate <= '$end_esc'
          AND o.vstdate <> '0000-00-00'
          AND o.qty > 0
          AND CAST(o.sum_price AS DECIMAL(16,4)) > 0
          AND ic.group2 IS NOT NULL
        GROUP BY hipdata_code, income_group2, dept_code, dept_name
    ";
    $t0 = microtime(true);
    $q = mysqli_query($hos, $sql);
    if (!$q) {
        return ['rows' => [], 'error' => mysqli_error($hos), 'elapsed_ms' => 0];
    }
    $rows = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $rows[] = $row;
    }
    return ['rows' => $rows, 'error' => '', 'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000)];
}

function irpt_fetch_hosxp_range_dept_stats($hos, $start_date, $end_date) {
    $start_esc = mysqli_real_escape_string($hos, $start_date);
    $end_esc = mysqli_real_escape_string($hos, $end_date);
    $dept_code = irpt_sql_dept_code_expr();
    $dept_name = irpt_sql_dept_name_expr();
    $sql = "
        SELECT
            DATE_FORMAT(o.vstdate, '%Y-%m') AS cache_ym,
            COALESCE(NULLIF(TRIM(pt.hipdata_code), ''), '__none__') AS hipdata_code,
            ic.group2 AS income_group2,
            COALESCE(NULLIF(TRIM($dept_code), ''), '__none__') AS dept_code,
            $dept_name AS dept_name,
            COUNT(*) AS item_count,
            SUM(CAST(o.sum_price AS DECIMAL(16,4))) AS total_price
        FROM opitemrece o
        INNER JOIN income ic ON ic.income = o.income
        " . irpt_sql_visit_joins() . "
        WHERE o.vstdate >= '$start_esc'
          AND o.vstdate <= '$end_esc'
          AND o.vstdate <> '0000-00-00'
          AND o.qty > 0
          AND CAST(o.sum_price AS DECIMAL(16,4)) > 0
          AND ic.group2 IS NOT NULL
        GROUP BY cache_ym, hipdata_code, income_group2, dept_code, dept_name
    ";
    $t0 = microtime(true);
    $q = mysqli_query($hos, $sql);
    if (!$q) {
        return ['rows' => [], 'error' => mysqli_error($hos), 'elapsed_ms' => 0];
    }
    $rows = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $rows[] = $row;
    }
    return ['rows' => $rows, 'error' => '', 'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000)];
}

function irpt_save_month_dept_cache($con, $year_month, array $rows) {
    irpt_ensure_dept_cache_table($con);
    $ym = rlu_escape($con, $year_month);
    mysqli_query($con, "DELETE FROM income_report_dept_monthly WHERE cache_ym='$ym'");
    $tuples = [];
    foreach ($rows as $row) {
        $hip = rlu_escape($con, trim($row['hipdata_code'] ?? ''));
        $gid = (int)($row['income_group2'] ?? 0);
        $code = rlu_escape($con, trim($row['dept_code'] ?? ''));
        if ($code === '__none__') {
            $code = '';
        }
        $name = rlu_escape($con, trim($row['dept_name'] ?? ''));
        $cnt = (int)($row['item_count'] ?? 0);
        $price = (float)($row['total_price'] ?? 0);
        $tuples[] = "('$ym', '$hip', $gid, '$code', '$name', $cnt, $price)";
    }
    return rlu_bulk_insert_values($con, 'income_report_dept_monthly',
        ['cache_ym', 'hipdata_code', 'income_group2', 'dept_code', 'dept_name', 'item_count', 'total_price'],
        $tuples);
}

function irpt_fetch_hosxp_month_doctor_stats($hos, $year_month) {
    $bounds = irpt_cache_month_bounds($year_month);
    if (!$bounds) {
        return ['rows' => [], 'error' => 'รูปแบบเดือนไม่ถูกต้อง', 'elapsed_ms' => 0];
    }
    $start_esc = mysqli_real_escape_string($hos, $bounds['start']);
    $end_esc = mysqli_real_escape_string($hos, $bounds['end']);
    $doctor_code = irpt_sql_doctor_code_expr();
    $sql = "
        SELECT
            COALESCE(NULLIF(TRIM(pt.hipdata_code), ''), '__none__') AS hipdata_code,
            ic.group2 AS income_group2,
            COALESCE(NULLIF(TRIM($doctor_code), ''), '__none__') AS doctor_code,
            CAST(CONVERT(
                COALESCE(
                    NULLIF(TRIM(doc.name), ''),
                    NULLIF(TRIM($doctor_code), ''),
                    'ไม่ระบุแพทย์'
                ) USING utf8) AS CHAR) AS doctor_name,
            COUNT(*) AS item_count,
            SUM(CAST(o.sum_price AS DECIMAL(16,4))) AS total_price,
            SUM(CASE WHEN o.an IS NULL OR TRIM(o.an) = '' THEN 1 ELSE 0 END) AS item_count_vn,
            SUM(CASE WHEN o.an IS NULL OR TRIM(o.an) = '' THEN CAST(o.sum_price AS DECIMAL(16,4)) ELSE 0 END) AS total_price_vn,
            SUM(CASE WHEN o.an IS NOT NULL AND TRIM(o.an) <> '' THEN 1 ELSE 0 END) AS item_count_an,
            SUM(CASE WHEN o.an IS NOT NULL AND TRIM(o.an) <> '' THEN CAST(o.sum_price AS DECIMAL(16,4)) ELSE 0 END) AS total_price_an,
            COUNT(DISTINCT NULLIF(TRIM(o.vn), '')) AS visit_count,
            COUNT(DISTINCT NULLIF(TRIM(ip.an), '')) AS an_count
        FROM opitemrece o
        INNER JOIN income ic ON ic.income = o.income
        " . irpt_sql_visit_joins() . "
        " . irpt_sql_ipt_join() . "
        WHERE o.vstdate >= '$start_esc'
          AND o.vstdate <= '$end_esc'
          AND o.vstdate <> '0000-00-00'
          AND o.qty > 0
          AND CAST(o.sum_price AS DECIMAL(16,4)) > 0
          AND ic.group2 IS NOT NULL
        GROUP BY hipdata_code, income_group2, doctor_code, doctor_name
    ";
    $t0 = microtime(true);
    $q = mysqli_query($hos, $sql);
    if (!$q) {
        return ['rows' => [], 'error' => mysqli_error($hos), 'elapsed_ms' => 0];
    }
    $rows = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $rows[] = $row;
    }
    return ['rows' => $rows, 'error' => '', 'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000)];
}

function irpt_fetch_hosxp_range_doctor_stats($hos, $start_date, $end_date) {
    $start_esc = mysqli_real_escape_string($hos, $start_date);
    $end_esc = mysqli_real_escape_string($hos, $end_date);
    $doctor_code = irpt_sql_doctor_code_expr();
    $sql = "
        SELECT
            DATE_FORMAT(o.vstdate, '%Y-%m') AS cache_ym,
            COALESCE(NULLIF(TRIM(pt.hipdata_code), ''), '__none__') AS hipdata_code,
            ic.group2 AS income_group2,
            COALESCE(NULLIF(TRIM($doctor_code), ''), '__none__') AS doctor_code,
            CAST(CONVERT(
                COALESCE(
                    NULLIF(TRIM(doc.name), ''),
                    NULLIF(TRIM($doctor_code), ''),
                    'ไม่ระบุแพทย์'
                ) USING utf8) AS CHAR) AS doctor_name,
            COUNT(*) AS item_count,
            SUM(CAST(o.sum_price AS DECIMAL(16,4))) AS total_price,
            SUM(CASE WHEN o.an IS NULL OR TRIM(o.an) = '' THEN 1 ELSE 0 END) AS item_count_vn,
            SUM(CASE WHEN o.an IS NULL OR TRIM(o.an) = '' THEN CAST(o.sum_price AS DECIMAL(16,4)) ELSE 0 END) AS total_price_vn,
            SUM(CASE WHEN o.an IS NOT NULL AND TRIM(o.an) <> '' THEN 1 ELSE 0 END) AS item_count_an,
            SUM(CASE WHEN o.an IS NOT NULL AND TRIM(o.an) <> '' THEN CAST(o.sum_price AS DECIMAL(16,4)) ELSE 0 END) AS total_price_an,
            COUNT(DISTINCT NULLIF(TRIM(o.vn), '')) AS visit_count,
            COUNT(DISTINCT NULLIF(TRIM(ip.an), '')) AS an_count
        FROM opitemrece o
        INNER JOIN income ic ON ic.income = o.income
        " . irpt_sql_visit_joins() . "
        " . irpt_sql_ipt_join() . "
        WHERE o.vstdate >= '$start_esc'
          AND o.vstdate <= '$end_esc'
          AND o.vstdate <> '0000-00-00'
          AND o.qty > 0
          AND CAST(o.sum_price AS DECIMAL(16,4)) > 0
          AND ic.group2 IS NOT NULL
        GROUP BY cache_ym, hipdata_code, income_group2, doctor_code, doctor_name
    ";
    $t0 = microtime(true);
    $q = mysqli_query($hos, $sql);
    if (!$q) {
        return ['rows' => [], 'error' => mysqli_error($hos), 'elapsed_ms' => 0];
    }
    $rows = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $rows[] = $row;
    }
    return ['rows' => $rows, 'error' => '', 'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000)];
}

function irpt_save_month_doctor_cache($con, $year_month, array $rows) {
    irpt_ensure_doctor_cache_table($con);
    $ym = rlu_escape($con, $year_month);
    mysqli_query($con, "DELETE FROM income_report_doctor_monthly WHERE cache_ym='$ym'");
    $tuples = [];
    foreach ($rows as $row) {
        $hip = rlu_escape($con, trim($row['hipdata_code'] ?? ''));
        $gid = (int)($row['income_group2'] ?? 0);
        $code = rlu_escape($con, trim($row['doctor_code'] ?? ''));
        if ($code === '__none__') {
            $code = '';
        }
        $name = rlu_escape($con, trim($row['doctor_name'] ?? ''));
        $cnt = (int)($row['item_count'] ?? 0);
        $price = (float)($row['total_price'] ?? 0);
        $cnt_vn = (int)($row['item_count_vn'] ?? 0);
        $price_vn = (float)($row['total_price_vn'] ?? 0);
        $cnt_an = (int)($row['item_count_an'] ?? 0);
        $price_an = (float)($row['total_price_an'] ?? 0);
        $vis = (int)($row['visit_count'] ?? 0);
        $anc = (int)($row['an_count'] ?? 0);
        $tuples[] = "('$ym', '$hip', $gid, '$code', '$name', $cnt, $price, $cnt_vn, $price_vn, $cnt_an, $price_an, $vis, $anc)";
    }
    return rlu_bulk_insert_values($con, 'income_report_doctor_monthly',
        ['cache_ym', 'hipdata_code', 'income_group2', 'doctor_code', 'doctor_name', 'item_count', 'total_price', 'item_count_vn', 'total_price_vn', 'item_count_an', 'total_price_an', 'visit_count', 'an_count'],
        $tuples);
}

function irpt_normalize_build_scope($scope) {
    $scope = strtolower(trim((string)$scope));
    return in_array($scope, ['item', 'dept', 'doctor', 'all'], true) ? $scope : 'item';
}

function irpt_build_month_item_cache($con, $hos, $year_month, $force = false) {
    irpt_ensure_item_cache_table($con);
    if (!$force && irpt_item_cache_month_exists($con, $year_month)) {
        return ['built' => false, 'cached' => true, 'year_month' => $year_month, 'elapsed_ms' => 0, 'scope' => 'item'];
    }
    $item_stats = irpt_fetch_hosxp_month_item_stats($hos, $year_month);
    if (($item_stats['error'] ?? '') !== '') {
        return ['built' => false, 'cached' => false, 'year_month' => $year_month, 'error' => $item_stats['error'], 'scope' => 'item'];
    }
    irpt_save_month_item_cache($con, $year_month, $item_stats['rows']);
    return [
        'built' => true,
        'cached' => true,
        'year_month' => $year_month,
        'item_rows' => count($item_stats['rows']),
        'elapsed_ms' => (int)($item_stats['elapsed_ms'] ?? 0),
        'scope' => 'item',
    ];
}

function irpt_build_month_dept_cache($con, $hos, $year_month, $force = false) {
    irpt_ensure_dept_cache_table($con);
    if (!$force && irpt_dept_cache_month_exists($con, $year_month)) {
        return ['built' => false, 'cached' => true, 'year_month' => $year_month, 'elapsed_ms' => 0, 'scope' => 'dept'];
    }
    $dept_stats = irpt_fetch_hosxp_month_dept_stats($hos, $year_month);
    if (($dept_stats['error'] ?? '') !== '') {
        return ['built' => false, 'cached' => false, 'year_month' => $year_month, 'error' => $dept_stats['error'], 'scope' => 'dept'];
    }
    irpt_save_month_dept_cache($con, $year_month, $dept_stats['rows']);
    return [
        'built' => true,
        'cached' => true,
        'year_month' => $year_month,
        'dept_rows' => count($dept_stats['rows']),
        'elapsed_ms' => (int)($dept_stats['elapsed_ms'] ?? 0),
        'scope' => 'dept',
    ];
}

function irpt_build_month_doctor_cache($con, $hos, $year_month, $force = false) {
    irpt_ensure_doctor_cache_table($con);
    if (!$force && irpt_doctor_cache_month_exists($con, $year_month)) {
        return ['built' => false, 'cached' => true, 'year_month' => $year_month, 'elapsed_ms' => 0, 'scope' => 'doctor'];
    }
    $doctor_stats = irpt_fetch_hosxp_month_doctor_stats($hos, $year_month);
    if (($doctor_stats['error'] ?? '') !== '') {
        return ['built' => false, 'cached' => false, 'year_month' => $year_month, 'error' => $doctor_stats['error'], 'scope' => 'doctor'];
    }
    irpt_save_month_doctor_cache($con, $year_month, $doctor_stats['rows']);
    return [
        'built' => true,
        'cached' => true,
        'year_month' => $year_month,
        'doctor_rows' => count($doctor_stats['rows']),
        'elapsed_ms' => (int)($doctor_stats['elapsed_ms'] ?? 0),
        'scope' => 'doctor',
    ];
}

function irpt_build_month_cache($con, $hos, $year_month, $force = false, $scope = 'item') {
    $scope = irpt_normalize_build_scope($scope);
    if ($scope === 'item') {
        return irpt_build_month_item_cache($con, $hos, $year_month, $force);
    }
    if ($scope === 'dept') {
        return irpt_build_month_dept_cache($con, $hos, $year_month, $force);
    }
    if ($scope === 'doctor') {
        return irpt_build_month_doctor_cache($con, $hos, $year_month, $force);
    }

    irpt_ensure_cache_tables($con);
    if (!$force && irpt_cache_month_exists($con, $year_month)) {
        return ['built' => false, 'cached' => true, 'year_month' => $year_month, 'elapsed_ms' => 0, 'scope' => 'all'];
    }
    $item_stats = irpt_fetch_hosxp_month_item_stats($hos, $year_month);
    if (($item_stats['error'] ?? '') !== '') {
        return ['built' => false, 'cached' => false, 'year_month' => $year_month, 'error' => $item_stats['error']];
    }
    $dept_stats = irpt_fetch_hosxp_month_dept_stats($hos, $year_month);
    if (($dept_stats['error'] ?? '') !== '') {
        return ['built' => false, 'cached' => false, 'year_month' => $year_month, 'error' => $dept_stats['error']];
    }
    $doctor_stats = irpt_fetch_hosxp_month_doctor_stats($hos, $year_month);
    if (($doctor_stats['error'] ?? '') !== '') {
        return ['built' => false, 'cached' => false, 'year_month' => $year_month, 'error' => $doctor_stats['error']];
    }
    irpt_save_month_item_cache($con, $year_month, $item_stats['rows']);
    irpt_save_month_dept_cache($con, $year_month, $dept_stats['rows']);
    irpt_save_month_doctor_cache($con, $year_month, $doctor_stats['rows']);
    $elapsed = (int)($item_stats['elapsed_ms'] ?? 0) + (int)($dept_stats['elapsed_ms'] ?? 0) + (int)($doctor_stats['elapsed_ms'] ?? 0);
    return [
        'built' => true,
        'cached' => true,
        'year_month' => $year_month,
        'item_rows' => count($item_stats['rows']),
        'dept_rows' => count($dept_stats['rows']),
        'doctor_rows' => count($doctor_stats['rows']),
        'elapsed_ms' => $elapsed,
        'scope' => 'all',
    ];
}

function irpt_months_needing_build($con, array $months, $scope, $force = false) {
    $scope = irpt_normalize_build_scope($scope);
    if ($force) {
        return array_values(array_unique($months));
    }
    return irpt_list_missing_dimension_cache_months($con, $months, $scope);
}

function irpt_build_months_cache_batch($con, &$hos, array $months, $force = false, $scope = 'item') {
    $scope = irpt_normalize_build_scope($scope);
    $months = rlu_parse_year_month_list($months);
    if (empty($months)) {
        return ['built' => false, 'cached' => false, 'error' => 'ไม่มีเดือนที่ต้องประมวลผล', 'scope' => $scope];
    }
    $to_build = irpt_months_needing_build($con, $months, $scope, $force);
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
        'item' => 'irpt_fetch_hosxp_range_item_stats',
        'dept' => 'irpt_fetch_hosxp_range_dept_stats',
        'doctor' => 'irpt_fetch_hosxp_range_doctor_stats',
    ];
    $save_map = [
        'item' => 'irpt_save_month_item_cache',
        'dept' => 'irpt_save_month_dept_cache',
        'doctor' => 'irpt_save_month_doctor_cache',
    ];
    $fetch_fn = $fetch_map[$scope] ?? 'irpt_fetch_hosxp_range_item_stats';
    $save_fn = $save_map[$scope] ?? 'irpt_save_month_item_cache';

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
            $save_fn($con, $ym, $by_ym[$ym] ?? []);
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

function irpt_cache_table_for_dimension($dimension) {
    if ($dimension === 'dept') {
        return 'income_report_dept_monthly';
    }
    if ($dimension === 'doctor') {
        return 'income_report_doctor_monthly';
    }
    return 'income_report_item_monthly';
}

function irpt_fetch_dimension_stats_from_cache($con, $start_date, $end_date, $dimension = 'item', array $options = []) {
    $dimension = trim((string)$dimension);
    if (!in_array($dimension, ['item', 'dept', 'doctor'], true)) {
        $dimension = 'item';
    }
    irpt_ensure_cache_tables($con);
    $months = rlu_month_keys_in_period($start_date, $end_date);
    if (empty($months)) {
        return ['rows' => [], 'error' => '', 'elapsed_ms' => 0, 'dimension' => $dimension, 'source' => 'cache'];
    }
    $ym_min = rlu_escape($con, min($months));
    $ym_max = rlu_escape($con, max($months));
    $filter = irpt_sql_cache_dimension_filter(
        $con,
        $options['income_group_ids'] ?? [],
        $options['hipdata_codes'] ?? []
    );
    [$cnt_col, $price_col] = irpt_cache_scope_exprs($options['visit_scope'] ?? '');

    if ($dimension === 'dept') {
        $table = 'income_report_dept_monthly';
        $sql = "
            SELECT dept_code AS row_code, MAX(dept_name) AS row_name,
                SUM(item_count) AS item_count, SUM(total_price) AS total_price
            FROM $table
            WHERE cache_ym >= '$ym_min' AND cache_ym <= '$ym_max' AND $filter
            GROUP BY dept_code
            HAVING item_count > 0 OR total_price > 0
            ORDER BY item_count DESC, row_name ASC
        ";
    } elseif ($dimension === 'doctor') {
        $table = 'income_report_doctor_monthly';
        $sql = "
            SELECT doctor_code AS row_code, MAX(doctor_name) AS row_name,
                SUM($cnt_col) AS item_count, SUM($price_col) AS total_price,
                SUM(visit_count) AS visit_count, SUM(an_count) AS an_count
            FROM $table
            WHERE cache_ym >= '$ym_min' AND cache_ym <= '$ym_max' AND $filter
            GROUP BY doctor_code
            HAVING item_count > 0 OR total_price > 0
            ORDER BY item_count DESC, row_name ASC
        ";
    } else {
        $table = 'income_report_item_monthly';
        $sql = "
            SELECT item_code AS row_code, MAX(item_name) AS row_name,
                SUM($cnt_col) AS item_count, SUM($price_col) AS total_price
            FROM $table
            WHERE cache_ym >= '$ym_min' AND cache_ym <= '$ym_max' AND $filter
            GROUP BY item_code
            HAVING item_count > 0 OR total_price > 0
            ORDER BY item_count DESC, row_name ASC
        ";
    }
    $limit = (int)($options['limit'] ?? 0);
    if ($limit > 0) {
        $sql .= ' LIMIT ' . max(1, min(2000, $limit));
    }

    $t0 = microtime(true);
    $q = mysqli_query($con, $sql);
    if (!$q) {
        return ['rows' => [], 'error' => mysqli_error($con), 'elapsed_ms' => 0, 'dimension' => $dimension, 'source' => 'cache'];
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
        'source' => 'cache',
    ];
}

function irpt_cache_period_case_sql($con, array $periods, $cnt_col, $price_col) {
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

function irpt_fetch_dimension_stats_multi_period_from_cache($con, array $periods, $dimension = 'item', array $options = []) {
    $period_count = count($periods);
    if ($period_count < 2 || $period_count > 4) {
        return null;
    }
    $dimension = trim((string)$dimension);
    if (!in_array($dimension, ['item', 'dept', 'doctor'], true)) {
        return null;
    }
    irpt_ensure_cache_tables($con);
    foreach ($periods as $period) {
        if (!irpt_cache_ready_for_period($con, $period['start_date'], $period['end_date'], $dimension)) {
            return null;
        }
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
    $filter = irpt_sql_cache_dimension_filter(
        $con,
        $options['income_group_ids'] ?? [],
        $options['hipdata_codes'] ?? []
    );
    [$cnt_col, $price_col] = irpt_cache_scope_exprs($options['visit_scope'] ?? '');
    [$case_cnt, $case_price] = irpt_cache_period_case_sql($con, $periods, $cnt_col, $price_col);

    if ($dimension === 'item') {
        $sql = "
            SELECT item_code AS row_code, MAX(item_name) AS row_name,
                $case_cnt,
                $case_price
            FROM income_report_item_monthly
            WHERE cache_ym >= '$ym_min' AND cache_ym <= '$ym_max' AND $filter
            GROUP BY item_code
            HAVING p0_cnt > 0
            ORDER BY p0_cnt DESC, row_name ASC
        ";
    } elseif ($dimension === 'doctor') {
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
            FROM income_report_doctor_monthly
            WHERE cache_ym >= '$ym_min' AND cache_ym <= '$ym_max' AND $filter
            GROUP BY doctor_code
            HAVING p0_cnt > 0
            ORDER BY p0_cnt DESC, row_name ASC
        ";
    } else {
        $sql = "
            SELECT dept_code AS row_code, MAX(dept_name) AS row_name,
                $case_cnt,
                $case_price
            FROM income_report_dept_monthly
            WHERE cache_ym >= '$ym_min' AND cache_ym <= '$ym_max' AND $filter
            GROUP BY dept_code
            HAVING p0_cnt > 0
            ORDER BY p0_cnt DESC, row_name ASC
        ";
    }
    $limit = (int)($options['limit'] ?? 0);
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

function irpt_collect_required_cache_months($start_date, $end_date, $compare_enabled, $compare_mode, array $params = []) {
    return rlu_collect_all_required_months($start_date, $end_date, $compare_enabled, $compare_mode, $params);
}

function irpt_list_missing_item_cache_months($con, array $months) {
    irpt_ensure_item_cache_table($con);
    $missing = [];
    foreach ($months as $ym) {
        if (!irpt_item_cache_month_exists($con, $ym)) {
            $missing[] = $ym;
        }
    }
    return $missing;
}

function irpt_list_missing_dept_cache_months($con, array $months) {
    irpt_ensure_dept_cache_table($con);
    $missing = [];
    foreach ($months as $ym) {
        if (!irpt_dept_cache_month_exists($con, $ym)) {
            $missing[] = $ym;
        }
    }
    return $missing;
}

function irpt_list_missing_doctor_cache_months($con, array $months) {
    irpt_ensure_doctor_cache_table($con);
    $missing = [];
    foreach ($months as $ym) {
        if (!irpt_doctor_cache_month_exists($con, $ym)) {
            $missing[] = $ym;
        }
    }
    return $missing;
}

function irpt_list_missing_dimension_cache_months($con, array $months, $dimension = 'item') {
    if ($dimension === 'dept') {
        return irpt_list_missing_dept_cache_months($con, $months);
    }
    if ($dimension === 'doctor') {
        return irpt_list_missing_doctor_cache_months($con, $months);
    }
    return irpt_list_missing_item_cache_months($con, $months);
}

/** รายงานสรุปจาก cache รายเดือน */
function irpt_aggregate_report_from_cache($con, $start_date, $end_date, array $income_group_ids, array $options = []) {
    irpt_ensure_cache_tables($con);
    $period = rlu_parse_period($start_date, $end_date);
    $months = rlu_month_keys_in_period($start_date, $end_date);
    if (empty($months)) {
        return rlu_empty_report($start_date, $end_date, 'ไม่มีช่วงวันที่');
    }
    $ym_min = rlu_escape($con, min($months));
    $ym_max = rlu_escape($con, max($months));
    $filter = irpt_sql_cache_dimension_filter($con, $income_group_ids, $options['hipdata_codes'] ?? []);
    [$cnt_col, $price_col] = irpt_cache_scope_exprs($options['visit_scope'] ?? '');
    $t0 = microtime(true);

    $sql_month = "
        SELECT cache_ym AS `year_month`,
            SUM($cnt_col) AS item_count,
            SUM($price_col) AS total_price
        FROM income_report_item_monthly
        WHERE cache_ym >= '$ym_min' AND cache_ym <= '$ym_max' AND $filter
        GROUP BY cache_ym
        ORDER BY cache_ym ASC
    ";
    $q = mysqli_query($con, $sql_month);
    if (!$q) {
        return rlu_empty_report($start_date, $end_date, mysqli_error($con));
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
        SELECT SUBSTRING(cache_ym, 1, 4) AS yr,
            SUM($cnt_col) AS item_count,
            SUM($price_col) AS total_price
        FROM income_report_item_monthly
        WHERE cache_ym >= '$ym_min' AND cache_ym <= '$ym_max' AND $filter
        GROUP BY yr
        ORDER BY yr DESC
    ";
    $q = mysqli_query($con, $sql_year);
    if (!$q) {
        return rlu_empty_report($start_date, $end_date, mysqli_error($con));
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
        SELECT SUBSTRING(cache_ym, 1, 4) AS yr,
            item_code,
            MAX(item_name) AS item_name,
            SUM($cnt_col) AS item_count,
            SUM($price_col) AS total_price
        FROM income_report_item_monthly
        WHERE cache_ym >= '$ym_min' AND cache_ym <= '$ym_max' AND $filter
        GROUP BY yr, item_code
        ORDER BY yr DESC, item_count DESC
        LIMIT 2000
    ";
    $q = mysqli_query($con, $sql_detail);
    if (!$q) {
        return rlu_empty_report($start_date, $end_date, mysqli_error($con));
    }
    $detail = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $cnt = (int)($row['item_count'] ?? 0);
        $price = (float)($row['total_price'] ?? 0);
        $detail[] = [
            'year' => (int)($row['yr'] ?? 0),
            'item_code' => trim($row['item_code'] ?? ''),
            'item_name' => trim($row['item_name'] ?? ''),
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
            'source' => 'cache_opitemrece',
            'income_group_ids' => rlu_parse_income_group_ids($income_group_ids),
            'hipdata_codes' => rlu_parse_hipdata_codes($options['hipdata_codes'] ?? []),
            'visit_scope' => rlu_normalize_visit_scope($options['visit_scope'] ?? ''),
            'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
        ],
        'period' => $period,
    ];
}

function irpt_item_doctor_cache_month_ready($con, $year_month, $item_code) {
    irpt_ensure_item_doctor_cache_table($con);
    $ym = rlu_escape($con, $year_month);
    $ic = rlu_escape($con, trim((string)$item_code));
    if ($ic === '') {
        return false;
    }
    $q = mysqli_query($con, "SELECT COUNT(*) AS c FROM income_report_item_doctor_monthly WHERE cache_ym='$ym' AND item_code='$ic' LIMIT 1");
    if (!$q) {
        return false;
    }
    $row = mysqli_fetch_assoc($q);
    return (int)($row['c'] ?? 0) > 0;
}

/** ดึงแพทย์ต่อรายการหนึ่งเดือน — กรอง icode + หมวดรายได้ + สิทธิ (ตรง rational_lab) */
function irpt_fetch_hosxp_month_item_doctor_stats($hos, $start_date, $end_date, $item_code, array $options = []) {
    return irpt_fetch_dimension_stats($hos, $start_date, $end_date, 'doctor', array_merge($options, [
        'item_code' => $item_code,
        'limit' => 0,
    ]), null);
}

function irpt_save_item_doctor_month_cache($con, $year_month, $item_code, array $rows) {
    irpt_ensure_item_doctor_cache_table($con);
    $ym = rlu_escape($con, $year_month);
    $ic = rlu_escape($con, trim((string)$item_code));
    mysqli_query($con, "DELETE FROM income_report_item_doctor_monthly WHERE cache_ym='$ym' AND item_code='$ic'");
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
        $cnt_vn = (int)($row['item_count_vn'] ?? 0);
        $price_vn = (float)($row['total_price_vn'] ?? 0);
        $cnt_an = (int)($row['item_count_an'] ?? 0);
        $price_an = (float)($row['total_price_an'] ?? 0);
        if (!isset($by_code[$key])) {
            $by_code[$key] = [
                'code' => $code,
                'name' => $name,
                'cnt' => $cnt,
                'price' => $price,
                'cnt_vn' => $cnt_vn,
                'price_vn' => $price_vn,
                'cnt_an' => $cnt_an,
                'price_an' => $price_an,
            ];
        } else {
            $by_code[$key]['cnt'] += $cnt;
            $by_code[$key]['price'] += $price;
            $by_code[$key]['cnt_vn'] += $cnt_vn;
            $by_code[$key]['price_vn'] += $price_vn;
            $by_code[$key]['cnt_an'] += $cnt_an;
            $by_code[$key]['price_an'] += $price_an;
            if ($by_code[$key]['name'] === '' && $name !== '') {
                $by_code[$key]['name'] = $name;
            }
        }
    }
    $tuples = [];
    foreach ($by_code as $entry) {
        $tuples[] = "('$ym','$ic','" . rlu_escape($con, $entry['code']) . "','" . rlu_escape($con, $entry['name']) . "',"
            . (int)$entry['cnt'] . ',' . (float)$entry['price'] . ','
            . (int)$entry['cnt_vn'] . ',' . (float)$entry['price_vn'] . ','
            . (int)$entry['cnt_an'] . ',' . (float)$entry['price_an'] . ')';
    }
    return rlu_bulk_insert_values($con, 'income_report_item_doctor_monthly', [
        'cache_ym', 'item_code', 'doctor_code', 'doctor_name',
        'item_count', 'total_price', 'item_count_vn', 'total_price_vn', 'item_count_an', 'total_price_an',
    ], $tuples);
}

/** รวมแพทย์ที่สั่งรายการหนึ่งจาก cache รายเดือน */
function irpt_fetch_item_doctors_period_from_cache($con, $start_date, $end_date, $item_code, $limit = 200) {
    irpt_ensure_item_doctor_cache_table($con);
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
            SUM(total_price) AS total_price,
            SUM(item_count_vn) AS item_count_vn,
            SUM(total_price_vn) AS total_price_vn,
            SUM(item_count_an) AS item_count_an,
            SUM(total_price_an) AS total_price_an
        FROM income_report_item_doctor_monthly
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
            'item_count_vn' => (int)($row['item_count_vn'] ?? 0),
            'total_price_vn' => (float)($row['total_price_vn'] ?? 0),
            'item_count_an' => (int)($row['item_count_an'] ?? 0),
            'total_price_an' => (float)($row['total_price_an'] ?? 0),
        ];
    }
    return [
        'rows' => $rows,
        'error' => '',
        'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
    ];
}

/** แพทย์ที่สั่งรายการยา/เวชภัณฑ์หนึ่งรายการ — cache รายเดือนต่อ icode (ตรง rlu_build_item_doctors_detail) */
function irpt_build_item_doctors_detail_impl($hos, array $periods, $item_code, array $options = [], $con = null) {
    $item_code = trim((string)$item_code);
    if ($item_code === '') {
        return ['rows' => [], 'periods' => $periods, 'error' => 'ไม่ระบุรหัสรายการ', 'dimension' => 'doctor'];
    }
    $opt = array_merge([
        'item_code' => $item_code,
        'limit' => 200,
        'income_group_ids' => $options['income_group_ids'] ?? [],
        'hipdata_codes' => $options['hipdata_codes'] ?? [],
    ], $options);

    if (!$hos) {
        return ['rows' => [], 'periods' => $periods, 'error' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้', 'dimension' => 'doctor'];
    }
    if (!$con) {
        return irpt_build_dimension_detail($hos, $periods, 'doctor', $opt, null);
    }

    $elapsed = 0;
    $item_map = [];
    foreach ($periods as $pi => $period) {
        $months = rlu_month_keys_in_period($period['start_date'], $period['end_date']);
        foreach ($months as $ym) {
            if (irpt_item_doctor_cache_month_ready($con, $ym, $item_code)) {
                continue;
            }
            $bounds = rlu_month_bounds($ym);
            if ($bounds === null) {
                continue;
            }
            $stats = irpt_fetch_hosxp_month_item_doctor_stats(
                $hos,
                $bounds['start'],
                $bounds['end'],
                $item_code,
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
            if (!irpt_save_item_doctor_month_cache($con, $ym, $item_code, $stats['rows'])) {
                $db_err = mysqli_error($con);
                return [
                    'rows' => [],
                    'periods' => $periods,
                    'error' => 'บันทึก cache แพทย์ต่อรายการไม่สำเร็จ' . ($db_err !== '' ? (': ' . $db_err) : ''),
                    'elapsed_ms' => $elapsed,
                    'dimension' => 'doctor',
                ];
            }
        }
        $cached = irpt_fetch_item_doctors_period_from_cache($con, $period['start_date'], $period['end_date'], $item_code, 200);
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
