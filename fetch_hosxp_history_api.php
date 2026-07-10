<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

set_time_limit(120);
include('connect.php');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$hn = isset($input['hn']) ? trim($input['hn']) : '';
if ($hn === '') {
    echo json_encode(['status' => 'error', 'message' => 'กรุณาระบุ HN']);
    exit;
}

$months = isset($input['months']) ? (int)$input['months'] : 0;
if ($months < 0) {
    $months = 0;
}
$limit = isset($input['limit']) ? (int)$input['limit'] : ($months === 0 ? 5000 : 3000);
if ($limit < 1) {
    $limit = 3000;
}
if ($limit > 5000) {
    $limit = 5000;
}

$hn_variants = [];
if (is_numeric($hn)) {
    $hn_variants[] = str_pad($hn, 9, '0', STR_PAD_LEFT);
    $hn_variants[] = ltrim($hn, '0');
    if ($hn_variants[1] === '') {
        $hn_variants[1] = '0';
    }
} else {
    $hn_variants[] = $hn;
}
$hn_variants = array_values(array_unique($hn_variants));

$q_config = mysqli_query($con, "SELECT config_name, config_value FROM sys_config WHERE config_name IN ('h_host', 'h_user', 'h_pass', 'h_db')");
$hos_cfg = [];
if ($q_config) {
    while ($r = mysqli_fetch_assoc($q_config)) {
        $hos_cfg[$r['config_name']] = $r['config_value'];
    }
}

mysqli_report(MYSQLI_REPORT_OFF);
$con_hosxp = mysqli_init();
mysqli_options($con_hosxp, MYSQLI_OPT_CONNECT_TIMEOUT, 8);
if (defined('MYSQLI_OPT_READ_TIMEOUT')) {
    mysqli_options($con_hosxp, MYSQLI_OPT_READ_TIMEOUT, 60);
}
@mysqli_real_connect(
    $con_hosxp,
    $hos_cfg['h_host'] ?? '',
    $hos_cfg['h_user'] ?? '',
    $hos_cfg['h_pass'] ?? '',
    $hos_cfg['h_db'] ?? ''
);

if (!$con_hosxp) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อฐานข้อมูล HOSxP ได้ ตรวจสอบการตั้งค่าใน sys_config']);
    exit;
}
mysqli_set_charset($con_hosxp, 'utf8');

$hn_in = [];
foreach ($hn_variants as $v) {
    $hn_in[] = "'" . mysqli_real_escape_string($con_hosxp, $v) . "'";
}
$hn_sql = implode(',', $hn_in);

$date_filter = '';
if ($months > 0) {
    $date_filter = " AND o.vstdate >= DATE_SUB(CURDATE(), INTERVAL $months MONTH) ";
}

$patient_info = null;
$sql_patient = "
    SELECT
        p.hn,
        CAST(CONVERT(p.pname USING utf8) AS CHAR) AS pname,
        CAST(CONVERT(p.fname USING utf8) AS CHAR) AS fname,
        CAST(CONVERT(p.lname USING utf8) AS CHAR) AS lname
    FROM patient p
    WHERE p.hn IN ($hn_sql)
    LIMIT 1
";
$q_patient = mysqli_query($con_hosxp, $sql_patient);
if ($q_patient && ($p = mysqli_fetch_assoc($q_patient))) {
    $patient_info = [
        'hn' => $p['hn'],
        'pname' => trim($p['pname'] ?? ''),
        'fname' => trim($p['fname'] ?? ''),
        'lname' => trim($p['lname'] ?? ''),
        'full_name' => trim(($p['pname'] ?? '') . ($p['fname'] ?? '') . ' ' . ($p['lname'] ?? '')),
    ];
}

