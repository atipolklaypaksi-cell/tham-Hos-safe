<?php
/**
 * Refer ยาผู้ป่วย — เทียบรายการยาจาก HOSxP กับบัญชียาเครือข่าย
 */

require_once __DIR__ . '/hosxp_tmt_drug_helper.php';
require_once __DIR__ . '/network_drug_helper.php';

function ndr_hn_variants($hn) {
    return function_exists('dr_hn_variants') ? dr_hn_variants($hn) : _ndr_hn_variants_fallback($hn);
}

function _ndr_hn_variants_fallback($hn) {
    $hn = trim((string)$hn);
    $variants = [];
    if ($hn === '') {
        return [];
    }
    if (is_numeric($hn)) {
        $variants[] = str_pad($hn, 9, '0', STR_PAD_LEFT);
        $l = ltrim($hn, '0');
        $variants[] = ($l === '') ? '0' : $l;
    } else {
        $variants[] = $hn;
    }
    return array_values(array_unique($variants));
}

function ndr_hn_sql_in($con_hosxp, $hn) {
    $parts = [];
    foreach (ndr_hn_variants($hn) as $v) {
        $parts[] = "'" . mysqli_real_escape_string($con_hosxp, $v) . "'";
    }
    return implode(',', $parts);
}

/** HN รูปแบบมาตรฐาน 9 หลัก (เติม 0 นำหน้า) สำหรับลิงก์และแสดงผล */
function ndr_canonical_hn($hn) {
    $hn = trim((string)$hn);
    if ($hn === '') {
        return '';
    }
    if (!is_numeric($hn)) {
        return $hn;
    }
    $stripped = ltrim($hn, '0');
    return str_pad($stripped === '' ? '0' : $stripped, 9, '0', STR_PAD_LEFT);
}

function ndr_normalize_key($s) {
    $s = trim((string)$s);
    if ($s === '') {
        return '';
    }
    $s = preg_replace('/\s+/u', ' ', $s);
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($s, 'UTF-8');
    }
    return strtolower($s);
}

function ndr_is_combination_drug_name($name) {
    return strpos((string)$name, '+') !== false;
}

function ndr_drug_name_candidates(array $drug, array $fields) {
    $out = [];
    $seen = [];
    foreach ($fields as $field) {
        $value = trim($drug[$field] ?? '');
        if ($value === '') {
            continue;
        }
        $key = ndr_normalize_key($value);
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $value;
    }
    return $out;
}

/** ยาผสม (มี +) แสดงชื่อสั้น (name/drug_name) บรรทัดแรก และ tmtdrug บรรทัดถัดไป */
function ndr_drug_display_lines(array $drug, $short_field = 'drug_name') {
    $long = trim($drug['tmtdrug'] ?? $drug['drug_name_tmt'] ?? '');
    $short = trim($drug[$short_field] ?? '');
    if ($short === '' && isset($drug['name'])) {
        $short = trim($drug['name'] ?? '');
    }
    if ($long === '' && $short !== '') {
        return ['primary' => $short, 'secondary' => ''];
    }
    if ($short === '') {
        return ['primary' => $long, 'secondary' => ''];
    }
    if (ndr_normalize_key($short) === ndr_normalize_key($long)) {
        return ['primary' => $long, 'secondary' => ''];
    }
    if (ndr_is_combination_drug_name($long) || ndr_is_combination_drug_name($short)) {
        return ['primary' => $short, 'secondary' => $long];
    }
    return ['primary' => $long !== '' ? $long : $short, 'secondary' => ''];
}

function ndr_drug_apply_display_fields(array &$drug, $short_field = 'drug_name') {
    $lines = ndr_drug_display_lines($drug, $short_field);
    $drug['display_primary'] = $lines['primary'];
    $drug['display_secondary'] = $lines['secondary'];
    $drug['display_name'] = $lines['secondary'] !== ''
        ? ($lines['primary'] . ' — ' . $lines['secondary'])
        : $lines['primary'];
}

function ndr_network_drug_normalize_row(array $row) {
    $row['drug_name'] = trim($row['name'] ?? '');
    ndr_drug_apply_display_fields($row, 'drug_name');
    return $row;
}

function ndr_match_display_for_tmtdrug(array $hos_index, $tmtdrug) {
    $tmtdrug = trim((string)$tmtdrug);
    if ($tmtdrug === '') {
        return '';
    }
    $key = ndr_normalize_key($tmtdrug);
    if (isset($hos_index['display_by_key'][$key])) {
        return $hos_index['display_by_key'][$key];
    }
    foreach ($hos_index['drugs'] ?? [] as $nd) {
        if (ndr_normalize_key($nd['tmtdrug'] ?? '') === $key) {
            return $nd['display_name'] ?? $tmtdrug;
        }
    }
    return $tmtdrug;
}

function ndr_enrich_match_display(array &$match, array $hos_index) {
    $tmt = trim($match['matched_tmtdrug'] ?? '');
    if ($tmt === '') {
        return;
    }
    foreach ($hos_index['drugs'] ?? [] as $nd) {
        if (trim($nd['tmtdrug'] ?? '') === $tmt) {
            $match['matched_display'] = $nd['display_name'] ?? $tmt;
            $match['matched_display_primary'] = $nd['display_primary'] ?? '';
            $match['matched_display_secondary'] = $nd['display_secondary'] ?? '';
            return;
        }
    }
    $match['matched_display'] = ndr_match_display_for_tmtdrug($hos_index, $tmt);
    $lines = ndr_drug_display_lines(['tmtdrug' => $tmt, 'drug_name' => ''], 'drug_name');
    $match['matched_display_primary'] = $lines['primary'];
    $match['matched_display_secondary'] = $lines['secondary'];
}

function ndr_drug_display_html(array $drug, $short_field = 'drug_name') {
    if (!isset($drug['display_primary'])) {
        ndr_drug_apply_display_fields($drug, $short_field);
    }
    $html = htmlspecialchars($drug['display_primary'] ?? '', ENT_QUOTES, 'UTF-8');
    if (!empty($drug['display_secondary'])) {
        $html .= '<br><small class="text-muted">' . htmlspecialchars($drug['display_secondary'], ENT_QUOTES, 'UTF-8') . '</small>';
    }
    return $html;
}

function ndr_match_display_html($match) {
    if (!is_array($match)) {
        return '';
    }
    $primary = trim($match['matched_display_primary'] ?? '');
    if ($primary === '') {
        return htmlspecialchars($match['matched_display'] ?? $match['matched_tmtdrug'] ?? '', ENT_QUOTES, 'UTF-8');
    }
    $html = htmlspecialchars($primary, ENT_QUOTES, 'UTF-8');
    $secondary = trim($match['matched_display_secondary'] ?? '');
    if ($secondary !== '') {
        $html .= '<br><small class="text-muted">' . htmlspecialchars($secondary, ENT_QUOTES, 'UTF-8') . '</small>';
    }
    return $html;
}

/** รวมชื่อ รพ. ที่เขียนต่างกันแต่หมายถึงแห่งเดียวกัน เช่น รพ.สระแก้ว / รพร.สระแก้ว */
function ndr_hosname_canonical($hos) {
    $h = ndr_normalize_key($hos);
    $h = preg_replace('/^รพ\.?ร\.?/u', '', $h);
    $h = preg_replace('/^รพจ\.?/u', '', $h);
    $h = preg_replace('/^รพ\.?/u', '', $h);
    $h = trim($h);
    return $h;
}

function ndr_list_hospitals_grouped($con) {
    nd_ensure_tables($con);
    $rows = nd_list_hospitals_with_stats($con);
    $groups = [];
    foreach ($rows as $r) {
        $canon = ndr_hosname_canonical($r['hosname'] ?? '');
        if ($canon === '') {
            continue;
        }
        if (!isset($groups[$canon])) {
            $groups[$canon] = [
                'canonical' => $canon,
                'display_name' => $r['hosname'],
                'aliases' => [],
                'row_count' => 0,
                'drug_count' => 0,
                'best_count' => 0,
            ];
        }
        $groups[$canon]['aliases'][] = $r['hosname'];
        $groups[$canon]['row_count'] += (int)($r['row_count'] ?? 0);
        $groups[$canon]['drug_count'] += (int)($r['drug_count'] ?? 0);
        if ((int)($r['row_count'] ?? 0) >= $groups[$canon]['best_count']) {
            $groups[$canon]['best_count'] = (int)($r['row_count'] ?? 0);
            $groups[$canon]['display_name'] = $r['hosname'];
        }
    }
    foreach ($groups as &$g) {
        $g['aliases'] = array_values(array_unique($g['aliases']));
        usort($g['aliases'], function ($a, $b) {
            return strcmp(nd_hospital_sort_key($b), nd_hospital_sort_key($a));
        });
        if (!empty($g['aliases'])) {
            $g['display_name'] = $g['aliases'][0];
        }
    }
    unset($g);
    $list = array_values($groups);
    usort($list, function ($a, $b) {
        return strcmp(nd_hospital_sort_key($a['display_name']), nd_hospital_sort_key($b['display_name']));
    });
    return $list;
}

function ndr_resolve_hospital_aliases($con, $selected_hos) {
    nd_ensure_tables($con);
    $all = [];
    $q = mysqli_query($con, "SELECT DISTINCT hosname FROM network_drug_items WHERE TRIM(hosname) <> ''");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $all[] = $r['hosname'];
        }
    }
    $by_canon = [];
    foreach ($all as $name) {
        $c = ndr_hosname_canonical($name);
        if ($c === '') {
            continue;
        }
        if (!isset($by_canon[$c])) {
            $by_canon[$c] = [];
        }
        $by_canon[$c][] = $name;
    }
    $resolved = [];
    foreach ($selected_hos as $hos) {
        $hos = trim((string)$hos);
        if ($hos === '') {
            continue;
        }
        $canon = ndr_hosname_canonical($hos);
        $names = $by_canon[$canon] ?? [$hos];
        if (!in_array($hos, $names, true)) {
            $names[] = $hos;
        }
        $resolved[$hos] = array_values(array_unique($names));
    }
    return $resolved;
}

function ndr_strip_strength($name) {
    $name = ndr_normalize_key($name);
    $name = preg_replace('/\d+(?:\.\d+)?\s*(?:mg|mcg|g|ml|iu|%|unit|units|tab|cap|เม็ด|ขวด|แผง)(?:\/\d+(?:\.\d+)?\s*(?:mg|mcg|g|ml))?/iu', ' ', $name);
    $name = preg_replace('/\s+/u', ' ', trim($name));
    return $name;
}

function ndr_extract_strength_values($text) {
    $text = ndr_normalize_key($text);
    $vals = [];
    if (preg_match_all('/(\d+(?:\.\d+)?)\s*(mg|mcg|g|ml)/iu', $text, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $num = (float)$hit[1];
            $unit = strtolower($hit[2]);
            if ($unit === 'g') {
                $num *= 1000;
                $unit = 'mg';
            } elseif ($unit === 'mcg') {
                $num /= 1000;
                $unit = 'mg';
            }
            $vals[] = round($num, 4) . $unit;
        }
    }
    return array_values(array_unique($vals));
}

function ndr_extract_ingredient_tokens($name) {
    $base = ndr_strip_strength($name);
    $base = str_replace([' + ', '+', '/', ',', '(', ')', '[', ']'], ' ', $base);
    $parts = preg_split('/\s+/u', $base, -1, PREG_SPLIT_NO_EMPTY);
    $stop = ['hydrochloride', 'sodium', 'potassium', 'tablet', 'capsule', 'syrup', 'injection', 'tab', 'cap', 'hcl', 'hct'];
    $tokens = [];
    foreach ($parts as $p) {
        if (strlen($p) < 3) {
            continue;
        }
        if (in_array($p, $stop, true)) {
            continue;
        }
        if (preg_match('/^\d+$/', $p)) {
            continue;
        }
        $tokens[] = $p;
    }
    return array_values(array_unique($tokens));
}

function ndr_similarity_names_plausible($patient_name, $network_name, array $p_tokens, array $n_tokens, $score) {
    $score = (int)$score;
    if ($score < 55) {
        return $score;
    }
    $patient_name = ndr_normalize_key($patient_name);
    $network_name = ndr_normalize_key($network_name);
    if ($patient_name === $network_name) {
        return $score;
    }
    if (!empty(array_intersect($p_tokens, $n_tokens))) {
        return $score;
    }
    similar_text($patient_name, $network_name, $pct);
    if ($pct >= 78) {
        return $score;
    }
    if (strlen($patient_name) >= 6 && strlen($network_name) >= 6) {
        if (strpos($network_name, $patient_name) !== false || strpos($patient_name, $network_name) !== false) {
            return $score;
        }
    }
    return min($score, 54);
}

