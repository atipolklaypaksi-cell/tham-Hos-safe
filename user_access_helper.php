<?php
/**
 * บันทึก log การเข้าใช้งาน และติดตามเวลาใช้งานล่าสุด
 */

function tham_user_access_ensure_tables($con) {
    static $ready = false;
    if ($ready) {
        return true;
    }

    $sql = @file_get_contents(__DIR__ . '/sql/user_access_log.sql');
    if ($sql !== false) {
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt !== '') {
                @mysqli_query($con, $stmt);
            }
        }
    }

    $cols = [
        'last_login_at' => "ALTER TABLE users ADD COLUMN last_login_at TIMESTAMP NULL DEFAULT NULL",
        'last_seen_at' => "ALTER TABLE users ADD COLUMN last_seen_at TIMESTAMP NULL DEFAULT NULL",
    ];
    foreach ($cols as $col => $ddl) {
        $q = mysqli_query($con, "SHOW COLUMNS FROM users LIKE '$col'");
        if ($q && mysqli_num_rows($q) === 0) {
            @mysqli_query($con, $ddl);
        }
    }

    $ready = true;
    return true;
}

function tham_user_access_client_ip() {
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $key) {
        if (empty($_SERVER[$key])) {
            continue;
        }
        $raw = trim((string)$_SERVER[$key]);
        if ($raw === '') {
            continue;
        }
        if ($key === 'HTTP_X_FORWARDED_FOR') {
            $parts = explode(',', $raw);
            $raw = trim($parts[0]);
        }
        if (filter_var($raw, FILTER_VALIDATE_IP)) {
            return substr($raw, 0, 45);
        }
    }
    return null;
}

function tham_user_access_client_ua() {
    return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
}

function tham_user_access_user_snapshot($con, $user_id) {
    $uid = (int)$user_id;
    if ($uid <= 0) {
        return ['username' => '', 'full_name' => ''];
    }
    $q = mysqli_query($con, "SELECT username, full_name FROM users WHERE id = $uid LIMIT 1");
    if (!$q || mysqli_num_rows($q) === 0) {
        return ['username' => '', 'full_name' => ''];
    }
    $row = mysqli_fetch_assoc($q);
    return [
        'username' => (string)($row['username'] ?? ''),
        'full_name' => (string)($row['full_name'] ?? ''),
    ];
}

