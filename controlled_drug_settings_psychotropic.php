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
include __DIR__ . '/controlled_drug_settings_view.php';
