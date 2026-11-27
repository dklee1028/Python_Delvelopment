<?php
/* 성능 아카이브(NetApp perf_archive.7z) 처리
   - 웹호스팅 PHP 에는 7z 해제 기능이 없으므로, 압축 해제와 1차 집계는 엔지니어 브라우저(7-Zip WASM)에서 합니다.
     서버에는 (1) 원본 파일을 조각(chunk)으로 나눠 올리고 (2) 브라우저가 만든 지표 요약(JSON)을 보냅니다.
   - 서버는 요약을 규칙으로 판정하고, API 키가 있으면 Claude 가 세부 해석을 씁니다.
   - 원본은 용량이 커서 보관 기한(기본 90일)이 지나면 자동 삭제합니다. 분석 결과는 남겨 보고서에 계속 쓸 수 있습니다. */

const PERF_KEEP_DAYS = 90;
const PERF_MAX_BYTES = 4 * 1073741824; // 4GB

const PERF_SYSTEM_PROMPT = "당신은 NetApp ONTAP 성능 분석 전문 컨설턴트입니다.
엔지니어가 perf_archive(성능 아카이브)에서 뽑은 카운터 통계 요약을 보고, 고객 보고서에 넣을 '성능 세부 분석'을 작성합니다.

작성 원칙:
- 한국어, 간결한 보고서 문체. 반드시 요약에 있는 수치(평균·95백분위·최대, 단위)를 근거로 씁니다.
- 데이터에 없는 사실은 만들지 않습니다. 카운터 의미가 불확실하면 \"확인 필요\"로 적습니다.
- counter_values_present 가 false 이면 성능 수치를 추정하지 말고, asup_package(시스템 정보·AutoSupport 이력·디스크 상태) 위주로 쓰고 \"성능 카운터 값 없음\"을 밝힙니다.
- 다음 구성으로 작성합니다:
  [성능 종합] 한두 줄 (양호/주의/위험 + 핵심 근거)
  [주요 지표] CPU, 지연시간(Latency), IOPS, 처리량, 디스크 사용률 순으로 확인된 것만 번호 목록
  [병목 및 위험 요인] 있으면 목록, 없으면 \"특이 사항 없음\"
  [권고 사항] 실행 가능한 조치
- 전체 분량은 A4 반 페이지 이내.";

function perf_out($r, $full = false) {
    $sum = jd($r['summary'] ?? '', []); $an = jd($r['analysis'] ?? '', []);
    $o = ['id' => (int)$r['id'], 'customer_id' => (int)$r['customer_id'], 'equipment_id' => (int)$r['equipment_id'], 'original_name' => $r['original_name'],
        'size_bytes' => (int)$r['size_bytes'], 'received_bytes' => (int)$r['received_bytes'], 'status' => $r['status'],
        'status_label' => ['uploading' => '올리는 중', 'uploaded' => '분석 대기', 'done' => '분석 완료', 'unreadable' => '읽을 수 있는 지표 없음', 'failed' => '실패'][$r['status']] ?? $r['status'],
        'period_from' => $r['period_from'], 'period_to' => $r['period_to'], 'expires_at' => $r['expires_at'] ? substr($r['expires_at'], 0, 10) : null,
        'keep_forever' => (bool)(int)$r['keep_forever'], 'file_available' => !$r['file_deleted_at'] && $r['stored_path'] && (int)$r['received_bytes'] >= (int)$r['size_bytes'] && (int)$r['size_bytes'] > 0,
        'file_deleted_at' => $r['file_deleted_at'] ? substr($r['file_deleted_at'], 0, 10) : null, 'created_at' => substr($r['created_at'], 0, 16),
        'verdict' => $an['status'] ?? null, 'findings_count' => count($an['findings'] ?? []), 'metrics_count' => count($sum['metrics'] ?? []), 'ai_model' => $r['ai_model'] ?: ''];
    if ($full) { $o['summary'] = $sum ?: null; $o['analysis'] = $an ?: null; }
    return $o;
}
function perf_get($id) { $r = one("SELECT * FROM mrs_perf_archive WHERE id=?", [(int)$id]); if (!$r) fail('성능 아카이브를 찾을 수 없습니다.', 404); return $r; }
function ini_bytes($v) { $v = trim((string)$v); if ($v === '') return 0; $n = (float)$v; $u = strtolower(substr($v, -1)); return (int)($u === 'g' ? $n * 1073741824 : ($u === 'm' ? $n * 1048576 : ($u === 'k' ? $n * 1024 : $n))); }
function perf_chunk_size() {
    $lim = min(array_filter([ini_bytes(ini_get('upload_max_filesize')), ini_bytes(ini_get('post_max_size'))]) ?: [2097152]);
    return (int)max(262144, min(4194304, $lim - 131072)); // 여유를 두고 최대 4MB
}