function tham_user_access_insert_log($con, $user_id, $action, $page = null, array $snapshot = null) {
    tham_user_access_ensure_tables($con);

    $uid = (int)$user_id;
    if ($uid <= 0) {
        return false;
    }

    if ($snapshot === null) {
        $snapshot = tham_user_access_user_snapshot($con, $uid);
    }

    $username = mysqli_real_escape_string($con, $snapshot['username'] ?? '');
    $full_name = mysqli_real_escape_string($con, $snapshot['full_name'] ?? '');
    $action = mysqli_real_escape_string($con, $action);
    $page_esc = $page !== null && $page !== ''
        ? "'" . mysqli_real_escape_string($con, substr((string)$page, 0, 255)) . "'"
        : 'NULL';
    $ip = tham_user_access_client_ip();
    $ip_esc = $ip !== null ? "'" . mysqli_real_escape_string($con, $ip) . "'" : 'NULL';
    $ua = mysqli_real_escape_string($con, tham_user_access_client_ua());

    return (bool)mysqli_query($con, "INSERT INTO user_access_log
        (user_id, username, full_name, action, page_url, ip_address, user_agent)
        VALUES ($uid, '$username', '$full_name', '$action', $page_esc, $ip_esc, '$ua')");
}

function tham_user_access_log_login($con, $user_id, $page = 'login.php') {
    tham_user_access_ensure_tables($con);
    $uid = (int)$user_id;
    if ($uid <= 0) {
        return false;
    }

    $snapshot = tham_user_access_user_snapshot($con, $uid);
    $now = date('Y-m-d H:i:s');
    mysqli_query($con, "UPDATE users SET last_login_at = '$now', last_seen_at = '$now' WHERE id = $uid");
    return tham_user_access_insert_log($con, $uid, 'login', $page, $snapshot);
}

function tham_user_access_log_logout($con, $user_id, $page = 'logout.php', array $snapshot = null) {
    return tham_user_access_insert_log($con, $user_id, 'logout', $page, $snapshot);
}

function tham_user_access_touch($con, $user_id, $page = '') {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    tham_user_access_ensure_tables($con);
    $uid = (int)$user_id;
    if ($uid <= 0) {
        return false;
    }

    $now = time();
    $last_touch = (int)($_SESSION['tham_access_touch_at'] ?? 0);
    if ($last_touch > 0 && ($now - $last_touch) < 120) {
        return false;
    }

    $_SESSION['tham_access_touch_at'] = $now;
    $now_sql = date('Y-m-d H:i:s', $now);
    mysqli_query($con, "UPDATE users SET last_seen_at = '$now_sql' WHERE id = $uid");

    if ($page !== '' && ($now - (int)($_SESSION['tham_access_active_log_at'] ?? 0)) >= 1800) {
        $_SESSION['tham_access_active_log_at'] = $now;
        tham_user_access_insert_log($con, $uid, 'active', $page);
    }

    return true;
}

function tham_user_access_seconds_ago($datetime) {
    if ($datetime === null || $datetime === '' || $datetime === '0000-00-00 00:00:00') {
        return null;
    }
    $ts = strtotime((string)$datetime);
    if ($ts === false) {
        return null;
    }
    return max(0, time() - $ts);
}

function tham_user_access_format_ago($datetime) {
    $seconds = tham_user_access_seconds_ago($datetime);
    if ($seconds === null) {
        return '-';
    }
    if ($seconds < 30) {
        return 'เมื่อสักครู่';
    }
    if ($seconds < 60) {
        return 'เมื่อ ' . $seconds . ' วินาทีที่แล้ว';
    }
    $minutes = (int)floor($seconds / 60);
    if ($minutes < 60) {
        return 'เมื่อ ' . $minutes . ' นาทีที่แล้ว';
    }
    $hours = (int)floor($minutes / 60);
    if ($hours < 24) {
        return 'เมื่อ ' . $hours . ' ชั่วโมงที่แล้ว';
    }
    $days = (int)floor($hours / 24);
    if ($days < 30) {
        return 'เมื่อ ' . $days . ' วันที่แล้ว';
    }
    return date('d/m/Y H:i', strtotime((string)$datetime));
}

function tham_user_access_online_badge($last_seen_at, $online_minutes = 5) {
    $seconds = tham_user_access_seconds_ago($last_seen_at);
    if ($seconds === null) {
        return '<span class="badge bg-secondary">ยังไม่เคยเข้า</span>';
    }
    if ($seconds <= ($online_minutes * 60)) {
        return '<span class="badge bg-success">ออนไลน์</span>';
    }
    return '<span class="badge bg-light text-dark border">ออฟไลน์</span>';
}

function tham_user_access_fetch_logs($con, array $filters = []) {
    tham_user_access_ensure_tables($con);

    $where = ['1=1'];
    $user_id = (int)($filters['user_id'] ?? 0);
    if ($user_id > 0) {
        $where[] = 'user_id = ' . $user_id;
    }

    $action = trim((string)($filters['action'] ?? ''));
    if ($action !== '' && in_array($action, ['login', 'logout', 'active'], true)) {
        $where[] = "action = '" . mysqli_real_escape_string($con, $action) . "'";
    }

    $q = trim((string)($filters['q'] ?? ''));
    if ($q !== '') {
        $esc = mysqli_real_escape_string($con, $q);
        $where[] = "(username LIKE '%$esc%' OR full_name LIKE '%$esc%' OR page_url LIKE '%$esc%' OR ip_address LIKE '%$esc%')";
    }

    $date_from = trim((string)($filters['date_from'] ?? ''));
    if ($date_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
        $where[] = "created_at >= '" . mysqli_real_escape_string($con, $date_from) . " 00:00:00'";
    }

    $date_to = trim((string)($filters['date_to'] ?? ''));
    if ($date_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
        $where[] = "created_at <= '" . mysqli_real_escape_string($con, $date_to) . " 23:59:59'";
    }

    $where_sql = implode(' AND ', $where);
    $count_q = mysqli_query($con, "SELECT COUNT(*) AS c FROM user_access_log WHERE $where_sql");
    $total = 0;
    if ($count_q) {
        $total = (int)mysqli_fetch_assoc($count_q)['c'];
    }

    $limit = max(1, min(500, (int)($filters['limit'] ?? 100)));
    $offset = max(0, (int)($filters['offset'] ?? 0));

    $rows = [];
    $q_rows = mysqli_query($con, "SELECT * FROM user_access_log WHERE $where_sql ORDER BY created_at DESC, id DESC LIMIT $limit OFFSET $offset");
    if ($q_rows) {
        while ($row = mysqli_fetch_assoc($q_rows)) {
            $row['ago_text'] = tham_user_access_format_ago($row['created_at'] ?? null);
            $rows[] = $row;
        }
    }

    return [
        'rows' => $rows,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
    ];
}

function tham_user_access_action_label($action) {
    switch ($action) {
        case 'login': return 'เข้าสู่ระบบ';
        case 'logout': return 'ออกจากระบบ';
        case 'active': return 'ใช้งานอยู่';
        default: return (string)$action;
    }
}
