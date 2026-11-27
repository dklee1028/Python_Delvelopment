"""보고서 PPT 생성.

점검보고서(정기/특별): A4 세로, 장비 수에 따라 3가지 레이아웃
  - single (1대)   : 1대 상세형 – 건강점수 게이지, 용량 차트, 볼륨/진단/육안점검을 2페이지로
  - multi  (2~3대) : 개요 1p + 장비별 상세 1p씩 + 종합의견 1p
  - fleet  (4대+)  : 전체 대시보드(현황 히트맵·용량 비교) + 장비 2대/페이지 요약 + 점검 매트릭스·종합의견
작업계획서: 16:9 가로, 표지/목차/개요/대상장비/상세절차/영향·일정/리스크/롤백/사전점검·의견
"""
from __future__ import annotations

from pptx import Presentation
from pptx.enum.text import MSO_ANCHOR, PP_ALIGN
from pptx.util import Cm, Emu

from . import pptx_kit as K
from .analyzer import vol_remark
from .catalog import INSPECTION_MATRIX, VISUAL_ITEMS
from .log_parser import human

A4_W, A4_H = Emu(7560310), Emu(10692130)
WIDE_W, WIDE_H = Emu(12192000), Emu(6858000)
M = Cm(1.0)


# ───────────────────────── 공통 블록 ─────────────────────────
def _new(prs):
    return prs.slides.add_slide(prs.slide_layouts[6])


def _header(slide, ctx, title_ko, title_en, sw):
    K.rect(slide, 0, 0, sw, Cm(2.6), K.NAVY)
    K.rect(slide, 0, Cm(2.6), sw, Cm(0.12), K.BLUE)
    K.text(slide, M, Cm(0.45), sw - Cm(8), Cm(1.0), title_ko, 20, True, K.WHITE)
    K.text(slide, M, Cm(1.45), sw - Cm(8), Cm(0.7), title_en, 10, False, K.RGBColor(0xB9, 0xD3, 0xEA))
    # 로고 박스 (흰 배경)
    K.rect(slide, sw - Cm(6.2), Cm(0.55), Cm(5.2), Cm(1.5), K.WHITE, shape=K.MSO_SHAPE.ROUNDED_RECTANGLE, radius=0.12)
    K.logo(slide, sw - Cm(5.95), Cm(0.82), Cm(0.95))
    st = ctx["report"]["overall_status"] or "normal"
    K.pill(slide, sw - Cm(6.2), Cm(2.05) - Cm(0.0), Cm(5.2), Cm(0.42),
           f"{ctx['report']['type_label']} · No. {ctx['report']['no']}", K.BLUE, size=7)
    return Cm(3.2), st


def _page_header(slide, ctx, title, sw):
    K.rect(slide, 0, 0, sw, Cm(1.5), K.NAVY)
    K.rect(slide, 0, Cm(1.5), sw, Cm(0.08), K.BLUE)
    K.text(slide, M, Cm(0.3), sw - Cm(8), Cm(0.9), title, 14, True, K.WHITE, anchor=MSO_ANCHOR.MIDDLE)
    K.text(slide, sw - Cm(8), Cm(0.4), Cm(7), Cm(0.7),
           f"{ctx['customer']['name']} · {ctx['report']['date']}", 8.5, False,
           K.RGBColor(0xB9, 0xD3, 0xEA), align=PP_ALIGN.RIGHT, anchor=MSO_ANCHOR.MIDDLE)
    return Cm(2.1)


def _customer_block(slide, ctx, y, sw):
    y = K.section(slide, M, y, sw - 2 * M, "고객 정보 (Customer Info)")
    c = ctx["customer"]
    rows = [
        ["Customer", c["name"], "Date", ctx["report"]["date"]],
        ["Location", K.clip(c.get("site") or c.get("address", ""), 40), "Contact",
         K.clip(" / ".join(x for x in (c.get("contact_name"), c.get("contact_phone")) if x), 32)],
    ]
    if ctx["report"].get("service_codes"):
        codes = "  ".join(("■ " if s in ctx["report"]["service_codes"] else "□ ") + s for s in ctx["service_code_list"])
        rows.append(["Service", codes, "", ""])
    y = K.table(slide, M, y, sw - 2 * M, rows, [1.3, 3.2, 1.0, 2.5], header=False, label_cols=(0, 2),
                align=[PP_ALIGN.CENTER, PP_ALIGN.LEFT, PP_ALIGN.CENTER, PP_ALIGN.LEFT], size=8.5)
    if ctx["report"].get("service_codes"):
        tbl = slide.shapes[-1].table
        K.merge(tbl, len(rows) - 1, 1, 3)
    return y + Cm(0.35)


def _equipment_list(slide, ctx, y, sw, compact=False):
    y = K.section(slide, M, y, sw - 2 * M, "점검 장비 정보 (Equipment Info)")
    rows = [["No.", "System", "Model", "System S/N", "OS Ver", "Disk", "Status"]]
    for e in ctx["equipments"]:
        rows.append([e["seq"], K.clip(e["name"], 16), K.clip(e["model"], 18), K.clip(e["serial"], 30),
                     K.clip(e["os_version"], 14), K.clip(e["disk_info"], 16), K.STATUS_TEXT[e["analysis"]["status"]]])
    return K.table(slide, M, y, sw - 2 * M, rows, [0.5, 1.5, 1.6, 2.6, 1.2, 1.4, 1.0], size=7.5 if compact else 8,
                   row_h=Cm(0.55 if compact else 0.6), status_col=6) + Cm(0.35)


