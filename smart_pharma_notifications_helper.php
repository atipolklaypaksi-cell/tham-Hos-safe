<?php
/**
 * Smart Pharma — แจ้งเตือนรวม (Telegram + Webhook)
 */

function sp_notifications_policy_core_key(array $r) {
    $r2 = $r;
    $r2['patient_type'] = '';
    $r2['module_key'] = '';
    return sp_notifications_policy_key($r2);
}

function sp_notifications_policy_key_without_ptype(array $r) {
    $r2 = $r;
    $r2['patient_type'] = '';
    return sp_notifications_policy_key($r2);
}

function sp_notifications_parse_module_keys($con, array $data) {
    $allowed = [];
    $q = mysqli_query($con, "SELECT module_key FROM smart_pharma_modules");
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $mk = trim((string)($row['module_key'] ?? ''));
            if ($mk !== '') {
                $allowed[$mk] = true;
            }
        }
    }
    $keys = [];
    if (!empty($data['module_keys']) && is_array($data['module_keys'])) {
        foreach ($data['module_keys'] as $mk) {
            $mk = trim((string)$mk);
            if ($mk !== '' && isset($allowed[$mk])) {
                $keys[$mk] = true;
            }
        }
    }
    if (empty($keys)) {
        $single = trim((string)($data['module_key'] ?? ''));
        if ($single !== '' && isset($allowed[$single])) {
            return [$single];
        }
        return [];
    }
    $out = array_keys($keys);
    sort($out);
    return $out;
}

function sp_notifications_dest_signature(array $g) {
    $tg = array_column($g['telegram'] ?? [], 'id');
    $wh = array_column($g['webhook'] ?? [], 'id');
    sort($tg);
    sort($wh);
    return implode(',', $tg) . '|' . implode(',', $wh);
}

function sp_notifications_parse_patient_types(array $data) {
    $types = [];
    if (!empty($data['patient_types']) && is_array($data['patient_types'])) {
        foreach ($data['patient_types'] as $t) {
            $t = strtoupper(trim((string)$t));
            if (in_array($t, ['OPD', 'IPD'], true)) {
                $types[$t] = true;
            }
        }
    }
    if (empty($types)) {
        $single = strtoupper(trim((string)($data['patient_type'] ?? '')));
        if ($single === 'OPD+IPD' || $single === 'BOTH') {
            return ['OPD', 'IPD'];
        }
        if (in_array($single, ['OPD', 'IPD'], true)) {
            return [$single];
        }
        return ['OPD', 'IPD'];
    }
    $out = array_keys($types);
    sort($out);
    return $out;
}

function sp_notifications_policy_keys_from_request(array $data) {
    $keys = [];
    if (!empty($data['old_policy_keys']) && is_array($data['old_policy_keys'])) {
        foreach ($data['old_policy_keys'] as $k) {
            $k = trim((string)$k);
            if ($k !== '') {
                $keys[] = $k;
            }
        }
    }
    $compound = trim((string)($data['old_policy_key'] ?? ''));
    if ($compound !== '') {
        foreach (explode('||', $compound) as $k) {
            $k = trim($k);
            if ($k !== '' && !in_array($k, $keys, true)) {
                $keys[] = $k;
            }
        }
    }
    return $keys;
}

function sp_notifications_delete_rules_by_policy_keys($con, array $policy_keys) {
    foreach ($policy_keys as $key) {
        $key = trim((string)$key);
        if ($key !== '') {
            sp_notifications_delete_rules_by_policy_key($con, $key);
        }
    }
}

