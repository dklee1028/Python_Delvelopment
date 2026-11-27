<?php
/* XLSX 작성기 (ZipArchive 로 OOXML 직접 작성) + 보고서 엑셀 버전 (PPT 와 같은 내용 + 원본 수치 전체) */

class XStyles {
    public $fonts = []; public $fills = []; public $borders = []; public $xfs = [];
    function __construct() {
        $this->font(10, false, '1F2937');
        $this->fills = ['<fill><patternFill patternType="none"/></fill>', '<fill><patternFill patternType="gray125"/></fill>'];
        $this->borders = ['<border><left/><right/><top/><bottom/><diagonal/></border>'];
        $this->xfs = ['<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'];
    }
    private function idx(&$arr, $x) { $k = array_search($x, $arr, true); if ($k === false) { $arr[] = $x; $k = count($arr) - 1; } return $k; }
    function font($size, $bold, $color) { return $this->idx($this->fonts, '<font>' . ($bold ? '<b/>' : '') . '<sz val="' . $size . '"/><color rgb="FF' . $color . '"/><name val="' . xe(FONT) . '"/><family val="2"/><charset val="129"/></font>'); }
    /* o: size,bold,color,fill,border,h,v,wrap,indent */
    function get($o) {
        $o += ['size' => 10, 'bold' => false, 'color' => '1F2937', 'fill' => null, 'border' => false, 'h' => null, 'v' => 'center', 'wrap' => false, 'indent' => 0];
        $f = $this->font($o['size'], $o['bold'], $o['color']);
        $fi = $o['fill'] ? $this->idx($this->fills, '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $o['fill'] . '"/><bgColor indexed="64"/></patternFill></fill>') : 0;
        $b = $o['border'] ? $this->idx($this->borders, '<border>' . implode('', array_map(function ($e) { return "<$e style=\"thin\"><color rgb=\"FF" . C_LINE . "\"/></$e>"; }, ['left', 'right', 'top', 'bottom'])) . '<diagonal/></border>') : 0;
        $al = '<alignment' . ($o['h'] ? ' horizontal="' . $o['h'] . '"' : '') . ' vertical="' . $o['v'] . '"' . ($o['wrap'] ? ' wrapText="1"' : '') . ($o['indent'] ? ' indent="' . $o['indent'] . '"' : '') . '/>';
        return $this->idx($this->xfs, '<xf numFmtId="0" fontId="' . $f . '" fillId="' . $fi . '" borderId="' . $b . '" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">' . $al . '</xf>');
    }
    function xml() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="' . count($this->fonts) . '">' . implode('', $this->fonts) . '</fonts><fills count="' . count($this->fills) . '">' . implode('', $this->fills) . '</fills>'
            . '<borders count="' . count($this->borders) . '">' . implode('', $this->borders) . '</borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($this->xfs) . '">' . implode('', $this->xfs) . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '<dxfs count="1"><dxf><font><b/><color rgb="FF' . C_RED . '"/></font></dxf></dxfs></styleSheet>';
    }
}
class XSheet {
    public $name; public $cells = []; public $merges = []; public $widths = []; public $heights = []; public $cf = []; public $image = null; public $landscape = false;
    function __construct($name) { $this->name = $name; }
    function set($r, $c, $v, $style = 0) { $this->cells[$r][$c] = [$v, $style]; }
    function style($r, $c, $style) { $v = $this->cells[$r][$c][0] ?? null; $this->cells[$r][$c] = [$v, $style]; }
}
function col_letter($c) { $s = ''; while ($c > 0) { $m = ($c - 1) % 26; $s = chr(65 + $m) . $s; $c = intdiv($c - 1, 26); } return $s; }
function clean_x($s) { return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$s); }

