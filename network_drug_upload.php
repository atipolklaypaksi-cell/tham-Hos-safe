<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/network_drug_helper.php';
include('connect.php');
mysqli_set_charset($con, 'utf8');
nd_ensure_tables($con);
$uploads = nd_list_uploads($con, 10);
$hospital_stats = nd_list_hospitals_with_stats($con);
$expected_cols = nd_expected_columns();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Upload บัญชียาเครือข่าย - THAM SAFE</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
    <style>
        .nd-upload-zone {
            border: 2px dashed #74b49b;
            border-radius: 12px;
            background: #f8fcfa;
            padding: 2rem;
            text-align: center;
            transition: background .2s, border-color .2s;
        }
        .nd-upload-zone.dragover {
            background: #e8f5ef;
            border-color: #1f4d3b;
        }
        .nd-col-badge { font-size: .78rem; }
        .nd-preview-table { font-size: .85rem; }
        .nd-preview-table th { background: #1f4d3b; color: #fff; white-space: nowrap; }
    </style>
</head>
<body>
<div class="wrapper">
    <?php include('sidebar.php'); ?>
    <div id="content" class="content">
        <div class="card shadow-sm border-0">
            <div class="card-header text-white py-3" style="background: var(--green-600, #1f4d3b);">
                <h5 class="mb-0 fw-bold"><i class="fas fa-file-upload me-2"></i>Upload บัญชียาเครือข่าย</h5>
            </div>
            <div class="card-body p-4">
                <p class="text-muted small mb-3">
                    อัปโหลดไฟล์ <strong>CSV</strong> หรือ <strong>Excel (.xlsx, .xls)</strong>
                    — นำเข้าแยกตาม <strong>ชื่อ รพ. (hosname)</strong>:
                    หาก รพ. มีข้อมูลอยู่แล้วจะ<strong>ทับข้อมูลเดิม</strong> หากเป็น รพ. ใหม่จะ<strong>เพิ่มเข้าระบบ</strong>
                    รพ. อื่นในฐานข้อมูลไม่ถูกลบ
                </p>

                <div class="mb-3">
                    <div class="fw-bold small mb-2">คอลัมน์ที่ต้องมีในไฟล์ <span class="text-muted fw-normal">(hosname ว่างได้ — ระบบจะให้กรอกชื่อ รพ.)</span>:</div>
                    <div class="d-flex flex-wrap gap-1">
                        <?php foreach ($expected_cols as $col): ?>
                        <span class="badge bg-secondary nd-col-badge"><?php echo htmlspecialchars($col, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div id="dropZone" class="nd-upload-zone mb-3">
                    <i class="fas fa-cloud-upload-alt fa-2x text-success mb-2"></i>
                    <div class="fw-bold">ลากไฟล์มาวาง หรือคลิกเลือกไฟล์</div>
                    <div class="text-muted small">รองรับ .csv, .xlsx, .xls</div>
                    <input type="file" id="fileInput" accept=".csv,.xlsx,.xls" class="d-none">
                    <button type="button" class="btn btn-success btn-sm mt-3" id="btnPickFile">
                        <i class="fas fa-folder-open me-1"></i> เลือกไฟล์
                    </button>
                </div>

                <div id="fileInfo" class="alert alert-light border d-none mb-3"></div>

                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button type="button" class="btn btn-primary" id="btnImport" disabled>
                        <i class="fas fa-database me-1"></i> นำเข้าข้อมูล
                    </button>
                    <a href="network_drug_list.php" class="btn btn-outline-secondary">
                        <i class="fas fa-table me-1"></i> ดูรายการบัญชียาเครือข่าย
                    </a>
                </div>

                <div id="importProgress" class="progress mb-3 d-none" style="height: 8px;">
                    <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 100%"></div>
                </div>
                <div id="importResult"></div>

                <div id="previewWrap" class="d-none">
                    <h6 class="fw-bold mt-4 mb-2"><i class="fas fa-eye me-1"></i>ตัวอย่างข้อมูล (10 แถวแรก)</h6>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm nd-preview-table">
                            <thead>
                                <tr>
                                    <?php foreach ($expected_cols as $col): ?>
                                    <th><?php echo htmlspecialchars($col, ENT_QUOTES, 'UTF-8'); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody id="previewBody"></tbody>
                        </table>
                    </div>
                </div>

                <?php if (!empty($uploads)): ?>
                <hr class="my-4">
                <h6 class="fw-bold mb-3"><i class="fas fa-history me-1"></i>ประวัติการนำเข้าล่าสุด</h6>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>วันที่</th>
                                <th>ไฟล์</th>
                                <th>ผู้นำเข้า</th>
                                <th class="text-center">แถว</th>
                                <th class="text-center">ยา</th>
                                <th class="text-center">รพ.</th>
                                <th>รพ. ที่นำเข้า</th>
                                <th class="text-center">สถานะ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($uploads as $u): ?>
                            <tr>
                                <td class="small"><?php echo htmlspecialchars($u['uploaded_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($u['filename'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="small"><?php echo htmlspecialchars($u['uploaded_by_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="text-center"><?php echo (int)($u['row_count'] ?? 0); ?></td>
                                <td class="text-center"><?php echo (int)($u['drug_count'] ?? 0); ?></td>
                                <td class="text-center"><?php echo (int)($u['hospital_count'] ?? 0); ?></td>
                                <td class="small">
                                    <?php
                                    $aff = [];
                                    if (!empty($u['hospitals_affected'])) {
                                        $aff = json_decode($u['hospitals_affected'], true);
                                    }
                                    echo htmlspecialchars(is_array($aff) ? implode(', ', $aff) : '-', ENT_QUOTES, 'UTF-8');
                                    ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-info text-dark">บันทึก</span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <hr class="my-4">
                <h6 class="fw-bold mb-2"><i class="fas fa-hospital me-1 text-danger"></i>จัดการข้อมูลโรงพยาบาลในฐานข้อมูล</h6>
                <p class="text-muted small mb-3">เลือกลบข้อมูลของ รพ. ที่ Upload ผิด — จะลบเฉพาะ รพ. ที่เลือก ไม่กระทบ รพ. อื่น</p>
                <div id="hosManageWrap">
                    <?php if (empty($hospital_stats)): ?>
                    <div class="alert alert-light border mb-0">ยังไม่มีข้อมูลโรงพยาบาลในระบบ</div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm align-middle mb-0" id="hosManageTable">
                            <thead class="table-light">
                                <tr>
                                    <th>โรงพยาบาล</th>
                                    <th class="text-center">แถว</th>
                                    <th class="text-center">ยา</th>
                                    <th>อัปเดตล่าสุด</th>
                                    <th class="text-center" width="90">ลบ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($hospital_stats as $hs): ?>
                                <tr data-hos="<?php echo htmlspecialchars($hs['hosname'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    <td class="fw-bold"><?php echo htmlspecialchars($hs['hosname'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="text-center"><?php echo (int)($hs['row_count'] ?? 0); ?></td>
                                    <td class="text-center"><?php echo (int)($hs['drug_count'] ?? 0); ?></td>
                                    <td class="small"><?php echo htmlspecialchars($hs['last_updated'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-outline-danger btn-sm btn-del-hos" title="ลบข้อมูล รพ. นี้">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="hosnameModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header text-white" style="background:#1f4d3b;">
                <h5 class="modal-title fw-bold"><i class="fas fa-hospital me-2"></i>ระบุชื่อโรงพยาบาล</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-3" id="hosnameModalHint">ไฟล์นี้ไม่มีชื่อโรงพยาบาล (hosname ว่าง) กรุณาระบุชื่อ รพ. ที่ข้อมูลในไฟล์นี้เป็นของ</p>
                <label class="form-label fw-bold">ชื่อโรงพยาบาล <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="defaultHosnameInput" placeholder="เช่น รพ.สระแก้ว">
                <div id="importOverwriteInfo" class="alert alert-warning small mt-3 d-none mb-0"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-primary fw-bold" id="btnConfirmImport">
                    <i class="fas fa-database me-1"></i> นำเข้าข้อมูล
                </button>
            </div>
        </div>
    </div>
</div>

<script src="js/jquery-3.7.0.min.js"></script>
<script src="js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
<script>
const expectedCols = <?php echo json_encode($expected_cols, JSON_UNESCAPED_UNICODE); ?>;
let pendingPayload = null;
let pendingFilename = '';
let previewAnalyze = null;
let hosnameModal = null;

function esc(s) {
    return $('<div>').text(s ?? '').html();
}

function showResult(type, html) {
    $('#importResult').html('<div class="alert alert-' + type + ' mb-0">' + html + '</div>');
}

function renderPreview(rows) {
    const $body = $('#previewBody').empty();
    rows.forEach(row => {
        let tr = '<tr>';
        expectedCols.forEach(col => {
            tr += '<td>' + esc(row[col] || '') + '</td>';
        });
        tr += '</tr>';
        $body.append(tr);
    });
    $('#previewWrap').removeClass('d-none');
}

function parseCsvText(text) {
    const rows = [];
    let row = [];
    let cell = '';
    let inQuotes = false;
    for (let i = 0; i < text.length; i++) {
        const ch = text[i];
        const next = text[i + 1];
        if (inQuotes) {
            if (ch === '"' && next === '"') {
                cell += '"';
                i++;
            } else if (ch === '"') {
                inQuotes = false;
            } else {
                cell += ch;
            }
        } else if (ch === '"') {
            inQuotes = true;
        } else if (ch === ',') {
            row.push(cell);
            cell = '';
        } else if (ch === '\r' && next === '\n') {
            row.push(cell);
            rows.push(row);
            row = [];
            cell = '';
            i++;
        } else if (ch === '\n' || ch === '\r') {
            row.push(cell);
            rows.push(row);
            row = [];
            cell = '';
        } else {
            cell += ch;
        }
    }
    if (cell !== '' || row.length) {
        row.push(cell);
        rows.push(row);
    }
    return rows.filter(r => r.some(c => String(c).trim() !== ''));
}

function handleParsedRows(rows, filename) {
    pendingPayload = rows;
    pendingFilename = filename;
    $('#fileInfo').removeClass('d-none').html(
        '<i class="fas fa-file me-1"></i><strong>' + esc(filename) + '</strong> — พบ ' + rows.length + ' แถว (รวมหัวตาราง)'
    );
    $('#btnImport').prop('disabled', rows.length < 2);

    fetch('upload_network_drug_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'preview_rows', rows: rows })
    })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') {
            previewAnalyze = res.analyze || null;
            renderPreview(res.preview || []);
            let msg = 'ตรวจสอบแล้ว พบข้อมูล <strong>' + res.total + '</strong> แถว พร้อมนำเข้า';
            if (res.analyze && res.analyze.needs_hosname) {
                msg += '<br><span class="text-warning"><i class="fas fa-exclamation-triangle me-1"></i>hosname ว่าง ' + res.analyze.empty_hosname_count + ' แถว — ต้องกรอกชื่อ รพ. ก่อนนำเข้า</span>';
            }
            if (res.will_overwrite && res.will_overwrite.length) {
                msg += '<br><span class="text-info"><i class="fas fa-sync me-1"></i>จะทับข้อมูล รพ.: ' + esc(res.will_overwrite.join(', ')) + '</span>';
            }
            if (res.will_add_new && res.will_add_new.length) {
                msg += '<br><span class="text-success"><i class="fas fa-plus-circle me-1"></i>จะเพิ่ม รพ. ใหม่: ' + esc(res.will_add_new.join(', ')) + '</span>';
            }
            showResult('info', msg);
        } else {
            $('#previewWrap').addClass('d-none');
            showResult('warning', esc(res.message || 'หัวตารางไม่ถูกต้อง'));
            $('#btnImport').prop('disabled', true);
        }
    })
    .catch(() => showResult('danger', 'ตรวจสอบไฟล์ไม่สำเร็จ'));
}

function processFile(file) {
    if (!file) return;
    const name = file.name || 'upload';
    const ext = name.split('.').pop().toLowerCase();
    pendingPayload = null;
    pendingFilename = name;
    $('#importResult').empty();
    $('#previewWrap').addClass('d-none');
    $('#btnImport').prop('disabled', true);

    if (ext === 'csv') {
        const reader = new FileReader();
        reader.onload = function(e) {
            const text = e.target.result || '';
            handleParsedRows(parseCsvText(text), name);
        };
        reader.readAsText(file, 'UTF-8');
        return;
    }

    if (ext === 'xlsx' || ext === 'xls') {
        const reader = new FileReader();
        reader.onload = function(e) {
            try {
                const data = new Uint8Array(e.target.result);
                const wb = XLSX.read(data, { type: 'array' });
                const sheet = wb.Sheets[wb.SheetNames[0]];
                const rows = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: '' });
                handleParsedRows(rows, name);
            } catch (err) {
                showResult('danger', 'อ่านไฟล์ Excel ไม่สำเร็จ');
            }
        };
        reader.readAsArrayBuffer(file);
        return;
    }
    showResult('warning', 'รองรับเฉพาะ .csv, .xlsx, .xls');
}

