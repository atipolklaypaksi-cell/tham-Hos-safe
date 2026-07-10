<?php
/**
 * รายงานตามหมวดรายได้ (income.group2) — ดึงจาก opitemrece รายการย่อย แบบ Grafana
 */
require_once __DIR__ . '/rational_lab_use_helper.php';
require_once __DIR__ . '/hosxp_tmt_drug_helper.php';

function irpt_fetch_drug_strength_map($hos, array $icodes) {
    $icodes = array_values(array_unique(array_filter(array_map(function ($c) {
        return trim((string)$c);
    }, $icodes))));
    if (empty($icodes) || !$hos) {
        return [];
    }
    $chunks = array_chunk($icodes, 400);
    $map = [];
    $strength_expr = hosxp_sql_tmt_strength('d', 'tm');
    foreach ($chunks as $chunk) {
        $escaped = [];
        foreach ($chunk as $ic) {
            $escaped[] = "'" . mysqli_real_escape_string($hos, $ic) . "'";
        }
        if (empty($escaped)) {
            continue;
        }
        $sql = "
            SELECT d.icode,
                   CAST(CONVERT($strength_expr USING utf8) AS CHAR) AS strength
            FROM drugitems d
            " . hosxp_sql_tmt_join('d', 'tm') . "
            WHERE d.icode IN (" . implode(',', $escaped) . ")
        ";
        $q = mysqli_query($hos, $sql);
        if (!$q) {
            continue;
        }
        while ($row = mysqli_fetch_assoc($q)) {
            $code = trim($row['icode'] ?? '');
            $strength = trim($row['strength'] ?? '');
            if ($code !== '' && $strength !== '') {
                $map[$code] = $strength;
            }
        }
    }
    return $map;
}

function irpt_fetch_item_catalog_name_map($hos, array $icodes) {
    $icodes = array_values(array_unique(array_filter(array_map(function ($c) {
        return trim((string)$c);
    }, $icodes))));
    if (empty($icodes) || !$hos) {
        return [];
    }
    $map = [];
    $chunks = array_chunk($icodes, 400);
    $queries = [
        ['drugitems', 'icode', 'name'],
        ['s_drugitems', 'icode', 'name'],
        ['nondrugitems', 'icode', 'name'],
        ['lab_items', 'lab_items_code', 'lab_items_name'],
    ];
    foreach ($queries as $spec) {
        [$table, $code_col, $name_col] = $spec;
        foreach ($chunks as $chunk) {
            $escaped = [];
            foreach ($chunk as $ic) {
                $escaped[] = "'" . mysqli_real_escape_string($hos, $ic) . "'";
            }
            if (empty($escaped)) {
                continue;
            }
            $sql = "
                SELECT $code_col AS icode,
                       CAST(CONVERT($name_col USING utf8) AS CHAR) AS item_name
                FROM $table
                WHERE $code_col IN (" . implode(',', $escaped) . ")
            ";
            $q = @mysqli_query($hos, $sql);
            if (!$q) {
                continue;
            }
            while ($row = mysqli_fetch_assoc($q)) {
                $code = trim($row['icode'] ?? '');
                $name = trim($row['item_name'] ?? '');
                if ($code !== '' && $name !== '' && !isset($map[$code])) {
                    $map[$code] = $name;
                }
            }
        }
    }
    return $map;
}

function irpt_fill_missing_item_names($hos, array $rows) {
    if (empty($rows) || !$hos) {
        return $rows;
    }
    $need_codes = [];
    foreach ($rows as $r) {
        $code = trim((string)($r['item_code'] ?? $r['row_code'] ?? $r['group_code'] ?? ''));
        if ($code === '') {
            continue;
        }
        $name = trim((string)($r['item_name'] ?? $r['row_name'] ?? $r['group_name'] ?? ''));
        if ($name === '' || strcasecmp($name, $code) === 0) {
            $need_codes[] = $code;
        }
    }
    if (empty($need_codes)) {
        return $rows;
    }
    $name_map = irpt_fetch_item_catalog_name_map($hos, $need_codes);
    if (empty($name_map)) {
        return $rows;
    }
    foreach ($rows as &$r) {
        $code = trim((string)($r['item_code'] ?? $r['row_code'] ?? $r['group_code'] ?? ''));
        if ($code === '' || !isset($name_map[$code])) {
            continue;
        }
        $resolved = $name_map[$code];
        foreach (['item_name', 'row_name', 'group_name'] as $key) {
            if (!isset($r[$key])) {
                continue;
            }
            $cur = trim((string)$r[$key]);
            if ($cur === '' || strcasecmp($cur, $code) === 0) {
                $r[$key] = $resolved;
            }
        }
    }
    unset($r);
    return $rows;
}

