<?php
/* 보고서 종류, 작업계획 유형, 육안점검 항목, 기본 작업 절차 등 공통 카탈로그 */

const REPORT_TYPES = ['regular' => '정기점검 보고서', 'special' => '특별점검 보고서', 'workplan' => '작업계획서', 'custom' => '고객 양식 보고서'];
const WORK_SUBTYPES = [
    'os_upgrade' => 'OS 업그레이드', 'controller' => '컨트롤러 교체', 'disk' => '디스크 교체', 'incident' => '장애 처리',
    'expansion' => '디스크/셸프 증설', 'install' => '신규 설치·구성', 'migration' => '데이터 마이그레이션', 'etc' => '기타 작업',
];
const SERVICE_CODES = ['Repair', 'PM', 'Install', 'Deinstall', 'Upgrade', 'Move', 'Reconfig', 'etc'];
/* 서비스 코드별 보고서 구성
   sections: system(용량·진단) / util(사용률) / checks(점검 항목 결과표) / visual(육안 점검) 중 이 코드에서 넣을 것
   fields  : 보고서의 '서비스 작업 내역' 표에 들어갈 입력 칸 [키, 이름, 안내]
   focus   : AI 진단 조언이 중점으로 볼 내용
   여러 코드를 고르면 넣을 섹션은 합쳐지고, 작업 내역 표는 코드마다 하나씩 들어갑니다. 아무 코드도 없으면 PM 과 같습니다. */
