<?php
/**
 * บริหารยาเสพติด/วัตถุออกฤทธิ์ — ดึงรายงานการใช้จาก HOSxP ตัดสต๊อกคงคลัง
 */

function cdm_drug_types() {
    return ['narcotic', 'psychotropic'];
}

function cdm_normalize_type($type) {
    $type = trim((string)$type);
    return in_array($type, cdm_drug_types(), true) ? $type : '';
}

function cdm_type_label($type) {
    $map = [
        'narcotic' => 'ยาเสพติด',
        'psychotropic' => 'วัตถุออกฤทธิ์',
    ];
    return $map[cdm_normalize_type($type)] ?? $type;
}

function cdm_stock_statuses() {
    return ['none', 'pending_form', 'pending_remains', 'returned_incomplete'];
}

function cdm_stock_status_label($status) {
    $map = [
        'none' => 'ปกติ',
        'pending_form' => 'ค้างใบ',
        'pending_remains' => 'ค้างซาก',
        'returned_incomplete' => 'ซากที่ได้กลับมายังไม่ครบ',
    ];
    $status = trim((string)$status);
    return $map[$status] ?? $status;
}

function cdm_stock_status_badge_class($status) {
    $map = [
        'none' => 'bg-success',
        'pending_form' => 'bg-warning text-dark',
        'pending_remains' => 'bg-danger',
        'returned_incomplete' => 'bg-info text-dark',
    ];
    return $map[$status] ?? 'bg-secondary';
}

function cdm_ensure_tables($con) {
    static $ready = false;
    if ($ready) {
        return true;
    }
    $q = mysqli_query($con, "SHOW TABLES LIKE 'controlled_drug_items'");
    if ($q && mysqli_num_rows($q) > 0) {
        cdm_ensure_dispense_tables($con);
        cdm_ensure_lot_minmax_tables($con);
        cdm_ensure_room_tables($con);
        $ready = true;
        return true;
    }
    $sql = @file_get_contents(__DIR__ . '/sql/controlled_drug.sql');
    if ($sql === false || trim($sql) === '') {
        return false;
    }
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt !== '' && !mysqli_query($con, $stmt)) {
            return false;
        }
    }
    cdm_ensure_dispense_tables($con);
    cdm_ensure_lot_minmax_tables($con);
    cdm_ensure_room_tables($con);
    $ready = true;
    return true;
}

function cdm_fetch_items($con, $drug_type, $active_only = true) {
    $drug_type = cdm_normalize_type($drug_type);
    if ($drug_type === '') {
        return [];
    }
    $type_esc = mysqli_real_escape_string($con, $drug_type);
    $where = "drug_type = '$type_esc'";
    if ($active_only) {
        $where .= ' AND is_active = 1';
    }
    $rows = [];
    $q = mysqli_query($con, "
        SELECT *
        FROM controlled_drug_items
        WHERE $where
        ORDER BY sort_order ASC, drug_name ASC, icode ASC
    ");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $rows[] = $r;
        }
    }
    return $rows;
}

