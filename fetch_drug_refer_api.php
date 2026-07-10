<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

set_time_limit(120);
include('connect.php');
require_once __DIR__ . '/drug_refer_helper.php';
require_once __DIR__ . '/hosxp_tmt_drug_helper.php';
mysqli_set_charset($con, 'utf8');
nd_ensure_tables($con);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_GET;
}

$action = trim($input['action'] ?? '');

$con_hosxp = hosxp_connect($con);
if (!$con_hosxp) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้']);
    exit;
}

if ($action === 'hospitals') {
    mysqli_close($con_hosxp);
    echo json_encode([
        'status' => 'success',
        'hospitals' => ndr_list_hospitals_grouped($con),
        'stats' => nd_global_stats($con),
    ]);
    exit;
}

$hn = trim($input['hn'] ?? '');
if ($hn === '' && !in_array($action, ['hospitals'], true)) {
    mysqli_close($con_hosxp);
    echo json_encode(['status' => 'error', 'message' => 'กรุณาระบุ HN']);
    exit;
}

if ($action === 'visits') {
    $months = (int)($input['months'] ?? 36);
    $patient = ndr_fetch_patient($con_hosxp, $hn);
    if (!$patient) {
        mysqli_close($con_hosxp);
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบผู้ป่วย HN นี้ใน HOSxP']);
        exit;
    }
    $visits = ndr_fetch_visits($con_hosxp, $hn, $months);
    $amp_name = trim($patient['amp_name'] ?? '');
    $suggested_hospital = $amp_name !== '' ? ndr_resolve_hospital_display_for_amp_name($con, $amp_name) : '';
    $patient['hn'] = ndr_canonical_hn($patient['hn'] ?? $hn);
    mysqli_close($con_hosxp);
    echo json_encode([
        'status' => 'success',
        'patient' => $patient,
        'visits' => $visits,
        'amp_name' => $amp_name,
        'suggested_hospital' => $suggested_hospital,
    ]);
    exit;
}

if ($action === 'drugs') {
    $vn = trim($input['vn'] ?? '');
    $an = trim($input['an'] ?? '');
    $visit_type = trim($input['visit_type'] ?? '');
    $drugs = ndr_fetch_visit_drugs($con_hosxp, $vn, $an, $visit_type);
    mysqli_close($con_hosxp);
    echo json_encode([
        'status' => 'success',
        'drugs' => $drugs,
        'filter_note' => ($an !== '' || $visit_type === 'ipd') ? 'เฉพาะรายการประเภท HME (ยากลับบ้าน)' : 'รายการยาทั้งหมดใน Visit',
    ]);
    exit;
}

if ($action === 'compare') {
    $vn = trim($input['vn'] ?? '');
    $an = trim($input['an'] ?? '');
    $visit_type = trim($input['visit_type'] ?? '');
    $hospitals = $input['hospitals'] ?? [];
    if (!is_array($hospitals)) {
        $hospitals = [];
    }

    $patient = ndr_fetch_patient($con_hosxp, $hn);
    $drugs = ndr_fetch_visit_drugs($con_hosxp, $vn, $an, $visit_type);
    $rights = ndr_fetch_visit_pttype($con_hosxp, $vn, $an);
    $visit_label = $an !== '' ? ('AN: ' . $an) : ('VN: ' . $vn);
    mysqli_close($con_hosxp);

    $result = ndr_compare_drugs($con, $drugs, $hospitals);
    if (($result['status'] ?? '') !== 'success') {
        if (empty($drugs) && ($an !== '' || strtolower($visit_type) === 'ipd')) {
            $result['message'] = 'ไม่พบรายการยา HME (ยากลับบ้าน) ใน Visit IPD นี้ — ลองเลือก Visit OPD หรือ IPD ที่มียา HME';
        }
        echo json_encode($result);
        exit;
    }

    $payload = [
        'patient' => $patient,
        'visit' => [
            'hn' => $hn,
            'vn' => $vn,
            'an' => $an,
            'visit_type' => $visit_type,
            'visit_label' => $visit_label,
            'filter_note' => ($an !== '' || $visit_type === 'ipd') ? 'เฉพาะ HME' : 'OPD ทั้ง Visit',
            'pttype' => $rights['pttype'],
            'pttype_name' => $rights['pttype_name'],
        ],
        'hospitals' => $result['hospitals'],
        'rows' => $result['rows'],
        'summary' => $result['summary'],
        'printed_by' => trim($_SESSION['full_name'] ?? ''),
        'printed_at' => date('Y-m-d H:i:s'),
    ];
    ndr_store_print_session($payload);

    echo json_encode(array_merge(['status' => 'success'], $payload));
    exit;
}

mysqli_close($con_hosxp);
echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
exit;
