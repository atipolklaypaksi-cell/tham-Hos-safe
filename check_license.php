<?php
/**
 * Step 2: Offline License Checker
 * วางไฟล์ license.key ในโฟลเดอร์เดียวกับไฟล์นี้ (root ของแอป)
 * เรียก tham_check_license() ก่อนใช้งานเว็บ — Cron CLI ข้ามอัตโนมัติ
 */
if (!defined('THAM_LICENSE_SECRET_KEY')) {
    // ต้องตรงกับ LICENSE_SECRET_KEY ใน license_admin_config.php (ฝั่ง Admin)
    define('THAM_LICENSE_SECRET_KEY', 'tham-hossafe-license-secret-change-me-32ch');
}

/**
 * @return bool true = ผ่านการตรวจ (หรือข้ามบน CLI/Cron)
 */
function tham_check_license() {
    // ข้ามเมื่อ Admin กำหนดเอง (หน้า map / generate key)
    if (defined('THAM_LICENSE_SKIP') && THAM_LICENSE_SKIP) {
        return true;
    }

    // Step 2.1: Cron CLI — ไม่ตรวจ license ไม่บล็อก
    if (php_sapi_name() === 'cli') {
        return true;
    }

    // ข้ามสคริปต์ cron_* (รองรับ cron ที่เรียกผ่าน HTTP ด้วย secret)
    $script = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
    if ($script !== '' && strpos($script, 'cron_') === 0) {
        return true;
    }

    // Step 2.2: ตรวจวันนี้แล้ว — cache ใน session
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (isset($_SESSION['license_checked_date']) && $_SESSION['license_checked_date'] === date('Y-m-d')) {
        return true;
    }

    // Step 2.3: อ่านและถอดรหัส license.key
    $license_path = __DIR__ . '/license.key';
    if (!is_readable($license_path)) {
        tham_license_fail('ไม่พบไฟล์ license.key — กรุณาติดต่อผู้พัฒนาเพื่อขอ License');
    }

    $content = trim((string)file_get_contents($license_path));
    $data = tham_license_decrypt($content);
    if ($data === null) {
        tham_license_fail('ไฟล์ License ไม่ถูกต้องหรือถูกแก้ไข');
    }

    // Step 2.4: ตรวจวันหมดอายุ
    $expire = trim((string)($data['expire_date'] ?? ''));
    if ($expire === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expire)) {
        tham_license_fail('รูปแบบวันหมดอายุใน License ไม่ถูกต้อง');
    }
    if (strtotime($expire) < strtotime(date('Y-m-d'))) {
        tham_license_fail('License หมดอายุแล้ว (หมดอายุ: ' . $expire . ') — กรุณาติดต่อผู้พัฒนาเพื่อต่ออายุ');
    }

    // Step 2.5: ผ่าน — บันทึก cache วันนี้
    $_SESSION['license_checked_date'] = date('Y-m-d');
    $_SESSION['license_hospital_name'] = (string)($data['hospital_name'] ?? '');
    return true;
}

function tham_license_decrypt($license_b64) {
    $raw = base64_decode($license_b64, true);
    if ($raw === false || strlen($raw) < 17) {
        return null;
    }
    $iv = substr($raw, 0, 16);
    $ciphertext = substr($raw, 16);
    $secret_key = hash('sha256', THAM_LICENSE_SECRET_KEY, true);
    $json = openssl_decrypt($ciphertext, 'AES-256-CBC', $secret_key, OPENSSL_RAW_DATA, $iv);
    if ($json === false) {
        return null;
    }
    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

function tham_license_fail($message) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        http_response_code(403);
    }
    echo '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8"><title>License Error</title>';
    echo '<style>body{font-family:Sarabun,Tahoma,sans-serif;background:#f8f9fa;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}';
    echo '.box{max-width:480px;background:#fff;border:1px solid #dee2e6;border-radius:12px;padding:2rem;box-shadow:0 4px 12px rgba(0,0,0,.08);text-align:center;}';
    echo 'h1{color:#dc3545;font-size:1.25rem;}p{color:#495057;line-height:1.6;}</style></head><body>';
    echo '<div class="box"><h1>⚠️ ไม่สามารถใช้งานระบบได้</h1>';
    echo '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p class="text-muted" style="font-size:0.85rem;color:#6c757d;">THAM Hos-Safe License Protection</p></div></body></html>';
    die();
}
