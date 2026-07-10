<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/drug_refer_helper.php';
include('connect.php');
mysqli_set_charset($con, 'utf8');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Refer ยาแยกเขต - THAM SAFE</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', Tahoma, sans-serif; background: #f4f7f6; }
        .period-tabs .nav-link { font-weight: 600; color: #1f4d3b; border-radius: 8px 8px 0 0; }
        .period-tabs .nav-link.active { background: #1f4d3b; color: #fff; border-color: #1f4d3b; }
        .district-sidebar-card {
            display: flex;
            flex-direction: column;
            position: sticky;
            top: 12px;
            max-height: calc(100vh - 100px);
            overflow: hidden;
        }
        .district-list-panel {
            max-height: 220px;
            overflow-y: auto;
            flex-shrink: 0;
            background: #fafbfc;
        }
        .district-recommend-split {
            border-top: 2px solid #e9ecef;
            background: linear-gradient(180deg, #fffdf8 0%, #fff 100%);
        }
        .recommend-list-panel {
            flex: 1 1 auto;
            min-height: 140px;
            max-height: 320px;
            overflow-y: auto;
            background: #fffdf8;
        }
        .recommend-item { padding: 8px 12px; border-bottom: 1px solid #f0e6d8; font-size: .82rem; }
        .recommend-item:last-child { border-bottom: 0; }
        .recommend-item .rec-name { font-weight: 600; line-height: 1.35; }
        .recommend-item .rec-sub {
            font-size: .75rem;
            color: #6c757d;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .district-item { padding: 10px 12px; border-bottom: 1px solid #e9ecef; cursor: pointer; transition: background .15s; }
        .district-item.district-item-all { background: #f0f7f4; border-bottom: 2px solid #c8e6d4; font-weight: 600; }
        .district-item.district-item-all.active { background: #1f4d3b; color: #fff; }
        .district-item:hover { background: #e8f4fd; }
        .district-item.active { background: #1f4d3b; color: #fff; }
        .district-item.active .text-muted { color: rgba(255,255,255,.85) !important; }
        .district-item .badge { min-width: 2.5rem; }
        #patientTable { font-size: .88rem; }
        #patientTable thead th { background: #e8f5ef; }
        #patientTable th.sortable { cursor: pointer; user-select: none; white-space: nowrap; }
        #patientTable th.sortable:hover { background: #d4ebdf; }
        #patientTable th.sortable .sort-icon { font-size: .72rem; margin-left: 2px; opacity: .35; }
        #patientTable th.sortable.active { color: #1f4d3b; }
        #patientTable th.sortable.active .sort-icon { opacity: 1; }
        .loading-overlay { min-height: 120px; display: flex; align-items: center; justify-content: center; color: #6c757d; }
        #pttypeFilterList .form-check { margin-bottom: .15rem; }
        #pttypeFilterList .form-check-label { font-size: .82rem; }
        .pttype-sss { opacity: .55; }
        .match-pct-link { font-weight: 700; text-decoration: none; white-space: nowrap; }
        .match-pct-link:hover { text-decoration: underline; }
        .match-pct-high { color: #198754; }
        .match-pct-mid { color: #fd7e14; }
        .match-pct-low { color: #dc3545; }
        .match-pct-na { color: #6c757d; }
        .nd-check { color: #198754; }
        .nd-similar { color: #fd7e14; }
        .nd-cross { color: #dc3545; }
        #matchDetailTable thead th { background: #e8f5ef; font-size: .82rem; }
        #matchDetailTable { font-size: .85rem; }
        #printArea { display: none; }
        @media print {
            .no-print, .no-print-section, #sidebar, .sidebar-header, .sidebar-user, .developer-credit { display: none !important; }
            #content { margin: 0 !important; width: 100% !important; }
            #printArea { display: block !important; }
            .wrapper { display: block !important; }
        }
    </style>
</head>
<body>
<div class="wrapper">
    <?php include('sidebar.php'); ?>
    <div id="content" class="content">
        <div class="container-fluid py-3 no-print-section">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                <h4 class="fw-bold mb-0"><i class="fas fa-map-marked-alt me-2 text-success"></i>Refer ยาแยกเขต</h4>
                <a href="drug_refer.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-share-square me-1"></i>Refer รายคน</a>
            </div>

            <div class="card shadow-sm border-0 mb-3">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <div>
                            <div class="fw-bold small text-muted mb-1">ช่วงเวลา</div>
                            <ul class="nav nav-tabs period-tabs border-0" id="periodTabs">
                                <li class="nav-item"><button type="button" class="nav-link active" data-preset="today">วันนี้</button></li>
                                <li class="nav-item"><button type="button" class="nav-link" data-preset="d3">3 วัน</button></li>
                                <li class="nav-item"><button type="button" class="nav-link" data-preset="d7">7 วัน</button></li>
                                <li class="nav-item"><button type="button" class="nav-link" data-preset="m1">1 เดือน</button></li>
                                <li class="nav-item"><button type="button" class="nav-link" data-preset="m3">3 เดือน</button></li>
                            </ul>
                        </div>
                        <div id="periodSummary" class="small text-muted"></div>
                    </div>
                    <div class="row g-3 align-items-end">
                        <div class="col-md-2">
                            <label class="form-label fw-bold small">ประเภท Visit</label>
                            <select id="visitType" class="form-select">
                                <option value="all" selected>ทั้งหมด</option>
                                <option value="opd">OPD (VN)</option>
                                <option value="ipd">IPD (AN)</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold small">วันที่เริ่ม</label>
                            <input type="date" id="startDate" class="form-control">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold small">วันที่สิ้นสุด</label>
                            <input type="date" id="endDate" class="form-control">
                        </div>
                        <div class="col-md-2">
                            <button type="button" class="btn btn-success w-100" id="btnLoad">
                                <i class="fas fa-sync-alt me-1"></i>โหลดรายการ
                            </button>
                        </div>
                        <div class="col-md-4">
                            <div id="scopeInfo" class="small text-muted border rounded p-2 bg-light h-100"></div>
                        </div>
                    </div>
                    <div id="loadNote" class="small text-warning mt-2 d-none">
                        <i class="fas fa-hourglass-half me-1"></i>ช่วงเวลายาวอาจใช้เวลาโหลด 1–3 นาที กรุณารอสักครู่
                    </div>
                    <div class="border rounded bg-light p-2 mt-3">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <span class="fw-bold small"><i class="fas fa-id-card me-1"></i>กรองสิทธิการรักษา</span>
                            <span id="pttypeFilterCount" class="badge bg-secondary">0</span>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="btnPttypeDefault">นอกเขต</button>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="btnPttypeInside">ในเขต</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnPttypeAll">ทั้งหมด</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnPttypeNone">ยกเลิกทั้งหมด</button>
                        </div>
                        <div id="pttypeFilterList" class="row g-1 small" style="max-height:180px;overflow-y:auto;"></div>
                    </div>
                </div>
            </div>

            <div class="row g-3 align-items-start">
                <div class="col-lg-4">
                    <div class="card shadow-sm border-0 district-sidebar-card">
                        <div class="card-header bg-white fw-bold py-2 d-flex justify-content-between align-items-center border-bottom-0">
                            <span><i class="fas fa-map me-1"></i>อำเภอ</span>
                            <span id="districtCount" class="badge bg-secondary">0</span>
                        </div>
                        <div id="districtEmpty" class="p-3 text-muted small">กดโหลดรายการเพื่อแสดงอำเภอ</div>
                        <div id="districtList" class="district-list-panel d-none"></div>

                        <div class="district-recommend-split flex-grow-1 d-flex flex-column min-h-0">
                            <div class="card-header bg-white fw-bold py-2 border-top-0 border-bottom-0">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <span><i class="fas fa-lightbulb me-1 text-warning"></i>รายการแนะนำที่ควรมีใน รพ.</span>
                                    <span id="recommendCount" class="badge bg-warning text-dark">0</span>
                                </div>
                                <div id="recommendHospital" class="small text-muted fw-normal mt-1"></div>
                            </div>
                            <div id="recommendEmpty" class="px-3 pb-3 text-muted small">เลือกอำเภอเพื่อแสดงรายการแนะนำ</div>
                            <div id="recommendLoading" class="px-3 pb-3 text-muted small d-none"><i class="fas fa-spinner fa-spin me-1"></i>กำลังวิเคราะห์รายการ...</div>
                            <div id="recommendList" class="recommend-list-panel d-none"></div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-8">
                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-white fw-bold py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <span id="patientPanelTitle"><i class="fas fa-users me-1"></i>รายชื่อผู้ป่วย</span>
                            <div class="d-flex align-items-center gap-2 no-print">
                                <div id="patientToolbar" class="btn-group btn-group-sm d-none">
                                    <button type="button" class="btn btn-outline-secondary" id="btnPrintPatients">
                                        <i class="fas fa-print me-1"></i>พิมพ์
                                    </button>
                                    <button type="button" class="btn btn-outline-success" id="btnExportExcel">
                                        <i class="fas fa-file-excel me-1"></i>Excel
                                    </button>
                                </div>
                                <span id="patientCount" class="badge bg-primary">0</span>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div id="patientEmpty" class="p-3 text-muted small">เลือกอำเภอจากรายการด้านซ้าย</div>
                            <div id="patientLoading" class="loading-overlay d-none"><i class="fas fa-spinner fa-spin me-2"></i>กำลังโหลด...</div>
                            <div id="patientWrap" class="d-none">
                                <div class="table-responsive">
                                    <table id="patientTable" class="table table-hover table-sm mb-0">
                                        <thead>
                                            <tr>
                                                <th class="col-amp d-none">อำเภอ</th>
                                                <th class="sortable" data-sort-col="hn">HN <i class="fas fa-sort sort-icon"></i></th>
                                                <th class="sortable" data-sort-col="full_name">ชื่อผู้ป่วย <i class="fas fa-sort sort-icon"></i></th>
                                                <th class="sortable" data-sort-col="tmb_name">ตำบล <i class="fas fa-sort sort-icon"></i></th>
                                                <th class="sortable" data-sort-col="address">ที่อยู่ <i class="fas fa-sort sort-icon"></i></th>
                                                <th class="sortable" data-sort-col="pttype_name">สิทธิการรักษา <i class="fas fa-sort sort-icon"></i></th>
                                                <th class="sortable text-center" data-sort-col="visit_count">Visit <i class="fas fa-sort sort-icon"></i></th>
                                                <th class="sortable" data-sort-col="last_order">ล่าสุด <i class="fas fa-sort sort-icon"></i></th>
                                                <th class="sortable text-center" data-sort-col="match_percent">เทียบบัญชี <i class="fas fa-sort sort-icon"></i></th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody id="patientBody"></tbody>
                                    </table>
                                </div>
                                <div class="d-flex justify-content-between align-items-center p-2 border-top small">
                                    <span id="patientPageInfo" class="text-muted"></span>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-secondary" id="btnPrevPage" disabled>ก่อนหน้า</button>
                                        <button type="button" class="btn btn-outline-secondary" id="btnNextPage" disabled>ถัดไป</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div id="printArea" class="p-4"></div>
    </div>
</div>

<div class="modal fade" id="matchDetailModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title fw-bold" id="matchDetailTitle">รายละเอียดการเทียบบัญชียา</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="matchDetailSummary" class="small mb-3 text-muted"></div>
                <div id="matchDetailLoading" class="text-center py-4 text-muted d-none">
                    <i class="fas fa-spinner fa-spin me-2"></i>กำลังโหลดรายการยา...
                </div>
                <div id="matchDetailError" class="alert alert-danger d-none"></div>
                <div class="table-responsive">
                    <table id="matchDetailTable" class="table table-sm table-hover mb-0 d-none">
                        <thead>
                            <tr>
                                <th>รายการยา</th>
                                <th class="text-center" width="90">ผลเทียบ</th>
                                <th>ยาในบัญชี รพ.</th>
                                <th class="text-center" width="70">คะแนน</th>
                            </tr>
                        </thead>
                        <tbody id="matchDetailBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-outline-secondary btn-sm no-print" id="btnPrintMatchDetail">
                    <i class="fas fa-print me-1"></i>พิมพ์
                </button>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">ปิด</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>

<script src="js/jquery-3.7.0.min.js"></script>
<script src="js/bootstrap.bundle.min.js"></script>
<script src="js/drug_refer_match_print.js"></script>
<script>
let currentPreset = 'today';
let currentPeriod = null;
let districtsData = [];
let selectedDistrict = null;
let patientPage = 1;
let patientPages = 1;
let hospitalChwpart = '';
let pttypeOptions = [];
let defaultPttypes = [];
let insidePttypes = [];
let lastMatchDetailData = null;
let patientSort = 'last_order_desc';
let lastPatientMeta = null;

function esc(s) { return $('<div>').text(s ?? '').html(); }

function renderDrugNameHtml(r) {
    const primary = r.display_primary || r.tmtdrug || r.drug_name_tmt || r.drug_name || '';
    const secondary = r.display_secondary || '';
    let html = '<strong>' + esc(primary) + '</strong>';
    if (secondary) {
        html += '<br><small class="text-muted">' + esc(secondary) + '</small>';
    }
    return html;
}

function renderMatchedNameHtml(match) {
    if (!match || typeof match !== 'object') return '-';
    const primary = match.matched_display_primary || match.matched_display || match.matched_tmtdrug || '-';
    const secondary = match.matched_display_secondary || '';
    let html = esc(primary);
    if (secondary) {
        html += '<br><small class="text-muted">' + esc(secondary) + '</small>';
    }
    return html;
}

function renderSuggestLabel(s) {
    const primary = s.display_primary || s.display_name || s.tmtdrug || '';
    const secondary = s.display_secondary || '';
    let label = primary;
    if (secondary && secondary !== primary) {
        label += ' — ' + secondary;
    }
    return label;
}

function todayStr() {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

function applyPresetToInputs(preset) {
    const end = $('#endDate').val() || todayStr();
    let start = end;
    if (preset === 'today') start = end;
    else if (preset === 'd3') start = shiftDate(end, -2);
    else if (preset === 'd7') start = shiftDate(end, -6);
    else if (preset === 'm1') start = shiftMonth(end, -1);
    else if (preset === 'm3') start = shiftMonth(end, -3);
    $('#startDate').val(start);
    $('#endDate').val(end);
}

function shiftDate(dateStr, days) {
    const d = new Date(dateStr + 'T00:00:00');
    d.setDate(d.getDate() + days);
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

function shiftMonth(dateStr, months) {
    const d = new Date(dateStr + 'T00:00:00');
    d.setMonth(d.getMonth() + months);
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

function updatePeriodSummary(period, elapsed, visitLabel, pttypeLabel) {
    if (!period) return;
    let html = '<i class="fas fa-calendar-alt me-1"></i>' + esc(period.label) +
        ' (' + esc(period.start_date) + ' – ' + esc(period.end_date) + ')';
    if (visitLabel) html += '<br><i class="fas fa-filter me-1"></i>' + esc(visitLabel);
    if (pttypeLabel) html += '<br><i class="fas fa-id-card me-1"></i>' + esc(pttypeLabel);
    if (elapsed) html += '<br><span class="text-success">โหลด ' + elapsed + ' วินาที</span>';
    $('#periodSummary').html(html);
}

function getVisitType() {
    return $('#visitType').val() || 'all';
}

function renderPttypeFilters() {
    const $list = $('#pttypeFilterList').empty();
    pttypeOptions.forEach((opt, i) => {
        const id = 'pttypeChk' + i;
        const cls = opt.is_social_security ? ' pttype-sss' : '';
        $list.append(
            '<div class="col-md-6 col-lg-4">' +
            '<div class="form-check' + cls + '">' +
            '<input class="form-check-input pttype-chk" type="checkbox" id="' + id + '" value="' + esc(opt.pttype) + '">' +
            '<label class="form-check-label" for="' + id + '">' + esc(opt.name) + '</label>' +
            '</div></div>'
        );
    });
    updatePttypeCount();
}

function setPttypeSelection(codes) {
    const set = {};
    (codes || []).forEach(c => { set[c] = true; });
    $('.pttype-chk').each(function() {
        $(this).prop('checked', !!set[$(this).val()]);
    });
    updatePttypeCount();
}

function updatePttypeCount() {
    const n = $('.pttype-chk:checked').length;
    $('#pttypeFilterCount').text(n);
}

function getPttypeFilterPayload() {
    const selected = [];
    $('.pttype-chk:checked').each(function() { selected.push($(this).val()); });
    if (!selected.length) return [];
    if (selected.length === pttypeOptions.length) return 'all';
    return selected;
}

function matchesPttypePreset(payload, presetCodes) {
    if (payload === 'all' || !Array.isArray(payload) || !presetCodes.length) return false;
    if (payload.length !== presetCodes.length) return false;
    const set = {};
    presetCodes.forEach(c => { set[c] = true; });
    return payload.every(c => !!set[c]);
}

const patientSortLabels = {
    hn: 'HN',
    full_name: 'ชื่อผู้ป่วย',
    tmb_name: 'ตำบล',
    address: 'ที่อยู่',
    pttype_name: 'สิทธิการรักษา',
    visit_count: 'Visit',
    last_order: 'ล่าสุด',
    match_percent: 'เทียบบัญชี'
};

function parsePatientSort() {
    const m = (patientSort || '').match(/^(hn|full_name|tmb_name|address|pttype_name|visit_count|last_order|match_percent)_(asc|desc)$/);
    if (!m) return { col: 'last_order', dir: 'desc' };
    return { col: m[1], dir: m[2] };
}

function getPatientSortLabel() {
    const s = parsePatientSort();
    const name = patientSortLabels[s.col] || 'ล่าสุด';
    return name + ' (' + (s.dir === 'asc' ? 'A-Z' : 'Z-A') + ')';
}

function updateSortHeaders() {
    const s = parsePatientSort();
    $('#patientTable thead th.sortable').each(function() {
        const col = $(this).data('sort-col');
        const $icon = $(this).find('.sort-icon');
        const active = col === s.col;
        $(this).toggleClass('active', active);
        if (active) {
            $icon.attr('class', 'fas sort-icon ' + (s.dir === 'asc' ? 'fa-sort-alpha-down' : 'fa-sort-alpha-down-alt'));
        } else {
            $icon.attr('class', 'fas fa-sort sort-icon');
        }
    });
}

function buildPatientPayload(extra) {
    return Object.assign({
        action: 'patients',
        preset: currentPreset === 'custom' ? 'custom' : currentPreset,
        start_date: $('#startDate').val(),
        end_date: $('#endDate').val(),
        district_key: selectedDistrict.district_key,
        visit_type: getVisitType(),
        pttype_filter: getPttypeFilterPayload(),
        sort: patientSort
    }, extra || {});
}

function fetchPatientsData(extra) {
    return fetch('fetch_drug_refer_district_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(buildPatientPayload(extra))
    }).then(r => r.json());
}

function isAllDistrictView() {
    return !!(selectedDistrict && selectedDistrict.amppart === 'all');
}

function patientRowsForExport(patients) {
    const showAmp = isAllDistrictView();
    return (patients || []).map((p, i) => {
        const row = [i + 1];
        if (showAmp) row.push(p.amp_name || '');
        row.push(
            p.hn || '',
            p.full_name || '',
            p.tmb_name || '',
            p.address || '',
            p.pttype_name || '',
            p.visit_count || 0,
            p.last_vstdate_display || p.last_vstdate || '',
            formatMatchPercent(p)
        );
        return row;
    });
}

function getExportFilename(ext) {
    const district = (lastPatientMeta && lastPatientMeta.district && lastPatientMeta.district.amp_name) || 'district';
    const period = (currentPeriod && currentPeriod.end_date) || todayStr();
    const safe = district.replace(/[\\/:*?"<>|]/g, '_');
    return 'refer_district_' + safe + '_' + period + '.' + ext;
}

function exportPatientsExcel(patients) {
    if (typeof XLSX === 'undefined') {
        alert('ไม่สามารถโหลดไลบรารี Excel ได้');
        return;
    }
    const showAmp = isAllDistrictView();
    const headers = ['ลำดับ'];
    if (showAmp) headers.push('อำเภอ');
    headers.push('HN', 'ชื่อผู้ป่วย', 'ตำบล', 'ที่อยู่', 'สิทธิการรักษา', 'Visit', 'ล่าสุด', 'เทียบบัญชี (%)');
    const ws = XLSX.utils.aoa_to_sheet([headers].concat(patientRowsForExport(patients)));
    const cols = [{ wch: 6 }];
    if (showAmp) cols.push({ wch: 16 });
    cols.push({ wch: 12 }, { wch: 24 }, { wch: 16 }, { wch: 40 }, { wch: 28 }, { wch: 8 }, { wch: 18 }, { wch: 12 });
    ws['!cols'] = cols;
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'รายชื่อผู้ป่วย');
    XLSX.writeFile(wb, getExportFilename('xlsx'));
}

function buildPrintHtml(patients, meta) {
    const district = (meta.district && meta.district.amp_name) || '';
    const showAmp = meta.district && meta.district.amppart === 'all';
    const period = meta.period || currentPeriod || {};
    const visitLabel = meta.visit_type_label || '';
    const pttypeLabel = (meta.pttype_filter && meta.pttype_filter.label) || getPttypeFilterLabel();
    const sortLabel = getPatientSortLabel();
    let html = '<div style="font-family:Sarabun,Tahoma,sans-serif;font-size:13px;">';
    html += '<h4 style="margin:0 0 8px;color:#1f4d3b;">Refer ยาแยกเขต — ' + esc(district) + '</h4>';
    html += '<div style="margin-bottom:12px;font-size:12px;line-height:1.6;">';
    html += '<div>ช่วงเวลา: <strong>' + esc(period.label || '') + '</strong> (' + esc(period.start_date || '') + ' – ' + esc(period.end_date || '') + ')</div>';
    if (visitLabel) html += '<div>ประเภท Visit: ' + esc(visitLabel) + '</div>';
    html += '<div>สิทธิการรักษา: ' + esc(pttypeLabel) + '</div>';
    html += '<div>เรียงตาม: ' + esc(sortLabel) + ' | จำนวน ' + (patients.length || 0) + ' ราย</div>';
    html += '</div>';
    html += '<table style="width:100%;border-collapse:collapse;font-size:11px;">';
    html += '<thead><tr>';
    const printHeaders = ['ลำดับ'];
    if (showAmp) printHeaders.push('อำเภอ');
    printHeaders.push('HN', 'ชื่อผู้ป่วย', 'ตำบล', 'ที่อยู่', 'สิทธิการรักษา', 'Visit', 'ล่าสุด', 'เทียบบัญชี (%)');
    printHeaders.forEach(h => {
        html += '<th style="border:1px solid #ccc;padding:4px 6px;background:#e8f5ef;">' + esc(h) + '</th>';
    });
    html += '</tr></thead><tbody>';
    (patients || []).forEach((p, i) => {
        html += '<tr>';
        const cells = [i + 1];
        if (showAmp) cells.push(p.amp_name || '-');
        cells.push(p.hn, p.full_name, p.tmb_name || '-', p.address || '-', p.pttype_name || '-', p.visit_count || 0, p.last_vstdate_display || p.last_vstdate || '-', formatMatchPercent(p));
        cells.forEach(cell => {
            html += '<td style="border:1px solid #ddd;padding:3px 6px;vertical-align:top;">' + esc(cell) + '</td>';
        });
        html += '</tr>';
    });
    html += '</tbody></table>';
    html += '<p style="margin-top:16px;font-size:11px;color:#666;">พิมพ์เมื่อ ' + esc(new Date().toLocaleString('th-TH')) + '</p></div>';
    return html;
}

function printPatientList(patients, meta) {
    $('#printArea').html(buildPrintHtml(patients, meta));
    window.print();
}

function runPatientExport(mode) {
    if (!selectedDistrict) {
        alert('กรุณาเลือกอำเภอก่อน');
        return;
    }
    const $btn = mode === 'print' ? $('#btnPrintPatients') : $('#btnExportExcel');
    const oldHtml = $btn.html();
    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');
    fetchPatientsData({ all: true, page: 1 })
        .then(res => {
            $btn.prop('disabled', false).html(oldHtml);
            if (res.status !== 'success') {
                alert(res.message || 'โหลดข้อมูลไม่สำเร็จ');
                return;
            }
            const patients = res.patients || [];
            if (!patients.length) {
                alert('ไม่มีข้อมูลสำหรับส่งออก');
                return;
            }
            if (mode === 'print') printPatientList(patients, res);
            else exportPatientsExcel(patients);
        })
        .catch(() => {
            $btn.prop('disabled', false).html(oldHtml);
            alert('โหลดข้อมูลไม่สำเร็จ');
        });
}

function formatMatchPercent(p) {
    if (p.match_note) return p.match_note;
    if (p.match_percent === null || p.match_percent === undefined || p.match_total === 0) return '-';
    return (p.match_percent || 0) + '% (' + (p.match_matched || 0) + '/' + (p.match_total || 0) + ')';
}

function matchPercentClass(p) {
    if (p.match_note || p.match_total === 0) return 'match-pct-na';
    const n = p.match_percent || 0;
    if (n >= 80) return 'match-pct-high';
    if (n >= 50) return 'match-pct-mid';
    return 'match-pct-low';
}

function canonicalHn(hn) {
    hn = String(hn || '').trim();
    if (!hn || !/^\d+$/.test(hn)) return hn;
    const stripped = hn.replace(/^0+/, '') || '0';
    return stripped.padStart(9, '0');
}

function buildReferDistrictUrl(p) {
    const parts = ['hn=' + encodeURIComponent(canonicalHn(p.hn || ''))];
    if (p.last_vstdate) parts.push('vstdate=' + encodeURIComponent(String(p.last_vstdate)));
    parts.push('from=district');
    return 'drug_refer.php?' + parts.join('&');
}

function renderMatchPercentCell(p) {
    if (p.match_note) {
        return '<span class="small text-muted" title="' + esc(p.match_note) + '">-</span>';
    }
    if (!p.match_total) {
        return '<span class="small text-muted">-</span>';
    }
    const pct = p.match_percent || 0;
    const title = (p.match_hospital || '') + ' — ' + (p.match_matched || 0) + '/' + (p.match_total || 0) + ' รายการ (ยอมรับชื่อคล้าย ≥90%)';
    return '<a href="#" class="match-pct-link ' + matchPercentClass(p) + ' match-detail-link" data-hn="' + esc(p.hn) + '" data-amp-name="' + esc(p.amp_name || '') + '" title="' + esc(title) + '">' +
        pct + '%</a>' +
        '<div class="small text-muted">' + (p.match_matched || 0) + '/' + (p.match_total || 0) + '</div>';
}

function renderMatchStatusCell(match) {
    if (!match || typeof match !== 'object') {
        return '<span class="nd-cross"><i class="fas fa-times"></i></span>';
    }
    const st = match.status || 'none';
    if (st === 'exact') {
        return '<span class="nd-check" title="ตรง 100%"><i class="fas fa-check"></i></span>';
    }
    if (st === 'similar') {
        return '<span class="nd-similar" title="ใกล้เคียง ' + (match.score || 0) + '%"><i class="fas fa-lightbulb"></i></span>';
    }
    return '<span class="nd-cross" title="ไม่พบในบัญชี"><i class="fas fa-times"></i></span>';
}

function openMatchDetail(hn, ampName) {
    if (!selectedDistrict || !hn) return;
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('matchDetailModal'));
    $('#matchDetailTitle').text('รายละเอียดการเทียบบัญชียา — HN ' + hn);
    $('#matchDetailSummary').empty();
    $('#matchDetailError').addClass('d-none');
    $('#matchDetailTable').addClass('d-none');
    $('#matchDetailLoading').removeClass('d-none');
    $('#matchDetailBody').empty();
    lastMatchDetailData = null;
    modal.show();

    const payload = {
        action: 'match_detail',
        hn: hn,
        district_key: selectedDistrict.district_key,
        preset: currentPreset === 'custom' ? 'custom' : currentPreset,
        start_date: $('#startDate').val(),
        end_date: $('#endDate').val(),
        visit_type: getVisitType(),
        pttype_filter: getPttypeFilterPayload()
    };
    if (ampName) payload.amp_name = ampName;

    fetch('fetch_drug_refer_district_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(res => {
        $('#matchDetailLoading').addClass('d-none');
        if (res.status !== 'success') {
            $('#matchDetailError').removeClass('d-none').text(res.message || 'โหลดไม่สำเร็จ');
            return;
        }
        lastMatchDetailData = res;
        const s = res.summary || {};
        const p = res.patient || {};
        let summary = '<strong>' + esc(p.full_name || '') + '</strong> | HN: ' + esc(p.hn || hn);
        if (p.address) summary += '<br><i class="fas fa-map-marker-alt me-1"></i>' + esc(p.address);
        summary += '<br><i class="fas fa-hospital me-1"></i>เทียบกับ <strong>' + esc(res.hospital || '') + '</strong>';
        summary += ' | ช่วง ' + esc((res.period && res.period.start_date) || '') + ' – ' + esc((res.period && res.period.end_date) || '');
        summary += '<br>รายการยา <strong>' + (s.total || 0) + '</strong> | ';
        summary += '<span class="text-success">ตรง ' + (s.exact || 0) + '</span> | ';
        summary += '<span class="text-warning">ใกล้เคียง ≥90% ' + (s.similar || 0) + '</span> | ';
        summary += '<span class="text-danger">ไม่พบ ' + (s.missing || 0) + '</span> | ';
        summary += '<strong class="' + matchPercentClass({ match_percent: s.match_percent, match_total: s.total }) + '">' + (s.match_percent || 0) + '%</strong>';
        $('#matchDetailSummary').html(summary);

        const $body = $('#matchDetailBody').empty();
        (res.rows || []).forEach(r => {
            const match = r.match || {};
            const score = match.status === 'exact' ? '100' : (match.score || 0);
            let suggest = '';
            const suggestions = match.suggestions || [];
            if (match.status !== 'exact' && suggestions.length) {
                suggest = '<div class="small text-muted mt-1">แนะนำ: ' + suggestions.slice(0, 2).map(x => esc(renderSuggestLabel(x)) + ' (' + x.score + '%)').join(', ') + '</div>';
            }
            $body.append(
                '<tr>' +
                '<td>' + renderDrugNameHtml(r) +
                (r.method ? '<br><small class="text-muted">' + esc(r.method) + '</small>' : '') + '</td>' +
                '<td class="text-center">' + renderMatchStatusCell(match) + '</td>' +
                '<td>' + renderMatchedNameHtml(match) + suggest + '</td>' +
                '<td class="text-center">' + (match.status === 'none' ? '-' : esc(score)) + '</td>' +
                '</tr>'
            );
        });
        $('#matchDetailTable').removeClass('d-none');
    })
    .catch(() => {
        $('#matchDetailLoading').addClass('d-none');
        $('#matchDetailError').removeClass('d-none').text('โหลดไม่สำเร็จ');
    });
}

function printMatchDetail() {
    if (!lastMatchDetailData) {
        alert('ยังไม่มีข้อมูลสำหรับพิมพ์');
        return;
    }
    $('#printArea').html(DrugReferMatchPrint.buildMatchDetailPrintHtml(lastMatchDetailData, {
        footerLabel: 'THAM Hos-Safe — Refer ยาแยกเขต'
    }));
    setTimeout(function() { window.print(); }, 150);
}

function getPttypeFilterLabel() {
    const payload = getPttypeFilterPayload();
    if (payload === 'all') return 'สิทธิทั้งหมด';
    if (!payload.length) return 'ยังไม่ได้เลือกสิทธิ';
    if (matchesPttypePreset(payload, defaultPttypes)) {
        return 'นอกเขต (ยกเว้นประกันสังคม)';
    }
    if (matchesPttypePreset(payload, insidePttypes)) {
        return 'ในเขต (ยกเว้นประกันสังคม)';
    }
    return 'สิทธิที่เลือก ' + payload.length + ' รายการ';
}

function renderRecommendations(res) {
    const items = res.items || [];
    $('#recommendCount').text(items.length);
    const hospital = (res.district && res.district.match_hospital) || res.hospital || '';
    const below = res.patients_below_threshold || 0;
    const total = res.patients_total || 0;
    let sub = '';
    if (hospital) {
        sub = esc(hospital);
        if (total > 0) {
            sub += ' — ผู้ป่วยเทียบ < ' + (res.patient_threshold_percent || 90) + '%: ' + below + '/' + total + ' ราย';
        }
    }
    $('#recommendHospital').html(sub);

    const $list = $('#recommendList').empty();
    if (!items.length) {
        $('#recommendEmpty').removeClass('d-none').text(
            res.note || (below === 0 && total > 0
                ? 'ไม่พบรายการยาที่ไม่ตรงจากผู้ป่วยเทียบบัญชี < ' + (res.patient_threshold_percent || 90) + '%'
                : 'ไม่พบรายการแนะนำในช่วงเวลานี้')
        );
        $('#recommendList').addClass('d-none');
        return;
    }
    $('#recommendEmpty').addClass('d-none');
    $('#recommendList').removeClass('d-none');
    items.forEach((item, i) => {
        const primary = item.display_primary || item.display_name || item.tmtdrug || '-';
        const secondary = item.display_secondary || '';
        let html = '<div class="recommend-item">' +
            '<div class="d-flex justify-content-between align-items-start gap-2">' +
            '<div class="flex-grow-1">' +
            '<div class="rec-name">' + (i + 1) + '. ' + esc(primary) + '</div>';
        if (secondary) {
            html += '<div class="rec-sub">' + esc(secondary) + '</div>';
        }
        html += '</div>' +
            '<span class="badge bg-secondary shrink-0" title="จำนวนผู้ป่วย">' + (item.patient_count || 0) + '</span>' +
            '</div></div>';
        $list.append(html);
    });
}

function resetRecommendations(message) {
    $('#recommendCount').text('0');
    $('#recommendHospital').empty();
    $('#recommendLoading').addClass('d-none');
    $('#recommendList').addClass('d-none').empty();
    $('#recommendEmpty').removeClass('d-none').text(message || 'เลือกอำเภอเพื่อแสดงรายการแนะนำ');
}

function loadRecommendations() {
    if (!selectedDistrict) {
        resetRecommendations();
        return;
    }
    if (selectedDistrict.amppart === 'all') {
        resetRecommendations('เลือกอำเภอเพื่อดูรายการแนะนำเฉพาะ รพ.');
        return;
    }
    $('#recommendEmpty').addClass('d-none');
    $('#recommendList').addClass('d-none');
    $('#recommendLoading').removeClass('d-none');

    fetch('fetch_drug_refer_district_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'recommendations',
            district_key: selectedDistrict.district_key,
            preset: currentPreset === 'custom' ? 'custom' : currentPreset,
            start_date: $('#startDate').val(),
            end_date: $('#endDate').val(),
            visit_type: getVisitType(),
            pttype_filter: getPttypeFilterPayload()
        })
    })
    .then(r => r.json())
    .then(res => {
        $('#recommendLoading').addClass('d-none');
        if (res.status !== 'success') {
            resetRecommendations(res.message || 'โหลดรายการแนะนำไม่สำเร็จ');
            return;
        }
        renderRecommendations(res);
    })
    .catch(() => {
        $('#recommendLoading').addClass('d-none');
        resetRecommendations('โหลดรายการแนะนำไม่สำเร็จ');
    });
}

function renderDistricts(districts) {
    const $list = $('#districtList').empty();
    const rawCount = districts.length;
    if (!rawCount) {
        districtsData = [];
        $('#districtEmpty').removeClass('d-none').text('ไม่พบผู้ป่วยในช่วงเวลานี้');
        $('#districtList').addClass('d-none');
        $('#districtCount').text('0');
        return;
    }
    const totalPatients = districts.reduce((s, d) => s + (d.patient_count || 0), 0);
    const chwpart = hospitalChwpart || districts[0].chwpart || '';
    const allDistrict = {
        district_key: chwpart + ':all',
        chwpart: chwpart,
        amppart: 'all',
        amp_name: 'ทุกอำเภอ',
        patient_count: totalPatients
    };
    districtsData = [allDistrict].concat(districts);
    $('#districtEmpty').addClass('d-none');
    $('#districtList').removeClass('d-none');
    $('#districtCount').text(rawCount);
    districtsData.forEach((d, i) => {
        const active = selectedDistrict && selectedDistrict.district_key === d.district_key ? ' active' : '';
        const isAll = d.amppart === 'all';
        $list.append(
            '<div class="district-item' + (isAll ? ' district-item-all' : '') + active + '" data-idx="' + i + '">' +
            '<div class="d-flex justify-content-between align-items-center gap-2">' +
            '<div class="fw-bold">' + (isAll ? '<i class="fas fa-layer-group me-1"></i>' : '') + esc(d.amp_name) + '</div>' +
            '<span class="badge bg-secondary">' + (d.patient_count || 0) + '</span>' +
            '</div></div>'
        );
    });
}

function renderPatients(res) {
    const patients = res.patients || [];
    const showAmp = res.district && res.district.amppart === 'all';
    patientPage = res.page || 1;
    patientPages = res.pages || 1;
    $('#patientCount').text(res.total || 0);
    $('#patientPanelTitle').html('<i class="fas fa-users me-1"></i>' + esc((res.district && res.district.amp_name) || 'รายชื่อผู้ป่วย'));
    $('#patientTable .col-amp').toggleClass('d-none', !showAmp);

    const $body = $('#patientBody').empty();
    if (!patients.length) {
        $('#patientEmpty').removeClass('d-none').text(showAmp ? 'ไม่พบผู้ป่วยในช่วงเวลานี้' : 'ไม่พบผู้ป่วยในอำเภอนี้');
        $('#patientWrap').addClass('d-none');
        $('#patientToolbar').addClass('d-none');
        return;
    }
    $('#patientEmpty').addClass('d-none');
    $('#patientWrap').removeClass('d-none');
    $('#patientToolbar').removeClass('d-none');
    updateSortHeaders();
    lastPatientMeta = res;
    patients.forEach(p => {
        let row = '<tr>';
        if (showAmp) row += '<td class="small">' + esc(p.amp_name || '-') + '</td>';
        row +=
            '<td><strong>' + esc(p.hn) + '</strong></td>' +
            '<td>' + esc(p.full_name) + '</td>' +
            '<td>' + esc(p.tmb_name || '-') + '</td>' +
            '<td class="small">' + esc(p.address || '-') + '</td>' +
            '<td class="small">' + esc(p.pttype_name || '-') + '</td>' +
            '<td class="text-center">' + (p.visit_count || 0) + '</td>' +
            '<td>' + esc(p.last_vstdate_display || p.last_vstdate || '-') + '</td>' +
            '<td class="text-center">' + renderMatchPercentCell(p) + '</td>' +
            '<td class="text-nowrap"><a href="#" class="btn btn-sm btn-outline-success btn-refer-patient" data-hn="' + esc(canonicalHn(p.hn || '')) + '" data-vstdate="' + esc(p.last_vstdate || '') + '" title="Refer รายคน">' +
            '<i class="fas fa-share-square"></i></a></td>' +
            '</tr>';
        $body.append(row);
    });
    $('#patientPageInfo').text('หน้า ' + patientPage + ' / ' + patientPages + ' (ทั้งหมด ' + (res.total || 0) + ' ราย)');
    $('#btnPrevPage').prop('disabled', patientPage <= 1);
    $('#btnNextPage').prop('disabled', patientPage >= patientPages);
}

function loadDistricts() {
    const pttypePayload = getPttypeFilterPayload();
    if (Array.isArray(pttypePayload) && !pttypePayload.length) {
        alert('กรุณาเลือกสิทธิการรักษาอย่างน้อย 1 รายการ');
        return;
    }
    const prevKey = selectedDistrict && selectedDistrict.district_key;
    selectedDistrict = null;
    patientPage = 1;
    lastPatientMeta = null;
    resetRecommendations('เลือกอำเภอเพื่อแสดงรายการแนะนำ');
    $('#patientToolbar').addClass('d-none');
    $('#patientEmpty').removeClass('d-none').html('<i class="fas fa-spinner fa-spin"></i> กำลังโหลด...');
    $('#patientWrap').addClass('d-none');
    $('#districtEmpty').removeClass('d-none').html('<i class="fas fa-spinner fa-spin"></i> กำลังโหลดอำเภอ...');
    $('#districtList').addClass('d-none');
    $('#loadNote').toggleClass('d-none', currentPreset === 'today' || currentPreset === 'd3' || currentPreset === 'd7');

    const payload = {
        action: 'districts',
        preset: currentPreset === 'custom' ? 'custom' : currentPreset,
        start_date: $('#startDate').val(),
        end_date: $('#endDate').val(),
        chwpart: hospitalChwpart,
        visit_type: getVisitType(),
        pttype_filter: pttypePayload
    };

    fetch('fetch_drug_refer_district_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(res => {
        if (res.status !== 'success') {
            $('#districtEmpty').removeClass('d-none').html('<span class="text-danger">' + esc(res.message) + '</span>');
            return;
        }
        currentPeriod = res.period;
        updatePeriodSummary(res.period, res.elapsed_sec, res.visit_type_label, (res.pttype_filter && res.pttype_filter.label) || getPttypeFilterLabel());
        renderDistricts(res.districts || []);
        let idx = 0;
        if (prevKey) {
            const found = districtsData.findIndex(d => d.district_key === prevKey);
            if (found >= 0) idx = found;
        }
        if (districtsData.length) selectDistrict(idx);
    })
    .catch(() => {
        $('#districtEmpty').removeClass('d-none').html('<span class="text-danger">โหลดไม่สำเร็จ</span>');
    });
}

function loadPatients(page) {
    if (!selectedDistrict) return;
    patientPage = page || 1;
    $('#patientLoading').removeClass('d-none');
    $('#patientWrap').addClass('d-none');

    fetchPatientsData({ page: patientPage, per_page: 50 })
    .then(res => {
        $('#patientLoading').addClass('d-none');
        if (res.status !== 'success') {
            $('#patientEmpty').removeClass('d-none').text(res.message || 'โหลดไม่สำเร็จ');
            return;
        }
        renderPatients(res);
    })
    .catch(() => {
        $('#patientLoading').addClass('d-none');
        $('#patientEmpty').removeClass('d-none').text('โหลดไม่สำเร็จ');
    });
}

function selectDistrict(idx) {
    selectedDistrict = districtsData[idx] || null;
    $('.district-item').removeClass('active');
    $('.district-item[data-idx="' + idx + '"]').addClass('active');
    if (!selectedDistrict) return;
    loadPatients(1);
    loadRecommendations();
}

$('#visitType').on('change', loadDistricts);

$(document).on('change', '.pttype-chk', updatePttypeCount);
$('#btnPttypeDefault').on('click', function() {
    setPttypeSelection(defaultPttypes);
    loadDistricts();
});
$('#btnPttypeInside').on('click', function() {
    setPttypeSelection(insidePttypes);
    loadDistricts();
});
$('#btnPttypeAll').on('click', function() {
    $('.pttype-chk').prop('checked', true);
    updatePttypeCount();
    loadDistricts();
});
$('#btnPttypeNone').on('click', function() {
    $('.pttype-chk').prop('checked', false);
    updatePttypeCount();
});

$('#periodTabs .nav-link').on('click', function() {
    $('#periodTabs .nav-link').removeClass('active');
    $(this).addClass('active');
    currentPreset = $(this).data('preset');
    applyPresetToInputs(currentPreset);
    loadDistricts();
});

$('#btnLoad').on('click', function() {
    currentPreset = 'custom';
    $('#periodTabs .nav-link').removeClass('active');
    loadDistricts();
});

$('#startDate, #endDate').on('change', function() {
    currentPreset = 'custom';
    $('#periodTabs .nav-link').removeClass('active');
});

$(document).on('click', '.district-item', function() {
    selectDistrict(parseInt($(this).data('idx'), 10));
});

$('#btnPrevPage').on('click', function() {
    if (patientPage > 1) loadPatients(patientPage - 1);
});
$('#btnNextPage').on('click', function() {
    if (patientPage < patientPages) loadPatients(patientPage + 1);
});

$(document).on('click', '#patientTable thead th.sortable', function() {
    const col = $(this).data('sort-col');
    const cur = parsePatientSort();
    if (cur.col === col) {
        patientSort = col + '_' + (cur.dir === 'asc' ? 'desc' : 'asc');
    } else {
        patientSort = col + '_asc';
    }
    updateSortHeaders();
    if (selectedDistrict) loadPatients(1);
});

$(document).on('click', '.btn-refer-patient', function(e) {
    e.preventDefault();
    const hn = $(this).attr('data-hn') || '';
    const vstdate = $(this).attr('data-vstdate') || '';
    if (!hn) return;
    const parts = ['hn=' + encodeURIComponent(canonicalHn(hn))];
    if (vstdate) parts.push('vstdate=' + encodeURIComponent(vstdate));
    parts.push('from=district');
    window.open('drug_refer.php?' + parts.join('&'), '_blank', 'noopener');
});

$(document).on('click', '.match-detail-link', function(e) {
    e.preventDefault();
    openMatchDetail($(this).data('hn'), $(this).data('amp-name') || '');
});

$('#btnPrintMatchDetail').on('click', printMatchDetail);
$('#btnPrintPatients').on('click', function() { runPatientExport('print'); });
$('#btnExportExcel').on('click', function() { runPatientExport('excel'); });

updateSortHeaders();

$('#endDate').val(todayStr());
applyPresetToInputs('today');

fetch('fetch_drug_refer_district_api.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'meta' })
})
.then(r => r.json())
.then(res => {
    if (res.status === 'success') {
        hospitalChwpart = res.hospital_chwpart || '';
        pttypeOptions = res.pttype_options || [];
        defaultPttypes = res.default_pttypes || [];
        insidePttypes = res.inside_pttypes || [];
        renderPttypeFilters();
        setPttypeSelection(defaultPttypes);
        const scope = res.hospital_chw_name
            ? ('<i class="fas fa-hospital me-1"></i>จังหวัด: <strong>' + esc(res.hospital_chw_name) + '</strong>')
            : '<i class="fas fa-globe me-1"></i>ทุกจังหวัด';
        $('#scopeInfo').html(scope + '<br><small class="text-muted">แยกรายชื่อตามอำเภอที่อยู่ผู้ป่วย</small>');
    }
    loadDistricts();
})
.catch(() => loadDistricts());
</script>
</body>
</html>
