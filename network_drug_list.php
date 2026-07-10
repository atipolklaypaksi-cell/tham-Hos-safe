<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/network_drug_helper.php';
include('connect.php');
mysqli_set_charset($con, 'utf8');
nd_ensure_tables($con);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>รายการบัญชียาเครือข่าย - THAM SAFE</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
    <link href="css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        .nd-matrix-wrap { overflow-x: auto; }
        #matrixTable { font-size: .82rem; white-space: nowrap; }
        #matrixTable thead th {
            background: #1f4d3b;
            color: #fff;
            vertical-align: middle;
            text-align: center;
            position: sticky;
            top: 0;
            z-index: 2;
        }
        #matrixTable thead th.col-drug {
            text-align: left;
            min-width: 260px;
            left: 0;
            z-index: 3;
        }
        #matrixTable tbody td.col-drug {
            font-weight: 500;
            background: #fff;
            position: sticky;
            left: 0;
            z-index: 1;
            min-width: 260px;
            max-width: 360px;
            white-space: normal;
        }
        #matrixTable tbody tr:nth-child(even) td.col-drug { background: #f8f9fa; }
        #matrixTable tbody tr:nth-child(even) { background: #f8f9fa; }
        #matrixTable td.hos-cell { text-align: center; width: 72px; }
        .nd-check {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            border-radius: 4px;
            background: #22c55e;
            color: #fff;
            font-size: .72rem;
        }
        .nd-meta { font-size: .85rem; }
        #detailTable thead th { background: #0d9488; color: #fff; font-size: .82rem; }
        #detailTable { font-size: .82rem; }
        .dataTables_wrapper .dataTables_filter input { margin-left: .5rem; }
        #hosFilterWrap { background: #f8fcfa; }
        #hosFilterList .form-check {
            margin: 0;
            padding: .35rem .65rem;
            background: #fff;
            border: 1px solid #d8e8df;
            border-radius: 8px;
            min-width: 148px;
        }
        #hosFilterList .form-check-label {
            font-size: .82rem;
            cursor: pointer;
            user-select: none;
        }
    </style>
