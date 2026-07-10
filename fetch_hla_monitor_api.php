<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start();
session_start();

include('connect.php');
include('hosxp_tmt_drug_helper.php');
require_once __DIR__ . '/monitor_hla_helper.php';
mysqli_set_charset($con, 'utf8');
ensure_monitor_hla_alert_level_column($con);
set_time_limit(120);

if (!isset($_SESSION['user_id']) && !isset($_SESSION['full_name'])) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : date('Y-m-d');
$recent_minutes = isset($_GET['recent_minutes']) ? (int)$_GET['recent_minutes'] : 0;
$lookback_months = isset($_GET['lookback_months']) ? (int)$_GET['lookback_months'] : 0;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
    $start_date = date('Y-m-d', strtotime('-30 days'));
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

$icode_filter = [];
if (!empty($_GET['icodes'])) {
    foreach (explode(',', $_GET['icodes']) as $c) {
        $c = trim($c);
        if ($c !== '') {
            $icode_filter[] = $c;
        }
    }
}

$config_keys = [
    'h_host', 'h_user', 'h_pass', 'h_db',
    'hla_lab_items_code', 'hla_lookback_months', 'hla_ptnote_regex', 'hla_rxnote_regex', 'hla_exclude_hns',
];
$cfg = [];
$q_cfg = mysqli_query($con, "SELECT config_name, config_value FROM sys_config WHERE config_name IN ('" . implode("','", array_map(function ($k) use ($con) {
    return mysqli_real_escape_string($con, $k);
}, $config_keys)) . "')");
if ($q_cfg) {
    while ($r = mysqli_fetch_assoc($q_cfg)) {
        $cfg[$r['config_name']] = $r['config_value'];
    }
}

$hos = hosxp_connect($con);
if (!$hos) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้'], JSON_UNESCAPED_UNICODE);
    exit;
}

$drugs = hla_load_drug_settings($con, $icode_filter);

if (empty($drugs)) {
    mysqli_close($hos);
    mysqli_close($con);
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'success',
        'hard_alerts' => [],
        'soft_alerts' => [],
        'alerts' => [],
        'meta' => [
            'message' => 'ยังไม่มีรายการยาที่ตั้งค่าเฝ้าระวัง — ไปที่แท็บตั้งค่า',
            'count' => 0,
            'hard_count' => 0,
            'soft_count' => 0,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$lab_code_in = monitor_lab_codes_sql_in($hos, $cfg['hla_lab_items_code'] ?? '2281');
if ($lab_code_in === "''") {
    $lab_code_in = "'2281'";
}

if ($lookback_months <= 0) {
    $lookback_months = (int)($cfg['hla_lookback_months'] ?? 24);
}
if ($lookback_months < 1) {
    $lookback_months = 1;
}
if ($lookback_months > 120) {
    $lookback_months = 120;
}

$ptnote_regex = trim($cfg['hla_ptnote_regex'] ?? '');
if ($ptnote_regex === '') {
    $ptnote_regex = 'ผ่านการตัดกรองยีนแพ้ยา|HLA-B';
}
$rxnote_regex = trim($cfg['hla_rxnote_regex'] ?? '');
if ($rxnote_regex === '') {
    $rxnote_regex = 'ยีนแพ้ยา|HLA-B|ตรวจยีน';
}
$ptnote_esc = mysqli_real_escape_string($hos, $ptnote_regex);
$rxnote_esc = mysqli_real_escape_string($hos, $rxnote_regex);

$exclude_clause = '';
$exclude_raw = trim($cfg['hla_exclude_hns'] ?? '');
if ($exclude_raw !== '') {
    $ex_hns = [];
    foreach (preg_split('/[,;\s]+/', $exclude_raw) as $hn) {
        $hn = trim($hn);
        if ($hn === '') {
            continue;
        }
        $ex_hns[] = "'" . mysqli_real_escape_string($hos, str_pad(ltrim($hn, '0'), 9, '0', STR_PAD_LEFT)) . "'";
        if (ltrim($hn, '0') !== $hn) {
            $ex_hns[] = "'" . mysqli_real_escape_string($hos, ltrim($hn, '0')) . "'";
        }
    }
    $ex_hns = array_values(array_unique($ex_hns));
    if (!empty($ex_hns)) {
        $exclude_clause = 'AND o.hn NOT IN (' . implode(',', $ex_hns) . ')';
    }
}

$icode_list = [];
foreach (array_keys($drugs) as $ic) {
    $icode_list[] = "'" . mysqli_real_escape_string($hos, $ic) . "'";
}
$icode_in = implode(',', $icode_list);

$start_esc = mysqli_real_escape_string($hos, $start_date);
$end_esc = mysqli_real_escape_string($hos, $end_date);

$recent_clause = '';
if ($recent_minutes > 0) {
    $recent_clause = "AND UNIX_TIMESTAMP(CONCAT(o.rxdate, ' ', o.rxtime))
        BETWEEN UNIX_TIMESTAMP(SUBDATE(NOW(), INTERVAL $recent_minutes MINUTE)) AND UNIX_TIMESTAMP(NOW())";
}

$method_expr = di_sql_method_expr('o');

$sql = "
SELECT
    MAX(CONCAT(o.rxdate, ' ', o.rxtime)) AS rx_datetime,
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
  $exclude_clause
  $recent_clause
  AND NOT EXISTS (
      SELECT 1 FROM opitemrece o2
      WHERE o2.hn = o.hn
        AND o2.icode = o.icode
        AND o2.vstdate < o.vstdate
        AND o2.vstdate >= DATE_SUB(o.vstdate, INTERVAL $lookback_months MONTH)
  )
  AND NOT EXISTS (
      SELECT 1 FROM lab_order lo2
      JOIN lab_head lh2 ON lo2.lab_order_number = lh2.lab_order_number
      WHERE lh2.hn = o.hn
        AND lo2.lab_items_code IN ($lab_code_in)
        AND lh2.order_date < o.vstdate
        AND lh2.order_date >= DATE_SUB(o.vstdate, INTERVAL $lookback_months MONTH)
  )
  AND NOT EXISTS (
      SELECT 1 FROM ptnote pn2
      WHERE pn2.hn = o.hn
        AND CAST(CONVERT(pn2.plain_text USING utf8) AS binary) REGEXP '$ptnote_esc'
  )
  AND NOT EXISTS (
      SELECT 1 FROM patient_rx_note pr2
      WHERE pr2.hn = o.hn
        AND CAST(CONVERT(pr2.rx_note USING utf8) AS binary) REGEXP '$rxnote_esc'
  )
GROUP BY o.vstdate, o.vn, o.an, d.icode, o.hn
ORDER BY rx_datetime DESC
LIMIT 5000
";

$t0 = microtime(true);
$q = mysqli_query($hos, $sql);
if (!$q) {
    $err = mysqli_error($hos);
    mysqli_close($hos);
    mysqli_close($con);
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'SQL Error: ' . $err], JSON_UNESCAPED_UNICODE);
    exit;
}