function irpt_enrich_rows_item_display_names($hos, array $rows) {
    if (empty($rows) || !$hos) {
        return $rows;
    }
    $rows = irpt_fill_missing_item_names($hos, $rows);
    $codes = [];
    foreach ($rows as $r) {
        $c = trim((string)($r['item_code'] ?? $r['row_code'] ?? $r['group_code'] ?? ''));
        if ($c !== '') {
            $codes[] = $c;
        }
    }
    $strength_map = irpt_fetch_drug_strength_map($hos, $codes);
    if (empty($strength_map)) {
        return $rows;
    }
    foreach ($rows as &$r) {
        $code = trim((string)($r['item_code'] ?? $r['row_code'] ?? $r['group_code'] ?? ''));
        if ($code === '' || !isset($strength_map[$code])) {
            continue;
        }
        $strength = $strength_map[$code];
        foreach (['item_name', 'row_name', 'group_name'] as $key) {
            if (!isset($r[$key]) || trim((string)$r[$key]) === '') {
                continue;
            }
            $r[$key] = di_format_drug_display_name($r[$key], $strength);
        }
    }
    unset($r);
    return $rows;
}

function irpt_maybe_enrich_item_rows($hos, $dimension, array $rows) {
    if (trim((string)$dimension) !== 'item' || !$hos || empty($rows)) {
        return $rows;
    }
    return irpt_enrich_rows_item_display_names($hos, $rows);
}

function irpt_sql_visit_joins() {
    return "
        LEFT JOIN ovst ov ON ov.vn = o.vn AND o.vn IS NOT NULL AND TRIM(o.vn) <> ''
        LEFT JOIN ovstdiag ovdiag ON ovdiag.vn = ov.vn AND ovdiag.diagtype = '1'
        LEFT JOIN spclty sp ON sp.spclty = ov.spclty
        LEFT JOIN kskdepartment kdep ON kdep.depcode = ov.main_dep
        LEFT JOIN doctor doc ON doc.code = COALESCE(
            NULLIF(NULLIF(TRIM(ovdiag.doctor), ''), '00000'),
            NULLIF(NULLIF(TRIM(ov.doctor), ''), '00000')
        )
        LEFT JOIN pttype pt ON pt.pttype = ov.pttype
    ";
}

function irpt_sql_ipt_join() {
    return "
        LEFT JOIN ipt ip ON ip.vn = o.vn AND o.vn IS NOT NULL AND TRIM(o.vn) <> ''
    ";
}

function irpt_sql_doctor_code_expr() {
    return "NULLIF(TRIM(
        COALESCE(
            NULLIF(NULLIF(TRIM(ovdiag.doctor), ''), '00000'),
            NULLIF(NULLIF(TRIM(ov.doctor), ''), '00000')
        )
    ), '')";
}

function irpt_sql_dept_code_expr() {
    return "COALESCE(NULLIF(TRIM(ov.main_dep), ''), NULLIF(TRIM(ov.spclty), ''))";
}

function irpt_sql_dept_name_expr() {
    return rlu_sql_lab_dept_name_expr();
}

function irpt_sql_item_catalog_joins() {
    return "
        LEFT JOIN drugitems d ON d.icode = o.icode
        LEFT JOIN s_drugitems sd ON sd.icode = o.icode
        LEFT JOIN nondrugitems nd ON nd.icode = o.icode
        LEFT JOIN lab_items li ON li.lab_items_code = o.icode
    ";
}

function irpt_sql_item_name_expr() {
    return "CAST(CONVERT(
        COALESCE(
            NULLIF(TRIM(d.name), ''),
            NULLIF(TRIM(sd.name), ''),
            NULLIF(TRIM(nd.name), ''),
            NULLIF(TRIM(li.lab_items_name), ''),
            o.icode
        ) USING utf8) AS CHAR)";
}

