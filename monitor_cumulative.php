<?php
session_start();
if (!isset($_SESSION['user_id']) && !isset($_SESSION['full_name'])) { header("Location: login.php"); exit; }
include('connect.php');
mysqli_set_charset($con, "utf8");

// จัดการบันทึก/ลบ การตั้งค่า
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] == 'add') {
        $icode = mysqli_real_escape_string($con, explode('|', $_POST['drug'])[0]);
        $drug_name = mysqli_real_escape_string($con, explode('|', $_POST['drug'])[1]);
        $cond = mysqli_real_escape_string($con, $_POST['condition_type']);
        $val1 = floatval($_POST['val1']);
        $val2 = isset($_POST['val2']) ? floatval($_POST['val2']) : 0;
        
        mysqli_query($con, "INSERT INTO monitor_settings (icode, drug_name, condition_type, val1, val2) VALUES ('$icode', '$drug_name', '$cond', '$val1', '$val2')");
        header("Location: monitor_cumulative.php?tab=settings");
        exit;
    }
    if (isset($_POST['action']) && $_POST['action'] == 'delete') {
        $id = intval($_POST['del_id']);
        mysqli_query($con, "DELETE FROM monitor_settings WHERE id = $id");
        header("Location: monitor_cumulative.php?tab=settings");
        exit;
    }
}

