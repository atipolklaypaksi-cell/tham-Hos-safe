<?php
session_start();
// 🎯 บังคับ Header ให้ส่งออกเป็น UTF-8
header('Content-Type: text/html; charset=utf-8');

if(isset($_SESSION['user_id'])) {
    header("Location: home.php");
    exit;
}

include('connect.php');
// 🎯 บังคับการเชื่อมต่อฐานข้อมูลเป็น UTF-8 เสมอ
mysqli_set_charset($con, "utf8");

$error_msg = '';

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    require_once __DIR__ . '/opduser_auth_helper.php';
    require_once __DIR__ . '/menu_permissions_helper.php';

    $logged_in = false;
    $user_row = null;

    // 1) บัญชีภายในระบบ (Admin ที่สร้างใน users.php)
    if ($username !== '') {
        $esc_user = mysqli_real_escape_string($con, $username);
        $hashed_password = md5($password);
        $sql = "SELECT * FROM users WHERE username = '$esc_user' AND auth_source = 'local' AND password = '$hashed_password' LIMIT 1";
        $query = mysqli_query($con, $sql);
        if ($query && mysqli_num_rows($query) > 0) {
            $user_row = mysqli_fetch_assoc($query);
            $logged_in = true;
        }
    }

    // 2) ยืนยันตัวตนจาก HOSxP opduser (เฉพาะ username ที่ไม่ใช่บัญชีภายใน)
    if (!$logged_in && $username !== '') {
        $esc_user = mysqli_real_escape_string($con, $username);
        $local_only = mysqli_query($con, "SELECT id FROM users WHERE username = '$esc_user' AND auth_source = 'local' LIMIT 1");
        if (!$local_only || mysqli_num_rows($local_only) === 0) {
            $auth = tham_opduser_authenticate($con, $username, $password);
            if (!empty($auth['ok'])) {
                $synced = tham_opduser_sync_local_user($con, $auth['opduser']);
                if ($synced) {
                    $q = mysqli_query($con, "SELECT * FROM users WHERE id = " . (int)$synced['id'] . " LIMIT 1");
                    if ($q && mysqli_num_rows($q) > 0) {
                        $user_row = mysqli_fetch_assoc($q);
                        $logged_in = true;
                    }
                }
            } elseif ($error_msg === '' && !empty($auth['error'])) {
                $error_msg = $auth['error'];
            }
        }
    }

    if ($logged_in && $user_row) {
        $_SESSION['user_id'] = $user_row['id'];
        $_SESSION['username'] = $user_row['username'];
        $_SESSION['full_name'] = $user_row['full_name'];
        $_SESSION['role'] = $user_row['role'];
        $_SESSION['auth_source'] = $user_row['auth_source'] ?? 'local';

        tham_menu_load_session_permissions($con, (int)$user_row['id'], $user_row['role']);

        require_once __DIR__ . '/user_access_helper.php';
        tham_user_access_log_login($con, (int)$user_row['id']);

        header("Location: home.php");
        exit;
    }
    if ($error_msg === '') {
        $error_msg = 'ชื่อผู้ใช้งาน หรือ รหัสผ่านไม่ถูกต้อง!';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>เข้าสู่ระบบ - THAM Hos-Safe</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #eef7f3 0%, #d4ebe3 50%, #a8d5c2 100%);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            width: 100%;
            max-width: 400px;
            border-radius: 16px;
            box-shadow: 0 12px 40px rgba(47, 61, 36, 0.2);
            border: 1px solid #a8d5c2;
        }
        .login-header {
            background: linear-gradient(135deg, #143326, #1a4032);
            color: white;
            border-top-left-radius: 16px;
            border-top-right-radius: 16px;
            padding: 30px 20px;
            text-align: center;
        }
        .login-card .btn-primary {
            background-color: #1f4d3b;
            border-color: #1f4d3b;
        }
        .login-card .btn-primary:hover {
            background-color: #143326;
            border-color: #143326;
        }
    </style>
</head>
<body>

<div class="card login-card">
    <div class="login-header">
        <i class="fas fa-shield-virus fa-3x mb-3"></i>
        <h3 class="fw-bold mb-0">THAM Hos-Safe</h3>
        <p class="mb-0 text-light">ระบบความปลอดภัยด้านยา</p>
    </div>
    <div class="card-body p-4">
        
        <?php if($error_msg != ''): ?>
            <div class="alert alert-danger fw-bold text-center py-2"><i class="fas fa-exclamation-circle me-2"></i><?php echo $error_msg; ?></div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <div class="mb-3">
                <label class="form-label fw-bold text-muted">ชื่อผู้ใช้งาน (Username)</label>
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="fas fa-user text-primary"></i></span>
                    <input type="text" name="username" class="form-control" required autofocus placeholder="loginname จาก HOSxP">
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label fw-bold text-muted">รหัสผ่าน (Password)</label>
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="fas fa-lock text-primary"></i></span>
                    <input type="password" name="password" class="form-control" required placeholder="รหัสผ่าน HOSxP">
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100 fw-bold py-2 shadow-sm"><i class="fas fa-sign-in-alt me-2"></i>เข้าสู่ระบบ</button>
        </form>
        
        <div class="text-center mt-4">
            <small class="text-muted">© <?php echo date('Y'); ?> THAM Hos-Safe. All rights reserved.</small>
        </div>
    </div>
</div>

<script src="js/bootstrap.bundle.min.js"></script>
</body>
</html>