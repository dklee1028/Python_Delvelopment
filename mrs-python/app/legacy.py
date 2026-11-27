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


# PMS(pms_customers)에 있으면 함께 읽어 오는 선택 컬럼. 없는 컬럼은 건너뜁니다.
OPTIONAL = ("code", "phone", "contact1", "contact1_phone", "contact1_email", "contact2", "contact2_phone")


def enabled() -> bool:
    if not settings.legacy_table:
        return False
    try:
        return inspect(engine).has_table(settings.legacy_table)
    except Exception:
        return False


def _columns() -> set[str]:
    try:
        return {c["name"] for c in inspect(engine).get_columns(settings.legacy_table)}
    except Exception:
        return set()


def _select() -> tuple[str, list[str], str]:
    t, i, n, a = (
        _q(settings.legacy_table), _q(settings.legacy_id_col),
        _q(settings.legacy_name_col), _q(settings.legacy_addr_col),
    )
    cols = _columns()
    extra = [c for c in OPTIONAL if c in cols]
    fields = ", ".join([f"{i} AS id", f"{n} AS name", f"{a} AS address"] + [f"{_q(c)} AS x_{c}" for c in extra])
    where = f"{_q('is_active')} = 1" if "is_active" in cols else "1 = 1"
    return f"SELECT {fields} FROM {t}", extra, where


def _row(r, extra: list[str]) -> dict:
    m = r._mapping
    d = {"id": str(m["id"]), "name": m["name"] or "", "address": m["address"] or ""}
    for c in extra:
        d[c] = m[f"x_{c}"] or ""
    return d


def contacts_of(src: dict) -> list[dict]:
    """PMS 고객의 담당자1·2를 MRS 담당자 초기값으로 변환."""
    out = []
    for k in ("contact1", "contact2"):
        name = (src.get(k) or "").strip()
        if name:
            out.append({"name": name, "phone": src.get(f"{k}_phone", ""), "email": src.get(f"{k}_email", "")})
    return out


def search(keyword: str = "", limit: int = 50) -> list[dict]:
    if not enabled():
        return []
    sql, extra, where = _select()
    n, a = _q(settings.legacy_name_col), _q(settings.legacy_addr_col)
    params: dict = {"lim": int(limit)}
    sql += f" WHERE {where}"
    if keyword:
        sql += f" AND ({n} LIKE :kw OR {a} LIKE :kw"
        if "code" in extra:
            sql += f" OR {_q('code')} LIKE :kw"
        sql += ")"
        params["kw"] = f"%{keyword}%"
    sql += f" ORDER BY {n} LIMIT :lim"
    with engine.connect() as c:
        return [_row(r, extra) for r in c.execute(text(sql), params)]


def get(legacy_id: str) -> dict | None:
    if not enabled():
        return None
    sql, extra, _ = _select()
    with engine.connect() as c:
        r = c.execute(text(f"{sql} WHERE {_q(settings.legacy_id_col)} = :v"), {"v": legacy_id}).first()
    return _row(r, extra) if r else None
