<?php
/**
 * Monitor Refer Back — คัดกรองผู้ป่วย VN/AN เทียบบัญชีเครือข่าย
 */

require_once __DIR__ . '/drug_refer_helper.php';
require_once __DIR__ . '/drug_refer_appointment_helper.php';
require_once __DIR__ . '/smart_pharma_helper.php';

function mrb_ensure_tables($con) {
    static $done = false;
    if ($done) {
        return true;
    }
    $sql = @file_get_contents(__DIR__ . '/sql/monitor_refer_back.sql');
    if (!$sql) {
        return false;
    }
    if (!mysqli_multi_query($con, $sql)) {
        return false;
    }
    while (mysqli_more_results($con)) {
        mysqli_next_result($con);
    }
    $col_q = mysqli_query($con, "SHOW COLUMNS FROM refer_back_monitor_settings LIKE 'exclude_outside_province'");
    if ($col_q && mysqli_num_rows($col_q) === 0) {
        mysqli_query($con, "ALTER TABLE refer_back_monitor_settings
            ADD COLUMN exclude_outside_province TINYINT(1) NOT NULL DEFAULT 1 AFTER pttype_codes");
    }
    $col_q = mysqli_query($con, "SHOW COLUMNS FROM refer_back_monitor_settings LIKE 'exclude_drugs'");
    if ($col_q && mysqli_num_rows($col_q) === 0) {
        mysqli_query($con, "ALTER TABLE refer_back_monitor_settings
            ADD COLUMN exclude_drugs TEXT NULL AFTER exclude_outside_province");
    }
    mysqli_query($con, "CREATE TABLE IF NOT EXISTS refer_back_exclude_drugs (
        icode VARCHAR(64) NOT NULL PRIMARY KEY,
        drug_name VARCHAR(255) NOT NULL DEFAULT '',
        exclude_opd TINYINT(1) NOT NULL DEFAULT 1,
        exclude_ipd TINYINT(1) NOT NULL DEFAULT 1,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    mysqli_query($con, "CREATE TABLE IF NOT EXISTS refer_back_substitute_pairs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        icode_a VARCHAR(64) NOT NULL,
        icode_b VARCHAR(64) NOT NULL,
        name_a VARCHAR(255) NOT NULL DEFAULT '',
        name_b VARCHAR(255) NOT NULL DEFAULT '',
        tmtdrug_a VARCHAR(255) NOT NULL DEFAULT '',
        tmtdrug_b VARCHAR(255) NOT NULL DEFAULT '',
        tpu_a VARCHAR(64) NOT NULL DEFAULT '',
        tpu_b VARCHAR(64) NOT NULL DEFAULT '',
        strength_a VARCHAR(64) NOT NULL DEFAULT '',
        strength_b VARCHAR(64) NOT NULL DEFAULT '',
        UNIQUE KEY uk_pair_a_tmt (icode_a, tmtdrug_b(191))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    mrb_migrate_substitute_pairs_schema($con);
    mrb_migrate_legacy_exclude_drugs($con);
    $done = true;
    return true;
}

function mrb_migrate_substitute_pairs_schema($con) {
    static $migrated = false;
    if ($migrated) {
        return;
    }
    $migrated = true;
    $q = mysqli_query($con, "SHOW INDEX FROM refer_back_substitute_pairs WHERE Key_name = 'uk_pair'");
    if ($q && mysqli_num_rows($q) > 0) {
        mysqli_query($con, 'ALTER TABLE refer_back_substitute_pairs DROP INDEX uk_pair');
    }
    $q2 = mysqli_query($con, "SHOW INDEX FROM refer_back_substitute_pairs WHERE Key_name = 'uk_pair_a_tmt'");
    if (!$q2 || mysqli_num_rows($q2) === 0) {
        mysqli_query($con, 'ALTER TABLE refer_back_substitute_pairs ADD UNIQUE KEY uk_pair_a_tmt (icode_a, tmtdrug_b(191))');
    }
    mysqli_query(
        $con,
        "UPDATE refer_back_substitute_pairs
         SET tmtdrug_b = TRIM(tmtdrug_b)
         WHERE TRIM(tmtdrug_b) = '' AND TRIM(name_b) <> ''"
    );
    mysqli_query(
        $con,
        "UPDATE refer_back_substitute_pairs
         SET tmtdrug_b = TRIM(name_b)
         WHERE TRIM(tmtdrug_b) = '' AND TRIM(icode_b) <> '' AND TRIM(name_b) = ''"
    );
}

function mrb_normalize_patient_type($type) {
    $type = strtoupper(trim((string)$type));
    return $type === 'IPD' ? 'IPD' : 'OPD';
}

function mrb_case_uid($patient_type, $screen_date, $hn, $vn, $an, $extra_key = '') {
    $patient_type = mrb_normalize_patient_type($patient_type);
    if ($patient_type === 'IPD') {
        $key = trim((string)$an);
        if ($extra_key !== '') {
            $key .= '@' . trim((string)$extra_key);
        }
    } else {
        $key = trim((string)$vn);
    }
    if ($key === '') {
        $key = trim((string)$hn);
    }
    return implode('|', [$patient_type, trim((string)$screen_date), trim((string)$hn), $key]);
}

function mrb_get_settings($con, $patient_type) {
    mrb_ensure_tables($con);
    $patient_type = mysqli_real_escape_string($con, mrb_normalize_patient_type($patient_type));
    $q = mysqli_query($con, "SELECT * FROM refer_back_monitor_settings WHERE patient_type='$patient_type' LIMIT 1");
    $row = $q ? mysqli_fetch_assoc($q) : null;
    if (!$row) {
        return [
            'patient_type' => $patient_type,
            'min_match_percent' => 80,
            'min_similar_score' => 90,
            'pttype_mode' => 'default',
            'pttype_codes' => null,
            'exclude_outside_province' => true,
            'enabled' => 1,
        ];
    }
    $codes = null;
    if (!empty($row['pttype_codes'])) {
        $decoded = json_decode($row['pttype_codes'], true);
        if (is_array($decoded)) {
            $codes = $decoded;
        }
    }
    $row['pttype_codes_array'] = $codes;
    $row['exclude_outside_province'] = !isset($row['exclude_outside_province']) || (int)$row['exclude_outside_province'] === 1;
    return $row;
}

function mrb_migrate_legacy_exclude_drugs($con) {
    static $migrated = false;
    if ($migrated) {
        return;
    }
    $migrated = true;
    $q = mysqli_query($con, "SELECT COUNT(*) AS c FROM refer_back_exclude_drugs");
    $row = $q ? mysqli_fetch_assoc($q) : null;
    if ((int)($row['c'] ?? 0) > 0) {
        return;
    }
    $map = [];
    $sq = mysqli_query($con, "SELECT patient_type, exclude_drugs FROM refer_back_monitor_settings
        WHERE exclude_drugs IS NOT NULL AND TRIM(exclude_drugs) <> '' AND exclude_drugs <> '[]'");
    if ($sq) {
        while ($srow = mysqli_fetch_assoc($sq)) {
            $ptype = strtoupper(trim($srow['patient_type'] ?? ''));
            foreach (mrb_parse_exclude_drugs($srow['exclude_drugs'] ?? null) as $item) {
                $icode = trim($item['icode'] ?? '');
                if ($icode === '') {
                    continue;
                }
                if (!isset($map[$icode])) {
                    $map[$icode] = [
                        'name' => trim($item['name'] ?? $icode),
                        'exclude_opd' => 0,
                        'exclude_ipd' => 0,
                    ];
                }
                if ($ptype === 'OPD') {
                    $map[$icode]['exclude_opd'] = 1;
                } elseif ($ptype === 'IPD') {
                    $map[$icode]['exclude_ipd'] = 1;
                }
            }
        }
    }
    foreach ($map as $icode => $item) {
        $ic_esc = mysqli_real_escape_string($con, $icode);
        $name_esc = mysqli_real_escape_string($con, $item['name'] !== '' ? $item['name'] : $icode);
        $opd = !empty($item['exclude_opd']) ? 1 : 0;
        $ipd = !empty($item['exclude_ipd']) ? 1 : 0;
        if ($opd === 0 && $ipd === 0) {
            continue;
        }
        mysqli_query($con, "INSERT INTO refer_back_exclude_drugs (icode, drug_name, exclude_opd, exclude_ipd)
            VALUES ('$ic_esc', '$name_esc', $opd, $ipd)");
    }
}

function mrb_get_exclude_drugs($con) {
    mrb_ensure_tables($con);
    $rows = [];
    $q = mysqli_query($con, "SELECT icode, drug_name, exclude_opd, exclude_ipd
        FROM refer_back_exclude_drugs
        ORDER BY drug_name ASC, icode ASC");
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $icode = trim($row['icode'] ?? '');
            if ($icode === '') {
                continue;
            }
            $rows[] = [
                'icode' => $icode,
                'name' => trim($row['drug_name'] ?? '') ?: $icode,
                'exclude_opd' => (int)($row['exclude_opd'] ?? 0) === 1,
                'exclude_ipd' => (int)($row['exclude_ipd'] ?? 0) === 1,
            ];
        }
    }
    return $rows;
}

function mrb_save_exclude_drugs($con, array $items) {
    mrb_ensure_tables($con);
    mysqli_query($con, 'DELETE FROM refer_back_exclude_drugs');
    $seen = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $icode = trim($item['icode'] ?? '');
        if ($icode === '' || isset($seen[$icode])) {
            continue;
        }
        $exclude_opd = !empty($item['exclude_opd']) ? 1 : 0;
        $exclude_ipd = !empty($item['exclude_ipd']) ? 1 : 0;
        if ($exclude_opd === 0 && $exclude_ipd === 0) {
            continue;
        }
        $seen[$icode] = true;
        $name = trim($item['name'] ?? $icode);
        $ic_esc = mysqli_real_escape_string($con, $icode);
        $name_esc = mysqli_real_escape_string($con, $name !== '' ? $name : $icode);
        mysqli_query($con, "INSERT INTO refer_back_exclude_drugs (icode, drug_name, exclude_opd, exclude_ipd)
            VALUES ('$ic_esc', '$name_esc', $exclude_opd, $exclude_ipd)");
    }
    return true;
}

function mrb_parse_exclude_drugs($raw) {
    if (is_array($raw)) {
        $decoded = $raw;
    } elseif (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
    } else {
        return [];
    }
    if (!is_array($decoded)) {
        return [];
    }
    $out = [];
    $seen = [];
    foreach ($decoded as $item) {
        if (is_string($item)) {
            $icode = trim($item);
            $name = $icode;
        } elseif (is_array($item)) {
            $icode = trim($item['icode'] ?? '');
            $name = trim($item['name'] ?? $icode);
        } else {
            continue;
        }
        if ($icode === '' || isset($seen[$icode])) {
            continue;
        }
        $seen[$icode] = true;
        $out[] = ['icode' => $icode, 'name' => $name !== '' ? $name : $icode];
    }
    return $out;
}

function mrb_exclude_drug_icodes($con, $patient_type) {
    mrb_ensure_tables($con);
    $patient_type = mrb_normalize_patient_type($patient_type);
    $col = $patient_type === 'IPD' ? 'exclude_ipd' : 'exclude_opd';
    $codes = [];
    $q = mysqli_query($con, "SELECT icode FROM refer_back_exclude_drugs WHERE $col = 1 ORDER BY icode ASC");
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $ic = trim($row['icode'] ?? '');
            if ($ic !== '') {
                $codes[] = $ic;
            }
        }
    }
    return $codes;
}

function mrb_filter_drugs_excluded(array $drugs, array $exclude_icodes) {
    if (empty($exclude_icodes) || empty($drugs)) {
        return $drugs;
    }
    $set = array_flip($exclude_icodes);
    return array_values(array_filter($drugs, function ($d) use ($set) {
        $ic = trim($d['icode'] ?? '');
        return $ic === '' || !isset($set[$ic]);
    }));
}

function mrb_fetch_hosxp_drug_meta($con_hosxp, $icode) {
    if (!$con_hosxp) {
        return null;
    }
    $icode_esc = mysqli_real_escape_string($con_hosxp, trim((string)$icode));
    if ($icode_esc === '') {
        return null;
    }
    $tmt_expr = hosxp_sql_tmt_drug_name('d', 'tm');
    $sql = "SELECT d.icode,
            CAST(CONVERT(d.name USING utf8) AS CHAR) AS name_local,
            $tmt_expr AS name_tmt,
            IFNULL(d.tpu_code_list, '') AS tpu_code_list,
            IFNULL(d.strength, '') AS strength
        FROM drugitems d
        " . hosxp_sql_tmt_join('d', 'tm') . "
        WHERE d.icode = '$icode_esc'
        LIMIT 1";
    $q = mysqli_query($con_hosxp, $sql);
    if (!$q || !($row = mysqli_fetch_assoc($q))) {
        return null;
    }
    $name_local = trim($row['name_local'] ?? '');
    $name_tmt = trim($row['name_tmt'] ?? '');
    if ($name_tmt === '') {
        $name_tmt = $name_local;
    }
    return [
        'icode' => trim($row['icode'] ?? ''),
        'name' => $name_local !== '' ? $name_local : $name_tmt,
        'tmtdrug' => $name_tmt,
        'tpu' => trim($row['tpu_code_list'] ?? ''),
        'strength' => trim($row['strength'] ?? ''),
    ];
}

function mrb_normalize_substitute_pair_meta($con_hosxp, $icode, $name, $tmtdrug = '', $tpu = '', $strength = '') {
    $icode = trim((string)$icode);
    $meta = null;
    if ($con_hosxp && $icode !== '') {
        $meta = mrb_fetch_hosxp_drug_meta($con_hosxp, $icode);
    }
    if ($meta) {
        return $meta;
    }
    $tmtdrug = trim((string)$tmtdrug);
    $name = trim((string)$name);
    if ($tmtdrug === '') {
        $tmtdrug = $name;
    }
    return [
        'icode' => $icode,
        'name' => $name !== '' ? $name : $icode,
        'tmtdrug' => $tmtdrug !== '' ? $tmtdrug : $icode,
        'tpu' => trim((string)$tpu),
        'strength' => trim((string)$strength),
    ];
}

function mrb_get_substitute_pairs($con) {
    mrb_ensure_tables($con);
    $rows = [];
    $all_hospitals = nd_list_hospitals($con, 0);
    $q = mysqli_query($con, "SELECT * FROM refer_back_substitute_pairs ORDER BY name_a ASC, tmtdrug_b ASC, icode_a ASC");
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $tmtdrug_b = trim($row['tmtdrug_b'] ?? '');
            if ($tmtdrug_b === '') {
                $tmtdrug_b = trim($row['name_b'] ?? '');
            }
            $hospitals = $tmtdrug_b !== '' ? nd_hospitals_for_tmtdrug($con, $tmtdrug_b) : [];
            $rows[] = [
                'id' => (int)($row['id'] ?? 0),
                'icode_a' => trim($row['icode_a'] ?? ''),
                'tmtdrug_b' => $tmtdrug_b,
                'name_a' => trim($row['name_a'] ?? ''),
                'tpu_b' => trim($row['tpu_b'] ?? ''),
                'hospital_label' => nd_format_hospital_coverage_label($all_hospitals, $hospitals),
                'hospitals' => $hospitals,
            ];
        }
    }
    return $rows;
}

function mrb_save_substitute_pairs($con, $con_hosxp, array $pairs) {
    mrb_ensure_tables($con);
    mysqli_query($con, 'DELETE FROM refer_back_substitute_pairs');
    $seen = [];
    foreach ($pairs as $pair) {
        if (!is_array($pair)) {
            continue;
        }
        $icode_a = trim((string)($pair['icode_a'] ?? ''));
        $tmtdrug_b = trim((string)($pair['tmtdrug_b'] ?? ''));
        if ($icode_a === '' || $tmtdrug_b === '') {
            continue;
        }
        $meta_a = mrb_normalize_substitute_pair_meta(
            $con_hosxp,
            $icode_a,
            $pair['name_a'] ?? '',
            $pair['tmtdrug_a'] ?? ''
        );
        $meta_b = nd_fetch_network_tmtdrug_meta($con, $tmtdrug_b);
        if (!$meta_b) {
            continue;
        }
        $key = $meta_a['icode'] . '|' . $meta_b['tmtdrug'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $a_ic = mysqli_real_escape_string($con, $meta_a['icode']);
        $a_name = mysqli_real_escape_string($con, $meta_a['name']);
        $a_tmt = mysqli_real_escape_string($con, $meta_a['tmtdrug']);
        $a_tpu = mysqli_real_escape_string($con, $meta_a['tpu']);
        $a_str = mysqli_real_escape_string($con, $meta_a['strength']);
        $b_tmt = mysqli_real_escape_string($con, $meta_b['tmtdrug']);
        $b_tpu = mysqli_real_escape_string($con, $meta_b['tpu_code_list'] ?? '');
        mysqli_query($con, "INSERT INTO refer_back_substitute_pairs
            (icode_a, icode_b, name_a, name_b, tmtdrug_a, tmtdrug_b, tpu_a, tpu_b, strength_a, strength_b)
            VALUES ('$a_ic', '', '$a_name', '$b_tmt', '$a_tmt', '$b_tmt', '$a_tpu', '$b_tpu', '$a_str', '')");
    }
    return true;
}

function mrb_build_substitute_lookup($con) {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    mrb_ensure_tables($con);
    $by_icode = [];
    $q = mysqli_query($con, 'SELECT * FROM refer_back_substitute_pairs');
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $a = [
                'icode' => trim($row['icode_a'] ?? ''),
                'name' => trim($row['name_a'] ?? ''),
                'tmtdrug' => trim($row['tmtdrug_a'] ?? ''),
                'tpu' => trim($row['tpu_a'] ?? ''),
                'strength' => trim($row['strength_a'] ?? ''),
            ];
            $b = [
                'icode' => '',
                'name' => trim($row['name_b'] ?? ''),
                'tmtdrug' => trim($row['tmtdrug_b'] ?? ''),
                'tpu' => trim($row['tpu_b'] ?? ''),
                'strength' => trim($row['strength_b'] ?? ''),
            ];
            if ($b['tmtdrug'] === '') {
                $b['tmtdrug'] = trim($row['name_b'] ?? '');
            }
            if ($a['icode'] === '' || $b['tmtdrug'] === '') {
                continue;
            }
            $by_icode[$a['icode']][] = $b;
        }
    }
    $cache = ['by_icode' => $by_icode];
    return $cache;
}

function mrb_substitute_proxy_drug(array $meta) {
    $tmtdrug = trim($meta['tmtdrug'] ?? '');
    $name = trim($meta['name'] ?? $tmtdrug);
    return [
        'icode' => trim($meta['icode'] ?? ''),
        'tmtdrug' => $tmtdrug,
        'drug_name_tmt' => $tmtdrug,
        'drug_name' => $name,
        'tpu_code_list' => trim($meta['tpu'] ?? ''),
        'strength' => trim($meta['strength'] ?? ''),
    ];
}

function mrb_match_drug_with_substitutes(array $patient_drug, array $hos_index, $min_similar_score, array $substitute_lookup) {
    $match = ndr_match_drug_for_hospital($patient_drug, $hos_index, $min_similar_score);
    if (($match['status'] ?? '') === 'exact') {
        return $match;
    }
    $icode = trim($patient_drug['icode'] ?? '');
    $candidates = $icode !== '' ? ($substitute_lookup['by_icode'][$icode] ?? []) : [];
    foreach ($candidates as $meta) {
        $proxy = mrb_substitute_proxy_drug($meta);
        $alt = ndr_match_drug_for_hospital($proxy, $hos_index, $min_similar_score);
        if (($alt['status'] ?? '') === 'exact') {
            $alt['substitute_for'] = trim($patient_drug['display_primary'] ?? $patient_drug['tmtdrug'] ?? $icode);
            $alt['substitute_via'] = trim($meta['tmtdrug'] ?? $meta['name'] ?? '');
            $alt['substitute_note'] = 'ยาทดแทนได้';
            return $alt;
        }
    }
    return $match;
}

function mrb_summarize_match_with_substitutes(array $drugs, array $hos_index, $min_similar_score, array $substitute_lookup) {
    $total = count($drugs);
    if ($total === 0) {
        return [
            'total' => 0,
            'matched' => 0,
            'exact' => 0,
            'similar' => 0,
            'missing' => 0,
            'match_percent' => 0,
        ];
    }
    $matched = 0;
    $exact = 0;
    $similar = 0;
    $missing = 0;
    foreach ($drugs as $drug) {
        $result = mrb_match_drug_with_substitutes($drug, $hos_index, $min_similar_score, $substitute_lookup);
        if (ndr_is_drug_matched_for_hospital($result, $min_similar_score)) {
            $matched++;
            if (($result['status'] ?? '') === 'exact') {
                $exact++;
            } else {
                $similar++;
            }
        } else {
            $missing++;
        }
    }
    return [
        'total' => $total,
        'matched' => $matched,
        'exact' => $exact,
        'similar' => $similar,
        'missing' => $missing,
        'match_percent' => (int)round($matched / $total * 100),
    ];
}

function mrb_compare_drugs_with_substitutes(array $drugs, array $hos_index, $hospital, $min_similar_score, array $substitute_lookup) {
    $rows = [];
    foreach ($drugs as $drug) {
        $match = mrb_match_drug_with_substitutes($drug, $hos_index, $min_similar_score, $substitute_lookup);
        $rows[] = array_merge($drug, [
            'match' => $match,
            'is_matched' => ndr_is_drug_matched_for_hospital($match, $min_similar_score),
        ]);
    }
    $summary = mrb_summarize_match_with_substitutes($drugs, $hos_index, $min_similar_score, $substitute_lookup);
    return [
        'hospital' => $hospital,
        'rows' => $rows,
        'summary' => $summary,
        'min_similar_score' => (int)$min_similar_score,
    ];
}

function mrb_save_settings($con, $patient_type, array $data, $user_id = 0) {
    mrb_ensure_tables($con);
    $patient_type = mrb_normalize_patient_type($patient_type);
    $ptype_esc = mysqli_real_escape_string($con, $patient_type);
    $min_match = max(0, min(100, (int)($data['min_match_percent'] ?? 80)));
    $min_similar = max(50, min(100, (int)($data['min_similar_score'] ?? 90)));
    $enabled = !empty($data['enabled']) ? 1 : 0;
    $exclude_province = !isset($data['exclude_outside_province']) || !empty($data['exclude_outside_province']) ? 1 : 0;
    $pttype_mode = trim((string)($data['pttype_mode'] ?? 'default'));
    if (!in_array($pttype_mode, ['default', 'inside', 'all', 'custom'], true)) {
        $pttype_mode = 'default';
    }
    $codes = $data['pttype_codes'] ?? null;
    if ($pttype_mode === 'custom' && is_array($codes)) {
        $codes_json = mysqli_real_escape_string($con, json_encode(array_values($codes), JSON_UNESCAPED_UNICODE));
    } else {
        $codes_json = 'NULL';
        $pttype_mode = in_array($pttype_mode, ['default', 'inside', 'all'], true) ? $pttype_mode : 'default';
    }
    $mode_esc = mysqli_real_escape_string($con, $pttype_mode);
    $uid = (int)$user_id;
    $codes_sql = $codes_json === 'NULL' ? 'NULL' : "'$codes_json'";
    mysqli_query($con, "INSERT INTO refer_back_monitor_settings
        (patient_type, min_match_percent, min_similar_score, pttype_mode, pttype_codes, exclude_outside_province, enabled, updated_by)
        VALUES ('$ptype_esc', $min_match, $min_similar, '$mode_esc', $codes_sql, $exclude_province, $enabled, $uid)
        ON DUPLICATE KEY UPDATE
            min_match_percent=$min_match,
            min_similar_score=$min_similar,
            pttype_mode='$mode_esc',
            pttype_codes=$codes_sql,
            exclude_outside_province=$exclude_province,
            enabled=$enabled,
            updated_by=$uid");
    return true;
}

function mrb_is_pttype_outside_province($pttype_name) {
    $name = trim((string)$pttype_name);
    if ($name === '' || strpos($name, 'นอกเขต') === false) {
        return false;
    }
    return strpos($name, 'จังหวัด') !== false;
}

function mrb_pttype_name_map($con_hosxp) {
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    foreach (ndr_fetch_pttype_filter_options($con_hosxp) as $opt) {
        $map[trim($opt['pttype'] ?? '')] = trim($opt['name'] ?? '');
    }
    return $map;
}

function mrb_filter_pttype_codes_exclude_province($con_hosxp, array $codes) {
    if (empty($codes)) {
        return $codes;
    }
    $names = mrb_pttype_name_map($con_hosxp);
    $out = [];
    foreach ($codes as $code) {
        $code = trim((string)$code);
        if ($code === '') {
            continue;
        }
        if (mrb_is_pttype_outside_province($names[$code] ?? '')) {
            continue;
        }
        $out[] = $code;
    }
    return $out;
}

function mrb_resolve_pttype_codes($con_hosxp, array $settings) {
    $mode = $settings['pttype_mode'] ?? 'default';
    if ($mode === 'custom' && !empty($settings['pttype_codes_array']) && is_array($settings['pttype_codes_array'])) {
        $codes = $settings['pttype_codes_array'];
    } elseif ($mode === 'inside') {
        $codes = ndr_default_pttype_inside_filter_codes($con_hosxp);
    } elseif ($mode === 'all') {
        return [];
    } else {
        $codes = ndr_default_pttype_filter_codes($con_hosxp);
    }
    if (!empty($settings['exclude_outside_province'])) {
        $codes = mrb_filter_pttype_codes_exclude_province($con_hosxp, $codes);
    }
    return $codes;
}

function mrb_pttype_filter_label(array $settings, array $codes) {
    $mode = $settings['pttype_mode'] ?? 'default';
    if ($mode === 'all') {
        return 'สิทธิทั้งหมด';
    }
    if ($mode === 'inside') {
        $label = 'ในเขต (ยกเว้นประกันสังคม)';
    } elseif ($mode === 'custom') {
        $label = 'สิทธิที่เลือก ' . count($codes) . ' รายการ';
    } else {
        $label = 'นอกเขต (ยกเว้นประกันสังคม)';
    }
    if (!empty($settings['exclude_outside_province']) && $mode !== 'inside' && $mode !== 'all') {
        $label .= ' — ตัดนอกเขตจังหวัด';
    }
    return $label;
}

function mrb_patient_passes_pttype($pttype, array $allowed_codes) {
    if (empty($allowed_codes)) {
        return true;
    }
    $pttype = trim((string)$pttype);
    return in_array($pttype, $allowed_codes, true);
}

function mrb_time_windows() {
    return ['5m', '15m', '30m', '1h', '3h', '6h', 'full'];
}

function mrb_time_window_minutes($window) {
    $map = ['5m' => 5, '15m' => 15, '30m' => 30, '1h' => 60, '3h' => 180, '6h' => 360];
    return $map[$window] ?? null;
}

function mrb_normalize_time_window($window, $screen_date = null) {
    $window = trim((string)$window);
    if (in_array($window, mrb_time_windows(), true)) {
        if ($window !== 'full' && !sp_is_screen_date_today($screen_date ?? date('Y-m-d'))) {
            return 'full';
        }
        return $window;
    }
    return sp_is_screen_date_today($screen_date ?? date('Y-m-d')) ? '5m' : 'full';
}

function mrb_time_window_label($window) {
    $labels = [
        '5m' => '5 นาที',
        '15m' => '15 นาที',
        '30m' => '30 นาที',
        '1h' => '1 ชั่วโมง',
        '3h' => '3 ชั่วโมง',
        '6h' => '6 ชั่วโมง',
        'full' => 'ทั้งวัน',
    ];
    return $labels[$window] ?? $window;
}

function mrb_rx_time_cutoff_datetime($screen_date, $time_window) {
    $window = mrb_normalize_time_window($time_window, $screen_date);
    if ($window === 'full' || !sp_is_screen_date_today($screen_date)) {
        return null;
    }
    $mins = mrb_time_window_minutes($window);
    if ($mins === null) {
        return null;
    }
    $dt = sp_now_datetime();
    $dt->modify('-' . $mins . ' minutes');
    return $dt->format('Y-m-d H:i:s');
}

function mrb_sql_rx_cutoff_clause($con_hosxp, $screen_date, $time_window, $o = 'o') {
    $cutoff = mrb_rx_time_cutoff_datetime($screen_date, $time_window);
    if ($cutoff === null) {
        return '';
    }
    $cutoff_esc = mysqli_real_escape_string($con_hosxp, $cutoff);
    return " AND TIMESTAMP($o.rxdate, $o.rxtime) >= '$cutoff_esc'";
}

function mrb_sql_hme_order_date($o = 'o') {
    return "COALESCE(NULLIF($o.rxdate, '0000-00-00'), $o.vstdate)";
}

function mrb_sql_hme_order_time($o = 'o') {
    return "IFNULL(NULLIF(TRIM($o.rxtime), ''), IFNULL(NULLIF(TRIM($o.vsttime), ''), '00:00:00'))";
}

function mrb_sql_hme_order_datetime($o = 'o') {
    $date = mrb_sql_hme_order_date($o);
    $time = mrb_sql_hme_order_time($o);
    return "TIMESTAMP($date, $time)";
}

function mrb_sql_hme_order_cutoff_clause($con_hosxp, $screen_date, $time_window, $o = 'o') {
    $cutoff = mrb_rx_time_cutoff_datetime($screen_date, $time_window);
    if ($cutoff === null) {
        return '';
    }
    $cutoff_esc = mysqli_real_escape_string($con_hosxp, $cutoff);
    return ' AND ' . mrb_sql_hme_order_datetime($o) . " >= '$cutoff_esc'";
}

function mrb_visit_timestamp($vstdate, $vsttime) {
    $d = trim((string)$vstdate);
    if ($d === '' || $d === '0000-00-00') {
        return null;
    }
    $t = trim((string)$vsttime);
    if ($t === '') {
        $t = '00:00:00';
    }
    if (strlen($t) === 5) {
        $t .= ':00';
    }
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $d . ' ' . $t, sp_timezone());
    if (!$dt) {
        try {
            $dt = new DateTime($d . ' ' . $t, sp_timezone());
        } catch (Exception $e) {
            return null;
        }
    }
    return $dt->getTimestamp();
}

function mrb_sql_vst_cutoff_clause($con_hosxp, $screen_date, $time_window, $date_col, $time_col) {
    $cutoff = mrb_rx_time_cutoff_datetime($screen_date, $time_window);
    if ($cutoff === null) {
        return '';
    }
    $cutoff_esc = mysqli_real_escape_string($con_hosxp, $cutoff);
    return " AND TIMESTAMP($date_col, IFNULL(NULLIF(TRIM($time_col), ''), '00:00:00')) >= '$cutoff_esc'";
}

function mrb_sql_opd_dept_code_expr($ov = 'ov') {
    return "COALESCE(NULLIF(TRIM($ov.main_dep), ''), NULLIF(TRIM($ov.spclty), ''))";
}

function mrb_sql_opd_dept_joins($ov = 'ov') {
    return "
        LEFT JOIN spclty sp ON sp.spclty = $ov.spclty
        LEFT JOIN kskdepartment kdep ON kdep.depcode = $ov.main_dep
    ";
}

function mrb_sql_opd_dept_name_expr($ov = 'ov') {
    $kdep = "CAST(CONVERT(COALESCE(kdep.department, '') USING utf8) AS CHAR)";
    $spname = "CAST(CONVERT(COALESCE(NULLIF(TRIM(sp.name), ''), NULLIF(TRIM($ov.spclty), '')) USING utf8) AS CHAR)";
    return "CAST(CONVERT(COALESCE(NULLIF($kdep, ''), NULLIF($spname, ''), 'ไม่ระบุแผนก') USING utf8) AS CHAR)";
}

function mrb_fetch_opd_departments($con_hosxp, $screen_date) {
    $screen_date = trim((string)$screen_date);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $screen_date)) {
        return [];
    }
    $date_esc = mysqli_real_escape_string($con_hosxp, $screen_date);
    $code_expr = mrb_sql_opd_dept_code_expr('ov');
    $name_expr = mrb_sql_opd_dept_name_expr('ov');
    $joins = mrb_sql_opd_dept_joins('ov');
    $sql = "SELECT $code_expr AS dept_code, $name_expr AS dept_name, COUNT(*) AS visit_count
        FROM ovst ov
        $joins
        WHERE ov.vstdate = '$date_esc'
          AND TRIM(IFNULL(ov.vn, '')) <> ''
          AND $code_expr IS NOT NULL
          AND TRIM($code_expr) <> ''
        GROUP BY dept_code, dept_name
        ORDER BY dept_name ASC, dept_code ASC";
    $q = mysqli_query($con_hosxp, $sql);
    $rows = [];
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $code = trim($row['dept_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $rows[] = [
                'dept_code' => $code,
                'dept_name' => trim($row['dept_name'] ?? $code),
                'visit_count' => (int)($row['visit_count'] ?? 0),
            ];
        }
    }
    return $rows;
}

