<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

set_time_limit(600);
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
$preset = trim($input['preset'] ?? 'today');
$start_date = trim($input['start_date'] ?? '');
$end_date = trim($input['end_date'] ?? '');
$chwpart = trim($input['chwpart'] ?? '');
$visit_type = ndr_district_normalize_visit_type($input['visit_type'] ?? 'all');

$con_hosxp = hosxp_connect($con, 600);
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

$hospital_chwpart = ndr_fetch_hospital_chwpart($con_hosxp);
if ($chwpart === '') {
    $chwpart = $hospital_chwpart;
}

$period = ndr_district_resolve_period($preset, $start_date, $end_date);
$t0 = microtime(true);

if ($action === 'meta') {
    $hospital_chw_name = ndr_fetch_hospital_chw_name($con_hosxp, $hospital_chwpart);
    $pttype_options = ndr_fetch_pttype_filter_options($con_hosxp);
    $default_pttypes = ndr_default_pttype_filter_codes($con_hosxp);
    $inside_pttypes = ndr_default_pttype_inside_filter_codes($con_hosxp);
    mysqli_close($con_hosxp);
    echo json_encode([
        'status' => 'success',
        'hospital_chwpart' => $hospital_chwpart,
        'hospital_chw_name' => $hospital_chw_name,
        'period' => $period,
        'pttype_options' => $pttype_options,
        'default_pttypes' => $default_pttypes,
        'inside_pttypes' => $inside_pttypes,
        'pttype_filter' => $pttype_filter,
    ]);
    exit;
}

if ($action === 'districts') {
    $districts = ndr_fetch_district_summary($con_hosxp, $period['start_date'], $period['end_date'], $chwpart, $visit_type, $pttype_codes);
    if (isset($districts['_error'])) {
        mysqli_close($con_hosxp);
        echo json_encode(['status' => 'error', 'message' => 'ดึงข้อมูลไม่สำเร็จ: ' . $districts['_error']]);
        exit;
    }
    $total_patients = 0;
    foreach ($districts as $d) {
        $total_patients += (int)($d['patient_count'] ?? 0);
    }
    $chw_name = ndr_fetch_hospital_chw_name($con_hosxp, $chwpart);
    mysqli_close($con_hosxp);
    echo json_encode([
        'status' => 'success',
        'period' => $period,
        'visit_type' => $visit_type,
        'visit_type_label' => ndr_district_visit_type_label($visit_type),
        'pttype_filter' => $pttype_filter,
        'chwpart' => $chwpart,
        'chw_name' => $chw_name,
        'districts' => $districts,
        'total_districts' => count($districts),
        'total_patients' => $total_patients,
        'elapsed_sec' => round(microtime(true) - $t0, 2),
    ]);
    exit;
}

if ($action === 'patients') {
    $amppart = trim($input['amppart'] ?? '');
    $district_key = trim($input['district_key'] ?? '');
    if ($district_key !== '' && strpos($district_key, ':') !== false) {
        [$chwpart, $amppart] = explode(':', $district_key, 2);
    }
    $page = (int)($input['page'] ?? 1);
    $per_page = (int)($input['per_page'] ?? 50);
    $export_all = !empty($input['all']);
    $sort = trim($input['sort'] ?? 'last_order_desc');
    if (!preg_match('/^(hn|full_name|tmb_name|address|pttype_name|visit_count|last_order|match_percent)_(asc|desc)$/', $sort)) {
        $sort = 'last_order_desc';
    }
    $sort_parsed = ndr_district_parse_patient_sort($sort);
    $sort_by_match = ($sort_parsed['col'] === 'match_percent');
    if ($export_all) {
        $per_page = 0;
        $page = 1;
    }

    if ($chwpart === '' || $amppart === '') {
        mysqli_close($con_hosxp);
        echo json_encode(['status' => 'error', 'message' => 'กรุณาเลือกอำเภอ']);
        exit;
    }

    $all_amp = ndr_district_is_all_amppart($amppart);
    if ($all_amp) {
        $amp_name = 'ทุกอำเภอ';
    } else {
        $amp_names = ndr_district_lookup_amp_names($con_hosxp, [$chwpart . ':' . $amppart]);
        $amp_name = $amp_names[$chwpart . ':' . $amppart] ?? 'ไม่ระบุอำเภอ';
    }
    $fetch_page = ($sort_by_match || $export_all) ? 1 : $page;
    $fetch_per_page = ($sort_by_match || $export_all) ? 0 : $per_page;
    $fetch_sort = $sort_by_match ? 'last_order_desc' : $sort;
    $result = ndr_fetch_district_patients(
        $con_hosxp,
        $chwpart,
        $amppart,
        $period['start_date'],
        $period['end_date'],
        $fetch_page,
        $fetch_per_page,
        $visit_type,
        $pttype_codes,
        $fetch_sort
    );
    if ($all_amp) {
        ndr_enrich_patients_district_match_by_home_amp(
            $con,
            $con_hosxp,
            $result['patients'],
            $period['start_date'],
            $period['end_date'],
            $visit_type,
            90
        );
    } else {
        ndr_enrich_patients_district_match(
            $con,
            $con_hosxp,
            $result['patients'],
            $amp_name,
            $period['start_date'],
            $period['end_date'],
            $visit_type,
            90
        );
    }
    if ($sort_by_match) {
        ndr_district_sort_patients($result['patients'], $sort);
    }
    if ($sort_by_match || $export_all) {
        $total = count($result['patients']);
        if (!$export_all) {
            $per_page = max(10, min(200, (int)$per_page));
            $offset = ($page - 1) * $per_page;
            $result['patients'] = array_slice($result['patients'], $offset, $per_page);
            $result['page'] = $page;
            $result['per_page'] = $per_page;
            $result['pages'] = $total > 0 ? (int)ceil($total / $per_page) : 1;
        } else {
            $result['page'] = 1;
            $result['per_page'] = $total;
            $result['pages'] = 1;
        }
        $result['total'] = $total;
        $result['sort'] = $sort;
    }
    mysqli_close($con_hosxp);

    echo json_encode([
        'status' => 'success',
        'period' => $period,
        'visit_type' => $visit_type,
        'visit_type_label' => ndr_district_visit_type_label($visit_type),
        'pttype_filter' => $pttype_filter,
        'district' => [
            'district_key' => $chwpart . ':' . $amppart,
            'chwpart' => $chwpart,
            'amppart' => $amppart,
            'amp_name' => $amp_name,
            'match_hospital' => $all_amp ? '' : ndr_resolve_hospital_for_amp_name($con, $amp_name),
        ],
        'patients' => $result['patients'],
        'total' => $result['total'],
        'page' => $result['page'],
        'per_page' => $result['per_page'],
        'pages' => $result['pages'],
        'sort' => $result['sort'] ?? $sort,
        'elapsed_sec' => round(microtime(true) - $t0, 2),
    ]);
    exit;
}

