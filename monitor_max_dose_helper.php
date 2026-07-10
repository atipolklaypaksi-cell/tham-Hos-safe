<?php
/**
 * Monitor Max Dose / Duration — helper
 */

function mmdd_ensure_table($con) {
    $q = mysqli_query($con, "SHOW TABLES LIKE 'monitor_max_dose_settings'");
    if ($q && mysqli_num_rows($q) > 0) {
        return true;
    }
    $sql = @file_get_contents(__DIR__ . '/sql/monitor_max_dose.sql');
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

function mmdd_alert_level($row) {
    return (($row['alert_level'] ?? '') === 'soft') ? 'soft' : 'hard';
}

function mmdd_alert_badge_html($level) {
    if ($level === 'hard') {
        return '<span class="badge bg-danger">Hard Alert</span>';
    }
    return '<span class="badge bg-warning text-dark">Soft Alert</span>';
}

function mmdd_load_active_rules($con, array $rule_ids = []) {
    mmdd_ensure_table($con);
    $rules = [];
    $q = mysqli_query($con, "SELECT * FROM monitor_max_dose_settings WHERE active = 1 ORDER BY drug_name ASC");
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
        if ((float)($row['max_dose_mg_per_day'] ?? 0) <= 0 && (int)($row['max_duration_days'] ?? 0) <= 0) {
            continue;
        }
        $rules[] = $row;
    }
    return $rules;
}

function mmdd_rule_is_valid(array $data) {
    $max_dose = (float)($data['max_dose_mg_per_day'] ?? 0);
    $max_days = (int)($data['max_duration_days'] ?? 0);
    if ($max_dose <= 0 && $max_days <= 0) {
        return ['ok' => false, 'error' => 'กรุณาระบุ Max Dose (mg/วัน) หรือ Max Duration (วัน) อย่างน้อย 1 ค่า'];
    }
    if ($max_dose < 0) {
        return ['ok' => false, 'error' => 'Max Dose ต้องมากกว่า 0'];
    }
    if ($max_days < 0) {
        return ['ok' => false, 'error' => 'Max Duration ต้องไม่ติดลบ'];
    }
    return ['ok' => true, 'error' => ''];
}

/**
 * นับวันสั่งยาติดต่อกันย้อนหลังจากวันที่สั่ง (รวมวันนั้น)
 */
function mmdd_consecutive_days($hos, $hn, $icode, $as_of_date, array &$cache = []) {
    $hn = trim((string)$hn);
    $icode = trim((string)$icode);
    $as_of_date = trim((string)$as_of_date);
    if ($hn === '' || $icode === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $as_of_date)) {
        return 0;
    }
    $key = $hn . '|' . $icode . '|' . $as_of_date;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $hn_esc = mysqli_real_escape_string($hos, $hn);
    $ic_esc = mysqli_real_escape_string($hos, $icode);
    $as_of_esc = mysqli_real_escape_string($hos, $as_of_date);
    $q = mysqli_query($hos, "SELECT DISTINCT o.rxdate
        FROM opitemrece o
        WHERE o.hn = '$hn_esc'
          AND o.icode = '$ic_esc'
          AND o.qty > 0
          AND o.rxdate <= '$as_of_esc'
          AND o.rxdate >= DATE_SUB('$as_of_esc', INTERVAL 400 DAY)
        ORDER BY o.rxdate DESC");
    $date_set = [];
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $d = trim((string)($r['rxdate'] ?? ''));
            if ($d !== '' && $d !== '0000-00-00') {
                $date_set[$d] = true;
            }
        }
    }
    if (empty($date_set[$as_of_date])) {
        $cache[$key] = 0;
        return 0;
    }
    $count = 0;
    $cur = strtotime($as_of_date);
    while ($cur && isset($date_set[date('Y-m-d', $cur)])) {
        $count++;
        $cur -= 86400;
    }
    $cache[$key] = $count;
    return $count;
}

function mmdd_build_trigger_reasons(array $rule, $daily_mg, $consecutive_days) {
    $reasons = [];
    $max_dose = (float)($rule['max_dose_mg_per_day'] ?? 0);
    $max_days = (int)($rule['max_duration_days'] ?? 0);
    if ($max_dose > 0 && $daily_mg !== null && $daily_mg > $max_dose) {
        $reasons[] = 'dose';
    }
    if ($max_days > 0 && $consecutive_days > $max_days) {
        $reasons[] = 'duration';
    }
    return $reasons;
}

function mmdd_default_alert_message(array $rule, array $reasons, $daily_mg, $consecutive_days) {
    $custom = trim((string)($rule['alert_message'] ?? ''));
    if ($custom !== '') {
        return $custom;
    }
    $parts = [];
    $max_dose = (float)($rule['max_dose_mg_per_day'] ?? 0);
    $max_days = (int)($rule['max_duration_days'] ?? 0);
    if (in_array('dose', $reasons, true)) {
        $calc = $daily_mg !== null ? rtrim(rtrim(number_format($daily_mg, 2, '.', ''), '0'), '.') : '?';
        $parts[] = "ขนาดรวม {$calc} mg/วัน เกิน Max Dose {$max_dose} mg/วัน";
    }
    if (in_array('duration', $reasons, true)) {
        $parts[] = "สั่งยาติดต่อกัน {$consecutive_days} วัน เกิน Max Duration {$max_days} วัน";
    }
    return implode(' | ', $parts) ?: 'เกินขีดจำกัด Max Dose/Duration';
}
