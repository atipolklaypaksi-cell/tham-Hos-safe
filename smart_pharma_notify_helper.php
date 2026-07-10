<?php
/**
 * Smart Pharma — ตรรกะแจ้งเตือนซ้ำ (Telegram + Webhook)
 */

function sp_notify_now_ts() {
    if (function_exists('sp_now_timestamp')) {
        return sp_now_timestamp();
    }
    return time();
}

/** แปลง datetime จาก MySQL (เวลาไทย) เป็น unix timestamp */
function sp_notify_db_ts($datetime_str) {
    $datetime_str = trim((string)$datetime_str);
    if ($datetime_str === '' || $datetime_str === '0000-00-00 00:00:00') {
        return false;
    }
    if (function_exists('sp_timezone')) {
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $datetime_str, sp_timezone());
        if ($dt instanceof DateTime) {
            return $dt->getTimestamp();
        }
    }
    $ts = strtotime($datetime_str);
    return $ts ? $ts : false;
}

function sp_notify_normalize_repeat_mode($mode) {
    $mode = strtolower(trim((string)$mode));
    return in_array($mode, ['off', '15', '30', '60', 'custom'], true) ? $mode : 'off';
}

function sp_notify_repeat_minutes_from_rule(array $rule, $level = 'hard') {
    $is_soft = ($level === 'soft');
    $mode_key = $is_soft ? 'soft_repeat_mode' : 'hard_repeat_mode';
    $minutes_key = $is_soft ? 'soft_repeat_minutes' : 'hard_repeat_minutes';
    $mode = sp_notify_normalize_repeat_mode($rule[$mode_key] ?? 'off');
    if ($mode === 'off') {
        return 0;
    }
    if ($mode === 'custom') {
        return max(1, min(10080, (int)($rule[$minutes_key] ?? 0)));
    }
    return (int)$mode;
}

function sp_notify_repeat_mode_label($mode, $custom_minutes = 0) {
    $mode = sp_notify_normalize_repeat_mode($mode);
    switch ($mode) {
        case '15':
            return 'ทุก 15 นาที';
        case '30':
            return 'ทุก 30 นาที';
        case '60':
            return 'ทุก 1 ชม.';
        case 'custom':
            $m = max(1, (int)$custom_minutes);
            return 'ทุก ' . $m . ' นาที';
        default:
            return 'ครั้งเดียว';
    }
}

function sp_notify_normalize_stop_mode($mode) {
    $mode = strtolower(trim((string)$mode));
    return in_array($mode, ['off', '12h', '1d', '2d', 'custom'], true) ? $mode : 'off';
}

function sp_notify_stop_mode_label($mode, $custom_minutes = 0) {
    $mode = sp_notify_normalize_stop_mode($mode);
    switch ($mode) {
        case 'off':
            return 'จนแก้ HOSxP/ตอบ Intervention';
        case '12h':
            return '12 ชม.';
        case '1d':
            return '1 วัน';
        case '2d':
            return '2 วัน';
        case 'custom':
            $m = max(1, (int)$custom_minutes);
            return $m . ' น.';
        default:
            return 'จนแก้ HOSxP/ตอบ Intervention';
    }
}

function sp_notify_stop_minutes_from_rule(array $rule, $level = 'hard') {
    $is_soft = ($level === 'soft');
    $mode_key = $is_soft ? 'soft_stop_mode' : 'hard_stop_mode';
    $minutes_key = $is_soft ? 'soft_stop_minutes' : 'hard_stop_minutes';
    $mode = sp_notify_normalize_stop_mode($rule[$mode_key] ?? $rule['stop_mode'] ?? 'off');
    if ($mode === 'off') {
        return 0;
    }
    if ($mode === '12h') {
        return 12 * 60;
    }
    if ($mode === '1d') {
        return 24 * 60;
    }
    if ($mode === '2d') {
        return 48 * 60;
    }
    return max(1, min(10080, (int)($rule[$minutes_key] ?? $rule['stop_minutes'] ?? 0)));
}

/** จุดเริ่มนับเวลาหยุดแจ้งซ้ำ — ใช้เวลาที่ระบบตรวจพบ Alert ครั้งแรก ไม่ใช่เวลาสั่งยา */
function sp_notify_stop_since_ts(array $log_row) {
    $since = trim((string)($log_row['first_detected_at'] ?? ''));
    if ($since === '' || $since === '0000-00-00 00:00:00') {
        $since = trim((string)($log_row['last_seen_at'] ?? ''));
    }
    if ($since === '' || $since === '0000-00-00 00:00:00') {
        return false;
    }
    $ts = sp_notify_db_ts($since);
    return $ts ? $ts : false;
}

