<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

include('connect.php');
include('hosxp_tmt_drug_helper.php');
mysqli_set_charset($con, 'utf8');

set_time_limit(120);

$organ_raw = isset($_GET['organ']) ? trim($_GET['organ']) : 'renal';
$organ = in_array($organ_raw, ['renal', 'hepatic', 'other'], true) ? $organ_raw : 'renal';
$renal_lab_types = ['GFR', 'CKD_EPI', 'SERUM_CR'];
$hepatic_lab_types = ['Bilirubin', 'Bilirubin_xULN', 'DirectBilirubin', 'IndirectBilirubin', 'AST', 'ALT', 'AST_xULN', 'ALT_xULN', 'AST_ALT_xULN'];
$other_lab_types = ['CUSTOM'];
$organ_lab_types = ($organ === 'renal') ? $renal_lab_types : (($organ === 'hepatic') ? $hepatic_lab_types : $other_lab_types);
ensure_drug_safety_rules_lab_items_columns($con);
ensure_drug_safety_rules_lab_condition_columns($con);
ensure_drug_safety_rules_patient_extra_columns($con);

$start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
    $start_date = date('Y-m-d', strtotime('-30 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    $end_date = date('Y-m-d');
}
if ($start_date > $end_date) {
    $tmp = $start_date;
    $start_date = $end_date;
    $end_date = $tmp;
}

$max_rows = 12000;
$response = [
    'status' => 'success',
    'hard_alerts' => [],
    'soft_alerts' => [],
    'meta' => [
        'organ' => $organ,
        'rule_count' => 0,
        'rules_selected' => 0,
        'rules_total' => 0,
        'orders_scanned' => 0,
        'orders_unique' => 0,
        'truncated' => false,
        'hard_count' => 0,
        'soft_count' => 0,
        'alert_count' => 0,
    ],
];

$has_drug_icode_col = ensure_drug_safety_rules_hosxp_columns($con);
ensure_drug_safety_rules_alert_level_column($con);
if ($has_drug_icode_col) {
    $rules_sql = "SELECT r.*,
            COALESCE(NULLIF(TRIM(r.drug_name), ''), d.name) AS drug_name,
            COALESCE(NULLIF(TRIM(r.drug_icode), ''), d.icode) AS icode
        FROM drug_safety_rules r
        LEFT JOIN drugitems d ON r.drug_id = d.id
        WHERE r.active_status = 1";
} else {
    $rules_sql = "SELECT r.*, d.name AS drug_name, d.icode AS icode
        FROM drug_safety_rules r
        INNER JOIN drugitems d ON r.drug_id = d.id
        WHERE r.active_status = 1";
}
$q_rules = mysqli_query($con, $rules_sql);
$all_rules = [];
$icodes = [];
$icode_to_drugid = [];
$rules_total = 0;

while ($r = mysqli_fetch_assoc($q_rules)) {
    if (!in_array($r['lab_type'], $organ_lab_types, true)) {
        continue;
    }
    $icode = trim($r['icode'] ?? '');
    if ($icode === '') {
        continue;
    }
    if ($organ === 'other' && trim($r['lab_items_code'] ?? '') === '') {
        continue;
    }
    $all_rules[$icode][] = $r;
    $icodes[] = $icode;
    $rules_total++;
}
$icodes = array_values(array_unique($icodes));

$rules = $all_rules;
$rule_ids_param = isset($_GET['rule_ids']) ? trim($_GET['rule_ids']) : '';
if ($rule_ids_param !== '') {
    $allowed_ids = [];
    foreach (explode(',', $rule_ids_param) as $rid) {
        $rid = (int)trim($rid);
        if ($rid > 0) {
            $allowed_ids[$rid] = true;
        }
    }
    if (!empty($allowed_ids)) {
        $filtered = [];
        foreach ($all_rules as $icode => $icode_rules) {
            foreach ($icode_rules as $rule) {
                if (!isset($allowed_ids[(int)$rule['id']])) {
                    continue;
                }
                $filtered[$icode][] = $rule;
            }
        }
        $rules = $filtered;
        $icodes = array_keys($rules);
    }
}

$rules_selected = 0;
foreach ($rules as $icode_rules) {
    $rules_selected += count($icode_rules);
}
$response['meta']['rule_count'] = $rules_selected;
$response['meta']['rules_selected'] = $rules_selected;
$response['meta']['rules_total'] = $rules_total;

if ($rules_selected === 0) {
    echo json_encode([
        'status' => 'error',
        'message' => 'ไม่ได้เลือกกฎที่ต้องการรายงาน — กรุณาเลือกอย่างน้อย 1 รายการยา',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($rules) || empty($icodes)) {
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

$icode_map_sql = "'" . implode("','", array_map(function ($v) use ($con) {
    return mysqli_real_escape_string($con, $v);
}, $icodes)) . "'";
$q_drug_map = mysqli_query($con, "SELECT id, icode FROM drugitems WHERE icode IN ($icode_map_sql)");
if ($q_drug_map) {
    while ($dm = mysqli_fetch_assoc($q_drug_map)) {
        $icode_to_drugid[trim($dm['icode'])] = (int)$dm['id'];
    }
}

$need_drug_ids = [];
foreach ($icodes as $ic) {
    if (!empty($icode_to_drugid[$ic])) {
        $need_drug_ids[] = (int)$icode_to_drugid[$ic];
    }
}
$need_drug_ids = array_values(array_unique($need_drug_ids));
$max_doses = [];
if (!empty($need_drug_ids)) {
    $did_sql = implode(',', $need_drug_ids);
    $q_pd = mysqli_query($con, "SELECT drug_item_id, dose, unit FROM protocol_drugs WHERE drug_item_id IN ($did_sql) ORDER BY dose DESC");
    while ($r = mysqli_fetch_assoc($q_pd)) {
        $did = (int)$r['drug_item_id'];
        if (!isset($max_doses[$did])) {
            $max_doses[$did] = ['dose' => (float)$r['dose'], 'unit' => trim($r['unit'])];
        }
    }
}

$q_config = mysqli_query($con, "SELECT config_name, config_value FROM sys_config WHERE config_name LIKE 'h\\_%' OR config_name LIKE 'lab\\_%'");
$sys = [];
while ($r = mysqli_fetch_assoc($q_config)) {
    $sys[$r['config_name']] = $r['config_value'];
}

$api_started = microtime(true);
$hos = hosxp_connect($con);
if (!$hos) {
    echo json_encode(['status' => 'error', 'message' => 'Connect HOSxP Failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$lab_lookback_days = 180;
$lab_min_date = date('Y-m-d', strtotime($start_date . " -{$lab_lookback_days} days"));

function monitor_lab_prefetch_vitals($hos, array $hn_padded_list, $tbl, $fld_bw, $fld_height, $min_date, $max_date) {
    $cache = [];
    if (empty($hn_padded_list)) {
        return $cache;
    }
    $min_esc = mysqli_real_escape_string($hos, $min_date);
    $max_esc = mysqli_real_escape_string($hos, $max_date);
    $per_hn_cap = 12;

    foreach (array_chunk(array_values(array_unique($hn_padded_list)), 80) as $hn_chunk) {
        $hn_in = "'" . implode("','", array_map(function ($h) use ($hos) {
            return mysqli_real_escape_string($hos, $h);
        }, $hn_chunk)) . "'";
        $sql = "SELECT hn, vstdate, $fld_bw AS bw, $fld_height AS height
            FROM $tbl
            WHERE hn IN ($hn_in)
              AND $fld_bw > 0
              AND vstdate >= '$min_esc'
              AND vstdate <= '$max_esc'
            ORDER BY hn, vstdate DESC";
        $q = mysqli_query($hos, $sql);
        if (!$q) {
            continue;
        }
        while ($r = mysqli_fetch_assoc($q)) {
            $hn_key = ltrim($r['hn'], '0');
            if (!isset($cache[$hn_key])) {
                $cache[$hn_key] = [];
            }
            if (count($cache[$hn_key]) >= $per_hn_cap) {
                continue;
            }
            $cache[$hn_key][] = [
                'vstdate' => $r['vstdate'],
                'bw' => (float)$r['bw'],
                'height' => (float)$r['height'],
            ];
        }
    }
    return $cache;
}

function monitor_lab_get_vitals_from_cache(array $cache, $hn, $order_date) {
    $hn_key = ltrim($hn, '0');
    foreach ($cache[$hn_key] ?? [] as $row) {
        if ($row['vstdate'] <= $order_date) {
            $w = $row['bw'];
            $h = $row['height'];
            $bsa = ($w > 0 && $h > 0) ? sqrt(($w * $h) / 3600) : 0;
            return ['w' => $w, 'h' => $h, 'bsa' => $bsa];
        }
    }
    return ['w' => 0, 'h' => 0, 'bsa' => 0];
}

function monitor_lab_patient_age_years($dob, $ref_date) {
    $dob = trim((string)$dob);
    $ref_date = trim((string)$ref_date);
    if ($dob === '' || $dob === '0000-00-00' || $ref_date === '' || $ref_date === '0000-00-00') {
        return 0;
    }
    try {
        return (new DateTime($ref_date))->diff(new DateTime($dob))->y;
    } catch (Exception $e) {
        return 0;
    }
}

function monitor_lab_match_patient_extra(array $rule, $age, $weight) {
    $use_age = !empty($rule['use_age_condition']);
    $use_weight = !empty($rule['use_weight_condition']);
    if (!$use_age && !$use_weight) {
        return true;
    }
    $age_ok = false;
    $weight_ok = false;
    if ($use_age) {
        $age_min = isset($rule['age_min']) && $rule['age_min'] !== '' && $rule['age_min'] !== null
            ? (float)$rule['age_min'] : 0;
        $age_max = isset($rule['age_max']) && $rule['age_max'] !== '' && $rule['age_max'] !== null
            ? (float)$rule['age_max'] : PHP_FLOAT_MAX;
        if ($age > 0 && $age >= $age_min && $age <= $age_max) {
            $age_ok = true;
        }
    }
    if ($use_weight) {
        $weight_min = isset($rule['weight_min']) && $rule['weight_min'] !== '' && $rule['weight_min'] !== null
            ? (float)$rule['weight_min'] : 0;
        $weight_max = isset($rule['weight_max']) && $rule['weight_max'] !== '' && $rule['weight_max'] !== null
            ? (float)$rule['weight_max'] : PHP_FLOAT_MAX;
        if ($weight > 0 && $weight >= $weight_min && $weight <= $weight_max) {
            $weight_ok = true;
        }
    }
    if ($use_age && $use_weight) {
        return $age_ok || $weight_ok;
    }
    if ($use_age) {
        return $age_ok;
    }
    return $weight_ok;
}

function monitor_lab_patient_extra_display(array $rule, $age, $weight) {
    $parts = [];
    if (!empty($rule['use_age_condition']) && $age > 0) {
        $parts[] = 'อายุ ' . $age . ' ปี';
    }
    if (!empty($rule['use_weight_condition']) && $weight > 0) {
        $parts[] = 'นน. ' . round($weight, 1) . ' kg';
    }
    return implode(' · ', $parts);
}

$icode_string = "'" . implode("','", array_map(function ($v) use ($hos) {
    return mysqli_real_escape_string($hos, $v);
}, $icodes)) . "'";
$start_esc = mysqli_real_escape_string($hos, $start_date);
$end_esc = mysqli_real_escape_string($hos, $end_date);

$method_expr = di_sql_method_expr('o');
$sql_hosxp = "
    SELECT o.hn, o.vstdate, o.vsttime, o.rxdate, o.rxtime,
        CAST(CONVERT(CONCAT(IFNULL(pt.pname, ''), IFNULL(pt.fname, ''), ' ', IFNULL(pt.lname, '')) USING utf8) AS CHAR) AS ptname,
        pt.sex AS gender, pt.birthday AS dob, o.icode, o.qty,
        CAST(CONVERT(COALESCE(NULLIF(TRIM(tm.strength), ''), d.strength) USING utf8) AS CHAR) AS strength,
        CAST(CONVERT(d.units USING utf8) AS CHAR) AS units,
        $method_expr AS method
    FROM opitemrece o
    INNER JOIN patient pt ON pt.hn = o.hn
    INNER JOIN drugitems d ON d.icode = o.icode
    LEFT JOIN tmt_tpu_code tm ON d.tpu_code_list = tm.tpu_code
    " . di_sql_method_joins('o') . "
    WHERE o.icode IN ($icode_string)
      AND o.qty > 0
      AND o.vstdate >= '$start_esc'
      AND o.vstdate <= '$end_esc'
    ORDER BY o.vstdate DESC, o.rxdate DESC, o.rxtime DESC, o.vsttime DESC
    LIMIT $max_rows
";

$q_orders = mysqli_query($hos, $sql_hosxp);
if (!$q_orders) {
    mysqli_close($hos);
    mysqli_close($con);
    echo json_encode(['status' => 'error', 'message' => 'SQL Error: ' . mysqli_error($hos)], JSON_UNESCAPED_UNICODE);
    exit;
}

$orders = [];
$orders_scanned = 0;
while ($row = mysqli_fetch_assoc($q_orders)) {
    $orders_scanned++;
    $orders[] = $row;
}
$truncated = ($orders_scanned >= $max_rows);
$response['meta']['orders_scanned'] = $orders_scanned;
$response['meta']['truncated'] = $truncated;

if (empty($orders)) {
    mysqli_close($hos);
    mysqli_close($con);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

$hn_padded = [];
$hn_list = [];
foreach ($orders as $pat) {
    $hn_padded[] = str_pad($pat['hn'], 9, '0', STR_PAD_LEFT);
    $hn_list[] = ltrim($pat['hn'], '0');
}
$hn_padded_unique = array_values(array_unique($hn_padded));

$rule_lab_types = [];
foreach ($rules as $icode_rules) {
    foreach ($icode_rules as $rule) {
        $rule_lab_types[$rule['lab_type']] = true;
    }
}

$tbl_vital = !empty($sys['h_tbl_vital']) ? $sys['h_tbl_vital'] : 'opdscreen';
$fld_bw = !empty($sys['h_fld_bw']) ? $sys['h_fld_bw'] : 'bw';
$fld_height = !empty($sys['h_fld_height']) ? $sys['h_fld_height'] : 'height';

$lab_codes = [];
$need_vitals = false;
if ($organ === 'other') {
    foreach ($rules as $icode_rules) {
        foreach ($icode_rules as $rule) {
            $c = trim($rule['lab_items_code'] ?? '');
            if ($c !== '') {
                $lab_codes[] = $c;
            }
        }
    }
    $lab_codes = array_values(array_unique($lab_codes));
} elseif ($organ === 'renal') {
    if ((!empty($rule_lab_types['GFR']) || !empty($rule_lab_types['SERUM_CR'])) && !empty($sys['h_code_scr'])) {
        $lab_codes[] = $sys['h_code_scr'];
    }
    if (!empty($rule_lab_types['GFR'])) {
        $need_vitals = true;
    }
    if (!empty($rule_lab_types['CKD_EPI']) && !empty($sys['h_code_ckd_epi'])) {
        $lab_codes[] = $sys['h_code_ckd_epi'];
    }
    foreach ($rules as $icode_rules) {
        foreach ($icode_rules as $rule) {
            if (!empty($rule['use_weight_condition'])) {
                $need_vitals = true;
                break 2;
            }
        }
    }
    $lab_codes = array_values(array_unique($lab_codes));
} else {
    $hepatic_code_map = [
        'AST' => $sys['h_code_ast'] ?? '',
        'AST_xULN' => $sys['h_code_ast'] ?? '',
        'ALT' => $sys['h_code_alt'] ?? '',
        'ALT_xULN' => $sys['h_code_alt'] ?? '',
        'Bilirubin' => $sys['h_code_tbili'] ?? '',
        'Bilirubin_xULN' => $sys['h_code_tbili'] ?? '',
        'DirectBilirubin' => $sys['h_code_dbili'] ?? '',
        'IndirectBilirubin' => $sys['h_code_ibili'] ?? '',
        'AST_ALT_xULN' => $sys['h_code_ast'] ?? '',
    ];
    foreach (array_keys($rule_lab_types) as $lt) {
        if (!empty($hepatic_code_map[$lt])) {
            $lab_codes[] = $hepatic_code_map[$lt];
        }
        if ($lt === 'AST_ALT_xULN' && !empty($sys['h_code_alt'])) {
            $lab_codes[] = $sys['h_code_alt'];
        }
    }
    $lab_codes = array_values(array_unique(array_filter($lab_codes)));
}

$lab_fetch_memo = [];
$vital_cache = $need_vitals
    ? monitor_lab_prefetch_vitals($hos, $hn_padded_unique, $tbl_vital, $fld_bw, $fld_height, $lab_min_date, $end_date)
    : [];
$tbili_uln = (float)($sys['lab_tbili_uln'] ?? 1.2);
$ast_uln = (float)($sys['lab_ast_uln'] ?? 40);
$alt_uln = (float)($sys['lab_alt_uln'] ?? 40);
if ($tbili_uln <= 0) {
    $tbili_uln = 1.2;
}
if ($ast_uln <= 0) {
    $ast_uln = 40;
}
if ($alt_uln <= 0) {
    $alt_uln = 40;
}

$processed_patients = [];
$processed_drug_checks = [];
$orders_unique = 0;

foreach ($orders as $pat) {
    $hn = ltrim($pat['hn'], '0');
    $icode = trim($pat['icode']);
    if (!isset($rules[$icode])) {
        continue;
    }
    $drug_id = $icode_to_drugid[$icode] ?? 0;
    $order_dt = monitor_lab_order_datetime($pat);
    $order_date = $order_dt['date'];
    $order_time_raw = $order_dt['time'];
    $vstdate = trim((string)($pat['vstdate'] ?? ''));
    if ($vstdate === '' || $vstdate === '0000-00-00') {
        $vstdate = $order_date;
    }

    $check_key = $hn . '_' . $icode . '_' . $order_date . '_' . monitor_lab_normalize_hosxp_time($order_time_raw);
    if (isset($processed_drug_checks[$check_key])) {
        continue;
    }
    $processed_drug_checks[$check_key] = true;
    $orders_unique++;

    $order_time_str = di_format_time($order_time_raw);
    $cache_key = $hn . '_' . $order_date . '_' . ($order_time_str !== '' ? $order_time_str : '00:00');

    if (!isset($processed_patients[$cache_key])) {
        $pt_age = monitor_lab_patient_age_years($pat['dob'] ?? '', $order_date);

        if ($organ === 'other') {
            $patient_labs = [
                'BSA' => 0,
                'Weight' => 0,
                'ptname' => trim($pat['ptname']),
            ];
            foreach ($lab_codes as $code) {
                $lab_data = monitor_lab_get_lab_for_order($hos, $lab_fetch_memo, $pat['hn'], $code, $order_date, $order_time_raw, $lab_min_date, $vstdate);
                $patient_labs[$code] = [
                    'val' => round($lab_data['value'], 4),
                    'raw' => $lab_data['raw'] ?? '',
                    'date' => $lab_data['date'],
                    'time' => $lab_data['time'],
                ];
            }
            $processed_patients[$cache_key] = $patient_labs;
        } elseif ($organ === 'renal') {
            $w = 0;
            $h = 0;
            $bsa = 0;
            if ($need_vitals) {
                $vitals = monitor_lab_get_vitals_from_cache($vital_cache, $pat['hn'], $order_date);
                $w = $vitals['w'];
                $h = $vitals['h'];
                $bsa = $vitals['bsa'];
            }

            $patient_labs = [
                'BSA' => $bsa,
                'Weight' => $w,
                'Age' => $pt_age,
                'ptname' => trim($pat['ptname']),
            ];

            $scr_data = ['value' => 0, 'date' => '-', 'time' => ''];
            if ((!empty($rule_lab_types['GFR']) || !empty($rule_lab_types['SERUM_CR'])) && !empty($sys['h_code_scr'])) {
                $scr_data = monitor_lab_get_lab_for_order($hos, $lab_fetch_memo, $pat['hn'], $sys['h_code_scr'] ?? '', $order_date, $order_time_raw, $lab_min_date, $vstdate);
            }

            if (!empty($rule_lab_types['GFR'])) {
                $gfr = 0;
                if ($pt_age > 0 && $w > 0 && $scr_data['value'] > 0) {
                    $gfr = ((140 - $pt_age) * $w) / (72 * $scr_data['value']);
                    if ($pat['gender'] == '2') {
                        $gfr = $gfr * 0.85;
                    }
                }
                $patient_labs['GFR'] = ['val' => round($gfr, 2), 'date' => $scr_data['date'], 'time' => $scr_data['time']];
            }
            if (!empty($rule_lab_types['SERUM_CR'])) {
                $patient_labs['SERUM_CR'] = ['val' => round($scr_data['value'], 2), 'date' => $scr_data['date'], 'time' => $scr_data['time']];
            }
            if (!empty($rule_lab_types['CKD_EPI'])) {
                $ckd_epi_data = monitor_lab_get_lab_for_order($hos, $lab_fetch_memo, $pat['hn'], $sys['h_code_ckd_epi'] ?? '', $order_date, $order_time_raw, $lab_min_date, $vstdate);
                $patient_labs['CKD_EPI'] = ['val' => round($ckd_epi_data['value'], 2), 'date' => $ckd_epi_data['date'], 'time' => $ckd_epi_data['time']];
            }

            $processed_patients[$cache_key] = $patient_labs;
        } else {
            $ast_data = monitor_lab_get_lab_for_order($hos, $lab_fetch_memo, $pat['hn'], $sys['h_code_ast'] ?? '', $order_date, $order_time_raw, $lab_min_date, $vstdate);
            $alt_data = monitor_lab_get_lab_for_order($hos, $lab_fetch_memo, $pat['hn'], $sys['h_code_alt'] ?? '', $order_date, $order_time_raw, $lab_min_date, $vstdate);
            $tbili_data = monitor_lab_get_lab_for_order($hos, $lab_fetch_memo, $pat['hn'], $sys['h_code_tbili'] ?? '', $order_date, $order_time_raw, $lab_min_date, $vstdate);
            $dbili_data = monitor_lab_get_lab_for_order($hos, $lab_fetch_memo, $pat['hn'], $sys['h_code_dbili'] ?? '', $order_date, $order_time_raw, $lab_min_date, $vstdate);
            $ibili_data = monitor_lab_get_lab_for_order($hos, $lab_fetch_memo, $pat['hn'], $sys['h_code_ibili'] ?? '', $order_date, $order_time_raw, $lab_min_date, $vstdate);

            $tbili_x = ($tbili_data['value'] > 0) ? round($tbili_data['value'] / $tbili_uln, 2) : 0;
            $ast_x = ($ast_data['value'] > 0) ? round($ast_data['value'] / $ast_uln, 2) : 0;
            $alt_x = ($alt_data['value'] > 0) ? round($alt_data['value'] / $alt_uln, 2) : 0;
            $max_x = max($ast_x, $alt_x);
            $max_x_date = ($ast_x >= $alt_x) ? $ast_data['date'] : $alt_data['date'];
            $max_x_time = ($ast_x >= $alt_x) ? $ast_data['time'] : $alt_data['time'];
            if ($max_x <= 0) {
                $max_x_date = '-';
                $max_x_time = '';
            }

            $processed_patients[$cache_key] = [
                'AST' => ['val' => $ast_data['value'], 'date' => $ast_data['date'], 'time' => $ast_data['time']],
                'ALT' => ['val' => $alt_data['value'], 'date' => $alt_data['date'], 'time' => $alt_data['time']],
                'Bilirubin' => ['val' => $tbili_data['value'], 'date' => $tbili_data['date'], 'time' => $tbili_data['time']],
                'Bilirubin_xULN' => ['val' => $tbili_x, 'date' => $tbili_data['date'], 'time' => $tbili_data['time']],
                'DirectBilirubin' => ['val' => $dbili_data['value'], 'date' => $dbili_data['date'], 'time' => $dbili_data['time']],
                'IndirectBilirubin' => ['val' => $ibili_data['value'], 'date' => $ibili_data['date'], 'time' => $ibili_data['time']],
                'AST_xULN' => ['val' => $ast_x, 'date' => $ast_data['date'], 'time' => $ast_data['time']],
                'ALT_xULN' => ['val' => $alt_x, 'date' => $alt_data['date'], 'time' => $alt_data['time']],
                'AST_ALT_xULN' => ['val' => $max_x, 'date' => $max_x_date, 'time' => $max_x_time],
                'BSA' => 0,
                'Weight' => 0,
                'ptname' => trim($pat['ptname']),
            ];
        }
    }

    $labs = $processed_patients[$cache_key];
    $patient_bsa = $labs['BSA'];
    $patient_wt = $labs['Weight'];
    $patient_age = $labs['Age'] ?? 0;

    $base_dose = 0;
    $base_unit = 'mg';
    if (isset($max_doses[$drug_id])) {
        $base_dose = $max_doses[$drug_id]['dose'];
        $base_unit = $max_doses[$drug_id]['unit'];
    }

    $dose_parsed = hosxp_parse_drug_daily_dose_mg([
        'qty' => $pat['qty'],
        'strength' => $pat['strength'] ?? '',
        'units' => $pat['units'] ?? '',
        'method' => $pat['method'] ?? '',
    ]);
    $actual_dose_val = ($dose_parsed['mg_dose'] !== null) ? (float)$dose_parsed['mg_dose'] : 0;
    $has_active_order = ($dose_parsed['mg_dose'] !== null && $dose_parsed['mg_dose'] > 0)
        || ($dose_parsed['pill_qty'] !== null && $dose_parsed['pill_qty'] > 0)
        || ((float)$pat['qty'] > 0);
    $actual_dose_str = hosxp_format_actual_dose_display($dose_parsed);
    $order_date_str = date('d/m/Y', strtotime($pat['vstdate']));
    $drug_method = trim($pat['method'] ?? '');

    foreach ($rules[$icode] as $rule) {
        $lab_type = $rule['lab_type'];
        $lab_key = ($organ === 'other') ? trim($rule['lab_items_code'] ?? '') : $lab_type;
        $cond_type = trim($rule['lab_condition_type'] ?? 'range');
        if ($cond_type === '') {
            $cond_type = 'range';
        }
        $cond_val = trim($rule['lab_condition_value'] ?? '');
        $min = (float)$rule['min_val'];
        $max = (float)$rule['max_val'];

        if ($lab_key === '' || !isset($labs[$lab_key])) {
            continue;
        }
        $current_val = $labs[$lab_key]['val'];
        $current_raw = $labs[$lab_key]['raw'] ?? '';
        $current_date = $labs[$lab_key]['date'];
        $current_time = $labs[$lab_key]['time'] ?? '';

        $lab_matched = false;
        $lab_value_display = $current_val;
        $lab_value_is_text = false;
        if ($organ === 'other' && $cond_type !== 'range') {
            if ($current_raw === '') {
                continue;
            }
            if (!monitor_lab_match_condition($cond_type, $cond_val, $current_raw)) {
                continue;
            }
            $lab_matched = true;
            $lab_value_display = $current_raw;
            $lab_value_is_text = true;
        } elseif ($current_val > 0 && $current_val >= $min && $current_val <= $max) {
            $lab_matched = true;
        }

        if ($lab_matched) {
            if (!monitor_lab_match_patient_extra($rule, $patient_age, $patient_wt)) {
                continue;
            }
            $patient_extra_text = monitor_lab_patient_extra_display($rule, $patient_age, $patient_wt);
            if ($lab_value_is_text) {
                if (!empty($rule['use_dose_condition'])) {
                    $dose_min = isset($rule['dose_min']) && $rule['dose_min'] !== '' && $rule['dose_min'] !== null
                        ? (float)$rule['dose_min'] : 0;
                    $dose_max = isset($rule['dose_max']) && $rule['dose_max'] !== '' && $rule['dose_max'] !== null
                        ? (float)$rule['dose_max'] : PHP_FLOAT_MAX;
                    if ($actual_dose_val <= 0) {
                        continue;
                    }
                    if ($actual_dose_val < $dose_min || $actual_dose_val > $dose_max) {
                        continue;
                    }
                }
                $alert_level = monitor_lab_alert_level_from_rule($rule);
                $text_alert = [
                    'hn' => $hn,
                    'ptname' => $labs['ptname'],
                    'drug_name' => $rule['drug_name'],
                    'order_date' => $order_date_str,
                    'order_time' => $order_time_str,
                    'drug_method' => $drug_method !== '' ? $drug_method : '-',
                    'lab_type' => $lab_type,
                    'lab_items_code' => trim($rule['lab_items_code'] ?? ''),
                    'lab_items_name' => trim($rule['lab_items_name'] ?? ''),
                    'lab_condition_type' => $cond_type,
                    'lab_condition_value' => $cond_val,
                    'lab_value' => $lab_value_display,
                    'lab_value_is_text' => true,
                    'lab_date' => $current_date,
                    'lab_time' => $current_time,
                    'warning_msg' => $rule['suggestion_msg'],
                    'patient_extra_text' => $patient_extra_text,
                    'actual_dose' => $actual_dose_str,
                    'recommended_dose' => '-',
                    'is_pass' => false,
                    'pass_text' => 'พบผลตามเงื่อนไข',
                ];
                if ($alert_level === 'hard') {
                    $response['hard_alerts'][] = $text_alert;
                } else {
                    $response['soft_alerts'][] = $text_alert;
                }
                continue;
            }
            if (!empty($rule['use_dose_condition'])) {
                $dose_min = isset($rule['dose_min']) && $rule['dose_min'] !== '' && $rule['dose_min'] !== null
                    ? (float)$rule['dose_min'] : 0;
                $dose_max = isset($rule['dose_max']) && $rule['dose_max'] !== '' && $rule['dose_max'] !== null
                    ? (float)$rule['dose_max'] : PHP_FLOAT_MAX;
                if ($actual_dose_val <= 0) {
                    continue;
                }
                if ($actual_dose_val < $dose_min || $actual_dose_val > $dose_max) {
                    continue;
                }
            }

            $action_type = $rule['action_type'];
            $action_value = (float)$rule['action_value'];
            $adj_base_dose = $base_dose;

            if ($action_type === 'percent') {
                $adj_base_dose = $base_dose - ($base_dose * ($action_value / 100));
            } elseif ($action_type === 'fixed') {
                $adj_base_dose = $action_value;
            } elseif ($action_type === 'omit') {
                $adj_base_dose = 0;
            }

            $recommended_dose_str = '-';
            $rec_calc = -1;

            if ($action_type === 'omit') {
                $recommended_dose_str = "<span class='text-danger fw-bold'>งดยา (Omit)</span>";
                $rec_calc = 0;
            } elseif ($adj_base_dose > 0) {
                $drug_name_lower = strtolower($rule['drug_name']);
                $unit_lower = strtolower(trim($base_unit));
                $disp_unit = (strpos($unit_lower, 'unit') !== false) ? 'unit' : 'mg';

                if (strpos($drug_name_lower, 'carboplatin') !== false && $current_val > 0) {
                    $auc = ($adj_base_dose <= 10) ? $adj_base_dose : 5;
                    $rec_calc = $auc * ($current_val + 25);
                    $recommended_dose_str = round($rec_calc, 2) . " mg <small class='text-muted'>(AUC $auc)</small>";
                } elseif (strpos($unit_lower, 'm2') !== false || strpos($unit_lower, 'm²') !== false) {
                    if ($patient_bsa > 0) {
                        $rec_calc = $adj_base_dose * $patient_bsa;
                        $recommended_dose_str = round($rec_calc, 2) . ' ' . $disp_unit;
                    } else {
                        $recommended_dose_str = 'รอข้อมูล นน./ส่วนสูง';
                    }
                } elseif (strpos($unit_lower, 'kg') !== false) {
                    if ($patient_wt > 0) {
                        $rec_calc = $adj_base_dose * $patient_wt;
                        $recommended_dose_str = round($rec_calc, 2) . ' ' . $disp_unit;
                    } else {
                        $recommended_dose_str = 'รอข้อมูล นน.';
                    }
                } else {
                    $rec_calc = $adj_base_dose;
                    $recommended_dose_str = $adj_base_dose . ' ' . $base_unit;
                }
            }

            $is_pass = false;
            $pass_text = 'รอข้อมูล';

            if ($rec_calc === 0) {
                $is_pass = !$has_active_order;
                $pass_text = $is_pass ? 'ผ่าน' : 'ไม่ผ่าน (คลาดเคลื่อน Omit)';
            } elseif ($rec_calc > 0) {
                if ($actual_dose_val <= 0) {
                    $is_pass = false;
                    $pass_text = 'ไม่ผ่าน (ไม่ได้สั่งยา)';
                } else {
                    $diff_percent = abs(($actual_dose_val - $rec_calc) / $rec_calc) * 100;
                    if ($diff_percent <= 5.0) {
                        $is_pass = true;
                        $pass_text = 'ผ่าน';
                    } else {
                        $is_pass = false;
                        $pass_text = 'ไม่ผ่าน (คลาดเคลื่อน ' . round($diff_percent, 1) . '%)';
                    }
                }
            }

            $alert_level = monitor_lab_alert_level_from_rule($rule);
            $alert_data = [
                'hn' => $hn,
                'ptname' => $labs['ptname'],
                'drug_name' => $rule['drug_name'],
                'order_date' => $order_date_str,
                'order_time' => $order_time_str,
                'drug_method' => $drug_method !== '' ? $drug_method : '-',
                'lab_type' => $lab_type,
                'lab_items_code' => trim($rule['lab_items_code'] ?? ''),
                'lab_items_name' => trim($rule['lab_items_name'] ?? ''),
                'lab_condition_type' => $cond_type,
                'lab_condition_value' => $cond_val,
                'lab_value' => $current_val,
                'lab_value_is_text' => false,
                'lab_date' => $current_date,
                'lab_time' => $current_time,
                'warning_msg' => $rule['suggestion_msg'],
                'patient_extra_text' => $patient_extra_text,
                'actual_dose' => $actual_dose_str,
                'recommended_dose' => $recommended_dose_str,
                'is_pass' => $is_pass,
                'pass_text' => $pass_text,
                'alert_level' => $alert_level,
            ];

            if ($alert_level === 'hard') {
                $response['hard_alerts'][] = $alert_data;
            } else {
                $response['soft_alerts'][] = $alert_data;
            }
        }
    }
}

mysqli_close($hos);
mysqli_close($con);

$response['hard_alerts'] = array_values(array_map('unserialize', array_unique(array_map('serialize', $response['hard_alerts']))));
$response['soft_alerts'] = array_values(array_map('unserialize', array_unique(array_map('serialize', $response['soft_alerts']))));
$response['meta']['orders_unique'] = $orders_unique;
$response['meta']['hard_count'] = count($response['hard_alerts']);
$response['meta']['soft_count'] = count($response['soft_alerts']);
$response['meta']['alert_count'] = $response['meta']['hard_count'] + $response['meta']['soft_count'];
$response['meta']['elapsed_ms'] = (int)round((microtime(true) - $api_started) * 1000);
$response['meta']['patients_scanned'] = count(array_unique($hn_list));
$response['meta']['high_volume'] = ($response['meta']['patients_scanned'] > 150);

echo json_encode($response, JSON_UNESCAPED_UNICODE);
