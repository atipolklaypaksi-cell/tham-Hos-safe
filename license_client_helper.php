<?php
/**
 * ตัวอย่าง: ตรวจสอบ license.key ฝั่งรพ. (วางในเครื่องรพ. หลังได้รับไฟล์จาก Admin)
 * ใช้ LICENSE_SECRET_KEY ชุดเดียวกับ license_admin_config.php
 */
require_once __DIR__ . '/license_admin_config.php';
require_once __DIR__ . '/license_admin_helper.php';

function license_client_validate_file($path) {
    if (!is_readable($path)) {
        return ['valid' => false, 'message' => 'ไม่พบไฟล์ license.key'];
    }
    $content = trim(file_get_contents($path));
    $data = license_admin_decrypt_key($content);
    if ($data === null) {
        return ['valid' => false, 'message' => 'License ไม่ถูกต้องหรือถูกแก้ไข'];
    }
    if (empty($data['expire_date']) || strtotime($data['expire_date']) < strtotime(date('Y-m-d'))) {
        return ['valid' => false, 'message' => 'License หมดอายุแล้ว (' . ($data['expire_date'] ?? '-') . ')'];
    }
    return [
        'valid' => true,
        'message' => 'License ใช้งานได้',
        'hospital_name' => $data['hospital_name'] ?? '',
        'expire_date' => $data['expire_date'] ?? '',
    ];
}

// CLI ทดสอบ: php license_client_helper.php path/to/license.key
if (php_sapi_name() === 'cli' && isset($argv[1])) {
    print_r(license_client_validate_file($argv[1]));
}
