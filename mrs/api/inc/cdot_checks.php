<?php
/* ONTAP cDOT 점검 명령어 목록 (2026-11-27 고객사 기준 리스트 반영)
   - 구분 '평시'  : 정기점검 보고서에 들어가는 항목
   - 구분 '상세'  : 특별점검 보고서에서 평시 항목과 함께 들어가는 항목
   - cmd 는 엔지니어가 실제로 입력하는 명령어(수정명령어 우선), match 는 로그의 프롬프트 줄에서 명령어를 알아보는 정규식입니다.
   - 리스트에서 '중복 삭제 요망'으로 표시된 명령어는 별도 항목으로 만들지 않고 해당 항목의 match 에만 포함했습니다.
   rule: empty_ok(출력 행이 있으면 문제) / bad(정규식에 맞는 줄이 있으면 문제) / custom 함수 / info(수집만) */

const CDOT_CHECKS = [
    // ───── 평시 ─────
    ['id' => 'image', 'kind' => 'base', 'group' => '버전·클러스터', 'cmd' => 'cluster image show', 'match' => '/^(cl(uster)?\s+im(age)?\s+show|version)\b/', 'point' => 'ONTAP 버전 및 노드별 이미지 일치 여부', 'rule' => 'custom', 'fn' => 'ck_image', 'visual' => 'partner_fw'],
    ['id' => 'cluster', 'kind' => 'base', 'group' => '버전·클러스터', 'cmd' => 'cluster show', 'match' => '/^cl(uster)?\s+show\s*$/', 'point' => '노드 Health=true, Eligibility=true', 'rule' => 'bad', 'bad' => '/\bfalse\b/i', 'sev' => 'critical', 'what' => 'Health/Eligibility false 노드', 'visual' => 'controller'],
    ['id' => 'node', 'kind' => 'base', 'group' => '노드 상태', 'cmd' => 'system node show -instance', 'match' => '/^sys(tem)?\s+node\s+show\b/', 'point' => '노드별 Health, Uptime, 모델', 'rule' => 'bad', 'bad' => '/^\s*(Health|Node Health)\s*:\s*false|^\S+\s+\S+\s+false\b/im', 'sev' => 'critical', 'what' => 'Health false 노드', 'visual' => 'controller'],
    ['id' => 'failover', 'kind' => 'base', 'group' => 'HA 상태', 'cmd' => 'storage failover show -instance', 'match' => '/^sto(rage)?\s+fa(ilover)?\s+show(\s+-ins(tance)?)?\s*$/', 'point' => "Takeover Possible=true, 'Connected to partner'", 'rule' => 'custom', 'fn' => 'ck_failover', 'visual' => 'controller'],
    ['id' => 'health', 'kind' => 'base', 'group' => '하드웨어 상태', 'cmd' => 'system health status show', 'match' => '/^sys(tem)?\s+he(alth)?\s+st(atus)?\s+show/', 'point' => "전체 Health 'ok'", 'rule' => 'bad', 'bad' => '/\b(degraded|failed|critical)\b/i', 'sev' => 'warning', 'what' => 'Health 비정상'],
    ['id' => 'alert', 'kind' => 'base', 'group' => '하드웨어 상태', 'cmd' => 'system health alert show', 'match' => '/^((sys(tem)?\s+)?he(alth)?\s+)?ale(rt)?\s+show/', 'point' => '활성 헬스 알림 (없어야 정상)', 'rule' => 'custom', 'fn' => 'ck_alert'],
    ['id' => 'sensor', 'kind' => 'base', 'group' => '하드웨어 상태', 'cmd' => 'system node environment sensors show', 'match' => '/^sys(tem)?\s+node\s+en(vironment)?\s+se(nsors)?\s+show/', 'point' => '전체 센서 수집 후 비정상 상태 확인', 'rule' => 'bad', 'bad' => '/\b(fault|failed|crit-(high|low)|warn-(high|low)|critical|bad|not[- ]present)\b/i', 'sev' => 'warning', 'what' => '비정상 센서'],
    ['id' => 'sp', 'kind' => 'base', 'group' => '하드웨어 상태', 'cmd' => 'system service-processor show -instance', 'match' => '/^((sys(tem)?\s+)?service-processor|sp)\s+show/', 'point' => 'SP/BMC 상태·IP·펌웨어', 'rule' => 'bad', 'bad' => '/\b(offline|degraded|unknown|failed)\b/i', 'sev' => 'warning', 'what' => 'SP 비정상', 'visual' => 'controller'],
    ['id' => 'broken', 'kind' => 'base', 'group' => '디스크', 'cmd' => 'storage disk show -broken', 'match' => '/disk\s+show\s+-broken/', 'point' => '고장 디스크 (없어야 정상)', 'rule' => 'empty_ok', 'sev' => 'critical', 'what' => '고장 디스크', 'visual' => 'hdd'],
    ['id' => 'spare', 'kind' => 'base', 'group' => '디스크', 'cmd' => 'storage disk show -spare', 'match' => '/(show-spare-disks|disk\s+show\s+-spare|-container-type\s+spare)/', 'point' => '노드별 스페어 디스크 수량·용량', 'rule' => 'custom', 'fn' => 'ck_spare', 'visual' => 'hdd'],
    ['id' => 'unassigned', 'kind' => 'base', 'group' => '디스크', 'cmd' => 'run -node * disk show -n', 'match' => '/(container-type\s+unassigned|disk\s+show\s+-n\b)/', 'point' => '미할당 디스크', 'rule' => 'bad', 'bad' => '/^\s*\S*\d+[a-z]?\.\d+\.\d+\S*\s+.*(not owned|unassigned)|^\s*\d\.\d+\.\d+\s/im', 'sev' => 'warning', 'what' => '미할당 디스크', 'visual' => 'hdd'],
    ['id' => 'aggr', 'kind' => 'base', 'group' => 'Aggregate·볼륨', 'cmd' => 'storage aggregate show', 'match' => '/^(sto(rage)?\s+)?aggr(egate)?\s+show\s*$/', 'point' => 'Aggregate online/RAID 상태·사용률', 'rule' => 'custom', 'fn' => 'ck_aggr', 'visual' => 'logical'],
    ['id' => 'volstate', 'kind' => 'base', 'group' => 'Aggregate·볼륨', 'cmd' => 'volume show', 'match' => '/^vol(ume)?\s+show(\s+-state\s+\S+|\s+-fields\s+\S+)?\s*$/', 'point' => 'online 아닌 볼륨 (없어야 정상)', 'rule' => 'bad', 'bad' => '/\s(offline|restricted|mixed)\s/i', 'sev' => 'warning', 'what' => 'online 아닌 볼륨', 'visual' => 'volume'],
    ['id' => 'df', 'kind' => 'base', 'group' => '사용량', 'cmd' => 'df -h', 'match' => '/^df(\s+-h)?\s*$/', 'point' => '볼륨 사용률 (85% 주의, 95% 위험)', 'rule' => 'custom', 'fn' => 'ck_pct', 'visual' => 'volume'],
    ['id' => 'dfa', 'kind' => 'base', 'group' => '사용량', 'cmd' => 'df -A -h', 'match' => '/^df\s+-a/i', 'point' => 'Aggregate 사용률', 'rule' => 'custom', 'fn' => 'ck_pct'],
    ['id' => 'dfi', 'kind' => 'base', 'group' => '사용량', 'cmd' => 'df -i', 'match' => '/^df\s+-i/', 'point' => 'inode 사용률', 'rule' => 'custom', 'fn' => 'ck_pct'],
    ['id' => 'lif', 'kind' => 'base', 'group' => '네트워크', 'cmd' => 'network interface show', 'match' => '/^net(work)?\s+in(t|terface)?\s+show/', 'point' => 'LIF 상태·Home 여부', 'rule' => 'bad', 'bad' => '/\s(down)\/|\/down\s|\s(down)\s|\sfalse\s*$/i', 'sev' => 'warning', 'what' => 'Down 또는 Home 아닌 LIF', 'visual' => 'cable'],
    ['id' => 'port', 'kind' => 'base', 'group' => '네트워크', 'cmd' => 'network port show', 'match' => '/^net(work)?\s+port\s+show\s*$/', 'point' => '포트 link up/down, 속도, MTU', 'rule' => 'bad', 'bad' => '/\s(down)\s/i', 'sev' => 'warning', 'what' => 'Down 포트', 'visual' => 'cable'],
    ['id' => 'vserver', 'kind' => 'base', 'group' => 'SVM', 'cmd' => 'vserver show', 'match' => '/^vserver\s+show\s*$/', 'point' => 'SVM running 상태', 'rule' => 'bad', 'bad' => '/\s(stopped|stopping|starting)\s/i', 'sev' => 'warning', 'what' => 'running 아닌 SVM'],
    ['id' => 'fcp', 'kind' => 'base', 'group' => 'FC/SAN', 'cmd' => 'network fcp adapter show / lun show', 'match' => '/(fcp\s+adapter\s+show|^lun\s+show)/', 'point' => 'FC 어댑터 상태, LUN online', 'rule' => 'bad', 'bad' => '/\s(offline|down|link-not-connected|nvfail)\s/i', 'sev' => 'warning', 'what' => 'offline 어댑터/LUN', 'visual' => 'sfp'],
    ['id' => 'igroup', 'kind' => 'base', 'group' => 'FC/SAN', 'cmd' => 'igroup show -instance', 'match' => '/^(lun\s+)?igroup\s+show/', 'point' => '클라이언트(Initiator) 로그인 상태', 'rule' => 'bad', 'bad' => '/\(not logged in\)/i', 'sev' => 'warning', 'what' => '로그인 안 된 Initiator'],
    ['id' => 'event', 'kind' => 'base', 'group' => '이벤트 로그', 'cmd' => 'event log show -severity <=ERROR', 'match' => '/^event\s+log\s+show/', 'point' => 'EMERGENCY/ALERT/ERROR 이벤트', 'rule' => 'custom', 'fn' => 'ck_event', 'visual' => 'syslog'],
    ['id' => 'snapshot', 'kind' => 'base', 'group' => '스냅샷', 'cmd' => 'volume snapshot show', 'match' => '/^(vol(ume)?\s+)?snap(shot)?\s+show/', 'point' => '볼륨별 스냅샷 현황', 'rule' => 'info', 'what' => '스냅샷'],
    ['id' => 'snapmirror', 'kind' => 'base', 'group' => '복제', 'cmd' => 'snapmirror show', 'match' => '/^snapmirror\s+show(\s+-healthy\s+\S+|\s+-ins(tance)?)?\s*$/', 'point' => '비정상 SnapMirror 관계, Lag Time', 'rule' => 'bad', 'bad' => '/\sfalse(\s|$)|\b(broken-off|quiesced)\b|^\s*Healthy:\s*false/im', 'sev' => 'critical', 'what' => '비정상 SnapMirror'],
    ['id' => 'smhist', 'kind' => 'base', 'group' => '복제', 'cmd' => 'snapmirror show-history', 'match' => '/^snapmirror\s+show-history/', 'point' => 'SnapMirror 전송 이력', 'rule' => 'bad', 'bad' => '/\b(failure|failed|abort)/i', 'sev' => 'warning', 'what' => '실패한 전송'],
    ['id' => 'asup', 'kind' => 'base', 'group' => 'AutoSupport', 'cmd' => 'system node autosupport show', 'match' => '/autosupport\s+show(\s+-ins(tance)?)?\s*$/', 'point' => 'AutoSupport 활성화·전송 설정', 'rule' => 'bad', 'bad' => '/\b(disable|false)\b/i', 'sev' => 'warning', 'what' => 'AutoSupport 비활성'],
    ['id' => 'asuphist', 'kind' => 'base', 'group' => 'AutoSupport', 'cmd' => 'system node autosupport history show', 'match' => '/autosupport\s+history\s+show/', 'point' => '최근 전송 성공/실패 이력', 'rule' => 'bad', 'bad' => '/(transmission-failed|collection-failed)/i', 'sev' => 'warning', 'what' => '전송/수집 실패'],
    ['id' => 'date', 'kind' => 'base', 'group' => '시간·라이선스', 'cmd' => 'cluster date show / system license show', 'match' => '/(cluster\s+date\s+show|license\s+show)/', 'point' => '노드 간 시간 동기화, 라이선스', 'rule' => 'bad', 'bad' => '/\b(expired|unlicensed|evaluation)\b/i', 'sev' => 'warning', 'what' => '만료/평가판 라이선스'],
    // ───── 상세 (특별점검) ─────
    ['id' => 'diskdetail', 'kind' => 'detail', 'group' => '디스크 상세', 'cmd' => 'storage disk show -instance', 'match' => '/disk\s+(show\s+(-ins(tance)?|-fields|-port)|error\s+show)/', 'point' => '디스크 모델·시리얼·펌웨어, 오류, A/B 경로', 'rule' => 'bad', 'bad' => '/\b(error|failed|single[- ]path)\b/i', 'sev' => 'warning', 'what' => '오류·단일 경로 디스크', 'visual' => 'hdd'],
    ['id' => 'shelf', 'kind' => 'detail', 'group' => '쉘프·경로', 'cmd' => 'storage shelf show -instance', 'match' => '/shelf\s+show/', 'point' => '쉘프 오류, IOM 모듈·포트 상태', 'rule' => 'bad', 'bad' => '/\b(error|fault|failed|degraded|critical)\b(?!s?\s*:\s*-)/i', 'sev' => 'warning', 'what' => '쉘프 오류', 'visual' => 'cable'],
    ['id' => 'sysconfig', 'kind' => 'detail', 'group' => '쉘프·경로', 'cmd' => 'system node run -node * sysconfig -a', 'match' => '/sysconfig\s+-a/', 'point' => '노드셸 하드웨어 상세', 'rule' => 'bad', 'bad' => '/\b(failed|fault|not present|degraded)\b/i', 'sev' => 'warning', 'what' => '하드웨어 이상 표기', 'visual' => 'controller'],
    ['id' => 'diskpath', 'kind' => 'detail', 'group' => '쉘프·경로', 'cmd' => 'system node run -node * storage show disk -p', 'match' => '/storage\s+show\s+disk\s+-p/', 'point' => '노드셸 디스크 경로 상세 (MPHA)', 'rule' => 'custom', 'fn' => 'ck_diskpath', 'visual' => 'cable'],
    ['id' => 'periodic', 'kind' => 'detail', 'group' => '성능', 'cmd' => 'statistics show-periodic', 'match' => '/statistics\s+show-periodic/', 'point' => '클러스터 CPU, ops, throughput', 'rule' => 'custom', 'fn' => 'ck_cpu'],
    ['id' => 'qosperf', 'kind' => 'detail', 'group' => '성능', 'cmd' => 'qos statistics volume performance show', 'match' => '/qos\s+statistics\s+(volume\s+)?performance\s+show/', 'point' => '볼륨별 IOPS·지연 TOP', 'rule' => 'custom', 'fn' => 'ck_latency'],
    ['id' => 'qoslat', 'kind' => 'detail', 'group' => '성능', 'cmd' => 'qos statistics volume latency show', 'match' => '/qos\s+statistics\s+(volume\s+)?latency\s+show/', 'point' => '볼륨별 지연 구간 (네트워크/디스크 등)', 'rule' => 'custom', 'fn' => 'ck_latency'],
    ['id' => 'sysstat', 'kind' => 'detail', 'group' => '성능', 'cmd' => 'system node run -node <노드> sysstat -x 1', 'match' => '/sysstat\s+-x/', 'point' => '노드 단위 실시간 성능', 'rule' => 'custom', 'fn' => 'ck_cpu'],
    ['id' => 'ring', 'kind' => 'detail', 'group' => '클러스터 연결', 'cmd' => 'set d → cluster ring show', 'match' => '/cluster\s+ring\s+show/', 'point' => '클러스터 RDB 링 상태', 'rule' => 'bad', 'bad' => '/\b(offline|unknown|false)\b/i', 'sev' => 'warning', 'what' => '비정상 링'],
    ['id' => 'ping', 'kind' => 'detail', 'group' => '클러스터 연결', 'cmd' => 'cluster ping-cluster -node <노드>', 'match' => '/ping-cluster/', 'point' => '클러스터 인터커넥트 통신', 'rule' => 'bad', 'bad' => '/\b(fail(ed|ure)?|unreachable|not reachable|timed out)\b/i', 'sev' => 'critical', 'what' => '통신 실패 경로', 'visual' => 'cable'],
    ['id' => 'raid', 'kind' => 'detail', 'group' => 'RAID·스크럽', 'cmd' => 'storage aggregate show -fields raidstatus', 'match' => '/raidstatus/', 'point' => 'RAID degraded/reconstruct 여부', 'rule' => 'bad', 'bad' => '/\b(degraded|reconstruct|partial|failed)\b/i', 'sev' => 'critical', 'what' => 'RAID 비정상', 'visual' => 'logical'],
    ['id' => 'scrub', 'kind' => 'detail', 'group' => 'RAID·스크럽', 'cmd' => 'storage aggregate scrub -aggregate <aggr> -action status', 'match' => '/scrub/', 'point' => '스크럽 상태', 'rule' => 'info', 'what' => '스크럽'],
    ['id' => 'porthealth', 'kind' => 'detail', 'group' => '네트워크 오류', 'cmd' => 'network port show -fields link,mtu,health-status', 'match' => '/port\s+show\s+-fields/', 'point' => '포트 헬스 (degraded 여부)', 'rule' => 'bad', 'bad' => '/\bdegraded\b/i', 'sev' => 'warning', 'what' => 'degraded 포트', 'visual' => 'cable'],
    ['id' => 'ifstat', 'kind' => 'detail', 'group' => '네트워크 오류', 'cmd' => 'node run -node <노드> ifstat -a', 'match' => '/ifstat/', 'point' => '포트별 CRC/에러 카운터', 'rule' => 'custom', 'fn' => 'ck_ifstat', 'visual' => 'sfp'],
    ['id' => 'giveback', 'kind' => 'detail', 'group' => 'HA 상세', 'cmd' => 'storage failover show-giveback', 'match' => '/(show-giveback|giveback-state)/', 'point' => 'Giveback 상태 상세', 'rule' => 'bad', 'bad' => '/\b(failed|vetoed|not attempted|in progress)\b/i', 'sev' => 'warning', 'what' => 'Giveback 이상', 'visual' => 'controller'],
    ['id' => 'coredump', 'kind' => 'detail', 'group' => '코어 덤프', 'cmd' => 'system node coredump show', 'match' => '/coredump\s+show/', 'point' => '패닉/코어 덤프 발생 여부', 'rule' => 'empty_ok', 'sev' => 'critical', 'what' => '코어 덤프', 'visual' => 'controller'],
    ['id' => 'asupinvoke', 'kind' => 'detail', 'group' => '전체 덤프', 'cmd' => 'system node autosupport invoke -node * -type all -message "점검"', 'match' => '/autosupport\s+invoke/', 'point' => 'AutoSupport 즉시 전송', 'rule' => 'info', 'what' => '전송 요청'],
];

