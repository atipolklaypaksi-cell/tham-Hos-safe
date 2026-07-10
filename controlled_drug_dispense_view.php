<?php
/** partial: จ่ายยา / ทะเบียนจ่ายยา — รองรับ include หลาย pane ในหน้าเดียว */
$cdm_list_only = !empty($cdm_list_only);
$cdm_render_shared = !empty($cdm_render_shared);
$cdm_render_all = !$cdm_list_only && !$cdm_render_shared;

$cdm_id_prefix = $cdm_id_prefix ?? 'dsp';
$pfx = preg_replace('/[^a-z0-9]/i', '', $cdm_id_prefix) ?: 'dsp';

if ($cdm_list_only || $cdm_render_all):
    $cdm_is_register = !empty($cdm_is_register);
    $cdm_form_label = cdm_form_type_label($cdm_type);
    $cdm_dispense_end = date('Y-m-d');
    $cdm_dispense_start = date('Y-m-d', strtotime('-6 days'));
    $cdm_list_title = $cdm_is_register ? 'ทะเบียนจ่ายยา (จัดเตรียม + จ่ายแล้ว)' : 'รายการจ่ายยา (รวมทุกหน่วย)';
    $cdm_load_btn_label = $cdm_is_register ? 'โหลดทะเบียน' : 'ดึงรายชื่อ';
    $cdm_load_btn_icon = $cdm_is_register ? 'fa-book-medical' : 'fa-cloud-download-alt';
    $cdm_initial_body_msg = $cdm_is_register ? 'กำลังโหลดทะเบียนจ่ายยา...' : 'กำลังเตรียมดึงรายชื่อจาก HOSxP...';
    $cdm_datetime_col = $cdm_is_register ? 'วันเวลาจ่าย' : 'วันเวลาสั่ง';
    $cdm_colspan = $cdm_is_register ? 15 : 13;
?>
<div id="<?= $cdm_is_register ? 'tabRegisterPane' : 'tabDispensePane' ?>">
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body filter-box">
            <div class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1">ตั้งแต่วันที่</label>
                    <input type="text" id="<?= $pfx ?>DateStart" class="form-control form-control-sm cdm-date" value="<?= $cdm_dispense_start ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1">ถึงวันที่</label>
                    <input type="text" id="<?= $pfx ?>DateEnd" class="form-control form-control-sm cdm-date" value="<?= $cdm_dispense_end ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1">ประเภทผู้ป่วย</label>
                    <select id="<?= $pfx ?>Mode" class="form-select form-select-sm">
                        <option value="all" selected>ทั้งหมด (OPD + IPD)</option>
                        <option value="ipd">ผู้ป่วยใน (IPD)</option>
                        <option value="opd">ผู้ป่วยนอก (OPD)</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1"><span id="<?= $pfx ?>UnitFilterLabel">กรองหน่วย/หอผู้ป่วย</span></label>
                    <select id="<?= $pfx ?>UnitFilter" class="form-select form-select-sm" disabled>
                        <option value="">ทั้งหมด</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1">ค้นหา</label>
                    <input type="text" id="<?= $pfx ?>Search" class="form-control form-control-sm" placeholder="HN, ชื่อผู้ป่วย, ชื่อยา..." disabled>
                </div>
                <div class="col-md-2 text-md-end">
                    <button type="button" class="btn btn-cdm btn-sm w-100" id="<?= $pfx ?>BtnLoad">
                        <i class="fas <?= $cdm_load_btn_icon ?> me-1"></i><?= htmlspecialchars($cdm_load_btn_label) ?>
                    </button>
                </div>
            </div>
            <?php if (!$cdm_is_register): ?>
            <div id="dspFixedRoomBanner" class="alert alert-success py-2 px-3 mb-0 mt-2 d-none align-items-center justify-content-between flex-wrap gap-2">
                <div>
                    <i class="fas fa-lock me-1"></i><strong>ห้องตัดสต๊อก:</strong> <span id="dspFixedRoomName">-</span>
                    <span class="text-muted small ms-1">ทุกรายการบันทึกจ่ายจะใช้ห้องนี้</span>
                </div>
                <button type="button" class="btn btn-sm btn-outline-success" id="dspChangeRoomBtn">
                    <i class="fas fa-exchange-alt me-1"></i>เปลี่ยนห้อง
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($cdm_is_register): ?>
    <div id="<?= $pfx ?>Dashboard" class="mb-3 d-none">
        <div class="row g-2 mb-2">
            <div class="col-md-3 col-6">
                <div class="cdm-stat">
                    <div class="small text-muted">จ่ายยาแล้ว</div>
                    <div class="num" id="<?= $pfx ?>StatTotal">0</div>
                    <div class="small text-muted" id="<?= $pfx ?>StatPatients">0 ผู้ป่วย</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="cdm-stat">
                    <div class="small text-muted"><?= htmlspecialchars($cdm_form_label) ?>ครบ</div>
                    <div class="num text-success" id="<?= $pfx ?>StatFormPct">0%</div>
                    <div class="small text-muted" id="<?= $pfx ?>StatFormDetail">0 / 0 รายการ</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="cdm-stat cdm-stat-filter" data-status="pending_form" title="คลิกเพื่อกรองรายการค้างใบ">
                    <div class="small text-muted">ค้าง<?= htmlspecialchars($cdm_form_label) ?></div>
                    <div class="num text-warning" id="<?= $pfx ?>StatPendingFormPct">0%</div>
                    <div class="small text-muted" id="<?= $pfx ?>StatPendingFormDetail">0 รายการ</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="cdm-stat cdm-stat-filter" data-status="pending_remains" title="คลิกเพื่อกรองรายการค้างซาก">
                    <div class="small text-muted">ค้างซาก</div>
                    <div class="num text-danger" id="<?= $pfx ?>StatPendingRemainsPct">0%</div>
                    <div class="small text-muted" id="<?= $pfx ?>StatPendingRemainsDetail">0 รายการ</div>
                </div>
            </div>
        </div>
        <div class="card shadow-sm border-0 mb-2">
            <div class="card-body py-2 px-3">
                <div class="fw-bold small mb-1"><i class="fas fa-pills me-1"></i>รายการยาที่จ่าย</div>
                <div id="<?= $pfx ?>DrugBreakdown" class="d-flex flex-wrap gap-1"></div>
            </div>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <div class="cdm-wf-bar flex-grow-1" id="<?= $pfx ?>WorkflowBar">
                <button type="button" class="cdm-wf-step cdm-wf-step--all active" data-wf="">
                    <span class="cdm-wf-icon"><i class="fas fa-layer-group"></i></span>
                    <span class="cdm-wf-label">ทั้งหมด</span>
                    <span class="cdm-wf-count" id="<?= $pfx ?>WfAll">0</span>
                </button>
                <span class="cdm-wf-line"></span>
                <button type="button" class="cdm-wf-step cdm-wf-step--prepared" data-wf="workflow_prepared">
                    <span class="cdm-wf-icon"><i class="fas fa-mortar-pestle"></i></span>
                    <span class="cdm-wf-label">จัดเตรียมแล้ว</span>
                    <span class="cdm-wf-count" id="<?= $pfx ?>WfPrepared">0</span>
                </button>
                <span class="cdm-wf-line"></span>
                <button type="button" class="cdm-wf-step cdm-wf-step--completed" data-wf="workflow_completed">
                    <span class="cdm-wf-icon"><i class="fas fa-hand-holding-medical"></i></span>
                    <span class="cdm-wf-label">จ่ายยาแล้ว</span>
                    <span class="cdm-wf-count" id="<?= $pfx ?>WfCompleted">0</span>
                </button>
            </div>
        </div>
        <input type="hidden" id="<?= $pfx ?>StatusFilter" value="">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="small text-muted">กรองเพิ่ม:</span>
            <button type="button" class="btn btn-outline-warning btn-sm cdm-extra-filter" data-status="pending_form">ค้าง<?= htmlspecialchars($cdm_form_label) ?></button>
            <button type="button" class="btn btn-outline-danger btn-sm cdm-extra-filter" data-status="pending_remains">ค้างซาก</button>
            <button type="button" class="btn btn-link btn-sm p-0" id="<?= $pfx ?>ClearStatusFilter">ล้างตัวกรอง</button>
        </div>
    </div>
    <?php endif; ?>

    <div id="<?= $pfx ?>Alert" class="alert d-none mb-3"></div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
            <div class="fw-bold"><i class="fas fa-list me-2"></i><?= htmlspecialchars($cdm_list_title) ?></div>
            <span class="badge bg-secondary" id="<?= $pfx ?>Meta"></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 cdm-dispense-table" id="<?= $pfx ?>Table">
                    <thead>
                        <tr>
                            <?php if ($cdm_is_register): ?><th class="text-center" style="width:52px">ID</th><?php endif; ?>
                            <th id="<?= $pfx ?>ThLocation" style="min-width:150px">หน่วย/หอผู้ป่วย</th>
                            <th style="min-width:120px"><?= htmlspecialchars($cdm_datetime_col) ?></th>
                            <th>HN / ชื่อผู้ป่วย</th>
                            <th>ยา</th>
                            <th class="text-end">สั่ง</th>
                            <th class="text-end" style="width:80px">จ่าย</th>
                            <th class="text-center" style="width:70px"><?= htmlspecialchars($cdm_form_label) ?></th>
                            <th class="text-center" style="width:70px">ซากครบ</th>
                            <th class="text-end" style="width:80px">ค้างซาก</th>
                            <th style="width:110px">ผู้รับ</th>
                            <th style="width:110px">ผู้จัดเตรียม</th>
                            <th style="width:110px">ผู้จ่าย</th>
                            <?php if ($cdm_is_register): ?><th class="text-center" style="width:100px">สถานะงาน</th><?php endif; ?>
                            <th class="text-center" style="width:90px">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody id="<?= $pfx ?>Body">
                        <tr><td colspan="<?= $cdm_colspan ?>" class="text-center text-muted py-4"><?= htmlspecialchars($cdm_initial_body_msg) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($cdm_render_shared || $cdm_render_all):
    $cdm_form_label = cdm_form_type_label($cdm_type);
    $cdm_remains_label = cdm_remains_label($cdm_type);
    $cdm_default_dispenser = cdm_default_dispenser_name($con, (int)($_SESSION['user_id'] ?? 0));
    $cdm_pane_configs = [
        [
            'prefix' => 'dsp',
            'isRegister' => false,
            'tabWrapId' => 'tabDispenseWrap',
            'loadBtnHtml' => '<i class="fas fa-cloud-download-alt me-1"></i>ดึงรายชื่อ',
            'emptyHint' => 'เลือกช่วงวันที่แล้วกดดึงรายชื่อจาก HOSxP',
            'manageBtn' => 'บันทึก',
            'colspan' => 13,
        ],
        [
            'prefix' => 'reg',
            'isRegister' => true,
            'tabWrapId' => 'tabRegisterWrap',
            'loadBtnHtml' => '<i class="fas fa-book-medical me-1"></i>โหลดทะเบียน',
            'emptyHint' => 'เลือกช่วงวันที่แล้วกดโหลดทะเบียน',
            'manageBtn' => 'ดู/แก้ไข',
            'formLabel' => cdm_form_type_label($cdm_type),
            'colspan' => 15,
        ],
    ];
    if ($cdm_render_all) {
        $cdm_is_register = !empty($cdm_is_register);
        $cdm_pane_configs = [[
            'prefix' => $pfx,
            'isRegister' => $cdm_is_register,
            'tabWrapId' => $cdm_is_register ? 'tabRegisterWrap' : 'tabDispenseWrap',
            'loadBtnHtml' => $cdm_is_register
                ? '<i class="fas fa-book-medical me-1"></i>โหลดทะเบียน'
                : '<i class="fas fa-cloud-download-alt me-1"></i>ดึงรายชื่อ',
            'emptyHint' => $cdm_is_register
                ? 'เลือกช่วงวันที่แล้วกดโหลดทะเบียน'
                : 'เลือกช่วงวันที่แล้วกดดึงรายชื่อจาก HOSxP',
            'manageBtn' => $cdm_is_register ? 'ดู/แก้ไข' : 'บันทึก',
            'colspan' => $cdm_is_register ? 15 : 13,
        ]];
    }
