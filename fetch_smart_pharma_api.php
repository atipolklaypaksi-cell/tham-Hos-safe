<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start();
session_start();

include('connect.php');
include('smart_pharma_helper.php');
include('smart_pharma_telegram_helper.php');
include('smart_pharma_webhook_helper.php');
include('smart_pharma_notifications_helper.php');
include('smart_pharma_alert_log_helper.php');
mysqli_set_charset($con, 'utf8');
set_time_limit(600);
ini_set('memory_limit', '512M');

if (!isset($_SESSION['user_id']) && !isset($_SESSION['full_name'])) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

sp_ensure_tables($con);

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

if ($action === 'modules') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $q = mysqli_query($con, "SELECT * FROM smart_pharma_modules ORDER BY sort_order ASC");
    $mods = [];
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $mods[] = $r;
        }
    }
    echo json_encode(['status' => 'success', 'modules' => $mods], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'save_modules' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $raw = json_decode(file_get_contents('php://input'), true);
    if (!empty($raw['modules']) && is_array($raw['modules'])) {
        sp_save_module_settings($con, $raw['modules']);
    }
    echo json_encode(['status' => 'success', 'message' => 'บันทึกการตั้งค่าโมดูลเรียบร้อย'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'save_intervention' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $raw = json_decode(file_get_contents('php://input'), true);
    if (empty($raw['hn']) || empty($raw['module_key'])) {
        echo json_encode(['status' => 'error', 'message' => 'ข้อมูลไม่ครบ'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = sp_save_intervention(
        $con,
        $raw,
        $_SESSION['user_id'] ?? 0,
        $_SESSION['full_name'] ?? ''
    );
    echo json_encode([
        'status' => !empty($result['ok']) ? 'success' : 'error',
        'message' => $result['message'] ?? 'บันทึกไม่สำเร็จ',
        'hard_pending' => !empty($result['hard_pending']),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'update_intervention' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $raw = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($raw['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบรายการ'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = sp_update_intervention(
        $con,
        $id,
        $raw,
        $_SESSION['user_id'] ?? 0,
        $_SESSION['full_name'] ?? ''
    );
    echo json_encode([
        'status' => !empty($result['ok']) ? 'success' : 'error',
        'message' => $result['message'] ?? 'แก้ไขไม่สำเร็จ',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'alert_logs') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $filters = [
        'start_date' => $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days')),
        'end_date' => $_GET['end_date'] ?? date('Y-m-d'),
        'status' => $_GET['status'] ?? '',
        'resolved_by' => $_GET['resolved_by'] ?? '',
        'patient_type' => $_GET['patient_type'] ?? '',
        'limit' => (int)($_GET['limit'] ?? 300),
    ];
    $rows = sp_alert_log_list($con, $filters);
    echo json_encode(['status' => 'success', 'logs' => $rows, 'count' => count($rows)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'alert_log_detail') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_GET['id'] ?? 0);
    $q = mysqli_query($con, "SELECT l.*, m.label_th AS module_label FROM smart_pharma_alert_logs l
        LEFT JOIN smart_pharma_modules m ON m.module_key = l.module_key WHERE l.id=$id LIMIT 1");
    $row = $q ? mysqli_fetch_assoc($q) : null;
    if (!$row) {
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบรายการ'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $row['status_label'] = sp_alert_log_status_label($row);
    $row['interventions'] = sp_alert_log_interventions($con, $id);
    echo json_encode(['status' => 'success', 'log' => $row], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'interventions') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $start = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
    $end = $_GET['end_date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
        $start = date('Y-m-d', strtotime('-30 days'));
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
        $end = date('Y-m-d');
    }
    $rows = sp_list_interventions($con, $start, $end);
    echo json_encode(['status' => 'success', 'interventions' => $rows, 'count' => count($rows)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'telegram_chats') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'success', 'chats' => sp_telegram_list_chats($con)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'telegram_rules') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'success', 'rules' => sp_telegram_list_rules($con)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'telegram_save_config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $raw = json_decode(file_get_contents('php://input'), true) ?: [];
    sp_telegram_save_config(
        $con,
        !empty($raw['enabled']),
        array_key_exists('cron_secret', $raw) ? ($raw['cron_secret'] ?? '') : null,
        array_key_exists('base_url', $raw) ? ($raw['base_url'] ?? '') : null
    );
    echo json_encode(['status' => 'success', 'message' => 'บันทึกการตั้งค่าเรียบร้อย'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'telegram_save_chat' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $raw = json_decode(file_get_contents('php://input'), true) ?: [];
    $ok = sp_telegram_save_chat($con, $raw);
    echo json_encode([
        'status' => $ok ? 'success' : 'error',
        'message' => $ok ? 'บันทึกกลุ่มเรียบร้อย' : 'บันทึกไม่สำเร็จ — ตรวจชื่อกลุ่ม, Bot Token (API) และ Chat ID',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'telegram_delete_chat' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $raw = json_decode(file_get_contents('php://input'), true) ?: [];
    $ok = sp_telegram_delete_chat($con, (int)($raw['id'] ?? 0));
    echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'ลบกลุ่มแล้ว' : 'ลบไม่สำเร็จ'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'telegram_save_rule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $raw = json_decode(file_get_contents('php://input'), true) ?: [];
    $ok = sp_telegram_save_rule($con, $raw);
    echo json_encode([
        'status' => $ok ? 'success' : 'error',
        'message' => $ok ? 'บันทึกกฎเรียบร้อย' : 'บันทึกไม่สำเร็จ — เลือก Hard หรือ Soft อย่างน้อยหนึ่งอย่าง',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'telegram_delete_rule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $raw = json_decode(file_get_contents('php://input'), true) ?: [];
    $ok = sp_telegram_delete_rule($con, (int)($raw['id'] ?? 0));
    echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'ลบกฎแล้ว' : 'ลบไม่สำเร็จ'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'webhook_endpoints') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'success', 'endpoints' => sp_webhook_list_endpoints($con)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'webhook_rules') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'success', 'rules' => sp_webhook_list_rules($con)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'webhook_save_config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $raw = json_decode(file_get_contents('php://input'), true) ?: [];
    sp_webhook_save_config(
        $con,
        !empty($raw['enabled']),
        array_key_exists('base_url', $raw) ? ($raw['base_url'] ?? '') : null
    );
    echo json_encode(['status' => 'success', 'message' => 'บันทึกการตั้งค่าเรียบร้อย'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'webhook_save_endpoint' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $raw = json_decode(file_get_contents('php://input'), true) ?: [];
    $ok = sp_webhook_save_endpoint($con, $raw);
    echo json_encode([
        'status' => $ok ? 'success' : 'error',
        'message' => $ok ? 'บันทึก Endpoint เรียบร้อย' : 'บันทึกไม่สำเร็จ — ตรวจชื่อและ Webhook URL (ต้องขึ้นต้นด้วย http:// หรือ https://)',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'webhook_delete_endpoint' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $raw = json_decode(file_get_contents('php://input'), true) ?: [];
    $ok = sp_webhook_delete_endpoint($con, (int)($raw['id'] ?? 0));
    echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'ลบ Endpoint แล้ว' : 'ลบไม่สำเร็จ'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'webhook_save_rule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $raw = json_decode(file_get_contents('php://input'), true) ?: [];
    $ok = sp_webhook_save_rule($con, $raw);
    echo json_encode([
        'status' => $ok ? 'success' : 'error',
        'message' => $ok ? 'บันทึกกฎเรียบร้อย' : 'บันทึกไม่สำเร็จ — เลือก Hard หรือ Soft อย่างน้อยหนึ่งอย่าง',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'webhook_delete_rule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $raw = json_decode(file_get_contents('php://input'), true) ?: [];
    $ok = sp_webhook_delete_rule($con, (int)($raw['id'] ?? 0));
    echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'ลบกฎแล้ว' : 'ลบไม่สำเร็จ'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'webhook_test' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $raw = json_decode(file_get_contents('php://input'), true) ?: [];
    $endpoint_id = (int)($raw['endpoint_id'] ?? 0);
    $endpoints = sp_webhook_list_endpoints($con);
    $endpoint_row = null;
    foreach ($endpoints as $e) {
        if ((int)$e['id'] === $endpoint_id) {
            $endpoint_row = $e;
            break;
        }
    }
    if (!$endpoint_row || trim((string)($endpoint_row['webhook_url'] ?? '')) === '') {
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบ Endpoint'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $mode = (($raw['mode'] ?? '') === 'sample') ? 'sample' : 'simple';
    $res = sp_webhook_send_test($con, $endpoint_row, $mode);
    $msg = $res['error'] ?? 'ส่งไม่สำเร็จ';
    if (!empty($res['ok'])) {
        if ($mode === 'sample') {
            $n = (int)($res['sent'] ?? 1);
            $t = (int)($res['total'] ?? $n);
            $msg = 'ส่งตัวอย่าง Alert จริง ' . $n . ' รายการแล้ว — payload มี alert_log_id และลิงก์ Intervention Log';
            if ($n < $t) {
                $msg .= ' (ส่งได้ ' . $n . '/' . $t . ')';
            }
        } else {
            $msg = 'ส่ง Ping ทดสอบแล้ว — ตรวจที่ปลายทาง Webhook';
        }
    }
    echo json_encode([
        'status' => !empty($res['ok']) ? 'success' : 'error',
        'message' => $msg,
        'sent' => (int)($res['sent'] ?? 0),
        'total' => (int)($res['total'] ?? 0),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'notification_config') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'success', 'config' => sp_notifications_get_config($con)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'notification_save_config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $raw = json_decode(file_get_contents('php://input'), true) ?: [];
    sp_notifications_save_config($con, $raw);
    echo json_encode(['status' => 'success', 'message' => 'บันทึกการตั้งค่าเรียบร้อย'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'notification_unified_rules') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'success', 'rules' => sp_notifications_list_unified_rules($con)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'notification_save_unified_rule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $raw = json_decode(file_get_contents('php://input'), true) ?: [];
    $result = sp_notifications_save_unified_rule($con, $raw);
    if (is_array($result) && !empty($result['conflicts'])) {
        echo json_encode([
            'status' => 'confirm',
            'message' => 'มีกฎเดิมที่ชนกัน — บันทึกจะแทนที่การตั้งค่าเดิม',
            'conflicts' => $result['conflicts'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $ok = (bool)$result;
    echo json_encode([
        'status' => $ok ? 'success' : 'error',
        'message' => $ok ? 'บันทึกกฎเรียบร้อย' : 'บันทึกไม่สำเร็จ — เลือกปลายทาง, โมดูล, OPD/IPD และ Hard/Soft ให้ครบ',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'screen_hn') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $raw = ($_SERVER['REQUEST_METHOD'] === 'POST')
        ? (json_decode(file_get_contents('php://input'), true) ?: [])
        : $_GET;
    $patient_type = ($raw['patient_type'] ?? 'OPD') === 'IPD' ? 'IPD' : 'OPD';
    $screen_date = trim((string)($raw['screen_date'] ?? $raw['vstdate'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $screen_date)) {
        $screen_date = date('Y-m-d');
    }
    $hn = trim((string)($raw['hn'] ?? ''));
    $vn = trim((string)($raw['vn'] ?? ''));
    $an = trim((string)($raw['an'] ?? ''));
    $notify = !isset($raw['notify']) || $raw['notify'] === true || $raw['notify'] === '1' || $raw['notify'] === 1;

    $hos = hosxp_connect($con);
    if (!$hos) {
        echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = sp_run_screening_hn($con, $hos, $patient_type, $screen_date, $hn, $vn, $an, ['notify' => $notify]);
    mysqli_close($hos);
    echo json_encode([
        'status' => !empty($result['ok']) ? 'success' : 'error',
        'message' => $result['message'] ?? '',
    ] + $result, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'screen_api_config') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'success',
        'api_key_set' => sp_screen_api_get_key($con) !== '',
        'api_url' => (isset($_SERVER['HTTP_HOST']) ? (((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''))) : '') . '/fetch_smart_pharma_screen_hn_api.php',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'screen_api_save_key' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $raw = json_decode(file_get_contents('php://input'), true) ?: [];
    sp_screen_api_save_key($con, $raw['api_key'] ?? '');
    echo json_encode(['status' => 'success', 'message' => 'บันทึก API Key แล้ว'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'notification_delete_unified_rule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $raw = json_decode(file_get_contents('php://input'), true) ?: [];
    $key = trim((string)($raw['policy_key'] ?? ''));
    if ($key === '') {
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบกฎ'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    sp_notifications_delete_rules_by_policy_keys($con, explode('||', $key));
    echo json_encode(['status' => 'success', 'message' => 'ลบกฎแล้ว'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'telegram_test' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $raw = json_decode(file_get_contents('php://input'), true) ?: [];
    $chat_row_id = (int)($raw['chat_row_id'] ?? 0);
    $chats = sp_telegram_list_chats($con);
    $chat_row = null;
    foreach ($chats as $c) {
        if ((int)$c['id'] === $chat_row_id) {
            $chat_row = $c;
            break;
        }
    }
    if (!$chat_row || trim((string)($chat_row['chat_id'] ?? '')) === '') {
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบกลุ่ม'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $mode = (($raw['mode'] ?? '') === 'sample') ? 'sample' : 'simple';
    $res = sp_telegram_send_test($con, $chat_row['chat_id'], $chat_row, $mode);
    $msg = $res['error'] ?? 'ส่งไม่สำเร็จ';
    if (!empty($res['ok'])) {
        if ($mode === 'sample') {
            $n = (int)($res['sent'] ?? 1);
            $t = (int)($res['total'] ?? $n);
            $msg = 'ส่งตัวอย่าง Alert จริง ' . $n . ' รายการแล้ว — แต่ละรายการมีลิงก์เปิด Intervention Log ของผู้ป่วยนั้น';
            if ($n < $t) {
                $msg .= ' (ส่งได้ ' . $n . '/' . $t . ')';
            }
        } else {
            $msg = 'ส่งข้อความทดสอบแล้ว — ตรวจใน Telegram';
        }
    }
    echo json_encode([
        'status' => !empty($res['ok']) ? 'success' : 'error',
        'message' => $msg,
        'sent' => (int)($res['sent'] ?? 0),
        'total' => (int)($res['total'] ?? 0),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action !== 'list') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Unknown action'], JSON_UNESCAPED_UNICODE);
    exit;
}

// action=list
$patient_type = ($_GET['patient_type'] ?? 'OPD') === 'IPD' ? 'IPD' : 'OPD';
$screen_date = trim($_GET['screen_date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $screen_date)) {
    $screen_date = date('Y-m-d');
}
$time_window = sp_normalize_time_window($_GET['time_window'] ?? '', $screen_date);
$time_from = trim($_GET['time_from'] ?? '');
$time_to = trim($_GET['time_to'] ?? '');
if ($time_window === 'custom' && sp_normalize_custom_time_range($time_from, $time_to) === null) {
    $time_window = sp_is_screen_date_today($screen_date) ? '1h' : 'full';
    $time_from = '';
    $time_to = '';
}

$hos = hosxp_connect($con);
if (!$hos) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อ HOSxP ได้'], JSON_UNESCAPED_UNICODE);
    exit;
}

$modules = sp_get_modules($con, $patient_type);
if (empty($modules)) {
    mysqli_close($hos);
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'success',
        'patients' => [],
        'modules' => [],
        'meta' => ['message' => 'ยังไม่ได้เปิดโมดูลคัดกรอง — ไปที่แท็บตั้งค่า'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$t0 = microtime(true);
$timings = [];
$cache_ttl = sp_cache_ttl($screen_date);
$cache_patients_key = 'pd_' . $screen_date . '_' . $patient_type;
$patients_with_drugs = sp_cache_get($cache_patients_key, $cache_ttl);
if ($patients_with_drugs === null) {
    $t_fetch = microtime(true);
    if ($patient_type === 'OPD') {
        $patients_with_drugs = sp_fetch_opd_patients_with_drugs($hos, $screen_date);
    } else {
        $patients_all = sp_fetch_ipd_patients($hos, $screen_date);
        $patients_with_drugs = sp_filter_patients_with_drug_orders(
            sp_enrich_patients_rx_time($hos, $patients_all, $screen_date)
        );
    }
    sp_cache_set($cache_patients_key, $patients_with_drugs);
    $timings['fetch_ms'] = (int)round((microtime(true) - $t_fetch) * 1000);
} else {
    $timings['fetch_ms'] = 0;
    $timings['fetch_cached'] = true;
}

$has_di_module = false;
foreach ($modules as $mod) {
    if (($mod['module_key'] ?? '') === 'di') {
        $has_di_module = true;
        break;
    }
}

$di_batch = null;
if ($has_di_module) {
    $cache_di_key = 'di_' . $screen_date;
    $di_batch = sp_cache_get($cache_di_key, $cache_ttl);
    if ($di_batch === null) {
        $t_di = microtime(true);
        $hn_filter = sp_extract_hn_list($patients_with_drugs);
        $di_batch = sp_batch_screen_di_for_date($con, $hos, $screen_date, $hn_filter);
        sp_cache_set($cache_di_key, $di_batch);
        $timings['di_ms'] = (int)round((microtime(true) - $t_di) * 1000);
    } else {
        $timings['di_ms'] = 0;
        $timings['di_cached'] = true;
    }
}

$cache_ctx_key = 'ctx_' . $screen_date . '_' . $patient_type;
$screen_ctx = sp_cache_get($cache_ctx_key, $cache_ttl);
if ($screen_ctx === null) {
    $t_ctx = microtime(true);
    $screen_ctx = sp_build_screen_cache($con, $hos, $patients_with_drugs, $screen_date, $modules);
    sp_cache_set($cache_ctx_key, $screen_ctx);
    $timings['screen_cache_ms'] = (int)round((microtime(true) - $t_ctx) * 1000);
} else {
    $timings['screen_cache_ms'] = 0;
    $timings['screen_cache_cached'] = true;
}

$all_screened = [];
$t_screen = microtime(true);
foreach ($patients_with_drugs as $pat) {
    $screen = sp_screen_patient($con, $hos, $pat, $modules, $di_batch, $screen_date, $screen_ctx);
    $all_screened[] = sp_build_screened_patient_row($pat, $screen, $patient_type, $screen_date, $modules);
}

$t_log = microtime(true);
$alert_log_stats = sp_alert_log_sync($con, $patient_type, $screen_date, $all_screened);
$timings['alert_log_ms'] = (int)round((microtime(true) - $t_log) * 1000);

$out = sp_filter_screened_rows_by_time($all_screened, $screen_date, $time_window, $time_from, $time_to);

usort($out, function ($a, $b) {
    $a_alert = ($a['overall'] ?? '') === 'alert' ? 0 : 1;
    $b_alert = ($b['overall'] ?? '') === 'alert' ? 0 : 1;
    if ($a_alert !== $b_alert) {
        return $a_alert <=> $b_alert;
    }
    $a_level = ($a['overall_level'] ?? '') === 'hard' ? 0 : 1;
    $b_level = ($b['overall_level'] ?? '') === 'hard' ? 0 : 1;
    if ($a_alert === 0 && $a_level !== $b_level) {
        return $a_level <=> $b_level;
    }
    $a_dt = sp_patient_order_timestamp([
        'rxdate' => $a['rxdate'] ?? '', 'rxtime' => $a['rxtime'] ?? '',
        'vstdate' => $a['vstdate'] ?? '', 'vsttime' => $a['vsttime'] ?? '',
    ]) ?: 0;
    $b_dt = sp_patient_order_timestamp([
        'rxdate' => $b['rxdate'] ?? '', 'rxtime' => $b['rxtime'] ?? '',
        'vstdate' => $b['vstdate'] ?? '', 'vsttime' => $b['vsttime'] ?? '',
    ]) ?: 0;
    if ($a_dt !== $b_dt) {
        return $b_dt <=> $a_dt;
    }
    return strcmp($a['hn'] ?? '', $b['hn'] ?? '');
});

$timings['screen_loop_ms'] = (int)round((microtime(true) - $t_screen) * 1000);

$module_labels = [];
foreach ($modules as $mod) {
    $module_labels[$mod['module_key']] = $mod['label_th'];
}
$t_tg = microtime(true);
require_once __DIR__ . '/smart_pharma_notify_helper.php';
$notify_patients = sp_notify_screening_alert_patients($all_screened);
$telegram_result = sp_telegram_notify_screening($con, $patient_type, $screen_date, $notify_patients, $module_labels);
$timings['telegram_ms'] = (int)round((microtime(true) - $t_tg) * 1000);

$t_wh = microtime(true);
$webhook_result = sp_webhook_notify_screening($con, $patient_type, $screen_date, $notify_patients, $module_labels);
$timings['webhook_ms'] = (int)round((microtime(true) - $t_wh) * 1000);

$timings['notify_repeat_ms'] = 0;

$elapsed = (int)round((microtime(true) - $t0) * 1000);
$timings['total_ms'] = $elapsed;
mysqli_close($hos);

$module_cols = array_map(function ($m) {
    return [
        'key' => $m['module_key'],
        'label' => $m['label_th'],
        'icon' => $m['icon'],
        'color' => $m['color'],
    ];
}, $modules);

ob_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'status' => 'success',
    'patients' => $out,
    'modules' => $module_cols,
    'meta' => [
        'patient_type' => $patient_type,
        'screen_date' => $screen_date,
        'count' => count($out),
        'total_patients' => count($patients_with_drugs),
        'time_window' => $time_window,
        'time_window_label' => sp_time_window_label($time_window, $time_from, $time_to),
        'time_from' => $time_from,
        'time_to' => $time_to,
        'is_today' => sp_is_screen_date_today($screen_date),
        'alert_count' => count(array_filter($out, function ($p) {
            return ($p['overall'] ?? '') === 'alert';
        })),
        'di_alert_count' => is_array($di_batch) ? count($di_batch['results'] ?? []) : 0,
        'elapsed_ms' => $elapsed,
        'timings' => $timings,
        'telegram' => $telegram_result,
        'webhook' => $webhook_result,
        'alert_log' => $alert_log_stats,
    ],
], JSON_UNESCAPED_UNICODE);
