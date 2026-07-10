<?php
/**
 * Admin: License Generator & Deployment Map Tracker
 * หน้านี้สำหรับเซิร์ฟเวอร์กลางของผู้พัฒนาเท่านั้น — ไม่ควรเปิดบนเครื่องรพ.
 */
session_start();
header('Content-Type: text/html; charset=utf-8');

include(__DIR__ . '/connect.php');
require_once __DIR__ . '/license_admin_helper.php';
mysqli_set_charset($con, 'utf8mb4');

$flash = ['type' => '', 'message' => '', 'license_content' => '', 'license_filename' => ''];
$deployments = license_admin_fetch_deployments($con);

// --- Backend: รับฟอร์ม Generate & Save ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $master_pin = trim($_POST['master_pin'] ?? '');
    $hospital_name = trim($_POST['hospital_name'] ?? '');
    $expire_date = trim($_POST['expire_date'] ?? '');
    $latitude = trim($_POST['latitude'] ?? '');
    $longitude = trim($_POST['longitude'] ?? '');

    // ขั้นที่ 1: ตรวจ Master PIN
    if ($master_pin !== LICENSE_MASTER_PIN) {
        $flash = ['type' => 'danger', 'message' => 'Master PIN ไม่ถูกต้อง — หยุดการทำงาน', 'license_content' => '', 'license_filename' => ''];
    } elseif ($hospital_name === '') {
        $flash = ['type' => 'warning', 'message' => 'กรุณาระบุชื่อโรงพยาบาล', 'license_content' => '', 'license_filename' => ''];
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expire_date)) {
        $flash = ['type' => 'warning', 'message' => 'รูปแบบวันหมดอายุไม่ถูกต้อง (ต้องเป็น YYYY-MM-DD)', 'license_content' => '', 'license_filename' => ''];
    } elseif (!is_numeric($latitude) || !is_numeric($longitude)) {
        $flash = ['type' => 'warning', 'message' => 'ละติจูด / ลองจิจูด ต้องเป็นตัวเลข', 'license_content' => '', 'license_filename' => ''];
    } else {
        $lat = (float)$latitude;
        $lng = (float)$longitude;

        // ขั้นที่ 2A: สร้าง License Key (AES-256-CBC + base64)
        $license_content = license_admin_generate_key_content($hospital_name, $expire_date);
        if ($license_content === null) {
            $flash = ['type' => 'danger', 'message' => 'ไม่สามารถเข้ารหัส License ได้', 'license_content' => '', 'license_filename' => ''];
        } else {
            // บันทึกไฟล์ license.key
            $license_filename = license_admin_write_license_file($hospital_name, $license_content);

            // ขั้นที่ 2B: บันทึก/อัปเดตข้อมูลลงแผนที่ (tb_deployments)
            $saved = license_admin_save_deployment($con, $hospital_name, $lat, $lng, $expire_date, $license_filename);

            if ($saved) {
                $deployments = license_admin_fetch_deployments($con);
                $flash = [
                    'type' => 'success',
                    'message' => 'สร้าง License และบันทึกลงแผนที่เรียบร้อยแล้ว' . ($license_filename ? " (ไฟล์: {$license_filename})" : ''),
                    'license_content' => $license_content,
                    'license_filename' => $license_filename ?: 'license.key',
                ];
            } else {
                $flash = [
                    'type' => 'warning',
                    'message' => 'สร้าง License ได้ แต่บันทึกฐานข้อมูลล้มเหลว: ' . mysqli_error($con),
                    'license_content' => $license_content,
                    'license_filename' => $license_filename ?: 'license.key',
                ];
            }
        }
    }
}

