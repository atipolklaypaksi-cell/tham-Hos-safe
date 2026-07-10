<?php
/**
 * Monitor จำนวนสั่งยา OPD — เปรียบเทียบ qty กับวันนัดครั้งต่อไป (oapp)
 */

require_once __DIR__ . '/moq_method_helper.php';

function moq_default_excess_pills() {
    return 10;
}

function moq_default_shortage_pills() {
    return 0;
}

function moq_rule_type($type) {
    return ($type === 'continuous') ? 'continuous' : 'drug';
}

function moq_is_continuous_rule(array $rule) {
    return moq_rule_type($rule['rule_type'] ?? 'drug') === 'continuous';
}

function moq_continuous_rule_icode() {
    return '*';
}

function moq_continuous_rule_label() {
    return 'ยาใช้ต่อเนื่อง (HOSxP)';
}

function moq_upgrade_schema($con) {
    if (!moq_ensure_table($con)) {
        return false;
    }
    $migrations = [
        'rule_type' => __DIR__ . '/sql/monitor_opd_order_qty_group.sql',
        'exclude_icodes' => __DIR__ . '/sql/monitor_opd_order_qty_exclude.sql',
        'shortage_pills_allowed' => __DIR__ . '/sql/monitor_opd_order_qty_shortage.sql',
    ];
    foreach ($migrations as $column => $file) {
        $q = mysqli_query($con, "SHOW COLUMNS FROM monitor_opd_order_qty_settings LIKE '" . mysqli_real_escape_string($con, $column) . "'");
        if ($q && mysqli_num_rows($q) > 0) {
            continue;
        }
        $sql = @file_get_contents($file);
        if ($sql === false || trim($sql) === '') {
            return false;
        }
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt !== '' && !mysqli_query($con, $stmt)) {
                return false;
            }
        }
    }
    return true;
}

function moq_ensure_table($con) {
    $q = mysqli_query($con, "SHOW TABLES LIKE 'monitor_opd_order_qty_settings'");
    if ($q && mysqli_num_rows($q) > 0) {
        return true;
    }
    $sql = @file_get_contents(__DIR__ . '/sql/monitor_opd_order_qty.sql');
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

function moq_alert_level($level) {
    return ($level === 'soft') ? 'soft' : 'hard';
}

function moq_alert_badge_html($level) {
    if ($level === 'hard') {
        return '<span class="badge bg-danger">Hard</span>';
    }
    return '<span class="badge bg-warning text-dark">Soft</span>';
}

function moq_load_active_rules($con, array $rule_ids = []) {
    moq_upgrade_schema($con);
    $rules = [];
    $q = mysqli_query($con, "SELECT * FROM monitor_opd_order_qty_settings WHERE active = 1 ORDER BY rule_type DESC, drug_name ASC");
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
        if (moq_is_continuous_rule($row)) {
            $rules[] = $row;
            continue;
        }
        $icode = trim($row['icode'] ?? '');
        if ($icode === '') {
            continue;
        }
        $rules[] = $row;
    }
    return $rules;
}

/**
 * ยาที่ติ๊ก "ยาใช้ต่อเนื่อง" ใน HOSxP (drugitems.continuous = Y)
 *
 * @return array<int, array{icode:string, name:string}>
 */
function moq_fetch_continuous_drugs($hos) {
    $list = [];
    if (!$hos) {
        return $list;
    }
    $sql = "SELECT d.icode,
            CAST(CONVERT(d.name USING utf8) AS CHAR) AS name_local
        FROM drugitems d
        WHERE d.istatus = 'Y'
          AND d.continuous = 'Y'
          AND d.icode IS NOT NULL
          AND TRIM(d.icode) <> ''
        ORDER BY name_local ASC";
    $q = mysqli_query($hos, $sql);
    if (!$q) {
        return $list;
    }
    while ($row = mysqli_fetch_assoc($q)) {
        $icode = trim((string)($row['icode'] ?? ''));
        if ($icode === '') {
            continue;
        }
        $list[] = [
            'icode' => $icode,
            'name' => trim((string)($row['name_local'] ?? '')),
        ];
    }
    return $list;
}