class Xlsx {
    public $sheets = []; public $st; public $title = '';
    function __construct() { $this->st = new XStyles(); }
    function sheet($name, $at = null) {
        $name = mb_substr(preg_replace('#[\\\\/*?:\[\]]#', '_', $name), 0, 31);
        $s = new XSheet($name);
        if ($at === null) $this->sheets[] = $s; else array_splice($this->sheets, $at, 0, [$s]);
        return $s;
    }
    function save($path) {
        $z = new ZipArchive();
        if ($z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new Exception('엑셀 파일을 만들 수 없습니다.');
        $n = count($this->sheets); $ov = ''; $wbr = []; $sh = '';
        $hasImg = false;
        foreach ($this->sheets as $i => $s) {
            $k = $i + 1;
            $ov .= '<Override PartName="/xl/worksheets/sheet' . $k . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            if ($s->image) { $ov .= '<Override PartName="/xl/drawings/drawing' . $k . '.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>'; $hasImg = true; }
            $wbr[] = ["rId$k", 'worksheet', "worksheets/sheet$k.xml"];
            $sh .= '<sheet name="' . xe($s->name) . '" sheetId="' . $k . '" r:id="rId' . $k . '"/>';
        }
        $wbr[] = ['rId' . ($n + 1), 'styles', 'styles.xml'];
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>' . $ov . '</Types>');
        $z->addFromString('_rels/.rels', rels_xml([['rId1', 'officeDocument', 'xl/workbook.xml'], ['rId2', 'package/2006/relationships/metadata/core-properties', 'docProps/core.xml'], ['rId3', 'extended-properties', 'docProps/app.xml']]));
        $z->addFromString('docProps/core.xml', core_xml($this->title ?: 'MRS Report'));
        $z->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>MRS</Application></Properties>');
        $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView/></bookViews><sheets>' . $sh . '</sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', rels_xml($wbr));
        foreach ($this->sheets as $i => $s) {
            $k = $i + 1;
            $z->addFromString("xl/worksheets/sheet$k.xml", $this->sheet_xml($s, $k));
            if ($s->image) {
                [$img, $col, $row, $hpx] = $s->image;
                $sz = getimagesize($img); $wpx = (int)round($hpx * $sz[0] / $sz[1]);
                $z->addFromString("xl/worksheets/_rels/sheet$k.xml.rels", rels_xml([['rId1', 'drawing', "../drawings/drawing$k.xml"]]));
                $z->addFromString("xl/drawings/drawing$k.xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                    . '<xdr:oneCellAnchor><xdr:from><xdr:col>' . ($col - 1) . '</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>' . ($row - 1) . '</xdr:row><xdr:rowOff>90000</xdr:rowOff></xdr:from><xdr:ext cx="' . ($wpx * 9525) . '" cy="' . ($hpx * 9525) . '"/>'
                    . '<xdr:pic><xdr:nvPicPr><xdr:cNvPr id="2" name="Logo"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr><xdr:blipFill><a:blip r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
                    . '<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . ($wpx * 9525) . '" cy="' . ($hpx * 9525) . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic><xdr:clientData/></xdr:oneCellAnchor></xdr:wsDr>');
                $z->addFromString("xl/drawings/_rels/drawing$k.xml.rels", rels_xml([['rId1', 'image', '../media/logo.png']]));
            }
        }
        if ($hasImg) $z->addFile(__DIR__ . '/../../assets/img/datanex_color.png', 'xl/media/logo.png');
        $z->addFromString('xl/styles.xml', $this->st->xml());
        if (!$z->close()) throw new Exception('엑셀 파일 저장에 실패했습니다.');
    }
    private function sheet_xml($s, $k) {
        $cols = '';
        if ($s->widths) { $cols = '<cols>'; foreach ($s->widths as $c => $w) $cols .= '<col min="' . $c . '" max="' . $c . '" width="' . $w . '" customWidth="1"/>'; $cols .= '</cols>'; }
        ksort($s->cells); $rows = '';
        $allRows = array_unique(array_merge(array_keys($s->cells), array_keys($s->heights))); sort($allRows);
        foreach ($allRows as $r) {
            $cells = $s->cells[$r] ?? []; ksort($cells);
            $rows .= '<row r="' . $r . '"' . (isset($s->heights[$r]) ? ' ht="' . $s->heights[$r] . '" customHeight="1"' : '') . '>';
            foreach ($cells as $c => [$v, $st]) {
                $ref = col_letter($c) . $r; $sa = $st ? ' s="' . $st . '"' : '';
                if ($v === null || $v === '') $rows .= '<c r="' . $ref . '"' . $sa . '/>';
                elseif (is_int($v) || is_float($v)) $rows .= '<c r="' . $ref . '"' . $sa . '><v>' . $v . '</v></c>';
                else $rows .= '<c r="' . $ref . '"' . $sa . ' t="inlineStr"><is><t xml:space="preserve">' . xe(clean_x($v)) . '</t></is></c>';
            }
            $rows .= '</row>';
        }
        $mg = $s->merges ? '<mergeCells count="' . count($s->merges) . '">' . implode('', array_map(function ($m) { return '<mergeCell ref="' . $m . '"/>'; }, $s->merges)) . '</mergeCells>' : '';
        $cf = ''; $p = 1;
        foreach ($s->cf as [$ref, $kind]) {
            if ($kind === 'databar') $cf .= '<conditionalFormatting sqref="' . $ref . '"><cfRule type="dataBar" priority="' . $p++ . '"><dataBar><cfvo type="num" val="0"/><cfvo type="num" val="100"/><color rgb="FF' . C_BLUE . '"/></dataBar></cfRule></conditionalFormatting>';
            else $cf .= '<conditionalFormatting sqref="' . $ref . '"><cfRule type="cellIs" dxfId="0" priority="' . $p++ . '" operator="greaterThanOrEqual"><formula>95</formula></cfRule></conditionalFormatting>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><sheetViews><sheetView workbookViewId="0" showGridLines="0"' . ($k === 1 ? ' tabSelected="1"' : '') . '/></sheetViews><sheetFormatPr defaultRowHeight="16.5"/>'
            . $cols . '<sheetData>' . $rows . '</sheetData>' . $mg . $cf
            . '<printOptions horizontalCentered="1"/><pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/><pageSetup paperSize="9" orientation="' . ($s->landscape ? 'landscape' : 'portrait') . '" fitToWidth="1" fitToHeight="0"/>'
            . ($s->image ? '<drawing r:id="rId1"/>' : '') . '</worksheet>';
    }
}

/* ---------- 보고서 엑셀 ---------- */
function x_title($x, $ws, $title, $sub, $wc = 8) {
    $ws->merges[] = "A1:" . col_letter($wc) . "1"; $ws->merges[] = "A2:" . col_letter($wc) . "2";
    $navy = $x->st->get(['fill' => C_LIGHT]);
    for ($r = 1; $r <= 2; $r++) for ($c = 1; $c <= $wc; $c++) $ws->set($r, $c, null, $navy);
    $ws->set(1, 1, $title, $x->st->get(['size' => 18, 'bold' => true, 'color' => C_NAVY, 'fill' => C_LIGHT, 'indent' => 1]));
    $ws->set(2, 1, $sub, $x->st->get(['size' => 9, 'color' => C_GREY, 'fill' => C_LIGHT, 'indent' => 1]));
    $ws->heights[1] = 36; $ws->heights[2] = 20;
    $blue = $x->st->get(['fill' => C_BLUE]); for ($c = 1; $c <= $wc; $c++) $ws->set(3, $c, null, $blue);
    $ws->heights[3] = 2;
    return 5;
}
function x_section($x, $ws, $r, $text) { $ws->set($r, 1, '▌ ' . $text, $x->st->get(['size' => 12, 'bold' => true, 'color' => C_NAVY])); $ws->heights[$r] = 22; return $r + 1; }
function x_table($x, $ws, $r, $rows, $o = []) {
    $o += ['header' => true, 'label_cols' => [], 'status_col' => null, 'tint' => null];
    foreach ($rows as $i => $row) {
        foreach (array_values($row) as $j => $v) {
            $head = $o['header'] && $i === 0;
            $long = is_string($v) && mb_strlen($v) >= 25;
            $st = ['size' => 9.5, 'border' => true, 'wrap' => true, 'h' => ($head || !$long) ? 'center' : null];
            if ($head) $st += ['fill' => C_HEAD, 'bold' => true, 'color' => C_NAVY];
            elseif (in_array($j, $o['label_cols'], true)) $st += ['fill' => C_LIGHT, 'bold' => true, 'color' => C_NAVY];
            if (!$head && $o['status_col'] === $j && ($t = status_tint($v))) { $st['color'] = $t; $st['bold'] = true; }
            if (!$head && $o['tint'] && ($t = ($o['tint'])($j, $v))) { $st['color'] = $t; $st['bold'] = true; }
            $ws->set($r + $i, $j + 1, $v, $x->st->get($st));
        }
    }
    return $r + count($rows) + 1;
}
function x_note($x, $ws, $r, $text, $fill = null, $wc = 8) {
    $ws->merges[] = "A$r:" . col_letter($wc) . $r;
    $st = $x->st->get(['size' => 10, 'wrap' => true, 'v' => 'top', 'fill' => $fill]);
    for ($c = 1; $c <= $wc; $c++) $ws->set($r, $c, $c === 1 ? $text : null, $st);
    $ws->heights[$r] = min(409, 15 * (substr_count($text, "\n") + 2 + intdiv(mb_strlen($text), 90)));
    return $r + 2;
}
function x_summary($x, $ctx) {
    $ws = $x->sheet('요약');
    $r = x_title($x, $ws, $ctx['report']['title'], "{$ctx['report']['type_label']} · No. {$ctx['report']['no']} · {$ctx['report']['date']}");
    $ws->widths = [1 => 16, 20, 18, 24, 14, 14, 12, 12];
    $ws->image = [__DIR__ . '/../../assets/img/datanex_color.png', 7, 1, 26];
    $c = $ctx['customer'];
    $r = x_section($x, $ws, $r, '고객 정보 (Customer Info)');
    $r = x_table($x, $ws, $r, [
        ['Customer', $c['name'], 'Date', $ctx['report']['date']],
        ['Location', $c['site'] ?: $c['address'], 'Contact', implode(' / ', array_filter([$c['contact_name'], $c['contact_phone'], $c['contact_email']]))],
        ['판정', $ctx['report']['overall_label'], '보고서 유형', $ctx['report']['type_label'] . ($ctx['report']['subtype_label'] ? ' · ' . $ctx['report']['subtype_label'] : '')],
    ], ['header' => false, 'label_cols' => [0, 2], 'status_col' => 1]);
    $r = x_section($x, $ws, $r, '점검 장비 정보 (Equipment Info)');
    $start = $r; $rows = [['No.', 'System', 'Model', 'System S/N', 'OS Ver', 'Disk', 'Status', 'Health']];
    foreach ($ctx['equipments'] as $e) $rows[] = [$e['seq'], $e['name'], $e['model'], $e['serial'], $e['os_version'], $e['disk_info'], STATUS_TEXT[$e['analysis']['status']], $e['analysis']['score']];
    $r = x_table($x, $ws, $r, $rows, ['status_col' => 6]);
    $ws->cf[] = ['H' . ($start + 1) . ':H' . ($start + count($rows) - 1), 'databar'];
    if (count($ctx['equipments']) >= 2) {
        $r = x_section($x, $ws, $r, '장비별 Data 용량 사용률 / Health Score');
        $s0 = $r; $rows = [['System', 'Data 사용률(%)', 'Health Score', '판정']];
        foreach ($ctx['equipments'] as $e) $rows[] = ['#' . $e['seq'] . ' ' . $e['model'], $e['analysis']['capacity']['pct'], $e['analysis']['score'], STATUS_TEXT[$e['analysis']['status']]];
        $r = x_table($x, $ws, $r, $rows, ['status_col' => 3]);
        $ws->cf[] = ['B' . ($s0 + 1) . ':C' . ($s0 + count($rows) - 1), 'databar'];
    }
    $r = x_section($x, $ws, $r, '장애 및 진단 의견 (엔지니어 검토 완료)');
    $r = x_note($x, $ws, $r, $ctx['diagnosis']);
    $r = x_section($x, $ws, $r, '엔지니어 최종 Comment');
    $r = x_note($x, $ws, $r, $ctx['final_comment'], C_LIGHT);
    $eng = $ctx['engineer'];
    x_table($x, $ws, $r, [['회사명', $ctx['company']['name'], '연락처', $eng['phone'] ?: ($ctx['company']['tel'] ?? '')],
        ['점검자', trim($eng['name'] . ' ' . $eng['title']) . ' (서명)', '이메일', $eng['email'] ?: ($ctx['company']['email'] ?? '')],
        ['회사 주소', $ctx['company']['address'], '고객확인', '(서명)']], ['header' => false, 'label_cols' => [0, 2]]);
}
function x_system($x, $ctx, $e) {
    $ws = $x->sheet("System {$e['seq']}");
    $r = x_title($x, $ws, "System {$e['seq']} · {$e['vendor']} {$e['model']}", "S/N {$e['serial']} · OS {$e['os_version']} · {$e['analysis']['status_label']} · Health {$e['analysis']['score']}");
    $ws->widths = [1 => 16, 22, 14, 14, 11, 11, 14, 16];
    $r = x_section($x, $ws, $r, 'Aggregate 현황'); $a0 = $r;
    $rows = [['Node', 'Aggr Name', 'Total(GB)', 'Used(GB)', 'Used(%)', 'Avail(GB)', 'State']];
    foreach ($e['parsed']['aggregates'] ?? [] as $a) $rows[] = [$a['node'] ?? '', $a['name'], round($a['total_gb'], 1), round($a['used_gb'], 1), (int)$a['pct'], round($a['avail_gb'], 1), $a['state'] ?? ''];
    $r = x_table($x, $ws, $r, $rows);
    if (count($rows) > 1) { $ref = 'E' . ($a0 + 1) . ':E' . ($a0 + count($rows) - 1); $ws->cf[] = [$ref, 'databar']; $ws->cf[] = [$ref, 'ge95']; }
    $r = x_section($x, $ws, $r, 'Volume 현황 (전체)'); $v0 = $r;
    $rows = [['Node/SVM', 'Volume', 'Total(GB)', 'Used(GB)', 'Used(%)', 'Inode(%)', 'Remarks', 'Aggregate']];
    foreach ($e['parsed']['volumes'] ?? [] as $v) $rows[] = [$v['node'] ?? '', $v['name'], round($v['total_gb'], 1), round($v['used_gb'], 1), (int)$v['pct'], isset($v['inode_pct']) ? (int)$v['inode_pct'] : '-', vol_remark($v['pct']), $v['aggr'] ?? ''];
    $r = x_table($x, $ws, $r, $rows, ['status_col' => 6]);
    if (count($rows) > 1) $ws->cf[] = ['E' . ($v0 + 1) . ':E' . ($v0 + count($rows) - 1), 'databar'];
    $r = x_section($x, $ws, $r, '로그 분석 진단 (Diagnostic Findings)');
    $rows = [['등급', '구분', '진단', '상세', '권고 조치']];
    foreach ($e['analysis']['findings'] as $f) $rows[] = [STATUS_TEXT[$f['severity']], $f['category'], $f['title'], $f['detail'], $f['recommendation']];
    if (count($rows) === 1) $rows[] = ['Normal', '-', '주요 이상 징후 없음', '', '정기 모니터링 유지'];
    $r = x_table($x, $ws, $r, $rows, ['status_col' => 0]);
    $top = $e['parsed']['events']['top'] ?? [];
    if ($top) {
        $r = x_section($x, $ws, $r, '주요 이벤트 (Top Events)');
        $rows = [['Severity', 'Event', 'Count']]; foreach ($top as $t) $rows[] = [strtoupper($t['severity']), $t['event'], (int)$t['count']];
        $r = x_table($x, $ws, $r, $rows, ['tint' => function ($j, $v) { return $j === 0 ? (in_array($v, ['EMERGENCY', 'ALERT'], true) ? C_RED : C_AMBER) : null; }]);
    }
    if (!empty($e['parsed']['files'])) $ws->set($r, 1, '분석 로그: ' . implode(', ', $e['parsed']['files']), $x->st->get(['size' => 8, 'color' => C_GREY]));
}
function x_visual($x, $ctx) {
    $eqs = $ctx['equipments']; $n = count($eqs);
    $ws = $x->sheet('육안점검');
    $r = x_title($x, $ws, '주요 점검 항목 – 육안 점검', '엔지니어 최종 선택 결과', 2 + $n);
    $ws->widths = [1 => 28]; for ($i = 0; $i < $n; $i++) $ws->widths[2 + $i] = 14; $ws->widths[2 + $n] = 30;
    $rows = [array_merge(['Inspection Item'], array_map(function ($e) { return "#{$e['seq']} {$e['model']}"; }, $eqs))];
    foreach (VISUAL_ITEMS as [$k, $label]) { $row = [$label]; foreach ($eqs as $e) $row[] = $e['visual'][$k] ?? '-'; $rows[] = $row; }
    $r = x_table($x, $ws, $r, $rows, ['label_cols' => [0], 'tint' => function ($j, $v) { return $j > 0 ? ($v === '비정상' ? C_RED : ($v === '정상' ? C_GREEN : null)) : null; }]);
    $r = x_section($x, $ws, $r, '로그 기반 자동 점검 매트릭스');
    $rows = [array_merge(['Category', 'Inspection Item'], array_map(function ($e) { return '#' . $e['seq']; }, $eqs), ['Standard / Criteria'])];
    foreach (INSPECTION_MATRIX as [$cat, $item, $crit]) { $row = [$cat, $item]; foreach ($eqs as $e) $row[] = $e['checks'][$item] ?? '-'; $row[] = $crit; $rows[] = $row; }
    x_table($x, $ws, $r, $rows, ['label_cols' => [0], 'tint' => function ($j, $v) { return ['✔' => C_GREEN, '!' => C_AMBER, '✖' => C_RED][$v] ?? null; }]);
}
function x_workplan($x, $ctx) {
    $ws = $x->sheet('세부작업계획', 1); $ws->landscape = true; $plan = $ctx['plan'];
    $r = x_title($x, $ws, $ctx['plan_title'], "{$ctx['customer']['name']} · {$ctx['report']['subtype_label']} · " . ($plan['schedule'] ?? ''), 7);
    $ws->widths = [1 => 6, 20, 24, 60, 18, 10, 22];
    $r = x_table($x, $ws, $r, [
        ['고객사', $ctx['customer']['name'], '', '작업 장소', $plan['location'] ?? '', '', ''],
        ['작업 일정', $plan['schedule'] ?? '', '', '작업 목적', $plan['purpose'] ?? '', '', ''],
        ['대상 장비', implode(', ', array_map(function ($e) { return "{$e['model']}({$e['name']})"; }, $ctx['equipments'])), '', '목표 버전/부품', $plan['target'] ?? '', '', ''],
    ], ['header' => false, 'label_cols' => [0, 3]]);
    $eng = $ctx['engineer']; $who = $ctx['company']['name'] . "\n" . trim($eng['name'] . ' ' . $eng['title']);
    $rows = [['No', 'Type(단계)', 'Activity', '상세 작업 내용', 'Who', 'Time(분)', '서비스 영향/비고']]; $tot = 0;
    foreach ($plan['steps'] ?? [] as $i => $s) { $m = (int)($s['minutes'] ?? 0); $tot += $m; $rows[] = [$s['no'] ?? $i + 1, $s['phase'] ?? '', $s['activity'] ?? '', $s['detail'] ?? '', ($s['who'] ?? '') ?: $who, $m, $s['impact'] ?? '']; }
    $rows[] = ['', '', '', '총 소요시간', '', $tot, ''];
    $r = x_table($x, $ws, $r, $rows, ['tint' => function ($j, $v) { return ($j === 6 && is_string($v) && $v !== '' && mb_strpos($v, '없음') === false) ? C_RED : null; }]);
    $r = x_section($x, $ws, $r, '롤백 계획');
    x_table($x, $ws, $r, [['롤백 기준', ($plan['rollback_criteria'] ?? '') ?: '작업 단계별 정상 확인 실패, 서비스 영향 허용 시간 초과 시'],
        ['롤백 절차', $plan['rollback'] ?? ''], ['의사 결정', ($plan['decision_maker'] ?? '') ?: '고객사 담당자와 협의 후 결정']], ['header' => false, 'label_cols' => [0]]);
    for ($k = 0; $k < 3; $k++) { $ws->merges[] = 'B' . ($r + $k) . ':G' . ($r + $k); $ws->heights[$r + $k] = 32; }
}
function build_xlsx($ctx, $path) {
    $x = new Xlsx(); $x->title = $ctx['report']['title'];
    x_summary($x, $ctx);
    if ($ctx['report']['type'] === 'workplan') x_workplan($x, $ctx);
    foreach ($ctx['equipments'] as $e) x_system($x, $ctx, $e);
    x_visual($x, $ctx);
    $x->save($path);
}
