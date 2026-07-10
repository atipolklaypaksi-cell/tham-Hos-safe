<?php
session_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
include('connect.php');
include('hosxp_tmt_drug_helper.php');
mysqli_set_charset($con, 'utf8');

set_time_limit(180);
ini_set('memory_limit', '512M');
$api_started = microtime(true);

function di_api_json_error($message, $code = 200) {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'status' => 'error',
            'message' => 'ระบบขัดข้อง: ' . ($err['message'] ?? 'unknown'),
        ], JSON_UNESCAPED_UNICODE);
    }
});

try {

$start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : date('Y-m-d', strtotime('-1 month'));
$end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
    $start_date = date('Y-m-d', strtotime('-1 month'));
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

$tbl_chk = mysqli_query($con, "SHOW TABLES LIKE 'monitor_drug_interaction_settings'");
if (!$tbl_chk || mysqli_num_rows($tbl_chk) === 0) {
    di_api_json_error('ยังไม่มีตาราง monitor_drug_interaction_settings — กรุณารัน upgrade.php');
}
ensure_di_dose_columns($con);

$q_rules = mysqli_query($con, "SELECT * FROM monitor_drug_interaction_settings WHERE active_status = 1 ORDER BY FIELD(COALESCE(alert_level,'soft'), 'hard', 'soft'), drug_name ASC");
if (!$q_rules) {
    di_api_json_error('ไม่สามารถโหลดกฎได้: ' . mysqli_error($con));
}
$all_active_rules = [];
while ($row = mysqli_fetch_assoc($q_rules)) {
    if (trim($row['drug_icode'] ?? '') === '' || trim($row['hosxp_icode'] ?? '') === '') {
        continue;
    }
    $all_active_rules[] = $row;
}

if (empty($all_active_rules)) {
    di_api_json_error('ยังไม่มีการตั้งค่า Drug Interaction ในระบบ');
}

$rules_total = count($all_active_rules);
$rules = $all_active_rules;
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
        $rules = array_values(array_filter($all_active_rules, function ($r) use ($allowed_ids) {
            return isset($allowed_ids[(int)$r['id']]);
        }));
    }
}

if (empty($rules)) {
    di_api_json_error('ไม่ได้เลือกกฎที่ต้องการรายงาน — กรุณาเลือกอย่างน้อย 1 คู่ยา');
}
$rules_selected = count($rules);

$con_hosxp = hosxp_connect($con);
if (!$con_hosxp) {
    di_api_json_error('ไม่สามารถเชื่อมต่อฐานข้อมูล HOSxP ได้');
}

$icode_set = [];
$lookback_anchor_icodes = [];
$lookback_partner_icodes = [];
foreach ($rules as $rule) {
    $icode_set[trim($rule['drug_icode'])] = true;
    $icode_set[trim($rule['hosxp_icode'])] = true;
    if (($rule['check_mode'] ?? 'same_visit') === 'lookback') {
        $lookback_anchor_icodes[trim($rule['drug_icode'])] = true;
        $lookback_partner_icodes[trim($rule['hosxp_icode'])] = true;
    }
}

$window = di_compute_query_window($start_date, $end_date, $rules);
$query_start = $window['query_start'];
$query_end = $window['query_end'];
$need_dose_mg = di_rules_need_dose_mg($rules);
$has_lookback = di_rules_have_lookback($rules);
$all_icodes = [];
foreach (array_keys($icode_set) as $ic) {
    if ($ic !== '') {
        $all_icodes[] = $ic;
    }
}

if (empty($all_icodes)) {
    mysqli_close($con_hosxp);
    di_api_json_error('ไม่พบ icode ในกฎที่ตั้งไว้');
}

$raw_rows = [];
$drug_names = [];
$ptnames = [];
$row_count_raw = 0;
$truncated = false;

[$phase1_rows, $phase1_raw, $phase1_trunc, $phase1_err] = di_fetch_hosxp_orders(
    $con_hosxp, $all_icodes, $start_date, $end_date, $need_dose_mg, [], $max_rows
);
if ($phase1_err) {
    mysqli_close($con_hosxp);
    di_api_json_error('SQL Error: ' . $phase1_err);
}
$row_count_raw += $phase1_raw;
$truncated = $truncated || $phase1_trunc;
$raw_rows = array_merge($raw_rows, $phase1_rows);

if ($has_lookback && !empty($lookback_partner_icodes)) {
    $hn_for_lookback = [];
    foreach ($phase1_rows as $row) {
        $ic = trim((string)($row['icode'] ?? ''));
        if (isset($lookback_anchor_icodes[$ic]) || isset($lookback_partner_icodes[$ic])) {
            $hn_for_lookback[trim((string)($row['hn'] ?? ''))] = true;
        }
    }
    $hn_list = [];
    foreach (array_keys($hn_for_lookback) as $hn) {
        if ($hn !== '') {
            $hn_list[] = $hn;
        }
    }

    if (!empty($hn_list)) {
        $lb_icodes = array_keys($lookback_partner_icodes);
        [$phase2_rows, $phase2_raw, $phase2_trunc, $phase2_err] = di_fetch_hosxp_orders(
            $con_hosxp, $lb_icodes, $query_start, $query_end, $need_dose_mg, $hn_list, min(8000, $max_rows)
        );
        if ($phase2_err) {
            mysqli_close($con_hosxp);
            di_api_json_error('SQL Error (lookback): ' . $phase2_err);
        }
        if ($phase2_raw > 0) {
            $row_count_raw += $phase2_raw;
            $truncated = $truncated || $phase2_trunc;
            $raw_rows = array_merge($raw_rows, $phase2_rows);
        }
    }
}

