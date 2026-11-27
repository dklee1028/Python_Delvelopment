"""SMTP 메일 발송 (보고서 PPT/엑셀 첨부)."""
from __future__ import annotations

import mimetypes
import smtplib
import ssl
from email.message import EmailMessage
from pathlib import Path

from ..config import settings


class MailNotConfigured(RuntimeError):
    pass


def send(to: list[str], cc: list[str], subject: str, body_text: str, body_html: str, attachments: list[tuple[str, str]]) -> None:
    """attachments: [(표시 파일명, 로컬 경로)]"""
    if not settings.smtp_host:
        raise MailNotConfigured("SMTP 설정(MRS_SMTP_HOST 등)이 없어 메일을 보낼 수 없습니다.")
    msg = EmailMessage()
    msg["From"] = settings.mail_from
    msg["To"] = ", ".join(to)
    if cc:
        msg["Cc"] = ", ".join(cc)
    msg["Subject"] = subject
    msg.set_content(body_text)
    msg.add_alternative(body_html, subtype="html")
    for name, path in attachments:
        ctype, _ = mimetypes.guess_type(name)
        maintype, subtype = (ctype or "application/octet-stream").split("/", 1)
        msg.add_attachment(Path(path).read_bytes(), maintype=maintype, subtype=subtype, filename=name)
    ctx = ssl.create_default_context()
    if settings.smtp_port == 465:
        with smtplib.SMTP_SSL(settings.smtp_host, settings.smtp_port, context=ctx, timeout=30) as s:
            if settings.smtp_user:
                s.login(settings.smtp_user, settings.smtp_password)
            s.send_message(msg)
    else:
        with smtplib.SMTP(settings.smtp_host, settings.smtp_port, timeout=30) as s:
            if settings.smtp_starttls:
                s.starttls(context=ctx)
            if settings.smtp_user:
                s.login(settings.smtp_user, settings.smtp_password)
            s.send_message(msg)
