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
$default_date = date('Y-m-d');
$api_key_set = sp_screen_api_get_key($con) !== '';
$api_url = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''))
    . '/fetch_smart_pharma_screen_hn_api.php';
session_write_close();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>คัดกรองตาม HN - Smart Pharma</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
    <link href="css/flatpickr.min.css" rel="stylesheet">
    <style>
        :root { --sp-primary: #0d9488; --sp-dark: #0f766e; }
        .btn-sp { background: var(--sp-primary); border-color: var(--sp-primary); color: #fff; }
        .btn-sp:hover { background: var(--sp-dark); border-color: var(--sp-dark); color: #fff; }
        .hn-hero { background: linear-gradient(135deg, #b45309 0%, #0d9488 100%); color: #fff; border-radius: 12px; padding: 1.25rem 1.5rem; }
        .mod-card { border-radius: 8px; padding: 0.65rem 0.85rem; margin-bottom: 0.5rem; }
        .mod-pass { background: #ecfdf5; border: 1px solid #a7f3d0; }
        .mod-alert-soft { background: #fffbeb; border: 1px solid #fcd34d; }
        .mod-alert-hard { background: #fef2f2; border: 1px solid #fca5a5; }
        .mod-na { background: #f8fafc; border: 1px solid #e2e8f0; color: #64748b; }
    </style>
</head>
<body class="bg-light">
<div class="wrapper">
    <?php include('sidebar.php'); ?>
    <div id="content" class="content">
        <div class="container-fluid py-4">
            <div class="hn-hero mb-4">
                <div class="small opacity-75 mb-1"><i class="fas fa-user-shield me-1"></i>Smart Pharma</div>
                <h4 class="fw-bold mb-1"><i class="fas fa-id-card me-2"></i>คัดกรองตาม HN</h4>
                <small>คัดกรองรายคน · แจ้งเตือนทันที · เชื่อม HOSxP ตอนแพทย์ save ยาได้</small>
            </div>

            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <h6 class="fw-bold text-primary border-bottom pb-2 mb-3"><i class="fas fa-search me-2"></i>คัดกรอง</h6>
                            <div class="mb-2">
                                <label class="form-label small fw-bold">HN</label>
                                <input type="text" id="scr_hn" class="form-control form-control-lg font-monospace" placeholder="เช่น 12345" autofocus>
                            </div>
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <label class="form-label small fw-bold">ประเภท</label>
                                    <select id="scr_ptype" class="form-select form-select-sm" onchange="toggleVnAn()">
                                        <option value="OPD">ผู้ป่วยนอก (OPD)</option>
                                        <option value="IPD">ผู้ป่วยใน (IPD)</option>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-bold">วันที่</label>
                                    <input type="text" id="scr_date" class="form-control form-control-sm" value="<?= htmlspecialchars($default_date) ?>">
                                </div>
                            </div>
                            <div class="mb-2" id="scr_vn_wrap">
                                <label class="form-label small fw-bold">VN <span class="text-muted fw-normal">(ไม่บังคับ)</span></label>
                                <input type="text" id="scr_vn" class="form-control form-control-sm font-monospace" placeholder="ถ้าว่างใช้ visit ล่าสุด">
                            </div>
                            <div class="mb-2 d-none" id="scr_an_wrap">
                                <label class="form-label small fw-bold">AN <span class="text-muted fw-normal">(ไม่บังคับ)</span></label>
                                <input type="text" id="scr_an" class="form-control form-control-sm font-monospace">
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="scr_notify" checked>
                                <label class="form-check-label small" for="scr_notify">ส่งแจ้งเตือน Telegram / Webhook (ถ้าเปิดใช้)</label>
                            </div>
                            <button type="button" class="btn btn-sp fw-bold w-100" id="scrBtn" onclick="runScreenHn()">
                                <i class="fas fa-play me-1"></i>คัดกรองทันที
                            </button>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm mt-4">
                        <div class="card-body">
                            <h6 class="fw-bold text-secondary border-bottom pb-2 mb-3"><i class="fas fa-plug me-2"></i>เชื่อม HOSxP</h6>
                            <p class="small text-muted mb-2">ตั้ง API Key แล้วเรียก URL นี้ตอนแพทย์ save ยา — ระบบจะคัดกรองและแจ้งเตือนทันที</p>
                            <label class="form-label small fw-bold">API URL</label>
                            <input type="text" class="form-control form-control-sm font-monospace mb-2" readonly value="<?= htmlspecialchars($api_url) ?>" onclick="this.select()">
                            <label class="form-label small fw-bold">API Key</label>
                            <div class="input-group input-group-sm mb-2">
                                <input type="password" id="scr_api_key" class="form-control font-monospace" placeholder="<?= $api_key_set ? '•••••••• (ตั้งแล้ว)' : 'ตั้งรหัสลับ' ?>">
                                <button class="btn btn-outline-secondary" type="button" onclick="saveApiKey()">บันทึก</button>
                            </div>
                            <div class="small bg-light border rounded p-2 font-monospace" style="font-size:0.72rem;word-break:break-all;">
                                GET <?= htmlspecialchars($api_url) ?>?key=KEY&amp;hn=HN&amp;patient_type=OPD&amp;vn=VN&amp;screen_date=YYYY-MM-DD&amp;notify=1
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm min-vh-50">
                        <div class="card-body" id="scrResult">
                            <div class="text-center text-muted py-5">
                                <i class="fas fa-user-md fa-3x mb-3 opacity-25"></i>
                                <p class="mb-0">กรอก HN แล้วกดคัดกรอง</p>
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
<script src="js/flatpickr.min.js"></script>
<script>
function esc(s) { return !s ? '' : String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function toggleVnAn() {
    const ipd = $('#scr_ptype').val() === 'IPD';
    $('#scr_vn_wrap').toggleClass('d-none', ipd);
    $('#scr_an_wrap').toggleClass('d-none', !ipd);
}

function modClass(st, lvl) {
    if (st === 'alert') return lvl === 'hard' ? 'mod-alert-hard' : 'mod-alert-soft';
    if (st === 'pass') return 'mod-pass';
    return 'mod-na';
}

function renderResult(d) {
    if (d.status !== 'success' || !d.patient) {
        $('#scrResult').html('<div class="alert alert-warning mb-0">' + esc(d.message || 'คัดกรองไม่สำเร็จ') + '</div>');
        return;
    }
    const p = d.patient;
    const alertBadge = d.has_alert
        ? '<span class="badge bg-danger ms-2">Alert ' + (d.alert_count || 0) + '</span>'
        : '<span class="badge bg-success ms-2">ผ่าน</span>';
    let html = '<div class="border-bottom pb-2 mb-3">'
        + '<h5 class="fw-bold mb-1">' + esc(p.ptname) + alertBadge + '</h5>'
        + '<div class="small text-muted">HN <code>' + esc(p.hn) + '</code>'
        + (p.vn ? ' · VN <code>' + esc(p.vn) + '</code>' : '')
        + (p.an ? ' · AN <code>' + esc(p.an) + '</code>' : '')
        + ' · ' + esc(p.patient_type) + ' · ' + esc(p.screen_date || $('#scr_date').val())
        + '</div>';
    if (d.telegram && d.telegram.sent > 0) {
        html += '<div class="small text-info mt-1"><i class="fab fa-telegram-plane me-1"></i>ส่งแจ้งเตือนแล้ว ' + d.telegram.sent + ' รายการ</div>';
    }
    if (d.webhook && d.webhook.sent > 0) {
        html += '<div class="small text-primary"><i class="fas fa-plug me-1"></i>ส่ง Webhook แล้ว ' + d.webhook.sent + ' รายการ</div>';
    }
    html += '</div><h6 class="fw-bold small text-secondary mb-2">ผลแต่ละโมดูล</h6>';

    const mods = p.modules || {};
    const labels = {};
    (d.modules || []).forEach(m => { labels[m.key] = m.label; });

    Object.keys(mods).forEach(k => {
        const m = mods[k];
        const cls = modClass(m.status, m.level);
        const icon = m.status === 'alert' ? (m.level === 'hard' ? '🔴' : '🟡') : (m.status === 'pass' ? '✅' : '—');
        html += '<div class="mod-card ' + cls + '">'
            + '<div class="fw-bold small">' + icon + ' ' + esc(labels[k] || k)
            + (m.status === 'alert' ? ' <span class="badge ' + (m.level === 'hard' ? 'bg-danger' : 'bg-warning text-dark') + '">' + esc((m.level || '').toUpperCase()) + '</span>' : '')
            + '</div>';
        if (m.summary) html += '<div class="small mt-1">' + esc(m.summary) + '</div>';
        html += '</div>';
    });

    if (d.has_alert) {
        html += '<div class="mt-3"><a href="smart_pharma_interventions.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-notes-medical me-1"></i>เปิด Intervention Log</a></div>';
    }
    $('#scrResult').html(html);
}

function runScreenHn() {
    const hn = $('#scr_hn').val().trim();
    if (!hn) { alert('กรุณากรอก HN'); return; }
    const btn = $('#scrBtn');
    const old = btn.html();
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i>กำลังคัดกรอง...');
    $('#scrResult').html('<div class="text-center text-muted py-5"><i class="fas fa-spinner fa-spin fa-2x"></i><p class="mt-2">กำลังคัดกรองจาก HOSxP...</p></div>');

    fetch('fetch_smart_pharma_api.php?action=screen_hn', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            hn: hn,
            patient_type: $('#scr_ptype').val(),
            screen_date: $('#scr_date').val(),
            vn: $('#scr_vn').val().trim(),
            an: $('#scr_an').val().trim(),
            notify: $('#scr_notify').is(':checked'),
        }),
    }).then(r => r.json()).then(renderResult).catch(() => {
        $('#scrResult').html('<div class="alert alert-danger">เกิดข้อผิดพลาด</div>');
    }).finally(() => btn.prop('disabled', false).html(old));
}

function saveApiKey() {
    const key = $('#scr_api_key').val().trim();
    if (!key) { alert('กรุณากรอก API Key'); return; }
    fetch('fetch_smart_pharma_api.php?action=screen_api_save_key', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ api_key: key }),
    }).then(r => r.json()).then(d => alert(d.message || 'บันทึกแล้ว'));
}

$(function() {
    if (typeof flatpickr !== 'undefined') {
        flatpickr('#scr_date', { dateFormat: 'Y-m-d', defaultDate: '<?= $default_date ?>' });
    }
    toggleVnAn();
    $('#scr_hn').on('keypress', function(e) { if (e.which === 13) runScreenHn(); });
});
</script>
</body>
</html>
