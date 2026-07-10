<?php
/**
 * สิทธิ์เข้าถึงเมนู — กำหนดตาม Role (กลุ่ม) หรือรายคน
 */

function tham_menu_registry() {
    static $registry = null;
    if ($registry !== null) {
        return $registry;
    }
    $registry = [
        'home' => [
            'label' => 'หน้าหลัก',
            'icon' => 'fas fa-home',
            'pages' => ['home.php'],
        ],
        'group_safety' => [
            'label' => 'จัดการความปลอดภัยยา',
            'icon' => 'fas fa-shield-alt',
            'group' => true,
            'children' => [
                'safety.interaction' => ['label' => 'Monitor Interaction', 'pages' => ['monitor_drug_interaction.php']],
                'safety.cumulative' => ['label' => 'Monitor Cumulative', 'pages' => ['monitor_cumulative.php']],
                'safety.lab_renal' => ['label' => 'Monitor Lab ไต', 'pages' => ['monitor_lab.php', 'monitor_lab_renal.php']],
                'safety.lab_hepatic' => ['label' => 'Monitor Lab ตับ', 'pages' => ['monitor_lab_hepatic.php']],
                'safety.lab_other' => ['label' => 'Monitor Lab อื่นๆ', 'pages' => ['monitor_lab_other.php']],
                'safety.hbv' => ['label' => 'Monitor HBV คัดกรอง', 'pages' => ['monitor_hbv.php']],
                'safety.hla' => ['label' => 'Monitor ยีนแพ้ยา HLA', 'pages' => ['monitor_hla.php']],
                'safety.ua' => ['label' => 'Monitor UA ปัสสาวะ', 'pages' => ['monitor_ua.php']],
                'safety.drug_usage' => ['label' => 'Monitor วิธีใช้ยา', 'pages' => ['monitor_drug_usage.php']],
                'safety.max_dose' => ['label' => 'Monitor Max Dose/Duration', 'pages' => ['monitor_max_dose.php']],
                'safety.mg_ml' => ['label' => 'Monitor mg. to mL.', 'pages' => ['monitor_mg_ml.php']],
                'safety.opd_qty' => ['label' => 'Monitor จำนวนสั่งยา (OPD)', 'pages' => ['monitor_opd_order_qty.php']],
                'safety.drug_allergy' => ['label' => 'Monitor Drug Allergy', 'pages' => ['monitor_drug_allergy.php']],
                'safety.drugitem' => ['label' => 'ข้อมูลยา (Master)', 'pages' => ['drugitem.php']],
            ],
        ],
        'group_smart_pharma' => [
            'label' => 'Smart Pharma',
            'icon' => 'fas fa-user-shield',
            'group' => true,
            'children' => [
                'smart_pharma.opd' => ['label' => 'ผู้ป่วยนอก (OPD)', 'pages' => ['smart_pharma_opd.php']],
                'smart_pharma.ipd' => ['label' => 'ผู้ป่วยใน (IPD)', 'pages' => ['smart_pharma_ipd.php']],
                'smart_pharma.interventions' => ['label' => 'Intervention Log', 'pages' => ['smart_pharma_interventions.php']],
                'smart_pharma.settings' => ['label' => 'ตั้งค่าโมดูล', 'pages' => ['smart_pharma_settings.php']],
                'smart_pharma.screen_hn' => ['label' => 'คัดกรองตาม HN', 'pages' => ['smart_pharma_screen_hn.php']],
                'smart_pharma.notifications' => ['label' => 'แจ้งเตือน Alert', 'pages' => ['smart_pharma_notifications.php', 'smart_pharma_telegram.php', 'smart_pharma_webhook.php']],
            ],
        ],
        'group_controlled_drug' => [
            'label' => 'บริหารยาเสพติด/วัตถุออกฤทธิ์',
            'icon' => 'fas fa-capsules',
            'group' => true,
            'children' => [
                'controlled_drug.narcotic' => ['label' => 'จ่ายยา (ยาเสพติด)', 'pages' => ['controlled_drug_narcotic.php', 'controlled_drug_register_narcotic.php', 'controlled_drug_settings_narcotic.php', 'controlled_drug_rooms.php', 'controlled_drug_rooms_narcotic.php']],
                'controlled_drug.psychotropic' => ['label' => 'จ่ายยา (วัตถุออกฤทธิ์)', 'pages' => ['controlled_drug_psychotropic.php', 'controlled_drug_register_psychotropic.php', 'controlled_drug_settings_psychotropic.php', 'controlled_drug_rooms.php', 'controlled_drug_rooms_psychotropic.php']],
            ],
        ],
        'group_rational_lab' => [
            'label' => 'Rational Lab Use',
            'icon' => 'fas fa-clipboard-check',
            'group' => true,
            'children' => [
                'rational_lab.dashboard' => ['label' => 'Dashboard ปริมาณการใช้ Lab', 'pages' => ['rational_lab_use_dashboard.php', 'rational_lab_use.php']],
            ],
        ],
        'group_pharm_care' => [
            'label' => 'บริบาลทางเภสัชกรรม',
            'icon' => 'fas fa-stethoscope',
            'group' => true,
            'children' => [
                'pharm_care.smart_drug' => ['label' => 'ระบบ Smart Drug', 'pages' => ['smart_drug.php']],
                'pharm_care.smart_lab' => ['label' => 'ระบบ Smart Lab', 'pages' => ['smart_lab.php']],
                'pharm_care.reconciliation' => ['label' => 'Drug Reconciliation', 'pages' => ['drug_reconciliation.php', 'drug_reconciliation_print.php']],
            ],
        ],
        'group_network_drug' => [
            'label' => 'บัญชียาเครือข่าย',
            'icon' => 'fas fa-network-wired',
            'group' => true,
            'children' => [
                'network_drug.upload' => ['label' => 'Upload ไฟล์', 'pages' => ['network_drug_upload.php']],
                'network_drug.list' => ['label' => 'รายการบัญชียาเครือข่าย', 'pages' => ['network_drug_list.php']],
                'network_drug.refer' => ['label' => 'Refer ยาผู้ป่วย', 'pages' => ['drug_refer.php', 'drug_refer_print.php']],
                'network_drug.refer_district' => ['label' => 'Refer ยาแยกเขต', 'pages' => ['drug_refer_district.php']],
                'network_drug.refer_appointment' => ['label' => 'Refer ยาแยกตามวันนัด', 'pages' => ['drug_refer_appointment.php']],
                'network_drug.monitor_refer_back' => ['label' => 'Monitor Refer Back', 'pages' => [
                    'monitor_refer_back.php',
                    'monitor_refer_back_opd.php',
                    'monitor_refer_back_ipd.php',
                    'monitor_refer_back_interventions.php',
                    'monitor_refer_back_settings.php',
                ]],
            ],
        ],
        'rational_lab.report' => [
            'label' => 'Dashboard รายงาน',
            'icon' => 'fas fa-file-alt',
            'pages' => ['report_dashboard.php'],
        ],
        'deployment.map' => [
            'label' => 'รพ.ที่ใช้ THAM Hos-Safe',
            'icon' => 'fas fa-map-marked-alt',
            'pages' => [],
        ],
        'group_admin' => [
            'label' => 'ตั้งค่าระบบ',
            'icon' => 'fas fa-cog',
            'group' => true,
            'children' => [
                'admin.hosxp' => ['label' => 'เชื่อมต่อ HOSxP', 'pages' => ['hosxp_setting.php']],
                'admin.users' => ['label' => 'จัดการผู้ใช้งาน', 'pages' => ['users.php', 'user_access_log.php']],
                'admin.menu_permissions' => ['label' => 'สิทธิ์เข้าถึงเมนู', 'pages' => ['menu_permissions.php']],
                'admin.upgrade' => ['label' => 'อัปเกรดระบบ', 'pages' => ['upgrade.php', 'do_update.php', 'backup_db.php']],
                'admin.license' => ['label' => 'สร้าง License', 'pages' => ['admin_generate_key.php']],
            ],
        ],
    ];
    return $registry;
}

