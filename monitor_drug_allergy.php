<?php
session_start();
if (!isset($_SESSION['user_id']) && !isset($_SESSION['full_name'])) {
    header('Location: login.php');
    exit;
}
include('connect.php');
include('monitor_drug_allergy_helper.php');
mysqli_set_charset($con, 'utf8');

mda_ensure_tables($con);
mda_ensure_config($con);

$default_start = date('Y-m-d', strtotime('-3 days'));
$default_end = date('Y-m-d');

function mda_esc($con, $v) {
    return mysqli_real_escape_string($con, $v ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_mda_config') {
        mda_save_config($con, [
            'mda_cross_group_enabled' => isset($_POST['mda_cross_group_enabled']) ? '1' : '0',
            'mda_pharmacology_group_enabled' => isset($_POST['mda_pharmacology_group_enabled']) ? '1' : '0',
            'mda_direct_alert_level' => $_POST['mda_direct_alert_level'] ?? 'hard',
            'mda_cross_alert_level' => $_POST['mda_cross_alert_level'] ?? 'soft',
            'mda_match_min_token' => $_POST['mda_match_min_token'] ?? '4',
            'mda_exclude_hns' => $_POST['mda_exclude_hns'] ?? '',
        ]);
        $_SESSION['mda_msg'] = 'บันทึกการตั้งค่าเรียบร้อย';
        header('Location: monitor_drug_allergy.php?tab=settings');
        exit;
    }

    if ($action === 'add_cross_rule' || $action === 'edit_cross_rule') {
        $rule_name = trim($_POST['rule_name'] ?? '');
        $allergy_regex = trim($_POST['allergy_regex'] ?? '');
        $rx_regex = trim($_POST['rx_regex'] ?? '');
        $alert_level = ($_POST['alert_level'] ?? '') === 'soft' ? 'soft' : 'hard';
        $active = isset($_POST['active']) ? 1 : 0;

        $chk_a = mda_validate_regex($allergy_regex);
        $chk_r = mda_validate_regex($rx_regex);
        if ($rule_name === '') {
            $_SESSION['mda_error'] = 'กรุณาระบุชื่อกฎ';
        } elseif (!$chk_a['ok']) {
            $_SESSION['mda_error'] = 'Allergy REGEXP: ' . $chk_a['error'];
        } elseif (!$chk_r['ok']) {
            $_SESSION['mda_error'] = 'Rx REGEXP: ' . $chk_r['error'];
        } else {
            $name_esc = mda_esc($con, $rule_name);
            $a_esc = mda_esc($con, $allergy_regex);
            $r_esc = mda_esc($con, $rx_regex);
            $lvl_esc = mda_esc($con, $alert_level);
            if ($action === 'add_cross_rule') {
                $ok = mysqli_query($con, "INSERT INTO monitor_drug_allergy_cross_rules
                    (rule_name, allergy_regex, rx_regex, alert_level, active)
                    VALUES ('$name_esc', '$a_esc', '$r_esc', '$lvl_esc', $active)");
            } else {
                $id = (int)($_POST['rule_id'] ?? 0);
                $ok = $id > 0 && mysqli_query($con, "UPDATE monitor_drug_allergy_cross_rules SET
                    rule_name='$name_esc', allergy_regex='$a_esc', rx_regex='$r_esc',
                    alert_level='$lvl_esc', active=$active WHERE id=$id");
            }
            $_SESSION['mda_msg'] = $ok ? 'บันทึกกฎข้ามกลุ่มเรียบร้อย' : ('บันทึกไม่สำเร็จ: ' . mysqli_error($con));
        }
        header('Location: monitor_drug_allergy.php?tab=settings');
        exit;
    }

    if ($action === 'delete_cross_rule') {
        $id = (int)($_POST['del_id'] ?? 0);
        if ($id > 0) {
            mysqli_query($con, "DELETE FROM monitor_drug_allergy_cross_rules WHERE id=$id");
            $_SESSION['mda_msg'] = 'ลบกฎข้ามกลุ่มเรียบร้อย';
        }
        header('Location: monitor_drug_allergy.php?tab=settings');
        exit;
    }
}

$cfg = mda_load_config($con);
$cross_rules = mda_load_cross_rules($con, false);
$tab = (isset($_GET['tab']) && $_GET['tab'] === 'settings') ? 'settings' : 'monitor';
$flash_msg = $_SESSION['mda_msg'] ?? '';
$flash_err = $_SESSION['mda_error'] ?? '';
unset($_SESSION['mda_msg'], $_SESSION['mda_error']);
session_write_close();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Monitor Drug Allergy - THAM SAFE</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', Tahoma, sans-serif; background-color: #f4f7f6; }
        .filter-box { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; }
        .section-hard .table thead { background-color: #dc3545 !important; color: #fff !important; }
        .section-soft .table thead { background-color: #fd7e14 !important; color: #fff !important; }
        .mda-range-btn.active { background-color: #e11d48 !important; border-color: #e11d48 !important; color: #fff !important; }
        #mdaCrossFormCard.edit-mode .card-header { background-color: #e11d48 !important; }
        tr.mda-editing-row { background-color: #fff1f2 !important; }
        .tmt-label { color: #0d9488; font-weight: 600; }
    </style>
</head>
<body>
<div class="wrapper">
    <?php include('sidebar.php'); ?>
    <div id="content" class="content">
        <div class="container-fluid py-4">
            <h4 class="fw-bold mb-2 text-dark">
                <i class="fas fa-allergies me-2 text-danger"></i>
                Monitor Drug Allergy (แพ้ยา)
            </h4>
            <p class="text-muted small mb-4">
                ตรวจสอบการสั่งยาที่อาจแพ้ข้ามกลุ่ม — ใช้ <span class="tmt-label">ชื่อจาก TMT</span> (active ingredient) เปรียบเทียบกับประวัติแพ้ใน HOSxP
            </p>

            <?php if ($flash_msg): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo htmlspecialchars($flash_msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>
            <?php if ($flash_err): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?php echo htmlspecialchars($flash_err); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>

            <ul class="nav nav-tabs fw-bold">
                <li class="nav-item">
                    <button class="nav-link <?php echo $tab === 'monitor' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#monitor" type="button">
                        <i class="fas fa-desktop me-1"></i> รายการเฝ้าระวัง
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link <?php echo $tab === 'settings' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#settings" type="button">
                        <i class="fas fa-cogs me-1"></i> ตั้งค่า / กฎข้ามกลุ่ม
                    </button>
                </li>
            </ul>

            <div class="tab-content bg-white border border-top-0 p-4 rounded-bottom shadow-sm">
                <div class="tab-pane fade <?php echo $tab === 'monitor' ? 'show active' : ''; ?>" id="monitor">
                    <div class="filter-box p-3 mb-3">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-2">
                                <label class="form-label small fw-bold mb-1">วันที่สั่งยา ตั้งแต่</label>
                                <input type="date" id="filter_start" class="form-control form-control-sm" value="<?php echo htmlspecialchars($default_start); ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-bold mb-1">ถึงวันที่</label>
                                <input type="date" id="filter_end" class="form-control form-control-sm" value="<?php echo htmlspecialchars($default_end); ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-bold mb-1">สั่งยาล่าสุด (นาที)</label>
                                <input type="number" id="recent_minutes" class="form-control form-control-sm" min="0" max="1440" value="60">
                                <div class="form-text" style="font-size:0.7rem;">0 = ทั้งช่วงวันที่</div>
                            </div>
                            <div class="col-md-2">
                                <button type="button" class="btn btn-sm fw-bold text-white w-100" style="background:#e11d48;" id="btnFetchMDA" onclick="fetchMDAData()">
                                    <i class="fas fa-sync-alt me-1"></i> ประมวลผล
                                </button>
                            </div>
                        </div>
                        <div class="row g-2 mt-2 align-items-center">
                            <div class="col-auto"><span class="small fw-bold text-secondary">ช่วงเร็ว:</span></div>
                            <div class="col">
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-secondary fw-bold mda-range-btn" onclick="setMDADateRange(1)">วันนี้</button>
                                    <button type="button" class="btn btn-outline-secondary fw-bold mda-range-btn active" onclick="setMDADateRange(3)">3 วัน</button>
                                    <button type="button" class="btn btn-outline-secondary fw-bold mda-range-btn" onclick="setMDADateRange(7)">7 วัน</button>
                                </div>
                            </div>
                        </div>
                        <div class="small text-muted mt-2">
                            <i class="fas fa-info-circle me-1"></i>
                            จับคู่ตรงด้วยชื่อ <b>TMT</b> |
                            ข้ามกลุ่ม: <?php echo ($cfg['mda_cross_group_enabled'] ?? '1') === '1' ? '<span class="text-success">เปิด</span>' : '<span class="text-danger">ปิด</span>'; ?>
                            | กลุ่มเภสัชวิทยา: <?php echo ($cfg['mda_pharmacology_group_enabled'] ?? '1') === '1' ? '<span class="text-success">เปิด</span>' : '<span class="text-danger">ปิด</span>'; ?>
                            <br><span class="text-warning"><i class="fas fa-bolt me-1"></i>แนะนำ: ใช้「สั่งยาล่าสุด 60 นาที」สำหรับหน้างาน — ช่วง 7 วันเต็มจะดึงใบสั่งล่าสุด 3,000 รายการก่อนตรวจ</span>
                        </div>
                        <div id="reportMeta" class="alert alert-info py-2 px-3 mt-2 mb-0 small fw-bold d-none"></div>
                    </div>

                    <div class="section-hard mb-4">
                        <h6 class="fw-bold text-danger mb-2"><i class="fas fa-exclamation-circle me-2"></i>Hard Alert <span id="hardCount" class="badge bg-danger">0</span></h6>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle border mb-0">
                                <thead class="text-center small">
                                    <tr>
                                        <th>HN</th><th>OPD/IPD</th><th class="text-start">ชื่อ-สกุล</th>
                                        <th>วันที่สั่ง</th><th class="text-start">ยาที่สั่ง (TMT)</th>
                                        <th class="text-start">ประวัติแพ้</th><th>วิธีจับคู่</th>
                                        <th class="text-start">อาการแพ้ / รายละเอียด</th>
                                    </tr>
                                </thead>
                                <tbody id="hardTbody"><tr><td colspan="8" class="text-center py-4 text-muted">กดปุ่มประมวลผลเพื่อดึงข้อมูล</td></tr></tbody>
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
                                        <th>วันที่สั่ง</th><th class="text-start">ยาที่สั่ง (TMT)</th>
                                        <th class="text-start">ประวัติแพ้</th><th>วิธีจับคู่</th>
                                        <th class="text-start">อาการแพ้ / รายละเอียด</th>
                                    </tr>
                                </thead>
                                <tbody id="softTbody"><tr><td colspan="8" class="text-center py-4 text-muted">—</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade <?php echo $tab === 'settings' ? 'show active' : ''; ?>" id="settings">
                    <div class="row g-4">
                        <div class="col-lg-5">
                            <div class="card shadow-sm border-0 mb-3">
                                <div class="card-header text-white fw-bold" style="background:#be123c;">
                                    <i class="fas fa-sliders-h me-1"></i> ตั้งค่าระบบตรวจแพ้ยา
                                </div>
                                <div class="card-body">
                                    <form method="post">
                                        <input type="hidden" name="action" value="save_mda_config">
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" name="mda_cross_group_enabled" id="mda_cross_group_enabled"
                                                <?php echo ($cfg['mda_cross_group_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>>
                                            <label class="form-check-label small fw-bold" for="mda_cross_group_enabled">เปิดตรวจแพ้ยาข้ามกลุ่ม (กฎ REGEXP ที่ตั้ง)</label>
                                        </div>
                                        <div class="form-check mb-3">
                                            <input class="form-check-input" type="checkbox" name="mda_pharmacology_group_enabled" id="mda_pharmacology_group_enabled"
                                                <?php echo ($cfg['mda_pharmacology_group_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>>
                                            <label class="form-check-label small fw-bold" for="mda_pharmacology_group_enabled">เปิดตรวจข้ามกลุ่มเภสัชวิทยา HOSxP (pharmacology_group)</label>
                                        </div>
                                        <div class="row g-2 mb-2">
                                            <div class="col-6">
                                                <label class="form-label small fw-bold">Alert จับคู่ตรง (TMT)</label>
                                                <select name="mda_direct_alert_level" class="form-select form-select-sm">
                                                    <option value="hard" <?php echo ($cfg['mda_direct_alert_level'] ?? 'hard') === 'hard' ? 'selected' : ''; ?>>Hard</option>
                                                    <option value="soft" <?php echo ($cfg['mda_direct_alert_level'] ?? '') === 'soft' ? 'selected' : ''; ?>>Soft</option>
                                                </select>
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label small fw-bold">Alert ข้ามกลุ่ม (ค่าเริ่มต้น)</label>
                                                <select name="mda_cross_alert_level" class="form-select form-select-sm">
                                                    <option value="hard" <?php echo ($cfg['mda_cross_alert_level'] ?? '') === 'hard' ? 'selected' : ''; ?>>Hard</option>
                                                    <option value="soft" <?php echo ($cfg['mda_cross_alert_level'] ?? 'soft') === 'soft' ? 'selected' : ''; ?>>Soft</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">ความยาวคำขั้นต่ำสำหรับจับคู่ TMT</label>
                                            <input type="number" name="mda_match_min_token" class="form-control form-control-sm" min="3" max="12"
                                                value="<?php echo htmlspecialchars($cfg['mda_match_min_token'] ?? '4'); ?>">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold">ยกเว้น HN (คั่นด้วย comma)</label>
                                            <input type="text" name="mda_exclude_hns" class="form-control form-control-sm"
                                                value="<?php echo htmlspecialchars($cfg['mda_exclude_hns'] ?? ''); ?>" placeholder="เช่น 1234567,7654321">
                                        </div>
                                        <button type="submit" class="btn btn-sm text-white fw-bold" style="background:#be123c;">
                                            <i class="fas fa-save me-1"></i> บันทึกตั้งค่า
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <div class="card shadow-sm border-0" id="mdaCrossFormCard">
                                <div class="card-header text-white fw-bold" style="background:#e11d48;">
                                    <i class="fas fa-plus-circle me-1"></i> <span id="mdaCrossFormTitle">เพิ่มกฎข้ามกลุ่ม</span>
                                </div>
                                <div class="card-body">
                                    <form method="post" id="mdaCrossForm">
                                        <input type="hidden" name="action" id="mda_cross_action" value="add_cross_rule">
                                        <input type="hidden" name="rule_id" id="mda_cross_rule_id" value="">
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">ชื่อกฎ</label>
                                            <input type="text" name="rule_name" id="mda_rule_name" class="form-control form-control-sm" required placeholder="เช่น Penicillin → Amoxicillin">
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">แพ้ยา (REGEXP จับ opd_allergy.agent)</label>
                                            <input type="text" name="allergy_regex" id="mda_allergy_regex" class="form-control form-control-sm font-monospace" required
                                                placeholder="penicillin|เพนิซิลลิน">
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">ยาที่สั่ง (REGEXP จับชื่อ TMT / active ingredient)</label>
                                            <input type="text" name="rx_regex" id="mda_rx_regex" class="form-control form-control-sm font-monospace" required
                                                placeholder="amoxicillin|ampicillin">
                                            <div class="form-text">ระบบใช้ชื่อจาก <b>TMT</b> เป็นหลักในการตรวจยาที่สั่ง</div>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">ระดับแจ้งเตือน</label>
                                            <select name="alert_level" id="mda_cross_alert_level" class="form-select form-select-sm">
                                                <option value="hard">Hard Alert</option>
                                                <option value="soft">Soft Alert</option>
                                            </select>
                                        </div>
                                        <div class="form-check mb-3">
                                            <input class="form-check-input" type="checkbox" name="active" id="mda_cross_active" checked>
                                            <label class="form-check-label small" for="mda_cross_active">เปิดใช้งาน</label>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <button type="submit" class="btn btn-sm text-white fw-bold" style="background:#e11d48;"><i class="fas fa-save me-1"></i> บันทึกกฎ</button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="resetMDACrossForm()">ล้างฟอร์ม</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-7">
                            <h6 class="fw-bold text-secondary mb-3"><i class="fas fa-random me-1"></i>กฎแพ้ยาข้ามกลุ่ม</h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover border align-middle">
                                    <thead class="table-light text-center small">
                                        <tr><th>ชื่อกฎ</th><th>แพ้ยา</th><th>ยาที่สั่ง (TMT)</th><th>Alert</th><th>เปิด</th><th>จัดการ</th></tr>
                                    </thead>
                                    <tbody>
                                    <?php if (empty($cross_rules)): ?>
                                        <tr><td colspan="6" class="text-center text-muted py-3">ยังไม่มีกฎ — รัน upgrade.php หรือเพิ่มกฎทางซ้าย</td></tr>
                                    <?php else: foreach ($cross_rules as $r):
                                        $rule_json = htmlspecialchars(json_encode([
                                            'id' => (int)$r['id'],
                                            'rule_name' => $r['rule_name'],
                                            'allergy_regex' => $r['allergy_regex'],
                                            'rx_regex' => $r['rx_regex'],
                                            'alert_level' => $r['alert_level'],
                                            'active' => (int)!empty($r['active']),
                                        ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                                    ?>
                                        <tr class="mda-cross-row" data-rule-id="<?php echo (int)$r['id']; ?>">
                                            <td class="fw-bold"><?php echo htmlspecialchars($r['rule_name']); ?></td>
                                            <td class="small font-monospace"><?php echo htmlspecialchars(mb_substr($r['allergy_regex'], 0, 40)); ?></td>
                                            <td class="small font-monospace"><?php echo htmlspecialchars(mb_substr($r['rx_regex'], 0, 40)); ?></td>
                                            <td class="text-center"><?php echo mda_alert_badge_html(($r['alert_level'] ?? '') === 'soft' ? 'soft' : 'hard'); ?></td>
                                            <td class="text-center"><?php echo !empty($r['active']) ? '<i class="fas fa-check text-success"></i>' : '<i class="fas fa-times text-muted"></i>'; ?></td>
                                            <td class="text-center text-nowrap">
                                                <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2 mda-btn-edit" title="แก้ไข" data-rule="<?php echo $rule_json; ?>"><i class="fas fa-edit"></i></button>
                                                <form method="post" class="d-inline" onsubmit="return confirm('ลบกฎนี้?');">
                                                    <input type="hidden" name="action" value="delete_cross_rule">
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
<script>
function mdaEsc(s) {
    if (s == null) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

$(function() {
    $(document).on('click', '.mda-btn-edit', function() {
        let rule = $(this).data('rule');
        if (typeof rule === 'string') {
            try { rule = JSON.parse(rule); } catch (e) { return; }
        }
        if (!rule || !rule.id) return;
        $('.mda-cross-row').removeClass('mda-editing-row');
        $('tr.mda-cross-row[data-rule-id="' + rule.id + '"]').addClass('mda-editing-row');
        $('#mdaCrossFormCard').addClass('edit-mode');
        $('#mdaCrossFormTitle').text('แก้ไขกฎ: ' + (rule.rule_name || ''));
        $('#mda_cross_action').val('edit_cross_rule');
        $('#mda_cross_rule_id').val(rule.id);
        $('#mda_rule_name').val(rule.rule_name || '');
        $('#mda_allergy_regex').val(rule.allergy_regex || '');
        $('#mda_rx_regex').val(rule.rx_regex || '');
        $('#mda_cross_alert_level').val(rule.alert_level === 'soft' ? 'soft' : 'hard');
        $('#mda_cross_active').prop('checked', !!rule.active);
        document.getElementById('mdaCrossFormCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
});

function setMDADateRange(days) {
    const end = new Date();
    const start = new Date();
    if (days <= 1) start.setHours(0,0,0,0); else start.setDate(end.getDate() - (days - 1));
    const fmt = d => d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
    $('#filter_start').val(fmt(start));
    $('#filter_end').val(fmt(end));
    $('.mda-range-btn').removeClass('active');
}

function renderMDARow(item) {
    const when = item.order_time ? item.order_date + ' ' + mdaEsc(item.order_time) : mdaEsc(item.order_date);
    const tmt = item.drug_name_tmt || item.active_ingredient || item.drug_name_local || '-';
    const allergyInfo = '<b class="text-danger">' + mdaEsc(item.allergy_agent) + '</b>'
        + (item.allergy_report_date ? '<br><small class="text-muted">บันทึก: ' + mdaEsc(item.allergy_report_date) + '</small>' : '');
    const symptom = (item.allergy_symptom ? mdaEsc(item.allergy_symptom) : '-')
        + (item.detail_text ? '<div class="text-muted small mt-1">' + mdaEsc(item.detail_text) + '</div>' : '');
    return '<tr>'
        + '<td class="text-center fw-bold">' + mdaEsc(item.hn) + '</td>'
        + '<td class="text-center"><span class="badge ' + (item.type_patient === 'IPD' ? 'bg-secondary' : 'bg-info text-dark') + '">' + mdaEsc(item.type_patient) + '</span></td>'
        + '<td>' + mdaEsc(item.ptname) + '</td>'
        + '<td class="text-center small">' + when + '</td>'
        + '<td class="text-start"><span class="tmt-label">' + mdaEsc(tmt) + '</span>'
        + (item.drug_name_local && item.drug_name_local !== tmt ? '<br><small class="text-muted">HOSxP: ' + mdaEsc(item.drug_name_local) + '</small>' : '')
        + '<br><small class="text-muted">icode: ' + mdaEsc(item.icode) + '</small></td>'
        + '<td class="text-start small">' + allergyInfo + '</td>'
        + '<td class="text-center"><span class="badge bg-light text-dark border">' + mdaEsc(item.match_type_label || item.match_type) + '</span></td>'
        + '<td class="text-start small">' + symptom + '</td>'
        + '</tr>';
}

function fetchMDAData() {
    const qs = new URLSearchParams({
        start_date: $('#filter_start').val(),
        end_date: $('#filter_end').val(),
        recent_minutes: $('#recent_minutes').val() || '0'
    });
    $('#btnFetchMDA').prop('disabled', true);
    $('#hardTbody').html('<tr><td colspan="8" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i>กำลังประมวลผล...</td></tr>');
    $('#softTbody').html('<tr><td colspan="8" class="text-center py-4 text-muted">—</td></tr>');

    fetch('fetch_monitor_drug_allergy_api.php?' + qs.toString(), { credentials: 'same-origin' })
    .then(async r => {
        const text = await r.text();
        try { return JSON.parse(text); } catch (e) { throw new Error(text ? text.substring(0, 300) : ('HTTP ' + r.status)); }
    })
    .then(data => {
        $('#btnFetchMDA').prop('disabled', false);
        if (data.status !== 'success') {
            $('#hardTbody').html('<tr><td colspan="8" class="text-center text-danger py-4">' + mdaEsc(data.message || 'เกิดข้อผิดพลาด') + '</td></tr>');
            return;
        }
        const meta = data.meta || {};
        const hard = data.hard_alerts || [];
        const soft = data.soft_alerts || [];
        $('#hardCount').text(meta.hard_count || hard.length);
        $('#softCount').text(meta.soft_count || soft.length);
        let metaHtml = '<i class="fas fa-info-circle me-1"></i> Hard: ' + (meta.hard_count || 0) + ' | Soft: ' + (meta.soft_count || 0)
            + ' | สแกนใบสั่ง: ' + (meta.rx_scanned || 0)
            + ' | ผู้ป่วยมีประวัติแพ้: ' + (meta.patients_with_allergy || 0);
        if (meta.recent_minutes > 0) metaHtml += ' | ล่าสุด ' + meta.recent_minutes + ' นาที';
        if (meta.elapsed_ms) metaHtml += ' | ' + (meta.elapsed_ms / 1000).toFixed(1) + ' วินาที';
        $('#reportMeta').removeClass('d-none').html(metaHtml);

        $('#hardTbody').html(hard.length ? hard.map(renderMDARow).join('') : '<tr><td colspan="8" class="text-center py-4 text-success fw-bold"><i class="fas fa-check-circle me-2"></i>ไม่พบ Hard Alert</td></tr>');
        $('#softTbody').html(soft.length ? soft.map(renderMDARow).join('') : '<tr><td colspan="8" class="text-center py-4 text-muted">ไม่พบ Soft Alert</td></tr>');
    })
    .catch(err => {
        $('#btnFetchMDA').prop('disabled', false);
        $('#hardTbody').html('<tr><td colspan="8" class="text-center text-danger py-4">' + mdaEsc(err.message || 'เชื่อมต่อ API ไม่สำเร็จ') + '</td></tr>');
    });
}

function resetMDACrossForm() {
    $('#mdaCrossFormCard').removeClass('edit-mode');
    $('#mdaCrossFormTitle').text('เพิ่มกฎข้ามกลุ่ม');
    $('#mda_cross_action').val('add_cross_rule');
    $('#mda_cross_rule_id').val('');
    $('#mdaCrossForm')[0].reset();
    $('#mda_cross_active').prop('checked', true);
    $('.mda-cross-row').removeClass('mda-editing-row');
}
</script>
</body>
</html>
