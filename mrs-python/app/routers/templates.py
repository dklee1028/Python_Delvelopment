"""고객 별도 양식 등록/수정/삭제."""
from __future__ import annotations

import uuid
from pathlib import Path

from fastapi import APIRouter, Depends, File, Form, HTTPException, UploadFile
from fastapi.responses import FileResponse
from sqlalchemy.orm import Session

from ..auth import audit, current_user, require
from ..config import settings
from ..db import get_db
from ..models import ReportTemplate, User
from ..services import custom_template

router = APIRouter(prefix="/api", tags=["templates"])


def tpl_out(t: ReportTemplate) -> dict:
    return {k: getattr(t, k) for k in ("id", "name", "report_type", "customer_id", "file_format", "original_name",
                                        "description", "placeholders", "is_active")} | {
        "updated_at": t.updated_at.strftime("%Y-%m-%d %H:%M")}


@router.get("/templates/placeholders")
def placeholders(_: User = Depends(current_user)):
    return custom_template.PLACEHOLDER_HELP


@router.get("/templates")
def list_templates(customer_id: int | None = None, db: Session = Depends(get_db), _: User = Depends(require("report.view"))):
    qs = db.query(ReportTemplate)
    if customer_id:
        qs = qs.filter((ReportTemplate.customer_id == customer_id) | (ReportTemplate.customer_id.is_(None)))
    return [tpl_out(t) for t in qs.order_by(ReportTemplate.name)]


async def _store(file: UploadFile) -> tuple[str, str]:
    ext = Path(file.filename or "").suffix.lower().lstrip(".")
    if ext not in ("pptx", "xlsx"):
        raise HTTPException(400, "pptx 또는 xlsx 양식만 등록할 수 있습니다.")
    path = settings.templates_dir / f"{uuid.uuid4().hex}.{ext}"
    path.write_bytes(await file.read())
    try:
        custom_template.scan(str(path), ext)
    except Exception:
        path.unlink(missing_ok=True)
        raise HTTPException(400, "양식 파일을 읽을 수 없습니다.")
    return str(path), ext


@router.post("/templates")
async def create_template(name: str = Form(...), report_type: str = Form("regular"), customer_id: int | None = Form(None),
                          description: str = Form(""), file: UploadFile = File(...), db: Session = Depends(get_db),
                          me: User = Depends(require("template.manage"))):
    path, ext = await _store(file)
    t = ReportTemplate(name=name, report_type=report_type, customer_id=customer_id, description=description,
                       file_format=ext, stored_path=path, original_name=file.filename or "",
                       placeholders=custom_template.scan(path, ext))
    db.add(t)
    audit(db, me, "template.create", name)
    db.commit()
    return tpl_out(t)


@router.put("/templates/{tid}")
async def update_template(tid: int, name: str = Form(...), report_type: str = Form("regular"),
                          customer_id: int | None = Form(None), description: str = Form(""), is_active: bool = Form(True),
                          file: UploadFile | None = File(None), db: Session = Depends(get_db),
                          me: User = Depends(require("template.manage"))):
    t = db.get(ReportTemplate, tid)
    if not t:
        raise HTTPException(404, "양식 없음")
    t.name, t.report_type, t.customer_id, t.description, t.is_active = name, report_type, customer_id, description, is_active
    if file and file.filename:
        old = t.stored_path
        t.stored_path, t.file_format = await _store(file)
        t.original_name = file.filename
        t.placeholders = custom_template.scan(t.stored_path, t.file_format)
        Path(old).unlink(missing_ok=True)
    audit(db, me, "template.update", name)
    db.commit()
    return tpl_out(t)


@router.get("/templates/{tid}/download")
def download_template(tid: int, db: Session = Depends(get_db), _: User = Depends(require("report.view"))):
    t = db.get(ReportTemplate, tid)
    if not t:
        raise HTTPException(404, "양식 없음")
    return FileResponse(t.stored_path, filename=t.original_name or f"template.{t.file_format}")


@router.delete("/templates/{tid}")
def delete_template(tid: int, db: Session = Depends(get_db), me: User = Depends(require("template.manage"))):
    t = db.get(ReportTemplate, tid)
    if not t:
        raise HTTPException(404, "양식 없음")
    Path(t.stored_path).unlink(missing_ok=True)
    audit(db, me, "template.delete", t.name)
    db.delete(t)
    db.commit()
    return {"ok": True}
