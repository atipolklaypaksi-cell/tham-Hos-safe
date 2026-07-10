<?php
session_start();
if (!isset($_SESSION['user_id']) && !isset($_SESSION['full_name'])) { header("Location: login.php"); exit; }

$organ = $MONITOR_LAB_ORGAN ?? null;
if ($organ === null) {
    $redirect = 'monitor_lab_renal.php';
    if (isset($_GET['tab'])) {
        $redirect .= '?tab=' . urlencode($_GET['tab']);
        if (isset($_GET['del'])) {
            $redirect .= '&del=' . urlencode($_GET['del']);
        }
    }
    header("Location: $redirect");
    exit;
}
if (!in_array($organ, ['renal', 'hepatic', 'other'], true)) {
    header("Location: monitor_lab_renal.php");
    exit;
}

include('connect.php');
include('hosxp_tmt_drug_helper.php');
mysqli_set_charset($con, "utf8");

function monitor_lab_resolve_drug_id($con, $icode) {
    $icode_esc = mysqli_real_escape_string($con, $icode);
    $q = mysqli_query($con, "SELECT id FROM drugitems WHERE icode = '$icode_esc' LIMIT 1");
    if ($q && $r = mysqli_fetch_assoc($q)) {
        return (int)$r['id'];
    }
    return 0;
}

function monitor_lab_parse_drug_post($con) {
    $parsed = hosxp_parse_drug_option($_POST['drug_hosxp'] ?? '');
    if ($parsed['icode'] === '') {
        return null;
    }
    return [
        'icode' => mysqli_real_escape_string($con, $parsed['icode']),
        'name' => mysqli_real_escape_string($con, $parsed['name']),
        'drug_id' => monitor_lab_resolve_drug_id($con, $parsed['icode']),
    ];
}

function monitor_lab_parse_lab_post($con) {
    $parsed = hosxp_parse_lab_option($_POST['lab_hosxp'] ?? '');
    if ($parsed['code'] === '') {
        return null;
    }
    $codes = monitor_lab_parse_codes($parsed['code']);
    $extra = monitor_lab_parse_codes($_POST['lab_extra_codes'] ?? '');
    if (!empty($extra)) {
        $codes = array_values(array_unique(array_merge($codes, $extra)));
    }
    return [
        'code' => mysqli_real_escape_string($con, implode(',', $codes)),
        'name' => mysqli_real_escape_string($con, $parsed['name']),
    ];
}

function monitor_lab_allowed_condition_types() {
    return ['range', 'regex', 'contains', 'less_than', 'greater_than'];
}

function monitor_lab_parse_condition_post($con) {
    $type = trim($_POST['lab_condition_type'] ?? 'range');
    if (!in_array($type, monitor_lab_allowed_condition_types(), true)) {
        $type = 'range';
    }
    $value = trim($_POST['lab_condition_value'] ?? '');
    if ($type !== 'range' && $value === '') {
        return null;
    }
    return [
        'type' => mysqli_real_escape_string($con, $type),
        'value' => mysqli_real_escape_string($con, $value),
    ];
}

$is_renal = ($organ === 'renal');
$is_hepatic = ($organ === 'hepatic');
$is_other = ($organ === 'other');
$page_file = $is_renal ? 'monitor_lab_renal.php' : ($is_hepatic ? 'monitor_lab_hepatic.php' : 'monitor_lab_other.php');
$page_title = $is_renal ? 'Monitor Lab ไต (Renal)' : ($is_hepatic ? 'Monitor Lab ตับ (Hepatic)' : 'Monitor Lab อื่นๆ');
$page_icon = $is_renal ? 'fa-heartbeat text-primary' : ($is_hepatic ? 'fa-lungs text-warning' : 'fa-vial text-info');
$lab_value_col = $is_renal ? 'ค่า GFR ล่าสุด' : ($is_hepatic ? 'ค่าตับล่าสุด' : 'ค่า Lab ล่าสุด');
$table_class = $is_renal ? 'table-renal' : ($is_hepatic ? 'table-hepatic' : 'table-other');
$form_border = $is_renal ? 'border-primary' : ($is_hepatic ? 'border-warning' : 'border-info');
$form_header_bg = $is_renal ? 'bg-primary' : ($is_hepatic ? 'bg-warning text-dark' : 'bg-info text-dark');
$summary_border = $is_renal ? '#0d6efd' : ($is_hepatic ? '#ffc107' : '#0dcaf0');

function monitor_lab_page_url($query = '') {
    global $page_file;
    return $page_file . ($query !== '' ? '?' . $query : '');
}

$lab_settings_tab = (isset($_GET['tab']) && $_GET['tab'] === 'settings');

$lab_type_labels = [
    'GFR' => 'CrCl',
    'CKD_EPI' => 'CKD-EPI',
    'SERUM_CR' => 'Serum Cr',
    'Bilirubin' => 'T.Bili',
    'Bilirubin_xULN' => 'T.Bili×ULN',
    'DirectBilirubin' => 'D.Bili',
    'IndirectBilirubin' => 'I.Bili',
    'AST' => 'AST',
    'ALT' => 'ALT',
    'AST_xULN' => 'AST×ULN',
    'ALT_xULN' => 'ALT×ULN',
    'AST_ALT_xULN' => 'Max×ULN',
    'CUSTOM' => 'Lab อื่นๆ',
];

$renal_lab_types = ['GFR', 'CKD_EPI', 'SERUM_CR'];
$hepatic_lab_types = ['Bilirubin', 'Bilirubin_xULN', 'DirectBilirubin', 'IndirectBilirubin', 'AST', 'ALT', 'AST_xULN', 'ALT_xULN', 'AST_ALT_xULN'];
$other_lab_types = ['CUSTOM'];
$organ_lab_types = $is_renal ? $renal_lab_types : ($is_hepatic ? $hepatic_lab_types : $other_lab_types);
$organ_lab_types_sql = "'" . implode("','", array_map(function ($t) use ($con) {
    return mysqli_real_escape_string($con, $t);
}, $organ_lab_types)) . "'";

function buildDoseSql($con, $use_dose) {
    if (!$use_dose) {
        return ['dose_min' => 'NULL', 'dose_max' => 'NULL', 'dose_unit' => 'NULL'];
    }
    $dose_min = mysqli_real_escape_string($con, $_POST['dose_min'] ?? '');
    $dose_max = mysqli_real_escape_string($con, $_POST['dose_max'] ?? '');
    return [
        'dose_min' => ($dose_min !== '') ? "'$dose_min'" : 'NULL',
        'dose_max' => ($dose_max !== '') ? "'$dose_max'" : 'NULL',
        'dose_unit' => "'mg'",
    ];
}