const SERVICE_PROFILE = [
    'PM' => ['label' => '정기 예방 점검', 'sections' => ['system', 'util', 'checks', 'visual'], 'fields' => [],
        'focus' => '전반적인 상태, 용량 추세, 잠재 위험의 예방 조치'],
    'Repair' => ['label' => '장애 처리 · 부품 교체', 'sections' => ['system', 'checks', 'visual'], 'fields' => [
        ['symptom', '장애 증상', '언제 어떤 현상이 있었는지'], ['cause', '원인', '로그·점검으로 확인한 원인'], ['action', '조치 내용', '수행한 조치'],
        ['parts', '교체 부품', '부품명 · P/N · 기존 S/N → 신규 S/N'], ['result', '조치 후 상태', '정상 동작 확인 내용']],
        'focus' => '장애 원인과 조치 결과의 타당성, 재발 방지, 교체 부품 이후 상태'],
    'Install' => ['label' => '신규 설치 · 구성', 'sections' => ['system', 'checks', 'visual'], 'fields' => [
        ['scope', '설치 범위', '설치한 장비·셸프·디스크 구성'], ['config', '구성 내용', 'Aggregate·SVM·LIF·프로토콜 등'],
        ['verify', '구성 검증', '케이블링·이중화(Takeover/Giveback)·AutoSupport·SP·NTP 확인'], ['license', '라이선스', '적용한 라이선스'], ['handover', '인수 사항', '고객 인계 내용']],
        'focus' => '설치·구성이 권장 구성(Best Practice)에 맞는지와 이중화 검증 결과'],
    'Deinstall' => ['label' => '철거 · 반출', 'sections' => ['visual'], 'fields' => [
        ['target', '철거 대상', '장비·셸프·부품 목록'], ['data', '데이터 처리', '데이터 이관·삭제(초기화) 방법과 확인'],
        ['shutdown', '종료 절차', '서비스 종료·전원 차단 순서'], ['handover', '반출 · 인수', '반출 일시와 인수자 확인']],
        'focus' => '데이터 삭제·이관 확인과 철거 절차의 누락 여부'],
    'Upgrade' => ['label' => 'OS · 펌웨어 업그레이드', 'sections' => ['system', 'util', 'checks', 'visual'], 'fields' => [
        ['version', '버전 변경', '이전 버전 → 신규 버전 (OS·디스크·셸프 펌웨어)'], ['method', '진행 방식', 'NDU(무중단)·롤링 등'],
        ['precheck', '사전 점검', '업그레이드 전 확인 결과'], ['postcheck', '사후 점검', '업그레이드 후 확인 결과'], ['impact', '서비스 영향', '중단 여부·시간']],
        'focus' => '업그레이드 전후 상태 비교, 호환성, 후속 권고(패치·펌웨어)'],
    'Move' => ['label' => '장비 이전', 'sections' => ['system', 'checks', 'visual'], 'fields' => [
        ['from_to', '이전 위치', '이전 전 → 이전 후 위치'], ['shutdown', '종료 · 기동', '종료·운반·기동 순서와 시간'],
        ['cabling', '재연결', '전원·네트워크·셸프 케이블 재연결 확인'], ['result', '이전 후 상태', '기동 후 정상 확인 내용']],
        'focus' => '이전 후 하드웨어·경로·이중화 정상 여부'],
    'Reconfig' => ['label' => '구성 변경', 'sections' => ['system', 'util', 'checks'], 'fields' => [
        ['change', '변경 내용', '무엇을 바꿨는지'], ['before_after', '변경 전 → 후', '설정 값 비교'], ['reason', '변경 사유', '요청 배경'], ['verify', '변경 후 확인', '확인 결과']],
        'focus' => '변경 내용의 영향 범위와 변경 후 정상 여부'],
    'etc' => ['label' => '기타 작업', 'sections' => ['system'], 'fields' => [['work', '작업 내용', ''], ['result', '결과', '']],
        'focus' => '작업 내용과 결과'],
];
/* 고른 서비스 코드 → 보고서에 넣을 섹션 (합집합). 코드가 없으면 PM */
function service_sections($codes) {
    $codes = array_values(array_intersect((array)$codes, SERVICE_CODES)) ?: ['PM'];
    $s = []; foreach ($codes as $c) foreach (SERVICE_PROFILE[$c]['sections'] as $x) $s[$x] = true;
    return $s;
}
const VISUAL_ITEMS = [
    ['hdd', 'HDD / Spare Disk'], ['psu', 'Power Supply'], ['controller', 'Controller / IO'], ['syslog', 'System Log'],
    ['sfp', 'SFP Status'], ['logical', 'Disk Logical Drive'], ['battery', 'Battery Status'], ['volume', 'Disk Volume'],
    ['cache', 'Cache Memory'], ['partner_fw', 'Controller Partner F/W'], ['fan', 'Fan Status'], ['cable', 'Cable / Port Connection'],
];
const VISUAL_CHOICES = ['정상', '비정상', 'N/A'];
const INSPECTION_MATRIX = [
    ['Hardware', 'Controller Health', 'Status Normal / No Fault'], ['Hardware', 'PSU Status', 'Green LED / Active'],
    ['Hardware', 'Fan/Temperature', 'Speed Normal / Under Threshold'], ['Storage', 'Failed Disks', 'No Failed Disk / Spare Available'],
    ['Storage', 'Aggregate Usage', 'Under 80% (Warning over 90%)'], ['Network', 'Network Ports Status', 'Link Up / Interface Active'],
    ['HA/Cluster', 'Takeover Possible', 'Status True / Connected'], ['System', 'AutoSupport', 'Enabled / Mail Sent'],
    ['System', 'System Logs', 'No Critical Errors / Panic'], ['System', 'Firmware/ONTAP Ver.', 'Version Checked / Compatible'],
];
const INSPECTION_SCOPE = [
    'regular' => [['점검 목적', '스토리지 시스템 안정적 운영을 위한 정기 예방 점검'], ['점검 대상', 'Storage H/W, S/W (ONTAP), 구성 및 용량'],
        ['점검 방법', 'System Log 분석, GUI/CLI 상태 점검, 육안 점검'], ['특이 사항', '점검 중 서비스 중단 없음 (Non-disruptive Inspection)']],
    'special' => [['점검 목적', '장애·이상 징후 발생에 따른 원인 분석 및 긴급 점검'], ['점검 대상', '이상 징후 관련 H/W, S/W 및 연관 구성 요소'],
        ['점검 방법', '이벤트 로그 집중 분석, CLI 상세 진단, 육안 점검'], ['특이 사항', '필요 시 후속 조치(부품 교체/설정 변경) 계획 수립']],
];
const VARIANT_LABEL = ['single' => '1대용', 'multi' => '2~3대용', 'fleet' => '4대 이상용'];
const STATUS_KO = ['draft' => '작성 중', 'review' => '검토 중', 'completed' => '완료', 'sent' => '발송 완료'];
const STATUS_LABEL = ['normal' => '정상', 'warning' => '요주의', 'critical' => '위험', 'info' => '참고'];

