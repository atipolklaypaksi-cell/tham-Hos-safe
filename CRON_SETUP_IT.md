# คู่มือตั้ง Cron Job — Smart Pharma (THAM Hos-Safe)

เอกสารนี้สำหรับทีม IT ติดตั้ง Cron ให้ระบบ Smart Pharma ทำงานอัตโนมัติ  
(คัดกรอง OPD/IPD · บันทึก Intervention Log · แจ้งเตือน Telegram/Webhook · Hard Alert แจ้งซ้ำ)

---

## 1. ภาพรวม

เมื่อไม่มีผู้ใช้เปิดหน้าจอคัดกรอง สคริปต์ Cron จะทำงานแทน:

1. เชื่อมต่อ HOSxP และคัดกรองผู้ป่วย **OPD + IPD**
2. ซิงก์ **Intervention Log** (ไม่สร้างแถวซ้ำ)
3. ส่งแจ้งเตือน **Telegram** และ **Webhook** ตามกฎที่เภสัชตั้งไว้
4. แจ้งซ้ำ **Hard Alert** ตามช่วงเวลาที่ตั้ง (15 / 30 / 60 นาที หรือกำหนดเอง)

---

## 2. ต้องส่งไฟล์อะไร?

### กรณีที่ deploy โปรแกรม Hos-Safe บน server แล้ว

**ไม่ต้องส่งไฟล์แยกเพิ่ม** — ใช้โปรแกรมที่ติดตั้งอยู่แล้ว  
IT ต้องทราบเฉพาะ **path จริงบน server** แล้วตั้ง crontab

### ไฟล์หลักที่ Cron เรียก

| ไฟล์ | บทบาท |
|------|--------|
| `cron_smart_pharma_telegram.php` | สคริปต์ Cron หลัก |

### ไฟล์ที่สคริปต์พึ่งพา (ต้องอยู่โฟลเดอร์เดียวกัน)

| ไฟล์ | บทบาท |
|------|--------|
| `connect.php` | เชื่อมฐานข้อมูล Hos-Safe |
| `smart_pharma_helper.php` | คัดกรองผู้ป่วย |
| `smart_pharma_telegram_helper.php` | แจ้งเตือน Telegram |
| `smart_pharma_webhook_helper.php` | แจ้งเตือน Webhook |
| `smart_pharma_alert_log_helper.php` | Intervention Log |
| `smart_pharma_notify_helper.php` | ตรรกะแจ้งซ้ำ Hard Alert |
| `sql/` | โครงสร้างตาราง (สร้างผ่าน upgrade แล้ว) |

### กรณีติดตั้งระบบใหม่ทั้งหมด

Deploy **ทั้งโฟลเดอร์โปรเจกต์** แล้วให้เภสัชรัน `upgrade.php` ผ่าน browser จนเวอร์ชัน **1.2.0** ขึ้นไป ก่อนเปิด Cron

**Path ตัวอย่างบน server นี้:**

```
/home/co-scph/employee/public/thamhossafe/
```

> ปรับ path ตามที่โรงพยาบาล deploy จริง

---

## 3. สิ่งที่ต้องเตรียมก่อนตั้ง Cron

| รายการ | รายละเอียด |
|--------|------------|
| PHP CLI | รัน `php -v` ได้ (แนะนำ PHP 7.4 ขึ้นไป) |
| PHP extensions | `mysqli`, `curl`, `json` |
| ฐานข้อมูล | `connect.php` ตั้งค่าเชื่อม Hos-Safe DB แล้ว |
| HOSxP | Server เชื่อม HOSxP ได้ (เหมือนเปิดหน้า Smart Pharma ปกติ) |
| ทรัพยากร | สคริปต์ใช้ `memory_limit=512M`, `max_execution_time=600` วินาที |
| การตั้งค่าในระบบ | เภสัชเปิด Telegram/Webhook และตั้งกฎแจ้งเตือนในเมนู Smart Pharma แล้ว |
| DB version | รัน `upgrade.php` จน `db_version` ≥ **1.2.0** |