function buildPatientExtraSql($con, $is_renal) {
    if (!$is_renal) {
        return [
            'use_age' => '0', 'age_min' => 'NULL', 'age_max' => 'NULL',
            'use_weight' => '0', 'weight_min' => 'NULL', 'weight_max' => 'NULL',
        ];
    }
    $use_age = isset($_POST['use_age_condition']) ? 1 : 0;
    $use_weight = isset($_POST['use_weight_condition']) ? 1 : 0;
    $age_min = mysqli_real_escape_string($con, $_POST['age_min'] ?? '');
    $age_max = mysqli_real_escape_string($con, $_POST['age_max'] ?? '');
    $weight_min = mysqli_real_escape_string($con, $_POST['weight_min'] ?? '');
    $weight_max = mysqli_real_escape_string($con, $_POST['weight_max'] ?? '');
    return [
        'use_age' => "'$use_age'",
        'age_min' => ($age_min !== '') ? "'$age_min'" : 'NULL',
        'age_max' => ($age_max !== '') ? "'$age_max'" : 'NULL',
        'use_weight' => "'$use_weight'",
        'weight_min' => ($weight_min !== '') ? "'$weight_min'" : 'NULL',
        'weight_max' => ($weight_max !== '') ? "'$weight_max'" : 'NULL',
    ];
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add') {
    $lab_type_raw = $is_other ? 'CUSTOM' : ($_POST['lab_type'] ?? '');
    if (!in_array($lab_type_raw, $organ_lab_types, true)) {
        $_SESSION['error'] = 'ประเภท Lab ไม่ถูกต้องสำหรับหน้านี้';
        header("Location: " . monitor_lab_page_url('tab=settings')); exit;
    }
    $drug = monitor_lab_parse_drug_post($con);
    if ($drug === null) {
        $_SESSION['error'] = 'กรุณาเลือกรายการยาจาก HOSxP';
        header("Location: " . monitor_lab_page_url('tab=settings')); exit;
    }
    $lab_items_sql = '';
    $lab_cond_sql = '';
    $lab_cond_vals = '';
    if ($is_other) {
        $lab_item = monitor_lab_parse_lab_post($con);
        if ($lab_item === null) {
            $_SESSION['error'] = 'กรุณาเลือกรายการ Lab จาก HOSxP';
            header("Location: " . monitor_lab_page_url('tab=settings')); exit;
        }
        $lab_items_sql = ", lab_items_code, lab_items_name";
        $lab_items_vals = ", '{$lab_item['code']}', '{$lab_item['name']}'";
        $lab_cond = monitor_lab_parse_condition_post($con);
        if ($lab_cond === null) {
            $_SESSION['error'] = 'กรุณาระบุค่าที่ต้องการดักจับสำหรับเงื่อนไขข้อความ';
            header("Location: " . monitor_lab_page_url('tab=settings')); exit;
        }
        $lab_cond_sql = ', lab_condition_type, lab_condition_value';
        $lab_cond_vals = ", '{$lab_cond['type']}', " . ($lab_cond['value'] !== '' ? "'{$lab_cond['value']}'" : 'NULL');
    } else {
        $lab_items_vals = '';
    }
    $lab_type = mysqli_real_escape_string($con, $lab_type_raw);
    $min_val = mysqli_real_escape_string($con, $_POST['min_val'] ?? '0');
    $max_val = mysqli_real_escape_string($con, $_POST['max_val']);
    $action_type = mysqli_real_escape_string($con, $_POST['action_type']);
    $action_value = mysqli_real_escape_string($con, $_POST['action_value'] ?? 0);
    $msg = mysqli_real_escape_string($con, $_POST['suggestion_msg']);
    $alert_level = (($_POST['alert_level'] ?? '') === 'hard') ? 'hard' : 'soft';
    $use_dose = isset($_POST['use_dose_condition']) ? 1 : 0;
    $dose = buildDoseSql($con, $use_dose);
    $extra = buildPatientExtraSql($con, $is_renal);

    $sql = "INSERT INTO drug_safety_rules (drug_id, drug_icode, drug_name, lab_type, min_val, max_val, use_dose_condition, dose_min, dose_max, dose_unit, use_age_condition, age_min, age_max, use_weight_condition, weight_min, weight_max, action_type, action_value, suggestion_msg, alert_level, active_status{$lab_items_sql}{$lab_cond_sql}) 
            VALUES ('{$drug['drug_id']}', '{$drug['icode']}', '{$drug['name']}', '$lab_type', '$min_val', '$max_val', '$use_dose', {$dose['dose_min']}, {$dose['dose_max']}, {$dose['dose_unit']}, {$extra['use_age']}, {$extra['age_min']}, {$extra['age_max']}, {$extra['use_weight']}, {$extra['weight_min']}, {$extra['weight_max']}, '$action_type', '$action_value', '$msg', '$alert_level', 1{$lab_items_vals}{$lab_cond_vals})";
    if (mysqli_query($con, $sql)) {
        $_SESSION['msg'] = "บันทึกกฎความปลอดภัยสำเร็จ";
    } else {
        $_SESSION['error'] = "เกิดข้อผิดพลาด: " . mysqli_error($con);
    }
    header("Location: " . monitor_lab_page_url('tab=settings')); exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'edit') {
    $id = (int)($_POST['rule_id'] ?? 0);
    if ($id <= 0) {
        $_SESSION['error'] = "ไม่พบรหัสกฎที่ต้องการแก้ไข";
        header("Location: " . monitor_lab_page_url('tab=settings')); exit;
    }
    $lab_type_raw = $is_other ? 'CUSTOM' : ($_POST['lab_type'] ?? '');
    if (!in_array($lab_type_raw, $organ_lab_types, true)) {
        $_SESSION['error'] = 'ประเภท Lab ไม่ถูกต้องสำหรับหน้านี้';
        header("Location: " . monitor_lab_page_url('tab=settings')); exit;
    }
    $drug = monitor_lab_parse_drug_post($con);
    if ($drug === null) {
        $_SESSION['error'] = 'กรุณาเลือกรายการยาจาก HOSxP';
        header("Location: " . monitor_lab_page_url('tab=settings')); exit;
    }
    $lab_items_set = '';
    $lab_cond_set = '';
    if ($is_other) {
        $lab_item = monitor_lab_parse_lab_post($con);
        if ($lab_item === null) {
            $_SESSION['error'] = 'กรุณาเลือกรายการ Lab จาก HOSxP';
            header("Location: " . monitor_lab_page_url('tab=settings')); exit;
        }
        $lab_items_set = ", lab_items_code='{$lab_item['code']}', lab_items_name='{$lab_item['name']}'";
        $lab_cond = monitor_lab_parse_condition_post($con);
        if ($lab_cond === null) {
            $_SESSION['error'] = 'กรุณาระบุค่าที่ต้องการดักจับสำหรับเงื่อนไขข้อความ';
            header("Location: " . monitor_lab_page_url('tab=settings')); exit;
        }
        $lab_cond_set = ", lab_condition_type='{$lab_cond['type']}', lab_condition_value=" . ($lab_cond['value'] !== '' ? "'{$lab_cond['value']}'" : 'NULL');
    }
    $lab_type = mysqli_real_escape_string($con, $lab_type_raw);
    $min_val = mysqli_real_escape_string($con, $_POST['min_val'] ?? '0');
    $max_val = mysqli_real_escape_string($con, $_POST['max_val']);
    $action_type = mysqli_real_escape_string($con, $_POST['action_type']);
    $action_value = mysqli_real_escape_string($con, $_POST['action_value'] ?? 0);
    $msg = mysqli_real_escape_string($con, $_POST['suggestion_msg']);
    $alert_level = (($_POST['alert_level'] ?? '') === 'hard') ? 'hard' : 'soft';
    $use_dose = isset($_POST['use_dose_condition']) ? 1 : 0;
    $dose = buildDoseSql($con, $use_dose);
    $extra = buildPatientExtraSql($con, $is_renal);

    $sql = "UPDATE drug_safety_rules SET 
            drug_id='{$drug['drug_id']}', drug_icode='{$drug['icode']}', drug_name='{$drug['name']}', lab_type='$lab_type', min_val='$min_val', max_val='$max_val',
            use_dose_condition='$use_dose', dose_min={$dose['dose_min']}, dose_max={$dose['dose_max']}, dose_unit={$dose['dose_unit']},
            use_age_condition={$extra['use_age']}, age_min={$extra['age_min']}, age_max={$extra['age_max']},
            use_weight_condition={$extra['use_weight']}, weight_min={$extra['weight_min']}, weight_max={$extra['weight_max']},
            action_type='$action_type', action_value='$action_value', suggestion_msg='$msg', alert_level='$alert_level'{$lab_items_set}{$lab_cond_set}
            WHERE id='$id'";
    if (mysqli_query($con, $sql)) {
        $_SESSION['msg'] = "แก้ไขกฎความปลอดภัยสำเร็จ";
    } else {
        $_SESSION['error'] = "เกิดข้อผิดพลาด: " . mysqli_error($con);
    }
    header("Location: " . monitor_lab_page_url('tab=settings')); exit;
}

if (isset($_GET['del'])) {
    $id = mysqli_real_escape_string($con, $_GET['del']);
    mysqli_query($con, "DELETE FROM drug_safety_rules WHERE id = '$id'");
    $_SESSION['msg'] = "ลบกฎความปลอดภัยสำเร็จ";
    header("Location: " . monitor_lab_page_url('tab=settings')); exit;
}

$has_drug_icode_col = ensure_drug_safety_rules_hosxp_columns($con);
$has_alert_level_col = ensure_drug_safety_rules_alert_level_column($con);
$has_lab_items_col = ensure_drug_safety_rules_lab_items_columns($con);
$has_lab_condition_col = ensure_drug_safety_rules_lab_condition_columns($con);
$has_patient_extra_col = ensure_drug_safety_rules_patient_extra_columns($con);
$lab_condition_labels = [
    'range' => 'ช่วงตัวเลข',
    'regex' => 'Regex (ข้อความ)',
    'contains' => 'มีคำว่า... (Text)',
    'less_than' => 'น้อยกว่า <',
    'greater_than' => 'มากกว่า >',
];

function monitor_lab_alert_level_from_row($row) {
    return monitor_lab_alert_level_from_rule($row);
}

function monitor_lab_alert_level_badge($level) {
    if ($level === 'hard') {
        return '<span class="badge bg-danger">Hard Alert</span>';
    }
    return '<span class="badge bg-warning text-dark">Soft Alert</span>';
}

$hosxp_drugs = [];
$has_hosxp_drugs = false;
$opt_hosxp_drugs = "<option value=''>- เลือกรายการยาจาก HOSxP (TMT) -</option>";
if ($lab_settings_tab) {
    $con_hosxp = hosxp_connect($con);
    if ($con_hosxp) {
        $hosxp_drugs = hosxp_fetch_active_drugs_tmt($con_hosxp);
        mysqli_close($con_hosxp);
        if (!empty($hosxp_drugs)) {
            $has_hosxp_drugs = true;
            $opt_hosxp_drugs = hosxp_build_drug_options_html($hosxp_drugs, '- เลือกรายการยาจาก HOSxP (TMT) -');
        }
    }
}

$dose_unit_fixed = 'mg';

function monitor_lab_rules_sql($organ_lab_types_sql, $has_drug_icode_col) {
    if ($has_drug_icode_col) {
        return "SELECT r.*,
                COALESCE(NULLIF(TRIM(r.drug_name), ''), d.name) AS display_drug_name,
                COALESCE(NULLIF(TRIM(r.drug_icode), ''), d.icode) AS display_icode
            FROM drug_safety_rules r
            LEFT JOIN drugitems d ON r.drug_id = d.id
            WHERE r.lab_type IN ($organ_lab_types_sql)
            ORDER BY display_drug_name, r.lab_type, r.min_val";
    }
    return "SELECT r.*, d.name AS display_drug_name, d.icode AS display_icode
        FROM drug_safety_rules r
        INNER JOIN drugitems d ON r.drug_id = d.id
        WHERE r.lab_type IN ($organ_lab_types_sql)
        ORDER BY d.name, r.lab_type, r.min_val";
}

function monitor_lab_active_rules_sql($organ_lab_types_sql, $has_drug_icode_col, $has_lab_items_col = false, $has_lab_condition_col = false) {
    $lab_cols = $has_lab_items_col ? ', r.lab_items_code, r.lab_items_name' : '';
    $lab_cols .= $has_lab_condition_col ? ', r.lab_condition_type, r.lab_condition_value' : '';
    if ($has_drug_icode_col) {
        return "SELECT r.id,
                COALESCE(NULLIF(TRIM(r.drug_name), ''), d.name) AS drug_name,
                COALESCE(NULLIF(TRIM(r.drug_icode), ''), d.icode) AS icode,
                r.lab_type, r.min_val, r.max_val, r.alert_level{$lab_cols}
            FROM drug_safety_rules r
            LEFT JOIN drugitems d ON r.drug_id = d.id
            WHERE r.active_status = 1
              AND r.lab_type IN ($organ_lab_types_sql)
              AND COALESCE(NULLIF(TRIM(r.drug_icode), ''), d.icode, '') <> ''
            ORDER BY drug_name, r.lab_type, r.min_val";
    }
    return "SELECT r.id, d.name AS drug_name, d.icode AS icode,
                r.lab_type, r.min_val, r.max_val, r.alert_level{$lab_cols}
            FROM drug_safety_rules r
            INNER JOIN drugitems d ON r.drug_id = d.id
            WHERE r.active_status = 1
              AND r.lab_type IN ($organ_lab_types_sql)
            ORDER BY d.name, r.lab_type, r.min_val";
}

