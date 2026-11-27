<?php
/* 파싱된 로그 → 컨설턴트 관점의 진단(Findings)·건강점수·판정 */

const SEV_ORDER = ['critical' => 0, 'warning' => 1, 'info' => 2];
const STATUS_LONG = ['normal' => '정상 (Normal)', 'warning' => '요주의 (Warning)', 'critical' => '위험 (Critical)'];

function vol_remark($pct) { return $pct >= 90 ? 'Check Req.' : ($pct >= 80 ? 'Monitoring' : 'Normal'); }
function is_root_aggr($n) { return (bool)preg_match('/^aggr0|root/i', $n); }
function old_ontap($v) {
    if (!$v) return false;
    if (stripos($v, '7-mode') !== false) return true;
    return preg_match('/(\d+)\.(\d+)/', $v, $m) && [(int)$m[1], (int)$m[2]] < [9, 8];
}

function analyze($eq, $p) {
    $f = [];
    $add = function ($sev, $cat, $title, $detail, $rec) use (&$f) { $f[] = ['severity' => $sev, 'category' => $cat, 'title' => $title, 'detail' => $detail, 'recommendation' => $rec]; };
    foreach ($p['aggregates'] ?? [] as $a) {
        if (is_root_aggr($a['name'])) {
            if ($a['pct'] >= 98) $add('info', '용량', "Root Aggregate {$a['name']} 사용률 {$a['pct']}%", 'Root Aggregate는 구조상 사용률이 높게 표시될 수 있습니다.',
                'Root 볼륨 여유 공간(vol0) 및 로그/코어 파일 누적 여부를 확인하십시오.');
            continue;
        }
        if ($a['pct'] >= 95) $add('critical', '용량', "Aggregate {$a['name']} 사용률 {$a['pct']}%", sprintf('여유 공간 %.1f GB로 쓰기 중단 및 서비스 장애 위험이 매우 높습니다.', $a['avail_gb']),
            '불필요 데이터·스냅샷 정리 또는 디스크 증설을 즉시 진행하십시오.');
        elseif ($a['pct'] >= 85) $add('warning', '용량', "Aggregate {$a['name']} 사용률 {$a['pct']}%", '권장 기준(80%)을 초과하여 성능 저하 및 용량 부족이 우려됩니다.',
            '용량 추이 모니터링 및 증설 계획 수립을 권장합니다.');
    }
    $vols = $p['volumes'] ?? [];
    $hot = array_values(array_filter($vols, function ($v) { return $v['pct'] >= 90; }));
    if ($hot) $add('warning', '용량', '고사용률 볼륨 ' . count($hot) . '개 (90% 이상)', implode(', ', array_map(function ($v) { return "{$v['name']}({$v['pct']}%)"; }, array_slice($hot, 0, 6))),
        '볼륨 자동 증가(Autosize) 설정 확인 및 스냅샷 보존 정책 검토를 권장합니다.');
    $ino = array_values(array_filter($vols, function ($v) { return ($v['inode_pct'] ?? 0) >= 85; }));
    if ($ino) $add('warning', '용량', 'Inode 사용률 높은 볼륨 ' . count($ino) . '개', implode(', ', array_map(function ($v) { return "{$v['name']}({$v['inode_pct']}%)"; }, array_slice($ino, 0, 6))),
        'maxfiles 상향 또는 파일 정리를 검토하십시오.');
    $failed = $p['disks']['failed'] ?? [];
    if ($failed) $add('critical', '디스크', 'Failed/Broken 디스크 ' . count($failed) . 'EA', implode(', ', array_column(array_slice($failed, 0, 8), 'disk')),
        'RAID 재구성 상태 확인 후 즉시 디스크 교체(RMA)를 진행하십시오.');
    if (!empty($p['aggregates']) && (int)($p['disks']['spare'] ?? 0) === 0) $add('warning', '디스크', 'Spare 디스크 미확인', '로그에서 Spare 디스크가 확인되지 않았습니다.',
        '노드별 최소 1~2EA Spare 디스크 확보를 권장합니다.');
    $ha = (array)($p['ha'] ?? []);
    if (array_key_exists('takeover_possible', $ha) && $ha['takeover_possible'] === false) $add('critical', 'HA', 'Takeover 불가 상태',
        'HA 파트너 간 Takeover가 불가하여 컨트롤러 장애 시 서비스 중단 위험이 있습니다.', 'Interconnect 및 Failover 설정을 점검하고 원인을 해소하십시오.');
    $ports = $p['ports_down'] ?? [];
    if ($ports) $add('warning', '네트워크', 'Down 포트 ' . count($ports) . '개', implode(', ', array_slice($ports, 0, 10)) . ' 포트가 Down 상태로 Single Path 운영 가능성이 있습니다.',
        '사용 포트 여부 확인 후 케이블/SFP 점검 및 이중화 구성을 권장합니다.');
    $ev = $p['events'] ?? [];
    $names = function ($sevs) use ($ev) { return mb_substr(implode(', ', array_column(array_filter($ev['top'] ?? [], function ($e) use ($sevs) { return in_array($e['severity'], $sevs, true); }), 'event')), 0, 200); };
    if (!empty($ev['panic'])) $add('critical', '시스템 로그', "Panic 흔적 {$ev['panic']}건", '시스템 Panic 관련 로그가 발견되었습니다.', '코어 덤프 수집 및 벤더 기술지원 케이스 오픈을 권장합니다.');
    if (!empty($ev['emergency']) || !empty($ev['alert'])) $add('critical', '시스템 로그', 'EMERGENCY/ALERT 이벤트 ' . (($ev['emergency'] ?? 0) + ($ev['alert'] ?? 0)) . '건',
        $names(['emergency', 'alert']), '해당 이벤트의 원인 분석 및 조치가 필요합니다.');
    if (!empty($ev['error'])) $add('warning', '시스템 로그', "ERROR 이벤트 {$ev['error']}건", $names(['error']), '반복 발생 이벤트를 중심으로 원인을 확인하십시오.');
    $sys = (array)($p['system'] ?? []);
    $ver = ($sys['os_version'] ?? '') ?: ($eq['os_version'] ?? '');
    if (old_ontap($ver)) $add('warning', '펌웨어/OS', "OS 버전 $ver", '제조사 지원 종료(EOS) 또는 노후 버전으로 보안·호환성 패치 수급이 제한될 수 있습니다.',
        '권장 버전으로의 업그레이드(또는 장비 교체) 계획 수립을 권장합니다.');
    if (array_key_exists('autosupport', $p) && $p['autosupport'] === false) $add('warning', '시스템', 'AutoSupport 비활성화',
        '장애 발생 시 벤더 사전 감지 및 부품 자동 발송이 불가합니다.', 'AutoSupport 활성화를 권장합니다.');

    usort($f, function ($a, $b) { return SEV_ORDER[$a['severity']] <=> SEV_ORDER[$b['severity']]; });
    $cnt = ['critical' => 0, 'warning' => 0, 'info' => 0];
    foreach ($f as $x) $cnt[$x['severity']]++;
    $score = max(0, 100 - $cnt['critical'] * 25 - $cnt['warning'] * 8);
    $status = $cnt['critical'] ? 'critical' : ($cnt['warning'] ? 'warning' : 'normal');
    $total = 0; $used = 0;
    foreach ($p['aggregates'] ?? [] as $a) if (!is_root_aggr($a['name'])) { $total += $a['total_gb']; $used += $a['used_gb']; }
    return ['findings' => $f, 'score' => $score, 'status' => $status, 'status_label' => STATUS_LONG[$status],
        'capacity' => ['total_gb' => $total, 'used_gb' => $used, 'pct' => $total ? (int)round($used / $total * 100) : 0], 'counts' => $cnt];
}
function overall_status($per) {
    $st = array_column($per, 'status');
    return in_array('critical', $st, true) ? 'critical' : (in_array('warning', $st, true) ? 'warning' : 'normal');
}

