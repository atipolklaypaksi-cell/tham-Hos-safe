<?php
session_start();
if (!isset($_SESSION['user_id']) && !isset($_SESSION['full_name'])) {
    header('Location: login.php');
    exit;
}
include('connect.php');
include('hosxp_tmt_drug_helper.php');
include('monitor_opd_order_qty_helper.php');
mysqli_set_charset($con, 'utf8');

moq_upgrade_schema($con);
moq_method_ensure_table($con);
moq_method_seed_defaults($con);

$default_start = date('Y-m-d', strtotime('-3 days'));
$default_end = date('Y-m-d');
$default_excess = moq_default_excess_pills();
$default_shortage = moq_default_shortage_pills();

function moq_escape($con, $v) {
    return mysqli_real_escape_string($con, $v ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_moq' || $action === 'edit_moq') {
        $rule_type = moq_rule_type($_POST['rule_type'] ?? 'drug');
        if ($rule_type === 'continuous') {
            $icode = moq_continuous_rule_icode();
            $drug_name = moq_continuous_rule_label();
        } else {
            $drug = hosxp_parse_drug_option($_POST['drug'] ?? '');
            $icode = trim($drug['icode'] ?? '');
            $drug_name = trim($drug['name'] ?? '');
        }
        $icode_esc = moq_escape($con, $icode);
        $drug_name_esc = moq_escape($con, $drug_name);
        $rule_type_esc = moq_escape($con, $rule_type);
        $excess = max(0, (int)($_POST['excess_pills_allowed'] ?? $default_excess));
        if ($excess <= 0) {
            $excess = $default_excess;
        }
        $shortage = max(0, (int)($_POST['shortage_pills_allowed'] ?? $default_shortage));
        $lvl_short = ($_POST['alert_level_shortage'] ?? '') === 'soft' ? 'soft' : 'hard';
        $lvl_excess = ($_POST['alert_level_excess'] ?? '') === 'hard' ? 'hard' : 'soft';
        $msg_short = moq_escape($con, $_POST['alert_message_shortage'] ?? '');
        $msg_excess = moq_escape($con, $_POST['alert_message_excess'] ?? '');
        $detail_text = moq_escape($con, $_POST['detail_text'] ?? '');
        $exclude_raw = $_POST['exclude_icodes'] ?? [];
        if (!is_array($exclude_raw)) {
            $exclude_raw = ($exclude_raw !== '') ? [$exclude_raw] : [];
        }
        $exclude_json = moq_escape($con, $rule_type === 'continuous' ? moq_encode_exclude_icodes($exclude_raw) : '[]');
        $active = isset($_POST['active']) ? 1 : 0;

        if ($rule_type === 'drug' && $icode === '') {
            $_SESSION['moq_error'] = 'กรุณาเลือกยาจาก HOSxP';
            header('Location: monitor_opd_order_qty.php?tab=settings');
            exit;
        }

        if ($action === 'add_moq') {
            $chk_sql = $rule_type === 'continuous'
                ? "SELECT id FROM monitor_opd_order_qty_settings WHERE rule_type='continuous' LIMIT 1"
                : "SELECT id FROM monitor_opd_order_qty_settings WHERE rule_type='drug' AND icode='$icode_esc' LIMIT 1";
            $chk = mysqli_query($con, $chk_sql);
            if ($chk && mysqli_num_rows($chk) > 0) {
                $_SESSION['moq_error'] = $rule_type === 'continuous'
                    ? 'มีกฎกลุ่มยาใช้ต่อเนื่องแล้ว — ใช้แก้ไขแทน'
                    : 'มีกฎสำหรับ icode นี้แล้ว — ใช้แก้ไขแทน';
            } else {
                $sql = "INSERT INTO monitor_opd_order_qty_settings
                    (rule_type, icode, drug_name, excess_pills_allowed, shortage_pills_allowed, alert_level_shortage, alert_level_excess,
                     alert_message_shortage, alert_message_excess, detail_text, exclude_icodes, active)
                    VALUES ('$rule_type_esc','$icode_esc','$drug_name_esc',$excess,$shortage,'$lvl_short','$lvl_excess','$msg_short','$msg_excess','$detail_text','$exclude_json',$active)";
                if (mysqli_query($con, $sql)) {
                    $_SESSION['moq_msg'] = $rule_type === 'continuous'
                        ? 'เพิ่มกฎกลุ่มยาใช้ต่อเนื่องเรียบร้อย'
                        : 'เพิ่มกฎ Monitor จำนวนสั่งยาเรียบร้อย';
                } else {
                    $_SESSION['moq_error'] = 'บันทึกไม่สำเร็จ: ' . mysqli_error($con);
                }
            }
        } else {
            $id = (int)($_POST['rule_id'] ?? 0);
            if ($id <= 0) {
                $_SESSION['moq_error'] = 'ไม่พบรายการที่จะแก้ไข';
            } else {
                $sql = "UPDATE monitor_opd_order_qty_settings SET
                    rule_type='$rule_type_esc', icode='$icode_esc', drug_name='$drug_name_esc', excess_pills_allowed=$excess,
                    shortage_pills_allowed=$shortage,
                    alert_level_shortage='$lvl_short', alert_level_excess='$lvl_excess',
                    alert_message_shortage='$msg_short', alert_message_excess='$msg_excess',
                    detail_text='$detail_text', exclude_icodes='$exclude_json', active=$active
                    WHERE id=$id";
                if (mysqli_query($con, $sql)) {
                    $_SESSION['moq_msg'] = 'บันทึกการแก้ไขเรียบร้อย';
                } else {
                    $_SESSION['moq_error'] = 'บันทึกไม่สำเร็จ: ' . mysqli_error($con);
                }
            }
        }
        header('Location: monitor_opd_order_qty.php?tab=settings');
        exit;
    }

    if ($action === 'scan_method_rules') {
        $hos = hosxp_connect($con);
        $scan = moq_method_scan_from_hosxp($hos, $con);
        if ($hos) {
            mysqli_close($hos);
        }
        if (($scan['message'] ?? '') === 'ok') {
            $_SESSION['moq_msg'] = 'สแกนฐานข้อมูลแล้ว — เพิ่มนิยามใหม่ ' . (int)($scan['inserted'] ?? 0) . ' รายการ';
        } else {
            $_SESSION['moq_error'] = 'สแกนไม่สำเร็จ: ' . ($scan['message'] ?? 'unknown');
        }
        header('Location: monitor_opd_order_qty.php?tab=method');
        exit;
    }

    if ($action === 'add_method_rule' || $action === 'edit_method_rule') {
        $mode = trim($_POST['rule_mode'] ?? 'skip');
        if (!in_array($mode, ['skip', 'daily', 'per_week', 'weekdays'], true)) {
            $mode = 'skip';
        }
        $match_pat = moq_escape($con, $_POST['match_pattern'] ?? '');
        $match_type = ($_POST['match_type'] ?? '') === 'regex' ? 'regex' : 'contains';
        $label = moq_escape($con, $_POST['label'] ?? '');
        $tpd = trim($_POST['times_per_day'] ?? '');
        $tpw = trim($_POST['times_per_week'] ?? '');
        $upd = trim($_POST['units_per_dose'] ?? '');
        $tpd_sql = ($tpd !== '' && is_numeric($tpd)) ? (float)$tpd : 'NULL';
        $tpw_sql = ($tpw !== '' && is_numeric($tpw)) ? (float)$tpw : 'NULL';
        $upd_sql = ($upd !== '' && is_numeric($upd)) ? (float)$upd : 'NULL';
        $priority = max(1, min(999, (int)($_POST['priority'] ?? 50)));
        $sample = moq_escape($con, $_POST['sample_method'] ?? '');
        $active = isset($_POST['active']) ? 1 : 0;
        $weekdays = [];
        if (!empty($_POST['weekdays']) && is_array($_POST['weekdays'])) {
            foreach ($_POST['weekdays'] as $d) {
                $d = (int)$d;
                if ($d >= 1 && $d <= 7) {
                    $weekdays[] = $d;
                }
            }
        }
        $wj = moq_escape($con, json_encode(array_values(array_unique($weekdays)), JSON_UNESCAPED_UNICODE));
        $mode_e = moq_escape($con, $mode);

        if ($match_pat === '') {
            $_SESSION['moq_error'] = 'กรุณาระบุคำ/รูปแบบที่ใช้จับคู่';
            header('Location: monitor_opd_order_qty.php?tab=method');
            exit;
        }

        if ($action === 'add_method_rule') {
            $sql = "INSERT INTO monitor_opd_order_qty_method_rules
                (rule_mode, match_pattern, match_type, label, times_per_day, times_per_week, weekdays_json, units_per_dose, priority, sample_method, source, active)
                VALUES ('$mode_e','$match_pat','$match_type','$label',$tpd_sql,$tpw_sql,'$wj',$upd_sql,$priority,'$sample','manual',$active)";
            $_SESSION['moq_msg'] = mysqli_query($con, $sql) ? 'เพิ่มนิยามวิธีใช้เรียบร้อย' : 'บันทึกไม่สำเร็จ: ' . mysqli_error($con);
        } else {
            $id = (int)($_POST['method_rule_id'] ?? 0);
            if ($id <= 0) {
                $_SESSION['moq_error'] = 'ไม่พบรายการที่จะแก้ไข';
            } else {
                $sql = "UPDATE monitor_opd_order_qty_method_rules SET
                    rule_mode='$mode_e', match_pattern='$match_pat', match_type='$match_type', label='$label',
                    times_per_day=$tpd_sql, times_per_week=$tpw_sql, weekdays_json='$wj', units_per_dose=$upd_sql,
                    priority=$priority, sample_method='$sample', active=$active
                    WHERE id=$id";
                $_SESSION['moq_msg'] = mysqli_query($con, $sql) ? 'บันทึกการแก้ไขเรียบร้อย' : 'บันทึกไม่สำเร็จ: ' . mysqli_error($con);
            }
        }
        header('Location: monitor_opd_order_qty.php?tab=method');
        exit;
    }

    if ($action === 'delete_method_rule') {
        $id = (int)($_POST['del_method_id'] ?? 0);
        if ($id > 0) {
            mysqli_query($con, "DELETE FROM monitor_opd_order_qty_method_rules WHERE id=$id");
            $_SESSION['moq_msg'] = 'ลบนิยามเรียบร้อย';
        }
        header('Location: monitor_opd_order_qty.php?tab=method');
        exit;
    }

    if ($action === 'delete_moq') {
        $id = (int)($_POST['del_id'] ?? 0);
        if ($id > 0) {
            mysqli_query($con, "DELETE FROM monitor_opd_order_qty_settings WHERE id=$id");
            $_SESSION['moq_msg'] = 'ลบกฎเรียบร้อย';
        }
        header('Location: monitor_opd_order_qty.php?tab=settings');
        exit;
    }
}

