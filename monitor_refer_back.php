<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
include('connect.php');
require_once __DIR__ . '/monitor_refer_back_helper.php';
mysqli_set_charset($con, 'utf8');
mrb_ensure_tables($con);

$tab = $_GET['tab'] ?? 'opd';
if (!in_array($tab, ['opd', 'ipd', 'interventions', 'settings'], true)) {
    $tab = 'opd';
}
if (basename($_SERVER['PHP_SELF']) === 'monitor_refer_back.php') {
    $redirects = [
        'opd' => 'monitor_refer_back_opd.php',
        'ipd' => 'monitor_refer_back_ipd.php',
        'interventions' => 'monitor_refer_back_interventions.php',
        'settings' => 'monitor_refer_back_settings.php',
    ];
    header('Location: ' . $redirects[$tab]);
    exit;
}

$meta = [
    'opd' => ['title' => 'Monitor Refer Back — VN (OPD)', 'icon' => 'fa-hospital-user', 'subtitle' => 'คัดกรองผู้ป่วยนอก เทียบบัญชียาเครือข่าย'],
    'ipd' => ['title' => 'Monitor Refer Back — AN (IPD)', 'icon' => 'fa-procedures', 'subtitle' => 'ตรวจจับรอบสั่งยา HME เทียบบัญชียาเครือข่าย'],
    'interventions' => ['title' => 'Intervention Log Refer Back', 'icon' => 'fa-notes-medical', 'subtitle' => 'บันทึก case ที่ทำ / ไม่ได้ทำ Refer Back'],
    'settings' => ['title' => 'ตั้งค่า Monitor Refer Back', 'icon' => 'fa-sliders-h', 'subtitle' => 'เกณฑ์ % เทียบบัญชี และสิทธิการรักษา (แยก OPD/IPD)'],
][$tab];
$patient_type = $tab === 'ipd' ? 'IPD' : 'OPD';
$default_date = function_exists('sp_today_date') ? sp_today_date() : date('Y-m-d');
$log_start = date('Y-m-d', strtotime('-30 days'));
session_write_close();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($meta['title']) ?> - THAM SAFE</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
<?php if ($tab === 'settings'): ?>
    <link href="css/select2.min.css" rel="stylesheet">
    <link href="css/select2-bootstrap-5-theme.min.css" rel="stylesheet">
