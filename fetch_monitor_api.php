<?php
header('Content-Type: application/json; charset=utf-8');
include('connect.php'); 

// 1. โหลด Settings ทั้งหมด
$q_set = mysqli_query($con, "SELECT * FROM monitor_settings");
$settings = [];
$icodes = [];
while ($r = mysqli_fetch_assoc($q_set)) {
    $settings[] = $r;
    if (!in_array(trim($r['icode']), $icodes)) {
        $icodes[] = trim($r['icode']);
    }
}

if (empty($settings)) {
    echo json_encode(['status' => 'success', 'results' => []]);
    exit;
}

// 2. เชื่อมต่อ HOSxP
$q_config = mysqli_query($con, "SELECT config_name, config_value FROM sys_config WHERE config_name IN ('h_host', 'h_user', 'h_pass', 'h_db')");
$hos_cfg = [];
while ($r = mysqli_fetch_assoc($q_config)) { $hos_cfg[$r['config_name']] = $r['config_value']; }

$con_hosxp = @mysqli_connect($hos_cfg['h_host'], $hos_cfg['h_user'], $hos_cfg['h_pass'], $hos_cfg['h_db']);
if (!$con_hosxp) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้']);
    exit;
}
mysqli_set_charset($con_hosxp, "utf8");

$icode_string = "'" . implode("','", array_map(function($v) use($con_hosxp) { return mysqli_real_escape_string($con_hosxp, $v); }, $icodes)) . "'";

// 3. ยิง Query รวบยอดแบบเร็วปรู๊ดปร๊าด
$sql_hosxp = "
    SELECT 
        g.hn,
        CONCAT(pt.pname, pt.fname, ' ', pt.lname) AS ptname,
        g.icode,
        g.total_dose
    FROM (
        SELECT 
            o.hn,
            o.icode,
            SUM(
                COALESCE(
                    CAST(NULLIF(REGEXP_SUBSTR(
                        IF(o.sp_use <> '' AND o.sp_use IS NOT NULL,
                           concat(IFNULL(sp.name1,''),' ',IFNULL(sp.name2,''),' ',IFNULL(sp.name3,'')),
                           IF(od.usage_line1 IS NOT NULL AND od.usage_line1 <> '',
                              concat(IFNULL(od.usage_line1,''),' ',IFNULL(od.usage_line2,''),' ',IFNULL(od.usage_line3,'')),
                              concat(IFNULL(du.name1,''),' ',IFNULL(du.name2,''),' ',IFNULL(du.name3,''))
                           )
                        ), '[0-9]+(\\\\.[0-9]+)?(?=\\\\s*mg)', 1, 1, 'i'), '') AS DECIMAL(10,2)
                    ), o.qty) 
            ) AS total_dose
        FROM opitemrece o
        LEFT JOIN drugusage du ON du.drugusage = o.drugusage
        LEFT JOIN sp_use sp ON sp.sp_use = o.sp_use
        LEFT JOIN opi_dispense od ON od.hos_guid = o.hos_guid
        WHERE o.icode IN ($icode_string)
        AND o.vstdate >= '2020-01-01' 
        GROUP BY o.hn, o.icode
    ) g
    INNER JOIN patient pt ON pt.hn = g.hn
";

$q_hosxp = mysqli_query($con_hosxp, $sql_hosxp);

if (!$q_hosxp) {
    echo json_encode(['status' => 'error', 'message' => 'SQL Error: ' . mysqli_error($con_hosxp)]);
    mysqli_close($con_hosxp);
    exit;
}

$results = [];

// 4. เอาผลรวมมาเทียบกับเงื่อนไขทีละคน (เพิ่มลอจิกการคำนวณ BSA)
while ($row = mysqli_fetch_assoc($q_hosxp)) {
    $hn = trim($row['hn']);
    $icode = trim($row['icode']);
    $total = floatval($row['total_dose']);
    $ptname = $row['ptname'];
    
    $patient_bsa = null; // รีเซ็ต BSA สำหรับผู้ป่วยรายใหม่

    foreach ($settings as $s) {
        if (trim($s['icode']) == $icode) {
            
            $is_bsa = (strpos($s['condition_type'], '_bsa') !== false);
            $base_type = str_replace('_bsa', '', $s['condition_type']);
            
            $compare_val = $total;
            $display_dose = number_format($total, 2) . " mg";
            
            // 🎯 ถ้าเงื่อนไขนี้สั่งให้คิดแบบ mg/m2
            if ($is_bsa) {
                // ดึงข้อมูลน้ำหนัก-ส่วนสูงล่าสุด (ทำแค่ 1 ครั้งต่อผู้ป่วย 1 คน)
                if ($patient_bsa === null) {
                    $sql_bw = "SELECT bw, height FROM opdscreen WHERE hn = '$hn' AND bw > 0 AND height > 0 ORDER BY vstdate DESC, vsttime DESC LIMIT 1";
                    $q_bw = mysqli_query($con_hosxp, $sql_bw);
                    if ($q_bw && $r_bw = mysqli_fetch_assoc($q_bw)) {
                        $w = floatval($r_bw['bw']);
                        $h = floatval($r_bw['height']);
                        $patient_bsa = sqrt(($w * $h) / 3600); // สูตร Mosteller
                    } else {
                        $patient_bsa = 0; // ไม่มีประวัติให้คำนวณ
                    }
                }
                
                // ถ้ารู้ BSA ให้คำนวณสัดส่วน
                if ($patient_bsa > 0) {
                    $compare_val = $total / $patient_bsa;
                    // โชว์ทั้งค่า mg สุทธิ และค่า mg/m2 ให้ดูประกอบการตัดสินใจ
                    $display_dose = number_format($total, 2) . " mg<br><small class='text-warning'>(" . number_format($compare_val, 2) . " mg/m²)</small>";
                } else {
                    // ถ้าหา BSA ไม่ได้ ข้ามการเช็คเงื่อนไขนี้ไปเลยเพื่อความปลอดภัย
                    continue; 
                }
            }
            
            $matched = false;
            $cond_str = "";
            $v1 = floatval($s['val1']);
            $v2 = floatval($s['val2']);
            $unit = $is_bsa ? 'mg/m²' : 'mg';
            
            if ($base_type == '>') {
                if ($compare_val > $v1) { $matched = true; $cond_str = "> {$v1} {$unit}"; }
            } elseif ($base_type == '<') {
                if ($compare_val < $v1) { $matched = true; $cond_str = "< {$v1} {$unit}"; }
            } elseif ($base_type == 'BETWEEN') {
                if ($compare_val >= $v1 && $compare_val <= $v2) { $matched = true; $cond_str = "{$v1} - {$v2} {$unit}"; }
            }
            
            if ($matched) {
                $results[] = [
                    'hn' => ltrim($hn, '0'), 
                    'ptname' => $ptname,
                    'icode' => $icode, // 🎯 เพิ่มบรรทัดนี้ เพื่อส่งรหัสยาไปให้ JavaScript
                    'drug_name' => $s['drug_name'],
                    'total_dose_display' => $display_dose,
                    'sort_val' => $compare_val, 
                    'condition_str' => $cond_str
                ];
            }
        }
    }
}

// 5. เรียงลำดับผลลัพธ์ให้คนที่เสี่ยงมาก (Dose หรือ Dose/m2 สูง) อยู่บนสุด
usort($results, function($a, $b) {
    return $b['sort_val'] <=> $a['sort_val'];
});

mysqli_close($con_hosxp);
echo json_encode(['status' => 'success', 'results' => $results]);
?>