function tham_menu_registry_groups() {
    $groups = [];
    foreach (tham_menu_registry() as $groupKey => $item) {
        if (!empty($item['group']) && !empty($item['children'])) {
            $children = [];
            foreach ($item['children'] as $childKey => $child) {
                $children[] = ['key' => $childKey, 'label' => $child['label']];
            }
            $groups[] = [
                'key' => $groupKey,
                'label' => $item['label'],
                'children' => $children,
            ];
        } else {
            $groups[] = [
                'key' => $groupKey,
                'label' => $item['label'],
                'children' => [['key' => $groupKey, 'label' => $item['label']]],
            ];
        }
    }
    return $groups;
}

function tham_menu_all_keys() {
    $keys = [];
    foreach (tham_menu_registry() as $key => $item) {
        if (!empty($item['group']) && !empty($item['children'])) {
            foreach ($item['children'] as $childKey => $child) {
                $keys[] = $childKey;
            }
        } else {
            $keys[] = $key;
        }
    }
    return $keys;
}

function tham_menu_page_map() {
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    foreach (tham_menu_registry() as $key => $item) {
        if (!empty($item['group']) && !empty($item['children'])) {
            foreach ($item['children'] as $childKey => $child) {
                foreach ($child['pages'] ?? [] as $page) {
                    $map[$page] = $childKey;
                }
            }
        } else {
            foreach ($item['pages'] ?? [] as $page) {
                $map[$page] = $key;
            }
        }
    }
  // หน้าที่ไม่ต้องตรวจ (login, logout)
    return $map;
}