if ($action === 'recommendations') {
    $amppart = trim($input['amppart'] ?? '');
    $district_key = trim($input['district_key'] ?? '');
    if ($district_key !== '' && strpos($district_key, ':') !== false) {
        [$chwpart, $amppart] = explode(':', $district_key, 2);
    }
    if ($chwpart === '' || $amppart === '') {
        mysqli_close($con_hosxp);
        echo json_encode(['status' => 'error', 'message' => 'กรุณาเลือกอำเภอ']);
        exit;
    }
    if (ndr_district_is_all_amppart($amppart)) {
        mysqli_close($con_hosxp);
        echo json_encode([
            'status' => 'success',
            'period' => $period,
            'visit_type' => $visit_type,
            'visit_type_label' => ndr_district_visit_type_label($visit_type),
            'pttype_filter' => $pttype_filter,
            'district' => [
                'district_key' => $chwpart . ':all',
                'amp_name' => 'ทุกอำเภอ',
                'match_hospital' => '',
            ],
            'hospital' => '',
            'patients_total' => 0,
            'patients_below_threshold' => 0,
            'items' => [],
            'note' => 'เลือกอำเภอเพื่อดูรายการแนะนำเฉพาะ รพ.',
            'elapsed_sec' => round(microtime(true) - $t0, 2),
        ]);
        exit;
    }
    $amp_names = ndr_district_lookup_amp_names($con_hosxp, [$chwpart . ':' . $amppart]);
    $amp_name = $amp_names[$chwpart . ':' . $amppart] ?? 'ไม่ระบุอำเภอ';
    $rec = ndr_fetch_district_drug_recommendations(
        $con,
        $con_hosxp,
        $chwpart,
        $amppart,
        $amp_name,
        $period['start_date'],
        $period['end_date'],
        $visit_type,
        $pttype_codes,
        90,
        90
    );
    mysqli_close($con_hosxp);
    echo json_encode(array_merge($rec, [
        'period' => $period,
        'visit_type' => $visit_type,
        'visit_type_label' => ndr_district_visit_type_label($visit_type),
        'pttype_filter' => $pttype_filter,
        'district' => [
            'district_key' => $chwpart . ':' . $amppart,
            'amp_name' => $amp_name,
            'match_hospital' => $rec['hospital'] ?? '',
        ],
        'elapsed_sec' => round(microtime(true) - $t0, 2),
    ]));
    exit;
}

if ($action === 'match_detail') {
    $hn = trim($input['hn'] ?? '');
    $amppart = trim($input['amppart'] ?? '');
    $district_key = trim($input['district_key'] ?? '');
    if ($district_key !== '' && strpos($district_key, ':') !== false) {
        [$chwpart, $amppart] = explode(':', $district_key, 2);
    }
    if ($chwpart === '' || $amppart === '') {
        mysqli_close($con_hosxp);
        echo json_encode(['status' => 'error', 'message' => 'กรุณาเลือกอำเภอ']);
        exit;
    }
    $amp_name_input = trim($input['amp_name'] ?? '');
    if ($amp_name_input !== '') {
        $amp_name = $amp_name_input;
    } elseif (ndr_district_is_all_amppart($amppart)) {
        mysqli_close($con_hosxp);
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูลอำเภอของผู้ป่วย']);
        exit;
    } else {
        $amp_names = ndr_district_lookup_amp_names($con_hosxp, [$chwpart . ':' . $amppart]);
        $amp_name = $amp_names[$chwpart . ':' . $amppart] ?? 'ไม่ระบุอำเภอ';
    }
    $detail = ndr_fetch_district_patient_match_detail(
        $con,
        $con_hosxp,
        $hn,
        $amp_name,
        $period['start_date'],
        $period['end_date'],
        $visit_type,
        90
    );
    mysqli_close($con_hosxp);
    if (($detail['status'] ?? '') !== 'success') {
        echo json_encode($detail);
        exit;
    }
    echo json_encode(array_merge($detail, [
        'period' => $period,
        'pttype_filter' => $pttype_filter,
        'elapsed_sec' => round(microtime(true) - $t0, 2),
    ]));
    exit;
}

mysqli_close($con_hosxp);
echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
exit;
