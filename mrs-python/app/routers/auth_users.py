from __future__ import annotations

from datetime import datetime

from fastapi import APIRouter, Depends, HTTPException, Request, Response
from pydantic import BaseModel
from sqlalchemy.orm import Session

from ..auth import (
    COOKIE, PERMISSIONS, ROLE_DEFAULTS, ROLE_LABELS, SESSION_TTL, audit, current_user, effective_permissions,
    hash_password, make_token, require, verify_password,
)
from ..config import settings
from ..db import get_db
from ..models import AuditLog, User

router = APIRouter(prefix="/api", tags=["auth"])


def user_out(u: User) -> dict:
    return {
        "id": u.id, "username": u.username, "full_name": u.full_name, "email": u.email, "phone": u.phone,
        "title": u.title, "role": u.role, "role_label": ROLE_LABELS.get(u.role, u.role),
        "permissions": sorted(effective_permissions(u)), "overrides": u.permissions or {}, "is_active": u.is_active,
        "last_login_at": u.last_login_at.isoformat() if u.last_login_at else None,
    }


class LoginIn(BaseModel):
    username: str
    password: str


@router.post("/auth/login")
def login(body: LoginIn, request: Request, response: Response, db: Session = Depends(get_db)):
    u = db.query(User).filter(User.username == body.username).first()
    if not u or not u.is_active or not verify_password(body.password, u.password_hash):
        raise HTTPException(401, "아이디 또는 비밀번호가 올바르지 않습니다.")
    u.last_login_at = datetime.now()
    audit(db, u, "login")
    db.commit()
    response.set_cookie(COOKIE, make_token(u.id), max_age=SESSION_TTL, httponly=True, samesite="lax",
                        secure=request.url.scheme == "https" or request.headers.get("x-forwarded-proto") == "https", path=settings.base_path or "/")
    return user_out(u)


@router.post("/auth/logout")
def logout(response: Response):
    response.delete_cookie(COOKIE, path=settings.base_path or "/")
    return {"ok": True}


@router.get("/auth/me")
def me(u: User = Depends(current_user)):
    return user_out(u)


class PwIn(BaseModel):
    current: str
    new: str


@router.post("/auth/password")
def change_password(body: PwIn, u: User = Depends(current_user), db: Session = Depends(get_db)):
    if not verify_password(body.current, u.password_hash):
        raise HTTPException(400, "현재 비밀번호가 올바르지 않습니다.")
    if len(body.new) < 8:
        raise HTTPException(400, "비밀번호는 8자 이상이어야 합니다.")
    u.password_hash = hash_password(body.new)
    db.commit()
    return {"ok": True}


@router.get("/permissions")
def permission_catalog(_: User = Depends(current_user)):
    return {"permissions": PERMISSIONS, "roles": ROLE_LABELS,
            "role_defaults": {k: sorted(v) for k, v in ROLE_DEFAULTS.items()}}


class UserIn(BaseModel):
    username: str
    full_name: str = ""
    email: str = ""
    phone: str = ""
    title: str = ""
    role: str = "engineer"
    password: str | None = None
    overrides: dict[str, bool] = {}
    is_active: bool = True


@router.get("/users")
def list_users(db: Session = Depends(get_db), _: User = Depends(current_user)):
    return [user_out(u) for u in db.query(User).order_by(User.id)]


@router.post("/users")
def create_user(body: UserIn, db: Session = Depends(get_db), me_: User = Depends(require("user.manage"))):
    if db.query(User).filter(User.username == body.username).first():
        raise HTTPException(400, "이미 존재하는 아이디입니다.")
    if body.role not in ROLE_DEFAULTS:
        raise HTTPException(400, "알 수 없는 역할입니다.")
    if not body.password or len(body.password) < 8:
        raise HTTPException(400, "초기 비밀번호는 8자 이상이어야 합니다.")
    u = User(username=body.username, full_name=body.full_name, email=body.email, phone=body.phone, title=body.title,
             role=body.role, permissions=body.overrides, is_active=body.is_active,
             password_hash=hash_password(body.password))
    db.add(u)
    audit(db, me_, "user.create", body.username)
    db.commit()
    return user_out(u)


@router.put("/users/{uid}")
def update_user(uid: int, body: UserIn, db: Session = Depends(get_db), me_: User = Depends(require("user.manage"))):
    u = db.get(User, uid)
    if not u:
        raise HTTPException(404, "사용자 없음")
    if body.role not in ROLE_DEFAULTS:
        raise HTTPException(400, "알 수 없는 역할입니다.")
    if u.id == me_.id and (body.role != "admin" or not body.is_active):
        raise HTTPException(400, "본인의 관리자 권한/활성 상태는 변경할 수 없습니다.")
    for k in ("full_name", "email", "phone", "title", "role", "is_active"):
        setattr(u, k, getattr(body, k))
    u.permissions = body.overrides
    if body.password:
        if len(body.password) < 8:
            raise HTTPException(400, "비밀번호는 8자 이상이어야 합니다.")
        u.password_hash = hash_password(body.password)
    audit(db, me_, "user.update", u.username)
    db.commit()
    return user_out(u)


@router.delete("/users/{uid}")
def delete_user(uid: int, db: Session = Depends(get_db), me_: User = Depends(require("user.manage"))):
    u = db.get(User, uid)
    if not u:
        raise HTTPException(404, "사용자 없음")
    if u.id == me_.id:
        raise HTTPException(400, "본인 계정은 삭제할 수 없습니다.")
    audit(db, me_, "user.delete", u.username)
    db.delete(u)
    db.commit()
    return {"ok": True}


@router.get("/audit")
def audit_log(limit: int = 100, db: Session = Depends(get_db), _: User = Depends(require("user.manage"))):
    rows = db.query(AuditLog).order_by(AuditLog.id.desc()).limit(min(limit, 500)).all()
    names = {u.id: u.full_name or u.username for u in db.query(User)}
    return [{"at": r.at.isoformat(), "user": names.get(r.user_id, "-"), "action": r.action, "target": r.target,
             "detail": r.detail} for r in rows]
