<?php
/* 고객 별도 양식(pptx/xlsx)에 {{치환자}} 값을 채워 넣습니다. 여러 글자 조각(run)으로 나뉜 치환자도 처리합니다. */

function placeholder_help() {
    return [
        'customer_name' => '고객명', 'customer_address' => '고객 주소', 'customer_site' => '설치 위치', 'contact_name' => '고객 담당자', 'contact_phone' => '담당자 연락처',
        'contact_email' => '담당자 이메일', 'report_no' => '보고서 번호', 'report_title' => '보고서 제목', 'report_type' => '보고서 종류', 'inspection_date' => '점검/작업일',
        'overall_status' => '종합 판정', 'health_score_avg' => '평균 건강 점수', 'diagnosis' => '장애 및 진단 의견(엔지니어 확정본)', 'final_comment' => '엔지니어 최종 Comment',
        'engineer_name' => '엔지니어', 'engineer_title' => '직급', 'engineer_phone' => '엔지니어 연락처', 'engineer_email' => '엔지니어 이메일', 'company_name' => '서비스 회사명',
        'company_address' => '서비스 회사 주소', 'equipment_count' => '장비 수', 'equipment_list' => '장비 목록(줄바꿈)', 'plan_schedule' => '작업 일정', 'plan_purpose' => '작업 목적',
        'plan_rollback' => '롤백 계획', 'eqN_model / eqN_serial / eqN_os / eqN_status / eqN_score / eqN_name' => 'N번째 장비 정보 (N=1~20)',
        'eqN_vi_<항목키>' => 'N번째 장비 육안점검 결과 (' . implode(', ', array_column(VISUAL_ITEMS, 0)) . ')',
    ];
}
function tpl_values($ctx) {
    $c = $ctx['customer']; $r = $ctx['report']; $eng = $ctx['engineer']; $plan = $ctx['plan'] ?? []; $eqs = $ctx['equipments'];
    $v = [
        'customer_name' => $c['name'], 'customer_address' => $c['address'], 'customer_site' => $c['site'], 'contact_name' => $c['contact_name'], 'contact_phone' => $c['contact_phone'],
        'contact_email' => $c['contact_email'], 'report_no' => $r['no'], 'report_title' => $r['title'], 'report_type' => $r['type_label'], 'inspection_date' => $r['date'],
        'overall_status' => $r['overall_label'], 'health_score_avg' => $eqs ? (string)round(array_sum(array_map(function ($e) { return $e['analysis']['score']; }, $eqs)) / count($eqs)) : '',
        'diagnosis' => $ctx['diagnosis'], 'final_comment' => $ctx['final_comment'], 'engineer_name' => $eng['name'], 'engineer_title' => $eng['title'], 'engineer_phone' => $eng['phone'],
        'engineer_email' => $eng['email'], 'company_name' => $ctx['company']['name'], 'company_address' => $ctx['company']['address'], 'equipment_count' => (string)count($eqs),
        'equipment_list' => implode("\n", array_map(function ($e) { return "{$e['seq']}. {$e['model']} ({$e['serial']}) - {$e['analysis']['status_label']}"; }, $eqs)),
        'plan_schedule' => $plan['schedule'] ?? '', 'plan_purpose' => $plan['purpose'] ?? '', 'plan_rollback' => $plan['rollback'] ?? '',
    ];
    foreach ($eqs as $e) {
        $p = "eq{$e['seq']}_";
        $v += [$p . 'name' => $e['name'], $p . 'model' => $e['model'], $p . 'serial' => $e['serial'], $p . 'os' => $e['os_version'], $p . 'status' => $e['analysis']['status_label'], $p . 'score' => (string)$e['analysis']['score']];
        foreach (VISUAL_ITEMS as [$k]) $v[$p . 'vi_' . $k] = $e['visual'][$k] ?? '';
    }
    return $v;
}
function tpl_parts($z, $fmt) {
    $out = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $n = $z->getNameIndex($i);
        if ($fmt === 'pptx' ? preg_match('#^ppt/(slides|slideLayouts|slideMasters|notesSlides)/[^/]+\.xml$#', $n) : preg_match('#^xl/(sharedStrings\.xml|worksheets/[^/]+\.xml)$#', $n)) $out[] = $n;
    }
    return $out;
}
function tpl_plain($frag) { preg_match_all('#<(?:a:)?t(?:\s[^>]*)?>(.*?)</(?:a:)?t>#s', $frag, $m); return html_entity_decode(implode('', $m[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'); }

/* 단락(<a:p>) / 문자열 항목(<si>, <is>) 단위로 처리 */
function tpl_process($xml, $fmt, $vals = null, &$found = null) {
    $re = $fmt === 'pptx' ? '#<a:p>(?:(?!</a:p>).)*</a:p>|<a:p\s[^>]*>(?:(?!</a:p>).)*</a:p>#s' : '#<si>.*?</si>|<is>.*?</is>#s';
    return preg_replace_callback($re, function ($m) use ($fmt, $vals, &$found) {
        $frag = $m[0]; $text = tpl_plain($frag);
        if (strpos($text, '{{') === false) return $frag;
        if (preg_match_all('/\{\{\s*([\w.]+)\s*\}\}/', $text, $pm) && is_array($found)) foreach ($pm[1] as $k) $found[$k] = 1;
        if ($vals === null) return $frag;
        $new = preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/', function ($x) use ($vals) { return $vals[$x[1]] ?? ''; }, $text);
        if ($fmt === 'pptx') {
            preg_match('#<a:r>.*?</a:r>#s', $frag, $first);
            $rpr = (isset($first[0]) && preg_match('#<a:rPr[^>]*/>|<a:rPr[^>]*>.*?</a:rPr>#s', $first[0], $rp)) ? $rp[0] : '';
            $runs = implode('<a:br/>', array_map(function ($ln) use ($rpr) { return '<a:r>' . $rpr . '<a:t>' . xe($ln) . '</a:t></a:r>'; }, explode("\n", $new)));
            preg_match('#<a:pPr[^>]*/>|<a:pPr[^>]*>.*?</a:pPr>#s', $frag, $ppr);
            preg_match('#<a:endParaRPr[^>]*/>|<a:endParaRPr[^>]*>.*?</a:endParaRPr>#s', $frag, $end);
            preg_match('#^<a:p[^>]*>#', $frag, $open);
            return ($open[0] ?? '<a:p>') . ($ppr[0] ?? '') . $runs . ($end[0] ?? '') . '</a:p>';
        }
        $tag = strpos($frag, '<si>') === 0 ? 'si' : 'is';
        return "<$tag><t xml:space=\"preserve\">" . xe($new) . "</t></$tag>";
    }, $xml);
}
function tpl_scan($path, $fmt) {
    $z = new ZipArchive(); if ($z->open($path) !== true) throw new Exception('양식 파일을 열 수 없습니다.');
    $found = [];
    foreach (tpl_parts($z, $fmt) as $n) tpl_process((string)$z->getFromName($n), $fmt, null, $found);
    $z->close();
    return array_keys($found);
}
function tpl_fill($src, $fmt, $ctx, $out) {
    if (!copy($src, $out)) throw new Exception('양식 파일을 복사할 수 없습니다.');
    $z = new ZipArchive(); if ($z->open($out) !== true) throw new Exception('양식 파일을 열 수 없습니다.');
    $vals = tpl_values($ctx); $dummy = [];
    foreach (tpl_parts($z, $fmt) as $n) {
        $x = (string)$z->getFromName($n); $y = tpl_process($x, $fmt, $vals, $dummy);
        if ($y !== $x) $z->addFromString($n, $y);
    }
    $z->close();
}