function sp_notifications_policy_key(array $r) {
    return implode('|', [
        $r['patient_type'] ?? '',
        $r['module_key'] ?? '',
        (int)($r['notify_hard'] ?? 0),
        (int)($r['notify_soft'] ?? 0),
        $r['hard_repeat_mode'] ?? 'off',
        (int)($r['hard_repeat_minutes'] ?? 0),
        $r['soft_repeat_mode'] ?? 'off',
        (int)($r['soft_repeat_minutes'] ?? 0),
        $r['hard_stop_mode'] ?? ($r['stop_mode'] ?? 'off'),
        (int)($r['hard_stop_minutes'] ?? ($r['stop_minutes'] ?? 0)),
        $r['soft_stop_mode'] ?? ($r['stop_mode'] ?? 'off'),
        (int)($r['soft_stop_minutes'] ?? ($r['stop_minutes'] ?? 0)),
        $r['link_mode'] ?? 'auto',
        trim((string)($r['extra_message'] ?? '')),
        trim((string)($r['extra_link'] ?? '')),
        trim((string)($r['extra_link_label'] ?? '')),
        (int)($r['active'] ?? 0),
    ]);
}

function sp_notifications_get_config($con) {
    require_once __DIR__ . '/smart_pharma_telegram_helper.php';
    require_once __DIR__ . '/smart_pharma_webhook_helper.php';
    $tg = sp_telegram_get_config($con);
    $wh = sp_webhook_get_config($con);
    return [
        'telegram_enabled' => !empty($tg['enabled']),
        'webhook_enabled' => !empty($wh['enabled']),
        'base_url' => $tg['base_url'] ?? '',
        'cron_secret' => $tg['cron_secret'] ?? '',
    ];
}

function sp_notifications_save_config($con, array $data) {
    require_once __DIR__ . '/smart_pharma_telegram_helper.php';
    require_once __DIR__ . '/smart_pharma_webhook_helper.php';
    sp_telegram_save_config(
        $con,
        !empty($data['telegram_enabled']),
        array_key_exists('cron_secret', $data) ? ($data['cron_secret'] ?? '') : null,
        array_key_exists('base_url', $data) ? ($data['base_url'] ?? '') : null
    );
    sp_webhook_save_config(
        $con,
        !empty($data['webhook_enabled']),
        array_key_exists('base_url', $data) ? ($data['base_url'] ?? '') : null
    );
    return true;
}

function sp_notifications_rule_row_base(array $r) {
    return [
        'patient_type' => $r['patient_type'] ?? 'OPD',
        'module_key' => $r['module_key'] ?? '',
        'module_label' => $r['module_label'] ?? ($r['module_key'] ?? ''),
        'notify_hard' => (int)($r['notify_hard'] ?? 0),
        'notify_soft' => (int)($r['notify_soft'] ?? 0),
        'hard_repeat_mode' => $r['hard_repeat_mode'] ?? 'off',
        'hard_repeat_minutes' => (int)($r['hard_repeat_minutes'] ?? 0),
        'soft_repeat_mode' => $r['soft_repeat_mode'] ?? 'off',
        'soft_repeat_minutes' => (int)($r['soft_repeat_minutes'] ?? 0),
        'hard_stop_mode' => $r['hard_stop_mode'] ?? ($r['stop_mode'] ?? 'off'),
        'hard_stop_minutes' => (int)($r['hard_stop_minutes'] ?? ($r['stop_minutes'] ?? 0)),
        'soft_stop_mode' => $r['soft_stop_mode'] ?? ($r['stop_mode'] ?? 'off'),
        'soft_stop_minutes' => (int)($r['soft_stop_minutes'] ?? ($r['stop_minutes'] ?? 0)),
        'stop_mode' => $r['stop_mode'] ?? 'off',
        'stop_minutes' => (int)($r['stop_minutes'] ?? 0),
        'link_mode' => $r['link_mode'] ?? 'auto',
        'extra_message' => $r['extra_message'] ?? '',
        'extra_link' => $r['extra_link'] ?? '',
        'extra_link_label' => $r['extra_link_label'] ?? '',
        'active' => (int)($r['active'] ?? 0),
    ];
}

