<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
include('connect.php');
mysqli_set_charset($con, "utf8");

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

function hbv_normalize_hn($hn) {
    $hn = trim((string)$hn);
    if ($hn === '') return '';
    return str_pad($hn, 9, '0', STR_PAD_LEFT);
}

function hbv_is_meaningful_lab_text($value) {
    $v = trim((string)$value);
    if ($v === '') return false;
    return !preg_match('/^(n\/a|na|none|nil|-+)$/i', $v);
}

function hbv_is_positive_text($result) {
    if ($result === null || $result === '') return false;
    $r = strtolower(trim($result));
    if (preg_match('/\b(neg|negative|non.?reactive|not\s+detected|not\s+reactive)\b/i', $r)) {
        return false;
    }
    if (preg_match('/^[\s\-0\.]+$/', $r)) return false;
    return (strpos($r, 'pos') !== false || strpos($r, 'reactive') !== false || preg_match('/\+/', $r));
}

function hbv_get_latest_labs($con_hosxp, $hn, $chemo_date, $lab_screen_months, $code_hbsag, $code_antihbc) {
    $safe_hn = mysqli_real_escape_string($con_hosxp, hbv_normalize_hn($hn));
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

    $sql = "SELECT lo.lab_items_code, lo.lab_order_result, lh.order_date
            FROM lab_head lh
            JOIN lab_order lo ON lh.lab_order_number = lo.lab_order_number
            WHERE lh.hn = '$safe_hn'
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

function hbv_apply_form_labs(&$labs, $form_hbsag, $form_antihbc) {
    if (!$labs['hbsag'] && hbv_is_meaningful_lab_text($form_hbsag)) {
        $labs['hbsag'] = ['lab_order_result' => trim($form_hbsag), 'order_date' => 'จากฟอร์ม'];
    }
    if (!$labs['antihbc'] && hbv_is_meaningful_lab_text($form_antihbc)) {
        $labs['antihbc'] = ['lab_order_result' => trim($form_antihbc), 'order_date' => 'จากฟอร์ม'];
    }
    $labs['has_screening'] = ($labs['hbsag'] !== null && $labs['antihbc'] !== null);
}

function hbv_get_antiviral($con_hosxp, $hn, $chemo_date, $antiviral_list, $co_med_months, $post_days) {
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
        while ($row = mysqli_fetch_assoc($res)) $drugs[] = $row;
    }
    return $drugs;
}

$raw = json_decode(file_get_contents('php://input'), true);
$hn = trim($raw['hn'] ?? '');
$chemo_date = trim($raw['chemo_date'] ?? date('Y-m-d'));
$icodes = isset($raw['icodes']) && is_array($raw['icodes']) ? $raw['icodes'] : [];
$form_hbsag = trim($raw['lab_hbsag'] ?? '');
$form_antihbc = trim($raw['lab_antihbc'] ?? '');

if ($hn === '' || empty($icodes)) {
    echo json_encode(['status' => 'success', 'alerts' => []]);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $chemo_date)) {
    $chemo_date = date('Y-m-d');
}

$co_med_months = 6;
$lab_screen_months = 12;
$post_chemo_days = 7;

$high_risk_map = [];
$moderate_risk_map = [];
$antiviral_icodes = [];

