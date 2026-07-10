<?php
/**
 * ทะเบียนจ่ายยา — ตั้ง $cdm_type = 'narcotic' | 'psychotropic' ก่อน include
 */
if (!isset($cdm_type) || cdm_normalize_type($cdm_type) === '') {
    die('Invalid drug type');
}
$cdm_type = cdm_normalize_type($cdm_type);
$cdm_label = cdm_type_label($cdm_type);
$cdm_is_register = true;
$cdm_dispense_file = $cdm_type === 'narcotic' ? 'controlled_drug_narcotic.php' : 'controlled_drug_psychotropic.php';
$cdm_theme = $cdm_type === 'narcotic' ? '#7c2d12' : '#4c1d95';
$cdm_theme_light = $cdm_type === 'narcotic' ? '#fff7ed' : '#f5f3ff';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ทะเบียนจ่ายยา — <?= htmlspecialchars($cdm_label) ?></title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
    <link href="css/flatpickr.min.css" rel="stylesheet">
    <link href="css/select2.min.css" rel="stylesheet">
    <link href="css/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <style>
        :root { --cdm-primary: <?= $cdm_theme ?>; --cdm-light: <?= $cdm_theme_light ?>; }
        .cdm-hero {
            background: linear-gradient(135deg, var(--cdm-primary) 0%, #1f4d3b 100%);
            color: #fff; border-radius: 12px; padding: 1.25rem 1.5rem;
        }
        .cdm-hero h4 { font-weight: 800; margin: 0; }
        .filter-box { background: var(--cdm-light); border: 1px solid rgba(0,0,0,.06); border-radius: 10px; }
        .btn-cdm { background: var(--cdm-primary); border-color: var(--cdm-primary); color: #fff; }
        .btn-cdm:hover { filter: brightness(1.08); color: #fff; }
    </style>
</head>
<body>
<div class="wrapper">
    <?php include('sidebar.php'); ?>
    <div id="content" class="content p-4">
        <div class="cdm-hero mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h4><i class="fas fa-book-medical me-2"></i>ทะเบียนจ่ายยา — <?= htmlspecialchars($cdm_label) ?></h4>
                <small class="opacity-90">รายการผู้ป่วยที่บันทึกการจ่ายยาแล้ว</small>
            </div>
            <a href="<?= htmlspecialchars($cdm_dispense_file) ?>" class="btn btn-light btn-sm fw-bold">
                <i class="fas fa-hand-holding-medical me-1"></i>ไปหน้าจ่ายยา
            </a>
        </div>

        <?php include __DIR__ . '/controlled_drug_dispense_view.php'; ?>
    </div>
</div>

<script src="js/jquery-3.7.0.min.js"></script>
<script src="js/bootstrap.bundle.min.js"></script>
<script src="js/flatpickr.min.js"></script>
<script src="js/select2.min.js"></script>
<script>
const CDM_TYPE = <?= json_encode($cdm_type) ?>;
function apiPost(action, data) {
    return fetch('fetch_controlled_drug_api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(Object.assign({action: action, drug_type: CDM_TYPE}, data || {}))
    }).then(function(r) {
        return r.text().then(function(t) {
            try { return JSON.parse(t); } catch (e) { throw new Error(t || 'Invalid JSON'); }
        });
    });
}
function cdmEsc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
function cdmFmtQty(n) {
    n = Number(n || 0);
    return Number.isInteger(n) ? n.toLocaleString() : n.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
}
flatpickr('.cdm-date', { dateFormat: 'Y-m-d', locale: {firstDayOfWeek: 1} });
</script>
</body>
</html>
