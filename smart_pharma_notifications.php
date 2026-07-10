<?php
session_start();
if (!isset($_SESSION['user_id']) && !isset($_SESSION['full_name'])) {
    header('Location: login.php');
    exit;
}
include('connect.php');
include('smart_pharma_helper.php');
include('smart_pharma_notifications_helper.php');
mysqli_set_charset($con, 'utf8');
sp_ensure_tables($con);

$all_modules = [];
$qmod = mysqli_query($con, "SELECT * FROM smart_pharma_modules ORDER BY sort_order ASC");
if ($qmod) {
    while ($r = mysqli_fetch_assoc($qmod)) {
        $all_modules[] = $r;
    }
}
$cfg = sp_notifications_get_config($con);
session_write_close();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แจ้งเตือน Alert - Smart Pharma</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
    <style>
        :root { --sp-primary: #0d9488; --sp-dark: #0f766e; }
        .btn-sp { background: var(--sp-primary); border-color: var(--sp-primary); color: #fff; }
        .btn-sp:hover { background: var(--sp-dark); border-color: var(--sp-dark); color: #fff; }
        .alert-hero {
            background: linear-gradient(135deg, #1e3a5f 0%, #0d9488 50%, #6366f1 100%);
            color: #fff; border-radius: 12px; padding: 1.25rem 1.5rem;
        }
        .channel-pill { font-size: 0.72rem; padding: 0.15rem 0.45rem; border-radius: 4px; }
        .channel-tg { background: #e3f4fc; color: #0088cc; }
        .channel-wh { background: #ede9fe; color: #4f46e5; }
        .dest-check-grid { max-height: 140px; overflow-y: auto; }
        #rule_module_checks.dest-check-grid { max-height: 180px; }
    </style>
</head>
<body class="bg-light">
<div class="wrapper">
    <?php include('sidebar.php'); ?>
    <div id="content" class="content">
        <div class="container-fluid py-4">
            <div class="alert-hero mb-4">
                <div class="small opacity-75 mb-1"><i class="fas fa-user-shield me-1"></i>Smart Pharma</div>
                <h4 class="fw-bold mb-1"><i class="fas fa-bell me-2"></i>แจ้งเตือน Alert</h4>
                <small>รวม Telegram + Webhook · เลือกช่องทาง · ตั้งระยะ Hard Alert ซ้ำ · กฎเดียวส่งหลายปลายทาง</small>
            </div>

            <!-- 1. ตั้งค่าทั่วไป -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h6 class="fw-bold text-primary border-bottom pb-2 mb-3"><i class="fas fa-sliders-h me-2"></i>การตั้งค่าทั่วไป</h6>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="cfg_tg_enabled" <?= !empty($cfg['telegram_enabled']) ? 'checked' : '' ?>>
                                <label class="form-check-label fw-bold" for="cfg_tg_enabled"><i class="fab fa-telegram-plane text-info me-1"></i>Telegram</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="cfg_wh_enabled" <?= !empty($cfg['webhook_enabled']) ? 'checked' : '' ?>>
                                <label class="form-check-label fw-bold" for="cfg_wh_enabled"><i class="fas fa-plug text-primary me-1"></i>Webhook</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">URL ระบบ (ลิงก์เปิด Intervention Log)</label>
                            <input type="text" id="cfg_base_url" class="form-control form-control-sm font-monospace"
                                value="<?= htmlspecialchars($cfg['base_url'] ?? '') ?>" placeholder="http://61.7.167.197/thamhossafe">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Cron Secret <span class="text-muted fw-normal">(เฉพาะ HTTP Cron)</span></label>
                            <input type="text" id="cfg_cron_secret" class="form-control form-control-sm font-monospace"
                                value="<?= htmlspecialchars($cfg['cron_secret'] ?? '') ?>" placeholder="เว้นว่างได้ถ้าใช้ php CLI">
                        </div>
                        <div class="col-12">
                            <button type="button" class="btn btn-sp btn-sm fw-bold" onclick="saveGlobalConfig()">
                                <i class="fas fa-save me-1"></i>บันทึกการตั้งค่า
                            </button>
                        </div>
                    </div>
                    <div class="border-top pt-3 mt-2">
                        <label class="form-label small fw-bold mb-2"><i class="fas fa-vial me-1"></i>ทดสอบส่ง</label>
                        <div class="row g-2 align-items-end">
                            <div class="col-md-2">
                                <select id="test_channel" class="form-select form-select-sm" onchange="fillTestDestinations()">
                                    <option value="telegram">Telegram</option>
                                    <option value="webhook">Webhook</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select id="test_dest" class="form-select form-select-sm"><option value="">— เลือกปลายทาง —</option></select>
                            </div>
                            <div class="col-md-3">
                                <select id="test_mode" class="form-select form-select-sm">
                                    <option value="simple">Ping สั้น</option>
                                    <option value="sample" selected>ตัวอย่าง Alert จริง (3 รายการ)</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <button type="button" class="btn btn-info btn-sm fw-bold w-100 text-white" id="testSendBtn" onclick="sendTest()">
                                    <i class="fas fa-paper-plane me-1"></i>ส่งทดสอบ
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <ul class="nav nav-tabs mb-3" role="tablist">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabDest" type="button">ปลายทาง (Contact Points)</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabRules" type="button">กฎแจ้งเตือน (Policies)</button></li>
            </ul>

            <div class="tab-content">
                <!-- ปลายทาง -->
                <div class="tab-pane fade show active" id="tabDest">
                    <div class="row g-4">
                        <div class="col-lg-6">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
                                        <h6 class="fw-bold text-info mb-0"><i class="fab fa-telegram-plane me-2"></i>Telegram</h6>
                                        <button type="button" class="btn btn-outline-info btn-sm" onclick="openChatForm()"><i class="fas fa-plus me-1"></i>เพิ่ม</button>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-sm align-middle mb-0">
                                            <thead class="table-light text-center small">
                                                <tr><th>ชื่อ</th><th>Chat ID</th><th>เปิด</th><th></th></tr>
                                            </thead>
                                            <tbody id="tgChatsBody"><tr><td colspan="4" class="text-center text-muted py-3">โหลด...</td></tr></tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
                                        <h6 class="fw-bold text-primary mb-0"><i class="fas fa-plug me-2"></i>Webhook</h6>
                                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="openEndpointForm()"><i class="fas fa-plus me-1"></i>เพิ่ม</button>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-sm align-middle mb-0">
                                            <thead class="table-light text-center small">
                                                <tr><th>ชื่อ</th><th>URL</th><th>เปิด</th><th></th></tr>
                                            </thead>
                                            <tbody id="whEndpointsBody"><tr><td colspan="4" class="text-center text-muted py-3">โหลด...</td></tr></tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- กฎ -->
                <div class="tab-pane fade" id="tabRules">
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
                                <h6 class="fw-bold text-primary mb-0"><i class="fas fa-route me-2"></i>กฎแจ้งเตือน</h6>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="openRuleForm()"><i class="fas fa-plus me-1"></i>เพิ่มกฎ</button>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-bordered table-sm align-middle mb-0">
                                    <thead class="table-light text-center small">
                                        <tr>
                                            <th>ปลายทาง</th><th>OPD/IPD</th><th>โมดูล</th>
                                            <th>Hard</th><th>Soft</th><th>Hard ซ้ำ</th><th>Soft ซ้ำ</th><th>Hard หยุด</th><th>Soft หยุด</th><th>ลิงก์</th><th>เปิด</th><th></th>
                                        </tr>
                                    </thead>
                                    <tbody id="unifiedRulesBody"><tr><td colspan="12" class="text-center text-muted py-3">โหลด...</td></tr></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="alert alert-info small mb-0 mt-3">
                <i class="fas fa-info-circle me-1"></i>
                กฎเดียวส่งได้หลายช่องทาง · เลือก OPD/IPD และโมดูลได้หลายรายการเมื่อใช้การตั้งค่าเดียวกัน
                · <strong>ชนกัน:</strong> ปลายทาง + OPD/IPD + โมดูลเดียวกัน เก็บได้แค่ 1 ชุด — บันทึกทีหลังจะแทนที่ของเดิม (ส่งซ้ำไม่เกิดถ้าปลายทางเดียวกัน)
                · ใช้ปุ่ม <i class="fas fa-copy"></i> คัดลอกกฎเพื่อสร้างแบบใหม่โดยเปลี่ยนเฉพาะส่วนที่ต้องการ
                · Cron: <code>cron_smart_pharma_telegram.php</code>
            </div>
        </div>
    </div>
</div>

<!-- Telegram modal -->
<div class="modal fade" id="chatModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-2"><h6 class="modal-title fw-bold" id="chatModalTitle">เพิ่มกลุ่ม Telegram</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" id="chat_id_edit">
                <div class="mb-2"><label class="form-label small fw-bold">ชื่อกลุ่ม</label><input type="text" id="chat_label" class="form-control form-control-sm"></div>
                <div class="mb-2"><label class="form-label small fw-bold">Bot Token</label><input type="password" id="chat_bot_token" class="form-control form-control-sm font-monospace"></div>
                <div class="mb-2"><label class="form-label small fw-bold">Chat ID</label><input type="text" id="chat_chat_id" class="form-control form-control-sm font-monospace"></div>
                <div class="mb-2"><label class="form-label small fw-bold">หมายเหตุ</label><input type="text" id="chat_notes" class="form-control form-control-sm"></div>
                <div class="form-check"><input class="form-check-input" type="checkbox" id="chat_active" checked><label class="form-check-label small" for="chat_active">เปิดใช้</label></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-sp btn-sm" onclick="saveChat()">บันทึก</button>
            </div>
        </div>
    </div>
</div>

<!-- Webhook modal -->
<div class="modal fade" id="endpointModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-2"><h6 class="modal-title fw-bold" id="endpointModalTitle">เพิ่ม Webhook</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" id="ep_id_edit">
                <div class="mb-2"><label class="form-label small fw-bold">ชื่อ</label><input type="text" id="ep_label" class="form-control form-control-sm"></div>
                <div class="mb-2"><label class="form-label small fw-bold">Webhook URL</label><input type="text" id="ep_url" class="form-control form-control-sm font-monospace"></div>
                <div class="mb-2"><label class="form-label small fw-bold">Secret (HMAC)</label><input type="password" id="ep_secret" class="form-control form-control-sm font-monospace"></div>
                <div class="mb-2"><label class="form-label small fw-bold">หมายเหตุ</label><input type="text" id="ep_notes" class="form-control form-control-sm"></div>
                <div class="form-check"><input class="form-check-input" type="checkbox" id="ep_active" checked><label class="form-check-label small" for="ep_active">เปิดใช้</label></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-sp btn-sm" onclick="saveEndpoint()">บันทึก</button>
            </div>
        </div>
    </div>
</div>

<!-- Unified rule modal -->
<div class="modal fade" id="ruleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2"><h6 class="modal-title fw-bold" id="ruleModalTitle">เพิ่มกฎแจ้งเตือน</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" id="rule_old_policy_key">
                <div class="row g-2 mb-2">
                    <div class="col-md-4">
                        <label class="form-label small fw-bold d-block">OPD / IPD</label>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" id="rule_pt_opd" value="OPD" checked>
                            <label class="form-check-label small" for="rule_pt_opd">OPD</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" id="rule_pt_ipd" value="IPD" checked>
                            <label class="form-check-label small" for="rule_pt_ipd">IPD</label>
                        </div>
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-bold">โมดูล Alert <span class="text-muted fw-normal">(เลือกได้หลายรายการ)</span></label>
                    <div id="rule_module_checks" class="dest-check-grid border rounded p-2 bg-white">
                        <div class="row g-1">
                            <?php foreach ($all_modules as $m): ?>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input rule-mod-chk" type="checkbox"
                                        value="<?= htmlspecialchars($m['module_key']) ?>"
                                        id="r_mod_<?= htmlspecialchars($m['module_key']) ?>">
                                    <label class="form-check-label small" for="r_mod_<?= htmlspecialchars($m['module_key']) ?>">
                                        <?= htmlspecialchars($m['label_th']) ?>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-bold d-block">ระดับ Alert</label>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="rule_notify_hard" checked onchange="toggleHardRepeat()">
                        <label class="form-check-label small" for="rule_notify_hard">Hard</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="rule_notify_soft" onchange="toggleSoftRepeat()">
                        <label class="form-check-label small" for="rule_notify_soft">Soft</label>
                    </div>
                </div>
                <div id="rule_hard_repeat_box" class="border rounded p-2 mb-2 bg-light">
                    <label class="form-label small fw-bold">Hard Alert — แจ้งเตือน</label>
                    <select id="rule_hard_repeat_mode" class="form-select form-select-sm mb-1" onchange="toggleRepeatCustom()">
                        <option value="off">แจ้งครั้งเดียว (ไม่ซ้ำ)</option>
                        <option value="15">แจ้งซ้ำทุก 15 นาที</option>
                        <option value="30">แจ้งซ้ำทุก 30 นาที</option>
                        <option value="60">แจ้งซ้ำทุก 1 ชั่วโมง</option>
                        <option value="custom">แจ้งซ้ำ — กำหนดเอง (นาที)</option>
                    </select>
                    <div id="rule_hard_repeat_custom" style="display:none">
                        <input type="number" id="rule_hard_repeat_minutes" class="form-control form-control-sm mb-2" min="1" max="10080" placeholder="นาที">
                    </div>
                    <label class="form-label small fw-bold mb-1">Hard — หยุดแจ้งซ้ำอัตโนมัติ</label>
                    <select id="rule_hard_stop_mode" class="form-select form-select-sm mb-1" onchange="toggleStopCustom()">
                        <option value="off">จนกว่าจะแก้ HOSxP / ตอบ Intervention</option>
                        <option value="12h">12 ชั่วโมง นับจากพบ Alert ครั้งแรก</option>
                        <option value="1d">1 วัน นับจากพบ Alert ครั้งแรก</option>
                        <option value="2d">2 วัน นับจากพบ Alert ครั้งแรก</option>
                        <option value="custom">กำหนดเอง (นาที) นับจากพบ Alert ครั้งแรก</option>
                    </select>
                    <div id="rule_hard_stop_custom" style="display:none">
                        <input type="number" id="rule_hard_stop_minutes" class="form-control form-control-sm" min="1" max="10080" placeholder="นาที">
                    </div>
                </div>
                <div id="rule_soft_repeat_box" class="border rounded p-2 mb-2 bg-light">
                    <label class="form-label small fw-bold">Soft Alert — แจ้งเตือน</label>
                    <select id="rule_soft_repeat_mode" class="form-select form-select-sm mb-1" onchange="toggleRepeatCustom()">
                        <option value="off">แจ้งครั้งเดียว (ไม่ซ้ำ)</option>
                        <option value="15">แจ้งซ้ำทุก 15 นาที</option>
                        <option value="30">แจ้งซ้ำทุก 30 นาที</option>
                        <option value="60">แจ้งซ้ำทุก 1 ชั่วโมง</option>
                        <option value="custom">แจ้งซ้ำ — กำหนดเอง (นาที)</option>
                    </select>
                    <div id="rule_soft_repeat_custom" style="display:none">
                        <input type="number" id="rule_soft_repeat_minutes" class="form-control form-control-sm mb-2" min="1" max="10080" placeholder="นาที">
                    </div>
                    <label class="form-label small fw-bold mb-1">Soft — หยุดแจ้งซ้ำอัตโนมัติ</label>
                    <select id="rule_soft_stop_mode" class="form-select form-select-sm mb-1" onchange="toggleStopCustom()">
                        <option value="off">จนกว่าจะแก้ HOSxP / ตอบ Intervention</option>
                        <option value="12h">12 ชั่วโมง นับจากพบ Alert ครั้งแรก</option>
                        <option value="1d">1 วัน นับจากพบ Alert ครั้งแรก</option>
                        <option value="2d">2 วัน นับจากพบ Alert ครั้งแรก</option>
                        <option value="custom">กำหนดเอง (นาที) นับจากพบ Alert ครั้งแรก</option>
                    </select>
                    <div id="rule_soft_stop_custom" style="display:none">
                        <input type="number" id="rule_soft_stop_minutes" class="form-control form-control-sm" min="1" max="10080" placeholder="นาที">
                    </div>
                </div>
                <div class="border rounded p-2 mb-2">
                    <label class="form-label small fw-bold mb-2">ส่งไปที่ (เลือกได้หลายรายการ)</label>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <div class="small fw-bold text-info mb-1"><i class="fab fa-telegram-plane me-1"></i>Telegram</div>
                            <div id="rule_tg_checks" class="dest-check-grid border rounded p-2 bg-white small text-muted">ยังไม่มีกลุ่ม</div>
                        </div>
                        <div class="col-md-6">
                            <div class="small fw-bold text-primary mb-1"><i class="fas fa-plug me-1"></i>Webhook</div>
                            <div id="rule_wh_checks" class="dest-check-grid border rounded p-2 bg-white small text-muted">ยังไม่มี Endpoint</div>
                        </div>
                    </div>
                </div>
                <div class="border rounded p-2 mb-2 bg-light">
                    <label class="form-label small fw-bold">ลิงก์แนบ</label>
                    <select id="rule_link_mode" class="form-select form-select-sm mb-2" onchange="toggleLinkFields()">
                        <option value="auto">ลิงก์ Intervention Log อัตโนมัติ</option>
                        <option value="custom">กำหนดเอง</option>
                        <option value="both">ทั้งสอง</option>
                        <option value="none">ไม่แนบ</option>
                    </select>
                    <div id="rule_custom_fields">
                        <input type="text" id="rule_extra_message" class="form-control form-control-sm mb-1" placeholder="ข้อความเพิ่ม">
                        <div class="row g-1">
                            <div class="col-8"><input type="text" id="rule_extra_link" class="form-control form-control-sm" placeholder="URL เพิ่ม"></div>
                            <div class="col-4"><input type="text" id="rule_extra_link_label" class="form-control form-control-sm" placeholder="ชื่อลิงก์"></div>
                        </div>
                    </div>
                </div>
                <div class="form-check"><input class="form-check-input" type="checkbox" id="rule_active" checked><label class="form-check-label small" for="rule_active">เปิดใช้กฎ</label></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-sp btn-sm" onclick="saveUnifiedRule()">บันทึก</button>
            </div>
        </div>
    </div>
</div>

<script src="js/jquery-3.7.0.min.js"></script>
<script src="js/bootstrap.bundle.min.js"></script>
<script>
let tgChats = [], whEndpoints = [], unifiedRules = [];
let chatModal, endpointModal, ruleModal;

const LINK_LABELS = { auto: 'ลิงก์ Alert', custom: 'กำหนดเอง', both: 'Alert+เพิ่ม', none: '—' };
const REPEAT_LABELS = { off: 'ครั้งเดียว', '15': '15 น.', '30': '30 น.', '60': '1 ชม.' };
const STOP_LABELS = { off: 'จนแก้/ตอบ', '12h': '12 ชม.', '1d': '1 วัน', '2d': '2 วัน' };

function esc(s) { return !s ? '' : String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function patientTypeLabel(r) {
    if (r.patient_type === 'OPD+IPD') return 'OPD+IPD';
    const types = r.patient_types || [r.patient_type];
    if (types.length === 2 && types.includes('OPD') && types.includes('IPD')) return 'OPD+IPD';
    return types[0] || r.patient_type || '—';
}
function getSelectedPatientTypes() {
    const types = [];
    if ($('#rule_pt_opd').is(':checked')) types.push('OPD');
    if ($('#rule_pt_ipd').is(':checked')) types.push('IPD');
    return types;
}
function setPatientTypeChecks(types) {
    types = types || [];
    $('#rule_pt_opd').prop('checked', types.includes('OPD'));
    $('#rule_pt_ipd').prop('checked', types.includes('IPD'));
}
function getSelectedModuleKeys() {
    const keys = [];
    $('.rule-mod-chk:checked').each(function() { keys.push(this.value); });
    return keys;
}
function setModuleChecks(keys) {
    keys = keys || [];
    $('.rule-mod-chk').each(function() {
        $(this).prop('checked', keys.indexOf(this.value) >= 0);
    });
}
function api(action, opts) {
    opts = opts || {};
    const url = 'fetch_smart_pharma_api.php?action=' + encodeURIComponent(action);
    if ((opts.method || 'GET') === 'GET') return fetch(url, { credentials: 'same-origin' }).then(r => r.json());
    return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(opts.body || {}) }).then(r => r.json());
}
function repeatLabel(mode, minutes) {
    const m = mode || 'off';
    if (m === 'custom' && minutes > 0) return minutes + ' น.';
    return REPEAT_LABELS[m] || 'ครั้งเดียว';
}
function stopLabel(mode, minutes, legacy) {
    const m = mode || (legacy && legacy.stop_mode) || 'off';
    const mins = minutes > 0 ? minutes : (legacy && legacy.stop_minutes > 0 ? legacy.stop_minutes : 0);
    if (m === 'custom' && mins > 0) return mins + ' น.';
    return STOP_LABELS[m] || 'จนแก้/ตอบ';
}
function truncateUrl(u) {
    if (!u) return '';
    return u.length <= 36 ? esc(u) : esc(u.substring(0, 32) + '...');
}

function saveGlobalConfig() {
    api('notification_save_config', { method: 'POST', body: {
        telegram_enabled: $('#cfg_tg_enabled').is(':checked'),
        webhook_enabled: $('#cfg_wh_enabled').is(':checked'),
        base_url: $('#cfg_base_url').val().trim(),
        cron_secret: $('#cfg_cron_secret').val().trim(),
    }}).then(d => alert(d.message || (d.status === 'success' ? 'บันทึกแล้ว' : 'บันทึกไม่สำเร็จ')));
}

function loadAll() {
    return Promise.all([
        api('telegram_chats'),
        api('webhook_endpoints'),
        api('notification_unified_rules'),
    ]).then(([tc, we, ur]) => {
        if (tc.status === 'success') tgChats = tc.chats || [];
        if (we.status === 'success') whEndpoints = we.endpoints || [];
        if (ur.status === 'success') unifiedRules = ur.rules || [];
        renderChats(); renderEndpoints(); renderUnifiedRules(); fillTestDestinations();
    });
}

function renderChats() {
    if (!tgChats.length) {
        $('#tgChatsBody').html('<tr><td colspan="4" class="text-center text-muted py-3">ยังไม่มี — กดเพิ่ม</td></tr>');
        return;
    }
    let h = '';
    tgChats.forEach(c => {
        h += '<tr><td class="fw-bold small">' + esc(c.label) + '</td><td class="text-center font-monospace small">' + esc(c.chat_id) + '</td>'
            + '<td class="text-center">' + (c.active == 1 ? '<span class="badge bg-success">เปิด</span>' : '<span class="badge bg-secondary">ปิด</span>') + '</td>'
            + '<td class="text-center text-nowrap">'
            + '<button class="btn btn-outline-info btn-sm py-0 me-1" onclick="testDest(\'telegram\',' + c.id + ')"><i class="fas fa-vial"></i></button>'
            + '<button class="btn btn-outline-secondary btn-sm py-0 me-1" onclick="editChat(' + c.id + ')"><i class="fas fa-pen"></i></button>'
            + '<button class="btn btn-outline-danger btn-sm py-0" onclick="deleteChat(' + c.id + ')"><i class="fas fa-trash"></i></button></td></tr>';
    });
    $('#tgChatsBody').html(h);
}

function renderEndpoints() {
    if (!whEndpoints.length) {
        $('#whEndpointsBody').html('<tr><td colspan="4" class="text-center text-muted py-3">ยังไม่มี — กดเพิ่ม</td></tr>');
        return;
    }
    let h = '';
    whEndpoints.forEach(e => {
        h += '<tr><td class="fw-bold small">' + esc(e.label) + '</td><td class="small font-monospace">' + truncateUrl(e.webhook_url) + '</td>'
            + '<td class="text-center">' + (e.active == 1 ? '<span class="badge bg-success">เปิด</span>' : '<span class="badge bg-secondary">ปิด</span>') + '</td>'
            + '<td class="text-center text-nowrap">'
            + '<button class="btn btn-outline-info btn-sm py-0 me-1" onclick="testDest(\'webhook\',' + e.id + ')"><i class="fas fa-vial"></i></button>'
            + '<button class="btn btn-outline-secondary btn-sm py-0 me-1" onclick="editEndpoint(' + e.id + ')"><i class="fas fa-pen"></i></button>'
            + '<button class="btn btn-outline-danger btn-sm py-0" onclick="deleteEndpoint(' + e.id + ')"><i class="fas fa-trash"></i></button></td></tr>';
    });
    $('#whEndpointsBody').html(h);
}

function renderDestBadges(r) {
    let h = '';
    (r.telegram || []).forEach(t => { h += '<span class="channel-pill channel-tg me-1"><i class="fab fa-telegram-plane"></i> ' + esc(t.label) + '</span>'; });
    (r.webhook || []).forEach(w => { h += '<span class="channel-pill channel-wh me-1"><i class="fas fa-plug"></i> ' + esc(w.label) + '</span>'; });
    return h || '—';
}

function renderUnifiedRules() {
    if (!unifiedRules.length) {
        $('#unifiedRulesBody').html('<tr><td colspan="12" class="text-center text-muted py-3">ยังไม่มีกฎ</td></tr>');
        return;
    }
    let h = '';
    unifiedRules.forEach(r => {
        h += '<tr><td class="small">' + renderDestBadges(r) + '</td>'
            + '<td class="text-center fw-bold">' + esc(patientTypeLabel(r)) + '</td>'
            + '<td class="small">' + esc(r.module_label || r.module_key) + '</td>'
            + '<td class="text-center">' + (r.notify_hard == 1 ? '<i class="fas fa-check text-danger"></i>' : '—') + '</td>'
            + '<td class="text-center">' + (r.notify_soft == 1 ? '<i class="fas fa-check text-warning"></i>' : '—') + '</td>'
            + '<td class="text-center small">' + (r.notify_hard == 1 ? esc(repeatLabel(r.hard_repeat_mode, r.hard_repeat_minutes)) : '—') + '</td>'
            + '<td class="text-center small">' + (r.notify_soft == 1 ? esc(repeatLabel(r.soft_repeat_mode, r.soft_repeat_minutes)) : '—') + '</td>'
            + '<td class="text-center small">' + (r.notify_hard == 1 ? esc(stopLabel(r.hard_stop_mode, r.hard_stop_minutes, r)) : '—') + '</td>'
            + '<td class="text-center small">' + (r.notify_soft == 1 ? esc(stopLabel(r.soft_stop_mode, r.soft_stop_minutes, r)) : '—') + '</td>'
            + '<td class="text-center small">' + esc(LINK_LABELS[r.link_mode] || r.link_mode) + '</td>'
            + '<td class="text-center">' + (r.active == 1 ? '<span class="badge bg-success">เปิด</span>' : '<span class="badge bg-secondary">ปิด</span>') + '</td>'
            + '<td class="text-center text-nowrap">'
            + '<button class="btn btn-outline-info btn-sm py-0 me-1" title="คัดลอกกฎ" data-policy-key="' + esc(r.policy_key) + '" onclick="copyUnifiedRule(this)"><i class="fas fa-copy"></i></button>'
            + '<button class="btn btn-outline-secondary btn-sm py-0 me-1" title="แก้ไข" data-policy-key="' + esc(r.policy_key) + '" onclick="editUnifiedRule(this)"><i class="fas fa-pen"></i></button>'
            + '<button class="btn btn-outline-danger btn-sm py-0" data-policy-key="' + esc(r.policy_key) + '" onclick="deleteUnifiedRule(this)"><i class="fas fa-trash"></i></button>'
            + '</td></tr>';
    });
    $('#unifiedRulesBody').html(h);
}

function fillTestDestinations() {
    const ch = $('#test_channel').val();
    const sel = $('#test_dest').empty().append('<option value="">— เลือก —</option>');
    if (ch === 'telegram') {
        tgChats.filter(c => c.active == 1).forEach(c => sel.append('<option value="' + c.id + '">' + esc(c.label) + '</option>'));
    } else {
        whEndpoints.filter(e => e.active == 1).forEach(e => sel.append('<option value="' + e.id + '">' + esc(e.label) + '</option>'));
    }
}

function sendTest() {
    const ch = $('#test_channel').val();
    const id = $('#test_dest').val();
    const mode = $('#test_mode').val();
    if (!id) { alert('เลือกปลายทาง'); return; }
    const btn = $('#testSendBtn');
    const old = btn.html();
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');
    const action = ch === 'telegram' ? 'telegram_test' : 'webhook_test';
    const body = ch === 'telegram' ? { chat_row_id: id, mode: mode } : { endpoint_id: id, mode: mode };
    api(action, { method: 'POST', body: body }).then(d => alert(d.message || '')).finally(() => btn.prop('disabled', false).html(old));
}
function testDest(ch, id) {
    $('#test_channel').val(ch);
    fillTestDestinations();
    $('#test_dest').val(String(id));
    $('html, body').animate({ scrollTop: 0 }, 200);
}

function openChatForm() {
    $('#chat_id_edit,#chat_label,#chat_bot_token,#chat_chat_id,#chat_notes').val('');
    $('#chat_active').prop('checked', true);
    $('#chatModalTitle').text('เพิ่มกลุ่ม Telegram');
    chatModal.show();
}
function editChat(id) {
    const c = tgChats.find(x => String(x.id) === String(id));
    if (!c) return;
    $('#chat_id_edit').val(c.id); $('#chat_label').val(c.label); $('#chat_bot_token').val(c.bot_token || '');
    $('#chat_chat_id').val(c.chat_id); $('#chat_notes').val(c.notes || ''); $('#chat_active').prop('checked', c.active == 1);
    $('#chatModalTitle').text('แก้ไขกลุ่ม Telegram');
    chatModal.show();
}
function saveChat() {
    if (!$('#chat_label').val().trim() || !$('#chat_chat_id').val().trim()) { alert('กรอกชื่อและ Chat ID'); return; }
    api('telegram_save_chat', { method: 'POST', body: {
        id: $('#chat_id_edit').val(), label: $('#chat_label').val().trim(), bot_token: $('#chat_bot_token').val().trim(),
        chat_id: $('#chat_chat_id').val().trim(), notes: $('#chat_notes').val().trim(), active: $('#chat_active').is(':checked'),
    }}).then(d => { if (d.status !== 'success') { alert(d.message); return; } chatModal.hide(); loadAll(); });
}
function deleteChat(id) {
    if (!confirm('ลบกลุ่มและกฎที่เกี่ยวข้อง?')) return;
    api('telegram_delete_chat', { method: 'POST', body: { id: id } }).then(d => { if (d.status === 'success') loadAll(); else alert(d.message); });
}

function openEndpointForm() {
    $('#ep_id_edit,#ep_label,#ep_url,#ep_secret,#ep_notes').val('');
    $('#ep_active').prop('checked', true);
    $('#endpointModalTitle').text('เพิ่ม Webhook');
    endpointModal.show();
}
function editEndpoint(id) {
    const e = whEndpoints.find(x => String(x.id) === String(id));
    if (!e) return;
    $('#ep_id_edit').val(e.id); $('#ep_label').val(e.label); $('#ep_url').val(e.webhook_url);
    $('#ep_secret').val(e.secret || ''); $('#ep_notes').val(e.notes || ''); $('#ep_active').prop('checked', e.active == 1);
    $('#endpointModalTitle').text('แก้ไข Webhook');
    endpointModal.show();
}
function saveEndpoint() {
    if (!$('#ep_label').val().trim() || !$('#ep_url').val().trim()) { alert('กรอกชื่อและ URL'); return; }
    api('webhook_save_endpoint', { method: 'POST', body: {
        id: $('#ep_id_edit').val(), label: $('#ep_label').val().trim(), webhook_url: $('#ep_url').val().trim(),
        secret: $('#ep_secret').val().trim(), notes: $('#ep_notes').val().trim(), active: $('#ep_active').is(':checked'),
    }}).then(d => { if (d.status !== 'success') { alert(d.message); return; } endpointModal.hide(); loadAll(); });
}
function deleteEndpoint(id) {
    if (!confirm('ลบ Endpoint และกฎที่เกี่ยวข้อง?')) return;
    api('webhook_delete_endpoint', { method: 'POST', body: { id: id } }).then(d => { if (d.status === 'success') loadAll(); else alert(d.message); });
}

function fillDestChecks(tgIds, whIds) {
    tgIds = tgIds || []; whIds = whIds || [];
    let tgH = '';
    if (!tgChats.length) tgH = '<span class="text-muted">ยังไม่มีกลุ่ม</span>';
    else tgChats.filter(c => c.active == 1).forEach(c => {
        const ck = tgIds.includes(c.id) || tgIds.includes(String(c.id)) ? ' checked' : '';
        tgH += '<div class="form-check"><input class="form-check-input rule-tg-chk" type="checkbox" value="' + c.id + '" id="r_tg_' + c.id + '"' + ck + '>'
            + '<label class="form-check-label" for="r_tg_' + c.id + '">' + esc(c.label) + '</label></div>';
    });
    $('#rule_tg_checks').html(tgH);
    let whH = '';
    if (!whEndpoints.length) whH = '<span class="text-muted">ยังไม่มี Endpoint</span>';
    else whEndpoints.filter(e => e.active == 1).forEach(e => {
        const ck = whIds.includes(e.id) || whIds.includes(String(e.id)) ? ' checked' : '';
        whH += '<div class="form-check"><input class="form-check-input rule-wh-chk" type="checkbox" value="' + e.id + '" id="r_wh_' + e.id + '"' + ck + '>'
            + '<label class="form-check-label" for="r_wh_' + e.id + '">' + esc(e.label) + '</label></div>';
    });
    $('#rule_wh_checks').html(whH);
}

function toggleHardRepeat() { $('#rule_hard_repeat_box').toggle($('#rule_notify_hard').is(':checked')); }
function toggleSoftRepeat() { $('#rule_soft_repeat_box').toggle($('#rule_notify_soft').is(':checked')); }
function toggleRepeatCustom() {
    $('#rule_hard_repeat_custom').toggle($('#rule_hard_repeat_mode').val() === 'custom');
    $('#rule_soft_repeat_custom').toggle($('#rule_soft_repeat_mode').val() === 'custom');
}
function toggleStopCustom() {
    $('#rule_hard_stop_custom').toggle($('#rule_hard_stop_mode').val() === 'custom');
    $('#rule_soft_stop_custom').toggle($('#rule_soft_stop_mode').val() === 'custom');
}
function toggleLinkFields() {
    const m = $('#rule_link_mode').val();
    $('#rule_custom_fields').toggle(m === 'custom' || m === 'both');
}

function openRuleForm() {
    $('#rule_old_policy_key').val('');
    $('#ruleModalTitle').text('เพิ่มกฎแจ้งเตือน');
    setPatientTypeChecks(['OPD', 'IPD']);
    setModuleChecks([]);
    $('#rule_notify_hard').prop('checked', true);
    $('#rule_notify_soft').prop('checked', false);
    $('#rule_hard_repeat_mode').val('off');
    $('#rule_hard_repeat_minutes').val('');
    $('#rule_soft_repeat_mode').val('off');
    $('#rule_soft_repeat_minutes').val('');
    $('#rule_hard_stop_mode').val('off');
    $('#rule_hard_stop_minutes').val('');
    $('#rule_soft_stop_mode').val('off');
    $('#rule_soft_stop_minutes').val('');
    $('#rule_link_mode').val('auto');
    $('#rule_extra_message,#rule_extra_link,#rule_extra_link_label').val('');
    $('#rule_active').prop('checked', true);
    fillDestChecks([], []);
    toggleHardRepeat(); toggleSoftRepeat(); toggleRepeatCustom(); toggleStopCustom(); toggleLinkFields();
    ruleModal.show();
}

function fillRuleFormFromRow(r, isCopy) {
    if (isCopy) {
        $('#rule_old_policy_key').val('');
        $('#ruleModalTitle').text('คัดลอกกฎแจ้งเตือน');
    } else {
        $('#rule_old_policy_key').val(r.policy_key);
        $('#ruleModalTitle').text('แก้ไขกฎแจ้งเตือน');
    }
    setPatientTypeChecks(r.patient_types || [r.patient_type]);
    setModuleChecks(r.module_keys || (r.module_key ? [r.module_key] : []));
    $('#rule_notify_hard').prop('checked', r.notify_hard == 1);
    $('#rule_notify_soft').prop('checked', r.notify_soft == 1);
    $('#rule_hard_repeat_mode').val(r.hard_repeat_mode || 'off');
    $('#rule_hard_repeat_minutes').val(r.hard_repeat_minutes > 0 ? r.hard_repeat_minutes : '');
    $('#rule_soft_repeat_mode').val(r.soft_repeat_mode || 'off');
    $('#rule_soft_repeat_minutes').val(r.soft_repeat_minutes > 0 ? r.soft_repeat_minutes : '');
    $('#rule_hard_stop_mode').val(r.hard_stop_mode || r.stop_mode || 'off');
    $('#rule_hard_stop_minutes').val((r.hard_stop_minutes || r.stop_minutes) > 0 ? (r.hard_stop_minutes || r.stop_minutes) : '');
    $('#rule_soft_stop_mode').val(r.soft_stop_mode || r.stop_mode || 'off');
    $('#rule_soft_stop_minutes').val((r.soft_stop_minutes || r.stop_minutes) > 0 ? (r.soft_stop_minutes || r.stop_minutes) : '');
    $('#rule_link_mode').val(r.link_mode || 'auto');
    $('#rule_extra_message').val(r.extra_message || '');
    $('#rule_extra_link').val(r.extra_link || '');
    $('#rule_extra_link_label').val(r.extra_link_label || '');
    $('#rule_active').prop('checked', r.active == 1);
    fillDestChecks((r.telegram || []).map(t => t.id), (r.webhook || []).map(w => w.id));
    toggleHardRepeat(); toggleSoftRepeat(); toggleRepeatCustom(); toggleStopCustom(); toggleLinkFields();
}

function editUnifiedRule(btn) {
    const policyKey = btn.getAttribute('data-policy-key');
    const r = unifiedRules.find(x => x.policy_key === policyKey);
    if (!r) return;
    fillRuleFormFromRow(r, false);
    ruleModal.show();
}

function copyUnifiedRule(btn) {
    const policyKey = btn.getAttribute('data-policy-key');
    const r = unifiedRules.find(x => x.policy_key === policyKey);
    if (!r) return;
    fillRuleFormFromRow(r, true);
    ruleModal.show();
}

function buildConflictConfirmMessage(conflicts) {
    let msg = 'มีกฎเดิมที่ชนกัน (ปลายทาง + OPD/IPD + โมดูลเดียวกัน):\n\n';
    conflicts.forEach(c => {
        msg += '• ' + c.channel + ' → ' + c.destination + ' | ' + c.patient_type + ' | ' + c.module_label + '\n';
    });
    msg += '\nบันทึกจะแทนที่การตั้งค่าเดิม\nต้องการบันทึกต่อหรือไม่?';
    return msg;
}

function saveUnifiedRule(forceOverwrite) {
    const tgIds = []; $('.rule-tg-chk:checked').each(function() { tgIds.push(parseInt(this.value, 10)); });
    const whIds = []; $('.rule-wh-chk:checked').each(function() { whIds.push(parseInt(this.value, 10)); });
    if (!tgIds.length && !whIds.length) { alert('เลือกปลายทางอย่างน้อย 1 รายการ'); return; }
    if (!$('#rule_notify_hard').is(':checked') && !$('#rule_notify_soft').is(':checked')) { alert('เลือก Hard หรือ Soft'); return; }
    const patientTypes = getSelectedPatientTypes();
    if (!patientTypes.length) { alert('เลือก OPD หรือ IPD อย่างน้อย 1 รายการ'); return; }
    const moduleKeys = getSelectedModuleKeys();
    if (!moduleKeys.length) { alert('เลือกโมดูล Alert อย่างน้อย 1 รายการ'); return; }
    const body = {
        old_policy_key: $('#rule_old_policy_key').val(),
        patient_types: patientTypes,
        module_keys: moduleKeys,
        notify_hard: $('#rule_notify_hard').is(':checked'),
        notify_soft: $('#rule_notify_soft').is(':checked'),
        hard_repeat_mode: $('#rule_notify_hard').is(':checked') ? $('#rule_hard_repeat_mode').val() : 'off',
        hard_repeat_minutes: $('#rule_hard_repeat_minutes').val(),
        soft_repeat_mode: $('#rule_notify_soft').is(':checked') ? $('#rule_soft_repeat_mode').val() : 'off',
        soft_repeat_minutes: $('#rule_soft_repeat_minutes').val(),
        hard_stop_mode: $('#rule_notify_hard').is(':checked') ? $('#rule_hard_stop_mode').val() : 'off',
        hard_stop_minutes: $('#rule_hard_stop_minutes').val(),
        soft_stop_mode: $('#rule_notify_soft').is(':checked') ? $('#rule_soft_stop_mode').val() : 'off',
        soft_stop_minutes: $('#rule_soft_stop_minutes').val(),
        link_mode: $('#rule_link_mode').val(),
        extra_message: $('#rule_extra_message').val().trim(),
        extra_link: $('#rule_extra_link').val().trim(),
        extra_link_label: $('#rule_extra_link_label').val().trim(),
        active: $('#rule_active').is(':checked'),
        telegram_chat_ids: tgIds,
        webhook_endpoint_ids: whIds,
    };
    if (forceOverwrite) body.force_overwrite = true;
    api('notification_save_unified_rule', { method: 'POST', body: body }).then(d => {
        if (d.status === 'confirm' && d.conflicts && d.conflicts.length) {
            if (confirm(buildConflictConfirmMessage(d.conflicts))) saveUnifiedRule(true);
            return;
        }
        if (d.status !== 'success') { alert(d.message); return; }
        ruleModal.hide();
        loadAll();
    });
}

function deleteUnifiedRule(btn) {
    const policyKey = btn.getAttribute('data-policy-key');
    if (!confirm('ลบกฎนี้ทุกปลายทาง?')) return;
    api('notification_delete_unified_rule', { method: 'POST', body: { policy_key: policyKey } })
        .then(d => { if (d.status === 'success') loadAll(); else alert(d.message); });
}

$(function() {
    chatModal = new bootstrap.Modal(document.getElementById('chatModal'));
    endpointModal = new bootstrap.Modal(document.getElementById('endpointModal'));
    ruleModal = new bootstrap.Modal(document.getElementById('ruleModal'));
    loadAll();
});
</script>
</body>
</html>