/* 보관 기한이 지난 원본 삭제 + 오래된 미완료 업로드 정리 (크론이 없어도 접속할 때 6시간에 한 번 실행) */
function perf_cleanup($force = false) {
    try {
        $last = (int)get_setting('perf_cleanup_at', 0);
        if (!$force && time() - $last < 21600) return 0;
        set_setting('perf_cleanup_at', (string)time());
        $n = 0; $now = now();
        foreach (all("SELECT * FROM mrs_perf_archive WHERE file_deleted_at IS NULL AND keep_forever=0 AND expires_at IS NOT NULL AND expires_at<?", [$now]) as $r) {
            if ($r['stored_path']) @unlink(data_dir() . '/' . $r['stored_path']);
            upd('mrs_perf_archive', ['file_deleted_at' => $now, 'updated_at' => $now], $r['id']); $n++;
        }
        foreach (all("SELECT * FROM mrs_perf_archive WHERE status='uploading' AND created_at<?", [date('Y-m-d H:i:s', time() - 2 * 86400)]) as $r) {
            if ($r['stored_path']) @unlink(data_dir() . '/' . $r['stored_path']);
            if (jd($r['summary'] ?? '', [])) upd('mrs_perf_archive', ['status' => 'uploaded', 'file_deleted_at' => $now, 'updated_at' => $now], $r['id']);
            else q("DELETE FROM mrs_perf_archive WHERE id=?", [$r['id']]);
            $n++;
        }
        if ($n) audit('perf.cleanup', '', "$n 건");
        return $n;
    } catch (Throwable $e) { error_log('MRS perf_cleanup: ' . $e->getMessage()); return 0; }
}

/* 브라우저가 보낸 요약을 정리 (크기·형식 제한) */
function perf_clean_summary($s) {
    $s = (array)$s; $num = function ($v) { return is_numeric($v) ? round((float)$v, 3) : null; };
    $m = [];
    foreach (array_slice((array)($s['metrics'] ?? []), 0, 150) as $x) {
        $x = (array)$x; if (!isset($x['counter'])) continue;
        $tops = [];
        foreach (array_slice((array)($x['top'] ?? []), 0, 5) as $t) { $t = (array)$t; $tops[] = ['name' => mb_substr((string)($t['name'] ?? ''), 0, 80), 'avg' => $num($t['avg'] ?? null), 'max' => $num($t['max'] ?? null)]; }
        $m[] = ['group' => mb_substr((string)($x['group'] ?? 'other'), 0, 20), 'object' => mb_substr((string)($x['object'] ?? ''), 0, 60), 'counter' => mb_substr((string)$x['counter'], 0, 80),
            'unit' => mb_substr((string)($x['unit'] ?? ''), 0, 12), 'n' => (int)($x['n'] ?? 0), 'avg' => $num($x['avg'] ?? null), 'p95' => $num($x['p95'] ?? null),
            'max' => $num($x['max'] ?? null), 'min' => $num($x['min'] ?? null), 'instances' => (int)($x['instances'] ?? 0), 'top' => $tops];
    }
    $files = [];
    foreach (array_slice((array)($s['files'] ?? []), 0, 60) as $f) { $f = (array)$f; $files[] = ['path' => mb_substr((string)($f['path'] ?? ''), 0, 200), 'size' => (int)($f['size'] ?? 0), 'kind' => mb_substr((string)($f['kind'] ?? ''), 0, 20)]; }
    return ['archive' => mb_substr((string)($s['archive'] ?? ''), 0, 200), 'files_total' => (int)($s['files_total'] ?? 0), 'text_files' => (int)($s['text_files'] ?? 0),
        'bytes_total' => (int)($s['bytes_total'] ?? 0), 'formats' => array_slice(array_map('strval', (array)($s['formats'] ?? [])), 0, 10),
        'period' => ['from' => mb_substr((string)($s['period']['from'] ?? ''), 0, 19), 'to' => mb_substr((string)($s['period']['to'] ?? ''), 0, 19)],
        'nodes' => array_slice(array_map(function ($v) { return mb_substr((string)$v, 0, 60); }, (array)($s['nodes'] ?? [])), 0, 8),
        'asup' => perf_clean_asup($s['asup'] ?? null),
        'metrics' => $m, 'files' => $files, 'samples' => mb_substr((string)($s['samples'] ?? ''), 0, 20000), 'note' => mb_substr((string)($s['note'] ?? ''), 0, 500)];
}

