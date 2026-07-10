<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
session_write_close(); 

// 1. เชื่อมต่อฐานข้อมูลระบบของเรา (Local DB)
include('connect.php'); 
mysqli_set_charset($con, "utf8");

// ==========================================
// 🔌 2. สร้างการเชื่อมต่อไปยังฐานข้อมูล HOSxP
// ==========================================
$config = [];
$q_conf = mysqli_query($con, "SELECT config_name, config_value FROM sys_config");
if($q_conf){
    while($r = mysqli_fetch_assoc($q_conf)){
        $config[$r['config_name']] = $r['config_value'];
    }
}

$hos_host = $config['h_host'] ?? '';
$hos_user = $config['h_user'] ?? '';
$hos_pass = $config['h_pass'] ?? '';
$hos_db   = $config['h_db']   ?? 'hos';

$hos_con = null;
try {
    mysqli_report(MYSQLI_REPORT_OFF); 
    
    $hos_con = mysqli_init();
    mysqli_options($hos_con, MYSQLI_OPT_CONNECT_TIMEOUT, 3);
    @mysqli_real_connect($hos_con, $hos_host, $hos_user, $hos_pass, $hos_db);
    
    if (!$hos_con) {
        throw new Exception(mysqli_connect_error());
    }
    mysqli_set_charset($hos_con, "utf8");

} catch (Exception $e) {
    die("
    <!DOCTYPE html><html lang='th'><head><meta charset='UTF-8'><link href='css/bootstrap.min.css' rel='stylesheet'><link href='css/all.min.css' rel='stylesheet'></head>
    <body class='bg-light'>
        <div class='container mt-5 max-w-md'>
            <div class='alert alert-danger text-center fw-bold shadow-sm p-4'>
                <i class='fas fa-plug fa-3x mb-3 text-danger d-block'></i>
                <h4 class='fw-bold'>ไม่สามารถเชื่อมต่อฐานข้อมูล HOSxP ได้</h4>
                <p class='text-muted small'>เซิร์ฟเวอร์ปลายทางไม่ตอบสนอง หรือตั้งค่าไม่ถูกต้อง</p>
                <p class='text-danger small'><b>Error:</b> " . htmlspecialchars($e->getMessage()) . "</p>
                <a href='hosxp_setting.php' class='btn btn-danger mt-3'><i class='fas fa-cogs me-1'></i> ไปตั้งค่าการเชื่อมต่อ</a>
            </div>
        </div>
    </body></html>");
}
// ==========================================


/** แปลงเวลา HOSxP (เช่น 125700, 03:40:36) เป็น HH:MM */
function smart_lab_format_time($time) {
    $time = trim((string)$time);
    if ($time === '') {
        return '';
    }
    if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $m)) {
        return sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
    }
    if (preg_match('/^\d{6}$/', $time)) {
        return substr($time, 0, 2) . ':' . substr($time, 2, 2);
    }
    if (preg_match('/^\d{5}$/', $time)) {
        return '0' . substr($time, 0, 1) . ':' . substr($time, 1, 2);
    }
    return $time;
}

/** วันที่+เวลา Lab สำหรับแสดงผล — report ก่อน order */
function smart_lab_dt_label(array $row) {
    $date = trim((string)($row['report_date'] ?? ''));
    if ($date === '' || $date === '0000-00-00') {
        $date = trim((string)($row['order_date'] ?? ''));
    }
    $time = trim((string)($row['report_time'] ?? ''));
    if ($time === '') {
        $time = trim((string)($row['order_time'] ?? ''));
    }
    if ($date === '' || $date === '0000-00-00') {
        return '-';
    }
    $date_fmt = date('d/m/Y', strtotime($date));
    $time_fmt = smart_lab_format_time($time);
    return $time_fmt !== '' ? $date_fmt . ' ' . $time_fmt : $date_fmt;
}

