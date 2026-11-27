<?php
/* MRS - API 진입점.  호출: api/index.php?p=리소스/경로&쿼리...
   일부 웹호스팅은 PUT/DELETE/PATCH 를 막으므로 화면은 항상 POST + X-HTTP-Method-Override 헤더로 보냅니다. */
require __DIR__ . '/lib.php';
foreach (['catalog', 'parser', 'analyzer', 'ai', 'mailer', 'pptx_kit', 'pptx_report', 'xlsx', 'custom_template', 'report_service'] as $f) require __DIR__ . "/inc/$f.php";
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@set_time_limit(300);
error_reporting(E_ALL);
ob_start();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out($d, $code = 200) { if (ob_get_level()) ob_clean(); http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR); exit; }
function fail($m, $code = 400) { out(['detail' => $m], $code); }
function send_file($path, $name, $type = 'application/octet-stream') {
    if (!is_file($path)) fail('파일이 없습니다.', 404);
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: ' . $type);
    header('Content-Length: ' . filesize($path));
    header("Content-Disposition: attachment; filename=\"" . rawurlencode($name) . "\"; filename*=UTF-8''" . rawurlencode($name));
    readfile($path); exit;
}

if (!cfg()) fail('설치가 필요합니다. install.php 를 먼저 실행하세요.', 503);
set_exception_handler(function ($e) { error_log('MRS: ' . $e); fail('서버 오류: ' . $e->getMessage(), 500); });
try { ensure_schema(); } catch (Throwable $e) { fail('DB 접속 실패: ' . $e->getMessage(), 500); }

