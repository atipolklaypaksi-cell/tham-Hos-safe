<?php
/**
 * จัดการห้องสต๊อกรวม — ตั้ง $cdm_can_narcotic, $cdm_can_psychotropic, $cdm_default_type ก่อน include
 */
$cdm_can_narcotic = !empty($cdm_can_narcotic);
$cdm_can_psychotropic = !empty($cdm_can_psychotropic);
$cdm_default_type = cdm_normalize_type($cdm_default_type ?? 'narcotic');
if ($cdm_default_type === '' || ($cdm_default_type === 'narcotic' && !$cdm_can_narcotic) || ($cdm_default_type === 'psychotropic' && !$cdm_can_psychotropic)) {
    $cdm_default_type = $cdm_can_narcotic ? 'narcotic' : 'psychotropic';
}
$cdm_type_labels = [
    'narcotic' => cdm_type_label('narcotic'),
    'psychotropic' => cdm_type_label('psychotropic'),
];
$cdm_dispense_files = [
    'narcotic' => 'controlled_drug_narcotic.php',
    'psychotropic' => 'controlled_drug_psychotropic.php',
];
$cdm_themes = [
    'narcotic' => ['primary' => '#7c2d12', 'light' => '#fff7ed'],
    'psychotropic' => ['primary' => '#4c1d95', 'light' => '#f5f3ff'],
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการห้องสต๊อก — ยาเสพติด/วัตถุออกฤทธิ์</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
    <style>
        :root { --cdm-primary: #7c2d12; --cdm-light: #fff7ed; }
        .cdm-hero {
            background: linear-gradient(135deg, var(--cdm-primary) 0%, #1f4d3b 100%);
            color: #fff; border-radius: 12px; padding: 1.25rem 1.5rem;
        }
        .cdm-hero h4 { font-weight: 800; margin: 0; }
        .filter-box { background: var(--cdm-light); border: 1px solid rgba(0,0,0,.06); border-radius: 10px; }
        .btn-cdm { background: var(--cdm-primary); border-color: var(--cdm-primary); color: #fff; }
        .btn-cdm:hover { filter: brightness(1.08); color: #fff; }
        #roomsTable thead th { background: var(--cdm-primary); color: #fff; font-size: .85rem; }
        #roomsTable { font-size: .9rem; }
        .room-form-card { border: 1px solid rgba(0,0,0,.08); border-radius: 10px; background: #fff; }
        .cdm-type-tabs .nav-link { font-weight: 700; color: #495057; }
        .cdm-type-tabs .nav-link.active { color: var(--cdm-primary); border-color: #dee2e6 #dee2e6 #fff; }
    </style>
</head>
<body>
<div class="wrapper">
    <?php include('sidebar.php'); ?>
    <div id="content" class="content p-4">
        <div class="cdm-hero mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h4><i class="fas fa-door-open me-2"></i>จัดการห้องสต๊อก</h4>
                <small class="opacity-90">กำหนดห้อง/คลังสำหรับตัดสต๊อกและจ่ายยา — <span id="heroTypeLabel"><?= htmlspecialchars($cdm_type_labels[$cdm_default_type]) ?></span></small>
            </div>
            <a href="<?= htmlspecialchars($cdm_dispense_files[$cdm_default_type]) ?>" class="btn btn-light btn-sm fw-bold" id="btnBackDispense">
                <i class="fas fa-arrow-left me-1"></i>กลับหน้าจ่ายยา
            </a>
        </div>

        <?php if ($cdm_can_narcotic && $cdm_can_psychotropic): ?>
        <ul class="nav nav-tabs cdm-type-tabs mb-3" id="cdmTypeTabs">
            <li class="nav-item">
                <button type="button" class="nav-link<?= $cdm_default_type === 'narcotic' ? ' active' : '' ?>" data-type="narcotic">
                    <i class="fas fa-pills me-1"></i><?= htmlspecialchars($cdm_type_labels['narcotic']) ?>
                </button>
            </li>
            <li class="nav-item">
                <button type="button" class="nav-link<?= $cdm_default_type === 'psychotropic' ? ' active' : '' ?>" data-type="psychotropic">
                    <i class="fas fa-capsules me-1"></i><?= htmlspecialchars($cdm_type_labels['psychotropic']) ?>
                </button>
            </li>
        </ul>
        <?php endif; ?>

        <div class="alert alert-light border small py-2 mb-3">
            <i class="fas fa-info-circle me-1 text-primary"></i>
            <strong>ห้องแยกตามประเภทยา</strong> — แต่ละประเภทยามีรายการห้องของตัวเอง
            เมื่อเพิ่มห้องใหม่ ระบบจะสร้างให้อีกประเภทอัตโนมัติ (ยกเว้นห้องหลัก)
            <?php if ($cdm_can_narcotic && $cdm_can_psychotropic): ?>
            <button type="button" class="btn btn-link btn-sm p-0 ms-1 align-baseline d-none" id="btnCopyRoomsFromOther"></button>
            <?php endif; ?>
        </div>

        <div id="roomsAlert" class="alert d-none mb-3"></div>

        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="roomsTable">
                        <thead>
                            <tr>
                                <th>รหัส</th>
                                <th>ชื่อห้อง</th>
                                <th>ผูก OPD/IPD</th>
                                <th class="text-center">ลำดับ</th>
                                <th class="text-center">สถานะ</th>
                                <th class="text-center" style="width:160px">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody id="roomsListBody">
                            <tr><td colspan="6" class="text-center text-muted py-4">กำลังโหลด...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0 room-form-card">
            <div class="card-header bg-white fw-bold">
                <i class="fas fa-plus-circle me-1"></i><span id="roomFormTitle">เพิ่มห้องใหม่</span>
            </div>
            <div class="card-body filter-box">
                <input type="hidden" id="roomEditId" value="0">
                <div class="row g-2 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label small fw-bold mb-1">รหัสห้อง</label>
                        <input type="text" id="roomCode" class="form-control form-control-sm" placeholder="เช่น ipd, opd">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold mb-1">ชื่อห้อง</label>
                        <input type="text" id="roomName" class="form-control form-control-sm" placeholder="ห้องจ่ายยาผู้ป่วยใน">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold mb-1">ผูกประเภทผู้ป่วย</label>
                        <select id="roomServiceMode" class="form-select form-select-sm">
                            <option value="all">ทั้งหมด</option>
                            <option value="ipd">IPD</option>
                            <option value="opd">OPD</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold mb-1">ลำดับ</label>
                        <input type="number" id="roomSort" class="form-control form-control-sm" value="50">
                    </div>
                    <div class="col-md-3 text-md-end">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnClearRoomForm">
                            <i class="fas fa-eraser me-1"></i>ล้างฟอร์ม
                        </button>
                        <button type="button" class="btn btn-cdm btn-sm" id="btnSaveRoom">
                            <i class="fas fa-save me-1"></i>บันทึกห้อง
                        </button>
                    </div>
                </div>
                <div class="small text-muted mt-2">
                    <i class="fas fa-info-circle me-1"></i>ลบได้เฉพาะห้องที่ยังไม่มีการใช้งาน (ไม่มียอดสต๊อก Lot การเคลื่อนไหว หรือบันทึกจ่ายยา) และไม่ใช่ห้องคลังหลัก
                </div>
            </div>
        </div>
    </div>
</div>

<script src="js/jquery-3.7.0.min.js"></script>
<script src="js/bootstrap.bundle.min.js"></script>
<script>
const CDM_CAN_NARCOTIC = <?= $cdm_can_narcotic ? 'true' : 'false' ?>;
const CDM_CAN_PSYCHOTROPIC = <?= $cdm_can_psychotropic ? 'true' : 'false' ?>;
const CDM_TYPE_LABELS = <?= json_encode($cdm_type_labels, JSON_UNESCAPED_UNICODE) ?>;
const CDM_DISPENSE_FILES = <?= json_encode($cdm_dispense_files, JSON_UNESCAPED_UNICODE) ?>;
const CDM_THEMES = <?= json_encode($cdm_themes, JSON_UNESCAPED_UNICODE) ?>;
let activeDrugType = <?= json_encode($cdm_default_type) ?>;

function applyDrugTypeTheme(type) {
    const theme = CDM_THEMES[type] || CDM_THEMES.narcotic;
    document.documentElement.style.setProperty('--cdm-primary', theme.primary);
    document.documentElement.style.setProperty('--cdm-light', theme.light);
}

function apiPost(action, data) {
    return fetch('fetch_controlled_drug_api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(Object.assign({action: action, drug_type: activeDrugType}, data || {}))
    }).then(function(r) {
        return r.text().then(function(t) {
            try { return JSON.parse(t); } catch (e) { throw new Error(t || 'Invalid JSON'); }
        });
    });
}

function esc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function showRoomsAlert(type, msg) {
    const el = document.getElementById('roomsAlert');
    el.className = 'alert alert-' + type + ' mb-3';
    el.textContent = msg;
    el.classList.remove('d-none');
}

function clearRoomForm() {
    document.getElementById('roomEditId').value = '0';
    document.getElementById('roomCode').value = '';
    document.getElementById('roomName').value = '';
    document.getElementById('roomServiceMode').value = 'all';
    document.getElementById('roomSort').value = '50';
    document.getElementById('roomFormTitle').textContent = 'เพิ่มห้องใหม่ — ' + (CDM_TYPE_LABELS[activeDrugType] || '');
}

function fillRoomForm(room) {
    document.getElementById('roomEditId').value = room.id;
    document.getElementById('roomCode').value = room.room_code || '';
    document.getElementById('roomName').value = room.room_name || '';
    document.getElementById('roomServiceMode').value = room.service_mode || 'all';
    document.getElementById('roomSort').value = room.sort_order || 0;
    document.getElementById('roomFormTitle').textContent = 'แก้ไขห้อง: ' + (room.room_name || '');
}

function renderRoomsList(rooms) {
    const body = document.getElementById('roomsListBody');
    if (!rooms.length) {
        body.innerHTML = '<tr><td colspan="6" class="text-muted text-center py-4">ยังไม่มีห้อง — เพิ่มห้องด้านล่าง</td></tr>';
        return;
    }
    body.innerHTML = rooms.map(function(r) {
        const isDefault = Number(r.is_default) === 1;
        const canDelete = Number(r.can_delete) === 1;
        const deleteBtn = canDelete
            ? '<button type="button" class="btn btn-outline-danger btn-sm btn-room-delete" data-id="' + r.id + '" title="ลบห้อง"><i class="fas fa-trash-alt"></i></button>'
            : '<button type="button" class="btn btn-outline-secondary btn-sm" disabled title="' + (isDefault ? 'ห้องคลังหลักลบไม่ได้' : 'ห้องนี้มีการใช้งานแล้ว') + '"><i class="fas fa-trash-alt"></i></button>';
        return '<tr>' +
            '<td><code>' + esc(r.room_code) + '</code>' + (isDefault ? ' <span class="badge bg-secondary">หลัก</span>' : '') + '</td>' +
            '<td>' + esc(r.room_name) + '</td>' +
            '<td class="small">' + esc(r.service_mode_label || r.service_mode) + '</td>' +
            '<td class="text-center">' + esc(r.sort_order) + '</td>' +
            '<td class="text-center">' + (Number(r.is_active) === 1 ? '<span class="text-success">เปิด</span>' : '<span class="text-muted">ปิด</span>') + '</td>' +
            '<td class="text-center text-nowrap">' +
                '<button type="button" class="btn btn-outline-primary btn-sm btn-room-edit" data-id="' + r.id + '"><i class="fas fa-edit"></i></button> ' +
                deleteBtn +
            '</td>' +
        '</tr>';
    }).join('');
}

function loadRoomsAdmin() {
    return apiPost('rooms_list').then(function(res) {
        if (res.status !== 'success') throw new Error(res.message || 'โหลดห้องไม่สำเร็จ');
        renderRoomsList(res.rooms || []);
        return res.rooms || [];
    });
}

function switchDrugType(type) {
    if (type === 'narcotic' && !CDM_CAN_NARCOTIC) return;
    if (type === 'psychotropic' && !CDM_CAN_PSYCHOTROPIC) return;
    activeDrugType = type;
    sessionStorage.setItem('cdm_rooms_admin_type', type);
    applyDrugTypeTheme(type);
    document.getElementById('heroTypeLabel').textContent = CDM_TYPE_LABELS[type] || type;
    document.getElementById('btnBackDispense').href = CDM_DISPENSE_FILES[type] || '#';
    document.querySelectorAll('#cdmTypeTabs .nav-link').forEach(function(btn) {
        btn.classList.toggle('active', btn.dataset.type === type);
    });
    updateCopyRoomsButton();
    clearRoomForm();
    document.getElementById('roomsAlert').classList.add('d-none');
    loadRoomsAdmin().catch(function(err) {
        showRoomsAlert('danger', err.message || 'โหลดห้องไม่สำเร็จ');
    });
}

function updateCopyRoomsButton() {
    const btn = document.getElementById('btnCopyRoomsFromOther');
    if (!btn || !CDM_CAN_NARCOTIC || !CDM_CAN_PSYCHOTROPIC) return;
    const source = activeDrugType === 'narcotic' ? 'psychotropic' : 'narcotic';
    btn.classList.remove('d-none');
    btn.textContent = 'คัดลอกห้องจาก' + (CDM_TYPE_LABELS[source] || source);
    btn.dataset.source = source;
}

document.getElementById('btnClearRoomForm').addEventListener('click', clearRoomForm);

document.getElementById('roomsListBody').addEventListener('click', function(e) {
    const editBtn = e.target.closest('.btn-room-edit');
    const deleteBtn = e.target.closest('.btn-room-delete');
    if (editBtn) {
        apiPost('rooms_list').then(function(res) {
            const room = (res.rooms || []).find(function(r) { return Number(r.id) === Number(editBtn.dataset.id); });
            if (room) fillRoomForm(room);
        });
        return;
    }
    if (deleteBtn) {
        const roomId = Number(deleteBtn.dataset.id || 0);
        if (!roomId) return;
        if (!confirm('ยืนยันลบห้องนี้?')) return;
        deleteBtn.disabled = true;
        apiPost('room_delete', { id: roomId }).then(function(res) {
            deleteBtn.disabled = false;
            if (res.status !== 'success') {
                alert(res.message || 'ลบไม่สำเร็จ');
                return;
            }
            showRoomsAlert('success', res.message || 'ลบห้องแล้ว');
            if (Number(document.getElementById('roomEditId').value) === roomId) clearRoomForm();
            loadRoomsAdmin();
        }).catch(function(err) {
            deleteBtn.disabled = false;
            alert(err.message);
        });
    }
});

document.getElementById('btnSaveRoom').addEventListener('click', function() {
    const btn = this;
    btn.disabled = true;
    apiPost('room_save', {
        id: Number(document.getElementById('roomEditId').value || 0),
        room_code: document.getElementById('roomCode').value,
        room_name: document.getElementById('roomName').value,
        service_mode: document.getElementById('roomServiceMode').value,
        sort_order: document.getElementById('roomSort').value,
        is_active: 1
    }).then(function(res) {
        btn.disabled = false;
        if (res.status !== 'success') {
            alert(res.message || 'บันทึกไม่สำเร็จ');
            return;
        }
        showRoomsAlert('success', res.message || 'บันทึกห้องแล้ว');
        clearRoomForm();
        loadRoomsAdmin();
    }).catch(function(err) {
        btn.disabled = false;
        alert(err.message);
    });
});

const tabsEl = document.getElementById('cdmTypeTabs');
if (tabsEl) {
    tabsEl.addEventListener('click', function(e) {
        const btn = e.target.closest('[data-type]');
        if (!btn) return;
        switchDrugType(btn.dataset.type);
    });
}

const copyBtn = document.getElementById('btnCopyRoomsFromOther');
if (copyBtn) {
    copyBtn.addEventListener('click', function() {
        const source = copyBtn.dataset.source;
        if (!source) return;
        if (!confirm('คัดลอกห้องจาก' + (CDM_TYPE_LABELS[source] || source) + ' มายัง' + (CDM_TYPE_LABELS[activeDrugType] || activeDrugType) + '?')) return;
        copyBtn.disabled = true;
        apiPost('room_copy_from', { source_type: source }).then(function(res) {
            copyBtn.disabled = false;
            if (res.status !== 'success') {
                alert(res.message || 'คัดลอกไม่สำเร็จ');
                return;
            }
            showRoomsAlert('success', res.message || 'คัดลอกห้องแล้ว');
            loadRoomsAdmin();
        }).catch(function(err) {
            copyBtn.disabled = false;
            alert(err.message);
        });
    });
}

const savedType = sessionStorage.getItem('cdm_rooms_admin_type');
if (savedType && ((savedType === 'narcotic' && CDM_CAN_NARCOTIC) || (savedType === 'psychotropic' && CDM_CAN_PSYCHOTROPIC))) {
    activeDrugType = savedType;
}
applyDrugTypeTheme(activeDrugType);
document.getElementById('heroTypeLabel').textContent = CDM_TYPE_LABELS[activeDrugType] || activeDrugType;
document.getElementById('btnBackDispense').href = CDM_DISPENSE_FILES[activeDrugType] || '#';
if (tabsEl) {
    document.querySelectorAll('#cdmTypeTabs .nav-link').forEach(function(btn) {
        btn.classList.toggle('active', btn.dataset.type === activeDrugType);
    });
}
updateCopyRoomsButton();
clearRoomForm();
loadRoomsAdmin().catch(function(err) {
    showRoomsAlert('danger', err.message || 'โหลดห้องไม่สำเร็จ');
});
</script>
</body>
</html>