function tham_menu_role_labels() {
    return [
        'admin' => 'ผู้ดูแลระบบ',
        'pharmacist' => 'เภสัชกร',
        'doctor' => 'แพทย์',
        'nurse' => 'พยาบาล',
        'pharmacy_technician' => 'เจ้าพนักงานเภสัชกรรม',
        'general_staff' => 'เจ้าหน้าที่ทั่วไป',
    ];
}

function tham_menu_ensure_tables($con) {
    static $ready = false;
    if ($ready) {
        return true;
    }
    $sql = @file_get_contents(__DIR__ . '/sql/menu_permissions.sql');
    if ($sql === false) {
        return false;
    }
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt !== '' && !mysqli_query($con, $stmt)) {
            return false;
        }
    }
    $q = mysqli_query($con, "SHOW COLUMNS FROM users LIKE 'use_custom_menus'");
    if ($q && mysqli_num_rows($q) === 0) {
        mysqli_query($con, "ALTER TABLE users ADD COLUMN use_custom_menus TINYINT(1) NOT NULL DEFAULT 0");
    }
    $ready = true;
    return true;
}

function tham_menu_fetch_role_permissions($con, $role) {
    tham_menu_ensure_tables($con);
    $role = mysqli_real_escape_string($con, $role);
    $q = mysqli_query($con, "SELECT menu_key FROM role_menu_permissions WHERE role = '$role'");
    $keys = [];
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $keys[] = $row['menu_key'];
        }
    }
    return $keys;
}

function tham_menu_fetch_user_permissions($con, $user_id) {
    tham_menu_ensure_tables($con);
    $user_id = (int)$user_id;
    $q = mysqli_query($con, "SELECT menu_key FROM user_menu_permissions WHERE user_id = $user_id");
    $keys = [];
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $keys[] = $row['menu_key'];
        }
    }
    return $keys;
}

function tham_menu_user_uses_custom($con, $user_id) {
    tham_menu_ensure_tables($con);
    $user_id = (int)$user_id;
    $q = mysqli_query($con, "SELECT use_custom_menus FROM users WHERE id = $user_id LIMIT 1");
    if ($q && ($row = mysqli_fetch_assoc($q))) {
        return (int)($row['use_custom_menus'] ?? 0) === 1;
    }
    return false;
}

function tham_menu_resolve_allowed_keys($con, $user_id, $role) {
    if ($role === 'admin') {
        return tham_menu_all_keys();
    }
    if (tham_menu_user_uses_custom($con, $user_id)) {
        $custom = tham_menu_fetch_user_permissions($con, $user_id);
        if (!empty($custom)) {
            return $custom;
        }
    }
    $roleKeys = tham_menu_fetch_role_permissions($con, $role);
    if (empty($roleKeys)) {
        return tham_menu_all_keys();
    }
    return $roleKeys;
}

