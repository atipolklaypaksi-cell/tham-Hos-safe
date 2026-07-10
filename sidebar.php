<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>
<style>
    @font-face {
        font-family: 'Caveat';
        font-style: normal;
        font-weight: 500;
        font-display: swap;
        src: url('fonts/caveat/Caveat-Medium.ttf') format('truetype');
    }
    @font-face {
        font-family: 'Caveat';
        font-style: normal;
        font-weight: 700;
        font-display: swap;
        src: url('fonts/caveat/Caveat-Bold.ttf') format('truetype');
    }

    /* Sidebar เขียวเข้ม อ่านสบายตา */
    #sidebar {
        background: linear-gradient(180deg, #1a4032 0%, #143326 55%, #0f2419 100%) !important;
        color: #fff !important;
        box-shadow: 4px 0 24px rgba(15, 36, 25, 0.3) !important;
    }
    #sidebar .sidebar-header {
        background: #0f2419 !important;
        border-bottom: 1px solid rgba(168, 213, 194, 0.12) !important;
    }
    #sidebar .sidebar-logo {
        background: rgba(116, 180, 155, 0.15) !important;
        color: #a8d5c2 !important;
    }
    #sidebar .sidebar-user {
        background: rgba(0, 0, 0, 0.15) !important;
        border-bottom: 1px solid rgba(168, 213, 194, 0.1) !important;
    }
    #sidebar ul li a {
        color: rgba(255, 255, 255, 0.82) !important;
        border-bottom-color: rgba(255, 255, 255, 0.05) !important;
    }
    #sidebar ul li a:hover {
        background: rgba(255, 255, 255, 0.07) !important;
        color: #fff !important;
    }
    #sidebar ul li.active > a {
        background: #2a6049 !important;
        border-left: 4px solid #74b49b !important;
        color: #fff !important;
    }
    #sidebar ul ul a {
        background: rgba(0, 0, 0, 0.18) !important;
        border-bottom-color: rgba(255, 255, 255, 0.03) !important;
    }
    #sidebar ul ul a:hover {
        background: rgba(255, 255, 255, 0.06) !important;
    }
    #sidebar ul ul li.active > a {
        background: rgba(61, 122, 94, 0.45) !important;
        color: #eef7f3 !important;
        border-left: 3px solid #74b49b !important;
    }
    #sidebar .developer-credit {
        font-family: 'Caveat', cursive !important;
        font-size: 1.4rem !important;
        color: #FFD700 !important;
        text-align: center;
        padding: 20px 15px;
        margin-top: auto;
        border-top: 1px dashed rgba(255, 255, 255, 0.2) !important;
        line-height: 1.2;
        transition: color 0.3s ease;
    }
    #sidebar .developer-credit:hover {
        color: #f8f9fa !important;
    }
    #sidebar .btn-sidebar-logout {
        background: rgba(255, 255, 255, 0.08) !important;
        border-color: rgba(168, 213, 194, 0.3) !important;
        color: rgba(255, 255, 255, 0.9) !important;
    }
    #sidebar .btn-sidebar-logout:hover {
        background: rgba(255, 255, 255, 0.16) !important;
        color: #fff !important;
    }
    #sidebar ul li a.dropdown-toggle {
        display: flex !important;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
    }
    #sidebar ul li a.dropdown-toggle .sidebar-menu-text {
        flex: 1;
        min-width: 0;
    }
    #sidebar ul li a.dropdown-toggle::after {
        content: "";
        width: 8px;
        height: 8px;
        border-right: 2px solid currentColor;
        border-bottom: 2px solid currentColor;
        border-top: 0;
        border-left: 0;
        transform: rotate(45deg);
        flex-shrink: 0;
        transition: transform 0.3s ease;
    }
    #sidebar ul li a[aria-expanded="true"].dropdown-toggle::after {
        transform: rotate(-135deg);
    }
    #sidebar .sidebar-menu-sub {
        display: block;
        padding-left: 1.5rem;
        opacity: 0.75;
        font-size: 0.72rem;
        font-weight: 500;
        line-height: 1.2;
        margin-top: 2px;
    }