/** กรองสิทธิแบบ EXISTS — เร็วกว่า JOIN หลายตารางเมื่อไม่ต้องใช้คอลัมน์ ovst */
function irpt_sql_hipdata_exists_filter($hos, array $codes) {
    $codes = rlu_parse_hipdata_codes($codes);
    if (empty($codes)) {
        return '';
    }
    $escaped = [];
    foreach ($codes as $code) {
        $escaped[] = "'" . mysqli_real_escape_string($hos, $code) . "'";
    }
    return " AND EXISTS (
        SELECT 1 FROM ovst ov2
        INNER JOIN pttype pt2 ON pt2.pttype = ov2.pttype
        WHERE ov2.vn = o.vn
          AND o.vn IS NOT NULL AND TRIM(o.vn) <> ''
          AND pt2.hipdata_code IN (" . implode(',', $escaped) . ")
    )";
}

function irpt_hipdata_use_exists($dimension, array $options = []) {
    $hipdata_codes = rlu_parse_hipdata_codes($options['hipdata_codes'] ?? []);
    if (empty($hipdata_codes)) {
        return false;
    }
    $dimension = trim((string)$dimension);
    $filter_doctor = trim((string)($options['doctor_code'] ?? ''));
    return $dimension === 'item' && $filter_doctor === '';
}

function irpt_sql_base_where($hos, $start_date, $end_date, array $income_group_ids, array $options = []) {
    $start_esc = mysqli_real_escape_string($hos, $start_date);
    $end_esc = mysqli_real_escape_string($hos, $end_date);
    $income_filter = rlu_sql_income_group_filter($hos, $income_group_ids);
    $where = "
        WHERE o.vstdate >= '$start_esc'
          AND o.vstdate <= '$end_esc'
          AND o.vstdate <> '0000-00-00'
          AND o.qty > 0
          AND CAST(o.sum_price AS DECIMAL(16,4)) > 0
          $income_filter
    ";
    $scope = rlu_normalize_visit_scope($options['visit_scope'] ?? '');
    if ($scope === 'vn') {
        $where .= " AND (o.an IS NULL OR TRIM(o.an) = '')";
    } elseif ($scope === 'an') {
        $where .= " AND o.an IS NOT NULL AND TRIM(o.an) <> ''";
    }
    $doctor_code = trim((string)($options['doctor_code'] ?? ''));
    if ($doctor_code !== '') {
        $dc_expr = irpt_sql_doctor_code_expr();
        if ($doctor_code === '__none__') {
            $doctor_code = '';
        }
        if ($doctor_code === '') {
            $where .= " AND ($dc_expr IS NULL OR TRIM($dc_expr) = '')";
        } else {
            $dc_esc = mysqli_real_escape_string($hos, $doctor_code);
            $where .= " AND TRIM($dc_expr) = '$dc_esc'";
        }
    }
    $item_code = trim((string)($options['item_code'] ?? ''));
    if ($item_code !== '') {
        $item_esc = mysqli_real_escape_string($hos, $item_code);
        $where .= " AND TRIM(o.icode) = '$item_esc'";
    }
    $hipdata_codes = rlu_parse_hipdata_codes($options['hipdata_codes'] ?? []);
    if (!empty($hipdata_codes)) {
        if (!empty($options['hipdata_via_exists'])) {
            $where .= irpt_sql_hipdata_exists_filter($hos, $hipdata_codes);
        } else {
            $where .= rlu_sql_hipdata_filter($hos, $hipdata_codes);
        }
    }
    return $where;
}

function irpt_sql_from_joins($dimension = 'item', $needs_doctor = false, array $options = []) {
    $joins = "
        FROM opitemrece o
        INNER JOIN income ic ON ic.income = o.income
        " . irpt_sql_item_catalog_joins() . "
    ";
    $hipdata_codes = rlu_parse_hipdata_codes($options['hipdata_codes'] ?? []);
    $use_hipdata_exists = irpt_hipdata_use_exists($dimension, $options);
    $needs_visit = ($dimension !== 'item') || $needs_doctor || (!empty($hipdata_codes) && !$use_hipdata_exists);
    if ($needs_visit) {
        $joins .= irpt_sql_visit_joins();
    }
    if ($dimension === 'doctor' || !empty($needs_doctor)) {
        $joins .= irpt_sql_ipt_join();
    }
    return $joins;
}

