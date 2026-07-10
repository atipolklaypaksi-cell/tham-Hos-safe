<?php
/**
 * Drug Reconciliation — เปรียบเทียบยาเดิม/ยาใหม่ ส่งแพทย์
 */

function dr_ensure_tables($con) {
    $q = mysqli_query($con, "SHOW TABLES LIKE 'drug_reconciliation_session'");
    if ($q && mysqli_num_rows($q) > 0) {
        return true;
    }
    $sql = @file_get_contents(__DIR__ . '/sql/drug_reconciliation.sql');
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

function dr_hn_variants($hn) {
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

function dr_hn_sql_in($con_hosxp, $hn) {
    $parts = [];
    foreach (dr_hn_variants($hn) as $v) {
        $parts[] = "'" . mysqli_real_escape_string($con_hosxp, $v) . "'";
    }
    return implode(',', $parts);
}

function dr_normalize_row_key($icode, $tmt = '') {
    $ic = trim((string)$icode);
    if ($ic !== '') {
        return 'ic:' . $ic;
    }
    $t = function_exists('mb_strtolower') ? mb_strtolower(trim((string)$tmt), 'UTF-8') : strtolower(trim((string)$tmt));
    if ($t !== '') {
        return 'nm:' . preg_replace('/\s+/', ' ', $t);
    }
    return 'unk:' . uniqid('', true);
}

function dr_clean_method($s) {
    return trim(preg_replace('/\s+/', ' ', (string)$s));
}

function dr_fetch_patient_context($con_hosxp, $hn, $an = '', $vn = '') {
    $an = trim($an);
    $vn = trim($vn);
    $hn = trim($hn);

    if ($hn === '' && $an !== '') {
        $an_esc = mysqli_real_escape_string($con_hosxp, $an);
        $qh = mysqli_query($con_hosxp, "SELECT hn FROM ipt WHERE an='$an_esc' LIMIT 1");
        if ($qh && ($hr = mysqli_fetch_assoc($qh))) {
            $hn = trim($hr['hn'] ?? '');
        }
    }
    if ($hn === '' && $vn !== '') {
        $vn_esc = mysqli_real_escape_string($con_hosxp, $vn);
        $qv = mysqli_query($con_hosxp, "SELECT hn FROM ovst WHERE vn='$vn_esc' LIMIT 1");
        if ($qv && ($vr = mysqli_fetch_assoc($qv))) {
            $hn = trim($vr['hn'] ?? '');
        }
    }

    $hn_sql = dr_hn_sql_in($con_hosxp, $hn);
    if ($hn_sql === '') {
        return null;
    }

    $ctx = [
        'hn' => '',
        'patient_name' => '',
        'an' => $an,
        'vn' => $vn,
        'visit_type' => 'ipd',
        'doctor_code' => '',
        'doctor_name' => '',
        'ward_name' => '',
        'admit_date' => '',
    ];

    $sql_p = "
        SELECT p.hn,
               CAST(CONVERT(CONCAT(IFNULL(p.pname,''), IFNULL(p.fname,''), ' ', IFNULL(p.lname,'')) USING utf8) AS CHAR) AS full_name
        FROM patient p
        WHERE p.hn IN ($hn_sql)
        LIMIT 1
    ";
    $qp = mysqli_query($con_hosxp, $sql_p);
    if ($qp && ($p = mysqli_fetch_assoc($qp))) {
        $ctx['hn'] = $p['hn'];
        $ctx['patient_name'] = trim($p['full_name'] ?? '');
    }

    $an_esc = mysqli_real_escape_string($con_hosxp, $an);
    if ($an !== '') {
        $sql_i = "
            SELECT i.an, i.hn, i.regdate, i.admdoctor,
                   CAST(CONVERT(CONCAT(IFNULL(doc.name,''), IFNULL(doc.licenseno,'')) USING utf8) AS CHAR) AS doctor_name,
                   CAST(CONVERT(IFNULL(w.name,'') USING utf8) AS CHAR) AS ward_name
            FROM ipt i
            LEFT JOIN doctor doc ON doc.code = i.admdoctor
            LEFT JOIN iptadm ia ON ia.an = i.an
            LEFT JOIN ward w ON w.ward = ia.ward
            WHERE i.an = '$an_esc'
            LIMIT 1
        ";
        $qi = mysqli_query($con_hosxp, $sql_i);
        if ($qi && ($row = mysqli_fetch_assoc($qi))) {
            $ctx['an'] = $row['an'];
            $ctx['hn'] = $row['hn'] ?: $ctx['hn'];
            $ctx['visit_type'] = 'ipd';
            $ctx['doctor_code'] = trim($row['admdoctor'] ?? '');
            $ctx['doctor_name'] = trim($row['doctor_name'] ?? '');
            $ctx['ward_name'] = trim($row['ward_name'] ?? '');
            $ctx['admit_date'] = $row['regdate'] ?? '';
        }
    } elseif ($vn !== '') {
        $vn_esc = mysqli_real_escape_string($con_hosxp, $vn);
        $sql_v = "
            SELECT o.vn, o.hn, o.vstdate,
                   CAST(CONVERT(CONCAT(IFNULL(doc.name,''), IFNULL(doc.licenseno,'')) USING utf8) AS CHAR) AS doctor_name,
                   o.doctor AS doctor_code
            FROM ovst o
            LEFT JOIN doctor doc ON doc.code = o.doctor
            WHERE o.vn = '$vn_esc'
            LIMIT 1
        ";
        $qv = mysqli_query($con_hosxp, $sql_v);
        if ($qv && ($row = mysqli_fetch_assoc($qv))) {
            $ctx['vn'] = $row['vn'];
            $ctx['hn'] = $row['hn'] ?: $ctx['hn'];
            $ctx['visit_type'] = 'opd';
            $ctx['doctor_code'] = trim($row['doctor_code'] ?? '');
            $ctx['doctor_name'] = trim($row['doctor_name'] ?? '');
            $ctx['admit_date'] = $row['vstdate'] ?? '';
        }
    } else {
        $sql_active = "
            SELECT i.an, i.hn, i.regdate, i.admdoctor,
                   CAST(CONVERT(CONCAT(IFNULL(doc.name,''), IFNULL(doc.licenseno,'')) USING utf8) AS CHAR) AS doctor_name,
                   CAST(CONVERT(IFNULL(w.name,'') USING utf8) AS CHAR) AS ward_name
            FROM ipt i
            LEFT JOIN doctor doc ON doc.code = i.admdoctor
            LEFT JOIN iptadm ia ON ia.an = i.an
            LEFT JOIN ward w ON w.ward = ia.ward
            WHERE i.hn IN ($hn_sql)
              AND (i.dchdate IS NULL OR i.dchdate = '0000-00-00')
            ORDER BY i.regdate DESC, i.an DESC
            LIMIT 1
        ";
        $qa = mysqli_query($con_hosxp, $sql_active);
        if ($qa && ($row = mysqli_fetch_assoc($qa))) {
            $ctx['an'] = $row['an'];
            $ctx['hn'] = $row['hn'] ?: $ctx['hn'];
            $ctx['visit_type'] = 'ipd';
            $ctx['doctor_code'] = trim($row['admdoctor'] ?? '');
            $ctx['doctor_name'] = trim($row['doctor_name'] ?? '');
            $ctx['ward_name'] = trim($row['ward_name'] ?? '');
            $ctx['admit_date'] = $row['regdate'] ?? '';
        }
    }

    return $ctx['hn'] !== '' ? $ctx : null;
}

function dr_sql_drug_select() {
    $method = di_sql_method_expr('o', 'du', 'sp', 'od');
    $tmt = hosxp_sql_tmt_drug_name('d', 'tm');
    $strength = hosxp_sql_tmt_strength('d', 'tm');
    return "
        o.icode,
        CAST(CONVERT(d.name USING utf8) AS CHAR) AS drug_name_local,
        $tmt AS drug_name_tmt,
        CAST(CONVERT($strength USING utf8) AS CHAR) AS strength,
        CAST(CONVERT(d.units USING utf8) AS CHAR) AS units,
        o.qty,
        $method AS method,
        o.vstdate AS source_date
    ";
}

function dr_sql_drug_joins() {
    return "
        INNER JOIN drugitems d ON d.icode = o.icode
        " . hosxp_sql_tmt_join('d', 'tm') . "
        " . di_sql_method_joins('o', 'du', 'sp', 'od') . "
    ";
}

function dr_map_drug_row(array $row, $source_label) {
    $icode = trim($row['icode'] ?? '');
    $name_tmt = trim($row['drug_name_tmt'] ?? '');
    $name_local = trim($row['drug_name_local'] ?? '');
    $display = $name_tmt !== '' ? $name_tmt : $name_local;
    return [
        'row_key' => dr_normalize_row_key($icode, $display),
        'icode' => $icode,
        'drug_name' => $name_local,
        'drug_name_tmt' => $name_tmt,
        'strength' => trim($row['strength'] ?? ''),
        'qty' => (float)($row['qty'] ?? 0),
        'units' => trim($row['units'] ?? ''),
        'method' => dr_clean_method($row['method'] ?? ''),
        'source_label' => $source_label,
        'source_date' => $row['source_date'] ?? null,
        'match_group' => '',
        'recon_action' => '',
        'pharmacist_comment' => '',
        'is_manual' => 0,
    ];
}

function dr_dedupe_drugs(array $rows) {
    $map = [];
    foreach ($rows as $r) {
        $key = $r['row_key'] ?? dr_normalize_row_key($r['icode'] ?? '', $r['drug_name_tmt'] ?? $r['drug_name'] ?? '');
        if (!isset($map[$key])) {
            $r['row_key'] = $key;
            $map[$key] = $r;
            continue;
        }
        $existing = $map[$key];
        $existing_date = $existing['source_date'] ?? '';
        $new_date = $r['source_date'] ?? '';
        if ($new_date > $existing_date) {
            $r['row_key'] = $key;
            $map[$key] = $r;
        }
    }
    return array_values($map);
}

function dr_fetch_baseline_drugs($con_hosxp, array $ctx) {
    $hn = $ctx['hn'] ?? '';
    $an = $ctx['an'] ?? '';
    $hn_sql = dr_hn_sql_in($con_hosxp, $hn);
    if ($hn_sql === '') {
        return [];
    }

    $rows = [];
    $sel = dr_sql_drug_select();
    $joins = dr_sql_drug_joins();

    $sql_am = "
        SELECT $sel
        FROM active_medication am
        INNER JOIN opitemrece o ON o.hos_guid = am.hos_guid
        $joins
        WHERE am.hn IN ($hn_sql)
    ";
    $q = mysqli_query($con_hosxp, $sql_am);
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $rows[] = dr_map_drug_row($r, 'ยาที่ใช้ประจำ (Active)');
        }
    }

    $sql_home = "
        SELECT $sel, ht.opitemrece_home_type_name
        FROM opitemrece_home oh
        INNER JOIN opitemrece o ON o.hos_guid = oh.opi_guid
        INNER JOIN opitemrece_home_type ht ON ht.opitemrece_home_type_id = oh.opitemrece_home_type_id
        $joins
        WHERE o.hn IN ($hn_sql)
          AND oh.opitemrece_home_type_id = 2
        ORDER BY o.vstdate DESC
        LIMIT 80
    ";
    $q = mysqli_query($con_hosxp, $sql_home);
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $label = 'ยาใช้ที่บ้าน';
            if (!empty($r['opitemrece_home_type_name'])) {
                $label = trim($r['opitemrece_home_type_name']);
            }
            $rows[] = dr_map_drug_row($r, $label);
        }
    }

    $date_filter = '';
    if (!empty($ctx['admit_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ctx['admit_date'])) {
        $admit_esc = mysqli_real_escape_string($con_hosxp, $ctx['admit_date']);
        $date_filter = " AND o.vstdate < '$admit_esc' ";
    }
    $sql_last_vn = "
        SELECT vn FROM opitemrece o
        WHERE o.hn IN ($hn_sql)
          AND (o.an IS NULL OR o.an = '')
          AND o.vstdate IS NOT NULL
          $date_filter
        ORDER BY o.vstdate DESC, o.vsttime DESC
        LIMIT 1
    ";
    $last_vn = '';
    $qv = mysqli_query($con_hosxp, $sql_last_vn);
    if ($qv && ($vr = mysqli_fetch_assoc($qv))) {
        $last_vn = trim($vr['vn'] ?? '');
    }
    if ($last_vn !== '') {
        $vn_esc = mysqli_real_escape_string($con_hosxp, $last_vn);
        $sql_opd = "
            SELECT $sel
            FROM opitemrece o
            $joins
            WHERE o.vn = '$vn_esc'
            ORDER BY o.item_no ASC
        ";
        $q = mysqli_query($con_hosxp, $sql_opd);
        if ($q) {
            while ($r = mysqli_fetch_assoc($q)) {
                $rows[] = dr_map_drug_row($r, 'OPD ครั้งล่าสุดก่อน Admit');
            }
        }
    }

    $sql_hist = "
        SELECT medication_name, taken_from, taken_date
        FROM pt_medication_history
        WHERE hn IN ($hn_sql)
        ORDER BY taken_date DESC, pt_medication_history_id DESC
        LIMIT 40
    ";
    $q = mysqli_query($con_hosxp, $sql_hist);
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $name = trim($r['medication_name'] ?? '');
            if ($name === '') {
                continue;
            }
            $rows[] = [
                'row_key' => dr_normalize_row_key('', $name),
                'icode' => '',
                'drug_name' => $name,
                'drug_name_tmt' => $name,
                'strength' => '',
                'qty' => 0,
                'units' => '',
                'method' => '',
                'source_label' => 'ประวัติยาที่ผู้ป่วยแจ้ง' . (trim($r['taken_from'] ?? '') !== '' ? ' (' . trim($r['taken_from']) . ')' : ''),
                'source_date' => $r['taken_date'] ?? null,
                'match_group' => '',
                'recon_action' => '',
                'pharmacist_comment' => '',
                'is_manual' => 0,
            ];
        }
    }

    if ($an !== '') {
        $an_esc = mysqli_real_escape_string($con_hosxp, $an);
        $sql_mrd = "
            SELECT mrd.medication_name, mrd.usage_name, mrd.last_receive_date, mrd.qty,
                   drc.doctor_reconciliation_command_name
            FROM medication_reconciliation mr
            INNER JOIN medication_reconciliation_detail mrd ON mrd.medication_reconciliation_id = mr.medication_reconciliation_id
            LEFT JOIN doctor_reconciliation_command drc ON drc.doctor_reconciliation_command_id = mrd.doctor_reconciliation_command_id
            WHERE mr.an = '$an_esc'
            ORDER BY mrd.medication_reconciliation_detail_id DESC
            LIMIT 60
        ";
        $q = mysqli_query($con_hosxp, $sql_mrd);
        if ($q) {
            while ($r = mysqli_fetch_assoc($q)) {
                $name = trim($r['medication_name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $action = '';
                $cmd = trim($r['doctor_reconciliation_command_name'] ?? '');
                if ($cmd === 'ใช้ต่อ') {
                    $action = 'continue';
                } elseif ($cmd === 'หยุดใช้') {
                    $action = 'off';
                }
                $rows[] = [
                    'row_key' => dr_normalize_row_key('', $name),
                    'icode' => '',
                    'drug_name' => $name,
                    'drug_name_tmt' => $name,
                    'strength' => '',
                    'qty' => (float)($r['qty'] ?? 0),
                    'units' => '',
                    'method' => dr_clean_method($r['usage_name'] ?? ''),
                    'source_label' => 'Med Recon HOSxP',
                    'source_date' => $r['last_receive_date'] ?? null,
                    'match_group' => '',
                    'recon_action' => $action,
                    'pharmacist_comment' => '',
                    'is_manual' => 0,
                ];
            }
        }
    }

    return dr_dedupe_drugs($rows);
}

function dr_fetch_current_drugs($con_hosxp, array $ctx) {
    $hn_sql = dr_hn_sql_in($con_hosxp, $ctx['hn'] ?? '');
    if ($hn_sql === '') {
        return [];
    }

    $rows = [];
    $sel = dr_sql_drug_select();
    $joins = dr_sql_drug_joins();
    $an = trim($ctx['an'] ?? '');
    $vn = trim($ctx['vn'] ?? '');

    if ($an !== '') {
        $an_esc = mysqli_real_escape_string($con_hosxp, $an);
        $sql = "
            SELECT $sel
            FROM opitemrece o
            $joins
            WHERE o.an = '$an_esc'
            ORDER BY o.vstdate DESC, o.vsttime DESC, o.item_no ASC
            LIMIT 200
        ";
        $q = mysqli_query($con_hosxp, $sql);
        if ($q) {
            while ($r = mysqli_fetch_assoc($q)) {
                $rows[] = dr_map_drug_row($r, 'สั่งใน IPD (ปัจจุบัน)');
            }
        }
    } elseif ($vn !== '') {
        $vn_esc = mysqli_real_escape_string($con_hosxp, $vn);
        $sql = "
            SELECT $sel
            FROM opitemrece o
            $joins
            WHERE o.vn = '$vn_esc'
            ORDER BY o.item_no ASC
        ";
        $q = mysqli_query($con_hosxp, $sql);
        if ($q) {
            while ($r = mysqli_fetch_assoc($q)) {
                $rows[] = dr_map_drug_row($r, 'สั่งใน OPD (ปัจจุบัน)');
            }
        }
    } else {
        $sql = "
            SELECT $sel
            FROM opitemrece o
            $joins
            WHERE o.hn IN ($hn_sql)
              AND o.vstdate >= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
            ORDER BY o.vstdate DESC, o.vsttime DESC
            LIMIT 120
        ";
        $q = mysqli_query($con_hosxp, $sql);
        if ($q) {
            while ($r = mysqli_fetch_assoc($q)) {
                $rows[] = dr_map_drug_row($r, 'รายการยาล่าสุด (3 วัน)');
            }
        }
    }

    return dr_dedupe_drugs($rows);
}

function dr_build_comparison(array $baseline, array $current) {
    $baseline_map = [];
    foreach ($baseline as $i => $row) {
        $baseline[$i]['match_group'] = $row['row_key'];
        $baseline_map[$row['row_key']] = $i;
    }

    $current_map = [];
    foreach ($current as $i => $row) {
        $key = $row['row_key'];
        if (isset($baseline_map[$key])) {
            $current[$i]['match_group'] = $key;
            $current_map[$key] = $i;
            continue;
        }
        $matched = false;
        if (!empty($row['icode'])) {
            foreach ($baseline as $brow) {
                if ($brow['icode'] === $row['icode']) {
                    $current[$i]['match_group'] = $brow['row_key'];
                    $current_map[$brow['row_key']] = $i;
                    $matched = true;
                    break;
                }
            }
        }
        if (!$matched) {
            $current[$i]['match_group'] = 'new:' . $key;
        }
    }

    $pairs = [];
    $used_current = [];
    foreach ($baseline as $b) {
        $mg = $b['match_group'];
        $c = null;
        if (isset($current_map[$mg])) {
            $ci = $current_map[$mg];
            $c = $current[$ci];
            $used_current[$ci] = true;
        }
        $status = 'baseline_only';
        if ($c !== null) {
            $status = ($b['method'] === $c['method'] && abs($b['qty'] - $c['qty']) < 0.001) ? 'matched' : 'changed';
        }
        $suggested = '';
        if ($status === 'matched') {
            $suggested = 'continue';
        } elseif ($status === 'baseline_only') {
            $suggested = 'off';
        } elseif ($status === 'changed') {
            $suggested = 'hold';
        }
        $pairs[] = [
            'status' => $status,
            'match_group' => $mg,
            'baseline' => $b,
            'current' => $c,
            'recon_action' => !empty($b['recon_action']) ? $b['recon_action'] : $suggested,
            'pharmacist_comment' => $b['pharmacist_comment'] ?? '',
            'suggested_action' => $suggested,
        ];
    }

    foreach ($current as $ci => $c) {
        if (isset($used_current[$ci])) {
            continue;
        }
        $pairs[] = [
            'status' => 'current_only',
            'match_group' => $c['match_group'],
            'baseline' => null,
            'current' => $c,
            'recon_action' => 'continue',
            'pharmacist_comment' => '',
            'suggested_action' => 'continue',
        ];
    }

    return [
        'baseline' => $baseline,
        'current' => $current,
        'pairs' => $pairs,
        'summary' => [
            'baseline_count' => count($baseline),
            'current_count' => count($current),
            'matched' => count(array_filter($pairs, function ($p) { return $p['status'] === 'matched'; })),
            'changed' => count(array_filter($pairs, function ($p) { return $p['status'] === 'changed'; })),
            'baseline_only' => count(array_filter($pairs, function ($p) { return $p['status'] === 'baseline_only'; })),
            'current_only' => count(array_filter($pairs, function ($p) { return $p['status'] === 'current_only'; })),
        ],
    ];
}

function dr_action_label($action) {
    $map = [
        'continue' => 'ต่อ (Continue)',
        'hold' => 'Hold',
        'off' => 'Off (หยุด)',
        '' => '—',
    ];
    return $map[$action] ?? $action;
}

function dr_load_session($con, $session_id) {
    $id = (int)$session_id;
    if ($id <= 0) {
        return null;
    }
    $q = mysqli_query($con, "SELECT * FROM drug_reconciliation_session WHERE id=$id LIMIT 1");
    if (!$q || mysqli_num_rows($q) === 0) {
        return null;
    }
    $session = mysqli_fetch_assoc($q);
    $items = ['baseline' => [], 'current' => []];
    $qi = mysqli_query($con, "SELECT * FROM drug_reconciliation_item WHERE session_id=$id ORDER BY list_type ASC, sort_order ASC, id ASC");
    if ($qi) {
        while ($r = mysqli_fetch_assoc($qi)) {
            $lt = $r['list_type'] === 'current' ? 'current' : 'baseline';
            $items[$lt][] = [
                'id' => (int)$r['id'],
                'row_key' => $r['row_key'],
                'icode' => $r['icode'],
                'drug_name' => $r['drug_name'],
                'drug_name_tmt' => $r['drug_name_tmt'],
                'strength' => $r['strength'],
                'qty' => (float)$r['qty'],
                'units' => $r['units'],
                'method' => $r['method'],
                'source_label' => $r['source_label'],
                'source_date' => $r['source_date'],
                'match_group' => $r['match_group'],
                'recon_action' => $r['recon_action'],
                'pharmacist_comment' => $r['pharmacist_comment'],
                'is_manual' => (int)$r['is_manual'],
            ];
        }
    }
    $session['items'] = $items;
    return $session;
}

function dr_save_session($con, array $payload, $user_id, $user_name) {
    dr_ensure_tables($con);

    $session_id = (int)($payload['session_id'] ?? 0);
    $ctx = $payload['context'] ?? [];
    $pairs = $payload['pairs'] ?? [];
    $status = trim($payload['status'] ?? 'draft');
    if (!in_array($status, ['draft', 'sent', 'cancelled'], true)) {
        $status = 'draft';
    }

    $hn = mysqli_real_escape_string($con, trim($ctx['hn'] ?? ''));
    $an = mysqli_real_escape_string($con, trim($ctx['an'] ?? ''));
    $vn = mysqli_real_escape_string($con, trim($ctx['vn'] ?? ''));
    $visit_type = ($ctx['visit_type'] ?? 'ipd') === 'opd' ? 'opd' : 'ipd';
    $patient_name = mysqli_real_escape_string($con, trim($ctx['patient_name'] ?? ''));
    $doctor_code = mysqli_real_escape_string($con, trim($ctx['doctor_code'] ?? ''));
    $doctor_name = mysqli_real_escape_string($con, trim($ctx['doctor_name'] ?? ''));
    $ward_name = mysqli_real_escape_string($con, trim($ctx['ward_name'] ?? ''));
    $ph_note = mysqli_real_escape_string($con, trim($payload['pharmacist_note'] ?? ''));
    $uid = (int)$user_id;
    $uname = mysqli_real_escape_string($con, trim($user_name));
    $sent_sql = ($status === 'sent') ? ', sent_at=NOW()' : '';

    if ($session_id > 0) {
        $ok = mysqli_query($con, "UPDATE drug_reconciliation_session SET
            hn='$hn', an='$an', vn='$vn', visit_type='$visit_type',
            patient_name='$patient_name', doctor_code='$doctor_code', doctor_name='$doctor_name',
            ward_name='$ward_name', status='$status', pharmacist_user_id=$uid,
            pharmacist_name='$uname', pharmacist_note='$ph_note' $sent_sql
            WHERE id=$session_id");
        if (!$ok) {
            return ['ok' => false, 'error' => mysqli_error($con)];
        }
        mysqli_query($con, "DELETE FROM drug_reconciliation_item WHERE session_id=$session_id");
    } else {
        $ok = mysqli_query($con, "INSERT INTO drug_reconciliation_session
            (hn, an, vn, visit_type, patient_name, doctor_code, doctor_name, ward_name,
             status, pharmacist_user_id, pharmacist_name, pharmacist_note" . ($status === 'sent' ? ', sent_at' : '') . ")
            VALUES ('$hn','$an','$vn','$visit_type','$patient_name','$doctor_code','$doctor_name','$ward_name',
             '$status',$uid,'$uname','$ph_note'" . ($status === 'sent' ? ', NOW()' : '') . ")");
        if (!$ok) {
            return ['ok' => false, 'error' => mysqli_error($con)];
        }
        $session_id = (int)mysqli_insert_id($con);
    }

    $sort = 0;
    foreach ($pairs as $pair) {
        $action = trim($pair['recon_action'] ?? $pair['suggested_action'] ?? '');
        if (!in_array($action, ['continue', 'hold', 'off', ''], true)) {
            $action = '';
        }
        $comment = mysqli_real_escape_string($con, trim($pair['pharmacist_comment'] ?? ''));
        $mg = mysqli_real_escape_string($con, trim($pair['match_group'] ?? ''));
        $action_esc = mysqli_real_escape_string($con, $action);

        foreach (['baseline', 'current'] as $side) {
            $row = $side === 'baseline' ? ($pair['baseline'] ?? null) : ($pair['current'] ?? null);
            if (!$row || !is_array($row)) {
                continue;
            }
            $sort++;
            $rk = mysqli_real_escape_string($con, $row['row_key'] ?? '');
            $ic = mysqli_real_escape_string($con, $row['icode'] ?? '');
            $dn = mysqli_real_escape_string($con, $row['drug_name'] ?? '');
            $dnt = mysqli_real_escape_string($con, $row['drug_name_tmt'] ?? '');
            $st = mysqli_real_escape_string($con, $row['strength'] ?? '');
            $qty = (float)($row['qty'] ?? 0);
            $un = mysqli_real_escape_string($con, $row['units'] ?? '');
            $mt = mysqli_real_escape_string($con, $row['method'] ?? '');
            $sl = mysqli_real_escape_string($con, $row['source_label'] ?? '');
            $sd = $row['source_date'] ?? null;
            $sd_sql = ($sd && preg_match('/^\d{4}-\d{2}-\d{2}$/', $sd)) ? "'" . mysqli_real_escape_string($con, $sd) . "'" : 'NULL';
            $lt = $side;
            $manual = (int)($row['is_manual'] ?? 0);

            mysqli_query($con, "INSERT INTO drug_reconciliation_item
                (session_id, list_type, row_key, icode, drug_name, drug_name_tmt, strength, qty, units, method,
                 source_label, source_date, match_group, recon_action, pharmacist_comment, sort_order, is_manual)
                VALUES ($session_id, '$lt', '$rk', '$ic', '$dn', '$dnt', '$st', $qty, '$un', '$mt',
                 '$sl', $sd_sql, '$mg', '$action_esc', '$comment', $sort, $manual)");
        }
    }

    return ['ok' => true, 'session_id' => $session_id];
}

function dr_list_sessions($con, $hn = '', $limit = 20) {
    dr_ensure_tables($con);
    $limit = max(1, min(50, (int)$limit));
    $where = '1=1';
    if (trim($hn) !== '') {
        $variants = dr_hn_variants($hn);
        $in = [];
        foreach ($variants as $v) {
            $in[] = "'" . mysqli_real_escape_string($con, $v) . "'";
        }
        if (!empty($in)) {
            $where = 'hn IN (' . implode(',', $in) . ')';
        }
    }
    $list = [];
    $q = mysqli_query($con, "SELECT id, hn, an, vn, visit_type, patient_name, doctor_name, ward_name,
        status, pharmacist_name, sent_at, created_at, updated_at
        FROM drug_reconciliation_session WHERE $where
        ORDER BY COALESCE(sent_at, created_at) DESC LIMIT $limit");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $list[] = $r;
        }
    }
    return $list;
}

function dr_pairs_from_session_items(array $items_baseline, array $items_current) {
    $by_group = [];
    foreach ($items_baseline as $b) {
        $mg = $b['match_group'] ?: $b['row_key'];
        $by_group[$mg]['baseline'] = $b;
        $by_group[$mg]['action'] = $b['recon_action'] ?? '';
        $by_group[$mg]['comment'] = $b['pharmacist_comment'] ?? '';
    }
    foreach ($items_current as $c) {
        $mg = $c['match_group'] ?: $c['row_key'];
        if (!isset($by_group[$mg])) {
            $by_group[$mg] = ['action' => $c['recon_action'] ?? '', 'comment' => $c['pharmacist_comment'] ?? ''];
        }
        $by_group[$mg]['current'] = $c;
        if (empty($by_group[$mg]['action'])) {
            $by_group[$mg]['action'] = $c['recon_action'] ?? '';
        }
    }

    $pairs = [];
    foreach ($by_group as $mg => $g) {
        $b = $g['baseline'] ?? null;
        $c = $g['current'] ?? null;
        $status = 'baseline_only';
        if ($b && $c) {
            $status = ($b['method'] === $c['method'] && abs($b['qty'] - $c['qty']) < 0.001) ? 'matched' : 'changed';
        } elseif ($c) {
            $status = 'current_only';
        }
        $pairs[] = [
            'status' => $status,
            'match_group' => $mg,
            'baseline' => $b,
            'current' => $c,
            'recon_action' => $g['action'] ?? '',
            'pharmacist_comment' => $g['comment'] ?? '',
        ];
    }
    return $pairs;
}
