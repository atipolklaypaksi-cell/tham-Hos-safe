<?php
session_start();
if (!isset($_SESSION['user_id']) && !isset($_SESSION['full_name'])) {
    header('Location: login.php');
    exit;
}
include('connect.php');
include('hosxp_tmt_drug_helper.php');
include('monitor_drug_usage_helper.php');
mysqli_set_charset($con, 'utf8');

mdu_ensure_table($con);

$default_start = date('Y-m-d', strtotime('-3 days'));
$default_end = date('Y-m-d');
$default_regex = mdu_default_required_regex();

function mdu_escape($con, $v) {
    return mysqli_real_escape_string($con, $v ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_mdu' || $action === 'edit_mdu') {
        $drug = hosxp_parse_drug_option($_POST['drug'] ?? '');
        $icode = mdu_escape($con, $drug['icode']);
        $drug_name = mdu_escape($con, $drug['name']);
        $required_regex = trim($_POST['required_regex'] ?? '');
        $regex_check = mdu_validate_regex($required_regex);
        $alert_level = ($_POST['alert_level'] ?? '') === 'soft' ? 'soft' : 'hard';
        $alert_message = mdu_escape($con, $_POST['alert_message'] ?? '');
        $detail_text = mdu_escape($con, $_POST['detail_text'] ?? '');
        $active = isset($_POST['active']) ? 1 : 0;
        $regex_esc = mdu_escape($con, $required_regex);
        $lvl_esc = mdu_escape($con, $alert_level);

        if ($icode === '') {
            $_SESSION['mdu_error'] = 'กรุณาเลือกยาจาก HOSxP';
            header('Location: monitor_drug_usage.php?tab=settings');
            exit;
        }
        if (!$regex_check['ok']) {
            $_SESSION['mdu_error'] = $regex_check['error'];
            header('Location: monitor_drug_usage.php?tab=settings');
            exit;
        }

        if ($action === 'add_mdu') {
            $chk = mysqli_query($con, "SELECT id FROM monitor_drug_usage_settings WHERE icode='$icode' LIMIT 1");
            if ($chk && mysqli_num_rows($chk) > 0) {
                $_SESSION['mdu_error'] = 'มีกฎสำหรับ icode นี้แล้ว — ใช้แก้ไขแทน';
            } else {
                $sql = "INSERT INTO monitor_drug_usage_settings
                    (icode, drug_name, required_regex, alert_level, alert_message, detail_text, active)
                    VALUES ('$icode', '$drug_name', '$regex_esc', '$lvl_esc', '$alert_message', '$detail_text', $active)";
                if (mysqli_query($con, $sql)) {
                    $_SESSION['mdu_msg'] = 'เพิ่มกฎตรวจวิธีใช้เรียบร้อย';
                } else {
                    $_SESSION['mdu_error'] = 'บันทึกไม่สำเร็จ: ' . mysqli_error($con);
                }
            }
        } else {
            $id = (int)($_POST['rule_id'] ?? 0);
            if ($id <= 0) {
                $_SESSION['mdu_error'] = 'ไม่พบรายการที่จะแก้ไข';
            } else {
                $sql = "UPDATE monitor_drug_usage_settings SET
                    icode='$icode', drug_name='$drug_name', required_regex='$regex_esc',
                    alert_level='$lvl_esc', alert_message='$alert_message', detail_text='$detail_text', active=$active
                    WHERE id=$id";
                if (mysqli_query($con, $sql)) {
                    $_SESSION['mdu_msg'] = 'บันทึกการแก้ไขเรียบร้อย';
                } else {
                    $_SESSION['mdu_error'] = 'บันทึกไม่สำเร็จ: ' . mysqli_error($con);
                }
            }
        }
        header('Location: monitor_drug_usage.php?tab=settings');
        exit;
    }

    if ($action === 'delete_mdu') {
        $id = (int)($_POST['del_id'] ?? 0);
        if ($id > 0) {
            mysqli_query($con, "DELETE FROM monitor_drug_usage_settings WHERE id=$id");
            $_SESSION['mdu_msg'] = 'ลบกฎเรียบร้อย';
        }
        header('Location: monitor_drug_usage.php?tab=settings');
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
$q_rules = mysqli_query($con, "SELECT * FROM monitor_drug_usage_settings ORDER BY drug_name ASC, id ASC");
if ($q_rules) {
    while ($r = mysqli_fetch_assoc($q_rules)) {
        $all_rules[] = $r;
        if (!empty($r['active'])) {
            $monitor_rules[] = $r;
        }
    }
}

$tab = (isset($_GET['tab']) && $_GET['tab'] === 'settings') ? 'settings' : 'monitor';
$flash_msg = $_SESSION['mdu_msg'] ?? '';
$flash_err = $_SESSION['mdu_error'] ?? '';
unset($_SESSION['mdu_msg'], $_SESSION['mdu_error']);
session_write_close();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Monitor วิธีใช้ยา - THAM SAFE</title>
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
        .mdu-range-btn.active { background-color: #0d9488 !important; border-color: #0d9488 !important; color: #fff !important; }
        .method-bad { background: #fff3cd; border-left: 3px solid #dc3545; padding: 4px 8px; border-radius: 4px; }
        #mduFormCard.edit-mode .card-header { background-color: #0d9488 !important; }
        tr.mdu-editing-row { background-color: #e6fffa !important; }
    </style>
</head>
<body>
<div class="wrapper">
    <?php include('sidebar.php'); ?>
    <div id="content" class="content">
        <div class="container-fluid py-4">
            <h4 class="fw-bold mb-2 text-dark">
                <i class="fas fa-prescription-bottle-alt me-2" style="color:#0d9488;"></i>
                Monitor วิธีใช้ยา (Drug Usage Instruction)
            </h4>
            <p class="text-muted small mb-4">
                ตรวจสอบว่าวิธีใช้มีคำกำหนดที่จำเป็น เช่น Methotrexate ต้องระบุวันรับประทานหรือสัปดาห์ละครั้ง
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
                        <i class="fas fa-cogs me-1"></i> ตั้งค่ากฎวิธีใช้
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
                                <label class="form-label small fw-bold mb-1">สั่งยาล่าสุด (นาที)</label>
                                <input type="number" id="recent_minutes" class="form-control form-control-sm" min="0" max="1440" value="60" placeholder="0 = ทั้งช่วง">
                                <div class="form-text" style="font-size:0.7rem;">60 = แบบ Grafana real-time</div>
                            </div>
                            <div class="col-md-2">
                                <button type="button" class="btn btn-sm fw-bold text-white w-100 shadow-sm" style="background:#0d9488;" id="btnFetchMDU" onclick="fetchMDUData()">
                                    <i class="fas fa-sync-alt me-1"></i> ประมวลผล
                                </button>
                            </div>
                        </div>
                        <div class="row g-2 mt-2 align-items-center">
                            <div class="col-auto"><label class="form-label small fw-bold mb-0 text-secondary">ช่วงเร็ว:</label></div>
                            <div class="col">
                                <div class="btn-group btn-group-sm flex-wrap">
                                    <button type="button" class="btn btn-outline-secondary fw-bold mdu-range-btn" data-days="1" onclick="setMDUDateRange(1)">วันนี้</button>
                                    <button type="button" class="btn btn-outline-secondary fw-bold mdu-range-btn active" data-days="3" onclick="setMDUDateRange(3)">3 วัน</button>
                                    <button type="button" class="btn btn-outline-secondary fw-bold mdu-range-btn" data-days="7" onclick="setMDUDateRange(7)">7 วัน</button>
                                    <button type="button" class="btn btn-outline-secondary fw-bold mdu-range-btn" data-days="30" onclick="setMDUDateRange(30)">30 วัน</button>
                                </div>
                            </div>
                        </div>
                        <div id="reportMeta" class="alert alert-info py-2 px-3 mt-2 mb-0 small fw-bold d-none"></div>
                    </div>

                    <div class="border rounded p-3 mb-3" id="mduRuleFilterBox">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                            <label class="form-label fw-bold small mb-0">
                                <i class="fas fa-filter me-1 text-primary"></i> เลือกยาที่ต้องการรายงาน
                            </label>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-primary fw-bold" onclick="mduSelectAllRules(true)">เลือกทั้งหมด</button>
                                <button type="button" class="btn btn-outline-secondary fw-bold" onclick="mduSelectAllRules(false)">ยกเลิกทั้งหมด</button>
                            </div>
                        </div>
                        <?php if (empty($monitor_rules)): ?>
                        <div class="small text-muted">ยังไม่มีกฎที่เปิดใช้งาน — ไปที่แท็บ <b>ตั้งค่ากฎวิธีใช้</b></div>
                        <?php else: ?>
                        <div class="row g-2">
                            <?php foreach ($monitor_rules as $mr):
                                $lvl = mdu_alert_level($mr);
                                $badge = $lvl === 'hard'
                                    ? '<span class="badge bg-danger ms-1">Hard</span>'
                                    : '<span class="badge bg-warning text-dark ms-1">Soft</span>';
                            ?>
                            <div class="col-md-6 col-xl-4">
                                <div class="form-check border rounded px-2 py-1 bg-white h-100">
                                    <input class="form-check-input mdu-rule-cb" type="checkbox" value="<?php echo (int)$mr['id']; ?>"
                                           id="mdu_rule_cb_<?php echo (int)$mr['id']; ?>" checked>
                                    <label class="form-check-label small" for="mdu_rule_cb_<?php echo (int)$mr['id']; ?>">
                                        <span class="fw-bold text-dark"><?php echo htmlspecialchars($mr['drug_name']); ?></span>
                                        <small class="text-muted">(<?php echo htmlspecialchars($mr['icode']); ?>)</small>
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
                                        <th class="text-start">วิธีใช้ (ที่พบ)</th><th>qty</th>
                                        <th>หอผู้ป่วย</th><th class="text-start">ข้อความแจ้งเตือน</th>
                                    </tr>
                                </thead>
                                <tbody id="hardTbody"><tr><td colspan="9" class="text-center py-4 text-muted">กดปุ่มประมวลผลเพื่อดึงข้อมูล</td></tr></tbody>
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
                                        <th class="text-start">วิธีใช้ (ที่พบ)</th><th>qty</th>
                                        <th>หอผู้ป่วย</th><th class="text-start">ข้อความแจ้งเตือน</th>
                                    </tr>
                                </thead>
                                <tbody id="softTbody"><tr><td colspan="9" class="text-center py-4 text-muted">—</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade <?php echo $tab === 'settings' ? 'show active' : ''; ?>" id="settings">
                    <div class="row g-4">
                        <div class="col-lg-5">
                            <div class="card shadow-sm border-0" id="mduFormCard">
                                <div class="card-header text-white fw-bold" style="background:#0d9488;">
                                    <i class="fas fa-plus-circle me-1"></i> <span id="mduFormTitle">เพิ่มกฎตรวจวิธีใช้</span>
                                </div>
                                <div class="card-body">
                                    <form method="post" id="mduForm">
                                        <input type="hidden" name="action" id="mdu_action" value="add_mdu">
                                        <input type="hidden" name="rule_id" id="mdu_rule_id" value="">
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">ยา (HOSxP)</label>
                                            <select name="drug" id="mdu_drug" class="form-select form-select-sm" required><?php echo $opt_drugs; ?></select>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">คำที่ต้องมีในวิธีใช้ (REGEXP)</label>
                                            <textarea name="required_regex" id="mdu_regex" class="form-control form-control-sm font-monospace" rows="2" required><?php echo htmlspecialchars($default_regex); ?></textarea>
                                            <div class="form-text">Alert เมื่อวิธีใช้ <b>ไม่มี</b>คำตามรูปแบบนี้ — ค่าเริ่มต้นเหมาะกับ Methotrexate (วัน/สัปดาห์)</div>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">ระดับแจ้งเตือน</label>
                                            <select name="alert_level" id="mdu_alert_level" class="form-select form-select-sm">
                                                <option value="hard">Hard Alert</option>
                                                <option value="soft">Soft Alert</option>
                                            </select>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">ข้อความแจ้งเตือน</label>
                                            <input type="text" name="alert_message" id="mdu_alert_message" class="form-control form-control-sm"
                                                placeholder="เช่น วิธีใช้ไม่ระบุวัน/สัปดาห์ — กรุณาตรวจสอบ">
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">รายละเอียดกฎ (สำหรับเภสัช)</label>
                                            <textarea name="detail_text" id="mdu_detail_text" class="form-control form-control-sm" rows="2"
                                                placeholder="อธิบายเหตุผล clinical"></textarea>
                                        </div>
                                        <div class="form-check mb-3">
                                            <input class="form-check-input" type="checkbox" name="active" id="mdu_active" checked>
                                            <label class="form-check-label small" for="mdu_active">เปิดใช้งาน</label>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <button type="submit" class="btn btn-sm text-white fw-bold" style="background:#0d9488;">
                                                <i class="fas fa-save me-1"></i> บันทึก
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" id="mduBtnReset" onclick="resetMDUForm()">ล้างฟอร์ม</button>
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
                                            <th>ยา</th><th>icode</th><th>REGEXP</th><th>Alert</th><th>เปิด</th><th>จัดการ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php if (empty($all_rules)): ?>
                                        <tr><td colspan="6" class="text-center text-muted py-3">ยังไม่มีกฎ — เพิ่ม Methotrexate หรือยาอื่นได้ทางซ้าย</td></tr>
                                    <?php else: foreach ($all_rules as $r):
                                        $rule_json = htmlspecialchars(json_encode([
                                            'id' => (int)$r['id'],
                                            'icode' => $r['icode'],
                                            'drug_name' => $r['drug_name'],
                                            'required_regex' => $r['required_regex'],
                                            'alert_level' => $r['alert_level'],
                                            'alert_message' => $r['alert_message'] ?? '',
                                            'detail_text' => $r['detail_text'] ?? '',
                                            'active' => (int)!empty($r['active']),
                                        ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                                    ?>
                                        <tr class="mdu-rule-row" data-rule-id="<?php echo (int)$r['id']; ?>">
                                            <td class="fw-bold"><?php echo htmlspecialchars($r['drug_name']); ?></td>
                                            <td class="text-center"><code><?php echo htmlspecialchars($r['icode']); ?></code></td>
                                            <td class="small font-monospace" style="max-width:180px;word-break:break-all;"><?php echo htmlspecialchars(mb_substr($r['required_regex'], 0, 60)); ?><?php echo mb_strlen($r['required_regex']) > 60 ? '…' : ''; ?></td>
                                            <td class="text-center"><?php echo mdu_alert_badge_html(mdu_alert_level($r)); ?></td>
                                            <td class="text-center"><?php echo !empty($r['active']) ? '<i class="fas fa-check text-success"></i>' : '<i class="fas fa-times text-muted"></i>'; ?></td>
                                            <td class="text-center text-nowrap">
                                                <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2 mdu-btn-edit" title="แก้ไข" data-rule="<?php echo $rule_json; ?>"><i class="fas fa-edit"></i></button>
                                                <form method="post" class="d-inline" onsubmit="return confirm('ลบกฎนี้?');">
                                                    <input type="hidden" name="action" value="delete_mdu">
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
const MDU_HAS_RULES = <?php echo !empty($monitor_rules) ? 'true' : 'false'; ?>;
const MDU_DEFAULT_REGEX = <?php echo json_encode($default_regex, JSON_UNESCAPED_UNICODE); ?>;
let fpStart, fpEnd;

function mduEsc(s) {
    if (s == null) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function ensureMduSelectOption($sel, value, label) {
    if (!$sel.find('option').filter(function() { return $(this).val() === value; }).length) {
        $sel.append(new Option(label, value, false, false));
    }
}

$(function() {
    $('#mdu_drug').select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'ค้นหายา...' });
    if (typeof flatpickr !== 'undefined') {
        fpStart = flatpickr('#filter_start', { dateFormat: 'Y-m-d' });
        fpEnd = flatpickr('#filter_end', { dateFormat: 'Y-m-d' });
    }
    $(document).on('click', '.mdu-btn-edit', function() {
        let rule = $(this).data('rule');
        if (typeof rule === 'string') {
            try { rule = JSON.parse(rule); } catch (e) { return; }
        }
        if (!rule || !rule.id) return;
        setMduEditMode(rule);
    });
});

function getSelectedMduRuleIds() {
    const ids = [];
    $('.mdu-rule-cb:checked').each(function() { ids.push($(this).val()); });
    return ids;
}

function mduSelectAllRules(on) {
    $('.mdu-rule-cb').prop('checked', !!on);
}

function setMDUDateRange(days) {
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
    $('.mdu-range-btn').removeClass('active');
    $('.mdu-range-btn[data-days="' + days + '"]').addClass('active');
}

function renderMDURow(item) {
    const when = item.order_time
        ? item.order_date + ' <span class="text-dark">' + mduEsc(item.order_time) + '</span>'
        : mduEsc(item.order_date);
    const methodHtml = item.method
        ? '<div class="method-bad small">' + mduEsc(item.method) + '</div>'
        : '<span class="text-danger fw-bold small">(ว่าง)</span>';
    let detail = '';
    if (item.detail_text) {
        detail = '<div class="text-muted small mt-1">' + mduEsc(item.detail_text) + '</div>';
    }
    return '<tr>'
        + '<td class="text-center fw-bold">' + mduEsc(item.hn) + '</td>'
        + '<td class="text-center"><span class="badge ' + (item.type_patient === 'IPD' ? 'bg-secondary' : 'bg-info text-dark') + '">' + mduEsc(item.type_patient) + '</span></td>'
        + '<td>' + mduEsc(item.ptname) + '</td>'
        + '<td class="text-center small">' + when + '</td>'
        + '<td class="text-start"><b>' + mduEsc(item.drug_name) + '</b><br><small class="text-muted">icode: ' + mduEsc(item.icode) + '</small></td>'
        + '<td class="text-start">' + methodHtml + '</td>'
        + '<td class="text-center">' + mduEsc(item.qty) + '</td>'
        + '<td class="text-center small">' + mduEsc(item.ward_display || '-') + '</td>'
        + '<td class="text-start small text-danger fw-bold">' + mduEsc(item.alert_message) + detail + '</td>'
        + '</tr>';
}

function fetchMDUData() {
    const ruleIds = getSelectedMduRuleIds();
    if (ruleIds.length === 0) {
        alert('กรุณาเลือกอย่างน้อย 1 กฎ');
        return;
    }
    const qs = new URLSearchParams({
        start_date: $('#filter_start').val(),
        end_date: $('#filter_end').val(),
        recent_minutes: $('#recent_minutes').val() || '0',
        rule_ids: ruleIds.join(',')
    });
    $('#btnFetchMDU').prop('disabled', true);
    $('#hardTbody').html('<tr><td colspan="9" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i>กำลังประมวลผล...</td></tr>');
    $('#softTbody').html('<tr><td colspan="9" class="text-center py-4 text-muted">—</td></tr>');

    fetch('fetch_monitor_drug_usage_api.php?' + qs.toString(), { credentials: 'same-origin' })
    .then(async r => {
        const text = await r.text();
        try { return JSON.parse(text); } catch (e) { throw new Error(text ? text.substring(0, 300) : ('HTTP ' + r.status)); }
    })
    .then(data => {
        $('#btnFetchMDU').prop('disabled', false);
        if (data.status !== 'success') {
            $('#hardTbody').html('<tr><td colspan="9" class="text-center text-danger py-4">' + mduEsc(data.message || 'เกิดข้อผิดพลาด') + '</td></tr>');
            return;
        }
        const meta = data.meta || {};
        const hard = data.hard_alerts || [];
        const soft = data.soft_alerts || [];
        $('#hardCount').text(meta.hard_count || hard.length);
        $('#softCount').text(meta.soft_count || soft.length);
        let metaHtml = '<i class="fas fa-info-circle me-1"></i> Hard: ' + (meta.hard_count || 0) + ' | Soft: ' + (meta.soft_count || 0);
        if (meta.recent_minutes > 0) metaHtml += ' | ล่าสุด ' + meta.recent_minutes + ' นาที';
        if (meta.elapsed_ms) metaHtml += ' | ' + (meta.elapsed_ms / 1000).toFixed(1) + ' วินาที';
        $('#reportMeta').removeClass('d-none').html(metaHtml);

        if (hard.length === 0) {
            $('#hardTbody').html('<tr><td colspan="9" class="text-center py-4 text-success fw-bold"><i class="fas fa-check-circle me-2"></i>ไม่พบ Hard Alert</td></tr>');
        } else {
            $('#hardTbody').html(hard.map(renderMDURow).join(''));
        }
        if (soft.length === 0) {
            $('#softTbody').html('<tr><td colspan="9" class="text-center py-4 text-muted">ไม่พบ Soft Alert</td></tr>');
        } else {
            $('#softTbody').html(soft.map(renderMDURow).join(''));
        }
    })
    .catch(err => {
        $('#btnFetchMDU').prop('disabled', false);
        $('#hardTbody').html('<tr><td colspan="9" class="text-center text-danger py-4">' + mduEsc(err.message || 'เชื่อมต่อ API ไม่สำเร็จ') + '</td></tr>');
    });
}

function resetMDUForm() {
    $('#mduFormCard').removeClass('edit-mode');
    $('#mduFormTitle').text('เพิ่มกฎตรวจวิธีใช้');
    $('#mdu_action').val('add_mdu');
    $('#mdu_rule_id').val('');
    $('#mdu_drug').val('').trigger('change');
    $('#mdu_regex').val(MDU_DEFAULT_REGEX);
    $('#mdu_alert_level').val('hard');
    $('#mdu_alert_message').val('');
    $('#mdu_detail_text').val('');
    $('#mdu_active').prop('checked', true);
    $('.mdu-rule-row').removeClass('mdu-editing-row');
}

function setMduEditMode(rule) {
    $('.mdu-rule-row').removeClass('mdu-editing-row');
    $('tr.mdu-rule-row[data-rule-id="' + rule.id + '"]').addClass('mdu-editing-row');
    $('#mduFormCard').addClass('edit-mode');
    $('#mduFormTitle').text('แก้ไขกฎ: ' + (rule.drug_name || '') + ' (ID: ' + rule.id + ')');
    $('#mdu_action').val('edit_mdu');
    $('#mdu_rule_id').val(rule.id);
    const drugVal = (rule.icode || '') + '|' + (rule.drug_name || '');
    ensureMduSelectOption($('#mdu_drug'), drugVal, (rule.drug_name || '') + ' (icode: ' + rule.icode + ')');
    $('#mdu_drug').val(drugVal).trigger('change');
    $('#mdu_regex').val(rule.required_regex || MDU_DEFAULT_REGEX);
    $('#mdu_alert_level').val(rule.alert_level === 'soft' ? 'soft' : 'hard');
    $('#mdu_alert_message').val(rule.alert_message || '');
    $('#mdu_detail_text').val(rule.detail_text || '');
    $('#mdu_active').prop('checked', !!rule.active);
    document.getElementById('mduFormCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
}
</script>
</body>
</html>
