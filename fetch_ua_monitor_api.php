<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
include('connect.php');

$start_date = isset($_GET['start_date']) ? mysqli_real_escape_string($con, $_GET['start_date']) : date('Y-m-d', strtotime('-1 year'));
$end_date = isset($_GET['end_date']) ? mysqli_real_escape_string($con, $_GET['end_date']) : date('Y-m-d');

// ดึง Rule Engine ทั้งหมดจาก THAM-Cyto DB
mysqli_set_charset($con, "utf8");
$q_rules = mysqli_query($con, "SELECT * FROM monitor_ua_settings");
$rules = [];
while ($row = mysqli_fetch_assoc($q_rules)) {
    $rules[] = $row;
}

if (empty($rules)) {
    echo json_encode(['status' => 'error', 'message' => 'ยังไม่มีการตั้งค่าเงื่อนไข UA ในระบบ']);
    exit;
}

// เชื่อมต่อ HOSxP
$q_config = mysqli_query($con, "SELECT config_name, config_value FROM sys_config WHERE config_name IN ('h_host', 'h_user', 'h_pass', 'h_db')");
$hos_cfg = [];
while ($r = mysqli_fetch_assoc($q_config)) { $hos_cfg[$r['config_name']] = $r['config_value']; }
$con_hosxp = @mysqli_connect($hos_cfg['h_host'], $hos_cfg['h_user'], $hos_cfg['h_pass'], $hos_cfg['h_db']);

if (!$con_hosxp) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อฐานข้อมูล HOSxP ได้']);
    exit;
}
mysqli_set_charset($con_hosxp, "utf8");

$hard_alerts = [];
$soft_alerts = [];
$hard_count = 0;
$soft_count = 0;

// วนลูปตรวจสอบตามแต่ละ Rule
foreach ($rules as $rule) {
    $icode = $rule['icode'];
    $lab_code = $rule['lab_code'];
    
    // 1. ค้นหาผู้ป่วยที่ได้รับยานี้ในช่วงเวลาที่กำหนด
    // *หมายเหตุ: การดึง Dose (qty) ขึ้นอยู่กับระบบ HOSxP ของรพ. หากใช้ตารางอื่นเก็บ dose สามารถปรับแก้ตรง o.qty ได้ครับ
    $sql_drug = "
        SELECT o.hn, o.vn, v.vstdate, CONCAT(p.pname, p.fname, ' ', p.lname) as ptname, 
               o.qty as dose_qty, d.name as drug_name
        FROM opitemrece o
        JOIN vn_stat v ON v.vn = o.vn
        JOIN patient p ON p.hn = o.hn
        JOIN drugitems d ON d.icode = o.icode
        WHERE o.icode = '$icode'
        AND v.vstdate BETWEEN '$start_date' AND '$end_date'
    ";
    $q_drug = mysqli_query($con_hosxp, $sql_drug);
    
    while ($pt = mysqli_fetch_assoc($q_drug)) {
        $dose = floatval($pt['dose_qty']);
        
        // 2. ตรวจสอบ Dose Range — ใช้เฉพาะเมื่อ use_dose_condition เปิด (แบบเดียวกับ manage_safety_rules)
        $pass_dose = true;
        $use_dose_rule = !empty($rule['use_dose_condition']);
        if ($use_dose_rule) {
            $pass_dose = true;
            $dmin = $rule['min_dose'] ?? null;
            $dmax = $rule['max_dose'] ?? null;
            if ($dmin !== null && $dmin !== '' && floatval($dmin) > 0 && $dose < floatval($dmin)) {
                $pass_dose = false;
            }
            if ($dmax !== null && $dmax !== '' && floatval($dmax) > 0 && $dose > floatval($dmax)) {
                $pass_dose = false;
            }
        }
        
        if (!$pass_dose) continue; // ข้ามไปถ้า Dose ไม่เข้าเกณฑ์
        
        // 3. ดึงผล Lab UA ใน visit เดียวกัน (หรือใกล้เคียง)
        $vn = $pt['vn'];
        $sql_lab = "
            SELECT lo.lab_order_result, lh.order_date
            FROM lab_order lo
            JOIN lab_head lh ON lh.lab_order_number = lo.lab_order_number
            WHERE lh.vn = '$vn' AND lo.lab_items_code = '$lab_code'
            ORDER BY lh.order_date DESC, lh.order_time DESC
            LIMIT 1
        ";
        $q_lab = mysqli_query($con_hosxp, $sql_lab);
        if (mysqli_num_rows($q_lab) > 0) {
            $lab = mysqli_fetch_assoc($q_lab);
            $lab_result = trim($lab['lab_order_result']);
            $lab_date = !empty($lab['order_date']) ? date('d/m/Y', strtotime($lab['order_date'])) : '';
            
            // 4. แปลผลด้วย Rule Engine (Regex, Contains, Less, Greater)
            $is_alert = false;
            $cond_type = $rule['condition_type'];
            $cond_val = $rule['condition_value'];
            
            if ($cond_type === 'regex') {
                // ใช้ preg_match หา Text แบบ Regex (i = ไม่สนพิมพ์เล็ก/ใหญ่)
                if (preg_match("/" . $cond_val . "/i", $lab_result)) { $is_alert = true; }
            } elseif ($cond_type === 'contains') {
                // ค้นหา Text ตรงๆ
                if (stripos($lab_result, $cond_val) !== false) { $is_alert = true; }
            } elseif ($cond_type === 'less_than') {
                // แปลงเป็นตัวเลขก่อนเทียบ (สำหรับ pH, Sp.gr)
                if (floatval($lab_result) < floatval($cond_val)) { $is_alert = true; }
            } elseif ($cond_type === 'greater_than') {
                if (floatval($lab_result) > floatval($cond_val)) { $is_alert = true; }
            }
            
            // 5. ถ้าตรงเงื่อนไข ให้นำเข้า Array
            if ($is_alert) {
                $alert_data = [
                    'hn' => $pt['hn'],
                    'ptname' => $pt['ptname'],
                    'vn' => $pt['vn'],
                    'vstdate' => !empty($pt['vstdate']) ? date('d/m/Y', strtotime($pt['vstdate'])) : '',
                    'drug_name' => $pt['drug_name'],
                    'dose_qty' => $dose,
                    'lab_name' => $rule['lab_name'],
                    'lab_result' => $lab_result,
                    'lab_date' => $lab_date,
                    'alert_level' => $rule['alert_level'],
                    'alert_msg' => $rule['alert_message']
                ];
                
                if ($rule['alert_level'] == 'hard') {
                    $hard_alerts[] = $alert_data;
                    $hard_count++;
                } else {
                    $soft_alerts[] = $alert_data;
                    $soft_count++;
                }
            }
        }
    }
}
mysqli_close($con_hosxp);

// Function สำหรับจัดกลุ่มผลลัพธ์ตาม HN
function groupAlertsByHN($alerts) {
    $grouped = [];
    foreach ($alerts as $a) {
        $hn = $a['hn'];
        if (!isset($grouped[$hn])) {
            $grouped[$hn] = ['hn' => $hn, 'ptname' => $a['ptname'], 'visits' => []];
        }
        $grouped[$hn]['visits'][] = $a;
    }
    // แปลง Assoc Array เป็น Indexed Array
    return array_values($grouped);
}

$response = [
    'status' => 'success',
    'meta' => [
        'hard_count' => $hard_count,
        'soft_count' => $soft_count
    ],
    'hard_groups' => groupAlertsByHN($hard_alerts),
    'soft_groups' => groupAlertsByHN($soft_alerts)
];

echo json_encode($response);
?>