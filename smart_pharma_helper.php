<?php
/**
 * Smart Pharma — ระบบคัดกรองผู้ป่วย
 */
require_once __DIR__ . '/hosxp_tmt_drug_helper.php';
require_once __DIR__ . '/monitor_hla_helper.php';

/** เวลาไทย — HOSxP บันทึก rxtime เป็น Asia/Bangkok */
function sp_timezone() {
    static $tz = null;
    if ($tz === null) {
        $tz = new DateTimeZone('Asia/Bangkok');
    }
    return $tz;
}

function sp_now_datetime() {
    return new DateTime('now', sp_timezone());
}

function sp_now_timestamp() {
    return sp_now_datetime()->getTimestamp();
}

function sp_today_date() {
    return sp_now_datetime()->format('Y-m-d');
}

/** Session cache สำหรับลดเวลาเมื่อสลับแท็บช่วงเวลา (TTL วินาที) */
function sp_cache_ttl($screen_date) {
    return sp_is_screen_date_today($screen_date) ? 60 : 600;
}

function sp_cache_get($key, $ttl = 600) {
    if (!isset($_SESSION['sp_perf_cache'][$key])) {
        return null;
    }
    $entry = $_SESSION['sp_perf_cache'][$key];
    if (time() - (int)($entry['t'] ?? 0) > $ttl) {
        unset($_SESSION['sp_perf_cache'][$key]);
        return null;
    }
    return $entry['data'] ?? null;
}

function sp_cache_set($key, $data) {
    if (!isset($_SESSION['sp_perf_cache'])) {
        $_SESSION['sp_perf_cache'] = [];
    }
    $_SESSION['sp_perf_cache'][$key] = ['t' => time(), 'data' => $data];
}

function sp_extract_hn_list(array $patients) {
    $hns = [];
    foreach ($patients as $p) {
        $hn = trim((string)($p['hn'] ?? ''));
        if ($hn !== '') {
            $hns[$hn] = true;
        }
    }
    return array_keys($hns);
}

function sp_sql_drug_order_join($o = 'o', $d = 'd') {
    return "INNER JOIN drugitems $d ON $d.icode = $o.icode AND $d.istatus = 'Y'";
}

function sp_ensure_tables($con) {
    $sql = @file_get_contents(__DIR__ . '/sql/smart_pharma.sql');
    if (!$sql) {
        return false;
    }
    if (!mysqli_multi_query($con, $sql)) {
        return false;
    }
    while (mysqli_more_results($con)) {
        mysqli_next_result($con);
    }
    require_once __DIR__ . '/smart_pharma_telegram_helper.php';
    sp_telegram_ensure_tables($con);
    require_once __DIR__ . '/smart_pharma_webhook_helper.php';
    sp_webhook_ensure_tables($con);
    require_once __DIR__ . '/smart_pharma_alert_log_helper.php';
    sp_alert_log_ensure_tables($con);
    return true;
}

function sp_get_modules($con, $patient_type = null) {
    $out = [];
    $q = mysqli_query($con, "SELECT * FROM smart_pharma_modules ORDER BY sort_order ASC, label_th ASC");
    if (!$q) {
        return $out;
    }
    while ($r = mysqli_fetch_assoc($q)) {
        if (empty($r['enabled'])) {
            continue;
        }
        if ($patient_type === 'OPD' && empty($r['enabled_opd'])) {
            continue;
        }
        if ($patient_type === 'IPD' && empty($r['enabled_ipd'])) {
            continue;
        }
        $out[] = $r;
    }
    return $out;
}

function sp_save_module_settings($con, array $rows) {
    foreach ($rows as $key => $data) {
        $key_esc = mysqli_real_escape_string($con, $key);
        $en = !empty($data['enabled']) ? 1 : 0;
        $opd = !empty($data['enabled_opd']) ? 1 : 0;
        $ipd = !empty($data['enabled_ipd']) ? 1 : 0;
        mysqli_query($con, "UPDATE smart_pharma_modules SET enabled=$en, enabled_opd=$opd, enabled_ipd=$ipd WHERE module_key='$key_esc'");
    }
}

function sp_hn_in_sql($hos, $hn) {
    return monitor_lab_hn_sql_in($hos, $hn);
}

/** รายชื่อผู้ป่วยนอก (OPD) ตามวัน visit — ใช้ ovst (มี vsttime) ไม่ใช่ vn_stat */
function sp_fetch_opd_patients($hos, $date) {
    $date_esc = mysqli_real_escape_string($hos, $date);
    $ptname_sql = "CAST(CONVERT(CONCAT(IFNULL(p.pname,''), IFNULL(p.fname,''), ' ', IFNULL(p.lname,'')) USING utf8) AS CHAR)";

    $queries = [
        "SELECT o.vn, o.hn, o.vstdate, o.vsttime, $ptname_sql AS ptname,
                'OPD' AS patient_type, '' AS ward_name, '' AS bedno, '' AS an
            FROM ovst o
            INNER JOIN patient p ON p.hn = o.hn
            WHERE o.vstdate = '$date_esc'
              AND (o.an IS NULL OR o.an = '')
            ORDER BY o.vsttime DESC",
        "SELECT v.vn, v.hn, v.vstdate, '' AS vsttime, $ptname_sql AS ptname,
                'OPD' AS patient_type, '' AS ward_name, '' AS bedno, '' AS an
            FROM vn_stat v
            INNER JOIN patient p ON p.hn = v.hn
            WHERE v.vstdate = '$date_esc'
            GROUP BY v.vn, v.hn, v.vstdate
            ORDER BY v.vn DESC",
    ];

    foreach ($queries as $sql) {
        $q = mysqli_query($hos, $sql);
        if (!$q) {
            continue;
        }
        $list = [];
        while ($r = mysqli_fetch_assoc($q)) {
            $list[] = $r;
        }
        if (!empty($list)) {
            return $list;
        }
    }
    return [];
}

/** OPD ที่มียาสั่งจริง — query เดียว ไม่ดึง ovst ทั้งวัน */
function sp_fetch_opd_patients_with_drugs($hos, $date) {
    $date_esc = mysqli_real_escape_string($hos, $date);
    $ptname_sql = "CAST(CONVERT(CONCAT(IFNULL(p.pname,''), IFNULL(p.fname,''), ' ', IFNULL(p.lname,'')) USING utf8) AS CHAR)";

    $sql = "SELECT o.vn, o.hn, o.vstdate,
            IFNULL(ov.vsttime, '') AS vsttime,
            o.rxdate, o.rxtime,
            $ptname_sql AS ptname,
            'OPD' AS patient_type, '' AS ward_name, '' AS bedno, '' AS an
        FROM opitemrece o
        " . sp_sql_drug_order_join('o', 'd') . "
        INNER JOIN patient p ON p.hn = o.hn
        LEFT JOIN ovst ov ON ov.vn = o.vn AND ov.vstdate = o.vstdate
        WHERE o.vstdate = '$date_esc' AND o.qty > 0
          AND o.rxdate IS NOT NULL AND o.rxdate != '0000-00-00'
          AND o.rxtime IS NOT NULL AND TRIM(o.rxtime) != ''
        ORDER BY o.rxdate DESC, o.rxtime DESC, o.item_no DESC";

    $q = mysqli_query($hos, $sql);
    $seen_vn = [];
    $list = [];
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $vn = trim((string)($r['vn'] ?? ''));
            if ($vn === '' || isset($seen_vn[$vn])) {
                continue;
            }
            $seen_vn[$vn] = true;
            $list[] = $r;
        }
    }
    return $list;
}

/**
 * ดึงผู้ป่วย OPD รายคนตาม HN (visit ล่าสุดที่มียาสั่งในวันนั้น หรือระบุ VN)
 * @return array|null
 */
function sp_fetch_opd_patient_by_hn($hos, $hn, $date, $vn = '') {
    $hn = trim((string)$hn);
    if ($hn === '') {
        return null;
    }
    $date_esc = mysqli_real_escape_string($hos, $date);
    $hn_in = sp_hn_in_sql($hos, $hn);
    $vn_clause = '';
    if (trim((string)$vn) !== '') {
        $vn_clause = "AND o.vn = '" . mysqli_real_escape_string($hos, trim($vn)) . "'";
    }
    $ptname_sql = "CAST(CONVERT(CONCAT(IFNULL(p.pname,''), IFNULL(p.fname,''), ' ', IFNULL(p.lname,'')) USING utf8) AS CHAR)";
    $sql = "SELECT o.vn, o.hn, o.vstdate,
            IFNULL(ov.vsttime, '') AS vsttime,
            o.rxdate, o.rxtime,
            $ptname_sql AS ptname,
            'OPD' AS patient_type, '' AS ward_name, '' AS bedno, '' AS an
        FROM opitemrece o
        " . sp_sql_drug_order_join('o', 'd') . "
        INNER JOIN patient p ON p.hn = o.hn
        LEFT JOIN ovst ov ON ov.vn = o.vn AND ov.vstdate = o.vstdate
        WHERE o.hn IN ($hn_in) AND o.vstdate = '$date_esc' AND o.qty > 0
          AND o.rxdate IS NOT NULL AND o.rxdate != '0000-00-00'
          AND o.rxtime IS NOT NULL AND TRIM(o.rxtime) != ''
          $vn_clause
        ORDER BY o.rxdate DESC, o.rxtime DESC, o.item_no DESC
        LIMIT 1";
    $q = mysqli_query($hos, $sql);
    return ($q && ($r = mysqli_fetch_assoc($q))) ? $r : null;
}

/**
 * ดึงผู้ป่วย IPD รายคนตาม HN (admit อยู่ ณ วันที่กำหนด หรือระบุ AN)
 * @return array|null
 */
function sp_fetch_ipd_patient_by_hn($hos, $hn, $date, $an = '') {
    $hn = trim((string)$hn);
    if ($hn === '') {
        return null;
    }
    $all = sp_fetch_ipd_patients($hos, $date);
    $hn_norm = ltrim($hn, '0');
    foreach ($all as $p) {
        $p_hn = ltrim(trim((string)($p['hn'] ?? '')), '0');
        if ($p_hn !== $hn_norm && trim((string)($p['hn'] ?? '')) !== $hn) {
            continue;
        }
        if (trim((string)$an) !== '' && trim((string)($p['an'] ?? '')) !== trim($an)) {
            continue;
        }
        return $p;
    }
    return null;
}

function sp_screen_api_get_key($con) {
    $q = mysqli_query($con, "SELECT config_value FROM sys_config WHERE config_name='sp_screen_api_key' LIMIT 1");
    if ($q && ($r = mysqli_fetch_assoc($q))) {
        return trim((string)($r['config_value'] ?? ''));
    }
    return '';
}

