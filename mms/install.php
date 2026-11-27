<?php
/* MRS 설치 프로그램 — 최초 1회 실행 후 이 파일은 서버에서 삭제하세요.
   PMS 와 같은 DB(datanex8)를 쓰며, mrs_ 로 시작하는 테이블만 새로 만듭니다. 기존 pms_ 테이블은 건드리지 않습니다. */
require __DIR__ . '/api/lib.php';
$cfgFile = __DIR__ . '/config.php';
$done = false; $err = ''; $legacyInfo = '';
$v = $_POST + ['driver' => 'mysql', 'host' => 'localhost', 'port' => '3306', 'name' => 'datanex8', 'user' => 'datanex8', 'admin_id' => 'admin', 'admin_name' => '관리자', 'admin_email' => '', 'admin_phone' => ''];
$installed = is_file($cfgFile);
$env = [
    ['PHP 버전 7.4 이상', version_compare(PHP_VERSION, '7.4', '>='), PHP_VERSION],
    ['PDO MySQL 확장 (MariaDB 접속)', extension_loaded('pdo_mysql'), ''],
    ['mbstring 확장 (한글 처리)', extension_loaded('mbstring'), ''],
    ['zip 확장 (PPT·엑셀 생성)', class_exists('ZipArchive'), ''],
    ['openssl 확장 (비밀번호·API 키 암호화, 메일 보안 연결)', function_exists('openssl_encrypt'), ''],
    ['cURL 확장 (Claude AI 호출)', function_exists('curl_init'), '없으면 규칙 기반 초안만 사용'],
    ['설치 폴더 쓰기 권한 (config.php, data 폴더 생성)', is_writable(__DIR__), ''],
];
$required = [0, 1, 2, 3, 4, 6];
$envOk = true; foreach ($required as $i) if (!$env[$i][1]) $envOk = false;

