<?php
/**
 * Monitor mg → mL — ตรวจการแปลงขนาดยา mg เป็น mL ในวิธีใช้
 */

function mmgml_ensure_table($con) {
    $q = mysqli_query($con, "SHOW TABLES LIKE 'monitor_mg_ml_settings'");
    if ($q && mysqli_num_rows($q) > 0) {
        return true;
    }
    $sql = @file_get_contents(__DIR__ . '/sql/monitor_mg_ml.sql');
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

function mmgml_escape($con, $v) {
    return mysqli_real_escape_string($con, $v ?? '');
}

function mmgml_alert_level($row) {
    return (($row['alert_level'] ?? '') === 'soft') ? 'soft' : 'hard';
}

function mmgml_alert_badge_html($level) {
    if ($level === 'hard') {
        return '<span class="badge bg-danger">Hard Alert</span>';
    }
    return '<span class="badge bg-warning text-dark">Soft Alert</span>';
}

function mmgml_load_active_rules($con, array $rule_ids = []) {
    mmgml_ensure_table($con);
    $rules = [];
    $q = mysqli_query($con, "SELECT * FROM monitor_mg_ml_settings WHERE active = 1 ORDER BY drug_name ASC");
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
        $mg_per_ml = (float)($row['mg_per_ml'] ?? 0);
        if ($icode === '' || $mg_per_ml <= 0) {
            continue;
        }
        $rules[] = $row;
    }
    return $rules;
}

function mmgml_rule_is_valid(array $data) {
    $mg_per_ml = (float)($data['mg_per_ml'] ?? 0);
    if ($mg_per_ml <= 0) {
        return ['ok' => false, 'error' => 'กรุณาระบุความเข้มข้น mg/mL มากกว่า 0'];
    }
    $tol_pct = (float)($data['tolerance_pct'] ?? 5);
    $tol_ml = (float)($data['tolerance_ml'] ?? 0.02);
    if ($tol_pct < 0 || $tol_pct > 50) {
        return ['ok' => false, 'error' => 'อนุโลม % ต้องอยู่ระหว่าง 0–50'];
    }
    if ($tol_ml < 0) {
        return ['ok' => false, 'error' => 'อนุโลม mL ต้องไม่ติดลบ'];
    }
    return ['ok' => true, 'error' => ''];
}

/**
 * แปลง strength เช่น "250 mg/ml", "62.5 mg/5 mL", "600 mcg/1 mL" → mg/mL
 */
function mmgml_parse_concentration_from_strength($strength) {
    $s = trim((string)$strength);
    if ($s === '') {
        return null;
    }
    $s_norm = preg_replace('/\s+/u', ' ', $s);

    $patterns = [
        [
            'regex' => '/(\d+(?:\.\d+)?)\s*mg\s*\/\s*(\d+(?:\.\d+)?)?\s*ml\b/iu',
            'unit' => 'mg',
        ],
        [
            'regex' => '/(\d+(?:\.\d+)?)\s*(?:mcg|µg|μg|ug)\s*\/\s*(\d+(?:\.\d+)?)?\s*ml\b/iu',
            'unit' => 'mcg',
        ],
        [
            'regex' => '/(\d+(?:\.\d+)?)\s*(?:g|gm|gram|กร\.?|กรัม)\s*\/\s*(\d+(?:\.\d+)?)?\s*ml\b/iu',
            'unit' => 'g',
        ],
    ];

    foreach ($patterns as $p) {
        if (!preg_match($p['regex'], $s_norm, $m)) {
            continue;
        }
        $num = (float)$m[1];
        $vol = isset($m[2]) && $m[2] !== '' ? (float)$m[2] : 1.0;
        if ($vol <= 0) {
            continue;
        }
        if ($p['unit'] === 'mcg') {
            $num = $num / 1000;
        } elseif ($p['unit'] === 'g') {
            $num = $num * 1000;
        }
        $mg_per_ml = $num / $vol;
        if ($mg_per_ml <= 0) {
            continue;
        }
        return [
            'mg_per_ml' => $mg_per_ml,
            'strength_text' => $s,
            'parse_note' => rtrim(rtrim(number_format($num, 4, '.', ''), '0'), '.') . ' mg / '
                . rtrim(rtrim(number_format($vol, 4, '.', ''), '0'), '.') . ' mL',
        ];
    }

    return null;
}

