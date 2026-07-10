<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    die("คุณไม่มีสิทธิ์เข้าถึงหน้านี้ กรุณาเข้าสู่ระบบ");
}

include('connect.php');
mysqli_set_charset($con, "utf8");
include('version.php'); // ดึงค่า APP_VERSION ปัจจุบัน
include('hosxp_tmt_drug_helper.php');

// 🎯 1. URL ของ version.json บน GitHub (repo เผยแพร่อัปเดต)
$github_version_url = "https://raw.githubusercontent.com/atipolklaypaksi-cell/tham-Hos-safe/main/version.json";

$remote_version = APP_VERSION;
$update_url = "";
$update_details = "";

// 🎯 2. ใช้ cURL ดึงข้อมูลแทน เพื่อแก้ปัญหา XAMPP บล็อก HTTPS
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $github_version_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // ทะลวงกำแพง SSL ของ XAMPP
curl_setopt($ch, CURLOPT_USERAGENT, 'THAM-CYTO-Updater'); // จำเป็นสำหรับ GitHub
$json_data = curl_exec($ch);
curl_close($ch);

if ($json_data !== FALSE && !empty($json_data)) {
    $remote_data = json_decode($json_data, true);
    if ($remote_data && isset($remote_data['latest_version'])) {
        $remote_version = $remote_data['latest_version'];
        $update_url = $remote_data['download_url'];
        $update_details = $remote_data['update_details'];
    }
}

// 🎯 3. ดึงเวอร์ชันฐานข้อมูล
$q = mysqli_query($con, "SELECT config_value FROM sys_config WHERE config_name = 'db_version'");
$r = mysqli_fetch_assoc($q);
$db_version = $r ? $r['config_value'] : '1.0.0';

echo "<div style='font-family: Tahoma, sans-serif; max-width: 800px; margin: 50px auto; padding: 30px; border: 1px solid #ddd; border-radius: 10px; background-color: #f9f9f9; box-shadow: 0 4px 8px rgba(0,0,0,0.1);'>";
echo "<h2 style='color: #1a4032;'><i class='fas fa-rocket'></i> ระบบตรวจสอบและอัปเดต THAM SAFE</h2>";

// --- ส่วนที่ 1: เช็คอัปเดตไฟล์ ---
echo "<div style='background: #fff; padding: 15px; border-radius: 8px; border-left: 5px solid #8e44ad; margin-bottom: 20px;'>";
echo "<h4>📦 1. ตรวจสอบเวอร์ชันโปรแกรม (Files)</h4>";
echo "เวอร์ชันในเครื่องปัจจุบัน: <strong style='color:#e67e22;'>" . APP_VERSION . "</strong><br>";

if (version_compare(APP_VERSION, $remote_version, '<')) {
    echo "เวอร์ชันล่าสุดบนเซิร์ฟเวอร์: <strong style='color:#e74c3c;'>" . $remote_version . "</strong> (มีอัปเดตใหม่!)<br>";
    echo "<p style='color: #555; font-size: 0.9em; margin-top: 10px;'><strong>รายละเอียดอัปเดต:</strong> " . $update_details . "</p>";
    
    // 🎯 [เพิ่มใหม่] กล่องคำเตือนและปุ่ม Backup ฐานข้อมูล
    echo "<div style='background-color: #fff3cd; border-left: 5px solid #ffc107; padding: 15px; margin: 15px 0; border-radius: 5px;'>";
    echo "<strong style='color: #856404;'><i class='fas fa-exclamation-triangle'></i> คำแนะนำก่อนอัปเดต:</strong><br>";
    echo "<span style='color: #856404; font-size: 0.95em;'>กรุณาสำรองฐานข้อมูลเก็บไว้ในเครื่องคอมพิวเตอร์ของท่านทุกครั้ง เพื่อป้องกันข้อมูลสูญหายกรณีเกิดข้อผิดพลาด</span><br>";
    echo "<a href='backup_db.php' target='_blank' style='display: inline-block; margin-top: 10px; padding: 8px 15px; background-color: #f39c12; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;'><i class='fas fa-save'></i> 1. กดดาวน์โหลดไฟล์สำรองฐานข้อมูล (.sql)</a>";
    echo "</div>";

    // ปุ่มอัปเดตอัตโนมัติ (เปลี่ยนมาเป็นข้อ 2)
    echo "<a href='do_update.php?url=" . urlencode($update_url) . "' style='display: inline-block; padding: 10px 20px; background-color: #e74c3c; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;'><i class='fas fa-cloud-download-alt'></i> 2. กดอัปเดตเป็นเวอร์ชัน " . $remote_version . " อัตโนมัติ</a>";
} else {
    echo "<span style='color:#27ae60;'>✔️ โปรแกรมของท่านเป็นเวอร์ชันล่าสุดแล้ว</span>";
}
echo "</div>";

