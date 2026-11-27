"""파싱된 로그 → 컨설턴트 관점의 진단(Findings)·건강점수·판정."""
from __future__ import annotations

import re

SEV_ORDER = {"critical": 0, "warning": 1, "info": 2}
STATUS_LABEL = {"normal": "정상 (Normal)", "warning": "요주의 (Warning)", "critical": "위험 (Critical)"}


def vol_remark(pct: int) -> str:
    if pct >= 90:
        return "Check Req."
    if pct >= 80:
        return "Monitoring"
    return "Normal"


def _is_root(name: str) -> bool:
    return bool(re.match(r"^aggr0|root", name, re.I))


def _old_ontap(ver: str) -> bool:
    if not ver:
        return False
    if "7-mode" in ver.lower():
        return True
    m = re.match(r"(\d+)\.(\d+)", ver)
    return bool(m) and (int(m.group(1)), int(m.group(2))) < (9, 8)


def analyze(eq: dict, parsed: dict) -> dict:
    """eq: 장비 기본정보, parsed: log_parser.merge 결과."""
    f: list[dict] = []

    def add(sev, cat, title, detail, rec):
        f.append({"severity": sev, "category": cat, "title": title, "detail": detail, "recommendation": rec})

    for a in parsed.get("aggregates", []):
        if _is_root(a["name"]):
            if a["pct"] >= 98:
                add("info", "용량", f"Root Aggregate {a['name']} 사용률 {a['pct']}%",
                    "Root Aggregate는 구조상 사용률이 높게 표시될 수 있습니다.",
                    "Root 볼륨 여유 공간(vol0) 및 로그/코어 파일 누적 여부를 확인하십시오.")
            continue
        if a["pct"] >= 95:
            add("critical", "용량", f"Aggregate {a['name']} 사용률 {a['pct']}%",
                f"여유 공간 {a['avail_gb']:.1f} GB로 쓰기 중단 및 서비스 장애 위험이 매우 높습니다.",
                "불필요 데이터·스냅샷 정리 또는 디스크 증설을 즉시 진행하십시오.")
        elif a["pct"] >= 85:
            add("warning", "용량", f"Aggregate {a['name']} 사용률 {a['pct']}%",
                "권장 기준(80%)을 초과하여 성능 저하 및 용량 부족이 우려됩니다.",
                "용량 추이 모니터링 및 증설 계획 수립을 권장합니다.")
    hot = [v for v in parsed.get("volumes", []) if v["pct"] >= 90]
    if hot:
        add("warning", "용량", f"고사용률 볼륨 {len(hot)}개 (90% 이상)",
            ", ".join(f"{v['name']}({v['pct']}%)" for v in hot[:6]),
            "볼륨 자동 증가(Autosize) 설정 확인 및 스냅샷 보존 정책 검토를 권장합니다.")
    inode = [v for v in parsed.get("volumes", []) if (v.get("inode_pct") or 0) >= 85]
    if inode:
        add("warning", "용량", f"Inode 사용률 높은 볼륨 {len(inode)}개",
            ", ".join(f"{v['name']}({v['inode_pct']}%)" for v in inode[:6]),
            "maxfiles 상향 또는 파일 정리를 검토하십시오.")

    failed = parsed.get("disks", {}).get("failed", [])
    if failed:
        add("critical", "디스크", f"Failed/Broken 디스크 {len(failed)}EA",
            ", ".join(d["disk"] for d in failed[:8]),
            "RAID 재구성 상태 확인 후 즉시 디스크 교체(RMA)를 진행하십시오.")
    spare = parsed.get("disks", {}).get("spare", 0)
    if parsed.get("aggregates") and spare == 0:
        add("warning", "디스크", "Spare 디스크 미확인",
            "로그에서 Spare 디스크가 확인되지 않았습니다.",
            "노드별 최소 1~2EA Spare 디스크 확보를 권장합니다.")

    ha = parsed.get("ha") or {}
    if ha.get("takeover_possible") is False:
        add("critical", "HA", "Takeover 불가 상태",
            "HA 파트너 간 Takeover가 불가하여 컨트롤러 장애 시 서비스 중단 위험이 있습니다.",
            "Interconnect 및 Failover 설정을 점검하고 원인을 해소하십시오.")

    ports = parsed.get("ports_down", [])
    if ports:
        add("warning", "네트워크", f"Down 포트 {len(ports)}개",
            ", ".join(ports[:10]) + " 포트가 Down 상태로 Single Path 운영 가능성이 있습니다.",
            "사용 포트 여부 확인 후 케이블/SFP 점검 및 이중화 구성을 권장합니다.")

    ev = parsed.get("events", {})
    if ev.get("panic"):
        add("critical", "시스템 로그", f"Panic 흔적 {ev['panic']}건",
            "시스템 Panic 관련 로그가 발견되었습니다.", "코어 덤프 수집 및 벤더 기술지원 케이스 오픈을 권장합니다.")
    if ev.get("emergency") or ev.get("alert"):
        add("critical", "시스템 로그", f"EMERGENCY/ALERT 이벤트 {ev.get('emergency', 0) + ev.get('alert', 0)}건",
            ", ".join(e["event"] for e in ev.get("top", []) if e["severity"] in ("emergency", "alert"))[:200],
            "해당 이벤트의 원인 분석 및 조치가 필요합니다.")
    if ev.get("error"):
        add("warning", "시스템 로그", f"ERROR 이벤트 {ev['error']}건",
            ", ".join(e["event"] for e in ev.get("top", []) if e["severity"] == "error")[:200],
            "반복 발생 이벤트를 중심으로 원인을 확인하십시오.")

    ver = (parsed.get("system") or {}).get("os_version") or eq.get("os_version", "")
    if _old_ontap(ver):
        add("warning", "펌웨어/OS", f"OS 버전 {ver}",
            "제조사 지원 종료(EOS) 또는 노후 버전으로 보안·호환성 패치 수급이 제한될 수 있습니다.",
            "권장 버전으로의 업그레이드(또는 장비 교체) 계획 수립을 권장합니다.")
    if parsed.get("autosupport") is False:
        add("warning", "시스템", "AutoSupport 비활성화",
            "장애 발생 시 벤더 사전 감지 및 부품 자동 발송이 불가합니다.", "AutoSupport 활성화를 권장합니다.")

    f.sort(key=lambda x: SEV_ORDER[x["severity"]])
    crit = sum(1 for x in f if x["severity"] == "critical")
    warn = sum(1 for x in f if x["severity"] == "warning")
    score = max(0, 100 - crit * 25 - warn * 8)
    status = "critical" if crit else "warning" if warn else "normal"

    aggs = [a for a in parsed.get("aggregates", []) if not _is_root(a["name"])]
    total = sum(a["total_gb"] for a in aggs)
    used = sum(a["used_gb"] for a in aggs)
    return {
        "findings": f,
        "score": score,
        "status": status,
        "status_label": STATUS_LABEL[status],
        "capacity": {"total_gb": total, "used_gb": used, "pct": round(used / total * 100) if total else 0},
        "counts": {"critical": crit, "warning": warn, "info": sum(1 for x in f if x["severity"] == "info")},
    }


def overall(per_eq: list[dict]) -> str:
    st = [a["status"] for a in per_eq]
    if "critical" in st:
        return "critical"
    if "warning" in st:
        return "warning"
    return "normal"
