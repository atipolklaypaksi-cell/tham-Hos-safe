<?php
session_start();
if (!isset($_SESSION['user_id']) && !isset($_SESSION['full_name'])) {
    header('Location: login.php');
    exit;
}
include('connect.php');
include('drug_reconciliation_helper.php');
mysqli_set_charset($con, 'utf8');
dr_ensure_tables($con);

$id = (int)($_GET['id'] ?? 0);
$session = dr_load_session($con, $id);
if (!$session) {
    die('ไม่พบรายการ Drug Reconciliation');
}
$pairs = dr_pairs_from_session_items($session['items']['baseline'], $session['items']['current']);
session_write_close();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Drug Reconciliation #<?php echo $id; ?></title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', Tahoma, sans-serif; font-size: 13px; color: #111; }
        .print-header { border-bottom: 2px solid #1f4d3b; margin-bottom: 16px; padding-bottom: 10px; }
        .meta-table td { padding: 2px 12px 2px 0; vertical-align: top; }
        table.dr-table { width: 100%; border-collapse: collapse; }
        table.dr-table th, table.dr-table td { border: 1px solid #333; padding: 6px 8px; vertical-align: top; }
        table.dr-table th { background: #e8f5ef; }
        .action-continue { color: #0d6efd; font-weight: 700; }
        .action-hold { color: #fd7e14; font-weight: 700; }
        .action-off { color: #dc3545; font-weight: 700; }
        @media print {
            .no-print { display: none !important; }
            body { margin: 0; }
        }
    </style>
</head>
<body>
<div class="container-fluid p-4">
    <div class="no-print mb-3">
        <button class="btn btn-primary" onclick="window.print()">พิมพ์</button>
        <a class="btn btn-secondary" href="drug_reconciliation.php">กลับ</a>
    </div>

    <div class="print-header">
        <h4 class="mb-1">Drug Reconciliation — รายการยาเพื่อแพทย์พิจารณา</h4>
        <small>THAM Hos-Safe | รหัสรายการ #<?php echo $id; ?></small>
    </div>

    <table class="meta-table mb-3">
        <tr><td><strong>HN</strong></td><td><?php echo htmlspecialchars($session['hn']); ?></td>
            <td><strong>ชื่อผู้ป่วย</strong></td><td><?php echo htmlspecialchars($session['patient_name']); ?></td></tr>
        <tr><td><strong>AN/VN</strong></td><td><?php echo htmlspecialchars($session['an'] ?: $session['vn']); ?></td>
            <td><strong>แพทย์เจ้าของไข้</strong></td><td><?php echo htmlspecialchars($session['doctor_name']); ?></td></tr>
        <tr><td><strong>หอผู้ป่วย</strong></td><td><?php echo htmlspecialchars($session['ward_name']); ?></td>
            <td><strong>เภสัชกร</strong></td><td><?php echo htmlspecialchars($session['pharmacist_name']); ?></td></tr>
        <tr><td><strong>สถานะ</strong></td><td><?php echo htmlspecialchars($session['status']); ?></td>
            <td><strong>วันที่ส่ง</strong></td><td><?php echo htmlspecialchars($session['sent_at'] ?: $session['created_at']); ?></td></tr>
    </table>

    <?php if (!empty($session['pharmacist_note'])): ?>
    <div class="mb-3"><strong>หมายเหตุจากเภสัชกร:</strong><br><?php echo nl2br(htmlspecialchars($session['pharmacist_note'])); ?></div>
    <?php endif; ?>

    <table class="dr-table">
        <thead>
            <tr>
                <th style="width:4%">#</th>
                <th style="width:22%">ยาเดิม (Baseline)</th>
                <th style="width:22%">ยาใหม่ (Current)</th>
                <th style="width:18%">วิธีใช้เดิม</th>
                <th style="width:18%">วิธีใช้ใหม่</th>
                <th style="width:10%">คำแนะนำ</th>
                <th style="width:6%">หมายเหตุ</th>
            </tr>
        </thead>
        <tbody>
        <?php $n = 0; foreach ($pairs as $p): $n++;
            $b = $p['baseline'];
            $c = $p['current'];
            $act = $p['recon_action'] ?? '';
            $act_class = 'action-' . ($act ?: 'none');
        ?>
            <tr>
                <td><?php echo $n; ?></td>
                <td>
                    <?php if ($b): ?>
                        <strong><?php echo htmlspecialchars($b['drug_name_tmt'] ?: $b['drug_name']); ?></strong>
                        <?php if ($b['icode']): ?><br><small>icode: <?php echo htmlspecialchars($b['icode']); ?></small><?php endif; ?>
                        <br><small class="text-muted"><?php echo htmlspecialchars($b['source_label']); ?></small>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td>
                    <?php if ($c): ?>
                        <strong><?php echo htmlspecialchars($c['drug_name_tmt'] ?: $c['drug_name']); ?></strong>
                        <?php if ($c['icode']): ?><br><small>icode: <?php echo htmlspecialchars($c['icode']); ?></small><?php endif; ?>
                        <br><small class="text-muted"><?php echo htmlspecialchars($c['source_label']); ?></small>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td><?php echo $b ? htmlspecialchars($b['method']) : '—'; ?></td>
                <td><?php echo $c ? htmlspecialchars($c['method']) : '—'; ?></td>
                <td class="<?php echo $act_class; ?>"><?php echo htmlspecialchars(dr_action_label($act)); ?></td>
                <td><?php echo htmlspecialchars($p['pharmacist_comment'] ?? ''); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="mt-4">
        <p class="mb-1"><strong>ลงชื่อเภสัชกรผู้ส่ง:</strong> _____________________________</p>
        <p class="mb-1"><strong>ลงชื่อแพทย์ผู้รับทราบ:</strong> _____________________________ วันที่ ______/______/______</p>
    </div>
</div>
</body>
</html>
