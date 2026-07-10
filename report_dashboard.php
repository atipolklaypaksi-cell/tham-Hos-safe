<?php
session_start();
if (!isset($_SESSION['user_id']) && !isset($_SESSION['full_name'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/rational_lab_use_helper.php';
$default_end = date('Y-m-d', strtotime('last day of previous month'));
$default_start = date('Y-m-01', strtotime($default_end));
$default_compare_year = (int)date('Y') - 1;
$compare_year_options = [];
for ($y = (int)date('Y'); $y >= (int)date('Y') - 15; $y--) {
    $compare_year_options[] = $y;
}
$default_compare_months = [];
for ($slot = 1; $slot <= 3; $slot++) {
    $default_compare_months[$slot] = date('Y-m', strtotime('-' . $slot . ' month'));
}
$income_group_options = rlu_income_group_catalog();
$default_income_groups = [3, 4];
$hipdata_group_options = rlu_hipdata_group_catalog();
session_write_close();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Dashboard รายงาน</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
    <link href="css/flatpickr.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', Tahoma, sans-serif; background-color: #f4f7f6; }
        .rlu-stat-card { border: 0; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,.06); }
        .rlu-stat-card .stat-value { font-size: 1.35rem; font-weight: 700; }
        .rlu-table thead { background: #0d9488; color: #fff; }
        .rlu-year-pill { font-size: .8rem; }
        .rlu-compare-up { color: #dc3545; }
        .rlu-compare-down { color: #198754; }
        #buildProgress { height: 8px; }
        #compareGroupChartWrap { position: relative; height: 380px; }
        #compareSummaryChartWrap { position: relative; height: 220px; }
        .rlu-group-link { cursor: pointer; color: #1f4d3b; }
        .rlu-group-link:hover { text-decoration: underline !important; color: #0d6efd !important; }
        .rlu-item-link { cursor: pointer; color: #1f4d3b; }
        .rlu-item-link:hover { text-decoration: underline !important; color: #0d6efd !important; }
        .rlu-doctor-link { cursor: pointer; color: #1f4d3b; }
        .rlu-doctor-link:hover { text-decoration: underline !important; color: #0d6efd !important; }
        #groupDetailDoctorAggWrap { display: none; }
        #groupDetailModal .table { font-size: 0.875rem; }
        #groupDetailItemChartWrap { position: relative; height: 320px; }
        #groupDetailSummaryChartWrap { position: relative; height: 180px; }
        #groupDetailChartFilters { max-height: 280px; overflow-y: auto; }
        #groupDetailTabs .nav-link { font-size: 0.875rem; }
        #incomeGroupFilters { max-height: 220px; overflow-y: auto; font-size: .85rem; }
        #incomeGroupFilters .form-check { margin-bottom: .2rem; }
        #hipdataGroupFilters { max-height: 220px; overflow-y: auto; font-size: .85rem; }
        #hipdataGroupFilters .form-check { margin-bottom: .2rem; }
        #chartGroupFilters { max-height: 280px; overflow-y: auto; }
        #chartGroupFilters .form-check { margin-bottom: .2rem; }
        .rlu-sort-th { cursor: pointer; user-select: none; white-space: nowrap; }
        .rlu-sort-th:hover { background: #e9ecef !important; }
        .rlu-sort-th .sort-arrow { font-size: 0.7rem; opacity: 0.55; margin-left: 2px; }
        .rlu-sort-th.active .sort-arrow { opacity: 1; color: #0d9488; }
    </style>
</head>
<body>
<div class="wrapper">
    <?php include('sidebar.php'); ?>
    <div id="content" class="content">
        <div class="container-fluid py-4">
            <h4 class="fw-bold mb-1 text-dark">
                <i class="fas fa-file-alt me-2" style="color:#0d9488;"></i>
                Dashboard รายงาน
            </h4>
            <p class="text-muted small mb-4">
                รายงานรายการย่อย (opitemrece) ตามหมวดรายได้ income.group2 — ประมวลผลทีละเดือนแล้วเก็บ cache โหลดครั้งถัดไปเร็วขึ้น
            </p>

            <div class="bg-white border rounded shadow-sm p-3 mb-3">
                <div class="row g-2 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label small fw-bold mb-1">ตั้งแต่</label>
                        <input type="text" id="filterStart" class="form-control form-control-sm" value="<?php echo htmlspecialchars($default_start); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold mb-1">ถึง</label>
                        <input type="text" id="filterEnd" class="form-control form-control-sm" value="<?php echo htmlspecialchars($default_end); ?>">
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary w-100" onclick="setQuickRange('ytd')">ตั้งแต่ต้นปี</button>
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary w-100" onclick="setQuickRange('last12')">12 เดือนล่าสุด</button>
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-sm fw-bold text-white w-100" style="background:#0d9488;" id="btnLoad" onclick="loadRluDashboard()">
                            <i class="fas fa-sync-alt me-1"></i> โหลดข้อมูล
                        </button>
                    </div>
                </div>

                <div class="border rounded p-2 mt-3 bg-white">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label small fw-bold mb-0">หมวดรายได้ (income.group2)</label>
                        <span class="small">
                            <button type="button" class="btn btn-link btn-sm p-0" onclick="selectIncomeGroups('drug')">ยา [3,4]</button>
                            <span class="text-muted">|</span>
                            <button type="button" class="btn btn-link btn-sm p-0" onclick="selectIncomeGroups('all')">ทั้งหมด</button>
                            <span class="text-muted">|</span>
                            <button type="button" class="btn btn-link btn-sm p-0" onclick="selectIncomeGroups('none')">ล้าง</button>
                        </span>
                    </div>
                    <div id="incomeGroupFilters" class="border rounded p-2 bg-light">
                        <?php foreach ($income_group_options as $gid => $gname): ?>
                        <div class="form-check">
                            <input class="form-check-input income-group-cb" type="checkbox" value="<?php echo (int)$gid; ?>"
                                id="incomeGroup<?php echo (int)$gid; ?>"
                                <?php echo in_array((int)$gid, $default_income_groups, true) ? ' checked' : ''; ?>>
                            <label class="form-check-label" for="incomeGroup<?php echo (int)$gid; ?>">
                                [<?php echo (int)$gid; ?>] <?php echo htmlspecialchars($gname, ENT_QUOTES, 'UTF-8'); ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="border rounded p-2 mt-3 bg-white">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label small fw-bold mb-0">กลุ่มสิทธิการรักษา (pttype.hipdata_code)</label>
                        <span class="small">
                            <button type="button" class="btn btn-link btn-sm p-0" onclick="selectHipdataGroups('ucs')">UCS</button>
                            <span class="text-muted">|</span>
                            <button type="button" class="btn btn-link btn-sm p-0" onclick="selectHipdataGroups('all')">ทั้งหมด</button>
                            <span class="text-muted">|</span>
                            <button type="button" class="btn btn-link btn-sm p-0" onclick="selectHipdataGroups('none')">ล้าง</button>
                        </span>
                    </div>
                    <div id="hipdataGroupFilters" class="border rounded p-2 bg-light">
                        <?php foreach ($hipdata_group_options as $code): ?>
                        <div class="form-check form-check-inline me-3">
                            <input class="form-check-input hipdata-group-cb" type="checkbox" value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>"
                                id="hipdataGroup<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>">
                            <label class="form-check-label" for="hipdataGroup<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="small text-muted mt-1">ไม่เลือก = แสดงทุกกลุ่มสิทธิ</div>
                </div>

                <div class="border rounded p-2 mt-3 bg-white">
                    <label class="form-label small fw-bold mb-1">ประเภท visit (VN/AN)</label>
                    <div class="btn-group btn-group-sm" role="group">
                        <input type="radio" class="btn-check" name="filterVisitScope" id="filterVisitScopeAll" value="total" checked>
                        <label class="btn btn-outline-secondary" for="filterVisitScopeAll">ทั้งหมด</label>
                        <input type="radio" class="btn-check" name="filterVisitScope" id="filterVisitScopeVn" value="vn_only">
                        <label class="btn btn-outline-secondary" for="filterVisitScopeVn">เฉพาะ VN</label>
                        <input type="radio" class="btn-check" name="filterVisitScope" id="filterVisitScopeAn" value="an_only">
                        <label class="btn btn-outline-secondary" for="filterVisitScopeAn">เฉพาะ AN</label>
                    </div>
                    <div class="small text-muted mt-1">เฉพาะ VN = รายการจาก visit นอก (OPD) | เฉพาะ AN = รายการจากผู้ป่วยใน (IPD)</div>
                </div>

                <div class="border rounded p-2 mt-3 bg-light">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="enableCompare" onchange="toggleComparePanel()">
                        <label class="form-check-label small fw-bold" for="enableCompare">เปรียบเทียบช่วงเวลา</label>
                    </div>
                    <div id="comparePanel" class="d-none">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label small mb-1">โหมดเปรียบเทียบ</label>
                                <select id="compareMode" class="form-select form-select-sm" onchange="toggleComparePanel()">
                                    <option value="prev_year">ช่วงเดียวกันปีก่อน</option>
                                    <option value="year">เทียบเป็นปี</option>
                                    <option value="month">เทียบเป็นเดือน</option>
                                    <option value="custom">กำหนดช่วงเอง</option>
                                </select>
                            </div>
                            <div class="col-md-2 compare-year d-none">
                                <label class="form-label small mb-1">จำนวนปีที่เทียบ</label>
                                <select id="compareYearCount" class="form-select form-select-sm" onchange="toggleCompareYearPickers()">
                                    <option value="1">1 ปี</option>
                                    <option value="2">2 ปี</option>
                                    <option value="3">3 ปี</option>
                                </select>
                            </div>
                            <?php for ($slot = 1; $slot <= 3; $slot++): ?>
                            <div class="col-md-2 compare-year compare-year-pick d-none" data-slot="<?php echo $slot; ?>"<?php echo $slot > 1 ? ' style="display:none"' : ''; ?>>
                                <label class="form-label small mb-1">ปีที่ <?php echo $slot; ?></label>
                                <select id="compareYear<?php echo $slot; ?>" class="form-select form-select-sm compare-year-select">
                                    <?php foreach ($compare_year_options as $y): ?>
                                    <option value="<?php echo (int)$y; ?>"<?php echo $y === ($default_compare_year - ($slot - 1)) ? ' selected' : ''; ?>><?php echo (int)$y; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endfor; ?>
                            <div class="col-md-2 compare-month d-none">
                                <label class="form-label small mb-1">จำนวนเดือนที่เทียบ</label>
                                <select id="compareMonthCount" class="form-select form-select-sm" onchange="toggleCompareMonthPickers()">
                                    <option value="1">1 เดือน</option>
                                    <option value="2">2 เดือน</option>
                                    <option value="3">3 เดือน</option>
                                </select>
                            </div>
                            <?php for ($slot = 1; $slot <= 3; $slot++): ?>
                            <div class="col-md-2 compare-month compare-month-pick d-none" data-slot="<?php echo $slot; ?>"<?php echo $slot > 1 ? ' style="display:none"' : ''; ?>>
                                <label class="form-label small mb-1">เดือนที่ <?php echo $slot; ?></label>
                                <input type="month" id="compareMonth<?php echo $slot; ?>" class="form-control form-control-sm"
                                    value="<?php echo htmlspecialchars($default_compare_months[$slot] ?? date('Y-m')); ?>">
                            </div>
                            <?php endfor; ?>
                            <div class="col-md-2 compare-custom d-none">
                                <label class="form-label small mb-1">เปรียบเทียบ ตั้งแต่</label>
                                <input type="text" id="compareStart" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-2 compare-custom d-none">
                                <label class="form-label small mb-1">เปรียบเทียบ ถึง</label>
                                <input type="text" id="compareEnd" class="form-control form-control-sm">
                            </div>
                        </div>
                    </div>
                </div>

                <div id="buildBox" class="mt-2 d-none">
                    <div class="small text-muted mb-1" id="buildLabel">กำลังประมวลผล...</div>
                    <div class="progress" id="buildProgressWrap">
                        <div class="progress-bar progress-bar-striped progress-bar-animated bg-teal" id="buildProgress" style="width:0%;background:#0d9488;"></div>
                    </div>
                </div>
                <div id="metaBox" class="alert alert-info py-2 px-3 mt-2 mb-0 small fw-bold d-none"></div>
            </div>

            <div class="row g-3 mb-3 period-section" id="summaryCards">
                <div class="col-md-6">
                    <div class="card rlu-stat-card h-100"><div class="card-body">
                        <div class="text-muted small">จำนวนรายการ</div>
                        <div class="stat-value text-dark" id="sumItems">—</div>
                    </div></div>
                </div>
                <div class="col-md-6">
                    <div class="card rlu-stat-card h-100"><div class="card-body">
                        <div class="text-muted small">ราคารวม (บาท)</div>
                        <div class="stat-value text-success" id="sumPrice">—</div>
                    </div></div>
                </div>
            </div>
            <div class="row g-3 mb-3 d-none" id="compareMultiSummary"></div>

            <div class="chart-section d-none mb-2" id="compareDimensionBar">
                <div class="small text-muted px-1" id="compareDimensionHint">รายการ — จาก opitemrece กรองหมวดรายได้ | คลิกแท่งกราฟเพื่อดูแพทย์ที่สั่งมาก</div>
            </div>

            <div class="card border-0 shadow-sm mb-4 chart-section d-none" id="compareChartCard">
                <div class="card-header bg-white fw-bold d-flex flex-wrap align-items-center gap-2">
                    <span id="compareChartTitle"><i class="fas fa-chart-column me-1" style="color:#0d9488;"></i> กราฟเปรียบเทียบรายการ</span>
                    <span class="badge bg-secondary ms-auto" id="compareChartLabels"></span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-lg-3">
                            <label class="form-label small fw-bold mb-1">แสดงข้อมูล</label>
                            <div class="btn-group btn-group-sm w-100 mb-3" role="group">
                                <input type="radio" class="btn-check" name="chartMetric" id="chartMetricCount" value="count" checked onchange="onCompareChartMetricChange()">
                                <label class="btn btn-outline-secondary" for="chartMetricCount">จำนวนรายการ</label>
                                <input type="radio" class="btn-check" name="chartMetric" id="chartMetricPrice" value="price" onchange="onCompareChartMetricChange()">
                                <label class="btn btn-outline-secondary" for="chartMetricPrice">ราคา (บาท)</label>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="small fw-bold" id="compareChartFilterLabel">เลือกรายการ</span>
                                <span class="small">
                                    <button type="button" class="btn btn-link btn-sm p-0" onclick="selectChartGroups('top8')">Top 8</button>
                                    <span class="text-muted">|</span>
                                    <button type="button" class="btn btn-link btn-sm p-0" onclick="selectChartGroups('top15')">Top 15</button>
                                    <span class="text-muted">|</span>
                                    <button type="button" class="btn btn-link btn-sm p-0" onclick="selectChartGroups('top30')">Top 30</button>
                                    <span class="text-muted">|</span>
                                    <button type="button" class="btn btn-link btn-sm p-0" onclick="selectChartGroups('all')">ทั้งหมด</button>
                                    <span class="text-muted">|</span>
                                    <button type="button" class="btn btn-link btn-sm p-0" onclick="selectChartGroups('none')">ล้าง</button>
                                </span>
                            </div>
                            <input type="search" id="chartGroupSearch" class="form-control form-control-sm mb-2" placeholder="ค้นหาชื่อรายการ..." autocomplete="off">
                            <div id="chartGroupFilters" class="border rounded p-2 bg-light">
                                <span class="text-muted small" id="chartGroupFilterPlaceholder">โหลดข้อมูลก่อน</span>
                                <div id="chartGroupFilterList"></div>
                            </div>
                        </div>
                        <div class="col-lg-9">
                            <div id="compareGroupChartWrap">
                                <canvas id="compareGroupChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4 pt-3 border-top">
                        <div class="small text-muted mb-2 fw-bold">สรุปรวมกลุ่มที่เลือก — เปรียบเทียบแต่ละช่วง</div>
                        <div id="compareSummaryChartWrap">
                            <canvas id="compareSummaryChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4 compare-only-section d-none" id="compareTableCard">
                <div class="card-header bg-white fw-bold">
                    <i class="fas fa-balance-scale me-1 text-secondary"></i> <span id="compareTableTitle">เปรียบเทียบรายการ</span>
                    <span class="badge bg-secondary ms-1" id="compareLabels"></span>
                    <small class="text-muted fw-normal ms-2" id="compareTableHint">คลิกหัวคอลัมน์เพื่อเรียง | คลิกแท่งกราฟเพื่อดูแพทย์ที่สั่งมาก</small>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light text-center small" id="compareThead">
                            <tr>
                                <th class="text-start">รายการ</th>
                                <th>ช่วง A จำนวน</th><th>ช่วง B จำนวน</th><th>ต่าง</th><th>%</th>
                                <th>ช่วง A ราคา</th><th>ช่วง B ราคา</th><th>ต่างราคา</th><th>%</th>
                            </tr>
                        </thead>
                        <tbody id="compareTbody"><tr><td colspan="9" class="text-center py-3 text-muted">—</td></tr></tbody>
                    </table>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4 period-section">
                <div class="card-header bg-white fw-bold"><i class="fas fa-calendar-alt me-1 text-secondary"></i> สรุปรายเดือน</div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light text-center small">
                            <tr><th>เดือน</th><th>จำนวนรายการ</th><th>ราคารวม (บาท)</th></tr>
                        </thead>
                        <tbody id="monthTbody"><tr><td colspan="3" class="text-center py-3 text-muted">กดโหลดข้อมูล</td></tr></tbody>
                    </table>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4 period-section">
                <div class="card-header bg-white fw-bold"><i class="fas fa-calendar me-1 text-secondary"></i> สรุปแยกตามปี</div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light text-center small">
                            <tr><th>ปี</th><th>จำนวนรายการ</th><th>ราคารวม (บาท)</th></tr>
                        </thead>
                        <tbody id="yearTbody"><tr><td colspan="3" class="text-center py-3 text-muted">—</td></tr></tbody>
                    </table>
                </div>
            </div>

            <div class="card border-0 shadow-sm period-section">
                <div class="card-header bg-white fw-bold d-flex flex-wrap align-items-center gap-2">
                    <span><i class="fas fa-list me-1 text-secondary"></i> รายละเอียดแยกปี + รายการ</span>
                    <button type="button" class="btn btn-sm btn-outline-success ms-auto" id="btnExportDetailExcel" disabled onclick="exportDetailExcel()">
                        <i class="fas fa-file-excel me-1"></i> ส่งออก Excel
                    </button>
                </div>
                <div class="px-3 pt-2 pb-0 border-bottom">
                    <input type="search" id="detailTableSearch" class="form-control form-control-sm" placeholder="ค้นหารายการหรือรหัส..." autocomplete="off">
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle rlu-table">
                        <thead class="table-light text-center small" id="detailThead">
                            <tr><th>ปี</th><th class="text-start">รายการ</th><th>รหัส</th><th>จำนวน</th><th>ราคา (บาท)</th></tr>
                        </thead>
                        <tbody id="detailTbody"><tr><td colspan="5" class="text-center py-4 text-muted">กดโหลดข้อมูล</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="itemDoctorModal" tabindex="-1" aria-labelledby="itemDoctorModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold" id="itemDoctorModalLabel"><i class="fas fa-user-md me-2"></i>แพทย์ที่สั่งรายการ Lab</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div id="itemDoctorMeta" class="px-3 py-2 bg-light border-bottom small text-muted"></div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light text-center small" id="itemDoctorThead">
                            <tr><th class="text-start">แพทย์</th><th>รหัส</th><th>จำนวน</th><th>ราคา</th></tr>
                        </thead>
                        <tbody id="itemDoctorTbody">
                            <tr><td colspan="4" class="text-center py-4 text-muted">—</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer py-2">
                <small class="text-muted me-auto" id="itemDoctorElapsed"></small>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">ปิด</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="groupDetailModal" tabindex="-1" aria-labelledby="groupDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold" id="groupDetailModalLabel"><i class="fas fa-list-alt me-2"></i>รายละเอียดรายการ</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div id="groupDetailMeta" class="px-3 py-2 bg-light border-bottom small text-muted"></div>
                <ul class="nav nav-tabs px-3 pt-2 bg-white border-bottom" id="groupDetailTabs" role="tablist">
                    <li class="nav-item d-none" role="presentation" id="groupDetailTabItems">
                        <button class="nav-link" type="button" data-detail-tab="items" onclick="switchGroupDetailTab('items')">
                            <i class="fas fa-list me-1"></i> รายการ
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" type="button" data-detail-tab="doctor" onclick="switchGroupDetailTab('doctor')">
                            <i class="fas fa-user-md me-1"></i> แพทย์
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" type="button" data-detail-tab="dept" onclick="switchGroupDetailTab('dept')">
                            <i class="fas fa-hospital me-1"></i> แผนก
                        </button>
                    </li>
                </ul>
                <div id="groupDetailChartSection" class="border-bottom px-3 py-3 d-none">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <span class="fw-bold small" id="groupDetailChartTitle"><i class="fas fa-chart-column me-1" style="color:#0d9488;"></i> กราฟเปรียบเทียบตามแพทย์</span>
                        <span class="badge bg-secondary ms-auto" id="groupDetailChartLabels"></span>
                    </div>
                    <div class="row g-3">
                        <div class="col-lg-3">
                            <label class="form-label small fw-bold mb-1">แสดงข้อมูล</label>
                            <div class="btn-group btn-group-sm w-100 mb-3" role="group">
                                <input type="radio" class="btn-check" name="detailChartMetric" id="detailChartMetricCount" value="count" checked>
                                <label class="btn btn-outline-secondary" for="detailChartMetricCount">จำนวนรายการ</label>
                                <input type="radio" class="btn-check" name="detailChartMetric" id="detailChartMetricPrice" value="price">
                                <label class="btn btn-outline-secondary" for="detailChartMetricPrice">ราคา (บาท)</label>
                            </div>
                            <div id="groupDetailDoctorAggWrap" class="mb-3">
                                <label class="form-label small fw-bold mb-1">สรุปแพทย์</label>
                                <div class="btn-group btn-group-sm w-100" role="group">
                                    <input type="radio" class="btn-check" name="detailDoctorAgg" id="detailDoctorAggTotal" value="total" checked>
                                    <label class="btn btn-outline-secondary" for="detailDoctorAggTotal">รวม</label>
                                    <input type="radio" class="btn-check" name="detailDoctorAgg" id="detailDoctorAggVn" value="vn_only">
                                    <label class="btn btn-outline-secondary" for="detailDoctorAggVn">เฉพาะ VN</label>
                                    <input type="radio" class="btn-check" name="detailDoctorAgg" id="detailDoctorAggAn" value="an_only">
                                    <label class="btn btn-outline-secondary" for="detailDoctorAggAn">เฉพาะ AN</label>
                                </div>
                                <div class="form-text">เฉพาะ VN = รายการจาก visit นอก (OPD) | เฉพาะ AN = รายการจากผู้ป่วยใน (IPD)</div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="small fw-bold" id="groupDetailFilterLabel">เลือกแพทย์</span>
                                <span class="small">
                                    <button type="button" class="btn btn-link btn-sm p-0" onclick="selectDetailChartItems('top8')">Top 8</button>
                                    <span class="text-muted">|</span>
                                    <button type="button" class="btn btn-link btn-sm p-0" onclick="selectDetailChartItems('top15')">Top 15</button>
                                    <span class="text-muted">|</span>
                                    <button type="button" class="btn btn-link btn-sm p-0" onclick="selectDetailChartItems('top30')">Top 30</button>
                                    <span class="text-muted">|</span>
                                    <button type="button" class="btn btn-link btn-sm p-0" onclick="selectDetailChartItems('all')">ทั้งหมด</button>
                                    <span class="text-muted">|</span>
                                    <button type="button" class="btn btn-link btn-sm p-0" onclick="selectDetailChartItems('none')">ล้าง</button>
                                </span>
                            </div>
                            <input type="search" id="groupDetailChartSearch" class="form-control form-control-sm mb-2" placeholder="ค้นหาชื่อรายการ..." autocomplete="off">
                            <div id="groupDetailChartFilters" class="border rounded p-2 bg-light small">
                                <span class="text-muted" id="groupDetailChartFilterPlaceholder">โหลดข้อมูลก่อน</span>
                                <div id="groupDetailChartFilterList"></div>
                            </div>
                        </div>
                        <div class="col-lg-9">
                            <div id="groupDetailItemChartWrap">
                                <canvas id="groupDetailItemChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 pt-3 border-top">
                        <div class="small text-muted mb-2 fw-bold" id="groupDetailSummaryTitle">สรุปรวมรายการที่เลือก — เปรียบเทียบแต่ละช่วง</div>
                        <div id="groupDetailSummaryChartWrap">
                            <canvas id="groupDetailSummaryChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light text-center small" id="groupDetailThead">
                            <tr><th class="text-start">รายการ</th><th>รหัส</th><th>จำนวน</th><th>ราคา (บาท)</th></tr>
                        </thead>
                        <tbody id="groupDetailTbody">
                            <tr><td colspan="4" class="text-center py-4 text-muted">กำลังโหลด...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer py-2">
                <small class="text-muted me-auto" id="groupDetailElapsed"></small>
                <button type="button" class="btn btn-outline-success btn-sm" id="btnExportGroupDetailExcel" disabled onclick="exportGroupDetailExcel()">
                    <i class="fas fa-file-excel me-1"></i> ส่งออก Excel
                </button>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">ปิด</button>
            </div>
        </div>
    </div>
</div>

<script src="js/jquery-3.7.0.min.js"></script>
<script src="js/bootstrap.bundle.min.js"></script>
<script src="js/flatpickr.min.js"></script>
<script src="js/chart.min.js"></script>
<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
<script>
let fpStart, fpEnd, fpCompareStart, fpCompareEnd;
let currentComparison = null;
let compareGroupChart = null;
let compareSummaryChart = null;
let compareChartGroups = [];
let itemDoctorModal = null;
let cachedGroupComparison = null;
let dimensionComparisonCache = {};
let currentCompareDimension = 'item';
let loadStatusHideTimer = null;
const RLR_API = 'fetch_income_report_api.php';
const RLR_MODE = true;
let compareTableSort = { key: 'a_item_count', dir: -1 };
let detailTableRows = [];
let detailTableSort = { key: 'item_count', dir: -1 };
let groupDetailModal = null;
let groupDetailItemChart = null;
let groupDetailSummaryChart = null;
let groupDetailChartItems = [];
let groupDetailChartDepts = [];
let groupDetailChartDoctors = [];
let currentGroupDetail = null;
let currentGroupDetailBundle = null;
let currentGroupDetailTab = 'doctor';
let currentGroupContext = null;
let currentDoctorDrilldown = null;
let groupDetailTabFetch = {};
let groupDetailTableSort = { key: 'a_item_count', dir: -1 };
const CHART_COLORS = ['#0d9488', '#6366f1', '#f59e0b', '#ef4444', '#8b5cf6', '#14b8a6'];

const DETAIL_TAB_CONFIG = {
    items: { label: 'รายการ', code: 'รหัส', chart: 'กราฟเปรียบเทียบรายการ', filter: 'เลือกรายการ', key: 'items' },
    dept: { label: 'แผนก', code: 'รหัสแผนก', chart: 'กราฟเปรียบเทียบตามแผนก', filter: 'เลือกแผนก', key: 'departments' },
    doctor: { label: 'แพทย์', code: 'รหัสแพทย์', chart: 'กราฟเปรียบเทียบตามแพทย์', filter: 'เลือกแพทย์', key: 'doctors' },
};

const COMPARE_DIM_CONFIG = {
    item: {
        label: 'รายการ',
        chart: 'กราฟเปรียบเทียบรายการ',
        table: 'เปรียบเทียบรายการ',
        filter: 'เลือกรายการ',
        hint: 'รายการ — จาก opitemrece กรองหมวดรายได้ | คลิกแท่งกราฟเพื่อดูแพทย์/แผนก',
        tableHint: 'คลิกหัวคอลัมน์เพื่อเรียง | คลิกแท่งกราฟเพื่อดูรายละเอียดแพทย์/แผนก',
    },
    dept: {
        label: 'แผนก',
        chart: 'กราฟเปรียบเทียบตามแผนก',
        table: 'เปรียบเทียบตามแผนก',
        filter: 'เลือกแผนก',
        hint: 'แผนก — จาก ovst/opitemrece | ดึงจาก HOSxP โดยตรง',
        tableHint: 'เรียงตามจำนวนมากไปน้อย',
    },
    doctor: {
        label: 'แพทย์',
        chart: 'กราฟเปรียบเทียบตามแพทย์',
        table: 'เปรียบเทียบตามแพทย์',
        filter: 'เลือกแพทย์',
        hint: 'แพทย์ — จาก opitemrece | รวม/เฉพาะ VN/เฉพาะ AN | คลิกแท่งกราฟเพื่อดูรายการ',
        tableHint: 'คลิกชื่อแพทย์หรือแท่งกราฟเพื่อดูรายการที่สั่งมาก',
    },
};

function groupLink(code, name, year) {
    const attrs = 'href="#" class="rlu-group-link text-decoration-none fw-bold"'
        + ' data-group-code="' + esc(code || '') + '"'
        + ' data-group-name="' + esc(name || '') + '"'
        + (year ? (' data-year="' + esc(year) + '"') : '');
    return '<a ' + attrs + '>' + esc(name || 'ไม่ระบุกลุ่ม') + '</a>';
}

function esc(s) {
    if (s == null) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function qs(params) {
    return new URLSearchParams(params).toString();
}

function toggleComparePanel() {
    const on = $('#enableCompare').is(':checked');
    $('#comparePanel').toggleClass('d-none', !on);
    const mode = $('#compareMode').val();
    $('.compare-custom').toggleClass('d-none', !(on && mode === 'custom'));
    const byYear = on && mode === 'year';
    $('.compare-year').toggleClass('d-none', !byYear);
    const byMonth = on && mode === 'month';
    $('.compare-month').toggleClass('d-none', !byMonth);
    if (byYear) {
        toggleCompareYearPickers();
    } else if (byMonth) {
        toggleCompareMonthPickers();
    } else {
        $('.compare-year-pick').hide();
        $('.compare-month-pick').hide();
    }
    setReportViewMode(on);
}

function toggleCompareYearPickers() {
    const n = parseInt($('#compareYearCount').val(), 10) || 1;
    $('.compare-year-pick').each(function() {
        const slot = parseInt($(this).data('slot'), 10);
        $(this).toggle(slot <= n);
    });
}

function toggleCompareMonthPickers() {
    const n = parseInt($('#compareMonthCount').val(), 10) || 1;
    $('.compare-month-pick').each(function() {
        const slot = parseInt($(this).data('slot'), 10);
        $(this).toggle(slot <= n);
    });
}

function setQuickRange(mode) {
    const end = new Date();
    let start = new Date();
    if (mode === 'ytd') {
        start = new Date(end.getFullYear(), 0, 1);
    } else if (mode === 'last12') {
        start.setMonth(start.getMonth() - 11);
        start.setDate(1);
    }
    const fmt = d => d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
    if (fpStart) fpStart.setDate(fmt(start), true); else $('#filterStart').val(fmt(start));
    if (fpEnd) fpEnd.setDate(fmt(end), true); else $('#filterEnd').val(fmt(end));
}

function renderMonthRows(rows) {
    if (!rows || !rows.length) return '<tr><td colspan="3" class="text-center py-3 text-muted">ไม่มีข้อมูล</td></tr>';
    return rows.map(r => '<tr>'
        + '<td class="text-center fw-bold">' + esc(r.year_month) + '</td>'
        + '<td class="text-center">' + esc(r.item_count_display) + '</td>'
        + '<td class="text-center text-success fw-bold">' + esc(r.total_price_display) + '</td>'
        + '</tr>').join('');
}

function renderYearRows(rows) {
    if (!rows || !rows.length) return '<tr><td colspan="3" class="text-center py-3 text-muted">ไม่มีข้อมูล</td></tr>';
    return rows.map(r => '<tr>'
        + '<td class="text-center fw-bold">' + esc(r.year) + '</td>'
        + '<td class="text-center">' + esc(r.item_count_display) + '</td>'
        + '<td class="text-center text-success fw-bold">' + esc(r.total_price_display) + '</td>'
        + '</tr>').join('');
}

function getIncomeGroupList() {
    const ids = [];
    $('.income-group-cb:checked').each(function() {
        const v = $(this).val();
        if (v && ids.indexOf(v) === -1) ids.push(v);
    });
    return ids;
}

function selectIncomeGroups(mode) {
    if (mode === 'drug') {
        $('.income-group-cb').prop('checked', false);
        $('#incomeGroup3, #incomeGroup4').prop('checked', true);
        return;
    }
    if (mode === 'all') {
        $('.income-group-cb').prop('checked', true);
        return;
    }
    $('.income-group-cb').prop('checked', false);
}

function getHipdataCodeList() {
    const codes = [];
    $('.hipdata-group-cb:checked').each(function() {
        const v = $(this).val();
        if (v && codes.indexOf(v) === -1) codes.push(v);
    });
    return codes;
}

function selectHipdataGroups(mode) {
    if (mode === 'ucs') {
        $('.hipdata-group-cb').prop('checked', false);
        $('.hipdata-group-cb[value="UCS"]').prop('checked', true);
        return;
    }
    if (mode === 'all') {
        $('.hipdata-group-cb').prop('checked', true);
        return;
    }
    $('.hipdata-group-cb').prop('checked', false);
}

function itemDetailLink(code, name) {
    return '<a href="#" class="rlu-item-link text-decoration-none fw-bold"'
        + ' data-item-code="' + esc(code || '') + '"'
        + ' data-item-name="' + esc(name || '') + '">'
        + esc(name || '—') + '</a>';
}

function getFilteredDetailTableRows() {
    const q = ($('#detailTableSearch').val() || '').trim().toLowerCase();
    if (!q) return detailTableRows;
    return detailTableRows.filter(r => {
        const name = (r.item_name || r.group_name || '').toLowerCase();
        const code = (r.item_code || r.group_code || '').toLowerCase();
        return name.indexOf(q) !== -1 || code.indexOf(q) !== -1;
    });
}

function renderDetailRows(rows) {
    if (!rows || !rows.length) {
        if (detailTableRows.length && ($('#detailTableSearch').val() || '').trim()) {
            return '<tr><td colspan="5" class="text-center py-4 text-muted">ไม่พบรายการที่ค้นหา</td></tr>';
        }
        return '<tr><td colspan="5" class="text-center py-4 text-muted">ไม่มีข้อมูล</td></tr>';
    }
    return rows.map(r => {
        const name = r.item_name || r.group_name || '—';
        const code = r.item_code || r.group_code || '';
        return '<tr>'
            + '<td class="text-center"><span class="badge bg-secondary rlu-year-pill">' + esc(r.year) + '</span></td>'
            + '<td>' + (code ? itemDetailLink(code, name) : esc(name)) + '</td>'
            + '<td class="text-center"><code>' + esc(code || '—') + '</code></td>'
            + '<td class="text-center">' + esc(r.item_count_display) + '</td>'
            + '<td class="text-end pe-3 text-success fw-bold">' + esc(r.total_price_display) + '</td>'
            + '</tr>';
    }).join('');
}

function detailSortTh(key, label, extraClass) {
    const active = detailTableSort.key === key;
    const arrow = active ? (detailTableSort.dir < 0 ? ' ▼' : ' ▲') : ' ⇅';
    const cls = 'rlu-sort-th' + (active ? ' active' : '') + (extraClass ? ' ' + extraClass : '');
    return '<th class="' + cls + '" data-sort-key="' + esc(key) + '">' + esc(label)
        + '<span class="sort-arrow">' + arrow + '</span></th>';
}

function buildDetailHead() {
    return '<tr>'
        + detailSortTh('year', 'ปี')
        + detailSortTh('item_name', 'รายการ', 'text-start')
        + detailSortTh('item_code', 'รหัส')
        + detailSortTh('item_count', 'จำนวน')
        + detailSortTh('total_price', 'ราคา (บาท)')
        + '</tr>';
}

function getDetailSortValue(row, key) {
    if (key === 'item_name') return (row.item_name || row.group_name || '').toLowerCase();
    if (key === 'item_code') return (row.item_code || row.group_code || '').toLowerCase();
    return Number(row[key]) || 0;
}

function sortDetailTableRows(rows) {
    const key = detailTableSort.key;
    const dir = detailTableSort.dir;
    return (rows || []).slice().sort((a, b) => {
        if (key === 'item_name' || key === 'item_code') {
            const va = getDetailSortValue(a, key);
            const vb = getDetailSortValue(b, key);
            return dir * va.localeCompare(vb, 'th');
        }
        return dir * (getDetailSortValue(a, key) - getDetailSortValue(b, key));
    });
}

function setDetailTableRows(rows) {
    detailTableRows = (rows || []).slice();
    $('#detailTableSearch').val('');
}

function renderDetailTable() {
    $('#detailThead').html(buildDetailHead());
    $('#detailTbody').html(renderDetailRows(sortDetailTableRows(getFilteredDetailTableRows())));
    $('#btnExportDetailExcel').prop('disabled', !detailTableRows.length);
}

function onDetailTableSort(key) {
    if (!key) return;
    if (detailTableSort.key === key) {
        detailTableSort.dir = -detailTableSort.dir;
    } else {
        detailTableSort.key = key;
        detailTableSort.dir = (key === 'item_name' || key === 'item_code') ? 1 : -1;
    }
    renderDetailTable();
}

function getDetailExportFilename() {
    const start = ($('#filterStart').val() || '').replace(/[\\/:*?"<>|]/g, '_');
    const end = ($('#filterEnd').val() || '').replace(/[\\/:*?"<>|]/g, '_');
    return 'income_report_' + start + '_' + end + '.xlsx';
}

function exportDetailExcel() {
    if (!detailTableRows.length) {
        alert('ไม่มีข้อมูลสำหรับส่งออก');
        return;
    }
    if (typeof XLSX === 'undefined') {
        alert('ไม่สามารถโหลดไลบรารี Excel ได้');
        return;
    }
    const sorted = sortDetailTableRows(getFilteredDetailTableRows());
    const headers = ['ปี', 'รายการ', 'รหัส', 'จำนวน', 'ราคา (บาท)'];
    const data = sorted.map(r => [
        r.year || '',
        r.item_name || r.group_name || '',
        r.item_code || r.group_code || '',
        Number(r.item_count) || 0,
        Number(r.total_price) || 0,
    ]);
    const ws = XLSX.utils.aoa_to_sheet([headers].concat(data));
    ws['!cols'] = [{ wch: 8 }, { wch: 36 }, { wch: 14 }, { wch: 12 }, { wch: 16 }];
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'รายการ');
    XLSX.writeFile(wb, getDetailExportFilename());
}

function renderCompareRows(rows) {
    if (!rows || !rows.length) return '<tr><td colspan="9" class="text-center py-3 text-muted">ไม่มีข้อมูลเปรียบเทียบ</td></tr>';
    return rows.map(r => {
        const clsCnt = r.diff_item_count > 0 ? 'rlu-compare-up' : (r.diff_item_count < 0 ? 'rlu-compare-down' : '');
        const clsPrice = r.diff_total_price > 0 ? 'rlu-compare-up' : (r.diff_total_price < 0 ? 'rlu-compare-down' : '');
        return '<tr>'
            + '<td>' + compareRowNameCell(r) + '</td>'
            + '<td class="text-center">' + esc(r.a_item_count_display) + '</td>'
            + '<td class="text-center">' + esc(r.b_item_count_display) + '</td>'
            + '<td class="text-center fw-bold ' + clsCnt + '">' + esc(r.diff_item_count_display) + '</td>'
            + '<td class="text-center ' + clsCnt + '">' + esc(r.pct_item_count) + '</td>'
            + '<td class="text-center">' + esc(r.a_total_price_display) + '</td>'
            + '<td class="text-center">' + esc(r.b_total_price_display) + '</td>'
            + '<td class="text-center fw-bold ' + clsPrice + '">' + esc(r.diff_total_price_display) + '</td>'
            + '<td class="text-center ' + clsPrice + '">' + esc(r.pct_total_price) + '</td>'
            + '</tr>';
    }).join('');
}

function parsePctSort(s) {
    if (s == null || s === '') return 0;
    return parseFloat(String(s).replace(/[%,+]/g, '')) || 0;
}

function compareSortTh(key, label, extraClass) {
    const active = compareTableSort.key === key;
    const arrow = active ? (compareTableSort.dir < 0 ? ' ▼' : ' ▲') : ' ⇅';
    const cls = 'rlu-sort-th' + (active ? ' active' : '') + (extraClass ? ' ' + extraClass : '');
    return '<th class="' + cls + '" data-sort-key="' + esc(key) + '">' + esc(label)
        + '<span class="sort-arrow">' + arrow + '</span></th>';
}

function getCompareSortValue(row, key) {
    if (key === 'name') return (row.group_name || row.row_name || '').toLowerCase();
    if (key === 'pct_item_count') return parsePctSort(row.pct_item_count);
    if (key === 'pct_total_price') return parsePctSort(row.pct_total_price);
    const m = key.match(/^p(\d+)_(cnt|diff|price|pct|diff_price|pct_price)$/);
    if (m) {
        const pi = parseInt(m[1], 10);
        const fld = m[2];
        const p = (row.periods || [])[pi];
        if (!p) return 0;
        if (fld === 'cnt') return Number(p.item_count) || 0;
        if (fld === 'diff') return Number(p.diff_item_count) || 0;
        if (fld === 'price') return Number(p.total_price) || 0;
        if (fld === 'pct') return parsePctSort(p.pct_item_count);
        if (fld === 'diff_price') return Number(p.diff_total_price) || 0;
        if (fld === 'pct_price') return parsePctSort(p.pct_total_price);
    }
    return Number(row[key]) || 0;
}

function sortCompareTableRows(rows) {
    const key = compareTableSort.key;
    const dir = compareTableSort.dir;
    return (rows || []).slice().sort((a, b) => {
        if (key === 'name') {
            const va = getCompareSortValue(a, key);
            const vb = getCompareSortValue(b, key);
            return dir * va.localeCompare(vb, 'th');
        }
        return dir * (getCompareSortValue(a, key) - getCompareSortValue(b, key));
    });
}

function onCompareTableSort(key) {
    if (!key) return;
    if (compareTableSort.key === key) {
        compareTableSort.dir = -compareTableSort.dir;
    } else {
        compareTableSort.key = key;
        compareTableSort.dir = (key === 'name') ? 1 : -1;
    }
    renderComparisonView(getActiveComparison());
}

function buildMultiYearCompareHead(comp, periods, firstColLabel) {
    const colLabel = firstColLabel || 'รายการ';
    const baseLabel = (comp && comp.peer_compare) ? (comp.label_a || 'ฐานอ้างอิง') : 'ช่วงหลัก';
    let html = '<tr>' + compareSortTh('name', colLabel, 'text-start');
    html += compareSortTh('a_item_count', baseLabel + ' จำนวน');
    (periods || []).forEach((p, i) => {
        const pl = comparePeriodLabel(comp || {}, p);
        html += compareSortTh('p' + i + '_cnt', pl + ' จำนวน');
        html += compareSortTh('p' + i + '_diff', 'ต่าง');
        html += compareSortTh('p' + i + '_pct', '%');
    });
    html += compareSortTh('a_total_price', baseLabel + ' ราคา');
    (periods || []).forEach((p, i) => {
        const pl = comparePeriodLabel(comp || {}, p);
        html += compareSortTh('p' + i + '_price', pl + ' ราคา');
        html += compareSortTh('p' + i + '_diff_price', 'ต่างราคา');
        html += compareSortTh('p' + i + '_pct_price', '%');
    });
    html += '</tr>';
    return html;
}

function renderMultiYearCompareRows(rows, periods) {
    const pCount = (periods || []).length;
    const cols = 1 + 1 + (pCount * 3) + 1 + (pCount * 3);
    if (!rows || !rows.length) {
        return '<tr><td colspan="' + cols + '" class="text-center py-3 text-muted">ไม่มีข้อมูลเปรียบเทียบ</td></tr>';
    }
    return rows.map(r => {
        let tr = '<tr><td>' + compareRowNameCell(r) + '</td>'
            + '<td class="text-center">' + esc(r.a_item_count_display) + '</td>';
        (r.periods || []).forEach(p => {
            const clsCnt = p.diff_item_count > 0 ? 'rlu-compare-up' : (p.diff_item_count < 0 ? 'rlu-compare-down' : '');
            tr += '<td class="text-center">' + esc(p.item_count_display) + '</td>'
                + '<td class="text-center fw-bold ' + clsCnt + '">' + esc(p.diff_item_count_display) + '</td>'
                + '<td class="text-center ' + clsCnt + '">' + esc(p.pct_item_count) + '</td>';
        });
        tr += '<td class="text-center">' + esc(r.a_total_price_display) + '</td>';
        (r.periods || []).forEach(p => {
            const clsPrice = p.diff_total_price > 0 ? 'rlu-compare-up' : (p.diff_total_price < 0 ? 'rlu-compare-down' : '');
            tr += '<td class="text-center">' + esc(p.total_price_display) + '</td>'
                + '<td class="text-center fw-bold ' + clsPrice + '">' + esc(p.diff_total_price_display) + '</td>'
                + '<td class="text-center ' + clsPrice + '">' + esc(p.pct_total_price) + '</td>';
        });
        tr += '</tr>';
        return tr;
    }).join('');
}

function renderMultiYearSummary(comp) {
    const periods = (comp.summary && comp.summary.periods) ? comp.summary.periods : [];
    const sumA = comp.summary || {};
    const totalCols = 1 + periods.length;
    const col = totalCols >= 4 ? 'col-md-3' : (totalCols === 3 ? 'col-md-4' : (totalCols === 2 ? 'col-md-6' : 'col-md-12'));
    const prefix = comp.compare_granularity === 'month' ? 'เดือน ' : 'ปี ';
    let html = '<div class="' + col + '"><div class="card rlu-stat-card h-100 border-primary"><div class="card-body">'
        + '<div class="text-muted small">ช่วงอ้างอิง (ช่วงหลัก)</div>'
        + '<div class="stat-value text-dark">' + esc(sumA.a_total_items_display || '0')
        + ' <span class="text-success">/ ' + esc(sumA.a_total_price_display || '0') + '</span></div>'
        + '<small class="text-muted">' + esc(comp.label_a || '') + '</small>'
        + '</div></div></div>';
    periods.forEach(p => {
        html += '<div class="' + col + '"><div class="card rlu-stat-card h-100 border-success"><div class="card-body">'
            + '<div class="text-muted small">' + prefix + esc(comparePeriodLabel(comp, p)) + ' — จำนวน / ราคา</div>'
            + '<div class="stat-value text-dark">' + esc(p.total_items_display) + ' <span class="text-success">/ ' + esc(p.total_price_display) + '</span></div>'
            + '<small class="text-muted">ต่างจำนวน ' + esc(p.diff_items_display) + ' (' + esc(p.pct_items) + ')'
            + ' | ต่างราคา ' + esc(p.diff_price_display) + ' (' + esc(p.pct_price) + ')</small>'
            + '</div></div></div>';
    });
    $('#compareMultiSummary').removeClass('d-none').html(html);
}

function renderPeerSummary(comp) {
    const all = comp.periods_all || [];
    const sumA = comp.summary || {};
    const others = sumA.periods || [];
    const prefix = comp.compare_granularity === 'month' ? 'เดือน ' : 'ปี ';
    const col = all.length >= 4 ? 'col-md-3' : (all.length === 3 ? 'col-md-4' : (all.length === 2 ? 'col-md-6' : 'col-md-12'));
    let html = '';
    all.forEach((p, idx) => {
        if (idx === 0) {
            html += '<div class="' + col + '"><div class="card rlu-stat-card h-100 border-primary"><div class="card-body">'
                + '<div class="text-muted small">ฐานอ้างอิง (' + prefix.trim() + 'ที่ 1)</div>'
                + '<div class="stat-value text-dark">' + esc(sumA.a_total_items_display || '0')
                + ' <span class="text-success">/ ' + esc(sumA.a_total_price_display || '0') + '</span></div>'
                + '<small class="text-muted">' + esc(p.label || comparePeriodLabel(comp, p)) + '</small>'
                + '</div></div></div>';
        } else {
            const o = others[idx - 1] || {};
            html += '<div class="' + col + '"><div class="card rlu-stat-card h-100 border-success"><div class="card-body">'
                + '<div class="text-muted small">' + prefix + esc(comparePeriodLabel(comp, p)) + ' — จำนวน / ราคา</div>'
                + '<div class="stat-value text-dark">' + esc(o.total_items_display || '0') + ' <span class="text-success">/ ' + esc(o.total_price_display || '0') + '</span></div>'
                + '<small class="text-muted">ต่างจำนวน ' + esc(o.diff_items_display || '0') + ' (' + esc(o.pct_items || '0%') + ')'
                + ' | ต่างราคา ' + esc(o.diff_price_display || '0') + ' (' + esc(o.pct_price || '0%') + ')</small>'
                + '<small class="text-muted d-block mt-1">' + esc(p.label || '') + '</small>'
                + '</div></div></div>';
        }
    });
    $('#compareMultiSummary').removeClass('d-none').html(html);
}

function renderSingleCompareSummary(comp) {
    const cs = comp.summary || {};
    const col = 'col-md-6';
    let html = '<div class="' + col + '"><div class="card rlu-stat-card h-100 border-primary"><div class="card-body">'
        + '<div class="text-muted small">ช่วงอ้างอิง (ช่วงหลัก)</div>'
        + '<div class="stat-value text-dark">' + esc(cs.a_total_items_display || '0')
        + ' <span class="text-success">/ ' + esc(cs.a_total_price_display || '0') + '</span></div>'
        + '<small class="text-muted">' + esc(comp.label_a || '') + '</small>'
        + '</div></div></div>';
    html += '<div class="' + col + '"><div class="card rlu-stat-card h-100 border-success"><div class="card-body">'
        + '<div class="text-muted small">ช่วงเปรียบเทียบ</div>'
        + '<div class="stat-value text-dark">' + esc(cs.b_total_items_display || '0')
        + ' <span class="text-success">/ ' + esc(cs.b_total_price_display || '0') + '</span></div>'
        + '<small class="text-muted">ต่างจำนวน ' + esc(cs.diff_items_display || '0') + ' (' + esc(cs.pct_items || '0%') + ')'
        + ' | ต่างราคา ' + esc(cs.diff_price_display || '0') + ' (' + esc(cs.pct_price || '0%') + ')</small>'
        + '<small class="text-muted d-block mt-1">' + esc(comp.label_b || '') + '</small>'
        + '</div></div></div>';
    $('#compareMultiSummary').removeClass('d-none').html(html);
}

function setReportViewMode(isCompare) {
    $('.period-section').toggleClass('d-none', isCompare);
    $('.compare-only-section').toggleClass('d-none', !isCompare);
    if (!isCompare) {
        $('#compareMultiSummary').addClass('d-none').empty();
        cachedGroupComparison = null;
    }
}

function getCompareDimConfig(dim) {
    return COMPARE_DIM_CONFIG[dim] || COMPARE_DIM_CONFIG.item;
}

function getCompareDoctorAggMode() {
    return ($('input[name="compareDoctorAgg"]:checked').val() || 'total');
}

function doctorDimensionCacheKey(dim) {
    if (dim !== 'doctor') return dim;
    return 'doctor_' + getCompareDoctorAggMode();
}

function doctorAggLabel(aggMode) {
    if (aggMode === 'vn_only') return 'เฉพาะ VN';
    if (aggMode === 'an_only') return 'เฉพาะ AN';
    return 'รวม';
}

function transformCompareDoctorRow(r, aggMode) {
    if (aggMode === 'total') return r;
    const baseRaw = {
        item_count: r.a_item_count,
        total_price: r.a_total_price,
        item_count_vn: r.a_item_count_vn,
        total_price_vn: r.a_total_price_vn,
        item_count_an: r.a_item_count_an,
        total_price_an: r.a_total_price_an,
        visit_count: r.a_visit_count,
        an_count: r.a_an_count,
    };
    const base = periodAggValues(baseRaw, aggMode);
    const periods = (r.periods || []).map((p, idx) => {
        const pRaw = {
            item_count: p.item_count,
            total_price: p.total_price,
            item_count_vn: p.item_count_vn,
            total_price_vn: p.total_price_vn,
            item_count_an: p.item_count_an,
            total_price_an: p.total_price_an,
            visit_count: p.visit_count,
            an_count: p.an_count,
        };
        const pv = periodAggValues(pRaw, aggMode);
        const diffCnt = pv.count - base.count;
        const diffPrice = pv.price - base.price;
        return Object.assign({}, p, {
            item_count: pv.count,
            total_price: pv.price,
            item_count_display: formatDetailValue(pv.count, false, aggMode),
            total_price_display: formatDetailValue(pv.price, true, aggMode),
            diff_item_count: diffCnt,
            diff_total_price: diffPrice,
            diff_item_count_display: formatDetailDiff(diffCnt, false, aggMode),
            diff_total_price_display: formatDetailDiff(diffPrice, true, aggMode),
            pct_item_count: formatDetailPct(base.count, pv.count),
            pct_total_price: formatDetailPct(base.price, pv.price),
        });
    });
    const out = Object.assign({}, r, {
        a_item_count: base.count,
        a_total_price: base.price,
        a_item_count_display: formatDetailValue(base.count, false, aggMode),
        a_total_price_display: formatDetailValue(base.price, true, aggMode),
        periods: periods,
    });
    if (periods.length === 1) {
        const p = periods[0];
        out.b_item_count = p.item_count;
        out.b_total_price = p.total_price;
        out.diff_item_count = p.diff_item_count;
        out.diff_total_price = p.diff_total_price;
        out.b_item_count_display = p.item_count_display;
        out.b_total_price_display = p.total_price_display;
        out.diff_item_count_display = p.diff_item_count_display;
        out.diff_total_price_display = p.diff_total_price_display;
        out.pct_item_count = p.pct_item_count;
        out.pct_total_price = p.pct_total_price;
    }
    return out;
}

function transformCompareDoctorComp(comp, aggMode) {
    if (!comp || aggMode === 'total' || currentCompareDimension !== 'doctor') return comp;
    const rows = (comp.rows || []).map(r => transformCompareDoctorRow(r, aggMode));
    rows.sort((a, b) => (b.a_item_count || 0) - (a.a_item_count || 0));
    return Object.assign({}, comp, { rows: rows });
}

function getActiveComparison() {
    return dimensionComparisonCache['item:' + getVisitScopeMode()] || null;
}

function compareRowNameCell(r) {
    const dim = currentCompareDimension;
    const name = r.group_name || r.row_name || '—';
    const code = r.group_code || r.row_code || '';
    if (dim === 'group') {
        return groupLink(code, name) + ' <small class="text-muted">(' + esc(code || '—') + ')</small>';
    }
    if (dim === 'doctor') {
        return doctorLink(code || '__none__', name);
    }
    return esc(name) + ' <small class="text-muted">(' + esc(code || '—') + ')</small>';
}

function compareDimColumnLabel() {
    const cfg = getCompareDimConfig(currentCompareDimension);
    const agg = currentCompareDimension === 'doctor' ? getCompareDoctorAggMode() : 'total';
    if (agg === 'vn_only') return cfg.label + ' (เฉพาะ VN)';
    if (agg === 'an_only') return cfg.label + ' (เฉพาะ AN)';
    return cfg.label;
}

function updateCompareDimensionUI() {
    currentCompareDimension = 'item';
    const cfg = getCompareDimConfig('item');
    $('#compareDimensionHint').text(cfg.hint);
    $('#compareChartTitle').html('<i class="fas fa-chart-column me-1" style="color:#0d9488;"></i> ' + esc(cfg.chart));
    $('#compareChartFilterLabel').text(cfg.filter);
    $('#compareTableTitle').text(cfg.table);
    $('#compareTableHint').text(cfg.tableHint);
}

function renderComparisonView(comp) {
    if (!comp) return;
    currentComparison = comp;
    const sortedRows = sortCompareTableRows(comp.rows || []);
    const compView = Object.assign({}, comp, { rows: sortedRows });
    if (comp.multi_year) {
        if (comp.peer_compare) {
            renderPeerSummary(comp);
        } else {
            renderMultiYearSummary(comp);
        }
        const periods = comp.periods_b || [];
        $('#compareThead').html(buildMultiYearCompareHead(comp, periods, compareDimColumnLabel()));
        $('#compareTbody').html(renderMultiYearCompareRows(compView.rows || [], periods));
        const labelParts = (comp.periods_all || comp.periods_b || []).map(p => comparePeriodLabel(comp, p));
        $('#compareLabels').text(labelParts.join(' vs '));
    } else {
        renderSingleCompareSummary(comp);
        $('#compareThead').html('<tr>'
            + compareSortTh('name', compareDimColumnLabel(), 'text-start')
            + compareSortTh('a_item_count', 'ช่วง A จำนวน')
            + compareSortTh('b_item_count', 'ช่วง B จำนวน')
            + compareSortTh('diff_item_count', 'ต่าง')
            + compareSortTh('pct_item_count', '%')
            + compareSortTh('a_total_price', 'ช่วง A ราคา')
            + compareSortTh('b_total_price', 'ช่วง B ราคา')
            + compareSortTh('diff_total_price', 'ต่างราคา')
            + compareSortTh('pct_total_price', '%')
            + '</tr>');
        $('#compareLabels').text((comp.label_a || 'A') + ' vs ' + (comp.label_b || 'B'));
        $('#compareTbody').html(renderCompareRows(compView.rows || []));
    }
    setupCompareCharts(comp);
}

function getVisitScopeMode() {
    return $('input[name="filterVisitScope"]:checked').val() || 'total';
}

function visitScopeLabel(mode) {
    mode = mode || getVisitScopeMode();
    if (mode === 'vn_only') return 'เฉพาะ VN';
    if (mode === 'an_only') return 'เฉพาะ AN';
    return '';
}

async function loadDimensionComparison(dim) {
    dim = 'item';
    const cacheKey = 'item:' + getVisitScopeMode();
    if (dimensionComparisonCache[cacheKey]) {
        return dimensionComparisonCache[cacheKey];
    }
    const params = Object.assign({
        action: 'compare_dimension',
        dimension: 'item',
    }, getReportParams());
    const data = await fetchJson(RLR_API + '?' + qs(params));
    if (data.status === 'need_build') {
        await buildMissingMonths(data.missing_months || [], getReportParams(), data.build_scope || dim);
        const retry = await fetchJson(RLR_API + '?' + qs(params));
        if (retry.status !== 'success') {
            throw new Error(retry.message || 'โหลดข้อมูล ' + dim + ' ไม่สำเร็จ');
        }
        dimensionComparisonCache[cacheKey] = retry.comparison || null;
        return dimensionComparisonCache[cacheKey];
    }
    if (data.status !== 'success') {
        throw new Error(data.message || 'โหลดข้อมูล ' + dim + ' ไม่สำเร็จ');
    }
    dimensionComparisonCache[cacheKey] = data.comparison || null;
    return dimensionComparisonCache[cacheKey];
}

function resetSingleCompareHead() {
    $('#compareThead').html('<tr>'
        + '<th class="text-start">รายการ</th>'
        + '<th>ช่วง A จำนวน</th><th>ช่วง B จำนวน</th><th>ต่าง</th><th>%</th>'
        + '<th>ช่วง A ราคา</th><th>ช่วง B ราคา</th><th>ต่างราคา</th><th>%</th>'
        + '</tr>');
}

function groupChartKey(r) {
    const code = r.row_code || r.group_code || r.item_code || '';
    const name = r.row_name || r.group_name || r.item_name || '';
    if (code) return String(code);
    return '|' + name;
}

function chartRowValues(r) {
    if (r.b_item_count !== undefined || r.b_total_price !== undefined) {
        return [
            { count: Number(r.a_item_count) || 0, price: Number(r.a_total_price) || 0 },
            { count: Number(r.b_item_count) || 0, price: Number(r.b_total_price) || 0 },
        ];
    }
    const vals = [{ count: Number(r.a_item_count) || 0, price: Number(r.a_total_price) || 0 }];
    (r.periods || []).forEach(p => {
        vals.push({
            count: Number(p.item_count) || 0,
            price: Number(p.total_price) || 0,
        });
    });
    return vals;
}

function chartPeriodLabels(comp) {
    if (comp.periods_all && comp.periods_all.length >= 2) {
        return comp.periods_all.map(p => ({ label: p.label || comparePeriodLabel(comp, p) || 'ช่วง' }));
    }
    return [
        { label: comp.label_a || 'ช่วง A' },
        { label: comp.label_b || 'ช่วง B' },
    ];
}

function isChartTwoPeriod(comp) {
    if (!comp) return false;
    if (!comp.multi_year) return true;
    if (comp.periods_all && comp.periods_all.length === 2) return true;
    return false;
}

function isSinglePeriodComp(comp) {
    if (!comp || comp.multi_year || comp.peer_compare) return false;
    if (comp.label_b) return false;
    if (comp.periods_b && comp.periods_b.length) return false;
    return true;
}

function normalizeCompareChartData(comp) {
    if (!comp || !comp.rows || !comp.rows.length) return null;

    const mapRow = r => ({
        key: groupChartKey(r),
        code: r.row_code || r.group_code || r.item_code || '',
        name: r.row_name || r.group_name || r.item_name || '',
    });

    if (isSinglePeriodComp(comp)) {
        const periods = [{ label: comp.label_a || 'ช่วงที่เลือก' }];
        const groups = comp.rows.map(r => Object.assign(mapRow(r), {
            values: [{ count: Number(r.a_item_count) || 0, price: Number(r.a_total_price) || 0 }],
        }));
        return { periods, groups };
    }

    if (isChartTwoPeriod(comp)) {
        const periods = chartPeriodLabels(comp).slice(0, 2);
        const groups = comp.rows.map(r => {
            let vals = chartRowValues(r);
            while (vals.length < periods.length) {
                vals.push({ count: 0, price: 0 });
            }
            return Object.assign(mapRow(r), { values: vals.slice(0, periods.length) });
        });
        return { periods, groups };
    }

    const periods = [];
    let groups = [];
    if (comp.multi_year) {
        if (comp.peer_compare && comp.periods_all) {
            periods.push(...comp.periods_all.map(p => ({
                label: p.label || comparePeriodLabel(comp, p) || 'ช่วง',
            })));
            groups = comp.rows.map(r => Object.assign(mapRow(r), {
                values: [
                    { count: Number(r.a_item_count) || 0, price: Number(r.a_total_price) || 0 },
                    ...(r.periods || []).map(p => ({
                        count: Number(p.item_count) || 0,
                        price: Number(p.total_price) || 0,
                    })),
                ],
            }));
            return { periods, groups };
        }
        periods.push({ label: comp.label_a || 'ช่วงหลัก' });
        (comp.periods_b || []).forEach(p => periods.push({ label: p.label || comparePeriodLabel(comp, p) || 'ช่วง' }));
        groups = comp.rows.map(r => Object.assign(mapRow(r), {
            values: [
                { count: Number(r.a_item_count) || 0, price: Number(r.a_total_price) || 0 },
                ...(r.periods || []).map(p => ({
                    count: Number(p.item_count) || 0,
                    price: Number(p.total_price) || 0,
                })),
            ],
        }));
    } else {
        periods.push({ label: comp.label_a || 'ช่วงหลัก' });
        periods.push({ label: comp.label_b || 'ช่วงเปรียบเทียบ' });
        groups = comp.rows.map(r => Object.assign(mapRow(r), {
            values: [
                { count: Number(r.a_item_count) || 0, price: Number(r.a_total_price) || 0 },
                { count: Number(r.b_item_count) || 0, price: Number(r.b_total_price) || 0 },
            ],
        }));
    }
    return { periods, groups };
}

function chartKeyAttr(key) {
    return String(key)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function chartGroupMetricVal(g, isPrice) {
    const vals = g.values || [];
    if (isPrice) {
        return Math.max(0, ...vals.map(v => Number(v.price) || 0));
    }
    return Math.max(0, ...vals.map(v => Number(v.count) || 0));
}

function filterChartGroupList() {
    const q = ($('#chartGroupSearch').val() || '').trim().toLowerCase();
    $('#chartGroupFilterList .chart-filter-row').each(function() {
        const hay = ($(this).attr('data-search') || '').toLowerCase();
        $(this).toggle(!q || hay.indexOf(q) !== -1);
    });
}

function buildChartGroupFilters(chartData) {
    const isPrice = ($('input[name="chartMetric"]:checked').val() || 'count') === 'price';
    const metricVal = g => chartGroupMetricVal(g, isPrice);
    const sorted = chartData.groups.slice().sort((a, b) => metricVal(b) - metricVal(a));
    let html = '';
    sorted.forEach((g, i) => {
        const label = g.name + (g.code ? ' (' + g.code + ')' : '');
        const searchText = ((g.name || '') + ' ' + (g.code || '')).toLowerCase();
        const checked = i < 8 ? ' checked' : '';
        html += '<div class="form-check chart-filter-row" data-search="' + esc(searchText) + '">'
            + '<input class="form-check-input chart-group-cb" type="checkbox" data-chart-key="' + chartKeyAttr(g.key) + '" id="cg_' + i + '"' + checked + ' onchange="refreshCompareChart()">'
            + '<label class="form-check-label" for="cg_' + i + '">' + esc(label) + '</label>'
            + '</div>';
    });
    $('#chartGroupFilterPlaceholder').addClass('d-none');
    $('#chartGroupSearch').val('');
    $('#chartGroupFilterList').html(html);
    filterChartGroupList();
}

function getSelectedChartGroupKeys() {
    const keys = [];
    $('.chart-group-cb:checked').each(function() {
        keys.push($(this).attr('data-chart-key') || '');
    });
    return keys;
}

function selectChartGroups(mode) {
    const topN = { top8: 8, top15: 15, top30: 30 };
    if (topN[mode]) {
        const comp = getActiveComparison();
        const chartData = comp ? normalizeCompareChartData(comp) : null;
        if (chartData) {
            buildChartGroupFilters(chartData);
        }
        const $cbs = $('.chart-group-cb');
        $cbs.prop('checked', false);
        $cbs.slice(0, topN[mode]).prop('checked', true);
    } else {
        const $cbs = $('.chart-group-cb');
        if (mode === 'all') {
            $cbs.prop('checked', true);
        } else if (mode === 'none') {
            $cbs.prop('checked', false);
        }
    }
    refreshCompareChart();
}

function formatChartValue(val, isPrice) {
    const n = Number(val) || 0;
    if (isPrice) {
        return n.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    return n.toLocaleString('th-TH');
}

function onCompareChartMetricChange() {
    const comp = getActiveComparison();
    if (!comp) return;
    const chartData = normalizeCompareChartData(comp);
    if (!chartData) return;
    const selected = getSelectedChartGroupKeys();
    buildChartGroupFilters(chartData);
    $('.chart-group-cb').each(function() {
        const k = $(this).attr('data-chart-key') || '';
        $(this).prop('checked', selected.indexOf(k) !== -1);
    });
    refreshCompareChart();
}

function refreshCompareChart() {
    const comp = getActiveComparison();
    if (!comp || typeof Chart === 'undefined') return;
    currentComparison = comp;
    const chartData = normalizeCompareChartData(comp);
    if (!chartData) return;

    const isPrice = ($('input[name="chartMetric"]:checked').val() || 'count') === 'price';
    const selected = getSelectedChartGroupKeys();
    const groups = chartData.groups
        .filter(g => selected.indexOf(g.key) !== -1)
        .sort((a, b) => chartGroupMetricVal(b, isPrice) - chartGroupMetricVal(a, isPrice));
    compareChartGroups = groups.slice();

    const labels = groups.map(g => {
        const n = g.name || '—';
        return n.length > 20 ? n.substring(0, 18) + '…' : n;
    });

    const datasets = chartData.periods.map((p, pi) => ({
        label: p.label || ('ช่วง ' + (pi + 1)),
        data: groups.map(g => {
            const v = (g.values && g.values[pi]) ? g.values[pi] : { count: 0, price: 0 };
            return isPrice ? (Number(v.price) || 0) : (Number(v.count) || 0);
        }),
        backgroundColor: CHART_COLORS[pi % CHART_COLORS.length],
        borderRadius: 4,
        maxBarThickness: 48,
        barPercentage: 0.9,
        categoryPercentage: 0.8,
    }));

    const ctx = document.getElementById('compareGroupChart');
    if (!ctx) return;
    if (compareGroupChart) compareGroupChart.destroy();
    compareGroupChart = new Chart(ctx.getContext('2d'), {
        type: 'bar',
        data: { labels, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            onClick: onCompareGroupChartClick,
            onHover: function(evt, elements) {
                const clickable = (currentCompareDimension === 'item' || currentCompareDimension === 'doctor')
                    && elements && elements.length > 0;
                evt.native.target.style.cursor = clickable ? 'pointer' : 'default';
            },
            plugins: {
                legend: { position: 'top' },
                tooltip: {
                    callbacks: {
                        label: function(item) {
                            return item.dataset.label + ': ' + formatChartValue(item.raw, isPrice);
                        },
                    },
                },
            },
            scales: {
                x: { ticks: { font: { size: 11 } } },
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: v => formatChartValue(v, isPrice),
                    },
                },
            },
        },
    });

    const totals = chartData.periods.map((p, pi) => groups.reduce((sum, g) => {
        const v = g.values[pi] || { count: 0, price: 0 };
        return sum + (isPrice ? (Number(v.price) || 0) : (Number(v.count) || 0));
    }, 0));

    const ctx2 = document.getElementById('compareSummaryChart');
    if (!ctx2) return;
    if (compareSummaryChart) compareSummaryChart.destroy();
    compareSummaryChart = new Chart(ctx2.getContext('2d'), {
        type: 'bar',
        data: {
            labels: chartData.periods.map(p => p.label || ''),
            datasets: [{
                label: isPrice ? 'ราคารวม (บาท)' : 'จำนวนรายการ',
                data: totals,
                backgroundColor: chartData.periods.map((_, i) => CHART_COLORS[i % CHART_COLORS.length]),
                borderRadius: 6,
                maxBarThickness: 72,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(item) {
                            return formatChartValue(item.raw, isPrice);
                        },
                    },
                },
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: v => formatChartValue(v, isPrice),
                    },
                },
            },
        },
    });
}

function setupCompareCharts(comp) {
    currentComparison = comp || null;
    if (!comp) {
        $('.chart-section').addClass('d-none');
        if (compareGroupChart) { compareGroupChart.destroy(); compareGroupChart = null; }
        if (compareSummaryChart) { compareSummaryChart.destroy(); compareSummaryChart = null; }
        return;
    }
    $('.chart-section').removeClass('d-none');
    const chartData = normalizeCompareChartData(comp);
    if (!chartData) return;
    buildChartGroupFilters(chartData);
    let labelText = chartData.periods.map(p => p.label).join(' vs ');
    $('#compareChartLabels').text(labelText);
    refreshCompareChart();
}

function buildGroupDetailHead(detail, tab) {
    const cfg = DETAIL_TAB_CONFIG[tab || currentGroupDetailTab] || DETAIL_TAB_CONFIG.items;
    const periods = detail.periods || [];
    const aggMode = (tab === 'doctor' && !currentDoctorDrilldown) ? getDoctorAggMode() : 'total';
    const cntLabel = aggMode === 'vn_only' ? 'จำนวน (เฉพาะ VN)' : (aggMode === 'an_only' ? 'จำนวน (เฉพาะ AN)' : 'จำนวน');
    const priceLabel = aggMode === 'vn_only' ? 'ราคา (เฉพาะ VN)' : (aggMode === 'an_only' ? 'ราคา (เฉพาะ AN)' : 'ราคา (บาท)');
    if (!detail.multi_period || periods.length <= 1) {
        const label = periods[0] ? periods[0].label : 'ช่วงที่เลือก';
        return '<tr>'
            + groupDetailSortTh('name', cfg.label, 'text-start')
            + groupDetailSortTh('code', cfg.code)
            + groupDetailSortTh('a_item_count', label + ' ' + cntLabel)
            + groupDetailSortTh('a_total_price', label + ' ' + priceLabel)
            + '</tr>';
    }
    let html = '<tr>'
        + groupDetailSortTh('name', cfg.label, 'text-start')
        + groupDetailSortTh('code', cfg.code)
        + groupDetailSortTh('a_item_count', (periods[0].label || 'ช่วงอ้างอิง') + ' ' + cntLabel);
    for (let i = 1; i < periods.length; i++) {
        const pi = i - 1;
        html += groupDetailSortTh('p' + pi + '_cnt', periods[i].label + ' ' + cntLabel);
        html += groupDetailSortTh('p' + pi + '_diff', 'ต่าง');
        html += groupDetailSortTh('p' + pi + '_pct', '%');
    }
    html += groupDetailSortTh('a_total_price', (periods[0].label || 'ช่วงอ้างอิง') + ' ' + priceLabel);
    for (let i = 1; i < periods.length; i++) {
        const pi = i - 1;
        html += groupDetailSortTh('p' + pi + '_price', periods[i].label + ' ' + priceLabel);
        html += groupDetailSortTh('p' + pi + '_diff_price', 'ต่างราคา');
        html += groupDetailSortTh('p' + pi + '_pct_price', '%');
    }
    html += '</tr>';
    return html;
}

function groupDetailSortTh(key, label, extraClass) {
    const active = groupDetailTableSort.key === key;
    const arrow = active ? (groupDetailTableSort.dir < 0 ? ' ▼' : ' ▲') : ' ⇅';
    const cls = 'rlu-sort-th' + (active ? ' active' : '') + (extraClass ? ' ' + extraClass : '');
    return '<th class="' + cls + '" data-sort-key="' + esc(key) + '">' + esc(label)
        + '<span class="sort-arrow">' + arrow + '</span></th>';
}

function getGroupDetailSortValue(row, key) {
    if (key === 'name') return (row.row_name || row.item_name || '').toLowerCase();
    if (key === 'code') return (row.row_code || row.item_code || '').toLowerCase();
    if (key === 'a_item_count') return Number(row.a_item_count) || 0;
    if (key === 'a_total_price') return Number(row.a_total_price) || 0;
    return getCompareSortValue(row, key);
}

function sortGroupDetailTableRows(rows) {
    const key = groupDetailTableSort.key;
    const dir = groupDetailTableSort.dir;
    return (rows || []).slice().sort((a, b) => {
        if (key === 'name' || key === 'code') {
            const va = getGroupDetailSortValue(a, key);
            const vb = getGroupDetailSortValue(b, key);
            return dir * va.localeCompare(vb, 'th');
        }
        return dir * (getGroupDetailSortValue(a, key) - getGroupDetailSortValue(b, key));
    });
}

function resetGroupDetailTableSort() {
    groupDetailTableSort = { key: 'a_item_count', dir: -1 };
}

function refreshGroupDetailTable(detail) {
    detail = detail || getActiveGroupDetailData() || { rows: [], periods: [] };
    const tab = currentDoctorDrilldown ? 'items' : currentGroupDetailTab;
    $('#groupDetailThead').html(buildGroupDetailHead(detail, tab));
    $('#groupDetailTbody').html(renderGroupDetailRows(detail));
}

function onGroupDetailTableSort(key) {
    if (!key) return;
    if (groupDetailTableSort.key === key) {
        groupDetailTableSort.dir = -groupDetailTableSort.dir;
    } else {
        groupDetailTableSort.key = key;
        groupDetailTableSort.dir = (key === 'name' || key === 'code') ? 1 : -1;
    }
    refreshGroupDetailTable();
}

function getDoctorAggMode() {
    return ($('input[name="detailDoctorAgg"]:checked').val() || 'total');
}

function doctorPeriodDenom(periodData, aggMode) {
    if (aggMode === 'avg_an') return Math.max(1, periodData.an_count || 0);
    if (aggMode === 'avg_vn') return Math.max(1, periodData.visit_count || 0);
    return 1;
}

function formatDetailValue(n, isMoney, aggMode) {
    const num = Number(n) || 0;
    return isMoney
        ? num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
        : Math.round(num).toLocaleString('en-US');
}

function formatDetailDiff(n, isMoney, aggMode) {
    const sign = n >= 0 ? '+' : '-';
    return sign + formatDetailValue(Math.abs(n), isMoney, aggMode);
}

function formatDetailPct(base, current) {
    base = Number(base) || 0;
    current = Number(current) || 0;
    if (base === 0) return current > 0 ? '+100%' : '0%';
    const pct = ((current - base) / base) * 100;
    return (pct > 0 ? '+' : '') + pct.toLocaleString('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%';
}

function periodAggValues(periodData, aggMode) {
    if (!periodData) return { count: 0, price: 0 };
    if (aggMode === 'vn_only') {
        return {
            count: periodData.item_count_vn != null ? periodData.item_count_vn : 0,
            price: periodData.total_price_vn != null ? periodData.total_price_vn : 0,
        };
    }
    if (aggMode === 'an_only') {
        return {
            count: periodData.item_count_an != null ? periodData.item_count_an : 0,
            price: periodData.total_price_an != null ? periodData.total_price_an : 0,
        };
    }
    return { count: periodData.item_count || 0, price: periodData.total_price || 0 };
}

function transformDoctorDetailForAgg(detail, aggMode) {
    if (!detail || aggMode === 'total') return detail;
    const rows = (detail.rows || []).map(r => {
        const baseRaw = {
            item_count: r.a_item_count,
            total_price: r.a_total_price,
            item_count_vn: r.a_item_count_vn,
            total_price_vn: r.a_total_price_vn,
            item_count_an: r.a_item_count_an,
            total_price_an: r.a_total_price_an,
            visit_count: r.a_visit_count,
            an_count: r.a_an_count,
        };
        const base = periodAggValues(baseRaw, aggMode);
        const periods = (r.periods || []).map((p, idx) => {
            const pRaw = {
                item_count: p.item_count,
                total_price: p.total_price,
                item_count_vn: p.item_count_vn,
                total_price_vn: p.total_price_vn,
                item_count_an: p.item_count_an,
                total_price_an: p.total_price_an,
                visit_count: p.visit_count,
                an_count: p.an_count,
            };
            const pv = periodAggValues(pRaw, aggMode);
            const diffCnt = pv.count - base.count;
            const diffPrice = pv.price - base.price;
            return {
                period_index: p.period_index != null ? p.period_index : (idx + 1),
                item_count: pv.count,
                total_price: pv.price,
                item_count_display: formatDetailValue(pv.count, false, aggMode),
                total_price_display: formatDetailValue(pv.price, true, aggMode),
                diff_item_count: diffCnt,
                diff_total_price: diffPrice,
                diff_item_count_display: formatDetailDiff(diffCnt, false, aggMode),
                diff_total_price_display: formatDetailDiff(diffPrice, true, aggMode),
                pct_item_count: formatDetailPct(base.count, pv.count),
                pct_total_price: formatDetailPct(base.price, pv.price),
            };
        });
        return Object.assign({}, r, {
            a_item_count: base.count,
            a_total_price: base.price,
            a_item_count_display: formatDetailValue(base.count, false, aggMode),
            a_total_price_display: formatDetailValue(base.price, true, aggMode),
            periods: periods,
        });
    });
    rows.sort((a, b) => (b.a_item_count || 0) - (a.a_item_count || 0));
    return Object.assign({}, detail, { rows: rows, doctor_agg_mode: aggMode });
}

function doctorLink(code, name) {
    const dc = code || '__none__';
    return '<a href="#" class="rlu-doctor-link text-decoration-none fw-bold"'
        + ' data-doctor-code="' + esc(dc) + '"'
        + ' data-doctor-name="' + esc(name || '') + '">'
        + esc(name || 'ไม่ระบุแพทย์') + '</a>';
}

function renderGroupDetailRows(detail) {
    const rows = sortGroupDetailTableRows(detail.rows || []);
    const periods = detail.periods || [];
    const multi = !!detail.multi_period && periods.length > 1;
    const isDoctorList = currentGroupDetailTab === 'doctor' && !currentDoctorDrilldown;
    if (!rows.length) {
        const cols = multi ? (2 + (periods.length - 1) * 3 + 2 + (periods.length - 1) * 3) : 4;
        return '<tr><td colspan="' + cols + '" class="text-center py-4 text-muted">ไม่มีข้อมูลในช่วงนี้</td></tr>';
    }
    return rows.map(r => {
        const name = r.row_name || r.item_name || '—';
        const code = r.row_code || r.item_code || '—';
        const nameCell = isDoctorList ? doctorLink(code, name) : esc(name);
        let tr = '<tr><td class="fw-bold">' + nameCell + '</td>'
            + '<td class="text-center"><code>' + esc(code || '—') + '</code></td>';
        if (!multi) {
            tr += '<td class="text-center">' + esc(r.a_item_count_display) + '</td>'
                + '<td class="text-end pe-3 text-success fw-bold">' + esc(r.a_total_price_display) + '</td>';
        } else {
            tr += '<td class="text-center">' + esc(r.a_item_count_display) + '</td>';
            (r.periods || []).forEach(p => {
                const clsCnt = p.diff_item_count > 0 ? 'rlu-compare-up' : (p.diff_item_count < 0 ? 'rlu-compare-down' : '');
                tr += '<td class="text-center">' + esc(p.item_count_display) + '</td>'
                    + '<td class="text-center fw-bold ' + clsCnt + '">' + esc(p.diff_item_count_display) + '</td>'
                    + '<td class="text-center ' + clsCnt + '">' + esc(p.pct_item_count) + '</td>';
            });
            tr += '<td class="text-center">' + esc(r.a_total_price_display) + '</td>';
            (r.periods || []).forEach(p => {
                const clsPrice = p.diff_total_price > 0 ? 'rlu-compare-up' : (p.diff_total_price < 0 ? 'rlu-compare-down' : '');
                tr += '<td class="text-center">' + esc(p.total_price_display) + '</td>'
                    + '<td class="text-center fw-bold ' + clsPrice + '">' + esc(p.diff_total_price_display) + '</td>'
                    + '<td class="text-center ' + clsPrice + '">' + esc(p.pct_total_price) + '</td>';
            });
        }
        tr += '</tr>';
        return tr;
    }).join('');
}

function rowChartKey(r) {
    return (r.row_code || r.item_code || '') + '|' + (r.row_name || r.item_name || '');
}

function getRawGroupDetailData() {
    if (!currentGroupDetailBundle) return currentGroupDetail;
    const cfg = DETAIL_TAB_CONFIG[currentGroupDetailTab] || DETAIL_TAB_CONFIG.items;
    return currentGroupDetailBundle[cfg.key] || currentGroupDetailBundle.items || null;
}

function getActiveGroupDetailData() {
    if (currentDoctorDrilldown && currentDoctorDrilldown.detail) {
        return currentDoctorDrilldown.detail;
    }
    const raw = getRawGroupDetailData();
    if (currentGroupDetailTab === 'doctor' && raw) {
        return transformDoctorDetailForAgg(raw, getDoctorAggMode());
    }
    return raw;
}

function updateGroupDetailMeta() {
    if (!currentGroupContext) return;
    let meta = $('#groupDetailMeta').data('base-meta') || '';
    if (currentDoctorDrilldown) {
        meta += ' | <button type="button" class="btn btn-link btn-sm p-0 align-baseline" id="btnDoctorDrillBack">← กลับรายชื่อแพทย์</button>'
            + ' | แพทย์: <strong>' + esc(currentDoctorDrilldown.name || 'ไม่ระบุแพทย์') + '</strong>';
    } else if (currentGroupDetailTab === 'doctor') {
        meta += ' | <span class="text-primary">คลิกแท่งกราฟเพื่อดูแพทย์ที่สั่งมากตามลำดับ</span>';
    } else if (currentGroupDetailTab === 'dept') {
        meta += ' | <span class="text-primary">คลิกแท่งกราฟเพื่อดูแผนกที่สั่งมาก</span>';
    }
    $('#groupDetailMeta').html(meta);
}

function toggleDoctorAggControls() {
    const show = currentGroupDetailTab === 'doctor' && !currentDoctorDrilldown;
    $('#groupDetailDoctorAggWrap').toggle(show);
}

function itemDetailBaseParams() {
    const p = Object.assign({}, currentGroupContext.reportParams || getReportParams());
    if (currentGroupContext.itemCode) {
        p.item_code = currentGroupContext.itemCode;
    }
    if (currentGroupContext.itemName) {
        p.item_name = currentGroupContext.itemName;
    }
    if ($('#enableCompare').is(':checked')) {
        p.compare = '1';
    }
    return p;
}

async function loadItemDetailDimension(tab) {
    const cfg = DETAIL_TAB_CONFIG[tab];
    if (!cfg || tab === 'items' || !currentGroupContext || !currentGroupDetailBundle) {
        return;
    }
    const bundle = currentGroupDetailBundle[cfg.key];
    if (bundle && bundle._loaded) {
        return;
    }
    if (groupDetailTabFetch[tab]) {
        return groupDetailTabFetch[tab];
    }
    groupDetailTabFetch[tab] = (async () => {
        const action = tab === 'doctor' ? 'item_doctors' : 'item_depts';
        const params = Object.assign({ action: action }, itemDetailBaseParams());
        const data = await fetchJson(RLR_API + '?' + qs(params));
        if (data.status !== 'success') {
            throw new Error(data.message || 'โหลดไม่สำเร็จ');
        }
        const loaded = tab === 'doctor'
            ? (data.detail || { rows: [], periods: [] })
            : (data.departments || data.detail || { rows: [], periods: [] });
        const periods = (currentGroupDetailBundle.doctors || {}).periods
            || (currentGroupDetailBundle.departments || {}).periods
            || loaded.periods
            || [];
        loaded._loaded = true;
        if (data.elapsed_ms) {
            loaded._elapsed_ms = data.elapsed_ms;
        }
        currentGroupDetailBundle[cfg.key] = loaded;
        if (!currentGroupDetailBundle.doctors.periods || !currentGroupDetailBundle.doctors.periods.length) {
            currentGroupDetailBundle.doctors.periods = loaded.periods || periods;
        }
        if (!currentGroupDetailBundle.departments.periods || !currentGroupDetailBundle.departments.periods.length) {
            currentGroupDetailBundle.departments.periods = loaded.periods || periods;
        }
    })();
    try {
        await groupDetailTabFetch[tab];
    } finally {
        delete groupDetailTabFetch[tab];
    }
}

async function switchGroupDetailTab(tab) {
    if (!DETAIL_TAB_CONFIG[tab] || !currentGroupDetailBundle) return;
    if (tab !== 'doctor') {
        currentDoctorDrilldown = null;
    }
    resetGroupDetailTableSort();
    currentGroupDetailTab = tab;
    $('#groupDetailTabs .nav-link').removeClass('active');
    $('#groupDetailTabs .nav-link[data-detail-tab="' + tab + '"]').addClass('active');
    toggleDoctorAggControls();
    const cfg = DETAIL_TAB_CONFIG[tab];
    if (tab !== 'items' && !currentDoctorDrilldown) {
        const bundle = currentGroupDetailBundle[cfg.key];
        if (!bundle || !bundle._loaded) {
            $('#groupDetailThead').html('<tr><th class="text-start">' + esc(cfg.label) + '</th><th>' + esc(cfg.code) + '</th><th>จำนวน</th><th>ราคา</th></tr>');
            $('#groupDetailTbody').html('<tr><td colspan="4" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-1"></i> กำลังโหลด' + esc(cfg.label) + '...</td></tr>');
            $('#groupDetailChartSection').addClass('d-none');
            try {
                await loadItemDetailDimension(tab);
            } catch (e) {
                $('#groupDetailTbody').html('<tr><td colspan="4" class="text-center py-4 text-danger">' + esc(e.message || 'เกิดข้อผิดพลาด') + '</td></tr>');
                return;
            }
        }
    }
    const detail = getActiveGroupDetailData();
    let chartTitle = cfg.chart;
    if (currentDoctorDrilldown) {
        chartTitle = 'รายการ — ' + (currentDoctorDrilldown.name || 'แพทย์');
    }
    $('#groupDetailChartTitle').html('<i class="fas fa-chart-column me-1" style="color:#0d9488;"></i> ' + esc(chartTitle));
    $('#groupDetailFilterLabel').text(currentDoctorDrilldown ? 'เลือกรายการ' : cfg.filter);
    refreshGroupDetailTable(detail || { rows: [], periods: [] });
    setupGroupDetailCharts(detail);
    updateGroupDetailMeta();
    updateGroupDetailExportButton();
    const rowCount = (detail && detail.rows) ? detail.rows.length : 0;
    const elapsed = (detail && detail._elapsed_ms) || $('#groupDetailElapsed').data('elapsed') || '';
    if (elapsed) {
        $('#groupDetailElapsed').text('โหลดใช้เวลา ' + elapsed + ' ms | ' + rowCount + ' รายการ');
    }
}

function backDoctorDrilldown() {
    if (!currentGroupDetailBundle) {
        currentDoctorDrilldown = null;
        if (groupDetailModal) groupDetailModal.hide();
        return;
    }
    currentDoctorDrilldown = null;
    $('#groupDetailTabs').removeClass('d-none');
    switchGroupDetailTab('doctor');
}

function itemChartKey(r) {
    return rowChartKey(r);
}

function parseChartNum(raw, displayRaw) {
    if (typeof raw === 'number' && isFinite(raw)) return raw;
    if (raw != null && raw !== '') {
        const n = parseFloat(String(raw).replace(/,/g, '').trim());
        if (isFinite(n)) return n;
    }
    if (displayRaw != null && displayRaw !== '') {
        const n = parseFloat(String(displayRaw).replace(/,/g, '').trim());
        if (isFinite(n)) return n;
    }
    return 0;
}

function getDetailChartMetric() {
    return ($('input[name="detailChartMetric"]:checked').val() || 'count');
}

function normalizeGroupDetailChartData(detail) {
    if (!detail || !detail.rows || !detail.rows.length) return null;
    const periods = (detail.periods || []).map(p => ({ label: p.label || '' }));
    if (!periods.length) {
        periods.push({ label: 'ช่วงที่เลือก' });
    }
    const items = detail.rows.map(r => {
        const values = [{
            count: parseChartNum(r.a_item_count, r.a_item_count_display),
            price: parseChartNum(r.a_total_price, r.a_total_price_display),
        }];
        (r.periods || []).forEach(p => {
            values.push({
                count: parseChartNum(p.item_count, p.item_count_display),
                price: parseChartNum(p.total_price, p.total_price_display),
            });
        });
        return {
            key: rowChartKey(r),
            code: r.row_code || r.item_code,
            name: r.row_name || r.item_name,
            values: values,
        };
    });
    return { periods, items, multi_period: !!detail.multi_period && periods.length > 1 };
}

function itemSortMetricTotal(item, metric) {
    return (item.values || []).reduce((sum, v) => {
        return sum + (metric === 'price' ? (v.price || 0) : (v.count || 0));
    }, 0);
}

function filterDetailChartList() {
    const q = ($('#groupDetailChartSearch').val() || '').trim().toLowerCase();
    $('#groupDetailChartFilterList .detail-filter-row').each(function() {
        const hay = ($(this).attr('data-search') || '').toLowerCase();
        $(this).toggle(!q || hay.indexOf(q) !== -1);
    });
}

function buildGroupDetailChartFilters(chartData, metric) {
    metric = metric || getDetailChartMetric();
    const sorted = chartData.items.slice().sort((a, b) => {
        return itemSortMetricTotal(b, metric) - itemSortMetricTotal(a, metric);
    });
    let html = '';
    sorted.forEach((item, i) => {
        const label = (item.name || '—') + (item.code ? ' (' + item.code + ')' : '');
        const searchText = ((item.name || '') + ' ' + (item.code || '')).toLowerCase();
        const checked = i < 8 ? ' checked' : '';
        html += '<div class="form-check detail-filter-row" data-search="' + esc(searchText) + '">'
            + '<input class="form-check-input detail-item-cb" type="checkbox" value="' + esc(item.key) + '" id="di_' + i + '"' + checked + '>'
            + '<label class="form-check-label" for="di_' + i + '">' + esc(label) + '</label>'
            + '</div>';
    });
    $('#groupDetailChartFilterPlaceholder').addClass('d-none');
    $('#groupDetailChartSearch').val('');
    $('#groupDetailChartFilterList').html(html);
    filterDetailChartList();
}

function getSelectedDetailChartKeys() {
    const keys = [];
    $('.detail-item-cb:checked').each(function() { keys.push($(this).val()); });
    return keys;
}

function selectDetailChartItems(mode) {
    const topN = { top8: 8, top15: 15, top30: 30 };
    if (topN[mode]) {
        const detail = getActiveGroupDetailData();
        const chartData = detail ? normalizeGroupDetailChartData(detail) : null;
        if (chartData) {
            buildGroupDetailChartFilters(chartData, getDetailChartMetric());
        }
        const $cbs = $('.detail-item-cb');
        $cbs.prop('checked', false);
        $cbs.slice(0, topN[mode]).prop('checked', true);
    } else {
        const $cbs = $('.detail-item-cb');
        if (mode === 'all') {
            $cbs.prop('checked', true);
        } else if (mode === 'none') {
            $cbs.prop('checked', false);
        }
    }
    refreshGroupDetailChart();
}

function updateGroupDetailExportButton() {
    const detail = getActiveGroupDetailData();
    $('#btnExportGroupDetailExcel').prop('disabled', !(detail && detail.rows && detail.rows.length));
}

function getGroupDetailExportFilename() {
    const ctx = currentGroupContext || {};
    let name = (ctx.itemName || ctx.name || 'detail').replace(/[\\/:*?"<>|]/g, '_');
    if (name.length > 40) name = name.substring(0, 40);
    const tab = currentDoctorDrilldown ? 'items' : (currentGroupDetailTab || 'detail');
    const start = ($('#filterStart').val() || '').replace(/[\\/:*?"<>|]/g, '_');
    const end = ($('#filterEnd').val() || '').replace(/[\\/:*?"<>|]/g, '_');
    return 'detail_' + name + '_' + tab + '_' + start + '_' + end + '.xlsx';
}

function groupDetailRowsForExport(detail) {
    const cfg = DETAIL_TAB_CONFIG[currentDoctorDrilldown ? 'items' : (currentGroupDetailTab || 'items')] || DETAIL_TAB_CONFIG.items;
    const periods = detail.periods || [];
    const multi = !!detail.multi_period && periods.length > 1;
    const rows = detail.rows || [];
    if (!multi) {
        return {
            headers: [cfg.label, cfg.code, 'จำนวน', 'ราคา (บาท)'],
            data: rows.map(r => [
                r.row_name || r.item_name || '',
                r.row_code || r.item_code || '',
                Number(r.a_item_count) || 0,
                Number(r.a_total_price) || 0,
            ]),
        };
    }
    const baseLabel = periods[0].label || 'ช่วงอ้างอิง';
    const headers = [cfg.label, cfg.code, baseLabel + ' จำนวน'];
    for (let i = 1; i < periods.length; i++) {
        headers.push(periods[i].label + ' จำนวน', 'ต่าง', '%');
    }
    headers.push(baseLabel + ' ราคา');
    for (let i = 1; i < periods.length; i++) {
        headers.push(periods[i].label + ' ราคา', 'ต่างราคา', '%');
    }
    const data = rows.map(r => {
        const row = [
            r.row_name || r.item_name || '',
            r.row_code || r.item_code || '',
            Number(r.a_item_count) || 0,
        ];
        (r.periods || []).forEach(p => {
            row.push(
                Number(p.item_count) || 0,
                Number(p.diff_item_count) || 0,
                p.pct_item_count || ''
            );
        });
        row.push(Number(r.a_total_price) || 0);
        (r.periods || []).forEach(p => {
            row.push(
                Number(p.total_price) || 0,
                Number(p.diff_total_price) || 0,
                p.pct_total_price || ''
            );
        });
        return row;
    });
    return { headers, data };
}

function exportGroupDetailExcel() {
    const detail = getActiveGroupDetailData();
    if (!detail || !detail.rows || !detail.rows.length) {
        alert('ไม่มีข้อมูลสำหรับส่งออก');
        return;
    }
    if (typeof XLSX === 'undefined') {
        alert('ไม่สามารถโหลดไลบรารี Excel ได้');
        return;
    }
    const sheet = groupDetailRowsForExport(Object.assign({}, detail, {
        rows: sortGroupDetailTableRows(detail.rows),
    }));
    const ws = XLSX.utils.aoa_to_sheet([sheet.headers].concat(sheet.data));
    ws['!cols'] = sheet.headers.map((h, i) => ({ wch: i < 2 ? (i === 0 ? 28 : 14) : 12 }));
    const wb = XLSX.utils.book_new();
    const tabLabel = (DETAIL_TAB_CONFIG[currentGroupDetailTab] || DETAIL_TAB_CONFIG.items).label;
    XLSX.utils.book_append_sheet(wb, ws, tabLabel.substring(0, 31));
    XLSX.writeFile(wb, getGroupDetailExportFilename());
}

function destroyGroupDetailCharts() {
    if (groupDetailItemChart) { groupDetailItemChart.destroy(); groupDetailItemChart = null; }
    if (groupDetailSummaryChart) { groupDetailSummaryChart.destroy(); groupDetailSummaryChart = null; }
}

function refreshGroupDetailChart() {
    const detail = getActiveGroupDetailData();
    if (!detail || typeof Chart === 'undefined') return;
    currentGroupDetail = detail;
    const chartData = normalizeGroupDetailChartData(detail);
    if (!chartData) return;

    const isPrice = getDetailChartMetric() === 'price';
    const selected = getSelectedDetailChartKeys();
    const items = chartData.items
        .filter(it => selected.indexOf(it.key) !== -1)
        .sort((a, b) => itemSortMetricTotal(b, isPrice ? 'price' : 'count') - itemSortMetricTotal(a, isPrice ? 'price' : 'count'));

    const labels = items.map(it => {
        const n = it.name || '—';
        return n.length > 22 ? n.substring(0, 20) + '…' : n;
    });

    const datasets = chartData.periods.map((p, pi) => ({
        label: p.label || ('ช่วง ' + (pi + 1)),
        data: items.map(it => {
            const v = it.values[pi] || { count: 0, price: 0 };
            return isPrice ? (Number(v.price) || 0) : (Number(v.count) || 0);
        }),
        backgroundColor: CHART_COLORS[pi % CHART_COLORS.length],
        borderRadius: 4,
        maxBarThickness: 48,
    }));

    groupDetailChartItems = items.map(it => ({ code: it.code || '', name: it.name || '' }));
    groupDetailChartDepts = groupDetailChartItems.slice();
    groupDetailChartDoctors = groupDetailChartItems.slice();

    const ctx = document.getElementById('groupDetailItemChart');
    if (!ctx) return;
    if (groupDetailItemChart) groupDetailItemChart.destroy();
    const chartClickable = !currentDoctorDrilldown && (currentGroupDetailTab === 'doctor' || currentGroupDetailTab === 'dept');
    let chartClickHint = currentGroupDetailTab === 'dept'
        ? 'คลิกเพื่อดูรายละเอียดแผนก'
        : 'คลิกเพื่อดูแพทย์ที่สั่งมาก';
    groupDetailItemChart = new Chart(ctx.getContext('2d'), {
        type: 'bar',
        data: { labels, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            onClick: chartClickable ? onGroupDetailChartClick : undefined,
            onHover: chartClickable ? function(evt, elements) {
                if (evt.native && evt.native.target) {
                    evt.native.target.style.cursor = (elements && elements.length) ? 'pointer' : 'default';
                }
            } : undefined,
            plugins: {
                legend: { position: 'top' },
                tooltip: {
                    callbacks: {
                        label: function(item) {
                            return item.dataset.label + ': ' + formatChartValue(item.raw, isPrice);
                        },
                        afterBody: chartClickable ? function() {
                            return ['', chartClickHint];
                        } : undefined,
                    },
                },
            },
            scales: {
                x: { ticks: { font: { size: 10 }, maxRotation: 45, minRotation: 0 } },
                y: {
                    beginAtZero: true,
                    ticks: { callback: v => formatChartValue(v, isPrice) },
                },
            },
        },
    });

    const totals = chartData.periods.map((p, pi) => items.reduce((sum, it) => {
        const v = it.values[pi] || { count: 0, price: 0 };
        return sum + (isPrice ? (Number(v.price) || 0) : (Number(v.count) || 0));
    }, 0));

    const ctx2 = document.getElementById('groupDetailSummaryChart');
    if (!ctx2) return;
    if (groupDetailSummaryChart) groupDetailSummaryChart.destroy();
    groupDetailSummaryChart = new Chart(ctx2.getContext('2d'), {
        type: 'bar',
        data: {
            labels: chartData.periods.map(p => p.label || ''),
            datasets: [{
                label: isPrice ? 'ราคารวม (บาท)' : 'จำนวนรายการ',
                data: totals,
                backgroundColor: chartData.periods.map((_, i) => CHART_COLORS[i % CHART_COLORS.length]),
                borderRadius: 6,
                maxBarThickness: 72,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(item) {
                            return formatChartValue(item.raw, isPrice);
                        },
                    },
                },
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { callback: v => formatChartValue(v, isPrice) },
                },
            },
        },
    });
}

function setupGroupDetailCharts(detail) {
    currentGroupDetail = detail || null;
    destroyGroupDetailCharts();
    if (!detail || !detail.rows || !detail.rows.length) {
        $('#groupDetailChartSection').addClass('d-none');
        updateGroupDetailExportButton();
        return;
    }
    const chartData = normalizeGroupDetailChartData(detail);
    if (!chartData) {
        $('#groupDetailChartSection').addClass('d-none');
        return;
    }
    $('#groupDetailChartSection').removeClass('d-none');
    $('#groupDetailChartLabels').text(chartData.periods.map(p => p.label).join(' vs '));
    $('#groupDetailSummaryTitle').text(
        currentDoctorDrilldown
            ? 'สรุปรวมรายการที่เลือก — ' + (currentDoctorDrilldown.name || 'แพทย์')
            : (chartData.multi_period
                ? 'สรุปรวมที่เลือก — เปรียบเทียบแต่ละช่วง'
                : 'สรุปรวมที่เลือก — ช่วงเดียว')
    );
    buildGroupDetailChartFilters(chartData, getDetailChartMetric());
    refreshGroupDetailChart();
}

function onDetailChartMetricChange() {
    const detail = getActiveGroupDetailData();
    if (!detail) return;
    const chartData = normalizeGroupDetailChartData(detail);
    if (!chartData) return;
    buildGroupDetailChartFilters(chartData, getDetailChartMetric());
    refreshGroupDetailChart();
}

function onDetailDoctorAggChange() {
    if (currentGroupDetailTab !== 'doctor' || currentDoctorDrilldown) return;
    switchGroupDetailTab('doctor');
}

function sortItemDoctorRows(rows, detail, sortPeriodIdx) {
    const list = (rows || []).slice();
    if (!list.length) return list;
    const multi = detail && detail.multi_period && (detail.periods || []).length > 1;
    if (!multi || sortPeriodIdx <= 0) {
        return list.sort((a, b) => (Number(b.a_item_count) || 0) - (Number(a.a_item_count) || 0));
    }
    return list.sort((a, b) => {
        const av = Number((a.periods && a.periods[0]) ? a.periods[0].item_count : 0) || 0;
        const bv = Number((b.periods && b.periods[0]) ? b.periods[0].item_count : 0) || 0;
        return bv - av;
    });
}

function buildItemDoctorHead(detail) {
    const periods = detail.periods || [];
    if (!detail.multi_period || periods.length <= 1) {
        const label = periods[0] ? periods[0].label : 'ช่วงที่เลือก';
        return '<tr><th class="text-center" style="width:3rem">#</th><th class="text-start">แพทย์</th><th>รหัส</th>'
            + '<th>' + esc(label) + ' จำนวน</th><th>' + esc(label) + ' ราคา</th></tr>';
    }
    let html = '<tr><th class="text-center" style="width:3rem">#</th><th class="text-start">แพทย์</th><th>รหัส</th>'
        + '<th>' + esc(periods[0].label || 'ช่วง A') + ' จำนวน</th>';
    for (let i = 1; i < periods.length; i++) {
        html += '<th>' + esc(periods[i].label) + ' จำนวน</th><th>ต่าง</th><th>%</th>';
    }
    html += '<th>' + esc(periods[0].label || 'ช่วง A') + ' ราคา</th>';
    for (let i = 1; i < periods.length; i++) {
        html += '<th>' + esc(periods[i].label) + ' ราคา</th><th>ต่างราคา</th><th>%</th>';
    }
    html += '</tr>';
    return html;
}

function renderItemDoctorRows(detail, sortPeriodIdx) {
    const rows = sortItemDoctorRows(detail.rows || [], detail, sortPeriodIdx);
    const periods = detail.periods || [];
    const multi = !!detail.multi_period && periods.length > 1;
    if (!rows.length) {
        const cols = multi ? (3 + (periods.length - 1) * 3 + 2 + (periods.length - 1) * 3) : 5;
        return '<tr><td colspan="' + cols + '" class="text-center py-4 text-muted">ไม่มีข้อมูลแพทย์ในช่วงนี้</td></tr>';
    }
    return rows.map((r, idx) => {
        const name = r.row_name || 'ไม่ระบุแพทย์';
        const code = r.row_code || '—';
        let tr = '<tr><td class="text-center text-muted">' + (idx + 1) + '</td>'
            + '<td class="fw-bold">' + esc(name) + '</td>'
            + '<td class="text-center"><code>' + esc(code) + '</code></td>';
        if (!multi) {
            tr += '<td class="text-center">' + esc(r.a_item_count_display) + '</td>'
                + '<td class="text-end pe-3 text-success fw-bold">' + esc(r.a_total_price_display) + '</td>';
        } else {
            tr += '<td class="text-center">' + esc(r.a_item_count_display) + '</td>';
            (r.periods || []).forEach(p => {
                const clsCnt = p.diff_item_count > 0 ? 'rlu-compare-up' : (p.diff_item_count < 0 ? 'rlu-compare-down' : '');
                tr += '<td class="text-center">' + esc(p.item_count_display) + '</td>'
                    + '<td class="text-center fw-bold ' + clsCnt + '">' + esc(p.diff_item_count_display) + '</td>'
                    + '<td class="text-center ' + clsCnt + '">' + esc(p.pct_item_count) + '</td>';
            });
            tr += '<td class="text-center">' + esc(r.a_total_price_display) + '</td>';
            (r.periods || []).forEach(p => {
                const clsPrice = p.diff_total_price > 0 ? 'rlu-compare-up' : (p.diff_total_price < 0 ? 'rlu-compare-down' : '');
                tr += '<td class="text-center">' + esc(p.total_price_display) + '</td>'
                    + '<td class="text-center fw-bold ' + clsPrice + '">' + esc(p.diff_total_price_display) + '</td>'
                    + '<td class="text-center ' + clsPrice + '">' + esc(p.pct_total_price) + '</td>';
            });
        }
        tr += '</tr>';
        return tr;
    }).join('');
}

function onCompareGroupChartClick(evt, elements) {
    if (!elements || !elements.length) return;
    const el = elements[0];
    const groupIdx = el.index;
    const periodIdx = el.datasetIndex;
    const g = compareChartGroups[groupIdx];
    if (!g) return;
    if (currentCompareDimension === 'item') {
        if (!g.code) return;
        openItemDetailModal(g.code, g.name, periodIdx);
    } else if (currentCompareDimension === 'doctor') {
        const dc = g.code || '__none__';
        openCompareDoctorLabItems(dc, g.name);
    }
}

function onGroupDetailChartClick(evt, elements) {
    if (!elements || !elements.length || currentDoctorDrilldown) return;
    const el = elements[0];
    const rowIdx = el.index;
    if (currentGroupDetailTab === 'doctor') {
        const doc = groupDetailChartDoctors[rowIdx];
        if (!doc) return;
        highlightDetailRow(doc.code, doc.name);
    } else if (currentGroupDetailTab === 'dept') {
        const dept = groupDetailChartDepts[rowIdx];
        if (!dept) return;
        highlightDetailRow(dept.code, dept.name);
    }
}

function highlightDetailRow(code, name) {
    $('#groupDetailTbody tr').removeClass('table-primary');
    $('#groupDetailTbody tr').each(function() {
        const $code = $(this).find('code');
        if ($code.length && $code.text().trim() === String(code || '').trim()) {
            $(this).addClass('table-primary');
            this.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    });
}

async function openItemDetailModal(itemCode, itemName, sortPeriodIdx) {
    if (!groupDetailModal) {
        groupDetailModal = new bootstrap.Modal(document.getElementById('groupDetailModal'));
    }
    const title = (itemName || 'ไม่ระบุรายการ') + (itemCode ? ' (' + itemCode + ')' : '');
    $('#groupDetailModalLabel').html('<i class="fas fa-list-alt me-2"></i>' + esc(title));
    $('#groupDetailMeta').text('กำลังโหลดรายชื่อแพทย์...');
    $('#groupDetailThead').html('<tr><th colspan="4" class="text-center">กำลังโหลด...</th></tr>');
    $('#groupDetailTbody').html('<tr><td colspan="4" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-1"></i> กำลังโหลด...</td></tr>');
    $('#groupDetailElapsed').text('').removeData('elapsed');
    $('#groupDetailChartSection').addClass('d-none');
    destroyGroupDetailCharts();
    resetGroupDetailTableSort();
    currentGroupDetail = null;
    currentGroupDetailBundle = null;
    currentGroupDetailTab = 'doctor';
    currentDoctorDrilldown = null;
    $('#groupDetailTabItems').addClass('d-none');
    $('#groupDetailTabs .nav-link').removeClass('active');
    $('#groupDetailTabs .nav-link[data-detail-tab="doctor"]').addClass('active');
    toggleDoctorAggControls();
    groupDetailModal.show();

    currentGroupContext = {
        itemCode: itemCode || '',
        itemName: itemName || '',
        code: itemCode || '',
        name: itemName || '',
        filterYear: '',
        sortPeriodIdx: sortPeriodIdx || 0,
        reportParams: getReportParams(),
    };

    try {
        const params = Object.assign({
            action: 'item_doctors',
            item_code: itemCode || '',
            item_name: itemName || '',
            compare: $('#enableCompare').is(':checked') ? '1' : '',
        }, getReportParams());
        const data = await fetchJson(RLR_API + '?' + qs(params));
        if (data.status !== 'success') {
            throw new Error(data.message || 'โหลดไม่สำเร็จ');
        }
        const detail = data.detail || { rows: [], periods: [] };
        const periods = detail.periods || [];
        let meta = 'รายการ: <strong>' + esc(itemName || itemCode || '') + '</strong>';
        if (periods.length === 1) {
            meta += ' | ช่วง ' + esc(periods[0].label || '');
        } else if (periods.length > 1) {
            meta += ' | เปรียบเทียบ ' + periods.map(p => esc(p.label)).join(' vs ');
        }
        meta += ' | แผนกโหลดเมื่อสลับแท็บ';
        $('#groupDetailMeta').html(meta).data('base-meta', meta);
        const doctorsData = Object.assign({}, detail, { _loaded: true });
        if (data.elapsed_ms) {
            doctorsData._elapsed_ms = data.elapsed_ms;
        }
        currentGroupDetailBundle = {
            doctors: doctorsData,
            departments: { rows: [], periods: periods, _loaded: false },
            items: { rows: [], periods: periods, _loaded: false },
        };
        await switchGroupDetailTab('doctor');
        if (data.elapsed_ms) {
            $('#groupDetailElapsed').data('elapsed', data.elapsed_ms);
            $('#groupDetailElapsed').text('โหลดใช้เวลา ' + data.elapsed_ms + ' ms | แพทย์ ' + ((detail.rows || []).length));
        }
    } catch (e) {
        $('#groupDetailChartSection').addClass('d-none');
        destroyGroupDetailCharts();
        $('#groupDetailTbody').html('<tr><td colspan="4" class="text-center py-4 text-danger">' + esc(e.message || 'เกิดข้อผิดพลาด') + '</td></tr>');
    }
}

async function openItemDoctorsDetail(itemCode, itemName, sortPeriodIdx) {
    return openItemDetailModal(itemCode, itemName, sortPeriodIdx);
}

function renderDoctorItemsDrilldownView() {
    if (!currentDoctorDrilldown || !currentDoctorDrilldown.detail) return;
    const detail = currentDoctorDrilldown.detail;
    $('#groupDetailTabs').addClass('d-none');
    $('#groupDetailChartTitle').html('<i class="fas fa-chart-column me-1" style="color:#0d9488;"></i> '
        + esc('รายการ Lab — ' + (currentDoctorDrilldown.name || 'แพทย์')));
    $('#groupDetailFilterLabel').text('เลือกรายการ Lab');
    refreshGroupDetailTable(detail);
    setupGroupDetailCharts(detail);
    updateGroupDetailMeta();
    updateGroupDetailExportButton();
    const rowCount = (detail.rows || []).length;
    const elapsed = $('#groupDetailElapsed').data('elapsed') || '';
    if (elapsed) {
        $('#groupDetailElapsed').text('โหลดใช้เวลา ' + elapsed + ' ms | ' + rowCount + ' รายการ');
    }
}

async function openCompareDoctorLabItems(doctorCode, doctorName) {
    if (!groupDetailModal) {
        groupDetailModal = new bootstrap.Modal(document.getElementById('groupDetailModal'));
    }
    if (!currentGroupContext) {
        currentGroupContext = {
            code: '__all__',
            name: 'ทุกประเภท Lab',
            filterYear: '',
            reportParams: getReportParams(),
        };
    } else {
        currentGroupContext.reportParams = getReportParams();
    }
    currentGroupDetailBundle = null;
    currentGroupDetail = null;
    currentGroupDetailTab = 'items';
    const dc = doctorCode || '__none__';
    const title = (doctorName || 'ไม่ระบุแพทย์') + (dc && dc !== '__none__' ? ' (' + dc + ')' : '');
    $('#groupDetailModalLabel').html('<i class="fas fa-user-md me-2"></i>' + esc(title) + ' — รายการ Lab ที่สั่ง');
    $('#groupDetailMeta').text('กำลังโหลดรายการ Lab จาก HOSxP...');
    $('#groupDetailTabs').addClass('d-none');
    $('#groupDetailThead').html('<tr><th colspan="4" class="text-center">กำลังโหลด...</th></tr>');
    $('#groupDetailTbody').html('<tr><td colspan="4" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-1"></i> กำลังโหลด...</td></tr>');
    $('#groupDetailElapsed').text('');
    $('#groupDetailChartSection').addClass('d-none');
    destroyGroupDetailCharts();
    groupDetailModal.show();
    await openDoctorItemsDetail(dc, doctorName);
}

async function openDoctorItemsDetail(doctorCode, doctorName) {
    if (!currentGroupContext) return;
    currentDoctorDrilldown = { code: doctorCode, name: doctorName, detail: null, loading: true };
    $('#groupDetailThead').html('<tr><th colspan="4" class="text-center">กำลังโหลด...</th></tr>');
    $('#groupDetailTbody').html('<tr><td colspan="4" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-1"></i> โหลดรายการ Lab ของแพทย์...</td></tr>');
    $('#groupDetailChartSection').addClass('d-none');
    destroyGroupDetailCharts();
    toggleDoctorAggControls();
    updateGroupDetailMeta();

    const params = Object.assign({
        action: 'doctor_items',
        doctor_code: doctorCode || '',
        doctor_name: doctorName || '',
        compare: $('#enableCompare').is(':checked') ? '1' : '',
    }, currentGroupContext.reportParams || {});
    if (currentGroupContext.filterYear) params.year = currentGroupContext.filterYear;

    try {
        const data = await fetchJson(RLR_API + '?' + qs(params));
        if (data.status !== 'success') {
            throw new Error(data.message || 'โหลดไม่สำเร็จ');
        }
        currentDoctorDrilldown = {
            code: doctorCode,
            name: doctorName,
            detail: data.detail || { rows: [], periods: [] },
        };
        if (data.elapsed_ms) {
            $('#groupDetailElapsed').data('elapsed', data.elapsed_ms);
        }
        if (currentGroupDetailBundle) {
            switchGroupDetailTab('doctor');
        } else {
            renderDoctorItemsDrilldownView();
        }
    } catch (e) {
        currentDoctorDrilldown = null;
        $('#groupDetailTbody').html('<tr><td colspan="4" class="text-center py-4 text-danger">' + esc(e.message || 'เกิดข้อผิดพลาด') + '</td></tr>');
        updateGroupDetailMeta();
    }
}

async function openGroupDetail(groupCode, groupName, filterYear) {
    if (!groupDetailModal) {
        groupDetailModal = new bootstrap.Modal(document.getElementById('groupDetailModal'));
    }
    const title = (groupName || 'ไม่ระบุกลุ่ม') + (groupCode ? ' (' + groupCode + ')' : '');
    $('#groupDetailModalLabel').html('<i class="fas fa-vial me-2"></i>' + esc(title));
    $('#groupDetailMeta').text('กำลังโหลดรายการ Lab ย่อยจาก HOSxP...');
    $('#groupDetailThead').html('<tr><th class="text-start">รายการ Lab</th><th>รหัส</th><th>จำนวน</th><th>ราคา</th></tr>');
    $('#groupDetailTbody').html('<tr><td colspan="4" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-1"></i> กำลังโหลด...</td></tr>');
    $('#groupDetailElapsed').text('');
    $('#groupDetailChartSection').addClass('d-none');
    destroyGroupDetailCharts();
    resetGroupDetailTableSort();
    currentGroupDetail = null;
    currentGroupDetailBundle = null;
    currentGroupDetailTab = 'items';
    currentGroupContext = null;
    currentDoctorDrilldown = null;
    $('#groupDetailTabs .nav-link').removeClass('active');
    $('#groupDetailTabs .nav-link[data-detail-tab="items"]').addClass('active');
    groupDetailModal.show();

    const params = Object.assign({ action: 'group_items', group_code: groupCode || '', group_name: groupName || '' }, getReportParams());
    if (filterYear) params.year = filterYear;

    try {
        const data = await fetchJson(RLR_API + '?' + qs(params));
        if (data.status !== 'success') {
            throw new Error(data.message || 'โหลดไม่สำเร็จ');
        }
        const detail = data.detail || {};
        const periods = detail.periods || [];
        let meta = 'แสดงรายการ Lab ย่อยในประเภทนี้';
        if (periods.length === 1) {
            meta += ' | ช่วง ' + esc(periods[0].label || '');
        } else if (periods.length > 1) {
            meta += ' | เปรียบเทียบ ' + periods.map(p => esc(p.label)).join(' vs ');
        }
        if (filterYear) meta += ' | กรองปี ' + esc(filterYear);
        $('#groupDetailMeta').html(meta).data('base-meta', meta);
        currentGroupContext = {
            code: groupCode,
            name: groupName,
            filterYear: filterYear || '',
            reportParams: getReportParams(),
        };
        currentGroupDetailBundle = {
            items: data.items || data.detail || {},
            departments: data.departments || { rows: [], periods: (data.detail || {}).periods || [] },
            doctors: data.doctors || { rows: [], periods: (data.detail || {}).periods || [] },
        };
        switchGroupDetailTab('items');
        if (data.elapsed_ms) {
            $('#groupDetailElapsed').data('elapsed', data.elapsed_ms);
            const items = currentGroupDetailBundle.items || {};
            $('#groupDetailElapsed').text('โหลดใช้เวลา ' + data.elapsed_ms + ' ms'
                + ' | รายการ ' + ((items.rows || []).length)
                + ' | แผนก ' + ((currentGroupDetailBundle.departments.rows || []).length)
                + ' | แพทย์ ' + ((currentGroupDetailBundle.doctors.rows || []).length));
        }
    } catch (e) {
        $('#groupDetailChartSection').addClass('d-none');
        destroyGroupDetailCharts();
        $('#groupDetailTbody').html('<tr><td colspan="4" class="text-center py-4 text-danger">' + esc(e.message || 'เกิดข้อผิดพลาด') + '</td></tr>');
    }
}

function getReportParams() {
    const p = {
        start_date: $('#filterStart').val(),
        end_date: $('#filterEnd').val(),
        income_groups: getIncomeGroupList().join(','),
        doctor_agg: getVisitScopeMode(),
    };
    const hip = getHipdataCodeList();
    if (hip.length) p.hipdata_codes = hip.join(',');
    if ($('#enableCompare').is(':checked')) {
        p.compare = '1';
        p.compare_mode = $('#compareMode').val();
        if (p.compare_mode === 'custom') {
            p.compare_start = $('#compareStart').val();
            p.compare_end = $('#compareEnd').val();
        } else if (p.compare_mode === 'year') {
            const years = [];
            const n = parseInt($('#compareYearCount').val(), 10) || 1;
            for (let i = 1; i <= n; i++) {
                const y = $('#compareYear' + i).val();
                if (y && years.indexOf(y) === -1) years.push(y);
            }
            p.compare_years = years.join(',');
        } else if (p.compare_mode === 'month') {
            p.compare_months = getCompareMonthList().join(',');
        }
    }
    return p;
}

async function fetchJson(url) {
    const r = await fetch(url, { credentials: 'same-origin' });
    const text = await r.text();
    try { return JSON.parse(text); } catch (e) { throw new Error(text ? text.substring(0, 300) : ('HTTP ' + r.status)); }
}

function chunkMonthList(arr, size) {
    const out = [];
    for (let i = 0; i < arr.length; i += size) {
        out.push(arr.slice(i, i + size));
    }
    return out;
}

function cacheBatchMonthsForScope(scope) {
    if (scope === 'usage') return 24;
    if (scope === 'doctor' || scope === 'dept') return 1;
    return 3;
}

async function runBuildTaskPool(concurrency, count, worker) {
    let idx = 0;
    const n = Math.min(concurrency, count);
    const workers = [];
    for (let w = 0; w < n; w++) {
        workers.push((async () => {
            while (true) {
                const i = idx++;
                if (i >= count) break;
                await worker(i);
            }
        })());
    }
    await Promise.all(workers);
}

async function buildMissingMonths(missing, baseParams, scope, silent) {
    if (!missing || !missing.length) return;
    const buildScope = scope || 'item';
    const BATCH_MONTHS = cacheBatchMonthsForScope(buildScope);
    const BATCH_PARALLEL = (buildScope === 'doctor' || buildScope === 'dept') ? 2 : 3;
    const MONTH_PARALLEL = 3;
    const total = missing.length;
    const scopeLabel = buildScope !== 'item' ? (' (' + buildScope + ')') : '';

    function updateProgress(done, tot, currentYm, note) {
        if (silent) return;
        const pct = Math.min(100, Math.round((done / tot) * 100));
        $('#buildBox').removeClass('d-none');
        $('#buildProgress').css('width', pct + '%');
        const tail = currentYm ? ' — ' + currentYm : '';
        const extra = note ? ' ' + note : '';
        $('#buildLabel').text('ประมวลผล' + scopeLabel + ' ' + done + '/' + tot + ' เดือน' + tail + extra + '...');
    }

    if (!silent) {
        $('#buildBox').removeClass('d-none');
    }

    if (total >= 2) {
        updateProgress(0, total, '', '');
        try {
            const groups = chunkMonthList(missing, BATCH_MONTHS);
            let done = 0;
            const parallel = Math.min(BATCH_PARALLEL, groups.length);
            await runBuildTaskPool(parallel, groups.length, async (gi) => {
                const group = groups[gi];
                const batch = await fetchJson(RLR_API + '?' + qs(Object.assign({}, baseParams, {
                    action: 'build_batch',
                    months: group.join(','),
                    scope: buildScope,
                })));
                if (batch.status !== 'success') {
                    throw new Error(batch.message || 'สร้าง cache batch ไม่สำเร็จ');
                }
                done += group.length;
                updateProgress(done, total, group[group.length - 1], '(' + parallel + ' batch คู่ขนาน)');
            });
            if (!silent) {
                $('#buildProgress').css('width', '100%');
                $('#buildLabel').text('ประมวลผลครบ ' + total + ' เดือน' + scopeLabel + ' (' + groups.length + ' batch)');
                setTimeout(() => $('#buildBox').addClass('d-none'), 800);
            }
            return;
        } catch (e) {
            if (silent) return;
        }
    }

    let done = 0;
    const n = Math.min(MONTH_PARALLEL, total);
    await runBuildTaskPool(n, total, async (i) => {
        const ym = missing[i];
        const data = await fetchJson(RLR_API + '?' + qs(Object.assign({}, baseParams, {
            action: 'build',
            ym: ym,
            scope: buildScope,
        })));
        if (data.status !== 'success') {
            throw new Error(data.message || 'สร้าง cache ไม่สำเร็จ: ' + ym);
        }
        done++;
        updateProgress(done, total, ym, '(' + n + ' คู่ขนาน)');
    });
    if (!silent) {
        $('#buildProgress').css('width', '100%');
        $('#buildLabel').text('ประมวลผลครบ ' + total + ' เดือน' + scopeLabel);
        setTimeout(() => $('#buildBox').addClass('d-none'), 800);
    }
}

function setLoadStatus(msg) {
    if (loadStatusHideTimer) {
        clearTimeout(loadStatusHideTimer);
        loadStatusHideTimer = null;
    }
    $('#buildBox').removeClass('d-none');
    $('#buildProgress').css('width', '100%');
    $('#buildLabel').text(msg);
}

function hideLoadStatus() {
    if (loadStatusHideTimer) {
        clearTimeout(loadStatusHideTimer);
    }
    loadStatusHideTimer = setTimeout(() => {
        $('#buildBox').addClass('d-none');
        loadStatusHideTimer = null;
    }, 200);
}

function getCompareYearList() {
    const years = [];
    const n = parseInt($('#compareYearCount').val(), 10) || 1;
    for (let i = 1; i <= n; i++) {
        const y = $('#compareYear' + i).val();
        if (y && years.indexOf(y) === -1) years.push(y);
    }
    return years;
}

function getCompareMonthList() {
    const months = [];
    const n = parseInt($('#compareMonthCount').val(), 10) || 1;
    for (let i = 1; i <= n; i++) {
        const m = $('#compareMonth' + i).val();
        if (m && months.indexOf(m) === -1) months.push(m);
    }
    return months;
}

function comparePeriodLabel(comp, p) {
    if (!p) return '';
    if (p.label) return p.label;
    if (p.compare_month) return String(p.compare_month);
    if (p.year != null && p.year !== '') return String(p.year);
    return '';
}

function mergeComparisonSlices(mainData, slices, granularity) {
    if (!slices || !slices.length) return null;
    if (slices.length === 1) {
        return Object.assign({ multi_year: false }, slices[0].comparison, { period_b: slices[0].period });
    }
    const periods_b = slices.map(s => {
        const key = s.compare_month || String(s.year);
        return {
            year: key,
            label: (s.comparison && s.comparison.label_b) || key,
            period: s.period,
        };
    });
    const rowMap = {};
    slices.forEach(s => {
        const periodKey = s.compare_month || String(s.year);
        (s.comparison.rows || []).forEach(r => {
            const key = (r.group_code || '') + '|' + (r.group_name || '');
            if (!rowMap[key]) {
                rowMap[key] = {
                    group_code: r.group_code,
                    group_name: r.group_name,
                    a_item_count: r.a_item_count,
                    a_total_price: r.a_total_price,
                    a_item_count_display: r.a_item_count_display,
                    a_total_price_display: r.a_total_price_display,
                    periods: [],
                };
            }
            rowMap[key].periods.push({
                year: periodKey,
                label: (s.comparison && s.comparison.label_b) || periodKey,
                item_count: r.b_item_count,
                total_price: r.b_total_price,
                diff_item_count: r.diff_item_count,
                diff_total_price: r.diff_total_price,
                pct_item_count: r.pct_item_count,
                pct_total_price: r.pct_total_price,
                item_count_display: r.b_item_count_display,
                total_price_display: r.b_total_price_display,
                diff_item_count_display: r.diff_item_count_display,
                diff_total_price_display: r.diff_total_price_display,
            });
        });
    });
    const summary_periods = slices.map(s => {
        const cs = (s.comparison && s.comparison.summary) ? s.comparison.summary : {};
        const periodKey = s.compare_month || String(s.year);
        return {
            year: periodKey,
            label: (s.comparison && s.comparison.label_b) || periodKey,
            total_items_display: cs.b_total_items_display || '0',
            total_price_display: cs.b_total_price_display || '0',
            diff_items_display: cs.diff_items_display || '0',
            diff_price_display: cs.diff_price_display || '0',
            pct_items: cs.pct_items || '0%',
            pct_price: cs.pct_price || '0%',
        };
    });
    const firstSummary = (mainData.summary || {});
    return {
        multi_year: true,
        compare_granularity: granularity || 'year',
        label_a: (mainData.period && mainData.period.label) || 'ช่วงหลัก',
        periods_b: periods_b,
        rows: Object.values(rowMap),
        summary: {
            a_total_items_display: firstSummary.total_items_display || '0',
            a_total_price_display: firstSummary.total_price_display || '0',
            periods: summary_periods,
        },
    };
}

async function fetchCompareSlice(baseParams, extra) {
    const p = Object.assign({}, baseParams, { action: 'compare_slice' }, extra || {});
    delete p.compare_years;
    delete p.compare_months;
    let data = await fetchJson('fetch_rational_lab_usage_api.php?' + qs(p));
    if (data.status === 'need_build') {
        await buildMissingMonths(data.missing_months || [], baseParams);
        data = await fetchJson('fetch_rational_lab_usage_api.php?' + qs(p));
    }
    if (data.status !== 'success') {
        throw new Error(data.message || 'โหลดเปรียบเทียบไม่สำเร็จ');
    }
    return data;
}

function isPeerCompareMode(baseParams) {
    if (!baseParams || baseParams.compare !== '1') return false;
    if (baseParams.compare_mode === 'month') return getCompareMonthList().length >= 2;
    if (baseParams.compare_mode === 'year') return getCompareYearList().length >= 2;
    return false;
}

async function loadPeerCompareData(baseParams) {
    setLoadStatus('โหลดเปรียบเทียบช่วงที่เลือก...');
    let data = await fetchJson('fetch_rational_lab_usage_api.php?' + qs(Object.assign({ action: 'peer_report' }, baseParams)));
    if (data.status === 'need_build') {
        await buildMissingMonths(data.missing_months || [], baseParams);
        data = await fetchJson('fetch_rational_lab_usage_api.php?' + qs(Object.assign({ action: 'peer_report' }, baseParams)));
    }
    if (data.status !== 'success') {
        throw new Error(data.message || 'โหลดเปรียบเทียบไม่สำเร็จ');
    }
    hideLoadStatus();
    return data.comparison;
}

async function loadCompareData(baseParams, mainData) {
    if (isPeerCompareMode(baseParams)) {
        return loadPeerCompareData(baseParams);
    }
    const mode = baseParams.compare_mode;
    const slices = [];
    if (mode === 'year') {
        const years = getCompareYearList();
        for (let i = 0; i < years.length; i++) {
            setLoadStatus('โหลดเปรียบเทียบปี ' + years[i] + ' (' + (i + 1) + '/' + years.length + ')...');
            const slice = await fetchCompareSlice(baseParams, { compare_year: years[i] });
            slices.push(slice);
        }
        hideLoadStatus();
        return mergeComparisonSlices(mainData, slices, 'year');
    }
    if (mode === 'month') {
        const months = getCompareMonthList();
        for (let i = 0; i < months.length; i++) {
            setLoadStatus('โหลดเปรียบเทียบเดือน ' + months[i] + ' (' + (i + 1) + '/' + months.length + ')...');
            const slice = await fetchCompareSlice(baseParams, { compare_month: months[i] });
            slices.push(slice);
        }
        hideLoadStatus();
        return mergeComparisonSlices(mainData, slices, 'month');
    }
    setLoadStatus('โหลดข้อมูลเปรียบเทียบ...');
    slices.push(await fetchCompareSlice(baseParams, {}));
    hideLoadStatus();
    return mergeComparisonSlices(mainData, slices, 'year');
}

function applyReport(data, comparison) {
    const summary = data.summary || {};
    $('#sumItems').text(summary.total_items_display || '0');
    $('#sumPrice').text(summary.total_price_display || '0');
    $('#monthTbody').html(renderMonthRows(summary.by_month || []));
    $('#yearTbody').html(renderYearRows(summary.by_year || []));
    setDetailTableRows(data.rows || []);
    renderDetailTable();

    const compareEnabled = $('#enableCompare').is(':checked');
    const comp = comparison !== undefined ? comparison : data.comparison;

    currentGroupContext = {
        code: '__all__',
        name: 'หมวดรายได้ที่เลือก',
        filterYear: '',
        reportParams: getReportParams(),
    };

    dimensionComparisonCache = {};
    currentCompareDimension = 'item';
    updateCompareDimensionUI();

    if (comp) {
        dimensionComparisonCache['item:' + getVisitScopeMode()] = comp;
        setupCompareCharts(comp);
        if (compareEnabled) {
            renderComparisonView(getActiveComparison());
        } else {
            $('#compareMultiSummary').addClass('d-none').empty();
        }
    } else {
        $('#compareMultiSummary').addClass('d-none').empty();
        setupCompareCharts(null);
    }

    setReportViewMode(compareEnabled);

    const meta = data.meta || {};
    let metaHtml = '<i class="fas fa-info-circle me-1"></i>';
    if (compareEnabled && comp) {
        if (comp.peer_compare) {
            metaHtml += 'เทียบกันเอง (ไม่รวมช่วงวันที่ด้านบน) | ฐานอ้างอิง: ' + esc(comp.label_a || '');
        } else {
            metaHtml += 'โหมดเปรียบเทียบ | อ้างอิง: ' + esc(comp.label_a || (data.period && data.period.label) || '');
        }
    } else {
        metaHtml += 'ช่วง: ' + esc((data.period && data.period.label) || '');
    }
    if (meta.source) metaHtml += (meta.source.indexOf('cache') >= 0 ? ' | แหล่ง: cache รายเดือน' : ' | แหล่ง: opitemrece');
    const scopeLbl = visitScopeLabel();
    if (scopeLbl) metaHtml += ' | ' + esc(scopeLbl);
    if (comp && comp.data_source === 'cache') metaHtml += ' | แหล่ง: cache รายเดือน';
    if (comp && comp.data_source === 'hosxp') metaHtml += ' | แหล่ง: HOSxP โดยตรง';
    if (meta.elapsed_ms != null) metaHtml += ' | อ่าน ' + (meta.elapsed_ms / 1000).toFixed(2) + ' วินาที';
    if (comp && comp.multi_year) metaHtml += comp.compare_granularity === 'month' ? ' | โหลดแยกทีละเดือน' : ' | โหลดแยกทีละปี';
    $('#metaBox').removeClass('alert-danger').addClass('alert-info').removeClass('d-none').html(metaHtml);
}

async function loadRluDashboard() {
    $('#btnLoad').prop('disabled', true);
    $('#detailTbody').html('<tr><td colspan="5" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i>กำลังโหลด...</td></tr>');
    toggleComparePanel();

    try {
        const baseParams = getReportParams();
        if (!baseParams.income_groups) {
            throw new Error('กรุณาเลือกหมวดรายได้อย่างน้อย 1 หมวด');
        }
        const compareEnabled = baseParams.compare === '1';

        setLoadStatus('ตรวจสอบ cache รายเดือน...');
        const status = await fetchJson(RLR_API + '?' + qs(Object.assign({ action: 'status' }, baseParams)));
        if (status.status === 'success' && status.missing_months && status.missing_months.length) {
            await buildMissingMonths(status.missing_months, baseParams);
        }

        let data = { summary: {}, rows: [], period: {}, meta: {} };
        let comparison = null;

        if (compareEnabled) {
            setLoadStatus(getHipdataCodeList().length
                ? 'โหลดเปรียบเทียบ (cache + กรองสิทธิ)...'
                : 'โหลดเปรียบเทียบรายการ...');
            dimensionComparisonCache = {};
            comparison = await loadDimensionComparison('item');
            applyReport(data, comparison);
            hideLoadStatus();
        } else {
            setLoadStatus('โหลดรายงาน...');
            const mainParams = Object.assign({}, baseParams, { action: 'report' });
            data = await fetchJson(RLR_API + '?' + qs(mainParams));
            if (data.status !== 'success') {
                throw new Error(data.message || 'โหลดรายงานไม่สำเร็จ');
            }
            setLoadStatus('โหลดรายการ...');
            dimensionComparisonCache = {};
            comparison = await loadDimensionComparison('item');
            applyReport(data, comparison);
            hideLoadStatus();
        }
    } catch (err) {
        hideLoadStatus();
        const msg = esc(err.message || 'เกิดข้อผิดพลาด');
        $('#detailTbody').html('<tr><td colspan="5" class="text-center text-danger py-4">' + msg + '</td></tr>');
        setDetailTableRows([]);
        $('#btnExportDetailExcel').prop('disabled', true);
        $('#compareTbody').html('<tr><td colspan="9" class="text-center text-danger py-4">' + msg + '</td></tr>');
        $('#chartGroupFilterPlaceholder').addClass('d-none');
        $('#chartGroupFilterList').html('<span class="text-danger small">' + msg + '</span>');
        $('#metaBox').removeClass('d-none alert-info').addClass('alert-danger').html('<i class="fas fa-exclamation-circle me-1"></i>' + msg);
    } finally {
        $('#btnLoad').prop('disabled', false);
    }
}

$(function() {
    if (typeof flatpickr !== 'undefined') {
        fpStart = flatpickr('#filterStart', { dateFormat: 'Y-m-d' });
        fpEnd = flatpickr('#filterEnd', { dateFormat: 'Y-m-d' });
        fpCompareStart = flatpickr('#compareStart', { dateFormat: 'Y-m-d' });
        fpCompareEnd = flatpickr('#compareEnd', { dateFormat: 'Y-m-d' });
    }
    toggleComparePanel();
    toggleCompareYearPickers();
    toggleCompareMonthPickers();
    $('#detailThead').html(buildDetailHead());

    $(document).on('input', '#detailTableSearch', renderDetailTable);

    $(document).on('click', '.rlu-item-link', function(e) {
        e.preventDefault();
        const code = $(this).attr('data-item-code') || '';
        const name = $(this).attr('data-item-name') || '';
        openItemDetailModal(code, name, 0);
    });

    $(document).on('click', '.rlu-group-link', function(e) {
        e.preventDefault();
        const code = $(this).data('group-code');
        const name = $(this).data('group-name');
        const year = $(this).data('year') || '';
        openGroupDetail(code, name, year);
    });

    $(document).on('click', '.rlu-doctor-link', function(e) {
        e.preventDefault();
        const code = $(this).data('doctor-code');
        const name = $(this).data('doctor-name');
        openCompareDoctorLabItems(code, name);
    });

    $(document).on('click', '#btnDoctorDrillBack', function(e) {
        e.preventDefault();
        backDoctorDrilldown();
    });

    $(document).on('click', '#compareThead .rlu-sort-th', function() {
        onCompareTableSort($(this).data('sort-key'));
    });

    $(document).on('click', '#detailThead .rlu-sort-th', function() {
        onDetailTableSort($(this).data('sort-key'));
    });

    $(document).on('click', '#groupDetailThead .rlu-sort-th', function() {
        onGroupDetailTableSort($(this).data('sort-key'));
    });

    $(document).on('input', '#chartGroupSearch', filterChartGroupList);
    $(document).on('input', '#groupDetailChartSearch', filterDetailChartList);

    $(document).on('change', 'input[name="detailChartMetric"]', onDetailChartMetricChange);
    $(document).on('change', 'input[name="detailDoctorAgg"]', onDetailDoctorAggChange);
    $(document).on('change', '.detail-item-cb', refreshGroupDetailChart);

    document.getElementById('groupDetailModal').addEventListener('hidden.bs.modal', function() {
        destroyGroupDetailCharts();
        currentGroupDetail = null;
        currentGroupDetailBundle = null;
        currentGroupDetailTab = 'doctor';
        currentGroupContext = null;
        currentDoctorDrilldown = null;
        groupDetailTabFetch = {};
        $('#groupDetailTabs').removeClass('d-none');
        $('#groupDetailTabItems').addClass('d-none');
        $('#btnExportGroupDetailExcel').prop('disabled', true);
        resetGroupDetailTableSort();
    });
});
</script>
</body>
</html>
