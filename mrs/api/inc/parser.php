<?php
/* 스토리지(NetApp ONTAP 7-Mode / Cluster-Mode 중심) CLI 로그 파서.
   콘솔 출력(.txt/.log/.out) 또는 이를 묶은 .zip/.tar/.tgz/.gz 를 받아 모델·시리얼·버전, Aggregate·Volume 사용률,
   디스크·HA·포트 상태, 이벤트 로그를 추출합니다. 알 수 없는 형식이라도 오류 키워드 스캔은 항상 수행합니다. */

const P_SIZE = '\d+(?:\.\d+)?\s?[KMGTP]?B';
const P_DISK = '(?:\d+\.\d+\.\d+|\d[a-z]\.\d+(?:\.\d+)?|[\w-]+:\d[a-z]\.\d+)';

function to_gb($s) {
    if (!preg_match('/^\s*(\d+(?:\.\d+)?)\s?([KMGTP]?B)\s*$/i', $s, $m)) return 0.0;
    $u = ['B' => 1 / 1073741824, 'KB' => 1 / 1048576, 'MB' => 1 / 1024, 'GB' => 1.0, 'TB' => 1024.0, 'PB' => 1048576.0];
    return (float)$m[1] * $u[strtoupper($m[2])];
}
function human_gb($gb) {
    if ($gb >= 1024) return number_format($gb / 1024, 1) . ' TB';
    return $gb >= 10 ? number_format($gb, 0) . ' GB' : number_format($gb, 1) . ' GB';
}

/* ---------- 파일 → 텍스트 ---------- */
function tar_entries($data) {
    $out = []; $pos = 0; $len = strlen($data);
    while ($pos + 512 <= $len && count($out) < 500) {
        $h = substr($data, $pos, 512);
        if (trim($h, "\0") === '') break;
        $name = rtrim(substr($h, 0, 100), "\0");
        $prefix = rtrim(substr($h, 345, 155), "\0");
        if ($prefix !== '' && substr($h, 257, 5) === 'ustar') $name = $prefix . '/' . $name;
        $size = octdec(trim(substr($h, 124, 12), "\0 "));
        $type = $h[156];
        $pos += 512;
        if (($type === '0' || $type === "\0") && $size < 50 * 1048576) $out[] = [$name, substr($data, $pos, $size)];
        $pos += (int)ceil($size / 512) * 512;
    }
    return $out;
}
function extract_text($name, $data, $depth = 0) {
    $l = strtolower($name);
    if ($depth > 2) return '';
    if (substr($l, -4) === '.zip') {
        if (!class_exists('ZipArchive')) return '[zip 확장이 없어 압축을 풀 수 없습니다]';
        $tmp = tempnam(sys_get_temp_dir(), 'mrz'); file_put_contents($tmp, $data);
        $z = new ZipArchive(); $out = [];
        if ($z->open($tmp) === true) {
            for ($i = 0; $i < min($z->numFiles, 500); $i++) {
                $st = $z->statIndex($i);
                if (substr($st['name'], -1) === '/' || $st['size'] > 50 * 1048576) continue;
                $out[] = "\n### FILE {$st['name']}\n" . extract_text($st['name'], (string)$z->getFromIndex($i), $depth + 1);
            }
            $z->close();
        } else $out[] = '[압축 해제 실패]';
        @unlink($tmp);
        return implode("\n", $out);
    }
    if (preg_match('/\.(tar\.gz|tgz|tar)$/', $l)) {
        $raw = preg_match('/\.(tar\.gz|tgz)$/', $l) ? @gzdecode($data) : $data;
        if ($raw === false) return '[압축 해제 실패]';
        $out = [];
        foreach (tar_entries($raw) as [$n, $d]) $out[] = "\n### FILE $n\n" . extract_text($n, $d, $depth + 1);
        return implode("\n", $out);
    }
    if (substr($l, -3) === '.gz') { $d = @gzdecode($data); return $d === false ? '[압축 해제 실패]' : extract_text(substr($name, 0, -3), $d, $depth + 1); }
    $textExt = preg_match('/\.(txt|log|out|cfg|conf|csv|xml|json)$/', $l) || strpos(basename($l), '.') === false;
    if (!$textExt && strpos(substr($data, 0, 4096), "\0") !== false) return '';
    if (substr($data, 0, 3) === "\xEF\xBB\xBF") $data = substr($data, 3);
    if (mb_check_encoding($data, 'UTF-8')) return $data;
    $c = @mb_convert_encoding($data, 'UTF-8', 'CP949');
    return $c !== false && $c !== '' ? $c : mb_convert_encoding($data, 'UTF-8', 'ISO-8859-1');
}

