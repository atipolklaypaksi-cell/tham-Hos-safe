<?php
/**
 * Smart Pharma — แจ้งเตือน Telegram
 */

function sp_db_add_column_if_missing($con, $table, $col, $alter_sql) {
    $table_esc = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $col_esc = mysqli_real_escape_string($con, $col);
    $cq = mysqli_query($con, "SHOW COLUMNS FROM `$table_esc` LIKE '$col_esc'");
    if ($cq && mysqli_num_rows($cq) === 0) {
        @mysqli_query($con, $alter_sql);
    }
}

function sp_text_truncate($text, $max_len = 3900) {
    $text = (string)$text;
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $max_len, 'UTF-8');
    }
    if (strlen($text) <= $max_len) {
        return $text;
    }
    return substr($text, 0, $max_len);
}

function sp_telegram_ensure_tables($con) {
    $sql = @file_get_contents(__DIR__ . '/sql/smart_pharma_telegram.sql');
    if (!$sql) {
        return false;
    }
    if (!mysqli_multi_query($con, $sql)) {
        return false;
    }
    while (mysqli_more_results($con)) {
        mysqli_next_result($con);
    }
    $q = mysqli_query($con, "SHOW COLUMNS FROM smart_pharma_telegram_chats LIKE 'bot_token'");
    if ($q && mysqli_num_rows($q) === 0) {
        mysqli_query($con, "ALTER TABLE smart_pharma_telegram_chats ADD COLUMN bot_token VARCHAR(255) NULL DEFAULT NULL AFTER chat_id");
    }
    $rule_cols = [
        'link_mode' => "ALTER TABLE smart_pharma_telegram_rules ADD COLUMN link_mode VARCHAR(10) NOT NULL DEFAULT 'auto' AFTER notify_soft",
        'extra_message' => "ALTER TABLE smart_pharma_telegram_rules ADD COLUMN extra_message VARCHAR(500) NULL DEFAULT NULL AFTER link_mode",
        'extra_link' => "ALTER TABLE smart_pharma_telegram_rules ADD COLUMN extra_link VARCHAR(500) NULL DEFAULT NULL AFTER extra_message",
        'extra_link_label' => "ALTER TABLE smart_pharma_telegram_rules ADD COLUMN extra_link_label VARCHAR(120) NULL DEFAULT NULL AFTER extra_link",
    ];
    foreach ($rule_cols as $col => $alter) {
        $cq = mysqli_query($con, "SHOW COLUMNS FROM smart_pharma_telegram_rules LIKE '$col'");
        if ($cq && mysqli_num_rows($cq) === 0) {
            mysqli_query($con, $alter);
        }
    }
    $repeat_cols = [
        'hard_repeat_mode' => "ALTER TABLE smart_pharma_telegram_rules ADD COLUMN hard_repeat_mode VARCHAR(10) NOT NULL DEFAULT 'off' AFTER extra_link_label",
        'hard_repeat_minutes' => "ALTER TABLE smart_pharma_telegram_rules ADD COLUMN hard_repeat_minutes INT NOT NULL DEFAULT 0 AFTER hard_repeat_mode",
        'soft_repeat_mode' => "ALTER TABLE smart_pharma_telegram_rules ADD COLUMN soft_repeat_mode VARCHAR(10) NOT NULL DEFAULT 'off' AFTER hard_repeat_minutes",
        'soft_repeat_minutes' => "ALTER TABLE smart_pharma_telegram_rules ADD COLUMN soft_repeat_minutes INT NOT NULL DEFAULT 0 AFTER soft_repeat_mode",
        'stop_mode' => "ALTER TABLE smart_pharma_telegram_rules ADD COLUMN stop_mode VARCHAR(10) NOT NULL DEFAULT 'off' AFTER soft_repeat_minutes",
        'stop_minutes' => "ALTER TABLE smart_pharma_telegram_rules ADD COLUMN stop_minutes INT NOT NULL DEFAULT 0 AFTER stop_mode",
        'hard_stop_mode' => "ALTER TABLE smart_pharma_telegram_rules ADD COLUMN hard_stop_mode VARCHAR(10) NOT NULL DEFAULT 'off' AFTER stop_minutes",
        'hard_stop_minutes' => "ALTER TABLE smart_pharma_telegram_rules ADD COLUMN hard_stop_minutes INT NOT NULL DEFAULT 0 AFTER hard_stop_mode",
        'soft_stop_mode' => "ALTER TABLE smart_pharma_telegram_rules ADD COLUMN soft_stop_mode VARCHAR(10) NOT NULL DEFAULT 'off' AFTER hard_stop_minutes",
        'soft_stop_minutes' => "ALTER TABLE smart_pharma_telegram_rules ADD COLUMN soft_stop_minutes INT NOT NULL DEFAULT 0 AFTER soft_stop_mode",
    ];
    $migrated_stop_split = false;
    foreach ($repeat_cols as $col => $alter) {
        $before = mysqli_query($con, "SHOW COLUMNS FROM smart_pharma_telegram_rules LIKE '$col'");
        $missing = $before && mysqli_num_rows($before) === 0;
        sp_db_add_column_if_missing($con, 'smart_pharma_telegram_rules', $col, $alter);
        if ($missing && $col === 'soft_stop_minutes') {
            $migrated_stop_split = true;
        }
    }
    if ($migrated_stop_split) {
        mysqli_query($con, "UPDATE smart_pharma_telegram_rules
            SET hard_stop_mode = stop_mode, soft_stop_mode = stop_mode,
                hard_stop_minutes = stop_minutes, soft_stop_minutes = stop_minutes
            WHERE stop_mode != 'off' OR stop_minutes > 0");
    }
    mysqli_query($con, "INSERT IGNORE INTO sys_config (config_name, config_value) VALUES ('sp_telegram_base_url', '')");
    return true;
}

function sp_telegram_normalize_link_mode($mode) {
    $mode = strtolower(trim((string)$mode));
    return in_array($mode, ['auto', 'custom', 'both', 'none'], true) ? $mode : 'auto';
}

function sp_telegram_detect_base_url() {
    if (empty($_SERVER['HTTP_HOST'])) {
        return '';
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $path = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($path === '/' || $path === '.') {
        $path = '';
    }
    return rtrim($scheme . '://' . $_SERVER['HTTP_HOST'] . $path, '/');
}

function sp_telegram_resolve_base_url(array $cfg) {
    $url = trim((string)($cfg['base_url'] ?? ''));
    if ($url !== '') {
        return rtrim($url, '/');
    }
    return sp_telegram_detect_base_url();
}

function sp_telegram_build_alert_link($base_url, $patient_type, $screen_date, array $patient, $module_key, $alert_log_id = 0) {
    $base = rtrim(trim((string)$base_url), '/');
    if ($base === '') {
        return '';
    }
    $alert_log_id = (int)$alert_log_id;
    if ($alert_log_id > 0) {
        return $base . '/smart_pharma_interventions.php?' . http_build_query([
            'alert_log_id' => $alert_log_id,
            'open' => '1',
        ]);
    }
    $hn = trim((string)($patient['hn_full'] ?? $patient['hn'] ?? ''));
    if ($hn === '') {
        return '';
    }
    $params = [
        'screen_date' => $screen_date,
        'hn' => $hn,
        'module' => $module_key,
        'patient_type' => $patient_type === 'IPD' ? 'IPD' : 'OPD',
        'open' => '1',
    ];
    $vn = trim((string)($patient['vn'] ?? ''));
    if ($vn !== '') {
        $params['vn'] = $vn;
    }
    return $base . '/smart_pharma_interventions.php?' . http_build_query($params);
}

function sp_telegram_append_rule_links($msg, array $rule, array $cfg, $patient_type, $screen_date, array $patient, $module_key, $alert_log_id = 0) {
    $mode = sp_telegram_normalize_link_mode($rule['link_mode'] ?? 'auto');
    if ($mode === 'none') {
        return $msg;
    }
    $lines = [];
    $base_url = sp_telegram_resolve_base_url($cfg);
    if (($mode === 'auto' || $mode === 'both') && $base_url !== '') {
        $alert_url = sp_telegram_build_alert_link($base_url, $patient_type, $screen_date, $patient, $module_key, $alert_log_id);
        if ($alert_url !== '') {
            $lines[] = '🔗 <a href="' . sp_telegram_escape_html($alert_url) . '">เปิด Intervention Log</a>';
        }
    }
    $extra_msg = trim((string)($rule['extra_message'] ?? ''));
    if ($extra_msg !== '' && ($mode === 'custom' || $mode === 'both')) {
        $lines[] = sp_telegram_escape_html($extra_msg);
    }
    $extra_link = trim((string)($rule['extra_link'] ?? ''));
    if ($extra_link !== '' && ($mode === 'custom' || $mode === 'both')) {
        $label = trim((string)($rule['extra_link_label'] ?? ''));
        if ($label === '') {
            $label = 'ลิงก์เพิ่มเติม';
        }
        $lines[] = '🔗 <a href="' . sp_telegram_escape_html($extra_link) . '">' . sp_telegram_escape_html($label) . '</a>';
    }
    if (empty($lines)) {
        return $msg;
    }
    $footer = implode("\n", $lines);
    $combined = $msg . "\n━━━━━━━━━━━━━━\n" . $footer;
    return sp_text_truncate($combined, 3900);
}

function sp_telegram_resolve_bot_token(array $chat_or_rule) {
    return trim((string)($chat_or_rule['chat_bot_token'] ?? $chat_or_rule['bot_token'] ?? ''));
}

function sp_telegram_get_config($con) {
    $cfg = ['enabled' => false, 'cron_secret' => '', 'base_url' => ''];
    $q = mysqli_query($con, "SELECT config_name, config_value FROM sys_config
        WHERE config_name IN ('sp_telegram_enabled', 'sp_telegram_cron_secret', 'sp_telegram_base_url')");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            if ($r['config_name'] === 'sp_telegram_enabled') {
                $cfg['enabled'] = !empty($r['config_value']) && $r['config_value'] !== '0';
            }
            if ($r['config_name'] === 'sp_telegram_cron_secret') {
                $cfg['cron_secret'] = trim((string)($r['config_value'] ?? ''));
            }
            if ($r['config_name'] === 'sp_telegram_base_url') {
                $cfg['base_url'] = trim((string)($r['config_value'] ?? ''));
            }
        }
    }
    return $cfg;
}

