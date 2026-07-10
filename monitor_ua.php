<?php
session_start();
if (!isset($_SESSION['user_id']) && !isset($_SESSION['full_name'])) { header("Location: login.php"); exit; }
include('connect.php');
mysqli_set_charset($con, "utf8");

/** ค่า min/max/unit สำหรับ dose range — รูปแบบเดียวกับ buildDoseSql ใน manage_safety_rules.php */
function buildUaDoseInsertParts($con, $use_dose) {
    if (!$use_dose) {
        return ['min_dose' => 'NULL', 'max_dose' => 'NULL', 'dose_unit' => 'NULL', 'use_flag' => '0'];
    }
    $dmin = mysqli_real_escape_string($con, $_POST['dose_min'] ?? '');
    $dmax = mysqli_real_escape_string($con, $_POST['dose_max'] ?? '');
    $dunit = mysqli_real_escape_string($con, $_POST['dose_unit'] ?? '');
    return [
        'min_dose' => ($dmin !== '') ? "'$dmin'" : 'NULL',
        'max_dose' => ($dmax !== '') ? "'$dmax'" : 'NULL',
        'dose_unit' => ($dunit !== '') ? "'$dunit'" : 'NULL',
        'use_flag' => '1',
    ];
}

// บันทึกและลบข้อมูลเงื่อนไข UA
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] == 'add_ua') {
        $dp = explode('|', $_POST['drug'], 2);
        $icode = mysqli_real_escape_string($con, $dp[0]);
        $drug_name = mysqli_real_escape_string($con, $dp[1] ?? '');
        
        $use_dose = isset($_POST['use_dose_condition']) ? 1 : 0;
        $dose_parts = buildUaDoseInsertParts($con, $use_dose);
        
        $lp = explode('|', $_POST['lab_code'], 2);
        $lab_code = mysqli_real_escape_string($con, $lp[0]);
        $lab_name = mysqli_real_escape_string($con, $lp[1] ?? '');
        
        $cond_type = mysqli_real_escape_string($con, $_POST['condition_type']);
        $cond_val = mysqli_real_escape_string($con, $_POST['condition_value']);
        $alert_lvl = mysqli_real_escape_string($con, $_POST['alert_level']);
        $alert_msg = mysqli_real_escape_string($con, $_POST['alert_message']);
        
        $sql = "INSERT INTO monitor_ua_settings 
                (icode, drug_name, min_dose, max_dose, use_dose_condition, dose_unit, lab_code, lab_name, condition_type, condition_value, alert_level, alert_message) 
                VALUES ('$icode', '$drug_name', {$dose_parts['min_dose']}, {$dose_parts['max_dose']}, {$dose_parts['use_flag']}, {$dose_parts['dose_unit']}, '$lab_code', '$lab_name', '$cond_type', '$cond_val', '$alert_lvl', '$alert_msg')";
        mysqli_query($con, $sql);
        header("Location: monitor_ua.php?tab=settings");
        exit;
    }
    if (isset($_POST['action']) && $_POST['action'] == 'edit_ua') {
        $id = intval($_POST['rule_id'] ?? 0);
        if ($id <= 0) {
            header("Location: monitor_ua.php?tab=settings");
            exit;
        }
        $dp = explode('|', $_POST['drug'], 2);
        $icode = mysqli_real_escape_string($con, $dp[0]);
        $drug_name = mysqli_real_escape_string($con, $dp[1] ?? '');

        $use_dose = isset($_POST['use_dose_condition']) ? 1 : 0;
        $dose_parts = buildUaDoseInsertParts($con, $use_dose);

        $lp = explode('|', $_POST['lab_code'], 2);
        $lab_code = mysqli_real_escape_string($con, $lp[0]);
        $lab_name = mysqli_real_escape_string($con, $lp[1] ?? '');

        $cond_type = mysqli_real_escape_string($con, $_POST['condition_type']);
        $cond_val = mysqli_real_escape_string($con, $_POST['condition_value']);
        $alert_lvl = mysqli_real_escape_string($con, $_POST['alert_level']);
        $alert_msg = mysqli_real_escape_string($con, $_POST['alert_message']);

        $sql = "UPDATE monitor_ua_settings SET
                icode='$icode', drug_name='$drug_name',
                min_dose={$dose_parts['min_dose']}, max_dose={$dose_parts['max_dose']},
                use_dose_condition={$dose_parts['use_flag']}, dose_unit={$dose_parts['dose_unit']},
                lab_code='$lab_code', lab_name='$lab_name',
                condition_type='$cond_type', condition_value='$cond_val',
                alert_level='$alert_lvl', alert_message='$alert_msg'
                WHERE id=$id";
        mysqli_query($con, $sql);
        header("Location: monitor_ua.php?tab=settings");
        exit;
    }
    if (isset($_POST['action']) && $_POST['action'] == 'delete_ua') {
        $id = intval($_POST['del_id']);
        mysqli_query($con, "DELETE FROM monitor_ua_settings WHERE id = $id");
        header("Location: monitor_ua.php?tab=settings");
        exit;
    }
}