function irpt_global_dimension_limit($dimension) {
    if ($dimension === 'item') {
        return 500;
    }
    if (in_array($dimension, ['dept', 'doctor'], true)) {
        return 300;
    }
    return 0;
}

/** สถิติรายการ/แผนก/แพทย์ ช่วงวันที่เดียว */
function irpt_fetch_dimension_stats($hos, $start_date, $end_date, $dimension = 'item', array $options = [], $con = null) {
    $income_group_ids = rlu_parse_income_group_ids($options['income_group_ids'] ?? []);
    if (empty($income_group_ids)) {
        return ['rows' => [], 'error' => 'กรุณาเลือกหมวดรายได้อย่างน้อย 1 หมวด', 'elapsed_ms' => 0, 'dimension' => $dimension];
    }
    $dimension = trim((string)$dimension);
    if (!in_array($dimension, ['item', 'dept', 'doctor'], true)) {
        $dimension = 'item';
    }
    if ($con && irpt_can_use_cache($options, $dimension) && irpt_cache_ready_for_period($con, $start_date, $end_date, $dimension)) {
        $result = irpt_fetch_dimension_stats_from_cache($con, $start_date, $end_date, $dimension, $options);
        $result['rows'] = irpt_maybe_enrich_item_rows($hos, $dimension, $result['rows'] ?? []);
        return $result;
    }
    $filter_doctor = trim((string)($options['doctor_code'] ?? ''));
    $needs_doctor = ($filter_doctor !== '') || ($dimension === 'doctor');
    $query_opts = $options;
    if (irpt_hipdata_use_exists($dimension, $options)) {
        $query_opts['hipdata_via_exists'] = true;
    }
    $joins = irpt_sql_from_joins($dimension, $needs_doctor, $query_opts);
    $where = irpt_sql_base_where($hos, $start_date, $end_date, $income_group_ids, $query_opts);

    if ($dimension === 'dept') {
        $dept_code = irpt_sql_dept_code_expr();
        $dept_name = irpt_sql_dept_name_expr();
        $select = "
            COALESCE(NULLIF(TRIM($dept_code), ''), '__none__') AS row_code,
            $dept_name AS row_name
        ";
        $group_by = 'row_code, row_name';
    } elseif ($dimension === 'doctor') {
        $doctor_code = irpt_sql_doctor_code_expr();
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
            o.icode AS row_code,
            " . irpt_sql_item_name_expr() . " AS row_name
        ";
        $group_by = 'o.icode, row_name';
    }

    $visit_select = $dimension === 'doctor'
        ? ", COUNT(DISTINCT NULLIF(TRIM(o.vn), '')) AS visit_count"
            . ", COUNT(DISTINCT NULLIF(TRIM(ip.an), '')) AS an_count"
            . ", SUM(CASE WHEN o.an IS NULL OR TRIM(o.an) = '' THEN 1 ELSE 0 END) AS item_count_vn"
            . ", SUM(CASE WHEN o.an IS NULL OR TRIM(o.an) = '' THEN CAST(o.sum_price AS DECIMAL(16,4)) ELSE 0 END) AS total_price_vn"
            . ", SUM(CASE WHEN o.an IS NOT NULL AND TRIM(o.an) <> '' THEN 1 ELSE 0 END) AS item_count_an"
            . ", SUM(CASE WHEN o.an IS NOT NULL AND TRIM(o.an) <> '' THEN CAST(o.sum_price AS DECIMAL(16,4)) ELSE 0 END) AS total_price_an"
        : '';

    $sql = "
        SELECT
            $select,
            COUNT(*) AS item_count,
            SUM(CAST(o.sum_price AS DECIMAL(16,4))) AS total_price
            $visit_select
        $joins
        $where
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
        return ['rows' => [], 'error' => mysqli_error($hos), 'elapsed_ms' => 0, 'dimension' => $dimension];
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
            $entry['item_count_vn'] = (int)($row['item_count_vn'] ?? 0);
            $entry['total_price_vn'] = (float)($row['total_price_vn'] ?? 0);
            $entry['item_count_an'] = (int)($row['item_count_an'] ?? 0);
            $entry['total_price_an'] = (float)($row['total_price_an'] ?? 0);
        }
        $rows[] = $entry;
    }

    $rows = irpt_maybe_enrich_item_rows($hos, $dimension, $rows);

    return [
        'rows' => $rows,
        'error' => '',
        'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
        'dimension' => $dimension,
        'source' => 'hosxp',
    ];
}

