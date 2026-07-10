<?php
/**
 * Monitor HLA — helper
 */

function hla_default_alert_level() {
    return 'hard';
}

function hla_alert_level_from_row($row) {
    $level = trim((string)($row['alert_level'] ?? ''));
    if ($level === 'hard' || $level === 'soft') {
        return $level;
    }
    return hla_default_alert_level();
}

function hla_alert_level_badge_html($level) {
    if ($level === 'hard') {
        return '<span class="badge bg-danger">Hard Alert</span>';
    }
    return '<span class="badge bg-warning text-dark">Soft Alert</span>';
}

function hla_resolve_alert_level(array $levels) {
    foreach ($levels as $lvl) {
        if ($lvl === 'hard') {
            return 'hard';
        }
    }
    return 'soft';
}

function ensure_monitor_hla_alert_level_column($con) {
    $chk = mysqli_query($con, "SHOW COLUMNS FROM monitor_hla_settings LIKE 'alert_level'");
    if ($chk && mysqli_num_rows($chk) > 0) {
        return true;
    }
    if (!mysqli_query(
        $con,
        "ALTER TABLE monitor_hla_settings
            ADD COLUMN alert_level VARCHAR(8) NOT NULL DEFAULT 'hard' COMMENT 'hard | soft' AFTER drug_name"
    )) {
        return false;
    }
    mysqli_query(
        $con,
        "UPDATE monitor_hla_settings
         SET alert_level = 'hard'
         WHERE alert_level IS NULL OR TRIM(alert_level) = ''"
    );
    return true;
}

function hla_load_drug_settings($con, array $icode_filter = []) {
    $drugs = [];
    $q = mysqli_query($con, "SELECT icode, drug_name, alert_level FROM monitor_hla_settings WHERE active = 1 ORDER BY drug_name ASC");
    if (!$q) {
        return $drugs;
    }
    while ($r = mysqli_fetch_assoc($q)) {
        $icode = trim($r['icode'] ?? '');
        if ($icode === '') {
            continue;
        }
        if (!empty($icode_filter) && !in_array($icode, $icode_filter, true)) {
            continue;
        }
        $drugs[$icode] = [
            'drug_name' => trim($r['drug_name'] ?? ''),
            'alert_level' => hla_alert_level_from_row($r),
        ];
    }
    return $drugs;
}
