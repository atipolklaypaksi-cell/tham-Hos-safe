<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/drug_refer_helper.php';
require_once __DIR__ . '/drug_refer_appointment_helper.php';
include('connect.php');
mysqli_set_charset($con, 'utf8');
$default_appt_date = date('Y-m-d', strtotime('+1 day'));
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Refer ยาแยกตามวันนัด - THAM SAFE</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', Tahoma, sans-serif; background: #f4f7f6; }
        .group-sidebar-card {
            position: sticky;
            top: 12px;
            max-height: calc(100vh - 100px);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .group-list-panel { flex: 1; overflow-y: auto; background: #fafbfc; max-height: calc(100vh - 180px); }
        .group-item { padding: 10px 12px; border-bottom: 1px solid #e9ecef; cursor: pointer; transition: background .15s; }
        .group-item:hover { background: #e8f4fd; }
        .group-item.active { background: #1f4d3b; color: #fff; }
        .group-item.active .text-muted { color: rgba(255,255,255,.85) !important; }
        .group-item-all { background: #f0f7f4; border-bottom: 2px solid #c8e6d4; font-weight: 600; }
        #patientTable { font-size: .88rem; }
        #patientTable thead th { background: #e8f5ef; }
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
            .no-print, .no-print-section, #sidebar, .sidebar-header, .sidebar-user, .developer-credit, .modal, .modal-backdrop { display: none !important; }
            #printArea { display: block !important; }
            #content { margin: 0 !important; width: 100% !important; padding: 0 !important; }
            .wrapper { display: block !important; }
            body { background: #fff; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            @page { size: A4 portrait; margin: 12mm 10mm; }
        }
        .loading-overlay { min-height: 120px; display: flex; align-items: center; justify-content: center; color: #6c757d; }
        #pttypeFilterList .form-check { margin-bottom: .15rem; }
        #pttypeFilterList .form-check-label { font-size: .82rem; }
        .pttype-sss { opacity: .55; }
    </style>
</head>
<body>
<div class="wrapper">
    <?php include('sidebar.php'); ?>
    <div id="content" class="content">
        <div class="container-fluid py-3 no-print-section">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                <h4 class="fw-bold mb-0"><i class="fas fa-calendar-day me-2 text-success"></i>Refer ยาแยกตามวันนัด</h4>
                <div class="d-flex gap-2">
                    <a href="drug_refer_district.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-map-marked-alt me-1"></i>แยกเขต</a>
                    <a href="drug_refer.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-share-square me-1"></i>Refer รายคน</a>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-3">
                <div class="card-body">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label fw-bold small">วันนัด</label>
                            <input type="date" id="apptDate" class="form-control" value="<?php echo htmlspecialchars($default_appt_date, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold small">ประเภท Visit</label>
                            <select id="visitType" class="form-select">
                                <option value="opd" selected>OPD (VN)</option>
                                <option value="ipd">IPD (AN — HME)</option>
                                <option value="all">ทั้งหมด</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="button" class="btn btn-success w-100" id="btnLoad">
                                <i class="fas fa-sync-alt me-1"></i>โหลดรายการ
                            </button>
                        </div>
                        <div class="col-md-5">
                            <div id="summaryInfo" class="small text-muted border rounded p-2 bg-light h-100"></div>
                        </div>
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
                        <div id="pttypeFilterList" class="row g-1 small" style="max-height:160px;overflow-y:auto;"></div>
                    </div>
                    <div class="small text-muted mt-2">
                        <i class="fas fa-info-circle me-1"></i>
                        ดึงรายการยาจาก <strong>VN ล่าสุด</strong> (OPD ทั้ง Visit) หรือ <strong>AN ล่าสุดเฉพาะ HME</strong> (IPD) เทียบบัญชี รพ.ตามอำเภอที่อยู่ผู้ป่วย
                    </div>
                </div>
            </div>

            <div class="row g-3 align-items-start">
                <div class="col-lg-4">
                    <div class="card shadow-sm border-0 group-sidebar-card">
                        <div class="card-header bg-white fw-bold py-2 d-flex justify-content-between align-items-center">
                            <span><i class="fas fa-clinic-medical me-1"></i>คลินิก / ห้องตรวจ</span>
                            <span id="groupCount" class="badge bg-secondary">0</span>
                        </div>
                        <div id="groupEmpty" class="p-3 text-muted small">กดโหลดรายการเพื่อแสดงคลินิกและห้องตรวจ</div>
                        <div id="groupList" class="group-list-panel d-none"></div>
                    </div>
                </div>
                <div class="col-lg-8">
                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-white fw-bold py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <span id="patientPanelTitle"><i class="fas fa-users me-1"></i>รายชื่อผู้ป่วยนัด</span>
                            <span id="patientCount" class="badge bg-primary">0</span>
                        </div>
                        <div class="card-body p-0">
                            <div id="patientEmpty" class="p-3 text-muted small">เลือกคลินิก/ห้องตรวจจากรายการด้านซ้าย</div>
                            <div id="patientLoading" class="loading-overlay d-none"><i class="fas fa-spinner fa-spin me-2"></i>กำลังโหลด...</div>
                            <div id="patientWrap" class="d-none">
                                <div class="table-responsive">
                                    <table id="patientTable" class="table table-hover table-sm mb-0">
                                        <thead>
                                            <tr>
                                                <th>เวลานัด</th>
                                                <th>HN</th>
                                                <th>ชื่อผู้ป่วย</th>
                                                <th>ตำบล</th>
                                                <th>อำเภอ</th>
                                                <th>สิทธิการรักษา</th>
                                                <th>Visit อ้างอิง</th>
                                                <th class="text-center">ยา</th>
                                                <th class="text-center">เทียบบัญชี</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody id="patientBody"></tbody>
                                    </table>
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

<script src="js/jquery-3.7.0.min.js"></script>
<script src="js/bootstrap.bundle.min.js"></script>
<script src="js/drug_refer_match_print.js"></script>
<script>
let groupsData = [];
let selectedGroup = null;
let pttypeOptions = [];
let defaultPttypes = [];
let insidePttypes = [];
let lastMatchDetailData = null;

function esc(s) { return $('<div>').text(s ?? '').html(); }

function renderDrugNameHtml(r) {
    const primary = r.display_primary || r.tmtdrug || r.drug_name_tmt || r.drug_name || '';
    const secondary = r.display_secondary || '';
    let html = '<strong>' + esc(primary) + '</strong>';
    if (secondary) html += '<br><small class="text-muted">' + esc(secondary) + '</small>';
    return html;
}

function renderMatchedNameHtml(match) {
    if (!match || typeof match !== 'object') return '-';
    const primary = match.matched_display_primary || match.matched_display || match.matched_tmtdrug || '-';
    const secondary = match.matched_display_secondary || '';
    let html = esc(primary);
    if (secondary) html += '<br><small class="text-muted">' + esc(secondary) + '</small>';
    return html;
}

function renderSuggestLabel(s) {
    const primary = s.display_primary || s.display_name || s.tmtdrug || '';
    const secondary = s.display_secondary || '';
    let label = primary;
    if (secondary && secondary !== primary) label += ' — ' + secondary;
    return label;
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

function canonicalHn(hn) {
    hn = String(hn || '').trim();
    if (!hn || !/^\d+$/.test(hn)) return hn;
    const stripped = hn.replace(/^0+/, '') || '0';
    return stripped.padStart(9, '0');
}

function buildReferUrl(p) {
    const parts = ['hn=' + encodeURIComponent(canonicalHn(p.hn || ''))];
    if (p.ref_vstdate) parts.push('vstdate=' + encodeURIComponent(p.ref_vstdate));
    parts.push('from=appointment');
    return 'drug_refer.php?' + parts.join('&');
}

function getVisitType() {
    return $('#visitType').val() || 'opd';
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

function getPttypeFilterLabel() {
    const payload = getPttypeFilterPayload();
    if (payload === 'all') return 'สิทธิทั้งหมด';
    if (!payload.length) return 'ยังไม่ได้เลือกสิทธิ';
    if (matchesPttypePreset(payload, defaultPttypes)) return 'นอกเขต (ยกเว้นประกันสังคม)';
    if (matchesPttypePreset(payload, insidePttypes)) return 'ในเขต (ยกเว้นประกันสังคม)';
    return 'สิทธิที่เลือก ' + payload.length + ' รายการ';
}

function updatePttypeCount() {
    const payload = getPttypeFilterPayload();
    const n = payload === 'all' ? pttypeOptions.length : (Array.isArray(payload) ? payload.length : 0);
    $('#pttypeFilterCount').text(n);
}

function setPttypeSelection(codes) {
    $('.pttype-chk').each(function() {
        $(this).prop('checked', codes.indexOf($(this).val()) >= 0);
    });
    updatePttypeCount();
}

function renderPttypeFilters() {
    const $list = $('#pttypeFilterList').empty();
    pttypeOptions.forEach(opt => {
        const cls = opt.is_social_security ? ' pttype-sss' : '';
        $list.append(
            '<div class="col-md-4 col-lg-3">' +
            '<div class="form-check' + cls + '">' +
            '<input class="form-check-input pttype-chk" type="checkbox" id="ptt_' + esc(opt.pttype) + '" value="' + esc(opt.pttype) + '">' +
            '<label class="form-check-label" for="ptt_' + esc(opt.pttype) + '">' + esc(opt.name) + '</label>' +
            '</div></div>'
        );
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

function renderMatchCell(p) {
    if (p.match_note) {
        return '<span class="small text-muted" title="' + esc(p.match_note) + '">-</span>';
    }
    if (!p.match_total) {
        return '<span class="small text-muted">-</span>';
    }
    const pct = p.match_percent || 0;
    const title = (p.match_hospital || '') + ' — ' + (p.match_matched || 0) + '/' + (p.match_total || 0) + ' รายการ (ยอมรับชื่อคล้าย ≥90%)';
    return '<a href="#" class="match-pct-link ' + matchPercentClass(p) + ' match-detail-link"' +
        ' data-hn="' + esc(p.hn) + '"' +
        ' data-amp-name="' + esc(p.amp_name || '') + '"' +
        ' data-ref-vn="' + esc(p.ref_vn || '') + '"' +
        ' data-ref-an="' + esc(p.ref_an || '') + '"' +
        ' data-ref-visit-type="' + esc(p.ref_visit_type || 'opd') + '"' +
        ' title="' + esc(title) + '">' + pct + '%</a>' +
        '<div class="small text-muted">' + (p.match_matched || 0) + '/' + (p.match_total || 0) + '</div>';
}

function renderMatchDetailSummaryHtml(res) {
    const s = res.summary || {};
    const p = res.patient || {};
    const ref = res.ref_visit || {};
    let summary = '<strong>' + esc(p.full_name || '') + '</strong> | HN: ' + esc(p.hn || '');
    if (p.address) summary += '<br><i class="fas fa-map-marker-alt me-1"></i>' + esc(p.address);
    if (p.amp_name) summary += ' | อำเภอ: ' + esc(p.amp_name);
    summary += '<br><i class="fas fa-hospital me-1"></i>เทียบกับ <strong>' + esc(res.hospital || '') + '</strong>';
    if (res.appt_date_label) {
        summary += ' | วันนัด: <strong>' + esc(res.appt_date_label) + '</strong>';
    }
    if (ref.visit_label) {
        summary += '<br><i class="fas fa-notes-medical me-1"></i>Visit อ้างอิง: <strong>' + esc(ref.visit_label) + '</strong>';
        if (ref.filter_note) summary += ' (' + esc(ref.filter_note) + ')';
    }
    summary += '<br>รายการยา <strong>' + (s.total || 0) + '</strong> | ';
    summary += '<span class="text-success">ตรง ' + (s.exact || 0) + '</span> | ';
    summary += '<span class="text-warning">ใกล้เคียง ≥90% ' + (s.similar || 0) + '</span> | ';
    summary += '<span class="text-danger">ไม่พบ ' + (s.missing || 0) + '</span> | ';
    summary += '<strong class="' + matchPercentClass({ match_percent: s.match_percent, match_total: s.total }) + '">' + (s.match_percent || 0) + '%</strong>';
    return summary;
}

function renderMatchDetailRowsHtml(rows) {
    let body = '';
    (rows || []).forEach(r => {
        const match = r.match || {};
        const score = match.status === 'exact' ? '100' : (match.score || 0);
        let suggest = '';
        const suggestions = match.suggestions || [];
        if (match.status !== 'exact' && suggestions.length) {
            suggest = '<div class="small text-muted mt-1">แนะนำ: ' + suggestions.slice(0, 2).map(x => esc(renderSuggestLabel(x)) + ' (' + x.score + '%)').join(', ') + '</div>';
        }
        body += '<tr>' +
            '<td>' + renderDrugNameHtml(r) + (r.method ? '<br><small class="text-muted">' + esc(r.method) + '</small>' : '') + '</td>' +
            '<td class="text-center">' + renderMatchStatusCell(match) + '</td>' +
            '<td>' + renderMatchedNameHtml(match) + suggest + '</td>' +
            '<td class="text-center">' + (match.status === 'none' ? '-' : esc(String(score))) + '</td>' +
            '</tr>';
    });
    return body;
}

function buildMatchDetailPrintHtml(res) {
    return DrugReferMatchPrint.buildMatchDetailPrintHtml(res, {
        footerLabel: 'THAM Hos-Safe — Refer ยาแยกตามวันนัด'
    });
}

function openMatchDetail(hn, ampName, refVn, refAn, refVisitType) {
    if (!hn) return;
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('matchDetailModal'));
    $('#matchDetailTitle').text('รายละเอียดการเทียบบัญชียา — HN ' + hn);
    $('#matchDetailSummary').empty();
    $('#matchDetailError').addClass('d-none');
    $('#matchDetailTable').addClass('d-none');
    $('#matchDetailLoading').removeClass('d-none');
    $('#matchDetailBody').empty();
    lastMatchDetailData = null;
    modal.show();

    fetch('fetch_drug_refer_appointment_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'match_detail',
            hn: hn,
            amp_name: ampName,
            vn: refVn,
            an: refAn,
            ref_visit_type: refVisitType || getVisitType(),
            appt_date: $('#apptDate').val(),
            visit_type: getVisitType(),
            pttype_filter: getPttypeFilterPayload()
        })
    })
    .then(r => r.json())
    .then(res => {
        $('#matchDetailLoading').addClass('d-none');
        if (res.status !== 'success') {
            $('#matchDetailError').removeClass('d-none').text(res.message || 'โหลดไม่สำเร็จ');
            return;
        }
        lastMatchDetailData = res;
        $('#matchDetailSummary').html(renderMatchDetailSummaryHtml(res));
        $('#matchDetailBody').html(renderMatchDetailRowsHtml(res.rows || []));
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
    $('#printArea').html(buildMatchDetailPrintHtml(lastMatchDetailData));
    setTimeout(function() { window.print(); }, 150);
}

function renderRefVisit(p) {
    if (p.ref_visit_type === 'ipd' && p.ref_an) {
        return 'AN: ' + esc(p.ref_an) + '<br><small class="text-muted">' + esc(p.ref_vstdate_display || p.ref_vstdate || '') + ' (HME)</small>';
    }
    if (p.ref_vn) {
        return 'VN: ' + esc(p.ref_vn) + '<br><small class="text-muted">' + esc(p.ref_vstdate_display || p.ref_vstdate || '') + '</small>';
    }
    return '<span class="text-muted">-</span>';
}

function updateSummary(res) {
    const label = res.appt_date_label || res.appt_date || '';
    const visit = res.visit_type_label || '';
    const pttype = (res.pttype_filter && res.pttype_filter.label) || getPttypeFilterLabel();
    let html = '<i class="fas fa-calendar-day me-1"></i>วันนัด: <strong>' + esc(label) + '</strong>';
    html += '<br><i class="fas fa-clinic-medical me-1"></i>ประเภท: ' + esc(visit);
    html += '<br><i class="fas fa-id-card me-1"></i>สิทธิ: ' + esc(pttype);
    if (res.total_groups !== undefined) {
        html += '<br><i class="fas fa-users me-1"></i>ผู้ป่วยนัดรวม ' + (res.total_patients || 0) + ' ราย / ' + (res.total_groups || 0) + ' กลุ่ม';
    }
    if (res.elapsed_sec !== undefined) {
        html += '<br><small class="text-muted">โหลด ' + res.elapsed_sec + ' วินาที</small>';
    }
    $('#summaryInfo').html(html);
}

function renderGroups(groups) {
    const $list = $('#groupList').empty();
    if (!groups.length) {
        groupsData = [];
        $('#groupEmpty').removeClass('d-none').text('ไม่พบผู้ป่วยนัดในวันนี้');
        $('#groupList').addClass('d-none');
        $('#groupCount').text('0');
        return;
    }
    const totalPatients = groups.reduce((s, g) => s + (g.patient_count || 0), 0);
    const allGroup = {
        group_key: ':',
        clinic_code: '',
        room_code: '',
        clinic_name: 'ทุกคลินิก/ห้อง',
        room_name: '',
        patient_count: totalPatients
    };
    groupsData = [allGroup].concat(groups);
    $('#groupEmpty').addClass('d-none');
    $('#groupList').removeClass('d-none');
    $('#groupCount').text(groups.length);
    groupsData.forEach((g, i) => {
        const active = selectedGroup && selectedGroup.group_key === g.group_key ? ' active' : '';
        const isAll = g.group_key === ':';
        const title = isAll ? g.clinic_name : (g.clinic_name + ' — ' + g.room_name);
        $list.append(
            '<div class="group-item' + (isAll ? ' group-item-all' : '') + active + '" data-idx="' + i + '">' +
            '<div class="d-flex justify-content-between align-items-start gap-2">' +
            '<div><div class="fw-bold">' + (isAll ? '<i class="fas fa-layer-group me-1"></i>' : '') + esc(title) + '</div>' +
            (!isAll && g.clinic_code ? '<div class="small text-muted">รหัส: ' + esc(g.clinic_code) + (g.room_code ? ' / ห้อง ' + esc(g.room_code) : '') + '</div>' : '') +
            '</div><span class="badge bg-secondary">' + (g.patient_count || 0) + '</span></div></div>'
        );
    });
}

function renderPatients(res) {
    const patients = res.patients || [];
    const g = selectedGroup || {};
    const title = g.group_key === ':'
        ? 'ทุกคลินิก/ห้อง'
        : ((g.clinic_name || '') + ' — ' + (g.room_name || ''));
    $('#patientPanelTitle').html('<i class="fas fa-users me-1"></i>' + esc(title));
    $('#patientCount').text(res.total || 0);
    const $body = $('#patientBody').empty();
    if (!patients.length) {
        $('#patientEmpty').removeClass('d-none').text('ไม่พบผู้ป่วยในกลุ่มนี้');
        $('#patientWrap').addClass('d-none');
        return;
    }
    $('#patientEmpty').addClass('d-none');
    $('#patientWrap').removeClass('d-none');
    patients.forEach(p => {
        $body.append(
            '<tr>' +
            '<td>' + esc(p.nexttime_display || p.nexttime || '-') + '</td>' +
            '<td><strong>' + esc(p.hn) + '</strong></td>' +
            '<td>' + esc(p.full_name) + '</td>' +
            '<td class="small">' + esc(p.tmb_name || '-') + '</td>' +
            '<td class="small">' + esc(p.amp_name || '-') + '</td>' +
            '<td class="small">' + esc(p.pttype_name || '-') + '</td>' +
            '<td class="small">' + renderRefVisit(p) + '</td>' +
            '<td class="text-center">' + (p.drug_count || 0) + '</td>' +
            '<td class="text-center">' + renderMatchCell(p) + '</td>' +
            '<td class="text-nowrap"><a href="#" class="btn btn-sm btn-outline-success btn-refer-patient" data-hn="' + esc(canonicalHn(p.hn || '')) + '" data-vstdate="' + esc(p.ref_vstdate || '') + '" title="Refer รายคน"><i class="fas fa-share-square"></i></a></td>' +
            '</tr>'
        );
    });
}

function loadGroups() {
    const pttypePayload = getPttypeFilterPayload();
    if (Array.isArray(pttypePayload) && !pttypePayload.length) {
        alert('กรุณาเลือกสิทธิการรักษาอย่างน้อย 1 รายการ');
        return;
    }
    const prevKey = selectedGroup && selectedGroup.group_key;
    selectedGroup = null;
    $('#patientEmpty').removeClass('d-none').html('<i class="fas fa-spinner fa-spin"></i> กำลังโหลด...');
    $('#patientWrap').addClass('d-none');
    $('#groupEmpty').removeClass('d-none').html('<i class="fas fa-spinner fa-spin"></i> กำลังโหลด...');
    $('#groupList').addClass('d-none');

    fetch('fetch_drug_refer_appointment_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'groups',
            appt_date: $('#apptDate').val(),
            visit_type: getVisitType(),
            pttype_filter: pttypePayload
        })
    })
    .then(r => r.json())
    .then(res => {
        if (res.status !== 'success') {
            $('#groupEmpty').removeClass('d-none').html('<span class="text-danger">' + esc(res.message) + '</span>');
            return;
        }
        updateSummary(res);
        renderGroups(res.groups || []);
        let idx = 0;
        if (prevKey) {
            const found = groupsData.findIndex(g => g.group_key === prevKey);
            if (found >= 0) idx = found;
        }
        if (groupsData.length) selectGroup(idx);
    })
    .catch(() => {
        $('#groupEmpty').removeClass('d-none').html('<span class="text-danger">โหลดไม่สำเร็จ</span>');
    });
}

function loadPatients() {
    if (!selectedGroup) return;
    $('#patientLoading').removeClass('d-none');
    $('#patientWrap').addClass('d-none');
    fetch('fetch_drug_refer_appointment_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'patients',
            appt_date: $('#apptDate').val(),
            visit_type: getVisitType(),
            pttype_filter: getPttypeFilterPayload(),
            group_key: selectedGroup.group_key,
            clinic_code: selectedGroup.clinic_code,
            room_code: selectedGroup.room_code
        })
    })
    .then(r => r.json())
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

function selectGroup(idx) {
    selectedGroup = groupsData[idx] || null;
    $('.group-item').removeClass('active');
    $('.group-item[data-idx="' + idx + '"]').addClass('active');
    if (!selectedGroup) return;
    loadPatients();
}

$('#btnLoad').on('click', loadGroups);
$('#visitType').on('change', loadGroups);
$('#apptDate').on('change', loadGroups);
$(document).on('change', '.pttype-chk', updatePttypeCount);
$('#btnPttypeDefault').on('click', function() { setPttypeSelection(defaultPttypes); loadGroups(); });
$('#btnPttypeInside').on('click', function() { setPttypeSelection(insidePttypes); loadGroups(); });
$('#btnPttypeAll').on('click', function() { $('.pttype-chk').prop('checked', true); updatePttypeCount(); loadGroups(); });
$('#btnPttypeNone').on('click', function() { $('.pttype-chk').prop('checked', false); updatePttypeCount(); });

$(document).on('click', '.group-item', function() {
    selectGroup(parseInt($(this).data('idx'), 10));
});

$(document).on('click', '.btn-refer-patient', function(e) {
    e.preventDefault();
    const hn = $(this).attr('data-hn') || '';
    const vstdate = $(this).attr('data-vstdate') || '';
    if (!hn) return;
    const parts = ['hn=' + encodeURIComponent(hn)];
    if (vstdate) parts.push('vstdate=' + encodeURIComponent(vstdate));
    parts.push('from=appointment');
    window.open('drug_refer.php?' + parts.join('&'), '_blank', 'noopener');
});

$(document).on('click', '.match-detail-link', function(e) {
    e.preventDefault();
    openMatchDetail(
        $(this).attr('data-hn') || '',
        $(this).attr('data-amp-name') || '',
        $(this).attr('data-ref-vn') || '',
        $(this).attr('data-ref-an') || '',
        $(this).attr('data-ref-visit-type') || 'opd'
    );
});

$('#btnPrintMatchDetail').on('click', printMatchDetail);

fetch('fetch_drug_refer_appointment_api.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'meta' })
})
.then(r => r.json())
.then(res => {
    if (res.status === 'success') {
        pttypeOptions = res.pttype_options || [];
        defaultPttypes = res.default_pttypes || [];
        insidePttypes = res.inside_pttypes || [];
        renderPttypeFilters();
        setPttypeSelection(defaultPttypes);
        if (res.default_appt_date) $('#apptDate').val(res.default_appt_date);
        updateSummary(res);
    }
    loadGroups();
})
.catch(() => loadGroups());
</script>
</body>
</html>
