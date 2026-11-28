<?php
/* 시스템 사용률 (CPU · 디스크 · 네트워크) — 로그의 명령어 출력에서 뽑습니다.
   - 노드별 CPU·디스크: system node run -node <노드> -command sysstat -x  (CPU 열, Disk util 열)
   - 클러스터 CPU: statistics show-periodic (cpu avg / busy)
   - 인터페이스별 네트워크: system node run -node <노드> -command ifstat -a (Bytes/second, Speed)
     + network port show 의 속도로 보완
   결과: ['nodes' => [...], 'ifaces' => [...], 'cluster' => [...]] */

function util_node_of($cmd, $fallback = '') {
    if (preg_match('/-node\s+(\S+)/', $cmd, $m) && $m[1] !== '*') return $m[1];
    return $fallback;
}
function util_num($s) {
    // 1530, 15.3k, 1.2m, 2g → 숫자 (k/m/g 는 1000 배수)
    if (!preg_match('/^([\d.]+)\s*([kmgKMG])?/', trim((string)$s), $m)) return null;
    $v = (float)$m[1]; $u = strtolower($m[2] ?? '');
    return $u === 'k' ? $v * 1e3 : ($u === 'm' ? $v * 1e6 : ($u === 'g' ? $v * 1e9 : $v));
}
/* 노드셸 출력에서 노드 이름 머리말 (예: "Node: FAS2750-01", "=== FAS2750-01 ===") */
function util_node_header($l) {
    if (preg_match('/^\s*(?:Node|노드)\s*:\s*(\S+)\s*$/i', $l, $m)) return $m[1];
    if (preg_match('/^\s*={2,}\s*(\S+)\s*={2,}\s*$/', $l, $m)) return $m[1];
    if (preg_match('/^\s*([A-Za-z][\w-]*\d[\w-]*)\s*:\s*$/', $l, $m)) return $m[1]; // system node run -node * 의 "노드이름:" 머리말
    return null;
}
function util_parse($txt) {
    $nodes = []; $ifaces = []; $cluster = null; $speeds = [];
    foreach (cdot_sections($txt) as $sec) {
        $cmd = $sec['cmd']; $L = $sec['lines'];
        // network port show: Node / Port / ... / Speed(auto/10000)
        if (preg_match('/^net(work)?\s+port\s+show\s*$/', $cmd)) {
            $node = '';
            foreach ($L as $l) {
                if (preg_match('/^Node:\s*(\S+)/', $l, $m)) { $node = $m[1]; continue; }
                if (preg_match('/^\s*(e\d+\w*|a\d+\w*|[\w-]+-\d+)\s+\S+\s+\S+\s+(up|down)\s+\d+\s+\S*\/(\d+)/', $l, $m)) $speeds["$node|{$m[1]}"] = (int)$m[3];
            }
        }
        if (preg_match('/sysstat\s+-x/', $cmd)) {
            $node = util_node_of($cmd, 'node'); $cpu = []; $disk = []; $nin = []; $nout = [];
            foreach ($L as $l) {
                if ($h = util_node_header($l)) { if ($cpu) $nodes[$node] = util_node_row($node, $cpu, $disk, $nin, $nout); $node = $h; $cpu = $disk = $nin = $nout = []; continue; }
                if (!preg_match('/^\s*\d{1,3}%\s/', $l)) continue;
                $t = preg_split('/\s+/', trim($l));
                $pcts = []; foreach ($t as $i => $x) if (preg_match('/^(\d{1,3})%$/', $x, $m)) $pcts[] = (int)$m[1];
                $cpu[] = $pcts[0];
                if (count($pcts) >= 4) $disk[] = $pcts[3]; // CPU, Cache hit, CP time, Disk util 순서
                if (isset($t[5], $t[6]) && is_numeric($t[5]) && is_numeric($t[6])) { $nin[] = (float)$t[5]; $nout[] = (float)$t[6]; } // Net kB/s in/out
            }
            if ($cpu) $nodes[$node] = util_node_row($node, $cpu, $disk, $nin, $nout);
        }
        if (preg_match('/statistics\s+show-periodic/', $cmd)) {
            $c = []; $busy = [];
            foreach ($L as $l) if (preg_match('/^\s*(\d{1,3})%\s+(\d{1,3})%\s/', $l, $m)) { $c[] = (int)$m[1]; $busy[] = (int)$m[2]; }
            if ($c) $cluster = ['cpu_avg' => round(array_sum($c) / count($c)), 'cpu_max' => max($c), 'busy_max' => max($busy), 'samples' => count($c)];
        }
        if (preg_match('/ifstat/', $cmd)) {
            $node = util_node_of($cmd, ''); $cur = null; $part = '';
            $flush = function () use (&$cur, &$ifaces) { if ($cur && ($cur['rx'] !== null || $cur['tx'] !== null)) $ifaces[$cur['node'] . '|' . $cur['if']] = $cur; };
            foreach ($L as $l) {
                if ($h = util_node_header($l)) { $flush(); $cur = null; $node = $h; continue; }
                if (preg_match('/^\s*--\s*interface\s+(\S+)/i', $l, $m)) { $flush(); $cur = ['node' => $node, 'if' => $m[1], 'rx' => null, 'tx' => null, 'speed' => null, 'state' => '']; $part = ''; continue; }
                if (!$cur) continue;
                if (preg_match('/^\s*(RECEIVE|TRANSMIT|LINK_INFO|DEVICE)/', $l, $m)) { $part = $m[1]; continue; }
                if (preg_match('/Bytes\/second:\s*([\d.]+\s*[kmgKMG]?)/', $l, $m)) { $v = util_num($m[1]); if ($part === 'RECEIVE') $cur['rx'] = $v; elseif ($part === 'TRANSMIT') $cur['tx'] = $v; }
                if (preg_match('/Speed:\s*([\d.]+)\s*([mgMG])?/', $l, $m)) $cur['speed'] = strtolower($m[2] ?? 'm') === 'g' ? (float)$m[1] * 1000 : (float)$m[1];
                if (preg_match('/Current state:\s*(\w+)/', $l, $m)) $cur['state'] = $m[1];
            }
            $flush();
        }
    }
    $rows = [];
    foreach ($ifaces as $k => $x) {
        $sp = $x['speed'] ?: ($speeds[$k] ?? null);
        $rx = ($x['rx'] ?? 0) * 8 / 1e6; $tx = ($x['tx'] ?? 0) * 8 / 1e6; // Mb/s
        $rows[] = ['node' => $x['node'], 'if' => $x['if'], 'state' => $x['state'], 'speed_mbps' => $sp, 'rx_mbps' => round($rx, 2), 'tx_mbps' => round($tx, 2),
            'util' => $sp ? round(max($rx, $tx) / $sp * 100, 1) : null];
    }
    usort($rows, function ($a, $b) { return [$b['util'] ?? -1, $b['rx_mbps'] + $b['tx_mbps']] <=> [$a['util'] ?? -1, $a['rx_mbps'] + $a['tx_mbps']]; });
    if (!$nodes && !$rows && !$cluster) return null;
    return ['nodes' => array_values($nodes), 'ifaces' => $rows, 'cluster' => $cluster];
}
function util_node_row($node, $cpu, $disk, $nin, $nout) {
    $avg = function ($a) { return $a ? round(array_sum($a) / count($a)) : null; };
    return ['node' => $node, 'cpu_avg' => $avg($cpu), 'cpu_max' => max($cpu), 'disk_avg' => $avg($disk), 'disk_max' => $disk ? max($disk) : null,
        'net_in_mbps' => $nin ? round(array_sum($nin) / count($nin) * 8 / 1000, 2) : null, 'net_out_mbps' => $nout ? round(array_sum($nout) / count($nout) * 8 / 1000, 2) : null, 'samples' => count($cpu)];
}
function util_merge($a, $b) {
    if (!$a) return $b; if (!$b) return $a;
    $n = []; foreach (array_merge($a['nodes'], $b['nodes']) as $x) $n[$x['node']] = $x;
    $i = []; foreach (array_merge($a['ifaces'], $b['ifaces']) as $x) $i[$x['node'] . '|' . $x['if']] = $x;
    return ['nodes' => array_values($n), 'ifaces' => array_values($i), 'cluster' => $b['cluster'] ?: $a['cluster']];
}
/* 판정: CPU 평균 80%↑ 위험, 60%↑ 주의 / 디스크 util 80%↑ 위험, 60%↑ 주의 / 인터페이스 80%↑ 위험, 60%↑ 주의 */
function util_level($v) { return $v === null ? 'info' : ($v >= 80 ? 'critical' : ($v >= 60 ? 'warning' : 'normal')); }
function util_findings($u) {
    $f = []; if (!$u) return $f;
    foreach ($u['nodes'] as $n) {
        if (($n['cpu_avg'] ?? 0) >= 60) $f[] = ['severity' => util_level($n['cpu_avg']), 'category' => '성능', 'title' => "{$n['node']} CPU 사용률 평균 {$n['cpu_avg']}% (최대 {$n['cpu_max']}%)", 'detail' => 'sysstat -x 기준',
            'recommendation' => 'CPU를 많이 쓰는 작업(스냅샷·복제·스크럽 일정, 특정 볼륨 부하)을 확인하고 부하 분산을 검토하십시오.'];
        if (($n['disk_avg'] ?? 0) >= 60) $f[] = ['severity' => util_level($n['disk_avg']), 'category' => '성능', 'title' => "{$n['node']} 디스크 사용률 평균 {$n['disk_avg']}% (최대 {$n['disk_max']}%)", 'detail' => 'sysstat -x Disk util 기준',
            'recommendation' => '디스크 병목 여부를 확인하고 Aggregate 디스크 증설 또는 부하 분산을 검토하십시오.'];
    }
    foreach ($u['ifaces'] as $x) if (($x['util'] ?? 0) >= 60) $f[] = ['severity' => util_level($x['util']), 'category' => '성능', 'title' => "{$x['node']} {$x['if']} 네트워크 사용률 {$x['util']}%", 'detail' => "수신 {$x['rx_mbps']}Mb/s · 송신 {$x['tx_mbps']}Mb/s · 속도 {$x['speed_mbps']}Mb/s",
        'recommendation' => '인터페이스 대역폭을 확인하고 LIF 분산·링크 증설(ifgrp)을 검토하십시오.'];
    return $f;
}
/* 장비 접속 정보 — network interface show / system service-processor show 에서 관리 IP 를 모읍니다.
   결과: ['cluster_mgmt' => IP, 'node_mgmt' => [노드 => IP], 'sp' => [노드 => IP]] (못 찾으면 []) */