/**
 * ดึงหลายช่วงในครั้งเดียว (สแกน opitemrece รอบเดียว) — ใช้กับมิติ item + เปรียบเทียบ
 * @return array|null null = ให้ fallback เป็นรายช่วง
 */
function irpt_fetch_dimension_stats_multi_period($hos, array $periods, $dimension = 'item', array $options = [], $con = null) {
    $period_count = count($periods);
    if ($period_count < 2 || $period_count > 4) {
        return null;
    }
    $dimension = trim((string)$dimension);
    if (!in_array($dimension, ['item', 'dept', 'doctor'], true)) {
        return null;
    }
    if (trim((string)($options['doctor_code'] ?? '')) !== '' || trim((string)($options['item_code'] ?? '')) !== '') {
        return null;
    }
    if ($con && irpt_can_use_cache($options, $dimension)) {
        $ready = true;
        foreach ($periods as $period) {
            $months = rlu_month_keys_in_period($period['start_date'], $period['end_date']);
            foreach ($months as $ym) {
                if ($dimension === 'item' && !irpt_item_cache_month_exists($con, $ym)) {
                    $ready = false;
                    break 2;
                }
                if ($dimension === 'dept' && !irpt_dept_cache_month_exists($con, $ym)) {
                    $ready = false;
                    break 2;
                }
                if ($dimension === 'doctor' && !irpt_doctor_cache_month_exists($con, $ym)) {
                    $ready = false;
                    break 2;
                }
            }
        }
        if ($ready) {
            return irpt_fetch_dimension_stats_multi_period_from_cache($con, $periods, $dimension, $options);
        }
    }
    if ($dimension !== 'item') {
        return null;
    }

    $income_group_ids = rlu_parse_income_group_ids($options['income_group_ids'] ?? []);
    if (empty($income_group_ids)) {
        return ['rows_by_period' => [], 'error' => 'กรุณาเลือกหมวดรายได้อย่างน้อย 1 หมวด', 'elapsed_ms' => 0, 'dimension' => 'item'];
    }

    $query_opts = $options;
    if (irpt_hipdata_use_exists('item', $options)) {
        $query_opts['hipdata_via_exists'] = true;
    }

    $date_or = [];
    $case_cnt = [];
    $case_price = [];
    foreach ($periods as $pi => $period) {
        $s = mysqli_real_escape_string($hos, $period['start_date']);
        $e = mysqli_real_escape_string($hos, $period['end_date']);
        $date_or[] = "(o.vstdate >= '$s' AND o.vstdate <= '$e')";
        $case_cnt[] = "SUM(CASE WHEN o.vstdate >= '$s' AND o.vstdate <= '$e' THEN 1 ELSE 0 END) AS p{$pi}_cnt";
        $case_price[] = "SUM(CASE WHEN o.vstdate >= '$s' AND o.vstdate <= '$e' THEN CAST(o.sum_price AS DECIMAL(16,4)) ELSE 0 END) AS p{$pi}_price";
    }

    $income_filter = rlu_sql_income_group_filter($hos, $income_group_ids);
    $joins = "
        FROM opitemrece o
        INNER JOIN income ic ON ic.income = o.income
        " . irpt_sql_item_catalog_joins() . "
    ";
    $where = "
        WHERE (" . implode(' OR ', $date_or) . ")
          AND o.vstdate <> '0000-00-00'
          AND o.qty > 0
          AND CAST(o.sum_price AS DECIMAL(16,4)) > 0
          $income_filter
    ";
    $scope = rlu_normalize_visit_scope($options['visit_scope'] ?? '');
    if ($scope === 'vn') {
        $where .= " AND (o.an IS NULL OR TRIM(o.an) = '')";
    } elseif ($scope === 'an') {
        $where .= " AND o.an IS NOT NULL AND TRIM(o.an) <> ''";
    }
    $hipdata_codes = rlu_parse_hipdata_codes($options['hipdata_codes'] ?? []);
    if (!empty($hipdata_codes)) {
        if (!empty($query_opts['hipdata_via_exists'])) {
            $where .= irpt_sql_hipdata_exists_filter($hos, $hipdata_codes);
        } else {
            $joins .= "
        LEFT JOIN ovst ov ON ov.vn = o.vn AND o.vn IS NOT NULL AND TRIM(o.vn) <> ''
        LEFT JOIN pttype pt ON pt.pttype = ov.pttype";
            $where .= rlu_sql_hipdata_filter($hos, $hipdata_codes);
        }
    }

    $item_name = irpt_sql_item_name_expr();

    $sql = "
        SELECT
            o.icode AS row_code,
            $item_name AS row_name,
            " . implode(",\n            ", array_merge($case_cnt, $case_price)) . "
        $joins
        $where
        GROUP BY o.icode, row_name
        HAVING p0_cnt > 0
        ORDER BY p0_cnt DESC, row_name ASC
    ";
    $limit = (int)($options['limit'] ?? 0);
    if ($limit > 0) {
        $sql .= ' LIMIT ' . max(1, min(2000, $limit));
    }

    $t0 = microtime(true);
    $q = mysqli_query($hos, $sql);
    if (!$q) {
        return ['rows_by_period' => [], 'error' => mysqli_error($hos), 'elapsed_ms' => 0, 'dimension' => 'item'];
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
            $rows_by_period[$pi][] = [
                'row_code' => $code,
                'row_name' => $name,
                'item_code' => $code,
                'item_name' => $name,
                'item_count' => $cnt,
                'total_price' => $price,
            ];
        }
    }

    return [
        'rows_by_period' => $rows_by_period,
        'error' => '',
        'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
        'dimension' => 'item',
        'source' => 'hosxp',
    ];
}