function ndr_similarity_score_names($patient_name, $network_name, array $patient_drug, array $network_drug) {
    $patient_name = ndr_normalize_key($patient_name);
    $network_name = ndr_normalize_key($network_name);
    if ($patient_name === '' || $network_name === '') {
        return 0;
    }

    $score = 0;

    if ($patient_name === $network_name) {
        return 100;
    }
    if (strpos($network_name, $patient_name) !== false || strpos($patient_name, $network_name) !== false) {
        $score = max($score, 92);
    }

    $p_strength = ndr_extract_strength_values($patient_name . ' ' . ($patient_drug['strength'] ?? ''));
    $n_strength = ndr_extract_strength_values($network_name . ' ' . ($network_drug['strength'] ?? ''));
    $strength_match = !empty($p_strength) && !empty($n_strength) && count(array_intersect($p_strength, $n_strength)) > 0;

    $p_tokens = ndr_extract_ingredient_tokens($patient_name);
    $n_tokens = ndr_extract_ingredient_tokens($network_name);
    if (!empty($p_tokens) && !empty($n_tokens)) {
        $common = array_intersect($p_tokens, $n_tokens);
        $union = array_unique(array_merge($p_tokens, $n_tokens));
        $token_score = (count($common) / max(1, count($union))) * 100;
        if (count($common) > 0) {
            $score = max($score, $token_score);
            if (count($common) >= min(count($p_tokens), count($n_tokens))) {
                $score = max($score, 80);
            }
        }
        if (!empty($common) && $strength_match) {
            $score = max($score, 88);
        }
    }

    similar_text($patient_name, $network_name, $pct);
    if ($pct >= 70) {
        $score = max($score, $pct);
    }

    $score = ndr_similarity_names_plausible($patient_name, $network_name, $p_tokens, $n_tokens, $score);

    return (int)round(min(99, $score));
}

function ndr_similarity_score(array $patient_drug, array $network_drug) {
    $patient_names = ndr_drug_name_candidates($patient_drug, ['tmtdrug', 'drug_name_tmt', 'drug_name']);
    $network_names = ndr_drug_name_candidates($network_drug, ['tmtdrug', 'name']);
    if (empty($patient_names) || empty($network_names)) {
        return 0;
    }

    $best = 0;
    foreach ($patient_names as $patient_name) {
        foreach ($network_names as $network_name) {
            $best = max($best, ndr_similarity_score_names($patient_name, $network_name, $patient_drug, $network_drug));
            if ($best >= 99) {
                return $best;
            }
        }
    }
    return $best;
}

function ndr_sql_drug_select() {
    $method = di_sql_method_expr('o', 'du', 'sp', 'od');
    $tmt = hosxp_sql_tmt_drug_name('d', 'tm');
    $strength = hosxp_sql_tmt_strength('d', 'tm');
    return "
        o.icode,
        d.tpu_code_list,
        CAST(CONVERT(d.name USING utf8) AS CHAR) AS drug_name_local,
        $tmt AS drug_name_tmt,
        CAST(CONVERT($strength USING utf8) AS CHAR) AS strength,
        CAST(CONVERT(d.units USING utf8) AS CHAR) AS units,
        o.qty,
        $method AS method,
        o.vstdate AS source_date,
        o.item_type,
        ion.order_type
    ";
}

function ndr_sql_drug_joins() {
    return "
        INNER JOIN drugitems d ON d.icode = o.icode
        " . hosxp_sql_tmt_join('d', 'tm') . "
        " . di_sql_method_joins('o', 'du', 'sp', 'od') . "
        LEFT JOIN ipt_order_no ion ON ion.order_no = o.order_no
    ";
}

function ndr_is_hme_row(array $row) {
    $order_type = trim($row['order_type'] ?? '');
    $item_type = trim($row['item_type'] ?? '');
    return (strcasecmp($order_type, 'Hme') === 0) || ($item_type === 'H');
}

function ndr_map_drug_row(array $row) {
    $name_tmt = trim($row['drug_name_tmt'] ?? '');
    $name_local = trim($row['drug_name_local'] ?? '');
    $display = $name_tmt !== '' ? $name_tmt : $name_local;
    $strength = trim($row['strength'] ?? '');
    if ($display === '' && $name_local !== '') {
        $display = trim($name_local . ' ' . $strength);
    }
    $mapped = [
        'icode' => trim($row['icode'] ?? ''),
        'tpu_code_list' => trim($row['tpu_code_list'] ?? ''),
        'drug_name' => $name_local,
        'drug_name_tmt' => $name_tmt,
        'tmtdrug' => $display,
        'strength' => $strength,
        'units' => trim($row['units'] ?? ''),
        'qty' => (float)($row['qty'] ?? 0),
        'method' => trim(preg_replace('/\s+/u', ' ', (string)($row['method'] ?? ''))),
        'is_hme' => ndr_is_hme_row($row),
    ];
    ndr_drug_apply_display_fields($mapped, 'drug_name');
    return $mapped;
}

function ndr_dedupe_drugs(array $rows) {
    $map = [];
    foreach ($rows as $r) {
        $icode = $r['icode'] ?? '';
        $key = $icode !== '' ? 'ic:' . $icode : 'nm:' . ndr_normalize_key($r['tmtdrug'] ?? '');
        if (!isset($map[$key])) {
            $map[$key] = $r;
        }
    }
    return array_values($map);
}

function ndr_sql_thaiaddress_names($prefix = 'p') {
    return "
        (SELECT CAST(CONVERT(t.name USING utf8) AS CHAR)
         FROM thaiaddress t
         WHERE t.chwpart = $prefix.chwpart
           AND t.amppart = $prefix.amppart
           AND t.tmbpart = $prefix.tmbpart
           AND t.codetype = '3'
         LIMIT 1) AS tmb_name,
        (SELECT CAST(CONVERT(t.name USING utf8) AS CHAR)
         FROM thaiaddress t
         WHERE t.chwpart = $prefix.chwpart
           AND t.amppart = $prefix.amppart
           AND t.tmbpart = '00'
           AND t.codetype = '2'
         LIMIT 1) AS amp_name,
        (SELECT CAST(CONVERT(t.name USING utf8) AS CHAR)
         FROM thaiaddress t
         WHERE t.chwpart = $prefix.chwpart
           AND t.amppart = '00'
           AND t.tmbpart = '00'
           AND t.codetype = '1'
         LIMIT 1) AS chw_name
    ";
}

function ndr_format_patient_address(array $p) {
    $parts = [];
    $addr = trim($p['addrpart'] ?? '');
    if ($addr !== '') {
        $parts[] = $addr;
    }
    $moo = trim($p['moopart'] ?? '');
    if ($moo !== '' && $moo !== '0') {
        $parts[] = 'หมู่ ' . $moo;
    }
    foreach (['tmb_name', 'amp_name', 'chw_name'] as $key) {
        $name = trim($p[$key] ?? '');
        if ($name !== '') {
            $parts[] = $name;
        }
    }
    return trim(implode(' ', $parts));
}

function ndr_fetch_latest_pttype($con_hosxp, $hn_sql) {
    $sql = "
        SELECT CAST(CONVERT(IFNULL(pt.name, '') USING utf8) AS CHAR) AS pttype_name,
               v.pttype
        FROM (
            SELECT ov.pttype, ov.vstdate AS visit_date, ov.vsttime AS visit_time
            FROM ovst ov
            WHERE ov.hn IN ($hn_sql)
            UNION ALL
            SELECT i.pttype, i.regdate AS visit_date, i.regtime AS visit_time
            FROM ipt i
            WHERE i.hn IN ($hn_sql)
        ) v
        LEFT JOIN pttype pt ON pt.pttype = v.pttype
        ORDER BY v.visit_date DESC, v.visit_time DESC
        LIMIT 1
    ";
    $q = mysqli_query($con_hosxp, $sql);
    if (!$q || !($row = mysqli_fetch_assoc($q))) {
        return ['pttype' => '', 'pttype_name' => ''];
    }
    return [
        'pttype' => trim($row['pttype'] ?? ''),
        'pttype_name' => trim($row['pttype_name'] ?? ''),
    ];
}

function ndr_fetch_visit_pttype($con_hosxp, $vn, $an) {
    $vn = trim((string)$vn);
    $an = trim((string)$an);
    if ($an !== '') {
        $an_esc = mysqli_real_escape_string($con_hosxp, $an);
        $sql = "
            SELECT i.pttype,
                   CAST(CONVERT(IFNULL(pt.name, '') USING utf8) AS CHAR) AS pttype_name
            FROM ipt i
            LEFT JOIN pttype pt ON pt.pttype = i.pttype
            WHERE i.an = '$an_esc'
            LIMIT 1
        ";
    } elseif ($vn !== '') {
        $vn_esc = mysqli_real_escape_string($con_hosxp, $vn);
        $sql = "
            SELECT ov.pttype,
                   CAST(CONVERT(IFNULL(pt.name, '') USING utf8) AS CHAR) AS pttype_name
            FROM ovst ov
            LEFT JOIN pttype pt ON pt.pttype = ov.pttype
            WHERE ov.vn = '$vn_esc'
            LIMIT 1
        ";
    } else {
        return ['pttype' => '', 'pttype_name' => ''];
    }
    $q = mysqli_query($con_hosxp, $sql);
    if (!$q || !($row = mysqli_fetch_assoc($q))) {
        return ['pttype' => '', 'pttype_name' => ''];
    }
    return [
        'pttype' => trim($row['pttype'] ?? ''),
        'pttype_name' => trim($row['pttype_name'] ?? ''),
    ];
}