function sp_screen_api_save_key($con, $key) {
    $key_esc = mysqli_real_escape_string($con, trim((string)$key));
    return mysqli_query($con, "INSERT INTO sys_config (config_name, config_value) VALUES ('sp_screen_api_key', '$key_esc')
        ON DUPLICATE KEY UPDATE config_value = '$key_esc'");
}

function sp_screen_api_verify($con, $key) {
    $stored = sp_screen_api_get_key($con);
    if ($stored === '') {
        return false;
    }
    return hash_equals($stored, trim((string)$key));
}

/**
 * คัดกรองผู้ป่วยรายคนตาม HN
 * @return array
 */
function sp_run_screening_hn($con, $hos, $patient_type, $screen_date, $hn, $vn = '', $an = '', array $options = []) {
    require_once __DIR__ . '/smart_pharma_alert_log_helper.php';
    require_once __DIR__ . '/smart_pharma_telegram_helper.php';
    require_once __DIR__ . '/smart_pharma_webhook_helper.php';

    $notify = !array_key_exists('notify', $options) || !empty($options['notify']);
    $patient_type = $patient_type === 'IPD' ? 'IPD' : 'OPD';
    $hn = trim((string)$hn);
    if ($hn === '') {
        return ['ok' => false, 'message' => 'กรุณาระบุ HN'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $screen_date)) {
        $screen_date = sp_today_date();
    }

    $modules = sp_get_modules($con, $patient_type);
    if (empty($modules)) {
        return ['ok' => false, 'message' => 'ยังไม่ได้เปิดโมดูลคัดกรอง'];
    }

    if ($patient_type === 'OPD') {
        $pat = sp_fetch_opd_patient_by_hn($hos, $hn, $screen_date, $vn);
        if (!$pat) {
            return ['ok' => false, 'message' => 'ไม่พบผู้ป่วยนอกหรือยังไม่มียาสั่งในวันที่เลือก'];
        }
    } else {
        $pat = sp_fetch_ipd_patient_by_hn($hos, $hn, $screen_date, $an);
        if (!$pat) {
            return ['ok' => false, 'message' => 'ไม่พบผู้ป่วยในที่ admit อยู่ในวันที่เลือก'];
        }
        $enriched = sp_enrich_patients_rx_time($hos, [$pat], $screen_date);
        $with_drugs = sp_filter_patients_with_drug_orders($enriched);
        if (empty($with_drugs)) {
            return ['ok' => false, 'message' => 'พบผู้ป่วยในแต่ยังไม่มียาสั่งในวันที่เลือก'];
        }
        $pat = $with_drugs[0];
    }

    $di_batch = null;
    foreach ($modules as $mod) {
        if (($mod['module_key'] ?? '') === 'di') {
            $di_batch = sp_batch_screen_di_for_date($con, $hos, $screen_date, sp_extract_hn_list([$pat]));
            break;
        }
    }
    $screen_ctx = sp_build_screen_cache($con, $hos, [$pat], $screen_date, $modules);
    $screen = sp_screen_patient($con, $hos, $pat, $modules, $di_batch, $screen_date, $screen_ctx);
    $row = sp_build_screened_patient_row($pat, $screen, $patient_type, $screen_date, $modules);
    $alert_log = sp_alert_log_sync($con, $patient_type, $screen_date, [$row]);

    $module_labels = [];
    $module_cols = [];
    foreach ($modules as $mod) {
        $module_labels[$mod['module_key']] = $mod['label_th'];
        $module_cols[] = [
            'key' => $mod['module_key'],
            'label' => $mod['label_th'],
            'icon' => $mod['icon'],
            'color' => $mod['color'],
        ];
    }

    $telegram_result = ['sent' => 0, 'skipped' => 0, 'errors' => []];
    $webhook_result = ['sent' => 0, 'skipped' => 0, 'errors' => []];
    if ($notify) {
        $telegram_result = sp_telegram_notify_screening($con, $patient_type, $screen_date, [$row], $module_labels);
        $webhook_result = sp_webhook_notify_screening($con, $patient_type, $screen_date, [$row], $module_labels);
    }

    $alerts = [];
    foreach ($row['modules'] ?? [] as $mk => $mod) {
        if (($mod['status'] ?? '') === 'alert') {
            $alerts[] = [
                'module_key' => $mk,
                'module_label' => $module_labels[$mk] ?? $mk,
                'level' => $mod['level'] ?? 'soft',
                'summary' => $mod['summary'] ?? '',
            ];
        }
    }

    return [
        'ok' => true,
        'message' => ($row['overall'] ?? '') === 'alert'
            ? 'พบ Alert ' . count($alerts) . ' รายการ'
            : 'คัดกรองผ่าน — ไม่พบ Alert',
        'patient' => $row,
        'modules' => $module_cols,
        'alerts' => $alerts,
        'alert_count' => count($alerts),
        'has_alert' => ($row['overall'] ?? '') === 'alert',
        'alert_log' => $alert_log,
        'telegram' => $telegram_result,
        'webhook' => $webhook_result,
    ];
}

/** รายชื่อผู้ป่วยใน (IPD) ที่ยัง Admit อยู่ ณ วันที่กำหนด */
function sp_fetch_ipd_patients($hos, $date) {
    $date_esc = mysqli_real_escape_string($hos, $date);
    $ptname_sql = "CAST(CONVERT(CONCAT(IFNULL(p.pname,''), IFNULL(p.fname,''), ' ', IFNULL(p.lname,'')) USING utf8) AS CHAR)";
    $bed_sql = "(SELECT adm.bedno FROM iptadm adm
            WHERE adm.an = ia.an
              AND adm.indate <= '$date_esc'
              AND (adm.outdate IS NULL OR adm.outdate = '0000-00-00' OR adm.outdate >= '$date_esc')
            ORDER BY adm.indate DESC, adm.intime DESC
            LIMIT 1)";

    $queries = [
        "SELECT ia.an, ia.hn, IFNULL(ia.vn, '') AS vn,
                COALESCE(NULLIF(ia.regdate,'0000-00-00'), '$date_esc') AS vstdate,
                IFNULL(ia.regtime, '') AS vsttime,
                $ptname_sql AS ptname,
                'IPD' AS patient_type,
                CAST(CONVERT(IFNULL(w.name, '') USING utf8) AS CHAR) AS ward_name,
                IFNULL($bed_sql, IFNULL(ia.cur_bedno, '')) AS bedno
            FROM ipt ia
            INNER JOIN patient p ON p.hn = ia.hn
            LEFT JOIN ward w ON w.ward = ia.ward
            WHERE (ia.dchdate IS NULL OR ia.dchdate = '0000-00-00' OR ia.dchdate >= '$date_esc')
              AND ia.regdate IS NOT NULL
              AND ia.regdate != '0000-00-00'
              AND ia.regdate <= '$date_esc'
            ORDER BY ward_name, bedno, ia.an",
        "SELECT ia.an, ia.hn, IFNULL(ia.vn, '') AS vn,
                COALESCE(NULLIF(ia.regdate,'0000-00-00'), '$date_esc') AS vstdate,
                IFNULL(ia.regtime, '') AS vsttime,
                $ptname_sql AS ptname,
                'IPD' AS patient_type,
                CAST(CONVERT(IFNULL(w2.name, IFNULL(w1.name, '')) USING utf8) AS CHAR) AS ward_name,
                IFNULL($bed_sql, '') AS bedno
            FROM ipt ia
            INNER JOIN patient p ON p.hn = ia.hn
            LEFT JOIN ward_admit_snapshot wa ON wa.an = ia.an
            LEFT JOIN ward w1 ON w1.ward = wa.ward
            LEFT JOIN ward w2 ON w2.ward = ia.ward
            WHERE (ia.dchdate IS NULL OR ia.dchdate = '0000-00-00' OR ia.dchdate >= '$date_esc')
              AND ia.regdate IS NOT NULL
              AND ia.regdate != '0000-00-00'
              AND ia.regdate <= '$date_esc'
            ORDER BY ward_name, bedno, ia.an",
    ];

    foreach ($queries as $sql) {
        $q = mysqli_query($hos, $sql);
        if (!$q) {
            continue;
        }
        $list = [];
        while ($r = mysqli_fetch_assoc($q)) {
            $list[] = $r;
        }
        if (!empty($list)) {
            return $list;
        }
    }
    return [];
}

function sp_patient_icodes_on_date($hos, $hn, $vstdate, $vn = '') {
    $hn_esc = mysqli_real_escape_string($hos, $hn);
    $date_esc = mysqli_real_escape_string($hos, $vstdate);
    $vn_clause = '';
    if ($vn !== '') {
        $vn_clause = "AND o.vn = '" . mysqli_real_escape_string($hos, $vn) . "'";
    }
    $sql = "SELECT DISTINCT o.icode FROM opitemrece o
        WHERE o.hn = '$hn_esc' AND o.vstdate = '$date_esc' AND o.qty > 0 $vn_clause";
    $q = mysqli_query($hos, $sql);
    $codes = [];
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $c = trim($r['icode'] ?? '');
            if ($c !== '') {
                $codes[] = $c;
            }
        }
    }
    return $codes;
}

function sp_result_pass($summary = '') {
    return ['status' => 'pass', 'level' => '', 'summary' => $summary ?: 'ผ่าน', 'details' => [], 'items' => []];
}

function sp_result_alert($level, $summary, array $details = [], array $items = []) {
    if (empty($details) && $summary !== '') {
        $details = array_values(array_filter(array_map('trim', preg_split('/\s*;\s*/', $summary))));
    }
    return [
        'status' => 'alert',
        'level' => $level ?: 'soft',
        'summary' => $summary,
        'details' => $details,
        'items' => $items,
    ];
}

function sp_result_na($summary = 'ไม่เกี่ยวข้อง') {
    return ['status' => 'na', 'level' => '', 'summary' => $summary, 'details' => [], 'items' => []];
}

function sp_patient_screen_key($patient, $screen_date = '') {
    $hn = trim((string)($patient['hn'] ?? ''));
    $vn = trim((string)($patient['vn'] ?? ''));
    $ptype = trim((string)($patient['patient_type'] ?? ''));
    $date = trim((string)$screen_date);
    if ($date === '') {
        if ($ptype === 'IPD') {
            $date = trim((string)($patient['rxdate'] ?? ''));
        }
        if ($date === '') {
            $date = trim((string)($patient['vstdate'] ?? ''));
        }
    }
    return $hn . '|' . $vn . '|' . $date;
}