/** สรุปหลายช่วง — ช่วงแรกเป็นฐานอ้างอิง */
function irpt_build_dimension_detail($hos, array $periods, $dimension = 'item', array $options = [], $con = null) {
    if (empty($periods)) {
        return ['rows' => [], 'periods' => [], 'error' => 'ไม่มีช่วงวันที่', 'dimension' => $dimension];
    }
    $item_map = [];
    $elapsed = 0;
    $data_source = 'hosxp';

    $multi = irpt_fetch_dimension_stats_multi_period($hos, $periods, $dimension, $options, $con);
    if (is_array($multi) && ($multi['error'] ?? '') === '' && !empty($multi['rows_by_period'])) {
        $elapsed = (int)($multi['elapsed_ms'] ?? 0);
        if (($multi['source'] ?? '') === 'cache') {
            $data_source = 'cache';
        }
        foreach ($multi['rows_by_period'] as $pi => $period_rows) {
            foreach ($period_rows as $row) {
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
                $item_map[$key]['periods'][$pi] = [
                    'item_count' => $cnt,
                    'total_price' => $price,
                    'item_count_display' => rlu_format_number($cnt),
                    'total_price_display' => rlu_format_money($price),
                ];
            }
        }
    } else {
        if (is_array($multi) && ($multi['error'] ?? '') !== '') {
            return ['rows' => [], 'periods' => $periods, 'error' => $multi['error'], 'elapsed_ms' => (int)($multi['elapsed_ms'] ?? 0), 'dimension' => $dimension];
        }
        foreach ($periods as $pi => $period) {
            $stats = irpt_fetch_dimension_stats($hos, $period['start_date'], $period['end_date'], $dimension, $options, $con);
            $elapsed += (int)($stats['elapsed_ms'] ?? 0);
            if (($stats['source'] ?? '') === 'cache') {
                $data_source = 'cache';
            }
            if (($stats['error'] ?? '') !== '') {
                return ['rows' => [], 'periods' => $periods, 'error' => $stats['error'], 'elapsed_ms' => $elapsed, 'dimension' => $dimension];
            }
            foreach ($stats['rows'] as $row) {
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
                }
                $item_map[$key]['periods'][$pi] = $period_entry;
            }
        }
    }

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
        }
        $rows[] = $row_out;
    }

    usort($rows, function ($a, $b) {
        return ($b['a_item_count'] <=> $a['a_item_count']);
    });

    $rows = irpt_maybe_enrich_item_rows($hos, $dimension, $rows);

    $period_meta = [];
    foreach ($periods as $p) {
        $period_meta[] = [
            'label' => $p['label'] ?? '',
            'start_date' => $p['start_date'] ?? '',
            'end_date' => $p['end_date'] ?? '',
        ];
    }

    return [
        'rows' => $rows,
        'periods' => $period_meta,
        'multi_period' => $period_count > 1,
        'dimension' => $dimension,
        'error' => '',
        'elapsed_ms' => $elapsed,
        'data_source' => $data_source,
    ];
}

