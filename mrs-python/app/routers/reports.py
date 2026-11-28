"""보고서 생성 워크플로 및 관리.

draft(작성) → AI 조언 → 엔지니어 검토 확정 → generate(검토본 PPT/엑셀) → review
→ 육안점검 전 항목 선택 + 최종 Comment → finalize(최종본) → completed → 메일 발송 → sent
"""
from __future__ import annotations

import html
import shutil
from datetime import datetime
from pathlib import Path

from fastapi import APIRouter, Depends, HTTPException
from fastapi.responses import FileResponse
from pydantic import BaseModel
from sqlalchemy import func, or_
from sqlalchemy.orm import Session

from .. import runtime_settings
from ..auth import audit, require
from ..config import settings
from ..db import get_db
from ..models import (
    Customer, Equipment, LogFile, MailLog, Report, ReportEquipment, ReportFile, ReportLog, ReportTemplate, User,
)
from ..services import ai_advisor, mailer, report_service
from ..services.analyzer import STATUS_LABEL
from ..services.catalog import (
    DEFAULT_ROLLBACK, DEFAULT_STEPS, REPORT_TYPES, SERVICE_CODES, VISUAL_CHOICES, VISUAL_ITEMS, WORK_SUBTYPES,
)

router = APIRouter(prefix="/api", tags=["reports"])
STATUS_KO = {"draft": "작성 중", "review": "검토 중", "completed": "완료", "sent": "발송 완료"}


@router.get("/catalog")
def catalog(_: User = Depends(require("report.view"))):
    cfg = runtime_settings.current()
    return {
        "report_types": REPORT_TYPES, "work_subtypes": WORK_SUBTYPES, "service_codes": SERVICE_CODES,
        "visual_items": VISUAL_ITEMS, "visual_choices": VISUAL_CHOICES, "default_steps": DEFAULT_STEPS,
        "default_rollback": DEFAULT_ROLLBACK, "status": STATUS_KO, "variants": report_service.VARIANT_LABEL,
        "ai_enabled": bool(cfg.anthropic_key), "mail_enabled": bool(cfg.smtp_host),
    }


def report_brief(r: Report) -> dict:
    return {
        "id": r.id, "report_no": r.report_no, "title": r.title, "customer_id": r.customer_id,
        "customer_name": r.customer.name if r.customer else "", "report_type": r.report_type,
        "report_type_label": REPORT_TYPES.get(r.report_type, r.report_type),
        "work_subtype": r.work_subtype, "work_subtype_label": WORK_SUBTYPES.get(r.work_subtype, ""),
        "variant": r.variant, "variant_label": report_service.VARIANT_LABEL.get(r.variant, ""),
        "status": r.status, "status_label": STATUS_KO.get(r.status, r.status),
        "overall_status": r.overall_status, "overall_label": STATUS_LABEL.get(r.overall_status or "normal", ""),
        "inspection_date": r.inspection_date, "equipment_count": len(r.equipment_links),
        "updated_at": r.updated_at.strftime("%Y-%m-%d %H:%M"),
        "completed_at": r.completed_at.strftime("%Y-%m-%d %H:%M") if r.completed_at else None,
        "sent_at": r.sent_at.strftime("%Y-%m-%d %H:%M") if r.sent_at else None,
    }


