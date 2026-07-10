<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
include('connect.php');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// --- Parameters ---
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-1 year'));
$end_date   = $_GET['end_date'] ?? date('Y-m-d');
$co_med_months = isset($_GET['co_med_months']) ? intval($_GET['co_med_months']) : 6;
$lab_screen_months = isset($_GET['lab_screen_months']) ? intval($_GET['lab_screen_months']) : 12;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
    $start_date = date('Y-m-d', strtotime('-1 year'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    $end_date = date('Y-m-d');
}
if (strtotime($start_date) > strtotime($end_date)) {
    $tmp = $start_date; $start_date = $end_date; $end_date = $tmp;
}
if ($co_med_months < 1)  $co_med_months = 1;
if ($co_med_months > 24) $co_med_months = 24;
if ($lab_screen_months < 1)  $lab_screen_months = 1;
if ($lab_screen_months > 60) $lab_screen_months = 60;

$post_chemo_days = 7;

function hbv_normalize_hn($hn) {
    $hn = trim((string)$hn);
    if ($hn === '') return '';
    return str_pad($hn, 9, '0', STR_PAD_LEFT);
}

function hbv_is_positive($result) {
    if ($result === null || $result === '') return false;
    $r = strtolower(trim($result));
    if (preg_match('/\b(neg|negative|non.?reactive|not\s+detected|not\s+reactive)\b/i', $r)) {
        return false;
    }
    if (preg_match('/^[\s\-0\.]+$/', $r)) return false;
    return (strpos($r, 'pos') !== false || strpos($r, 'reactive') !== false || preg_match('/\+/', $r));
}

function hbv_get_antiviral_drugs($con_hosxp, $hn, $chemo_date, $antiviral_list, $co_med_months, $post_days) {
    $hn_esc = mysqli_real_escape_string($con_hosxp, hbv_normalize_hn($hn));
    $date_esc = mysqli_real_escape_string($con_hosxp, $chemo_date);
    $drugs = [];
    $sql = "SELECT d.name AS drug_name, o.vstdate
            FROM opitemrece o
            JOIN drugitems d ON o.icode = d.icode
            WHERE o.hn = '$hn_esc'
              AND o.icode IN ($antiviral_list)
              AND o.vstdate >= DATE_SUB('$date_esc', INTERVAL $co_med_months MONTH)
              AND o.vstdate <= DATE_ADD('$date_esc', INTERVAL $post_days DAY)
            GROUP BY d.name, o.vstdate
            ORDER BY o.vstdate DESC";
    $res = mysqli_query($con_hosxp, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $drugs[] = $row;
        }
    }
    return $drugs;
}

function hbv_format_antiviral_status($drugs) {
    if (empty($drugs)) {
        return '<span class="text-danger fw-bold"><i class="fas fa-times-circle"></i> ไม่พบยาป้องกัน</span>';
    }
    $html = '<span class="text-success fw-bold"><i class="fas fa-check-circle"></i> พบยาป้องกัน</span>';
    $html .= '<div class="small text-success mt-1">';
    $seen = [];
    foreach ($drugs as $d) {
        $key = $d['drug_name'] . '|' . $d['vstdate'];
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $html .= '<div class="d-block">• ' . htmlspecialchars($d['drug_name']) . ' <span class="text-muted">(' . $d['vstdate'] . ')</span></div>';
    }
    $html .= '</div>';
    return $html;
}

