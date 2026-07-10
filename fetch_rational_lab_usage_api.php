<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start();
session_start();

include(__DIR__ . '/connect.php');
include(__DIR__ . '/hosxp_tmt_drug_helper.php');
include(__DIR__ . '/rational_lab_use_helper.php');
mysqli_set_charset($con, 'utf8');
set_time_limit(120);

if (!isset($_SESSION['user_id']) && !isset($_SESSION['full_name'])) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

rlu_ensure_cache_table($con);

$action = isset($_GET['action']) ? trim($_GET['action']) : 'report';
$start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : date('Y-01-01');
$end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : date('Y-m-d');
$period = rlu_parse_period($start_date, $end_date);
$start_date = $period['start_date'];
$end_date = $period['end_date'];
$hipdata_codes = rlu_parse_hipdata_filter_opts($con, $_GET['hipdata_codes'] ?? '');
$rlu_filter_opts = ['hipdata_codes' => $hipdata_codes];

$hos = hosxp_connect($con);

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
if (!$hos && in_array($action, ['build', 'build_batch', 'status'], true)) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'status') {
    $compare = !empty($_GET['compare']);
    $compare_mode = isset($_GET['compare_mode']) ? trim($_GET['compare_mode']) : 'prev_year';
    $compare_params = [
        'compare_years' => isset($_GET['compare_years']) ? trim($_GET['compare_years']) : '',
        'compare_year' => isset($_GET['compare_year']) ? (int)$_GET['compare_year'] : 0,
        'compare_months' => isset($_GET['compare_months']) ? trim($_GET['compare_months']) : '',
        'compare_month' => isset($_GET['compare_month']) ? trim($_GET['compare_month']) : '',
        'compare_start' => isset($_GET['compare_start']) ? trim($_GET['compare_start']) : '',
        'compare_end' => isset($_GET['compare_end']) ? trim($_GET['compare_end']) : '',
    ];
    if (!rlu_api_needs_cache($rlu_filter_opts)) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'success',
            'action' => 'status',
            'period' => $period,
            'months' => [],
            'missing_months' => [],
            'cached_count' => 0,
            'total_months' => 0,
            'ready' => true,
            'hipdata_filter' => true,
        ], JSON_UNESCAPED_UNICODE);
        if ($hos) {
            mysqli_close($hos);
        }
        exit;
    }
    $months = rlu_collect_all_required_months($start_date, $end_date, $compare, $compare_mode, $compare_params);
    $status_scope = isset($_GET['build_scope']) ? trim($_GET['build_scope']) : 'usage';
    $missing = rlu_months_needing_build($con, $months, $status_scope, false);
    $cached = count($months) - count($missing);
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'success',
        'action' => 'status',
        'period' => $period,
        'months' => $months,
        'missing_months' => $missing,
        'build_scope' => rlu_normalize_build_scope($status_scope),
        'cached_count' => $cached,
        'total_months' => count($months),
        'ready' => empty($missing),
    ], JSON_UNESCAPED_UNICODE);
    if ($hos) {
        mysqli_close($hos);
    }
    exit;
}

