<?php
/* 아주 작은 PDF 작성기 (외부 라이브러리 없이).
   - 한글: api/fonts 의 나눔스퀘어(OFL) TTF 에서 실제로 쓴 글자만 골라 넣습니다(서브셋) → 파일이 작고, 글자 복사·검색이 됩니다.
   - 좌표는 pt, 원점은 왼쪽 위. A4 세로(595 x 842) 또는 가로. */

class TtfFont {
    public $upem, $asc, $desc, $cap, $bbox, $name; private $d, $t = [], $adv = [], $nhm, $cmap = null, $loca = [];
    public $used = [0 => true]; public $uni = [];
    function __construct($path) {
        $this->d = file_get_contents($path); $d = $this->d; $n = $this->u16(4);
        for ($i = 0; $i < $n; $i++) { $o = 12 + $i * 16; $this->t[substr($d, $o, 4)] = [$this->u32($o + 8), $this->u32($o + 12)]; }
        $h = $this->t['head'][0]; $this->upem = $this->u16($h + 18);
        $this->bbox = [$this->s16($h + 36), $this->s16($h + 38), $this->s16($h + 40), $this->s16($h + 42)];
        $hh = $this->t['hhea'][0]; $this->asc = $this->s16($hh + 4); $this->desc = $this->s16($hh + 6); $this->nhm = $this->u16($hh + 34);
        $this->cap = isset($this->t['OS/2']) && $this->u16($this->t['OS/2'][0]) >= 2 ? $this->s16($this->t['OS/2'][0] + 88) : $this->asc;
        $m = $this->t['hmtx'][0]; for ($i = 0; $i < $this->nhm; $i++) $this->adv[$i] = $this->u16($m + $i * 4);
        $this->name = preg_replace('/\W/', '', pathinfo($path, PATHINFO_FILENAME));
    }
    private function u16($o) { return unpack('n', $this->d, $o)[1]; }
    private function s16($o) { $v = $this->u16($o); return $v >= 0x8000 ? $v - 0x10000 : $v; }
    private function u32($o) { return unpack('N', $this->d, $o)[1]; }
    private function loadCmap() {
        $this->cmap = []; $c = $this->t['cmap'][0]; $n = $this->u16($c + 2); $best = null;
        for ($i = 0; $i < $n; $i++) { $pl = $this->u16($c + 4 + $i * 8); $en = $this->u16($c + 6 + $i * 8); $off = $c + $this->u32($c + 8 + $i * 8); $fmt = $this->u16($off);
            if ($pl === 3 && $en === 10 && $fmt === 12) { $best = $off; break; } if ($pl === 3 && $en === 1 && $fmt === 4) $best = $off; }
        if ($best === null) return; $o = $best;
        if ($this->u16($o) === 12) { $g = $this->u32($o + 12); for ($i = 0; $i < $g; $i++) { $p = $o + 16 + $i * 12; $s = $this->u32($p); $e = $this->u32($p + 4); $id = $this->u32($p + 8); for ($cp = $s; $cp <= $e; $cp++) $this->cmap[$cp] = $id + $cp - $s; } return; }
        $seg = $this->u16($o + 6) / 2; $ends = $o + 14; $starts = $ends + $seg * 2 + 2; $deltas = $starts + $seg * 2; $ros = $deltas + $seg * 2;
        for ($i = 0; $i < $seg; $i++) {
            $e = $this->u16($ends + $i * 2); $s = $this->u16($starts + $i * 2); $dl = $this->u16($deltas + $i * 2); $ro = $this->u16($ros + $i * 2);
            for ($cp = $s; $cp <= $e && $cp !== 0xFFFF; $cp++) {
                if ($ro === 0) $g = ($cp + $dl) & 0xFFFF;
                else { $p = $ros + $i * 2 + $ro + ($cp - $s) * 2; $g = $this->u16($p); if ($g) $g = ($g + $dl) & 0xFFFF; }
                if ($g) $this->cmap[$cp] = $g;
            }
        }
    }
    function gid($cp) { if ($this->cmap === null) $this->loadCmap(); return $this->cmap[$cp] ?? 0; }
    function adv($g) { return $this->adv[min($g, $this->nhm - 1)] ?? 0; }
    /* 글자열 → [16진 글리프 문자열, 폭(1000 단위)] */
    function encode($s) {
        $hex = ''; $w = 0;
        foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $cp = mb_ord($ch, 'UTF-8'); $g = $this->gid($cp);
            if (!$g) { $sub = ['✔' => '●', '✓' => '●', '⚡' => '!', "\t" => ' '][$ch] ?? '?'; $cp = mb_ord($sub, 'UTF-8'); $g = $this->gid($cp); }
            $this->used[$g] = true; $this->uni[$g] = $cp;
            $hex .= sprintf('%04X', $g); $w += $this->adv($g) * 1000 / $this->upem;
        }
        return [$hex, $w];
    }
    function width($s) { $w = 0; foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) as $ch) { $g = $this->gid(mb_ord($ch, 'UTF-8')) ?: $this->gid(63); $w += $this->adv($g); } return $w * 1000 / $this->upem; }
    /* 쓴 글리프만 남긴 TTF (글리프 번호는 그대로, 안 쓰는 글리프는 비움) */
    function subset() {
        $d = $this->d; $h = $this->t['head'][0]; $long = $this->u16($h + 50) === 1;
        $nG = $this->u16($this->t['maxp'][0] + 4); $lo = $this->t['loca'][0]; $loca = [];
        for ($i = 0; $i <= $nG; $i++) $loca[$i] = $long ? $this->u32($lo + $i * 4) : $this->u16($lo + $i * 2) * 2;
        $gb = $this->t['glyf'][0]; $keep = $this->used; $queue = array_keys($keep);
        while ($queue) { // 합성 글리프의 부품도 함께
            $g = array_pop($queue); if ($g >= $nG) continue; $o = $gb + $loca[$g]; if ($loca[$g + 1] - $loca[$g] < 10 || $this->s16($o) >= 0) continue;
            $p = $o + 10;
            do { $fl = $this->u16($p); $cg = $this->u16($p + 2); if (!isset($keep[$cg])) { $keep[$cg] = true; $queue[] = $cg; }
                $p += 4 + (($fl & 1) ? 4 : 2) + (($fl & 8) ? 2 : (($fl & 0x40) ? 4 : (($fl & 0x80) ? 8 : 0))); } while ($fl & 0x20);
        }
        $glyf = ''; $nl = [];
        for ($i = 0; $i < $nG; $i++) { $nl[] = strlen($glyf); if (isset($keep[$i])) { $glyf .= substr($d, $gb + $loca[$i], $loca[$i + 1] - $loca[$i]); while (strlen($glyf) % 4) $glyf .= "\0"; } }
        $nl[] = strlen($glyf);
        $head = substr($d, $h, $this->t['head'][1]); $head = substr_replace($head, "\0\0\0\0", 8, 4); $head = substr_replace($head, pack('n', 1), 50, 2);
        $tabs = ['head' => $head, 'loca' => pack('N*', ...$nl), 'glyf' => $glyf, 'post' => pack('NNnnNNNNN', 0x00030000, 0, 0, 0, 0, 0, 0, 0, 0)];
        foreach (['hhea', 'maxp', 'hmtx', 'OS/2', 'cvt ', 'fpgm', 'prep'] as $k) if (isset($this->t[$k])) $tabs[$k] = substr($d, $this->t[$k][0], $this->t[$k][1]);
        ksort($tabs, SORT_STRING); $n = count($tabs); $es = (int)floor(log($n, 2)); $sr = (2 ** $es) * 16;
        $out = pack('Nnnnn', 0x00010000, $n, $sr, $es, $n * 16 - $sr); $off = 12 + $n * 16; $body = '';
        foreach ($tabs as $tag => $data) {
            $pad = $data . str_repeat("\0", (4 - strlen($data) % 4) % 4); $sum = 0;
            foreach (unpack('N*', $pad) as $v) $sum = ($sum + $v) & 0xFFFFFFFF;
            $out .= $tag . pack('NNN', $sum, $off + strlen($body), strlen($data)); $body .= $pad;
        }
        return $out . $body;
    }
}