function hbv_get_labs_before_chemo($con_hosxp, $hn, $chemo_date, $lab_screen_months, $code_hbsag, $code_antihbc) {
    $hn_esc = mysqli_real_escape_string($con_hosxp, hbv_normalize_hn($hn));
    $date_esc = mysqli_real_escape_string($con_hosxp, $chemo_date);
    $labs = ['hbsag' => null, 'antihbc' => null, 'has_screening' => false];

    if ($code_hbsag === '') { $code_hbsag = '227'; }
    if ($code_antihbc === '') { $code_antihbc = '225'; }

    $codes = array_unique(array_filter([$code_hbsag, $code_antihbc], function ($c) {
        return trim((string)$c) !== '';
    }));
    if (empty($codes)) {
        return $labs;
    }

    $escaped_codes = array_map(function ($c) use ($con_hosxp) {
        return "'" . mysqli_real_escape_string($con_hosxp, $c) . "'";
    }, $codes);
    $code_list = implode(',', $escaped_codes);

    $sql = "SELECT lo.lab_items_code, lo.lab_order_result, lh.order_date, li.lab_items_name
            FROM lab_head lh
            JOIN lab_order lo ON lh.lab_order_number = lo.lab_order_number
            JOIN lab_items li ON lo.lab_items_code = li.lab_items_code
            WHERE lh.hn = '$hn_esc'
              AND lo.lab_items_code IN ($code_list)
              AND lo.lab_order_result != ''
              AND lo.lab_order_result IS NOT NULL
              AND lh.order_date >= DATE_SUB('$date_esc', INTERVAL $lab_screen_months MONTH)
              AND lh.order_date <= '$date_esc'
            ORDER BY lh.order_date DESC, lh.order_time DESC";
    $res = mysqli_query($con_hosxp, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $item_code = trim((string)$row['lab_items_code']);
            if ($item_code === (string)$code_hbsag && $labs['hbsag'] === null) {
                $labs['hbsag'] = $row;
            }
            if ($item_code === (string)$code_antihbc && $labs['antihbc'] === null) {
                $labs['antihbc'] = $row;
            }
        }
    }
    $labs['has_screening'] = ($labs['hbsag'] !== null && $labs['antihbc'] !== null);
    return $labs;
}

function hbv_format_lab_cell($labs) {
    if (!$labs['has_screening']) {
        return '<span class="text-muted">ไม่พบการคัดกรอง</span>';
    }
    $parts = [];
    if ($labs['hbsag']) {
        $pos = hbv_is_positive($labs['hbsag']['lab_order_result']);
        $cls = $pos ? 'text-danger' : 'text-success';
        $parts[] = 'HBsAg: <b class="' . $cls . '">' . htmlspecialchars($labs['hbsag']['lab_order_result']) . '</b> (' . $labs['hbsag']['order_date'] . ')';
    } else {
        $parts[] = 'HBsAg: <span class="text-muted">ไม่พบ</span>';
    }
    if ($labs['antihbc']) {
        $pos = hbv_is_positive($labs['antihbc']['lab_order_result']);
        $cls = $pos ? 'text-danger' : 'text-success';
        $parts[] = 'Anti-HBc: <b class="' . $cls . '">' . htmlspecialchars($labs['antihbc']['lab_order_result']) . '</b> (' . $labs['antihbc']['order_date'] . ')';
    } else {
        $parts[] = 'Anti-HBc: <span class="text-muted">ไม่พบ</span>';
    }
    return implode('<br>', $parts);
}

function hbv_group_by_hn($visits) {
    $groups = [];
    foreach ($visits as $v) {
        $hn = $v['hn'];
        if (!isset($groups[$hn])) {
            $groups[$hn] = [
                'hn' => $hn,
                'ptname' => $v['ptname'],
                'visits' => []
            ];
        }
        $groups[$hn]['visits'][] = $v;
    }
    return array_values($groups);
}

// --- Load settings ---
$antiviral_icodes = [];
$high_risk_map = [];
$moderate_risk_map = [];