function sp_notifications_list_unified_rules($con) {
    require_once __DIR__ . '/smart_pharma_telegram_helper.php';
    require_once __DIR__ . '/smart_pharma_webhook_helper.php';
    require_once __DIR__ . '/smart_pharma_notify_helper.php';

    $groups = [];
    foreach (sp_telegram_list_rules($con) as $r) {
        $base = sp_notifications_rule_row_base($r);
        $key = sp_notifications_policy_key($base);
        if (!isset($groups[$key])) {
            $groups[$key] = $base + [
                'policy_key' => $key,
                'telegram' => [],
                'webhook' => [],
            ];
        }
        $groups[$key]['telegram'][] = [
            'rule_id' => (int)$r['id'],
            'id' => (int)$r['chat_id'],
            'label' => $r['chat_label'] ?? '',
        ];
    }
    foreach (sp_webhook_list_rules($con) as $r) {
        $base = sp_notifications_rule_row_base($r);
        $key = sp_notifications_policy_key($base);
        if (!isset($groups[$key])) {
            $groups[$key] = $base + [
                'policy_key' => $key,
                'telegram' => [],
                'webhook' => [],
            ];
        }
        $groups[$key]['webhook'][] = [
            'rule_id' => (int)$r['id'],
            'id' => (int)$r['endpoint_id'],
            'label' => $r['endpoint_label'] ?? '',
        ];
    }

    $clusters = [];
    foreach ($groups as $key => $g) {
        $g['policy_key'] = $key;
        $ck = sp_notifications_policy_core_key($g) . '##' . sp_notifications_dest_signature($g);
        if (!isset($clusters[$ck])) {
            $clusters[$ck] = [];
        }
        $clusters[$ck][] = $g;
    }

    $rows = [];
    foreach ($clusters as $items) {
        $merged = $items[0];
        $types = [];
        $modules = [];
        $labels = [];
        $keys = [];
        foreach ($items as $it) {
            $pt = trim((string)($it['patient_type'] ?? ''));
            if ($pt !== '') {
                $types[] = $pt;
            }
            $mk = trim((string)($it['module_key'] ?? ''));
            if ($mk !== '') {
                $modules[] = $mk;
                $labels[$mk] = $it['module_label'] ?? $mk;
            }
            $keys[] = $it['policy_key'] ?? sp_notifications_policy_key($it);
        }
        $types = array_values(array_unique($types));
        $modules = array_values(array_unique($modules));
        $keys = array_values(array_unique($keys));
        sort($types);
        sort($modules);

        if (count($types) === 2 && in_array('OPD', $types, true) && in_array('IPD', $types, true)) {
            $merged['patient_type'] = 'OPD+IPD';
            $merged['patient_types'] = ['OPD', 'IPD'];
        } elseif (count($types) === 1) {
            $merged['patient_type'] = $types[0];
            $merged['patient_types'] = $types;
        } else {
            $merged['patient_type'] = implode('+', $types);
            $merged['patient_types'] = $types;
        }

        $merged['module_keys'] = $modules;
        $merged['module_key'] = count($modules) === 1 ? $modules[0] : '';
        $merged['module_label'] = implode(', ', array_map(function ($mk) use ($labels) {
            return $labels[$mk] ?? $mk;
        }, $modules));

        $merged['policy_keys'] = $keys;
        $merged['policy_key'] = implode('||', $keys);
        $rows[] = $merged;
    }
    usort($rows, function ($a, $b) {
        $c = strcmp($a['patient_type'] ?? '', $b['patient_type'] ?? '');
        if ($c !== 0) {
            return $c;
        }
        return strcmp($a['module_label'] ?? '', $b['module_label'] ?? '');
    });
    return $rows;
}

