<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start();
session_start();

include(__DIR__ . '/connect.php');
include(__DIR__ . '/hosxp_tmt_drug_helper.php');
require_once __DIR__ . '/controlled_drug_helper.php';
mysqli_set_charset($con, 'utf8');
cdm_ensure_tables($con);

function cdm_api_json(array $payload) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    cdm_api_json(['status' => 'error', 'message' => 'Unauthorized']);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_GET;
}

$action = trim((string)($input['action'] ?? 'list'));
$drug_type = cdm_normalize_type($input['drug_type'] ?? '');
$user_id = (int)($_SESSION['user_id'] ?? 0);

if ($drug_type === '' && $action !== 'ping') {
    cdm_api_json(['status' => 'error', 'message' => 'ไม่พบประเภทยา']);
}

if ($action === 'list') {
    $status_filter = trim((string)($input['status_filter'] ?? ''));
    $room_id = (int)($input['room_id'] ?? 0);
    $data = cdm_fetch_stock_summary($con, $drug_type, $status_filter, [
        'usage_months' => (int)($input['usage_months'] ?? 2),
        'min_reserve_months' => (int)($input['min_reserve_months'] ?? 1),
        'max_reserve_months' => (int)($input['max_reserve_months'] ?? 3),
        'room_id' => $room_id,
    ]);
    cdm_api_json([
        'status' => 'success',
        'drug_type' => $drug_type,
        'type_label' => cdm_type_label($drug_type),
        'items' => $data['items'],
        'summary' => $data['summary'],
        'rooms' => cdm_fetch_rooms($con, $drug_type, true),
        'room_id' => $room_id,
        'usage_months' => $data['usage_months'] ?? 2,
        'min_reserve_months' => $data['min_reserve_months'] ?? 1,
        'max_reserve_months' => $data['max_reserve_months'] ?? 3,
        'status_options' => array_map(function ($s) {
            return ['value' => $s, 'label' => cdm_stock_status_label($s)];
        }, cdm_stock_statuses()),
    ]);
}

if ($action === 'rooms_list') {
    cdm_api_json([
        'status' => 'success',
        'rooms' => cdm_fetch_rooms($con, $drug_type, false),
    ]);
}

if ($action === 'room_save') {
    cdm_api_json(cdm_save_room($con, [
        'drug_type' => $drug_type,
        'id' => (int)($input['id'] ?? 0),
        'room_code' => $input['room_code'] ?? '',
        'room_name' => $input['room_name'] ?? '',
        'service_mode' => $input['service_mode'] ?? 'all',
        'sort_order' => (int)($input['sort_order'] ?? 0),
        'is_active' => !isset($input['is_active']) || !empty($input['is_active']),
        'note' => $input['note'] ?? '',
    ]));
}

if ($action === 'room_delete') {
    cdm_api_json(cdm_delete_room($con, $drug_type, (int)($input['id'] ?? 0)));
}

if ($action === 'room_copy_from') {
    $source_type = cdm_normalize_type($input['source_type'] ?? '');
    cdm_api_json(cdm_copy_rooms_from_type($con, $drug_type, $source_type));
}

if ($action === 'moves') {
    cdm_api_json([
        'status' => 'success',
        'rows' => cdm_fetch_recent_moves($con, $drug_type, (int)($input['limit'] ?? 50)),
    ]);
}

if ($action === 'sync_logs') {
    cdm_api_json([
        'status' => 'success',
        'rows' => cdm_fetch_sync_logs($con, $drug_type, (int)($input['limit'] ?? 20)),
    ]);
}

if ($action === 'settings_list') {
    cdm_api_json([
        'status' => 'success',
        'items' => cdm_fetch_items($con, $drug_type, false),
        'status_options' => array_map(function ($s) {
            return ['value' => $s, 'label' => cdm_stock_status_label($s)];
        }, cdm_stock_statuses()),
    ]);
}