$q_settings = mysqli_query($con, "SELECT monitor_type, icode, drug_name FROM monitor_hbv_settings 
    WHERE monitor_type IN ('hbv_required_drug', 'hbv_high_risk_chemo', 'hbv_moderate_risk_chemo')");
while ($s = mysqli_fetch_assoc($q_settings)) {
    $icode = $s['icode'];
    if ($s['monitor_type'] == 'hbv_required_drug') {
        $antiviral_icodes[] = "'" . mysqli_real_escape_string($con, $icode) . "'";
    } elseif ($s['monitor_type'] == 'hbv_high_risk_chemo') {
        $high_risk_map[$icode] = $s['drug_name'];
    } elseif ($s['monitor_type'] == 'hbv_moderate_risk_chemo') {
        $moderate_risk_map[$icode] = $s['drug_name'];
    }
}

if (empty($antiviral_icodes)) { $antiviral_icodes = ["''"]; }
$antiviral_list = implode(',', $antiviral_icodes);

$all_risk_icodes = array_merge(array_keys($high_risk_map), array_keys($moderate_risk_map));
if (empty($all_risk_icodes)) {
    echo json_encode([
        'status' => 'success',
        'meta' => [
            'summary' => 'ยังไม่มีการตั้งค่ายาเคมีความเสี่ยง (สูง/ปานกลาง) ในระบบ',
            'hard_count' => 0,
            'soft_count' => 0,
            'protected_count' => 0,
            'screened_negative_count' => 0
        ],
        'hard_groups' => [],
        'soft_groups' => [],
        'protected_groups' => [],
        'screened_negative_groups' => []
    ]);
    exit;
}

$risk_icode_list = implode(',', array_map(function($c) use ($con) {
    return "'" . mysqli_real_escape_string($con, $c) . "'";
}, $all_risk_icodes));

// --- HOSxP ---
$q_config = mysqli_query($con, "SELECT config_name, config_value FROM sys_config WHERE config_name IN ('h_host', 'h_user', 'h_pass', 'h_db', 'h_code_hbsag', 'h_code_antihbc')");
$hos_cfg = [];
while ($r = mysqli_fetch_assoc($q_config)) { $hos_cfg[$r['config_name']] = $r['config_value']; }
$code_hbsag = trim($hos_cfg['h_code_hbsag'] ?? '');
$code_antihbc = trim($hos_cfg['h_code_antihbc'] ?? '');

$con_hosxp = @mysqli_connect($hos_cfg['h_host'], $hos_cfg['h_user'], $hos_cfg['h_pass'], $hos_cfg['h_db']);
if (!$con_hosxp) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อฐานข้อมูล HOSxP ได้']);
    exit;
}
mysqli_set_charset($con_hosxp, "utf8");

$start_esc = mysqli_real_escape_string($con_hosxp, $start_date);
$end_esc   = mysqli_real_escape_string($con_hosxp, $end_date);

// --- Fetch all risk chemo orders in report period ---
$sql_orders = "
    SELECT o.hn, o.vstdate, o.vn, o.icode, d.name AS drug_name,
           p.pname, p.fname, p.lname
    FROM opitemrece o
    JOIN patient p ON o.hn = p.hn
    JOIN drugitems d ON o.icode = d.icode
    WHERE o.vstdate BETWEEN '$start_esc' AND '$end_esc'
      AND o.icode IN ($risk_icode_list)
    ORDER BY o.hn ASC, o.vstdate DESC, d.name ASC
";
$res_orders = mysqli_query($con_hosxp, $sql_orders);

$visit_map = [];
if ($res_orders) {
    while ($row = mysqli_fetch_assoc($res_orders)) {
        $icode = $row['icode'];
        $risk = isset($high_risk_map[$icode]) ? 'high' : 'moderate';
        $key = $row['hn'] . '|' . $row['vstdate'] . '|' . ($row['vn'] ?? '');

        if (!isset($visit_map[$key])) {
            $visit_map[$key] = [
                'hn' => $row['hn'],
                'ptname' => $row['pname'] . $row['fname'] . ' ' . $row['lname'],
                'vstdate' => $row['vstdate'],
                'vn' => $row['vn'] ?? '',
                'high_drugs' => [],
                'moderate_drugs' => [],
            ];
        }
        if ($risk === 'high') {
            $visit_map[$key]['high_drugs'][$icode] = $row['drug_name'];
        } else {
            $visit_map[$key]['moderate_drugs'][$icode] = $row['drug_name'];
        }
    }
}

$hard_visits = [];
$soft_visits = [];
$protected_visits = [];
$screened_negative_visits = [];

