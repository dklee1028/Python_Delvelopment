<?php
/* 보고서 파이프라인: 로그 분석 → 컨텍스트 → PPT/엑셀(+고객양식) 생성 */

function variant_for($n) { return $n <= 1 ? 'single' : ($n <= 3 ? 'multi' : 'fleet'); }
function next_report_no() {
    $prefix = 'MRS-' . date('Ymd') . '-';
    $last = val("SELECT report_no FROM mrs_report WHERE report_no LIKE ? ORDER BY report_no DESC LIMIT 1", [$prefix . '%']);
    $n = $last ? (int)substr($last, strrpos($last, '-') + 1) + 1 : 1;
    return $prefix . sprintf('%03d', $n);
}
function report_get($rid) { $r = one("SELECT * FROM mrs_report WHERE id=?", [(int)$rid]); if (!$r) fail('보고서를 찾을 수 없습니다.', 404); return $r; }
function report_eq_links($rid) {
    return all("SELECT re.seq, e.* FROM mrs_report_equipment re JOIN mrs_equipment e ON e.id=re.equipment_id WHERE re.report_id=? ORDER BY re.seq", [$rid]);
}
function report_log_ids($rid) { return array_map('intval', array_column(all("SELECT log_id FROM mrs_report_log WHERE report_id=?", [$rid]), 'log_id')); }

/* 연결된 로그를 장비별로 병합·분석하여 analysis 에 저장 */
function run_analysis($r) {
    $links = report_eq_links($r['id']); $lids = report_log_ids($r['id']);
    $logs = $lids ? all('SELECT id, equipment_id, collected_at, parsed FROM mrs_log_file WHERE id IN (' . implode(',', $lids) . ') ORDER BY collected_at') : [];
    $out = []; $per = [];
    foreach ($links as $eq) {
        $mine = array_values(array_filter($logs, function ($lg) use ($eq, $links) { return (int)$lg['equipment_id'] === (int)$eq['id'] || ($lg['equipment_id'] === null && count($links) === 1); }));
        $auto = false;
        if (!$mine) { // 이 장비 로그를 고르지 않았으면 점검일 기준 가장 최근 로그를 자동으로 씁니다
            $lg = one("SELECT id, equipment_id, collected_at, parsed FROM mrs_log_file WHERE equipment_id=? AND collected_at<=? ORDER BY collected_at DESC, id DESC LIMIT 1", [$eq['id'], ($r['inspection_date'] ?: today()) . ' 23:59:59'])
                ?: one("SELECT id, equipment_id, collected_at, parsed FROM mrs_log_file WHERE equipment_id=? ORDER BY collected_at DESC, id DESC LIMIT 1", [$eq['id']]);
            if ($lg) { $mine = [$lg]; $auto = true; }
        }
        $parsed = merge_parsed(array_map(function ($lg) { return jd($lg['parsed'], []); }, $mine));
        $parsed = filter_volumes($parsed, (string)($eq['exclude_vols'] ?? ''));
        // 성능 아카이브 세부 분석은 특별점검 보고서에만 넣습니다
        $perf = (int)($r['include_perf'] ?? 1) && $r['report_type'] === 'special' ? perf_for_equipment($eq['id'], $r['inspection_date']) : null;
        $sys = (array)$parsed['system']; $fill = [];
        foreach (['model' => 'model', 'serial' => 'serial', 'os_version' => 'os_version'] as $col => $k) if (!$eq[$col] && !empty($sys[$k])) { $fill[$col] = mb_substr($sys[$k], 0, 100); $eq[$col] = $sys[$k]; }
        if ($fill) upd('mrs_equipment', $fill + ['updated_at' => now()], $eq['id']);
        $extra = array_merge($perf['findings'] ?? [], util_findings($parsed['util'] ?? null), ($parsed['os_mode'] ?? '') === 'cdot' ? cdot_findings((array)($parsed['cdot'] ?? []), $r['report_type']) : []);
        $a = analyze(['model' => $eq['model'], 'os_version' => $eq['os_version'], 'ignore' => ignore_rules_for($eq['id'])], $parsed, $extra);
        $a['visual_hints'] = visual_hints($parsed, $a, $perf);
        $out[(string)$eq['id']] = ['parsed' => $parsed, 'analysis' => $a, 'log_ids' => array_map(function ($l) { return (int)$l['id']; }, $mine), 'auto_logs' => $auto, 'perf' => $perf];
        $per[] = $a;
    }
    $st = $per ? overall_status($per) : 'normal';
    upd('mrs_report', ['analysis' => je($out), 'overall_status' => $st, 'updated_at' => now()], $r['id']);
    return $out;
}

