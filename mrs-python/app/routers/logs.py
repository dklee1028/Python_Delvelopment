"""로그 업로드(드래그&드롭) · 장비별/시점별 보관 · 조회."""
from __future__ import annotations

import hashlib
import re
from datetime import datetime
from pathlib import Path

from fastapi import APIRouter, Depends, File, Form, HTTPException, UploadFile
from fastapi.responses import FileResponse
from sqlalchemy.orm import Session

from ..auth import audit, require
from ..config import settings
from ..db import get_db
from ..models import Customer, Equipment, LogFile, User
from ..services import analyzer, log_parser

router = APIRouter(prefix="/api", tags=["logs"])
MAX_BYTES = 200 * 1024 * 1024


def log_out(lg: LogFile, eq_names: dict | None = None) -> dict:
    p = lg.parsed or {}
    a = analyzer.analyze({}, p) if p else None
    return {
        "id": lg.id, "customer_id": lg.customer_id, "equipment_id": lg.equipment_id,
        "equipment_name": (eq_names or {}).get(lg.equipment_id, ""),
        "collected_at": lg.collected_at.strftime("%Y-%m-%d %H:%M"), "uploaded_at": lg.created_at.strftime("%Y-%m-%d %H:%M"),
        "original_name": lg.original_name, "size_bytes": lg.size_bytes, "note": lg.note,
        "summary": {
            "model": p.get("system", {}).get("model"), "os_version": p.get("system", {}).get("os_version"),
            "aggregates": len(p.get("aggregates", [])), "volumes": len(p.get("volumes", [])),
            "failed_disks": len(p.get("disks", {}).get("failed", [])),
            "events": {k: p.get("events", {}).get(k, 0) for k in ("emergency", "alert", "error")},
            "status": a["status"] if a else "normal", "score": a["score"] if a else None,
        },
    }


def _safe(name: str) -> str:
    return re.sub(r"[^\w.\-가-힣]+", "_", Path(name).name)[:150] or "log.txt"


@router.post("/customers/{cid}/logs")
async def upload_logs(
    cid: int,
    files: list[UploadFile] = File(...),
    equipment_id: int | None = Form(None),
    collected_at: str | None = Form(None),
    note: str = Form(""),
    db: Session = Depends(get_db),
    me: User = Depends(require("log.upload")),
):
    cust = db.get(Customer, cid)
    if not cust:
        raise HTTPException(404, "고객 없음")
    eq = db.get(Equipment, equipment_id) if equipment_id else None
    if equipment_id and (not eq or eq.customer_id != cid):
        raise HTTPException(400, "해당 고객의 장비가 아닙니다.")
    when = datetime.fromisoformat(collected_at) if collected_at else datetime.now()
    out = []
    for f in files:
        data = await f.read()
        if len(data) > MAX_BYTES:
            raise HTTPException(413, f"{f.filename}: 200MB 를 초과합니다.")
        parsed = log_parser.parse_file(f.filename or "log.txt", data)
        # 장비 미지정 시: 로그의 시리얼/호스트명으로 자동 매칭
        target = eq
        if not target:
            ser = (parsed.get("system", {}).get("serial") or "").split(" / ")[0]
            host = parsed.get("system", {}).get("hostname") or ""
            for cand in cust.equipment:
                if (ser and ser in (cand.serial or "")) or (host and host == cand.name):
                    target = cand
                    break
        # 장비별 / 시점별 폴더에 보관
        folder = settings.logs_dir / f"c{cid}" / (f"e{target.id}" if target else "unassigned") / when.strftime("%Y%m%d_%H%M")
        folder.mkdir(parents=True, exist_ok=True)
        path = folder / _safe(f.filename or "log.txt")
        path.write_bytes(data)
        lg = LogFile(customer_id=cid, equipment_id=target.id if target else None, collected_at=when,
                     original_name=f.filename or path.name, stored_path=str(path), size_bytes=len(data),
                     sha256=hashlib.sha256(data).hexdigest(), parsed=parsed, note=note, uploaded_by=me.id)
        db.add(lg)
        db.flush()
        out.append(lg)
    audit(db, me, "log.upload", cust.name, ", ".join(x.original_name for x in out))
    db.commit()
    names = {e.id: e.name for e in cust.equipment}
    return [log_out(x, names) for x in out]


@router.get("/customers/{cid}/logs")
def list_logs(cid: int, equipment_id: int | None = None, date_from: str | None = None, date_to: str | None = None,
              db: Session = Depends(get_db), _: User = Depends(require("customer.view"))):
    qs = db.query(LogFile).filter(LogFile.customer_id == cid)
    if equipment_id:
        qs = qs.filter(LogFile.equipment_id == equipment_id)
    if date_from:
        qs = qs.filter(LogFile.collected_at >= datetime.fromisoformat(date_from))
    if date_to:
        qs = qs.filter(LogFile.collected_at <= datetime.fromisoformat(date_to + "T23:59:59"))
    names = {e.id: e.name for e in db.query(Equipment).filter(Equipment.customer_id == cid)}
    return [log_out(x, names) for x in qs.order_by(LogFile.collected_at.desc()).limit(500)]


@router.get("/logs/{lid}")
def get_log(lid: int, db: Session = Depends(get_db), _: User = Depends(require("customer.view"))):
    lg = db.get(LogFile, lid)
    if not lg:
        raise HTTPException(404, "로그 없음")
    d = log_out(lg)
    d["parsed"] = lg.parsed
    d["analysis"] = analyzer.analyze({}, lg.parsed or {})
    return d


@router.patch("/logs/{lid}")
def assign_log(lid: int, equipment_id: int | None = None, db: Session = Depends(get_db), me: User = Depends(require("log.upload"))):
    lg = db.get(LogFile, lid)
    if not lg:
        raise HTTPException(404, "로그 없음")
    if equipment_id:
        eq = db.get(Equipment, equipment_id)
        if not eq or eq.customer_id != lg.customer_id:
            raise HTTPException(400, "해당 고객의 장비가 아닙니다.")
    lg.equipment_id = equipment_id
    db.commit()
    return log_out(lg)


@router.get("/logs/{lid}/download")
def download_log(lid: int, db: Session = Depends(get_db), _: User = Depends(require("customer.view"))):
    lg = db.get(LogFile, lid)
    if not lg or not Path(lg.stored_path).exists():
        raise HTTPException(404, "로그 없음")
    return FileResponse(lg.stored_path, filename=lg.original_name)


@router.delete("/logs/{lid}")
def delete_log(lid: int, db: Session = Depends(get_db), me: User = Depends(require("log.delete"))):
    lg = db.get(LogFile, lid)
    if not lg:
        raise HTTPException(404, "로그 없음")
    Path(lg.stored_path).unlink(missing_ok=True)
    audit(db, me, "log.delete", lg.original_name)
    db.delete(lg)
    db.commit()
    return {"ok": True}
