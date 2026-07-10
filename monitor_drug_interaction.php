<?php
session_start();
if (!isset($_SESSION['user_id']) && !isset($_SESSION['full_name'])) {
    header("Location: login.php");
    exit;
}
include('connect.php');
include('hosxp_tmt_drug_helper.php');
mysqli_set_charset($con, "utf8");

function di_escape($con, $v) {
    return mysqli_real_escape_string($con, $v ?? '');
}

function di_alert_level_from_row($row) {
    if (!empty($row['alert_level']) && in_array($row['alert_level'], ['hard', 'soft'], true)) {
        return $row['alert_level'];
    }
    // รองรับข้อมูลเก่าที่ยังมี severity
    if (isset($row['severity']) && (int)$row['severity'] >= 3) {
        return 'hard';
    }
    return 'soft';
}

function di_alert_badge_html($level) {
    if ($level === 'hard') {
        return '<span class="badge bg-danger">Hard Alert</span>';
    }
    return '<span class="badge bg-warning text-dark">Soft Alert</span>';
}

function di_build_dose_sql($con, $use_dose, $prefix) {
    if (!$use_dose) {
        return [
            'use' => 0,
            'min' => 'NULL',
            'max' => 'NULL',
        ];
    }
    $min = mysqli_real_escape_string($con, $_POST["{$prefix}_min"] ?? '');
    $max = mysqli_real_escape_string($con, $_POST["{$prefix}_max"] ?? '');
    return [
        'use' => 1,
        'min' => ($min !== '') ? "'$min'" : 'NULL',
        'max' => ($max !== '') ? "'$max'" : 'NULL',
    ];
}

function di_dose_config_warning($row, $which = 1) {
    return '';
}

function di_dose_condition_label($row, $which = 1) {
    $use_key = $which === 2 ? 'use_dose2_condition' : 'use_dose1_condition';
    $min_key = $which === 2 ? 'dose2_min' : 'dose1_min';
    $max_key = $which === 2 ? 'dose2_max' : 'dose1_max';
    if (empty($row[$use_key])) {
        return '';
    }
    $min = $row[$min_key] ?? '';
    $max = $row[$max_key] ?? '';
    if ($min === '' && $max === '') {
        return '';
    }
    return trim("{$min} – {$max} mg/วัน");
}

$has_di_dose_cols = ensure_di_dose_columns($con);

// --- CRUD ตั้งค่า ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_di' || $action === 'edit_di') {
        $drug_a = hosxp_parse_drug_option($_POST['drug_a'] ?? '');
        $drug_b = hosxp_parse_drug_option($_POST['drug_b'] ?? '');

        $drug_icode = di_escape($con, $drug_a['icode']);
        $drug_name = di_escape($con, $drug_a['name']);
        $hosxp_icode = di_escape($con, $drug_b['icode']);
        $hosxp_drug_name = di_escape($con, $drug_b['name']);
        $drug_id = 0;

        $alert_level = ($_POST['alert_level'] ?? '') === 'hard' ? 'hard' : 'soft';
        $interaction_type = di_escape($con, $_POST['interaction_type'] ?? 'drug_drug');
        $detail_text = di_escape($con, $_POST['detail_text'] ?? '');
        $check_mode = ($_POST['check_mode'] ?? '') === 'lookback' ? 'lookback' : 'same_visit';
        $lookback_days = $check_mode === 'lookback' ? max(1, (int)($_POST['lookback_days'] ?? 7)) : 0;
        $active_status = isset($_POST['active_status']) ? 1 : 0;
        $dose1 = di_build_dose_sql($con, isset($_POST['use_dose1_condition']), 'dose1');
        $dose2 = di_build_dose_sql($con, isset($_POST['use_dose2_condition']), 'dose2');

        if ($drug_icode === '') {
            $_SESSION['di_error'] = 'กรุณาเลือกยาที่ 1 จาก HOSxP';
            header("Location: monitor_drug_interaction.php?tab=settings");
            exit;
        }
        if ($hosxp_icode === '') {
            $_SESSION['di_error'] = 'กรุณาเลือกยาที่ 2 จาก HOSxP';
            header("Location: monitor_drug_interaction.php?tab=settings");
            exit;
        }
        if ($drug_icode === $hosxp_icode) {
            $_SESSION['di_error'] = 'ยาที่ 1 และยาที่ 2 ต้องไม่ใช่รายการเดียวกัน';
            header("Location: monitor_drug_interaction.php?tab=settings");
            exit;
        }

        if ($action === 'add_di') {
            $dose_cols = $has_di_dose_cols
                ? ", use_dose1_condition, dose1_min, dose1_max, use_dose2_condition, dose2_min, dose2_max"
                : '';
            $dose_vals = $has_di_dose_cols
                ? ", {$dose1['use']}, {$dose1['min']}, {$dose1['max']}, {$dose2['use']}, {$dose2['min']}, {$dose2['max']}"
                : '';
            $sql = "INSERT INTO monitor_drug_interaction_settings
                (drug_id, drug_name, drug_icode, hosxp_icode, hosxp_drug_name, interaction_type, alert_level, detail_text,
                 check_mode, lookback_days{$dose_cols}, active_status)
                VALUES ('$drug_id', '$drug_name', '$drug_icode', '$hosxp_icode', '$hosxp_drug_name', '$interaction_type', '$alert_level', '$detail_text',
                        '$check_mode', '$lookback_days'{$dose_vals}, '$active_status')";
            if (mysqli_query($con, $sql)) {
                $_SESSION['di_msg'] = 'บันทึกกฎ Drug Interaction สำเร็จ';
            } else {
                $_SESSION['di_error'] = 'เกิดข้อผิดพลาด: ' . mysqli_error($con);
            }
        } else {
            $id = (int)($_POST['rule_id'] ?? 0);
            if ($id <= 0) {
                $_SESSION['di_error'] = 'ไม่พบรหัสกฎที่ต้องการแก้ไข';
            } else {
                $dose_set = $has_di_dose_cols
                    ? ", use_dose1_condition={$dose1['use']}, dose1_min={$dose1['min']}, dose1_max={$dose1['max']},
                       use_dose2_condition={$dose2['use']}, dose2_min={$dose2['min']}, dose2_max={$dose2['max']}"
                    : '';
                $sql = "UPDATE monitor_drug_interaction_settings SET
                    drug_id='$drug_id', drug_name='$drug_name', drug_icode='$drug_icode',
                    hosxp_icode='$hosxp_icode', hosxp_drug_name='$hosxp_drug_name',
                    interaction_type='$interaction_type', alert_level='$alert_level', detail_text='$detail_text',
                    check_mode='$check_mode', lookback_days='$lookback_days'{$dose_set},
                    active_status='$active_status'
                    WHERE id='$id'";
                if (mysqli_query($con, $sql)) {
                    $_SESSION['di_msg'] = 'แก้ไขกฎ Drug Interaction สำเร็จ';
                } else {
                    $_SESSION['di_error'] = 'เกิดข้อผิดพลาด: ' . mysqli_error($con);
                }
            }
        }
        header("Location: monitor_drug_interaction.php?tab=settings");
        exit;
    }

    if ($action === 'delete_di') {
        $id = (int)($_POST['del_id'] ?? 0);
        if ($id > 0) {
            mysqli_query($con, "DELETE FROM monitor_drug_interaction_settings WHERE id = $id");
            $_SESSION['di_msg'] = 'ลบกฎ Drug Interaction สำเร็จ';
        }
        header("Location: monitor_drug_interaction.php?tab=settings");
        exit;
    }
}

