<?php
/**
 * Step 3: Admin License Generator
 * สร้าง license.key + บันทึก tb_deployments — ต้อง login เป็น admin
 */
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8"><title>ไม่มีสิทธิ์</title></head>'
        . '<body style="font-family:Sarabun,Tahoma,sans-serif;padding:40px;text-align:center;">'
        . '<h3>ไม่มีสิทธิ์เข้าหน้านี้</h3>'
        . '<p>เฉพาะผู้ดูแลระบบ (admin) เท่านั้น</p>'
        . '<a href="home.php">กลับหน้าหลัก</a></body></html>';
    exit;
}

define('THAM_LICENSE_SKIP', true);
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/license_admin_helper.php';

header('Content-Type: text/html; charset=utf-8');

$success_msg = '';
$error_msg = '';
$license_download = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $master_pin = (string)($_POST['master_pin'] ?? '');
    $hospital_name = trim($_POST['hospital_name'] ?? '');
    $expire_date = trim($_POST['expire_date'] ?? '');
    $lat = trim($_POST['lat'] ?? '');
    $lng = trim($_POST['lng'] ?? '');

    if (!hash_equals(LICENSE_MASTER_PIN, $master_pin)) {
        $error_msg = 'Master PIN ไม่ถูกต้อง';
    } elseif ($hospital_name === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expire_date)) {
        $error_msg = 'กรุณากรอกชื่อโรงพยาบาลและวันหมดอายุให้ถูกต้อง';
    } elseif (!is_numeric($lat) || !is_numeric($lng)) {
        $error_msg = 'Lat / Lng ต้องเป็นตัวเลข';
    } else {
        // Task A: เข้ารหัส JSON → base64(IV + ciphertext) → บันทึก license.key
        $license_content = license_admin_generate_key_content($hospital_name, $expire_date);
        if ($license_content === null) {
            $error_msg = 'เข้ารหัส License ไม่สำเร็จ';
        } else {
            license_admin_ensure_storage_dir();
            $archive_name = license_admin_safe_filename($hospital_name) . '_' . date('Ymd_His') . '.key';
            $archive_path = LICENSE_STORAGE_DIR . '/' . $archive_name;
            file_put_contents($archive_path, $license_content);

            // ไฟล์ license.key มาตรฐานสำหรับส่งให้รพ. (วางที่ root แอป)
            $license_path = __DIR__ . '/storage/licenses/' . license_admin_safe_filename($hospital_name) . '.key';
            file_put_contents($license_path, $license_content);

            // Task B: บันทึก/อัปเดต tb_deployments
            $saved = license_admin_save_deployment($con, $hospital_name, (float)$lat, (float)$lng, $expire_date, $archive_name);
            if ($saved) {
                $success_msg = 'สร้าง License และบันทึกแผนที่เรียบร้อยแล้ว — โรงพยาบาล: ' . $hospital_name;
                $license_download = $license_content;
            } else {
                $error_msg = 'สร้าง License ได้ แต่บันทึกฐานข้อมูลล้มเหลว: ' . mysqli_error($con);
                $license_download = $license_content;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin — สร้าง License Key</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
    <link href="css/flatpickr.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4" style="max-width:560px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="fw-bold mb-0"><i class="fas fa-key text-warning me-2"></i>สร้าง License Key</h4>
        <a href="map_dashboard.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-map me-1"></i>แผนที่</a>
    </div>

    <?php if ($success_msg): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle me-1"></i><?php echo htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php if ($license_download): ?>
    <div class="card border-success mb-3">
        <div class="card-body">
            <p class="small fw-bold mb-2">ส่งเนื้อหานี้เป็นไฟล์ <code>license.key</code> ให้รพ. วางที่ root ของแอป</p>
            <textarea class="form-control font-monospace small mb-2" rows="4" readonly id="licenseOut"><?php echo htmlspecialchars($license_download); ?></textarea>
            <button type="button" class="btn btn-sm btn-success" id="btnDl"><i class="fas fa-download me-1"></i>ดาวน์โหลด license.key</button>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($error_msg): ?>
    <div class="alert alert-danger"><i class="fas fa-times-circle me-1"></i><?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="card-body">
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label fw-bold">ชื่อโรงพยาบาล <span class="text-danger">*</span></label>
                    <input type="text" name="hospital_name" class="form-control" required maxlength="255"
                        placeholder="เช่น โรงพยาบาลสมุทรปราการ">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-bold">ละติจูด (Lat)</label>
                        <input type="text" name="lat" class="form-control" required value="13.7563">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold">ลองจิจูด (Lng)</label>
                        <input type="text" name="lng" class="form-control" required value="100.5018">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">วันหมดอายุ <span class="text-danger">*</span></label>
                    <input type="text" name="expire_date" id="expire_date" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Master PIN <span class="text-danger">*</span></label>
                    <input type="password" name="master_pin" class="form-control" required autocomplete="off">
                </div>
                <button type="submit" class="btn btn-warning fw-bold w-100">
                    <i class="fas fa-bolt me-1"></i> Generate &amp; Save to Map
                </button>
            </form>
        </div>
    </div>
</div>
<script src="js/flatpickr.min.js"></script>
<script>
const d = new Date(); d.setFullYear(d.getFullYear() + 1);
flatpickr('#expire_date', { dateFormat: 'Y-m-d', defaultDate: d });
const lic = document.getElementById('licenseOut');
if (lic) {
    document.getElementById('btnDl').onclick = function() {
        const b = new Blob([lic.value], { type: 'text/plain;charset=utf-8' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(b);
        a.download = 'license.key';
        a.click();
    };
}
</script>
</body>
</html>
