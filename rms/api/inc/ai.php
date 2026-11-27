<?php
/* Claude 기반 장애·진단 조언 생성.
   엔지니어는 이 결과를 그대로 쓰지 않고 반드시 검토·수정한 뒤 '진단 의견'으로 확정합니다.
   API 키가 없거나 호출이 실패하면 규칙 기반 초안을 돌려주며, 그 사실을 표시합니다.
   웹호스팅에서는 composer(공식 PHP SDK)를 쓸 수 없어 Messages API 를 cURL 로 직접 호출합니다. */

const AI_SYSTEM_PROMPT = "당신은 엔터프라이즈 스토리지(NetApp ONTAP 중심) 전문 컨설턴트입니다.
엔지니어가 수집한 로그 분석 결과를 바탕으로 고객 제출용 보고서에 들어갈 '장애 및 진단 의견' 초안을 작성합니다.

작성 원칙:
- 한국어, 간결한 보고서 문체(~입니다/~권장합니다). 과장하지 말고 근거(수치, 이벤트명)를 함께 적습니다.
- 데이터에 없는 사실은 만들지 않습니다. 확인이 필요한 부분은 \"현장 확인 필요\"로 표시합니다.
- 다음 구성으로 작성합니다:
  [종합 판정] 한 줄 (정상/요주의/위험 + 핵심 사유)
  [주요 진단] 장비별 핵심 문제를 우선순위(위험→요주의) 순으로 번호 목록
  [권고 사항] 즉시 조치 / 단기(1개월) / 중장기 로 나눈 실행 가능한 조치
- 작업계획서인 경우: [작업 리스크]와 [사전 확인 사항], [롤백 고려사항]을 추가합니다.
- 전체 분량은 A4 반 페이지 이내로 유지합니다.";

function ai_payload($type, $sub, $customer, $eqs, $extra) {
    $slim = [];
    foreach ($eqs as $e) {
        $p = $e['parsed'] ?? []; $a = $e['analysis'] ?? [];
        $slim[] = [
            'system' => $e['label'] ?? '', 'model' => $e['model'] ?? '', 'os_version' => $e['os_version'] ?? '',
            'analysis' => ['status' => $a['status'] ?? null, 'score' => $a['score'] ?? null, 'findings' => $a['findings'] ?? [], 'capacity' => $a['capacity'] ?? null],
            'aggregates' => array_map(function ($x) { return array_intersect_key($x, array_flip(['node', 'name', 'pct', 'total_gb', 'avail_gb'])); }, array_slice($p['aggregates'] ?? [], 0, 10)),
            'top_volumes' => array_map(function ($x) { return array_intersect_key($x, array_flip(['name', 'pct', 'inode_pct'])); }, array_slice($p['volumes'] ?? [], 0, 10)),
            'failed_disks' => array_slice($p['disks']['failed'] ?? [], 0, 10), 'ha' => $p['ha'] ?? null, 'ports_down' => $p['ports_down'] ?? [], 'events' => $p['events'] ?? null,
        ];
    }
    return je(['report_type' => REPORT_TYPES[$type] ?? $type, 'work_type' => WORK_SUBTYPES[$sub] ?? '', 'customer' => $customer,
        'equipment_count' => count($eqs), 'equipments' => $slim, 'engineer_notes' => $extra]);
}

function ai_rule_based($eqs, $type = 'regular') {
    $st = array_map(function ($e) { return $e['analysis']['status'] ?? 'normal'; }, $eqs);
    $verdict = in_array('critical', $st, true) ? '위험 (Critical)' : (in_array('warning', $st, true) ? '요주의 (Warning)' : '정상 (Normal)');
    $L = ["[종합 판정: $verdict]", '', '[주요 진단]']; $n = 1; $recs = [];
    foreach ($eqs as $e) {
        $fs = $e['analysis']['findings'] ?? [];
        if (!$fs) { $L[] = "$n. {$e['label']}: 주요 지표가 정상 범위 내에서 안정적으로 운영되고 있습니다."; $n++; continue; }
        foreach ($fs as $x) {
            if ($x['severity'] === 'info') continue;
            $L[] = "$n. {$e['label']}: {$x['title']} - {$x['detail']}"; $recs[] = $x['recommendation']; $n++;
        }
    }
    $L[] = ''; $L[] = '[권고 사항]';
    foreach (array_unique($recs) as $r) $L[] = "- $r";
    if (!$recs) $L[] = '- 현 상태 유지를 위해 정기적인 모니터링을 권장합니다.';
    if ($type === 'workplan') { $L[] = ''; $L[] = '[작업 리스크]'; $L[] = '- 작업 전 AutoSupport 수집 및 구성 백업을 완료하십시오.'; $L[] = '- HA Takeover 가능 여부와 Spare 디스크 상태를 사전에 확인하십시오.'; }
    return implode("\n", $L);
}

function ai_generate($type, $sub, $customer, $eqs, $extra = '') {
    $key = conf('anthropic_key');
    if (!$key) return ['text' => ai_rule_based($eqs, $type), 'model' => 'rule-based', 'fallback' => true, 'note' => 'Anthropic API 키가 설정되지 않아 규칙 기반 초안을 만들었습니다.'];
    try {
        if (!function_exists('curl_init')) throw new Exception('PHP cURL 확장이 없습니다.');
        $body = [
            'model' => conf('ai_model') ?: 'claude-opus-5-5', 'max_tokens' => 16000, 'system' => AI_SYSTEM_PROMPT,
            'output_config' => ['effort' => 'medium'], 'fallbacks' => 'default',
            'messages' => [['role' => 'user', 'content' => "다음 분석 데이터를 바탕으로 보고서용 장애 및 진단 의견을 작성해 주세요.\n\n" . ai_payload($type, $sub, $customer, $eqs, $extra)]],
        ];
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 170, CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2026-10-21', 'anthropic-beta: server-side-fallback-2026-07-01'],
            CURLOPT_POSTFIELDS => je($body)]);
        $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $cerr = curl_error($ch); curl_close($ch);
        if ($raw === false) throw new Exception("연결 실패: $cerr");
        $res = json_decode($raw, true);
        if ($code >= 400 || !is_array($res)) throw new Exception('API 오류 ' . $code . ': ' . ($res['error']['message'] ?? mb_substr((string)$raw, 0, 200)));
        if (($res['stop_reason'] ?? '') === 'refusal') throw new Exception('AI가 응답을 거절했습니다.');
        $text = trim(implode("\n", array_column(array_filter($res['content'] ?? [], function ($b) { return ($b['type'] ?? '') === 'text'; }), 'text')));
        if ($text === '') throw new Exception('빈 응답');
        return ['text' => $text, 'model' => $res['model'] ?? $body['model'], 'fallback' => false, 'note' => ''];
    } catch (Throwable $e) {
        error_log('MRS Claude 호출 실패: ' . $e->getMessage());
        return ['text' => ai_rule_based($eqs, $type), 'model' => 'rule-based', 'fallback' => true, 'note' => 'Claude 호출에 실패해 규칙 기반 초안을 만들었습니다: ' . $e->getMessage()];
    }
}
