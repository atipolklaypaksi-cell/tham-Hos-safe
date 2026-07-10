<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start();
session_start();

include(__DIR__ . '/connect.php');
include(__DIR__ . '/hosxp_tmt_drug_helper.php');
include(__DIR__ . '/income_report_helper.php');
mysqli_set_charset($con, 'utf8');
set_time_limit(300);
irpt_ensure_cache_tables($con);

if (!isset($_SESSION['user_id']) && !isset($_SESSION['full_name'])) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = isset($_GET['action']) ? trim($_GET['action']) : 'report';
$start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : date('Y-m-01', strtotime('first day of previous month'));
$end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : date('Y-m-d', strtotime('last day of previous month'));
$period = rlu_parse_period($start_date, $end_date);
$start_date = $period['start_date'];
$end_date = $period['end_date'];
$income_group_ids = rlu_parse_income_group_ids($_GET['income_groups'] ?? '');
$hipdata_codes = rlu_parse_hipdata_codes($_GET['hipdata_codes'] ?? '');

$hos = hosxp_connect($con);

if ($action === 'status') {
    $compare = !empty($_GET['compare']);
    $compare_mode = isset($_GET['compare_mode']) ? trim($_GET['compare_mode']) : 'prev_year';
    $compare_params = [
        'compare_years' => isset($_GET['compare_years']) ? trim($_GET['compare_years']) : '',
        'compare_months' => isset($_GET['compare_months']) ? trim($_GET['compare_months']) : '',
        'compare_start' => isset($_GET['compare_start']) ? trim($_GET['compare_start']) : '',
        'compare_end' => isset($_GET['compare_end']) ? trim($_GET['compare_end']) : '',
    ];
    $months = irpt_collect_required_cache_months($start_date, $end_date, $compare, $compare_mode, $compare_params);
    $status_scope = isset($_GET['build_scope']) ? trim($_GET['build_scope']) : 'item';
    $missing = irpt_months_needing_build($con, $months, $status_scope, false);
    if ($hos) {
        mysqli_close($hos);
    }
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'success',
        'action' => 'status',
        'period' => $period,
        'months' => $months,
        'missing_months' => $missing,
        'build_scope' => irpt_normalize_build_scope($status_scope),
        'cached_count' => count($months) - count($missing),
        'total_months' => count($months),
        'ready' => empty($missing),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'build') {
    $year_month = isset($_GET['ym']) ? trim($_GET['ym']) : '';
    $force = !empty($_GET['force']);
    $scope = isset($_GET['scope']) ? trim($_GET['scope']) : 'item';
    if (!preg_match('/^\d{4}-\d{2}$/', $year_month)) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'รูปแบบเดือนไม่ถูกต้อง (YYYY-MM)'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!$hos) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    set_time_limit(300);
    hosxp_extend_read_timeout($hos, 300);
    $result = irpt_build_month_cache($con, $hos, $year_month, $force, $scope);
    mysqli_close($hos);
    if (!empty($result['error'])) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => $result['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'success',
        'action' => 'build',
        'result' => $result,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'build_batch') {
    $months_raw = isset($_GET['months']) ? trim($_GET['months']) : '';
    $months = rlu_parse_year_month_list($months_raw);
    $force = !empty($_GET['force']);
    $scope = isset($_GET['scope']) ? trim($_GET['scope']) : 'item';
    if (empty($months)) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'ไม่มีเดือนที่ต้องประมวลผล'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!$hos) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    set_time_limit(600);
    hosxp_extend_read_timeout($hos, 600);
    $result = irpt_build_months_cache_batch($con, $hos, $months, $force, $scope);
    mysqli_close($hos);
    if (!empty($result['error'])) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => $result['error'], 'result' => $result], JSON_UNESCAPED_UNICODE);
        exit;
    }
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'success',
        'action' => 'build_batch',
        'result' => $result,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'hipdata_groups') {
    $options = rlu_fetch_hipdata_group_options($hos);
    if ($hos) {
        mysqli_close($hos);
    }
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'success',
        'action' => 'hipdata_groups',
        'groups' => $options,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'income_groups') {
    $options = rlu_fetch_income_group_options($hos);
    if ($hos) {
        mysqli_close($hos);
    }
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'success',
        'action' => 'income_groups',
        'groups' => $options,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($income_group_ids)) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'กรุณาเลือกหมวดรายได้อย่างน้อย 1 หมวด'], JSON_UNESCAPED_UNICODE);
    if ($hos) {
        mysqli_close($hos);
    }
    exit;
}