function cdm_fetch_item_by_icode($con, $drug_type, $icode) {
    $drug_type = cdm_normalize_type($drug_type);
    $icode = trim((string)$icode);
    if ($drug_type === '' || $icode === '') {
        return null;
    }
    $type_esc = mysqli_real_escape_string($con, $drug_type);
    $icode_esc = mysqli_real_escape_string($con, $icode);
    $q = mysqli_query($con, "
        SELECT *
        FROM controlled_drug_items
        WHERE drug_type = '$type_esc' AND icode = '$icode_esc'
        LIMIT 1
    ");
    if ($q && ($row = mysqli_fetch_assoc($q))) {
        return $row;
    }
    return null;
}

function cdm_fetch_active_icodes($con, $drug_type) {
    $items = cdm_fetch_items($con, $drug_type, true);
    $icodes = [];
    foreach ($items as $item) {
        $icode = trim((string)($item['icode'] ?? ''));
        if ($icode !== '') {
            $icodes[] = $icode;
        }
    }
    return $icodes;
}

function cdm_format_qty($qty) {
    $qty = (float)$qty;
    if (abs($qty - round($qty)) < 0.0001) {
        return number_format($qty, 0, '.', ',');
    }
    return number_format($qty, 2, '.', ',');
}

function cdm_parse_qty($value) {
    $value = str_replace(',', '', trim((string)$value));
    if ($value === '' || !is_numeric($value)) {
        return null;
    }
    return (float)$value;
}

function cdm_parse_date($value) {
    $value = trim((string)$value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }
    return $value;
}

function cdm_hosxp_fetch_usage_summary($hos, array $icodes, $start_date, $end_date) {
    if (!$hos || empty($icodes)) {
        return ['rows' => [], 'error' => empty($icodes) ? 'ไม่มีรายการยาที่ตั้งค่าไว้' : 'ไม่สามารถเชื่อมต่อ HOSxP ได้'];
    }
    $start_date = cdm_parse_date($start_date);
    $end_date = cdm_parse_date($end_date);
    if ($start_date === null || $end_date === null) {
        return ['rows' => [], 'error' => 'รูปแบบวันที่ไม่ถูกต้อง'];
    }
    if ($start_date > $end_date) {
        return ['rows' => [], 'error' => 'วันที่เริ่มต้นต้องไม่เกินวันที่สิ้นสุด'];
    }

    $icode_sql = [];
    foreach ($icodes as $icode) {
        $icode = trim((string)$icode);
        if ($icode === '') {
            continue;
        }
        $icode_sql[] = "'" . mysqli_real_escape_string($hos, $icode) . "'";
    }
    if (empty($icode_sql)) {
        return ['rows' => [], 'error' => 'ไม่มีรหัสยา'];
    }

    $start_esc = mysqli_real_escape_string($hos, $start_date);
    $end_esc = mysqli_real_escape_string($hos, $end_date);
    $tmt_name = hosxp_sql_tmt_drug_name('d', 'tm');

    $sql = "
        SELECT o.icode,
               CAST(CONVERT(COALESCE(NULLIF(TRIM(d.name), ''), o.icode) USING utf8) AS CHAR) AS drug_name_local,
               $tmt_name AS drug_name_tmt,
               CAST(CONVERT(COALESCE(NULLIF(TRIM(d.units), ''), '') USING utf8) AS CHAR) AS unit,
               SUM(o.qty) AS total_qty,
               COUNT(*) AS tx_count
        FROM opitemrece o
        INNER JOIN drugitems d ON d.icode = o.icode
        " . hosxp_sql_tmt_join('d', 'tm') . "
        WHERE o.icode IN (" . implode(',', $icode_sql) . ")
          AND o.qty > 0
          AND o.vstdate >= '$start_esc'
          AND o.vstdate <= '$end_esc'
        GROUP BY o.icode, drug_name_local, drug_name_tmt, unit
        ORDER BY drug_name_local ASC
    ";

    $q = mysqli_query($hos, $sql);
    if (!$q) {
        return ['rows' => [], 'error' => mysqli_error($hos)];
    }

    $rows = [];
    while ($r = mysqli_fetch_assoc($q)) {
        $name = trim((string)($r['drug_name_local'] ?? ''));
        $tmt = trim((string)($r['drug_name_tmt'] ?? ''));
        if ($tmt !== '' && $tmt !== $name) {
            $name = $name !== '' ? ($name . ' — ' . $tmt) : $tmt;
        }
        $rows[] = [
            'icode' => trim((string)($r['icode'] ?? '')),
            'drug_name' => $name,
            'unit' => trim((string)($r['unit'] ?? '')),
            'total_qty' => (float)($r['total_qty'] ?? 0),
            'tx_count' => (int)($r['tx_count'] ?? 0),
        ];
    }

    return ['rows' => $rows, 'error' => null];
}

function cdm_record_stock_move($con, array $params) {
    $drug_type = cdm_normalize_type($params['drug_type'] ?? '');
    $icode = trim((string)($params['icode'] ?? ''));
    $move_type = trim((string)($params['move_type'] ?? ''));
    $qty_change = (float)($params['qty_change'] ?? 0);
    $qty_before = (float)($params['qty_before'] ?? 0);
    $qty_after = (float)($params['qty_after'] ?? 0);
    $move_date = cdm_parse_date($params['move_date'] ?? date('Y-m-d')) ?? date('Y-m-d');
    $user_id = isset($params['user_id']) ? (int)$params['user_id'] : null;

    if ($drug_type === '' || $icode === '' || !in_array($move_type, ['opening', 'hosxp_usage', 'adjust', 'manual'], true)) {
        return false;
    }

    $type_esc = mysqli_real_escape_string($con, $drug_type);
    $icode_esc = mysqli_real_escape_string($con, $icode);
    $move_type_esc = mysqli_real_escape_string($con, $move_type);
    $note_esc = mysqli_real_escape_string($con, (string)($params['note'] ?? ''));
    $ref_start = cdm_parse_date($params['ref_date_start'] ?? '');
    $ref_end = cdm_parse_date($params['ref_date_end'] ?? '');
    $ref_start_sql = $ref_start ? "'" . mysqli_real_escape_string($con, $ref_start) . "'" : 'NULL';
    $ref_end_sql = $ref_end ? "'" . mysqli_real_escape_string($con, $ref_end) . "'" : 'NULL';
    $hosxp_rows = isset($params['hosxp_rows']) ? (int)$params['hosxp_rows'] : 'NULL';
    $user_sql = $user_id ? (string)$user_id : 'NULL';
    $room_id = isset($params['room_id']) ? (int)$params['room_id'] : 0;
    $room_sql = $room_id > 0 ? (string)$room_id : 'NULL';

    $sql = "
        INSERT INTO controlled_drug_stock_moves
            (drug_type, icode, room_id, move_date, move_type, qty_change, qty_before, qty_after,
             ref_date_start, ref_date_end, hosxp_rows, note, user_id)
        VALUES
            ('$type_esc', '$icode_esc', $room_sql, '$move_date', '$move_type_esc',
             $qty_change, $qty_before, $qty_after,
             $ref_start_sql, $ref_end_sql, $hosxp_rows, '$note_esc', $user_sql)
    ";
    return (bool)mysqli_query($con, $sql);
}

function cdm_update_current_qty($con, $drug_type, $icode, $new_qty) {
    $drug_type = cdm_normalize_type($drug_type);
    $icode = trim((string)$icode);
    if ($drug_type === '' || $icode === '') {
        return false;
    }
    $type_esc = mysqli_real_escape_string($con, $drug_type);
    $icode_esc = mysqli_real_escape_string($con, $icode);
    $new_qty = (float)$new_qty;
    return (bool)mysqli_query($con, "
        UPDATE controlled_drug_items
        SET current_qty = $new_qty
        WHERE drug_type = '$type_esc' AND icode = '$icode_esc'
        LIMIT 1
    ");
}

function cdm_save_item($con, array $data) {
    $drug_type = cdm_normalize_type($data['drug_type'] ?? '');
    $icode = trim((string)($data['icode'] ?? ''));
    if ($drug_type === '' || $icode === '') {
        return ['status' => 'error', 'message' => 'กรุณาระบุประเภทยาและรหัส icode'];
    }

    $drug_name = trim((string)($data['drug_name'] ?? ''));
    $unit = trim((string)($data['unit'] ?? ''));
    $opening_qty = cdm_parse_qty($data['opening_qty'] ?? 0);
    if ($opening_qty === null) {
        $opening_qty = 0;
    }
    $sort_order = (int)($data['sort_order'] ?? 0);
    $is_active = !empty($data['is_active']) ? 1 : 0;
    $stock_status = trim((string)($data['stock_status'] ?? 'none'));
    if (!in_array($stock_status, cdm_stock_statuses(), true)) {
        $stock_status = 'none';
    }
    $stock_status_note = trim((string)($data['stock_status_note'] ?? ''));

    $existing = cdm_fetch_item_by_icode($con, $drug_type, $icode);
    $type_esc = mysqli_real_escape_string($con, $drug_type);
    $icode_esc = mysqli_real_escape_string($con, $icode);
    $name_esc = mysqli_real_escape_string($con, $drug_name);
    $unit_esc = mysqli_real_escape_string($con, $unit);
    $status_esc = mysqli_real_escape_string($con, $stock_status);
    $note_esc = mysqli_real_escape_string($con, $stock_status_note);

    if ($existing) {
        $id = (int)$existing['id'];
        $ok = mysqli_query($con, "
            UPDATE controlled_drug_items
            SET drug_name = '$name_esc',
                unit = '$unit_esc',
                sort_order = $sort_order,
                is_active = $is_active,
                stock_status = '$status_esc',
                stock_status_note = '$note_esc',
                updated_at = NOW()
            WHERE id = $id
            LIMIT 1
        ");
        if (!$ok) {
            return ['status' => 'error', 'message' => mysqli_error($con)];
        }
        return ['status' => 'success', 'message' => 'บันทึกรายการยาแล้ว', 'id' => $id];
    }

    $ok = mysqli_query($con, "
        INSERT INTO controlled_drug_items
            (drug_type, icode, drug_name, unit, opening_qty, current_qty,
             stock_status, stock_status_note, is_active, sort_order)
        VALUES
            ('$type_esc', '$icode_esc', '$name_esc', '$unit_esc', $opening_qty, $opening_qty,
             '$status_esc', '$note_esc', $is_active, $sort_order)
    ");
    if (!$ok) {
        return ['status' => 'error', 'message' => mysqli_error($con)];
    }
    $id = (int)mysqli_insert_id($con);
    if ($opening_qty != 0) {
        cdm_ensure_room_tables($con);
        $room_id = (int)($data['room_id'] ?? 0);
        if ($room_id <= 0) {
            $room_id = cdm_get_default_room_id($con, $drug_type);
        }
        if ($room_id > 0) {
            cdm_set_room_stock_qty($con, $drug_type, $icode, $room_id, $opening_qty, true);
        }
        cdm_record_stock_move($con, [
            'drug_type' => $drug_type,
            'icode' => $icode,
            'room_id' => $room_id > 0 ? $room_id : null,
            'move_type' => 'opening',
            'qty_change' => $opening_qty,
            'qty_before' => 0,
            'qty_after' => $opening_qty,
            'note' => 'ยอดยกมาเริ่มต้น',
            'user_id' => $data['user_id'] ?? null,
        ]);
    }
    return ['status' => 'success', 'message' => 'เพิ่มรายการยาแล้ว', 'id' => $id];
}

function cdm_delete_item($con, $drug_type, $id) {
    $drug_type = cdm_normalize_type($drug_type);
    $id = (int)$id;
    if ($drug_type === '' || $id <= 0) {
        return ['status' => 'error', 'message' => 'ข้อมูลไม่ถูกต้อง'];
    }
    $type_esc = mysqli_real_escape_string($con, $drug_type);
    $ok = mysqli_query($con, "
        UPDATE controlled_drug_items
        SET is_active = 0
        WHERE id = $id AND drug_type = '$type_esc'
        LIMIT 1
    ");
    if (!$ok || mysqli_affected_rows($con) === 0) {
        return ['status' => 'error', 'message' => 'ไม่พบรายการยา'];
    }
    return ['status' => 'success', 'message' => 'ปิดใช้งานรายการยาแล้ว'];
}

function cdm_update_stock_status($con, $drug_type, $id, $stock_status, $stock_status_note = '') {
    $drug_type = cdm_normalize_type($drug_type);
    $id = (int)$id;
    $stock_status = trim((string)$stock_status);
    if ($drug_type === '' || $id <= 0 || !in_array($stock_status, cdm_stock_statuses(), true)) {
        return ['status' => 'error', 'message' => 'ข้อมูลไม่ถูกต้อง'];
    }
    $type_esc = mysqli_real_escape_string($con, $drug_type);
    $status_esc = mysqli_real_escape_string($con, $stock_status);
    $note_esc = mysqli_real_escape_string($con, $stock_status_note);
    $ok = mysqli_query($con, "
        UPDATE controlled_drug_items
        SET stock_status = '$status_esc',
            stock_status_note = '$note_esc'
        WHERE id = $id AND drug_type = '$type_esc'
        LIMIT 1
    ");
    if (!$ok || mysqli_affected_rows($con) === 0) {
        return ['status' => 'error', 'message' => 'ไม่พบรายการยา'];
    }
    return ['status' => 'success', 'message' => 'อัปเดตสถานะสต๊อกแล้ว'];
}

function cdm_adjust_stock($con, $drug_type, $id, $new_qty, $note, $user_id = null, $room_id = null) {
    $drug_type = cdm_normalize_type($drug_type);
    $id = (int)$id;
    $new_qty = cdm_parse_qty($new_qty);
    if ($drug_type === '' || $id <= 0 || $new_qty === null) {
        return ['status' => 'error', 'message' => 'ข้อมูลไม่ถูกต้อง'];
    }
    cdm_ensure_room_tables($con);
    $room_id = (int)($room_id ?? 0);
    if ($room_id <= 0) {
        $room_id = cdm_get_default_room_id($con, $drug_type);
    }
    if ($room_id <= 0) {
        return ['status' => 'error', 'message' => 'ไม่พบห้องสต๊อก'];
    }
    $type_esc = mysqli_real_escape_string($con, $drug_type);
    $q = mysqli_query($con, "SELECT * FROM controlled_drug_items WHERE id = $id AND drug_type = '$type_esc' LIMIT 1");
    if (!$q || !($item = mysqli_fetch_assoc($q))) {
        return ['status' => 'error', 'message' => 'ไม่พบรายการยา'];
    }
    $icode = trim((string)($item['icode'] ?? ''));
    if (cdm_room_has_lots($con, $drug_type, $icode, $room_id)) {
        return ['status' => 'error', 'message' => 'ห้องนี้มี Lot แล้ว — กรุณาปรับยอดผ่าน Lot'];
    }
    $before = cdm_get_room_qty($con, $drug_type, $icode, $room_id);
    $change = $new_qty - $before;
    if (abs($change) < 0.0001) {
        return ['status' => 'success', 'message' => 'ยอดคงคลังไม่เปลี่ยนแปลง'];
    }
    $room = cdm_fetch_room_by_id($con, $drug_type, $room_id);
    $room_label = $room ? ($room['room_name'] ?? '') : '';
    mysqli_begin_transaction($con);
    if (!cdm_set_room_stock_qty($con, $drug_type, $icode, $room_id, $new_qty, true)) {
        mysqli_rollback($con);
        return ['status' => 'error', 'message' => mysqli_error($con)];
    }
    $after_total = (float)(cdm_fetch_item_by_icode($con, $drug_type, $icode)['current_qty'] ?? $new_qty);
    if (!cdm_record_stock_move($con, [
        'drug_type' => $drug_type,
        'icode' => $icode,
        'room_id' => $room_id,
        'move_type' => 'adjust',
        'qty_change' => $change,
        'qty_before' => $before,
        'qty_after' => $new_qty,
        'note' => ($note !== '' ? $note : 'ปรับยอดคงคลัง') . ($room_label !== '' ? ' [' . $room_label . ']' : ''),
        'user_id' => $user_id,
    ])) {
        mysqli_rollback($con);
        return ['status' => 'error', 'message' => mysqli_error($con)];
    }
    mysqli_commit($con);
    return ['status' => 'success', 'message' => 'ปรับยอดคงคลังแล้ว', 'qty_after_total' => $after_total];
}

function cdm_apply_hosxp_usage_cut($con, $drug_type, $start_date, $end_date, array $usage_rows, $user_id = null, $room_id = null) {
    $drug_type = cdm_normalize_type($drug_type);
    $start_date = cdm_parse_date($start_date);
    $end_date = cdm_parse_date($end_date);
    if ($drug_type === '' || $start_date === null || $end_date === null) {
        return ['status' => 'error', 'message' => 'ข้อมูลไม่ถูกต้อง'];
    }
    if (empty($usage_rows)) {
        return ['status' => 'error', 'message' => 'ไม่มีรายการใช้ยาจาก HOSxP'];
    }
    cdm_ensure_room_tables($con);
    $room_id = (int)($room_id ?? 0);
    if ($room_id <= 0) {
        $room_id = cdm_get_default_room_id($con, $drug_type);
    }
    if ($room_id <= 0) {
        return ['status' => 'error', 'message' => 'ไม่พบห้องสต๊อกสำหรับตัดยอด'];
    }
    $room = cdm_fetch_room_by_id($con, $drug_type, $room_id);
    $room_label = $room ? ($room['room_name'] ?? '') : '';

    $applied = 0;
    $total_cut = 0.0;
    mysqli_begin_transaction($con);
    foreach ($usage_rows as $row) {
        $icode = trim((string)($row['icode'] ?? ''));
        $qty = (float)($row['total_qty'] ?? 0);
        $tx_count = (int)($row['tx_count'] ?? 0);
        if ($icode === '' || $qty <= 0) {
            continue;
        }
        $item = cdm_fetch_item_by_icode($con, $drug_type, $icode);
        if (!$item || (int)($item['is_active'] ?? 0) !== 1) {
            continue;
        }
        $before = cdm_get_room_qty($con, $drug_type, $icode, $room_id);
        if (cdm_room_has_lots($con, $drug_type, $icode, $room_id)) {
            cdm_cut_lots_fefo($con, $drug_type, $icode, $qty, $room_id);
        } else {
            $after_room = $before - $qty;
            if (!cdm_set_room_stock_qty($con, $drug_type, $icode, $room_id, $after_room, true)) {
                mysqli_rollback($con);
                return ['status' => 'error', 'message' => mysqli_error($con)];
            }
        }
        $after_room = cdm_get_room_qty($con, $drug_type, $icode, $room_id);
        if (!cdm_record_stock_move($con, [
            'drug_type' => $drug_type,
            'icode' => $icode,
            'room_id' => $room_id,
            'move_type' => 'hosxp_usage',
            'qty_change' => -$qty,
            'qty_before' => $before,
            'qty_after' => $after_room,
            'ref_date_start' => $start_date,
            'ref_date_end' => $end_date,
            'hosxp_rows' => $tx_count,
            'note' => 'ตัดสต๊อกจากรายงานการใช้ HOSxP' . ($room_label !== '' ? ' [' . $room_label . ']' : ''),
            'user_id' => $user_id,
        ])) {
            mysqli_rollback($con);
            return ['status' => 'error', 'message' => mysqli_error($con)];
        }
        $applied++;
        $total_cut += $qty;
    }

    if ($applied === 0) {
        mysqli_rollback($con);
        return ['status' => 'error', 'message' => 'ไม่มีรายการที่ตัดสต๊อกได้ (ตรวจสอบว่ามีการตั้งค่ารายการยาแล้ว)'];
    }

    $type_esc = mysqli_real_escape_string($con, $drug_type);
    $start_esc = mysqli_real_escape_string($con, $start_date);
    $end_esc = mysqli_real_escape_string($con, $end_date);
    $user_sql = $user_id ? (int)$user_id : 'NULL';
    mysqli_query($con, "
        INSERT INTO controlled_drug_hosxp_sync_log
            (drug_type, sync_date_start, sync_date_end, icodes_synced, total_qty_cut, user_id)
        VALUES
            ('$type_esc', '$start_esc', '$end_esc', $applied, $total_cut, $user_sql)
    ");

    mysqli_commit($con);
    return [
        'status' => 'success',
        'message' => 'ตัดสต๊อกจาก HOSxP แล้ว ' . $applied . ' รายการ รวม ' . cdm_format_qty($total_cut) . ' หน่วย',
        'applied' => $applied,
        'total_qty_cut' => $total_cut,
    ];
}

function cdm_ensure_lot_minmax_tables($con) {
    static $lot_ready = false;
    if ($lot_ready) {
        return true;
    }
    $col = mysqli_query($con, "SHOW COLUMNS FROM controlled_drug_items LIKE 'min_stock'");
    if ($col && mysqli_num_rows($col) === 0) {
        @mysqli_query($con, "ALTER TABLE controlled_drug_items ADD COLUMN min_stock DECIMAL(14,4) NULL DEFAULT NULL AFTER current_qty");
    }
    $col2 = mysqli_query($con, "SHOW COLUMNS FROM controlled_drug_items LIKE 'max_stock'");
    if ($col2 && mysqli_num_rows($col2) === 0) {
        @mysqli_query($con, "ALTER TABLE controlled_drug_items ADD COLUMN max_stock DECIMAL(14,4) NULL DEFAULT NULL AFTER min_stock");
    }
    $q = mysqli_query($con, "SHOW TABLES LIKE 'controlled_drug_lots'");
    if (!$q || mysqli_num_rows($q) === 0) {
        @mysqli_query($con, "
            CREATE TABLE IF NOT EXISTS controlled_drug_lots (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              drug_type ENUM('narcotic','psychotropic') NOT NULL,
              icode VARCHAR(20) NOT NULL,
              lot_no VARCHAR(50) NOT NULL DEFAULT '',
              exp_date DATE NULL DEFAULT NULL,
              qty DECIMAL(14,4) NOT NULL DEFAULT 0,
              note VARCHAR(255) NULL DEFAULT NULL,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_cdm_lots_type_icode (drug_type, icode),
              KEY idx_cdm_lots_exp (exp_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    $lot_ready = true;
    return true;
}

function cdm_ensure_room_tables($con) {
    static $room_ready = false;
    if ($room_ready) {
        return true;
    }
    $sql = @file_get_contents(__DIR__ . '/sql/controlled_drug_rooms.sql');
    if ($sql !== false && trim($sql) !== '') {
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt !== '') {
                @mysqli_query($con, $stmt);
            }
        }
    }
    $room_cols = [
        'drug_type' => "ENUM('narcotic','psychotropic') NOT NULL DEFAULT 'narcotic'",
        'room_code' => "VARCHAR(32) NOT NULL DEFAULT ''",
        'room_name' => "VARCHAR(120) NOT NULL DEFAULT ''",
        'service_mode' => "ENUM('all','ipd','opd') NOT NULL DEFAULT 'all'",
        'is_default' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'sort_order' => 'INT NOT NULL DEFAULT 0',
        'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'note' => 'VARCHAR(255) NULL',
    ];
    $tq = mysqli_query($con, "SHOW TABLES LIKE 'controlled_drug_rooms'");
    if ($tq && mysqli_num_rows($tq) > 0) {
        foreach ($room_cols as $col => $def) {
            $cq = mysqli_query($con, "SHOW COLUMNS FROM controlled_drug_rooms LIKE '$col'");
            if ($cq && mysqli_num_rows($cq) === 0) {
                @mysqli_query($con, "ALTER TABLE controlled_drug_rooms ADD COLUMN `$col` $def");
            }
        }
    }
    $col = mysqli_query($con, "SHOW COLUMNS FROM controlled_drug_lots LIKE 'room_id'");
    if ($col && mysqli_num_rows($col) === 0) {
        @mysqli_query($con, "ALTER TABLE controlled_drug_lots ADD COLUMN room_id INT UNSIGNED NULL DEFAULT NULL AFTER icode");
        @mysqli_query($con, "ALTER TABLE controlled_drug_lots ADD KEY idx_cdm_lots_room (room_id)");
    }
    $col2 = mysqli_query($con, "SHOW COLUMNS FROM controlled_drug_stock_moves LIKE 'room_id'");
    if ($col2 && mysqli_num_rows($col2) === 0) {
        @mysqli_query($con, "ALTER TABLE controlled_drug_stock_moves ADD COLUMN room_id INT UNSIGNED NULL DEFAULT NULL AFTER icode");
    }
    cdm_ensure_dispense_tables($con);
    $col3 = mysqli_query($con, "SHOW COLUMNS FROM controlled_drug_dispense LIKE 'stock_room_id'");
    if ($col3 && mysqli_num_rows($col3) === 0) {
        @mysqli_query($con, "ALTER TABLE controlled_drug_dispense ADD COLUMN stock_room_id INT UNSIGNED NULL DEFAULT NULL AFTER icode");
    }
    // ป้องกัน recursion: seed/migrate เรียก get_default_room_id / fetch_rooms ที่เรียก ensure ซ้ำ
    $room_ready = true;
    foreach (['narcotic', 'psychotropic'] as $dt) {
        cdm_seed_default_rooms($con, $dt);
        cdm_migrate_stock_to_default_room($con, $dt);
    }
    return true;
}

function cdm_seed_default_rooms($con, $drug_type) {
    $drug_type = cdm_normalize_type($drug_type);
    if ($drug_type === '') {
        return;
    }
    $type_esc = mysqli_real_escape_string($con, $drug_type);
    $defaults = [
        ['main', 'คลังกลาง', 'all', 1, 0],
    ];
    foreach ($defaults as [$code, $name, $mode, $is_def, $sort]) {
        $code_esc = mysqli_real_escape_string($con, $code);
        $exists = mysqli_query($con, "
            SELECT id FROM controlled_drug_rooms
            WHERE drug_type = '$type_esc' AND room_code = '$code_esc'
            LIMIT 1
        ");
        if ($exists && mysqli_num_rows($exists) > 0) {
            continue;
        }
        $name_esc = mysqli_real_escape_string($con, $name);
        $mode_esc = mysqli_real_escape_string($con, $mode);
        mysqli_query($con, "
            INSERT INTO controlled_drug_rooms
                (drug_type, room_code, room_name, service_mode, is_default, sort_order, is_active)
            VALUES
                ('$type_esc', '$code_esc', '$name_esc', '$mode_esc', $is_def, $sort, 1)
        ");
    }
}

function cdm_migrate_stock_to_default_room($con, $drug_type) {
    static $migrated = [];
    $drug_type = cdm_normalize_type($drug_type);
    if ($drug_type === '' || !empty($migrated[$drug_type])) {
        return;
    }
    $default_id = cdm_get_default_room_id($con, $drug_type);
    if ($default_id <= 0) {
        return;
    }
    $type_esc = mysqli_real_escape_string($con, $drug_type);
    @mysqli_query($con, "
        UPDATE controlled_drug_lots
        SET room_id = $default_id
        WHERE drug_type = '$type_esc' AND (room_id IS NULL OR room_id = 0)
    ");
    $q = mysqli_query($con, "SELECT icode, current_qty FROM controlled_drug_items WHERE drug_type = '$type_esc' AND is_active = 1");
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $icode = trim((string)($row['icode'] ?? ''));
            if ($icode === '') {
                continue;
            }
            if (cdm_room_has_lots($con, $drug_type, $icode, $default_id)) {
                continue;
            }
            $qty = (float)($row['current_qty'] ?? 0);
            $existing = cdm_fetch_room_stock_row($con, $drug_type, $icode, $default_id);
            if ($existing) {
                continue;
            }
            if ($qty != 0) {
                cdm_set_room_stock_qty($con, $drug_type, $icode, $default_id, $qty, false);
            }
        }
    }
    $migrated[$drug_type] = true;
}

function cdm_fetch_rooms($con, $drug_type, $active_only = true) {
    cdm_ensure_room_tables($con);
    $drug_type = cdm_normalize_type($drug_type);
    if ($drug_type === '') {
        return [];
    }
    cdm_seed_default_rooms($con, $drug_type);
    $type_esc = mysqli_real_escape_string($con, $drug_type);
    $where = "drug_type = '$type_esc'";
    if ($active_only) {
        $where .= ' AND is_active = 1';
    }
    $rows = [];
    $q = mysqli_query($con, "
        SELECT * FROM controlled_drug_rooms
        WHERE $where
        ORDER BY sort_order ASC, room_name ASC, id ASC
    ");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $r['service_mode_label'] = cdm_service_mode_label($r['service_mode'] ?? '');
            $r['can_delete'] = cdm_room_can_delete($con, $drug_type, (int)($r['id'] ?? 0)) ? 1 : 0;
            $rows[] = $r;
        }
    }
    return $rows;
}

function cdm_fetch_room_by_id($con, $drug_type, $room_id) {
    $room_id = (int)$room_id;
    $drug_type = cdm_normalize_type($drug_type);
    if ($drug_type === '' || $room_id <= 0) {
        return null;
    }
    $type_esc = mysqli_real_escape_string($con, $drug_type);
    $q = mysqli_query($con, "SELECT * FROM controlled_drug_rooms WHERE id = $room_id AND drug_type = '$type_esc' LIMIT 1");
    if ($q && ($r = mysqli_fetch_assoc($q))) {
        $r['service_mode_label'] = cdm_service_mode_label($r['service_mode'] ?? '');
        return $r;
    }
    return null;
}

function cdm_get_default_room_id($con, $drug_type) {
    cdm_ensure_room_tables($con);
    $drug_type = cdm_normalize_type($drug_type);
    if ($drug_type === '') {
        return 0;
    }
    $type_esc = mysqli_real_escape_string($con, $drug_type);
    $q = mysqli_query($con, "
        SELECT id FROM controlled_drug_rooms
        WHERE drug_type = '$type_esc' AND is_active = 1
        ORDER BY is_default DESC, sort_order ASC, id ASC
        LIMIT 1
    ");
    if ($q && ($r = mysqli_fetch_assoc($q))) {
        return (int)($r['id'] ?? 0);
    }
    return 0;
}

function cdm_resolve_room_for_service($con, $drug_type, $service_mode, $preferred_room_id = 0) {
    cdm_ensure_room_tables($con);
    $preferred_room_id = (int)$preferred_room_id;
    if ($preferred_room_id > 0 && cdm_fetch_room_by_id($con, $drug_type, $preferred_room_id)) {
        return $preferred_room_id;
    }
    $service_mode = cdm_normalize_service_mode($service_mode);
    foreach (cdm_fetch_rooms($con, $drug_type, true) as $room) {
        if ($service_mode !== '' && $service_mode !== 'all' && ($room['service_mode'] ?? '') === $service_mode) {
            return (int)$room['id'];
        }
    }
    return cdm_get_default_room_id($con, $drug_type);
}

function cdm_normalize_room_code($code) {
    $code = strtolower(trim((string)$code));
    $code = preg_replace('/[^a-z0-9_-]+/', '', $code);
    return substr($code, 0, 32);
}

function cdm_save_room($con, array $data) {
    cdm_ensure_room_tables($con);
    $drug_type = cdm_normalize_type($data['drug_type'] ?? '');
    $id = (int)($data['id'] ?? 0);
    $room_code = cdm_normalize_room_code($data['room_code'] ?? '');
    $room_name = trim((string)($data['room_name'] ?? ''));
    $service_mode = cdm_normalize_service_mode($data['service_mode'] ?? 'all');
    if ($service_mode === '') {
        $service_mode = 'all';
    }
    $sort_order = (int)($data['sort_order'] ?? 0);
    $is_active = !isset($data['is_active']) || !empty($data['is_active']) ? 1 : 0;
    $note = trim((string)($data['note'] ?? ''));
    if ($drug_type === '' || $room_code === '' || $room_name === '') {
        return ['status' => 'error', 'message' => 'กรุณาระบุรหัสและชื่อห้อง'];
    }
    $type_esc = mysqli_real_escape_string($con, $drug_type);
    $code_esc = mysqli_real_escape_string($con, $room_code);
    $name_esc = mysqli_real_escape_string($con, $room_name);
    $mode_esc = mysqli_real_escape_string($con, $service_mode);
    $note_esc = mysqli_real_escape_string($con, $note);

    if ($id > 0) {
        $dup = mysqli_query($con, "
            SELECT id FROM controlled_drug_rooms
            WHERE drug_type = '$type_esc' AND room_code = '$code_esc' AND id <> $id
            LIMIT 1
        ");
        if ($dup && mysqli_num_rows($dup) > 0) {
            return ['status' => 'error', 'message' => 'รหัสห้องซ้ำ'];
        }
        $ok = mysqli_query($con, "
            UPDATE controlled_drug_rooms
            SET room_code = '$code_esc', room_name = '$name_esc', service_mode = '$mode_esc',
                sort_order = $sort_order, is_active = $is_active, note = '$note_esc'
            WHERE id = $id AND drug_type = '$type_esc'
            LIMIT 1
        ");
        if (!$ok || mysqli_affected_rows($con) === 0) {
            return ['status' => 'error', 'message' => 'ไม่พบห้องหรือบันทึกไม่สำเร็จ'];
        }
        return ['status' => 'success', 'message' => 'บันทึกห้องแล้ว', 'id' => $id];
    }

    $dup = mysqli_query($con, "
        SELECT id FROM controlled_drug_rooms
        WHERE drug_type = '$type_esc' AND room_code = '$code_esc'
        LIMIT 1
    ");
    if ($dup && mysqli_num_rows($dup) > 0) {
        return ['status' => 'error', 'message' => 'รหัสห้องซ้ำ'];
    }
    $ok = mysqli_query($con, "
        INSERT INTO controlled_drug_rooms
            (drug_type, room_code, room_name, service_mode, is_default, sort_order, is_active, note)
        VALUES
            ('$type_esc', '$code_esc', '$name_esc', '$mode_esc', 0, $sort_order, $is_active, '$note_esc')
    ");
    if (!$ok) {
        return ['status' => 'error', 'message' => mysqli_error($con)];
    }
    $new_id = (int)mysqli_insert_id($con);
    $mirrored = cdm_mirror_room_to_other_type($con, $drug_type, [
        'room_code' => $room_code,
        'room_name' => $room_name,
        'service_mode' => $service_mode,
        'sort_order' => $sort_order,
        'is_active' => $is_active,
        'note' => $note,
    ]);
    $other_label = cdm_type_label($drug_type === 'narcotic' ? 'psychotropic' : 'narcotic');
    $message = $mirrored
        ? 'เพิ่มห้องแล้ว (สร้างให้' . $other_label . 'ด้วยอัตโนมัติ)'
        : 'เพิ่มห้องแล้ว';
    return ['status' => 'success', 'message' => $message, 'id' => $new_id, 'mirrored' => $mirrored ? 1 : 0];
}

function cdm_room_is_in_use($con, $drug_type, $room_id) {
    $room_id = (int)$room_id;
    $drug_type = cdm_normalize_type($drug_type);
    if ($drug_type === '' || $room_id <= 0) {
        return true;
    }
    $type_esc = mysqli_real_escape_string($con, $drug_type);

    $checks = [
        "SELECT id FROM controlled_drug_room_stock
         WHERE room_id = $room_id AND drug_type = '$type_esc' AND ABS(qty) > 0.0001 LIMIT 1",
        "SELECT id FROM controlled_drug_lots
         WHERE room_id = $room_id AND drug_type = '$type_esc' LIMIT 1",
        "SELECT id FROM controlled_drug_stock_moves
         WHERE room_id = $room_id AND drug_type = '$type_esc' LIMIT 1",
        "SELECT id FROM controlled_drug_dispense
         WHERE stock_room_id = $room_id AND drug_type = '$type_esc' LIMIT 1",
    ];
    foreach ($checks as $sql) {
        $q = mysqli_query($con, $sql);
        if ($q && mysqli_num_rows($q) > 0) {
            return true;
        }
    }
    return false;
}

function cdm_room_can_delete($con, $drug_type, $room_id) {
    $room = cdm_fetch_room_by_id($con, $drug_type, $room_id);
    if (!$room) {
        return false;
    }
    if ((int)($room['is_default'] ?? 0) === 1) {
        return false;
    }
    return !cdm_room_is_in_use($con, $drug_type, $room_id);
}

function cdm_delete_room($con, $drug_type, $room_id) {
    cdm_ensure_room_tables($con);
    $drug_type = cdm_normalize_type($drug_type);
    $room_id = (int)$room_id;
    if ($drug_type === '' || $room_id <= 0) {
        return ['status' => 'error', 'message' => 'ไม่พบห้อง'];
    }
    $room = cdm_fetch_room_by_id($con, $drug_type, $room_id);
    if (!$room) {
        return ['status' => 'error', 'message' => 'ไม่พบห้อง'];
    }
    if ((int)($room['is_default'] ?? 0) === 1) {
        return ['status' => 'error', 'message' => 'ไม่สามารถลบห้องคลังหลักได้'];
    }
    if (cdm_room_is_in_use($con, $drug_type, $room_id)) {
        return ['status' => 'error', 'message' => 'ห้องนี้มีการใช้งานแล้ว (มียอดสต๊อก Lot การเคลื่อนไหว หรือบันทึกจ่ายยา) จึงลบไม่ได้'];
    }
    $type_esc = mysqli_real_escape_string($con, $drug_type);
    @mysqli_query($con, "
        DELETE FROM controlled_drug_room_stock
        WHERE room_id = $room_id AND drug_type = '$type_esc'
    ");
    $ok = mysqli_query($con, "
        DELETE FROM controlled_drug_rooms
        WHERE id = $room_id AND drug_type = '$type_esc'
        LIMIT 1
    ");
    if (!$ok || mysqli_affected_rows($con) === 0) {
        return ['status' => 'error', 'message' => 'ลบห้องไม่สำเร็จ'];
    }
    return ['status' => 'success', 'message' => 'ลบห้องแล้ว'];
}

function cdm_copy_rooms_from_type($con, $target_type, $source_type) {
    cdm_ensure_room_tables($con);
    $target_type = cdm_normalize_type($target_type);
    $source_type = cdm_normalize_type($source_type);
    if ($target_type === '' || $source_type === '' || $target_type === $source_type) {
        return ['status' => 'error', 'message' => 'ประเภทยาต้นทางและปลายทางไม่ถูกต้อง'];
    }
    $target_esc = mysqli_real_escape_string($con, $target_type);
    $source_esc = mysqli_real_escape_string($con, $source_type);
    $copied = 0;
    $q = mysqli_query($con, "
        SELECT * FROM controlled_drug_rooms
        WHERE drug_type = '$source_esc'
        ORDER BY sort_order ASC, id ASC
    ");
    if (!$q) {
        return ['status' => 'error', 'message' => 'โหลดห้องต้นทางไม่สำเร็จ'];
    }
    while ($room = mysqli_fetch_assoc($q)) {
        $code = cdm_normalize_room_code($room['room_code'] ?? '');
        if ($code === '') {
            continue;
        }
        $code_esc = mysqli_real_escape_string($con, $code);
        $exists = mysqli_query($con, "
            SELECT id FROM controlled_drug_rooms
            WHERE drug_type = '$target_esc' AND room_code = '$code_esc'
            LIMIT 1
        ");
        if ($exists && mysqli_num_rows($exists) > 0) {
            continue;
        }
        $name_esc = mysqli_real_escape_string($con, trim((string)($room['room_name'] ?? '')));
        $mode_esc = mysqli_real_escape_string($con, cdm_normalize_service_mode($room['service_mode'] ?? 'all') ?: 'all');
        $sort_order = (int)($room['sort_order'] ?? 0);
        $is_default = ($code === 'main') ? 1 : 0;
        $is_active = (int)($room['is_active'] ?? 1) ? 1 : 0;
        $note_esc = mysqli_real_escape_string($con, trim((string)($room['note'] ?? '')));
        if ($name_esc === '') {
            continue;
        }
        if (!mysqli_query($con, "
            INSERT INTO controlled_drug_rooms
                (drug_type, room_code, room_name, service_mode, is_default, sort_order, is_active, note)
            VALUES
                ('$target_esc', '$code_esc', '$name_esc', '$mode_esc', $is_default, $sort_order, $is_active, '$note_esc')
        ")) {
            return ['status' => 'error', 'message' => 'คัดลอกห้องไม่สำเร็จ: ' . mysqli_error($con)];
        }
        $copied++;
    }
    if ($copied === 0) {
        return ['status' => 'success', 'message' => 'ไม่มีห้องใหม่ให้คัดลอก (มีครบแล้ว)', 'copied' => 0];
    }
    return ['status' => 'success', 'message' => 'คัดลอกห้องแล้ว ' . $copied . ' รายการ', 'copied' => $copied];
}

function cdm_mirror_room_to_other_type($con, $drug_type, array $room) {
    $drug_type = cdm_normalize_type($drug_type);
    $other_type = $drug_type === 'narcotic' ? 'psychotropic' : 'narcotic';
    $code = cdm_normalize_room_code($room['room_code'] ?? '');
    if ($drug_type === '' || $code === '' || $code === 'main') {
        return false;
    }
    $room_name = trim((string)($room['room_name'] ?? ''));
    if ($room_name === '') {
        return false;
    }
    $other_esc = mysqli_real_escape_string($con, $other_type);
    $code_esc = mysqli_real_escape_string($con, $code);
    $exists = mysqli_query($con, "
        SELECT id FROM controlled_drug_rooms
        WHERE drug_type = '$other_esc' AND room_code = '$code_esc'
        LIMIT 1
    ");
    if ($exists && mysqli_num_rows($exists) > 0) {
        return false;
    }
    $name_esc = mysqli_real_escape_string($con, $room_name);
    $mode_esc = mysqli_real_escape_string($con, cdm_normalize_service_mode($room['service_mode'] ?? 'all') ?: 'all');
    $sort_order = (int)($room['sort_order'] ?? 0);
    $is_active = !isset($room['is_active']) || !empty($room['is_active']) ? 1 : 0;
    $note_esc = mysqli_real_escape_string($con, trim((string)($room['note'] ?? '')));
    return (bool)mysqli_query($con, "
        INSERT INTO controlled_drug_rooms
            (drug_type, room_code, room_name, service_mode, is_default, sort_order, is_active, note)
        VALUES
            ('$other_esc', '$code_esc', '$name_esc', '$mode_esc', 0, $sort_order, $is_active, '$note_esc')
    ");
}

function cdm_fetch_room_stock_row($con, $drug_type, $icode, $room_id) {
    $drug_type = cdm_normalize_type($drug_type);
    $icode = trim((string)$icode);
    $room_id = (int)$room_id;
    if ($drug_type === '' || $icode === '' || $room_id <= 0) {
        return null;
    }
    $type_esc = mysqli_real_escape_string($con, $drug_type);
    $icode_esc = mysqli_real_escape_string($con, $icode);
    $q = mysqli_query($con, "
        SELECT * FROM controlled_drug_room_stock
        WHERE room_id = $room_id AND drug_type = '$type_esc' AND icode = '$icode_esc'
        LIMIT 1
    ");
    if ($q && ($r = mysqli_fetch_assoc($q))) {
        return $r;
    }
    return null;
}

function cdm_room_has_lots($con, $drug_type, $icode, $room_id) {
    return count(cdm_fetch_lots($con, $drug_type, $icode, true, (int)$room_id)) > 0;
}

function cdm_get_room_qty($con, $drug_type, $icode, $room_id) {
    $room_id = (int)$room_id;
    if ($room_id <= 0) {
        return 0.0;
    }
    $lots = cdm_fetch_lots($con, $drug_type, $icode, true, $room_id);
    if (!empty($lots)) {
        $sum = 0.0;
        foreach ($lots as $lot) {
            $sum += (float)($lot['qty'] ?? 0);
        }
        return $sum;
    }
    $row = cdm_fetch_room_stock_row($con, $drug_type, $icode, $room_id);
    return $row ? (float)($row['qty'] ?? 0) : 0.0;
}

function cdm_set_room_stock_qty($con, $drug_type, $icode, $room_id, $qty, $sync_total = true) {
    $drug_type = cdm_normalize_type($drug_type);
    $icode = trim((string)$icode);
    $room_id = (int)$room_id;
    $qty = (float)$qty;
    if ($drug_type === '' || $icode === '' || $room_id <= 0) {
        return false;
    }
    if (cdm_room_has_lots($con, $drug_type, $icode, $room_id)) {
        return false;
    }
    $type_esc = mysqli_real_escape_string($con, $drug_type);
    $icode_esc = mysqli_real_escape_string($con, $icode);
    $row = cdm_fetch_room_stock_row($con, $drug_type, $icode, $room_id);
    if ($row) {
        $ok = mysqli_query($con, "
            UPDATE controlled_drug_room_stock
            SET qty = $qty
            WHERE id = " . (int)$row['id'] . " LIMIT 1
        ");
    } else {
        $ok = mysqli_query($con, "
            INSERT INTO controlled_drug_room_stock (room_id, drug_type, icode, qty)
            VALUES ($room_id, '$type_esc', '$icode_esc', $qty)
        ");
    }
    if (!$ok) {
        return false;
    }
    if ($sync_total) {
        return cdm_sync_item_qty_from_rooms($con, $drug_type, $icode);
    }
    return true;
}

function cdm_sync_item_qty_from_rooms($con, $drug_type, $icode) {
    $total = 0.0;
    foreach (cdm_fetch_rooms($con, $drug_type, false) as $room) {
        if ((int)($room['is_active'] ?? 0) !== 1) {
            continue;
        }
        $total += cdm_get_room_qty($con, $drug_type, $icode, (int)$room['id']);
    }
    return cdm_update_current_qty($con, $drug_type, $icode, $total);
}

function cdm_format_exp_date($date) {
    $date = trim((string)$date);
    if ($date === '' || $date === '0000-00-00') {
        return '';
    }
    $ts = strtotime($date);
    return $ts ? date('d/m/Y', $ts) : $date;
}

function cdm_fetch_lots($con, $drug_type, $icode, $active_only = true, $room_id = null) {
    $drug_type = cdm_normalize_type($drug_type);
    $icode = trim((string)$icode);
    if ($drug_type === '' || $icode === '') {
        return [];
    }
    $type_esc = mysqli_real_escape_string($con, $drug_type);
    $icode_esc = mysqli_real_escape_string($con, $icode);
    $where = "drug_type = '$type_esc' AND icode = '$icode_esc'";
    if ($active_only) {
        $where .= ' AND is_active = 1';
    }
    if ($room_id !== null && (int)$room_id > 0) {
        $where .= ' AND room_id = ' . (int)$room_id;
    }
    $rows = [];
    $q = mysqli_query($con, "
        SELECT *
        FROM controlled_drug_lots
        WHERE $where
        ORDER BY (exp_date IS NULL) ASC, exp_date ASC, id ASC
    ");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $r['exp_display'] = cdm_format_exp_date($r['exp_date'] ?? '');
            $rows[] = $r;
        }
    }
    return $rows;
}

function cdm_fetch_lots_map($con, $drug_type, $room_id = null) {
    $drug_type = cdm_normalize_type($drug_type);
    if ($drug_type === '') {
        return [];
    }
    $type_esc = mysqli_real_escape_string($con, $drug_type);
    $where = "drug_type = '$type_esc' AND is_active = 1";
    if ($room_id !== null && (int)$room_id > 0) {
        $where .= ' AND room_id = ' . (int)$room_id;
    }
    $map = [];
    $q = mysqli_query($con, "
        SELECT *
        FROM controlled_drug_lots
        WHERE $where
        ORDER BY icode ASC, (exp_date IS NULL) ASC, exp_date ASC, id ASC
    ");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $icode = trim((string)($r['icode'] ?? ''));
            if ($icode === '') {
                continue;
            }
            $r['exp_display'] = cdm_format_exp_date($r['exp_date'] ?? '');
            if (!isset($map[$icode])) {
                $map[$icode] = [];
            }
            $map[$icode][] = $r;
        }
    }
    return $map;
}

function cdm_item_has_lots($con, $drug_type, $icode) {
    return count(cdm_fetch_lots($con, $drug_type, $icode, true)) > 0;
}

function cdm_sync_item_qty_from_lots($con, $drug_type, $icode) {
    cdm_ensure_room_tables($con);
    return cdm_sync_item_qty_from_rooms($con, $drug_type, $icode);
}

function cdm_cut_lots_fefo($con, $drug_type, $icode, $qty_to_cut, $room_id = null) {
    $drug_type = cdm_normalize_type($drug_type);
    $icode = trim((string)$icode);
    $qty_to_cut = max(0, (float)$qty_to_cut);
    if ($drug_type === '' || $icode === '' || $qty_to_cut <= 0) {
        return true;
    }
    $remaining = $qty_to_cut;
    foreach (cdm_fetch_lots($con, $drug_type, $icode, true, $room_id) as $lot) {
        if ($remaining <= 0) {
            break;
        }
        $lot_qty = (float)($lot['qty'] ?? 0);
        if ($lot_qty <= 0) {
            continue;
        }
        $cut = min($lot_qty, $remaining);
        $new_qty = $lot_qty - $cut;
        $remaining -= $cut;
        $id = (int)($lot['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        if ($new_qty <= 0.0001) {
            mysqli_query($con, "UPDATE controlled_drug_lots SET qty = 0, is_active = 0 WHERE id = $id LIMIT 1");
        } else {
            mysqli_query($con, "UPDATE controlled_drug_lots SET qty = $new_qty WHERE id = $id LIMIT 1");
        }
    }
    return cdm_sync_item_qty_from_lots($con, $drug_type, $icode);
}

function cdm_fetch_item_usage_total($con, $drug_type, $icode, $months = 2) {
    $drug_type = cdm_normalize_type($drug_type);
    $icode = trim((string)$icode);
    $months = max(1, min(24, (int)$months));
    if ($drug_type === '' || $icode === '') {
        return 0.0;
    }
    $type_esc = mysqli_real_escape_string($con, $drug_type);
    $icode_esc = mysqli_real_escape_string($con, $icode);
    $since = date('Y-m-d', strtotime('-' . $months . ' months'));
    $since_esc = mysqli_real_escape_string($con, $since);
    $total = 0.0;

    $q = mysqli_query($con, "
        SELECT COALESCE(SUM(ABS(qty_change)), 0) AS used_qty
        FROM controlled_drug_stock_moves
        WHERE drug_type = '$type_esc' AND icode = '$icode_esc'
          AND move_type IN ('hosxp_usage', 'manual', 'adjust')
          AND qty_change < 0
          AND move_date >= '$since_esc'
    ");
    if ($q && ($r = mysqli_fetch_assoc($q))) {
        $total += (float)($r['used_qty'] ?? 0);
    }

    cdm_ensure_dispense_tables($con);
    $q2 = mysqli_query($con, "
        SELECT COALESCE(SUM(dispense_qty), 0) AS used_qty
        FROM controlled_drug_dispense
        WHERE drug_type = '$type_esc' AND icode = '$icode_esc'
          AND status = 'completed'
          AND service_date >= '$since_esc'
    ");
    if ($q2 && ($r2 = mysqli_fetch_assoc($q2))) {
        $total += (float)($r2['used_qty'] ?? 0);
    }

    return $total;
}

function cdm_calc_minmax_suggestions($total_usage, $months, $min_reserve_months, $max_reserve_months) {
    $months = max(1, (int)$months);
    $min_reserve_months = max(1, (int)$min_reserve_months);
    $max_reserve_months = max($min_reserve_months, (int)$max_reserve_months);
    $monthly = $total_usage / $months;
    $suggest_min = $monthly > 0 ? (int)ceil($monthly * $min_reserve_months) : 0;
    $suggest_max = $monthly > 0 ? (int)ceil($monthly * $max_reserve_months) : 0;
    if ($suggest_max > 0 && $suggest_max < $suggest_min) {
        $suggest_max = $suggest_min;
    }
    return [
        'monthly_avg' => round($monthly, 4),
        'suggest_min' => $suggest_min,
        'suggest_max' => $suggest_max,
        'usage_total' => (float)$total_usage,
    ];
}

function cdm_calc_exp_alert(array $lots, $current_qty, $monthly_avg) {
    $active_lots = array_values(array_filter($lots, function ($lot) {
        return (float)($lot['qty'] ?? 0) > 0;
    }));
    if (empty($active_lots)) {
        return ['level' => 'none', 'label' => 'ไม่มีข้อมูล', 'class' => 'bg-secondary'];
    }
    $has_exp = false;
    $worst = 'pass';
    $rank = ['pass' => 0, 'caution' => 1, 'alert' => 2, 'none' => -1];
    foreach ($active_lots as $lot) {
        $lot_qty = (float)($lot['qty'] ?? 0);
        $exp = trim((string)($lot['exp_date'] ?? ''));
        if ($exp === '' || $exp === '0000-00-00') {
            continue;
        }
        $has_exp = true;
        $exp_ts = strtotime($exp);
        if (!$exp_ts) {
            continue;
        }
        $days_left = ($exp_ts - time()) / 86400;
        if ($days_left < 0) {
            $level = 'alert';
        } elseif ($monthly_avg <= 0) {
            $level = $days_left < 180 ? 'caution' : 'pass';
        } else {
            $months_left = max(0, $days_left / 30.0);
            $can_use = $monthly_avg * $months_left;
            if ($lot_qty > $can_use * 1.25) {
                $level = 'alert';
            } elseif ($lot_qty > $can_use) {
                $level = 'caution';
            } else {
                $level = 'pass';
            }
        }
        if ($rank[$level] > $rank[$worst]) {
            $worst = $level;
        }
    }
    if (!$has_exp) {
        return ['level' => 'none', 'label' => 'ไม่มี EXP', 'class' => 'bg-secondary'];
    }
    $labels = [
        'pass' => ['label' => 'Pass', 'class' => 'bg-success'],
        'caution' => ['label' => 'Caution', 'class' => 'bg-warning text-dark'],
        'alert' => ['label' => 'Alert', 'class' => 'bg-danger'],
    ];
    return array_merge(['level' => $worst], $labels[$worst] ?? $labels['pass']);
}

function cdm_inventory_level_status($current_qty, $min_stock, $max_stock) {
    $qty = (float)$current_qty;
    $min = ($min_stock !== null && $min_stock !== '') ? (float)$min_stock : null;
    if ($min !== null && $qty < $min) {
        return ['code' => 'below_min', 'label' => 'สต๊อกต่ำกว่า Min!', 'class' => 'bg-danger'];
    }
    return null;
}

function cdm_save_lot($con, array $data) {
    cdm_ensure_lot_minmax_tables($con);
    $drug_type = cdm_normalize_type($data['drug_type'] ?? '');
    $icode = trim((string)($data['icode'] ?? ''));
    $lot_no = trim((string)($data['lot_no'] ?? ''));
    $exp_date = cdm_parse_date($data['exp_date'] ?? '');
    $qty = cdm_parse_qty($data['qty'] ?? null);
    $note = trim((string)($data['note'] ?? ''));
    $id = (int)($data['id'] ?? 0);
    cdm_ensure_room_tables($con);
    $room_id = (int)($data['room_id'] ?? 0);
    if ($room_id <= 0) {
        $room_id = cdm_get_default_room_id($con, $drug_type);
    }
    if ($room_id <= 0) {
        return ['status' => 'error', 'message' => 'ไม่พบห้องสต๊อก'];
    }
    if ($drug_type === '' || $icode === '' || $qty === null || $qty < 0) {
        return ['status' => 'error', 'message' => 'ข้อมูล Lot ไม่ครบ'];
    }
    $item = cdm_fetch_item_by_icode($con, $drug_type, $icode);
    if (!$item || (int)($item['is_active'] ?? 0) !== 1) {
        return ['status' => 'error', 'message' => 'ไม่พบรายการยา'];
    }

    $type_esc = mysqli_real_escape_string($con, $drug_type);
    $icode_esc = mysqli_real_escape_string($con, $icode);
    $lot_esc = mysqli_real_escape_string($con, $lot_no);
    $note_esc = mysqli_real_escape_string($con, $note);
    $exp_sql = $exp_date ? "'" . mysqli_real_escape_string($con, $exp_date) . "'" : 'NULL';

    mysqli_begin_transaction($con);
    if ($id > 0) {
        $old = null;
        $q = mysqli_query($con, "SELECT * FROM controlled_drug_lots WHERE id = $id AND drug_type = '$type_esc' AND icode = '$icode_esc' LIMIT 1");
        if ($q) {
            $old = mysqli_fetch_assoc($q);
        }
        if (!$old) {
            mysqli_rollback($con);
            return ['status' => 'error', 'message' => 'ไม่พบ Lot'];
        }
        if (!mysqli_query($con, "
            UPDATE controlled_drug_lots
            SET lot_no = '$lot_esc', exp_date = $exp_sql, qty = $qty, note = '$note_esc', is_active = 1, room_id = $room_id
            WHERE id = $id LIMIT 1
        ")) {
            mysqli_rollback($con);
            return ['status' => 'error', 'message' => mysqli_error($con)];
        }
    } else {
        if (!mysqli_query($con, "
            INSERT INTO controlled_drug_lots (drug_type, icode, room_id, lot_no, exp_date, qty, note)
            VALUES ('$type_esc', '$icode_esc', $room_id, '$lot_esc', $exp_sql, $qty, '$note_esc')
        ")) {
            mysqli_rollback($con);
            return ['status' => 'error', 'message' => mysqli_error($con)];
        }
        $id = (int)mysqli_insert_id($con);
    }
    if (!cdm_sync_item_qty_from_lots($con, $drug_type, $icode)) {
        mysqli_rollback($con);
        return ['status' => 'error', 'message' => 'อัปเดตยอดคงคลังไม่สำเร็จ'];
    }
    mysqli_commit($con);
    return ['status' => 'success', 'message' => 'บันทึก Lot แล้ว', 'id' => $id];
}

function cdm_delete_lot($con, $drug_type, $lot_id) {
    cdm_ensure_lot_minmax_tables($con);
    $drug_type = cdm_normalize_type($drug_type);
    $lot_id = (int)$lot_id;
    if ($drug_type === '' || $lot_id <= 0) {
        return ['status' => 'error', 'message' => 'ข้อมูลไม่ถูกต้อง'];
    }
    $type_esc = mysqli_real_escape_string($con, $drug_type);
    $q = mysqli_query($con, "SELECT icode FROM controlled_drug_lots WHERE id = $lot_id AND drug_type = '$type_esc' LIMIT 1");
    if (!$q || !($row = mysqli_fetch_assoc($q))) {
        return ['status' => 'error', 'message' => 'ไม่พบ Lot'];
    }
    $icode = trim((string)($row['icode'] ?? ''));
    mysqli_begin_transaction($con);
    if (!mysqli_query($con, "UPDATE controlled_drug_lots SET is_active = 0, qty = 0 WHERE id = $lot_id LIMIT 1")) {
        mysqli_rollback($con);
        return ['status' => 'error', 'message' => mysqli_error($con)];
    }
    cdm_sync_item_qty_from_lots($con, $drug_type, $icode);
    mysqli_commit($con);
    return ['status' => 'success', 'message' => 'ลบ Lot แล้ว'];
}

function cdm_update_item_minmax($con, $drug_type, $item_id, $min_stock, $max_stock) {
    cdm_ensure_lot_minmax_tables($con);
    $drug_type = cdm_normalize_type($drug_type);
    $item_id = (int)$item_id;
    if ($drug_type === '' || $item_id <= 0) {
        return ['status' => 'error', 'message' => 'ข้อมูลไม่ถูกต้อง'];
    }
    $min = ($min_stock === '' || $min_stock === null) ? 'NULL' : (string)(float)$min_stock;
    $max = ($max_stock === '' || $max_stock === null) ? 'NULL' : (string)(float)$max_stock;
    if ($min !== 'NULL' && $max !== 'NULL' && (float)$max < (float)$min) {
        return ['status' => 'error', 'message' => 'Max ต้องไม่น้อยกว่า Min'];
    }
    $type_esc = mysqli_real_escape_string($con, $drug_type);
    if (!mysqli_query($con, "
        UPDATE controlled_drug_items
        SET min_stock = $min, max_stock = $max
        WHERE id = $item_id AND drug_type = '$type_esc'
        LIMIT 1
    ")) {
        return ['status' => 'error', 'message' => mysqli_error($con)];
    }
    return ['status' => 'success', 'message' => 'บันทึก Min/Max แล้ว'];
}

function cdm_apply_all_minmax_suggestions($con, $drug_type, $usage_months, $min_reserve, $max_reserve) {
    $data = cdm_fetch_stock_summary($con, $drug_type, '', [
        'usage_months' => $usage_months,
        'min_reserve_months' => $min_reserve,
        'max_reserve_months' => $max_reserve,
    ]);
    $updated = 0;
    foreach ($data['items'] as $item) {
        $s = $item['minmax_suggest'] ?? null;
        if (!$s || ((int)($s['suggest_min'] ?? 0) <= 0 && (int)($s['suggest_max'] ?? 0) <= 0)) {
            continue;
        }
        $res = cdm_update_item_minmax(
            $con,
            $drug_type,
            (int)$item['id'],
            $s['suggest_min'],
            $s['suggest_max'] > 0 ? $s['suggest_max'] : null
        );
        if (($res['status'] ?? '') === 'success') {
            $updated++;
        }
    }
    return ['status' => 'success', 'message' => 'ใช้คำแนะนำ Min/Max แล้ว ' . $updated . ' รายการ', 'updated' => $updated];
}

function cdm_fetch_stock_summary($con, $drug_type, $status_filter = '', array $opts = []) {
    cdm_ensure_lot_minmax_tables($con);
    cdm_ensure_room_tables($con);
    $usage_months = max(1, min(24, (int)($opts['usage_months'] ?? 2)));
    $min_reserve = max(1, min(12, (int)($opts['min_reserve_months'] ?? 1)));
    $max_reserve = max($min_reserve, min(24, (int)($opts['max_reserve_months'] ?? 3)));
    $room_id = (int)($opts['room_id'] ?? 0);

    $items = cdm_fetch_items($con, $drug_type, true);
    $lots_map = cdm_fetch_lots_map($con, $drug_type, $room_id > 0 ? $room_id : null);
    $enriched = [];

    foreach ($items as $item) {
        $icode = trim((string)($item['icode'] ?? ''));
        $lots = $lots_map[$icode] ?? [];
        $total_qty = (float)($item['current_qty'] ?? 0);
        $current = $room_id > 0 ? cdm_get_room_qty($con, $drug_type, $icode, $room_id) : $total_qty;
        $usage_total = cdm_fetch_item_usage_total($con, $drug_type, $icode, $usage_months);
        $minmax = cdm_calc_minmax_suggestions($usage_total, $usage_months, $min_reserve, $max_reserve);
        $monthly_avg = (float)($minmax['monthly_avg'] ?? 0);
        $exp_alert = cdm_calc_exp_alert($lots, $current, $monthly_avg);
        $level_status = cdm_inventory_level_status($current, $item['min_stock'] ?? null, $item['max_stock'] ?? null);

        $item['lots'] = $lots;
        $item['lot_count'] = count($lots);
        $item['room_qty'] = $current;
        $item['total_qty'] = $total_qty;
        $item['current_qty'] = $current;
        $item['minmax_suggest'] = $minmax;
        $item['exp_alert'] = $exp_alert;
        $item['inventory_status'] = $level_status;
        $item['below_min'] = $level_status !== null;
        $enriched[] = $item;
    }

    if ($status_filter === 'below_min') {
        $enriched = array_values(array_filter($enriched, function ($item) {
            return !empty($item['below_min']);
        }));
    } elseif ($status_filter === 'exp_risk') {
        $enriched = array_values(array_filter($enriched, function ($item) {
            $lvl = $item['exp_alert']['level'] ?? 'none';
            return in_array($lvl, ['caution', 'alert'], true);
        }));
    } elseif ($status_filter !== '' && in_array($status_filter, cdm_stock_statuses(), true)) {
        $enriched = array_values(array_filter($enriched, function ($item) use ($status_filter) {
            return ($item['stock_status'] ?? 'none') === $status_filter;
        }));
    }

    $summary = [
        'total_items' => count($enriched),
        'total_qty' => 0,
        'pending_form' => 0,
        'pending_remains' => 0,
        'returned_incomplete' => 0,
        'below_min' => 0,
        'exp_risk' => 0,
    ];
    foreach ($enriched as $item) {
        $summary['total_qty'] += (float)($item['current_qty'] ?? 0);
        $st = $item['stock_status'] ?? 'none';
        if (isset($summary[$st])) {
            $summary[$st]++;
        }
        if (!empty($item['below_min'])) {
            $summary['below_min']++;
        }
        $lvl = $item['exp_alert']['level'] ?? 'none';
        if (in_array($lvl, ['caution', 'alert'], true)) {
            $summary['exp_risk']++;
        }
    }

    return [
        'items' => $enriched,
        'summary' => $summary,
        'usage_months' => $usage_months,
        'min_reserve_months' => $min_reserve,
        'max_reserve_months' => $max_reserve,
    ];
}

function cdm_fetch_recent_moves($con, $drug_type, $limit = 50) {
    $drug_type = cdm_normalize_type($drug_type);
    if ($drug_type === '') {
        return [];
    }
    $type_esc = mysqli_real_escape_string($con, $drug_type);
    $limit = max(1, min(200, (int)$limit));
    $rows = [];
    $q = mysqli_query($con, "
        SELECT m.*, i.drug_name, i.unit
        FROM controlled_drug_stock_moves m
        LEFT JOIN controlled_drug_items i
          ON i.drug_type = m.drug_type AND i.icode = m.icode
        WHERE m.drug_type = '$type_esc'
        ORDER BY m.id DESC
        LIMIT $limit
    ");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $rows[] = $r;
        }
    }
    return $rows;
}

function cdm_fetch_sync_logs($con, $drug_type, $limit = 20) {
    $drug_type = cdm_normalize_type($drug_type);
    if ($drug_type === '') {
        return [];
    }
    $type_esc = mysqli_real_escape_string($con, $drug_type);
    $limit = max(1, min(100, (int)$limit));
    $rows = [];
    $q = mysqli_query($con, "
        SELECT *
        FROM controlled_drug_hosxp_sync_log
        WHERE drug_type = '$type_esc'
        ORDER BY id DESC
        LIMIT $limit
    ");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $rows[] = $r;
        }
    }
    return $rows;
}

function cdm_move_type_label($type) {
    $map = [
        'opening' => 'ยอดยกมา',
        'hosxp_usage' => 'ตัดจาก HOSxP',
        'adjust' => 'ปรับยอด',
        'manual' => 'บันทึกด้วยตนเอง',
    ];
    return $map[$type] ?? $type;
}

function cdm_lookup_hosxp_drug_meta($hos, $icode) {
    $icode = trim((string)$icode);
    if (!$hos || $icode === '') {
        return ['drug_name' => '', 'unit' => ''];
    }
    $icode_esc = mysqli_real_escape_string($hos, $icode);
    $tmt_name = hosxp_sql_tmt_drug_name('d', 'tm');
    $q = mysqli_query($hos, "
        SELECT CAST(CONVERT(d.name USING utf8) AS CHAR) AS name_local,
               $tmt_name AS name_tmt,
               CAST(CONVERT(COALESCE(NULLIF(TRIM(d.units), ''), '') USING utf8) AS CHAR) AS unit
        FROM drugitems d
        " . hosxp_sql_tmt_join('d', 'tm') . "
        WHERE d.icode = '$icode_esc'
        LIMIT 1
    ");
    if (!$q || !($r = mysqli_fetch_assoc($q))) {
        return ['drug_name' => $icode, 'unit' => ''];
    }
    $name = trim((string)($r['name_local'] ?? ''));
    $tmt = trim((string)($r['name_tmt'] ?? ''));
    if ($tmt !== '' && $tmt !== $name) {
        $name = $name !== '' ? ($name . ' — ' . $tmt) : $tmt;
    }
    return [
        'drug_name' => $name !== '' ? $name : $icode,
        'unit' => trim((string)($r['unit'] ?? '')),
    ];
}

function cdm_ensure_dispense_tables($con) {
    static $disp_ready = false;
    if ($disp_ready) {
        return true;
    }
    $q = mysqli_query($con, "SHOW TABLES LIKE 'controlled_drug_dispense'");
    if (!$q || mysqli_num_rows($q) === 0) {
        $sql = @file_get_contents(__DIR__ . '/sql/controlled_drug_dispense.sql');
        if ($sql === false || trim($sql) === '') {
            return false;
        }
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt !== '' && !mysqli_query($con, $stmt)) {
                return false;
            }
        }
    }
    $col = mysqli_query($con, "SHOW COLUMNS FROM controlled_drug_dispense LIKE 'drug_remains_qty'");
    if ($col && mysqli_num_rows($col) === 0) {
        @mysqli_query($con, "ALTER TABLE controlled_drug_dispense ADD COLUMN drug_remains_qty DECIMAL(14,4) NULL AFTER dispense_qty");
    }
    $col2 = mysqli_query($con, "SHOW COLUMNS FROM controlled_drug_dispense LIKE 'drug_remains_received_qty'");
    if ($col2 && mysqli_num_rows($col2) === 0) {
        @mysqli_query($con, "ALTER TABLE controlled_drug_dispense ADD COLUMN drug_remains_received_qty DECIMAL(14,4) NULL AFTER drug_remains_qty");
    }
    $col3 = mysqli_query($con, "SHOW COLUMNS FROM controlled_drug_dispense LIKE 'preparer_name'");
    if ($col3 && mysqli_num_rows($col3) === 0) {
        @mysqli_query($con, "ALTER TABLE controlled_drug_dispense ADD COLUMN preparer_name VARCHAR(255) NOT NULL DEFAULT '' AFTER receiver_name");
    }
    $col4 = mysqli_query($con, "SHOW COLUMNS FROM controlled_drug_dispense LIKE 'prepared_at'");
    if ($col4 && mysqli_num_rows($col4) === 0) {
        @mysqli_query($con, "ALTER TABLE controlled_drug_dispense ADD COLUMN prepared_at DATETIME NULL AFTER dispensed_at");
    }
    @mysqli_query($con, "ALTER TABLE controlled_drug_dispense MODIFY COLUMN status ENUM('pending','prepared','completed') NOT NULL DEFAULT 'pending'");
    $disp_ready = true;
    return true;
}

function cdm_normalize_service_mode($mode) {
    $mode = strtolower(trim((string)$mode));
    return in_array($mode, ['opd', 'ipd', 'all'], true) ? $mode : '';
}

function cdm_service_mode_label($mode) {
    $map = [
        'opd' => 'ผู้ป่วยนอก (OPD)',
        'ipd' => 'ผู้ป่วยใน (IPD)',
        'all' => 'ทั้งหมด (OPD + IPD)',
    ];
    return $map[cdm_normalize_service_mode($mode)] ?? $mode;
}

function cdm_parse_date_range($start_date, $end_date, $default_days = 7, $max_days = 31) {
    $end = cdm_parse_date($end_date) ?? date('Y-m-d');
    $start = cdm_parse_date($start_date);
    if ($start === null) {
        $start = date('Y-m-d', strtotime('-' . max(0, (int)$default_days - 1) . ' days', strtotime($end)));
    }
    if ($start > $end) {
        $tmp = $start;
        $start = $end;
        $end = $tmp;
    }
    $days = (int)floor((strtotime($end) - strtotime($start)) / 86400) + 1;
    if ($days > $max_days) {
        return ['error' => 'ช่วงวันที่ต้องไม่เกิน ' . $max_days . ' วัน'];
    }
    return [
        'start_date' => $start,
        'end_date' => $end,
        'days' => $days,
    ];
}

function cdm_hosxp_sql_drug_name_simple($d = 'd') {
    return "CAST(CONVERT(COALESCE(NULLIF(TRIM($d.name), ''), $d.icode) USING utf8) AS CHAR)";
}

function cdm_build_local_item_maps($con, $drug_type) {
    $names = [];
    $units = [];
    foreach (cdm_fetch_items($con, $drug_type, true) as $item) {
        $icode = trim((string)($item['icode'] ?? ''));
        if ($icode === '') {
            continue;
        }
        $names[$icode] = trim((string)($item['drug_name'] ?? ''));
        $units[$icode] = trim((string)($item['unit'] ?? ''));
    }
    return ['names' => $names, 'units' => $units];
}

function cdm_build_icode_sql($hos, array $icodes) {
    $parts = [];
    foreach ($icodes as $icode) {
        $icode = trim((string)$icode);
        if ($icode === '') {
            continue;
        }
        $parts[] = "'" . mysqli_real_escape_string($hos, $icode) . "'";
    }
    return $parts;
}

function cdm_hosxp_sql_ptname($alias = 'p') {
    return "CAST(CONVERT(CONCAT(IFNULL($alias.pname,''), IFNULL($alias.fname,''), ' ', IFNULL($alias.lname,'')) USING utf8) AS CHAR)";
}

function cdm_hosxp_sql_dept_code() {
    return "COALESCE(NULLIF(TRIM(ov.main_dep), ''), NULLIF(TRIM(ov.spclty), ''), '__none__')";
}

function cdm_hosxp_sql_dept_name() {
    $kdep = "CAST(CONVERT(COALESCE(kdep.department, '') USING utf8) AS CHAR)";
    $spname = "CAST(CONVERT(COALESCE(NULLIF(TRIM(sp.name), ''), NULLIF(TRIM(ov.spclty), ''), 'ไม่ระบุหน่วย') USING utf8) AS CHAR)";
    return "CASE WHEN NULLIF(TRIM(ov.main_dep), '') IS NOT NULL AND $kdep <> '' THEN $kdep ELSE $spname END";
}

function cdm_hosxp_fetch_units_summary($hos, $con, $drug_type, $service_date, $service_mode) {
    $drug_type = cdm_normalize_type($drug_type);
    $service_mode = cdm_normalize_service_mode($service_mode);
    $service_date = cdm_parse_date($service_date);
    if (!$hos || $drug_type === '' || $service_mode === '' || $service_date === null) {
        return ['rows' => [], 'error' => 'ข้อมูลไม่ครบหรือเชื่อมต่อ HOSxP ไม่ได้'];
    }
    $icodes = cdm_fetch_active_icodes($con, $drug_type);
    if (empty($icodes)) {
        return ['rows' => [], 'error' => 'ยังไม่มีรายการยาที่ตั้งค่าไว้ — กรุณาตั้งค่ารายการยาก่อน'];
    }
    $icode_sql = cdm_build_icode_sql($hos, $icodes);
    if (empty($icode_sql)) {
        return ['rows' => [], 'error' => 'ไม่มีรหัสยา'];
    }
    $date_esc = mysqli_real_escape_string($hos, $service_date);

    if ($service_mode === 'ipd') {
        $sql = "
            SELECT TRIM(ia.ward) AS unit_code,
                   CAST(CONVERT(COALESCE(NULLIF(TRIM(w.name), ''), TRIM(ia.ward), 'ไม่ระบุหอ') USING utf8) AS CHAR) AS unit_name,
                   COUNT(DISTINCT o.hn) AS patient_count,
                   COUNT(*) AS order_count,
                   SUM(o.qty) AS total_qty
            FROM opitemrece o
            INNER JOIN ipt ia ON ia.an = o.an
            LEFT JOIN ward w ON w.ward = ia.ward
            WHERE o.vstdate = '$date_esc'
              AND o.qty > 0
              AND TRIM(IFNULL(o.an, '')) <> ''
              AND o.icode IN (" . implode(',', $icode_sql) . ")
            GROUP BY unit_code, unit_name
            HAVING unit_code IS NOT NULL AND TRIM(unit_code) <> ''
            ORDER BY unit_name ASC
        ";
    } else {
        $dept_code = cdm_hosxp_sql_dept_code();
        $dept_name = cdm_hosxp_sql_dept_name();
        $sql = "
            SELECT $dept_code AS unit_code,
                   $dept_name AS unit_name,
                   COUNT(DISTINCT o.hn) AS patient_count,
                   COUNT(*) AS order_count,
                   SUM(o.qty) AS total_qty
            FROM opitemrece o
            INNER JOIN ovst ov ON ov.vn = o.vn
            LEFT JOIN kskdepartment kdep ON kdep.depcode = ov.main_dep
            LEFT JOIN spclty sp ON sp.spclty = ov.spclty
            WHERE o.vstdate = '$date_esc'
              AND o.qty > 0
              AND (o.an IS NULL OR TRIM(o.an) = '')
              AND o.icode IN (" . implode(',', $icode_sql) . ")
            GROUP BY unit_code, unit_name
            ORDER BY unit_name ASC
        ";
    }

    $q = mysqli_query($hos, $sql);
    if (!$q) {
        return ['rows' => [], 'error' => mysqli_error($hos)];
    }
    $rows = [];
    while ($r = mysqli_fetch_assoc($q)) {
        $rows[] = [
            'unit_code' => trim((string)($r['unit_code'] ?? '')),
            'unit_name' => trim((string)($r['unit_name'] ?? '')),
            'patient_count' => (int)($r['patient_count'] ?? 0),
            'order_count' => (int)($r['order_count'] ?? 0),
            'total_qty' => (float)($r['total_qty'] ?? 0),
        ];
    }
    return ['rows' => $rows, 'error' => null];
}

function cdm_hosxp_fetch_dispense_orders($hos, $con, $drug_type, $start_date, $end_date, $service_mode, $unit_filter_key = '') {
    $drug_type = cdm_normalize_type($drug_type);
    $service_mode = cdm_normalize_service_mode($service_mode);
    $range = cdm_parse_date_range($start_date, $end_date, 7, 31);
    if (!empty($range['error'])) {
        return ['rows' => [], 'error' => $range['error']];
    }
    $start_date = $range['start_date'];
    $end_date = $range['end_date'];
    $unit_filter_key = trim((string)$unit_filter_key);
    if (!$hos || $drug_type === '' || $service_mode === '') {
        return ['rows' => [], 'error' => 'ข้อมูลไม่ครบ'];
    }
    $icodes = cdm_fetch_active_icodes($con, $drug_type);
    if (empty($icodes)) {
        return ['rows' => [], 'error' => 'ยังไม่มีรายการยาที่ตั้งค่าไว้'];
    }
    $icode_sql = cdm_build_icode_sql($hos, $icodes);
    if (empty($icode_sql)) {
        return ['rows' => [], 'error' => 'ไม่มีรหัสยา'];
    }
    $icode_in = implode(',', $icode_sql);
    $local_maps = cdm_build_local_item_maps($con, $drug_type);
    $saved = cdm_fetch_dispense_map_by_range($con, $drug_type, $start_date, $end_date);

    $filter_mode = '';
    $filter_code = '';
    if ($unit_filter_key !== '' && strpos($unit_filter_key, ':') !== false) {
        [$filter_mode, $filter_code] = explode(':', $unit_filter_key, 2);
        $filter_mode = cdm_normalize_service_mode($filter_mode);
        $filter_code = trim($filter_code);
    }

    $start_esc = mysqli_real_escape_string($hos, $start_date);
    $end_esc = mysqli_real_escape_string($hos, $end_date);
    $date_filter = "o.vstdate >= '$start_esc' AND o.vstdate <= '$end_esc'";

    $queries = [];
    $modes_to_run = $service_mode === 'all' ? ['ipd', 'opd'] : [$service_mode];
    foreach ($modes_to_run as $mode) {
        if ($filter_mode !== '' && $filter_mode !== $mode) {
            continue;
        }
        $unit_code = ($filter_mode === $mode && $filter_code !== '') ? $filter_code : '';
        $sql = cdm_hosxp_sql_dispense_orders($hos, $mode, $date_filter, $icode_in, $unit_code);
        if ($sql !== '') {
            $queries[] = $sql;
        }
    }
    if (empty($queries)) {
        return ['rows' => [], 'error' => null, 'start_date' => $start_date, 'end_date' => $end_date];
    }

    $order = ' ORDER BY rxdate DESC, rxtime DESC, ptname ASC, drug_name ASC, item_no ASC';
    $sql = count($queries) === 1
        ? $queries[0] . $order
        : implode(' UNION ALL ', $queries) . $order;

    $q = mysqli_query($hos, $sql);
    if (!$q) {
        return ['rows' => [], 'error' => mysqli_error($hos)];
    }

    $rows = [];
    while ($r = mysqli_fetch_assoc($q)) {
        $row = cdm_map_dispense_order_row($r, $local_maps, $start_date, $end_date);
        if ($row !== null) {
            $rows[] = $row;
        }
    }

    foreach ($rows as &$row) {
        $s = $saved[$row['hosxp_guid']] ?? null;
        if ($s) {
            $row['dispense_id'] = (int)$s['id'];
            $row['dispense_qty'] = $s['dispense_qty'] !== null ? (float)$s['dispense_qty'] : null;
            $row['drug_remains_qty'] = $s['drug_remains_qty'] !== null ? (float)$s['drug_remains_qty'] : null;
            $row['drug_remains_received_qty'] = $s['drug_remains_received_qty'] !== null ? (float)$s['drug_remains_received_qty'] : null;
            $row['form_received'] = (int)($s['form_received'] ?? 0);
            $row['receiver_name'] = $s['receiver_name'] ?? '';
            $row['preparer_name'] = $s['preparer_name'] ?? '';
            $row['dispenser_name'] = $s['dispenser_name'] ?? '';
            $row['receiver_signature'] = '';
            $row['dispenser_signature'] = '';
            $row['note'] = $s['note'] ?? '';
            $row['status'] = $s['status'] ?? 'pending';
            $row['stock_room_id'] = (int)($s['stock_room_id'] ?? 0);
            $row['saved'] = true;
            $row['prepared_at'] = $s['prepared_at'] ?? '';
        } else {
            $row['dispense_id'] = 0;
            $row['dispense_qty'] = null;
            $row['drug_remains_qty'] = null;
            $row['drug_remains_received_qty'] = null;
            $row['form_received'] = 0;
            $row['receiver_name'] = '';
            $row['preparer_name'] = '';
            $row['dispenser_name'] = '';
            $row['receiver_signature'] = '';
            $row['dispenser_signature'] = '';
            $row['note'] = '';
            $row['status'] = 'pending';
            $row['stock_room_id'] = cdm_resolve_room_for_service($con, $drug_type, $row['service_mode'] ?? '');
            $row['saved'] = false;
        }
    }
    unset($row);

    foreach ($rows as &$row) {
        $row = array_merge($row, cdm_dispense_remains_status($row));
        if (!empty($row['saved'])) {
            $row['workflow'] = cdm_dispense_workflow_meta($row['status'] ?? 'pending');
        }
    }
    unset($row);

    return ['rows' => $rows, 'error' => null, 'start_date' => $start_date, 'end_date' => $end_date];
}

function cdm_hosxp_sql_dispense_orders($hos, $mode, $date_filter, $icode_in, $unit_code = '') {
    $ptname = cdm_hosxp_sql_ptname('p');
    $unit_code = trim((string)$unit_code);
    $unit_filter = '';
    if ($unit_code !== '') {
        $unit_esc = mysqli_real_escape_string($hos, $unit_code);
        if ($mode === 'ipd') {
            $unit_filter = " AND TRIM(ia.ward) = '$unit_esc' ";
        } else {
            $dept_code = cdm_hosxp_sql_dept_code();
            $unit_filter = " AND $dept_code = '$unit_esc' ";
        }
    }

    if ($mode === 'ipd') {
        return "
            SELECT 'ipd' AS service_mode,
                   o.hos_guid AS hosxp_guid,
                   o.item_no,
                   o.hn,
                   IFNULL(o.vn, '') AS vn,
                   o.an,
                   $ptname AS ptname,
                   CAST(CONVERT(COALESCE(NULLIF(TRIM(w.name), ''), TRIM(ia.ward), '') USING utf8) AS CHAR) AS unit_name,
                   CAST(CONVERT(COALESCE(NULLIF(TRIM(w.name), ''), '') USING utf8) AS CHAR) AS ward_name,
                   IFNULL(ia.cur_bedno, '') AS bedno,
                   o.icode,
                   o.icode AS drug_name,
                   '' AS unit,
                   o.qty AS order_qty,
                   o.rxdate,
                   o.rxtime,
                   o.vstdate,
                   o.vsttime,
                   TRIM(ia.ward) AS unit_code
            FROM opitemrece o
            INNER JOIN ipt ia ON ia.an = o.an
            INNER JOIN patient p ON p.hn = o.hn
            LEFT JOIN ward w ON w.ward = ia.ward
            WHERE $date_filter
              AND o.qty > 0
              AND TRIM(IFNULL(o.an, '')) <> ''
              $unit_filter
              AND o.icode IN ($icode_in)
        ";
    }

    $dept_code = cdm_hosxp_sql_dept_code();
    $dept_name = cdm_hosxp_sql_dept_name();
    return "
        SELECT 'opd' AS service_mode,
               o.hos_guid AS hosxp_guid,
               o.item_no,
               o.hn,
               o.vn,
               IFNULL(o.an, '') AS an,
               $ptname AS ptname,
               $dept_name AS unit_name,
               '' AS ward_name,
               '' AS bedno,
               o.icode,
               o.icode AS drug_name,
               '' AS unit,
               o.qty AS order_qty,
               o.rxdate,
               o.rxtime,
               o.vstdate,
               o.vsttime,
               $dept_code AS unit_code
        FROM opitemrece o
        INNER JOIN ovst ov ON ov.vn = o.vn
        INNER JOIN patient p ON p.hn = o.hn
        LEFT JOIN kskdepartment kdep ON kdep.depcode = ov.main_dep
        LEFT JOIN spclty sp ON sp.spclty = ov.spclty
        WHERE $date_filter
          AND o.qty > 0
          AND (o.an IS NULL OR TRIM(o.an) = '')
          $unit_filter
          AND o.icode IN ($icode_in)
    ";
}

function cdm_map_dispense_order_row(array $r, array $local_maps, $start_date, $end_date) {
    $vstdate = trim((string)($r['vstdate'] ?? ''));
    $guid = trim((string)($r['hosxp_guid'] ?? ''));
    if ($guid === '') {
        $guid = md5(implode('|', [
            $r['hn'] ?? '',
            $r['vn'] ?? '',
            $r['an'] ?? '',
            $r['icode'] ?? '',
            $r['item_no'] ?? '',
            $vstdate,
            $r['service_mode'] ?? '',
        ]));
    }
    $icode = trim((string)($r['icode'] ?? ''));
    $drug_name_hos = trim((string)($r['drug_name'] ?? ''));
    $unit_hos = trim((string)($r['unit'] ?? ''));
    $local_name = $local_maps['names'][$icode] ?? '';
    $local_unit = $local_maps['units'][$icode] ?? '';
    $rxdate = trim((string)($r['rxdate'] ?? ''));
    $rxtime = trim((string)($r['rxtime'] ?? ''));
    $vsttime = trim((string)($r['vsttime'] ?? ''));
    $service_mode = cdm_normalize_service_mode($r['service_mode'] ?? '');
    $unit_code = trim((string)($r['unit_code'] ?? ''));

    return [
        'hosxp_guid' => $guid,
        'service_mode' => $service_mode,
        'filter_key' => ($service_mode !== '' && $unit_code !== '') ? $service_mode . ':' . $unit_code : $unit_code,
        'item_no' => (int)($r['item_no'] ?? 0),
        'hn' => trim((string)($r['hn'] ?? '')),
        'vn' => trim((string)($r['vn'] ?? '')),
        'an' => trim((string)($r['an'] ?? '')),
        'ptname' => trim((string)($r['ptname'] ?? '')),
        'unit_code' => $unit_code,
        'unit_name' => trim((string)($r['unit_name'] ?? '')),
        'ward_name' => trim((string)($r['ward_name'] ?? '')),
        'bedno' => trim((string)($r['bedno'] ?? '')),
        'icode' => $icode,
        'drug_name' => $local_name !== '' ? $local_name : $drug_name_hos,
        'unit' => $local_unit !== '' ? $local_unit : $unit_hos,
        'order_qty' => (float)($r['order_qty'] ?? 0),
        'rxdate' => $rxdate,
        'rxtime' => $rxtime,
        'vstdate' => $vstdate,
        'vsttime' => $vsttime,
        'order_datetime' => cdm_format_order_datetime($rxdate, $rxtime, $vstdate, $vsttime),
    ];
}

function cdm_fetch_dispense_map_by_range($con, $drug_type, $start_date, $end_date) {
    $drug_type = cdm_normalize_type($drug_type);
    $start_date = cdm_parse_date($start_date);
    $end_date = cdm_parse_date($end_date);
    if ($drug_type === '' || $start_date === null || $end_date === null) {
        return [];
    }
    $type_esc = mysqli_real_escape_string($con, $drug_type);
    $start_esc = mysqli_real_escape_string($con, $start_date);
    $end_esc = mysqli_real_escape_string($con, $end_date);
    $map = [];
    $q = mysqli_query($con, "
        SELECT id, hosxp_guid, dispense_qty, drug_remains_qty, drug_remains_received_qty,
               form_received, receiver_name, preparer_name, dispenser_name, note, status,
               stock_room_id, prepared_at, dispensed_at
        FROM controlled_drug_dispense
        WHERE drug_type = '$type_esc'
          AND service_date >= '$start_esc'
          AND service_date <= '$end_esc'
    ");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $map[$r['hosxp_guid']] = $r;
        }
    }
    return $map;
}

function cdm_fetch_dispense_detail($con, $drug_type, $dispense_id) {
    $drug_type = cdm_normalize_type($drug_type);
    $dispense_id = (int)$dispense_id;
    if ($drug_type === '' || $dispense_id <= 0) {
        return null;
    }
    $type_esc = mysqli_real_escape_string($con, $drug_type);
    $q = mysqli_query($con, "
        SELECT id, hosxp_guid, receiver_signature, dispenser_signature
        FROM controlled_drug_dispense
        WHERE drug_type = '$type_esc' AND id = $dispense_id
        LIMIT 1
    ");
    return ($q && ($r = mysqli_fetch_assoc($q))) ? $r : null;
}

function cdm_map_dispense_register_row(array $r) {
    $service_mode = cdm_normalize_service_mode($r['service_mode'] ?? '');
    $unit_code = trim((string)($r['unit_code'] ?? ''));
    $row = [
        'dispense_id' => (int)($r['id'] ?? 0),
        'hosxp_guid' => trim((string)($r['hosxp_guid'] ?? '')),
        'service_mode' => $service_mode,
        'hn' => trim((string)($r['hn'] ?? '')),
        'vn' => trim((string)($r['vn'] ?? '')),
        'an' => trim((string)($r['an'] ?? '')),
        'ptname' => trim((string)($r['ptname'] ?? '')),
        'unit_code' => $unit_code,
        'unit_name' => trim((string)($r['unit_name'] ?? '')),
        'ward_name' => trim((string)($r['ward_name'] ?? '')),
        'bedno' => trim((string)($r['bedno'] ?? '')),
        'icode' => trim((string)($r['icode'] ?? '')),
        'stock_room_id' => (int)($r['stock_room_id'] ?? 0),
        'drug_name' => trim((string)($r['drug_name'] ?? '')),
        'unit' => trim((string)($r['unit'] ?? '')),
        'order_qty' => (float)($r['order_qty'] ?? 0),
        'dispense_qty' => $r['dispense_qty'] !== null ? (float)$r['dispense_qty'] : null,
        'drug_remains_qty' => $r['drug_remains_qty'] !== null ? (float)$r['drug_remains_qty'] : null,
        'drug_remains_received_qty' => $r['drug_remains_received_qty'] !== null ? (float)$r['drug_remains_received_qty'] : null,
        'form_received' => (int)($r['form_received'] ?? 0),
        'receiver_name' => trim((string)($r['receiver_name'] ?? '')),
        'preparer_name' => trim((string)($r['preparer_name'] ?? '')),
        'dispenser_name' => trim((string)($r['dispenser_name'] ?? '')),
        'note' => trim((string)($r['note'] ?? '')),
        'status' => trim((string)($r['status'] ?? '')),
        'saved' => true,
        'vstdate' => trim((string)($r['service_date'] ?? '')),
        'filter_key' => ($unit_code !== '' ? $service_mode . ':' . $unit_code : ''),
        'dispensed_at' => trim((string)($r['dispensed_at'] ?? '')),
        'prepared_at' => trim((string)($r['prepared_at'] ?? '')),
    ];
    $disp_at = $row['dispensed_at'];
    if ($disp_at !== '' && $disp_at !== '0000-00-00 00:00:00') {
        $ts = strtotime($disp_at);
        $row['order_datetime'] = $ts ? date('d/m/Y H:i', $ts) : $disp_at;
    } elseif (($row['prepared_at'] ?? '') !== '' && $row['prepared_at'] !== '0000-00-00 00:00:00') {
        $ts = strtotime($row['prepared_at']);
        $row['order_datetime'] = $ts ? date('d/m/Y H:i', $ts) : $row['prepared_at'];
    } else {
        $row['order_datetime'] = cdm_format_order_datetime('', '', $row['vstdate'], '');
    }
    $row['workflow'] = cdm_dispense_workflow_meta($row['status']);
    return array_merge($row, cdm_dispense_remains_status($row));
}

function cdm_dispense_register_sort_ts(array $row) {
    $status = trim((string)($row['status'] ?? ''));
    $raw = $status === 'completed'
        ? trim((string)($row['dispensed_at'] ?? ''))
        : trim((string)($row['prepared_at'] ?? ''));
    if ($raw === '' || $raw === '0000-00-00 00:00:00') {
        $raw = trim((string)($row['dispensed_at'] ?? ''));
        if ($raw === '' || $raw === '0000-00-00 00:00:00') {
            $raw = trim((string)($row['prepared_at'] ?? ''));
        }
    }
    if ($raw !== '' && $raw !== '0000-00-00 00:00:00') {
        $ts = strtotime($raw);
        if ($ts) {
            return $ts;
        }
    }
    $vst = trim((string)($row['vstdate'] ?? ''));
    if ($vst !== '') {
        $ts = strtotime($vst . ' 23:59:59');
        if ($ts) {
            return $ts;
        }
    }
    return 0;
}

function cdm_fetch_dispense_register($con, $drug_type, $start_date, $end_date, $service_mode = 'all', $unit_filter_key = '') {
    cdm_ensure_dispense_tables($con);
    $drug_type = cdm_normalize_type($drug_type);
    $service_mode = cdm_normalize_service_mode($service_mode);
    $range = cdm_parse_date_range($start_date, $end_date, 7, 31);
    if (!empty($range['error'])) {
        return ['rows' => [], 'error' => $range['error']];
    }
    $start_date = $range['start_date'];
    $end_date = $range['end_date'];
    if ($drug_type === '') {
        return ['rows' => [], 'error' => 'ข้อมูลไม่ครบ'];
    }

    $type_esc = mysqli_real_escape_string($con, $drug_type);
    $start_esc = mysqli_real_escape_string($con, $start_date);
    $end_esc = mysqli_real_escape_string($con, $end_date);
    $where = [
        "drug_type = '$type_esc'",
        "service_date >= '$start_esc'",
        "service_date <= '$end_esc'",
        "status IN ('prepared','completed')",
    ];

    if ($service_mode !== '' && $service_mode !== 'all') {
        $mode_esc = mysqli_real_escape_string($con, $service_mode);
        $where[] = "service_mode = '$mode_esc'";
    }

    $unit_filter_key = trim((string)$unit_filter_key);
    if ($unit_filter_key !== '' && strpos($unit_filter_key, ':') !== false) {
        [$filter_mode, $filter_code] = explode(':', $unit_filter_key, 2);
        $filter_mode = cdm_normalize_service_mode($filter_mode);
        $filter_code = trim($filter_code);
        if ($filter_mode !== '' && $filter_code !== '') {
            $mode_esc = mysqli_real_escape_string($con, $filter_mode);
            $code_esc = mysqli_real_escape_string($con, $filter_code);
            $where[] = "service_mode = '$mode_esc'";
            $where[] = "unit_code = '$code_esc'";
        }
    }

    $rows = [];
    $q = mysqli_query($con, "
        SELECT *
        FROM controlled_drug_dispense
        WHERE " . implode(' AND ', $where) . "
        ORDER BY
            CASE WHEN status = 'prepared' THEN 0 ELSE 1 END ASC,
            COALESCE(
                NULLIF(CASE WHEN status = 'completed' THEN dispensed_at ELSE prepared_at END, '0000-00-00 00:00:00'),
                NULLIF(prepared_at, '0000-00-00 00:00:00'),
                NULLIF(dispensed_at, '0000-00-00 00:00:00'),
                CONCAT(service_date, ' 23:59:59')
            ) DESC,
            id DESC
        LIMIT 2000
    ");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $rows[] = cdm_map_dispense_register_row($r);
        }
    }

    usort($rows, function ($a, $b) {
        $pa = ($a['status'] ?? '') === 'prepared' ? 0 : 1;
        $pb = ($b['status'] ?? '') === 'prepared' ? 0 : 1;
        if ($pa !== $pb) {
            return $pa <=> $pb;
        }
        $ta = cdm_dispense_register_sort_ts($a);
        $tb = cdm_dispense_register_sort_ts($b);
        if ($ta !== $tb) {
            return $tb <=> $ta;
        }
        return (int)($b['dispense_id'] ?? 0) <=> (int)($a['dispense_id'] ?? 0);
    });

    return [
        'rows' => $rows,
        'error' => null,
        'start_date' => $start_date,
        'end_date' => $end_date,
    ];
}

function cdm_extract_unit_options(array $rows, $view_mode = 'all') {
    $view_mode = cdm_normalize_service_mode($view_mode);
    if ($view_mode === '') {
        $view_mode = 'all';
    }
    $map = [];
    foreach ($rows as $row) {
        $code = trim((string)($row['unit_code'] ?? ''));
        $name = trim((string)($row['unit_name'] ?? ''));
        $mode = cdm_normalize_service_mode($row['service_mode'] ?? '');
        if ($code === '' && $name === '') {
            continue;
        }
        $filter_key = trim((string)($row['filter_key'] ?? ''));
        if ($filter_key === '') {
            $filter_key = ($mode !== '' && $code !== '') ? $mode . ':' . $code : ($code !== '' ? $code : $name);
        }
        if (!isset($map[$filter_key])) {
            $display = $name !== '' ? $name : $code;
            if ($view_mode === 'all') {
                if ($mode === 'ipd') {
                    $display = '[IPD] ' . $display;
                } elseif ($mode === 'opd') {
                    $display = '[OPD] ' . $display;
                }
            }
            $map[$filter_key] = [
                'filter_key' => $filter_key,
                'service_mode' => $mode,
                'unit_code' => $code,
                'unit_name' => $display,
                'order_count' => 0,
            ];
        }
        $map[$filter_key]['order_count']++;
    }
    $list = array_values($map);
    usort($list, function ($a, $b) {
        return strcmp($a['unit_name'], $b['unit_name']);
    });
    return $list;
}

function cdm_order_datetime_from_parts($rxdate, $rxtime, $vstdate = '', $vsttime = '') {
    $date = trim((string)$rxdate);
    if ($date === '' || $date === '0000-00-00') {
        $date = trim((string)$vstdate);
    }
    $time = trim((string)$rxtime);
    if ($time === '') {
        $time = trim((string)$vsttime);
    }
    return ['date' => $date, 'time' => $time];
}

function cdm_format_order_datetime($rxdate, $rxtime, $vstdate = '', $vsttime = '') {
    $parts = cdm_order_datetime_from_parts($rxdate, $rxtime, $vstdate, $vsttime);
    $date = $parts['date'];
    $time = $parts['time'];
    if ($date === '' || $date === '0000-00-00') {
        return '';
    }
    $ts = strtotime($date . ' ' . ($time !== '' ? $time : '00:00:00'));
    if (!$ts) {
        return $date . ($time !== '' ? ' ' . substr($time, 0, 5) : '');
    }
    $be = (int)date('Y', $ts) + 543;
    return date('d/m/', $ts) . $be . ' ' . date('H:i', $ts);
}

function cdm_location_display(array $row, $service_mode = '') {
    $unit = trim((string)($row['unit_name'] ?? ''));
    if ($service_mode === 'ipd' || ($row['bedno'] ?? '') !== '' || ($row['ward_name'] ?? '') !== '') {
        $bed = trim((string)($row['bedno'] ?? ''));
        if ($unit !== '' && $bed !== '') {
            return $unit . ' / เตียง ' . $bed;
        }
        return $unit !== '' ? $unit : trim((string)($row['ward_name'] ?? ''));
    }
    return $unit !== '' ? $unit : '-';
}

function cdm_fetch_dispense_map($con, $drug_type, array $guids) {
    $drug_type = cdm_normalize_type($drug_type);
    if ($drug_type === '' || empty($guids)) {
        return [];
    }
    $type_esc = mysqli_real_escape_string($con, $drug_type);
    $guid_sql = [];
    foreach ($guids as $g) {
        $g = trim((string)$g);
        if ($g !== '') {
            $guid_sql[] = "'" . mysqli_real_escape_string($con, $g) . "'";
        }
    }
    if (empty($guid_sql)) {
        return [];
    }
    $map = [];
    $q = mysqli_query($con, "
        SELECT *
        FROM controlled_drug_dispense
        WHERE drug_type = '$type_esc'
          AND hosxp_guid IN (" . implode(',', $guid_sql) . ")
    ");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $map[$r['hosxp_guid']] = $r;
        }
    }
    return $map;
}

function cdm_save_dispense($con, array $data) {
    cdm_ensure_dispense_tables($con);
    $drug_type = cdm_normalize_type($data['drug_type'] ?? '');
    $service_mode = cdm_normalize_service_mode($data['service_mode'] ?? '');
    if ($service_mode === 'all') {
        $service_mode = cdm_normalize_service_mode($data['row_service_mode'] ?? '');
    }
    $service_date = cdm_parse_date($data['service_date'] ?? '');
    if ($service_date === null) {
        $service_date = cdm_parse_date($data['vstdate'] ?? '');
    }
    $hosxp_guid = trim((string)($data['hosxp_guid'] ?? ''));
    if ($drug_type === '' || $service_mode === '' || $service_date === null || $hosxp_guid === '') {
        return ['status' => 'error', 'message' => 'ข้อมูลไม่ครบ'];
    }

    $dispense_qty = cdm_parse_qty($data['dispense_qty'] ?? '');
    if ($dispense_qty === null) {
        $dispense_qty = (float)($data['order_qty'] ?? 0);
    }
    $form_received = !empty($data['form_received']) ? 1 : 0;
    $drug_remains_qty = cdm_parse_qty($data['drug_remains_qty'] ?? '');
    if ($drug_remains_qty === null) {
        $drug_remains_qty = $dispense_qty;
    }
    $drug_remains_received_qty = cdm_parse_qty($data['drug_remains_received_qty'] ?? '');
    if ($drug_remains_received_qty === null) {
        $drug_remains_received_qty = 0;
    }
    $receiver_name = trim((string)($data['receiver_name'] ?? ''));
    $preparer_name = trim((string)($data['preparer_name'] ?? ''));
    $dispenser_name = trim((string)($data['dispenser_name'] ?? ''));
    $receiver_sig = trim((string)($data['receiver_signature'] ?? ''));
    $dispenser_sig = trim((string)($data['dispenser_signature'] ?? ''));
    $note = trim((string)($data['note'] ?? ''));
    $user_id = isset($data['user_id']) ? (int)$data['user_id'] : null;
    $save_mode = trim((string)($data['save_mode'] ?? ''));
    cdm_ensure_room_tables($con);
    $stock_room_id = (int)($data['stock_room_id'] ?? 0);
    if ($stock_room_id <= 0) {
        $stock_room_id = cdm_resolve_room_for_service($con, $drug_type, $service_mode);
    }

    $completed = cdm_resolve_dispense_status($preparer_name, $receiver_name, $dispenser_name, $dispense_qty, $save_mode);
    if ($save_mode === 'prepare' && $preparer_name === '') {
        return ['status' => 'error', 'message' => 'กรุณาระบุผู้จัดเตรียมยา'];
    }
    if ($save_mode === 'dispense' && $preparer_name === '') {
        return ['status' => 'error', 'message' => 'กรุณาระบุผู้จัดเตรียมยาก่อนบันทึกจ่ายยา'];
    }
    if ($save_mode === 'dispense' && ($receiver_name === '' || $dispenser_name === '')) {
        return ['status' => 'error', 'message' => 'กรุณาระบุผู้รับยาและผู้จ่ายยา'];
    }

    $type_esc = mysqli_real_escape_string($con, $drug_type);
    $guid_esc = mysqli_real_escape_string($con, $hosxp_guid);
    $existing_q = mysqli_query($con, "
        SELECT id FROM controlled_drug_dispense
        WHERE drug_type = '$type_esc' AND hosxp_guid = '$guid_esc'
        LIMIT 1
    ");
    $existing = ($existing_q && ($row = mysqli_fetch_assoc($existing_q))) ? (int)$row['id'] : 0;

    $fields = [
        'service_date' => $service_date,
        'service_mode' => $service_mode,
        'unit_code' => trim((string)($data['unit_code'] ?? '')),
        'unit_name' => trim((string)($data['unit_name'] ?? '')),
        'hn' => trim((string)($data['hn'] ?? '')),
        'vn' => trim((string)($data['vn'] ?? '')),
        'an' => trim((string)($data['an'] ?? '')),
        'ptname' => trim((string)($data['ptname'] ?? '')),
        'ward_name' => trim((string)($data['ward_name'] ?? '')),
        'bedno' => trim((string)($data['bedno'] ?? '')),
        'item_no' => (int)($data['item_no'] ?? 0),
        'icode' => trim((string)($data['icode'] ?? '')),
        'drug_name' => trim((string)($data['drug_name'] ?? '')),
        'unit' => trim((string)($data['unit'] ?? '')),
        'order_qty' => (float)($data['order_qty'] ?? 0),
        'dispense_qty' => $dispense_qty,
        'drug_remains_qty' => $drug_remains_qty,
        'drug_remains_received_qty' => $drug_remains_received_qty,
        'form_received' => $form_received,
        'receiver_name' => $receiver_name,
        'preparer_name' => $preparer_name,
        'dispenser_name' => $dispenser_name,
        'receiver_signature' => $receiver_sig,
        'dispenser_signature' => $dispenser_sig,
        'note' => $note,
        'status' => $completed,
        'user_id' => $user_id,
    ];

    if ($existing > 0) {
        $sets = [];
        foreach ($fields as $col => $val) {
            if (in_array($col, ['receiver_signature', 'dispenser_signature'], true) && $val === '') {
                continue;
            }
            if (is_int($val) || is_float($val)) {
                $sets[] = "$col = $val";
            } else {
                $sets[] = "$col = '" . mysqli_real_escape_string($con, (string)$val) . "'";
            }
        }
        $sets[] = 'stock_room_id = ' . ($stock_room_id > 0 ? (string)$stock_room_id : 'NULL');
        $sets[] = "dispensed_at = " . ($completed === 'completed' ? 'NOW()' : 'NULL');
        $sets[] = "prepared_at = " . (in_array($completed, ['prepared', 'completed'], true) && $preparer_name !== '' ? 'NOW()' : 'NULL');
        $sets[] = 'updated_at = NOW()';
        $ok = mysqli_query($con, "UPDATE controlled_drug_dispense SET " . implode(', ', $sets) . " WHERE id = $existing LIMIT 1");
        if (!$ok) {
            return ['status' => 'error', 'message' => mysqli_error($con)];
        }
        $msg = $completed === 'completed' ? 'บันทึกการจ่ายยาแล้ว' : ($completed === 'prepared' ? 'บันทึกการจัดเตรียมยาแล้ว' : 'บันทึกแล้ว');
        return ['status' => 'success', 'message' => $msg, 'id' => $existing, 'dispense_status' => $completed];
    }

    $mode_esc = mysqli_real_escape_string($con, $service_mode);
    $date_esc = mysqli_real_escape_string($con, $service_date);
    $user_sql = $user_id ? (string)$user_id : 'NULL';
    $dispensed_sql = $completed === 'completed' ? 'NOW()' : 'NULL';
    $prepared_sql = (in_array($completed, ['prepared', 'completed'], true) && $preparer_name !== '') ? 'NOW()' : 'NULL';

    $sql = "
        INSERT INTO controlled_drug_dispense (
            drug_type, service_date, service_mode, unit_code, unit_name,
            hn, vn, an, ptname, ward_name, bedno, hosxp_guid, item_no,
            icode, stock_room_id, drug_name, unit, order_qty, dispense_qty, drug_remains_qty, drug_remains_received_qty, form_received,
            receiver_name, preparer_name, dispenser_name, receiver_signature, dispenser_signature,
            note, status, dispensed_at, prepared_at, user_id
        ) VALUES (
            '$type_esc', '$date_esc', '$mode_esc',
            '" . mysqli_real_escape_string($con, $fields['unit_code']) . "',
            '" . mysqli_real_escape_string($con, $fields['unit_name']) . "',
            '" . mysqli_real_escape_string($con, $fields['hn']) . "',
            '" . mysqli_real_escape_string($con, $fields['vn']) . "',
            '" . mysqli_real_escape_string($con, $fields['an']) . "',
            '" . mysqli_real_escape_string($con, $fields['ptname']) . "',
            '" . mysqli_real_escape_string($con, $fields['ward_name']) . "',
            '" . mysqli_real_escape_string($con, $fields['bedno']) . "',
            '$guid_esc',
            " . (int)$fields['item_no'] . ",
            '" . mysqli_real_escape_string($con, $fields['icode']) . "',
            " . ($stock_room_id > 0 ? (string)$stock_room_id : 'NULL') . ",
            '" . mysqli_real_escape_string($con, $fields['drug_name']) . "',
            '" . mysqli_real_escape_string($con, $fields['unit']) . "',
            " . (float)$fields['order_qty'] . ",
            $dispense_qty,
            $drug_remains_qty,
            $drug_remains_received_qty,
            $form_received,
            '" . mysqli_real_escape_string($con, $receiver_name) . "',
            '" . mysqli_real_escape_string($con, $preparer_name) . "',
            '" . mysqli_real_escape_string($con, $dispenser_name) . "',
            '" . mysqli_real_escape_string($con, $receiver_sig) . "',
            '" . mysqli_real_escape_string($con, $dispenser_sig) . "',
            '" . mysqli_real_escape_string($con, $note) . "',
            '$completed',
            $dispensed_sql,
            $prepared_sql,
            $user_sql
        )
    ";
    if (!mysqli_query($con, $sql)) {
        return ['status' => 'error', 'message' => mysqli_error($con)];
    }
    $msg = $completed === 'completed' ? 'บันทึกการจ่ายยาแล้ว' : ($completed === 'prepared' ? 'บันทึกการจัดเตรียมยาแล้ว' : 'บันทึกแล้ว');
    return [
        'status' => 'success',
        'message' => $msg,
        'id' => (int)mysqli_insert_id($con),
        'dispense_status' => $completed,
    ];
}

function cdm_form_type_label($drug_type) {
    return cdm_normalize_type($drug_type) === 'psychotropic'
        ? 'ใบวัตถุออกฤทธิ์'
        : 'ใบยาเสพติด';
}

function cdm_dispense_workflow_meta($status) {
    $status = trim((string)$status);
    $map = [
        'pending' => ['code' => 'pending', 'label' => 'รอจัดเตรียม', 'icon' => 'fa-clock', 'class' => 'pending'],
        'prepared' => ['code' => 'prepared', 'label' => 'จัดเตรียมแล้ว', 'icon' => 'fa-mortar-pestle', 'class' => 'prepared'],
        'completed' => ['code' => 'completed', 'label' => 'จ่ายยาแล้ว', 'icon' => 'fa-hand-holding-medical', 'class' => 'completed'],
    ];
    return $map[$status] ?? $map['pending'];
}

function cdm_resolve_dispense_status($preparer_name, $receiver_name, $dispenser_name, $dispense_qty, $save_mode = '') {
    $save_mode = trim((string)$save_mode);
    $preparer_name = trim((string)$preparer_name);
    $receiver_name = trim((string)$receiver_name);
    $dispenser_name = trim((string)$dispenser_name);
    $dispense_qty = (float)$dispense_qty;

    if ($save_mode === 'prepare') {
        return $preparer_name !== '' ? 'prepared' : 'pending';
    }
    if ($save_mode === 'dispense' || ($dispense_qty > 0 && $receiver_name !== '' && $dispenser_name !== '')) {
        return 'completed';
    }
    if ($preparer_name !== '') {
        return 'prepared';
    }
    return 'pending';
}

function cdm_remains_label($drug_type) {
    return cdm_normalize_type($drug_type) === 'psychotropic'
        ? 'ซากวัตถุออกฤทธิ์'
        : 'ซากยาเสพติด';
}

function cdm_dispense_remains_status(array $row) {
    if (empty($row['saved'])) {
        return [
            'remains_expected' => null,
            'remains_received' => null,
            'remains_outstanding' => null,
            'remains_complete' => null,
        ];
    }
    $dispense_qty = (float)($row['dispense_qty'] ?? 0);
    $expected = ($row['drug_remains_qty'] !== null && $row['drug_remains_qty'] !== '')
        ? (float)$row['drug_remains_qty']
        : $dispense_qty;
    $received = ($row['drug_remains_received_qty'] !== null && $row['drug_remains_received_qty'] !== '')
        ? (float)$row['drug_remains_received_qty']
        : 0.0;
    $outstanding = max(0, $expected - $received);
    $complete = ($expected <= 0) || ($outstanding < 0.0001);
    return [
        'remains_expected' => $expected,
        'remains_received' => $received,
        'remains_outstanding' => $complete ? 0.0 : $outstanding,
        'remains_complete' => $complete ? 1 : 0,
    ];
}

function cdm_pharmacist_entryposition_regex() {
    return 'เภสัชกร';
}

function cdm_is_pharmacist_entryposition($entryposition) {
    $entryposition = trim((string)$entryposition);
    if ($entryposition === '') {
        return false;
    }
    if (mb_strpos($entryposition, 'เภสัชกรรม') !== false) {
        return false;
    }
    if (mb_strpos($entryposition, 'รพ.') !== false) {
        return false;
    }
    return (bool)preg_match('/' . preg_quote(cdm_pharmacist_entryposition_regex(), '/') . '/u', $entryposition);
}

function cdm_format_entryposition_label($entryposition) {
    return trim((string)$entryposition);
}

function cdm_build_hosxp_user_select_results(array $users) {
    $results = [];
    foreach ($users as $u) {
        $name = trim((string)($u['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $pos = cdm_format_entryposition_label($u['entryposition'] ?? '');
        $label = $name;
        if ($pos !== '') {
            $label .= ' (' . $pos . ')';
        }
        $results[] = [
            'id' => $name,
            'text' => $label,
        ];
    }
    return $results;
}

function cdm_entryposition_matches_regex($entryposition, $regex) {
    $entryposition = (string)$entryposition;
    $regex = trim((string)$regex);
    if ($regex === '') {
        return true;
    }
    if ($entryposition === '') {
        return false;
    }
    return (bool)preg_match('/' . preg_quote($regex, '/') . '/u', $entryposition);
}

function cdm_default_dispenser_name($con, $user_id) {
    $user_id = (int)$user_id;
    if ($user_id <= 0) {
        return '';
    }
    $q = mysqli_query($con, "
        SELECT username, full_name, entryposition
        FROM users
        WHERE id = $user_id
        LIMIT 1
    ");
    if (!$q || !($r = mysqli_fetch_assoc($q))) {
        return '';
    }
    $username = trim((string)($r['username'] ?? ''));
    if ($username !== '') {
        $hosxp = cdm_fetch_hosxp_opduser($con, $username);
        if ($hosxp && cdm_is_pharmacist_entryposition($hosxp['entryposition'] ?? '')) {
            return trim((string)($hosxp['name'] ?? ''));
        }
    }
    if (cdm_is_pharmacist_entryposition($r['entryposition'] ?? '')) {
        return trim((string)($r['full_name'] ?? ''));
    }
    return '';
}

function cdm_fetch_hosxp_opduser($con, $loginname) {
    require_once __DIR__ . '/opduser_auth_helper.php';
    require_once __DIR__ . '/hosxp_tmt_drug_helper.php';
    $loginname = trim((string)$loginname);
    if ($loginname === '') {
        return null;
    }
    $hos = hosxp_connect($con, 20);
    if (!$hos) {
        return null;
    }
    $row = tham_opduser_fetch_by_loginname($hos, $loginname);
    mysqli_close($hos);
    if (!$row) {
        return null;
    }
    return [
        'loginname' => trim((string)($row['loginname'] ?? '')),
        'name' => trim((string)($row['name'] ?? '')),
        'entryposition' => trim((string)($row['entryposition'] ?? '')),
    ];
}

function cdm_search_hosxp_opdusers($con, $term = '', $limit = 100, $entryposition_regex = '') {
    require_once __DIR__ . '/opduser_auth_helper.php';
    require_once __DIR__ . '/hosxp_tmt_drug_helper.php';
    $limit = max(1, min(200, (int)$limit));
    $hos = hosxp_connect($con, 30);
    if (!$hos) {
        return [];
    }
    $where_parts = [tham_opduser_sql_active_only()];
    $term = trim((string)$term);
    if ($term !== '') {
        $esc = mysqli_real_escape_string($hos, '%' . $term . '%');
        $where_parts[] = "(loginname LIKE '$esc' OR name LIKE '$esc')";
    }
    $entryposition_regex = trim((string)$entryposition_regex);
    $pharmacist_only = ($entryposition_regex === cdm_pharmacist_entryposition_regex());
    if ($entryposition_regex !== '') {
        $esc = mysqli_real_escape_string($hos, $entryposition_regex);
        $where_parts[] = "IFNULL(entryposition, '') REGEXP '$esc'";
        if ($pharmacist_only) {
            $exclude = mysqli_real_escape_string($hos, 'เภสัชกรรม');
            $where_parts[] = "IFNULL(entryposition, '') NOT LIKE '%$exclude%'";
            $exclude_rp = mysqli_real_escape_string($hos, 'รพ.');
            $where_parts[] = "IFNULL(entryposition, '') NOT LIKE '%$exclude_rp%'";
        }
    }
    $where = implode(' AND ', $where_parts);
    $rows = [];
    $q = mysqli_query($hos, "
        SELECT loginname,
               TRIM(IFNULL(name, '')) AS name,
               TRIM(IFNULL(entryposition, '')) AS entryposition
        FROM opduser
        WHERE $where
        ORDER BY name ASC, loginname ASC
        LIMIT $limit
    ");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            if (trim((string)($r['name'] ?? '')) === '') {
                continue;
            }
            if ($pharmacist_only && !cdm_is_pharmacist_entryposition($r['entryposition'] ?? '')) {
                continue;
            }
            $rows[] = $r;
        }
    }
    mysqli_close($hos);
    return $rows;
}

function cdm_search_users($con, $term = '', $limit = 30, $entryposition_regex = '') {
    $limit = max(1, min(50, (int)$limit));
    $where_parts = ['1=1'];
    $term = trim((string)$term);
    if ($term !== '') {
        $t = mysqli_real_escape_string($con, $term);
        $where_parts[] = "(username LIKE '%$t%' OR full_name LIKE '%$t%')";
    }
    $entryposition_regex = trim((string)$entryposition_regex);
    if ($entryposition_regex !== '') {
        $esc = mysqli_real_escape_string($con, $entryposition_regex);
        $where_parts[] = "IFNULL(entryposition, '') REGEXP '$esc'";
    }
    $where = implode(' AND ', $where_parts);
    $rows = [];
    $q = mysqli_query($con, "
        SELECT id, username, full_name, role, entryposition
        FROM users
        WHERE $where
        ORDER BY full_name ASC, username ASC
        LIMIT $limit
    ");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $rows[] = $r;
        }
    }
    return $rows;
}