function mrb_resolve_opd_dept_label($con_hosxp, $screen_date, $dept_code) {
    return mrb_resolve_opd_dept_filter_label($con_hosxp, $screen_date, mrb_normalize_opd_dept_codes($dept_code));
}

function mrb_normalize_opd_dept_codes($input) {
    if (is_string($input)) {
        $input = trim($input);
        if ($input === '') {
            return [];
        }
        if ($input[0] === '[') {
            $decoded = json_decode($input, true);
            if (is_array($decoded)) {
                $input = $decoded;
            } else {
                $input = [$input];
            }
        } elseif (strpos($input, ',') !== false) {
            $input = explode(',', $input);
        } else {
            $input = [$input];
        }
    }
    if (!is_array($input)) {
        return [];
    }
    $out = [];
    foreach ($input as $code) {
        $code = trim((string)$code);
        if ($code !== '') {
            $out[$code] = true;
        }
    }
    return array_keys($out);
}

function mrb_sql_opd_dept_in_filter($con_hosxp, array $dept_codes, $ov = 'ov') {
    $dept_codes = mrb_normalize_opd_dept_codes($dept_codes);
    if (empty($dept_codes)) {
        return '';
    }
    $in = [];
    foreach ($dept_codes as $code) {
        $in[] = "'" . mysqli_real_escape_string($con_hosxp, $code) . "'";
    }
    return ' AND ' . mrb_sql_opd_dept_code_expr($ov) . ' IN (' . implode(',', $in) . ')';
}