$con_hosxp = hosxp_connect($con);
$hosxp_drugs = $con_hosxp ? hosxp_fetch_active_drugs_tmt($con_hosxp) : [];
$continuous_drugs = $con_hosxp ? moq_fetch_continuous_drugs($con_hosxp) : [];
$continuous_drug_count = count($continuous_drugs);
$opt_continuous_exclude = moq_build_continuous_drug_options_html($continuous_drugs);
if ($con_hosxp) {
    mysqli_close($con_hosxp);
}
$opt_drugs = hosxp_build_drug_options_html($hosxp_drugs, '- เลือกยาจาก HOSxP (TMT) -');

$all_rules = [];
$monitor_rules = [];
$q_rules = mysqli_query($con, "SELECT * FROM monitor_opd_order_qty_settings ORDER BY drug_name ASC, id ASC");
if ($q_rules) {
    while ($r = mysqli_fetch_assoc($q_rules)) {
        $all_rules[] = $r;
        if (!empty($r['active'])) {
            $monitor_rules[] = $r;
        }
    }
}

$tab = 'monitor';
if (isset($_GET['tab'])) {
    if ($_GET['tab'] === 'settings') {
        $tab = 'settings';
    } elseif ($_GET['tab'] === 'method') {
        $tab = 'method';
    }
}