$('#btnPickFile, #dropZone').on('click', function(e) {
    if (e.target.id !== 'fileInput') {
        $('#fileInput').trigger('click');
    }
});
$('#fileInput').on('change', function() {
    processFile(this.files[0]);
});

const dropZone = document.getElementById('dropZone');
['dragenter', 'dragover'].forEach(evt => {
    dropZone.addEventListener(evt, e => {
        e.preventDefault();
        dropZone.classList.add('dragover');
    });
});
['dragleave', 'drop'].forEach(evt => {
    dropZone.addEventListener(evt, e => {
        e.preventDefault();
        dropZone.classList.remove('dragover');
    });
});
dropZone.addEventListener('drop', e => {
    const file = e.dataTransfer.files[0];
    processFile(file);
});

$('#btnImport').on('click', function() {
    if (!pendingPayload || !pendingFilename) return;
    if (previewAnalyze && previewAnalyze.needs_hosname) {
        $('#defaultHosnameInput').val('');
        $('#hosnameModalHint').text(
            'ไฟล์นี้มี hosname ว่าง ' + previewAnalyze.empty_hosname_count + ' แถว — กรุณาระบุชื่อ รพ. ที่ข้อมูลในไฟล์นี้เป็นของ'
        );
        $('#importOverwriteInfo').addClass('d-none');
        hosnameModal.show();
        return;
    }
    doImport('');
});