/* ---------- 파서 ---------- */
function empty_parsed() {
    return ['system' => new stdClass(), 'aggregates' => [], 'volumes' => [], 'disks' => ['failed' => [], 'spare' => 0], 'ha' => new stdClass(),
        'ports_down' => [], 'events' => ['emergency' => 0, 'alert' => 0, 'error' => 0, 'panic' => 0, 'top' => []], 'autosupport' => null, 'lines' => 0];
}
/* 표 머리글(----- 줄 위의 제목 줄)을 보고 열 위치를 알아내 Aggregate·Volume 표를 읽습니다.
   'storage aggregate show', 'aggr show -fields ...', 'volume show -fields ...', 'df -A' 처럼 열 순서가 달라도 읽을 수 있습니다.
   이름이 길어 다음 줄로 넘어간 행도 이어 붙입니다. */
function hdr_key($h) {
    $h = strtolower(trim($h));
    $map = ['aggregate' => 'aggr', 'aggr' => 'aggr', 'aggregate-name' => 'aggr', 'volume' => 'vol', 'vol' => 'vol', 'vserver' => 'svm', 'svm' => 'svm', 'node' => 'node', 'nodes' => 'node',
        'size' => 'total', 'total' => 'total', 'kbytes' => 'total', 'available' => 'avail', 'availsize' => 'avail', 'avail' => 'avail', 'used' => 'used', 'usedsize' => 'used',
        'used%' => 'pct', 'percent-used' => 'pct', 'capacity' => 'pct', 'use%' => 'pct', 'state' => 'state', 'type' => 'type', 'filesystem' => 'fs'];
    return $map[$h] ?? null;
}
function parse_header_tables($lines, &$res, &$aggrSeen, &$volSeen) {
    $S = '/^\d+(?:\.\d+)?\s?[KMGTP]?B$/i';
    $n = count($lines);
    for ($i = 1; $i < $n; $i++) {
        if (!preg_match('/^\s*-{3,}(\s+-{2,})+\s*$/', $lines[$i])) continue;
        $hdr = preg_split('/\s+/', trim($lines[$i - 1])); $keys = array_map('hdr_key', $hdr);
        $isA = in_array('aggr', $keys, true) && !in_array('vol', $keys, true); $isV = in_array('vol', $keys, true);
        if ((!$isA && !$isV) || !in_array('pct', $keys, true) && !(in_array('total', $keys, true) && (in_array('avail', $keys, true) || in_array('used', $keys, true)))) continue;
        $cols = count($hdr); $buf = [];
        for ($j = $i + 1; $j < $n; $j++) {
            $ln = $lines[$j];
            if (trim($ln) === '' || preg_match('/^\s*\d+ entries were displayed|^\s*[\w.\-]+::?\*?>|^### FILE/', $ln)) break;
            if (!$buf && preg_match('/^\s/', $ln)) continue; // 앞 행의 마지막 열(예: RAID Status)이 줄바꿈된 것
            $tok = preg_split('/\s+/', trim($ln));
            // 이름이 길어 줄바꿈된 행: 토큰이 모자라면 다음 줄과 합칩니다
            $buf = array_merge($buf, $tok);
            if (count($buf) < $cols - 1) continue;
            $row = $buf; $buf = [];
            if (count($row) > $cols) $row = array_slice($row, 0, $cols); // RAID 상태 등 뒤쪽 여분 무시
            $v = []; foreach ($keys as $k => $key) if ($key && isset($row[$k])) $v[$key] = $row[$k];
            $t = isset($v['total']) ? (preg_match($S, $v['total']) ? to_gb($v['total']) : (ctype_digit($v['total']) ? (int)$v['total'] / 1048576 : 0)) : 0;
            $a = isset($v['avail']) ? (preg_match($S, $v['avail']) ? to_gb($v['avail']) : (ctype_digit($v['avail']) ? (int)$v['avail'] / 1048576 : 0)) : 0;
            $u = isset($v['used']) ? (preg_match($S, $v['used']) ? to_gb($v['used']) : (ctype_digit($v['used']) ? (int)$v['used'] / 1048576 : 0)) : max($t - $a, 0);
            if (!$a && $t && $u) $a = max($t - $u, 0);
            $pct = isset($v['pct']) ? (int)rtrim($v['pct'], '%') : ($t ? (int)round($u / $t * 100) : 0);
            if (!$t && !isset($v['pct'])) continue;
            if ($isA) {
                $name = $v['aggr']; if (strpos($name, '/.snapshot') !== false || strpos($name, '/') !== false) continue;
                if (isset($aggrSeen[$name])) continue; $aggrSeen[$name] = 1;
                $res['aggregates'][] = ['node' => $v['node'] ?? '', 'name' => $name, 'total_gb' => $t, 'used_gb' => $u, 'avail_gb' => $a, 'pct' => $pct, 'state' => $v['state'] ?? 'online'];
            } else {
                $svm = $v['svm'] ?? ($v['node'] ?? ''); $name = $v['vol']; $key = $svm . '/' . $name;
                if (isset($volSeen[$key]) || isset($volSeen[$name])) continue; $volSeen[$key] = 1;
                $res['volumes'][] = ['node' => $svm, 'name' => $name, 'aggr' => $v['aggr'] ?? '', 'state' => $v['state'] ?? 'online', 'type' => strtoupper($v['type'] ?? ''), 'total_gb' => $t, 'used_gb' => $u, 'avail_gb' => $a,
                    'pct' => $pct, 'inode_pct' => null, 'protocol' => ''];
            }
        }
    }
}
function parse_text($txt) {
    $res = empty_parsed(); $sys = []; $ha = [];
    $res['lines'] = substr_count($txt, "\n") + 1;
    $S = P_SIZE; $D = P_DISK;
    if (preg_match('/NetApp Release\s+([0-9][\w.]*)(\s+7-Mode)?/i', $txt, $m)) $sys['os_version'] = $m[1] . (!empty($m[2]) ? ' 7-Mode' : '');
    if (preg_match('/^\s*(?:System\s+)?Model(?:\s+Name)?\s*[:=]\s*(\S+)/im', $txt, $m)) $sys['model'] = $m[1];
    if (preg_match_all('/^\s*(?:System\s+)?Serial\s+Number\s*[:=]\s*(\S+)/im', $txt, $m)) $sys['serial'] = implode(' / ', array_slice(array_values(array_unique($m[1])), 0, 2));
    if (preg_match('/^\s*(?:Hostname|Node(?:\s+Name)?)\s*[:=]\s*(\S+)/im', $txt, $m)) $sys['hostname'] = $m[1];
    if (preg_match('/^\s*(?:State|autosupport\.enable)\s*[:=]?\s*(enable|on|disable|off)/im', $txt, $m)) $res['autosupport'] = in_array(strtolower($m[1]), ['enable', 'on'], true);

    $inode = [];
    if (preg_match_all('#^(/vol/[^/\s]+/?)\s+\d+\s+\d+\s+(\d+)%#m', $txt, $mm, PREG_SET_ORDER))
        foreach ($mm as $x) { $p = explode('/', trim($x[1], '/')); $inode[end($p)] = (int)$x[2]; }

    $section = ''; $nodes = []; $aggrSeen = []; $volSeen = []; $failedSeen = []; $ev = [];
    $allLines = preg_split('/\r\n|\n|\r/', $txt);
    parse_header_tables($allLines, $res, $aggrSeen, $volSeen);
    foreach ($allLines as $raw) {
        $line = rtrim($raw);
        if (trim($line) === '') continue;
        if (preg_match('/^\s*([\w.\-]+)(?:::?\*?>|\*?>)\s*(.+)$/', $line, $pm)) {
            $section = strtolower(trim($pm[2]));
            if (strpos(explode('>', $line)[0], '::') === false && !isset($sys['hostname'])) $sys['hostname'] = $pm[1];
            continue;
        }
        if (strpos($line, '### FILE') === 0) { $section = strtolower($line); continue; }
        if (preg_match('/^(\S+)\s+(true|false)\s+(true|false)\s+.*?\s((?:FAS|AFF|ASA)[-\w]*\d\w*|[AC]\d{3}\w*)\b/i', $line, $m)) {
            $nodes[] = $m[1]; if (!isset($sys['model'])) $sys['model'] = $m[4]; continue;
        }
        if (preg_match("/^(\S+)\s+($S)\s+($S)\s+(\d+)%\s+(\S+)\s+(\d+)\s+(\S+)/i", $line, $m)) {
            if (!isset($aggrSeen[$m[1]]) && strtolower($m[1]) !== 'aggregate') {
                $aggrSeen[$m[1]] = 1; $t = to_gb($m[2]); $a = to_gb($m[3]);
                $res['aggregates'][] = ['node' => $m[7], 'name' => $m[1], 'total_gb' => $t, 'used_gb' => max($t - $a, 0), 'avail_gb' => $a, 'pct' => (int)$m[4], 'state' => $m[5]];
            }
            continue;
        }
        if (preg_match("/^(\S+)\s+(\S+)\s+(\S+)\s+(online|offline|restricted)\s+(RW|DP|LS|TMP)\s+($S)\s+($S)\s+(\d+)%/i", $line, $m)) {
            $key = $m[1] . '/' . $m[2];
            if (!isset($volSeen[$key])) {
                $volSeen[$key] = 1; $t = to_gb($m[6]); $a = to_gb($m[7]);
                $res['volumes'][] = ['node' => $m[1], 'name' => $m[2], 'aggr' => $m[3], 'state' => $m[4], 'total_gb' => $t, 'used_gb' => max($t - $a, 0), 'avail_gb' => $a,
                    'pct' => (int)$m[8], 'inode_pct' => $inode[$m[2]] ?? null, 'protocol' => ''];
            }
            continue;
        }
        $kb = false; $m7 = null;
        if (preg_match("/^(\S+)\s+($S)\s+($S)\s+($S)\s+(\d+)%/i", $line, $m)) $m7 = $m;
        elseif (preg_match('/^(\S+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)%/', $line, $m)) { $m7 = $m; $kb = true; }
        if ($m7) {
            $name = $m7[1];
            if (strpos($name, '.snapshot') !== false || in_array(strtolower($name), ['filesystem', 'aggregate'], true)) continue;
            $conv = $kb ? function ($s) { return (int)$s / 1048576; } : 'to_gb';
            $t = $conv($m7[2]); $u = $conv($m7[3]); $a = $conv($m7[4]); $pct = (int)$m7[5];
            if (strpos($name, '/vol/') === 0) {
                $p = explode('/', trim($name, '/')); $vn = end($p);
                if (!isset($volSeen[$vn])) {
                    $volSeen[$vn] = 1;
                    $res['volumes'][] = ['node' => $sys['hostname'] ?? '', 'name' => $vn, 'aggr' => '', 'state' => 'online', 'total_gb' => $t, 'used_gb' => $u, 'avail_gb' => $a,
                        'pct' => $pct, 'inode_pct' => $inode[$vn] ?? null, 'protocol' => ''];
                }
            } elseif ((strpos($section, '-a') !== false || strpos($section, 'aggr') !== false || strpos($name, 'aggr') === 0) && !isset($aggrSeen[$name])) {
                $aggrSeen[$name] = 1;
                $res['aggregates'][] = ['node' => $sys['hostname'] ?? '', 'name' => $name, 'total_gb' => $t, 'used_gb' => $u, 'avail_gb' => $a, 'pct' => $pct, 'state' => 'online'];
            }
            continue;
        }
        $low = strtolower($line);
        if ((strpos($section, 'broken') !== false || strpos($section, '-f') !== false || strpos($low, 'failed') !== false || strpos($low, 'broken') !== false)
            && preg_match("/($D).*\b(broken|failed|fail|prefailed)\b/i", $line, $m)) {
            if (!isset($failedSeen[$m[1]])) { $failedSeen[$m[1]] = 1; $res['disks']['failed'][] = ['disk' => $m[1], 'line' => mb_substr(trim($line), 0, 160)]; }
            continue;
        }
        if (preg_match("/^\s*spare\s+($D)|^\s*($D)\s+.*\bspare\b/i", $line)) { $res['disks']['spare']++; continue; }
        if (strpos($section, 'failover') !== false && preg_match('/^(\S+)\s+(\S+)\s+(true|false)\s+(.+)$/i', $line, $m) && strtolower($m[1]) !== 'node') {
            $ha['nodes'][] = ['node' => $m[1], 'partner' => $m[2], 'takeover_possible' => strtolower($m[3]) === 'true', 'state' => mb_substr(trim($m[4]), 0, 120)];
            continue;
        }
        if (strpos($low, 'cluster enabled') !== false || (strpos($low, 'takeover') !== false && strpos($low, 'disabled') !== false)) {
            $ha['cf_status'] = mb_substr(trim($line), 0, 160);
            if (strpos($low, 'disabled') !== false) $ha['takeover_disabled'] = true;
        }
        if (preg_match('/^\s*(?:\S+\s+)?((?:e|a)\d+[a-z](?:-\d+)?)\s+\S+\s+\S+\s+down\b/i', $line, $m)
            && (strpos($section, 'port') !== false || strpos($section, 'ifgrp') !== false || strpos($section, 'network') !== false)) {
            if (!in_array($m[1], $res['ports_down'], true)) $res['ports_down'][] = $m[1];
            continue;
        }
        if (preg_match('/[Ll]ink (?:is )?down on (?:port )?((?:e|a)\d+[a-z](?:-\d+)?)/', $line, $m) && !in_array($m[1], $res['ports_down'], true)) $res['ports_down'][] = $m[1];
        if (preg_match('/\b(EMERGENCY|ALERT|ERROR)\s+([\w.]+)\s*:?\s*(.*)$/', $line, $m)) {
            $sev = strtolower($m[1]); $res['events'][$sev]++; $k = "$sev|{$m[2]}"; $ev[$k] = ($ev[$k] ?? 0) + 1; continue;
        }
        if (preg_match('/\[([\w-]+):([\w.]+):(emergency|alert|critical|error)\]:?\s*(.*)$/i', $line, $m)) {
            $sev = strtolower($m[3]); if ($sev === 'critical') $sev = 'alert';
            $res['events'][$sev]++; $k = "$sev|{$m[2]}"; $ev[$k] = ($ev[$k] ?? 0) + 1; continue;
        }
        if (preg_match('/\b(PANIC|panic string|Kernel panic)\b/', $line)) $res['events']['panic']++;
    }
    if ($nodes) { $sys['nodes'] = $nodes; if (!isset($sys['hostname'])) $sys['hostname'] = $nodes[0]; }
    if (!empty($ha['nodes'])) $ha['takeover_possible'] = !in_array(false, array_column($ha['nodes'], 'takeover_possible'), true);
    elseif (!empty($ha['takeover_disabled'])) $ha['takeover_possible'] = false;
    arsort($ev);
    $top = [];
    foreach (array_slice($ev, 0, 12, true) as $k => $c) { [$s, $e] = explode('|', $k, 2); $top[] = ['severity' => $s, 'event' => $e, 'count' => $c]; }
    $res['events']['top'] = $top;
    $res['system'] = $sys ?: new stdClass();
    $res['ha'] = $ha ?: new stdClass();
    // cDOT 점검 명령어별 결과 (정기=평시 / 특별=평시+상세)
    if (preg_match('/^\s*[\w.\-]+::\*?>/m', $txt)) { $res['cdot'] = cdot_eval($txt); $res['os_mode'] = 'cdot'; }
    elseif (preg_match('/^\s*[\w.\-]+\*?>\s/m', $txt)) $res['os_mode'] = '7mode';
    $res['hw_hits'] = hw_scan($allLines);
    return $res;
}
/* 육안 점검과 연결되는 하드웨어 이상 문구 찾기 (PSU·팬·배터리·SFP·케이블·캐시·컨트롤러) */
const HW_PATTERNS = [
    'psu' => '/\b(psu|power supply|power_supply|pwr)\b.*\b(fail|fault|degrad|missing|bad|critical|not present|off\b)/i',
    'fan' => '/\bfan\w*\b.*\b(fail|fault|degrad|stopped|critical|bad|slow|not present)/i',
    'battery' => '/\b(battery|bbu|nvram\.battery|nvmem\.battery)\b.*\b(fail|fault|low|degrad|expir|critical|bad|discharg)/i',
    'sfp' => '/\b(sfp|qsfp|transceiver|gbic)\b.*\b(fail|fault|unsupported|error|missing|bad|not qualified)/i',
    'cable' => '/\b(cable|cabling|single[- ]path|mpha|multipath|link down|lost connectivity)\b.*\b(fail|fault|degrad|miss|error|down|lost)|\b(single[- ]path)\b/i',
    'cache' => '/\b(flash ?cache|fcache|\becc\b|dimm|memory)\b.*\b(fail|fault|error|degrad|offline)/i',
    'controller' => '/\b(controller|motherboard|nvram|service processor|bmc|iom\d*|io module)\b.*\b(fail|fault|offline|degrad|critical)/i',
];
function hw_scan($lines) {
    $out = [];
    foreach ($lines as $l) {
        if (strlen($l) > 400 || preg_match('/^\s*(options|[\w.]+\s+(on|off)\s*$)/i', $l)) continue;
        foreach (HW_PATTERNS as $k => $re) if ((!isset($out[$k]) || count($out[$k]) < 2) && preg_match($re, $l) && !preg_match('/\b(ok|normal|no fault|none|0 errors?)\b\s*$/i', $l)) $out[$k][] = mb_substr(trim($l), 0, 160);
    }
    return $out;
}
function parse_file($name, $data) { $p = parse_text(extract_text($name, $data)); $p['file'] = $name; return $p; }

