<?php
/* 보고서 PPT 생성.
   점검보고서(정기/특별): A4 세로, 장비 수에 따라 3가지 레이아웃
     - single (1대)   : 1대 상세형 – 건강점수 게이지, 용량 막대, 볼륨/진단/육안점검을 2페이지로
     - multi  (2~3대) : 개요 1p + 장비별 상세 1p씩 + 종합의견 1p
     - fleet  (4대+)  : 전체 대시보드 + 장비 2대/페이지 요약 + 점검 매트릭스·종합의견
   작업계획서: 16:9 가로, 표지/목차/개요/대상장비/상세절차/영향·일정/리스크/롤백/사전점검·의견 */

const A4_W = 7560310; const A4_H = 10692130; const WIDE_W = 12192000; const WIDE_H = 6858000;
function M_() { return cm(1.0); }

function p_header($s, $ctx, $ko, $en, $sw) {
    // 표지 머리: 옅은 회청색 바탕 + 남색 글자 (진한 띠 없이)
    k_rect($s, 0, 0, $sw, cm(2.5), C_LIGHT);
    k_rect($s, 0, cm(2.5), $sw, cm(0.06), C_SKY);
    k_rect($s, M_(), cm(0.55), cm(0.12), cm(1.4), C_BLUE);
    k_text($s, M_() + cm(0.35), cm(0.42), $sw - cm(9), cm(1.0), $ko, 19, true, C_NAVY);
    k_text($s, M_() + cm(0.35), cm(1.4), $sw - cm(9), cm(0.6), $en . '  ·  ' . $ctx['customer']['name'] . '  ·  ' . $ctx['report']['date'], 9, false, C_GREY);
    k_logo($s, $sw - M_() - cm(4.4), cm(0.95), cm(4.4));
    k_text($s, $sw - M_() - cm(5.6), cm(1.5), cm(5.6), cm(0.5), 'No. ' . $ctx['report']['no'], 8, true, C_GREY, 'r', 'm');
    return cm(3.0);
}
function p_page_header($s, $ctx, $title, $sw) {
    k_rect($s, M_(), cm(0.55), cm(0.12), cm(0.75), C_BLUE);
    k_text($s, M_() + cm(0.3), cm(0.45), $sw - cm(9), cm(0.95), $title, 13, true, C_NAVY, 'l', 'm');
    k_logo($s, $sw - M_() - cm(2.6), cm(0.925), cm(2.6));
    k_text($s, $sw - M_() - cm(10.2), cm(0.5), cm(7.2), cm(0.85), $ctx['customer']['name'] . ' · ' . $ctx['report']['date'], 8, false, C_GREY, 'r', 'm');
    k_line($s, M_(), cm(1.55), $sw - M_(), cm(1.55), C_LINE, 0.75);
    return cm(1.9);
}
function p_customer($s, $ctx, $y, $sw) {
    $W = $sw - 2 * M_();
    $y = k_section($s, M_(), $y, $W, '고객 정보 (Customer Info)');
    $c = $ctx['customer'];
    $rows = [['Customer', $c['name'], 'Date', $ctx['report']['date']],
        ['Location', clip($c['site'] ?: $c['address'], 40), 'Contact', clip(implode(' / ', array_filter([$c['contact_name'], $c['contact_phone']])), 32)]];
    if ($ctx['report']['service_codes']) {
        $codes = implode('  ', array_map(function ($x) use ($ctx) { return (in_array($x, $ctx['report']['service_codes'], true) ? '■ ' : '□ ') . $x; }, SERVICE_CODES));
        $rows[] = ['Service', ['v' => $codes, 'span' => 3]];
    }
    return k_table($s, M_(), $y, $W, $rows, ['col_w' => [1.3, 3.2, 1.0, 2.5], 'header' => false, 'label_cols' => [0, 2], 'align' => ['c', 'l', 'c', 'l']]) + cm(0.35);
}
function p_equipment_list($s, $ctx, $y, $sw, $compact = false) {
    $y = k_section($s, M_(), $y, $sw - 2 * M_(), '점검 장비 정보 (Equipment Info)');
    $rows = [['No.', 'System', 'Model', 'System S/N', 'OS Ver', 'Disk', 'Status']];
    foreach ($ctx['equipments'] as $e) $rows[] = [$e['seq'], clip($e['name'], 16), clip($e['model'], 18), clip($e['serial'], 30), clip($e['os_version'], 14), clip($e['disk_info'], 16), STATUS_TEXT[$e['analysis']['status']]];
    return k_table($s, M_(), $y, $sw - 2 * M_(), $rows, ['col_w' => [0.5, 1.5, 1.6, 2.6, 1.2, 1.4, 1.0], 'size' => $compact ? 7.5 : 8, 'row_h' => cm($compact ? 0.55 : 0.6), 'status_col' => 6]) + cm(0.35);
}
function p_aggr_rows($e, $lim) {
    $rows = [['Node', 'Aggr Name', 'Total', 'Used', 'Used (%)', 'Available']];
    foreach (array_slice($e['parsed']['aggregates'] ?? [], 0, $lim) as $a)
        $rows[] = [clip($a['node'] ?? '', 14), clip($a['name'], 18), human_gb($a['total_gb']), human_gb($a['used_gb']), $a['pct'] . '%', human_gb($a['avail_gb'])];
    if (count($rows) === 1) $rows[] = ['-', '로그 데이터 없음', '-', '-', '-', '-'];
    return $rows;
}
function p_vol_rows($e, $lim) {
    $rows = [['SVM / Node', 'Volume', 'Total', 'Used', 'Used (%)', 'Inode (%)', 'Remarks']];
    foreach (array_slice($e['parsed']['volumes'] ?? [], 0, $lim) as $v)
        $rows[] = [clip($v['node'] ?? '', 12), clip($v['name'], 18), human_gb($v['total_gb']), human_gb($v['used_gb']), $v['pct'] . '%', isset($v['inode_pct']) ? $v['inode_pct'] . '%' : '-', vol_remark($v['pct'])];
    if (count($rows) === 1) $rows[] = ['-', '로그 데이터 없음', '-', '-', '-', '-', '-'];
    return $rows;
}
function p_find_rows($e, $lim) {
    $rows = [['등급', '구분', '진단 내용', '권고 조치']];
    foreach (array_slice($e['analysis']['findings'], 0, $lim) as $f) $rows[] = [STATUS_TEXT[$f['severity']], $f['category'], clip($f['title'] . ' - ' . $f['detail'], 60), clip($f['recommendation'], 44)];
    if (count($rows) === 1) $rows[] = ['Normal', '-', '주요 이상 징후가 발견되지 않았습니다.', '정기 모니터링 유지'];
    return $rows;
}
function p_kpis($s, $e, $x, $y, $w) {
    $a = $e['analysis']; $p = $e['parsed']; $g = cm(3.0);
    k_donut($s, $x, $y, $g, $a['score'], 'Health Score', null, $a['status_label']);
    $cx = $x + $g + cm(0.4); $cw = (int)(($w - $g - cm(0.4) - cm(0.3) * 3) / 4); $cap = $a['capacity'];
    $failed = count($p['disks']['failed'] ?? []);
    $vals = [
        [$cap['total_gb'] ? $cap['pct'] . '%' : '-', $cap['total_gb'] ? "Data 용량 사용률\n" . human_gb($cap['used_gb']) . ' / ' . human_gb($cap['total_gb']) : 'Data 용량 사용률', $cap['pct'] >= 95 ? C_RED : ($cap['pct'] >= 85 ? C_AMBER : C_BLUE)],
        [(string)$a['counts']['critical'], 'Critical 진단', $a['counts']['critical'] ? C_RED : C_GREEN],
        [(string)$a['counts']['warning'], 'Warning 진단', $a['counts']['warning'] ? C_AMBER : C_GREEN],
        [(string)$failed, "Failed Disk\nSpare " . ($p['disks']['spare'] ?? 0) . 'EA', $failed ? C_RED : C_GREEN],
    ];
    foreach ($vals as $i => [$v, $l, $c]) k_kpi($s, $cx + $i * ($cw + cm(0.3)), $y + cm(0.2), $cw, cm(2.4), $v, $l, $c, $c);
    return $y + $g + cm(0.6);
}
function p_visual_single($s, $ctx, $e, $y, $sw) {
    $y = k_section($s, M_(), $y, $sw - 2 * M_(), '주요 점검 항목 – 육안 점검 (Main Inspection Items)');
    $vis = $e['visual']; $rows = [];
    for ($i = 0; $i < count(VISUAL_ITEMS); $i += 2) {
        $r = [];
        foreach (array_slice(VISUAL_ITEMS, $i, 2) as [$k, $label]) {
            $v = $vis[$k] ?? '';
            $r[] = $label; $r[] = ($v === '정상' ? '■ 정상' : '□ 정상') . '   ' . ($v === '비정상' ? '■ 비정상' : '□ 비정상') . ($v === 'N/A' ? '   ■ N/A' : '');
        }
        $rows[] = $r;
    }
    $tint = function ($r, $c, $v) { return ($c % 2 === 1 && mb_strpos($v, '■ 비정상') !== false) ? C_RED : null; };
    return k_table($s, M_(), $y, $sw - 2 * M_(), $rows, ['col_w' => [2.2, 2.0, 2.2, 2.0], 'header' => false, 'label_cols' => [0, 2], 'size' => 8, 'row_h' => cm(0.55), 'tint' => $tint]) + cm(0.35);
}
function vis_mark($v) { return ['정상' => '● 정상', '비정상' => '▲ 비정상', 'N/A' => 'N/A'][$v] ?? '-'; }
function vis_tint($r, $c, $v) { return mb_strpos($v, '▲') !== false ? C_RED : (mb_strpos($v, '●') !== false ? C_GREEN : null); }
function p_check_matrix($s, $ctx, $y, $sw) {
    $y = k_section($s, M_(), $y, $sw - 2 * M_(), '로그 기반 자동 점검 매트릭스 (Automated Check Matrix)');
    $eqs = $ctx['equipments']; $n = count($eqs);
    $rows = [array_merge(['Category', 'Inspection Item'], array_map(function ($e) { return '#' . $e['seq']; }, $eqs), ['Standard / Criteria'])];
    $last = '';
    foreach (INSPECTION_MATRIX as [$cat, $item, $crit]) {
        $r = [$cat !== $last ? $cat : '', $item]; $last = $cat;
        foreach ($eqs as $e) $r[] = $e['checks'][$item] ?? '-';
        $r[] = $crit; $rows[] = $r;
    }
    $tint = function ($r, $c, $v) use ($n) { if ($c < 2 || $c >= 2 + $n) return null; return ['✔' => C_GREEN, '!' => C_AMBER, '✖' => C_RED][$v] ?? C_GREY; };
    return k_table($s, M_(), $y, $sw - 2 * M_(), $rows, ['col_w' => array_merge([1.3, 2.2], array_fill(0, $n, 0.6), [2.8]), 'size' => 7, 'row_h' => cm(0.42), 'label_cols' => [0],
        'align' => array_merge(['c', 'l'], array_fill(0, $n, 'c'), ['l']), 'tint' => $tint]) + cm(0.3);
}
/* cDOT 점검 명령어 결과표 — 정기: 평시 항목 / 특별: 평시 + 상세 항목 */
const CK_MARK = ['normal' => '✔', 'warning' => '!', 'critical' => '✖', 'info' => 'i', 'missing' => '-'];
function has_cmd_checks($ctx) { foreach ($ctx['equipments'] as $e) if (!empty($e['cmd_checks'])) return true; return false; }
function p_cmd_checks($s, $ctx, $y, $sw, $from = 0, $lim = 99) {
    $m = M_(); $W = $sw - 2 * $m; $eqs = $ctx['equipments']; $n = count($eqs);
    $base = null; foreach ($eqs as $e) if ($e['cmd_checks']) { $base = $e['cmd_checks']; break; }
    $items = array_slice($base, $from, $lim);
    $sp = $ctx['report']['type'] === 'special';
    $y = k_section($s, $m, $y, $W, $from ? '상세 점검 항목 결과 (특별점검 추가 항목)' : ($sp ? '평시 점검 항목 결과 (특별점검 = 평시 + 상세)' : '평시 점검 항목 결과 (정기점검)'));
    if ($n === 1) {
        $rows = [['구분', '항목', '명령어', '결과', '확인 내용']]; $last = '';
        foreach ($items as $c) { $rows[] = [($c['kind'] === 'detail' ? '상세 · ' : '') . ($c['group'] !== $last ? $c['group'] : ''), clip($c['point'], 30), clip($c['cmd'], 34), STATUS_TEXT[$c['status']] ?? '미수집', clip($c['summary'], 46)]; $last = $c['group']; }
        return k_table($s, $m, $y, $W, $rows, ['col_w' => [1.3, 2.2, 2.4, 0.8, 3.1], 'size' => 6.5, 'row_h' => cm(0.4), 'status_col' => 3, 'align' => ['l', 'l', 'l', 'c', 'l']]) + cm(0.3);
    }
    $rows = [array_merge(['구분', '항목 (명령어)'], array_map(function ($e) { return '#' . $e['seq']; }, $eqs), ['확인 포인트'])]; $last = '';
    foreach ($items as $i => $c) {
        $r = [($c['kind'] === 'detail' ? '상세·' : '') . ($c['group'] !== $last ? $c['group'] : ''), clip($c['cmd'], 36)]; $last = $c['group'];
        foreach ($eqs as $e) { $x = null; foreach ($e['cmd_checks'] as $cc) if ($cc['id'] === $c['id']) { $x = $cc; break; } $r[] = CK_MARK[$x['status'] ?? 'missing']; }
        $r[] = clip($c['point'], 34); $rows[] = $r;
    }
    $tint = function ($r, $c, $v) use ($n) { if ($c < 2 || $c >= 2 + $n) return null; return ['✔' => C_GREEN, '!' => C_AMBER, '✖' => C_RED][$v] ?? C_GREY; };
    $y = k_table($s, $m, $y, $W, $rows, ['col_w' => array_merge([1.4, 3.0], array_fill(0, $n, 0.5), [2.8]), 'size' => 6.5, 'row_h' => cm(0.38), 'label_cols' => [0],
        'align' => array_merge(['l', 'l'], array_fill(0, $n, 'c'), ['l']), 'tint' => $tint]);
    k_text($s, $m, $y + cm(0.05), $W, cm(0.4), '✔ 정상  ! 주의  ✖ 위험  i 참고  - 로그에 없음 (해당 명령어 출력을 로그에 포함해 주세요)', 6.5, false, C_GREY);
    return $y + cm(0.55);
}
function p_sign($s, $ctx, $y, $sw) {
    $eng = $ctx['engineer'];
    $rows = [['회사명', $ctx['company']['name'], '연락처', $eng['phone'] ?: ($ctx['company']['tel'] ?? '')],
        ['점검자', trim($eng['name'] . ' ' . $eng['title']) . ' (서명)', '고객확인', '(서명)']];
    return k_table($s, M_(), $y, $sw - 2 * M_(), $rows, ['col_w' => [1.2, 3.0, 1.2, 2.6], 'header' => false, 'label_cols' => [0, 2], 'size' => 8.5, 'row_h' => cm(0.65)]);
}
function p_opinion($s, $ctx, $y, $sw, $bottom, $title = "엔지니어 진단 의견 (Engineer's Opinion)") {
    $W = $sw - 2 * M_();
    $y = k_section($s, M_(), $y, $W, $title);
    $st = $ctx['report']['overall_status'] ?: 'normal';
    $chars = (int)($W / cm(0.30));
    $need = 0; foreach (explode("\n", $ctx['diagnosis']) as $ln) $need += max(1, (int)ceil(mb_strlen($ln) / $chars));
    $avail = max(cm(2.0), $bottom - $y - cm(4.3));
    $box = $bottom < cm(100) ? $avail : max(cm(3.0), cm(0.42) * $need + cm(0.5)); // 마지막 페이지에서는 남은 공간을 의견란으로 채움
    k_rect($s, M_(), $y, $W, $box, C_WHITE, C_LINE);
    k_rect($s, M_(), $y, cm(0.15), $box, STATUS_COLOR[$st]);
    $lines = (int)(($box - cm(0.3)) / cm(0.42));
    k_text($s, M_() + cm(0.35), $y + cm(0.15), $W - cm(0.5), $box - cm(0.2), fit_lines($ctx['diagnosis'], $lines, $chars), 8.5, false, C_INK, 'l', 't', 1.1);
    $y += $box + cm(0.25);
    k_rect($s, M_(), $y, $W, cm(1.6), C_LIGHT);
    k_text($s, M_() + cm(0.3), $y + cm(0.1), cm(4), cm(0.5), '엔지니어 최종 Comment', 8.5, true, C_BLUE);
    k_text($s, M_() + cm(0.3), $y + cm(0.55), $W - cm(0.6), cm(1.0), fit_lines($ctx['final_comment'], 2, $chars), 8.5, false, C_INK);
    $y += cm(1.8);
    return p_sign($s, $ctx, $y, $sw);
}
/* 육안 점검(왼쪽) + 종합 진단 의견(오른쪽)을 나란히, 그 아래 최종 Comment·서명 — 여러 대 보고서의 마지막 부분을 한 덩어리로 */
function p_result_side($s, $ctx, $y, $sw, $bottom) {
    $m = M_(); $W = $sw - 2 * $m; $eqs = $ctx['equipments']; $n = count($eqs);
    $y = k_section($s, $m, $y, $W, "육안 점검 및 종합 엔지니어 의견 (Inspection & Engineer's Opinion)");
    $lw = (int)($W * 0.42); $gap = cm(0.3); $rw = $W - $lw - $gap;
    $short = $n > 2; // 장비가 많으면 ● / ▲ 기호만 (범례는 머리글에)
    $rows = [array_merge([$short ? '육안 점검 (● 정상 ▲ 비정상)' : '육안 점검 항목'], array_map(function ($e) { return '#' . $e['seq']; }, $eqs))];
    foreach (VISUAL_ITEMS as [$k, $label]) { $r = [$label]; foreach ($eqs as $e) { $v = vis_mark($e['visual'][$k] ?? ''); $r[] = $short ? (explode(' ', $v)[0]) : $v; } $rows[] = $r; }
    $vb = k_table($s, $m, $y, $lw, $rows, ['col_w' => array_merge([3.0], array_fill(0, $n, $short ? 0.55 : 1.1)), 'size' => 7, 'row_h' => cm(0.42), 'label_cols' => [0],
        'align' => array_merge(['l'], array_fill(0, $n, 'c')), 'tint' => 'vis_tint']);
    $chars = (int)($rw / cm(0.29)); $need = 0; foreach (explode("\n", $ctx['diagnosis']) as $ln) $need += max(1, (int)ceil(mb_strlen($ln) / $chars));
    $box = max($vb - $y, min($bottom - $y - cm(3.6), cm(0.4) * $need + cm(0.5)));
    $st = $ctx['report']['overall_status'] ?: 'normal'; $rx = $m + $lw + $gap;
    k_rect($s, $rx, $y, $rw, $box, C_PALE, C_LINE);
    k_rect($s, $rx, $y, cm(0.12), $box, STATUS_COLOR[$st]);
    k_text($s, $rx + cm(0.3), $y + cm(0.12), $rw - cm(0.45), $box - cm(0.2), fit_lines($ctx['diagnosis'], (int)(($box - cm(0.3)) / cm(0.4)), $chars), 8, false, C_INK, 'l', 't', 1.1);
    $y = max($vb, $y + $box) + cm(0.25);
    k_rect($s, $m, $y, $W, cm(1.4), C_LIGHT);
    k_text($s, $m + cm(0.3), $y + cm(0.08), cm(5), cm(0.5), '엔지니어 최종 Comment', 8.5, true, C_BLUE);
    k_text($s, $m + cm(0.3), $y + cm(0.5), $W - cm(0.6), cm(0.9), fit_lines($ctx['final_comment'], 2, (int)($W / cm(0.3))), 8.5, false, C_INK);
    return p_sign($s, $ctx, $y + cm(1.6), $sw);
}
function p_system_detail($s, $ctx, $e, $y, $sw, $vl = 10, $al = 6, $fl = 5) {
    $W = $sw - 2 * M_(); $m = M_();
    k_rect($s, $m, $y, $W, cm(1.0), C_LIGHT);
    k_text($s, $m + cm(0.3), $y + cm(0.12), $W - cm(4), cm(0.8), "System {$e['seq']}  |  {$e['vendor']} {$e['model']}  (S/N: " . clip($e['serial'], 40) . ')', 10, true, C_NAVY, 'l', 'm');
    $st = $e['analysis']['status'];
    k_pill($s, $m + $W - cm(3.0), $y + cm(0.22), cm(2.7), cm(0.56), STATUS_TEXT[$st], STATUS_COLOR[$st]);
    $y += cm(1.3);
    $y = p_kpis($s, $e, $m, $y, $W);
    $y = k_section($s, $m, $y, $W, 'Aggregate 현황');
    $aggs = array_slice($e['parsed']['aggregates'] ?? [], 0, $al);
    $ao = ['col_w' => [1.3, 1.6, 1.0, 1.0, 0.9, 1.0], 'size' => 7.5, 'row_h' => cm(0.55)];
    if ($aggs) {
        $tw = (int)($W * 0.6);
        $b = k_table($s, $m, $y, $tw, p_aggr_rows($e, $al), $ao);
        $ch = max($b - $y, cm(2.6));
        k_bars($s, $m + $tw + cm(0.3), $y, $W - $tw - cm(0.3), $ch, array_column($aggs, 'name'), array_column($aggs, 'pct'));
        $y = max($b, $y + $ch) + cm(0.3);
    } else $y = k_table($s, $m, $y, $W, p_aggr_rows($e, $al), $ao) + cm(0.3);
    // 1대 보고서에는 SVM 볼륨 현황 표를 넣지 않습니다 (요청 사항). 볼륨 상세는 엑셀 보고서에 있습니다.
    $y = k_section($s, $m, $y, $W, '로그 분석 진단 (Diagnostic Findings)');
    return k_table($s, $m, $y, $W, p_find_rows($e, $fl), ['col_w' => [0.8, 0.9, 4.2, 2.8], 'size' => 7.5, 'row_h' => cm(0.62), 'status_col' => 0, 'align' => ['c', 'c', 'l', 'l']]) + cm(0.3);
}
function p_system_compact($s, $ctx, $e, $y, $sw, $vl = 5, $fl = 3) {
    $W = $sw - 2 * M_(); $m = M_();
    k_rect($s, $m, $y, $W, cm(0.9), C_LIGHT);
    k_text($s, $m + cm(0.3), $y + cm(0.1), $W - cm(4), cm(0.7), "System {$e['seq']}  |  {$e['vendor']} {$e['model']}  (S/N: " . clip($e['serial'], 36) . ')', 9.5, true, C_NAVY, 'l', 'm');
    $st = $e['analysis']['status'];
    k_pill($s, $m + $W - cm(2.9), $y + cm(0.17), cm(2.6), cm(0.55), STATUS_TEXT[$st] . ' · ' . $e['analysis']['score'], STATUS_COLOR[$st], C_WHITE, 8);
    $y += cm(1.15); $tw = (int)($W * 0.62);
    $b1 = k_table($s, $m, $y, $tw, p_aggr_rows($e, 4), ['col_w' => [1.3, 1.6, 1.0, 1.0, 0.9, 1.0], 'size' => 7, 'row_h' => cm(0.5)]);
    $aggs = array_slice($e['parsed']['aggregates'] ?? [], 0, 4);
    if ($aggs) k_bars($s, $m + $tw + cm(0.3), $y, $W - $tw - cm(0.3), max($b1 - $y, cm(2.4)), array_column($aggs, 'name'), array_column($aggs, 'pct'));
    $y = max($b1, $y + cm(2.4)) + cm(0.2);
    $y = k_table($s, $m, $y, $W, p_vol_rows($e, $vl), ['col_w' => [1.2, 1.8, 1.0, 1.0, 0.8, 0.8, 1.0], 'size' => 7, 'row_h' => cm(0.46), 'status_col' => 6]) + cm(0.2);
    $y = k_table($s, $m, $y, $W, p_find_rows($e, $fl), ['col_w' => [0.8, 0.9, 4.2, 2.8], 'size' => 7, 'row_h' => cm(0.55), 'status_col' => 0, 'align' => ['c', 'c', 'l', 'l']]);
    return $y + cm(0.6);
}
/* 성능 세부 분석 (perf_archive) — 지표 표 + 해석. 성능 아카이브가 있는 장비만 */
const PERF_GROUP_KO = ['cpu' => 'CPU', 'latency' => '지연시간', 'ops' => 'IOPS', 'throughput' => '처리량', 'disk' => '디스크', 'net' => '네트워크', 'other' => '기타'];
function perf_num($v) { if ($v === null) return '-'; $a = abs($v); return $a >= 1000 ? number_format($v, 0) : ($a >= 10 ? number_format($v, 1) : number_format($v, 2)); }
/* 서비스 코드별 작업 내역 (Repair·Install·Upgrade 등) */
function p_service($s, $ctx, $y, $sw) {
    $m = M_(); $W = $sw - 2 * $m;
    foreach ($ctx['report']['service'] as $sv) {
        $y = k_section($s, $m, $y, $W, "서비스 작업 내역 – {$sv['title']}");
        $y = k_table($s, $m, $y, $W, array_map(function ($r) { return [$r[0], clip($r[1], 160)]; }, $sv['rows']),
            ['col_w' => [1.3, 6.7], 'header' => false, 'label_cols' => [0], 'size' => 8, 'row_h' => cm(0.62), 'align' => ['c', 'l']]) + cm(0.3);
    }
    return $y;
}
/* 시스템 사용률 (CPU · 디스크 · 네트워크) — 정기·특별 공통, 로그의 sysstat/ifstat 출력 기준 */
function p_util($s, $ctx, $e, $y, $sw, $lim = 8) {
    $u = $e['util'] ?? null; if (!$u) return $y;
    [$n, $i] = util_tables($u, $lim); if (!$n && !$i) return $y;
    $m = M_(); $W = $sw - 2 * $m;
    $y = k_section($s, $m, $y, $W, "시스템 사용률 (CPU · Disk · Network) – System {$e['seq']}");
    if ($n) $y = k_table($s, $m, $y, $W, $n, ['col_w' => [1.6, 1.4, 1.4, 1.9, 0.8], 'size' => 7, 'row_h' => cm(0.45), 'status_col' => 4, 'align' => ['l', 'c', 'c', 'c', 'c']]) + cm(0.15);
    if ($i) $y = k_table($s, $m, $y, $W, $i, ['col_w' => [1.5, 1.4, 0.7, 1.0, 1.0, 0.8, 0.8], 'size' => 7, 'row_h' => cm(0.42), 'status_col' => 6, 'align' => ['l', 'l', 'c', 'r', 'r', 'r', 'c']]) + cm(0.1);
    k_text($s, $m, $y - cm(0.05), $W, cm(0.4), '※ 60% 이상 요주의, 80% 이상 위험 · 네트워크 사용률 = max(수신, 송신) ÷ 링크 속도', 6.5, false, C_GREY);
    return $y + cm(0.45);
}
function p_perf($s, $ctx, $e, $y, $sw, $rowsLim = 10, $textLines = 14) {
    $pf = $e['perf']; if (!$pf) return $y;
    $m = M_(); $W = $sw - 2 * $m;
    $y = k_section($s, $m, $y, $W, "성능 세부 분석 (Performance Archive) – System {$e['seq']}");
    $per = ($pf['period']['from'] ?? '') ? ' · 수집 기간 ' . substr($pf['period']['from'], 0, 16) . ' ~ ' . substr($pf['period']['to'] ?? '', 0, 16) : '';
    k_text($s, $m, $y - cm(0.05), $W - cm(3.2), cm(0.45), clip($pf['file'], 50) . " (업로드 {$pf['uploaded']})" . $per, 7.5, false, C_GREY);
    $st = $pf['status'];
    k_pill($s, $m + $W - cm(3.0), $y - cm(0.07), cm(3.0), cm(0.5), '성능 ' . STATUS_TEXT[$st], STATUS_COLOR[$st], C_WHITE, 8);
    $y += cm(0.55);
    $kpi = (array)$pf['kpi']; $ks = [];
    if (isset($kpi['cpu'])) $ks[] = [perf_num($kpi['cpu']) . '%', 'CPU 평균(최대 노드)'];
    if (isset($kpi['latency_ms'])) $ks[] = [perf_num($kpi['latency_ms']) . ' ms', '평균 지연(최대)'];
    if (isset($kpi['ops'])) $ks[] = [perf_num($kpi['ops']), 'IOPS 평균(최대)'];
    if (isset($kpi['disk_util'])) $ks[] = [perf_num($kpi['disk_util']) . '%', '디스크 사용률 평균'];
    if ($ks) {
        $kw = (int)(($W - cm(0.3) * (count($ks) - 1)) / count($ks));
        foreach ($ks as $i => [$v, $l]) k_kpi($s, $m + $i * ($kw + cm(0.3)), $y, $kw, cm(1.45), $v, $l);
        $y += cm(1.7);
    }
    $tw = (int)($W * 0.52); $gap = cm(0.3); $rw = $W - $tw - $gap;
    if ($pf['metrics']) {
        $rows = [['구분', '오브젝트 : 카운터', '평균', '95%', '최대', '단위']];
        foreach (array_slice($pf['metrics'], 0, $rowsLim) as $x) $rows[] = [PERF_GROUP_KO[$x['group']] ?? $x['group'], clip(($x['object'] ? $x['object'] . ' : ' : '') . $x['counter'], 46), perf_num($x['avg']), perf_num($x['p95']), perf_num($x['max']), $x['unit'] ?: '-'];
        $b = k_table($s, $m, $y, $tw, $rows, ['col_w' => [0.8, 3.2, 0.8, 0.8, 0.8, 0.6], 'size' => 6.5, 'row_h' => cm(0.42), 'align' => ['c', 'l', 'r', 'r', 'r', 'c']]);
    } else {
        // 카운터 값이 없는 아카이브: 함께 들어 있는 AutoSupport 시스템 정보로 점검표를 만듭니다
        $rows = [['점검 항목', '아카이브에서 확인한 내용', '결과']];
        foreach (perf_system_rows($pf) as [$k, $v, $st2]) $rows[] = [$k, clip($v, 60), ['normal' => '정상', 'warning' => '요주의', 'critical' => '위험', 'info' => '참고'][$st2]];
        $b = k_table($s, $m, $y, $tw, $rows, ['col_w' => [1.1, 3.4, 0.7], 'size' => 6.8, 'row_h' => cm(0.46), 'align' => ['l', 'l', 'c'], 'status_col' => 2]);
    }
    if ($pf['findings']) {
        $fr = [['등급', '성능 아카이브 진단', '권고 조치']];
        foreach (array_slice($pf['findings'], 0, 6) as $f) $fr[] = [STATUS_TEXT[$f['severity']] ?? $f['severity'], clip($f['title'] . ($f['detail'] ? ' – ' . $f['detail'] : ''), 70), clip($f['recommendation'], 50)];
        $b = k_table($s, $m, $b + cm(0.2), $tw, $fr, ['col_w' => [0.85, 2.7, 1.75], 'size' => 6.5, 'row_h' => cm(0.5), 'status_col' => 0, 'align' => ['c', 'l', 'l']]);
    }
    $chars = (int)($rw / cm(0.27));
    $box = max($b - $y, min(cm(0.36) * $textLines + cm(0.3), cm(0.36) * max(4, count(explode("\n", $pf['text']))) + cm(0.3)));
    k_rect($s, $m + $tw + $gap, $y, $rw, $box, C_PALE, C_LINE);
    k_rect($s, $m + $tw + $gap, $y, cm(0.12), $box, STATUS_COLOR[$st]);
    k_text($s, $m + $tw + $gap + cm(0.25), $y + cm(0.1), $rw - cm(0.4), $box - cm(0.15), fit_lines($pf['text'], (int)(($box - cm(0.25)) / cm(0.36)), $chars), 7, false, C_INK, 'l', 't', 1.05);
    return max($b, $y + $box) + cm(0.35);
}
/* 점검 매트릭스: cDOT 로그면 고객 점검 명령어 기준 결과표(평시 / 평시+상세), 아니면 기존 자동 매트릭스 */
function flow_checks(&$F, $ctx, $sw) {
    if (!has_cmd_checks($ctx)) { flow_put($F, function ($s, $y) use ($ctx, $sw) { return p_check_matrix($s, $ctx, $y, $sw); }); return; }
    $base = null; foreach ($ctx['equipments'] as $e) if ($e['cmd_checks']) { $base = $e['cmd_checks']; break; }
    $nb = count(array_filter($base, function ($c) { return $c['kind'] === 'base'; })); $nd = count($base) - $nb;
    flow_put($F, function ($s, $y) use ($ctx, $sw, $nb) { return p_cmd_checks($s, $ctx, $y, $sw, 0, $nb); });
    if ($nd) flow_put($F, function ($s, $y) use ($ctx, $sw, $nb, $nd) { return p_cmd_checks($s, $ctx, $y, $sw, $nb, $nd); });
}
function tag_findings($ctx) {
    $out = [];
    foreach ($ctx['equipments'] as $e) foreach ($e['analysis']['findings'] as $f) if ($f['severity'] !== 'info') { $f['_seq'] = $e['seq']; $out[] = $f; }
    usort($out, function ($a, $b) { return ($a['severity'] === 'critical' ? 0 : 1) <=> ($b['severity'] === 'critical' ? 0 : 1); });
    return $out;
}

