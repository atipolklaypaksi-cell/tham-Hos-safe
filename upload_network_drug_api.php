<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

set_time_limit(300);
include('connect.php');
require_once __DIR__ . '/network_drug_helper.php';
mysqli_set_charset($con, 'utf8');
nd_ensure_tables($con);

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_name = trim($_SESSION['full_name'] ?? $_SESSION['username'] ?? '');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$action = trim($input['action'] ?? $_GET['action'] ?? '');

if ($action === 'list_hospitals') {
    echo json_encode([
        'status' => 'success',
        'hospitals' => nd_list_hospitals_with_stats($con),
        'stats' => nd_global_stats($con),
    ]);
    exit;
}

if ($action === 'delete_hospital') {
    $hosname = trim($input['hosname'] ?? '');
    echo json_encode(nd_delete_hospital($con, $hosname));
    exit;
}

if ($action === 'import_rows') {
    $rows_payload = $input['rows'] ?? [];
    $filename = trim($input['filename'] ?? 'upload.xlsx');
    $default_hosname = trim($input['default_hosname'] ?? '');
    $parsed = nd_parse_rows_payload($rows_payload);
    if (($parsed['status'] ?? '') !== 'success') {
        echo json_encode($parsed);
        exit;
    }
    $result = nd_import_rows($con, $parsed['rows'], $filename, $user_id, $user_name, $default_hosname);
    echo json_encode($result);
    exit;
}

if ($action === 'preview_rows') {
    $rows_payload = $input['rows'] ?? [];
    $parsed = nd_parse_rows_payload($rows_payload);
    if (($parsed['status'] ?? '') !== 'success') {
        echo json_encode($parsed);
        exit;
    }
    $analyze = nd_analyze_rows($parsed['rows']);
    $existing = nd_list_hospitals($con, 0);
    $will_affect = $analyze['hospitals_in_file'];
    if ($analyze['needs_hosname']) {
        // ยังไม่รู้ชื่อ รพ. ที่จะใช้กับแถวว่าง — client จะส่ง default_hosname ตอน import
    }
    $preview = array_slice($parsed['rows'], 0, 10);
    echo json_encode([
        'status' => 'success',
        'total' => count($parsed['rows']),
        'preview' => $preview,
        'analyze' => $analyze,
        'existing_hospitals' => $existing,
        'will_overwrite' => array_values(array_intersect($existing, $will_affect)),
        'will_add_new' => array_values(array_diff($will_affect, $existing)),
    ]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
exit;