function sp_patient_screen_key_variants($patient, $screen_date = '') {
    $hn = trim((string)($patient['hn'] ?? ''));
    if ($hn === '') {
        return [];
    }
    $vn = trim((string)($patient['vn'] ?? ''));
    $ptype = trim((string)($patient['patient_type'] ?? ''));
    $date = trim((string)$screen_date);
    if ($date === '') {
        if ($ptype === 'IPD') {
            $date = trim((string)($patient['rxdate'] ?? ''));
        }
        if ($date === '') {
            $date = trim((string)($patient['vstdate'] ?? ''));
        }
    }
    $keys = [$hn . '|' . $vn . '|' . $date];
    if ($vn !== '') {
        $keys[] = $hn . '||' . $date;
    }
    return array_values(array_unique($keys));
}

/** คีย์สำหรับ lookup DI batch — IPD ใช้วันที่คัดกรอง ไม่ใช่ regdate */
function sp_is_screen_date_today($screen_date) {
    return trim((string)$screen_date) === sp_today_date();
}

function sp_slot_time_windows() {
    return ['00-08', '08-16', '16-24', 'full'];
}

function sp_live_time_windows() {
    return ['30m', '1h', '3h'];
}

function sp_allowed_time_windows($screen_date) {
    if (sp_is_screen_date_today($screen_date)) {
        return array_merge(sp_live_time_windows(), sp_slot_time_windows(), ['custom']);
    }
    return sp_slot_time_windows();
}

function sp_time_window_label($window, $time_from = '', $time_to = '') {
    $labels = [
        '30m' => '30 นาทีล่าสุด',
        '1h' => '1 ชั่วโมงล่าสุด',
        '3h' => '3 ชั่วโมงล่าสุด',
        '00-08' => '00:00–08:00',
        '08-16' => '08:01–16:00',
        '16-24' => '16:01–24:00',
        'full' => 'ทั้งวัน',
        'custom' => 'ช่วงเวลาที่เลือก',
    ];
    if ($window === 'custom' && $time_from !== '' && $time_to !== '') {
        return sp_format_time_hm($time_from) . '–' . sp_format_time_hm($time_to);
    }
    return $labels[$window] ?? $window;
}

function sp_normalize_time_window($window, $screen_date = null) {
    $screen_date = $screen_date ?? date('Y-m-d');
    $allowed = sp_allowed_time_windows($screen_date);
    if (in_array($window, $allowed, true)) {
        return $window;
    }
    return sp_is_screen_date_today($screen_date) ? '1h' : 'full';
}

/** แปลง HH:MM หรือ HH:MM:SS เป็นวินาทีในวัน */
function sp_time_to_seconds_of_day($time) {
    $time = trim((string)$time);
    if ($time === '') {
        return null;
    }
    if (strlen($time) === 5) {
        $time .= ':00';
    }
    if (!preg_match('/^(\d{1,2}):(\d{2}):(\d{2})$/', $time, $m)) {
        return null;
    }
    $h = (int)$m[1];
    $min = (int)$m[2];
    $s = (int)$m[3];
    if ($h > 23 || $min > 59 || $s > 59) {
        return null;
    }
    return ($h * 3600) + ($min * 60) + $s;
}

function sp_format_time_hm($time) {
    $sec = sp_time_to_seconds_of_day($time);
    if ($sec === null) {
        return trim((string)$time);
    }
    $h = (int)floor($sec / 3600);
    $m = (int)floor(($sec % 3600) / 60);
    return sprintf('%02d:%02d', $h, $m);
}

function sp_normalize_custom_time_range($time_from, $time_to) {
    $from = sp_time_to_seconds_of_day($time_from);
    $to = sp_time_to_seconds_of_day($time_to);
    if ($from === null || $to === null) {
        return null;
    }
    return ['from' => $from, 'to' => $to, 'from_raw' => sp_format_time_hm($time_from), 'to_raw' => sp_format_time_hm($time_to)];
}

/** กรองตามช่วงเวลาสั่งยาในวัน (HH:MM–HH:MM) */
function sp_filter_patients_by_custom_time(array $patients, $time_from, $time_to) {
    $range = sp_normalize_custom_time_range($time_from, $time_to);
    if ($range === null) {
        return $patients;
    }
    $from = $range['from'];
    $to = $range['to'];
    $out = [];
    foreach ($patients as $p) {
        $sec = sp_patient_rx_seconds_of_day($p);
        if ($sec === null) {
            continue;
        }
        if ($from <= $to) {
            if ($sec >= $from && $sec <= $to) {
                $out[] = $p;
            }
        } elseif ($sec >= $from || $sec <= $to) {
            $out[] = $p;
        }
    }
    return $out;
}

/** วันที่/เวลาสั่งยาล่าสุด — rxdate/rxtime ก่อน vstdate/vsttime */
function sp_patient_order_datetime(array $patient) {
    $date = trim((string)($patient['rxdate'] ?? ''));
    if ($date === '' || $date === '0000-00-00') {
        $date = trim((string)($patient['vstdate'] ?? ''));
    }
    $time = trim((string)($patient['rxtime'] ?? ''));
    if ($time === '') {
        $time = trim((string)($patient['vsttime'] ?? ''));
    }
    return ['date' => $date, 'time' => $time];
}

function sp_patient_rx_timestamp(array $patient) {
    $d = trim((string)($patient['rxdate'] ?? ''));
    if ($d === '' || $d === '0000-00-00') {
        return null;
    }
    $t = trim((string)($patient['rxtime'] ?? ''));
    if ($t === '') {
        return null;
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

function sp_patient_rx_seconds_of_day(array $patient) {
    $t = trim((string)($patient['rxtime'] ?? ''));
    if ($t === '') {
        return null;
    }
    if (strlen($t) === 5) {
        $t .= ':00';
    }
    if (!preg_match('/^(\d{1,2}):(\d{2}):(\d{2})$/', $t, $m)) {
        return null;
    }
    return ((int)$m[1] * 3600) + ((int)$m[2] * 60) + (int)$m[3];
}

/** แสดงผล — fallback vst ถ้าไม่มียาสั่ง */
function sp_patient_order_timestamp(array $patient) {
    return sp_patient_rx_timestamp($patient) ?: (function ($p) {
        $d = trim((string)($p['vstdate'] ?? ''));
        if ($d === '' || $d === '0000-00-00') {
            return null;
        }
        $t = trim((string)($p['vsttime'] ?? '00:00:00')) ?: '00:00:00';
        if (strlen($t) === 5) {
            $t .= ':00';
        }
        $ts = strtotime($d . ' ' . $t);
        return $ts ?: null;
    })($patient);
}

/** เติม rxdate/rxtime ล่าสุดจาก opitemrece ต่อ visit */
function sp_enrich_patients_rx_time($hos, array $patients, $screen_date) {
    if (empty($patients)) {
        return $patients;
    }
    $date_esc = mysqli_real_escape_string($hos, $screen_date);
    $sql = "SELECT o.hn, o.vn, IFNULL(o.an, '') AS an, o.rxdate, o.rxtime
        FROM opitemrece o
        " . sp_sql_drug_order_join('o', 'd') . "
        WHERE o.vstdate = '$date_esc' AND o.qty > 0
          AND o.rxdate IS NOT NULL AND o.rxdate != '0000-00-00'
          AND o.rxtime IS NOT NULL AND TRIM(o.rxtime) != ''
        ORDER BY o.rxdate DESC, o.rxtime DESC, o.item_no DESC";
    $q = mysqli_query($hos, $sql);
    $by_vn = [];
    $by_an = [];
    $by_hn = [];
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $hn = trim($r['hn'] ?? '');
            $vn = trim($r['vn'] ?? '');
            $an = trim($r['an'] ?? '');
            if ($vn !== '' && !isset($by_vn[$vn])) {
                $by_vn[$vn] = $r;
            }
            if ($an !== '' && !isset($by_an[$an])) {
                $by_an[$an] = $r;
            }
            if ($hn !== '' && !isset($by_hn[$hn])) {
                $by_hn[$hn] = $r;
            }
        }
    }
    foreach ($patients as &$p) {
        $vn = trim($p['vn'] ?? '');
        $an = trim($p['an'] ?? '');
        $hn = trim($p['hn'] ?? '');
        $rx = null;
        if ($vn !== '' && isset($by_vn[$vn])) {
            $rx = $by_vn[$vn];
        } elseif ($an !== '' && isset($by_an[$an])) {
            $rx = $by_an[$an];
        } elseif ($hn !== '' && isset($by_hn[$hn])) {
            $rx = $by_hn[$hn];
        }
        if ($rx) {
            $p['rxdate'] = $rx['rxdate'];
            $p['rxtime'] = $rx['rxtime'];
        } else {
            $p['rxdate'] = '';
            $p['rxtime'] = '';
        }
    }
    unset($p);
    return $patients;
}

/** คัดเฉพาะผู้ป่วยที่มีรายการยาจริง (มี rxdate/rxtime จาก drugitems) */
function sp_filter_patients_with_drug_orders(array $patients) {
    return array_values(array_filter($patients, function ($p) {
        $d = trim((string)($p['rxdate'] ?? ''));
        $t = trim((string)($p['rxtime'] ?? ''));
        return $d !== '' && $d !== '0000-00-00' && $t !== '';
    }));
}

function sp_patient_in_time_slot($seconds, $slot) {
    $s8 = 8 * 3600;
    $s16 = 16 * 3600;
    $eod = (24 * 3600) - 1;
    switch ($slot) {
        case '00-08':
            return $seconds >= 0 && $seconds <= $s8;
        case '08-16':
            return $seconds > $s8 && $seconds <= $s16;
        case '16-24':
            return $seconds > $s16 && $seconds <= $eod;
        default:
            return false;
    }
}

/** กรองรายชื่อผู้ป่วยตามช่วงเวลาสั่งยา (rxdate/rxtime) */
function sp_filter_patients_by_time_window(array $patients, $screen_date, $window) {
    $screen_date = trim((string)$screen_date);
    $window = sp_normalize_time_window($window, $screen_date);

    if ($window === 'full') {
        return $patients;
    }

    $out = [];
    if (sp_is_screen_date_today($screen_date) && in_array($window, ['30m', '1h', '3h'], true)) {
        $mins_map = ['30m' => 30, '1h' => 60, '3h' => 180];
        $end = sp_now_timestamp();
        $start = $end - ($mins_map[$window] * 60);
        foreach ($patients as $p) {
            $ts = sp_patient_rx_timestamp($p);
            if ($ts === null) {
                continue;
            }
            if ($ts >= $start && $ts <= $end) {
                $out[] = $p;
            }
        }
        return $out;
    }

    if (in_array($window, ['00-08', '08-16', '16-24'], true)) {
        foreach ($patients as $p) {
            $sec = sp_patient_rx_seconds_of_day($p);
            if ($sec === null) {
                continue;
            }
            if (sp_patient_in_time_slot($sec, $window)) {
                $out[] = $p;
            }
        }
        return $out;
    }

    return $patients;
}