/* ---------- 흐름 배치: 블록 높이를 미리 재서 남는 공간에 이어 붙이고, 모자랄 때만 새 페이지 ---------- */
function measure_h($sw, $sh, $fn) { $tmp = new Pptx($sw, $sh); $s = $tmp->slide(); return $fn($s, 0); }
function flow_new(&$F) {
    $s = $F['prs']->slide(); $F['pages'][] = $s; $F['s'] = $s;
    $F['y'] = p_page_header($s, $F['ctx'], $F['title'], $F['sw']);
}
function flow_put(&$F, $fn, $gap = 0) {
    $h = measure_h($F['sw'], $F['sh'], $fn);
    if ($F['y'] + $h > $F['bottom']) flow_new($F);
    $F['y'] = $fn($F['s'], $F['y']) + $gap;
}
function p_exec_summary($s, $ctx, $y, $sw) {
    $m = M_(); $W = $sw - 2 * $m; $eqs = $ctx['equipments']; $n = count($eqs);
    $y = k_section($s, $m, $y, $W, 'Executive Summary – 장비별 건강 점수');
    $per = min(5, $n); $cw = (int)(($W - cm(0.3) * ($per - 1)) / $per); $g = min(cm(2.3), $cw - cm(0.6));
    foreach ($eqs as $i => $e) {
        $x = $m + ($i % $per) * ($cw + cm(0.3)); $yy = $y + intdiv($i, $per) * cm(4.0);
        k_rect($s, $x, $yy, $cw, cm(3.7), C_PALE, C_LINE, 'roundRect', 0.05);
        k_text($s, $x, $yy + cm(0.1), $cw, cm(0.45), "#{$e['seq']} " . clip($e['model'], 18), 8, true, C_NAVY, 'c', 'm');
        k_donut($s, $x + (int)(($cw - $g) / 2), $yy + cm(0.6), $g, $e['analysis']['score'], 'Health', null, $e['analysis']['status_label']);
    }
    return $y + (int)ceil($n / $per) * cm(4.0) + cm(0.1);
}
function p_key_issues($s, $ctx, $y, $sw, $lim = 6) {
    $m = M_(); $W = $sw - 2 * $m; $tf = array_slice(tag_findings($ctx), 0, $lim);
    if (!$tf) return $y;
    $y = k_section($s, $m, $y, $W, '핵심 이슈 (Key Issues)');
    $rows = [['등급', '장비', '이슈', '권고']];
    foreach ($tf as $f) $rows[] = [STATUS_TEXT[$f['severity']], '#' . $f['_seq'], clip($f['title'] . ' - ' . $f['detail'], 60), clip($f['recommendation'], 40)];
    return k_table($s, $m, $y, $W, $rows, ['col_w' => [0.8, 0.5, 4.6, 3.0], 'size' => 7.5, 'row_h' => cm(0.52), 'status_col' => 0, 'align' => ['c', 'c', 'l', 'l']]) + cm(0.35);
}
function p_scope($s, $ctx, $y, $sw) {
    $m = M_(); $W = $sw - 2 * $m;
    $y = k_section($s, $m, $y, $W, '점검 범위 (Inspection Scope)');
    $sc = $ctx['scope']; $rows = [];
    for ($i = 0; $i < count($sc); $i += 2) $rows[] = [$sc[$i][0], $sc[$i][1], $sc[$i + 1][0] ?? '', $sc[$i + 1][1] ?? ''];
    return k_table($s, $m, $y, $W, $rows, ['col_w' => [1.1, 3.0, 1.1, 3.0], 'header' => false, 'label_cols' => [0, 2], 'size' => 7.5, 'row_h' => cm(0.5), 'align' => ['c', 'l', 'c', 'l']]) + cm(0.35);
}
function p_fleet_dash($s, $ctx, $y, $sw, $sh) {
    $m = M_(); $W = $sw - 2 * $m; $eqs = $ctx['equipments'];
    $y = k_section($s, $m, $y, $W, 'Fleet Dashboard – 전체 현황');
    $c = count(array_filter($eqs, function ($e) { return $e['analysis']['status'] === 'critical'; }));
    $w_ = count(array_filter($eqs, function ($e) { return $e['analysis']['status'] === 'warning'; }));
    $tot = 0; $used = 0; foreach ($eqs as $e) { $tot += $e['analysis']['capacity']['total_gb']; $used += $e['analysis']['capacity']['used_gb']; }
    $kw = (int)(($W - cm(0.3) * 3) / 4);
    k_kpi($s, $m, $y, $kw, cm(1.7), count($eqs) . '대', '점검 장비');
    k_kpi($s, $m + ($kw + cm(0.3)), $y, $kw, cm(1.7), (string)$c, 'Critical 장비', $c ? C_RED : C_GREEN, $c ? C_RED : C_GREEN);
    k_kpi($s, $m + 2 * ($kw + cm(0.3)), $y, $kw, cm(1.7), (string)$w_, 'Warning 장비', $w_ ? C_AMBER : C_GREEN, $w_ ? C_AMBER : C_GREEN);
    k_kpi($s, $m + 3 * ($kw + cm(0.3)), $y, $kw, cm(1.7), ($tot ? round($used / $tot * 100) : 0) . '%', $tot ? '전체 사용률 ' . human_gb($used) . ' / ' . human_gb($tot) : '전체 사용률');
    $y += cm(2.0);
    $ch = cm(0.62) * count($eqs) + cm(0.8);
    k_bars($s, $m, $y, (int)($W * 0.5), $ch, array_map(function ($e) { return '#' . $e['seq'] . ' ' . clip($e['model'], 14); }, $eqs),
        array_map(function ($e) { return $e['analysis']['capacity']['pct']; }, $eqs), ['title' => '장비별 Data 용량 사용률']);
    k_bars($s, $m + (int)($W * 0.52), $y, (int)($W * 0.48), $ch, array_map(function ($e) { return '#' . $e['seq']; }, $eqs),
        array_map(function ($e) { return $e['analysis']['score']; }, $eqs), ['title' => '장비별 Health Score', 'suffix' => '',
            'colors' => array_map(function ($e) { return STATUS_COLOR[$e['analysis']['status']]; }, $eqs)]);
    return $y + $ch + cm(0.3);
}

