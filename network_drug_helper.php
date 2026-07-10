<?php
/**
 * บัญชียาเครือข่าย — อัปโหลด CSV/Excel และแสดงตารางเครือข่าย
 */

function nd_expected_columns() {
    return [
        'tpu_code_list',
        'name',
        'generic_name',
        'strength',
        'tmtdrug',
        'units',
        'dosageform',
        'hosname',
    ];
}

function nd_ensure_tables($con) {
    $q = mysqli_query($con, "SHOW TABLES LIKE 'network_drug_items'");
    if ($q && mysqli_num_rows($q) > 0) {
        nd_ensure_schema($con);
        return true;
    }
    $sql = @file_get_contents(__DIR__ . '/sql/network_drug.sql');
    if ($sql === false || trim($sql) === '') {
        return false;
    }
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt !== '' && !mysqli_query($con, $stmt)) {
            return false;
        }
    }
    nd_ensure_schema($con);
    return true;
}

function nd_ensure_schema($con) {
    $q = mysqli_query($con, "SHOW COLUMNS FROM network_drug_uploads LIKE 'hospitals_affected'");
    if ($q && mysqli_num_rows($q) === 0) {
        mysqli_query($con, 'ALTER TABLE network_drug_uploads ADD COLUMN hospitals_affected TEXT NULL AFTER is_active');
    }
}

function nd_truncate_str($str, $max) {
    $str = (string)$str;
    $max = (int)$max;
    if (function_exists('mb_substr')) {
        return mb_substr($str, 0, $max, 'UTF-8');
    }
    return substr($str, 0, $max);
}

function nd_normalize_header($header) {
    $h = trim((string)$header);
    $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
    $h = strtolower($h);
    $h = preg_replace('/[^a-z0-9_]+/', '_', $h);
    return trim($h, '_');
}

function nd_map_headers(array $headers) {
    $expected = nd_expected_columns();
    $aliases = [
        'tpu_code' => 'tpu_code_list',
        'tpu_code_list' => 'tpu_code_list',
        'tpu' => 'tpu_code_list',
        'generic' => 'generic_name',
        'generic_name' => 'generic_name',
        'dosage_form' => 'dosageform',
        'dosageform' => 'dosageform',
        'unit' => 'units',
        'units' => 'units',
        'hospital' => 'hosname',
        'hos_name' => 'hosname',
        'hosname' => 'hosname',
        'tmt_drug' => 'tmtdrug',
        'tmtdrug' => 'tmtdrug',
    ];
    $map = [];
    foreach ($headers as $idx => $raw) {
        $norm = nd_normalize_header($raw);
        if ($norm === '') {
            continue;
        }
        $key = $aliases[$norm] ?? $norm;
        if (in_array($key, $expected, true) && !isset($map[$key])) {
            $map[$key] = $idx;
        }
    }
    return $map;
}

function nd_validate_header_map(array $map, $require_hosname = false) {
    $missing = [];
    foreach (nd_expected_columns() as $col) {
        if (!$require_hosname && $col === 'hosname') {
            continue;
        }
        if (!isset($map[$col])) {
            $missing[] = $col;
        }
    }
    return $missing;
}

function nd_clean_cell($value) {
    $v = trim((string)$value);
    $v = preg_replace('/\s+/u', ' ', $v);
    return $v;
}

function nd_row_from_cells(array $cells, array $header_map) {
    $row = [];
    foreach (nd_expected_columns() as $col) {
        $idx = $header_map[$col] ?? null;
        $row[$col] = ($idx !== null && isset($cells[$idx])) ? nd_clean_cell($cells[$idx]) : '';
    }
    return $row;
}

function nd_is_empty_row(array $row) {
    foreach ($row as $v) {
        if ($v !== '') {
            return false;
        }
    }
    return true;
}

