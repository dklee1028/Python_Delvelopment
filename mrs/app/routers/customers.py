"""고객 / 전산담당자 / 장비 관리 + 기존 고객DB 연결."""
from __future__ import annotations

from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel
from sqlalchemy import func, or_
from sqlalchemy.orm import Session

from .. import legacy
from ..auth import audit, require
from ..db import get_db
from ..models import Customer, CustomerContact, Equipment, LogFile, Report, ReportEquipment, User

router = APIRouter(prefix="/api", tags=["customers"])


def contact_out(c: CustomerContact) -> dict:
    return {k: getattr(c, k) for k in ("id", "name", "department", "position", "email", "phone", "receive_reports", "is_primary")}


def eq_out(e: Equipment) -> dict:
    return {k: getattr(e, k) for k in ("id", "customer_id", "name", "vendor", "model", "serial", "os_version", "ha_type",
                                        "disk_info", "location", "contract_type", "memo", "is_active")}


def customer_out(c: Customer, db: Session, detail=False) -> dict:
    d = {k: getattr(c, k) for k in ("id", "legacy_customer_id", "name", "address", "site", "memo")}
    d["linked"] = bool(c.legacy_customer_id)
    d["equipment_count"] = len(c.equipment)
    d["contact_count"] = len(c.contacts)
    d["report_count"] = db.query(func.count(Report.id)).filter(Report.customer_id == c.id).scalar()
    if detail:
        d["contacts"] = [contact_out(x) for x in c.contacts]
        d["equipment"] = [eq_out(x) for x in c.equipment]
    return d


# ───────── 기존 고객 DB ─────────
@router.get("/legacy/customers")
def legacy_search(q: str = "", db: Session = Depends(get_db), _: User = Depends(require("customer.view"))):
    rows = legacy.search(q)
    linked = {c.legacy_customer_id: c.id for c in db.query(Customer).filter(Customer.legacy_customer_id.isnot(None))}
    for r in rows:
        r["mrs_customer_id"] = linked.get(r["id"])
    return {"enabled": legacy.enabled(), "items": rows}


@router.post("/legacy/customers/{legacy_id}/link")
def legacy_link(legacy_id: str, db: Session = Depends(get_db), me: User = Depends(require("customer.edit"))):
    exist = db.query(Customer).filter(Customer.legacy_customer_id == legacy_id).first()
    src = legacy.get(legacy_id)
    if not src:
        raise HTTPException(404, "기존 고객 DB에서 찾을 수 없습니다.")
    if exist:
        exist.name, exist.address = src["name"], src["address"]
        db.commit()
        return customer_out(exist, db)
    c = Customer(legacy_customer_id=legacy_id, name=src["name"], address=src["address"])
    db.add(c)
    audit(db, me, "customer.link", src["name"])
    db.commit()
    return customer_out(c, db)


@router.post("/legacy/sync")
def legacy_sync(db: Session = Depends(get_db), me: User = Depends(require("customer.edit"))):
    """연결된 고객의 고객명/주소를 기존 DB 기준으로 갱신."""
    n = 0
    for c in db.query(Customer).filter(Customer.legacy_customer_id.isnot(None)):
        src = legacy.get(c.legacy_customer_id)
        if src and (src["name"] != c.name or src["address"] != c.address):
            c.name, c.address = src["name"], src["address"]
            n += 1
    audit(db, me, "customer.sync", detail=f"{n}건 갱신")
    db.commit()
    return {"updated": n}


# ───────── 고객 ─────────
class CustomerIn(BaseModel):
    name: str
    address: str = ""
    site: str = ""
    memo: str = ""
    legacy_customer_id: str | None = None


@router.get("/customers")
def list_customers(q: str = "", db: Session = Depends(get_db), _: User = Depends(require("customer.view"))):
    qs = db.query(Customer)
    if q:
        qs = qs.filter(or_(Customer.name.like(f"%{q}%"), Customer.address.like(f"%{q}%")))
    return [customer_out(c, db) for c in qs.order_by(Customer.name)]


@router.get("/customers/{cid}")
def get_customer(cid: int, db: Session = Depends(get_db), _: User = Depends(require("customer.view"))):
    c = db.get(Customer, cid)
    if not c:
        raise HTTPException(404, "고객 없음")
    return customer_out(c, db, detail=True)


@router.post("/customers")
def create_customer(body: CustomerIn, db: Session = Depends(get_db), me: User = Depends(require("customer.edit"))):
    c = Customer(**body.model_dump())
    if c.legacy_customer_id:
        src = legacy.get(c.legacy_customer_id)
        if src:
            c.name, c.address = src["name"], src["address"]
    db.add(c)
    audit(db, me, "customer.create", c.name)
    db.commit()
    return customer_out(c, db, True)