$('#btnConfirmImport').on('click', function() {
    const hosname = $('#defaultHosnameInput').val().trim();
    if (!hosname) {
        $('#defaultHosnameInput').addClass('is-invalid');
        return;
    }
    $('#defaultHosnameInput').removeClass('is-invalid');
    hosnameModal.hide();
    doImport(hosname);
});

function doImport(defaultHosname) {
    if (!pendingPayload || !pendingFilename) return;
    $('#importProgress').removeClass('d-none');
    $('#btnImport').prop('disabled', true);

    fetch('upload_network_drug_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'import_rows',
            filename: pendingFilename,
            rows: pendingPayload,
            default_hosname: defaultHosname
        })
    })
    .then(r => r.json())
    .then(res => {
        $('#importProgress').addClass('d-none');
        if (res.status === 'success') {
            let msg = '<i class="fas fa-check-circle me-1"></i>นำเข้าสำเร็จ ' + res.row_count + ' แถว | ' + res.drug_count + ' ยา | ' + res.hospital_count + ' รพ.';
            if (res.overwritten_hospitals && res.overwritten_hospitals.length) {
                msg += '<br>ทับข้อมูล: ' + esc(res.overwritten_hospitals.join(', '));
            }
            if (res.new_hospitals && res.new_hospitals.length) {
                msg += '<br>เพิ่มใหม่: ' + esc(res.new_hospitals.join(', '));
            }
            msg += ' <a href="network_drug_list.php" class="alert-link ms-2">ดูรายการ</a>';
            showResult('success', msg);
            setTimeout(() => location.reload(), 2000);
        } else if (res.needs_hosname) {
            showResult('warning', esc(res.message || 'กรุณาระบุชื่อโรงพยาบาล'));
            hosnameModal.show();
            $('#btnImport').prop('disabled', false);
        } else {
            showResult('danger', esc(res.message || 'นำเข้าไม่สำเร็จ'));
            $('#btnImport').prop('disabled', false);
        }
    })
    .catch(() => {
        $('#importProgress').addClass('d-none');
        showResult('danger', 'นำเข้าข้อมูลไม่สำเร็จ');
        $('#btnImport').prop('disabled', false);
    });
}

$(document).on('click', '.btn-del-hos', function() {
    const hos = $(this).closest('tr').data('hos');
    if (!hos) return;
    if (!confirm('ยืนยันลบข้อมูลทั้งหมดของ\n"' + hos + '"\nจากฐานข้อมูล?')) return;
    const $btn = $(this).prop('disabled', true);
    fetch('upload_network_drug_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete_hospital', hosname: hos })
    })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') {
            showResult('success', 'ลบข้อมูล ' + esc(hos) + ' สำเร็จ (' + res.deleted_rows + ' แถว)');
            setTimeout(() => location.reload(), 1200);
        } else {
            showResult('danger', esc(res.message || 'ลบไม่สำเร็จ'));
            $btn.prop('disabled', false);
        }
    })
    .catch(() => {
        showResult('danger', 'ลบข้อมูลไม่สำเร็จ');
        $btn.prop('disabled', false);
    });
});

$(function() {
    hosnameModal = new bootstrap.Modal(document.getElementById('hosnameModal'));
});
</script>
</body>
</html>