function tham_menu_load_session_permissions($con, $user_id, $role) {
    $allowed = tham_menu_resolve_allowed_keys($con, $user_id, $role);
    $_SESSION['allowed_menus'] = $allowed;
    $_SESSION['menu_permissions_loaded'] = true;
    $_SESSION['use_custom_menus'] = tham_menu_user_uses_custom($con, $user_id) ? 1 : 0;
}

function tham_menu_can($menu_key) {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    if (($_SESSION['role'] ?? '') === 'admin') {
        return true;
    }
    $allowed = $_SESSION['allowed_menus'] ?? null;
    if ($allowed === null) {
        return true;
    }
    return in_array($menu_key, $allowed, true);
}

function tham_menu_group_visible($group_key) {
    $registry = tham_menu_registry();
    if (!isset($registry[$group_key]['children'])) {
        return tham_menu_can($group_key);
    }
    foreach ($registry[$group_key]['children'] as $childKey => $child) {
        if (tham_menu_can($childKey)) {
            return true;
        }
    }
    return false;
}

function tham_menu_ensure_page_access($page) {
    if (!isset($_SESSION['user_id'])) {
        return;
    }
    $skip = ['login.php', 'logout.php', 'index.php'];
    if (in_array($page, $skip, true)) {
        return;
    }
    if (($_SESSION['role'] ?? '') === 'admin') {
        return;
    }
    $map = tham_menu_page_map();
    if (!isset($map[$page])) {
        return;
    }
    $menu_key = $map[$page];
    if (!tham_menu_can($menu_key)) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8"><title>ไม่มีสิทธิ์</title></head><body style="font-family:Tahoma,sans-serif;padding:40px;text-align:center;">';
        echo '<h3>ไม่มีสิทธิ์เข้าถึงหน้านี้</h3>';
        echo '<p>กรุณาติดต่อผู้ดูแลระบบเพื่อขอสิทธิ์เมนู</p>';
        echo '<a href="home.php">กลับหน้าหลัก</a></body></html>';
        exit;
    }
}

function tham_menu_save_role_permissions($con, $role, array $menu_keys) {
    tham_menu_ensure_tables($con);
    $role = mysqli_real_escape_string($con, $role);
    $valid = array_flip(tham_menu_all_keys());
    $values = [];
    $seen = [];
    foreach ($menu_keys as $key) {
        $key = trim((string)$key);
        if ($key === '' || !isset($valid[$key]) || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $k = mysqli_real_escape_string($con, $key);
        $values[] = "('$role', '$k')";
    }
    mysqli_begin_transaction($con);
    if (!mysqli_query($con, "DELETE FROM role_menu_permissions WHERE role = '$role'")) {
        mysqli_rollback($con);
        return false;
    }
    if (!empty($values)) {
        $sql = 'INSERT INTO role_menu_permissions (role, menu_key) VALUES ' . implode(',', $values);
        if (!mysqli_query($con, $sql)) {
            mysqli_rollback($con);
            return false;
        }
    }
    mysqli_commit($con);
    return true;
}

function tham_menu_save_user_permissions($con, $user_id, $use_custom, array $menu_keys) {
    tham_menu_ensure_tables($con);
    $user_id = (int)$user_id;
    $use_custom = $use_custom ? 1 : 0;
    mysqli_begin_transaction($con);
    if (!mysqli_query($con, "UPDATE users SET use_custom_menus = $use_custom WHERE id = $user_id")) {
        mysqli_rollback($con);
        return false;
    }
    if (!mysqli_query($con, "DELETE FROM user_menu_permissions WHERE user_id = $user_id")) {
        mysqli_rollback($con);
        return false;
    }
    if ($use_custom) {
        $valid = array_flip(tham_menu_all_keys());
        $values = [];
        $seen = [];
        foreach ($menu_keys as $key) {
            $key = trim((string)$key);
            if ($key === '' || !isset($valid[$key]) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $k = mysqli_real_escape_string($con, $key);
            $values[] = "($user_id, '$k')";
        }
        if (!empty($values)) {
            $sql = 'INSERT INTO user_menu_permissions (user_id, menu_key) VALUES ' . implode(',', $values);
            if (!mysqli_query($con, $sql)) {
                mysqli_rollback($con);
                return false;
            }
        }
    }
    mysqli_commit($con);
    return true;
}
