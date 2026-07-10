<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start();
session_start();

include(__DIR__ . '/connect.php');
include(__DIR__ . '/hosxp_tmt_drug_helper.php');
include(__DIR__ . '/monitor_drug_allergy_helper.php');
mysqli_set_charset($con, 'utf8');
set_time_limit(300);
ini_set('memory_limit', '512M');

if (!isset($_SESSION['user_id']) && !isset($_SESSION['full_name'])) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : date('Y-m-d', strtotime('-3 days'));
$end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : date('Y-m-d');
$recent_minutes = isset($_GET['recent_minutes']) ? (int)$_GET['recent_minutes'] : 60;

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

$cfg = mda_load_config($con);
$cross_rules = mda_load_cross_rules($con, true);

$hos = hosxp_connect($con);
if (!$hos) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้'], JSON_UNESCAPED_UNICODE);
    exit;
}

$start_esc = mysqli_real_escape_string($hos, $start_date);
$end_esc = mysqli_real_escape_string($hos, $end_date);
$exclude_clause = mda_build_exclude_clause($hos, $cfg['mda_exclude_hns'] ?? '');

$recent_clause = '';
if ($recent_minutes > 0) {
    $recent_clause = "AND UNIX_TIMESTAMP(CONCAT(o.rxdate, ' ', IFNULL(o.rxtime,'00:00:00')))
        BETWEEN UNIX_TIMESTAMP(SUBDATE(NOW(), INTERVAL $recent_minutes MINUTE)) AND UNIX_TIMESTAMP(NOW())";
}

$tmt_expr = hosxp_sql_tmt_drug_name('d', 'tm');
$t0 = microtime(true);

$sql_rx = "
SELECT
    sub.hn, sub.vn, sub.an, sub.vstdate, sub.rxdate, sub.rxtime, sub.icode, sub.qty,
    IF(sub.vn IS NULL OR sub.vn = '', 'IPD', 'OPD') AS type_patient,
    CAST(CONVERT(CONCAT(IFNULL(pt.pname,''), IFNULL(pt.fname,''), ' ', IFNULL(pt.lname,'')) USING utf8) AS CHAR) AS ptname,
    CAST(CONVERT(d.name USING utf8) AS CHAR) AS local_name,
    $tmt_expr AS tmt_name,
    CAST(CONVERT(IFNULL(tm.active_ingredient,'') USING utf8) AS CHAR) AS active_ingredient,
    '' AS ward_name,
    '' AS bedno,
    dp.pharmacology_group_id,
    dp.pharmacology_group_id_2,
    dp.pharmacology_group_id_3,
    dp.pharmacology_group_id_4
FROM (
    SELECT o.hn, o.vn, o.an, o.vstdate, o.rxdate, o.rxtime, o.icode, o.qty, o.item_no
    FROM opitemrece o
    INNER JOIN (
        SELECT DISTINCT hn FROM opd_allergy
        WHERE agent IS NOT NULL AND TRIM(agent) <> ''
          AND (no_alert IS NULL OR no_alert <> 'Y')
    ) al_hn ON al_hn.hn = o.hn
    WHERE o.rxdate BETWEEN '$start_esc' AND '$end_esc'
      AND o.qty > 0
      $exclude_clause
      $recent_clause
    ORDER BY o.rxdate DESC, o.rxtime DESC, o.item_no DESC
    LIMIT 3000
) sub
INNER JOIN drugitems d ON d.icode = sub.icode
LEFT JOIN patient pt ON pt.hn = sub.hn
" . hosxp_sql_tmt_join('d', 'tm') . "
LEFT JOIN drugitems_pharmacology dp ON dp.icode = sub.icode
ORDER BY sub.rxdate DESC, sub.rxtime DESC, sub.item_no DESC
";

$q_rx = mysqli_query($hos, $sql_rx);
if (!$q_rx) {
    $err = mysqli_error($hos);
    mysqli_close($hos);
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'SQL Error: ' . $err], JSON_UNESCAPED_UNICODE);
    exit;
}

$rx_rows = [];
$hn_set = [];
while ($row = mysqli_fetch_assoc($q_rx)) {
    $rx_rows[] = $row;
    $hn = trim($row['hn'] ?? '');
    if ($hn !== '') {
        $hn_set[$hn] = true;
    }
}

$allergies_by_hn = [];
if (!empty($hn_set)) {
    $hn_in = [];
    foreach (array_keys($hn_set) as $hn) {
        $hn_in[] = "'" . mysqli_real_escape_string($hos, $hn) . "'";
    }
    $sql_al = "SELECT opd_allergy_id, hn, agent, symptom, report_date, icode, allergy_group_id, no_alert
        FROM opd_allergy
        WHERE hn IN (" . implode(',', $hn_in) . ")
          AND agent IS NOT NULL AND TRIM(agent) <> ''
          AND (no_alert IS NULL OR no_alert <> 'Y')";
    $q_al = mysqli_query($hos, $sql_al);
    if ($q_al) {
        while ($al = mysqli_fetch_assoc($q_al)) {
            $hn = trim($al['hn'] ?? '');
            if ($hn === '') {
                continue;
            }
            if (!isset($allergies_by_hn[$hn])) {
                $allergies_by_hn[$hn] = [];
            }
            $allergies_by_hn[$hn][] = $al;
        }
    }
}

$pharma_index = mda_build_pharma_index($hos);

$hard_alerts = [];
$soft_alerts = [];
$dedupe = [];

foreach ($rx_rows as $rx) {
    $hn = trim($rx['hn'] ?? '');
    $allergies = $allergies_by_hn[$hn] ?? [];
    if (empty($allergies)) {
        continue;
    }
    $result = mda_evaluate_prescription($rx, $allergies, $cfg, $cross_rules, $pharma_index);
    foreach (['hard' => &$hard_alerts, 'soft' => &$soft_alerts] as $bucket => &$target) {
        foreach ($result[$bucket] as $item) {
            $key = implode('|', [
                $item['hn_full'],
                $item['icode'],
                $item['rxdate'],
                $item['order_time'],
                $item['allergy_agent'],
                $item['match_type'],
            ]);
            if (isset($dedupe[$key])) {
                continue;
            }
            $dedupe[$key] = true;
            $target[] = $item;
        }
    }
}
unset($target);

$elapsed = (int)round((microtime(true) - $t0) * 1000);
mysqli_close($hos);

ob_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'status' => 'success',
    'hard_alerts' => $hard_alerts,
    'soft_alerts' => $soft_alerts,
    'meta' => [
        'hard_count' => count($hard_alerts),
        'soft_count' => count($soft_alerts),
        'rx_scanned' => count($rx_rows),
        'patients_with_allergy' => count($allergies_by_hn),
        'cross_rules' => count($cross_rules),
        'cross_group_enabled' => ($cfg['mda_cross_group_enabled'] ?? '1') === '1',
        'pharmacology_group_enabled' => ($cfg['mda_pharmacology_group_enabled'] ?? '1') === '1',
        'start_date' => $start_date,
        'end_date' => $end_date,
        'recent_minutes' => $recent_minutes,
        'elapsed_ms' => $elapsed,
    ],
], JSON_UNESCAPED_UNICODE);