def report_detail(db: Session, r: Report) -> dict:
    d = report_brief(r)
    eqs = []
    for i, link in enumerate(r.equipment_links, 1):
        e = link.equipment
        an = (r.analysis or {}).get(str(e.id), {})
        p = an.get("parsed", {})
        eqs.append({
            "id": e.id, "seq": i, "name": e.name, "model": e.model, "serial": e.serial, "os_version": e.os_version,
            "analysis": an.get("analysis"), "log_ids": an.get("log_ids", []),
            "aggregates": p.get("aggregates", [])[:12], "volumes": p.get("volumes", [])[:12],
            "events": p.get("events"), "ha": p.get("ha"), "ports_down": p.get("ports_down"),
            "failed_disks": p.get("disks", {}).get("failed", []), "spare": p.get("disks", {}).get("spare", 0),
        })
    eng = db.get(User, r.engineer_id) if r.engineer_id else None
    d.update({
        "template_id": r.template_id, "service_codes": r.service_codes or [], "plan": r.plan or {},
        "equipment_ids": [l.equipment_id for l in r.equipment_links], "log_ids": [l.log_id for l in r.log_links],
        "equipments": eqs, "ai_advice": r.ai_advice, "ai_model": r.ai_model,
        "ai_generated_at": r.ai_generated_at.strftime("%Y-%m-%d %H:%M") if r.ai_generated_at else None,
        "diagnosis": r.diagnosis, "diagnosis_reviewed": r.diagnosis_reviewed,
        "visual_inspection": r.visual_inspection or {}, "final_comment": r.final_comment,
        "engineer_id": r.engineer_id, "engineer_name": eng.full_name if eng else "",
        "files": [{"id": f.id, "format": f.file_format, "kind": f.kind, "filename": f.filename, "size": f.size_bytes,
                   "created_at": f.created_at.strftime("%Y-%m-%d %H:%M")} for f in r.files],
        "mails": [{"at": m.created_at.strftime("%Y-%m-%d %H:%M"), "to": m.recipients, "cc": m.cc, "status": m.status,
                   "error": m.error, "subject": m.subject}
                  for m in db.query(MailLog).filter(MailLog.report_id == r.id).order_by(MailLog.id.desc())],
        "missing": report_service.missing_for_completion(r),
        "recipients": [{"name": c.name, "email": c.email, "default": c.receive_reports}
                       for c in r.customer.contacts if c.email],
    })
    return d


def _get(db: Session, rid: int) -> Report:
    r = db.get(Report, rid)
    if not r:
        raise HTTPException(404, "보고서 없음")
    return r


@router.get("/dashboard")
def dashboard(db: Session = Depends(get_db), _: User = Depends(require("report.view"))):
    by_status = dict(db.query(Report.status, func.count(Report.id)).group_by(Report.status).all())
    by_type = dict(db.query(Report.report_type, func.count(Report.id)).group_by(Report.report_type).all())
    month = datetime.now().strftime("%Y-%m")
    recent = db.query(Report).order_by(Report.updated_at.desc()).limit(8).all()
    attention = (db.query(Report).filter(Report.overall_status == "critical").order_by(Report.updated_at.desc()).limit(5).all())
    return {
        "customers": db.query(func.count(Customer.id)).scalar(),
        "equipment": db.query(func.count(Equipment.id)).filter(Equipment.is_active.is_(True)).scalar(),
        "logs": db.query(func.count(LogFile.id)).scalar(),
        "reports": sum(by_status.values()),
        "this_month": db.query(func.count(Report.id)).filter(Report.inspection_date.like(month + "%")).scalar(),
        "by_status": by_status, "by_type": by_type,
        "recent": [report_brief(r) for r in recent], "attention": [report_brief(r) for r in attention],
    }


@router.get("/reports")
def list_reports(q: str = "", status: str = "", report_type: str = "", customer_id: int | None = None,
                 db: Session = Depends(get_db), _: User = Depends(require("report.view"))):
    qs = db.query(Report).join(Customer)
    if q:
        qs = qs.filter(or_(Report.title.like(f"%{q}%"), Report.report_no.like(f"%{q}%"), Customer.name.like(f"%{q}%")))
    if status:
        qs = qs.filter(Report.status == status)
    if report_type:
        qs = qs.filter(Report.report_type == report_type)
    if customer_id:
        qs = qs.filter(Report.customer_id == customer_id)
    return [report_brief(r) for r in qs.order_by(Report.updated_at.desc()).limit(500)]