?>
<!-- Modal เลือกห้องตัดสต๊อก (ก่อนเริ่มจ่ายยา) -->
<div class="modal fade" id="dispenseRoomModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><i class="fas fa-door-open me-2 text-success"></i>เลือกห้องตัดสต๊อก</h5>
                <button type="button" class="btn-close d-none" id="dspRoomModalCloseBtn" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body pt-2">
                <p class="text-muted small mb-3">กรุณาเลือกห้องที่จะใช้ตัดสต๊อกก่อนเริ่มบันทึกจ่ายยา เพื่อป้องกันเลือกผิด</p>
                <label class="form-label fw-bold small" for="dspRoomPickSelect">ห้องตัดสต๊อก</label>
                <select id="dspRoomPickSelect" class="form-select form-select-lg">
                    <option value="">— เลือกห้อง —</option>
                </select>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary d-none" id="dspRoomModalCancelBtn" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-cdm px-4" id="dspRoomModalConfirmBtn">
                    <i class="fas fa-check me-1"></i>ยืนยันและเริ่มงาน
                </button>
            </div>
        </div>
    </div>
</div>
<!-- Modal บันทึกจ่ายยา + ลายเซ็น -->
<div class="modal fade" id="dispenseModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-signature me-2"></i>บันทึกการจ่ายยา</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="dmGuid">
                <div class="mb-1"><strong id="dmPatient"></strong></div>
                <div class="mb-1 small"><i class="fas fa-clock me-1 text-muted"></i><span id="dmOrderDatetime"></span></div>
                <div class="mb-2 small text-muted" id="dmLocation"></div>
                <div class="mb-3 small text-muted" id="dmDrug"></div>
                <div class="row g-2 mb-3">
                    <div class="col-md-3">
                        <label class="form-label fw-bold small">จำนวนสั่ง (HOSxP)</label>
                        <input type="text" id="dmOrderQty" class="form-control form-control-sm" readonly>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold small">จำนวนจ่าย</label>
                        <input type="number" step="0.01" min="0" id="dmDispenseQty" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold small"><?= htmlspecialchars($cdm_remains_label) ?></label>
                        <input type="number" step="0.01" min="0" id="dmRemainsQty" class="form-control form-control-sm">
                        <div class="form-text">จำนวนซากที่ต้องรับคืน</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold small">รับซากแล้ว</label>
                        <input type="number" step="0.01" min="0" id="dmRemainsReceivedQty" class="form-control form-control-sm" value="0">
                        <div class="form-text">ระบุจำนวนซากที่รับคืนแล้ว</div>
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="dmFormReceived" checked>
                            <label class="form-check-label small" for="dmFormReceived">นำ<?= htmlspecialchars($cdm_form_label) ?>มาแล้ว</label>
                        </div>
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-12">
                        <label class="form-label fw-bold small"><i class="fas fa-door-open me-1"></i>ห้องตัดสต๊อก</label>
                        <div id="dmStockRoomWrap">
                            <select id="dmStockRoomId" class="form-select form-select-sm"></select>
                            <div id="dmStockRoomLocked" class="form-control form-control-sm bg-light fw-bold text-success d-none"></div>
                        </div>
                        <div class="form-text">ใช้ห้องที่เลือกตอนเริ่มงาน (ล็อกอัตโนมัติ)</div>
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-12">
                        <label class="form-label fw-bold small"><i class="fas fa-mortar-pestle me-1"></i>ผู้จัดเตรียมยา</label>
                        <select id="dmPreparerName" class="form-select form-select-sm" style="width:100%"></select>
                        <div class="form-text">เภสัชกรใน รพ.นี้ — บันทึกขั้นตอนจัดเตรียมก่อนจ่ายยา</div>
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold small">ชื่อผู้รับยา</label>
                        <select id="dmReceiverName" class="form-select form-select-sm" style="width:100%"></select>
                        <div class="form-text">ดึงจาก HOSxP — ค้นหาชื่อผู้รับยา (หรือพิมพ์ชื่อเองได้)</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small">ชื่อผู้จ่ายยา</label>
                        <select id="dmDispenserName" class="form-select form-select-sm" style="width:100%"></select>
                        <div class="form-text">ดึงจาก HOSxP — เฉพาะเภสัชกรใน รพ.นี้ (ไม่รวมเจ้าพนักงานเภสัชกรรม / เภสัชกร รพ.อื่น)</div>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold small">ลายเซ็นผู้รับ</label>
                        <div class="border rounded bg-white position-relative">
                            <canvas id="sigReceiver" class="cdm-sig-canvas" width="320" height="120"></canvas>
                            <button type="button" class="btn btn-link btn-sm position-absolute top-0 end-0 btn-clear-sig" data-target="sigReceiver">ล้าง</button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small">ลายเซ็นผู้จ่าย</label>
                        <div class="border rounded bg-white position-relative">
                            <canvas id="sigDispenser" class="cdm-sig-canvas" width="320" height="120"></canvas>
                            <button type="button" class="btn btn-link btn-sm position-absolute top-0 end-0 btn-clear-sig" data-target="sigDispenser">ล้าง</button>
                        </div>
                    </div>
                </div>
                <div class="mt-2">
                    <label class="form-label fw-bold small">หมายเหตุ</label>
                    <input type="text" id="dmNote" class="form-control form-control-sm" maxlength="255">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-outline-primary" id="btnSavePrepare"><i class="fas fa-mortar-pestle me-1"></i>บันทึกจัดเตรียม</button>
                <button type="button" class="btn btn-cdm" id="btnSaveDispense"><i class="fas fa-save me-1"></i>บันทึกจ่ายยา</button>
            </div>
        </div>
    </div>