foreach ($raw_rows as $row) {
    $icode = trim($row['icode']);
    $hn = $row['hn'];
    if (!isset($drug_names[$icode])) {
        $drug_names[$icode] = trim($row['drug_name'] ?? '') ?: $icode;
    }
    if (!isset($ptnames[$hn])) {
        $ptnames[$hn] = trim($row['ptname'] ?? '');
    }
}

mysqli_close($con_hosxp);

$deduped_rows = di_dedupe_latest_orders($raw_rows);
$prepared_rows = di_prepare_orders($deduped_rows, $need_dose_mg);
[$by_icode, $by_vn_icode, $by_hn_icode] = di_reindex_orders_fast($prepared_rows);
$row_count = count($prepared_rows);

$block_alerts = [];
$warning_alerts = [];
$block_count = 0;
$warning_count = 0;
$seen = [];

foreach ($rules as $rule) {
    $icode1 = trim($rule['drug_icode']);
    $icode2 = trim($rule['hosxp_icode']);
    $check_mode = ($rule['check_mode'] ?? 'same_visit') === 'lookback' ? 'lookback' : 'same_visit';
    $lookback_days = max(1, (int)($rule['lookback_days'] ?? 7));

    $alert_level = 'soft';
    if (!empty($rule['alert_level']) && in_array($rule['alert_level'], ['hard', 'soft'], true)) {
        $alert_level = $rule['alert_level'];
    } elseif (isset($rule['severity']) && (int)$rule['severity'] >= 3) {
        $alert_level = 'hard';
    }

    $drug1_name = $drug_names[$icode1] ?? $rule['drug_name'];
    $drug2_name = $drug_names[$icode2] ?? $rule['hosxp_drug_name'];

    foreach ($by_icode[$icode1] ?? [] as $o1) {
        if (!di_order_qty_valid($o1)) {
            continue;
        }
        $vst1 = $o1['vstdate'];
        if ($vst1 < $start_date || $vst1 > $end_date) {
            continue;
        }

        $c1 = $o1['_c'];
        $end1 = $o1['_end'];

        if ($check_mode === 'same_visit') {
            foreach (di_same_visit_candidates($o1, $icode2, $by_vn_icode, $by_hn_icode) as $o2) {
                if (!di_order_qty_valid($o2)) {
                    continue;
                }

                $c2 = $o2['_c'];
                if (!di_periods_overlap($vst1, $c1, $o2['vstdate'], $c2)) {
                    continue;
                }
                if (!di_dose_condition_matches($rule, $o1, 1) || !di_dose_condition_matches($rule, $o2, 2)) {
                    continue;
                }

                $vn2 = trim((string)($o2['vn'] ?? ''));
                $key = $rule['id'] . '|' . $o1['hn'] . '|' . $vst1 . '|' . $o2['vstdate'] . '|' . $vn2;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $alert_data = di_build_alert(
                    $rule, $o1, $o2, $alert_level, $check_mode, $lookback_days,
                    $ptnames[$o1['hn']] ?? '', $drug1_name, $drug2_name, $c1, $c2
                );
                di_append_alert($alert_data, $alert_level, $block_alerts, $warning_alerts, $block_count, $warning_count);
            }
        } else {
            $lb_start = date('Y-m-d', strtotime($vst1 . " -{$lookback_days} days"));
            foreach ($by_hn_icode[$o1['hn']][$icode2] ?? [] as $o2) {
                if (!di_order_qty_valid($o2)) {
                    continue;
                }
                $vst2 = $o2['vstdate'];
                $c2 = $o2['_c'];
                if (!di_periods_overlap($vst1, $c1, $vst2, $c2)) {
                    continue;
                }
                if ($o2['_end'] < $lb_start) {
                    continue;
                }
                if (!di_dose_condition_matches($rule, $o1, 1) || !di_dose_condition_matches($rule, $o2, 2)) {
                    continue;
                }

                $key = $rule['id'] . '|' . $o1['hn'] . '|' . $vst1 . '|' . $vst2 . '|' . $c1 . '|' . $c2;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $alert_data = di_build_alert(
                    $rule, $o1, $o2, $alert_level, $check_mode, $lookback_days,
                    $ptnames[$o1['hn']] ?? '', $drug1_name, $drug2_name, $c1, $c2
                );
                di_append_alert($alert_data, $alert_level, $block_alerts, $warning_alerts, $block_count, $warning_count);
            }
        }
    }
}

$hn_set = [];
foreach ($prepared_rows as $row) {
    if (($row['vstdate'] ?? '') >= $start_date && ($row['vstdate'] ?? '') <= $end_date) {
        $hn_set[$row['hn']] = true;
    }
}

echo json_encode([
    'status' => 'success',
    'meta' => [
        'block_count' => $block_count,
        'warning_count' => $warning_count,
        'rule_count' => $rules_selected,
        'rules_selected' => $rules_selected,
        'rules_total' => $rules_total,
        'orders_scanned' => $row_count,
        'orders_raw' => $row_count_raw,
        'date_from' => $start_date,
        'date_to' => $end_date,
        'query_from' => $query_start,
        'query_to' => $query_end,
        'truncated' => $truncated,
        'elapsed_ms' => (int)round((microtime(true) - $api_started) * 1000),
        'patients_scanned' => count($hn_set),
        'high_volume' => (count($icode_set) > 20 || count($rules) > 30),
    ],
    'block_groups' => di_group_alerts_by_hn($block_alerts),
    'warning_groups' => di_group_alerts_by_hn($warning_alerts),
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

} catch (Throwable $e) {
    di_api_json_error('เกิดข้อผิดพลาด: ' . $e->getMessage());
}
