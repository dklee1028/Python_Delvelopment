"""환경변수 기반 설정. 비밀값은 코드에 두지 않고 .env / 환경변수로만 받습니다."""
from __future__ import annotations

import os
from dataclasses import dataclass, field
from pathlib import Path


def _load_dotenv() -> None:
    env = Path(os.environ.get("MRS_ENV_FILE", Path(__file__).resolve().parent.parent / ".env"))
    if not env.exists():
        return
    for line in env.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        os.environ.setdefault(k.strip(), v.strip())


_load_dotenv()


def _b(name: str, default: bool) -> bool:
    v = os.environ.get(name)
    return default if v is None else v.strip().lower() in ("1", "true", "yes", "on")


@dataclass
class Settings:
    db_url: str = field(default_factory=lambda: os.environ.get("MRS_DB_URL", "sqlite:///./data/mrs_dev.db"))
    legacy_table: str = field(default_factory=lambda: os.environ.get("MRS_LEGACY_CUSTOMER_TABLE", ""))
    legacy_id_col: str = field(default_factory=lambda: os.environ.get("MRS_LEGACY_CUSTOMER_ID_COL", "id"))
    legacy_name_col: str = field(default_factory=lambda: os.environ.get("MRS_LEGACY_CUSTOMER_NAME_COL", "name"))
    legacy_addr_col: str = field(default_factory=lambda: os.environ.get("MRS_LEGACY_CUSTOMER_ADDR_COL", "address"))
    base_path: str = field(default_factory=lambda: os.environ.get("MRS_BASE_PATH", "/mms").rstrip("/"))
    public_url: str = field(default_factory=lambda: os.environ.get("MRS_PUBLIC_URL", "https://www.datanex.io.kr/mms"))
    secret_key: str = field(default_factory=lambda: os.environ.get("MRS_SECRET_KEY", "dev-secret-change-me"))
    admin_username: str = field(default_factory=lambda: os.environ.get("MRS_ADMIN_USERNAME", "admin"))
    admin_password: str = field(default_factory=lambda: os.environ.get("MRS_ADMIN_PASSWORD", "admin1234"))
    data_dir: Path = field(default_factory=lambda: Path(os.environ.get("MRS_DATA_DIR", "./data")).resolve())
    anthropic_key: str = field(default_factory=lambda: os.environ.get("ANTHROPIC_API_KEY", ""))
    ai_model: str = field(default_factory=lambda: os.environ.get("MRS_AI_MODEL", "claude-opus-5-5"))
    smtp_host: str = field(default_factory=lambda: os.environ.get("MRS_SMTP_HOST", ""))
    smtp_port: int = field(default_factory=lambda: int(os.environ.get("MRS_SMTP_PORT", "587")))
    smtp_user: str = field(default_factory=lambda: os.environ.get("MRS_SMTP_USER", ""))
    smtp_password: str = field(default_factory=lambda: os.environ.get("MRS_SMTP_PASSWORD", ""))
    smtp_starttls: bool = field(default_factory=lambda: _b("MRS_SMTP_STARTTLS", True))
    mail_from: str = field(default_factory=lambda: os.environ.get("MRS_MAIL_FROM", "MRS <noreply@datanex.io.kr>"))
    company_name: str = field(default_factory=lambda: os.environ.get("MRS_COMPANY_NAME", "(주)데이터넥스"))
    company_address: str = field(default_factory=lambda: os.environ.get("MRS_COMPANY_ADDRESS", ""))
    company_tel: str = field(default_factory=lambda: os.environ.get("MRS_COMPANY_TEL", ""))
    company_email: str = field(default_factory=lambda: os.environ.get("MRS_COMPANY_EMAIL", ""))

    @property
    def logs_dir(self) -> Path:
        return self.data_dir / "logs"

    @property
    def reports_dir(self) -> Path:
        return self.data_dir / "reports"

    @property
    def templates_dir(self) -> Path:
        return self.data_dir / "templates"


settings = Settings()
for _d in (settings.data_dir, settings.logs_dir, settings.reports_dir, settings.templates_dir):
    _d.mkdir(parents=True, exist_ok=True)