</head>
<body>
<div class="wrapper">
    <?php include('sidebar.php'); ?>
    <div id="content" class="content">
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header text-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-3" style="background: var(--green-600, #1f4d3b);">
                <h5 class="mb-0 fw-bold"><i class="fas fa-network-wired me-2"></i>รายการบัญชียาเครือข่าย</h5>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-light btn-sm fw-bold" id="btnExportExcel" disabled>
                        <i class="fas fa-file-excel me-1"></i> ส่งออก Excel
                    </button>
                    <a href="network_drug_upload.php" class="btn btn-light btn-sm fw-bold">
                        <i class="fas fa-file-upload me-1"></i> Upload ไฟล์
                    </a>
                </div>
            </div>
            <div class="card-body p-4">
                <div class="row g-2 align-items-end mb-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold mb-1">ค้นหายา</label>
                        <input type="text" id="searchInput" class="form-control form-control-sm" placeholder="ชื่อยา, TMT, TPU code...">
                    </div>
                    <div class="col-md-6">
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="viewMode" id="viewMatrix" value="matrix" checked>
                            <label class="btn btn-outline-success btn-sm" for="viewMatrix"><i class="fas fa-th me-1"></i>ตารางเครือข่าย</label>
                            <input type="radio" class="btn-check" name="viewMode" id="viewDetail" value="detail">
                            <label class="btn btn-outline-success btn-sm" for="viewDetail"><i class="fas fa-list me-1"></i>รายละเอียด</label>
                        </div>
                    </div>
                </div>

                <div id="hosFilterWrap" class="border rounded p-3 mb-3 d-none">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <div class="fw-bold small">
                            <i class="fas fa-hospital me-1 text-success"></i>กรองโรงพยาบาล
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <input type="text" id="hosSearchInput" class="form-control form-control-sm" style="width:180px" placeholder="ค้นหาชื่อ รพ....">
                            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" id="btnHosAll">เลือกทั้งหมด</button>
                            <span class="text-muted">|</span>
                            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" id="btnHosNone">ยกเลิกทั้งหมด</button>
                        </div>
                    </div>
                    <div id="hosFilterList" class="d-flex flex-wrap gap-2"></div>
                    <div class="small text-muted mt-2" id="hosFilterSummary"></div>
                </div>

                <div id="metaInfo" class="nd-meta text-muted mb-3"></div>

                <div id="emptyState" class="alert alert-warning d-none">
                    <i class="fas fa-info-circle me-1"></i>
                    <span id="emptyStateText">ยังไม่มีข้อมูลบัญชียาเครือข่าย —
                    <a href="network_drug_upload.php" class="alert-link">อัปโหลดไฟล์ CSV/Excel</a></span>
                </div>

                <div id="matrixView" class="nd-matrix-wrap">
                    <table id="matrixTable" class="table table-bordered table-sm align-middle mb-0">
                        <thead><tr id="matrixHeadRow"><th class="col-drug">tmtdrug</th></tr></thead>
                        <tbody id="matrixBody"></tbody>
                    </table>
                </div>

                <div id="detailView" class="d-none">
                    <div class="table-responsive">
                        <table id="detailTable" class="table table-bordered table-hover table-sm w-100">
                            <thead>
                                <tr>
                                    <th>tpu_code_list</th>
                                    <th>name</th>
                                    <th>generic_name</th>
                                    <th>strength</th>
                                    <th>tmtdrug</th>
                                    <th>units</th>
                                    <th>dosageform</th>
                                    <th>hosname</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
<script src="js/jquery-3.7.0.min.js"></script>
<script src="js/bootstrap.bundle.min.js"></script>
<script src="js/jquery.dataTables.min.js"></script>
<script src="js/dataTables.bootstrap5.min.js"></script>
<script>
let detailDt = null;
let searchTimer = null;
let matrixCache = null;
let detailCache = null;
let hospitalSelection = {};
let allHospitals = [];

function esc(s) {
    return $('<div>').text(s ?? '').html();
}

function currentSearch() {
    return $('#searchInput').val().trim();
}

function currentMode() {
    return $('input[name="viewMode"]:checked').val() || 'matrix';
}

function getSelectedHospitals() {
    return allHospitals.filter(h => hospitalSelection[h]);
}

function updateHosFilterSummary() {
    const selected = getSelectedHospitals().length;
    const total = allHospitals.length;
    $('#hosFilterSummary').text('แสดง ' + selected + ' / ' + total + ' โรงพยาบาล');
}

function buildHospitalFilters(hospitals) {
    allHospitals = hospitals || [];
    const prev = Object.assign({}, hospitalSelection);
    hospitalSelection = {};
    allHospitals.forEach(h => {
        hospitalSelection[h] = Object.prototype.hasOwnProperty.call(prev, h) ? prev[h] : true;
    });

    if (!allHospitals.length) {
        $('#hosFilterWrap').addClass('d-none');
        $('#hosFilterList').empty();
        $('#hosFilterSummary').empty();
        return;
    }

    $('#hosFilterWrap').removeClass('d-none');
    const term = ($('#hosSearchInput').val() || '').trim().toLowerCase();
    const $list = $('#hosFilterList').empty();

    allHospitals.forEach((h, idx) => {
        if (term && h.toLowerCase().indexOf(term) === -1) {
            return;
        }
        const id = 'hosChk' + idx;
        const checked = hospitalSelection[h] ? 'checked' : '';
        $list.append(
            '<div class="form-check">' +
            '<input class="form-check-input hos-filter-chk" type="checkbox" id="' + id + '" data-hos-idx="' + idx + '" ' + checked + '>' +
            '<label class="form-check-label" for="' + id + '">' + esc(h) + '</label>' +
            '</div>'
        );
    });

    if (!$list.children().length) {
        $list.append('<span class="small text-muted">ไม่พบโรงพยาบาลที่ตรงกับคำค้น</span>');
    }
    updateHosFilterSummary();
}

function applyHospitalFilter() {
    if (currentMode() === 'detail') {
        renderDetail(detailCache || []);
        return;
    }
    if (matrixCache) {
        renderMatrix(matrixCache);
    }
}

function renderMeta(stats) {
    if (!stats || !stats.row_count) {
        $('#metaInfo').empty();
        return;
    }
    $('#metaInfo').html(
        '<i class="fas fa-database me-1"></i>ข้อมูลรวมทั้งเครือข่าย: ' +
        stats.row_count + ' แถว | ' + stats.drug_count + ' ยา | ' + stats.hospital_count + ' โรงพยาบาล' +
        (stats.last_updated ? ' | อัปเดตล่าสุด: ' + esc(stats.last_updated) : '')
    );
}

function showEmptyState(html) {
    $('#emptyStateText').html(html);
    $('#emptyState').removeClass('d-none');
}

function resetEmptyState() {
    $('#emptyStateText').html(
        'ยังไม่มีข้อมูลบัญชียาเครือข่าย — <a href="network_drug_upload.php" class="alert-link">อัปโหลดไฟล์ CSV/Excel</a>'
    );
}

function updateExportButton() {
    const mode = currentMode();
    const hospitals = getSelectedHospitals();
    let enabled = hospitals.length > 0;
    if (mode === 'detail') {
        const selected = new Set(hospitals);
        enabled = enabled && (detailCache || []).some(r => selected.has(r.hosname));
    } else {
        enabled = enabled && matrixCache && (matrixCache.rows || []).length > 0;
    }
    $('#btnExportExcel').prop('disabled', !enabled);
}

function renderMatrix(data) {
    const hospitals = getSelectedHospitals();
    const rows = data.rows || [];

    if (!rows.length) {
        resetEmptyState();
        $('#emptyState').removeClass('d-none');
        $('#matrixView').addClass('d-none');
        updateExportButton();
        return;
    }
    if (!hospitals.length) {
        showEmptyState('กรุณาเลือกโรงพยาบาลอย่างน้อย 1 แห่งเพื่อแสดงตาราง');
        $('#matrixView').addClass('d-none');
        updateExportButton();
        return;
    }
    $('#emptyState').addClass('d-none');
    $('#matrixView').removeClass('d-none');

    let head = '<th class="col-drug">tmtdrug</th>';
    hospitals.forEach(h => { head += '<th title="' + esc(h) + '">' + esc(h) + '</th>'; });
    $('#matrixHeadRow').html(head);

    const $body = $('#matrixBody').empty();
    rows.forEach(row => {
        let tr = '<tr><td class="col-drug">' + esc(row.tmtdrug) + '</td>';
        hospitals.forEach(h => {
            const ok = row.hospitals && row.hospitals[h];
            tr += '<td class="hos-cell">' + (ok ? '<span class="nd-check"><i class="fas fa-check"></i></span>' : '') + '</td>';
        });
        tr += '</tr>';
        $body.append(tr);
    });
    updateExportButton();
}

function renderDetail(rows) {
    const selected = new Set(getSelectedHospitals());
    const filtered = (rows || []).filter(r => selected.has(r.hosname));
    if (detailDt) {
        detailDt.destroy();
        detailDt = null;
    }
    const $tbody = $('#detailTable tbody').empty();
    if (!filtered.length) {
        if (selected.size === 0) {
            showEmptyState('กรุณาเลือกโรงพยาบาลอย่างน้อย 1 แห่งเพื่อแสดงรายละเอียด');
        } else {
            showEmptyState('ไม่พบข้อมูลตามเงื่อนไขที่เลือก');
        }
        $('#detailView').addClass('d-none');
        updateExportButton();
        return;
    }
    $('#emptyState').addClass('d-none');
    $('#detailView').removeClass('d-none');

    filtered.forEach(r => {
        $tbody.append(
            '<tr>' +
            '<td>' + esc(r.tpu_code_list) + '</td>' +
            '<td>' + esc(r.name) + '</td>' +
            '<td>' + esc(r.generic_name) + '</td>' +
            '<td>' + esc(r.strength) + '</td>' +
            '<td>' + esc(r.tmtdrug) + '</td>' +
            '<td>' + esc(r.units) + '</td>' +
            '<td>' + esc(r.dosageform) + '</td>' +
            '<td>' + esc(r.hosname) + '</td>' +
            '</tr>'
        );
    });

    detailDt = $('#detailTable').DataTable({
        pageLength: 50,
        lengthMenu: [[25, 50, 100, 250, -1], [25, 50, 100, 250, 'ทั้งหมด']],
        language: { url: 'js/i18n/datatables-th.json' },
        order: [[4, 'asc']]
    });
    updateExportButton();
}

function getExportFilename() {
    const mode = currentMode();
    const search = currentSearch();
    const date = new Date().toISOString().slice(0, 10);
    let name = 'network_drug_' + (mode === 'detail' ? 'detail' : 'matrix') + '_' + date;
    if (search) {
        name += '_' + search.replace(/[\\/:*?"<>|]/g, '_').slice(0, 40);
    }
    return name + '.xlsx';
}

function matrixRowsForExport() {
    const hospitals = getSelectedHospitals();
    const rows = (matrixCache && matrixCache.rows) || [];
    const data = rows.map(row => {
        const line = [row.tmtdrug || ''];
        hospitals.forEach(h => {
            line.push(row.hospitals && row.hospitals[h] ? '✓' : '');
        });
        return line;
    });
    return { headers: ['tmtdrug'].concat(hospitals), data: data };
}

function detailRowsForExport(rows) {
    const selected = new Set(getSelectedHospitals());
    const filtered = (rows || []).filter(r => selected.has(r.hosname));
    const headers = ['tpu_code_list', 'name', 'generic_name', 'strength', 'tmtdrug', 'units', 'dosageform', 'hosname'];
    const data = filtered.map(r => headers.map(col => r[col] ?? ''));
    return { headers: headers, data: data };
}

function writeExcelSheet(sheet, sheetName) {
    if (typeof XLSX === 'undefined') {
        alert('ไม่สามารถโหลดไลบรารี Excel ได้');
        return false;
    }
    const ws = XLSX.utils.aoa_to_sheet([sheet.headers].concat(sheet.data));
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, sheetName.substring(0, 31));
    XLSX.writeFile(wb, getExportFilename());
    return true;
}

function exportMatrixExcel() {
    const hospitals = getSelectedHospitals();
    if (!hospitals.length) {
        alert('กรุณาเลือกโรงพยาบาลอย่างน้อย 1 แห่ง');
        return;
    }
    const sheet = matrixRowsForExport();
    if (!sheet.data.length) {
        alert('ไม่มีข้อมูลสำหรับส่งออก');
        return;
    }
    writeExcelSheet(sheet, 'ตารางเครือข่าย');
}

function exportDetailExcel(rows) {
    const hospitals = getSelectedHospitals();
    if (!hospitals.length) {
        alert('กรุณาเลือกโรงพยาบาลอย่างน้อย 1 แห่ง');
        return;
    }
    const sheet = detailRowsForExport(rows);
    if (!sheet.data.length) {
        alert('ไม่มีข้อมูลสำหรับส่งออก');
        return;
    }
    writeExcelSheet(sheet, 'รายละเอียด');
}

function runExportExcel() {
    const $btn = $('#btnExportExcel');
    const oldHtml = $btn.html();
    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');

    if (currentMode() === 'detail') {
        fetch('fetch_network_drug_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'detail', search: currentSearch(), all: true })
        })
        .then(r => r.json())
        .then(res => {
            $btn.html(oldHtml);
            if (res.status !== 'success') {
                alert(res.message || 'โหลดข้อมูลไม่สำเร็จ');
                updateExportButton();
                return;
            }
            exportDetailExcel(res.rows || []);
            updateExportButton();
        })
        .catch(() => {
            $btn.html(oldHtml);
            alert('โหลดข้อมูลไม่สำเร็จ');
            updateExportButton();
        });
        return;
    }

    $btn.html(oldHtml);
    exportMatrixExcel();
    updateExportButton();
}

function loadData() {
    const search = currentSearch();
    const mode = currentMode();
    const payload = { search: search };

    if (mode === 'detail') {
        fetch('fetch_network_drug_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ action: 'detail' }, payload))
        })
        .then(r => r.json())
        .then(res => {
            if (res.status !== 'success') return;
            detailCache = res.rows || [];
            buildHospitalFilters(res.hospitals || []);
            renderDetail(detailCache);
        });
        return;
    }

    fetch('fetch_network_drug_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(res => {
        if (res.status !== 'success') return;
        matrixCache = res;
        buildHospitalFilters(res.hospitals || []);
        renderMeta(res.stats);
        renderMatrix(res);
    });
}