function access_parse($txt) {
    $out = ['cluster_mgmt' => '', 'node_mgmt' => [], 'sp' => []];
    foreach (cdot_sections($txt) as $sec) {
        $cmd = $sec['cmd'];
        if (preg_match('/^net(work)?\s+int(erface)?\s+show/', $cmd)) {
            foreach ($sec['lines'] as $l) {
                // "Vserver  LIF  up/up  IP/mask  Node  Port ..." 또는 Vserver 가 윗줄에 있고 LIF 부터 시작하는 줄
                if (!preg_match('/^\s*(?:\S+\s+)?(\S+)\s+(up|down)\/(up|down)\s+(\d+\.\d+\.\d+\.\d+)\/\d+\s+(\S+)\s+(\S+)/', $l, $m)) continue;
                $lif = $m[1]; $ip = $m[4]; $node = $m[5];
                if (stripos($lif, 'cluster_mgmt') !== false) { if (!$out['cluster_mgmt']) $out['cluster_mgmt'] = $ip; }
                elseif (stripos($lif, 'mgmt') !== false && !isset($out['node_mgmt'][$node])) $out['node_mgmt'][$node] = $ip;
            }
        }
        if (preg_match('/^(system\s+)?service-processor\s+(network\s+)?show|^sp\s+show/', $cmd)) {
            foreach ($sec['lines'] as $l) if (preg_match('/^\s*(\S+)\s+\S+.*?\b(\d+\.\d+\.\d+\.\d+)\b/', $l, $m) && !preg_match('/^(Node|---)/', $m[1])) $out['sp'][$m[1]] = $out['sp'][$m[1]] ?? $m[2];
        }
    }
    if (!$out['cluster_mgmt'] && !$out['node_mgmt'] && !$out['sp']) return [];
    return $out;
}
/* 정기·특별 공통으로 사용률 수집 명령어 (점검 항목 표와는 별도) */
function util_commands() {
    return ['# [시스템 사용률 (CPU·디스크·네트워크)]',
        'network interface show',
        'system node run -node * -command "sysstat -x -c 5 2"',
        'system node run -node * -command "ifstat -a"',
        'statistics show-periodic -iterations 5 -interval 2'];
}
/* 보고서 표: [노드 표, 인터페이스 표] (머리줄 포함). 인터페이스는 사용률 높은 순 $lim 개 */
function util_ko($v) { return ['critical' => '위험', 'warning' => '요주의', 'normal' => '정상', 'info' => '-'][util_level($v)]; }
function util_tables($u, $lim = 8) {
    $f = function ($v, $sfx = '') { return $v === null ? '-' : $v . $sfx; };
    $n = [['노드', 'CPU 평균/최대', '디스크 평균/최대', '네트워크 수신/송신', '판정']];
    foreach ($u['nodes'] as $x) $n[] = [$x['node'], $f($x['cpu_avg'], '%') . ' / ' . $f($x['cpu_max'], '%'), $f($x['disk_avg'], '%') . ' / ' . $f($x['disk_max'], '%'),
        $f($x['net_in_mbps']) . ' / ' . $f($x['net_out_mbps']) . ' Mb/s', util_ko(max($x['cpu_avg'] ?? 0, $x['disk_avg'] ?? 0))];
    if (!empty($u['cluster']) && !$u['nodes']) $n[] = ['클러스터', $u['cluster']['cpu_avg'] . '% / ' . $u['cluster']['cpu_max'] . '%', '-', '-', util_ko($u['cluster']['cpu_avg'])];
    $i = [['노드', '인터페이스', '속도', '수신 Mb/s', '송신 Mb/s', '사용률', '판정']];
    foreach (array_slice($u['ifaces'], 0, $lim) as $x) $i[] = [$x['node'], $x['if'] . ($x['state'] && $x['state'] !== 'up' ? " ({$x['state']})" : ''),
        $x['speed_mbps'] ? ($x['speed_mbps'] >= 1000 ? ($x['speed_mbps'] / 1000) . 'G' : $x['speed_mbps'] . 'M') : '-', $x['rx_mbps'], $x['tx_mbps'], $f($x['util'], '%'), util_ko($x['util'])];
    return [count($n) > 1 ? $n : null, count($i) > 1 ? $i : null];
}
