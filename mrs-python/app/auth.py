"""로그인/세션/권한. 쿠키에 HMAC 서명 토큰을 저장합니다."""
from __future__ import annotations

import base64
import hashlib
import hmac
import json
import os
import time
from datetime import datetime

from fastapi import Depends, HTTPException, Request
from sqlalchemy.orm import Session

from .config import settings
from .db import get_db
from .models import AuditLog, User

COOKIE = "mrs_session"
SESSION_TTL = 60 * 60 * 12

PERMISSIONS: dict[str, str] = {
    "customer.view": "고객 조회",
    "customer.edit": "고객·담당자 등록/수정/삭제",
    "equipment.edit": "장비 등록/수정/삭제",
    "log.upload": "로그 업로드",
    "log.delete": "로그 삭제",
    "report.view": "보고서 조회",
    "report.create": "보고서 생성",
    "report.edit": "보고서 수정",
    "report.delete": "보고서 삭제",
    "report.send": "보고서 메일 발송",
    "template.manage": "고객 양식 관리",
    "user.manage": "사용자·권한 관리",
    "settings.manage": "시스템 설정(SMTP·회사정보·AI 키)",
}

ROLE_DEFAULTS: dict[str, set[str]] = {
    "admin": set(PERMISSIONS),
    "manager": set(PERMISSIONS) - {"user.manage", "settings.manage"},
    "engineer": {
        "customer.view", "customer.edit", "equipment.edit", "log.upload",
        "report.view", "report.create", "report.edit", "report.send",
    },
    "viewer": {"customer.view", "report.view"},
}
ROLE_LABELS = {"admin": "관리자", "manager": "팀장", "engineer": "엔지니어", "viewer": "조회자"}


# ── 비밀번호 ──
def hash_password(pw: str) -> str:
    salt = os.urandom(16)
    dk = hashlib.pbkdf2_hmac("sha256", pw.encode(), salt, 240_000)
    return "pbkdf2_sha256$240000$" + base64.b64encode(salt).decode() + "$" + base64.b64encode(dk).decode()


def verify_password(pw: str, stored: str) -> bool:
    try:
        _, it, salt_b64, dk_b64 = stored.split("$")
        dk = hashlib.pbkdf2_hmac("sha256", pw.encode(), base64.b64decode(salt_b64), int(it))
        return hmac.compare_digest(dk, base64.b64decode(dk_b64))
    except Exception:
        return False


# ── 토큰 ──
def _sign(payload: bytes) -> str:
    return hmac.new(settings.secret_key.encode(), payload, hashlib.sha256).hexdigest()


def make_token(user_id: int) -> str:
    body = base64.urlsafe_b64encode(json.dumps({"u": user_id, "e": int(time.time()) + SESSION_TTL}).encode())
    return body.decode() + "." + _sign(body)


def read_token(token: str) -> int | None:
    try:
        body, sig = token.rsplit(".", 1)
        if not hmac.compare_digest(sig, _sign(body.encode())):
            return None
        data = json.loads(base64.urlsafe_b64decode(body.encode()))
        if data["e"] < time.time():
            return None
        return int(data["u"])
    except Exception:
        return None


def effective_permissions(user: User) -> set[str]:
    perms = set(ROLE_DEFAULTS.get(user.role, set()))
    for k, v in (user.permissions or {}).items():
        if k in PERMISSIONS:
            (perms.add if v else perms.discard)(k)
    return perms


def current_user(request: Request, db: Session = Depends(get_db)) -> User:
    token = request.cookies.get(COOKIE) or request.headers.get("X-MRS-Token", "")
    uid = read_token(token) if token else None
    user = db.get(User, uid) if uid else None
    if not user or not user.is_active:
        raise HTTPException(401, "로그인이 필요합니다.")
    return user


def require(perm: str):
    def dep(user: User = Depends(current_user)) -> User:
        if perm not in effective_permissions(user):
            raise HTTPException(403, f"권한이 없습니다: {PERMISSIONS.get(perm, perm)}")
        return user

    return dep


def audit(db: Session, user: User | None, action: str, target: str = "", detail: str = "") -> None:
    db.add(AuditLog(user_id=user.id if user else None, action=action, target=target, detail=detail[:2000]))


def ensure_admin(db: Session) -> None:
    if db.query(User).count() == 0:
        db.add(
            User(
                username=settings.admin_username,
                full_name="시스템 관리자",
                password_hash=hash_password(settings.admin_password),
                role="admin",
                last_login_at=datetime.now(),
            )
        )
        db.commit()
