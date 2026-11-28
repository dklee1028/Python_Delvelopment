<?php
/* MRS - 공통 라이브러리 (DB, 스키마, 설정, 권한, 헬퍼)
   지원 환경: PHP 7.4 / 8.2 / 8.4, MariaDB 10.x (InnoDB, utf8mb4) — PMS 와 같은 웹호스팅
   MRS 는 공유 DB 에 mrs_ 로 시작하는 테이블만 추가하며, 기존(pms_) 테이블은 읽기만 합니다. */
date_default_timezone_set('Asia/Seoul');
mb_internal_encoding('UTF-8');
const MRS_VERSION = '1.0.0';
const SCHEMA_V = '5';

function cfg() {
    static $c = false;
    if ($c === false) {
        $f = __DIR__ . '/../config.php';
        $c = is_file($f) ? (require $f) : null;
    }
    return $c;
}
function data_dir() { $d = __DIR__ . '/../data'; if (!is_dir($d)) @mkdir($d, 0755, true); return realpath($d); }

function db_connect($c) {
    if (($c['driver'] ?? 'mysql') === 'sqlite') {
        $pdo = new PDO('sqlite:' . $c['path']);
        $pdo->exec('PRAGMA foreign_keys = ON');
    } else {
        $dsn = 'mysql:host=' . $c['host'] . ';port=' . ($c['port'] ?? 3306) . ';dbname=' . $c['name'] . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"]);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}
function db($set = null) {
    static $pdo = null;
    if ($set) $pdo = $set;
    if (!$pdo) $pdo = db_connect(cfg()['db']);
    return $pdo;
}
function driver() { return db()->getAttribute(PDO::ATTR_DRIVER_NAME); }
function q($sql, $p = []) { $s = db()->prepare($sql); $s->execute(array_values($p)); return $s; }
function all($sql, $p = []) { return q($sql, $p)->fetchAll(); }
function one($sql, $p = []) { $r = q($sql, $p)->fetch(); return $r ?: null; }
function val($sql, $p = []) { $r = q($sql, $p)->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }
function ins($t, $d) {
    $k = array_keys($d);
    q("INSERT INTO $t (" . implode(',', $k) . ") VALUES (" . implode(',', array_fill(0, count($k), '?')) . ")", $d);
    return (int)db()->lastInsertId();
}
function upd($t, $d, $id) {
    if (!$d) return;
    $set = implode(',', array_map(function ($k) { return "$k=?"; }, array_keys($d)));
    $p = array_values($d); $p[] = $id;
    q("UPDATE $t SET $set WHERE id=?", $p);
}
function now() { return date('Y-m-d H:i:s'); }
function today() { return date('Y-m-d'); }
function jd($s, $def = []) { if ($s === null || $s === '') return $def; $v = json_decode($s, true); return $v === null ? $def : $v; }
function je($v) { return json_encode($v, JSON_UNESCAPED_UNICODE); }
function s($v, $n = 255) { return mb_substr(trim((string)$v), 0, $n); }
function has_table($t) {
    try {
        if (driver() === 'sqlite') return (bool)val("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?", [$t]);
        return (bool)val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?", [$t]);
    } catch (Throwable $e) { return false; }
}
function table_columns($t) {
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $t)) return [];
    try {
        if (driver() === 'sqlite') return array_column(all("PRAGMA table_info($t)"), 'name');
        return array_column(all("SHOW COLUMNS FROM `$t`"), 'Field');
    } catch (Throwable $e) { return []; }
}

