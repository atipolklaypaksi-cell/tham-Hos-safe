<?php
session_start();
if (!isset($_SESSION['user_id']) && !isset($_SESSION['full_name'])) {
    header('Location: login.php');
    exit;
}
include('connect.php');
include('smart_pharma_helper.php');
mysqli_set_charset($con, 'utf8');
sp_ensure_tables($con);

$tab = $_GET['tab'] ?? 'opd';
if (!in_array($tab, ['opd', 'ipd', 'interventions', 'settings'], true)) {
    $tab = 'opd';
}

if (basename($_SERVER['PHP_SELF']) === 'smart_pharma.php') {
    $sp_redirects = [
        'opd' => 'smart_pharma_opd.php',
        'ipd' => 'smart_pharma_ipd.php',
        'interventions' => 'smart_pharma_interventions.php',
        'settings' => 'smart_pharma_settings.php',
    ];
    header('Location: ' . $sp_redirects[$tab]);
    exit;
}

$sp_page_meta = [
    'opd' => [
        'title' => 'ผู้ป่วยนอก (OPD)',
        'icon' => 'fa-hospital-user',
        'subtitle' => 'คัดกรองผู้ป่วยนอก — ความปลอดภัยด้านยา',
    ],
    'ipd' => [
        'title' => 'ผู้ป่วยใน (IPD)',
        'icon' => 'fa-procedures',
        'subtitle' => 'คัดกรองผู้ป่วยใน — ความปลอดภัยด้านยา',
    ],
    'interventions' => [
        'title' => 'Intervention Log',
        'icon' => 'fa-notes-medical',
        'subtitle' => 'บันทึก Alert และสถานะการแก้ไข (Pending / Resolved)',
    ],
    'settings' => [
        'title' => 'ตั้งค่าโมดูล',
        'icon' => 'fa-sliders-h',
        'subtitle' => 'เปิด/ปิดโมดูลคัดกรอง OPD และ IPD',
    ],
];
$sp_meta = $sp_page_meta[$tab] ?? $sp_page_meta['opd'];

$all_modules = [];
$qmod = mysqli_query($con, "SELECT * FROM smart_pharma_modules ORDER BY sort_order ASC");
if ($qmod) {
    while ($r = mysqli_fetch_assoc($qmod)) {
        $all_modules[] = $r;
    }
}

