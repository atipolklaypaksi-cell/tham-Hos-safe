<?php
/**
 * สร้าง cache รายเดือน Lab + Report ล่วงหน้า (CLI / HTTP + secret)
 * CLI:  php cron_dashboard_cache.php [--year=2025] [--lab] [--report] [--scope=item,doctor,dept]
 * HTTP: cron_dashboard_cache.php?key=SECRET&year=2025
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);
set_time_limit(0);
ini_set('memory_limit', '512M');

include(__DIR__ . '/connect.php');
include(__DIR__ . '/hosxp_tmt_drug_helper.php');
include(__DIR__ . '/rational_lab_use_helper.php');
include(__DIR__ . '/income_report_helper.php');
mysqli_set_charset($con, 'utf8');

if (php_sapi_name() !== 'cli') {
    $secret = '';
    $q = mysqli_query($con, "SELECT config_value FROM sys_config WHERE config_name='dashboard_cache_cron_secret' LIMIT 1");
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

$year = (int)date('Y');
$do_lab = true;
$do_report = true;
$scopes = ['item', 'doctor', 'dept'];
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--year=(\d{4})$/', $arg, $m)) {
        $year = (int)$m[1];
    } elseif ($arg === '--lab') {
        $do_report = false;
    } elseif ($arg === '--report') {
        $do_lab = false;
    } elseif (preg_match('/^--scope=(.+)$/', $arg, $m)) {
        $scopes = array_values(array_filter(array_map('trim', explode(',', $m[1]))));
    }
}
if (php_sapi_name() !== 'cli') {
    if (isset($_GET['year'])) {
        $year = (int)$_GET['year'];
    }
    if (isset($_GET['lab']) && $_GET['lab'] === '0') {
        $do_lab = false;
    }
    if (isset($_GET['report']) && $_GET['report'] === '0') {
        $do_report = false;
    }
}

$start_date = sprintf('%04d-01-01', $year);
$end_date = sprintf('%04d-12-31', $year);
$months = rlu_month_keys_in_period($start_date, $end_date);

$hos = hosxp_connect($con, 900);
if (!$hos) {
    $out = ['status' => 'error', 'message' => 'HOSxP connect failed'];
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}
hosxp_extend_read_timeout($hos, 900);

$summary = ['status' => 'success', 'year' => $year, 'months' => count($months), 'lab' => [], 'report' => []];

if ($do_lab) {
    $lab_scopes = array_values(array_intersect(['usage', 'item', 'doctor', 'dept'], $scopes));
    if (empty($lab_scopes)) {
        $lab_scopes = ['item', 'doctor', 'dept'];
    }
    foreach ($lab_scopes as $scope) {
        $t0 = microtime(true);
        $r = rlu_build_months_cache_batch($con, $hos, $months, false, $scope);
        $r['elapsed_ms'] = (int)round((microtime(true) - $t0) * 1000);
        $summary['lab'][$scope] = $r;
        if (!empty($r['error'])) {
            $summary['status'] = 'partial';
        }
    }
}

if ($do_report) {
    $report_scopes = array_intersect(['item', 'dept', 'doctor'], $scopes);
    if (empty($report_scopes)) {
        $report_scopes = ['item', 'dept', 'doctor'];
    }
    foreach ($report_scopes as $scope) {
        $t0 = microtime(true);
        $r = irpt_build_months_cache_batch($con, $hos, $months, false, $scope);
        $r['elapsed_ms'] = (int)round((microtime(true) - $t0) * 1000);
        $summary['report'][$scope] = $r;
        if (!empty($r['error'])) {
            $summary['status'] = 'partial';
        }
    }
}

mysqli_close($hos);

if (php_sapi_name() === 'cli') {
    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    echo json_encode($summary, JSON_UNESCAPED_UNICODE);
}