function toggleView() {
    const mode = currentMode();
    if (mode === 'detail') {
        $('#matrixView').addClass('d-none');
        $('#detailView').removeClass('d-none');
    } else {
        $('#detailView').addClass('d-none');
        $('#matrixView').removeClass('d-none');
        if (detailDt) {
            detailDt.destroy();
            detailDt = null;
        }
    }
    loadData();
}

$('input[name="viewMode"]').on('change', toggleView);
$('#searchInput').on('input', function() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(loadData, 350);
});

$(document).on('change', '.hos-filter-chk', function() {
    const idx = parseInt($(this).data('hos-idx'), 10);
    const hos = allHospitals[idx];
    if (hos) {
        hospitalSelection[hos] = $(this).is(':checked');
    }
    updateHosFilterSummary();
    applyHospitalFilter();
});

$('#btnHosAll').on('click', function() {
    allHospitals.forEach(h => { hospitalSelection[h] = true; });
    buildHospitalFilters(allHospitals);
    applyHospitalFilter();
});

$('#btnHosNone').on('click', function() {
    allHospitals.forEach(h => { hospitalSelection[h] = false; });
    buildHospitalFilters(allHospitals);
    applyHospitalFilter();
});

$('#hosSearchInput').on('input', function() {
    buildHospitalFilters(allHospitals);
});

$('#btnExportExcel').on('click', runExportExcel);

toggleView();
</script>
</body>
</html>
