"""python-pptx 공용 그리기 도구 (DATANEX 브랜드 톤)."""
from __future__ import annotations

from pathlib import Path

from pptx.chart.data import CategoryChartData
from pptx.dml.color import RGBColor
from pptx.enum.chart import XL_CHART_TYPE, XL_LABEL_POSITION, XL_LEGEND_POSITION
from pptx.enum.shapes import MSO_SHAPE
from pptx.enum.text import MSO_ANCHOR, PP_ALIGN
from pptx.oxml.ns import qn
from pptx.util import Cm, Pt

FONT = "맑은 고딕"
STATIC = Path(__file__).resolve().parent.parent / "static"
LOGO_COLOR = STATIC / "img" / "datanex_color.png"

BLUE = RGBColor(0x19, 0x69, 0xA5)
BLACK = RGBColor(0x04, 0x00, 0x00)
NAVY = RGBColor(0x0B, 0x2E, 0x4F)
INK = RGBColor(0x1F, 0x29, 0x37)
GREY = RGBColor(0x6B, 0x77, 0x85)
LINE = RGBColor(0xD5, 0xDE, 0xE8)
LIGHT = RGBColor(0xEE, 0xF4, 0xFA)
WHITE = RGBColor(0xFF, 0xFF, 0xFF)
RED = RGBColor(0xD6, 0x45, 0x45)
AMBER = RGBColor(0xE0, 0x9A, 0x2B)
GREEN = RGBColor(0x2E, 0x9E, 0x6A)
STATUS_COLOR = {"critical": RED, "warning": AMBER, "normal": GREEN, "info": BLUE}
STATUS_TEXT = {"critical": "Critical", "warning": "Warning", "normal": "Normal", "info": "Info"}


def hexrgb(c: RGBColor) -> str:
    return str(c)


def _font(run, size=10, bold=False, color=INK):
    run.font.size = Pt(size)
    run.font.bold = bold
    run.font.color.rgb = color
    run.font.name = FONT
    rpr = run._r.get_or_add_rPr()
    for tag in ("a:ea", "a:cs"):
        el = rpr.find(qn(tag))
        if el is None:
            el = rpr.makeelement(qn(tag), {})
            rpr.append(el)
        el.set("typeface", FONT)


def text(slide, x, y, w, h, value, size=10, bold=False, color=INK, align=PP_ALIGN.LEFT,
         anchor=MSO_ANCHOR.TOP, line_spacing=1.15, wrap=True):
    tb = slide.shapes.add_textbox(x, y, w, h)
    tf = tb.text_frame
    tf.word_wrap = wrap
    tf.vertical_anchor = anchor
    tf.margin_left = tf.margin_right = Cm(0.1)
    tf.margin_top = tf.margin_bottom = Cm(0.05)
    lines = str(value).split("\n") if value is not None else [""]
    for i, ln in enumerate(lines):
        p = tf.paragraphs[0] if i == 0 else tf.add_paragraph()
        p.alignment = align
        p.line_spacing = line_spacing
        r = p.add_run()
        r.text = ln
        b = bold or (ln.startswith("[") and ln.rstrip().endswith("]"))
        _font(r, size, b, color)
    return tb


def rect(slide, x, y, w, h, fill=LIGHT, line=None, shape=MSO_SHAPE.RECTANGLE, radius=None):
    s = slide.shapes.add_shape(shape, x, y, w, h)
    s.fill.solid()
    s.fill.fore_color.rgb = fill
    if line is None:
        s.line.fill.background()
    else:
        s.line.color.rgb = line
        s.line.width = Pt(0.75)
    s.shadow.inherit = False
    if radius is not None and shape == MSO_SHAPE.ROUNDED_RECTANGLE:
        s.adjustments[0] = radius
    return s