class ReportIn(BaseModel):
    customer_id: int
    report_type: str
    work_subtype: str = ""
    template_id: int | None = None
    title: str = ""
    inspection_date: str = ""
    equipment_ids: list[int]
    log_ids: list[int] = []
    service_codes: list[str] = []
    plan: dict = {}
    engineer_id: int | None = None


def _apply(db: Session, r: Report, body: ReportIn) -> None:
    if body.report_type not in REPORT_TYPES:
        raise HTTPException(400, "알 수 없는 보고서 종류입니다.")
    if not body.equipment_ids:
        raise HTTPException(400, "장비를 1대 이상 선택하세요.")
    cust = db.get(Customer, body.customer_id)
    if not cust:
        raise HTTPException(404, "고객 없음")
    eqs = db.query(Equipment).filter(Equipment.id.in_(body.equipment_ids), Equipment.customer_id == cust.id).all()
    if len(eqs) != len(set(body.equipment_ids)):
        raise HTTPException(400, "선택한 장비가 고객 소속이 아닙니다.")
    logs = db.query(LogFile).filter(LogFile.id.in_(body.log_ids or [0]), LogFile.customer_id == cust.id).all()
    if body.report_type == "custom" and not body.template_id:
        raise HTTPException(400, "고객 양식 보고서는 양식을 선택해야 합니다.")
    if body.template_id and not db.get(ReportTemplate, body.template_id):
        raise HTTPException(400, "양식을 찾을 수 없습니다.")
    r.customer_id = cust.id
    r.customer = cust
    r.report_type, r.work_subtype, r.template_id = body.report_type, body.work_subtype, body.template_id
    r.inspection_date = body.inspection_date or datetime.now().strftime("%Y-%m-%d")
    r.service_codes = body.service_codes
    plan = dict(body.plan or {})
    if body.report_type == "workplan":
        sub = body.work_subtype or "etc"
        plan.setdefault("steps", [dict(s) for s in DEFAULT_STEPS.get(sub, DEFAULT_STEPS["etc"])])
        if not plan.get("steps"):
            plan["steps"] = [dict(s) for s in DEFAULT_STEPS.get(sub, DEFAULT_STEPS["etc"])]
        plan.setdefault("rollback", DEFAULT_ROLLBACK.get(sub, ""))
        plan.setdefault("location", cust.site or cust.address)
    r.plan = plan
    order = {eid: i for i, eid in enumerate(body.equipment_ids)}
    # 기존 링크는 재사용(유니크 제약 충돌 방지), 빠진 것만 제거
    cur = {l.equipment_id: l for l in r.equipment_links}
    for eid in list(cur):
        if eid not in order:
            r.equipment_links.remove(cur.pop(eid))
    for e in eqs:
        if e.id in cur:
            cur[e.id].seq = order[e.id] + 1
        else:
            r.equipment_links.append(ReportEquipment(equipment_id=e.id, equipment=e, seq=order[e.id] + 1))
    r.equipment_links.sort(key=lambda l: l.seq)
    want = {lg.id: lg for lg in logs}
    for l in list(r.log_links):
        if l.log_id not in want:
            r.log_links.remove(l)
    have = {l.log_id for l in r.log_links}
    for lid, lg in want.items():
        if lid not in have:
            r.log_links.append(ReportLog(log_id=lid, log=lg))
    r.variant = report_service.variant_for(len(eqs))
    label = WORK_SUBTYPES.get(r.work_subtype, "") + " 작업계획서" if r.report_type == "workplan" else REPORT_TYPES[r.report_type]
    r.title = body.title or f"{cust.name} {label}"
    if body.engineer_id:
        r.engineer_id = body.engineer_id


@router.post("/reports")
def create_report(body: ReportIn, db: Session = Depends(get_db), me: User = Depends(require("report.create"))):
    r = Report(report_no=report_service.next_report_no(db), status="draft", created_by=me.id, engineer_id=me.id,
               title="", report_type=body.report_type)
    _apply(db, r, body)
    db.add(r)
    db.flush()
    report_service.run_analysis(db, r)
    audit(db, me, "report.create", r.report_no, r.title)
    db.commit()
    return report_detail(db, r)