if ($action === 'save_item') {
    $icode_raw = trim((string)($input['icode'] ?? ''));
    if (strpos($icode_raw, '|') !== false) {
        $parts = explode('|', $icode_raw, 2);
        $icode_raw = trim($parts[0]);
    }
    $result = cdm_save_item($con, [
        'drug_type' => $drug_type,
        'icode' => $icode_raw,
        'drug_name' => trim((string)($input['drug_name'] ?? '')),
        'unit' => trim((string)($input['unit'] ?? '')),
        'opening_qty' => $input['opening_qty'] ?? 0,
        'sort_order' => (int)($input['sort_order'] ?? 0),
        'is_active' => !isset($input['is_active']) || !empty($input['is_active']),
        'stock_status' => $input['stock_status'] ?? 'none',
        'stock_status_note' => $input['stock_status_note'] ?? '',
        'room_id' => (int)($input['room_id'] ?? 0),
        'user_id' => $user_id,
    ]);
    if ($result['status'] === 'success' && trim((string)($input['drug_name'] ?? '')) === '') {
        $hos = hosxp_connect($con);
        if ($hos) {
            $meta = cdm_lookup_hosxp_drug_meta($hos, $icode_raw);
            mysqli_close($hos);
            if ($meta['drug_name'] !== '') {
                cdm_save_item($con, [
                    'drug_type' => $drug_type,
                    'icode' => $icode_raw,
                    'drug_name' => $meta['drug_name'],
                    'unit' => $meta['unit'],
                    'is_active' => 1,
                ]);
            }
        }
    }
    cdm_api_json($result);
}

if ($action === 'delete_item') {
    cdm_api_json(cdm_delete_item($con, $drug_type, (int)($input['id'] ?? 0)));
}

if ($action === 'update_status') {
    cdm_api_json(cdm_update_stock_status(
        $con,
        $drug_type,
        (int)($input['id'] ?? 0),
        $input['stock_status'] ?? '',
        $input['stock_status_note'] ?? ''
    ));
}

if ($action === 'adjust_stock') {
    cdm_api_json(cdm_adjust_stock(
        $con,
        $drug_type,
        (int)($input['id'] ?? 0),
        $input['new_qty'] ?? '',
        trim((string)($input['note'] ?? '')),
        $user_id,
        (int)($input['room_id'] ?? 0)
    ));
}

if ($action === 'save_lot') {
    $item = cdm_fetch_item_by_icode($con, $drug_type, trim((string)($input['icode'] ?? '')));
    if (!$item) {
        cdm_api_json(['status' => 'error', 'message' => 'ไม่พบรายการยา']);
    }
    cdm_api_json(cdm_save_lot($con, [
        'drug_type' => $drug_type,
        'icode' => $item['icode'],
        'id' => (int)($input['id'] ?? 0),
        'room_id' => (int)($input['room_id'] ?? 0),
        'lot_no' => $input['lot_no'] ?? '',
        'exp_date' => $input['exp_date'] ?? '',
        'qty' => $input['qty'] ?? 0,
        'note' => $input['note'] ?? '',
    ]));
}

if ($action === 'delete_lot') {
    cdm_api_json(cdm_delete_lot($con, $drug_type, (int)($input['id'] ?? 0)));
}

if ($action === 'update_minmax') {
    cdm_api_json(cdm_update_item_minmax(
        $con,
        $drug_type,
        (int)($input['id'] ?? 0),
        $input['min_stock'] ?? null,
        $input['max_stock'] ?? null
    ));
}

if ($action === 'apply_minmax_all') {
    cdm_api_json(cdm_apply_all_minmax_suggestions(
        $con,
        $drug_type,
        (int)($input['usage_months'] ?? 2),
        (int)($input['min_reserve_months'] ?? 1),
        (int)($input['max_reserve_months'] ?? 3)
    ));
}

if ($action === 'list_lots') {
    $icode = trim((string)($input['icode'] ?? ''));
    cdm_api_json([
        'status' => 'success',
        'lots' => cdm_fetch_lots($con, $drug_type, $icode, false),
    ]);
}

