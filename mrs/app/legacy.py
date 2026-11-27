"""기존(공유) MariaDB 고객 테이블 읽기 전용 조회.

테이블/컬럼명은 .env 로 매핑하며, SELECT 만 수행합니다. 기존 테이블에는 어떤 변경도 하지 않습니다.
"""
from __future__ import annotations

import re

from sqlalchemy import inspect, text

from .config import settings
from .db import engine

_IDENT = re.compile(r"^[A-Za-z_][A-Za-z0-9_$]{0,63}$")


def _q(ident: str) -> str:
    if not _IDENT.match(ident):
        raise ValueError(f"잘못된 식별자: {ident}")
    return f'"{ident}"' if engine.dialect.name == "sqlite" else f"`{ident}`"


def enabled() -> bool:
    if not settings.legacy_table:
        return False
    try:
        return inspect(engine).has_table(settings.legacy_table)
    except Exception:
        return False


def search(keyword: str = "", limit: int = 50) -> list[dict]:
    if not enabled():
        return []
    t, i, n, a = (
        _q(settings.legacy_table), _q(settings.legacy_id_col),
        _q(settings.legacy_name_col), _q(settings.legacy_addr_col),
    )
    sql = f"SELECT {i} AS id, {n} AS name, {a} AS address FROM {t}"
    params: dict = {"lim": int(limit)}
    if keyword:
        sql += f" WHERE {n} LIKE :kw OR {a} LIKE :kw"
        params["kw"] = f"%{keyword}%"
    sql += f" ORDER BY {n} LIMIT :lim"
    with engine.connect() as c:
        return [
            {"id": str(r.id), "name": r.name or "", "address": r.address or ""}
            for r in c.execute(text(sql), params)
        ]


def get(legacy_id: str) -> dict | None:
    if not enabled():
        return None
    t, i, n, a = (
        _q(settings.legacy_table), _q(settings.legacy_id_col),
        _q(settings.legacy_name_col), _q(settings.legacy_addr_col),
    )
    with engine.connect() as c:
        r = c.execute(
            text(f"SELECT {i} AS id, {n} AS name, {a} AS address FROM {t} WHERE {i} = :v"), {"v": legacy_id}
        ).first()
    return {"id": str(r.id), "name": r.name or "", "address": r.address or ""} if r else None
