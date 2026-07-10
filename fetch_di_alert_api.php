<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
include('connect.php');
include('hosxp_tmt_drug_helper.php');
mysqli_set_charset($con, 'utf8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$raw = json_decode(file_get_contents('php://input'), true);
$hn = trim($raw['hn'] ?? '');
$order_date = trim($raw['order_date'] ?? date('Y-m-d'));
$icodes = isset($raw['icodes']) && is_array($raw['icodes']) ? $raw['icodes'] : [];

if ($hn === '' || empty($icodes)) {
    echo json_encode(['status' => 'success', 'alerts' => []]);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $order_date)) {
    $order_date = date('Y-m-d');
}

$selected = [];
foreach ($icodes as $c) {
    $v = trim((string)$c);
    if ($v !== '') {
        $selected[$v] = true;
    }
}
if (empty($selected)) {
    echo json_encode(['status' => 'success', 'alerts' => []]);
    exit;
}

$q_rules = mysqli_query($con, "SELECT * FROM monitor_drug_interaction_settings WHERE active_status = 1");
$rules = [];
while ($row = mysqli_fetch_assoc($q_rules)) {
    $drug_icode = trim((string)($row['drug_icode'] ?? ''));
    $hosxp_icode = trim((string)($row['hosxp_icode'] ?? ''));
    if ($drug_icode === '' || $hosxp_icode === '') {
        continue;
    }
    if (!isset($selected[$drug_icode])) {
        continue;
    }
    $rules[] = $row;
}

if (empty($rules)) {
    echo json_encode(['status' => 'success', 'alerts' => []]);
    exit;
}

$con_hosxp = hosxp_connect($con);
if (!$con_hosxp) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อฐานข้อมูล HOSxP ได้']);
    exit;
}

$icode_set = [];
$max_lookback = 0;
foreach ($rules as $rule) {
    $icode_set[trim($rule['drug_icode'])] = true;
    $icode_set[trim($rule['hosxp_icode'])] = true;
    if (($rule['check_mode'] ?? 'same_visit') === 'lookback') {
        $max_lookback = max($max_lookback, max(1, (int)($rule['lookback_days'] ?? 30)));
    }
}

$icode_sql = [];
foreach (array_keys($icode_set) as $ic) {
    if ($ic !== '') {
        $icode_sql[] = "'" . mysqli_real_escape_string($con_hosxp, $ic) . "'";
    }
}

$hn_esc = mysqli_real_escape_string($con_hosxp, $hn);
$query_start = date('Y-m-d', strtotime($order_date . ' - ' . max($max_lookback, 30) . ' days'));
$query_end = date('Y-m-d', strtotime($order_date . ' + 365 days'));
$start_esc = mysqli_real_escape_string($con_hosxp, $query_start);
$end_esc = mysqli_real_escape_string($con_hosxp, $query_end);
$method_expr = di_sql_method_expr('o');
$tmt_expr = hosxp_sql_tmt_drug_name('d', 'tm');

$sql = "
    SELECT o.hn, o.vn, o.vstdate, o.vsttime, o.rxdate, o.rxtime, o.item_no, o.icode, o.qty,
           $method_expr AS method,
           CAST(CONVERT(d.units USING utf8) AS CHAR) AS units,
           $tmt_expr AS drug_name
    FROM opitemrece o
    INNER JOIN drugitems d ON d.icode = o.icode
    " . hosxp_sql_tmt_join('d', 'tm') . "
    " . di_sql_method_joins('o') . "
    WHERE o.hn = '$hn_esc'
      AND o.icode IN (" . implode(',', $icode_sql) . ")
      AND o.qty > 0
      AND d.icode IS NOT NULL
      AND o.vstdate >= '$start_esc'
      AND o.vstdate <= '$end_esc'
    ORDER BY o.vstdate DESC, o.rxdate DESC, o.rxtime DESC, o.vsttime DESC, o.item_no DESC
";

$q = mysqli_query($con_hosxp, $sql);
$raw_rows = [];
$drug_names = [];
while ($q && ($row = mysqli_fetch_assoc($q))) {
    if (!di_order_qty_valid($row)) {
        continue;
    }
    $row['method'] = trim((string)($row['method'] ?? ''));
    $raw_rows[] = $row;
    $ic = trim($row['icode']);
    if (!isset($drug_names[$ic])) {
        $drug_names[$ic] = trim($row['drug_name'] ?? '') ?: $ic;
    }
}
mysqli_close($con_hosxp);

$orders = di_dedupe_latest_orders($raw_rows);
[$by_icode, $by_vn, $by_hn] = di_reindex_orders($orders);

$alerts = [];
$seen = [];

foreach ($rules as $rule) {
    $icode1 = trim((string)$rule['drug_icode']);
    $icode2 = trim((string)$rule['hosxp_icode']);
    $check_mode = (($rule['check_mode'] ?? 'same_visit') === 'lookback') ? 'lookback' : 'same_visit';
    $lookback_days = max(1, (int)($rule['lookback_days'] ?? 30));
    $level = (!empty($rule['alert_level']) && $rule['alert_level'] === 'hard') ? 'hard' : 'soft';
    $type = ($level === 'hard') ? 'DI Hard Alert' : 'DI Soft Alert';

    foreach ($by_icode[$icode1] ?? [] as $o1) {
        if ($o1['vstdate'] !== $order_date) {
            continue;
        }

        $c1 = di_calculate_coverage_days($o1['qty'], $o1['method'] ?? '', $o1['units'] ?? '');
        $drug1_name = $drug_names[$icode1] ?? $rule['drug_name'];
        $drug2_name = $drug_names[$icode2] ?? $rule['hosxp_drug_name'];

        $candidates = ($check_mode === 'same_visit')
            ? ($by_vn[$o1['vn']] ?? [])
            : ($by_hn[$hn] ?? []);

        foreach ($candidates as $o2) {
            if (trim($o2['icode']) !== $icode2) {
                continue;
            }

            if ($check_mode === 'same_visit') {
                $vn1 = trim((string)($o1['vn'] ?? ''));
                $vn2 = trim((string)($o2['vn'] ?? ''));
                $same_visit = ($vn1 !== '' && $vn1 === $vn2) || ($o1['vstdate'] === $o2['vstdate']);
                if (!$same_visit) {
                    continue;
                }
            }

            $c2 = di_calculate_coverage_days($o2['qty'], $o2['method'] ?? '', $o2['units'] ?? '');
            if (!di_periods_overlap($o1['vstdate'], $c1, $o2['vstdate'], $c2)) {
                continue;
            }

            if ($check_mode === 'lookback') {
                $lb_start = date('Y-m-d', strtotime($order_date . " -{$lookback_days} days"));
                if (di_period_end_date($o2['vstdate'], $c2) < $lb_start) {
                    continue;
                }
            }

            $meta = ($check_mode === 'same_visit')
                ? 'Visit เดียวกัน · ทับช่วงให้ยา ' . $c1 . '/' . $c2 . ' วัน'
                : di_build_lookback_label($lookback_days, $c1, $c2, $o1['vstdate'], $o2['vstdate']);

            $msg = trim((string)($rule['detail_text'] ?? 'พบปฏิกิริยาระหว่างยา'));
            $full = "{$drug1_name} + {$drug2_name} | {$msg} | {$meta}";
            $k = md5($type . '|' . $full);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;

            $alerts[] = [
                'level' => $level,
                'type' => $type,
                'title' => $type,
                'message' => $full,
            ];
        }
    }
}

echo json_encode(['status' => 'success', 'alerts' => $alerts]);
exit;
