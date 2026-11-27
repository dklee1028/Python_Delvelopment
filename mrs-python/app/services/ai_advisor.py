"""Claude 기반 장애·진단 조언 생성.

엔지니어는 이 결과를 그대로 쓰지 않고 반드시 검토·수정한 뒤 '진단 의견'으로 확정합니다.
ANTHROPIC_API_KEY 가 없거나 호출이 실패하면 규칙 기반 초안을 돌려주며, 그 사실을 표시합니다.
"""
from __future__ import annotations

import json
import logging

from .. import runtime_settings
from ..config import settings
from .catalog import REPORT_TYPES, WORK_SUBTYPES

log = logging.getLogger("mrs.ai")

SYSTEM_PROMPT = """당신은 엔터프라이즈 스토리지(NetApp ONTAP 중심) 전문 컨설턴트입니다.
엔지니어가 수집한 로그 분석 결과를 바탕으로 고객 제출용 보고서에 들어갈 '장애 및 진단 의견' 초안을 작성합니다.

작성 원칙:
- 한국어, 간결한 보고서 문체(~입니다/~권장합니다). 과장하지 말고 근거(수치, 이벤트명)를 함께 적습니다.
- 데이터에 없는 사실은 만들지 않습니다. 확인이 필요한 부분은 "현장 확인 필요"로 표시합니다.
- 다음 구성으로 작성합니다:
  [종합 판정] 한 줄 (정상/요주의/위험 + 핵심 사유)
  [주요 진단] 장비별 핵심 문제를 우선순위(위험→요주의) 순으로 번호 목록
  [권고 사항] 즉시 조치 / 단기(1개월) / 중장기 로 나눈 실행 가능한 조치
- 작업계획서인 경우: [작업 리스크]와 [사전 확인 사항], [롤백 고려사항]을 추가합니다.
- 전체 분량은 A4 반 페이지 이내로 유지합니다."""


def _payload(report_type: str, subtype: str, customer: str, equipments: list[dict], extra: str) -> str:
    slim = []
    for e in equipments:
        p = e.get("parsed") or {}
        slim.append({
            "system": e.get("label"),
            "model": e.get("model"), "os_version": e.get("os_version"),
            "analysis": {k: e.get("analysis", {}).get(k) for k in ("status", "score", "findings", "capacity")},
            "aggregates": [
                {k: a.get(k) for k in ("node", "name", "pct", "total_gb", "avail_gb")} for a in p.get("aggregates", [])[:10]
            ],
            "top_volumes": [{k: v.get(k) for k in ("name", "pct", "inode_pct")} for v in p.get("volumes", [])[:10]],
            "failed_disks": p.get("disks", {}).get("failed", [])[:10],
            "ha": p.get("ha"), "ports_down": p.get("ports_down"),
            "events": p.get("events"),
        })
    return json.dumps(
        {
            "report_type": REPORT_TYPES.get(report_type, report_type),
            "work_type": WORK_SUBTYPES.get(subtype, ""),
            "customer": customer,
            "equipment_count": len(equipments),
            "equipments": slim,
            "engineer_notes": extra,
        },
        ensure_ascii=False,
        default=str,
    )


def rule_based(equipments: list[dict], report_type: str = "regular", subtype: str = "") -> str:
    statuses = [e.get("analysis", {}).get("status", "normal") for e in equipments]
    verdict = "위험 (Critical)" if "critical" in statuses else "요주의 (Warning)" if "warning" in statuses else "정상 (Normal)"
    lines = [f"[종합 판정: {verdict}]", "", "[주요 진단]"]
    n = 1
    recs: list[str] = []
    for e in equipments:
        fs = e.get("analysis", {}).get("findings", [])
        if not fs:
            lines.append(f"{n}. {e.get('label')}: 주요 지표가 정상 범위 내에서 안정적으로 운영되고 있습니다.")
            n += 1
            continue
        for x in fs:
            if x["severity"] == "info":
                continue
            lines.append(f"{n}. {e.get('label')}: {x['title']} - {x['detail']}")
            recs.append(x["recommendation"])
            n += 1
    lines += ["", "[권고 사항]"]
    for r in dict.fromkeys(recs):
        lines.append(f"- {r}")
    if not recs:
        lines.append("- 현 상태 유지를 위해 정기적인 모니터링을 권장합니다.")
    if report_type == "workplan":
        lines += ["", "[작업 리스크]", "- 작업 전 AutoSupport 수집 및 구성 백업을 완료하십시오.",
                  "- HA Takeover 가능 여부와 Spare 디스크 상태를 사전에 확인하십시오."]
    return "\n".join(lines)


def generate(report_type: str, subtype: str, customer: str, equipments: list[dict], extra: str = "") -> dict:
    cfg = runtime_settings.current()
    if not cfg.anthropic_key:
        return {"text": rule_based(equipments, report_type, subtype), "model": "rule-based", "fallback": True,
                "note": "Anthropic API 키 미설정으로 규칙 기반 초안을 생성했습니다."}
    try:
        import anthropic

        client = anthropic.Anthropic(api_key=cfg.anthropic_key)
        resp = client.beta.messages.create(
            model=cfg.ai_model or settings.ai_model,
            max_tokens=16000,
            system=SYSTEM_PROMPT,
            output_config={"effort": "medium"},
            betas=["server-side-fallback-2026-07-01"],
            fallbacks="default",
            messages=[{
                "role": "user",
                "content": "다음 분석 데이터를 바탕으로 보고서용 장애 및 진단 의견을 작성해 주세요.\n\n"
                + _payload(report_type, subtype, customer, equipments, extra),
            }],
        )
        if resp.stop_reason == "refusal":
            raise RuntimeError("AI가 응답을 거절했습니다.")
        text = "\n".join(b.text for b in resp.content if getattr(b, "type", "") == "text").strip()
        if not text:
            raise RuntimeError("빈 응답")
        return {"text": text, "model": resp.model, "fallback": False, "note": ""}
    except Exception as e:  # 네트워크/키 오류 등 → 규칙 기반으로 대체
        log.warning("Claude 호출 실패: %s", e)
        return {"text": rule_based(equipments, report_type, subtype), "model": "rule-based", "fallback": True,
                "note": f"Claude 호출 실패로 규칙 기반 초안을 생성했습니다: {e}"}
