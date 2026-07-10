<?php
/**
 * Refer ยาแยกตามวันนัด — รายชื่อจาก oapp แยกคลินิก/ห้องตรวจ
 */

require_once __DIR__ . '/drug_refer_helper.php';

function ndr_appt_has_column($con_hosxp, $table, $column) {
    static $cache = [];
    $key = $table . '.' . $column;
    if (!array_key_exists($key, $cache)) {
        $t = mysqli_real_escape_string($con_hosxp, $table);
        $c = mysqli_real_escape_string($con_hosxp, $column);
        $q = @mysqli_query($con_hosxp, "SHOW COLUMNS FROM `$t` LIKE '$c'");
        $cache[$key] = $q && mysqli_num_rows($q) > 0;
    }
    return $cache[$key];
}

function ndr_appt_sql_room_code($con_hosxp, $prefix = 'o') {
    if (ndr_appt_has_column($con_hosxp, 'oapp', 'room')) {
        return "NULLIF(TRIM($prefix.room), '')";
    }
    if (ndr_appt_has_column($con_hosxp, 'oapp', 'roomno')) {
        return "NULLIF(TRIM($prefix.roomno), '')";
    }
    return "''";
}

function ndr_appt_sql_clinic_code() {
    return "COALESCE(NULLIF(TRIM(o.clinic), ''), NULLIF(TRIM(ov.main_dep), ''), '')";
}

function ndr_appt_sql_clinic_name() {
    return "CAST(CONVERT(COALESCE(
        NULLIF(TRIM(dep.department), ''),
        NULLIF(TRIM(cl.name), ''),
        NULLIF(TRIM(o.clinic), ''),
        NULLIF(TRIM(ov.main_dep), ''),
        'ไม่ระบุคลินิก'
    ) USING utf8) AS CHAR)";
}

function ndr_appt_sql_room_name($con_hosxp) {
    $room_code = ndr_appt_sql_room_code($con_hosxp, 'o');
    if (ndr_appt_has_column($con_hosxp, 'room', 'room')) {
        return "CAST(CONVERT(COALESCE(NULLIF(TRIM(rm.name), ''), $room_code, 'ไม่ระบุห้อง') USING utf8) AS CHAR)";
    }
    return "CAST(CONVERT(COALESCE($room_code, 'ไม่ระบุห้อง') USING utf8) AS CHAR)";
}

function ndr_appt_normalize_visit_type($visit_type) {
    $visit_type = strtolower(trim((string)$visit_type));
    if (in_array($visit_type, ['opd', 'vn', 'v'], true)) {
        return 'opd';
    }
    if (in_array($visit_type, ['ipd', 'an', 'a'], true)) {
        return 'ipd';
    }
    return 'all';
}

function ndr_appt_visit_type_label($visit_type) {
    $visit_type = ndr_appt_normalize_visit_type($visit_type);
    if ($visit_type === 'opd') {
        return 'OPD (VN)';
    }
    if ($visit_type === 'ipd') {
        return 'IPD (AN — เฉพาะ HME)';
    }
    return 'ทั้งหมด';
}

function ndr_appt_sql_visit_filter($visit_type) {
    $visit_type = ndr_appt_normalize_visit_type($visit_type);
    if ($visit_type === 'opd') {
        return " AND TRIM(IFNULL(o.vn, '')) <> '' ";
    }
    if ($visit_type === 'ipd') {
        return " AND TRIM(IFNULL(o.an, '')) <> '' ";
    }
    return '';
}

function ndr_appt_sql_pttype_joins() {
    return "
        LEFT JOIN ipt ndr_ipt ON ndr_ipt.an = o.an AND TRIM(IFNULL(o.an, '')) <> ''
        LEFT JOIN pttype ndr_pt ON ndr_pt.pttype = IF(TRIM(IFNULL(o.an, '')) <> '', ndr_ipt.pttype, ov.pttype)
    ";
}

