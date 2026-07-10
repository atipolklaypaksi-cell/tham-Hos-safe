<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    die("Unauthorized");
}

$zipUrl = isset($_GET['url']) ? $_GET['url'] : '';
if(empty($zipUrl)) {
    die("ไม่พบลิงก์สำหรับดาวน์โหลดอัปเดต");
}

$localZipFile = 'update_temp.zip';

echo "<div style='font-family: Tahoma; text-align: center; margin-top: 50px;'>";
echo "<h2>กำลังดาวน์โหลดการอัปเดต... ⏳</h2>";

// 🎯 ใช้ cURL ดาวน์โหลดไฟล์ Zip แกร่งกว่าเดิม
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $zipUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1); // ให้มันตามลิงก์ไปถ้า GitHub มีการ Redirect
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'THAM-CYTO-Updater');
$zipData = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if($zipData === FALSE || $http_code != 200) {
    die("<h3 style='color:red;'>❌ ดาวน์โหลดไฟล์ล้มเหลว (HTTP Status: $http_code)</h3>");
}

file_put_contents($localZipFile, $zipData);
echo "<p>✅ ดาวน์โหลดไฟล์สำเร็จ กำลังติดตั้ง...</p>";

$zip = new ZipArchive;
if ($zip->open($localZipFile) === TRUE) {
    $zip->extractTo('./');
    $zip->close();
    unlink($localZipFile);
    
    echo "<p>✅ ติดตั้งไฟล์ระบบสำเร็จ!</p>";
    echo "<h3 style='color:green;'>กำลังพาท่านไปอัปเดตฐานข้อมูล... 🚀</h3>";
    
    echo "<script>
            setTimeout(function() {
                window.location.href = 'upgrade.php';
            }, 2000);
          </script>";
} else {
    echo "<h3 style='color:red;'>❌ แตกไฟล์ Zip ล้มเหลว โปรดตรวจสอบสิทธิ์ของโฟลเดอร์</h3>";
}
echo "</div>";
?>