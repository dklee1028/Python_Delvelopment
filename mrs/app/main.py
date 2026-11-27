"""MRS 진입점. https://www.datanex.io.kr/mms 하위 경로로 서비스됩니다.

실행: uvicorn app.main:app --host 127.0.0.1 --port 8000
"""
from __future__ import annotations

from contextlib import asynccontextmanager
from pathlib import Path

from fastapi import FastAPI, Request
from fastapi.responses import FileResponse, JSONResponse, RedirectResponse
from fastapi.staticfiles import StaticFiles

from . import __version__
from .auth import ensure_admin
from .config import settings
from .db import SessionLocal, init_db
from .routers import auth_users, customers, logs, reports, settings as settings_router, templates

STATIC = Path(__file__).resolve().parent / "static"


def create_mrs() -> FastAPI:
    mrs = FastAPI(title="MRS - Management Report System", version=__version__, docs_url="/api/docs",
                  openapi_url="/api/openapi.json")
    for r in (auth_users.router, customers.router, logs.router, templates.router, reports.router, settings_router.router):
        mrs.include_router(r)

    @mrs.get("/api/health")
    def health():
        return {"ok": True, "version": __version__}

    @mrs.get("/manifest.webmanifest")
    def manifest():
        base = settings.base_path + "/"
        return JSONResponse({
            "name": "MRS - Management Report System",
            "short_name": "MRS",
            "description": "DATANEX 고객 보고서 자동 생성 시스템",
            "start_url": base,
            "scope": base,
            "display": "standalone",
            "background_color": "#0B2E4F",
            "theme_color": "#1969A5",
            "lang": "ko",
            "icons": [
                {"src": base + "static/icons/mrs-192.png", "sizes": "192x192", "type": "image/png"},
                {"src": base + "static/icons/mrs-512.png", "sizes": "512x512", "type": "image/png"},
                {"src": base + "static/icons/mrs-maskable-512.png", "sizes": "512x512", "type": "image/png",
                 "purpose": "maskable"},
            ],
        }, media_type="application/manifest+json")

    @mrs.get("/sw.js")
    def sw():
        return FileResponse(STATIC / "sw.js", media_type="application/javascript",
                            headers={"Service-Worker-Allowed": settings.base_path + "/", "Cache-Control": "no-cache"})

    mrs.mount("/static", StaticFiles(directory=STATIC), name="static")

    @mrs.get("/")
    def index():
        return FileResponse(STATIC / "index.html", headers={"Cache-Control": "no-cache"})

    return mrs


def bootstrap() -> None:
    """mrs_ 테이블 생성(없을 때만) + 최초 관리자 생성. 마운트된 하위 앱은 lifespan 이 돌지 않으므로 외부 앱에서 호출."""
    init_db()
    with SessionLocal() as db:
        ensure_admin(db)


mrs_app = create_mrs()


@asynccontextmanager
async def lifespan(_app):
    bootstrap()
    yield


app = FastAPI(title="DATANEX", docs_url=None, redoc_url=None, openapi_url=None, lifespan=lifespan)
app.mount(settings.base_path or "/mms", mrs_app)


@app.get("/")
def root():
    return RedirectResponse(settings.base_path + "/")


@app.middleware("http")
async def trailing_slash(request: Request, call_next):
    if request.url.path == settings.base_path:
        return RedirectResponse(settings.base_path + "/")
    return await call_next(request)