function ndr_appt_base_from_sql($con_hosxp) {
    $clinic_code = ndr_appt_sql_clinic_code();
    $room_code = ndr_appt_sql_room_code($con_hosxp, 'o');
    $clinic_name = ndr_appt_sql_clinic_name();
    $room_name = ndr_appt_sql_room_name($con_hosxp);
    $room_join = '';
    if (ndr_appt_has_column($con_hosxp, 'room', 'room') && ndr_appt_has_column($con_hosxp, 'oapp', 'room')) {
        $room_join = 'LEFT JOIN room rm ON rm.room = o.room';
    }
    $clinic_join = '';
    if (ndr_appt_has_column($con_hosxp, 'clinic', 'clinic')) {
        $clinic_join = 'LEFT JOIN clinic cl ON cl.clinic = o.clinic';
    }

    return [
        'clinic_code' => $clinic_code,
        'room_code' => $room_code,
        'clinic_name' => $clinic_name,
        'room_name' => $room_name,
        'joins' => "
            INNER JOIN patient p ON p.hn = o.hn
            LEFT JOIN ovst ov ON ov.vn = o.vn
            LEFT JOIN kskdepartment dep ON dep.depcode = $clinic_code
            $clinic_join
            $room_join
            " . ndr_appt_sql_pttype_joins(),
    ];
}

function ndr_appt_format_date_th($date) {
    $ts = strtotime((string)$date);
    if (!$ts) {
        return '';
    }
    return date('d/m/', $ts) . ((int)date('Y', $ts) + 543);
}

function ndr_appt_resolve_date($date) {
    $date = trim((string)$date);
    if ($date === '' || $date === '0000-00-00') {
        $date = date('Y-m-d', strtotime('+1 day'));
    }
    $ts = strtotime($date);
    if (!$ts) {
        $date = date('Y-m-d', strtotime('+1 day'));
        $ts = strtotime($date);
    }
    return [
        'appt_date' => date('Y-m-d', $ts),
        'label' => ndr_appt_format_date_th(date('Y-m-d', $ts)),
    ];
}

function ndr_appt_group_key($clinic_code, $room_code) {
    return trim((string)$clinic_code) . ':' . trim((string)$room_code);
}

function ndr_appt_fetch_groups($con_hosxp, $appt_date, $visit_type = 'opd', array $pttype_codes = []) {
    $period = ndr_appt_resolve_date($appt_date);
    $date_esc = mysqli_real_escape_string($con_hosxp, $period['appt_date']);
    $base = ndr_appt_base_from_sql($con_hosxp);
    $visit_filter = ndr_appt_sql_visit_filter($visit_type);
    $pt_filter = ndr_district_sql_pttype_filter($con_hosxp, $pttype_codes, 'ndr_pt');
    $clinic_code = $base['clinic_code'];
    $room_code = $base['room_code'];
    $clinic_name = $base['clinic_name'];
    $room_name = $base['room_name'];

    $sql = "
        SELECT
            $clinic_code AS clinic_code,
            $room_code AS room_code,
            $clinic_name AS clinic_name,
            $room_name AS room_name,
            COUNT(DISTINCT o.hn) AS patient_count
        FROM oapp o
        {$base['joins']}
        WHERE o.nextdate = '$date_esc'
          AND o.nextdate IS NOT NULL
          AND o.nextdate <> '0000-00-00'
          $visit_filter
          $pt_filter
        GROUP BY $clinic_code, $room_code, $clinic_name, $room_name
        ORDER BY clinic_name ASC, room_name ASC
    ";
    $q = mysqli_query($con_hosxp, $sql);
    if (!$q) {
        return ['_error' => mysqli_error($con_hosxp)];
    }
    $groups = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $clinic_code_val = trim($row['clinic_code'] ?? '');
        $room_code_val = trim($row['room_code'] ?? '');
        $groups[] = [
            'group_key' => ndr_appt_group_key($clinic_code_val, $room_code_val),
            'clinic_code' => $clinic_code_val,
            'room_code' => $room_code_val,
            'clinic_name' => trim($row['clinic_name'] ?? '') ?: 'ไม่ระบุคลินิก',
            'room_name' => trim($row['room_name'] ?? '') ?: 'ไม่ระบุห้อง',
            'patient_count' => (int)($row['patient_count'] ?? 0),
        ];
    }
    return $groups;
}