/* ---------- 스키마 (mrs_ 테이블만) ---------- */
function schema_sql($driver) {
    $my = $driver !== 'sqlite';
    $pk = $my ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $end = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $T = $my ? 'MEDIUMTEXT' : 'TEXT';
    $t = [];
    $t[] = "CREATE TABLE IF NOT EXISTS mrs_user (id $pk, username VARCHAR(64) NOT NULL UNIQUE, full_name VARCHAR(100), email VARCHAR(200), phone VARCHAR(50),
        title VARCHAR(50), password_hash VARCHAR(255) NOT NULL, role VARCHAR(20) NOT NULL DEFAULT 'engineer', permissions TEXT, is_active INT NOT NULL DEFAULT 1,
        leader_id INT, dept_id INT, pw_due_at VARCHAR(19), must_change_pw INT NOT NULL DEFAULT 0, fail_count INT NOT NULL DEFAULT 0, locked_until VARCHAR(19), last_login_at VARCHAR(19), created_at VARCHAR(19), updated_at VARCHAR(19))$end";
    $t[] = "CREATE TABLE IF NOT EXISTS mrs_customer (id $pk, legacy_customer_id VARCHAR(64), name VARCHAR(200) NOT NULL, address VARCHAR(500), site VARCHAR(200),
        memo TEXT, created_at VARCHAR(19), updated_at VARCHAR(19))$end";
    $t[] = "CREATE TABLE IF NOT EXISTS mrs_customer_contact (id $pk, customer_id INT NOT NULL, name VARCHAR(100) NOT NULL, department VARCHAR(100), position VARCHAR(100),
        email VARCHAR(200), phone VARCHAR(50), receive_reports INT NOT NULL DEFAULT 1, is_primary INT NOT NULL DEFAULT 0, created_at VARCHAR(19), updated_at VARCHAR(19))$end";
    $t[] = "CREATE TABLE IF NOT EXISTS mrs_equipment (id $pk, customer_id INT NOT NULL, name VARCHAR(100) NOT NULL, vendor VARCHAR(50), model VARCHAR(100), serial VARCHAR(200),
        os_version VARCHAR(100), ha_type VARCHAR(50), disk_info VARCHAR(200), location VARCHAR(200), contract_type VARCHAR(100), memo TEXT, is_active INT NOT NULL DEFAULT 1,
        disposed_at VARCHAR(19), dispose_note VARCHAR(500), exclude_vols VARCHAR(500), mgmt_host VARCHAR(200), access_info TEXT, created_at VARCHAR(19), updated_at VARCHAR(19))$end";
    $t[] = "CREATE TABLE IF NOT EXISTS mrs_log_file (id $pk, customer_id INT NOT NULL, equipment_id INT, collected_at VARCHAR(19), original_name VARCHAR(300),
        stored_path VARCHAR(600), size_bytes INT NOT NULL DEFAULT 0, sha256 VARCHAR(64), parsed $T, note VARCHAR(500), uploaded_by INT,
        status VARCHAR(20), n_crit INT, n_warn INT, created_at VARCHAR(19), updated_at VARCHAR(19))$end";
    // 성능 아카이브(perf_archive.7z): 원본은 보관 기한(기본 90일)이 지나면 자동 삭제, 분석 요약은 남김
    $t[] = "CREATE TABLE IF NOT EXISTS mrs_perf_archive (id $pk, customer_id INT NOT NULL, equipment_id INT NOT NULL, original_name VARCHAR(300), stored_path VARCHAR(600),
        size_bytes BIGINT NOT NULL DEFAULT 0, received_bytes BIGINT NOT NULL DEFAULT 0, upload_token VARCHAR(64), status VARCHAR(20) NOT NULL DEFAULT 'uploading',
        summary $T, analysis $T, ai_model VARCHAR(60), period_from VARCHAR(19), period_to VARCHAR(19), expires_at VARCHAR(19), keep_forever INT NOT NULL DEFAULT 0,
        file_deleted_at VARCHAR(19), uploaded_by INT, created_at VARCHAR(19), updated_at VARCHAR(19))$end";
    // 고객 담당 배정: kind = main(정) / sub(부). 자료는 고객에 붙어 있으므로 배정을 옮겨도 그대로 유지됩니다.
    $t[] = "CREATE TABLE IF NOT EXISTS mrs_customer_assign (id $pk, customer_id INT NOT NULL, user_id INT NOT NULL, kind VARCHAR(10) NOT NULL DEFAULT 'sub', created_at VARCHAR(19))$end";
    $t[] = "CREATE TABLE IF NOT EXISTS mrs_report_template (id $pk, name VARCHAR(200) NOT NULL, report_type VARCHAR(30), customer_id INT, file_format VARCHAR(10),
        stored_path VARCHAR(600), original_name VARCHAR(300), description TEXT, placeholders TEXT, is_active INT NOT NULL DEFAULT 1, created_at VARCHAR(19), updated_at VARCHAR(19))$end";
    $t[] = "CREATE TABLE IF NOT EXISTS mrs_report (id $pk, report_no VARCHAR(40) NOT NULL UNIQUE, customer_id INT NOT NULL, report_type VARCHAR(30), work_subtype VARCHAR(30),
        template_id INT, title VARCHAR(300), variant VARCHAR(10), status VARCHAR(20) NOT NULL DEFAULT 'draft', inspection_date VARCHAR(20), service_codes TEXT,
        analysis $T, plan $T, ai_advice TEXT, ai_model VARCHAR(60), ai_generated_at VARCHAR(19), diagnosis TEXT, diagnosis_reviewed INT NOT NULL DEFAULT 0,
        visual_inspection TEXT, final_comment TEXT, overall_status VARCHAR(20), engineer_id INT, include_perf INT NOT NULL DEFAULT 1, created_by INT, completed_at VARCHAR(19), sent_at VARCHAR(19),
        created_at VARCHAR(19), updated_at VARCHAR(19))$end";
    $t[] = "CREATE TABLE IF NOT EXISTS mrs_report_equipment (id $pk, report_id INT NOT NULL, equipment_id INT NOT NULL, seq INT NOT NULL DEFAULT 1)$end";
    $t[] = "CREATE TABLE IF NOT EXISTS mrs_report_log (id $pk, report_id INT NOT NULL, log_id INT NOT NULL)$end";
    $t[] = "CREATE TABLE IF NOT EXISTS mrs_report_file (id $pk, report_id INT NOT NULL, file_format VARCHAR(10), kind VARCHAR(20), filename VARCHAR(300),
        stored_path VARCHAR(600), size_bytes INT NOT NULL DEFAULT 0, created_at VARCHAR(19))$end";
    $t[] = "CREATE TABLE IF NOT EXISTS mrs_mail_log (id $pk, report_id INT NOT NULL, recipients TEXT, cc TEXT, subject VARCHAR(300), status VARCHAR(20), error TEXT,
        sent_by INT, created_at VARCHAR(19))$end";
    $t[] = "CREATE TABLE IF NOT EXISTS mrs_audit_log (id $pk, at VARCHAR(19), user_id INT, user_name VARCHAR(110), action VARCHAR(50), target VARCHAR(150), detail TEXT, ip VARCHAR(45))$end";
    $t[] = "CREATE TABLE IF NOT EXISTS mrs_dept (id $pk, name VARCHAR(100) NOT NULL, sort_no INT NOT NULL DEFAULT 0, created_at VARCHAR(19))$end";
    $t[] = "CREATE TABLE IF NOT EXISTS mrs_ignore_rule (id $pk, equipment_id INT, rule_key VARCHAR(300) NOT NULL, category VARCHAR(50), title VARCHAR(300), reason VARCHAR(500),
        created_by INT, created_at VARCHAR(19))$end";
    $t[] = "CREATE TABLE IF NOT EXISTS mrs_setting (k VARCHAR(64) NOT NULL PRIMARY KEY, v TEXT, is_secret INT NOT NULL DEFAULT 0, updated_at VARCHAR(19), updated_by INT)$end";
    $ix = [
        'ix_mrs_cust_legacy ON mrs_customer (legacy_customer_id)', 'ix_mrs_contact_c ON mrs_customer_contact (customer_id)',
        'ix_mrs_eq_c ON mrs_equipment (customer_id)', 'ix_mrs_ign_e ON mrs_ignore_rule (equipment_id)', 'ix_mrs_log_c ON mrs_log_file (customer_id)', 'ix_mrs_log_e ON mrs_log_file (equipment_id)',
        'ix_mrs_log_at ON mrs_log_file (collected_at)', 'ix_mrs_rep_c ON mrs_report (customer_id)', 'ix_mrs_rep_st ON mrs_report (status)',
        'ux_mrs_rep_eq ON mrs_report_equipment (report_id, equipment_id)', 'ux_mrs_rep_log ON mrs_report_log (report_id, log_id)',
        'ix_mrs_rfile_r ON mrs_report_file (report_id)', 'ux_mrs_assign ON mrs_customer_assign (customer_id, user_id)', 'ix_mrs_assign_u ON mrs_customer_assign (user_id)', 'ix_mrs_perf_e ON mrs_perf_archive (equipment_id)', 'ix_mrs_mail_r ON mrs_mail_log (report_id)', 'ix_mrs_audit_at ON mrs_audit_log (at)',
    ];
    foreach ($ix as $i) $t[] = (strpos($i, 'ux_') === 0 ? 'CREATE UNIQUE INDEX ' : 'CREATE INDEX ') . $i;
    return $t;
}
function install_schema($pdo, $driver) {
    // v1 → v2: 기존 테이블에 열 추가 (데이터는 그대로)
    // v2 → v3: 팀장(leader_id), 보고서 제외 볼륨, 성능 아카이브 포함 여부
    // v4 → v5: 부서(dept_id), 비밀번호 변경 주기(pw_due_at), 부서·무시 규칙 테이블
    // v3 → v4: 장비 접속 정보 (관리 IP — 첫 로그에서 자동 수집, 직접 수정 가능)
    $add = ['mrs_equipment' => ['disposed_at' => 'VARCHAR(19)', 'dispose_note' => 'VARCHAR(500)', 'exclude_vols' => 'VARCHAR(500)', 'mgmt_host' => 'VARCHAR(200)', 'access_info' => 'TEXT'], 'mrs_log_file' => ['status' => 'VARCHAR(20)', 'n_crit' => 'INT', 'n_warn' => 'INT'],
        'mrs_user' => ['leader_id' => 'INT', 'dept_id' => 'INT', 'pw_due_at' => 'VARCHAR(19)'], 'mrs_report' => ['include_perf' => 'INT NOT NULL DEFAULT 1']];
    foreach ($add as $tb => $cols) {
        if (!has_table($tb)) continue;
        $have = table_columns($tb);
        foreach ($cols as $c => $ty) if (!in_array($c, $have, true)) $pdo->exec("ALTER TABLE $tb ADD COLUMN $c $ty");
    }
    foreach (schema_sql($driver) as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $e) {
            // 이미 있는 인덱스는 건너뜀 (MariaDB 는 CREATE INDEX IF NOT EXISTS 를 10.1.4+ 에서만 지원)
            if (stripos($sql, 'CREATE TABLE') === 0) throw $e;
        }
    }
    set_setting('schema_v', SCHEMA_V);
}
function ensure_schema() {
    if (get_setting('schema_v', '') !== SCHEMA_V) install_schema(db(), driver());
}

