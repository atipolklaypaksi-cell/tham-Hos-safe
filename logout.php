<?php
session_start();
include('connect.php');
mysqli_set_charset($con, 'utf8');
require_once __DIR__ . '/user_access_helper.php';

if (isset($_SESSION['user_id'])) {
    $snapshot = [
        'username' => (string)($_SESSION['username'] ?? ''),
        'full_name' => (string)($_SESSION['full_name'] ?? ''),
    ];
    tham_user_access_log_logout($con, (int)$_SESSION['user_id'], 'logout.php', $snapshot);
}

session_destroy();
header("Location: login.php");
exit;
