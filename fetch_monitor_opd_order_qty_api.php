<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start();
session_start();

include(__DIR__ . '/connect.php');
include(__DIR__ . '/hosxp_tmt_drug_helper.php');
include(__DIR__ . '/monitor_opd_order_qty_helper.php');
mysqli_set_charset($con, 'utf8');
set_time_limit(180);

if (!isset($_SESSION['user_id']) && !isset($_SESSION['full_name'])) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : date('Y-m-d', strtotime('-3 days'));
$end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
    $start_date = date('Y-m-d', strtotime('-3 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    $end_date = date('Y-m-d');
}
if (strtotime($start_date) > strtotime($end_date)) {
    $tmp = $start_date;
    $start_date = $end_date;
    $end_date = $tmp;
}

$rule_ids = [];
if (!empty($_GET['rule_ids'])) {
    foreach (explode(',', $_GET['rule_ids']) as $rid) {
        $rid = (int)trim($rid);
        if ($rid > 0) {
            $rule_ids[] = $rid;
        }
    }
}

$rules = moq_load_active_rules($con, $rule_ids);
if (empty($rules)) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'success',
        'hard_alerts' => [],
        'soft_alerts' => [],
        'meta' => ['message' => 'ยังไม่มีกฎที่เปิดใช้งาน — ไปที่แท็บตั้งค่า', 'rule_count' => 0],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$hos = hosxp_connect($con);
if (!$hos) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rule_by_icode = [];
$icode_list = [];
list($rule_by_icode, $icode_list) = moq_build_rule_map_by_icode($hos, $rules);
$icode_in = implode(',', array_unique($icode_list));

if ($icode_in === '') {
    mysqli_close($hos);
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'success',
        'hard_alerts' => [],
        'soft_alerts' => [],
        'meta' => ['rule_count' => 0],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$start_esc = mysqli_real_escape_string($hos, $start_date);
$end_esc = mysqli_real_escape_string($hos, $end_date);
$method_expr = di_sql_method_expr('o');
$tmt_expr = hosxp_sql_tmt_drug_name('d', 'tm');

$sql = "
SELECT
    o.hn, o.vn, o.vstdate, o.rxdate, o.rxtime, o.icode, o.qty, o.item_no,
    $method_expr AS method,
    CAST(CONVERT(d.units USING utf8) AS CHAR) AS units,
    $tmt_expr AS drug_name,
    CAST(CONVERT(CONCAT(IFNULL(pt.pname,''), IFNULL(pt.fname,''), ' ', IFNULL(pt.lname,'')) USING utf8) AS CHAR) AS ptname,
    CAST(CONVERT(IFNULL(dep.department,'') USING utf8) AS CHAR) AS clinic_name
FROM opitemrece o
INNER JOIN drugitems d ON d.icode = o.icode
INNER JOIN vn_stat v ON v.vn = o.vn
" . hosxp_sql_tmt_join('d', 'tm') . "
LEFT JOIN patient pt ON pt.hn = o.hn
LEFT JOIN ovst k ON k.vn = o.vn
LEFT JOIN kskdepartment dep ON dep.depcode = k.main_dep
" . di_sql_method_joins('o') . "
WHERE o.icode IN ($icode_in)
  AND o.rxdate BETWEEN '$start_esc' AND '$end_esc'
  AND o.qty > 0
  AND o.vn IS NOT NULL AND o.vn <> ''
  AND (o.an IS NULL OR o.an = '')
  AND v.vn IS NOT NULL
ORDER BY o.rxdate DESC, o.rxtime DESC, o.item_no DESC
LIMIT 8000
";

$t0 = microtime(true);
$q = mysqli_query($hos, $sql);
if (!$q) {
    $err = mysqli_error($hos);
    mysqli_close($hos);
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'SQL Error: ' . $err], JSON_UNESCAPED_UNICODE);
    exit;
}

$rows = [];
$vn_list = [];
while ($row = mysqli_fetch_assoc($q)) {
    $rows[] = $row;
    $vn = trim($row['vn'] ?? '');
    if ($vn !== '') {
        $vn_list[] = $vn;
    }
}
$appt_map = moq_fetch_appointments_by_vn($hos, $vn_list);

$hard_alerts = [];
$soft_alerts = [];
$seen = [];
$skipped_no_appt = 0;
$skipped_method = 0;
$orders_scanned = count($rows);