function ndr_enrich_visits_pttype($con_hosxp, array &$visits_map) {
    $vn_keys = [];
    $an_keys = [];
    foreach ($visits_map as $vk => $v) {
        if (($v['visit_type'] ?? '') === 'ipd' && !empty($v['an'])) {
            $an_keys[$v['an']] = $vk;
        } elseif (!empty($v['vn'])) {
            $vn_keys[$v['vn']] = $vk;
        }
        $visits_map[$vk]['pttype'] = '';
        $visits_map[$vk]['pttype_name'] = '';
    }

    if (!empty($vn_keys)) {
        $in = [];
        foreach (array_keys($vn_keys) as $vn) {
            $in[] = "'" . mysqli_real_escape_string($con_hosxp, $vn) . "'";
        }
        $q = mysqli_query($con_hosxp, "
            SELECT ov.vn, ov.pttype,
                   CAST(CONVERT(IFNULL(pt.name, '') USING utf8) AS CHAR) AS pttype_name
            FROM ovst ov
            LEFT JOIN pttype pt ON pt.pttype = ov.pttype
            WHERE ov.vn IN (" . implode(',', $in) . ")
        ");
        if ($q) {
            while ($row = mysqli_fetch_assoc($q)) {
                $vn = trim($row['vn'] ?? '');
                if ($vn !== '' && isset($vn_keys[$vn])) {
                    $vk = $vn_keys[$vn];
                    $visits_map[$vk]['pttype'] = trim($row['pttype'] ?? '');
                    $visits_map[$vk]['pttype_name'] = trim($row['pttype_name'] ?? '');
                }
            }
        }
    }

    if (!empty($an_keys)) {
        $in = [];
        foreach (array_keys($an_keys) as $an) {
            $in[] = "'" . mysqli_real_escape_string($con_hosxp, $an) . "'";
        }
        $q = mysqli_query($con_hosxp, "
            SELECT i.an, i.pttype,
                   CAST(CONVERT(IFNULL(pt.name, '') USING utf8) AS CHAR) AS pttype_name
            FROM ipt i
            LEFT JOIN pttype pt ON pt.pttype = i.pttype
            WHERE i.an IN (" . implode(',', $in) . ")
        ");
        if ($q) {
            while ($row = mysqli_fetch_assoc($q)) {
                $an = trim($row['an'] ?? '');
                if ($an !== '' && isset($an_keys[$an])) {
                    $vk = $an_keys[$an];
                    $visits_map[$vk]['pttype'] = trim($row['pttype'] ?? '');
                    $visits_map[$vk]['pttype_name'] = trim($row['pttype_name'] ?? '');
                }
            }
        }
    }
}

function ndr_fetch_patient($con_hosxp, $hn) {
    $hn_sql = ndr_hn_sql_in($con_hosxp, $hn);
    if ($hn_sql === '') {
        return null;
    }
    $addr_sql = ndr_sql_thaiaddress_names('p');
    $sql = "
        SELECT p.hn,
               CAST(CONVERT(p.pname USING utf8) AS CHAR) AS pname,
               CAST(CONVERT(p.fname USING utf8) AS CHAR) AS fname,
               CAST(CONVERT(p.lname USING utf8) AS CHAR) AS lname,
               CAST(CONVERT(IFNULL(p.addrpart, '') USING utf8) AS CHAR) AS addrpart,
               CAST(CONVERT(IFNULL(p.moopart, '') USING utf8) AS CHAR) AS moopart,
               $addr_sql
        FROM patient p
        WHERE p.hn IN ($hn_sql)
        LIMIT 1
    ";
    $q = mysqli_query($con_hosxp, $sql);
    if (!$q || !($p = mysqli_fetch_assoc($q))) {
        return null;
    }
    $rights = ndr_fetch_latest_pttype($con_hosxp, $hn_sql);
    return [
        'hn' => $p['hn'],
        'full_name' => trim(($p['pname'] ?? '') . ($p['fname'] ?? '') . ' ' . ($p['lname'] ?? '')),
        'address' => ndr_format_patient_address($p),
        'tmb_name' => trim($p['tmb_name'] ?? ''),
        'amp_name' => trim($p['amp_name'] ?? ''),
        'pttype' => $rights['pttype'],
        'pttype_name' => $rights['pttype_name'],
    ];
}

function ndr_fetch_visits($con_hosxp, $hn, $months = 36) {
    $hn_sql = ndr_hn_sql_in($con_hosxp, $hn);
    if ($hn_sql === '') {
        return [];
    }
    $months = max(0, min(120, (int)$months));
    $date_filter = $months > 0 ? " AND o.vstdate >= DATE_SUB(CURDATE(), INTERVAL $months MONTH) " : '';

    $sql = "
        SELECT
            o.vn,
            o.an,
            MAX(o.vstdate) AS vstdate,
            MAX(o.vsttime) AS vsttime,
            COUNT(*) AS drug_count,
            SUM(CASE WHEN LOWER(TRIM(IFNULL(ion.order_type,''))) = 'hme' OR TRIM(IFNULL(o.item_type,'')) = 'H' THEN 1 ELSE 0 END) AS hme_count
        FROM opitemrece o
        INNER JOIN drugitems d ON d.icode = o.icode
        LEFT JOIN ipt_order_no ion ON ion.order_no = o.order_no
        WHERE o.hn IN ($hn_sql)
          $date_filter
          AND (
              (o.vn IS NOT NULL AND TRIM(o.vn) <> '')
              OR (o.an IS NOT NULL AND TRIM(o.an) <> '')
          )
        GROUP BY
            CASE WHEN TRIM(IFNULL(o.an,'')) <> '' THEN CONCAT('ipd:', o.an) ELSE CONCAT('opd:', o.vn) END,
            o.vn,
            o.an
        ORDER BY vstdate DESC, vsttime DESC
        LIMIT 200
    ";
    $q = mysqli_query($con_hosxp, $sql);
    if (!$q) {
        return [];
    }

    $visits_map = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $vn = trim($row['vn'] ?? '');
        $an = trim($row['an'] ?? '');
        if ($an !== '') {
            $visit_key = 'ipd:' . $an;
            $visit_type = 'ipd';
        } elseif ($vn !== '') {
            $visit_key = 'opd:' . $vn;
            $visit_type = 'opd';
        } else {
            continue;
        }
        if (!isset($visits_map[$visit_key])) {
            $ts = strtotime($row['vstdate'] ?? '');
            $be = $ts ? ((int)date('Y', $ts) + 543) : '';
            $visits_map[$visit_key] = [
                'visit_key' => $visit_key,
                'visit_type' => $visit_type,
                'vn' => $vn,
                'an' => $an,
                'vstdate' => $row['vstdate'] ?? '',
                'vsttime' => $row['vsttime'] ?? '',
                'vstdate_display' => $ts ? (date('d/m/', $ts) . $be) : '',
                'drug_count' => 0,
                'hme_count' => 0,
                'ward_name' => '',
                'pttype' => '',
                'pttype_name' => '',
            ];
        }
        $visits_map[$visit_key]['drug_count'] += (int)($row['drug_count'] ?? 0);
        $visits_map[$visit_key]['hme_count'] += (int)($row['hme_count'] ?? 0);
    }

    $ipd_ans = [];
    foreach ($visits_map as $vk => $v) {
        if (($v['visit_type'] ?? '') === 'ipd' && !empty($v['an'])) {
            $ipd_ans[$v['an']] = $vk;
        }
    }
    if (!empty($ipd_ans)) {
        $an_in = [];
        foreach (array_keys($ipd_ans) as $a) {
            $an_in[] = "'" . mysqli_real_escape_string($con_hosxp, $a) . "'";
        }
        $qi = mysqli_query($con_hosxp, "
            SELECT i.an,
                   CAST(CONVERT(IFNULL(w.name,'') USING utf8) AS CHAR) AS ward_name
            FROM ipt i
            LEFT JOIN iptadm ia ON ia.an = i.an
            LEFT JOIN ward w ON w.ward = ia.ward
            WHERE i.an IN (" . implode(',', $an_in) . ")
        ");
        if ($qi) {
            while ($ir = mysqli_fetch_assoc($qi)) {
                $a = trim($ir['an'] ?? '');
                if ($a !== '' && isset($ipd_ans[$a])) {
                    $visits_map[$ipd_ans[$a]]['ward_name'] = trim($ir['ward_name'] ?? '');
                }
            }
        }
    }

    ndr_enrich_visits_pttype($con_hosxp, $visits_map);

    $visits = array_values($visits_map);
    usort($visits, function ($a, $b) {
        $da = ($a['vstdate'] ?? '') . ' ' . ($a['vsttime'] ?? '');
        $db = ($b['vstdate'] ?? '') . ' ' . ($b['vsttime'] ?? '');
        return strcmp($db, $da);
    });
    return $visits;
}

function ndr_fetch_visit_drugs($con_hosxp, $vn, $an, $visit_type, $options = []) {
    $vn = trim((string)$vn);
    $an = trim((string)$an);
    $visit_type = strtolower(trim((string)$visit_type));
    $hme_only = !empty($options['hme_only']);
    $order_no = trim((string)($options['order_no'] ?? ''));
    $rxdate = trim((string)($options['rxdate'] ?? ''));
    $rxtime = trim((string)($options['rxtime'] ?? ''));
    $sel = ndr_sql_drug_select();
    $joins = ndr_sql_drug_joins();
    $hme_filter = "
        AND (
            LOWER(TRIM(IFNULL(ion.order_type,''))) = 'hme'
            OR TRIM(IFNULL(o.item_type,'')) = 'H'
            OR EXISTS (
                SELECT 1 FROM opitemrece_home oh
                WHERE oh.opi_guid = o.hos_guid
                  AND oh.opitemrece_home_type_id = 2
            )
        )
    ";
    $apply_hme = $hme_only || $an !== '' || $visit_type === 'ipd';
    $order_filter = '';
    if ($order_no !== '') {
        $order_filter = " AND IFNULL(o.order_no, '') = '" . mysqli_real_escape_string($con_hosxp, $order_no) . "'";
    } elseif ($rxdate !== '') {
        $date_esc = mysqli_real_escape_string($con_hosxp, $rxdate);
        $order_filter = " AND COALESCE(NULLIF(o.rxdate, '0000-00-00'), o.vstdate) = '$date_esc'";
        if ($rxtime !== '') {
            $time_esc = mysqli_real_escape_string($con_hosxp, $rxtime);
            $order_filter .= " AND IFNULL(NULLIF(TRIM(o.rxtime), ''), IFNULL(NULLIF(TRIM(o.vsttime), ''), '00:00:00')) = '$time_esc'";
        }
    }

    if ($an !== '' || $visit_type === 'ipd') {
        if ($an === '') {
            return [];
        }
        $an_esc = mysqli_real_escape_string($con_hosxp, $an);
        $sql = "
            SELECT $sel
            FROM opitemrece o
            $joins
            WHERE o.an = '$an_esc'
            " . ($apply_hme ? $hme_filter : '') . "
            $order_filter
            ORDER BY o.item_no ASC
        ";
    } elseif ($vn !== '') {
        $vn_esc = mysqli_real_escape_string($con_hosxp, $vn);
        $sql = "
            SELECT $sel
            FROM opitemrece o
            $joins
            WHERE o.vn = '$vn_esc'
            " . ($apply_hme ? $hme_filter : '') . "
            ORDER BY o.item_no ASC
        ";
    } else {
        return [];
    }

    $rows = [];
    $q = mysqli_query($con_hosxp, $sql);
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $rows[] = ndr_map_drug_row($r);
        }
    }
    return ndr_dedupe_drugs($rows);
}

function ndr_build_hospital_drug_index($con, array $hospitals) {
    nd_ensure_tables($con);
    $alias_map = ndr_resolve_hospital_aliases($con, $hospitals);
    $index = [];

    foreach ($hospitals as $hos) {
        $hos = trim((string)$hos);
        if ($hos === '') {
            continue;
        }
        $index[$hos] = [
            'tmtdrug' => [],
            'tpu' => [],
            'drugs' => [],
            'display_by_key' => [],
        ];
        $aliases = $alias_map[$hos] ?? [$hos];
        $in_parts = [];
        foreach ($aliases as $alias) {
            $in_parts[] = "'" . mysqli_real_escape_string($con, $alias) . "'";
        }
        if (empty($in_parts)) {
            continue;
        }
        $in_sql = implode(',', $in_parts);
        $q = mysqli_query(
            $con,
            "SELECT tmtdrug, tpu_code_list, name, strength FROM network_drug_items WHERE hosname IN ($in_sql) AND TRIM(tmtdrug) <> ''"
        );
        if ($q) {
            while ($r = mysqli_fetch_assoc($q)) {
                $r = ndr_network_drug_normalize_row($r);
                $key = ndr_normalize_key($r['tmtdrug'] ?? '');
                if ($key !== '') {
                    $index[$hos]['tmtdrug'][$key] = $r['tmtdrug'];
                    $index[$hos]['display_by_key'][$key] = $r['display_name'] ?? $r['tmtdrug'];
                }
                $name_key = ndr_normalize_key($r['name'] ?? '');
                if ($name_key !== '' && $name_key !== $key) {
                    $index[$hos]['tmtdrug'][$name_key] = $r['tmtdrug'];
                    $index[$hos]['display_by_key'][$name_key] = $r['display_name'] ?? $r['tmtdrug'];
                }
                $tpu = trim($r['tpu_code_list'] ?? '');
                if ($tpu !== '') {
                    $index[$hos]['tpu'][$tpu] = $r['tmtdrug'];
                }
                $index[$hos]['drugs'][] = $r;
            }
        }
    }
    return $index;
}

function ndr_drug_match_keys(array $drug) {
    $keys_tmt = [];
    $keys_tpu = [];
    $tpu = trim($drug['tpu_code_list'] ?? '');
    if ($tpu !== '') {
        $keys_tpu[] = $tpu;
    }
    foreach (['tmtdrug', 'drug_name_tmt', 'drug_name', 'name'] as $field) {
        $v = ndr_normalize_key($drug[$field] ?? '');
        if ($v !== '') {
            $keys_tmt[] = $v;
        }
    }
    $strength = ndr_normalize_key($drug['strength'] ?? '');
    if ($strength !== '' && !empty($keys_tmt)) {
        $base = $keys_tmt[0];
        $keys_tmt[] = ndr_normalize_key($base . ' ' . $strength);
    }
    return [
        'tmtdrug' => array_values(array_unique($keys_tmt)),
        'tpu' => array_values(array_unique($keys_tpu)),
    ];
}

function ndr_match_drug_in_hospital(array $match_keys, array $hos_index) {
    foreach ($match_keys['tpu'] as $tpu) {
        if (isset($hos_index['tpu'][$tpu])) {
            return [
                'status' => 'exact',
                'score' => 100,
                'matched_tmtdrug' => $hos_index['tpu'][$tpu],
                'suggestions' => [],
            ];
        }
    }
    foreach ($match_keys['tmtdrug'] as $tk) {
        if ($tk !== '' && isset($hos_index['tmtdrug'][$tk])) {
            return [
                'status' => 'exact',
                'score' => 100,
                'matched_tmtdrug' => $hos_index['tmtdrug'][$tk],
                'suggestions' => [],
            ];
        }
    }
    foreach ($match_keys['tmtdrug'] as $pk) {
        if ($pk === '') {
            continue;
        }
        foreach ($hos_index['tmtdrug'] as $nk => $orig) {
            if ($pk === $nk) {
                return [
                    'status' => 'exact',
                    'score' => 100,
                    'matched_tmtdrug' => $orig,
                    'suggestions' => [],
                ];
            }
            if (strlen($pk) >= 6 && (strpos($nk, $pk) !== false || strpos($pk, $nk) !== false)) {
                return [
                    'status' => 'exact',
                    'score' => 95,
                    'matched_tmtdrug' => $orig,
                    'suggestions' => [],
                ];
            }
        }
    }
    return null;
}

