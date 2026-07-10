<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start();
session_start();

include(__DIR__ . '/connect.php');
include(__DIR__ . '/hosxp_tmt_drug_helper.php');
include(__DIR__ . '/monitor_mg_ml_helper.php');
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
$recent_minutes = isset($_GET['recent_minutes']) ? (int)$_GET['recent_minutes'] : 0;

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
if ($recent_minutes < 0) {
    $recent_minutes = 0;
}
if ($recent_minutes > 1440) {
    $recent_minutes = 1440;
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

$rules = mmgml_load_active_rules($con, $rule_ids);
if (empty($rules)) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'success',
        'hard_alerts' => [],
        'soft_alerts' => [],
        'passed_items' => [],
        'meta' => [
            'message' => 'ยังไม่มีกฎที่เปิดใช้งาน — ไปที่แท็บตั้งค่า',
            'rule_count' => 0,
        ],
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

$rule_by_icode = mmgml_build_rule_map_by_icode($rules);
$icode_list = [];
foreach (array_keys($rule_by_icode) as $ic) {
    $icode_list[] = "'" . mysqli_real_escape_string($hos, $ic) . "'";
}
$icode_in = implode(',', $icode_list);

if ($icode_in === '') {
    mysqli_close($hos);
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'success',
        'hard_alerts' => [],
        'soft_alerts' => [],
        'passed_items' => [],
        'meta' => ['rule_count' => 0],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$start_esc = mysqli_real_escape_string($hos, $start_date);
$end_esc = mysqli_real_escape_string($hos, $end_date);

$recent_clause = '';
if ($recent_minutes > 0) {
    $recent_clause = "AND UNIX_TIMESTAMP(CONCAT(o.rxdate, ' ', IFNULL(o.rxtime,'00:00:00')))
        BETWEEN UNIX_TIMESTAMP(SUBDATE(NOW(), INTERVAL $recent_minutes MINUTE)) AND UNIX_TIMESTAMP(NOW())";
}

$method_expr = di_sql_method_expr('o');
$tmt_expr = hosxp_sql_tmt_drug_name('d', 'tm');

$sql = "
SELECT
    o.hn, o.vn, o.an, o.vstdate, o.rxdate, o.rxtime, o.icode, o.qty, o.item_no,
    $method_expr AS method,
    CAST(CONVERT(COALESCE(NULLIF(TRIM(tm.strength), ''), d.strength) USING utf8) AS CHAR) AS strength,
    CAST(CONVERT(d.units USING utf8) AS CHAR) AS units,
    $tmt_expr AS drug_name,
    CAST(CONVERT(CONCAT(IFNULL(pt.pname,''), IFNULL(pt.fname,''), ' ', IFNULL(pt.lname,'')) USING utf8) AS CHAR) AS ptname,
    IF(o.vn IS NULL OR o.vn = '', 'IPD', 'OPD') AS type_patient,
    CAST(CONVERT(IFNULL(w1.name,'') USING utf8) AS CHAR) AS ward_name,
    IFNULL(ia.bedno, '') AS bedno
FROM opitemrece o
INNER JOIN drugitems d ON d.icode = o.icode
" . hosxp_sql_tmt_join('d', 'tm') . "
LEFT JOIN patient pt ON pt.hn = o.hn
" . di_sql_method_joins('o') . "
LEFT JOIN iptadm ia ON ia.an = o.an
LEFT JOIN ward_admit_snapshot wa ON wa.an = o.an
LEFT JOIN ward w1 ON w1.ward = wa.ward
WHERE o.icode IN ($icode_in)
  AND o.rxdate BETWEEN '$start_esc' AND '$end_esc'
  AND o.qty > 0
  $recent_clause
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

$hard_alerts = [];
$soft_alerts = [];
$passed_items = [];
$seen = [];
$orders_scanned = 0;
$orders_checked = 0;

while ($row = mysqli_fetch_assoc($q)) {
    $orders_scanned++;
    $icode = trim($row['icode'] ?? '');
    $matched_rules = $rule_by_icode[$icode] ?? [];
    if (empty($matched_rules)) {
        continue;
    }

    $method = trim(preg_replace('/\s+/u', ' ', $row['method'] ?? ''));

    foreach ($matched_rules as $rule) {
        $dose_mg = hosxp_extract_mg_from_usage($method);
        $stated_ml = mmgml_extract_ml_from_usage($method);
        $mg_per_ml = (float)($rule['mg_per_ml'] ?? 0);
        if ($dose_mg === null || $dose_mg <= 0 || $stated_ml === null || $stated_ml <= 0 || $mg_per_ml <= 0) {
            continue;
        }
        $orders_checked++;

        $dedupe = implode('|', [
            $row['hn'] ?? '',
            $row['vstdate'] ?? '',
            $row['vn'] ?? '',
            $row['an'] ?? '',
            $icode,
            $row['item_no'] ?? '',
            (int)$rule['id'],
            $method,
        ]);
        if (isset($seen[$dedupe])) {
            continue;
        }
        $seen[$dedupe] = true;

        $expected_ml = (float)$dose_mg / $mg_per_ml;
        $tolerance_ml = mmgml_compute_tolerance_ml($expected_ml, $rule);
        $diff_ml = abs((float)$stated_ml - $expected_ml);
        $is_passed = $diff_ml <= $tolerance_ml;

        $level = mmgml_alert_level($rule);
        $rx_date_fmt = !empty($row['rxdate']) ? date('d/m/Y', strtotime($row['rxdate'])) : '-';
        $rx_time_fmt = di_format_time(monitor_lab_normalize_hosxp_time($row['rxtime'] ?? ''));

        $type_patient = $row['type_patient'] ?? 'OPD';
        $ward_name = trim($row['ward_name'] ?? '');
        $bedno = trim($row['bedno'] ?? '');
        if ($type_patient === 'OPD') {
            $ward_display = 'ผู้ป่วยนอก';
        } else {
            $ward_display = trim($ward_name . ($ward_name && $bedno ? ' / ' : '') . $bedno);
            if ($ward_display === '') {
                $ward_display = '-';
            }
        }

        $item = [
            'rule_id' => (int)$rule['id'],
            'hn' => ltrim($row['hn'] ?? '', '0'),
            'hn_full' => $row['hn'] ?? '',
            'ptname' => trim($row['ptname'] ?? ''),
            'type_patient' => $type_patient,
            'vstdate' => $row['vstdate'] ?? '',
            'vn' => $row['vn'] ?? '',
            'an' => $row['an'] ?? '',
            'item_no' => $row['item_no'] ?? '',
            'rxdate' => trim((string)($row['rxdate'] ?? '')),
            'order_date' => $rx_date_fmt,
            'order_time' => $rx_time_fmt,
            'drug_name' => trim($row['drug_name'] ?? ($rule['drug_name'] ?? '')),
            'icode' => $icode,
            'method' => $method,
            'qty' => $row['qty'] ?? '',
            'strength' => trim($row['strength'] ?? ''),
            'dose_mg' => (float)$dose_mg,
            'stated_ml' => (float)$stated_ml,
            'expected_ml' => $expected_ml,
            'tolerance_ml' => $tolerance_ml,
            'diff_ml' => $diff_ml,
            'mg_per_ml' => $mg_per_ml,
            'strength_text' => trim($rule['strength_text'] ?? ''),
            'dose_mg_display' => mmgml_format_mg($dose_mg) . ' mg',
            'stated_ml_display' => mmgml_format_ml($stated_ml) . ' mL',
            'expected_ml_display' => mmgml_format_ml($expected_ml) . ' mL',
            'mg_per_ml_display' => mmgml_format_mg($mg_per_ml) . ' mg/mL',
            'diff_ml_display' => mmgml_format_ml($diff_ml) . ' mL',
            'ward_display' => $ward_display,
            'alert_level' => $level,
            'alert_message' => '',
            'detail_text' => trim($rule['detail_text'] ?? ''),
        ];

        if ($is_passed) {
            $item['pass_message'] = 'ผ่านเกณฑ์ (ผลต่าง ' . $item['diff_ml_display'] . ' ภายในอนุโลม)';
            $passed_items[] = $item;
            continue;
        }

        $item['alert_message'] = mmgml_default_alert_message($rule, [
            'dose_mg' => $dose_mg,
            'stated_ml' => $stated_ml,
            'expected_ml' => $expected_ml,
            'tolerance_ml' => $tolerance_ml,
            'diff_ml' => $diff_ml,
            'mg_per_ml' => $mg_per_ml,
            'strength_text' => trim($rule['strength_text'] ?? ''),
        ]);

        if ($level === 'hard') {
            $hard_alerts[] = $item;
        } else {
            $soft_alerts[] = $item;
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
    'passed_items' => $passed_items,
    'meta' => [
        'rule_count' => count($rules),
        'hard_count' => count($hard_alerts),
        'soft_count' => count($soft_alerts),
        'passed_count' => count($passed_items),
        'orders_scanned' => $orders_scanned,
        'orders_checked' => $orders_checked,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'recent_minutes' => $recent_minutes,
        'elapsed_ms' => $elapsed,
    ],
], JSON_UNESCAPED_UNICODE);
