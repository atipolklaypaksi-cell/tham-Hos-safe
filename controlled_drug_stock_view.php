<?php
/**
 * Shared stock view — ตั้ง $cdm_type = 'narcotic' | 'psychotropic' ก่อน include
 */
if (!isset($cdm_type) || cdm_normalize_type($cdm_type) === '') {
    die('Invalid drug type');
}
$cdm_type = cdm_normalize_type($cdm_type);
$cdm_label = cdm_type_label($cdm_type);
$cdm_page_file = $cdm_type === 'narcotic' ? 'controlled_drug_narcotic.php' : 'controlled_drug_psychotropic.php';
$cdm_settings_file = $cdm_type === 'narcotic' ? 'controlled_drug_settings_narcotic.php' : 'controlled_drug_settings_psychotropic.php';
$cdm_theme = $cdm_type === 'narcotic' ? '#7c2d12' : '#4c1d95';
$cdm_theme_light = $cdm_type === 'narcotic' ? '#fff7ed' : '#f5f3ff';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($cdm_label) ?> - บริหารยาเสพติด/วัตถุออกฤทธิ์</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
    <link href="css/flatpickr.min.css" rel="stylesheet">
    <link href="css/select2.min.css" rel="stylesheet">
    <link href="css/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <style>
        :root { --cdm-primary: <?= $cdm_theme ?>; --cdm-light: <?= $cdm_theme_light ?>; }
        .cdm-hero {
            background: linear-gradient(135deg, var(--cdm-primary) 0%, #1f4d3b 100%);
            color: #fff; border-radius: 12px; padding: 1.25rem 1.5rem;
        }
        .cdm-hero h4 { font-weight: 800; margin: 0; }
        .cdm-stat {
            background: var(--cdm-light); border: 1px solid rgba(0,0,0,.06);
            border-radius: 10px; padding: .85rem 1rem;
        }
        .cdm-stat .num { font-size: 1.35rem; font-weight: 800; color: var(--cdm-primary); }
        #stockTable thead th { background: var(--cdm-primary); color: #fff; font-size: .82rem; }
        #stockTable { font-size: .86rem; }
        .filter-box { background: var(--cdm-light); border: 1px solid rgba(0,0,0,.06); border-radius: 10px; }
        .btn-cdm { background: var(--cdm-primary); border-color: var(--cdm-primary); color: #fff; }
        .btn-cdm:hover { filter: brightness(1.08); color: #fff; }
        #previewTable thead th { background: #1f4d3b; color: #fff; font-size: .82rem; }
        #movesTable thead th { background: #334155; color: #fff; font-size: .82rem; }
        .cdm-tabs .nav-link { font-weight: 700; color: #495057; }
        .cdm-tabs .nav-link.active { color: var(--cdm-primary); border-color: #dee2e6 #dee2e6 #fff; }
        .cdm-lot-box {
            border: 1px solid rgba(0,0,0,.1); border-radius: 8px; padding: .35rem .5rem;
            background: #fff; font-size: .76rem; min-width: 130px;
        }
        .cdm-lot-box .lot-qty { font-weight: 800; color: var(--cdm-primary); }
        .cdm-minmax-val { font-size: 1.15rem; font-weight: 800; line-height: 1.1; }
        .cdm-minmax-hint { font-size: .72rem; color: #2563eb; }
        .cdm-stock-qty-low { color: #dc2626 !important; }
        #stockTable td { vertical-align: top; }
    </style>
</head>
<body>
<div class="wrapper">
    <?php include('sidebar.php'); ?>
    <div id="content" class="content p-4">
        <div class="cdm-hero mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h4><i class="fas fa-pills me-2"></i><?= htmlspecialchars($cdm_label) ?></h4>
                <small class="opacity-90">จ่ายยา ทะเบียน และสต๊อกคงคลัง — ดึงข้อมูลจาก HOSxP</small>
            </div>
            <a href="<?= htmlspecialchars($cdm_settings_file) ?>" class="btn btn-light btn-sm fw-bold">
                <i class="fas fa-cog me-1"></i>ตั้งค่ารายการยา
            </a>
        </div>

        <?php
        $cdm_initial_tab = $cdm_initial_tab ?? 'dispense';
        $cdm_tab_active = [
            'dispense' => $cdm_initial_tab === 'dispense',
            'register' => $cdm_initial_tab === 'register',
            'stock' => $cdm_initial_tab === 'stock',
        ];
        if (!$cdm_tab_active['dispense'] && !$cdm_tab_active['register'] && !$cdm_tab_active['stock']) {
            $cdm_tab_active = ['dispense' => true, 'register' => false, 'stock' => false];
        }
        ?>
        <ul class="nav nav-tabs cdm-tabs mb-3" role="tablist">
            <li class="nav-item">
                <button class="nav-link<?= $cdm_tab_active['dispense'] ? ' active' : '' ?>" id="tab-dispense-btn" data-bs-toggle="tab" data-bs-target="#tabDispenseWrap" type="button">
                    <i class="fas fa-hand-holding-medical me-1"></i>จ่ายยา
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link<?= $cdm_tab_active['register'] ? ' active' : '' ?>" id="tab-register-btn" data-bs-toggle="tab" data-bs-target="#tabRegisterWrap" type="button">
                    <i class="fas fa-book-medical me-1"></i>ทะเบียนจ่ายยา
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link<?= $cdm_tab_active['stock'] ? ' active' : '' ?>" id="tab-stock-btn" data-bs-toggle="tab" data-bs-target="#tabStockWrap" type="button">
                    <i class="fas fa-boxes me-1"></i>สต๊อกคงคลัง
                </button>
            </li>
        </ul>

        <div class="tab-content">
            <div class="tab-pane fade<?= $cdm_tab_active['dispense'] ? ' show active' : '' ?>" id="tabDispenseWrap">
                <?php
                $cdm_is_register = false;
                $cdm_id_prefix = 'dsp';
                $cdm_list_only = true;
                include __DIR__ . '/controlled_drug_dispense_view.php';
                ?>
            </div>
            <div class="tab-pane fade<?= $cdm_tab_active['register'] ? ' show active' : '' ?>" id="tabRegisterWrap">
                <?php
                $cdm_is_register = true;
                $cdm_id_prefix = 'reg';
                $cdm_list_only = true;
                include __DIR__ . '/controlled_drug_dispense_view.php';
                ?>
            </div>
            <div class="tab-pane fade<?= $cdm_tab_active['stock'] ? ' show active' : '' ?>" id="tabStockWrap">
        <div id="tabStockPane">
        <div class="row g-2 mb-3">
            <div class="col-md-3 col-6"><div class="cdm-stat"><div class="small text-muted">รายการยา</div><div class="num" id="statItems">0</div></div></div>
            <div class="col-md-3 col-6"><div class="cdm-stat"><div class="small text-muted">ยอดคงคลังรวม</div><div class="num" id="statQty">0</div></div></div>
            <div class="col-md-3 col-6"><div class="cdm-stat"><div class="small text-muted">ต่ำกว่า Min</div><div class="num text-danger" id="statBelowMin">0</div></div></div>
            <div class="col-md-3 col-6"><div class="cdm-stat"><div class="small text-muted">EXP เสี่ยง</div><div class="num text-warning" id="statExpRisk">0</div></div></div>
        </div>

        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body filter-box py-2">
                <div class="row g-2 align-items-end">
                    <div class="col-md-2 col-6">
                        <label class="form-label small fw-bold mb-1">ดูอัตราใช้ย้อนหลัง</label>
                        <select id="usageMonths" class="form-select form-select-sm">
                            <option value="1">1 เดือน</option>
                            <option value="2" selected>2 เดือน</option>
                            <option value="3">3 เดือน</option>
                            <option value="6">6 เดือน</option>
                        </select>
                    </div>
                    <div class="col-md-2 col-6">
                        <label class="form-label small fw-bold mb-1">สำรอง Min</label>
                        <select id="minReserveMonths" class="form-select form-select-sm">
                            <option value="1" selected>1 เดือน</option>
                            <option value="2">2 เดือน</option>
                            <option value="3">3 เดือน</option>
                        </select>
                    </div>
                    <div class="col-md-2 col-6">
                        <label class="form-label small fw-bold mb-1">สำรอง Max</label>
                        <select id="maxReserveMonths" class="form-select form-select-sm">
                            <option value="2">2 เดือน</option>
                            <option value="3" selected>3 เดือน</option>
                            <option value="6">6 เดือน</option>
                        </select>
                    </div>
                    <div class="col-md-4 col-12">
                        <div class="small text-muted">
                            <span class="badge bg-success me-1">Pass</span> ใช้หมดก่อนหมดอายุ
                            <span class="badge bg-warning text-dark me-1">Caution</span> เสี่ยงค้าง
                            <span class="badge bg-danger me-1">Alert</span> เสี่ยงหมดอายุ
                        </div>
                    </div>
                    <div class="col-md-2 col-12 text-md-end">
                        <button type="button" class="btn btn-outline-primary btn-sm w-100 mb-1" id="btnApplyMinmaxAll">
                            <i class="fas fa-magic me-1"></i>ใช้คำแนะนำทั้งหมด
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body filter-box">
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small fw-bold mb-1">ห้องสต๊อก / คลัง</label>
                        <select id="roomFilter" class="form-select form-select-sm"></select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold mb-1">กรองรายการ</label>
                        <select id="statusFilter" class="form-select form-select-sm">
                            <option value="">ทั้งหมด</option>
                            <option value="below_min">ต่ำกว่า Min</option>
                            <option value="exp_risk">EXP เสี่ยงหมดอายุ</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold mb-1">ค้นหารายการ</label>
                        <input type="text" id="searchInput" class="form-control form-control-sm" placeholder="icode, ชื่อยา...">
                    </div>
                    <div class="col-md-4 text-md-end">
                        <button type="button" class="btn btn-cdm btn-sm mb-1" id="btnOpenSync">
                            <i class="fas fa-cloud-download-alt me-1"></i>ดึงรายงานจาก HOSxP
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm mb-1" id="btnReload">
                            <i class="fas fa-sync-alt me-1"></i>รีเฟรช
                        </button>
                    </div>
                </div>
                <div class="small text-muted mt-1" id="roomStockHint"></div>
            </div>
        </div>

        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="stockTable">
                        <thead>
                            <tr>
                                <th>icode</th>
                                <th style="min-width:180px">ชื่อยา</th>
                                <th class="text-end" style="width:80px">คงเหลือ</th>
                                <th class="text-center" style="width:70px">Min</th>
                                <th class="text-center" style="width:70px">Max</th>
                                <th style="min-width:200px">Lot &amp; EXP</th>
                                <th class="text-center" style="width:80px">EXP Alert</th>
                                <th style="width:120px">ระดับสต๊อก</th>
                                <th class="text-center" style="width:150px">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody id="stockBody">
                            <tr><td colspan="9" class="text-center text-muted py-4">กำลังโหลด...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-header bg-white fw-bold"><i class="fas fa-history me-2"></i>ประวัติการเคลื่อนไหวสต๊อกล่าสุด</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0" id="movesTable">
                        <thead>
                            <tr>
                                <th>วันที่</th>
                                <th>icode</th>
                                <th>ชื่อยา</th>
                                <th>ประเภท</th>
                                <th class="text-end">เปลี่ยนแปลง</th>
                                <th class="text-end">คงเหลือ</th>
                                <th>หมายเหตุ</th>
                            </tr>
                        </thead>
                        <tbody id="movesBody">
                            <tr><td colspan="7" class="text-center text-muted py-3">กำลังโหลด...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        </div><!-- /tabStockPane -->
            </div><!-- /tabStockWrap -->
        </div><!-- /tab-content -->
    </div>
</div>

<!-- Modal: Min/Max -->
<div class="modal fade" id="minmaxModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">กำหนด Min / Max</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="minmaxItemId">
                <div class="mb-2"><strong id="minmaxDrugName"></strong></div>
                <div class="alert alert-light border small mb-3" id="minmaxSuggestHint"></div>
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label fw-bold">Min</label>
                        <input type="number" step="1" min="0" id="minmaxMin" class="form-control">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold">Max</label>
                        <input type="number" step="1" min="0" id="minmaxMax" class="form-control">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-primary btn-sm" id="btnUseMinmaxSuggest">ใช้คำแนะนำ</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-cdm" id="btnSaveMinmax">บันทึก</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Lot -->
<div class="modal fade" id="lotModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-boxes me-2"></i>จัดการ Lot &amp; EXP</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="lotItemId">
                <input type="hidden" id="lotIcode">
                <div class="mb-2"><strong id="lotDrugName"></strong></div>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered mb-0">
                        <thead><tr><th>Lot</th><th>EXP</th><th class="text-end">จำนวน</th><th>หมายเหตุ</th><th></th></tr></thead>
                        <tbody id="lotListBody"><tr><td colspan="5" class="text-muted text-center">ไม่มี Lot</td></tr></tbody>
                    </table>
                </div>
                <hr>
                <div class="fw-bold small mb-2" id="lotFormTitle">เพิ่ม Lot ใหม่</div>
                <input type="hidden" id="lotEditId" value="0">
                <div class="row g-2">
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">Lot No.</label>
                        <input type="text" id="lotNo" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">EXP</label>
                        <input type="text" id="lotExp" class="form-control form-control-sm cdm-date">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold">จำนวน</label>
                        <input type="number" step="0.01" min="0" id="lotQty" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">หมายเหตุ</label>
                        <input type="text" id="lotNote" class="form-control form-control-sm" maxlength="255">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnClearLotForm">ล้างฟอร์ม</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                <button type="button" class="btn btn-cdm" id="btnSaveLot"><i class="fas fa-save me-1"></i>บันทึก Lot</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: ปรับยอด -->
<div class="modal fade" id="adjustModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">ปรับยอดคงคลัง</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="adjustItemId">
                <div class="mb-2"><strong id="adjustDrugName"></strong></div>
                <div class="mb-3">
                    <label class="form-label fw-bold">ยอดคงคลังใหม่</label>
                    <input type="number" step="0.01" id="adjustQty" class="form-control">
                </div>
                <div class="mb-2">
                    <label class="form-label fw-bold">หมายเหตุ</label>
                    <input type="text" id="adjustNote" class="form-control" maxlength="255">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-cdm" id="btnSaveAdjust">บันทึก</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: ดึงจาก HOSxP -->
<div class="modal fade" id="syncModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-cloud-download-alt me-2"></i>ดึงรายงานการใช้จาก HOSxP</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">ตัดสต๊อกจากห้อง</label>
                        <select id="syncRoomId" class="form-select form-select-sm"></select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">วันที่เริ่ม</label>
                        <input type="text" id="syncStart" class="form-control cdm-date" value="<?= date('Y-m-01') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">วันที่สิ้นสุด</label>
                        <input type="text" id="syncEnd" class="form-control cdm-date" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="button" class="btn btn-outline-primary w-100" id="btnPreviewSync">
                            <i class="fas fa-search me-1"></i>ดูรายงานก่อนตัดสต๊อก
                        </button>
                    </div>
                </div>
                <div id="syncAlert" class="alert d-none"></div>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered" id="previewTable">
                        <thead>
                            <tr>
                                <th>icode</th>
                                <th>ชื่อยา</th>
                                <th class="text-end">ใช้จาก HOSxP</th>
                                <th class="text-end">คงคลังปัจจุบัน</th>
                                <th class="text-end">หลังตัดสต๊อก</th>
                                <th>หน่วย</th>
                                <th class="text-center">ตั้งค่าแล้ว</th>
                            </tr>
                        </thead>
                        <tbody id="previewBody">
                            <tr><td colspan="7" class="text-center text-muted py-3">เลือกช่วงวันที่แล้วกดดูรายงาน</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                <button type="button" class="btn btn-cdm" id="btnApplySync" disabled>
                    <i class="fas fa-cut me-1"></i>ยืนยันตัดสต๊อก
                </button>
            </div>
        </div>
    </div>
</div>

<script src="js/jquery-3.7.0.min.js"></script>
<script src="js/bootstrap.bundle.min.js"></script>
<script src="js/select2.min.js"></script>
<script src="js/flatpickr.min.js"></script>
<?php
unset($cdm_list_only, $cdm_is_register, $cdm_id_prefix);
$cdm_render_shared = true;
include __DIR__ . '/controlled_drug_dispense_view.php';
?>
<script>
const CDM_TYPE = <?= json_encode($cdm_type) ?>;
let stockRows = [];
let cdmRooms = [];
let activeRoomId = 0;
let previewReady = false;
let activeMinmaxSuggest = null;

function roomStorageKey() {
    return 'cdm_room_' + CDM_TYPE;
}

function activeRoom() {
    return cdmRooms.find(function(r) { return Number(r.id) === Number(activeRoomId); }) || null;
}

function populateRoomSelects(selectedId) {
    const saved = parseInt(sessionStorage.getItem(roomStorageKey()) || '0', 10);
    activeRoomId = selectedId || saved || (cdmRooms[0] ? Number(cdmRooms[0].id) : 0);
    const opts = cdmRooms.map(function(r) {
        const label = r.room_name + (r.service_mode && r.service_mode !== 'all' ? ' (' + (r.service_mode_label || r.service_mode).toUpperCase() + ')' : '');
        return '<option value="' + r.id + '"' + (Number(r.id) === Number(activeRoomId) ? ' selected' : '') + '>' + esc(label) + '</option>';
    }).join('');
    ['roomFilter', 'syncRoomId'].forEach(function(id) {
        const el = document.getElementById(id);
        if (el) el.innerHTML = opts || '<option value="">ไม่มีห้อง</option>';
    });
    if (activeRoomId) sessionStorage.setItem(roomStorageKey(), String(activeRoomId));
    const hint = document.getElementById('roomStockHint');
    if (hint) {
        const room = activeRoom();
        hint.textContent = room
            ? ('แสดงยอดคงคลังของห้อง: ' + room.room_name + ' — ตัดสต๊อก HOSxP และปรับยอดจะมีผลกับห้องนี้')
            : '';
    }
}

function stockLoadParams() {
    return {
        status_filter: document.getElementById('statusFilter').value,
        room_id: activeRoomId,
        usage_months: Number(document.getElementById('usageMonths').value || 2),
        min_reserve_months: Number(document.getElementById('minReserveMonths').value || 1),
        max_reserve_months: Number(document.getElementById('maxReserveMonths').value || 3),
    };
}

function renderLotBoxes(lots) {
    if (!lots || !lots.length) {
        return '<span class="text-muted small">ยังไม่มี Lot</span>';
    }
    return '<div class="d-flex flex-wrap gap-1">' + lots.map(function(l) {
        const exp = l.exp_display || l.exp_date || '-';
        const lotNo = l.lot_no || '-';
        return '<div class="cdm-lot-box">' +
            '<div><strong>Lot:</strong> ' + esc(lotNo) + '</div>' +
            '<div><strong>EXP:</strong> ' + esc(exp) + '</div>' +
            '<div class="text-end lot-qty">เหลือ: ' + fmtQty(l.qty) + '</div>' +
        '</div>';
    }).join('') + '</div>';
}

function renderMinMaxCell(val, suggest, field) {
    const v = val != null && val !== '' ? fmtQty(val) : '-';
    const s = suggest && suggest['suggest_' + field] != null ? suggest['suggest_' + field] : null;
    let hint = '';
    if (s != null && Number(s) > 0) {
        hint = '<div class="cdm-minmax-hint">แนะนำ: ' + s + '</div>';
    }
    return '<div class="cdm-minmax-val">' + v + '</div>' + hint;
}

function renderInventoryStatusBadges(r) {
    if (r.inventory_status) {
        return '<span class="badge ' + esc(r.inventory_status.class) + '">' + esc(r.inventory_status.label) + '</span>';
    }
    return '<span class="badge bg-success">ปกติ</span>';
}

function esc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function fmtQty(n) {
    n = Number(n || 0);
    return Number.isInteger(n) ? n.toLocaleString() : n.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function apiPost(action, data) {
    return fetch('fetch_controlled_drug_api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(Object.assign({action: action, drug_type: CDM_TYPE}, data || {}))
    }).then(function(r) {
        return r.text().then(function(text) {
            try {
                return JSON.parse(text);
            } catch (e) {
                throw new Error(text ? text.substring(0, 200) : 'เซิร์ฟเวอร์ไม่ส่ง JSON');
            }
        });
    });
}

function loadStock() {
    return apiPost('list', stockLoadParams()).then(function(res) {
        if (res.status !== 'success') throw new Error(res.message || 'โหลดไม่สำเร็จ');
        stockRows = res.items || [];
        cdmRooms = res.rooms || cdmRooms;
        populateRoomSelects(res.room_id || activeRoomId);
        renderStock();
        const s = res.summary || {};
        document.getElementById('statItems').textContent = s.total_items || 0;
        document.getElementById('statQty').textContent = fmtQty(s.total_qty || 0);
        document.getElementById('statBelowMin').textContent = s.below_min || 0;
        document.getElementById('statExpRisk').textContent = s.exp_risk || 0;
    });
}

function loadMoves() {
    return apiPost('moves', {limit: 30}).then(function(res) {
        const body = document.getElementById('movesBody');
        if (res.status !== 'success' || !res.rows || !res.rows.length) {
            body.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">ยังไม่มีประวัติ</td></tr>';
            return;
        }
        body.innerHTML = res.rows.map(function(r) {
            const chg = Number(r.qty_change || 0);
            const chgCls = chg < 0 ? 'text-danger' : (chg > 0 ? 'text-success' : '');
            const moveLabels = {opening:'ยอดยกมา', hosxp_usage:'ตัดจาก HOSxP', adjust:'ปรับยอด', manual:'บันทึกเอง'};
            let note = r.note || '';
            if (r.ref_date_start && r.ref_date_end) {
                note += (note ? ' ' : '') + '(' + r.ref_date_start + ' ถึง ' + r.ref_date_end + ')';
            }
            return '<tr>' +
                '<td>' + esc(r.move_date) + '</td>' +
                '<td>' + esc(r.icode) + '</td>' +
                '<td>' + esc(r.drug_name || '') + '</td>' +
                '<td>' + esc(moveLabels[r.move_type] || r.move_type) + '</td>' +
                '<td class="text-end ' + chgCls + ' fw-bold">' + (chg > 0 ? '+' : '') + fmtQty(chg) + '</td>' +
                '<td class="text-end">' + fmtQty(r.qty_after) + '</td>' +
                '<td class="small">' + esc(note) + '</td>' +
            '</tr>';
        }).join('');
    });
}

function renderStock() {
    const q = (document.getElementById('searchInput').value || '').toLowerCase().trim();
    const filtered = stockRows.filter(function(r) {
        if (!q) return true;
        return (r.icode || '').toLowerCase().includes(q) ||
            (r.drug_name || '').toLowerCase().includes(q);
    });
    const body = document.getElementById('stockBody');
    if (!filtered.length) {
        body.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">ไม่พบรายการ — กรุณาตั้งค่ารายการยาก่อน</td></tr>';
        return;
    }
    body.innerHTML = filtered.map(function(r) {
        const qtyCls = r.below_min ? 'cdm-stock-qty-low' : '';
        const exp = r.exp_alert || {label: '-', class: 'bg-secondary'};
        const suggest = r.minmax_suggest || {};
        return '<tr>' +
            '<td><code>' + esc(r.icode) + '</code></td>' +
            '<td><div>' + esc(r.drug_name) + '</div><div class="small text-muted">' + esc(r.unit || '') + '</div></td>' +
            '<td class="text-end fw-bold ' + qtyCls + '">' + fmtQty(r.current_qty) +
                (r.total_qty != null && Number(r.total_qty) !== Number(r.current_qty)
                    ? '<div class="small text-muted fw-normal">รวมทุกห้อง ' + fmtQty(r.total_qty) + '</div>' : '') +
            '</td>' +
            '<td class="text-center">' + renderMinMaxCell(r.min_stock, suggest, 'min') + '</td>' +
            '<td class="text-center">' + renderMinMaxCell(r.max_stock, suggest, 'max') + '</td>' +
            '<td>' + renderLotBoxes(r.lots || []) + '</td>' +
            '<td class="text-center"><span class="badge ' + esc(exp.class || 'bg-secondary') + '">' + esc(exp.label || '-') + '</span></td>' +
            '<td>' + renderInventoryStatusBadges(r) + '</td>' +
            '<td class="text-center text-nowrap">' +
                '<button class="btn btn-success btn-sm py-0 px-1 me-1 btn-lot" data-id="' + r.id + '" title="Lot"><i class="fas fa-plus"></i></button>' +
                '<button class="btn btn-warning btn-sm py-0 px-1 me-1 btn-minmax" data-id="' + r.id + '" title="Min/Max"><i class="fas fa-sliders-h"></i></button>' +
                '<button class="btn btn-outline-secondary btn-sm py-0 px-1 btn-adjust" data-id="' + r.id + '">ปรับยอด</button>' +
            '</td>' +
        '</tr>';
    }).join('');
}

function showAlert(el, type, msg) {
    el.className = 'alert alert-' + type;
    el.textContent = msg;
    el.classList.remove('d-none');
}

document.getElementById('statusFilter').addEventListener('change', function() { loadStock(); });
document.getElementById('roomFilter').addEventListener('change', function() {
    activeRoomId = Number(this.value) || 0;
    sessionStorage.setItem(roomStorageKey(), String(activeRoomId));
    populateRoomSelects(activeRoomId);
    loadStock();
});
document.getElementById('usageMonths').addEventListener('change', function() { loadStock(); });
document.getElementById('minReserveMonths').addEventListener('change', function() { loadStock(); });
document.getElementById('maxReserveMonths').addEventListener('change', function() { loadStock(); });
document.getElementById('searchInput').addEventListener('input', renderStock);
document.getElementById('btnReload').addEventListener('click', function() { reloadAll(); });

document.getElementById('btnApplyMinmaxAll').addEventListener('click', function() {
    if (!confirm('ใช้คำแนะนำ Min/Max กับทุกรายการที่มีข้อมูลการใช้?')) return;
    const btn = this;
    btn.disabled = true;
    apiPost('apply_minmax_all', stockLoadParams()).then(function(res) {
        btn.disabled = false;
        if (res.status !== 'success') { alert(res.message || 'ไม่สำเร็จ'); return; }
        alert(res.message || 'บันทึกแล้ว');
        reloadAll();
    }).catch(function(err) { btn.disabled = false; alert(err.message); });
});

function clearLotForm() {
    document.getElementById('lotEditId').value = '0';
    document.getElementById('lotFormTitle').textContent = 'เพิ่ม Lot ใหม่';
    document.getElementById('lotNo').value = '';
    document.getElementById('lotExp').value = '';
    document.getElementById('lotQty').value = '';
    document.getElementById('lotNote').value = '';
}

function renderLotList(lots) {
    const body = document.getElementById('lotListBody');
    if (!lots || !lots.length) {
        body.innerHTML = '<tr><td colspan="5" class="text-muted text-center">ไม่มี Lot</td></tr>';
        return;
    }
    body.innerHTML = lots.filter(function(l) { return Number(l.is_active) === 1; }).map(function(l) {
        return '<tr>' +
            '<td>' + esc(l.lot_no || '-') + '</td>' +
            '<td>' + esc(l.exp_display || l.exp_date || '-') + '</td>' +
            '<td class="text-end fw-bold">' + fmtQty(l.qty) + '</td>' +
            '<td class="small">' + esc(l.note || '') + '</td>' +
            '<td class="text-nowrap">' +
                '<button type="button" class="btn btn-link btn-sm p-0 me-2 btn-edit-lot" data-id="' + l.id + '">แก้ไข</button>' +
                '<button type="button" class="btn btn-link btn-sm p-0 text-danger btn-del-lot" data-id="' + l.id + '">ลบ</button>' +
            '</td></tr>';
    }).join('') || '<tr><td colspan="5" class="text-muted text-center">ไม่มี Lot</td></tr>';
}

function openLotModal(item) {
    document.getElementById('lotItemId').value = item.id;
    document.getElementById('lotIcode').value = item.icode;
    document.getElementById('lotDrugName').textContent = item.drug_name + ' (' + item.icode + ')';
    clearLotForm();
    renderLotList(item.lots || []);
    new bootstrap.Modal(document.getElementById('lotModal')).show();
}

function openMinmaxModal(item) {
    activeMinmaxSuggest = item.minmax_suggest || null;
    document.getElementById('minmaxItemId').value = item.id;
    document.getElementById('minmaxDrugName').textContent = item.drug_name + ' (' + item.icode + ')';
    document.getElementById('minmaxMin').value = item.min_stock != null ? item.min_stock : '';
    document.getElementById('minmaxMax').value = item.max_stock != null ? item.max_stock : '';
    const s = activeMinmaxSuggest;
    const hint = s && Number(s.monthly_avg) > 0
        ? 'ใช้เฉลี่ย ' + fmtQty(s.monthly_avg) + ' / เดือน (รวม ' + fmtQty(s.usage_total) + ' ใน ' + document.getElementById('usageMonths').value + ' เดือน) — แนะนำ Min ' + (s.suggest_min || 0) + ', Max ' + (s.suggest_max || 0)
        : 'ยังไม่มีข้อมูลการใช้ย้อนหลัง — กำหนด Min/Max เองได้';
    document.getElementById('minmaxSuggestHint').textContent = hint;
    new bootstrap.Modal(document.getElementById('minmaxModal')).show();
}

document.getElementById('stockBody').addEventListener('click', function(e) {
    const adjustBtn = e.target.closest('.btn-adjust');
    const lotBtn = e.target.closest('.btn-lot');
    const minmaxBtn = e.target.closest('.btn-minmax');
    if (lotBtn) {
        const id = Number(lotBtn.dataset.id);
        const item = stockRows.find(function(r) { return Number(r.id) === id; });
        if (item) openLotModal(item);
        return;
    }
    if (minmaxBtn) {
        const id = Number(minmaxBtn.dataset.id);
        const item = stockRows.find(function(r) { return Number(r.id) === id; });
        if (item) openMinmaxModal(item);
        return;
    }
    if (adjustBtn) {
        const id = Number(adjustBtn.dataset.id);
        const item = stockRows.find(function(r) { return Number(r.id) === id; });
        if (!item) return;
        document.getElementById('adjustItemId').value = id;
        document.getElementById('adjustDrugName').textContent = item.drug_name + ' (' + item.icode + ')';
        document.getElementById('adjustQty').value = item.current_qty;
        document.getElementById('adjustNote').value = '';
        const room = activeRoom();
        document.getElementById('adjustDrugName').textContent += room ? ' — ' + room.room_name : '';
        new bootstrap.Modal(document.getElementById('adjustModal')).show();
    }
});

document.getElementById('btnSaveAdjust').addEventListener('click', function() {
    const btn = this;
    btn.disabled = true;
    apiPost('adjust_stock', {
        id: Number(document.getElementById('adjustItemId').value),
        room_id: activeRoomId,
        new_qty: document.getElementById('adjustQty').value,
        note: document.getElementById('adjustNote').value
    }).then(function(res) {
        btn.disabled = false;
        if (res.status !== 'success') { alert(res.message || 'บันทึกไม่สำเร็จ'); return; }
        bootstrap.Modal.getInstance(document.getElementById('adjustModal')).hide();
        reloadAll();
    }).catch(function(err) { btn.disabled = false; alert(err.message); });
});

document.getElementById('btnClearLotForm').addEventListener('click', clearLotForm);

document.getElementById('lotListBody').addEventListener('click', function(e) {
    const editBtn = e.target.closest('.btn-edit-lot');
    const delBtn = e.target.closest('.btn-del-lot');
    const icode = document.getElementById('lotIcode').value;
    const item = stockRows.find(function(r) { return r.icode === icode; });
    if (editBtn && item) {
        const lot = (item.lots || []).find(function(l) { return Number(l.id) === Number(editBtn.dataset.id); });
        if (!lot) return;
        document.getElementById('lotEditId').value = lot.id;
        document.getElementById('lotFormTitle').textContent = 'แก้ไข Lot';
        document.getElementById('lotNo').value = lot.lot_no || '';
        document.getElementById('lotExp').value = lot.exp_date || '';
        document.getElementById('lotQty').value = lot.qty;
        document.getElementById('lotNote').value = lot.note || '';
    }
    if (delBtn) {
        if (!confirm('ลบ Lot นี้?')) return;
        apiPost('delete_lot', { id: Number(delBtn.dataset.id) }).then(function(res) {
            if (res.status !== 'success') { alert(res.message); return; }
            reloadAll().then(function() {
                const updated = stockRows.find(function(r) { return r.icode === icode; });
                if (updated) renderLotList(updated.lots || []);
            });
        });
    }
});

document.getElementById('btnSaveLot').addEventListener('click', function() {
    const btn = this;
    btn.disabled = true;
    apiPost('save_lot', {
        id: Number(document.getElementById('lotEditId').value || 0),
        room_id: activeRoomId,
        icode: document.getElementById('lotIcode').value,
        lot_no: document.getElementById('lotNo').value,
        exp_date: document.getElementById('lotExp').value,
        qty: document.getElementById('lotQty').value,
        note: document.getElementById('lotNote').value
    }).then(function(res) {
        btn.disabled = false;
        if (res.status !== 'success') { alert(res.message || 'บันทึกไม่สำเร็จ'); return; }
        clearLotForm();
        reloadAll().then(function() {
            const icode = document.getElementById('lotIcode').value;
            const updated = stockRows.find(function(r) { return r.icode === icode; });
            if (updated) renderLotList(updated.lots || []);
        });
    }).catch(function(err) { btn.disabled = false; alert(err.message); });
});

document.getElementById('btnUseMinmaxSuggest').addEventListener('click', function() {
    if (!activeMinmaxSuggest) return;
    document.getElementById('minmaxMin').value = activeMinmaxSuggest.suggest_min || '';
    document.getElementById('minmaxMax').value = activeMinmaxSuggest.suggest_max || '';
});

document.getElementById('btnSaveMinmax').addEventListener('click', function() {
    const btn = this;
    btn.disabled = true;
    apiPost('update_minmax', {
        id: Number(document.getElementById('minmaxItemId').value),
        min_stock: document.getElementById('minmaxMin').value,
        max_stock: document.getElementById('minmaxMax').value
    }).then(function(res) {
        btn.disabled = false;
        if (res.status !== 'success') { alert(res.message || 'บันทึกไม่สำเร็จ'); return; }
        bootstrap.Modal.getInstance(document.getElementById('minmaxModal')).hide();
        reloadAll();
    }).catch(function(err) { btn.disabled = false; alert(err.message); });
});

document.getElementById('btnOpenSync').addEventListener('click', function() {
    previewReady = false;
    document.getElementById('btnApplySync').disabled = true;
    document.getElementById('previewBody').innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">เลือกช่วงวันที่แล้วกดดูรายงาน</td></tr>';
    document.getElementById('syncAlert').classList.add('d-none');
    new bootstrap.Modal(document.getElementById('syncModal')).show();
});

document.getElementById('btnPreviewSync').addEventListener('click', function() {
    const btn = this;
    const alertEl = document.getElementById('syncAlert');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>กำลังดึงข้อมูล...';
    apiPost('hosxp_preview', {
        start_date: document.getElementById('syncStart').value,
        end_date: document.getElementById('syncEnd').value
    }).then(function(res) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-search me-1"></i>ดูรายงานก่อนตัดสต๊อก';
        if (res.status !== 'success') {
            showAlert(alertEl, 'danger', res.message || 'ดึงข้อมูลไม่สำเร็จ');
            return;
        }
        alertEl.classList.add('d-none');
        const rows = res.rows || [];
        if (!rows.length) {
            document.getElementById('previewBody').innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">ไม่พบการใช้ยาในช่วงวันที่ที่เลือก</td></tr>';
            previewReady = false;
            document.getElementById('btnApplySync').disabled = true;
            return;
        }
        document.getElementById('previewBody').innerHTML = rows.map(function(r) {
            const after = r.after_qty;
            const afterCls = after !== null && after < 0 ? 'text-danger fw-bold' : '';
            return '<tr>' +
                '<td><code>' + esc(r.icode) + '</code></td>' +
                '<td>' + esc(r.drug_name) + '</td>' +
                '<td class="text-end fw-bold">' + fmtQty(r.total_qty) + '</td>' +
                '<td class="text-end">' + (r.current_qty == null ? '-' : fmtQty(r.current_qty)) + '</td>' +
                '<td class="text-end ' + afterCls + '">' + (after == null ? '-' : fmtQty(after)) + '</td>' +
                '<td>' + esc(r.unit) + '</td>' +
                '<td class="text-center">' + (r.configured ? '<i class="fas fa-check text-success"></i>' : '<i class="fas fa-times text-danger"></i>') + '</td>' +
            '</tr>';
        }).join('');
        previewReady = rows.some(function(r) { return r.configured && Number(r.total_qty) > 0; });
        document.getElementById('btnApplySync').disabled = !previewReady;
        showAlert(alertEl, 'info', 'พบ ' + rows.length + ' รายการ รวมปริมาณใช้ ' + fmtQty(res.total_qty) + ' หน่วย');
    }).catch(function(err) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-search me-1"></i>ดูรายงานก่อนตัดสต๊อก';
        showAlert(alertEl, 'danger', err.message);
    });
});

document.getElementById('btnApplySync').addEventListener('click', function() {
    if (!previewReady) return;
    if (!confirm('ยืนยันตัดสต๊อกจากรายงาน HOSxP ช่วงวันที่ที่เลือก?')) return;
    const btn = this;
    btn.disabled = true;
    apiPost('hosxp_apply', {
        start_date: document.getElementById('syncStart').value,
        end_date: document.getElementById('syncEnd').value,
        room_id: Number(document.getElementById('syncRoomId').value || activeRoomId)
    }).then(function(res) {
        btn.disabled = false;
        if (res.status !== 'success') { alert(res.message || 'ตัดสต๊อกไม่สำเร็จ'); return; }
        alert(res.message || 'ตัดสต๊อกสำเร็จ');
        bootstrap.Modal.getInstance(document.getElementById('syncModal')).hide();
        reloadAll();
    }).catch(function(err) { btn.disabled = false; alert(err.message); });
});

function reloadAll() {
    Promise.all([loadStock(), loadMoves()]).catch(function(err) {
        alert(err.message || 'โหลดข้อมูลไม่สำเร็จ');
    });
}

flatpickr('.cdm-date', { dateFormat: 'Y-m-d', locale: {firstDayOfWeek: 1} });

let stockLoaded = false;
document.getElementById('tab-stock-btn').addEventListener('shown.bs.tab', function() {
    if (!stockLoaded) {
        stockLoaded = true;
        reloadAll();
    }
});
document.getElementById('tab-dispense-btn').addEventListener('shown.bs.tab', function() {
    document.getElementById('dspAlert')?.classList.add('d-none');
    if (typeof window.cdmOnDispenseTabShown === 'function') {
        window.cdmOnDispenseTabShown();
    } else if (typeof window.cdmTryAutoLoadDispense === 'function') {
        window.cdmTryAutoLoadDispense();
    }
});
document.getElementById('tab-register-btn').addEventListener('shown.bs.tab', function() {
    document.getElementById('regAlert')?.classList.add('d-none');
    if (typeof window.cdmTryAutoLoadRegister === 'function') {
        window.cdmTryAutoLoadRegister();
    }
});
</script>
</body>
</html>