/**
 * Hard Alert ยังควรแจ้งซ้ำหรือไม่ (หยุดเมื่อแก้ HOSxP หรือตอบ Intervention แล้ว)
 */
function sp_alert_log_should_continue_notify(array $log_row) {
    if (($log_row['status'] ?? '') === 'resolved') {
        return false;
    }
    if (!empty($log_row['has_pharmacist_note'])) {
        return false;
    }
    return true;
}

function sp_notify_get_last_sent_at($con, $table, $dedupe_key) {
    $allowed = ['smart_pharma_telegram_sent', 'smart_pharma_webhook_sent'];
    if (!in_array($table, $allowed, true)) {
        return null;
    }
    $key_esc = mysqli_real_escape_string($con, $dedupe_key);
    $q = mysqli_query($con, "SELECT sent_at FROM `$table` WHERE dedupe_key='$key_esc' LIMIT 1");
    if (!$q || !($row = mysqli_fetch_assoc($q))) {
        return null;
    }
    return $row['sent_at'] ?? null;
}

/**
 * @return array{send:bool, is_repeat:bool, reason?:string}
 */
function sp_notify_should_send($con, $table, $dedupe_key, $level, $repeat_minutes, $log_row = null, $stop_minutes = 0) {
    if ($log_row && !sp_alert_log_should_continue_notify($log_row)) {
        return ['send' => false, 'is_repeat' => false, 'reason' => 'stopped'];
    }
    if ($log_row && $stop_minutes > 0) {
        $since_ts = sp_notify_stop_since_ts($log_row);
        if ($since_ts && (sp_notify_now_ts() - $since_ts) >= ($stop_minutes * 60)) {
            return ['send' => false, 'is_repeat' => false, 'reason' => 'stop_window'];
        }
    }
    $last = sp_notify_get_last_sent_at($con, $table, $dedupe_key);
    if ($last === null) {
        return ['send' => true, 'is_repeat' => false, 'reason' => 'first'];
    }
    $last_ts = sp_notify_db_ts($last);
    if ($last_ts === false) {
        return ['send' => true, 'is_repeat' => false, 'reason' => 'first'];
    }
    if ($repeat_minutes <= 0) {
        return ['send' => false, 'is_repeat' => false, 'reason' => 'once'];
    }
    $elapsed = sp_notify_now_ts() - $last_ts;
    if ($elapsed >= ($repeat_minutes * 60)) {
        return ['send' => true, 'is_repeat' => true, 'reason' => 'repeat'];
    }
    return ['send' => false, 'is_repeat' => false, 'reason' => 'wait'];
}

function sp_notify_sent_table_meta($table) {
    $allowed = [
        'smart_pharma_telegram_sent' => 'chat_id',
        'smart_pharma_webhook_sent' => 'endpoint_id',
    ];
    return $allowed[$table] ?? null;
}