function ndr_find_similar_drugs(array $patient_drug, array $hos_index, $limit = 3, $min_score = 55) {
    $scored = [];
    $seen = [];
    foreach ($hos_index['drugs'] ?? [] as $nd) {
        $name = trim($nd['tmtdrug'] ?? '');
        if ($name === '' || isset($seen[$name])) {
            continue;
        }
        $seen[$name] = true;
        $score = ndr_similarity_score($patient_drug, $nd);
        if ($score >= $min_score) {
            $scored[] = [
                'tmtdrug' => $name,
                'display_name' => $nd['display_name'] ?? $name,
                'display_primary' => $nd['display_primary'] ?? $name,
                'display_secondary' => $nd['display_secondary'] ?? '',
                'score' => $score,
                'tpu_code_list' => trim($nd['tpu_code_list'] ?? ''),
            ];
        }
    }
    usort($scored, function ($a, $b) {
        if ($b['score'] !== $a['score']) {
            return $b['score'] - $a['score'];
        }
        return strcmp($a['tmtdrug'], $b['tmtdrug']);
    });
    return array_slice($scored, 0, max(1, (int)$limit));
}

function ndr_match_drug_for_hospital(array $patient_drug, array $hos_index, $min_similar_score = 75) {
    $match_keys = ndr_drug_match_keys($patient_drug);
    $exact = ndr_match_drug_in_hospital($match_keys, $hos_index);
    if ($exact) {
        ndr_enrich_match_display($exact, $hos_index);
        return $exact;
    }
    $suggestions = ndr_find_similar_drugs($patient_drug, $hos_index, 3, 55);
    $min_similar_score = max(0, min(100, (int)$min_similar_score));
    if (!empty($suggestions) && $suggestions[0]['score'] >= $min_similar_score) {
        $match = [
            'status' => 'similar',
            'score' => $suggestions[0]['score'],
            'matched_tmtdrug' => $suggestions[0]['tmtdrug'],
            'matched_display' => $suggestions[0]['display_name'] ?? $suggestions[0]['tmtdrug'],
            'matched_display_primary' => $suggestions[0]['display_primary'] ?? '',
            'matched_display_secondary' => $suggestions[0]['display_secondary'] ?? '',
            'suggestions' => $suggestions,
        ];
        ndr_enrich_match_display($match, $hos_index);
        return $match;
    }
    return [
        'status' => 'none',
        'score' => 0,
        'matched_tmtdrug' => '',
        'matched_display' => '',
        'suggestions' => $suggestions,
    ];
}

function ndr_compare_drugs($con, array $drugs, array $hospitals) {
    $hospitals = array_values(array_filter(array_map('trim', $hospitals)));
    if (empty($hospitals)) {
        return ['status' => 'error', 'message' => 'กรุณาเลือกโรงพยาบาลปลายทาง'];
    }
    if (empty($drugs)) {
        return ['status' => 'error', 'message' => 'ไม่พบรายการยาสำหรับ Visit ที่เลือก'];
    }

    $index = ndr_build_hospital_drug_index($con, $hospitals);
    $rows = [];
    $summary = [
        'total' => 0,
        'exact_all' => 0,
        'similar_some' => 0,
        'missing_any' => 0,
    ];

    foreach ($drugs as $drug) {
        $hos_map = [];
        $all_exact = true;
        $any_similar = false;
        $any_missing = false;
        $row_suggestions = [];

        foreach ($hospitals as $hos) {
            $result = isset($index[$hos])
                ? ndr_match_drug_for_hospital($drug, $index[$hos])
                : ['status' => 'none', 'score' => 0, 'matched_tmtdrug' => '', 'suggestions' => []];
            $hos_map[$hos] = $result;

            if (($result['status'] ?? '') === 'exact') {
                // ok
            } elseif (($result['status'] ?? '') === 'similar') {
                $all_exact = false;
                $any_similar = true;
            } else {
                $all_exact = false;
                $any_missing = true;
            }
            if (!empty($result['suggestions'])) {
                $row_suggestions[$hos] = $result['suggestions'];
            }
        }

        $summary['total']++;
        if ($all_exact) {
            $summary['exact_all']++;
        } elseif ($any_similar) {
            $summary['similar_some']++;
        }
        if ($any_missing) {
            $summary['missing_any']++;
        }

        $rows[] = array_merge($drug, [
            'hospitals' => $hos_map,
            'suggestions' => $row_suggestions,
        ]);
    }

    return [
        'status' => 'success',
        'hospitals' => $hospitals,
        'rows' => $rows,
        'summary' => $summary,
    ];
}

/** จับคู่อำเภอกับชื่อ รพ. ในบัญชียาเครือข่าย เช่น วังน้ำเย็น → รพ.วังน้ำเย็น */
function ndr_resolve_hospital_for_amp_name($con, $amp_name) {
    $amp_name = trim((string)$amp_name);
    if ($amp_name === '') {
        return '';
    }
    nd_ensure_tables($con);
    $hospitals = nd_list_hospitals($con, 0);
    if (empty($hospitals)) {
        return '';
    }
    $amp_canon = ndr_hosname_canonical($amp_name);

    foreach ($hospitals as $hos) {
        if (ndr_hosname_canonical($hos) === $amp_canon) {
            return $hos;
        }
    }
    $best = '';
    $best_len = 0;
    foreach ($hospitals as $hos) {
        $hos_canon = ndr_hosname_canonical($hos);
        if ($hos_canon === '' || $amp_canon === '') {
            continue;
        }
        if (strpos($amp_canon, $hos_canon) !== false || strpos($hos_canon, $amp_canon) !== false) {
            $len = strlen($hos_canon);
            if ($len > $best_len) {
                $best = $hos;
                $best_len = $len;
            }
        }
    }
    return $best;
}

/** ชื่อ รพ. สำหรับ checkbox ใน UI (display_name จากบัญชีเครือข่าย) */
function ndr_resolve_hospital_display_for_amp_name($con, $amp_name) {
    $resolved = ndr_resolve_hospital_for_amp_name($con, $amp_name);
    if ($resolved === '') {
        return '';
    }
    $resolved_canon = ndr_hosname_canonical($resolved);
    foreach (ndr_list_hospitals_grouped($con) as $g) {
        $display = trim($g['display_name'] ?? '');
        if ($display !== '' && ndr_hosname_canonical($display) === $resolved_canon) {
            return $display;
        }
        foreach ($g['aliases'] ?? [] as $alias) {
            if (ndr_hosname_canonical($alias) === $resolved_canon) {
                return $display !== '' ? $display : $resolved;
            }
        }
    }
    return $resolved;
}

function ndr_fetch_patient_drugs_in_period($con_hosxp, $hn, $start_date, $end_date, $visit_type = 'all') {
    $batch = ndr_fetch_patients_drugs_batch($con_hosxp, [$hn], $start_date, $end_date, $visit_type);
    return $batch[trim((string)$hn)] ?? [];
}

function ndr_fetch_patients_drugs_batch($con_hosxp, array $hns, $start_date, $end_date, $visit_type = 'all') {
    $hn_lookup = [];
    $in_parts = [];
    foreach ($hns as $hn) {
        $hn = trim((string)$hn);
        if ($hn === '') {
            continue;
        }
        foreach (ndr_hn_variants($hn) as $v) {
            $esc = "'" . mysqli_real_escape_string($con_hosxp, $v) . "'";
            $in_parts[$esc] = true;
            $hn_lookup[$v] = $hn;
        }
    }
    if (empty($in_parts)) {
        return [];
    }

    $start_esc = mysqli_real_escape_string($con_hosxp, $start_date);
    $end_esc = mysqli_real_escape_string($con_hosxp, $end_date);
    $visit_filter = ndr_district_sql_visit_filter($visit_type, 'o');
    $sel = ndr_sql_drug_select();
    $joins = ndr_sql_drug_joins();
    $in_sql = implode(',', array_keys($in_parts));
    $sql = "
        SELECT o.hn, $sel
        FROM opitemrece o
        $joins
        WHERE o.hn IN ($in_sql)
          AND o.vstdate BETWEEN '$start_esc' AND '$end_esc'
          AND d.istatus = 'Y'
          $visit_filter
        ORDER BY o.vstdate DESC, o.rxdate DESC, o.rxtime DESC, o.vsttime DESC, o.item_no DESC
    ";
    $q = mysqli_query($con_hosxp, $sql);
    if (!$q) {
        return [];
    }

    $by_hn = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $raw_hn = trim($row['hn'] ?? '');
        $hn = $hn_lookup[$raw_hn] ?? $raw_hn;
        if ($hn === '') {
            continue;
        }
        if (!isset($by_hn[$hn])) {
            $by_hn[$hn] = [];
        }
        $mapped = ndr_map_drug_row($row);
        $icode = $mapped['icode'] ?? '';
        $key = $icode !== '' ? 'ic:' . $icode : 'nm:' . ndr_normalize_key($mapped['tmtdrug'] ?? '');
        if (!isset($by_hn[$hn][$key])) {
            $by_hn[$hn][$key] = $mapped;
        }
    }

    $out = [];
    foreach ($by_hn as $hn => $map) {
        $out[$hn] = array_values($map);
    }
    return $out;
}

function ndr_is_drug_matched_for_hospital(array $match, $min_similar_score = 90) {
    $status = $match['status'] ?? 'none';
    if ($status === 'exact') {
        return true;
    }
    if ($status === 'similar' && (int)($match['score'] ?? 0) >= (int)$min_similar_score) {
        return true;
    }
    return false;
}