function nd_parse_csv_file($filepath) {
    $handle = @fopen($filepath, 'r');
    if (!$handle) {
        return ['status' => 'error', 'message' => 'เปิดไฟล์ CSV ไม่ได้'];
    }
    $headers = fgetcsv($handle);
    if (!$headers || !is_array($headers)) {
        fclose($handle);
        return ['status' => 'error', 'message' => 'ไฟล์ CSV ไม่มีหัวตาราง'];
    }
    $header_map = nd_map_headers($headers);
    $missing = nd_validate_header_map($header_map);
    if (!empty($missing)) {
        fclose($handle);
        return [
            'status' => 'error',
            'message' => 'หัวตารางไม่ครบ: ' . implode(', ', $missing),
            'expected' => nd_expected_columns(),
            'found' => array_values(array_filter(array_map('trim', $headers))),
        ];
    }
    $rows = [];
    while (($cells = fgetcsv($handle)) !== false) {
        if (!is_array($cells)) {
            continue;
        }
        $row = nd_row_from_cells($cells, $header_map);
        if (!nd_is_empty_row($row)) {
            $rows[] = $row;
        }
    }
    fclose($handle);
    return ['status' => 'success', 'rows' => $rows];
}

function nd_parse_rows_payload(array $payload_rows) {
    if (empty($payload_rows)) {
        return ['status' => 'error', 'message' => 'ไม่พบข้อมูลในไฟล์'];
    }
    $first = $payload_rows[0];
    if (!is_array($first)) {
        return ['status' => 'error', 'message' => 'รูปแบบข้อมูลไม่ถูกต้อง'];
    }

    $is_assoc = !isset($first[0]);
    if ($is_assoc) {
        $rows = [];
        foreach ($payload_rows as $item) {
            if (!is_array($item)) {
                continue;
            }
            $row = [];
            foreach (nd_expected_columns() as $col) {
                $row[$col] = nd_clean_cell($item[$col] ?? '');
            }
            if (!nd_is_empty_row($row)) {
                $rows[] = $row;
            }
        }
        if (empty($rows)) {
            return ['status' => 'error', 'message' => 'ไม่พบข้อมูลที่นำเข้าได้'];
        }
        $missing = [];
        foreach (nd_expected_columns() as $col) {
            if ($col === 'hosname') {
                continue;
            }
            $has = false;
            foreach ($rows as $r) {
                if ($r[$col] !== '') {
                    $has = true;
                    break;
                }
            }
            if (!$has) {
                $missing[] = $col;
            }
        }
        if (!empty($missing)) {
            return ['status' => 'error', 'message' => 'หัวตารางไม่ครบ: ' . implode(', ', $missing)];
        }
        return ['status' => 'success', 'rows' => $rows];
    }

    $headers = array_map('nd_clean_cell', $first);
    $header_map = nd_map_headers($headers);
    $missing = nd_validate_header_map($header_map);
    if (!empty($missing)) {
        return [
            'status' => 'error',
            'message' => 'หัวตารางไม่ครบ: ' . implode(', ', $missing),
            'expected' => nd_expected_columns(),
            'found' => $headers,
        ];
    }
    $rows = [];
    for ($i = 1, $n = count($payload_rows); $i < $n; $i++) {
        $cells = $payload_rows[$i];
        if (!is_array($cells)) {
            continue;
        }
        $row = nd_row_from_cells($cells, $header_map);
        if (!nd_is_empty_row($row)) {
            $rows[] = $row;
        }
    }
    if (empty($rows)) {
        return ['status' => 'error', 'message' => 'ไม่พบข้อมูลที่นำเข้าได้'];
    }
    return ['status' => 'success', 'rows' => $rows];
}

function nd_analyze_rows(array $rows) {
    $empty_count = 0;
    $has_value_count = 0;
    $hospitals = [];
    foreach ($rows as $row) {
        if (nd_is_empty_row($row)) {
            continue;
        }
        if (($row['tmtdrug'] ?? '') === '' && ($row['name'] ?? '') === '') {
            continue;
        }
        $hos = nd_clean_cell($row['hosname'] ?? '');
        if ($hos === '') {
            $empty_count++;
        } else {
            $has_value_count++;
            $hospitals[$hos] = true;
        }
    }
    return [
        'needs_hosname' => $empty_count > 0,
        'empty_hosname_count' => $empty_count,
        'hospitals_in_file' => array_values(array_keys($hospitals)),
        'total_data_rows' => $empty_count + $has_value_count,
    ];
}

