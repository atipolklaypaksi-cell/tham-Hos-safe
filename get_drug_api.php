<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if(!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if(empty($_GET['id'])) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่พบรหัสยา']);
    exit;
}

include('connect.php');
mysqli_set_charset($con, "utf8");

$id = mysqli_real_escape_string($con, $_GET['id']);
$sql = "SELECT * FROM drugitems WHERE id = '$id' LIMIT 1";
$q = mysqli_query($con, $sql);

if($q && $row = mysqli_fetch_assoc($q)) {
    echo json_encode(['status' => 'success', 'data' => $row]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูลยาในระบบ']);
}

mysqli_close($con);
?>