def _aggr_rows(e, limit):
    rows = [["Node", "Aggr Name", "Total", "Used", "Used (%)", "Available"]]
    for a in e["parsed"].get("aggregates", [])[:limit]:
        rows.append([K.clip(a.get("node", ""), 14), K.clip(a["name"], 18), human(a["total_gb"]), human(a["used_gb"]),
                     f"{a['pct']}%", human(a["avail_gb"])])
    if len(rows) == 1:
        rows.append(["-", "로그 데이터 없음", "-", "-", "-", "-"])
    return rows


def _vol_rows(e, limit):
    rows = [["Node", "Volume", "Total", "Used", "Used (%)", "Inode (%)", "Remarks"]]
    for v in e["parsed"].get("volumes", [])[:limit]:
        rows.append([K.clip(v.get("node", ""), 12), K.clip(v["name"], 18), human(v["total_gb"]), human(v["used_gb"]),
                     f"{v['pct']}%", f"{v['inode_pct']}%" if v.get("inode_pct") is not None else "-",
                     vol_remark(v["pct"])])
    if len(rows) == 1:
        rows.append(["-", "로그 데이터 없음", "-", "-", "-", "-", "-"])
    return rows


def _findings_rows(e, limit):
    rows = [["등급", "구분", "진단 내용", "권고 조치"]]
    for f in e["analysis"]["findings"][:limit]:
        rows.append([K.STATUS_TEXT[f["severity"]], f["category"], K.clip(f["title"] + " - " + f["detail"], 60),
                     K.clip(f["recommendation"], 44)])
    if len(rows) == 1:
        rows.append(["Normal", "-", "주요 이상 징후가 발견되지 않았습니다.", "정기 모니터링 유지"])
    return rows


def _kpis(slide, e, x, y, w):
    a = e["analysis"]
    p = e["parsed"]
    gauge = Cm(3.0)
    K.donut(slide, x, y, gauge, a["score"], "Health Score", sub=a["status_label"])
    cx = x + gauge + Cm(0.4)
    cw = (w - gauge - Cm(0.4) - Cm(0.3) * 3) / 4
    cap = a["capacity"]
    vals = [
        (f"{cap['pct']}%" if cap["total_gb"] else "-", f"Data 용량 사용률\n{human(cap['used_gb'])} / {human(cap['total_gb'])}" if cap["total_gb"] else "Data 용량 사용률", K.RED if cap["pct"] >= 95 else K.AMBER if cap["pct"] >= 85 else K.BLUE),
        (str(a["counts"]["critical"]), "Critical 진단", K.RED if a["counts"]["critical"] else K.GREEN),
        (str(a["counts"]["warning"]), "Warning 진단", K.AMBER if a["counts"]["warning"] else K.GREEN),
        (str(len(p.get("disks", {}).get("failed", []))), f"Failed Disk\nSpare {p.get('disks', {}).get('spare', 0)}EA",
         K.RED if p.get("disks", {}).get("failed") else K.GREEN),
    ]
    for i, (v, lbl, col) in enumerate(vals):
        K.kpi(slide, cx + i * (cw + Cm(0.3)), y + Cm(0.2), cw, Cm(2.4), v, lbl, col, col)
    return y + gauge + Cm(0.6)


def _visual_single(slide, ctx, e, y, sw):
    y = K.section(slide, M, y, sw - 2 * M, "주요 점검 항목 – 육안 점검 (Main Inspection Items)")
    vis = e["visual"]
    rows = []
    for i in range(0, len(VISUAL_ITEMS), 2):
        r = []
        for key, label in VISUAL_ITEMS[i:i + 2]:
            v = vis.get(key, "")
            r += [label, ("■ 정상" if v == "정상" else "□ 정상") + "   " + ("■ 비정상" if v == "비정상" else "□ 비정상")
                  + ("   ■ N/A" if v == "N/A" else "")]
        rows.append(r + [""] * (4 - len(r)))
    return K.table(slide, M, y, sw - 2 * M, rows, [2.2, 2.0, 2.2, 2.0], header=False, label_cols=(0, 2), size=8,
                   row_h=Cm(0.55), status_col=None) + Cm(0.35)


def _visual_matrix(slide, ctx, y, sw):
    y = K.section(slide, M, y, sw - 2 * M, "주요 점검 항목 – 육안 점검 (Main Inspection Items)")
    eqs = ctx["equipments"]
    head = ["Inspection Item"] + [f"#{e['seq']}" for e in eqs]
    rows = [head]
    for key, label in VISUAL_ITEMS:
        row = [label]
        for e in eqs:
            v = e["visual"].get(key, "")
            row.append({"정상": "● 정상", "비정상": "▲ 비정상", "N/A": "N/A"}.get(v, "-"))
        rows.append(row)
    cw = [3.0] + [1.0] * len(eqs)
    return K.table(slide, M, y, sw - 2 * M, rows, cw, size=7.5, row_h=Cm(0.5), label_cols=(0,),
                   align=[PP_ALIGN.LEFT] + [PP_ALIGN.CENTER] * len(eqs)) + Cm(0.3)