</style>
<?php
require_once __DIR__ . '/menu_permissions_helper.php';
require_once __DIR__ . '/deployment_map_config.php';
if (is_file(__DIR__ . '/deployment_map_local.php')) {
    require_once __DIR__ . '/deployment_map_local.php';
}
if (isset($_SESSION['user_id']) && empty($_SESSION['menu_permissions_loaded'])) {
    if (!isset($con)) {
        include_once __DIR__ . '/connect.php';
        if (isset($con)) {
            mysqli_set_charset($con, 'utf8');
        }
    }
    if (isset($con)) {
        tham_menu_load_session_permissions($con, (int)$_SESSION['user_id'], $_SESSION['role'] ?? '');
    }
}
if (isset($_SESSION['user_id'])) {
    tham_menu_ensure_page_access($current_page);
    if (!isset($con)) {
        include_once __DIR__ . '/connect.php';
        if (isset($con)) {
            mysqli_set_charset($con, 'utf8');
        }
    }
    if (isset($con)) {
        require_once __DIR__ . '/user_access_helper.php';
        tham_user_access_touch($con, (int)$_SESSION['user_id'], $current_page);
    }
}

$safety_pages = [
    'monitor_drug_interaction.php',
    'monitor_cumulative.php',
    'monitor_lab.php',
    'monitor_lab_renal.php',
    'monitor_lab_hepatic.php',
    'monitor_lab_other.php',
    'monitor_hbv.php',
    'monitor_hla.php',
    'monitor_ua.php',
    'monitor_drug_usage.php',
    'monitor_max_dose.php',
    'monitor_mg_ml.php',
    'monitor_opd_order_qty.php',
    'monitor_drug_allergy.php',
    'drugitem.php',
];
$is_safety_active = in_array($current_page, $safety_pages, true);

$pharm_care_pages = ['smart_drug.php', 'smart_lab.php', 'drug_reconciliation.php', 'drug_reconciliation_print.php'];
$is_pharm_care_active = in_array($current_page, $pharm_care_pages, true);

$smart_pharma_pages = [
    'smart_pharma.php',
    'smart_pharma_opd.php',
    'smart_pharma_ipd.php',
    'smart_pharma_interventions.php',
    'smart_pharma_settings.php',
    'smart_pharma_screen_hn.php',
    'smart_pharma_notifications.php',
    'smart_pharma_telegram.php',
    'smart_pharma_webhook.php',
];
$is_smart_pharma_active = in_array($current_page, $smart_pharma_pages, true);

$controlled_drug_pages = [
    'controlled_drug_narcotic.php',
    'controlled_drug_psychotropic.php',
    'controlled_drug_register_narcotic.php',
    'controlled_drug_register_psychotropic.php',
    'controlled_drug_settings_narcotic.php',
    'controlled_drug_settings_psychotropic.php',
    'controlled_drug_rooms.php',
    'controlled_drug_rooms_narcotic.php',
    'controlled_drug_rooms_psychotropic.php',
];
$is_controlled_drug_active = in_array($current_page, $controlled_drug_pages, true);

$rational_lab_use_pages = [
    'rational_lab_use.php',
    'rational_lab_use_dashboard.php',
];
$is_rational_lab_use_active = in_array($current_page, $rational_lab_use_pages, true);

$network_drug_pages = ['network_drug_upload.php', 'network_drug_list.php', 'drug_refer.php', 'drug_refer_print.php', 'drug_refer_district.php', 'drug_refer_appointment.php', 'monitor_refer_back.php', 'monitor_refer_back_opd.php', 'monitor_refer_back_ipd.php', 'monitor_refer_back_interventions.php', 'monitor_refer_back_settings.php'];
$is_network_drug_active = in_array($current_page, $network_drug_pages, true);

$admin_pages = ['hosxp_setting.php', 'users.php', 'user_access_log.php', 'menu_permissions.php', 'upgrade.php', 'admin_generate_key.php'];
$is_admin_active = in_array($current_page, $admin_pages, true);

$show_home = tham_menu_can('home');
$show_safety = tham_menu_group_visible('group_safety');
$show_smart_pharma = tham_menu_group_visible('group_smart_pharma');
$show_controlled_drug = tham_menu_group_visible('group_controlled_drug');
$show_rational_lab = tham_menu_group_visible('group_rational_lab');
$show_pharm_care = tham_menu_group_visible('group_pharm_care');
$show_network_drug = tham_menu_group_visible('group_network_drug');
$show_rational_lab_report = tham_menu_can('rational_lab.report');
$show_deployment_map = tham_menu_can('deployment.map');
$show_admin = tham_menu_group_visible('group_admin');
?>

