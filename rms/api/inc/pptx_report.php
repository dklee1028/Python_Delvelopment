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
    k_rect($s, 0, 0, $sw, cm(2.6), C_NAVY);
    k_rect($s, 0, cm(2.6), $sw, cm(0.12), C_BLUE);
    k_text($s, M_(), cm(0.45), $sw - cm(8), cm(1.0), $ko, 20, true, C_WHITE);
    k_text($s, M_(), cm(1.45), $sw - cm(8), cm(0.7), $en, 10, false, C_SKY);
    k_rect($s, $sw - cm(6.2), cm(0.55), cm(5.2), cm(1.5), C_WHITE, null, 'roundRect', 0.12);
    k_logo($s, $sw - cm(5.95), cm(0.82), cm(0.95));
    k_pill($s, $sw - cm(6.2), cm(2.05), cm(5.2), cm(0.42), $ctx['report']['type_label'] . ' · No. ' . $ctx['report']['no'], C_BLUE, C_WHITE, 7);
    return cm(3.2);
}
function p_page_header($s, $ctx, $title, $sw) {
    k_rect($s, 0, 0, $sw, cm(1.5), C_NAVY);
    k_rect($s, 0, cm(1.5), $sw, cm(0.08), C_BLUE);
    k_text($s, M_(), cm(0.3), $sw - cm(8), cm(0.9), $title, 14, true, C_WHITE, 'l', 'm');
    k_text($s, $sw - cm(8), cm(0.4), cm(7), cm(0.7), $ctx['customer']['name'] . ' · ' . $ctx['report']['date'], 8.5, false, C_SKY, 'r', 'm');
    return cm(2.1);
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
    $rows = [['Node', 'Volume', 'Total', 'Used', 'Used (%)', 'Inode (%)', 'Remarks']];
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
function p_visual_matrix($s, $ctx, $y, $sw) {
    $y = k_section($s, M_(), $y, $sw - 2 * M_(), '주요 점검 항목 – 육안 점검 (Main Inspection Items)');
    $eqs = $ctx['equipments']; $rows = [array_merge(['Inspection Item'], array_map(function ($e) { return '#' . $e['seq']; }, $eqs))];
    foreach (VISUAL_ITEMS as [$k, $label]) { $r = [$label]; foreach ($eqs as $e) $r[] = vis_mark($e['visual'][$k] ?? ''); $rows[] = $r; }
    return k_table($s, M_(), $y, $sw - 2 * M_(), $rows, ['col_w' => array_merge([3.0], array_fill(0, count($eqs), 1.0)), 'size' => 7.5, 'row_h' => cm(0.5), 'label_cols' => [0],
        'align' => array_merge(['l'], array_fill(0, count($eqs), 'c')), 'tint' => 'vis_tint']) + cm(0.3);
}
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
    return k_table($s, M_(), $y, $sw - 2 * M_(), $rows, ['col_w' => array_merge([1.3, 2.2], array_fill(0, $n, 0.6), [2.8]), 'size' => 7, 'row_h' => cm(0.48), 'label_cols' => [0],
        'align' => array_merge(['c', 'l'], array_fill(0, $n, 'c'), ['l']), 'tint' => $tint]) + cm(0.3);
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
    $box = min($avail, max(cm(3.0), cm(0.42) * $need + cm(0.5)));
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
function p_opinion_fit($prs, &$pages, $s, $ctx, $y, $sw, $sh, $title) {
    if ($sh - cm(1.3) - $y < cm(9.5)) { $s = $prs->slide(); $pages[] = $s; $y = p_page_header($s, $ctx, "종합 의견 (Engineer's Opinion)", $sw); }
    p_opinion($s, $ctx, $y, $sw, $sh - cm(1.3), $title);
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
    $y = k_section($s, $m, $y, $W, "주요 볼륨 현황 (Top $vl Volumes)");
    $y = k_table($s, $m, $y, $W, p_vol_rows($e, $vl), ['col_w' => [1.2, 1.8, 1.0, 1.0, 0.8, 0.8, 1.0], 'size' => 7.5, 'row_h' => cm(0.5), 'status_col' => 6]) + cm(0.3);
    $y = k_section($s, $m, $y, $W, '로그 분석 진단 (Diagnostic Findings)');
    return k_table($s, $m, $y, $W, p_find_rows($e, $fl), ['col_w' => [0.8, 0.9, 4.2, 2.8], 'size' => 7.5, 'row_h' => cm(0.62), 'status_col' => 0, 'align' => ['c', 'c', 'l', 'l']]) + cm(0.3);
}
function p_system_compact($s, $ctx, $e, $y, $sw) {
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
    $y = k_table($s, $m, $y, $W, p_vol_rows($e, 5), ['col_w' => [1.2, 1.8, 1.0, 1.0, 0.8, 0.8, 1.0], 'size' => 7, 'row_h' => cm(0.46), 'status_col' => 6]) + cm(0.2);
    $y = k_table($s, $m, $y, $W, p_find_rows($e, 3), ['col_w' => [0.8, 0.9, 4.2, 2.8], 'size' => 7, 'row_h' => cm(0.55), 'status_col' => 0, 'align' => ['c', 'c', 'l', 'l']]);
    return $y + cm(0.6);
}
function tag_findings($ctx) {
    $out = [];
    foreach ($ctx['equipments'] as $e) foreach ($e['analysis']['findings'] as $f) if ($f['severity'] !== 'info') { $f['_seq'] = $e['seq']; $out[] = $f; }
    usort($out, function ($a, $b) { return ($a['severity'] === 'critical' ? 0 : 1) <=> ($b['severity'] === 'critical' ? 0 : 1); });
    return $out;
}

function build_inspection($ctx, $path) {
    $prs = new Pptx(A4_W, A4_H); $sw = A4_W; $sh = A4_H; $m = M_(); $W = $sw - 2 * $m;
    $variant = $ctx['report']['variant'];
    $title = str_replace(' 보고서', '', $ctx['report']['type_label']) . ' 보고서';
    $pages = [];
    if ($variant === 'single') {
        $e = $ctx['equipments'][0];
        $s = $prs->slide(); $pages[] = $s;
        $y = p_header($s, $ctx, $title, 'Storage Service Report', $sw);
        $y = p_customer($s, $ctx, $y, $sw);
        $y = k_section($s, $m, $y, $W, '점검 장비 정보 (Equipment Info)');
        $y = k_table($s, $m, $y, $W, [['Model', "{$e['vendor']} {$e['model']}" . ($e['ha_type'] ? " ({$e['ha_type']})" : ''), 'System S/N', $e['serial']], ['OS Ver', $e['os_version'], 'Disk Type', $e['disk_info']]],
            ['col_w' => [1.2, 2.8, 1.2, 2.8], 'header' => false, 'label_cols' => [0, 2], 'align' => ['c', 'l', 'c', 'l']]) + cm(0.35);
        p_system_detail($s, $ctx, $e, $y, $sw, 5, 4, 3);
        $s2 = $prs->slide(); $pages[] = $s2;
        $y = p_page_header($s2, $ctx, '점검 결과 및 종합 의견', $sw);
        $y = p_visual_single($s2, $ctx, $e, $y, $sw);
        p_opinion($s2, $ctx, $y, $sw, $sh - cm(1.3));
    } else {
        $s = $prs->slide(); $pages[] = $s;
        $y = p_header($s, $ctx, $title, 'Storage Service Report', $sw);
        $y = p_customer($s, $ctx, $y, $sw);
        $y = p_equipment_list($s, $ctx, $y, $sw, $variant === 'fleet');
        $eqs = $ctx['equipments'];
        if ($variant === 'multi') {
            $y = k_section($s, $m, $y, $W, '점검 범위 (Inspection Scope)');
            $sc = $ctx['scope']; $rows = [];
            for ($i = 0; $i < count($sc); $i += 2) $rows[] = [$sc[$i][0], $sc[$i][1], $sc[$i + 1][0] ?? '', $sc[$i + 1][1] ?? ''];
            $y = k_table($s, $m, $y, $W, $rows, ['col_w' => [1.1, 3.0, 1.1, 3.0], 'header' => false, 'label_cols' => [0, 2], 'size' => 8, 'align' => ['c', 'l', 'c', 'l']]) + cm(0.35);
            $y = k_section($s, $m, $y, $W, 'Executive Summary – 장비별 건강 점수');
            $n = count($eqs); $cw = (int)(($W - cm(0.4) * ($n - 1)) / $n);
            foreach ($eqs as $i => $e) {
                $x = $m + $i * ($cw + cm(0.4));
                k_rect($s, $x, $y, $cw, cm(5.2), C_WHITE, C_LINE, 'roundRect', 0.05);
                k_text($s, $x, $y + cm(0.2), $cw, cm(0.5), "System {$e['seq']}", 8, true, C_GREY, 'c');
                k_text($s, $x, $y + cm(0.6), $cw, cm(0.6), clip($e['model'], 22), 10, true, C_NAVY, 'c');
                k_donut($s, $x + (int)(($cw - cm(2.6)) / 2), $y + cm(1.25), cm(2.6), $e['analysis']['score'], 'Health', null, $e['analysis']['status_label']);
            }
            $y += cm(5.6);
            $tf = array_slice(tag_findings($ctx), 0, 5);
            if ($tf) {
                $y = k_section($s, $m, $y, $W, '핵심 이슈 (Key Issues)');
                $rows = [['등급', '장비', '이슈']];
                foreach ($tf as $f) $rows[] = [STATUS_TEXT[$f['severity']], '#' . $f['_seq'], clip($f['title'] . ' - ' . $f['detail'], 80)];
                k_table($s, $m, $y, $W, $rows, ['col_w' => [0.9, 0.6, 6.5], 'size' => 7.5, 'row_h' => cm(0.55), 'status_col' => 0, 'align' => ['c', 'c', 'l']]);
            }
            foreach ($eqs as $e) {
                $sp = $prs->slide(); $pages[] = $sp;
                $y = p_page_header($sp, $ctx, '스토리지 운영현황 (Storage Operation Status)', $sw);
                p_system_detail($sp, $ctx, $e, $y, $sw, 10, 6, 5);
            }
            $so = $prs->slide(); $pages[] = $so;
            $y = p_page_header($so, $ctx, '종합 점검 결과 (Summary)', $sw);
            $y = p_check_matrix($so, $ctx, $y, $sw);
            $y = p_visual_matrix($so, $ctx, $y, $sw);
            p_opinion_fit($prs, $pages, $so, $ctx, $y, $sw, $sh, "종합 엔지니어 의견 (Comprehensive Engineer's Opinion)");
        } else {
            $y = k_section($s, $m, $y, $W, 'Fleet Dashboard – 전체 현황');
            $c = count(array_filter($eqs, function ($e) { return $e['analysis']['status'] === 'critical'; }));
            $w_ = count(array_filter($eqs, function ($e) { return $e['analysis']['status'] === 'warning'; }));
            $tot = 0; $used = 0; foreach ($eqs as $e) { $tot += $e['analysis']['capacity']['total_gb']; $used += $e['analysis']['capacity']['used_gb']; }
            $kw = (int)(($W - cm(0.3) * 3) / 4);
            k_kpi($s, $m, $y, $kw, cm(1.9), count($eqs) . '대', '점검 장비');
            k_kpi($s, $m + ($kw + cm(0.3)), $y, $kw, cm(1.9), (string)$c, 'Critical 장비', $c ? C_RED : C_GREEN, $c ? C_RED : C_GREEN);
            k_kpi($s, $m + 2 * ($kw + cm(0.3)), $y, $kw, cm(1.9), (string)$w_, 'Warning 장비', $w_ ? C_AMBER : C_GREEN, $w_ ? C_AMBER : C_GREEN);
            k_kpi($s, $m + 3 * ($kw + cm(0.3)), $y, $kw, cm(1.9), ($tot ? round($used / $tot * 100) : 0) . '%', $tot ? '전체 사용률 ' . human_gb($used) . ' / ' . human_gb($tot) : '전체 사용률');
            $y += cm(2.2);
            $ch = min(cm(0.75) * count($eqs) + cm(1.0), $sh - cm(1.4) - $y - cm(0.2));
            k_bars($s, $m, $y, (int)($W * 0.5), $ch, array_map(function ($e) { return '#' . $e['seq'] . ' ' . clip($e['model'], 14); }, $eqs),
                array_map(function ($e) { return $e['analysis']['capacity']['pct']; }, $eqs), ['title' => '장비별 Data 용량 사용률']);
            k_bars($s, $m + (int)($W * 0.52), $y, (int)($W * 0.48), $ch, array_map(function ($e) { return '#' . $e['seq']; }, $eqs),
                array_map(function ($e) { return $e['analysis']['score']; }, $eqs), ['title' => '장비별 Health Score', 'suffix' => '',
                    'colors' => array_map(function ($e) { return STATUS_COLOR[$e['analysis']['status']]; }, $eqs)]);
            for ($i = 0; $i < count($eqs); $i += 2) {
                $sp = $prs->slide(); $pages[] = $sp;
                $y = p_page_header($sp, $ctx, '스토리지 운영현황 (Storage Operation Status)', $sw);
                foreach (array_slice($eqs, $i, 2) as $e) $y = p_system_compact($sp, $ctx, $e, $y, $sw);
            }
            $so = $prs->slide(); $pages[] = $so;
            $y = p_page_header($so, $ctx, '종합 점검 결과 (Summary)', $sw);
            p_check_matrix($so, $ctx, $y, $sw);
            $sv = $prs->slide(); $pages[] = $sv;
            $y2 = p_page_header($sv, $ctx, '육안 점검 및 종합 의견', $sw);
            $y2 = p_visual_matrix($sv, $ctx, $y2, $sw);
            p_opinion_fit($prs, $pages, $sv, $ctx, $y2, $sw, $sh, "종합 엔지니어 의견 (Comprehensive Engineer's Opinion)");
        }
    }
    foreach ($pages as $i => $p) k_footer($p, $sw, $sh, $ctx['company'], $i + 1, count($pages));
    $prs->save($path);
}

function wide_title($s, $ctx, $title, $num = '') {
    $sw = WIDE_W;
    k_rect($s, 0, 0, cm(0.5), WIDE_H, C_BLUE);
    k_text($s, cm(1.3), cm(0.6), $sw - cm(10), cm(1.1), ($num ? "$num. " : '') . $title, 20, true, C_NAVY, 'l', 'm');
    k_text($s, $sw - cm(9), cm(0.75), cm(7.7), cm(0.8), $ctx['plan_title'], 9, false, C_GREY, 'r', 'm');
    k_line($s, cm(1.3), cm(1.9), $sw - cm(1.3), cm(1.9));
    return cm(2.4);
}
function downtime_of($steps) {
    $imp = []; foreach ($steps as $s) if (!empty($s['impact']) && mb_strpos($s['impact'], '없음') === false) $imp[$s['impact']] = 1;
    return $imp ? implode(', ', array_keys($imp)) : '없음 (무중단)';
}
function build_workplan($ctx, $path) {
    $prs = new Pptx(WIDE_W, WIDE_H); $sw = WIDE_W; $sh = WIDE_H; $plan = $ctx['plan']; $L = cm(1.3); $W = $sw - 2 * $L; $pages = [];
    $eng = $ctx['engineer'];
    // 표지
    $s = $prs->slide();
    k_rect($s, 0, 0, $sw, $sh, C_NAVY);
    k_rect($s, 0, $sh - cm(4.2), $sw, cm(4.2), C_WHITE);
    k_rect($s, $L, cm(4.0), cm(2.0), cm(0.18), C_BLUE);
    k_text($s, $L, cm(4.5), $W, cm(1.0), $ctx['customer']['name'], 16, false, C_SKY);
    k_text($s, $L, cm(5.6), $W, cm(2.2), $ctx['plan_title'], 30, true, C_WHITE);
    k_text($s, $L, cm(8.2), $W, cm(0.8), "{$ctx['report']['subtype_label']} · {$ctx['report']['date']} · No. {$ctx['report']['no']}", 11, false, C_SKY);
    k_logo($s, $L, $sh - cm(3.0), cm(1.3));
    k_text($s, $sw - cm(12), $sh - cm(3.0), cm(10.7), cm(1.4), "작성: {$ctx['company']['name']}\n" . trim($eng['name'] . ' ' . $eng['title']), 10, false, C_INK, 'r');
    // 목차
    $toc = ['작업 개요', '대상 장비 현황', '상세 작업 절차', '서비스 영향 및 소요 시간', '작업 리스크 진단', '롤백 계획', '사전 점검 및 최종 의견'];
    $s = $prs->slide(); $pages[] = $s; $y = wide_title($s, $ctx, '목차');
    foreach ($toc as $i => $t) {
        $col = intdiv($i, 4); $row = $i % 4; $x = $L + $col * (int)($W / 2); $yy = $y + cm(0.4) + $row * cm(2.6);
        k_text($s, $x, $yy, cm(2.2), cm(1.6), sprintf('%02d', $i + 1), 28, true, C_BLUE, 'l', 'm');
        k_text($s, $x + cm(2.4), $yy, (int)($W / 2) - cm(3), cm(1.6), $t, 16, true, C_INK, 'l', 'm');
    }
    // 1. 작업 개요
    $s = $prs->slide(); $pages[] = $s; $y = wide_title($s, $ctx, '작업 개요', '1');
    $steps = $plan['steps'] ?? []; $tm = 0; foreach ($steps as $x) $tm += (int)($x['minutes'] ?? 0);
    $rows = [
        ['고객사', $ctx['customer']['name'], '작업 장소', ($plan['location'] ?? '') ?: $ctx['customer']['site']],
        ['작업 일정', $plan['schedule'] ?? '', '예상 소요', intdiv($tm, 60) . '시간 ' . ($tm % 60) . "분 ({$tm}분)"],
        ['작업 유형', $ctx['report']['subtype_label'], '서비스 중단', ($plan['downtime'] ?? '') ?: downtime_of($steps)],
        ['대상 장비', ['v' => implode(', ', array_map(function ($e) { return "{$e['model']}({$e['name']})"; }, $ctx['equipments'])), 'span' => 3]],
        ['작업 목적', ['v' => $plan['purpose'] ?? '', 'span' => 3]],
    ];
    $y = k_table($s, $L, $y, $W, $rows, ['col_w' => [1.3, 4.0, 1.3, 3.0], 'header' => false, 'label_cols' => [0, 2], 'size' => 10, 'row_h' => cm(0.95), 'align' => ['c', 'l', 'c', 'l']]) + cm(0.5);
    $workers = $plan['workers'] ?? [];
    if ($workers) {
        k_text($s, $L, $y, $W, cm(0.6), '작업자 / 연락처', 11, true, C_NAVY);
        $rows = [['회사', '이름', '역할', '연락처', '이메일']];
        foreach (array_slice($workers, 0, 4) as $w) $rows[] = [$w['company'] ?? '', $w['name'] ?? '', $w['role'] ?? '', $w['phone'] ?? '', $w['email'] ?? ''];
        k_table($s, $L, $y + cm(0.7), $W, $rows, ['col_w' => [2, 1.5, 1.5, 2, 3], 'size' => 9, 'row_h' => cm(0.7)]);
    }
    // 2. 대상 장비 현황
    $s = $prs->slide(); $pages[] = $s; $y = wide_title($s, $ctx, '대상 장비 현황', '2');
    $rows = [['No', 'System', 'Model', 'S/N', '현재 OS', '목표 버전/부품', 'HA', '상태', 'Health']];
    foreach ($ctx['equipments'] as $e) $rows[] = [$e['seq'], $e['name'], $e['model'], clip($e['serial'], 30), $e['os_version'], $plan['target'] ?? '', $e['ha_type'], STATUS_TEXT[$e['analysis']['status']], $e['analysis']['score']];
    $y = k_table($s, $L, $y, $W, $rows, ['col_w' => [0.5, 1.6, 1.6, 2.6, 1.3, 1.6, 1.0, 1.0, 0.8], 'size' => 9, 'row_h' => cm(0.75), 'status_col' => 7]) + cm(0.5);
    $tf = array_slice(tag_findings($ctx), 0, 5);
    if ($tf) {
        k_text($s, $L, $y, $W, cm(0.6), '작업 전 확인된 이슈 (로그 분석)', 11, true, C_NAVY);
        $rows = [['등급', '장비', '내용', '권고']];
        foreach ($tf as $f) $rows[] = [STATUS_TEXT[$f['severity']], '#' . $f['_seq'], clip($f['title'] . ' - ' . $f['detail'], 70), clip($f['recommendation'], 50)];
        k_table($s, $L, $y + cm(0.7), $W, $rows, ['col_w' => [0.9, 0.6, 5.0, 3.5], 'size' => 8.5, 'row_h' => cm(0.62), 'status_col' => 0, 'align' => ['c', 'c', 'l', 'l']]);
    }
    // 3. 상세 작업 절차
    $chunks = $steps ? array_chunk($steps, 9) : [[]]; $n = 0;
    foreach ($chunks as $ci => $chunk) {
        $s = $prs->slide(); $pages[] = $s;
        $y = wide_title($s, $ctx, '상세 작업 절차' . (count($chunks) > 1 ? ' (' . ($ci + 1) . '/' . count($chunks) . ')' : ''), '3');
        $rows = [['No', '단계', '작업 항목', '작업 상세', '소요', '서비스 영향']];
        foreach ($chunk as $st) { $n++; $rows[] = [$n, $st['phase'] ?? '', $st['activity'] ?? '', clip($st['detail'] ?? '', 70), ($st['minutes'] ?? '') . '분', $st['impact'] ?? '']; }
        $tint = function ($r, $c, $v) { return ($c === 5 && trim($v) !== '' && mb_strpos($v, '없음') === false) ? C_RED : null; };
        k_table($s, $L, $y, $W, $rows, ['col_w' => [0.5, 1.8, 2.0, 5.2, 0.8, 1.4], 'size' => 9, 'row_h' => cm(0.9), 'align' => ['c', 'l', 'l', 'l', 'c', 'c'], 'tint' => $tint]);
    }
    // 4. 서비스 영향 및 소요 시간
    $s = $prs->slide(); $pages[] = $s; $y = wide_title($s, $ctx, '서비스 영향 및 소요 시간', '4');
    $phases = []; foreach ($steps as $st) { $k = $st['phase'] ?? ''; $phases[$k] = ($phases[$k] ?? 0) + (int)($st['minutes'] ?? 0); }
    if ($phases) k_bars($s, $L, $y, (int)($W * 0.58), $sh - $y - cm(1.5), array_keys($phases), array_values($phases),
        ['title' => '단계별 소요 시간(분)', 'max' => max($phases) * 1.25 ?: 1, 'suffix' => '분', 'th' => [1e9, 1e9]]);
    $impact = array_values(array_filter($steps, function ($st) { return !empty($st['impact']) && mb_strpos($st['impact'], '없음') === false; }));
    $x2 = $L + (int)($W * 0.62); $w2 = (int)($W * 0.38);
    k_kpi($s, $x2, $y, $w2, cm(2.0), "{$tm}분", '총 예상 소요 시간');
    k_kpi($s, $x2, $y + cm(2.3), $w2, cm(2.0), count($impact) . '건', '서비스 영향 단계', $impact ? C_RED : C_GREEN, $impact ? C_RED : C_GREEN);
    $rows = [['영향 단계', '영향']]; foreach (array_slice($impact, 0, 6) as $st) $rows[] = [clip($st['activity'] ?? '', 22), $st['impact']];
    if (count($rows) > 1) k_table($s, $x2, $y + cm(4.7), $w2, $rows, ['col_w' => [2, 1.4], 'size' => 8.5, 'row_h' => cm(0.6)]);
    // 5. 리스크 진단
    $s = $prs->slide(); $pages[] = $s; $y = wide_title($s, $ctx, '작업 리스크 진단 (엔지니어 검토)', '5');
    k_rect($s, $L, $y, $W, $sh - $y - cm(1.2), C_WHITE, C_LINE);
    k_rect($s, $L, $y, cm(0.15), $sh - $y - cm(1.2), STATUS_COLOR[$ctx['report']['overall_status'] ?: 'normal']);
    k_text($s, $L + cm(0.4), $y + cm(0.2), $W - cm(0.6), $sh - $y - cm(1.6), fit_lines($ctx['diagnosis'], (int)(($sh - $y - cm(1.8)) / cm(0.46)), (int)($W / cm(0.26))), 10, false, C_INK, 'l', 't', 1.1);
    // 6. 롤백
    $s = $prs->slide(); $pages[] = $s; $y = wide_title($s, $ctx, '작업 실패 시 롤백 계획', '6');
    $rows = [['구분', '내용'], ['롤백 기준', ($plan['rollback_criteria'] ?? '') ?: '작업 단계별 정상 확인 실패, 서비스 영향 허용 시간 초과 시'],
        ['롤백 절차', $plan['rollback'] ?? ''], ['의사 결정', ($plan['decision_maker'] ?? '') ?: '고객사 담당자와 협의 후 결정']];
    k_table($s, $L, $y, $W, $rows, ['col_w' => [1.5, 8.5], 'size' => 11, 'row_h' => cm(1.6), 'label_cols' => [0], 'align' => ['c', 'l']]);
    // 7. 사전 점검 및 최종 의견
    $s = $prs->slide(); $pages[] = $s; $y = wide_title($s, $ctx, '사전 점검(육안) 결과 및 엔지니어 최종 의견', '7');
    $eqs = $ctx['equipments'];
    $rows = [array_merge(['점검 항목'], array_map(function ($e) { return '#' . $e['seq']; }, $eqs))];
    foreach (VISUAL_ITEMS as [$k, $label]) { $r = [$label]; foreach ($eqs as $e) $r[] = vis_mark($e['visual'][$k] ?? ''); $rows[] = $r; }
    $hw = (int)($W * 0.48);
    k_table($s, $L, $y, $hw, $rows, ['col_w' => array_merge([2.4], array_fill(0, count($eqs), 1)), 'size' => 8, 'row_h' => cm(0.36), 'label_cols' => [0],
        'align' => array_merge(['l'], array_fill(0, count($eqs), 'c')), 'tint' => 'vis_tint']);
    $x2 = $L + (int)($W * 0.52);
    k_rect($s, $x2, $y, $hw, cm(5.0), C_LIGHT);
    k_text($s, $x2 + cm(0.3), $y + cm(0.2), $hw - cm(0.6), cm(0.6), '엔지니어 최종 Comment', 11, true, C_BLUE);
    k_text($s, $x2 + cm(0.3), $y + cm(0.9), $hw - cm(0.6), cm(4.0), fit_lines($ctx['final_comment'], 8, (int)($hw / cm(0.3))), 10);
    k_table($s, $x2, $y + cm(5.4), $hw, [['작성자', trim($eng['name'] . ' ' . $eng['title'])], ['연락처', $eng['phone']], ['이메일', $eng['email']], ['고객 승인', '(서명)']],
        ['col_w' => [1, 3], 'header' => false, 'label_cols' => [0], 'size' => 9, 'row_h' => cm(0.7), 'align' => ['c', 'l']]);
    // 마무리
    $s = $prs->slide();
    k_rect($s, 0, 0, $sw, $sh, C_NAVY);
    k_text($s, 0, (int)($sh / 2) - cm(1.5), $sw, cm(2), 'Thank you', 40, true, C_WHITE, 'c');
    k_text($s, 0, (int)($sh / 2) + cm(0.5), $sw, cm(1), $ctx['company']['name'], 14, false, C_SKY, 'c');
    $contact = implode('  |  ', array_filter([trim($eng['name'] . ' ' . $eng['title']), $eng['phone'], $eng['email']]));
    if ($contact) k_text($s, 0, (int)($sh / 2) + cm(1.5), $sw, cm(0.8), $contact, 11, false, C_SKY, 'c');
    foreach ($pages as $i => $p) k_text($p, $sw - cm(3), $sh - cm(0.9), cm(2), cm(0.5), (string)($i + 2), 9, true, C_GREY, 'r');
    $prs->save($path);
}
function build_pptx($ctx, $path) { $ctx['report']['type'] === 'workplan' ? build_workplan($ctx, $path) : build_inspection($ctx, $path); }
