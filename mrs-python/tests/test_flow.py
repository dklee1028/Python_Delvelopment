from pathlib import Path

from pptx import Presentation
from openpyxl import load_workbook

SAMPLES = Path(__file__).resolve().parent.parent / "sample_logs"
API = "/mms/api"


def _customer(client, name, n_eq):
    c = client.post(f"{API}/customers", json={"name": name, "address": "서울시", "site": "전산실(IDC)"}).json()
    client.post(f"{API}/customers/{c['id']}/contacts",
                json={"name": "홍길동", "email": "it@example.com", "phone": "010-0000-0000", "is_primary": True})
    eq_ids, log_ids = [], []
    for i in range(n_eq):
        sample = ["fas2240_7mode.txt", "aff_a300_cdot.txt"][i % 2]
        e = client.post(f"{API}/customers/{c['id']}/equipment",
                        json={"name": f"NAS-{i + 1:02d}", "model": "", "disk_info": "SAS 24EA"}).json()
        eq_ids.append(e["id"])
        with open(SAMPLES / sample, "rb") as f:
            r = client.post(f"{API}/customers/{c['id']}/logs", data={"equipment_id": str(e["id"])},
                            files=[("files", (sample, f, "text/plain"))])
        assert r.status_code == 200, r.text
        log_ids += [x["id"] for x in r.json()]
    return c, eq_ids, log_ids


def _complete(client, rid, eq_ids, catalog):
    r = client.post(f"{API}/reports/{rid}/ai-advice", json={"notes": ""})
    assert r.status_code == 200 and r.json()["ai_advice"]
    # AI 조언 없이/검토 없이는 생성 불가
    assert client.post(f"{API}/reports/{rid}/generate").status_code == 400
    r = client.put(f"{API}/reports/{rid}/diagnosis", json={"diagnosis": r.json()["diagnosis"] + "\n(엔지니어 검토)", "reviewed": True})
    assert r.status_code == 200
    r = client.post(f"{API}/reports/{rid}/generate")
    assert r.status_code == 200, r.text
    assert r.json()["status"] == "review"
    # 육안점검/최종 Comment 누락 시 완료 불가
    bad = client.put(f"{API}/reports/{rid}/finalize", json={"visual_inspection": {}, "final_comment": ""})
    assert bad.status_code == 400
    vis = {str(e): {k: "정상" for k, _ in catalog["visual_items"]} for e in eq_ids}
    vis[str(eq_ids[0])]["hdd"] = "비정상"
    r = client.put(f"{API}/reports/{rid}/finalize", json={"visual_inspection": vis, "final_comment": "점검 완료. 디스크 교체 권고."})
    assert r.status_code == 200, r.text
    d = r.json()
    assert d["status"] == "completed"
    fmts = {f["format"] for f in d["files"]}
    assert fmts == {"pptx", "xlsx"}
    for f in d["files"]:
        resp = client.get(f"{API}/reports/{rid}/files/{f['id']}")
        assert resp.status_code == 200 and len(resp.content) > 5000
    return d


def test_three_variants(client, tmp_path):
    catalog = client.get(f"{API}/catalog").json()
    expect = {1: ("single", 2), 3: ("multi", 5), 5: ("fleet", 5)}
    for n, (variant, min_pages) in expect.items():
        c, eq_ids, log_ids = _customer(client, f"고객{n}", n)
        r = client.post(f"{API}/reports", json={
            "customer_id": c["id"], "report_type": "regular", "equipment_ids": eq_ids, "log_ids": log_ids,
            "inspection_date": "2026-11-27", "service_codes": ["PM"]})
        assert r.status_code == 200, r.text
        rep = r.json()
        assert rep["variant"] == variant
        assert rep["overall_status"] == "critical"
        d = _complete(client, rep["id"], eq_ids, catalog)
        ppt = next(f for f in d["files"] if f["format"] == "pptx")
        out = tmp_path / f"{variant}.pptx"
        out.write_bytes(client.get(f"{API}/reports/{rep['id']}/files/{ppt['id']}").content)
        assert len(Presentation(out).slides) >= min_pages
        xl = next(f for f in d["files"] if f["format"] == "xlsx")
        outx = tmp_path / f"{variant}.xlsx"
        outx.write_bytes(client.get(f"{API}/reports/{rep['id']}/files/{xl['id']}").content)
        wb = load_workbook(outx)
        assert "요약" in wb.sheetnames and "육안점검" in wb.sheetnames