function irpt_build_global_dimension_comparison($hos, array $periods, $dimension = 'item', array $options = [], $con = null) {
    $dimension = trim((string)$dimension);
    if (!in_array($dimension, ['item', 'dept', 'doctor'], true)) {
        $dimension = 'item';
    }
    $opts = array_merge($options, [
        'limit' => irpt_global_dimension_limit($dimension),
        'visit_scope' => rlu_normalize_visit_scope($options['visit_scope'] ?? ''),
    ]);
    $detail = irpt_build_dimension_detail($hos, $periods, $dimension, $opts, $con);
    if (($detail['error'] ?? '') !== '') {
        return ['error' => $detail['error'], 'elapsed_ms' => (int)($detail['elapsed_ms'] ?? 0)];
    }
    $comp = rlu_dimension_detail_to_comparison($detail, $options);
    $comp['data_source'] = $detail['data_source'] ?? 'hosxp';
    return $comp;
}

/** รายงานสรุปช่วงวันที่ */
function irpt_aggregate_report($hos, $start_date, $end_date, array $income_group_ids, array $options = [], $con = null) {
    $ids = rlu_parse_income_group_ids($income_group_ids);
    if (empty($ids)) {
        return rlu_empty_report($start_date, $end_date, 'กรุณาเลือกหมวดรายได้อย่างน้อย 1 หมวด');
    }
    if ($con && irpt_can_use_cache($options, 'item') && irpt_cache_ready_for_period($con, $start_date, $end_date, 'item')) {
        $report = irpt_aggregate_report_from_cache($con, $start_date, $end_date, $ids, $options);
        $report['rows'] = irpt_maybe_enrich_item_rows($hos, 'item', $report['rows'] ?? []);
        return $report;
    }
    $query_opts = $options;
    if (irpt_hipdata_use_exists('item', $options)) {
        $query_opts['hipdata_via_exists'] = true;
    }
    $joins = "
        FROM opitemrece o
        INNER JOIN income ic ON ic.income = o.income
        " . irpt_sql_item_catalog_joins() . "
    ";
    $where = irpt_sql_base_where($hos, $start_date, $end_date, $ids, $query_opts);
    $base_from = $joins . "\n" . $where;

    $t0 = microtime(true);

    $sql_month = "
        SELECT DATE_FORMAT(o.vstdate, '%Y-%m') AS `year_month`,
            COUNT(*) AS item_count,
            SUM(CAST(o.sum_price AS DECIMAL(16,4))) AS total_price
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
        SELECT YEAR(o.vstdate) AS yr,
            COUNT(*) AS item_count,
            SUM(CAST(o.sum_price AS DECIMAL(16,4))) AS total_price
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

    $item_name = irpt_sql_item_name_expr();
    $sql_detail = "
        SELECT YEAR(o.vstdate) AS yr,
            o.icode AS item_code,
            $item_name AS item_name,
            COUNT(*) AS item_count,
            SUM(CAST(o.sum_price AS DECIMAL(16,4))) AS total_price
        $base_from
        GROUP BY yr, o.icode, item_name
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
            'item_count' => $cnt,
            'total_price' => $price,
            'item_count_display' => rlu_format_number($cnt),
            'total_price_display' => rlu_format_money($price),
        ];
    }
    $detail = irpt_maybe_enrich_item_rows($hos, 'item', $detail);

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
            'source' => 'hosxp_opitemrece',
            'income_group_ids' => $ids,
            'hipdata_codes' => rlu_parse_hipdata_codes($options['hipdata_codes'] ?? []),
            'visit_scope' => rlu_normalize_visit_scope($options['visit_scope'] ?? ''),
            'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
        ],
        'period' => $period,
    ];
}

function irpt_build_item_doctors_detail($hos, array $periods, $item_code, array $options = [], $con = null) {
    return irpt_build_item_doctors_detail_impl($hos, $periods, $item_code, $options, $con);
}

require_once __DIR__ . '/income_report_cache_helper.php';