function moq_count_continuous_drugs($hos) {
    return count(moq_fetch_continuous_drugs($hos));
}

/**
 * @return string[]
 */
function moq_parse_exclude_icodes($raw) {
    if (is_array($raw)) {
        $list = $raw;
    } else {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        $list = is_array($decoded) ? $decoded : explode(',', $raw);
    }
    $out = [];
    foreach ($list as $icode) {
        $icode = trim((string)$icode);
        if ($icode !== '') {
            $out[$icode] = true;
        }
    }
    return array_keys($out);
}

/**
 * @return array<string, bool>
 */
function moq_get_rule_exclude_set(array $rule) {
    $set = [];
    foreach (moq_parse_exclude_icodes($rule['exclude_icodes'] ?? '') as $icode) {
        $set[$icode] = true;
    }
    return $set;
}

/**
 * @param string[] $icodes
 */
function moq_encode_exclude_icodes(array $icodes) {
    return json_encode(moq_parse_exclude_icodes($icodes), JSON_UNESCAPED_UNICODE);
}

function moq_count_continuous_for_rule($hos, array $rule) {
    if (!moq_is_continuous_rule($rule)) {
        return 0;
    }
    $exclude = moq_get_rule_exclude_set($rule);
    $count = 0;
    foreach (moq_fetch_continuous_drugs($hos) as $drug) {
        $ic = trim($drug['icode'] ?? '');
        if ($ic !== '' && !isset($exclude[$ic])) {
            $count++;
        }
    }
    return $count;
}

function moq_build_continuous_drug_options_html(array $drugs) {
    $html = '';
    foreach ($drugs as $d) {
        $icode = trim($d['icode'] ?? '');
        if ($icode === '') {
            continue;
        }
        $name = trim($d['name'] ?? '');
        $label = htmlspecialchars($name !== '' ? $name : $icode, ENT_QUOTES, 'UTF-8');
        $label .= ' (' . htmlspecialchars($icode, ENT_QUOTES, 'UTF-8') . ')';
        $html .= '<option value="' . htmlspecialchars($icode, ENT_QUOTES, 'UTF-8') . '">' . $label . '</option>';
    }
    return $html;
}

/**
 * สร้าง map icode => กฎที่ใช้ (กฎยาเดี่ยวชนะกฎกลุ่มยาใช้ต่อเนื่อง)
 *
 * @return array{0: array<string, array<int, array>>, 1: string[]}
 */
function moq_build_rule_map_by_icode($hos, array $rules) {
    $rule_by_icode = [];
    $icode_list = [];

    foreach ($rules as $rule) {
        if (moq_is_continuous_rule($rule)) {
            continue;
        }
        $ic = trim($rule['icode'] ?? '');
        if ($ic === '') {
            continue;
        }
        if (!isset($rule_by_icode[$ic])) {
            $rule_by_icode[$ic] = [];
            $icode_list[] = "'" . mysqli_real_escape_string($hos, $ic) . "'";
        }
        $rule_by_icode[$ic][] = $rule;
    }

    foreach ($rules as $rule) {
        if (!moq_is_continuous_rule($rule)) {
            continue;
        }
        $exclude = moq_get_rule_exclude_set($rule);
        foreach (moq_fetch_continuous_drugs($hos) as $drug) {
            $ic = trim($drug['icode'] ?? '');
            if ($ic === '' || isset($rule_by_icode[$ic]) || isset($exclude[$ic])) {
                continue;
            }
            $rule_by_icode[$ic] = [$rule];
            $icode_list[] = "'" . mysqli_real_escape_string($hos, $ic) . "'";
        }
    }

    return [$rule_by_icode, $icode_list];
}

