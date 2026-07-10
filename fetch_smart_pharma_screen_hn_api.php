<?php
/**
 * Smart Pharma — คัดกรองรายคนตาม HN (สำหรับ HOSxP / ระบบภายนอก)
 *
 * GET/POST parameters:
 *   key          — API Key (ตั้งใน Smart Pharma → คัดกรองตาม HN)
 *   hn           — HN ผู้ป่วย (บังคับ)
 *   patient_type — OPD | IPD (default OPD)
 *   screen_date  — วันที่คัดกรอง YYYY-MM-DD (default วันนี้)
 *   vn           — VN (OPD, ถ้ามี)
 *   an           — AN (IPD, ถ้ามี)
 *   notify       — 1|0 ส่ง Telegram/Webhook (default 1)
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

include(__DIR__ . '/connect.php');
include(__DIR__ . '/smart_pharma_helper.php');
mysqli_set_charset($con, 'utf8');
set_time_limit(120);
ini_set('memory_limit', '256M');
sp_ensure_tables($con);

$key = trim((string)($_GET['key'] ?? $_POST['key'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? '')));
if (!sp_screen_api_verify($con, $key)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden — API Key ไม่ถูกต้องหรือยังไม่ได้ตั้ง'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = array_merge($_GET, $_POST);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json = json_decode(file_get_contents('php://input'), true);
    if (is_array($json)) {
        $input = array_merge($input, $json);
    }
}

$patient_type = ($input['patient_type'] ?? 'OPD') === 'IPD' ? 'IPD' : 'OPD';
$screen_date = trim((string)($input['screen_date'] ?? $input['vstdate'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $screen_date)) {
    $screen_date = date('Y-m-d');
}
$hn = trim((string)($input['hn'] ?? ''));
$vn = trim((string)($input['vn'] ?? ''));
$an = trim((string)($input['an'] ?? ''));
$notify = !isset($input['notify']) || $input['notify'] === '1' || $input['notify'] === 1 || $input['notify'] === true;

if ($hn === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'กรุณาระบุ hn'], JSON_UNESCAPED_UNICODE);
    exit;
}

$hos = hosxp_connect($con);
if (!$hos) {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'HOSxP connect failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = sp_run_screening_hn($con, $hos, $patient_type, $screen_date, $hn, $vn, $an, ['notify' => $notify]);
mysqli_close($hos);

$http = !empty($result['ok']) ? 200 : 404;
http_response_code($http);
echo json_encode([
    'status' => !empty($result['ok']) ? 'success' : 'error',
    'message' => $result['message'] ?? '',
] + $result, JSON_UNESCAPED_UNICODE);