function mrb_resolve_opd_dept_filter_label($con_hosxp, $screen_date, array $dept_codes) {
    $dept_codes = mrb_normalize_opd_dept_codes($dept_codes);
    if (empty($dept_codes)) {
        return 'ทุกแผนก';
    }
    $name_map = [];
    foreach (mrb_fetch_opd_departments($con_hosxp, $screen_date) as $row) {
        $name_map[$row['dept_code']] = $row['dept_name'];
    }
    $labels = [];
    foreach ($dept_codes as $code) {
        $labels[] = $name_map[$code] ?? $code;
    }
    if (count($labels) <= 2) {
        return implode(', ', $labels);
    }
    return count($labels) . ' แผนก';
}

function mrb_fetch_opd_visits($con_hosxp, $screen_date, $time_window = 'full', $dept_codes = []) {
    $date_esc = mysqli_real_escape_string($con_hosxp, $screen_date);
    $cutoff_sql = mrb_sql_vst_cutoff_clause($con_hosxp, $screen_date, $time_window, 'ov.vstdate', 'ov.vsttime');
    $dept_filter = mrb_sql_opd_dept_in_filter($con_hosxp, $dept_codes, 'ov');
    $ptname_sql = "CAST(CONVERT(CONCAT(IFNULL(p.pname,''), IFNULL(p.fname,''), ' ', IFNULL(p.lname,'')) USING utf8) AS CHAR)";
    $dept_name_sql = mrb_sql_opd_dept_name_expr('ov');
    $dept_code_sql = mrb_sql_opd_dept_code_expr('ov');
    $joins = mrb_sql_opd_dept_joins('ov');
    $sql = "SELECT ov.vn, ov.hn, ov.vstdate,
            IFNULL(ov.vsttime, '') AS vsttime,
            '' AS rxdate, '' AS rxtime,
            $ptname_sql AS ptname,
            $dept_name_sql AS dept_name,
            $dept_code_sql AS dept_code,
            'OPD' AS patient_type, '' AS ward_name, '' AS bedno, '' AS an
        FROM ovst ov
        INNER JOIN patient p ON p.hn = ov.hn
        $joins
        WHERE ov.vstdate = '$date_esc'
          AND TRIM(IFNULL(ov.vn, '')) <> ''
          $cutoff_sql
          $dept_filter
        ORDER BY ov.vsttime DESC, ov.vn DESC";
    $q = mysqli_query($con_hosxp, $sql);
    $list = [];
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $list[] = $r;
        }
    }
    return $list;
}

