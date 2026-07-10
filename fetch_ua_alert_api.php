<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
include('connect.php');
include('hosxp_tmt_drug_helper.php');
mysqli_set_charset($con, "utf8");

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

function ua_match_condition($cond_type, $cond_val, $lab_result) {
    return monitor_lab_match_condition($cond_type, $cond_val, $lab_result);
}

$raw = json_decode(file_get_contents('php://input'), true);
$hn = trim($raw['hn'] ?? '');
$order_date = trim($raw['order_date'] ?? date('Y-m-d'));
$icodes = isset($raw['icodes']) && is_array($raw['icodes']) ? $raw['icodes'] : [];

if ($hn === '' || empty($icodes)) {
    echo json_encode(['status' => 'success', 'alerts' => []]);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $order_date)) {
    $order_date = date('Y-m-d');
}

$rules = [];
$q_rules = mysqli_query($con, "SELECT * FROM monitor_ua_settings");
if ($q_rules) {
    while ($row = mysqli_fetch_assoc($q_rules)) $rules[] = $row;
}
if (empty($rules)) {
    echo json_encode(['status' => 'success', 'alerts' => []]);
    exit;
}

$rule_map_by_icode = [];
foreach ($rules as $r) {
    $code = trim((string)$r['icode']);
    if ($code === '') continue;
    if (!isset($rule_map_by_icode[$code])) $rule_map_by_icode[$code] = [];
    $rule_map_by_icode[$code][] = $r;
}

$selected_icodes = [];
foreach ($icodes as $i) {
    $v = trim((string)$i);
    if ($v !== '' && isset($rule_map_by_icode[$v])) $selected_icodes[$v] = true;
}
if (empty($selected_icodes)) {
    echo json_encode(['status' => 'success', 'alerts' => []]);
    exit;
}

$q_config = mysqli_query($con, "SELECT config_name, config_value FROM sys_config WHERE config_name IN ('h_host', 'h_user', 'h_pass', 'h_db')");
$hos_cfg = [];
while ($r = mysqli_fetch_assoc($q_config)) $hos_cfg[$r['config_name']] = $r['config_value'];
$con_hosxp = @mysqli_connect($hos_cfg['h_host'] ?? '', $hos_cfg['h_user'] ?? '', $hos_cfg['h_pass'] ?? '', $hos_cfg['h_db'] ?? '');
if (!$con_hosxp) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อฐานข้อมูล HOSxP ได้']);
    exit;
}
mysqli_set_charset($con_hosxp, "utf8");

$hn_esc = mysqli_real_escape_string($con_hosxp, $hn);
$date_esc = mysqli_real_escape_string($con_hosxp, $order_date);

$icode_list = implode(',', array_map(function($code) use ($con_hosxp) {
    return "'" . mysqli_real_escape_string($con_hosxp, $code) . "'";
}, array_keys($selected_icodes)));

$sql_visits = "SELECT o.vn, o.icode, o.qty AS dose_qty, d.name AS drug_name
               FROM opitemrece o
               JOIN vn_stat v ON v.vn = o.vn
               LEFT JOIN drugitems d ON d.icode = o.icode
               WHERE o.hn = '$hn_esc'
                 AND v.vstdate = '$date_esc'
                 AND o.icode IN ($icode_list)";
$q_visits = mysqli_query($con_hosxp, $sql_visits);

$alerts = [];
$seen_keys = [];

if ($q_visits) {
    while ($ord = mysqli_fetch_assoc($q_visits)) {
        $icode = trim((string)$ord['icode']);
        $dose = floatval($ord['dose_qty']);
        $vn = trim((string)$ord['vn']);
        $drug_name = trim((string)($ord['drug_name'] ?? $icode));

        $matched_rules = $rule_map_by_icode[$icode] ?? [];
        foreach ($matched_rules as $rule) {
            $use_dose = !empty($rule['use_dose_condition']);
            if ($use_dose) {
                $dmin = $rule['min_dose'];
                $dmax = $rule['max_dose'];
                if ($dmin !== null && $dmin !== '' && floatval($dmin) > 0 && $dose < floatval($dmin)) continue;
                if ($dmax !== null && $dmax !== '' && floatval($dmax) > 0 && $dose > floatval($dmax)) continue;
            }

            $lab_code = mysqli_real_escape_string($con_hosxp, $rule['lab_code']);
            $vn_lab = mysqli_real_escape_string($con_hosxp, $vn);
            $sql_lab = "SELECT lo.lab_order_result, lh.order_date
                        FROM lab_order lo
                        JOIN lab_head lh ON lh.lab_order_number = lo.lab_order_number
                        WHERE lh.vn = '$vn_lab'
                          AND lo.lab_items_code = '$lab_code'
                        ORDER BY lh.order_date DESC, lh.order_time DESC
                        LIMIT 1";
            $q_lab = mysqli_query($con_hosxp, $sql_lab);
            if (!$q_lab || mysqli_num_rows($q_lab) === 0) continue;
            $lab = mysqli_fetch_assoc($q_lab);
            $lab_result = trim((string)$lab['lab_order_result']);
            $cond_type = (string)$rule['condition_type'];
            $cond_val = (string)$rule['condition_value'];

            if (!ua_match_condition($cond_type, $cond_val, $lab_result)) continue;

            $lvl = ($rule['alert_level'] === 'hard') ? 'hard' : 'soft';
            $alert_type = ($lvl === 'hard') ? 'UA Hard Alert' : 'UA Soft Alert';
            $msg = $rule['alert_message'] . " | {$rule['lab_name']}: {$lab_result} | ยา {$drug_name} (Dose: {$dose})";
            $k = md5($alert_type . '|' . $msg);
            if (isset($seen_keys[$k])) continue;
            $seen_keys[$k] = true;

            $alerts[] = [
                'level' => $lvl,
                'type' => $alert_type,
                'title' => $alert_type,
                'message' => $msg
            ];
        }
    }
}

mysqli_close($con_hosxp);
echo json_encode(['status' => 'success', 'alerts' => $alerts]);
exit;
?>