function nd_prepare_rows_for_import(array $rows, $default_hosname = '') {
    $default_hosname = nd_clean_cell($default_hosname);
    $prepared = [];
    $by_hospital = [];

    foreach ($rows as $row) {
        if (nd_is_empty_row($row)) {
            continue;
        }
        if ($row['tmtdrug'] === '' && $row['name'] === '') {
            continue;
        }
        if ($row['tmtdrug'] === '') {
            $row['tmtdrug'] = trim($row['name'] . ' ' . $row['strength']);
        }
        $hos = nd_clean_cell($row['hosname'] ?? '');
        if ($hos === '') {
            $hos = $default_hosname;
        }
        if ($hos === '') {
            continue;
        }
        $row['hosname'] = $hos;
        $prepared[] = $row;
        $by_hospital[$hos][] = $row;
    }

    return ['rows' => $prepared, 'by_hospital' => $by_hospital];
}

function nd_import_rows($con, array $rows, $filename, $user_id, $user_name, $default_hosname = '') {
    nd_ensure_tables($con);
    if (empty($rows)) {
        return ['status' => 'error', 'message' => 'ไม่มีข้อมูลสำหรับนำเข้า'];
    }

    $analyze = nd_analyze_rows($rows);
    $default_hosname = nd_clean_cell($default_hosname);
    if ($analyze['needs_hosname'] && $default_hosname === '') {
        return [
            'status' => 'error',
            'message' => 'กรุณาระบุชื่อโรงพยาบาล (hosname ในไฟล์ว่าง)',
            'needs_hosname' => true,
            'analyze' => $analyze,
        ];
    }

    $prepared = nd_prepare_rows_for_import($rows, $default_hosname);
    if (empty($prepared['rows'])) {
        return ['status' => 'error', 'message' => 'ไม่พบแถวข้อมูลที่สมบูรณ์ (ต้องมีชื่อยา และชื่อโรงพยาบาล)'];
    }

    $existing_hospitals = nd_list_hospitals($con, 0);
    $affected_hospitals = array_keys($prepared['by_hospital']);
    usort($affected_hospitals, function ($a, $b) {
        return strcmp(nd_hospital_sort_key($a), nd_hospital_sort_key($b));
    });
    $overwritten = array_values(array_intersect($existing_hospitals, $affected_hospitals));
    $new_hospitals = array_values(array_diff($affected_hospitals, $existing_hospitals));

    mysqli_begin_transaction($con);

    $filename_esc = mysqli_real_escape_string($con, nd_truncate_str((string)$filename, 255));
    $user_name_esc = mysqli_real_escape_string($con, nd_truncate_str((string)$user_name, 255));
    $user_id = (int)$user_id;
    $hospitals_json = mysqli_real_escape_string($con, json_encode($affected_hospitals, JSON_UNESCAPED_UNICODE));

    if (!mysqli_query($con, "INSERT INTO network_drug_uploads (filename, uploaded_by, uploaded_by_name, row_count, is_active, hospitals_affected) VALUES ('$filename_esc', $user_id, '$user_name_esc', 0, 1, '$hospitals_json')")) {
        mysqli_rollback($con);
        return ['status' => 'error', 'message' => 'บันทึกข้อมูลไม่สำเร็จ: ' . mysqli_error($con)];
    }
    $upload_id = (int)mysqli_insert_id($con);

    $inserted = 0;
    $drugs = [];
    $stmt = mysqli_prepare(
        $con,
        'INSERT INTO network_drug_items (upload_id, tpu_code_list, name, generic_name, strength, tmtdrug, units, dosageform, hosname) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        mysqli_rollback($con);
        return ['status' => 'error', 'message' => 'เตรียมคำสั่ง SQL ไม่สำเร็จ'];
    }

    foreach ($prepared['by_hospital'] as $hosname => $hos_rows) {
        $hos_esc = mysqli_real_escape_string($con, $hosname);
        if (!mysqli_query($con, "DELETE FROM network_drug_items WHERE hosname = '$hos_esc'")) {
            mysqli_stmt_close($stmt);
            mysqli_rollback($con);
            return ['status' => 'error', 'message' => 'ลบข้อมูลเดิมของ รพ. ไม่สำเร็จ: ' . mysqli_error($con)];
        }

        foreach ($hos_rows as $row) {
            $tpu = $row['tpu_code_list'];
            $name = $row['name'];
            $generic = $row['generic_name'];
            $strength = $row['strength'];
            $tmtdrug = $row['tmtdrug'];
            $units = $row['units'];
            $dosageform = $row['dosageform'];
            $hos = $row['hosname'];

            mysqli_stmt_bind_param($stmt, 'issssssss', $upload_id, $tpu, $name, $generic, $strength, $tmtdrug, $units, $dosageform, $hos);
            if (!mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                mysqli_rollback($con);
                return ['status' => 'error', 'message' => 'นำเข้าข้อมูลไม่สำเร็จ: ' . mysqli_error($con)];
            }
            $inserted++;
            $drugs[$tmtdrug] = true;
        }
    }
    mysqli_stmt_close($stmt);

    $hospital_count = count($affected_hospitals);
    $drug_count = count($drugs);
    mysqli_query($con, "UPDATE network_drug_uploads SET row_count = $inserted, hospital_count = $hospital_count, drug_count = $drug_count WHERE id = $upload_id");
    mysqli_commit($con);

    return [
        'status' => 'success',
        'upload_id' => $upload_id,
        'row_count' => $inserted,
        'hospital_count' => $hospital_count,
        'drug_count' => $drug_count,
        'affected_hospitals' => $affected_hospitals,
        'overwritten_hospitals' => $overwritten,
        'new_hospitals' => $new_hospitals,
    ];
}