// ดึงค่า Config เพื่อต่อ HOSxP และดึงรายชื่อยา
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
    <title>Monitor Cumulative Dose - THAM CYTO</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
    <link href="css/select2.min.css" rel="stylesheet">
    <link href="css/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', Tahoma, sans-serif; background-color: #f4f7f6; }
        .btn-glow-info { animation: glow-info 1.2s infinite alternate; }
        @keyframes glow-info { from { box-shadow: 0 0 2px #0dcaf0; } to { box-shadow: 0 0 10px #0dcaf0; } }
    </style>
</head>
<body>

<div class="wrapper">
    <?php include('sidebar.php'); ?>
    <div id="content" class="content">
        <div class="container-fluid">
            
            <h4 class="fw-bold mb-4 text-dark"><i class="fas fa-exclamation-triangle me-2 text-warning"></i>เฝ้าระวังขนาดยาสะสม (Cumulative Dose Monitor)</h4>

            <ul class="nav nav-tabs fw-bold" id="myTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo (!isset($_GET['tab']) || $_GET['tab'] != 'settings') ? 'active' : ''; ?> text-primary" id="monitor-tab" data-bs-toggle="tab" data-bs-target="#monitor" type="button" role="tab"><i class="fas fa-desktop me-1"></i> รายการผู้ป่วยที่ต้องเฝ้าระวัง</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo (isset($_GET['tab']) && $_GET['tab'] == 'settings') ? 'active' : ''; ?> text-secondary" id="settings-tab" data-bs-toggle="tab" data-bs-target="#settings" type="button" role="tab"><i class="fas fa-cogs me-1"></i> ตั้งค่าเงื่อนไข</button>
                </li>
            </ul>

            <div class="tab-content bg-white border border-top-0 p-4 rounded-bottom shadow-sm" id="myTabContent">
                
                <div class="tab-pane fade <?php echo (!isset($_GET['tab']) || $_GET['tab'] != 'settings') ? 'show active' : ''; ?>" id="monitor" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold text-danger"><i class="fas fa-users me-2"></i>รายชื่อผู้ป่วยที่เข้าเกณฑ์เงื่อนไข</h6>
                        <button class="btn btn-sm btn-primary fw-bold shadow-sm" onclick="fetchMonitorData()"><i class="fas fa-sync-alt me-1"></i> อัปเดตข้อมูล</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle border">
                            <thead class="table-danger text-center">
                                <tr>
                                    <th width="10%">HN</th>
                                    <th width="20%" class="text-start">ชื่อ-สกุล</th>
                                    <th width="20%" class="text-start">รายการยาที่เฝ้าระวัง</th>
                                    <th width="15%">Cumulative Dose</th>
                                    <th width="20%">เงื่อนไขที่ตั้งไว้</th>
                                    <th width="15%">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody id="monitorTbody">
                                <tr><td colspan="6" class="text-center py-5 text-muted"><i class="fas fa-spinner fa-spin fa-2x mb-3 d-block"></i>กำลังประมวลผลข้อมูลจาก HOSxP...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tab-pane fade <?php echo (isset($_GET['tab']) && $_GET['tab'] == 'settings') ? 'show active' : ''; ?>" id="settings" role="tabpanel">
                    <div class="row">
                        <div class="col-md-5 border-end pr-4">
                            <h6 class="fw-bold text-primary mb-3"><i class="fas fa-plus-circle me-2"></i>เพิ่มเงื่อนไขใหม่</h6>
                            <form method="POST" action="monitor_cumulative.php">
                                <input type="hidden" name="action" value="add">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">เลือกรายการยา</label>
                                    <select class="form-select select2-drug" name="drug" required><?php echo $opt_drugs; ?></select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">เงื่อนไข (Condition)</label>
                                    <select class="form-select border-info fw-bold" name="condition_type" id="cond_type" onchange="toggleVal2()" required>
                                        <optgroup label="คำนวณแบบปกติตามปริมาณยา (mg)">
                                            <option value=">">มากกว่า (mg)</option>
                                            <option value="<">น้อยกว่า (mg)</option>
                                            <option value="BETWEEN">อยู่ระหว่าง (mg)</option>
                                        </optgroup>
                                        <optgroup label="คำนวณตามพื้นที่ผิวร่างกาย (mg/m²)">
                                            <option value=">_bsa">มากกว่า (mg/m²)</option>
                                            <option value="<_bsa">น้อยกว่า (mg/m²)</option>
                                            <option value="BETWEEN_bsa">อยู่ระหว่าง (mg/m²)</option>
                                        </optgroup>
                                    </select>
                                </div>
                                <div class="row g-2 mb-4">
                                    <div class="col-6">
                                        <label class="form-label fw-bold text-danger" id="lbl_val1">ค่าที่กำหนด (mg)</label>
                                        <input type="number" step="0.01" class="form-control border-danger fw-bold" name="val1" required>
                                    </div>
                                    <div class="col-6 d-none" id="div_val2">
                                        <label class="form-label fw-bold text-danger" id="lbl_val2">ถึงค่า (mg)</label>
                                        <input type="number" step="0.01" class="form-control border-danger fw-bold" name="val2" id="input_val2">
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-success w-100 fw-bold shadow-sm"><i class="fas fa-save me-1"></i> บันทึกเงื่อนไข</button>
                            </form>
                        </div>
                        <div class="col-md-7 ps-4">
                            <h6 class="fw-bold text-secondary mb-3"><i class="fas fa-list me-2"></i>รายการเงื่อนไขที่ใช้งานอยู่</h6>
                            <table class="table table-bordered table-sm">
                                <thead class="table-light text-center">
                                    <tr><th>รายการยา</th><th>เงื่อนไข</th><th>จัดการ</th></tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $q_set = mysqli_query($con, "SELECT * FROM monitor_settings ORDER BY drug_name ASC");
                                    if(mysqli_num_rows($q_set) > 0) {
                                        while($s = mysqli_fetch_assoc($q_set)) {
                                            $is_bsa = (strpos($s['condition_type'], '_bsa') !== false);
                                            $unit = $is_bsa ? 'mg/m²' : 'mg';
                                            $base_type = str_replace('_bsa', '', $s['condition_type']);

                                            $cond_text = "";
                                            if($base_type == 'BETWEEN') $cond_text = "อยู่ระหว่าง " . $s['val1'] . " - " . $s['val2'] . " " . $unit;
                                            else $cond_text = $base_type . " " . $s['val1'] . " " . $unit;
                                            
                                            echo "<tr>
                                                    <td class=".'fw-bold text-primary'.">{$s['drug_name']}</td>
                                                    <td class=".'text-center fw-bold text-danger'.">{$cond_text}</td>
                                                    <td class=".'text-center'.">
                                                        <form method='POST' style='display:inline;' onsubmit=\"return confirm('ยืนยันลบเงื่อนไขนี้?');\">
                                                            <input type='hidden' name='action' value='delete'>
                                                            <input type='hidden' name='del_id' value='{$s['id']}'>
                                                            <button type='submit' class='btn btn-sm btn-outline-danger'><i class='fas fa-trash'></i></button>
                                                        </form>
                                                    </td>
                                                  </tr>";
                                        }
                                    } else {
                                        echo "<tr><td colspan='3' class='text-center text-muted py-3'>ยังไม่มีการตั้งค่าเงื่อนไข</td></tr>";
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
<script>
    $(document).ready(function() {
        $('.select2-drug').select2({ theme: 'bootstrap-5', width: '100%' });
        if($('#monitor-tab').hasClass('active')) { fetchMonitorData(); }
    });

    function toggleVal2() {
        let type = $('#cond_type').val();
        let isBsa = type.includes('_bsa');
        let unit = isBsa ? 'mg/m²' : 'mg';

        if(type.includes('BETWEEN')) {
            $('#div_val2').removeClass('d-none');
            $('#input_val2').prop('required', true);
            $('#lbl_val1').text(`ตั้งแต่ค่า (${unit})`);
            $('#lbl_val2').text(`ถึงค่า (${unit})`);
        } else {
            $('#div_val2').addClass('d-none');
            $('#input_val2').prop('required', false);
            $('#lbl_val1').text(`ค่าที่กำหนด (${unit})`);
        }
    }

    function fetchMonitorData() {
        $('#monitorTbody').html('<tr><td colspan="6" class="text-center py-5 text-muted"><i class="fas fa-spinner fa-spin fa-2x mb-3 d-block"></i>กำลังประมวลผลข้อมูลจาก HOSxP (อาจใช้เวลาสักครู่)...</td></tr>');
        
        fetch('fetch_monitor_api.php')
        .then(res => res.json())
        .then(data => {
            if(data.status === 'success') {
                if(data.results.length === 0) {
                    $('#monitorTbody').html('<tr><td colspan="6" class="text-center py-4 text-success fw-bold"><i class="fas fa-check-circle fa-2x mb-2 d-block"></i>ไม่พบผู้ป่วยที่เข้าเกณฑ์เฝ้าระวัง</td></tr>');
                    return;
                }
                let html = '';
                data.results.forEach(item => {
                    html += `<tr>
                                <td class="text-center fw-bold text-primary">${item.hn}</td>
                                <td>${item.ptname}</td>
                                <td class="fw-bold text-dark">${item.drug_name}</td>
                                <td class="text-center">
                                    <div class="badge bg-danger text-wrap shadow-sm px-3 py-2" style="font-size: 0.9rem; line-height: 1.4;">
                                        ${item.total_dose_display}
                                    </div>
                                </td>
                                <td class="text-center text-muted"><small class="fw-bold">${item.condition_str}</small></td>
                                <td class="text-center">
                                    <a href="smart_drug.php?hn=${item.hn}" target="_blank" class="btn btn-sm btn-info text-white fw-bold shadow-sm btn-glow-info">
                                        <i class="fas fa-capsules me-1"></i> Smart Drug
                                    </a>
                                </td>
                             </tr>`;
                });
                $('#monitorTbody').html(html);
            } else {
                $('#monitorTbody').html(`<tr><td colspan="6" class="text-center text-danger py-4 fw-bold"><i class="fas fa-exclamation-triangle me-2"></i>${data.message}</td></tr>`);
            }
        })
        .catch(err => {
            console.error(err);
            $('#monitorTbody').html('<tr><td colspan="6" class="text-center text-danger py-4 fw-bold"><i class="fas fa-wifi me-2"></i>เกิดข้อผิดพลาดในการเชื่อมต่อ API</td></tr>');
        });
    }
</script>
</body>
</html>