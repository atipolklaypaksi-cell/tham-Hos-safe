<?php
session_start();
if (!isset($_SESSION['user_id']) && !isset($_SESSION['full_name'])) { header("Location: login.php"); exit; }
include('connect.php');
mysqli_set_charset($con, "utf8");

// บันทึกและลบข้อมูลเงื่อนไข HBV
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] == 'add_hbv') {
        $icode = mysqli_real_escape_string($con, explode('|', $_POST['drug'])[0]);
        $drug_name = mysqli_real_escape_string($con, explode('|', $_POST['drug'])[1]);
        $m_type = mysqli_real_escape_string($con, $_POST['monitor_type']);
        
        mysqli_query($con, "INSERT INTO monitor_hbv_settings (monitor_type, icode, drug_name) VALUES ('$m_type', '$icode', '$drug_name')");
        header("Location: monitor_hbv.php?tab=settings");
        exit;
    }
    if (isset($_POST['action']) && $_POST['action'] == 'delete') {
        $id = intval($_POST['del_id']);
        mysqli_query($con, "DELETE FROM monitor_hbv_settings WHERE id = $id");
        header("Location: monitor_hbv.php?tab=settings");
        exit;
    }
}

// ต่อท่อดึงยาจาก HOSxP
$q_config = mysqli_query($con, "SELECT config_name, config_value FROM sys_config WHERE config_name IN ('h_host', 'h_user', 'h_pass', 'h_db')");
$hos_cfg = [];
while ($r = mysqli_fetch_assoc($q_config)) { $hos_cfg[$r['config_name']] = $r['config_value']; }
$con_hosxp = @mysqli_connect($hos_cfg['h_host'], $hos_cfg['h_user'], $hos_cfg['h_pass'], $hos_cfg['h_db']);