@router.get("/reports/{rid}")
def get_report(rid: int, db: Session = Depends(get_db), _: User = Depends(require("report.view"))):
    return report_detail(db, _get(db, rid))


@router.put("/reports/{rid}")
def update_report(rid: int, body: ReportIn, db: Session = Depends(get_db), me: User = Depends(require("report.edit"))):
    r = _get(db, rid)
    _apply(db, r, body)
    db.flush()
    report_service.run_analysis(db, r)
    if r.status in ("completed", "sent"):
        r.status = "review"  # 내용이 바뀌었으므로 최종 확정을 다시 받음
    audit(db, me, "report.update", r.report_no)
    db.commit()
    return report_detail(db, r)


class AiIn(BaseModel):
    notes: str = ""
    replace_diagnosis: bool = True


@router.post("/reports/{rid}/ai-advice")
def ai_advice(rid: int, body: AiIn, db: Session = Depends(get_db), me: User = Depends(require("report.edit"))):
    r = _get(db, rid)
    report_service.run_analysis(db, r)
    ctx = report_service.build_context(db, r)
    res = ai_advisor.generate(r.report_type, r.work_subtype, r.customer.name, ctx["equipments"], body.notes)
    r.ai_advice, r.ai_model, r.ai_generated_at = res["text"], res["model"], datetime.now()
    if body.replace_diagnosis or not r.diagnosis:
        r.diagnosis = res["text"]
    r.diagnosis_reviewed = False
    audit(db, me, "report.ai", r.report_no, res["model"])
    db.commit()
    out = report_detail(db, r)
    out["ai_note"] = res["note"]
    out["ai_fallback"] = res["fallback"]
    return out


class DiagIn(BaseModel):
    diagnosis: str
    reviewed: bool


@router.put("/reports/{rid}/diagnosis")
def save_diagnosis(rid: int, body: DiagIn, db: Session = Depends(get_db), me: User = Depends(require("report.edit"))):
    r = _get(db, rid)
    if body.reviewed and not body.diagnosis.strip():
        raise HTTPException(400, "진단 의견을 입력하세요.")
    if body.reviewed and not r.ai_advice:
        raise HTTPException(400, "AI 진단 조언을 먼저 받아야 합니다. (필수)")
    r.diagnosis, r.diagnosis_reviewed = body.diagnosis, body.reviewed
    audit(db, me, "report.diagnosis", r.report_no, "검토 확정" if body.reviewed else "임시 저장")
    db.commit()
    return report_detail(db, r)


class PlanIn(BaseModel):
    plan: dict


@router.put("/reports/{rid}/plan")
def save_plan(rid: int, body: PlanIn, db: Session = Depends(get_db), me: User = Depends(require("report.edit"))):
    r = _get(db, rid)
    r.plan = body.plan
    db.commit()
    return report_detail(db, r)


@router.post("/reports/{rid}/generate")
def generate(rid: int, db: Session = Depends(get_db), me: User = Depends(require("report.edit"))):
    r = _get(db, rid)
    if not r.ai_advice:
        raise HTTPException(400, "AI 진단 조언을 먼저 받아야 합니다. (필수)")
    if not r.diagnosis_reviewed or not (r.diagnosis or "").strip():
        raise HTTPException(400, "엔지니어가 진단 의견을 검토·확정해야 보고서를 생성할 수 있습니다.")
    report_service.run_analysis(db, r)
    report_service.generate_files(db, r, final=False)
    if r.status == "draft":
        r.status = "review"
    audit(db, me, "report.generate", r.report_no)
    db.commit()
    return report_detail(db, r)


class FinalizeIn(BaseModel):
    visual_inspection: dict[str, dict[str, str]]
    final_comment: str


