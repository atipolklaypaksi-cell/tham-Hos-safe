<?php
/**
 * Smart Pharma — Webhook แจ้งเตือน Alert
 */

function sp_webhook_ensure_tables($con) {
    require_once __DIR__ . '/smart_pharma_telegram_helper.php';
    $sql = @file_get_contents(__DIR__ . '/sql/smart_pharma_webhook.sql');
    if (!$sql) {
        return false;
    }
    if (!mysqli_multi_query($con, $sql)) {
        return false;
    }
    while (mysqli_more_results($con)) {
        mysqli_next_result($con);
    }
    $repeat_cols = [
        'hard_repeat_mode' => "ALTER TABLE smart_pharma_webhook_rules ADD COLUMN hard_repeat_mode VARCHAR(10) NOT NULL DEFAULT 'off' AFTER extra_link_label",
        'hard_repeat_minutes' => "ALTER TABLE smart_pharma_webhook_rules ADD COLUMN hard_repeat_minutes INT NOT NULL DEFAULT 0 AFTER hard_repeat_mode",
        'soft_repeat_mode' => "ALTER TABLE smart_pharma_webhook_rules ADD COLUMN soft_repeat_mode VARCHAR(10) NOT NULL DEFAULT 'off' AFTER hard_repeat_minutes",
        'soft_repeat_minutes' => "ALTER TABLE smart_pharma_webhook_rules ADD COLUMN soft_repeat_minutes INT NOT NULL DEFAULT 0 AFTER soft_repeat_mode",
        'stop_mode' => "ALTER TABLE smart_pharma_webhook_rules ADD COLUMN stop_mode VARCHAR(10) NOT NULL DEFAULT 'off' AFTER soft_repeat_minutes",
        'stop_minutes' => "ALTER TABLE smart_pharma_webhook_rules ADD COLUMN stop_minutes INT NOT NULL DEFAULT 0 AFTER stop_mode",
        'hard_stop_mode' => "ALTER TABLE smart_pharma_webhook_rules ADD COLUMN hard_stop_mode VARCHAR(10) NOT NULL DEFAULT 'off' AFTER stop_minutes",
        'hard_stop_minutes' => "ALTER TABLE smart_pharma_webhook_rules ADD COLUMN hard_stop_minutes INT NOT NULL DEFAULT 0 AFTER hard_stop_mode",
        'soft_stop_mode' => "ALTER TABLE smart_pharma_webhook_rules ADD COLUMN soft_stop_mode VARCHAR(10) NOT NULL DEFAULT 'off' AFTER hard_stop_minutes",
        'soft_stop_minutes' => "ALTER TABLE smart_pharma_webhook_rules ADD COLUMN soft_stop_minutes INT NOT NULL DEFAULT 0 AFTER soft_stop_mode",
    ];
    $migrated_stop_split = false;
    foreach ($repeat_cols as $col => $alter) {
        $before = mysqli_query($con, "SHOW COLUMNS FROM smart_pharma_webhook_rules LIKE '$col'");
        $missing = $before && mysqli_num_rows($before) === 0;
        sp_db_add_column_if_missing($con, 'smart_pharma_webhook_rules', $col, $alter);
        if ($missing && $col === 'soft_stop_minutes') {
            $migrated_stop_split = true;
        }
    }
    if ($migrated_stop_split) {
        mysqli_query($con, "UPDATE smart_pharma_webhook_rules
            SET hard_stop_mode = stop_mode, soft_stop_mode = stop_mode,
                hard_stop_minutes = stop_minutes, soft_stop_minutes = stop_minutes
            WHERE stop_mode != 'off' OR stop_minutes > 0");
    }
    return true;
}

function sp_webhook_get_config($con) {
    $cfg = ['enabled' => false, 'base_url' => ''];
    $q = mysqli_query($con, "SELECT config_name, config_value FROM sys_config
        WHERE config_name IN ('sp_webhook_enabled', 'sp_telegram_base_url')");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            if ($r['config_name'] === 'sp_webhook_enabled') {
                $cfg['enabled'] = !empty($r['config_value']) && $r['config_value'] !== '0';
            }
            if ($r['config_name'] === 'sp_telegram_base_url') {
                $cfg['base_url'] = trim((string)($r['config_value'] ?? ''));
            }
        }
    }
    return $cfg;
}

