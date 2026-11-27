"""보고서 파이프라인: 로그 분석 → 컨텍스트 → PPT/엑셀(+고객양식) 생성."""
from __future__ import annotations

import re
from datetime import datetime
from pathlib import Path

from sqlalchemy.orm import Session

from ..config import settings
from ..models import Customer, Report, ReportFile, User
from . import analyzer, custom_template, log_parser, pptx_report, xlsx_report
from .catalog import (
    INSPECTION_SCOPE,
    REPORT_TYPES,
    SERVICE_CODES,
    VISUAL_ITEMS,
    WORK_SUBTYPES,
)

VARIANT_LABEL = {"single": "1대용", "multi": "2~3대용", "fleet": "4대 이상용"}


def variant_for(n: int) -> str:
    return "single" if n <= 1 else "multi" if n <= 3 else "fleet"


def next_report_no(db: Session) -> str:
    prefix = "MRS-" + datetime.now().strftime("%Y%m%d") + "-"
    last = db.query(Report.report_no).filter(Report.report_no.like(prefix + "%")).order_by(Report.report_no.desc()).first()
    n = int(last[0].rsplit("-", 1)[1]) + 1 if last else 1
    return f"{prefix}{n:03d}"


def _eq_label(e) -> str:
    return f"System {e.seq}" if hasattr(e, "seq") else ""


def run_analysis(db: Session, report: Report) -> dict:
    """연결된 로그를 장비별로 병합·분석하여 report.analysis 에 저장."""
    links = report.equipment_links
    logs = [rl.log for rl in report.log_links]
    out: dict = {}
    per = []
    for link in links:
        eq = link.equipment
        mine = [lg for lg in logs if lg.equipment_id == eq.id or (lg.equipment_id is None and len(links) == 1)]
        mine.sort(key=lambda lg: lg.collected_at)
        parsed = log_parser.merge([lg.parsed or {} for lg in mine])
        s = parsed.get("system", {})
        # 장비 정보가 비어 있으면 로그에서 채움
        for attr, key in (("model", "model"), ("serial", "serial"), ("os_version", "os_version")):
            if not getattr(eq, attr) and s.get(key):
                setattr(eq, attr, s[key])
        eqd = {"model": eq.model, "os_version": eq.os_version}
        a = analyzer.analyze(eqd, parsed)
        out[str(eq.id)] = {"parsed": parsed, "analysis": a, "log_ids": [lg.id for lg in mine]}
        per.append(a)
    report.analysis = out
    report.overall_status = analyzer.overall(per) if per else "normal"
    return out


def auto_checks(parsed: dict, analysis: dict, eq_os: str) -> dict:
    """INSPECTION_MATRIX 항목별 자동 판정: ✔ 정상 / ! 주의 / ✖ 이상 / - 데이터 없음."""
    has = bool(parsed.get("lines", 0) > 1)
    f = analysis.get("findings", [])

    def by(cat):
        sev = [x["severity"] for x in f if x["category"] == cat]
        return "✖" if "critical" in sev else "!" if "warning" in sev else "✔"

    aggs = [a for a in parsed.get("aggregates", []) if not re.match(r"^aggr0|root", a["name"], re.I)]
    mx = max((a["pct"] for a in aggs), default=None)
    ha = parsed.get("ha", {}).get("takeover_possible")
    ev = parsed.get("events", {})
    return {
        "Controller Health": "✖" if ev.get("panic") else ("✔" if has else "-"),
        "PSU Status": "-",
        "Fan/Temperature": "-",
        "Failed Disks": ("✖" if parsed.get("disks", {}).get("failed") else "✔") if has else "-",
        "Aggregate Usage": "-" if mx is None else "✖" if mx >= 95 else "!" if mx >= 85 else "✔",
        "Network Ports Status": ("!" if parsed.get("ports_down") else "✔") if has else "-",
        "Takeover Possible": "-" if ha is None else "✔" if ha else "✖",
        "AutoSupport": "-" if parsed.get("autosupport") is None else "✔" if parsed["autosupport"] else "!",
        "System Logs": by("시스템 로그") if has else "-",
        "Firmware/ONTAP Ver.": by("펌웨어/OS") if (has or eq_os) else "-",
    }