function sp_telegram_save_config($con, $enabled, $cron_secret = null, $base_url = null) {
    $en = $enabled ? '1' : '0';
    mysqli_query($con, "INSERT INTO sys_config (config_name, config_value) VALUES ('sp_telegram_enabled', '$en')
        ON DUPLICATE KEY UPDATE config_value = '$en'");
    if ($cron_secret !== null) {
        $sec_esc = mysqli_real_escape_string($con, trim((string)$cron_secret));
        mysqli_query($con, "INSERT INTO sys_config (config_name, config_value) VALUES ('sp_telegram_cron_secret', '$sec_esc')
            ON DUPLICATE KEY UPDATE config_value = '$sec_esc'");
    }
    if ($base_url !== null) {
        $url_esc = mysqli_real_escape_string($con, rtrim(trim((string)$base_url), '/'));
        mysqli_query($con, "INSERT INTO sys_config (config_name, config_value) VALUES ('sp_telegram_base_url', '$url_esc')
            ON DUPLICATE KEY UPDATE config_value = '$url_esc'");
    }
    return true;
}

function sp_telegram_list_chats($con) {
    $rows = [];
    $q = mysqli_query($con, "SELECT * FROM smart_pharma_telegram_chats ORDER BY sort_order ASC, label ASC, id ASC");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $rows[] = $r;
        }
    }
    return $rows;
}

function sp_telegram_save_chat($con, array $data) {
    $id = (int)($data['id'] ?? 0);
    $label = mysqli_real_escape_string($con, trim((string)($data['label'] ?? '')));
    $chat_id = mysqli_real_escape_string($con, trim((string)($data['chat_id'] ?? '')));
    $bot_token = mysqli_real_escape_string($con, trim((string)($data['bot_token'] ?? '')));
    $notes = mysqli_real_escape_string($con, trim((string)($data['notes'] ?? '')));
    $active = !empty($data['active']) ? 1 : 0;
    $sort = (int)($data['sort_order'] ?? 0);
    if ($label === '' || $chat_id === '') {
        return false;
    }
    if ($id > 0) {
        $token_sql = $bot_token !== '' ? "bot_token='$bot_token'," : '';
        return mysqli_query($con, "UPDATE smart_pharma_telegram_chats
            SET label='$label', chat_id='$chat_id', {$token_sql} notes='$notes', active=$active, sort_order=$sort
            WHERE id=$id");
    }
    if ($bot_token === '') {
        return false;
    }
    return mysqli_query($con, "INSERT INTO smart_pharma_telegram_chats (label, chat_id, bot_token, notes, active, sort_order)
        VALUES ('$label','$chat_id','$bot_token','$notes',$active,$sort)");
}

function sp_telegram_delete_chat($con, $id) {
    $id = (int)$id;
    if ($id <= 0) {
        return false;
    }
    mysqli_query($con, "DELETE FROM smart_pharma_telegram_rules WHERE chat_id=$id");
    return mysqli_query($con, "DELETE FROM smart_pharma_telegram_chats WHERE id=$id");
}

function sp_telegram_list_rules($con) {
    $rows = [];
    $q = mysqli_query($con, "SELECT r.*, c.label AS chat_label, c.chat_id AS telegram_chat_id, c.bot_token AS chat_bot_token,
            c.active AS chat_active, m.label_th AS module_label
        FROM smart_pharma_telegram_rules r
        INNER JOIN smart_pharma_telegram_chats c ON c.id = r.chat_id
        LEFT JOIN smart_pharma_modules m ON m.module_key = r.module_key
        ORDER BY c.sort_order, c.label, r.patient_type, m.sort_order, r.module_key");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $rows[] = $r;
        }
    }
    return $rows;
}

function sp_telegram_save_rule($con, array $data) {
    $id = (int)($data['id'] ?? 0);
    $chat_id = (int)($data['chat_id'] ?? 0);
    $ptype = in_array($data['patient_type'] ?? '', ['OPD', 'IPD'], true) ? $data['patient_type'] : 'OPD';
    $module = mysqli_real_escape_string($con, trim((string)($data['module_key'] ?? '')));
    $hard = !empty($data['notify_hard']) ? 1 : 0;
    $soft = !empty($data['notify_soft']) ? 1 : 0;
    $active = !empty($data['active']) ? 1 : 0;
    $link_mode = sp_telegram_normalize_link_mode($data['link_mode'] ?? 'auto');
    $extra_message = mysqli_real_escape_string($con, trim((string)($data['extra_message'] ?? '')));
    $extra_link = mysqli_real_escape_string($con, trim((string)($data['extra_link'] ?? '')));
    $extra_link_label = mysqli_real_escape_string($con, trim((string)($data['extra_link_label'] ?? '')));
    require_once __DIR__ . '/smart_pharma_notify_helper.php';
    $repeat_mode = sp_notify_normalize_repeat_mode($data['hard_repeat_mode'] ?? 'off');
    $repeat_minutes = ($repeat_mode === 'custom') ? max(1, min(10080, (int)($data['hard_repeat_minutes'] ?? 0))) : 0;
    $soft_repeat_mode = sp_notify_normalize_repeat_mode($data['soft_repeat_mode'] ?? 'off');
    $soft_repeat_minutes = ($soft_repeat_mode === 'custom') ? max(1, min(10080, (int)($data['soft_repeat_minutes'] ?? 0))) : 0;
    $hard_stop_mode = sp_notify_normalize_stop_mode($data['hard_stop_mode'] ?? $data['stop_mode'] ?? 'off');
    $hard_stop_minutes = ($hard_stop_mode === 'custom') ? max(1, min(10080, (int)($data['hard_stop_minutes'] ?? $data['stop_minutes'] ?? 0))) : 0;
    $soft_stop_mode = sp_notify_normalize_stop_mode($data['soft_stop_mode'] ?? $data['stop_mode'] ?? 'off');
    $soft_stop_minutes = ($soft_stop_mode === 'custom') ? max(1, min(10080, (int)($data['soft_stop_minutes'] ?? $data['stop_minutes'] ?? 0))) : 0;
    $stop_mode = $hard_stop_mode;
    $stop_minutes = $hard_stop_minutes;
    if ($chat_id <= 0 || $module === '') {
        return false;
    }
    if (!$hard && !$soft) {
        return false;
    }
    $ptype_esc = mysqli_real_escape_string($con, $ptype);
    $link_esc = mysqli_real_escape_string($con, $link_mode);
    $repeat_esc = mysqli_real_escape_string($con, $repeat_mode);
    $soft_repeat_esc = mysqli_real_escape_string($con, $soft_repeat_mode);
    $stop_mode_esc = mysqli_real_escape_string($con, $stop_mode);
    $hard_stop_esc = mysqli_real_escape_string($con, $hard_stop_mode);
    $soft_stop_esc = mysqli_real_escape_string($con, $soft_stop_mode);
    $extra_msg_sql = $extra_message !== '' ? "'$extra_message'" : 'NULL';
    $extra_link_sql = $extra_link !== '' ? "'$extra_link'" : 'NULL';
    $extra_label_sql = $extra_link_label !== '' ? "'$extra_link_label'" : 'NULL';
    if ($id > 0) {
        return mysqli_query($con, "UPDATE smart_pharma_telegram_rules
            SET chat_id=$chat_id, patient_type='$ptype_esc', module_key='$module',
                notify_hard=$hard, notify_soft=$soft, link_mode='$link_esc',
                extra_message=$extra_msg_sql, extra_link=$extra_link_sql, extra_link_label=$extra_label_sql,
                hard_repeat_mode='$repeat_esc', hard_repeat_minutes=$repeat_minutes,
                soft_repeat_mode='$soft_repeat_esc', soft_repeat_minutes=$soft_repeat_minutes,
                stop_mode='$stop_mode_esc', stop_minutes=$stop_minutes,
                hard_stop_mode='$hard_stop_esc', hard_stop_minutes=$hard_stop_minutes,
                soft_stop_mode='$soft_stop_esc', soft_stop_minutes=$soft_stop_minutes,
                active=$active
            WHERE id=$id");
    }
    return mysqli_query($con, "INSERT INTO smart_pharma_telegram_rules
        (chat_id, patient_type, module_key, notify_hard, notify_soft, link_mode, extra_message, extra_link, extra_link_label, hard_repeat_mode, hard_repeat_minutes, soft_repeat_mode, soft_repeat_minutes, stop_mode, stop_minutes, hard_stop_mode, hard_stop_minutes, soft_stop_mode, soft_stop_minutes, active)
        VALUES ($chat_id,'$ptype_esc','$module',$hard,$soft,'$link_esc',$extra_msg_sql,$extra_link_sql,$extra_label_sql,'$repeat_esc',$repeat_minutes,'$soft_repeat_esc',$soft_repeat_minutes,'$stop_mode_esc',$stop_minutes,'$hard_stop_esc',$hard_stop_minutes,'$soft_stop_esc',$soft_stop_minutes,$active)
        ON DUPLICATE KEY UPDATE notify_hard=$hard, notify_soft=$soft, link_mode='$link_esc',
            extra_message=$extra_msg_sql, extra_link=$extra_link_sql, extra_link_label=$extra_label_sql,
            hard_repeat_mode='$repeat_esc', hard_repeat_minutes=$repeat_minutes,
            soft_repeat_mode='$soft_repeat_esc', soft_repeat_minutes=$soft_repeat_minutes,
            stop_mode='$stop_mode_esc', stop_minutes=$stop_minutes,
            hard_stop_mode='$hard_stop_esc', hard_stop_minutes=$hard_stop_minutes,
            soft_stop_mode='$soft_stop_esc', soft_stop_minutes=$soft_stop_minutes, active=$active");
}

function sp_telegram_delete_rule($con, $id) {
    $id = (int)$id;
    if ($id <= 0) {
        return false;
    }
    return mysqli_query($con, "DELETE FROM smart_pharma_telegram_rules WHERE id=$id");
}

function sp_telegram_escape_html($text) {
    return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sp_telegram_api_send($bot_token, $chat_id, $text) {
    $bot_token = trim((string)$bot_token);
    $chat_id = trim((string)$chat_id);
    if ($bot_token === '' || $chat_id === '' || $text === '') {
        return ['ok' => false, 'error' => 'ข้อมูลไม่ครบ'];
    }
    $url = 'https://api.telegram.org/bot' . $bot_token . '/sendMessage';
    $payload = json_encode([
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ], JSON_UNESCAPED_UNICODE);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            return ['ok' => false, 'error' => $err ?: 'curl failed'];
        }
        $json = json_decode($resp, true);
        if (!empty($json['ok'])) {
            return ['ok' => true];
        }
        return ['ok' => false, 'error' => $json['description'] ?? $resp];
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 15,
        ],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) {
        return ['ok' => false, 'error' => 'HTTP request failed'];
    }
    $json = json_decode($resp, true);
    if (!empty($json['ok'])) {
        return ['ok' => true];
    }
    return ['ok' => false, 'error' => $json['description'] ?? $resp];
}

function sp_telegram_load_active_rules($con) {
    $rules = [];
    $q = mysqli_query($con, "SELECT r.*, c.label AS chat_label, c.chat_id AS telegram_chat_id, c.bot_token AS chat_bot_token
        FROM smart_pharma_telegram_rules r
        INNER JOIN smart_pharma_telegram_chats c ON c.id = r.chat_id AND c.active = 1
        WHERE r.active = 1");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $rules[] = $r;
        }
    }
    return $rules;
}

function sp_telegram_dedupe_key($patient_type, $screen_date, $hn, $vn, $module_key, $level, $telegram_chat_id) {
    return implode('|', [
        $patient_type,
        $screen_date,
        trim((string)$hn),
        trim((string)$vn),
        $module_key,
        $level,
        trim((string)$telegram_chat_id),
    ]);
}

function sp_telegram_mark_sent($con, $dedupe_key, $patient_type, $screen_date, $hn, $module_key, $level, $telegram_chat_id) {
    require_once __DIR__ . '/smart_pharma_notify_helper.php';
    return sp_notify_mark_sent(
        $con,
        'smart_pharma_telegram_sent',
        $dedupe_key,
        $patient_type,
        $screen_date,
        $hn,
        $module_key,
        $level,
        $telegram_chat_id,
        'chat_id'
    );
}

function sp_telegram_build_message($patient_type, array $patient, $module_label, $level, array $mod) {
    $lvl = strtoupper($level === 'hard' ? 'HARD' : 'SOFT');
    $icon = $level === 'hard' ? '🔴' : '🟡';
    $ptype = $patient_type === 'IPD' ? 'IPD' : 'OPD';
    $hn = sp_telegram_escape_html(ltrim($patient['hn'] ?? '', '0') ?: ($patient['hn_full'] ?? ''));
    $name = sp_telegram_escape_html($patient['ptname'] ?? '');
    $ward = sp_telegram_escape_html($patient['ward_display'] ?? $ptype);
    $rx = sp_telegram_escape_html(trim(($patient['rxdate'] ?? '') . ' ' . ($patient['rxtime'] ?? '')));
    $summary = sp_telegram_escape_html($mod['summary'] ?? '');
    $detail = '';
    if (!empty($mod['details']) && is_array($mod['details'])) {
        $detail = sp_telegram_escape_html(implode("\n", array_slice($mod['details'], 0, 3)));
    }
    $msg = "🚨 <b>Smart Pharma Alert</b> [{$ptype}]\n";
    $msg .= "━━━━━━━━━━━━━━\n";
    $msg .= "👤 <b>{$name}</b>\n";
    $msg .= "🆔 HN: <code>{$hn}</code>\n";
    if (!empty($patient['vn'])) {
        $msg .= '📋 VN: <code>' . sp_telegram_escape_html($patient['vn']) . "</code>\n";
    }
    $msg .= "🏥 {$ward}\n";
    if ($rx !== '') {
        $msg .= "💊 สั่งยา: {$rx}\n";
    }
    $msg .= "{$icon} <b>{$module_label}</b> [{$lvl}]\n";
    if ($summary !== '') {
        $msg .= "📝 {$summary}\n";
    }
    if ($detail !== '') {
        $msg .= "▸ {$detail}\n";
    }
    return sp_text_truncate($msg, 3900);
}

/**
 * ส่งแจ้งเตือนตามกฎที่ตั้งไว้ (Soft = ครั้งเดียว, Hard = ซ้ำตามช่วงเวลาที่ตั้ง)
 * @return array{sent:int, skipped:int, errors:array}
 */
function sp_telegram_notify_screening($con, $patient_type, $screen_date, array $patients, array $module_labels = []) {
    require_once __DIR__ . '/smart_pharma_notify_helper.php';
    require_once __DIR__ . '/smart_pharma_alert_log_helper.php';
    $result = ['sent' => 0, 'skipped' => 0, 'errors' => []];
    $cfg = sp_telegram_get_config($con);
    if (empty($cfg['enabled'])) {
        return $result;
    }
    $rules = sp_telegram_load_active_rules($con);
    if (empty($rules)) {
        return $result;
    }
    $rule_map = [];
    foreach ($rules as $rule) {
        if (($rule['patient_type'] ?? '') !== $patient_type) {
            continue;
        }
        $mk = $rule['module_key'] ?? '';
        if ($mk === '') {
            continue;
        }
        if (!isset($rule_map[$mk])) {
            $rule_map[$mk] = [];
        }
        $rule_map[$mk][] = $rule;
    }
    if (empty($rule_map)) {
        return $result;
    }

    foreach ($patients as $patient) {
        if (($patient['overall'] ?? '') !== 'alert') {
            continue;
        }
        $mods = $patient['modules'] ?? [];
        foreach ($mods as $module_key => $mod) {
            if (($mod['status'] ?? '') !== 'alert') {
                continue;
            }
            $level = ($mod['level'] ?? '') === 'hard' ? 'hard' : 'soft';
            if (empty($rule_map[$module_key])) {
                continue;
            }
            $module_label = $module_labels[$module_key] ?? $module_key;
            $hn_full = $patient['hn_full'] ?? $patient['hn'] ?? '';
            $log_row = sp_alert_log_find_by_uid(
                $con,
                $patient_type,
                $screen_date,
                $hn_full,
                $patient['vn'] ?? '',
                $module_key
            );
            foreach ($rule_map[$module_key] as $rule) {
                if ($level === 'hard' && empty($rule['notify_hard'])) {
                    continue;
                }
                if ($level === 'soft' && empty($rule['notify_soft'])) {
                    continue;
                }
                $tg_chat = $rule['telegram_chat_id'] ?? '';
                if ($tg_chat === '') {
                    continue;
                }
                $repeat_minutes = sp_notify_repeat_minutes_from_rule($rule, $level);
                $stop_minutes = sp_notify_stop_minutes_from_rule($rule, $level);
                $dedupe = sp_telegram_dedupe_key(
                    $patient_type,
                    $screen_date,
                    $hn_full,
                    $patient['vn'] ?? '',
                    $module_key,
                    $level,
                    $tg_chat
                );
                $send_check = sp_notify_claim_send(
                    $con,
                    'smart_pharma_telegram_sent',
                    $dedupe,
                    $level,
                    $repeat_minutes,
                    $log_row,
                    $stop_minutes,
                    $patient_type,
                    $screen_date,
                    $hn_full,
                    $module_key,
                    $tg_chat,
                    false
                );
                if (empty($send_check['send'])) {
                    $result['skipped']++;
                    continue;
                }
                $bot_token = sp_telegram_resolve_bot_token($rule);
                if ($bot_token === '') {
                    if (empty($send_check['is_repeat'])) {
                        sp_notify_release_first_claim($con, 'smart_pharma_telegram_sent', $dedupe);
                    }
                    $result['errors'][] = ($rule['chat_label'] ?? $tg_chat) . ': ยังไม่ได้ตั้ง Bot Token (API)';
                    continue;
                }
                $msg = sp_telegram_build_message($patient_type, $patient, $module_label, $level, $mod);
                $alert_log_id = (int)($log_row['id'] ?? 0);
                $msg = sp_telegram_append_rule_links(
                    $msg,
                    $rule,
                    $cfg,
                    $patient_type,
                    $screen_date,
                    $patient,
                    $module_key,
                    $alert_log_id
                );
                if (!empty($send_check['is_repeat'])) {
                    $msg = "🔁 <b>[แจ้งซ้ำ Hard Alert]</b>\n" . $msg;
                }
                $send = sp_telegram_api_send($bot_token, $tg_chat, $msg);
                if (!empty($send['ok'])) {
                    $result['sent']++;
                } else {
                    if (empty($send_check['is_repeat'])) {
                        sp_notify_release_first_claim($con, 'smart_pharma_telegram_sent', $dedupe);
                    }
                    $result['errors'][] = ($rule['chat_label'] ?? $tg_chat) . ': ' . ($send['error'] ?? 'ส่งไม่สำเร็จ');
                }
            }
        }
    }
    return $result;
}

/**
 * แจ้งซ้ำ Hard Alert ที่ยัง Pending (รวมรายที่หลุดจากหน้าคัดกรอง)
 * @return array{sent:int, skipped:int, errors:array}
 */
function sp_telegram_notify_pending_repeats($con, array $module_labels = []) {
    require_once __DIR__ . '/smart_pharma_notify_helper.php';
    $result = ['sent' => 0, 'skipped' => 0, 'errors' => []];
    $cfg = sp_telegram_get_config($con);
    if (empty($cfg['enabled'])) {
        return $result;
    }
    $rules = sp_telegram_load_active_rules($con);
    if (empty($rules)) {
        return $result;
    }
    $rule_map = [];
    foreach ($rules as $rule) {
        if (empty($rule['notify_hard'])) {
            continue;
        }
        if (sp_notify_repeat_minutes_from_rule($rule, 'hard') <= 0) {
            continue;
        }
        $ptype = $rule['patient_type'] ?? '';
        $mk = $rule['module_key'] ?? '';
        if ($ptype === '' || $mk === '') {
            continue;
        }
        $rule_map[$ptype][$mk][] = $rule;
    }
    if (empty($rule_map)) {
        return $result;
    }

    $logs = sp_alert_log_list_pending_hard_notify($con);
    foreach ($logs as $log) {
        $patient_type = ($log['patient_type'] ?? '') === 'IPD' ? 'IPD' : 'OPD';
        $module_key = $log['module_key'] ?? '';
        if (empty($rule_map[$patient_type][$module_key])) {
            continue;
        }
        foreach ($rule_map[$patient_type][$module_key] as $rule) {
            $tg_chat = $rule['telegram_chat_id'] ?? '';
            if ($tg_chat === '') {
                continue;
            }
            $repeat_minutes = sp_notify_repeat_minutes_from_rule($rule, 'hard');
            $stop_minutes = sp_notify_stop_minutes_from_rule($rule, 'hard');
            $screen_date = $log['screen_date'] ?? date('Y-m-d');
            $hn_full = $log['hn'] ?? '';
            $dedupe = sp_telegram_dedupe_key(
                $patient_type,
                $screen_date,
                $hn_full,
                $log['vn'] ?? '',
                $module_key,
                'hard',
                $tg_chat
            );
            $send_check = sp_notify_claim_send(
                $con,
                'smart_pharma_telegram_sent',
                $dedupe,
                'hard',
                $repeat_minutes,
                $log,
                $stop_minutes,
                $patient_type,
                $screen_date,
                $hn_full,
                $module_key,
                $tg_chat,
                true
            );
            if (empty($send_check['send'])) {
                $result['skipped']++;
                continue;
            }
            $bot_token = sp_telegram_resolve_bot_token($rule);
            if ($bot_token === '') {
                $result['errors'][] = ($rule['chat_label'] ?? $tg_chat) . ': ยังไม่ได้ตั้ง Bot Token (API)';
                continue;
            }
            $msg = sp_telegram_build_message_from_alert_log($log, $cfg, $rule);
            if (!empty($send_check['is_repeat'])) {
                $msg = "🔁 <b>[แจ้งซ้ำ Hard Alert]</b>\n" . $msg;
            }
            $send = sp_telegram_api_send($bot_token, $tg_chat, $msg);
            if (!empty($send['ok'])) {
                $result['sent']++;
            } else {
                $result['errors'][] = ($rule['chat_label'] ?? $tg_chat) . ': ' . ($send['error'] ?? 'ส่งไม่สำเร็จ');
            }
        }
    }
    return $result;
}

function sp_telegram_patient_from_alert_log(array $log) {
    return [
        'hn' => ltrim((string)($log['hn'] ?? ''), '0') ?: ($log['hn'] ?? ''),
        'hn_full' => $log['hn'] ?? '',
        'vn' => $log['vn'] ?? '',
        'an' => $log['an'] ?? '',
        'ptname' => trim((string)($log['ptname'] ?? '')),
        'ward_display' => trim((string)($log['ward_display'] ?? '')) ?: ($log['patient_type'] ?? 'OPD'),
        'rxdate' => $log['rxdate'] ?? '',
        'rxtime' => $log['rxtime'] ?? '',
    ];
}

function sp_telegram_mod_from_alert_log(array $log) {
    $mod = [
        'summary' => trim((string)($log['alert_summary'] ?? '')),
        'details' => [],
    ];
    if (!empty($log['alert_details'])) {
        $det = json_decode((string)$log['alert_details'], true);
        if (is_array($det)) {
            $mod['details'] = $det;
        }
    }
    return $mod;
}

function sp_telegram_build_message_from_alert_log(array $log, array $cfg, array $rule = null) {
    if ($rule === null) {
        $rule = ['link_mode' => 'auto', 'extra_message' => '', 'extra_link' => '', 'extra_link_label' => ''];
    }
    $patient_type = ($log['patient_type'] ?? '') === 'IPD' ? 'IPD' : 'OPD';
    $level = ($log['alert_level'] ?? '') === 'hard' ? 'hard' : 'soft';
    $patient = sp_telegram_patient_from_alert_log($log);
    $mod = sp_telegram_mod_from_alert_log($log);
    $module_label = trim((string)($log['module_label'] ?? '')) ?: ($log['module_key'] ?? '');
    $screen_date = $log['screen_date'] ?? date('Y-m-d');
    $module_key = $log['module_key'] ?? '';
    $msg = sp_telegram_build_message($patient_type, $patient, $module_label, $level, $mod);
    return sp_telegram_append_rule_links(
        $msg,
        $rule,
        $cfg,
        $patient_type,
        $screen_date,
        $patient,
        $module_key,
        (int)($log['id'] ?? 0)
    );
}

/**
 * @param string $mode simple|sample
 * @return array{ok:bool, error?:string, sent?:int, total?:int}
 */
function sp_telegram_send_test($con, $chat_id, array $chat_row = [], $mode = 'simple') {
    $bot_token = sp_telegram_resolve_bot_token($chat_row);
    if ($bot_token === '') {
        return ['ok' => false, 'error' => 'ยังไม่ได้ตั้ง Bot Token (API) ในกลุ่มนี้'];
    }
    if ($mode !== 'sample') {
        $text = "✅ <b>THAM Smart Pharma</b>\nทดสอบแจ้งเตือน Telegram สำเร็จ\n" . date('Y-m-d H:i:s');
        $send = sp_telegram_api_send($bot_token, $chat_id, $text);
        return array_merge($send, ['sent' => !empty($send['ok']) ? 1 : 0, 'total' => 1]);
    }

    require_once __DIR__ . '/smart_pharma_alert_log_helper.php';
    $logs = sp_alert_log_recent_samples($con, 3);
    if (empty($logs)) {
        return [
            'ok' => false,
            'error' => 'ไม่พบ Alert ใน Intervention Log — ลองคัดกรอง OPD/IPD หรือรอ Cron บันทึก Log ก่อน',
        ];
    }

    $cfg = sp_telegram_get_config($con);
    $rule = ['link_mode' => 'auto', 'extra_message' => '', 'extra_link' => '', 'extra_link_label' => ''];
    $total = count($logs);
    $sent = 0;
    $errors = [];
    foreach ($logs as $i => $log) {
        $body = sp_telegram_build_message_from_alert_log($log, $cfg, $rule);
        $text = '🧪 <b>[ทดสอบ ' . ($i + 1) . '/' . $total . ' — ข้อมูลจริง]</b>' . "\n" . $body;
        $send = sp_telegram_api_send($bot_token, $chat_id, $text);
        if (!empty($send['ok'])) {
            $sent++;
        } else {
            $errors[] = $send['error'] ?? 'ส่งไม่สำเร็จ';
        }
    }
    if ($sent <= 0) {
        return ['ok' => false, 'error' => $errors[0] ?? 'ส่งไม่สำเร็จ', 'sent' => 0, 'total' => $total];
    }
    return [
        'ok' => true,
        'sent' => $sent,
        'total' => $total,
        'error' => $sent < $total ? ('ส่งได้ ' . $sent . '/' . $total . ' รายการ') : '',
    ];
}