function nd_get_active_upload_id($con) {
    nd_ensure_tables($con);
    $q = mysqli_query($con, 'SELECT id FROM network_drug_uploads WHERE is_active = 1 ORDER BY uploaded_at DESC, id DESC LIMIT 1');
    if ($q && ($row = mysqli_fetch_assoc($q))) {
        return (int)$row['id'];
    }
    return 0;
}

function nd_list_uploads($con, $limit = 20) {
    nd_ensure_tables($con);
    $limit = max(1, min(100, (int)$limit));
    $rows = [];
    $q = mysqli_query($con, "SELECT id, filename, uploaded_by_name, row_count, hospital_count, drug_count, is_active, uploaded_at FROM network_drug_uploads ORDER BY uploaded_at DESC, id DESC LIMIT $limit");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $rows[] = $r;
        }
    }
    return $rows;
}

function nd_hospital_sort_key($name) {
    $n = trim((string)$name);
    if (preg_match('/^รพ\.?/u', $n)) {
        return '0_' . $n;
    }
    return '1_' . $n;
}

function nd_list_hospitals($con, $upload_id = 0) {
    nd_ensure_tables($con);
    $upload_id = (int)$upload_id;
    $where = "TRIM(hosname) <> ''";
    if ($upload_id > 0) {
        $where .= " AND upload_id = $upload_id";
    }
    $hospitals = [];
    $qh = mysqli_query($con, "SELECT DISTINCT hosname FROM network_drug_items WHERE $where ORDER BY hosname ASC");
    if ($qh) {
        while ($hr = mysqli_fetch_assoc($qh)) {
            $hospitals[] = $hr['hosname'];
        }
    }
    usort($hospitals, function ($a, $b) {
        return strcmp(nd_hospital_sort_key($a), nd_hospital_sort_key($b));
    });
    return $hospitals;
}

function nd_list_hospitals_with_stats($con) {
    nd_ensure_tables($con);
    $rows = [];
    $q = mysqli_query(
        $con,
        "SELECT hosname,
                COUNT(*) AS row_count,
                COUNT(DISTINCT tmtdrug) AS drug_count,
                MAX(created_at) AS last_updated
         FROM network_drug_items
         WHERE TRIM(hosname) <> ''
         GROUP BY hosname
         ORDER BY hosname ASC"
    );
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $rows[] = $r;
        }
    }
    usort($rows, function ($a, $b) {
        return strcmp(nd_hospital_sort_key($a['hosname']), nd_hospital_sort_key($b['hosname']));
    });
    return $rows;
}

function nd_delete_hospital($con, $hosname) {
    nd_ensure_tables($con);
    $hosname = nd_clean_cell($hosname);
    if ($hosname === '') {
        return ['status' => 'error', 'message' => 'กรุณาระบุชื่อโรงพยาบาล'];
    }
    $hos_esc = mysqli_real_escape_string($con, $hosname);
    $q = mysqli_query($con, "SELECT COUNT(*) AS c FROM network_drug_items WHERE hosname = '$hos_esc'");
    $count = 0;
    if ($q && ($r = mysqli_fetch_assoc($q))) {
        $count = (int)($r['c'] ?? 0);
    }
    if ($count === 0) {
        return ['status' => 'error', 'message' => 'ไม่พบข้อมูลของโรงพยาบาลนี้'];
    }
    if (!mysqli_query($con, "DELETE FROM network_drug_items WHERE hosname = '$hos_esc'")) {
        return ['status' => 'error', 'message' => 'ลบข้อมูลไม่สำเร็จ: ' . mysqli_error($con)];
    }
    return [
        'status' => 'success',
        'hosname' => $hosname,
        'deleted_rows' => $count,
    ];
}

