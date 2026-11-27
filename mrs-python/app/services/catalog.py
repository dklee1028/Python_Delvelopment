"""보고서 종류, 작업계획 유형, 육안점검 항목, 기본 작업 절차 등 공통 카탈로그."""
from __future__ import annotations

REPORT_TYPES = {
    "regular": "정기점검 보고서",
    "special": "특별점검 보고서",
    "workplan": "작업계획서",
    "custom": "고객 양식 보고서",
}

WORK_SUBTYPES = {
    "os_upgrade": "OS 업그레이드",
    "controller": "컨트롤러 교체",
    "disk": "디스크 교체",
    "incident": "장애 처리",
    "expansion": "디스크/셸프 증설",
    "install": "신규 설치·구성",
    "migration": "데이터 마이그레이션",
    "etc": "기타 작업",
}

SERVICE_CODES = ["Repair", "PM", "Install", "Deinstall", "Upgrade", "Move", "Reconfig", "etc"]

# 1대용 샘플 보고서의 '주요 점검 항목' 기준
VISUAL_ITEMS = [
    ("hdd", "HDD / Spare Disk"),
    ("psu", "Power Supply"),
    ("controller", "Controller / IO"),
    ("syslog", "System Log"),
    ("sfp", "SFP Status"),
    ("logical", "Disk Logical Drive"),
    ("battery", "Battery Status"),
    ("volume", "Disk Volume"),
    ("cache", "Cache Memory"),
    ("partner_fw", "Controller Partner F/W"),
    ("fan", "Fan Status"),
    ("cable", "Cable / Port Connection"),
]
VISUAL_CHOICES = ["정상", "비정상", "N/A"]

# 다수 장비(5대용) 샘플의 카테고리형 점검 매트릭스
INSPECTION_MATRIX = [
    ("Hardware", "Controller Health", "Status Normal / No Fault"),
    ("Hardware", "PSU Status", "Green LED / Active"),
    ("Hardware", "Fan/Temperature", "Speed Normal / Under Threshold"),
    ("Storage", "Failed Disks", "No Failed Disk / Spare Available"),
    ("Storage", "Aggregate Usage", "Under 80% (Warning over 90%)"),
    ("Network", "Network Ports Status", "Link Up / Interface Active"),
    ("HA/Cluster", "Takeover Possible", "Status True / Connected"),
    ("System", "AutoSupport", "Enabled / Mail Sent"),
    ("System", "System Logs", "No Critical Errors / Panic"),
    ("System", "Firmware/ONTAP Ver.", "Version Checked / Compatible"),
]

INSPECTION_SCOPE = {
    "regular": [
        ("점검 목적", "스토리지 시스템 안정적 운영을 위한 정기 예방 점검"),
        ("점검 대상", "Storage H/W, S/W (ONTAP), 구성 및 용량"),
        ("점검 방법", "System Log 분석, GUI/CLI 상태 점검, 육안 점검"),
        ("특이 사항", "점검 중 서비스 중단 없음 (Non-disruptive Inspection)"),
    ],
    "special": [
        ("점검 목적", "장애·이상 징후 발생에 따른 원인 분석 및 긴급 점검"),
        ("점검 대상", "이상 징후 관련 H/W, S/W 및 연관 구성 요소"),
        ("점검 방법", "이벤트 로그 집중 분석, CLI 상세 진단, 육안 점검"),
        ("특이 사항", "필요 시 후속 조치(부품 교체/설정 변경) 계획 수립"),
    ],
}

