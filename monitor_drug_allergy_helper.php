<?php
/**
 * Monitor Drug Allergy — ตรวจแพ้ยาด้วยชื่อ TMT + ข้ามกลุ่ม
 */

function mda_default_config() {
    return [
        'mda_cross_group_enabled' => '1',
        'mda_pharmacology_group_enabled' => '1',
        'mda_direct_alert_level' => 'hard',
        'mda_cross_alert_level' => 'soft',
        'mda_match_min_token' => '4',
        'mda_exclude_hns' => '',
    ];
}

function mda_ensure_tables($con) {
    $q = mysqli_query($con, "SHOW TABLES LIKE 'monitor_drug_allergy_cross_rules'");
    if ($q && mysqli_num_rows($q) > 0) {
        return true;
    }
    $sql = @file_get_contents(__DIR__ . '/sql/monitor_drug_allergy.sql');
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

function mda_ensure_config($con) {
    foreach (mda_default_config() as $k => $v) {
        $k_esc = mysqli_real_escape_string($con, $k);
        $q = mysqli_query($con, "SELECT config_name FROM sys_config WHERE config_name='$k_esc' LIMIT 1");
        if ($q && mysqli_num_rows($q) === 0) {
            $v_esc = mysqli_real_escape_string($con, $v);
            mysqli_query($con, "INSERT INTO sys_config (config_name, config_value) VALUES ('$k_esc', '$v_esc')");
        }
    }
}

function mda_seed_cross_rules($con) {
    if (!mda_ensure_tables($con)) {
        return false;
    }
    $q = mysqli_query($con, "SELECT COUNT(*) AS c FROM monitor_drug_allergy_cross_rules");
    $row = $q ? mysqli_fetch_assoc($q) : null;
    if ($row && (int)$row['c'] > 0) {
        return true;
    }
    $defaults = [
        ['Penicillin → Aminopenicillin', 'penicillin|penicillins|เพนิซิลลิน', 'amoxicillin|ampicillin|amoxycillin|flucloxacillin|dicloxacillin|benzylpenicillin|phenoxymethylpenicillin', 'hard'],
        ['Penicillin → Cephalosporin (ระวัง)', 'penicillin|penicillins|เพนิซิลลิน', 'cephalexin|cefuroxime|ceftriaxone|cefotaxime|cefixime|cefazolin|ceftazidime', 'soft'],
        ['NSAID cross-reactivity', 'diclofenac|ibuprofen|naproxen|mefenamic|piroxicam|indomethacin|ketoprofen|nsaid|ไอบูโพรเฟน|ไดโคลฟีแนก', 'diclofenac|ibuprofen|naproxen|mefenamic|piroxicam|indomethacin|ketoprofen|aceclofenac|etoricoxib|meloxicam', 'soft'],
        ['Sulfa drugs', 'sulfa|sulfamethoxazole|trimethoprim|ซัลฟา|ซัลฟาเมทอกซาโซล', 'sulfamethoxazole|trimethoprim|sulfadiazine|sulfasalazine', 'hard'],
    ];
    foreach ($defaults as $d) {
        $name = mysqli_real_escape_string($con, $d[0]);
        $a_rx = mysqli_real_escape_string($con, $d[1]);
        $r_rx = mysqli_real_escape_string($con, $d[2]);
        $lvl = mysqli_real_escape_string($con, $d[3]);
        mysqli_query($con, "INSERT INTO monitor_drug_allergy_cross_rules
            (rule_name, allergy_regex, rx_regex, alert_level, active)
            VALUES ('$name', '$a_rx', '$r_rx', '$lvl', 1)");
    }
    return true;
}

function mda_load_config($con) {
    mda_ensure_config($con);
    $cfg = mda_default_config();
    $keys = array_keys($cfg);
    $in = "'" . implode("','", array_map(function ($k) use ($con) {
        return mysqli_real_escape_string($con, $k);
    }, $keys)) . "'";
    $q = mysqli_query($con, "SELECT config_name, config_value FROM sys_config WHERE config_name IN ($in)");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $cfg[$r['config_name']] = $r['config_value'];
        }
    }
    return $cfg;
}

