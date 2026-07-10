<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}
include('connect.php');
mysqli_set_charset($con, "utf8");

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // 🎯 รายการที่เราอนุญาตให้บันทึก (Whitelist)
   $allowed_configs = [
        'h_host', 'h_db', 'h_user', 'h_pass', 
        'h_tbl_vital', 'h_fld_bw', 'h_fld_height', 
        'h_tbl_lab', 'h_code_scr', 'h_code_ckd_epi',
        'h_auto_dose', 'h_query_dose',
        'h_code_wbc', 'h_code_neutrophil', 'h_code_platelet',
        'h_code_tbili', 'h_code_dbili', 'h_code_ibili', 'h_code_ast', 'h_code_alt',
        'h_code_hbsag', 'h_code_antihbc',
        'h_code_urine_ph', 'h_code_urine_sg', 'h_code_urine_protein',
        'h_code_urine_rbc', 'h_code_urine_protein_24h',
        'lab_tbili_uln', 'lab_ast_uln', 'lab_alt_uln'
    ];

    // 1. บันทึกลงตาราง sys_config
    foreach ($allowed_configs as $key) {
        if (isset($_POST[$key])) {
            $k = mysqli_real_escape_string($con, $key);
            $v = mysqli_real_escape_string($con, $_POST[$key]);
            
            $check = mysqli_query($con, "SELECT config_name FROM sys_config WHERE config_name = '$k'");
            if (mysqli_num_rows($check) > 0) {
                mysqli_query($con, "UPDATE sys_config SET config_value = '$v' WHERE config_name = '$k'");
            } else {
                mysqli_query($con, "INSERT INTO sys_config (config_name, config_value) VALUES ('$k', '$v')");
            }
        }
    }

    // 2. บันทึกลงตาราง hosxp_settings (สำหรับ 4 ค่าหลักเชื่อมต่อ DB)
    $h_host = mysqli_real_escape_string($con, $_POST['h_host'] ?? '');
    $h_db   = mysqli_real_escape_string($con, $_POST['h_db'] ?? 'hos');
    $h_user = mysqli_real_escape_string($con, $_POST['h_user'] ?? '');
    $h_pass = mysqli_real_escape_string($con, $_POST['h_pass'] ?? '');

    $check_hosxp = mysqli_query($con, "SELECT id FROM hosxp_settings WHERE id = 1");
    if (mysqli_num_rows($check_hosxp) > 0) {
        mysqli_query($con, "UPDATE hosxp_settings SET host_ip = '$h_host', dbname = '$h_db', username = '$h_user', password = '$h_pass' WHERE id = 1");
    } else {
        mysqli_query($con, "INSERT INTO hosxp_settings (id, host_ip, dbname, username, password) VALUES (1, '$h_host', '$h_db', '$h_user', '$h_pass')");
    }

    echo json_encode(['status' => 'success', 'message' => 'บันทึกการตั้งค่าเรียบร้อยแล้ว!']);
    exit;
}
?>