/** จำนวนวันที่ต้องมียา (นับรวมวันนัด) */
function moq_days_needed_until_appointment($order_date, $nextdate) {
    $order_date = trim((string)$order_date);
    $nextdate = trim((string)$nextdate);
    if ($order_date === '' || $nextdate === '' || $nextdate === '0000-00-00') {
        return null;
    }
    $order_ts = strtotime($order_date);
    $next_ts = strtotime($nextdate);
    if (!$order_ts || !$next_ts || $next_ts < $order_ts) {
        return null;
    }
    return max(1, (int)floor(($next_ts - $order_ts) / 86400) + 1);
}

/**
 * @return array{status:string, days_needed?:int, coverage_days?:int, coverage_end?:string, daily_units?:float|null, pills_needed?:float|null, min_qty_required?:float|null, max_qty_allowed?:float|null, excess_pills_allowed?:int, shortage_pills_allowed?:int}
 */
function moq_evaluate_order($qty, $method, $units, $order_date, $nextdate, $excess_pills = 10, $shortage_pills = 0, $con = null) {
    $days_needed = moq_days_needed_until_appointment($order_date, $nextdate);
    if ($days_needed === null) {
        return ['status' => 'skip'];
    }

    $qty = (float)$qty;
    $method = trim((string)$method);
    $units = trim((string)$units);
    $excess_pills = max(0, (int)$excess_pills);
    $shortage_pills = max(0, (int)$shortage_pills);

    if ($con) {
        $analysis = moq_method_analyze($con, $method, $qty, $units, $order_date, $nextdate);
        if (($analysis['status'] ?? '') === 'skip') {
            return [
                'status' => 'skip',
                'skip_reason' => $analysis['skip_reason'] ?? 'ข้ามตามนิยามวิธีใช้',
                'usage_mode' => $analysis['usage']['mode'] ?? 'skip',
                'usage_label' => $analysis['usage']['label'] ?? '',
            ];
        }
        if (($analysis['status'] ?? '') === 'ok') {
            $coverage_days = (int)($analysis['coverage_days'] ?? 1);
            $coverage_end = $analysis['coverage_end'] ?? di_period_end_date($order_date, $coverage_days);
            $daily = $analysis['daily_units'] ?? null;
            $pills_needed = $analysis['units_needed'] ?? null;
        } else {
            $coverage_days = di_calculate_coverage_days($qty, $method, $units);
            $coverage_end = di_period_end_date($order_date, $coverage_days);
            $daily = di_parse_daily_dose($method);
            $pills_needed = ($daily !== null && $daily > 0) ? $daily * $days_needed : null;
        }
    } else {
        $coverage_days = di_calculate_coverage_days($qty, $method, $units);
        $coverage_end = di_period_end_date($order_date, $coverage_days);
        $daily = di_parse_daily_dose($method);
        $pills_needed = ($daily !== null && $daily > 0) ? $daily * $days_needed : null;
    }

    $next_ts = strtotime($nextdate);
    $cov_end_ts = strtotime($coverage_end);

    if ($pills_needed === null && $daily !== null && $daily > 0) {
        $pills_needed = $daily * $days_needed;
    }

    $min_qty_required = null;
    $max_qty_allowed = null;
    $excess = false;
    $shortage = false;
    if ($pills_needed !== null && $pills_needed > 0) {
        $min_qty_required = max(0, $pills_needed - $shortage_pills);
        $max_qty_allowed = $pills_needed + $excess_pills;
        $shortage = ($qty < $min_qty_required);
        $excess = ($qty > $max_qty_allowed);
    } elseif ($cov_end_ts !== false && $next_ts !== false && $cov_end_ts < $next_ts) {
        $shortage = true;
    }

    $base = [
        'days_needed' => $days_needed,
        'coverage_days' => $coverage_days,
        'coverage_end' => $coverage_end,
        'daily_units' => $daily,
        'pills_needed' => $pills_needed,
        'min_qty_required' => $min_qty_required,
        'max_qty_allowed' => $max_qty_allowed,
        'excess_pills_allowed' => $excess_pills,
        'shortage_pills_allowed' => $shortage_pills,
    ];

    if ($shortage) {
        return array_merge($base, ['status' => 'shortage']);
    }
    if ($excess) {
        return array_merge($base, ['status' => 'excess']);
    }
    return array_merge($base, ['status' => 'ok']);
}