$alerts = [];
$hard_alerts = [];
$soft_alerts = [];
while ($row = mysqli_fetch_assoc($q)) {
    $rx_dt = trim($row['rx_datetime'] ?? '');
    $rx_date_fmt = !empty($row['rxdate']) ? date('d/m/Y', strtotime($row['rxdate'])) : '-';
    $rx_time_fmt = di_format_time(monitor_lab_normalize_hosxp_time($row['rxtime'] ?? ''));

    $type_patient = $row['type_patient'] ?? '';
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

    $icode = $row['icode'] ?? '';
    $drug_alert_level = $drugs[$icode]['alert_level'] ?? hla_default_alert_level();
    $alert_item = [
        'hn' => ltrim($row['hn'] ?? '', '0'),
        'hn_full' => $row['hn'] ?? '',
        'ptname' => trim($row['ptname'] ?? ''),
        'type_patient' => $type_patient,
        'vstdate' => $row['vstdate'] ?? '',
        'rx_datetime' => $rx_dt,
        'order_date' => $rx_date_fmt,
        'order_time' => $rx_time_fmt,
        'drug_name' => trim($row['drug_name'] ?? ''),
        'icode' => $icode,
        'method' => trim(preg_replace('/\s+/', ' ', $row['method'] ?? '')),
        'qty' => $row['qty'] ?? '',
        'ward_name' => $ward_name,
        'bedno' => $bedno,
        'ward_display' => $ward_display,
        'alert_level' => $drug_alert_level,
        'alert_message' => 'ยังไม่ได้คัดกรองยีนแพ้ยา (HLA-B) ก่อนเริ่มยาควบคุมโรคข้อ',
    ];
    $alerts[] = $alert_item;
    if ($drug_alert_level === 'hard') {
        $hard_alerts[] = $alert_item;
    } else {
        $soft_alerts[] = $alert_item;
    }
}

$elapsed = round((microtime(true) - $t0) * 1000);

mysqli_close($hos);
mysqli_close($con);

ob_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'status' => 'success',
    'alerts' => $alerts,
    'hard_alerts' => $hard_alerts,
    'soft_alerts' => $soft_alerts,
    'meta' => [
        'count' => count($alerts),
        'hard_count' => count($hard_alerts),
        'soft_count' => count($soft_alerts),
        'drugs_monitored' => count($drugs),
        'lookback_months' => $lookback_months,
        'recent_minutes' => $recent_minutes,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'elapsed_ms' => $elapsed,
    ],
], JSON_UNESCAPED_UNICODE);
