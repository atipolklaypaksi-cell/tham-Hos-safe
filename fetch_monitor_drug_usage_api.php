<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start();
session_start();

include(__DIR__ . '/connect.php');
include(__DIR__ . '/hosxp_tmt_drug_helper.php');
include(__DIR__ . '/monitor_drug_usage_helper.php');
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

$rules = mdu_load_active_rules($con, $rule_ids);
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

$sql = "
SELECT
    MAX(CONCAT(o.rxdate, ' ', IFNULL(o.rxtime,''))) AS rx_datetime,
    MAX(o.rxdate) AS rxdate,
    MAX(o.rxtime) AS rxtime,
    o.vstdate, o.vn, o.an, o.hn,
    IF(MAX(o.vn) IS NULL OR MAX(o.vn) = '', 'IPD', 'OPD') AS type_patient,
    MAX(CAST(CONVERT(CONCAT(IFNULL(pt.pname,''), IFNULL(pt.fname,''), ' ', IFNULL(pt.lname,'')) USING utf8) AS CHAR)) AS ptname,
    MAX(CAST(CONVERT(d.name USING utf8) AS CHAR)) AS drug_name,
    o.icode,
    MAX($method_expr) AS method,
    MAX(o.qty) AS qty,
    MAX(CAST(CONVERT(IFNULL(w1.name,'') USING utf8) AS CHAR)) AS ward_name,
    MAX(IFNULL(ia.bedno, '')) AS bedno
FROM opitemrece o
LEFT JOIN drugitems d ON d.icode = o.icode
LEFT JOIN patient pt ON pt.hn = o.hn
" . di_sql_method_joins('o') . "
LEFT JOIN iptadm ia ON ia.an = o.an
LEFT JOIN ward_admit_snapshot wa ON wa.an = o.an
LEFT JOIN ward w1 ON w1.ward = wa.ward
WHERE o.icode IN ($icode_in)
  AND o.rxdate BETWEEN '$start_esc' AND '$end_esc'
  AND o.qty > 0
  $recent_clause
GROUP BY o.vstdate, o.vn, o.an, o.icode, o.hn
ORDER BY rx_datetime DESC
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

while ($row = mysqli_fetch_assoc($q)) {
    $icode = trim($row['icode'] ?? '');
    $matched_rules = $rule_by_icode[$icode] ?? [];
    if (empty($matched_rules)) {
        continue;
    }

    $method = trim(preg_replace('/\s+/u', ' ', $row['method'] ?? ''));

    foreach ($matched_rules as $rule) {
        if (mdu_method_passes($method, $rule['required_regex'] ?? '')) {
            continue;
        }

        $level = mdu_alert_level($rule);
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

        $dedupe = implode('|', [
            $row['hn'] ?? '',
            $row['vstdate'] ?? '',
            $row['vn'] ?? '',
            $row['an'] ?? '',
            $icode,
            (int)$rule['id'],
        ]);
        if (isset($seen[$dedupe])) {
            continue;
        }
        $seen[$dedupe] = true;

        $alert = [
            'rule_id' => (int)$rule['id'],
            'hn' => ltrim($row['hn'] ?? '', '0'),
            'hn_full' => $row['hn'] ?? '',
            'ptname' => trim($row['ptname'] ?? ''),
            'type_patient' => $type_patient,
            'vstdate' => $row['vstdate'] ?? '',
            'vn' => $row['vn'] ?? '',
            'an' => $row['an'] ?? '',
            'rx_datetime' => trim($row['rx_datetime'] ?? ''),
            'order_date' => $rx_date_fmt,
            'order_time' => $rx_time_fmt,
            'drug_name' => trim($row['drug_name'] ?? ($rule['drug_name'] ?? '')),
            'icode' => $icode,
            'method' => $method,
            'qty' => $row['qty'] ?? '',
            'ward_display' => $ward_display,
            'alert_level' => $level,
            'alert_message' => trim($rule['alert_message'] ?? '') !== ''
                ? trim($rule['alert_message'])
                : 'วิธีใช้ไม่ตรงตามเงื่อนไขที่กำหนด',
            'detail_text' => trim($rule['detail_text'] ?? ''),
            'required_regex' => $rule['required_regex'] ?? '',
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
        'start_date' => $start_date,
        'end_date' => $end_date,
        'recent_minutes' => $recent_minutes,
        'elapsed_ms' => $elapsed,
    ],
], JSON_UNESCAPED_UNICODE);