function perf_clean_asup($a) {
    if (!$a) return null; $a = (array)$a; $t = function ($v, $n = 120) { return mb_substr((string)$v, 0, $n); };
    $hist = []; foreach ((array)($a['history'] ?? []) as $k => $v) $hist[$t($k, 40)] = (int)$v;
    $fails = []; foreach (array_slice((array)($a['recent_failures'] ?? []), 0, 5) as $f) { $f = (array)$f; $fails[] = ['at' => $t($f['at'] ?? '', 30), 'status' => $t($f['status'] ?? '', 30), 'dest' => $t($f['dest'] ?? '', 30), 'subject' => $t($f['subject'] ?? '', 120)]; }
    return ['hostname' => $t($a['hostname'] ?? '', 60), 'model' => $t($a['model'] ?? '', 40), 'serial' => $t($a['serial'] ?? '', 40), 'os' => $t($a['os'] ?? ''), 'cluster' => $t($a['cluster'] ?? '', 60),
        'partner' => $t($a['partner'] ?? '', 60), 'subject' => $t($a['subject'] ?? '', 200), 'history' => $hist, 'recent_failures' => $fails,
        'spare' => isset($a['spare']) ? (int)$a['spare'] : null, 'failed_disks' => array_slice(array_map($t, (array)($a['failed_disks'] ?? [])), 0, 10),
        'orphaned' => (int)($a['orphaned'] ?? 0), 'raid_bad' => array_slice(array_map($t, (array)($a['raid_bad'] ?? [])), 0, 10), 'presets' => (int)($a['presets'] ?? 0),
        'counter_data' => (bool)($a['counter_data'] ?? false)];
}
/* 규칙 판정: 카운터 이름과 단위로 CPU·지연시간·디스크 사용률을 봅니다 */
function perf_ms($x, $v) { if ($v === null) return null; $u = strtolower($x['unit']); return $u === 'us' ? $v / 1000 : ($u === 's' ? $v * 1000 : $v); }
function perf_rules($sum) {
    $f = []; $add = function ($sev, $title, $detail, $rec) use (&$f) { $f[] = ['severity' => $sev, 'category' => '성능', 'title' => $title, 'detail' => $detail, 'recommendation' => $rec]; };
    $kpi = [];
    foreach ($sum['metrics'] ?? [] as $x) {
        $c = strtolower($x['counter']); $g = $x['group']; $name = ($x['object'] ? $x['object'] . ':' : '') . $x['counter'];
        if ($g === 'cpu' && $x['unit'] === '%' && $x['avg'] !== null) {
            $kpi['cpu'] = max($kpi['cpu'] ?? 0, $x['avg']);
            if (($x['p95'] ?? 0) >= 90 || $x['avg'] >= 80) $add('critical', "CPU 사용률 높음 ($name 평균 {$x['avg']}%, 95% {$x['p95']}%)", '컨트롤러 CPU 여유가 부족해 응답 지연이 생길 수 있습니다.', '부하가 몰리는 시간대·워크로드를 확인하고 분산 또는 증설을 검토하십시오.');
            elseif ($x['avg'] >= 60 || ($x['p95'] ?? 0) >= 80) $add('warning', "CPU 사용률 주의 ($name 평균 {$x['avg']}%, 95% {$x['p95']}%)", '피크 시간대 CPU 사용률이 높습니다.', 'CPU 추이를 모니터링하고 백업·스냅미러 일정을 분산하십시오.');
        } elseif ($g === 'latency' && strpos($c, 'latency') !== false) {
            $avg = perf_ms($x, $x['avg']); $p95 = perf_ms($x, $x['p95']);
            if ($avg === null) continue;
            $kpi['latency_ms'] = max($kpi['latency_ms'] ?? 0, $avg);
            $txt = sprintf('%s 평균 %.2fms, 95%% %.2fms', $name, $avg, $p95 ?? 0);
            if ($avg >= 20 || ($p95 ?? 0) >= 50) $add('critical', "지연시간 높음 ($txt)", '응답 지연으로 업무 영향이 있을 수 있는 수준입니다.', '지연이 큰 볼륨/LUN·디스크를 확인하고 QoS, 워크로드 분산, 디스크 증설을 검토하십시오.');
            elseif ($avg >= 5 || ($p95 ?? 0) >= 20) $add('warning', "지연시간 주의 ($txt)", '일반 권장치(평균 5ms 이하)를 넘었습니다.', '피크 시간대 지연 원인(디스크 사용률, CP 처리, 네트워크)을 확인하십시오.');
        } elseif ($g === 'disk' && $x['unit'] === '%' && $x['avg'] !== null) {
            $kpi['disk_util'] = max($kpi['disk_util'] ?? 0, $x['avg']);
            if ($x['avg'] >= 70 || ($x['p95'] ?? 0) >= 90) $add('critical', "디스크 사용률 높음 ($name 평균 {$x['avg']}%, 95% {$x['p95']}%)", '디스크가 바빠 I/O 대기가 생기는 수준입니다.', '디스크 추가 또는 데이터 재배치(Aggregate 분산)를 검토하십시오.');
            elseif ($x['avg'] >= 50 || ($x['p95'] ?? 0) >= 80) $add('warning', "디스크 사용률 주의 ($name 평균 {$x['avg']}%)", '피크 시간대 디스크 사용률이 높습니다.', '디스크 사용률 추이를 모니터링하십시오.');
        } elseif ($g === 'ops' && $x['avg'] !== null) $kpi['ops'] = max($kpi['ops'] ?? 0, $x['avg']);
    }
    // AutoSupport 패키지(성능 아카이브에 같이 들어 있는 시스템 정보)에서 찾은 문제
    $as = $sum['asup'] ?? null;
    if ($as) {
        $h = $as['history'] ?? []; $tf = ($h['transmission-failed'] ?? 0); $cf = ($h['collection-failed'] ?? 0);
        if ($tf + $cf > 0) $add('warning', "AutoSupport 전송 실패 {$tf}건, 수집 실패 {$cf}건", '최근 AutoSupport 이력에서 실패가 확인되었습니다. 장애 시 NetApp 사전 감지·부품 자동 발송이 되지 않을 수 있습니다.', 'AutoSupport 전송 경로(SMTP/HTTPS, 프록시, 방화벽)와 메일 서버 설정을 점검하십시오.');
        if ($as['spare'] !== null && $as['spare'] === 0) $add('warning', 'Spare 디스크 없음 (sysconfig -r)', 'Pool0/Pool1 spare disks (empty) 로 확인되었습니다. 디스크 장애 시 자동 재구성이 되지 않습니다.', '노드별 최소 1~2EA Spare 디스크를 확보하십시오.');
        if ($as['failed_disks']) $add('critical', 'Failed/Broken 디스크 ' . count($as['failed_disks']) . 'EA (sysconfig -r)', implode(', ', $as['failed_disks']), '디스크 교체(RMA)를 진행하십시오.');
        if ($as['orphaned']) $add('warning', "Orphaned 디스크 {$as['orphaned']}EA (sysconfig -r)", 'RAID 그룹에서 분리된(orphaned) 디스크가 있습니다.', '디스크 상태와 소유권을 확인하고 Spare 로 전환하거나 교체하십시오.');
        if ($as['raid_bad']) $add('critical', 'Aggregate/RAID 그룹 비정상', implode(', ', $as['raid_bad']), 'Aggregate 상태(storage aggregate show)와 누락·Failed 디스크를 확인하고 디스크 교체·RAID 재구성을 진행하십시오.');
    }
    // 같은 제목이 여럿이면 심각한 것부터 최대 8개
    usort($f, function ($a, $b) { return SEV_ORDER[$a['severity']] <=> SEV_ORDER[$b['severity']]; });
    $f = array_slice($f, 0, 8);
    $st = in_array('critical', array_column($f, 'severity'), true) ? 'critical' : ($f ? 'warning' : 'normal');
    return ['status' => $st, 'findings' => $f, 'kpi' => $kpi];
}
function perf_rule_text($sum, $an) {
    $L = ['[성능 종합] ' . (['critical' => '위험', 'warning' => '주의', 'normal' => '양호'][$an['status']] ?? '양호') . ($sum['metrics'] ?? [] ? ' - 성능 지표 ' . count($sum['metrics']) . '개를 기준으로 판정했습니다.' : ' - 성능 카운터 값이 없어 시스템 정보(AutoSupport)를 기준으로 판정했습니다.'), '', '[주요 지표]'];
    $n = 1;
    foreach (['cpu' => 'CPU', 'latency' => '지연시간', 'ops' => 'IOPS', 'throughput' => '처리량', 'disk' => '디스크 사용률', 'net' => '네트워크'] as $g => $lab) {
        foreach (array_slice(array_values(array_filter($sum['metrics'] ?? [], function ($x) use ($g) { return $x['group'] === $g; })), 0, 3) as $x)
            $L[] = $n++ . ". $lab - " . ($x['object'] ? $x['object'] . ':' : '') . "{$x['counter']}: 평균 {$x['avg']}{$x['unit']}, 95% {$x['p95']}{$x['unit']}, 최대 {$x['max']}{$x['unit']}";
    }
    if ($n === 1) $L[] = '- 이 아카이브에는 성능 카운터 값이 없습니다. (카운터 정의와 시스템 정보만 포함)';
    if ($as = ($sum['asup'] ?? null)) {
        $L[] = ''; $L[] = '[시스템 정보 (AutoSupport 패키지)]';
        $L[] = "- {$as['hostname']} / {$as['model']} / S/N {$as['serial']}" . ($as['partner'] ? " (파트너 {$as['partner']})" : '');
        if ($as['os']) $L[] = '- ' . $as['os'];
        if ($as['subject']) $L[] = '- ' . $as['subject'];
        if ($as['history']) $L[] = '- AutoSupport 이력: ' . implode(', ', array_map(function ($k, $v) { return "$k {$v}건"; }, array_keys($as['history']), $as['history']));
    }
    $L[] = ''; $L[] = '[병목 및 위험 요인]';
    foreach ($an['findings'] as $x) $L[] = '- ' . $x['title'];
    if (!$an['findings']) $L[] = '- 특이 사항 없음';
    $L[] = ''; $L[] = '[권고 사항]';
    foreach (array_unique(array_column($an['findings'], 'recommendation')) as $r) $L[] = "- $r";
    if (!$an['findings']) $L[] = '- 현재 수준을 유지하며 정기적으로 성능 아카이브를 수집해 추이를 비교하십시오.';
    return implode("\n", $L);
}
function perf_analyze($id, $useAi = true) {
    $r = perf_get($id); $sum = jd($r['summary'], []);
    if (!$sum) fail('분석할 요약이 없습니다. 파일을 다시 올려 주세요.');
    if (!($sum['metrics'] ?? []) && !($sum['asup'] ?? null)) {
        upd('mrs_perf_archive', ['status' => 'unreadable', 'analysis' => je(['status' => 'normal', 'findings' => [], 'kpi' => new stdClass(),
            'text' => '아카이브 안에서 읽을 수 있는 성능 지표(텍스트/CSV/JSON 형식 카운터)를 찾지 못했습니다. 파일 목록은 요약에 남겨 두었습니다.', 'fallback' => true]), 'updated_at' => now()], $id);
        return perf_get($id);
    }
    $an = perf_rules($sum); $text = ''; $model = 'rule-based'; $note = '';
    if (!($sum['metrics'] ?? [])) $note = '이 파일에는 성능 카운터 값이 없어(프리셋 정의·시스템 정보만 포함) 시스템 상태 위주로 분석했습니다.';
    if ($useAi && conf('anthropic_key')) {
        try {
            $eq = one("SELECT name, model, os_version FROM mrs_equipment WHERE id=?", [$r['equipment_id']]);
            $payload = je(['equipment' => $eq, 'period' => $sum['period'], 'nodes' => $sum['nodes'], 'asup_package' => $sum['asup'] ?? null, 'counter_values_present' => (bool)($sum['metrics'] ?? []), 'rule_findings' => $an['findings'],
                'metrics' => array_slice($sum['metrics'], 0, 100), 'unparsed_samples' => mb_substr($sum['samples'] ?? '', 0, 8000)]);
            [$text, $model] = claude_call(PERF_SYSTEM_PROMPT, "다음 성능 아카이브 요약으로 세부 성능 분석을 작성해 주세요.\n\n" . $payload, 8000);
        } catch (Throwable $e) { $note = trim($note . ' Claude 호출 실패로 규칙 기반 분석을 넣었습니다: ' . $e->getMessage()); }
    } elseif ($useAi) $note = trim($note . ' Anthropic API 키가 없어 규칙 기반 분석을 넣었습니다.');
    if ($text === '') $text = perf_rule_text($sum, $an);
    $an['text'] = $text; $an['note'] = $note; $an['fallback'] = $model === 'rule-based';
    upd('mrs_perf_archive', ['status' => 'done', 'analysis' => je($an), 'ai_model' => mb_substr($model, 0, 60), 'updated_at' => now()], $id);
    return perf_get($id);
}

