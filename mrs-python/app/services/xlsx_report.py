"""보고서 엑셀 버전 생성 (PPT와 동일한 내용 + 원본 수치 전체)."""
from __future__ import annotations

from openpyxl import Workbook
from openpyxl.chart import BarChart, Reference
from openpyxl.drawing.image import Image as XLImage
from openpyxl.formatting.rule import CellIsRule, DataBarRule
from openpyxl.styles import Alignment, Border, Font, PatternFill, Side
from openpyxl.utils import get_column_letter

from . import pptx_kit as K
from .analyzer import vol_remark
from .catalog import INSPECTION_MATRIX, VISUAL_ITEMS

FONT = "맑은 고딕"
NAVY, BLUE, LIGHT, LINE = "0B2E4F", "1969A5", "EEF4FA", "D5DEE8"
SEV = {"critical": "D64545", "warning": "E09A2B", "normal": "2E9E6A", "info": "1969A5"}
thin = Side(style="thin", color=LINE)
BORDER = Border(left=thin, right=thin, top=thin, bottom=thin)
WRAP = Alignment(wrap_text=True, vertical="center")
CENTER = Alignment(horizontal="center", vertical="center", wrap_text=True)


def _f(size=10, bold=False, color="1F2937"):
    return Font(name=FONT, size=size, bold=bold, color=color)


def _title(ws, title, sub, width_cols=8):
    ws.sheet_view.showGridLines = False
    ws.merge_cells(start_row=1, start_column=1, end_row=1, end_column=width_cols)
    ws.merge_cells(start_row=2, start_column=1, end_row=2, end_column=width_cols)
    for r in (1, 2):
        for c in range(1, width_cols + 1):
            ws.cell(r, c).fill = PatternFill("solid", fgColor=NAVY)
    ws["A1"] = title
    ws["A1"].font = _f(18, True, "FFFFFF")
    ws["A1"].alignment = Alignment(vertical="center", indent=1)
    ws["A2"] = sub
    ws["A2"].font = _f(9, False, "B9D3EA")
    ws["A2"].alignment = Alignment(vertical="center", indent=1)
    ws.row_dimensions[1].height = 36
    ws.row_dimensions[2].height = 20
    for c in range(1, width_cols + 1):
        ws.cell(3, c).fill = PatternFill("solid", fgColor=BLUE)
    ws.row_dimensions[3].height = 4
    return 5


def _section(ws, row, text, width_cols=8):
    ws.cell(row, 1, "▌ " + text).font = _f(12, True, NAVY)
    ws.row_dimensions[row].height = 22
    return row + 1


def _table(ws, row, rows, header=True, label_cols=(), status_col=None, widths=None, start_col=1):
    for i, r in enumerate(rows):
        for j, v in enumerate(r):
            c = ws.cell(row + i, start_col + j, v)
            c.border = BORDER
            c.alignment = CENTER if (header and i == 0) or not isinstance(v, str) or len(str(v)) < 25 else WRAP
            if header and i == 0:
                c.fill = PatternFill("solid", fgColor=NAVY)
                c.font = _f(9.5, True, "FFFFFF")
            elif j in label_cols:
                c.fill = PatternFill("solid", fgColor=LIGHT)
                c.font = _f(9.5, True, NAVY)
            else:
                c.font = _f(9.5)
            if status_col is not None and j == status_col and i > 0:
                k = str(v).lower()
                for key, col in (("critical", SEV["critical"]), ("check", SEV["critical"]), ("비정상", SEV["critical"]),
                                 ("warning", SEV["warning"]), ("monitor", SEV["warning"]),
                                 ("normal", SEV["normal"]), ("정상", SEV["normal"])):
                    if key in k:
                        c.font = _f(9.5, True, col)
                        break
    return row + len(rows) + 1


def _widths(ws, widths):
    for i, w in enumerate(widths, 1):
        ws.column_dimensions[get_column_letter(i)].width = w


def _logo(ws, anchor):
    if K.LOGO_COLOR.exists():
        try:
            img = XLImage(str(K.LOGO_COLOR))
            ratio = 26 / img.height
            img.height, img.width = 26, int(img.width * ratio)
            ws.add_image(img, anchor)
        except Exception:
            pass