def test_workplan_and_custom_template(client, tmp_path):
    from pptx import Presentation as P
    from pptx.util import Cm

    catalog = client.get(f"{API}/catalog").json()
    c, eq_ids, log_ids = _customer(client, "작업고객", 2)
    # 고객 양식 등록
    prs = P()
    s = prs.slides.add_slide(prs.slide_layouts[6])
    tb = s.shapes.add_textbox(Cm(1), Cm(1), Cm(15), Cm(5))
    tb.text_frame.text = "{{customer_name}} / {{report_no}} / {{eq1_model}} / {{final_comment}}"
    tpl = tmp_path / "tpl.pptx"
    prs.save(tpl)
    with open(tpl, "rb") as f:
        t = client.post(f"{API}/templates", data={"name": "고객양식", "report_type": "workplan", "customer_id": str(c["id"])},
                        files={"file": ("tpl.pptx", f)}).json()
    assert "customer_name" in t["placeholders"]
    r = client.post(f"{API}/reports", json={
        "customer_id": c["id"], "report_type": "workplan", "work_subtype": "os_upgrade", "template_id": t["id"],
        "equipment_ids": eq_ids, "log_ids": log_ids, "plan": {"schedule": "2026-12-05 22:00", "purpose": "EOS 대응", "target": "ONTAP 9.14.1"}})
    assert r.status_code == 200, r.text
    rep = r.json()
    assert len(rep["plan"]["steps"]) > 5
    d = _complete(client, rep["id"], eq_ids, catalog)
    kinds = {(f["kind"], f["format"]) for f in d["files"]}
    assert ("custom", "pptx") in kinds
    custom = next(f for f in d["files"] if f["kind"] == "custom")
    out = tmp_path / "custom.pptx"
    out.write_bytes(client.get(f"{API}/reports/{rep['id']}/files/{custom['id']}").content)
    txt = P(out).slides[0].shapes[0].text_frame.text
    assert "작업고객" in txt and rep["report_no"] in txt and "{{" not in txt
    # 메일: SMTP 미설정이면 실패 기록 후 502
    m = client.post(f"{API}/reports/{rep['id']}/send", json={"to": ["it@example.com"]})
    assert m.status_code == 502
    # 수정 → 상태가 review 로 돌아감, 삭제
    body = {"customer_id": c["id"], "report_type": "workplan", "work_subtype": "os_upgrade", "equipment_ids": eq_ids[:1],
            "log_ids": log_ids, "plan": rep["plan"], "template_id": t["id"]}
    u = client.put(f"{API}/reports/{rep['id']}", json=body).json()
    assert u["status"] == "review" and u["variant"] == "single"
    assert client.delete(f"{API}/reports/{rep['id']}").status_code == 200


def test_permissions(client):
    r = client.post(f"{API}/users", json={"username": "viewer1", "password": "viewer1234", "role": "viewer"})
    assert r.status_code == 200
    from fastapi.testclient import TestClient
    from app.main import app

    with TestClient(app) as v:
        assert v.post(f"{API}/auth/login", json={"username": "viewer1", "password": "viewer1234"}).status_code == 200
        assert v.get(f"{API}/reports").status_code == 200
        assert v.post(f"{API}/customers", json={"name": "x"}).status_code == 403
        assert v.get(f"{API}/users").status_code == 200
        assert v.post(f"{API}/users", json={"username": "x", "password": "12345678"}).status_code == 403


def test_pwa_and_paths(client):
    assert client.get("/mms/").status_code == 200
    m = client.get("/mms/manifest.webmanifest").json()
    assert m["short_name"] == "MRS" and m["start_url"] == "/mms/"
    assert client.get("/mms/sw.js").status_code == 200
    assert client.get("/mms/static/icons/mrs-512.png").status_code == 200
