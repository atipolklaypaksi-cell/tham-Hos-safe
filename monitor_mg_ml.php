<?php
session_start();
if (!isset($_SESSION['user_id']) && !isset($_SESSION['full_name'])) {
    header('Location: login.php');
    exit;
}
include('connect.php');
include('hosxp_tmt_drug_helper.php');
include('monitor_mg_ml_helper.php');
mysqli_set_charset($con, 'utf8');

mmgml_ensure_table($con);

$default_start = date('Y-m-d', strtotime('-3 days'));
$default_end = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_mmgml' || $action === 'edit_mmgml') {
        $drug = hosxp_parse_drug_option($_POST['drug'] ?? '');
        $icode = mmgml_escape($con, $drug['icode']);
        $drug_name = mmgml_escape($con, $drug['name']);
        $strength_text = mmgml_escape($con, $_POST['strength_text'] ?? '');
        $mg_per_ml = (float)($_POST['mg_per_ml'] ?? 0);
        $tolerance_pct = (float)($_POST['tolerance_pct'] ?? 5);
        $tolerance_ml = (float)($_POST['tolerance_ml'] ?? 0.02);
        $auto_from_tmt = isset($_POST['auto_from_tmt']) ? 1 : 0;
        $alert_level = ($_POST['alert_level'] ?? '') === 'soft' ? 'soft' : 'hard';
        $alert_message = mmgml_escape($con, $_POST['alert_message'] ?? '');
        $detail_text = mmgml_escape($con, $_POST['detail_text'] ?? '');
        $active = isset($_POST['active']) ? 1 : 0;
        $lvl_esc = mmgml_escape($con, $alert_level);

        if ($icode === '') {
            $_SESSION['mmgml_error'] = 'กรุณาเลือกยาจาก HOSxP';
            header('Location: monitor_mg_ml.php?tab=settings');
            exit;
        }

        $valid = mmgml_rule_is_valid([
            'mg_per_ml' => $mg_per_ml,
            'tolerance_pct' => $tolerance_pct,
            'tolerance_ml' => $tolerance_ml,
        ]);
        if (!$valid['ok']) {
            $_SESSION['mmgml_error'] = $valid['error'];
            header('Location: monitor_mg_ml.php?tab=settings');
            exit;
        }

        if ($action === 'add_mmgml') {
            $chk = mysqli_query($con, "SELECT id FROM monitor_mg_ml_settings WHERE icode='$icode' LIMIT 1");
            if ($chk && mysqli_num_rows($chk) > 0) {
                $_SESSION['mmgml_error'] = 'มีกฎสำหรับ icode นี้แล้ว — ใช้แก้ไขแทน';
            } else {
                $sql = "INSERT INTO monitor_mg_ml_settings
                    (icode, drug_name, strength_text, mg_per_ml, tolerance_pct, tolerance_ml, auto_from_tmt, alert_level, alert_message, detail_text, active)
                    VALUES ('$icode', '$drug_name', '$strength_text', $mg_per_ml, $tolerance_pct, $tolerance_ml, $auto_from_tmt, '$lvl_esc', '$alert_message', '$detail_text', $active)";
                if (mysqli_query($con, $sql)) {
                    $_SESSION['mmgml_msg'] = 'เพิ่มกฎ Monitor mg → mL เรียบร้อย';
                } else {
                    $_SESSION['mmgml_error'] = 'บันทึกไม่สำเร็จ: ' . mysqli_error($con);
                }
            }
        } else {
            $id = (int)($_POST['rule_id'] ?? 0);
            if ($id <= 0) {
                $_SESSION['mmgml_error'] = 'ไม่พบรายการที่จะแก้ไข';
            } else {
                $sql = "UPDATE monitor_mg_ml_settings SET
                    icode='$icode', drug_name='$drug_name', strength_text='$strength_text',
                    mg_per_ml=$mg_per_ml, tolerance_pct=$tolerance_pct, tolerance_ml=$tolerance_ml,
                    auto_from_tmt=$auto_from_tmt, alert_level='$lvl_esc',
                    alert_message='$alert_message', detail_text='$detail_text', active=$active
                    WHERE id=$id";
                if (mysqli_query($con, $sql)) {
                    $_SESSION['mmgml_msg'] = 'บันทึกการแก้ไขเรียบร้อย';
                } else {
                    $_SESSION['mmgml_error'] = 'บันทึกไม่สำเร็จ: ' . mysqli_error($con);
                }
            }
        }
        header('Location: monitor_mg_ml.php?tab=settings');
        exit;
    }

    if ($action === 'delete_mmgml') {
        $id = (int)($_POST['del_id'] ?? 0);
        if ($id > 0) {
            mysqli_query($con, "DELETE FROM monitor_mg_ml_settings WHERE id=$id");
            $_SESSION['mmgml_msg'] = 'ลบกฎเรียบร้อย';
        }
        header('Location: monitor_mg_ml.php?tab=settings');
        exit;
    }

    if ($action === 'scan_tmt') {
        $con_hosxp = hosxp_connect($con);
        $result = mmgml_scan_from_tmt($con_hosxp, $con);
        if ($con_hosxp) {
            mysqli_close($con_hosxp);
        }
        if (($result['message'] ?? '') === 'ok') {
            $_SESSION['mmgml_msg'] = 'สแกน TMT สำเร็จ — เพิ่ม ' . (int)$result['inserted']
                . ' รายการ, อัปเดต ' . (int)$result['updated']
                . ' รายการ (ข้าม ' . (int)$result['skipped'] . ' จาก ' . (int)$result['scanned'] . ' รายการ)';
        } else {
            $_SESSION['mmgml_error'] = 'สแกนไม่สำเร็จ: ' . ($result['message'] ?? 'unknown');
        }
        header('Location: monitor_mg_ml.php?tab=settings');
        exit;
    }
}