foreach ($rows as $row) {
    $icode = trim($row['icode'] ?? '');
    $matched_rules = $rule_by_icode[$icode] ?? [];
    if (empty($matched_rules)) {
        continue;
    }

    $vn = trim($row['vn'] ?? '');
    $order_date = trim($row['rxdate'] ?? '') ?: trim($row['vstdate'] ?? '');
    $visit_appts = $appt_map[$vn] ?? [];
    if (empty($visit_appts)) {
        $skipped_no_appt++;
        continue;
    }

    $method = trim(preg_replace('/\s+/u', ' ', $row['method'] ?? ''));
    $qty = (float)($row['qty'] ?? 0);

    foreach ($matched_rules as $rule) {
        $excess_allowed = (int)($rule['excess_pills_allowed'] ?? moq_default_excess_pills());
        if ($excess_allowed <= 0) {
            $excess_allowed = moq_default_excess_pills();
        }
        $shortage_allowed = max(0, (int)($rule['shortage_pills_allowed'] ?? moq_default_shortage_pills()));
        $result = moq_evaluate_order_against_appointments($qty, $method, $row['units'] ?? '', $order_date, $visit_appts, $excess_allowed, $shortage_allowed, $con);
        $eval = $result['eval'] ?? [];
        if (($result['status'] ?? '') === 'skip' || ($result['status'] ?? '') === 'ok') {
            if (($result['status'] ?? '') === 'skip') {
                $skipped_method++;
            }
            continue;
        }

        $appt = $result['appointment'] ?? [];
        $nextdate = $appt['nextdate'] ?? '';
        $nexttime = di_format_time(monitor_lab_normalize_hosxp_time($appt['nexttime'] ?? ''));

        $alert_type = $result['status'];
        $level = $alert_type === 'shortage'
            ? moq_alert_level($rule['alert_level_shortage'] ?? 'hard')
            : moq_alert_level($rule['alert_level_excess'] ?? 'soft');

        $dedupe = implode('|', [
            $row['hn'] ?? '',
            $row['vn'] ?? '',
            $icode,
            $row['item_no'] ?? '',
            (int)$rule['id'],
            $alert_type,
        ]);
        if (isset($seen[$dedupe])) {
            continue;
        }
        $seen[$dedupe] = true;

        $rx_date_fmt = !empty($row['rxdate']) ? date('d/m/Y', strtotime($row['rxdate'])) : '-';
        $rx_time_fmt = di_format_time(monitor_lab_normalize_hosxp_time($row['rxtime'] ?? ''));

        $alert = [
            'rule_id' => (int)$rule['id'],
            'alert_type' => $alert_type,
            'hn' => ltrim($row['hn'] ?? '', '0'),
            'hn_full' => $row['hn'] ?? '',
            'ptname' => trim($row['ptname'] ?? ''),
            'type_patient' => 'OPD',
            'vn' => $vn,
            'vstdate' => $row['vstdate'] ?? '',
            'order_date' => $rx_date_fmt,
            'order_time' => $rx_time_fmt,
            'drug_name' => trim($row['drug_name'] ?? ($rule['drug_name'] ?? '')),
            'icode' => $icode,
            'method' => $method,
            'qty' => $qty,
            'units' => trim($row['units'] ?? ''),
            'daily_units' => $eval['daily_units'],
            'coverage_days' => (int)($eval['coverage_days'] ?? 0),
            'coverage_end' => moq_format_date_th($eval['coverage_end'] ?? ''),
            'days_needed' => (int)($eval['days_needed'] ?? 0),
            'pills_needed' => $eval['pills_needed'],
            'max_qty_allowed' => $eval['max_qty_allowed'],
            'excess_pills_allowed' => (int)($eval['excess_pills_allowed'] ?? $excess_allowed),
            'shortage_pills_allowed' => (int)($eval['shortage_pills_allowed'] ?? $shortage_allowed),
            'min_qty_required' => $eval['min_qty_required'] ?? null,
            'next_appointment' => moq_format_date_th($nextdate),
            'next_appointment_time' => $nexttime,
            'clinic_name' => trim($row['clinic_name'] ?? ''),
            'alert_level' => $level,
            'alert_message' => $alert_type === 'shortage'
                ? moq_default_shortage_message($rule, $eval, $nextdate)
                : moq_default_excess_message($rule, $eval, $nextdate),
            'detail_text' => trim($rule['detail_text'] ?? ''),
        ];

        if ($level === 'hard') {
            $hard_alerts[] = $alert;
        } else {
            $soft_alerts[] = $alert;
        }
    }
}

$elapsed = (int)round((microtime(true) - $t0) * 1000);
mysqli_close($hos);

ob_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'status' => 'success',
    'hard_alerts' => $hard_alerts,
    'soft_alerts' => $soft_alerts,
    'meta' => [
        'rule_count' => count($rules),
        'hard_count' => count($hard_alerts),
        'soft_count' => count($soft_alerts),
        'orders_scanned' => $orders_scanned,
        'skipped_no_appointment' => $skipped_no_appt,
        'skipped_method' => $skipped_method,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'elapsed_ms' => $elapsed,
    ],
], JSON_UNESCAPED_UNICODE);