if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$envOk) throw new Exception('서버 환경 점검 항목을 먼저 해결하세요.');
        if (!preg_match('/^[A-Za-z0-9_.\-]{3,64}$/', $v['admin_id'])) throw new Exception('관리자 아이디는 영문/숫자 3자 이상이어야 합니다.');
        if (trim($v['admin_name']) === '') throw new Exception('관리자 이름을 입력하세요.');
        if ($e = pw_check($v['admin_pw'] ?? '')) throw new Exception($e);
        if ($v['admin_email'] !== '' && !filter_var($v['admin_email'], FILTER_VALIDATE_EMAIL)) throw new Exception('관리자 이메일 형식이 올바르지 않습니다.');
        protect_dir(__DIR__ . '/data');
        if ($v['driver'] === 'sqlite') $db = ['driver' => 'sqlite', 'path' => __DIR__ . '/data/mrs_' . bin2hex(random_bytes(6)) . '.sqlite'];
        else $db = ['driver' => 'mysql', 'host' => trim($v['host']), 'port' => (int)$v['port'] ?: 3306, 'name' => trim($v['name']), 'user' => trim($v['user']), 'pass' => (string)($v['pass'] ?? '')];
        $pdo = db_connect($db); db($pdo);
        $code = "<?php\n/* MRS 설정 파일 - 외부에 노출되지 않도록 주의하세요. secret 을 바꾸면 저장된 SMTP 비밀번호·API 키를 다시 입력해야 합니다. */\nreturn "
            . var_export(['db' => $db, 'secret' => bin2hex(random_bytes(32))], true) . ";\n";
        if (file_put_contents($cfgFile, $code) === false) throw new Exception('config.php 를 쓸 수 없습니다. 폴더 쓰기 권한(705/755)을 확인하세요.');
        @chmod($cfgFile, 0600);
        install_schema($pdo, $db['driver']);
        if (!val("SELECT COUNT(*) FROM mrs_user WHERE username=?", [$v['admin_id']])) {
            ins('mrs_user', ['username' => $v['admin_id'], 'password_hash' => password_hash($v['admin_pw'], PASSWORD_DEFAULT), 'full_name' => trim($v['admin_name']),
                'email' => trim($v['admin_email']), 'phone' => trim($v['admin_phone']), 'title' => '', 'role' => 'admin', 'permissions' => '{}', 'is_active' => 1, 'must_change_pw' => 0,
                'created_at' => now(), 'updated_at' => now()]);
        }
        $legacyInfo = has_table('pms_customers') ? 'PMS 고객 테이블(pms_customers) ' . number_format((int)val('SELECT COUNT(*) FROM pms_customers')) . '곳을 찾았습니다. [고객] 화면에서 바로 불러올 수 있습니다.'
            : 'PMS 고객 테이블(pms_customers)을 찾지 못했습니다. 다른 DB라면 [시스템 설정]에서 고객 테이블 이름을 지정하세요.';
        $done = true;
    } catch (Throwable $e) {
        if (!$done && is_file($cfgFile) && !$installed) @unlink($cfgFile);
        $err = $e->getMessage();
    }
}
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?><!DOCTYPE html>
<html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>MRS 설치</title><link rel="icon" href="assets/icons/mrs-32.png">
<style>
body{margin:0;font-family:'Apple SD Gothic Neo','Malgun Gothic',sans-serif;background:#eef2f6;color:#16202c}
.box{max-width:580px;margin:40px auto;background:#fff;border-radius:10px;padding:32px;box-shadow:0 10px 30px rgba(16,40,70,.08)}
.top{display:flex;align-items:center;gap:12px}.top img{height:44px;border-radius:10px}
h1{font-size:20px;margin:0} p{color:#5b6877;font-size:14px;line-height:1.6}
h2{font-size:13px;color:#1969a5;margin:24px 0 8px;letter-spacing:.04em}
label{display:block;font-size:13px;font-weight:600;margin:10px 0 4px} input,select{width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #cfd8e3;border-radius:6px;font-size:14px}
.row{display:grid;grid-template-columns:1fr 1fr;gap:12px} @media(max-width:520px){.row{grid-template-columns:1fr}.box{margin:0;border-radius:0}}
button{margin-top:22px;width:100%;padding:12px;border:0;border-radius:6px;background:#1969a5;color:#fff;font-size:15px;font-weight:700;cursor:pointer}
.err{background:#fdecec;color:#a4262c;padding:12px;border-radius:7px;font-size:14px;margin-top:14px}.okb{background:#e8f6ee;color:#17663a;padding:14px;border-radius:7px;font-size:14px;line-height:1.8}
a{color:#1969a5;font-weight:700} small{color:#8493a3}
</style></head><body><div class="box">
<div class="top"><img src="assets/icons/mrs-192.png" alt=""><div><h1>MRS 보고서 시스템 설치</h1><small>Management Report System · DATANEX</small></div></div>
<?php if ($done): ?>
  <div class="okb" style="margin-top:20px"><b>설치가 완료되었습니다.</b><br>1. 보안을 위해 서버에서 <b>install.php 파일을 삭제</b>하세요.<br>2. <a href="./">MRS 로그인 화면으로 이동</a>해 방금 만든 관리자 계정으로 로그인하세요.<br>
  3. [시스템 설정]에서 SMTP 계정과 Anthropic API 키를 입력하세요.<br>4. <?= h($legacyInfo) ?></div>
<?php elseif ($installed): ?>
  <div class="okb" style="margin-top:20px">이미 설치되어 있습니다. <a href="./">MRS로 이동</a><br>다시 설치하려면 서버의 config.php 파일을 삭제한 뒤 이 페이지를 새로고침하세요.</div>
<?php else: ?>
  <p>PMS와 같은 MariaDB 접속 정보와 최초 관리자 계정을 입력하세요. 기존 테이블은 그대로 두고 <b>mrs_</b>로 시작하는 테이블만 추가합니다.</p>
  <h2>서버 환경 점검</h2>
  <table style="width:100%;border-collapse:collapse;font-size:13px"><?php foreach ($env as $i => $e): ?><tr><td style="padding:5px 0;border-bottom:1px solid #eef2f6"><?= h($e[0]) ?> <span style="color:#8493a3"><?= h($e[2]) ?></span></td><td style="text-align:right;border-bottom:1px solid #eef2f6;font-weight:700;color:<?= $e[1] ? '#17663a' : (in_array($i, $required, true) ? '#a4262c' : '#b7791f') ?>"><?= $e[1] ? '정상' : (in_array($i, $required, true) ? '필요' : '권장') ?></td></tr><?php endforeach; ?></table>
  <?php if ($err): ?><div class="err">설치 실패: <?= h($err) ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <h2>데이터베이스 (PMS와 같은 DB)</h2>
    <label>DB 종류</label><select name="driver" onchange="document.getElementById('my').style.display=this.value==='mysql'?'':'none'">
      <option value="mysql" <?= $v['driver'] === 'mysql' ? 'selected' : '' ?>>MariaDB / MySQL (권장)</option>
      <option value="sqlite" <?= $v['driver'] === 'sqlite' ? 'selected' : '' ?>>SQLite (시험용, 기존 고객 DB 연동 없음)</option></select>
    <div id="my" style="<?= $v['driver'] === 'sqlite' ? 'display:none' : '' ?>">
      <div class="row"><div><label>DB 호스트</label><input name="host" value="<?= h($v['host']) ?>"></div><div><label>포트</label><input name="port" value="<?= h($v['port']) ?>"></div></div>
      <label>DB 이름</label><input name="name" value="<?= h($v['name']) ?>">
      <div class="row"><div><label>DB 사용자</label><input name="user" value="<?= h($v['user']) ?>"></div><div><label>DB 비밀번호</label><input name="pass" type="password"></div></div>
    </div>
    <h2>관리자 계정</h2>
    <div class="row"><div><label>아이디</label><input name="admin_id" value="<?= h($v['admin_id']) ?>"></div><div><label>이름</label><input name="admin_name" value="<?= h($v['admin_name']) ?>"></div></div>
    <label>비밀번호 (8자 이상, 영문+숫자)</label><input name="admin_pw" type="password">
    <div class="row"><div><label>이메일</label><input name="admin_email" value="<?= h($v['admin_email']) ?>"></div><div><label>연락처</label><input name="admin_phone" value="<?= h($v['admin_phone']) ?>"></div></div>
    <small>이메일·연락처는 이 계정이 담당 엔지니어인 보고서에 회사 연락처로 표시됩니다.</small>
    <button type="submit">설치 시작</button>
  </form>
<?php endif; ?>
</div></body></html>