function st_row($phase, $act, $detail, $min, $impact) { return ['phase' => phase_name($phase), 'activity' => $act, 'detail' => $detail, 'minutes' => $min, 'impact' => $impact]; }
/* 작업 단계 번호: 단계 이름 앞의 숫자는 지우고, 지금 순서대로 1, 2, 3… 을 다시 매깁니다.
   같은 단계가 이어지면 1-1, 1-2 처럼 하위 번호를 붙입니다. (순서를 위아래로 옮기면 번호도 바뀝니다) */
function phase_name($p) { return trim(preg_replace('/^\s*\d+(\s*[.\-)]\s*\d+)*\s*[.)]?\s*/u', '', (string)$p)); }
function number_steps($steps) {
    $out = []; $pi = 0; $si = 0; $last = null;
    foreach (array_values((array)$steps) as $st) {
        $name = phase_name($st['phase'] ?? '');
        if ($last === null || $name !== $last) { $pi++; $si = 0; $last = $name; }
        $si++;
        $st['phase_name'] = $name; $st['phase'] = $name === '' ? "$pi" : "$pi. $name"; $st['no'] = "$pi-$si"; $out[] = $st;
    }
    return $out;
}
function default_steps() {
    $pre = [st_row('1. 사전 작업', '사전 회의', '작업 진행 프로세스 브리핑 및 고객 승인 확인', 30, '없음'),
        st_row('1. 사전 작업', '장비 상태 점검', 'H/W 외관(Disk, PSU, Fan, LED) 및 시스템 Health 확인, AutoSupport 수집', 30, '없음')];
    $post = [st_row('9. 최종 점검', '서비스 정상 확인', '고객 서비스 서버의 NAS/SAN 접근 및 업무 정상 여부 확인', 30, '없음'),
        st_row('9. 최종 점검', '최종 점검 및 철수', '최종 Health Check, AutoSupport 발송, 작업 결과 공유', 30, '없음')];
    $m = [
        'os_upgrade' => [['2. OS 업그레이드', '이미지 업로드·검증', '대상 ONTAP 이미지 업로드 및 업그레이드 사전 검증(Upgrade Advisor)', 30, '없음'],
            ['2. OS 업그레이드', 'NDU 시작', '클러스터 무중단 업그레이드(NDU) 프로세스 시작', 10, '없음'],
            ['2. OS 업그레이드', 'Node-01 Takeover/Giveback', 'Node-01 재부팅 시 파트너 노드로 Takeover, 신규 OS 부팅 후 Giveback', 30, '1~5초 순단'],
            ['2. OS 업그레이드', 'Node-02 Takeover/Giveback', 'Node-02 재부팅 시 파트너 노드로 Takeover, 신규 OS 부팅 후 Giveback', 30, '1~5초 순단'],
            ['2. OS 업그레이드', '업그레이드 확인', '전체 노드 버전 확인 및 클러스터 Health 확인', 20, '없음']],
        'controller' => [['2. 컨트롤러 교체', '대상 노드 Takeover', '교체 대상 컨트롤러 Takeover 및 Halt', 15, '1~5초 순단'],
            ['2. 컨트롤러 교체', '컨트롤러 모듈 교체', '케이블 라벨링 후 모듈 탈거, 부품(DIMM, Boot media 등) 이관, 신규 모듈 장착', 60, 'HA 단일 운영'],
            ['2. 컨트롤러 교체', '부팅 및 설정 복원', 'System ID 재할당, 디스크 소유권 확인, 펌웨어/OS 버전 일치 확인', 40, 'HA 단일 운영'],
            ['2. 컨트롤러 교체', 'Giveback', 'Giveback 수행 및 HA 상태 정상화 확인', 15, '1~5초 순단'],
            ['2. 컨트롤러 교체', '부품 반납 처리', 'RMA 반납 및 AutoSupport 케이스 업데이트', 10, '없음']],
        'disk' => [['2. 디스크 교체', '장애 디스크 확인', 'Fail 디스크 위치(Shelf/Bay) 및 Spare 재구성(Reconstruction) 완료 확인', 10, '없음'],
            ['2. 디스크 교체', '디스크 교체', 'LED 점등으로 위치 확인 후 장애 디스크 탈거 및 신규 디스크 장착', 15, '없음 (Hot-swap)'],
            ['2. 디스크 교체', '인식 및 Spare 지정', '신규 디스크 인식, 소유권 할당, 펌웨어 업데이트 및 Spare 등록 확인', 20, '없음']],
        'incident' => [['2. 장애 처리', '장애 현상 확인', '증상 재현, 이벤트 로그/코어 덤프 수집 및 영향 범위 파악', 30, '확인 필요'],
            ['2. 장애 처리', '원인 분석', '로그 분석 및 벤더 기술지원(케이스) 협업으로 근본 원인 도출', 60, '확인 필요'],
            ['2. 장애 처리', '조치 수행', '부품 교체 / 설정 변경 / 패치 적용 등 조치 수행', 60, '확인 필요'],
            ['2. 장애 처리', '재발 방지 대책', '모니터링 강화 및 재발 방지 권고사항 정리', 20, '없음']],
        'expansion' => [['2. 증설', '셸프/디스크 장착', '디스크(셸프) 장착 및 SAS/전원 케이블 연결', 40, '없음'],
            ['2. 증설', '인식 및 소유권 할당', '디스크 인식, 펌웨어 업데이트, 소유권 할당', 30, '없음'],
            ['2. 증설', 'Aggregate 확장', 'RAID 그룹 구성 검토 후 Aggregate 확장 및 용량 확인', 30, '없음']],
        'install' => [['2. 설치', '랙 실장 및 케이블링', '컨트롤러/셸프 랙 실장, 전원·네트워크·SAS 케이블링', 90, '없음'],
            ['2. 설치', '초기 설정', '클러스터 셋업, 라이선스, 시간/DNS, AutoSupport 설정', 60, '없음'],
            ['2. 설치', '스토리지 구성', 'Aggregate/SVM/Volume/LIF 구성 및 프로토콜(NFS/CIFS/iSCSI) 설정', 120, '없음']],
        'migration' => [['2. 마이그레이션', '복제 관계 구성', '소스/타깃 간 SnapMirror(또는 복제 도구) 관계 생성 및 초기 전송', 60, '없음'],
            ['2. 마이그레이션', '증분 동기화', '증분 업데이트 및 데이터 정합성 검증', 60, '없음'],
            ['2. 마이그레이션', '컷오버', '서비스 중지 → 최종 동기화 → 관계 해제 → 타깃 서비스 전환', 60, '서비스 중단']],
        'etc' => [['2. 작업', '작업 수행', '작업 내용 입력', 60, '확인 필요']],
    ];
    $out = [];
    foreach ($m as $k => $rows) $out[$k] = array_merge($pre, array_map(function ($r) { return st_row(...$r); }, $rows), $post);
    return $out;
}
const DEFAULT_ROLLBACK = [
    'os_upgrade' => '업그레이드 실패 시 이전 이미지로 부팅(Revert) 및 Giveback, 필요 시 벤더 기술지원 연계',
    'controller' => '신규 모듈 이상 시 기존 모듈 재장착 후 Giveback, RMA 재요청',
    'disk' => '신규 디스크 인식 불가 시 재장착/슬롯 확인 후 예비 디스크로 대체',
    'incident' => '조치 후 증상 지속 시 변경 사항 원복 후 벤더 에스컬레이션',
    'expansion' => '인식 오류 시 케이블 재연결 및 셸프 펌웨어 확인, Aggregate 확장 전 단계에서 중단',
    'install' => '구성 오류 시 초기화 후 재구성',
    'migration' => '컷오버 실패 시 소스 볼륨으로 서비스 원복(역방향 전환)',
    'etc' => '작업 실패 시 작업 전 상태로 원복',
];