/* ---------- 설정 (mrs_setting) ---------- */
function get_setting($k, $def = null) {
    try { $r = one("SELECT v, is_secret FROM mrs_setting WHERE k=?", [$k]); } catch (Throwable $e) { return $def; }
    if (!$r || $r['v'] === null || $r['v'] === '') return $def;
    return (int)$r['is_secret'] ? decrypt_secret($r['v']) : $r['v'];
}
function set_setting($k, $v, $secret = false, $uid = null) {
    $v = (string)$v;
    $stored = $secret && $v !== '' ? encrypt_secret($v) : $v;
    if (one("SELECT k FROM mrs_setting WHERE k=?", [$k])) q("UPDATE mrs_setting SET v=?, is_secret=?, updated_at=?, updated_by=? WHERE k=?", [$stored, $secret ? 1 : 0, now(), $uid, $k]);
    else ins('mrs_setting', ['k' => $k, 'v' => $stored, 'is_secret' => $secret ? 1 : 0, 'updated_at' => now(), 'updated_by' => $uid]);
}
function del_setting($k) { q("DELETE FROM mrs_setting WHERE k=?", [$k]); }

function secret_key() { $c = cfg(); return 'mrs:' . ($c['secret'] ?? 'change-me'); }
function encrypt_secret($plain) {
    $key = hash('sha256', secret_key(), true); $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return 'v1:' . base64_encode($iv . $tag . $ct);
}
function decrypt_secret($v) {
    if (strncmp($v, 'v1:', 3) !== 0) return '';
    $b = base64_decode(substr($v, 3)); if (strlen($b) < 29) return '';
    $key = hash('sha256', secret_key(), true);
    $p = openssl_decrypt(substr($b, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16));
    return $p === false ? '' : $p;
}