</div>

<style>
.cdm-sig-canvas { width: 100%; height: 120px; touch-action: none; cursor: crosshair; display: block; }
.cdm-dispense-table thead th { background: var(--cdm-primary); color: #fff; font-size: .78rem; vertical-align: middle; }
.cdm-dispense-table { font-size: .82rem; }
.cdm-dispense-table td { vertical-align: middle; }
.cdm-stat-filter { cursor: pointer; transition: box-shadow .15s, border-color .15s; }
.cdm-stat-filter:hover { box-shadow: 0 0 0 2px rgba(124, 45, 18, .15); }
.cdm-stat-filter.active { box-shadow: 0 0 0 2px var(--cdm-primary); border-color: var(--cdm-primary) !important; }
.cdm-drug-chip {
    display: inline-flex; align-items: center; gap: .35rem;
    background: var(--cdm-light); border: 1px solid rgba(0,0,0,.08);
    border-radius: 999px; padding: .2rem .65rem; font-size: .78rem;
}
.cdm-drug-chip .cnt { font-weight: 800; color: var(--cdm-primary); }
.cdm-wf-bar { display: flex; align-items: center; gap: .25rem; background: #fff; border: 1px solid rgba(0,0,0,.08); border-radius: 12px; padding: .5rem .75rem; }
.cdm-wf-step { border: 0; background: transparent; display: flex; flex-direction: column; align-items: center; gap: .2rem; min-width: 92px; padding: .25rem .35rem; color: #6b7280; cursor: pointer; transition: color .15s; }
.cdm-wf-step:hover { color: #374151; }
.cdm-wf-step.active { font-weight: 700; }
.cdm-wf-step--all.active { color: var(--cdm-primary); }
.cdm-wf-step--prepared.active { color: #b45309; }
.cdm-wf-step--completed.active { color: #047857; }
.cdm-wf-icon {
    width: 34px; height: 34px; border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    background: #e5e7eb; color: #6b7280; font-size: .82rem;
    transition: background .15s, color .15s, box-shadow .15s;
}
.cdm-wf-step--all.active .cdm-wf-icon { background: var(--cdm-primary); color: #fff; box-shadow: 0 0 0 3px rgba(124,45,18,.15); }
.cdm-wf-step--prepared.active .cdm-wf-icon { background: #f59e0b; color: #fff; box-shadow: 0 0 0 3px rgba(245,158,11,.2); }
.cdm-wf-step--completed.active .cdm-wf-icon { background: #10b981; color: #fff; box-shadow: 0 0 0 3px rgba(16,185,129,.2); }
.cdm-wf-line { flex: 1; height: 2px; background: linear-gradient(90deg, #d1d5db, #e5e7eb); min-width: 24px; }
.cdm-wf-count { font-size: .72rem; font-weight: 800; }
.cdm-wf-label { font-size: .76rem; white-space: nowrap; }
.cdm-status-pill {
    display: inline-flex; align-items: center; gap: .35rem;
    padding: .18rem .55rem .18rem .28rem; border-radius: 999px;
    font-size: .72rem; font-weight: 600; white-space: nowrap; line-height: 1.2;
    border: 1px solid transparent;
}
.cdm-status-pill .cdm-status-ico {
    width: 20px; height: 20px; border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: .6rem; flex-shrink: 0;
}
.cdm-status-pill.pending { background: #f3f4f6; color: #4b5563; border-color: #e5e7eb; }
.cdm-status-pill.pending .cdm-status-ico { background: #9ca3af; color: #fff; }
.cdm-status-pill.prepared { background: #fff7ed; color: #9a3412; border-color: #fed7aa; }
.cdm-status-pill.prepared .cdm-status-ico { background: #f59e0b; color: #fff; }
.cdm-status-pill.completed { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
.cdm-status-pill.completed .cdm-status-ico { background: #10b981; color: #fff; }
.cdm-dispense-id { font-size: .78rem; font-weight: 700; color: var(--cdm-primary); }
.cdm-extra-filter.active { font-weight: 700; }
</style>

<script>
(function() {
    const CDM_DEFAULT_DISPENSER = <?= json_encode($cdm_default_dispenser, JSON_UNESCAPED_UNICODE) ?>;
    const CDM_PHARMACIST_POSITION_REGEX = <?= json_encode(cdm_pharmacist_entryposition_regex(), JSON_UNESCAPED_UNICODE) ?>;
    const CDM_PANE_CONFIGS = <?= json_encode($cdm_pane_configs, JSON_UNESCAPED_UNICODE) ?>;

    const sigPads = {};
    let receiverSelectReady = false;
    let dispenserSelectReady = false;
    let preparerSelectReady = false;
    let remainsQtyDirty = false;
    let activeRecord = null;
    let modalSourcePrefix = 'dsp';
    const paneControllers = {};
    let cdmDispenseRooms = [];
    let dispenseRoomModal = null;

    function dispenseFixedRoomStorageKey() {
        return 'cdm_dispense_fixed_room_' + CDM_TYPE;
    }

    function getFixedDispenseRoomId() {
        return Number(sessionStorage.getItem(dispenseFixedRoomStorageKey()) || 0);
    }

    function setFixedDispenseRoomId(roomId) {
        const id = Number(roomId) || 0;
        if (id > 0) {
            sessionStorage.setItem(dispenseFixedRoomStorageKey(), String(id));
        } else {
            sessionStorage.removeItem(dispenseFixedRoomStorageKey());
        }
        updateFixedRoomUi();
        updateModalStockRoomUi();
    }

    function validateFixedDispenseRoom() {
        const id = getFixedDispenseRoomId();
        if (!id) return;
        const activeRooms = cdmDispenseRooms.filter(function(r) { return Number(r.is_active) !== 0; });
        if (!activeRooms.some(function(r) { return Number(r.id) === id; })) {
            setFixedDispenseRoomId(0);
        }
    }

    function populateDispenseRooms(rooms) {
        cdmDispenseRooms = rooms || [];
        const activeRooms = cdmDispenseRooms.filter(function(r) { return Number(r.is_active) !== 0; });
        validateFixedDispenseRoom();
        const fixedId = getFixedDispenseRoomId();
        const buildOptions = function(list) {
            return list.map(function(r) {
                const label = r.room_name + (r.service_mode && r.service_mode !== 'all' ? ' (' + (r.service_mode_label || r.service_mode).toUpperCase() + ')' : '');
                return '<option value="' + r.id + '">' + esc(label) + '</option>';
            }).join('');
        };
        const sel = document.getElementById('dmStockRoomId');
        if (sel) {
            sel.innerHTML = buildOptions(activeRooms);
        }
        const pick = document.getElementById('dspRoomPickSelect');
        if (pick) {
            let cur = fixedId ? String(fixedId) : (pick.value || '');
            if (cur && !activeRooms.some(function(r) { return String(r.id) === cur; })) cur = '';
            pick.innerHTML = '<option value="">— เลือกห้อง —</option>' + buildOptions(activeRooms);
            pick.value = cur || '';
        }
        updateFixedRoomUi();
        updateModalStockRoomUi();
    }

    function updateFixedRoomUi() {
        const id = getFixedDispenseRoomId();
        const room = cdmDispenseRooms.find(function(r) { return Number(r.id) === id; });
        const banner = document.getElementById('dspFixedRoomBanner');
        const nameEl = document.getElementById('dspFixedRoomName');
        if (banner) {
            banner.classList.toggle('d-none', !(id > 0));
            if (id > 0) banner.classList.add('d-flex');
        }
        if (nameEl) nameEl.textContent = room ? room.room_name : (id > 0 ? ('ห้อง #' + id) : '-');
        const pick = document.getElementById('dspRoomPickSelect');
        if (pick && id > 0) pick.value = String(id);
    }

    function openDispenseRoomModal(optional) {
        const showModal = function() {
            const modalEl = document.getElementById('dispenseRoomModal');
            if (!modalEl) return;
            const activeRooms = cdmDispenseRooms.filter(function(r) { return Number(r.is_active) !== 0; });
            if (!activeRooms.length) {
                alert('ยังไม่มีห้องตัดสต๊อกสำหรับประเภทยานี้ กรุณาไปเมนู จัดการห้อง ใน sidebar หรือรีเฟรชหน้าแล้วลองใหม่');
                return;
            }
            const canCancel = !!optional && getFixedDispenseRoomId() > 0;
            document.getElementById('dspRoomModalCloseBtn')?.classList.toggle('d-none', !canCancel);
            document.getElementById('dspRoomModalCancelBtn')?.classList.toggle('d-none', !canCancel);
            const pick = document.getElementById('dspRoomPickSelect');
            if (pick) {
                const cur = getFixedDispenseRoomId();
                if (cur > 0) pick.value = String(cur);
            }
            if (!dispenseRoomModal) {
                dispenseRoomModal = new bootstrap.Modal(modalEl, { backdrop: 'static', keyboard: false });
            }
            dispenseRoomModal.show();
            setTimeout(function() { pick?.focus(); }, 300);
        };
        if (!cdmDispenseRooms.length) {
            return loadDispenseRoomsList().then(showModal);
        }
        showModal();
    }

    function confirmDispenseRoomPick() {
        const activeRooms = cdmDispenseRooms.filter(function(r) { return Number(r.is_active) !== 0; });
        if (!activeRooms.length) {
            alert('ยังไม่มีห้องตัดสต๊อก กรุณาไปเมนู จัดการห้อง ใน sidebar เพื่อเพิ่มห้อง');
            return;
        }
        const pick = document.getElementById('dspRoomPickSelect');
        const id = Number(pick?.value || 0);
        if (!id) {
            alert('กรุณาเลือกห้องตัดสต๊อก');
            pick?.focus();
            return;
        }
        setFixedDispenseRoomId(id);
        if (dispenseRoomModal) dispenseRoomModal.hide();
        if (typeof window.cdmTryAutoLoadDispense === 'function') window.cdmTryAutoLoadDispense();
    }

    function updateModalStockRoomUi() {
        const fixed = (modalSourcePrefix === 'dsp') ? getFixedDispenseRoomId() : 0;
        const sel = document.getElementById('dmStockRoomId');
        const locked = document.getElementById('dmStockRoomLocked');
        if (!sel || !locked) return;
        if (fixed > 0) {
            const room = cdmDispenseRooms.find(function(r) { return Number(r.id) === fixed; });
            locked.innerHTML = '<i class="fas fa-lock me-1"></i>' + esc(room ? room.room_name : ('ห้อง #' + fixed));
            sel.classList.add('d-none');
            locked.classList.remove('d-none');
            sel.value = String(fixed);
        } else {
            sel.classList.remove('d-none');
            locked.classList.add('d-none');
        }
    }

    function setStockRoomSelectValue(roomId, serviceMode) {
        const sel = document.getElementById('dmStockRoomId');
        if (!sel) return;
        const fixed = (modalSourcePrefix === 'dsp') ? getFixedDispenseRoomId() : 0;
        let id = 0;
        if (activeRecord && activeRecord.saved && Number(activeRecord.stock_room_id) > 0) {
            id = Number(activeRecord.stock_room_id);
        } else if (fixed > 0) {
            id = fixed;
        } else {
            id = Number(roomId) || 0;
            if (!id && serviceMode) {
                const match = cdmDispenseRooms.find(function(r) { return r.service_mode === serviceMode; });
                if (match) id = Number(match.id);
            }
            if (!id) {
                const def = cdmDispenseRooms.find(function(r) { return Number(r.is_default) === 1; });
                if (def) id = Number(def.id);
            }
            if (!id && cdmDispenseRooms[0]) id = Number(cdmDispenseRooms[0].id);
        }
        sel.value = id ? String(id) : '';
        updateModalStockRoomUi();
    }

    function getStockRoomSelectValue() {
        if (modalSourcePrefix === 'dsp') {
            const fixed = getFixedDispenseRoomId();
            if (fixed > 0) return fixed;
        }
        return Number(document.getElementById('dmStockRoomId').value || 0);
    }

    function loadDispenseRoomsList() {
        return dispenseApi('rooms_list').then(function(res) {
            if (res.status === 'success') {
                populateDispenseRooms(res.rooms || []);
                return cdmDispenseRooms;
            }
            alert(res.message || 'โหลดรายการห้องไม่สำเร็จ');
            return [];
        }).catch(function(err) {
            alert(err.message || 'โหลดรายการห้องไม่สำเร็จ');
            return [];
        });
    }

    function esc(s) {
        if (typeof window.cdmEsc === 'function') return window.cdmEsc(s);
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
    function fmtQty(n) {
        if (typeof window.cdmFmtQty === 'function') return window.cdmFmtQty(n);
        n = Number(n || 0);
        return Number.isInteger(n) ? n.toLocaleString() : n.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    function dispenseApi(action, data) {
        return (typeof apiPost === 'function' ? apiPost(action, data) : fetch('fetch_controlled_drug_api.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(Object.assign({action: action, drug_type: CDM_TYPE}, data || {}))
        }).then(function(r) {
            return r.text().then(function(t) { return JSON.parse(t); });
        }));
    }

    function initSigPad(canvasId) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return null;
        const ctx = canvas.getContext('2d');
        ctx.strokeStyle = '#111';
        ctx.lineWidth = 2;
        ctx.lineCap = 'round';
        let drawing = false;
        let hasInk = false;

        function pos(e) {
            const rect = canvas.getBoundingClientRect();
            const src = e.touches ? e.touches[0] : e;
            return { x: src.clientX - rect.left, y: src.clientY - rect.top };
        }
        function start(e) { drawing = true; const p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); e.preventDefault(); }
        function move(e) {
            if (!drawing) return;
            const p = pos(e);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            hasInk = true;
            e.preventDefault();
        }
        function end() { drawing = false; }

        canvas.addEventListener('mousedown', start);
        canvas.addEventListener('mousemove', move);
        canvas.addEventListener('mouseup', end);
        canvas.addEventListener('mouseleave', end);
        canvas.addEventListener('touchstart', start, {passive:false});
        canvas.addEventListener('touchmove', move, {passive:false});
        canvas.addEventListener('touchend', end);

        return {
            clear: function() { ctx.clearRect(0, 0, canvas.width, canvas.height); hasInk = false; },
            load: function(dataUrl) {
                this.clear();
                if (!dataUrl) return;
                const img = new Image();
                img.onload = function() { ctx.drawImage(img, 0, 0); hasInk = true; };
                img.src = dataUrl;
            },
            dataUrl: function() { return hasInk ? canvas.toDataURL('image/png') : ''; }
        };
    }

    function ensureSigPads() {
        if (!sigPads.receiver) sigPads.receiver = initSigPad('sigReceiver');
        if (!sigPads.dispenser) sigPads.dispenser = initSigPad('sigDispenser');
    }

    document.querySelectorAll('.btn-clear-sig').forEach(function(btn) {
        btn.addEventListener('click', function() {
            ensureSigPads();
            const key = btn.dataset.target === 'sigReceiver' ? 'receiver' : 'dispenser';
            if (sigPads[key]) sigPads[key].clear();
        });
    });

    function initReceiverSelect() {
        if (typeof jQuery === 'undefined' || typeof jQuery.fn.select2 !== 'function') return;
        const $sel = jQuery('#dmReceiverName');
        if ($sel.data('select2')) $sel.select2('destroy');
        $sel.select2({
            theme: 'bootstrap-5', width: '100%', dropdownParent: jQuery('#dispenseModal'),
            tags: true, placeholder: 'ค้นหาชื่อจาก HOSxP...', allowClear: true, minimumInputLength: 0,
            ajax: {
                url: 'fetch_users_select_api.php', dataType: 'json', delay: 250,
                data: function(params) { return { q: params.term || '', source: 'hosxp' }; },
                processResults: function(data) { return { results: data.results || [] }; }
            }
        });
        receiverSelectReady = true;
    }

    function initPreparerSelect() {
        if (typeof jQuery === 'undefined' || typeof jQuery.fn.select2 !== 'function') return;
        const $sel = jQuery('#dmPreparerName');
        if ($sel.data('select2')) $sel.select2('destroy');
        $sel.select2({
            theme: 'bootstrap-5', width: '100%', dropdownParent: jQuery('#dispenseModal'),
            tags: false, placeholder: 'ค้นหาชื่อเภสัชกร...', allowClear: true, minimumInputLength: 0,
            ajax: {
                url: 'fetch_users_select_api.php', dataType: 'json', delay: 250,
                data: function(params) { return { q: params.term || '', position_regex: CDM_PHARMACIST_POSITION_REGEX }; },
                processResults: function(data) { return { results: data.results || [] }; }
            }
        });
        preparerSelectReady = true;
    }

    function setPreparerSelectValue(name) {
        const val = (name || '').trim();
        const $sel = jQuery('#dmPreparerName');
        if (!preparerSelectReady || typeof jQuery === 'undefined') return;
        if (val === '') { $sel.val(null).trigger('change'); return; }
        if ($sel.find('option[value="' + val.replace(/"/g, '\\"') + '"]').length === 0) {
            $sel.append(new Option(val, val, true, true));
        }
        $sel.val(val).trigger('change');
    }

    function getPreparerSelectValue() {
        if (typeof jQuery === 'undefined') return '';
        const data = jQuery('#dmPreparerName').select2('data');
        if (data && data[0]) return (data[0].id || data[0].text || '').trim();
        return (jQuery('#dmPreparerName').val() || '').trim();
    }

    function initDispenserSelect() {
        if (typeof jQuery === 'undefined' || typeof jQuery.fn.select2 !== 'function') return;
        const $sel = jQuery('#dmDispenserName');
        if ($sel.data('select2')) $sel.select2('destroy');
        $sel.select2({
            theme: 'bootstrap-5', width: '100%', dropdownParent: jQuery('#dispenseModal'),
            tags: false, placeholder: 'ค้นหาชื่อเภสัชกร...', allowClear: true, minimumInputLength: 0,
            ajax: {
                url: 'fetch_users_select_api.php', dataType: 'json', delay: 250,
                data: function(params) { return { q: params.term || '', position_regex: CDM_PHARMACIST_POSITION_REGEX }; },
                processResults: function(data) { return { results: data.results || [] }; }
            }
        });
        dispenserSelectReady = true;
    }

    function setDispenserSelectValue(name) {
        const val = (name || '').trim();
        const $sel = jQuery('#dmDispenserName');
        if (!dispenserSelectReady || typeof jQuery === 'undefined') return;
        if (val === '') { $sel.val(null).trigger('change'); return; }
        if ($sel.find('option[value="' + val.replace(/"/g, '\\"') + '"]').length === 0) {
            $sel.append(new Option(val, val, true, true));
        }
        $sel.val(val).trigger('change');
    }

    function getDispenserSelectValue() {
        if (typeof jQuery === 'undefined') return '';
        const data = jQuery('#dmDispenserName').select2('data');
        if (data && data[0]) return (data[0].id || data[0].text || '').trim();
        return (jQuery('#dmDispenserName').val() || '').trim();
    }

    function setReceiverSelectValue(name) {
        const val = (name || '').trim();
        const $sel = jQuery('#dmReceiverName');
        if (!receiverSelectReady || typeof jQuery === 'undefined') return;
        if (val === '') { $sel.val(null).trigger('change'); return; }
        if ($sel.find('option[value="' + val.replace(/"/g, '\\"') + '"]').length === 0) {
            $sel.append(new Option(val, val, true, true));
        }
        $sel.val(val).trigger('change');
    }

    function getReceiverSelectValue() {
        if (typeof jQuery === 'undefined') return '';
        const data = jQuery('#dmReceiverName').select2('data');
        if (data && data[0]) return (data[0].id || data[0].text || '').trim();
        return (jQuery('#dmReceiverName').val() || '').trim();
    }

    function fillDispenseModalFields(record) {
        const dispQty = record.dispense_qty != null ? record.dispense_qty : record.order_qty;
        document.getElementById('dmOrderQty').value = record.order_qty;
        document.getElementById('dmDispenseQty').value = dispQty;
        document.getElementById('dmFormReceived').checked = record.saved ? Number(record.form_received) === 1 : true;
        remainsQtyDirty = false;
        if (record.saved && record.drug_remains_qty != null) {
            document.getElementById('dmRemainsQty').value = record.drug_remains_qty;
            remainsQtyDirty = true;
        } else {
            document.getElementById('dmRemainsQty').value = dispQty;
        }
        document.getElementById('dmRemainsReceivedQty').value = record.saved && record.drug_remains_received_qty != null
            ? record.drug_remains_received_qty : 0;
    }

    function loadDispenseSignatures(row) {
        if (!row || !row.dispense_id) {
            sigPads.receiver.clear();
            sigPads.dispenser.clear();
            return Promise.resolve();
        }
        return dispenseApi('dispense_detail', { id: row.dispense_id }).then(function(res) {
            if (res.status !== 'success') {
                sigPads.receiver.clear();
                sigPads.dispenser.clear();
                return;
            }
            sigPads.receiver.load(res.receiver_signature || '');
            sigPads.dispenser.load(res.dispenser_signature || '');
        }).catch(function() {
            sigPads.receiver.clear();
            sigPads.dispenser.clear();
        });
    }

    function initCdmDispensePane(cfg) {
        const pfx = cfg.prefix;
        if (!document.getElementById(pfx + 'DateStart')) return null;

        const state = {
            ordersAll: [],
            ordersFiltered: [],
            filteredIndices: [],
            currentServiceMode: 'all',
            autoLoaded: false
        };

        function gid(id) { return document.getElementById(pfx + id); }

        function locationText(r) {
            const mode = r.service_mode || state.currentServiceMode;
            const unit = (r.unit_name || r.ward_name || '').trim();
            const bed = (r.bedno || '').trim();
            let loc = '-';
            if (mode === 'ipd') {
                loc = (unit && bed) ? unit + ' / เตียง ' + bed : (unit || '-');
            } else {
                loc = unit || '-';
            }
            if (state.currentServiceMode === 'all' && mode) {
                return '[' + (mode === 'opd' ? 'OPD' : 'IPD') + '] ' + loc;
            }
            return loc;
        }

        function updateModeLabels() {
            const labels = {
                all: ['กรองหน่วย/หอผู้ป่วย', 'หน่วย/หอผู้ป่วย'],
                ipd: ['กรองหอผู้ป่วย', 'หอผู้ป่วย'],
                opd: ['กรองหน่วยให้บริการ', 'หน่วยให้บริการ'],
            };
            const pair = labels[state.currentServiceMode] || labels.all;
            gid('UnitFilterLabel').textContent = pair[0];
            gid('ThLocation').textContent = pair[1];
        }

        function populateUnitFilter(units) {
            const sel = gid('UnitFilter');
            const current = sel.value;
            let html = '<option value="">ทั้งหมด</option>';
            (units || []).forEach(function(u) {
                const key = u.filter_key || u.unit_code || '';
                html += '<option value="' + esc(key) + '">' + esc(u.unit_name || u.unit_code) +
                    ' (' + (u.order_count || 0) + ' รายการ)</option>';
            });
            sel.innerHTML = html;
            sel.disabled = !(units && units.length);
            if (current && sel.querySelector('option[value="' + current.replace(/"/g, '\\"') + '"]')) {
                sel.value = current;
            }
        }

        function showAlert(type, msg) {
            const el = gid('Alert');
            el.className = 'alert alert-' + type + ' mb-3';
            el.textContent = msg;
            el.classList.remove('d-none');
        }

        function isPendingForm(r) {
            return Number(r.form_received) !== 1;
        }

        function isPendingRemains(r) {
            return Number(r.remains_complete) !== 1;
        }

        function registerRowSortTs(r) {
            const raw = r.status === 'completed'
                ? (r.dispensed_at || '')
                : (r.prepared_at || r.dispensed_at || '');
            if (raw && raw !== '0000-00-00 00:00:00') {
                const ts = Date.parse(String(raw).replace(' ', 'T'));
                if (!isNaN(ts)) return ts;
            }
            if (r.vstdate) {
                const ts = Date.parse(String(r.vstdate) + 'T23:59:59');
                if (!isNaN(ts)) return ts;
            }
            return 0;
        }

        function compareRegisterRows(a, b) {
            const pa = a.status === 'prepared' ? 0 : 1;
            const pb = b.status === 'prepared' ? 0 : 1;
            if (pa !== pb) return pa - pb;
            const ta = registerRowSortTs(a);
            const tb = registerRowSortTs(b);
            if (ta !== tb) return tb - ta;
            return (Number(b.dispense_id) || 0) - (Number(a.dispense_id) || 0);
        }

        function sortRegisterRows(rows) {
            if (!cfg.isRegister || !Array.isArray(rows)) return rows;
            rows.sort(compareRegisterRows);
            return rows;
        }

        function workflowBadge(r) {
            const wf = r.workflow || {};
            const code = wf.code || r.status || 'pending';
            const label = wf.label || (r.status === 'completed' ? 'จ่ายยาแล้ว' : (r.status === 'prepared' ? 'จัดเตรียมแล้ว' : 'รอจัดเตรียม'));
            const icon = wf.icon || (code === 'completed' ? 'fa-hand-holding-medical' : (code === 'prepared' ? 'fa-mortar-pestle' : 'fa-clock'));
            const pillCls = wf.class || code;
            return '<span class="cdm-status-pill ' + esc(pillCls) + '">' +
                '<span class="cdm-status-ico"><i class="fas ' + esc(icon) + '"></i></span>' +
                '<span>' + esc(label) + '</span></span>';
        }

        function rowStatusClass(r) {
            if (r.status === 'completed') return 'table-success';
            if (r.status === 'prepared') return 'table-warning';
            return '';
        }

        function pctPart(n, total) {
            if (!total) return 0;
            return Math.round((n / total) * 1000) / 10;
        }

        function renderRegisterDashboard() {
            if (!cfg.isRegister) return;
            const dash = gid('Dashboard');
            if (!dash) return;
            const rows = state.ordersAll;
            if (!rows.length) {
                dash.classList.add('d-none');
                return;
            }
            dash.classList.remove('d-none');

            const total = rows.length;
            const completed = rows.filter(function(r) { return r.status === 'completed'; }).length;
            const prepared = rows.filter(function(r) { return r.status === 'prepared'; }).length;
            const patients = new Set(rows.map(function(r) { return r.hn; }).filter(Boolean)).size;
            const formComplete = rows.filter(function(r) { return !isPendingForm(r); }).length;
            const pendingForm = total - formComplete;
            const pendingRemains = rows.filter(isPendingRemains).length;

            gid('StatTotal').textContent = completed.toLocaleString();
            gid('StatPatients').textContent = patients.toLocaleString() + ' ผู้ป่วย';
            if (gid('WfAll')) gid('WfAll').textContent = total;
            if (gid('WfPrepared')) gid('WfPrepared').textContent = prepared;
            if (gid('WfCompleted')) gid('WfCompleted').textContent = completed;
            gid('StatFormPct').textContent = pctPart(formComplete, total) + '%';
            gid('StatFormDetail').textContent = formComplete + ' / ' + total + ' รายการ';
            gid('StatPendingFormPct').textContent = pctPart(pendingForm, total) + '%';
            gid('StatPendingFormDetail').textContent = pendingForm + ' รายการ';
            gid('StatPendingRemainsPct').textContent = pctPart(pendingRemains, total) + '%';
            gid('StatPendingRemainsDetail').textContent = pendingRemains + ' รายการ';

            const drugMap = {};
            rows.forEach(function(r) {
                const key = (r.icode || r.drug_name || '-').trim();
                if (!drugMap[key]) {
                    drugMap[key] = { name: r.drug_name || key, icode: r.icode || '', count: 0, qty: 0, unit: r.unit || '' };
                }
                drugMap[key].count++;
                drugMap[key].qty += Number(r.dispense_qty || 0);
            });
            const drugs = Object.keys(drugMap).map(function(k) { return drugMap[k]; })
                .sort(function(a, b) { return b.count - a.count || a.name.localeCompare(b.name, 'th'); });
            const drugEl = gid('DrugBreakdown');
            if (drugEl) {
                drugEl.innerHTML = drugs.length
                    ? drugs.map(function(d) {
                        return '<span class="cdm-drug-chip" title="' + esc(d.icode) + '">' +
                            esc(d.name) +
                            ' <span class="cnt">' + d.count + '</span> ครั้ง' +
                            (d.qty > 0 ? ' · ' + fmtQty(d.qty) + ' ' + esc(d.unit) : '') +
                            '</span>';
                    }).join('')
                    : '<span class="text-muted small">ไม่มีข้อมูล</span>';
            }

            updateStatusFilterUi();
        }

        function updateStatusFilterUi() {
            if (!cfg.isRegister) return;
            const val = (gid('StatusFilter') && gid('StatusFilter').value) || '';
            document.querySelectorAll('#' + pfx + 'Dashboard .cdm-stat-filter').forEach(function(el) {
                el.classList.toggle('active', el.dataset.status === val && val !== '');
            });
            document.querySelectorAll('#' + pfx + 'WorkflowBar .cdm-wf-step').forEach(function(el) {
                const wf = el.dataset.wf || '';
                el.classList.toggle('active', (wf === val) || (wf === '' && (val === '' || val.indexOf('workflow_') !== 0 && val.indexOf('pending_') !== 0)));
            });
            document.querySelectorAll('#' + pfx + 'Dashboard .cdm-extra-filter').forEach(function(el) {
                el.classList.toggle('active', el.dataset.status === val);
            });
        }

        function setStatusFilter(val) {
            if (gid('StatusFilter')) gid('StatusFilter').value = val || '';
            updateStatusFilterUi();
            applyFilters();
        }

        function remainsSummaryCells(r) {
            if (!r.saved) {
                return '<td class="text-center text-muted">-</td><td class="text-end text-muted">-</td>';
            }
            const complete = Number(r.remains_complete) === 1;
            const outstanding = r.remains_outstanding != null ? r.remains_outstanding : 0;
            return '<td class="text-center">' +
                (complete ? '<i class="fas fa-check text-success" title="รับซากครบ"></i>' :
                    '<i class="fas fa-times text-danger" title="ยังรับซากไม่ครบ"></i>') +
                '</td><td class="text-end' + (complete ? ' text-muted' : ' fw-bold text-danger') + '">' +
                (complete ? fmtQty(0) : fmtQty(outstanding)) + '</td>';
        }

        function renderOrders() {
            const body = gid('Body');
            const rows = state.ordersFiltered;
            const colspan = cfg.colspan || 12;
            const done = rows.filter(function(r) { return r.status === 'completed'; }).length;
            const prepared = rows.filter(function(r) { return r.status === 'prepared'; }).length;
            gid('Meta').textContent = cfg.isRegister
                ? ('แสดง ' + rows.length + ' / ' + state.ordersAll.length + ' รายการ | จัดเตรียม ' + prepared + ' | จ่ายแล้ว ' + done)
                : ('แสดง ' + rows.length + ' / ' + state.ordersAll.length + ' รายการ | บันทึกแล้ว ' + done);

            if (!state.ordersAll.length) {
                body.innerHTML = '<tr><td colspan="' + colspan + '" class="text-center text-muted py-4">ไม่พบรายการในวันที่เลือก</td></tr>';
                return;
            }
            if (!rows.length) {
                body.innerHTML = '<tr><td colspan="' + colspan + '" class="text-center text-muted py-4">ไม่พบรายการตามตัวกรอง</td></tr>';
                return;
            }

            body.innerHTML = rows.map(function(r, fi) {
                const idx = state.filteredIndices[fi];
                const dt = r.order_datetime || '-';
                let html = '<tr class="' + rowStatusClass(r) + '" data-idx="' + idx + '">';
                if (cfg.isRegister) {
                    html += '<td class="text-center"><code class="cdm-dispense-id">' + esc(String(r.dispense_id || '-')) + '</code></td>';
                }
                html += '<td class="small">' + esc(locationText(r)) + '</td>' +
                    '<td class="small text-nowrap">' + esc(dt) + '</td>' +
                    '<td><div><code>' + esc(r.hn) + '</code> ' + esc(r.ptname) + '</div></td>' +
                    '<td><div>' + esc(r.drug_name) + '</div><div class="small text-muted">' + esc(r.icode) + '</div></td>' +
                    '<td class="text-end">' + fmtQty(r.order_qty) + ' <span class="small text-muted">' + esc(r.unit) + '</span></td>' +
                    '<td class="text-end fw-bold">' + (r.dispense_qty != null ? fmtQty(r.dispense_qty) : '-') + '</td>' +
                    '<td class="text-center">' + (Number(r.form_received) === 1 ? '<i class="fas fa-check text-success"></i>' : '<i class="fas fa-times text-danger"></i>') + '</td>' +
                    remainsSummaryCells(r) +
                    '<td class="small">' + esc(r.receiver_name || '-') + '</td>' +
                    '<td class="small">' + esc(r.preparer_name || '-') + '</td>' +
                    '<td class="small">' + esc(r.dispenser_name || '-') + '</td>';
                if (cfg.isRegister) {
                    html += '<td class="text-center">' + workflowBadge(r) + '</td>';
                }
                html += '<td class="text-center"><button type="button" class="btn btn-outline-primary btn-sm py-0 px-2 btn-open-dispense">' + esc(cfg.manageBtn) + '</button></td></tr>';
                return html;
            }).join('');
        }

        function applyFilters() {
            const unitKey = gid('UnitFilter').value;
            const q = (gid('Search').value || '').toLowerCase().trim();
            const statusFilter = cfg.isRegister && gid('StatusFilter') ? gid('StatusFilter').value : '';
            state.ordersFiltered = [];
            state.filteredIndices = [];
            for (let i = 0; i < state.ordersAll.length; i++) {
                const r = state.ordersAll[i];
                if (unitKey !== '') {
                    const rowKey = r.filter_key || r.unit_code || '';
                    if (rowKey !== unitKey) continue;
                }
                if (statusFilter === 'pending_form' && !isPendingForm(r)) continue;
                if (statusFilter === 'pending_remains' && !isPendingRemains(r)) continue;
                if (statusFilter === 'workflow_prepared' && r.status !== 'prepared') continue;
                if (statusFilter === 'workflow_completed' && r.status !== 'completed') continue;
                if (q) {
                    const hay = [r.hn, r.ptname, r.drug_name, r.icode, r.unit_name, r.ward_name, r.bedno, r.service_mode, String(r.dispense_id || '')]
                        .join(' ').toLowerCase();
                    if (hay.indexOf(q) < 0) continue;
                }
                state.ordersFiltered.push(r);
                state.filteredIndices.push(i);
            }
            if (cfg.isRegister) {
                const pairs = state.ordersFiltered.map(function(r, i) {
                    return { row: r, idx: state.filteredIndices[i] };
                });
                pairs.sort(function(a, b) { return compareRegisterRows(a.row, b.row); });
                state.ordersFiltered = pairs.map(function(p) { return p.row; });
                state.filteredIndices = pairs.map(function(p) { return p.idx; });
            }
            renderOrders();
        }

        function loadDispenseList() {
            if (!cfg.isRegister && !getFixedDispenseRoomId()) {
                openDispenseRoomModal(false);
                return Promise.resolve();
            }
            const btn = gid('BtnLoad');
            state.currentServiceMode = gid('Mode').value;
            updateModeLabels();
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>กำลังโหลด...';
            gid('Alert').classList.add('d-none');
            gid('Meta').textContent = 'กำลังโหลด...';
            gid('Body').innerHTML = '<tr><td colspan="' + (cfg.colspan || 12) + '" class="text-center text-muted py-4">กำลังโหลด...</td></tr>';

            const apiAction = cfg.isRegister ? 'dispense_register' : 'dispense_orders';
            return dispenseApi(apiAction, {
                start_date: gid('DateStart').value,
                end_date: gid('DateEnd').value,
                service_mode: state.currentServiceMode
            }).then(function(res) {
                btn.disabled = false;
                btn.innerHTML = cfg.loadBtnHtml;
                if (res.status !== 'success') {
                    showAlert('danger', res.message || (cfg.isRegister ? 'โหลดทะเบียนไม่สำเร็จ' : 'ดึงข้อมูลไม่สำเร็จ'));
                    state.ordersAll = [];
                    state.ordersFiltered = [];
                    state.filteredIndices = [];
                    populateUnitFilter([]);
                    gid('Search').disabled = true;
                    renderRegisterDashboard();
                    renderOrders();
                    return;
                }
                state.ordersAll = res.rows || [];
                if (res.rooms && res.rooms.length) populateDispenseRooms(res.rooms);
                if (cfg.isRegister) sortRegisterRows(state.ordersAll);
                populateUnitFilter(res.units || []);
                gid('UnitFilter').value = '';
                gid('Search').disabled = false;
                gid('Search').value = '';
                if (cfg.isRegister && gid('StatusFilter')) gid('StatusFilter').value = '';
                renderRegisterDashboard();
                applyFilters();
                const unitCount = (res.units || []).length;
                const rangeText = (res.start_date || '') + ' ถึง ' + (res.end_date || '');
                showAlert('info',
                    (cfg.isRegister ? 'พบทะเบียนจ่ายยา ' : 'ดึงรายการรวม ') + state.ordersAll.length +
                    ' รายการ (' + rangeText + ') จาก ' + unitCount + ' หน่วย');
            }).catch(function(err) {
                btn.disabled = false;
                btn.innerHTML = cfg.loadBtnHtml;
                showAlert('danger', err.message || 'เชื่อมต่อไม่สำเร็จ');
            });
        }

        function scheduleAutoLoad() {
            if (state.autoLoaded) return;
            if (cfg.tabWrapId) {
                const wrap = document.getElementById(cfg.tabWrapId);
                if (wrap && !wrap.classList.contains('active') && !wrap.classList.contains('show')) return;
            }
            function runAutoLoad() {
                if (state.autoLoaded) return;
                state.autoLoaded = true;
                loadDispenseList();
            }
            function afterPaint() {
                requestAnimationFrame(function() { requestAnimationFrame(runAutoLoad); });
            }
            if (document.readyState === 'complete') afterPaint();
            else window.addEventListener('load', afterPaint, { once: true });
        }

        gid('BtnLoad').addEventListener('click', loadDispenseList);
        gid('UnitFilter').addEventListener('change', applyFilters);
        gid('Search').addEventListener('input', applyFilters);
        if (cfg.isRegister) {
            const clearBtn = gid('ClearStatusFilter');
            if (clearBtn) clearBtn.addEventListener('click', function() { setStatusFilter(''); });
            document.querySelectorAll('#' + pfx + 'Dashboard .cdm-stat-filter').forEach(function(el) {
                el.addEventListener('click', function() {
                    const cur = gid('StatusFilter') ? gid('StatusFilter').value : '';
                    setStatusFilter(cur === el.dataset.status ? '' : el.dataset.status);
                });
            });
            document.querySelectorAll('#' + pfx + 'WorkflowBar .cdm-wf-step').forEach(function(el) {
                el.addEventListener('click', function() {
                    const wf = el.dataset.wf || '';
                    const cur = gid('StatusFilter') ? gid('StatusFilter').value : '';
                    setStatusFilter(cur === wf ? '' : wf);
                });
            });
            document.querySelectorAll('#' + pfx + 'Dashboard .cdm-extra-filter').forEach(function(el) {
                el.addEventListener('click', function() {
                    const cur = gid('StatusFilter') ? gid('StatusFilter').value : '';
                    setStatusFilter(cur === el.dataset.status ? '' : el.dataset.status);
                });
            });
        }
        gid('Mode').addEventListener('change', function() {
            state.currentServiceMode = this.value;
            updateModeLabels();
            state.ordersAll = [];
            state.ordersFiltered = [];
            state.filteredIndices = [];
            populateUnitFilter([]);
            gid('UnitFilter').value = '';
            gid('Search').value = '';
            gid('Search').disabled = true;
            if (cfg.isRegister) {
                if (gid('StatusFilter')) gid('StatusFilter').value = '';
                if (gid('Dashboard')) gid('Dashboard').classList.add('d-none');
            }
            gid('Meta').textContent = '';
            gid('Body').innerHTML = '<tr><td colspan="' + (cfg.colspan || 12) + '" class="text-center text-muted py-4">' + esc(cfg.emptyHint) + '</td></tr>';
            gid('Alert').classList.add('d-none');
        });

        gid('Body').addEventListener('click', function(e) {
            const btnEl = e.target.closest('.btn-open-dispense');
            if (!btnEl) return;
            if (!cfg.isRegister && !getFixedDispenseRoomId()) {
                openDispenseRoomModal(false);
                return;
            }
            const idx = Number(btnEl.closest('tr').dataset.idx);
            activeRecord = state.ordersAll[idx];
            if (!activeRecord) return;
            modalSourcePrefix = pfx;
            ensureSigPads();
            document.getElementById('dmGuid').value = activeRecord.hosxp_guid;
            document.getElementById('dmPatient').textContent = activeRecord.ptname + ' (HN ' + activeRecord.hn + ')';
            document.getElementById('dmOrderDatetime').textContent = activeRecord.order_datetime || '-';
            document.getElementById('dmLocation').textContent = locationText(activeRecord);
            document.getElementById('dmDrug').textContent = activeRecord.drug_name + ' — สั่ง ' + fmtQty(activeRecord.order_qty) + ' ' + (activeRecord.unit || '');
            fillDispenseModalFields(activeRecord);
            document.getElementById('dmNote').value = activeRecord.note || '';
            sigPads.receiver.clear();
            sigPads.dispenser.clear();
            const modalEl = document.getElementById('dispenseModal');
            const onShown = function() {
                initReceiverSelect();
                initPreparerSelect();
                initDispenserSelect();
                setPreparerSelectValue(activeRecord.preparer_name || CDM_DEFAULT_DISPENSER);
                setReceiverSelectValue(activeRecord.receiver_name || '');
                setDispenserSelectValue(activeRecord.dispenser_name || CDM_DEFAULT_DISPENSER);
                setStockRoomSelectValue(activeRecord.stock_room_id, activeRecord.service_mode);
                loadDispenseSignatures(activeRecord);
                modalEl.removeEventListener('shown.bs.modal', onShown);
            };
            modalEl.addEventListener('shown.bs.modal', onShown);
            new bootstrap.Modal(modalEl).show();
        });

        updateModeLabels();

        const ctrl = { loadDispenseList: loadDispenseList, scheduleAutoLoad: scheduleAutoLoad, prefix: pfx };
        paneControllers[pfx] = ctrl;
        if (cfg.isRegister) {
            window.cdmTryAutoLoadRegister = scheduleAutoLoad;
            scheduleAutoLoad();
        } else {
            window.cdmTryAutoLoadDispense = function() {
                if (!getFixedDispenseRoomId()) return;
                scheduleAutoLoad();
            };
            loadDispenseRoomsList().then(function() {
                updateFixedRoomUi();
                if (getFixedDispenseRoomId()) scheduleAutoLoad();
                else openDispenseRoomModal(false);
            });
        }
        return ctrl;
    }

    document.getElementById('dspRoomModalConfirmBtn')?.addEventListener('click', confirmDispenseRoomPick);
    document.getElementById('dspChangeRoomBtn')?.addEventListener('click', function() {
        openDispenseRoomModal(true);
    });
    window.cdmOnDispenseTabShown = function() {
        if (!document.getElementById('dispenseRoomModal')) return;
        loadDispenseRoomsList().then(function() {
            updateFixedRoomUi();
            if (!getFixedDispenseRoomId()) openDispenseRoomModal(false);
            else if (typeof window.cdmTryAutoLoadDispense === 'function') window.cdmTryAutoLoadDispense();
        });
    };

    document.getElementById('dmDispenseQty').addEventListener('input', function() {
        if (!remainsQtyDirty) document.getElementById('dmRemainsQty').value = this.value;
    });
    document.getElementById('dmRemainsQty').addEventListener('input', function() { remainsQtyDirty = true; });

    function buildSavePayload(saveMode) {
        const srcGid = function(id) { return document.getElementById(modalSourcePrefix + id); };
        ensureSigPads();
        return Object.assign({}, activeRecord, {
            service_date: activeRecord.vstdate || srcGid('DateEnd').value,
            service_mode: activeRecord.service_mode || srcGid('Mode').value,
            dispense_qty: document.getElementById('dmDispenseQty').value,
            drug_remains_qty: document.getElementById('dmRemainsQty').value,
            drug_remains_received_qty: document.getElementById('dmRemainsReceivedQty').value,
            form_received: document.getElementById('dmFormReceived').checked ? 1 : 0,
            preparer_name: getPreparerSelectValue(),
            receiver_name: getReceiverSelectValue(),
            dispenser_name: getDispenserSelectValue(),
            receiver_signature: sigPads.receiver.dataUrl(),
            dispenser_signature: sigPads.dispenser.dataUrl(),
            note: document.getElementById('dmNote').value,
            stock_room_id: getStockRoomSelectValue(),
            save_mode: saveMode
        });
    }

    function submitDispenseSave(saveMode, btn) {
        if (!activeRecord) return;
        const srcCtrl = paneControllers[modalSourcePrefix];
        btn.disabled = true;
        dispenseApi('dispense_save', { record: buildSavePayload(saveMode) }).then(function(res) {
            btn.disabled = false;
            if (res.status !== 'success') { alert(res.message || 'บันทึกไม่สำเร็จ'); return; }
            if (saveMode === 'dispense') {
                bootstrap.Modal.getInstance(document.getElementById('dispenseModal')).hide();
            }
            if (srcCtrl) return srcCtrl.loadDispenseList();
            if (paneControllers.dsp) return paneControllers.dsp.loadDispenseList();
        }).catch(function(err) { btn.disabled = false; alert(err.message); });
    }

    document.getElementById('btnSavePrepare').addEventListener('click', function() {
        submitDispenseSave('prepare', this);
    });

    document.getElementById('btnSaveDispense').addEventListener('click', function() {
        submitDispenseSave('dispense', this);
    });

    CDM_PANE_CONFIGS.forEach(initCdmDispensePane);
})();
</script>
<?php endif; ?>
