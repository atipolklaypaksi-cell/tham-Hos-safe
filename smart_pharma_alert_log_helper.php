<?php
/**
 * Smart Pharma — Alert Log & Intervention tracking
 */

function sp_alert_log_ensure_tables($con) {
    $sql = @file_get_contents(__DIR__ . '/sql/smart_pharma_alert_logs.sql');
    if (!$sql) {
        return false;
    }
    if (!mysqli_multi_query($con, $sql)) {
        return false;
    }
    while (mysqli_more_results($con)) {
        mysqli_next_result($con);
    }
    $cols = [
        'alert_log_id' => "ALTER TABLE pharmacist_interventions ADD COLUMN alert_log_id INT NULL DEFAULT NULL AFTER module_key",
        'resolves_alert' => "ALTER TABLE pharmacist_interventions ADD COLUMN resolves_alert TINYINT(1) NOT NULL DEFAULT 0 AFTER alert_log_id",
    ];
    foreach ($cols as $col => $alter) {
        $q = mysqli_query($con, "SHOW COLUMNS FROM pharmacist_interventions LIKE '$col'");
        if ($q && mysqli_num_rows($q) === 0) {
            mysqli_query($con, $alter);
        }
    }
    return true;
}

function sp_alert_log_uid($patient_type, $screen_date, $hn, $vn, $module_key) {
    return implode('|', [
        $patient_type,
        trim((string)$screen_date),
        trim((string)$hn),
        trim((string)$vn),
        trim((string)$module_key),
    ]);
}

function sp_alert_log_status_label($row) {
    if (($row['status'] ?? '') === 'resolved') {
        if (($row['resolved_by'] ?? '') === 'auto_cleared') {
            return 'แก้ไขแล้ว — ไม่พบ Alert (HOSxP)';
        }
        if (($row['resolved_by'] ?? '') === 'intervention') {
            return 'แก้ไขแล้ว — เภสัชตอบ Intervention';
        }
        return 'แก้ไขแล้ว';
    }
    if (!empty($row['has_pharmacist_note'])) {
        return 'รอแก้ไข HOSxP (มีบันทึกเภสัช)';
    }
    return 'รอการแก้ไข';
}

/**
 * @return array{id:int, action:string}|null  action: new|updated|reopened
 */