/** ดึง mL จากวิธีใช้ — เน้นรูปแบบ (0.28 ml) หรือ 70 MG (0.28 ml) */
function mmgml_extract_ml_from_usage($text) {
    $text = trim((string)$text);
    if ($text === '') {
        return null;
    }

    if (preg_match_all('/\((\d+(?:\.\d+)?)\s*ml\)/iu', $text, $paren, PREG_SET_ORDER)) {
        foreach ($paren as $m) {
            return (float)$m[1];
        }
    }

    if (preg_match('/(\d+(?:\.\d+)?)\s*mg\b[^()]*\((\d+(?:\.\d+)?)\s*ml\)/iu', $text, $pair)) {
        return (float)$pair[2];
    }

    if (preg_match_all('/(\d+(?:\.\d+)?)\s*ml\b/iu', $text, $all, PREG_SET_ORDER)) {
        foreach ($all as $m) {
            $pos = strpos(strtolower($text), strtolower($m[0]));
            $after = substr($text, $pos + strlen($m[0]));
            if (preg_match('/^\s*\/\s*hr\b/i', $after)) {
                continue;
            }
            return (float)$m[1];
        }
    }

    return null;
}

function mmgml_format_ml($val) {
    if ($val === null) {
        return '—';
    }
    $v = (float)$val;
    if ($v <= 0) {
        return '—';
    }
    return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
}

function mmgml_format_mg($val) {
    if ($val === null) {
        return '—';
    }
    $v = (float)$val;
    if ($v <= 0) {
        return '—';
    }
    return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
}

function mmgml_compute_tolerance_ml($expected_ml, array $rule) {
    $expected_ml = (float)$expected_ml;
    $tol_pct = max(0, (float)($rule['tolerance_pct'] ?? 5));
    $tol_ml = max(0, (float)($rule['tolerance_ml'] ?? 0.02));
    $from_pct = $expected_ml * ($tol_pct / 100);
    return max($tol_ml, $from_pct);
}

/**
 * ประเมินวิธีใช้กับกฎ — คืน null ถ้าไม่ต้องแจ้ง (ข้าม/ถูกต้อง)
 */
function mmgml_evaluate_method(array $rule, $method) {
    $method = trim(preg_replace('/\s+/u', ' ', (string)$method));
    $mg_per_ml = (float)($rule['mg_per_ml'] ?? 0);
    if ($method === '' || $mg_per_ml <= 0) {
        return null;
    }

    $dose_mg = hosxp_extract_mg_from_usage($method);
    $stated_ml = mmgml_extract_ml_from_usage($method);

    if ($dose_mg === null || $dose_mg <= 0) {
        return null;
    }
    if ($stated_ml === null || $stated_ml <= 0) {
        return null;
    }

    $expected_ml = $dose_mg / $mg_per_ml;
    $tolerance = mmgml_compute_tolerance_ml($expected_ml, $rule);
    $diff = abs($stated_ml - $expected_ml);

    if ($diff <= $tolerance) {
        return null;
    }

    return [
        'dose_mg' => $dose_mg,
        'stated_ml' => $stated_ml,
        'expected_ml' => $expected_ml,
        'tolerance_ml' => $tolerance,
        'diff_ml' => $diff,
        'mg_per_ml' => $mg_per_ml,
        'strength_text' => trim($rule['strength_text'] ?? ''),
    ];
}

function mmgml_default_alert_message(array $rule, array $eval) {
    $custom = trim($rule['alert_message'] ?? '');
    if ($custom !== '') {
        return $custom;
    }
    $drug = trim($rule['drug_name'] ?? '');
    $exp = mmgml_format_ml($eval['expected_ml']);
    $got = mmgml_format_ml($eval['stated_ml']);
    $mg = mmgml_format_mg($eval['dose_mg']);
    $conc = mmgml_format_mg($eval['mg_per_ml']);
    return ($drug !== '' ? $drug . ': ' : '')
        . "{$mg} mg ควรเป็น {$exp} mL (ที่ {$conc} mg/mL) แต่ระบุ {$got} mL";
}

