<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/network_drug_helper.php';
require_once __DIR__ . '/drug_refer_helper.php';
include('connect.php');
mysqli_set_charset($con, 'utf8');
nd_ensure_tables($con);
$hospital_groups = ndr_list_hospitals_grouped($con);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Refer ยาผู้ป่วย - THAM SAFE</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', Tahoma, sans-serif; background: #f4f7f6; }
        .visit-list-panel { max-height: 420px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 8px; background: #fafbfc; }
        .visit-item { padding: 10px 12px; border-bottom: 1px solid #e9ecef; cursor: pointer; transition: background .15s; }
        .visit-item:hover { background: #e8f4fd; }
        .visit-item.active { background: #1f4d3b; color: #fff; }
        .visit-item.active .text-muted { color: rgba(255,255,255,.85) !important; }
        .hos-pick { font-size: .85rem; }
        .hos-pick .form-check { margin: 0; padding: .35rem .6rem; background: #fff; border: 1px solid #d8e8df; border-radius: 8px; }
        #patientInfo { line-height: 1.5; }
        #patientInfo .patient-detail { margin-top: 4px; }
        #referTable { font-size: .85rem; }
        #referTable thead th { background: #1f4d3b; color: #fff; text-align: center; vertical-align: middle; }
        #referTable thead th.col-drug { text-align: left; min-width: 240px; }
        #referTable td.hos-cell { text-align: center; width: 72px; }
        .nd-check {
            display: inline-flex; align-items: center; justify-content: center;
            width: 22px; height: 22px; border-radius: 4px;
            background: #22c55e; color: #fff; font-size: .72rem;
        }
        .nd-similar {
            display: inline-flex; align-items: center; justify-content: center;
            width: 22px; height: 22px; border-radius: 4px;
            background: #f59e0b; color: #fff; font-size: .72rem;
        }
        .nd-cross {
            display: inline-flex; align-items: center; justify-content: center;
            width: 22px; height: 22px; border-radius: 4px;
            background: #ef4444; color: #fff; font-size: .72rem;
        }
        .nd-suggest-box {
            font-size: .75rem;
            color: #92400e;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 6px;
            padding: .35rem .5rem;
            margin-top: .35rem;
        }
        #printArea { display: none; }
        @media print {
            .no-print, .no-print-section, #sidebar, .sidebar-header, .sidebar-user, .developer-credit { display: none !important; }
            #printArea { display: block !important; }
            #content { margin: 0 !important; width: 100% !important; }
            body { background: #fff; }
        }
    </style>
</head>
<body>
<div class="wrapper">
    <?php include('sidebar.php'); ?>
    <div id="content" class="content">
        <div class="container-fluid py-3 no-print-section">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                <h4 class="fw-bold mb-0"><i class="fas fa-share-square me-2 text-success"></i>Refer ยาผู้ป่วย</h4>
                <a href="network_drug_list.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-table me-1"></i>บัญชียาเครือข่าย</a>
            </div>

            <div class="card shadow-sm border-0 mb-3">
                <div class="card-body">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-5">
                            <label class="form-label fw-bold small">HN ผู้ป่วย</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-id-card"></i></span>
                                <input type="text" id="hnInput" class="form-control" placeholder="กรอก HN" value="<?php echo htmlspecialchars(ndr_canonical_hn($_GET['hn'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                <button class="btn btn-success" id="btnSearch"><i class="fas fa-search me-1"></i>ค้นหา</button>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold small">ช่วง Visit</label>
                            <select id="monthsSelect" class="form-select">
                                <option value="12">12 เดือน</option>
                                <option value="24">24 เดือน</option>
                                <option value="36" selected>36 เดือน</option>
                                <option value="60">5 ปี</option>
                                <option value="0">ทั้งหมด</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <div id="patientInfo" class="small text-muted pt-2"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-lg-5">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-header bg-white fw-bold py-2"><i class="fas fa-calendar-check me-1"></i>เลือก Visit</div>
                        <div class="card-body p-0">
                            <div id="visitEmpty" class="p-3 text-muted small">ค้นหา HN เพื่อแสดงรายการ Visit</div>
                            <div id="visitList" class="visit-list-panel d-none"></div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-7">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-header bg-white fw-bold py-2"><i class="fas fa-hospital me-1"></i>เลือก รพ. ปลายทาง Refer</div>
                        <div class="card-body">
                            <div id="selectedVisitInfo" class="alert alert-light border small mb-3">ยังไม่ได้เลือก Visit</div>
                            <?php if (empty($hospital_groups)): ?>
                            <div class="alert alert-warning mb-0">
                                ยังไม่มีข้อมูลบัญชียาเครือข่าย — <a href="network_drug_upload.php">อัปโหลดไฟล์</a>
                            </div>
                            <?php else: ?>
                            <div class="d-flex flex-wrap gap-2 mb-2">
                                <button type="button" class="btn btn-link btn-sm p-0" id="btnHosAll">เลือกทั้งหมด</button>
                                <span class="text-muted">|</span>
                                <button type="button" class="btn btn-link btn-sm p-0" id="btnHosNone">ยกเลิกทั้งหมด</button>
                            </div>
                            <div id="hosPickList" class="d-flex flex-wrap gap-2 hos-pick mb-3">
                                <?php foreach ($hospital_groups as $idx => $hg): ?>
                                <div class="form-check" title="<?php echo htmlspecialchars(implode(', ', $hg['aliases'] ?? []), ENT_QUOTES, 'UTF-8'); ?>">
                                    <input class="form-check-input hos-pick-chk" type="checkbox" id="hos<?php echo $idx; ?>" value="<?php echo htmlspecialchars($hg['display_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <label class="form-check-label" for="hos<?php echo $idx; ?>">
                                        <?php echo htmlspecialchars($hg['display_name'], ENT_QUOTES, 'UTF-8'); ?>
                                        <small class="text-muted">(<?php echo (int)($hg['drug_count'] ?? 0); ?>)</small>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-primary fw-bold" id="btnCompare" disabled>
                                <i class="fas fa-balance-scale me-1"></i>เทียบบัญชียาเครือข่าย
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div id="resultCard" class="card shadow-sm border-0 d-none">
                <div class="card-header text-white d-flex justify-content-between align-items-center py-2 no-print" style="background:#1f4d3b;">
                    <span class="fw-bold"><i class="fas fa-table me-1"></i>ผลการเทียบบัญชียา</span>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-light btn-sm" id="btnPrint"><i class="fas fa-print me-1"></i>พิมพ์</button>
                        <a href="drug_refer_print.php" target="_blank" class="btn btn-outline-light btn-sm" id="btnPrintPage"><i class="fas fa-external-link-alt me-1"></i>หน้าพิมพ์</a>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div id="resultSummary" class="p-3 border-bottom small bg-light"></div>
                    <div class="table-responsive">
                        <table id="referTable" class="table table-bordered table-sm mb-0">
                            <thead><tr id="referHead"><th class="col-drug">tmtdrug</th></tr></thead>
                            <tbody id="referBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div id="printArea" class="p-4"></div>
    </div>
</div>

<script src="js/jquery-3.7.0.min.js"></script>
<script src="js/bootstrap.bundle.min.js"></script>
<script src="js/drug_refer_match_print.js"></script>
<script>
let patientData = null;
let visitsData = [];
let selectedVisit = null;
let lastCompareData = null;
const urlParams = new URLSearchParams(window.location.search);
const preferVstdate = (urlParams.get('vstdate') || '').trim();

function esc(s) { return $('<div>').text(s ?? '').html(); }

function canonicalHn(hn) {
    hn = String(hn || '').trim();
    if (!hn || !/^\d+$/.test(hn)) return hn;
    const stripped = hn.replace(/^0+/, '') || '0';
    return stripped.padStart(9, '0');
}

function visitHasReferableDrugs(v) {
    if (!v) return false;
    if (v.visit_type === 'ipd') return (v.hme_count || 0) > 0;
    return (v.drug_count || 0) > 0;
}

function pickDefaultVisitIndex(visits, vstdate) {
    if (!visits || !visits.length) return -1;
    let candidates = visits;
    if (vstdate) {
        const sameDay = visits.filter(v => (v.vstdate || '') === vstdate);
        if (sameDay.length) candidates = sameDay;
    }
    for (let i = 0; i < visits.length; i++) {
        if (!candidates.includes(visits[i])) continue;
        if (visitHasReferableDrugs(visits[i])) return i;
    }
    return 0;
}

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

function renderPatientInfo(patient, visitRights) {
    if (!patient) return '';
    let html = '<strong>' + esc(patient.full_name) + '</strong> | HN: ' + esc(patient.hn);
    if (visitsData.length) html += ' | Visit: ' + visitsData.length;
    const rights = (visitRights && visitRights.pttype_name) || patient.pttype_name || '';
    if (patient.address) {
        html += '<div class="patient-detail"><i class="fas fa-map-marker-alt me-1 text-muted"></i><span class="text-muted">ที่อยู่:</span> ' + esc(patient.address) + '</div>';
    }
    if (patient.amp_name) {
        html += '<div class="patient-detail"><i class="fas fa-map me-1 text-muted"></i><span class="text-muted">อำเภอ:</span> ' + esc(patient.amp_name) + '</div>';
    }
    if (rights) {
        html += '<div class="patient-detail"><i class="fas fa-id-card me-1 text-muted"></i><span class="text-muted">สิทธิการรักษา:</span> ' + esc(rights) + '</div>';
    }
    return html;
}

function getSelectedHospitals() {
    const list = [];
    $('.hos-pick-chk:checked').each(function() { list.push($(this).val()); });
    return list;
}

function updateCompareBtn() {
    $('#btnCompare').prop('disabled', !selectedVisit || getSelectedHospitals().length === 0);
}

function selectDefaultHospital(hospitalName) {
    $('.hos-pick-chk').prop('checked', false);
    if (!hospitalName) {
        updateCompareBtn();
        return false;
    }
    let matched = false;
    $('.hos-pick-chk').each(function() {
        if ($(this).val() === hospitalName) {
            $(this).prop('checked', true);
            matched = true;
            return false;
        }
    });
    updateCompareBtn();
    return matched;
}

function applySearchDefaults(res) {
    if (visitsData.length) {
        const idx = pickDefaultVisitIndex(visitsData, preferVstdate);
        selectVisit(idx >= 0 ? idx : 0);
    } else {
        selectedVisit = null;
        $('#selectedVisitInfo').text('ไม่พบ Visit ในช่วงเวลาที่เลือก');
        updateCompareBtn();
    }
    if (patientData && patientData.hn) {
        $('#hnInput').val(canonicalHn(patientData.hn));
    }
    const hospital = res.suggested_hospital || '';
    const ampName = res.amp_name || (res.patient && res.patient.amp_name) || '';
    const matched = selectDefaultHospital(hospital);
    if (selectedVisit && hospital) {
        let note = '<br><i class="fas fa-hospital me-1"></i>รพ.ปลายทาง: <strong>' + esc(hospital) + '</strong>';
        if (ampName) note += ' <span class="text-muted">(อำเภอ' + esc(ampName) + ')</span>';
        if (!matched) note += ' <span class="text-warning">— ไม่พบในรายการ checkbox</span>';
        $('#selectedVisitInfo').append(note);
    }
    maybeAutoCompare();
}

function maybeAutoCompare() {
    if (!selectedVisit || !visitHasReferableDrugs(selectedVisit)) return;
    if (!getSelectedHospitals().length) return;
    runCompare();
}

function runCompare() {
    if (!selectedVisit) return;
    const hospitals = getSelectedHospitals();
    if (!hospitals.length) return;
    const $btn = $('#btnCompare').prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i>กำลังเทียบ...');
    fetch('fetch_drug_refer_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'compare',
            hn: $('#hnInput').val().trim(),
            vn: selectedVisit.vn || '',
            an: selectedVisit.an || '',
            visit_type: selectedVisit.visit_type || '',
            hospitals: hospitals
        })
    })
    .then(r => r.json())
    .then(res => {
        $btn.prop('disabled', false).html('<i class="fas fa-balance-scale me-1"></i>เทียบบัญชียาเครือข่าย');
        updateCompareBtn();
        if (res.status !== 'success') {
            alert(res.message || 'เทียบไม่สำเร็จ');
            return;
        }
        renderCompare(res);
    })
    .catch(() => {
        $btn.prop('disabled', false).html('<i class="fas fa-balance-scale me-1"></i>เทียบบัญชียาเครือข่าย');
        updateCompareBtn();
        alert('เทียบไม่สำเร็จ');
    });
}

function renderVisits(visits) {
    const $list = $('#visitList').empty();
    if (!visits.length) {
        $('#visitEmpty').removeClass('d-none').text('ไม่พบ Visit ในช่วงเวลาที่เลือก');
        $('#visitList').addClass('d-none');
        return;
    }
    $('#visitEmpty').addClass('d-none');
    $('#visitList').removeClass('d-none');
    visits.forEach((v, i) => {
        const isIpd = v.visit_type === 'ipd';
        const badge = isIpd
            ? '<span class="badge bg-warning text-dark">IPD</span>'
            : '<span class="badge bg-primary">OPD</span>';
        const idLabel = isIpd ? ('AN: ' + esc(v.an)) : ('VN: ' + esc(v.vn));
        const meta = isIpd
            ? ('HME: ' + (v.hme_count || 0) + ' | ยาทั้งหมด: ' + (v.drug_count || 0) + (v.ward_name ? ' | ' + esc(v.ward_name) : ''))
            : ('ยา: ' + (v.drug_count || 0));
        const rights = v.pttype_name ? ('<div class="small text-muted">สิทธิ: ' + esc(v.pttype_name) + '</div>') : '';
        $list.append(
            '<div class="visit-item" data-idx="' + i + '">' +
            '<div class="d-flex justify-content-between align-items-start gap-2">' +
            '<div><div class="fw-bold">' + esc(v.vstdate_display || v.vstdate) + ' ' + badge + '</div>' +
            '<div class="small text-muted">' + idLabel + '</div>' +
            '<div class="small text-muted">' + meta + '</div>' + rights + '</div></div></div>'
        );
    });
}

function selectVisit(idx) {
    selectedVisit = visitsData[idx] || null;
    $('.visit-item').removeClass('active');
    $('.visit-item[data-idx="' + idx + '"]').addClass('active');
    if (!selectedVisit) {
        $('#selectedVisitInfo').text('ยังไม่ได้เลือก Visit');
        updateCompareBtn();
        return;
    }
    const isIpd = selectedVisit.visit_type === 'ipd';
    const note = isIpd
        ? '<br><span class="text-success"><i class="fas fa-home me-1"></i>IPD จะดึงเฉพาะรายการประเภท <strong>HME</strong> (ยากลับบ้าน)</span>'
        : '<br><span class="text-info"><i class="fas fa-clinic-medical me-1"></i>OPD จะดึงรายการยาทั้งหมดใน Visit</span>';
    const noReferDrugs = !visitHasReferableDrugs(selectedVisit);
    const warnLine = noReferDrugs
        ? '<br><span class="text-warning"><i class="fas fa-exclamation-triangle me-1"></i>Visit นี้ไม่มียาที่นำมา Refer ได้' +
          (isIpd ? ' (ไม่มียา HME)' : '') + ' — ลองเลือก Visit อื่น</span>'
        : '';
    const rightsLine = selectedVisit.pttype_name
        ? ('<br><i class="fas fa-id-card me-1"></i>สิทธิการรักษา: <strong>' + esc(selectedVisit.pttype_name) + '</strong>')
        : '';
    $('#selectedVisitInfo').html(
        '<strong>' + esc(selectedVisit.vstdate_display || selectedVisit.vstdate) + '</strong> | ' +
        (isIpd ? ('AN: ' + esc(selectedVisit.an)) : ('VN: ' + esc(selectedVisit.vn))) + rightsLine + note + warnLine
    );
    $('#patientInfo').html(renderPatientInfo(patientData, selectedVisit));
    updateCompareBtn();
}

function renderCell(match) {
    if (!match || typeof match !== 'object') {
        return '<span class="nd-cross"><i class="fas fa-times"></i></span>';
    }
    const st = match.status || 'none';
    if (st === 'exact') {
        const tip = match.matched_display || match.matched_tmtdrug || '';
        return '<span class="nd-check"' + (tip ? ' title="' + esc(tip) + '"' : '') + '><i class="fas fa-check"></i></span>';
    }
    if (st === 'similar') {
        const tip = 'ใกล้เคียง: ' + (match.matched_display || match.matched_tmtdrug || '') + ' (' + (match.score || 0) + '%)';
        return '<span class="nd-similar" title="' + esc(tip) + '"><i class="fas fa-lightbulb"></i></span>' +
            '<div class="small text-warning mt-1" style="font-size:.68rem;line-height:1.2;">' + renderMatchedNameHtml(match) + '</div>';
    }
    return '<span class="nd-cross"><i class="fas fa-times"></i></span>';
}

function renderSuggestBox(r, hospitals) {
    let html = '';
    hospitals.forEach(h => {
        const match = r.hospitals && r.hospitals[h];
        if (match && match.status === 'exact') return;
        const list = (r.suggestions && r.suggestions[h]) || (match && match.suggestions) || [];
        if (!list.length) return;
        html += '<div class="nd-suggest-box"><strong>' + esc(h) + ' แนะนำ:</strong><ul class="mb-0 ps-3">';
        list.forEach(s => {
            html += '<li>' + esc(renderSuggestLabel(s)) + ' <span class="text-muted">(' + s.score + '%)</span></li>';
        });
        html += '</ul></div>';
    });
    return html;
}

function renderCompare(data) {
    const hospitals = data.hospitals || [];
    const rows = data.rows || [];
    let head = '<th class="col-drug">tmtdrug / วิธีใช้</th>';
    hospitals.forEach(h => { head += '<th title="' + esc(h) + '">' + esc(h) + '</th>'; });
    $('#referHead').html(head);

    const $body = $('#referBody').empty();
    rows.forEach(r => {
        let method = r.method ? '<br><small class="text-muted">' + esc(r.method) + '</small>' : '';
        const suggest = renderSuggestBox(r, hospitals);
        let tr = '<tr><td class="col-drug">' + renderDrugNameHtml(r) + method + suggest + '</td>';
        hospitals.forEach(h => {
            const match = r.hospitals && r.hospitals[h];
            tr += '<td class="hos-cell">' + renderCell(match) + '</td>';
        });
        tr += '</tr>';
        $body.append(tr);
    });

    const s = data.summary || {};
    const p = data.patient || {};
    const v = data.visit || {};
    let summaryHtml = '<i class="fas fa-user me-1"></i>' + esc(p.full_name || '') +
        ' | HN: <strong>' + esc(v.hn || p.hn || '') + '</strong> | ' +
        esc(v.visit_label || '') + ' | ' + esc(v.filter_note || '');
    if (p.address) {
        summaryHtml += '<br><i class="fas fa-map-marker-alt me-1"></i>ที่อยู่: ' + esc(p.address);
    }
    const rights = v.pttype_name || p.pttype_name || '';
    if (rights) {
        summaryHtml += '<br><i class="fas fa-id-card me-1"></i>สิทธิการรักษา: <strong>' + esc(rights) + '</strong>';
    }
    summaryHtml += '<br>รายการยา <strong>' + (s.total || 0) + '</strong> | ' +
        '<span class="text-success"><i class="fas fa-check me-1"></i>ตรง 100% ' + (s.exact_all || 0) + '</span> | ' +
        '<span class="text-warning"><i class="fas fa-lightbulb me-1"></i>ใกล้เคียง ' + (s.similar_some || 0) + '</span> | ' +
        '<span class="text-danger"><i class="fas fa-times me-1"></i>ไม่พบ ' + (s.missing_any || 0) + '</span>' +
        '<br><small class="text-muted"><i class="fas fa-check text-success"></i> ตรง | <i class="fas fa-lightbulb text-warning"></i> ใกล้เคียง | <i class="fas fa-times text-danger"></i> ไม่พบ</small>';
    $('#resultSummary').html(summaryHtml);
    $('#resultCard').removeClass('d-none');

    lastCompareData = data;
    $('#printArea').html(DrugReferMatchPrint.buildMultiHospitalComparePrintHtml(data, {
        footerLabel: 'THAM Hos-Safe — Refer ยาผู้ป่วย'
    }));
}

function searchPatient() {
    const hn = $('#hnInput').val().trim();
    if (!hn) return;
    selectedVisit = null;
    $('.hos-pick-chk').prop('checked', false);
    $('#resultCard').addClass('d-none');
    $('#patientInfo').html('<i class="fas fa-spinner fa-spin"></i> กำลังค้นหา...');
    fetch('fetch_drug_refer_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'visits', hn: hn, months: parseInt($('#monthsSelect').val(), 10) || 36 })
    })
    .then(r => r.json())
    .then(res => {
        if (res.status !== 'success') {
            $('#patientInfo').html('<span class="text-danger">' + esc(res.message) + '</span>');
            $('#visitEmpty').removeClass('d-none').text(res.message || 'ไม่พบข้อมูล');
            $('#visitList').addClass('d-none');
            return;
        }
        patientData = res.patient;
        visitsData = res.visits || [];
        $('#patientInfo').html(renderPatientInfo(patientData));
        renderVisits(visitsData);
        applySearchDefaults(res);
    })
    .catch(() => $('#patientInfo').html('<span class="text-danger">ค้นหาไม่สำเร็จ</span>'));
}

$('#btnSearch, #hnInput').on('keypress', function(e) {
    if (e.which === 13) { e.preventDefault(); searchPatient(); }
});
$('#btnSearch').on('click', searchPatient);
$('#monthsSelect').on('change', function() {
    if ($('#hnInput').val().trim()) searchPatient();
});

$(document).on('click', '.visit-item', function() {
    selectVisit(parseInt($(this).data('idx'), 10));
});

$('.hos-pick-chk').on('change', updateCompareBtn);
$('#btnHosAll').on('click', function() { $('.hos-pick-chk').prop('checked', true); updateCompareBtn(); });
$('#btnHosNone').on('click', function() { $('.hos-pick-chk').prop('checked', false); updateCompareBtn(); });

$('#btnCompare').on('click', runCompare);

$('#btnPrint').on('click', function() {
    if (!lastCompareData) {
        alert('กรุณาเทียบบัญชียาก่อนพิมพ์');
        return;
    }
    window.print();
});

if ($('#hnInput').val().trim()) searchPatient();
</script>
</body>
</html>
