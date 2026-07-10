<?php
session_start();
include('connect.php');
mysqli_set_charset($con, 'utf8');
require_once __DIR__ . '/menu_permissions_helper.php';
require_once __DIR__ . '/opduser_auth_helper.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Unauthorized');
}
if (empty($_SESSION['menu_permissions_loaded'])) {
    tham_menu_load_session_permissions($con, (int)$_SESSION['user_id'], $_SESSION['role'] ?? '');
}
if (!tham_menu_can('admin.users')) {
    http_response_code(403);
    exit('Unauthorized');
}

$filters = [
    'q' => trim($_GET['q'] ?? ''),
    'entryposition' => trim($_GET['entryposition'] ?? ''),
    'department' => trim($_GET['department'] ?? ''),
    'limit' => 5000,
    'offset' => 0,
];

$result = tham_opduser_fetch_users_report($con, $filters);
if (empty($result['ok'])) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $result['error'] ?? 'Export failed';
    exit;
}

$filename = 'opduser_report_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fprintf($out, "\xEF\xBB\xBF");
fputcsv($out, [
    'loginname',
    'ชื่อ-นามสกุล',
    'entryposition',
    'แผนก',
    'ตำแหน่งในแผนก',
    'สิทธิที่กำหนด (ตามตำแหน่ง)',
    'ลงทะเบียน THAM SAFE',
    'สิทธิใน THAM SAFE',
]);

foreach ($result['rows'] as $row) {
    fputcsv($out, [
        $row['loginname'] ?? '',
        $row['name'] ?? '',
        $row['entryposition'] ?? '',
        $row['department'] ?? '',
        $row['departmentposition'] ?? '',
        $row['mapped_role_label'] ?? '',
        !empty($row['tham_registered']) ? 'ใช่' : 'ยังไม่เคย login',
        $row['tham_role_label'] ?? '',
    ]);
}
fclose($out);