function ndr_summarize_single_hospital_match(array $drugs, array $hos_index, $min_similar_score = 90) {
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
        $result = ndr_match_drug_for_hospital($drug, $hos_index, $min_similar_score);
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

function ndr_compare_drugs_single_hospital(array $drugs, array $hos_index, $hospital, $min_similar_score = 90) {
    $rows = [];
    foreach ($drugs as $drug) {
        $match = ndr_match_drug_for_hospital($drug, $hos_index, $min_similar_score);
        $rows[] = array_merge($drug, [
            'match' => $match,
            'is_matched' => ndr_is_drug_matched_for_hospital($match, $min_similar_score),
        ]);
    }
    $summary = ndr_summarize_single_hospital_match($drugs, $hos_index, $min_similar_score);
    return [
        'hospital' => $hospital,
        'rows' => $rows,
        'summary' => $summary,
        'min_similar_score' => (int)$min_similar_score,
    ];
}

function ndr_district_is_all_amppart($amppart) {
    return trim((string)$amppart) === 'all';
}

function ndr_district_sql_patient_amp_filter($con_hosxp, $amppart, $prefix = 'p') {
    if (ndr_district_is_all_amppart($amppart)) {
        return '';
    }
    $amp_esc = mysqli_real_escape_string($con_hosxp, trim((string)$amppart));
    return "AND $prefix.amppart = '$amp_esc'";
}

/** เทียบบัญชีตามอำเภอที่อยู่ของแต่ละผู้ป่วย (โหมดทุกอำเภอ) */
function ndr_enrich_patients_district_match_by_home_amp($con, $con_hosxp, array &$patients, $start_date, $end_date, $visit_type = 'all', $min_similar_score = 90) {
    if (empty($patients)) {
        return;
    }
    $by_amp = [];
    foreach ($patients as $idx => $p) {
        $amp = trim($p['amp_name'] ?? '');
        $by_amp[$amp][] = $idx;
    }
    $hns = [];
    foreach ($patients as $p) {
        $hn = trim($p['hn'] ?? '');
        if ($hn !== '') {
            $hns[] = $hn;
        }
    }
    $drugs_by_hn = ndr_fetch_patients_drugs_batch($con_hosxp, $hns, $start_date, $end_date, $visit_type);
    $hospital_cache = [];
    $index_cache = [];

    foreach ($by_amp as $amp_name => $indices) {
        if ($amp_name === '') {
            foreach ($indices as $idx) {
                $patients[$idx]['match_hospital'] = '';
                $patients[$idx]['match_total'] = 0;
                $patients[$idx]['match_matched'] = 0;
                $patients[$idx]['match_percent'] = null;
                $patients[$idx]['match_note'] = 'ไม่ระบุอำเภอ';
            }
            continue;
        }
        if (!array_key_exists($amp_name, $hospital_cache)) {
            $hospital_cache[$amp_name] = ndr_resolve_hospital_for_amp_name($con, $amp_name);
            $index_cache[$amp_name] = null;
            if ($hospital_cache[$amp_name] !== '') {
                $built = ndr_build_hospital_drug_index($con, [$hospital_cache[$amp_name]]);
                $index_cache[$amp_name] = $built[$hospital_cache[$amp_name]] ?? ['tmtdrug' => [], 'tpu' => [], 'drugs' => []];
            }
        }
        $hospital = $hospital_cache[$amp_name];
        $hos_index = $index_cache[$amp_name];
        foreach ($indices as $idx) {
            if ($hospital === '' || $hos_index === null) {
                $patients[$idx]['match_hospital'] = '';
                $patients[$idx]['match_total'] = 0;
                $patients[$idx]['match_matched'] = 0;
                $patients[$idx]['match_percent'] = null;
                $patients[$idx]['match_note'] = 'ไม่พบบัญชียา รพ.ในอำเภอนี้';
                continue;
            }
            $hn = trim($patients[$idx]['hn'] ?? '');
            $drugs = $drugs_by_hn[$hn] ?? [];
            $summary = ndr_summarize_single_hospital_match($drugs, $hos_index, $min_similar_score);
            $patients[$idx]['match_hospital'] = $hospital;
            $patients[$idx]['match_total'] = $summary['total'];
            $patients[$idx]['match_matched'] = $summary['matched'];
            $patients[$idx]['match_percent'] = $summary['match_percent'];
            $patients[$idx]['match_note'] = '';
        }
    }
}

function ndr_enrich_patients_district_match($con, $con_hosxp, array &$patients, $amp_name, $start_date, $end_date, $visit_type = 'all', $min_similar_score = 90) {
    if (empty($patients)) {
        return;
    }
    $hospital = ndr_resolve_hospital_for_amp_name($con, $amp_name);
    if ($hospital === '') {
        foreach ($patients as &$p) {
            $p['match_hospital'] = '';
            $p['match_total'] = 0;
            $p['match_matched'] = 0;
            $p['match_percent'] = null;
            $p['match_note'] = 'ไม่พบบัญชียา รพ.ในอำเภอนี้';
        }
        unset($p);
        return;
    }

    $index = ndr_build_hospital_drug_index($con, [$hospital]);
    $hos_index = $index[$hospital] ?? ['tmtdrug' => [], 'tpu' => [], 'drugs' => []];
    $hns = [];
    foreach ($patients as $p) {
        $hn = trim($p['hn'] ?? '');
        if ($hn !== '') {
            $hns[] = $hn;
        }
    }
    $drugs_by_hn = ndr_fetch_patients_drugs_batch($con_hosxp, $hns, $start_date, $end_date, $visit_type);

    foreach ($patients as &$p) {
        $hn = trim($p['hn'] ?? '');
        $drugs = $drugs_by_hn[$hn] ?? [];
        $summary = ndr_summarize_single_hospital_match($drugs, $hos_index, $min_similar_score);
        $p['match_hospital'] = $hospital;
        $p['match_total'] = $summary['total'];
        $p['match_matched'] = $summary['matched'];
        $p['match_percent'] = $summary['match_percent'];
        $p['match_note'] = '';
    }
    unset($p);
}

function ndr_fetch_district_patient_match_detail($con, $con_hosxp, $hn, $amp_name, $start_date, $end_date, $visit_type = 'all', $min_similar_score = 90) {
    $hn = trim((string)$hn);
    if ($hn === '') {
        return ['status' => 'error', 'message' => 'กรุณาระบุ HN'];
    }
    $hospital = ndr_resolve_hospital_for_amp_name($con, $amp_name);
    if ($hospital === '') {
        return ['status' => 'error', 'message' => 'ไม่พบบัญชียาเครือข่ายของ รพ.ในอำเภอ ' . $amp_name];
    }
    $patient = ndr_fetch_patient($con_hosxp, $hn);
    if (!$patient) {
        return ['status' => 'error', 'message' => 'ไม่พบผู้ป่วย HN นี้'];
    }
    $drugs = ndr_fetch_patient_drugs_in_period($con_hosxp, $hn, $start_date, $end_date, $visit_type);
    if (empty($drugs)) {
        return ['status' => 'error', 'message' => 'ไม่พบรายการยาในช่วงเวลาที่เลือก'];
    }
    $index = ndr_build_hospital_drug_index($con, [$hospital]);
    $hos_index = $index[$hospital] ?? ['tmtdrug' => [], 'tpu' => [], 'drugs' => []];
    $compare = ndr_compare_drugs_single_hospital($drugs, $hos_index, $hospital, $min_similar_score);
    return [
        'status' => 'success',
        'patient' => $patient,
        'hospital' => $hospital,
        'amp_name' => $amp_name,
        'period' => ['start_date' => $start_date, 'end_date' => $end_date],
        'visit_type' => $visit_type,
        'visit_type_label' => ndr_district_visit_type_label($visit_type),
        'min_similar_score' => (int)$min_similar_score,
        'rows' => $compare['rows'],
        'summary' => $compare['summary'],
    ];
}

function ndr_district_recommendation_drug_key(array $drug) {
    $icode = trim($drug['icode'] ?? '');
    if ($icode !== '') {
        return 'ic:' . $icode;
    }
    $name = ndr_normalize_key($drug['display_name'] ?? $drug['tmtdrug'] ?? $drug['drug_name'] ?? '');
    return 'nm:' . $name;
}

/** รายการยาที่ควรมีใน รพ. — จากความถี่ยาที่เทียบไม่ตรง (< min_similar_score) ของผู้ป่วยเทียบบัญชี < threshold_percent */
function ndr_fetch_district_drug_recommendations(
    $con,
    $con_hosxp,
    $chwpart,
    $amppart,
    $amp_name,
    $start_date,
    $end_date,
    $visit_type = 'all',
    array $pttype_codes = [],
    $min_similar_score = 90,
    $patient_threshold_percent = 90
) {
    $patient_threshold_percent = max(0, min(100, (int)$patient_threshold_percent));
    $min_similar_score = max(0, min(100, (int)$min_similar_score));

    $result = ndr_fetch_district_patients(
        $con_hosxp,
        $chwpart,
        $amppart,
        $start_date,
        $end_date,
        1,
        0,
        $visit_type,
        $pttype_codes,
        'last_order_desc'
    );
    ndr_enrich_patients_district_match(
        $con,
        $con_hosxp,
        $result['patients'],
        $amp_name,
        $start_date,
        $end_date,
        $visit_type,
        $min_similar_score
    );

    $hospital = ndr_resolve_hospital_for_amp_name($con, $amp_name);
    if ($hospital === '') {
        return [
            'status' => 'success',
            'hospital' => '',
            'amp_name' => $amp_name,
            'min_similar_score' => $min_similar_score,
            'patient_threshold_percent' => $patient_threshold_percent,
            'patients_total' => count($result['patients']),
            'patients_below_threshold' => 0,
            'items' => [],
            'note' => 'ไม่พบบัญชียา รพ.ในอำเภอนี้',
        ];
    }

    $index = ndr_build_hospital_drug_index($con, [$hospital]);
    $hos_index = $index[$hospital] ?? ['tmtdrug' => [], 'tpu' => [], 'drugs' => [], 'display_by_key' => []];

    $target_hns = [];
    foreach ($result['patients'] as $p) {
        $pct = $p['match_percent'] ?? null;
        if ($pct === null) {
            continue;
        }
        if ((int)$pct < $patient_threshold_percent) {
            $hn = trim($p['hn'] ?? '');
            if ($hn !== '') {
                $target_hns[] = $hn;
            }
        }
    }

    if (empty($target_hns)) {
        return [
            'status' => 'success',
            'hospital' => $hospital,
            'amp_name' => $amp_name,
            'min_similar_score' => $min_similar_score,
            'patient_threshold_percent' => $patient_threshold_percent,
            'patients_total' => count($result['patients']),
            'patients_below_threshold' => 0,
            'items' => [],
            'note' => '',
        ];
    }

    $drugs_by_hn = ndr_fetch_patients_drugs_batch($con_hosxp, $target_hns, $start_date, $end_date, $visit_type);
    $freq = [];

    foreach ($target_hns as $hn) {
        $drugs = $drugs_by_hn[$hn] ?? [];
        $seen_in_patient = [];
        foreach ($drugs as $drug) {
            $match = ndr_match_drug_for_hospital($drug, $hos_index, $min_similar_score);
            if (ndr_is_drug_matched_for_hospital($match, $min_similar_score)) {
                continue;
            }
            $key = ndr_district_recommendation_drug_key($drug);
            if (!isset($freq[$key])) {
                $freq[$key] = [
                    'drug_key' => $key,
                    'icode' => trim($drug['icode'] ?? ''),
                    'display_primary' => trim($drug['display_primary'] ?? ''),
                    'display_secondary' => trim($drug['display_secondary'] ?? ''),
                    'display_name' => trim($drug['display_name'] ?? ''),
                    'tmtdrug' => trim($drug['tmtdrug'] ?? ''),
                    'patient_count' => 0,
                    'item_count' => 0,
                    'best_match_score' => 0,
                ];
            }
            $freq[$key]['item_count']++;
            $freq[$key]['best_match_score'] = max(
                (int)$freq[$key]['best_match_score'],
                (int)($match['score'] ?? 0)
            );
            if (!isset($seen_in_patient[$key])) {
                $seen_in_patient[$key] = true;
                $freq[$key]['patient_count']++;
            }
        }
    }

    $items = array_values($freq);
    usort($items, function ($a, $b) {
        $cmp = ($b['patient_count'] ?? 0) <=> ($a['patient_count'] ?? 0);
        if ($cmp !== 0) {
            return $cmp;
        }
        $cmp = ($b['item_count'] ?? 0) <=> ($a['item_count'] ?? 0);
        if ($cmp !== 0) {
            return $cmp;
        }
        return strcmp($a['display_name'] ?? '', $b['display_name'] ?? '');
    });

    return [
        'status' => 'success',
        'hospital' => $hospital,
        'amp_name' => $amp_name,
        'min_similar_score' => $min_similar_score,
        'patient_threshold_percent' => $patient_threshold_percent,
        'patients_total' => count($result['patients']),
        'patients_below_threshold' => count($target_hns),
        'items' => $items,
        'note' => '',
    ];
}

function ndr_store_print_session(array $payload) {
    $_SESSION['drug_refer_print'] = $payload;
}

function ndr_load_print_session() {
    return $_SESSION['drug_refer_print'] ?? null;
}

function ndr_fetch_hospital_chwpart($con_hosxp) {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = '';
    $q = mysqli_query($con_hosxp, "
        SELECT CAST(CONVERT(IFNULL(hospitalname, '') USING utf8) AS CHAR) AS hospitalname
        FROM opdconfig
        LIMIT 1
    ");
    if (!$q || !($row = mysqli_fetch_assoc($q))) {
        return $cache;
    }
    $hname = trim($row['hospitalname'] ?? '');
    if ($hname === '') {
        return $cache;
    }
    $qp = mysqli_query($con_hosxp, "
        SELECT chwpart,
               CAST(CONVERT(name USING utf8) AS CHAR) AS name
        FROM thaiaddress
        WHERE codetype = '1' AND amppart = '00' AND tmbpart = '00'
        ORDER BY CHAR_LENGTH(name) DESC
    ");
    if ($qp) {
        while ($pr = mysqli_fetch_assoc($qp)) {
            $pname = trim($pr['name'] ?? '');
            if ($pname !== '' && strpos($hname, $pname) !== false) {
                $cache = trim($pr['chwpart'] ?? '');
                break;
            }
        }
    }
    return $cache;
}

function ndr_fetch_hospital_chw_name($con_hosxp, $chwpart) {
    $chwpart = trim((string)$chwpart);
    if ($chwpart === '') {
        return '';
    }
    $esc = mysqli_real_escape_string($con_hosxp, $chwpart);
    $q = mysqli_query($con_hosxp, "
        SELECT CAST(CONVERT(name USING utf8) AS CHAR) AS name
        FROM thaiaddress
        WHERE chwpart = '$esc' AND amppart = '00' AND tmbpart = '00' AND codetype = '1'
        LIMIT 1
    ");
    if (!$q || !($row = mysqli_fetch_assoc($q))) {
        return '';
    }
    return trim($row['name'] ?? '');
}

function ndr_district_resolve_period($preset, $start_date = '', $end_date = '') {
    $preset = trim((string)$preset);
    $end_date = trim((string)$end_date);
    $start_date = trim((string)$start_date);
    if ($end_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
        $end_date = date('Y-m-d');
    }

    switch ($preset) {
        case 'today':
            $start_date = $end_date;
            $label = 'วันนี้';
            break;
        case 'd3':
            $start_date = date('Y-m-d', strtotime($end_date . ' -2 days'));
            $label = '3 วัน';
            break;
        case 'd7':
            $start_date = date('Y-m-d', strtotime($end_date . ' -6 days'));
            $label = '7 วัน';
            break;
        case 'm1':
            $start_date = date('Y-m-d', strtotime($end_date . ' -1 month'));
            $label = '1 เดือน';
            break;
        case 'm3':
            $start_date = date('Y-m-d', strtotime($end_date . ' -3 months'));
            $label = '3 เดือน';
            break;
        default:
            if ($start_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
                $label = $start_date . ' ถึง ' . $end_date;
                $preset = 'custom';
            } else {
                $preset = 'today';
                $start_date = $end_date;
                $label = 'วันนี้';
            }
    }

    if (strtotime($start_date) > strtotime($end_date)) {
        $tmp = $start_date;
        $start_date = $end_date;
        $end_date = $tmp;
    }

    return [
        'preset' => $preset,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'label' => $label,
        'days' => (int)((strtotime($end_date) - strtotime($start_date)) / 86400) + 1,
    ];
}

function ndr_district_chunk_ranges($start_date, $end_date, $chunk_days = 3) {
    $chunk_days = max(1, min(7, (int)$chunk_days));
    $ranges = [];
    $cur = strtotime($start_date);
    $end = strtotime($end_date);
    while ($cur <= $end) {
        $chunk_start = date('Y-m-d', $cur);
        $chunk_end_ts = min($end, strtotime('+' . ($chunk_days - 1) . ' days', $cur));
        $ranges[] = [$chunk_start, date('Y-m-d', $chunk_end_ts)];
        $cur = strtotime('+' . $chunk_days . ' days', $cur);
    }
    return $ranges;
}

function ndr_district_sql_chw_filter($con_hosxp, $chwpart, $alias = 'p') {
    $chwpart = trim((string)$chwpart);
    if ($chwpart === '') {
        return '';
    }
    $esc = mysqli_real_escape_string($con_hosxp, $chwpart);
    return " AND $alias.chwpart = '$esc' ";
}

function ndr_district_normalize_visit_type($visit_type) {
    $visit_type = strtolower(trim((string)$visit_type));
    if (in_array($visit_type, ['opd', 'vn'], true)) {
        return 'opd';
    }
    if (in_array($visit_type, ['ipd', 'an'], true)) {
        return 'ipd';
    }
    return 'all';
}

function ndr_district_visit_type_label($visit_type) {
    $visit_type = ndr_district_normalize_visit_type($visit_type);
    if ($visit_type === 'opd') {
        return 'OPD (VN)';
    }
    if ($visit_type === 'ipd') {
        return 'IPD (AN)';
    }
    return 'ทั้งหมด';
}

function ndr_district_sql_visit_filter($visit_type, $alias = 'o') {
    $visit_type = ndr_district_normalize_visit_type($visit_type);
    if ($visit_type === 'opd') {
        return " AND TRIM(IFNULL($alias.an, '')) = '' AND TRIM(IFNULL($alias.vn, '')) <> '' ";
    }
    if ($visit_type === 'ipd') {
        return " AND TRIM(IFNULL($alias.an, '')) <> '' ";
    }
    return '';
}

function ndr_is_pttype_social_security(array $row) {
    $name = trim($row['name'] ?? '');
    $hip = strtoupper(trim($row['hipdata_code'] ?? ''));
    if ($hip === 'SSS') {
        return true;
    }
    return $name !== '' && strpos($name, 'ประกันสังคม') !== false;
}

function ndr_is_pttype_default_outside_district(array $row) {
    $name = trim($row['name'] ?? '');
    if ($name === '' || strpos($name, 'นอกเขต') === false) {
        return false;
    }
    return !ndr_is_pttype_social_security($row);
}

function ndr_is_pttype_inside_district(array $row) {
    $name = trim($row['name'] ?? '');
    if ($name === '' || strpos($name, 'ในเขต') === false) {
        return false;
    }
    if (strpos($name, 'นอกเขต') !== false) {
        return false;
    }
    return !ndr_is_pttype_social_security($row);
}

function ndr_fetch_pttype_filter_options($con_hosxp) {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    $q = mysqli_query($con_hosxp, "
        SELECT pttype,
               CAST(CONVERT(name USING utf8) AS CHAR) AS name,
               CAST(CONVERT(IFNULL(hipdata_code, '') USING utf8) AS CHAR) AS hipdata_code
        FROM pttype
        WHERE TRIM(IFNULL(name, '')) <> ''
        ORDER BY name ASC
    ");
    if (!$q) {
        return $cache;
    }
    while ($row = mysqli_fetch_assoc($q)) {
        $cache[] = [
            'pttype' => trim($row['pttype'] ?? ''),
            'name' => trim($row['name'] ?? ''),
            'hipdata_code' => trim($row['hipdata_code'] ?? ''),
            'is_default' => ndr_is_pttype_default_outside_district($row),
            'is_inside_default' => ndr_is_pttype_inside_district($row),
            'is_social_security' => ndr_is_pttype_social_security($row),
        ];
    }
    return $cache;
}

function ndr_default_pttype_filter_codes($con_hosxp) {
    $codes = [];
    foreach (ndr_fetch_pttype_filter_options($con_hosxp) as $opt) {
        if (!empty($opt['is_default'])) {
            $codes[] = $opt['pttype'];
        }
    }
    return $codes;
}

function ndr_default_pttype_inside_filter_codes($con_hosxp) {
    $codes = [];
    foreach (ndr_fetch_pttype_filter_options($con_hosxp) as $opt) {
        if (!empty($opt['is_inside_default'])) {
            $codes[] = $opt['pttype'];
        }
    }
    return $codes;
}

function ndr_resolve_pttype_filter($con_hosxp, $raw) {
    $all_codes = array_map(function ($opt) {
        return $opt['pttype'];
    }, ndr_fetch_pttype_filter_options($con_hosxp));

    if ($raw === 'all' || $raw === null) {
        return ['mode' => 'all', 'codes' => [], 'label' => 'สิทธิทั้งหมด'];
    }

    if ($raw === 'default') {
        $codes = ndr_default_pttype_filter_codes($con_hosxp);
        return [
            'mode' => 'default',
            'codes' => $codes,
            'label' => 'นอกเขต (ยกเว้นประกันสังคม)',
        ];
    }

    if ($raw === 'inside') {
        $codes = ndr_default_pttype_inside_filter_codes($con_hosxp);
        return [
            'mode' => 'inside',
            'codes' => $codes,
            'label' => 'ในเขต (ยกเว้นประกันสังคม)',
        ];
    }

    $codes = [];
    if (is_array($raw)) {
        foreach ($raw as $c) {
            $c = trim((string)$c);
            if ($c !== '' && $c !== 'all') {
                $codes[$c] = true;
            }
        }
    } elseif (is_string($raw) && $raw !== '') {
        foreach (explode(',', $raw) as $c) {
            $c = trim($c);
            if ($c !== '') {
                $codes[$c] = true;
            }
        }
    }
    $codes = array_values(array_keys($codes));

    if (empty($codes)) {
        $codes = ndr_default_pttype_filter_codes($con_hosxp);
        return [
            'mode' => 'default',
            'codes' => $codes,
            'label' => 'นอกเขต (ยกเว้นประกันสังคม)',
        ];
    }

    if (count($codes) === count($all_codes)) {
        return ['mode' => 'all', 'codes' => [], 'label' => 'สิทธิทั้งหมด'];
    }

    return [
        'mode' => 'custom',
        'codes' => $codes,
        'label' => 'สิทธิที่เลือก ' . count($codes) . ' รายการ',
    ];
}

function ndr_district_sql_pttype_joins() {
    return "
        LEFT JOIN ovst ndr_ov ON ndr_ov.vn = o.vn AND TRIM(IFNULL(o.vn, '')) <> ''
        LEFT JOIN ipt ndr_ipt ON ndr_ipt.an = o.an AND TRIM(IFNULL(o.an, '')) <> ''
        LEFT JOIN pttype ndr_pt ON ndr_pt.pttype = IF(TRIM(IFNULL(o.an, '')) <> '', ndr_ipt.pttype, ndr_ov.pttype)
    ";
}

function ndr_district_sql_pttype_filter($con_hosxp, array $pttype_codes, $alias = 'ndr_pt') {
    if (empty($pttype_codes)) {
        return '';
    }
    $in = [];
    foreach ($pttype_codes as $pt) {
        $pt = trim((string)$pt);
        if ($pt !== '') {
            $in[] = "'" . mysqli_real_escape_string($con_hosxp, $pt) . "'";
        }
    }
    if (empty($in)) {
        return '';
    }
    return ' AND ' . $alias . '.pttype IN (' . implode(',', $in) . ') ';
}

function ndr_district_pttype_sql_parts($con_hosxp, array $pttype_codes) {
    if (empty($pttype_codes)) {
        return ['joins' => '', 'filter' => ''];
    }
    return [
        'joins' => ndr_district_sql_pttype_joins(),
        'filter' => ndr_district_sql_pttype_filter($con_hosxp, $pttype_codes, 'ndr_pt'),
    ];
}

function ndr_district_fetch_hn_rows($con_hosxp, $start_date, $end_date, $chwpart = '', $visit_type = 'all', array $pttype_codes = []) {
    $start_esc = mysqli_real_escape_string($con_hosxp, $start_date);
    $end_esc = mysqli_real_escape_string($con_hosxp, $end_date);
    $chw_filter = ndr_district_sql_chw_filter($con_hosxp, $chwpart, 'p');
    $visit_filter = ndr_district_sql_visit_filter($visit_type, 'o');
    $pt_sql = ndr_district_pttype_sql_parts($con_hosxp, $pttype_codes);
    $sql = "
        SELECT DISTINCT p.chwpart, p.amppart, p.hn
        FROM opitemrece o
        INNER JOIN drugitems d ON d.icode = o.icode AND d.istatus = 'Y'
        INNER JOIN patient p ON p.hn = o.hn
        {$pt_sql['joins']}
        WHERE o.vstdate BETWEEN '$start_esc' AND '$end_esc'
        $chw_filter
        $visit_filter
        {$pt_sql['filter']}
    ";
    $q = mysqli_query($con_hosxp, $sql);
    if (!$q) {
        return [];
    }
    $rows = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $rows[] = $row;
    }
    return $rows;
}

function ndr_district_lookup_amp_names($con_hosxp, array $district_keys) {
    $names = [];
    if (empty($district_keys)) {
        return $names;
    }
    $conds = [];
    foreach ($district_keys as $key) {
        $parts = explode(':', $key, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $chw = mysqli_real_escape_string($con_hosxp, $parts[0]);
        $amp = mysqli_real_escape_string($con_hosxp, $parts[1]);
        $conds[] = "(chwpart = '$chw' AND amppart = '$amp')";
    }
    if (empty($conds)) {
        return $names;
    }
    $q = mysqli_query($con_hosxp, "
        SELECT chwpart, amppart,
               CAST(CONVERT(name USING utf8) AS CHAR) AS amp_name
        FROM thaiaddress
        WHERE codetype = '2' AND tmbpart = '00'
          AND (" . implode(' OR ', $conds) . ")
    ");
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $key = trim($row['chwpart'] ?? '') . ':' . trim($row['amppart'] ?? '');
            $names[$key] = trim($row['amp_name'] ?? '');
        }
    }
    return $names;
}

function ndr_district_fetch_summary_single($con_hosxp, $start_date, $end_date, $chwpart = '', $visit_type = 'all', array $pttype_codes = []) {
    $start_esc = mysqli_real_escape_string($con_hosxp, $start_date);
    $end_esc = mysqli_real_escape_string($con_hosxp, $end_date);
    $chw_filter = ndr_district_sql_chw_filter($con_hosxp, $chwpart, 'p');
    $visit_filter = ndr_district_sql_visit_filter($visit_type, 'o');
    $pt_sql = ndr_district_pttype_sql_parts($con_hosxp, $pttype_codes);
    $sql = "
        SELECT p.chwpart, p.amppart,
               COUNT(DISTINCT p.hn) AS patient_count
        FROM opitemrece o
        INNER JOIN drugitems d ON d.icode = o.icode AND d.istatus = 'Y'
        INNER JOIN patient p ON p.hn = o.hn
        {$pt_sql['joins']}
        WHERE o.vstdate BETWEEN '$start_esc' AND '$end_esc'
        $chw_filter
        $visit_filter
        {$pt_sql['filter']}
        GROUP BY p.chwpart, p.amppart
        ORDER BY patient_count DESC
    ";
    $q = mysqli_query($con_hosxp, $sql);
    if (!$q) {
        return ['_error' => mysqli_error($con_hosxp)];
    }
    $map = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $key = trim($row['chwpart'] ?? '') . ':' . trim($row['amppart'] ?? '');
        $map[$key] = [
            'district_key' => $key,
            'chwpart' => trim($row['chwpart'] ?? ''),
            'amppart' => trim($row['amppart'] ?? ''),
            'patient_count' => (int)($row['patient_count'] ?? 0),
        ];
    }
    return $map;
}

function ndr_district_fetch_summary_chunked($con_hosxp, $start_date, $end_date, $chwpart = '', $visit_type = 'all', array $pttype_codes = []) {
    $map = [];
    $hn_sets = [];
    foreach (ndr_district_chunk_ranges($start_date, $end_date, 3) as $range) {
        $rows = ndr_district_fetch_hn_rows($con_hosxp, $range[0], $range[1], $chwpart, $visit_type, $pttype_codes);
        foreach ($rows as $row) {
            $key = trim($row['chwpart'] ?? '') . ':' . trim($row['amppart'] ?? '');
            $hn = trim($row['hn'] ?? '');
            if ($hn === '') {
                continue;
            }
            if (!isset($hn_sets[$key])) {
                $hn_sets[$key] = [];
            }
            $hn_sets[$key][$hn] = true;
            if (!isset($map[$key])) {
                $map[$key] = [
                    'district_key' => $key,
                    'chwpart' => trim($row['chwpart'] ?? ''),
                    'amppart' => trim($row['amppart'] ?? ''),
                    'patient_count' => 0,
                ];
            }
        }
    }
    foreach ($hn_sets as $key => $hns) {
        if (isset($map[$key])) {
            $map[$key]['patient_count'] = count($hns);
        }
    }
    return $map;
}

function ndr_fetch_district_summary($con_hosxp, $start_date, $end_date, $chwpart = '', $visit_type = 'all', array $pttype_codes = []) {
    $days = (int)((strtotime($end_date) - strtotime($start_date)) / 86400) + 1;
    if ($days <= 3) {
        $map = ndr_district_fetch_summary_single($con_hosxp, $start_date, $end_date, $chwpart, $visit_type, $pttype_codes);
        if (isset($map['_error'])) {
            return ['_error' => $map['_error']];
        }
    } else {
        $map = ndr_district_fetch_summary_chunked($con_hosxp, $start_date, $end_date, $chwpart, $visit_type, $pttype_codes);
    }

    $amp_names = ndr_district_lookup_amp_names($con_hosxp, array_keys($map));
    $chw_name = $chwpart !== '' ? ndr_fetch_hospital_chw_name($con_hosxp, $chwpart) : '';
    $districts = [];
    foreach ($map as $key => $d) {
        $amp_name = trim($amp_names[$key] ?? '');
        if ($amp_name === '') {
            $amp_name = 'ไม่ระบุอำเภอ';
        }
        $districts[] = [
            'district_key' => $key,
            'chwpart' => $d['chwpart'],
            'amppart' => $d['amppart'],
            'amp_name' => $amp_name,
            'chw_name' => $chw_name,
            'patient_count' => (int)$d['patient_count'],
        ];
    }
    usort($districts, function ($a, $b) {
        $cmp = ($b['patient_count'] ?? 0) <=> ($a['patient_count'] ?? 0);
        if ($cmp !== 0) {
            return $cmp;
        }
        return strcmp($a['amp_name'] ?? '', $b['amp_name'] ?? '');
    });
    return $districts;
}

function ndr_format_time_hm($time) {
    $time = trim((string)$time);
    if ($time === '') {
        return '';
    }
    if (preg_match('/^\d{1,2}:\d{2}/', $time)) {
        return substr($time, 0, 5);
    }
    $digits = preg_replace('/\D/', '', $time);
    if (strlen($digits) >= 4) {
        $digits = str_pad(substr($digits, 0, 6), 6, '0', STR_PAD_RIGHT);
        return substr($digits, 0, 2) . ':' . substr($digits, 2, 2);
    }
    return $time;
}

function ndr_time_sort_key($time) {
    $time = trim((string)$time);
    if ($time === '') {
        return '000000';
    }
    if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $time)) {
        $parts = explode(':', $time);
        return str_pad($parts[0], 2, '0', STR_PAD_LEFT)
            . str_pad($parts[1], 2, '0', STR_PAD_LEFT)
            . str_pad($parts[2] ?? '00', 2, '0', STR_PAD_LEFT);
    }
    $digits = preg_replace('/\D/', '', $time);
    return str_pad(substr($digits, 0, 6), 6, '0', STR_PAD_RIGHT);
}

function ndr_district_order_key_from_parts($vstdate, $rxdate, $rxtime, $vsttime) {
    $date = trim((string)$rxdate);
    if ($date === '' || $date === '0000-00-00') {
        $date = trim((string)$vstdate);
    }
    $time = trim((string)$rxtime);
    if ($time === '') {
        $time = trim((string)$vsttime);
    }
    return $date . ' ' . ndr_time_sort_key($time);
}

function ndr_district_apply_last_order_fields(array &$patient, $vstdate, $rxdate, $rxtime, $vsttime) {
    $date = trim((string)$rxdate);
    if ($date === '' || $date === '0000-00-00') {
        $date = trim((string)$vstdate);
    }
    $timeRaw = trim((string)$rxtime);
    if ($timeRaw === '') {
        $timeRaw = trim((string)$vsttime);
    }
    $patient['last_vstdate'] = $date;
    $patient['last_order_time'] = $timeRaw;
    $patient['last_order_sort'] = ndr_district_order_key_from_parts($vstdate, $rxdate, $rxtime, $vsttime);
    $ts = strtotime($date);
    $dateDisplay = $ts ? date('d/m/', $ts) . ((int)date('Y', $ts) + 543) : '';
    $timeDisplay = ndr_format_time_hm($timeRaw);
    $patient['last_vstdate_display'] = trim($dateDisplay . ($timeDisplay !== '' ? ' ' . $timeDisplay : ''));
}

function ndr_district_parse_last_order_raw($raw) {
    $parts = explode('|', (string)$raw);
    return [
        'vstdate' => trim($parts[0] ?? ''),
        'rxdate' => trim($parts[1] ?? ''),
        'rxtime' => trim($parts[2] ?? ''),
        'vsttime' => trim($parts[3] ?? ''),
    ];
}

function ndr_district_parse_patient_sort($sort) {
    $sort = trim((string)$sort);
    if (preg_match('/^(hn|full_name|tmb_name|address|pttype_name|visit_count|last_order|match_percent)_(asc|desc)$/', $sort, $m)) {
        return ['col' => $m[1], 'dir' => $m[2]];
    }
    if ($sort === 'date_asc') {
        return ['col' => 'last_order', 'dir' => 'asc'];
    }
    return ['col' => 'last_order', 'dir' => 'desc'];
}

function ndr_district_patient_sort_label($sort) {
    $labels = [
        'hn' => 'HN',
        'full_name' => 'ชื่อผู้ป่วย',
        'tmb_name' => 'ตำบล',
        'address' => 'ที่อยู่',
        'pttype_name' => 'สิทธิการรักษา',
        'visit_count' => 'Visit',
        'last_order' => 'ล่าสุด',
        'match_percent' => 'เทียบบัญชี',
    ];
    $parsed = ndr_district_parse_patient_sort($sort);
    $name = $labels[$parsed['col']] ?? 'ล่าสุด';
    $dir = $parsed['dir'] === 'asc' ? 'A-Z' : 'Z-A';
    return $name . ' (' . $dir . ')';
}

function ndr_district_match_percent_sort_key(array $patient) {
    if (($patient['match_percent'] ?? null) === null || (int)($patient['match_total'] ?? 0) === 0) {
        return -1;
    }
    return (int)$patient['match_percent'];
}

function ndr_district_sort_patients(array &$patients, $sort) {
    $parsed = ndr_district_parse_patient_sort($sort);
    $col = $parsed['col'];
    $dir = $parsed['dir'];
    usort($patients, function ($a, $b) use ($col, $dir) {
        if ($col === 'match_percent') {
            $cmp = ndr_district_match_percent_sort_key($a) <=> ndr_district_match_percent_sort_key($b);
        } else {
            $va = ndr_district_patient_sort_value($a, $col);
            $vb = ndr_district_patient_sort_value($b, $col);
            $cmp = strcmp($va, $vb);
        }
        if ($cmp === 0) {
            $cmp = strcmp($a['hn'] ?? '', $b['hn'] ?? '');
        }
        return $dir === 'asc' ? $cmp : -$cmp;
    });
}

function ndr_district_patient_sort_value(array $patient, $col) {
    switch ($col) {
        case 'hn':
            return $patient['hn'] ?? '';
        case 'full_name':
            return $patient['full_name'] ?? '';
        case 'tmb_name':
            return $patient['tmb_name'] ?? '';
        case 'address':
            return $patient['address'] ?? '';
        case 'pttype_name':
            return $patient['pttype_name'] ?? '';
        case 'visit_count':
            return str_pad((int)($patient['visit_count'] ?? 0), 10, '0', STR_PAD_LEFT);
        case 'last_order':
        default:
            return ($patient['last_order_sort'] ?? $patient['last_vstdate'] ?? '')
                . ' ' . ndr_time_sort_key($patient['last_order_time'] ?? '')
                . ' ' . ($patient['hn'] ?? '');
    }
}

function ndr_district_merge_patient_rows(array &$patients, array $rows) {
    foreach ($rows as $row) {
        $hn = trim($row['hn'] ?? '');
        if ($hn === '') {
            continue;
        }
        $vstdate = trim($row['vstdate'] ?? '');
        $rxdate = trim($row['rxdate'] ?? '');
        $rxtime = trim($row['rxtime'] ?? '');
        $vsttime = trim($row['vsttime'] ?? '');
        $vn = trim($row['vn'] ?? '');
        $an = trim($row['an'] ?? '');
        $visit_key = $an !== '' ? ('i:' . $an) : ('o:' . $vn);
        $orderKey = ndr_district_order_key_from_parts($vstdate, $rxdate, $rxtime, $vsttime);

        if (!isset($patients[$hn])) {
            $amppart = trim($row['amppart'] ?? '');
            $chwpart = trim($row['chwpart'] ?? '');
            $patients[$hn] = [
                'hn' => $hn,
                'full_name' => trim($row['full_name'] ?? ''),
                'address' => ndr_format_patient_address($row),
                'tmb_name' => trim($row['tmb_name'] ?? ''),
                'amppart' => $amppart,
                'amp_name' => trim($row['amp_name'] ?? ''),
                'district_key' => ($chwpart !== '' && $amppart !== '') ? ($chwpart . ':' . $amppart) : '',
                'last_vstdate' => $vstdate,
                'last_order_time' => '',
                'last_order_sort' => $orderKey,
                'pttype_name' => trim($row['pttype_name'] ?? ''),
                'visit_keys' => [],
                'drug_count' => 0,
            ];
            ndr_district_apply_last_order_fields($patients[$hn], $vstdate, $rxdate, $rxtime, $vsttime);
        } elseif (strcmp($orderKey, $patients[$hn]['last_order_sort'] ?? '') > 0) {
            ndr_district_apply_last_order_fields($patients[$hn], $vstdate, $rxdate, $rxtime, $vsttime);
            $ptname = trim($row['pttype_name'] ?? '');
            if ($ptname !== '') {
                $patients[$hn]['pttype_name'] = $ptname;
            }
        }
        if ($vn !== '' || $an !== '') {
            $patients[$hn]['visit_keys'][$visit_key] = true;
        }
        $patients[$hn]['drug_count']++;
    }
}

function ndr_district_fetch_patient_rows($con_hosxp, $start_date, $end_date, $chwpart, $amppart, $visit_type = 'all', array $pttype_codes = []) {
    $start_esc = mysqli_real_escape_string($con_hosxp, $start_date);
    $end_esc = mysqli_real_escape_string($con_hosxp, $end_date);
    $chw_esc = mysqli_real_escape_string($con_hosxp, $chwpart);
    $amp_filter = ndr_district_sql_patient_amp_filter($con_hosxp, $amppart, 'p');
    $visit_filter = ndr_district_sql_visit_filter($visit_type, 'o');
    $pt_sql = ndr_district_pttype_sql_parts($con_hosxp, $pttype_codes);
    $addr_sql = ndr_sql_thaiaddress_names('p');
    $sql = "
        SELECT p.hn,
               p.chwpart,
               p.amppart,
               CAST(CONVERT(CONCAT(IFNULL(p.pname,''), IFNULL(p.fname,''), ' ', IFNULL(p.lname,'')) USING utf8) AS CHAR) AS full_name,
               CAST(CONVERT(IFNULL(p.addrpart,'') USING utf8) AS CHAR) AS addrpart,
               CAST(CONVERT(IFNULL(p.moopart,'') USING utf8) AS CHAR) AS moopart,
               $addr_sql,
               o.vstdate,
               o.rxdate,
               o.rxtime,
               o.vsttime,
               o.vn,
               o.an,
               CAST(CONVERT(IFNULL(ndr_pt.name, '') USING utf8) AS CHAR) AS pttype_name
        FROM opitemrece o
        INNER JOIN drugitems d ON d.icode = o.icode AND d.istatus = 'Y'
        INNER JOIN patient p ON p.hn = o.hn
            AND p.chwpart = '$chw_esc'
            $amp_filter
        {$pt_sql['joins']}
        WHERE o.vstdate BETWEEN '$start_esc' AND '$end_esc'
        $visit_filter
        {$pt_sql['filter']}
    ";
    $q = mysqli_query($con_hosxp, $sql);
    if (!$q) {
        return [];
    }
    $rows = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $rows[] = $row;
    }
    return $rows;
}