function moq_format_date_th($date) {
    $ts = strtotime((string)$date);
    if (!$ts) {
        return (string)$date;
    }
    return date('d/m/', $ts) . ((int)date('Y', $ts) + 543);
}

function moq_default_shortage_message(array $rule, array $eval, $nextdate) {
    $custom = trim((string)($rule['alert_message_shortage'] ?? ''));
    if ($custom !== '') {
        return $custom;
    }
    $parts = [];
    $parts[] = 'ยาไม่พอถึงวันนัด ' . moq_format_date_th($nextdate);
    if (!empty($eval['coverage_days']) && !empty($eval['days_needed'])) {
        $parts[] = 'ยาพอ ' . (int)$eval['coverage_days'] . ' วัน ต้องการ ' . (int)$eval['days_needed'] . ' วัน';
    }
    if ($eval['pills_needed'] !== null) {
        $parts[] = 'ต้องการ ' . rtrim(rtrim(number_format((float)$eval['pills_needed'], 2, '.', ''), '0'), '.') . ' หน่วย';
    }
    $short_allow = (int)($eval['shortage_pills_allowed'] ?? moq_default_shortage_pills());
    if ($short_allow > 0 && $eval['min_qty_required'] !== null) {
        $parts[] = 'ขาดได้ ' . $short_allow . ' หน่วย (ขั้นต่ำ ' . rtrim(rtrim(number_format((float)$eval['min_qty_required'], 2, '.', ''), '0'), '.') . ' หน่วย)';
    }
    return implode(' — ', $parts);
}

function moq_default_excess_message(array $rule, array $eval, $nextdate) {
    $custom = trim((string)($rule['alert_message_excess'] ?? ''));
    if ($custom !== '') {
        return $custom;
    }
    $allow = (int)($eval['excess_pills_allowed'] ?? moq_default_excess_pills());
    $parts = [];
    $parts[] = 'ยาเกินวันนัด ' . moq_format_date_th($nextdate) . ' มากกว่าที่อนุโลม (' . $allow . ' หน่วย)';
    if ($eval['max_qty_allowed'] !== null) {
        $parts[] = 'สูงสุดที่อนุโลม ' . rtrim(rtrim(number_format((float)$eval['max_qty_allowed'], 2, '.', ''), '0'), '.') . ' หน่วย';
    }
    return implode(' — ', $parts);
}

/**
 * @return array<string, array{nextdate:string, nexttime:string, oapp_id:int}>
 */
function moq_fetch_appointments_by_vn($hos, array $vn_list) {
    $out = [];
    if (empty($vn_list)) {
        return $out;
    }
    $in = [];
    foreach (array_unique($vn_list) as $vn) {
        $vn = trim((string)$vn);
        if ($vn !== '') {
            $in[] = "'" . mysqli_real_escape_string($hos, $vn) . "'";
        }
    }
    if (empty($in)) {
        return $out;
    }
    $sql = "SELECT oapp_id, vn, vstdate, nextdate, nexttime
        FROM oapp
        WHERE vn IN (" . implode(',', $in) . ")
          AND nextdate IS NOT NULL
          AND nextdate <> '0000-00-00'
        ORDER BY vn ASC, nextdate DESC, oapp_id DESC";
    $q = mysqli_query($hos, $sql);
    if (!$q) {
        return $out;
    }
    while ($row = mysqli_fetch_assoc($q)) {
        $vn = trim((string)($row['vn'] ?? ''));
        if ($vn === '') {
            continue;
        }
        if (!isset($out[$vn])) {
            $out[$vn] = [];
        }
        $out[$vn][] = [
            'oapp_id' => (int)($row['oapp_id'] ?? 0),
            'vstdate' => trim((string)($row['vstdate'] ?? '')),
            'nextdate' => trim((string)($row['nextdate'] ?? '')),
            'nexttime' => trim((string)($row['nexttime'] ?? '')),
        ];
    }
    return $out;
}

