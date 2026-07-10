-- สิทธิ์เข้าถึงเมนูตาม Role (กลุ่ม)
CREATE TABLE IF NOT EXISTS role_menu_permissions (
    role VARCHAR(50) NOT NULL,
    menu_key VARCHAR(64) NOT NULL,
    PRIMARY KEY (role, menu_key),
    KEY idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- สิทธิ์เข้าถึงเมนูรายคน (เมื่อเปิดใช้ custom)
CREATE TABLE IF NOT EXISTS user_menu_permissions (
    user_id INT NOT NULL,
    menu_key VARCHAR(64) NOT NULL,
    PRIMARY KEY (user_id, menu_key),
    KEY idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
