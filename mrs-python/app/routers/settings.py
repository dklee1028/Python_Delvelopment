"""시스템 설정 화면 API (SMTP 계정·회사 정보·Anthropic API 키). 권한: settings.manage"""
from __future__ import annotations

from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel
from sqlalchemy.orm import Session

from .. import runtime_settings as rs
from ..auth import audit, require
from ..db import get_db
from ..models import SystemSetting, User
from ..services import mailer

router = APIRouter(prefix="/api/settings", tags=["settings"])


def _mask(v: str) -> str:
    if not v:
        return ""
    return ("•" * 8) + v[-4:] if len(v) > 8 else "•" * 8


@router.get("")
def get_settings(_: User = Depends(require("settings.manage"))):
    cur, rows = rs.current(), rs.stored()
    items = []
    for key, (label, kind, secret, _attr) in rs.FIELDS.items():
        v = getattr(cur, key)
        row = rows.get(key)
        items.append({
            "key": key, "label": label, "kind": kind, "secret": secret,
            "value": "" if secret else v,
            "masked": _mask(v) if secret else "",
            "is_set": bool(v) if secret else None,
            "source": "db" if row is not None and row.value != "" else ("env" if v not in ("", 0, None) else "none"),
        })
    return {"items": items}


class SettingsIn(BaseModel):
    values: dict[str, object]
    clear: list[str] = []  # 비밀값 삭제(화면 값으로 되돌림)


@router.put("")
def put_settings(body: SettingsIn, db: Session = Depends(get_db), me: User = Depends(require("settings.manage"))):
    changed = []
    for key, raw in body.values.items():
        if key not in rs.FIELDS:
            raise HTTPException(400, f"알 수 없는 설정: {key}")
        _label, kind, secret, _ = rs.FIELDS[key]
        if secret and (raw is None or str(raw) == ""):
            continue  # 비밀값은 비워 두면 기존 값 유지
        if kind == "bool":
            val = "true" if (raw is True or str(raw).lower() in ("1", "true", "yes", "on")) else "false"
        elif kind == "number":
            try:
                val = str(int(raw))
            except (TypeError, ValueError):
                raise HTTPException(400, f"{_label}은(는) 숫자여야 합니다.")
        else:
            val = str(raw).strip()
        row = db.get(SystemSetting, key) or SystemSetting(key=key)
        row.value, row.is_secret, row.updated_by = (rs.encrypt(val) if secret and val else val), secret, me.id
        db.merge(row)
        changed.append(key)
    for key in body.clear:
        if key in rs.FIELDS:
            row = db.get(SystemSetting, key)
            if row:
                db.delete(row)
                changed.append(key)
    audit(db, me, "settings.update", detail=", ".join(changed))  # 값 자체는 기록하지 않음
    db.commit()
    return {"changed": changed}


class TestMailIn(BaseModel):
    to: str


@router.post("/test-mail")
def test_mail(body: TestMailIn, me: User = Depends(require("settings.manage"))):
    to = body.to.strip()
    if "@" not in to:
        raise HTTPException(400, "받는 주소를 확인하세요.")
    try:
        mailer.send([to], [], "[MRS] SMTP 설정 확인 메일", "MRS SMTP 설정이 정상입니다.",
                    "<p>MRS SMTP 설정이 정상입니다.</p>", [])
    except Exception as e:
        raise HTTPException(502, f"발송 실패: {e}")
    return {"ok": True}