function mda_save_config($con, array $data) {
    mda_ensure_config($con);
    $defaults = mda_default_config();
    foreach ($defaults as $k => $default) {
        if (!array_key_exists($k, $data)) {
            continue;
        }
        $val = trim((string)$data[$k]);
        if ($k === 'mda_cross_group_enabled' || $k === 'mda_pharmacology_group_enabled') {
            $val = ($val === '1' || $val === 'on' || $val === 'true') ? '1' : '0';
        }
        if ($k === 'mda_match_min_token') {
            $val = (string)max(3, min(12, (int)$val));
        }
        if ($k === 'mda_direct_alert_level' || $k === 'mda_cross_alert_level') {
            $val = ($val === 'soft') ? 'soft' : 'hard';
        }
        $k_esc = mysqli_real_escape_string($con, $k);
        $v_esc = mysqli_real_escape_string($con, $val);
        mysqli_query($con, "UPDATE sys_config SET config_value='$v_esc' WHERE config_name='$k_esc'");
    }
    return true;
}

function mda_load_cross_rules($con, $active_only = true) {
    mda_ensure_tables($con);
    $sql = "SELECT * FROM monitor_drug_allergy_cross_rules";
    if ($active_only) {
        $sql .= " WHERE active = 1";
    }
    $sql .= " ORDER BY rule_name ASC, id ASC";
    $rules = [];
    $q = mysqli_query($con, $sql);
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $rules[] = $r;
        }
    }
    return $rules;
}

function mda_validate_regex($regex) {
    $regex = trim((string)$regex);
    if ($regex === '') {
        return ['ok' => false, 'error' => 'กรุณาระบุ REGEXP'];
    }
    if (@preg_match('/' . str_replace('/', '\\/', $regex) . '/iu', '') === false) {
        return ['ok' => false, 'error' => 'รูปแบบ REGEXP ไม่ถูกต้อง'];
    }
    return ['ok' => true, 'error' => ''];
}

function mda_normalize_text($text) {
    $text = trim(preg_replace('/\s+/u', ' ', (string)$text));
    if ($text === '') {
        return '';
    }
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($text, 'UTF-8');
    }
    return strtolower($text);
}

function mda_strpos($haystack, $needle) {
    if (function_exists('mb_strpos')) {
        return mb_strpos($haystack, $needle, 0, 'UTF-8');
    }
    return strpos($haystack, $needle);
}

function mda_strlen($text) {
    if (function_exists('mb_strlen')) {
        return mb_strlen($text, 'UTF-8');
    }
    return strlen($text);
}

function mda_tokens($text, $min_len = 4) {
    $text = mda_normalize_text($text);
    $parts = preg_split('/[\s\/\(\)\+\-,;]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $tokens = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if (mda_strlen($p) >= $min_len) {
            $tokens[] = $p;
        }
    }
    return array_values(array_unique($tokens));
}

function mda_regex_match($regex, $text) {
    $regex = trim((string)$regex);
    if ($regex === '') {
        return false;
    }
    $pattern = '/' . str_replace('/', '\\/', $regex) . '/iu';
    return @preg_match($pattern, (string)$text) === 1;
}

/**
 * จับคู่ตรงด้วยชื่อ TMT / active ingredient
 */
function mda_direct_tmt_match($allergy_agent, $tmt_name, $active_ingredient, $local_name, $min_token = 4) {
    $agent = mda_normalize_text($allergy_agent);
    if ($agent === '') {
        return false;
    }
    $haystacks = array_filter([
        mda_normalize_text($tmt_name),
        mda_normalize_text($active_ingredient),
        mda_normalize_text($local_name),
    ]);
    foreach ($haystacks as $hay) {
        if ($hay === '') {
            continue;
        }
        if ($agent === $hay) {
            return true;
        }
        if (mda_strpos($hay, $agent) !== false || mda_strpos($agent, $hay) !== false) {
            return true;
        }
        foreach (mda_tokens($agent, $min_token) as $tok) {
            if (mda_strpos($hay, $tok) !== false) {
                return true;
            }
        }
        foreach (mda_tokens($hay, $min_token) as $tok) {
            if (mda_strpos($agent, $tok) !== false) {
                return true;
            }
        }
    }
    return false;
}