<?php endif; ?>
    <style>
        :root { --mrb-primary: #1f4d3b; --mrb-light: #e8f5ef; }
        .mrb-hero { background: linear-gradient(135deg, #163d2f, #1f4d3b 55%, #2d6a4f); color:#fff; border-radius:12px; padding:1.2rem 1.4rem; }
        .mrb-table thead { background: var(--mrb-primary) !important; color:#fff !important; }
        .mrb-row-pending { background:#fff8f8 !important; box-shadow: inset 4px 0 0 #dc3545; }
        .mrb-row-printed { background:#f4fbf7 !important; }
        .mrb-badge-pending { background:#dc3545; color:#fff; }
        .mrb-badge-printed { background:#198754; color:#fff; }
        .mrb-alert-banner { background:#fff3cd; border:1px solid #ffc107; border-radius:8px; padding:.6rem .9rem; margin-bottom:1rem; }
        @keyframes mrb-blink { 0%,100%{opacity:1} 50%{opacity:.35} }
        .mrb-blink { animation: mrb-blink 1s ease-in-out infinite; }
        .mrb-time-tabs { display:flex; flex-wrap:wrap; gap:.35rem; align-items:center; }
        .mrb-time-tab { font-size:.78rem; padding:.25rem .55rem; border:1px solid #c5d9ce; background:#fff; color:#1f4d3b; border-radius:999px; }
        .mrb-time-tab:hover { background:#e8f5ef; }
        .mrb-time-tab.active { background:var(--mrb-primary); color:#fff; border-color:var(--mrb-primary); }
        .mrb-exclude-table th { font-size: .78rem; background: #f1f8f4; }
        .mrb-exclude-table td { vertical-align: middle; font-size: .82rem; }
        .mrb-pttype-list { border:1px solid #dee2e6; border-radius:8px; padding:.5rem; background:#fafcfb; }
        .mrb-pttype-list .form-check { margin-bottom:.2rem; padding:.15rem .25rem; border-radius:4px; }
        .mrb-pttype-list .form-check-input {
            width:1.05em; height:1.05em; margin-top:.2em;
            border:2px solid #6c757d; cursor:pointer;
        }
        .mrb-pttype-list .form-check-input:checked {
            background-color:#198754; border-color:#198754;
        }
        .mrb-pttype-list .form-check-input:disabled { opacity:1; cursor:default; }
        .mrb-pttype-list .mrb-pttype-on { background:#e8f5ef; }
        .mrb-pttype-list .mrb-pttype-on .form-check-label { color:#1f4d3b; font-weight:600; }
        .mrb-pttype-list .mrb-pttype-off { opacity:.42; }
        .mrb-dept-list { border:1px solid #dee2e6; border-radius:8px; padding:.35rem .5rem; background:#fafcfb; min-width:280px; }
        .mrb-dept-list .form-check { margin-bottom:.15rem; padding:.1rem .15rem; border-radius:4px; }
        .mrb-dept-list .form-check-input { margin-top:.25em; }
        .mrb-dept-list .form-check-label { font-size:.82rem; cursor:pointer; }
        .mrb-dept-list .mrb-dept-on { background:#e8f5ef; }
        #mrbDeptFilterBtn { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        #printArea { display:none; }
        @media print {
            body { background: #fff !important; }
            .wrapper { display: block !important; }
            .no-print, .no-print-section, #sidebar, .modal, .modal-backdrop { display: none !important; }
            .mrb-hero {
                display: block !important;
                margin: 0 0 12px !important;
                padding: 1rem 1.2rem !important;
                border-radius: 8px !important;
                page-break-inside: avoid;
            }
            #content { margin: 0 !important; padding: 0 !important; width: 100% !important; }
            .container-fluid { padding: 0 !important; max-width: 100% !important; }
            #printArea { display: block !important; padding: 0 !important; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            @page { size: A4 portrait; margin: 12mm 10mm; }
        }
    </style>
</head>
<body>
<div class="wrapper">
<?php include('sidebar.php'); ?>
<div id="content" class="content">
<div class="container-fluid py-4">
    <div class="mrb-hero mb-3">
        <div class="small opacity-75 mb-1"><i class="fas fa-share-square me-1"></i>บัญชียาเครือข่าย</div>
        <h4 class="fw-bold mb-1"><i class="fas <?= htmlspecialchars($meta['icon']) ?> me-2"></i><?= htmlspecialchars($meta['title']) ?></h4>
        <small><?= htmlspecialchars($meta['subtitle']) ?></small>
    </div>

    <ul class="nav nav-tabs mb-3 no-print">
        <li class="nav-item"><a class="nav-link <?= $tab==='opd'?'active':'' ?>" href="monitor_refer_back_opd.php">VN (OPD)</a></li>
        <li class="nav-item"><a class="nav-link <?= $tab==='ipd'?'active':'' ?>" href="monitor_refer_back_ipd.php">AN (IPD)</a></li>
        <li class="nav-item"><a class="nav-link <?= $tab==='interventions'?'active':'' ?>" href="monitor_refer_back_interventions.php">Intervention Log</a></li>
        <li class="nav-item"><a class="nav-link <?= $tab==='settings'?'active':'' ?>" href="monitor_refer_back_settings.php">ตั้งค่า</a></li>
    </ul>

    <div class="card shadow-sm border-0 no-print-section">
        <div class="card-body">

<?php if ($tab === 'opd' || $tab === 'ipd'): ?>
    <div id="mrbAlertBanner" class="mrb-alert-banner d-none">
        <i class="fas fa-bell mrb-blink text-danger me-2"></i>
        <span id="mrbAlertText">พบผู้ป่วยรอ Print Refer Back</span>
    </div>
    <div class="row g-2 align-items-end mb-2 no-print-section">
        <div class="col-md-2">
            <label class="form-label small fw-bold">วันที่</label>
            <input type="date" id="screenDate" class="form-control form-control-sm" value="<?= htmlspecialchars($default_date) ?>">
        </div>
<?php if ($tab === 'opd'): ?>
        <div class="col-md-4">
            <label class="form-label small fw-bold">แผนก <span class="text-muted fw-normal">(ติ๊กได้หลายแผนก)</span></label>
            <div class="dropdown w-100" id="mrbDeptFilterWrap">
                <button type="button" class="btn btn-outline-secondary btn-sm w-100 text-start dropdown-toggle" id="mrbDeptFilterBtn" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">ทุกแผนก</button>
                <div class="dropdown-menu p-2 w-100 shadow-sm" style="max-height:320px;overflow-y:auto;">
                    <div class="d-flex gap-1 mb-2">
                        <button type="button" class="btn btn-outline-primary btn-sm" id="mrbDeptSelectAll">เลือกทั้งหมด</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="mrbDeptClearAll">ล้าง</button>
                    </div>
                    <div id="mrbOpdDeptList" class="mrb-dept-list small text-muted py-2">กำลังโหลดแผนก...</div>
                </div>
            </div>
        </div>
<?php endif; ?>
        <div class="col-md-2">
            <button type="button" class="btn btn-success btn-sm w-100" id="btnLoad"><i class="fas fa-sync-alt me-1"></i>โหลดรายการ</button>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-bold">Auto refresh</label>
            <select id="mrbAutoRefresh" class="form-select form-select-sm">
                <option value="0">ปิด</option>
                <option value="5" selected>5 วินาที</option>
                <option value="30">30 วินาที</option>
                <option value="60">1 นาที</option>
                <option value="120">2 นาที</option>
                <option value="300">5 นาที</option>
            </select>
        </div>
        <div class="col-md-2">
            <div class="form-check form-switch mt-4">
                <input class="form-check-input" type="checkbox" id="mrbAlertSound" checked>
                <label class="form-check-label small" for="mrbAlertSound">เสียง Alert</label>
            </div>
        </div>
        <div class="col-md-<?= $tab === 'opd' ? '2' : '4' ?> text-md-end">
            <span class="badge bg-secondary" id="badgeTotal">0 ราย</span>
            <span class="badge mrb-badge-pending ms-1" id="badgePending">รอ Print 0</span>
            <span class="badge mrb-badge-printed ms-1" id="badgePrinted">พิมพ์แล้ว 0</span>
            <span class="text-muted ms-1 d-none" id="mrbRefreshHint"><i class="fas fa-sync-alt fa-spin"></i></span>
        </div>
    </div>
    <div class="mb-3 no-print-section">
        <label class="form-label small fw-bold mb-1">ช่วงเวลาสั่งยา</label>
        <div id="mrbTimeTabs" class="mrb-time-tabs"></div>
        <div class="small text-muted mt-1" id="mrbTimeHint"></div>
    </div>
    <div id="screenMeta" class="small text-muted border rounded p-2 mb-3 bg-light d-none"></div>
    <div class="table-responsive">
        <table class="table table-hover table-sm mrb-table mb-0">
            <thead class="text-center small">
                <tr>
                    <th><?= $patient_type === 'IPD' ? 'เวลาสั่ง HME' : 'เวลา' ?></th><th>HN</th><th class="text-start">ชื่อผู้ป่วย</th><th>อำเภอ</th><th>สิทธิ</th>
                    <th>รพ.ปลายทาง</th><th>%</th><th>Print</th><th>Intervention</th><th></th>
                </tr>
            </thead>
            <tbody id="screenBody"><tr><td colspan="10" class="text-center text-muted py-4">กดโหลดรายการ</td></tr></tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($tab === 'interventions'): ?>
    <div class="row g-2 mb-3" id="statsRow">
        <div class="col-md-2"><div class="border rounded p-2 text-center"><div class="small text-muted">ทั้งหมด</div><div class="fw-bold fs-5" id="statTotal">0</div></div></div>
        <div class="col-md-2"><div class="border rounded p-2 text-center bg-success bg-opacity-10"><div class="small text-muted">พิมพ์แล้ว</div><div class="fw-bold fs-5 text-success" id="statPrinted">0</div></div></div>
        <div class="col-md-2"><div class="border rounded p-2 text-center bg-danger bg-opacity-10"><div class="small text-muted">รอ Print</div><div class="fw-bold fs-5 text-danger" id="statPending">0</div></div></div>
        <div class="col-md-2"><div class="border rounded p-2 text-center"><div class="small text-muted">ทำแล้ว</div><div class="fw-bold fs-5" id="statDone">0</div></div></div>
        <div class="col-md-2"><div class="border rounded p-2 text-center"><div class="small text-muted">ไม่ได้ทำ</div><div class="fw-bold fs-5 text-warning" id="statNotDone">0</div></div></div>
    </div>
    <div class="row g-2 align-items-end mb-3">
        <div class="col-md-2"><label class="form-label small fw-bold">ตั้งแต่</label><input type="date" id="logStart" class="form-control form-control-sm" value="<?= htmlspecialchars($log_start) ?>"></div>
        <div class="col-md-2"><label class="form-label small fw-bold">ถึง</label><input type="date" id="logEnd" class="form-control form-control-sm" value="<?= htmlspecialchars($default_date) ?>"></div>
        <div class="col-md-2"><label class="form-label small fw-bold">ประเภท</label>
            <select id="logPtype" class="form-select form-select-sm"><option value="">ทั้งหมด</option><option value="OPD">OPD</option><option value="IPD">IPD</option></select>
        </div>
        <div class="col-md-2"><label class="form-label small fw-bold">สถานะ</label>
            <select id="logFilter" class="form-select form-select-sm">
                <option value="">ทั้งหมด</option><option value="pending">รอ Print</option><option value="printed">พิมพ์แล้ว</option>
                <option value="done">ทำแล้ว</option><option value="not_done">ไม่ได้ทำ</option>
            </select>
        </div>
        <div class="col-md-2"><button type="button" class="btn btn-success btn-sm w-100" id="btnLoadLogs"><i class="fas fa-search me-1"></i>ค้นหา</button></div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mrb-table mb-0">
            <thead class="small text-center"><tr>
                <th>วันที่</th><th>ประเภท</th><th>HN</th><th>ชื่อ</th><th>อำเภอ</th><th>%</th><th>Print</th><th>Intervention</th><th>ผู้บันทึก</th>
            </tr></thead>
            <tbody id="logBody"><tr><td colspan="9" class="text-center text-muted py-3">กดค้นหา</td></tr></tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($tab === 'settings'): ?>
    <div id="settingsPanels"></div>
<?php endif; ?>

        </div>
    </div>
    <div id="printArea" class="p-4"></div>
</div>
</div>
</div>

<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title fw-bold" id="detailTitle">รายละเอียด Refer Back</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="detailSummary" class="small mb-3"></div>
                <div id="detailLoading" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin"></i> กำลังโหลด...</div>
                <div id="detailError" class="alert alert-danger d-none"></div>
                <table class="table table-sm d-none" id="detailTable"><thead><tr>
                    <th>รายการยา</th><th class="text-center">ผลเทียบ</th><th id="detailColMatch">ยาในบัญชี รพ.</th><th class="text-center">คะแนน</th>
                </tr></thead><tbody id="detailBody"></tbody></table>
            </div>
            <div class="modal-footer py-2 no-print">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnPrintDetail"><i class="fas fa-print me-1"></i>พิมพ์</button>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">ปิด</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="ivModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-2"><h5 class="modal-title">บันทึก Intervention Refer Back</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" id="ivCaseId">
                <div class="mb-2"><label class="form-label small fw-bold">สถานะ</label>
                    <select id="ivStatus" class="form-select form-select-sm"><option value="done">ทำ Refer Back แล้ว</option><option value="not_done">ไม่ได้ทำ</option></select>
                </div>
                <div class="mb-2"><label class="form-label small fw-bold">หมายเหตุ</label><textarea id="ivNote" class="form-control form-control-sm" rows="3"></textarea></div>
            </div>
            <div class="modal-footer py-2"><button type="button" class="btn btn-success btn-sm" id="btnSaveIv">บันทึก</button></div>
        </div>
    </div>
</div>

<script src="js/jquery-3.7.0.min.js"></script>
<script src="js/bootstrap.bundle.min.js"></script>
<script src="js/drug_refer_match_print.js"></script>
<?php if ($tab === 'settings'): ?>
<script src="js/select2.min.js"></script>
<?php endif; ?>
<script>
const MRB_TAB = <?= json_encode($tab) ?>;
const MRB_PTYPE = <?= json_encode($patient_type) ?>;
let screenData = [];
let prevPendingKeys = new Set();
let isFirstLoad = true;
let lastPrintData = null;
let currentCase = null;
let screenLoadedOnce = false;
let mrbRefreshTimer = null;
let mrbAutoRefreshCountdownTimer = null;
let mrbAutoRefreshSecLeft = 0;
let mrbAudioCtx = null;
let mrbFetchAbort = null;
let mrbTimeWindow = '';

const MRB_TIME_TABS = [
    { w: '5m', label: '5 นาที' },
    { w: '15m', label: '15 นาที' },
    { w: '30m', label: '30 นาที' },
    { w: '1h', label: '1 ชั่วโมง' },
    { w: '3h', label: '3 ชั่วโมง' },
    { w: '6h', label: '6 ชั่วโมง' },
    { w: 'full', label: 'ทั้งวัน' }
];
const MRB_TIME_LABELS = {
    '5m': '5 นาที', '15m': '15 นาที', '30m': '30 นาที',
    '1h': '1 ชั่วโมง', '3h': '3 ชั่วโมง', '6h': '6 ชั่วโมง', 'full': 'ทั้งวัน'
};

function mrbTodayBangkok() {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Bangkok' }).format(new Date());
}

function mrbIsScreenDateToday() {
    return ($('#screenDate').val() || '') === mrbTodayBangkok();
}

function mrbDefaultTimeWindow() {
    return mrbIsScreenDateToday() ? '5m' : 'full';
}

function mrbUpdateTimeTabs(forceDefault) {
    const isToday = mrbIsScreenDateToday();
    const tabs = isToday ? MRB_TIME_TABS : MRB_TIME_TABS.filter(t => t.w === 'full');
    const allowed = tabs.map(t => t.w);
    if (forceDefault || !allowed.includes(mrbTimeWindow)) {
        mrbTimeWindow = mrbDefaultTimeWindow();
        localStorage.setItem(mrbLsKey('time_window'), mrbTimeWindow);
    }
    let html = '';
    tabs.forEach(t => {
        const active = t.w === mrbTimeWindow ? ' active' : '';
        html += '<button type="button" class="btn mrb-time-tab' + active + '" data-window="' + t.w + '">' + t.label + '</button>';
    });
    $('#mrbTimeTabs').html(html);
    $('#mrbTimeHint').text(isToday
        ? 'วันนี้ — เลือกช่วงล่าสุดเพื่อโหลดเร็วและแจ้งเตือนทัน (แนะนำ 5 นาที)'
        : 'ย้อนหลัง — แสดงทั้งวันเท่านั้น');
}

function esc(s) { return $('<div>').text(s ?? '').html(); }

function mrbCanonicalHn(hn) {
    hn = String(hn || '').trim();
    if (!hn) return '';
    if (!/^\d+$/.test(hn)) return hn;
    const stripped = hn.replace(/^0+/, '') || '0';
    return stripped.padStart(9, '0');
}

function mrbDrugReferUrl(hn) {
    const c = mrbCanonicalHn(hn);
    return c ? ('drug_refer.php?hn=' + encodeURIComponent(c)) : 'drug_refer.php';
}

function mrbFormatMatchColHeader(hospital) {
    return DrugReferMatchPrint.formatMatchColHeader(hospital);
}

function mrbLsKey(suffix) { return 'mrb_' + suffix + '_' + MRB_PTYPE; }

function mrbReadSavedDeptCodes() {
    const raw = localStorage.getItem(mrbLsKey('dept_codes'));
    if (raw) {
        try {
            const arr = JSON.parse(raw);
            if (Array.isArray(arr)) return arr.map(String).filter(Boolean);
        } catch (e) {}
    }
    const legacy = localStorage.getItem(mrbLsKey('dept_code'));
    return legacy ? [String(legacy)] : [];
}

function mrbSaveDeptCodes(codes) {
    const list = (codes || []).map(String).filter(Boolean);
    localStorage.setItem(mrbLsKey('dept_codes'), JSON.stringify(list));
    localStorage.removeItem(mrbLsKey('dept_code'));
}

function mrbGetSelectedDeptCodes() {
    const codes = [];
    $('#mrbOpdDeptList .mrb-dept-cb:checked').each(function() {
        codes.push(String($(this).val()));
    });
    return codes;
}

function mrbUpdateDeptFilterSummary() {
    const $btn = $('#mrbDeptFilterBtn');
    if (!$btn.length) return;
    const codes = mrbGetSelectedDeptCodes();
    if (!codes.length) {
        $btn.text('ทุกแผนก');
        return;
    }
    const names = [];
    $('#mrbOpdDeptList .mrb-dept-cb:checked').each(function() {
        names.push(String($(this).data('name') || $(this).val()));
    });
    if (names.length <= 2) {
        $btn.text(names.join(', '));
    } else {
        $btn.text(names.length + ' แผนก');
    }
}

function mrbLoadOpdDepartments() {
    if (MRB_PTYPE !== 'OPD' || !$('#mrbOpdDeptList').length) {
        return Promise.resolve();
    }
    const savedSet = new Set(mrbReadSavedDeptCodes());
    $('#mrbOpdDeptList').html('<div class="text-muted py-1"><i class="fas fa-spinner fa-spin me-1"></i>กำลังโหลด...</div>');
    return fetch('fetch_monitor_refer_back_api.php?' + new URLSearchParams({
        action: 'opd_departments',
        screen_date: $('#screenDate').val() || ''
    }), { credentials: 'same-origin', cache: 'no-store' })
    .then(r => r.json())
    .then(res => {
        const list = res.departments || [];
        if (!list.length) {
            $('#mrbOpdDeptList').html('<div class="text-muted py-1">ไม่พบ VN ในวันนี้</div>');
            mrbUpdateDeptFilterSummary();
            return;
        }
        let html = '';
        list.forEach((d, idx) => {
            const code = String(d.dept_code || '');
            const name = String(d.dept_name || code);
            const cnt = parseInt(d.visit_count, 10) || 0;
            const checked = savedSet.has(code) ? ' checked' : '';
            const onCls = checked ? ' mrb-dept-on' : '';
            const cid = 'mrbDept_' + idx;
            html += '<div class="form-check' + onCls + '">' +
                '<input class="form-check-input mrb-dept-cb" type="checkbox" value="' + esc(code) + '" id="' + cid + '" data-name="' + esc(name) + '"' + checked + '>' +
                '<label class="form-check-label" for="' + cid + '">' + esc(name) + (cnt ? ' <span class="text-muted">(' + cnt + ' VN)</span>' : '') + '</label>' +
                '</div>';
        });
        $('#mrbOpdDeptList').html(html);
        const validSaved = mrbGetSelectedDeptCodes();
        if (validSaved.length !== savedSet.size) {
            mrbSaveDeptCodes(validSaved);
        }
        mrbUpdateDeptFilterSummary();
    })
    .catch(() => {
        $('#mrbOpdDeptList').html('<div class="text-danger py-1">โหลดแผนกไม่สำเร็จ</div>');
    });
}

function mrbPlayAlertSound() {
    if (!$('#mrbAlertSound').is(':checked')) return;
    try {
        if (!mrbAudioCtx) {
            mrbAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
        }
        const playTone = (freq, start, dur, vol) => {
            const o = mrbAudioCtx.createOscillator();
            const g = mrbAudioCtx.createGain();
            o.type = 'sine'; o.frequency.value = freq;
            g.gain.setValueAtTime(0, mrbAudioCtx.currentTime + start);
            g.gain.linearRampToValueAtTime(vol, mrbAudioCtx.currentTime + start + 0.02);
            g.gain.exponentialRampToValueAtTime(0.001, mrbAudioCtx.currentTime + start + dur);
            o.connect(g); g.connect(mrbAudioCtx.destination);
            o.start(mrbAudioCtx.currentTime + start);
            o.stop(mrbAudioCtx.currentTime + start + dur + 0.05);
        };
        playTone(1318.5, 0, 0.42, 0.32);
        playTone(987.8, 0.58, 0.72, 0.36);
        if (window.speechSynthesis) {
            setTimeout(() => {
                const u = new SpeechSynthesisUtterance('Refer Back alert');
                u.lang = 'en-US'; u.rate = 0.95;
                window.speechSynthesis.speak(u);
            }, 1200);
        }
    } catch (e) {}
}
document.body.addEventListener('click', function() {
    if (mrbAudioCtx && mrbAudioCtx.state === 'suspended') mrbAudioCtx.resume();
}, { once: true });

function printStatusHtml(st) {
    if (st === 'printed') return '<span class="badge mrb-badge-printed"><i class="fas fa-check me-1"></i>พิมพ์แล้ว</span>';
    return '<span class="badge mrb-badge-pending"><i class="fas fa-clock me-1"></i>รอ Print</span>';
}
function ivStatusHtml(st) {
    if (st === 'done') return '<span class="badge bg-success">ทำแล้ว</span>';
    if (st === 'not_done') return '<span class="badge bg-warning text-dark">ไม่ได้ทำ</span>';
    return '<span class="text-muted small">-</span>';
}

function fetchScreen(opts) {
    opts = opts || {};
    if (mrbFetchAbort) {
        try { mrbFetchAbort.abort(); } catch (e) {}
    }
    mrbFetchAbort = new AbortController();
    const signal = mrbFetchAbort.signal;
    $('#btnLoad').prop('disabled', true);
    if (!opts.silent) {
        $('#screenBody').html('<tr><td colspan="10" class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin me-2"></i>กำลังโหลดและเทียบบัญชี... อาจใช้เวลาสักครู่</td></tr>');
    } else {
        $('#mrbRefreshHint').removeClass('d-none');
    }
    fetch('fetch_monitor_refer_back_api.php?_=' + Date.now(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Cache-Control': 'no-cache' },
        credentials: 'same-origin',
        cache: 'no-store',
        signal: signal,
        body: JSON.stringify({
            action: 'list',
            patient_type: MRB_PTYPE,
            screen_date: $('#screenDate').val(),
            time_window: mrbTimeWindow,
            dept_codes: MRB_PTYPE === 'OPD' ? mrbGetSelectedDeptCodes() : []
        })
    })
    .then(r => r.json())
    .then(res => {
        if (signal.aborted) return;
        $('#btnLoad').prop('disabled', false);
        $('#mrbRefreshHint').addClass('d-none');
        if (res.status !== 'success') {
            if (!opts.silent) {
                $('#screenBody').html('<tr><td colspan="10" class="text-danger text-center">' + esc(res.message) + '</td></tr>');
            }
            return;
        }
        if (res.time_window) {
            mrbTimeWindow = res.time_window;
            localStorage.setItem(mrbLsKey('time_window'), mrbTimeWindow);
            $('#mrbTimeTabs .mrb-time-tab').removeClass('active');
            $('#mrbTimeTabs .mrb-time-tab[data-window="' + mrbTimeWindow + '"]').addClass('active');
        }
        screenData = res.patients || [];
        res.fetched_at = new Date();
        renderScreen(res);
        screenLoadedOnce = true;
        if (opts.isAuto) {
            const sec = mrbGetAutoRefreshSec();
            if (sec > 0) mrbAutoRefreshSecLeft = sec;
        }
        mrbUpdateRefreshStatusText();
        const pendingKeys = new Set(screenData.filter(p => p.print_status === 'pending').map(p => p.case_uid));
        let newPending = false;
        if (!isFirstLoad) {
            pendingKeys.forEach(k => { if (!prevPendingKeys.has(k)) newPending = true; });
        }
        if (newPending) mrbPlayAlertSound();
        prevPendingKeys = pendingKeys;
        isFirstLoad = false;
    })
    .catch(err => {
        if (err && err.name === 'AbortError') return;
        $('#btnLoad').prop('disabled', false);
        $('#mrbRefreshHint').addClass('d-none');
        if (!opts.silent) {
            $('#screenBody').html('<tr><td colspan="10" class="text-danger text-center">เชื่อมต่อ API ไม่สำเร็จ</td></tr>');
        }
    })
    .finally(() => {
        if (signal.aborted) return;
        mrbFetchAbort = null;
    });
}

function mrbFormatTime(t) {
    t = (t || '').trim();
    if (!t || t === '00:00:00') return '-';
    const m = t.match(/^(\d{1,2}):(\d{2})/);
    return m ? (m[1].padStart(2, '0') + ':' + m[2]) : t;
}

function mrbFormatMatchCell(row) {
    if (row.match_percent === null || row.match_percent === undefined) return '-';
    const pct = row.match_percent + '%';
    const matched = parseInt(row.match_matched, 10) || 0;
    const total = parseInt(row.match_total, 10) || 0;
    if (total > 0) {
        return pct + '<br><small class="text-muted fw-normal">' + matched + '/' + total + '</small>';
    }
    return pct;
}

function renderScreen(res) {
    const patients = res.patients || [];
    let printed = 0, pending = 0;
    const $body = $('#screenBody').empty();
    if (!patients.length) {
        $body.html('<tr><td colspan="10" class="text-center text-muted py-4">ไม่พบผู้ป่วยตามเกณฑ์ที่ตั้งไว้</td></tr>');
    }
    patients.forEach(p => {
        if (p.print_status === 'printed') printed++; else pending++;
        const rowCls = p.print_status === 'printed' ? 'mrb-row-printed' : 'mrb-row-pending';
        const time = mrbFormatTime(p.vsttime);
        const pct = mrbFormatMatchCell(p);
        const refNote = p.ref_visit_label
            ? '<br><small class="text-muted" title="' + (MRB_PTYPE === 'IPD' ? 'รอบสั่งยา HME' : 'Visit อ้างอิงยา') + '">↩ ' + esc(p.ref_visit_label) + '</small>'
            : '';
        const deptNote = (MRB_PTYPE === 'OPD' && p.dept_name)
            ? '<br><small class="text-muted"><i class="fas fa-clinic-medical me-1"></i>' + esc(p.dept_name) + '</small>'
            : '';
        $body.append('<tr class="' + rowCls + '" data-case="' + p.case_id + '">' +
            '<td class="text-center small">' + time + '</td>' +
            '<td class="text-center">' + esc(p.hn) + '</td>' +
            '<td>' + esc(p.ptname) + refNote + deptNote + (p.ward_display ? '<br><small class="text-muted">' + esc(p.ward_display) + '</small>' : '') + '</td>' +
            '<td class="small">' + esc(p.amp_name || '-') + '</td>' +
            '<td class="small">' + esc(p.pttype_name || '-') + '</td>' +
            '<td class="small">' + esc(p.match_hospital || '-') + '</td>' +
            '<td class="text-center fw-bold">' + pct + '</td>' +
            '<td class="text-center">' + printStatusHtml(p.print_status) + '</td>' +
            '<td class="text-center">' + ivStatusHtml(p.intervention_status) + '</td>' +
            '<td class="text-nowrap text-center">' +
            '<button type="button" class="btn btn-sm btn-outline-primary btn-detail me-1" title="ดูรายละเอียด"><i class="fas fa-eye"></i></button>' +
            '<button type="button" class="btn btn-sm btn-outline-success btn-print me-1" title="พิมพ์"><i class="fas fa-print"></i></button>' +
            '<button type="button" class="btn btn-sm btn-outline-secondary btn-iv me-1" title="Intervention"><i class="fas fa-notes-medical"></i></button>' +
            '<a href="' + mrbDrugReferUrl(p.hn) + '" class="btn btn-sm btn-outline-info" title="Refer ยาผู้ป่วย"><i class="fas fa-share-square"></i></a>' +
            '</td></tr>');
        $body.find('tr:last').data('patient', p);
    });
    $('#badgeTotal').text(patients.length + ' ราย');
    $('#badgePending').text('รอ Print ' + pending);
    $('#badgePrinted').text('พิมพ์แล้ว ' + printed);
    if (pending > 0) {
        $('#mrbAlertBanner').removeClass('d-none');
        $('#mrbAlertText').html('พบผู้ป่วย <strong>รอ Print Refer Back ' + pending + ' ราย</strong>');
    } else {
        $('#mrbAlertBanner').addClass('d-none');
    }
    const s = res.settings || {};
    const pf = res.pttype_filter || {};
    let meta = 'ช่วง <strong>' + esc(res.time_window_label || MRB_TIME_LABELS[mrbTimeWindow] || mrbTimeWindow) + '</strong>';
    if (res.monitor_mode === 'ipd_hme_order' || MRB_PTYPE === 'IPD') {
        meta += ' | สั่งยา HME ' + (res.visit_total || 0) + ' → เทียบยา ' + (res.scanned_total || 0);
    } else {
        meta += ' | เปิด Visit ' + (res.visit_total || 0) + ' → อ้างอิงยาได้ ' + (res.scanned_total || 0);
    }
    meta += ' → หลังกรองสิทธิ ' + (res.after_pttype || 0) + ' → แสดง ' + (res.total || 0) + ' ราย';
    meta += '<br>เกณฑ์ % เทียบบัญชี ≥ <strong>' + (s.min_match_percent || 80) + '%</strong>';
    meta += ' | สิทธิ: ' + esc(pf.label || s.pttype_mode || '-');
    meta += ' | ตัดอำเภอ เมืองสระแก้ว';
    if (MRB_PTYPE === 'OPD' && res.dept_filter && res.dept_filter.codes && res.dept_filter.codes.length) {
        meta += ' | แผนก: <strong>' + esc(res.dept_filter.label || res.dept_filter.codes.join(', ')) + '</strong>';
    }
    if (res.monitor_mode === 'ipd_hme_order' || MRB_PTYPE === 'IPD') {
        meta += ' | เวลาสั่งยา HME | เทียบยาเฉพาะรอบนั้น';
    } else {
        meta += ' | เวลาเปิด Visit | ยาอ้างอิง VN/HME ล่าสุด 1 ปี';
    }
    meta += ' | โหลด ' + (res.elapsed_sec || '-') + ' วินาที';
    if (res.fetched_at) {
        meta += ' | อัปเดตล่าสุด <strong>' + esc(res.fetched_at.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit', second: '2-digit' })) + '</strong>';
    }
    if (res.server_time) {
        meta += ' | เวลาเซิร์ฟเวอร์ <strong>' + esc(res.server_time) + '</strong>';
    }
    const refreshSec = mrbGetAutoRefreshSec();
    if (refreshSec > 0 && mrbIsScreenDateToday()) {
        meta += ' | auto refresh ถัดไปใน <strong id="mrbRefreshCountdown">' + mrbFormatCountdown(mrbAutoRefreshSecLeft || refreshSec) + '</strong>';
    }
    $('#screenMeta').removeClass('d-none').html(meta);
}

function mrbGetAutoRefreshSec() {
    if (!mrbIsScreenDateToday()) return 0;
    return parseInt(localStorage.getItem(mrbLsKey('auto_refresh')) || $('#mrbAutoRefresh').val(), 10) || 0;
}

function mrbFormatCountdown(sec) {
    const m = Math.floor(sec / 60);
    const s = sec % 60;
    return m + ':' + String(s).padStart(2, '0');
}

function mrbUpdateRefreshStatusText() {
    const sec = mrbGetAutoRefreshSec();
    const $cd = $('#mrbRefreshCountdown');
    if (!$cd.length) return;
    if (sec <= 0) {
        $cd.text('-');
        return;
    }
    $cd.text(mrbFormatCountdown(mrbAutoRefreshSecLeft));
}

function mrbStopAutoRefresh() {
    if (mrbRefreshTimer) {
        clearInterval(mrbRefreshTimer);
        mrbRefreshTimer = null;
    }
    if (mrbAutoRefreshCountdownTimer) {
        clearInterval(mrbAutoRefreshCountdownTimer);
        mrbAutoRefreshCountdownTimer = null;
    }
}

function mrbStartAutoRefresh() {
    mrbStopAutoRefresh();
    const sec = mrbGetAutoRefreshSec();
    mrbUpdateRefreshStatusText();
    if (sec <= 0 || !mrbIsScreenDateToday()) return;
    mrbAutoRefreshSecLeft = sec;
    mrbAutoRefreshCountdownTimer = setInterval(function() {
        if (document.hidden) return;
        mrbAutoRefreshSecLeft = Math.max(0, mrbAutoRefreshSecLeft - 1);
        mrbUpdateRefreshStatusText();
    }, 1000);
    mrbRefreshTimer = setInterval(function() {
        if (document.hidden) return;
        if (!mrbIsScreenDateToday()) {
            mrbStopAutoRefresh();
            return;
        }
        mrbAutoRefreshSecLeft = sec;
        mrbUpdateRefreshStatusText();
        fetchScreen({ silent: true, isAuto: true });
    }, sec * 1000);
}

function openDetail(p) {
    currentCase = p;
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('detailModal'));
    $('#detailTitle').text('Refer Back — HN ' + (p.hn || ''));
    $('#detailSummary').empty();
    $('#detailTable').addClass('d-none');
    $('#detailError').addClass('d-none');
    $('#detailLoading').removeClass('d-none');
    $('#detailBody').empty();
    $('#detailColMatch').text('ยาในบัญชี รพ.');
    lastPrintData = null;
    modal.show();
    fetch('fetch_monitor_refer_back_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'match_detail', patient_type: MRB_PTYPE,
            hn: p.hn_full || p.hn, amp_name: p.amp_name,
            vn: p.ref_vn, an: p.ref_an, ref_visit_type: p.ref_visit_type,
            ref_visit_label: p.ref_visit_label || '',
            ref_hme_only: !!p.ref_hme_only,
            hme_order_no: p.hme_order_no || '',
            hme_rxdate: p.hme_rxdate || '',
            hme_rxtime: p.hme_rxtime || '',
            case_id: p.case_id
        })
    })
    .then(r => r.json())
    .then(res => {
        $('#detailLoading').addClass('d-none');
        if (res.status !== 'success') {
            $('#detailError').removeClass('d-none').text(res.message || 'โหลดไม่สำเร็จ');
            return;
        }
        lastPrintData = res;
        const s = res.summary || {};
        const pt = res.patient || {};
        let sum = '<strong>' + esc(pt.full_name || '') + '</strong> | HN: ' + esc(pt.hn || '');
        if (p.ref_visit_label) sum += '<br>อ้างอิงยา: <strong>' + esc(p.ref_visit_label) + '</strong>';
        sum += '<br>เทียบกับ <strong>' + esc(res.hospital || '') + '</strong>';
        sum += ' | คะแนน <strong>' + (s.match_percent || 0) + '%</strong> (' + (s.matched || 0) + '/' + (s.total || 0) + ')';
        $('#detailSummary').html(sum);
        $('#detailColMatch').html(mrbFormatMatchColHeader(res.hospital || ''));
        let body = '';
        (res.rows || []).forEach(r => {
            const m = r.match || {};
            const st = mrbFormatDetailMatchStatus(m);
            const matchedName = m.substitute_note
                ? esc(m.matched_display_primary || m.matched_tmtdrug || '-') + '<br><small class="text-muted">↔ ' + esc(m.substitute_via || '') + '</small>'
                : esc(m.matched_display_primary || m.matched_tmtdrug || '-');
            body += '<tr><td>' + esc(r.display_primary || r.tmtdrug || '') + '</td><td class="text-center">' + st + '</td><td>' + matchedName + '</td><td class="text-center">' + (m.status === 'exact' ? '100' : (m.score || '-')) + '</td></tr>';
        });
        $('#detailBody').html(body);
        $('#detailTable').removeClass('d-none');
    });
}

function buildPrintHtml(res) {
    return DrugReferMatchPrint.buildMatchDetailPrintHtml(res, {
        footerLabel: 'THAM Hos-Safe — Monitor Refer Back'
    });
}

function mrbFormatDetailMatchStatus(m) {
    if (!m || typeof m !== 'object') return '<span class="text-danger">ไม่พบ</span>';
    if (m.status === 'exact' && m.substitute_note) {
        return '<span class="text-success">ตรง (ทดแทน)</span><br><small class="text-muted">' + esc(m.substitute_note) + '</small>';
    }
    if (m.status === 'exact') return '<span class="text-success">ตรง</span>';
    if (m.status === 'similar') return '<span class="text-warning">ใกล้เคียง</span>';
    return '<span class="text-danger">ไม่พบ</span>';
}

function doPrint(caseId) {
    if (!lastPrintData) return;
    $('#printArea').html(buildPrintHtml(lastPrintData));
    setTimeout(() => window.print(), 150);
    if (!caseId) return;
    fetch('fetch_monitor_refer_back_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'mark_printed', case_id: caseId })
    })
    .then(r => r.json())
    .then(() => fetchScreen({ silent: true }));
}

function setupAutoRefresh() {
    mrbStartAutoRefresh();
}

if (MRB_TAB === 'opd' || MRB_TAB === 'ipd') {
    const savedDate = localStorage.getItem(mrbLsKey('screen_date'));
    if (savedDate) $('#screenDate').val(savedDate);
    mrbTimeWindow = localStorage.getItem(mrbLsKey('time_window')) || mrbDefaultTimeWindow();
    const savedRefresh = localStorage.getItem(mrbLsKey('auto_refresh'));
    if (savedRefresh !== null) $('#mrbAutoRefresh').val(savedRefresh);
    mrbUpdateTimeTabs(false);
    $('#mrbAlertSound').prop('checked', localStorage.getItem(mrbLsKey('sound')) !== '0');
    $('#mrbAlertSound').on('change', function() { localStorage.setItem(mrbLsKey('sound'), $(this).is(':checked') ? '1' : '0'); });
    $('#mrbAutoRefresh').on('change', function() {
        localStorage.setItem(mrbLsKey('auto_refresh'), $(this).val());
        mrbStartAutoRefresh();
    });
    if (MRB_PTYPE === 'OPD') {
        $(document).on('change', '#mrbOpdDeptList .mrb-dept-cb', function() {
            $(this).closest('.form-check').toggleClass('mrb-dept-on', $(this).is(':checked'));
            mrbSaveDeptCodes(mrbGetSelectedDeptCodes());
            mrbUpdateDeptFilterSummary();
            if (screenLoadedOnce) fetchScreen();
        });
        $('#mrbDeptSelectAll').on('click', function() {
            $('#mrbOpdDeptList .mrb-dept-cb').prop('checked', true);
            $('#mrbOpdDeptList .form-check').addClass('mrb-dept-on');
            mrbSaveDeptCodes(mrbGetSelectedDeptCodes());
            mrbUpdateDeptFilterSummary();
            if (screenLoadedOnce) fetchScreen();
        });
        $('#mrbDeptClearAll').on('click', function() {
            $('#mrbOpdDeptList .mrb-dept-cb').prop('checked', false);
            $('#mrbOpdDeptList .form-check').removeClass('mrb-dept-on');
            mrbSaveDeptCodes([]);
            mrbUpdateDeptFilterSummary();
            if (screenLoadedOnce) fetchScreen();
        });
    }
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden && mrbGetAutoRefreshSec() > 0 && mrbIsScreenDateToday()) {
            fetchScreen({ silent: true, isAuto: true });
            mrbStartAutoRefresh();
        }
    });
    $('#screenDate').on('change', function() {
        localStorage.setItem(mrbLsKey('screen_date'), $(this).val());
        mrbUpdateTimeTabs(true);
        if (!mrbIsScreenDateToday()) {
            $('#mrbAutoRefresh').val('0');
            mrbStopAutoRefresh();
        } else if (localStorage.getItem(mrbLsKey('auto_refresh')) === null) {
            $('#mrbAutoRefresh').val('5');
        }
        const reload = function() {
            if (screenLoadedOnce) fetchScreen();
        };
        if (MRB_PTYPE === 'OPD') {
            mrbLoadOpdDepartments().then(reload);
        } else {
            reload();
        }
    });
    $(document).on('click', '.mrb-time-tab', function() {
        const w = $(this).data('window');
        if (!w || w === mrbTimeWindow) return;
        mrbTimeWindow = w;
        localStorage.setItem(mrbLsKey('time_window'), w);
        mrbUpdateTimeTabs(false);
        fetchScreen();
    });
    $(document).on('click', '.btn-detail', function() { openDetail($(this).closest('tr').data('patient')); });
    $(document).on('click', '.btn-print', function() {
        const p = $(this).closest('tr').data('patient');
        openDetail(p);
        setTimeout(() => { if (lastPrintData) doPrint(p.case_id); }, 800);
    });
    $(document).on('click', '.btn-iv', function() {
        const p = $(this).closest('tr').data('patient');
        $('#ivCaseId').val(p.case_id);
        $('#ivStatus').val(p.intervention_status === 'not_done' ? 'not_done' : 'done');
        $('#ivNote').val(p.intervention_note || '');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('ivModal')).show();
    });
    $('#btnPrintDetail').on('click', () => doPrint(currentCase ? currentCase.case_id : 0));
    $('#btnSaveIv').on('click', function() {
        fetch('fetch_monitor_refer_back_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'save_intervention',
                case_id: parseInt($('#ivCaseId').val(), 10),
                intervention_status: $('#ivStatus').val(),
                intervention_note: $('#ivNote').val()
            })
        }).then(r => r.json()).then(res => {
            if (res.status === 'success') {
                bootstrap.Modal.getInstance(document.getElementById('ivModal')).hide();
                if (MRB_TAB === 'interventions') loadLogs(); else fetchScreen({ silent: true });
            } else alert(res.message || 'บันทึกไม่สำเร็จ');
        });
    });
    $('#btnLoad').on('click', () => fetchScreen());
    const mrbStartScreen = function() {
        mrbStartAutoRefresh();
        fetchScreen();
    };
    if (MRB_PTYPE === 'OPD') {
        mrbLoadOpdDepartments().then(mrbStartScreen);
    } else {
        mrbStartScreen();
    }
}

function loadLogs() {
    $('#logBody').html('<tr><td colspan="9" class="text-center text-muted py-3"><i class="fas fa-spinner fa-spin me-2"></i>กำลังโหลด...</td></tr>');
    fetch('fetch_monitor_refer_back_api.php?' + new URLSearchParams({
        action: 'intervention_logs',
        start_date: $('#logStart').val(),
        end_date: $('#logEnd').val(),
        patient_type: $('#logPtype').val(),
        status_filter: $('#logFilter').val()
    }))
    .then(r => r.json())
    .then(res => {
        if (res.status !== 'success') {
            $('#logBody').html('<tr><td colspan="9" class="text-danger text-center py-3">' + esc(res.message || 'โหลดไม่สำเร็จ') + '</td></tr>');
            return;
        }
        const st = res.stats || {};
        $('#statTotal').text(st.total || 0);
        $('#statPrinted').text(st.printed || 0);
        $('#statPending').text(st.pending_print || 0);
        $('#statDone').text(st.done || 0);
        $('#statNotDone').text(st.not_done || 0);
        const $b = $('#logBody').empty();
        (res.logs || []).forEach(row => {
            $b.append('<tr><td class="text-center small">' + esc(row.screen_date) + '</td>' +
                '<td class="text-center">' + esc(row.patient_type) + '</td>' +
                '<td class="text-center">' + esc(row.hn) + '</td>' +
                '<td>' + esc(row.ptname) + '</td>' +
                '<td class="small">' + esc(row.amp_name || '-') + '</td>' +
                '<td class="text-center">' + mrbFormatMatchCell(row) + '</td>' +
                '<td class="text-center">' + printStatusHtml(row.print_status) + '</td>' +
                '<td class="text-center">' + ivStatusHtml(row.intervention_status) + '</td>' +
                '<td class="small">' + esc(row.intervention_by_name || row.printed_by_name || '-') + '</td></tr>');
        });
        if (!(res.logs || []).length) $b.html('<tr><td colspan="9" class="text-center text-muted py-3">ไม่พบข้อมูล</td></tr>');
    })
    .catch(() => {
        $('#logBody').html('<tr><td colspan="9" class="text-danger text-center py-3">โหลดไม่สำเร็จ</td></tr>');
    });
}
if (MRB_TAB === 'interventions') {
    $('#btnLoadLogs').on('click', loadLogs);
    loadLogs();
}

function renderSettingsPanel(ptype, data) {
    const s = data.settings || {};
    const opts = data.pttype_options || [];
    const mode = s.pttype_mode || 'default';
    const isCustom = mode === 'custom';
    const effective = isCustom ? (s.pttype_codes_array || []) : (data.effective_pttypes || []);
    const effectiveSet = new Set((effective || []).map(String));
    let pttypeHtml = '<div class="small text-muted mb-1 mrb-pttype-hint" data-ptype="' + ptype + '">';
    if (isCustom) {
        pttypeHtml += '<i class="fas fa-edit me-1"></i>เลือกสิทธิที่ต้องการ — ติ๊กแล้วกดบันทึก (<strong class="text-success">' + effective.length + '</strong> รายการ)';
    } else {
        pttypeHtml += '<i class="fas fa-eye me-1"></i>สิทธิที่ใช้งานจริง — <strong class="text-success">' + effective.length + '</strong> รายการ';
        if (data.pttype_filter_label) pttypeHtml += ' | ' + esc(data.pttype_filter_label);
        pttypeHtml += ' <span class="text-muted">(เปลี่ยนโหมดเป็น "เลือกเอง" ถ้าต้องการแก้ไข)</span>';
    }
    pttypeHtml += '</div>';
    pttypeHtml += '<div class="row g-1 mrb-pttype-list" data-ptype="' + ptype + '" style="max-height:200px;overflow-y:auto">';
    opts.forEach(o => {
        const on = effectiveSet.has(String(o.pttype));
        const chk = on ? ' checked' : '';
        const dis = isCustom ? '' : ' disabled';
        const rowCls = on ? 'mrb-pttype-on' : 'mrb-pttype-off';
        const id = 'pt_' + ptype + '_' + o.pttype;
        pttypeHtml += '<div class="col-md-4"><div class="form-check ' + rowCls + '">' +
            '<input class="form-check-input st-pttype" type="checkbox" data-ptype="' + ptype + '" value="' + esc(o.pttype) + '" id="' + id + '"' + chk + dis + '>' +
            '<label class="form-check-label small" for="' + id + '">' + esc(o.name) + '</label></div></div>';
    });
    pttypeHtml += '</div>';
    return '<div class="card mb-3 mrb-settings-card" data-ptype="' + ptype + '"><div class="card-header fw-bold">' + ptype + '</div><div class="card-body">' +
        '<div class="row g-3">' +
        '<div class="col-md-2"><label class="form-label small fw-bold">% เทียบบัญชีขึ้นไป</label><input type="number" class="form-control form-control-sm st-min-pct" data-ptype="' + ptype + '" min="0" max="100" value="' + (s.min_match_percent || 80) + '"></div>' +
        '<div class="col-md-2"><label class="form-label small fw-bold">คะแนนใกล้เคียง</label><input type="number" class="form-control form-control-sm st-min-sim" data-ptype="' + ptype + '" min="50" max="100" value="' + (s.min_similar_score || 90) + '"></div>' +
        '<div class="col-md-3"><label class="form-label small fw-bold">สิทธิการรักษา</label><select class="form-select form-select-sm st-ptmode" data-ptype="' + ptype + '">' +
        '<option value="default"' + (mode === 'default' ? ' selected' : '') + '>นอกเขต (ค่าเริ่มต้น)</option>' +
        '<option value="inside"' + (mode === 'inside' ? ' selected' : '') + '>ในเขต</option>' +
        '<option value="all"' + (mode === 'all' ? ' selected' : '') + '>ทั้งหมด</option>' +
        '<option value="custom"' + (mode === 'custom' ? ' selected' : '') + '>เลือกเอง</option></select></div>' +
        '<div class="col-md-2"><div class="form-check form-switch mt-4"><input class="form-check-input st-enabled" type="checkbox" data-ptype="' + ptype + '"' + (s.enabled ? ' checked' : '') + '><label class="form-check-label small">เปิดใช้งาน</label></div></div>' +
        '</div>' +
        '<div class="form-check mt-2"><input class="form-check-input st-exclude-province" type="checkbox" data-ptype="' + ptype + '" id="exprov_' + ptype + '"' +
        ((s.exclude_outside_province === undefined || s.exclude_outside_province) ? ' checked' : '') +
        '><label class="form-check-label small" for="exprov_' + ptype + '">ตัดสิทธิ <strong>นอกเขต จังหวัด</strong> ออก (ใช้กับโหมดนอกเขต)</label></div>' +
        '<div class="mt-2 st-pttype-wrap" data-ptype="' + ptype + '">' + pttypeHtml + '</div>' +
        '<button type="button" class="btn btn-success btn-sm mt-2 btn-save-st" data-ptype="' + ptype + '"><i class="fas fa-save me-1"></i>บันทึก ' + ptype + '</button></div></div>';
}

function renderExcludeDrugsPanel() {
    return '<div class="card mb-3" id="mrbExcludeDrugsCard">' +
        '<div class="card-header fw-bold"><i class="fas fa-pills me-1"></i>ตัดรายการยาออกจากการเทียบบัญชี</div>' +
        '<div class="card-body">' +
        '<div class="small text-muted mb-2">เลือกยาแล้วติ๊กว่าจะตัดจาก OPD / IPD — ค่าเริ่มต้นตัดทั้งสองประเภท</div>' +
        '<label class="form-label small fw-bold mb-1">เพิ่มรายการยา</label>' +
        '<select id="mrbExcludeDrugAdd" class="form-select form-select-sm mb-3"></select>' +
        '<div class="table-responsive" style="max-height:320px;overflow-y:auto;">' +
        '<table class="table table-sm table-hover mrb-exclude-table mb-0">' +
        '<thead class="text-center"><tr><th class="text-start">รายการยา</th><th style="width:72px;">OPD</th><th style="width:72px;">IPD</th><th style="width:56px;"></th></tr></thead>' +
        '<tbody id="mrbExcludeDrugBody"></tbody></table></div>' +
        '<div class="small text-muted mt-2">ยาที่ติ๊กจะไม่นำมาคิด % เทียบบัญชี และไม่แสดงในใบพิมพ์</div>' +
        '<button type="button" class="btn btn-success btn-sm mt-2" id="btnSaveExcludeDrugs"><i class="fas fa-save me-1"></i>บันทึกรายการตัดยา</button>' +
        '</div></div>';
}

function renderSubstituteDrugsPanel() {
    return '<div class="card mb-3" id="mrbSubstituteDrugsCard">' +
        '<div class="card-header fw-bold"><i class="fas fa-exchange-alt me-1"></i>ยาทดแทนได้ (ถือว่าตรงกัน 100%)</div>' +
        '<div class="card-body">' +
        '<div class="small text-muted mb-2">กำหนดคู่ยา — ยาที่ 1 จาก HOSxP เทียบกับ <strong>tmtdrug ในบัญชียาเครือข่าย (รพ.ปลายทาง)</strong> ถือว่าตรงกัน <strong>100%</strong></div>' +
        '<div class="row g-2 mb-2">' +
        '<div class="col-md-5"><label class="form-label small fw-bold mb-1">ยาที่ 1 (HOSxP)</label><select id="mrbSubstituteDrugA" class="form-select form-select-sm"></select></div>' +
        '<div class="col-md-2 d-flex align-items-end justify-content-center pb-1"><span class="text-muted"><i class="fas fa-arrows-alt-h"></i></span></div>' +
        '<div class="col-md-5"><label class="form-label small fw-bold mb-1">ยาที่ 2 (รพ.ปลายทาง)</label><select id="mrbSubstituteDrugB" class="form-select form-select-sm"></select></div>' +
        '</div>' +
        '<div class="small text-muted mb-2">ค้นหายาที่ 2 ด้วย <strong>tmtdrug</strong> หรือ <strong>TPU code</strong> จากรายการบัญชียาเครือข่าย</div>' +
        '<button type="button" class="btn btn-outline-primary btn-sm mb-3" id="btnAddSubstitutePair"><i class="fas fa-plus me-1"></i>เพิ่มคู่ยาทดแทน</button>' +
        '<div class="table-responsive" style="max-height:320px;overflow-y:auto;">' +
        '<table class="table table-sm table-hover mrb-substitute-table mb-0">' +
        '<thead class="text-center"><tr><th class="text-start">ยาที่ 1 (HOSxP)</th><th style="width:40px;"></th><th class="text-start">ยาที่ 2 (รพ.ปลายทาง)</th><th style="width:56px;"></th></tr></thead>' +
        '<tbody id="mrbSubstituteDrugBody"></tbody></table></div>' +
        '<button type="button" class="btn btn-success btn-sm mt-2" id="btnSaveSubstituteDrugs"><i class="fas fa-save me-1"></i>บันทึกยาทดแทนได้</button>' +
        '</div></div>';
}

function mrbComputeEffectivePttypes(data, ptype) {
    const s = data.settings || {};
    const mode = $('.st-ptmode[data-ptype="' + ptype + '"]').val() || s.pttype_mode || 'default';
    const excludeProvince = $('.st-exclude-province[data-ptype="' + ptype + '"]').is(':checked');
    if (mode === 'custom') {
        const codes = [];
        $('.st-pttype[data-ptype="' + ptype + '"]:checked').each(function() { codes.push($(this).val()); });
        return codes;
    }
    if (mode === 'all') {
        return (data.pttype_options || []).map(o => String(o.pttype));
    }
    let codes = mode === 'inside' ? (data.inside_pttypes || []) : (data.default_pttypes || []);
    if (excludeProvince && mode !== 'inside') {
        codes = codes.filter(code => {
            const opt = (data.pttype_options || []).find(o => String(o.pttype) === String(code));
            const name = opt ? (opt.name || '') : '';
            return !(name.indexOf('นอกเขต') >= 0 && name.indexOf('จังหวัด') >= 0);
        });
    }
    return codes.map(String);
}

function mrbCleanDrugLabel(text) {
    return String(text || '').replace(/\s*\(icode:.*\)\s*$/i, '').trim();
}

function mrbRenderExcludeDrugRows() {
    const $b = $('#mrbExcludeDrugBody').empty();
    const list = window.mrbExcludeDrugs || [];
    if (!list.length) {
        $b.html('<tr><td colspan="4" class="text-center text-muted small py-3">ยังไม่มีรายการ — ค้นหาและเลือกยาด้านบน</td></tr>');
        return;
    }
    list.forEach(d => {
        const icode = esc(d.icode || '');
        const name = esc(d.name || d.icode || '');
        const opd = d.exclude_opd !== false ? ' checked' : '';
        const ipd = d.exclude_ipd !== false ? ' checked' : '';
        $b.append('<tr data-icode="' + icode + '">' +
            '<td class="small">' + name + '<br><span class="text-muted">icode: ' + icode + '</span></td>' +
            '<td class="text-center"><input type="checkbox" class="form-check-input ex-opd"' + opd + ' title="ตัด OPD"></td>' +
            '<td class="text-center"><input type="checkbox" class="form-check-input ex-ipd"' + ipd + ' title="ตัด IPD"></td>' +
            '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger btn-ex-rm" title="ลบ"><i class="fas fa-times"></i></button></td>' +
            '</tr>');
    });
}

function mrbInitExcludeDrugAdd() {
    mrbInitDrugSelect2('#mrbExcludeDrugAdd', 'ค้นหาและเลือกยาที่ต้องการตัดออก...');
}

function mrbInitDrugSelect2(selector, placeholder) {
    if (typeof $.fn.select2 !== 'function') return;
    const $sel = $(selector);
    if (!$sel.length) return;
    if ($sel.data('select2')) $sel.select2('destroy');
    $sel.empty().append(new Option('', '', true, true));
    $sel.select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: placeholder,
        allowClear: true,
        ajax: {
            url: 'fetch_hosxp_drugs_select_api.php',
            dataType: 'json',
            delay: 250,
            data: params => ({ q: params.term || '' }),
            processResults: data => data
        }
    });
}

function mrbParseDrugSelectItem(item) {
    const parts = String((item && item.id) || '').split('|');
    const icode = (parts[0] || '').trim();
    const name = mrbCleanDrugLabel(item && item.text) || parts.slice(1).join('|') || icode;
    return { icode: icode, name: name };
}

function mrbSubstitutePairKey(icodeA, tmtdrugB) {
    return String(icodeA || '') + '|' + String(tmtdrugB || '');
}

function mrbRenderSubstituteRows() {
    const $b = $('#mrbSubstituteDrugBody').empty();
    const list = window.mrbSubstitutePairs || [];
    if (!list.length) {
        $b.html('<tr><td colspan="4" class="text-center text-muted small py-3">ยังไม่มีคู่ยาทดแทน — เลือกยา HOSxP + tmtdrug บัญชีเครือข่าย แล้วกดเพิ่ม</td></tr>');
        return;
    }
    list.forEach((p, idx) => {
        const nameA = esc(p.name_a || p.icode_a || '');
        const tmtdrugB = esc(p.tmtdrug_b || '');
        const hospitalLabel = esc(p.hospital_label || '');
        const icodeA = esc(p.icode_a || '');
        $b.append('<tr data-idx="' + idx + '">' +
            '<td class="small">' + nameA + '<br><span class="text-muted">icode: ' + icodeA + '</span></td>' +
            '<td class="text-center text-muted"><i class="fas fa-exchange-alt"></i></td>' +
            '<td class="small">' + tmtdrugB +
            (hospitalLabel ? '<br><span class="text-muted"><i class="fas fa-hospital me-1"></i>' + hospitalLabel + '</span>' : '') +
            '</td>' +
            '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger btn-sub-rm" title="ลบ"><i class="fas fa-times"></i></button></td>' +
            '</tr>');
    });
}

function mrbInitSubstituteDrugSelects() {
    mrbInitDrugSelect2('#mrbSubstituteDrugA', 'ค้นหายา HOSxP...');
    mrbInitNetworkTmtdrugSelect2('#mrbSubstituteDrugB', 'ค้นหา tmtdrug / TPU จากบัญชียาเครือข่าย...');
}

function mrbInitNetworkTmtdrugSelect2(selector, placeholder) {
    if (typeof $.fn.select2 !== 'function') return;
    const $sel = $(selector);
    if (!$sel.length) return;
    if ($sel.data('select2')) $sel.select2('destroy');
    $sel.empty().append(new Option('', '', true, true));
    $sel.select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: placeholder,
        allowClear: true,
        ajax: {
            url: 'fetch_monitor_refer_back_api.php',
            dataType: 'json',
            delay: 250,
            data: params => ({ action: 'search_network_tmtdrug', q: params.term || '' }),
            processResults: data => data
        }
    });
}

function mrbParseNetworkTmtdrugItem(item) {
    const tmtdrug = String((item && (item.tmtdrug || item.id)) || '').trim();
    return {
        tmtdrug: tmtdrug,
        tpu_code_list: String((item && item.tpu_code_list) || '').trim(),
        hospital_label: String((item && item.hospital_label) || '').trim(),
        hospitals: Array.isArray(item && item.hospitals) ? item.hospitals : []
    };
}

function mrbCollectSubstitutePairs() {
    return (window.mrbSubstitutePairs || []).map(p => ({
        icode_a: String(p.icode_a || ''),
        tmtdrug_b: String(p.tmtdrug_b || ''),
        name_a: String(p.name_a || p.icode_a || ''),
        hospital_label: String(p.hospital_label || '')
    })).filter(p => p.icode_a && p.tmtdrug_b);
}

function mrbCollectExcludeDrugsFromTable() {
    const out = [];
    const list = window.mrbExcludeDrugs || [];
    const map = {};
    list.forEach(d => { if (d.icode) map[d.icode] = d; });
    $('#mrbExcludeDrugBody tr[data-icode]').each(function() {
        const icode = String($(this).data('icode') || '');
        const base = map[icode] || { icode: icode, name: icode };
        const exclude_opd = $(this).find('.ex-opd').is(':checked');
        const exclude_ipd = $(this).find('.ex-ipd').is(':checked');
        if (!exclude_opd && !exclude_ipd) return;
        out.push({
            icode: icode,
            name: base.name || icode,
            exclude_opd: exclude_opd ? 1 : 0,
            exclude_ipd: exclude_ipd ? 1 : 0
        });
    });
    return out;
}

function mrbUpdatePttypeList(ptype) {
    const data = window.mrbSettingsCache && window.mrbSettingsCache[ptype];
    if (!data) return;
    const mode = $('.st-ptmode[data-ptype="' + ptype + '"]').val() || 'default';
    const isCustom = mode === 'custom';
    const effective = isCustom
        ? (function() {
            const codes = [];
            $('.st-pttype[data-ptype="' + ptype + '"]:checked').each(function() { codes.push($(this).val()); });
            return codes.length ? codes : (data.settings.pttype_codes_array || []);
        })()
        : mrbComputeEffectivePttypes(data, ptype);
    const effectiveSet = new Set(effective.map(String));
    const $wrap = $('.st-pttype-wrap[data-ptype="' + ptype + '"]');
    let hint = '';
    if (isCustom) {
        hint = '<i class="fas fa-edit me-1"></i>เลือกสิทธิที่ต้องการ — ติ๊กแล้วกดบันทึก (<strong class="text-success">' + effective.length + '</strong> รายการ)';
    } else {
        hint = '<i class="fas fa-eye me-1"></i>สิทธิที่ใช้งานจริง — <strong class="text-success">' + effective.length + '</strong> รายการ';
        hint += ' <span class="text-muted">(เปลี่ยนโหมดเป็น "เลือกเอง" ถ้าต้องการแก้ไข)</span>';
    }
    $wrap.find('.mrb-pttype-hint').html(hint);
    $wrap.find('.st-pttype').each(function() {
        const on = effectiveSet.has(String($(this).val()));
        $(this).prop('checked', on).prop('disabled', !isCustom);
        $(this).closest('.form-check').toggleClass('mrb-pttype-on', on).toggleClass('mrb-pttype-off', !on);
    });
}

if (MRB_TAB === 'settings') {
    window.mrbSettingsCache = {};
    window.mrbExcludeDrugs = [];
    window.mrbSubstitutePairs = [];
    $('#settingsPanels').html('<div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin me-2"></i>กำลังโหลดการตั้งค่า...</div>');
    (async function() {
        try {
            let html = renderExcludeDrugsPanel() + renderSubstituteDrugsPanel();
            for (const pt of ['OPD', 'IPD']) {
                const r = await fetch('fetch_monitor_refer_back_api.php?action=settings&patient_type=' + pt);
                const res = await r.json();
                if (res.status !== 'success') {
                    html += '<div class="alert alert-danger">' + esc(res.message || 'โหลด ' + pt + ' ไม่สำเร็จ') + '</div>';
                    continue;
                }
                window.mrbSettingsCache[pt] = res;
                html += renderSettingsPanel(pt, res);
            }
            const exRes = await fetch('fetch_monitor_refer_back_api.php?action=exclude_drugs');
            const exData = await exRes.json();
            if (exData.status === 'success') {
                window.mrbExcludeDrugs = (exData.items || []).map(d => ({
                    icode: String(d.icode || ''),
                    name: String(d.name || d.icode || ''),
                    exclude_opd: d.exclude_opd !== false,
                    exclude_ipd: d.exclude_ipd !== false
                }));
            }
            const subRes = await fetch('fetch_monitor_refer_back_api.php?action=substitute_drugs');
            const subData = await subRes.json();
            if (subData.status === 'success') {
                window.mrbSubstitutePairs = (subData.pairs || []).map(p => ({
                    icode_a: String(p.icode_a || ''),
                    tmtdrug_b: String(p.tmtdrug_b || ''),
                    name_a: String(p.name_a || p.icode_a || ''),
                    hospital_label: String(p.hospital_label || ''),
                    tpu_b: String(p.tpu_b || '')
                }));
            }
            $('#settingsPanels').html(html || '<div class="alert alert-warning mb-0">ไม่พบการตั้งค่า</div>');
            mrbRenderExcludeDrugRows();
            mrbInitExcludeDrugAdd();
            mrbRenderSubstituteRows();
            mrbInitSubstituteDrugSelects();
        } catch (e) {
            $('#settingsPanels').html('<div class="alert alert-danger mb-0">โหลดการตั้งค่าไม่สำเร็จ</div>');
        }
    })();
    $(document).on('select2:select', '#mrbExcludeDrugAdd', function(e) {
        const item = e.params.data || {};
        const parts = String(item.id || '').split('|');
        const icode = (parts[0] || '').trim();
        if (!icode) return;
        if ((window.mrbExcludeDrugs || []).some(d => d.icode === icode)) {
            alert('มีรายการยานี้แล้ว');
            $('#mrbExcludeDrugAdd').val(null).trigger('change');
            return;
        }
        window.mrbExcludeDrugs = window.mrbExcludeDrugs || [];
        window.mrbExcludeDrugs.push({
            icode: icode,
            name: mrbCleanDrugLabel(item.text) || parts.slice(1).join('|') || icode,
            exclude_opd: true,
            exclude_ipd: true
        });
        mrbRenderExcludeDrugRows();
        $('#mrbExcludeDrugAdd').val(null).trigger('change');
    });
    $(document).on('click', '.btn-ex-rm', function() {
        const icode = String($(this).closest('tr').data('icode') || '');
        window.mrbExcludeDrugs = (window.mrbExcludeDrugs || []).filter(d => d.icode !== icode);
        mrbRenderExcludeDrugRows();
    });
    $('#settingsPanels').on('click', '#btnSaveExcludeDrugs', function() {
        fetch('fetch_monitor_refer_back_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'save_exclude_drugs',
                items: mrbCollectExcludeDrugsFromTable()
            })
        }).then(r => r.json()).then(res => alert(res.message || (res.status === 'success' ? 'บันทึกแล้ว' : 'ผิดพลาด')));
    });
    $('#settingsPanels').on('click', '#btnAddSubstitutePair', function() {
        const itemA = $('#mrbSubstituteDrugA').select2('data')[0] || {};
        const itemB = $('#mrbSubstituteDrugB').select2('data')[0] || {};
        const drugA = mrbParseDrugSelectItem(itemA);
        const drugB = mrbParseNetworkTmtdrugItem(itemB);
        if (!drugA.icode || !drugB.tmtdrug) {
            alert('กรุณาเลือกยา HOSxP (ยาที่ 1) และ tmtdrug บัญชีเครือข่าย (ยาที่ 2)');
            return;
        }
        const key = mrbSubstitutePairKey(drugA.icode, drugB.tmtdrug);
        window.mrbSubstitutePairs = window.mrbSubstitutePairs || [];
        if (window.mrbSubstitutePairs.some(p => mrbSubstitutePairKey(p.icode_a, p.tmtdrug_b) === key)) {
            alert('มีคู่ยานี้แล้ว');
            return;
        }
        window.mrbSubstitutePairs.push({
            icode_a: drugA.icode,
            tmtdrug_b: drugB.tmtdrug,
            name_a: drugA.name,
            hospital_label: drugB.hospital_label,
            tpu_b: drugB.tpu_code_list
        });
        mrbRenderSubstituteRows();
        $('#mrbSubstituteDrugA, #mrbSubstituteDrugB').val(null).trigger('change');
    });
    $(document).on('click', '.btn-sub-rm', function() {
        const idx = parseInt($(this).closest('tr').data('idx'), 10);
        if (isNaN(idx)) return;
        window.mrbSubstitutePairs = (window.mrbSubstitutePairs || []).filter((_, i) => i !== idx);
        mrbRenderSubstituteRows();
    });
    $('#settingsPanels').on('click', '#btnSaveSubstituteDrugs', function() {
        fetch('fetch_monitor_refer_back_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'save_substitute_drugs',
                pairs: mrbCollectSubstitutePairs()
            })
        }).then(r => r.json()).then(res => alert(res.message || (res.status === 'success' ? 'บันทึกแล้ว' : 'ผิดพลาด')));
    });
    $(document).on('change', '.st-ptmode, .st-exclude-province', function() {
        const ptype = $(this).data('ptype');
        if (ptype) mrbUpdatePttypeList(ptype);
    });
    $(document).on('change', '.st-pttype', function() {
        const ptype = $(this).data('ptype');
        if (ptype && $('.st-ptmode[data-ptype="' + ptype + '"]').val() === 'custom') {
            mrbUpdatePttypeList(ptype);
        }
    });
    $(document).on('click', '.btn-save-st', function() {
        const ptype = $(this).data('ptype');
        const codes = [];
        $('.st-pttype[data-ptype="' + ptype + '"]:checked').each(function() { codes.push($(this).val()); });
        fetch('fetch_monitor_refer_back_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'save_settings', patient_type: ptype,
                min_match_percent: parseInt($('.st-min-pct[data-ptype="' + ptype + '"]').val(), 10) || 80,
                min_similar_score: parseInt($('.st-min-sim[data-ptype="' + ptype + '"]').val(), 10) || 90,
                pttype_mode: $('.st-ptmode[data-ptype="' + ptype + '"]').val(),
                pttype_codes: codes,
                exclude_outside_province: $('.st-exclude-province[data-ptype="' + ptype + '"]').is(':checked') ? 1 : 0,
                enabled: $('.st-enabled[data-ptype="' + ptype + '"]').is(':checked') ? 1 : 0
            })
        }).then(r => r.json()).then(res => alert(res.message || (res.status === 'success' ? 'บันทึกแล้ว' : 'ผิดพลาด')));
    });
}
</script>
</body>
</html>