// ==========================================
// 🎯 3. ส่วน AJAX สำหรับดึงข้อมูลวาดกราฟ / แสดงข้อความ
// ==========================================
if (isset($_GET['ajax_graph'])) {
    error_reporting(0);
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    $raw_ajax_hn = isset($_GET['hn']) ? trim($_GET['hn']) : '';
    
    if (!empty($raw_ajax_hn) && is_numeric($raw_ajax_hn) && strlen($raw_ajax_hn) < 9) {
        $raw_ajax_hn = str_pad($raw_ajax_hn, 9, "0", STR_PAD_LEFT);
    }

    $hn = mysqli_real_escape_string($hos_con, $raw_ajax_hn);
    $lab_code = mysqli_real_escape_string($hos_con, $_GET['lab_code']);
    
    // คิวรีดึงประวัติ Lab ย้อนหลัง 10 ครั้ง
    $sql_history = "SELECT h.lab_order_number,
                           h.order_date, h.order_time,
                           h.report_date, h.report_time,
                           o.lab_order_result, i.lab_items_normal_value
                    FROM lab_head h
                    JOIN lab_order o ON h.lab_order_number = o.lab_order_number
                    LEFT JOIN lab_items i ON o.lab_items_code = i.lab_items_code
                    WHERE h.hn = '$hn'
                      AND o.lab_items_code = '$lab_code'
                      AND h.confirm_report = 'Y'
                    ORDER BY h.lab_order_number DESC
                    LIMIT 10";
                    
    $q_history = mysqli_query($hos_con, $sql_history); 
    
    $dates = [];
    $values = [];
    $raw_values = [];
    $normal_range = "";
    
    $text_count = 0;
    $num_count = 0;
    
    if ($q_history && mysqli_num_rows($q_history) > 0) {
        while ($row = mysqli_fetch_assoc($q_history)) {
            $raw_val = trim($row['lab_order_result']);
            
            $dates[] = smart_lab_dt_label($row);
            $raw_values[] = $raw_val; 
            
            // กรองเอาเฉพาะตัวเลขสำหรับกราฟ (เอาลูกน้ำออกด้วย)
            $val = preg_replace('/[^0-9.-]/', '', $raw_val);
            $values[] = floatval($val);
            
            if (empty($normal_range) && !empty($row['lab_items_normal_value'])) {
                $normal_range = $row['lab_items_normal_value'];
            }

            // --- แก้ไขตรงนี้: ลบลูกน้ำออกก่อนทำการเช็คว่าเป็นตัวเลขหรือไม่ ---
            if ($raw_val !== '') {
                $clean_check_val = str_replace(',', '', $raw_val); // เอา , ออกชั่วคราว
                
                if(preg_match('/^[<>=]?\s*[-+]?\d*\.?\d+\s*$/', $clean_check_val)) {
                    $num_count++;
                } else {
                    $text_count++; 
                }
            }
        }
        
        $data_type = ($text_count > 0 && $text_count >= $num_count) ? 'text' : 'numeric';
        
        if ($data_type === 'numeric') {
            $dates = array_reverse($dates);
            $values = array_reverse($values);
        }

        echo json_encode([
            'status' => 'success', 
            'data_type' => $data_type,
            'dates' => $dates, 
            'values' => $values, 
            'raw_values' => $raw_values,
            'normal_range' => $normal_range
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบประวัติผล Lab ย้อนหลัง']);
    }
    exit;
}
// ==========================================


// ตัวแปรค้นหา
$raw_hn = isset($_GET['hn']) ? trim($_GET['hn']) : '';
if (!empty($raw_hn) && is_numeric($raw_hn) && strlen($raw_hn) < 9) {
    $raw_hn = str_pad($raw_hn, 9, "0", STR_PAD_LEFT);
}

$search_hn = mysqli_real_escape_string($hos_con, $raw_hn);
$patient_name = "";
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0"> 
    <title>Smart Lab - THAM SAFE</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
    <style>
        .table-custom { box-shadow: 0 4px 15px rgba(0,0,0,0.05); border-radius: 10px; overflow: hidden; }
        .table-custom th { background-color: #f8f9fa; color: #495057; }
        #chartContainer { position: relative; height: 340px; width: 100%; }
        .history-scroll { max-height: 500px; overflow-y: auto; }
        .history-scroll::-webkit-scrollbar { width: 6px; }
        .history-scroll::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 10px; }
    </style>
</head>
<body class="bg-light">

<div class="wrapper">
    <?php include('sidebar.php'); ?>

    <div id="content" class="content">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="fw-bold mb-0 text-dark"><i class="fas fa-microscope me-2 text-info"></i>ระบบ Smart Lab</h4>
        </div>

<div class="container-fluid">
    
    <div class="card border-0 shadow-sm mb-4" style="border-radius: 15px;">
        <div class="card-body p-4">
            <form method="GET" action="smart_lab.php" class="row g-3 align-items-end">
                <div class="col-md-9 col-sm-12">
                    <label class="fw-bold text-secondary mb-1">กรอก HN ผู้ป่วย</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light text-info"><i class="fas fa-id-card"></i></span>
                        <input type="text" name="hn" class="form-control form-control-lg fw-bold text-info border-info" value="<?php echo htmlspecialchars($search_hn); ?>" required placeholder="ค้นหาประวัติ Lab...">
                    </div>
                </div>
                <div class="col-md-3 col-sm-12">
                    <button type="submit" class="btn btn-info text-white btn-lg w-100 fw-bold shadow-sm">
                        <i class="fas fa-search me-2"></i> ค้นหา
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if (!empty($search_hn)): ?>
    
        <?php
        $q_pt = mysqli_query($hos_con, "SELECT CONCAT(pname, fname, ' ', lname) as fullname FROM patient WHERE hn = '$search_hn' LIMIT 1");
        if ($pt = mysqli_fetch_assoc($q_pt)) { $patient_name = $pt['fullname']; }
        else { $patient_name = "ไม่พบชื่อในระบบ HOSxP"; }
        
        $sql_all_labs = "SELECT lab_order_number, order_date, order_time 
                         FROM lab_head WHERE hn = '$search_hn' AND confirm_report = 'Y' 
                         ORDER BY order_date DESC, order_time DESC";
        $q_all_labs = mysqli_query($hos_con, $sql_all_labs);
        
        $lab_history = [];
        while($row = mysqli_fetch_assoc($q_all_labs)){
            $lab_history[] = $row;
        }

        $selected_order = isset($_GET['lab_order']) ? mysqli_real_escape_string($hos_con, $_GET['lab_order']) : '';
        $selected_date = "";

        if (count($lab_history) > 0): 
            if(empty($selected_order)){
                $selected_order = $lab_history[0]['lab_order_number'];
            }
            
            foreach($lab_history as $h) {
                if($h['lab_order_number'] == $selected_order) {
                    $selected_date = date('d/m/Y', strtotime($h['order_date'])) . ' (' . $h['order_time'] . ' น.)';
                    break;
                }
            }
        ?>
        
        <div class="alert alert-success fw-bold shadow-sm mb-3">
            <i class="fas fa-user-check me-2"></i> ผู้ป่วย: <span class="text-dark"><?php echo $patient_name; ?></span> 
        </div>

        <div class="row">
            <div class="col-md-4 col-lg-3 mb-4">
                <div class="card border-0 shadow-sm table-custom">
                    <div class="card-header bg-secondary text-white fw-bold py-3">
                        <i class="fas fa-calendar-alt me-1"></i> ประวัติวันที่ตรวจ
                    </div>
                    <div class="list-group list-group-flush history-scroll">
                        <?php foreach($lab_history as $history): 
                            $is_active = ($history['lab_order_number'] == $selected_order) ? 'active bg-info border-info' : '';
                            $date_show = date('d/m/Y', strtotime($history['order_date']));
                            $time_show = $history['order_time'];
                        ?>
                            <a href="?hn=<?= urlencode($search_hn) ?>&lab_order=<?= urlencode($history['lab_order_number']) ?>" 
                               class="list-group-item list-group-item-action <?= $is_active ?>">
                                <i class="fas fa-flask me-2"></i> <?= $date_show ?> 
                                <small class="float-end <?= $is_active ? 'text-white' : 'text-muted' ?>"><?= $time_show ?></small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-8 col-lg-9">
                <h5 class="fw-bold text-info mb-3"><i class="fas fa-file-medical-alt me-2"></i> ผลการตรวจวันที่: <?= $selected_date ?></h5>
                
                <div class="card border-0 table-custom">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th class="py-3 px-4">รายการ Lab (Lab Items)</th>
                                        <th class="py-3 text-center">ผลลัพธ์ (Result)</th>
                                        <th class="py-3 text-center">ค่าอ้างอิง (Normal Range)</th>
                                        <th class="text-center py-3">กราฟ/ประวัติ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $sql_items = "SELECT o.lab_items_code, o.lab_items_name_ref, o.lab_order_result, i.lab_items_normal_value 
                                                  FROM lab_order o
                                                  LEFT JOIN lab_items i ON o.lab_items_code = i.lab_items_code
                                                  WHERE o.lab_order_number = '$selected_order'
                                                  ORDER BY o.lab_items_name_ref ASC";
                                    $q_items = mysqli_query($hos_con, $sql_items);
                                    
                                    while ($item = mysqli_fetch_assoc($q_items)) {
                                        $lab_name = htmlspecialchars($item['lab_items_name_ref'] ?? '');
                                        $lab_code = htmlspecialchars($item['lab_items_code'] ?? '');
                                        $result = htmlspecialchars($item['lab_order_result'] ?? '-'); 
                                        $normal = htmlspecialchars($item['lab_items_normal_value'] ?? '');
                                        
                                        echo "<tr>";
                                        echo "<td class='px-4 fw-bold text-secondary'>{$lab_name}</td>";
                                        echo "<td class='text-center fs-5 text-dark fw-bold'>{$result}</td>";
                                        echo "<td class='text-center text-muted'><small>{$normal}</small></td>";
                                        echo "<td class='text-center'>
                                                <button class='btn btn-sm btn-outline-info fw-bold' onclick=\"openGraphModal('{$search_hn}', '{$lab_code}', '{$lab_name}')\">
                                                    <i class='fas fa-search-plus me-1'></i> ดูแนวโน้ม
                                                </button>
                                              </td>";
                                        echo "</tr>";
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php else: ?>
            <div class="alert alert-warning text-center py-4 fw-bold shadow-sm">
                <i class="fas fa-exclamation-triangle fa-2x mb-2"></i><br>
                ไม่พบประวัติผล Lab ที่ยืนยันแล้วของ HN: <?php echo $search_hn; ?>
            </div>
        <?php endif; ?>
        
    <?php endif; ?>
</div>

    </div>
</div>

<div class="modal fade" id="graphModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered"> 
    <div class="modal-content">
      <div class="modal-header bg-info text-white py-3">
        <h5 class="modal-title fw-bold" id="modalLabTitle"><i class="fas fa-chart-area me-2"></i> แนวโน้มค่า Lab</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4 bg-light text-center">
          
          <div id="chartLoading" style="display: none;">
              <i class="fas fa-spinner fa-spin fa-3x text-info mb-3"></i>
              <p class="fw-bold text-secondary">กำลังโหลดข้อมูลประวัติ...</p>
          </div>
          
          <div id="chartContainer" style="display: none;">
              <canvas id="labChart"></canvas>
          </div>
          
          <div id="textContainer" style="display: none;" class="text-start mt-2"></div>
          
          <div class="mt-3 p-2 bg-white rounded shadow-sm border">
              <span class="fw-bold text-secondary">ค่าอ้างอิงปกติ (Normal Value):</span> 
              <span class="fw-bold text-dark fs-5 ms-2" id="modalNormalRange">-</span>
          </div>
          
      </div>
    </div>
  </div>
</div>

<script src="js/chart.min.js"></script>
<script src="js/bootstrap.bundle.min.js"></script>

<script>
let labChartInstance = null; 

function openGraphModal(hn, labCode, labName) {
    document.getElementById('modalLabTitle').innerHTML = `<i class="fas fa-history me-2"></i> ประวัติค่า ${labName} (10 ครั้งล่าสุด)`;
    document.getElementById('modalNormalRange').innerText = '-';
    
    const graphModal = new bootstrap.Modal(document.getElementById('graphModal'));
    graphModal.show();
    
    document.getElementById('chartLoading').style.display = 'block';
    document.getElementById('chartContainer').style.display = 'none';
    document.getElementById('textContainer').style.display = 'none';
    
    fetch(`smart_lab.php?ajax_graph=true&hn=${hn}&lab_code=${labCode}`)
    .then(response => response.json())
    .then(data => {
        document.getElementById('chartLoading').style.display = 'none';
        
        if (data.status === 'success') {
            document.getElementById('modalNormalRange').innerText = data.normal_range || 'ไม่มีข้อมูลในระบบ';
            
            // ตรวจสอบชนิดข้อมูล
            if (data.data_type === 'text') {
                document.getElementById('chartContainer').style.display = 'none';
                document.getElementById('textContainer').style.display = 'block';
                
                let html = '<ul class="list-group shadow-sm">';
                for(let i=0; i<data.dates.length; i++) {
                    let val = data.raw_values[i];
                    let valLower = val.toLowerCase();
                    let badgeClass = 'bg-secondary';
                    
                    if(valLower.includes('positive') || val.includes('+') || valLower.includes('abnormal') || valLower.includes('reactive')) {
                        badgeClass = 'bg-danger';
                    } else if(valLower.includes('negative') || valLower.includes('normal') || valLower.includes('clear') || valLower.includes('non-reactive')) {
                        badgeClass = 'bg-success';
                    } else if(valLower.includes('trace') || valLower.includes('turbid')) {
                        badgeClass = 'bg-warning text-dark';
                    }
                    
                    html += `<li class="list-group-item d-flex justify-content-between align-items-center p-3">
                                <span class="fw-bold text-secondary"><i class="fas fa-calendar-day me-2 text-info"></i> ${data.dates[i]}</span>
                                <span class="badge ${badgeClass} rounded-pill fs-6 px-3 py-2">${val || '-'}</span>
                             </li>`;
                }
                html += '</ul>';
                document.getElementById('textContainer').innerHTML = html;
                
            } else {
                document.getElementById('textContainer').style.display = 'none';
                document.getElementById('chartContainer').style.display = 'block';
                renderChart(labName, data.dates, data.values);
            }
            
        } else {
            document.getElementById('chartContainer').innerHTML = `<div class="alert alert-danger mt-4">${data.message}</div>`;
            document.getElementById('chartContainer').style.display = 'block';
        }
    })
    .catch(error => {
        document.getElementById('chartLoading').style.display = 'none';
        alert('เกิดข้อผิดพลาดในการดึงข้อมูล');
    });
}

function renderChart(labName, labels, dataValues) {
    const ctx = document.getElementById('labChart').getContext('2d');
    
    if (labChartInstance) {
        labChartInstance.destroy();
    }
    
    labChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: `ค่า ${labName}`,
                data: dataValues,
                borderColor: '#0dcaf0',
                backgroundColor: 'rgba(13, 202, 240, 0.2)',
                borderWidth: 3,
                pointBackgroundColor: '#fff',
                pointBorderColor: '#0dcaf0',
                pointBorderWidth: 2,
                pointRadius: 5,
                pointHoverRadius: 7,
                fill: true,
                tension: 0.3 
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title: function(context) {
                            return context[0].label;
                        },
                        label: function(context) {
                            return `ผลลัพธ์: ${context.parsed.y.toLocaleString()}`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: false,
                    grid: { color: '#f0f0f0' }
                },
                x: {
                    grid: { display: false },
                    ticks: {
                        maxRotation: 55,
                        minRotation: 55,
                        autoSkip: true,
                        maxTicksLimit: 10,
                        font: { size: 10 }
                    }
                }
            }
        }
    });
}
</script>

</body>
</html>