// 화면에서 바꾸는 운영 설정: key => [라벨, 종류, 비밀여부, 기본값]
const SETTING_FIELDS = [
    'company_name' => ['회사명', 'text', false, 'DATANEX'],
    'company_address' => ['회사 주소', 'text', false, '서울시 구로구 디지털로34길 55 코오롱싸이언스밸리2차 1205호'],
    'mail_method' => ['발송 방식', 'select', false, 'smtp'],
    'm365_tenant' => ['디렉터리(테넌트) ID', 'text', false, ''],
    'm365_client_id' => ['애플리케이션(클라이언트) ID', 'text', false, ''],
    'm365_client_secret' => ['클라이언트 암호 (값)', 'password', true, ''],
    'smtp_host' => ['SMTP 서버', 'text', false, ''],
    'smtp_port' => ['SMTP 포트', 'number', false, '587'],
    'smtp_security' => ['보안 연결', 'select', false, 'starttls'],
    'smtp_user' => ['SMTP 계정', 'text', false, ''],
    'smtp_password' => ['SMTP 비밀번호', 'password', true, ''],
    'mail_from' => ['보내는 사람 주소', 'text', false, ''],
    'mail_from_name' => ['보내는 사람 이름', 'text', false, 'DATANEX'],
    'anthropic_key' => ['Anthropic API 키', 'password', true, ''],
    'ai_model' => ['Claude 모델', 'text', false, 'claude-opus-5-5'],
    'legacy_table' => ['기존 고객 테이블', 'text', false, 'pms_customers'],
];
const SETTING_OPTIONS = [
    'mail_method' => ['smtp' => '일반 SMTP (아이디·비밀번호)', 'm365' => 'Microsoft 365 (OAuth · Graph API)'],
    'smtp_security' => ['starttls' => 'STARTTLS (보통 587)', 'ssl' => 'SSL/TLS (보통 465)', 'none' => '사용 안 함 (보통 25)'],
];
function conf($k) { $f = SETTING_FIELDS[$k]; return get_setting($k, $f[3]); }

