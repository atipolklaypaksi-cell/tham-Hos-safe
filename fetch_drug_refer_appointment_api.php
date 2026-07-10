<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

set_time_limit(300);
include('connect.php');
require_once __DIR__ . '/drug_refer_helper.php';
require_once __DIR__ . '/drug_refer_appointment_helper.php';
require_once __DIR__ . '/hosxp_tmt_drug_helper.php';
mysqli_set_charset($con, 'utf8');
nd_ensure_tables($con);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_GET;
}

$action = trim($input['action'] ?? '');
$appt_date = trim($input['appt_date'] ?? '');
$visit_type = ndr_appt_normalize_visit_type($input['visit_type'] ?? 'opd');

$con_hosxp = hosxp_connect($con, 300);
if (!$con_hosxp) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้']);
    exit;
}
mysqli_set_charset($con_hosxp, 'utf8');

$pttype_filter_raw = $input['pttype_filter'] ?? 'default';
if ($action === 'meta' && !isset($input['pttype_filter'])) {
    $pttype_filter_raw = 'default';
}
$pttype_filter = ndr_resolve_pttype_filter($con_hosxp, $pttype_filter_raw);
$pttype_codes = $pttype_filter['codes'];
$period = ndr_appt_resolve_date($appt_date);
$t0 = microtime(true);

if ($action === 'meta') {
    $pttype_options = ndr_fetch_pttype_filter_options($con_hosxp);
    mysqli_close($con_hosxp);
    echo json_encode([
        'status' => 'success',
        'appt_date' => $period['appt_date'],
        'appt_date_label' => $period['label'],
        'default_appt_date' => date('Y-m-d', strtotime('+1 day')),
        'pttype_options' => $pttype_options,
        'default_pttypes' => ndr_default_pttype_filter_codes($con_hosxp),
        'inside_pttypes' => ndr_default_pttype_inside_filter_codes($con_hosxp),
        'pttype_filter' => $pttype_filter,
        'visit_type' => $visit_type,
        'visit_type_label' => ndr_appt_visit_type_label($visit_type),
    ]);
    exit;
}

if ($action === 'groups') {
    $groups = ndr_appt_fetch_groups($con_hosxp, $period['appt_date'], $visit_type, $pttype_codes);
    if (isset($groups['_error'])) {
        mysqli_close($con_hosxp);
        echo json_encode(['status' => 'error', 'message' => 'ดึงข้อมูลไม่สำเร็จ: ' . $groups['_error']]);
        exit;
    }
    $total = 0;
    foreach ($groups as $g) {
        $total += (int)($g['patient_count'] ?? 0);
    }
    mysqli_close($con_hosxp);
    echo json_encode([
        'status' => 'success',
        'appt_date' => $period['appt_date'],
        'appt_date_label' => $period['label'],
        'visit_type' => $visit_type,
        'visit_type_label' => ndr_appt_visit_type_label($visit_type),
        'pttype_filter' => $pttype_filter,
        'groups' => $groups,
        'total_groups' => count($groups),
        'total_patients' => $total,
        'elapsed_sec' => round(microtime(true) - $t0, 2),
    ]);
    exit;
}

if ($action === 'patients') {
    $group_key = trim($input['group_key'] ?? '');
    $clinic_code = trim($input['clinic_code'] ?? '');
    $room_code = trim($input['room_code'] ?? '');
    if ($group_key !== '' && strpos($group_key, ':') !== false) {
        [$clinic_code, $room_code] = explode(':', $group_key, 2);
    }

    $all_groups = ($group_key === ':' || ($clinic_code === '' && $room_code === '' && $group_key === ''));

    $patients = ndr_appt_fetch_patients(
        $con_hosxp,
        $period['appt_date'],
        $clinic_code,
        $room_code,
        $visit_type,
        $pttype_codes,
        $all_groups
    );
    if (isset($patients['_error'])) {
        mysqli_close($con_hosxp);
        echo json_encode(['status' => 'error', 'message' => 'ดึงข้อมูลไม่สำเร็จ: ' . $patients['_error']]);
        exit;
    }

    ndr_appt_enrich_patients_match($con, $con_hosxp, $patients, 90);
    mysqli_close($con_hosxp);

    echo json_encode([
        'status' => 'success',
        'appt_date' => $period['appt_date'],
        'appt_date_label' => $period['label'],
        'visit_type' => $visit_type,
        'visit_type_label' => ndr_appt_visit_type_label($visit_type),
        'pttype_filter' => $pttype_filter,
        'group' => [
            'group_key' => ndr_appt_group_key($clinic_code, $room_code),
            'clinic_code' => $clinic_code,
            'room_code' => $room_code,
        ],
        'patients' => $patients,
        'total' => count($patients),
        'elapsed_sec' => round(microtime(true) - $t0, 2),
    ]);
    exit;
}

if ($action === 'match_detail') {
    $hn = trim($input['hn'] ?? '');
    $amp_name = trim($input['amp_name'] ?? '');
    $vn = trim($input['vn'] ?? '');
    $an = trim($input['an'] ?? '');
    $ref_visit_type = trim($input['ref_visit_type'] ?? $visit_type);

    $detail = ndr_fetch_appointment_patient_match_detail(
        $con,
        $con_hosxp,
        $hn,
        $amp_name,
        $vn,
        $an,
        $ref_visit_type,
        90
    );
    mysqli_close($con_hosxp);
    if (($detail['status'] ?? '') !== 'success') {
        echo json_encode($detail);
        exit;
    }
    echo json_encode(array_merge($detail, [
        'appt_date' => $period['appt_date'],
        'appt_date_label' => $period['label'],
        'pttype_filter' => $pttype_filter,
        'elapsed_sec' => round(microtime(true) - $t0, 2),
    ]));
    exit;
}

mysqli_close($con_hosxp);
echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
exit;
