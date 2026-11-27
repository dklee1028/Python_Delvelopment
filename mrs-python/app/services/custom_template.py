"""고객 별도 양식(pptx/xlsx)에 {{치환자}} 값을 채워 넣습니다."""
from __future__ import annotations

import re

from openpyxl import load_workbook
from pptx import Presentation

from .catalog import VISUAL_ITEMS

PH = re.compile(r"\{\{\s*([\w.]+)\s*\}\}")

PLACEHOLDER_HELP = {
    "customer_name": "고객명", "customer_address": "고객 주소", "customer_site": "설치 위치",
    "contact_name": "고객 담당자", "contact_phone": "담당자 연락처", "contact_email": "담당자 이메일",
    "report_no": "보고서 번호", "report_title": "보고서 제목", "report_type": "보고서 종류",
    "inspection_date": "점검/작업일", "overall_status": "종합 판정", "health_score_avg": "평균 건강 점수",
    "diagnosis": "장애 및 진단 의견(엔지니어 확정본)", "final_comment": "엔지니어 최종 Comment",
    "engineer_name": "엔지니어", "engineer_title": "직급", "engineer_phone": "엔지니어 연락처",
    "company_name": "서비스 회사명", "equipment_count": "장비 수", "equipment_list": "장비 목록(줄바꿈)",
    "plan_schedule": "작업 일정", "plan_purpose": "작업 목적", "plan_rollback": "롤백 계획",
    "eqN_model / eqN_serial / eqN_os / eqN_status / eqN_score / eqN_name": "N번째 장비 정보 (N=1~20)",
    "eqN_vi_<항목키>": "N번째 장비 육안점검 결과 (" + ", ".join(k for k, _ in VISUAL_ITEMS) + ")",
}


def values(ctx: dict) -> dict[str, str]:
    c, r, eng, plan = ctx["customer"], ctx["report"], ctx["engineer"], ctx.get("plan", {})
    eqs = ctx["equipments"]
    v = {
        "customer_name": c["name"], "customer_address": c.get("address", ""), "customer_site": c.get("site", ""),
        "contact_name": c.get("contact_name", ""), "contact_phone": c.get("contact_phone", ""),
        "contact_email": c.get("contact_email", ""),
        "report_no": r["no"], "report_title": r["title"], "report_type": r["type_label"],
        "inspection_date": r["date"], "overall_status": r["overall_label"],
        "health_score_avg": str(round(sum(e["analysis"]["score"] for e in eqs) / len(eqs))) if eqs else "",
        "diagnosis": ctx["diagnosis"], "final_comment": ctx["final_comment"],
        "engineer_name": eng.get("name", ""), "engineer_title": eng.get("title", ""),
        "engineer_phone": eng.get("phone", ""), "company_name": ctx["company"]["name"],
        "equipment_count": str(len(eqs)),
        "equipment_list": "\n".join(f"{e['seq']}. {e['model']} ({e['serial']}) - {e['analysis']['status_label']}" for e in eqs),
        "plan_schedule": plan.get("schedule", ""), "plan_purpose": plan.get("purpose", ""),
        "plan_rollback": plan.get("rollback", ""),
    }
    for e in eqs:
        p = f"eq{e['seq']}_"
        v.update({p + "name": e["name"], p + "model": e["model"], p + "serial": e["serial"], p + "os": e["os_version"],
                  p + "status": e["analysis"]["status_label"], p + "score": str(e["analysis"]["score"])})
        for k, _ in VISUAL_ITEMS:
            v[p + "vi_" + k] = e["visual"].get(k, "")
    return v


def _sub(s: str, vals: dict) -> str:
    return PH.sub(lambda m: vals.get(m.group(1), ""), s)


def scan(path: str, fmt: str) -> list[str]:
    found: list[str] = []
    if fmt == "pptx":
        for slide in Presentation(path).slides:
            for shp in slide.shapes:
                for tf in _text_frames(shp):
                    found += PH.findall(tf.text)
    else:
        for ws in load_workbook(path).worksheets:
            for row in ws.iter_rows():
                for c in row:
                    if isinstance(c.value, str):
                        found += PH.findall(c.value)
    return list(dict.fromkeys(found))


def _text_frames(shp):
    if shp.has_text_frame:
        yield shp.text_frame
    if getattr(shp, "has_table", False) and shp.has_table:
        for row in shp.table.rows:
            for cell in row.cells:
                yield cell.text_frame
    if shp.shape_type == 6:  # group
        for s in shp.shapes:
            yield from _text_frames(s)


def fill(template_path: str, fmt: str, ctx: dict, out_path: str) -> None:
    vals = values(ctx)
    if fmt == "pptx":
        prs = Presentation(template_path)
        for slide in prs.slides:
            for shp in slide.shapes:
                for tf in _text_frames(shp):
                    for p in tf.paragraphs:
                        full = "".join(r.text for r in p.runs)
                        if "{{" not in full or not p.runs:
                            continue
                        p.runs[0].text = _sub(full, vals)
                        for r in p.runs[1:]:
                            r.text = ""
        prs.save(out_path)
    else:
        wb = load_workbook(template_path)
        for ws in wb.worksheets:
            for row in ws.iter_rows():
                for c in row:
                    if isinstance(c.value, str) and "{{" in c.value:
                        c.value = _sub(c.value, vals)
        wb.save(out_path)
