<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

include('connect.php');
mysqli_set_charset($con, 'utf8');
require_once __DIR__ . '/menu_permissions_helper.php';
require_once __DIR__ . '/opduser_auth_helper.php';
require_once __DIR__ . '/user_access_helper.php';
tham_opduser_ensure_tables($con);
tham_user_access_ensure_tables($con);
if (empty($_SESSION['menu_permissions_loaded'])) {
    tham_menu_load_session_permissions($con, (int)$_SESSION['user_id'], $_SESSION['role'] ?? '');
}
if (!tham_menu_can('admin.users')) {
    echo "<script>alert('คุณไม่มีสิทธิ์เข้าหน้าจัดการผู้ใช้งาน'); window.location='home.php';</script>";
    exit;
}

$roleLabels = tham_menu_role_labels();

function users_role_badge($role) {
    switch ($role) {
        case 'admin': return '<span class="badge bg-danger">ผู้ดูแลระบบ</span>';
        case 'pharmacist': return '<span class="badge bg-success">เภสัชกร</span>';
        case 'doctor': return '<span class="badge bg-primary">แพทย์</span>';
        case 'nurse': return '<span class="badge bg-info text-dark">พยาบาล</span>';
        case 'pharmacy_technician': return '<span class="badge bg-warning text-dark">เจ้าพนักงานเภสัชกรรม</span>';
        case 'general_staff': return '<span class="badge bg-secondary">เจ้าหน้าที่ทั่วไป</span>';
        default: return '<span class="badge bg-light text-dark">ไม่ระบุ</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>จัดการผู้ใช้งาน - THAM SAFE</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet"> </head>
<body>

<div class="wrapper">
    <?php include('sidebar.php'); ?>

    <div id="content" class="content">
        <div class="container-fluid">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-primary text-white py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0 fw-bold"><i class="fas fa-users-cog me-2"></i>จัดการรายชื่อผู้เข้าใช้งานระบบ</h5>
                    <div class="d-flex gap-2">
                        <a href="user_access_log.php" class="btn btn-light btn-sm fw-bold text-primary shadow-sm"><i class="fas fa-history me-1"></i> Log การเข้าใช้งาน</a>
                        <a href="menu_permissions.php" class="btn btn-light btn-sm fw-bold text-primary shadow-sm"><i class="fas fa-key me-1"></i> สิทธิ์เข้าถึงเมนู</a>
                        <button class="btn btn-light btn-sm fw-bold text-primary shadow-sm" onclick="openUserModal()"><i class="fas fa-plus-circle me-1"></i> เพิ่มบัญชี Admin ภายใน</button>
                    </div>
                </div>
                <div class="card-body p-4">
                    <div class="alert alert-info py-2 mb-3">
                        <i class="fas fa-info-circle me-1"></i>
                        ผู้ใช้ทั่วไปเข้าระบบด้วย <strong>loginname</strong> และรหัสผ่านจากตาราง <strong>opduser</strong> ใน HOSxP
                        (เฉพาะบัญชีที่ <strong>account_disable = N</strong>) สิทธิเริ่มต้นคือ <strong>เจ้าหน้าที่ทั่วไป</strong> และสามารถผูกสิทธิตาม <strong>entryposition</strong> ได้ด้านล่าง
                    </div>
                    <div class="row g-2 mb-3 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label fw-bold mb-1">ค้นหาในรายการที่ลงทะเบียนแล้ว</label>
                            <input type="text" class="form-control" id="localUserSearch" placeholder="Username, ชื่อ, ตำแหน่ง...">
                        </div>
                        <div class="col-md-8 text-md-end">
                            <a href="#reportCard" class="btn btn-outline-success btn-sm fw-bold"><i class="fas fa-search me-1"></i>ค้นหาจาก HOSxP ทั้งหมด</a>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-bordered align-middle" id="localUsersTable">
                            <thead class="table-light text-center">
                                <tr>
                                    <th width="4%">ID</th>
                                    <th width="14%">Username</th>
                                    <th width="22%">ชื่อ - นามสกุล</th>
                                    <th width="16%">ตำแหน่ง (entryposition)</th>
                                    <th width="10%">แหล่งข้อมูล</th>
                                    <th width="10%">สิทธิ์การใช้งาน</th>
                                    <th width="14%">ใช้งานล่าสุด</th>
                                    <th width="18%">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $q = mysqli_query($con, "SELECT * FROM users ORDER BY auth_source ASC, id ASC");
                                while($row = mysqli_fetch_assoc($q)):
                                    $auth_source = $row['auth_source'] ?? 'local';
                                    $source_badge = $auth_source === 'opduser'
                                        ? '<span class="badge bg-success">HOSxP</span>'
                                        : '<span class="badge bg-dark">ภายใน</span>';
                                    $entryposition = trim((string)($row['entryposition'] ?? ''));
                                    $auto_role = (int)($row['role_from_entryposition'] ?? 0) === 1;
                                    $role_note = $auto_role ? '<br><small class="text-muted">ตามตำแหน่ง</small>' : '';
                                ?>
                                <tr class="local-user-row" data-search="<?php echo htmlspecialchars(mb_strtolower($row['username'] . ' ' . $row['full_name'] . ' ' . $entryposition, 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>">
                                    <td class="text-center fw-bold"><?php echo $row['id']; ?></td>
                                    <td class="fw-bold text-primary"><?php echo htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo $entryposition !== '' ? htmlspecialchars($entryposition, ENT_QUOTES, 'UTF-8') : '<span class="text-muted">-</span>'; ?></td>
                                    <td class="text-center"><?php echo $source_badge; ?></td>
                                    <td class="text-center"><?php echo users_role_badge($row['role']) . $role_note; ?></td>
                                    <td class="text-center small" data-last-seen="<?php echo htmlspecialchars((string)($row['last_seen_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php
                                        $last_seen = $row['last_seen_at'] ?? null;
                                        echo tham_user_access_online_badge($last_seen);
                                        echo '<br><span class="text-muted ago-text">' . htmlspecialchars(tham_user_access_format_ago($last_seen), ENT_QUOTES, 'UTF-8') . '</span>';
                                        if (!empty($row['last_login_at'])) {
                                            echo '<br><small class="text-muted">ล็อกอิน: ' . htmlspecialchars(tham_user_access_format_ago($row['last_login_at']), ENT_QUOTES, 'UTF-8') . '</small>';
                                        }
                                        ?>
                                    </td>
                                    <td class="text-center">
                                        <button class="btn btn-warning btn-sm fw-bold shadow-sm" onclick='editUser(<?php echo json_encode([
                                            "id" => (int)$row["id"],
                                            "username" => $row["username"],
                                            "full_name" => $row["full_name"],
                                            "role" => $row["role"],
                                            "auth_source" => $auth_source,
                                            "entryposition" => $entryposition,
                                            "role_from_entryposition" => $auto_role ? 1 : 0,
                                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>)'>
                                            <i class="fas fa-edit me-1"></i> แก้ไข
                                        </button>
                                        <?php if ($row['role'] !== 'admin'): ?>
                                        <a href="menu_permissions.php?tab=user&amp;user_id=<?php echo (int)$row['id']; ?>" class="btn btn-info btn-sm fw-bold shadow-sm ms-1 text-white" title="สิทธิ์เมนูรายคน">
                                            <i class="fas fa-key me-1"></i> สิทธิ์เมนู
                                        </a>
                                        <?php endif; ?>
                                        <a href="user_access_log.php?user_id=<?php echo (int)$row['id']; ?>" class="btn btn-outline-dark btn-sm fw-bold shadow-sm ms-1" title="ดู log การเข้าใช้">
                                            <i class="fas fa-history"></i>
                                        </a>
                                        <?php if ($auth_source === 'local'): ?>
                                        <button class="btn btn-outline-danger btn-sm fw-bold shadow-sm ms-1" onclick="deleteUser(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8'); ?>')">
                                            <i class="fas fa-trash-alt me-1"></i> ลบ
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-4" id="reportCard">
                <div class="card-header bg-info text-white py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0 fw-bold"><i class="fas fa-file-alt me-2"></i>ค้นหา / รายงานผู้ใช้ตามตำแหน่ง (ดึงจาก HOSxP)</h5>
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="button" class="btn btn-light btn-sm fw-bold text-info" id="btnPrintReport"><i class="fas fa-print me-1"></i>พิมพ์</button>
                        <button type="button" class="btn btn-light btn-sm fw-bold text-info" id="btnExportReport"><i class="fas fa-file-csv me-1"></i>ส่งออก CSV</button>
                    </div>
                </div>
                <div class="card-body p-4" id="reportPrintArea">
                    <div class="row g-2 mb-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label fw-bold mb-1">คำค้น</label>
                            <input type="text" class="form-control" id="reportQ" placeholder="loginname, ชื่อ, แผนก...">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold mb-1">ตำแหน่ง (entryposition)</label>
                            <select class="form-select" id="reportPosition">
                                <option value="">— ทุกตำแหน่ง —</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold mb-1">แผนก</label>
                            <input type="text" class="form-control" id="reportDepartment" placeholder="เช่น อายุรกรรม">
                        </div>
                        <div class="col-md-3">
                            <button type="button" class="btn btn-info text-white fw-bold w-100" id="btnSearchReport"><i class="fas fa-search me-1"></i>ค้นหา</button>
                        </div>
                    </div>
                    <div id="reportSummary" class="text-muted mb-2"></div>
                    <div id="reportLoading" class="text-center text-muted py-4 d-none"><i class="fas fa-spinner fa-spin me-1"></i>กำลังดึงข้อมูลจาก HOSxP...</div>
                    <div id="reportError" class="alert alert-danger d-none"></div>
                    <div class="table-responsive d-none" id="reportWrap">
                        <table class="table table-bordered table-hover align-middle table-sm">
                            <thead class="table-light text-center">
                                <tr>
                                    <th>loginname</th>
                                    <th>ชื่อ - นามสกุล</th>
                                    <th>entryposition</th>
                                    <th>แผนก</th>
                                    <th>สิทธิ (ตามตำแหน่ง)</th>
                                    <th>THAM SAFE</th>
                                </tr>
                            </thead>
                            <tbody id="reportBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-header bg-success text-white py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0 fw-bold"><i class="fas fa-id-badge me-2"></i>กำหนดสิทธิตามตำแหน่ง (entryposition จาก HOSxP)</h5>
                    <button type="button" class="btn btn-light btn-sm fw-bold text-success" id="btnReloadPositions"><i class="fas fa-sync-alt me-1"></i> โหลดจาก HOSxP</button>
                </div>
                <div class="card-body p-4">
                    <p class="text-muted mb-3">
                        ตำแหน่งที่ยังไม่กำหนดจะได้สิทธิ <strong>เจ้าหน้าที่ทั่วไป</strong> โดยอัตโนมัติ —
                        ผู้ใช้ที่เปิด "ผูกสิทธิตามตำแหน่ง" จะอัปเดต Role ตามตารางนี้เมื่อบันทึกหรือเข้าสู่ระบบ —
                        <strong>คลิกชื่อตำแหน่งหรือจำนวนคน</strong> เพื่อดูรายชื่อในตำแหน่งนั้น
                    </p>
                    <div id="positionMapLoading" class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin me-1"></i>กำลังโหลดรายการตำแหน่ง...</div>
                    <div id="positionMapError" class="alert alert-danger d-none"></div>
                    <div class="table-responsive d-none" id="positionMapWrap">
                        <table class="table table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>ตำแหน่ง (entryposition)</th>
                                    <th width="12%" class="text-center">จำนวนใน HOSxP</th>
                                    <th width="28%">สิทธิใน THAM SAFE</th>
                                </tr>
                            </thead>
                            <tbody id="positionMapBody"></tbody>
                        </table>
                        <div class="text-end mt-3">
                            <button type="button" class="btn btn-success fw-bold px-4" id="btnSavePositionMap"><i class="fas fa-save me-1"></i>บันทึกการผูกสิทธิ</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-primary">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold" id="userModalTitle"><i class="fas fa-user-plus me-2"></i>เพิ่มผู้ใช้งานใหม่</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="userForm" onsubmit="submitUser(event)">
                    <input type="hidden" id="u_id" name="id" value="0">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">ชื่อเข้าใช้งาน (Username) <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="u_username" name="username" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">รหัสผ่าน (Password) <span class="text-danger" id="pwd_req">*</span></label>
                        <input type="password" class="form-control" id="u_password" name="password" placeholder="ปล่อยว่างไว้หากไม่ต้องการเปลี่ยนรหัสผ่าน (กรณีแก้ไข)">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">ยืนยันรหัสผ่าน (Confirm Password) <span class="text-danger" id="pwd_confirm_req">*</span></label>
                        <input type="password" class="form-control" id="u_password_confirm" placeholder="กรอกรหัสผ่านอีกครั้งให้ตรงกัน">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">ชื่อ - นามสกุล (แสดงบนจอ) <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="u_fullname" name="full_name" required>
                    </div>

                    <div class="mb-3" id="wrap_entryposition">
                        <label class="form-label fw-bold">ตำแหน่ง (entryposition)</label>
                        <input type="text" class="form-control bg-light" id="u_entryposition" readonly>
                    </div>

                    <div class="mb-3 d-none" id="wrap_role_auto">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="u_role_auto" name="role_from_entryposition" value="1">
                            <label class="form-check-label fw-bold" for="u_role_auto">ผูกสิทธิตามตำแหน่ง (entryposition) อัตโนมัติ</label>
                        </div>
                    </div>

                    <div class="mb-4" id="wrap_role">
                        <label class="form-label fw-bold">ระดับสิทธิ์ (Role) <span class="text-danger">*</span></label>
                        <select class="form-select" id="u_role" name="role" required>
                            <option value="admin">ผู้ดูแลระบบ (Admin)</option>
                            <option value="pharmacist">เภสัชกร (Pharmacist)</option>
                            <option value="doctor">แพทย์ (Doctor)</option>
                            <option value="nurse">พยาบาล (Nurse)</option>
                            <option value="pharmacy_technician">เจ้าพนักงานเภสัชกรรม (Pharmacy Technician)</option>
                            <option value="general_staff">เจ้าหน้าที่ทั่วไป (General Staff)</option>
                        </select>
                    </div>

                    <div class="text-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary fw-bold px-4" id="btnSaveUser"><i class="fas fa-save me-1"></i>บันทึกข้อมูล</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="js/jquery-3.7.0.min.js"></script>
<script src="js/bootstrap.bundle.min.js"></script>
<script>
    const roleLabels = <?php echo json_encode($roleLabels, JSON_UNESCAPED_UNICODE); ?>;
    let userModal = new bootstrap.Modal(document.getElementById('userModal'));
    let currentAuthSource = 'local';
    let positionMapRows = [];

    function setLocalUserFormMode(isLocal) {
        document.getElementById('wrap_entryposition').classList.toggle('d-none', isLocal);
        document.getElementById('wrap_role_auto').classList.toggle('d-none', isLocal);
        document.getElementById('u_password').closest('.mb-3').classList.toggle('d-none', !isLocal);
        document.getElementById('u_password_confirm').closest('.mb-3').classList.toggle('d-none', !isLocal);
        const roleSelect = document.getElementById('u_role');
        const auto = document.getElementById('u_role_auto').checked;
        roleSelect.disabled = !isLocal && auto;
    }

    function openUserModal() {
        currentAuthSource = 'local';
        document.getElementById('u_id').value = '0';
        document.getElementById('u_username').value = '';
        document.getElementById('u_username').readOnly = false;
        document.getElementById('u_password').value = '';
        document.getElementById('u_password').required = true;
        document.getElementById('pwd_req').style.display = 'inline';
        document.getElementById('u_password_confirm').value = '';
        document.getElementById('u_password_confirm').required = true;
        document.getElementById('pwd_confirm_req').style.display = 'inline';
        document.getElementById('u_fullname').value = '';
        document.getElementById('u_entryposition').value = '';
        document.getElementById('u_role_auto').checked = false;
        document.getElementById('u_role').value = 'admin';
        document.getElementById('userModalTitle').innerHTML = '<i class="fas fa-user-plus me-2"></i>เพิ่มบัญชี Admin ภายใน';
        setLocalUserFormMode(true);
        userModal.show();
    }

    function editUser(data) {
        currentAuthSource = data.auth_source || 'local';
        const isLocal = currentAuthSource !== 'opduser';
        document.getElementById('u_id').value = data.id;
        document.getElementById('u_username').value = data.username;
        document.getElementById('u_username').readOnly = true;
        document.getElementById('u_password').value = '';
        document.getElementById('u_password').required = false;
        document.getElementById('pwd_req').style.display = 'none';
        document.getElementById('u_password_confirm').value = '';
        document.getElementById('u_password_confirm').required = false;
        document.getElementById('pwd_confirm_req').style.display = 'none';
        document.getElementById('u_fullname').value = data.full_name;
        document.getElementById('u_entryposition').value = data.entryposition || '';
        document.getElementById('u_role_auto').checked = !!data.role_from_entryposition;
        document.getElementById('u_role').value = data.role;
        document.getElementById('userModalTitle').innerHTML = '<i class="fas fa-user-edit me-2"></i>แก้ไขข้อมูลผู้ใช้งาน';
        setLocalUserFormMode(isLocal);
        userModal.show();
    }

    document.getElementById('u_role_auto').addEventListener('change', function() {
        setLocalUserFormMode(currentAuthSource !== 'opduser');
    });

    function submitUser(e) {
        e.preventDefault();

        // ตรวจสอบความถูกต้องของรหัสผ่าน
        let pwd = document.getElementById('u_password').value;
        let pwd_confirm = document.getElementById('u_password_confirm').value;
        const isLocalForm = currentAuthSource === 'local' && document.getElementById('u_id').value === '0'
            || (document.getElementById('u_id').value !== '0' && currentAuthSource === 'local');

        if (isLocalForm && pwd !== pwd_confirm) {
            alert('รหัสผ่านและการยืนยันรหัสผ่านไม่ตรงกัน กรุณาตรวจสอบอีกครั้งครับ!');
            return; // หยุดการทำงานถ้าไม่ตรงกัน
        }

        let form = document.getElementById('userForm');
        let formData = new FormData(form);
        // สามารถลบฟิลด์ confirm password ออกจาก formData ได้ (ถ้าไม่ต้องการส่งไปรก backend)
        formData.delete('password_confirm'); 

        let btn = document.getElementById('btnSaveUser');
        let originalText = btn.innerHTML;

        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>กำลังบันทึก...';
        btn.disabled = true;

        fetch('save_user_api.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if(data.status === 'success') {
                    alert('บันทึกข้อมูลผู้ใช้งานเรียบร้อยแล้วครับ');
                    location.reload();
                } else {
                    alert('เกิดข้อผิดพลาด: ' + data.msg);
                }
            })
            .catch(err => alert('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้'))
            .finally(() => { btn.innerHTML = originalText; btn.disabled = false; });
    }

    function deleteUser(id, username) {
        if(confirm(`คุณแน่ใจหรือไม่ว่าต้องการลบผู้ใช้งาน: ${username} ?\n\n⚠️ การลบข้อมูลไม่สามารถกู้คืนได้`)) {
            fetch('delete_user_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id })
            })
            .then(res => res.json())
            .then(data => {
                if(data.status === 'success') {
                    alert('ลบผู้ใช้งานสำเร็จ!');
                    location.reload();
                } else {
                    alert('ลบไม่สำเร็จ: ' + data.msg);
                }
            });
        }
    }

    let positionOptionsLoaded = false;

    function loadReportPositionOptions(selected) {
        return fetch('fetch_opduser_report_api.php?action=positions')
            .then(r => r.json())
            .then(data => {
                if (data.status !== 'success') return;
                const sel = document.getElementById('reportPosition');
                const cur = selected || sel.value;
                sel.innerHTML = '<option value="">— ทุกตำแหน่ง —</option>';
                (data.positions || []).forEach(function(p) {
                    if (p.entryposition === '__empty__') return;
                    const opt = document.createElement('option');
                    opt.value = p.entryposition;
                    opt.textContent = p.entryposition + ' (' + p.user_count + ')';
                    sel.appendChild(opt);
                });
                const emptyOpt = document.createElement('option');
                emptyOpt.value = '__empty__';
                emptyOpt.textContent = '(ไม่ระบุตำแหน่ง)';
                sel.appendChild(emptyOpt);
                if (cur) sel.value = cur;
                positionOptionsLoaded = true;
            });
    }

    function reportQueryParams() {
        return new URLSearchParams({
            q: document.getElementById('reportQ').value.trim(),
            entryposition: document.getElementById('reportPosition').value,
            department: document.getElementById('reportDepartment').value.trim(),
            limit: '500',
            offset: '0'
        });
    }

    function searchOpduserReport(scrollToReport) {
        const loading = document.getElementById('reportLoading');
        const wrap = document.getElementById('reportWrap');
        const err = document.getElementById('reportError');
        const summary = document.getElementById('reportSummary');
        loading.classList.remove('d-none');
        wrap.classList.add('d-none');
        err.classList.add('d-none');
        summary.textContent = '';
        if (scrollToReport) {
            document.getElementById('reportCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        fetch('fetch_opduser_report_api.php?' + reportQueryParams().toString())
            .then(r => r.json())
            .then(data => {
                loading.classList.add('d-none');
                if (data.status !== 'success') {
                    err.textContent = data.msg || 'ค้นหาไม่สำเร็จ';
                    err.classList.remove('d-none');
                    return;
                }
                const body = document.getElementById('reportBody');
                body.innerHTML = '';
                (data.rows || []).forEach(function(row) {
                    const tr = document.createElement('tr');
                    const thamBadge = row.tham_registered
                        ? '<span class="badge bg-success">ลงทะเบียนแล้ว</span><br><small>' + esc(row.tham_role_label || '-') + '</small>'
                        : '<span class="badge bg-secondary">ยังไม่เคย login</span>';
                    tr.innerHTML =
                        '<td class="fw-bold text-primary">' + esc(row.loginname) + '</td>' +
                        '<td>' + esc(row.name) + '</td>' +
                        '<td>' + (row.entryposition ? esc(row.entryposition) : '<span class="text-muted">-</span>') + '</td>' +
                        '<td>' + (row.department ? esc(row.department) : '<span class="text-muted">-</span>') + '</td>' +
                        '<td class="text-center">' + esc(row.mapped_role_label || '-') + '</td>' +
                        '<td class="text-center">' + thamBadge + '</td>';
                    body.appendChild(tr);
                });
                let msg = 'พบ ' + data.total + ' รายการ';
                if (data.truncated) {
                    msg += ' (แสดง ' + (data.rows || []).length + ' รายการแรก — ใช้ตัวกรองเพิ่มหรือส่งออก CSV เพื่อดูทั้งหมด)';
                }
                summary.textContent = msg;
                wrap.classList.remove('d-none');
            })
            .catch(function() {
                loading.classList.add('d-none');
                err.textContent = 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้';
                err.classList.remove('d-none');
            });
    }

    function searchByEntryposition(pos) {
        document.getElementById('reportPosition').value = pos;
        document.getElementById('reportQ').value = '';
        searchOpduserReport(true);
    }

    document.getElementById('btnSearchReport').addEventListener('click', function() { searchOpduserReport(false); });
    document.getElementById('reportQ').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); searchOpduserReport(false); }
    });
    document.getElementById('btnExportReport').addEventListener('click', function() {
        window.location.href = 'export_opduser_report.php?' + reportQueryParams().toString();
    });
    document.getElementById('btnPrintReport').addEventListener('click', function() {
        const area = document.getElementById('reportPrintArea');
        const w = window.open('', '_blank');
        w.document.write('<html><head><meta charset="UTF-8"><title>รายงานผู้ใช้ HOSxP</title>');
        w.document.write('<link href="css/bootstrap.min.css" rel="stylesheet">');
        w.document.write('<style>body{padding:20px;font-family:Tahoma,sans-serif} table{font-size:12px}</style></head><body>');
        w.document.write('<h4>รายงานผู้ใช้ตามตำแหน่ง (HOSxP opduser)</h4>');
        w.document.write('<p class="text-muted">' + esc(document.getElementById('reportSummary').textContent) + '</p>');
        w.document.write(area.querySelector('#reportWrap') ? area.querySelector('#reportWrap').outerHTML : '');
        w.document.write('</body></html>');
        w.document.close();
        w.focus();
        w.print();
    });

    document.getElementById('localUserSearch').addEventListener('input', function() {
        const q = this.value.trim().toLowerCase();
        document.querySelectorAll('.local-user-row').forEach(function(tr) {
            const hay = tr.getAttribute('data-search') || '';
            tr.style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
        });
    });

    loadReportPositionOptions();

    function roleOptionsHtml(selected) {
        let html = '';
        Object.keys(roleLabels).forEach(function(key) {
            if (key === 'admin') return;
            html += '<option value="' + key + '"' + (key === selected ? ' selected' : '') + '>' + roleLabels[key] + '</option>';
        });
        return html;
    }

    function escAttr(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    }

    function loadPositionMap() {
        const loading = document.getElementById('positionMapLoading');
        const wrap = document.getElementById('positionMapWrap');
        const err = document.getElementById('positionMapError');
        loading.classList.remove('d-none');
        wrap.classList.add('d-none');
        err.classList.add('d-none');
        fetch('fetch_entryposition_map_api.php')
            .then(r => r.json())
            .then(data => {
                loading.classList.add('d-none');
                if (data.status !== 'success') {
                    err.textContent = data.msg || 'โหลดไม่สำเร็จ';
                    err.classList.remove('d-none');
                    return;
                }
                const body = document.getElementById('positionMapBody');
                body.innerHTML = '';
                positionMapRows = (data.positions || []).map(function(row) {
                    return {
                        entryposition: row.entryposition || '',
                        role: row.role || data.default_role,
                        user_count: row.user_count || 0
                    };
                });
                positionMapRows.forEach(function(row, idx) {
                    const tr = document.createElement('tr');
                    tr.innerHTML =
                        '<td class="fw-bold"><button type="button" class="btn btn-link btn-sm p-0 text-decoration-none pos-name-link" data-pos-idx="' + idx + '">' + esc(row.entryposition) + '</button></td>' +
                        '<td class="text-center"><button type="button" class="btn btn-link btn-sm fw-bold pos-count-link" data-pos-idx="' + idx + '" title="ดูรายชื่อ">' + row.user_count + '</button></td>' +
                        '<td><select class="form-select form-select-sm pos-role-select" data-pos-idx="' + idx + '">' +
                        roleOptionsHtml(row.role || data.default_role) + '</select></td>';
                    body.appendChild(tr);
                });
                wrap.classList.remove('d-none');
            })
            .catch(function() {
                loading.classList.add('d-none');
                err.textContent = 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้';
                err.classList.remove('d-none');
            });
    }

    function esc(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
    }

    document.getElementById('positionMapBody').addEventListener('click', function(e) {
        const btn = e.target.closest('.pos-count-link, .pos-name-link');
        if (!btn) return;
        const idx = parseInt(btn.getAttribute('data-pos-idx'), 10);
        const row = positionMapRows[idx];
        if (row && row.entryposition) {
            searchByEntryposition(row.entryposition);
        }
    });

    document.getElementById('btnReloadPositions').addEventListener('click', loadPositionMap);
    document.getElementById('btnSavePositionMap').addEventListener('click', function() {
        const rows = [];
        document.querySelectorAll('.pos-role-select').forEach(function(sel) {
            const idx = parseInt(sel.getAttribute('data-pos-idx'), 10);
            const base = positionMapRows[idx];
            if (!base || !base.entryposition) return;
            rows.push({ entryposition: base.entryposition, role: sel.value });
        });
        if (!rows.length) {
            alert('ไม่พบข้อมูลตำแหน่ง กรุณากด "โหลดจาก HOSxP" ก่อนบันทึก');
            return;
        }
        const btn = this;
        const old = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>กำลังบันทึก...';
        const formData = new FormData();
        formData.append('positions', JSON.stringify(rows));
        fetch('save_entryposition_map_api.php', {
            method: 'POST',
            body: formData
        })
        .then(function(r) {
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
        })
        .then(function(data) {
            if (data.status === 'success') {
                let msg = data.msg || 'บันทึกสำเร็จ';
                if (data.positions_saved) msg += '\nบันทึก ' + data.positions_saved + ' ตำแหน่ง';
                if (data.users_updated) msg += '\nอัปเดตผู้ใช้ ' + data.users_updated + ' รายการ';
                alert(msg);
                location.reload();
            } else {
                alert(data.msg || 'บันทึกไม่สำเร็จ');
            }
        })
        .catch(function(err) {
            alert('ไม่สามารถบันทึกได้: ' + (err && err.message ? err.message : 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้'));
        })
        .finally(function() { btn.disabled = false; btn.innerHTML = old; });
    });

    loadPositionMap();

    function formatAgoFromIso(iso) {
        if (!iso) return '-';
        const ts = new Date(String(iso).replace(' ', 'T')).getTime();
        if (Number.isNaN(ts)) return '-';
        const seconds = Math.max(0, Math.floor((Date.now() - ts) / 1000));
        if (seconds < 30) return 'เมื่อสักครู่';
        if (seconds < 60) return 'เมื่อ ' + seconds + ' วินาทีที่แล้ว';
        const minutes = Math.floor(seconds / 60);
        if (minutes < 60) return 'เมื่อ ' + minutes + ' นาทีที่แล้ว';
        const hours = Math.floor(minutes / 60);
        if (hours < 24) return 'เมื่อ ' + hours + ' ชั่วโมงที่แล้ว';
        const days = Math.floor(hours / 24);
        return 'เมื่อ ' + days + ' วันที่แล้ว';
    }

    function refreshLastSeenAgo() {
        document.querySelectorAll('[data-last-seen]').forEach(function (cell) {
            const iso = cell.getAttribute('data-last-seen');
            const agoEl = cell.querySelector('.ago-text');
            if (agoEl) {
                agoEl.textContent = formatAgoFromIso(iso);
            }
        });
    }

    setInterval(refreshLastSeenAgo, 60000);
</script>
</body>
</html>