$opt_drugs = "<option value=''>- ค้นหาและเลือกยาจาก HOSxP -</option>";
if ($con_hosxp) {
    mysqli_set_charset($con_hosxp, "utf8");
    $q_drug_hosxp = mysqli_query($con_hosxp, "SELECT icode, name FROM drugitems WHERE istatus='Y' AND icode IS NOT NULL ORDER BY name ASC");
    while ($r = mysqli_fetch_assoc($q_drug_hosxp)) {
        $opt_drugs .= "<option value='" . $r['icode'] . "|" . htmlspecialchars($r['name']) . "'>" . htmlspecialchars($r['name']) . " (icode: " . $r['icode'] . ")</option>";
    }
    mysqli_close($con_hosxp);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>HBV Screening Monitor - THAM CYTO</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
    <link href="css/select2.min.css" rel="stylesheet">
    <link href="css/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="css/flatpickr.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', Tahoma, sans-serif; background-color: #f4f7f6; }
        .badge-alert { font-size: 0.85rem; padding: 6px 12px; }
        .filter-box { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; }
        .hn-group-header { background: #e9ecef; font-weight: bold; }
        .hn-group-header td { border-left: 4px solid #0d6efd; }
        .visit-row td { background: #fff; }
        .visit-row td:first-child { padding-left: 2rem; }
        .section-hard .table thead { background-color: #dc3545 !important; }
        .section-soft .table thead { background-color: #ffc107 !important; color: #212529 !important; }
        .section-success .table thead { background-color: #198754 !important; color: #fff !important; }
        .section-screened .table thead { background-color: #0dcaf0 !important; color: #0b2e3a !important; }
    </style>
</head>
<body>

<div class="wrapper">
    <?php include('sidebar.php'); ?>
    <div id="content" class="content">
        <div class="container-fluid py-4">
    <h4 class="fw-bold mb-4 text-dark"><i class="fas fa-virus-covid text-danger me-2"></i>ระบบเฝ้าระวังและคัดกรองไวรัสตับอักเสบบี (HBV Monitoring Dashboard)</h4>

    <ul class="nav nav-tabs fw-bold" id="hbvTab" role="tablist">
        <li class="nav-item">
            <button class="nav-link <?php echo (!isset($_GET['tab']) || $_GET['tab'] != 'settings') ? 'active' : ''; ?>" id="monitor-tab" data-bs-toggle="tab" data-bs-target="#monitor" type="button"><i class="fas fa-desktop me-1"></i> รายการเฝ้าระวังหน้างาน</button>
        </li>
        <li class="nav-item">
            <button class="nav-link" id="protected-tab" data-bs-toggle="tab" data-bs-target="#protected" type="button"><i class="fas fa-shield-check me-1"></i> รายการที่ได้รับยาป้องกัน</button>
        </li>
        <li class="nav-item">
            <button class="nav-link" id="screened-tab" data-bs-toggle="tab" data-bs-target="#screened" type="button"><i class="fas fa-vial-circle-check me-1"></i> รายการที่คัดกรองแล้ว</button>
        </li>
        <li class="nav-item">
            <button class="nav-link <?php echo (isset($_GET['tab']) && $_GET['tab'] == 'settings') ? 'active' : ''; ?>" id="settings-tab" data-bs-toggle="tab" data-bs-target="#settings" type="button"><i class="fas fa-cogs me-1"></i> ตั้งค่ากลุ่มยาเฝ้าระวัง</button>
        </li>
    </ul>

    <div class="tab-content bg-white border border-top-0 p-4 rounded-bottom shadow-sm">
        
        <div class="tab-pane fade <?php echo (!isset($_GET['tab']) || $_GET['tab'] != 'settings') ? 'show active' : ''; ?>" id="monitor">
            <div class="filter-box p-3 mb-3">
                <h6 class="fw-bold text-secondary mb-3"><i class="fas fa-filter me-2"></i>ตัวกรองรายงาน</h6>
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small fw-bold mb-1">ช่วงรายงาน ตั้งแต่</label>
                        <input type="text" id="filter_start" class="form-control form-control-sm bg-white">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold mb-1">ถึงวันที่</label>
                        <input type="text" id="filter_end" class="form-control form-control-sm bg-white">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold mb-1">ยาป้องกัน: ย้อนหลังก่อนให้คีโม</label>
                        <select id="co_med_months" class="form-select form-select-sm border-primary fw-bold">
                            <option value="1">1 เดือน</option>
                            <option value="2">2 เดือน</option>
                            <option value="3">3 เดือน</option>
                            <option value="6" selected>6 เดือน</option>
                            <option value="12">12 เดือน</option>
                        </select>
                        <div class="form-text text-muted" style="font-size:0.75rem;">ตรวจถึง <b>7 วัน</b> หลังวันให้คีโม (คงที่)</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold mb-1">คัดกรอง Lab: ย้อนหลังก่อนให้คีโม</label>
                        <select id="lab_screen_months" class="form-select form-select-sm border-warning fw-bold">
                            <option value="3">3 เดือน</option>
                            <option value="6">6 เดือน</option>
                            <option value="12" selected>1 ปี (12 เดือน)</option>
                            <option value="24">2 ปี (24 เดือน)</option>
                            <option value="36">3 ปี (36 เดือน)</option>
                        </select>
                        <div class="form-text text-muted" style="font-size:0.75rem;">ตรวจ HBsAg / Anti-HBc ก่อนวันให้คีโม</div>
                    </div>
                </div>
                <div class="row g-2 mt-2">
                    <div class="col-12">
                        <button class="btn btn-primary btn-sm fw-bold shadow-sm px-4" onclick="fetchHBVData()">
                            <i class="fas fa-sync-alt me-1"></i> ประมวลผล / ดึงรายงาน
                        </button>
                    </div>
                </div>
                <div id="reportMeta" class="alert alert-info py-2 px-3 mt-3 mb-0 small fw-bold d-none"></div>
            </div>

            <div class="section-hard mb-4">
                <h6 class="fw-bold text-danger mb-2"><i class="fas fa-exclamation-circle me-2"></i>Hard Alert <span id="hardCount" class="badge bg-danger">0</span></h6>
                <div class="table-responsive">
                    <table class="table table-hover align-middle border mb-0">
                        <thead class="text-center text-white">
                            <tr>
                                <th>HN / Visit</th><th class="text-start">ชื่อ-สกุล</th><th>วันที่ให้ยา</th>
                                <th class="text-start">รายการยาเสี่ยง</th><th>ผล Lab</th><th>ยาป้องกัน</th>
                                <th class="text-start">สาเหตุ Alert</th><th>จัดการ</th>
                            </tr>
                        </thead>
                        <tbody id="hardTbody"><tr><td colspan="8" class="text-center py-4 text-muted">กดปุ่มประมวลผลเพื่อดึงข้อมูล</td></tr></tbody>
                    </table>
                </div>
            </div>

            <div class="section-soft mb-4">
                <h6 class="fw-bold text-warning mb-2"><i class="fas fa-exclamation-triangle me-2"></i>Soft Alert <span id="softCount" class="badge bg-warning text-dark">0</span></h6>
                <div class="table-responsive">
                    <table class="table table-hover align-middle border mb-0">
                        <thead class="text-center">
                            <tr>
                                <th>HN / Visit</th><th class="text-start">ชื่อ-สกุล</th><th>วันที่ให้ยา</th>
                                <th class="text-start">รายการยาเสี่ยง</th><th>ผล Lab</th><th>ยาป้องกัน</th>
                                <th class="text-start">สาเหตุ Alert</th><th>จัดการ</th>
                            </tr>
                        </thead>
                        <tbody id="softTbody"><tr><td colspan="8" class="text-center py-4 text-muted">กดปุ่มประมวลผลเพื่อดึงข้อมูล</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="protected">
            <div class="alert alert-success border-success small mb-3">
                <i class="fas fa-info-circle me-1"></i> รายการด้านล่างนี้คือผู้ป่วยที่มีผล HBsAg หรือ Anti-HBc เป็นบวก แต่ <b>ได้รับยาต้านไวรัสป้องกันเรียบร้อยแล้ว</b> ตามช่วงเวลาที่กำหนด
            </div>
            <div class="section-success">
                <h6 class="fw-bold text-success mb-2"><i class="fas fa-shield-check me-2"></i>รายการที่ได้รับยาป้องกัน (Protected Cases) <span id="protectedCount" class="badge bg-success">0</span></h6>
                <div class="table-responsive">
                    <table class="table table-hover align-middle border mb-0">
                        <thead class="text-center">
                            <tr>
                                <th>HN / Visit</th><th class="text-start">ชื่อ-สกุล</th><th>วันที่ให้ยา</th>
                                <th class="text-start">รายการยาเสี่ยง</th><th>ผล Lab</th><th>ยาป้องกัน</th>
                                <th class="text-start">สถานะ</th><th>จัดการ</th>
                            </tr>
                        </thead>
                        <tbody id="protectedTbody"><tr><td colspan="8" class="text-center py-4 text-muted">กดปุ่มประมวลผลที่แท็บหลักเพื่อดึงข้อมูล</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="screened">
            <div class="alert alert-info border-info small mb-3">
                <i class="fas fa-info-circle me-1"></i> รายการด้านล่างนี้คือผู้ป่วยที่ได้รับการคัดกรองแล้ว และมีผล <b>HBsAg / Anti-HBc เป็นลบ (Negative)</b>
            </div>
            <div class="section-screened">
                <h6 class="fw-bold text-info mb-2"><i class="fas fa-vial-circle-check me-2"></i>รายการที่คัดกรองแล้ว (Negative Cases) <span id="screenedCount" class="badge bg-info text-dark">0</span></h6>
                <div class="table-responsive">
                    <table class="table table-hover align-middle border mb-0">
                        <thead class="text-center">
                            <tr>
                                <th>HN / Visit</th><th class="text-start">ชื่อ-สกุล</th><th>วันที่ให้ยา</th>
                                <th class="text-start">รายการยาเสี่ยง</th><th>ผล Lab</th><th>ยาป้องกัน</th>
                                <th class="text-start">สถานะ</th><th>จัดการ</th>
                            </tr>
                        </thead>
                        <tbody id="screenedTbody"><tr><td colspan="8" class="text-center py-4 text-muted">กดปุ่มประมวลผลที่แท็บหลักเพื่อดึงข้อมูล</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="tab-pane fade <?php echo (isset($_GET['tab']) && $_GET['tab'] == 'settings') ? 'show active' : ''; ?>" id="settings">
            <div class="alert alert-light border small mb-3">
                <b>เกณฑ์ Clinical Decision:</b>
                HBsAg (+) → Hard ต้องให้ยาป้องกันทุกราย |
                Anti-HBc (+) + ยาเสี่ยงสูง → Hard ต้องให้ยาป้องกัน |
                Anti-HBc (+) + ยาเสี่ยงปานกลาง → Soft ติดตาม HBsAg/ALT ทุก 3 เดือน
            </div>
            <div class="row">
                <div class="col-md-5 border-end pr-4">
                    <h6 class="fw-bold text-primary mb-3"><i class="fas fa-plus-circle me-2"></i>เพิ่มตัวดักจับยา</h6>
                    <form method="POST" action="monitor_hbv.php">
                        <input type="hidden" name="action" value="add_hbv">
                        <div class="mb-3">
                            <label class="form-label fw-bold">ประเภทการ Monitor</label>
                            <select class="form-select border-primary" name="monitor_type" required>
                                <option value="hbv_required_drug">กลุ่มที่ 1: ยาต้านไวรัสป้องกัน (เช่น Tenofovir, Entecavir)</option>
                                <option value="hbv_high_risk_chemo">กลุ่มที่ 2: ยาเคมีความเสี่ยงสูง (Rituximab, Anti-CD20 ฯลฯ)</option>
                                <option value="hbv_moderate_risk_chemo">กลุ่มที่ 3: ยาเคมีความเสี่ยงปานกลาง (Doxorubicin, Epirubicin ฯลฯ)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">เลือกรายการยาจาก HOSxP</label>
                            <select class="form-select select2-drug" name="drug" required><?php echo $opt_drugs; ?></select>
                        </div>
                        <button type="submit" class="btn btn-success w-100 fw-bold shadow-sm"><i class="fas fa-save me-1"></i> บันทึกข้อมูลตั้งค่า</button>
                    </form>
                </div>
                <div class="col-md-7 ps-4">
                    <h6 class="fw-bold text-secondary mb-3"><i class="fas fa-list me-2"></i>รายการ Mapping ยาในระบบปัจจุบัน</h6>
                    <table class="table table-bordered table-sm align-middle">
                        <thead class="table-light text-center">
                            <tr><th>ประเภทกลุ่มงานเฝ้าระวัง</th><th>รายการเวชภัณฑ์</th><th>ลบ</th></tr>
                        </thead>
                        <tbody>
                            <?php
                            $q_set = mysqli_query($con, "SELECT * FROM monitor_hbv_settings WHERE monitor_type IN ('hbv_required_drug', 'hbv_high_risk_chemo', 'hbv_moderate_risk_chemo') ORDER BY monitor_type, drug_name ASC");
                            if (mysqli_num_rows($q_set) > 0) {
                                while ($s = mysqli_fetch_assoc($q_set)) {
                                    if ($s['monitor_type'] == 'hbv_required_drug') {
                                        $type_lbl = '<span class="badge bg-info">ยาต้านไวรัสป้องกัน</span>';
                                    } elseif ($s['monitor_type'] == 'hbv_high_risk_chemo') {
                                        $type_lbl = '<span class="badge bg-danger">คีโมเสี่ยงสูง</span>';
                                    } else {
                                        $type_lbl = '<span class="badge bg-warning text-dark">คีโมเสี่ยงปานกลาง</span>';
                                    }
                                    echo "<tr>
                                            <td class='text-center'>{$type_lbl}</td>
                                            <td class='fw-bold ps-2'>{$s['drug_name']} <small class='text-muted'>(icode: {$s['icode']})</small></td>
                                            <td class='text-center'>
                                                <form method='POST' style='display:inline;' onsubmit=\"return confirm('ยืนยันลบการตั้งค่ายานี้?');\">
                                                    <input type='hidden' name='action' value='delete'>
                                                    <input type='hidden' name='del_id' value='{$s['id']}'>
                                                    <button type='submit' class='btn btn-sm btn-outline-danger'><i class='fas fa-trash'></i></button>
                                                </form>
                                            </td>
                                          </tr>";
                                }
                            } else {
                                echo "<tr><td colspan='3' class='text-center text-muted py-3'>ยังไม่มีการตั้งค่าเงื่อนไขไวรัสบี</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
        </div>
    </div>
</div>

<script src="js/jquery-3.7.0.min.js"></script>
<script src="js/bootstrap.bundle.min.js"></script>
<script src="js/select2.min.js"></script>
<script src="js/flatpickr.min.js"></script>
<script>
    $(document).ready(function() {
        $('.select2-drug').select2({ theme: 'bootstrap-5', width: '100%' });

        let dStart = new Date();
        dStart.setFullYear(dStart.getFullYear() - 1);
        flatpickr('#filter_start', { dateFormat: 'Y-m-d', defaultDate: dStart });
        flatpickr('#filter_end', { dateFormat: 'Y-m-d', defaultDate: new Date() });

        if ($('#monitor-tab').hasClass('active')) { fetchHBVData(); }
    });

    function renderGroupedTable(groups, tbodyId, emptyMsg) {
        if (!groups || groups.length === 0) {
            $(tbodyId).html(`<tr><td colspan="8" class="text-center py-4 text-success fw-bold"><i class="fas fa-check-circle me-2"></i>${emptyMsg}</td></tr>`);
            return;
        }
        let html = '';
        groups.forEach(grp => {
            html += `<tr class="hn-group-header">
                <td colspan="8"><i class="fas fa-user-injured me-2 text-primary"></i>HN: <b>${grp.hn}</b> — ${grp.ptname} <span class="badge bg-secondary ms-2">${grp.visits.length} visit</span></td>
            </tr>`;
            grp.visits.forEach(v => {
                let vnLbl = v.vn ? ` (VN: ${v.vn})` : '';
                html += `<tr class="visit-row">
                    <td class="text-center small text-muted">↳ visit</td>
                    <td class="small">${grp.ptname}</td>
                    <td class="text-center fw-bold">${v.vstdate}${vnLbl}</td>
                    <td class="small">${v.risk_drugs}</td>
                    <td class="small">${v.lab_status}</td>
                    <td class="text-center small">${v.antiviral_status}</td>
                    <td class="small"><span class="badge ${v.alert_level === 'hard' ? 'bg-danger' : (v.alert_level === 'success' ? 'bg-success' : 'bg-warning text-dark')} me-1">${v.alert_type_th}</span><br>${v.details}</td>
                    <td class="text-center">
                        <a href="smart_drug.php?hn=${grp.hn}" target="_blank" class="btn btn-sm btn-dark fw-bold"><i class="fas fa-search"></i></a>
                    </td>
                </tr>`;
            });
        });
        $(tbodyId).html(html);
    }

    function fetchHBVData() {
        let sDate = $('#filter_start').val();
        let eDate = $('#filter_end').val();
        let coMonths = $('#co_med_months').val();
        let labMonths = $('#lab_screen_months').val();

        $('#reportMeta').addClass('d-none');
        let loading = '<tr><td colspan="8" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i>กำลังประมวลผล...</td></tr>';
        $('#hardTbody, #softTbody, #protectedTbody, #screenedTbody').html(loading);

        fetch(`fetch_hbv_monitor_api.php?start_date=${sDate}&end_date=${eDate}&co_med_months=${coMonths}&lab_screen_months=${labMonths}`)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                if (data.meta) {
                    $('#reportMeta').removeClass('d-none').html('<i class="fas fa-info-circle me-2"></i>' + data.meta.summary);
                    $('#hardCount').text(data.meta.hard_count || 0);
                    $('#softCount').text(data.meta.soft_count || 0);
                    $('#protectedCount').text(data.meta.protected_count || 0);
                    $('#screenedCount').text(data.meta.screened_negative_count || 0);
                }
                renderGroupedTable(data.hard_groups, '#hardTbody', 'ไม่พบ Hard Alert');
                renderGroupedTable(data.soft_groups, '#softTbody', 'ไม่พบ Soft Alert');
                renderGroupedTable(data.protected_groups, '#protectedTbody', 'ไม่มีผู้ป่วยที่เข้าเกณฑ์ได้รับยาป้องกันในเงื่อนไขนี้');
                renderGroupedTable(data.screened_negative_groups, '#screenedTbody', 'ไม่มีผู้ป่วยที่มีผล HBsAg / Anti-HBc เป็น Negative ในเงื่อนไขนี้');
            } else {
                $('#hardTbody, #softTbody, #protectedTbody, #screenedTbody').html(`<tr><td colspan="8" class="text-center text-danger py-4 fw-bold">${data.message}</td></tr>`);
            }
        })
        .catch(err => {
            console.error(err);
            $('#hardTbody, #softTbody, #protectedTbody, #screenedTbody').html('<tr><td colspan="8" class="text-center text-danger py-4 fw-bold"><i class="fas fa-wifi me-2"></i>เกิดข้อผิดพลาดในการเชื่อมต่อ</td></tr>');
        });
    }
</script>
</body>
</html>