function ndr_fetch_district_patients($con_hosxp, $chwpart, $amppart, $start_date, $end_date, $page = 1, $per_page = 50, $visit_type = 'all', array $pttype_codes = [], $sort = 'last_order_desc') {
    $chwpart = trim((string)$chwpart);
    $amppart = trim((string)$amppart);
    $all_amp = ndr_district_is_all_amppart($amppart);
    if ($chwpart === '' || ($amppart === '' && !$all_amp)) {
        return ['patients' => [], 'total' => 0, 'page' => 1, 'per_page' => $per_page];
    }

    $sort_parsed = ndr_district_parse_patient_sort($sort);
    $sort = $sort_parsed['col'] . '_' . $sort_parsed['dir'];
    $export_all = ((int)$per_page) <= 0;
    $page = max(1, (int)$page);
    if (!$export_all) {
        $per_page = max(10, min(200, (int)$per_page));
    }
    $days = (int)((strtotime($end_date) - strtotime($start_date)) / 86400) + 1;
    $patients = [];

    if ($days <= 3) {
        $start_esc = mysqli_real_escape_string($con_hosxp, $start_date);
        $end_esc = mysqli_real_escape_string($con_hosxp, $end_date);
        $chw_esc = mysqli_real_escape_string($con_hosxp, $chwpart);
        $amp_filter = ndr_district_sql_patient_amp_filter($con_hosxp, $amppart, 'p');
        $visit_filter = ndr_district_sql_visit_filter($visit_type, 'o');
        $pt_sql = ndr_district_pttype_sql_parts($con_hosxp, $pttype_codes);
        $addr_sql = ndr_sql_thaiaddress_names('p');
        $sql = "
            SELECT p.hn,
                   p.chwpart,
                   p.amppart,
                   CAST(CONVERT(CONCAT(IFNULL(p.pname,''), IFNULL(p.fname,''), ' ', IFNULL(p.lname,'')) USING utf8) AS CHAR) AS full_name,
                   CAST(CONVERT(IFNULL(p.addrpart,'') USING utf8) AS CHAR) AS addrpart,
                   CAST(CONVERT(IFNULL(p.moopart,'') USING utf8) AS CHAR) AS moopart,
                   $addr_sql,
                   SUBSTRING_INDEX(GROUP_CONCAT(
                       CAST(CONCAT(
                           IFNULL(o.vstdate, ''), '|',
                           IFNULL(NULLIF(o.rxdate, '0000-00-00'), ''), '|',
                           IFNULL(o.rxtime, ''), '|',
                           IFNULL(o.vsttime, '')
                       ) AS CHAR)
                       ORDER BY o.vstdate DESC,
                                IFNULL(NULLIF(o.rxdate, '0000-00-00'), o.vstdate) DESC,
                                IFNULL(NULLIF(o.rxtime, ''), o.vsttime) DESC,
                                o.vsttime DESC,
                                o.item_no DESC
                       SEPARATOR '||'
                   ), '||', 1) AS last_order_raw,
                   COUNT(DISTINCT CASE WHEN TRIM(IFNULL(o.an,'')) <> '' THEN o.an ELSE o.vn END) AS visit_count,
                   COUNT(*) AS drug_count,
                   SUBSTRING_INDEX(GROUP_CONCAT(
                       CAST(CONVERT(IFNULL(ndr_pt.name, '') USING utf8) AS CHAR)
                       ORDER BY o.vstdate DESC SEPARATOR '||'
                   ), '||', 1) AS pttype_name
            FROM opitemrece o
            INNER JOIN drugitems d ON d.icode = o.icode AND d.istatus = 'Y'
            INNER JOIN patient p ON p.hn = o.hn
                AND p.chwpart = '$chw_esc'
                $amp_filter
            {$pt_sql['joins']}
            WHERE o.vstdate BETWEEN '$start_esc' AND '$end_esc'
            $visit_filter
            {$pt_sql['filter']}
            GROUP BY p.hn, p.pname, p.fname, p.lname, p.addrpart, p.moopart,
                     p.chwpart, p.amppart, p.tmbpart
        ";
        $q = mysqli_query($con_hosxp, $sql);
        if ($q) {
            while ($row = mysqli_fetch_assoc($q)) {
                $order = ndr_district_parse_last_order_raw($row['last_order_raw'] ?? '');
                $row_amppart = trim($row['amppart'] ?? '');
                $row_chwpart = trim($row['chwpart'] ?? '');
                $patient = [
                    'hn' => trim($row['hn']),
                    'full_name' => trim($row['full_name'] ?? ''),
                    'address' => ndr_format_patient_address($row),
                    'tmb_name' => trim($row['tmb_name'] ?? ''),
                    'amppart' => $row_amppart,
                    'amp_name' => trim($row['amp_name'] ?? ''),
                    'district_key' => ($row_chwpart !== '' && $row_amppart !== '') ? ($row_chwpart . ':' . $row_amppart) : '',
                    'visit_count' => (int)($row['visit_count'] ?? 0),
                    'drug_count' => (int)($row['drug_count'] ?? 0),
                    'pttype_name' => trim($row['pttype_name'] ?? ''),
                ];
                ndr_district_apply_last_order_fields(
                    $patient,
                    $order['vstdate'],
                    $order['rxdate'],
                    $order['rxtime'],
                    $order['vsttime']
                );
                $patients[trim($row['hn'])] = $patient;
            }
        }
    } else {
        foreach (ndr_district_chunk_ranges($start_date, $end_date, 3) as $range) {
            $rows = ndr_district_fetch_patient_rows($con_hosxp, $range[0], $range[1], $chwpart, $amppart, $visit_type, $pttype_codes);
            ndr_district_merge_patient_rows($patients, $rows);
        }
        foreach ($patients as $hn => $p) {
            $patients[$hn]['visit_count'] = count($p['visit_keys'] ?? []);
            unset($patients[$hn]['visit_keys']);
        }
    }

    $list = array_values($patients);
    $sort_col = $sort_parsed['col'];
    $sort_dir = $sort_parsed['dir'];
    usort($list, function ($a, $b) use ($sort_col, $sort_dir) {
        $va = ndr_district_patient_sort_value($a, $sort_col);
        $vb = ndr_district_patient_sort_value($b, $sort_col);
        $cmp = strcmp($va, $vb);
        if ($cmp === 0) {
            $cmp = strcmp($a['hn'] ?? '', $b['hn'] ?? '');
        }
        return $sort_dir === 'asc' ? $cmp : -$cmp;
    });

    $total = count($list);
    if (!$export_all) {
        $offset = ($page - 1) * $per_page;
        $list = array_slice($list, $offset, $per_page);
    }

    ndr_enrich_patients_pttype_in_period($con_hosxp, $list, $start_date, $end_date, $visit_type, $pttype_codes);

    return [
        'patients' => $list,
        'total' => $total,
        'page' => $export_all ? 1 : $page,
        'per_page' => $export_all ? $total : $per_page,
        'pages' => $export_all ? 1 : ($total > 0 ? (int)ceil($total / $per_page) : 1),
        'sort' => $sort,
    ];
}