def _check_matrix(slide, ctx, y, sw):
    """5대용 샘플의 카테고리형 점검 매트릭스(로그 기반 자동 판정)."""
    y = K.section(slide, M, y, sw - 2 * M, "로그 기반 자동 점검 매트릭스 (Automated Check Matrix)")
    eqs = ctx["equipments"]
    rows = [["Category", "Inspection Item"] + [f"#{e['seq']}" for e in eqs] + ["Standard / Criteria"]]
    last = ""
    for cat, item, crit in INSPECTION_MATRIX:
        row = [cat if cat != last else "", item]
        last = cat
        for e in eqs:
            row.append(e["checks"].get(item, "-"))
        rows.append(row + [crit])
    cw = [1.3, 2.2] + [0.6] * len(eqs) + [2.8]
    out = K.table(slide, M, y, sw - 2 * M, rows, cw, size=7, row_h=Cm(0.48), label_cols=(0,),
                  align=[PP_ALIGN.CENTER, PP_ALIGN.LEFT] + [PP_ALIGN.CENTER] * len(eqs) + [PP_ALIGN.LEFT])
    tbl = slide.shapes[-1].table
    for r in range(1, len(rows)):
        for c in range(2, 2 + len(eqs)):
            run = tbl.cell(r, c).text_frame.paragraphs[0].runs[0]
            run.font.color.rgb = {"✔": K.GREEN, "!": K.AMBER, "✖": K.RED}.get(run.text, K.GREY)
            run.font.bold = True
    return out + Cm(0.3)


