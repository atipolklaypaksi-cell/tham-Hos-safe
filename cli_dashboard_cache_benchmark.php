<?php
/**
 * ทดสอบความเร็ว cache build + อ่านมิติ Lab / Report
 * CLI: php cli_dashboard_cache_benchmark.php [--year=2025] [--force] [--read-only]
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(900);
ini_set('memory_limit', '512M');

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

include(__DIR__ . '/connect.php');
include(__DIR__ . '/hosxp_tmt_drug_helper.php');
include(__DIR__ . '/rational_lab_use_helper.php');
include(__DIR__ . '/income_report_helper.php');
mysqli_set_charset($con, 'utf8');

$year = (int)date('Y');
foreach ($argv as $arg) {
    if (preg_match('/^--year=(\d{4})$/', $arg, $m)) {
        $year = (int)$m[1];
    }
}
$force = in_array('--force', $argv, true);
$read_only = in_array('--read-only', $argv, true);

$start_date = sprintf('%04d-01-01', $year);
$end_date = sprintf('%04d-12-31', $year);
$months = rlu_month_keys_in_period($start_date, $end_date);

echo "=== Dashboard Cache Benchmark ===\n";
echo "Year: $year | Months: " . count($months) . " | Force: " . ($force ? 'yes' : 'no') . " | Read-only: " . ($read_only ? 'yes' : 'no') . "\n\n";

$hos = hosxp_connect($con, 600);
if (!$hos) {
    echo "ERROR: cannot connect HOSxP\n";
    exit(1);
}
hosxp_extend_read_timeout($hos, 600);

function bench_line($label, array $result) {
    $ms = (int)($result['elapsed_ms'] ?? 0);
    $built = !empty($result['built']) ? 'built' : (!empty($result['cached']) ? 'cached' : 'skip');
    $cnt = (int)($result['built_count'] ?? $result['row_count'] ?? $result['item_rows'] ?? $result['doctor_rows'] ?? $result['dept_rows'] ?? 0);
    $err = trim((string)($result['error'] ?? ''));
    echo sprintf("  %-28s %6d ms  %-8s rows~%d%s\n", $label, $ms, $built, $cnt, $err !== '' ? " ERR:$err" : '');
}

if (!$read_only) {
    $scopes_lab = ['usage' => 'Lab หลัก', 'item' => 'Lab ย่อย', 'doctor' => 'Lab แพทย์', 'dept' => 'Lab แผนก'];
    echo "[Lab] Build batch (ทั้งปี)\n";
    foreach ($scopes_lab as $scope => $label) {
        $t0 = microtime(true);
        $r = rlu_build_months_cache_batch($con, $hos, $months, $force, $scope);
        $r['elapsed_ms'] = (int)round((microtime(true) - $t0) * 1000);
        bench_line($label, $r);
    }

    $scopes_irpt = ['item' => 'Report รายการ', 'dept' => 'Report แผนก', 'doctor' => 'Report แพทย์'];
    echo "\n[Report] Build batch (ทั้งปี)\n";
    foreach ($scopes_irpt as $scope => $label) {
        $t0 = microtime(true);
        $r = irpt_build_months_cache_batch($con, $hos, $months, $force, $scope);
        $r['elapsed_ms'] = (int)round((microtime(true) - $t0) * 1000);
        bench_line($label, $r);
    }
}

$periods = [
    ['label' => 'ช่วง A', 'start_date' => sprintf('%04d-01-01', $year), 'end_date' => sprintf('%04d-06-30', $year)],
    ['label' => 'ช่วง B', 'start_date' => sprintf('%04d-07-01', $year), 'end_date' => sprintf('%04d-12-31', $year)],
];

echo "\n[Lab] Read compare (2 ช่วง, 1 query/มิติ)\n";
foreach (['item', 'doctor', 'dept'] as $dim) {
    $t0 = microtime(true);
    $detail = rlu_build_group_dimension_detail($hos, $periods, '__all__', $dim, ['limit' => 300], $con);
    $ms = (int)round((microtime(true) - $t0) * 1000);
    $src = $detail['data_source'] ?? 'hosxp';
    $rows = count($detail['rows'] ?? []);
    $err = trim((string)($detail['error'] ?? ''));
    echo sprintf("  %-28s %6d ms  %-8s rows=%d%s\n", $dim, $ms, $src, $rows, $err !== '' ? " ERR:$err" : '');
}

echo "\n[Report] Read compare (2 ช่วง, 1 query/มิติ)\n";
$income_opts = ['income_group_ids' => [3, 4, 5]];
foreach (['item', 'dept', 'doctor'] as $dim) {
    $t0 = microtime(true);
    $detail = irpt_build_dimension_detail($hos, $periods, $dim, $income_opts, $con);
    $ms = (int)round((microtime(true) - $t0) * 1000);
    $src = $detail['data_source'] ?? 'hosxp';
    $rows = count($detail['rows'] ?? []);
    $err = trim((string)($detail['error'] ?? ''));
    echo sprintf("  %-28s %6d ms  %-8s rows=%d%s\n", $dim, $ms, $src, $rows, $err !== '' ? " ERR:$err" : '');
}

mysqli_close($hos);
echo "\nDone.\n";
