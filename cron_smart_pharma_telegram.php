<?php
/**
 * Cron Smart Pharma — แจ้งเตือน Telegram + บันทึก Intervention Log (ไม่ซ้ำ)
 * CLI:  php cron_smart_pharma_telegram.php
 * HTTP: cron_smart_pharma_telegram.php?key=YOUR_SECRET
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);
set_time_limit(600);
ini_set('memory_limit', '512M');

include(__DIR__ . '/connect.php');
include(__DIR__ . '/smart_pharma_helper.php');
include(__DIR__ . '/smart_pharma_telegram_helper.php');
include(__DIR__ . '/smart_pharma_webhook_helper.php');
include(__DIR__ . '/smart_pharma_alert_log_helper.php');
mysqli_set_charset($con, 'utf8');
sp_ensure_tables($con);

if (php_sapi_name() !== 'cli') {
    $secret = '';
    $q = mysqli_query($con, "SELECT config_value FROM sys_config WHERE config_name='sp_telegram_cron_secret' LIMIT 1");
    if ($q && ($r = mysqli_fetch_assoc($q))) {
        $secret = trim((string)($r['config_value'] ?? ''));
    }
    $key = trim((string)($_GET['key'] ?? ''));
    if ($secret === '' || $key !== $secret) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
}

$hos = hosxp_connect($con);
if (!$hos) {
    $out = ['status' => 'error', 'message' => 'HOSxP connect failed'];
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit(1);
}

$screen_date = sp_today_date();
$time_window = sp_is_screen_date_today($screen_date) ? '3h' : 'full';
$summary = [
    'screen_date' => $screen_date,
    'time_window' => $time_window,
    'alert_log_totals' => ['new' => 0, 'updated' => 0, 'reopened' => 0, 'resolved' => 0, 'skipped' => 0],
    'results' => [],
];

foreach (['OPD', 'IPD'] as $patient_type) {
    $batch = sp_run_screening_list($con, $hos, $patient_type, $screen_date, $time_window);
    $alert_log = $batch['alert_log'] ?? ['new' => 0, 'updated' => 0, 'reopened' => 0, 'resolved' => 0, 'skipped' => 0];
    foreach (['new', 'updated', 'reopened', 'resolved', 'skipped'] as $k) {
        $summary['alert_log_totals'][$k] += (int)($alert_log[$k] ?? 0);
    }
    require_once __DIR__ . '/smart_pharma_notify_helper.php';
    $notify_patients = sp_notify_screening_alert_patients($batch['all_screened'] ?? $batch['patients']);
    $tg = sp_telegram_notify_screening(
        $con,
        $patient_type,
        $screen_date,
        $notify_patients,
        $batch['module_labels']
    );
    $wh = sp_webhook_notify_screening(
        $con,
        $patient_type,
        $screen_date,
        $notify_patients,
        $batch['module_labels']
    );
    $summary['results'][$patient_type] = [
        'patients' => count($batch['patients']),
        'alert_log' => $alert_log,
        'telegram' => $tg,
        'webhook' => $wh,
    ];
}

$tg_repeat = sp_telegram_notify_pending_repeats($con);
$wh_repeat = sp_webhook_notify_pending_repeats($con);
$summary['pending_repeats'] = ['telegram' => $tg_repeat, 'webhook' => $wh_repeat];

mysqli_close($hos);

if (php_sapi_name() === 'cli') {
    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    echo json_encode(['status' => 'success'] + $summary, JSON_UNESCAPED_UNICODE);
}