// เฉพาะรายการยา + ชื่อ/strength จาก TMT (tmt_tpu_code) ถ้ามี tpu_code_list
$sql_hosxp = "
    SELECT
        o.vn,
        o.an,
        o.vstdate,
        o.vsttime,
        o.rxdate,
        o.rxtime,
        o.order_no,
        o.item_type,
        ion.order_type,
        o.item_no,
        o.icode,
        o.qty,
        CAST(CONVERT(d.name USING utf8) AS CHAR) AS drug_name_local,
        CAST(CONVERT(
            COALESCE(
                NULLIF(TRIM(CONCAT(IFNULL(tm.active_ingredient, ''), ' ', IFNULL(tm.strength, ''))), ''),
                d.name
            ) USING utf8
        ) AS CHAR) AS drug_name,
        CAST(CONVERT(
            COALESCE(NULLIF(TRIM(tm.strength), ''), d.strength)
            USING utf8
        ) AS CHAR) AS strength,
        CAST(CONVERT(d.units USING utf8) AS CHAR) AS units,
        IF(o.sp_use <> '' AND o.sp_use IS NOT NULL,
           CONCAT(IFNULL(sp.name1,''), ' ', IFNULL(sp.name2,''), ' ', IFNULL(sp.name3,'')),
           CONCAT(IFNULL(du.name1,''), ' ', IFNULL(du.name2,''), ' ', IFNULL(du.name3,''))
        ) AS method
    FROM opitemrece o
    INNER JOIN drugitems d ON d.icode = o.icode
    LEFT JOIN tmt_tpu_code tm ON d.tpu_code_list = tm.tpu_code
    LEFT JOIN drugusage du ON du.drugusage = o.drugusage
    LEFT JOIN sp_use sp ON sp.sp_use = o.sp_use
    LEFT JOIN ipt_order_no ion ON ion.order_no = o.order_no
    WHERE o.hn IN ($hn_sql)
      $date_filter
    ORDER BY o.vstdate DESC, o.vsttime DESC, o.item_no ASC
    LIMIT $limit
";

$q_hosxp = mysqli_query($con_hosxp, $sql_hosxp);

if (!$q_hosxp) {
    echo json_encode(['status' => 'error', 'message' => 'SQL Error: ' . mysqli_error($con_hosxp)]);
    mysqli_close($con_hosxp);
    exit;
}

$history = [];
$visits_map = [];
$row_count = 0;