foreach ($visit_map as $visit) {
    $has_high = !empty($visit['high_drugs']);
    $has_moderate = !empty($visit['moderate_drugs']);
    $max_risk = $has_high ? 'high' : 'moderate';

    $risk_drug_names = array_merge(array_values($visit['high_drugs']), array_values($visit['moderate_drugs']));
    $risk_drugs_html = '';
    if (!empty($visit['high_drugs'])) {
        $risk_drugs_html .= '<span class="badge bg-danger me-1 mb-1">สูง</span> ' . implode(', ', array_map('htmlspecialchars', array_values($visit['high_drugs']))) . '<br>';
    }
    if (!empty($visit['moderate_drugs'])) {
        $risk_drugs_html .= '<span class="badge bg-warning text-dark me-1 mb-1">ปานกลาง</span> ' . implode(', ', array_map('htmlspecialchars', array_values($visit['moderate_drugs'])));
    }

    $labs = hbv_get_labs_before_chemo($con_hosxp, $visit['hn'], $visit['vstdate'], $lab_screen_months, $code_hbsag, $code_antihbc);
    $hbsag_pos = ($labs['hbsag'] && hbv_is_positive($labs['hbsag']['lab_order_result']));
    $hbsag_neg = ($labs['hbsag'] && !hbv_is_positive($labs['hbsag']['lab_order_result']));
    $antihbc_pos = ($labs['antihbc'] && hbv_is_positive($labs['antihbc']['lab_order_result']));
    $antihbc_neg = ($labs['antihbc'] && !hbv_is_positive($labs['antihbc']['lab_order_result']));
    $antiviral_drugs = hbv_get_antiviral_drugs($con_hosxp, $visit['hn'], $visit['vstdate'], $antiviral_list, $co_med_months, $post_chemo_days);
    $has_antiviral = !empty($antiviral_drugs);

    $base = [
        'hn' => $visit['hn'],
        'ptname' => $visit['ptname'],
        'vstdate' => $visit['vstdate'],
        'vn' => $visit['vn'],
        'max_risk' => $max_risk,
        'max_risk_th' => ($max_risk === 'high') ? 'เสี่ยงสูง' : 'เสี่ยงปานกลาง',
        'risk_drugs' => $risk_drugs_html,
        'risk_drug_list' => $risk_drug_names,
        'lab_status' => hbv_format_lab_cell($labs),
        'has_antiviral' => $has_antiviral,
        'antiviral_drugs' => array_map(function($d) {
            return $d['drug_name'] . ' (' . $d['vstdate'] . ')';
        }, $antiviral_drugs),
        'antiviral_status' => hbv_format_antiviral_status($antiviral_drugs),
    ];

    // --- Clinical decision matrix ---
    if (!$labs['has_screening']) {
        $alert = array_merge($base, [
            'alert_level' => $has_high ? 'hard' : 'soft',
            'alert_type' => 'unscreened',
            'alert_type_th' => $has_high ? 'Hard: ยังไม่คัดกรอง Lab' : 'Soft: ยังไม่คัดกรอง Lab',
            'details' => 'ไม่พบผลตรวจ HBsAg / Anti-HBc ภายใน ' . $lab_screen_months . ' เดือนก่อนวันให้คีโม (' . $visit['vstdate'] . ') — ควรสั่งคัดกรองก่อน/วันให้ยา',
        ]);
        if ($has_high) $hard_visits[] = $alert; else $soft_visits[] = $alert;
        continue;
    }

    // มีผลคัดกรองครบทั้ง 2 ตัว และผลเป็นลบทั้งคู่ -> เข้ากลุ่ม "คัดกรองแล้ว"
    if ($hbsag_neg && $antihbc_neg) {
        $screened_negative_visits[] = array_merge($base, [
            'alert_level' => 'success',
            'alert_type' => 'screened_negative',
            'alert_type_th' => 'Safe: คัดกรองแล้ว (Negative)',
            'details' => 'มีผล HBsAg และ Anti-HBc เป็นลบทั้งคู่ — อยู่ในกลุ่มคัดกรองแล้ว',
        ]);
        continue;
    }

    if ($hbsag_pos) {
        if (!$has_antiviral) {
            $hard_visits[] = array_merge($base, [
                'alert_level' => 'hard',
                'alert_type' => 'hbsag_missing_prophylaxis',
                'alert_type_th' => 'Hard: HBsAg (+) ต้องให้ยาป้องกัน',
                'details' => 'ผล HBsAg เป็นบวก — ต้องสั่งยาต้านไวรัสป้องกันก่อนหรือพร้อมให้คีโม (ไม่พบยาป้องกันในช่วง ' . $co_med_months . ' เดือนก่อน ถึง ' . $post_chemo_days . ' วันหลังให้คีโม)',
            ]);
        } else {
            // เพิ่ม: กรณี HBsAg (+) และได้รับยาป้องกันแล้ว
            $protected_visits[] = array_merge($base, [
                'alert_level' => 'success',
                'alert_type' => 'protected',
                'alert_type_th' => 'Safe: ได้รับยาป้องกันแล้ว',
                'details' => 'ผล HBsAg เป็นบวก — ผู้ป่วยได้รับยาต้านไวรัสป้องกันเรียบร้อยแล้ว',
            ]);
        }
        continue;
    }

    if ($antihbc_pos && ($hbsag_neg || !$labs['hbsag'])) {
        if ($has_high && !$has_antiviral) {
            $hard_visits[] = array_merge($base, [
                'alert_level' => 'hard',
                'alert_type' => 'core_high_missing_prophylaxis',
                'alert_type_th' => 'Hard: Anti-HBc (+) + ยาเสี่ยงสูง',
                'details' => 'Anti-HBc (+) / HBsAg (-) + ยาเคมีความเสี่ยงสูง — ต้องให้ยาป้องกัน (ไม่พบยาป้องกันในช่วงที่กำหนด)',
            ]);
        } elseif ($has_high && $has_antiviral) {
            // เพิ่ม: กรณี Anti-HBc (+) ให้ยาเสี่ยงสูง และได้รับยาป้องกันแล้ว
            $protected_visits[] = array_merge($base, [
                'alert_level' => 'success',
                'alert_type' => 'protected',
                'alert_type_th' => 'Safe: ได้รับยาป้องกันแล้ว',
                'details' => 'Anti-HBc (+) + ยาเคมีความเสี่ยงสูง — ผู้ป่วยได้รับยาต้านไวรัสป้องกันเรียบร้อยแล้ว',
            ]);
        }
        
        // กรณีเสี่ยงปานกลาง "อย่างเดียว" ให้เลือกแสดงเพียงฝั่งเดียว:
        // - มี prophylaxis -> Protected
        // - ไม่มี prophylaxis -> Soft (เฝ้าระวังต่อเนื่อง)
        if ($has_moderate && !$has_high) {
            if ($has_antiviral) {
                $protected_visits[] = array_merge($base, [
                    'alert_level' => 'success',
                    'alert_type' => 'protected_moderate',
                    'alert_type_th' => 'Safe: ได้รับยาป้องกันแล้ว',
                    'details' => 'Anti-HBc (+) / HBsAg (-) + ยาเคมีความเสี่ยงปานกลาง — พบยาต้านไวรัสป้องกันแล้ว (ยังแนะนำติดตาม HBsAg และ ALT ทุก 3 เดือน)',
                ]);
            } else {
                $soft_visits[] = array_merge($base, [
                    'alert_level' => 'soft',
                    'alert_type' => 'core_moderate_surveillance',
                    'alert_type_th' => 'Soft: Anti-HBc (+) + ยาเสี่ยงปานกลาง',
                    'details' => 'Anti-HBc (+) / HBsAg (-) + ยาเคมีความเสี่ยงปานกลาง — ติดตาม HBsAg และ ALT ทุก 3 เดือน',
                ]);
            }
        }
        continue;
    }
}