/** icode ต่อ visit — query เดียวสำหรับทุกคนในวัน */
function sp_batch_patient_icodes_map($hos, $screen_date, array $patients) {
    $vns = [];
    $ans = [];
    $hns = [];
    $by_vn = [];
    $by_an = [];
    $by_hn = [];
    foreach ($patients as $p) {
        $vn = trim((string)($p['vn'] ?? ''));
        $an = trim((string)($p['an'] ?? ''));
        $hn = trim((string)($p['hn'] ?? ''));
        $keys = sp_patient_screen_key_variants($p, $screen_date);
        if ($vn !== '') {
            $vns[$vn] = true;
            if (!isset($by_vn[$vn])) {
                $by_vn[$vn] = [];
            }
            foreach ($keys as $k) {
                $by_vn[$vn][$k] = true;
            }
        }
        if ($an !== '') {
            $ans[$an] = true;
            if (!isset($by_an[$an])) {
                $by_an[$an] = [];
            }
            foreach ($keys as $k) {
                $by_an[$an][$k] = true;
            }
        }
        if ($hn !== '') {
            $hns[$hn] = true;
            if (!isset($by_hn[$hn])) {
                $by_hn[$hn] = [];
            }
            foreach ($keys as $k) {
                $by_hn[$hn][$k] = true;
            }
        }
    }
    if (empty($vns) && empty($ans) && empty($hns)) {
        return [];
    }
    $date_esc = mysqli_real_escape_string($hos, $screen_date);
    $where_parts = [];
    if (!empty($vns)) {
        $vn_in = [];
        foreach (array_keys($vns) as $vn) {
            $vn_in[] = "'" . mysqli_real_escape_string($hos, $vn) . "'";
        }
        $where_parts[] = "(o.vn <> '' AND o.vn IN (" . implode(',', $vn_in) . "))";
    }
    if (!empty($ans)) {
        $an_in = [];
        foreach (array_keys($ans) as $an) {
            $an_in[] = "'" . mysqli_real_escape_string($hos, $an) . "'";
        }
        $where_parts[] = "(o.an <> '' AND o.an IN (" . implode(',', $an_in) . "))";
    }
    if (!empty($hns)) {
        $hn_in = [];
        foreach (array_keys($hns) as $hn) {
            $hn_in[] = "'" . mysqli_real_escape_string($hos, $hn) . "'";
        }
        $where_parts[] = "(o.hn IN (" . implode(',', $hn_in) . "))";
    }
    if (empty($where_parts)) {
        return [];
    }
    $sql = "SELECT o.vn, o.an, o.hn, o.icode FROM opitemrece o
        " . sp_sql_drug_order_join('o', 'd') . "
        WHERE o.vstdate = '$date_esc' AND o.qty > 0
          AND (" . implode(' OR ', $where_parts) . ")";
    $q = mysqli_query($hos, $sql);
    $map = [];
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $vn = trim((string)($r['vn'] ?? ''));
            $an = trim((string)($r['an'] ?? ''));
            $hn = trim((string)($r['hn'] ?? ''));
            $c = trim((string)($r['icode'] ?? ''));
            if ($c === '') {
                continue;
            }
            $targets = [];
            if ($vn !== '' && isset($by_vn[$vn])) {
                $targets = array_merge($targets, array_keys($by_vn[$vn]));
            }
            if ($an !== '' && isset($by_an[$an])) {
                $targets = array_merge($targets, array_keys($by_an[$an]));
            }
            if ($hn !== '' && isset($by_hn[$hn])) {
                $targets = array_merge($targets, array_keys($by_hn[$hn]));
            }
            $targets = array_values(array_unique($targets));
            foreach ($targets as $key) {
                if (!isset($map[$key])) {
                    $map[$key] = [];
                }
                $map[$key][$c] = true;
            }
        }
    }
    foreach ($map as $k => $codes) {
        $map[$k] = array_keys($codes);
    }
    return $map;
}

/** HLA alert ทั้งวัน — query เดียว */
function sp_batch_hla_alert_map($con, $hos, $screen_date) {
    ensure_monitor_hla_alert_level_column($con);
    $drug_settings = hla_load_drug_settings($con);
    if (empty($drug_settings)) {
        return ['no_settings' => true, 'icodes' => [], 'alerts' => [], 'alert_levels' => []];
    }
    $hla_icodes = array_fill_keys(array_keys($drug_settings), true);

    $cfg = [];
    $qc = mysqli_query($con, "SELECT config_name, config_value FROM sys_config WHERE config_name LIKE 'hla_%'");
    if ($qc) {
        while ($r = mysqli_fetch_assoc($qc)) {
            $cfg[$r['config_name']] = $r['config_value'];
        }
    }
    $lb = (int)($cfg['hla_lookback_months'] ?? 24);
    $lab_in = monitor_lab_codes_sql_in($hos, $cfg['hla_lab_items_code'] ?? '2281');
    $ptnote = mysqli_real_escape_string($hos, $cfg['hla_ptnote_regex'] ?? 'ผ่านการตัดกรองยีนแพ้ยา|HLA-B');
    $rxnote = mysqli_real_escape_string($hos, $cfg['hla_rxnote_regex'] ?? 'ยีนแพ้ยา|HLA-B|ตรวจยีน');
    $date_esc = mysqli_real_escape_string($hos, $screen_date);
    $icode_in = implode(',', array_map(function ($c) use ($hos) {
        return "'" . mysqli_real_escape_string($hos, $c) . "'";
    }, array_keys($hla_icodes)));

    $sql = "SELECT DISTINCT o.hn, o.vn, o.icode FROM opitemrece o
        WHERE o.vstdate = '$date_esc' AND o.icode IN ($icode_in) AND o.qty > 0
        AND NOT EXISTS (SELECT 1 FROM opitemrece o2 WHERE o2.hn=o.hn AND o2.icode=o.icode
            AND o2.vstdate < o.vstdate AND o2.vstdate >= DATE_SUB(o.vstdate, INTERVAL $lb MONTH))
        AND NOT EXISTS (SELECT 1 FROM lab_order lo2 JOIN lab_head lh2 ON lo2.lab_order_number=lh2.lab_order_number
            WHERE lh2.hn=o.hn AND lo2.lab_items_code IN ($lab_in)
            AND lh2.order_date < o.vstdate AND lh2.order_date >= DATE_SUB(o.vstdate, INTERVAL $lb MONTH))
        AND NOT EXISTS (SELECT 1 FROM ptnote pn2 WHERE pn2.hn=o.hn
            AND CAST(CONVERT(pn2.plain_text USING utf8) AS binary) REGEXP '$ptnote')
        AND NOT EXISTS (SELECT 1 FROM patient_rx_note pr2 WHERE pr2.hn=o.hn
            AND CAST(CONVERT(pr2.rx_note USING utf8) AS binary) REGEXP '$rxnote')";

    $alerts = [];
    $alert_levels = [];
    $q2 = mysqli_query($hos, $sql);
    if ($q2) {
        while ($r = mysqli_fetch_assoc($q2)) {
            $key = sp_patient_screen_key([
                'hn' => $r['hn'] ?? '',
                'vn' => $r['vn'] ?? '',
                'vstdate' => $screen_date,
            ]);
            $alerts[$key] = true;
            $icode = trim((string)($r['icode'] ?? ''));
            $lvl = $drug_settings[$icode]['alert_level'] ?? hla_default_alert_level();
            $prev = $alert_levels[$key] ?? 'soft';
            $alert_levels[$key] = hla_resolve_alert_level([$prev, $lvl]);
        }
    }
    return ['no_settings' => false, 'icodes' => array_keys($hla_icodes), 'alerts' => $alerts, 'alert_levels' => $alert_levels];
}

/** UA ทั้งวัน — ดึง lab ครั้งเดียว แล้วประเมินใน PHP */
function sp_batch_ua_results_map($con, $hos, $screen_date, array $icodes_map) {
    $rules = [];
    $q = mysqli_query($con, "SELECT * FROM monitor_ua_settings");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $rules[] = $r;
        }
    }
    if (empty($rules)) {
        return ['no_rules' => true, 'results' => []];
    }
    $rule_map = [];
    $lab_codes = [];
    foreach ($rules as $r) {
        $c = trim((string)($r['icode'] ?? ''));
        if ($c === '') {
            continue;
        }
        $rule_map[$c][] = $r;
        $lc = trim((string)($r['lab_code'] ?? ''));
        if ($lc !== '') {
            $lab_codes[$lc] = true;
        }
    }
    if (empty($rule_map)) {
        return ['no_rules' => true, 'results' => []];
    }

    $candidates = [];
    $vn_by_key = [];
    foreach ($icodes_map as $pkey => $icodes) {
        $has_rule = false;
        foreach ($icodes as $icode) {
            if (isset($rule_map[$icode])) {
                $has_rule = true;
                break;
            }
        }
        if (!$has_rule) {
            continue;
        }
        $parts = explode('|', $pkey, 3);
        $vn = $parts[1] ?? '';
        if ($vn === '') {
            continue;
        }
        $candidates[$pkey] = $icodes;
        $vn_by_key[$pkey] = $vn;
    }

    $lab_latest = [];
    if (!empty($candidates) && !empty($lab_codes)) {
        $vn_in = [];
        foreach ($vn_by_key as $vn) {
            $vn_in[$vn] = "'" . mysqli_real_escape_string($hos, $vn) . "'";
        }
        $lab_in = [];
        foreach (array_keys($lab_codes) as $lc) {
            $lab_in[] = "'" . mysqli_real_escape_string($hos, $lc) . "'";
        }
        $sql = "SELECT lh.vn, lo.lab_items_code, lo.lab_order_result, lh.order_date
            FROM lab_order lo
            JOIN lab_head lh ON lh.lab_order_number = lo.lab_order_number
            WHERE lh.vn IN (" . implode(',', array_values($vn_in)) . ")
              AND lo.lab_items_code IN (" . implode(',', $lab_in) . ")
            ORDER BY lh.order_date DESC";
        $ql = mysqli_query($hos, $sql);
        if ($ql) {
            while ($row = mysqli_fetch_assoc($ql)) {
                $vn = trim((string)($row['vn'] ?? ''));
                $code = trim((string)($row['lab_items_code'] ?? ''));
                $lk = $vn . '|' . $code;
                if (!isset($lab_latest[$lk])) {
                    $lab_latest[$lk] = trim((string)($row['lab_order_result'] ?? ''));
                }
            }
        }
    }

    $results = [];
    foreach ($candidates as $pkey => $icodes) {
        $vn = $vn_by_key[$pkey] ?? '';
        $found = false;
        foreach ($icodes as $icode) {
            if (!isset($rule_map[$icode])) {
                continue;
            }
            foreach ($rule_map[$icode] as $rule) {
                $lab_code = trim((string)($rule['lab_code'] ?? ''));
                $lk = $vn . '|' . $lab_code;
                if (!isset($lab_latest[$lk])) {
                    continue;
                }
                $res = $lab_latest[$lk];
                if (monitor_lab_match_condition($rule['condition_type'] ?? 'range', $rule['condition_value'] ?? '', $res)) {
                    $lvl = ($rule['alert_level'] ?? '') === 'hard' ? 'hard' : 'soft';
                    $results[$pkey] = sp_result_alert($lvl, ($rule['alert_message'] ?? 'UA Alert') . ': ' . $res);
                    $found = true;
                    break 2;
                }
            }
        }
        if (!$found) {
            $results[$pkey] = sp_result_pass();
        }
    }
    return ['no_rules' => false, 'results' => $results];
}