function sp_webhook_save_config($con, $enabled, $base_url = null) {
    $en = $enabled ? '1' : '0';
    mysqli_query($con, "INSERT INTO sys_config (config_name, config_value) VALUES ('sp_webhook_enabled', '$en')
        ON DUPLICATE KEY UPDATE config_value = '$en'");
    if ($base_url !== null) {
        require_once __DIR__ . '/smart_pharma_telegram_helper.php';
        sp_telegram_save_config($con, true, null, $base_url);
    }
    return true;
}

function sp_webhook_list_endpoints($con) {
    $rows = [];
    $q = mysqli_query($con, "SELECT * FROM smart_pharma_webhook_endpoints ORDER BY sort_order ASC, label ASC, id ASC");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $rows[] = $r;
        }
    }
    return $rows;
}

function sp_webhook_save_endpoint($con, array $data) {
    $id = (int)($data['id'] ?? 0);
    $label = mysqli_real_escape_string($con, trim((string)($data['label'] ?? '')));
    $url = mysqli_real_escape_string($con, trim((string)($data['webhook_url'] ?? '')));
    $secret = mysqli_real_escape_string($con, trim((string)($data['secret'] ?? '')));
    $notes = mysqli_real_escape_string($con, trim((string)($data['notes'] ?? '')));
    $active = !empty($data['active']) ? 1 : 0;
    $sort = (int)($data['sort_order'] ?? 0);
    if ($label === '' || $url === '' || !preg_match('#^https?://#i', $data['webhook_url'] ?? '')) {
        return false;
    }
    $secret_sql = $secret !== '' ? "'$secret'" : 'NULL';
    if ($id > 0) {
        return mysqli_query($con, "UPDATE smart_pharma_webhook_endpoints
            SET label='$label', webhook_url='$url', secret=$secret_sql, notes='$notes', active=$active, sort_order=$sort
            WHERE id=$id");
    }
    return mysqli_query($con, "INSERT INTO smart_pharma_webhook_endpoints (label, webhook_url, secret, notes, active, sort_order)
        VALUES ('$label','$url',$secret_sql,'$notes',$active,$sort)");
}

function sp_webhook_delete_endpoint($con, $id) {
    $id = (int)$id;
    if ($id <= 0) {
        return false;
    }
    mysqli_query($con, "DELETE FROM smart_pharma_webhook_rules WHERE endpoint_id=$id");
    return mysqli_query($con, "DELETE FROM smart_pharma_webhook_endpoints WHERE id=$id");
}

function sp_webhook_list_rules($con) {
    $rows = [];
    $q = mysqli_query($con, "SELECT r.*, e.label AS endpoint_label, e.webhook_url, e.active AS endpoint_active,
            m.label_th AS module_label
        FROM smart_pharma_webhook_rules r
        INNER JOIN smart_pharma_webhook_endpoints e ON e.id = r.endpoint_id
        LEFT JOIN smart_pharma_modules m ON m.module_key = r.module_key
        ORDER BY e.sort_order, e.label, r.patient_type, m.sort_order, r.module_key");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $rows[] = $r;
        }
    }
    return $rows;
}

function sp_webhook_save_rule($con, array $data) {
    require_once __DIR__ . '/smart_pharma_telegram_helper.php';
    $id = (int)($data['id'] ?? 0);
    $endpoint_id = (int)($data['endpoint_id'] ?? 0);
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
    if ($endpoint_id <= 0 || $module === '' || (!$hard && !$soft)) {
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
        return mysqli_query($con, "UPDATE smart_pharma_webhook_rules
            SET endpoint_id=$endpoint_id, patient_type='$ptype_esc', module_key='$module',
                notify_hard=$hard, notify_soft=$soft, link_mode='$link_esc',
                extra_message=$extra_msg_sql, extra_link=$extra_link_sql, extra_link_label=$extra_label_sql,
                hard_repeat_mode='$repeat_esc', hard_repeat_minutes=$repeat_minutes,
                soft_repeat_mode='$soft_repeat_esc', soft_repeat_minutes=$soft_repeat_minutes,
                stop_mode='$stop_mode_esc', stop_minutes=$stop_minutes,
                hard_stop_mode='$hard_stop_esc', hard_stop_minutes=$hard_stop_minutes,
                soft_stop_mode='$soft_stop_esc', soft_stop_minutes=$soft_stop_minutes,
                active=$active WHERE id=$id");
    }
    return mysqli_query($con, "INSERT INTO smart_pharma_webhook_rules
        (endpoint_id, patient_type, module_key, notify_hard, notify_soft, link_mode, extra_message, extra_link, extra_link_label, hard_repeat_mode, hard_repeat_minutes, soft_repeat_mode, soft_repeat_minutes, stop_mode, stop_minutes, hard_stop_mode, hard_stop_minutes, soft_stop_mode, soft_stop_minutes, active)
        VALUES ($endpoint_id,'$ptype_esc','$module',$hard,$soft,'$link_esc',$extra_msg_sql,$extra_link_sql,$extra_label_sql,'$repeat_esc',$repeat_minutes,'$soft_repeat_esc',$soft_repeat_minutes,'$stop_mode_esc',$stop_minutes,'$hard_stop_esc',$hard_stop_minutes,'$soft_stop_esc',$soft_stop_minutes,$active)
        ON DUPLICATE KEY UPDATE notify_hard=$hard, notify_soft=$soft, link_mode='$link_esc',
            extra_message=$extra_msg_sql, extra_link=$extra_link_sql, extra_link_label=$extra_label_sql,
            hard_repeat_mode='$repeat_esc', hard_repeat_minutes=$repeat_minutes,
            soft_repeat_mode='$soft_repeat_esc', soft_repeat_minutes=$soft_repeat_minutes,
            stop_mode='$stop_mode_esc', stop_minutes=$stop_minutes,
            hard_stop_mode='$hard_stop_esc', hard_stop_minutes=$hard_stop_minutes,
            soft_stop_mode='$soft_stop_esc', soft_stop_minutes=$soft_stop_minutes, active=$active");
}

function sp_webhook_delete_rule($con, $id) {
    $id = (int)$id;
    if ($id <= 0) {
        return false;
    }
    return mysqli_query($con, "DELETE FROM smart_pharma_webhook_rules WHERE id=$id");
}

function sp_webhook_dedupe_key($patient_type, $screen_date, $hn, $vn, $module_key, $level, $endpoint_id) {
    return implode('|', [
        $patient_type,
        $screen_date,
        trim((string)$hn),
        trim((string)$vn),
        $module_key,
        $level,
        (string)(int)$endpoint_id,
    ]);
}

function sp_webhook_mark_sent($con, $dedupe_key, $patient_type, $screen_date, $hn, $module_key, $level, $endpoint_id) {
    require_once __DIR__ . '/smart_pharma_notify_helper.php';
    return sp_notify_mark_sent(
        $con,
        'smart_pharma_webhook_sent',
        $dedupe_key,
        $patient_type,
        $screen_date,
        $hn,
        $module_key,
        $level,
        (string)(int)$endpoint_id,
        'endpoint_id'
    );
}

function sp_webhook_build_links(array $rule, array $cfg, $patient_type, $screen_date, array $patient, $module_key, $alert_log_id = 0) {
    require_once __DIR__ . '/smart_pharma_telegram_helper.php';
    $mode = sp_telegram_normalize_link_mode($rule['link_mode'] ?? 'auto');
    $links = [];
    if ($mode === 'none') {
        return $links;
    }
    $base_url = sp_telegram_resolve_base_url($cfg);
    if (($mode === 'auto' || $mode === 'both') && $base_url !== '') {
        $url = sp_telegram_build_alert_link($base_url, $patient_type, $screen_date, $patient, $module_key, $alert_log_id);
        if ($url !== '') {
            $links['intervention_log'] = $url;
        }
    }
    $extra_link = trim((string)($rule['extra_link'] ?? ''));
    if (($mode === 'custom' || $mode === 'both') && $extra_link !== '') {
        $label = trim((string)($rule['extra_link_label'] ?? ''));
        $links['extra'] = ['url' => $extra_link, 'label' => $label !== '' ? $label : 'ลิงก์เพิ่มเติม'];
    }
    $extra_msg = trim((string)($rule['extra_message'] ?? ''));
    if ($extra_msg !== '' && ($mode === 'custom' || $mode === 'both')) {
        $links['extra_message'] = $extra_msg;
    }
    return $links;
}

function sp_webhook_build_payload($patient_type, $screen_date, array $patient, $module_key, $module_label, $level, array $mod, array $rule, array $cfg, $alert_log_id = 0, $is_test = false, $is_repeat = false) {
    $hn_full = $patient['hn_full'] ?? $patient['hn'] ?? '';
    $links = sp_webhook_build_links($rule, $cfg, $patient_type, $screen_date, $patient, $module_key, $alert_log_id);
    return [
        'event' => $is_test ? 'smart_pharma.alert.test' : ($is_repeat ? 'smart_pharma.alert.repeat' : 'smart_pharma.alert'),
        'version' => '1.0',
        'sent_at' => date('c'),
        'repeat' => (bool)$is_repeat,
        'patient_type' => $patient_type,
        'screen_date' => $screen_date,
        'alert_log_id' => $alert_log_id > 0 ? $alert_log_id : null,
        'patient' => [
            'hn' => ltrim((string)($patient['hn'] ?? ''), '0') ?: $hn_full,
            'hn_full' => $hn_full,
            'vn' => $patient['vn'] ?? '',
            'an' => $patient['an'] ?? '',
            'ptname' => trim((string)($patient['ptname'] ?? '')),
            'ward_display' => $patient['ward_display'] ?? '',
            'rxdate' => $patient['rxdate'] ?? '',
            'rxtime' => $patient['rxtime'] ?? '',
        ],
        'alert' => [
            'module_key' => $module_key,
            'module_label' => $module_label,
            'level' => $level,
            'summary' => $mod['summary'] ?? '',
            'details' => $mod['details'] ?? [],
            'items' => $mod['items'] ?? [],
        ],
        'links' => $links,
    ];
}

function sp_webhook_http_post($url, array $payload, $secret = '') {
    $url = trim((string)$url);
    if ($url === '') {
        return ['ok' => false, 'error' => 'URL ว่าง'];
    }
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        return ['ok' => false, 'error' => 'encode JSON ไม่สำเร็จ'];
    }
    $headers = ['Content-Type: application/json', 'User-Agent: THAM-SmartPharma-Webhook/1.0'];
    $secret = trim((string)$secret);
    if ($secret !== '') {
        $headers[] = 'X-Webhook-Signature: sha256=' . hash_hmac('sha256', $body, $secret);
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            return ['ok' => false, 'error' => $err ?: 'curl failed'];
        }
        if ($code >= 200 && $code < 300) {
            return ['ok' => true, 'http_code' => $code];
        }
        return ['ok' => false, 'error' => 'HTTP ' . $code, 'http_code' => $code, 'body' => sp_text_truncate((string)$resp, 200)];
    }
    $header_str = implode("\r\n", $headers) . "\r\n";
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => $header_str,
            'content' => $body,
            'timeout' => 15,
            'ignore_errors' => true,
        ],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) {
        return ['ok' => false, 'error' => 'HTTP request failed'];
    }
    return ['ok' => true];
}