def _summary(wb, ctx):
    ws = wb.active
    ws.title = "요약"
    r = _title(ws, ctx["report"]["title"], f"{ctx['report']['type_label']} · No. {ctx['report']['no']} · {ctx['report']['date']}")
    _widths(ws, [16, 20, 18, 24, 14, 14, 12, 12])
    c = ctx["customer"]
    r = _section(ws, r, "고객 정보 (Customer Info)")
    r = _table(ws, r, [
        ["Customer", c["name"], "Date", ctx["report"]["date"]],
        ["Location", c.get("site") or c.get("address", ""), "Contact", " / ".join(x for x in (c.get("contact_name"), c.get("contact_phone"), c.get("contact_email")) if x)],
        ["판정", ctx["report"]["overall_label"], "보고서 유형", ctx["report"]["type_label"] + (f" · {ctx['report']['subtype_label']}" if ctx["report"].get("subtype_label") else "")],
    ], header=False, label_cols=(0, 2), status_col=1)
    r = _section(ws, r, "점검 장비 정보 (Equipment Info)")
    start = r
    rows = [["No.", "System", "Model", "System S/N", "OS Ver", "Disk", "Status", "Health"]]
    for e in ctx["equipments"]:
        rows.append([e["seq"], e["name"], e["model"], e["serial"], e["os_version"], e["disk_info"],
                     K.STATUS_TEXT[e["analysis"]["status"]], e["analysis"]["score"]])
    r = _table(ws, r, rows, status_col=6)
    ws.conditional_formatting.add(f"H{start + 1}:H{start + len(rows) - 1}",
                                  DataBarRule(start_type="num", start_value=0, end_type="num", end_value=100, color="1969A5"))
    if len(ctx["equipments"]) >= 2:
        r = _section(ws, r, "장비별 Data 용량 사용률 / Health Score")
        crow = r
        ws.cell(crow, 1, "System").font = _f(9, True)
        ws.cell(crow, 2, "사용률(%)").font = _f(9, True)
        ws.cell(crow, 3, "Health").font = _f(9, True)
        for i, e in enumerate(ctx["equipments"], 1):
            ws.cell(crow + i, 1, f"#{e['seq']} {e['model']}")
            ws.cell(crow + i, 2, e["analysis"]["capacity"]["pct"])
            ws.cell(crow + i, 3, e["analysis"]["score"])
        ch = BarChart()
        ch.type = "bar"
        ch.style = 10
        ch.height, ch.width = 6 + 0.4 * len(ctx["equipments"]), 18
        ch.y_axis.scaling.min, ch.y_axis.scaling.max = 0, 100
        data = Reference(ws, min_col=2, max_col=3, min_row=crow, max_row=crow + len(ctx["equipments"]))
        cats = Reference(ws, min_col=1, min_row=crow + 1, max_row=crow + len(ctx["equipments"]))
        ch.add_data(data, titles_from_data=True)
        ch.set_categories(cats)
        ws.add_chart(ch, f"D{crow}")
        r = crow + max(len(ctx["equipments"]) + 2, 14)
    r = _section(ws, r, "장애 및 진단 의견 (엔지니어 검토 완료)")
    ws.merge_cells(start_row=r, start_column=1, end_row=r, end_column=8)
    cell = ws.cell(r, 1, ctx["diagnosis"])
    cell.alignment = Alignment(wrap_text=True, vertical="top")
    cell.font = _f(10)
    ws.row_dimensions[r].height = min(409, 15 * (ctx["diagnosis"].count("\n") + 2 + len(ctx["diagnosis"]) // 90))
    r += 2
    r = _section(ws, r, "엔지니어 최종 Comment")
    ws.merge_cells(start_row=r, start_column=1, end_row=r, end_column=8)
    cell = ws.cell(r, 1, ctx["final_comment"])
    cell.alignment = Alignment(wrap_text=True, vertical="top")
    cell.font = _f(10)
    cell.fill = PatternFill("solid", fgColor=LIGHT)
    ws.row_dimensions[r].height = min(409, 15 * (ctx["final_comment"].count("\n") + 2 + len(ctx["final_comment"]) // 90))
    r += 2
    eng = ctx["engineer"]
    r = _table(ws, r, [["회사명", ctx["company"]["name"], "연락처", eng.get("phone") or ctx["company"].get("tel", "")],
                       ["점검자", f"{eng.get('name', '')} {eng.get('title', '')} (서명)", "고객확인", "(서명)"]],
               header=False, label_cols=(0, 2))
    _logo(ws, "G1")
    ws.print_options.horizontalCentered = True
    ws.page_setup.orientation = "portrait"
    ws.page_setup.fitToWidth = 1
    ws.sheet_properties.pageSetUpPr.fitToPage = True
    ws.page_setup.fitToHeight = 0


def _system_sheet(wb, ctx, e):
    ws = wb.create_sheet(f"System {e['seq']}"[:31])
    r = _title(ws, f"System {e['seq']} · {e['vendor']} {e['model']}", f"S/N {e['serial']} · OS {e['os_version']} · {e['analysis']['status_label']} · Health {e['analysis']['score']}")
    _widths(ws, [16, 22, 14, 14, 11, 11, 14, 16])
    r = _section(ws, r, "Aggregate 현황")
    a0 = r
    rows = [["Node", "Aggr Name", "Total(GB)", "Used(GB)", "Used(%)", "Avail(GB)", "State"]]
    for a in e["parsed"].get("aggregates", []):
        rows.append([a.get("node", ""), a["name"], round(a["total_gb"], 1), round(a["used_gb"], 1), a["pct"],
                     round(a["avail_gb"], 1), a.get("state", "")])
    r = _table(ws, r, rows)
    if len(rows) > 1:
        rng = f"E{a0 + 1}:E{a0 + len(rows) - 1}"
        ws.conditional_formatting.add(rng, DataBarRule(start_type="num", start_value=0, end_type="num", end_value=100, color="1969A5"))
        ws.conditional_formatting.add(rng, CellIsRule(operator="greaterThanOrEqual", formula=["95"], font=Font(color=SEV["critical"], bold=True)))
    r = _section(ws, r, "Volume 현황 (전체)")
    v0 = r
    rows = [["Node/SVM", "Volume", "Total(GB)", "Used(GB)", "Used(%)", "Inode(%)", "Remarks", "Aggregate"]]
    for v in e["parsed"].get("volumes", []):
        rows.append([v.get("node", ""), v["name"], round(v["total_gb"], 1), round(v["used_gb"], 1), v["pct"],
                     v.get("inode_pct") if v.get("inode_pct") is not None else "-", vol_remark(v["pct"]), v.get("aggr", "")])
    r = _table(ws, r, rows, status_col=6)
    if len(rows) > 1:
        ws.conditional_formatting.add(f"E{v0 + 1}:E{v0 + len(rows) - 1}",
                                      DataBarRule(start_type="num", start_value=0, end_type="num", end_value=100, color="1969A5"))
    r = _section(ws, r, "로그 분석 진단 (Diagnostic Findings)")
    rows = [["등급", "구분", "진단", "상세", "권고 조치"]]
    for f in e["analysis"]["findings"]:
        rows.append([K.STATUS_TEXT[f["severity"]], f["category"], f["title"], f["detail"], f["recommendation"]])
    if len(rows) == 1:
        rows.append(["Normal", "-", "주요 이상 징후 없음", "", "정기 모니터링 유지"])
    r = _table(ws, r, rows, status_col=0)
    ev = e["parsed"].get("events", {})
    if ev.get("top"):
        r = _section(ws, r, "주요 이벤트 (Top Events)")
        r = _table(ws, r, [["Severity", "Event", "Count"]] + [[x["severity"].upper(), x["event"], x["count"]] for x in ev["top"]], status_col=0)
    files = e["parsed"].get("files") or []
    if files:
        ws.cell(r, 1, "분석 로그: " + ", ".join(files)).font = _f(8, False, "6B7785")


def _visual_sheet(wb, ctx):
    ws = wb.create_sheet("육안점검")
    eqs = ctx["equipments"]
    r = _title(ws, "주요 점검 항목 – 육안 점검", "엔지니어 최종 선택 결과", 2 + len(eqs))
    _widths(ws, [28] + [14] * len(eqs) + [30])
    rows = [["Inspection Item"] + [f"#{e['seq']} {e['model']}" for e in eqs]]
    for key, label in VISUAL_ITEMS:
        rows.append([label] + [e["visual"].get(key, "-") for e in eqs])
    r = _table(ws, r, rows, label_cols=(0,))
    for row in ws.iter_rows(min_row=6, max_row=r, min_col=2, max_col=1 + len(eqs)):
        for c in row:
            if c.value == "비정상":
                c.font = _f(9.5, True, SEV["critical"])
            elif c.value == "정상":
                c.font = _f(9.5, True, SEV["normal"])
    r = _section(ws, r, "로그 기반 자동 점검 매트릭스")
    rows = [["Category", "Inspection Item"] + [f"#{e['seq']}" for e in eqs] + ["Standard / Criteria"]]
    for cat, item, crit in INSPECTION_MATRIX:
        rows.append([cat, item] + [e["checks"].get(item, "-") for e in eqs] + [crit])
    _table(ws, r, rows, label_cols=(0,))


def _workplan_sheet(wb, ctx):
    ws = wb.create_sheet("세부작업계획", 1)
    plan = ctx["plan"]
    r = _title(ws, ctx["plan_title"], f"{ctx['customer']['name']} · {ctx['report']['subtype_label']} · {plan.get('schedule', '')}", 7)
    _widths(ws, [6, 20, 24, 60, 18, 10, 22])
    r = _table(ws, r, [
        ["고객사", ctx["customer"]["name"], "", "작업 장소", plan.get("location", ""), "", ""],
        ["작업 일정", plan.get("schedule", ""), "", "작업 목적", plan.get("purpose", ""), "", ""],
        ["대상 장비", ", ".join(f"{e['model']}({e['name']})" for e in ctx["equipments"]), "", "목표 버전/부품", plan.get("target", ""), "", ""],
    ], header=False, label_cols=(0, 3))
    total = sum(int(s.get("minutes") or 0) for s in plan.get("steps", []))
    rows = [["No", "Type(단계)", "Activity", "상세 작업 내용", "Who", "Time(분)", "서비스 영향/비고"]]
    eng = ctx["engineer"]
    who = f"{ctx['company']['name']}\n{eng.get('name', '')} {eng.get('title', '')}"
    for i, s in enumerate(plan.get("steps", []), 1):
        rows.append([i, s.get("phase", ""), s.get("activity", ""), s.get("detail", ""), s.get("who") or who,
                     int(s.get("minutes") or 0), s.get("impact", "")])
    rows.append(["", "", "", "총 소요시간", "", total, ""])
    r = _table(ws, r, rows)
    r = _section(ws, r, "롤백 계획")
    _table(ws, r, [["롤백 기준", plan.get("rollback_criteria") or "작업 단계별 정상 확인 실패, 서비스 영향 허용 시간 초과 시"],
                   ["롤백 절차", plan.get("rollback", "")], ["의사 결정", plan.get("decision_maker") or "고객사 담당자와 협의 후 결정"]],
           header=False, label_cols=(0,))
    ws.merge_cells(start_row=r, start_column=2, end_row=r, end_column=7)
    ws.merge_cells(start_row=r + 1, start_column=2, end_row=r + 1, end_column=7)
    ws.merge_cells(start_row=r + 2, start_column=2, end_row=r + 2, end_column=7)


def build(ctx: dict, path: str) -> None:
    wb = Workbook()
    _summary(wb, ctx)
    if ctx["report"]["type"] == "workplan":
        _workplan_sheet(wb, ctx)
    for e in ctx["equipments"]:
        _system_sheet(wb, ctx, e)
    _visual_sheet(wb, ctx)
    wb.properties.creator = "MRS (Management Report System)"
    wb.properties.title = ctx["report"]["title"]
    wb.save(path)
