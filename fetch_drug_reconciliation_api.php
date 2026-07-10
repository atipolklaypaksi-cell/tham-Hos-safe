<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

set_time_limit(120);
include('connect.php');
include('hosxp_tmt_drug_helper.php');
include('drug_reconciliation_helper.php');
mysqli_set_charset($con, 'utf8');
dr_ensure_tables($con);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_GET;
}

$action = trim($input['action'] ?? 'load');
$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_name = trim($_SESSION['full_name'] ?? $_SESSION['username'] ?? '');

if ($action === 'load') {
    $hn = trim($input['hn'] ?? '');
    $an = trim($input['an'] ?? '');
    $vn = trim($input['vn'] ?? '');

    if ($hn === '' && $an === '' && $vn === '') {
        echo json_encode(['status' => 'error', 'message' => 'กรุณาระบุ HN, AN หรือ VN']);
        exit;
    }

    $con_hosxp = hosxp_connect($con);
    if (!$con_hosxp) {
        echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้']);
        exit;
    }

    if ($hn === '' && $an !== '') {
        $an_esc = mysqli_real_escape_string($con_hosxp, $an);
        $qh = mysqli_query($con_hosxp, "SELECT hn FROM ipt WHERE an='$an_esc' LIMIT 1");
        if ($qh && ($hr = mysqli_fetch_assoc($qh))) {
            $hn = $hr['hn'];
        }
    }
    if ($hn === '' && $vn !== '') {
        $vn_esc = mysqli_real_escape_string($con_hosxp, $vn);
        $qv = mysqli_query($con_hosxp, "SELECT hn FROM ovst WHERE vn='$vn_esc' LIMIT 1");
        if ($qv && ($vr = mysqli_fetch_assoc($qv))) {
            $hn = $vr['hn'];
        }
    }

    $ctx = dr_fetch_patient_context($con_hosxp, $hn, $an, $vn);
    if (!$ctx) {
        mysqli_close($con_hosxp);
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูลผู้ป่วยใน HOSxP']);
        exit;
    }

    $baseline = dr_fetch_baseline_drugs($con_hosxp, $ctx);
    $current = dr_fetch_current_drugs($con_hosxp, $ctx);
    $comparison = dr_build_comparison($baseline, $current);
    mysqli_close($con_hosxp);

    $sessions = dr_list_sessions($con, $ctx['hn'], 15);

    echo json_encode([
        'status' => 'success',
        'context' => $ctx,
        'comparison' => $comparison,
        'sessions' => $sessions,
    ]);
    exit;
}

if ($action === 'get_session') {
    $session_id = (int)($input['session_id'] ?? 0);
    $session = dr_load_session($con, $session_id);
    if (!$session) {
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบรายการ']);
        exit;
    }
    $pairs = dr_pairs_from_session_items($session['items']['baseline'], $session['items']['current']);
    echo json_encode([
        'status' => 'success',
        'session' => $session,
        'pairs' => $pairs,
        'context' => [
            'hn' => $session['hn'],
            'an' => $session['an'],
            'vn' => $session['vn'],
            'visit_type' => $session['visit_type'],
            'patient_name' => $session['patient_name'],
            'doctor_code' => $session['doctor_code'],
            'doctor_name' => $session['doctor_name'],
            'ward_name' => $session['ward_name'],
        ],
    ]);
    exit;
}

if ($action === 'save' || $action === 'send') {
    $status = ($action === 'send') ? 'sent' : 'draft';
    $payload = $input;
    $payload['status'] = $status;
    $result = dr_save_session($con, $payload, $user_id, $user_name);
    if (!$result['ok']) {
        echo json_encode(['status' => 'error', 'message' => $result['error'] ?? 'บันทึกไม่สำเร็จ']);
        exit;
    }
    echo json_encode([
        'status' => 'success',
        'message' => $status === 'sent' ? 'ส่งรายการให้แพทย์เรียบร้อย' : 'บันทึกแบบร่างเรียบร้อย',
        'session_id' => $result['session_id'],
        'print_url' => 'drug_reconciliation_print.php?id=' . (int)$result['session_id'],
    ]);
    exit;
}

if ($action === 'list') {
    $hn = trim($input['hn'] ?? '');
    $sessions = dr_list_sessions($con, $hn, (int)($input['limit'] ?? 20));
    echo json_encode(['status' => 'success', 'sessions' => $sessions]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