/* ---------- 권한 ---------- */
const PERMISSIONS = [
    'customer.view' => '고객 조회', 'customer.edit' => '고객·담당자 등록/수정/삭제', 'equipment.edit' => '장비 등록/수정/삭제',
    'log.upload' => '로그 업로드', 'log.delete' => '로그 삭제', 'report.view' => '보고서 조회', 'report.create' => '보고서 생성',
    'report.edit' => '보고서 수정', 'report.delete' => '보고서 삭제', 'report.send' => '보고서 메일 발송',
    'template.manage' => '고객 양식 관리', 'user.manage' => '사용자·권한 관리', 'settings.manage' => '시스템 설정(SMTP·회사정보·AI 키)',
];
const ROLE_LABELS = ['admin' => '관리자', 'manager' => '팀장', 'engineer' => '엔지니어', 'viewer' => '조회자'];
function role_defaults() {
    $all = array_keys(PERMISSIONS);
    return [
        'admin' => $all,
        'manager' => array_values(array_diff($all, ['user.manage', 'settings.manage'])),
        'engineer' => ['customer.view', 'customer.edit', 'equipment.edit', 'log.upload', 'report.view', 'report.create', 'report.edit', 'report.send'],
        'viewer' => ['customer.view', 'report.view'],
    ];
}
function effective_perms($u) {
    $rd = role_defaults(); $p = array_flip($rd[$u['role']] ?? []);
    foreach (jd($u['permissions'] ?? '', []) as $k => $on) {
        if (!isset(PERMISSIONS[$k])) continue;
        if ($on) $p[$k] = 1; else unset($p[$k]);
    }
    return array_keys($p);
}
function pw_check($pw) {
    if (strlen($pw) < 8) return '비밀번호는 8자 이상이어야 합니다.';
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/[0-9]/', $pw)) return '비밀번호는 영문과 숫자를 함께 써야 합니다.';
    return '';
}

/* ---------- 파일 ---------- */
function safe_name($n) {
    $n = preg_replace('/[\\\\\/:*?"<>|\x00-\x1f]+/u', '_', (string)$n);
    $n = trim($n, " .");
    return $n === '' ? 'file' : mb_substr($n, 0, 150);
}
function rrmdir($d) {
    if (!is_dir($d)) return;
    foreach (scandir($d) as $f) { if ($f === '.' || $f === '..') continue; $p = "$d/$f"; is_dir($p) ? rrmdir($p) : @unlink($p); }
    @rmdir($d);
}
function protect_dir($d) {
    if (!is_dir($d)) @mkdir($d, 0755, true);
    if (!is_file("$d/.htaccess")) @file_put_contents("$d/.htaccess", "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    if (!is_file("$d/index.html")) @file_put_contents("$d/index.html", '');
}