def pill(slide, x, y, w, h, label, fill, color=WHITE, size=9):
    s = rect(slide, x, y, w, h, fill, shape=MSO_SHAPE.ROUNDED_RECTANGLE, radius=0.5)
    tf = s.text_frame
    tf.margin_left = tf.margin_right = Cm(0.05)
    tf.margin_top = tf.margin_bottom = 0
    tf.vertical_anchor = MSO_ANCHOR.MIDDLE
    p = tf.paragraphs[0]
    p.alignment = PP_ALIGN.CENTER
    r = p.add_run()
    r.text = label
    _font(r, size, True, color)
    return s


def section(slide, x, y, w, title, size=12):
    """좌측 블루 바 + 제목 + 얇은 구분선 (샘플 보고서의 섹션 헤더 스타일)."""
    rect(slide, x, y + Cm(0.08), Cm(0.18), Cm(0.55), BLUE)
    text(slide, x + Cm(0.35), y, w - Cm(0.35), Cm(0.7), title, size, True, NAVY, anchor=MSO_ANCHOR.MIDDLE)
    ln = slide.shapes.add_connector(1, x, y + Cm(0.78), x + w, y + Cm(0.78))
    ln.line.color.rgb = LINE
    ln.line.width = Pt(0.75)
    return y + Cm(0.95)


def table(slide, x, y, w, rows, col_w=None, header=True, size=8.5, row_h=Cm(0.62),
          header_fill=NAVY, zebra=True, align=None, label_cols=(), status_col=None, wrap_cols=()):
    """rows: list[list[str]]. label_cols: 라벨 셀(연한 배경) 열 인덱스. 반환: 표 하단 y."""
    nrows, ncols = len(rows), max(len(r) for r in rows)
    shp = slide.shapes.add_table(nrows, ncols, x, y, w, row_h * nrows)
    tbl = shp.table
    tblPr = tbl._tbl.tblPr
    style = tblPr.find(qn("a:tableStyleId"))
    if style is None:
        style = tblPr.makeelement(qn("a:tableStyleId"), {})
        tblPr.append(style)
    style.text = "{5940675A-B579-460E-94D1-54222C63F5DA}"  # No Style, Table Grid
    tbl.first_row = header
    tbl.horz_banding = False
    tot = sum(col_w) if col_w else ncols
    widths = [int(w * (col_w[i] if col_w else 1) / tot) for i in range(ncols)]
    for i, cw in enumerate(widths):
        tbl.columns[i].width = cw
    total_h = 0
    for r in range(nrows):
        need = max(_row_need(rows[r][c] if c < len(rows[r]) else "", widths[c], size) for c in range(ncols))
        h = max(row_h, need)
        tbl.rows[r].height = h
        total_h += h
        for c in range(ncols):
            cell = tbl.cell(r, c)
            val = rows[r][c] if c < len(rows[r]) else ""
            cell.margin_left = cell.margin_right = Cm(0.12)
            cell.margin_top = cell.margin_bottom = Cm(0.03)
            cell.vertical_anchor = MSO_ANCHOR.MIDDLE
            tf = cell.text_frame
            tf.word_wrap = True
            p = tf.paragraphs[0]
            p.alignment = (align[c] if align and c < len(align) else PP_ALIGN.CENTER)
            run = p.add_run()
            run.text = " " if val is None or str(val) == "" else str(val)  # 빈 셀은 기본 18pt 로 커지므로 공백
            is_head = header and r == 0
            color, bold = INK, False
            cell.fill.solid()
            if is_head:
                cell.fill.fore_color.rgb = header_fill
                color, bold = WHITE, True
            elif c in label_cols:
                cell.fill.fore_color.rgb = LIGHT
                color, bold = NAVY, True
            else:
                cell.fill.fore_color.rgb = RGBColor(0xF8, 0xFA, 0xFC) if zebra and r % 2 == 0 else WHITE
            if status_col is not None and c == status_col and not is_head:
                key = str(val).lower()
                for k, col in (("critical", RED), ("check", RED), ("비정상", RED), ("위험", RED),
                               ("warning", AMBER), ("monitor", AMBER), ("요주의", AMBER),
                               ("normal", GREEN), ("정상", GREEN)):
                    if k in key:
                        color, bold = col, True
                        break
            _font(run, size, bold, color)
            _set_cell_border(cell)
    shp.height = total_h
    return y + total_h


