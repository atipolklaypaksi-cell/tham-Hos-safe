<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
include('connect.php');
mysqli_set_charset($con, "utf8");

// 1. ดึงค่า Config เดิมจากฐานข้อมูลมาเก็บในรูปแบบ Array
$config = [];
$q = mysqli_query($con, "SELECT config_name, config_value FROM sys_config");
if($q){
    while($r = mysqli_fetch_assoc($q)){
        $config[$r['config_name']] = $r['config_value'];
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ตั้งค่าการเชื่อมต่อ HOSxP - THAM SAFE</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="wrapper">
    <?php include('sidebar.php'); ?>

    <div id="content" class="content p-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold text-dark"><i class="fas fa-cogs me-2 text-warning"></i>ตั้งค่าระบบเชื่อมต่อ HOSxP</h4>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <form id="configForm" onsubmit="saveConfig(event)">
                    
                    <h6 class="fw-bold text-primary border-bottom pb-2 mb-3"><i class="fas fa-network-wired me-2"></i>1. ข้อมูลฐานข้อมูล (Database Connection)</h6>
                    <div class="row g-3 mb-4 bg-white p-3 border rounded">
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Host / IP</label>
                            <input type="text" class="form-control border-primary" name="h_host" id="h_host" value="<?php echo htmlspecialchars($config['h_host'] ?? ''); ?>" placeholder="เช่น 192.168.1.5">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Database Name</label>
                            <input type="text" class="form-control border-primary" name="h_db" id="h_db" value="<?php echo htmlspecialchars($config['h_db'] ?? 'hos'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Username</label>
                            <input type="text" class="form-control border-primary" name="h_user" id="h_user" value="<?php echo htmlspecialchars($config['h_user'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Password</label>
                            <input type="password" class="form-control border-primary" name="h_pass" id="h_pass" value="<?php echo htmlspecialchars($config['h_pass'] ?? ''); ?>">
                        </div>
                    </div>

                    <h6 class="fw-bold text-success border-bottom pb-2 mb-3"><i class="fas fa-table me-2"></i>2. การจับคู่ตารางและฟิลด์ (Table Mapping)</h6>
                    <div class="row g-3 mb-4 bg-white p-3 border rounded">
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-muted small">ตารางเก็บน้ำหนัก/ส่วนสูง</label>
                            <input type="text" class="form-control" name="h_tbl_vital" value="<?php echo htmlspecialchars($config['h_tbl_vital'] ?? 'opdscreen'); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-muted small">ฟิลด์น้ำหนัก</label>
                            <input type="text" class="form-control" name="h_fld_bw" value="<?php echo htmlspecialchars($config['h_fld_bw'] ?? 'bw'); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-muted small">ฟิลด์ส่วนสูง</label>
                            <input type="text" class="form-control" name="h_fld_height" value="<?php echo htmlspecialchars($config['h_fld_height'] ?? 'height'); ?>">
                        </div>
                    </div>
                        
                    <h6 class="fw-bold text-danger border-bottom pb-2 mb-3 mt-4"><i class="fas fa-microscope me-2"></i>3. การตั้งค่า Lab (ตรวจสอบรหัสกับ HOSxP)</h6>
                    <div class="row g-3 mb-4 bg-white p-3 border rounded">
                        
                        <div class="col-md-12 mb-1"><strong class="text-secondary"><i class="fas fa-heartbeat me-1"></i> Renal Function</strong></div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-muted small">Lab Code: Serum Cr</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" id="h_code_scr" name="h_code_scr" value="<?php echo htmlspecialchars($config['h_code_scr'] ?? ''); ?>" placeholder="เช่น 114">
                                <button class="btn btn-outline-secondary" type="button" onclick="verifyLabCode('h_code_scr')"><i class="fas fa-search"></i> ตรวจสอบ</button>
                            </div>
                            <div id="lbl_h_code_scr" class="form-text text-primary fw-bold" style="min-height: 20px;"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-muted small">Lab Code: CKD-EPI</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" id="h_code_ckd_epi" name="h_code_ckd_epi" value="<?php echo htmlspecialchars($config['h_code_ckd_epi'] ?? ''); ?>" placeholder="เช่น 1809">
                                <button class="btn btn-outline-secondary" type="button" onclick="verifyLabCode('h_code_ckd_epi')"><i class="fas fa-search"></i> ตรวจสอบ</button>
                            </div>
                            <div id="lbl_h_code_ckd_epi" class="form-text text-primary fw-bold" style="min-height: 20px;"></div>
                        </div>
                        
                        <div class="col-md-12 mt-3 mb-1"><strong class="text-secondary"><i class="fas fa-tint me-1"></i> CBC</strong></div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-muted small">Lab Code: WBC</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" id="h_code_wbc" name="h_code_wbc" value="<?php echo htmlspecialchars($config['h_code_wbc'] ?? ''); ?>">
                                <button class="btn btn-outline-secondary" type="button" onclick="verifyLabCode('h_code_wbc')"><i class="fas fa-search"></i></button>
                            </div>
                            <div id="lbl_h_code_wbc" class="form-text text-primary fw-bold" style="min-height: 20px;"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-muted small">Lab Code: %Neutrophil (ANC)</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" id="h_code_neutrophil" name="h_code_neutrophil" value="<?php echo htmlspecialchars($config['h_code_neutrophil'] ?? ''); ?>">
                                <button class="btn btn-outline-secondary" type="button" onclick="verifyLabCode('h_code_neutrophil')"><i class="fas fa-search"></i></button>
                            </div>
                            <div id="lbl_h_code_neutrophil" class="form-text text-primary fw-bold" style="min-height: 20px;"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-muted small">Lab Code: Platelet</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" id="h_code_platelet" name="h_code_platelet" value="<?php echo htmlspecialchars($config['h_code_platelet'] ?? ''); ?>">
                                <button class="btn btn-outline-secondary" type="button" onclick="verifyLabCode('h_code_platelet')"><i class="fas fa-search"></i></button>
                            </div>
                            <div id="lbl_h_code_platelet" class="form-text text-primary fw-bold" style="min-height: 20px;"></div>
                        </div>

                        <div class="col-md-12 mt-3 mb-1"><strong class="text-secondary"><i class="fas fa-lungs me-1"></i> Hepatic Function</strong></div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-muted small">Lab Code: Total Bilirubin</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" id="h_code_tbili" name="h_code_tbili" value="<?php echo htmlspecialchars($config['h_code_tbili'] ?? ''); ?>">
                                <button class="btn btn-outline-secondary" type="button" onclick="verifyLabCode('h_code_tbili')"><i class="fas fa-search"></i></button>
                            </div>
                            <div id="lbl_h_code_tbili" class="form-text text-primary fw-bold" style="min-height: 20px;"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-muted small">Lab Code: Direct Bilirubin</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" id="h_code_dbili" name="h_code_dbili" value="<?php echo htmlspecialchars($config['h_code_dbili'] ?? ''); ?>">
                                <button class="btn btn-outline-secondary" type="button" onclick="verifyLabCode('h_code_dbili')"><i class="fas fa-search"></i></button>
                            </div>
                            <div id="lbl_h_code_dbili" class="form-text text-primary fw-bold" style="min-height: 20px;"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-muted small">Lab Code: Indirect Bilirubin</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" id="h_code_ibili" name="h_code_ibili" value="<?php echo htmlspecialchars($config['h_code_ibili'] ?? ''); ?>">
                                <button class="btn btn-outline-secondary" type="button" onclick="verifyLabCode('h_code_ibili')"><i class="fas fa-search"></i></button>
                            </div>
                            <div id="lbl_h_code_ibili" class="form-text text-primary fw-bold" style="min-height: 20px;"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-muted small">Lab Code: AST (SGOT)</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" id="h_code_ast" name="h_code_ast" value="<?php echo htmlspecialchars($config['h_code_ast'] ?? ''); ?>">
                                <button class="btn btn-outline-secondary" type="button" onclick="verifyLabCode('h_code_ast')"><i class="fas fa-search"></i></button>
                            </div>
                            <div id="lbl_h_code_ast" class="form-text text-primary fw-bold" style="min-height: 20px;"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-muted small">Lab Code: ALT (SGPT)</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" id="h_code_alt" name="h_code_alt" value="<?php echo htmlspecialchars($config['h_code_alt'] ?? ''); ?>">
                                <button class="btn btn-outline-secondary" type="button" onclick="verifyLabCode('h_code_alt')"><i class="fas fa-search"></i></button>
                            </div>
                            <div id="lbl_h_code_alt" class="form-text text-primary fw-bold" style="min-height: 20px;"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-muted small">ULN อ้างอิง: T.Bilirubin (mg/dL)</label>
                            <input type="number" step="0.001" class="form-control form-control-sm" name="lab_tbili_uln" value="<?php echo htmlspecialchars($config['lab_tbili_uln'] ?? '1.2'); ?>" placeholder="เช่น 1.2">
                            <div class="form-text text-muted">ใช้คำนวณ T.Bilirubin เป็นเท่าของ ULN</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-muted small">ULN อ้างอิง: AST (U/L)</label>
                            <input type="number" step="0.01" class="form-control form-control-sm" name="lab_ast_uln" value="<?php echo htmlspecialchars($config['lab_ast_uln'] ?? '40'); ?>" placeholder="เช่น 40">
                            <div class="form-text text-muted">ใช้คำนวณ AST เป็นเท่าของ ULN</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-muted small">ULN อ้างอิง: ALT (U/L)</label>
                            <input type="number" step="0.01" class="form-control form-control-sm" name="lab_alt_uln" value="<?php echo htmlspecialchars($config['lab_alt_uln'] ?? '40'); ?>" placeholder="เช่น 40">
                            <div class="form-text text-muted">ใช้คำนวณ ALT เป็นเท่าของ ULN</div>
                        </div>

                        <div class="col-md-12 mt-3 mb-1"><strong class="text-secondary"><i class="fas fa-virus me-1"></i> Infection / Virology <small class="text-muted fw-normal">(HBV / HIV / HCV)</small></strong></div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-muted small">Lab Code: HBsAg</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" id="h_code_hbsag" name="h_code_hbsag" value="<?php echo htmlspecialchars($config['h_code_hbsag'] ?? ''); ?>">
                                <button class="btn btn-outline-secondary" type="button" onclick="verifyLabCode('h_code_hbsag')"><i class="fas fa-search"></i></button>
                            </div>
                            <div id="lbl_h_code_hbsag" class="form-text text-primary fw-bold" style="min-height: 20px;"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-muted small">Lab Code: Anti-HBc</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" id="h_code_antihbc" name="h_code_antihbc" value="<?php echo htmlspecialchars($config['h_code_antihbc'] ?? ''); ?>">
                                <button class="btn btn-outline-secondary" type="button" onclick="verifyLabCode('h_code_antihbc')"><i class="fas fa-search"></i></button>
                            </div>
                            <div id="lbl_h_code_antihbc" class="form-text text-primary fw-bold" style="min-height: 20px;"></div>
                        </div>

                        <div class="col-md-12 mt-3 mb-1"><strong class="text-secondary"><i class="fas fa-flask me-1"></i> Urinalysis</strong></div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-muted small">Lab Code: Urine pH</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" id="h_code_urine_ph" name="h_code_urine_ph" value="<?php echo htmlspecialchars($config['h_code_urine_ph'] ?? ''); ?>">
                                <button class="btn btn-outline-secondary" type="button" onclick="verifyLabCode('h_code_urine_ph')"><i class="fas fa-search"></i></button>
                            </div>
                            <div id="lbl_h_code_urine_ph" class="form-text text-primary fw-bold" style="min-height: 20px;"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-muted small">Lab Code: Urine Specific Gravity</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" id="h_code_urine_sg" name="h_code_urine_sg" value="<?php echo htmlspecialchars($config['h_code_urine_sg'] ?? ''); ?>">
                                <button class="btn btn-outline-secondary" type="button" onclick="verifyLabCode('h_code_urine_sg')"><i class="fas fa-search"></i></button>
                            </div>
                            <div id="lbl_h_code_urine_sg" class="form-text text-primary fw-bold" style="min-height: 20px;"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-muted small">Lab Code: Urine Protein</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" id="h_code_urine_protein" name="h_code_urine_protein" value="<?php echo htmlspecialchars($config['h_code_urine_protein'] ?? ''); ?>">
                                <button class="btn btn-outline-secondary" type="button" onclick="verifyLabCode('h_code_urine_protein')"><i class="fas fa-search"></i></button>
                            </div>
                            <div id="lbl_h_code_urine_protein" class="form-text text-primary fw-bold" style="min-height: 20px;"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-muted small">Lab Code: Urine RBC</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" id="h_code_urine_rbc" name="h_code_urine_rbc" value="<?php echo htmlspecialchars($config['h_code_urine_rbc'] ?? ''); ?>">
                                <button class="btn btn-outline-secondary" type="button" onclick="verifyLabCode('h_code_urine_rbc')"><i class="fas fa-search"></i></button>
                            </div>
                            <div id="lbl_h_code_urine_rbc" class="form-text text-primary fw-bold" style="min-height: 20px;"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-muted small">Lab Code: Urine Protein 24 hrs</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" id="h_code_urine_protein_24h" name="h_code_urine_protein_24h" value="<?php echo htmlspecialchars($config['h_code_urine_protein_24h'] ?? ''); ?>">
                                <button class="btn btn-outline-secondary" type="button" onclick="verifyLabCode('h_code_urine_protein_24h')"><i class="fas fa-search"></i></button>
                            </div>
                            <div id="lbl_h_code_urine_protein_24h" class="form-text text-primary fw-bold" style="min-height: 20px;"></div>
                        </div>

                    </div>

                    <h6 class="fw-bold text-info border-bottom pb-2 mb-3 mt-4"><i class="fas fa-magic me-2"></i>4. ตั้งค่าการดึงข้อมูลอัตโนมัติ (Auto Fetch)</h6>
                    <div class="row g-3 mb-4 bg-white p-3 border rounded">
                        <div class="col-md-12 mb-2">
                            <div class="form-check form-switch form-switch-lg">
                                <input type="hidden" name="h_auto_dose" value="0">
                                <input class="form-check-input" type="checkbox" id="chk_auto_dose" name="h_auto_dose" value="1" <?php echo (isset($config['h_auto_dose']) && $config['h_auto_dose'] == '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-bold ms-2 text-primary" for="chk_auto_dose" style="font-size: 1.1rem;">
                                    เปิดใช้งาน : ตรวจสอบและดึงขนาดยา (Dose) อัตโนมัติจาก HOSxP ของวันนี้
                                </label>
                            </div>
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label fw-bold text-muted small">SQL Query สำหรับดึงวิธีใช้ยา <span class="text-danger">(ใช้ {hn} และ {icodes} เป็นตัวแปรแทนค่า)</span></label>
                            <textarea class="form-control text-primary font-monospace" name="h_query_dose" rows="10" style="font-size: 0.85rem; background-color: #f8f9fa;"><?php 
                    echo htmlspecialchars($config['h_query_dose'] ?? "SELECT o.icode, 
                    IF(o.sp_use = '' OR o.sp_use IS NULL,
                     CAST(CONVERT(concat(IFNULL(du.name1,''),' ',IFNULL(du.name2,''),' ',IFNULL(du.name3,'')) USING utf8) AS binary),
                      CAST(CONVERT(concat(IFNULL(sp.name1,''),' ',IFNULL(sp.name2,''),' ',IFNULL(sp.name3,'')) USING utf8) AS binary)
                    ) as method
                    FROM opitemrece o
                    LEFT JOIN drugusage du ON du.drugusage = o.drugusage
                    LEFT JOIN sp_use sp ON sp.sp_use = o.sp_use
                    WHERE o.hn = '{hn}' 
                    AND o.vstdate = CURDATE() 
                    AND o.icode IN ({icodes})"); 
                            ?></textarea>
                            <div class="form-text text-danger"><i class="fas fa-exclamation-circle"></i> ข้อควรระวัง: ห้ามลบคำว่า <code>{hn}</code> และ <code>{icodes}</code> ออกจาก Query เด็ดขาด</div>
                        </div>
                    </div>
                    <hr>
                    <div class="text-end">
                        <button type="button" class="btn btn-outline-info fw-bold me-2" onclick="testConnection()">
                            <i class="fas fa-plug me-1"></i> ทดสอบการเชื่อมต่อ
                        </button>
                        <button type="submit" class="btn btn-warning px-5 fw-bold shadow-sm text-dark">
                            <i class="fas fa-save me-1"></i> บันทึกการตั้งค่า
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="js/jquery-3.7.0.min.js"></script>
<script src="js/bootstrap.bundle.min.js"></script>
<script>
    // 🎯 ฟังก์ชันตรวจสอบรหัส Lab จาก HOSxP
    function verifyLabCode(inputId) {
        let code = document.getElementById(inputId).value.trim();
        let displayLabel = document.getElementById('lbl_' + inputId);
        
        if (code === '') {
            displayLabel.innerHTML = '<span class="text-danger">กรุณาระบุรหัสก่อนครับ</span>';
            return;
        }

        // ดึงการตั้งค่าฐานข้อมูลปัจจุบันจากในฟอร์มเพื่อใช้เชื่อมต่อ
        let host = document.querySelector('input[name="h_host"]').value;
        let db = document.querySelector('input[name="h_db"]').value;
        let user = document.querySelector('input[name="h_user"]').value;
        let pass = document.querySelector('input[name="h_pass"]').value;

        displayLabel.innerHTML = '<span class="text-warning"><i class="fas fa-spinner fa-spin"></i> กำลังค้นหา...</span>';

        fetch('verify_lab_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ h_host: host, h_db: db, h_user: user, h_pass: pass, lab_code: code })
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                displayLabel.innerHTML = `<span class="text-success"><i class="fas fa-check-circle"></i> พบข้อมูล: ${data.lab_name}</span>`;
            } else {
                displayLabel.innerHTML = `<span class="text-danger"><i class="fas fa-times-circle"></i> ${data.message}</span>`;
            }
        })
        .catch(err => {
            console.error(err);
            displayLabel.innerHTML = '<span class="text-danger"><i class="fas fa-exclamation-triangle"></i> เชื่อมต่อฐานข้อมูลไม่สำเร็จ</span>';
        });
    }

    // ฟังก์ชันทดสอบการเชื่อมต่อ (ของเดิม)
    function testConnection() {
        let host = document.querySelector('input[name="h_host"]').value;
        let db = document.querySelector('input[name="h_db"]').value;
        let user = document.querySelector('input[name="h_user"]').value;
        let pass = document.querySelector('input[name="h_pass"]').value;
        
        let btn = document.querySelector('button[onclick="testConnection()"]');
        let originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> กำลังทดสอบ...';
        btn.disabled = true;

        fetch('test_hosxp_connection_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ h_host: host, h_db: db, h_user: user, h_pass: pass })
        })
        .then(async res => {
            const text = await res.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                throw new Error(res.ok
                    ? 'ไฟล์ API ตอบกลับไม่ใช่ JSON'
                    : `เรียกไฟล์ API ไม่สำเร็จ (HTTP ${res.status})`);
            }
        })
        .then(data => {
            if (data.status === 'success') {
                alert('✅ ' + data.message);
            } else if (data.status === 'warning') {
                alert('⚠️ ' + data.message);
            } else {
                alert('❌ ' + data.message);
            }
        })
        .catch(err => {
            console.error(err);
            alert('❌ ' + (err.message || 'เกิดข้อผิดพลาดในการเชื่อมต่อกับไฟล์ API'));
        })
        .finally(() => {
            btn.innerHTML = originalHtml;
            btn.disabled = false;
        });
    }

    // ฟังก์ชันบันทึกข้อมูล (ของเดิม)
    function saveConfig(event) {
        event.preventDefault(); 
        let form = document.getElementById('configForm');
        let formData = new FormData(form);

        let btn = document.querySelector('button[type="submit"]');
        let originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> กำลังบันทึก...';
        btn.disabled = true;

        fetch('save_hosxp_config.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                alert('✅ ' + data.message);
            } else {
                alert('❌ ' + (data.message || 'เกิดข้อผิดพลาดไม่ทราบสาเหตุ'));
            }
        })
        .catch(err => {
            console.error(err);
            alert('❌ เกิดข้อผิดพลาดในการส่งข้อมูล');
        })
        .finally(() => {
            btn.innerHTML = originalHtml;
            btn.disabled = false;
        });
    }
</script>
</body>
</html>