$default_date = date('Y-m-d');
$log_start = date('Y-m-d', strtotime('-30 days'));
$sp_deep_link = [
    'alert_log_id' => max(0, (int)($_GET['alert_log_id'] ?? 0)),
    'hn' => trim((string)($_GET['hn'] ?? '')),
    'vn' => trim((string)($_GET['vn'] ?? '')),
    'module' => trim((string)($_GET['module'] ?? '')),
    'patient_type' => in_array($_GET['patient_type'] ?? '', ['OPD', 'IPD'], true) ? $_GET['patient_type'] : '',
    'screen_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['screen_date'] ?? '') ? $_GET['screen_date'] : '',
    'open' => !empty($_GET['open']) || !empty($_GET['open_alert']),
    'open_alert' => !empty($_GET['open_alert']) || !empty($_GET['open']),
];
if ($sp_deep_link['screen_date'] !== '' && in_array($tab, ['opd', 'ipd'], true)) {
    $default_date = $sp_deep_link['screen_date'];
}
if ($sp_deep_link['screen_date'] !== '' && $tab === 'interventions') {
    $log_start = $sp_deep_link['screen_date'];
}
session_write_close();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($sp_meta['title']) ?> - Smart Pharma</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
    <link href="css/flatpickr.min.css" rel="stylesheet">
    <style>
        :root { --sp-primary: #0d9488; --sp-dark: #0f766e; --sp-light: #ccfbf1; }
        .sp-hero {
            background: linear-gradient(135deg, var(--sp-dark) 0%, var(--sp-primary) 55%, #14b8a6 100%);
            color: #fff; border-radius: 12px; padding: 1.25rem 1.5rem;
            box-shadow: 0 8px 24px rgba(13, 148, 136, 0.25);
        }
        .sp-hero h4 { font-weight: 800; letter-spacing: 0.02em; margin: 0; }
        .sp-hero small { opacity: 0.92; }
        .nav-tabs .nav-link.active { color: var(--sp-dark) !important; border-color: #dee2e6 #dee2e6 #fff; font-weight: 700; }
        .nav-tabs .nav-link { color: #495057; font-weight: 600; }
        .sp-table thead { background: var(--sp-dark) !important; color: #fff !important; }
        .sp-badge-pass { background: #198754; color: #fff; }
        .sp-badge-alert { background: #dc3545; color: #fff; }
        .sp-badge-soft { background: #ffc107; color: #212529; }
        .sp-badge-na { background: #e9ecef; color: #6c757d; }
        .sp-cell-pass { color: #198754; font-size: 1.1rem; }
        .sp-cell-alert { color: #dc3545; font-size: 1.1rem; }
        .sp-cell-na { color: #adb5bd; }
        .sp-cell-click { cursor: pointer; text-decoration: none !important; }
        .sp-cell-click:hover { opacity: 0.85; transform: scale(1.08); }
        .sp-badge-click { cursor: pointer; }
        .sp-badge-click:hover { filter: brightness(0.95); }
        .sp-row-alert { background: #fff5f5 !important; }
        .sp-row-pass { background: #fff !important; }
        .sp-alert-detail-item { border-left: 4px solid #ffc107; background: #fffbeb; border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 0.65rem; }
        .sp-alert-detail-item.hard { border-left-color: #dc3545; background: #fff5f5; }
        .sp-di-pair { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
        @media (max-width: 768px) { .sp-di-pair { grid-template-columns: 1fr; } }
        .sp-di-drug { background: #fff; border: 1px solid #e9ecef; border-radius: 8px; padding: 0.65rem 0.75rem; }
        .sp-di-drug.d1 { border-top: 3px solid #0d6efd; }
        .sp-di-drug.d2 { border-top: 3px solid #dc3545; }
        .sp-di-detail-text { white-space: pre-wrap; font-size: 0.85rem; line-height: 1.45; }
        .btn-sp { background: var(--sp-primary); border-color: var(--sp-primary); color: #fff; }
        .btn-sp:hover { background: var(--sp-dark); border-color: var(--sp-dark); color: #fff; }
        .filter-box { background: #f8fffe; border: 1px solid #99f6e4; border-radius: 10px; }
        .sp-time-tabs .btn { border-color: #99f6e4; color: var(--sp-dark); font-weight: 600; }
        .sp-time-tabs .btn:hover { background: var(--sp-light); }
        .sp-time-tabs .btn.active {
            background: var(--sp-primary) !important; border-color: var(--sp-primary) !important;
            color: #fff !important;
        }
        .sp-time-divider { color: #94a3b8; font-weight: 400; align-self: center; }
        .sp-custom-time-row { padding-top: 0.25rem; }
        @keyframes sp-row-blink {
            0%, 100% { background-color: #fff5f5; box-shadow: inset 4px 0 0 #dc3545; }
            50% { background-color: #ffd6d6; box-shadow: inset 4px 0 0 #b02a37, 0 0 12px rgba(220, 53, 69, 0.35); }
        }
        @keyframes sp-row-blink-new {
            0%, 100% { background-color: #ffe8e8; box-shadow: inset 4px 0 0 #dc3545, 0 0 0 0 rgba(220, 53, 69, 0.6); }
            50% { background-color: #ffb3b3; box-shadow: inset 4px 0 0 #b02a37, 0 0 16px 4px rgba(220, 53, 69, 0.55); }
        }
        .sp-row-alert {
            background-color: #fff5f5;
            box-shadow: inset 4px 0 0 #dc3545;
        }
        .sp-row-alert-new { animation: sp-row-blink-new 0.9s ease-in-out infinite; }
        .sp-new-patient-badge {
            display: inline-flex; align-items: center;
            background: linear-gradient(135deg, #ffc107, #ff9800);
            color: #212529; font-size: 0.68rem; font-weight: 800;
            padding: 0.15rem 0.45rem; border-radius: 999px;
            border: 1px solid #e65100; margin-right: 6px;
            box-shadow: 0 0 8px rgba(255, 152, 0, 0.55);
            vertical-align: middle; letter-spacing: 0.02em;
        }
        .sp-new-pin { color: #ff9800; font-size: 0.85rem; margin-right: 2px; }
        @keyframes sp-badge-blink {
            0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.5); }
            50% { transform: scale(1.06); box-shadow: 0 0 14px 4px rgba(220, 53, 69, 0.65); }
        }
        #alertCountBadge.sp-alert-active { animation: sp-badge-blink 1s ease-in-out infinite; }
        @keyframes sp-icon-blink {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.35; transform: scale(1.15); }
        }
        .sp-icon-blink { animation: sp-icon-blink 0.75s ease-in-out infinite; display: inline-block; }
        @keyframes sp-banner-blink {
            0%, 100% { background: linear-gradient(90deg, #dc3545, #c82333); }
            50% { background: linear-gradient(90deg, #ff6b6b, #dc3545); }
        }
        .sp-alert-banner {
            animation: sp-banner-blink 1.2s ease-in-out infinite;
            color: #fff; font-weight: 700; border-radius: 8px;
            padding: 0.55rem 1rem; margin-bottom: 0.75rem;
            box-shadow: 0 4px 14px rgba(220, 53, 69, 0.35);
        }
        .sp-refresh-status { font-size: 0.78rem; color: #64748b; }
        .sp-refresh-dot {
            display: inline-block; width: 8px; height: 8px; border-radius: 50%;
            background: #0d9488; margin-right: 4px; vertical-align: middle;
        }
        .sp-refresh-dot.sp-refreshing { animation: sp-icon-blink 0.6s infinite; background: #f59e0b; }
        .sp-quick-links { display: inline-flex; align-items: center; gap: 4px; margin-left: 6px; white-space: nowrap; }
        .sp-quick-link {
            display: inline-flex; align-items: center; justify-content: center;
            width: 1.65rem; height: 1.65rem; border-radius: 6px;
            text-decoration: none !important; font-size: 0.78rem;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .sp-quick-link:hover { transform: scale(1.12); box-shadow: 0 2px 6px rgba(0,0,0,0.12); }
        .sp-quick-drug { background: #e8f4fd; color: #0d6efd; border: 1px solid #b6d4fe; }
        .sp-quick-drug:hover { background: #cfe2ff; color: #084298; }
        .sp-quick-lab { background: #e6fffa; color: #0d9488; border: 1px solid #99f6e4; }
        .sp-quick-lab:hover { background: #ccfbf1; color: #0f766e; }
    </style>
</head>
<body class="bg-light">
<div class="wrapper">
    <?php include('sidebar.php'); ?>
    <div id="content" class="content">
        <div class="container-fluid py-4">
            <div class="sp-hero mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div>
                    <div class="small opacity-75 mb-1"><i class="fas fa-user-shield me-1"></i>Smart Pharma</div>
                    <h4><i class="fas <?= htmlspecialchars($sp_meta['icon']) ?> me-2"></i><?= htmlspecialchars($sp_meta['title']) ?></h4>
                    <small><?= htmlspecialchars($sp_meta['subtitle']) ?></small>
                </div>
                <div class="text-end small opacity-90">
                    <?php if ($tab === 'opd' || $tab === 'ipd'): ?>
                    <i class="fas fa-clipboard-check me-1"></i> Pass = ผ่าน &nbsp;|&nbsp;
                    <i class="fas fa-exclamation-triangle me-1"></i> Alert = พบความเสี่ยง
                    <?php endif; ?>
                </div>
            </div>

            <div class="bg-white border rounded shadow-sm p-4">

                <?php if ($tab === 'opd' || $tab === 'ipd'): ?>
                <div id="spAlertBanner" class="sp-alert-banner d-none d-flex align-items-center justify-content-between flex-wrap gap-2" role="alert">
                    <span id="spAlertBannerText"><i class="fas fa-bell sp-icon-blink me-2"></i>พบผู้ป่วย Alert — กรุณาตรวจสอบ</span>
                    <button type="button" class="btn btn-warning btn-sm fw-bold d-none" id="btnDismissNewAlerts">
                        <i class="fas fa-check me-1"></i>รับทราบ Alert ใหม่
                    </button>
                </div>
                <div class="filter-box p-3 mb-3">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-2">
                            <label class="form-label small fw-bold mb-1">วันที่คัดกรอง</label>
                            <input type="text" id="screen_date" class="form-control form-control-sm" value="<?= htmlspecialchars($default_date) ?>">
                        </div>
                        <div class="col-md-2">
                            <button type="button" class="btn btn-sp btn-sm fw-bold w-100" id="btnScreen" onclick="fetchScreenData()">
                                <i class="fas fa-sync-alt me-1"></i> ประมวลผลคัดกรอง
                            </button>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold mb-1">Auto refresh <span class="text-muted fw-normal">(แนะนำ 2 นาที)</span></label>
                            <select id="spAutoRefresh" class="form-select form-select-sm">
                                <option value="0">ปิด</option>
                                <option value="60">ทุก 1 นาที</option>
                                <option value="120" selected>ทุก 2 นาที</option>
                                <option value="180">ทุก 3 นาที</option>
                                <option value="300">ทุก 5 นาที</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold mb-1">เสียงแจ้งเตือน</label>
                            <div class="form-check form-switch mt-1">
                                <input class="form-check-input" type="checkbox" id="spAlertSound" checked>
                                <label class="form-check-label small" for="spAlertSound">เปิดเสียง Alert</label>
                            </div>
                        </div>
                        <div class="col-md-3 text-md-end">
                            <span class="badge bg-secondary" id="screenTypeBadge"><?= $tab === 'ipd' ? 'IPD' : 'OPD' ?></span>
                            <span class="badge sp-badge-pass ms-1" id="passCountBadge">ผ่าน 0</span>
                            <span class="badge sp-badge-alert ms-1" id="alertCountBadge">Alert 0</span>
                            <div class="sp-refresh-status mt-1" id="spRefreshStatus">
                                <span class="sp-refresh-dot" id="spRefreshDot"></span><span id="spRefreshStatusText">—</span>
                            </div>
                        </div>
                        <div class="col-12 mt-2">
                            <label class="form-label small fw-bold mb-1">ช่วงเวลาสั่งยา</label>
                            <div class="btn-group btn-group-sm sp-time-tabs flex-wrap" role="group" id="spTimeTabs"></div>
                            <div class="form-text" id="spTimeHint">วันนี้: เทียบเวลาปัจจุบัน · วันอื่น: เลือกช่วง 8 ชั่วโมงหรือทั้งวัน</div>
                            <div class="sp-custom-time-row d-none mt-2" id="spCustomTimeRow">
                                <label class="form-label small fw-bold mb-1">เลือกช่วงเวลาเอง</label>
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <input type="time" id="spTimeFrom" class="form-control form-control-sm" style="max-width:8.5rem" step="60">
                                    <span class="small text-muted">ถึง</span>
                                    <input type="time" id="spTimeTo" class="form-control form-control-sm" style="max-width:8.5rem" step="60">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btnCustomTime">
                                        <i class="fas fa-filter me-1"></i>ใช้ช่วงนี้
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="screenMeta" class="alert alert-info py-2 px-3 mt-3 mb-0 small fw-bold d-none"></div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle border mb-0 sp-table">
                        <thead class="text-center">
                            <tr id="screenTableHead">
                                <th>HN</th><th class="text-start">ชื่อ-สกุล</th><th>Visit</th>
                                <th>หอผู้ป่วย / เตียง</th><th>สรุป</th><th>จัดการ</th>
                            </tr>
                        </thead>
                        <tbody id="screenTbody">
                            <tr><td colspan="6" class="text-center py-4 text-muted">กดปุ่มประมวลผลเพื่อคัดกรอง</td></tr>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <?php if ($tab === 'interventions'): ?>
                <ul class="nav nav-pills mb-3 sp-log-tabs" id="logStatusTabs">
                    <li class="nav-item"><button type="button" class="nav-link active" data-status="pending" data-resolved="">รอการแก้ไข</button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-status="resolved" data-resolved="">แก้ไขแล้ว (ทั้งหมด)</button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-status="resolved" data-resolved="auto_cleared">แก้ไขแล้ว — HOSxP</button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-status="resolved" data-resolved="intervention">แก้ไขแล้ว — Intervention</button></li>
                </ul>
                <div class="filter-box p-3 mb-3">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-2">
                            <label class="form-label small fw-bold mb-1">ตั้งแต่</label>
                            <input type="text" id="log_start" class="form-control form-control-sm" value="<?= htmlspecialchars($log_start) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold mb-1">ถึง</label>
                            <input type="text" id="log_end" class="form-control form-control-sm" value="<?= htmlspecialchars($default_date) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold mb-1">ประเภท</label>
                            <select id="log_ptype" class="form-select form-select-sm">
                                <option value="">ทั้งหมด</option>
                                <option value="OPD">OPD</option>
                                <option value="IPD">IPD</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="button" class="btn btn-sp btn-sm fw-bold w-100" onclick="fetchAlertLogs()">
                                <i class="fas fa-search me-1"></i> ค้นหา
                            </button>
                        </div>
                        <div class="col-md-4 text-end">
                            <span class="badge bg-warning text-dark" id="logCountBadge">—</span>
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm align-middle">
                        <thead class="table-light text-center">
                            <tr>
                                <th>วันที่คัดกรอง</th><th>HN</th><th>ผู้ป่วย</th><th>OPD/IPD</th>
                                <th>โมดูล</th><th>ระดับ</th><th>Alert</th><th>สถานะ</th>
                                <th>Intervention</th><th>จัดการ</th>
                            </tr>
                        </thead>
                        <tbody id="logTbody">
                            <tr><td colspan="10" class="text-center text-muted py-3">กำลังโหลด...</td></tr>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <?php if ($tab === 'settings'): ?>
                <div class="alert alert-light border small mb-3">
                    <i class="fas fa-info-circle text-info me-1"></i>
                    เปิด/ปิดโมดูลที่ใช้คัดกรองใน Smart Pharma — แยกได้ว่าใช้กับ OPD หรือ IPD
                </div>
                <form id="moduleSettingsForm">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light text-center">
                            <tr><th>โมดูล</th><th>เปิดใช้</th><th>OPD</th><th>IPD</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($all_modules as $m): ?>
                            <tr>
                                <td class="fw-bold">
                                    <i class="fas fa-<?= htmlspecialchars($m['icon'] ?? 'circle') ?> me-2 text-<?= htmlspecialchars($m['color'] ?? 'secondary') ?>"></i>
                                    <?= htmlspecialchars($m['label_th']) ?>
                                </td>
                                <td class="text-center">
                                    <input type="checkbox" class="form-check-input mod-en" data-key="<?= htmlspecialchars($m['module_key']) ?>"
                                        <?= !empty($m['enabled']) ? 'checked' : '' ?>>
                                </td>
                                <td class="text-center">
                                    <input type="checkbox" class="form-check-input mod-opd" data-key="<?= htmlspecialchars($m['module_key']) ?>"
                                        <?= !empty($m['enabled_opd']) ? 'checked' : '' ?>>
                                </td>
                                <td class="text-center">
                                    <input type="checkbox" class="form-check-input mod-ipd" data-key="<?= htmlspecialchars($m['module_key']) ?>"
                                        <?= !empty($m['enabled_ipd']) ? 'checked' : '' ?>>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <button type="button" class="btn btn-sp fw-bold" onclick="saveModuleSettings()">
                        <i class="fas fa-save me-1"></i> บันทึกการตั้งค่า
                    </button>
                </form>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<!-- Alert Detail Modal -->
<div class="modal fade" id="alertDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" style="background:#fff5f5;">
                <h5 class="modal-title fw-bold text-danger"><i class="fas fa-exclamation-triangle me-2"></i>รายละเอียด Alert</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3 p-3 rounded border bg-light" id="alertDetailPatient"></div>
                <div id="alertDetailList"></div>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">ปิด</button>
                <button type="button" class="btn btn-sp btn-sm fw-bold" id="btnAlertToIv" onclick="openInterventionFromAlert()">
                    <i class="fas fa-pen me-1"></i> บันทึก Intervention
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Intervention Modal -->
<div class="modal fade" id="ivModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--sp-light);">
                <h5 class="modal-title fw-bold"><i class="fas fa-notes-medical me-2"></i>บันทึก Intervention</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="iv_hn"><input type="hidden" id="iv_vn"><input type="hidden" id="iv_an">
                <input type="hidden" id="iv_vstdate"><input type="hidden" id="iv_ptype"><input type="hidden" id="iv_module">
                <input type="hidden" id="iv_alert_log_id"><input type="hidden" id="iv_intervention_id"><input type="hidden" id="iv_screen_date">
                <p class="small text-muted mb-3" id="iv_patient_info"></p>
                <div id="iv_hard_warn" class="alert alert-danger py-2 small d-none mb-2">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    <strong>Hard Alert</strong> — ต้องแก้ไขใน HOSxP จึงจะปิด Alert ได้ การบันทึก Intervention จะถูกเก็บแยกและสถานะยังคงรอการแก้ไข
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-bold">โมดูล</label>
                    <select id="iv_module_sel" class="form-select form-select-sm"></select>
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-bold">การแทรกแซง</label>
                    <select id="iv_type" class="form-select form-select-sm">
                        <option value="recommend">แนะนำ / Consult</option>
                        <option value="adjust">ปรับยา</option>
                        <option value="reject">ไม่จ่าย / Hold</option>
                        <option value="followup">ติดตาม</option>
                        <option value="document">บันทึกเท่านั้น</option>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-bold">ประเภทความคลาดเคลื่อน</label>
                    <select id="iv_deviation" class="form-select form-select-sm">
                        <option value="">— ไม่ระบุ —</option>
                        <option value="ความคลาดเคลื่อนจริง">ความคลาดเคลื่อนจริง</option>
                        <option value="ไม่ใช่ความคลาดเคลื่อน">ไม่ใช่ความคลาดเคลื่อน</option>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-bold">หมายเหตุ</label>
                    <textarea id="iv_note" class="form-control form-control-sm" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-sp btn-sm fw-bold" onclick="saveIntervention()">บันทึก</button>
            </div>
        </div>
    </div>
</div>

<!-- Alert Log Detail Modal -->
<div class="modal fade" id="alertLogModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--sp-light);">
                <h5 class="modal-title fw-bold"><i class="fas fa-clipboard-list me-2"></i>รายละเอียด Alert Log</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="alertLogBody">
                <div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin"></i></div>
            </div>
        </div>
    </div>
</div>

<script src="js/jquery-3.7.0.min.js"></script>
<script src="js/bootstrap.bundle.min.js"></script>
<script src="js/flatpickr.min.js"></script>
<script>
const SP_TAB = '<?= $tab ?>';
const SP_PTYPE = SP_TAB === 'ipd' ? 'IPD' : 'OPD';
const SP_SCREENING = SP_TAB === 'opd' || SP_TAB === 'ipd';
const SP_DEEP_LINK = <?= json_encode($sp_deep_link, JSON_UNESCAPED_UNICODE) ?>;
let spDeepLinkPending = null;
const SP_LS_REFRESH = 'sp_auto_refresh_' + SP_PTYPE;
const SP_LS_SOUND = 'sp_alert_sound_' + SP_PTYPE;
let spPatients = [];
let spDisplayPatients = [];
let spModules = [];
let spTimeWindow = '';
let spTimeFrom = '';
let spTimeTo = '';
let spScreenMeta = {};
let ivModal;
let alertLogModal;
let alertDetailModal;
let logStatusFilter = 'pending';
let logResolvedByFilter = '';
let spAlertLogs = [];
let spInterventionDeepLinkPending = null;
let alertDetailIdx = -1;
let alertDetailFocusModule = '';
let spPrevAlertKeys = new Set();
let spNewAlertKeys = new Set();
let spPersistedNewKeys = new Set();
let spIsFirstScreenLoad = true;
let spAutoRefreshTimer = null;
let spAutoRefreshCountdownTimer = null;
let spAutoRefreshSecLeft = 0;
let spAudioCtx = null;

const SP_TIME_LABELS = {
    custom: 'ช่วงเวลาที่เลือก',
    '30m': '30 นาทีล่าสุด', '1h': '1 ชั่วโมงล่าสุด', '3h': '3 ชั่วโมงล่าสุด',
    '00-08': '00:00–08:00', '08-16': '08:01–16:00', '16-24': '16:01–24:00', 'full': 'ทั้งวัน'
};
const SP_LIVE_TABS = [
    { w: '30m', label: '30 นาทีล่าสุด' },
    { w: '1h', label: '1 ชั่วโมงล่าสุด' },
    { w: '3h', label: '3 ชั่วโมงล่าสุด' }
];
const SP_SLOT_TABS = [
    { w: '00-08', label: '00:00–08:00' },
    { w: '08-16', label: '08:01–16:00' },
    { w: '16-24', label: '16:01–24:00' },
    { w: 'full', label: 'ทั้งวัน' }
];

function spTodayBangkok() {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Bangkok' }).format(new Date());
}

function spIsScreenDateToday() {
    const d = $('#screen_date').val() || '<?= $default_date ?>';
    return d === spTodayBangkok();
}

function spDefaultTimeWindow() {
    return spIsScreenDateToday() ? '1h' : 'full';
}

function spClearCustomTime() {
    spTimeFrom = '';
    spTimeTo = '';
    $('#spTimeFrom, #spTimeTo').val('');
}

function spUpdateTimeTabs(forceDefault) {
    const isToday = spIsScreenDateToday();
    const tabs = isToday ? SP_LIVE_TABS.concat(SP_SLOT_TABS) : SP_SLOT_TABS;
    const allowed = tabs.map(t => t.w).concat(isToday ? ['custom'] : []);
    if (forceDefault || (!allowed.includes(spTimeWindow))) {
        spTimeWindow = spDefaultTimeWindow();
        spClearCustomTime();
    }
    let html = '';
    tabs.forEach((t, i) => {
        if (isToday && i === SP_LIVE_TABS.length) {
            html += '<span class="sp-time-divider px-1">|</span>';
        }
        const active = t.w === spTimeWindow ? ' active' : '';
        html += '<button type="button" class="btn sp-time-tab' + active + '" data-window="' + t.w + '">' + t.label + '</button>';
    });
    $('#spTimeTabs').html(html);
    $('#spCustomTimeRow').toggleClass('d-none', !isToday);
    if (!isToday) {
        spClearCustomTime();
    }
    $('#spTimeHint').text(isToday
        ? 'วันนี้ — ล่าสุด (30 นาที/1 ชม./3 ชม.) · ช่วง 8 ชั่วโมง · ทั้งวัน · หรือกำหนดเวลาเองด้านล่าง'
        : 'เลือกช่วงเวลาสั่งยา ทุก 8 ชั่วโมง หรือทั้งวัน');
}

$(function() {
    if (typeof flatpickr !== 'undefined') {
        flatpickr('#screen_date', {
            dateFormat: 'Y-m-d',
            defaultDate: '<?= $default_date ?>',
            onChange: function() {
                spUpdateTimeTabs(true);
                spIsFirstScreenLoad = true;
                spPrevAlertKeys = new Set();
                spPersistedNewKeys = new Set();
                if (!spIsScreenDateToday()) {
                    $('#spAutoRefresh').val('0');
                }
                spStartAutoRefresh();
                fetchScreenData();
            }
        });
        flatpickr('#log_start', { dateFormat: 'Y-m-d' });
        flatpickr('#log_end', { dateFormat: 'Y-m-d', defaultDate: '<?= $default_date ?>' });
    }
    ivModal = new bootstrap.Modal(document.getElementById('ivModal'));
    if (document.getElementById('alertLogModal')) {
        alertLogModal = new bootstrap.Modal(document.getElementById('alertLogModal'));
    }
    $('#iv_module_sel').on('change', function() {
        const lvl = $('#iv_module_sel option:selected').data('level');
        $('#iv_hard_warn').toggleClass('d-none', lvl !== 'hard');
    });
    $('#logStatusTabs .nav-link').on('click', function() {
        $('#logStatusTabs .nav-link').removeClass('active');
        $(this).addClass('active');
        logStatusFilter = $(this).data('status') || '';
        logResolvedByFilter = $(this).data('resolved') || '';
        fetchAlertLogs();
    });
    alertDetailModal = new bootstrap.Modal(document.getElementById('alertDetailModal'));
    spUpdateTimeTabs(true);
    $('#spTimeTabs').on('click', '.sp-time-tab', function() {
        const w = $(this).data('window');
        if (!w || w === spTimeWindow) return;
        spTimeWindow = w;
        spClearCustomTime();
        $('#spTimeTabs .sp-time-tab').removeClass('active');
        $(this).addClass('active');
        fetchScreenData();
    });
    $('#btnCustomTime').on('click', function() {
        const from = $('#spTimeFrom').val();
        const to = $('#spTimeTo').val();
        if (!from || !to) {
            alert('กรุณาเลือกเวลาเริ่มและเวลาสิ้นสุด');
            return;
        }
        spTimeWindow = 'custom';
        spTimeFrom = from;
        spTimeTo = to;
        $('#spTimeTabs .sp-time-tab').removeClass('active');
        fetchScreenData();
    });
    if (SP_SCREENING) {
        spInitDeepLink();
        spInitAutoRefresh();
        fetchScreenData();
    }
    if (SP_TAB === 'interventions') {
        flatpickr('#log_start', { dateFormat: 'Y-m-d', locale: { firstDayOfWeek: 1 } });
        flatpickr('#log_end', { dateFormat: 'Y-m-d', locale: { firstDayOfWeek: 1 } });
        spInitInterventionDeepLink();
        fetchAlertLogs();
    }
});

function spEsc(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function spNormHn(hn) {
    return String(hn || '').replace(/^0+/, '') || String(hn || '');
}

function spInitDeepLink() {
    if (!SP_DEEP_LINK || !SP_DEEP_LINK.open_alert || !SP_DEEP_LINK.hn) return;
    if (SP_DEEP_LINK.screen_date) {
        $('#screen_date').val(SP_DEEP_LINK.screen_date);
    }
    spTimeWindow = 'full';
    spClearCustomTime();
    spDeepLinkPending = SP_DEEP_LINK;
}

function spApplyDeepLink() {
    if (!spDeepLinkPending) return;
    const dl = spDeepLinkPending;
    spDeepLinkPending = null;
    const hnNorm = spNormHn(dl.hn);
    let idx = -1;
    spDisplayPatients.forEach((p, i) => {
        if (spNormHn(p.hn_full || p.hn) !== hnNorm && spNormHn(p.hn) !== hnNorm) return;
        if (dl.vn && p.vn && String(p.vn) !== String(dl.vn)) return;
        idx = i;
    });
    if (idx >= 0) {
        setTimeout(function() { openAlertDetail(idx, dl.module || ''); }, 300);
    }
}

function spInitInterventionDeepLink() {
    if (!SP_DEEP_LINK) return;
    if (!SP_DEEP_LINK.open && !SP_DEEP_LINK.alert_log_id) return;
    if (SP_DEEP_LINK.alert_log_id) {
        setTimeout(function() { openAlertLogDetail(SP_DEEP_LINK.alert_log_id); }, 150);
        return;
    }
    if (SP_DEEP_LINK.screen_date) {
        $('#log_start').val(SP_DEEP_LINK.screen_date);
        $('#log_end').val(SP_DEEP_LINK.screen_date);
    }
    if (SP_DEEP_LINK.patient_type) {
        $('#log_ptype').val(SP_DEEP_LINK.patient_type);
    }
    spInterventionDeepLinkPending = SP_DEEP_LINK;
}

function spApplyInterventionDeepLink() {
    if (!spInterventionDeepLinkPending) return;
    const dl = spInterventionDeepLinkPending;
    spInterventionDeepLinkPending = null;
    if (dl.alert_log_id) {
        setTimeout(function() { openAlertLogDetail(dl.alert_log_id); }, 200);
        return;
    }
    if (!dl.hn) return;
    const hnNorm = spNormHn(dl.hn);
    let found = null;
    spAlertLogs.forEach(r => {
        if (found) return;
        if (spNormHn(r.hn) !== hnNorm) return;
        if (dl.vn && r.vn && String(r.vn) !== String(dl.vn)) return;
        if (dl.module && r.module_key && r.module_key !== dl.module) return;
        if (dl.patient_type && r.patient_type && r.patient_type !== dl.patient_type) return;
        found = r;
    });
    if (found) {
        setTimeout(function() { openAlertLogDetail(found.id); }, 200);
    }
}

function renderSpDiDrugBox(item, which) {
    const is1 = which === 1;
    const name = spEsc(is1 ? item.drug1_name : item.drug2_name);
    const date = spEsc(is1 ? (item.drug1_date || '') : (item.drug2_date || ''));
    const time = spEsc(is1 ? (item.drug1_time || '') : (item.drug2_time || ''));
    const qty = spEsc(is1 ? item.dose1_qty : item.dose2_qty);
    const method = spEsc(is1 ? (item.drug1_method || '') : (item.drug2_method || ''));
    const cov = spEsc(is1 ? item.drug1_coverage_days : item.drug2_coverage_days);
    const covEnd = spEsc(is1 ? item.drug1_coverage_end : item.drug2_coverage_end);
    const dailyMg = spEsc(is1 ? (item.drug1_daily_mg || '') : (item.drug2_daily_mg || ''));
    let html = '<div class="sp-di-drug d' + which + '">'
        + '<div class="fw-bold ' + (is1 ? 'text-primary' : 'text-danger') + '">' + (name || '-') + '</div>'
        + '<div class="small mt-1"><i class="far fa-calendar-alt me-1"></i>' + (date || '-') + (time ? ' <span class="text-muted">' + time + ' น.</span>' : '') + '</div>'
        + '<div class="small">qty: <b>' + (qty || '-') + '</b></div>';
    if (method) {
        html += '<div class="small text-muted mt-1"><i class="fas fa-prescription-bottle-alt me-1"></i>' + method + '</div>';
    }
    if (dailyMg) {
        html += '<div class="small text-success fw-bold">' + dailyMg + '</div>';
    }
    if (cov) {
        html += '<div class="small text-success">ครอบคลุม ' + cov + ' วัน' + (covEnd ? ' (ถึง ' + covEnd + ')' : '') + '</div>';
    }
    html += '</div>';
    return html;
}

function renderSpAlertModuleBody(mod, modKey) {
    if (modKey === 'di' && mod.items && mod.items.length) {
        let html = '';
        mod.items.forEach((item, i) => {
            const detail = spEsc(item.detail_text || item.interaction_type || 'พบปฏิกิริยาระหว่างยา');
            const meta = spEsc(item.check_mode_label || '');
            html += '<div class="' + (i > 0 ? 'mt-3 pt-3 border-top' : '') + '">'
                + '<div class="sp-di-pair mb-2">' + renderSpDiDrugBox(item, 1) + renderSpDiDrugBox(item, 2) + '</div>'
                + '<div class="small fw-bold text-secondary mb-1"><i class="fas fa-file-medical me-1"></i>รายละเอียด</div>'
                + '<div class="sp-di-detail-text mb-2">' + detail + '</div>';
            if (meta) {
                html += '<div class="small text-muted"><i class="fas fa-search me-1"></i>' + meta + '</div>';
            }
            html += '</div>';
        });
        return html;
    }
    const details = [];
    if (mod.details && mod.details.length) {
        mod.details.forEach(d => { if (d) details.push(d); });
    } else if (mod.summary) {
        details.push(mod.summary);
    }
    if (!details.length) {
        return '<div class="small text-muted">ไม่พบรายละเอียดเพิ่มเติม</div>';
    }
    let html = '<ul class="mb-0 ps-3 small">';
    details.forEach(d => { html += '<li class="mb-1">' + spEsc(d) + '</li>'; });
    html += '</ul>';
    return html;
}

function spOrderDateTime(p) {
    let d = String(p.rxdate || '').trim();
    if (!d || d === '0000-00-00') d = String(p.vstdate || '').trim();
    let t = String(p.rxtime || '').trim();
    if (!t) t = String(p.vsttime || '').trim();
    return { date: d, time: t };
}

function spParseOrderDate(p) {
    const o = spOrderDateTime(p);
    if (!o.date) return null;
    let t = o.time || '00:00:00';
    if (t.length === 5) t += ':00';
    const dt = new Date(o.date + 'T' + t);
    return isNaN(dt.getTime()) ? null : dt;
}

function spSortPatients(list) {
    list.sort((a, b) => {
        const aNew = spIsNewAlertPatient(spPatientKey(a)) ? 0 : 1;
        const bNew = spIsNewAlertPatient(spPatientKey(b)) ? 0 : 1;
        if (aNew !== bNew) return aNew - bNew;
        const aAlert = a.overall === 'alert' ? 0 : 1;
        const bAlert = b.overall === 'alert' ? 0 : 1;
        if (aAlert !== bAlert) return aAlert - bAlert;
        const aLevel = a.overall_level === 'hard' ? 0 : 1;
        const bLevel = b.overall_level === 'hard' ? 0 : 1;
        if (aAlert === 0 && aLevel !== bLevel) return aLevel - bLevel;
        const aDt = spParseOrderDate(a);
        const bDt = spParseOrderDate(b);
        const aTs = aDt ? aDt.getTime() : 0;
        const bTs = bDt ? bDt.getTime() : 0;
        if (aTs !== bTs) return bTs - aTs;
        return String(a.hn || '').localeCompare(String(b.hn || ''));
    });
}

function spPatientKey(p) {
    return String(p.hn_full || p.hn || '') + '|' + String(p.vn || '') + '|'
        + String(p.rxdate || p.vstdate || '') + '|' + String(p.rxtime || p.vsttime || '');
}

function spCollectAlertKeys(list) {
    const keys = new Set();
    (list || []).forEach(p => {
        if (p.overall === 'alert') keys.add(spPatientKey(p));
    });
    return keys;
}

function spIsSoundEnabled() {
    return $('#spAlertSound').is(':checked');
}

function spEnsureAudioCtx() {
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return null;
    if (!spAudioCtx) spAudioCtx = new Ctx();
    if (spAudioCtx.state === 'suspended') spAudioCtx.resume();
    return spAudioCtx;
}

/** ติ๊งหน่องแบบประกาศสนามบิน แล้วพูด "Drug alert" */
function spPlayAirportBell(freq, startSec, durationSec, volume) {
    const ctx = spEnsureAudioCtx();
    if (!ctx) return;
    const t0 = ctx.currentTime + startSec;
    const osc1 = ctx.createOscillator();
    const osc2 = ctx.createOscillator();
    const gain = ctx.createGain();
    osc1.type = 'sine';
    osc2.type = 'sine';
    osc1.frequency.value = freq;
    osc2.frequency.value = freq * 2.756; // harmonic ให้ฟังเป็นระฆัง
    gain.gain.setValueAtTime(0.0001, t0);
    gain.gain.exponentialRampToValueAtTime(volume, t0 + 0.02);
    gain.gain.exponentialRampToValueAtTime(0.0001, t0 + durationSec);
    osc1.connect(gain);
    osc2.connect(gain);
    gain.connect(ctx.destination);
    osc1.start(t0);
    osc2.start(t0);
    osc1.stop(t0 + durationSec + 0.08);
    osc2.stop(t0 + durationSec + 0.08);
}

function spPickMaleEnVoice(voices) {
    const maleHints = /male|david|mark|james|daniel|george|richard|thomas|aaron|guy|ryan|fred|paul|microsoft david/i;
    const femaleHints = /female|samantha|zira|susan|karen|victoria|hazel|heather|linda|sara|aria|jenny|michelle/i;
    const isMale = function(v) {
        return maleHints.test(v.name) && !femaleHints.test(v.name);
    };
    const isNotFemale = function(v) {
        return !femaleHints.test(v.name);
    };
    return voices.find(function(v) { return /en-US/i.test(v.lang) && isMale(v); })
        || voices.find(function(v) { return /^en/i.test(v.lang) && isMale(v); })
        || voices.find(function(v) { return /en-US/i.test(v.lang) && isNotFemale(v); })
        || voices.find(function(v) { return /^en/i.test(v.lang); });
}

function spSpeakFoundAlert() {
    if (!window.speechSynthesis) return;
    const speak = function() {
        window.speechSynthesis.cancel();
        const u = new SpeechSynthesisUtterance('Drug alert');
        u.lang = 'en-US';
        u.rate = 0.9;
        u.pitch = 0.82;
        u.volume = 1;
        const voices = window.speechSynthesis.getVoices();
        const pick = spPickMaleEnVoice(voices);
        if (pick) u.voice = pick;
        window.speechSynthesis.speak(u);
    };
    const voices = window.speechSynthesis.getVoices();
    if (voices.length) {
        speak();
    } else {
        window.speechSynthesis.onvoiceschanged = function() {
            window.speechSynthesis.onvoiceschanged = null;
            speak();
        };
    }
}

function spPlayAlertSound() {
    if (!spIsSoundEnabled()) return;
    try {
        if (!spEnsureAudioCtx()) {
            spSpeakFoundAlert();
            return;
        }
        // Ding (สูง) → Dong (ต่ำ) แบบประกาศสนามบิน
        spPlayAirportBell(1318.5, 0, 0.42, 0.32);   // E6 — ติ๊ง
        spPlayAirportBell(987.8, 0.58, 0.72, 0.36); // B5 — หน่อง
        setTimeout(spSpeakFoundAlert, 1450);
    } catch (e) {
        spSpeakFoundAlert();
    }
}

function spPreloadSpeechVoices() {
    if (!window.speechSynthesis) return;
    window.speechSynthesis.getVoices();
    window.speechSynthesis.onvoiceschanged = function() {
        window.speechSynthesis.getVoices();
    };
}

function spIsNewAlertPatient(pkey) {
    return spPersistedNewKeys.has(pkey);
}

function spNewBadgeHtml() {
    return '<span class="sp-new-patient-badge sp-icon-blink" title="Alert ใหม่ — ยังไม่รับทราบ">'
        + '<i class="fas fa-star me-1"></i>ใหม่</span>';
}

function spUpdateAlertVisuals(alertCount) {
    const $badge = $('#alertCountBadge');
    const newCount = spPersistedNewKeys.size;
    if (alertCount > 0) {
        $badge.addClass('sp-alert-active');
        $('#spAlertBanner').removeClass('d-none');
        let banner = '<i class="fas fa-exclamation-triangle me-1"></i>พบผู้ป่วย <b>Alert ' + alertCount + '</b> ราย — กรุณาตรวจสอบ';
        if (newCount > 0) {
            banner += ' <span class="badge bg-warning text-dark ms-1">ใหม่ ' + newCount + '</span>';
            banner += ' <span class="small opacity-90 ms-1">(ดูป้าย <b>ใหม่</b> ในตาราง)</span>';
        }
        $('#spAlertBannerText').html(banner);
        $('#btnDismissNewAlerts').toggleClass('d-none', newCount === 0);
    } else {
        $badge.removeClass('sp-alert-active');
        $('#spAlertBanner').addClass('d-none');
        $('#btnDismissNewAlerts').addClass('d-none');
    }
}

function spDismissNewAlerts() {
    spPersistedNewKeys = new Set();
    let alertC = 0;
    spDisplayPatients.forEach(p => { if (p.overall === 'alert') alertC++; });
    spUpdateAlertVisuals(alertC);
    buildScreenTable();
}

function spDetectNewAlerts(currentKeys) {
    spNewAlertKeys = new Set();
    if (spIsFirstScreenLoad) {
        return false;
    }
    currentKeys.forEach(k => {
        if (!spPrevAlertKeys.has(k)) {
            spNewAlertKeys.add(k);
            spPersistedNewKeys.add(k);
        }
    });
    return spNewAlertKeys.size > 0;
}

function spGetAutoRefreshSec() {
    if (!spIsScreenDateToday()) return 0;
    return parseInt($('#spAutoRefresh').val(), 10) || 0;
}

function spFormatCountdown(sec) {
    const m = Math.floor(sec / 60);
    const s = sec % 60;
    return m + ':' + String(s).padStart(2, '0');
}

function spStopAutoRefresh() {
    if (spAutoRefreshTimer) {
        clearInterval(spAutoRefreshTimer);
        spAutoRefreshTimer = null;
    }
    if (spAutoRefreshCountdownTimer) {
        clearInterval(spAutoRefreshCountdownTimer);
        spAutoRefreshCountdownTimer = null;
    }
}

function spUpdateRefreshStatusText() {
    const sec = spGetAutoRefreshSec();
    const $text = $('#spRefreshStatusText');
    if (!spIsScreenDateToday()) {
        $text.text('Auto refresh ใช้ได้เฉพาะวันนี้');
        return;
    }
    if (sec <= 0) {
        $text.text('Auto refresh ปิดอยู่');
        return;
    }
    $text.text('อัปเดตถัดไปใน ' + spFormatCountdown(spAutoRefreshSecLeft));
}

function spStartAutoRefresh() {
    spStopAutoRefresh();
    const sec = spGetAutoRefreshSec();
    spUpdateRefreshStatusText();
    if (!SP_SCREENING || sec <= 0 || !spIsScreenDateToday()) return;
    spAutoRefreshSecLeft = sec;
    spAutoRefreshCountdownTimer = setInterval(function() {
        if (document.hidden) return;
        spAutoRefreshSecLeft = Math.max(0, spAutoRefreshSecLeft - 1);
        spUpdateRefreshStatusText();
    }, 1000);
    spAutoRefreshTimer = setInterval(function() {
        if (document.hidden) return;
        if (!spIsScreenDateToday()) {
            spStopAutoRefresh();
            spUpdateRefreshStatusText();
            return;
        }
        spAutoRefreshSecLeft = sec;
        fetchScreenData({ silent: true, isAuto: true });
    }, sec * 1000);
}

function spInitAutoRefresh() {
    if (!SP_SCREENING) return;
    spPreloadSpeechVoices();
    $('#btnDismissNewAlerts').on('click', spDismissNewAlerts);
    const saved = localStorage.getItem(SP_LS_REFRESH);
    if (saved !== null) $('#spAutoRefresh').val(saved);
    if (!spIsScreenDateToday()) $('#spAutoRefresh').val('0');
    const soundSaved = localStorage.getItem(SP_LS_SOUND);
    if (soundSaved !== null) $('#spAlertSound').prop('checked', soundSaved === '1');
    $('#spAutoRefresh').on('change', function() {
        localStorage.setItem(SP_LS_REFRESH, $(this).val());
        spStartAutoRefresh();
    });
    $('#spAlertSound').on('change', function() {
        localStorage.setItem(SP_LS_SOUND, $(this).is(':checked') ? '1' : '0');
        if ($(this).is(':checked')) spPlayAlertSound();
    });
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden && spGetAutoRefreshSec() > 0 && spIsScreenDateToday()) {
            fetchScreenData({ silent: true, isAuto: true });
            spStartAutoRefresh();
        }
    });
    document.body.addEventListener('click', function spUnlockAudioOnce() {
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (Ctx && !spAudioCtx) spAudioCtx = new Ctx();
            if (spAudioCtx && spAudioCtx.state === 'suspended') spAudioCtx.resume();
        } catch (e) { /* ignore */ }
    }, { once: true });
    spStartAutoRefresh();
}

function updateScreenCounts() {
    let passC = 0, alertC = 0;
    spDisplayPatients.forEach(p => {
        if (p.overall === 'alert') alertC++;
        else passC++;
    });
    $('#passCountBadge').text('ผ่าน ' + passC);
    $('#alertCountBadge').text('Alert ' + alertC);
    const shown = spPatients.length;
    const total = spScreenMeta.total_patients || shown;
    let mh = '<i class="fas fa-info-circle me-1"></i> แสดง <b>' + shown + '</b> รายการ';
    if (total > shown) mh += ' (จากทั้งหมดในวัน ' + total + ')';
    mh += ' | ช่วง <b>' + spEsc(spScreenMeta.time_window_label || SP_TIME_LABELS[spTimeWindow] || spTimeWindow) + '</b>';
    if (spScreenMeta.elapsed_ms) mh += ' | ประมวลผล ' + (spScreenMeta.elapsed_ms / 1000).toFixed(1) + ' วินาที';
    if (spScreenMeta.timings && spScreenMeta.timings.fetch_cached) {
        mh += ' <span class="text-muted">(cache)</span>';
    }
    $('#screenMeta').removeClass('d-none').html(mh);
    spUpdateAlertVisuals(alertC);
}

function spPatientQuickLinks(p) {
    const hn = String(p.hn_full || p.hn || '').trim();
    if (!hn) return '';
    const hnEnc = encodeURIComponent(hn);
    return '<span class="sp-quick-links">'
        + '<a href="smart_drug.php?hn=' + hnEnc + '" class="sp-quick-link sp-quick-drug" target="_blank" rel="noopener noreferrer"'
        + ' title="Smart Drug — ประวัติยา"><i class="fas fa-capsules"></i></a>'
        + '<a href="smart_lab.php?hn=' + hnEnc + '" class="sp-quick-link sp-quick-lab" target="_blank" rel="noopener noreferrer"'
        + ' title="Smart Lab — ประวัติ Lab"><i class="fas fa-microscope"></i></a>'
        + '</span>';
}

function modCellHtml(m, patientIdx, modKey) {
    if (!m || m.status === 'na') {
        return '<span class="sp-cell-na" title="' + spEsc(m ? m.summary : '') + '">—</span>';
    }
    if (m.status === 'pass') {
        return '<span class="sp-cell-pass" title="' + spEsc(m.summary) + '"><i class="fas fa-check-circle"></i></span>';
    }
    const cls = m.level === 'hard' ? 'sp-cell-alert' : 'text-warning';
    const blink = ' sp-icon-blink';
    return '<button type="button" class="btn btn-link p-0 border-0 sp-cell-click ' + cls + '"'
        + ' onclick="openAlertDetail(' + patientIdx + ', \'' + String(modKey).replace(/'/g, '') + '\')"'
        + ' title="คลิกดูรายละเอียด: ' + spEsc(m.summary) + '">'
        + '<i class="fas fa-exclamation-triangle' + blink + '"></i></button>';
}

function fetchScreenData(opts) {
    opts = opts || {};
    const silent = !!opts.silent;
    const date = $('#screen_date').val() || '<?= $default_date ?>';
    if (!silent) {
        $('#btnScreen').prop('disabled', true);
        $('#screenTbody').html('<tr><td colspan="20" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i>กำลังคัดกรองผู้ป่วยทั้งหมดในวันที่เลือก (อาจใช้เวลาสักครู่)...</td></tr>');
    } else {
        $('#spRefreshDot').addClass('sp-refreshing');
    }
    let apiUrl = 'fetch_smart_pharma_api.php?action=list&patient_type=' + SP_PTYPE
        + '&screen_date=' + encodeURIComponent(date)
        + '&time_window=' + encodeURIComponent(spTimeWindow);
    if (spTimeWindow === 'custom' && spTimeFrom && spTimeTo) {
        apiUrl += '&time_from=' + encodeURIComponent(spTimeFrom)
            + '&time_to=' + encodeURIComponent(spTimeTo);
    }
    fetch(apiUrl, { credentials: 'same-origin' })
    .then(r => r.json())
    .then(data => {
        $('#btnScreen').prop('disabled', false);
        $('#spRefreshDot').removeClass('sp-refreshing');
        if (data.status !== 'success') {
            if (!silent) {
                $('#screenTbody').html('<tr><td colspan="20" class="text-danger text-center py-4">' + spEsc(data.message) + '</td></tr>');
            }
            return;
        }
        const currentAlertKeys = spCollectAlertKeys(data.patients || []);
        const shouldPlaySound = spDetectNewAlerts(currentAlertKeys);
        spPrevAlertKeys = currentAlertKeys;
        if (shouldPlaySound) spPlayAlertSound();
        spIsFirstScreenLoad = false;

        spPatients = data.patients || [];
        spDisplayPatients = spPatients;
        spModules = data.modules || [];
        spScreenMeta = data.meta || {};
        if (spScreenMeta.time_window) {
            spTimeWindow = spScreenMeta.time_window;
            $('#spTimeTabs .sp-time-tab').removeClass('active');
            if (spTimeWindow === 'custom') {
                if (spScreenMeta.time_from) {
                    spTimeFrom = spScreenMeta.time_from;
                    $('#spTimeFrom').val(spTimeFrom.length === 5 ? spTimeFrom : spTimeFrom.substring(0, 5));
                }
                if (spScreenMeta.time_to) {
                    spTimeTo = spScreenMeta.time_to;
                    $('#spTimeTo').val(spTimeTo.length === 5 ? spTimeTo : spTimeTo.substring(0, 5));
                }
            } else {
                spClearCustomTime();
                $('#spTimeTabs .sp-time-tab[data-window="' + spTimeWindow + '"]').addClass('active');
            }
        }
        spSortPatients(spPatients);
        spDisplayPatients = spPatients;
        buildScreenTable();
        updateScreenCounts();
        if (opts.isAuto) {
            const sec = spGetAutoRefreshSec();
            if (sec > 0) spAutoRefreshSecLeft = sec;
        }
        spUpdateRefreshStatusText();
        spApplyDeepLink();
    })
    .catch(() => {
        $('#btnScreen').prop('disabled', false);
        $('#spRefreshDot').removeClass('sp-refreshing');
        if (!silent) {
            $('#screenTbody').html('<tr><td colspan="20" class="text-danger text-center py-4">เชื่อมต่อ API ไม่สำเร็จ</td></tr>');
        }
    });
}

function buildScreenTable() {
    let head = '<th>HN</th><th class="text-start">ชื่อ-สกุล</th><th>สั่งยา</th><th>หอผู้ป่วย / เตียง</th>';
    spModules.forEach(m => {
        head += '<th class="small" title="' + spEsc(m.label) + '">' + spEsc(m.label.length > 12 ? m.label.substring(0,10)+'…' : m.label) + '</th>';
    });
    head += '<th>สรุป</th><th>จัดการ</th>';
    $('#screenTableHead').html(head);

    if (!spPatients.length) {
        $('#screenTbody').html('<tr><td colspan="20" class="text-center py-4 text-success fw-bold"><i class="fas fa-check-circle me-2"></i>ไม่พบผู้ป่วยในวันนี้</td></tr>');
        return;
    }
    if (!spDisplayPatients.length) {
        $('#screenTbody').html('<tr><td colspan="20" class="text-center py-4 text-muted"><i class="fas fa-clock me-2"></i>ไม่พบผู้ป่วยในช่วงเวลาที่เลือก</td></tr>');
        return;
    }
    let html = '';
    spDisplayPatients.forEach((p, idx) => {
        const pkey = spPatientKey(p);
        const isNewAlert = spIsNewAlertPatient(pkey);
        let rowCls = p.overall === 'alert' ? 'sp-row-alert' : 'sp-row-pass';
        if (isNewAlert) rowCls += ' sp-row-alert-new';
        const sumBadge = p.overall === 'alert'
            ? '<button type="button" class="badge border-0 sp-badge-click ' + (p.overall_level === 'hard' ? 'sp-badge-alert' : 'sp-badge-soft')
                + (isNewAlert ? ' sp-alert-active' : '') + '" onclick="openAlertDetail(' + idx + ')">'
                + (isNewAlert ? '<i class="fas fa-bell sp-icon-blink me-1"></i>' : '')
                + (isNewAlert ? 'ใหม่ ' : '') + 'Alert</button>'
            : '<span class="badge sp-badge-pass"><i class="fas fa-check me-1"></i>ผ่าน</span>';
        let row = '<tr class="' + rowCls + '"' + (isNewAlert ? ' data-sp-new="1"' : '') + '>'
            + '<td class="text-center fw-bold">'
            + (isNewAlert ? '<i class="fas fa-thumbtack sp-new-pin sp-icon-blink" title="Alert ใหม่"></i>' : '')
            + spEsc(p.hn) + '</td>'
            + '<td><span class="d-inline-flex align-items-center flex-wrap">'
            + (isNewAlert ? spNewBadgeHtml() : '')
            + spEsc(p.ptname) + spPatientQuickLinks(p) + '</span></td>'
            + '<td class="text-center small">' + spEsc(spOrderDateTime(p).date) + (spOrderDateTime(p).time ? '<br>' + spEsc(spOrderDateTime(p).time) : '') + '</td>'
            + '<td class="text-center small ' + (SP_PTYPE === 'OPD' ? 'text-info fw-bold' : '') + '">' + spEsc(p.ward_display) + '</td>';
        spModules.forEach(m => {
            row += '<td class="text-center">' + modCellHtml(p.modules ? p.modules[m.key] : null, idx, m.key) + '</td>';
        });
        row += '<td class="text-center">' + sumBadge + '</td>'
            + '<td class="text-center"><button type="button" class="btn btn-outline-secondary btn-sm py-0" onclick="openIntervention(' + idx + ')"><i class="fas fa-pen"></i></button></td>'
            + '</tr>';
        html += row;
    });
    $('#screenTbody').html(html);
}

function openAlertDetail(idx, focusModule) {
    const p = spDisplayPatients[idx];
    if (!p || p.overall !== 'alert') return;
    alertDetailIdx = idx;
    alertDetailFocusModule = focusModule || '';
    const od = spOrderDateTime(p);
    const visitLbl = od.date + (od.time ? ' ' + od.time : '');
    $('#alertDetailPatient').html(
        '<div class="fw-bold fs-6">' + spEsc(p.ptname) + '</div>'
        + '<div class="small text-muted mt-1">'
        + '<span class="me-3"><i class="fas fa-id-card me-1"></i>HN ' + spEsc(p.hn) + '</span>'
        + '<span class="me-3"><i class="fas fa-calendar me-1"></i>' + spEsc(visitLbl) + '</span>'
        + '<span><i class="fas fa-hospital me-1"></i>' + spEsc(p.ward_display || SP_PTYPE) + '</span>'
        + '</div>'
    );
    let html = '';
    let hasAlert = false;
    spModules.forEach(m => {
        const mod = p.modules ? p.modules[m.key] : null;
        if (!mod || mod.status !== 'alert') return;
        hasAlert = true;
        const hard = mod.level === 'hard';
        const focusCls = (focusModule && focusModule === m.key) ? ' border border-2 border-danger' : '';
        html += '<div class="sp-alert-detail-item' + (hard ? ' hard' : '') + focusCls + '">'
            + '<div class="d-flex justify-content-between align-items-start gap-2 mb-2">'
            + '<div class="fw-bold"><i class="fas fa-exclamation-triangle me-1 ' + (hard ? 'text-danger' : 'text-warning') + '"></i>' + spEsc(m.label) + '</div>'
            + '<span class="badge ' + (hard ? 'sp-badge-alert' : 'sp-badge-soft') + '">' + (hard ? 'Hard' : 'Soft') + '</span>'
            + '</div>'
            + renderSpAlertModuleBody(mod, m.key)
            + '</div>';
    });
    if (!hasAlert) {
        html = '<div class="text-muted text-center py-3">ไม่พบรายละเอียด Alert</div>';
    }
    $('#alertDetailList').html(html);
    alertDetailModal.show();
}

function openInterventionFromAlert() {
    if (alertDetailIdx < 0) return;
    alertDetailModal.hide();
    openIntervention(alertDetailIdx);
}

function openIntervention(idx) {
    const p = spDisplayPatients[idx];
    if (!p) return;
    resetInterventionModal();
    $('#iv_hn').val(p.hn_full || p.hn);
    $('#iv_vn').val(p.vn || '');
    $('#iv_an').val(p.an || '');
    $('#iv_vstdate').val(p.vstdate);
    $('#iv_ptype').val(p.patient_type || SP_PTYPE);
    $('#iv_patient_info').text('HN ' + p.hn + ' — ' + p.ptname + ' | ' + p.vstdate);
    const sel = $('#iv_module_sel').empty().prop('disabled', false);
    let firstAlertKey = '';
    spModules.forEach(m => {
        const mod = p.modules ? p.modules[m.key] : null;
        const label = m.label + (mod && mod.status === 'alert' ? ' ⚠' : '');
        sel.append('<option value="' + spEsc(m.key) + '" data-level="' + spEsc(mod ? mod.level : '') + '" data-summary="' + spEsc(mod ? mod.summary : '') + '">' + label + '</option>');
        if (!firstAlertKey && mod && mod.status === 'alert') {
            firstAlertKey = m.key;
        }
    });
    const pick = alertDetailFocusModule || firstAlertKey;
    if (pick) {
        sel.val(pick);
    }
    const lvl = sel.find('option:selected').data('level');
    $('#iv_hard_warn').toggleClass('d-none', lvl !== 'hard');
    ivModal.show();
}

function saveIntervention() {
    const opt = $('#iv_module_sel option:selected');
    const interventionId = $('#iv_intervention_id').val();
    const isEdit = !!interventionId;
    const payload = {
        hn: $('#iv_hn').val(),
        vn: $('#iv_vn').val(),
        an: $('#iv_an').val(),
        vstdate: $('#iv_vstdate').val(),
        screen_date: $('#iv_screen_date').val() || $('#screen_date').val() || $('#iv_vstdate').val(),
        patient_type: $('#iv_ptype').val(),
        module_key: $('#iv_module_sel').val(),
        alert_level: opt.data('level') || 'soft',
        alert_summary: opt.data('summary') || '',
        intervention_type: $('#iv_type').val(),
        deviation_category: $('#iv_deviation').val(),
        note_text: $('#iv_note').val(),
        alert_log_id: $('#iv_alert_log_id').val() || ''
    };
    if (isEdit) {
        payload.id = interventionId;
        payload.action = 'update_intervention';
    } else {
        payload.action = 'save_intervention';
    }
    fetch('fetch_smart_pharma_api.php' + (isEdit ? '?action=update_intervention' : ''), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message || (data.status === 'success' ? 'บันทึกแล้ว' : 'ผิดพลาด'));
        if (data.status === 'success') {
            ivModal.hide();
            $('#iv_note').val('');
            $('#iv_intervention_id').val('');
            if (SP_TAB === 'interventions') {
                fetchAlertLogs();
            }
        }
    });
}

function resetInterventionModal() {
    $('#iv_intervention_id').val('');
    $('#iv_alert_log_id').val('');
    $('#iv_screen_date').val('');
    $('#iv_type').val('recommend');
    $('#iv_deviation').val('');
    $('#iv_note').val('');
    $('#iv_module_sel').prop('disabled', false);
    $('#ivModal .modal-title').html('<i class="fas fa-notes-medical me-2"></i>บันทึก Intervention');
}

function openInterventionFromLog(logId, intervention) {
    const log = spAlertLogs.find(x => String(x.id) === String(logId));
    if (!log) return;
    resetInterventionModal();
    const isEdit = intervention && intervention.id;
    $('#iv_alert_log_id').val(log.id);
    $('#iv_screen_date').val(log.screen_date);
    $('#iv_hn').val(log.hn);
    $('#iv_vn').val(log.vn || '');
    $('#iv_an').val(log.an || '');
    $('#iv_vstdate').val(log.vstdate || log.screen_date);
    $('#iv_ptype').val(log.patient_type);
    $('#iv_patient_info').text('HN ' + spEsc(String(log.hn || '').replace(/^0+/, '') || log.hn)
        + ' — ' + spEsc(log.ptname) + ' | ' + spEsc(log.screen_date));
    const modLabel = log.module_label || log.module_key;
    $('#iv_module_sel').empty().append(
        '<option value="' + spEsc(log.module_key) + '" data-level="' + spEsc(log.alert_level) + '" data-summary="' + spEsc(log.alert_summary) + '">'
        + spEsc(modLabel) + '</option>'
    ).prop('disabled', true);
    $('#iv_hard_warn').toggleClass('d-none', log.alert_level !== 'hard');
    if (isEdit) {
        $('#iv_intervention_id').val(intervention.id);
        $('#iv_type').val(intervention.intervention_type || 'recommend');
        $('#iv_deviation').val(intervention.deviation_category || '');
        $('#iv_note').val(intervention.note_text || '');
        $('#ivModal .modal-title').html('<i class="fas fa-pen me-2"></i>แก้ไข Intervention');
    }
    ivModal.show();
}

function editInterventionFromLog(logId, interventionId) {
    fetch('fetch_smart_pharma_api.php?action=alert_log_detail&id=' + encodeURIComponent(logId), { credentials: 'same-origin' })
    .then(r => r.json())
    .then(data => {
        if (data.status !== 'success' || !data.log) {
            alert('ไม่พบรายการ');
            return;
        }
        const iv = (data.log.interventions || []).find(x => String(x.id) === String(interventionId));
        if (!iv) {
            alert('ไม่พบบันทึก Intervention');
            return;
        }
        if (!spAlertLogs.find(x => String(x.id) === String(logId))) {
            spAlertLogs.push(data.log);
        }
        openInterventionFromLog(logId, iv);
    });
}

function spLogStatusBadge(r) {
    if (r.status === 'pending') {
        if (parseInt(r.has_pharmacist_note, 10) === 1) {
            return '<span class="badge bg-warning text-dark">รอแก้ HOSxP (มีบันทึกเภสัช)</span>';
        }
        return '<span class="badge bg-danger">รอการแก้ไข</span>';
    }
    if (r.resolved_by === 'auto_cleared') {
        return '<span class="badge bg-success">แก้แล้ว — HOSxP</span>';
    }
    if (r.resolved_by === 'intervention') {
        return '<span class="badge bg-info text-dark">แก้แล้ว — Intervention</span>';
    }
    return '<span class="badge bg-secondary">แก้ไขแล้ว</span>';
}

function fetchAlertLogs() {
    const s = $('#log_start').val(), e = $('#log_end').val();
    const ptype = $('#log_ptype').val() || '';
    let url = 'fetch_smart_pharma_api.php?action=alert_logs'
        + '&start_date=' + encodeURIComponent(s)
        + '&end_date=' + encodeURIComponent(e);
    if (logStatusFilter) {
        url += '&status=' + encodeURIComponent(logStatusFilter);
    }
    if (logResolvedByFilter) {
        url += '&resolved_by=' + encodeURIComponent(logResolvedByFilter);
    }
    if (ptype) {
        url += '&patient_type=' + encodeURIComponent(ptype);
    }
    $('#logTbody').html('<tr><td colspan="10" class="text-center py-3"><i class="fas fa-spinner fa-spin"></i></td></tr>');
    return fetch(url, { credentials: 'same-origin' })
    .then(r => r.json())
    .then(data => {
        spAlertLogs = data.logs || [];
        $('#logCountBadge').text(spAlertLogs.length + ' รายการ');
        if (!spAlertLogs.length) {
            $('#logTbody').html('<tr><td colspan="10" class="text-center text-muted py-3">ไม่พบรายการ</td></tr>');
            return;
        }
        let html = '';
        spAlertLogs.forEach(r => {
            const hn = spEsc(String(r.hn || '').replace(/^0+/, '') || r.hn);
            const lvl = (r.alert_level === 'hard')
                ? '<span class="badge bg-danger">Hard</span>'
                : '<span class="badge bg-warning text-dark">Soft</span>';
            const ivCount = parseInt(r.intervention_count, 10) || 0;
            const latestIvId = parseInt(r.latest_intervention_id, 10) || 0;
            const isPending = r.status === 'pending';
            let actions = '<button type="button" class="btn btn-outline-secondary btn-sm py-0 me-1" title="ดูรายละเอียด" onclick="openAlertLogDetail(' + parseInt(r.id, 10) + ')"><i class="fas fa-eye"></i></button>';
            if (isPending) {
                actions += '<button type="button" class="btn btn-sp btn-sm py-0 me-1" title="บันทึก Intervention" onclick="openInterventionFromLog(' + parseInt(r.id, 10) + ')"><i class="fas fa-plus me-1"></i>บันทึก</button>';
            }
            if (latestIvId > 0) {
                actions += '<button type="button" class="btn btn-outline-primary btn-sm py-0" title="แก้ไข Intervention ล่าสุด" onclick="editInterventionFromLog(' + parseInt(r.id, 10) + ',' + latestIvId + ')"><i class="fas fa-pen me-1"></i>แก้ไข</button>';
            }
            html += '<tr>'
                + '<td class="text-center small">' + spEsc(r.screen_date) + '</td>'
                + '<td class="fw-bold text-center">' + hn + '</td>'
                + '<td class="small">' + spEsc(r.ptname) + '</td>'
                + '<td class="text-center"><span class="badge bg-secondary">' + spEsc(r.patient_type) + '</span></td>'
                + '<td class="small">' + spEsc(r.module_label || r.module_key) + '</td>'
                + '<td class="text-center">' + lvl + '</td>'
                + '<td class="small">' + spEsc(r.alert_summary) + '</td>'
                + '<td class="text-center small">' + spLogStatusBadge(r) + '</td>'
                + '<td class="text-center">' + (ivCount > 0 ? '<span class="badge bg-primary">' + ivCount + '</span>' : '—') + '</td>'
                + '<td class="text-center text-nowrap">' + actions + '</td>'
                + '</tr>';
        });
        $('#logTbody').html(html);
        spApplyInterventionDeepLink();
        return data;
    });
}

function openAlertLogDetail(id) {
    if (!id || !alertLogModal) return;
    $('#alertLogBody').html('<div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin"></i></div>');
    alertLogModal.show();
    fetch('fetch_smart_pharma_api.php?action=alert_log_detail&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
    .then(r => r.json())
    .then(data => {
        if (data.status !== 'success' || !data.log) {
            $('#alertLogBody').html('<div class="alert alert-warning mb-0">ไม่พบรายการ</div>');
            return;
        }
        const log = data.log;
        const typeLabels = { recommend:'แนะนำ / Consult', adjust:'ปรับยา', reject:'ไม่จ่าย / Hold', followup:'ติดตาม', document:'บันทึกเท่านั้น' };
        let html = '<div class="row g-2 mb-3 small">'
            + '<div class="col-md-6"><strong>HN:</strong> ' + spEsc(String(log.hn||'').replace(/^0+/, '') || log.hn) + ' — ' + spEsc(log.ptname) + '</div>'
            + '<div class="col-md-6"><strong>วันที่คัดกรอง:</strong> ' + spEsc(log.screen_date) + '</div>'
            + '<div class="col-md-6"><strong>โมดูล:</strong> ' + spEsc(log.module_label || log.module_key) + '</div>'
            + '<div class="col-md-6"><strong>สถานะ:</strong> ' + spLogStatusBadge(log) + '</div>'
            + '<div class="col-12"><strong>Alert:</strong> ' + spEsc(log.alert_summary) + '</div>'
            + '</div>';
        if (!spAlertLogs.find(x => String(x.id) === String(log.id))) {
            spAlertLogs.push(log);
        }
        const ivs = log.interventions || [];
        const canAdd = log.status === 'pending';
        html += '<div class="d-flex gap-2 mb-3">';
        if (canAdd) {
            html += '<button type="button" class="btn btn-sp btn-sm fw-bold" onclick="alertLogModal.hide(); openInterventionFromLog(' + parseInt(log.id, 10) + ')"><i class="fas fa-plus me-1"></i>บันทึก Intervention</button>';
        }
        html += '</div>';
        if (!ivs.length) {
            html += '<p class="text-muted small mb-0">ยังไม่มีบันทึก Intervention</p>';
        } else {
            html += '<h6 class="fw-bold mt-2">บันทึก Intervention</h6>'
                + '<table class="table table-sm table-bordered mb-0"><thead class="table-light"><tr>'
                + '<th>วันที่</th><th>ประเภท</th><th>บันทึกโดย</th><th>หมายเหตุ</th><th>ปิด Alert</th><th></th></tr></thead><tbody>';
            ivs.forEach(iv => {
                const closed = parseInt(iv.resolves_alert, 10) === 1
                    ? '<span class="badge bg-success">ใช่</span>'
                    : '<span class="badge bg-secondary">ไม่ (Hard)</span>';
                html += '<tr>'
                    + '<td class="small">' + spEsc((iv.created_at||'').substring(0,16)) + '</td>'
                    + '<td class="small">' + spEsc(typeLabels[iv.intervention_type] || iv.intervention_type) + '</td>'
                    + '<td class="small">' + spEsc(iv.recorded_name) + '</td>'
                    + '<td class="small">' + spEsc(iv.note_text) + '</td>'
                    + '<td class="text-center">' + closed + '</td>'
                    + '<td class="text-center"><button type="button" class="btn btn-outline-primary btn-sm py-0" onclick="alertLogModal.hide(); editInterventionFromLog(' + parseInt(log.id, 10) + ',' + parseInt(iv.id, 10) + ')"><i class="fas fa-pen"></i></button></td>'
                    + '</tr>';
            });
            html += '</tbody></table>';
        }
        $('#alertLogBody').html(html);
    });
}

function saveModuleSettings() {
    const modules = {};
    $('.mod-en').each(function() {
        const k = $(this).data('key');
        modules[k] = {
            enabled: $(this).is(':checked'),
            enabled_opd: $('.mod-opd[data-key="' + k + '"]').is(':checked'),
            enabled_ipd: $('.mod-ipd[data-key="' + k + '"]').is(':checked')
        };
    });
    fetch('fetch_smart_pharma_api.php?action=save_modules', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ modules: modules })
    })
    .then(r => r.json())
    .then(data => alert(data.message || 'บันทึกแล้ว'));
}
</script>
</body>
</html>
