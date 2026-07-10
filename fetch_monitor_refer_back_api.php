<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}
session_write_close();

set_time_limit(300);
include('connect.php');
require_once __DIR__ . '/monitor_refer_back_helper.php';
require_once __DIR__ . '/hosxp_tmt_drug_helper.php';
mysqli_set_charset($con, 'utf8');
nd_ensure_tables($con);
mrb_ensure_tables($con);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_GET;
}
$action = trim($input['action'] ?? $_GET['action'] ?? 'list');
$t0 = microtime(true);

$hosxp_actions = ['list', 'match_detail', 'opd_departments'];
$settings_actions = ['settings', 'exclude_drugs', 'save_exclude_drugs', 'substitute_drugs', 'save_substitute_drugs', 'search_network_tmtdrug'];
$con_hosxp = null;
if (in_array($action, $hosxp_actions, true)) {
    $con_hosxp = hosxp_connect($con, 120);
    if (!$con_hosxp) {
        echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้']);
        exit;
    }
    mysqli_set_charset($con_hosxp, 'utf8');
} elseif (in_array($action, $settings_actions, true)) {
    $con_hosxp = hosxp_connect($con, 10);
    if ($con_hosxp) {
        mysqli_set_charset($con_hosxp, 'utf8');
    }
}

if ($action === 'exclude_drugs') {
    echo json_encode([
        'status' => 'success',
        'items' => mrb_get_exclude_drugs($con),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'save_exclude_drugs' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    mrb_save_exclude_drugs($con, is_array($input['items'] ?? null) ? $input['items'] : []);
    echo json_encode(['status' => 'success', 'message' => 'บันทึกรายการตัดยาเรียบร้อย'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'substitute_drugs') {
    echo json_encode([
        'status' => 'success',
        'pairs' => mrb_get_substitute_pairs($con),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'search_network_tmtdrug') {
    $q = trim($input['q'] ?? $_GET['q'] ?? '');
    echo json_encode([
        'results' => nd_search_tmtdrugs($con, $q, 30),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'save_substitute_drugs' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    mrb_save_substitute_pairs(
        $con,
        $con_hosxp,
        is_array($input['pairs'] ?? null) ? $input['pairs'] : []
    );
    if ($con_hosxp) {
        mysqli_close($con_hosxp);
    }
    echo json_encode(['status' => 'success', 'message' => 'บันทึกยาทดแทนได้เรียบร้อย'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'settings') {
    $patient_type = mrb_normalize_patient_type($input['patient_type'] ?? 'OPD');
    $settings = mrb_get_settings($con, $patient_type);
    $pttype_options = [];
    $default_pttypes = [];
    $inside_pttypes = [];
    $effective_pttypes = [];
    if ($con_hosxp) {
        $pttype_options = ndr_fetch_pttype_filter_options($con_hosxp);
        $default_pttypes = ndr_default_pttype_filter_codes($con_hosxp);
        $inside_pttypes = ndr_default_pttype_inside_filter_codes($con_hosxp);
        $effective_pttypes = mrb_resolve_pttype_codes($con_hosxp, $settings);
        if (($settings['pttype_mode'] ?? '') === 'all') {
            $effective_pttypes = array_values(array_filter(array_map(function ($opt) {
                return trim($opt['pttype'] ?? '');
            }, $pttype_options)));
        }
        mysqli_close($con_hosxp);
    }
    echo json_encode([
        'status' => 'success',
        'settings' => $settings,
        'pttype_options' => $pttype_options,
        'default_pttypes' => $default_pttypes,
        'inside_pttypes' => $inside_pttypes,
        'effective_pttypes' => $effective_pttypes,
        'pttype_filter_label' => mrb_pttype_filter_label($settings, $effective_pttypes),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'save_settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $patient_type = mrb_normalize_patient_type($input['patient_type'] ?? 'OPD');
    mrb_save_settings($con, $patient_type, $input, $_SESSION['user_id'] ?? 0);
    if ($con_hosxp) {
        mysqli_close($con_hosxp);
    }
    echo json_encode(['status' => 'success', 'message' => 'บันทึกการตั้งค่าเรียบร้อย'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'list') {
    $patient_type = mrb_normalize_patient_type($input['patient_type'] ?? $_GET['patient_type'] ?? 'OPD');
    $screen_date = trim($input['screen_date'] ?? $_GET['screen_date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $screen_date)) {
        $screen_date = date('Y-m-d');
    }
    $dept_codes = [];
    if (isset($input['dept_codes']) && is_array($input['dept_codes'])) {
        $dept_codes = $input['dept_codes'];
    } elseif (!empty($input['dept_code'])) {
        $dept_codes = [$input['dept_code']];
    }
    $dept_codes = mrb_normalize_opd_dept_codes($dept_codes);
    $result = mrb_screen_list(
        $con,
        $con_hosxp,
        $patient_type,
        $screen_date,
        trim($input['time_window'] ?? $_GET['time_window'] ?? 'full'),
        $dept_codes
    );
    mysqli_close($con_hosxp);
    echo json_encode(array_merge([
        'status' => 'success',
        'elapsed_sec' => round(microtime(true) - $t0, 2),
        'server_time' => function_exists('sp_now_datetime') ? sp_now_datetime()->format('Y-m-d H:i:s') : date('Y-m-d H:i:s'),
    ], $result), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'opd_departments') {
    $screen_date = trim($input['screen_date'] ?? $_GET['screen_date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $screen_date)) {
        $screen_date = date('Y-m-d');
    }
    echo json_encode([
        'status' => 'success',
        'departments' => mrb_fetch_opd_departments($con_hosxp, $screen_date),
    ], JSON_UNESCAPED_UNICODE);
    mysqli_close($con_hosxp);
    exit;
}

if ($action === 'match_detail') {
    $hn = trim($input['hn'] ?? '');
    $amp_name = trim($input['amp_name'] ?? '');
    $vn = trim($input['vn'] ?? '');
    $an = trim($input['an'] ?? '');
    $ref_visit_type = trim($input['ref_visit_type'] ?? 'opd');
    $settings = mrb_get_settings($con, mrb_normalize_patient_type($input['patient_type'] ?? 'OPD'));
    $min_similar = (int)($settings['min_similar_score'] ?? 90);
    $detail = mrb_fetch_match_detail(
        $con,
        $con_hosxp,
        $hn,
        $amp_name,
        $vn,
        $an,
        $ref_visit_type,
        (int)($settings['min_similar_score'] ?? 90),
        trim($input['ref_visit_label'] ?? ''),
        array_key_exists('ref_hme_only', $input) ? !empty($input['ref_hme_only']) : null,
        mrb_exclude_drug_icodes($con, mrb_normalize_patient_type($input['patient_type'] ?? 'OPD')),
        trim($input['hme_order_no'] ?? ''),
        trim($input['hme_rxdate'] ?? ''),
        trim($input['hme_rxtime'] ?? '')
    );
    mysqli_close($con_hosxp);
    if (($detail['status'] ?? '') !== 'success') {
        echo json_encode($detail, JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(array_merge($detail, [
        'case_id' => (int)($input['case_id'] ?? 0),
        'elapsed_sec' => round(microtime(true) - $t0, 2),
    ]), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'mark_printed' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = mrb_mark_printed($con, (int)($input['case_id'] ?? 0), $_SESSION['user_id'] ?? 0, $_SESSION['full_name'] ?? '');
    if ($con_hosxp) {
        mysqli_close($con_hosxp);
    }
    echo json_encode([
        'status' => !empty($result['ok']) ? 'success' : 'error',
        'message' => $result['message'] ?? '',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'save_intervention' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = mrb_save_intervention(
        $con,
        (int)($input['case_id'] ?? 0),
        $input['intervention_status'] ?? 'not_done',
        $input['intervention_note'] ?? '',
        $_SESSION['user_id'] ?? 0,
        $_SESSION['full_name'] ?? ''
    );
    if ($con_hosxp) {
        mysqli_close($con_hosxp);
    }
    echo json_encode([
        'status' => !empty($result['ok']) ? 'success' : 'error',
        'message' => $result['message'] ?? '',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'intervention_logs') {
    $start = trim($input['start_date'] ?? $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days')));
    $end = trim($input['end_date'] ?? $_GET['end_date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
        $start = date('Y-m-d', strtotime('-30 days'));
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
        $end = date('Y-m-d');
    }
    $patient_type = trim($input['patient_type'] ?? $_GET['patient_type'] ?? '');
    $status_filter = trim($input['status_filter'] ?? $_GET['status_filter'] ?? '');
    $logs = mrb_list_cases($con, $start, $end, $patient_type, $status_filter);
    $stats = mrb_intervention_stats($con, $start, $end);
    if ($con_hosxp) {
        mysqli_close($con_hosxp);
    }
    echo json_encode([
        'status' => 'success',
        'logs' => $logs,
        'stats' => $stats,
        'elapsed_sec' => round(microtime(true) - $t0, 2),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($con_hosxp) {
    mysqli_close($con_hosxp);
}
echo json_encode(['status' => 'error', 'message' => 'Unknown action'], JSON_UNESCAPED_UNICODE);
exit;