function sp_notify_insert_sent_if_new($con, $table, $dedupe_key, $patient_type, $screen_date, $hn, $module_key, $level, $target_id, $target_column) {
    $allowed_column = sp_notify_sent_table_meta($table);
    if ($allowed_column === null || $allowed_column !== $target_column) {
        return false;
    }
    $key_esc = mysqli_real_escape_string($con, $dedupe_key);
    $ptype_esc = mysqli_real_escape_string($con, $patient_type);
    $date_esc = mysqli_real_escape_string($con, $screen_date);
    $hn_esc = mysqli_real_escape_string($con, $hn);
    $mod_esc = mysqli_real_escape_string($con, $module_key);
    $lvl_esc = mysqli_real_escape_string($con, $level);
    $tid_esc = mysqli_real_escape_string($con, (string)$target_id);
    $ok = mysqli_query($con, "INSERT IGNORE INTO `$table`
        (dedupe_key, patient_type, screen_date, hn, module_key, alert_level, `$target_column`, sent_at)
        VALUES ('$key_esc','$ptype_esc','$date_esc','$hn_esc','$mod_esc','$lvl_esc','$tid_esc',NOW())");
    return $ok && mysqli_affected_rows($con) > 0;
}

function sp_notify_release_first_claim($con, $table, $dedupe_key) {
    if (!sp_notify_sent_table_meta($table)) {
        return false;
    }
    $key_esc = mysqli_real_escape_string($con, $dedupe_key);
    return mysqli_query($con, "DELETE FROM `$table` WHERE dedupe_key='$key_esc'");
}

/**
 * จองสิทธิ์ส่งแบบ atomic — ป้องกัน cron + auto-refresh ส่งซ้ำพร้อมกัน
 * @param bool $repeat_only true = ใช้เฉพาะแจ้งซ้ำ Hard (ไม่ส่งครั้งแรก)
 * @return array{send:bool, is_repeat:bool, reason?:string}
 */
function sp_notify_claim_send($con, $table, $dedupe_key, $level, $repeat_minutes, $log_row, $stop_minutes, $patient_type, $screen_date, $hn, $module_key, $target_id, $repeat_only = false) {
    $precheck = sp_notify_should_send($con, $table, $dedupe_key, $level, $repeat_minutes, $log_row, $stop_minutes);
    if (empty($precheck['send'])) {
        return $precheck;
    }
    $target_column = sp_notify_sent_table_meta($table);
    if ($target_column === null) {
        return ['send' => false, 'is_repeat' => false, 'reason' => 'invalid_table'];
    }

    if (empty($precheck['is_repeat'])) {
        if ($repeat_only) {
            return ['send' => false, 'is_repeat' => false, 'reason' => 'first_via_screening'];
        }
        $claimed = sp_notify_insert_sent_if_new(
            $con,
            $table,
            $dedupe_key,
            $patient_type,
            $screen_date,
            $hn,
            $module_key,
            $level,
            $target_id,
            $target_column
        );
        if (!$claimed) {
            return ['send' => false, 'is_repeat' => false, 'reason' => 'claimed'];
        }
        return ['send' => true, 'is_repeat' => false, 'reason' => 'first'];
    }

    $repeat_sec = max(1, (int)$repeat_minutes) * 60;
    $key_esc = mysqli_real_escape_string($con, $dedupe_key);
    $ok = mysqli_query($con, "UPDATE `$table` SET sent_at=NOW()
        WHERE dedupe_key='$key_esc'
          AND UNIX_TIMESTAMP(sent_at) <= UNIX_TIMESTAMP(NOW()) - $repeat_sec");
    if ($ok && mysqli_affected_rows($con) > 0) {
        return ['send' => true, 'is_repeat' => true, 'reason' => 'repeat'];
    }
    return ['send' => false, 'is_repeat' => false, 'reason' => 'wait'];
}

function sp_notify_screening_alert_patients(array $patients) {
    return array_values(array_filter($patients, function ($patient) {
        return ($patient['overall'] ?? '') === 'alert';
    }));
}

function sp_notify_mark_sent($con, $table, $dedupe_key, $patient_type, $screen_date, $hn, $module_key, $level, $target_id, $target_column) {
    $allowed_tables = ['smart_pharma_telegram_sent' => 'chat_id', 'smart_pharma_webhook_sent' => 'endpoint_id'];
    if (!isset($allowed_tables[$table]) || $allowed_tables[$table] !== $target_column) {
        return false;
    }
    $key_esc = mysqli_real_escape_string($con, $dedupe_key);
    $ptype_esc = mysqli_real_escape_string($con, $patient_type);
    $date_esc = mysqli_real_escape_string($con, $screen_date);
    $hn_esc = mysqli_real_escape_string($con, $hn);
    $mod_esc = mysqli_real_escape_string($con, $module_key);
    $lvl_esc = mysqli_real_escape_string($con, $level);
    $tid_esc = mysqli_real_escape_string($con, (string)$target_id);
    return mysqli_query($con, "INSERT INTO `$table`
        (dedupe_key, patient_type, screen_date, hn, module_key, alert_level, `$target_column`, sent_at)
        VALUES ('$key_esc','$ptype_esc','$date_esc','$hn_esc','$mod_esc','$lvl_esc','$tid_esc',NOW())
        ON DUPLICATE KEY UPDATE sent_at=NOW()");
}

function sp_notify_clear_sent_for_alert($con, $patient_type, $screen_date, $hn, $vn, $module_key) {
    $prefix = implode('|', [
        $patient_type,
        $screen_date,
        trim((string)$hn),
        trim((string)$vn),
        trim((string)$module_key),
    ]);
    $prefix_esc = mysqli_real_escape_string($con, $prefix . '|');
    mysqli_query($con, "DELETE FROM smart_pharma_telegram_sent WHERE dedupe_key LIKE '$prefix_esc%'");
    mysqli_query($con, "DELETE FROM smart_pharma_webhook_sent WHERE dedupe_key LIKE '$prefix_esc%'");
}

function sp_alert_log_list_pending_hard_notify($con) {
    $q = mysqli_query($con, "SELECT l.*, m.label_th AS module_label
        FROM smart_pharma_alert_logs l
        LEFT JOIN smart_pharma_modules m ON m.module_key = l.module_key
        WHERE l.status='pending' AND l.alert_level='hard' AND (l.has_pharmacist_note IS NULL OR l.has_pharmacist_note=0)
        ORDER BY l.last_seen_at DESC, l.id DESC");
    $rows = [];
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $rows[] = $r;
        }
    }
    return $rows;
}
