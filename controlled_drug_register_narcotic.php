<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
include('connect.php');
require_once __DIR__ . '/menu_permissions_helper.php';
mysqli_set_charset($con, 'utf8');
if (empty($_SESSION['menu_permissions_loaded'])) {
    tham_menu_load_session_permissions($con, (int)$_SESSION['user_id'], $_SESSION['role'] ?? '');
}
if (!tham_menu_can('controlled_drug.narcotic')) {
    tham_menu_ensure_page_access('controlled_drug_narcotic.php');
}
header('Location: controlled_drug_narcotic.php?tab=register');
exit;