function sp_load_rule_icodes($con, $table, $icode_col = 'icode', $where = '') {
    $sql = "SELECT $icode_col FROM $table";
    if ($where !== '') {
        $sql .= " WHERE $where";
    }
    $q = mysqli_query($con, $sql);
    $codes = [];
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $c = trim((string)($r[$icode_col] ?? ''));
            if ($c !== '') {
                $codes[$c] = true;
            }
        }
    }
    return array_keys($codes);
}

/** โหลดกฎ + batch screening ครั้งเดียวต่อวัน */
function sp_build_screen_cache($con, $hos, array $patients, $screen_date, array $modules) {
    $ctx = [
        'icodes' => sp_batch_patient_icodes_map($hos, $screen_date, $patients),
        'hla' => null,
        'ua' => null,
        'hbv_icodes' => [],
        'hbv_has_settings' => false,
        'lab_renal_icodes' => [],
        'lab_hepatic_icodes' => [],
        'lab_other_icodes' => [],
        'lab_has_rules' => ['renal' => false, 'hepatic' => false, 'other' => false],
        'cumulative_has_rules' => false,
    ];
    $need = [];
    foreach ($modules as $mod) {
        $need[$mod['module_key'] ?? ''] = true;
    }
    if (!empty($need['hla'])) {
        $ctx['hla'] = sp_batch_hla_alert_map($con, $hos, $screen_date);
    }
    if (!empty($need['ua'])) {
        $ctx['ua'] = sp_batch_ua_results_map($con, $hos, $screen_date, $ctx['icodes']);
    }
    if (!empty($need['hbv'])) {
        $ctx['hbv_icodes'] = sp_load_rule_icodes(
            $con,
            'monitor_hbv_settings',
            'icode',
            "monitor_type IN ('hbv_required_drug','hbv_high_risk_chemo','hbv_moderate_risk_chemo')"
        );
        $ctx['hbv_has_settings'] = !empty($ctx['hbv_icodes']);
    }
    if (!empty($need['lab_renal'])) {
        $ctx['lab_renal_icodes'] = sp_load_rule_icodes(
            $con,
            'drug_safety_rules',
            'drug_icode',
            "active_status=1 AND lab_type IN ('GFR','CKD_EPI','SERUM_CR') AND drug_icode IS NOT NULL AND TRIM(drug_icode)<>''"
        );
        $ctx['lab_has_rules']['renal'] = !empty($ctx['lab_renal_icodes']);
    }
    if (!empty($need['lab_hepatic'])) {
        $ctx['lab_hepatic_icodes'] = sp_load_rule_icodes(
            $con,
            'drug_safety_rules',
            'drug_icode',
            "active_status=1 AND lab_type IN ('Bilirubin','Bilirubin_xULN','AST','ALT','AST_xULN','ALT_xULN','AST_ALT_xULN','DirectBilirubin','IndirectBilirubin') AND drug_icode IS NOT NULL AND TRIM(drug_icode)<>''"
        );
        $ctx['lab_has_rules']['hepatic'] = !empty($ctx['lab_hepatic_icodes']);
    }
    if (!empty($need['lab_other'])) {
        $ctx['lab_other_icodes'] = sp_load_rule_icodes(
            $con,
            'drug_safety_rules',
            'drug_icode',
            "active_status=1 AND lab_type IN ('CUSTOM') AND drug_icode IS NOT NULL AND TRIM(drug_icode)<>''"
        );
        $ctx['lab_has_rules']['other'] = !empty($ctx['lab_other_icodes']);
    }
    if (!empty($need['cumulative'])) {
        $qc = mysqli_query($con, "SELECT COUNT(*) AS c FROM monitor_settings");
        $row = $qc ? mysqli_fetch_assoc($qc) : null;
        $ctx['cumulative_has_rules'] = $row && (int)($row['c'] ?? 0) > 0;
    }
    return $ctx;
}

function sp_patient_icodes_from_ctx(array $ctx, $pkey, $patient = null, $screen_date = '') {
    if (isset($ctx['icodes'][$pkey])) {
        return $ctx['icodes'][$pkey];
    }
    if (is_array($patient)) {
        foreach (sp_patient_screen_key_variants($patient, $screen_date) as $k) {
            if (isset($ctx['icodes'][$k])) {
                return $ctx['icodes'][$k];
            }
        }
    }
    return [];
}

function sp_screen_hla_cached(array $patient, array $ctx, $pkey, $screen_date = '') {
    $hla = $ctx['hla'] ?? null;
    if (!$hla || !empty($hla['no_settings'])) {
        return sp_result_na('ยังไม่ตั้งยา HLA');
    }
    $icodes = sp_patient_icodes_from_ctx($ctx, $pkey, $patient, $screen_date);
    $hla_set = array_flip($hla['icodes'] ?? []);
    $matched = false;
    foreach ($icodes as $ic) {
        if (isset($hla_set[$ic])) {
            $matched = true;
            break;
        }
    }
    if (!$matched) {
        return sp_result_na('ไม่มียา HLA');
    }
    $alert_hit = !empty($hla['alerts'][$pkey]);
    if (!$alert_hit) {
        foreach (sp_patient_screen_key_variants($patient, $screen_date) as $k) {
            if (!empty($hla['alerts'][$k])) {
                $alert_hit = true;
                break;
            }
        }
    }
    if ($alert_hit) {
        $level = $hla['alert_levels'][$pkey] ?? hla_default_alert_level();
        foreach (sp_patient_screen_key_variants($patient, $screen_date) as $k) {
            if (!empty($hla['alert_levels'][$k])) {
                $level = hla_resolve_alert_level([$level, $hla['alert_levels'][$k]]);
            }
        }
        return sp_result_alert($level, 'ยังไม่คัดกรองยีนแพ้ยา HLA-B');
    }
    return sp_result_pass('คัดกรองแล้ว / ไม่เข้าเงื่อนไข');
}

function sp_screen_ua_cached(array $ctx, $pkey, array $patient = [], $screen_date = '') {
    $ua = $ctx['ua'] ?? null;
    if (!$ua || !empty($ua['no_rules'])) {
        return sp_result_na('ยังไม่ตั้งกฎ UA');
    }
    if (isset($ua['results'][$pkey])) {
        return $ua['results'][$pkey];
    }
    $icodes = sp_patient_icodes_from_ctx($ctx, $pkey, $patient, $screen_date);
    if (empty($icodes)) {
        return sp_result_na();
    }
    return sp_result_pass();
}

function sp_screen_hbv_cached(array $ctx, $pkey, array $patient = [], $screen_date = '') {
    if (empty($ctx['hbv_has_settings'])) {
        return sp_result_na('ยังไม่ตั้งยา HBV');
    }
    $icodes = sp_patient_icodes_from_ctx($ctx, $pkey, $patient, $screen_date);
    $hbv_set = array_flip($ctx['hbv_icodes'] ?? []);
    foreach ($icodes as $ic) {
        if (isset($hbv_set[$ic])) {
            return sp_result_pass('ตรวจรายละเอียดที่ Monitor HBV');
        }
    }
    return sp_result_na('ไม่มียา HBV');
}

function sp_screen_lab_organ_cached(array $ctx, $pkey, $organ, array $patient = [], $screen_date = '') {
    $key_map = [
        'renal' => 'lab_renal_icodes',
        'hepatic' => 'lab_hepatic_icodes',
        'other' => 'lab_other_icodes',
    ];
    if (empty($ctx['lab_has_rules'][$organ])) {
        return sp_result_na('ยังไม่ตั้งกฎ Lab');
    }
    $rule_icodes = array_flip($ctx[$key_map[$organ]] ?? []);
    $icodes = sp_patient_icodes_from_ctx($ctx, $pkey, $patient, $screen_date);
    foreach ($icodes as $ic) {
        if (isset($rule_icodes[$ic])) {
            return sp_result_pass('ตรวจรายละเอียดที่ Monitor Lab');
        }
    }
    return sp_result_na('ไม่มียาในกลุ่ม Lab');
}

function sp_screen_cumulative_cached(array $ctx) {
    if (empty($ctx['cumulative_has_rules'])) {
        return sp_result_na('ยังไม่ตั้ง Cumulative');
    }
    return sp_result_pass('ตรวจที่ Monitor Cumulative');
}

function sp_patient_di_key($patient, $screen_date = '') {
    $ptype = $patient['patient_type'] ?? '';
    $vst = ($ptype === 'IPD' && $screen_date !== '') ? $screen_date : ($patient['vstdate'] ?? '');
    return trim((string)($patient['hn'] ?? '')) . '|'
        . trim((string)($patient['vn'] ?? '')) . '|'
        . trim((string)$vst);
}

/** เงื่อนไข Dose — ถ้าคำนวณ mg ไม่ได้ ยังให้แจ้ง Alert (ไม่กรองทิ้ง) */
function sp_di_dose_matches_lenient(array $rule, array $order, $which = 1) {
    $which = (int)$which;
    $use_key = $which === 2 ? 'use_dose2_condition' : 'use_dose1_condition';
    if (empty($rule[$use_key])) {
        return true;
    }
    if (di_dose_condition_matches($rule, $order, $which)) {
        return true;
    }
    if (array_key_exists('_daily_mg', $order)) {
        $mg = $order['_daily_mg'];
    } else {
        $parsed = hosxp_parse_drug_daily_dose_mg([
            'qty' => $order['qty'] ?? 0,
            'strength' => $order['strength'] ?? '',
            'units' => $order['units'] ?? '',
            'method' => $order['method'] ?? '',
        ]);
        $mg = $parsed['mg_dose'] ?? null;
    }
    return ($mg === null || $mg <= 0);
}