function sp_webhook_load_active_rules($con) {
    $rules = [];
    $q = mysqli_query($con, "SELECT r.*, e.label AS endpoint_label, e.webhook_url, e.secret AS endpoint_secret
        FROM smart_pharma_webhook_rules r
        INNER JOIN smart_pharma_webhook_endpoints e ON e.id = r.endpoint_id AND e.active = 1
        WHERE r.active = 1");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $rules[] = $r;
        }
    }
    return $rules;
}

/**
 * @return array{sent:int, skipped:int, errors:array}
 */
function sp_webhook_notify_screening($con, $patient_type, $screen_date, array $patients, array $module_labels = []) {
    require_once __DIR__ . '/smart_pharma_notify_helper.php';
    require_once __DIR__ . '/smart_pharma_alert_log_helper.php';
    $result = ['sent' => 0, 'skipped' => 0, 'errors' => []];
    $cfg = sp_webhook_get_config($con);
    if (empty($cfg['enabled'])) {
        return $result;
    }
    $rules = sp_webhook_load_active_rules($con);
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
            $log_row = sp_alert_log_find_by_uid($con, $patient_type, $screen_date, $hn_full, $patient['vn'] ?? '', $module_key);
            $alert_log_id = (int)($log_row['id'] ?? 0);

            foreach ($rule_map[$module_key] as $rule) {
                if ($level === 'hard' && empty($rule['notify_hard'])) {
                    continue;
                }
                if ($level === 'soft' && empty($rule['notify_soft'])) {
                    continue;
                }
                $endpoint_id = (int)($rule['endpoint_id'] ?? 0);
                $webhook_url = trim((string)($rule['webhook_url'] ?? ''));
                if ($endpoint_id <= 0 || $webhook_url === '') {
                    continue;
                }
                $repeat_minutes = sp_notify_repeat_minutes_from_rule($rule, $level);
                $stop_minutes = sp_notify_stop_minutes_from_rule($rule, $level);
                $dedupe = sp_webhook_dedupe_key(
                    $patient_type,
                    $screen_date,
                    $hn_full,
                    $patient['vn'] ?? '',
                    $module_key,
                    $level,
                    $endpoint_id
                );
                $send_check = sp_notify_claim_send(
                    $con,
                    'smart_pharma_webhook_sent',
                    $dedupe,
                    $level,
                    $repeat_minutes,
                    $log_row,
                    $stop_minutes,
                    $patient_type,
                    $screen_date,
                    $hn_full,
                    $module_key,
                    $endpoint_id,
                    false
                );
                if (empty($send_check['send'])) {
                    $result['skipped']++;
                    continue;
                }
                $payload = sp_webhook_build_payload(
                    $patient_type,
                    $screen_date,
                    $patient,
                    $module_key,
                    $module_label,
                    $level,
                    $mod,
                    $rule,
                    $cfg,
                    $alert_log_id,
                    false,
                    !empty($send_check['is_repeat'])
                );
                $send = sp_webhook_http_post($webhook_url, $payload, $rule['endpoint_secret'] ?? '');
                if (!empty($send['ok'])) {
                    $result['sent']++;
                } else {
                    if (empty($send_check['is_repeat'])) {
                        sp_notify_release_first_claim($con, 'smart_pharma_webhook_sent', $dedupe);
                    }
                    $result['errors'][] = ($rule['endpoint_label'] ?? $webhook_url) . ': ' . ($send['error'] ?? 'ส่งไม่สำเร็จ');
                }
            }
        }
    }
    return $result;
}

