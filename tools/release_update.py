#!/usr/bin/env python3
"""
สร้างแพ็กเกจอัปเดตสำหรับเผยแพร่ผ่าน GitHub: atipolklaypaksi-cell/tham-Hos-safe

ใช้งาน:
  python3 tools/release_update.py
  python3 tools/release_update.py --version 1.5.4 --details "เพิ่ม Export Excel"
  python3 tools/release_update.py --bump patch --details "แก้บั๊ก..."

ผลลัพธ์:
  - releases/update_X.Y.Z.zip
  - version.json (ชี้ download_url ไปที่ GitHub)
"""

from __future__ import annotations

import argparse
import datetime as dt
import fnmatch
import os
import re
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REPO = "atipolklaypaksi-cell/tham-Hos-safe"
BRANCH = "main"

# ไฟล์/โฟลเดอร์ที่ห้ามใส่ใน zip อัปเดต
EXCLUDE_NAMES = {
    ".git",
    ".gitignore",
    ".idea",
    ".vscode",
    ".DS_Store",
    "Thumbs.db",
    "db_connect.php",
    "license.key",
    ".env",
    "dist",
    "uploads",
    "logs",
    "storage",
    "file",
    "tmp",
    "temp",
    "_notes",
    "พื้นที่รับผิดชอบ",
    "update_temp.zip",
}

EXCLUDE_GLOBS = [
    "*.swp",
    "*.swo",
    ".env.*",
    "releases/*.zip",
    "dist/*",
]


def read_app_version() -> str:
    text = (ROOT / "version.php").read_text(encoding="utf-8", errors="ignore")
    m = re.search(r"define\(\s*'APP_VERSION'\s*,\s*'([^']+)'\s*\)", text)
    if not m:
        raise SystemExit("ไม่พบ APP_VERSION ใน version.php")
    return m.group(1)


def write_app_version(version: str) -> None:
    path = ROOT / "version.php"
    text = path.read_text(encoding="utf-8", errors="ignore")
    new_text, n = re.subn(
        r"define\(\s*'APP_VERSION'\s*,\s*'[^']+'\s*\)",
        f"define('APP_VERSION', '{version}')",
        text,
        count=1,
    )
    if n != 1:
        raise SystemExit("อัปเดต APP_VERSION ใน version.php ไม่สำเร็จ")
    path.write_text(new_text, encoding="utf-8")


def bump_version(version: str, kind: str) -> str:
    parts = [int(x) for x in version.split(".")]
    while len(parts) < 3:
        parts.append(0)
    major, minor, patch = parts[0], parts[1], parts[2]
    if kind == "major":
        major, minor, patch = major + 1, 0, 0
    elif kind == "minor":
        minor, patch = minor + 1, 0
    else:
        patch += 1
    return f"{major}.{minor}.{patch}"


def should_exclude(rel: Path) -> bool:
    parts = rel.parts
    if not parts:
        return True
    if parts[0] in EXCLUDE_NAMES:
        return True
    if any(p in EXCLUDE_NAMES for p in parts):
        return True
    s = rel.as_posix()
    for pat in EXCLUDE_GLOBS:
        if fnmatch.fnmatch(s, pat) or fnmatch.fnmatch(parts[-1], pat):
            return True
    return False


def build_zip(version: str) -> Path:
    out_dir = ROOT / "releases"
    out_dir.mkdir(parents=True, exist_ok=True)
    out_path = out_dir / f"update_{version}.zip"
    if out_path.exists():
        out_path.unlink()

    count = 0
    with zipfile.ZipFile(out_path, "w", compression=zipfile.ZIP_DEFLATED) as zf:
        for dirpath, dirnames, filenames in os.walk(ROOT):
            rel_dir = Path(dirpath).relative_to(ROOT)
            # ตัดโฟลเดอร์ที่ต้องข้ามออกจาก walk
            dirnames[:] = [
                d for d in dirnames
                if not should_exclude(rel_dir / d if str(rel_dir) != "." else Path(d))
            ]
            for name in filenames:
                full = Path(dirpath) / name
                rel = full.relative_to(ROOT)
                if should_exclude(rel):
                    continue
                # อย่าใส่ zip ตัวเอง
                if rel.as_posix().startswith("releases/update_") and rel.suffix == ".zip":
                    continue
                zf.write(full, rel.as_posix())
                count += 1

    size_mb = out_path.stat().st_size / (1024 * 1024)
    print(f"✅ สร้าง {out_path.relative_to(ROOT)} ({count} ไฟล์, {size_mb:.1f} MB)")
    return out_path


def write_version_json(version: str, details: str) -> Path:
    download_url = (
        f"https://github.com/{REPO}/raw/{BRANCH}/releases/update_{version}.zip"
    )
    payload = {
        "latest_version": version,
        "release_date": dt.date.today().isoformat(),
        "update_details": details,
        "download_url": download_url,
    }
    path = ROOT / "version.json"
    import json

    path.write_text(
        json.dumps(payload, ensure_ascii=False, indent=4) + "\n",
        encoding="utf-8",
    )
    print(f"✅ อัปเดต version.json → {version}")
    print(f"   download_url: {download_url}")
    return path


def main() -> int:
    parser = argparse.ArgumentParser(description="สร้างแพ็กเกจอัปเดต THAM Hos-Safe")
    parser.add_argument("--version", help="เลขเวอร์ชัน เช่น 1.5.4")
    parser.add_argument("--bump", choices=["major", "minor", "patch"], help="เพิ่มเวอร์ชันอัตโนมัติ")
    parser.add_argument("--details", default="", help="รายละเอียดอัปเดตที่แสดงใน upgrade.php")
    parser.add_argument("--no-write-version-php", action="store_true", help="ไม่แก้ version.php")
    args = parser.parse_args()

    current = read_app_version()
    if args.version:
        version = args.version
    elif args.bump:
        version = bump_version(current, args.bump)
    else:
        version = current

    details = args.details.strip() or f"อัปเดต THAM Hos-Safe เป็นเวอร์ชัน {version}"

    print(f"เวอร์ชันปัจจุบัน: {current}")
    print(f"เวอร์ชันที่จะปล่อย: {version}")

    if not args.no_write_version_php and version != current:
        write_app_version(version)
        print(f"✅ อัปเดต version.php → {version}")

    build_zip(version)
    write_version_json(version, details)

    print("\nขั้นตอนถัดไป (อัปขึ้น GitHub):")
    print("  cd", ROOT)
    print("  git add version.php version.json releases/update_{}.zip".format(version))
    print('  git commit -m "Release {}"'.format(version))
    print("  git push origin main")
    print("\nจากนั้นที่ รพ.อื่น เปิด upgrade.php เพื่ออัปเดต")
    return 0


if __name__ == "__main__":
    sys.exit(main())