// Dropdown ยาจาก HOSxP ทั้งคู่ (ชื่อจาก TMT)
$con_hosxp = hosxp_connect($con);
$hosxp_drugs = $con_hosxp ? hosxp_fetch_active_drugs_tmt($con_hosxp) : [];
if ($con_hosxp) {
    mysqli_close($con_hosxp);
}
$opt_hosxp_drugs_a = hosxp_build_drug_options_html($hosxp_drugs, '- เลือกยาที่ 1 จาก HOSxP -');
$opt_hosxp_drugs_b = hosxp_build_drug_options_html($hosxp_drugs, '- เลือกยาที่ 2 จาก HOSxP -');

$type_options = [
    'drug_drug' => 'Drug - Drug Interaction',
    'drug_food' => 'Drug - Food Interaction',
    'drug_disease' => 'Drug - Disease Interaction',
    'other' => 'อื่นๆ',
];

$monitor_rules = [];
$tbl_di_mon = mysqli_query($con, "SHOW TABLES LIKE 'monitor_drug_interaction_settings'");
if ($tbl_di_mon && mysqli_num_rows($tbl_di_mon) > 0) {
    $q_mon_rules = mysqli_query(
        $con,
        "SELECT id, drug_name, hosxp_drug_name, alert_level
         FROM monitor_drug_interaction_settings
         WHERE active_status = 1
           AND TRIM(drug_icode) <> ''
           AND TRIM(hosxp_icode) <> ''
         ORDER BY drug_name ASC, hosxp_drug_name ASC"
    );
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
    <title>Monitor Interaction - THAM SAFE</title>
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
        .hn-group-header td { border-left: 4px solid #6f42c1; }
        .visit-row td { background: #fff; }
        .visit-row td:first-child { padding-left: 2rem; }
        .section-block .table thead { background-color: #dc3545 !important; color: #fff !important; }
        .section-warn .table thead { background-color: #fd7e14 !important; color: #fff !important; }
        #diFormCard.edit-mode .card-header { background-color: #6f42c1 !important; border-color: #6f42c1; }
        #diFormCard.copy-mode .card-header { background-color: #6c757d !important; border-color: #6c757d; }
        tr.di-editing-row { background-color: #f3e8ff !important; }
        .disabled-section { opacity: 0.45; pointer-events: none; }
        .lookback-wrap.disabled-section { opacity: 0.45; pointer-events: none; }
        .di-detail-cell { font-size: 0.85rem; white-space: pre-wrap; word-break: break-word; min-width: 280px; max-width: 480px; }
        .di-detail-label { font-weight: 700; color: #495057; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.02em; }
        .di-detail-text { color: #212529; line-height: 1.45; }
        .di-check-meta { color: #0d6efd; line-height: 1.45; font-weight: 600; }
        #diRuleFilterBox { background: #fafbfc; }
        #diRuleFilterBox .form-check-label { line-height: 1.35; }
        #diRuleFilterBox .di-rule-cb-label { cursor: pointer; }
    </style>
</head>
<body>
<div class="wrapper">
    <?php include('sidebar.php'); ?>
    <div id="content" class="content">
        <div class="container-fluid py-4">
            <h4 class="fw-bold mb-4 text-dark"><i class="fas fa-exclamation-triangle text-danger me-2"></i>Monitor Interaction</h4>

            <?php if (!empty($_SESSION['di_msg'])): ?>
                <div class="alert alert-success fw-bold"><i class="fas fa-check-circle me-1"></i><?php echo htmlspecialchars($_SESSION['di_msg']); ?></div>
                <?php unset($_SESSION['di_msg']); ?>
            <?php endif; ?>
            <?php if (!empty($_SESSION['di_error'])): ?>
                <div class="alert alert-danger fw-bold"><i class="fas fa-times-circle me-1"></i><?php echo htmlspecialchars($_SESSION['di_error']); ?></div>
                <?php unset($_SESSION['di_error']); ?>
            <?php endif; ?>

            <ul class="nav nav-tabs fw-bold" id="diTab" role="tablist">
                <li class="nav-item">
                    <button class="nav-link <?php echo (!isset($_GET['tab']) || $_GET['tab'] !== 'settings') ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#monitor" type="button"><i class="fas fa-desktop me-1"></i> รายการเฝ้าระวังหน้างาน</button>
                </li>
                <li class="nav-item">
                    <button class="nav-link <?php echo (isset($_GET['tab']) && $_GET['tab'] === 'settings') ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#settings" type="button"><i class="fas fa-cogs me-1"></i> ตั้งค่า Drug Interaction</button>
                </li>
            </ul>

            <div class="tab-content bg-white border border-top-0 p-4 rounded-bottom shadow-sm">

                <div class="tab-pane fade <?php echo (!isset($_GET['tab']) || $_GET['tab'] !== 'settings') ? 'show active' : ''; ?>" id="monitor">
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
                                <label class="form-label small fw-bold mb-1">ช่วงเวลาเร็ว</label>
                                <div class="btn-group w-100" role="group">
                                    <button type="button" class="btn btn-outline-secondary btn-sm fw-bold" onclick="setDIDateRange(7)">7 วัน</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm fw-bold" onclick="setDIDateRange(30)">1 เดือน</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm fw-bold" onclick="setDIDateRange(90)">3 เดือน</button>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <button class="btn btn-primary btn-sm fw-bold shadow-sm w-100" type="button" onclick="fetchDIData()">
                                    <i class="fas fa-sync-alt me-1"></i> ประมวลผล / ดึงรายงาน
                                </button>
                            </div>
                        </div>
                        <div class="small text-muted mt-2"><i class="fas fa-info-circle me-1"></i> แนะนำเริ่มที่ <b>1 เดือน</b> — ช่วงยาวขึ้นจะใช้เวลานานขึ้น</div>
                        <div id="reportMeta" class="alert alert-info py-2 px-3 mt-2 mb-0 small fw-bold d-none"></div>
                    </div>

                    <div class="border rounded p-3 mb-3" id="diRuleFilterBox">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                            <label class="form-label fw-bold small mb-0">
                                <i class="fas fa-filter me-1 text-primary"></i> เลือกคู่ยาที่ต้องการรายงาน
                                <span class="text-muted fw-normal" id="diRuleSelectedCount"></span>
                            </label>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-primary fw-bold" onclick="diSelectAllRules(true)">เลือกทั้งหมด</button>
                                <button type="button" class="btn btn-outline-secondary fw-bold" onclick="diSelectAllRules(false)">ยกเลิกทั้งหมด</button>
                            </div>
                        </div>
                        <?php if (empty($monitor_rules)): ?>
                        <div class="small text-muted">ยังไม่มีกฎที่เปิดใช้งาน — ไปที่แท็บ <b>ตั้งค่า Drug Interaction</b> เพื่อเพิ่มกฎ</div>
                        <?php else: ?>
                        <div class="row g-2" id="diRuleCheckboxes">
                            <?php foreach ($monitor_rules as $mr):
                                $lvl = di_alert_level_from_row($mr);
                                $lvl_badge = $lvl === 'hard'
                                    ? '<span class="badge bg-danger ms-1">Hard</span>'
                                    : '<span class="badge bg-warning text-dark ms-1">Soft</span>';
                            ?>
                            <div class="col-md-6 col-xl-4">
                                <div class="form-check border rounded px-2 py-1 bg-white h-100">
                                    <input class="form-check-input di-rule-cb" type="checkbox"
                                           value="<?php echo (int)$mr['id']; ?>"
                                           id="di_rule_cb_<?php echo (int)$mr['id']; ?>" checked>
                                    <label class="form-check-label small di-rule-cb-label" for="di_rule_cb_<?php echo (int)$mr['id']; ?>">
                                        <span class="text-primary fw-bold"><?php echo htmlspecialchars($mr['drug_name']); ?></span>
                                        <i class="fas fa-arrows-left-right text-muted mx-1"></i>
                                        <span class="text-danger fw-bold"><?php echo htmlspecialchars($mr['hosxp_drug_name']); ?></span>
                                        <?php echo $lvl_badge; ?>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="section-block mb-4">
                        <h6 class="fw-bold text-danger mb-2"><i class="fas fa-exclamation-circle me-2"></i>Hard Alert <span id="blockCount" class="badge bg-danger">0</span></h6>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle border mb-0">
                                <thead class="text-center">
                                    <tr>
                                        <th>HN</th><th class="text-start">ชื่อ-สกุล</th>
                                        <th class="text-start">ยาที่ 1 (วันที่ · qty · วิธีใช้)</th>
                                        <th class="text-start">ยาที่ 2 (วันที่ · qty · วิธีใช้)</th>
                                        <th>Alert</th>
                                        <th class="text-start" style="min-width:300px">รายละเอียด / วิธีตรวจ</th><th>จัดการ</th>
                                    </tr>
                                </thead>
                                <tbody id="blockTbody"><tr><td colspan="7" class="text-center py-4 text-muted">กดปุ่มประมวลผลเพื่อดึงข้อมูล</td></tr></tbody>
                            </table>
                        </div>
                    </div>

                    <div class="section-warn mb-4">
                        <h6 class="fw-bold text-warning mb-2"><i class="fas fa-exclamation-triangle me-2"></i>Soft Alert <span id="warnCount" class="badge bg-warning text-dark">0</span></h6>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle border mb-0">
                                <thead class="text-center">
                                    <tr>
                                        <th>HN</th><th class="text-start">ชื่อ-สกุล</th>
                                        <th class="text-start">ยาที่ 1 (วันที่ · qty · วิธีใช้)</th>
                                        <th class="text-start">ยาที่ 2 (วันที่ · qty · วิธีใช้)</th>
                                        <th>Alert</th>
                                        <th class="text-start" style="min-width:300px">รายละเอียด / วิธีตรวจ</th><th>จัดการ</th>
                                    </tr>
                                </thead>
                                <tbody id="warnTbody"><tr><td colspan="7" class="text-center py-4 text-muted">กดปุ่มประมวลผลเพื่อดึงข้อมูล</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade <?php echo (isset($_GET['tab']) && $_GET['tab'] === 'settings') ? 'show active' : ''; ?>" id="settings">
                    <div class="alert alert-light border small mb-4">
                        <i class="fas fa-info-circle text-primary me-2"></i>
                        <b>การตั้งค่า:</b> เลือก <b>ยาที่ 1 และยาที่ 2 จาก HOSxP</b> (แสดงชื่อจาก TMT code)
                        กำหนดระดับการเตือน (Hard / Soft Alert) รายละเอียดทางคลินิก และเลือกรูปแบบการตรวจ —
                        <b>Visit เดียวกัน</b> หรือ <b>ย้อนหลังตามจำนวนวัน</b>
                    </div>

                    <div class="row">
                        <div class="col-lg-5 border-end">
                            <div class="card shadow-sm border-0" id="diFormCard">
                                <div class="card-header bg-danger text-white py-2 fw-bold" id="diFormHeader">
                                    <i class="fas fa-plus-circle me-1" id="diFormIcon"></i> <span id="diFormTitle">เพิ่มกฎ Drug Interaction</span>
                                </div>
                                <div class="card-body">
                                    <form method="POST" action="monitor_drug_interaction.php" id="diRuleForm">
                                        <input type="hidden" name="action" id="di_form_action" value="add_di">
                                        <input type="hidden" name="rule_id" id="di_rule_id" value="">

                                        <div class="mb-3">
                                            <label class="form-label fw-bold small">ชื่อยา 1 — จาก HOSxP (TMT)</label>
                                            <select name="drug_a" id="drug_a" class="form-select select2-hosxp" required><?php echo $opt_hosxp_drugs_a; ?></select>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label fw-bold small">ชื่อยา 2 — จาก HOSxP (TMT)</label>
                                            <select name="drug_b" id="drug_b" class="form-select select2-hosxp" required><?php echo $opt_hosxp_drugs_b; ?></select>
                                        </div>

                                        <div class="row g-2 mb-3">
                                            <div class="col-5">
                                                <label class="form-label fw-bold small">ระดับการเตือน</label>
                                                <select name="alert_level" id="alert_level" class="form-select form-select-sm fw-bold" required>
                                                    <option value="soft" class="text-warning">🟡 Soft Alert</option>
                                                    <option value="hard" class="text-danger">🔴 Hard Alert</option>
                                                </select>
                                            </div>
                                            <div class="col-7">
                                                <label class="form-label fw-bold small">ประเภท</label>
                                                <select name="interaction_type" id="interaction_type" class="form-select form-select-sm" required>
                                                    <?php foreach ($type_options as $k => $lbl): ?>
                                                    <option value="<?php echo htmlspecialchars($k); ?>"><?php echo htmlspecialchars($lbl); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label fw-bold small">รายละเอียด (Effect / Mechanism / Management)</label>
                                            <textarea name="detail_text" id="detail_text" class="form-control" rows="5" placeholder="Effect: ...&#10;Mechanism: ...&#10;Management: ..."></textarea>
                                        </div>

                                        <div class="border rounded p-3 mb-3">
                                            <label class="form-label fw-bold small d-block mb-2"><i class="fas fa-pills me-1"></i> เงื่อนไขขนาดยา (mg/วัน)</label>
                                            <div class="form-check form-switch mb-2">
                                                <input class="form-check-input" type="checkbox" id="use_dose1_condition" name="use_dose1_condition" value="1">
                                                <label class="form-check-label small fw-bold text-primary" for="use_dose1_condition">กำหนดขนาดยา 1 — mg/วันรวมที่สั่งใช้</label>
                                            </div>
                                            <div class="row g-2 mb-3 disabled-section" id="dose1Fields">
                                                <div class="col-6">
                                                    <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="dose1_min" id="dose1_min" placeholder="ต่ำสุด mg/วัน">
                                                </div>
                                                <div class="col-6">
                                                    <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="dose1_max" id="dose1_max" placeholder="สูงสุด mg/วัน">
                                                </div>
                                            </div>
                                            <div class="form-check form-switch mb-2">
                                                <input class="form-check-input" type="checkbox" id="use_dose2_condition" name="use_dose2_condition" value="1">
                                                <label class="form-check-label small fw-bold text-danger" for="use_dose2_condition">กำหนดขนาดยา 2 (ถ้าต้องการ)</label>
                                            </div>
                                            <div class="row g-2 disabled-section" id="dose2Fields">
                                                <div class="col-6">
                                                    <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="dose2_min" id="dose2_min" placeholder="ต่ำสุด mg/วัน">
                                                </div>
                                                <div class="col-6">
                                                    <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="dose2_max" id="dose2_max" placeholder="สูงสุด mg/วัน">
                                                </div>
                                            </div>
                                            <small class="text-muted d-block mt-2">
                                                อิง <b>mg/วันรวม</b> จากวิธีใช้ (strength × จำนวนเม็ด/วัน) — ไม่ใช่ strength บนฉลากอย่างเดียว<br>
                                                ตัวอย่าง <b>simvastatin 10 mg</b> + ต่ำสุด <b>20</b> สูงสุด <b>80</b>:<br>
                                                · 1 เม็ด/วัน = 10 mg/วัน → <span class="text-success">ไม่เตือน</span><br>
                                                · 2 เม็ด วันละ 1 ครั้ง = 20 mg/วัน → <span class="text-danger">เตือน</span><br>
                                                · ครึ่งเม็ด/วัน เช่น simvastatin 40 mg ครึ่งเม็ด = 20 mg/วัน → รองรับ<br>
                                                เลือก icode ตามฉลากที่สั่ง (10 mg หรือ 20 mg) แล้วกำหนดช่วง mg/วันรวมที่ต้องการ
                                            </small>
                                        </div>

                                        <div class="border rounded p-3 mb-3">
                                            <label class="form-label fw-bold small d-block mb-2"><i class="fas fa-clock me-1"></i> รูปแบบการตรวจจับ</label>
                                            <div class="form-check mb-2">
                                                <input class="form-check-input" type="radio" name="check_mode" id="check_same_visit" value="same_visit" checked>
                                                <label class="form-check-label small fw-bold" for="check_same_visit">Visit เดียวกัน (VN เดียวกัน)</label>
                                            </div>
                                            <div class="form-check mb-2">
                                                <input class="form-check-input" type="radio" name="check_mode" id="check_lookback" value="lookback">
                                                <label class="form-check-label small fw-bold" for="check_lookback">ตรวจช่วงทับซ้อน (qty + วิธีใช้)</label>
                                            </div>
                                            <div class="lookback-wrap disabled-section" id="lookbackWrap">
                                                <label class="form-label small mb-1">ค้นหาย้อนหลังสูงสุด</label>
                                                <div class="input-group input-group-sm">
                                                    <input type="number" name="lookback_days" id="lookback_days" class="form-control" min="1" max="365" value="30" placeholder="เช่น 30">
                                                    <span class="input-group-text">วัน</span>
                                                </div>
                                                <small class="text-muted d-block mt-1">คำนวณ <b>จำนวนวันครอบคลุม</b> จาก qty ÷ ปริมาณต่อวัน (เช่น 55 เม็ด วันละ 1 เม็ด = 55 วัน) แล้วตรวจว่าช่วงให้ยาทั้งสองยาทับซ้อนกันหรือไม่ — qty 1 เม็ด = 1 วัน</small>
                                            </div>
                                        </div>

                                        <div class="form-check form-switch mb-3">
                                            <input class="form-check-input" type="checkbox" name="active_status" id="active_status" value="1" checked>
                                            <label class="form-check-label fw-bold small" for="active_status">เปิดใช้งานกฎนี้</label>
                                        </div>

                                        <div class="d-flex gap-2">
                                            <button type="submit" class="btn btn-danger flex-grow-1 fw-bold" id="di_btn_submit"><i class="fas fa-save me-1"></i> บันทึก</button>
                                            <button type="button" class="btn btn-secondary fw-bold d-none" id="di_btn_cancel" onclick="setDiAddMode()"><i class="fas fa-times me-1"></i> ยกเลิก</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-7 ps-lg-4 mt-4 mt-lg-0">
                            <h6 class="fw-bold text-secondary mb-3"><i class="fas fa-list me-2"></i>รายการ Drug Interaction ที่ตั้งไว้</h6>
                            <div class="table-responsive">
                                <table class="table table-bordered table-sm align-middle small">
                                    <thead class="table-light text-center">
                                        <tr>
                                            <th>ID</th>
                                            <th class="text-start">ยา 1 → ยา 2</th>
                                            <th>Dose</th>
                                            <th>Alert</th>
                                            <th>การตรวจ</th>
                                            <th>สถานะ</th>
                                            <th>จัดการ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $tbl_exists = mysqli_query($con, "SHOW TABLES LIKE 'monitor_drug_interaction_settings'");
                                        if (!$tbl_exists || mysqli_num_rows($tbl_exists) === 0) {
                                            echo '<tr><td colspan="7" class="text-center text-danger py-3">ยังไม่มีตาราง — กรุณารัน <a href="upgrade.php">อัปเกรดฐานข้อมูล</a></td></tr>';
                                        } else {
                                            $q_rules = mysqli_query($con, "SELECT * FROM monitor_drug_interaction_settings ORDER BY drug_name ASC, hosxp_drug_name ASC");
                                            if (mysqli_num_rows($q_rules) === 0) {
                                                echo '<tr><td colspan="7" class="text-center text-muted py-3">ยังไม่มีการตั้งค่า Drug Interaction</td></tr>';
                                            } else {
                                                while ($r = mysqli_fetch_assoc($q_rules)) {
                                                    $check_lbl = ($r['check_mode'] ?? '') === 'lookback'
                                                        ? 'ย้อนหลัง ' . (int)$r['lookback_days'] . ' วัน'
                                                        : 'Visit เดียวกัน';
                                                    $active_badge = !empty($r['active_status'])
                                                        ? '<span class="badge bg-success">เปิด</span>'
                                                        : '<span class="badge bg-secondary">ปิด</span>';
                                                    $dose1_lbl = di_dose_condition_label($r, 1);
                                                    $dose2_lbl = di_dose_condition_label($r, 2);
                                                    $rule_json = htmlspecialchars(json_encode([
                                                        'id' => (int)$r['id'],
                                                        'drug_a' => $r['drug_icode'] . '|' . $r['drug_name'],
                                                        'drug_b' => $r['hosxp_icode'] . '|' . $r['hosxp_drug_name'],
                                                        'alert_level' => di_alert_level_from_row($r),
                                                        'interaction_type' => $r['interaction_type'],
                                                        'detail_text' => $r['detail_text'] ?? '',
                                                        'check_mode' => $r['check_mode'],
                                                        'lookback_days' => (int)$r['lookback_days'],
                                                        'active_status' => (int)!empty($r['active_status']),
                                                        'use_dose1_condition' => (int)!empty($r['use_dose1_condition']),
                                                        'dose1_min' => $r['dose1_min'] ?? '',
                                                        'dose1_max' => $r['dose1_max'] ?? '',
                                                        'use_dose2_condition' => (int)!empty($r['use_dose2_condition']),
                                                        'dose2_min' => $r['dose2_min'] ?? '',
                                                        'dose2_max' => $r['dose2_max'] ?? '',
                                                    ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                                                    echo '<tr class="di-rule-row" data-rule-id="' . (int)$r['id'] . '">';
                                                    echo '<td class="text-center">' . (int)$r['id'] . '</td>';
                                                    echo '<td class="text-start"><span class="fw-bold text-primary">' . htmlspecialchars($r['drug_name']) . '</span>';
                                                    echo ' <i class="fas fa-arrows-left-right text-muted mx-1"></i> ';
                                                    echo '<span class="fw-bold text-danger">' . htmlspecialchars($r['hosxp_drug_name']) . '</span>';
                                                    echo '<br><small class="text-muted">' . htmlspecialchars($r['drug_icode']) . ' ↔ ' . htmlspecialchars($r['hosxp_icode']) . '</small>';
                                                    echo '</td>';
                                                    echo '<td class="text-center small">';
                                                    if ($dose1_lbl !== '') {
                                                        echo '<span class="badge bg-primary d-block mb-1">ยา1: ' . htmlspecialchars($dose1_lbl) . '</span>';
                                                    }
                                                    if ($dose2_lbl !== '') {
                                                        echo '<span class="badge bg-danger d-block">ยา2: ' . htmlspecialchars($dose2_lbl) . '</span>';
                                                    }
                                                    if ($dose1_lbl === '' && $dose2_lbl === '') {
                                                        echo '<span class="text-muted">ไม่กำหนด</span>';
                                                    }
                                                    $dose_warn = di_dose_config_warning($r, 1);
                                                    if ($dose_warn !== '') {
                                                        echo '<small class="text-warning d-block mt-1"><i class="fas fa-exclamation-triangle me-1"></i>' . htmlspecialchars($dose_warn) . '</small>';
                                                    }
                                                    echo '</td>';
                                                    $lvl = di_alert_level_from_row($r);
                                                    echo '<td class="text-center">' . di_alert_badge_html($lvl) . '</td>';
                                                    echo '<td class="text-center small">' . htmlspecialchars($check_lbl) . '</td>';
                                                    echo '<td class="text-center">' . $active_badge . '</td>';
                                                    echo '<td class="text-center text-nowrap">';
                                                    echo '<button type="button" class="btn btn-sm btn-outline-primary di-btn-edit me-1" title="แก้ไข" data-rule="' . $rule_json . '"><i class="fas fa-edit"></i></button>';
                                                    echo '<button type="button" class="btn btn-sm btn-outline-secondary di-btn-copy me-1" title="คัดลอก" data-rule="' . $rule_json . '"><i class="fas fa-copy"></i></button>';
                                                    echo '<form method="POST" style="display:inline;" onsubmit="return confirm(\'ยืนยันลบกฎนี้?\');">';
                                                    echo '<input type="hidden" name="action" value="delete_di"><input type="hidden" name="del_id" value="' . (int)$r['id'] . '">';
                                                    echo '<button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button></form>';
                                                    echo '</td></tr>';
                                                }
                                            }
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
</div>

<script src="js/jquery-3.7.0.min.js"></script>
<script src="js/bootstrap.bundle.min.js"></script>
<script src="js/select2.min.js"></script>
<script src="js/flatpickr.min.js"></script>
<script>
function toggleLookbackWrap() {
    const isLb = $('#check_lookback').is(':checked');
    const $w = $('#lookbackWrap');
    if (isLb) {
        $w.removeClass('disabled-section');
        $('#lookback_days').prop('required', true);
    } else {
        $w.addClass('disabled-section');
        $('#lookback_days').prop('required', false);
    }
}

function toggleDiDoseFields(which) {
    const on = $('#use_dose' + which + '_condition').is(':checked');
    const $sec = $('#dose' + which + 'Fields');
    if (on) {
        $sec.removeClass('disabled-section');
        $('#dose' + which + '_min, #dose' + which + '_max').prop('required', true);
    } else {
        $sec.addClass('disabled-section');
        $('#dose' + which + '_min, #dose' + which + '_max').prop('required', false);
    }
}

function ensureSelectOption($sel, val, label) {
    if (!val) return;
    if ($sel.find('option').filter(function() { return this.value === val; }).length === 0) {
        $sel.append(new Option(label || val, val, true, true));
    }
}

function setDiAddMode() {
    document.getElementById('diRuleForm').reset();
    $('#di_form_action').val('add_di');
    $('#di_rule_id').val('');
    $('#diFormCard').removeClass('edit-mode copy-mode');
    $('#diFormHeader').removeClass('bg-primary bg-secondary').addClass('bg-danger');
    $('#diFormIcon').removeClass('fa-edit').addClass('fa-plus-circle');
    $('#diFormTitle').text('เพิ่มกฎ Drug Interaction');
    $('#di_btn_submit').removeClass('btn-primary').addClass('btn-danger').html('<i class="fas fa-save me-1"></i> บันทึก');
    $('#di_btn_cancel').addClass('d-none');
    $('#active_status').prop('checked', true);
    $('#check_same_visit').prop('checked', true);
    $('#use_dose1_condition, #use_dose2_condition').prop('checked', false);
    $('.select2-hosxp').val(null).trigger('change');
    toggleLookbackWrap();
    toggleDiDoseFields(1);
    toggleDiDoseFields(2);
    $('tr.di-editing-row').removeClass('di-editing-row');
}

function fillDiRuleForm(rule) {
    ensureSelectOption($('#drug_a'), rule.drug_a, rule.drug_a);
    $('#drug_a').val(rule.drug_a).trigger('change');
    ensureSelectOption($('#drug_b'), rule.drug_b, rule.drug_b);
    $('#drug_b').val(rule.drug_b).trigger('change');

    $('#alert_level').val(rule.alert_level === 'hard' ? 'hard' : 'soft');
    $('#interaction_type').val(rule.interaction_type);
    $('#detail_text').val(rule.detail_text || '');
    $('#active_status').prop('checked', !!rule.active_status);

    if (rule.check_mode === 'lookback') {
        $('#check_lookback').prop('checked', true);
        $('#lookback_days').val(rule.lookback_days || 7);
    } else {
        $('#check_same_visit').prop('checked', true);
        $('#lookback_days').val(rule.lookback_days || 7);
    }
    $('#use_dose1_condition').prop('checked', !!rule.use_dose1_condition);
    $('#dose1_min').val(rule.dose1_min ?? '');
    $('#dose1_max').val(rule.dose1_max ?? '');
    $('#use_dose2_condition').prop('checked', !!rule.use_dose2_condition);
    $('#dose2_min').val(rule.dose2_min ?? '');
    $('#dose2_max').val(rule.dose2_max ?? '');
    toggleLookbackWrap();
    toggleDiDoseFields(1);
    toggleDiDoseFields(2);
}

function setDiEditMode(rule) {
    $('#di_form_action').val('edit_di');
    $('#di_rule_id').val(rule.id);
    $('#diFormCard').removeClass('copy-mode').addClass('edit-mode');
    $('#diFormHeader').removeClass('bg-danger bg-secondary').addClass('bg-primary');
    $('#diFormIcon').removeClass('fa-plus-circle fa-copy').addClass('fa-edit');
    $('#diFormTitle').text('แก้ไขกฎ (ID: ' + rule.id + ')');
    $('#di_btn_submit').removeClass('btn-danger btn-secondary').addClass('btn-primary').html('<i class="fas fa-save me-1"></i> บันทึกการแก้ไข');
    $('#di_btn_cancel').removeClass('d-none');

    fillDiRuleForm(rule);

    $('tr.di-editing-row').removeClass('di-editing-row');
    $('tr.di-rule-row[data-rule-id="' + rule.id + '"]').addClass('di-editing-row');
    document.getElementById('diFormCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function setDiCopyMode(rule) {
    $('#di_form_action').val('add_di');
    $('#di_rule_id').val('');
    $('#diFormCard').removeClass('edit-mode').addClass('copy-mode');
    $('#diFormHeader').removeClass('bg-primary bg-danger').addClass('bg-secondary');
    $('#diFormIcon').removeClass('fa-edit fa-plus-circle').addClass('fa-copy');
    $('#diFormTitle').text('คัดลอกกฎจาก ID: ' + rule.id);
    $('#di_btn_submit').removeClass('btn-primary btn-danger').addClass('btn-secondary').html('<i class="fas fa-save me-1"></i> บันทึกกฎใหม่');
    $('#di_btn_cancel').removeClass('d-none');

    fillDiRuleForm(rule);

    $('tr.di-editing-row').removeClass('di-editing-row');
    $('tr.di-rule-row[data-rule-id="' + rule.id + '"]').addClass('di-editing-row');
    document.getElementById('diFormCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function diEsc(str) {
    if (!str && str !== 0) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function renderDIDrugCell(v, which) {
    const is1 = which === 1;
    const name = diEsc(is1 ? v.drug1_name : v.drug2_name);
    const date = diEsc(is1 ? (v.drug1_date || v.vstdate || '-') : (v.drug2_date || '-'));
    const time = diEsc(is1 ? (v.drug1_time || '') : (v.drug2_time || ''));
    const qty = diEsc(is1 ? v.dose1_qty : v.dose2_qty);
    const cov = diEsc(is1 ? v.drug1_coverage_days : v.drug2_coverage_days);
    const covEnd = diEsc(is1 ? v.drug1_coverage_end : v.drug2_coverage_end);
    const method = diEsc(is1 ? (v.drug1_method || '') : (v.drug2_method || ''));
    const daily = is1 ? v.drug1_daily_dose : v.drug2_daily_dose;
    const dailyMg = diEsc(is1 ? (v.drug1_daily_mg || '') : (v.drug2_daily_mg || ''));
    const color = is1 ? 'text-primary' : 'text-danger';
    const badge = is1 ? 'bg-primary' : 'bg-danger';
    let covLine = '';
    if (cov) {
        covLine = `<div class="small text-success fw-bold"><i class="fas fa-hourglass-half me-1"></i>ครอบคลุม ${cov} วัน${covEnd ? ` (ถึง ${covEnd})` : ''}</div>`;
    }
    let methodLine = `<div class="small mt-2 p-2 rounded border bg-light lh-sm" style="max-width:300px">`;
    methodLine += `<div class="fw-bold text-secondary mb-1"><i class="fas fa-prescription-bottle-alt me-1"></i>วิธีใช้</div>`;
    methodLine += method
        ? `<div class="text-dark">${method}</div>`
        : `<div class="text-muted fst-italic">ไม่พบข้อมูลวิธีใช้</div>`;
    if (dailyMg) {
        methodLine += `<div class="text-success fw-bold mt-1"><i class="fas fa-weight me-1"></i>${dailyMg}</div>`;
    }
    if (daily !== null && daily !== undefined && daily !== '') {
        methodLine += `<div class="text-warning-emphasis fw-bold mt-1">≈ ${daily} หน่วย/วัน → ใช้คำนวณครอบคลุม</div>`;
    }
    methodLine += `</div>`;
    return `<div class="${color} fw-bold">${name}</div>
        <div class="small mt-1"><i class="far fa-calendar-alt me-1"></i><span class="fw-bold">${date}</span>${time ? ` <span class="text-muted">${time} น.</span>` : ''}</div>
        <div class="small fw-bold text-muted mt-1">qty: <span class="text-dark">${qty}</span></div>
        ${methodLine}
        ${covLine}
        <span class="badge ${badge} mt-1">ยา ${which}</span>`;
}

function renderDITable(groups, tbodyId, emptyMsg) {
    if (!groups || groups.length === 0) {
        $(tbodyId).html('<tr><td colspan="7" class="text-center py-4 text-success fw-bold"><i class="fas fa-check-circle me-2"></i>' + emptyMsg + '</td></tr>');
        return;
    }
    let html = '';
    groups.forEach(grp => {
        html += '<tr class="hn-group-header"><td colspan="7"><i class="fas fa-user-injured me-2 text-primary"></i>HN: <b>' + diEsc(grp.hn) + '</b> — ' + diEsc(grp.ptname) + '</td></tr>';
        grp.visits.forEach(v => {
            const sevBadge = v.alert_level === 'hard'
                ? '<span class="badge bg-danger">Hard Alert</span>'
                : '<span class="badge bg-warning text-dark">Soft Alert</span>';
            const detail = diEsc(v.detail_text || '');
            const meta = diEsc(v.check_mode_label || '');
            html += '<tr class="visit-row">';
            html += '<td class="text-center small text-muted">↳ ' + diEsc(grp.hn) + '</td>';
            html += '<td class="small">' + diEsc(grp.ptname) + '</td>';
            html += '<td class="small align-top">' + renderDIDrugCell(v, 1) + '</td>';
            html += '<td class="small align-top">' + renderDIDrugCell(v, 2) + '</td>';
            html += '<td class="text-center align-top">' + sevBadge + '</td>';
            html += '<td class="small align-top di-detail-cell">';
            html += '<div class="di-detail-label mb-1"><i class="fas fa-file-medical me-1"></i>รายละเอียด</div>';
            html += '<div class="di-detail-text">' + (detail || '<span class="text-muted">-</span>') + '</div>';
            html += '<div class="di-detail-label mt-2 mb-1"><i class="fas fa-search me-1"></i>วิธีตรวจ</div>';
            html += '<div class="di-check-meta">' + (meta || '<span class="text-muted fw-normal">-</span>') + '</div>';
            html += '</td>';
            html += '<td class="text-center"><a href="smart_drug.php?hn=' + encodeURIComponent(grp.hn) + '" target="_blank" class="btn btn-sm btn-dark py-0"><i class="fas fa-search"></i></a></td>';
            html += '</tr>';
        });
    });
    $(tbodyId).html(html);
}

function getSelectedDiRuleIds() {
    return $('.di-rule-cb:checked').map(function() { return this.value; }).get();
}

function diSelectAllRules(checked) {
    $('.di-rule-cb').prop('checked', checked);
    diUpdateRuleSelectedCount();
}

function diUpdateRuleSelectedCount() {
    const total = $('.di-rule-cb').length;
    const sel = getSelectedDiRuleIds().length;
    $('#diRuleSelectedCount').text(total > 0 ? '(เลือก ' + sel + ' / ' + total + ' คู่)' : '');
}

function fetchDIData() {
    const sDate = $('#filter_start').val();
    const eDate = $('#filter_end').val();
    const ruleIds = getSelectedDiRuleIds();
    if (ruleIds.length === 0) {
        alert('กรุณาเลือกอย่างน้อย 1 คู่ยาที่ต้องการรายงาน');
        return;
    }
    const loading = '<tr><td colspan="7" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i>กำลังประมวลผล...</td></tr>';
    $('#blockTbody, #warnTbody').html(loading);

    fetch('fetch_drug_interaction_api.php?start_date=' + encodeURIComponent(sDate) + '&end_date=' + encodeURIComponent(eDate) + '&rule_ids=' + encodeURIComponent(ruleIds.join(',')))
        .then(async res => {
            const text = await res.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                const preview = text.replace(/\s+/g, ' ').trim().substring(0, 300);
                throw new Error(preview || ('HTTP ' + res.status + ' — ไม่ได้รับ JSON จาก API'));
            }
            if (!res.ok && data.status !== 'success') {
                throw new Error(data.message || ('HTTP ' + res.status));
            }
            return data;
        })
        .then(data => {
            if (data.status === 'success') {
                $('#blockCount').text(data.meta.block_count || 0);
                $('#warnCount').text(data.meta.warning_count || 0);
                let metaHtml = '<i class="fas fa-info-circle me-1"></i> กฎที่เลือก: ' + (data.meta.rules_selected || data.meta.rule_count || 0) +
                    ' / ' + (data.meta.rules_total || data.meta.rule_count || 0) +
                    ' | Hard Alert: ' + (data.meta.block_count || 0) + ' | Soft Alert: ' + (data.meta.warning_count || 0) +
                    ' | สแกน order: ' + (data.meta.orders_scanned || 0) + ' รายการ' +
                    ' (HN ' + (data.meta.patients_scanned || 0) + ' ราย)';
                if (data.meta.elapsed_ms) {
                    metaHtml += ' | ใช้เวลา ' + (data.meta.elapsed_ms / 1000).toFixed(1) + ' วินาที';
                }
                if (data.meta.high_volume) {
                    metaHtml += ' <span class="text-warning">(กฎ/ยามาก — แนะนำลดจำนวนกฎที่เปิดใช้หรือใช้ Visit เดียวกัน)</span>';
                }
                if (data.meta.truncated) {
                    metaHtml += ' <span class="text-danger">(เกิน limit — ลดช่วงวันที่)</span>';
                }
                $('#reportMeta').removeClass('d-none').html(metaHtml);
                renderDITable(data.block_groups, '#blockTbody', 'ไม่พบรายการ Block ในช่วงเวลานี้');
                renderDITable(data.warning_groups, '#warnTbody', 'ไม่พบรายการ Warning ในช่วงเวลานี้');
            } else {
                $('#reportMeta').addClass('d-none');
                $('#blockTbody, #warnTbody').html('<tr><td colspan="7" class="text-center text-danger py-4 fw-bold">' + (data.message || 'เกิดข้อผิดพลาด') + '</td></tr>');
            }
        })
        .catch(err => {
            console.error(err);
            const msg = (err && err.message) ? err.message : 'เกิดข้อผิดพลาดในการเชื่อมต่อ';
            $('#reportMeta').removeClass('d-none').html('<i class="fas fa-exclamation-triangle me-1 text-danger"></i> ' + msg);
            $('#blockTbody, #warnTbody').html('<tr><td colspan="7" class="text-center text-danger py-4">' + msg + '</td></tr>');
        });
}

$(document).ready(function() {
    $('.select2-hosxp').select2({ theme: 'bootstrap-5', width: '100%' });
    $('input[name="check_mode"]').on('change', toggleLookbackWrap);
    $('#use_dose1_condition').on('change', function() { toggleDiDoseFields(1); });
    $('#use_dose2_condition').on('change', function() { toggleDiDoseFields(2); });
    toggleLookbackWrap();
    toggleDiDoseFields(1);
    toggleDiDoseFields(2);

    function parseDiRuleData(el) {
        let rule = $(el).data('rule');
        if (typeof rule === 'string') {
            try { rule = JSON.parse(rule); } catch (e) { return null; }
        }
        return rule && rule.id ? rule : null;
    }

    $(document).on('click', '.di-btn-edit', function() {
        const rule = parseDiRuleData(this);
        if (!rule) return;
        setDiEditMode(rule);
    });

    $(document).on('click', '.di-btn-copy', function() {
        const rule = parseDiRuleData(this);
        if (!rule) return;
        setDiCopyMode(rule);
    });

    $(document).on('change', '.di-rule-cb', diUpdateRuleSelectedCount);
    diUpdateRuleSelectedCount();

    flatpickr('#filter_start', { dateFormat: 'Y-m-d' });
    flatpickr('#filter_end', { dateFormat: 'Y-m-d' });
    setDIDateRange(30);
});

function setDIDateRange(days) {
    const end = new Date();
    const start = new Date();
    start.setDate(start.getDate() - days);
    const fmt = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    const fpStart = document.getElementById('filter_start')._flatpickr;
    const fpEnd = document.getElementById('filter_end')._flatpickr;
    if (fpStart) fpStart.setDate(fmt(start), true);
    if (fpEnd) fpEnd.setDate(fmt(end), true);
}
</script>
</body>
</html>
