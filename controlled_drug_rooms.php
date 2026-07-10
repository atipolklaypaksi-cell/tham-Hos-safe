<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
include('connect.php');
require_once __DIR__ . '/controlled_drug_helper.php';
require_once __DIR__ . '/menu_permissions_helper.php';
mysqli_set_charset($con, 'utf8');
cdm_ensure_tables($con);
if (empty($_SESSION['menu_permissions_loaded'])) {
    tham_menu_load_session_permissions($con, (int)$_SESSION['user_id'], $_SESSION['role'] ?? '');
}

$cdm_can_narcotic = tham_menu_can('controlled_drug.narcotic');
$cdm_can_psychotropic = tham_menu_can('controlled_drug.psychotropic');
if (!$cdm_can_narcotic && !$cdm_can_psychotropic) {
    tham_menu_ensure_page_access('controlled_drug_rooms.php');
}

$cdm_default_type = 'narcotic';
if (!$cdm_can_narcotic && $cdm_can_psychotropic) {
    $cdm_default_type = 'psychotropic';
}
$req_type = cdm_normalize_type($_GET['type'] ?? '');
if ($req_type === 'psychotropic' && $cdm_can_psychotropic) {
    $cdm_default_type = 'psychotropic';
} elseif ($req_type === 'narcotic' && $cdm_can_narcotic) {
    $cdm_default_type = 'narcotic';
}

include __DIR__ . '/controlled_drug_rooms_view.php';
