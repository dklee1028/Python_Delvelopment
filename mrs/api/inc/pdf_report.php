<?php
/* 보고서 PDF — PPT 와 같은 내용을 A4 세로 문서로 이어서 씁니다 (페이지가 넘치면 자동으로 다음 장).
   점검보고서: 표지 머리 · 고객/장비 정보 · 종합 판정 · 장비별 용량/진단 · 점검 항목 결과 · 사용률 · 육안 점검 · 서비스 내역 · 의견 · 서명
   작업계획서: 개요 · 대상 장비 · 상세 절차 · 영향/롤백 · 사전 점검 · 의견 · 서명 */

class PdfFlow {
    public $pdf, $y, $ctx, $m = 40, $top = 70, $bottom = 800; public $W;
    function __construct($ctx) { $this->pdf = new Pdf(); $this->ctx = $ctx; $this->W = $this->pdf->W - 2 * $this->m; }
    function page($cover = false) {
        $p = $this->pdf; $p->addPage(); $c = $this->ctx; $m = $this->m;
        if ($cover) {
            $p->rect(0, 0, $p->W, 96, C_LIGHT); $p->rect(0, 96, $p->W, 1.5, C_SKY); $p->rect($m, 22, 3.5, 44, C_BLUE);
            $title = $c['report']['type'] === 'workplan' ? $c['plan_title'] : $c['report']['type_label'];
            $p->text($m + 12, 22, $title, 19, true, C_NAVY);
            $p->text($m + 12, 50, trim(($c['report']['type'] === 'workplan' ? 'Work Plan' : 'Inspection Report') . '  ·  ' . $c['customer']['name'] . '  ·  ' . $c['report']['date']), 9, false, C_GREY);
            $p->text($m, 30, $c['company']['name'] ?: 'DATANEX', 11, true, C_BLUE, 'r', $this->W);
            $p->text($m, 50, 'No. ' . $c['report']['no'], 8, false, C_GREY, 'r', $this->W);
            $this->y = 116;
        } else {
            $p->rect($m, 28, 3, 16, C_BLUE);
            $p->text($m + 9, 29, $c['report']['type'] === 'workplan' ? $c['plan_title'] : $c['report']['type_label'] . ($c['report']['variant_label'] ? ' · ' . $c['report']['variant_label'] : ''), 11, true, C_NAVY);
            $p->text($m, 31, $c['customer']['name'] . ' · ' . $c['report']['date'], 8, false, C_GREY, 'r', $this->W);
            $p->line($m, 52, $m + $this->W, 52, C_LINE, 0.6);
            $this->y = $this->top;
        }
    }
    function need($h) { if ($this->y + $h > $this->bottom) $this->page(); }
    function gap($h = 10) { $this->y += $h; }
    function section($t) {
        $this->need(90); $p = $this->pdf; // 제목만 페이지 끝에 남지 않도록
        $p->rect($this->m, $this->y + 1, 2.5, 11, C_BLUE);
        $p->text($this->m + 8, $this->y, $t, 10.5, true, C_NAVY); $this->y += 18;
    }
    function para($s, $size = 9, $color = C_INK, $box = null) {
        $p = $this->pdf; $pad = $box ? 9 : 0; $lh = $size * 1.55;
        $lines = $p->wrap($s, $this->W - 2 * $pad - ($box ? 4 : 0), $size);
        $i = 0;
        while ($i < count($lines)) {
            $room = (int)floor(($this->bottom - $this->y - 2 * $pad) / $lh);
            if ($room < 2) { $this->page(); continue; }
            $chunk = array_slice($lines, $i, $room); $h = count($chunk) * $lh + 2 * $pad;
            if ($box) { $p->rect($this->m, $this->y, $this->W, $h, $box['fill'] ?? C_PALE, C_LINE); if (!empty($box['bar'])) $p->rect($this->m, $this->y, 3, $h, $box['bar']); }
            foreach ($chunk as $k => $ln) $p->text($this->m + $pad + ($box ? 4 : 0), $this->y + $pad + $k * $lh + ($lh - $size) / 2, $ln, $size, false, $color);
            $this->y += $h; $i += count($chunk);
        }
        $this->y += 8;
    }
    /* 표. $rows[0] 이 머리줄(header=true). 칸 내용은 자동 줄바꿈, 페이지가 넘으면 머리줄을 다시 그립니다 */
    function table($rows, $o = []) {
        $o += ['col_w' => null, 'header' => true, 'size' => 8, 'align' => [], 'label_cols' => [], 'status_col' => null, 'tint' => null];
        $p = $this->pdf; $n = 0; foreach ($rows as $r) $n = max($n, count($r));
        $cw = $o['col_w'] ?: array_fill(0, $n, 1); $tot = array_sum($cw); $ws = array_map(function ($v) use ($tot) { return $this->W * $v / $tot; }, $cw);
        $sz = $o['size']; $lh = $sz * 1.45; $pad = 4;
        $head = $o['header'] ? array_shift($rows) : null;
        $draw = function ($r, $isHead, $zebra) use ($p, $ws, $sz, $lh, $pad, $o) {
            $cells = []; $h = 0; $x = $this->m;
            $nc = count($r); if ($nc < count($ws)) { $ws = array_slice($ws, 0, $nc - 1) + [$nc - 1 => array_sum(array_slice($ws, $nc - 1))]; } // 칸이 모자라면 마지막 칸을 끝까지 합칩니다
            foreach ($ws as $i => $w) {
                $v = $r[$i] ?? ''; $v = is_array($v) ? ($v['v'] ?? '') : $v;
                $bold = $isHead || in_array($i, $o['label_cols'], true);
                $ls = $p->wrap((string)$v, $w - 2 * $pad, $sz, $bold); $cells[] = [$ls, $bold, (string)$v]; $h = max($h, count($ls) * $lh + 2 * $pad - 1);
            }
            $h = max($h, $lh + 2 * $pad - 1);
            if ($this->y + $h > $this->bottom) return false;
            foreach ($ws as $i => $w) {
                [$ls, $bold, $raw] = $cells[$i];
                $fill = $isHead ? C_HEAD : (in_array($i, $o['label_cols'], true) ? C_LIGHT : ($zebra ? C_ZEBRA : null));
                $p->rect($x, $this->y, $w, $h, $fill, C_LINE, 0.5);
                $color = C_INK;
                if (!$isHead && $o['status_col'] === $i) { $k = array_search($raw, STATUS_TEXT, true); if ($k && isset(STATUS_COLOR[$k])) { $color = STATUS_COLOR[$k]; $bold = true; } }
                if (!$isHead && $o['tint']) { $t = call_user_func($o['tint'], 0, $i, $raw); if ($t) { $color = $t; $bold = true; } }
                $al = $isHead ? 'c' : ($o['align'][$i] ?? 'l');
                foreach ($ls as $k => $ln) $p->text($x + $pad, $this->y + $pad + $k * $lh + ($lh - $sz) / 2, $ln, $sz, $bold, $isHead ? C_NAVY : $color, $al, $w - 2 * $pad);
                $x += $w;
            }
            $this->y += $h; return true;
        };
        $this->need(($head ? 2 : 1) * ($lh + 2 * $pad) + 4);
        if ($head) $draw($head, true, false);
        foreach ($rows as $k => $r) {
            if (!$draw($r, false, $k % 2 === 1)) { $this->page(); if ($head) $draw($head, true, false); $draw($r, false, $k % 2 === 1); }
        }
        $this->y += 10;
    }
    function kpis($items) { // [값, 이름, 색]
        $this->need(52); $p = $this->pdf; $n = count($items); $g = 8; $w = ($this->W - $g * ($n - 1)) / $n;
        foreach ($items as $i => [$v, $l, $c]) {
            $x = $this->m + $i * ($w + $g);
            $p->rect($x, $this->y, $w, 44, C_WHITE, C_LINE); $p->rect($x, $this->y, $w, 2.5, $c);
            $p->text($x, $this->y + 9, $v, 15, true, $c, 'c', $w); $p->text($x, $this->y + 29, $l, 7.5, false, C_GREY, 'c', $w);
        }
        $this->y += 54;
    }
    function sign() {
        $c = $this->ctx; $e = $c['engineer'];
        $this->table([['회사명', $c['company']['name'], '연락처', trim(($e['phone'] ?: ($c['company']['tel'] ?? '')) . ($e['email'] ? '  ' . $e['email'] : ''))],
            ['점검자', trim($e['name'] . ' ' . ($e['title'] ?? '')) . '  (서명)', '고객 확인', '(서명)']], ['col_w' => [1.2, 3.0, 1.2, 2.6], 'header' => false, 'label_cols' => [0, 2], 'size' => 8.5]);
    }
    function footers() {
        $p = $this->pdf; $n = count($p->pages);
        foreach ($p->pages as $i => $_) { $p->p = $i;
            $p->line($this->m, 816, $this->m + $this->W, 816, C_LINE, 0.5);
            $p->text($this->m, 821, ($this->ctx['company']['name'] ?: 'DATANEX') . '  ·  ' . $this->ctx['report']['no'], 7, false, C_GREY);
            $p->text($this->m, 821, ($i + 1) . ' / ' . $n, 7, false, C_GREY, 'r', $this->W);
        }
    }
}