/**
 * คัดกรอง DI ทั้งวันครั้งเดียว (ดึง order จาก HOSxP รอบเดียว — เร็วกว่าทีละคน)
 * @return array{results: array, has_drug: array}
 */
function sp_batch_screen_di_for_date($con, $hos, $screen_date, array $hn_filter = []) {
    ensure_di_dose_columns($con);
    $screen_date = trim((string)$screen_date);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $screen_date)) {
        return ['results' => [], 'has_drug' => [], 'has_rules' => false];
    }

    $q_rules = mysqli_query($con, "SELECT * FROM monitor_drug_interaction_settings WHERE active_status = 1");
    $rules = [];
    if ($q_rules) {
        while ($row = mysqli_fetch_assoc($q_rules)) {
            $d1 = trim($row['drug_icode'] ?? '');
            $d2 = trim($row['hosxp_icode'] ?? '');
            if ($d1 === '' || $d2 === '') {
                continue;
            }
            $rules[] = $row;
        }
    }
    if (empty($rules)) {
        return ['results' => [], 'has_drug' => [], 'has_rules' => false];
    }

    $monitor_icodes = [];
    $icode_set = [];
    foreach ($rules as $rule) {
        $d1 = trim($rule['drug_icode']);
        $monitor_icodes[$d1] = true;
        $icode_set[$d1] = true;
        $icode_set[trim($rule['hosxp_icode'])] = true;
    }

    $all_icodes = array_keys(array_filter($icode_set, function ($k) {
        return $k !== '';
    }));
    $need_dose_mg = di_rules_need_dose_mg($rules);
    $window = di_compute_query_window($screen_date, $screen_date, $rules);
    $query_start = $window['query_start'];
    $query_end = date('Y-m-d', strtotime($screen_date . ' + 30 days'));

    [$raw_rows] = di_fetch_hosxp_orders($hos, $all_icodes, $query_start, $query_end, $need_dose_mg, $hn_filter, 80000);
    $orders = di_dedupe_latest_orders($raw_rows);
    $prepared_rows = di_prepare_orders($orders, $need_dose_mg);
    [$by_icode, $by_vn_icode, $by_hn_icode] = di_reindex_orders_fast($prepared_rows);

    $drug_names = [];
    $ptnames = [];
    $has_drug = [];
    foreach ($prepared_rows as $row) {
        $ic = trim($row['icode'] ?? '');
        if ($ic !== '' && !isset($drug_names[$ic])) {
            $drug_names[$ic] = trim($row['drug_name'] ?? '') ?: $ic;
        }
        $hn = trim($row['hn'] ?? '');
        if ($hn !== '' && !isset($ptnames[$hn])) {
            $ptnames[$hn] = trim($row['ptname'] ?? '');
        }
        if (($row['vstdate'] ?? '') === $screen_date && isset($monitor_icodes[$ic])) {
            $has_drug[sp_patient_screen_key($row)] = true;
        }
    }

    $alert_buckets = [];
    $seen = [];

    foreach ($rules as $rule) {
        $icode1 = trim($rule['drug_icode']);
        $icode2 = trim($rule['hosxp_icode']);
        $check_mode = (($rule['check_mode'] ?? 'same_visit') === 'lookback') ? 'lookback' : 'same_visit';
        $lookback_days = max(1, (int)($rule['lookback_days'] ?? 30));
        $alert_level = (!empty($rule['alert_level']) && $rule['alert_level'] === 'hard') ? 'hard' : 'soft';
        $drug1_name = $drug_names[$icode1] ?? ($rule['drug_name'] ?? $icode1);
        $drug2_name = $drug_names[$icode2] ?? ($rule['hosxp_drug_name'] ?? $icode2);

        foreach ($by_icode[$icode1] ?? [] as $o1) {
            if (!di_order_qty_valid($o1) || ($o1['vstdate'] ?? '') !== $screen_date) {
                continue;
            }
            $hn = trim($o1['hn'] ?? '');
            $c1 = $o1['_c'];
            $ptname = $ptnames[$hn] ?? '';
            $pkey = sp_patient_screen_key($o1);

            if ($check_mode === 'same_visit') {
                foreach (di_same_visit_candidates($o1, $icode2, $by_vn_icode, $by_hn_icode) as $o2) {
                    if (!di_order_qty_valid($o2)) {
                        continue;
                    }
                    $c2 = $o2['_c'];
                    if (!di_periods_overlap($screen_date, $c1, $o2['vstdate'], $c2)) {
                        continue;
                    }
                    if (!sp_di_dose_matches_lenient($rule, $o1, 1) || !sp_di_dose_matches_lenient($rule, $o2, 2)) {
                        continue;
                    }
                    $dedupe = $rule['id'] . '|' . $pkey . '|' . ($o2['vstdate'] ?? '') . '|' . ($o2['vn'] ?? '') . '|' . $c1 . '|' . $c2;
                    if (isset($seen[$dedupe])) {
                        continue;
                    }
                    $seen[$dedupe] = true;
                    $alert_buckets[$pkey][] = di_build_alert(
                        $rule, $o1, $o2, $alert_level, $check_mode, $lookback_days,
                        $ptname, $drug1_name, $drug2_name, $c1, $c2
                    );
                }
            } else {
                $lb_start = date('Y-m-d', strtotime($screen_date . " -{$lookback_days} days"));
                foreach ($by_hn_icode[$hn][$icode2] ?? [] as $o2) {
                    if (!di_order_qty_valid($o2)) {
                        continue;
                    }
                    $c2 = $o2['_c'];
                    if (!di_periods_overlap($screen_date, $c1, $o2['vstdate'], $c2)) {
                        continue;
                    }
                    if ($o2['_end'] < $lb_start) {
                        continue;
                    }
                    if (!sp_di_dose_matches_lenient($rule, $o1, 1) || !sp_di_dose_matches_lenient($rule, $o2, 2)) {
                        continue;
                    }
                    $dedupe = $rule['id'] . '|' . $pkey . '|' . ($o2['vstdate'] ?? '') . '|' . $c1 . '|' . $c2;
                    if (isset($seen[$dedupe])) {
                        continue;
                    }
                    $seen[$dedupe] = true;
                    $alert_buckets[$pkey][] = di_build_alert(
                        $rule, $o1, $o2, $alert_level, $check_mode, $lookback_days,
                        $ptname, $drug1_name, $drug2_name, $c1, $c2
                    );
                }
            }
        }
    }

    $results = [];
    foreach ($alert_buckets as $pkey => $alerts) {
        $details = array_values(array_unique(array_map('di_format_alert_summary_line', $alerts)));
        $has_hard = false;
        foreach ($alerts as $a) {
            if (($a['alert_level'] ?? '') === 'hard') {
                $has_hard = true;
            }
        }
        $results[$pkey] = sp_result_alert(
            $has_hard ? 'hard' : 'soft',
            implode('; ', $details),
            $details,
            $alerts
        );
    }

    return ['results' => $results, 'has_drug' => $has_drug, 'has_rules' => true];
}

/** Drug Interaction — ใช้ cache จาก batch ถ้ามี */
function sp_screen_di($con, $hos, $patient, array $di_batch = null, $screen_date = '') {
    if (is_array($di_batch)) {
        $key = sp_patient_di_key($patient, $screen_date);
        if (!empty($di_batch['results'][$key])) {
            return $di_batch['results'][$key];
        }
        if (!empty($di_batch['has_drug'][$key])) {
            return sp_result_pass();
        }
        if (empty($di_batch['has_rules'])) {
            return sp_result_na('ยังไม่ตั้งกฎ DI');
        }
        return sp_result_na('ไม่มียาในกลุ่ม DI');
    }
    $icodes = sp_patient_icodes_on_date($hos, $patient['hn'], $patient['vstdate'], $patient['vn'] ?? '');
    if (empty($icodes)) {
        return sp_result_na('ไม่มียาที่เฝ้าระวัง');
    }
    $eval = di_evaluate_visit_alerts(
        $con,
        $hos,
        $patient['hn'],
        $patient['vstdate'],
        $patient['vn'] ?? '',
        trim($patient['ptname'] ?? ''),
        $icodes
    );
    if (empty($eval['alerts'])) {
        $q = mysqli_query($con, "SELECT 1 FROM monitor_drug_interaction_settings WHERE active_status = 1 LIMIT 1");
        if (!$q || mysqli_num_rows($q) === 0) {
            return sp_result_na('ยังไม่ตั้งกฎ DI');
        }
        $selected = array_flip($icodes);
        $has_rule = false;
        $q2 = mysqli_query($con, "SELECT drug_icode FROM monitor_drug_interaction_settings WHERE active_status = 1");
        if ($q2) {
            while ($r = mysqli_fetch_assoc($q2)) {
                if (isset($selected[trim($r['drug_icode'] ?? '')])) {
                    $has_rule = true;
                    break;
                }
            }
        }
        return $has_rule ? sp_result_pass() : sp_result_na('ไม่มียาในกลุ่ม DI');
    }
    $details = array_map('di_format_alert_summary_line', $eval['alerts']);
    $details = array_values(array_unique($details));
    return sp_result_alert(
        !empty($eval['has_hard']) ? 'hard' : 'soft',
        implode('; ', $details),
        $details,
        $eval['alerts']
    );
}

