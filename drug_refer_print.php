<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/drug_refer_helper.php';

$data = ndr_load_print_session();
if (!$data || empty($data['rows'])) {
    die('ไม่พบข้อมูลสำหรับพิมพ์ — กรุณาเทียบบัญชียาจากหน้า Refer ยาผู้ป่วยก่อน');
}
$patient = $data['patient'] ?? [];
$visit = $data['visit'] ?? [];
$hospitals = $data['hospitals'] ?? [];
$rows = $data['rows'] ?? [];
$summary = $data['summary'] ?? [];

function ndr_print_cell($match) {
    if (!is_array($match)) {
        return '<span class="no">✗</span>';
    }
    $st = $match['status'] ?? 'none';
    if ($st === 'exact') {
        return '<span class="ok">✓</span>';
    }
    if ($st === 'similar') {
        $name = ndr_match_display_html($match);
        $score = (int)($match['score'] ?? 0);
        return '<span class="sim">~</span><br><small class="sim-txt">' . $name . ' (' . $score . '%)</small>';
    }
    return '<span class="no">✗</span>';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Refer ยาผู้ป่วย — พิมพ์</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', Tahoma, sans-serif; font-size: 13px; color: #111; }
        .print-header { border-bottom: 2px solid #1f4d3b; margin-bottom: 16px; padding-bottom: 10px; }
        table.ref-table { width: 100%; border-collapse: collapse; }
        table.ref-table th, table.ref-table td { border: 1px solid #333; padding: 6px 8px; vertical-align: top; }
        table.ref-table th { background: #e8f5ef; text-align: center; }
        table.ref-table th.col-drug { text-align: left; }
        table.ref-table td.hos-cell { text-align: center; width: 64px; }
        .ok { color: #16a34a; font-weight: 700; font-size: 16px; }
        .sim { color: #d97706; font-weight: 700; font-size: 16px; }
        .sim-txt { color: #92400e; font-size: 10px; }
        .no { color: #dc2626; font-weight: 700; font-size: 16px; }
        .suggest { font-size: 11px; color: #92400e; margin-top: 4px; }
        @media print { .no-print { display: none !important; } body { margin: 0; } }
    </style>
</head>
<body>
<div class="container-fluid p-4">
    <div class="no-print mb-3">
        <button class="btn btn-primary" onclick="window.print()">พิมพ์</button>
        <a class="btn btn-secondary" href="drug_refer.php">กลับ</a>
    </div>

    <div class="print-header">
        <h4 class="mb-1">Refer ยาผู้ป่วย — เทียบบัญชียาเครือข่าย</h4>
        <small>THAM Hos-Safe | ✓ ตรง | ~ ใกล้เคียง | ✗ ไม่พบ</small>
    </div>

    <table class="table table-sm table-borderless mb-3" style="width:auto;">
        <tr>
            <td><strong>HN</strong></td><td><?php echo htmlspecialchars($visit['hn'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
            <td><strong>ชื่อผู้ป่วย</strong></td><td><?php echo htmlspecialchars($patient['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
        </tr>
        <?php if (!empty($patient['address'])): ?>
        <tr>
            <td><strong>ที่อยู่</strong></td><td colspan="3"><?php echo htmlspecialchars($patient['address'], ENT_QUOTES, 'UTF-8'); ?></td>
        </tr>
        <?php endif; ?>
        <tr>
            <td><strong>Visit</strong></td><td><?php echo htmlspecialchars($visit['visit_label'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
            <td><strong>สิทธิการรักษา</strong></td>
            <td><?php echo htmlspecialchars($visit['pttype_name'] ?? $patient['pttype_name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
        </tr>
        <tr>
            <td><strong>สรุป</strong></td>
            <td colspan="3">
                รายการยา <?php echo (int)($summary['total'] ?? 0); ?> |
                ตรง <?php echo (int)($summary['exact_all'] ?? 0); ?> |
                ใกล้เคียง <?php echo (int)($summary['similar_some'] ?? 0); ?> |
                ไม่พบ <?php echo (int)($summary['missing_any'] ?? 0); ?>
            </td>
        </tr>
    </table>

    <table class="ref-table">
        <thead>
            <tr>
                <th class="col-drug">tmtdrug / วิธีใช้</th>
                <?php foreach ($hospitals as $hos): ?>
                <th><?php echo htmlspecialchars($hos, ENT_QUOTES, 'UTF-8'); ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td>
                    <?php echo ndr_drug_display_html($r, 'drug_name'); ?>
                    <?php if (!empty($r['method'])): ?>
                    <br><small><?php echo htmlspecialchars($r['method'], ENT_QUOTES, 'UTF-8'); ?></small>
                    <?php endif; ?>
                    <?php
                    foreach ($hospitals as $hos) {
                        $match = $r['hospitals'][$hos] ?? null;
                        if (is_array($match) && ($match['status'] ?? '') === 'exact') {
                            continue;
                        }
                        $list = ($r['suggestions'][$hos] ?? []) ?: ($match['suggestions'] ?? []);
                        if (empty($list)) {
                            continue;
                        }
                        echo '<div class="suggest"><strong>' . htmlspecialchars($hos, ENT_QUOTES, 'UTF-8') . ' แนะนำ:</strong> ';
                        $parts = [];
                        foreach ($list as $s) {
                            $label = trim($s['display_name'] ?? $s['tmtdrug'] ?? '');
                            $parts[] = htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ' (' . (int)($s['score'] ?? 0) . '%)';
                        }
                        echo implode(', ', $parts) . '</div>';
                    }
                    ?>
                </td>
                <?php foreach ($hospitals as $hos): ?>
                <td class="hos-cell"><?php echo ndr_print_cell($r['hospitals'][$hos] ?? null); ?></td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <p class="mt-4 mb-0 small text-muted">
        พิมพ์เมื่อ <?php echo htmlspecialchars($data['printed_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
        โดย <?php echo htmlspecialchars($data['printed_by'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
    </p>
</div>
</body>
</html>
