<?php
session_start();
if (!isset($_SESSION['user_id']) && !isset($_SESSION['full_name'])) {
    header('Location: login.php');
    exit;
}
include('connect.php');
include('hosxp_tmt_drug_helper.php');
include('monitor_max_dose_helper.php');
mysqli_set_charset($con, 'utf8');

mmdd_ensure_table($con);

$default_start = date('Y-m-d', strtotime('-3 days'));
$default_end = date('Y-m-d');

function mmdd_escape($con, $v) {
    return mysqli_real_escape_string($con, $v ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_mmdd' || $action === 'edit_mmdd') {
        $drug = hosxp_parse_drug_option($_POST['drug'] ?? '');
        $icode = mmdd_escape($con, $drug['icode']);
        $drug_name = mmdd_escape($con, $drug['name']);
        $max_dose = (float)($_POST['max_dose_mg_per_day'] ?? 0);
        $max_days = (int)($_POST['max_duration_days'] ?? 0);
        $valid = mmdd_rule_is_valid([
            'max_dose_mg_per_day' => $max_dose,
            'max_duration_days' => $max_days,
        ]);
        $alert_level = ($_POST['alert_level'] ?? '') === 'soft' ? 'soft' : 'hard';
        $alert_message = mmdd_escape($con, $_POST['alert_message'] ?? '');
        $detail_text = mmdd_escape($con, $_POST['detail_text'] ?? '');
        $active = isset($_POST['active']) ? 1 : 0;
        $lvl_esc = mmdd_escape($con, $alert_level);

        if ($icode === '') {
            $_SESSION['mmdd_error'] = 'กรุณาเลือกยาจาก HOSxP';
            header('Location: monitor_max_dose.php?tab=settings');
            exit;
        }
        if (!$valid['ok']) {
            $_SESSION['mmdd_error'] = $valid['error'];
            header('Location: monitor_max_dose.php?tab=settings');
            exit;
        }

        if ($action === 'add_mmdd') {
            $chk = mysqli_query($con, "SELECT id FROM monitor_max_dose_settings WHERE icode='$icode' LIMIT 1");
            if ($chk && mysqli_num_rows($chk) > 0) {
                $_SESSION['mmdd_error'] = 'มีกฎสำหรับ icode นี้แล้ว — ใช้แก้ไขแทน';
            } else {
                $sql = "INSERT INTO monitor_max_dose_settings
                    (icode, drug_name, max_dose_mg_per_day, max_duration_days, alert_level, alert_message, detail_text, active)
                    VALUES ('$icode', '$drug_name', $max_dose, $max_days, '$lvl_esc', '$alert_message', '$detail_text', $active)";
                if (mysqli_query($con, $sql)) {
                    $_SESSION['mmdd_msg'] = 'เพิ่มกฎ Max Dose/Duration เรียบร้อย';
                } else {
                    $_SESSION['mmdd_error'] = 'บันทึกไม่สำเร็จ: ' . mysqli_error($con);
                }
            }
        } else {
            $id = (int)($_POST['rule_id'] ?? 0);
            if ($id <= 0) {
                $_SESSION['mmdd_error'] = 'ไม่พบรายการที่จะแก้ไข';
            } else {
                $sql = "UPDATE monitor_max_dose_settings SET
                    icode='$icode', drug_name='$drug_name', max_dose_mg_per_day=$max_dose, max_duration_days=$max_days,
                    alert_level='$lvl_esc', alert_message='$alert_message', detail_text='$detail_text', active=$active
                    WHERE id=$id";
                if (mysqli_query($con, $sql)) {
                    $_SESSION['mmdd_msg'] = 'บันทึกการแก้ไขเรียบร้อย';
                } else {
                    $_SESSION['mmdd_error'] = 'บันทึกไม่สำเร็จ: ' . mysqli_error($con);
                }
            }
        }
        header('Location: monitor_max_dose.php?tab=settings');
        exit;
    }

    if ($action === 'delete_mmdd') {
        $id = (int)($_POST['del_id'] ?? 0);
        if ($id > 0) {
            mysqli_query($con, "DELETE FROM monitor_max_dose_settings WHERE id=$id");
            $_SESSION['mmdd_msg'] = 'ลบกฎเรียบร้อย';
        }
        header('Location: monitor_max_dose.php?tab=settings');
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
$q_rules = mysqli_query($con, "SELECT * FROM monitor_max_dose_settings ORDER BY drug_name ASC, id ASC");
if ($q_rules) {
    while ($r = mysqli_fetch_assoc($q_rules)) {
        $all_rules[] = $r;
        if (!empty($r['active'])) {
            $monitor_rules[] = $r;
        }
    }
}

$tab = (isset($_GET['tab']) && $_GET['tab'] === 'settings') ? 'settings' : 'monitor';
$flash_msg = $_SESSION['mmdd_msg'] ?? '';
$flash_err = $_SESSION['mmdd_error'] ?? '';
unset($_SESSION['mmdd_msg'], $_SESSION['mmdd_error']);
session_write_close();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Monitor Max Dose/Duration - THAM SAFE</title>
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
        .mmdd-range-btn.active { background-color: #7c3aed !important; border-color: #7c3aed !important; color: #fff !important; }
        .dose-over { background: #fee2e2; border-left: 3px solid #dc3545; padding: 4px 8px; border-radius: 4px; font-weight: 600; }
        #mmddFormCard.edit-mode .card-header { background-color: #5b21b6 !important; }
        tr.mmdd-editing-row { background-color: #f3e8ff !important; }
    </style>
</head>
<body>
<div class="wrapper">
    <?php include('sidebar.php'); ?>
    <div id="content" class="content">
        <div class="container-fluid py-4">
            <h4 class="fw-bold mb-2 text-dark">
                <i class="fas fa-tachometer-alt me-2" style="color:#7c3aed;"></i>
                Monitor Max Dose / Duration
            </h4>
            <p class="text-muted small mb-4">
                ตรวจขนาดยารวมต่อวัน (mg/วัน) และระยะเวลาสั่งยาติดต่อกัน — ตัวอย่าง
                <b>Amlodipine Max 10 mg/วัน</b>: แพทย์ต้องการเพิ่มจาก 5 → 10 mg ควรสั่ง 10 mg 1 tab OD
                แต่ถ้าสั่ง 10 mg 1 tab BID ผู้ป่วยจะได้ 20 mg/วัน (เกินขีดจำกัด)
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
                        <i class="fas fa-cogs me-1"></i> ตั้งค่ากฎ Max Dose/Duration
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
                                <button type="button" class="btn btn-sm fw-bold text-white w-100 shadow-sm" style="background:#7c3aed;" id="btnFetchMMDD" onclick="fetchMMDDData()">
                                    <i class="fas fa-sync-alt me-1"></i> ประมวลผล
                                </button>
                            </div>
                        </div>
                        <div class="row g-2 mt-2 align-items-center">
                            <div class="col-auto"><label class="form-label small fw-bold mb-0 text-secondary">ช่วงเร็ว:</label></div>
                            <div class="col">
                                <div class="btn-group btn-group-sm flex-wrap">
                                    <button type="button" class="btn btn-outline-secondary fw-bold mmdd-range-btn" data-days="1" onclick="setMMDDDateRange(1)">วันนี้</button>
                                    <button type="button" class="btn btn-outline-secondary fw-bold mmdd-range-btn active" data-days="3" onclick="setMMDDDateRange(3)">3 วัน</button>
                                    <button type="button" class="btn btn-outline-secondary fw-bold mmdd-range-btn" data-days="7" onclick="setMMDDDateRange(7)">7 วัน</button>
                                    <button type="button" class="btn btn-outline-secondary fw-bold mmdd-range-btn" data-days="30" onclick="setMMDDDateRange(30)">30 วัน</button>
                                </div>
                            </div>
                        </div>
                        <div id="reportMeta" class="alert alert-info py-2 px-3 mt-2 mb-0 small fw-bold d-none"></div>
                    </div>

                    <div class="border rounded p-3 mb-3" id="mmddRuleFilterBox">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                            <label class="form-label fw-bold small mb-0">
                                <i class="fas fa-filter me-1 text-primary"></i> เลือกยาที่ต้องการรายงาน
                            </label>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-primary fw-bold" onclick="mmddSelectAllRules(true)">เลือกทั้งหมด</button>
                                <button type="button" class="btn btn-outline-secondary fw-bold" onclick="mmddSelectAllRules(false)">ยกเลิกทั้งหมด</button>
                            </div>
                        </div>
                        <?php if (empty($monitor_rules)): ?>
                        <div class="small text-muted">ยังไม่มีกฎที่เปิดใช้งาน — ไปที่แท็บ <b>ตั้งค่ากฎ</b> (เช่น Amlodipine Max 10 mg/วัน)</div>
                        <?php else: ?>
                        <div class="row g-2">
                            <?php foreach ($monitor_rules as $mr):
                                $lvl = mmdd_alert_level($mr);
                                $badge = $lvl === 'hard'
                                    ? '<span class="badge bg-danger ms-1">Hard</span>'
                                    : '<span class="badge bg-warning text-dark ms-1">Soft</span>';
                                $dose_txt = (float)($mr['max_dose_mg_per_day'] ?? 0) > 0
                                    ? rtrim(rtrim(number_format((float)$mr['max_dose_mg_per_day'], 2, '.', ''), '0'), '.') . ' mg/วัน'
                                    : '';
                                $dur_txt = (int)($mr['max_duration_days'] ?? 0) > 0
                                    ? (int)$mr['max_duration_days'] . ' วัน'
                                    : '';
                                $limit = trim($dose_txt . ($dose_txt && $dur_txt ? ' | ' : '') . $dur_txt);
                            ?>
                            <div class="col-md-6 col-xl-4">
                                <div class="form-check border rounded px-2 py-1 bg-white h-100">
                                    <input class="form-check-input mmdd-rule-cb" type="checkbox" value="<?php echo (int)$mr['id']; ?>"
                                           id="mmdd_rule_cb_<?php echo (int)$mr['id']; ?>" checked>
                                    <label class="form-check-label small" for="mmdd_rule_cb_<?php echo (int)$mr['id']; ?>">
                                        <span class="fw-bold text-dark"><?php echo htmlspecialchars($mr['drug_name']); ?></span>
                                        <small class="text-muted">(<?php echo htmlspecialchars($mr['icode']); ?>)</small>
                                        <?php if ($limit): ?><br><small class="text-primary"><?php echo htmlspecialchars($limit); ?></small><?php endif; ?>
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
                                        <th class="text-start">วิธีใช้</th><th class="text-start">mg/วัน (คำนวณ)</th>
                                        <th>Max</th><th>วันติดต่อ</th>
                                        <th>หอผู้ป่วย</th><th class="text-start">ข้อความแจ้งเตือน</th>
                                    </tr>
                                </thead>
                                <tbody id="hardTbody"><tr><td colspan="11" class="text-center py-4 text-muted">กดปุ่มประมวลผลเพื่อดึงข้อมูล</td></tr></tbody>
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
                                        <th class="text-start">วิธีใช้</th><th class="text-start">mg/วัน (คำนวณ)</th>
                                        <th>Max</th><th>วันติดต่อ</th>
                                        <th>หอผู้ป่วย</th><th class="text-start">ข้อความแจ้งเตือน</th>
                                    </tr>
                                </thead>
                                <tbody id="softTbody"><tr><td colspan="11" class="text-center py-4 text-muted">—</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade <?php echo $tab === 'settings' ? 'show active' : ''; ?>" id="settings">
                    <div class="row g-4">
                        <div class="col-lg-5">
                            <div class="card shadow-sm border-0" id="mmddFormCard">
                                <div class="card-header text-white fw-bold" style="background:#7c3aed;">
                                    <i class="fas fa-plus-circle me-1"></i> <span id="mmddFormTitle">เพิ่มกฎ Max Dose/Duration</span>
                                </div>
                                <div class="card-body">
                                    <form method="post" id="mmddForm">
                                        <input type="hidden" name="action" id="mmdd_action" value="add_mmdd">
                                        <input type="hidden" name="rule_id" id="mmdd_rule_id" value="">
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">ยา (HOSxP)</label>
                                            <select name="drug" id="mmdd_drug" class="form-select form-select-sm" required><?php echo $opt_drugs; ?></select>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">Max Dose (mg/วัน)</label>
                                            <input type="number" name="max_dose_mg_per_day" id="mmdd_max_dose" class="form-control form-control-sm" min="0" step="0.01" placeholder="เช่น 10">
                                            <div class="form-text">0 = ไม่ตรวจขนาด — คำนวณจาก strength × จำนวน/วัน ตามวิธีใช้</div>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">Max Duration (วันสั่งติดต่อกัน)</label>
                                            <input type="number" name="max_duration_days" id="mmdd_max_days" class="form-control form-control-sm" min="0" step="1" value="0">
                                            <div class="form-text">0 = ไม่ตรวจระยะเวลา — นับวันที่มีสั่งยา icode นี้ติดต่อกัน</div>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">ระดับแจ้งเตือน</label>
                                            <select name="alert_level" id="mmdd_alert_level" class="form-select form-select-sm">
                                                <option value="hard">Hard Alert</option>
                                                <option value="soft">Soft Alert</option>
                                            </select>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">ข้อความแจ้งเตือน (ไม่บังคับ)</label>
                                            <input type="text" name="alert_message" id="mmdd_alert_message" class="form-control form-control-sm"
                                                placeholder="เช่น Amlodipine เกิน 10 mg/วัน — ตรวจความถี่ BID/OD">
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">รายละเอียดกฎ (สำหรับเภสัช)</label>
                                            <textarea name="detail_text" id="mmdd_detail_text" class="form-control form-control-sm" rows="3"
                                                placeholder="เช่น จุดที่พลาดบ่อย: ต้องการเพิ่มจาก 5→10 mg ควรเปลี่ยน strength เป็น 10 mg OD ไม่ใช่ 10 mg BID"></textarea>
                                        </div>
                                        <div class="form-check mb-3">
                                            <input class="form-check-input" type="checkbox" name="active" id="mmdd_active" checked>
                                            <label class="form-check-label small" for="mmdd_active">เปิดใช้งาน</label>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <button type="submit" class="btn btn-sm text-white fw-bold" style="background:#7c3aed;">
                                                <i class="fas fa-save me-1"></i> บันทึก
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" id="mmddBtnReset" onclick="resetMMDDForm()">ล้างฟอร์ม</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-7">
                            <h6 class="fw-bold text-secondary mb-3"><i class="fas fa-list me-1"></i>กฎที่ตั้งไว้</h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover border align-middle">
                                    <thead class="table-light text-center small">
                                        <tr>
                                            <th>ยา</th><th>icode</th><th>Max mg/วัน</th><th>Max วัน</th><th>Alert</th><th>เปิด</th><th>จัดการ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php if (empty($all_rules)): ?>
                                        <tr><td colspan="7" class="text-center text-muted py-3">ยังไม่มีกฎ — เพิ่ม Amlodipine Max 10 mg/วัน ได้ทางซ้าย</td></tr>
                                    <?php else: foreach ($all_rules as $r):
                                        $rule_json = htmlspecialchars(json_encode([
                                            'id' => (int)$r['id'],
                                            'icode' => $r['icode'],
                                            'drug_name' => $r['drug_name'],
                                            'max_dose_mg_per_day' => (float)($r['max_dose_mg_per_day'] ?? 0),
                                            'max_duration_days' => (int)($r['max_duration_days'] ?? 0),
                                            'alert_level' => $r['alert_level'],
                                            'alert_message' => $r['alert_message'] ?? '',
                                            'detail_text' => $r['detail_text'] ?? '',
                                            'active' => (int)!empty($r['active']),
                                        ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                                    ?>
                                        <tr class="mmdd-rule-row" data-rule-id="<?php echo (int)$r['id']; ?>">
                                            <td class="fw-bold"><?php echo htmlspecialchars($r['drug_name']); ?></td>
                                            <td class="text-center"><code><?php echo htmlspecialchars($r['icode']); ?></code></td>
                                            <td class="text-center"><?php echo (float)($r['max_dose_mg_per_day'] ?? 0) > 0 ? htmlspecialchars(rtrim(rtrim(number_format((float)$r['max_dose_mg_per_day'], 2, '.', ''), '0'), '.')) : '—'; ?></td>
                                            <td class="text-center"><?php echo (int)($r['max_duration_days'] ?? 0) > 0 ? (int)$r['max_duration_days'] : '—'; ?></td>
                                            <td class="text-center"><?php echo mmdd_alert_badge_html(mmdd_alert_level($r)); ?></td>
                                            <td class="text-center"><?php echo !empty($r['active']) ? '<i class="fas fa-check text-success"></i>' : '<i class="fas fa-times text-muted"></i>'; ?></td>
                                            <td class="text-center text-nowrap">
                                                <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2 mmdd-btn-edit" title="แก้ไข" data-rule="<?php echo $rule_json; ?>"><i class="fas fa-edit"></i></button>
                                                <form method="post" class="d-inline" onsubmit="return confirm('ลบกฎนี้?');">
                                                    <input type="hidden" name="action" value="delete_mmdd">
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
const MMDD_HAS_RULES = <?php echo !empty($monitor_rules) ? 'true' : 'false'; ?>;
let fpStart, fpEnd;

function mmddEsc(s) {
    if (s == null) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function ensureMmddSelectOption($sel, value, label) {
    if (!$sel.find('option').filter(function() { return $(this).val() === value; }).length) {
        $sel.append(new Option(label, value, false, false));
    }
}

$(function() {
    $('#mmdd_drug').select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'ค้นหายา...' });
    if (typeof flatpickr !== 'undefined') {
        fpStart = flatpickr('#filter_start', { dateFormat: 'Y-m-d' });
        fpEnd = flatpickr('#filter_end', { dateFormat: 'Y-m-d' });
    }
    $(document).on('click', '.mmdd-btn-edit', function() {
        let rule = $(this).data('rule');
        if (typeof rule === 'string') {
            try { rule = JSON.parse(rule); } catch (e) { return; }
        }
        if (!rule || !rule.id) return;
        setMmddEditMode(rule);
    });
});

function getSelectedMmddRuleIds() {
    const ids = [];
    $('.mmdd-rule-cb:checked').each(function() { ids.push($(this).val()); });
    return ids;
}

function mmddSelectAllRules(on) {
    $('.mmdd-rule-cb').prop('checked', !!on);
}

function setMMDDDateRange(days) {
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
    $('.mmdd-range-btn').removeClass('active');
    $('.mmdd-range-btn[data-days="' + days + '"]').addClass('active');
}

function renderMMDDRow(item) {
    const when = item.order_time
        ? item.order_date + ' <span class="text-dark">' + mmddEsc(item.order_time) + '</span>'
        : mmddEsc(item.order_date);
    const doseHtml = item.daily_mg_display
        ? '<div class="dose-over small">' + mmddEsc(item.daily_mg_display) + '</div>'
        : '<span class="text-muted small">—</span>';
    const maxDose = item.max_dose_mg_per_day > 0 ? mmddEsc(item.max_dose_mg_per_day) + ' mg' : '—';
    const maxDays = item.max_duration_days > 0 ? mmddEsc(item.max_duration_days) : '—';
    const consec = item.consecutive_days > 0 ? mmddEsc(item.consecutive_days) : '—';
    let detail = '';
    if (item.detail_text) {
        detail = '<div class="text-muted small mt-1">' + mmddEsc(item.detail_text) + '</div>';
    }
    return '<tr>'
        + '<td class="text-center fw-bold">' + mmddEsc(item.hn) + '</td>'
        + '<td class="text-center"><span class="badge ' + (item.type_patient === 'IPD' ? 'bg-secondary' : 'bg-info text-dark') + '">' + mmddEsc(item.type_patient) + '</span></td>'
        + '<td>' + mmddEsc(item.ptname) + '</td>'
        + '<td class="text-center small">' + when + '</td>'
        + '<td class="text-start"><b>' + mmddEsc(item.drug_name) + '</b><br><small class="text-muted">icode: ' + mmddEsc(item.icode) + '</small></td>'
        + '<td class="text-start small">' + mmddEsc(item.method || '-') + '</td>'
        + '<td class="text-start">' + doseHtml + '</td>'
        + '<td class="text-center small">' + maxDose + '</td>'
        + '<td class="text-center small">' + consec + ' / ' + maxDays + '</td>'
        + '<td class="text-center small">' + mmddEsc(item.ward_display || '-') + '</td>'
        + '<td class="text-start small text-danger fw-bold">' + mmddEsc(item.alert_message) + detail + '</td>'
        + '</tr>';
}

function fetchMMDDData() {
    const ruleIds = getSelectedMmddRuleIds();
    if (ruleIds.length === 0) {
        alert('กรุณาเลือกอย่างน้อย 1 กฎ');
        return;
    }
    const qs = new URLSearchParams({
        start_date: $('#filter_start').val(),
        end_date: $('#filter_end').val(),
        rule_ids: ruleIds.join(',')
    });
    $('#btnFetchMMDD').prop('disabled', true);
    $('#hardTbody').html('<tr><td colspan="11" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i>กำลังประมวลผล...</td></tr>');
    $('#softTbody').html('<tr><td colspan="11" class="text-center py-4 text-muted">—</td></tr>');

    fetch('fetch_monitor_max_dose_api.php?' + qs.toString(), { credentials: 'same-origin' })
    .then(async r => {
        const text = await r.text();
        try { return JSON.parse(text); } catch (e) { throw new Error(text ? text.substring(0, 300) : ('HTTP ' + r.status)); }
    })
    .then(data => {
        $('#btnFetchMMDD').prop('disabled', false);
        if (data.status !== 'success') {
            $('#hardTbody').html('<tr><td colspan="11" class="text-center text-danger py-4">' + mmddEsc(data.message || 'เกิดข้อผิดพลาด') + '</td></tr>');
            return;
        }
        const meta = data.meta || {};
        const hard = data.hard_alerts || [];
        const soft = data.soft_alerts || [];
        $('#hardCount').text(meta.hard_count || hard.length);
        $('#softCount').text(meta.soft_count || soft.length);
        let metaHtml = '<i class="fas fa-info-circle me-1"></i> Hard: ' + (meta.hard_count || 0) + ' | Soft: ' + (meta.soft_count || 0);
        if (meta.orders_scanned) metaHtml += ' | สแกน order: ' + meta.orders_scanned;
        if (meta.elapsed_ms) metaHtml += ' | ' + (meta.elapsed_ms / 1000).toFixed(1) + ' วินาที';
        $('#reportMeta').removeClass('d-none').html(metaHtml);

        if (hard.length === 0) {
            $('#hardTbody').html('<tr><td colspan="11" class="text-center py-4 text-success fw-bold"><i class="fas fa-check-circle me-2"></i>ไม่พบ Hard Alert</td></tr>');
        } else {
            $('#hardTbody').html(hard.map(renderMMDDRow).join(''));
        }
        if (soft.length === 0) {
            $('#softTbody').html('<tr><td colspan="11" class="text-center py-4 text-muted">ไม่พบ Soft Alert</td></tr>');
        } else {
            $('#softTbody').html(soft.map(renderMMDDRow).join(''));
        }
    })
    .catch(err => {
        $('#btnFetchMMDD').prop('disabled', false);
        $('#hardTbody').html('<tr><td colspan="11" class="text-center text-danger py-4">' + mmddEsc(err.message || 'เชื่อมต่อ API ไม่สำเร็จ') + '</td></tr>');
    });
}

function resetMMDDForm() {
    $('#mmddFormCard').removeClass('edit-mode');
    $('#mmddFormTitle').text('เพิ่มกฎ Max Dose/Duration');
    $('#mmdd_action').val('add_mmdd');
    $('#mmdd_rule_id').val('');
    $('#mmdd_drug').val('').trigger('change');
    $('#mmdd_max_dose').val('');
    $('#mmdd_max_days').val('0');
    $('#mmdd_alert_level').val('hard');
    $('#mmdd_alert_message').val('');
    $('#mmdd_detail_text').val('');
    $('#mmdd_active').prop('checked', true);
    $('.mmdd-rule-row').removeClass('mmdd-editing-row');
}

function setMmddEditMode(rule) {
    $('.mmdd-rule-row').removeClass('mmdd-editing-row');
    $('tr.mmdd-rule-row[data-rule-id="' + rule.id + '"]').addClass('mmdd-editing-row');
    $('#mmddFormCard').addClass('edit-mode');
    $('#mmddFormTitle').text('แก้ไขกฎ: ' + (rule.drug_name || '') + ' (ID: ' + rule.id + ')');
    $('#mmdd_action').val('edit_mmdd');
    $('#mmdd_rule_id').val(rule.id);
    const drugVal = (rule.icode || '') + '|' + (rule.drug_name || '');
    ensureMmddSelectOption($('#mmdd_drug'), drugVal, (rule.drug_name || '') + ' (icode: ' + rule.icode + ')');
    $('#mmdd_drug').val(drugVal).trigger('change');
    $('#mmdd_max_dose').val(rule.max_dose_mg_per_day || '');
    $('#mmdd_max_days').val(rule.max_duration_days || 0);
    $('#mmdd_alert_level').val(rule.alert_level === 'soft' ? 'soft' : 'hard');
    $('#mmdd_alert_message').val(rule.alert_message || '');
    $('#mmdd_detail_text').val(rule.detail_text || '');
    $('#mmdd_active').prop('checked', !!rule.active);
    document.getElementById('mmddFormCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
}
</script>
</body>
</html>