def _opinion(slide, ctx, y, sw, bottom, title="엔지니어 진단 의견 (Engineer's Opinion)"):
    y = K.section(slide, M, y, sw - 2 * M, title)
    st = ctx["report"]["overall_status"] or "normal"
    chars = int((sw - 2 * M) / Cm(0.30))
    need_lines = sum(max(1, -(-len(ln) // chars)) for ln in (ctx["diagnosis"] or "").split("\n"))
    avail = max(Cm(2.0), bottom - y - Cm(4.3))
    box_h = min(avail, max(Cm(3.0), Cm(0.42) * need_lines + Cm(0.5)))
    K.rect(slide, M, y, sw - 2 * M, box_h, K.WHITE, line=K.LINE)
    K.rect(slide, M, y, Cm(0.15), box_h, K.STATUS_COLOR[st])
    lines = int((box_h - Cm(0.3)) / Cm(0.42))
    K.text(slide, M + Cm(0.35), y + Cm(0.15), sw - 2 * M - Cm(0.5), box_h - Cm(0.2),
           K.fit_lines(ctx["diagnosis"], lines, chars), 8.5, line_spacing=1.1)
    y += box_h + Cm(0.25)
    # 최종 Comment
    K.rect(slide, M, y, sw - 2 * M, Cm(1.6), K.LIGHT)
    K.text(slide, M + Cm(0.3), y + Cm(0.1), Cm(4), Cm(0.5), "엔지니어 최종 Comment", 8.5, True, K.BLUE)
    K.text(slide, M + Cm(0.3), y + Cm(0.55), sw - 2 * M - Cm(0.6), Cm(1.0),
           K.fit_lines(ctx["final_comment"], 2, chars), 8.5, False, K.INK)
    y += Cm(1.8)
    return _sign(slide, ctx, y, sw)


def _opinion_fit(prs, pages, slide, ctx, y, sw, sh, title):
    """남은 공간이 부족하면 새 페이지에 의견 블록을 배치."""
    if sh - Cm(1.3) - y < Cm(9.5):
        slide = _new(prs)
        pages.append(slide)
        y = _page_header(slide, ctx, "종합 의견 (Engineer's Opinion)", sw)
    _opinion(slide, ctx, y, sw, sh - Cm(1.3), title)


def _sign(slide, ctx, y, sw):
    eng = ctx["engineer"]
    rows = [
        ["회사명", ctx["company"]["name"], "연락처", eng.get("phone") or ctx["company"].get("tel", "")],
        ["점검자", f"{eng.get('name', '')} {eng.get('title', '')} (서명)", "고객확인", "(서명)"],
    ]
    return K.table(slide, M, y, sw - 2 * M, rows, [1.2, 3.0, 1.2, 2.6], header=False, label_cols=(0, 2), size=8.5,
                   row_h=Cm(0.65))


def _system_detail(slide, ctx, e, y, sw, vol_limit=10, aggr_limit=6, find_limit=5, chart=True):
    w = sw - 2 * M
    K.rect(slide, M, y, w, Cm(1.0), K.LIGHT)
    K.text(slide, M + Cm(0.3), y + Cm(0.12), w - Cm(4), Cm(0.8),
           f"System {e['seq']}  |  {e['vendor']} {e['model']}  (S/N: {K.clip(e['serial'], 40)})", 10, True, K.NAVY,
           anchor=MSO_ANCHOR.MIDDLE)
    st = e["analysis"]["status"]
    K.pill(slide, M + w - Cm(3.0), y + Cm(0.22), Cm(2.7), Cm(0.56), K.STATUS_TEXT[st], K.STATUS_COLOR[st])
    y += Cm(1.3)
    y = _kpis(slide, e, M, y, w)
    y = K.section(slide, M, y, w, "Aggregate 현황")
    aggs = e["parsed"].get("aggregates", [])[:aggr_limit]
    if chart and aggs:
        tw = w * 0.6
        bottom = K.table(slide, M, y, tw, _aggr_rows(e, aggr_limit), [1.3, 1.6, 1.0, 1.0, 0.9, 1.0], size=7.5,
                         row_h=Cm(0.55))
        ch_h = max(bottom - y, Cm(2.6))
        K.bar_chart(slide, M + tw + Cm(0.3), y - Cm(0.1), w - tw - Cm(0.3), ch_h,
                    [a["name"] for a in aggs], [a["pct"] for a in aggs])
        y = max(bottom, y + ch_h) + Cm(0.3)
    else:
        y = K.table(slide, M, y, w, _aggr_rows(e, aggr_limit), [1.3, 1.6, 1.0, 1.0, 0.9, 1.0], size=7.5,
                    row_h=Cm(0.55)) + Cm(0.3)
    y = K.section(slide, M, y, w, f"주요 볼륨 현황 (Top {vol_limit} Volumes)")
    y = K.table(slide, M, y, w, _vol_rows(e, vol_limit), [1.2, 1.8, 1.0, 1.0, 0.8, 0.8, 1.0], size=7.5,
                row_h=Cm(0.5), status_col=6) + Cm(0.3)
    y = K.section(slide, M, y, w, "로그 분석 진단 (Diagnostic Findings)")
    y = K.table(slide, M, y, w, _findings_rows(e, find_limit), [0.8, 0.9, 4.2, 2.8], size=7.5, row_h=Cm(0.62),
                status_col=0, align=[PP_ALIGN.CENTER, PP_ALIGN.CENTER, PP_ALIGN.LEFT, PP_ALIGN.LEFT]) + Cm(0.3)
    return y


# ───────────────────────── 점검보고서 ─────────────────────────
def build_inspection(ctx: dict, path: str) -> None:
    prs = Presentation()
    prs.slide_width, prs.slide_height = A4_W, A4_H
    sw, sh = A4_W, A4_H
    variant = ctx["report"]["variant"]
    title_ko = ctx["report"]["type_label"].replace(" 보고서", "") + " 보고서"
    pages = []

    if variant == "single":
        e = ctx["equipments"][0]
        s = _new(prs)
        pages.append(s)
        y, _ = _header(s, ctx, title_ko, "Storage Service Report", sw)
        y = _customer_block(s, ctx, y, sw)
        y = K.section(s, M, y, sw - 2 * M, "점검 장비 정보 (Equipment Info)")
        y = K.table(s, M, y, sw - 2 * M, [
            ["Model", f"{e['vendor']} {e['model']}" + (f" ({e['ha_type']})" if e["ha_type"] else ""), "System S/N", e["serial"]],
            ["OS Ver", e["os_version"], "Disk Type", e["disk_info"]],
        ], [1.2, 2.8, 1.2, 2.8], header=False, label_cols=(0, 2), size=8.5,
            align=[PP_ALIGN.CENTER, PP_ALIGN.LEFT] * 2) + Cm(0.35)
        _system_detail(s, ctx, e, y, sw, vol_limit=5, aggr_limit=4, find_limit=3)
        s2 = _new(prs)
        pages.append(s2)
        y = _page_header(s2, ctx, "점검 결과 및 종합 의견", sw)
        y = _visual_single(s2, ctx, e, y, sw)
        _opinion(s2, ctx, y, sw, sh - Cm(1.3))
    else:
        s = _new(prs)
        pages.append(s)
        y, _ = _header(s, ctx, title_ko, "Storage Service Report", sw)
        y = _customer_block(s, ctx, y, sw)
        y = _equipment_list(s, ctx, y, sw, compact=variant == "fleet")
        if variant == "multi":
            y = K.section(s, M, y, sw - 2 * M, "점검 범위 (Inspection Scope)")
            scope = ctx["scope"]
            rows = [[scope[i][0], scope[i][1], scope[i + 1][0] if i + 1 < len(scope) else "",
                     scope[i + 1][1] if i + 1 < len(scope) else ""] for i in range(0, len(scope), 2)]
            y = K.table(s, M, y, sw - 2 * M, rows, [1.1, 3.0, 1.1, 3.0], header=False, label_cols=(0, 2), size=8,
                        align=[PP_ALIGN.CENTER, PP_ALIGN.LEFT] * 2) + Cm(0.35)
            y = K.section(s, M, y, sw - 2 * M, "Executive Summary – 장비별 건강 점수")
            n = len(ctx["equipments"])
            cw = (sw - 2 * M - Cm(0.4) * (n - 1)) / n
            for i, e in enumerate(ctx["equipments"]):
                x = M + i * (cw + Cm(0.4))
                K.rect(s, x, y, cw, Cm(5.2), K.WHITE, line=K.LINE, shape=K.MSO_SHAPE.ROUNDED_RECTANGLE, radius=0.05)
                K.text(s, x, y + Cm(0.2), cw, Cm(0.5), f"System {e['seq']}", 8, True, K.GREY, align=PP_ALIGN.CENTER)
                K.text(s, x, y + Cm(0.6), cw, Cm(0.6), K.clip(e["model"], 22), 10, True, K.NAVY, align=PP_ALIGN.CENTER)
                K.donut(s, x + (cw - Cm(2.6)) / 2, y + Cm(1.25), Cm(2.6), e["analysis"]["score"], "Health",
                        sub=e["analysis"]["status_label"])
            y += Cm(5.6)
            top = [f for e in ctx["equipments"] for f in e["analysis"]["findings"] if f["severity"] != "info"][:5]
            if top:
                y = K.section(s, M, y, sw - 2 * M, "핵심 이슈 (Key Issues)")
                rows = [["등급", "장비", "이슈"]] + [
                    [K.STATUS_TEXT[f["severity"]], f"#{f['_seq']}", K.clip(f["title"] + " - " + f["detail"], 80)]
                    for f in _tag_findings(ctx)[:5]
                ]
                K.table(s, M, y, sw - 2 * M, rows, [0.9, 0.6, 6.5], size=7.5, row_h=Cm(0.55), status_col=0,
                        align=[PP_ALIGN.CENTER, PP_ALIGN.CENTER, PP_ALIGN.LEFT])
            for e in ctx["equipments"]:
                sp = _new(prs)
                pages.append(sp)
                y = _page_header(sp, ctx, "스토리지 운영현황 (Storage Operation Status)", sw)
                _system_detail(sp, ctx, e, y, sw, vol_limit=10, aggr_limit=6, find_limit=5)
            so = _new(prs)
            pages.append(so)
            y = _page_header(so, ctx, "종합 점검 결과 (Summary)", sw)
            y = _check_matrix(so, ctx, y, sw)
            y = _visual_matrix(so, ctx, y, sw)
            _opinion_fit(prs, pages, so, ctx, y, sw, sh, "종합 엔지니어 의견 (Comprehensive Engineer's Opinion)")
        else:  # fleet
            y = K.section(s, M, y, sw - 2 * M, "Fleet Dashboard – 전체 현황")
            eqs = ctx["equipments"]
            c = sum(1 for e in eqs if e["analysis"]["status"] == "critical")
            w_ = sum(1 for e in eqs if e["analysis"]["status"] == "warning")
            tot = sum(e["analysis"]["capacity"]["total_gb"] for e in eqs)
            used = sum(e["analysis"]["capacity"]["used_gb"] for e in eqs)
            kw = (sw - 2 * M - Cm(0.3) * 3) / 4
            K.kpi(s, M, y, kw, Cm(1.9), f"{len(eqs)}대", "점검 장비")
            K.kpi(s, M + (kw + Cm(0.3)), y, kw, Cm(1.9), str(c), "Critical 장비", K.RED if c else K.GREEN, K.RED if c else K.GREEN)
            K.kpi(s, M + 2 * (kw + Cm(0.3)), y, kw, Cm(1.9), str(w_), "Warning 장비", K.AMBER if w_ else K.GREEN, K.AMBER if w_ else K.GREEN)
            K.kpi(s, M + 3 * (kw + Cm(0.3)), y, kw, Cm(1.9), f"{round(used / tot * 100) if tot else 0}%",
                  f"전체 사용률 {human(used)} / {human(tot)}" if tot else "전체 사용률")
            y += Cm(2.2)
            bottom = sh - Cm(1.4)
            ch_h = min(Cm(0.75) * len(eqs) + Cm(1.0), bottom - y - Cm(0.2))
            K.bar_chart(s, M, y, (sw - 2 * M) * 0.5, ch_h, [f"#{e['seq']} {K.clip(e['model'], 14)}" for e in eqs],
                        [e["analysis"]["capacity"]["pct"] for e in eqs], title="장비별 Data 용량 사용률")
            K.bar_chart(s, M + (sw - 2 * M) * 0.52, y, (sw - 2 * M) * 0.48, ch_h,
                        [f"#{e['seq']}" for e in eqs], [e["analysis"]["score"] for e in eqs],
                        title="장비별 Health Score", suffix="", thresholds=(101, 101))
            # 점수 차트는 점수가 낮을수록 위험 → 색 재지정
            ch = s.shapes[-1].chart
            for i, e in enumerate(eqs):
                pt = ch.plots[0].series[0].points[i]
                pt.format.fill.fore_color.rgb = K.STATUS_COLOR[e["analysis"]["status"]]
            per = 2
            for i in range(0, len(eqs), per):
                sp = _new(prs)
                pages.append(sp)
                y = _page_header(sp, ctx, "스토리지 운영현황 (Storage Operation Status)", sw)
                for e in eqs[i:i + per]:
                    y = _system_compact(sp, ctx, e, y, sw)
            so = _new(prs)
            pages.append(so)
            y = _page_header(so, ctx, "종합 점검 결과 (Summary)", sw)
            y = _check_matrix(so, ctx, y, sw)
            sv = _new(prs)
            pages.append(sv)
            y2 = _page_header(sv, ctx, "육안 점검 및 종합 의견", sw)
            y2 = _visual_matrix(sv, ctx, y2, sw)
            _opinion_fit(prs, pages, sv, ctx, y2, sw, sh, "종합 엔지니어 의견 (Comprehensive Engineer's Opinion)")

    for i, s in enumerate(pages, 1):
        K.footer(s, sw, sh, ctx["company"], i, len(pages))
    prs.save(path)


def _tag_findings(ctx):
    out = []
    for e in ctx["equipments"]:
        for f in e["analysis"]["findings"]:
            if f["severity"] != "info":
                out.append({**f, "_seq": e["seq"]})
    out.sort(key=lambda f: 0 if f["severity"] == "critical" else 1)
    return out


def _system_compact(slide, ctx, e, y, sw):
    w = sw - 2 * M
    K.rect(slide, M, y, w, Cm(0.9), K.LIGHT)
    K.text(slide, M + Cm(0.3), y + Cm(0.1), w - Cm(4), Cm(0.7),
           f"System {e['seq']}  |  {e['vendor']} {e['model']}  (S/N: {K.clip(e['serial'], 36)})", 9.5, True, K.NAVY,
           anchor=MSO_ANCHOR.MIDDLE)
    st = e["analysis"]["status"]
    K.pill(slide, M + w - Cm(2.9), y + Cm(0.17), Cm(2.6), Cm(0.55), f"{K.STATUS_TEXT[st]} · {e['analysis']['score']}",
           K.STATUS_COLOR[st], size=8)
    y += Cm(1.15)
    tw = w * 0.62
    b1 = K.table(slide, M, y, tw, _aggr_rows(e, 4), [1.3, 1.6, 1.0, 1.0, 0.9, 1.0], size=7, row_h=Cm(0.5))
    aggs = e["parsed"].get("aggregates", [])[:4]
    if aggs:
        K.bar_chart(slide, M + tw + Cm(0.3), y - Cm(0.1), w - tw - Cm(0.3), max(b1 - y, Cm(2.4)),
                    [a["name"] for a in aggs], [a["pct"] for a in aggs])
    y = max(b1, y + Cm(2.4)) + Cm(0.2)
    y = K.table(slide, M, y, w, _vol_rows(e, 5), [1.2, 1.8, 1.0, 1.0, 0.8, 0.8, 1.0], size=7, row_h=Cm(0.46),
                status_col=6) + Cm(0.2)
    y = K.table(slide, M, y, w, _findings_rows(e, 3), [0.8, 0.9, 4.2, 2.8], size=7, row_h=Cm(0.55), status_col=0,
                align=[PP_ALIGN.CENTER, PP_ALIGN.CENTER, PP_ALIGN.LEFT, PP_ALIGN.LEFT])
    return y + Cm(0.6)


# ───────────────────────── 작업계획서 ─────────────────────────
def _wide_title(slide, ctx, title, num=""):
    sw = WIDE_W
    K.rect(slide, 0, 0, Cm(0.5), WIDE_H, K.BLUE)
    K.text(slide, Cm(1.3), Cm(0.6), sw - Cm(8), Cm(1.1), (f"{num}. " if num else "") + title, 20, True, K.NAVY,
           anchor=MSO_ANCHOR.MIDDLE)
    K.text(slide, sw - Cm(9), Cm(0.75), Cm(7.7), Cm(0.8), ctx["plan_title"], 9, False, K.GREY, align=PP_ALIGN.RIGHT,
           anchor=MSO_ANCHOR.MIDDLE)
    ln = slide.shapes.add_connector(1, Cm(1.3), Cm(1.9), sw - Cm(1.3), Cm(1.9))
    ln.line.color.rgb = K.LINE
    return Cm(2.4)


def build_workplan(ctx: dict, path: str) -> None:
    prs = Presentation()
    prs.slide_width, prs.slide_height = WIDE_W, WIDE_H
    sw, sh = WIDE_W, WIDE_H
    plan = ctx["plan"]
    L = Cm(1.3)
    W = sw - 2 * L
    pages = []

    # 표지
    s = _new(prs)
    K.rect(s, 0, 0, sw, sh, K.NAVY)
    K.rect(s, 0, sh - Cm(4.2), sw, Cm(4.2), K.WHITE)
    K.rect(s, L, Cm(4.0), Cm(2.0), Cm(0.18), K.BLUE)
    K.text(s, L, Cm(4.5), W, Cm(1.0), ctx["customer"]["name"], 16, False, K.RGBColor(0xB9, 0xD3, 0xEA))
    K.text(s, L, Cm(5.6), W, Cm(2.2), ctx["plan_title"], 30, True, K.WHITE)
    K.text(s, L, Cm(8.2), W, Cm(0.8), f"{ctx['report']['subtype_label']} · {ctx['report']['date']} · No. {ctx['report']['no']}",
           11, False, K.RGBColor(0xB9, 0xD3, 0xEA))
    K.logo(s, L, sh - Cm(3.0), Cm(1.3))
    K.text(s, sw - Cm(12), sh - Cm(3.0), Cm(10.7), Cm(1.4), f"작성: {ctx['company']['name']}\n{ctx['engineer'].get('name', '')} {ctx['engineer'].get('title', '')}",
           10, False, K.INK, align=PP_ALIGN.RIGHT)

    toc = ["작업 개요", "대상 장비 현황", "상세 작업 절차", "서비스 영향 및 소요 시간", "작업 리스크 진단", "롤백 계획",
           "사전 점검 및 최종 의견"]
    s = _new(prs)
    pages.append(s)
    y = _wide_title(s, ctx, "목차")
    for i, t in enumerate(toc, 1):
        col, row = divmod(i - 1, 4)
        x = L + col * (W / 2)
        yy = y + Cm(0.4) + row * Cm(2.6)
        K.text(s, x, yy, Cm(2.2), Cm(1.6), f"{i:02d}", 28, True, K.BLUE, anchor=MSO_ANCHOR.MIDDLE)
        K.text(s, x + Cm(2.4), yy, W / 2 - Cm(3), Cm(1.6), t, 16, True, K.INK, anchor=MSO_ANCHOR.MIDDLE)

    # 1. 작업 개요
    s = _new(prs)
    pages.append(s)
    y = _wide_title(s, ctx, "작업 개요", "1")
    steps = plan.get("steps", [])
    total_min = sum(int(x.get("minutes") or 0) for x in steps)
    rows = [
        ["고객사", ctx["customer"]["name"], "작업 장소", plan.get("location") or ctx["customer"].get("site", "")],
        ["작업 일정", plan.get("schedule", ""), "예상 소요", f"{total_min // 60}시간 {total_min % 60}분 ({total_min}분)"],
        ["작업 유형", ctx["report"]["subtype_label"], "서비스 중단", plan.get("downtime", "") or _downtime(steps)],
        ["대상 장비", ", ".join(f"{e['model']}({e['name']})" for e in ctx["equipments"]), "", ""],
        ["작업 목적", plan.get("purpose", ""), "", ""],
    ]
    y = K.table(s, L, y, W, rows, [1.3, 4.0, 1.3, 3.0], header=False, label_cols=(0, 2), size=10, row_h=Cm(0.95),
                align=[PP_ALIGN.CENTER, PP_ALIGN.LEFT] * 2)
    tbl = s.shapes[-1].table
    for r in (3, 4):
        K.merge(tbl, r, 1, 3)
    y += Cm(0.5)
    workers = plan.get("workers") or []
    if workers:
        K.text(s, L, y, W, Cm(0.6), "작업자 / 연락처", 11, True, K.NAVY)
        rows = [["회사", "이름", "역할", "연락처", "이메일"]] + [
            [w.get("company", ""), w.get("name", ""), w.get("role", ""), w.get("phone", ""), w.get("email", "")]
            for w in workers[:4]
        ]
        K.table(s, L, y + Cm(0.7), W, rows, [2, 1.5, 1.5, 2, 3], size=9, row_h=Cm(0.7))

    # 2. 대상 장비 현황
    s = _new(prs)
    pages.append(s)
    y = _wide_title(s, ctx, "대상 장비 현황", "2")
    rows = [["No", "System", "Model", "S/N", "현재 OS", "목표 버전/부품", "HA", "상태", "Health"]]
    for e in ctx["equipments"]:
        rows.append([e["seq"], e["name"], e["model"], K.clip(e["serial"], 30), e["os_version"],
                     plan.get("target", ""), e["ha_type"], K.STATUS_TEXT[e["analysis"]["status"]],
                     e["analysis"]["score"]])
    y = K.table(s, L, y, W, rows, [0.5, 1.6, 1.6, 2.6, 1.3, 1.6, 1.0, 1.0, 0.8], size=9, row_h=Cm(0.75),
                status_col=7) + Cm(0.5)
    tf = _tag_findings(ctx)[:5]
    if tf:
        K.text(s, L, y, W, Cm(0.6), "작업 전 확인된 이슈 (로그 분석)", 11, True, K.NAVY)
        rows = [["등급", "장비", "내용", "권고"]] + [
            [K.STATUS_TEXT[f["severity"]], f"#{f['_seq']}", K.clip(f["title"] + " - " + f["detail"], 70),
             K.clip(f["recommendation"], 50)] for f in tf]
        K.table(s, L, y + Cm(0.7), W, rows, [0.9, 0.6, 5.0, 3.5], size=8.5, row_h=Cm(0.62), status_col=0,
                align=[PP_ALIGN.CENTER, PP_ALIGN.CENTER, PP_ALIGN.LEFT, PP_ALIGN.LEFT])

    # 3. 상세 작업 절차 (페이지 분할)
    per = 9
    chunks = [steps[i:i + per] for i in range(0, len(steps), per)] or [[]]
    n = 0
    for ci, chunk in enumerate(chunks):
        s = _new(prs)
        pages.append(s)
        y = _wide_title(s, ctx, "상세 작업 절차" + (f" ({ci + 1}/{len(chunks)})" if len(chunks) > 1 else ""), "3")
        rows = [["No", "단계", "작업 항목", "작업 상세", "소요", "서비스 영향"]]
        for st in chunk:
            n += 1
            rows.append([n, st.get("phase", ""), st.get("activity", ""), K.clip(st.get("detail", ""), 70),
                         f"{st.get('minutes', '')}분", st.get("impact", "")])
        K.table(s, L, y, W, rows, [0.5, 1.8, 2.0, 5.2, 0.8, 1.4], size=9, row_h=Cm(0.9),
                align=[PP_ALIGN.CENTER, PP_ALIGN.LEFT, PP_ALIGN.LEFT, PP_ALIGN.LEFT, PP_ALIGN.CENTER, PP_ALIGN.CENTER])
        tbl = s.shapes[-1].table
        for r in range(1, len(rows)):
            run = tbl.cell(r, 5).text_frame.paragraphs[0].runs[0]
            if run.text and "없음" not in run.text:
                run.font.color.rgb = K.RED
                run.font.bold = True

    # 4. 서비스 영향 및 소요 시간 – 단계별 타임라인(가로 막대)
    s = _new(prs)
    pages.append(s)
    y = _wide_title(s, ctx, "서비스 영향 및 소요 시간", "4")
    phases: dict[str, int] = {}
    for st in steps:
        phases[st.get("phase", "")] = phases.get(st.get("phase", ""), 0) + int(st.get("minutes") or 0)
    if phases:
        K.bar_chart(s, L, y, W * 0.58, sh - y - Cm(1.5), list(phases.keys()), list(phases.values()),
                    title="단계별 소요 시간(분)", max_val=max(phases.values()) * 1.25, suffix="분", thresholds=(10**9, 10**9))
    impact = [st for st in steps if st.get("impact") and "없음" not in st.get("impact", "")]
    x2 = L + W * 0.62
    K.kpi(s, x2, y, W * 0.38, Cm(2.0), f"{total_min}분", "총 예상 소요 시간")
    K.kpi(s, x2, y + Cm(2.3), W * 0.38, Cm(2.0), f"{len(impact)}건", "서비스 영향 단계", K.RED if impact else K.GREEN,
          K.RED if impact else K.GREEN)
    rows = [["영향 단계", "영향"]] + [[K.clip(st.get("activity", ""), 22), st.get("impact", "")] for st in impact[:6]]
    if len(rows) > 1:
        K.table(s, x2, y + Cm(4.7), W * 0.38, rows, [2, 1.4], size=8.5, row_h=Cm(0.6))

    # 5. 리스크 진단 (AI 조언 → 엔지니어 검토본)
    s = _new(prs)
    pages.append(s)
    y = _wide_title(s, ctx, "작업 리스크 진단 (엔지니어 검토)", "5")
    K.rect(s, L, y, W, sh - y - Cm(1.2), K.WHITE, line=K.LINE)
    K.rect(s, L, y, Cm(0.15), sh - y - Cm(1.2), K.STATUS_COLOR[ctx["report"]["overall_status"] or "normal"])
    K.text(s, L + Cm(0.4), y + Cm(0.2), W - Cm(0.6), sh - y - Cm(1.6),
           K.fit_lines(ctx["diagnosis"], int((sh - y - Cm(1.8)) / Cm(0.46)), int(W / Cm(0.26))), 10, line_spacing=1.1)

    # 6. 롤백 계획
    s = _new(prs)
    pages.append(s)
    y = _wide_title(s, ctx, "작업 실패 시 롤백 계획", "6")
    rb = plan.get("rollback", "")
    rows = [["구분", "내용"], ["롤백 기준", plan.get("rollback_criteria") or "작업 단계별 정상 확인 실패, 서비스 영향 허용 시간 초과 시"],
            ["롤백 절차", rb], ["의사 결정", plan.get("decision_maker") or "고객사 담당자와 협의 후 결정"]]
    K.table(s, L, y, W, rows, [1.5, 8.5], size=11, row_h=Cm(1.6), label_cols=(0,),
            align=[PP_ALIGN.CENTER, PP_ALIGN.LEFT])

    # 7. 사전 점검 및 최종 의견
    s = _new(prs)
    pages.append(s)
    y = _wide_title(s, ctx, "사전 점검(육안) 결과 및 엔지니어 최종 의견", "7")
    eqs = ctx["equipments"]
    rows = [["점검 항목"] + [f"#{e['seq']}" for e in eqs]]
    for key, label in VISUAL_ITEMS:
        rows.append([label] + [{"정상": "● 정상", "비정상": "▲ 비정상", "N/A": "N/A"}.get(e["visual"].get(key, ""), "-") for e in eqs])
    K.table(s, L, y, W * 0.48, rows, [2.4] + [1] * len(eqs), size=8, row_h=Cm(0.55), label_cols=(0,),
            align=[PP_ALIGN.LEFT] + [PP_ALIGN.CENTER] * len(eqs))
    x2 = L + W * 0.52
    K.rect(s, x2, y, W * 0.48, Cm(5.0), K.LIGHT)
    K.text(s, x2 + Cm(0.3), y + Cm(0.2), W * 0.48 - Cm(0.6), Cm(0.6), "엔지니어 최종 Comment", 11, True, K.BLUE)
    K.text(s, x2 + Cm(0.3), y + Cm(0.9), W * 0.48 - Cm(0.6), Cm(4.0),
           K.fit_lines(ctx["final_comment"], 8, int(W * 0.48 / Cm(0.3))), 10)
    eng = ctx["engineer"]
    K.table(s, x2, y + Cm(5.4), W * 0.48, [["작성자", f"{eng.get('name', '')} {eng.get('title', '')}"],
                                           ["연락처", eng.get("phone", "")], ["고객 승인", "(서명)"]],
            [1, 3], header=False, label_cols=(0,), size=9, row_h=Cm(0.75), align=[PP_ALIGN.CENTER, PP_ALIGN.LEFT])

    s = _new(prs)
    K.rect(s, 0, 0, sw, sh, K.NAVY)
    K.text(s, 0, sh / 2 - Cm(1.5), sw, Cm(2), "Thank you", 40, True, K.WHITE, align=PP_ALIGN.CENTER)
    K.text(s, 0, sh / 2 + Cm(0.5), sw, Cm(1), ctx["company"]["name"], 14, False, K.RGBColor(0xB9, 0xD3, 0xEA),
           align=PP_ALIGN.CENTER)

    for i, p in enumerate(pages, 2):
        K.text(p, sw - Cm(3), sh - Cm(0.9), Cm(2), Cm(0.5), f"{i}", 9, True, K.GREY, align=PP_ALIGN.RIGHT)
    prs.save(path)


def _downtime(steps):
    imp = [s.get("impact", "") for s in steps if s.get("impact") and "없음" not in s.get("impact", "")]
    if not imp:
        return "없음 (무중단)"
    return ", ".join(dict.fromkeys(imp))


def build(ctx: dict, path: str) -> None:
    if ctx["report"]["type"] == "workplan":
        build_workplan(ctx, path)
    else:
        build_inspection(ctx, path)
