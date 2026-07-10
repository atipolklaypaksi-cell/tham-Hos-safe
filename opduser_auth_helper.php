<?php
/**
 * ยืนยันตัวตนและซิงก์ผู้ใช้จาก HOSxP opduser
 */

require_once __DIR__ . '/hosxp_tmt_drug_helper.php';
require_once __DIR__ . '/menu_permissions_helper.php';

function tham_opduser_default_role() {
    return 'general_staff';
}

function tham_opduser_valid_roles() {
    return array_keys(tham_menu_role_labels());
}

/** เฉพาะบัญชีที่ยังใช้งานได้ใน HOSxP (account_disable = N) */
function tham_opduser_sql_active_only() {
    return "UPPER(TRIM(IFNULL(account_disable, ''))) = 'N'";
}

function tham_opduser_ensure_tables($con) {
    static $ready = false;
    if ($ready) {
        return true;
    }
    $sql = @file_get_contents(__DIR__ . '/sql/opduser_auth.sql');
    if ($sql !== false) {
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt !== '') {
                @mysqli_query($con, $stmt);
            }
        }
    }
    $cols = [
        'auth_source' => "ALTER TABLE users ADD COLUMN auth_source VARCHAR(20) NOT NULL DEFAULT 'local'",
        'entryposition' => "ALTER TABLE users ADD COLUMN entryposition VARCHAR(255) NULL DEFAULT NULL",
        'role_from_entryposition' => "ALTER TABLE users ADD COLUMN role_from_entryposition TINYINT(1) NOT NULL DEFAULT 0",
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

function tham_opduser_hash_password($plain) {
    return strtoupper(md5((string)$plain));
}

function tham_opduser_fetch_by_loginname($hos, $loginname) {
    if (!$hos) {
        return null;
    }
    $loginname = trim((string)$loginname);
    if ($loginname === '') {
        return null;
    }
    $esc = mysqli_real_escape_string($hos, $loginname);
    $active = tham_opduser_sql_active_only();
    $q = mysqli_query($hos, "SELECT loginname, name, password, passweb, entryposition, department
        FROM opduser WHERE loginname = '$esc' AND $active LIMIT 1");
    if (!$q || mysqli_num_rows($q) === 0) {
        return null;
    }
    return mysqli_fetch_assoc($q);
}

function tham_opduser_password_matches(array $row, $plain) {
    $hash = tham_opduser_hash_password($plain);
    $lower = md5((string)$plain);
    foreach (['password', 'passweb'] as $field) {
        $stored = trim((string)($row[$field] ?? ''));
        if ($stored === '') {
            continue;
        }
        if (strcasecmp($stored, $hash) === 0 || strcasecmp($stored, $lower) === 0 || $stored === $hash || $stored === $lower) {
            return true;
        }
    }
    return false;
}

function tham_opduser_authenticate($con, $loginname, $password) {
    tham_opduser_ensure_tables($con);
    $hos = hosxp_connect($con, 15);
    if (!$hos) {
        return ['ok' => false, 'error' => 'เชื่อมต่อ HOSxP ไม่ได้ กรุณาตรวจสอบการตั้งค่า'];
    }
    $row = tham_opduser_fetch_by_loginname($hos, $loginname);
    mysqli_close($hos);
    if (!$row) {
        return ['ok' => false, 'error' => 'ชื่อผู้ใช้งาน หรือ รหัสผ่านไม่ถูกต้อง'];
    }
    if (!tham_opduser_password_matches($row, $password)) {
        return ['ok' => false, 'error' => 'ชื่อผู้ใช้งาน หรือ รหัสผ่านไม่ถูกต้อง'];
    }
    return ['ok' => true, 'opduser' => $row];
}

function tham_opduser_fetch_role_map($con) {
    tham_opduser_ensure_tables($con);
    $map = [];
    $q = mysqli_query($con, "SELECT entryposition, role FROM entryposition_role_map");
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $map[$row['entryposition']] = $row['role'];
        }
    }
    return $map;
}

function tham_opduser_resolve_role($con, $entryposition) {
    $entryposition = trim((string)$entryposition);
    if ($entryposition === '') {
        return tham_opduser_default_role();
    }
    $map = tham_opduser_fetch_role_map($con);
    if (isset($map[$entryposition])) {
        $role = $map[$entryposition];
        if (in_array($role, tham_opduser_valid_roles(), true)) {
            return $role;
        }
    }
    return tham_opduser_default_role();
}

