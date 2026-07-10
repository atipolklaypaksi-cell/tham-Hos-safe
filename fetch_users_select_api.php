<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) && !isset($_SESSION['full_name'])) {
    echo json_encode(['results' => []]);
    exit;
}

include('connect.php');
require_once __DIR__ . '/controlled_drug_helper.php';
require_once __DIR__ . '/menu_permissions_helper.php';
mysqli_set_charset($con, 'utf8');

$term = trim($_GET['q'] ?? $_GET['term'] ?? '');
$source = trim($_GET['source'] ?? '');
$position_regex = trim($_GET['position_regex'] ?? '');
if ($position_regex === '' && !empty($_GET['pharmacist'])) {
    $position_regex = cdm_pharmacist_entryposition_regex();
}

$results = [];
if ($position_regex !== '') {
    $users = cdm_search_hosxp_opdusers($con, $term, 100, $position_regex);
    $results = cdm_build_hosxp_user_select_results($users);
} elseif ($source === 'hosxp') {
    $users = cdm_search_hosxp_opdusers($con, $term, 100, '');
    $results = cdm_build_hosxp_user_select_results($users);
} else {
    $users = cdm_search_users($con, $term, 30, '');
    $role_labels = tham_menu_role_labels();
    foreach ($users as $u) {
        $name = trim((string)($u['full_name'] ?? ''));
        if ($name === '') {
            $name = trim((string)($u['username'] ?? ''));
        }
        if ($name === '') {
            continue;
        }
        $pos = trim((string)($u['entryposition'] ?? ''));
        $role = trim((string)($u['role'] ?? ''));
        $role_label = $role_labels[$role] ?? $role;
        $label = $name;
        if ($pos !== '') {
            $label .= ' (' . $pos . ')';
        } elseif ($role_label !== '') {
            $label .= ' (' . $role_label . ')';
        }
        $results[] = [
            'id' => $name,
            'text' => $label,
        ];
    }
}

echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE);