/* 보고서에 넣을 장비별 최신 성능 분석 (점검일 기준 120일 이내) */
function perf_for_equipment($eid, $date) {
    $to = date('Y-m-d 23:59:59', strtotime($date ?: today()) ?: time()); $from = date('Y-m-d H:i:s', strtotime($to) - 120 * 86400);
    $r = one("SELECT * FROM mrs_perf_archive WHERE equipment_id=? AND status='done' AND created_at BETWEEN ? AND ? ORDER BY created_at DESC LIMIT 1", [(int)$eid, $from, $to]);
    if (!$r) return null;
    $sum = jd($r['summary'], []); $an = jd($r['analysis'], []);
    $order = ['cpu' => 0, 'latency' => 1, 'ops' => 2, 'throughput' => 3, 'disk' => 4, 'net' => 5];
    $ms = $sum['metrics'] ?? [];
    usort($ms, function ($a, $b) use ($order) { return [($order[$a['group']] ?? 9)] <=> [($order[$b['group']] ?? 9)]; });
    return ['id' => (int)$r['id'], 'file' => $r['original_name'], 'uploaded' => substr($r['created_at'], 0, 10), 'period' => $sum['period'] ?? [], 'status' => $an['status'] ?? 'normal',
        'findings' => $an['findings'] ?? [], 'kpi' => $an['kpi'] ?? [], 'text' => $an['text'] ?? '', 'model' => $r['ai_model'] ?: '', 'metrics' => array_slice($ms, 0, 40)];
}