/* ---------- 세션 (PMS 와 같은 방식: 설치 경로에만 쿠키, 전용 세션 폴더) ---------- */
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$base = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/index.php'))), '/') . '/';
$sdir = data_dir() . '/sess';
if (!is_dir($sdir)) { protect_dir(data_dir()); @mkdir($sdir, 0700, true); @file_put_contents("$sdir/index.html", ''); }
if (is_dir($sdir) && is_writable($sdir)) { session_save_path($sdir); @ini_set('session.gc_probability', '1'); @ini_set('session.gc_divisor', '100'); }
@ini_set('session.gc_maxlifetime', '28800');
@ini_set('session.use_strict_mode', '1');
session_name('MRSSESS');
session_set_cookie_params(['lifetime' => 0, 'path' => $base, 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
session_start();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'POST' && !empty($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) $method = strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
// 다른 사이트에서 몰래 보내는 요청(CSRF) 차단: 상태를 바꾸는 요청은 화면이 붙이는 헤더가 있어야 함
if ($method !== 'GET' && ($_SERVER['HTTP_X_MRS'] ?? '') !== '1') fail('잘못된 요청입니다.', 403);
$raw = (string)file_get_contents('php://input');
if (strncmp($raw, 'b64:', 4) === 0) $raw = (string)base64_decode(substr($raw, 4));
$in = json_decode($raw, true) ?: [];
$path = trim((string)($_GET['p'] ?? ''), '/');

function me() {
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['uid'])) {
            if (time() - ($_SESSION['seen'] ?? 0) > 28800) $_SESSION = [];
            else {
                $_SESSION['seen'] = time();
                $u = one("SELECT * FROM mrs_user WHERE id=? AND is_active=1", [$_SESSION['uid']]);
                if ($u) $u['perms'] = effective_perms($u);
            }
        }
    }
    return $u;
}
function need_login() { $u = me(); if (!$u) fail('로그인이 필요합니다.', 401); return $u; }
function need($p) { $u = need_login(); if (!in_array($p, $u['perms'], true)) fail('권한이 없습니다: ' . (PERMISSIONS[$p] ?? $p), 403); return $u; }
function audit($action, $target = '', $detail = '') {
    $u = me();
    ins('mrs_audit_log', ['at' => now(), 'user_id' => $u['id'] ?? null, 'user_name' => $u ? mb_substr(($u['full_name'] ?: $u['username']), 0, 110) : '-',
        'action' => $action, 'target' => mb_substr((string)$target, 0, 150), 'detail' => mb_substr((string)$detail, 0, 2000), 'ip' => mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
}
function b($v) { return $v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'on'; }
function g($k, $def = '') { return $_GET[$k] ?? $def; }

/* ---------- 라우터 ---------- */
$R = [];
function route($m, $pat, $fn) { global $R; $R[] = [$m, '#^' . $pat . '$#', $fn]; }

/* ===== 인증 · 사용자 ===== */
function user_out($u) {
    return ['id' => (int)$u['id'], 'username' => $u['username'], 'full_name' => $u['full_name'] ?: '', 'email' => $u['email'] ?: '', 'phone' => $u['phone'] ?: '', 'title' => $u['title'] ?: '',
        'role' => $u['role'], 'role_label' => ROLE_LABELS[$u['role']] ?? $u['role'], 'permissions' => effective_perms($u), 'overrides' => (object)jd($u['permissions'], []),
        'is_active' => (bool)(int)$u['is_active'], 'must_change_pw' => (bool)(int)$u['must_change_pw'], 'last_login_at' => $u['last_login_at'],
        'assigned' => ['main' => (int)val("SELECT COUNT(*) FROM mrs_customer_assign WHERE user_id=? AND kind='main'", [$u['id']]), 'sub' => (int)val("SELECT COUNT(*) FROM mrs_customer_assign WHERE user_id=? AND kind='sub'", [$u['id']])]];
}
route('POST', 'auth/login', function () use ($in) {
    $u = one("SELECT * FROM mrs_user WHERE username=?", [s($in['username'] ?? '', 64)]);
    if ($u && $u['locked_until'] && $u['locked_until'] > now()) fail('로그인 5회 실패로 10분간 잠겼습니다. 잠시 후 다시 시도하세요.', 429);
    if (!$u || !(int)$u['is_active'] || !password_verify((string)($in['password'] ?? ''), $u['password_hash'])) {
        if ($u) { $fc = (int)$u['fail_count'] + 1; upd('mrs_user', ['fail_count' => $fc >= 5 ? 0 : $fc, 'locked_until' => $fc >= 5 ? date('Y-m-d H:i:s', time() + 600) : null], $u['id']); }
        fail('아이디 또는 비밀번호가 올바르지 않습니다.', 401);
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id']; $_SESSION['seen'] = time();
    upd('mrs_user', ['last_login_at' => now(), 'fail_count' => 0, 'locked_until' => null], $u['id']);
    audit('login');
    out(user_out(one("SELECT * FROM mrs_user WHERE id=?", [$u['id']])));
});
route('POST', 'auth/logout', function () { $_SESSION = []; session_destroy(); out(['ok' => true]); });
route('GET', 'auth/me', function () { out(user_out(need_login())); });
route('POST', 'auth/password', function () use ($in) {
    $u = need_login();
    if (!password_verify((string)($in['current'] ?? ''), $u['password_hash'])) fail('현재 비밀번호가 올바르지 않습니다.');
    if ($e = pw_check((string)($in['new'] ?? ''))) fail($e);
    upd('mrs_user', ['password_hash' => password_hash($in['new'], PASSWORD_DEFAULT), 'must_change_pw' => 0, 'updated_at' => now()], $u['id']);
    audit('password.change', $u['username']);
    out(['ok' => true]);
});
route('GET', 'permissions', function () { need_login(); out(['permissions' => PERMISSIONS, 'roles' => ROLE_LABELS, 'role_defaults' => role_defaults()]); });
route('GET', 'users', function () { need_login(); out(array_map('user_out', all("SELECT * FROM mrs_user ORDER BY is_active DESC, id"))); });
function user_save($id, $in) {
    $me = need('user.manage');
    $role = $in['role'] ?? 'engineer';
    if (!isset(ROLE_LABELS[$role])) fail('알 수 없는 역할입니다.');
    $ov = []; foreach ((array)($in['overrides'] ?? []) as $k => $v) if (isset(PERMISSIONS[$k])) $ov[$k] = (bool)$v;
    $d = ['full_name' => s($in['full_name'] ?? '', 100), 'email' => s($in['email'] ?? '', 200), 'phone' => s($in['phone'] ?? '', 50), 'title' => s($in['title'] ?? '', 50),
        'role' => $role, 'permissions' => je((object)$ov), 'is_active' => b($in['is_active'] ?? true) ? 1 : 0, 'updated_at' => now()];
    if ($d['email'] && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) fail('이메일 형식이 올바르지 않습니다.');
    $pw = (string)($in['password'] ?? '');
    if ($id === null) {
        $un = s($in['username'] ?? '', 64);
        if (!preg_match('/^[A-Za-z0-9_.\-]{3,64}$/', $un)) fail('아이디는 영문/숫자 3자 이상이어야 합니다.');
        if (one("SELECT id FROM mrs_user WHERE username=?", [$un])) fail('이미 존재하는 아이디입니다.');
        if ($e = pw_check($pw)) fail('초기 ' . $e);
        $id = ins('mrs_user', $d + ['username' => $un, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'must_change_pw' => 1, 'created_at' => now()]);
        audit('user.create', $un);
    } else {
        $u = one("SELECT * FROM mrs_user WHERE id=?", [$id]); if (!$u) fail('사용자를 찾을 수 없습니다.', 404);
        if ((int)$u['id'] === (int)$me['id'] && ($role !== 'admin' || !$d['is_active'])) fail('본인의 관리자 권한/활성 상태는 변경할 수 없습니다.');
        if ($pw !== '') { if ($e = pw_check($pw)) fail($e); $d['password_hash'] = password_hash($pw, PASSWORD_DEFAULT); $d['must_change_pw'] = 1; }
        upd('mrs_user', $d, $id);
        audit('user.update', $u['username']);
    }
    out(user_out(one("SELECT * FROM mrs_user WHERE id=?", [$id])));
}
route('POST', 'users', function () use ($in) { user_save(null, $in); });
route('PUT', 'users/(\d+)', function ($id) use ($in) { user_save((int)$id, $in); });
route('DELETE', 'users/(\d+)', function ($id) {
    $me = need('user.manage');
    $u = one("SELECT * FROM mrs_user WHERE id=?", [$id]); if (!$u) fail('사용자를 찾을 수 없습니다.', 404);
    if ((int)$u['id'] === (int)$me['id']) fail('본인 계정은 삭제할 수 없습니다.');
    if ($u['role'] === 'admin' && !val("SELECT COUNT(*) FROM mrs_user WHERE role='admin' AND is_active=1 AND id<>?", [$id])) fail('마지막 관리자는 삭제할 수 없습니다.');
    // 보고서에 남은 담당 엔지니어 기록은 비우고 계정만 삭제
    q("UPDATE mrs_report SET engineer_id=NULL WHERE engineer_id=?", [$id]);
    q("DELETE FROM mrs_customer_assign WHERE user_id=?", [$id]);
    q("DELETE FROM mrs_user WHERE id=?", [$id]);
    audit('user.delete', $u['username']);
    out(['ok' => true]);
});
/* 담당 고객 이관: 한 사용자의 정/부 배정을 다른 사용자에게 옮깁니다. 보고서·로그·장비는 고객에 붙어 있어 그대로 유지됩니다. */
route('POST', 'users/(\d+)/transfer', function ($id) use ($in) {
    need('user.manage'); $from = one("SELECT * FROM mrs_user WHERE id=?", [$id]); $to = one("SELECT * FROM mrs_user WHERE id=? AND is_active=1", [(int)($in['to_user_id'] ?? 0)]);
    if (!$from || !$to) fail('사용자를 찾을 수 없습니다.', 404);
    if ((int)$from['id'] === (int)$to['id']) fail('같은 사용자입니다.');
    $only = array_map('intval', (array)($in['customer_ids'] ?? [])); $n = 0;
    foreach (all("SELECT * FROM mrs_customer_assign WHERE user_id=?", [$from['id']]) as $a) {
        if ($only && !in_array((int)$a['customer_id'], $only, true)) continue;
        assign_set((int)$a['customer_id'], (int)$to['id'], $a['kind']); q("DELETE FROM mrs_customer_assign WHERE id=?", [$a['id']]); $n++;
    }
    if (b($in['reports'] ?? false)) q("UPDATE mrs_report SET engineer_id=? WHERE engineer_id=? AND status<>'completed'", [$to['id'], $from['id']]);
    audit('assign.transfer', $from['username'] . ' → ' . $to['username'], "{$n}개 고객");
    out(['moved' => $n]);
});
route('GET', 'audit', function () {
    need('user.manage');
    $rows = all("SELECT * FROM mrs_audit_log ORDER BY id DESC LIMIT " . min(500, max(1, (int)g('limit', 100))));
    out(array_map(function ($r) { return ['at' => str_replace(' ', 'T', $r['at']), 'user' => $r['user_name'] ?: '-', 'action' => $r['action'], 'target' => $r['target'] ?: '', 'detail' => $r['detail'] ?: '']; }, $rows));
});

/* ===== 기존 고객 DB (PMS pms_customers, 읽기 전용) ===== */
function legacy_table() { $t = conf('legacy_table'); return preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $t) && has_table($t) ? $t : null; }
function legacy_select($t) {
    $cols = table_columns($t); $q = function ($c) { return driver() === 'sqlite' ? "\"$c\"" : "`$c`"; };
    $f = ["{$q('id')} AS id", "{$q('name')} AS name", (in_array('address', $cols, true) ? $q('address') : "''") . ' AS address'];
    $ext = array_values(array_intersect(['code', 'phone', 'contact1', 'contact1_phone', 'contact1_email', 'contact2', 'contact2_phone'], $cols));
    foreach ($ext as $c) $f[] = $q($c) . " AS x_$c";
    return ['SELECT ' . implode(', ', $f) . ' FROM ' . $q($t), $ext, in_array('is_active', $cols, true) ? $q('is_active') . '=1' : '1=1', $q];
}
function legacy_row($r, $ext) { $d = ['id' => (string)$r['id'], 'name' => $r['name'] ?: '', 'address' => $r['address'] ?: '']; foreach ($ext as $c) $d[$c] = $r["x_$c"] ?: ''; return $d; }
function legacy_get($id) { $t = legacy_table(); if (!$t) return null; [$sql, $ext, , $q] = legacy_select($t); $r = one("$sql WHERE {$q('id')}=?", [$id]); return $r ? legacy_row($r, $ext) : null; }
route('GET', 'legacy/customers', function () {
    need('customer.view'); $t = legacy_table();
    if (!$t) out(['enabled' => false, 'items' => []]);
    [$sql, $ext, $where, $q] = legacy_select($t); $p = []; $sql .= " WHERE $where"; $kw = trim((string)g('q'));
    if ($kw !== '') { $sql .= " AND ({$q('name')} LIKE ? OR " . (in_array('code', $ext, true) ? "{$q('code')} LIKE ? OR " : '') . (in_array('address', table_columns($t), true) ? "{$q('address')} LIKE ?" : '1=0') . ")";
        $p = array_fill(0, 1 + (in_array('code', $ext, true) ? 1 : 0) + (in_array('address', table_columns($t), true) ? 1 : 0), "%$kw%"); }
    $rows = array_map(function ($r) use ($ext) { return legacy_row($r, $ext); }, all($sql . " ORDER BY {$q('name')}", $p));
    $linked = []; foreach (all("SELECT id, legacy_customer_id FROM mrs_customer WHERE legacy_customer_id IS NOT NULL") as $c) $linked[$c['legacy_customer_id']] = (int)$c['id'];
    $mains = assign_names('main');
    foreach ($rows as &$r) { $r['mrs_customer_id'] = $linked[$r['id']] ?? null; $r['main_name'] = $r['mrs_customer_id'] ? ($mains[$r['mrs_customer_id']] ?? '') : ''; }
    out(['enabled' => true, 'table' => $t, 'total' => count($rows), 'items' => $rows]);
});
route('POST', 'legacy/customers/([^/]+)/link', function ($lid) {
    need('customer.edit'); $src = legacy_get($lid); if (!$src) fail('기존 고객 DB에서 찾을 수 없습니다.', 404);
    $ex = one("SELECT id FROM mrs_customer WHERE legacy_customer_id=?", [$lid]); $kind = assign_kind($GLOBALS['in']['kind'] ?? '');
    if ($ex) { upd('mrs_customer', ['name' => $src['name'], 'address' => $src['address'], 'updated_at' => now()], $ex['id']); if ($kind) assign_self((int)$ex['id'], $kind); out(customer_out($ex['id'])); }
    $cid = ins('mrs_customer', ['legacy_customer_id' => $lid, 'name' => $src['name'], 'address' => $src['address'], 'site' => '', 'memo' => '', 'created_at' => now(), 'updated_at' => now()]);
    // PMS 고객에 등록된 담당자1·2를 전산담당자 초기값으로 가져옵니다(이후 MRS 에서 따로 관리).
    $k = 0;
    foreach (['contact1', 'contact2'] as $c) {
        $nm = trim($src[$c] ?? ''); if ($nm === '') continue;
        $em = $src[$c . '_email'] ?? '';
        ins('mrs_customer_contact', ['customer_id' => $cid, 'name' => $nm, 'phone' => $src[$c . '_phone'] ?? '', 'email' => $em, 'department' => '', 'position' => '',
            'receive_reports' => $em ? 1 : 0, 'is_primary' => $k++ === 0 ? 1 : 0, 'created_at' => now(), 'updated_at' => now()]);
    }
    if ($kind) assign_self((int)$cid, $kind);
    audit('customer.link', $src['name']);
    out(customer_out($cid));
});
route('POST', 'legacy/sync', function () {
    need('customer.edit'); $n = 0;
    foreach (all("SELECT * FROM mrs_customer WHERE legacy_customer_id IS NOT NULL") as $c) {
        $src = legacy_get($c['legacy_customer_id']);
        if ($src && ($src['name'] !== $c['name'] || $src['address'] !== $c['address'])) { upd('mrs_customer', ['name' => $src['name'], 'address' => $src['address'], 'updated_at' => now()], $c['id']); $n++; }
    }
    audit('customer.sync', '', "{$n}건 갱신");
    out(['updated' => $n]);
});

/* ===== 고객 · 전산담당자 · 장비 ===== */
function contact_out($c) { return ['id' => (int)$c['id'], 'name' => $c['name'], 'department' => $c['department'] ?: '', 'position' => $c['position'] ?: '', 'email' => $c['email'] ?: '', 'phone' => $c['phone'] ?: '', 'receive_reports' => (bool)(int)$c['receive_reports'], 'is_primary' => (bool)(int)$c['is_primary']]; }
function eq_out($e) {
    $d = []; foreach (['id', 'customer_id', 'name', 'vendor', 'model', 'serial', 'os_version', 'ha_type', 'disk_info', 'location', 'contract_type', 'memo'] as $k) $d[$k] = in_array($k, ['id', 'customer_id'], true) ? (int)$e[$k] : ($e[$k] ?? '');
    $d['is_active'] = (bool)(int)$e['is_active']; $d['disposed'] = !empty($e['disposed_at']); $d['disposed_at'] = $e['disposed_at'] ? substr($e['disposed_at'], 0, 10) : null; $d['dispose_note'] = $e['dispose_note'] ?? '';
    $st = one("SELECT status, n_crit, n_warn, collected_at FROM mrs_log_file WHERE equipment_id=? ORDER BY collected_at DESC, id DESC LIMIT 1", [$e['id']]);
    $d['last_log'] = $st ? ['at' => substr($st['collected_at'], 0, 10), 'status' => $st['status'] ?: 'normal', 'crit' => (int)$st['n_crit'], 'warn' => (int)$st['n_warn']] : null;
    return $d;
}
/* ----- 담당 배정 (정/부) ----- */
function assign_kind($k) { return in_array($k, ['main', 'sub'], true) ? $k : ''; }
function assign_set($cid, $uid, $kind) {
    if ($kind === 'main') q("UPDATE mrs_customer_assign SET kind='sub' WHERE customer_id=? AND kind='main' AND user_id<>?", [$cid, $uid]); // 정은 고객당 1명, 기존 정은 부로
    $ex = one("SELECT id FROM mrs_customer_assign WHERE customer_id=? AND user_id=?", [$cid, $uid]);
    if ($ex) upd('mrs_customer_assign', ['kind' => $kind], $ex['id']);
    else ins('mrs_customer_assign', ['customer_id' => $cid, 'user_id' => $uid, 'kind' => $kind, 'created_at' => now()]);
}
// 다른 사람의 정 담당을 바꾸거나 남을 배정할 수 있는 사람: 관리자·팀장(또는 사용자 관리 권한)
function can_reassign($u) { return in_array('user.manage', $u['perms'], true) || in_array($u['role'], ['admin', 'manager'], true); }
function assign_self($cid, $kind) {
    $u = me(); $cur = val("SELECT user_id FROM mrs_customer_assign WHERE customer_id=? AND kind='main'", [$cid]);
    if ($kind === 'main' && $cur && (int)$cur !== (int)$u['id'] && !can_reassign($u)) $kind = 'sub'; // 이미 정 담당이 있으면 부로
    assign_set($cid, (int)$u['id'], $kind);
}
function assign_names($kind) { $m = []; foreach (all("SELECT a.customer_id, u.full_name, u.username FROM mrs_customer_assign a JOIN mrs_user u ON u.id=a.user_id WHERE a.kind=? ORDER BY a.id", [$kind]) as $r) $m[$r['customer_id']] = ($m[$r['customer_id']] ?? '') ? $m[$r['customer_id']] . ', ' . ($r['full_name'] ?: $r['username']) : ($r['full_name'] ?: $r['username']); return $m; }
function cust_assign($cid) {
    $rows = all("SELECT a.user_id, a.kind, u.full_name, u.username FROM mrs_customer_assign a JOIN mrs_user u ON u.id=a.user_id WHERE a.customer_id=? ORDER BY a.kind, a.id", [$cid]);
    $main = null; $subs = [];
    foreach ($rows as $r) { $x = ['id' => (int)$r['user_id'], 'name' => $r['full_name'] ?: $r['username']]; if ($r['kind'] === 'main') $main = $x; else $subs[] = $x; }
    return [$main, $subs];
}
/* 로그 상태 요약을 저장해 두고 고객 현황표에서 빠르게 씁니다. */
function log_stat_fill($where = '1=1', $p = []) {
    foreach (all("SELECT id, parsed FROM mrs_log_file WHERE status IS NULL AND $where LIMIT 300", $p) as $l) {
        $pa = jd($l['parsed'], []); $a = $pa ? analyze([], $pa) : ['status' => 'normal', 'findings' => []]; $c = ['critical' => 0, 'warning' => 0];
        foreach ($a['findings'] as $f) if (isset($c[$f['severity']])) $c[$f['severity']]++;
        upd('mrs_log_file', ['status' => $a['status'], 'n_crit' => $c['critical'], 'n_warn' => $c['warning']], $l['id']);
    }
}
function customer_out($cid, $detail = false) {
    $c = one("SELECT * FROM mrs_customer WHERE id=?", [$cid]); if (!$c) fail('고객을 찾을 수 없습니다.', 404);
    [$main, $subs] = cust_assign($cid); $uid = (int)(me()['id'] ?? 0);
    $d = ['id' => (int)$c['id'], 'legacy_customer_id' => $c['legacy_customer_id'], 'name' => $c['name'], 'address' => $c['address'] ?: '', 'site' => $c['site'] ?: '', 'memo' => $c['memo'] ?: '',
        'main' => $main, 'subs' => $subs, 'my_role' => $main && $main['id'] === $uid ? 'main' : (in_array($uid, array_column($subs, 'id'), true) ? 'sub' : null),
        'disposed_count' => (int)val("SELECT COUNT(*) FROM mrs_equipment WHERE customer_id=? AND disposed_at IS NOT NULL", [$cid]),
        'linked' => (bool)$c['legacy_customer_id'], 'equipment_count' => (int)val("SELECT COUNT(*) FROM mrs_equipment WHERE customer_id=? AND disposed_at IS NULL", [$cid]),
        'contact_count' => (int)val("SELECT COUNT(*) FROM mrs_customer_contact WHERE customer_id=?", [$cid]), 'report_count' => (int)val("SELECT COUNT(*) FROM mrs_report WHERE customer_id=?", [$cid])];
    if ($detail) {
        $d['contacts'] = array_map('contact_out', all("SELECT * FROM mrs_customer_contact WHERE customer_id=? ORDER BY id", [$cid]));
        log_stat_fill('customer_id=?', [$cid]);
        $d['equipment'] = array_map('eq_out', all("SELECT * FROM mrs_equipment WHERE customer_id=?" . (g('disposed') ? '' : ' AND disposed_at IS NULL') . " ORDER BY disposed_at IS NOT NULL, id", [$cid]));
    }
    return $d;
}
/* 고객 현황표: scope=mine(내 정·부 고객, 기본) / all(전체).  한 줄에 담당자·장비 수·현재 문제·최근 로그/보고서를 보여 줍니다. */
route('GET', 'customers', function () {
    $u = need('customer.view'); $uid = (int)$u['id']; $kw = trim((string)g('q')); $scope = g('scope', 'mine'); $p = []; $w = [];
    if ($kw !== '') { $w[] = "(c.name LIKE ? OR c.address LIKE ? OR c.site LIKE ?)"; array_push($p, "%$kw%", "%$kw%", "%$kw%"); }
    if ($scope === 'mine') { $w[] = "c.id IN (SELECT customer_id FROM mrs_customer_assign WHERE user_id=?)"; $p[] = $uid; }
    elseif ($scope === 'unassigned') $w[] = "c.id NOT IN (SELECT customer_id FROM mrs_customer_assign WHERE kind='main')";
    elseif (preg_match('/^user:(\d+)$/', $scope, $m)) { $w[] = "c.id IN (SELECT customer_id FROM mrs_customer_assign WHERE user_id=?)"; $p[] = (int)$m[1]; }
    $rows = all("SELECT c.* FROM mrs_customer c" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY c.name", $p);
    log_stat_fill();
    $as = []; foreach (all("SELECT a.customer_id, a.user_id, a.kind, u.full_name, u.username FROM mrs_customer_assign a JOIN mrs_user u ON u.id=a.user_id ORDER BY a.id") as $a) $as[$a['customer_id']][] = $a;
    $eq = []; foreach (all("SELECT customer_id, SUM(CASE WHEN disposed_at IS NULL THEN 1 ELSE 0 END) act, SUM(CASE WHEN disposed_at IS NULL THEN 0 ELSE 1 END) disp FROM mrs_equipment GROUP BY customer_id") as $e) $eq[$e['customer_id']] = $e;
    $st = []; // 사용 중 장비별 최신 로그 기준 현재 상태
    foreach (all("SELECT l.customer_id, l.status, l.n_crit, l.n_warn, l.collected_at FROM mrs_log_file l JOIN mrs_equipment e ON e.id=l.equipment_id AND e.disposed_at IS NULL
        JOIN (SELECT equipment_id, MAX(collected_at) m FROM mrs_log_file WHERE equipment_id IS NOT NULL GROUP BY equipment_id) x ON x.equipment_id=l.equipment_id AND x.m=l.collected_at") as $l) {
        $s = &$st[$l['customer_id']]; $s = $s ?? ['crit' => 0, 'warn' => 0, 'worst' => 'normal', 'last' => '', 'checked' => 0];
        $s['crit'] += (int)$l['n_crit']; $s['warn'] += (int)$l['n_warn']; $s['checked']++; if ($l['collected_at'] > $s['last']) $s['last'] = $l['collected_at'];
        if (($l['status'] === 'critical') || ($l['status'] === 'warning' && $s['worst'] === 'normal')) $s['worst'] = $l['status']; unset($s);
    }
    $rp = []; foreach (all("SELECT r.customer_id, r.report_no, r.inspection_date, r.status, r.report_type FROM mrs_report r JOIN (SELECT customer_id, MAX(id) m FROM mrs_report GROUP BY customer_id) x ON x.m=r.id") as $r) $rp[$r['customer_id']] = $r;
    $rc = []; foreach (all("SELECT customer_id, COUNT(*) n FROM mrs_report GROUP BY customer_id") as $r) $rc[$r['customer_id']] = (int)$r['n'];
    $cc = []; foreach (all("SELECT customer_id, COUNT(*) n FROM mrs_customer_contact GROUP BY customer_id") as $r) $cc[$r['customer_id']] = (int)$r['n'];
    out(array_map(function ($c) use ($as, $eq, $st, $rp, $rc, $cc, $uid) {
        $main = null; $subs = []; $my = null;
        foreach ($as[$c['id']] ?? [] as $a) { $x = ['id' => (int)$a['user_id'], 'name' => $a['full_name'] ?: $a['username']]; if ($a['kind'] === 'main') $main = $x; else $subs[] = $x; if ((int)$a['user_id'] === $uid) $my = $a['kind']; }
        $s = $st[$c['id']] ?? null; $r = $rp[$c['id']] ?? null;
        return ['id' => (int)$c['id'], 'name' => $c['name'], 'address' => $c['address'] ?: '', 'site' => $c['site'] ?: '', 'linked' => (bool)$c['legacy_customer_id'], 'legacy_customer_id' => $c['legacy_customer_id'],
            'main' => $main, 'subs' => $subs, 'my_role' => $my,
            'equipment_count' => (int)($eq[$c['id']]['act'] ?? 0), 'disposed_count' => (int)($eq[$c['id']]['disp'] ?? 0), 'contact_count' => $cc[$c['id']] ?? 0, 'report_count' => $rc[$c['id']] ?? 0,
            'health' => $s ? ['status' => $s['worst'], 'crit' => $s['crit'], 'warn' => $s['warn'], 'checked' => $s['checked'], 'last_log' => substr($s['last'], 0, 10)] : null,
            'last_report' => $r ? ['no' => $r['report_no'], 'date' => $r['inspection_date'], 'status' => $r['status'], 'type' => REPORT_TYPES[$r['report_type']] ?? $r['report_type']] : null];
    }, $rows));
});
route('POST', 'customers/(\d+)/assign', function ($cid) use ($in) {
    $u = need('customer.view'); customer_out($cid); $kind = $in['kind'] ?? ''; $target = (int)($in['user_id'] ?? 0) ?: (int)$u['id'];
    if ($target !== (int)$u['id']) { if (!can_reassign($u)) fail('다른 담당자 배정은 관리자·팀장만 바꿀 수 있습니다.', 403); if (!one("SELECT id FROM mrs_user WHERE id=? AND is_active=1", [$target])) fail('사용자를 찾을 수 없습니다.', 404); }
    if ($kind === 'none') q("DELETE FROM mrs_customer_assign WHERE customer_id=? AND user_id=?", [$cid, $target]);
    elseif (!assign_kind($kind)) fail('담당 구분은 정 또는 부입니다.');
    elseif ($target === (int)$u['id']) assign_self((int)$cid, $kind);
    else assign_set((int)$cid, $target, $kind);
    audit('assign.' . $kind, (string)val("SELECT name FROM mrs_customer WHERE id=?", [$cid]), (string)val("SELECT username FROM mrs_user WHERE id=?", [$target]));
    out(customer_out($cid));
});
route('GET', 'customers/(\d+)', function ($id) { need('customer.view'); out(customer_out($id, true)); });
function cust_data($in) { $d = ['name' => s($in['name'] ?? '', 200), 'address' => s($in['address'] ?? '', 500), 'site' => s($in['site'] ?? '', 200), 'memo' => s($in['memo'] ?? '', 5000)]; if ($d['name'] === '') fail('고객명을 입력하세요.'); return $d; }
route('POST', 'customers', function () use ($in) {
    need('customer.edit'); $d = cust_data($in); $lid = s($in['legacy_customer_id'] ?? '', 64) ?: null;
    if ($lid && ($src = legacy_get($lid))) { $d['name'] = $src['name']; $d['address'] = $src['address']; }
    $id = ins('mrs_customer', $d + ['legacy_customer_id' => $lid, 'created_at' => now(), 'updated_at' => now()]);
    audit('customer.create', $d['name']); out(customer_out($id, true));
});
route('PUT', 'customers/(\d+)', function ($id) use ($in) { need('customer.edit'); customer_out($id); $d = cust_data($in); upd('mrs_customer', $d + ['updated_at' => now()], $id); audit('customer.update', $d['name']); out(customer_out($id, true)); });
route('DELETE', 'customers/(\d+)', function ($id) {
    need('customer.edit'); $c = customer_out($id);
    if ($c['report_count']) fail('보고서가 있는 고객은 삭제할 수 없습니다. 보고서를 먼저 삭제하세요.');
    foreach (all("SELECT stored_path FROM mrs_log_file WHERE customer_id=?", [$id]) as $l) @unlink(data_dir() . '/' . $l['stored_path']);
    q("DELETE FROM mrs_log_file WHERE customer_id=?", [$id]); q("DELETE FROM mrs_equipment WHERE customer_id=?", [$id]); q("DELETE FROM mrs_customer_contact WHERE customer_id=?", [$id]);
    q("DELETE FROM mrs_customer_assign WHERE customer_id=?", [$id]);
    q("DELETE FROM mrs_customer WHERE id=?", [$id]); audit('customer.delete', $c['name']); out(['ok' => true]);
});
function contact_data($in) {
    $d = ['name' => s($in['name'] ?? '', 100), 'department' => s($in['department'] ?? '', 100), 'position' => s($in['position'] ?? '', 100), 'email' => s($in['email'] ?? '', 200),
        'phone' => s($in['phone'] ?? '', 50), 'receive_reports' => b($in['receive_reports'] ?? true) ? 1 : 0, 'is_primary' => b($in['is_primary'] ?? false) ? 1 : 0];
    if ($d['name'] === '') fail('담당자 이름을 입력하세요.');
    if ($d['email'] && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) fail('이메일 형식이 올바르지 않습니다.');
    return $d;
}
route('POST', 'customers/(\d+)/contacts', function ($cid) use ($in) {
    need('customer.edit'); $c = customer_out($cid); $d = contact_data($in);
    if ($d['is_primary']) q("UPDATE mrs_customer_contact SET is_primary=0 WHERE customer_id=?", [$cid]);
    $id = ins('mrs_customer_contact', $d + ['customer_id' => (int)$cid, 'created_at' => now(), 'updated_at' => now()]);
    audit('contact.create', "{$c['name']}/{$d['name']}"); out(contact_out(one("SELECT * FROM mrs_customer_contact WHERE id=?", [$id])));
});
route('PUT', 'contacts/(\d+)', function ($id) use ($in) {
    need('customer.edit'); $ct = one("SELECT * FROM mrs_customer_contact WHERE id=?", [$id]); if (!$ct) fail('담당자를 찾을 수 없습니다.', 404);
    $d = contact_data($in); if ($d['is_primary']) q("UPDATE mrs_customer_contact SET is_primary=0 WHERE customer_id=?", [$ct['customer_id']]);
    upd('mrs_customer_contact', $d + ['updated_at' => now()], $id); audit('contact.update', $d['name']); out(contact_out(one("SELECT * FROM mrs_customer_contact WHERE id=?", [$id])));
});
route('DELETE', 'contacts/(\d+)', function ($id) {
    need('customer.edit'); $ct = one("SELECT * FROM mrs_customer_contact WHERE id=?", [$id]); if (!$ct) fail('담당자를 찾을 수 없습니다.', 404);
    q("DELETE FROM mrs_customer_contact WHERE id=?", [$id]); audit('contact.delete', $ct['name']); out(['ok' => true]);
});
function eq_data($in) {
    $d = ['name' => s($in['name'] ?? '', 100)]; foreach (['vendor' => 50, 'model' => 100, 'serial' => 200, 'os_version' => 100, 'ha_type' => 50, 'disk_info' => 200, 'location' => 200, 'contract_type' => 100, 'memo' => 5000] as $k => $n) $d[$k] = s($in[$k] ?? '', $n);
    $d['is_active'] = b($in['is_active'] ?? true) ? 1 : 0; if ($d['name'] === '') fail('시스템명을 입력하세요.'); return $d;
}
route('POST', 'customers/(\d+)/equipment', function ($cid) use ($in) { need('equipment.edit'); customer_out($cid); $d = eq_data($in); $id = ins('mrs_equipment', $d + ['customer_id' => (int)$cid, 'created_at' => now(), 'updated_at' => now()]); audit('equipment.create', $d['name']); out(eq_out(one("SELECT * FROM mrs_equipment WHERE id=?", [$id]))); });
route('PUT', 'equipment/(\d+)', function ($id) use ($in) { need('equipment.edit'); if (!one("SELECT id FROM mrs_equipment WHERE id=?", [$id])) fail('장비를 찾을 수 없습니다.', 404); $d = eq_data($in); upd('mrs_equipment', $d + ['updated_at' => now()], $id); audit('equipment.update', $d['name']); out(eq_out(one("SELECT * FROM mrs_equipment WHERE id=?", [$id]))); });
route('POST', 'equipment/(\d+)/dispose', function ($id) use ($in) {
    need('equipment.edit'); $e = one("SELECT * FROM mrs_equipment WHERE id=?", [$id]); if (!$e) fail('장비를 찾을 수 없습니다.', 404);
    upd('mrs_equipment', ['is_active' => 0, 'disposed_at' => now(), 'dispose_note' => s($in['note'] ?? '', 500), 'updated_at' => now()], $id);
    audit('equipment.dispose', $e['name'], s($in['note'] ?? '', 500)); out(['ok' => true]);
});
route('POST', 'equipment/(\d+)/restore', function ($id) {
    need('equipment.edit'); $e = one("SELECT * FROM mrs_equipment WHERE id=?", [$id]); if (!$e) fail('장비를 찾을 수 없습니다.', 404);
    upd('mrs_equipment', ['is_active' => 1, 'disposed_at' => null, 'dispose_note' => '', 'updated_at' => now()], $id); audit('equipment.restore', $e['name']); out(['ok' => true]);
});
route('DELETE', 'equipment/(\d+)', function ($id) {
    need('equipment.edit'); $e = one("SELECT * FROM mrs_equipment WHERE id=?", [$id]); if (!$e) fail('장비를 찾을 수 없습니다.', 404);
    if (val("SELECT COUNT(*) FROM mrs_log_file WHERE equipment_id=?", [$id]) || val("SELECT COUNT(*) FROM mrs_report_equipment WHERE equipment_id=?", [$id])) {
        fail('로그나 보고서 이력이 있는 장비는 삭제하지 않고 "파기"로 처리합니다. 파기하면 현황에서는 빠지고, "파기장비 포함"을 켜면 로그와 함께 다시 볼 수 있습니다.');
    }
    q("DELETE FROM mrs_equipment WHERE id=?", [$id]); audit('equipment.delete', $e['name']); out(['ok' => true]);
});

/* ===== 로그 (드래그&드롭 업로드 · 장비별/시점별 보관) ===== */
function log_out($lg, $names = []) {
    $p = jd($lg['parsed'], []); $a = $p ? analyze([], $p) : null; $sys = (array)($p['system'] ?? []);
    return ['id' => (int)$lg['id'], 'customer_id' => (int)$lg['customer_id'], 'equipment_id' => $lg['equipment_id'] === null ? null : (int)$lg['equipment_id'],
        'equipment_name' => $names[$lg['equipment_id']] ?? '', 'collected_at' => substr($lg['collected_at'], 0, 16), 'uploaded_at' => substr($lg['created_at'], 0, 16),
        'original_name' => $lg['original_name'], 'size_bytes' => (int)$lg['size_bytes'], 'note' => $lg['note'] ?: '',
        'summary' => ['model' => $sys['model'] ?? null, 'os_version' => $sys['os_version'] ?? null, 'aggregates' => count($p['aggregates'] ?? []), 'volumes' => count($p['volumes'] ?? []),
            'failed_disks' => count($p['disks']['failed'] ?? []), 'events' => ['emergency' => $p['events']['emergency'] ?? 0, 'alert' => $p['events']['alert'] ?? 0, 'error' => $p['events']['error'] ?? 0],
            'status' => $a['status'] ?? 'normal', 'score' => $a['score'] ?? null]];
}
function eq_names($cid) { $n = []; foreach (all("SELECT id, name, disposed_at FROM mrs_equipment WHERE customer_id=?", [$cid]) as $e) $n[$e['id']] = $e['name'] . ($e['disposed_at'] ? ' (파기)' : ''); return $n; }
function upload_list($key) {
    $f = $_FILES[$key] ?? null; if (!$f) return [];
    if (!is_array($f['name'])) return [$f];
    $out = []; foreach ($f['name'] as $i => $n) $out[] = ['name' => $n, 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    return $out;
}
function upload_err($e) { return [UPLOAD_ERR_INI_SIZE => '서버 업로드 한도(upload_max_filesize)를 넘었습니다.', UPLOAD_ERR_FORM_SIZE => '파일이 너무 큽니다.', UPLOAD_ERR_PARTIAL => '업로드가 중간에 끊겼습니다.', UPLOAD_ERR_NO_FILE => '파일이 없습니다.'][$e] ?? "업로드 오류($e)"; }
route('POST', 'customers/(\d+)/logs', function ($cid) {
    need('log.upload'); $cust = customer_out($cid);
    $files = upload_list('files'); if (!$files) fail('업로드할 파일이 없습니다. (서버 post_max_size 한도를 넘었을 수 있습니다)');
    $eid = (int)($_POST['equipment_id'] ?? 0) ?: null;
    if ($eid && !one("SELECT id FROM mrs_equipment WHERE id=? AND customer_id=?", [$eid, $cid])) fail('해당 고객의 장비가 아닙니다.');
    $ts = strtotime((string)($_POST['collected_at'] ?? '')) ?: time(); $when = date('Y-m-d H:i:s', $ts);
    $note = s($_POST['note'] ?? '', 500); $eqs = all("SELECT id, name, serial FROM mrs_equipment WHERE customer_id=?", [$cid]); $ids = [];
    protect_dir(data_dir() . '/logs');
    foreach ($files as $f) {
        if ($f['error'] !== UPLOAD_ERR_OK) fail($f['name'] . ': ' . upload_err($f['error']));
        if ($f['size'] > 200 * 1048576) fail($f['name'] . ': 200MB 를 초과합니다.', 413);
        $data = (string)file_get_contents($f['tmp_name']);
        $parsed = parse_file($f['name'], $data); $sys = (array)$parsed['system'];
        $target = $eid;
        if (!$target) { // 장비 미지정 시: 로그의 시리얼/호스트명으로 자동 매칭
            $ser = explode(' / ', $sys['serial'] ?? '')[0]; $host = $sys['hostname'] ?? '';
            foreach ($eqs as $c) if (($ser && strpos((string)$c['serial'], $ser) !== false) || ($host && $host === $c['name'])) { $target = (int)$c['id']; break; }
        }
        $rel = "logs/c$cid/" . ($target ? "e$target" : 'unassigned') . '/' . date('Ymd_His', $ts);
        $dir = data_dir() . '/' . $rel; if (!is_dir($dir)) mkdir($dir, 0755, true);
        $name = safe_name($f['name']); $k = 1; $base = $name;
        while (is_file("$dir/$name")) $name = pathinfo($base, PATHINFO_FILENAME) . '_' . (++$k) . (pathinfo($base, PATHINFO_EXTENSION) ? '.' . pathinfo($base, PATHINFO_EXTENSION) : '');
        if (!move_uploaded_file($f['tmp_name'], "$dir/$name") && !rename($f['tmp_name'], "$dir/$name")) fail('파일을 저장할 수 없습니다. data 폴더 쓰기 권한을 확인하세요.', 500);
        $ids[] = ins('mrs_log_file', ['customer_id' => (int)$cid, 'equipment_id' => $target, 'collected_at' => $when, 'original_name' => mb_substr($f['name'], 0, 300), 'stored_path' => "$rel/$name",
            'size_bytes' => strlen($data), 'sha256' => hash('sha256', $data), 'parsed' => je($parsed), 'note' => $note, 'uploaded_by' => me()['id'], 'created_at' => now(), 'updated_at' => now()]);
        log_stat_fill('id=?', [end($ids)]);
    }
    audit('log.upload', $cust['name'], implode(', ', array_column($files, 'name')));
    $names = eq_names($cid);
    out(array_map(function ($id) use ($names) { return log_out(one("SELECT * FROM mrs_log_file WHERE id=?", [$id]), $names); }, $ids));
});
route('GET', 'customers/(\d+)/logs', function ($cid) {
    need('customer.view'); $sql = "SELECT * FROM mrs_log_file WHERE customer_id=?"; $p = [$cid];
    if (!g('disposed')) $sql .= " AND (equipment_id IS NULL OR equipment_id NOT IN (SELECT id FROM mrs_equipment WHERE disposed_at IS NOT NULL))";
    if ($e = (int)g('equipment_id', 0)) { $sql .= " AND equipment_id=?"; $p[] = $e; }
    if ($d = g('date_from')) { $sql .= " AND collected_at>=?"; $p[] = substr($d, 0, 10) . ' 00:00:00'; }
    if ($d = g('date_to')) { $sql .= " AND collected_at<=?"; $p[] = substr($d, 0, 10) . ' 23:59:59'; }
    $names = eq_names($cid);
    out(array_map(function ($l) use ($names) { return log_out($l, $names); }, all("$sql ORDER BY collected_at DESC LIMIT 500", $p)));
});
function log_get($id) { $l = one("SELECT * FROM mrs_log_file WHERE id=?", [$id]); if (!$l) fail('로그를 찾을 수 없습니다.', 404); return $l; }
route('GET', 'logs/(\d+)', function ($id) { need('customer.view'); $l = log_get($id); $d = log_out($l, eq_names($l['customer_id'])); $p = jd($l['parsed'], []); $d['parsed'] = $p; $d['analysis'] = analyze([], $p); out($d); });
route('PATCH', 'logs/(\d+)', function ($id) {
    need('log.upload'); $l = log_get($id); $eid = (int)g('equipment_id', 0) ?: null;
    if ($eid && !one("SELECT id FROM mrs_equipment WHERE id=? AND customer_id=?", [$eid, $l['customer_id']])) fail('해당 고객의 장비가 아닙니다.');
    upd('mrs_log_file', ['equipment_id' => $eid, 'updated_at' => now()], $id); out(log_out(log_get($id), eq_names($l['customer_id'])));
});
route('GET', 'logs/(\d+)/download', function ($id) { need('customer.view'); $l = log_get($id); send_file(data_dir() . '/' . $l['stored_path'], $l['original_name']); });
route('DELETE', 'logs/(\d+)', function ($id) {
    need('log.delete'); $l = log_get($id); @unlink(data_dir() . '/' . $l['stored_path']);
    q("DELETE FROM mrs_report_log WHERE log_id=?", [$id]); q("DELETE FROM mrs_log_file WHERE id=?", [$id]); audit('log.delete', $l['original_name']); out(['ok' => true]);
});

/* ===== 고객 양식 ===== */
function tpl_out($t) {
    return ['id' => (int)$t['id'], 'name' => $t['name'], 'report_type' => $t['report_type'], 'customer_id' => $t['customer_id'] === null ? null : (int)$t['customer_id'], 'file_format' => $t['file_format'],
        'original_name' => $t['original_name'] ?: '', 'description' => $t['description'] ?: '', 'placeholders' => jd($t['placeholders'], []), 'is_active' => (bool)(int)$t['is_active'], 'updated_at' => substr($t['updated_at'], 0, 16)];
}
route('GET', 'templates/placeholders', function () { need_login(); out(placeholder_help()); });
route('GET', 'templates', function () {
    need('report.view'); $c = (int)g('customer_id', 0);
    $rows = $c ? all("SELECT * FROM mrs_report_template WHERE customer_id=? OR customer_id IS NULL ORDER BY name", [$c]) : all("SELECT * FROM mrs_report_template ORDER BY name");
    out(array_map('tpl_out', $rows));
});
function tpl_store() {
    $f = upload_list('file')[0] ?? null; if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] !== UPLOAD_ERR_OK) fail(upload_err($f['error']));
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)); if (!in_array($ext, ['pptx', 'xlsx'], true)) fail('pptx 또는 xlsx 양식만 등록할 수 있습니다.');
    protect_dir(data_dir() . '/templates'); $rel = 'templates/' . bin2hex(random_bytes(12)) . ".$ext";
    if (!move_uploaded_file($f['tmp_name'], data_dir() . "/$rel") && !rename($f['tmp_name'], data_dir() . "/$rel")) fail('양식을 저장할 수 없습니다.', 500);
    try { $ph = tpl_scan(data_dir() . "/$rel", $ext); } catch (Throwable $e) { @unlink(data_dir() . "/$rel"); fail('양식 파일을 읽을 수 없습니다.'); }
    return ['stored_path' => $rel, 'file_format' => $ext, 'original_name' => mb_substr($f['name'], 0, 300), 'placeholders' => je($ph)];
}
function tpl_form() {
    $d = ['name' => s($_POST['name'] ?? '', 200), 'report_type' => s($_POST['report_type'] ?? 'regular', 30), 'customer_id' => (int)($_POST['customer_id'] ?? 0) ?: null,
        'description' => s($_POST['description'] ?? '', 5000), 'is_active' => b($_POST['is_active'] ?? true) ? 1 : 0, 'updated_at' => now()];
    if ($d['name'] === '') fail('양식 이름을 입력하세요.'); return $d;
}
route('POST', 'templates', function () { need('template.manage'); $d = tpl_form(); $f = tpl_store(); if (!$f) fail('양식 파일을 선택하세요.'); $id = ins('mrs_report_template', $d + $f + ['created_at' => now()]); audit('template.create', $d['name']); out(tpl_out(one("SELECT * FROM mrs_report_template WHERE id=?", [$id]))); });
route('PUT', 'templates/(\d+)', function ($id) {
    need('template.manage'); $t = one("SELECT * FROM mrs_report_template WHERE id=?", [$id]); if (!$t) fail('양식을 찾을 수 없습니다.', 404);
    $d = tpl_form(); $f = tpl_store(); if ($f) { @unlink(data_dir() . '/' . $t['stored_path']); $d += $f; }
    upd('mrs_report_template', $d, $id); audit('template.update', $d['name']); out(tpl_out(one("SELECT * FROM mrs_report_template WHERE id=?", [$id])));
});
route('GET', 'templates/(\d+)/download', function ($id) { need('report.view'); $t = one("SELECT * FROM mrs_report_template WHERE id=?", [$id]); if (!$t) fail('양식을 찾을 수 없습니다.', 404); send_file(data_dir() . '/' . $t['stored_path'], $t['original_name'] ?: "template.{$t['file_format']}"); });
route('DELETE', 'templates/(\d+)', function ($id) {
    need('template.manage'); $t = one("SELECT * FROM mrs_report_template WHERE id=?", [$id]); if (!$t) fail('양식을 찾을 수 없습니다.', 404);
    @unlink(data_dir() . '/' . $t['stored_path']); q("UPDATE mrs_report SET template_id=NULL WHERE template_id=?", [$id]); q("DELETE FROM mrs_report_template WHERE id=?", [$id]); audit('template.delete', $t['name']); out(['ok' => true]);
});

/* ===== 보고서 ===== */
route('GET', 'catalog', function () {
    need('report.view');
    out(['report_types' => REPORT_TYPES, 'work_subtypes' => WORK_SUBTYPES, 'service_codes' => SERVICE_CODES, 'visual_items' => VISUAL_ITEMS, 'visual_choices' => VISUAL_CHOICES,
        'default_steps' => default_steps(), 'default_rollback' => DEFAULT_ROLLBACK, 'status' => STATUS_KO, 'variants' => VARIANT_LABEL,
        'ai_enabled' => (bool)conf('anthropic_key'), 'mail_enabled' => mail_ready(),
        'engineers' => array_map(function ($u) { return ['id' => (int)$u['id'], 'name' => trim(($u['full_name'] ?: $u['username']) . ' ' . $u['title'])]; }, all("SELECT id, username, full_name, title FROM mrs_user WHERE is_active=1 ORDER BY full_name"))]);
});
function report_brief($r) {
    $cn = val("SELECT name FROM mrs_customer WHERE id=?", [$r['customer_id']]);
    return ['id' => (int)$r['id'], 'report_no' => $r['report_no'], 'title' => $r['title'], 'customer_id' => (int)$r['customer_id'], 'customer_name' => $cn ?: '',
        'report_type' => $r['report_type'], 'report_type_label' => REPORT_TYPES[$r['report_type']] ?? $r['report_type'], 'work_subtype' => $r['work_subtype'] ?: '',
        'work_subtype_label' => WORK_SUBTYPES[$r['work_subtype']] ?? '', 'variant' => $r['variant'], 'variant_label' => VARIANT_LABEL[$r['variant']] ?? '',
        'status' => $r['status'], 'status_label' => STATUS_KO[$r['status']] ?? $r['status'], 'overall_status' => $r['overall_status'] ?: '', 'overall_label' => STATUS_LONG[$r['overall_status'] ?: 'normal'] ?? '',
        'inspection_date' => $r['inspection_date'], 'equipment_count' => (int)val("SELECT COUNT(*) FROM mrs_report_equipment WHERE report_id=?", [$r['id']]),
        'updated_at' => substr($r['updated_at'], 0, 16), 'completed_at' => $r['completed_at'] ? substr($r['completed_at'], 0, 16) : null, 'sent_at' => $r['sent_at'] ? substr($r['sent_at'], 0, 16) : null];
}
function report_detail($rid) {
    $r = report_get($rid); $d = report_brief($r); $an = jd($r['analysis'], []); $eqs = [];
    foreach (report_eq_links($rid) as $i => $e) {
        $a = $an[(string)$e['id']] ?? []; $p = $a['parsed'] ?? [];
        $eqs[] = ['id' => (int)$e['id'], 'seq' => $i + 1, 'name' => $e['name'], 'model' => $e['model'], 'serial' => $e['serial'], 'os_version' => $e['os_version'], 'analysis' => $a['analysis'] ?? null,
            'log_ids' => $a['log_ids'] ?? [], 'aggregates' => array_slice($p['aggregates'] ?? [], 0, 12), 'volumes' => array_slice($p['volumes'] ?? [], 0, 12), 'events' => $p['events'] ?? null,
            'ha' => $p['ha'] ?? null, 'ports_down' => $p['ports_down'] ?? [], 'failed_disks' => $p['disks']['failed'] ?? [], 'spare' => $p['disks']['spare'] ?? 0];
    }
    $eng = $r['engineer_id'] ? one("SELECT full_name, username FROM mrs_user WHERE id=?", [$r['engineer_id']]) : null;
    $d += ['template_id' => $r['template_id'] === null ? null : (int)$r['template_id'], 'service_codes' => jd($r['service_codes'], []), 'plan' => (object)jd($r['plan'], []),
        'equipment_ids' => array_map('intval', array_column(report_eq_links($rid), 'id')), 'log_ids' => report_log_ids($rid), 'equipments' => $eqs,
        'ai_advice' => $r['ai_advice'] ?: '', 'ai_model' => $r['ai_model'] ?: '', 'ai_generated_at' => $r['ai_generated_at'] ? substr($r['ai_generated_at'], 0, 16) : null,
        'diagnosis' => $r['diagnosis'] ?: '', 'diagnosis_reviewed' => (bool)(int)$r['diagnosis_reviewed'], 'visual_inspection' => (object)jd($r['visual_inspection'], []),
        'final_comment' => $r['final_comment'] ?: '', 'engineer_id' => $r['engineer_id'] === null ? null : (int)$r['engineer_id'], 'engineer_name' => $eng ? ($eng['full_name'] ?: $eng['username']) : '',
        'files' => array_map(function ($f) { return ['id' => (int)$f['id'], 'format' => $f['file_format'], 'kind' => $f['kind'], 'filename' => $f['filename'], 'size' => (int)$f['size_bytes'], 'created_at' => substr($f['created_at'], 0, 16)]; },
            all("SELECT * FROM mrs_report_file WHERE report_id=? ORDER BY id", [$rid])),
        'mails' => array_map(function ($m) { return ['at' => substr($m['created_at'], 0, 16), 'to' => jd($m['recipients'], []), 'cc' => jd($m['cc'], []), 'status' => $m['status'], 'error' => $m['error'] ?: '', 'subject' => $m['subject']]; },
            all("SELECT * FROM mrs_mail_log WHERE report_id=? ORDER BY id DESC", [$rid])),
        'missing' => missing_for_completion($r),
        'recipients' => array_map(function ($c) { return ['name' => $c['name'], 'email' => $c['email'], 'default' => (bool)(int)$c['receive_reports']]; },
            all("SELECT * FROM mrs_customer_contact WHERE customer_id=? AND email<>'' ORDER BY is_primary DESC, id", [$r['customer_id']]))];
    return $d;
}
route('GET', 'dashboard', function () {
    need('report.view');
    $bs = []; foreach (all("SELECT status, COUNT(*) n FROM mrs_report GROUP BY status") as $x) $bs[$x['status']] = (int)$x['n'];
    $bt = []; foreach (all("SELECT report_type, COUNT(*) n FROM mrs_report GROUP BY report_type") as $x) $bt[$x['report_type']] = (int)$x['n'];
    out(['customers' => (int)val("SELECT COUNT(*) FROM mrs_customer"), 'equipment' => (int)val("SELECT COUNT(*) FROM mrs_equipment WHERE is_active=1 AND disposed_at IS NULL"), 'logs' => (int)val("SELECT COUNT(*) FROM mrs_log_file"),
        'reports' => array_sum($bs), 'this_month' => (int)val("SELECT COUNT(*) FROM mrs_report WHERE inspection_date LIKE ?", [date('Y-m') . '%']), 'by_status' => (object)$bs, 'by_type' => (object)$bt,
        'recent' => array_map('report_brief', all("SELECT * FROM mrs_report ORDER BY updated_at DESC, id DESC LIMIT 8")),
        'attention' => array_map('report_brief', all("SELECT * FROM mrs_report WHERE overall_status='critical' ORDER BY updated_at DESC LIMIT 5"))]);
});
route('GET', 'reports', function () {
    need('report.view'); $sql = "SELECT r.* FROM mrs_report r JOIN mrs_customer c ON c.id=r.customer_id WHERE 1=1"; $p = [];
    if (($kw = trim((string)g('q'))) !== '') { $sql .= " AND (r.title LIKE ? OR r.report_no LIKE ? OR c.name LIKE ?)"; array_push($p, "%$kw%", "%$kw%", "%$kw%"); }
    if ($v = g('status')) { $sql .= " AND r.status=?"; $p[] = $v; }
    if ($v = g('report_type')) { $sql .= " AND r.report_type=?"; $p[] = $v; }
    if ($v = (int)g('customer_id', 0)) { $sql .= " AND r.customer_id=?"; $p[] = $v; }
    out(array_map('report_brief', all("$sql ORDER BY r.updated_at DESC, r.id DESC LIMIT 500", $p)));
});
function report_apply($rid, $in, $isNew) {
    $type = $in['report_type'] ?? ''; if (!isset(REPORT_TYPES[$type])) fail('알 수 없는 보고서 종류입니다.');
    $eids = array_values(array_unique(array_map('intval', (array)($in['equipment_ids'] ?? [])))); if (!$eids) fail('장비를 1대 이상 선택하세요.');
    $cust = one("SELECT * FROM mrs_customer WHERE id=?", [(int)($in['customer_id'] ?? 0)]); if (!$cust) fail('고객을 찾을 수 없습니다.', 404);
    $ok = all('SELECT id FROM mrs_equipment WHERE customer_id=? AND id IN (' . implode(',', $eids) . ')', [$cust['id']]);
    if (count($ok) !== count($eids)) fail('선택한 장비가 고객 소속이 아닙니다.');
    $lids = array_values(array_unique(array_map('intval', (array)($in['log_ids'] ?? []))));
    $lids = $lids ? array_map('intval', array_column(all('SELECT id FROM mrs_log_file WHERE customer_id=? AND id IN (' . implode(',', $lids) . ')', [$cust['id']]), 'id')) : [];
    $tid = (int)($in['template_id'] ?? 0) ?: null;
    if ($type === 'custom' && !$tid) fail('고객 양식 보고서는 양식을 선택해야 합니다.');
    if ($tid && !one("SELECT id FROM mrs_report_template WHERE id=?", [$tid])) fail('양식을 찾을 수 없습니다.');
    $sub = s($in['work_subtype'] ?? '', 30); $plan = (array)($in['plan'] ?? []);
    if ($type === 'workplan') {
        $sub = $sub ?: 'etc'; $ds = default_steps();
        if (empty($plan['steps'])) $plan['steps'] = $ds[$sub] ?? $ds['etc'];
        if (!isset($plan['rollback'])) $plan['rollback'] = DEFAULT_ROLLBACK[$sub] ?? '';
        if (!isset($plan['location'])) $plan['location'] = $cust['site'] ?: $cust['address'];
    }
    $label = $type === 'workplan' ? (WORK_SUBTYPES[$sub] ?? '') . ' 작업계획서' : REPORT_TYPES[$type];
    $d = ['customer_id' => (int)$cust['id'], 'report_type' => $type, 'work_subtype' => $sub, 'template_id' => $tid, 'inspection_date' => s($in['inspection_date'] ?? '', 20) ?: today(),
        'service_codes' => je(array_values(array_intersect((array)($in['service_codes'] ?? []), SERVICE_CODES))), 'plan' => je((object)$plan), 'variant' => variant_for(count($eids)),
        'title' => s($in['title'] ?? '', 300) ?: "{$cust['name']} $label", 'updated_at' => now()];
    if (!empty($in['engineer_id']) && one("SELECT id FROM mrs_user WHERE id=? AND is_active=1", [(int)$in['engineer_id']])) $d['engineer_id'] = (int)$in['engineer_id'];
    if ($isNew) { $u = me(); $rid = ins('mrs_report', $d + ['report_no' => next_report_no(), 'status' => 'draft', 'created_by' => $u['id'], 'engineer_id' => $d['engineer_id'] ?? $u['id'], 'created_at' => now(), 'analysis' => '{}', 'visual_inspection' => '{}']); }
    else upd('mrs_report', $d, $rid);
    q("DELETE FROM mrs_report_equipment WHERE report_id=?", [$rid]);
    foreach ($eids as $i => $e) ins('mrs_report_equipment', ['report_id' => $rid, 'equipment_id' => $e, 'seq' => $i + 1]);
    q("DELETE FROM mrs_report_log WHERE report_id=?", [$rid]);
    foreach ($lids as $l) ins('mrs_report_log', ['report_id' => $rid, 'log_id' => $l]);
    return $rid;
}
route('POST', 'reports', function () use ($in) {
    need('report.create'); db()->beginTransaction(); $rid = report_apply(null, $in, true); run_analysis(report_get($rid)); db()->commit();
    $r = report_get($rid); audit('report.create', $r['report_no'], $r['title']); out(report_detail($rid));
});
route('GET', 'reports/(\d+)', function ($id) { need('report.view'); out(report_detail($id)); });
route('PUT', 'reports/(\d+)', function ($id) use ($in) {
    need('report.edit'); $r = report_get($id); db()->beginTransaction(); report_apply((int)$id, $in, false); run_analysis($r);
    if (in_array($r['status'], ['completed', 'sent'], true)) upd('mrs_report', ['status' => 'review'], $id); // 내용이 바뀌었으므로 최종 확정을 다시 받음
    db()->commit(); audit('report.update', $r['report_no']); out(report_detail($id));
});
route('POST', 'reports/(\d+)/ai-advice', function ($id) use ($in) {
    need('report.edit'); $r = report_get($id); run_analysis($r); $ctx = build_context($r);
    $res = ai_generate($r['report_type'], $r['work_subtype'], $ctx['customer']['name'], $ctx['equipments'], s($in['notes'] ?? '', 4000));
    $d = ['ai_advice' => $res['text'], 'ai_model' => mb_substr($res['model'], 0, 60), 'ai_generated_at' => now(), 'diagnosis_reviewed' => 0, 'updated_at' => now()];
    if (b($in['replace_diagnosis'] ?? true) || !$r['diagnosis']) $d['diagnosis'] = $res['text'];
    upd('mrs_report', $d, $id); audit('report.ai', $r['report_no'], $res['model']);
    $o = report_detail($id); $o['ai_note'] = $res['note']; $o['ai_fallback'] = $res['fallback']; out($o);
});
route('PUT', 'reports/(\d+)/diagnosis', function ($id) use ($in) {
    need('report.edit'); $r = report_get($id); $txt = (string)($in['diagnosis'] ?? ''); $rev = b($in['reviewed'] ?? false);
    if ($rev && trim($txt) === '') fail('진단 의견을 입력하세요.');
    if ($rev && !$r['ai_advice']) fail('AI 진단 조언을 먼저 받아야 합니다. (필수)');
    upd('mrs_report', ['diagnosis' => $txt, 'diagnosis_reviewed' => $rev ? 1 : 0, 'updated_at' => now()], $id);
    audit('report.diagnosis', $r['report_no'], $rev ? '검토 확정' : '임시 저장'); out(report_detail($id));
});
route('PUT', 'reports/(\d+)/plan', function ($id) use ($in) { need('report.edit'); report_get($id); upd('mrs_report', ['plan' => je((object)($in['plan'] ?? [])), 'updated_at' => now()], $id); out(report_detail($id)); });
route('POST', 'reports/(\d+)/generate', function ($id) {
    need('report.edit'); $r = report_get($id);
    if (!$r['ai_advice']) fail('AI 진단 조언을 먼저 받아야 합니다. (필수)');
    if (!(int)$r['diagnosis_reviewed'] || trim($r['diagnosis']) === '') fail('엔지니어가 진단 의견을 검토·확정해야 보고서를 생성할 수 있습니다.');
    if (!class_exists('ZipArchive')) fail('서버에 PHP zip 확장이 없어 PPT/엑셀을 만들 수 없습니다. 호스팅 관리 화면에서 zip 확장을 켜 주세요.', 500);
    run_analysis($r); generate_files(report_get($id), false);
    if ($r['status'] === 'draft') upd('mrs_report', ['status' => 'review'], $id);
    audit('report.generate', $r['report_no']); out(report_detail($id));
});
route('PUT', 'reports/(\d+)/finalize', function ($id) use ($in) {
    need('report.edit'); $r = report_get($id);
    if ($r['status'] === 'draft') fail('먼저 보고서(검토본)를 생성하세요.');
    $vis = []; foreach ((array)($in['visual_inspection'] ?? []) as $eid => $items) foreach ((array)$items as $k => $v) if (in_array($v, VISUAL_CHOICES, true)) $vis[(string)$eid][$k] = $v;
    upd('mrs_report', ['visual_inspection' => je((object)$vis), 'final_comment' => trim((string)($in['final_comment'] ?? '')), 'updated_at' => now()], $id); // 입력값은 임시 저장
    $miss = missing_for_completion(report_get($id));
    if ($miss) fail('필수 항목 누락: ' . implode(' / ', $miss));
    generate_files(report_get($id), true);
    upd('mrs_report', ['status' => 'completed', 'completed_at' => now()], $id);
    audit('report.complete', $r['report_no']); out(report_detail($id));
});
route('GET', 'reports/(\d+)/files/(\d+)', function ($rid, $fid) {
    need('report.view'); $f = one("SELECT * FROM mrs_report_file WHERE id=? AND report_id=?", [$fid, $rid]); if (!$f) fail('파일이 없습니다.', 404);
    send_file(data_dir() . '/' . $f['stored_path'], $f['filename'], $f['file_format'] === 'pptx' ? 'application/vnd.openxmlformats-officedocument.presentationml.presentation' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});
route('POST', 'reports/(\d+)/send', function ($id) use ($in) {
    $me = need('report.send'); $r = report_get($id);
    if (!in_array($r['status'], ['completed', 'sent'], true)) fail('최종 완료된 보고서만 발송할 수 있습니다.');
    $to = array_values(array_filter(array_map('trim', (array)($in['to'] ?? [])))); $cc = array_values(array_filter(array_map('trim', (array)($in['cc'] ?? []))));
    if (!$to) fail('받는 사람을 입력하세요.');
    $fids = array_map('intval', (array)($in['file_ids'] ?? []));
    $files = array_values(array_filter(all("SELECT * FROM mrs_report_file WHERE report_id=?", [$id]), function ($f) use ($fids) { return !$fids || in_array((int)$f['id'], $fids, true); }));
    $company = conf('company_name');
    $subject = s($in['subject'] ?? '', 300) ?: "[$company] {$r['title']} ({$r['inspection_date']})";
    $msg = trim((string)($in['message'] ?? '')) ?: "안녕하세요, {$company}입니다.\n\n{$r['title']}를 첨부와 같이 송부드립니다.\n검토 부탁드립니다.\n\n감사합니다.";
    $eng = engineer_of($r);
    $sig = implode(' | ', array_filter([trim($eng['name'] . ' ' . $eng['title']), $eng['phone'], $eng['email']]));
    $h = function ($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
    $html = "<div style=\"font-family:'Malgun Gothic',sans-serif;font-size:14px;color:#1f2937\"><div style='background:#0B2E4F;color:#fff;padding:16px 20px;font-size:18px;font-weight:700'>" . $h($r['title'])
        . "</div><div style='height:4px;background:#1969A5'></div><div style='padding:20px;line-height:1.7'>" . nl2br($h($msg)) . "</div>"
        . "<div style='padding:12px 20px;color:#6b7785;font-size:12px;border-top:1px solid #d5dee8'>" . ($sig ? $h($sig) . '<br>' : '') . $h($company . ' · ' . conf('company_address'))
        . '<br>첨부: ' . $h(implode(', ', array_column($files, 'filename'))) . '</div></div>';
    $text = $msg . ($sig ? "\n\n-- \n$sig\n$company" : '');
    $status = 'sent'; $err = '';
    try { mail_send($to, $cc, $subject, $text, $html, array_map(function ($f) { return [$f['filename'], data_dir() . '/' . $f['stored_path']]; }, $files)); upd('mrs_report', ['status' => 'sent', 'sent_at' => now()], $id); }
    catch (Throwable $e) { $status = 'failed'; $err = $e->getMessage(); }
    ins('mrs_mail_log', ['report_id' => (int)$id, 'recipients' => je($to), 'cc' => je($cc), 'subject' => $subject, 'status' => $status, 'error' => $err, 'sent_by' => $me['id'], 'created_at' => now()]);
    audit('report.send', $r['report_no'], "$status: " . implode(', ', $to));
    if ($status === 'failed') fail("메일 발송 실패: $err", 502);
    out(report_detail($id));
});
route('DELETE', 'reports/(\d+)', function ($id) {
    need('report.delete'); $r = report_get($id);
    rrmdir(data_dir() . '/reports/' . preg_replace('/[^A-Za-z0-9\-]/', '', $r['report_no']));
    foreach (['mrs_report_file', 'mrs_report_equipment', 'mrs_report_log', 'mrs_mail_log'] as $t) q("DELETE FROM $t WHERE report_id=?", [$id]);
    q("DELETE FROM mrs_report WHERE id=?", [$id]); audit('report.delete', $r['report_no'], $r['title']); out(['ok' => true]);
});

/* ===== 시스템 설정 ===== */
function mask($v, $tail = true) { return $v === '' ? '' : ($tail && mb_strlen($v) > 12 ? str_repeat('•', 8) . mb_substr($v, -4) : str_repeat('•', 8)); }
route('GET', 'settings', function () {
    need('settings.manage'); $items = [];
    foreach (SETTING_FIELDS as $k => [$label, $kind, $secret, $def]) {
        $raw = one("SELECT v FROM mrs_setting WHERE k=?", [$k]); $v = conf($k);
        $items[] = ['key' => $k, 'label' => $label, 'kind' => $kind, 'secret' => $secret, 'value' => $secret ? '' : $v, 'masked' => $secret ? mask($v, $k === 'anthropic_key') : '', 'is_set' => $secret ? $v !== '' : null, 'options' => SETTING_OPTIONS[$k] ?? null,
            'source' => ($raw && $raw['v'] !== '') ? 'db' : 'default'];
    }
    out(['items' => $items, 'legacy_ok' => (bool)legacy_table(), 'zip' => class_exists('ZipArchive'), 'curl' => function_exists('curl_init'), 'openssl' => function_exists('openssl_encrypt'),
        'upload_max' => ini_get('upload_max_filesize'), 'post_max' => ini_get('post_max_size'), 'php' => PHP_VERSION]);
});
route('PUT', 'settings', function () use ($in) {
    $me = need('settings.manage'); $changed = [];
    foreach ((array)($in['values'] ?? []) as $k => $v) {
        if (!isset(SETTING_FIELDS[$k])) fail("알 수 없는 설정: $k");
        [$label, $kind, $secret] = SETTING_FIELDS[$k];
        if ($secret && ($v === null || (string)$v === '')) continue; // 비밀값은 비워 두면 기존 값 유지
        if ($kind === 'number') { if (!is_numeric($v)) fail("{$label}은(는) 숫자여야 합니다."); $v = (string)(int)$v; }
        elseif ($kind === 'select' && !isset(SETTING_OPTIONS[$k][$v])) fail("{$label} 값이 올바르지 않습니다.");
        else $v = trim((string)$v);
        if ($k === 'legacy_table' && $v !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $v)) fail('테이블 이름 형식이 올바르지 않습니다.');
        if (in_array($k, ['m365_tenant', 'm365_client_id'], true) && $v !== '' && !preg_match('/^[A-Za-z0-9.\-]{3,100}$/', $v)) fail("{$label} 형식이 올바르지 않습니다. Azure 화면의 값을 그대로 복사해 주세요.");
        if ($k === 'mail_from' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) fail('보내는 사람 주소 형식이 올바르지 않습니다.');
        set_setting($k, $v, $secret, $me['id']); $changed[] = $k;
    }
    foreach ((array)($in['clear'] ?? []) as $k) if (isset(SETTING_FIELDS[$k])) { del_setting($k); $changed[] = $k; }
    audit('settings.update', '', implode(', ', $changed)); // 값 자체는 기록하지 않음
    out(['changed' => $changed]);
});
route('POST', 'settings/test-mail', function () use ($in) {
    need('settings.manage'); $to = trim((string)($in['to'] ?? '')); if (!filter_var($to, FILTER_VALIDATE_EMAIL)) fail('받는 주소를 확인하세요.');
    try { $how = conf('mail_method') === 'm365' ? 'Microsoft 365' : 'SMTP'; mail_send([$to], [], "[MRS] 메일 설정 확인 ($how)", "MRS 메일 설정($how)이 정상입니다.", "<p>MRS 메일 설정(<b>$how</b>)이 정상입니다.</p>", []); } catch (Throwable $e) { fail('발송 실패: ' . $e->getMessage(), 502); }
    out(['ok' => true]);
});
route('GET', 'health', function () use ($https, $base, $sdir) {
    // 로그인 문제 진단용 (비밀 정보 없음)
    $r = ['ok' => true, 'version' => MRS_VERSION, 'php' => PHP_VERSION, 'https' => $https, 'cookie_path' => $base,
        'session_dir_ok' => is_dir($sdir) && is_writable($sdir), 'session_path' => is_dir($sdir) && is_writable($sdir) ? 'data/sess' : 'server default',
        'session_works' => isset($_SESSION['probe']) ? 'yes' : 'reload to check', 'admins' => (int)val("SELECT COUNT(*) FROM mrs_user WHERE role='admin' AND is_active=1")];
    $_SESSION['probe'] = 1;
    out($r);
});

/* ---------- 실행 ---------- */
foreach ($R as [$m, $re, $fn]) {
    if ($m === $method && preg_match($re, $path, $mm)) { array_shift($mm); $fn(...$mm); }
}
fail('요청한 기능을 찾을 수 없습니다.', 404);
