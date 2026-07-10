<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
include('connect.php');
require_once __DIR__ . '/controlled_drug_helper.php';
mysqli_set_charset($con, 'utf8');
cdm_ensure_tables($con);

$cdm_type = 'psychotropic';
$cdm_initial_tab = $_GET['tab'] ?? 'dispense';
include __DIR__ . '/controlled_drug_stock_view.php';