function nd_global_stats($con) {
    nd_ensure_tables($con);
    $stats = ['row_count' => 0, 'drug_count' => 0, 'hospital_count' => 0, 'last_updated' => null];
    $q = mysqli_query(
        $con,
        "SELECT COUNT(*) AS row_count,
                COUNT(DISTINCT tmtdrug) AS drug_count,
                COUNT(DISTINCT hosname) AS hospital_count,
                MAX(created_at) AS last_updated
         FROM network_drug_items
         WHERE TRIM(hosname) <> ''"
    );
    if ($q && ($r = mysqli_fetch_assoc($q))) {
        $stats = [
            'row_count' => (int)($r['row_count'] ?? 0),
            'drug_count' => (int)($r['drug_count'] ?? 0),
            'hospital_count' => (int)($r['hospital_count'] ?? 0),
            'last_updated' => $r['last_updated'] ?? null,
        ];
    }
    return $stats;
}

function nd_fetch_matrix($con, $upload_id = 0, $search = '') {
    nd_ensure_tables($con);

    $where = "TRIM(hosname) <> ''";
    $search = trim((string)$search);
    if ($search !== '') {
        $s = mysqli_real_escape_string($con, $search);
        $where .= " AND (tmtdrug LIKE '%$s%' OR name LIKE '%$s%' OR generic_name LIKE '%$s%' OR tpu_code_list LIKE '%$s%')";
    }

    $hospitals = nd_list_hospitals($con, 0);

    $matrix = [];
    $q = mysqli_query($con, "SELECT tmtdrug, hosname FROM network_drug_items WHERE $where");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $drug = $r['tmtdrug'];
            $hos = $r['hosname'];
            if ($drug === '' || $hos === '') {
                continue;
            }
            if (!isset($matrix[$drug])) {
                $matrix[$drug] = [];
            }
            $matrix[$drug][$hos] = true;
        }
    }

    ksort($matrix, SORT_NATURAL | SORT_FLAG_CASE);
    $rows = [];
    foreach ($matrix as $tmtdrug => $hosMap) {
        $item = ['tmtdrug' => $tmtdrug, 'hospitals' => []];
        foreach ($hospitals as $hos) {
            $item['hospitals'][$hos] = !empty($hosMap[$hos]);
        }
        $rows[] = $item;
    }

    return [
        'status' => 'success',
        'upload_id' => 0,
        'stats' => nd_global_stats($con),
        'hospitals' => $hospitals,
        'rows' => $rows,
        'total_drugs' => count($rows),
    ];
}

function nd_fetch_detail_rows($con, $upload_id = 0, $search = '', $limit = 5000) {
    nd_ensure_tables($con);
    $limit = (int)$limit;
    $limitClause = '';
    if ($limit > 0) {
        $limit = max(1, min(20000, $limit));
        $limitClause = " LIMIT $limit";
    }
    $where = "TRIM(hosname) <> ''";
    $search = trim((string)$search);
    if ($search !== '') {
        $s = mysqli_real_escape_string($con, $search);
        $where .= " AND (tmtdrug LIKE '%$s%' OR name LIKE '%$s%' OR generic_name LIKE '%$s%' OR tpu_code_list LIKE '%$s%' OR hosname LIKE '%$s%')";
    }
    $rows = [];
    $q = mysqli_query($con, "SELECT tpu_code_list, name, generic_name, strength, tmtdrug, units, dosageform, hosname FROM network_drug_items WHERE $where ORDER BY tmtdrug ASC, hosname ASC$limitClause");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $rows[] = $r;
        }
    }
    return $rows;
}