function build_inspection($ctx, $path) {
    $prs = new Pptx(A4_W, A4_H); $sw = A4_W; $sh = A4_H; $m = M_(); $W = $sw - 2 * $m;
    $variant = $ctx['report']['variant'];
    $title = str_replace(' 보고서', '', $ctx['report']['type_label']) . ' 보고서';
    $F = ['prs' => $prs, 'pages' => [], 's' => null, 'y' => 0, 'bottom' => $sh - cm(1.25), 'sw' => $sw, 'sh' => $sh, 'ctx' => $ctx, 'title' => '스토리지 운영현황 (Storage Operation Status)'];
    $s = $prs->slide(); $F['pages'][] = $s; $F['s'] = $s;
    $F['y'] = p_header($s, $ctx, $title, 'Storage Service Report', $sw);
    $F['y'] = p_customer($s, $ctx, $F['y'], $sw);
    $eqs = $ctx['equipments']; $SEC = $ctx['report']['sections'] ?? service_sections([]);
    if ($variant === 'single') {
        $e = $eqs[0];
        $F['y'] = k_section($s, $m, $F['y'], $W, '점검 장비 정보 (Equipment Info)');
        $F['y'] = k_table($s, $m, $F['y'], $W, [['Model', "{$e['vendor']} {$e['model']}" . ($e['ha_type'] ? " ({$e['ha_type']})" : ''), 'System S/N', $e['serial']], ['OS Ver', $e['os_version'], 'Disk Type', $e['disk_info']]],
            ['col_w' => [1.2, 2.8, 1.2, 2.8], 'header' => false, 'label_cols' => [0, 2], 'size' => 8, 'row_h' => cm(0.55), 'align' => ['c', 'l', 'c', 'l']]) + cm(0.3);
        foreach ($ctx['report']['service'] as $sv) flow_put($F, function ($s, $y) use ($ctx, $sv, $sw) { $c = $ctx; $c['report']['service'] = [$sv]; return p_service($s, $c, $y, $sw); });
        if ($SEC['system'] ?? false) flow_put($F, function ($s, $y) use ($ctx, $e, $sw) { return p_system_detail($s, $ctx, $e, $y, $sw, 8, 5, 5); });
        if (($SEC['util'] ?? false) && !empty($e['util'])) flow_put($F, function ($s, $y) use ($ctx, $e, $sw) { return p_util($s, $ctx, $e, $y, $sw, 8); });
        if (!empty($e['perf'])) { $F['title'] = '성능 세부 분석 (Performance)'; flow_put($F, function ($s, $y) use ($ctx, $e, $sw) { return p_perf($s, $ctx, $e, $y, $sw, 14, 22); }); }
        $F['title'] = '점검 결과 및 종합 의견';
    } else {
        $F['y'] = p_equipment_list($s, $ctx, $F['y'], $sw, $variant === 'fleet');
        if ($variant === 'multi') {
            $F['y'] = p_scope($s, $ctx, $F['y'], $sw);
            $F['y'] = p_exec_summary($s, $ctx, $F['y'], $sw);
        } else $F['y'] = p_fleet_dash($s, $ctx, $F['y'], $sw, $sh);
        foreach ($ctx['report']['service'] as $sv) flow_put($F, function ($s, $y) use ($ctx, $sv, $sw) { $c = $ctx; $c['report']['service'] = [$sv]; return p_service($s, $c, $y, $sw); });
        flow_put($F, function ($s, $y) use ($ctx, $sw) { return p_key_issues($s, $ctx, $y, $sw); });
        if ($SEC['system'] ?? false) foreach ($eqs as $e) {
            if ($variant === 'multi') flow_put($F, function ($s, $y) use ($ctx, $e, $sw) { return p_system_compact($s, $ctx, $e, $y, $sw, 6, 5); }, cm(0.1));
            else flow_put($F, function ($s, $y) use ($ctx, $e, $sw) { return p_system_compact($s, $ctx, $e, $y, $sw); });
        }
        if ($SEC['util'] ?? false) foreach ($eqs as $e) if (!empty($e['util'])) flow_put($F, function ($s, $y) use ($ctx, $e, $sw) { return p_util($s, $ctx, $e, $y, $sw, 5); });
        foreach ($eqs as $e) if (!empty($e['perf'])) { $F['title'] = '성능 세부 분석 (Performance)'; flow_put($F, function ($s, $y) use ($ctx, $e, $sw) { return p_perf($s, $ctx, $e, $y, $sw, 8, 12); }); }
        $F['title'] = '종합 점검 결과 (Summary)';
        if ($SEC['checks'] ?? false) flow_checks($F, $ctx, $sw);
    }
    if ($variant !== 'single') {
        $bt = $F['bottom'];
        $need = measure_h($sw, $sh, function ($s, $y) use ($ctx, $sw) { return p_result_side($s, $ctx, $y, $sw, cm(200)); });
        if ($F['y'] + min($need, cm(12)) > $F['bottom']) flow_new($F);
        p_result_side($F['s'], $ctx, $F['y'], $sw, $bt);
        foreach ($F['pages'] as $i => $p) k_footer($p, $sw, $sh, $ctx['company'], $i + 1, count($F['pages']));
        $prs->save($path); return;
    }
    if ($SEC['visual'] ?? false) flow_put($F, function ($s, $y) use ($ctx, $eqs, $sw) { return p_visual_single($s, $ctx, $eqs[0], $y, $sw); });
    if ($SEC['checks'] ?? false) flow_checks($F, $ctx, $sw);
    $op = $variant === 'single' ? "엔지니어 진단 의견 (Engineer's Opinion)" : "종합 엔지니어 의견 (Comprehensive Engineer's Opinion)";
    // 의견란은 남은 공간에 맞춰 늘거나 줄어듭니다. 최소 높이도 안 되면 새 페이지
    $need = measure_h($sw, $sh, function ($s, $y) use ($ctx, $sw, $op) { return p_opinion($s, $ctx, $y, $sw, cm(200), $op); });
    if ($F['y'] + min($need, cm(9.0)) > $F['bottom']) flow_new($F);
    p_opinion($F['s'], $ctx, $F['y'], $sw, $F['bottom'], $op);
    foreach ($F['pages'] as $i => $p) k_footer($p, $sw, $sh, $ctx['company'], $i + 1, count($F['pages']));
    $prs->save($path);
}