function ndr_hn_array_sql_in($con_hosxp, array $hns) {
    $parts = [];
    foreach ($hns as $hn) {
        $hn = trim((string)$hn);
        if ($hn === '') {
            continue;
        }
        foreach (ndr_hn_variants($hn) as $v) {
            $parts["'" . mysqli_real_escape_string($con_hosxp, $v) . "'"] = true;
        }
    }
    return implode(',', array_keys($parts));
}

/** สิทธิการรักษาจาก Visit ที่ผูกกับรายการยาในช่วงเวลา (รองรับ IPD ที่ admit ก่อนช่วงแต่จ่ายยาในช่วง) */
function ndr_enrich_patients_pttype_in_period($con_hosxp, array &$patients, $start_date, $end_date, $visit_type = 'all', array $pttype_codes = []) {
    if (empty($patients)) {
        return;
    }
    $visit_filter = ndr_district_sql_visit_filter($visit_type, 'o');
    $pt_filter = ndr_district_sql_pttype_filter($con_hosxp, $pttype_codes, 'pt');
    $hns = [];
    foreach ($patients as $p) {
        $hn = trim($p['hn'] ?? '');
        if ($hn !== '') {
            $hns[] = $hn;
        }
    }
    $hn_in = ndr_hn_array_sql_in($con_hosxp, $hns);
    if ($hn_in === '') {
        return;
    }
    $start_esc = mysqli_real_escape_string($con_hosxp, $start_date);
    $end_esc = mysqli_real_escape_string($con_hosxp, $end_date);

    $map = [];

    $sql_orders = "
        SELECT o.hn,
               CAST(CONVERT(IFNULL(pt.name, '') USING utf8) AS CHAR) AS pttype_name,
               o.vstdate
        FROM opitemrece o
        INNER JOIN drugitems d ON d.icode = o.icode AND d.istatus = 'Y'
        LEFT JOIN ovst ov ON ov.vn = o.vn AND TRIM(IFNULL(o.vn, '')) <> ''
        LEFT JOIN ipt i ON i.an = o.an AND TRIM(IFNULL(o.an, '')) <> ''
        LEFT JOIN pttype pt ON pt.pttype = IF(TRIM(IFNULL(o.an, '')) <> '', i.pttype, ov.pttype)
        WHERE o.hn IN ($hn_in)
          AND o.vstdate BETWEEN '$start_esc' AND '$end_esc'
          $visit_filter
          $pt_filter
          AND TRIM(IFNULL(pt.name, '')) <> ''
        ORDER BY o.hn ASC, o.vstdate DESC
    ";
    $q = mysqli_query($con_hosxp, $sql_orders);
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $hn = trim($row['hn'] ?? '');
            if ($hn !== '' && !isset($map[$hn])) {
                $map[$hn] = trim($row['pttype_name'] ?? '');
            }
        }
    }

    $missing = [];
    foreach ($patients as $p) {
        $hn = trim($p['hn'] ?? '');
        if ($hn !== '' && !isset($map[$hn])) {
            $missing[] = $hn;
        }
    }
    if (!empty($missing)) {
        $miss_in = ndr_hn_array_sql_in($con_hosxp, $missing);
        if ($miss_in !== '') {
            $visit_type_norm = ndr_district_normalize_visit_type($visit_type);
            $ovst_part = '';
            $ipt_part = '';
            if ($visit_type_norm !== 'ipd') {
                $ovst_part = "
                    SELECT ov.hn, ov.pttype, ov.vstdate AS visit_date, ov.vsttime AS visit_time
                    FROM ovst ov
                    WHERE ov.hn IN ($miss_in)
                      AND ov.vstdate BETWEEN '$start_esc' AND '$end_esc'
                ";
            }
            if ($visit_type_norm !== 'opd') {
                $ipt_part = "
                    SELECT i.hn, i.pttype, i.regdate AS visit_date, i.regtime AS visit_time
                    FROM ipt i
                    WHERE i.hn IN ($miss_in)
                      AND i.regdate BETWEEN '$start_esc' AND '$end_esc'
                ";
            }
            $union_parts = array_filter([trim($ovst_part), trim($ipt_part)]);
            if (!empty($union_parts)) {
            $sql_visits = "
                SELECT v.hn,
                       CAST(CONVERT(IFNULL(pt.name, '') USING utf8) AS CHAR) AS pttype_name,
                       v.visit_date,
                       v.visit_time
                FROM (
                    " . implode(' UNION ALL ', $union_parts) . "
                ) v
                LEFT JOIN pttype pt ON pt.pttype = v.pttype
                WHERE TRIM(IFNULL(pt.name, '')) <> ''
                ORDER BY v.hn ASC, v.visit_date DESC, v.visit_time DESC
            ";
            $qv = mysqli_query($con_hosxp, $sql_visits);
            if ($qv) {
                while ($row = mysqli_fetch_assoc($qv)) {
                    $hn = trim($row['hn'] ?? '');
                    if ($hn !== '' && !isset($map[$hn])) {
                        $map[$hn] = trim($row['pttype_name'] ?? '');
                    }
                }
            }
            }
        }
    }

    foreach ($patients as &$p) {
        $hn = trim($p['hn'] ?? '');
        if (trim($p['pttype_name'] ?? '') === '') {
            $p['pttype_name'] = $map[$hn] ?? '';
        }
    }
    unset($p);
}