# 작업계획서 기본 절차 (샘플 작업계획서들을 기반으로 한 템플릿, UI에서 편집 가능)
_PRE = [
    {"phase": "1. 사전 작업", "activity": "사전 회의", "detail": "작업 진행 프로세스 브리핑 및 고객 승인 확인", "minutes": 30, "impact": "없음"},
    {"phase": "1. 사전 작업", "activity": "장비 상태 점검", "detail": "H/W 외관(Disk, PSU, Fan, LED) 및 시스템 Health 확인, AutoSupport 수집", "minutes": 30, "impact": "없음"},
]
_POST = [
    {"phase": "9. 최종 점검", "activity": "서비스 정상 확인", "detail": "고객 서비스 서버의 NAS/SAN 접근 및 업무 정상 여부 확인", "minutes": 30, "impact": "없음"},
    {"phase": "9. 최종 점검", "activity": "최종 점검 및 철수", "detail": "최종 Health Check, AutoSupport 발송, 작업 결과 공유", "minutes": 30, "impact": "없음"},
]
DEFAULT_STEPS = {
    "os_upgrade": _PRE + [
        {"phase": "2. OS 업그레이드", "activity": "이미지 업로드·검증", "detail": "대상 ONTAP 이미지 업로드 및 업그레이드 사전 검증(Upgrade Advisor)", "minutes": 30, "impact": "없음"},
        {"phase": "2. OS 업그레이드", "activity": "NDU 시작", "detail": "클러스터 무중단 업그레이드(NDU) 프로세스 시작", "minutes": 10, "impact": "없음"},
        {"phase": "2. OS 업그레이드", "activity": "Node-01 Takeover/Giveback", "detail": "Node-01 재부팅 시 파트너 노드로 Takeover, 신규 OS 부팅 후 Giveback", "minutes": 30, "impact": "1~5초 순단"},
        {"phase": "2. OS 업그레이드", "activity": "Node-02 Takeover/Giveback", "detail": "Node-02 재부팅 시 파트너 노드로 Takeover, 신규 OS 부팅 후 Giveback", "minutes": 30, "impact": "1~5초 순단"},
        {"phase": "2. OS 업그레이드", "activity": "업그레이드 확인", "detail": "전체 노드 버전 확인 및 클러스터 Health 확인", "minutes": 20, "impact": "없음"},
    ] + _POST,
    "controller": _PRE + [
        {"phase": "2. 컨트롤러 교체", "activity": "대상 노드 Takeover", "detail": "교체 대상 컨트롤러 Takeover 및 Halt", "minutes": 15, "impact": "1~5초 순단"},
        {"phase": "2. 컨트롤러 교체", "activity": "컨트롤러 모듈 교체", "detail": "케이블 라벨링 후 모듈 탈거, 부품(DIMM, Boot media 등) 이관, 신규 모듈 장착", "minutes": 60, "impact": "HA 단일 운영"},
        {"phase": "2. 컨트롤러 교체", "activity": "부팅 및 설정 복원", "detail": "System ID 재할당, 디스크 소유권 확인, 펌웨어/OS 버전 일치 확인", "minutes": 40, "impact": "HA 단일 운영"},
        {"phase": "2. 컨트롤러 교체", "activity": "Giveback", "detail": "Giveback 수행 및 HA 상태 정상화 확인", "minutes": 15, "impact": "1~5초 순단"},
        {"phase": "2. 컨트롤러 교체", "activity": "부품 반납 처리", "detail": "RMA 반납 및 AutoSupport 케이스 업데이트", "minutes": 10, "impact": "없음"},
    ] + _POST,
    "disk": _PRE + [
        {"phase": "2. 디스크 교체", "activity": "장애 디스크 확인", "detail": "Fail 디스크 위치(Shelf/Bay) 및 Spare 재구성(Reconstruction) 완료 확인", "minutes": 10, "impact": "없음"},
        {"phase": "2. 디스크 교체", "activity": "디스크 교체", "detail": "LED 점등으로 위치 확인 후 장애 디스크 탈거 및 신규 디스크 장착", "minutes": 15, "impact": "없음 (Hot-swap)"},
        {"phase": "2. 디스크 교체", "activity": "인식 및 Spare 지정", "detail": "신규 디스크 인식, 소유권 할당, 펌웨어 업데이트 및 Spare 등록 확인", "minutes": 20, "impact": "없음"},
    ] + _POST,
    "incident": _PRE + [
        {"phase": "2. 장애 처리", "activity": "장애 현상 확인", "detail": "증상 재현, 이벤트 로그/코어 덤프 수집 및 영향 범위 파악", "minutes": 30, "impact": "확인 필요"},
        {"phase": "2. 장애 처리", "activity": "원인 분석", "detail": "로그 분석 및 벤더 기술지원(케이스) 협업으로 근본 원인 도출", "minutes": 60, "impact": "확인 필요"},
        {"phase": "2. 장애 처리", "activity": "조치 수행", "detail": "부품 교체 / 설정 변경 / 패치 적용 등 조치 수행", "minutes": 60, "impact": "확인 필요"},
        {"phase": "2. 장애 처리", "activity": "재발 방지 대책", "detail": "모니터링 강화 및 재발 방지 권고사항 정리", "minutes": 20, "impact": "없음"},
    ] + _POST,
    "expansion": _PRE + [
        {"phase": "2. 증설", "activity": "셸프/디스크 장착", "detail": "디스크(셸프) 장착 및 SAS/전원 케이블 연결", "minutes": 40, "impact": "없음"},
        {"phase": "2. 증설", "activity": "인식 및 소유권 할당", "detail": "디스크 인식, 펌웨어 업데이트, 소유권 할당", "minutes": 30, "impact": "없음"},
        {"phase": "2. 증설", "activity": "Aggregate 확장", "detail": "RAID 그룹 구성 검토 후 Aggregate 확장 및 용량 확인", "minutes": 30, "impact": "없음"},
    ] + _POST,
    "install": _PRE + [
        {"phase": "2. 설치", "activity": "랙 실장 및 케이블링", "detail": "컨트롤러/셸프 랙 실장, 전원·네트워크·SAS 케이블링", "minutes": 90, "impact": "없음"},
        {"phase": "2. 설치", "activity": "초기 설정", "detail": "클러스터 셋업, 라이선스, 시간/DNS, AutoSupport 설정", "minutes": 60, "impact": "없음"},
        {"phase": "2. 설치", "activity": "스토리지 구성", "detail": "Aggregate/SVM/Volume/LIF 구성 및 프로토콜(NFS/CIFS/iSCSI) 설정", "minutes": 120, "impact": "없음"},
    ] + _POST,
    "migration": _PRE + [
        {"phase": "2. 마이그레이션", "activity": "복제 관계 구성", "detail": "소스/타깃 간 SnapMirror(또는 복제 도구) 관계 생성 및 초기 전송", "minutes": 60, "impact": "없음"},
        {"phase": "2. 마이그레이션", "activity": "증분 동기화", "detail": "증분 업데이트 및 데이터 정합성 검증", "minutes": 60, "impact": "없음"},
        {"phase": "2. 마이그레이션", "activity": "컷오버", "detail": "서비스 중지 → 최종 동기화 → 관계 해제 → 타깃 서비스 전환", "minutes": 60, "impact": "서비스 중단"},
    ] + _POST,
    "etc": _PRE + [
        {"phase": "2. 작업", "activity": "작업 수행", "detail": "작업 내용 입력", "minutes": 60, "impact": "확인 필요"},
    ] + _POST,
}

DEFAULT_ROLLBACK = {
    "os_upgrade": "업그레이드 실패 시 이전 이미지로 부팅(Revert) 및 Giveback, 필요 시 벤더 기술지원 연계",
    "controller": "신규 모듈 이상 시 기존 모듈 재장착 후 Giveback, RMA 재요청",
    "disk": "신규 디스크 인식 불가 시 재장착/슬롯 확인 후 예비 디스크로 대체",
    "incident": "조치 후 증상 지속 시 변경 사항 원복 후 벤더 에스컬레이션",
    "expansion": "인식 오류 시 케이블 재연결 및 셸프 펌웨어 확인, Aggregate 확장 전 단계에서 중단",
    "install": "구성 오류 시 초기화 후 재구성",
    "migration": "컷오버 실패 시 소스 볼륨으로 서비스 원복(역방향 전환)",
    "etc": "작업 실패 시 작업 전 상태로 원복",
}