function nd_hospitals_for_tmtdrug($con, $tmtdrug) {
    nd_ensure_tables($con);
    $tmtdrug = nd_clean_cell($tmtdrug);
    if ($tmtdrug === '') {
        return [];
    }
    $tmt_esc = mysqli_real_escape_string($con, $tmtdrug);
    $hospitals = [];
    $q = mysqli_query(
        $con,
        "SELECT DISTINCT hosname FROM network_drug_items
         WHERE TRIM(hosname) <> '' AND tmtdrug = '$tmt_esc'
         ORDER BY hosname ASC"
    );
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $hos = trim($r['hosname'] ?? '');
            if ($hos !== '') {
                $hospitals[] = $hos;
            }
        }
    }
    usort($hospitals, function ($a, $b) {
        return strcmp(nd_hospital_sort_key($a), nd_hospital_sort_key($b));
    });
    return $hospitals;
}

function nd_format_hospital_coverage_label(array $all_hospitals, array $present_hospitals) {
    $all = array_values(array_unique(array_filter(array_map('trim', $all_hospitals))));
    $present = array_values(array_unique(array_filter(array_map('trim', $present_hospitals))));
    if (empty($all)) {
        return $present ? implode(', ', $present) : '';
    }
    $present_set = array_flip($present);
    $missing = [];
    foreach ($all as $hos) {
        if (!isset($present_set[$hos])) {
            $missing[] = $hos;
        }
    }
    if (empty($missing)) {
        return 'ทุกรพ.';
    }
    if (count($missing) <= 2) {
        return 'ทุก รพ. ยกเว้น ' . implode(', ', $missing);
    }
    return implode(', ', $present);
}

function nd_fetch_network_tmtdrug_meta($con, $tmtdrug) {
    nd_ensure_tables($con);
    $tmtdrug = nd_clean_cell($tmtdrug);
    if ($tmtdrug === '') {
        return null;
    }
    $tmt_esc = mysqli_real_escape_string($con, $tmtdrug);
    $meta = [
        'tmtdrug' => $tmtdrug,
        'tpu_code_list' => '',
        'hospitals' => nd_hospitals_for_tmtdrug($con, $tmtdrug),
    ];
    $q = mysqli_query(
        $con,
        "SELECT tpu_code_list FROM network_drug_items
         WHERE tmtdrug = '$tmt_esc' AND TRIM(tpu_code_list) <> ''
         ORDER BY id ASC LIMIT 1"
    );
    if ($q && ($row = mysqli_fetch_assoc($q))) {
        $meta['tpu_code_list'] = trim($row['tpu_code_list'] ?? '');
    }
    $all_hospitals = nd_list_hospitals($con, 0);
    $meta['hospital_label'] = nd_format_hospital_coverage_label($all_hospitals, $meta['hospitals']);
    return $meta;
}

function nd_search_tmtdrugs($con, $search = '', $limit = 30) {
    nd_ensure_tables($con);
    $limit = max(1, min(50, (int)$limit));
    $where = "TRIM(hosname) <> '' AND TRIM(tmtdrug) <> ''";
    $search = trim((string)$search);
    if ($search !== '') {
        $s = mysqli_real_escape_string($con, $search);
        $where .= " AND (tmtdrug LIKE '%$s%' OR tpu_code_list LIKE '%$s%')";
    }
    $tmtdrugs = [];
    $q = mysqli_query(
        $con,
        "SELECT tmtdrug, MIN(NULLIF(TRIM(tpu_code_list), '')) AS tpu_code_list
         FROM network_drug_items
         WHERE $where
         GROUP BY tmtdrug
         ORDER BY tmtdrug ASC
         LIMIT $limit"
    );
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $tmt = trim($r['tmtdrug'] ?? '');
            if ($tmt === '') {
                continue;
            }
            $tmtdrugs[] = [
                'tmtdrug' => $tmt,
                'tpu_code_list' => trim($r['tpu_code_list'] ?? ''),
            ];
        }
    }
    $all_hospitals = nd_list_hospitals($con, 0);
    $results = [];
    foreach ($tmtdrugs as $item) {
        $hospitals = nd_hospitals_for_tmtdrug($con, $item['tmtdrug']);
        $label = nd_format_hospital_coverage_label($all_hospitals, $hospitals);
        $text = $item['tmtdrug'];
        if ($item['tpu_code_list'] !== '') {
            $text .= ' [' . $item['tpu_code_list'] . ']';
        }
        $text .= ' — ' . $label;
        $results[] = [
            'id' => $item['tmtdrug'],
            'text' => $text,
            'tmtdrug' => $item['tmtdrug'],
            'tpu_code_list' => $item['tpu_code_list'],
            'hospital_label' => $label,
            'hospitals' => $hospitals,
        ];
    }
    return $results;
}