function sp_notifications_delete_rules_by_policy_key($con, $policy_key) {
    require_once __DIR__ . '/smart_pharma_telegram_helper.php';
    require_once __DIR__ . '/smart_pharma_webhook_helper.php';
    $policy_key = (string)$policy_key;
    foreach (sp_telegram_list_rules($con) as $r) {
        if (sp_notifications_policy_key(sp_notifications_rule_row_base($r)) === $policy_key) {
            sp_telegram_delete_rule($con, (int)$r['id']);
        }
    }
    foreach (sp_webhook_list_rules($con) as $r) {
        if (sp_notifications_policy_key(sp_notifications_rule_row_base($r)) === $policy_key) {
            sp_webhook_delete_rule($con, (int)$r['id']);
        }
    }
}

function sp_notifications_build_policy_base(array $data) {
    return [
        'notify_hard' => !empty($data['notify_hard']),
        'notify_soft' => !empty($data['notify_soft']),
        'hard_repeat_mode' => $data['hard_repeat_mode'] ?? 'off',
        'hard_repeat_minutes' => (int)($data['hard_repeat_minutes'] ?? 0),
        'soft_repeat_mode' => $data['soft_repeat_mode'] ?? 'off',
        'soft_repeat_minutes' => (int)($data['soft_repeat_minutes'] ?? 0),
        'hard_stop_mode' => $data['hard_stop_mode'] ?? ($data['stop_mode'] ?? 'off'),
        'hard_stop_minutes' => (int)($data['hard_stop_minutes'] ?? ($data['stop_minutes'] ?? 0)),
        'soft_stop_mode' => $data['soft_stop_mode'] ?? ($data['stop_mode'] ?? 'off'),
        'soft_stop_minutes' => (int)($data['soft_stop_minutes'] ?? ($data['stop_minutes'] ?? 0)),
        'link_mode' => $data['link_mode'] ?? 'auto',
        'extra_message' => trim((string)($data['extra_message'] ?? '')),
        'extra_link' => trim((string)($data['extra_link'] ?? '')),
        'extra_link_label' => trim((string)($data['extra_link_label'] ?? '')),
        'active' => !empty($data['active']),
    ];
}

/**
 * กฎที่ชนกัน = ปลายทาง + OPD/IPD + โมดูลเดียวกัน แต่การตั้งค่า (repeat/stop ฯลฯ) ต่างกัน
 * ระบบเก็บได้แค่ 1 กฎต่อชุดนั้น — บันทึกทับของเดิม
 */
function sp_notifications_detect_save_conflicts($con, array $data) {
    require_once __DIR__ . '/smart_pharma_telegram_helper.php';
    require_once __DIR__ . '/smart_pharma_webhook_helper.php';

    $patient_types = sp_notifications_parse_patient_types($data);
    $module_keys = sp_notifications_parse_module_keys($con, $data);
    if (empty($patient_types) || empty($module_keys)) {
        return [];
    }

    $telegram_ids = array_values(array_filter(array_map('intval', $data['telegram_chat_ids'] ?? []), function ($id) {
        return $id > 0;
    }));
    $webhook_ids = array_values(array_filter(array_map('intval', $data['webhook_endpoint_ids'] ?? []), function ($id) {
        return $id > 0;
    }));

    $skip_keys = sp_notifications_policy_keys_from_request($data);
    $policy_base = sp_notifications_build_policy_base($data);
    $conflicts = [];
    $seen = [];

    foreach (sp_telegram_list_rules($con) as $r) {
        $row = sp_notifications_rule_row_base($r);
        $existing_key = sp_notifications_policy_key($row);
        if (in_array($existing_key, $skip_keys, true)) {
            continue;
        }
        if (!in_array($row['patient_type'], $patient_types, true)) {
            continue;
        }
        if (!in_array($row['module_key'], $module_keys, true)) {
            continue;
        }
        if (!in_array((int)$r['chat_id'], $telegram_ids, true)) {
            continue;
        }
        $new_key = sp_notifications_policy_key($row + $policy_base);
        if ($existing_key === $new_key) {
            continue;
        }
        $sig = 'tg|' . $r['chat_id'] . '|' . $row['patient_type'] . '|' . $row['module_key'];
        if (isset($seen[$sig])) {
            continue;
        }
        $seen[$sig] = true;
        $conflicts[] = [
            'channel' => 'Telegram',
            'destination' => $r['chat_label'] ?? '',
            'patient_type' => $row['patient_type'],
            'module_label' => $row['module_label'] ?? $row['module_key'],
        ];
    }

    foreach (sp_webhook_list_rules($con) as $r) {
        $row = sp_notifications_rule_row_base($r);
        $existing_key = sp_notifications_policy_key($row);
        if (in_array($existing_key, $skip_keys, true)) {
            continue;
        }
        if (!in_array($row['patient_type'], $patient_types, true)) {
            continue;
        }
        if (!in_array($row['module_key'], $module_keys, true)) {
            continue;
        }
        if (!in_array((int)$r['endpoint_id'], $webhook_ids, true)) {
            continue;
        }
        $new_key = sp_notifications_policy_key($row + $policy_base);
        if ($existing_key === $new_key) {
            continue;
        }
        $sig = 'wh|' . $r['endpoint_id'] . '|' . $row['patient_type'] . '|' . $row['module_key'];
        if (isset($seen[$sig])) {
            continue;
        }
        $seen[$sig] = true;
        $conflicts[] = [
            'channel' => 'Webhook',
            'destination' => $r['endpoint_label'] ?? '',
            'patient_type' => $row['patient_type'],
            'module_label' => $row['module_label'] ?? $row['module_key'],
        ];
    }

    return $conflicts;
}