$method_rules = moq_method_load_rules($con, false);
$flash_msg = $_SESSION['moq_msg'] ?? '';
$flash_err = $_SESSION['moq_error'] ?? '';
unset($_SESSION['moq_msg'], $_SESSION['moq_error']);
session_write_close();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Monitor จำนวนสั่งยา OPD - THAM SAFE</title>
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
        .moq-range-btn.active { background-color: #0891b2 !important; border-color: #0891b2 !important; color: #fff !important; }
        .badge-shortage { background: #dc3545; }
        .badge-excess { background: #fd7e14; color: #212529; }
        #moqFormCard.edit-mode .card-header { background-color: #0e7490 !important; }
        tr.moq-editing-row { background-color: #ecfeff !important; }
    </style>
</head>
<body>
<div class="wrapper">
    <?php include('sidebar.php'); ?>
    <div id="content" class="content">
        <div class="container-fluid py-4">
            <h4 class="fw-bold mb-2 text-dark">
                <i class="fas fa-calculator me-2" style="color:#0891b2;"></i>
                Monitor จำนวนสั่งยา <span class="badge bg-info text-dark">OPD เท่านั้น</span>
            </h4>
            <p class="text-muted small mb-4">
                ตรวจว่าจำนวนยาที่สั่ง <b>พอถึงวันนัดครั้งต่อไป</b> (จากตารางนัด oapp) หรือไม่ —
                <span class="text-danger">ไม่ยอมให้ขาด</span> |
                ยาเกินจนถึงวันนัดได้ไม่เกิน <b>10 หน่วย</b> (ปรับได้ต่อกฎ)
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
                        <i class="fas fa-cogs me-1"></i> ตั้งค่ากฎ
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link <?php echo $tab === 'method' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#method" type="button">
                        <i class="fas fa-book-medical me-1"></i> นิยามวิธีใช้
                    </button>
                </li>
            </ul>

            <div class="tab-content bg-white border border-top-0 p-4 rounded-bottom shadow-sm">
                <div class="tab-pane fade <?php echo $tab === 'monitor' ? 'show active' : ''; ?>" id="monitor">
                    <div class="filter-box p-3 mb-3">
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
                                <button type="button" class="btn btn-sm fw-bold text-white w-100 shadow-sm" style="background:#0891b2;" id="btnFetchMOQ" onclick="fetchMOQData()">
                                    <i class="fas fa-sync-alt me-1"></i> ประมวลผล
                                </button>
                            </div>
                        </div>
                        <div class="row g-2 mt-2 align-items-center">
                            <div class="col-auto"><label class="form-label small fw-bold mb-0 text-secondary">ช่วงเร็ว:</label></div>
                            <div class="col">
                                <div class="btn-group btn-group-sm flex-wrap">
                                    <button type="button" class="btn btn-outline-secondary fw-bold moq-range-btn" data-days="1" onclick="setMOQDateRange(1)">วันนี้</button>
                                    <button type="button" class="btn btn-outline-secondary fw-bold moq-range-btn active" data-days="3" onclick="setMOQDateRange(3)">3 วัน</button>
                                    <button type="button" class="btn btn-outline-secondary fw-bold moq-range-btn" data-days="7" onclick="setMOQDateRange(7)">7 วัน</button>
                                    <button type="button" class="btn btn-outline-secondary fw-bold moq-range-btn" data-days="30" onclick="setMOQDateRange(30)">30 วัน</button>
                                </div>
                            </div>
                        </div>
                        <div id="reportMeta" class="alert alert-info py-2 px-3 mt-2 mb-0 small fw-bold d-none"></div>
                    </div>

                    <div class="border rounded p-3 mb-3">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                            <label class="form-label fw-bold small mb-0"><i class="fas fa-filter me-1 text-primary"></i> เลือกยาที่ต้องการรายงาน</label>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-primary fw-bold" onclick="moqSelectAllRules(true)">เลือกทั้งหมด</button>
                                <button type="button" class="btn btn-outline-secondary fw-bold" onclick="moqSelectAllRules(false)">ยกเลิกทั้งหมด</button>
                            </div>
                        </div>
                        <?php if (empty($monitor_rules)): ?>
                        <div class="small text-muted">ยังไม่มีกฎ — ไปที่แท็บตั้งค่าเพื่อเลือกยาที่ต้องการ Monitor</div>
                        <?php else: ?>
                        <div class="row g-2">
                            <?php foreach ($monitor_rules as $mr):
                                $mr_exclude_n = moq_is_continuous_rule($mr) ? count(moq_parse_exclude_icodes($mr['exclude_icodes'] ?? '')) : 0;
                                $mr_active_n = moq_is_continuous_rule($mr) ? max(0, $continuous_drug_count - $mr_exclude_n) : 0;
                            ?>
                            <div class="col-md-6 col-xl-4">
                                <div class="form-check border rounded px-2 py-1 bg-white h-100">
                                    <input class="form-check-input moq-rule-cb" type="checkbox" value="<?php echo (int)$mr['id']; ?>" id="moq_rule_cb_<?php echo (int)$mr['id']; ?>" checked>
                                    <label class="form-check-label small" for="moq_rule_cb_<?php echo (int)$mr['id']; ?>">
                                        <?php if (moq_is_continuous_rule($mr)): ?>
                                        <span class="badge bg-secondary">กลุ่ม</span>
                                        <span class="fw-bold"><?php echo htmlspecialchars($mr['drug_name']); ?></span>
                                        <small class="text-muted">(<?php echo (int)$mr_active_n; ?> รายการ<?php if ($mr_exclude_n > 0): ?>, ยกเว้น <?php echo (int)$mr_exclude_n; ?><?php endif; ?>)</small>
                                        <?php else: ?>
                                        <span class="fw-bold"><?php echo htmlspecialchars($mr['drug_name']); ?></span>
                                        <small class="text-muted">(<?php echo htmlspecialchars($mr['icode']); ?>)</small>
                                        <?php endif; ?>
                                        <br><small class="text-primary">เกินได้ +<?php echo (int)($mr['excess_pills_allowed'] ?? $default_excess); ?> · ขาดได้ -<?php echo (int)($mr['shortage_pills_allowed'] ?? $default_shortage); ?></small>
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
                                        <th>ประเภท</th><th>HN</th><th class="text-start">ชื่อ-สกุล</th><th>วันที่สั่ง</th>
                                        <th class="text-start">ยา</th><th>qty</th><th class="text-start">วิธีใช้</th>
                                        <th>ยาพอ(วัน)</th><th>ต้องการ(วัน)</th><th>วันนัด</th><th class="text-start">ข้อความ</th>
                                    </tr>
                                </thead>
                                <tbody id="hardTbody"><tr><td colspan="11" class="text-center py-4 text-muted">กดปุ่มประมวลผล</td></tr></tbody>
                            </table>
                        </div>
                    </div>

                    <div class="section-soft mb-2">
                        <h6 class="fw-bold text-warning mb-2"><i class="fas fa-exclamation-triangle me-2"></i>Soft Alert <span id="softCount" class="badge bg-warning text-dark">0</span></h6>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle border mb-0">
                                <thead class="text-center small">
                                    <tr>
                                        <th>ประเภท</th><th>HN</th><th class="text-start">ชื่อ-สกุล</th><th>วันที่สั่ง</th>
                                        <th class="text-start">ยา</th><th>qty</th><th class="text-start">วิธีใช้</th>
                                        <th>ยาพอ(วัน)</th><th>ต้องการ(วัน)</th><th>วันนัด</th><th class="text-start">ข้อความ</th>
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
                            <div class="card shadow-sm border-0" id="moqFormCard">
                                <div class="card-header text-white fw-bold" style="background:#0891b2;">
                                    <i class="fas fa-plus-circle me-1"></i> <span id="moqFormTitle">เพิ่มกฎ Monitor จำนวนสั่งยา</span>
                                </div>
                                <div class="card-body">
                                    <form method="post" id="moqForm">
                                        <input type="hidden" name="action" id="moq_action" value="add_moq">
                                        <input type="hidden" name="rule_id" id="moq_rule_id" value="">
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">ประเภทกฎ</label>
                                            <div class="d-flex flex-wrap gap-3">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="radio" name="rule_type" id="moq_rule_type_drug" value="drug" checked>
                                                    <label class="form-check-label small" for="moq_rule_type_drug">ยาเดี่ยว</label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="radio" name="rule_type" id="moq_rule_type_continuous" value="continuous">
                                                    <label class="form-check-label small" for="moq_rule_type_continuous">กลุ่มยาใช้ต่อเนื่อง (HOSxP)</label>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="mb-2" id="moq_drug_single_wrap">
                                            <label class="form-label small fw-bold">ยา (HOSxP)</label>
                                            <select name="drug" id="moq_drug" class="form-select form-select-sm"><?php echo $opt_drugs; ?></select>
                                        </div>
                                        <div class="mb-2 d-none" id="moq_drug_group_wrap">
                                            <div class="alert alert-info py-2 px-3 mb-2 small">
                                                <i class="fas fa-layer-group me-1"></i>
                                                ใช้กฎกับยาที่ติ๊ก <b>ยาใช้ต่อเนื่อง</b> ใน HOSxP
                                                (<code>drugitems.continuous = Y</code>)
                                                <div class="mt-1">ปัจจุบันมี <b><?php echo (int)$continuous_drug_count; ?></b> รายการ เช่น simVASTATIN, amlodipine</div>
                                                <div class="text-muted mt-1">ถ้ามียาเดี่ยวที่ตั้งกฎแยกไว้แล้ว ระบบจะใช้กฎยาเดี่ยวก่อน</div>
                                            </div>
                                            <label class="form-label small fw-bold">ยกเว้นรายการ (ไม่ใช้กฎกลุ่ม)</label>
                                            <select name="exclude_icodes[]" id="moq_exclude_drugs" class="form-select form-select-sm" multiple><?php echo $opt_continuous_exclude; ?></select>
                                            <div class="form-text">เลือกยาที่ไม่ต้องการให้กฎกลุ่มครอบคลุม — ค้นหาชื่อยาได้</div>
                                        </div>
                                        <div class="row g-2 mb-2">
                                            <div class="col-6">
                                                <label class="form-label small fw-bold">อนุโลมยาเกิน (หน่วย/เม็ด)</label>
                                                <input type="number" name="excess_pills_allowed" id="moq_excess" class="form-control form-control-sm" min="0" max="999" value="<?php echo (int)$default_excess; ?>">
                                                <div class="form-text">ค่าเริ่มต้น 10</div>
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label small fw-bold">อนุโลมยาขาด (หน่วย/เม็ด)</label>
                                                <input type="number" name="shortage_pills_allowed" id="moq_shortage" class="form-control form-control-sm" min="0" max="999" value="<?php echo (int)$default_shortage; ?>">
                                                <div class="form-text">ค่าเริ่มต้น 0 — ยาขาดได้ไม่เกินค่านี้</div>
                                            </div>
                                        </div>
                                        <div class="row g-2 mb-2">
                                            <div class="col-6">
                                                <label class="form-label small fw-bold">Alert เมื่อยา <u>ไม่พอ</u></label>
                                                <select name="alert_level_shortage" id="moq_lvl_short" class="form-select form-select-sm">
                                                    <option value="hard">Hard</option>
                                                    <option value="soft">Soft</option>
                                                </select>
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label small fw-bold">Alert เมื่อยา <u>เกิน</u></label>
                                                <select name="alert_level_excess" id="moq_lvl_excess" class="form-select form-select-sm">
                                                    <option value="soft">Soft</option>
                                                    <option value="hard">Hard</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">ข้อความแจ้ง (ยาไม่พอ)</label>
                                            <input type="text" name="alert_message_shortage" id="moq_msg_short" class="form-control form-control-sm" placeholder="ว่าง = ข้อความอัตโนมัติ">
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">ข้อความแจ้ง (ยาเกิน)</label>
                                            <input type="text" name="alert_message_excess" id="moq_msg_excess" class="form-control form-control-sm" placeholder="ว่าง = ข้อความอัตโนมัติ">
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">รายละเอียดกฎ</label>
                                            <textarea name="detail_text" id="moq_detail" class="form-control form-control-sm" rows="2"></textarea>
                                        </div>
                                        <div class="form-check mb-3">
                                            <input class="form-check-input" type="checkbox" name="active" id="moq_active" checked>
                                            <label class="form-check-label small" for="moq_active">เปิดใช้งาน</label>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <button type="submit" class="btn btn-sm text-white fw-bold" style="background:#0891b2;"><i class="fas fa-save me-1"></i> บันทึก</button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="resetMOQForm()">ล้างฟอร์ม</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-7">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover border align-middle">
                                    <thead class="table-light text-center small">
                                        <tr><th>ยา / กลุ่ม</th><th>icode</th><th>เกินได้</th><th>ขาดได้</th><th>ไม่พอ</th><th>เกิน</th><th>เปิด</th><th>จัดการ</th></tr>
                                    </thead>
                                    <tbody>
                                    <?php if (empty($all_rules)): ?>
                                        <tr><td colspan="8" class="text-center text-muted py-3">ยังไม่มีกฎ</td></tr>
                                    <?php else: foreach ($all_rules as $r):
                                        $exclude_icodes = moq_parse_exclude_icodes($r['exclude_icodes'] ?? '');
                                        $exclude_n = count($exclude_icodes);
                                        $rule_json = htmlspecialchars(json_encode([
                                            'id' => (int)$r['id'],
                                            'rule_type' => moq_rule_type($r['rule_type'] ?? 'drug'),
                                            'icode' => $r['icode'], 'drug_name' => $r['drug_name'],
                                            'exclude_icodes' => $exclude_icodes,
                                            'excess_pills_allowed' => (int)($r['excess_pills_allowed'] ?? $default_excess),
                                            'shortage_pills_allowed' => (int)($r['shortage_pills_allowed'] ?? $default_shortage),
                                            'alert_level_shortage' => $r['alert_level_shortage'] ?? 'hard',
                                            'alert_level_excess' => $r['alert_level_excess'] ?? 'soft',
                                            'alert_message_shortage' => $r['alert_message_shortage'] ?? '',
                                            'alert_message_excess' => $r['alert_message_excess'] ?? '',
                                            'detail_text' => $r['detail_text'] ?? '',
                                            'active' => (int)!empty($r['active']),
                                        ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                                        $is_group = moq_is_continuous_rule($r);
                                        $active_n = $is_group ? max(0, $continuous_drug_count - $exclude_n) : 0;
                                    ?>
                                        <tr class="moq-rule-row" data-rule-id="<?php echo (int)$r['id']; ?>">
                                            <td class="fw-bold">
                                                <?php if ($is_group): ?><span class="badge bg-secondary me-1">กลุ่ม</span><?php endif; ?>
                                                <?php echo htmlspecialchars($r['drug_name']); ?>
                                                <?php if ($is_group): ?>
                                                <div class="small text-muted fw-normal"><?php echo (int)$active_n; ?> รายการ<?php if ($exclude_n > 0): ?> · ยกเว้น <?php echo (int)$exclude_n; ?><?php endif; ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center"><?php if ($is_group): ?><span class="text-muted">—</span><?php else: ?><code><?php echo htmlspecialchars($r['icode']); ?></code><?php endif; ?></td>
                                            <td class="text-center">+<?php echo (int)($r['excess_pills_allowed'] ?? $default_excess); ?></td>
                                            <td class="text-center">-<?php echo (int)($r['shortage_pills_allowed'] ?? $default_shortage); ?></td>
                                            <td class="text-center"><?php echo moq_alert_badge_html(moq_alert_level($r['alert_level_shortage'] ?? 'hard')); ?></td>
                                            <td class="text-center"><?php echo moq_alert_badge_html(moq_alert_level($r['alert_level_excess'] ?? 'soft')); ?></td>
                                            <td class="text-center"><?php echo !empty($r['active']) ? '<i class="fas fa-check text-success"></i>' : '<i class="fas fa-times text-muted"></i>'; ?></td>
                                            <td class="text-center text-nowrap">
                                                <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2 moq-btn-edit" data-rule="<?php echo $rule_json; ?>"><i class="fas fa-edit"></i></button>
                                                <form method="post" class="d-inline" onsubmit="return confirm('ลบกฎนี้?');">
                                                    <input type="hidden" name="action" value="delete_moq">
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

                <div class="tab-pane fade <?php echo $tab === 'method' ? 'show active' : ''; ?>" id="method">
                    <div class="row g-4">
                        <div class="col-lg-5">
                            <div class="card shadow-sm border-0" id="moqMethodFormCard">
                                <div class="card-header text-white fw-bold" style="background:#0e7490;">
                                    <i class="fas fa-plus-circle me-1"></i> <span id="moqMethodFormTitle">เพิ่มนิยามวิธีใช้</span>
                                </div>
                                <div class="card-body">
                                    <form method="post" id="moqMethodForm">
                                        <input type="hidden" name="action" id="moq_method_action" value="add_method_rule">
                                        <input type="hidden" name="method_rule_id" id="moq_method_rule_id" value="">
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">โหมด</label>
                                            <select name="rule_mode" id="moq_method_mode" class="form-select form-select-sm">
                                                <option value="skip">ข้าม (ไม่แจ้งเตือน)</option>
                                                <option value="daily">วันละ N ครั้ง</option>
                                                <option value="per_week">สัปดาห์ละ N ครั้ง</option>
                                                <option value="weekdays">เฉพาะวันในสัปดาห์</option>
                                            </select>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">จับคู่ข้อความ</label>
                                            <input type="text" name="match_pattern" id="moq_method_pattern" class="form-control form-control-sm" placeholder="เช่น ทันที, สัปดาห์ละ, ทุกวัน" required>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">ประเภทจับคู่</label>
                                            <select name="match_type" id="moq_method_match_type" class="form-select form-select-sm">
                                                <option value="contains">มีคำนี้ในข้อความ</option>
                                                <option value="regex">Regular expression</option>
                                            </select>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">ชื่ออธิบาย</label>
                                            <input type="text" name="label" id="moq_method_label" class="form-control form-control-sm">
                                        </div>
                                        <div class="row g-2 mb-2 moq-method-field" data-modes="daily">
                                            <div class="col-6">
                                                <label class="form-label small fw-bold">ครั้ง/วัน</label>
                                                <input type="number" step="0.01" name="times_per_day" id="moq_method_tpd" class="form-control form-control-sm" placeholder="ว่าง=ดึงจากข้อความ">
                                            </div>
                                        </div>
                                        <div class="row g-2 mb-2 moq-method-field" data-modes="per_week">
                                            <div class="col-6">
                                                <label class="form-label small fw-bold">ครั้ง/สัปดาห์</label>
                                                <input type="number" step="0.01" name="times_per_week" id="moq_method_tpw" class="form-control form-control-sm" placeholder="ว่าง=ดึงจากข้อความ">
                                            </div>
                                        </div>
                                        <div class="mb-2 moq-method-field" data-modes="weekdays">
                                            <label class="form-label small fw-bold">วันในสัปดาห์ (ถ้าว่างจะดึงจากข้อความ)</label>
                                            <div class="d-flex flex-wrap gap-2">
                                                <?php foreach (moq_method_thai_weekday_map() as $name => $num): if ($name === 'พฤหัสบดี') continue; ?>
                                                <div class="form-check form-check-inline mb-0">
                                                    <input class="form-check-input moq-wd-cb" type="checkbox" name="weekdays[]" value="<?php echo (int)$num; ?>" id="moq_wd_<?php echo (int)$num; ?>">
                                                    <label class="form-check-label small" for="moq_wd_<?php echo (int)$num; ?>"><?php echo htmlspecialchars($name); ?></label>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">หน่วย/ครั้ง (ว่าง=ดึงจากข้อความ)</label>
                                            <input type="number" step="0.01" name="units_per_dose" id="moq_method_upd" class="form-control form-control-sm">
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">ลำดับความสำคัญ</label>
                                            <input type="number" name="priority" id="moq_method_priority" class="form-control form-control-sm" value="50" min="1" max="999">
                                            <div class="form-text">ค่ามากตรวจก่อน</div>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">ตัวอย่างวิธีใช้</label>
                                            <input type="text" name="sample_method" id="moq_method_sample" class="form-control form-control-sm">
                                        </div>
                                        <div class="form-check mb-3">
                                            <input class="form-check-input" type="checkbox" name="active" id="moq_method_active" checked>
                                            <label class="form-check-label small" for="moq_method_active">เปิดใช้งาน</label>
                                        </div>
                                        <div class="d-flex gap-2 flex-wrap">
                                            <button type="submit" class="btn btn-sm text-white fw-bold" style="background:#0e7490;"><i class="fas fa-save me-1"></i> บันทึก</button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="resetMoqMethodForm()">ล้างฟอร์ม</button>
                                        </div>
                                    </form>
                                    <form method="post" class="mt-3 pt-3 border-top">
                                        <input type="hidden" name="action" value="scan_method_rules">
                                        <button type="submit" class="btn btn-sm btn-outline-primary fw-bold w-100">
                                            <i class="fas fa-search me-1"></i> สแกน HOSxP และเติมนิยามอัตโนมัติ
                                        </button>
                                        <div class="form-text mt-1">ดึงรูปแบบวิธีใช้ที่พบบ่อย เช่น ทันที, สัปดาห์ละ, ทุกวันX</div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-7">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover border align-middle">
                                    <thead class="table-light text-center small">
                                        <tr><th>โหมด</th><th>จับคู่</th><th>ชื่อ</th><th>ลำดับ</th><th>ที่มา</th><th>เปิด</th><th>จัดการ</th></tr>
                                    </thead>
                                    <tbody>
                                    <?php if (empty($method_rules)): ?>
                                        <tr><td colspan="7" class="text-center text-muted py-3">ยังไม่มีนิยาม — กดสแกนอัตโนมัติหรือเพิ่มเอง</td></tr>
                                    <?php else: foreach ($method_rules as $mr):
                                        $mr_json = htmlspecialchars(json_encode([
                                            'id' => (int)$mr['id'],
                                            'rule_mode' => $mr['rule_mode'],
                                            'match_pattern' => $mr['match_pattern'],
                                            'match_type' => $mr['match_type'],
                                            'label' => $mr['label'],
                                            'times_per_day' => $mr['times_per_day'],
                                            'times_per_week' => $mr['times_per_week'],
                                            'weekdays_json' => $mr['weekdays_json'],
                                            'units_per_dose' => $mr['units_per_dose'],
                                            'priority' => (int)$mr['priority'],
                                            'sample_method' => $mr['sample_method'],
                                            'active' => (int)!empty($mr['active']),
                                        ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                                    ?>
                                        <tr>
                                            <td class="small text-center"><?php echo htmlspecialchars(moq_method_rule_mode_label($mr['rule_mode'])); ?></td>
                                            <td class="small"><code><?php echo htmlspecialchars($mr['match_pattern']); ?></code></td>
                                            <td class="small"><?php echo htmlspecialchars($mr['label'] ?: '-'); ?></td>
                                            <td class="text-center"><?php echo (int)$mr['priority']; ?></td>
                                            <td class="text-center"><span class="badge <?php echo ($mr['source'] ?? '') === 'auto' ? 'bg-secondary' : 'bg-primary'; ?>"><?php echo ($mr['source'] ?? '') === 'auto' ? 'auto' : 'manual'; ?></span></td>
                                            <td class="text-center"><?php echo !empty($mr['active']) ? '<i class="fas fa-check text-success"></i>' : '<i class="fas fa-times text-muted"></i>'; ?></td>
                                            <td class="text-center text-nowrap">
                                                <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2 moq-method-edit" data-rule="<?php echo $mr_json; ?>" title="แก้ไข"><i class="fas fa-edit"></i></button>
                                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 moq-method-copy" data-rule="<?php echo $mr_json; ?>" title="คัดลอก"><i class="fas fa-copy"></i></button>
                                                <form method="post" class="d-inline" onsubmit="return confirm('ลบนิยามนี้?');">
                                                    <input type="hidden" name="action" value="delete_method_rule">
                                                    <input type="hidden" name="del_method_id" value="<?php echo (int)$mr['id']; ?>">
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
const MOQ_DEFAULT_EXCESS = <?php echo (int)$default_excess; ?>;
const MOQ_DEFAULT_SHORTAGE = <?php echo (int)$default_shortage; ?>;
const MOQ_CONTINUOUS_COUNT = <?php echo (int)$continuous_drug_count; ?>;
let fpStart, fpEnd;

function moqToggleRuleType() {
    const isGroup = $('input[name="rule_type"]:checked').val() === 'continuous';
    $('#moq_drug_single_wrap').toggleClass('d-none', isGroup);
    $('#moq_drug_group_wrap').toggleClass('d-none', !isGroup);
    $('#moq_drug').prop('required', !isGroup);
}

function moqEsc(s) {
    if (s == null) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function ensureMoqSelectOption($sel, value, label) {
    if (!$sel.find('option').filter(function() { return $(this).val() === value; }).length) {
        $sel.append(new Option(label, value, false, false));
    }
}

$(function() {
    $('#moq_drug').select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'ค้นหายา...' });
    $('#moq_exclude_drugs').select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: 'เลือกยาที่ต้องการยกเว้น...',
        allowClear: true
    });
    $('input[name="rule_type"]').on('change', moqToggleRuleType);
    moqToggleRuleType();
    if (typeof flatpickr !== 'undefined') {
        fpStart = flatpickr('#filter_start', { dateFormat: 'Y-m-d' });
        fpEnd = flatpickr('#filter_end', { dateFormat: 'Y-m-d' });
    }
    $(document).on('click', '.moq-btn-edit', function() {
        let rule = $(this).data('rule');
        if (typeof rule === 'string') { try { rule = JSON.parse(rule); } catch (e) { return; } }
        if (rule && rule.id) setMoqEditMode(rule);
    });
    $('#moq_method_mode').on('change', moqMethodToggleFields);
    moqMethodToggleFields();
    $(document).on('click', '.moq-method-edit', function() {
        let rule = $(this).data('rule');
        if (typeof rule === 'string') { try { rule = JSON.parse(rule); } catch (e) { return; } }
        if (rule && rule.id) setMoqMethodEditMode(rule);
    });
    $(document).on('click', '.moq-method-copy', function() {
        let rule = $(this).data('rule');
        if (typeof rule === 'string') { try { rule = JSON.parse(rule); } catch (e) { return; } }
        if (rule) setMoqMethodCopyMode(rule);
    });
});

function getSelectedMoqRuleIds() {
    const ids = [];
    $('.moq-rule-cb:checked').each(function() { ids.push($(this).val()); });
    return ids;
}
function moqSelectAllRules(on) { $('.moq-rule-cb').prop('checked', !!on); }

function setMOQDateRange(days) {
    const end = new Date(), start = new Date();
    if (days <= 1) start.setHours(0,0,0,0); else start.setDate(end.getDate() - (days - 1));
    const fmt = d => d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
    if (fpStart) fpStart.setDate(fmt(start), true); else $('#filter_start').val(fmt(start));
    if (fpEnd) fpEnd.setDate(fmt(end), true); else $('#filter_end').val(fmt(end));
    $('.moq-range-btn').removeClass('active');
    $('.moq-range-btn[data-days="' + days + '"]').addClass('active');
}

function renderMOQRow(item) {
    const typeBadge = item.alert_type === 'shortage'
        ? '<span class="badge badge-shortage">ไม่พอ</span>'
        : '<span class="badge badge-excess">เกิน</span>';
    const when = item.order_time ? item.order_date + ' ' + moqEsc(item.order_time) : moqEsc(item.order_date);
    const appt = item.next_appointment + (item.next_appointment_time ? ' ' + moqEsc(item.next_appointment_time) : '');
    let detail = item.detail_text ? '<div class="text-muted small mt-1">' + moqEsc(item.detail_text) + '</div>' : '';
    return '<tr>'
        + '<td class="text-center">' + typeBadge + '</td>'
        + '<td class="text-center fw-bold">' + moqEsc(item.hn) + '</td>'
        + '<td>' + moqEsc(item.ptname) + '</td>'
        + '<td class="text-center small">' + when + '</td>'
        + '<td class="text-start"><b>' + moqEsc(item.drug_name) + '</b><br><small class="text-muted">' + moqEsc(item.icode) + '</small></td>'
        + '<td class="text-center fw-bold">' + moqEsc(item.qty) + '</td>'
        + '<td class="text-start small">' + moqEsc(item.method || '-') + '</td>'
        + '<td class="text-center">' + moqEsc(item.coverage_days) + '</td>'
        + '<td class="text-center">' + moqEsc(item.days_needed) + '</td>'
        + '<td class="text-center small">' + moqEsc(appt) + '</td>'
        + '<td class="text-start small text-danger fw-bold">' + moqEsc(item.alert_message) + detail + '</td>'
        + '</tr>';
}

function fetchMOQData() {
    const ruleIds = getSelectedMoqRuleIds();
    if (!ruleIds.length) { alert('กรุณาเลือกอย่างน้อย 1 กฎ'); return; }
    const qs = new URLSearchParams({ start_date: $('#filter_start').val(), end_date: $('#filter_end').val(), rule_ids: ruleIds.join(',') });
    $('#btnFetchMOQ').prop('disabled', true);
    $('#hardTbody').html('<tr><td colspan="11" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i>กำลังประมวลผล...</td></tr>');
    fetch('fetch_monitor_opd_order_qty_api.php?' + qs, { credentials: 'same-origin' })
    .then(async r => { const t = await r.text(); try { return JSON.parse(t); } catch (e) { throw new Error(t.substring(0,300)); } })
    .then(data => {
        $('#btnFetchMOQ').prop('disabled', false);
        if (data.status !== 'success') {
            $('#hardTbody').html('<tr><td colspan="11" class="text-center text-danger py-4">' + moqEsc(data.message) + '</td></tr>');
            return;
        }
        const meta = data.meta || {}, hard = data.hard_alerts || [], soft = data.soft_alerts || [];
        $('#hardCount').text(meta.hard_count || hard.length);
        $('#softCount').text(meta.soft_count || soft.length);
        let metaHtml = 'Hard: ' + (meta.hard_count||0) + ' | Soft: ' + (meta.soft_count||0);
        if (meta.orders_scanned) metaHtml += ' | สแกน ' + meta.orders_scanned + ' order';
        if (meta.skipped_no_appointment) metaHtml += ' | ไม่มีวันนัด ' + meta.skipped_no_appointment;
        if (meta.skipped_method) metaHtml += ' | ข้ามวิธีใช้ ' + meta.skipped_method;
        if (meta.elapsed_ms) metaHtml += ' | ' + (meta.elapsed_ms/1000).toFixed(1) + ' วินาที';
        $('#reportMeta').removeClass('d-none').html(metaHtml);
        $('#hardTbody').html(hard.length ? hard.map(renderMOQRow).join('') : '<tr><td colspan="11" class="text-center py-4 text-success fw-bold"><i class="fas fa-check-circle me-2"></i>ไม่พบ Hard Alert</td></tr>');
        $('#softTbody').html(soft.length ? soft.map(renderMOQRow).join('') : '<tr><td colspan="11" class="text-center py-4 text-muted">ไม่พบ Soft Alert</td></tr>');
    })
    .catch(err => {
        $('#btnFetchMOQ').prop('disabled', false);
        $('#hardTbody').html('<tr><td colspan="11" class="text-center text-danger py-4">' + moqEsc(err.message) + '</td></tr>');
    });
}

function resetMOQForm() {
    $('#moqFormCard').removeClass('edit-mode');
    $('#moqFormTitle').text('เพิ่มกฎ Monitor จำนวนสั่งยา');
    $('#moq_action').val('add_moq'); $('#moq_rule_id').val('');
    $('#moq_rule_type_drug').prop('checked', true);
    moqToggleRuleType();
    $('#moq_drug').val('').trigger('change');
    $('#moq_exclude_drugs').val(null).trigger('change');
    $('#moq_excess').val(MOQ_DEFAULT_EXCESS);
    $('#moq_shortage').val(MOQ_DEFAULT_SHORTAGE);
    $('#moq_lvl_short').val('hard'); $('#moq_lvl_excess').val('soft');
    $('#moq_msg_short, #moq_msg_excess, #moq_detail').val('');
    $('#moq_active').prop('checked', true);
    $('.moq-rule-row').removeClass('moq-editing-row');
}

function setMoqEditMode(rule) {
    $('.moq-rule-row').removeClass('moq-editing-row');
    $('tr.moq-rule-row[data-rule-id="' + rule.id + '"]').addClass('moq-editing-row');
    $('#moqFormCard').addClass('edit-mode');
    $('#moqFormTitle').text('แก้ไข: ' + (rule.drug_name || ''));
    $('#moq_action').val('edit_moq'); $('#moq_rule_id').val(rule.id);
    if ((rule.rule_type || 'drug') === 'continuous') {
        $('#moq_rule_type_continuous').prop('checked', true);
        const excludes = Array.isArray(rule.exclude_icodes) ? rule.exclude_icodes : [];
        $('#moq_exclude_drugs').val(excludes).trigger('change');
    } else {
        $('#moq_rule_type_drug').prop('checked', true);
        $('#moq_exclude_drugs').val(null).trigger('change');
        const drugVal = (rule.icode||'') + '|' + (rule.drug_name||'');
        ensureMoqSelectOption($('#moq_drug'), drugVal, rule.drug_name + ' (' + rule.icode + ')');
        $('#moq_drug').val(drugVal).trigger('change');
    }
    moqToggleRuleType();
    $('#moq_excess').val(rule.excess_pills_allowed || MOQ_DEFAULT_EXCESS);
    $('#moq_shortage').val(rule.shortage_pills_allowed != null ? rule.shortage_pills_allowed : MOQ_DEFAULT_SHORTAGE);
    $('#moq_lvl_short').val(rule.alert_level_shortage === 'soft' ? 'soft' : 'hard');
    $('#moq_lvl_excess').val(rule.alert_level_excess === 'hard' ? 'hard' : 'soft');
    $('#moq_msg_short').val(rule.alert_message_shortage || '');
    $('#moq_msg_excess').val(rule.alert_message_excess || '');
    $('#moq_detail').val(rule.detail_text || '');
    $('#moq_active').prop('checked', !!rule.active);
    document.getElementById('moqFormCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function moqMethodToggleFields() {
    const mode = $('#moq_method_mode').val();
    $('.moq-method-field').each(function() {
        const modes = ($(this).data('modes') || '').split(',');
        $(this).toggleClass('d-none', modes.indexOf(mode) < 0);
    });
}

function resetMoqMethodForm() {
    $('#moqMethodFormCard').removeClass('edit-mode');
    $('#moqMethodFormTitle').text('เพิ่มนิยามวิธีใช้');
    $('#moq_method_action').val('add_method_rule');
    $('#moq_method_rule_id').val('');
    $('#moq_method_mode').val('skip');
    $('#moq_method_pattern, #moq_method_label, #moq_method_tpd, #moq_method_tpw, #moq_method_upd, #moq_method_sample').val('');
    $('#moq_method_match_type').val('contains');
    $('#moq_method_priority').val(50);
    $('.moq-wd-cb').prop('checked', false);
    $('#moq_method_active').prop('checked', true);
    moqMethodToggleFields();
}

function moqMethodFillForm(rule, titlePrefix) {
    $('#moqMethodFormTitle').text(titlePrefix + ': ' + (rule.label || rule.match_pattern || ''));
    $('#moq_method_mode').val(rule.rule_mode || 'skip');
    $('#moq_method_pattern').val(rule.match_pattern || '');
    $('#moq_method_match_type').val(rule.match_type === 'regex' ? 'regex' : 'contains');
    $('#moq_method_label').val(rule.label || '');
    $('#moq_method_tpd').val(rule.times_per_day || '');
    $('#moq_method_tpw').val(rule.times_per_week || '');
    $('#moq_method_upd').val(rule.units_per_dose || '');
    $('#moq_method_priority').val(rule.priority || 50);
    $('#moq_method_sample').val(rule.sample_method || '');
    $('#moq_method_active').prop('checked', !!rule.active);
    $('.moq-wd-cb').prop('checked', false);
    let wds = [];
    try { wds = JSON.parse(rule.weekdays_json || '[]'); } catch (e) {}
    wds.forEach(function(d) { $('#moq_wd_' + d).prop('checked', true); });
    moqMethodToggleFields();
}

function setMoqMethodEditMode(rule) {
    $('#moqMethodFormCard').addClass('edit-mode');
    $('#moq_method_action').val('edit_method_rule');
    $('#moq_method_rule_id').val(rule.id);
    moqMethodFillForm(rule, 'แก้ไขนิยาม');
    document.getElementById('moqMethodFormCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function setMoqMethodCopyMode(rule) {
    $('#moqMethodFormCard').removeClass('edit-mode');
    $('#moq_method_action').val('add_method_rule');
    $('#moq_method_rule_id').val('');
    moqMethodFillForm(rule, 'คัดลอกนิยาม');
    document.getElementById('moqMethodFormCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
}
</script>
</body>
</html>