def build_context(db: Session, report: Report) -> dict:
    cust: Customer = report.customer
    contact = next((c for c in cust.contacts if c.is_primary), cust.contacts[0] if cust.contacts else None)
    eng = db.get(User, report.engineer_id) if report.engineer_id else None
    equipments = []
    for i, link in enumerate(report.equipment_links, 1):
        e = link.equipment
        data = (report.analysis or {}).get(str(e.id)) or {"parsed": log_parser.merge([]), "analysis": analyzer.analyze({}, log_parser.merge([]))}
        equipments.append({
            "id": e.id, "seq": i, "label": f"System {i} ({e.model or e.name})", "name": e.name, "vendor": e.vendor or "",
            "model": e.model or "-", "serial": e.serial or "-", "os_version": e.os_version or "-",
            "disk_info": e.disk_info or "-", "ha_type": e.ha_type or "", "location": e.location or "",
            "parsed": data["parsed"], "analysis": data["analysis"],
            "visual": (report.visual_inspection or {}).get(str(e.id), {}),
            "checks": auto_checks(data["parsed"], data["analysis"], e.os_version or ""),
        })
    st = report.overall_status or "normal"
    plan = dict(report.plan or {})
    ctx = {
        "report": {
            "id": report.id, "no": report.report_no, "type": report.report_type,
            "type_label": REPORT_TYPES.get(report.report_type, report.report_type),
            "subtype_label": WORK_SUBTYPES.get(report.work_subtype, ""),
            "title": report.title, "date": report.inspection_date or datetime.now().strftime("%Y-%m-%d"),
            "variant": report.variant, "variant_label": VARIANT_LABEL.get(report.variant, ""),
            "overall_status": st, "overall_label": analyzer.STATUS_LABEL.get(st, ""),
            "service_codes": report.service_codes or [],
        },
        "service_code_list": SERVICE_CODES,
        "company": {"name": settings.company_name, "address": settings.company_address, "tel": settings.company_tel,
                    "email": settings.company_email},
        "customer": {
            "name": cust.name, "address": cust.address, "site": cust.site,
            "contact_name": contact.name if contact else "", "contact_phone": contact.phone if contact else "",
            "contact_email": contact.email if contact else "",
        },
        "engineer": {"name": eng.full_name if eng else "", "title": eng.title if eng else "",
                     "phone": eng.phone if eng else "", "email": eng.email if eng else ""},
        "equipments": equipments,
        "scope": INSPECTION_SCOPE.get(report.report_type, INSPECTION_SCOPE["regular"]),
        "diagnosis": report.diagnosis or "",
        "final_comment": report.final_comment or "(최종 Comment 미입력)",
        "plan": plan,
        "plan_title": plan.get("title") or f"{WORK_SUBTYPES.get(report.work_subtype, '작업')} 작업계획서",
    }
    return ctx


def _safe(s: str) -> str:
    return re.sub(r'[\\/:*?"<>|\s]+', "_", s).strip("_")[:60]


def generate_files(db: Session, report: Report, final: bool) -> list[ReportFile]:
    ctx = build_context(db, report)
    folder = settings.reports_dir / report.report_no
    folder.mkdir(parents=True, exist_ok=True)
    for old in list(report.files):
        Path(old.stored_path).unlink(missing_ok=True)
        report.files.remove(old)
    date = (report.inspection_date or datetime.now().strftime("%Y-%m-%d")).replace("-", "")
    base_type = "workplan" if report.report_type == "workplan" else "inspection"
    kind_label = ctx["plan_title"] if base_type == "workplan" else ctx["report"]["type_label"]
    stem = _safe(f"{date}_{report.customer.name}_{kind_label}" + ("" if base_type == "workplan" else f"_{ctx['report']['variant_label']}"))
    if not final:
        stem += "_검토본"
    made: list[ReportFile] = []

    ppt = folder / f"{stem}.pptx"
    pptx_report.build(ctx, str(ppt))
    xls = folder / f"{stem}.xlsx"
    xlsx_report.build(ctx, str(xls))
    for p, fmt in ((ppt, "pptx"), (xls, "xlsx")):
        made.append(ReportFile(file_format=fmt, kind="standard", filename=p.name, stored_path=str(p), size_bytes=p.stat().st_size))

    if report.template_id:
        from ..models import ReportTemplate

        tpl = db.get(ReportTemplate, report.template_id)
        if tpl and Path(tpl.stored_path).exists():
            out = folder / f"{stem}_고객양식.{tpl.file_format}"
            custom_template.fill(tpl.stored_path, tpl.file_format, ctx, str(out))
            made.append(ReportFile(file_format=tpl.file_format, kind="custom", filename=out.name, stored_path=str(out),
                                   size_bytes=out.stat().st_size))
    for f in made:
        report.files.append(f)
    return made


def missing_for_completion(report: Report) -> list[str]:
    miss = []
    if not (report.diagnosis or "").strip():
        miss.append("장애 및 진단 의견(AI 조언 검토 후 확정)")
    if not report.diagnosis_reviewed:
        miss.append("진단 의견 엔지니어 검토 확인")
    vis = report.visual_inspection or {}
    for link in report.equipment_links:
        sel = vis.get(str(link.equipment_id), {})
        lacking = [label for key, label in VISUAL_ITEMS if sel.get(key) not in ("정상", "비정상", "N/A")]
        if lacking:
            miss.append(f"{link.equipment.name} 육안점검 {len(lacking)}개 항목 미선택")
    if not (report.final_comment or "").strip():
        miss.append("엔지니어 최종 Comment")
    return miss