class Pdf {
    public $W, $H, $pages = [], $p = -1; private $fonts = [], $cur = ['R', 10];
    function __construct($landscape = false) { [$this->W, $this->H] = $landscape ? [842, 595] : [595, 842];
        $dir = dirname(__DIR__) . '/fonts/'; $this->fonts = ['R' => new TtfFont($dir . 'NanumSquareR.ttf'), 'B' => new TtfFont($dir . 'NanumSquareB.ttf')]; }
    function addPage() { $this->pages[] = ''; $this->p = count($this->pages) - 1; return $this->p; }
    private function o($s) { $this->pages[$this->p] .= $s . "\n"; }
    private static function rgb($hex) { $hex = ltrim($hex, '#'); return sprintf('%.3F %.3F %.3F', hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255); }
    private function Y($y) { return $this->H - $y; }
    function rect($x, $y, $w, $h, $fill = null, $stroke = null, $lw = 0.5) {
        $op = $fill && $stroke ? 'B' : ($fill ? 'f' : 'S'); if (!$fill && !$stroke) return;
        $this->o(($fill ? self::rgb($fill) . ' rg ' : '') . ($stroke ? self::rgb($stroke) . ' RG ' . sprintf('%.2F w ', $lw) : '') . sprintf('%.2F %.2F %.2F %.2F re %s', $x, $this->Y($y + $h), $w, $h, $op));
    }
    function line($x1, $y1, $x2, $y2, $color = 'DEE5EC', $lw = 0.5) { $this->o(self::rgb($color) . ' RG ' . sprintf('%.2F w %.2F %.2F m %.2F %.2F l S', $lw, $x1, $this->Y($y1), $x2, $this->Y($y2))); }
    function width($s, $size, $bold = false) { return $this->fonts[$bold ? 'B' : 'R']->width($s) * $size / 1000; }
    /* 글자 한 줄. $y 는 글자 윗선 기준 */
    function text($x, $y, $s, $size = 9, $bold = false, $color = '333B45', $align = 'l', $w = 0) {
        $s = (string)$s; if ($s === '') return;
        $f = $this->fonts[$bold ? 'B' : 'R']; [$hex, $tw] = $f->encode($s); $tw = $tw * $size / 1000;
        if ($align === 'r') $x = $x + $w - $tw; elseif ($align === 'c') $x = $x + ($w - $tw) / 2;
        $base = $y + $size * 0.82;
        $this->o('BT ' . self::rgb($color) . ' rg /F' . ($bold ? 'B' : 'R') . sprintf(' %.2F Tf %.2F %.2F Td <%s> Tj ET', $size, $x, $this->Y($base), $hex));
    }
    /* 폭에 맞게 줄바꿈 (한글은 글자 단위, 영문은 단어 단위로 최대한) */
    function wrap($s, $w, $size, $bold = false) {
        $out = [];
        foreach (preg_split("/\r?\n/", (string)$s) as $para) {
            if ($para === '') { $out[] = ''; continue; }
            $line = ''; $lw = 0;
            foreach (preg_split('/(\s+)/u', $para, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $tok) {
                $tw = $this->width($tok, $size, $bold);
                if ($lw + $tw <= $w || $line === '') {
                    if ($tw > $w) { // 긴 낱말은 글자 단위로
                        foreach (preg_split('//u', $tok, -1, PREG_SPLIT_NO_EMPTY) as $ch) { $cw = $this->width($ch, $size, $bold); if ($lw + $cw > $w && $line !== '') { $out[] = rtrim($line); $line = ''; $lw = 0; } $line .= $ch; $lw += $cw; }
                    } else { $line .= $tok; $lw += $tw; }
                } else {
                    $out[] = rtrim($line); $line = ltrim($tok); $lw = $this->width($line, $size, $bold);
                    if ($lw > $w) { $t = $line; $line = ''; $lw = 0; foreach (preg_split('//u', $t, -1, PREG_SPLIT_NO_EMPTY) as $ch) { $cw = $this->width($ch, $size, $bold); if ($lw + $cw > $w && $line !== '') { $out[] = $line; $line = ''; $lw = 0; } $line .= $ch; $lw += $cw; } }
                }
            }
            $out[] = rtrim($line);
        }
        return $out;
    }
    function output() {
        $objs = []; $add = function ($s) use (&$objs) { $objs[] = $s; return count($objs); };
        $catalog = $add(''); $pagesId = $add('');
        $fontIds = [];
        foreach ($this->fonts as $k => $f) {
            $sub = $f->subset(); $z = gzcompress($sub, 6);
            $ff = $add("<< /Length " . strlen($z) . " /Length1 " . strlen($sub) . " /Filter /FlateDecode >>\nstream\n$z\nendstream");
            $sc = 1000 / $f->upem; $bb = array_map(function ($v) use ($sc) { return (int)round($v * $sc); }, $f->bbox);
            $fd = $add("<< /Type /FontDescriptor /FontName /MRS$k+{$f->name} /Flags 4 /FontBBox [" . implode(' ', $bb) . "] /ItalicAngle 0 /Ascent " . (int)($f->asc * $sc) . " /Descent " . (int)($f->desc * $sc) . " /CapHeight " . (int)($f->cap * $sc) . " /StemV 80 /FontFile2 $ff 0 R >>");
            $g = array_keys($f->used); sort($g); $w = '';
            foreach ($g as $id) $w .= "$id [" . (int)round($f->adv($id) * $sc) . '] ';
            $cid = $add("<< /Type /Font /Subtype /CIDFontType2 /BaseFont /MRS$k+{$f->name} /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor $fd 0 R /DW 1000 /W [$w] /CIDToGIDMap /Identity >>");
            $cm = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";
            $pairs = []; foreach ($f->uni as $gid => $cp) $pairs[] = sprintf('<%04X> <%s>', $gid, strtoupper(bin2hex(mb_convert_encoding(mb_chr($cp, 'UTF-8'), 'UTF-16BE', 'UTF-8'))));
            foreach (array_chunk($pairs, 100) as $ch) $cm .= count($ch) . " beginbfchar\n" . implode("\n", $ch) . "\nendbfchar\n";
            $cm .= "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend"; $cz = gzcompress($cm);
            $tu = $add("<< /Length " . strlen($cz) . " /Filter /FlateDecode >>\nstream\n$cz\nendstream");
            $fontIds[$k] = $add("<< /Type /Font /Subtype /Type0 /BaseFont /MRS$k+{$f->name} /Encoding /Identity-H /DescendantFonts [$cid 0 R] /ToUnicode $tu 0 R >>");
        }
        $res = "<< /Font << /FR {$fontIds['R']} 0 R /FB {$fontIds['B']} 0 R >> >>"; $kids = [];
        foreach ($this->pages as $c) {
            $z = gzcompress($c, 6); $cs = $add("<< /Length " . strlen($z) . " /Filter /FlateDecode >>\nstream\n$z\nendstream");
            $kids[] = $add("<< /Type /Page /Parent $pagesId 0 R /MediaBox [0 0 {$this->W} {$this->H}] /Resources $res /Contents $cs 0 R >>");
        }
        $objs[$catalog - 1] = "<< /Type /Catalog /Pages $pagesId 0 R >>";
        $objs[$pagesId - 1] = "<< /Type /Pages /Kids [" . implode(' ', array_map(function ($k) { return "$k 0 R"; }, $kids)) . "] /Count " . count($kids) . " >>";
        $info = $add("<< /Producer (MRS) /CreationDate (D:" . date('YmdHis') . ") >>");
        $pdf = "%PDF-1.5\n%\xE2\xE3\xCF\xD3\n"; $xref = [];
        foreach ($objs as $i => $o) { $xref[] = strlen($pdf); $pdf .= ($i + 1) . " 0 obj\n$o\nendobj\n"; }
        $x = strlen($pdf); $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($xref as $off) $pdf .= sprintf("%010d 00000 n \n", $off);
        return $pdf . "trailer\n<< /Size " . (count($objs) + 1) . " /Root $catalog 0 R /Info $info 0 R >>\nstartxref\n$x\n%%EOF\n";
    }
}