$q_settings = mysqli_query($con, "SELECT monitor_type, icode, drug_name
                                  FROM monitor_hbv_settings
                                  WHERE monitor_type IN ('hbv_required_drug', 'hbv_high_risk_chemo', 'hbv_moderate_risk_chemo')");
while ($s = mysqli_fetch_assoc($q_settings)) {
    $icode = trim((string)$s['icode']);
    if ($icode === '') continue;
    if ($s['monitor_type'] === 'hbv_required_drug') $antiviral_icodes[] = "'" . mysqli_real_escape_string($con, $icode) . "'";
    if ($s['monitor_type'] === 'hbv_high_risk_chemo') $high_risk_map[$icode] = $s['drug_name'];
    if ($s['monitor_type'] === 'hbv_moderate_risk_chemo') $moderate_risk_map[$icode] = $s['drug_name'];
}

$has_high = false;
$has_moderate = false;
$selected_high = [];
$selected_moderate = [];
foreach ($icodes as $icode_raw) {
    $icode = trim((string)$icode_raw);
    if ($icode === '') continue;
    if (isset($high_risk_map[$icode])) {
        $has_high = true;
        $selected_high[] = $high_risk_map[$icode];
    }
    if (isset($moderate_risk_map[$icode])) {
        $has_moderate = true;
        $selected_moderate[] = $moderate_risk_map[$icode];
    }
}

if (!$has_high && !$has_moderate) {
    echo json_encode(['status' => 'success', 'alerts' => []]);
    exit;
}

if (empty($antiviral_icodes)) $antiviral_icodes = ["''"];
$antiviral_list = implode(',', $antiviral_icodes);

$q_config = mysqli_query($con, "SELECT config_name, config_value FROM sys_config WHERE config_name IN ('h_host', 'h_user', 'h_pass', 'h_db', 'h_code_hbsag', 'h_code_antihbc')");
$hos_cfg = [];
while ($r = mysqli_fetch_assoc($q_config)) $hos_cfg[$r['config_name']] = $r['config_value'];

$code_hbsag = trim($hos_cfg['h_code_hbsag'] ?? '');
$code_antihbc = trim($hos_cfg['h_code_antihbc'] ?? '');

$alerts = [];
$labs = ['hbsag' => null, 'antihbc' => null, 'has_screening' => false];
$antiviral = [];
$has_antiviral = false;

$con_hosxp = @mysqli_connect($hos_cfg['h_host'] ?? '', $hos_cfg['h_user'] ?? '', $hos_cfg['h_pass'] ?? '', $hos_cfg['h_db'] ?? '');
if ($con_hosxp) {
    mysqli_set_charset($con_hosxp, "utf8");
    $labs = hbv_get_latest_labs($con_hosxp, $hn, $chemo_date, $lab_screen_months, $code_hbsag, $code_antihbc);
    $antiviral = hbv_get_antiviral($con_hosxp, $hn, $chemo_date, $antiviral_list, $co_med_months, $post_chemo_days);
    $has_antiviral = !empty($antiviral);
    mysqli_close($con_hosxp);
}

hbv_apply_form_labs($labs, $form_hbsag, $form_antihbc);

$hbsag_pos = ($labs['hbsag'] && hbv_is_positive_text($labs['hbsag']['lab_order_result']));
$hbsag_neg = ($labs['hbsag'] && !hbv_is_positive_text($labs['hbsag']['lab_order_result']));
$antihbc_pos = ($labs['antihbc'] && hbv_is_positive_text($labs['antihbc']['lab_order_result']));

$risk_label = $has_high ? 'ยาเสี่ยงสูง' : 'ยาเสี่ยงปานกลาง';
$risk_items = $has_high ? $selected_high : $selected_moderate;
$risk_text = implode(', ', array_unique($risk_items));

if (!$labs['has_screening']) {
    $is_hard = $has_high;
    $alerts[] = [
        'level' => $is_hard ? 'hard' : 'soft',
        'type' => $is_hard ? 'HBV Hard Alert' : 'HBV Soft Alert',
        'title' => $is_hard ? 'Hard: ยังไม่คัดกรอง Lab HBV' : 'Soft: ยังไม่คัดกรอง Lab HBV',
        'message' => "ไม่พบผล HBsAg/Anti-HBc ภายใน {$lab_screen_months} เดือนก่อนวันให้คีโม ({$chemo_date}) ในเคส {$risk_label}" . ($risk_text !== '' ? " [{$risk_text}]" : '')
    ];
} elseif ($hbsag_pos && !$has_antiviral) {
    $alerts[] = [
        'level' => 'hard',
        'type' => 'HBV Hard Alert',
        'title' => 'Hard: HBsAg (+) ต้องให้ยาป้องกัน',
        'message' => "ผล HBsAg เป็นบวก และไม่พบยาป้องกันในช่วง {$co_med_months} เดือนก่อนถึง {$post_chemo_days} วันหลังให้คีโม"
    ];
} elseif ($antihbc_pos && ($hbsag_neg || !$labs['hbsag'])) {
    if ($has_high && !$has_antiviral) {
        $alerts[] = [
            'level' => 'hard',
            'type' => 'HBV Hard Alert',
            'title' => 'Hard: Anti-HBc (+) + ยาเสี่ยงสูง',
            'message' => "Anti-HBc (+) / HBsAg (-) + ยาเสี่ยงสูง และไม่พบยาป้องกันในช่วงที่กำหนด"
        ];
    } elseif (!$has_high && $has_moderate && !$has_antiviral) {
        $alerts[] = [
            'level' => 'soft',
            'type' => 'HBV Soft Alert',
            'title' => 'Soft: Anti-HBc (+) + ยาเสี่ยงปานกลาง',
            'message' => 'Anti-HBc (+) / HBsAg (-) + ยาเสี่ยงปานกลาง แนะนำติดตาม HBsAg และ ALT ทุก 3 เดือน'
        ];
    }
}

echo json_encode(['status' => 'success', 'alerts' => $alerts]);
exit;
?>