/**
 * @return array{sent:int, skipped:int, errors:array}
 */
function sp_webhook_notify_pending_repeats($con, array $module_labels = []) {
    require_once __DIR__ . '/smart_pharma_notify_helper.php';
    require_once __DIR__ . '/smart_pharma_telegram_helper.php';
    $result = ['sent' => 0, 'skipped' => 0, 'errors' => []];
    $cfg = sp_webhook_get_config($con);
    if (empty($cfg['enabled'])) {
        return $result;
    }
    $rules = sp_webhook_load_active_rules($con);
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
        $patient = sp_telegram_patient_from_alert_log($log);
        $mod = sp_telegram_mod_from_alert_log($log);
        $module_label = trim((string)($log['module_label'] ?? '')) ?: $module_key;
        $screen_date = $log['screen_date'] ?? date('Y-m-d');
        $hn_full = $log['hn'] ?? '';
        $alert_log_id = (int)($log['id'] ?? 0);

        foreach ($rule_map[$patient_type][$module_key] as $rule) {
            $endpoint_id = (int)($rule['endpoint_id'] ?? 0);
            $webhook_url = trim((string)($rule['webhook_url'] ?? ''));
            if ($endpoint_id <= 0 || $webhook_url === '') {
                continue;
            }
            $repeat_minutes = sp_notify_repeat_minutes_from_rule($rule, 'hard');
            $stop_minutes = sp_notify_stop_minutes_from_rule($rule, 'hard');
            $dedupe = sp_webhook_dedupe_key(
                $patient_type,
                $screen_date,
                $hn_full,
                $log['vn'] ?? '',
                $module_key,
                'hard',
                $endpoint_id
            );
            $send_check = sp_notify_claim_send(
                $con,
                'smart_pharma_webhook_sent',
                $dedupe,
                'hard',
                $repeat_minutes,
                $log,
                $stop_minutes,
                $patient_type,
                $screen_date,
                $hn_full,
                $module_key,
                $endpoint_id,
                true
            );
            if (empty($send_check['send'])) {
                $result['skipped']++;
                continue;
            }
            $payload = sp_webhook_build_payload(
                $patient_type,
                $screen_date,
                $patient,
                $module_key,
                $module_label,
                'hard',
                $mod,
                $rule,
                $cfg,
                $alert_log_id,
                false,
                !empty($send_check['is_repeat'])
            );
            $send = sp_webhook_http_post($webhook_url, $payload, $rule['endpoint_secret'] ?? '');
            if (!empty($send['ok'])) {
                $result['sent']++;
            } else {
                $result['errors'][] = ($rule['endpoint_label'] ?? $webhook_url) . ': ' . ($send['error'] ?? 'ส่งไม่สำเร็จ');
            }
        }
    }
    return $result;
}

