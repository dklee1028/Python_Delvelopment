from __future__ import annotations

from sqlalchemy import create_engine
from sqlalchemy.orm import DeclarativeBase, sessionmaker

from .config import settings

_kwargs = {"pool_pre_ping": True}
if settings.db_url.startswith("sqlite"):
    _kwargs["connect_args"] = {"check_same_thread": False}
else:
    _kwargs["pool_recycle"] = 3600

engine = create_engine(settings.db_url, **_kwargs)
SessionLocal = sessionmaker(bind=engine, autoflush=False, expire_on_commit=False)


class Base(DeclarativeBase):
    pass


def get_db():
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()


def init_db() -> None:
    """mrs_ 테이블만 생성합니다(이미 있으면 건드리지 않음). 기존 테이블은 절대 변경하지 않습니다."""
    from . import models  # noqa: F401

    Base.metadata.create_all(bind=engine, checkfirst=True)