while ($row = mysqli_fetch_assoc($q_hosxp)) {
    $row_count++;
    $method = trim($row['method'] ?? '');
    $ts = strtotime($row['vstdate']);
    $be_year = (int)date('Y', $ts) + 543;

    $row['vstdate_iso'] = date('Y-m-d', $ts);
    $row['vstdate_display'] = date('d/m/', $ts) . $be_year;
    $row['vsttime_display'] = $row['vsttime'] ? substr($row['vsttime'], 0, 8) : '';
    $row['qty'] = floatval($row['qty']);
    $row['numeric_mg_value'] = null;

    $rxdate_raw = trim($row['rxdate'] ?? '');
    $rxtime_raw = trim($row['rxtime'] ?? '');
    $order_date_raw = ($rxdate_raw !== '' && $rxdate_raw !== '0000-00-00') ? $rxdate_raw : $row['vstdate'];
    $order_time_raw = ($rxtime_raw !== '') ? $rxtime_raw : ($row['vsttime'] ?? '');
    $order_ts = strtotime($order_date_raw . ' ' . substr($order_time_raw, 0, 8));
    if (!$order_ts) {
        $order_ts = strtotime($order_date_raw);
    }
    $order_be_year = $order_ts ? ((int)date('Y', $order_ts) + 543) : $be_year;
    $row['order_date_iso'] = $order_ts ? date('Y-m-d', $order_ts) : $row['vstdate_iso'];
    $row['order_date_display'] = $order_ts ? (date('d/m/', $order_ts) . $order_be_year) : $row['vstdate_display'];
    $row['order_time_display'] = $order_time_raw ? substr($order_time_raw, 0, 8) : '';
    $row['order_sort_key'] = $row['order_date_iso'] . ' ' . str_pad(substr($row['order_time_display'], 0, 8), 8, '0');

    $order_type = trim($row['order_type'] ?? '');
    $item_type = trim($row['item_type'] ?? '');
    $row['is_hme'] = (strcasecmp($order_type, 'Hme') === 0) || ($item_type === 'H');

    if ($method !== '' && preg_match('/(\d+(?:\.\d+)?)\s*(?:mcg|µg|μg|ug|ไมโครกรัม)/iu', $method, $mc)) {
        $row['numeric_mg_value'] = (float)$mc[1] / 1000;
    } elseif ($method !== '' && preg_match('/(\d+(?:\.\d+)?)\s*mg(?!cg)/i', $method, $m)) {
        $row['numeric_mg_value'] = (float)$m[1];
    } elseif ($method !== '' && preg_match('/(\d+(?:\.\d+)?)\s*(?:g|gm|gram|กร\.?|กรัม)(?![a-zA-Z])/iu', $method, $g)) {
        $row['numeric_mg_value'] = (float)$g[1] * 1000;
    }

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

    $row['an'] = $an;
    $row['visit_key'] = $visit_key;
    $row['visit_type'] = $visit_type;

    if (!isset($visits_map[$visit_key])) {
        $visits_map[$visit_key] = [
            'visit_key' => $visit_key,
            'visit_type' => $visit_type,
            'vn' => $vn,
            'an' => $an,
            'vstdate_iso' => $row['vstdate_iso'],
            'vstdate_display' => $row['vstdate_display'],
            'vsttime_display' => $row['vsttime_display'],
            'drug_count' => 0,
            'ward_name' => '',
            'admit_date' => '',
        ];
    }
    $visits_map[$visit_key]['drug_count']++;
    if ($row['vstdate_iso'] > $visits_map[$visit_key]['vstdate_iso']) {
        $visits_map[$visit_key]['vstdate_iso'] = $row['vstdate_iso'];
        $visits_map[$visit_key]['vstdate_display'] = $row['vstdate_display'];
        $visits_map[$visit_key]['vsttime_display'] = $row['vsttime_display'];
    }

    $history[] = $row;
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
    $sql_ipt = "
        SELECT i.an, i.regdate,
               CAST(CONVERT(IFNULL(w.name, '') USING utf8) AS CHAR) AS ward_name
        FROM ipt i
        LEFT JOIN iptadm ia ON ia.an = i.an
        LEFT JOIN ward w ON w.ward = ia.ward
        WHERE i.an IN (" . implode(',', $an_in) . ")
    ";
    $qi = mysqli_query($con_hosxp, $sql_ipt);
    if ($qi) {
        while ($ir = mysqli_fetch_assoc($qi)) {
            $a = trim($ir['an'] ?? '');
            if ($a === '' || !isset($ipd_ans[$a])) {
                continue;
            }
            $vk = $ipd_ans[$a];
            $visits_map[$vk]['ward_name'] = trim($ir['ward_name'] ?? '');
            $reg = $ir['regdate'] ?? '';
            if ($reg && $reg !== '0000-00-00') {
                $visits_map[$vk]['admit_date'] = $reg;
                $ts = strtotime($reg);
                if ($ts) {
                    $visits_map[$vk]['vstdate_iso'] = date('Y-m-d', $ts);
                    $visits_map[$vk]['vstdate_display'] = date('d/m/', $ts) . ((int)date('Y', $ts) + 543);
                }
            }
        }
    }
}

$visits = array_values($visits_map);
usort($visits, function ($a, $b) {
    $da = $a['vstdate_iso'] ?? '';
    $db = $b['vstdate_iso'] ?? '';
    if ($da !== $db) {
        return strcmp($db, $da);
    }
    $ta = $a['vsttime_display'] ?? '';
    $tb = $b['vsttime_display'] ?? '';
    return strcmp($tb, $ta);
});

echo json_encode([
    'status' => 'success',
    'patient' => $patient_info,
    'visits' => $visits,
    'history' => $history,
    'meta' => [
        'count' => $row_count,
        'visit_count' => count($visits),
        'months' => $months,
        'limit' => $limit,
        'truncated' => ($row_count >= $limit),
        'drugs_only' => true,
    ],
]);

mysqli_close($con_hosxp);