function mrb_filter_ipd_by_visit_time(array $patients, $screen_date, $time_window) {
    $cutoff = mrb_rx_time_cutoff_datetime($screen_date, $time_window);
    if ($cutoff === null) {
        return $patients;
    }
    $cutoff_ts = strtotime($cutoff);
    if ($cutoff_ts === false) {
        return $patients;
    }
    $out = [];
    foreach ($patients as $p) {
        $ts = mrb_visit_timestamp($p['vstdate'] ?? '', $p['vsttime'] ?? '');
        if ($ts !== null && $ts >= $cutoff_ts) {
            $out[] = $p;
        }
    }
    return $out;
}

function mrb_ref_lookback_date($screen_date) {
    return mrb_hme_lookback_date($screen_date, 12);
}

function mrb_hme_lookback_date($screen_date, $months = 12) {
    $months = max(3, min(36, (int)$months));
    $dt = DateTime::createFromFormat('Y-m-d', trim((string)$screen_date), sp_timezone());
    if (!$dt) {
        return date('Y-m-d', strtotime('-' . $months . ' months'));
    }
    $dt->modify('-' . $months . ' months');
    return $dt->format('Y-m-d');
}

/** join เบา — หา visit ที่มียา HME ไม่ต้อง join TMT */
function mrb_sql_hme_opitem_joins($o = 'o') {
    return "
        INNER JOIN drugitems d ON d.icode = $o.icode AND d.istatus = 'Y'
        LEFT JOIN ipt_order_no ion ON ion.order_no = $o.order_no
        LEFT JOIN opitemrece_home oh ON oh.opi_guid = $o.hos_guid AND oh.opitemrece_home_type_id = 2
    ";
}

/** join เบามาก — ค้นหารอบสั่ง HME จาก opitemrece โดยไม่ join drugitems (เร็วเหมือน OPD ใช้ ovst) */
function mrb_sql_hme_discovery_joins($o = 'o') {
    return "
        LEFT JOIN ipt_order_no ion ON ion.order_no = $o.order_no
        LEFT JOIN opitemrece_home oh ON oh.opi_guid = $o.hos_guid AND oh.opitemrece_home_type_id = 2
    ";
}

function mrb_sql_hme_filter($o = 'o', $ion = 'ion', $oh = null) {
    $home_cond = $oh !== null
        ? "$oh.opi_guid IS NOT NULL"
        : "EXISTS (
                SELECT 1 FROM opitemrece_home oh2
                WHERE oh2.opi_guid = $o.hos_guid
                  AND oh2.opitemrece_home_type_id = 2
            )";
    return "
        AND (
            LOWER(TRIM(IFNULL($ion.order_type,''))) = 'hme'
            OR TRIM(IFNULL($o.item_type,'')) = 'H'
            OR $home_cond
        )
    ";
}

function mrb_load_visit_candidates($con_hosxp, array $hn_list, $patient_type, $screen_date, $mode = 'all') {
    if (empty($hn_list)) {
        return [];
    }
    $patient_type = mrb_normalize_patient_type($patient_type);
    $mode = $mode === 'hme' ? 'hme' : 'all';
    $screen_esc = mysqli_real_escape_string($con_hosxp, $screen_date);
    $lookback_esc = mysqli_real_escape_string($con_hosxp, mrb_ref_lookback_date($screen_date));
    $joins = mrb_sql_hme_opitem_joins();
    $hme = $mode === 'hme' ? mrb_sql_hme_filter('o', 'ion', 'oh') : '';
    $hn_in = ndr_hn_array_sql_in($con_hosxp, $hn_list);
    if ($hn_in === '') {
        return [];
    }
    $candidates = [];
    if ($patient_type === 'OPD') {
        $sql = "SELECT o.hn, o.vn, o.vstdate, IFNULL(ov.vsttime, '') AS vsttime
            FROM opitemrece o
            $joins
            LEFT JOIN ovst ov ON ov.vn = o.vn AND ov.vstdate = o.vstdate
            WHERE o.hn IN ($hn_in)
              AND TRIM(IFNULL(o.vn, '')) <> ''
              AND o.qty > 0
              AND o.vstdate >= '$lookback_esc'
              AND o.vstdate <= '$screen_esc'
              $hme
            GROUP BY o.hn, o.vn, o.vstdate, ov.vsttime";
    } else {
        $sql = "SELECT o.hn, o.an, IFNULL(ia.vn, '') AS vn,
                COALESCE(NULLIF(ia.regdate, '0000-00-00'), o.vstdate) AS vstdate,
                IFNULL(ia.regtime, '') AS vsttime
            FROM opitemrece o
            $joins
            INNER JOIN ipt ia ON ia.an = o.an
            WHERE o.hn IN ($hn_in)
              AND TRIM(IFNULL(o.an, '')) <> ''
              AND o.qty > 0
              AND o.vstdate >= '$lookback_esc'
              AND o.vstdate <= '$screen_esc'
              $hme
            GROUP BY o.hn, o.an, ia.vn, ia.regdate, ia.regtime, o.vstdate";
    }
    $q = mysqli_query($con_hosxp, $sql);
    if (!$q) {
        return [];
    }
    while ($row = mysqli_fetch_assoc($q)) {
        $hn = trim($row['hn'] ?? '');
        if ($hn === '') {
            continue;
        }
        if ($patient_type === 'OPD') {
            $visit_id = trim($row['vn'] ?? '');
            $vn = $visit_id;
            $an = '';
            $visit_type = 'opd';
        } else {
            $visit_id = trim($row['an'] ?? '');
            $vn = trim($row['vn'] ?? '');
            $an = $visit_id;
            $visit_type = 'ipd';
        }
        if ($visit_id === '') {
            continue;
        }
        $vd = trim($row['vstdate'] ?? '');
        $vt = trim($row['vsttime'] ?? '');
        $ts = mrb_visit_timestamp($vd, $vt);
        if ($ts === null) {
            continue;
        }
        $dedupe = $visit_id . '@' . $vd;
        if (!isset($candidates[$hn][$dedupe]) || $ts > $candidates[$hn][$dedupe]['ts']) {
            $candidates[$hn][$dedupe] = [
                'vn' => $vn,
                'an' => $an,
                'vstdate' => $vd,
                'vsttime' => $vt,
                'visit_type' => $visit_type,
                'ts' => $ts,
                'is_hme' => $mode === 'hme',
            ];
        }
    }
    return $candidates;
}

function mrb_load_hme_visit_candidates($con_hosxp, array $hn_list, $patient_type, $screen_date) {
    return mrb_load_visit_candidates($con_hosxp, $hn_list, $patient_type, $screen_date, 'hme');
}

function mrb_pick_best_prior_visit(array $rows, $cur_ts, $cur_vn, $cur_an, $patient_type) {
    $best = null;
    foreach ($rows as $c) {
        if ($c['ts'] >= $cur_ts) {
            continue;
        }
        if ($patient_type === 'OPD' && $c['vn'] === $cur_vn) {
            continue;
        }
        if ($patient_type === 'IPD' && $c['an'] === $cur_an) {
            continue;
        }
        if ($best === null || $c['ts'] > $best['ts']) {
            $best = $c;
        }
    }
    return $best;
}

function mrb_format_ref_visit_label(array $best) {
    $prefix = !empty($best['is_hme'])
        ? (!empty($best['an']) ? 'AN HME: ' : 'VN HME: ')
        : (!empty($best['an']) ? 'AN: ' : 'VN: ');
    $id = !empty($best['an']) ? $best['an'] : $best['vn'];
    return $prefix . $id . ' (' . ndr_appt_format_date_th($best['vstdate'] ?? '') . ')';
}

function mrb_ipd_hme_order_key(array $p) {
    $order_no = trim($p['order_no'] ?? $p['hme_order_no'] ?? '');
    if ($order_no !== '') {
        return 'ord:' . $order_no;
    }
    $rxdate = trim($p['rxdate'] ?? $p['vstdate'] ?? '');
    $rxtime = trim($p['rxtime'] ?? $p['vsttime'] ?? '');
    return 'rx:' . $rxdate . 'T' . $rxtime;
}

function mrb_format_ipd_hme_order_label(array $p) {
    $an = trim($p['an'] ?? '');
    $rxdate = trim($p['rxdate'] ?? $p['vstdate'] ?? '');
    $rxtime = trim($p['rxtime'] ?? $p['vsttime'] ?? '');
    $label = 'HME สั่งยา AN: ' . $an;
    if ($rxdate !== '') {
        $label .= ' (' . ndr_appt_format_date_th($rxdate);
        if ($rxtime !== '') {
            $label .= ' ' . substr($rxtime, 0, 5);
        }
        $label .= ')';
    }
    return $label;
}

function mrb_attach_ipd_hme_order_ref(array &$p) {
    $p['ref_vn'] = trim($p['vn'] ?? '');
    $p['ref_an'] = trim($p['an'] ?? '');
    $p['ref_visit_type'] = 'ipd';
    $p['ref_hme_only'] = true;
    $p['ref_vstdate'] = trim($p['rxdate'] ?? $p['vstdate'] ?? '');
    $p['hme_order_no'] = trim($p['order_no'] ?? '');
    $p['hme_order_key'] = mrb_ipd_hme_order_key($p);
    $p['ref_visit_label'] = mrb_format_ipd_hme_order_label($p);
}

function mrb_batch_prior_ref_map($con_hosxp, array $patients, $patient_type, $screen_date = null) {
    if (empty($patients)) {
        return [];
    }
    $patient_type = mrb_normalize_patient_type($patient_type);
    $screen_date = trim((string)($screen_date ?? date('Y-m-d')));
    $hns = [];
    foreach ($patients as $p) {
        $hn = trim($p['hn'] ?? '');
        if ($hn !== '') {
            $hns[$hn] = true;
        }
    }
    if (empty($hns)) {
        return [];
    }
    $hn_list = array_keys($hns);
    $vn_candidates = [];
    $hme_candidates = [];
    $chunk_size = 80;
    for ($i = 0; $i < count($hn_list); $i += $chunk_size) {
        $chunk = array_slice($hn_list, $i, $chunk_size);
        foreach (['all' => &$vn_candidates, 'hme' => &$hme_candidates] as $mode => &$target) {
            $part = mrb_load_visit_candidates($con_hosxp, $chunk, $patient_type, $screen_date, $mode);
            foreach ($part as $hn => $rows) {
                if (!isset($target[$hn])) {
                    $target[$hn] = [];
                }
                foreach ($rows as $dedupe => $row) {
                    if (!isset($target[$hn][$dedupe]) || $row['ts'] > $target[$hn][$dedupe]['ts']) {
                        $target[$hn][$dedupe] = $row;
                    }
                }
            }
        }
        unset($target);
    }

    $map = [];
    foreach ($patients as $p) {
        $hn = trim($p['hn'] ?? '');
        $cur_vn = trim($p['vn'] ?? '');
        $cur_an = trim($p['an'] ?? '');
        $cur_key = $patient_type === 'IPD' ? $cur_an : $cur_vn;
        if ($hn === '' || $cur_key === '') {
            continue;
        }
        $cur_ts = mrb_visit_timestamp($p['vstdate'] ?? '', $p['vsttime'] ?? '');
        if ($cur_ts === null) {
            continue;
        }
        $best_vn = mrb_pick_best_prior_visit($vn_candidates[$hn] ?? [], $cur_ts, $cur_vn, $cur_an, $patient_type);
        $best_hme = mrb_pick_best_prior_visit($hme_candidates[$hn] ?? [], $cur_ts, $cur_vn, $cur_an, $patient_type);
        $best = null;
        if ($best_vn && $best_hme) {
            $best = $best_hme['ts'] >= $best_vn['ts'] ? $best_hme : $best_vn;
        } elseif ($best_hme) {
            $best = $best_hme;
        } elseif ($best_vn) {
            $best = $best_vn;
        }
        if ($best) {
            $map[$cur_key] = [
                'ref_vn' => $best['vn'],
                'ref_an' => $best['an'],
                'ref_visit_type' => $best['visit_type'],
                'ref_vstdate' => $best['vstdate'],
                'ref_vsttime' => $best['vsttime'],
                'ref_hme_only' => !empty($best['is_hme']),
                'ref_visit_label' => mrb_format_ref_visit_label($best),
            ];
        }
    }
    return $map;
}