function mda_collect_pharma_group_ids(array $dp_row) {
    $ids = [];
    foreach (['pharmacology_group_id', 'pharmacology_group_id_2', 'pharmacology_group_id_3', 'pharmacology_group_id_4'] as $k) {
        $id = (int)($dp_row[$k] ?? 0);
        if ($id > 0) {
            $ids[$id] = true;
        }
    }
    return array_keys($ids);
}

/**
 * โหลดดัชนีกลุ่มเภสัชวิทยา (icode → groups, group → ชื่อยา TMT)
 */
function mda_build_pharma_index($hos) {
    $tmt_expr = hosxp_sql_tmt_drug_name('d', 'tm');
    $sql = "
        SELECT d.icode,
               $tmt_expr AS tmt_name,
               CAST(CONVERT(IFNULL(tm.active_ingredient,'') USING utf8) AS CHAR) AS active_ingredient,
               CAST(CONVERT(d.name USING utf8) AS CHAR) AS local_name,
               dp.pharmacology_group_id, dp.pharmacology_group_id_2, dp.pharmacology_group_id_3, dp.pharmacology_group_id_4,
               CAST(CONVERT(IFNULL(pg.pharmacology_group_name,'') USING utf8) AS CHAR) AS pharmacology_group_name
        FROM drugitems_pharmacology dp
        INNER JOIN drugitems d ON d.icode = dp.icode
        " . hosxp_sql_tmt_join('d', 'tm') . "
        LEFT JOIN pharmacology_group pg ON pg.pharmacology_group_id = dp.pharmacology_group_id
        WHERE d.istatus = 'Y'
          AND (
            dp.pharmacology_group_id > 0 OR dp.pharmacology_group_id_2 > 0
            OR dp.pharmacology_group_id_3 > 0 OR dp.pharmacology_group_id_4 > 0
          )
    ";
    $icode_groups = [];
    $group_labels = [];
    $group_names = [];
    $q = mysqli_query($hos, $sql);
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $icode = trim($row['icode'] ?? '');
            if ($icode === '') {
                continue;
            }
            $groups = mda_collect_pharma_group_ids($row);
            if (!isset($icode_groups[$icode])) {
                $icode_groups[$icode] = [];
            }
            foreach ($groups as $gid) {
                $icode_groups[$icode][$gid] = true;
                if (!isset($group_labels[$gid])) {
                    $group_labels[$gid] = trim($row['pharmacology_group_name'] ?? '');
                }
                $label = trim(($row['tmt_name'] ?? '') ?: ($row['local_name'] ?? ''));
                if ($label !== '') {
                    if (!isset($group_names[$gid])) {
                        $group_names[$gid] = [];
                    }
                    $group_names[$gid][mda_normalize_text($label)] = $label;
                }
            }
        }
    }
    foreach ($icode_groups as $ic => $gmap) {
        $icode_groups[$ic] = array_map('intval', array_keys($gmap));
    }
    return [
        'icode_groups' => $icode_groups,
        'group_labels' => $group_labels,
        'group_names' => $group_names,
    ];
}

function mda_infer_allergy_pharma_groups($allergy_agent, array $pharma_index, $min_token = 4) {
    $groups = [];
    $agent = mda_normalize_text($allergy_agent);
    if ($agent === '') {
        return $groups;
    }
    foreach ($pharma_index['group_labels'] as $gid => $gname) {
        $gname_n = mda_normalize_text($gname);
        if ($gname_n !== '' && (mda_strpos($gname_n, $agent) !== false || mda_strpos($agent, $gname_n) !== false)) {
            $groups[(int)$gid] = true;
        }
    }
    foreach ($pharma_index['group_names'] as $gid => $names) {
        foreach ($names as $name_n => $name_raw) {
            if (mda_direct_tmt_match($allergy_agent, $name_raw, $name_raw, '', $min_token)) {
                $groups[(int)$gid] = true;
            }
        }
    }
    return array_map('intval', array_keys($groups));
}