/**
 * ตรวจทุกวันนัดใน visit — ถ้าวันใดวันหนึ่ง qty เพียงพอ (status ok) ไม่แจ้งเตือน
 *
 * @return array{status:string, appointment?:array, eval?:array}
 */
function moq_evaluate_order_against_appointments($qty, $method, $units, $order_date, array $appointments, $excess_pills = 10, $shortage_pills = 0, $con = null) {
    $order_date = trim((string)$order_date);
    if ($order_date === '' || empty($appointments)) {
        return ['status' => 'skip'];
    }
    $order_ts = strtotime($order_date);
    if (!$order_ts) {
        return ['status' => 'skip'];
    }

    $valid = [];
    foreach ($appointments as $ap) {
        $nd = trim((string)($ap['nextdate'] ?? ''));
        if ($nd === '' || $nd === '0000-00-00') {
            continue;
        }
        $nd_ts = strtotime($nd);
        if (!$nd_ts || $nd_ts < $order_ts) {
            continue;
        }
        $valid[] = $ap;
    }
    if (empty($valid)) {
        return ['status' => 'skip'];
    }

    usort($valid, function ($a, $b) {
        $cmp = strcmp($a['nextdate'], $b['nextdate']);
        if ($cmp !== 0) {
            return $cmp;
        }
        return ($a['oapp_id'] ?? 0) <=> ($b['oapp_id'] ?? 0);
    });

    $evals = [];
    foreach ($valid as $ap) {
        $ev = moq_evaluate_order($qty, $method, $units, $order_date, $ap['nextdate'], $excess_pills, $shortage_pills, $con);
        $ev['_appointment'] = $ap;
        $evals[] = $ev;
        if (($ev['status'] ?? '') === 'skip') {
            unset($ev['_appointment']);
            return [
                'status' => 'skip',
                'eval' => $ev,
            ];
        }
        if (($ev['status'] ?? '') === 'ok') {
            unset($ev['_appointment']);
            return [
                'status' => 'ok',
                'appointment' => $ap,
                'eval' => $ev,
            ];
        }
    }

    foreach ($evals as $ev) {
        if (($ev['status'] ?? '') === 'shortage') {
            $ap = $ev['_appointment'];
            unset($ev['_appointment']);
            return [
                'status' => 'shortage',
                'appointment' => $ap,
                'eval' => $ev,
            ];
        }
    }
    foreach ($evals as $ev) {
        if (($ev['status'] ?? '') === 'excess') {
            $ap = $ev['_appointment'];
            unset($ev['_appointment']);
            return [
                'status' => 'excess',
                'appointment' => $ap,
                'eval' => $ev,
            ];
        }
    }

    return ['status' => 'skip'];
}

/**
 * @deprecated ใช้ moq_evaluate_order_against_appointments แทน
 */
function moq_pick_appointment_for_order(array $appointments, $order_date) {
    $order_date = trim((string)$order_date);
    if ($order_date === '' || empty($appointments)) {
        return null;
    }
    $order_ts = strtotime($order_date);
    if (!$order_ts) {
        return null;
    }
    $best = null;
    foreach ($appointments as $ap) {
        $nd = trim((string)($ap['nextdate'] ?? ''));
        if ($nd === '' || $nd === '0000-00-00') {
            continue;
        }
        $nd_ts = strtotime($nd);
        if (!$nd_ts || $nd_ts < $order_ts) {
            continue;
        }
        if ($best === null || $nd_ts > strtotime($best['nextdate'])) {
            $best = $ap;
        }
    }
    return $best;
}