/* 로그를 프롬프트(클러스터::> 명령어) 단위로 나눕니다 */
function cdot_sections($txt) {
    $out = []; $cur = null;
    foreach (preg_split('/\r\n|\n|\r/', $txt) as $ln) {
        if (preg_match('/^\s*[\w.\-]+::\*?>\s*(.+?)\s*$/', $ln, $m)) {
            if ($cur) $out[] = $cur;
            $cmd = strtolower(preg_replace('/\s+/', ' ', $m[1]));
            $cmd = preg_replace('/^(set\s+(-priv(ilege)?\s+)?\w+\s*;\s*)/', '', $cmd);
            $cur = ['cmd' => $cmd, 'lines' => []]; continue;
        }
        if ($cur && count($cur['lines']) < 20000) $cur['lines'][] = rtrim($ln);
    }
    if ($cur) $out[] = $cur;
    return $out;
}
function ck_rows($lines) {
    // 표의 데이터 행: '----' 줄 다음부터, 빈 줄·'entries were displayed' 전까지. 표가 없으면 내용 있는 줄 전체
    $rows = []; $dash = false; $any = false;
    foreach ($lines as $l) {
        if (preg_match('/^\s*-{3,}(\s+-{2,})*\s*$/', $l)) { if (!$any) $rows = []; $dash = true; $any = true; continue; } // 첫 구분선 위(머리글)는 버림
        if (preg_match('/entries were displayed|^\s*$/', $l)) { if ($dash) $dash = false; continue; }
        if ($dash || !$any) $rows[] = $l;
    }
    if (!$any) $rows = array_values(array_filter($lines, function ($l) { return trim($l) !== '' && !preg_match('/^(Warning|Info|Error):/i', trim($l)); }));
    return $rows;
}
function ck_noentry($lines) { return (bool)preg_grep('/There are no entries matching|no entries|no matching/i', $lines); }

