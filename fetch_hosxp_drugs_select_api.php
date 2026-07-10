<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) && !isset($_SESSION['full_name'])) {
    echo json_encode(['results' => []]);
    exit;
}

include('connect.php');
include('hosxp_tmt_drug_helper.php');

$term = trim($_GET['q'] ?? $_GET['term'] ?? '');
$con_hosxp = hosxp_connect($con);
if (!$con_hosxp) {
    echo json_encode(['results' => []]);
    exit;
}

$drugs = hosxp_search_drugs_tmt($con_hosxp, $term, 30);
mysqli_close($con_hosxp);

$results = [];
foreach ($drugs as $d) {
    $label = $d['name_local'];
    if ($d['name_tmt'] !== '' && $d['name_tmt'] !== $d['name_local']) {
        $label .= ' — ' . $d['name_tmt'];
    }
    $label .= ' (icode: ' . $d['icode'] . ')';
    $results[] = [
        'id' => $d['icode'] . '|' . $d['name_tmt'],
        'text' => $label,
    ];
}

echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE);
