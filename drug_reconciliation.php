<?php
session_start();
if (!isset($_SESSION['user_id']) && !isset($_SESSION['full_name'])) {
    header('Location: login.php');
    exit;
}
include('connect.php');
include('drug_reconciliation_helper.php');
mysqli_set_charset($con, 'utf8');
dr_ensure_tables($con);
session_write_close();

$prefill_hn = isset($_GET['hn']) ? htmlspecialchars($_GET['hn']) : '';
$prefill_an = isset($_GET['an']) ? htmlspecialchars($_GET['an']) : '';
$prefill_vn = isset($_GET['vn']) ? htmlspecialchars($_GET['vn']) : '';
$prefill_session = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Drug Reconciliation - THAM SAFE</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', Tahoma, sans-serif; background-color: #f4f7f6; }
        .filter-box { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; }
        .patient-bar { background: #eef7f3; border: 1px solid #c5d4a0; border-radius: 8px; }
        .summary-pill { border-radius: 20px; padding: 4px 12px; font-size: 0.85rem; font-weight: 600; }
        .status-matched { background: #d1e7dd; }
        .status-changed { background: #fff3cd; }
        .status-baseline_only { background: #f8d7da; }
        .status-current_only { background: #cfe2ff; }
        .drug-cell-title { font-weight: 700; color: #1a4032; }
        .drug-cell-meta { font-size: 0.8rem; color: #6c757d; }
        .tmt-label { color: #0d9488; }
        .action-btn-group .btn { min-width: 72px; font-size: 0.8rem; }
        .action-btn-group .btn.active-continue { background: #0d6efd; color: #fff; border-color: #0d6efd; }
        .action-btn-group .btn.active-hold { background: #fd7e14; color: #fff; border-color: #fd7e14; }
        .action-btn-group .btn.active-off { background: #dc3545; color: #fff; border-color: #dc3545; }
        #drTableWrap { max-height: 68vh; overflow: auto; }
        #historyPanel { max-height: 280px; overflow-y: auto; }
    </style>
</head>
<body>
<div class="wrapper">
    <?php include('sidebar.php'); ?>
    <div id="content" class="content">
        <div class="container-fluid py-4">
            <h4 class="fw-bold mb-2 text-dark">
                <i class="fas fa-exchange-alt me-2 text-success"></i>
                Drug Reconciliation
            </h4>
            <p class="text-muted mb-3">เปรียบเทียบรายการยาเดิมกับยาใหม่ กำหนด Continue / Hold / Off และส่งรายการให้แพทย์</p>

            <div class="filter-box p-3 mb-3">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label fw-bold">HN</label>
                        <input type="text" id="search_hn" class="form-control" placeholder="HN" value="<?php echo $prefill_hn; ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-bold">AN (IPD)</label>
                        <input type="text" id="search_an" class="form-control" placeholder="AN" value="<?php echo $prefill_an; ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-bold">VN (OPD)</label>
                        <input type="text" id="search_vn" class="form-control" placeholder="VN" value="<?php echo $prefill_vn; ?>">
                    </div>
                    <div class="col-md-3">
                        <button class="btn btn-success fw-bold" id="btnLoad"><i class="fas fa-search me-1"></i> โหลดเปรียบเทียบ</button>
                        <button class="btn btn-outline-secondary" id="btnClear">ล้าง</button>
                    </div>
                </div>
            </div>

            <div id="patientBar" class="patient-bar p-3 mb-3 d-none">
                <div class="row">
                    <div class="col-md-8">
                        <div class="fw-bold fs-5" id="ptName">—</div>
                        <div class="text-muted" id="ptMeta">—</div>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <div id="summaryPills"></div>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-lg-9">
                    <div class="card shadow-sm">
                        <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                            <span class="fw-bold"><i class="fas fa-table me-1"></i> ตารางเปรียบเทียบยา</span>
                            <div>
                                <button class="btn btn-sm btn-light" id="btnApplySuggest" title="ใช้คำแนะนำอัตโนมัติ"><i class="fas fa-magic"></i> Auto</button>
                            </div>
                        </div>
                        <div class="card-body p-0" id="drTableWrap">
                            <div class="p-4 text-center text-muted" id="emptyState">กรอก HN แล้วกดโหลดเปรียบเทียบ</div>
                            <table class="table table-sm table-hover mb-0 d-none" id="drTable">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th>#</th>
                                        <th>ยาเดิม</th>
                                        <th>ยาใหม่</th>
                                        <th>สถานะ</th>
                                        <th>คำแนะนำเภสัช</th>
                                        <th>หมายเหตุ</th>
                                    </tr>
                                </thead>
                                <tbody id="drTableBody"></tbody>
                            </table>
                        </div>
                    </div>

                    <div class="card shadow-sm mt-3">
                        <div class="card-body">
                            <label class="form-label fw-bold">หมายเหตุถึงแพทย์</label>
                            <textarea id="pharmacistNote" class="form-control" rows="2" placeholder="เช่น ขอให้พิจารณาหยุดยา X เนื่องจาก ..."></textarea>
                            <div class="mt-3 d-flex flex-wrap gap-2">
                                <button class="btn btn-outline-primary" id="btnSaveDraft"><i class="fas fa-save me-1"></i> บันทึกแบบร่าง</button>
                                <button class="btn btn-primary" id="btnSendDoctor"><i class="fas fa-paper-plane me-1"></i> ส่งให้แพทย์ + พิมพ์</button>
                                <span class="align-self-center text-muted small" id="sessionInfo"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-3">
                    <div class="card shadow-sm">
                        <div class="card-header bg-secondary text-white fw-bold"><i class="fas fa-history me-1"></i> ประวัติการส่ง</div>
                        <div class="card-body p-0" id="historyPanel">
                            <div class="p-3 text-muted small" id="historyEmpty">ยังไม่มีประวัติ</div>
                            <ul class="list-group list-group-flush d-none" id="historyList"></ul>
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
let drContext = null;
let drPairs = [];
let drSessionId = <?php echo $prefill_session; ?>;

const statusLabel = {
    matched: 'ตรงกัน',
    changed: 'เปลี่ยนแปลง',
    baseline_only: 'มีเดิม ไม่มีใหม่',
    current_only: 'ยาใหม่'
};

function esc(s) {
    return $('<div>').text(s || '').html();
}

function drugCell(row) {
    if (!row) return '<span class="text-muted">—</span>';
    const name = row.drug_name_tmt || row.drug_name || '—';
    const icode = row.icode ? '<div class="drug-cell-meta">icode: ' + esc(row.icode) + '</div>' : '';
    const method = row.method ? '<div class="drug-cell-meta">' + esc(row.method) + '</div>' : '';
    const src = row.source_label ? '<div class="drug-cell-meta">' + esc(row.source_label) + '</div>' : '';
    return '<div class="drug-cell-title tmt-label">' + esc(name) + '</div>' + icode + method + src;
}

function renderSummary(summary) {
    if (!summary) return;
    const html = [
        '<span class="summary-pill status-matched me-1">ตรง ' + summary.matched + '</span>',
        '<span class="summary-pill status-changed me-1">เปลี่ยน ' + summary.changed + '</span>',
        '<span class="summary-pill status-baseline_only me-1">เดิมอย่างเดียว ' + summary.baseline_only + '</span>',
        '<span class="summary-pill status-current_only">ใหม่ ' + summary.current_only + '</span>'
    ].join('');
    $('#summaryPills').html(html);
}

function renderPairs() {
    const $body = $('#drTableBody').empty();
    if (!drPairs.length) {
        $('#drTable').addClass('d-none');
        $('#emptyState').removeClass('d-none').text('ไม่พบรายการยา');
        return;
    }
    $('#emptyState').addClass('d-none');
    $('#drTable').removeClass('d-none');

    drPairs.forEach(function(p, idx) {
        const act = p.recon_action || '';
        const $tr = $('<tr>').attr('data-idx', idx);
        $tr.append('<td>' + (idx + 1) + '</td>');
        $tr.append('<td>' + drugCell(p.baseline) + '</td>');
        $tr.append('<td>' + drugCell(p.current) + '</td>');
        $tr.append('<td><span class="badge status-' + p.status + '">' + esc(statusLabel[p.status] || p.status) + '</span></td>');

        const $actions = $('<td class="action-btn-group">');
        ['continue', 'hold', 'off'].forEach(function(a) {
            const labels = { continue: 'ต่อ', hold: 'Hold', off: 'Off' };
            const cls = act === a ? 'active-' + a : 'btn-outline-secondary';
            $actions.append(
                $('<button type="button" class="btn btn-sm me-1 ' + cls + '">')
                    .text(labels[a])
                    .on('click', function() {
                        drPairs[idx].recon_action = a;
                        renderPairs();
                    })
            );
        });
        $tr.append($actions);

        const $cmt = $('<input type="text" class="form-control form-control-sm">')
            .val(p.pharmacist_comment || '')
            .on('change input', function() {
                drPairs[idx].pharmacist_comment = $(this).val();
            });
        $tr.append($('<td>').append($cmt));
        $body.append($tr);
    });
}

function renderHistory(sessions) {
    const $list = $('#historyList').empty();
    if (!sessions || !sessions.length) {
        $('#historyList').addClass('d-none');
        $('#historyEmpty').removeClass('d-none');
        return;
    }
    $('#historyEmpty').addClass('d-none');
    $('#historyList').removeClass('d-none');
    sessions.forEach(function(s) {
        const badge = s.status === 'sent' ? 'success' : 'secondary';
        const when = s.sent_at || s.created_at || '';
        const $li = $('<li class="list-group-item list-group-item-action py-2">');
        $li.append('<div class="fw-bold small">#' + s.id + ' <span class="badge bg-' + badge + '">' + esc(s.status) + '</span></div>');
        $li.append('<div class="small text-muted">' + esc(when) + '</div>');
        $li.append('<div class="small">' + esc(s.pharmacist_name) + '</div>');
        $li.on('click', function() { loadSession(s.id); });
        $list.append($li);
    });
}

function applyLoadResult(data) {
    drContext = data.context;
    drPairs = (data.comparison && data.comparison.pairs) ? data.comparison.pairs : [];
    drSessionId = 0;
    $('#pharmacistNote').val('');

    $('#patientBar').removeClass('d-none');
    $('#ptName').text((drContext.patient_name || '—') + ' (HN ' + (drContext.hn || '') + ')');
    const meta = [
        drContext.visit_type === 'ipd' ? 'IPD AN: ' + (drContext.an || '—') : 'OPD VN: ' + (drContext.vn || '—'),
        'แพทย์: ' + (drContext.doctor_name || '—'),
        drContext.ward_name ? 'Ward: ' + drContext.ward_name : ''
    ].filter(Boolean).join(' | ');
    $('#ptMeta').text(meta);
    renderSummary(data.comparison.summary);
    renderPairs();
    renderHistory(data.sessions || []);
    $('#sessionInfo').text('');
}

function loadComparison() {
    const hn = $('#search_hn').val().trim();
    const an = $('#search_an').val().trim();
    const vn = $('#search_vn').val().trim();
    if (!hn && !an && !vn) {
        alert('กรุณาระบุ HN, AN หรือ VN');
        return;
    }
    $('#btnLoad').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> โหลด...');
    fetch('fetch_drug_reconciliation_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'load', hn: hn, an: an, vn: vn })
    })
    .then(r => r.json())
    .then(function(data) {
        if (data.status !== 'success') {
            alert(data.message || 'โหลดไม่สำเร็จ');
            return;
        }
        applyLoadResult(data);
    })
    .catch(function() { alert('เกิดข้อผิดพลาดในการเชื่อมต่อ'); })
    .finally(function() {
        $('#btnLoad').prop('disabled', false).html('<i class="fas fa-search me-1"></i> โหลดเปรียบเทียบ');
    });
}

function loadSession(sessionId) {
    fetch('fetch_drug_reconciliation_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'get_session', session_id: sessionId })
    })
    .then(r => r.json())
    .then(function(data) {
        if (data.status !== 'success') {
            alert(data.message || 'โหลดไม่สำเร็จ');
            return;
        }
        drContext = data.context;
        drPairs = data.pairs || [];
        drSessionId = parseInt(data.session.id, 10);
        $('#search_hn').val(drContext.hn || '');
        $('#search_an').val(drContext.an || '');
        $('#search_vn').val(drContext.vn || '');
        $('#pharmacistNote').val(data.session.pharmacist_note || '');
        $('#patientBar').removeClass('d-none');
        $('#ptName').text((drContext.patient_name || '—') + ' (HN ' + (drContext.hn || '') + ')');
        $('#ptMeta').text('โหลดรายการ #' + drSessionId + ' | สถานะ: ' + (data.session.status || ''));
        renderPairs();
        $('#sessionInfo').text('Session #' + drSessionId);
    });
}

function saveSession(sendToDoctor) {
    if (!drContext || !drPairs.length) {
        alert('ไม่มีข้อมูลให้บันทึก');
        return;
    }
    const action = sendToDoctor ? 'send' : 'save';
    const $btn = sendToDoctor ? $('#btnSendDoctor') : $('#btnSaveDraft');
    $btn.prop('disabled', true);
    fetch('fetch_drug_reconciliation_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: action,
            session_id: drSessionId,
            context: drContext,
            pairs: drPairs,
            pharmacist_note: $('#pharmacistNote').val()
        })
    })
    .then(r => r.json())
    .then(function(data) {
        if (data.status !== 'success') {
            alert(data.message || 'บันทึกไม่สำเร็จ');
            return;
        }
        drSessionId = data.session_id;
        $('#sessionInfo').text('Session #' + drSessionId + ' — ' + data.message);
        if (sendToDoctor && data.print_url) {
            window.open(data.print_url, '_blank');
        }
        fetch('fetch_drug_reconciliation_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'list', hn: drContext.hn, limit: 15 })
        })
        .then(r => r.json())
        .then(function(res) {
            if (res.status === 'success') renderHistory(res.sessions || []);
        });
    })
    .catch(function() { alert('เกิดข้อผิดพลาด'); })
    .finally(function() { $btn.prop('disabled', false); });
}

$('#btnLoad').on('click', loadComparison);
$('#btnClear').on('click', function() {
    $('#search_hn, #search_an, #search_vn, #pharmacistNote').val('');
    drContext = null; drPairs = []; drSessionId = 0;
    $('#patientBar').addClass('d-none');
    $('#drTable').addClass('d-none');
    $('#emptyState').removeClass('d-none').text('กรอก HN แล้วกดโหลดเปรียบเทียบ');
    $('#historyList').addClass('d-none');
    $('#historyEmpty').removeClass('d-none');
    $('#sessionInfo').text('');
});
$('#btnApplySuggest').on('click', function() {
    drPairs.forEach(function(p) {
        if (!p.recon_action && p.suggested_action) {
            p.recon_action = p.suggested_action;
        }
    });
    renderPairs();
});
$('#btnSaveDraft').on('click', function() { saveSession(false); });
$('#btnSendDoctor').on('click', function() {
    if (!confirm('ยืนยันส่งรายการยาให้แพทย์?')) return;
    saveSession(true);
});
$('#search_hn, #search_an, #search_vn').on('keypress', function(e) {
    if (e.which === 13) loadComparison();
});

if (drSessionId > 0) {
    loadSession(drSessionId);
} else if ($('#search_hn').val() || $('#search_an').val() || $('#search_vn').val()) {
    loadComparison();
}
</script>
</body>
</html>
