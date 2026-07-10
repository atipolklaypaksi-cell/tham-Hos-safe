<?php
session_start();
// ตรวจสอบสิทธิ์การเข้าถึง
if (!isset($_SESSION['user_id'])) {
    die("คุณไม่มีสิทธิ์เข้าถึงหน้านี้");
}

include('connect.php');
mysqli_set_charset($con, "utf8");

// ตั้งชื่อไฟล์ Backup (เช่น tham_cyto_backup_2026-03-24_10-30-00.sql)
$filename = "tham_cyto_backup_" . date("Y-m-d_H-i-s") . ".sql";

// กำหนด Header ให้เบราว์เซอร์รู้ว่านี่คือการดาวน์โหลดไฟล์
header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$sqlScript = "-- ---------------------------------------------------------\n";
$sqlScript .= "-- ฐานข้อมูล THAM CYTO Backup\n";
$sqlScript .= "-- วันที่สำรองข้อมูล: " . date("Y-m-d H:i:s") . "\n";
$sqlScript .= "-- ---------------------------------------------------------\n\n";
$sqlScript .= "SET FOREIGN_KEY_CHECKS=0;\n\n"; // ปิดการเช็คคีย์ชั่วคราวตอน Import จะได้ไม่ Error

// 1. หาชื่อตารางทั้งหมดในฐานข้อมูล
$tables = [];
$result = mysqli_query($con, "SHOW TABLES");
while ($row = mysqli_fetch_row($result)) { 
    $tables[] = $row[0]; 
}

// 2. วนลูปดูดข้อมูลทีละตาราง
foreach ($tables as $table) {
    // ดึงโครงสร้างตาราง (CREATE TABLE)
    $result = mysqli_query($con, "SHOW CREATE TABLE `$table`");
    $row = mysqli_fetch_row($result);
    $sqlScript .= "-- โครงสร้างตาราง `$table`\n";
    $sqlScript .= "DROP TABLE IF EXISTS `$table`;\n"; // สั่งลบตารางเก่าทิ้งก่อนตอน Import
    $sqlScript .= $row[1] . ";\n\n";

    // ดึงข้อมูลในตาราง (INSERT INTO)
    $result = mysqli_query($con, "SELECT * FROM `$table`");
    if (mysqli_num_rows($result) > 0) {
        $sqlScript .= "-- ข้อมูลในตาราง `$table`\n";
        while ($row = mysqli_fetch_assoc($result)) {
            $keys = array_keys($row);
            $values = array_map(function($value) use ($con) {
                if ($value === null) return 'NULL';
                return "'" . mysqli_real_escape_string($con, $value) . "'";
            }, array_values($row));
            
            $sqlScript .= "INSERT INTO `$table` (`" . implode("`, `", $keys) . "`) VALUES (" . implode(", ", $values) . ");\n";
        }
        $sqlScript .= "\n";
    }
}

$sqlScript .= "SET FOREIGN_KEY_CHECKS=1;\n"; // เปิดการเช็คคีย์กลับมาเหมือนเดิม

// พิมพ์ข้อมูลทั้งหมดออกมา (ซึ่งจะถูกโหลดเป็นไฟล์ .sql ทันที)
echo $sqlScript;
mysqli_close($con);
exit;
?>