/* INSPECTION_MATRIX 항목별 자동 판정: ✔ 정상 / ! 주의 / ✖ 이상 / - 데이터 없음 */
function auto_checks($p, $an, $eqOs) {
    $has = ($p['lines'] ?? 0) > 1; $f = $an['findings'] ?? [];
    $by = function ($cat) use ($f) { $s = array_column(array_filter($f, function ($x) use ($cat) { return $x['category'] === $cat; }), 'severity');
        return in_array('critical', $s, true) ? '✖' : (in_array('warning', $s, true) ? '!' : '✔'); };
    $mx = null;
    foreach ($p['aggregates'] ?? [] as $a) if (!is_root_aggr($a['name'])) $mx = max($mx ?? 0, $a['pct']);
    $ha = (array)($p['ha'] ?? []); $to = array_key_exists('takeover_possible', $ha) ? $ha['takeover_possible'] : null;
    $ev = $p['events'] ?? []; $asup = $p['autosupport'] ?? null;
    return [
        'Controller Health' => !empty($ev['panic']) ? '✖' : ($has ? '✔' : '-'),
        'PSU Status' => '-', 'Fan/Temperature' => '-',
        'Failed Disks' => $has ? (!empty($p['disks']['failed']) ? '✖' : '✔') : '-',
        'Aggregate Usage' => $mx === null ? '-' : ($mx >= 95 ? '✖' : ($mx >= 85 ? '!' : '✔')),
        'Network Ports Status' => $has ? (!empty($p['ports_down']) ? '!' : '✔') : '-',
        'Takeover Possible' => $to === null ? '-' : ($to ? '✔' : '✖'),
        'AutoSupport' => $asup === null ? '-' : ($asup ? '✔' : '!'),
        'System Logs' => $has ? $by('시스템 로그') : '-',
        'Firmware/ONTAP Ver.' => ($has || $eqOs) ? $by('펌웨어/OS') : '-',
    ];
}
