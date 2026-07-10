<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
include('connect.php');
mysqli_set_charset($con, 'utf8');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ข้อมูลยา - THAM SAFE</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
    <link href="css/dataTables.bootstrap5.min.css" rel="stylesheet">
</head>
<body>
<div class="wrapper">
    <?php include('sidebar.php'); ?>

    <div id="content" class="content">
        <div class="card shadow-sm border-0">
            <div class="card-header text-white d-flex justify-content-between align-items-center py-3" style="background: var(--green-600, #1f4d3b);">
                <h5 class="mb-0 fw-bold"><i class="fas fa-capsules me-2"></i>ข้อมูลยา (Master Data)</h5>
                <button class="btn btn-light btn-sm fw-bold" onclick="openAddModal()">
                    <i class="fas fa-plus-circle me-1"></i> เพิ่มยา
                </button>
            </div>
            <div class="card-body p-4">
                <p class="text-muted small mb-3">
                    รายการยาในระบบนี้ใช้สำหรับ Monitor Interaction และ Monitor Lab — ต้องกำหนด <strong>ICODE</strong> ให้ตรงกับ HOSxP
                </p>
                <div class="table-responsive">
                    <table id="drugTable" class="table table-bordered table-hover align-middle w-100">
                        <thead class="table-light text-center">
                            <tr>
                                <th width="5%">ID</th>
                                <th width="15%">ICODE</th>
                                <th>ชื่อยา</th>
                                <th width="12%">หน่วย</th>
                                <th width="10%">สถานะ</th>
                                <th width="12%">Cumulative</th>
                                <th width="10%">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $q = mysqli_query($con, "SELECT id, icode, name, strength_unit, istatus, cumulative_dose FROM drugitems ORDER BY name ASC");
                            while ($row = mysqli_fetch_assoc($q)):
                            ?>
                            <tr>
                                <td class="text-center"><?php echo (int)$row['id']; ?></td>
                                <td class="text-center fw-bold text-primary"><?php echo htmlspecialchars($row['icode'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="text-center"><?php echo htmlspecialchars($row['strength_unit'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="text-center">
                                    <?php if (($row['istatus'] ?? 'Y') === 'Y'): ?>
                                        <span class="badge bg-success">ใช้งาน</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">ปิด</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?php echo $row['cumulative_dose'] > 0 ? number_format($row['cumulative_dose'], 2) : '-'; ?></td>
                                <td class="text-center">
                                    <button class="btn btn-warning btn-sm" onclick="openEditModal(<?php echo (int)$row['id']; ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="drugModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form id="drugForm" action="save_drug.php" method="POST" class="modal-content">
            <div class="modal-header text-white" style="background: var(--green-600, #1f4d3b);">
                <h5 class="modal-title fw-bold" id="modalTitle">เพิ่มยา</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" name="action_type" id="action_type" value="add">
                <input type="hidden" name="id" id="m_id" value="">
                <div class="mb-3">
                    <label class="form-label fw-bold">ICODE (HOSxP) <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="icode" id="m_icode" required placeholder="เช่น 1500123">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">ชื่อยา <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="name" id="m_name" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">หน่วย (Unit)</label>
                    <input type="text" class="form-control" name="strength_unit" id="m_strength" placeholder="เช่น mg, mg/m2">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Cumulative Dose Limit</label>
                    <input type="number" step="0.01" class="form-control" name="cumulative_dose" id="m_cumulative" value="0">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">สถานะ</label>
                    <select class="form-select" name="istatus" id="m_istatus">
                        <option value="Y">ใช้งาน</option>
                        <option value="N">ปิดใช้งาน</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn btn-primary fw-bold">บันทึก</button>
            </div>
        </form>
    </div>
</div>

<script src="js/jquery-3.7.0.min.js"></script>
<script src="js/bootstrap.bundle.min.js"></script>
<script src="js/jquery.dataTables.min.js"></script>
<script src="js/dataTables.bootstrap5.min.js"></script>
<script>
    let drugModal = new bootstrap.Modal(document.getElementById('drugModal'));

    $(document).ready(function() {
        $('#drugTable').DataTable({ language: { url: 'js/i18n/datatables-th.json' } });
    });

    function openAddModal() {
        document.getElementById('action_type').value = 'add';
        document.getElementById('m_id').value = '';
        document.getElementById('m_icode').value = '';
        document.getElementById('m_name').value = '';
        document.getElementById('m_strength').value = '';
        document.getElementById('m_cumulative').value = '0';
        document.getElementById('m_istatus').value = 'Y';
        document.getElementById('modalTitle').textContent = 'เพิ่มยา';
        drugModal.show();
    }

    function openEditModal(id) {
        fetch('get_drug_api.php?id=' + id)
            .then(r => r.json())
            .then(res => {
                if (res.status !== 'success') { alert(res.message); return; }
                const d = res.data;
                document.getElementById('action_type').value = 'edit';
                document.getElementById('m_id').value = d.id;
                document.getElementById('m_icode').value = d.icode || '';
                document.getElementById('m_name').value = d.name || '';
                document.getElementById('m_strength').value = d.strength_unit || '';
                document.getElementById('m_cumulative').value = d.cumulative_dose || '0';
                document.getElementById('m_istatus').value = d.istatus || 'Y';
                document.getElementById('modalTitle').textContent = 'แก้ไขยา';
                drugModal.show();
            });
    }
</script>
</body>
</html>