/** @deprecated use mrb_batch_prior_ref_map */
function mrb_batch_prior_hme_ref_map($con_hosxp, array $patients, $patient_type, $screen_date = null) {
    return mrb_batch_prior_ref_map($con_hosxp, $patients, $patient_type, $screen_date);
}

function mrb_fetch_opd_patients_with_drugs($con_hosxp, $screen_date, $time_window = 'full', $dept_codes = []) {
    return mrb_fetch_opd_visits($con_hosxp, $screen_date, $time_window, $dept_codes);
}

function mrb_batch_ipd_rows_by_an($con_hosxp, array $ans, $screen_date, $with_bed_lookup = false) {
    if (empty($ans)) {
        return [];
    }
    $date_esc = mysqli_real_escape_string($con_hosxp, $screen_date);
    $in = [];
    foreach ($ans as $an) {
        $in[] = "'" . mysqli_real_escape_string($con_hosxp, $an) . "'";
    }
    $ptname_sql = "CAST(CONVERT(CONCAT(IFNULL(p.pname,''), IFNULL(p.fname,''), ' ', IFNULL(p.lname,'')) USING utf8) AS CHAR)";
    if ($with_bed_lookup) {
        $bed_sql = "(SELECT adm.bedno FROM iptadm adm
            WHERE adm.an = ia.an
              AND adm.indate <= '$date_esc'
              AND (adm.outdate IS NULL OR adm.outdate = '0000-00-00' OR adm.outdate >= '$date_esc')
            ORDER BY adm.indate DESC, adm.intime DESC
            LIMIT 1)";
        $bed_select = "IFNULL($bed_sql, IFNULL(ia.cur_bedno, '')) AS bedno";
    } else {
        $bed_select = "IFNULL(ia.cur_bedno, '') AS bedno";
    }
    $sql = "SELECT ia.an, ia.hn, IFNULL(ia.vn, '') AS vn,
            ia.pttype,
            COALESCE(NULLIF(ia.regdate,'0000-00-00'), '$date_esc') AS vstdate,
            IFNULL(ia.regtime, '') AS vsttime,
            $ptname_sql AS ptname,
            'IPD' AS patient_type,
            CAST(CONVERT(IFNULL(w.name, '') USING utf8) AS CHAR) AS ward_name,
            $bed_select,
            CAST(CONVERT(IFNULL(pt.name,'') USING utf8) AS CHAR) AS pttype_name
        FROM ipt ia
        INNER JOIN patient p ON p.hn = ia.hn
        LEFT JOIN ward w ON w.ward = ia.ward
        LEFT JOIN pttype pt ON pt.pttype = ia.pttype
        WHERE ia.an IN (" . implode(',', $in) . ")";
    $q = mysqli_query($con_hosxp, $sql);
    $map = [];
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $map[trim($row['an'] ?? '')] = $row;
        }
    }
    return $map;
}

function mrb_fetch_screen_patients($con_hosxp, $patient_type, $screen_date, $time_window = 'full', $dept_codes = []) {
    $patient_type = mrb_normalize_patient_type($patient_type);
    if ($patient_type === 'IPD') {
        return mrb_fetch_ipd_patients_with_hme($con_hosxp, $screen_date, $time_window);
    }
    return mrb_fetch_opd_patients_with_drugs($con_hosxp, $screen_date, $time_window, $dept_codes);
}

function mrb_fetch_ipd_patients_with_hme($con_hosxp, $screen_date, $time_window = 'full') {
    return mrb_fetch_ipd_hme_orders($con_hosxp, $screen_date, $time_window);
}

function mrb_fetch_ipd_hme_orders($con_hosxp, $screen_date, $time_window = 'full') {
    $screen_date = trim((string)$screen_date);
    $date_esc = mysqli_real_escape_string($con_hosxp, $screen_date);
    $cutoff_sql = mrb_sql_hme_order_cutoff_clause($con_hosxp, $screen_date, $time_window, 'o');
    $hme = mrb_sql_hme_filter('o', 'ion', 'oh');
    $joins = mrb_sql_hme_discovery_joins();
    $order_date = mrb_sql_hme_order_date('o');
    $order_time = mrb_sql_hme_order_time('o');
    $order_dt = mrb_sql_hme_order_datetime('o');
    $sql = "SELECT o.an,
            IFNULL(o.order_no, '') AS order_no,
            $order_date AS rxdate,
            $order_time AS rxtime,
            MIN(o.hn) AS hn,
            MAX($order_dt) AS hme_order_sort
        FROM opitemrece o
        $joins
        WHERE TRIM(IFNULL(o.an, '')) <> ''
          AND o.qty > 0
          AND o.vstdate = '$date_esc'
          $hme
          $cutoff_sql
        GROUP BY o.an, IFNULL(o.order_no, ''), $order_date, $order_time
        ORDER BY hme_order_sort DESC, o.an DESC";
    $q = mysqli_query($con_hosxp, $sql);
    if (!$q) {
        return [];
    }
    $orders = [];
    $ans = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $an = trim($row['an'] ?? '');
        if ($an === '') {
            continue;
        }
        $rxtime = trim($row['rxtime'] ?? '');
        if (strlen($rxtime) === 5) {
            $rxtime .= ':00';
        }
        $orders[] = [
            'an' => $an,
            'hn' => trim($row['hn'] ?? ''),
            'vn' => '',
            'order_no' => trim($row['order_no'] ?? ''),
            'rxdate' => trim($row['rxdate'] ?? $screen_date),
            'rxtime' => $rxtime,
            'vstdate' => trim($row['rxdate'] ?? $screen_date),
            'vsttime' => $rxtime,
        ];
        $ans[$an] = true;
    }
    if (empty($orders)) {
        return [];
    }
    $ipt_map = mrb_batch_ipd_rows_by_an($con_hosxp, array_keys($ans), $screen_date, false);
    $out = [];
    foreach ($orders as $order) {
        $an = $order['an'];
        $ipt = $ipt_map[$an] ?? [];
        $row = array_merge($ipt, $order, [
            'patient_type' => 'IPD',
            'ptname' => trim($ipt['ptname'] ?? ''),
            'ward_name' => trim($ipt['ward_name'] ?? ''),
            'bedno' => trim($ipt['bedno'] ?? ''),
            'vn' => trim($ipt['vn'] ?? $order['vn'] ?? ''),
        ]);
        mrb_attach_ipd_hme_order_ref($row);
        $out[] = $row;
    }
    return $out;
}

function mrb_batch_patient_amp_map($con_hosxp, array $hns) {
    $hn_in = ndr_hn_array_sql_in($con_hosxp, $hns);
    if ($hn_in === '') {
        return [];
    }
    $addr_sql = ndr_sql_thaiaddress_names('p');
    $sql = "SELECT p.hn,
            CAST(CONVERT(CONCAT(IFNULL(p.pname,''), IFNULL(p.fname,''), ' ', IFNULL(p.lname,'')) USING utf8) AS CHAR) AS full_name,
            CAST(CONVERT(IFNULL(p.addrpart, '') USING utf8) AS CHAR) AS addrpart,
            CAST(CONVERT(IFNULL(p.moopart, '') USING utf8) AS CHAR) AS moopart,
            $addr_sql
        FROM patient p
        WHERE p.hn IN ($hn_in)";
    $q = mysqli_query($con_hosxp, $sql);
    $map = [];
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $hn = trim($row['hn'] ?? '');
            if ($hn === '') {
                continue;
            }
            $map[$hn] = [
                'amp_name' => trim($row['amp_name'] ?? ''),
                'tmb_name' => trim($row['tmb_name'] ?? ''),
                'address' => ndr_format_patient_address($row),
                'full_name' => trim($row['full_name'] ?? ''),
            ];
        }
    }
    return $map;
}

function mrb_batch_pttype_map($con_hosxp, array $patients, $patient_type, $screen_date) {
    $patient_type = mrb_normalize_patient_type($patient_type);
    $date_esc = mysqli_real_escape_string($con_hosxp, $screen_date);
    $map = [];
    if ($patient_type === 'IPD') {
        $ans = [];
        foreach ($patients as $p) {
            $an = trim($p['an'] ?? '');
            if ($an !== '') {
                $ans[$an] = true;
            }
        }
        if (empty($ans)) {
            return $map;
        }
        $in = [];
        foreach (array_keys($ans) as $an) {
            $in[] = "'" . mysqli_real_escape_string($con_hosxp, $an) . "'";
        }
        $sql = "SELECT i.an, i.pttype,
                CAST(CONVERT(IFNULL(pt.name,'') USING utf8) AS CHAR) AS pttype_name
            FROM ipt i
            LEFT JOIN pttype pt ON pt.pttype = i.pttype
            WHERE i.an IN (" . implode(',', $in) . ")";
        $q = mysqli_query($con_hosxp, $sql);
        if ($q) {
            while ($row = mysqli_fetch_assoc($q)) {
                $map['an:' . trim($row['an'] ?? '')] = [
                    'pttype' => trim($row['pttype'] ?? ''),
                    'pttype_name' => trim($row['pttype_name'] ?? ''),
                ];
            }
        }
        return $map;
    }
    $vns = [];
    foreach ($patients as $p) {
        $vn = trim($p['vn'] ?? '');
        if ($vn !== '') {
            $vns[$vn] = true;
        }
    }
    if (empty($vns)) {
        return $map;
    }
    $in = [];
    foreach (array_keys($vns) as $vn) {
        $in[] = "'" . mysqli_real_escape_string($con_hosxp, $vn) . "'";
    }
    $sql = "SELECT o.vn, o.pttype,
            CAST(CONVERT(IFNULL(pt.name,'') USING utf8) AS CHAR) AS pttype_name
        FROM ovst o
        LEFT JOIN pttype pt ON pt.pttype = o.pttype
        WHERE o.vn IN (" . implode(',', $in) . ")
          AND o.vstdate = '$date_esc'";
    $q = mysqli_query($con_hosxp, $sql);
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $map['vn:' . trim($row['vn'] ?? '')] = [
                'pttype' => trim($row['pttype'] ?? ''),
                'pttype_name' => trim($row['pttype_name'] ?? ''),
            ];
        }
    }
    return $map;
}

function mrb_ipd_order_drug_map_key($visit_key, $order_no, $rxdate, $rxtime) {
    $visit_key = trim((string)$visit_key);
    $order_no = trim((string)$order_no);
    if ($order_no !== '') {
        return $visit_key . '@' . $order_no . ':1';
    }
    $rxdate = trim((string)$rxdate);
    $rxtime = trim((string)$rxtime);
    if (strlen($rxtime) === 5) {
        $rxtime .= ':00';
    }
    return $visit_key . '@rx:' . $rxdate . 'T' . $rxtime . ':1';
}

