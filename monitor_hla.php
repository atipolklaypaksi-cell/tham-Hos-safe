<?php
session_start();
if (!isset($_SESSION['user_id']) && !isset($_SESSION['full_name'])) {
    header('Location: login.php');
    exit;
}
include('connect.php');
include('hosxp_tmt_drug_helper.php');
require_once __DIR__ . '/monitor_hla_helper.php';
mysqli_set_charset($con, 'utf8');
ensure_monitor_hla_alert_level_column($con);

$hla_default_start = date('Y-m-d', strtotime('-30 days'));
$hla_default_end = date('Y-m-d');

function hla_ensure_config_row($con, $name, $default) {
    $name_esc = mysqli_real_escape_string($con, $name);
    $q = mysqli_query($con, "SELECT config_value FROM sys_config WHERE config_name = '$name_esc' LIMIT 1");
    if ($q && mysqli_num_rows($q) > 0) {
        return;
    }
    $val_esc = mysqli_real_escape_string($con, $default);
    mysqli_query($con, "INSERT INTO sys_config (config_name, config_value) VALUES ('$name_esc', '$val_esc')");
}

function hla_save_config($con) {
    $fields = [
        'hla_lab_items_code' => trim($_POST['hla_lab_items_code'] ?? '2281'),
        'hla_lookback_months' => (string)max(1, min(120, (int)($_POST['hla_lookback_months'] ?? 24))),
        'hla_ptnote_regex' => trim($_POST['hla_ptnote_regex'] ?? ''),
        'hla_rxnote_regex' => trim($_POST['hla_rxnote_regex'] ?? ''),
        'hla_exclude_hns' => trim($_POST['hla_exclude_hns'] ?? ''),
    ];
    foreach ($fields as $k => $v) {
        $k_esc = mysqli_real_escape_string($con, $k);
        $v_esc = mysqli_real_escape_string($con, $v);
        $q = mysqli_query($con, "SELECT config_name FROM sys_config WHERE config_name = '$k_esc' LIMIT 1");
        if ($q && mysqli_num_rows($q) > 0) {
            mysqli_query($con, "UPDATE sys_config SET config_value = '$v_esc' WHERE config_name = '$k_esc'");
        } else {
            mysqli_query($con, "INSERT INTO sys_config (config_name, config_value) VALUES ('$k_esc', '$v_esc')");
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_hla_drug') {
        $drug = hosxp_parse_drug_option($_POST['drug'] ?? '');
        $icode = mysqli_real_escape_string($con, $drug['icode']);
        $drug_name = mysqli_real_escape_string($con, $drug['name']);
        if ($icode !== '') {
            $chk = mysqli_query($con, "SELECT id FROM monitor_hla_settings WHERE icode = '$icode' LIMIT 1");
            if ($chk && mysqli_num_rows($chk) > 0) {
                $_SESSION['msg'] = 'รายการยานี้มีอยู่แล้ว (icode: ' . $drug['icode'] . ')';
            } else {
                $alert_level = (($_POST['alert_level'] ?? '') === 'soft') ? 'soft' : 'hard';
                $lvl_esc = mysqli_real_escape_string($con, $alert_level);
                mysqli_query($con, "INSERT INTO monitor_hla_settings (icode, drug_name, alert_level, active) VALUES ('$icode', '$drug_name', '$lvl_esc', 1)");
                $_SESSION['msg'] = 'เพิ่มรายการยาเรียบร้อย';
            }
        } else {
            $_SESSION['msg'] = 'กรุณาเลือกรายการยาจาก HOSxP (TMT)';
        }
        header('Location: monitor_hla.php?tab=settings');
        exit;
    }
    if ($action === 'edit_hla_drug') {
        $id = (int)($_POST['rule_id'] ?? 0);
        $alert_level = (($_POST['alert_level'] ?? '') === 'soft') ? 'soft' : 'hard';
        $active = !empty($_POST['active']) ? 1 : 0;
        if ($id > 0) {
            $lvl_esc = mysqli_real_escape_string($con, $alert_level);
            mysqli_query($con, "UPDATE monitor_hla_settings SET alert_level='$lvl_esc', active=$active WHERE id=$id");
            $_SESSION['msg'] = 'บันทึกการแก้ไขเรียบร้อย';
        }
        header('Location: monitor_hla.php?tab=settings');
        exit;
    }
    if ($action === 'delete_hla_drug') {
        $id = (int)($_POST['del_id'] ?? 0);
        if ($id > 0) {
            mysqli_query($con, "DELETE FROM monitor_hla_settings WHERE id = $id");
        }
        header('Location: monitor_hla.php?tab=settings');
        exit;
    }
    if ($action === 'save_hla_config') {
        hla_save_config($con);
        $_SESSION['msg'] = 'บันทึกการตั้งค่าคัดกรองยีนแพ้ยาเรียบร้อย';
        header('Location: monitor_hla.php?tab=settings');
        exit;
    }
}

hla_ensure_config_row($con, 'hla_lab_items_code', '2281');
hla_ensure_config_row($con, 'hla_lookback_months', '24');
hla_ensure_config_row($con, 'hla_ptnote_regex', 'ผ่านการตัดกรองยีนแพ้ยา|HLA-B');
hla_ensure_config_row($con, 'hla_rxnote_regex', 'ยีนแพ้ยา|HLA-B|ตรวจยีน');
hla_ensure_config_row($con, 'hla_exclude_hns', '');

$hla_cfg = [];
$q_hla = mysqli_query($con, "SELECT config_name, config_value FROM sys_config WHERE config_name LIKE 'hla_%'");
if ($q_hla) {
    while ($r = mysqli_fetch_assoc($q_hla)) {
        $hla_cfg[$r['config_name']] = $r['config_value'];
    }
}

$con_hosxp = hosxp_connect($con);
$hosxp_drugs = $con_hosxp ? hosxp_fetch_active_drugs_tmt($con_hosxp) : [];
if ($con_hosxp) {
    mysqli_close($con_hosxp);
}
$has_hosxp_drugs = !empty($hosxp_drugs);
$opt_drugs = hosxp_build_drug_options_html($hosxp_drugs, '- เลือกยาจาก HOSxP (TMT) -');

$monitor_drugs = [];
$q_mon = mysqli_query($con, "SELECT * FROM monitor_hla_settings WHERE active = 1 ORDER BY drug_name ASC");
if ($q_mon) {
    while ($r = mysqli_fetch_assoc($q_mon)) {
        $monitor_drugs[] = $r;
    }
}

$tab = (isset($_GET['tab']) && $_GET['tab'] === 'settings') ? 'settings' : 'monitor';
$flash_msg = $_SESSION['msg'] ?? '';
unset($_SESSION['msg']);
session_write_close();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Monitor คัดกรองยีนแพ้ยา - THAM SAFE</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
    <link href="css/select2.min.css" rel="stylesheet">
    <link href="css/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="css/flatpickr.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', Tahoma, sans-serif; background-color: #f4f7f6; }
        .filter-box { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; }
        .section-hard .table thead { background-color: #dc3545 !important; color: #fff !important; }
        .section-soft .table thead { background-color: #ffc107 !important; color: #212529 !important; }
        .section-alert .table thead { background-color: #9b59b6 !important; color: #fff !important; }
        .hla-range-btn.active { background-color: #9b59b6 !important; border-color: #9b59b6 !important; color: #fff !important; }
        .hla-rule-row.hla-editing-row { background-color: #f3e8ff; }
        #hlaDrugForm.edit-mode { border: 2px solid #9b59b6; border-radius: 8px; padding: 12px; background: #faf8ff; }
    </style>
</head>
<body>
<div class="wrapper">
    <?php include('sidebar.php'); ?>
    <div id="content" class="content">
        <div class="container-fluid py-4">
            <h4 class="fw-bold mb-4 text-dark">
                <i class="fas fa-dna text-purple me-2" style="color:#9b59b6;"></i>
                Monitor คัดกรองยีนแพ้ยา (HLA-B / Pharmacogenomics)
            </h4>

            <?php if ($flash_msg): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo htmlspecialchars($flash_msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <ul class="nav nav-tabs fw-bold" role="tablist">
                <li class="nav-item">
                    <button class="nav-link <?php echo $tab === 'monitor' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#monitor" type="button">
                        <i class="fas fa-desktop me-1"></i> รายการเฝ้าระวัง
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link <?php echo $tab === 'settings' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#settings" type="button">
                        <i class="fas fa-cogs me-1"></i> ตั้งค่า
                    </button>
                </li>
            </ul>

            <div class="tab-content bg-white border border-top-0 p-4 rounded-bottom shadow-sm">
                <div class="tab-pane fade <?php echo $tab === 'monitor' ? 'show active' : ''; ?>" id="monitor">
                    <div class="filter-box p-3 mb-3">
                        <h6 class="fw-bold text-secondary mb-3"><i class="fas fa-filter me-2"></i>ตัวกรองรายงาน</h6>
                        <div class="row g-2 align-items-end">
                            <div class="col-md-2">
                                <label class="form-label small fw-bold mb-1">วันที่สั่งยา ตั้งแต่</label>
                                <input type="text" id="filter_start" class="form-control form-control-sm bg-white"
                                    value="<?php echo htmlspecialchars($hla_default_start); ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-bold mb-1">ถึงวันที่</label>
                                <input type="text" id="filter_end" class="form-control form-control-sm bg-white"
                                    value="<?php echo htmlspecialchars($hla_default_end); ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-bold mb-1">ย้อนหลังตรวจคัดกรอง (เดือน)</label>
                                <input type="number" id="lookback_months" class="form-control form-control-sm" min="1" max="120"
                                    value="<?php echo htmlspecialchars($hla_cfg['hla_lookback_months'] ?? '24'); ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-bold mb-1">เฉพาะสั่งยาล่าสุด (นาที)</label>
                                <input type="number" id="recent_minutes" class="form-control form-control-sm" min="0" max="1440" value="0" placeholder="0 = ทั้งช่วง">
                                <div class="form-text" style="font-size:0.7rem;">เช่น 8 = แบบ Grafana real-time</div>
                            </div>
                            <div class="col-md-2">
                                <button type="button" class="btn btn-sm fw-bold text-white w-100 shadow-sm" style="background:#9b59b6;" id="btnFetchHLA" onclick="fetchHLAData()">
                                    <i class="fas fa-sync-alt me-1"></i> ประมวลผล
                                </button>
                            </div>
                        </div>
                        <div class="row g-2 mt-2 align-items-center">
                            <div class="col-auto">
                                <label class="form-label small fw-bold mb-0 text-secondary">ช่วงรายงานเร็ว:</label>
                            </div>
                            <div class="col">
                                <div class="btn-group btn-group-sm flex-wrap" role="group" id="hlaRangeTabs">
                                    <button type="button" class="btn btn-outline-secondary fw-bold hla-range-btn" data-days="7" onclick="setHLADateRange(7)">7 วัน</button>
                                    <button type="button" class="btn btn-outline-secondary fw-bold hla-range-btn active" data-days="30" onclick="setHLADateRange(30)">1 เดือน</button>
                                    <button type="button" class="btn btn-outline-secondary fw-bold hla-range-btn" data-days="90" onclick="setHLADateRange(90)">3 เดือน</button>
                                    <button type="button" class="btn btn-outline-secondary fw-bold hla-range-btn" data-days="180" onclick="setHLADateRange(180)">6 เดือน</button>
                                    <button type="button" class="btn btn-outline-secondary fw-bold hla-range-btn" data-days="365" onclick="setHLADateRange(365)">12 เดือน</button>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($monitor_drugs)): ?>
                        <div class="mt-3 border-top pt-3">
                            <div class="small fw-bold text-secondary mb-2">
                                เลือกยาที่ต้องการรายงาน
                                <button type="button" class="btn btn-link btn-sm p-0 ms-2" onclick="hlaSelectAllDrugs(true)">เลือกทั้งหมด</button>
                                <button type="button" class="btn btn-link btn-sm p-0 ms-1" onclick="hlaSelectAllDrugs(false)">ยกเลิก</button>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($monitor_drugs as $md): ?>
                                <label class="badge bg-light text-dark border p-2">
                                    <input type="checkbox" class="form-check-input me-1 hla-drug-cb" value="<?php echo htmlspecialchars($md['icode']); ?>" checked>
                                    <?php echo htmlspecialchars($md['drug_name']); ?>
                                    <span class="text-muted">(<?php echo htmlspecialchars($md['icode']); ?>)</span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-warning small mt-3 mb-0">
                            <i class="fas fa-exclamation-triangle me-1"></i> ยังไม่มีรายการยา — ไปที่แท็บ <b>ตั้งค่า</b> เพื่อเพิ่มยาควบคุมโรคข้อ (เช่น icode 1000009, 1000038, 1590040)
                        </div>
                        <?php endif; ?>

                        <div id="reportMeta" class="alert alert-info py-2 px-3 mt-3 mb-0 small fw-bold d-none"></div>
                    </div>

                    <div class="section-hard mb-4">
                        <h6 class="fw-bold text-danger mb-2">
                            <i class="fas fa-exclamation-circle me-2"></i>Hard Alert
                            <span id="hardCount" class="badge bg-danger">0</span>
                        </h6>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle border mb-0">
                                <thead class="text-center">
                                    <tr>
                                        <th>HN</th>
                                        <th>OPD/IPD</th>
                                        <th class="text-start">ชื่อ-สกุล</th>
                                        <th>วัน-เวลาสั่งยา</th>
                                        <th class="text-start">รายการยา</th>
                                        <th class="text-start">วิธีใช้</th>
                                        <th>จำนวน</th>
                                        <th>หอผู้ป่วย / เตียง</th>
                                        <th class="text-start">หมายเหตุ</th>
                                    </tr>
                                </thead>
                                <tbody id="hardTbody">
                                    <tr><td colspan="9" class="text-center py-4 text-muted">กดปุ่มประมวลผลเพื่อดึงข้อมูล</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="section-soft">
                        <h6 class="fw-bold text-warning mb-2">
                            <i class="fas fa-exclamation-triangle me-2"></i>Soft Alert
                            <span id="softCount" class="badge bg-warning text-dark">0</span>
                        </h6>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle border mb-0">
                                <thead class="text-center">
                                    <tr>
                                        <th>HN</th>
                                        <th>OPD/IPD</th>
                                        <th class="text-start">ชื่อ-สกุล</th>
                                        <th>วัน-เวลาสั่งยา</th>
                                        <th class="text-start">รายการยา</th>
                                        <th class="text-start">วิธีใช้</th>
                                        <th>จำนวน</th>
                                        <th>หอผู้ป่วย / เตียง</th>
                                        <th class="text-start">หมายเหตุ</th>
                                    </tr>
                                </thead>
                                <tbody id="softTbody">
                                    <tr><td colspan="9" class="text-center py-4 text-muted">กดปุ่มประมวลผลเพื่อดึงข้อมูล</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade <?php echo $tab === 'settings' ? 'show active' : ''; ?>" id="settings">
                    <div class="row">
                        <div class="col-lg-5 border-end">
                            <h6 class="fw-bold text-primary mb-3"><i class="fas fa-pills me-2"></i>กลุ่มยาที่ต้องคัดกรองก่อนใช้</h6>
                            <?php if (!$has_hosxp_drugs): ?>
                            <div class="alert alert-warning small">
                                <i class="fas fa-exclamation-triangle me-1"></i>ไม่สามารถดึงรายการยาจาก HOSxP ได้ — ตรวจสอบที่ <a href="hosxp_setting.php" class="fw-bold">ตั้งค่า HOSxP</a>
                            </div>
                            <?php endif; ?>
                            <form method="POST" class="mb-4" id="hlaDrugForm">
                                <input type="hidden" name="action" id="hla_action" value="add_hla_drug">
                                <input type="hidden" name="rule_id" id="hla_rule_id" value="">
                                <h6 class="fw-bold small text-primary mb-2" id="hlaFormTitle"><i class="fas fa-plus-circle me-1"></i> เพิ่มรายการยา</h6>
                                <label class="form-label fw-bold small text-muted mb-1">เลือกรายการยา — จาก HOSxP (TMT)</label>
                                <div class="input-group input-group-sm mb-2" id="hla_drug_add_row">
                                    <select name="drug" id="hla_drug" class="form-select select2-hosxp" <?php echo $has_hosxp_drugs ? 'required' : 'disabled'; ?>><?php echo $opt_drugs; ?></select>
                                </div>
                                <div class="mb-2 d-none" id="hla_drug_edit_info">
                                    <div class="border rounded bg-light p-2 small">
                                        <span class="text-muted">กำลังแก้ไข:</span>
                                        <b id="hla_edit_drug_name"></b>
                                        <span class="text-muted">(icode: <span id="hla_edit_icode"></span>)</span>
                                    </div>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label fw-bold small">ระดับการเตือน</label>
                                    <select name="alert_level" id="hla_alert_level" class="form-select form-select-sm fw-bold">
                                        <option value="hard">Hard Alert</option>
                                        <option value="soft">Soft Alert</option>
                                    </select>
                                    <div class="form-text">Hard = ต้องคัดกรองก่อนใช้ยา | Soft = แนะนำคัดกรอง</div>
                                </div>
                                <div class="form-check mb-2 d-none" id="hla_active_wrap">
                                    <input class="form-check-input" type="checkbox" name="active" id="hla_active" value="1" checked>
                                    <label class="form-check-label small" for="hla_active">เปิดใช้งานรายการนี้</label>
                                </div>
                                <div class="d-flex gap-2 mb-2">
                                    <button class="btn btn-success fw-bold btn-sm" type="submit" id="hlaBtnSubmit" <?php echo $has_hosxp_drugs ? '' : 'disabled'; ?>><i class="fas fa-plus"></i> เพิ่ม</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="hlaBtnCancel" onclick="resetHlaDrugForm()">ยกเลิกแก้ไข</button>
                                </div>
                                <p class="small text-muted mb-0 mt-2" id="hlaFormHint">แสดงชื่อ TMT พร้อม icode — ตัวอย่าง Grafana: 1000009, 1000038, 1590040</p>
                            </form>
                            <table class="table table-sm table-bordered">
                                <thead class="table-light"><tr><th>icode</th><th>ชื่อยา</th><th>Alert</th><th>เปิด</th><th>จัดการ</th></tr></thead>
                                <tbody>
                                <?php
                                $q_all = mysqli_query($con, "SELECT * FROM monitor_hla_settings ORDER BY drug_name ASC");
                                if ($q_all && mysqli_num_rows($q_all) > 0):
                                    while ($row = mysqli_fetch_assoc($q_all)):
                                        $rule_json = htmlspecialchars(json_encode([
                                            'id' => (int)$row['id'],
                                            'icode' => $row['icode'],
                                            'drug_name' => $row['drug_name'],
                                            'alert_level' => hla_alert_level_from_row($row),
                                            'active' => (int)!empty($row['active']),
                                        ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                                ?>
                                    <tr class="hla-rule-row" data-rule-id="<?php echo (int)$row['id']; ?>">
                                        <td class="fw-bold"><?php echo htmlspecialchars($row['icode']); ?></td>
                                        <td><?php echo htmlspecialchars($row['drug_name']); ?></td>
                                        <td class="text-center"><?php echo hla_alert_level_badge_html(hla_alert_level_from_row($row)); ?></td>
                                        <td class="text-center"><?php echo !empty($row['active']) ? '<i class="fas fa-check text-success"></i>' : '<i class="fas fa-times text-muted"></i>'; ?></td>
                                        <td class="text-end text-nowrap">
                                            <button type="button" class="btn btn-outline-primary btn-sm hla-btn-edit" title="แก้ไข" data-rule="<?php echo $rule_json; ?>"><i class="fas fa-edit"></i></button>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('ลบรายการนี้?');">
                                                <input type="hidden" name="action" value="delete_hla_drug">
                                                <input type="hidden" name="del_id" value="<?php echo (int)$row['id']; ?>">
                                                <button class="btn btn-outline-danger btn-sm"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endwhile; else: ?>
                                    <tr><td colspan="5" class="text-center text-muted">ยังไม่มีรายการ</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="col-lg-7 ps-lg-4">
                            <h6 class="fw-bold text-secondary mb-3"><i class="fas fa-sliders-h me-2"></i>เงื่อนไขคัดกรอง (ตาม Grafana)</h6>
                            <form method="POST">
                                <input type="hidden" name="action" value="save_hla_config">
                                <div class="mb-3">
                                    <label class="form-label fw-bold small">รหัส Lab คัดกรอง HLA-B</label>
                                    <input type="text" name="hla_lab_items_code" class="form-control form-control-sm"
                                        value="<?php echo htmlspecialchars($hla_cfg['hla_lab_items_code'] ?? '2281'); ?>" placeholder="เช่น 2281 หรือ 2281,2282">
                                    <div class="form-text">ถ้ารพ.มีหลาย code คั่นด้วย comma</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold small">ย้อนหลังตรวจคัดกรอง (เดือน)</label>
                                    <input type="number" name="hla_lookback_months" class="form-control form-control-sm" min="1" max="120"
                                        value="<?php echo htmlspecialchars($hla_cfg['hla_lookback_months'] ?? '24'); ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold small">Regex บันทึก ptnote (ถือว่าคัดกรองแล้ว)</label>
                                    <input type="text" name="hla_ptnote_regex" class="form-control form-control-sm"
                                        value="<?php echo htmlspecialchars($hla_cfg['hla_ptnote_regex'] ?? 'ผ่านการตัดกรองยีนแพ้ยา|HLA-B'); ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold small">Regex บันทึก patient_rx_note (ถือว่าคัดกรองแล้ว)</label>
                                    <input type="text" name="hla_rxnote_regex" class="form-control form-control-sm"
                                        value="<?php echo htmlspecialchars($hla_cfg['hla_rxnote_regex'] ?? 'ยีนแพ้ยา|HLA-B|ตรวจยีน'); ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold small">HN ที่ยกเว้น (คั่นด้วย comma)</label>
                                    <input type="text" name="hla_exclude_hns" class="form-control form-control-sm"
                                        value="<?php echo htmlspecialchars($hla_cfg['hla_exclude_hns'] ?? ''); ?>" placeholder="เช่น 000312974">
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm fw-bold"><i class="fas fa-save me-1"></i> บันทึกการตั้งค่า</button>
                            </form>

                            <div class="alert alert-light border small mt-4 mb-0">
                                <b>เงื่อนไขรายงาน (อัตโนมัติ):</b><br>
                                - ผู้ป่วยที่สั่งยากลุ่มนี้ครั้งแรกในรอบ <?php echo htmlspecialchars($hla_cfg['hla_lookback_months'] ?? '24'); ?> เดือน<br>
                                - ยังไม่มี Lab คัดกรองก่อนวัน visit<br>
                                - ไม่มีข้อความคัดกรองใน ptnote / patient_rx_note<br>
                                - ไม่เคยได้รับยารายการเดียวกันมาก่อนในรอบ lookback
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
const HLA_HAS_DRUGS = <?php echo !empty($monitor_drugs) ? 'true' : 'false'; ?>;

$(function() {
    if (typeof flatpickr !== 'undefined') {
        flatpickr('#filter_start', { dateFormat: 'Y-m-d', defaultDate: '<?php echo $hla_default_start; ?>' });
        flatpickr('#filter_end', { dateFormat: 'Y-m-d', defaultDate: '<?php echo $hla_default_end; ?>' });
    }
    if ($('.select2-hosxp').length) {
        $('.select2-hosxp').select2({ theme: 'bootstrap-5', width: '100%' });
    }
    $(document).on('click', '.hla-btn-edit', function() {
        let rule = $(this).data('rule');
        if (typeof rule === 'string') {
            try { rule = JSON.parse(rule); } catch (e) { return; }
        }
        if (!rule || !rule.id) return;
        setHlaEditMode(rule);
    });
    if (HLA_HAS_DRUGS && $('#monitor').hasClass('show')) {
        setHLADateRange(30, true);
    }
});

function hlaSelectAllDrugs(checked) {
    $('.hla-drug-cb').prop('checked', checked);
}

function getSelectedHlaIcodes() {
    return $('.hla-drug-cb:checked').map(function() { return this.value; }).get();
}

function hlaEsc(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function hlaFmtDate(d) {
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

function setHLADateRange(days, autoFetch) {
    const end = new Date();
    const start = new Date();
    start.setDate(start.getDate() - days);
    const startStr = hlaFmtDate(start);
    const endStr = hlaFmtDate(end);
    const fpStart = document.getElementById('filter_start')._flatpickr;
    const fpEnd = document.getElementById('filter_end')._flatpickr;
    if (fpStart) {
        fpStart.setDate(startStr, true);
    } else {
        $('#filter_start').val(startStr);
    }
    if (fpEnd) {
        fpEnd.setDate(endStr, true);
    } else {
        $('#filter_end').val(endStr);
    }
    $('.hla-range-btn').removeClass('active');
    $('.hla-range-btn[data-days="' + days + '"]').addClass('active');
    if (autoFetch !== false && HLA_HAS_DRUGS) {
        fetchHLAData();
    }
}

function hlaWardBedDisplay(item) {
    if (item.ward_display) {
        return item.ward_display;
    }
    if (item.type_patient === 'OPD') {
        return 'ผู้ป่วยนอก';
    }
    const wardBed = [item.ward_name, item.bedno].filter(Boolean).join(' / ');
    return wardBed || '-';
}

function hlaRenderAlertTable(alerts, tbodyId, emptyMsg) {
    if (!alerts || alerts.length === 0) {
        $(tbodyId).html('<tr><td colspan="9" class="text-center py-4 text-success fw-bold"><i class="fas fa-check-circle me-2"></i>' + hlaEsc(emptyMsg) + '</td></tr>');
        return;
    }
    let html = '';
    alerts.forEach(item => {
        const when = item.order_time
            ? item.order_date + ' <span class="text-dark">' + hlaEsc(item.order_time) + '</span>'
            : hlaEsc(item.order_date);
        const wardBed = hlaWardBedDisplay(item);
        const wardClass = item.type_patient === 'OPD' ? 'text-info fw-bold' : '';
        const lvlBadge = item.alert_level === 'hard'
            ? '<span class="badge bg-danger me-1">Hard</span>'
            : '<span class="badge bg-warning text-dark me-1">Soft</span>';
        html += '<tr>'
            + '<td class="text-center fw-bold">' + hlaEsc(item.hn) + '</td>'
            + '<td class="text-center"><span class="badge ' + (item.type_patient === 'IPD' ? 'bg-secondary' : 'bg-info text-dark') + '">' + hlaEsc(item.type_patient) + '</span></td>'
            + '<td>' + hlaEsc(item.ptname) + '</td>'
            + '<td class="text-center small">' + when + '</td>'
            + '<td class="text-start"><b>' + hlaEsc(item.drug_name) + '</b><br><small class="text-muted">icode: ' + hlaEsc(item.icode) + '</small></td>'
            + '<td class="text-start small">' + hlaEsc(item.method || '-') + '</td>'
            + '<td class="text-center">' + hlaEsc(item.qty) + '</td>'
            + '<td class="text-center small ' + wardClass + '">' + hlaEsc(wardBed) + '</td>'
            + '<td class="text-start small text-danger fw-bold">' + lvlBadge + hlaEsc(item.alert_message) + '</td>'
            + '</tr>';
    });
    $(tbodyId).html(html);
}

function fetchHLAData() {
    const icodes = getSelectedHlaIcodes();
    if (icodes.length === 0) {
        alert('กรุณาเลือกอย่างน้อย 1 รายการยา');
        return;
    }
    let startDate = $('#filter_start').val();
    let endDate = $('#filter_end').val();
    if (!startDate) startDate = '<?php echo $hla_default_start; ?>';
    if (!endDate) endDate = '<?php echo $hla_default_end; ?>';

    $('#btnFetchHLA').prop('disabled', true);
    const loading = '<tr><td colspan="9" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i>กำลังประมวลผล... (อาจใช้เวลา 10–30 วินาที)</td></tr>';
    $('#hardTbody, #softTbody').html(loading);
    const qs = new URLSearchParams({
        start_date: startDate,
        end_date: endDate,
        lookback_months: $('#lookback_months').val() || '24',
        recent_minutes: $('#recent_minutes').val() || '0',
        icodes: icodes.join(',')
    });
    fetch('fetch_hla_monitor_api.php?' + qs.toString(), { credentials: 'same-origin' })
    .then(async r => {
        const text = await r.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            throw new Error(text ? text.substring(0, 300) : ('HTTP ' + r.status));
        }
    })
    .then(data => {
        $('#btnFetchHLA').prop('disabled', false);
        if (data.status !== 'success') {
            $('#hardTbody, #softTbody').html('<tr><td colspan="9" class="text-center text-danger py-4">' + hlaEsc(data.message || 'เกิดข้อผิดพลาด') + '</td></tr>');
            $('#reportMeta').addClass('d-none');
            return;
        }
        const hardAlerts = data.hard_alerts || [];
        const softAlerts = data.soft_alerts || [];
        const meta = data.meta || {};
        $('#hardCount').text(meta.hard_count || hardAlerts.length);
        $('#softCount').text(meta.soft_count || softAlerts.length);
        let metaHtml = '<i class="fas fa-info-circle me-1"></i> Hard: ' + (meta.hard_count || 0)
            + ' | Soft: ' + (meta.soft_count || 0)
            + ' | ยาที่ตรวจ: ' + (meta.drugs_monitored || 0)
            + ' | lookback: ' + (meta.lookback_months || '-') + ' เดือน';
        if (meta.recent_minutes > 0) metaHtml += ' | ล่าสุด ' + meta.recent_minutes + ' นาที';
        if (meta.elapsed_ms) metaHtml += ' | ' + (meta.elapsed_ms / 1000).toFixed(1) + ' วินาที';
        $('#reportMeta').removeClass('d-none').html(metaHtml);

        hlaRenderAlertTable(hardAlerts, '#hardTbody', 'ไม่พบ Hard Alert');
        hlaRenderAlertTable(softAlerts, '#softTbody', 'ไม่พบ Soft Alert');
    })
    .catch(err => {
        $('#btnFetchHLA').prop('disabled', false);
        const msg = err && err.message ? err.message : 'เกิดข้อผิดพลาดในการเชื่อมต่อ API';
        $('#hardTbody, #softTbody').html('<tr><td colspan="9" class="text-center text-danger py-4">' + hlaEsc(msg) + '</td></tr>');
        $('#reportMeta').addClass('d-none');
    });
}

function resetHlaDrugForm() {
    $('#hlaDrugForm').removeClass('edit-mode');
    $('#hlaFormTitle').html('<i class="fas fa-plus-circle me-1"></i> เพิ่มรายการยา');
    $('#hla_action').val('add_hla_drug');
    $('#hla_rule_id').val('');
    $('#hla_drug_add_row').removeClass('d-none');
    $('#hla_drug_edit_info').addClass('d-none');
    $('#hla_active_wrap').addClass('d-none');
    $('#hlaBtnCancel').addClass('d-none');
    $('#hlaBtnSubmit').html('<i class="fas fa-plus"></i> เพิ่ม').removeClass('btn-primary').addClass('btn-success');
    $('#hla_drug').prop('required', true);
    $('#hla_drug').val('').trigger('change');
    $('#hla_alert_level').val('hard');
    $('#hla_active').prop('checked', true);
    $('#hlaFormHint').removeClass('d-none');
    $('.hla-rule-row').removeClass('hla-editing-row');
}

function setHlaEditMode(rule) {
    $('.hla-rule-row').removeClass('hla-editing-row');
    $('tr.hla-rule-row[data-rule-id="' + rule.id + '"]').addClass('hla-editing-row');
    $('#hlaDrugForm').addClass('edit-mode');
    $('#hlaFormTitle').html('<i class="fas fa-edit me-1"></i> แก้ไข: ' + hlaEsc(rule.drug_name || '') + ' (icode: ' + hlaEsc(rule.icode || '') + ')');
    $('#hla_action').val('edit_hla_drug');
    $('#hla_rule_id').val(rule.id);
    $('#hla_drug_add_row').addClass('d-none');
    $('#hla_drug_edit_info').removeClass('d-none');
    $('#hla_edit_drug_name').text(rule.drug_name || '');
    $('#hla_edit_icode').text(rule.icode || '');
    $('#hla_active_wrap').removeClass('d-none');
    $('#hlaBtnCancel').removeClass('d-none');
    $('#hlaBtnSubmit').html('<i class="fas fa-save"></i> บันทึก').removeClass('btn-success').addClass('btn-primary');
    $('#hla_drug').prop('required', false);
    $('#hla_alert_level').val(rule.alert_level === 'soft' ? 'soft' : 'hard');
    $('#hla_active').prop('checked', !!rule.active);
    $('#hlaFormHint').addClass('d-none');
    document.getElementById('hlaDrugForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
}
</script>
</body>
</html>