/** HLA screening */
function sp_screen_hla($con, $hos, $patient) {
    ensure_monitor_hla_alert_level_column($con);
    $drug_settings = hla_load_drug_settings($con);
    if (empty($drug_settings)) {
        return sp_result_na('ยังไม่ตั้งยา HLA');
    }
    $hla_icodes = array_fill_keys(array_keys($drug_settings), true);
    $icodes = sp_patient_icodes_on_date($hos, $patient['hn'], $patient['vstdate'], $patient['vn'] ?? '');
    $matched = array_intersect($icodes, array_keys($hla_icodes));
    if (empty($matched)) {
        return sp_result_na('ไม่มียา HLA');
    }
    $matched_levels = [];
    foreach ($matched as $ic) {
        $matched_levels[] = $drug_settings[$ic]['alert_level'] ?? hla_default_alert_level();
    }
    $cfg = [];
    $qc = mysqli_query($con, "SELECT config_name, config_value FROM sys_config WHERE config_name LIKE 'hla_%'");
    if ($qc) {
        while ($r = mysqli_fetch_assoc($qc)) {
            $cfg[$r['config_name']] = $r['config_value'];
        }
    }
    $lb = (int)($cfg['hla_lookback_months'] ?? 24);
    $lab_in = monitor_lab_codes_sql_in($hos, $cfg['hla_lab_items_code'] ?? '2281');
    $ptnote = mysqli_real_escape_string($hos, $cfg['hla_ptnote_regex'] ?? 'ผ่านการตัดกรองยีนแพ้ยา|HLA-B');
    $rxnote = mysqli_real_escape_string($hos, $cfg['hla_rxnote_regex'] ?? 'ยีนแพ้ยา|HLA-B|ตรวจยีน');
    $hn_in = sp_hn_in_sql($hos, $patient['hn']);
    $vst_esc = mysqli_real_escape_string($hos, $patient['vstdate']);
    $icode_in = implode(',', array_map(function ($c) use ($hos) {
        return "'" . mysqli_real_escape_string($hos, $c) . "'";
    }, $matched));
    $sql = "SELECT 1 FROM opitemrece o
        WHERE o.hn IN ($hn_in) AND o.icode IN ($icode_in) AND o.vstdate = '$vst_esc' AND o.qty > 0
        AND NOT EXISTS (SELECT 1 FROM opitemrece o2 WHERE o2.hn=o.hn AND o2.icode=o.icode
            AND o2.vstdate < o.vstdate AND o2.vstdate >= DATE_SUB(o.vstdate, INTERVAL $lb MONTH))
        AND NOT EXISTS (SELECT 1 FROM lab_order lo2 JOIN lab_head lh2 ON lo2.lab_order_number=lh2.lab_order_number
            WHERE lh2.hn=o.hn AND lo2.lab_items_code IN ($lab_in)
            AND lh2.order_date < o.vstdate AND lh2.order_date >= DATE_SUB(o.vstdate, INTERVAL $lb MONTH))
        AND NOT EXISTS (SELECT 1 FROM ptnote pn2 WHERE pn2.hn=o.hn
            AND CAST(CONVERT(pn2.plain_text USING utf8) AS binary) REGEXP '$ptnote')
        AND NOT EXISTS (SELECT 1 FROM patient_rx_note pr2 WHERE pr2.hn=o.hn
            AND CAST(CONVERT(pr2.rx_note USING utf8) AS binary) REGEXP '$rxnote')
        LIMIT 1";
    $q2 = mysqli_query($hos, $sql);
    if ($q2 && mysqli_num_rows($q2) > 0) {
        return sp_result_alert(hla_resolve_alert_level($matched_levels), 'ยังไม่คัดกรองยีนแพ้ยา HLA-B');
    }
    return sp_result_pass('คัดกรองแล้ว / ไม่เข้าเงื่อนไข');
}

/** UA */
function sp_screen_ua($con, $hos, $patient) {
    $icodes = sp_patient_icodes_on_date($hos, $patient['hn'], $patient['vstdate'], $patient['vn'] ?? '');
    if (empty($icodes)) {
        return sp_result_na();
    }
    $rules = [];
    $q = mysqli_query($con, "SELECT * FROM monitor_ua_settings");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $rules[] = $r;
        }
    }
    if (empty($rules)) {
        return sp_result_na('ยังไม่ตั้งกฎ UA');
    }
    $map = [];
    foreach ($rules as $r) {
        $c = trim($r['icode'] ?? '');
        if ($c !== '') {
            $map[$c][] = $r;
        }
    }
    $hn_esc = mysqli_real_escape_string($hos, $patient['hn']);
    $date_esc = mysqli_real_escape_string($hos, $patient['vstdate']);
    $vn = trim($patient['vn'] ?? '');
    foreach ($icodes as $icode) {
        if (!isset($map[$icode])) {
            continue;
        }
        foreach ($map[$icode] as $rule) {
            if ($vn === '') {
                continue;
            }
            $lab_code = mysqli_real_escape_string($hos, $rule['lab_code']);
            $vn_esc = mysqli_real_escape_string($hos, $vn);
            $ql = mysqli_query($hos, "SELECT lo.lab_order_result FROM lab_order lo
                JOIN lab_head lh ON lh.lab_order_number = lo.lab_order_number
                WHERE lh.vn = '$vn_esc' AND lo.lab_items_code = '$lab_code'
                ORDER BY lh.order_date DESC LIMIT 1");
            if (!$ql || !($lab = mysqli_fetch_assoc($ql))) {
                continue;
            }
            $res = trim($lab['lab_order_result'] ?? '');
            if (monitor_lab_match_condition($rule['condition_type'] ?? 'range', $rule['condition_value'] ?? '', $res)) {
                $lvl = ($rule['alert_level'] ?? '') === 'hard' ? 'hard' : 'soft';
                return sp_result_alert($lvl, ($rule['alert_message'] ?? 'UA Alert') . ': ' . $res);
            }
        }
    }
    return sp_result_pass();
}

/** HBV — simplified */
function sp_screen_hbv($con, $hos, $patient) {
    $q = mysqli_query($con, "SELECT icode FROM monitor_hbv_settings WHERE monitor_type IN ('hbv_required_drug','hbv_high_risk_chemo','hbv_moderate_risk_chemo')");
    if (!$q || mysqli_num_rows($q) === 0) {
        return sp_result_na('ยังไม่ตั้งยา HBV');
    }
    $hbv_icodes = [];
    while ($r = mysqli_fetch_assoc($q)) {
        $hbv_icodes[trim($r['icode'])] = true;
    }
    $icodes = sp_patient_icodes_on_date($hos, $patient['hn'], $patient['vstdate'], $patient['vn'] ?? '');
    if (!array_intersect($icodes, array_keys($hbv_icodes))) {
        return sp_result_na('ไม่มียา HBV');
    }
    return sp_result_pass('ตรวจรายละเอียดที่ Monitor HBV');
}

/** Lab organ — pass if no matching drug rules on visit */
function sp_screen_lab_organ($con, $hos, $patient, $organ) {
    $types_map = [
        'renal' => ['GFR', 'CKD_EPI', 'SERUM_CR'],
        'hepatic' => ['GFR', 'CKD_EPI', 'AST', 'ALT', 'Bilirubin', 'Bilirubin_xULN', 'AST_xULN', 'ALT_xULN', 'AST_ALT_xULN'],
        'other' => ['CUSTOM'],
    ];
    if ($organ === 'renal') {
        $lab_types = "'GFR','CKD_EPI','SERUM_CR'";
    } elseif ($organ === 'hepatic') {
        $lab_types = "'Bilirubin','Bilirubin_xULN','AST','ALT','AST_xULN','ALT_xULN','AST_ALT_xULN','DirectBilirubin','IndirectBilirubin'";
    } else {
        $lab_types = "'CUSTOM'";
    }
    $q = mysqli_query($con, "SELECT drug_icode FROM drug_safety_rules WHERE active_status=1 AND lab_type IN ($lab_types) AND drug_icode IS NOT NULL AND TRIM(drug_icode)<>''");
    if (!$q || mysqli_num_rows($q) === 0) {
        return sp_result_na('ยังไม่ตั้งกฎ Lab');
    }
    $rule_icodes = [];
    while ($r = mysqli_fetch_assoc($q)) {
        $rule_icodes[trim($r['drug_icode'])] = true;
    }
    $icodes = sp_patient_icodes_on_date($hos, $patient['hn'], $patient['vstdate'], $patient['vn'] ?? '');
    if (!array_intersect($icodes, array_keys($rule_icodes))) {
        return sp_result_na('ไม่มียาในกลุ่ม Lab');
    }
    return sp_result_pass('ตรวจรายละเอียดที่ Monitor Lab');
}

function sp_screen_cumulative($con, $hos, $patient) {
    $q = mysqli_query($con, "SELECT COUNT(*) AS c FROM monitor_settings");
    $row = $q ? mysqli_fetch_assoc($q) : null;
    if (!$row || (int)$row['c'] === 0) {
        return sp_result_na('ยังไม่ตั้ง Cumulative');
    }
    return sp_result_pass('ตรวจที่ Monitor Cumulative');
}

function sp_screen_patient($con, $hos, $patient, array $modules, array $di_batch = null, $screen_date = '', array $ctx = null) {
    $results = [];
    $has_alert = false;
    $max_level = 'soft';
    $pkey = sp_patient_screen_key($patient, $screen_date);
    $use_ctx = is_array($ctx);
    foreach ($modules as $mod) {
        $key = $mod['module_key'];
        switch ($key) {
            case 'di':
                $r = sp_screen_di($con, $hos, $patient, $di_batch, $screen_date);
                break;
            case 'hla':
                $r = $use_ctx ? sp_screen_hla_cached($patient, $ctx, $pkey, $screen_date) : sp_screen_hla($con, $hos, $patient);
                break;
            case 'ua':
                $r = $use_ctx ? sp_screen_ua_cached($ctx, $pkey, $patient, $screen_date) : sp_screen_ua($con, $hos, $patient);
                break;
            case 'hbv':
                $r = $use_ctx ? sp_screen_hbv_cached($ctx, $pkey, $patient, $screen_date) : sp_screen_hbv($con, $hos, $patient);
                break;
            case 'lab_renal':
                $r = $use_ctx ? sp_screen_lab_organ_cached($ctx, $pkey, 'renal', $patient, $screen_date) : sp_screen_lab_organ($con, $hos, $patient, 'renal');
                break;
            case 'lab_hepatic':
                $r = $use_ctx ? sp_screen_lab_organ_cached($ctx, $pkey, 'hepatic', $patient, $screen_date) : sp_screen_lab_organ($con, $hos, $patient, 'hepatic');
                break;
            case 'lab_other':
                $r = $use_ctx ? sp_screen_lab_organ_cached($ctx, $pkey, 'other', $patient, $screen_date) : sp_screen_lab_organ($con, $hos, $patient, 'other');
                break;
            case 'cumulative':
                $r = $use_ctx ? sp_screen_cumulative_cached($ctx) : sp_screen_cumulative($con, $hos, $patient);
                break;
            default:
                $r = sp_result_na();
        }
        $results[$key] = $r;
        if ($r['status'] === 'alert') {
            $has_alert = true;
            if ($r['level'] === 'hard') {
                $max_level = 'hard';
            }
        }
    }
    return [
        'modules' => $results,
        'overall' => $has_alert ? 'alert' : 'pass',
        'overall_level' => $has_alert ? $max_level : '',
    ];
}

