"""MRS 전용 테이블 (모두 mrs_ 접두어). 기존 고객 테이블은 수정하지 않고 legacy_customer_id 로만 참조합니다."""
from __future__ import annotations

from datetime import datetime

from sqlalchemy import (
    JSON,
    Boolean,
    DateTime,
    ForeignKey,
    Integer,
    String,
    Text,
    UniqueConstraint,
)
from sqlalchemy.orm import Mapped, mapped_column, relationship

from .db import Base


def _now() -> datetime:
    return datetime.now()


class TimestampMixin:
    created_at: Mapped[datetime] = mapped_column(DateTime, default=_now)
    updated_at: Mapped[datetime] = mapped_column(DateTime, default=_now, onupdate=_now)


# ───────────────────────── 사용자 / 권한 ─────────────────────────
class User(Base, TimestampMixin):
    __tablename__ = "mrs_user"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    username: Mapped[str] = mapped_column(String(64), unique=True, index=True)
    full_name: Mapped[str] = mapped_column(String(100), default="")
    email: Mapped[str] = mapped_column(String(200), default="")
    phone: Mapped[str] = mapped_column(String(50), default="")
    title: Mapped[str] = mapped_column(String(50), default="")  # 직급 (예: 매니저)
    password_hash: Mapped[str] = mapped_column(String(255))
    role: Mapped[str] = mapped_column(String(20), default="engineer")  # admin/manager/engineer/viewer
    permissions: Mapped[dict] = mapped_column(JSON, default=dict)  # 역할 기본값을 개별 덮어쓰기
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    last_login_at: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)


# ───────────────────────── 고객 ─────────────────────────
class Customer(Base, TimestampMixin):
    __tablename__ = "mrs_customer"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    legacy_customer_id: Mapped[str | None] = mapped_column(String(64), nullable=True, index=True)
    name: Mapped[str] = mapped_column(String(200))  # 기존 DB와 연결된 경우 동기화된 사본
    address: Mapped[str] = mapped_column(String(500), default="")
    site: Mapped[str] = mapped_column(String(200), default="")  # 전산실/IDC 위치
    memo: Mapped[str] = mapped_column(Text, default="")
    contacts: Mapped[list["CustomerContact"]] = relationship(
        back_populates="customer", cascade="all, delete-orphan", order_by="CustomerContact.id"
    )
    equipment: Mapped[list["Equipment"]] = relationship(
        back_populates="customer", cascade="all, delete-orphan", order_by="Equipment.id"
    )


class CustomerContact(Base, TimestampMixin):
    """고객사 전산담당자 (별도 등록/수정/삭제)."""

    __tablename__ = "mrs_customer_contact"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    customer_id: Mapped[int] = mapped_column(ForeignKey("mrs_customer.id", ondelete="CASCADE"), index=True)
    name: Mapped[str] = mapped_column(String(100))
    department: Mapped[str] = mapped_column(String(100), default="")
    position: Mapped[str] = mapped_column(String(100), default="")
    email: Mapped[str] = mapped_column(String(200), default="")
    phone: Mapped[str] = mapped_column(String(50), default="")
    receive_reports: Mapped[bool] = mapped_column(Boolean, default=True)
    is_primary: Mapped[bool] = mapped_column(Boolean, default=False)
    customer: Mapped[Customer] = relationship(back_populates="contacts")


# ───────────────────────── 장비 / 로그 ─────────────────────────
class Equipment(Base, TimestampMixin):
    __tablename__ = "mrs_equipment"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    customer_id: Mapped[int] = mapped_column(ForeignKey("mrs_customer.id", ondelete="CASCADE"), index=True)
    name: Mapped[str] = mapped_column(String(100))  # 시스템명/호스트명
    vendor: Mapped[str] = mapped_column(String(50), default="NetApp")
    model: Mapped[str] = mapped_column(String(100), default="")
    serial: Mapped[str] = mapped_column(String(200), default="")
    os_version: Mapped[str] = mapped_column(String(100), default="")
    ha_type: Mapped[str] = mapped_column(String(50), default="")
    disk_info: Mapped[str] = mapped_column(String(200), default="")
    location: Mapped[str] = mapped_column(String(200), default="")
    contract_type: Mapped[str] = mapped_column(String(100), default="")
    memo: Mapped[str] = mapped_column(Text, default="")
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    customer: Mapped[Customer] = relationship(back_populates="equipment")