if ($action === 'hosxp_preview') {
    $start_date = trim((string)($input['start_date'] ?? ''));
    $end_date = trim((string)($input['end_date'] ?? ''));
    $icodes = cdm_fetch_active_icodes($con, $drug_type);
    $hos = hosxp_connect($con, 300);
    if (!$hos) {
        cdm_api_json(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้']);
    }
    hosxp_extend_read_timeout($hos, 300);
    $usage = cdm_hosxp_fetch_usage_summary($hos, $icodes, $start_date, $end_date);
    mysqli_close($hos);
    if (!empty($usage['error'])) {
        cdm_api_json(['status' => 'error', 'message' => $usage['error']]);
    }

    $item_map = [];
    foreach (cdm_fetch_items($con, $drug_type, true) as $item) {
        $item_map[$item['icode']] = $item;
    }
    $rows = [];
    foreach ($usage['rows'] as $row) {
        $icode = $row['icode'];
        $current = isset($item_map[$icode]) ? (float)$item_map[$icode]['current_qty'] : null;
        $rows[] = array_merge($row, [
            'current_qty' => $current,
            'after_qty' => $current !== null ? ($current - (float)$row['total_qty']) : null,
            'configured' => isset($item_map[$icode]),
        ]);
    }

    cdm_api_json([
        'status' => 'success',
        'start_date' => $start_date,
        'end_date' => $end_date,
        'rows' => $rows,
        'total_rows' => count($rows),
        'total_qty' => array_sum(array_column($rows, 'total_qty')),
    ]);
}

if ($action === 'hosxp_apply') {
    $start_date = trim((string)($input['start_date'] ?? ''));
    $end_date = trim((string)($input['end_date'] ?? ''));
    $icodes = cdm_fetch_active_icodes($con, $drug_type);
    $hos = hosxp_connect($con, 300);
    if (!$hos) {
        cdm_api_json(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้']);
    }
    hosxp_extend_read_timeout($hos, 300);
    $usage = cdm_hosxp_fetch_usage_summary($hos, $icodes, $start_date, $end_date);
    mysqli_close($hos);
    if (!empty($usage['error'])) {
        cdm_api_json(['status' => 'error', 'message' => $usage['error']]);
    }
    cdm_api_json(cdm_apply_hosxp_usage_cut($con, $drug_type, $start_date, $end_date, $usage['rows'], $user_id, (int)($input['room_id'] ?? 0)));
}

if ($action === 'dispense_units') {
    $service_date = trim((string)($input['service_date'] ?? date('Y-m-d')));
    $service_mode = trim((string)($input['service_mode'] ?? 'ipd'));
    $hos = hosxp_connect($con, 180);
    if (!$hos) {
        cdm_api_json(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้']);
    }
    hosxp_extend_read_timeout($hos, 180);
    $result = cdm_hosxp_fetch_units_summary($hos, $con, $drug_type, $service_date, $service_mode);
    mysqli_close($hos);
    if (!empty($result['error'])) {
        cdm_api_json(['status' => 'error', 'message' => $result['error']]);
    }
    cdm_api_json([
        'status' => 'success',
        'service_date' => $service_date,
        'service_mode' => $service_mode,
        'mode_label' => cdm_service_mode_label($service_mode),
        'form_label' => cdm_form_type_label($drug_type),
        'rows' => $result['rows'],
    ]);
}

if ($action === 'dispense_orders') {
    $start_date = trim((string)($input['start_date'] ?? ''));
    $end_date = trim((string)($input['end_date'] ?? ''));
    if ($start_date === '' && $end_date === '' && !empty($input['service_date'])) {
        $legacy = trim((string)$input['service_date']);
        $start_date = $legacy;
        $end_date = $legacy;
    }
    $service_mode = trim((string)($input['service_mode'] ?? 'all'));
    $unit_filter_key = trim((string)($input['unit_filter_key'] ?? $input['unit_code'] ?? ''));
    $range = cdm_parse_date_range($start_date, $end_date, 7, 31);
    if (!empty($range['error'])) {
        cdm_api_json(['status' => 'error', 'message' => $range['error']]);
    }
    if (empty(cdm_fetch_active_icodes($con, $drug_type))) {
        cdm_api_json(['status' => 'error', 'message' => 'ยังไม่มีรายการยาที่ตั้งค่าไว้']);
    }
    $hos = hosxp_connect($con, 120);
    if (!$hos) {
        cdm_api_json(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้']);
    }
    hosxp_extend_read_timeout($hos, 120);
    $result = cdm_hosxp_fetch_dispense_orders($hos, $con, $drug_type, $start_date, $end_date, $service_mode, $unit_filter_key);
    mysqli_close($hos);
    if (!empty($result['error'])) {
        cdm_api_json(['status' => 'error', 'message' => $result['error']]);
    }
    $rows = $result['rows'] ?? [];
    $start_date = $result['start_date'] ?? $start_date;
    $end_date = $result['end_date'] ?? $end_date;
    cdm_api_json([
        'status' => 'success',
        'start_date' => $start_date,
        'end_date' => $end_date,
        'service_mode' => $service_mode,
        'unit_filter_key' => $unit_filter_key,
        'form_label' => cdm_form_type_label($drug_type),
        'mode_label' => cdm_service_mode_label($service_mode),
        'unit_label' => $service_mode === 'opd' ? 'หน่วยให้บริการ' : ($service_mode === 'all' ? 'หน่วย/หอผู้ป่วย' : 'หอผู้ป่วย'),
        'default_dispenser' => cdm_default_dispenser_name($con, (int)($_SESSION['user_id'] ?? 0)),
        'units' => cdm_extract_unit_options($rows, $service_mode),
        'rooms' => cdm_fetch_rooms($con, $drug_type, true),
        'rows' => $rows,
        'total_rows' => count($rows),
    ]);
}

if ($action === 'dispense_register') {
    $start_date = trim((string)($input['start_date'] ?? ''));
    $end_date = trim((string)($input['end_date'] ?? ''));
    $service_mode = trim((string)($input['service_mode'] ?? 'all'));
    $unit_filter_key = trim((string)($input['unit_filter_key'] ?? $input['unit_code'] ?? ''));
    $result = cdm_fetch_dispense_register($con, $drug_type, $start_date, $end_date, $service_mode, $unit_filter_key);
    if (!empty($result['error'])) {
        cdm_api_json(['status' => 'error', 'message' => $result['error']]);
    }
    $rows = $result['rows'] ?? [];
    cdm_api_json([
        'status' => 'success',
        'start_date' => $result['start_date'] ?? $start_date,
        'end_date' => $result['end_date'] ?? $end_date,
        'service_mode' => $service_mode,
        'form_label' => cdm_form_type_label($drug_type),
        'mode_label' => cdm_service_mode_label($service_mode),
        'units' => cdm_extract_unit_options($rows, $service_mode),
        'rooms' => cdm_fetch_rooms($con, $drug_type, true),
        'rows' => $rows,
        'total_rows' => count($rows),
    ]);
}

if ($action === 'dispense_save') {
    $payload = is_array($input['record'] ?? null) ? $input['record'] : $input;
    $payload['drug_type'] = $drug_type;
    $payload['user_id'] = $user_id;
    cdm_api_json(cdm_save_dispense($con, $payload));
}

if ($action === 'dispense_detail') {
    $dispense_id = (int)($input['id'] ?? $input['dispense_id'] ?? 0);
    $row = cdm_fetch_dispense_detail($con, $drug_type, $dispense_id);
    if (!$row) {
        cdm_api_json(['status' => 'error', 'message' => 'ไม่พบรายการ']);
    }
    cdm_api_json([
        'status' => 'success',
        'id' => (int)$row['id'],
        'hosxp_guid' => $row['hosxp_guid'] ?? '',
        'receiver_signature' => $row['receiver_signature'] ?? '',
        'dispenser_signature' => $row['dispenser_signature'] ?? '',
    ]);
}

cdm_api_json(['status' => 'error', 'message' => 'ไม่รู้จัก action: ' . $action]);