function sp_build_screened_patient_row(array $pat, array $screen, $patient_type, $screen_date, array $modules) {
    $ward_display = ($patient_type === 'OPD')
        ? 'ผู้ป่วยนอก'
        : (trim(($pat['ward_name'] ?? '') . (($pat['ward_name'] ?? '') && ($pat['bedno'] ?? '') ? ' / ' : '') . ($pat['bedno'] ?? '')) ?: '-');
    $mod_display = [];
    foreach ($modules as $mod) {
        $k = $mod['module_key'];
        $r = $screen['modules'][$k] ?? sp_result_na();
        $mod_display[$k] = [
            'status' => $r['status'],
            'level' => $r['level'],
            'summary' => $r['summary'],
            'details' => $r['details'] ?? [],
            'items' => $r['items'] ?? [],
        ];
    }
    $order_dt = sp_patient_order_datetime($pat);
    return [
        'hn' => ltrim($pat['hn'] ?? '', '0'),
        'hn_full' => $pat['hn'] ?? '',
        'vn' => $pat['vn'] ?? '',
        'an' => $pat['an'] ?? '',
        'ptname' => trim($pat['ptname'] ?? ''),
        'vstdate' => $pat['vstdate'] ?? $screen_date,
        'vsttime' => $pat['vsttime'] ?? '',
        'rxdate' => $order_dt['date'],
        'rxtime' => $order_dt['time'],
        'patient_type' => $patient_type,
        'ward_display' => $ward_display,
        'overall' => $screen['overall'],
        'overall_level' => $screen['overall_level'],
        'modules' => $mod_display,
    ];
}

function sp_filter_screened_rows_by_time(array $rows, $screen_date, $window, $time_from = '', $time_to = '') {
    if ($window === 'full') {
        return $rows;
    }
    $out = [];
    foreach ($rows as $row) {
        $p = [
            'rxdate' => $row['rxdate'] ?? '',
            'rxtime' => $row['rxtime'] ?? '',
            'vstdate' => $row['vstdate'] ?? '',
            'vsttime' => $row['vsttime'] ?? '',
        ];
        if ($window === 'custom' && $time_from !== '' && $time_to !== '') {
            $hit = sp_filter_patients_by_custom_time([$p], $time_from, $time_to);
        } else {
            $hit = sp_filter_patients_by_time_window([$p], $screen_date, $window);
        }
        if (!empty($hit)) {
            $out[] = $row;
        }
    }
    return $out;
}

function sp_save_intervention($con, array $data, $user_id, $user_name) {
    require_once __DIR__ . '/smart_pharma_alert_log_helper.php';
    sp_alert_log_ensure_tables($con);

    $hn_raw = trim((string)($data['hn'] ?? ''));
    $vn_raw = trim((string)($data['vn'] ?? ''));
    $ptype = in_array($data['patient_type'] ?? '', ['OPD', 'IPD'], true) ? $data['patient_type'] : 'OPD';
    $screen_date = trim((string)($data['screen_date'] ?? $data['vstdate'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $screen_date)) {
        $screen_date = date('Y-m-d');
    }
    $module_key = trim((string)($data['module_key'] ?? ''));
    $alert_level = ($data['alert_level'] ?? '') === 'hard' ? 'hard' : 'soft';

    $alert_log_id = (int)($data['alert_log_id'] ?? 0);
    $alert_log = null;
    if ($alert_log_id > 0) {
        $q = mysqli_query($con, "SELECT * FROM smart_pharma_alert_logs WHERE id=$alert_log_id LIMIT 1");
        $alert_log = $q ? mysqli_fetch_assoc($q) : null;
        if ($alert_log) {
            $alert_level = ($alert_log['alert_level'] ?? '') === 'hard' ? 'hard' : 'soft';
            if ($module_key === '') {
                $module_key = trim((string)($alert_log['module_key'] ?? ''));
            }
        } else {
            $alert_log_id = 0;
        }
    }
    if ($alert_log_id <= 0) {
        $alert_log = sp_alert_log_find_pending($con, $ptype, $screen_date, $hn_raw, $vn_raw, $module_key);
        $alert_log_id = (int)($alert_log['id'] ?? 0);
    }

    $hn = mysqli_real_escape_string($con, $hn_raw);
    $vn = mysqli_real_escape_string($con, $vn_raw);
    $an = mysqli_real_escape_string($con, $data['an'] ?? '');
    $vst = mysqli_real_escape_string($con, $data['vstdate'] ?? $screen_date);
    $mod = mysqli_real_escape_string($con, $module_key);
    $lvl = mysqli_real_escape_string($con, $alert_level);
    $sum = mysqli_real_escape_string($con, $data['alert_summary'] ?? '');
    $itype = mysqli_real_escape_string($con, $data['intervention_type'] ?? 'recommend');
    $dcat = mysqli_real_escape_string($con, $data['deviation_category'] ?? '');
    $note = mysqli_real_escape_string($con, $data['note_text'] ?? '');
    $uid = (int)$user_id;
    $uname = mysqli_real_escape_string($con, $user_name);

    $resolves = ($alert_level === 'soft') ? 1 : 0;
    $alert_log_sql = $alert_log_id > 0 ? (string)$alert_log_id : 'NULL';

    $sql = "INSERT INTO pharmacist_interventions
        (hn, vn, an, patient_type, vstdate, module_key, alert_log_id, resolves_alert, alert_level, alert_summary,
         intervention_type, deviation_category, note_text, recorded_by, recorded_name)
        VALUES ('$hn','$vn','$an','$ptype','$vst','$mod',$alert_log_sql,$resolves,'$lvl','$sum','$itype','$dcat','$note',$uid,'$uname')";
    if (!mysqli_query($con, $sql)) {
        return ['ok' => false, 'message' => 'บันทึกไม่สำเร็จ: ' . mysqli_error($con)];
    }

    if ($alert_log_id > 0) {
        if ($alert_level === 'hard') {
            sp_alert_log_mark_pharmacist_note($con, $alert_log_id);
            return [
                'ok' => true,
                'message' => 'บันทึก Intervention แล้ว — Hard Alert ยังรอแก้ไขใน HOSxP (จะปิดเมื่อไม่พบ Alert)',
                'hard_pending' => true,
            ];
        }
        sp_alert_log_resolve_by_intervention($con, $alert_log_id);
        return [
            'ok' => true,
            'message' => 'บันทึก Intervention และปิด Alert (Soft) เรียบร้อย',
            'hard_pending' => false,
        ];
    }
    return ['ok' => true, 'message' => 'บันทึก Intervention เรียบร้อย', 'hard_pending' => false];
}

function sp_update_intervention($con, $intervention_id, array $data, $user_id, $user_name) {
    $id = (int)$intervention_id;
    if ($id <= 0) {
        return ['ok' => false, 'message' => 'ไม่พบรายการ Intervention'];
    }
    $q = mysqli_query($con, "SELECT * FROM pharmacist_interventions WHERE id=$id LIMIT 1");
    $row = $q ? mysqli_fetch_assoc($q) : null;
    if (!$row) {
        return ['ok' => false, 'message' => 'ไม่พบรายการ Intervention'];
    }
    $itype = mysqli_real_escape_string($con, $data['intervention_type'] ?? $row['intervention_type']);
    $dcat = mysqli_real_escape_string($con, $data['deviation_category'] ?? $row['deviation_category']);
    $note = mysqli_real_escape_string($con, $data['note_text'] ?? $row['note_text']);
    $uid = (int)$user_id;
    $uname = mysqli_real_escape_string($con, $user_name);
    if (!mysqli_query($con, "UPDATE pharmacist_interventions SET
            intervention_type='$itype', deviation_category='$dcat', note_text='$note',
            recorded_by=$uid, recorded_name='$uname'
            WHERE id=$id")) {
        return ['ok' => false, 'message' => 'แก้ไขไม่สำเร็จ: ' . mysqli_error($con)];
    }
    return ['ok' => true, 'message' => 'แก้ไข Intervention เรียบร้อย'];
}

function sp_list_interventions($con, $start, $end, $limit = 200) {
    $start_esc = mysqli_real_escape_string($con, $start);
    $end_esc = mysqli_real_escape_string($con, $end);
    $limit = max(1, min(500, (int)$limit));
    $sql = "SELECT i.*, m.label_th AS module_label
        FROM pharmacist_interventions i
        LEFT JOIN smart_pharma_modules m ON m.module_key = i.module_key
        WHERE i.vstdate >= '$start_esc' AND i.vstdate <= '$end_esc'
        ORDER BY i.created_at DESC
        LIMIT $limit";
    $q = mysqli_query($con, $sql);
    $rows = [];
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $rows[] = $r;
        }
    }
    return $rows;
}

/**
 * คัดกรองรายชื่อผู้ป่วย (ใช้กับ Cron Telegram) — ทั้งวัน ไม่ใช้ session cache
 * @return array{patients: array, module_labels: array, alert_log: array}
 */
function sp_run_screening_list($con, $hos, $patient_type, $screen_date, $time_window = 'full') {
    $patient_type = $patient_type === 'IPD' ? 'IPD' : 'OPD';
    $modules = sp_get_modules($con, $patient_type);
    if (empty($modules)) {
        return ['patients' => [], 'module_labels' => [], 'alert_log' => ['new' => 0, 'updated' => 0, 'reopened' => 0, 'resolved' => 0, 'skipped' => 0]];
    }
    if ($patient_type === 'OPD') {
        $patients_with_drugs = sp_fetch_opd_patients_with_drugs($hos, $screen_date);
    } else {
        $patients_all = sp_fetch_ipd_patients($hos, $screen_date);
        $patients_with_drugs = sp_filter_patients_with_drug_orders(
            sp_enrich_patients_rx_time($hos, $patients_all, $screen_date)
        );
    }
    $di_batch = null;
    foreach ($modules as $mod) {
        if (($mod['module_key'] ?? '') === 'di') {
            $hn_filter = sp_extract_hn_list($patients_with_drugs);
            $di_batch = sp_batch_screen_di_for_date($con, $hos, $screen_date, $hn_filter);
            break;
        }
    }
    $screen_ctx = sp_build_screen_cache($con, $hos, $patients_with_drugs, $screen_date, $modules);

    $module_labels = [];
    foreach ($modules as $mod) {
        $module_labels[$mod['module_key']] = $mod['label_th'];
    }
    $all_screened = [];
    foreach ($patients_with_drugs as $pat) {
        $screen = sp_screen_patient($con, $hos, $pat, $modules, $di_batch, $screen_date, $screen_ctx);
        $all_screened[] = sp_build_screened_patient_row($pat, $screen, $patient_type, $screen_date, $modules);
    }
    require_once __DIR__ . '/smart_pharma_alert_log_helper.php';
    $alert_log = sp_alert_log_sync($con, $patient_type, $screen_date, $all_screened);
    $out = sp_filter_screened_rows_by_time($all_screened, $screen_date, $time_window);
    return ['patients' => $out, 'all_screened' => $all_screened, 'module_labels' => $module_labels, 'alert_log' => $alert_log];
}