// รายการ Lab UA จาก sys_config — ตรงกับหัวข้อ Urinalysis ใน hosxp_setting.php (แสดงเฉพาะชื่อ ไม่โชว์รหัส)
$ua_lab_defs = [
    'h_code_urine_ph' => 'Urine pH',
    'h_code_urine_sg' => 'Urine Specific Gravity',
    'h_code_urine_protein' => 'Urine Protein',
    'h_code_urine_rbc' => 'Urine RBC',
    'h_code_urine_protein_24h' => 'Urine Protein 24 hrs',
];
$q_ua_cfg = mysqli_query($con, "SELECT config_name, config_value FROM sys_config WHERE config_name IN ('h_code_urine_ph','h_code_urine_sg','h_code_urine_protein','h_code_urine_rbc','h_code_urine_protein_24h')");
$sys_ua = array_fill_keys(array_keys($ua_lab_defs), '');
if ($q_ua_cfg) {
    while ($r = mysqli_fetch_assoc($q_ua_cfg)) {
        $sys_ua[$r['config_name']] = trim((string)($r['config_value'] ?? ''));
    }
}
$opt_labs = '';
$has_ua_lab_options = false;
foreach ($ua_lab_defs as $cfg_key => $label) {
    $code = $sys_ua[$cfg_key] ?? '';
    if ($code === '') {
        continue;
    }
    $has_ua_lab_options = true;
    $opt_labs .= '<option value="' . htmlspecialchars($code . '|' . $label, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
}
if (!$has_ua_lab_options) {
    $opt_labs = '<option value="">— ยังไม่ได้ตั้งรหัส Lab UA —</option>';
}

$dose_unit_options = ['', 'mg./m2', 'mg./kg', 'mg', 'unit', 'mg./m2/day'];

// ต่อท่อดึงยาจาก HOSxP
$q_config = mysqli_query($con, "SELECT config_name, config_value FROM sys_config WHERE config_name IN ('h_host', 'h_user', 'h_pass', 'h_db')");
$hos_cfg = [];
while ($r = mysqli_fetch_assoc($q_config)) { $hos_cfg[$r['config_name']] = $r['config_value']; }
$con_hosxp = @mysqli_connect($hos_cfg['h_host'], $hos_cfg['h_user'], $hos_cfg['h_pass'], $hos_cfg['h_db']);

$opt_drugs = "<option value=''>- ค้นหาและเลือกยาจาก HOSxP -</option>";
if ($con_hosxp) {
    mysqli_set_charset($con_hosxp, "utf8");
    $q_drug_hosxp = mysqli_query($con_hosxp, "SELECT icode, name FROM drugitems WHERE istatus='Y' AND icode IS NOT NULL ORDER BY name ASC");
    while ($r = mysqli_fetch_assoc($q_drug_hosxp)) {
        $opt_drugs .= "<option value='" . $r['icode'] . "|" . htmlspecialchars($r['name']) . "'>" . htmlspecialchars($r['name']) . " (icode: " . $r['icode'] . ")</option>";
    }
    mysqli_close($con_hosxp);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Urinalysis Monitor - THAM CYTO</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
    <link href="css/select2.min.css" rel="stylesheet">
    <link href="css/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="css/flatpickr.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', Tahoma, sans-serif; background-color: #f4f7f6; }
        .filter-box { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; }
        .hn-group-header { background: #e9ecef; font-weight: bold; }
        .hn-group-header td { border-left: 4px solid #0d6efd; }
        .visit-row td { background: #fff; }
        .visit-row td:first-child { padding-left: 2rem; }
        .section-hard .table thead { background-color: #dc3545 !important; color: white;}
        .section-soft .table thead { background-color: #ffc107 !important; color: #212529 !important; }
        .dose-fields { transition: opacity 0.2s; }
        .dose-fields.disabled-section { opacity: 0.45; pointer-events: none; }
        tr.ua-editing-row { background-color: #fff3cd !important; }
    </style>
</head>
<body>
<div class="wrapper">
    <?php include('sidebar.php'); ?>
    <div id="content" class="content">
        <div class="container-fluid py-4">
            <h4 class="fw-bold mb-4 text-dark"><i class="fas fa-flask text-primary me-2"></i>ระบบเฝ้าระวังผลปัสสาวะ (Urinalysis Monitoring)</h4>

            <ul class="nav nav-tabs fw-bold" id="uaTab" role="tablist">
                <li class="nav-item">
                    <button class="nav-link <?php echo (!isset($_GET['tab']) || $_GET['tab'] != 'settings') ? 'active' : ''; ?>" id="monitor-tab" data-bs-toggle="tab" data-bs-target="#monitor" type="button"><i class="fas fa-desktop me-1"></i> รายการเฝ้าระวังหน้างาน</button>
                </li>
                <li class="nav-item">
                    <button class="nav-link <?php echo (isset($_GET['tab']) && $_GET['tab'] == 'settings') ? 'active' : ''; ?>" id="settings-tab" data-bs-toggle="tab" data-bs-target="#settings" type="button"><i class="fas fa-cogs me-1"></i> ตั้งค่าเงื่อนไข UA (Rule Engine)</button>
                </li>
            </ul>

            <div class="tab-content bg-white border border-top-0 p-4 rounded-bottom shadow-sm">
                
                <div class="tab-pane fade <?php echo (!isset($_GET['tab']) || $_GET['tab'] != 'settings') ? 'show active' : ''; ?>" id="monitor">
                    <div class="filter-box p-3 mb-3">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label small fw-bold mb-1">ช่วงวันที่ให้ยา ตั้งแต่</label>
                                <input type="text" id="filter_start" class="form-control form-control-sm bg-white">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-bold mb-1">ถึงวันที่</label>
                                <input type="text" id="filter_end" class="form-control form-control-sm bg-white">
                            </div>
                            <div class="col-md-3">
                                <button class="btn btn-primary btn-sm fw-bold shadow-sm w-100" onclick="fetchUAData()">
                                    <i class="fas fa-sync-alt me-1"></i> ประมวลผล / ดึงรายงาน
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="section-hard mb-4">
                        <h6 class="fw-bold text-danger mb-2"><i class="fas fa-exclamation-circle me-2"></i>Hard Alert <span id="hardCount" class="badge bg-danger">0</span></h6>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle border mb-0">
                                <thead class="text-center">
                                    <tr>
                                        <th>HN / Visit</th><th class="text-start">ชื่อ-สกุล</th><th>วันที่ให้ยา</th>
                                        <th class="text-start">รายการยา (Dose)</th><th>ผล UA ที่พบ</th>
                                        <th class="text-start">ข้อความแจ้งเตือน</th><th>จัดการ</th>
                                    </tr>
                                </thead>
                                <tbody id="hardTbody"><tr><td colspan="7" class="text-center py-4 text-muted">กดปุ่มประมวลผลเพื่อดึงข้อมูล</td></tr></tbody>
                            </table>
                        </div>
                    </div>

                    <div class="section-soft mb-4">
                        <h6 class="fw-bold text-warning mb-2"><i class="fas fa-exclamation-triangle me-2"></i>Soft Alert <span id="softCount" class="badge bg-warning text-dark">0</span></h6>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle border mb-0">
                                <thead class="text-center">
                                    <tr>
                                        <th>HN / Visit</th><th class="text-start">ชื่อ-สกุล</th><th>วันที่ให้ยา</th>
                                        <th class="text-start">รายการยา (Dose)</th><th>ผล UA ที่พบ</th>
                                        <th class="text-start">ข้อความแจ้งเตือน</th><th>จัดการ</th>
                                    </tr>
                                </thead>
                                <tbody id="softTbody"><tr><td colspan="7" class="text-center py-4 text-muted">กดปุ่มประมวลผลเพื่อดึงข้อมูล</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade <?php echo (isset($_GET['tab']) && $_GET['tab'] == 'settings') ? 'show active' : ''; ?>" id="settings">
                    <div class="alert alert-light border small mb-4">
                        <i class="fas fa-lightbulb text-warning me-2"></i><b>คำแนะนำการตั้งเงื่อนไข (Rule Engine):</b><br>
                        - <b>Regex (ข้อความ):</b> ใช้ดักจับคำที่อาจเขียนได้หลายแบบ เช่น <code>2\+|3\+|4\+</code> จะดักจับ 2+, 3+, 4+ ได้ทั้งหมด (<b>เครื่องหมาย | = OR</b> ใน Regex)<br>
                        - <b>หลายค่า / OR ในยาเดียว:</b> เลือก <b>Regex</b> แล้วรวมรูปแบบด้วย <code>|</code> — ตัวอักษรพิเศษ (เช่น <code>+</code> <code>.</code>) ต้อง escape ด้วย <code>\</code> เช่น pH เป็น <code>5\.0|6\.2|7\.0</code> ถ้าผล Lab เป็นข้อความ / มีหน่วยต่อท้าย<br>
                        - <b>มากกว่า / น้อยกว่า (ตัวเลข):</b> ระบุได้ <b>ทีละเกณฑ์เดียว</b>ต่อ 1 rule (เทียบ <code>float</code> จากผล Lab) — ถ้าต้องการหลายช่วง เช่น pH &gt; 7 หรือ pH &lt; 5 ให้ <b>บันทึกเป็น 2 rule</b> หรือใช้ Regex แทนถ้าผลเป็นข้อความ<br>
                        - <b>Contains:</b> ใช้หา <b>คำหนึ่งคำ</b>ต่อครั้ง — ถ้าต้องการหลายคำแบบ OR ให้ใช้ <b>Regex</b> รวมด้วย <code>|</code> จะยืดหยุ่นกว่า<br>
                        - <b>Protocol (Dose Range):</b> เปิดสวิตช์ <b>กำหนดเงื่อนไขขนาดยา Protocol (Dose Range)</b> เมื่อต้องการแยก rule ตามขนาดยา (เทียบกับ qty ใน HOSxP opitemrece ตามหน่วยที่ตั้ง) — ปิดแล้วจะเฝ้าระวังทุกขนาดยา
                    </div>
                    
                    <div class="row">
                        <div class="col-md-5 border-end pr-4" id="uaFormCard">
                            <h6 class="fw-bold text-primary mb-3">
                                <i class="fas fa-plus-circle me-2" id="uaFormIcon"></i><span id="uaFormTitle">เพิ่มเงื่อนไขเฝ้าระวัง UA</span>
                            </h6>
                            <form method="POST" action="monitor_ua.php" id="uaRuleForm">
                                <input type="hidden" name="action" id="ua_form_action" value="add_ua">
                                <input type="hidden" name="rule_id" id="ua_rule_id" value="">
                                
                                <div class="mb-3">
                                    <label class="form-label fw-bold small text-muted mb-1">1. เลือกยาที่ต้องการเฝ้าระวัง (จาก HOSxP)</label>
                                    <select class="form-select select2-drug" name="drug" required><?php echo $opt_drugs; ?></select>
                                </div>

                                <div class="border rounded p-3 mb-3 bg-white">
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox" role="switch" id="use_dose_condition" name="use_dose_condition" value="1">
                                        <label class="form-check-label fw-bold text-primary" for="use_dose_condition">
                                            <i class="fas fa-pills me-1"></i> กำหนดเงื่อนไขขนาดยา Protocol (Dose Range)
                                        </label>
                                        <small class="text-muted d-block ms-4">เปิดเมื่อต้องการแยกกฎตามขนาดยา (ปิด = เฝ้าระวังทุกขนาดยา — ใช้เฉพาะเงื่อนไข Lab)</small>
                                    </div>
                                    <div class="row g-2 dose-fields disabled-section" id="uaDoseFields">
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold small text-primary mb-1">Dose ต่ำสุด</label>
                                            <input type="number" step="0.01" class="form-control form-control-sm" name="dose_min" id="dose_min" placeholder="เช่น 0">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold small text-primary mb-1">Dose สูงสุด</label>
                                            <input type="number" step="0.01" class="form-control form-control-sm" name="dose_max" id="dose_max" placeholder="เช่น 50">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold small text-primary mb-1">หน่วย Dose</label>
                                            <select name="dose_unit" id="dose_unit" class="form-select form-select-sm">
                                                <?php foreach ($dose_unit_options as $u): ?>
                                                <option value="<?php echo htmlspecialchars($u); ?>"><?php echo $u === '' ? '(ไม่ระบุหน่วย - ใช้ทุกหน่วย)' : htmlspecialchars($u); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-12">
                                            <small class="text-muted"><i class="fas fa-info-circle me-1"></i> เปรียบเทียบกับค่า qty ใน order (opitemrece) ของ visit เดียวกัน — ให้สอดคล้องกับหน่วยที่โรงพยาบาลใช้บันทึกใน HOSxP</small>
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-bold small text-muted mb-1">2. เลือกรายการ Lab UA</label>
                                    <select class="form-select form-select-sm border-primary fw-bold" name="lab_code" <?php echo $has_ua_lab_options ? 'required' : 'disabled'; ?>><?php echo $opt_labs; ?></select>
                                    <?php if (!$has_ua_lab_options): ?>
                                    <p class="small text-danger mb-0 mt-1"><i class="fas fa-exclamation-circle me-1"></i>กำหนดรหัส Lab แต่ละรายการในหน้า <a href="hosxp_setting.php" class="fw-bold">ตั้งค่า HOSxP</a> หัวข้อ Urinalysis</p>
                                    <?php endif; ?>
                                </div>

                                <div class="row mb-3 g-2">
                                    <div class="col-5">
                                        <label class="form-label fw-bold small text-muted mb-1">รูปแบบเงื่อนไข</label>
                                        <select class="form-select form-select-sm" name="condition_type" required>
                                            <option value="regex">Regex (ข้อความ)</option>
                                            <option value="contains">มีคำว่า... (Text)</option>
                                            <option value="less_than">น้อยกว่า < (ตัวเลข)</option>
                                            <option value="greater_than">มากกว่า > (ตัวเลข)</option>
                                        </select>
                                    </div>
                                    <div class="col-7">
                                        <label class="form-label fw-bold small text-muted mb-1">ค่าที่ต้องการดักจับ</label>
                                        <input type="text" class="form-control form-control-sm" name="condition_value" placeholder="เช่น 2\+|3\+|4\+ หรือ 7.0" required>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label fw-bold small text-muted mb-1">3. ระดับการเตือน & ข้อความ</label>
                                    <div class="input-group input-group-sm">
                                        <select class="form-select fw-bold" name="alert_level" style="max-width: 100px;" required>
                                            <option value="soft" class="text-warning">🟡 Soft</option>
                                            <option value="hard" class="text-danger">🔴 Hard</option>
                                        </select>
                                        <input type="text" class="form-control" name="alert_message" placeholder="ข้อความเตือน..." required>
                                    </div>
                                </div>

                                <div class="d-flex gap-2">
                                    <button type="submit" id="ua_btn_submit" class="btn btn-success flex-grow-1 fw-bold shadow-sm" <?php echo !$has_ua_lab_options ? 'disabled' : ''; ?>><i class="fas fa-save me-1"></i> บันทึกเงื่อนไข</button>
                                    <button type="button" id="ua_btn_cancel" class="btn btn-secondary fw-bold d-none" onclick="setUaAddMode()"><i class="fas fa-times me-1"></i> ยกเลิก</button>
                                </div>
                            </form>
                        </div>

                        <div class="col-md-7 ps-4">
                            <h6 class="fw-bold text-secondary mb-3"><i class="fas fa-list me-2"></i>รายการเงื่อนไข (Rule Engine) ปัจจุบัน</h6>
                            <table class="table table-bordered table-sm align-middle small">
                                <thead class="table-light text-center">
                                    <tr><th>ระดับ</th><th>ยาที่เฝ้าระวัง (Dose Range)</th><th>เงื่อนไข Lab</th><th class="text-nowrap">แก้ไข / ลบ</th></tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $q_rules = mysqli_query($con, "SELECT * FROM monitor_ua_settings ORDER BY drug_name ASC");
                                    if (mysqli_num_rows($q_rules) > 0) {
                                        while ($r = mysqli_fetch_assoc($q_rules)) {
                                            $lvl_badge = $r['alert_level'] == 'hard' ? '<span class="badge bg-danger">Hard</span>' : '<span class="badge bg-warning text-dark">Soft</span>';
                                            $use_d = !empty($r['use_dose_condition']);
                                            if ($use_d) {
                                                $u = !empty($r['dose_unit']) ? htmlspecialchars($r['dose_unit']) : 'ทุกหน่วย';
                                                $dmin = isset($r['min_dose']) && $r['min_dose'] !== '' && $r['min_dose'] !== null ? $r['min_dose'] : '—';
                                                $dmax = isset($r['max_dose']) && $r['max_dose'] !== '' && $r['max_dose'] !== null ? $r['max_dose'] : '—';
                                                $dose_lbl = "<br><span class='badge bg-primary mt-1'>" . htmlspecialchars($dmin) . " – " . htmlspecialchars($dmax) . " " . $u . "</span>";
                                            } else {
                                                $dose_lbl = "<br><small class='text-muted'>ไม่กำหนด Dose Range</small>";
                                            }
                                            $rule_json = htmlspecialchars(json_encode([
                                                'id' => (int)$r['id'],
                                                'icode' => $r['icode'],
                                                'drug_name' => $r['drug_name'],
                                                'use_dose_condition' => (int)!empty($r['use_dose_condition']),
                                                'min_dose' => $r['min_dose'],
                                                'max_dose' => $r['max_dose'],
                                                'dose_unit' => $r['dose_unit'] ?? '',
                                                'lab_code' => $r['lab_code'],
                                                'lab_name' => $r['lab_name'],
                                                'condition_type' => $r['condition_type'],
                                                'condition_value' => $r['condition_value'],
                                                'alert_level' => $r['alert_level'],
                                                'alert_message' => $r['alert_message'],
                                            ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                                            echo "<tr class='ua-rule-row' data-rule-id=\"{$r['id']}\">
                                                    <td class='text-center'>{$lvl_badge}</td>
                                                    <td class='fw-bold'>" . htmlspecialchars($r['drug_name']) . " {$dose_lbl}</td>
                                                    <td><b>" . htmlspecialchars($r['lab_name']) . "</b><br><span class='text-primary'>" . htmlspecialchars($r['condition_type']) . ' : ' . htmlspecialchars($r['condition_value']) . "</span></td>
                                                    <td class='text-center text-nowrap'>
                                                        <button type='button' class='btn btn-sm btn-outline-primary ua-btn-edit me-1' data-rule=\"{$rule_json}\" title='แก้ไข'><i class='fas fa-edit'></i></button>
                                                        <form method='POST' style='display:inline;' onsubmit=\"return confirm('ยืนยันลบการตั้งค่านี้?');\">
                                                            <input type='hidden' name='action' value='delete_ua'>
                                                            <input type='hidden' name='del_id' value='{$r['id']}'>
                                                            <button type='submit' class='btn btn-sm btn-outline-danger py-0 px-2' title='ลบ'><i class='fas fa-trash'></i></button>
                                                        </form>
                                                    </td>
                                                  </tr>";
                                        }
                                    } else {
                                        echo "<tr><td colspan='4' class='text-center text-muted py-3'>ยังไม่มีการตั้งค่าเงื่อนไข UA</td></tr>";
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<script src="js/jquery-3.7.0.min.js"></script>
<script src="js/bootstrap.bundle.min.js"></script>
<script src="js/select2.min.js"></script>
<script src="js/flatpickr.min.js"></script>
<script>
    function toggleUaDoseFields() {
        const on = $('#use_dose_condition').is(':checked');
        const $sec = $('#uaDoseFields');
        if (on) {
            $sec.removeClass('disabled-section');
            $('#dose_min, #dose_max').prop('required', true);
        } else {
            $sec.addClass('disabled-section');
            $('#dose_min, #dose_max').prop('required', false);
        }
    }

    /** เพิ่ม option ถ้ายังไม่มี (กรณีแก้ไข rule เก่า / ยาถูกถอดจากรายการ HOSxP) */
    function ensureUaSelectOption($select, value, labelText) {
        if (value === undefined || value === '') return;
        const exists = $select.find('option').filter(function() { return this.value === value; }).length > 0;
        if (!exists) {
            const opt = new Option(labelText || value, value, true, true);
            $select.append(opt);
        }
    }

    function setUaAddMode() {
        document.getElementById('uaRuleForm').reset();
        $('#ua_form_action').val('add_ua');
        $('#ua_rule_id').val('');
        $('#uaFormTitle').text('เพิ่มเงื่อนไขเฝ้าระวัง UA');
        $('#uaFormIcon').removeClass('fa-edit').addClass('fa-plus-circle');
        $('#ua_btn_submit').removeClass('btn-primary').addClass('btn-success').html('<i class="fas fa-save me-1"></i> บันทึกเงื่อนไข');
        $('#ua_btn_cancel').addClass('d-none');
        $('.select2-drug').val(null).trigger('change');
        $('#use_dose_condition').prop('checked', false);
        toggleUaDoseFields();
        $('tr.ua-editing-row').removeClass('ua-editing-row');
    }

    function setUaEditMode(rule) {
        $('#ua_form_action').val('edit_ua');
        $('#ua_rule_id').val(rule.id);
        const drugVal = rule.icode + '|' + rule.drug_name;
        ensureUaSelectOption($('.select2-drug'), drugVal, rule.drug_name + ' (icode: ' + rule.icode + ')');
        $('.select2-drug').val(drugVal).trigger('change');

        const labVal = rule.lab_code + '|' + rule.lab_name;
        ensureUaSelectOption($('select[name="lab_code"]'), labVal, rule.lab_name);
        $('select[name="lab_code"]').val(labVal);

        $('#use_dose_condition').prop('checked', !!rule.use_dose_condition);
        $('#dose_min').val(rule.min_dose != null && rule.min_dose !== '' ? rule.min_dose : '');
        $('#dose_max').val(rule.max_dose != null && rule.max_dose !== '' ? rule.max_dose : '');
        $('#dose_unit').val(rule.dose_unit != null ? rule.dose_unit : '');
        $('select[name="condition_type"]').val(rule.condition_type);
        $('input[name="condition_value"]').val(rule.condition_value);
        $('select[name="alert_level"]').val(rule.alert_level);
        $('input[name="alert_message"]').val(rule.alert_message);
        toggleUaDoseFields();

        $('#uaFormTitle').text('แก้ไขเงื่อนไข (ID: ' + rule.id + ')');
        $('#uaFormIcon').removeClass('fa-plus-circle').addClass('fa-edit');
        $('#ua_btn_submit').removeClass('btn-success').addClass('btn-primary').html('<i class="fas fa-save me-1"></i> บันทึกการแก้ไข');
        $('#ua_btn_cancel').removeClass('d-none');
        $('tr.ua-editing-row').removeClass('ua-editing-row');
        $('tr.ua-rule-row[data-rule-id="' + rule.id + '"]').addClass('ua-editing-row');
        document.getElementById('uaFormCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    $(document).ready(function() {
        $('.select2-drug').select2({ theme: 'bootstrap-5', width: '100%' });
        $('#use_dose_condition').on('change', toggleUaDoseFields);
        toggleUaDoseFields();

        $(document).on('click', '.ua-btn-edit', function() {
            let rule = $(this).data('rule');
            if (typeof rule === 'string') {
                try { rule = JSON.parse(rule); } catch (e) { return; }
            }
            if (!rule || !rule.id) return;
            setUaEditMode(rule);
        });

        let dStart = new Date();
        dStart.setFullYear(dStart.getFullYear() - 1); // Default 1 ปีย้อนหลัง
        flatpickr('#filter_start', { dateFormat: 'Y-m-d', defaultDate: dStart });
        flatpickr('#filter_end', { dateFormat: 'Y-m-d', defaultDate: new Date() });

        if ($('#monitor-tab').hasClass('active')) { fetchUAData(); }
    });

    function renderUATable(groups, tbodyId, emptyMsg) {
        if (!groups || groups.length === 0) {
            $(tbodyId).html(`<tr><td colspan="7" class="text-center py-4 text-success fw-bold"><i class="fas fa-check-circle me-2"></i>${emptyMsg}</td></tr>`);
            return;
        }
        let html = '';
        groups.forEach(grp => {
            html += `<tr class="hn-group-header">
                <td colspan="7"><i class="fas fa-user-injured me-2 text-primary"></i>HN: <b>${grp.hn}</b> — ${grp.ptname}</td>
            </tr>`;
            grp.visits.forEach(v => {
                let badgeClass = v.alert_level === 'hard' ? 'bg-danger' : 'bg-warning text-dark';
                html += `<tr class="visit-row">
                    <td class="text-center small text-muted">↳ visit (VN: ${v.vn})</td>
                    <td class="small">${grp.ptname}</td>
                    <td class="text-center fw-bold">${v.vstdate}</td>
                    <td class="small fw-bold text-primary">${v.drug_name} <br><span class="text-muted fw-normal">(Dose: ${v.dose_qty})</span></td>
                    <td class="small"><b>${v.lab_name}:</b> <span class="text-danger fw-bold">${v.lab_result}</span>${v.lab_date ? `<br><span class="text-muted"><i class="fas fa-calendar-check me-1"></i>ตรวจเมื่อ: ${v.lab_date}</span>` : ''}</td>
                    <td class="small"><span class="badge ${badgeClass} me-1">${v.alert_level.toUpperCase()}</span> ${v.alert_msg}</td>
                    <td class="text-center">
                        <a href="smart_drug.php?hn=${grp.hn}" target="_blank" class="btn btn-sm btn-dark fw-bold py-0"><i class="fas fa-search"></i></a>
                    </td>
                </tr>`;
            });
        });
        $(tbodyId).html(html);
    }

    function fetchUAData() {
        let sDate = $('#filter_start').val();
        let eDate = $('#filter_end').val();

        let loading = '<tr><td colspan="7" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i>กำลังประมวลผล...</td></tr>';
        $('#hardTbody, #softTbody').html(loading);

        fetch(`fetch_ua_monitor_api.php?start_date=${sDate}&end_date=${eDate}`)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                $('#hardCount').text(data.meta.hard_count || 0);
                $('#softCount').text(data.meta.soft_count || 0);
                renderUATable(data.hard_groups, '#hardTbody', 'ไม่พบ Hard Alert ในช่วงเวลานี้');
                renderUATable(data.soft_groups, '#softTbody', 'ไม่พบ Soft Alert ในช่วงเวลานี้');
            } else {
                $('#hardTbody, #softTbody').html(`<tr><td colspan="7" class="text-center text-danger py-4 fw-bold">${data.message}</td></tr>`);
            }
        })
        .catch(err => {
            console.error(err);
            $('#hardTbody, #softTbody').html('<tr><td colspan="7" class="text-center text-danger py-4 fw-bold"><i class="fas fa-wifi me-2"></i>เกิดข้อผิดพลาดในการเชื่อมต่อ</td></tr>');
        });
    }
</script>
</body>
</html>