if ($action === 'build') {
    $year_month = isset($_GET['ym']) ? trim($_GET['ym']) : '';
    $force = !empty($_GET['force']);
    $scope = isset($_GET['scope']) ? trim($_GET['scope']) : 'usage';
    if (!preg_match('/^\d{4}-\d{2}$/', $year_month)) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'รูปแบบเดือนไม่ถูกต้อง (YYYY-MM)'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    set_time_limit(300);
    hosxp_extend_read_timeout($hos, 300);
    $result = rlu_build_month_cache($con, $hos, $year_month, $force, $scope);
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
    $scope = isset($_GET['scope']) ? trim($_GET['scope']) : 'usage';
    if (empty($months)) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'ไม่มีเดือนที่ต้องประมวลผล'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    set_time_limit(600);
    hosxp_extend_read_timeout($hos, 600);
    $result = rlu_build_months_cache_batch($con, $hos, $months, $force, $scope);
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

if ($action === 'peer_report') {
    $compare_mode = isset($_GET['compare_mode']) ? trim($_GET['compare_mode']) : 'month';
    $compare_params = [
        'compare_years' => isset($_GET['compare_years']) ? trim($_GET['compare_years']) : '',
        'compare_months' => isset($_GET['compare_months']) ? trim($_GET['compare_months']) : '',
    ];
    if (!rlu_is_peer_compare_mode($compare_mode, $compare_params)) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'โหมดเปรียบเทียบแบบเทียบกันเองต้องเลือกอย่างน้อย 2 ช่วง'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $slices_data = [];
    $all_missing = [];
    if ($compare_mode === 'month') {
        $keys = rlu_parse_compare_months($compare_params['compare_months'], $end_date, 3, true);
        $granularity = 'month';
        foreach ($keys as $cm) {
            $period_b = rlu_shift_period_to_month($start_date, $end_date, $cm);
            $months_b = rlu_month_keys_in_period($period_b['start_date'], $period_b['end_date']);
            foreach (rlu_list_missing_months($con, $months_b) as $ym) {
                if (!in_array($ym, $all_missing, true)) {
                    $all_missing[] = $ym;
                }
            }
            $slices_data[] = ['key' => $cm, 'period' => $period_b, 'months' => $months_b];
        }
    } else {
        $keys = rlu_parse_compare_years($compare_params['compare_years'], $start_date, 3, true);
        $granularity = 'year';
        foreach ($keys as $yr) {
            $period_b = rlu_shift_period_to_year($start_date, $end_date, $yr);
            $months_b = rlu_month_keys_in_period($period_b['start_date'], $period_b['end_date']);
            foreach (rlu_list_missing_months($con, $months_b) as $ym) {
                if (!in_array($ym, $all_missing, true)) {
                    $all_missing[] = $ym;
                }
            }
            $slices_data[] = ['key' => (string)$yr, 'period' => $period_b, 'months' => $months_b];
        }
    }
    if (rlu_api_needs_cache($rlu_filter_opts) && !empty($all_missing)) {
        if ($hos) {
            mysqli_close($hos);
        }
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'need_build',
            'message' => 'ยังไม่มี cache ช่วงเปรียบเทียบ — ต้องประมวลผลรายเดือนก่อน',
            'missing_months' => $all_missing,
            'total_months' => count($all_missing),
            'cached_count' => 0,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!rlu_api_needs_cache($rlu_filter_opts) && !$hos) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (rlu_api_needs_cache($rlu_filter_opts) && $hos) {
        mysqli_close($hos);
        $hos = null;
    }
    $built = [];
    foreach ($slices_data as $sd) {
        $report = rlu_api_aggregate_period($con, $hos, $sd['period']['start_date'], $sd['period']['end_date'], $rlu_filter_opts);
        $built[] = [
            'key' => $sd['key'],
            'label' => $sd['period']['label'],
            'period' => $sd['period'],
            'report' => $report,
        ];
    }
    $comparison = rlu_build_peer_comparison($built, $granularity);
    if ($hos) {
        mysqli_close($hos);
    }
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'success',
        'action' => 'peer_report',
        'comparison' => $comparison,
        'period_keys' => array_column($built, 'key'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'group_items') {
    set_time_limit(240);
    $group_code = isset($_GET['group_code']) ? trim($_GET['group_code']) : '';
    $group_name = isset($_GET['group_name']) ? trim($_GET['group_name']) : '';
    $dimension = isset($_GET['dimension']) ? trim($_GET['dimension']) : 'item';
    if (!in_array($dimension, ['item', 'dept', 'doctor', 'all'], true)) {
        $dimension = 'item';
    }
    $filter_year = isset($_GET['year']) ? (int)$_GET['year'] : 0;
    $compare = !empty($_GET['compare']);
    $compare_mode = isset($_GET['compare_mode']) ? trim($_GET['compare_mode']) : 'prev_year';
    $compare_params = [
        'compare_years' => isset($_GET['compare_years']) ? trim($_GET['compare_years']) : '',
        'compare_months' => isset($_GET['compare_months']) ? trim($_GET['compare_months']) : '',
        'compare_start' => isset($_GET['compare_start']) ? trim($_GET['compare_start']) : '',
        'compare_end' => isset($_GET['compare_end']) ? trim($_GET['compare_end']) : '',
    ];
    $periods = rlu_resolve_detail_periods($start_date, $end_date, $compare, $compare_mode, $compare_params);
    if ($filter_year > 0) {
        $clipped = [];
        foreach ($periods as $p) {
            $cp = rlu_clip_period_to_year($p, $filter_year);
            if ($cp !== null) {
                $clipped[] = $cp;
            }
        }
        $periods = !empty($clipped) ? $clipped : [rlu_parse_period(sprintf('%04d-01-01', $filter_year), sprintf('%04d-12-31', $filter_year))];
    }
    $months_needed = [];
    foreach ($periods as $p) {
        $months_needed = array_merge($months_needed, rlu_month_keys_in_period($p['start_date'], $p['end_date']));
    }
    $months_needed = array_values(array_unique($months_needed));
    $cache_scopes = $dimension === 'all' ? null : [$dimension];
    $dim_missing = rlu_api_needs_cache($rlu_filter_opts)
        ? rlu_first_missing_dimension_cache($con, $months_needed, $cache_scopes)
        : null;
    if (!empty($dim_missing)) {
        if ($hos) {
            mysqli_close($hos);
        }
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'need_build',
            'message' => 'ยังไม่มี cache ' . rlu_dimension_cache_scope_label($dim_missing['scope']) . ' — ต้องประมวลผลรายเดือนก่อน',
            'missing_months' => $dim_missing['months'],
            'total_months' => count($dim_missing['months']),
            'build_scope' => $dim_missing['scope'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $needs_hosxp_dim = $dimension !== 'item'
        && ($dimension === 'all' || in_array($dimension, ['dept', 'doctor'], true))
        && rlu_group_detail_needs_hosxp($group_code, $rlu_filter_opts);
    if ($dimension === 'item' && rlu_api_needs_cache($rlu_filter_opts) && $hos) {
        mysqli_close($hos);
        $hos = null;
    } elseif (rlu_api_needs_cache($rlu_filter_opts) && $hos && !$needs_hosxp_dim) {
        mysqli_close($hos);
        $hos = null;
    }
    if (!$needs_hosxp_dim && !rlu_api_needs_cache($rlu_filter_opts) && !$hos) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($needs_hosxp_dim && !$hos) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้ — แท็บนี้ต้องใช้ข้อมูลสด'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($needs_hosxp_dim && $hos) {
        hosxp_extend_read_timeout($hos, 600);
    }
    $t0 = microtime(true);
    if ($dimension === 'all') {
        $full = rlu_build_group_full_detail($hos, $periods, $group_code, $rlu_filter_opts, $con);
        if ($hos) {
            mysqli_close($hos);
        }
        if (($full['error'] ?? '') !== '') {
            ob_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => $full['error']], JSON_UNESCAPED_UNICODE);
            exit;
        }
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'success',
            'action' => 'group_items',
            'dimension' => 'all',
            'group_code' => $group_code,
            'group_name' => $group_name,
            'detail' => $full['items'],
            'items' => $full['items'],
            'departments' => $full['departments'],
            'doctors' => $full['doctors'],
            'warnings' => $full['warnings'] ?? [],
            'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $detail = rlu_build_group_dimension_detail($hos, $periods, $group_code, $dimension, $rlu_filter_opts, $con);
    if ($hos) {
        mysqli_close($hos);
    }
    if (($detail['error'] ?? '') !== '') {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => $detail['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $payload = [
        'status' => 'success',
        'action' => 'group_items',
        'dimension' => $dimension,
        'group_code' => $group_code,
        'group_name' => $group_name,
        'detail' => $detail,
        'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
    ];
    if ($dimension === 'item') {
        $payload['items'] = $detail;
    } elseif ($dimension === 'dept') {
        $payload['departments'] = $detail;
    } elseif ($dimension === 'doctor') {
        $payload['doctors'] = $detail;
    }
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'group_doctor_items') {
    if (!$hos) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    set_time_limit(240);
    hosxp_extend_read_timeout($hos, 300);
    $group_code = isset($_GET['group_code']) ? trim($_GET['group_code']) : '';
    $group_name = isset($_GET['group_name']) ? trim($_GET['group_name']) : '';
    $doctor_code = isset($_GET['doctor_code']) ? trim($_GET['doctor_code']) : '';
    $doctor_name = isset($_GET['doctor_name']) ? trim($_GET['doctor_name']) : '';
    $filter_year = isset($_GET['year']) ? (int)$_GET['year'] : 0;
    $compare = !empty($_GET['compare']);
    $compare_mode = isset($_GET['compare_mode']) ? trim($_GET['compare_mode']) : 'prev_year';
    $compare_params = [
        'compare_years' => isset($_GET['compare_years']) ? trim($_GET['compare_years']) : '',
        'compare_months' => isset($_GET['compare_months']) ? trim($_GET['compare_months']) : '',
        'compare_start' => isset($_GET['compare_start']) ? trim($_GET['compare_start']) : '',
        'compare_end' => isset($_GET['compare_end']) ? trim($_GET['compare_end']) : '',
    ];
    $periods = rlu_resolve_detail_periods($start_date, $end_date, $compare, $compare_mode, $compare_params);
    if ($filter_year > 0) {
        $clipped = [];
        foreach ($periods as $p) {
            $cp = rlu_clip_period_to_year($p, $filter_year);
            if ($cp !== null) {
                $clipped[] = $cp;
            }
        }
        $periods = !empty($clipped) ? $clipped : [rlu_parse_period(sprintf('%04d-01-01', $filter_year), sprintf('%04d-12-31', $filter_year))];
    }
    $period_index = isset($_GET['period_index']) ? (int)$_GET['period_index'] : -1;
    if ($period_index >= 0 && isset($periods[$period_index])) {
        $periods = [$periods[$period_index]];
    }
    $t0 = microtime(true);
    $detail = rlu_build_group_doctor_items_detail($hos, $periods, $group_code, $doctor_code, $rlu_filter_opts);
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
        'action' => 'group_doctor_items',
        'group_code' => $group_code,
        'group_name' => $group_name,
        'doctor_code' => $doctor_code,
        'doctor_name' => $doctor_name,
        'detail' => $detail,
        'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'item_doctors') {
    if (!$hos) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    set_time_limit(240);
    hosxp_extend_read_timeout($hos, 300);
    $item_code = isset($_GET['item_code']) ? trim($_GET['item_code']) : '';
    $item_name = isset($_GET['item_name']) ? trim($_GET['item_name']) : '';
    $group_code = isset($_GET['group_code']) ? trim($_GET['group_code']) : '__all__';
    if ($group_code === '') {
        $group_code = '__all__';
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
    $period_index = isset($_GET['period_index']) ? (int)$_GET['period_index'] : -1;
    if ($period_index >= 0 && isset($periods[$period_index])) {
        $periods = [$periods[$period_index]];
    }
    $t0 = microtime(true);
    $detail = rlu_build_item_doctors_detail($hos, $periods, $item_code, array_merge($rlu_filter_opts, [
        'group_code' => $group_code,
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
        'action' => 'item_doctors',
        'item_code' => $item_code,
        'item_name' => $item_name,
        'group_code' => $group_code,
        'detail' => $detail,
        'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'dept_doctors') {
    if (!$hos) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    set_time_limit(240);
    hosxp_extend_read_timeout($hos, 300);
    $dept_code = isset($_GET['dept_code']) ? trim($_GET['dept_code']) : '';
    $dept_name = isset($_GET['dept_name']) ? trim($_GET['dept_name']) : '';
    $group_code = isset($_GET['group_code']) ? trim($_GET['group_code']) : '__all__';
    if ($group_code === '') {
        $group_code = '__all__';
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
    $period_index = isset($_GET['period_index']) ? (int)$_GET['period_index'] : -1;
    if ($period_index >= 0 && isset($periods[$period_index])) {
        $periods = [$periods[$period_index]];
    }
    $t0 = microtime(true);
    $detail = rlu_build_dept_doctors_detail($hos, $periods, $dept_code, array_merge($rlu_filter_opts, [
        'group_code' => $group_code,
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
        'action' => 'dept_doctors',
        'dept_code' => $dept_code,
        'dept_name' => $dept_name,
        'group_code' => $group_code,
        'detail' => $detail,
        'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'compare_dimension') {
    set_time_limit(300);
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
    $months_needed = rlu_collect_all_required_months($start_date, $end_date, $compare, $compare_mode, $compare_params);
    $missing_items = ($dimension === 'item') ? rlu_list_missing_item_months($con, $months_needed) : [];
    $missing_doctors = ($dimension === 'doctor') ? rlu_list_missing_doctor_months($con, $months_needed) : [];
    $missing_depts = ($dimension === 'dept') ? rlu_list_missing_dept_months($con, $months_needed) : [];
    $missing_cache = rlu_api_needs_cache($rlu_filter_opts)
        ? (!empty($missing_items) ? $missing_items : (!empty($missing_doctors) ? $missing_doctors : $missing_depts))
        : [];
    if ($dimension === 'dept' && !$con && !$hos) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($dimension !== 'item' && $dimension !== 'doctor' && $dimension !== 'dept' && !$hos) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!rlu_api_needs_cache($rlu_filter_opts) && !$hos) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!empty($missing_cache)) {
        if (!$hos) {
            ob_clean();
            header('Content-Type: application/json; charset=utf-8');
            $cache_label = ($dimension === 'doctor') ? 'แพทย์' : (($dimension === 'dept') ? 'แผนก' : 'รายการ Lab ย่อย');
            echo json_encode([
                'status' => 'need_build',
                'message' => 'ยังไม่มี cache ' . $cache_label . ' — ต้องประมวลผลรายเดือนก่อน',
                'missing_months' => $missing_cache,
                'total_months' => count($missing_cache),
                'build_scope' => $dimension,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        mysqli_close($hos);
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        $cache_label = ($dimension === 'doctor') ? 'แพทย์' : (($dimension === 'dept') ? 'แผนก' : 'รายการ Lab ย่อย');
        echo json_encode([
            'status' => 'need_build',
            'message' => 'ยังไม่มี cache ' . $cache_label . ' — ต้องประมวลผลรายเดือนก่อน',
            'missing_months' => $missing_cache,
            'total_months' => count($missing_cache),
            'build_scope' => $dimension,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (rlu_api_needs_cache($rlu_filter_opts) && $hos) {
        mysqli_close($hos);
        $hos = null;
    }
    $peer = $compare && rlu_is_peer_compare_mode($compare_mode, $compare_params);
    $conv_opts = [];
    if ($peer) {
        $conv_opts['peer_compare'] = true;
        $conv_opts['granularity'] = ($compare_mode === 'month') ? 'month' : 'year';
        $conv_opts['baseline_key'] = $periods[0]['label'] ?? '';
    }
    $t0 = microtime(true);
    $doctor_agg = isset($_GET['doctor_agg']) ? trim($_GET['doctor_agg']) : 'total';
    $conv_opts['visit_scope'] = rlu_normalize_visit_scope($doctor_agg);
    $conv_opts = array_merge($rlu_filter_opts, $conv_opts);
    $comparison = rlu_build_global_dimension_comparison($hos, $con, $periods, $dimension, $conv_opts);
    if ($hos) {
        mysqli_close($hos);
    }
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

if ($action === 'compare_slice') {
    $compare_mode = isset($_GET['compare_mode']) ? trim($_GET['compare_mode']) : 'prev_year';
    $compare_params = [
        'compare_year' => isset($_GET['compare_year']) ? (int)$_GET['compare_year'] : 0,
        'compare_month' => isset($_GET['compare_month']) ? trim($_GET['compare_month']) : '',
        'compare_start' => isset($_GET['compare_start']) ? trim($_GET['compare_start']) : '',
        'compare_end' => isset($_GET['compare_end']) ? trim($_GET['compare_end']) : '',
    ];
    $peer_only = !empty($_GET['peer_only']);
    $period_b = rlu_resolve_compare_period($compare_mode, $start_date, $end_date, $compare_params);
    $months_b = rlu_month_keys_in_period($period_b['start_date'], $period_b['end_date']);
    $months_check = $peer_only ? $months_b : array_merge(rlu_month_keys_in_period($start_date, $end_date), $months_b);
    $missing = rlu_api_needs_cache($rlu_filter_opts)
        ? rlu_list_missing_months($con, $months_check)
        : [];
    if (rlu_api_needs_cache($rlu_filter_opts) && !empty($missing)) {
        if ($hos) {
            mysqli_close($hos);
        }
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'need_build',
            'message' => 'ยังไม่มี cache ช่วงเปรียบเทียบ — ต้องประมวลผลรายเดือนก่อน',
            'period' => $period,
            'compare_period' => $period_b,
            'missing_months' => $missing,
            'total_months' => count($missing),
            'cached_count' => 0,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!rlu_api_needs_cache($rlu_filter_opts) && !$hos) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (rlu_api_needs_cache($rlu_filter_opts) && $hos) {
        mysqli_close($hos);
        $hos = null;
    }
    $t0 = microtime(true);
    $report_b = rlu_api_aggregate_period($con, $hos, $period_b['start_date'], $period_b['end_date'], $rlu_filter_opts);
    if ($peer_only) {
        $slice_year = $compare_mode === 'year' ? (int)$compare_params['compare_year'] : null;
        $slice_month = $compare_mode === 'month' ? $compare_params['compare_month'] : null;
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'success',
            'action' => 'compare_slice',
            'peer_slice' => true,
            'year' => $slice_year,
            'compare_month' => $slice_month,
            'period' => $period_b,
            'report' => [
                'rows' => $report_b['rows'],
                'summary' => $report_b['summary'],
            ],
            'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $report_a = rlu_api_aggregate_period($con, $hos, $start_date, $end_date, $rlu_filter_opts);
    $comparison = rlu_build_comparison($report_a, $report_b, $period['label'], $period_b['label']);
    $slice_year = $compare_mode === 'year' ? (int)$compare_params['compare_year'] : null;
    $slice_month = $compare_mode === 'month' ? $compare_params['compare_month'] : null;
    if ($slice_year === null && $slice_month === null) {
        $slice_year = (int)substr($period_b['start_date'], 0, 4);
    }
    if ($hos) {
        mysqli_close($hos);
    }
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'success',
        'action' => 'compare_slice',
        'year' => $slice_year,
        'compare_month' => $slice_month,
        'period' => $period_b,
        'comparison' => $comparison,
        'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// action=report (default)
$compare = !empty($_GET['compare']);
$compare_mode = isset($_GET['compare_mode']) ? trim($_GET['compare_mode']) : 'prev_year';
$compare_start = isset($_GET['compare_start']) ? trim($_GET['compare_start']) : '';
$compare_end = isset($_GET['compare_end']) ? trim($_GET['compare_end']) : '';
$compare_year = isset($_GET['compare_year']) ? (int)$_GET['compare_year'] : 0;
$compare_years_raw = isset($_GET['compare_years']) ? trim($_GET['compare_years']) : '';
$compare_months_raw = isset($_GET['compare_months']) ? trim($_GET['compare_months']) : '';

$months = rlu_month_keys_in_period($start_date, $end_date);
$missing = rlu_api_needs_cache($rlu_filter_opts) ? rlu_list_missing_months($con, $months) : [];

if (rlu_api_needs_cache($rlu_filter_opts) && !empty($missing)) {
    if ($hos) {
        mysqli_close($hos);
    }
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'need_build',
        'message' => 'ยังไม่มี cache ครบ — ต้องประมวลผลรายเดือนก่อน',
        'period' => $period,
        'missing_months' => $missing,
        'total_months' => count($months),
        'cached_count' => count($months) - count($missing),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!rlu_api_needs_cache($rlu_filter_opts) && !$hos) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (rlu_api_needs_cache($rlu_filter_opts) && $hos) {
    mysqli_close($hos);
    $hos = null;
}

$t0 = microtime(true);
$report = rlu_api_aggregate_period($con, $hos, $start_date, $end_date, $rlu_filter_opts);
$report['meta']['elapsed_ms'] = (int)round((microtime(true) - $t0) * 1000);

$comparison = null;
if ($compare) {
    if ($compare_mode === 'custom' && $compare_start !== '' && $compare_end !== '') {
        $period_b = rlu_parse_period($compare_start, $compare_end);
        $months_b = rlu_month_keys_in_period($period_b['start_date'], $period_b['end_date']);
        $missing_b = rlu_api_needs_cache($rlu_filter_opts)
            ? rlu_list_missing_months($con, $months_b)
            : [];
        if (rlu_api_needs_cache($rlu_filter_opts) && !empty($missing_b)) {
            ob_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status' => 'need_build',
                'message' => 'ยังไม่มี cache ช่วงเปรียบเทียบ — ต้องประมวลผลรายเดือนก่อน',
                'period' => $period,
                'compare_period' => $period_b,
                'missing_months' => $missing_b,
                'total_months' => count($months_b),
                'cached_count' => count($months_b) - count($missing_b),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $report_b = rlu_api_aggregate_period($con, $hos, $period_b['start_date'], $period_b['end_date'], $rlu_filter_opts);
        $comparison = rlu_build_comparison($report, $report_b, $period['label'], $period_b['label']);
        $comparison['period_b'] = $period_b;
    } elseif ($compare_mode === 'year') {
        $years_input = $compare_years_raw !== '' ? $compare_years_raw : ($compare_year > 0 ? (string)$compare_year : '');
        $compare_years = rlu_parse_compare_years($years_input, $start_date);
        $periods_b_data = [];
        $all_missing_b = [];
        foreach ($compare_years as $yr) {
            $period_b = rlu_shift_period_to_year($start_date, $end_date, $yr);
            $months_b = rlu_month_keys_in_period($period_b['start_date'], $period_b['end_date']);
            $missing_b = rlu_list_missing_months($con, $months_b);
            foreach ($missing_b as $ym) {
                if (!in_array($ym, $all_missing_b, true)) {
                    $all_missing_b[] = $ym;
                }
            }
            $periods_b_data[] = [
                'year' => $yr,
                'period' => $period_b,
            ];
        }
        if (rlu_api_needs_cache($rlu_filter_opts) && !empty($all_missing_b)) {
            ob_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status' => 'need_build',
                'message' => 'ยังไม่มี cache ช่วงเปรียบเทียบ — ต้องประมวลผลรายเดือนก่อน',
                'period' => $period,
                'compare_years' => $compare_years,
                'missing_months' => $all_missing_b,
                'total_months' => count($all_missing_b),
                'cached_count' => 0,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $built = [];
        foreach ($periods_b_data as $pb) {
            $report_b = rlu_api_aggregate_period($con, $hos, $pb['period']['start_date'], $pb['period']['end_date'], $rlu_filter_opts);
            $built[] = [
                'year' => $pb['year'],
                'report' => $report_b,
                'label' => $pb['period']['label'],
                'period' => $pb['period'],
            ];
        }
        $comparison = rlu_build_multi_year_comparison($report, $built, $period['label']);
        $comparison['compare_granularity'] = 'year';
    } elseif ($compare_mode === 'month') {
        $months_input = $compare_months_raw !== '' ? $compare_months_raw : '';
        $compare_months = rlu_parse_compare_months($months_input, $end_date);
        $periods_b_data = [];
        $all_missing_b = [];
        foreach ($compare_months as $cm) {
            $period_b = rlu_shift_period_to_month($start_date, $end_date, $cm);
            $months_b = rlu_month_keys_in_period($period_b['start_date'], $period_b['end_date']);
            $missing_b = rlu_list_missing_months($con, $months_b);
            foreach ($missing_b as $ym) {
                if (!in_array($ym, $all_missing_b, true)) {
                    $all_missing_b[] = $ym;
                }
            }
            $periods_b_data[] = [
                'year' => $cm,
                'period' => $period_b,
            ];
        }
        if (rlu_api_needs_cache($rlu_filter_opts) && !empty($all_missing_b)) {
            ob_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status' => 'need_build',
                'message' => 'ยังไม่มี cache ช่วงเปรียบเทียบ — ต้องประมวลผลรายเดือนก่อน',
                'period' => $period,
                'compare_months' => $compare_months,
                'missing_months' => $all_missing_b,
                'total_months' => count($all_missing_b),
                'cached_count' => 0,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $built = [];
        foreach ($periods_b_data as $pb) {
            $report_b = rlu_api_aggregate_period($con, $hos, $pb['period']['start_date'], $pb['period']['end_date'], $rlu_filter_opts);
            $built[] = [
                'year' => $pb['year'],
                'report' => $report_b,
                'label' => $pb['period']['label'],
                'period' => $pb['period'],
            ];
        }
        $comparison = rlu_build_multi_year_comparison($report, $built, $period['label']);
        $comparison['compare_granularity'] = 'month';
    } else {
        $period_b = rlu_shift_period_prev_year($start_date, $end_date);
        $months_b = rlu_month_keys_in_period($period_b['start_date'], $period_b['end_date']);
        $missing_b = rlu_api_needs_cache($rlu_filter_opts)
            ? rlu_list_missing_months($con, $months_b)
            : [];
        if (rlu_api_needs_cache($rlu_filter_opts) && !empty($missing_b)) {
            ob_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status' => 'need_build',
                'message' => 'ยังไม่มี cache ช่วงเปรียบเทียบ — ต้องประมวลผลรายเดือนก่อน',
                'period' => $period,
                'compare_period' => $period_b,
                'missing_months' => $missing_b,
                'total_months' => count($months_b),
                'cached_count' => count($months_b) - count($missing_b),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $report_b = rlu_api_aggregate_period($con, $hos, $period_b['start_date'], $period_b['end_date'], $rlu_filter_opts);
        $comparison = rlu_build_comparison($report, $report_b, $period['label'], $period_b['label']);
        $comparison['period_b'] = $period_b;
    }
}

if ($hos) {
    mysqli_close($hos);
}

ob_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'status' => 'success',
    'action' => 'report',
    'period' => $period,
    'rows' => $report['rows'],
    'summary' => $report['summary'],
    'meta' => $report['meta'],
    'comparison' => $comparison,
    'compare_enabled' => $compare,
], JSON_UNESCAPED_UNICODE);
