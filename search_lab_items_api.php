<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) && !isset($_SESSION['full_name'])) {
    echo json_encode(['results' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

include('connect.php');
include('hosxp_tmt_drug_helper.php');

$term = isset($_GET['q']) ? trim($_GET['q']) : '';
if ($term === '' && isset($_GET['term'])) {
    $term = trim($_GET['term']);
}

$con_hosxp = hosxp_connect($con);
if (!$con_hosxp) {
    echo json_encode(['results' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$items = hosxp_search_lab_items($con_hosxp, $term, 40);
mysqli_close($con_hosxp);

$results = [];
foreach ($items as $item) {
    $code = $item['code'];
    $name = $item['name'];
    $val = $code . '|' . $name;
    $label = '[' . $code . '] ' . ($name !== '' ? $name : $code);
    $results[] = ['id' => $val, 'text' => $label];
}

echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE);