function mrb_batch_ipd_order_drugs_map($con_hosxp, array $order_pairs, $screen_date = '') {
    if (empty($order_pairs)) {
        return [];
    }
    $screen_date = trim((string)$screen_date);
    $date_sql = '';
    if ($screen_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $screen_date)) {
        $date_esc = mysqli_real_escape_string($con_hosxp, $screen_date);
        $date_sql = " AND o.vstdate = '$date_esc'";
    }
    $valid_keys = [];
    $ans = [];
    foreach ($order_pairs as $pair) {
        $vk = trim($pair['visit_key'] ?? '');
        if ($vk === '') {
            continue;
        }
        $ans[$vk] = true;
        $valid_keys[mrb_ipd_order_drug_map_key($vk, $pair['order_no'] ?? '', $pair['rxdate'] ?? '', $pair['rxtime'] ?? '')] = true;
    }
    if (empty($ans)) {
        return [];
    }
    $sel = ndr_sql_drug_select();
    $joins = ndr_sql_drug_joins() . "
        LEFT JOIN opitemrece_home oh ON oh.opi_guid = o.hos_guid AND oh.opitemrece_home_type_id = 2
    ";
    $hme = mrb_sql_hme_filter('o', 'ion', 'oh');
    $order_date = mrb_sql_hme_order_date('o');
    $order_time = mrb_sql_hme_order_time('o');
    $by_key = [];
    $an_list = array_keys($ans);
    $chunk_size = 40;
    for ($i = 0; $i < count($an_list); $i += $chunk_size) {
        $chunk = array_slice($an_list, $i, $chunk_size);
        $in = [];
        foreach ($chunk as $an) {
            $in[] = "'" . mysqli_real_escape_string($con_hosxp, $an) . "'";
        }
        $sql = "SELECT o.an AS visit_key, IFNULL(o.order_no, '') AS order_no_key,
                $order_date AS rxdate_key, $order_time AS rxtime_key, $sel
            FROM opitemrece o
            $joins
            WHERE o.an IN (" . implode(',', $in) . ")
              AND d.istatus = 'Y'
              AND o.qty > 0
              $date_sql
              $hme
            ORDER BY o.an, o.item_no ASC";
        $q = mysqli_query($con_hosxp, $sql);
        if (!$q) {
            continue;
        }
        while ($row = mysqli_fetch_assoc($q)) {
            $map_key = mrb_ipd_order_drug_map_key(
                $row['visit_key'] ?? '',
                $row['order_no_key'] ?? '',
                $row['rxdate_key'] ?? '',
                $row['rxtime_key'] ?? ''
            );
            if (!isset($valid_keys[$map_key])) {
                continue;
            }
            if (!isset($by_key[$map_key])) {
                $by_key[$map_key] = [];
            }
            $by_key[$map_key][] = ndr_map_drug_row($row);
        }
    }
    foreach ($by_key as $key => $rows) {
        $by_key[$key] = ndr_dedupe_drugs($rows);
    }
    return $by_key;
}

