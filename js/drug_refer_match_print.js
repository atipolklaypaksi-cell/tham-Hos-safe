/**
 * รูปแบบพิมพ์เอกสารเทียบบัญชียา / Refer Back — ใช้ร่วมกันทุกหน้า
 */
(function (global) {
    'use strict';

    function esc(s) {
        if (typeof global.jQuery !== 'undefined') {
            return global.jQuery('<div>').text(s ?? '').html();
        }
        return String(s ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function formatMatchColHeader(hospital) {
        const h = String(hospital || '').trim();
        return h ? ('ยาในบัญชี ' + h) : 'ยาในบัญชี รพ.';
    }

    function needsReferBack(match) {
        const st = (match && match.status) ? match.status : 'none';
        return st !== 'exact';
    }

    function countReferBackItems(rows) {
        let n = 0;
        (rows || []).forEach(function (r) {
            if (needsReferBack(r.match)) n++;
        });
        return n;
    }

    function renderSuggestLabel(s) {
        const primary = s.display_primary || s.display_name || s.tmtdrug || '';
        const secondary = s.display_secondary || '';
        let label = primary;
        if (secondary && secondary !== primary) label += ' — ' + secondary;
        return label;
    }

    function renderPrintCheckboxBox() {
        return '<span style="display:inline-block;width:10px;height:10px;border:1.2px solid #222;vertical-align:-1px;margin-right:3px;"></span>';
    }

    function renderMatchDetailPrintSignoffHtml() {
        const cb = renderPrintCheckboxBox();
        let html = '<div style="margin-top:10px;border:1.5px solid #333;padding:5px 8px;font-size:10px;line-height:1.35;color:#000;">';
        html += '<div style="margin-bottom:3px;"><strong>แพทย์</strong> &nbsp;ลงชื่อ ....................................................................................................</div>';
        html += '<div style="margin-bottom:4px;">' + cb + 'Refer back ได้ &nbsp;นัด ............... เดือน / ไม่นัด &nbsp;&nbsp; ' +
            cb + 'ไม่ Refer back &nbsp;เหตุผล ..................................................................</div>';
        html += '<table style="width:100%;border-collapse:collapse;font-size:10px;line-height:1.35;"><tr>';
        html += '<td style="width:50%;vertical-align:middle;padding:0 8px 0 0;border-right:1px solid #bbb;">';
        html += '<strong>เภสัชกร</strong> &nbsp;ลงชื่อ ........................ &nbsp;' + cb + 'ตรวจสอบแล้ว';
        html += '</td>';
        html += '<td style="width:50%;vertical-align:middle;padding:0 0 0 8px;">';
        html += '<strong>พยาบาล</strong> &nbsp;ลงชื่อ ........................ &nbsp;' + cb + 'ตรวจสอบแล้ว';
        html += '</td>';
        html += '</tr></table>';
        html += '</div>';
        return html;
    }

    function renderReferBackPrintBadge(match) {
        if (!needsReferBack(match)) {
            return '<span style="color:#198754;font-weight:700;font-size:13px;">✓</span>' +
                '<div style="font-size:8px;color:#666;margin-top:2px;">ในบัญชี</div>';
        }
        const isMissing = !match || match.status === 'none';
        const bg = isMissing ? '#dc3545' : '#e67e22';
        const border = isMissing ? '#842029' : '#9a5700';
        return '<div style="display:inline-block;padding:3px 5px;border-radius:4px;background:' + bg + ';color:#fff;' +
            'font-weight:800;font-size:8px;line-height:1.25;text-align:center;border:2px solid ' + border + ';min-width:46px;">' +
            '<div style="font-size:16px;line-height:1;margin-bottom:1px;">↩</div>REFER<br>BACK</div>';
    }

    function renderReferBackPrintHeader(res) {
        const rows = res.rows || [];
        const referBackCount = countReferBackItems(rows);
        const hospital = res.hospital || 'รพ.เครือข่ายตามอำเภอ';
        if (!referBackCount) {
            return '<div style="border:2px solid #198754;background:#d1e7dd;padding:8px 12px;border-radius:6px;margin-bottom:10px;">' +
                '<strong style="color:#0f5132;font-size:12px;">✓ ยาทุกรายการตรงบัญชี ' + esc(hospital) + '</strong>' +
                '<div style="font-size:10px;color:#0f5132;margin-top:3px;">สามารถ Refer Back กลับ รพ.ปลายทางได้</div></div>';
        }
        return '<div style="border:3px double #dc3545;background:#fff5f5;padding:10px 12px;border-radius:6px;margin-bottom:10px;">' +
            '<div style="display:flex;align-items:flex-start;gap:10px;">' +
            '<div style="flex-shrink:0;width:54px;height:54px;border-radius:50%;background:#dc3545;color:#fff;' +
            'display:flex;align-items:center;justify-content:center;font-size:30px;font-weight:900;border:3px solid #842029;line-height:1;">↩</div>' +
            '<div style="flex:1;">' +
            '<div style="font-size:17px;font-weight:900;color:#dc3545;letter-spacing:1.5px;line-height:1.2;">REFER BACK</div>' +
            '<div style="font-size:11px;color:#842029;font-weight:700;margin-top:3px;">ส่งต่อยาไป <strong>' + esc(hospital) + '</strong> — <strong>' + referBackCount + '</strong> รายการ</div>' +
            '<div style="font-size:10px;color:#555;margin-top:5px;line-height:1.5;">' +
            'สัญลักษณ์ <strong style="color:#dc3545;">↩ REFER BACK</strong> = ยาไม่อยู่ในบัญชี รพ.ปลายทาง หรือใกล้เคียงไม่ตรง 100%<br>' +
            'กรุณาแจ้งบุคลากรทางการแพทย์/เภสัชกร เพื่อปรับยาหรือส่ง Refer กลับ รพ.ตามเขตผู้ป่วย</div>' +
            '</div></div></div>';
    }

    function renderMatchStatusPrintLabel(match) {
        if (!match || typeof match !== 'object') return 'ไม่พบ';
        const st = match.status || 'none';
        if (st === 'exact' && match.substitute_note) return 'ตรง (ทดแทน)';
        if (st === 'exact') return 'ตรง';
        if (st === 'similar') return 'ใกล้เคียง';
        return 'ไม่พบ';
    }

    function matchStatusPrintColors(match) {
        const st = (match && match.status) ? match.status : 'none';
        if (st === 'exact') {
            return { bg: '#d1e7dd', border: '#198754', text: '#0f5132', row: '#f4fbf7' };
        }
        if (st === 'similar') {
            return { bg: '#fff3cd', border: '#fd7e14', text: '#664d03', row: '#fffdf5' };
        }
        return { bg: '#f8d7da', border: '#dc3545', text: '#842029', row: '#fff8f8' };
    }

    function renderMatchStatusPrintBadge(match) {
        const label = renderMatchStatusPrintLabel(match);
        const c = matchStatusPrintColors(match);
        return '<span style="display:inline-block;padding:2px 8px;border-radius:4px;font-weight:700;font-size:10px;' +
            'background:' + c.bg + ';color:' + c.text + ';border:1px solid ' + c.border + ';">' + esc(label) + '</span>';
    }

    function renderDrugNamePrint(r) {
        const primary = r.display_primary || r.tmtdrug || r.drug_name_tmt || r.drug_name || '';
        const secondary = r.display_secondary || '';
        let html = esc(primary);
        if (secondary) html += '<br>' + esc(secondary);
        if (r.method) html += '<br><span style="color:#666;font-size:10px;">' + esc(r.method) + '</span>';
        return html;
    }

    function renderMatchedNamePrint(match) {
        if (!match || typeof match !== 'object') return '-';
        const primary = match.matched_display_primary || match.matched_display || match.matched_tmtdrug || '-';
        const secondary = match.matched_display_secondary || '';
        let html = esc(primary);
        if (secondary) html += '<br><span style="color:#666;font-size:10px;">' + esc(secondary) + '</span>';
        if (match.substitute_note && match.substitute_via) {
            html += '<br><span style="color:#0f5132;font-size:10px;">↔ ' + esc(match.substitute_via) + ' (' + esc(match.substitute_note) + ')</span>';
        }
        return html;
    }

    function renderMatchDetailPrintSummaryHtml(res) {
        const s = res.summary || {};
        const p = res.patient || {};
        const ref = res.ref_visit || {};
        const pct = s.match_percent || 0;
        let pctColor = '#842029';
        let pctBg = '#f8d7da';
        if (pct >= 80) { pctColor = '#0f5132'; pctBg = '#d1e7dd'; }
        else if (pct >= 50) { pctColor = '#664d03'; pctBg = '#fff3cd'; }

        let html = '<div style="border:1px solid #c8e6d4;border-radius:6px;padding:10px 12px;background:#f8fcfa;margin-bottom:10px;">';
        html += '<div style="font-size:13px;margin-bottom:4px;"><strong>' + esc(p.full_name || '') + '</strong> | HN: <strong>' + esc(p.hn || '') + '</strong></div>';
        if (p.address) html += '<div style="font-size:11px;color:#444;">ที่อยู่: ' + esc(p.address) + '</div>';
        if (p.amp_name) html += '<div style="font-size:11px;color:#444;">อำเภอ: ' + esc(p.amp_name) + '</div>';
        html += '<div style="font-size:11px;margin-top:4px;">เทียบกับ <strong style="color:#1f4d3b;">' + esc(res.hospital || '') + '</strong>';
        if (res.appt_date_label) html += ' | วันนัด: <strong>' + esc(res.appt_date_label) + '</strong>';
        html += '</div>';
        if (res.period && res.period.start_date) {
            html += '<div style="font-size:11px;">ช่วง: <strong>' + esc(res.period.start_date) + ' – ' + esc(res.period.end_date || '') + '</strong></div>';
        }
        if (ref.visit_label) {
            html += '<div style="font-size:11px;">Visit อ้างอิง: <strong>' + esc(ref.visit_label) + '</strong>';
            if (ref.filter_note) html += ' <span style="color:#666;">(' + esc(ref.filter_note) + ')</span>';
            html += '</div>';
        }
        html += '</div>';

        html += '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;">';
        html += '<span style="padding:4px 10px;border-radius:4px;background:#e9ecef;font-size:11px;">รายการยา <strong>' + (s.total || 0) + '</strong></span>';
        html += '<span style="padding:4px 10px;border-radius:4px;background:#d1e7dd;color:#0f5132;border:1px solid #198754;font-size:11px;">ตรง <strong>' + (s.exact || 0) + '</strong></span>';
        html += '<span style="padding:4px 10px;border-radius:4px;background:#fff3cd;color:#664d03;border:1px solid #fd7e14;font-size:11px;">ใกล้เคียง ≥90% <strong>' + (s.similar || 0) + '</strong></span>';
        html += '<span style="padding:4px 10px;border-radius:4px;background:#f8d7da;color:#842029;border:1px solid #dc3545;font-size:11px;">ไม่พบ <strong>' + (s.missing || 0) + '</strong></span>';
        const referBackCount = countReferBackItems(res.rows || []);
        if (referBackCount > 0) {
            html += '<span style="padding:4px 10px;border-radius:4px;background:#dc3545;color:#fff;border:2px solid #842029;font-size:11px;font-weight:700;">↩ Refer Back <strong>' + referBackCount + '</strong></span>';
        }
        html += '<span style="padding:4px 10px;border-radius:4px;background:' + pctBg + ';color:' + pctColor + ';border:1px solid ' + pctColor + ';font-size:11px;font-weight:700;">คะแนนรวม ' + pct + '%</span>';
        html += '</div>';

        html += '<div style="font-size:10px;color:#666;margin-bottom:8px;">';
        html += '<span style="display:inline-block;width:12px;height:12px;background:#d1e7dd;border:1px solid #198754;vertical-align:middle;margin-right:4px;"></span> ตรง 100% &nbsp; ';
        html += '<span style="display:inline-block;width:12px;height:12px;background:#fff3cd;border:1px solid #fd7e14;vertical-align:middle;margin-right:4px;"></span> ใกล้เคียง ≥90% &nbsp; ';
        html += '<span style="display:inline-block;width:12px;height:12px;background:#f8d7da;border:1px solid #dc3545;vertical-align:middle;margin-right:4px;"></span> ไม่พบในบัญชี &nbsp; ';
        html += '<span style="display:inline-block;padding:1px 5px;background:#dc3545;color:#fff;font-weight:700;font-size:9px;border-radius:3px;vertical-align:middle;margin-right:4px;">↩ REFER BACK</span> ต้องส่งต่อยาไป รพ.ปลายทาง';
        html += '</div>';
        return html;
    }

    function renderMatchDetailPrintRowsHtml(rows) {
        const td = 'style="border:1px solid #ccc;padding:5px 6px;vertical-align:top;"';
        const tdCenter = 'style="border:1px solid #ccc;padding:5px 6px;vertical-align:top;text-align:center;"';
        let body = '';
        (rows || []).forEach(function (r) {
            const match = r.match || {};
            const colors = matchStatusPrintColors(match);
            const referBack = needsReferBack(match);
            const rowStyle = referBack
                ? 'background:' + colors.row + ';border-left:4px solid #dc3545;'
                : 'background:' + colors.row + ';';
            const score = match.status === 'exact' ? '100' : (match.status === 'none' ? '-' : esc(String(match.score || 0)));
            let suggest = '';
            const suggestions = match.suggestions || [];
            if (match.status !== 'exact' && suggestions.length) {
                suggest = '<div style="color:#664d03;font-size:10px;margin-top:3px;background:#fff8e6;padding:2px 4px;border-radius:3px;">แนะนำ: ' +
                    suggestions.slice(0, 2).map(function (x) {
                        return esc(renderSuggestLabel(x)) + ' (' + x.score + '%)';
                    }).join(', ') +
                    '</div>';
            }
            body += '<tr style="' + rowStyle + '">' +
                '<td ' + tdCenter + '>' + renderReferBackPrintBadge(match) + '</td>' +
                '<td ' + td + '>' + renderDrugNamePrint(r) + '</td>' +
                '<td ' + tdCenter + '>' + renderMatchStatusPrintBadge(match) + '</td>' +
                '<td ' + td + '>' + renderMatchedNamePrint(match) + suggest + '</td>' +
                '<td ' + tdCenter + '><strong style="color:' + colors.text + ';">' + score + '</strong></td>' +
                '</tr>';
        });
        return body;
    }

    function printStyles() {
        let html = '<style>';
        html += '@page { size: A4 portrait; margin: 12mm 10mm; }';
        html += '* { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }';
        html += '.print-match-doc { font-family: Sarabun, Tahoma, sans-serif; font-size: 12px; color: #000; width: 100%; }';
        html += '.print-match-doc h4 { margin: 0 0 10px; color: #1f4d3b; font-size: 15px; border-bottom: 2px solid #1f4d3b; padding-bottom: 6px; }';
        html += '.print-match-doc table { width: 100%; border-collapse: collapse; font-size: 10.5px; table-layout: fixed; }';
        html += '.print-match-doc th { border: 1px solid #1f4d3b; padding: 5px 6px; background: #1f4d3b; color: #fff; text-align: left; }';
        html += '.print-match-doc th.col-referback { text-align: center; width: 54px; background: #dc3545; border-color: #842029; }';
        html += '.print-match-doc th.col-status, .print-match-doc th.col-score { text-align: center; width: 62px; }';
        html += '.print-match-doc th.col-drug { width: 32%; }';
        html += '.print-match-doc th.col-match { width: 32%; }';
        html += '</style>';
        return html;
    }

    function buildMatchDetailPrintHtml(res, options) {
        options = options || {};
        const hn = (res.patient && res.patient.hn) ? res.patient.hn : '';
        const hospital = (res.hospital || '').trim();
        const matchColLabel = options.matchColLabel || formatMatchColHeader(hospital);
        const footerLabel = options.footerLabel || 'THAM Hos-Safe';
        const includeReferBackHeader = options.includeReferBackHeader !== false;
        const title = options.title || ('รายละเอียดการเทียบบัญชียา' + (hn ? ' — HN ' + esc(hn) : ''));

        let html = printStyles();
        html += '<div class="print-match-doc">';
        html += '<h4>' + title + '</h4>';
        if (includeReferBackHeader) html += renderReferBackPrintHeader(res);
        html += renderMatchDetailPrintSummaryHtml(res);
        html += '<table><thead><tr>';
        html += '<th class="col-referback">↩<br>Refer<br>Back</th>';
        html += '<th class="col-drug">รายการยา</th>';
        html += '<th class="col-status">ผลเทียบ</th>';
        html += '<th class="col-match">' + matchColLabel + '</th>';
        html += '<th class="col-score">คะแนน</th>';
        html += '</tr></thead><tbody>' + renderMatchDetailPrintRowsHtml(res.rows || []) + '</tbody></table>';
        html += renderMatchDetailPrintSignoffHtml();
        html += '<p style="margin-top:14px;font-size:10px;color:#666;">พิมพ์เมื่อ ' + esc(new Date().toLocaleString('th-TH')) + ' | ' + esc(footerLabel) + '</p></div>';
        return html;
    }

    function adaptCompareForHospital(data, hospital, minSimilar) {
        minSimilar = minSimilar == null ? 90 : minSimilar;
        const rows = (data.rows || []).map(function (r) {
            const match = (r.hospitals && r.hospitals[hospital]) ? r.hospitals[hospital] : { status: 'none', score: 0 };
            return Object.assign({}, r, { match: match });
        });
        let exact = 0;
        let similar = 0;
        let missing = 0;
        let matched = 0;
        rows.forEach(function (r) {
            const m = r.match || {};
            if (m.status === 'exact') {
                exact++;
                matched++;
            } else if (m.status === 'similar' && (m.score || 0) >= minSimilar) {
                similar++;
                matched++;
            } else {
                missing++;
            }
        });
        const total = rows.length;
        const patient = Object.assign({}, data.patient || {});
        if (!patient.hn && data.visit) patient.hn = data.visit.hn || '';
        return {
            patient: patient,
            hospital: hospital,
            summary: {
                total: total,
                exact: exact,
                similar: similar,
                missing: missing,
                matched: matched,
                match_percent: total ? Math.round(matched / total * 100) : 0
            },
            rows: rows,
            ref_visit: {
                visit_label: (data.visit && data.visit.visit_label) || '',
                filter_note: (data.visit && data.visit.filter_note) || ''
            }
        };
    }

    function buildMultiHospitalComparePrintHtml(data, options) {
        options = options || {};
        const hospitals = data.hospitals || [];
        if (!hospitals.length) return '';
        let html = '';
        hospitals.forEach(function (hos, i) {
            if (i > 0) html += '<div style="page-break-before:always;"></div>';
            html += buildMatchDetailPrintHtml(adaptCompareForHospital(data, hos, options.minSimilar), options);
        });
        return html;
    }

    global.DrugReferMatchPrint = {
        esc: esc,
        formatMatchColHeader: formatMatchColHeader,
        buildMatchDetailPrintHtml: buildMatchDetailPrintHtml,
        adaptCompareForHospital: adaptCompareForHospital,
        buildMultiHospitalComparePrintHtml: buildMultiHospitalComparePrintHtml
    };
})(typeof window !== 'undefined' ? window : this);