def _text_pt(s: str, size: float) -> float:
    return sum(size * (1.0 if ord(ch) > 0x2E80 else 0.56) for ch in s)


def _row_need(val, width_emu, size) -> int:
    """LibreOffice/PowerPoint 가 실제로 그리는 행 높이 추정 (글꼴 크기·줄바꿈 반영)."""
    avail = max(width_emu / 12700 - 9, 10)  # 좌우 여백 제외 (pt)
    lines = 0
    for part in str(val if val is not None else "").split("\n"):
        lines += max(1, -(-int(_text_pt(part, size)) // int(avail)))
    return int(Pt(lines * size * 1.32).emu + Cm(0.2).emu)


def _set_cell_border(cell, color="D5DEE8", width=6350):
    tcPr = cell._tc.get_or_add_tcPr()
    for edge in ("a:lnL", "a:lnR", "a:lnT", "a:lnB"):
        ln = tcPr.find(qn(edge))
        if ln is None:
            ln = tcPr.makeelement(qn(edge), {"w": str(width), "cap": "flat", "cmpd": "sng", "algn": "ctr"})
            tcPr.append(ln)
        sf = ln.makeelement(qn("a:solidFill"), {})
        clr = sf.makeelement(qn("a:srgbClr"), {"val": color})
        sf.append(clr)
        ln.append(sf)


def bar_chart(slide, x, y, w, h, categories, values, title="", max_val=100, suffix="%",
              thresholds=(85, 95), horizontal=True):
    cd = CategoryChartData()
    cd.categories = categories or ["-"]
    cd.add_series(title or "사용률", values or [0])
    kind = XL_CHART_TYPE.BAR_CLUSTERED if horizontal else XL_CHART_TYPE.COLUMN_CLUSTERED
    gf = slide.shapes.add_chart(kind, x, y, w, h, cd)
    ch = gf.chart
    ch.has_legend = False
    ch.font.size = Pt(8)
    ch.font.name = FONT
    if title:
        ch.has_title = True
        ch.chart_title.text_frame.text = title
        _font(ch.chart_title.text_frame.paragraphs[0].runs[0], 9, True, NAVY)
    else:
        ch.has_title = False
    va = ch.value_axis
    va.maximum_scale = max_val
    va.minimum_scale = 0
    va.has_major_gridlines = True
    va.major_gridlines.format.line.color.rgb = LINE
    va.format.line.fill.background()
    va.tick_labels.font.size = Pt(7)
    va.tick_labels.font.color.rgb = GREY
    ca = ch.category_axis
    ca.tick_labels.font.size = Pt(7.5)
    ca.format.line.color.rgb = LINE
    if horizontal:
        ca.reverse_order = True
    plot = ch.plots[0]
    plot.gap_width = 60
    plot.has_data_labels = True
    dl = plot.data_labels
    dl.number_format = '0"' + suffix + '"'
    dl.number_format_is_linked = False
    dl.font.size = Pt(7.5)
    dl.font.bold = True
    dl.position = XL_LABEL_POSITION.OUTSIDE_END
    ser = plot.series[0]
    for i, v in enumerate(values or [0]):
        pt = ser.points[i]
        pt.format.fill.solid()
        pt.format.fill.fore_color.rgb = RED if v >= thresholds[1] else AMBER if v >= thresholds[0] else BLUE
    return gf


def donut(slide, x, y, size, value, label, color=None, sub=""):
    """건강 점수 게이지 (도넛 차트 + 중앙 숫자)."""
    color = color or (GREEN if value >= 80 else AMBER if value >= 60 else RED)
    cd = CategoryChartData()
    cd.categories = ["score", "rest"]
    cd.add_series("s", [value, max(0, 100 - value)])
    gf = slide.shapes.add_chart(XL_CHART_TYPE.DOUGHNUT, x, y, size, size, cd)
    ch = gf.chart
    ch.has_legend = False
    ch.has_title = False
    plot = ch.plots[0]
    try:
        hole = plot._element.find(qn("c:holeSize"))
        if hole is None:
            hole = plot._element.makeelement(qn("c:holeSize"), {})
            plot._element.append(hole)
        hole.set("val", "72")
    except Exception:
        pass
    pts = plot.series[0].points
    pts[0].format.fill.solid()
    pts[0].format.fill.fore_color.rgb = color
    pts[1].format.fill.solid()
    pts[1].format.fill.fore_color.rgb = RGBColor(0xE6, 0xEC, 0xF2)
    text(slide, x, y + size * 0.30, size, size * 0.25, f"{value}", int(size / 12700 / 4.2), True, color,
         align=PP_ALIGN.CENTER, anchor=MSO_ANCHOR.MIDDLE)
    text(slide, x, y + size * 0.55, size, size * 0.15, label, 7, False, GREY, align=PP_ALIGN.CENTER)
    if sub:
        text(slide, x - Cm(0.5), y + size, size + Cm(1), Cm(0.5), sub, 7.5, True, color, align=PP_ALIGN.CENTER)


def kpi(slide, x, y, w, h, value, label, color=NAVY, accent=BLUE):
    rect(slide, x, y, w, h, WHITE, line=LINE, shape=MSO_SHAPE.ROUNDED_RECTANGLE, radius=0.08)
    rect(slide, x, y + Cm(0.15), Cm(0.12), h - Cm(0.3), accent)
    text(slide, x + Cm(0.3), y + Cm(0.1), w - Cm(0.4), h * 0.55, value, 16, True, color, anchor=MSO_ANCHOR.MIDDLE)
    text(slide, x + Cm(0.3), y + h * 0.58, w - Cm(0.4), h * 0.38, label, 7.5, False, GREY)


def logo(slide, x, y, h):
    if LOGO_COLOR.exists():
        slide.shapes.add_picture(str(LOGO_COLOR), x, y, height=h)


def footer(slide, sw, sh, company: dict, page: int, total: int):
    y = sh - Cm(1.0)
    ln = slide.shapes.add_connector(1, Cm(1.0), y, sw - Cm(1.0), y)
    ln.line.color.rgb = LINE
    parts = [company.get("name", "")]
    if company.get("address"):
        parts.append(company["address"])
    contact = " | ".join(
        s for s in (
            f"Tel: {company['tel']}" if company.get("tel") else "",
            f"Email: {company['email']}" if company.get("email") else "",
        ) if s
    )
    text(slide, Cm(1.0), y + Cm(0.1), sw - Cm(5), Cm(0.4), " | ".join(parts), 7, False, GREY)
    if contact:
        text(slide, Cm(1.0), y + Cm(0.45), sw - Cm(5), Cm(0.4), contact, 7, False, GREY)
    text(slide, sw - Cm(4), y + Cm(0.1), Cm(3), Cm(0.4), f"{page} / {total}", 8, True, NAVY, align=PP_ALIGN.RIGHT)


def clip(s: str, n: int) -> str:
    s = (s or "").replace("\n", " ")
    return s if len(s) <= n else s[: n - 1] + "…"


def fit_lines(value: str, max_lines: int, width_chars: int) -> str:
    """긴 문단을 박스에 맞게 자릅니다(초과분은 '…'로 표시)."""
    out, used = [], 0
    for ln in (value or "").split("\n"):
        need = max(1, -(-len(ln) // width_chars))
        if used + need > max_lines:
            out.append("… (이하 생략, 전체 내용은 엑셀 보고서 참조)")
            break
        out.append(ln)
        used += need
    return "\n".join(out)


def merge(tbl, r, c1, c2):
    """셀 병합 후 병합으로 딸려 온 빈 단락 제거 (행 높이가 커지는 문제 방지)."""
    cell = tbl.cell(r, c1)
    cell.merge(tbl.cell(r, c2))
    tf = cell.text_frame
    for p in list(tf.paragraphs)[1:]:
        if not p.text.strip():
            p._p.getparent().remove(p._p)
