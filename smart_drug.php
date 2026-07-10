<?php
session_start();
if (!isset($_SESSION['user_id']) && !isset($_SESSION['full_name'])) {
    header("Location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Smart Drug - THAM SAFE</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
    <script src="js/chart.min.js"></script>
    <style>
        body { font-family: 'Sarabun', sans-serif; background-color: #f4f7f6; }
        .card { border: none; box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075); margin-bottom: 20px; }
        .visit-list-panel {
            max-height: 520px;
            overflow-y: auto;
            border-right: 1px solid #dee2e6;
            background: #fafbfc;
        }
        .visit-item {
            padding: 10px 12px;
            border-bottom: 1px solid #e9ecef;
            cursor: pointer;
            transition: background 0.15s;
        }
        .visit-item:hover { background: #e8f4fd; }
        .visit-item.active {
            background: #1f4d3b;
            color: #fff;
        }
        .visit-item.active .visit-meta { color: rgba(255,255,255,0.85); }
        .visit-item.active .visit-type-icon i { color: #fff !important; }
        .visit-date { font-weight: 700; font-size: 0.95rem; }
        .visit-meta { font-size: 0.8rem; color: #6c757d; }
        .visit-badge {
            font-size: 0.7rem;
            padding: 2px 8px;
            border-radius: 10px;
            background: rgba(0,0,0,0.08);
        }
        .visit-badge-opd {
            background: #0d6efd;
            color: #fff;
            font-weight: 700;
        }
        .visit-badge-ipd {
            background: #e67e22;
            color: #fff;
            font-weight: 700;
        }
        .visit-type-icon {
            width: 1.25rem;
            text-align: center;
        }
        .visit-item.active .visit-badge-opd,
        .visit-item.active .visit-badge-ipd {
            background: rgba(255,255,255,0.25);
            color: #fff;
        }
        .visit-item.active .visit-badge {
            background: rgba(255,255,255,0.2);
            color: #fff;
        }
        .drug-table-wrap { max-height: 520px; overflow-y: auto; }
        .clickable-drug { cursor: pointer; color: #0d6efd; text-decoration: underline; font-weight: bold; }
        .clickable-drug:hover { color: #0a58ca; }
        #chartContainer { position: relative; height: 350px; }
        .panel-title {
            background: #495057;
            color: #fff;
            padding: 10px 14px;
            font-weight: 700;
            font-size: 0.9rem;
        }
        .selected-visit-bar {
            background: #eef7f3;
            border-bottom: 1px solid #c5d4a0;
            padding: 10px 16px;
            font-weight: 600;
            color: #1a4032;
        }
        #drugTable .col-calc-qty { display: none; }
        #drugTable.show-calc-qty .col-calc-qty { display: table-cell; }
        .order-group-row td {
            background: #e9ecef !important;
            color: #495057;
            border-top: 2px solid #ced4da;
        }
        .badge-hme {
            background: #198754;
            color: #fff;
            font-size: 0.68rem;
            vertical-align: middle;
        }
    </style>
</head>
<body>

<div class="wrapper">
    <?php include('sidebar.php'); ?>

    <div id="content" class="content">
        <div class="container-fluid">

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="fw-bold mb-0 text-dark"><i class="fas fa-capsules me-2 text-warning"></i>ระบบ Smart Drug (Medication Timeline)</h4>
            </div>

            <div class="card p-4 rounded-3 shadow-sm border-primary">
                <div class="row align-items-end g-3">
                    <div class="col-md-5">
                        <label class="form-label fw-bold text-primary">ระบุ HN ผู้ป่วย:</label>
                        <div class="input-group">
                            <span class="input-group-text bg-primary text-white"><i class="fas fa-id-card"></i></span>
                            <input type="text" id="search_hn" class="form-control form-control-lg border-primary fw-bold text-primary" placeholder="กรอก HN (ไม่ต้องใส่ 0 นำหน้า)" value="<?php echo isset($_GET['hn']) ? htmlspecialchars($_GET['hn']) : ''; ?>" onkeypress="if(event.keyCode==13) fetchSmartDrugData()">
                            <button class="btn btn-primary btn-lg fw-bold" onclick="fetchSmartDrugData()"><i class="fas fa-search me-1"></i> ค้นหา</button>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold text-secondary">ช่วงเวลาย้อนหลัง:</label>
                        <select id="search_months" class="form-select form-select-lg">
                            <option value="0" selected>ทั้งหมด (สูงสุด 5,000 รายการ)</option>
                            <option value="12">12 เดือน</option>
                            <option value="24">24 เดือน</option>
                            <option value="36">36 เดือน</option>
                            <option value="60">5 ปี</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <div id="resultMeta" class="small text-muted pt-4"></div>
                    </div>
                </div>
                <div id="patientInfoRow" class="row mt-3" style="display:none;">
                    <div class="col-12">
                        <div class="d-flex align-items-center gap-3 p-3 rounded-3 border border-success bg-white shadow-sm">
                            <i class="fas fa-user-circle fa-2x text-success"></i>
                            <div>
                                <div class="fw-bold fs-5 text-dark" id="patientName">-</div>
                                <div class="small text-muted">HN: <span id="patientHnDisplay" class="fw-bold text-primary"></span></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card rounded-3 border-secondary shadow-sm">
                <div class="card-header bg-secondary text-white fw-bold py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div><i class="fas fa-history me-2"></i>ประวัติการรับยา (OPD + IPD)
                        <span class="badge bg-light text-dark ms-1 fw-normal"><i class="fas fa-hospital-user text-primary"></i> OPD</span>
                        <span class="badge bg-light text-dark ms-1 fw-normal"><i class="fas fa-procedures text-warning"></i> IPD</span>
                        <span class="badge bg-success ms-1 fw-normal"><i class="fas fa-home"></i> HME ยากลับบ้าน</span>
                    </div>
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <label class="me-2 mb-0 text-white fw-bold" style="white-space: nowrap;"><i class="fas fa-filter"></i> กรองชื่อยา:</label>
                        <select id="drugFilter" class="form-select form-select-sm fw-bold border-0 shadow-sm text-primary" style="min-width: 220px; cursor: pointer;" onchange="filterTableByDrug()">
                            <option value="all">-- แสดงยาทั้งหมด --</option>
                        </select>
                        <div class="form-check form-check-inline mb-0 ms-1">
                            <input class="form-check-input" type="checkbox" id="toggleCalcQtyCol" onchange="toggleCalcQtyColumn()">
                            <label class="form-check-label text-white fw-bold small" for="toggleCalcQtyCol" style="white-space: nowrap;">แสดงจำนวนคำนวณ mg</label>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="row g-0">
                        <div class="col-md-3 col-lg-3">
                            <div class="panel-title"><i class="fas fa-list me-1"></i> Visit List</div>
                            <div id="visitList" class="visit-list-panel">
                                <div class="text-center text-muted py-5 px-2 small">ค้นหา HN เพื่อแสดงรายการ Visit</div>
                            </div>
                        </div>
                        <div class="col-md-9 col-lg-9">
                            <div id="selectedVisitBar" class="selected-visit-bar">
                                <i class="fas fa-calendar-check me-1"></i> เลือก Visit จากรายการด้านซ้าย
                            </div>
                            <div class="drug-table-wrap">
                                <table id="drugTable" class="table table-hover table-striped align-middle mb-0">
                                    <thead class="table-light text-center sticky-top">
                                        <tr>
                                            <th width="5%">ลำดับ</th>
                                            <th class="text-start" width="26%">รายการยา (icode)</th>
                                            <th width="10%">จำนวนสั่งยา (qty)</th>
                                            <th class="col-calc-qty" width="10%">จำนวนคำนวณ mg</th>
                                            <th width="10%">ขนาด (mg)</th>
                                            <th class="text-start" width="39%">วิธีใช้ / Rate</th>
                                        </tr>
                                    </thead>
                                    <tbody id="drugHistoryTbody">
                                        <tr><td colspan="6" class="text-center text-muted py-5"><i class="fas fa-search fa-2x mb-3 d-block text-black-50"></i> กรุณาค้นหาด้วย HN เพื่อดึงข้อมูลจาก HOSxP</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="analysisSection" class="mt-4" style="display:none;">
                <div class="card rounded-3 shadow border-info">
                    <div class="card-header bg-info text-white d-flex justify-content-between align-items-center py-3">
                        <span class="fw-bold"><i class="fas fa-chart-line me-2"></i>Timeline & Cumulative Dose: <span id="selectedDrugLabel" class="badge bg-warning text-dark fs-6 ms-2"></span></span>
                        <button class="btn btn-sm btn-light text-danger fw-bold" onclick="$('#analysisSection').fadeOut()"><i class="fas fa-times"></i> ปิดกราฟ</button>
                    </div>
                    <div class="card-body p-4">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                            <div class="d-flex flex-wrap gap-3 align-items-center">
                                <span class="fw-bold text-secondary"><i class="fas fa-sliders-h me-1"></i> แสดงในกราฟ:</span>
                                <div class="form-check form-check-inline mb-0">
                                    <input class="form-check-input" type="checkbox" id="chartShowQty" checked onchange="refreshChart()">
                                    <label class="form-check-label fw-bold" for="chartShowQty">จำนวนสั่งยา (qty)</label>
                                </div>
                                <div class="form-check form-check-inline mb-0">
                                    <input class="form-check-input" type="checkbox" id="chartShowMg" checked onchange="refreshChart()">
                                    <label class="form-check-label fw-bold" for="chartShowMg">ขนาด (mg)</label>
                                </div>
                            </div>
                            <div class="d-flex gap-2 flex-wrap">
                                <div id="cumQtyBox" class="shadow-sm bg-white border border-primary border-2 rounded-3 px-3 py-2 text-end">
                                    <small class="text-muted d-block fw-bold mb-0">สะสมจำนวนสั่งยา</small>
                                    <span id="cumQtyValue" class="fs-4 fw-bold text-primary">0</span>
                                    <small id="cumQtyUnit" class="text-primary fw-bold">หน่วย</small>
                                </div>
                                <div id="cumMgBox" class="shadow-sm bg-white border border-danger border-2 rounded-3 px-3 py-2 text-end">
                                    <small class="text-muted d-block fw-bold mb-0">สะสม mg</small>
                                    <span id="cumMgValue" class="fs-4 fw-bold text-danger">0</span>
                                    <small class="text-danger fw-bold">mg</small>
                                </div>
                            </div>
                        </div>
                        <div id="chartContainer" style="position: relative; height: 320px; width: 100%;">
                            <canvas id="drugTimelineChart"></canvas>
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
let globalHistoryData = [];
let globalVisits = [];
let selectedVisitKey = null;
let drugChart = null;
let currentChartDrug = null;

$(document).ready(function() {
    if ($('#search_hn').val().trim() !== '') {
        fetchSmartDrugData();
    }
});

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function extractMgFromText(text) {
    if (!text) return null;
    let mcgMatch = text.match(/(\d+(?:\.\d+)?)\s*(?:mcg|µg|μg|ug|ไมโครกรัม)/i);
    if (mcgMatch) return parseFloat(mcgMatch[1]) / 1000;
    let mgMatch = text.match(/(\d+(?:\.\d+)?)\s*mg(?![a-z])/i);
    if (mgMatch) return parseFloat(mgMatch[1]);
    let gMatch = text.match(/(\d+(?:\.\d+)?)\s*(?:g|gm|gram|กร\.?|กรัม)(?![a-zA-Z])/i);
    if (gMatch) return parseFloat(gMatch[1]) * 1000;
    return null;
}

function parseQtyFromUsage(text) {
    if (!text) return null;
    let half = text.match(/(\d+(?:\.\d+)?)\s*เม็ด\s*ครึ่ง/i);
    if (half) return { qty: parseFloat(half[1]) + 0.5, unit: 'เม็ด' };
    if (/เม็ด\s*ครึ่ง|ครึ่ง\s*เม็ด/i.test(text)) return { qty: 0.5, unit: 'เม็ด' };

    let pill = text.match(/(?:ครั้งละ|รับประทานครั้งละ|ทานครั้งละ|ใช้ครั้งละ)?\s*(\d+(?:\.\d+)?)\s*เม็ด/i);
    if (pill) return { qty: parseFloat(pill[1]), unit: 'เม็ด' };

    let cap = text.match(/(\d+(?:\.\d+)?)\s*(?:cap|capsule|tab|tablet)/i);
    if (cap) return { qty: parseFloat(cap[1]), unit: 'เม็ด' };

    let spoon = text.match(/(\d+(?:\.\d+)?)\s*ช้อน(?:โต๊ะ|tea)?/i);
    if (spoon) return { qty: parseFloat(spoon[1]), unit: 'ช้อน' };

    let sachet = text.match(/(\d+(?:\.\d+)?)\s*(?:ซอง|sachet)/i);
    if (sachet) return { qty: parseFloat(sachet[1]), unit: 'ซอง' };

    return null;
}

function parseStrengthMg(strength, units) {
    const s = String(strength ?? '').trim().toLowerCase();
    const u = String(units ?? '').trim().toLowerCase();
    const combined = (s + ' ' + u).trim();
    if (!combined) return null;

    const numMatch = combined.match(/(\d+(?:\.\d+)?)/);
    if (!numMatch) return null;
    const num = parseFloat(numMatch[1]);

    // ตรวจ mcg ก่อน mg เพราะ "mcg" มี "mg" เป็นส่วนหนึ่ง
    if (/\bmcg\b|µg|μg|\bug\b|ไมโครกรัม/i.test(combined)) {
        return num / 1000;
    }
    if (/\bmg\b|มิลลิกรัม|มก\./i.test(combined)) {
        return num;
    }
    if (/\b(?:g|gm|gram|กร\.?|กรัม)\b/i.test(combined)) {
        return num * 1000;
    }
    return num;
}

function parseDrugDose(item) {
    const usage = (item.method || '').trim();
    const orderQty = parseFloat(item.qty);
    const strengthMg = parseStrengthMg(item.strength, item.units);

    let pillQty = null;
    let pillUnit = 'หน่วย';
    let mg = extractMgFromText(usage);
    let mgNote = mg !== null ? 'จากวิธีใช้' : '';

    const fromUsage = parseQtyFromUsage(usage);
    if (fromUsage) {
        pillQty = fromUsage.qty;
        pillUnit = fromUsage.unit;
    } else if (!isNaN(orderQty) && orderQty > 0) {
        pillQty = orderQty;
        const u = (item.units || '').trim();
        if (/เม็ด|tab|cap/i.test(u)) pillUnit = 'เม็ด';
        else if (u) pillUnit = u;
    }

    if (mg === null && strengthMg !== null && pillQty !== null) {
        mg = strengthMg * pillQty;
        mgNote = `strength × จำนวน`;
    } else if (mg === null && strengthMg !== null && !isNaN(orderQty) && orderQty > 0) {
        mg = strengthMg * orderQty;
        mgNote = `strength × qty`;
    }

    return {
        pill_qty: pillQty,
        pill_unit: pillUnit,
        mg_dose: mg,
        mg_note: mgNote,
        usage_display: usage || '-'
    };
}

function enrichDose(item) {
    const parsed = parseDrugDose(item);
    item.pill_qty = parsed.pill_qty;
    item.pill_unit = parsed.pill_unit;
    item.mg_dose = parsed.mg_dose;
    item.mg_note = parsed.mg_note;
    item.usage_display = parsed.usage_display;
    return item;
}

function formatQtyDisplay(qty, unit, colorClass) {
    if (qty === null || qty === undefined || isNaN(qty)) return '<span class="text-muted">-</span>';
    const display = Number.isInteger(qty) ? qty : qty.toLocaleString(undefined, { maximumFractionDigits: 2 });
    const cls = colorClass || 'text-primary';
    return `<span class="fw-bold ${cls} fs-5">${display}</span> <span class="text-muted fw-bold">${escapeHtml(unit || '')}</span>`;
}

function formatOrderQtyDisplay(item) {
    const qty = parseFloat(item.qty);
    const unit = (item.units || '').trim() || 'หน่วย';
    return formatQtyDisplay(isNaN(qty) ? null : qty, unit, 'text-success');
}

function toggleCalcQtyColumn() {
    $('#drugTable').toggleClass('show-calc-qty', $('#toggleCalcQtyCol').is(':checked'));
}

function showPatientInfo(patient) {
    if (!patient || !patient.full_name) {
        $('#patientInfoRow').hide();
        return;
    }
    $('#patientName').text(patient.full_name);
    $('#patientHnDisplay').text(patient.hn || $('#search_hn').val().trim());
    $('#patientInfoRow').show();
}

function clearPatientInfo() {
    $('#patientInfoRow').hide();
    $('#patientName').text('-');
    $('#patientHnDisplay').text('');
}

function formatMgDisplay(mg, note) {
    if (mg === null || mg === undefined || isNaN(mg)) return '<span class="text-muted">-</span>';
    const frac = (mg > 0 && mg < 1) ? 4 : 2;
    const display = mg.toLocaleString(undefined, { maximumFractionDigits: frac });
    let html = `<span class="fw-bold text-danger fs-5">${display}</span> <span class="text-muted fw-bold">mg</span>`;
    if (note) html += `<br><small class="text-muted">${escapeHtml(note)}</small>`;
    return html;
}

async function fetchSmartDrugData() {
    let hn = $('#search_hn').val().trim();
    if (!hn) { alert('กรุณาระบุ HN ก่อนครับ'); return; }

    if (!isNaN(hn) && hn.length > 0 && hn.length < 9) {
        hn = hn.padStart(9, '0');
        $('#search_hn').val(hn);
    }

    selectedVisitKey = null;
    $('#resultMeta').text('');
    clearPatientInfo();
    $('#visitList').html('<div class="text-center text-primary py-5"><i class="fas fa-spinner fa-spin fa-2x"></i><div class="mt-2 small">กำลังโหลด Visit...</div></div>');
    $('#drugHistoryTbody').html('<tr><td colspan="6" class="text-center text-primary py-5"><i class="fas fa-spinner fa-spin fa-2x mb-2 d-block"></i> กำลังดึงข้อมูลจาก HOSxP...</td></tr>');
    $('#selectedVisitBar').html('<i class="fas fa-calendar-check me-1"></i> กำลังโหลด...');
    $('#analysisSection').hide();

    const months = parseInt($('#search_months').val(), 10);
    const monthsVal = isNaN(months) ? 0 : months;
    const limit = monthsVal === 0 ? 5000 : 3000;

    try {
        const response = await fetch('fetch_hosxp_history_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ hn: hn, months: monthsVal, limit: limit })
        });

        const raw = await response.text();
        let data;
        try {
            data = JSON.parse(raw);
        } catch (e) {
            throw new Error('เซิร์ฟเวอร์ตอบกลับไม่ถูกต้อง — ลองเลือกช่วงเวลาที่สั้นลง');
        }

        if (data.status === 'success' && data.history && data.history.length > 0) {
            globalHistoryData = data.history.map(enrichDose);
            globalVisits = data.visits || [];
            showPatientInfo(data.patient);
            populateDrugFilter(globalHistoryData);
            renderVisitList(globalVisits);

            if (globalVisits.length > 0) {
                selectVisit(globalVisits[0].visit_key);
            }

            const ipdCount = globalVisits.filter(v => v.visit_type === 'ipd').length;
            const opdCount = globalVisits.length - ipdCount;
            let metaText = `${globalVisits.length} Visit (OPD ${opdCount} · IPD ${ipdCount}) · ${data.history.length} รายการยา`;
            if (data.meta && data.meta.months > 0) metaText += ` (ย้อนหลัง ${data.meta.months} เดือน)`;
            else metaText += ' (ทั้งหมด)';
            if (data.meta && data.meta.truncated) metaText += ' — สูงสุด ' + (data.meta.limit || limit) + ' รายการ';
            $('#resultMeta').text(metaText);
        } else if (data.status === 'success') {
            globalHistoryData = [];
            globalVisits = [];
            showPatientInfo(data.patient);
            $('#visitList').html('<div class="text-center text-warning py-4 px-2 small">ไม่พบ Visit ที่มีรายการยา</div>');
            $('#drugHistoryTbody').html('<tr><td colspan="6" class="text-center text-warning py-5">ไม่พบประวัติการรับยาในช่วงเวลาที่เลือก</td></tr>');
            $('#selectedVisitBar').html('<i class="fas fa-info-circle me-1"></i> ไม่พบข้อมูล');
        } else {
            $('#visitList').html('<div class="text-danger py-4 px-2 small text-center">' + escapeHtml(data.message) + '</div>');
            $('#drugHistoryTbody').html('<tr><td colspan="6" class="text-center text-danger py-5">' + escapeHtml(data.message || 'เกิดข้อผิดพลาด') + '</td></tr>');
        }
    } catch (err) {
        $('#visitList').html('<div class="text-danger py-4 px-2 small text-center">' + escapeHtml(err.message) + '</div>');
        $('#drugHistoryTbody').html('<tr><td colspan="6" class="text-center text-danger py-5">' + escapeHtml(err.message) + '</td></tr>');
        console.error(err);
    }
}

function visitTypeBadge(visit) {
    if (visit.visit_type === 'ipd') {
        return '<span class="visit-badge visit-badge-ipd"><i class="fas fa-procedures me-1"></i>IPD</span>';
    }
    return '<span class="visit-badge visit-badge-opd"><i class="fas fa-hospital-user me-1"></i>OPD</span>';
}

function visitRefLabel(visit) {
    if (visit.visit_type === 'ipd') {
        let s = 'AN: ' + escapeHtml(visit.an || '-');
        if (visit.ward_name) s += ' · ' + escapeHtml(visit.ward_name);
        return s;
    }
    return 'VN: ' + escapeHtml(visit.vn || '-');
}

function renderVisitList(visits) {
    if (!visits.length) {
        $('#visitList').html('<div class="text-center text-muted py-4 small">ไม่มี Visit</div>');
        return;
    }

    let html = '';
    let seq = visits.length;
    visits.forEach(v => {
        const vk = v.visit_key || ('opd:' + (v.vn || ''));
        const active = (vk === selectedVisitKey) ? ' active' : '';
        const safeKey = escapeHtml(vk).replace(/'/g, '&#39;');
        html += `
            <div class="visit-item${active}" data-visit-key="${escapeHtml(vk)}" onclick="selectVisit('${safeKey}')">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="flex-grow-1">
                        <div class="visit-date d-flex align-items-center gap-2">
                            <span class="visit-type-icon">${v.visit_type === 'ipd' ? '<i class="fas fa-procedures text-warning"></i>' : '<i class="fas fa-hospital-user text-primary"></i>'}</span>
                            <span>${seq}. ${escapeHtml(v.vstdate_display)}</span>
                        </div>
                        <div class="visit-meta"><i class="far fa-clock me-1"></i>${escapeHtml(v.vsttime_display || '-')}</div>
                        <div class="visit-meta small">${visitRefLabel(v)}</div>
                    </div>
                    <div class="text-end">
                        ${visitTypeBadge(v)}
                        <div class="mt-1"><span class="visit-badge">${v.drug_count} ยา</span></div>
                    </div>
                </div>
            </div>`;
        seq--;
    });
    $('#visitList').html(html);
}

function selectVisit(visitKey) {
    selectedVisitKey = visitKey;
    $('.visit-item').removeClass('active');
    $('.visit-item[data-visit-key="' + visitKey + '"]').addClass('active');

    const visit = globalVisits.find(v => (v.visit_key || ('opd:' + v.vn)) === visitKey);
    if (visit) {
        const typeLabel = visit.visit_type === 'ipd'
            ? `<span class="badge visit-badge-ipd ms-1"><i class="fas fa-procedures"></i> IPD</span>`
            : `<span class="badge visit-badge-opd ms-1"><i class="fas fa-hospital-user"></i> OPD</span>`;
        const ref = visit.visit_type === 'ipd'
            ? `AN: <strong>${escapeHtml(visit.an)}</strong>${visit.ward_name ? ' · ' + escapeHtml(visit.ward_name) : ''}`
            : `VN: <strong>${escapeHtml(visit.vn)}</strong>`;
        $('#selectedVisitBar').html(
            `<i class="fas fa-calendar-check me-1"></i> Visit: <strong>${escapeHtml(visit.vstdate_display)}</strong> ${escapeHtml(visit.vsttime_display || '')} ` +
            typeLabel +
            `<span class="text-muted ms-2">(${ref} · ${visit.drug_count} รายการยา)</span>`
        );
    }

    $('#analysisSection').hide();
    filterTableByDrug();
}

function getPrimaryDrugName(item) {
    return (item.drug_name_local || item.drug_name || '').trim() || '-';
}

function getTmtDrugName(item) {
    const tmt = (item.drug_name || '').trim();
    const local = (item.drug_name_local || '').trim();
    if (tmt && tmt !== local) return tmt;
    return '';
}

function getSelectedVisit() {
    return globalVisits.find(v => (v.visit_key || ('opd:' + v.vn)) === selectedVisitKey) || null;
}

function getOrderGroupKey(item) {
    const d = item.order_date_display || item.vstdate_display || '-';
    const t = (item.order_time_display || item.vsttime_display || '').substring(0, 8);
    return d + (t ? ' ' + t : '');
}

function sortItemsForVisit(items) {
    const visit = getSelectedVisit();
    if (visit && visit.visit_type === 'ipd') {
        return [...items].sort((a, b) => {
            const ka = a.order_sort_key || (a.vstdate_iso + ' ' + (a.vsttime_display || ''));
            const kb = b.order_sort_key || (b.vstdate_iso + ' ' + (b.vsttime_display || ''));
            const c = ka.localeCompare(kb);
            if (c !== 0) return c;
            return String(a.item_no || '').localeCompare(String(b.item_no || ''), undefined, { numeric: true });
        });
    }
    return items;
}

function renderDrugRow(item, rowNo, showHme) {
    const primaryName = escapeHtml(getPrimaryDrugName(item));
    const tmtName = getTmtDrugName(item);
    const hmeBadge = (showHme && item.is_hme)
        ? ' <span class="badge badge-hme" title="ยากลับบ้าน (Home Medication)"><i class="fas fa-home me-1"></i>HME</span>'
        : '';
    return `
        <tr>
            <td class="text-center fw-bold text-secondary">${rowNo}</td>
            <td class="align-middle">
                <span class="clickable-drug fs-6" data-drug="${primaryName}" onclick="plotDrugTimeline(this.dataset.drug)">
                    <i class="fas fa-pills me-1 text-primary"></i> ${primaryName}${hmeBadge}
                </span><br>
                <small class="badge bg-white text-secondary border mt-1 shadow-sm"><i class="fas fa-barcode me-1"></i> ${escapeHtml(item.icode || '-')}</small>
                ${tmtName ? `<br><small class="text-dark">${escapeHtml(tmtName)}</small>` : ''}
            </td>
            <td class="text-center align-middle">${formatOrderQtyDisplay(item)}</td>
            <td class="text-center align-middle col-calc-qty">${formatQtyDisplay(item.pill_qty, item.pill_unit, 'text-primary')}</td>
            <td class="text-center align-middle">${formatMgDisplay(item.mg_dose, item.mg_note)}</td>
            <td class="text-dark align-middle"><small class="fw-bold">${escapeHtml(item.usage_display || '-')}</small></td>
        </tr>`;
}

function getFilteredItems() {
    let items = globalHistoryData.filter(i => (i.visit_key || ('opd:' + i.vn)) === selectedVisitKey);
    const selectedDrug = $('#drugFilter').val();
    if (selectedDrug && selectedDrug !== 'all') {
        items = items.filter(i => getPrimaryDrugName(i) === selectedDrug);
    }
    return sortItemsForVisit(items);
}

function renderTable(items) {
    if (!items.length) {
        $('#drugHistoryTbody').html('<tr><td colspan="6" class="text-center text-muted py-4">ไม่มีรายการยาใน Visit นี้</td></tr>');
        return;
    }

    const visit = getSelectedVisit();
    const isIpd = visit && visit.visit_type === 'ipd';
    let html = '';
    let lastGroup = null;
    let rowNo = 0;
    items.forEach((item) => {
        if (isIpd) {
            const groupKey = getOrderGroupKey(item);
            if (groupKey !== lastGroup) {
                html += `
                    <tr class="order-group-row">
                        <td colspan="6" class="fw-bold small py-2">
                            <i class="far fa-calendar-alt me-1"></i> สั่งยา ${escapeHtml(groupKey)}
                        </td>
                    </tr>`;
                lastGroup = groupKey;
            }
        }
        rowNo += 1;
        html += renderDrugRow(item, rowNo, isIpd);
    });
    $('#drugHistoryTbody').html(html);
    toggleCalcQtyColumn();
}

function plotDrugTimeline(drugName) {
    currentChartDrug = drugName;
    $('#selectedDrugLabel').text(drugName);
    $('#analysisSection').fadeIn();
    refreshChart();
    $('html, body').animate({ scrollTop: $('#analysisSection').offset().top - 50 }, 500);
}

function refreshChart() {
    if (!currentChartDrug) return;

    const showQty = $('#chartShowQty').is(':checked');
    const showMg = $('#chartShowMg').is(':checked');

    $('#cumQtyBox').toggle(showQty);
    $('#cumMgBox').toggle(showMg);

    if (!showQty && !showMg) {
        if (drugChart) { drugChart.destroy(); drugChart = null; }
        return;
    }

    const drugRecords = globalHistoryData
        .filter(d => getPrimaryDrugName(d) === currentChartDrug)
        .sort((a, b) => {
            const da = a.vstdate_iso + ' ' + (a.vsttime_display || '');
            const db = b.vstdate_iso + ' ' + (b.vsttime_display || '');
            return da.localeCompare(db);
        });

    if (!drugRecords.length) return;

    const labels = drugRecords.map(r =>
        r.vstdate_display + ' ' + (r.vsttime_display || '').substring(0, 5)
    );

    const datasets = [];
    let totalQty = 0;
    let totalMg = 0;
    let qtyUnit = (drugRecords[0].units || '').trim() || 'หน่วย';

    if (showQty) {
        const qtyData = drugRecords.map(r => {
            const v = parseFloat(r.qty);
            if (!isNaN(v)) totalQty += v;
            return !isNaN(v) ? v : null;
        });
        datasets.push({
            label: 'จำนวนสั่งยา (' + qtyUnit + ')',
            data: qtyData,
            borderColor: '#0d6efd',
            backgroundColor: 'rgba(13, 110, 253, 0.08)',
            borderWidth: 2,
            tension: 0.3,
            fill: false,
            yAxisID: 'yQty',
            spanGaps: true,
            pointRadius: 5
        });
    }

    if (showMg) {
        const mgData = drugRecords.map(r => {
            const v = r.mg_dose;
            if (v !== null && !isNaN(v)) totalMg += v;
            return (v !== null && !isNaN(v)) ? v : null;
        });
        datasets.push({
            label: 'ขนาด (mg)',
            data: mgData,
            borderColor: '#dc3545',
            backgroundColor: 'rgba(220, 53, 69, 0.08)',
            borderWidth: 2,
            tension: 0.3,
            fill: false,
            yAxisID: 'yMg',
            spanGaps: true,
            pointRadius: 5
        });
    }

    $('#cumQtyValue').text(totalQty.toLocaleString(undefined, { maximumFractionDigits: 2 }));
    $('#cumQtyUnit').text(qtyUnit);
    $('#cumMgValue').text(totalMg.toLocaleString(undefined, { maximumFractionDigits: 2 }));

    const scales = {
        x: { grid: { display: false }, title: { display: true, text: 'วันที่ Visit' } }
    };

    if (showQty) {
        scales.yQty = {
            type: 'linear',
            position: 'left',
            beginAtZero: true,
            title: { display: true, text: 'จำนวนสั่งยา (' + qtyUnit + ')' },
            grid: { color: '#eef2ff' }
        };
    }
    if (showMg) {
        scales.yMg = {
            type: 'linear',
            position: showQty ? 'right' : 'left',
            beginAtZero: true,
            title: { display: true, text: 'mg' },
            grid: { drawOnChartArea: !showQty, color: '#fde8e8' }
        };
    }

    const ctx = document.getElementById('drugTimelineChart').getContext('2d');
    if (drugChart) drugChart.destroy();

    drugChart = new Chart(ctx, {
        type: 'line',
        data: { labels, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { display: true, position: 'top' } },
            scales
        }
    });
}

function populateDrugFilter(history) {
    let uniqueDrugs = [...new Set(history.map(i => getPrimaryDrugName(i)))].filter(d => d && d !== '-').sort();
    let sel = $('#drugFilter');
    sel.empty().append('<option value="all">-- แสดงยาทั้งหมด --</option>');
    uniqueDrugs.forEach(d => sel.append(`<option value="${escapeHtml(d)}">${escapeHtml(d)}</option>`));
}

function filterTableByDrug() {
    if (!selectedVisitKey) return;
    $('#analysisSection').hide();
    renderTable(getFilteredItems());
}
</script>
</body>
</html>