@router.put("/customers/{cid}")
def update_customer(cid: int, body: CustomerIn, db: Session = Depends(get_db), me: User = Depends(require("customer.edit"))):
    c = db.get(Customer, cid)
    if not c:
        raise HTTPException(404, "고객 없음")
    for k, v in body.model_dump().items():
        setattr(c, k, v)
    audit(db, me, "customer.update", c.name)
    db.commit()
    return customer_out(c, db, True)


@router.delete("/customers/{cid}")
def delete_customer(cid: int, db: Session = Depends(get_db), me: User = Depends(require("customer.edit"))):
    c = db.get(Customer, cid)
    if not c:
        raise HTTPException(404, "고객 없음")
    if db.query(Report).filter(Report.customer_id == cid).count():
        raise HTTPException(400, "보고서가 있는 고객은 삭제할 수 없습니다. 보고서를 먼저 삭제하세요.")
    audit(db, me, "customer.delete", c.name)
    db.delete(c)
    db.commit()
    return {"ok": True}


# ───────── 전산담당자 ─────────
class ContactIn(BaseModel):
    name: str
    department: str = ""
    position: str = ""
    email: str = ""
    phone: str = ""
    receive_reports: bool = True
    is_primary: bool = False


@router.post("/customers/{cid}/contacts")
def add_contact(cid: int, body: ContactIn, db: Session = Depends(get_db), me: User = Depends(require("customer.edit"))):
    c = db.get(Customer, cid)
    if not c:
        raise HTTPException(404, "고객 없음")
    if body.is_primary:
        for x in c.contacts:
            x.is_primary = False
    ct = CustomerContact(customer_id=cid, **body.model_dump())
    db.add(ct)
    audit(db, me, "contact.create", f"{c.name}/{body.name}")
    db.commit()
    return contact_out(ct)


@router.put("/contacts/{ctid}")
def update_contact(ctid: int, body: ContactIn, db: Session = Depends(get_db), me: User = Depends(require("customer.edit"))):
    ct = db.get(CustomerContact, ctid)
    if not ct:
        raise HTTPException(404, "담당자 없음")
    if body.is_primary:
        for x in ct.customer.contacts:
            x.is_primary = False
    for k, v in body.model_dump().items():
        setattr(ct, k, v)
    audit(db, me, "contact.update", body.name)
    db.commit()
    return contact_out(ct)


@router.delete("/contacts/{ctid}")
def delete_contact(ctid: int, db: Session = Depends(get_db), me: User = Depends(require("customer.edit"))):
    ct = db.get(CustomerContact, ctid)
    if not ct:
        raise HTTPException(404, "담당자 없음")
    audit(db, me, "contact.delete", ct.name)
    db.delete(ct)
    db.commit()
    return {"ok": True}


# ───────── 장비 ─────────
class EquipmentIn(BaseModel):
    name: str
    vendor: str = "NetApp"
    model: str = ""
    serial: str = ""
    os_version: str = ""
    ha_type: str = ""
    disk_info: str = ""
    location: str = ""
    contract_type: str = ""
    memo: str = ""
    is_active: bool = True


@router.post("/customers/{cid}/equipment")
def add_equipment(cid: int, body: EquipmentIn, db: Session = Depends(get_db), me: User = Depends(require("equipment.edit"))):
    if not db.get(Customer, cid):
        raise HTTPException(404, "고객 없음")
    e = Equipment(customer_id=cid, **body.model_dump())
    db.add(e)
    audit(db, me, "equipment.create", body.name)
    db.commit()
    return eq_out(e)


@router.put("/equipment/{eid}")
def update_equipment(eid: int, body: EquipmentIn, db: Session = Depends(get_db), me: User = Depends(require("equipment.edit"))):
    e = db.get(Equipment, eid)
    if not e:
        raise HTTPException(404, "장비 없음")
    for k, v in body.model_dump().items():
        setattr(e, k, v)
    audit(db, me, "equipment.update", e.name)
    db.commit()
    return eq_out(e)


@router.delete("/equipment/{eid}")
def delete_equipment(eid: int, db: Session = Depends(get_db), me: User = Depends(require("equipment.edit"))):
    e = db.get(Equipment, eid)
    if not e:
        raise HTTPException(404, "장비 없음")
    if (db.query(LogFile).filter(LogFile.equipment_id == eid).count()
            or db.query(ReportEquipment).filter(ReportEquipment.equipment_id == eid).count()):
        e.is_active = False  # 로그·보고서 이력 보존을 위해 비활성화만
        db.commit()
        return {"ok": True, "deactivated": True}
    audit(db, me, "equipment.delete", e.name)
    db.delete(e)
    db.commit()
    return {"ok": True}
