"""운영 설정: 화면(mrs_setting)에 저장된 값 → 없으면 .env 값.

비밀값(SMTP 비밀번호, Anthropic API 키)은 MRS_SECRET_KEY 로 파생한 키로 암호화해 저장합니다.
"""
from __future__ import annotations

import base64
import hashlib
from types import SimpleNamespace

from cryptography.fernet import Fernet, InvalidToken

from .config import settings
from .db import SessionLocal

# key: (라벨, 종류, 비밀여부, .env 속성)
FIELDS: dict[str, tuple[str, str, bool, str]] = {
    "company_name": ("회사명", "text", False, "company_name"),
    "company_address": ("회사 주소", "text", False, "company_address"),
    "smtp_host": ("SMTP 서버", "text", False, "smtp_host"),
    "smtp_port": ("SMTP 포트", "number", False, "smtp_port"),
    "smtp_starttls": ("STARTTLS 사용", "bool", False, "smtp_starttls"),
    "smtp_user": ("SMTP 계정", "text", False, "smtp_user"),
    "smtp_password": ("SMTP 비밀번호", "password", True, "smtp_password"),
    "mail_from": ("보내는 사람", "text", False, "mail_from"),
    "anthropic_key": ("Anthropic API 키", "password", True, "anthropic_key"),
    "ai_model": ("Claude 모델", "text", False, "ai_model"),
}


def _fernet() -> Fernet:
    return Fernet(base64.urlsafe_b64encode(hashlib.sha256(("mrs:" + settings.secret_key).encode()).digest()))


def encrypt(v: str) -> str:
    return _fernet().encrypt(v.encode()).decode()


def decrypt(v: str) -> str:
    try:
        return _fernet().decrypt(v.encode()).decode()
    except (InvalidToken, ValueError):
        return ""


def _cast(kind: str, raw):
    if kind == "number":
        try:
            return int(raw)
        except (TypeError, ValueError):
            return 0
    if kind == "bool":
        return raw if isinstance(raw, bool) else str(raw).lower() in ("1", "true", "yes", "on")
    return raw or ""


def stored() -> dict:
    from .models import SystemSetting

    with SessionLocal() as db:
        return {s.key: s for s in db.query(SystemSetting).all()}


def current() -> SimpleNamespace:
    rows = stored()
    out = {}
    for key, (_, kind, secret, attr) in FIELDS.items():
        row = rows.get(key)
        if row is not None and row.value != "":
            raw = decrypt(row.value) if row.is_secret else row.value
        else:
            raw = getattr(settings, attr)
        out[key] = _cast(kind, raw)
    return SimpleNamespace(**out)