function mda_build_exclude_clause($hos, $exclude_raw) {
    $exclude_raw = trim((string)$exclude_raw);
    if ($exclude_raw === '') {
        return '';
    }
    $hns = [];
    foreach (preg_split('/[,;\s]+/', $exclude_raw) as $hn) {
        $hn = trim($hn);
        if ($hn === '') {
            continue;
        }
        $hns[] = "'" . mysqli_real_escape_string($hos, str_pad(ltrim($hn, '0'), 9, '0', STR_PAD_LEFT)) . "'";
        if (ltrim($hn, '0') !== $hn) {
            $hns[] = "'" . mysqli_real_escape_string($hos, ltrim($hn, '0')) . "'";
        }
    }
    $hns = array_values(array_unique($hns));
    if (empty($hns)) {
        return '';
    }
    return 'AND o.hn NOT IN (' . implode(',', $hns) . ')';
}

function mda_alert_badge_html($level) {
    if ($level === 'hard') {
        return '<span class="badge bg-danger">Hard Alert</span>';
    }
    return '<span class="badge bg-warning text-dark">Soft Alert</span>';
}

function mda_match_type_label($type) {
    switch ($type) {
        case 'direct_tmt':
            return 'ตรง (ชื่อ TMT)';
        case 'cross_rule':
            return 'ข้ามกลุ่ม (กฎที่ตั้ง)';
        case 'pharmacology_group':
            return 'ข้ามกลุ่ม (กลุ่มเภสัชวิทยา)';
        default:
            return $type;
    }
}

/**
 * ประมวลผลแพ้ยาสำหรับรายการสั่งยา + ประวัติแพ้
 * @return array{hard:array,soft:array}
 */
function mda_evaluate_prescription(array $rx, array $allergies, array $cfg, array $cross_rules, array $pharma_index) {
    $hard = [];
    $soft = [];
    $min_token = max(3, (int)($cfg['mda_match_min_token'] ?? 4));
    $direct_level = ($cfg['mda_direct_alert_level'] ?? 'hard') === 'soft' ? 'soft' : 'hard';
    $cross_level_default = ($cfg['mda_cross_alert_level'] ?? 'soft') === 'hard' ? 'hard' : 'soft';
    $cross_enabled = ($cfg['mda_cross_group_enabled'] ?? '1') === '1';
    $pharma_enabled = ($cfg['mda_pharmacology_group_enabled'] ?? '1') === '1';

    $tmt_name = trim($rx['tmt_name'] ?? '');
    $active_ingredient = trim($rx['active_ingredient'] ?? '');
    $local_name = trim($rx['local_name'] ?? '');
    $rx_search = mda_normalize_text($tmt_name . ' ' . $active_ingredient . ' ' . $local_name);
    $icode = trim($rx['icode'] ?? '');
    $rx_groups = $pharma_index['icode_groups'][$icode] ?? mda_collect_pharma_group_ids($rx);

    $seen = [];

    foreach ($allergies as $al) {
        if (!empty($al['no_alert']) && strtoupper(trim($al['no_alert'])) === 'Y') {
            continue;
        }
        $agent = trim($al['agent'] ?? '');
        if ($agent === '') {
            continue;
        }

        $symptom = trim($al['symptom'] ?? '');
        $allergy_date = $al['report_date'] ?? '';

        // 1) Direct TMT match
        if (mda_direct_tmt_match($agent, $tmt_name, $active_ingredient, $local_name, $min_token)) {
            $key = 'direct|' . ($al['opd_allergy_id'] ?? $agent) . '|' . $icode;
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $item = mda_build_alert_item($rx, $al, 'direct_tmt', $direct_level, $agent, $symptom, $allergy_date, 'จับคู่ตรงจากชื่อ TMT กับประวัติแพ้ยา');
                if ($direct_level === 'hard') {
                    $hard[] = $item;
                } else {
                    $soft[] = $item;
                }
            }
            continue;
        }

        if (!$cross_enabled) {
            continue;
        }

        // 2) Custom cross-group rules
        foreach ($cross_rules as $rule) {
            if (empty($rule['active'])) {
                continue;
            }
            $a_rx = $rule['allergy_regex'] ?? '';
            $r_rx = $rule['rx_regex'] ?? '';
            if (!mda_regex_match($a_rx, $agent)) {
                continue;
            }
            if (!mda_regex_match($r_rx, $rx_search)) {
                continue;
            }
            $lvl = ($rule['alert_level'] ?? '') === 'soft' ? 'soft' : 'hard';
            $key = 'rule|' . ($rule['id'] ?? 0) . '|' . ($al['opd_allergy_id'] ?? $agent) . '|' . $icode;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $item = mda_build_alert_item(
                $rx,
                $al,
                'cross_rule',
                $lvl,
                $agent,
                $symptom,
                $allergy_date,
                'ข้ามกลุ่ม: ' . ($rule['rule_name'] ?? 'กฎแพ้ยาข้ามกลุ่ม')
            );
            if ($lvl === 'hard') {
                $hard[] = $item;
            } else {
                $soft[] = $item;
            }
        }

        // 3) Pharmacology group cross
        if (!$pharma_enabled || empty($rx_groups)) {
            continue;
        }
        $allergy_groups = mda_infer_allergy_pharma_groups($agent, $pharma_index, $min_token);
        $shared = array_values(array_intersect($rx_groups, $allergy_groups));
        if (empty($shared)) {
            continue;
        }
        $gid = (int)$shared[0];
        $glabel = $pharma_index['group_labels'][$gid] ?? ('กลุ่ม #' . $gid);
        $key = 'pharma|' . $gid . '|' . ($al['opd_allergy_id'] ?? $agent) . '|' . $icode;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $item = mda_build_alert_item(
            $rx,
            $al,
            'pharmacology_group',
            $cross_level_default,
            $agent,
            $symptom,
            $allergy_date,
            'ข้ามกลุ่มเภสัชวิทยา: ' . $glabel
        );
        if ($cross_level_default === 'hard') {
            $hard[] = $item;
        } else {
            $soft[] = $item;
        }
    }

    return ['hard' => $hard, 'soft' => $soft];
}