---

## 4. วิธีตั้ง Cron (แนะนำ: PHP CLI)

### 4.1 หา path

```bash
which php
# ตัวอย่างผลลัพธ์: /usr/bin/php

# ตรวจว่าไฟล์ Cron มีอยู่
ls -la /home/co-scph/employee/public/thamhossafe/cron_smart_pharma_telegram.php
```

### 4.2 ทดสอบรันมือ (บังคับก่อนตั้ง crontab)

```bash
/usr/bin/php /home/co-scph/employee/public/thamhossafe/cron_smart_pharma_telegram.php
```

**ผลลัพธ์ที่ถูกต้อง:** ได้ JSON ประมาณนี้ (ไม่มี error / fatal)

```json
{
  "screen_date": "2026-06-11",
  "time_window": "3h",
  "alert_log_totals": {
    "new": 0,
    "updated": 0,
    "reopened": 0,
    "resolved": 0,
    "skipped": 0
  },
  "results": {
    "OPD": { "patients": 0, "alert_log": {}, "telegram": {}, "webhook": {} },
    "IPD": { "patients": 0, "alert_log": {}, "telegram": {}, "webhook": {} }
  },
  "pending_repeats": {
    "telegram": { "sent": 0, "skipped": 0, "errors": [] },
    "webhook": { "sent": 0, "skipped": 0, "errors": [] }
  }
}
```

**ข้อผิดพลาดที่พบบ่อย:**

| อาการ | สาเหตุที่เป็นไปได้ |
|-------|-------------------|
| `HOSxP connect failed` | Server เชื่อม HOSxP ไม่ได้ / firewall |
| `mysqli_connect` error | `connect.php` ผิด หรือ DB ล่ม |
| `Class/Function not found` | deploy ไฟล์ไม่ครบ |
| รันนานมาก | ผู้ป่วยเยอะ — ปรับความถี่ Cron หรือดู log |

### 4.3 ตั้ง crontab

แก้ crontab ของ user ที่รันได้ (เช่น `www-data` หรือ user เดียวกับ web server):

```bash
sudo crontab -u www-data -e
```

**ตัวอย่างที่แนะนำ** — ช่วงเวลาทำงานทุก 15 นาที, นอกเวลาทุก 30 นาที:

```cron
# THAM Hos-Safe — Smart Pharma (คัดกรอง + แจ้งเตือนอัตโนมัติ)
*/15 7-21 * * * /usr/bin/php /home/co-scph/employee/public/thamhossafe/cron_smart_pharma_telegram.php >> /var/log/thamhossafe_cron.log 2>&1
*/30 0-6,22-23 * * * /usr/bin/php /home/co-scph/employee/public/thamhossafe/cron_smart_pharma_telegram.php >> /var/log/thamhossafe_cron.log 2>&1
```

> แก้ `/usr/bin/php` และ path โปรเจกต์ให้ตรงกับ server จริง

### 4.4 สร้างไฟล์ log

```bash
sudo touch /var/log/thamhossafe_cron.log
sudo chown www-data:www-data /var/log/thamhossafe_cron.log
sudo chmod 644 /var/log/thamhossafe_cron.log
```

ตรวจ log:

```bash
tail -f /var/log/thamhossafe_cron.log
```

---

## 5. ทางเลือก: เรียกผ่าน HTTP

ใช้เมื่อรัน PHP CLI ไม่ได้ แต่เปิด URL ผ่าน web server ได้

### ขั้นตอน

1. เภสัชตั้ง **Cron Secret** ที่  
   `Smart Pharma → แจ้งเตือน Telegram → ตั้งค่าระบบ → Cron Secret`
2. IT ตั้ง crontab เรียก URL:

```cron
*/15 7-21 * * * curl -fsS "https://YOUR-SERVER/thamhossafe/cron_smart_pharma_telegram.php?key=CRON_SECRET" >> /var/log/thamhossafe_cron.log 2>&1
```

- ถ้าไม่ตั้ง Cron Secret หรือ key ผิด → ได้ `403 Forbidden`
- **แนะนำ CLI มากกว่า HTTP** (ปลอดภัยกว่า ไม่ต้องเปิด endpoint สาธารณะ)

---

## 6. ความถี่ Cron ที่เหมาะสม

| Hard repeat สั้นสุดที่เภสัชตั้ง | Cron ที่แนะนำ |
|-------------------------------|--------------|
| 15 นาที | ทุก **15 นาที** |
| 30 นาที | ทุก **15–30 นาที** |
| 60 นาที | ทุก **30 นาที** |
| ไม่ใช้ repeat (ครั้งเดียว) | ทุก **30–60 นาที** |

**กฎ:** Cron ควรถี่ **ไม่เกิน** repeat สั้นสุดที่ตั้งไว้

**แบบประหยัดทรัพยากร** (ถ้า HOSxP ช้า หรือมีคนเปิดหน้า OPD/IPD อยู่บ่อย):

```cron
*/30 * * * * /usr/bin/php /home/co-scph/employee/public/thamhossafe/cron_smart_pharma_telegram.php >> /var/log/thamhossafe_cron.log 2>&1
```

> อย่าตั้งถี่กว่า **10 นาที** เว้นแต่ server และ HOSxP รับโหลดได้ดี

---

## 7. Checklist หลังติดตั้ง

- [ ] รันมือ `php cron_smart_pharma_telegram.php` ได้ JSON ไม่ error
- [ ] crontab บันทึกแล้ว (`crontab -l`)
- [ ] log มีการรันตามเวลา
- [ ] Intervention Log มีรายการหลังพบ Alert จริง
- [ ] Telegram / Webhook ส่งได้ (ถ้าเปิดใช้และตั้งกฎแล้ว)
- [ ] HOSxP และ web server ไม่ช้าผิดปกติ

---

## 8. ข้อความสั้นสำหรับส่ง IT (copy ได้)

```
เรื่อง: ขอตั้ง Cron Job — THAM Hos-Safe Smart Pharma

สคริปต์:
  /home/co-scph/employee/public/thamhossafe/cron_smart_pharma_telegram.php

วิธีรัน (แนะนำ):
  /usr/bin/php /home/co-scph/employee/public/thamhossafe/cron_smart_pharma_telegram.php

ความถี่ (แนะนำ):
  */15 ช่วง 07:00–21:00
  */30 นอกเวลา

Log:
  /var/log/thamhossafe_cron.log

ความต้องการ:
  - PHP CLI (mysqli, curl, json)
  - เชื่อม Hos-Safe DB + HOSxP ได้
  - deploy โปรแกรม Hos-Safe ครบชุดแล้ว (ไม่ต้องส่งไฟล์แยก)

ทดสอบก่อนตั้ง crontab:
  รันมือ 1 ครั้ง ต้องได้ JSON ไม่มี error

ทางเลือก HTTP:
  ตั้ง Cron Secret ในเมนู Telegram แล้วเรียก URL ?key=...
```

---

## 9. ติดต่อ / หมายเหตุ

- การตั้งค่า Telegram, Webhook, กฎแจ้งเตือน, Hard repeat — ทำโดยเภสัชผ่านหน้าเว็บ ไม่ใช่หน้าที่ IT
- IT รับผิดชอบ: Cron, PHP CLI, log, สิทธิ์รัน, network ไป HOSxP
- อัปเดตโปรแกรม: deploy ไฟล์ใหม่แล้วรัน `upgrade.php` — ไม่ต้องเปลี่ยน crontab ถ้า path เดิม

---

*เอกสารนี้สอดคล้องกับ THAM Hos-Safe เวอร์ชัน 1.2.0 ขึ้นไป*