function ndr_appt_fetch_patients($con_hosxp, $appt_date, $clinic_code, $room_code, $visit_type = 'opd', array $pttype_codes = [], $all_groups = false) {
    $period = ndr_appt_resolve_date($appt_date);
    $date_esc = mysqli_real_escape_string($con_hosxp, $period['appt_date']);
    $clinic_esc = mysqli_real_escape_string($con_hosxp, trim((string)$clinic_code));
    $room_esc = mysqli_real_escape_string($con_hosxp, trim((string)$room_code));
    $base = ndr_appt_base_from_sql($con_hosxp);
    $visit_filter = ndr_appt_sql_visit_filter($visit_type);
    $pt_filter = ndr_district_sql_pttype_filter($con_hosxp, $pttype_codes, 'ndr_pt');
    $clinic_code_sql = $base['clinic_code'];
    $room_code_sql = $base['room_code'];
    $addr_sql = ndr_sql_thaiaddress_names('p');

    $clinic_filter = '';
    $room_filter = '';
    if (!$all_groups) {
        $clinic_filter = $clinic_esc === ''
            ? " AND ($clinic_code_sql = '' OR $clinic_code_sql IS NULL) "
            : " AND $clinic_code_sql = '$clinic_esc' ";
        $room_filter = $room_esc === ''
            ? " AND ($room_code_sql = '' OR $room_code_sql IS NULL) "
            : " AND $room_code_sql = '$room_esc' ";
    }

    $sql = "
        SELECT
            o.oapp_id,
            o.hn,
            o.vn,
            o.an,
            o.vstdate,
            o.nextdate,
            o.nexttime,
            $clinic_code_sql AS clinic_code,
            $room_code_sql AS room_code,
            CAST(CONVERT(CONCAT(IFNULL(p.pname,''), IFNULL(p.fname,''), ' ', IFNULL(p.lname,'')) USING utf8) AS CHAR) AS full_name,
            CAST(CONVERT(IFNULL(p.addrpart,'') USING utf8) AS CHAR) AS addrpart,
            CAST(CONVERT(IFNULL(p.moopart,'') USING utf8) AS CHAR) AS moopart,
            $addr_sql,
            CAST(CONVERT(IFNULL(ndr_pt.name, '') USING utf8) AS CHAR) AS pttype_name
        FROM oapp o
        {$base['joins']}
        WHERE o.nextdate = '$date_esc'
          AND o.nextdate IS NOT NULL
          AND o.nextdate <> '0000-00-00'
          $visit_filter
          $pt_filter
          $clinic_filter
          $room_filter
        ORDER BY o.nexttime ASC, o.hn ASC
    ";
    $q = mysqli_query($con_hosxp, $sql);
    if (!$q) {
        return ['_error' => mysqli_error($con_hosxp)];
    }

    $by_hn = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $hn = trim($row['hn'] ?? '');
        if ($hn === '') {
            continue;
        }
        if (!isset($by_hn[$hn])) {
            $by_hn[$hn] = [
                'hn' => $hn,
                'full_name' => trim($row['full_name'] ?? ''),
                'address' => ndr_format_patient_address($row),
                'tmb_name' => trim($row['tmb_name'] ?? ''),
                'amp_name' => trim($row['amp_name'] ?? ''),
                'pttype_name' => trim($row['pttype_name'] ?? ''),
                'oapp_id' => (int)($row['oapp_id'] ?? 0),
                'appt_vn' => trim($row['vn'] ?? ''),
                'appt_an' => trim($row['an'] ?? ''),
                'nextdate' => trim($row['nextdate'] ?? ''),
                'nexttime' => trim($row['nexttime'] ?? ''),
                'nexttime_display' => ndr_format_time_display($row['nexttime'] ?? ''),
                'clinic_code' => trim($row['clinic_code'] ?? ''),
                'room_code' => trim($row['room_code'] ?? ''),
                'ref_vn' => '',
                'ref_an' => '',
                'ref_vstdate' => '',
                'ref_visit_type' => '',
                'drug_count' => 0,
            ];
        }
    }

    if (empty($by_hn)) {
        return [];
    }

    $visit_type = ndr_appt_normalize_visit_type($visit_type);
    $refs = ndr_appt_fetch_patient_visit_refs_batch($con_hosxp, array_keys($by_hn), $visit_type);
    foreach ($by_hn as $hn => &$patient) {
        $ref = $refs[$hn] ?? null;
        if ($ref) {
            $patient['ref_vn'] = $ref['vn'] ?? '';
            $patient['ref_an'] = $ref['an'] ?? '';
            $patient['ref_vstdate'] = $ref['vstdate'] ?? '';
            $patient['ref_vstdate_display'] = ndr_appt_format_date_th($ref['vstdate'] ?? '');
            $patient['ref_visit_type'] = $ref['visit_type'] ?? '';
            $patient['drug_count'] = (int)($ref['drug_count'] ?? 0);
        }
    }
    unset($patient);

    return array_values($by_hn);
}

function ndr_format_time_display($time) {
    $time = trim((string)$time);
    if ($time === '' || $time === '00:00:00') {
        return '';
    }
    if (preg_match('/^(\d{2}):(\d{2})/', $time, $m)) {
        return $m[1] . ':' . $m[2];
    }
    return $time;
}