// --- ส่วนที่ 2: เช็คอัปเดตฐานข้อมูล ---
echo "<div style='background: #fff; padding: 15px; border-radius: 8px; border-left: 5px solid #2980b9; margin-bottom: 20px;'>";
echo "<h4>🗄️ 2. ตรวจสอบโครงสร้างฐานข้อมูล (Database)</h4>";
echo "เวอร์ชันฐานข้อมูลปัจจุบัน: <strong style='color:#2980b9;'>" . $db_version . "</strong><br>";

if (version_compare($db_version, APP_VERSION, '<')) {
    echo "<h4 style='color: #d35400; margin-top: 15px;'>กำลังเริ่มปรับปรุงฐานข้อมูลให้ตรงกับโปรแกรม...</h4>";
    echo "<ul style='line-height: 1.8;'>";

    // 🎯 เริ่มอัปเดตฐานข้อมูลไปเป็นเวอร์ชัน 1.0.1
    if (version_compare($db_version, '1.0.1', '<')) {
        
        // 1. (อนาคต) ถ้ามีคำสั่งแก้ตาราง ให้เขียนตรงนี้ เช่น:
        // mysqli_query($con, "ALTER TABLE orders ADD COLUMN new_column VARCHAR(255)");
        
        echo "<li>✅ ปรับปรุงโครงสร้างตารางสำหรับเวอร์ชัน 1.0.1 สำเร็จ</li>";
        
        // 2. คำสั่งนี้สำคัญสุด! อัปเดตเลขในตาราง sys_config ให้เป็น 1.0.1
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.0.1' WHERE config_name = 'db_version'");
        
        // อัปเดตตัวแปรเพื่อจบงาน
        $db_version = '1.0.1'; 
    }
    
    if (version_compare($db_version, '1.0.2', '<')) {
        $lab_cols = [
            'lab_tbili' => 'DECIMAL(10,3) NULL DEFAULT NULL',
            'lab_ast' => 'DECIMAL(10,2) NULL DEFAULT NULL',
            'lab_alt' => 'DECIMAL(10,2) NULL DEFAULT NULL',
            'lab_hbsag' => 'VARCHAR(255) NULL DEFAULT NULL',
            'lab_antihbc' => 'VARCHAR(255) NULL DEFAULT NULL',
            'lab_urine_ph' => 'VARCHAR(50) NULL DEFAULT NULL',
            'lab_urine_sg' => 'DECIMAL(5,4) NULL DEFAULT NULL',
            'lab_urine_protein' => 'VARCHAR(255) NULL DEFAULT NULL',
        ];
        $existing_cols = [];
        $q_cols = mysqli_query($con, 'SHOW COLUMNS FROM orders');
        while ($rc = mysqli_fetch_assoc($q_cols)) {
            $existing_cols[] = $rc['Field'];
        }
        foreach ($lab_cols as $col_name => $col_def) {
            if (!in_array($col_name, $existing_cols, true)) {
                mysqli_query($con, "ALTER TABLE orders ADD COLUMN $col_name $col_def");
            }
        }
        echo "<li>✅ เพิ่มคอลัมน์ Lab (ตับ / Virology / Urinalysis) ในตาราง orders สำเร็จ</li>";
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.0.2' WHERE config_name = 'db_version'");
        $db_version = '1.0.2';
    }

    // ปรับขนาด/ประเภทคอลัมน์ Lab ให้รองรับหลาย รพ. (เคยติดตั้งแบบเดิม)
    if (version_compare($db_version, '1.0.3', '<')) {
        $mods = [
            "ALTER TABLE orders MODIFY COLUMN lab_hbsag VARCHAR(255) NULL DEFAULT NULL",
            "ALTER TABLE orders MODIFY COLUMN lab_antihbc VARCHAR(255) NULL DEFAULT NULL",
            "ALTER TABLE orders MODIFY COLUMN lab_urine_protein VARCHAR(255) NULL DEFAULT NULL",
            "ALTER TABLE orders MODIFY COLUMN lab_urine_ph VARCHAR(50) NULL DEFAULT NULL",
            "ALTER TABLE orders MODIFY COLUMN lab_urine_sg DECIMAL(5,4) NULL DEFAULT NULL",
        ];
        foreach ($mods as $sql_mod) {
            mysqli_query($con, $sql_mod);
        }
        echo "<li>✅ ปรับประเภทคอลัมน์ Lab ให้รองรับผลข้อความ/หลายรูปแบบจาก HOSxP สำเร็จ</li>";
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.0.3' WHERE config_name = 'db_version'");
        $db_version = '1.0.3';
    }

    if (version_compare($db_version, '1.0.4', '<')) {
        $lab_cols_104 = [
            'lab_urine_rbc' => 'VARCHAR(255) NULL DEFAULT NULL',
            'lab_urine_protein_24h' => 'VARCHAR(255) NULL DEFAULT NULL',
        ];
        $existing_cols_104 = [];
        $q_cols_104 = mysqli_query($con, 'SHOW COLUMNS FROM orders');
        while ($rc = mysqli_fetch_assoc($q_cols_104)) {
            $existing_cols_104[] = $rc['Field'];
        }
        foreach ($lab_cols_104 as $col_name => $col_def) {
            if (!in_array($col_name, $existing_cols_104, true)) {
                mysqli_query($con, "ALTER TABLE orders ADD COLUMN $col_name $col_def");
            }
        }
        echo "<li>✅ เพิ่มคอลัมน์ Urine RBC / Urine Protein 24 hrs ในตาราง orders สำเร็จ</li>";
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.0.4' WHERE config_name = 'db_version'");
        $db_version = '1.0.4';
    }

    if (version_compare($db_version, '1.0.5', '<')) {
        $sql_di = file_get_contents(__DIR__ . '/sql/monitor_drug_interaction.sql');
        if ($sql_di !== false) {
            mysqli_multi_query($con, $sql_di);
            while (mysqli_more_results($con)) {
                mysqli_next_result($con);
            }
        }
        echo "<li>✅ สร้างตาราง monitor_drug_interaction_settings สำหรับ Monitor Drug Interaction สำเร็จ</li>";
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.0.5' WHERE config_name = 'db_version'");
        $db_version = '1.0.5';
    }

    if (version_compare($db_version, '1.0.6', '<')) {
        $chk_col = mysqli_query($con, "SHOW COLUMNS FROM monitor_drug_interaction_settings LIKE 'alert_level'");
        if (!$chk_col || mysqli_num_rows($chk_col) === 0) {
            $sql_al = file_get_contents(__DIR__ . '/sql/monitor_drug_interaction_alert_level.sql');
            if ($sql_al !== false) {
                $stmts = array_filter(array_map('trim', explode(';', $sql_al)));
                foreach ($stmts as $stmt) {
                    if ($stmt !== '' && !preg_match('/^--/', $stmt)) {
                        mysqli_query($con, $stmt);
                    }
                }
            }
            echo "<li>✅ เปลี่ยนระดับความรุนแรงเป็น Hard / Soft Alert สำเร็จ</li>";
        } else {
            echo "<li>✅ คอลัมน์ alert_level มีอยู่แล้ว</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.0.6' WHERE config_name = 'db_version'");
        $db_version = '1.0.6';
    }

    if (version_compare($db_version, '1.0.7', '<')) {
        $lab_cols_107 = [
            'lab_dbili' => 'DECIMAL(10,3) NULL DEFAULT NULL',
            'lab_ibili' => 'DECIMAL(10,3) NULL DEFAULT NULL',
        ];
        $existing_cols_107 = [];
        $q_cols_107 = mysqli_query($con, 'SHOW COLUMNS FROM orders');
        while ($rc = mysqli_fetch_assoc($q_cols_107)) {
            $existing_cols_107[] = $rc['Field'];
        }
        foreach ($lab_cols_107 as $col_name => $col_def) {
            if (!in_array($col_name, $existing_cols_107, true)) {
                mysqli_query($con, "ALTER TABLE orders ADD COLUMN $col_name $col_def");
            }
        }
        echo "<li>✅ เพิ่มคอลัมน์ Direct / Indirect Bilirubin ในตาราง orders สำเร็จ</li>";
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.0.7' WHERE config_name = 'db_version'");
        $db_version = '1.0.7';
    }

    if (version_compare($db_version, '1.0.8', '<')) {
        if (ensure_drug_safety_rules_hosxp_columns($con)) {
            echo "<li>✅ เพิ่มคอลัมน์ drug_icode / drug_name ใน drug_safety_rules (Monitor Lab จาก HOSxP) สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถเพิ่มคอลัมน์ drug_icode / drug_name ใน drug_safety_rules ได้</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.0.8' WHERE config_name = 'db_version'");
        $db_version = '1.0.8';
    }

    if (version_compare($db_version, '1.0.9', '<')) {
        if (ensure_drug_safety_rules_alert_level_column($con)) {
            echo "<li>✅ เพิ่มคอลัมน์ alert_level (Hard / Soft Alert) ใน drug_safety_rules สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถเพิ่มคอลัมน์ alert_level ใน drug_safety_rules ได้</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.0.9' WHERE config_name = 'db_version'");
        $db_version = '1.0.9';
    }

    if (version_compare($db_version, '1.1.0', '<')) {
        if (ensure_di_dose_columns($con)) {
            echo "<li>✅ เพิ่มเงื่อนไข Dose (mg/วัน) ใน monitor_drug_interaction_settings สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถเพิ่มคอลัมน์เงื่อนไข Dose ใน monitor_drug_interaction_settings ได้</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.1.0' WHERE config_name = 'db_version'");
        $db_version = '1.1.0';
    }

    if (version_compare($db_version, '1.1.1', '<')) {
        if (ensure_drug_safety_rules_lab_items_columns($con)) {
            echo "<li>✅ เพิ่มคอลัมน์ lab_items_code / lab_items_name ใน drug_safety_rules (Monitor Lab อื่นๆ) สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถเพิ่มคอลัมน์ lab_items_code / lab_items_name ใน drug_safety_rules ได้</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.1.1' WHERE config_name = 'db_version'");
        $db_version = '1.1.1';
    }

    if (version_compare($db_version, '1.1.2', '<')) {
        if (ensure_drug_safety_rules_lab_condition_columns($con)) {
            echo "<li>✅ เพิ่มคอลัมน์ lab_condition_type / lab_condition_value ใน drug_safety_rules (เงื่อนไขข้อความ Monitor Lab อื่นๆ) สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถเพิ่มคอลัมน์ lab_condition_type / lab_condition_value ใน drug_safety_rules ได้</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.1.2' WHERE config_name = 'db_version'");
        $db_version = '1.1.2';
    }

    if (version_compare($db_version, '1.1.3', '<')) {
        $sql_hla = @file_get_contents(__DIR__ . '/sql/monitor_hla.sql');
        if ($sql_hla && mysqli_multi_query($con, $sql_hla)) {
            while (mysqli_more_results($con)) {
                mysqli_next_result($con);
            }
            echo "<li>✅ สร้างตาราง monitor_hla_settings (Monitor คัดกรองยีนแพ้ยา) สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถสร้างตาราง monitor_hla_settings ได้: " . htmlspecialchars(mysqli_error($con)) . "</li>";
        }
        $hla_defaults = [
            'hla_lab_items_code' => '2281',
            'hla_lookback_months' => '24',
            'hla_ptnote_regex' => 'ผ่านการตัดกรองยีนแพ้ยา|HLA-B',
            'hla_rxnote_regex' => 'ยีนแพ้ยา|HLA-B|ตรวจยีน',
            'hla_exclude_hns' => '',
        ];
        foreach ($hla_defaults as $k => $v) {
            $k_esc = mysqli_real_escape_string($con, $k);
            $chk = mysqli_query($con, "SELECT config_name FROM sys_config WHERE config_name = '$k_esc' LIMIT 1");
            if ($chk && mysqli_num_rows($chk) === 0) {
                $v_esc = mysqli_real_escape_string($con, $v);
                mysqli_query($con, "INSERT INTO sys_config (config_name, config_value) VALUES ('$k_esc', '$v_esc')");
            }
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.1.3' WHERE config_name = 'db_version'");
        $db_version = '1.1.3';
    }

    if (version_compare($db_version, '1.1.4', '<')) {
        $sql_sp = @file_get_contents(__DIR__ . '/sql/smart_pharma.sql');
        if ($sql_sp && mysqli_multi_query($con, $sql_sp)) {
            while (mysqli_more_results($con)) {
                mysqli_next_result($con);
            }
            echo "<li>✅ สร้างตาราง Smart Pharma (smart_pharma_modules, pharmacist_interventions) สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถสร้างตาราง Smart Pharma ได้: " . htmlspecialchars(mysqli_error($con)) . "</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.1.4' WHERE config_name = 'db_version'");
        $db_version = '1.1.4';
    }

    if (version_compare($db_version, '1.1.5', '<')) {
        $sql_tg = @file_get_contents(__DIR__ . '/sql/smart_pharma_telegram.sql');
        if ($sql_tg && mysqli_multi_query($con, $sql_tg)) {
            while (mysqli_more_results($con)) {
                mysqli_next_result($con);
            }
            echo "<li>✅ สร้างตาราง Smart Pharma Telegram สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถสร้างตาราง Telegram ได้: " . htmlspecialchars(mysqli_error($con)) . "</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.1.5' WHERE config_name = 'db_version'");
        $db_version = '1.1.5';
    }

    if (version_compare($db_version, '1.1.6', '<')) {
        require_once __DIR__ . '/smart_pharma_alert_log_helper.php';
        if (sp_alert_log_ensure_tables($con)) {
            echo "<li>✅ สร้างตาราง Smart Pharma Alert Log และคอลัมน์ Intervention สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถสร้างตาราง Alert Log ได้: " . htmlspecialchars(mysqli_error($con)) . "</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.1.6' WHERE config_name = 'db_version'");
        $db_version = '1.1.6';
    }

    if (version_compare($db_version, '1.1.7', '<')) {
        require_once __DIR__ . '/smart_pharma_telegram_helper.php';
        if (sp_telegram_ensure_tables($con)) {
            echo "<li>✅ เพิ่มคอลัมน์ Bot Token ต่อกลุ่ม Telegram สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถอัปเดตตาราง Telegram ได้: " . htmlspecialchars(mysqli_error($con)) . "</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.1.7' WHERE config_name = 'db_version'");
        $db_version = '1.1.7';
    }

    if (version_compare($db_version, '1.1.8', '<')) {
        require_once __DIR__ . '/smart_pharma_telegram_helper.php';
        if (sp_telegram_ensure_tables($con)) {
            echo "<li>✅ เพิ่มลิงก์/ข้อความแนบ Telegram Alert สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถอัปเดต Telegram link ได้: " . htmlspecialchars(mysqli_error($con)) . "</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.1.8' WHERE config_name = 'db_version'");
        $db_version = '1.1.8';
    }

    if (version_compare($db_version, '1.1.9', '<')) {
        require_once __DIR__ . '/smart_pharma_webhook_helper.php';
        if (sp_webhook_ensure_tables($con)) {
            echo "<li>✅ สร้างตาราง Smart Pharma Webhook สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถสร้างตาราง Webhook ได้: " . htmlspecialchars(mysqli_error($con)) . "</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.1.9' WHERE config_name = 'db_version'");
        $db_version = '1.1.9';
    }

    if (version_compare($db_version, '1.2.0', '<')) {
        require_once __DIR__ . '/smart_pharma_telegram_helper.php';
        require_once __DIR__ . '/smart_pharma_webhook_helper.php';
        $tg_ok = sp_telegram_ensure_tables($con);
        $wh_ok = sp_webhook_ensure_tables($con);
        if ($tg_ok && $wh_ok) {
            echo "<li>✅ เพิ่มการตั้งค่า Hard Alert แจ้งซ้ำ (Telegram + Webhook) สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถอัปเดต Hard Alert repeat ได้: " . htmlspecialchars(mysqli_error($con)) . "</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.2.0' WHERE config_name = 'db_version'");
        $db_version = '1.2.0';
    }

    if (version_compare($db_version, '1.2.1', '<')) {
        require_once __DIR__ . '/monitor_drug_usage_helper.php';
        if (mdu_ensure_table($con) && mdu_seed_default_rules($con)) {
            echo "<li>✅ สร้างตาราง Monitor วิธีใช้ยา + ตัวอย่าง Methotrexate (icode 1000146) สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถสร้างตาราง Monitor วิธีใช้ยาได้: " . htmlspecialchars(mysqli_error($con)) . "</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.2.1' WHERE config_name = 'db_version'");
        $db_version = '1.2.1';
    }

    if (version_compare($db_version, '1.2.2', '<')) {
        require_once __DIR__ . '/monitor_drug_allergy_helper.php';
        $ok_tbl = mda_ensure_tables($con);
        $ok_cfg = mda_ensure_config($con);
        $ok_seed = mda_seed_cross_rules($con);
        if ($ok_tbl && $ok_cfg && $ok_seed) {
            echo "<li>✅ สร้าง Monitor Drug Allergy (TMT + ข้ามกลุ่ม) + กฎเริ่มต้น Penicillin/NSAID/Sulfa สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถสร้าง Monitor Drug Allergy ได้: " . htmlspecialchars(mysqli_error($con)) . "</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.2.2' WHERE config_name = 'db_version'");
        $db_version = '1.2.2';
    }

    if (version_compare($db_version, '1.2.3', '<')) {
        require_once __DIR__ . '/drug_reconciliation_helper.php';
        if (dr_ensure_tables($con)) {
            echo "<li>✅ สร้างตาราง Drug Reconciliation (session + items) สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถสร้างตาราง Drug Reconciliation ได้: " . htmlspecialchars(mysqli_error($con)) . "</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.2.3' WHERE config_name = 'db_version'");
        $db_version = '1.2.3';
    }

    if (version_compare($db_version, '1.2.4', '<')) {
        require_once __DIR__ . '/monitor_max_dose_helper.php';
        if (mmdd_ensure_table($con)) {
            echo "<li>✅ สร้าง Monitor Max Dose/Duration สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถสร้าง Monitor Max Dose/Duration ได้: " . htmlspecialchars(mysqli_error($con)) . "</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.2.4' WHERE config_name = 'db_version'");
        $db_version = '1.2.4';
    }

    if (version_compare($db_version, '1.2.5', '<')) {
        require_once __DIR__ . '/monitor_opd_order_qty_helper.php';
        if (moq_ensure_table($con)) {
            echo "<li>✅ สร้าง Monitor จำนวนสั่งยา OPD สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถสร้าง Monitor จำนวนสั่งยา OPD ได้: " . htmlspecialchars(mysqli_error($con)) . "</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.2.5' WHERE config_name = 'db_version'");
        $db_version = '1.2.5';
    }

    if (version_compare($db_version, '1.2.6', '<')) {
        require_once __DIR__ . '/monitor_opd_order_qty_helper.php';
        if (moq_upgrade_schema($con)) {
            echo "<li>✅ เพิ่มกฎกลุ่มยาใช้ต่อเนื่องใน Monitor จำนวนสั่งยา OPD สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถอัปเดต Monitor จำนวนสั่งยา OPD ได้: " . htmlspecialchars(mysqli_error($con)) . "</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.2.6' WHERE config_name = 'db_version'");
        $db_version = '1.2.6';
    }

    if (version_compare($db_version, '1.2.7', '<')) {
        require_once __DIR__ . '/monitor_opd_order_qty_helper.php';
        if (moq_upgrade_schema($con)) {
            echo "<li>✅ เพิ่มรายการยกเว้นในกฎกลุ่มยาใช้ต่อเนื่องสำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถอัปเดต Monitor จำนวนสั่งยา OPD ได้: " . htmlspecialchars(mysqli_error($con)) . "</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.2.7' WHERE config_name = 'db_version'");
        $db_version = '1.2.7';
    }

    if (version_compare($db_version, '1.2.8', '<')) {
        require_once __DIR__ . '/moq_method_helper.php';
        if (moq_method_ensure_table($con)) {
            $seeded = moq_method_seed_defaults($con);
            echo "<li>✅ สร้างนิยามวิธีใช้ Monitor OPD" . ($seeded > 0 ? " (เติมค่าเริ่มต้น $seeded รายการ)" : '') . "</li>";
        } else {
            echo "<li>❌ ไม่สามารถสร้างนิยามวิธีใช้ได้: " . htmlspecialchars(mysqli_error($con)) . "</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.2.8' WHERE config_name = 'db_version'");
        $db_version = '1.2.8';
    }

    if (version_compare($db_version, '1.2.9', '<')) {
        require_once __DIR__ . '/monitor_opd_order_qty_helper.php';
        if (moq_upgrade_schema($con)) {
            echo "<li>✅ เพิ่มอนุโลมยาขาดใน Monitor จำนวนสั่งยา OPD สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถอัปเดต Monitor จำนวนสั่งยา OPD ได้: " . htmlspecialchars(mysqli_error($con)) . "</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.2.9' WHERE config_name = 'db_version'");
        $db_version = '1.2.9';
    }

    if (version_compare($db_version, '1.3.0', '<')) {
        require_once __DIR__ . '/monitor_mg_ml_helper.php';
        if (mmgml_ensure_table($con)) {
            echo "<li>✅ สร้างตาราง Monitor mg. to mL. สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถสร้าง Monitor mg. to mL. ได้: " . htmlspecialchars(mysqli_error($con)) . "</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.3.0' WHERE config_name = 'db_version'");
        $db_version = '1.3.0';
    }

    if (version_compare($db_version, '1.3.1', '<')) {
        require_once __DIR__ . '/rational_lab_use_helper.php';
        if (rlu_ensure_cache_table($con)) {
            echo "<li>✅ สร้างตาราง cache Rational Lab Use (รายเดือน) สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถสร้าง cache Rational Lab Use ได้: " . htmlspecialchars(mysqli_error($con)) . "</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.3.1' WHERE config_name = 'db_version'");
        $db_version = '1.3.1';
    }

    if (version_compare($db_version, '1.3.2', '<')) {
        require_once __DIR__ . '/menu_permissions_helper.php';
        if (tham_menu_ensure_tables($con)) {
            echo "<li>✅ สร้างตารางสิทธิ์เข้าถึงเมนู (Role / รายคน) สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถสร้างตารางสิทธิ์เมนูได้: " . htmlspecialchars(mysqli_error($con)) . "</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.3.2' WHERE config_name = 'db_version'");
        $db_version = '1.3.2';
    }

    if (version_compare($db_version, '1.3.3', '<')) {
        require_once __DIR__ . '/network_drug_helper.php';
        if (nd_ensure_tables($con)) {
            echo "<li>✅ สร้างตารางบัญชียาเครือข่าย (network_drug) สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถสร้างตารางบัญชียาเครือข่ายได้: " . htmlspecialchars(mysqli_error($con)) . "</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.3.3' WHERE config_name = 'db_version'");
        $db_version = '1.3.3';
    }

    if (version_compare($db_version, '1.3.4', '<')) {
        if (ensure_drug_safety_rules_patient_extra_columns($con)) {
            echo "<li>✅ เพิ่มคอลัมน์เงื่อนไขอายุ/น้ำหนัก ใน drug_safety_rules (Monitor Lab ไต) สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถเพิ่มคอลัมน์เงื่อนไขอายุ/น้ำหนัก ใน drug_safety_rules ได้</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.3.4' WHERE config_name = 'db_version'");
        $db_version = '1.3.4';
    }

    if (version_compare($db_version, '1.3.5', '<')) {
        require_once __DIR__ . '/controlled_drug_helper.php';
        if (cdm_ensure_tables($con)) {
            echo "<li>✅ สร้างตารางบริหารยาเสพติด/วัตถุออกฤทธิ์ (controlled_drug) สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถสร้างตารางบริหารยาเสพติด/วัตถุออกฤทธิ์ได้: " . htmlspecialchars(mysqli_error($con)) . "</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.3.5' WHERE config_name = 'db_version'");
        $db_version = '1.3.5';
    }

    if (version_compare($db_version, '1.3.6', '<')) {
        require_once __DIR__ . '/controlled_drug_helper.php';
        if (cdm_ensure_dispense_tables($con)) {
            echo "<li>✅ สร้างตารางบันทึกการจ่ายยาเสพติด/วัตถุออกฤทธิ์ สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถสร้างตารางบันทึกการจ่ายยาได้: " . htmlspecialchars(mysqli_error($con)) . "</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.3.6' WHERE config_name = 'db_version'");
        $db_version = '1.3.6';
    }

    if (version_compare($db_version, '1.3.7', '<')) {
        require_once __DIR__ . '/controlled_drug_helper.php';
        if (cdm_ensure_dispense_tables($con)) {
            echo "<li>✅ เพิ่มฟิลด์ซากยาเสพติด/วัตถุออกฤทธิ์ (drug_remains_qty) สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถอัปเดตตารางบันทึกการจ่ายยาได้: " . htmlspecialchars(mysqli_error($con)) . "</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.3.7' WHERE config_name = 'db_version'");
        $db_version = '1.3.7';
    }

    if (version_compare($db_version, '1.3.8', '<')) {
        require_once __DIR__ . '/controlled_drug_helper.php';
        if (cdm_ensure_dispense_tables($con)) {
            echo "<li>✅ เพิ่มฟิลด์รับซาก (drug_remains_received_qty) สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถอัปเดตตารางบันทึกการจ่ายยาได้: " . htmlspecialchars(mysqli_error($con)) . "</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.3.8' WHERE config_name = 'db_version'");
        $db_version = '1.3.8';
    }

    if (version_compare($db_version, '1.3.9', '<')) {
        require_once __DIR__ . '/controlled_drug_helper.php';
        if (cdm_ensure_lot_minmax_tables($con)) {
            echo "<li>✅ เพิ่ม Lot/EXP และ Min/Max สต๊อกยาเสพติด/วัตถุออกฤทธิ์ สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถอัปเดต Lot/Min-Max ได้: " . htmlspecialchars(mysqli_error($con)) . "</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.3.9' WHERE config_name = 'db_version'");
        $db_version = '1.3.9';
    }

    if (version_compare($db_version, '1.4.0', '<')) {
        require_once __DIR__ . '/controlled_drug_helper.php';
        if (cdm_ensure_dispense_tables($con)) {
            echo "<li>✅ เพิ่มผู้จัดเตรียมยาและสถานะ workflow (จัดเตรียม/จ่าย) สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถอัปเดตตารางบันทึกการจ่ายยาได้: " . htmlspecialchars(mysqli_error($con)) . "</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.4.0' WHERE config_name = 'db_version'");
        $db_version = '1.4.0';
    }

    if (version_compare($db_version, '1.5.0', '<')) {
        require_once __DIR__ . '/controlled_drug_helper.php';
        if (cdm_ensure_room_tables($con)) {
            echo "<li>✅ เพิ่มระบบห้องสต๊อก (แยกคลัง IPD/OPD และเพิ่มห้องได้) สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถอัปเดตตารางห้องสต๊อกได้: " . htmlspecialchars(mysqli_error($con)) . "</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.5.0' WHERE config_name = 'db_version'");
        $db_version = '1.5.0';
    }

    if (version_compare($db_version, '1.5.1', '<')) {
        require_once __DIR__ . '/user_access_helper.php';
        if (tham_user_access_ensure_tables($con)) {
            echo "<li>✅ เพิ่มระบบบันทึก log การเข้าใช้งาน (user_access_log) สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถสร้างตาราง log การเข้าใช้งานได้: " . htmlspecialchars(mysqli_error($con)) . "</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.5.1' WHERE config_name = 'db_version'");
        $db_version = '1.5.1';
    }

    if (version_compare($db_version, '1.5.2', '<')) {
        require_once __DIR__ . '/monitor_hla_helper.php';
        if (ensure_monitor_hla_alert_level_column($con)) {
            echo "<li>✅ เพิ่มคอลัมน์ alert_level (Hard / Soft Alert) ใน monitor_hla_settings สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถเพิ่มคอลัมน์ alert_level ใน monitor_hla_settings ได้</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.5.2' WHERE config_name = 'db_version'");
        $db_version = '1.5.2';
    }

    if (version_compare($db_version, '1.5.3', '<')) {
        require_once __DIR__ . '/license_admin_helper.php';
        if (license_admin_ensure_table($con)) {
            echo "<li>✅ สร้างตาราง tb_deployments (License Map Tracker) สำเร็จ</li>";
        } else {
            echo "<li>❌ ไม่สามารถสร้างตาราง tb_deployments ได้: " . htmlspecialchars(mysqli_error($con)) . "</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.5.3' WHERE config_name = 'db_version'");
        $db_version = '1.5.3';
    }

    if (version_compare($db_version, '1.5.4', '<')) {
        // รพ.ทั่วไป: ลบเครื่องมือสร้าง License ออกถ้าไม่ได้เปิดโหมดเซิร์ฟเวอร์กลาง
        if (is_file(__DIR__ . '/deployment_map_config.php')) {
            require_once __DIR__ . '/deployment_map_config.php';
        }
        if (is_file(__DIR__ . '/deployment_map_local.php')) {
            require_once __DIR__ . '/deployment_map_local.php';
        }
        $is_license_admin = defined('THAM_LICENSE_ADMIN_ENABLED') && THAM_LICENSE_ADMIN_ENABLED;
        if (!$is_license_admin) {
            foreach (['admin_generate_key.php', 'admin_license_generator.php', 'license_admin_config.php'] as $admin_file) {
                $path = __DIR__ . '/' . $admin_file;
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            echo "<li>✅ ลบเครื่องมือ License ของเซิร์ฟเวอร์กลางออกจากเครื่อง รพ. แล้ว</li>";
        } else {
            echo "<li>✅ โหมดเซิร์ฟเวอร์กลาง — คงเครื่องมือสร้าง License ไว้</li>";
        }
        mysqli_query($con, "UPDATE sys_config SET config_value = '1.5.4' WHERE config_name = 'db_version'");
        $db_version = '1.5.4';
    }

    echo "</ul>";
    echo "<h3 style='color: #27ae60;'>🎉 อัปเดตฐานข้อมูลเสร็จสมบูรณ์!</h3>";
} else {
    echo "<span style='color:#27ae60;'>✔️ โครงสร้างฐานข้อมูลสมบูรณ์ ตรงกับเวอร์ชันโปรแกรมแล้ว</span>";
}
echo "</div>";

echo "<div style='text-align: center;'><a href='home.php' style='display: inline-block; padding: 10px 25px; background-color: #1f4d3b; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;'>กลับสู่หน้าหลัก</a></div>";
echo "</div>";

mysqli_close($con);
?>