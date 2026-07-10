<?php
/**
 * Step 1: Spot Map Dashboard — แสดงรพ.ที่ติดตั้งบนแผนที่ (เปิดดูได้โดยไม่ต้อง login)
 */
define('THAM_LICENSE_SKIP', true);
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/license_admin_helper.php';

header('Content-Type: text/html; charset=utf-8');

if (!license_admin_ensure_table($con)) {
    die('<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8"><title>DB Error</title></head><body style="font-family:Sarabun,sans-serif;padding:2rem;">'
        . '<h3>ไม่สามารถสร้างตาราง tb_deployments ได้</h3>'
        . '<p>' . htmlspecialchars(mysqli_error($con), ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p>ลองรัน <a href="upgrade.php">upgrade.php</a> หรือ import ไฟล์ <code>sql/tb_deployments.sql</code></p></body></html>');
}

$deployments = license_admin_fetch_deployments($con);
$map_points = [];
foreach ($deployments as $row) {
    $map_points[] = [
        'name' => $row['hospital_name'],
        'lat' => (float)($row['lat'] ?? $row['latitude'] ?? 0),
        'lng' => (float)($row['lng'] ?? $row['longitude'] ?? 0),
        'status' => $row['status'],
        'expire' => $row['expire_date'],
    ];
}
$map_json = json_encode($map_points, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Deployment Spot Map — THAM Hos-Safe</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
    <style>
        body { font-family: Sarabun, Tahoma, sans-serif; background: #f4f7f6; }
        #map { height: 70vh; min-height: 400px; border-radius: 8px; border: 1px solid #dee2e6; }
        .ph-marker { background: transparent !important; border: none !important; }
        .ph-marker-pin {
            position: relative;
            width: 38px;
            height: 48px;
            filter: drop-shadow(0 3px 6px rgba(0,0,0,.35));
            transition: transform .15s ease;
        }
        .ph-marker-pin:hover { transform: scale(1.08) translateY(-2px); }
        .ph-marker-pin svg { display: block; width: 38px; height: 48px; }
    </style>
</head>
<body>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold mb-0"><i class="fas fa-map-marked-alt text-danger me-2"></i>Spot Map — รพ.ที่ติดตั้ง THAM Hos-Safe</h4>
        <div>
            <span class="badge bg-primary fs-6"><?php echo count($deployments); ?> รพ.</span>
        </div>
    </div>
    <div id="map"></div>
    <div class="table-responsive mt-3 bg-white rounded border shadow-sm">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr><th>โรงพยาบาล</th><th>Lat</th><th>Lng</th><th>หมดอายุ</th><th>สถานะ</th></tr>
            </thead>
            <tbody>
            <?php if (empty($deployments)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">ยังไม่มีข้อมูลรพ.ที่ติดตั้ง</td></tr>
            <?php else: foreach ($deployments as $d):
                $lat = $d['lat'] ?? $d['latitude'] ?? '';
                $lng = $d['lng'] ?? $d['longitude'] ?? '';
                $badge = ($d['status'] === 'Active') ? 'success' : (($d['status'] === 'Expired') ? 'warning' : 'secondary');
            ?>
                <tr>
                    <td class="fw-bold"><?php echo htmlspecialchars($d['hospital_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($lat); ?></td>
                    <td><?php echo htmlspecialchars($lng); ?></td>
                    <td><?php echo htmlspecialchars($d['expire_date']); ?></td>
                    <td><span class="badge bg-<?php echo $badge; ?>"><?php echo htmlspecialchars($d['status']); ?></span></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script>
const POINTS = <?php echo $map_json ?: '[]'; ?>;
// ศูนย์กลางประเทศไทย
const map = L.map('map').setView([13.7563, 100.5018], 6);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 18,
    attribution: '&copy; OpenStreetMap contributors'
}).addTo(map);

const bounds = [];

function phMarkerColor(status) {
    if (status === 'Expired') return { main: '#f59e0b', dark: '#d97706' };
    if (status === 'Revoked') return { main: '#6b7280', dark: '#4b5563' };
    return { main: '#0d9488', dark: '#0f766e' };
}

function phMarkerHtml(status) {
    const c = phMarkerColor(status);
    return '<div class="ph-marker-pin" title="โรงพยาบาล">' +
        '<svg viewBox="0 0 38 48" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' +
        '<path d="M19 0C9.06 0 1 8.06 1 18c0 11.25 17.1 29.1 17.72 29.72a1.2 1.2 0 0 0 1.72 0C20.9 47.1 37 29.25 37 18 37 8.06 28.94 0 19 0z" fill="' + c.main + '" stroke="' + c.dark + '" stroke-width="1.2"/>' +
        '<circle cx="19" cy="17" r="11" fill="#fff" opacity=".95"/>' +
        '<rect x="16.2" y="11" width="5.6" height="12" rx="1" fill="' + c.dark + '"/>' +
        '<rect x="13" y="14.2" width="12" height="5.6" rx="1" fill="' + c.dark + '"/>' +
        '</svg></div>';
}

POINTS.forEach(function(p) {
    const icon = L.divIcon({
        className: 'ph-marker',
        html: phMarkerHtml(p.status),
        iconSize: [38, 48],
        iconAnchor: [19, 48],
        popupAnchor: [0, -44]
    });
    const marker = L.marker([p.lat, p.lng], { icon: icon }).addTo(map);
    const statusBadge = p.status === 'Active'
        ? '<span style="color:#0d9488;font-weight:bold;">● Active</span>'
        : (p.status === 'Expired'
            ? '<span style="color:#d97706;font-weight:bold;">● Expired</span>'
            : '<span style="color:#6b7280;">● ' + esc(p.status) + '</span>');
    marker.bindPopup(
        '<div style="min-width:200px;font-family:Sarabun,Tahoma,sans-serif;">' +
        '<div style="font-size:1.05rem;font-weight:bold;color:#0f766e;margin-bottom:6px;">' +
        '<span style="font-size:1.2rem;">🏥</span> ' + esc(p.name) + '</div>' +
        '<div style="font-size:0.9rem;line-height:1.6;">' +
        'สถานะ: ' + statusBadge + '<br>' +
        'หมดอายุ: <b>' + esc(p.expire) + '</b>' +
        '</div></div>'
    );
    bounds.push([p.lat, p.lng]);
});
if (bounds.length > 0) {
    map.fitBounds(bounds, { padding: [40, 40] });
}

function esc(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>
</body>
</html>
