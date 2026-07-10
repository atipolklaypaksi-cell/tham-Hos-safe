<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

include('connect.php');
mysqli_set_charset($con, 'utf8');
require_once __DIR__ . '/menu_permissions_helper.php';
require_once __DIR__ . '/user_access_helper.php';
tham_user_access_ensure_tables($con);

if (empty($_SESSION['menu_permissions_loaded'])) {
    tham_menu_load_session_permissions($con, (int)$_SESSION['user_id'], $_SESSION['role'] ?? '');
}
if (!tham_menu_can('admin.users')) {
    echo "<script>alert('คุณไม่มีสิทธิ์เข้าหน้านี้'); window.location='home.php';</script>";
    exit;
}

$filter_user_id = (int)($_GET['user_id'] ?? 0);
$filter_user_label = '';
if ($filter_user_id > 0) {
    $q = mysqli_query($con, "SELECT username, full_name FROM users WHERE id = $filter_user_id LIMIT 1");
    if ($q && mysqli_num_rows($q) > 0) {
        $u = mysqli_fetch_assoc($q);
        $filter_user_label = trim(($u['username'] ?? '') . ' — ' . ($u['full_name'] ?? ''));
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Log การเข้าใช้งาน - THAM SAFE</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
</head>
<body>
<div class="wrapper">
    <?php include('sidebar.php'); ?>

    <div id="content" class="content">
        <div class="container-fluid">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-dark text-white py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0 fw-bold"><i class="fas fa-history me-2"></i>Log การเข้าใช้งานระบบ</h5>
                    <a href="users.php" class="btn btn-light btn-sm fw-bold"><i class="fas fa-arrow-left me-1"></i> กลับจัดการผู้ใช้</a>
                </div>
                <div class="card-body p-4">
                    <?php if ($filter_user_label !== ''): ?>
                    <div class="alert alert-info py-2">
                        <i class="fas fa-filter me-1"></i> กรองเฉพาะผู้ใช้: <strong><?php echo htmlspecialchars($filter_user_label, ENT_QUOTES, 'UTF-8'); ?></strong>
                        <a href="user_access_log.php" class="ms-2 small">ล้างตัวกรอง</a>
                    </div>
                    <?php endif; ?>

                    <div class="row g-2 mb-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label fw-bold mb-1">คำค้น</label>
                            <input type="text" class="form-control" id="logQ" placeholder="username, ชื่อ, IP, หน้า...">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold mb-1">ประเภท</label>
                            <select class="form-select" id="logAction">
                                <option value="">ทั้งหมด</option>
                                <option value="login">เข้าสู่ระบบ</option>
                                <option value="logout">ออกจากระบบ</option>
                                <option value="active">ใช้งานอยู่</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold mb-1">ตั้งแต่วันที่</label>
                            <input type="date" class="form-control" id="logDateFrom">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold mb-1">ถึงวันที่</label>
                            <input type="date" class="form-control" id="logDateTo">
                        </div>
                        <div class="col-md-3">
                            <button type="button" class="btn btn-primary fw-bold w-100" id="btnSearchLog"><i class="fas fa-search me-1"></i>ค้นหา</button>
                        </div>
                    </div>

                    <div id="logSummary" class="text-muted mb-2"></div>
                    <div id="logLoading" class="text-center text-muted py-4 d-none"><i class="fas fa-spinner fa-spin me-1"></i>กำลังโหลด...</div>
                    <div id="logError" class="alert alert-danger d-none"></div>

                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle table-sm">
                            <thead class="table-light text-center">
                                <tr>
                                    <th width="12%">เวลา</th>
                                    <th width="12%">เมื่อ</th>
                                    <th width="12%">Username</th>
                                    <th width="18%">ชื่อ - นามสกุล</th>
                                    <th width="10%">ประเภท</th>
                                    <th width="14%">หน้า</th>
                                    <th width="12%">IP</th>
                                </tr>
                            </thead>
                            <tbody id="logBody">
                                <tr><td colspan="7" class="text-center text-muted py-4">กดค้นหาเพื่อแสดง log</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnLogPrev" disabled><i class="fas fa-chevron-left"></i> ก่อนหน้า</button>
                        <span id="logPageInfo" class="text-muted small"></span>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnLogNext" disabled>ถัดไป <i class="fas fa-chevron-right"></i></button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const filterUserId = <?php echo (int)$filter_user_id; ?>;
    const limit = 100;
    let offset = 0;
    let total = 0;

    const els = {
        q: document.getElementById('logQ'),
        action: document.getElementById('logAction'),
        dateFrom: document.getElementById('logDateFrom'),
        dateTo: document.getElementById('logDateTo'),
        body: document.getElementById('logBody'),
        summary: document.getElementById('logSummary'),
        loading: document.getElementById('logLoading'),
        error: document.getElementById('logError'),
        prev: document.getElementById('btnLogPrev'),
        next: document.getElementById('btnLogNext'),
        pageInfo: document.getElementById('logPageInfo'),
    };

    const actionBadge = {
        login: 'bg-success',
        logout: 'bg-secondary',
        active: 'bg-info text-dark',
    };

    const actionLabel = {
        login: 'เข้าสู่ระบบ',
        logout: 'ออกจากระบบ',
        active: 'ใช้งานอยู่',
    };

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function fmtDateTime(s) {
        if (!s) return '-';
        const d = new Date(String(s).replace(' ', 'T'));
        if (Number.isNaN(d.getTime())) return esc(s);
        return d.toLocaleString('th-TH');
    }

    function buildParams() {
        const p = new URLSearchParams();
        p.set('limit', String(limit));
        p.set('offset', String(offset));
        if (filterUserId > 0) p.set('user_id', String(filterUserId));
        if (els.q.value.trim()) p.set('q', els.q.value.trim());
        if (els.action.value) p.set('action', els.action.value);
        if (els.dateFrom.value) p.set('date_from', els.dateFrom.value);
        if (els.dateTo.value) p.set('date_to', els.dateTo.value);
        return p.toString();
    }

    function updatePager() {
        const page = Math.floor(offset / limit) + 1;
        const pages = Math.max(1, Math.ceil(total / limit));
        els.pageInfo.textContent = total > 0 ? `หน้า ${page} / ${pages} (ทั้งหมด ${total} รายการ)` : '';
        els.prev.disabled = offset <= 0;
        els.next.disabled = offset + limit >= total;
    }

    async function loadLogs() {
        els.error.classList.add('d-none');
        els.loading.classList.remove('d-none');
        try {
            const res = await fetch('fetch_user_access_log_api.php?' + buildParams());
            const data = await res.json();
            if (data.status !== 'success') {
                throw new Error(data.msg || 'โหลดไม่สำเร็จ');
            }
            total = data.total || 0;
            const rows = data.rows || [];
            if (!rows.length) {
                els.body.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">ไม่พบข้อมูล</td></tr>';
            } else {
                els.body.innerHTML = rows.map(function (r) {
                    const badge = actionBadge[r.action] || 'bg-light text-dark';
                    const label = actionLabel[r.action] || esc(r.action);
                    return '<tr>' +
                        '<td class="text-center small">' + fmtDateTime(r.created_at) + '</td>' +
                        '<td class="text-center small text-primary fw-bold">' + esc(r.ago_text || '-') + '</td>' +
                        '<td class="fw-bold text-primary">' + esc(r.username) + '</td>' +
                        '<td>' + esc(r.full_name) + '</td>' +
                        '<td class="text-center"><span class="badge ' + badge + '">' + label + '</span></td>' +
                        '<td class="small">' + esc(r.page_url || '-') + '</td>' +
                        '<td class="text-center small">' + esc(r.ip_address || '-') + '</td>' +
                        '</tr>';
                }).join('');
            }
            els.summary.textContent = total > 0 ? `แสดง ${offset + 1}-${Math.min(offset + limit, total)} จาก ${total} รายการ` : '';
            updatePager();
        } catch (e) {
            els.error.textContent = e.message || 'เกิดข้อผิดพลาด';
            els.error.classList.remove('d-none');
        } finally {
            els.loading.classList.add('d-none');
        }
    }

    document.getElementById('btnSearchLog').addEventListener('click', function () {
        offset = 0;
        loadLogs();
    });
    els.prev.addEventListener('click', function () {
        offset = Math.max(0, offset - limit);
        loadLogs();
    });
    els.next.addEventListener('click', function () {
        if (offset + limit < total) {
            offset += limit;
            loadLogs();
        }
    });

    [els.q, els.action, els.dateFrom, els.dateTo].forEach(function (el) {
        el.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                offset = 0;
                loadLogs();
            }
        });
    });

    loadLogs();
})();
</script>
</body>
</html>