/* 같은 장비의 여러 로그 파일을 하나로 합칩니다 (뒤에 온 값이 우선) */
function merge_parsed($list) {
    $out = empty_parsed(); $sys = []; $ha = []; $aggr = []; $vols = []; $failed = []; $top = []; $files = [];
    foreach ($list as $p) {
        foreach ((array)($p['system'] ?? []) as $k => $v) if ($v) $sys[$k] = $v;
        foreach ($p['aggregates'] ?? [] as $a) $aggr[($a['node'] ?? '') . '|' . $a['name']] = $a;
        foreach ($p['volumes'] ?? [] as $v) $vols[($v['node'] ?? '') . '|' . $v['name']] = $v;
        foreach ($p['disks']['failed'] ?? [] as $d) $failed[$d['disk']] = $d;
        $out['disks']['spare'] = max($out['disks']['spare'], (int)($p['disks']['spare'] ?? 0));
        foreach ((array)($p['ha'] ?? []) as $k => $v) $ha[$k] = $v;
        foreach ($p['ports_down'] ?? [] as $x) if (!in_array($x, $out['ports_down'], true)) $out['ports_down'][] = $x;
        foreach (['emergency', 'alert', 'error', 'panic'] as $k) $out['events'][$k] += (int)($p['events'][$k] ?? 0);
        foreach ($p['events']['top'] ?? [] as $e) $top[] = $e;
        if (array_key_exists('autosupport', $p) && $p['autosupport'] !== null) $out['autosupport'] = $p['autosupport'];
        $out['lines'] += (int)($p['lines'] ?? 0);
        if (!empty($p['file'])) $files[] = $p['file'];
        foreach ((array)($p['cdot'] ?? []) as $k => $v) $out['cdot'][$k] = $v;
        foreach ((array)($p['hw_hits'] ?? []) as $k => $v) $out['hw_hits'][$k] = array_slice(array_merge($out['hw_hits'][$k] ?? [], $v), 0, 3);
        if (!empty($p['os_mode'])) $out['os_mode'] = $p['os_mode'];
    }
    $aggr = array_values($aggr); usort($aggr, function ($a, $b) { return [$b['pct'], $a['name']] <=> [$a['pct'], $b['name']]; });
    $vols = array_values($vols); usort($vols, function ($a, $b) { return $b['pct'] <=> $a['pct']; });
    usort($top, function ($a, $b) { return $b['count'] <=> $a['count']; });
    $out['system'] = $sys ?: new stdClass(); $out['ha'] = $ha ?: new stdClass();
    $out['aggregates'] = $aggr; $out['volumes'] = $vols; $out['disks']['failed'] = array_values($failed);
    $out['events']['top'] = array_slice($top, 0, 12); $out['files'] = $files;
    return $out;
}