function ndr_appt_fetch_patient_visit_refs_batch($con_hosxp, array $hns, $visit_type = 'opd') {
    $visit_type = ndr_appt_normalize_visit_type($visit_type);
    $hn_in = ndr_hn_array_sql_in($con_hosxp, $hns);
    if ($hn_in === '') {
        return [];
    }

    $out = [];
    if ($visit_type === 'opd' || $visit_type === 'all') {
        $sql = "
            SELECT o.hn,
                   SUBSTRING_INDEX(GROUP_CONCAT(
                       CAST(CONCAT(
                           IFNULL(o.vn, ''), '|',
                           IFNULL(o.vstdate, ''), '|',
                           IFNULL(o.vsttime, '')
                       ) AS CHAR)
                       ORDER BY o.vstdate DESC, o.vsttime DESC, o.item_no DESC
                       SEPARATOR '||'
                   ), '||', 1) AS latest_raw
            FROM opitemrece o
            INNER JOIN drugitems d ON d.icode = o.icode AND d.istatus = 'Y'
            WHERE o.hn IN ($hn_in)
              AND TRIM(IFNULL(o.vn, '')) <> ''
            GROUP BY o.hn
        ";
        $q = mysqli_query($con_hosxp, $sql);
        if ($q) {
            while ($row = mysqli_fetch_assoc($q)) {
                $hn = trim($row['hn'] ?? '');
                $parts = explode('|', $row['latest_raw'] ?? '');
                $vn = trim($parts[0] ?? '');
                if ($vn === '') {
                    continue;
                }
                $out[$hn] = [
                    'vn' => $vn,
                    'an' => '',
                    'vstdate' => trim($parts[1] ?? ''),
                    'visit_type' => 'opd',
                    'drug_count' => 0,
                ];
            }
        }
    }

    if ($visit_type === 'ipd' || $visit_type === 'all') {
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
        $sql = "
            SELECT o.hn,
                   SUBSTRING_INDEX(GROUP_CONCAT(
                       CAST(CONCAT(
                           IFNULL(o.an, ''), '|',
                           IFNULL(o.vstdate, ''), '|',
                           IFNULL(o.vsttime, '')
                       ) AS CHAR)
                       ORDER BY o.vstdate DESC, o.vsttime DESC, o.item_no DESC
                       SEPARATOR '||'
                   ), '||', 1) AS latest_raw
            FROM opitemrece o
            INNER JOIN drugitems d ON d.icode = o.icode AND d.istatus = 'Y'
            LEFT JOIN ipt_order_no ion ON ion.order_no = o.order_no
            WHERE o.hn IN ($hn_in)
              AND TRIM(IFNULL(o.an, '')) <> ''
              $hme_filter
            GROUP BY o.hn
        ";
        $q = mysqli_query($con_hosxp, $sql);
        if ($q) {
            while ($row = mysqli_fetch_assoc($q)) {
                $hn = trim($row['hn'] ?? '');
                $parts = explode('|', $row['latest_raw'] ?? '');
                $an = trim($parts[0] ?? '');
                if ($an === '') {
                    continue;
                }
                $candidate = [
                    'vn' => '',
                    'an' => $an,
                    'vstdate' => trim($parts[1] ?? ''),
                    'visit_type' => 'ipd',
                    'drug_count' => 0,
                ];
                if ($visit_type === 'ipd' || !isset($out[$hn])) {
                    $out[$hn] = $candidate;
                }
            }
        }
    }

    foreach ($out as $hn => &$ref) {
        if ((int)($ref['drug_count'] ?? 0) <= 0) {
            $drugs = ndr_fetch_visit_drugs(
                $con_hosxp,
                $ref['vn'] ?? '',
                $ref['an'] ?? '',
                $ref['visit_type'] ?? 'opd'
            );
            $ref['drug_count'] = count($drugs);
        }
    }
    unset($ref);

    return $out;
}

