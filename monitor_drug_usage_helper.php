<?php
/**
 * Monitor วิธีใช้ยา — helper
 */

function mdu_default_required_regex() {
    return 'สัปดาห์|เสาร์|อาทิตย์|จันทร์|อังคาร|พุธ|พฤหัส|ศุกร์|ทันที|week';
}

function mdu_ensure_table($con) {
    $q = mysqli_query($con, "SHOW TABLES LIKE 'monitor_drug_usage_settings'");
    if ($q && mysqli_num_rows($q) > 0) {
        return true;
    }
    $sql = @file_get_contents(__DIR__ . '/sql/monitor_drug_usage.sql');
    if ($sql === false || trim($sql) === '') {
        return false;
    }
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt !== '' && !mysqli_query($con, $stmt)) {
            return false;
        }
    }
    return true;
}

function mdu_seed_default_rules($con) {
    if (!mdu_ensure_table($con)) {
        return false;
    }
    $icode = '1000146';
    $icode_esc = mysqli_real_escape_string($con, $icode);
    $q = mysqli_query($con, "SELECT id FROM monitor_drug_usage_settings WHERE icode='$icode_esc' LIMIT 1");
    if ($q && mysqli_num_rows($q) > 0) {
        return true;
    }
    $regex = mysqli_real_escape_string($con, mdu_default_required_regex());
    $name = mysqli_real_escape_string($con, 'Methotrexate');
    $msg = mysqli_real_escape_string($con, 'วิธีใช้ไม่ระบุวันรับประทานหรือสัปดาห์ละครั้ง — กรุณาตรวจสอบ (Methotrexate)');
    $detail = mysqli_real_escape_string($con, 'Methotrexate ปกติรับประทานวันกำหนดหรือสัปดาห์ละครั้ง ต้องมีคำระบุวัน/สัปดาห์ในวิธีใช้');
    return mysqli_query($con, "INSERT INTO monitor_drug_usage_settings
        (icode, drug_name, required_regex, alert_level, alert_message, detail_text, active)
        VALUES ('$icode_esc', '$name', '$regex', 'hard', '$msg', '$detail', 1)");
}

/**
 * ตรวจว่า regex ใช้ได้ (ทดสอบด้วย preg_match)
 */
function mdu_validate_regex($regex) {
    $regex = trim((string)$regex);
    if ($regex === '') {
        return ['ok' => false, 'error' => 'กรุณาระบุรูปแบบคำที่ต้องมีในวิธีใช้'];
    }
    $pattern = mdu_regex_to_php($regex);
    if ($pattern === null) {
        return ['ok' => false, 'error' => 'รูปแบบ REGEXP ไม่ถูกต้อง'];
    }
    return ['ok' => true, 'error' => ''];
}

function mdu_regex_to_php($regex) {
    $regex = trim((string)$regex);
    if ($regex === '') {
        return null;
    }
    $delim = '#';
    $escaped = str_replace($delim, '\\' . $delim, $regex);
    $pattern = $delim . $escaped . $delim . 'iu';
    if (@preg_match($pattern, '') === false) {
        return null;
    }
    return $pattern;
}

/**
 * วิธีใช้ผ่านเงื่อนไขหรือไม่ (ต้องมีคำตาม regex)
 */
function mdu_method_passes($method, $regex) {
    $method = trim(preg_replace('/\s+/u', ' ', (string)$method));
    if ($method === '') {
        return false;
    }
    $pattern = mdu_regex_to_php($regex);
    if ($pattern === null) {
        return false;
    }
    return preg_match($pattern, $method) === 1;
}

function mdu_alert_level($row) {
    return (($row['alert_level'] ?? '') === 'soft') ? 'soft' : 'hard';
}

function mdu_alert_badge_html($level) {
    if ($level === 'hard') {
        return '<span class="badge bg-danger">Hard Alert</span>';
    }
    return '<span class="badge bg-warning text-dark">Soft Alert</span>';
}

function mdu_load_active_rules($con, array $rule_ids = []) {
    mdu_ensure_table($con);
    $rules = [];
    $q = mysqli_query($con, "SELECT * FROM monitor_drug_usage_settings WHERE active = 1 ORDER BY drug_name ASC");
    if (!$q) {
        return $rules;
    }
    $filter = [];
    foreach ($rule_ids as $rid) {
        $rid = (int)$rid;
        if ($rid > 0) {
            $filter[$rid] = true;
        }
    }
    while ($row = mysqli_fetch_assoc($q)) {
        if (!empty($filter) && !isset($filter[(int)$row['id']])) {
            continue;
        }
        $icode = trim($row['icode'] ?? '');
        if ($icode === '') {
            continue;
        }
        $rules[] = $row;
    }
    return $rules;
}