function sp_alert_log_upsert_pending($con, $patient_type, $screen_date, array $patient, $module_key, array $mod) {
    $hn = trim((string)($patient['hn_full'] ?? $patient['hn'] ?? ''));
    if ($hn === '') {
        return null;
    }
    $vn = trim((string)($patient['vn'] ?? ''));
    $level = ($mod['level'] ?? '') === 'hard' ? 'hard' : 'soft';
    $uid = sp_alert_log_uid($patient_type, $screen_date, $hn, $vn, $module_key);
    $uid_esc = mysqli_real_escape_string($con, $uid);
    $hn_esc = mysqli_real_escape_string($con, $hn);
    $vn_esc = mysqli_real_escape_string($con, $vn);
    $an_esc = mysqli_real_escape_string($con, trim((string)($patient['an'] ?? '')));
    $ptype_esc = mysqli_real_escape_string($con, $patient_type);
    $sdate_esc = mysqli_real_escape_string($con, $screen_date);
    $vst_esc = mysqli_real_escape_string($con, $patient['vstdate'] ?? $screen_date);
    $pt_esc = mysqli_real_escape_string($con, $patient['ptname'] ?? '');
    $rxdate = trim((string)($patient['rxdate'] ?? ''));
    $rxdate_sql = ($rxdate !== '' && $rxdate !== '0000-00-00')
        ? "'" . mysqli_real_escape_string($con, $rxdate) . "'"
        : 'NULL';
    $rxtime_esc = mysqli_real_escape_string($con, $patient['rxtime'] ?? '');
    $ward_esc = mysqli_real_escape_string($con, $patient['ward_display'] ?? '');
    $mod_esc = mysqli_real_escape_string($con, $module_key);
    $lvl_esc = mysqli_real_escape_string($con, $level);
    $sum_esc = mysqli_real_escape_string($con, $mod['summary'] ?? '');
    $details = $mod['details'] ?? [];
    if (!is_array($details)) {
        $details = [];
    }
    $det_esc = mysqli_real_escape_string($con, json_encode($details, JSON_UNESCAPED_UNICODE));

    $q = mysqli_query($con, "SELECT id, status, alert_level, alert_summary, alert_details
        FROM smart_pharma_alert_logs WHERE alert_uid='$uid_esc' LIMIT 1");
    $existing = $q ? mysqli_fetch_assoc($q) : null;
    if ($existing && ($existing['status'] ?? '') === 'resolved') {
        mysqli_query($con, "UPDATE smart_pharma_alert_logs SET
            status='pending', resolved_by=NULL, resolved_at=NULL, has_pharmacist_note=0,
            alert_level='$lvl_esc', alert_summary='$sum_esc', alert_details='$det_esc',
            ptname='$pt_esc', rxdate=$rxdate_sql, rxtime='$rxtime_esc', ward_display='$ward_esc',
            last_seen_at=NOW()
            WHERE alert_uid='$uid_esc'");
        require_once __DIR__ . '/smart_pharma_notify_helper.php';
        sp_notify_clear_sent_for_alert($con, $patient_type, $screen_date, $hn, $vn, $module_key);
        return ['id' => (int)$existing['id'], 'action' => 'reopened'];
    }
    if ($existing) {
        $old_level = trim((string)($existing['alert_level'] ?? ''));
        $old_summary = trim((string)($existing['alert_summary'] ?? ''));
        $old_details = trim((string)($existing['alert_details'] ?? ''));
        $changed = ($old_level !== $level) || ($old_summary !== ($mod['summary'] ?? '')) || ($old_details !== json_encode($details, JSON_UNESCAPED_UNICODE));

        mysqli_query($con, "UPDATE smart_pharma_alert_logs SET
            alert_level='$lvl_esc', alert_summary='$sum_esc', alert_details='$det_esc',
            ptname='$pt_esc', rxdate=$rxdate_sql, rxtime='$rxtime_esc', ward_display='$ward_esc',
            last_seen_at=NOW()
            WHERE alert_uid='$uid_esc'");
        if ($changed) {
            require_once __DIR__ . '/smart_pharma_notify_helper.php';
            sp_notify_clear_sent_for_alert($con, $patient_type, $screen_date, $hn, $vn, $module_key);
        }
        return ['id' => (int)$existing['id'], 'action' => 'updated'];
    }
    if (!mysqli_query($con, "INSERT INTO smart_pharma_alert_logs
        (alert_uid, hn, vn, an, patient_type, screen_date, vstdate, ptname, rxdate, rxtime, ward_display,
         module_key, alert_level, alert_summary, alert_details, status, last_seen_at)
        VALUES ('$uid_esc','$hn_esc','$vn_esc','$an_esc','$ptype_esc','$sdate_esc','$vst_esc','$pt_esc',
            $rxdate_sql,'$rxtime_esc','$ward_esc','$mod_esc','$lvl_esc','$sum_esc','$det_esc','pending',NOW())")) {
        return null;
    }
    return ['id' => (int)mysqli_insert_id($con), 'action' => 'new'];
}

/**
 * ซิงก์ Alert Log จากผลคัดกรองทั้งวัน (ไม่สร้างแถวซ้ำ — ใช้ alert_uid เป็น key)
 * @return array{new:int, updated:int, reopened:int, resolved:int, skipped:int}
 */
function sp_alert_log_sync($con, $patient_type, $screen_date, array $patients) {
    $stats = ['new' => 0, 'updated' => 0, 'reopened' => 0, 'resolved' => 0, 'skipped' => 0];
    $active_uids = [];

    foreach ($patients as $patient) {
        if (($patient['overall'] ?? '') !== 'alert') {
            continue;
        }
        $mods = $patient['modules'] ?? [];
        foreach ($mods as $module_key => $mod) {
            if (($mod['status'] ?? '') !== 'alert') {
                continue;
            }
            $uid = sp_alert_log_uid(
                $patient_type,
                $screen_date,
                $patient['hn_full'] ?? $patient['hn'] ?? '',
                $patient['vn'] ?? '',
                $module_key
            );
            $active_uids[$uid] = true;
            $upsert = sp_alert_log_upsert_pending($con, $patient_type, $screen_date, $patient, $module_key, $mod);
            if (!$upsert) {
                $stats['skipped']++;
                continue;
            }
            $action = $upsert['action'] ?? 'updated';
            if (isset($stats[$action])) {
                $stats[$action]++;
            }
        }
    }

    $ptype_esc = mysqli_real_escape_string($con, $patient_type);
    $sdate_esc = mysqli_real_escape_string($con, $screen_date);
    $q = mysqli_query($con, "SELECT id, alert_uid FROM smart_pharma_alert_logs
        WHERE status='pending' AND patient_type='$ptype_esc' AND screen_date='$sdate_esc'");
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $uid = $row['alert_uid'] ?? '';
            if ($uid !== '' && !isset($active_uids[$uid])) {
                $id = (int)$row['id'];
                mysqli_query($con, "UPDATE smart_pharma_alert_logs SET
                    status='resolved', resolved_by='auto_cleared', resolved_at=NOW()
                    WHERE id=$id");
                $parts = explode('|', $uid, 5);
                if (count($parts) >= 5) {
                    require_once __DIR__ . '/smart_pharma_notify_helper.php';
                    sp_notify_clear_sent_for_alert($con, $parts[0], $parts[1], $parts[2], $parts[3], $parts[4]);
                }
                $stats['resolved']++;
            }
        }
    }
    return $stats;
}

function sp_alert_log_find_pending($con, $patient_type, $screen_date, $hn, $vn, $module_key) {
    $uid = sp_alert_log_uid($patient_type, $screen_date, $hn, $vn, $module_key);
    $uid_esc = mysqli_real_escape_string($con, $uid);
    $q = mysqli_query($con, "SELECT * FROM smart_pharma_alert_logs
        WHERE alert_uid='$uid_esc' AND status='pending' LIMIT 1");
    return $q ? mysqli_fetch_assoc($q) : null;
}

function sp_alert_log_find_by_uid($con, $patient_type, $screen_date, $hn, $vn, $module_key) {
    $uid = sp_alert_log_uid($patient_type, $screen_date, $hn, $vn, $module_key);
    $uid_esc = mysqli_real_escape_string($con, $uid);
    $q = mysqli_query($con, "SELECT * FROM smart_pharma_alert_logs WHERE alert_uid='$uid_esc' LIMIT 1");
    return $q ? mysqli_fetch_assoc($q) : null;
}

function sp_alert_log_mark_pharmacist_note($con, $alert_log_id) {
    $id = (int)$alert_log_id;
    if ($id <= 0) {
        return false;
    }
    $q = mysqli_query($con, "SELECT * FROM smart_pharma_alert_logs WHERE id=$id AND status='pending' LIMIT 1");
    $row = $q ? mysqli_fetch_assoc($q) : null;
    if (!$row) {
        return false;
    }
    $ok = mysqli_query($con, "UPDATE smart_pharma_alert_logs SET has_pharmacist_note=1 WHERE id=$id AND status='pending'");
    if ($ok) {
        require_once __DIR__ . '/smart_pharma_notify_helper.php';
        sp_notify_clear_sent_for_alert(
            $con,
            $row['patient_type'] ?? 'OPD',
            $row['screen_date'] ?? date('Y-m-d'),
            $row['hn'] ?? '',
            $row['vn'] ?? '',
            $row['module_key'] ?? ''
        );
    }
    return $ok;
}

function sp_alert_log_resolve_by_intervention($con, $alert_log_id) {
    $id = (int)$alert_log_id;
    if ($id <= 0) {
        return false;
    }
    $q = mysqli_query($con, "SELECT * FROM smart_pharma_alert_logs WHERE id=$id AND status='pending' LIMIT 1");
    $row = $q ? mysqli_fetch_assoc($q) : null;
    if (!$row) {
        return false;
    }
    $ok = mysqli_query($con, "UPDATE smart_pharma_alert_logs SET
        status='resolved', resolved_by='intervention', resolved_at=NOW(), has_pharmacist_note=1
        WHERE id=$id AND status='pending'");
    if ($ok) {
        require_once __DIR__ . '/smart_pharma_notify_helper.php';
        sp_notify_clear_sent_for_alert(
            $con,
            $row['patient_type'] ?? 'OPD',
            $row['screen_date'] ?? date('Y-m-d'),
            $row['hn'] ?? '',
            $row['vn'] ?? '',
            $row['module_key'] ?? ''
        );
    }
    return $ok;
}

/**
 * Alert Log ล่าสุดสำหรับทดสอบ Telegram (ข้อมูลจริง)
 */
function sp_alert_log_recent_samples($con, $limit = 3) {
    $limit = max(1, min(10, (int)$limit));
    $sql = "SELECT l.*, m.label_th AS module_label
        FROM smart_pharma_alert_logs l
        LEFT JOIN smart_pharma_modules m ON m.module_key = l.module_key
        ORDER BY l.last_seen_at DESC, l.id DESC
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

function sp_alert_log_list($con, array $filters = []) {
    $where = ['1=1'];
    $start = $filters['start_date'] ?? '';
    $end = $filters['end_date'] ?? '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
        $where[] = "l.screen_date >= '" . mysqli_real_escape_string($con, $start) . "'";
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
        $where[] = "l.screen_date <= '" . mysqli_real_escape_string($con, $end) . "'";
    }
    $status = $filters['status'] ?? '';
    if (in_array($status, ['pending', 'resolved'], true)) {
        $where[] = "l.status='" . mysqli_real_escape_string($con, $status) . "'";
    }
    $resolved_by = $filters['resolved_by'] ?? '';
    if (in_array($resolved_by, ['auto_cleared', 'intervention'], true)) {
        $where[] = "l.resolved_by='" . mysqli_real_escape_string($con, $resolved_by) . "'";
    }
    $ptype = $filters['patient_type'] ?? '';
    if (in_array($ptype, ['OPD', 'IPD'], true)) {
        $where[] = "l.patient_type='" . mysqli_real_escape_string($con, $ptype) . "'";
    }
    $limit = max(1, min(500, (int)($filters['limit'] ?? 300)));
    $sql = "SELECT l.*, m.label_th AS module_label,
            (SELECT COUNT(*) FROM pharmacist_interventions pi WHERE pi.alert_log_id = l.id) AS intervention_count,
            (SELECT pi.id FROM pharmacist_interventions pi WHERE pi.alert_log_id = l.id ORDER BY pi.created_at DESC LIMIT 1) AS latest_intervention_id
        FROM smart_pharma_alert_logs l
        LEFT JOIN smart_pharma_modules m ON m.module_key = l.module_key
        WHERE " . implode(' AND ', $where) . "
        ORDER BY l.status ASC, l.last_seen_at DESC, l.id DESC
        LIMIT $limit";
    $q = mysqli_query($con, $sql);
    $rows = [];
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $r['status_label'] = sp_alert_log_status_label($r);
            $rows[] = $r;
        }
    }
    return $rows;
}

function sp_alert_log_interventions($con, $alert_log_id) {
    $id = (int)$alert_log_id;
    if ($id <= 0) {
        return [];
    }
    $q = mysqli_query($con, "SELECT * FROM pharmacist_interventions WHERE alert_log_id=$id ORDER BY created_at DESC");
    $rows = [];
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $rows[] = $r;
        }
    }
    return $rows;
}
