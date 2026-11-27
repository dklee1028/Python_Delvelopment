<?php
/* PPTX 작성기 (외부 라이브러리 없이 ZipArchive 로 OOXML 을 직접 씁니다) + DATANEX 브랜드 그리기 도구.
   좌표·크기 단위는 EMU (1cm = 360000, 1pt = 12700). */

const FONT = '맑은 고딕';
const C_BLUE = '1969A5'; const C_BLACK = '040000'; const C_NAVY = '0B2E4F'; const C_INK = '1F2937'; const C_GREY = '6B7785';
const C_LINE = 'D5DEE8'; const C_LIGHT = 'EEF4FA'; const C_WHITE = 'FFFFFF'; const C_RED = 'D64545'; const C_AMBER = 'E09A2B';
const C_GREEN = '2E9E6A'; const C_SKY = 'B9D3EA'; const C_ZEBRA = 'F8FAFC'; const C_TRACK = 'E6ECF2';
const STATUS_COLOR = ['critical' => C_RED, 'warning' => C_AMBER, 'normal' => C_GREEN, 'info' => C_BLUE];
const STATUS_TEXT = ['critical' => 'Critical', 'warning' => 'Warning', 'normal' => 'Normal', 'info' => 'Info'];

function cm($v) { return (int)round($v * 360000); }
function xe($s) { return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
function clip($s, $n) { $s = str_replace("\n", ' ', (string)$s); return mb_strlen($s) <= $n ? $s : mb_substr($s, 0, $n - 1) . '…'; }
function fit_lines($v, $max, $chars) {
    $out = []; $used = 0; $chars = max(1, $chars);
    foreach (explode("\n", (string)$v) as $ln) {
        $need = max(1, (int)ceil(mb_strlen($ln) / $chars));
        if ($used + $need > $max) { $out[] = '… (이하 생략, 전체 내용은 엑셀 보고서 참조)'; break; }
        $out[] = $ln; $used += $need;
    }
    return implode("\n", $out);
}

class Pptx {
    public $w; public $h; public $slides = []; public $media = [];
    function __construct($w, $h) { $this->w = (int)$w; $this->h = (int)$h; }
    function slide() { $s = new PSlide($this); $this->slides[] = $s; return $s; }
    function media($path) {
        if (!isset($this->media[$path])) $this->media[$path] = 'image' . (count($this->media) + 1) . '.' . strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return $this->media[$path];
    }
    function save($path) {
        $z = new ZipArchive();
        if ($z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new Exception('PPT 파일을 만들 수 없습니다.');
        $n = count($this->slides);
        $ov = '';
        for ($i = 1; $i <= $n; $i++) $ov .= '<Override PartName="/ppt/slides/slide' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>';
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Default Extension="png" ContentType="image/png"/><Default Extension="jpg" ContentType="image/jpeg"/><Default Extension="jpeg" ContentType="image/jpeg"/>'
            . '<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
            . '<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>'
            . '<Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>'
            . '<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
            . '<Override PartName="/ppt/presProps.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presProps+xml"/>'
            . '<Override PartName="/ppt/viewProps.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.viewProps+xml"/>'
            . '<Override PartName="/ppt/tableStyles.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.tableStyles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>' . $ov . '</Types>');
        $z->addFromString('_rels/.rels', rels_xml([['rId1', 'officeDocument', 'ppt/presentation.xml'], ['rId2', 'package/2006/relationships/metadata/core-properties', 'docProps/core.xml'],
            ['rId3', 'extended-properties', 'docProps/app.xml']]));
        $z->addFromString('docProps/core.xml', core_xml('MRS Report'));
        $z->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>MRS</Application><Slides>' . $n . '</Slides></Properties>');
        $ids = ''; $prels = [['rId1', 'slideMaster', 'slideMasters/slideMaster1.xml'], ['rId2', 'theme', 'theme/theme1.xml'], ['rId3', 'presProps', 'presProps.xml'],
            ['rId4', 'viewProps', 'viewProps.xml'], ['rId5', 'tableStyles', 'tableStyles.xml']];
        for ($i = 1; $i <= $n; $i++) { $ids .= '<p:sldId id="' . (255 + $i) . '" r:id="rId' . (10 + $i) . '"/>'; $prels[] = ['rId' . (10 + $i), 'slide', "slides/slide$i.xml"]; }
        $z->addFromString('ppt/presentation.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:presentation ' . NS_P . ' saveSubsetFonts="1">'
            . '<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst><p:sldIdLst>' . $ids . '</p:sldIdLst>'
            . '<p:sldSz cx="' . $this->w . '" cy="' . $this->h . '"/><p:notesSz cx="6858000" cy="9144000"/><p:defaultTextStyle><a:defPPr><a:defRPr lang="ko-KR"/></a:defPPr></p:defaultTextStyle></p:presentation>');
        $z->addFromString('ppt/_rels/presentation.xml.rels', rels_xml($prels));
        $z->addFromString('ppt/presProps.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:presentationPr ' . NS_P . '/>');
        $z->addFromString('ppt/viewProps.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:viewPr ' . NS_P . '><p:normalViewPr><p:restoredLeft sz="15620"/><p:restoredTop sz="94660"/></p:normalViewPr><p:gridSpacing cx="72008" cy="72008"/></p:viewPr>');
        $z->addFromString('ppt/tableStyles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><a:tblStyleLst xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" def="{5C22544A-7EE6-4342-B048-85BDC9FD1C3A}"/>');
        $z->addFromString('ppt/theme/theme1.xml', theme_xml());
        $z->addFromString('ppt/slideMasters/slideMaster1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:sldMaster ' . NS_P . '><p:cSld><p:bg><p:bgRef idx="1001"><a:schemeClr val="bg1"/></p:bgRef></p:bg>'
            . '<p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/></p:spTree></p:cSld>'
            . '<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>'
            . '<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst><p:txStyles><p:titleStyle><a:lvl1pPr><a:defRPr sz="3200"/></a:lvl1pPr></p:titleStyle><p:bodyStyle><a:lvl1pPr><a:defRPr sz="1800"/></a:lvl1pPr></p:bodyStyle><p:otherStyle><a:lvl1pPr><a:defRPr sz="1800"/></a:lvl1pPr></p:otherStyle></p:txStyles></p:sldMaster>');
        $z->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels', rels_xml([['rId1', 'slideLayout', '../slideLayouts/slideLayout1.xml'], ['rId2', 'theme', '../theme/theme1.xml']]));
        $z->addFromString('ppt/slideLayouts/slideLayout1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:sldLayout ' . NS_P . ' type="blank" preserve="1"><p:cSld name="Blank"><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/></p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sldLayout>');
        $z->addFromString('ppt/slideLayouts/_rels/slideLayout1.xml.rels', rels_xml([['rId1', 'slideMaster', '../slideMasters/slideMaster1.xml']]));
        foreach ($this->slides as $i => $s) {
            $k = $i + 1;
            $z->addFromString("ppt/slides/slide$k.xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:sld ' . NS_P . '><p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
                . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>' . implode('', $s->xml) . '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>');
            $rels = [['rId1', 'slideLayout', '../slideLayouts/slideLayout1.xml']];
            foreach ($s->rels as $rid => $target) $rels[] = [$rid, 'image', $target];
            $z->addFromString("ppt/slides/_rels/slide$k.xml.rels", rels_xml($rels));
        }
        foreach ($this->media as $src => $name) $z->addFile($src, "ppt/media/$name");
        if (!$z->close()) throw new Exception('PPT 파일 저장에 실패했습니다.');
    }
}
const NS_P = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"';

function rels_xml($rows) {
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    foreach ($rows as [$id, $type, $target]) {
        $t = strpos($type, '/') !== false ? 'http://schemas.openxmlformats.org/' . $type : 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/' . $type;
        $x .= '<Relationship Id="' . $id . '" Type="' . $t . '" Target="' . xe($target) . '"/>';
    }
    return $x . '</Relationships>';
}
function core_xml($title) {
    $d = gmdate('Y-m-d\TH:i:s\Z');
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>' . xe($title) . '</dc:title><dc:creator>MRS</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . $d . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $d . '</dcterms:modified></cp:coreProperties>';
}
function theme_xml() {
    $f = xe(FONT);
    $fill = '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>';
    $ln = '<a:ln w="9525"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln>';
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="DATANEX"><a:themeElements>'
        . '<a:clrScheme name="DATANEX"><a:dk1><a:srgbClr val="' . C_INK . '"/></a:dk1><a:lt1><a:srgbClr val="FFFFFF"/></a:lt1><a:dk2><a:srgbClr val="' . C_NAVY . '"/></a:dk2><a:lt2><a:srgbClr val="' . C_LIGHT . '"/></a:lt2>'
        . '<a:accent1><a:srgbClr val="' . C_BLUE . '"/></a:accent1><a:accent2><a:srgbClr val="' . C_NAVY . '"/></a:accent2><a:accent3><a:srgbClr val="' . C_GREEN . '"/></a:accent3>'
        . '<a:accent4><a:srgbClr val="' . C_AMBER . '"/></a:accent4><a:accent5><a:srgbClr val="' . C_RED . '"/></a:accent5><a:accent6><a:srgbClr val="' . C_GREY . '"/></a:accent6>'
        . '<a:hlink><a:srgbClr val="' . C_BLUE . '"/></a:hlink><a:folHlink><a:srgbClr val="' . C_NAVY . '"/></a:folHlink></a:clrScheme>'
        . '<a:fontScheme name="DATANEX"><a:majorFont><a:latin typeface="' . $f . '"/><a:ea typeface="' . $f . '"/><a:cs typeface=""/></a:majorFont><a:minorFont><a:latin typeface="' . $f . '"/><a:ea typeface="' . $f . '"/><a:cs typeface=""/></a:minorFont></a:fontScheme>'
        . '<a:fmtScheme name="DATANEX"><a:fillStyleLst>' . $fill . $fill . $fill . '</a:fillStyleLst><a:lnStyleLst>' . $ln . $ln . $ln . '</a:lnStyleLst>'
        . '<a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst>'
        . '<a:bgFillStyleLst>' . $fill . $fill . $fill . '</a:bgFillStyleLst></a:fmtScheme></a:themeElements><a:objectDefaults/><a:extraClrSchemeLst/></a:theme>';
}

class PSlide {
    public $p; public $xml = []; public $rels = []; private $id = 1;
    function __construct($p) { $this->p = $p; }
    function nid() { return ++$this->id; }
    function add($x) { $this->xml[] = $x; }
}

/* ---------- 텍스트 XML ---------- */
function run_xml($t, $size, $bold, $color) {
    $sz = (int)round($size * 100);
    return '<a:r><a:rPr lang="ko-KR" altLang="en-US" sz="' . $sz . '" b="' . ($bold ? 1 : 0) . '" dirty="0"><a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill>'
        . '<a:latin typeface="' . xe(FONT) . '"/><a:ea typeface="' . xe(FONT) . '"/><a:cs typeface="' . xe(FONT) . '"/></a:rPr><a:t>' . xe($t) . '</a:t></a:r>';
}
function para_xml($t, $size, $bold, $color, $align = 'l', $ls = 1.15) {
    $al = ['l' => 'l', 'c' => 'ctr', 'r' => 'r'][$align] ?? 'l';
    $p = '<a:p><a:pPr algn="' . $al . '"><a:lnSpc><a:spcPct val="' . (int)round($ls * 100000) . '"/></a:lnSpc></a:pPr>';
    $p .= ($t === '' ? '' : run_xml($t, $size, $bold, $color));
    return $p . '<a:endParaRPr lang="ko-KR" sz="' . (int)round($size * 100) . '"><a:latin typeface="' . xe(FONT) . '"/><a:ea typeface="' . xe(FONT) . '"/></a:endParaRPr></a:p>';
}
function xfrm($x, $y, $w, $h) { return '<a:xfrm><a:off x="' . (int)$x . '" y="' . (int)$y . '"/><a:ext cx="' . max(0, (int)$w) . '" cy="' . max(0, (int)$h) . '"/></a:xfrm>'; }
function anchor_of($a) { return ['t' => 't', 'm' => 'ctr', 'b' => 'b'][$a] ?? 't'; }

/* ---------- 도형 ---------- */
function k_text($s, $x, $y, $w, $h, $value, $size = 10, $bold = false, $color = C_INK, $align = 'l', $anchor = 't', $ls = 1.15) {
    $id = $s->nid(); $ps = '';
    foreach (explode("\n", (string)$value) as $ln) {
        $b = $bold || (mb_substr($ln, 0, 1) === '[' && mb_substr(rtrim($ln), -1) === ']');
        $ps .= para_xml($ln, $size, $b, $color, $align, $ls);
    }
    $s->add('<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="Text ' . $id . '"/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr><p:spPr>' . xfrm($x, $y, $w, $h)
        . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/></p:spPr><p:txBody><a:bodyPr wrap="square" lIns="36000" tIns="18000" rIns="36000" bIns="18000" anchor="' . anchor_of($anchor) . '"><a:noAutofit/></a:bodyPr><a:lstStyle/>'
        . $ps . '</p:txBody></p:sp>');
}
function k_rect($s, $x, $y, $w, $h, $fill = C_LIGHT, $line = null, $shape = 'rect', $radius = null, $inner = '') {
    $id = $s->nid();
    $av = $radius !== null ? '<a:gd name="adj" fmla="val ' . (int)round($radius * 50000) . '"/>' : '';
    $ln = $line === null ? '<a:ln><a:noFill/></a:ln>' : '<a:ln w="9525"><a:solidFill><a:srgbClr val="' . $line . '"/></a:solidFill></a:ln>';
    $fl = $fill === null ? '<a:noFill/>' : '<a:solidFill><a:srgbClr val="' . $fill . '"/></a:solidFill>';
    $s->add('<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="Shape ' . $id . '"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr><p:spPr>' . xfrm($x, $y, $w, $h)
        . '<a:prstGeom prst="' . $shape . '"><a:avLst>' . $av . '</a:avLst></a:prstGeom>' . $fl . $ln . '</p:spPr>'
        . ($inner ?: '<p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:endParaRPr lang="ko-KR" sz="100"/></a:p></p:txBody>') . '</p:sp>');
}
function k_pill($s, $x, $y, $w, $h, $label, $fill, $color = C_WHITE, $size = 9) {
    $inner = '<p:txBody><a:bodyPr wrap="square" lIns="18000" tIns="0" rIns="18000" bIns="0" anchor="ctr"><a:noAutofit/></a:bodyPr><a:lstStyle/>' . para_xml($label, $size, true, $color, 'c', 1.0) . '</p:txBody>';
    k_rect($s, $x, $y, $w, $h, $fill, null, 'roundRect', 1.0, $inner);
}
function k_line($s, $x1, $y1, $x2, $y2, $color = C_LINE, $wpt = 0.75) {
    $id = $s->nid();
    $s->add('<p:cxnSp><p:nvCxnSpPr><p:cNvPr id="' . $id . '" name="Line ' . $id . '"/><p:cNvCxnSpPr/><p:nvPr/></p:nvCxnSpPr><p:spPr>' . xfrm($x1, $y1, $x2 - $x1, $y2 - $y1)
        . '<a:prstGeom prst="line"><a:avLst/></a:prstGeom><a:ln w="' . (int)round($wpt * 12700) . '"><a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill></a:ln></p:spPr></p:cxnSp>');
}
function k_image($s, $path, $x, $y, $h) {
    if (!is_file($path)) return;
    $sz = @getimagesize($path); if (!$sz) return;
    $w = (int)round($h * $sz[0] / $sz[1]);
    $name = $s->p->media($path); $rid = 'rId' . (count($s->rels) + 2); $s->rels[$rid] = "../media/$name";
    $id = $s->nid();
    $s->add('<p:pic><p:nvPicPr><p:cNvPr id="' . $id . '" name="Picture ' . $id . '"/><p:cNvPicPr><a:picLocks noChangeAspect="1"/></p:cNvPicPr><p:nvPr/></p:nvPicPr>'
        . '<p:blipFill><a:blip r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></p:blipFill><p:spPr>' . xfrm($x, $y, $w, $h) . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr></p:pic>');
}
function k_logo($s, $x, $y, $h) { k_image($s, __DIR__ . '/../../assets/img/datanex_color.png', $x, $y, $h); }

/* 좌측 블루 바 + 제목 + 얇은 구분선 (샘플 보고서의 섹션 헤더 스타일) */
function k_section($s, $x, $y, $w, $title, $size = 12) {
    k_rect($s, $x, $y + cm(0.08), cm(0.18), cm(0.55), C_BLUE);
    k_text($s, $x + cm(0.35), $y, $w - cm(0.35), cm(0.7), $title, $size, true, C_NAVY, 'l', 'm');
    k_line($s, $x, $y + cm(0.78), $x + $w, $y + cm(0.78));
    return $y + cm(0.95);
}

function text_pt($str, $size) { $t = 0; foreach (preg_split('//u', (string)$str, -1, PREG_SPLIT_NO_EMPTY) as $ch) $t += $size * (mb_ord($ch) > 0x2E80 ? 1.0 : 0.56); return $t; }
function row_need($val, $wemu, $size) {
    $avail = max($wemu / 12700 - 9, 10); $lines = 0;
    foreach (explode("\n", (string)$val) as $part) $lines += max(1, (int)ceil(text_pt($part, $size) / $avail));
    return (int)round($lines * $size * 1.32 * 12700 + cm(0.2));
}
function status_tint($val) {
    $k = mb_strtolower((string)$val);
    foreach ([['critical', C_RED], ['check', C_RED], ['비정상', C_RED], ['위험', C_RED], ['warning', C_AMBER], ['monitor', C_AMBER], ['요주의', C_AMBER], ['normal', C_GREEN], ['정상', C_GREEN]] as [$w, $c])
        if (mb_strpos($k, $w) !== false) return $c;
    return null;
}

/* 표. rows: 문자열 또는 ['v'=>값, 'span'=>열수, 'color'=>, 'bold'=>] 셀의 2차원 배열. 반환: 표 하단 y
   opts: col_w, header(true), size(8.5), row_h, header_fill, zebra(true), align(['c','l',..]), label_cols([]), status_col, tint(fn) */
function k_table($s, $x, $y, $w, $rows, $o = []) {
    $o += ['col_w' => null, 'header' => true, 'size' => 8.5, 'row_h' => cm(0.62), 'header_fill' => C_NAVY, 'zebra' => true, 'align' => null, 'label_cols' => [], 'status_col' => null, 'tint' => null];
    $nc = 0; foreach ($rows as $r) { $c = 0; foreach ($r as $cell) $c += is_array($cell) ? ($cell['span'] ?? 1) : 1; $nc = max($nc, $c); }
    $cw = $o['col_w'] ?: array_fill(0, $nc, 1); $tot = array_sum($cw);
    $widths = []; for ($i = 0; $i < $nc; $i++) $widths[] = (int)($w * ($cw[$i] ?? 1) / $tot);
    $grid = ''; foreach ($widths as $cwv) $grid .= '<a:gridCol w="' . $cwv . '"/>';
    $trs = ''; $total = 0; $size = $o['size'];
    foreach ($rows as $ri => $r) {
        $cells = []; $ci = 0;
        foreach ($r as $cell) { $sp = is_array($cell) ? ($cell['span'] ?? 1) : 1; $cells[] = [$ci, $sp, $cell]; $ci += $sp; }
        while ($ci < $nc) { $cells[] = [$ci, 1, '']; $ci++; }
        $need = $o['row_h'];
        foreach ($cells as [$c, $sp, $cell]) { $cwid = array_sum(array_slice($widths, $c, $sp)); $need = max($need, row_need(is_array($cell) ? ($cell['v'] ?? '') : $cell, $cwid, $size)); }
        $total += $need;
        $tcs = '';
        foreach ($cells as [$c, $sp, $cell]) {
            $v = is_array($cell) ? ($cell['v'] ?? '') : $cell; $v = ($v === null || (string)$v === '') ? ' ' : (string)$v;
            $head = $o['header'] && $ri === 0; $color = C_INK; $bold = false;
            if ($head) { $fill = $o['header_fill']; $color = C_WHITE; $bold = true; }
            elseif (in_array($c, $o['label_cols'], true)) { $fill = C_LIGHT; $color = C_NAVY; $bold = true; }
            else $fill = ($o['zebra'] && $ri % 2 === 0) ? C_ZEBRA : C_WHITE;
            if (!$head && $o['status_col'] !== null && $c === $o['status_col'] && ($t = status_tint($v))) { $color = $t; $bold = true; }
            if (!$head && $o['tint'] && ($t = ($o['tint'])($ri, $c, $v))) { $color = $t; $bold = true; }
            if (is_array($cell)) { if (isset($cell['color'])) $color = $cell['color']; if (isset($cell['bold'])) $bold = $cell['bold']; if (isset($cell['fill'])) $fill = $cell['fill']; }
            $al = $o['align'][$c] ?? 'c';
            $ps = ''; foreach (explode("\n", $v) as $ln) $ps .= para_xml($ln === '' ? ' ' : $ln, $size, $bold, $color, $al, 1.0);
            $bd = ''; foreach (['lnL', 'lnR', 'lnT', 'lnB'] as $e) $bd .= '<a:' . $e . ' w="6350" cap="flat" cmpd="sng" algn="ctr"><a:solidFill><a:srgbClr val="' . C_LINE . '"/></a:solidFill></a:' . $e . '>';
            $tcs .= '<a:tc' . ($sp > 1 ? ' gridSpan="' . $sp . '"' : '') . '><a:txBody><a:bodyPr/><a:lstStyle/>' . $ps . '</a:txBody><a:tcPr marL="43200" marR="43200" marT="10800" marB="10800" anchor="ctr">'
                . $bd . '<a:solidFill><a:srgbClr val="' . $fill . '"/></a:solidFill></a:tcPr></a:tc>';
            for ($k = 1; $k < $sp; $k++) $tcs .= '<a:tc hMerge="1"><a:txBody><a:bodyPr/><a:lstStyle/><a:p><a:endParaRPr lang="ko-KR" sz="' . (int)($size * 100) . '"/></a:p></a:txBody><a:tcPr/></a:tc>';
        }
        $trs .= '<a:tr h="' . $need . '">' . $tcs . '</a:tr>';
    }
    $id = $s->nid();
    $s->add('<p:graphicFrame><p:nvGraphicFramePr><p:cNvPr id="' . $id . '" name="Table ' . $id . '"/><p:cNvGraphicFramePr><a:graphicFrameLocks noGrp="1"/></p:cNvGraphicFramePr><p:nvPr/></p:nvGraphicFramePr>'
        . '<p:xfrm><a:off x="' . (int)$x . '" y="' . (int)$y . '"/><a:ext cx="' . array_sum($widths) . '" cy="' . $total . '"/></p:xfrm><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/table">'
        . '<a:tbl><a:tblPr firstRow="' . ($o['header'] ? 1 : 0) . '"/><a:tblGrid>' . $grid . '</a:tblGrid>' . $trs . '</a:tbl></a:graphicData></a:graphic></p:graphicFrame>');
    return $y + $total;
}

/* 가로 막대 그래프 (도형으로 그려 PowerPoint·LibreOffice·한컴 어디서나 같게 보입니다) */
function k_bars($s, $x, $y, $w, $h, $cats, $vals, $o = []) {
    $o += ['title' => '', 'max' => 100, 'suffix' => '%', 'th' => [85, 95], 'colors' => null];
    if ($o['title'] !== '') { k_text($s, $x, $y, $w, cm(0.6), $o['title'], 9, true, C_NAVY, 'c', 'm'); $y += cm(0.7); $h -= cm(0.7); }
    $n = max(1, count($cats)); $rowh = min((int)($h / $n), cm(0.9)); $lw = (int)($w * 0.32); $vw = cm(1.5);
    $tx = $x + $lw + cm(0.15); $tw = $w - $lw - $vw - cm(0.3); $max = $o['max'] ?: 1;
    $yy = $y + (int)(($h - $rowh * $n) / 2);
    foreach (array_values($cats) as $i => $c) {
        $v = (float)($vals[$i] ?? 0); $bh = (int)($rowh * 0.56); $by = $yy + (int)(($rowh - $bh) / 2);
        $col = $o['colors'][$i] ?? ($v >= $o['th'][1] ? C_RED : ($v >= $o['th'][0] ? C_AMBER : C_BLUE));
        k_text($s, $x, $yy, $lw, $rowh, clip($c, 22), 7.5, false, C_INK, 'r', 'm', 1.0);
        k_rect($s, $tx, $by, $tw, $bh, C_TRACK);
        $bw = (int)($tw * max(0, min(1, $v / $max)));
        if ($bw > 0) k_rect($s, $tx, $by, $bw, $bh, $col);
        k_text($s, $tx + $tw + cm(0.1), $yy, $vw, $rowh, (fmod($v, 1) ? number_format($v, 1) : (int)$v) . $o['suffix'], 7.5, true, $col, 'l', 'm', 1.0);
        $yy += $rowh;
    }
}

/* 건강 점수 게이지 (원형 링 + 중앙 숫자) */
function k_donut($s, $x, $y, $size, $value, $label, $color = null, $sub = '') {
    $value = max(0, min(100, (int)$value));
    $color = $color ?: ($value >= 80 ? C_GREEN : ($value >= 60 ? C_AMBER : C_RED));
    $thick = '<a:gd name="adj" fmla="val 14000"/>';
    $id = $s->nid();
    $s->add('<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="Ring ' . $id . '"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr><p:spPr>' . xfrm($x, $y, $size, $size)
        . '<a:prstGeom prst="donut"><a:avLst>' . $thick . '</a:avLst></a:prstGeom><a:solidFill><a:srgbClr val="' . ($value >= 100 ? $color : C_TRACK) . '"/></a:solidFill><a:ln><a:noFill/></a:ln></p:spPr></p:sp>');
    if ($value > 0 && $value < 100) {
        $st = 16200000; $en = ($st + (int)round(21600000 * $value / 100)) % 21600000; $id = $s->nid();
        $s->add('<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="Arc ' . $id . '"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr><p:spPr>' . xfrm($x, $y, $size, $size)
            . '<a:prstGeom prst="blockArc"><a:avLst><a:gd name="adj1" fmla="val ' . $st . '"/><a:gd name="adj2" fmla="val ' . $en . '"/><a:gd name="adj3" fmla="val 14000"/></a:avLst></a:prstGeom>'
            . '<a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill><a:ln><a:noFill/></a:ln></p:spPr></p:sp>');
    }
    k_text($s, $x, $y + (int)($size * 0.28), $size, (int)($size * 0.28), (string)$value, max(10, (int)($size / 12700 / 4.2)), true, $color, 'c', 'm', 1.0);
    k_text($s, $x, $y + (int)($size * 0.55), $size, (int)($size * 0.16), $label, 7, false, C_GREY, 'c', 't', 1.0);
    if ($sub !== '') k_text($s, $x - cm(0.5), $y + $size + cm(0.05), $size + cm(1), cm(0.5), $sub, 7.5, true, $color, 'c', 't', 1.0);
}
function k_kpi($s, $x, $y, $w, $h, $value, $label, $color = C_NAVY, $accent = C_BLUE) {
    k_rect($s, $x, $y, $w, $h, C_WHITE, C_LINE, 'roundRect', 0.08);
    k_rect($s, $x, $y + cm(0.15), cm(0.12), $h - cm(0.3), $accent);
    k_text($s, $x + cm(0.3), $y + cm(0.1), $w - cm(0.4), (int)($h * 0.55), $value, 16, true, $color, 'l', 'm', 1.0);
    k_text($s, $x + cm(0.3), $y + (int)($h * 0.58), $w - cm(0.4), (int)($h * 0.38), $label, 7.5, false, C_GREY, 'l', 't', 1.05);
}
function k_footer($s, $sw, $sh, $company, $page, $total) {
    $y = $sh - cm(1.0);
    k_line($s, cm(1.0), $y, $sw - cm(1.0), $y);
    $parts = array_filter([$company['name'] ?? '', $company['address'] ?? '']);
    $contact = implode(' | ', array_filter([!empty($company['tel']) ? 'Tel: ' . $company['tel'] : '', !empty($company['email']) ? 'Email: ' . $company['email'] : '']));
    k_text($s, cm(1.0), $y + cm(0.1), $sw - cm(5), cm(0.4), implode(' | ', $parts), 7, false, C_GREY, 'l', 't', 1.0);
    if ($contact) k_text($s, cm(1.0), $y + cm(0.45), $sw - cm(5), cm(0.4), $contact, 7, false, C_GREY, 'l', 't', 1.0);
    k_text($s, $sw - cm(4), $y + cm(0.1), cm(3), cm(0.4), "$page / $total", 8, true, C_NAVY, 'r', 't', 1.0);
}