$default_lat = LICENSE_DEFAULT_LAT;
$default_lng = LICENSE_DEFAULT_LNG;
$map_points_json = json_encode(array_map(function ($r) {
    return [
        'id' => (int)$r['id'],
        'name' => $r['hospital_name'],
        'lat' => (float)$r['latitude'],
        'lng' => (float)$r['longitude'],
        'expire' => $r['expire_date'],
        'status' => $r['status'],
    ];
}, $deployments), JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>License Generator &amp; Deployment Map — THAM Hos-Safe Admin</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
    <link href="css/flatpickr.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
    <style>
        body { font-family: 'Sarabun', Tahoma, sans-serif; background: #f4f7f6; }
        #deploymentMap { height: 420px; border-radius: 8px; border: 1px solid #dee2e6; }
        .license-box { font-family: monospace; font-size: 0.75rem; word-break: break-all; max-height: 120px; overflow-y: auto; }
    </style>
</head>
<body>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="fw-bold mb-0">
            <i class="fas fa-key text-warning me-2"></i>License Generator &amp; Deployment Map
        </h4>
        <span class="badge bg-secondary"><?php echo count($deployments); ?> รพ.</span>
    </div>

    <?php if ($flash['message']): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flash['message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php if ($flash['license_content']): ?>
    <div class="card border-success mb-4">
        <div class="card-header bg-success text-white fw-bold">
            <i class="fas fa-file-download me-1"></i> License Key ที่สร้างแล้ว — ส่งไฟล์นี้ให้รพ.
        </div>
        <div class="card-body">
            <div class="license-box bg-light border rounded p-2 mb-2"><?php echo htmlspecialchars($flash['license_content']); ?></div>
            <button type="button" class="btn btn-sm btn-outline-primary" id="btnCopyLicense">
                <i class="fas fa-copy me-1"></i> คัดลอก
            </button>
            <a href="#" class="btn btn-sm btn-success" id="btnDownloadLicense" download="<?php echo htmlspecialchars($flash['license_filename']); ?>">
                <i class="fas fa-download me-1"></i> ดาวน์โหลด license.key
            </a>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white fw-bold">
                    <i class="fas fa-plus-circle text-primary me-1"></i> สร้าง License &amp; บันทึกแผนที่
                </div>
                <div class="card-body">
                    <form method="POST" id="licenseForm" autocomplete="off">
                        <div class="mb-3">
                            <label class="form-label fw-bold">ชื่อโรงพยาบาล <span class="text-danger">*</span></label>
                            <input type="text" name="hospital_name" class="form-control" required
                                placeholder="เช่น โรงพยาบาลสมุทรปราการ" maxlength="255">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">วันหมดอายุ <span class="text-danger">*</span></label>
                            <input type="text" name="expire_date" id="expire_date" class="form-control" required
                                placeholder="เลือกวันที่">
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-bold">ละติจูด (Latitude)</label>
                                <input type="text" name="latitude" id="latitude" class="form-control" required
                                    value="<?php echo $default_lat; ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-bold">ลองจิจูด (Longitude)</label>
                                <input type="text" name="longitude" id="longitude" class="form-control" required
                                    value="<?php echo $default_lng; ?>">
                            </div>
                        </div>
                        <p class="small text-muted mb-3">
                            <i class="fas fa-map-marker-alt me-1"></i>
                            ค่าเริ่มต้น = กรุงเทพฯ — ปรับตามพิกัด รพ. จาก Google Maps
                        </p>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Master PIN <span class="text-danger">*</span></label>
                            <input type="password" name="master_pin" class="form-control" required
                                placeholder="รหัสผ่าน Admin">
                        </div>
                        <button type="submit" class="btn btn-warning fw-bold w-100 shadow-sm">
                            <i class="fas fa-bolt me-1"></i> Generate &amp; Save to Map
                        </button>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm border-0 mt-3">
                <div class="card-body small text-muted">
                    <b>ขั้นตอนระบบ:</b><br>
                    1) ตรวจ Master PIN<br>
                    2) เข้ารหัส JSON (ชื่อ รพ. + วันหมดอายุ) ด้วย AES-256-CBC → บันทึก <code>license.key</code><br>
                    3) บันทึก/อัปเดต <code>tb_deployments</code> สำหรับแผนที่
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-white fw-bold">
                    <i class="fas fa-map text-danger me-1"></i> Deployment Spot Map
                </div>
                <div class="card-body p-2">
                    <div id="deploymentMap"></div>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-header bg-white fw-bold">
                    <i class="fas fa-list me-1"></i> รายการรพ.ที่ติดตั้ง
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>โรงพยาบาล</th>
                                <th class="text-center">Lat / Lng</th>
                                <th class="text-center">หมดอายุ</th>
                                <th class="text-center">สถานะ</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($deployments)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">ยังไม่มีข้อมูล — สร้าง License รายการแรกได้ทางซ้าย</td></tr>
                        <?php else: foreach ($deployments as $d):
                            $badge = $d['status'] === 'Active' ? 'success' : ($d['status'] === 'Expired' ? 'warning' : 'secondary');
                        ?>
                            <tr>
                                <td class="fw-bold"><?php echo htmlspecialchars($d['hospital_name']); ?></td>
                                <td class="text-center small text-muted">
                                    <?php echo htmlspecialchars($d['latitude']); ?><br>
                                    <?php echo htmlspecialchars($d['longitude']); ?>
                                </td>
                                <td class="text-center"><?php echo htmlspecialchars($d['expire_date']); ?></td>
                                <td class="text-center">
                                    <span class="badge bg-<?php echo $badge; ?>"><?php echo htmlspecialchars($d['status']); ?></span>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="js/jquery-3.7.0.min.js"></script>
<script src="js/bootstrap.bundle.min.js"></script>
<script src="js/flatpickr.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script>
const MAP_POINTS = <?php echo $map_points_json ?: '[]'; ?>;
const LICENSE_CONTENT = <?php echo json_encode($flash['license_content'] ?? '', JSON_UNESCAPED_UNICODE); ?>;

$(function() {
    // Datepicker — วันหมดอายุ (default +1 ปี)
    const nextYear = new Date();
    nextYear.setFullYear(nextYear.getFullYear() + 1);
    flatpickr('#expire_date', {
        dateFormat: 'Y-m-d',
        defaultDate: nextYear,
        locale: { firstDayOfWeek: 1 }
    });

    // แผนที่ Leaflet + OpenStreetMap
    const map = L.map('deploymentMap').setView([<?php echo $default_lat; ?>, <?php echo $default_lng; ?>], 6);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '&copy; OpenStreetMap'
    }).addTo(map);

    const bounds = [];
    MAP_POINTS.forEach(function(p) {
        const marker = L.marker([p.lat, p.lng]).addTo(map);
        marker.bindPopup(
            '<b>' + escapeHtml(p.name) + '</b><br>' +
            'หมดอายุ: ' + escapeHtml(p.expire) + '<br>' +
            'สถานะ: ' + escapeHtml(p.status)
        );
        bounds.push([p.lat, p.lng]);
    });
    if (bounds.length > 0) {
        map.fitBounds(bounds, { padding: [30, 30] });
    }

    // คัดลอก / ดาวน์โหลด license
    $('#btnCopyLicense').on('click', function() {
        if (!LICENSE_CONTENT) return;
        navigator.clipboard.writeText(LICENSE_CONTENT).then(function() {
            alert('คัดลอก License Key แล้ว');
        });
    });
    if (LICENSE_CONTENT) {
        const blob = new Blob([LICENSE_CONTENT], { type: 'text/plain;charset=utf-8' });
        $('#btnDownloadLicense').attr('href', URL.createObjectURL(blob));
    }
});

function escapeHtml(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>
</body>
</html>
