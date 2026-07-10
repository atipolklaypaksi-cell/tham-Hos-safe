<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start();
session_start();

include(__DIR__ . '/connect.php');
include(__DIR__ . '/hosxp_tmt_drug_helper.php');
include(__DIR__ . '/monitor_max_dose_helper.php');
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

$rules = mmdd_load_active_rules($con, $rule_ids);
if (empty($rules)) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'success',
        'hard_alerts' => [],
        'soft_alerts' => [],
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

$rule_by_icode = [];
$icode_list = [];
foreach ($rules as $rule) {
    $ic = trim($rule['icode'] ?? '');
    if ($ic === '') {
        continue;
    }
    if (!isset($rule_by_icode[$ic])) {
        $rule_by_icode[$ic] = [];
        $icode_list[] = "'" . mysqli_real_escape_string($hos, $ic) . "'";
    }
    $rule_by_icode[$ic][] = $rule;
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
$seen = [];
$duration_cache = [];
$orders_scanned = 0;

while ($row = mysqli_fetch_assoc($q)) {
    $orders_scanned++;
    $icode = trim($row['icode'] ?? '');
    $matched_rules = $rule_by_icode[$icode] ?? [];
    if (empty($matched_rules)) {
        continue;
    }

    $method = trim(preg_replace('/\s+/u', ' ', $row['method'] ?? ''));
    $dose_parsed = hosxp_parse_drug_daily_dose_mg([
        'qty' => $row['qty'],
        'strength' => $row['strength'] ?? '',
        'units' => $row['units'] ?? '',
        'method' => $method,
    ]);
    $daily_mg = isset($dose_parsed['mg_dose']) ? (float)$dose_parsed['mg_dose'] : null;
    $daily_mg_display = di_format_daily_mg_display([
        'qty' => $row['qty'],
        'strength' => $row['strength'] ?? '',
        'units' => $row['units'] ?? '',
        'method' => $method,
    ]);

    $rxdate = trim((string)($row['rxdate'] ?? ''));
    $consecutive_days = mmdd_consecutive_days($hos, $row['hn'] ?? '', $icode, $rxdate, $duration_cache);

    foreach ($matched_rules as $rule) {
        $reasons = mmdd_build_trigger_reasons($rule, $daily_mg, $consecutive_days);
        if (empty($reasons)) {
            continue;
        }

        $dedupe = implode('|', [
            $row['hn'] ?? '',
            $row['vstdate'] ?? '',
            $row['vn'] ?? '',
            $row['an'] ?? '',
            $icode,
            $row['item_no'] ?? '',
            (int)$rule['id'],
            implode(',', $reasons),
        ]);
        if (isset($seen[$dedupe])) {
            continue;
        }
        $seen[$dedupe] = true;

        $level = mmdd_alert_level($rule);
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

        $alert = [
            'rule_id' => (int)$rule['id'],
            'hn' => ltrim($row['hn'] ?? '', '0'),
            'hn_full' => $row['hn'] ?? '',
            'ptname' => trim($row['ptname'] ?? ''),
            'type_patient' => $type_patient,
            'vstdate' => $row['vstdate'] ?? '',
            'vn' => $row['vn'] ?? '',
            'an' => $row['an'] ?? '',
            'item_no' => $row['item_no'] ?? '',
            'rxdate' => $rxdate,
            'order_date' => $rx_date_fmt,
            'order_time' => $rx_time_fmt,
            'drug_name' => trim($row['drug_name'] ?? ($rule['drug_name'] ?? '')),
            'icode' => $icode,
            'method' => $method,
            'qty' => $row['qty'] ?? '',
            'strength' => trim($row['strength'] ?? ''),
            'daily_mg' => $daily_mg,
            'daily_mg_display' => $daily_mg_display,
            'max_dose_mg_per_day' => (float)($rule['max_dose_mg_per_day'] ?? 0),
            'consecutive_days' => $consecutive_days,
            'max_duration_days' => (int)($rule['max_duration_days'] ?? 0),
            'trigger_reasons' => $reasons,
            'ward_display' => $ward_display,
            'alert_level' => $level,
            'alert_message' => mmdd_default_alert_message($rule, $reasons, $daily_mg, $consecutive_days),
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
        'start_date' => $start_date,
        'end_date' => $end_date,
        'recent_minutes' => $recent_minutes,
        'elapsed_ms' => $elapsed,
    ],
], JSON_UNESCAPED_UNICODE);