<nav id="sidebar" class="d-flex flex-column">
    <div class="sidebar-header">
        <div class="sidebar-logo"><i class="fas fa-shield-virus"></i></div>
        <h3>THAM Hos-Safe</h3>
        <small>ระบบความปลอดภัยด้านยา</small>
    </div>

    <div class="sidebar-user">
        <div class="sidebar-user-label">เข้าสู่ระบบโดย</div>
        <div class="sidebar-user-name">
            <i class="fas fa-user-circle me-2"></i><?php echo htmlspecialchars($_SESSION['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <a href="logout.php" class="btn btn-sidebar-logout w-100 mt-3">
            <i class="fas fa-sign-out-alt me-1"></i> ออกจากระบบ
        </a>
    </div>

    <ul class="list-unstyled components flex-grow-1 mb-0">
        <?php if ($show_home): ?>
        <li class="<?= $current_page === 'home.php' ? 'active' : '' ?>">
            <a href="home.php"><i class="fas fa-home me-2"></i> หน้าหลัก</a>
        </li>
        <?php endif; ?>

        <?php if ($show_safety): ?>
        <li class="<?= $is_safety_active ? 'active' : '' ?>">
            <a href="#safetySubmenu" data-bs-toggle="collapse" aria-expanded="<?= $is_safety_active ? 'true' : 'false' ?>" class="dropdown-toggle">
                <span class="sidebar-menu-text"><i class="fas fa-shield-alt me-2"></i> จัดการความปลอดภัยยา</span>
            </a>
            <ul class="collapse list-unstyled <?= $is_safety_active ? 'show' : '' ?>" id="safetySubmenu">
                <?php if (tham_menu_can('safety.interaction')): ?>
                <li class="<?= $current_page === 'monitor_drug_interaction.php' ? 'active' : '' ?>">
                    <a href="monitor_drug_interaction.php"><i class="fas fa-pills me-2"></i> Monitor Interaction</a>
                </li>
                <?php endif; ?>
                <?php if (tham_menu_can('safety.cumulative')): ?>
                <li class="<?= $current_page === 'monitor_cumulative.php' ? 'active' : '' ?>">
                    <a href="monitor_cumulative.php"><i class="fas fa-radiation-alt me-2"></i> Monitor Cumulative</a>
                </li>
                <?php endif; ?>
                <?php if (tham_menu_can('safety.lab_renal')): ?>
                <li class="<?= in_array($current_page, ['monitor_lab.php', 'monitor_lab_renal.php'], true) ? 'active' : '' ?>">
                    <a href="monitor_lab_renal.php"><i class="fas fa-heartbeat me-2"></i> Monitor Lab ไต</a>
                </li>
                <?php endif; ?>
                <?php if (tham_menu_can('safety.lab_hepatic')): ?>
                <li class="<?= $current_page === 'monitor_lab_hepatic.php' ? 'active' : '' ?>">
                    <a href="monitor_lab_hepatic.php"><i class="fas fa-lungs me-2"></i> Monitor Lab ตับ</a>
                </li>
                <?php endif; ?>
                <?php if (tham_menu_can('safety.lab_other')): ?>
                <li class="<?= $current_page === 'monitor_lab_other.php' ? 'active' : '' ?>">
                    <a href="monitor_lab_other.php"><i class="fas fa-vial me-2"></i> Monitor Lab อื่นๆ</a>
                </li>
                <?php endif; ?>
                <?php if (tham_menu_can('safety.hbv')): ?>
                <li class="<?= $current_page === 'monitor_hbv.php' ? 'active' : '' ?>">
                    <a href="monitor_hbv.php"><i class="fas fa-virus me-2"></i> Monitor HBV คัดกรอง</a>
                </li>
                <?php endif; ?>
                <?php if (tham_menu_can('safety.hla')): ?>
                <li class="<?= $current_page === 'monitor_hla.php' ? 'active' : '' ?>">
                    <a href="monitor_hla.php"><i class="fas fa-dna me-2"></i> Monitor ยีนแพ้ยา HLA</a>
                </li>
                <?php endif; ?>
                <?php if (tham_menu_can('safety.ua')): ?>
                <li class="<?= $current_page === 'monitor_ua.php' ? 'active' : '' ?>">
                    <a href="monitor_ua.php"><i class="fas fa-flask me-2"></i> Monitor UA ปัสสาวะ</a>
                </li>
                <?php endif; ?>
                <?php if (tham_menu_can('safety.drug_usage')): ?>
                <li class="<?= $current_page === 'monitor_drug_usage.php' ? 'active' : '' ?>">
                    <a href="monitor_drug_usage.php"><i class="fas fa-prescription-bottle-alt me-2"></i> Monitor วิธีใช้ยา</a>
                </li>
                <?php endif; ?>
                <?php if (tham_menu_can('safety.max_dose')): ?>
                <li class="<?= $current_page === 'monitor_max_dose.php' ? 'active' : '' ?>">
                    <a href="monitor_max_dose.php"><i class="fas fa-tachometer-alt me-2"></i> Monitor Max Dose/Duration</a>
                </li>
                <?php endif; ?>
                <?php if (tham_menu_can('safety.mg_ml')): ?>
                <li class="<?= $current_page === 'monitor_mg_ml.php' ? 'active' : '' ?>">
                    <a href="monitor_mg_ml.php"><i class="fas fa-syringe me-2"></i> Monitor mg. to mL.</a>
                </li>
                <?php endif; ?>
                <?php if (tham_menu_can('safety.opd_qty')): ?>
                <li class="<?= $current_page === 'monitor_opd_order_qty.php' ? 'active' : '' ?>">
                    <a href="monitor_opd_order_qty.php"><i class="fas fa-calculator me-2"></i> Monitor จำนวนสั่งยา (OPD)</a>
                </li>
                <?php endif; ?>
                <?php if (tham_menu_can('safety.drug_allergy')): ?>
                <li class="<?= $current_page === 'monitor_drug_allergy.php' ? 'active' : '' ?>">
                    <a href="monitor_drug_allergy.php"><i class="fas fa-allergies me-2"></i> Monitor Drug Allergy</a>
                </li>
                <?php endif; ?>
                <?php if (tham_menu_can('safety.drugitem')): ?>
                <li class="<?= $current_page === 'drugitem.php' ? 'active' : '' ?>">
                    <a href="drugitem.php"><i class="fas fa-capsules me-2"></i> ข้อมูลยา (Master)</a>
                </li>
                <?php endif; ?>
            </ul>
        </li>
        <?php endif; ?>

        <?php if ($show_smart_pharma): ?>
        <li class="<?= $is_smart_pharma_active ? 'active' : '' ?>">
            <a href="#smartPharmaSubmenu" data-bs-toggle="collapse" aria-expanded="<?= $is_smart_pharma_active ? 'true' : 'false' ?>" class="dropdown-toggle">
                <span class="sidebar-menu-text">
                    <span><i class="fas fa-user-shield me-2"></i> Smart Pharma</span>
                    <small class="sidebar-menu-sub">ระบบคัดกรองผู้ป่วย</small>
                </span>
            </a>
            <ul class="collapse list-unstyled <?= $is_smart_pharma_active ? 'show' : '' ?>" id="smartPharmaSubmenu">
                <?php if (tham_menu_can('smart_pharma.opd')): ?>
                <li class="<?= $current_page === 'smart_pharma_opd.php' ? 'active' : '' ?>">
                    <a href="smart_pharma_opd.php"><i class="fas fa-hospital-user me-2"></i> ผู้ป่วยนอก (OPD)</a>
                </li>
                <?php endif; ?>
                <?php if (tham_menu_can('smart_pharma.ipd')): ?>
                <li class="<?= $current_page === 'smart_pharma_ipd.php' ? 'active' : '' ?>">
                    <a href="smart_pharma_ipd.php"><i class="fas fa-procedures me-2"></i> ผู้ป่วยใน (IPD)</a>
                </li>
                <?php endif; ?>
                <?php if (tham_menu_can('smart_pharma.interventions')): ?>
                <li class="<?= $current_page === 'smart_pharma_interventions.php' ? 'active' : '' ?>">
                    <a href="smart_pharma_interventions.php"><i class="fas fa-notes-medical me-2"></i> Intervention Log</a>
                </li>
                <?php endif; ?>
                <?php if (tham_menu_can('smart_pharma.settings')): ?>
                <li class="<?= $current_page === 'smart_pharma_settings.php' ? 'active' : '' ?>">
                    <a href="smart_pharma_settings.php"><i class="fas fa-sliders-h me-2"></i> ตั้งค่าโมดูล</a>
                </li>
                <?php endif; ?>
                <?php if (tham_menu_can('smart_pharma.screen_hn')): ?>
                <li class="<?= $current_page === 'smart_pharma_screen_hn.php' ? 'active' : '' ?>">
                    <a href="smart_pharma_screen_hn.php"><i class="fas fa-id-card me-2"></i> คัดกรองตาม HN</a>
                </li>
                <?php endif; ?>
                <?php if (tham_menu_can('smart_pharma.notifications')): ?>
                <li class="<?= in_array($current_page, ['smart_pharma_notifications.php', 'smart_pharma_telegram.php', 'smart_pharma_webhook.php'], true) ? 'active' : '' ?>">
                    <a href="smart_pharma_notifications.php"><i class="fas fa-bell me-2"></i> แจ้งเตือน Alert</a>
                </li>
                <?php endif; ?>
            </ul>
        </li>
        <?php endif; ?>

        <?php if ($show_controlled_drug): ?>
        <li class="<?= $is_controlled_drug_active ? 'active' : '' ?>">
            <a href="#controlledDrugSubmenu" data-bs-toggle="collapse" aria-expanded="<?= $is_controlled_drug_active ? 'true' : 'false' ?>" class="dropdown-toggle">
                <span class="sidebar-menu-text">
                    <span><i class="fas fa-capsules me-2"></i> บริหารยาเสพติด/วัตถุออกฤทธิ์</span>
                    <small class="sidebar-menu-sub">สต๊อกคงคลังและรายงาน HOSxP</small>
                </span>
            </a>
            <ul class="collapse list-unstyled <?= $is_controlled_drug_active ? 'show' : '' ?>" id="controlledDrugSubmenu">
                <?php if (tham_menu_can('controlled_drug.narcotic')): ?>
                <li class="<?= $current_page === 'controlled_drug_narcotic.php' ? 'active' : '' ?>">
                    <a href="controlled_drug_narcotic.php"><i class="fas fa-hand-holding-medical me-2"></i> จ่ายยา (ยาเสพติด)</a>
                </li>
                <?php endif; ?>
                <?php if (tham_menu_can('controlled_drug.psychotropic')): ?>
                <li class="<?= $current_page === 'controlled_drug_psychotropic.php' ? 'active' : '' ?>">
                    <a href="controlled_drug_psychotropic.php"><i class="fas fa-hand-holding-medical me-2"></i> จ่ายยา (วัตถุออกฤทธิ์)</a>
                </li>
                <?php endif; ?>
                <?php if (tham_menu_can('controlled_drug.narcotic') || tham_menu_can('controlled_drug.psychotropic')): ?>
                <li class="<?= in_array($current_page, ['controlled_drug_rooms.php', 'controlled_drug_rooms_narcotic.php', 'controlled_drug_rooms_psychotropic.php'], true) ? 'active' : '' ?>">
                    <a href="controlled_drug_rooms.php"><i class="fas fa-door-open me-2"></i> จัดการห้องสต๊อก</a>
                </li>
                <?php endif; ?>
            </ul>
        </li>
        <?php endif; ?>

        <?php if ($show_rational_lab): ?>
        <li class="<?= $is_rational_lab_use_active ? 'active' : '' ?>">
            <a href="#rationalLabUseSubmenu" data-bs-toggle="collapse" aria-expanded="<?= $is_rational_lab_use_active ? 'true' : 'false' ?>" class="dropdown-toggle">
                <span class="sidebar-menu-text">
                    <span><i class="fas fa-clipboard-check me-2"></i> Rational Lab Use</span>
                    <small class="sidebar-menu-sub">การสั่งตรวจ Lab อย่างสมเหตุสมผล</small>
                </span>
            </a>
            <ul class="collapse list-unstyled <?= $is_rational_lab_use_active ? 'show' : '' ?>" id="rationalLabUseSubmenu">
                <?php if (tham_menu_can('rational_lab.dashboard')): ?>
                <li class="<?= $current_page === 'rational_lab_use_dashboard.php' ? 'active' : '' ?>">
                    <a href="rational_lab_use_dashboard.php"><i class="fas fa-chart-bar me-2"></i> Dashboard ปริมาณการใช้ Lab</a>
                </li>
                <?php endif; ?>
            </ul>
        </li>
        <?php endif; ?>

        <?php if ($show_pharm_care): ?>
        <li class="<?= $is_pharm_care_active ? 'active' : '' ?>">
            <a href="#pharmCareSubmenu" data-bs-toggle="collapse" aria-expanded="<?= $is_pharm_care_active ? 'true' : 'false' ?>" class="dropdown-toggle">
                <span class="sidebar-menu-text"><i class="fas fa-stethoscope me-2"></i> บริบาลทางเภสัชกรรม</span>
            </a>
            <ul class="collapse list-unstyled <?= $is_pharm_care_active ? 'show' : '' ?>" id="pharmCareSubmenu">
                <?php if (tham_menu_can('pharm_care.smart_drug')): ?>
                <li class="<?= $current_page === 'smart_drug.php' ? 'active' : '' ?>">
                    <a href="smart_drug.php"><i class="fas fa-capsules me-2"></i> ระบบ Smart Drug</a>
                </li>
                <?php endif; ?>
                <?php if (tham_menu_can('pharm_care.smart_lab')): ?>
                <li class="<?= $current_page === 'smart_lab.php' ? 'active' : '' ?>">
                    <a href="smart_lab.php"><i class="fas fa-microscope me-2"></i> ระบบ Smart Lab</a>
                </li>
                <?php endif; ?>
                <?php if (tham_menu_can('pharm_care.reconciliation')): ?>
                <li class="<?= in_array($current_page, ['drug_reconciliation.php', 'drug_reconciliation_print.php'], true) ? 'active' : '' ?>">
                    <a href="drug_reconciliation.php"><i class="fas fa-exchange-alt me-2"></i> Drug Reconciliation</a>
                </li>
                <?php endif; ?>
            </ul>
        </li>
        <?php endif; ?>

        <?php if ($show_network_drug): ?>
        <li class="<?= $is_network_drug_active ? 'active' : '' ?>">
            <a href="#networkDrugSubmenu" data-bs-toggle="collapse" aria-expanded="<?= $is_network_drug_active ? 'true' : 'false' ?>" class="dropdown-toggle">
                <span class="sidebar-menu-text">
                    <span><i class="fas fa-network-wired me-2"></i> บัญชียาเครือข่าย</span>
                    <small class="sidebar-menu-sub">ยาเครือข่ายจังหวัด</small>
                </span>
            </a>
            <ul class="collapse list-unstyled <?= $is_network_drug_active ? 'show' : '' ?>" id="networkDrugSubmenu">
                <?php if (tham_menu_can('network_drug.upload')): ?>
                <li class="<?= $current_page === 'network_drug_upload.php' ? 'active' : '' ?>">
                    <a href="network_drug_upload.php"><i class="fas fa-file-upload me-2"></i> Upload ไฟล์</a>
                </li>
                <?php endif; ?>
                <?php if (tham_menu_can('network_drug.list')): ?>
                <li class="<?= $current_page === 'network_drug_list.php' ? 'active' : '' ?>">
                    <a href="network_drug_list.php"><i class="fas fa-table me-2"></i> รายการบัญชียาเครือข่าย</a>
                </li>
                <?php endif; ?>
                <?php if (tham_menu_can('network_drug.refer')): ?>
                <li class="<?= in_array($current_page, ['drug_refer.php', 'drug_refer_print.php'], true) ? 'active' : '' ?>">
                    <a href="drug_refer.php"><i class="fas fa-share-square me-2"></i> Refer ยาผู้ป่วย</a>
                </li>
                <?php endif; ?>
                <?php if (tham_menu_can('network_drug.refer_district')): ?>
                <li class="<?= $current_page === 'drug_refer_district.php' ? 'active' : '' ?>">
                    <a href="drug_refer_district.php"><i class="fas fa-map-marked-alt me-2"></i> Refer ยาแยกเขต</a>
                </li>
                <?php endif; ?>
                <?php if (tham_menu_can('network_drug.refer_appointment')): ?>
                <li class="<?= $current_page === 'drug_refer_appointment.php' ? 'active' : '' ?>">
                    <a href="drug_refer_appointment.php"><i class="fas fa-calendar-day me-2"></i> Refer ยาแยกตามวันนัด</a>
                </li>
                <?php endif; ?>
                <?php if (tham_menu_can('network_drug.monitor_refer_back')): ?>
                <li class="<?= in_array($current_page, ['monitor_refer_back_opd.php', 'monitor_refer_back_ipd.php', 'monitor_refer_back_interventions.php', 'monitor_refer_back_settings.php'], true) ? 'active' : '' ?>">
                    <a href="monitor_refer_back_opd.php"><i class="fas fa-bell me-2"></i> Monitor Refer Back</a>
                </li>
                <?php endif; ?>
            </ul>
        </li>
        <?php endif; ?>

        <?php if ($show_rational_lab_report): ?>
        <li class="<?= $current_page === 'report_dashboard.php' ? 'active' : '' ?>">
            <a href="report_dashboard.php"><i class="fas fa-file-alt me-2"></i> Dashboard รายงาน</a>
        </li>
        <?php endif; ?>

        <?php if ($show_deployment_map): ?>
        <li>
            <a href="<?php echo htmlspecialchars(THAM_DEPLOYMENT_MAP_URL, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">
                <i class="fas fa-map-marked-alt me-2"></i> รพ.ที่ใช้ THAM Hos-Safe
                <i class="fas fa-external-link-alt ms-1 small opacity-75"></i>
            </a>
        </li>
        <?php endif; ?>

        <?php if ($show_admin): ?>
        <li class="<?= $is_admin_active ? 'active' : '' ?>">
            <a href="#adminSubmenu" data-bs-toggle="collapse" aria-expanded="<?= $is_admin_active ? 'true' : 'false' ?>" class="dropdown-toggle">
                <span class="sidebar-menu-text"><i class="fas fa-cog me-2"></i> ตั้งค่าระบบ</span>
            </a>
            <ul class="collapse list-unstyled <?= $is_admin_active ? 'show' : '' ?>" id="adminSubmenu">
                <?php if (tham_menu_can('admin.hosxp')): ?>
                <li class="<?= $current_page === 'hosxp_setting.php' ? 'active' : '' ?>">
                    <a href="hosxp_setting.php"><i class="fas fa-network-wired me-2"></i> เชื่อมต่อ HOSxP</a>
                </li>
                <?php endif; ?>
                <?php if (tham_menu_can('admin.users')): ?>
                <li class="<?= $current_page === 'users.php' ? 'active' : '' ?>">
                    <a href="users.php"><i class="fas fa-user-shield me-2"></i> จัดการผู้ใช้งาน</a>
                </li>
                <?php endif; ?>
                <?php if (tham_menu_can('admin.menu_permissions')): ?>
                <li class="<?= $current_page === 'menu_permissions.php' ? 'active' : '' ?>">
                    <a href="menu_permissions.php"><i class="fas fa-key me-2"></i> สิทธิ์เข้าถึงเมนู</a>
                </li>
                <?php endif; ?>
                <?php if (tham_menu_can('admin.upgrade')): ?>
                <li class="<?= $current_page === 'upgrade.php' ? 'active' : '' ?>">
                    <a href="upgrade.php"><i class="fas fa-rocket me-2"></i> อัปเกรดระบบ</a>
                </li>
                <?php endif; ?>
                <?php if (
                    defined('THAM_LICENSE_ADMIN_ENABLED')
                    && THAM_LICENSE_ADMIN_ENABLED
                    && is_file(__DIR__ . '/admin_generate_key.php')
                    && tham_menu_can('admin.license')
                ): ?>
                <li class="<?= $current_page === 'admin_generate_key.php' ? 'active' : '' ?>">
                    <a href="admin_generate_key.php"><i class="fas fa-key me-2"></i> สร้าง License</a>
                </li>
                <?php endif; ?>
            </ul>
        </li>
        <?php endif; ?>
    </ul>

    <div class="developer-credit">
        Develop by<br>
        <span class="fw-bold">Pharm. Atipol Klaipaksi</span>
    </div>
</nav>