if (!$hos) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้'], JSON_UNESCAPED_UNICODE);
    exit;
}

$income_opts = [
    'income_group_ids' => $income_group_ids,
    'hipdata_codes' => $hipdata_codes,
    'visit_scope' => rlu_normalize_visit_scope(isset($_GET['doctor_agg']) ? trim($_GET['doctor_agg']) : 'total'),
];

if ($action === 'compare_dimension') {
    set_time_limit(300);
    hosxp_extend_read_timeout($hos, 180);
    $dimension = isset($_GET['dimension']) ? trim($_GET['dimension']) : 'item';
    if (!in_array($dimension, ['item', 'dept', 'doctor'], true)) {
        $dimension = 'item';
    }
    $compare = !empty($_GET['compare']);
    $compare_mode = isset($_GET['compare_mode']) ? trim($_GET['compare_mode']) : 'prev_year';
    $compare_params = [
        'compare_years' => isset($_GET['compare_years']) ? trim($_GET['compare_years']) : '',
        'compare_months' => isset($_GET['compare_months']) ? trim($_GET['compare_months']) : '',
        'compare_start' => isset($_GET['compare_start']) ? trim($_GET['compare_start']) : '',
        'compare_end' => isset($_GET['compare_end']) ? trim($_GET['compare_end']) : '',
    ];
    $periods = rlu_resolve_detail_periods($start_date, $end_date, $compare, $compare_mode, $compare_params);
    $months_needed = [];
    foreach ($periods as $p) {
        $months_needed = array_merge($months_needed, rlu_month_keys_in_period($p['start_date'], $p['end_date']));
    }
    $months_needed = array_values(array_unique($months_needed));
    $missing_cache = irpt_list_missing_dimension_cache_months($con, $months_needed, $dimension);
    if (!empty($missing_cache)) {
        if ($hos) {
            mysqli_close($hos);
        }
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        $cache_label = ($dimension === 'doctor') ? 'แพทย์' : (($dimension === 'dept') ? 'แผนก' : 'รายการยา/เวชภัณฑ์');
        echo json_encode([
            'status' => 'need_build',
            'message' => 'ยังไม่มี cache ' . $cache_label . ' — ต้องประมวลผลรายเดือนก่อน',
            'missing_months' => $missing_cache,
            'total_months' => count($missing_cache),
            'build_scope' => $dimension,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $peer = $compare && rlu_is_peer_compare_mode($compare_mode, $compare_params);
    $conv_opts = array_merge($income_opts, []);
    if ($peer) {
        $conv_opts['peer_compare'] = true;
        $conv_opts['granularity'] = ($compare_mode === 'month') ? 'month' : 'year';
        $conv_opts['baseline_key'] = $periods[0]['label'] ?? '';
    }
    $t0 = microtime(true);
    $comparison = irpt_build_global_dimension_comparison($hos, $periods, $dimension, $conv_opts, $con);
    mysqli_close($hos);
    if (($comparison['error'] ?? '') !== '') {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => $comparison['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'success',
        'action' => 'compare_dimension',
        'dimension' => $dimension,
        'comparison' => $comparison,
        'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'item_doctors') {
    set_time_limit(240);
    $item_code = isset($_GET['item_code']) ? trim($_GET['item_code']) : '';
    $item_name = isset($_GET['item_name']) ? trim($_GET['item_name']) : '';
    $compare = !empty($_GET['compare']);
    $compare_mode = isset($_GET['compare_mode']) ? trim($_GET['compare_mode']) : 'prev_year';
    $compare_params = [
        'compare_years' => isset($_GET['compare_years']) ? trim($_GET['compare_years']) : '',
        'compare_months' => isset($_GET['compare_months']) ? trim($_GET['compare_months']) : '',
        'compare_start' => isset($_GET['compare_start']) ? trim($_GET['compare_start']) : '',
        'compare_end' => isset($_GET['compare_end']) ? trim($_GET['compare_end']) : '',
    ];
    $periods = rlu_resolve_detail_periods($start_date, $end_date, $compare, $compare_mode, $compare_params);
    set_time_limit(300);
    hosxp_extend_read_timeout($hos, 300);
    $t0 = microtime(true);
    $detail = irpt_build_item_doctors_detail($hos, $periods, $item_code, $income_opts, $con);
    mysqli_close($hos);
    if (($detail['error'] ?? '') !== '') {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => $detail['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'success',
        'action' => 'item_doctors',
        'item_code' => $item_code,
        'item_name' => $item_name,
        'detail' => $detail,
        'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'item_depts') {
    set_time_limit(300);
    hosxp_extend_read_timeout($hos, 300);
    $item_code = isset($_GET['item_code']) ? trim($_GET['item_code']) : '';
    $compare = !empty($_GET['compare']);
    $compare_mode = isset($_GET['compare_mode']) ? trim($_GET['compare_mode']) : 'prev_year';
    $compare_params = [
        'compare_years' => isset($_GET['compare_years']) ? trim($_GET['compare_years']) : '',
        'compare_months' => isset($_GET['compare_months']) ? trim($_GET['compare_months']) : '',
        'compare_start' => isset($_GET['compare_start']) ? trim($_GET['compare_start']) : '',
        'compare_end' => isset($_GET['compare_end']) ? trim($_GET['compare_end']) : '',
    ];
    $periods = rlu_resolve_detail_periods($start_date, $end_date, $compare, $compare_mode, $compare_params);
    $t0 = microtime(true);
    $detail = irpt_build_dimension_detail($hos, $periods, 'dept', array_merge($income_opts, [
        'item_code' => $item_code,
        'limit' => 200,
    ]), $con);
    mysqli_close($hos);
    if (($detail['error'] ?? '') !== '') {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => $detail['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'success',
        'action' => 'item_depts',
        'item_code' => $item_code,
        'departments' => $detail,
        'detail' => $detail,
        'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'doctor_items') {
    set_time_limit(240);
    $doctor_code = isset($_GET['doctor_code']) ? trim($_GET['doctor_code']) : '';
    $doctor_name = isset($_GET['doctor_name']) ? trim($_GET['doctor_name']) : '';
    $compare = !empty($_GET['compare']);
    $compare_mode = isset($_GET['compare_mode']) ? trim($_GET['compare_mode']) : 'prev_year';
    $compare_params = [
        'compare_years' => isset($_GET['compare_years']) ? trim($_GET['compare_years']) : '',
        'compare_months' => isset($_GET['compare_months']) ? trim($_GET['compare_months']) : '',
        'compare_start' => isset($_GET['compare_start']) ? trim($_GET['compare_start']) : '',
        'compare_end' => isset($_GET['compare_end']) ? trim($_GET['compare_end']) : '',
    ];
    $periods = rlu_resolve_detail_periods($start_date, $end_date, $compare, $compare_mode, $compare_params);
    $t0 = microtime(true);
    $detail = irpt_build_dimension_detail($hos, $periods, 'item', array_merge($income_opts, [
        'doctor_code' => $doctor_code,
    ]));
    mysqli_close($hos);
    if (($detail['error'] ?? '') !== '') {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => $detail['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'success',
        'action' => 'doctor_items',
        'doctor_code' => $doctor_code,
        'doctor_name' => $doctor_name,
        'detail' => $detail,
        'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$t0 = microtime(true);
hosxp_extend_read_timeout($hos, 120);
$report = irpt_aggregate_report($hos, $start_date, $end_date, $income_group_ids, $income_opts, $con);
mysqli_close($hos);

if (!empty($report['error'])) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => $report['error']], JSON_UNESCAPED_UNICODE);
    exit;
}

$report['meta']['elapsed_ms'] = (int)round((microtime(true) - $t0) * 1000);

ob_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'status' => 'success',
    'action' => 'report',
    'period' => $period,
    'summary' => $report['summary'],
    'rows' => $report['rows'],
    'meta' => $report['meta'],
], JSON_UNESCAPED_UNICODE);
exit;