function mda_build_alert_item(array $rx, array $al, $match_type, $level, $agent, $symptom, $allergy_date, $detail) {
    $type_patient = $rx['type_patient'] ?? 'OPD';
    $ward_display = ($type_patient === 'OPD')
        ? 'ผู้ป่วยนอก'
        : trim(($rx['ward_name'] ?? '') . (($rx['ward_name'] ?? '') && ($rx['bedno'] ?? '') ? ' / ' : '') . ($rx['bedno'] ?? ''));
    if ($ward_display === '') {
        $ward_display = '-';
    }
    $rx_date_fmt = !empty($rx['rxdate']) ? date('d/m/Y', strtotime($rx['rxdate'])) : '-';
    $rx_time_fmt = function_exists('di_format_time') && function_exists('monitor_lab_normalize_hosxp_time')
        ? di_format_time(monitor_lab_normalize_hosxp_time($rx['rxtime'] ?? ''))
        : trim($rx['rxtime'] ?? '');

    return [
        'hn' => ltrim($rx['hn'] ?? '', '0'),
        'hn_full' => $rx['hn'] ?? '',
        'ptname' => trim($rx['ptname'] ?? ''),
        'type_patient' => $type_patient,
        'vn' => $rx['vn'] ?? '',
        'an' => $rx['an'] ?? '',
        'rxdate' => $rx['rxdate'] ?? '',
        'order_date' => $rx_date_fmt,
        'order_time' => $rx_time_fmt,
        'icode' => $rx['icode'] ?? '',
        'drug_name_local' => trim($rx['local_name'] ?? ''),
        'drug_name_tmt' => trim($rx['tmt_name'] ?? ''),
        'active_ingredient' => trim($rx['active_ingredient'] ?? ''),
        'qty' => $rx['qty'] ?? '',
        'ward_display' => $ward_display,
        'allergy_agent' => $agent,
        'allergy_symptom' => $symptom,
        'allergy_report_date' => $allergy_date,
        'match_type' => $match_type,
        'match_type_label' => mda_match_type_label($match_type),
        'alert_level' => $level,
        'alert_message' => 'แพ้ยา: ' . $agent . ' → สั่ง: ' . trim($rx['tmt_name'] ?? $rx['local_name'] ?? ''),
        'detail_text' => $detail,
    ];
}