mysqli_close($con_hosxp);

$hard_groups = hbv_group_by_hn($hard_visits);
$soft_groups = hbv_group_by_hn($soft_visits);
$protected_groups = hbv_group_by_hn($protected_visits);
$screened_negative_groups = hbv_group_by_hn($screened_negative_visits);

echo json_encode([
    'status' => 'success',
    'meta' => [
        'start_date' => $start_date,
        'end_date' => $end_date,
        'co_med_months' => $co_med_months,
        'lab_screen_months' => $lab_screen_months,
        'post_chemo_days' => $post_chemo_days,
        'hard_count' => count($hard_visits),
        'soft_count' => count($soft_visits),
        'protected_count' => count($protected_visits),
        'screened_negative_count' => count($screened_negative_visits),
        'summary' => 'ช่วงรายงาน ' . $start_date . ' ถึง ' . $end_date
            . ' | Hard: ' . count($hard_visits) 
            . ' | Soft: ' . count($soft_visits) 
            . ' | Protected: ' . count($protected_visits)
            . ' | Screened(Neg): ' . count($screened_negative_visits),
    ],
    'hard_groups' => $hard_groups,
    'soft_groups' => $soft_groups,
    'protected_groups' => $protected_groups,
    'screened_negative_groups' => $screened_negative_groups,
]);
exit;