class LogFile(Base, TimestampMixin):
    """업로드된 로그. 고객/장비/수집시점별로 보관·조회."""

    __tablename__ = "mrs_log_file"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    customer_id: Mapped[int] = mapped_column(ForeignKey("mrs_customer.id", ondelete="CASCADE"), index=True)
    equipment_id: Mapped[int | None] = mapped_column(
        ForeignKey("mrs_equipment.id", ondelete="SET NULL"), nullable=True, index=True
    )
    collected_at: Mapped[datetime] = mapped_column(DateTime, default=_now, index=True)
    original_name: Mapped[str] = mapped_column(String(300))
    stored_path: Mapped[str] = mapped_column(String(600))
    size_bytes: Mapped[int] = mapped_column(Integer, default=0)
    sha256: Mapped[str] = mapped_column(String(64), default="")
    parsed: Mapped[dict] = mapped_column(JSON, default=dict)
    note: Mapped[str] = mapped_column(String(500), default="")
    uploaded_by: Mapped[int | None] = mapped_column(ForeignKey("mrs_user.id", ondelete="SET NULL"), nullable=True)


# ───────────────────────── 양식 ─────────────────────────
class ReportTemplate(Base, TimestampMixin):
    """고객별 별도 양식(pptx/xlsx). {{customer_name}} 같은 치환자를 채워 생성."""

    __tablename__ = "mrs_report_template"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    name: Mapped[str] = mapped_column(String(200))
    report_type: Mapped[str] = mapped_column(String(30), default="regular")
    customer_id: Mapped[int | None] = mapped_column(
        ForeignKey("mrs_customer.id", ondelete="CASCADE"), nullable=True, index=True
    )
    file_format: Mapped[str] = mapped_column(String(10))  # pptx / xlsx
    stored_path: Mapped[str] = mapped_column(String(600))
    original_name: Mapped[str] = mapped_column(String(300), default="")
    description: Mapped[str] = mapped_column(Text, default="")
    placeholders: Mapped[list] = mapped_column(JSON, default=list)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)


# ───────────────────────── 보고서 ─────────────────────────
class Report(Base, TimestampMixin):
    __tablename__ = "mrs_report"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    report_no: Mapped[str] = mapped_column(String(40), unique=True, index=True)
    customer_id: Mapped[int] = mapped_column(ForeignKey("mrs_customer.id", ondelete="CASCADE"), index=True)
    report_type: Mapped[str] = mapped_column(String(30))  # regular/special/workplan/custom
    work_subtype: Mapped[str] = mapped_column(String(30), default="")  # os_upgrade/controller/disk/incident/...
    template_id: Mapped[int | None] = mapped_column(
        ForeignKey("mrs_report_template.id", ondelete="SET NULL"), nullable=True
    )
    title: Mapped[str] = mapped_column(String(300))
    variant: Mapped[str] = mapped_column(String(10), default="single")  # single(1) / multi(2~3) / fleet(4+)
    status: Mapped[str] = mapped_column(String(20), default="draft")  # draft/review/completed/sent
    inspection_date: Mapped[str] = mapped_column(String(20), default="")
    service_codes: Mapped[list] = mapped_column(JSON, default=list)
    analysis: Mapped[dict] = mapped_column(JSON, default=dict)  # 로그 분석 결과 (장비별)
    plan: Mapped[dict] = mapped_column(JSON, default=dict)  # 작업계획서 내용 (일정/절차/롤백 등)
    ai_advice: Mapped[str] = mapped_column(Text, default="")  # Claude 원문 조언
    ai_model: Mapped[str] = mapped_column(String(60), default="")
    ai_generated_at: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)
    diagnosis: Mapped[str] = mapped_column(Text, default="")  # 엔지니어 검토 후 확정본 (필수)
    diagnosis_reviewed: Mapped[bool] = mapped_column(Boolean, default=False)
    visual_inspection: Mapped[dict] = mapped_column(JSON, default=dict)  # {equipment_id: {item: 정상/비정상/N/A}}
    final_comment: Mapped[str] = mapped_column(Text, default="")  # 엔지니어 최종 Comment (필수)
    overall_status: Mapped[str] = mapped_column(String(20), default="")  # normal/warning/critical
    engineer_id: Mapped[int | None] = mapped_column(ForeignKey("mrs_user.id", ondelete="SET NULL"), nullable=True)
    created_by: Mapped[int | None] = mapped_column(ForeignKey("mrs_user.id", ondelete="SET NULL"), nullable=True)
    completed_at: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)
    sent_at: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)

    customer: Mapped[Customer] = relationship()
    equipment_links: Mapped[list["ReportEquipment"]] = relationship(
        cascade="all, delete-orphan", order_by="ReportEquipment.seq"
    )
    log_links: Mapped[list["ReportLog"]] = relationship(cascade="all, delete-orphan")
    files: Mapped[list["ReportFile"]] = relationship(cascade="all, delete-orphan")


