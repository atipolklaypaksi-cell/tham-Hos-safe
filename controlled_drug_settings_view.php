<?php
/**
 * Shared settings view — ตั้ง $cdm_type = 'narcotic' | 'psychotropic' ก่อน include
 */
if (!isset($cdm_type) || cdm_normalize_type($cdm_type) === '') {
    die('Invalid drug type');
}
$cdm_type = cdm_normalize_type($cdm_type);
$cdm_label = cdm_type_label($cdm_type);
$cdm_stock_file = $cdm_type === 'narcotic' ? 'controlled_drug_narcotic.php' : 'controlled_drug_psychotropic.php';
$cdm_theme = $cdm_type === 'narcotic' ? '#7c2d12' : '#4c1d95';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตั้งค่ารายการ<?= htmlspecialchars($cdm_label) ?></title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
    <link href="css/select2.min.css" rel="stylesheet">
    <link href="css/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <style>
        :root { --cdm-primary: <?= $cdm_theme ?>; }
        .cdm-hero {
            background: linear-gradient(135deg, var(--cdm-primary) 0%, #1f4d3b 100%);
            color: #fff; border-radius: 12px; padding: 1.25rem 1.5rem;
        }
        #settingsTable thead th { background: var(--cdm-primary); color: #fff; font-size: .82rem; }
        #settingsTable { font-size: .86rem; }
        .btn-cdm { background: var(--cdm-primary); border-color: var(--cdm-primary); color: #fff; }
        .btn-cdm:hover { filter: brightness(1.08); color: #fff; }
    </style>
</head>
<body>
<div class="wrapper">
    <?php include('sidebar.php'); ?>
    <div id="content" class="content p-4">
        <div class="cdm-hero mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h4><i class="fas fa-cog me-2"></i>ตั้งค่ารายการ<?= htmlspecialchars($cdm_label) ?></h4>
                <small class="opacity-90">กำหนดรายการยาที่ใช้ตัดสต๊อกคงคลังจากรายงาน HOSxP</small>
            </div>
            <a href="<?= htmlspecialchars($cdm_stock_file) ?>" class="btn btn-light btn-sm fw-bold">
                <i class="fas fa-boxes me-1"></i>กลับหน้าสต๊อก
            </a>
        </div>

        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-white fw-bold"><i class="fas fa-plus-circle me-2"></i>เพิ่มรายการยา</div>
            <div class="card-body">
                <div class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label fw-bold small">ค้นหายาจาก HOSxP</label>
                        <select id="drugSelect" class="form-select" style="width:100%"></select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-bold small">ยอดยกมา</label>
                        <input type="number" step="0.01" id="openingQty" class="form-control" value="0">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold small">ห้องสต๊อก</label>
                        <select id="openingRoomId" class="form-select"></select>
                    </div>
                    <div class="col-md-3">
                        <button type="button" class="btn btn-cdm w-100" id="btnAddDrug">
                            <i class="fas fa-save me-1"></i>เพิ่มรายการ
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="settingsTable">
                        <thead>
                            <tr>
                                <th>icode</th>
                                <th>ชื่อยา</th>
                                <th class="text-end">ยอดยกมา</th>
                                <th class="text-end">คงคลัง</th>
                                <th>หน่วย</th>
                                <th>ใช้งาน</th>
                                <th class="text-center">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody id="settingsBody">
                            <tr><td colspan="7" class="text-center text-muted py-4">กำลังโหลด...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="js/jquery-3.7.0.min.js"></script>
<script src="js/bootstrap.bundle.min.js"></script>
<script src="js/select2.min.js"></script>
<script>
const CDM_TYPE = <?= json_encode($cdm_type) ?>;

function esc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function fmtQty(n) {
    n = Number(n || 0);
    return Number.isInteger(n) ? n.toLocaleString() : n.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function apiPost(action, data) {
    return fetch('fetch_controlled_drug_api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(Object.assign({action: action, drug_type: CDM_TYPE}, data || {}))
    }).then(function(r) {
        return r.text().then(function(text) {
            try {
                return JSON.parse(text);
            } catch (e) {
                throw new Error(text ? text.substring(0, 200) : 'เซิร์ฟเวอร์ไม่ส่ง JSON');
            }
        });
    });
}

function loadOpeningRooms() {
    return apiPost('rooms_list').then(function(res) {
        const sel = document.getElementById('openingRoomId');
        const rooms = (res.rooms || []).filter(function(r) { return Number(r.is_active) === 1; });
        sel.innerHTML = rooms.map(function(r) {
            return '<option value="' + r.id + '">' + esc(r.room_name) + '</option>';
        }).join('');
        const def = rooms.find(function(r) { return Number(r.is_default) === 1; });
        if (def) sel.value = String(def.id);
    });
}

function loadSettings() {
    return apiPost('settings_list').then(function(res) {
        if (res.status !== 'success') throw new Error(res.message || 'โหลดไม่สำเร็จ');
        const body = document.getElementById('settingsBody');
        const rows = res.items || [];
        if (!rows.length) {
            body.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">ยังไม่มีรายการยา — ค้นหาและเพิ่มจาก HOSxP ด้านบน</td></tr>';
            return;
        }
        body.innerHTML = rows.map(function(r) {
            const active = Number(r.is_active) === 1;
            return '<tr class="' + (active ? '' : 'table-secondary') + '">' +
                '<td><code>' + esc(r.icode) + '</code></td>' +
                '<td>' + esc(r.drug_name) + '</td>' +
                '<td class="text-end">' + fmtQty(r.opening_qty) + '</td>' +
                '<td class="text-end fw-bold">' + fmtQty(r.current_qty) + '</td>' +
                '<td>' + esc(r.unit) + '</td>' +
                '<td>' + (active ? '<span class="text-success">เปิด</span>' : '<span class="text-muted">ปิด</span>') + '</td>' +
                '<td class="text-center">' +
                    (active ? '<button class="btn btn-outline-danger btn-sm py-0 px-2 btn-del" data-id="' + r.id + '">ปิดใช้</button>' : '') +
                '</td>' +
            '</tr>';
        }).join('');
    });
}

function initDrugSelect() {
    if (typeof jQuery === 'undefined' || typeof jQuery.fn.select2 !== 'function') {
        return;
    }
    jQuery('#drugSelect').select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: 'พิมพ์ชื่อยาหรือ icode เพื่อค้นหา...',
        allowClear: true,
        minimumInputLength: 1,
        ajax: {
            url: 'fetch_hosxp_drugs_select_api.php',
            dataType: 'json',
            delay: 250,
            data: function(params) { return { q: params.term }; },
            processResults: function(data) { return { results: data.results || [] }; }
        }
    });
}

document.getElementById('btnAddDrug').addEventListener('click', function() {
    const sel = (typeof jQuery !== 'undefined') ? jQuery('#drugSelect').select2('data')[0] : null;
    if (!sel || !sel.id) {
        alert('กรุณาเลือกยาจาก HOSxP');
        return;
    }
    const parts = String(sel.id).split('|');
    const icode = parts[0] || '';
    const drugName = sel.text || '';
    const btn = this;
    btn.disabled = true;
    apiPost('save_item', {
        icode: icode,
        drug_name: drugName,
        opening_qty: document.getElementById('openingQty').value,
        room_id: Number(document.getElementById('openingRoomId').value || 0),
        is_active: 1
    }).then(function(res) {
        btn.disabled = false;
        if (res.status !== 'success') { alert(res.message || 'เพิ่มไม่สำเร็จ'); return; }
        if (typeof jQuery !== 'undefined') jQuery('#drugSelect').val(null).trigger('change');
        document.getElementById('openingQty').value = '0';
        loadSettings();
    }).catch(function(err) { btn.disabled = false; alert(err.message); });
});

document.getElementById('settingsBody').addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-del');
    if (!btn) return;
    if (!confirm('ปิดใช้งานรายการยานี้?')) return;
    apiPost('delete_item', { id: Number(btn.dataset.id) }).then(function(res) {
        if (res.status !== 'success') { alert(res.message || 'ลบไม่สำเร็จ'); return; }
        loadSettings();
    });
});

loadOpeningRooms()
    .then(function() { return loadSettings(); })
    .then(function() { initDrugSelect(); })
    .catch(function(err) {
        document.getElementById('settingsBody').innerHTML =
            '<tr><td colspan="7" class="text-center text-danger py-4">' + esc(err.message || 'โหลดไม่สำเร็จ') + '</td></tr>';
    });
</script>
</body>
</html>