function pdf_find_rows($e) { // PPT 와 달리 잘라 내지 않고 전체 문장
    $rows = [['등급', '구분', '진단 내용', '권고 조치']];
    foreach ($e['analysis']['findings'] as $f) $rows[] = [STATUS_TEXT[$f['severity']] ?? $f['severity'], $f['category'], $f['title'] . ($f['detail'] ? ' - ' . $f['detail'] : ''), $f['recommendation']];
    if (count($rows) === 1) $rows[] = ['Normal', '-', '주요 이상 징후가 발견되지 않았습니다.', '정기 모니터링 유지'];
    return $rows;
}
function pdf_customer($F) {
    $c = $F->ctx['customer']; $r = $F->ctx['report'];
    $F->section('고객 정보 (Customer Info)');
    $rows = [['고객명', $c['name'], '점검일', $r['date']], ['위치', $c['site'] ?: $c['address'] ?: '-', '고객 담당', implode(' / ', array_filter([$c['contact_name'], $c['contact_phone']])) ?: '-']];
    if ($r['service_codes']) $rows[] = ['서비스', implode('  ', array_map(function ($x) use ($r) { return (in_array($x, $r['service_codes'], true) ? '■ ' : '□ ') . $x; }, SERVICE_CODES)), '보고서 번호', $r['no']];
    $F->table($rows, ['col_w' => [1.2, 3.2, 1.2, 2.4], 'header' => false, 'label_cols' => [0, 2], 'size' => 8.5]);
}
function pdf_equipment_list($F) {
    $F->section('점검 장비 정보 (Equipment Info)');
    $rows = [['No.', 'System', 'Model', 'S/N', 'OS', 'Disk', '판정']];
    foreach ($F->ctx['equipments'] as $e) $rows[] = [$e['seq'], $e['name'], $e['model'], $e['serial'], $e['os_version'], $e['disk_info'], STATUS_TEXT[$e['analysis']['status']] ?? '-'];
    $F->table($rows, ['col_w' => [0.5, 1.6, 1.6, 2.4, 1.2, 1.4, 1.0], 'size' => 7.5, 'status_col' => 6, 'align' => ['c', 'l', 'l', 'l', 'l', 'l', 'c']]);
}
function build_pdf_inspection($ctx, $path) {
    $F = new PdfFlow($ctx); $F->page(true); $r = $ctx['report']; $st = $r['overall_status'] ?: 'normal';
    // 종합 판정 띠
    $p = $F->pdf; $p->rect($F->m, $F->y, $F->W, 34, C_PALE, C_LINE); $p->rect($F->m, $F->y, 4, 34, STATUS_COLOR[$st] ?? C_BLUE);
    $p->text($F->m + 14, $F->y + 7, '종합 판정', 8, false, C_GREY); $p->text($F->m + 14, $F->y + 17, $r['overall_label'] ?: '-', 11, true, STATUS_COLOR[$st] ?? C_NAVY);
    $p->text($F->m, $F->y + 12, $r['variant_label'] ? $r['type_label'] . ' · ' . $r['variant_label'] : $r['type_label'], 9, false, C_INK, 'r', $F->W - 12);
    $F->y += 46;
    pdf_customer($F); pdf_equipment_list($F);
    $S = $r['sections'];
    $F->section('점검 범위 (Scope)'); $F->table($ctx['scope'], ['col_w' => [1.4, 6.6], 'header' => false, 'label_cols' => [0], 'size' => 8.5]);
    foreach ($ctx['equipments'] as $e) {
        if (empty($S['system']) && empty($S['checks']) && empty($S['util'])) break;
        $a = $e['analysis']; $pa = $e['parsed']; $cap = $a['capacity'] ?? ['total_gb' => 0, 'pct' => 0, 'used_gb' => 0];
        $F->need(150); $p = $F->pdf;
        $p->rect($F->m, $F->y, $F->W, 24, C_LIGHT);
        $p->text($F->m + 10, $F->y + 7, "System {$e['seq']}  |  " . trim("{$e['vendor']} {$e['model']}") . "  (S/N: {$e['serial']})", 10, true, C_NAVY);
        $p->text($F->m, $F->y + 7, (STATUS_TEXT[$a['status']] ?? '') . ' · ' . ($a['score'] ?? '-') . '점', 9, true, STATUS_COLOR[$a['status']] ?? C_NAVY, 'r', $F->W - 10);
        $F->y += 32;
        if (!empty($S['system'])) {
        $failed = count($pa['disks']['failed'] ?? []);
        $F->kpis([[($a['score'] ?? '-') . '', 'Health Score', STATUS_COLOR[$a['status']] ?? C_BLUE],
            [$cap['total_gb'] ? $cap['pct'] . '%' : '-', '데이터 용량 사용률', ($cap['pct'] ?? 0) >= 95 ? C_RED : (($cap['pct'] ?? 0) >= 85 ? C_AMBER : C_BLUE)],
            [(string)$a['counts']['critical'], 'Critical 진단', $a['counts']['critical'] ? C_RED : C_GREEN],
            [(string)$a['counts']['warning'], 'Warning 진단', $a['counts']['warning'] ? C_AMBER : C_GREEN],
            [(string)$failed, 'Failed Disk · Spare ' . ($pa['disks']['spare'] ?? 0), $failed ? C_RED : C_GREEN]]);
        $F->section('Aggregate 현황'); $F->table(p_aggr_rows($e, 12), ['col_w' => [1.3, 1.8, 1.0, 1.0, 0.8, 1.0], 'size' => 7.5, 'align' => ['l', 'l', 'r', 'r', 'r', 'r']]);
        if (count($ctx['equipments']) > 1 || $r['type'] === 'special') { $F->section('볼륨 현황 (사용률 상위)'); $F->table(p_vol_rows($e, 10), ['col_w' => [1.2, 1.8, 1.0, 1.0, 0.8, 0.8, 1.0], 'size' => 7.5, 'status_col' => 6, 'align' => ['l', 'l', 'r', 'r', 'r', 'r', 'c']]); }
        $F->section('로그 분석 진단 (Diagnostic Findings)'); $F->table(pdf_find_rows($e), ['col_w' => [0.8, 0.9, 4.2, 2.8], 'size' => 7.5, 'status_col' => 0, 'align' => ['c', 'c', 'l', 'l']]);
        }
        if (!empty($S['checks']) && !empty($e['cmd_checks'])) {
            $F->section($r['type'] === 'special' ? '점검 항목 결과 (평시 + 상세)' : '점검 항목 결과 (평시)');
            $rows = [['구분', '항목', '명령어', '결과', '확인 내용']]; $last = '';
            foreach ($e['cmd_checks'] as $c) { $rows[] = [($c['kind'] === 'detail' ? '상세 · ' : '') . ($c['group'] !== $last ? $c['group'] : ''), $c['point'], $c['cmd'], STATUS_TEXT[$c['status']] ?? '미수집', $c['summary']]; $last = $c['group']; }
            $F->table($rows, ['col_w' => [1.1, 2.0, 2.4, 0.8, 3.2], 'size' => 6.8, 'status_col' => 3, 'align' => ['l', 'l', 'l', 'c', 'l']]);
        }
        if (!empty($S['util']) && !empty($e['util'])) { [$un, $ui] = util_tables($e['util'], 12); if ($un || $ui) { $F->section('시스템 사용률 (CPU · 디스크 · 네트워크)'); if ($un) $F->table($un, ['size' => 7.5, 'col_w' => [1.2, 1.4, 1.4, 1.8, 0.8]]); if ($ui) $F->table($ui, ['size' => 7.5, 'col_w' => [1.2, 1.4, 0.8, 1.0, 1.0, 0.8, 0.8]]); } }
    }
    // 육안 점검
    $eqs = $ctx['equipments'];
    if ($eqs && !empty($S['visual'])) {
        $F->section('육안 점검 (Visual Inspection)');
        $rows = [array_merge(['점검 항목'], array_map(function ($e) { return '#' . $e['seq'] . ' ' . $e['name']; }, $eqs))];
        foreach (VISUAL_ITEMS as [$k, $label]) { $rw = [$label]; foreach ($eqs as $e) $rw[] = vis_mark($e['visual'][$k] ?? ''); $rows[] = $rw; }
        $F->table($rows, ['col_w' => array_merge([3.0], array_fill(0, count($eqs), 1.4)), 'size' => 8, 'label_cols' => [0], 'align' => array_merge(['l'], array_fill(0, count($eqs), 'c')), 'tint' => 'vis_tint']);
    }
    foreach ($r['service'] as $sv) { $F->section('서비스 작업 내역 – ' . $sv['title']); $F->table($sv['rows'], ['col_w' => [1.3, 6.7], 'header' => false, 'label_cols' => [0], 'size' => 8]); }
    $F->section("엔지니어 진단 의견 (Engineer's Opinion)"); $F->para($ctx['diagnosis'] ?: '-', 9, C_INK, ['fill' => C_PALE, 'bar' => STATUS_COLOR[$st] ?? C_BLUE]);
    $F->section('엔지니어 최종 Comment'); $F->para($ctx['final_comment'], 9, C_INK, ['fill' => C_LIGHT]);
    $F->need(70); $F->sign(); $F->footers();
    file_put_contents($path, $F->pdf->output());
}
function build_pdf_workplan($ctx, $path) {
    $F = new PdfFlow($ctx); $F->page(true); $pl = $ctx['plan']; $c = $ctx['customer'];
    $F->section('작업 개요');
    $rows = [['고객사', $c['name'], '작업 일정', $pl['schedule'] ?? $ctx['report']['date']], ['작업 장소', $pl['location'] ?? ($c['site'] ?: $c['address']), '작업 종류', $ctx['report']['subtype_label'] ?: '-'],
        ['작업 목적', ['v' => $pl['purpose'] ?? '-']], ['작업 대상', ['v' => $pl['target'] ?? '-']], ['작업자', $pl['workers'] ?? ($ctx['engineer']['name'] ?? ''), '의사 결정자', $pl['decision_maker'] ?? '-'],
        ['서비스 중단', ['v' => $pl['downtime'] ?? '-']]];
    $F->table($rows, ['col_w' => [1.2, 3.0, 1.2, 2.6], 'header' => false, 'label_cols' => [0, 2], 'size' => 8.5]);
    pdf_equipment_list($F);
    $F->section('상세 작업 절차');
    $rows = [['No.', '단계', '작업 내용', '세부 사항', '영향', '소요(분)']];
    foreach ($pl['steps'] ?? [] as $s) $rows[] = [$s['no'] ?? '', $s['phase'] ?? '', $s['activity'] ?? '', $s['detail'] ?? '', $s['impact'] ?? '', $s['minutes'] ?? ''];
    if (count($rows) === 1) $rows[] = ['-', '-', '등록된 절차가 없습니다.', '', '', ''];
    $F->table($rows, ['col_w' => [0.6, 1.0, 2.4, 3.0, 1.0, 0.7], 'size' => 7.5, 'align' => ['c', 'l', 'l', 'l', 'l', 'c']]);
    $F->section('롤백 계획'); $F->para(trim(($pl['rollback'] ?? '') . (!empty($pl['rollback_criteria']) ? "\n\n롤백 기준: " . $pl['rollback_criteria'] : '')) ?: '-', 8.5, C_INK, ['fill' => C_PALE]);
    foreach ($ctx['report']['service'] as $sv) { $F->section('서비스 작업 내역 – ' . $sv['title']); $F->table($sv['rows'], ['col_w' => [1.3, 6.7], 'header' => false, 'label_cols' => [0], 'size' => 8]); }
    $eqs = $ctx['equipments'];
    if ($eqs) { $F->section('사전 점검 (육안)'); $rows = [array_merge(['점검 항목'], array_map(function ($e) { return '#' . $e['seq'] . ' ' . $e['name']; }, $eqs))];
        foreach (VISUAL_ITEMS as [$k, $label]) { $rw = [$label]; foreach ($eqs as $e) $rw[] = vis_mark($e['visual'][$k] ?? ''); $rows[] = $rw; }
        $F->table($rows, ['col_w' => array_merge([3.0], array_fill(0, count($eqs), 1.4)), 'size' => 8, 'label_cols' => [0], 'align' => array_merge(['l'], array_fill(0, count($eqs), 'c')), 'tint' => 'vis_tint']); }
    if (trim($ctx['diagnosis']) !== '') { $F->section('작업 리스크 진단 · 의견'); $F->para($ctx['diagnosis'], 9, C_INK, ['fill' => C_PALE, 'bar' => C_BLUE]); }
    $F->section('엔지니어 최종 Comment'); $F->para($ctx['final_comment'], 9, C_INK, ['fill' => C_LIGHT]);
    $F->need(70); $F->sign(); $F->footers();
    file_put_contents($path, $F->pdf->output());
}
function build_pdf($ctx, $path) { $ctx['report']['type'] === 'workplan' ? build_pdf_workplan($ctx, $path) : build_pdf_inspection($ctx, $path); }