/* ---------- 작업계획서 (16:9) : 표지+개요 / 대상 장비·작업자 / 상세 절차 / 영향·리스크 / 롤백·사전점검·의견 ---------- */
function wide_title($s, $ctx, $title, $num = '') {
    $sw = WIDE_W;
    k_rect($s, cm(1.3), cm(0.62), cm(0.13), cm(0.8), C_BLUE);
    k_text($s, cm(1.6), cm(0.5), $sw - cm(12), cm(1.05), ($num ? "$num. " : '') . $title, 17, true, C_NAVY, 'l', 'm');
    k_logo($s, $sw - cm(1.3) - cm(3.0), cm(1.0), cm(3.0));
    k_text($s, $sw - cm(13.6), cm(0.6), cm(9), cm(0.8), $ctx['customer']['name'] . ' · ' . $ctx['plan_title'], 8.5, false, C_GREY, 'r', 'm');
    k_line($s, cm(1.3), cm(1.75), $sw - cm(1.3), cm(1.75));
    return cm(2.1);
}
function wide_sub($s, $x, $y, $w, $t) { k_text($s, $x, $y, $w, cm(0.6), $t, 10.5, true, C_NAVY, 'l', 'm'); return $y + cm(0.65); }
function downtime_of($steps) {
    $imp = []; foreach ($steps as $s) if (!empty($s['impact']) && mb_strpos($s['impact'], '없음') === false) $imp[$s['impact']] = 1;
    return $imp ? implode(', ', array_keys($imp)) : '없음 (무중단)';
}
function wide_footer($s, $ctx, $page) {
    $sw = WIDE_W; $sh = WIDE_H; $eng = $ctx['engineer'];
    k_line($s, cm(1.3), $sh - cm(0.85), $sw - cm(1.3), $sh - cm(0.85));
    k_text($s, cm(1.3), $sh - cm(0.8), $sw - cm(6), cm(0.5), implode('  |  ', array_filter([$ctx['company']['name'], trim($eng['name'] . ' ' . $eng['title']), $eng['phone'], $eng['email']])), 7.5, false, C_GREY, 'l', 'm');
    k_text($s, $sw - cm(4), $sh - cm(0.8), cm(2.7), cm(0.5), (string)$page, 8, true, C_GREY, 'r', 'm');
}
function build_workplan($ctx, $path) {
    $prs = new Pptx(WIDE_W, WIDE_H); $sw = WIDE_W; $sh = WIDE_H; $plan = $ctx['plan']; $L = cm(1.3); $W = $sw - 2 * $L; $pages = [];
    $eng = $ctx['engineer']; $steps = $plan['steps'] ?? []; $tm = 0; foreach ($steps as $x) $tm += (int)($x['minutes'] ?? 0);
    $bottom = $sh - cm(1.1); $gap = cm(0.6); $hw = (int)(($W - $gap) / 2);

    // 1. 표지 + 작업 개요 + 대상 장비 + 작업자 (한 장)
    $s = $prs->slide(); $pages[] = $s;
    k_rect($s, 0, 0, $sw, cm(4.4), C_LIGHT);
    k_rect($s, 0, cm(4.4), $sw, cm(0.06), C_SKY);
    k_rect($s, $L, cm(0.9), cm(1.6), cm(0.12), C_BLUE);
    k_text($s, $L, cm(1.15), $W - cm(8), cm(0.7), $ctx['customer']['name'], 12, false, C_GREY);
    k_text($s, $L, cm(1.85), $W - cm(8), cm(1.3), $ctx['plan_title'], 24, true, C_NAVY);
    k_text($s, $L, cm(3.25), $W - cm(8), cm(0.6), "{$ctx['report']['subtype_label']} · 작성일 {$ctx['report']['date']} · No. {$ctx['report']['no']}", 9.5, false, C_GREY);
    k_logo($s, $sw - $L - cm(5.0), cm(1.5), cm(5.0));
    k_text($s, $sw - $L - cm(7), cm(2.3), cm(7), cm(1.3), "작성: {$ctx['company']['name']}\n" . trim($eng['name'] . ' ' . $eng['title']), 9.5, false, C_INK, 'r');
    $y = cm(4.9); $lw = (int)($W * 0.6); $rw = $W - $lw - $gap; $rx = $L + $lw + $gap;
    $ly = wide_sub($s, $L, $y, $lw, '작업 개요');
    $rows = [
        ['고객사', $ctx['customer']['name'], '작업 장소', ($plan['location'] ?? '') ?: $ctx['customer']['site']],
        ['작업 일정', $plan['schedule'] ?? '', '예상 소요', intdiv($tm, 60) . '시간 ' . ($tm % 60) . "분 ({$tm}분)"],
        ['작업 유형', $ctx['report']['subtype_label'], '서비스 영향', ($plan['downtime'] ?? '') ?: downtime_of($steps)],
        ['목표 버전', $plan['target'] ?? '', '대상 장비', count($ctx['equipments']) . '대'],
        ['작업 목적', ['v' => $plan['purpose'] ?? '', 'span' => 3]],
    ];
    $lb = k_table($s, $L, $ly, $lw, $rows, ['col_w' => [1.2, 3.0, 1.2, 2.6], 'header' => false, 'label_cols' => [0, 2], 'size' => 9, 'row_h' => cm(0.78), 'align' => ['c', 'l', 'c', 'l']]);
    $ry = wide_sub($s, $rx, $y, $rw, '작업자 / 연락처');
    $rows = [['회사', '이름', '역할', '연락처']];
    foreach (array_slice($plan['workers'] ?? [], 0, 5) as $w) $rows[] = [$w['company'] ?? '', $w['name'] ?? '', $w['role'] ?? '', $w['phone'] ?? ''];
    if (count($rows) === 1) $rows[] = [$ctx['company']['name'], $eng['name'], '작업 책임', $eng['phone']];
    $rb = k_table($s, $rx, $ry, $rw, $rows, ['col_w' => [1.5, 1.1, 1.1, 1.7], 'size' => 8.5, 'row_h' => cm(0.6)]);
    $y = max($lb, $rb) + cm(0.4);
    $y = wide_sub($s, $L, $y, $W, '대상 장비 현황');
    $rows = [['No', 'System', 'Model', 'S/N', '현재 OS', '목표 버전/부품', 'HA', '상태', 'Health']];
    foreach ($ctx['equipments'] as $e) $rows[] = [$e['seq'], $e['name'], $e['model'], clip($e['serial'], 30), $e['os_version'], $plan['target'] ?? '', $e['ha_type'], STATUS_TEXT[$e['analysis']['status']], $e['analysis']['score']];
    $rh = max(cm(0.5), min(cm(0.7), (int)(($bottom - $y) / (count($rows) + 0.5))));
    k_table($s, $L, $y, $W, array_slice($rows, 0, max(2, (int)(($bottom - $y) / $rh))), ['col_w' => [0.5, 1.6, 1.6, 2.6, 1.3, 1.6, 1.0, 1.0, 0.8], 'size' => 8.5, 'row_h' => $rh, 'status_col' => 7]);

    // 2. 상세 작업 절차 (단계 번호 1-1, 1-2 …) — 한 장을 채우도록 행 높이를 맞추고, 넘치면 이어서
    $tint = function ($r, $c, $v) { return ($c === 5 && trim($v) !== '' && mb_strpos($v, '없음') === false) ? C_RED : null; };
    $head = ['No', '단계', '작업 항목', '작업 상세', '소요', '서비스 영향'];
    $rowsAll = []; $last = null;
    foreach ($steps as $st) { $ph = ($st['phase'] ?? '') !== $last ? ($st['phase'] ?? '') : ''; $last = $st['phase'] ?? ''; $rowsAll[] = [$st['no'] ?? '', $ph, $st['activity'] ?? '', $st['detail'] ?? '', ($st['minutes'] ?? '') . '분', $st['impact'] ?? '']; }
    $i = 0; $n = count($rowsAll);
    do {
        $s = $prs->slide(); $pages[] = $s;
        $y = wide_title($s, $ctx, '상세 작업 절차', '1');
        $avail = $bottom - $y - cm(0.2);
        $o = ['col_w' => [0.6, 1.7, 2.0, 5.3, 0.7, 1.3], 'size' => 9, 'row_h' => cm(0.6), 'align' => ['c', 'l', 'l', 'l', 'c', 'c'], 'tint' => $tint];
        $rows = [$head];
        while ($i < $n) {
            $try = array_merge($rows, [$rowsAll[$i]]);
            if (count($rows) > 1 && measure_h($sw, $sh, function ($ts, $yy) use ($L, $W, $try, $o) { return k_table($ts, $L, $yy, $W, $try, $o); }) > $avail - cm(0.7)) break;
            $rows = $try; $i++;
        }
        if ($i >= $n) $rows[] = ['', ['v' => '합계', 'span' => 3], ['v' => $tm . '분', 'bold' => true], ['v' => intdiv($tm, 60) . '시간 ' . ($tm % 60) . '분', 'bold' => true]];
        $h0 = measure_h($sw, $sh, function ($ts, $yy) use ($L, $W, $rows, $o) { return k_table($ts, $L, $yy, $W, $rows, $o); });
        $o['row_h'] = min(cm(1.0), cm(0.6) + (int)(max(0, $avail - $h0) / count($rows))); // 남는 높이를 행에 나눠 페이지를 채움
        k_table($s, $L, $y, $W, $rows, $o);
    } while ($i < $n);

    // 3. 서비스 영향·소요 시간·사전 이슈(왼쪽) + 작업 리스크 진단(오른쪽)
    $s = $prs->slide(); $pages[] = $s; $y = wide_title($s, $ctx, '서비스 영향 및 작업 리스크', '2');
    $impact = array_values(array_filter($steps, function ($st) { return !empty($st['impact']) && mb_strpos($st['impact'], '없음') === false; }));
    $kw = (int)(($hw - cm(0.3)) / 2);
    k_kpi($s, $L, $y, $kw, cm(1.6), "{$tm}분", '총 예상 소요 (' . intdiv($tm, 60) . '시간 ' . ($tm % 60) . '분)');
    k_kpi($s, $L + $kw + cm(0.3), $y, $kw, cm(1.6), count($impact) . '건', '서비스 영향 단계', $impact ? C_RED : C_GREEN, $impact ? C_RED : C_GREEN);
    $yy = $y + cm(1.85);
    $phases = []; foreach ($steps as $st) { $k = $st['phase'] ?? ''; $phases[$k] = ($phases[$k] ?? 0) + (int)($st['minutes'] ?? 0); }
    $bh = min(cm(0.55) * count($phases) + cm(0.8), cm(4.0));
    if ($phases) { k_bars($s, $L, $yy, $hw, $bh, array_keys($phases), array_values($phases), ['title' => '단계별 소요 시간(분)', 'max' => max($phases) * 1.2 ?: 1, 'suffix' => '분', 'th' => [1e9, 1e9]]); $yy += $bh + cm(0.2); }
    $rows = [['No', '서비스 영향 단계', '영향']]; foreach (array_slice($impact, 0, 4) as $st) $rows[] = [$st['no'] ?? '', clip($st['activity'] ?? '', 30), $st['impact']];
    if (count($rows) > 1) $yy = k_table($s, $L, $yy, $hw, $rows, ['col_w' => [0.6, 3, 1.6], 'size' => 8, 'row_h' => cm(0.5), 'tint' => function ($r, $c, $v) { return $c === 2 ? C_RED : null; }]) + cm(0.3);
    $tf = tag_findings($ctx); $room = (int)(($bottom - $yy - cm(1.2)) / cm(0.5));
    if ($room > 0) {
        $yy = wide_sub($s, $L, $yy, $hw, '작업 전 확인된 이슈 (로그 분석)');
        $rows = [['등급', '장비', '내용']];
        foreach (array_slice($tf, 0, $room) as $f) $rows[] = [STATUS_TEXT[$f['severity']], '#' . $f['_seq'], clip($f['title'], 40)];
        if (count($rows) === 1) $rows[] = ['Normal', '-', '작업에 영향을 줄 이상 징후가 없습니다.'];
        k_table($s, $L, $yy, $hw, $rows, ['col_w' => [0.9, 0.6, 4.4], 'size' => 8, 'row_h' => cm(0.5), 'status_col' => 0, 'align' => ['c', 'c', 'l']]);
    }
    $rx = $L + $hw + $gap; $ry = wide_sub($s, $rx, $y, $hw, '작업 리스크 진단 (엔지니어 검토)');
    $bxh = $bottom - $ry;
    k_rect($s, $rx, $ry, $hw, $bxh, C_PALE, C_LINE);
    k_rect($s, $rx, $ry, cm(0.12), $bxh, STATUS_COLOR[$ctx['report']['overall_status'] ?: 'normal']);
    k_text($s, $rx + cm(0.35), $ry + cm(0.15), $hw - cm(0.5), $bxh - cm(0.3), fit_lines($ctx['diagnosis'], (int)(($bxh - cm(0.4)) / cm(0.45)), (int)($hw / cm(0.3))), 9.5, false, C_INK, 'l', 't', 1.15);

    // 5. 롤백 계획 + 사전 점검(육안) + 최종 의견·서명 (한 장)
    $s = $prs->slide(); $pages[] = $s; $y = wide_title($s, $ctx, '롤백 계획 · 사전 점검 · 최종 의견', '3');
    $ly = wide_sub($s, $L, $y, $hw, '작업 실패 시 롤백 계획');
    $rows = [['롤백 기준', ($plan['rollback_criteria'] ?? '') ?: '작업 단계별 정상 확인 실패, 서비스 영향 허용 시간 초과 시'],
        ['롤백 절차', $plan['rollback'] ?? ''], ['의사 결정', ($plan['decision_maker'] ?? '') ?: '고객사 담당자와 협의 후 결정']];
    $ly = k_table($s, $L, $ly, $hw, $rows, ['col_w' => [1.2, 4.8], 'header' => false, 'size' => 8.5, 'row_h' => cm(0.7), 'label_cols' => [0], 'align' => ['c', 'l']]) + cm(0.4);
    $ly = wide_sub($s, $L, $ly, $hw, '엔지니어 최종 Comment');
    $ch = max(cm(1.6), $bottom - $ly - cm(2.3));
    k_rect($s, $L, $ly, $hw, $ch, C_PALE, C_LINE);
    k_text($s, $L + cm(0.25), $ly + cm(0.1), $hw - cm(0.5), $ch - cm(0.2), fit_lines($ctx['final_comment'], (int)(($ch - cm(0.2)) / cm(0.45)), (int)($hw / cm(0.3))), 9);
    k_table($s, $L, $ly + $ch + cm(0.25), $hw, [['작성자', trim($eng['name'] . ' ' . $eng['title']), '연락처', $eng['phone']], ['이메일', $eng['email'], '고객 승인', '(서명)']],
        ['col_w' => [0.9, 2.1, 0.9, 2.1], 'header' => false, 'label_cols' => [0, 2], 'size' => 8.5, 'row_h' => cm(0.62), 'align' => ['c', 'l', 'c', 'l']]);
    $rx = $L + $hw + $gap; $ry = wide_sub($s, $rx, $y, $hw, '사전 점검 (육안) 결과');
    $eqs = $ctx['equipments'];
    $rows = [array_merge(['점검 항목'], array_map(function ($e) { return '#' . $e['seq']; }, $eqs))];
    foreach (VISUAL_ITEMS as [$k, $label]) { $r = [$label]; foreach ($eqs as $e) $r[] = vis_mark($e['visual'][$k] ?? ''); $rows[] = $r; }
    $rh = min(cm(0.85), (int)(($bottom - $ry) / (count(VISUAL_ITEMS) + 1.3)));
    k_table($s, $rx, $ry, $hw, $rows, ['col_w' => array_merge([2.4], array_fill(0, count($eqs), 1)), 'size' => 8, 'row_h' => $rh, 'label_cols' => [0],
        'align' => array_merge(['l'], array_fill(0, count($eqs), 'c')), 'tint' => 'vis_tint']);

    foreach ($pages as $i => $p) wide_footer($p, $ctx, $i + 1);
    $prs->save($path);
}
function build_pptx($ctx, $path) { $ctx['report']['type'] === 'workplan' ? build_workplan($ctx, $path) : build_inspection($ctx, $path); }