function ck_image($lines) {
    $v = []; foreach (ck_rows($lines) as $l) if (preg_match('/(\d+\.\d+(?:\.\d+)?(?:P\d+)?(?:D\d+)?(?:HF\d+)?)/', $l, $m)) $v[$m[1]] = 1;
    if (preg_match('/NetApp Release\s+(\S+)/', implode("\n", $lines), $m)) $v[rtrim($m[1], ':')] = 1;
    if (!$v) return ['info', '버전 확인'];
    return count($v) > 1 ? ['warning', '노드 간 버전 불일치: ' . implode(', ', array_keys($v))] : ['normal', 'ONTAP ' . array_keys($v)[0]];
}
function ck_alert($lines) {
    if (ck_noentry($lines)) return ['normal', '활성 알림 없음'];
    $n = count(preg_grep('/^\s*Node:\s/', $lines)) ?: count(ck_rows($lines));
    $why = preg_grep('/(Probable Cause|Alert ID|Resource)\s*:/i', $lines);
    return $n ? ['warning', "활성 알림 {$n}건" . ($why ? ': ' . mb_substr(trim(preg_replace('/\s+/', ' ', explode(':', reset($why), 2)[1])), 0, 60) : '')] : ['normal', '활성 알림 없음'];
}
function ck_failover($lines) {
    $t = implode("\n", $lines);
    if (preg_match('/Takeover Possible\s*:\s*false|\s(false)\s+(Waiting|Partner|Connected|In takeover)/i', $t)) return ['critical', 'Takeover 불가 노드 있음'];
    if (preg_match('/(not connected|waiting for|takeover in progress|in takeover|giveback)/i', $t)) return ['warning', '파트너 연결/상태 확인 필요'];
    return preg_match('/(true|Connected to)/i', $t) ? ['normal', 'Takeover 가능, 파트너 연결'] : ['info', '출력 확인'];
}
function ck_spare($lines) {
    $n = 0; foreach (ck_rows($lines) as $l) if (preg_match('/\d+[a-z]?\.\d+\.\d+|\b\d+\.\d+\.\d+\b|^\s*\S+\.\S+\s+\S+\s+(SAS|SSD|BSAS|FSAS|NL-SAS|SATA|MSATA)/i', $l)) $n++;
    $t = implode("\n", $lines);
    if (preg_match('/no entries|spare disks \(empty\)/i', $t) && !$n) return ['warning', 'Spare 디스크 없음'];
    return $n ? ['normal', "Spare {$n}EA"] : ['info', '출력 확인'];
}
function ck_aggr($lines) {
    $bad = []; $hi = 0; $rows = []; $pend = '';
    foreach (ck_rows($lines) as $l) { // 이름이 길어 줄바꿈된 행 합치기, RAID 상태 연속 줄은 버림
        if (preg_match('/^\S+\s*$/', $l)) { $pend = trim($l); continue; }
        if ($pend !== '') { $rows[] = $pend . ' ' . trim($l); $pend = ''; continue; }
        if (preg_match('/^\s/', $l) && !preg_match('/%/', $l)) continue;
        $rows[] = $l;
    }
    foreach ($rows as $l) {
        if (preg_match('/^\s*(aggr0|root)/i', $l) || preg_match('/^\s*\S*_root\b/', $l)) continue; // 루트 Aggregate 는 구조상 사용률이 높아 판정에서 제외
        if (preg_match('/\s(\d+)%\s+(\S+)/', $l, $m)) { $hi = max($hi, (int)$m[1]); if (!in_array(strtolower($m[2]), ['online'], true)) $bad[] = trim(strtok($l, ' ')); }
        if (preg_match('/\b(degraded|reconstruct|failed|offline|restricted)\b/i', $l)) $bad[] = trim(strtok($l, ' '));
    }
    if ($bad) return ['critical', '상태 이상 Aggregate: ' . implode(', ', array_slice(array_unique($bad), 0, 4))];
    return $hi >= 95 ? ['critical', "최대 사용률 {$hi}%"] : ($hi >= 85 ? ['warning', "최대 사용률 {$hi}%"] : ($hi ? ['normal', "최대 사용률 {$hi}%"] : ['info', '출력 확인']));
}
function ck_pct($lines) {
    $hi = 0; $who = '';
    foreach (ck_rows($lines) as $l) if (preg_match('/^(\S+).*\s(\d+)%/', $l, $m) && strpos($m[1], '.snapshot') === false && !preg_match('#/vol/vol0|_root|^aggr0#i', $m[1])) { if ((int)$m[2] > $hi) { $hi = (int)$m[2]; $who = $m[1]; } }
    if (!$hi) return ['info', '출력 확인'];
    return [$hi >= 95 ? 'critical' : ($hi >= 85 ? 'warning' : 'normal'), "최대 {$hi}% ($who)"];
}
function ck_event($lines) {
    $t = implode("\n", $lines); $e = preg_match_all('/\b(EMERGENCY|ALERT)\b/', $t); $r = preg_match_all('/\bERROR\b/', $t);
    if ($e) return ['critical', "EMERGENCY/ALERT {$e}건, ERROR {$r}건"];
    return $r ? ['warning', "ERROR {$r}건"] : ['normal', 'ERROR 이상 이벤트 없음'];
}
function ck_diskpath($lines) {
    $single = 0; foreach (ck_rows($lines) as $l) if (preg_match('/^\s*\S+\s+\S+\s+-\s/', $l) || preg_match('/single/i', $l)) $single++;
    return $single ? ['warning', "단일 경로 의심 {$single}건"] : ['normal', '경로 이중화 확인'];
}
function ck_cpu($lines) {
    $vals = [];
    foreach ($lines as $l) if (preg_match('/^\s*(\d{1,3})%\s/', $l, $m)) $vals[] = (int)$m[1];
    if (!$vals) return ['info', '출력 확인'];
    $avg = round(array_sum($vals) / count($vals)); $mx = max($vals);
    return [$avg >= 80 ? 'critical' : ($avg >= 60 || $mx >= 90 ? 'warning' : 'normal'), "CPU 평균 {$avg}%, 최대 {$mx}%"];
}
function ck_latency($lines) {
    $mx = 0; $who = '';
    foreach (ck_rows($lines) as $l) if (preg_match('/^\s*(\S+).*?\s([\d.]+)(ms|us)\b/', $l, $m)) { $v = $m[3] === 'us' ? $m[2] / 1000 : (float)$m[2]; if ($v > $mx) { $mx = $v; $who = $m[1]; } }
    if (!$mx) return ['info', '출력 확인'];
    return [$mx >= 20 ? 'critical' : ($mx >= 5 ? 'warning' : 'normal'), sprintf('최대 지연 %.2fms (%s)', $mx, $who)];
}
function ck_ifstat($lines) {
    $n = 0; foreach ($lines as $l) if (preg_match('/CRC errors\s*\|?\s*([1-9]\d*)|Bad frames\s*\|?\s*([1-9]\d*)/i', $l)) $n++;
    return $n ? ['warning', "CRC/에러 카운터 증가 {$n}건"] : ['normal', 'CRC 에러 없음'];
}