$con_hosxp = hosxp_connect($con);
$hosxp_drugs = $con_hosxp ? hosxp_fetch_active_drugs_tmt($con_hosxp) : [];
if ($con_hosxp) {
    mysqli_close($con_hosxp);
}
$opt_drugs = hosxp_build_drug_options_html($hosxp_drugs, '- เลือกยาจาก HOSxP (TMT) -');

$all_rules = [];
$monitor_rules = [];
$q_rules = mysqli_query($con, "SELECT * FROM monitor_mg_ml_settings ORDER BY drug_name ASC, id ASC");
if ($q_rules) {
    while ($r = mysqli_fetch_assoc($q_rules)) {
        $all_rules[] = $r;
        if (!empty($r['active'])) {
            $monitor_rules[] = $r;
        }
    }
}

$tab = (isset($_GET['tab']) && $_GET['tab'] === 'settings') ? 'settings' : 'monitor';
$flash_msg = $_SESSION['mmgml_msg'] ?? '';
$flash_err = $_SESSION['mmgml_error'] ?? '';
unset($_SESSION['mmgml_msg'], $_SESSION['mmgml_error']);
session_write_close();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Monitor mg. to mL. - THAM SAFE</title>
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
        .section-soft .table thead { background-color: #fd7e14 !important; color: #fff !important; }
        .section-pass .table thead { background-color: #198754 !important; color: #fff !important; }
        .mmgml-range-btn.active { background-color: #0d6efd !important; border-color: #0d6efd !important; color: #fff !important; }
        .ml-mismatch { background: #fee2e2; border-left: 3px solid #dc3545; padding: 4px 8px; border-radius: 4px; font-weight: 600; }
        .ml-pass { background: #dcfce7; border-left: 3px solid #198754; padding: 4px 8px; border-radius: 4px; font-weight: 600; }
        #mmgmlFormCard.edit-mode .card-header { background-color: #0a58ca !important; }
        tr.mmgml-editing-row { background-color: #e7f1ff !important; }
    </style>
</head>
<body>
<div class="wrapper">
    <?php include('sidebar.php'); ?>
    <div id="content" class="content">
        <div class="container-fluid py-4">
            <h4 class="fw-bold mb-2 text-dark">
                <i class="fas fa-syringe me-2" style="color:#0d6efd;"></i>
                Monitor mg. to mL.
            </h4>
            <p class="text-muted small mb-4">
                ตรวจการแปลงขนาดยา <b>mg → mL</b> ในวิธีใช้ (OPD/IPD) — ตัวอย่าง
                <b>CLOXACILLIN INJ. 1 GM</b> ละลาย 4 mL → 250 mg/mL:
                <code>IV 70 MG (0.28 ml)</code> ต้องคำนวณได้ 70 ÷ 250 = 0.28 mL
                <br>กฎจาก TMT ดึงอัตโนมัติเมื่อ strength เป็นรูปแบบ <i>mg/mL</i> หรือ <i>mcg/mL</i>
                — ยาที่ต้องละลายเอง (เช่น Cloxacillin 1 g) ให้ตั้ง mg/mL ด้วยตนเอง
            </p>

            <?php if ($flash_msg): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo htmlspecialchars($flash_msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            <?php if ($flash_err): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?php echo htmlspecialchars($flash_err); ?>
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
                        <i class="fas fa-cogs me-1"></i> ตั้งค่ากฎ mg → mL
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
                                <input type="text" id="filter_start" class="form-control form-control-sm bg-white" value="<?php echo htmlspecialchars($default_start); ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-bold mb-1">ถึงวันที่</label>
                                <input type="text" id="filter_end" class="form-control form-control-sm bg-white" value="<?php echo htmlspecialchars($default_end); ?>">
                            </div>
                            <div class="col-md-2">
                                <button type="button" class="btn btn-sm fw-bold text-white w-100 shadow-sm" style="background:#0d6efd;" id="btnFetchMMGML" onclick="fetchMMGMLData()">
                                    <i class="fas fa-sync-alt me-1"></i> ประมวลผล
                                </button>
                            </div>
                        </div>
                        <div class="row g-2 mt-2 align-items-center">
                            <div class="col-auto"><label class="form-label small fw-bold mb-0 text-secondary">ช่วงเร็ว:</label></div>
                            <div class="col">
                                <div class="btn-group btn-group-sm flex-wrap">
                                    <button type="button" class="btn btn-outline-secondary fw-bold mmgml-range-btn" data-days="1" onclick="setMMGMLDateRange(1)">วันนี้</button>
                                    <button type="button" class="btn btn-outline-secondary fw-bold mmgml-range-btn active" data-days="3" onclick="setMMGMLDateRange(3)">3 วัน</button>
                                    <button type="button" class="btn btn-outline-secondary fw-bold mmgml-range-btn" data-days="7" onclick="setMMGMLDateRange(7)">7 วัน</button>
                                    <button type="button" class="btn btn-outline-secondary fw-bold mmgml-range-btn" data-days="30" onclick="setMMGMLDateRange(30)">30 วัน</button>
                                </div>
                            </div>
                        </div>
                        <div id="reportMeta" class="alert alert-info py-2 px-3 mt-2 mb-0 small fw-bold d-none"></div>
                    </div>

                    <div class="border rounded p-3 mb-3" id="mmgmlRuleFilterBox">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                            <label class="form-label fw-bold small mb-0">
                                <i class="fas fa-filter me-1 text-primary"></i> เลือกยาที่ต้องการรายงาน
                            </label>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-primary fw-bold" onclick="mmgmlSelectAllRules(true)">เลือกทั้งหมด</button>
                                <button type="button" class="btn btn-outline-secondary fw-bold" onclick="mmgmlSelectAllRules(false)">ยกเลิกทั้งหมด</button>
                            </div>
                        </div>
                        <?php if (empty($monitor_rules)): ?>
                        <div class="small text-muted">ยังไม่มีกฎที่เปิดใช้งาน — ไปที่แท็บ <b>ตั้งค่ากฎ</b> หรือกด <b>สแกนจาก TMT</b></div>
                        <?php else: ?>
                        <div class="row g-2">
                            <?php foreach ($monitor_rules as $mr):
                                $lvl = mmgml_alert_level($mr);
                                $badge = $lvl === 'hard'
                                    ? '<span class="badge bg-danger ms-1">Hard</span>'
                                    : '<span class="badge bg-warning text-dark ms-1">Soft</span>';
                                $conc = (float)($mr['mg_per_ml'] ?? 0) > 0
                                    ? mmgml_format_mg($mr['mg_per_ml']) . ' mg/mL'
                                    : '';
                            ?>
                            <div class="col-md-6 col-xl-4">
                                <div class="form-check border rounded px-2 py-1 bg-white h-100">
                                    <input class="form-check-input mmgml-rule-cb" type="checkbox" value="<?php echo (int)$mr['id']; ?>"
                                           id="mmgml_rule_cb_<?php echo (int)$mr['id']; ?>" checked>
                                    <label class="form-check-label small" for="mmgml_rule_cb_<?php echo (int)$mr['id']; ?>">
                                        <span class="fw-bold text-dark"><?php echo htmlspecialchars($mr['drug_name']); ?></span>
                                        <small class="text-muted">(<?php echo htmlspecialchars($mr['icode']); ?>)</small>
                                        <?php if ($conc): ?><br><small class="text-primary"><?php echo htmlspecialchars($conc); ?></small><?php endif; ?>
                                        <?php echo $badge; ?>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="section-hard mb-4">
                        <h6 class="fw-bold text-danger mb-2"><i class="fas fa-exclamation-circle me-2"></i>Hard Alert <span id="hardCount" class="badge bg-danger">0</span></h6>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle border mb-0">
                                <thead class="text-center small">
                                    <tr>
                                        <th>HN</th><th>OPD/IPD</th><th class="text-start">ชื่อ-สกุล</th>
                                        <th>วันที่สั่ง</th><th class="text-start">ยา</th>
                                        <th class="text-start">วิธีใช้</th>
                                        <th>mg</th><th>mL (ระบุ)</th><th>mL (คำนวณ)</th><th>mg/mL</th>
                                        <th>หอผู้ป่วย</th><th class="text-start">ข้อความแจ้งเตือน</th>
                                    </tr>
                                </thead>
                                <tbody id="hardTbody"><tr><td colspan="12" class="text-center py-4 text-muted">กดปุ่มประมวลผลเพื่อดึงข้อมูล</td></tr></tbody>
                            </table>
                        </div>
                    </div>

                    <div class="section-soft mb-2">
                        <h6 class="fw-bold text-warning mb-2"><i class="fas fa-exclamation-triangle me-2"></i>Soft Alert <span id="softCount" class="badge bg-warning text-dark">0</span></h6>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle border mb-0">
                                <thead class="text-center small">
                                    <tr>
                                        <th>HN</th><th>OPD/IPD</th><th class="text-start">ชื่อ-สกุล</th>
                                        <th>วันที่สั่ง</th><th class="text-start">ยา</th>
                                        <th class="text-start">วิธีใช้</th>
                                        <th>mg</th><th>mL (ระบุ)</th><th>mL (คำนวณ)</th><th>mg/mL</th>
                                        <th>หอผู้ป่วย</th><th class="text-start">ข้อความแจ้งเตือน</th>
                                    </tr>
                                </thead>
                                <tbody id="softTbody"><tr><td colspan="12" class="text-center py-4 text-muted">—</td></tr></tbody>
                            </table>
                        </div>
                    </div>

                    <div class="section-pass mt-4">
                        <h6 class="fw-bold text-success mb-2"><i class="fas fa-check-circle me-2"></i>รายการผ่าน <span id="passCount" class="badge bg-success">0</span></h6>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle border mb-0">
                                <thead class="text-center small">
                                    <tr>
                                        <th>HN</th><th>OPD/IPD</th><th class="text-start">ชื่อ-สกุล</th>
                                        <th>วันที่สั่ง</th><th class="text-start">ยา</th>
                                        <th class="text-start">วิธีใช้</th>
                                        <th>mg</th><th>mL (ระบุ)</th><th>mL (คำนวณ)</th><th>ผลต่าง</th><th>mg/mL</th>
                                        <th>สถานะ</th>
                                    </tr>
                                </thead>
                                <tbody id="passTbody"><tr><td colspan="12" class="text-center py-4 text-muted">—</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade <?php echo $tab === 'settings' ? 'show active' : ''; ?>" id="settings">
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <form method="post" class="d-inline" onsubmit="return confirm('สแกนยาจาก HOSxP/TMT ที่มี strength แบบ mg/mL หรือ mcg/mL และเพิ่มกฎอัตโนมัติ?');">
                            <input type="hidden" name="action" value="scan_tmt">
                            <button type="submit" class="btn btn-sm btn-outline-primary fw-bold">
                                <i class="fas fa-search me-1"></i> สแกนจาก TMT (mg/mL, mcg/mL)
                            </button>
                        </form>
                        <span class="small text-muted align-self-center">อัปเดตเฉพาะรายการที่ตั้ง <code>auto_from_tmt</code> — รายการที่แก้มือจะไม่ถูกทับ</span>
                    </div>

                    <div class="row g-4">
                        <div class="col-lg-5">
                            <div class="card shadow-sm border-0" id="mmgmlFormCard">
                                <div class="card-header text-white fw-bold" style="background:#0d6efd;">
                                    <i class="fas fa-plus-circle me-1"></i> <span id="mmgmlFormTitle">เพิ่มกฎ mg → mL</span>
                                </div>
                                <div class="card-body">
                                    <form method="post" id="mmgmlForm">
                                        <input type="hidden" name="action" id="mmgml_action" value="add_mmgml">
                                        <input type="hidden" name="rule_id" id="mmgml_rule_id" value="">
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">ยา (HOSxP)</label>
                                            <select name="drug" id="mmgml_drug" class="form-select form-select-sm" required><?php echo $opt_drugs; ?></select>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">Strength (อ้างอิง)</label>
                                            <input type="text" name="strength_text" id="mmgml_strength_text" class="form-control form-control-sm"
                                                placeholder="เช่น 250 mg/mL หรือ 1000 mg/4 mL">
                                            <div class="form-text">กดปุ่ม Parse เพื่อคำนวณ mg/mL จากข้อความ strength</div>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">ความเข้มข้น mg/mL <span class="text-danger">*</span></label>
                                            <div class="input-group input-group-sm">
                                                <input type="number" name="mg_per_ml" id="mmgml_mg_per_ml" class="form-control" min="0" step="0.000001" required placeholder="เช่น 250">
                                                <button type="button" class="btn btn-outline-secondary" onclick="parseMMGMLStrength()">Parse</button>
                                            </div>
                                            <div class="form-text">Cloxacillin 1 GM ละลาย 4 mL → ใส่ 250</div>
                                        </div>
                                        <div class="row g-2 mb-2">
                                            <div class="col-6">
                                                <label class="form-label small fw-bold">อนุโลม (%)</label>
                                                <input type="number" name="tolerance_pct" id="mmgml_tolerance_pct" class="form-control form-control-sm" min="0" max="50" step="0.1" value="5">
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label small fw-bold">อนุโลมขั้นต่ำ (mL)</label>
                                                <input type="number" name="tolerance_ml" id="mmgml_tolerance_ml" class="form-control form-control-sm" min="0" step="0.001" value="0.02">
                                            </div>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">ระดับแจ้งเตือน</label>
                                            <select name="alert_level" id="mmgml_alert_level" class="form-select form-select-sm">
                                                <option value="hard">Hard Alert</option>
                                                <option value="soft">Soft Alert</option>
                                            </select>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">ข้อความแจ้งเตือน (ไม่บังคับ)</label>
                                            <input type="text" name="alert_message" id="mmgml_alert_message" class="form-control form-control-sm"
                                                placeholder="เช่น แปลง mg → mL ไม่ถูกต้อง — ตรวจความเข้มข้นหลังละลาย">
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">รายละเอียดกฎ (สำหรับเภสัช)</label>
                                            <textarea name="detail_text" id="mmgml_detail_text" class="form-control form-control-sm" rows="3"
                                                placeholder="เช่น Cloxacillin 1 g vial ละลาย 4 mL NS → 250 mg/mL"></textarea>
                                        </div>
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" name="auto_from_tmt" id="mmgml_auto_from_tmt">
                                            <label class="form-check-label small" for="mmgml_auto_from_tmt">อัปเดตอัตโนมัติจาก TMT เมื่อสแกน</label>
                                        </div>
                                        <div class="form-check mb-3">
                                            <input class="form-check-input" type="checkbox" name="active" id="mmgml_active" checked>
                                            <label class="form-check-label small" for="mmgml_active">เปิดใช้งาน</label>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <button type="submit" class="btn btn-sm text-white fw-bold" style="background:#0d6efd;">
                                                <i class="fas fa-save me-1"></i> บันทึก
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" id="mmgmlBtnReset" onclick="resetMMGMLForm()">ล้างฟอร์ม</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-7">
                            <h6 class="fw-bold text-secondary mb-3"><i class="fas fa-list me-1"></i>กฎที่ตั้งไว้ (<?php echo count($all_rules); ?> รายการ)</h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover border align-middle">
                                    <thead class="table-light text-center small">
                                        <tr>
                                            <th>ยา</th><th>icode</th><th>mg/mL</th><th>Strength</th><th>TMT</th><th>Alert</th><th>เปิด</th><th>จัดการ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php if (empty($all_rules)): ?>
                                        <tr><td colspan="8" class="text-center text-muted py-3">ยังไม่มีกฎ — กดสแกนจาก TMT หรือเพิ่ม Cloxacillin 250 mg/mL ด้วยตนเอง</td></tr>
                                    <?php else: foreach ($all_rules as $r):
                                        $rule_json = htmlspecialchars(json_encode([
                                            'id' => (int)$r['id'],
                                            'icode' => $r['icode'],
                                            'drug_name' => $r['drug_name'],
                                            'strength_text' => $r['strength_text'] ?? '',
                                            'mg_per_ml' => (float)($r['mg_per_ml'] ?? 0),
                                            'tolerance_pct' => (float)($r['tolerance_pct'] ?? 5),
                                            'tolerance_ml' => (float)($r['tolerance_ml'] ?? 0.02),
                                            'auto_from_tmt' => (int)!empty($r['auto_from_tmt']),
                                            'alert_level' => $r['alert_level'],
                                            'alert_message' => $r['alert_message'] ?? '',
                                            'detail_text' => $r['detail_text'] ?? '',
                                            'active' => (int)!empty($r['active']),
                                        ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                                    ?>
                                        <tr class="mmgml-rule-row" data-rule-id="<?php echo (int)$r['id']; ?>">
                                            <td class="fw-bold"><?php echo htmlspecialchars($r['drug_name']); ?></td>
                                            <td class="text-center"><code><?php echo htmlspecialchars($r['icode']); ?></code></td>
                                            <td class="text-center"><?php echo htmlspecialchars(mmgml_format_mg($r['mg_per_ml'])); ?></td>
                                            <td class="small"><?php echo htmlspecialchars($r['strength_text'] ?? '—'); ?></td>
                                            <td class="text-center"><?php echo !empty($r['auto_from_tmt']) ? '<i class="fas fa-robot text-primary" title="Auto TMT"></i>' : '—'; ?></td>
                                            <td class="text-center"><?php echo mmgml_alert_badge_html(mmgml_alert_level($r)); ?></td>
                                            <td class="text-center"><?php echo !empty($r['active']) ? '<i class="fas fa-check text-success"></i>' : '<i class="fas fa-times text-muted"></i>'; ?></td>
                                            <td class="text-center text-nowrap">
                                                <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2 mmgml-btn-edit" title="แก้ไข" data-rule="<?php echo $rule_json; ?>"><i class="fas fa-edit"></i></button>
                                                <form method="post" class="d-inline" onsubmit="return confirm('ลบกฎนี้?');">
                                                    <input type="hidden" name="action" value="delete_mmgml">
                                                    <input type="hidden" name="del_id" value="<?php echo (int)$r['id']; ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2"><i class="fas fa-trash"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
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
const MMGML_PARSE_RULES = <?php
    echo json_encode([
        ['regex' => '/(\\d+(?:\\.\\d+)?)\\s*mg\\s*\\/\\s*(\\d+(?:\\.\\d+)?)?\\s*ml\\b/i', 'unit' => 'mg'],
        ['regex' => '/(\\d+(?:\\.\\d+)?)\\s*(?:mcg|µg|μg|ug)\\s*\\/\\s*(\\d+(?:\\.\\d+)?)?\\s*ml\\b/i', 'unit' => 'mcg'],
        ['regex' => '/(\\d+(?:\\.\\d+)?)\\s*(?:g|gm|gram)\\s*\\/\\s*(\\d+(?:\\.\\d+)?)?\\s*ml\\b/i', 'unit' => 'g'],
    ], JSON_UNESCAPED_UNICODE);
?>;

let fpStart, fpEnd;

function mmgmlEsc(s) {
    if (s == null) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function ensureMmgmlSelectOption($sel, value, label) {
    if (!$sel.find('option').filter(function() { return $(this).val() === value; }).length) {
        $sel.append(new Option(label, value, false, false));
    }
}

function parseMMGMLStrength() {
    const text = ($('#mmgml_strength_text').val() || '').trim();
    if (!text) {
        alert('กรุณาใส่ strength เช่น 250 mg/mL หรือ 1000 mg/4 mL');
        return;
    }
    for (const p of MMGML_PARSE_RULES) {
        const re = new RegExp(p.regex.source, p.regex.flags || 'i');
        const m = text.match(re);
        if (!m) continue;
        let num = parseFloat(m[1]);
        let vol = m[2] !== undefined && m[2] !== '' ? parseFloat(m[2]) : 1;
        if (!(vol > 0)) continue;
        if (p.unit === 'mcg') num = num / 1000;
        if (p.unit === 'g') num = num * 1000;
        const mgPerMl = num / vol;
        if (mgPerMl > 0) {
            $('#mmgml_mg_per_ml').val(mgPerMl);
            return;
        }
    }
    alert('ไม่สามารถแปลง strength นี้ได้ — ลองใส่ mg/mL โดยตรง');
}

$(function() {
    $('#mmgml_drug').select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'ค้นหายา...' });
    if (typeof flatpickr !== 'undefined') {
        fpStart = flatpickr('#filter_start', { dateFormat: 'Y-m-d' });
        fpEnd = flatpickr('#filter_end', { dateFormat: 'Y-m-d' });
    }
    $(document).on('click', '.mmgml-btn-edit', function() {
        let rule = $(this).data('rule');
        if (typeof rule === 'string') {
            try { rule = JSON.parse(rule); } catch (e) { return; }
        }
        if (!rule || !rule.id) return;
        setMmgmlEditMode(rule);
    });
});

function getSelectedMmgmlRuleIds() {
    const ids = [];
    $('.mmgml-rule-cb:checked').each(function() { ids.push($(this).val()); });
    return ids;
}

function mmgmlSelectAllRules(on) {
    $('.mmgml-rule-cb').prop('checked', !!on);
}

function setMMGMLDateRange(days) {
    const end = new Date();
    const start = new Date();
    if (days <= 1) {
        start.setHours(0,0,0,0);
    } else {
        start.setDate(end.getDate() - (days - 1));
    }
    const fmt = d => d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
    if (fpStart) fpStart.setDate(fmt(start), true); else $('#filter_start').val(fmt(start));
    if (fpEnd) fpEnd.setDate(fmt(end), true); else $('#filter_end').val(fmt(end));
    $('.mmgml-range-btn').removeClass('active');
    $('.mmgml-range-btn[data-days="' + days + '"]').addClass('active');
}

function renderMMGMLRow(item) {
    const when = item.order_time
        ? item.order_date + ' <span class="text-dark">' + mmgmlEsc(item.order_time) + '</span>'
        : mmgmlEsc(item.order_date);
    const calcHtml = '<div class="ml-mismatch small">' + mmgmlEsc(item.expected_ml_display || '') + '</div>';
    let detail = '';
    if (item.detail_text) {
        detail = '<div class="text-muted small mt-1">' + mmgmlEsc(item.detail_text) + '</div>';
    }
    return '<tr>'
        + '<td class="text-center fw-bold">' + mmgmlEsc(item.hn) + '</td>'
        + '<td class="text-center"><span class="badge ' + (item.type_patient === 'IPD' ? 'bg-secondary' : 'bg-info text-dark') + '">' + mmgmlEsc(item.type_patient) + '</span></td>'
        + '<td>' + mmgmlEsc(item.ptname) + '</td>'
        + '<td class="text-center small">' + when + '</td>'
        + '<td class="text-start"><b>' + mmgmlEsc(item.drug_name) + '</b><br><small class="text-muted">icode: ' + mmgmlEsc(item.icode) + '</small></td>'
        + '<td class="text-start small">' + mmgmlEsc(item.method || '-') + '</td>'
        + '<td class="text-center small fw-bold">' + mmgmlEsc(item.dose_mg_display || '—') + '</td>'
        + '<td class="text-center small text-danger fw-bold">' + mmgmlEsc(item.stated_ml_display || '—') + '</td>'
        + '<td class="text-center small">' + calcHtml + '</td>'
        + '<td class="text-center small">' + mmgmlEsc(item.mg_per_ml_display || '—') + '</td>'
        + '<td class="text-center small">' + mmgmlEsc(item.ward_display || '-') + '</td>'
        + '<td class="text-start small text-danger fw-bold">' + mmgmlEsc(item.alert_message) + detail + '</td>'
        + '</tr>';
}

function renderMMGMLPassRow(item) {
    const when = item.order_time
        ? item.order_date + ' <span class="text-dark">' + mmgmlEsc(item.order_time) + '</span>'
        : mmgmlEsc(item.order_date);
    const calcHtml = '<div class="ml-pass small">' + mmgmlEsc(item.expected_ml_display || '') + '</div>';
    return '<tr>'
        + '<td class="text-center fw-bold">' + mmgmlEsc(item.hn) + '</td>'
        + '<td class="text-center"><span class="badge ' + (item.type_patient === 'IPD' ? 'bg-secondary' : 'bg-info text-dark') + '">' + mmgmlEsc(item.type_patient) + '</span></td>'
        + '<td>' + mmgmlEsc(item.ptname) + '</td>'
        + '<td class="text-center small">' + when + '</td>'
        + '<td class="text-start"><b>' + mmgmlEsc(item.drug_name) + '</b><br><small class="text-muted">icode: ' + mmgmlEsc(item.icode) + '</small></td>'
        + '<td class="text-start small">' + mmgmlEsc(item.method || '-') + '</td>'
        + '<td class="text-center small fw-bold">' + mmgmlEsc(item.dose_mg_display || '—') + '</td>'
        + '<td class="text-center small">' + mmgmlEsc(item.stated_ml_display || '—') + '</td>'
        + '<td class="text-center small">' + calcHtml + '</td>'
        + '<td class="text-center small">' + mmgmlEsc(item.diff_ml_display || '—') + '</td>'
        + '<td class="text-center small">' + mmgmlEsc(item.mg_per_ml_display || '—') + '</td>'
        + '<td class="text-start small text-success fw-bold">' + mmgmlEsc(item.pass_message || 'ผ่านเกณฑ์') + '</td>'
        + '</tr>';
}

function fetchMMGMLData() {
    const ruleIds = getSelectedMmgmlRuleIds();
    if (ruleIds.length === 0) {
        alert('กรุณาเลือกอย่างน้อย 1 กฎ');
        return;
    }
    const qs = new URLSearchParams({
        start_date: $('#filter_start').val(),
        end_date: $('#filter_end').val(),
        rule_ids: ruleIds.join(',')
    });
    $('#btnFetchMMGML').prop('disabled', true);
    $('#hardTbody').html('<tr><td colspan="12" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i>กำลังประมวลผล...</td></tr>');
    $('#softTbody').html('<tr><td colspan="12" class="text-center py-4 text-muted">—</td></tr>');
    $('#passTbody').html('<tr><td colspan="12" class="text-center py-4 text-muted">—</td></tr>');

    fetch('fetch_monitor_mg_ml_api.php?' + qs.toString(), { credentials: 'same-origin' })
    .then(async r => {
        const text = await r.text();
        try { return JSON.parse(text); } catch (e) { throw new Error(text ? text.substring(0, 300) : ('HTTP ' + r.status)); }
    })
    .then(data => {
        $('#btnFetchMMGML').prop('disabled', false);
        if (data.status !== 'success') {
            $('#hardTbody').html('<tr><td colspan="12" class="text-center text-danger py-4">' + mmgmlEsc(data.message || 'เกิดข้อผิดพลาด') + '</td></tr>');
            return;
        }
        const meta = data.meta || {};
        const hard = data.hard_alerts || [];
        const soft = data.soft_alerts || [];
        const passed = data.passed_items || [];
        $('#hardCount').text(meta.hard_count || hard.length);
        $('#softCount').text(meta.soft_count || soft.length);
        $('#passCount').text(meta.passed_count || passed.length);
        let metaHtml = '<i class="fas fa-info-circle me-1"></i> Hard: ' + (meta.hard_count || 0) + ' | Soft: ' + (meta.soft_count || 0) + ' | ผ่าน: ' + (meta.passed_count || 0);
        if (meta.orders_scanned) metaHtml += ' | สแกน order: ' + meta.orders_scanned;
        if (meta.orders_checked) metaHtml += ' | ตรวจ mg+mL: ' + meta.orders_checked;
        if (meta.elapsed_ms) metaHtml += ' | ' + (meta.elapsed_ms / 1000).toFixed(1) + ' วินาที';
        $('#reportMeta').removeClass('d-none').html(metaHtml);

        if (hard.length === 0) {
            $('#hardTbody').html('<tr><td colspan="12" class="text-center py-4 text-success fw-bold"><i class="fas fa-check-circle me-2"></i>ไม่พบ Hard Alert</td></tr>');
        } else {
            $('#hardTbody').html(hard.map(renderMMGMLRow).join(''));
        }
        if (soft.length === 0) {
            $('#softTbody').html('<tr><td colspan="12" class="text-center py-4 text-muted">ไม่พบ Soft Alert</td></tr>');
        } else {
            $('#softTbody').html(soft.map(renderMMGMLRow).join(''));
        }
        if (passed.length === 0) {
            $('#passTbody').html('<tr><td colspan="12" class="text-center py-4 text-muted">ยังไม่พบรายการที่ผ่านเงื่อนไข</td></tr>');
        } else {
            $('#passTbody').html(passed.map(renderMMGMLPassRow).join(''));
        }
    })
    .catch(err => {
        $('#btnFetchMMGML').prop('disabled', false);
        $('#hardTbody').html('<tr><td colspan="12" class="text-center text-danger py-4">' + mmgmlEsc(err.message || 'เชื่อมต่อ API ไม่สำเร็จ') + '</td></tr>');
    });
}

function resetMMGMLForm() {
    $('#mmgmlFormCard').removeClass('edit-mode');
    $('#mmgmlFormTitle').text('เพิ่มกฎ mg → mL');
    $('#mmgml_action').val('add_mmgml');
    $('#mmgml_rule_id').val('');
    $('#mmgml_drug').val('').trigger('change');
    $('#mmgml_strength_text').val('');
    $('#mmgml_mg_per_ml').val('');
    $('#mmgml_tolerance_pct').val('5');
    $('#mmgml_tolerance_ml').val('0.02');
    $('#mmgml_alert_level').val('hard');
    $('#mmgml_alert_message').val('');
    $('#mmgml_detail_text').val('');
    $('#mmgml_auto_from_tmt').prop('checked', false);
    $('#mmgml_active').prop('checked', true);
    $('.mmgml-rule-row').removeClass('mmgml-editing-row');
}

function setMmgmlEditMode(rule) {
    $('.mmgml-rule-row').removeClass('mmgml-editing-row');
    $('tr.mmgml-rule-row[data-rule-id="' + rule.id + '"]').addClass('mmgml-editing-row');
    $('#mmgmlFormCard').addClass('edit-mode');
    $('#mmgmlFormTitle').text('แก้ไขกฎ: ' + (rule.drug_name || '') + ' (ID: ' + rule.id + ')');
    $('#mmgml_action').val('edit_mmgml');
    $('#mmgml_rule_id').val(rule.id);
    const drugVal = (rule.icode || '') + '|' + (rule.drug_name || '');
    ensureMmgmlSelectOption($('#mmgml_drug'), drugVal, (rule.drug_name || '') + ' (icode: ' + rule.icode + ')');
    $('#mmgml_drug').val(drugVal).trigger('change');
    $('#mmgml_strength_text').val(rule.strength_text || '');
    $('#mmgml_mg_per_ml').val(rule.mg_per_ml || '');
    $('#mmgml_tolerance_pct').val(rule.tolerance_pct != null ? rule.tolerance_pct : 5);
    $('#mmgml_tolerance_ml').val(rule.tolerance_ml != null ? rule.tolerance_ml : 0.02);
    $('#mmgml_alert_level').val(rule.alert_level === 'soft' ? 'soft' : 'hard');
    $('#mmgml_alert_message').val(rule.alert_message || '');
    $('#mmgml_detail_text').val(rule.detail_text || '');
    $('#mmgml_auto_from_tmt').prop('checked', !!rule.auto_from_tmt);
    $('#mmgml_active').prop('checked', !!rule.active);
    document.getElementById('mmgmlFormCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
}
</script>
</body>
</html>