function mrb_batch_visit_drugs_map($con_hosxp, array $patients, $patient_type) {
    $patient_type = mrb_normalize_patient_type($patient_type);
    $sel = ndr_sql_drug_select();
    $joins = ndr_sql_drug_joins();
    $pairs = [];
    foreach ($patients as $p) {
        $ref_an = trim($p['ref_an'] ?? '');
        $ref_vn = trim($p['ref_vn'] ?? '');
        $visit_key = $ref_an !== '' ? $ref_an : $ref_vn;
        if ($visit_key === '') {
            continue;
        }
        $hme_only = !empty($p['ref_hme_only']);
        $order_no = trim($p['hme_order_no'] ?? $p['order_no'] ?? '');
        if ($patient_type === 'IPD') {
            $rxdate = trim($p['rxdate'] ?? $p['ref_vstdate'] ?? '');
            $rxtime = trim($p['rxtime'] ?? $p['vsttime'] ?? '');
            $pair_key = mrb_ipd_order_drug_map_key($visit_key, $order_no, $rxdate, $rxtime);
            $pairs[$pair_key] = [
                'visit_key' => $visit_key,
                'hme_only' => true,
                'order_no' => $order_no,
                'rxdate' => $rxdate,
                'rxtime' => $rxtime,
            ];
        } else {
            $pair_key = $visit_key . ':' . ($hme_only ? '1' : '0');
            $pairs[$pair_key] = ['visit_key' => $visit_key, 'hme_only' => $hme_only, 'order_no' => '', 'rxdate' => '', 'rxtime' => ''];
        }
    }
    if (empty($pairs)) {
        return [];
    }
    $hme_keys = [];
    $all_keys = [];
    $order_pairs = [];
    foreach ($pairs as $pair) {
        if ($patient_type === 'IPD') {
            $order_pairs[$pair['visit_key'] . '@' . ($pair['order_no'] !== '' ? $pair['order_no'] : ('rx:' . $pair['rxdate'] . 'T' . $pair['rxtime']))] = $pair;
        } elseif ($pair['hme_only']) {
            $hme_keys[$pair['visit_key']] = true;
        } else {
            $all_keys[$pair['visit_key']] = true;
        }
    }
    $by_key = [];
    if ($patient_type === 'IPD') {
        $screen_date = '';
        foreach ($patients as $p) {
            $d = trim($p['rxdate'] ?? $p['vstdate'] ?? $p['ref_vstdate'] ?? '');
            if ($d !== '') {
                $screen_date = $d;
                break;
            }
        }
        $by_key = mrb_batch_ipd_order_drugs_map($con_hosxp, $order_pairs, $screen_date);
        return $by_key;
    }
    $load_chunk = function (array $keys, $with_hme) use ($con_hosxp, $sel, $joins, &$by_key) {
        if (empty($keys)) {
            return;
        }
        $in = [];
        foreach (array_keys($keys) as $k) {
            $in[] = "'" . mysqli_real_escape_string($con_hosxp, $k) . "'";
        }
        $use_joins = $joins;
        $hme = '';
        if ($with_hme) {
            $use_joins .= "
        LEFT JOIN opitemrece_home oh ON oh.opi_guid = o.hos_guid AND oh.opitemrece_home_type_id = 2";
            $hme = mrb_sql_hme_filter('o', 'ion', 'oh');
        }
        $sql = "SELECT o.vn AS visit_key, '' AS order_no_key, $sel
            FROM opitemrece o
            $use_joins
            WHERE o.vn IN (" . implode(',', $in) . ")
              AND d.istatus = 'Y'
              $hme
            ORDER BY o.item_no ASC";
        $q = mysqli_query($con_hosxp, $sql);
        if (!$q) {
            return;
        }
        while ($row = mysqli_fetch_assoc($q)) {
            $key = trim($row['visit_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $map_key = $key . ($with_hme ? ':1' : ':0');
            if (!isset($by_key[$map_key])) {
                $by_key[$map_key] = [];
            }
            $by_key[$map_key][] = ndr_map_drug_row($row);
        }
    };
    $load_chunk($all_keys, false);
    $load_chunk($hme_keys, true);
    foreach ($by_key as $key => $rows) {
        $by_key[$key] = ndr_dedupe_drugs($rows);
    }
    return $by_key;
}

function mrb_attach_patient_meta(array &$patients, $patient_type, $screen_date, array $amp_map, array $pttype_map, array $prior_ref_map = []) {
    $patient_type = mrb_normalize_patient_type($patient_type);
    foreach ($patients as &$p) {
        $hn = trim($p['hn'] ?? '');
        $meta = $amp_map[$hn] ?? null;
        $p['amp_name'] = $meta['amp_name'] ?? '';
        $p['tmb_name'] = $meta['tmb_name'] ?? '';
        $p['address'] = $meta['address'] ?? '';
        if (trim($p['ptname'] ?? '') === '' && $meta) {
            $p['ptname'] = $meta['full_name'] ?? '';
        }
        $cur_key = $patient_type === 'IPD' ? trim($p['an'] ?? '') : trim($p['vn'] ?? '');
        if ($patient_type === 'IPD') {
            if (trim($p['hme_order_key'] ?? '') === '') {
                mrb_attach_ipd_hme_order_ref($p);
            }
        } else {
            $ref = $prior_ref_map[$cur_key] ?? null;
            if ($ref) {
                $p['ref_vn'] = trim($ref['ref_vn'] ?? '');
                $p['ref_an'] = trim($ref['ref_an'] ?? '');
                $p['ref_visit_type'] = trim($ref['ref_visit_type'] ?? 'opd');
                $p['ref_visit_label'] = trim($ref['ref_visit_label'] ?? '');
                $p['ref_vstdate'] = trim($ref['ref_vstdate'] ?? '');
                $p['ref_hme_only'] = !empty($ref['ref_hme_only']);
            } else {
                $p['ref_vn'] = '';
                $p['ref_an'] = '';
                $p['ref_visit_type'] = 'opd';
                $p['ref_visit_label'] = '';
                $p['ref_vstdate'] = '';
                $p['ref_hme_only'] = false;
            }
        }
        $pt_key = $patient_type === 'IPD' ? ('an:' . trim($p['an'] ?? '')) : ('vn:' . trim($p['vn'] ?? ''));
        $pt = $pttype_map[$pt_key] ?? ['pttype' => '', 'pttype_name' => ''];
        $p['pttype'] = $pt['pttype'] ?? '';
        $p['pttype_name'] = $pt['pttype_name'] ?? '';
    }
    unset($p);
}

function mrb_prebuild_amp_hospital_indexes($con, array $patients) {
    $amps = [];
    foreach ($patients as $p) {
        $amp = trim($p['amp_name'] ?? '');
        if ($amp !== '') {
            $amps[$amp] = true;
        }
    }
    $hospital_cache = [];
    $index_cache = [];
    $hos_set = [];
    foreach (array_keys($amps) as $amp_name) {
        $hos = ndr_resolve_hospital_for_amp_name($con, $amp_name);
        $hospital_cache[$amp_name] = $hos;
        if ($hos !== '') {
            $hos_set[$hos] = true;
        }
    }
    $built = !empty($hos_set) ? ndr_build_hospital_drug_index($con, array_keys($hos_set)) : [];
    foreach (array_keys($amps) as $amp_name) {
        $hos = $hospital_cache[$amp_name] ?? '';
        if ($hos === '' || !isset($built[$hos])) {
            $index_cache[$amp_name] = null;
            continue;
        }
        $index_cache[$amp_name] = $built[$hos];
    }
    return [$hospital_cache, $index_cache];
}

function mrb_enrich_patients_match_fast($con, $con_hosxp, array &$patients, $patient_type, $min_similar_score = 90, array $exclude_icodes = []) {
    if (empty($patients)) {
        return;
    }
    $patient_type = mrb_normalize_patient_type($patient_type);
    $drugs_map = mrb_batch_visit_drugs_map($con_hosxp, $patients, $patient_type);
    list($hospital_cache, $index_cache) = mrb_prebuild_amp_hospital_indexes($con, $patients);
    $substitute_lookup = mrb_build_substitute_lookup($con);

    foreach ($patients as &$p) {
        $amp_name = trim($p['amp_name'] ?? '');
        $ref_vn = trim($p['ref_vn'] ?? '');
        $ref_an = trim($p['ref_an'] ?? '');
        $ref_type = trim($p['ref_visit_type'] ?? ($patient_type === 'IPD' ? 'ipd' : 'opd'));
        if ($ref_vn === '' && $ref_an === '') {
            $p['match_hospital'] = '';
            $p['match_total'] = 0;
            $p['match_matched'] = 0;
            $p['match_percent'] = null;
            $p['match_note'] = 'ไม่พบ VN/AN อ้างอิงยาใน 1 ปีก่อน Visit นี้';
            $p['drug_count'] = 0;
            continue;
        }
        $visit_key = $ref_an !== '' ? $ref_an : $ref_vn;
        if ($patient_type === 'IPD') {
            $drug_map_key = mrb_ipd_order_drug_map_key(
                $visit_key,
                trim($p['hme_order_no'] ?? ''),
                trim($p['rxdate'] ?? $p['ref_vstdate'] ?? ''),
                trim($p['rxtime'] ?? $p['vsttime'] ?? '')
            );
        } else {
            $drug_map_key = $visit_key . ':' . (!empty($p['ref_hme_only']) ? '1' : '0');
        }
        $drugs = $drugs_map[$drug_map_key] ?? [];
        $drugs = mrb_filter_drugs_excluded($drugs, $exclude_icodes);
        if (empty($drugs)) {
            $p['match_hospital'] = '';
            $p['match_total'] = 0;
            $p['match_matched'] = 0;
            $p['match_percent'] = null;
            $p['match_note'] = !empty($exclude_icodes)
                ? 'ไม่มีรายการยาหลังตัดยาที่ยกเว้น'
                : ($patient_type === 'IPD'
                    ? 'ไม่พบรายการยาในรอบสั่ง HME นี้'
                    : (!empty($p['ref_hme_only'])
                        ? 'ไม่พบรายการยา HME ใน Visit อ้างอิง'
                        : 'ไม่พบรายการยาใน Visit อ้างอิง'));
            $p['drug_count'] = 0;
            continue;
        }
        if ($amp_name === '') {
            $p['match_hospital'] = '';
            $p['match_total'] = count($drugs);
            $p['match_matched'] = 0;
            $p['match_percent'] = null;
            $p['match_note'] = 'ไม่ระบุอำเภอ';
            $p['drug_count'] = count($drugs);
            continue;
        }
        $hospital = $hospital_cache[$amp_name] ?? '';
        $hos_index = $index_cache[$amp_name] ?? null;
        if ($hospital === '' || $hos_index === null) {
            $p['match_hospital'] = '';
            $p['match_total'] = count($drugs);
            $p['match_matched'] = 0;
            $p['match_percent'] = null;
            $p['match_note'] = 'ไม่พบบัญชียา รพ.ในอำเภอนี้';
            $p['drug_count'] = count($drugs);
            continue;
        }
        $summary = mrb_summarize_match_with_substitutes($drugs, $hos_index, $min_similar_score, $substitute_lookup);
        $p['match_hospital'] = $hospital;
        $p['match_total'] = $summary['total'];
        $p['match_matched'] = $summary['matched'];
        $p['match_percent'] = $summary['match_percent'];
        $p['match_note'] = '';
        $p['drug_count'] = count($drugs);
    }
    unset($p);
}

function mrb_excluded_amp_names() {
    return ['เมืองสระแก้ว'];
}

function mrb_is_excluded_amp($amp_name) {
    $name = trim((string)$amp_name);
    if ($name === '') {
        return false;
    }
    $name_canon = function_exists('ndr_hosname_canonical') ? ndr_hosname_canonical($name) : mb_strtolower($name);
    foreach (mrb_excluded_amp_names() as $ex) {
        $ex_canon = function_exists('ndr_hosname_canonical') ? ndr_hosname_canonical($ex) : mb_strtolower($ex);
        if ($name_canon === $ex_canon) {
            return true;
        }
    }
    return false;
}

function mrb_filter_patients_exclude_amp(array $patients) {
    $out = [];
    foreach ($patients as $p) {
        if (!mrb_is_excluded_amp($p['amp_name'] ?? '')) {
            $out[] = $p;
        }
    }
    return $out;
}

function mrb_filter_patients_by_pttype(array $patients, array $pttype_codes) {
    if (empty($pttype_codes)) {
        return $patients;
    }
    $out = [];
    foreach ($patients as $p) {
        if (mrb_patient_passes_pttype($p['pttype'] ?? '', $pttype_codes)) {
            $out[] = $p;
        }
    }
    return $out;
}

function mrb_filter_patients_by_match(array $patients, array $settings) {
    $min_pct = max(0, min(100, (int)($settings['min_match_percent'] ?? 80)));
    $out = [];
    foreach ($patients as $p) {
        if ((int)($p['match_total'] ?? 0) <= 0) {
            continue;
        }
        if ($p['match_percent'] === null) {
            continue;
        }
        if ((int)$p['match_percent'] < $min_pct) {
            continue;
        }
        $out[] = $p;
    }
    return $out;
}

function mrb_filter_patients(array $patients, array $settings, array $pttype_codes) {
    return mrb_filter_patients_by_match(
        mrb_filter_patients_by_pttype($patients, $pttype_codes),
        $settings
    );
}

function mrb_sync_cases($con, array $patients, $patient_type, $screen_date) {
    mrb_ensure_tables($con);
    $patient_type = mrb_normalize_patient_type($patient_type);
    $date_esc = mysqli_real_escape_string($con, $screen_date);
    $ptype_esc = mysqli_real_escape_string($con, $patient_type);

    mysqli_query($con, "UPDATE refer_back_cases SET last_seen_at=NOW()
        WHERE screen_date='$date_esc' AND patient_type='$ptype_esc'");

    $ids = [];
    foreach ($patients as $p) {
        $hn = trim($p['hn'] ?? '');
        if ($hn === '') {
            continue;
        }
        $vn = trim($p['vn'] ?? '');
        $an = trim($p['an'] ?? '');
        $uid = mrb_case_uid($patient_type, $screen_date, $hn, $vn, $an, trim($p['hme_order_key'] ?? ''));
        $uid_esc = mysqli_real_escape_string($con, $uid);
        $hn_esc = mysqli_real_escape_string($con, $hn);
        $vn_esc = mysqli_real_escape_string($con, $vn);
        $an_esc = mysqli_real_escape_string($con, $an);
        $pt_esc = mysqli_real_escape_string($con, $p['ptname'] ?? '');
        $amp_esc = mysqli_real_escape_string($con, $p['amp_name'] ?? '');
        $pttype_esc = mysqli_real_escape_string($con, $p['pttype_name'] ?? '');
        $hos_esc = mysqli_real_escape_string($con, $p['match_hospital'] ?? '');
        $pct = $p['match_percent'] !== null ? (int)$p['match_percent'] : 'NULL';
        $total = (int)($p['match_total'] ?? 0);
        $matched = (int)($p['match_matched'] ?? 0);
        $ref_vn_esc = mysqli_real_escape_string($con, trim($p['ref_vn'] ?? ''));
        $ref_an_esc = mysqli_real_escape_string($con, trim($p['ref_an'] ?? ''));
        $ref_vt_esc = mysqli_real_escape_string($con, $p['ref_visit_type'] ?? ($patient_type === 'IPD' ? 'ipd' : 'opd'));

        $q = mysqli_query($con, "SELECT id FROM refer_back_cases WHERE case_uid='$uid_esc' LIMIT 1");
        $existing = $q ? mysqli_fetch_assoc($q) : null;
        if ($existing) {
            mysqli_query($con, "UPDATE refer_back_cases SET
                ptname='$pt_esc', amp_name='$amp_esc', pttype_name='$pttype_esc',
                match_hospital='$hos_esc', match_percent=$pct, match_total=$total, match_matched=$matched,
                ref_vn='$ref_vn_esc', ref_an='$ref_an_esc', ref_visit_type='$ref_vt_esc',
                last_seen_at=NOW()
                WHERE case_uid='$uid_esc'");
            $ids[] = (int)$existing['id'];
        } else {
            mysqli_query($con, "INSERT INTO refer_back_cases
                (case_uid, hn, vn, an, patient_type, screen_date, ptname, amp_name, pttype_name,
                 match_hospital, match_percent, match_total, match_matched,
                 ref_vn, ref_an, ref_visit_type, print_status, intervention_status)
                VALUES ('$uid_esc','$hn_esc','$vn_esc','$an_esc','$ptype_esc','$date_esc',
                '$pt_esc','$amp_esc','$pttype_esc','$hos_esc',$pct,$total,$matched,
                '$ref_vn_esc','$ref_an_esc','$ref_vt_esc','pending','none')");
            $ids[] = (int)mysqli_insert_id($con);
        }
    }
    return $ids;
}

function mrb_merge_case_status($con, array $patients, $patient_type, $screen_date) {
    if (empty($patients)) {
        return [];
    }
    $patient_type = mrb_normalize_patient_type($patient_type);
    $map = [];
    $date_esc = mysqli_real_escape_string($con, $screen_date);
    $ptype_esc = mysqli_real_escape_string($con, $patient_type);
    $q = mysqli_query($con, "SELECT * FROM refer_back_cases
        WHERE screen_date='$date_esc' AND patient_type='$ptype_esc'");
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $map[$row['case_uid']] = $row;
        }
    }
    $out = [];
    foreach ($patients as $p) {
        $hn = trim($p['hn'] ?? '');
        $vn = trim($p['vn'] ?? '');
        $an = trim($p['an'] ?? '');
        $uid = mrb_case_uid($patient_type, $screen_date, $hn, $vn, $an, trim($p['hme_order_key'] ?? ''));
        $case = $map[$uid] ?? null;
        $out[] = [
            'case_id' => (int)($case['id'] ?? 0),
            'case_uid' => $uid,
            'hn' => ndr_canonical_hn($hn),
            'hn_full' => $hn,
            'vn' => $vn,
            'an' => $an,
            'ptname' => trim($p['ptname'] ?? ''),
            'dept_name' => trim($p['dept_name'] ?? ''),
            'amp_name' => trim($p['amp_name'] ?? ''),
            'pttype_name' => trim($p['pttype_name'] ?? ''),
            'match_hospital' => trim($p['match_hospital'] ?? ''),
            'match_percent' => $p['match_percent'],
            'match_total' => (int)($p['match_total'] ?? 0),
            'match_matched' => (int)($p['match_matched'] ?? 0),
            'match_note' => trim($p['match_note'] ?? ''),
            'ref_vn' => trim($p['ref_vn'] ?? ''),
            'ref_an' => trim($p['ref_an'] ?? ''),
            'ref_visit_type' => $p['ref_visit_type'] ?? ($patient_type === 'IPD' ? 'ipd' : 'opd'),
            'ref_visit_label' => trim($p['ref_visit_label'] ?? ''),
            'ref_hme_only' => !empty($p['ref_hme_only']),
            'hme_order_no' => trim($p['hme_order_no'] ?? $p['order_no'] ?? ''),
            'hme_order_key' => trim($p['hme_order_key'] ?? ''),
            'hme_rxdate' => trim($p['rxdate'] ?? $p['ref_vstdate'] ?? ''),
            'hme_rxtime' => trim($p['rxtime'] ?? ''),
            'vsttime' => trim($p['vsttime'] ?? ''),
            'rxtime' => trim($p['rxtime'] ?? ''),
            'ward_display' => trim(($p['ward_name'] ?? '') . (($p['bedno'] ?? '') ? ' / ' . $p['bedno'] : '')),
            'print_status' => $case['print_status'] ?? 'pending',
            'printed_at' => $case['printed_at'] ?? null,
            'printed_by_name' => $case['printed_by_name'] ?? '',
            'intervention_status' => $case['intervention_status'] ?? 'none',
            'intervention_note' => $case['intervention_note'] ?? '',
            'intervention_by_name' => $case['intervention_by_name'] ?? '',
            'intervention_at' => $case['intervention_at'] ?? null,
            'needs_alert' => ($case['print_status'] ?? 'pending') === 'pending',
        ];
    }
    usort($out, function ($a, $b) {
        if ($a['needs_alert'] !== $b['needs_alert']) {
            return $a['needs_alert'] ? -1 : 1;
        }
        return ((int)($b['match_percent'] ?? 0)) <=> ((int)($a['match_percent'] ?? 0));
    });
    return $out;
}

function mrb_mark_printed($con, $case_id, $user_id, $user_name) {
    $case_id = (int)$case_id;
    if ($case_id <= 0) {
        return ['ok' => false, 'message' => 'ไม่พบรายการ'];
    }
    $uname = mysqli_real_escape_string($con, $user_name);
    $uid = (int)$user_id;
    $ok = mysqli_query($con, "UPDATE refer_back_cases SET
        print_status='printed', printed_at=NOW(), printed_by=$uid, printed_by_name='$uname',
        intervention_status=IF(intervention_status='none','done',intervention_status),
        intervention_at=IF(intervention_at IS NULL, NOW(), intervention_at),
        intervention_by=IF(intervention_by IS NULL, $uid, intervention_by),
        intervention_by_name=IF(intervention_by_name IS NULL OR intervention_by_name='', '$uname', intervention_by_name)
        WHERE id=$case_id");
    return ['ok' => (bool)$ok, 'message' => $ok ? 'บันทึกการพิมพ์แล้ว' : 'บันทึกไม่สำเร็จ'];
}

function mrb_save_intervention($con, $case_id, $status, $note, $user_id, $user_name) {
    $case_id = (int)$case_id;
    if ($case_id <= 0) {
        return ['ok' => false, 'message' => 'ไม่พบรายการ'];
    }
    $status = in_array($status, ['done', 'not_done'], true) ? $status : 'not_done';
    $note_esc = mysqli_real_escape_string($con, trim((string)$note));
    $uname = mysqli_real_escape_string($con, $user_name);
    $uid = (int)$user_id;
    $ok = mysqli_query($con, "UPDATE refer_back_cases SET
        intervention_status='$status',
        intervention_note='$note_esc',
        intervention_by=$uid,
        intervention_by_name='$uname',
        intervention_at=NOW()
        WHERE id=$case_id");
    return ['ok' => (bool)$ok, 'message' => $ok ? 'บันทึก Intervention แล้ว' : 'บันทึกไม่สำเร็จ'];
}

function mrb_list_cases($con, $start_date, $end_date, $patient_type = '', $status_filter = '') {
    mrb_ensure_tables($con);
    $start_esc = mysqli_real_escape_string($con, $start_date);
    $end_esc = mysqli_real_escape_string($con, $end_date);
    $where = "screen_date BETWEEN '$start_esc' AND '$end_esc'";
    if ($patient_type !== '' && in_array($patient_type, ['OPD', 'IPD'], true)) {
        $where .= " AND patient_type='" . mysqli_real_escape_string($con, $patient_type) . "'";
    }
    if ($status_filter === 'printed') {
        $where .= " AND print_status='printed'";
    } elseif ($status_filter === 'pending') {
        $where .= " AND print_status='pending'";
    } elseif ($status_filter === 'done') {
        $where .= " AND intervention_status='done'";
    } elseif ($status_filter === 'not_done') {
        $where .= " AND intervention_status='not_done'";
    }
    $rows = [];
    $q = mysqli_query($con, "SELECT * FROM refer_back_cases WHERE $where ORDER BY screen_date DESC, last_seen_at DESC LIMIT 500");
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $row['print_status_label'] = ($row['print_status'] ?? '') === 'printed' ? 'พิมพ์แล้ว' : 'รอ Print';
            $row['intervention_status_label'] = mrb_intervention_label($row['intervention_status'] ?? 'none');
            $rows[] = $row;
        }
    }
    return $rows;
}

function mrb_intervention_label($status) {
    if ($status === 'done') {
        return 'ทำแล้ว';
    }
    if ($status === 'not_done') {
        return 'ไม่ได้ทำ';
    }
    return 'ยังไม่บันทึก';
}

function mrb_intervention_stats($con, $start_date, $end_date) {
    $start_esc = mysqli_real_escape_string($con, $start_date);
    $end_esc = mysqli_real_escape_string($con, $end_date);
    $stats = [
        'total' => 0,
        'printed' => 0,
        'pending_print' => 0,
        'done' => 0,
        'not_done' => 0,
        'none' => 0,
    ];
    $q = mysqli_query($con, "SELECT print_status, intervention_status, COUNT(*) AS c
        FROM refer_back_cases
        WHERE screen_date BETWEEN '$start_esc' AND '$end_esc'
        GROUP BY print_status, intervention_status");
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $c = (int)($row['c'] ?? 0);
            $stats['total'] += $c;
            if (($row['print_status'] ?? '') === 'printed') {
                $stats['printed'] += $c;
            } else {
                $stats['pending_print'] += $c;
            }
            $ist = $row['intervention_status'] ?? 'none';
            if (isset($stats[$ist])) {
                $stats[$ist] += $c;
            }
        }
    }
    return $stats;
}

function mrb_fetch_match_detail(
    $con,
    $con_hosxp,
    $hn,
    $amp_name,
    $vn,
    $an,
    $ref_visit_type = 'opd',
    $min_similar_score = 90,
    $ref_visit_label = '',
    $ref_hme_only = null,
    array $exclude_icodes = [],
    $hme_order_no = '',
    $hme_rxdate = '',
    $hme_rxtime = ''
) {
    $hn = trim((string)$hn);
    $amp_name = trim((string)$amp_name);
    $vn = trim((string)$vn);
    $an = trim((string)$an);
    $ref_visit_type = ndr_appt_normalize_visit_type($ref_visit_type);
    if ($ref_visit_type === 'all') {
        $ref_visit_type = $an !== '' ? 'ipd' : 'opd';
    }
    if ($hn === '') {
        return ['status' => 'error', 'message' => 'กรุณาระบุ HN'];
    }
    if ($amp_name === '') {
        return ['status' => 'error', 'message' => 'ไม่ระบุอำเภอของผู้ป่วย'];
    }
    if ($vn === '' && $an === '') {
        return ['status' => 'error', 'message' => 'ไม่พบ Visit อ้างอิงยาใน 1 ปีก่อน Visit นี้'];
    }
    $hospital = ndr_resolve_hospital_for_amp_name($con, $amp_name);
    if ($hospital === '') {
        return ['status' => 'error', 'message' => 'ไม่พบบัญชียาเครือข่ายของ รพ.ในอำเภอ ' . $amp_name];
    }
    $patient = ndr_fetch_patient($con_hosxp, $hn);
    if (!$patient) {
        return ['status' => 'error', 'message' => 'ไม่พบผู้ป่วย HN นี้'];
    }
    if ($ref_hme_only === null) {
        $ref_hme_only = stripos($ref_visit_label, 'HME') !== false;
    }
    $hme_only = (bool)$ref_hme_only;
    $is_ipd_hme_order = $an !== '' && ($hme_order_no !== '' || $hme_rxdate !== '');
    $drug_opts = ['hme_only' => $hme_only];
    if ($is_ipd_hme_order) {
        $drug_opts['order_no'] = trim((string)$hme_order_no);
        $drug_opts['rxdate'] = trim((string)$hme_rxdate);
        $drug_opts['rxtime'] = trim((string)$hme_rxtime);
    }
    $drugs = ndr_fetch_visit_drugs($con_hosxp, $vn, $an, $ref_visit_type, $drug_opts);
    $drugs = mrb_filter_drugs_excluded($drugs, $exclude_icodes);
    if (empty($drugs)) {
        return ['status' => 'error', 'message' => !empty($exclude_icodes)
            ? 'ไม่มีรายการยาหลังตัดยาที่ยกเว้น'
            : ($is_ipd_hme_order
                ? 'ไม่พบรายการยาในรอบสั่ง HME นี้'
                : ($hme_only
                    ? 'ไม่พบรายการยา HME ใน Visit อ้างอิง'
                    : 'ไม่พบรายการยาใน Visit อ้างอิง'))];
    }
    $index = ndr_build_hospital_drug_index($con, [$hospital]);
    $hos_index = $index[$hospital] ?? ['tmtdrug' => [], 'tpu' => [], 'drugs' => []];
    $substitute_lookup = mrb_build_substitute_lookup($con);
    $compare = mrb_compare_drugs_with_substitutes($drugs, $hos_index, $hospital, $min_similar_score, $substitute_lookup);
    $label = trim((string)$ref_visit_label);
    if ($label === '') {
        $label = $an !== '' ? ('AN: ' . $an) : ('VN: ' . $vn);
    }
    return [
        'status' => 'success',
        'patient' => $patient,
        'hospital' => $hospital,
        'amp_name' => $amp_name,
        'ref_visit' => [
            'vn' => $vn,
            'an' => $an,
            'visit_type' => $ref_visit_type,
            'visit_label' => $label,
            'filter_note' => $is_ipd_hme_order
                ? 'ยาในรอบสั่ง HME นี้'
                : ($hme_only
                    ? 'VN/AN HME ล่าสุดภายใน 1 ปี'
                    : 'VN/AN ล่าสุดภายใน 1 ปี'),
        ],
        'visit_type' => $ref_visit_type,
        'visit_type_label' => ndr_appt_visit_type_label($ref_visit_type),
        'min_similar_score' => (int)$min_similar_score,
        'rows' => $compare['rows'],
        'summary' => $compare['summary'],
    ];
}

function mrb_screen_list($con, $con_hosxp, $patient_type, $screen_date, $time_window = 'full', $dept_codes = []) {
    $time_window = mrb_normalize_time_window($time_window, $screen_date);
    $settings = mrb_get_settings($con, $patient_type);
    if (empty($settings['enabled'])) {
        return [
            'patients' => [],
            'settings' => $settings,
            'time_window' => $time_window,
            'time_window_label' => mrb_time_window_label($time_window),
            'message' => 'ระบบ Monitor Refer Back ปิดอยู่ — เปิดได้ที่ตั้งค่า',
        ];
    }
    $pttype_codes = mrb_resolve_pttype_codes($con_hosxp, $settings);
    $patient_type_norm = mrb_normalize_patient_type($patient_type);
    $dept_codes = $patient_type_norm === 'OPD' ? mrb_normalize_opd_dept_codes($dept_codes) : [];
    $dept_label = $patient_type_norm === 'OPD'
        ? mrb_resolve_opd_dept_filter_label($con_hosxp, $screen_date, $dept_codes)
        : '';
    $raw = mrb_fetch_screen_patients($con_hosxp, $patient_type, $screen_date, $time_window, $dept_codes);
    $visit_total = count($raw);
    $scanned_total = $visit_total;
    if ($patient_type_norm === 'IPD') {
        $prior_ref_map = [];
        $raw = mrb_filter_patients_by_pttype($raw, $pttype_codes);
        $scanned_total = count($raw);
    } else {
        $prior_ref_map = mrb_batch_prior_ref_map($con_hosxp, $raw, $patient_type, $screen_date);
        $raw = array_values(array_filter($raw, function ($p) use ($prior_ref_map) {
            $key = trim($p['vn'] ?? '');
            return $key !== '' && isset($prior_ref_map[$key]);
        }));
        $scanned_total = count($raw);
    }
    $hns = [];
    foreach ($raw as $p) {
        $hn = trim($p['hn'] ?? '');
        if ($hn !== '') {
            $hns[] = $hn;
        }
    }
    $amp_map = mrb_batch_patient_amp_map($con_hosxp, $hns);
    if ($patient_type_norm === 'IPD') {
        $pttype_map = [];
        foreach ($raw as $p) {
            $an = trim($p['an'] ?? '');
            if ($an === '') {
                continue;
            }
            $pttype_map['an:' . $an] = [
                'pttype' => trim($p['pttype'] ?? ''),
                'pttype_name' => trim($p['pttype_name'] ?? ''),
            ];
        }
    } else {
        $pttype_map = mrb_batch_pttype_map($con_hosxp, $raw, $patient_type, $screen_date);
    }
    mrb_attach_patient_meta($raw, $patient_type, $screen_date, $amp_map, $pttype_map, $prior_ref_map);
    $after_amp = mrb_filter_patients_exclude_amp($raw);
    $pt_filtered = $patient_type_norm === 'IPD'
        ? $after_amp
        : mrb_filter_patients_by_pttype($after_amp, $pttype_codes);
    mrb_enrich_patients_match_fast(
        $con,
        $con_hosxp,
        $pt_filtered,
        $patient_type,
        (int)($settings['min_similar_score'] ?? 90),
        mrb_exclude_drug_icodes($con, $patient_type)
    );
    $filtered = mrb_filter_patients_by_match($pt_filtered, $settings);
    mrb_sync_cases($con, $filtered, $patient_type, $screen_date);
    $patients = mrb_merge_case_status($con, $filtered, $patient_type, $screen_date);
    $pending = 0;
    foreach ($patients as $p) {
        if ($p['needs_alert']) {
            $pending++;
        }
    }
    return [
        'patients' => $patients,
        'settings' => $settings,
        'pttype_filter' => [
            'mode' => $settings['pttype_mode'] ?? 'default',
            'codes' => $pttype_codes,
            'label' => mrb_pttype_filter_label($settings, $pttype_codes),
        ],
        'visit_total' => $visit_total,
        'scanned_total' => $scanned_total,
        'after_pttype' => count($pt_filtered),
        'total' => count($patients),
        'pending_print' => $pending,
        'screen_date' => $screen_date,
        'patient_type' => mrb_normalize_patient_type($patient_type),
        'time_window' => $time_window,
        'time_window_label' => mrb_time_window_label($time_window),
        'monitor_mode' => $patient_type_norm === 'IPD' ? 'ipd_hme_order' : 'opd_visit',
        'dept_filter' => [
            'codes' => $dept_codes,
            'label' => $dept_label,
        ],
    ];
}