function tham_opduser_sync_local_user($con, array $opduser, $role_override = null) {
    tham_opduser_ensure_tables($con);
    $loginname = trim((string)($opduser['loginname'] ?? ''));
    $full_name = trim((string)($opduser['name'] ?? ''));
    $entryposition = trim((string)($opduser['entryposition'] ?? ''));
    if ($loginname === '') {
        return null;
    }

    $esc_user = mysqli_real_escape_string($con, $loginname);
    $esc_name = mysqli_real_escape_string($con, $full_name);
    $esc_pos = mysqli_real_escape_string($con, $entryposition);
    $placeholder_pass = mysqli_real_escape_string($con, 'opduser:' . $loginname);

    $q = mysqli_query($con, "SELECT id, role, role_from_entryposition, auth_source FROM users WHERE username = '$esc_user' LIMIT 1");
    $existing = ($q && mysqli_num_rows($q) > 0) ? mysqli_fetch_assoc($q) : null;

    if ($role_override !== null && in_array($role_override, tham_opduser_valid_roles(), true)) {
        $role = $role_override;
        $auto_role = 0;
    } elseif ($existing && (int)($existing['role_from_entryposition'] ?? 0) === 0) {
        $role = $existing['role'];
        $auto_role = 0;
    } else {
        $role = tham_opduser_resolve_role($con, $entryposition);
        $auto_role = 1;
    }
    $esc_role = mysqli_real_escape_string($con, $role);

    if ($existing) {
        if (($existing['auth_source'] ?? 'local') === 'local') {
            return null;
        }
        mysqli_query($con, "UPDATE users SET
            full_name = '$esc_name',
            entryposition = " . ($entryposition !== '' ? "'$esc_pos'" : "NULL") . ",
            auth_source = 'opduser',
            role = '$esc_role',
            role_from_entryposition = $auto_role
            WHERE id = " . (int)$existing['id']);
        $user_id = (int)$existing['id'];
    } else {
        mysqli_query($con, "INSERT INTO users (username, password, full_name, role, auth_source, entryposition, role_from_entryposition)
            VALUES ('$esc_user', '$placeholder_pass', '$esc_name', '$esc_role', 'opduser', " .
            ($entryposition !== '' ? "'$esc_pos'" : "NULL") . ", $auto_role)");
        $user_id = (int)mysqli_insert_id($con);
    }
    return [
        'id' => $user_id,
        'username' => $loginname,
        'full_name' => $full_name,
        'role' => $role,
        'entryposition' => $entryposition,
        'auth_source' => 'opduser',
    ];
}

function tham_opduser_fetch_distinct_positions($con) {
    tham_opduser_ensure_tables($con);
    $hos = hosxp_connect($con, 30);
    if (!$hos) {
        return ['ok' => false, 'error' => 'เชื่อมต่อ HOSxP ไม่ได้', 'positions' => []];
    }
    $positions = [];
    $active = tham_opduser_sql_active_only();
    $q = mysqli_query($hos, "SELECT TRIM(entryposition) AS entryposition, COUNT(*) AS user_count
        FROM opduser
        WHERE $active AND TRIM(IFNULL(entryposition, '')) <> ''
        GROUP BY TRIM(entryposition)
        ORDER BY entryposition ASC");
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $positions[] = [
                'entryposition' => $row['entryposition'],
                'user_count' => (int)$row['user_count'],
            ];
        }
    }
    mysqli_close($hos);
    $map = tham_opduser_fetch_role_map($con);
    foreach ($positions as &$p) {
        $pos = $p['entryposition'];
        $p['role'] = $map[$pos] ?? tham_opduser_default_role();
        $p['mapped'] = isset($map[$pos]);
    }
    unset($p);
    return ['ok' => true, 'positions' => $positions, 'default_role' => tham_opduser_default_role()];
}

function tham_opduser_save_role_map($con, array $rows) {
    tham_opduser_ensure_tables($con);
    $valid = array_flip(tham_opduser_valid_roles());
    $default_role = tham_opduser_default_role();
    $seen = [];
    $value_sql = [];
    foreach ($rows as $row) {
        $pos = trim((string)($row['entryposition'] ?? ''));
        $role = trim((string)($row['role'] ?? $default_role));
        if ($pos === '' || !isset($valid[$role]) || isset($seen[$pos])) {
            continue;
        }
        $seen[$pos] = true;
        $esc_pos = mysqli_real_escape_string($con, $pos);
        $esc_role = mysqli_real_escape_string($con, $role);
        $value_sql[] = "('$esc_pos','$esc_role')";
    }

    mysqli_begin_transaction($con);
    if (!mysqli_query($con, 'DELETE FROM entryposition_role_map')) {
        $err = mysqli_error($con);
        mysqli_rollback($con);
        return ['ok' => false, 'error' => $err];
    }
    foreach (array_chunk($value_sql, 80) as $chunk) {
        if (empty($chunk)) {
            continue;
        }
        $sql = 'INSERT INTO entryposition_role_map (entryposition, role) VALUES ' . implode(',', $chunk);
        if (!mysqli_query($con, $sql)) {
            $err = mysqli_error($con);
            mysqli_rollback($con);
            return ['ok' => false, 'error' => $err];
        }
    }
    mysqli_commit($con);
    return ['ok' => true, 'saved' => count($seen)];
}

function tham_opduser_apply_role_map_to_users($con) {
    tham_opduser_ensure_tables($con);
    $default_role = mysqli_real_escape_string($con, tham_opduser_default_role());
    $sql = "UPDATE users u
        LEFT JOIN entryposition_role_map m
            ON m.entryposition = TRIM(IFNULL(u.entryposition, ''))
        SET u.role = IFNULL(m.role, '$default_role')
        WHERE u.auth_source = 'opduser' AND u.role_from_entryposition = 1";
    if (!mysqli_query($con, $sql)) {
        return 0;
    }
    return (int)mysqli_affected_rows($con);
}

function tham_opduser_build_report_where($hos, array $filters) {
    $parts = [tham_opduser_sql_active_only()];
    $entryposition = trim((string)($filters['entryposition'] ?? ''));
    $department = trim((string)($filters['department'] ?? ''));
    $q = trim((string)($filters['q'] ?? ''));

    if ($entryposition !== '') {
        if ($entryposition === '__empty__') {
            $parts[] = "TRIM(IFNULL(entryposition, '')) = ''";
        } else {
            $esc = mysqli_real_escape_string($hos, $entryposition);
            $parts[] = "TRIM(entryposition) = '$esc'";
        }
    }
    if ($department !== '') {
        $esc = mysqli_real_escape_string($hos, '%' . $department . '%');
        $parts[] = "department LIKE '$esc'";
    }
    if ($q !== '') {
        $esc = mysqli_real_escape_string($hos, '%' . $q . '%');
        $parts[] = "(loginname LIKE '$esc' OR name LIKE '$esc' OR entryposition LIKE '$esc' OR department LIKE '$esc' OR departmentposition LIKE '$esc')";
    }
    return implode(' AND ', $parts);
}

function tham_opduser_fetch_users_report($con, array $filters = []) {
    tham_opduser_ensure_tables($con);
    $hos = hosxp_connect($con, 60);
    if (!$hos) {
        return ['ok' => false, 'error' => 'เชื่อมต่อ HOSxP ไม่ได้', 'rows' => [], 'total' => 0];
    }

    $where = tham_opduser_build_report_where($hos, $filters);
    $limit = max(1, min(2000, (int)($filters['limit'] ?? 500)));
    $offset = max(0, (int)($filters['offset'] ?? 0));

    $total = 0;
    $cq = mysqli_query($hos, "SELECT COUNT(*) AS c FROM opduser WHERE $where");
    if ($cq && ($cr = mysqli_fetch_assoc($cq))) {
        $total = (int)$cr['c'];
    }

    $rows = [];
    $sql = "SELECT loginname, name,
            TRIM(IFNULL(entryposition, '')) AS entryposition,
            TRIM(IFNULL(department, '')) AS department,
            TRIM(IFNULL(departmentposition, '')) AS departmentposition
        FROM opduser
        WHERE $where
        ORDER BY entryposition ASC, name ASC, loginname ASC
        LIMIT $offset, $limit";
    $q = mysqli_query($hos, $sql);
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $rows[] = $row;
        }
    }
    mysqli_close($hos);

    $role_map = tham_opduser_fetch_role_map($con);
    $default_role = tham_opduser_default_role();
    $local_users = [];
    $lq = mysqli_query($con, "SELECT username, role, auth_source FROM users");
    if ($lq) {
        while ($lu = mysqli_fetch_assoc($lq)) {
            $local_users[$lu['username']] = $lu;
        }
    }

    $role_labels = tham_menu_role_labels();
    foreach ($rows as &$row) {
        $pos = $row['entryposition'] ?? '';
        $mapped_role = $pos !== '' ? ($role_map[$pos] ?? $default_role) : $default_role;
        $local = $local_users[$row['loginname']] ?? null;
        $row['mapped_role'] = $mapped_role;
        $row['mapped_role_label'] = $role_labels[$mapped_role] ?? $mapped_role;
        $row['tham_registered'] = $local ? 1 : 0;
        $row['tham_role'] = $local['role'] ?? '';
        $row['tham_role_label'] = isset($local['role']) ? ($role_labels[$local['role']] ?? $local['role']) : '';
        $row['tham_auth_source'] = $local['auth_source'] ?? '';
    }
    unset($row);

    return [
        'ok' => true,
        'rows' => $rows,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
        'truncated' => ($offset + count($rows)) < $total,
    ];
}

function tham_opduser_fetch_departments($con) {
    $hos = hosxp_connect($con, 30);
    if (!$hos) {
        return [];
    }
    $list = [];
    $active = tham_opduser_sql_active_only();
    $q = mysqli_query($hos, "SELECT TRIM(department) AS department, COUNT(*) AS user_count
        FROM opduser
        WHERE $active AND TRIM(IFNULL(department, '')) <> ''
        GROUP BY TRIM(department)
        ORDER BY department ASC");
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $list[] = [
                'department' => $row['department'],
                'user_count' => (int)$row['user_count'],
            ];
        }
    }
    mysqli_close($hos);
    return $list;
}