function sp_notifications_save_unified_rule($con, array $data) {
    require_once __DIR__ . '/smart_pharma_telegram_helper.php';
    require_once __DIR__ . '/smart_pharma_webhook_helper.php';

    $telegram_ids = array_map('intval', $data['telegram_chat_ids'] ?? []);
    $webhook_ids = array_map('intval', $data['webhook_endpoint_ids'] ?? []);
    $telegram_ids = array_values(array_filter($telegram_ids, function ($id) {
        return $id > 0;
    }));
    $webhook_ids = array_values(array_filter($webhook_ids, function ($id) {
        return $id > 0;
    }));

    if (empty($telegram_ids) && empty($webhook_ids)) {
        return false;
    }
    if (empty($data['notify_hard']) && empty($data['notify_soft'])) {
        return false;
    }

    if (empty($data['force_overwrite'])) {
        $conflicts = sp_notifications_detect_save_conflicts($con, $data);
        if (!empty($conflicts)) {
            return ['conflicts' => $conflicts];
        }
    }

    $patient_types = sp_notifications_parse_patient_types($data);
    if (empty($patient_types)) {
        return false;
    }

    $module_keys = sp_notifications_parse_module_keys($con, $data);
    if (empty($module_keys)) {
        return false;
    }

    $old_keys = sp_notifications_policy_keys_from_request($data);
    if (!empty($old_keys)) {
        sp_notifications_delete_rules_by_policy_keys($con, $old_keys);
    }

    $rule_base = sp_notifications_build_policy_base($data) + [
        'stop_mode' => $data['hard_stop_mode'] ?? ($data['stop_mode'] ?? 'off'),
        'stop_minutes' => (int)($data['hard_stop_minutes'] ?? ($data['stop_minutes'] ?? 0)),
    ];

    $ok = true;
    foreach ($patient_types as $patient_type) {
        foreach ($module_keys as $module_key) {
            $payload_base = $rule_base + [
                'patient_type' => $patient_type,
                'module_key' => $module_key,
            ];
            foreach ($telegram_ids as $chat_id) {
                $payload = $payload_base + ['chat_id' => $chat_id];
                if (!sp_telegram_save_rule($con, $payload)) {
                    $ok = false;
                }
            }
            foreach ($webhook_ids as $endpoint_id) {
                $payload = $payload_base + ['endpoint_id' => $endpoint_id];
                if (!sp_webhook_save_rule($con, $payload)) {
                    $ok = false;
                }
            }
        }
    }
    return $ok;
}