function ndr_appt_enrich_patients_match($con, $con_hosxp, array &$patients, $min_similar_score = 90) {
    if (empty($patients)) {
        return;
    }
    $hospital_cache = [];
    $index_cache = [];

    foreach ($patients as &$p) {
        $amp_name = trim($p['amp_name'] ?? '');
        $vn = trim($p['ref_vn'] ?? '');
        $an = trim($p['ref_an'] ?? '');
        $visit_type = trim($p['ref_visit_type'] ?? 'opd');
        if ($vn === '' && $an === '') {
            $p['match_hospital'] = '';
            $p['match_total'] = 0;
            $p['match_matched'] = 0;
            $p['match_percent'] = null;
            $p['match_note'] = 'ไม่พบ Visit อ้างอิงสำหรับดึงรายการยา';
            continue;
        }
        $drugs = ndr_fetch_visit_drugs($con_hosxp, $vn, $an, $visit_type);
        if (empty($drugs)) {
            $p['match_hospital'] = '';
            $p['match_total'] = 0;
            $p['match_matched'] = 0;
            $p['match_percent'] = null;
            $p['match_note'] = $visit_type === 'ipd'
                ? 'ไม่พบรายการยา HME ใน AN ล่าสุด'
                : 'ไม่พบรายการยาใน VN ล่าสุด';
            continue;
        }
        if ($amp_name === '') {
            $p['match_hospital'] = '';
            $p['match_total'] = count($drugs);
            $p['match_matched'] = 0;
            $p['match_percent'] = null;
            $p['match_note'] = 'ไม่ระบุอำเภอ';
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
        if ($hospital === '' || $hos_index === null) {
            $p['match_hospital'] = '';
            $p['match_total'] = count($drugs);
            $p['match_matched'] = 0;
            $p['match_percent'] = null;
            $p['match_note'] = 'ไม่พบบัญชียา รพ.ในอำเภอนี้';
            continue;
        }
        $summary = ndr_summarize_single_hospital_match($drugs, $hos_index, $min_similar_score);
        $p['match_hospital'] = $hospital;
        $p['match_total'] = $summary['total'];
        $p['match_matched'] = $summary['matched'];
        $p['match_percent'] = $summary['match_percent'];
        $p['match_note'] = '';
        $p['drug_count'] = count($drugs);
    }
    unset($p);
}

function ndr_fetch_appointment_patient_match_detail(
    $con,
    $con_hosxp,
    $hn,
    $amp_name,
    $vn,
    $an,
    $visit_type = 'opd',
    $min_similar_score = 90
) {
    $hn = trim((string)$hn);
    $amp_name = trim((string)$amp_name);
    $vn = trim((string)$vn);
    $an = trim((string)$an);
    $visit_type = ndr_appt_normalize_visit_type($visit_type);
    if ($visit_type === 'all') {
        $visit_type = $an !== '' ? 'ipd' : 'opd';
    }

    if ($hn === '') {
        return ['status' => 'error', 'message' => 'กรุณาระบุ HN'];
    }
    if ($amp_name === '') {
        return ['status' => 'error', 'message' => 'ไม่ระบุอำเภอของผู้ป่วย'];
    }
    $hospital = ndr_resolve_hospital_for_amp_name($con, $amp_name);
    if ($hospital === '') {
        return ['status' => 'error', 'message' => 'ไม่พบบัญชียาเครือข่ายของ รพ.ในอำเภอ ' . $amp_name];
    }
    $patient = ndr_fetch_patient($con_hosxp, $hn);
    if (!$patient) {
        return ['status' => 'error', 'message' => 'ไม่พบผู้ป่วย HN นี้'];
    }
    if ($vn === '' && $an === '') {
        return ['status' => 'error', 'message' => 'ไม่พบ Visit อ้างอิงสำหรับดึงรายการยา'];
    }

    $drugs = ndr_fetch_visit_drugs($con_hosxp, $vn, $an, $visit_type);
    if (empty($drugs)) {
        return [
            'status' => 'error',
            'message' => $visit_type === 'ipd'
                ? 'ไม่พบรายการยา HME ใน AN ที่อ้างอิง'
                : 'ไม่พบรายการยาใน VN ที่อ้างอิง',
        ];
    }

    $index = ndr_build_hospital_drug_index($con, [$hospital]);
    $hos_index = $index[$hospital] ?? ['tmtdrug' => [], 'tpu' => [], 'drugs' => []];
    $compare = ndr_compare_drugs_single_hospital($drugs, $hos_index, $hospital, $min_similar_score);

    $ref_label = $an !== '' ? ('AN: ' . $an) : ('VN: ' . $vn);
    return [
        'status' => 'success',
        'patient' => $patient,
        'hospital' => $hospital,
        'amp_name' => $amp_name,
        'ref_visit' => [
            'vn' => $vn,
            'an' => $an,
            'visit_type' => $visit_type,
            'visit_label' => $ref_label,
            'filter_note' => $visit_type === 'ipd' ? 'เฉพาะ HME' : 'OPD ทั้ง Visit',
        ],
        'visit_type' => $visit_type,
        'visit_type_label' => ndr_appt_visit_type_label($visit_type),
        'min_similar_score' => (int)$min_similar_score,
        'rows' => $compare['rows'],
        'summary' => $compare['summary'],
    ];
}