@router.put("/reports/{rid}/finalize")
def finalize(rid: int, body: FinalizeIn, db: Session = Depends(get_db), me: User = Depends(require("report.edit"))):
    r = _get(db, rid)
    if r.status == "draft":
        raise HTTPException(400, "먼저 보고서(검토본)를 생성하세요.")
    r.visual_inspection = body.visual_inspection
    r.final_comment = body.final_comment.strip()
    miss = report_service.missing_for_completion(r)
    if miss:
        db.commit()  # 입력값은 임시 저장
        raise HTTPException(400, "필수 항목 누락: " + " / ".join(miss))
    report_service.generate_files(db, r, final=True)
    r.status, r.completed_at = "completed", datetime.now()
    audit(db, me, "report.complete", r.report_no)
    db.commit()
    return report_detail(db, r)


@router.get("/reports/{rid}/files/{fid}")
def download(rid: int, fid: int, db: Session = Depends(get_db), _: User = Depends(require("report.view"))):
    f = db.get(ReportFile, fid)
    if not f or f.report_id != rid or not Path(f.stored_path).exists():
        raise HTTPException(404, "파일 없음")
    media = ("application/vnd.openxmlformats-officedocument.presentationml.presentation" if f.file_format == "pptx"
             else "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")
    return FileResponse(f.stored_path, filename=f.filename, media_type=media)


class SendIn(BaseModel):
    to: list[str]
    cc: list[str] = []
    subject: str = ""
    message: str = ""
    file_ids: list[int] = []


@router.post("/reports/{rid}/send")
def send(rid: int, body: SendIn, db: Session = Depends(get_db), me: User = Depends(require("report.send"))):
    r = _get(db, rid)
    if r.status not in ("completed", "sent"):
        raise HTTPException(400, "최종 완료된 보고서만 발송할 수 있습니다.")
    to = [x.strip() for x in body.to if x.strip()]
    if not to:
        raise HTTPException(400, "받는 사람을 입력하세요.")
    files = [f for f in r.files if not body.file_ids or f.id in body.file_ids]
    company = runtime_settings.current().company_name
    subject = body.subject or f"[{company}] {r.title} ({r.inspection_date})"
    msg = body.message or f"안녕하세요, {company}입니다.\n\n{r.title}를 첨부와 같이 송부드립니다.\n검토 부탁드립니다.\n\n감사합니다."
    html_body = (
        "<div style=\"font-family:'Malgun Gothic',sans-serif;font-size:14px;color:#1f2937\">"
        "<div style='background:#0B2E4F;color:#fff;padding:16px 20px;font-size:18px;font-weight:700'>"
        f"{html.escape(r.title)}</div><div style='height:4px;background:#1969A5'></div>"
        f"<div style='padding:20px;line-height:1.7'>{html.escape(msg).replace(chr(10), '<br>')}</div>"
        "<div style='padding:12px 20px;color:#6b7785;font-size:12px;border-top:1px solid #d5dee8'>"
        f"첨부: {html.escape(', '.join(f.filename for f in files))}<br>MRS · Management Report System</div></div>"
    )
    log = MailLog(report_id=r.id, recipients=to, cc=body.cc, subject=subject, status="sent", sent_by=me.id)
    try:
        mailer.send(to, body.cc, subject, msg, html_body, [(f.filename, f.stored_path) for f in files])
        r.status, r.sent_at = "sent", datetime.now()
    except Exception as e:
        log.status, log.error = "failed", str(e)
    db.add(log)
    audit(db, me, "report.send", r.report_no, f"{log.status}: {', '.join(to)}")
    db.commit()
    if log.status == "failed":
        raise HTTPException(502, f"메일 발송 실패: {log.error}")
    return report_detail(db, r)


@router.delete("/reports/{rid}")
def delete_report(rid: int, db: Session = Depends(get_db), me: User = Depends(require("report.delete"))):
    r = _get(db, rid)
    shutil.rmtree(settings.reports_dir / r.report_no, ignore_errors=True)
    audit(db, me, "report.delete", r.report_no, r.title)
    db.delete(r)
    db.commit()
    return {"ok": True}
