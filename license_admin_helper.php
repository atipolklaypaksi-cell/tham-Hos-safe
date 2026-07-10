<?php
/**
 * Helper: License Generator & Deployment Map Tracker
 * ไฟล์ license_admin_config.php มีเฉพาะเซิร์ฟเวอร์กลาง — รพ.ใช้ค่า default ด้านล่าง
 */
if (is_file(__DIR__ . '/license_admin_config.php')) {
    require_once __DIR__ . '/license_admin_config.php';
} else {
    if (!defined('LICENSE_MASTER_PIN')) {
        define('LICENSE_MASTER_PIN', '');
    }
    if (!defined('LICENSE_SECRET_KEY')) {
        define('LICENSE_SECRET_KEY', 'tham-hossafe-license-secret-change-me-32ch');
    }
    if (!defined('LICENSE_STORAGE_DIR')) {
        define('LICENSE_STORAGE_DIR', __DIR__ . '/storage/licenses');
    }
    if (!defined('LICENSE_DEFAULT_LAT')) {
        define('LICENSE_DEFAULT_LAT', 13.7563);
    }
    if (!defined('LICENSE_DEFAULT_LNG')) {
        define('LICENSE_DEFAULT_LNG', 100.5018);
    }
}

function license_admin_ensure_table($con) {
    static $done = false;
    if ($done) {
        return true;
    }

    $chk = mysqli_query($con, "SHOW TABLES LIKE 'tb_deployments'");
    if ($chk && mysqli_num_rows($chk) > 0) {
        $done = true;
        return true;
    }

    $sql = "CREATE TABLE IF NOT EXISTS tb_deployments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        hospital_name VARCHAR(255) NOT NULL COMMENT 'ชื่อโรงพยาบาล',
        lat DECIMAL(10, 7) NOT NULL DEFAULT 13.7563000,
        lng DECIMAL(10, 7) NOT NULL DEFAULT 100.5018000,
        expire_date DATE NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        license_file VARCHAR(255) NULL DEFAULT NULL,
        license_generated_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_hospital_name (hospital_name),
        KEY idx_status (status),
        KEY idx_expire_date (expire_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!mysqli_query($con, $sql)) {
        return false;
    }

    $done = true;
    return true;
}

function license_admin_ensure_storage_dir() {
    if (!is_dir(LICENSE_STORAGE_DIR)) {
        if (!@mkdir(LICENSE_STORAGE_DIR, 0750, true)) {
            return false;
        }
    }
    return is_writable(LICENSE_STORAGE_DIR);
}

/**
 * สร้าง license.key จากชื่อ รพ. + วันหมดอายุ
 * รูปแบบ: base64( IV[16 bytes] + ciphertext AES-256-CBC )
 */
function license_admin_generate_key_content($hospital_name, $expire_date) {
    $payload = json_encode([
        'hospital_name' => $hospital_name,
        'expire_date' => $expire_date,
        'issued_at' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE);

    // แปลง secret เป็น 32 bytes สำหรับ AES-256
    $secret_key = hash('sha256', LICENSE_SECRET_KEY, true);

    // สุ่ม IV 16 bytes แล้วนำไปต่อหน้า ciphertext
    $iv = openssl_random_pseudo_bytes(16);
    $encrypted = openssl_encrypt($payload, 'AES-256-CBC', $secret_key, OPENSSL_RAW_DATA, $iv);
    if ($encrypted === false) {
        return null;
    }

    return base64_encode($iv . $encrypted);
}

function license_admin_safe_filename($hospital_name) {
    $slug = preg_replace('/[^a-zA-Z0-9ก-๙]+/u', '_', trim($hospital_name));
    $slug = trim($slug, '_');
    if ($slug === '') {
        $slug = 'hospital';
    }
    return mb_substr($slug, 0, 80);
}

/**
 * บันทึก license.key ลงดิสก์
 */
function license_admin_write_license_file($hospital_name, $license_content) {
    if (!license_admin_ensure_storage_dir()) {
        return null;
    }
    $filename = license_admin_safe_filename($hospital_name) . '_' . date('Ymd_His') . '.key';
    $path = LICENSE_STORAGE_DIR . '/' . $filename;
    if (file_put_contents($path, $license_content) === false) {
        return null;
    }
    return $filename;
}

/**
 * บันทึก/อัปเดตข้อมูลรพ.ในตาราง tb_deployments
 */
function license_admin_save_deployment($con, $hospital_name, $lat, $lng, $expire_date, $license_file = null) {
    license_admin_ensure_table($con);

    $name_esc = mysqli_real_escape_string($con, $hospital_name);
    $lat_f = (float)$lat;
    $lng_f = (float)$lng;
    $expire_esc = mysqli_real_escape_string($con, $expire_date);
    $status = 'Active';

    // ถ้าวันหมดอายุผ่านแล้ว ตั้งสถานะเป็น Expired
    if (strtotime($expire_date) < strtotime(date('Y-m-d'))) {
        $status = 'Expired';
    }
    $status_esc = mysqli_real_escape_string($con, $status);
    $file_esc = $license_file !== null
        ? "'" . mysqli_real_escape_string($con, $license_file) . "'"
        : 'NULL';
    $now = date('Y-m-d H:i:s');

    $chk = mysqli_query($con, "SELECT id FROM tb_deployments WHERE hospital_name = '$name_esc' LIMIT 1");
    if ($chk && mysqli_num_rows($chk) > 0) {
        $sql = "UPDATE tb_deployments SET
            lat = $lat_f,
            lng = $lng_f,
            expire_date = '$expire_esc',
            status = '$status_esc',
            license_file = $file_esc,
            license_generated_at = '$now',
            updated_at = '$now'
            WHERE hospital_name = '$name_esc'";
    } else {
        $sql = "INSERT INTO tb_deployments
            (hospital_name, lat, lng, expire_date, status, license_file, license_generated_at)
            VALUES ('$name_esc', $lat_f, $lng_f, '$expire_esc', '$status_esc', $file_esc, '$now')";
    }

    return mysqli_query($con, $sql);
}

function license_admin_fetch_deployments($con) {
    if (!license_admin_ensure_table($con)) {
        return [];
    }
    $rows = [];
    $q = mysqli_query($con, "SELECT * FROM tb_deployments ORDER BY hospital_name ASC");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $rows[] = $r;
        }
    }
    return $rows;
}

/**
 * ถอดรหัส license (ใช้ฝั่งรพ. ตรวจสอบ — ใส่ไว้เป็นตัวอย่าง)
 */
function license_admin_decrypt_key($license_b64) {
    $raw = base64_decode($license_b64, true);
    if ($raw === false || strlen($raw) < 17) {
        return null;
    }
    $iv = substr($raw, 0, 16);
    $ciphertext = substr($raw, 16);
    $secret_key = hash('sha256', LICENSE_SECRET_KEY, true);
    $json = openssl_decrypt($ciphertext, 'AES-256-CBC', $secret_key, OPENSSL_RAW_DATA, $iv);
    if ($json === false) {
        return null;
    }
    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}