/* 섹션별 결과: [id => [status, summary, excerpt]] */
function cdot_eval($txt) {
    $res = [];
    foreach (cdot_sections($txt) as $sec) {
        foreach (CDOT_CHECKS as $c) {
            if (!preg_match($c['match'], $sec['cmd'])) continue;
            $L = $sec['lines'];
            if ($c['rule'] === 'custom') [$st, $sum] = ($c['fn'])($L);
            elseif ($c['rule'] === 'info') { $n = count(ck_rows($L)); [$st, $sum] = ['info', ck_noentry($L) ? '항목 없음' : "{$c['what']} {$n}건 수집"]; }
            elseif ($c['rule'] === 'empty_ok') { $rows = ck_noentry($L) ? [] : ck_rows($L); [$st, $sum] = $rows ? [$c['sev'], "{$c['what']} " . count($rows) . '건'] : ['normal', "{$c['what']} 없음"]; }
            else { $hit = array_values(preg_grep($c['bad'], ck_rows($L))); [$st, $sum] = $hit ? [$c['sev'], "{$c['what']} " . count($hit) . '건: ' . mb_substr(trim(preg_replace('/\s+/', ' ', $hit[0])), 0, 70)] : ['normal', '이상 없음']; }
            $ex = array_slice(array_values(array_filter($L, function ($l) { return trim($l) !== ''; })), 0, 14);
            $res[$c['id']] = ['status' => $st, 'summary' => $sum, 'cmd' => $sec['cmd'], 'excerpt' => array_map(function ($l) { return mb_substr($l, 0, 160); }, $ex)];
            break;
        }
    }
    return $res;
}
/* 보고서 종류에 맞는 항목: 정기 = 평시, 특별 = 평시 + 상세 */
function cdot_items_for($type) { return array_values(array_filter(CDOT_CHECKS, function ($c) use ($type) { return $c['kind'] === 'base' || $type === 'special'; })); }
function cdot_findings($checks, $type) {
    $f = []; $skip = ['aggr', 'df', 'dfa', 'event', 'failover', 'broken', 'asup', 'periodic', 'sysstat']; // CPU·디스크 사용률은 사용률 표(util)에서 따로 판정 // 기존 로그 분석과 겹치는 항목은 제외
    foreach (cdot_items_for($type) as $c) {
        $r = $checks[$c['id']] ?? null; if (!$r || !in_array($r['status'], ['warning', 'critical'], true) || in_array($c['id'], $skip, true)) continue;
        $f[] = ['severity' => $r['status'], 'category' => $c['group'], 'title' => $c['point'] . ' – ' . mb_substr($r['summary'], 0, 60), 'detail' => '명령어: ' . $c['cmd'],
            'recommendation' => $c['kind'] === 'detail' ? '상세 점검 결과를 바탕으로 원인 확인 및 조치 계획을 수립하십시오.' : '해당 항목을 확인하고 필요한 조치를 진행하십시오.'];
    }
    return $f;
}
/* 엔지니어가 실행할 명령어 목록 (화면에서 복사용) */
function cdot_command_script($type) {
    $L = []; $g = '';
    foreach (cdot_items_for($type) as $c) { if ($c['group'] !== $g) { $g = $c['group']; $L[] = "# [$g]"; } foreach (array_map('trim', explode(' / ', $c['cmd'])) as $x) $L[] = $x; }
    foreach (util_commands() as $x) $L[] = $x;
    return implode("\n", $L);
}
