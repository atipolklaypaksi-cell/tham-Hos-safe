<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

include('connect.php');
mysqli_set_charset($con, 'utf8');

$action_type = $_POST['action_type'] ?? 'add';
$icode = mysqli_real_escape_string($con, trim($_POST['icode'] ?? ''));
$name = mysqli_real_escape_string($con, trim($_POST['name'] ?? ''));
$strength_unit = mysqli_real_escape_string($con, trim($_POST['strength_unit'] ?? ''));
$cumulative_dose = !empty($_POST['cumulative_dose']) ? (float)$_POST['cumulative_dose'] : 0;
$istatus = mysqli_real_escape_string($con, $_POST['istatus'] ?? 'Y');

if ($icode === '' || $name === '') {
    echo "<script>alert('กรุณากรอก ICODE และชื่อยา'); window.history.back();</script>";
    exit;
}

if ($action_type === 'add') {
    $sql = "INSERT INTO drugitems (icode, name, strength_unit, cumulative_dose, istatus, unitprice, dose_per_container, vol_per_container, calc_method, gfr_cap, is_vesicant, is_cytotoxic, is_light_protect, is_refrigerate, auto_stock_cut)
            VALUES ('$icode', '$name', '$strength_unit', '$cumulative_dose', '$istatus', 0, 0, 0, 1, 0, 0, 0, 0, 0, 0)";
} else {
    $id = (int)($_POST['id'] ?? 0);
    $sql = "UPDATE drugitems SET
                icode = '$icode',
                name = '$name',
                strength_unit = '$strength_unit',
                cumulative_dose = '$cumulative_dose',
                istatus = '$istatus'
            WHERE id = $id";
}

if (mysqli_query($con, $sql)) {
    echo "<script>alert('บันทึกข้อมูลเรียบร้อยแล้ว'); window.location='drugitem.php';</script>";
} else {
    echo "<script>alert('เกิดข้อผิดพลาด: " . addslashes(mysqli_error($con)) . "'); window.history.back();</script>";
}

mysqli_close($con);