class ReportEquipment(Base):
    __tablename__ = "mrs_report_equipment"
    __table_args__ = (UniqueConstraint("report_id", "equipment_id"),)
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    report_id: Mapped[int] = mapped_column(ForeignKey("mrs_report.id", ondelete="CASCADE"), index=True)
    equipment_id: Mapped[int] = mapped_column(ForeignKey("mrs_equipment.id", ondelete="CASCADE"))
    seq: Mapped[int] = mapped_column(Integer, default=1)
    equipment: Mapped[Equipment] = relationship()


class ReportLog(Base):
    __tablename__ = "mrs_report_log"
    __table_args__ = (UniqueConstraint("report_id", "log_id"),)
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    report_id: Mapped[int] = mapped_column(ForeignKey("mrs_report.id", ondelete="CASCADE"), index=True)
    log_id: Mapped[int] = mapped_column(ForeignKey("mrs_log_file.id", ondelete="CASCADE"))
    log: Mapped[LogFile] = relationship()


class ReportFile(Base, TimestampMixin):
    __tablename__ = "mrs_report_file"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    report_id: Mapped[int] = mapped_column(ForeignKey("mrs_report.id", ondelete="CASCADE"), index=True)
    file_format: Mapped[str] = mapped_column(String(10))  # pptx/xlsx
    kind: Mapped[str] = mapped_column(String(20), default="standard")  # standard/custom
    filename: Mapped[str] = mapped_column(String(300))
    stored_path: Mapped[str] = mapped_column(String(600))
    size_bytes: Mapped[int] = mapped_column(Integer, default=0)


class MailLog(Base, TimestampMixin):
    __tablename__ = "mrs_mail_log"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    report_id: Mapped[int] = mapped_column(ForeignKey("mrs_report.id", ondelete="CASCADE"), index=True)
    recipients: Mapped[list] = mapped_column(JSON, default=list)
    cc: Mapped[list] = mapped_column(JSON, default=list)
    subject: Mapped[str] = mapped_column(String(300))
    status: Mapped[str] = mapped_column(String(20))  # sent/failed
    error: Mapped[str] = mapped_column(Text, default="")
    sent_by: Mapped[int | None] = mapped_column(ForeignKey("mrs_user.id", ondelete="SET NULL"), nullable=True)


class AuditLog(Base):
    __tablename__ = "mrs_audit_log"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    at: Mapped[datetime] = mapped_column(DateTime, default=_now, index=True)
    user_id: Mapped[int | None] = mapped_column(Integer, nullable=True)
    action: Mapped[str] = mapped_column(String(50))
    target: Mapped[str] = mapped_column(String(100), default="")
    detail: Mapped[str] = mapped_column(Text, default="")