/**
 * @return array{ok:bool, error?:string, sent?:int, total?:int}
 */
function sp_webhook_send_test($con, $endpoint_row, $mode = 'simple') {
    $url = trim((string)($endpoint_row['webhook_url'] ?? ''));
    if ($url === '') {
        return ['ok' => false, 'error' => 'ยังไม่ได้ตั้ง Webhook URL'];
    }
    $secret = trim((string)($endpoint_row['secret'] ?? ''));
    $cfg = sp_webhook_get_config($con);
    $rule = ['link_mode' => 'auto', 'extra_message' => '', 'extra_link' => '', 'extra_link_label' => ''];

    if ($mode !== 'sample') {
        $payload = [
            'event' => 'smart_pharma.ping',
            'version' => '1.0',
            'sent_at' => date('c'),
            'message' => 'THAM Smart Pharma Webhook test OK',
        ];
        $send = sp_webhook_http_post($url, $payload, $secret);
        return array_merge($send, ['sent' => !empty($send['ok']) ? 1 : 0, 'total' => 1]);
    }

    require_once __DIR__ . '/smart_pharma_alert_log_helper.php';
    $logs = sp_alert_log_recent_samples($con, 3);
    if (empty($logs)) {
        return ['ok' => false, 'error' => 'ไม่พบ Alert ใน Intervention Log — ลองคัดกรอง OPD/IPD ก่อน'];
    }

    $sent = 0;
    $total = count($logs);
    $errors = [];
    foreach ($logs as $log) {
        $patient_type = ($log['patient_type'] ?? '') === 'IPD' ? 'IPD' : 'OPD';
        $level = ($log['alert_level'] ?? '') === 'hard' ? 'hard' : 'soft';
        $patient = [
            'hn' => ltrim((string)($log['hn'] ?? ''), '0') ?: ($log['hn'] ?? ''),
            'hn_full' => $log['hn'] ?? '',
            'vn' => $log['vn'] ?? '',
            'an' => $log['an'] ?? '',
            'ptname' => $log['ptname'] ?? '',
            'ward_display' => $log['ward_display'] ?? '',
            'rxdate' => $log['rxdate'] ?? '',
            'rxtime' => $log['rxtime'] ?? '',
        ];
        $mod = [
            'summary' => $log['alert_summary'] ?? '',
            'details' => [],
        ];
        if (!empty($log['alert_details'])) {
            $det = json_decode((string)$log['alert_details'], true);
            if (is_array($det)) {
                $mod['details'] = $det;
            }
        }
        $payload = sp_webhook_build_payload(
            $patient_type,
            $log['screen_date'] ?? date('Y-m-d'),
            $patient,
            $log['module_key'] ?? '',
            $log['module_label'] ?? ($log['module_key'] ?? ''),
            $level,
            $mod,
            $rule,
            $cfg,
            (int)($log['id'] ?? 0),
            true
        );
        $send = sp_webhook_http_post($url, $payload, $secret);
        if (!empty($send['ok'])) {
            $sent++;
        } else {
            $errors[] = $send['error'] ?? 'ส่งไม่สำเร็จ';
        }
    }
    if ($sent <= 0) {
        return ['ok' => false, 'error' => $errors[0] ?? 'ส่งไม่สำเร็จ', 'sent' => 0, 'total' => $total];
    }
    return ['ok' => true, 'sent' => $sent, 'total' => $total];
}