$monitor_rules = [];
if (!$lab_settings_tab) {
    $q_mon_rules = mysqli_query($con, monitor_lab_active_rules_sql($organ_lab_types_sql, $has_drug_icode_col, $has_lab_items_col, $has_lab_condition_col));
    if ($q_mon_rules) {
        while ($mr = mysqli_fetch_assoc($q_mon_rules)) {
            $monitor_rules[] = $mr;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($page_title); ?> - THAM CYTO</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
    <link href="css/select2.min.css" rel="stylesheet">
    <link href="css/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="css/flatpickr.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', Tahoma, sans-serif; background-color: #f4f7f6; }
        .nav-tabs .nav-link.active.tab-renal { color: #0d6efd; border-bottom: 3px solid #0d6efd; font-weight: bold; }
        .nav-tabs .nav-link.active.tab-hepatic { color: #ffc107; border-bottom: 3px solid #ffc107; font-weight: bold; text-shadow: 0 0 1px #ffc107;}
        .nav-tabs .nav-link.active.tab-other { color: #0dcaf0; border-bottom: 3px solid #0dcaf0; font-weight: bold; }
        .table-renal thead { background-color: #e8f4fd; color: #0d6efd; }
        .table-hepatic thead { background-color: #fffcf0; color: #d39e00; }
        .table-other thead { background-color: #e8fcff; color: #0aa2c0; }
        .btn-glow { animation: glow 1.2s infinite alternate; }
        @keyframes glow { from { box-shadow: 0 0 2px #0dcaf0; } to { box-shadow: 0 0 10px #0dcaf0; } }
        .dose-box { background-color: #f8f9fa; border-radius: 6px; padding: 6px; margin-top: 5px; border: 1px solid #e9ecef; }
        .chart-container { position: relative; height: 200px; width: 100%; }
        .summary-card { border-radius: 12px; border: none; border-top: 4px solid <?php echo $summary_border; ?>; }
        .dose-fields { transition: opacity 0.2s; }
        .dose-fields.disabled-section { opacity: 0.45; pointer-events: none; }
        .age-fields, .weight-fields { transition: opacity 0.2s; }
        .age-fields.disabled-section, .weight-fields.disabled-section { opacity: 0.45; pointer-events: none; }
        #formCard.edit-mode .card-header { background-color: #0d6efd !important; border-color: #0d6efd; }
        #formCard.copy-mode .card-header { background-color: #6c757d !important; border-color: #6c757d; }
        tr.editing-row { background-color: #fff3cd !important; }
        .filter-box { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; }
        .section-hard { border-left: 4px solid #dc3545; padding-left: 12px; }
        .section-soft { border-left: 4px solid #ffc107; padding-left: 12px; }
        #labRuleFilterBox .lab-rule-cb-label { cursor: pointer; }
    </style>
</head>
<body>

<div class="wrapper">
    <?php include('sidebar.php'); ?>
    <div id="content" class="content">
        <div class="container-fluid py-4">

            <h4 class="fw-bold mb-4 text-dark"><i class="fas <?php echo $page_icon; ?> me-2"></i><?php echo htmlspecialchars($page_title); ?></h4>

            <ul class="nav nav-tabs fw-bold mb-0" id="labMainTab" role="tablist">
                <li class="nav-item">
                    <a class="nav-link <?php echo !$lab_settings_tab ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(monitor_lab_page_url()); ?>"><i class="fas fa-desktop me-1"></i> รายการเฝ้าระวังหน้างาน</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $lab_settings_tab ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(monitor_lab_page_url('tab=settings')); ?>"><i class="fas fa-cogs me-1"></i> ตั้งค่ายาเฝ้าระวัง</a>
                </li>
            </ul>

            <?php if (!$lab_settings_tab): ?>

            <div class="tab-content border border-top-0">
                <div class="p-0" id="mainMonitor">

            <div class="filter-box p-3 mb-3">
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small fw-bold mb-1">ช่วงวันที่สั่งยา ตั้งแต่</label>
                        <input type="text" id="filter_start" class="form-control form-control-sm bg-white">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold mb-1">ถึงวันที่</label>
                        <input type="text" id="filter_end" class="form-control form-control-sm bg-white">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold mb-1">ช่วงเวลาเร็ว</label>
                        <div class="btn-group w-100" role="group">
                            <button type="button" class="btn btn-outline-secondary btn-sm fw-bold" onclick="setLabDateRange(7)">7 วัน</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm fw-bold" onclick="setLabDateRange(30)">1 เดือน</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm fw-bold" onclick="setLabDateRange(90)">3 เดือน</button>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <button class="btn btn-primary btn-sm fw-bold shadow-sm w-100" type="button" onclick="fetchLabMonitorData()">
                            <i class="fas fa-sync-alt me-1"></i> ประมวลผล / ดึงผล Lab
                        </button>
                    </div>
                </div>
                <div class="small text-muted mt-2"><i class="fas fa-info-circle me-1"></i> แนะนำเริ่มที่ <b>1 เดือน</b> — ช่วงยาวขึ้นจะใช้เวลานานขึ้น</div>
                <div id="reportMeta" class="alert alert-info py-2 px-3 mt-2 mb-0 small fw-bold d-none"></div>
            </div>

            <div class="border rounded p-3 mb-3" id="labRuleFilterBox">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                    <label class="form-label fw-bold small mb-0">
                        <i class="fas fa-filter me-1 text-primary"></i> เลือกรายการยาที่ต้องการรายงาน
                        <span class="text-muted fw-normal" id="labRuleSelectedCount"></span>
                    </label>
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-outline-primary fw-bold" onclick="labSelectAllRules(true)">เลือกทั้งหมด</button>
                        <button type="button" class="btn btn-outline-secondary fw-bold" onclick="labSelectAllRules(false)">ยกเลิกทั้งหมด</button>
                    </div>
                </div>
                <?php if (empty($monitor_rules)): ?>
                <div class="small text-muted">ยังไม่มีกฎที่เปิดใช้งาน — ไปที่แท็บ <b>ตั้งค่ายาเฝ้าระวัง</b> เพื่อเพิ่มกฎ</div>
                <?php else: ?>
                <div class="row g-2" id="labRuleCheckboxes">
                    <?php foreach ($monitor_rules as $mr):
                        $lvl = monitor_lab_alert_level_from_row($mr);
                        $lvl_badge = $lvl === 'hard'
                            ? '<span class="badge bg-danger ms-1">Hard</span>'
                            : '<span class="badge bg-warning text-dark ms-1">Soft</span>';
                        $lab_lbl = htmlspecialchars($lab_type_labels[$mr['lab_type']] ?? $mr['lab_type']);
                        $lab_code_disp = !empty($mr['lab_items_code']) ? htmlspecialchars($mr['lab_items_code']) : '';
                        $lab_name_disp = !empty($mr['lab_items_name']) ? htmlspecialchars($mr['lab_items_name']) : '';
                        $cond_type_mr = $mr['lab_condition_type'] ?? 'range';
                        $cond_val_mr = $mr['lab_condition_value'] ?? '';
                    ?>
                    <div class="col-md-6 col-xl-4">
                        <div class="form-check border rounded px-2 py-1 bg-white h-100">
                            <input class="form-check-input lab-rule-cb" type="checkbox"
                                   value="<?php echo (int)$mr['id']; ?>"
                                   id="lab_rule_cb_<?php echo (int)$mr['id']; ?>" checked>
                            <label class="form-check-label small lab-rule-cb-label" for="lab_rule_cb_<?php echo (int)$mr['id']; ?>">
                                <span class="text-primary fw-bold"><?php echo htmlspecialchars($mr['drug_name']); ?></span>
                                <?php if ($is_other && $lab_code_disp !== ''): ?>
                                <span class="badge bg-secondary ms-1"><?php echo $lab_code_disp; ?></span>
                                <span class="text-dark"><?php echo $lab_name_disp; ?></span>
                                <?php if ($cond_type_mr !== 'range' && $cond_val_mr !== ''): ?>
                                <br><span class="text-primary"><?php echo htmlspecialchars($lab_condition_labels[$cond_type_mr] ?? $cond_type_mr); ?>: <?php echo htmlspecialchars($cond_val_mr); ?></span>
                                <?php else: ?>
                                <span class="text-muted"> <?php echo htmlspecialchars($mr['min_val']); ?>–<?php echo htmlspecialchars($mr['max_val']); ?></span>
                                <?php endif; ?>
                                <?php else: ?>
                                <span class="badge bg-info text-dark ms-1"><?php echo $lab_lbl; ?></span>
                                <?php endif; ?>
                                <span class="text-muted"><?php echo htmlspecialchars($mr['min_val']); ?>–<?php echo htmlspecialchars($mr['max_val']); ?></span>
                                <?php echo $lvl_badge; ?>
                            </label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="card shadow-sm mb-4 summary-card">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-5 border-end">
                            <h6 class="fw-bold text-success mb-3"><i class="fas fa-chart-pie me-2"></i>สรุปการปรับขนาดยา (Dose Adjustment)</h6>
                            <div class="chart-container">
                                <canvas id="summaryChart"></canvas>
                            </div>
                        </div>
                        <div class="col-md-7 ps-4">
                            <h6 class="fw-bold text-secondary mb-3"><i class="fas fa-clipboard-check me-2"></i>สรุปผลการตรวจ</h6>
                            <div class="row text-center">
                                <div class="col-6">
                                    <div class="p-3 bg-success bg-opacity-10 rounded text-success fw-bold border border-success">
                                        ผ่านเกณฑ์ (≤ 5%): <span id="count_pass" class="fs-4">0</span>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="p-3 bg-danger bg-opacity-10 rounded text-danger fw-bold border border-danger">
                                        ไม่ผ่านเกณฑ์ (> 5%): <span id="count_fail" class="fs-4">0</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="section-hard mb-4">
                <h6 class="fw-bold text-danger mb-2"><i class="fas fa-exclamation-circle me-2"></i>Hard Alert <span id="hardCount" class="badge bg-danger">0</span></h6>
                <div class="bg-white border p-3 rounded shadow-sm">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle border mb-0 <?php echo $table_class; ?>">
                            <thead class="text-center">
                                <tr>
                                    <th width="8%">HN</th>
                                    <th width="15%" class="text-start">ชื่อ-สกุล</th>
                                    <th width="22%" class="text-start">รายการยา / ขนาดยา (Dose)</th>
                                    <th width="12%"><?php echo htmlspecialchars($lab_value_col); ?></th>
                                    <th width="18%">เงื่อนไขที่ละเมิด</th>
                                    <th width="15%">สรุป (Dose)</th>
                                </tr>
                            </thead>
                            <tbody id="hardTbody">
                                <tr><td colspan="6" class="text-center py-4 text-muted">กดปุ่มประมวลผลเพื่อดึงข้อมูล</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="section-soft mb-4">
                <h6 class="fw-bold text-warning mb-2"><i class="fas fa-exclamation-triangle me-2"></i>Soft Alert <span id="softCount" class="badge bg-warning text-dark">0</span></h6>
                <div class="bg-white border p-3 rounded shadow-sm">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle border mb-0 <?php echo $table_class; ?>">
                            <thead class="text-center">
                                <tr>
                                    <th width="8%">HN</th>
                                    <th width="15%" class="text-start">ชื่อ-สกุล</th>
                                    <th width="22%" class="text-start">รายการยา / ขนาดยา (Dose)</th>
                                    <th width="12%"><?php echo htmlspecialchars($lab_value_col); ?></th>
                                    <th width="18%">เงื่อนไขที่ละเมิด</th>
                                    <th width="15%">สรุป (Dose)</th>
                                </tr>
                            </thead>
                            <tbody id="softTbody">
                                <tr><td colspan="6" class="text-center py-4 text-muted">กดปุ่มประมวลผลเพื่อดึงข้อมูล</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

                </div>
            </div>

            <?php else: ?>

            <div class="bg-white border border-top-0 p-4 rounded-bottom shadow-sm" id="mainSettings">

                    <?php if (isset($_SESSION['msg'])) { echo "<div class='alert alert-success fw-bold'><i class='fas fa-check-circle'></i> {$_SESSION['msg']}</div>"; unset($_SESSION['msg']); } ?>
                    <?php if (isset($_SESSION['error'])) { echo "<div class='alert alert-danger fw-bold'><i class='fas fa-times-circle'></i> {$_SESSION['error']}</div>"; unset($_SESSION['error']); } ?>

                    <div class="alert alert-light border small mb-4">
                        <i class="fas fa-info-circle text-primary me-2"></i>
                        <?php if ($is_renal): ?>
                        กำหนดกฎลดขนาดยาตามผล Lab ไต (CrCl / CKD-EPI / Serum Cr) — เลือก <b>รายการยาจาก HOSxP (TMT)</b> ใช้ประกอบการเฝ้าระวังในแท็บ <b>รายการเฝ้าระวังหน้างาน</b>
                        <br><span class="text-muted">CrCl คำนวณจาก Serum Cr · CKD-EPI / Serum Cr ดึงจากรหัส Lab ที่ตั้งใน hosxp_setting · รองรับเงื่อนไขเพิ่มเติม <b>อายุ และ/หรือ น้ำหนัก</b> (เช่น Apixaban: Serum Cr ≥1.5 และ อายุ ≥80 หรือ นน. ≤60)</span>
                        <?php elseif ($is_hepatic): ?>
                        กำหนดกฎลดขนาดยาตามผล Lab ตับ (Bilirubin / T.Bili×ULN / AST·ALT×ULN) — เลือก <b>รายการยาจาก HOSxP (TMT)</b> ใช้ประกอบการเฝ้าระวังในแท็บ <b>รายการเฝ้าระวังหน้างาน</b>
                        <br><span class="text-muted">สำหรับ AST/ALT×ULN ให้กำหนดช่วงเป็นจำนวนเท่า (เช่น ต่ำสุด 2 สูงสุด 10 = 2–10× ULN) — ตั้งค่า ULN ได้ที่ hosxp_setting</span>
                        <?php else: ?>
                        กำหนดกฎลดขนาดยาตามผล Lab อื่นๆ — เลือก <b>รายการยาจาก HOSxP (TMT)</b> และ <b>รายการ Lab จากตาราง lab_items</b> (แสดงรหัส Lab)
                        <br><span class="text-muted">รองรับ <b>ช่วงตัวเลข (min-max)</b> หรือ <b>เงื่อนไขข้อความ</b> แบบ Monitor UA — Regex / มีคำว่า / มากกว่า / น้อยกว่า</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!$has_drug_icode_col): ?>
                    <div class="alert alert-danger fw-bold mb-4">
                        <i class="fas fa-database me-2"></i>ไม่สามารถปรับโครงสร้างตาราง drug_safety_rules ได้ — ลองรัน <a href="upgrade.php">อัปเกรดระบบ</a>
                    </div>
                    <?php elseif (!$has_hosxp_drugs): ?>
                    <div class="alert alert-warning fw-bold mb-4">
                        <i class="fas fa-exclamation-triangle me-2"></i>ไม่สามารถดึงรายการยาจาก HOSxP ได้ — ตรวจสอบที่ <a href="hosxp_setting.php">ตั้งค่า HOSxP</a>
                    </div>
                    <?php elseif ($is_other && !$has_lab_items_col): ?>
                    <div class="alert alert-danger fw-bold mb-4">
                        <i class="fas fa-database me-2"></i>ยังไม่มีคอลัมน์ lab_items ในตาราง drug_safety_rules — ลองรัน <a href="upgrade.php">อัปเกรดระบบ</a>
                    </div>
                    <?php elseif ($is_other && !$has_lab_condition_col): ?>
                    <div class="alert alert-danger fw-bold mb-4">
                        <i class="fas fa-database me-2"></i>ยังไม่มีคอลัมน์ lab_condition ในตาราง drug_safety_rules — ลองรัน <a href="upgrade.php">อัปเกรดระบบ</a>
                    </div>
                    <?php elseif ($is_other): ?>
                    <div class="alert alert-light border small mb-4">
                        <i class="fas fa-lightbulb text-warning me-2"></i><b>เงื่อนไขข้อความ (แบบ Monitor UA):</b><br>
                        - <b>Regex:</b> รวมหลายรูปแบบด้วย <code>|</code> เช่น <code>2\+|3\+|4\+</code> ดักจับ 2+, 3+, 4+<br>
                        - <b>มีคำว่า:</b> หาคำเดียวต่อครั้ง — หลายคำแบบ OR ให้ใช้ Regex<br>
                        - <b>มากกว่า / น้อยกว่า:</b> เปรียบเทียบตัวเลขจากผล Lab (float)<br>
                        - <b>รหัส Lab ซ้ำซ้อน:</b> ถ้ารพ.ใช้หลาย code สำหรับรายการเดียวกัน (เช่น Potassium <code>132</code> และ <code>1100</code>) ให้ใส่รหัสเพิ่มเติมคั่นด้วย comma
                    </div>
                    <?php endif; ?>

                    <div class="card shadow-sm <?php echo $form_border; ?> mb-4" id="formCard">
                        <div class="card-header <?php echo $form_header_bg; ?> py-2 fw-bold" id="formCardHeader">
                            <i class="fas fa-plus-circle me-1" id="formCardIcon"></i> <span id="formCardTitle">เพิ่มเงื่อนไขใหม่</span>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="<?php echo htmlspecialchars(monitor_lab_page_url('tab=settings')); ?>" id="ruleForm">
                                <input type="hidden" name="action" id="form_action" value="add">
                                <input type="hidden" name="rule_id" id="rule_id" value="">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">เลือกรายการยา — จาก HOSxP (TMT)</label>
                                        <select name="drug_hosxp" id="drug_hosxp" class="form-select select2-hosxp" <?php echo ($has_drug_icode_col && $has_hosxp_drugs) ? 'required' : 'disabled'; ?>><?php echo $opt_hosxp_drugs; ?></select>
                                    </div>
                                    <?php if ($is_other): ?>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">เลือกรายการ Lab — จาก HOSxP (lab_items)</label>
                                        <select name="lab_hosxp" id="lab_hosxp" class="form-select select2-lab" <?php echo ($has_drug_icode_col && $has_lab_items_col) ? 'required' : 'disabled'; ?>></select>
                                        <label class="form-label small text-muted mt-2 mb-1">รหัส Lab เพิ่มเติม (ถ้ามีหลาย code ในรพ.)</label>
                                        <input type="text" name="lab_extra_codes" id="lab_extra_codes" class="form-control form-control-sm" placeholder="เช่น 1100 หรือ 1100,132">
                                        <input type="hidden" name="lab_type" value="CUSTOM">
                                    </div>
                                    <?php else: ?>
                                    <div class="col-md-2">
                                        <label class="form-label fw-bold">ผล Lab อ้างอิง</label>
                                        <select name="lab_type" id="lab_type" class="form-select border-primary" required>
                                            <?php if ($is_renal): ?>
                                            <option value="GFR">ค่าไต: CrCl (ml/min)</option>
                                            <option value="CKD_EPI">ค่าไต: CKD-EPI eGFR (ml/min/1.73m²)</option>
                                            <option value="SERUM_CR">ค่าไต: Serum Creatinine (mg/dL)</option>
                                            <?php else: ?>
                                            <option value="Bilirubin">ค่าตับ: T.Bilirubin (mg/dL)</option>
                                            <option value="Bilirubin_xULN">ค่าตับ: T.Bilirubin (เท่าของ ULN)</option>
                                            <option value="DirectBilirubin">ค่าตับ: Direct Bilirubin (mg/dL)</option>
                                            <option value="IndirectBilirubin">ค่าตับ: Indirect Bilirubin (mg/dL)</option>
                                            <option value="AST">ค่าตับ: AST (ค่าจริง U/L)</option>
                                            <option value="ALT">ค่าตับ: ALT (ค่าจริง U/L)</option>
                                            <option value="AST_xULN">ค่าตับ: AST (เท่าของ ULN)</option>
                                            <option value="ALT_xULN">ค่าตับ: ALT (เท่าของ ULN)</option>
                                            <option value="AST_ALT_xULN">ค่าตับ: AST/ALT สูงสุด (เท่าของ ULN)</option>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($is_other): ?>
                                    <div class="col-md-12">
                                        <div class="row g-2 align-items-end" id="otherConditionBlock">
                                            <div class="col-md-4">
                                                <label class="form-label fw-bold">รูปแบบเงื่อนไข</label>
                                                <select name="lab_condition_type" id="lab_condition_type" class="form-select border-info">
                                                    <?php foreach ($lab_condition_labels as $ck => $clbl): ?>
                                                    <option value="<?php echo htmlspecialchars($ck); ?>"><?php echo htmlspecialchars($clbl); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-8" id="otherTextCondWrap">
                                                <label class="form-label fw-bold">ค่าที่ต้องการดักจับ</label>
                                                <input type="text" class="form-control" name="lab_condition_value" id="lab_condition_value" placeholder="เช่น 2\+|3\+|4\+ หรือ Positive">
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    <div class="col-md-12">
                                        <div id="lab_range_hint" class="form-text text-primary fw-bold"></div>
                                    </div>
                                    <div class="col-md-2 other-range-field">
                                        <label class="form-label fw-bold text-danger">ช่วง Lab ต่ำสุด</label>
                                        <input type="number" step="0.01" class="form-control" name="min_val" id="min_val" placeholder="เช่น 45" <?php echo $is_other ? '' : 'required'; ?>>
                                    </div>
                                    <div class="col-md-2 other-range-field">
                                        <label class="form-label fw-bold text-danger">ช่วง Lab สูงสุด</label>
                                        <input type="number" step="0.01" class="form-control" name="max_val" id="max_val" placeholder="เช่น 60" <?php echo $is_other ? '' : 'required'; ?>>
                                    </div>
                                    <div class="col-md-3 other-range-field">
                                        <label class="form-label fw-bold text-success">รูปแบบการปรับลด</label>
                                        <select name="action_type" id="action_type" class="form-select border-success" <?php echo $is_other ? '' : 'required'; ?>>
                                            <option value="percent">ลดลงตามเปอร์เซ็นต์ (%)</option>
                                            <option value="fixed">กำหนด Fix Dose คงที่</option>
                                            <option value="omit">OMIT (หลีกเลี่ยงการใช้)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2 other-range-field">
                                        <label class="form-label fw-bold">ตัวเลข (% หรือ Fix)</label>
                                        <input type="number" step="0.01" class="form-control" name="action_value" id="action_value" placeholder="เช่น 25" value="0">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label fw-bold">ระดับการเตือน</label>
                                        <select name="alert_level" id="alert_level" class="form-select fw-bold" required>
                                            <option value="soft" class="text-warning">Soft Alert</option>
                                            <option value="hard" class="text-danger">Hard Alert</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">ข้อความแนะนำแพทย์ (แสดงที่ป้ายเตือน)</label>
                                        <input type="text" class="form-control" name="suggestion_msg" id="suggestion_msg" placeholder="เช่น ลด dose 25% เนื่องจาก CrCl 45-60" required>
                                    </div>
                                </div>
                                <div class="border rounded p-3 mt-3 bg-white">
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox" role="switch" id="use_dose_condition" name="use_dose_condition" value="1">
                                        <label class="form-check-label fw-bold text-primary" for="use_dose_condition">
                                            <i class="fas fa-pills me-1"></i> กำหนดเงื่อนไขขนาดยาที่สั่งใช้ (mg/วัน)
                                        </label>
                                        <small class="text-muted d-block ms-4">เปิดใช้เมื่อต้องการแยกกฎตามขนาดยาจริงที่สั่ง — เช่น eGFR 30–45 + metformin สูงสุด 1000 mg/วัน (ปิด = ใช้เฉพาะ Lab)</small>
                                    </div>
                                    <div class="row g-3 dose-fields disabled-section" id="doseFields">
                                        <div class="col-md-3">
                                            <label class="form-label fw-bold text-primary">ขนาดยาต่ำสุด (mg/วัน)</label>
                                            <input type="number" step="1" min="0" class="form-control" name="dose_min" id="dose_min" placeholder="เช่น 0">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label fw-bold text-primary">ขนาดยาสูงสุด (mg/วัน)</label>
                                            <input type="number" step="1" min="0" class="form-control" name="dose_max" id="dose_max" placeholder="เช่น 1000">
                                        </div>
                                        <div class="col-md-2 d-flex align-items-end">
                                            <div class="form-control bg-light text-center fw-bold text-secondary">mg/วัน</div>
                                            <input type="hidden" name="dose_unit" id="dose_unit" value="<?php echo htmlspecialchars($dose_unit_fixed); ?>">
                                        </div>
                                    </div>
                                </div>
                                <?php if ($is_renal && $has_patient_extra_col): ?>
                                <div class="border rounded p-3 mt-3 bg-white">
                                    <div class="small text-muted mb-2">
                                        <i class="fas fa-user-md me-1 text-primary"></i>
                                        เงื่อนไขเพิ่มเติม: ต้องพบเงื่อนไข Lab <b>และ</b> อย่างน้อยหนึ่งใน (อายุ <b>หรือ</b> น้ำหนัก) — ตัวอย่าง Apixaban: Serum Cr 1.5–99 + อายุ ≥80 หรือ นน. ≤60
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <div class="form-check form-switch mb-2">
                                                <input class="form-check-input" type="checkbox" role="switch" id="use_age_condition" name="use_age_condition" value="1">
                                                <label class="form-check-label fw-bold text-primary" for="use_age_condition">เงื่อนไขอายุ (ปี)</label>
                                            </div>
                                            <div class="row g-2 age-fields disabled-section" id="ageFields">
                                                <div class="col-6">
                                                    <label class="form-label small fw-bold">อายุต่ำสุด</label>
                                                    <input type="number" step="1" min="0" class="form-control form-control-sm" name="age_min" id="age_min" placeholder="เช่น 80">
                                                </div>
                                                <div class="col-6">
                                                    <label class="form-label small fw-bold">อายุสูงสุด</label>
                                                    <input type="number" step="1" min="0" class="form-control form-control-sm" name="age_max" id="age_max" placeholder="เช่น 999">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-check form-switch mb-2">
                                                <input class="form-check-input" type="checkbox" role="switch" id="use_weight_condition" name="use_weight_condition" value="1">
                                                <label class="form-check-label fw-bold text-primary" for="use_weight_condition">เงื่อนไขน้ำหนัก (kg)</label>
                                            </div>
                                            <div class="row g-2 weight-fields disabled-section" id="weightFields">
                                                <div class="col-6">
                                                    <label class="form-label small fw-bold">น้ำหนักต่ำสุด</label>
                                                    <input type="number" step="0.1" min="0" class="form-control form-control-sm" name="weight_min" id="weight_min" placeholder="เช่น 0">
                                                </div>
                                                <div class="col-6">
                                                    <label class="form-label small fw-bold">น้ำหนักสูงสุด</label>
                                                    <input type="number" step="0.1" min="0" class="form-control form-control-sm" name="weight_max" id="weight_max" placeholder="เช่น 60">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <div class="row mt-3 g-2">
                                    <div class="col-md-2">
                                        <button type="submit" class="btn <?php echo $is_renal ? 'btn-danger' : ($is_hepatic ? 'btn-warning text-dark' : 'btn-info'); ?> w-100 fw-bold" id="btnSubmit"><i class="fas fa-save me-1"></i> บันทึกกฎ</button>
                                    </div>
                                    <div class="col-md-2 d-none" id="btnCancelWrap">
                                        <button type="button" class="btn btn-secondary w-100 fw-bold" id="btnCancelEdit"><i class="fas fa-times me-1"></i> ยกเลิก</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="card shadow-sm border-0">
                        <div class="card-body">
                            <h6 class="fw-bold text-secondary mb-3"><i class="fas fa-list me-2"></i>รายการกฎที่ตั้งไว้</h6>
                            <table class="table table-bordered table-striped align-middle text-center">
                                <thead class="table-dark">
                                    <tr>
                                        <th>ชื่อยา</th><th><?php echo $is_other ? 'รายการ Lab (รหัส)' : 'เงื่อนไข Lab'; ?></th><th>ต่ำสุด</th><th>สูงสุด</th>
                                        <?php if ($is_renal && $has_patient_extra_col): ?><th>อายุ/นน.</th><?php endif; ?>
                                        <th>เงื่อนไข Dose</th><th>Action</th><th>Alert</th><th>ข้อความเตือน</th><th>จัดการ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $sql = monitor_lab_rules_sql($organ_lab_types_sql, $has_drug_icode_col);
                                    $q = mysqli_query($con, $sql);
                                    if ($q):
                                    while ($row = mysqli_fetch_assoc($q)):
                                        $action = $row['action_type'] == 'percent' ? "ลด {$row['action_value']}%" : ($row['action_type'] == 'fixed' ? "Fix: {$row['action_value']}" : "OMIT");
                                        $badge = $row['action_type'] == 'omit' ? 'bg-dark' : 'bg-warning text-dark';
                                        $dose_cond = '-';
                                        if (!empty($row['use_dose_condition'])) {
                                            $dose_cond = "{$row['dose_min']} – {$row['dose_max']} mg/วัน";
                                        }
                                        $patient_extra_cond = '-';
                                        if ($is_renal && $has_patient_extra_col) {
                                            $parts = [];
                                            if (!empty($row['use_age_condition'])) {
                                                $parts[] = 'อายุ ' . $row['age_min'] . '–' . $row['age_max'] . ' ปี';
                                            }
                                            if (!empty($row['use_weight_condition'])) {
                                                $parts[] = 'นน. ' . $row['weight_min'] . '–' . $row['weight_max'] . ' kg';
                                            }
                                            if (!empty($parts)) {
                                                $patient_extra_cond = implode(' <b>หรือ</b> ', $parts);
                                            }
                                        }
                                        $drug_hosxp_val = trim($row['display_icode'] ?? '') . '|' . trim($row['display_drug_name'] ?? '');
                                        $lab_code_parts = monitor_lab_parse_codes($row['lab_items_code'] ?? '');
                                        $lab_primary = $lab_code_parts[0] ?? '';
                                        $lab_extra_codes = implode(',', array_slice($lab_code_parts, 1));
                                        $lab_hosxp_val = $lab_primary . '|' . trim($row['lab_items_name'] ?? '');
                                        $lvl = monitor_lab_alert_level_from_row($row);
                                        $rule_payload = [
                                            'id' => $row['id'], 'drug_hosxp' => $drug_hosxp_val, 'lab_type' => $row['lab_type'],
                                            'min_val' => $row['min_val'], 'max_val' => $row['max_val'],
                                            'use_dose_condition' => (int)$row['use_dose_condition'],
                                            'dose_min' => $row['dose_min'], 'dose_max' => $row['dose_max'], 'dose_unit' => $row['dose_unit'] ?? '',
                                            'action_type' => $row['action_type'], 'action_value' => $row['action_value'],
                                            'suggestion_msg' => $row['suggestion_msg'],
                                            'alert_level' => $lvl,
                                        ];
                                        if ($is_renal && $has_patient_extra_col) {
                                            $rule_payload['use_age_condition'] = (int)($row['use_age_condition'] ?? 0);
                                            $rule_payload['age_min'] = $row['age_min'];
                                            $rule_payload['age_max'] = $row['age_max'];
                                            $rule_payload['use_weight_condition'] = (int)($row['use_weight_condition'] ?? 0);
                                            $rule_payload['weight_min'] = $row['weight_min'];
                                            $rule_payload['weight_max'] = $row['weight_max'];
                                        }
                                        if ($is_other) {
                                            $rule_payload['lab_hosxp'] = $lab_hosxp_val;
                                            $rule_payload['lab_extra_codes'] = $lab_extra_codes;
                                            $rule_payload['lab_items_code'] = trim($row['lab_items_code'] ?? '');
                                            $rule_payload['lab_condition_type'] = $row['lab_condition_type'] ?? 'range';
                                            $rule_payload['lab_condition_value'] = $row['lab_condition_value'] ?? '';
                                        }
                                        $rule_json = htmlspecialchars(json_encode($rule_payload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                                    ?>
                                    <tr data-rule-id="<?php echo $row['id']; ?>">
                                        <td class="fw-bold text-primary text-start">
                                            <?php echo htmlspecialchars($row['display_drug_name']); ?>
                                            <?php if (!empty($row['display_icode'])): ?>
                                            <br><small class="text-muted">icode: <?php echo htmlspecialchars($row['display_icode']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($is_other && !empty($row['lab_items_code'])): ?>
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($row['lab_items_code']); ?></span>
                                            <div class="small text-dark mt-1"><?php echo htmlspecialchars($row['lab_items_name'] ?? ''); ?></div>
                                            <?php
                                            $row_cond_type = $row['lab_condition_type'] ?? 'range';
                                            if ($row_cond_type !== 'range' && ($row['lab_condition_value'] ?? '') !== ''): ?>
                                            <div class="small text-primary mt-1"><?php echo htmlspecialchars($lab_condition_labels[$row_cond_type] ?? $row_cond_type); ?>: <?php echo htmlspecialchars($row['lab_condition_value']); ?></div>
                                            <?php else: ?>
                                            <div class="small text-muted mt-1">ช่วง <?php echo htmlspecialchars($row['min_val']); ?>–<?php echo htmlspecialchars($row['max_val']); ?></div>
                                            <?php endif; ?>
                                            <?php else: ?>
                                            <span class="badge bg-info text-dark"><?php echo htmlspecialchars($lab_type_labels[$row['lab_type']] ?? $row['lab_type']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-danger fw-bold"><?php echo $row['min_val']; ?></td>
                                        <td class="text-danger fw-bold"><?php echo $row['max_val']; ?></td>
                                        <?php if ($is_renal && $has_patient_extra_col): ?>
                                        <td class="small"><?php if ($patient_extra_cond !== '-'): ?><span class="badge bg-secondary"><?php echo $patient_extra_cond; ?></span><?php else: ?><span class="text-muted">ไม่กำหนด</span><?php endif; ?></td>
                                        <?php endif; ?>
                                        <td><?php if (!empty($row['use_dose_condition'])): ?><span class="badge bg-primary"><?php echo htmlspecialchars($dose_cond); ?></span><?php else: ?><span class="text-muted">ไม่กำหนด</span><?php endif; ?></td>
                                        <td><span class="badge <?php echo $badge; ?>"><?php echo $action; ?></span></td>
                                        <td><?php echo monitor_lab_alert_level_badge($lvl); ?></td>
                                        <td class="text-start"><?php echo htmlspecialchars($row['suggestion_msg']); ?></td>
                                        <td class="text-nowrap">
                                            <button type="button" class="btn btn-sm btn-outline-primary btn-edit-rule me-1" title="แก้ไข" data-rule="<?php echo $rule_json; ?>"><i class="fas fa-edit"></i></button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary btn-copy-rule me-1" title="คัดลอก" data-rule="<?php echo $rule_json; ?>"><i class="fas fa-copy"></i></button>
                                            <a href="<?php echo htmlspecialchars(monitor_lab_page_url('tab=settings&del=' . $row['id'])); ?>" class="btn btn-sm btn-outline-danger" title="ลบ" onclick="return confirm('ยืนยันการลบเงื่อนไขนี้?')"><i class="fas fa-trash"></i></a>
                                        </td>
                                    </tr>
                                    <?php endwhile; else: ?>
                                    <tr><td colspan="<?php echo ($is_renal && $has_patient_extra_col) ? 10 : 9; ?>" class="text-center text-danger py-3">ไม่สามารถโหลดรายการกฎได้ — กรุณารัน <a href="upgrade.php">อัปเกรดระบบ</a></td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

            </div><!-- /mainSettings -->

            <?php endif; ?>

        </div>
    </div>
</div>

<script src="js/jquery-3.7.0.min.js"></script>
<script src="js/bootstrap.bundle.min.js"></script>
<?php if (!$lab_settings_tab): ?>
<script src="js/chart.min.js"></script>
<script src="js/flatpickr.min.js"></script>
<?php else: ?>
<script src="js/select2.min.js"></script>
<?php endif; ?>
<script>
    const LAB_SETTINGS_TAB = <?php echo $lab_settings_tab ? 'true' : 'false'; ?>;
    const MONITOR_ORGAN = <?php echo json_encode($organ); ?>;
    const IS_OTHER_LAB = <?php echo $is_other ? 'true' : 'false'; ?>;
    const IS_RENAL_LAB = <?php echo $is_renal ? 'true' : 'false'; ?>;
    let summaryChart = null;

    const LAB_TYPE_HINTS_ALL = {
        'GFR': { min: '45', max: '60', text: 'ช่วง CrCl (ml/min) — คำนวณจาก Serum Cr' },
        'CKD_EPI': { min: '45', max: '60', text: 'ช่วง eGFR CKD-EPI (ml/min/1.73m²) — ดึงจาก HOSxP' },
        'SERUM_CR': { min: '1.5', max: '99', text: 'ช่วง Serum Creatinine (mg/dL) — ดึงจาก HOSxP (เช่น Apixaban ≥1.5)' },
        'Bilirubin': { min: '1.2', max: '3', text: 'ช่วง T.Bilirubin (mg/dL)' },
        'Bilirubin_xULN': { min: '1.25', max: '5', text: 'ช่วง T.Bilirubin เป็นเท่าของ ULN (เช่น 1.25–2× = ลด dose, ≥5× = OMIT)' },
        'DirectBilirubin': { min: '0.3', max: '1.5', text: 'ช่วง Direct Bilirubin (mg/dL)' },
        'IndirectBilirubin': { min: '1', max: '5', text: 'ช่วง Indirect Bilirubin (mg/dL)' },
        'AST': { min: '40', max: '200', text: 'ช่วงค่า AST จริง (U/L)' },
        'ALT': { min: '40', max: '200', text: 'ช่วงค่า ALT จริง (U/L)' },
        'AST_xULN': { min: '2', max: '10', text: 'ช่วงเป็นจำนวนเท่าของ ULN (เช่น 2–10× ULN)' },
        'ALT_xULN': { min: '2', max: '10', text: 'ช่วงเป็นจำนวนเท่าของ ULN (เช่น 2–10× ULN)' },
        'AST_ALT_xULN': { min: '10', max: '99', text: 'ช่วง Max(AST,ALT)/ULN — เช่น ≥10× = ต่ำสุด 10 สูงสุด 99' }
    };
    const LAB_TYPE_HINTS = LAB_TYPE_HINTS_ALL;
    const LAB_TYPE_DISPLAY = <?php echo json_encode($lab_type_labels, JSON_UNESCAPED_UNICODE); ?>;

    function ensureHosxpSelectOption($sel, val, label) {
        if (!val) return;
        if ($sel.find('option').filter(function() { return this.value === val; }).length === 0) {
            $sel.append(new Option(label || val, val, true, true));
        }
    }

    function ensureLabSelectOption($sel, val, label) {
        ensureHosxpSelectOption($sel, val, label);
    }

    function initLabSelect2() {
        if (!IS_OTHER_LAB || !$('#lab_hosxp').length) return;
        $('#lab_hosxp').select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder: 'ค้นหารหัสหรือชื่อ Lab จาก HOSxP...',
            allowClear: true,
            minimumInputLength: 1,
            ajax: {
                url: 'search_lab_items_api.php',
                dataType: 'json',
                delay: 250,
                data: params => ({ q: params.term || '' }),
                processResults: data => data
            }
        });
    }

    function toggleOtherConditionMode() {
        if (!IS_OTHER_LAB) return;
        const mode = $('#lab_condition_type').val() || 'range';
        const isRange = mode === 'range';
        $('.other-range-field').toggle(isRange);
        $('#otherTextCondWrap').toggle(!isRange);
        $('#lab_condition_value').prop('required', !isRange);
        $('#min_val, #max_val').prop('required', isRange);
        if (!isRange) {
            $('#min_val, #max_val').val('0');
            $('#action_type').val('omit');
            $('#action_value').val('0');
        }
        updateLabRangeHint();
    }

    function updateLabRangeHint() {
        if (IS_OTHER_LAB) {
            const mode = $('#lab_condition_type').val() || 'range';
            if (mode === 'range') {
                $('#lab_range_hint').html('<i class="fas fa-info-circle me-1"></i>กำหนดช่วงค่าผล Lab ตามหน่วยของรายการที่เลือก (ค่าตัวเลขจาก lab_order_result)');
                if ($('#form_action').val() === 'add') {
                    $('#min_val').attr('placeholder', 'เช่น 0');
                    $('#max_val').attr('placeholder', 'เช่น 100');
                }
            } else if (mode === 'regex') {
                $('#lab_range_hint').html('<i class="fas fa-info-circle me-1"></i>Regex — รวมหลายรูปแบบด้วย <code>|</code> เช่น <code>2\\+|3\\+|4\\+</code>');
            } else if (mode === 'contains') {
                $('#lab_range_hint').html('<i class="fas fa-info-circle me-1"></i>มีคำว่า — หาคำเดียวต่อครั้ง (หลายคำให้ใช้ Regex)');
            } else {
                $('#lab_range_hint').html('<i class="fas fa-info-circle me-1"></i>เปรียบเทียบตัวเลขจากผล Lab (float)');
            }
            return;
        }
        const t = $('#lab_type').val();
        const h = LAB_TYPE_HINTS[t];
        if (!h) {
            $('#lab_range_hint').text('');
            return;
        }
        $('#lab_range_hint').html('<i class="fas fa-info-circle me-1"></i>' + h.text);
        if ($('#form_action').val() === 'add') {
            $('#min_val').attr('placeholder', h.min);
            $('#max_val').attr('placeholder', h.max);
        }
    }

    function toggleDoseFields() {
        const on = $('#use_dose_condition').is(':checked');
        const $sec = $('#doseFields');
        if (on) {
            $sec.removeClass('disabled-section');
            $('#dose_min, #dose_max').prop('required', true);
        } else {
            $sec.addClass('disabled-section');
            $('#dose_min, #dose_max').prop('required', false);
        }
    }

    function toggleAgeFields() {
        if (!IS_RENAL_LAB || !$('#use_age_condition').length) return;
        const on = $('#use_age_condition').is(':checked');
        const $sec = $('#ageFields');
        if (on) {
            $sec.removeClass('disabled-section');
            $('#age_min, #age_max').prop('required', true);
        } else {
            $sec.addClass('disabled-section');
            $('#age_min, #age_max').prop('required', false);
        }
    }

    function toggleWeightFields() {
        if (!IS_RENAL_LAB || !$('#use_weight_condition').length) return;
        const on = $('#use_weight_condition').is(':checked');
        const $sec = $('#weightFields');
        if (on) {
            $sec.removeClass('disabled-section');
            $('#weight_min, #weight_max').prop('required', true);
        } else {
            $sec.addClass('disabled-section');
            $('#weight_min, #weight_max').prop('required', false);
        }
    }

    function parseLabRuleData(el) {
        let rule = $(el).data('rule');
        if (typeof rule === 'string') {
            try { rule = JSON.parse(rule); } catch (e) { return null; }
        }
        return rule && (rule.lab_type || rule.lab_hosxp) ? rule : null;
    }

    function fillRuleForm(rule) {
        const drugParts = (rule.drug_hosxp || '').split('|');
        const drugLabel = drugParts.length > 1
            ? drugParts[1] + ' (icode: ' + drugParts[0] + ')'
            : rule.drug_hosxp;
        ensureHosxpSelectOption($('#drug_hosxp'), rule.drug_hosxp, drugLabel);
        $('#drug_hosxp').val(rule.drug_hosxp).trigger('change');
        if (IS_OTHER_LAB) {
            const labParts = (rule.lab_hosxp || '').split('|');
            const labLabel = labParts.length > 1
                ? '[' + labParts[0] + '] ' + labParts[1]
                : rule.lab_hosxp;
            ensureLabSelectOption($('#lab_hosxp'), rule.lab_hosxp, labLabel);
            $('#lab_hosxp').val(rule.lab_hosxp).trigger('change');
            $('#lab_extra_codes').val(rule.lab_extra_codes || '');
            $('#lab_condition_type').val(rule.lab_condition_type || 'range');
            $('#lab_condition_value').val(rule.lab_condition_value || '');
            toggleOtherConditionMode();
        } else {
            $('#lab_type').val(rule.lab_type);
        }
        updateLabRangeHint();
        $('#min_val').val(rule.min_val);
        $('#max_val').val(rule.max_val);
        $('#action_type').val(rule.action_type);
        $('#action_value').val(rule.action_value);
        $('#suggestion_msg').val(rule.suggestion_msg);
        $('#alert_level').val(rule.alert_level === 'hard' ? 'hard' : 'soft');
        $('#use_dose_condition').prop('checked', rule.use_dose_condition == 1);
        $('#dose_min').val(rule.dose_min ?? '');
        $('#dose_max').val(rule.dose_max ?? '');
        $('#dose_unit').val('mg');
        toggleDoseFields();
        if (IS_RENAL_LAB && $('#use_age_condition').length) {
            $('#use_age_condition').prop('checked', rule.use_age_condition == 1);
            $('#age_min').val(rule.age_min ?? '');
            $('#age_max').val(rule.age_max ?? '');
            $('#use_weight_condition').prop('checked', rule.use_weight_condition == 1);
            $('#weight_min').val(rule.weight_min ?? '');
            $('#weight_max').val(rule.weight_max ?? '');
            toggleAgeFields();
            toggleWeightFields();
        }
    }

    function setAddMode() {
        $('#form_action').val('add');
        $('#rule_id').val('');
        $('#formCard').removeClass('edit-mode copy-mode');
        $('#formCardHeader').removeClass('bg-primary bg-secondary bg-warning text-dark').addClass('<?php echo $form_header_bg; ?>');
        $('#formCardIcon').removeClass('fa-edit fa-copy').addClass('fa-plus-circle');
        $('#formCardTitle').text('เพิ่มเงื่อนไขใหม่');
        $('#btnSubmit').removeClass('btn-primary').addClass('<?php echo $is_renal ? 'btn-danger' : ($is_hepatic ? 'btn-warning text-dark' : 'btn-info'); ?>').html('<i class="fas fa-save me-1"></i> บันทึกกฎ');
        $('#btnCancelWrap').addClass('d-none');
        $('tr.editing-row').removeClass('editing-row');
        document.getElementById('ruleForm').reset();
        $('#drug_hosxp').val('').trigger('change');
        if (IS_OTHER_LAB) {
            $('#lab_hosxp').val(null).trigger('change');
            $('#lab_extra_codes').val('');
            $('#lab_condition_type').val('range');
            $('#lab_condition_value').val('');
            toggleOtherConditionMode();
        }
        $('#use_dose_condition').prop('checked', false);
        toggleDoseFields();
        if (IS_RENAL_LAB && $('#use_age_condition').length) {
            $('#use_age_condition, #use_weight_condition').prop('checked', false);
            $('#age_min, #age_max, #weight_min, #weight_max').val('');
            toggleAgeFields();
            toggleWeightFields();
        }
        updateLabRangeHint();
    }

    function setEditMode(rule) {
        $('#form_action').val('edit');
        $('#rule_id').val(rule.id);
        $('#formCard').removeClass('copy-mode').addClass('edit-mode');
        $('#formCardHeader').removeClass('<?php echo $form_header_bg; ?> bg-secondary').addClass('bg-primary');
        $('#formCardIcon').removeClass('fa-plus-circle fa-copy').addClass('fa-edit');
        $('#formCardTitle').text('แก้ไขเงื่อนไข (ID: ' + rule.id + ')');
        $('#btnSubmit').removeClass('btn-danger btn-warning text-dark btn-secondary btn-info').addClass('btn-primary').html('<i class="fas fa-save me-1"></i> บันทึกการแก้ไข');
        $('#btnCancelWrap').removeClass('d-none');
        fillRuleForm(rule);
        $('tr.editing-row').removeClass('editing-row');
        $('tr[data-rule-id="' + rule.id + '"]').addClass('editing-row');
        document.getElementById('formCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function setCopyMode(rule) {
        $('#form_action').val('add');
        $('#rule_id').val('');
        $('#formCard').removeClass('edit-mode').addClass('copy-mode');
        $('#formCardHeader').removeClass('<?php echo $form_header_bg; ?> bg-primary').addClass('bg-secondary');
        $('#formCardIcon').removeClass('fa-plus-circle fa-edit').addClass('fa-copy');
        $('#formCardTitle').text('คัดลอกเงื่อนไขจาก ID: ' + rule.id);
        $('#btnSubmit').removeClass('btn-danger btn-warning text-dark btn-primary btn-info').addClass('btn-secondary').html('<i class="fas fa-save me-1"></i> บันทึกกฎใหม่');
        $('#btnCancelWrap').removeClass('d-none');
        fillRuleForm(rule);
        $('tr.editing-row').removeClass('editing-row');
        $('tr[data-rule-id="' + rule.id + '"]').addClass('editing-row');
        document.getElementById('formCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    $(document).ready(function() {
        if (LAB_SETTINGS_TAB) {
            $('.select2-hosxp').select2({ theme: 'bootstrap-5', width: '100%' });
            initLabSelect2();
            $('#use_dose_condition').on('change', toggleDoseFields);
            if (IS_RENAL_LAB) {
                $('#use_age_condition').on('change', toggleAgeFields);
                $('#use_weight_condition').on('change', toggleWeightFields);
                toggleAgeFields();
                toggleWeightFields();
            }
            if (IS_OTHER_LAB) {
                $('#lab_condition_type').on('change', toggleOtherConditionMode);
                toggleOtherConditionMode();
            } else {
                $('#lab_type').on('change', updateLabRangeHint);
            }
            toggleDoseFields();
            updateLabRangeHint();
            $(document).on('click', '.btn-edit-rule', function() {
                const rule = parseLabRuleData(this);
                if (!rule) return;
                setEditMode(rule);
            });
            $(document).on('click', '.btn-copy-rule', function() {
                const rule = parseLabRuleData(this);
                if (!rule) return;
                setCopyMode(rule);
            });
            $('#btnCancelEdit').on('click', setAddMode);
        } else {
            flatpickr('#filter_start', { dateFormat: 'Y-m-d' });
            flatpickr('#filter_end', { dateFormat: 'Y-m-d' });
            initChart();
            setLabDateRange(30);
            $(document).on('change', '.lab-rule-cb', labUpdateRuleSelectedCount);
            labUpdateRuleSelectedCount();
        }
    });

    function setLabDateRange(days) {
        const end = new Date();
        const start = new Date();
        start.setDate(start.getDate() - days);
        const fmt = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        const fpStart = document.getElementById('filter_start')._flatpickr;
        const fpEnd = document.getElementById('filter_end')._flatpickr;
        if (fpStart) fpStart.setDate(fmt(start), true);
        if (fpEnd) fpEnd.setDate(fmt(end), true);
    }

    function initChart() {
        const ctx = document.getElementById('summaryChart').getContext('2d');
        summaryChart = new Chart(ctx, {
            type: 'doughnut',
            data: { labels: ['ผ่านเกณฑ์', 'ไม่ผ่าน (คลาดเคลื่อน)'], datasets: [{ data: [0, 0], backgroundColor: ['#198754', '#dc3545'], borderWidth: 0 }] },
            options: { responsive: true, maintainAspectRatio: false, cutout: '70%', plugins: { legend: { position: 'right' } } }
        });
    }

    function updateChart(passCount, failCount) {
        summaryChart.data.datasets[0].data = [passCount, failCount];
        summaryChart.update();
        $('#count_pass').text(passCount);
        $('#count_fail').text(failCount);
    }

    function getSelectedLabRuleIds() {
        return $('.lab-rule-cb:checked').map(function() { return this.value; }).get();
    }

    function labSelectAllRules(checked) {
        $('.lab-rule-cb').prop('checked', checked);
        labUpdateRuleSelectedCount();
    }

    function labUpdateRuleSelectedCount() {
        const total = $('.lab-rule-cb').length;
        const sel = getSelectedLabRuleIds().length;
        $('#labRuleSelectedCount').text(total > 0 ? '(เลือก ' + sel + ' / ' + total + ' รายการ)' : '');
    }

    function fetchLabMonitorData() {
        const sDate = $('#filter_start').val();
        const eDate = $('#filter_end').val();
        const ruleIds = getSelectedLabRuleIds();
        if (ruleIds.length === 0) {
            alert('กรุณาเลือกอย่างน้อย 1 รายการยาที่ต้องการรายงาน');
            return;
        }
        const loading = '<tr><td colspan="6" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i>กำลังประมวลผล...</td></tr>';
        $('#hardTbody, #softTbody').html(loading);
        fetch('fetch_monitor_lab_api.php?start_date=' + encodeURIComponent(sDate) + '&end_date=' + encodeURIComponent(eDate) + '&organ=' + encodeURIComponent(MONITOR_ORGAN) + '&rule_ids=' + encodeURIComponent(ruleIds.join(',')))
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                const hardAlerts = data.hard_alerts || [];
                const softAlerts = data.soft_alerts || [];
                const allAlerts = hardAlerts.concat(softAlerts);
                const meta = data.meta || {};
                let metaHtml = '<i class="fas fa-info-circle me-1"></i> กฎที่เลือก: ' + (meta.rules_selected || meta.rule_count || 0) +
                    ' / ' + (meta.rules_total || meta.rule_count || 0) +
                    ' | Hard Alert: ' + (meta.hard_count || hardAlerts.length) +
                    ' | Soft Alert: ' + (meta.soft_count || softAlerts.length) +
                    ' | สแกน order: ' + (meta.orders_scanned || 0) + ' รายการ' +
                    ' (ไม่ซ้ำ ' + (meta.orders_unique || 0) + ', HN ' + (meta.patients_scanned || 0) + ' ราย)';
                if (meta.elapsed_ms) {
                    metaHtml += ' | ใช้เวลา ' + (meta.elapsed_ms / 1000).toFixed(1) + ' วินาที';
                }
                if (meta.high_volume) {
                    metaHtml += ' <span class="text-warning">(พบการสั่งยามาก — แนะนำเลือกเฉพาะยาที่ต้องการเฝ้าระวังใน Settings หรือลดช่วงวันที่)</span>';
                }
                if (meta.truncated) {
                    metaHtml += ' <span class="text-danger">(เกิน limit — ลดช่วงวันที่)</span>';
                }
                $('#reportMeta').removeClass('d-none').html(metaHtml);
                $('#hardCount').text(meta.hard_count || hardAlerts.length);
                $('#softCount').text(meta.soft_count || softAlerts.length);
                renderTable('hardTbody', hardAlerts, 'ไม่พบรายการ Hard Alert ในช่วงเวลานี้');
                renderTable('softTbody', softAlerts, 'ไม่พบรายการ Soft Alert ในช่วงเวลานี้');
                let totalPass = 0, totalFail = 0;
                allAlerts.forEach(item => {
                    if (item.is_pass) totalPass++; else totalFail++;
                });
                updateChart(totalPass, totalFail);
            } else {
                $('#reportMeta').addClass('d-none');
                const errRow = '<tr><td colspan="6" class="text-center text-danger py-4 fw-bold">' + (data.message || 'เกิดข้อผิดพลาด') + '</td></tr>';
                $('#hardTbody, #softTbody').html(errRow);
            }
        })
        .catch(err => {
            console.error(err);
            $('#reportMeta').addClass('d-none');
            $('#hardTbody, #softTbody').html('<tr><td colspan="6" class="text-center text-danger py-4">เกิดข้อผิดพลาดในการเชื่อมต่อ API</td></tr>');
        });
    }

    function labEsc(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function renderTable(tbodyId, alerts, emptyText) {
        if (alerts.length === 0) {
            $(`#${tbodyId}`).html(`<tr><td colspan="6" class="text-center py-4 text-muted">${emptyText || 'ไม่พบรายการในช่วงเวลานี้'}</td></tr>`);
            return;
        }
        let html = '';
        alerts.forEach(item => {
            let badgeClass = MONITOR_ORGAN === 'other' ? 'bg-info text-dark'
                : ((item.lab_type === 'GFR' || item.lab_type === 'CKD_EPI' || item.lab_type === 'SERUM_CR') ? 'bg-primary' : 'bg-warning text-dark');
            let labLabel = MONITOR_ORGAN === 'other'
                ? '[' + (item.lab_items_code || '') + '] ' + (item.lab_items_name || 'Lab')
                : (LAB_TYPE_DISPLAY[item.lab_type] || item.lab_type);
            let labValText = item.lab_value_is_text ? item.lab_value : item.lab_value;
            if (!item.lab_value_is_text && item.lab_type && item.lab_type.indexOf('xULN') !== -1) {
                labValText = parseFloat(item.lab_value).toFixed(2) + '× ULN';
            }
            if (item.lab_condition_type && item.lab_condition_type !== 'range' && item.lab_condition_value) {
                labLabel += '<div class="small text-muted mt-1">' + labEsc(item.lab_condition_type) + ': ' + labEsc(item.lab_condition_value) + '</div>';
            }
            if (item.patient_extra_text) {
                labLabel += '<div class="small text-dark mt-1">' + labEsc(item.patient_extra_text) + '</div>';
            }
            let passBadge = item.is_pass
                ? `<span class="badge bg-success w-100 py-2 shadow-sm"><i class="fas fa-check-circle me-1"></i> ${item.pass_text}</span>`
                : `<span class="badge bg-danger w-100 py-2 shadow-sm"><i class="fas fa-times-circle me-1"></i> ${item.pass_text}</span>`;
            const orderWhen = item.order_time
                ? `${item.order_date} <span class="text-dark">${item.order_time}</span>`
                : item.order_date;
            const labWhen = item.lab_time
                ? `${item.lab_date} <span class="text-dark">${item.lab_time}</span>`
                : item.lab_date;
            const methodText = labEsc(item.drug_method || '-');
            html += `<tr>
                <td class="text-center fw-bold text-dark">${item.hn}</td>
                <td><div>${item.ptname}</div><div class="text-muted small mt-1"><i class="fas fa-calendar-plus"></i> สั่งยา: ${orderWhen}</div></td>
                <td><div class="fw-bold text-primary"><i class="fas fa-pills me-1"></i>${item.drug_name}</div>
                    <div class="dose-box">
                        <div class="small mb-1 text-muted"><i class="fas fa-info-circle me-1"></i>วิธีใช้: <span class="text-dark">${methodText}</span></div>
                        <div class="small mb-1">สั่งใช้จริง: <span class="text-danger fw-bold">${item.actual_dose}</span></div>
                        <div class="small">แนะนำ: <span class="text-success fw-bold bg-success bg-opacity-10 px-2 py-1 rounded border border-success">${item.recommended_dose}</span></div>
                    </div></td>
                <td class="text-center"><div class="badge ${badgeClass} fs-6 px-3 py-2 shadow-sm mb-1">${labLabel}: ${labValText}</div>
                    <div class="text-secondary fw-bold" style="font-size: 0.75rem;"><i class="fas fa-vial me-1"></i> เจาะเมื่อ: ${labWhen}</div></td>
                <td class="text-center text-danger fw-bold"><small>${item.warning_msg}</small></td>
                <td class="text-center">${passBadge}</td>
            </tr>`;
        });
        $(`#${tbodyId}`).html(html);
    }
</script>
</body>
</html>
