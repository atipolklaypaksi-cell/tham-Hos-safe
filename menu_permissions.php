<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

include('connect.php');
mysqli_set_charset($con, 'utf8');
require_once __DIR__ . '/menu_permissions_helper.php';
if (empty($_SESSION['menu_permissions_loaded'])) {
    tham_menu_load_session_permissions($con, (int)$_SESSION['user_id'], $_SESSION['role'] ?? '');
}
if (!tham_menu_can('admin.menu_permissions')) {
    echo "<script>alert('คุณไม่มีสิทธิ์เข้าหน้าตั้งค่าเมนู'); window.location='home.php';</script>";
    exit;
}

tham_menu_ensure_tables($con);

$tab = ($_GET['tab'] ?? 'role') === 'user' ? 'user' : 'role';
$preselectUser = (int)($_GET['user_id'] ?? 0);

$users = [];
$uq = mysqli_query($con, "SELECT id, username, full_name, role, use_custom_menus FROM users ORDER BY id ASC");
while ($uq && ($row = mysqli_fetch_assoc($uq))) {
    $users[] = $row;
}
$roleLabels = tham_menu_role_labels();
$menuGroupsPhp = tham_menu_registry_groups();
$allMenuKeysPhp = tham_menu_all_keys();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>สิทธิ์เข้าถึงเมนู - THAM SAFE</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
    <style>
        .menu-group-card { border-left: 4px solid #1f4d3b; }
        .menu-group-card .form-check { margin-bottom: 0.35rem; }
        .menu-group-card .group-check { font-weight: 600; color: #1f4d3b; }
        .perm-hint { font-size: 0.875rem; }
    </style>
</head>
<body>
<div class="wrapper">
    <?php include('sidebar.php'); ?>
    <div id="content" class="content">
        <div class="container-fluid">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-primary text-white py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0 fw-bold"><i class="fas fa-key me-2"></i>ตั้งค่าสิทธิ์เข้าถึงเมนู</h5>
                    <a href="users.php" class="btn btn-light btn-sm fw-bold text-primary"><i class="fas fa-users-cog me-1"></i>จัดการผู้ใช้งาน</a>
                </div>
                <div class="card-body p-4">
                    <p class="perm-hint text-muted mb-3">
                        กำหนดได้ทั้ง <strong>กลุ่มตาม Role</strong> และ <strong>รายคน</strong> — ถ้ายังไม่ได้ตั้งค่า ระบบจะให้เข้าถึงเมนูทั้งหมดเหมือนเดิม (ยกเว้น Admin ได้ทุกเมนูเสมอ)
                    </p>

                    <ul class="nav nav-tabs mb-4" role="tablist">
                        <li class="nav-item">
                            <button class="nav-link <?= $tab === 'role' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tabRole" type="button">ตามกลุ่ม (Role)</button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link <?= $tab === 'user' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tabUser" type="button">รายคน</button>
                        </li>
                    </ul>

                    <div class="tab-content">
                        <div class="tab-pane fade <?= $tab === 'role' ? 'show active' : '' ?>" id="tabRole">
                            <div class="row g-3 mb-3">
                                <div class="col-md-5">
                                    <label class="form-label fw-bold">เลือกกลุ่มสิทธิ์ (Role)</label>
                                    <select id="roleSelect" class="form-select">
                                        <?php foreach ($roleLabels as $rk => $rl): ?>
                                            <?php if ($rk === 'admin') continue; ?>
                                            <option value="<?= htmlspecialchars($rk, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($rl, ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-7 d-flex align-items-end">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="roleUseCustom">
                                        <label class="form-check-label fw-bold" for="roleUseCustom">กำหนดเมนูเฉพาะกลุ่มนี้ (ปิด = ใช้ค่าเริ่มต้นทุกเมนู)</label>
                                    </div>
                                </div>
                            </div>
                            <div id="roleMenuPanel" class="opacity-50 pe-none"></div>
                            <div class="text-end mt-3">
                                <button type="button" class="btn btn-primary fw-bold" id="btnSaveRole"><i class="fas fa-save me-1"></i>บันทึกสิทธิ์กลุ่ม</button>
                            </div>
                        </div>

                        <div class="tab-pane fade <?= $tab === 'user' ? 'show active' : '' ?>" id="tabUser">
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">เลือกผู้ใช้งาน</label>
                                    <select id="userSelect" class="form-select">
                                        <?php foreach ($users as $u): ?>
                                            <option value="<?= (int)$u['id'] ?>" data-role="<?= htmlspecialchars($u['role'], ENT_QUOTES, 'UTF-8') ?>"
                                                <?= $preselectUser === (int)$u['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($u['full_name'] . ' (' . $u['username'] . ')', ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 d-flex align-items-end">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="userUseCustom">
                                        <label class="form-check-label fw-bold" for="userUseCustom">ใช้สิทธิ์เฉพาะรายคน (ปิด = ตาม Role ของผู้ใช้)</label>
                                    </div>
                                </div>
                            </div>
                            <div id="userInfo" class="alert alert-info py-2 perm-hint"></div>
                            <div id="userMenuPanel" class="opacity-50 pe-none"></div>
                            <div class="text-end mt-3">
                                <button type="button" class="btn btn-primary fw-bold" id="btnSaveUser"><i class="fas fa-save me-1"></i>บันทึกสิทธิ์รายคน</button>
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
<script>
let menuGroups = <?= json_encode($menuGroupsPhp, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
let allKeys = <?= json_encode($allMenuKeysPhp, JSON_UNESCAPED_UNICODE) ?>;
const roleLabels = <?= json_encode($roleLabels, JSON_UNESCAPED_UNICODE) ?>;

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;');
}

function safeDomId(s) {
    return String(s).replace(/[^a-zA-Z0-9_-]/g, '_');
}

function parseApiJson(r) {
    return r.text().then(function(text) {
        try {
            return JSON.parse(text);
        } catch (e) {
            throw new Error(text ? text.substring(0, 200) : 'โหลดข้อมูลไม่สำเร็จ');
        }
    });
}

function buildMenuPanel(containerId, selectedKeys) {
    const set = new Set(selectedKeys || []);
    if (!menuGroups.length) {
        document.getElementById(containerId).innerHTML =
            '<div class="alert alert-warning mb-0">ไม่พบรายการเมนู กรุณารีเฟรชหน้าใหม่</div>';
        return;
    }
    let html = '<div class="row g-3">';
    menuGroups.forEach(function(group, gi) {
        const childKeys = group.children.map(function(c) { return c.key; });
        const allChecked = childKeys.every(function(k) { return set.has(k); });
        const groupDomId = 'g_' + gi + '_' + safeDomId(group.key);
        html += '<div class="col-lg-6"><div class="card menu-group-card h-100"><div class="card-body">';
        html += '<div class="form-check group-check mb-2">';
        html += '<input class="form-check-input group-toggle" type="checkbox" data-group-idx="' + gi + '" id="' + groupDomId + '"' + (allChecked ? ' checked' : '') + '>';
        html += '<label class="form-check-label" for="' + groupDomId + '">' + esc(group.label) + '</label></div>';
        group.children.forEach(function(child, ci) {
            const cid = 'm_' + gi + '_' + ci + '_' + safeDomId(child.key);
            html += '<div class="form-check ms-3">';
            html += '<input class="form-check-input menu-item" type="checkbox" data-group-idx="' + gi + '" data-key="' + esc(child.key) + '" value="' + esc(child.key) + '" id="' + cid + '"' + (set.has(child.key) ? ' checked' : '') + '>';
            html += '<label class="form-check-label" for="' + cid + '">' + esc(child.label) + '</label></div>';
        });
        html += '</div></div></div>';
    });
    html += '</div>';
    const el = document.getElementById(containerId);
    el.innerHTML = html;

    el.querySelectorAll('.group-toggle').forEach(function(cb) {
        cb.addEventListener('change', function() {
            const gi = this.getAttribute('data-group-idx');
            el.querySelectorAll('.menu-item[data-group-idx="' + gi + '"]').forEach(function(item) {
                item.checked = cb.checked;
            });
        });
    });
    el.querySelectorAll('.menu-item').forEach(function(cb) {
        cb.addEventListener('change', function() {
            const gi = this.getAttribute('data-group-idx');
            const items = el.querySelectorAll('.menu-item[data-group-idx="' + gi + '"]');
            const gt = el.querySelector('.group-toggle[data-group-idx="' + gi + '"]');
            if (gt) gt.checked = Array.from(items).every(function(i) { return i.checked; });
        });
    });
}

function collectKeys(containerId) {
    const keys = [];
    document.querySelectorAll('#' + containerId + ' .menu-item:checked').forEach(function(cb) {
        const k = cb.getAttribute('data-key');
        if (k) keys.push(k);
    });
    return keys;
}

function setPanelEnabled(containerId, enabled) {
    const el = document.getElementById(containerId);
    el.classList.toggle('opacity-50', !enabled);
    el.classList.toggle('pe-none', !enabled);
}

function loadRegistry() {
    return Promise.resolve();
}

function initMenuPanels() {
    buildMenuPanel('roleMenuPanel', allKeys);
    setPanelEnabled('roleMenuPanel', false);
    buildMenuPanel('userMenuPanel', allKeys);
    setPanelEnabled('userMenuPanel', false);
}

function loadRolePerms() {
    const role = document.getElementById('roleSelect').value;
    return fetch('fetch_menu_permissions_api.php?action=get_role&role=' + encodeURIComponent(role))
        .then(parseApiJson)
        .then(function(data) {
            if (data.status !== 'success') { alert(data.msg); return; }
            document.getElementById('roleUseCustom').checked = !!data.use_custom;
            const keys = data.use_custom ? (data.menu_keys || []) : (allKeys.length ? allKeys : (data.menu_keys || []));
            buildMenuPanel('roleMenuPanel', keys);
            setPanelEnabled('roleMenuPanel', !!data.use_custom);
        })
        .catch(function(err) { alert('โหลดสิทธิ์กลุ่มไม่สำเร็จ: ' + err.message); });
}

function loadUserPerms() {
    const userId = document.getElementById('userSelect').value;
    const opt = document.getElementById('userSelect').selectedOptions[0];
    const role = opt ? opt.dataset.role : '';
    return fetch('fetch_menu_permissions_api.php?action=get_user&user_id=' + encodeURIComponent(userId))
        .then(parseApiJson)
        .then(function(data) {
            if (data.status !== 'success') { alert(data.msg); return; }
            const isAdmin = data.user.role === 'admin';
            document.getElementById('userUseCustom').checked = !!data.use_custom;
            document.getElementById('userUseCustom').disabled = isAdmin;
            document.getElementById('btnSaveUser').disabled = isAdmin;
            let info = `Role: <strong>${esc(roleLabels[data.user.role] || data.user.role)}</strong>`;
            if (isAdmin) info += ' — ผู้ดูแลระบบเข้าถึงทุกเมนู';
            else if (!data.use_custom) info += ' — ใช้สิทธิ์ตามกลุ่ม Role';
            else info += ' — ใช้สิทธิ์เฉพาะรายคน';
            document.getElementById('userInfo').innerHTML = info;
            buildMenuPanel('userMenuPanel', data.menu_keys);
            setPanelEnabled('userMenuPanel', !isAdmin && !!data.use_custom);
        })
        .catch(function(err) { alert('โหลดสิทธิ์รายคนไม่สำเร็จ: ' + err.message); });
}

document.getElementById('roleUseCustom').addEventListener('change', function() {
    const enabled = this.checked;
    setPanelEnabled('roleMenuPanel', enabled);
    if (enabled) {
        const keys = collectKeys('roleMenuPanel');
        buildMenuPanel('roleMenuPanel', keys.length ? keys : allKeys);
    }
});

document.getElementById('userUseCustom').addEventListener('change', function() {
    const enabled = this.checked;
    setPanelEnabled('userMenuPanel', enabled);
    if (enabled) {
        const keys = collectKeys('userMenuPanel');
        buildMenuPanel('userMenuPanel', keys.length ? keys : allKeys);
    }
});

document.getElementById('roleSelect').addEventListener('change', loadRolePerms);
document.getElementById('userSelect').addEventListener('change', loadUserPerms);

function saveMenuPermissions(action, payload, onSuccess) {
    const formData = new FormData();
    formData.append('action', action);
    formData.append('payload', JSON.stringify(payload));
    return fetch('fetch_menu_permissions_api.php?action=' + encodeURIComponent(action), {
        method: 'POST',
        body: formData
    }).then(function(r) {
        return r.text().then(function(text) {
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                throw new Error(text ? text.substring(0, 300) : 'เซิร์ฟเวอร์ไม่ส่ง JSON');
            }
            if (!r.ok && (!data || !data.msg)) {
                throw new Error('HTTP ' + r.status);
            }
            return data;
        });
    }).then(function(data) {
        if (data.status === 'success') {
            if (onSuccess) onSuccess();
            return data;
        }
        throw new Error(data.msg || 'บันทึกไม่สำเร็จ');
    });
}

document.getElementById('btnSaveRole').addEventListener('click', function() {
    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>กำลังบันทึก...';
    saveMenuPermissions('save_role', {
        role: document.getElementById('roleSelect').value,
        use_custom: document.getElementById('roleUseCustom').checked,
        menu_keys: collectKeys('roleMenuPanel')
    }, loadRolePerms)
    .then(function() { alert('บันทึกสิทธิ์กลุ่มเรียบร้อย'); })
    .catch(function(err) { alert('ไม่สามารถบันทึกได้: ' + (err.message || 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้')); })
    .finally(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save me-1"></i>บันทึกสิทธิ์กลุ่ม';
    });
});

document.getElementById('btnSaveUser').addEventListener('click', function() {
    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>กำลังบันทึก...';
    saveMenuPermissions('save_user', {
        user_id: parseInt(document.getElementById('userSelect').value, 10),
        use_custom: document.getElementById('userUseCustom').checked,
        menu_keys: collectKeys('userMenuPanel')
    }, loadUserPerms)
    .then(function() { alert('บันทึกสิทธิ์รายคนเรียบร้อย'); })
    .catch(function(err) { alert('ไม่สามารถบันทึกได้: ' + (err.message || 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้')); })
    .finally(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save me-1"></i>บันทึกสิทธิ์รายคน';
    });
});

initMenuPanels();
loadRolePerms().catch(function(err) { alert('โหลดสิทธิ์กลุ่มไม่สำเร็จ: ' + err.message); });
loadUserPerms().catch(function(err) { alert('โหลดสิทธิ์รายคนไม่สำเร็จ: ' + err.message); });
</script>
</body>
</html>
