<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
include('connect.php');
mysqli_set_charset($con, 'utf8');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>หน้าหลัก - THAM SAFE</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="wrapper">
    <?php include('sidebar.php'); ?>

    <div id="content" class="content p-4">
        <div class="page-hero mb-4">
            <h2 class="fw-bold mb-1"><i class="fas fa-shield-virus me-2"></i>ยินดีต้อนรับ, <?php echo htmlspecialchars($_SESSION['full_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
            <p class="mb-0 text-muted">ระบบจัดการความปลอดภัยด้านยาและเชื่อมต่อ HOSxP</p>
        </div>

        <div class="row g-4">
            <div class="col-md-6 col-xl-4">
                <a href="monitor_drug_interaction.php" class="home-card">
                    <div class="home-card-icon text-danger"><i class="fas fa-pills"></i></div>
                    <h5 class="fw-bold">Monitor Interaction</h5>
                    <p class="text-muted mb-0 small">ตรวจสอบปฏิกิริยาระหว่างยา</p>
                </a>
            </div>
            <div class="col-md-6 col-xl-4">
                <a href="monitor_cumulative.php" class="home-card">
                    <div class="home-card-icon text-warning"><i class="fas fa-radiation-alt"></i></div>
                    <h5 class="fw-bold">Monitor Cumulative</h5>
                    <p class="text-muted mb-0 small">ติดตามปริมาณยาสะสม</p>
                </a>
            </div>
            <div class="col-md-6 col-xl-4">
                <a href="monitor_lab_renal.php" class="home-card">
                    <div class="home-card-icon text-primary"><i class="fas fa-heartbeat"></i></div>
                    <h5 class="fw-bold">Monitor Lab ไต</h5>
                    <p class="text-muted mb-0 small">เฝ้าระวัง CrCl / CKD-EPI</p>
                </a>
            </div>
            <div class="col-md-6 col-xl-4">
                <a href="monitor_lab_hepatic.php" class="home-card">
                    <div class="home-card-icon text-warning"><i class="fas fa-lungs"></i></div>
                    <h5 class="fw-bold">Monitor Lab ตับ</h5>
                    <p class="text-muted mb-0 small">เฝ้าระวัง AST / ALT / Bilirubin</p>
                </a>
            </div>
            <div class="col-md-6 col-xl-4">
                <a href="monitor_hbv.php" class="home-card">
                    <div class="home-card-icon text-danger"><i class="fas fa-virus"></i></div>
                    <h5 class="fw-bold">Monitor HBV</h5>
                    <p class="text-muted mb-0 small">คัดกรองและติดตาม HBV</p>
                </a>
            </div>
            <div class="col-md-6 col-xl-4">
                <a href="monitor_hla.php" class="home-card">
                    <div class="home-card-icon" style="color:#9b59b6;"><i class="fas fa-dna"></i></div>
                    <h5 class="fw-bold">Monitor ยีนแพ้ยา HLA</h5>
                    <p class="text-muted mb-0 small">คัดกรอง HLA-B ก่อนยาควบคุมโรคข้อ</p>
                </a>
            </div>
            <div class="col-md-6 col-xl-4">
                <a href="monitor_ua.php" class="home-card">
                    <div class="home-card-icon text-primary"><i class="fas fa-flask"></i></div>
                    <h5 class="fw-bold">Monitor UA</h5>
                    <p class="text-muted mb-0 small">ติดตามผลตรวจปัสสาวะ</p>
                </a>
            </div>
            <div class="col-md-6 col-xl-4">
                <a href="monitor_drug_usage.php" class="home-card">
                    <div class="home-card-icon" style="color:#0d9488;"><i class="fas fa-prescription-bottle-alt"></i></div>
                    <h5 class="fw-bold">Monitor วิธีใช้ยา</h5>
                    <p class="text-muted mb-0 small">ตรวจวิธีใช้ เช่น Methotrexate วัน/สัปดาห์</p>
                </a>
            </div>
            <div class="col-md-6 col-xl-4">
                <a href="monitor_max_dose.php" class="home-card">
                    <div class="home-card-icon" style="color:#7c3aed;"><i class="fas fa-tachometer-alt"></i></div>
                    <h5 class="fw-bold">Monitor Max Dose/Duration</h5>
                    <p class="text-muted mb-0 small">ตรวจ mg/วัน และระยะเวลาสั่งยาเกินขีดจำกัด</p>
                </a>
            </div>
            <div class="col-md-6 col-xl-4">
                <a href="monitor_mg_ml.php" class="home-card">
                    <div class="home-card-icon" style="color:#0d6efd;"><i class="fas fa-syringe"></i></div>
                    <h5 class="fw-bold">Monitor mg. to mL.</h5>
                    <p class="text-muted mb-0 small">ตรวจการแปลง mg → mL ในวิธีใช้ยา (IPD/OPD)</p>
                </a>
            </div>
            <div class="col-md-6 col-xl-4">
                <a href="monitor_opd_order_qty.php" class="home-card">
                    <div class="home-card-icon" style="color:#0891b2;"><i class="fas fa-calculator"></i></div>
                    <h5 class="fw-bold">Monitor จำนวนสั่งยา</h5>
                    <p class="text-muted mb-0 small">OPD — ตรวจ qty ถึงวันนัด (ไม่ให้ขาด / เกินได้ 10)</p>
                </a>
            </div>
            <div class="col-md-6 col-xl-4">
                <a href="monitor_drug_allergy.php" class="home-card">
                    <div class="home-card-icon text-danger"><i class="fas fa-allergies"></i></div>
                    <h5 class="fw-bold">Monitor Drug Allergy</h5>
                    <p class="text-muted mb-0 small">ตรวจแพ้ยาด้วยชื่อ TMT + ข้ามกลุ่ม</p>
                </a>
            </div>
            <div class="col-md-6 col-xl-4">
                <a href="drug_reconciliation.php" class="home-card">
                    <div class="home-card-icon text-success"><i class="fas fa-exchange-alt"></i></div>
                    <h5 class="fw-bold">Drug Reconciliation</h5>
                    <p class="text-muted mb-0 small">เปรียบเทียบยาเดิม/ใหม่ ส่งแพทย์ Continue Hold Off</p>
                </a>
            </div>
            <div class="col-md-6 col-xl-4">
                <a href="smart_pharma_opd.php" class="home-card">
                    <div class="home-card-icon" style="color:#17a2b8;"><i class="fas fa-hospital-user"></i></div>
                    <h5 class="fw-bold">Smart Pharma OPD</h5>
                    <p class="text-muted mb-0 small">คัดกรองผู้ป่วยนอกรายวัน</p>
                </a>
            </div>
            <div class="col-md-6 col-xl-4">
                <a href="smart_pharma_ipd.php" class="home-card">
                    <div class="home-card-icon" style="color:#0d9488;"><i class="fas fa-procedures"></i></div>
                    <h5 class="fw-bold">Smart Pharma IPD</h5>
                    <p class="text-muted mb-0 small">คัดกรองผู้ป่วยในรายวัน</p>
                </a>
            </div>
            <div class="col-md-6 col-xl-4">
                <a href="hosxp_setting.php" class="home-card">
                    <div class="home-card-icon text-olive"><i class="fas fa-network-wired"></i></div>
                    <h5 class="fw-bold">ตั้งค่า HOSxP</h5>
                    <p class="text-muted mb-0 small">เชื่อมต่อและกำหนด Lab Code</p>
                </a>
            </div>
        </div>
    </div>
</div>
<script src="js/jquery-3.7.0.min.js"></script>
<script src="js/bootstrap.bundle.min.js"></script>
</body>
</html>