/** สแกน HOSxP — ดึงยาที่ strength มี mg/mL หรือ mcg/mL */
function mmgml_scan_from_tmt($hos, $con) {
    mmgml_ensure_table($con);
    if (!$hos) {
        return ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'scanned' => 0, 'message' => 'ไม่สามารถเชื่อมต่อ HOSxP'];
    }

    $strength_expr = hosxp_sql_tmt_strength('d', 'tm');
    $name_expr = hosxp_sql_tmt_drug_name('d', 'tm');
    $sql = "
        SELECT d.icode,
               CAST(CONVERT($name_expr USING utf8) AS CHAR) AS drug_name,
               CAST(CONVERT($strength_expr USING utf8) AS CHAR) AS strength
        FROM drugitems d
        " . hosxp_sql_tmt_join('d', 'tm') . "
        WHERE d.istatus = 'Y'
          AND d.icode IS NOT NULL
          AND TRIM(d.icode) <> ''
          AND (
            LOWER(CAST(CONVERT($strength_expr USING utf8) AS CHAR)) REGEXP '[0-9].*mg.*[/].*ml'
            OR LOWER(CAST(CONVERT($strength_expr USING utf8) AS CHAR)) REGEXP '[0-9].*(mcg|µg|μg|ug).*[ /].*ml'
          )
        ORDER BY drug_name ASC
    ";

    $q = mysqli_query($hos, $sql);
    if (!$q) {
        return ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'scanned' => 0, 'message' => mysqli_error($hos)];
    }

    $inserted = 0;
    $updated = 0;
    $skipped = 0;
    $scanned = 0;

    while ($row = mysqli_fetch_assoc($q)) {
        $scanned++;
        $icode = trim($row['icode'] ?? '');
        $strength = trim($row['strength'] ?? '');
        $drug_name = trim($row['drug_name'] ?? '');
        if ($icode === '' || $strength === '') {
            $skipped++;
            continue;
        }

        $parsed = mmgml_parse_concentration_from_strength($strength);
        if ($parsed === null || (float)$parsed['mg_per_ml'] <= 0) {
            $skipped++;
            continue;
        }

        $mg_per_ml = (float)$parsed['mg_per_ml'];
        $icode_e = mmgml_escape($con, $icode);
        $name_e = mmgml_escape($con, $drug_name);
        $strength_e = mmgml_escape($con, $strength);

        $chk = mysqli_query($con, "SELECT id, mg_per_ml, auto_from_tmt FROM monitor_mg_ml_settings WHERE icode='$icode_e' LIMIT 1");
        if ($chk && mysqli_num_rows($chk) > 0) {
            $existing = mysqli_fetch_assoc($chk);
            if (!empty($existing['auto_from_tmt'])) {
                $id = (int)$existing['id'];
                $sql_up = "UPDATE monitor_mg_ml_settings SET
                    drug_name='$name_e',
                    strength_text='$strength_e',
                    mg_per_ml=$mg_per_ml,
                    auto_from_tmt=1
                    WHERE id=$id";
                if (mysqli_query($con, $sql_up)) {
                    $updated++;
                }
            } else {
                $skipped++;
            }
            continue;
        }

        $sql_ins = "INSERT INTO monitor_mg_ml_settings
            (icode, drug_name, strength_text, mg_per_ml, tolerance_pct, tolerance_ml, auto_from_tmt, alert_level, active)
            VALUES ('$icode_e', '$name_e', '$strength_e', $mg_per_ml, 5.00, 0.0200, 1, 'hard', 1)";
        if (mysqli_query($con, $sql_ins)) {
            $inserted++;
        }
    }

    return [
        'inserted' => $inserted,
        'updated' => $updated,
        'skipped' => $skipped,
        'scanned' => $scanned,
        'message' => 'ok',
    ];
}

function mmgml_build_rule_map_by_icode(array $rules) {
    $map = [];
    foreach ($rules as $rule) {
        $ic = trim($rule['icode'] ?? '');
        if ($ic === '') {
            continue;
        }
        if (!isset($map[$ic])) {
            $map[$ic] = [];
        }
        $map[$ic][] = $rule;
    }
    return $map;
}