/* 보고서 볼륨 표에서 빼는 것: 루트 볼륨(vol0, *_root, MDV_*)과 장비에 적어 둔 제외 대상(SVM 이름·볼륨 이름·SVM:볼륨, * 사용 가능) */
function is_root_vol($v) { return (bool)preg_match('/^(vol0|root|rootvol|vsroot|svm_?root)$|_root$|^root_|^MDV_|^vol0_/i', $v['name']); }
function vol_excluded($v, $pats) {
    foreach ($pats as $pt) {
        $pt = strtolower($pt); $svm = strtolower((string)($v['node'] ?? '')); $n = strtolower($v['name']);
        if (fnmatch($pt, $n) || ($svm !== '' && (fnmatch($pt, $svm) || fnmatch($pt, "$svm:$n")))) return true;
    }
    return false;
}
function filter_volumes($p, $excl) {
    $pats = array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/', $excl))));
    $keep = []; $hidden = 0;
    foreach ($p['volumes'] ?? [] as $v) { if (is_root_vol($v) || vol_excluded($v, $pats)) { $hidden++; continue; } $keep[] = $v; }
    $p['volumes'] = $keep; $p['volumes_hidden'] = $hidden;
    return $p;
}
/* 보고서에 넣을 점검 명령어 결과 (cDOT 로그일 때만). 정기 = 평시, 특별 = 평시 + 상세 */
function cmd_check_rows($p, $type) {
    if (($p['os_mode'] ?? '') !== 'cdot') return [];
    $ck = (array)($p['cdot'] ?? []); $rows = [];
    foreach (cdot_items_for($type === 'special' ? 'special' : 'regular') as $c) {
        $r = $ck[$c['id']] ?? null;
        $rows[] = ['id' => $c['id'], 'kind' => $c['kind'], 'group' => $c['group'], 'cmd' => $c['cmd'], 'point' => $c['point'],
            'status' => $r ? $r['status'] : 'missing', 'summary' => $r ? $r['summary'] : '로그에 없음', 'excerpt' => $r['excerpt'] ?? []];
    }
    return $rows;
}
function engineer_of($r) {
    $u = $r['engineer_id'] ? one("SELECT full_name, title, phone, email FROM mrs_user WHERE id=?", [$r['engineer_id']]) : null;
    return ['name' => $u['full_name'] ?? '', 'title' => $u['title'] ?? '', 'phone' => $u['phone'] ?? '', 'email' => $u['email'] ?? ''];
}
function build_context($r) {
    $r = one("SELECT * FROM mrs_report WHERE id=?", [$r['id']]);
    $cust = one("SELECT * FROM mrs_customer WHERE id=?", [$r['customer_id']]);
    $contacts = all("SELECT * FROM mrs_customer_contact WHERE customer_id=? ORDER BY is_primary DESC, id", [$cust['id']]);
    $contact = $contacts[0] ?? null;
    $eng = engineer_of($r);
    $analysis = jd($r['analysis'], []); $vis = jd($r['visual_inspection'], []);
    $eqs = [];
    foreach (report_eq_links($r['id']) as $i => $e) {
        $data = $analysis[(string)$e['id']] ?? null;
        if (!$data) { $p = merge_parsed([]); $data = ['parsed' => $p, 'analysis' => analyze([], $p)]; }
        $seq = $i + 1;
        $eqs[] = ['id' => (int)$e['id'], 'seq' => $seq, 'label' => "System $seq (" . ($e['model'] ?: $e['name']) . ')', 'name' => $e['name'], 'vendor' => $e['vendor'] ?: '',
            'model' => $e['model'] ?: '-', 'serial' => $e['serial'] ?: '-', 'os_version' => $e['os_version'] ?: '-', 'disk_info' => $e['disk_info'] ?: '-',
            'ha_type' => $e['ha_type'] ?: '', 'location' => $e['location'] ?: '', 'parsed' => $data['parsed'], 'analysis' => $data['analysis'],
            'visual' => $vis[(string)$e['id']] ?? [], 'perf' => $data['perf'] ?? null, 'cmd_checks' => cmd_check_rows($data['parsed'], $r['report_type']), 'util' => $data['parsed']['util'] ?? null, 'checks' => auto_checks($data['parsed'], $data['analysis'], $e['os_version'] ?: '')];
    }
    $st = $r['overall_status'] ?: 'normal'; $plan = jd($r['plan'], []);
    if (!empty($plan['steps'])) $plan['steps'] = number_steps($plan['steps']);
    return [
        'report' => ['id' => (int)$r['id'], 'no' => $r['report_no'], 'type' => $r['report_type'], 'type_label' => REPORT_TYPES[$r['report_type']] ?? $r['report_type'],
            'subtype_label' => WORK_SUBTYPES[$r['work_subtype']] ?? '', 'title' => $r['title'], 'date' => $r['inspection_date'] ?: today(),
            'variant' => $r['variant'], 'variant_label' => VARIANT_LABEL[$r['variant']] ?? '', 'overall_status' => $st, 'overall_label' => STATUS_LONG[$st] ?? '',
            'service_codes' => jd($r['service_codes'], []), 'sections' => service_sections(jd($r['service_codes'], [])),
            'service' => service_rows(jd($r['service_codes'], []), (array)(jd($r['plan'], [])['service'] ?? []))],
        // 연락처는 회사 대표번호가 아니라 보고서 담당 엔지니어(사용자 등록 정보)의 연락처를 사용합니다.
        'company' => ['name' => conf('company_name'), 'address' => conf('company_address'), 'tel' => $eng['phone'], 'email' => $eng['email']],
        'customer' => ['name' => $cust['name'], 'address' => $cust['address'] ?: '', 'site' => $cust['site'] ?: '', 'contact_name' => $contact['name'] ?? '',
            'contact_phone' => $contact['phone'] ?? '', 'contact_email' => $contact['email'] ?? ''],
        'engineer' => $eng, 'equipments' => $eqs,
        'scope' => INSPECTION_SCOPE[$r['report_type']] ?? INSPECTION_SCOPE['regular'],
        'diagnosis' => $r['diagnosis'] ?: '', 'final_comment' => $r['final_comment'] ?: '(최종 Comment 미입력)',
        'plan' => $plan, 'plan_title' => ($plan['title'] ?? '') ?: ((WORK_SUBTYPES[$r['work_subtype']] ?? '작업') . ' 작업계획서'),
    ];
}
function safe_stem($s) { return mb_substr(trim(preg_replace('#[\\\\/:*?"<>|\s]+#u', '_', $s), '_'), 0, 60); }
function report_dir($no) { $d = data_dir() . '/reports/' . preg_replace('/[^A-Za-z0-9\-]/', '', $no); if (!is_dir($d)) mkdir($d, 0755, true); protect_dir(data_dir() . '/reports'); return $d; }
function generate_files($r, $final) {
    $ctx = build_context($r);
    $dir = report_dir($r['report_no']);
    foreach (all("SELECT * FROM mrs_report_file WHERE report_id=?", [$r['id']]) as $f) @unlink(data_dir() . '/' . $f['stored_path']);
    q("DELETE FROM mrs_report_file WHERE report_id=?", [$r['id']]);
    $date = str_replace('-', '', $ctx['report']['date']);
    $wp = $r['report_type'] === 'workplan';
    $stem = safe_stem("{$date}_{$ctx['customer']['name']}_" . ($wp ? $ctx['plan_title'] : $ctx['report']['type_label'] . '_' . $ctx['report']['variant_label'])) . ($final ? '' : '_검토본');
    $made = [];
    build_pptx($ctx, "$dir/$stem.pptx"); $made[] = ['pptx', 'standard', "$stem.pptx"];
    build_xlsx($ctx, "$dir/$stem.xlsx"); $made[] = ['xlsx', 'standard', "$stem.xlsx"];
    if ($r['template_id']) {
        $t = one("SELECT * FROM mrs_report_template WHERE id=?", [$r['template_id']]);
        if ($t && is_file(data_dir() . '/' . $t['stored_path'])) {
            $name = "{$stem}_고객양식.{$t['file_format']}";
            tpl_fill(data_dir() . '/' . $t['stored_path'], $t['file_format'], $ctx, "$dir/$name");
            $made[] = [$t['file_format'], 'custom', $name];
        }
    }
    $rel = 'reports/' . basename($dir);
    foreach ($made as [$fmt, $kind, $name]) ins('mrs_report_file', ['report_id' => $r['id'], 'file_format' => $fmt, 'kind' => $kind, 'filename' => $name,
        'stored_path' => "$rel/$name", 'size_bytes' => filesize("$dir/$name"), 'created_at' => now()]);
}
function missing_for_completion($r) {
    $miss = [];
    if (trim($r['diagnosis'] ?? '') === '') $miss[] = '장애 및 진단 의견(AI 조언 검토 후 확정)';
    if (!(int)$r['diagnosis_reviewed']) $miss[] = '진단 의견 엔지니어 검토 확인';
    $vis = jd($r['visual_inspection'], []);
    foreach (report_eq_links($r['id']) as $e) {
        $sel = $vis[(string)$e['id']] ?? []; $lack = 0;
        foreach (VISUAL_ITEMS as [$k]) if (!in_array($sel[$k] ?? '', VISUAL_CHOICES, true)) $lack++;
        if ($lack) $miss[] = "{$e['name']} 육안점검 {$lack}개 항목 미선택";
    }
    if (trim($r['final_comment'] ?? '') === '') $miss[] = '엔지니어 최종 Comment';
    return $miss;
}

/* 서비스 작업 내역: 고른 코드 중 입력 칸이 있는 코드마다 [제목, [[이름, 내용], ...]] */
function service_rows($codes, $svc) {
    $out = [];
    foreach (array_values(array_intersect((array)$codes, SERVICE_CODES)) as $c) {
        $pf = SERVICE_PROFILE[$c]; if (!$pf['fields']) continue;
        $v = (array)($svc[$c] ?? []); $rows = [];
        // 고객에게 나가는 보고서이므로 비어 있는 칸은 넣지 않습니다. 하나도 안 적었으면 그 코드의 표는 생략
        foreach ($pf['fields'] as [$k, $label]) if (($t = trim((string)($v[$k] ?? ''))) !== '') $rows[] = [$label, $t];
        if (!$rows) continue;
        $out[] = ['code' => $c, 'title' => "$c · {$pf['label']}", 'rows' => $rows];
    }
    return $out;
}
