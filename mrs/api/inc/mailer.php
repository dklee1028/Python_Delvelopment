<?php
/* SMTP 메일 발송 (보고서 PPT/엑셀 첨부). 외부 라이브러리 없이 소켓으로 직접 통신합니다.
   보안 연결: ssl(보통 465) / starttls(보통 587) / none */

class MailError extends Exception {}

function smtp_send($to, $cc, $subject, $text, $html, $attachments) {
    $host = conf('smtp_host'); $port = (int)conf('smtp_port') ?: 587; $sec = conf('smtp_security') ?: 'starttls';
    $user = conf('smtp_user'); $pass = conf('smtp_password');
    $from = conf('mail_from') ?: $user; $fromName = conf('mail_from_name') ?: conf('company_name');
    if (!$host) throw new MailError('SMTP 설정이 없어 메일을 보낼 수 없습니다. [시스템 설정]에서 SMTP 계정을 등록해 주세요.');
    if (!$from) throw new MailError('보내는 사람 주소가 없습니다.');
    foreach (array_merge($to, $cc) as $a) if (!filter_var($a, FILTER_VALIDATE_EMAIL)) throw new MailError("메일 주소 형식이 올바르지 않습니다: $a");

    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $fp = @stream_socket_client(($sec === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) throw new MailError("SMTP 서버에 연결할 수 없습니다: $errstr ($errno)");
    stream_set_timeout($fp, 30);
    $read = function () use ($fp) { $out = ''; while (($l = fgets($fp, 1024)) !== false) { $out .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $out; };
    $cmd = function ($c, $expect) use ($fp, $read) {
        if ($c !== null) fwrite($fp, $c . "\r\n");
        $r = $read(); $code = (int)substr($r, 0, 3);
        if (!in_array($code, (array)$expect, true)) throw new MailError('SMTP 오류: ' . trim($r ?: '응답 없음'));
        return $r;
    };
    try {
        $cmd(null, 220);
        $me = gethostname() ?: 'localhost';
        $ehlo = $cmd("EHLO $me", 250);
        if ($sec === 'starttls') {
            $cmd('STARTTLS', 220);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new MailError('STARTTLS 협상에 실패했습니다.');
            $ehlo = $cmd("EHLO $me", 250);
        }
        if ($user !== '') {
            if (stripos($ehlo, 'AUTH') !== false && stripos($ehlo, 'PLAIN') !== false) $cmd('AUTH PLAIN ' . base64_encode("\0$user\0$pass"), 235);
            else { $cmd('AUTH LOGIN', 334); $cmd(base64_encode($user), 334); $cmd(base64_encode($pass), 235); }
        }
        $cmd("MAIL FROM:<$from>", 250);
        foreach (array_merge($to, $cc) as $a) $cmd("RCPT TO:<$a>", [250, 251]);
        $cmd('DATA', 354);
        $msg = mime_build($from, $fromName, $to, $cc, $subject, $text, $html, $attachments);
        $msg = preg_replace('/^\./m', '..', $msg); // dot-stuffing
        fwrite($fp, $msg . "\r\n.\r\n");
        $cmd(null, 250);
        @fwrite($fp, "QUIT\r\n");
    } finally { @fclose($fp); }
}

function enc_hdr($s) { return preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s; }
function mime_build($from, $fromName, $to, $cc, $subject, $text, $html, $attachments) {
    $b1 = 'mrs_m_' . bin2hex(random_bytes(8)); $b2 = 'mrs_a_' . bin2hex(random_bytes(8));
    $h = ['Date: ' . date('r'), 'From: ' . enc_hdr($fromName) . " <$from>", 'To: ' . implode(', ', $to)];
    if ($cc) $h[] = 'Cc: ' . implode(', ', $cc);
    $h[] = 'Subject: ' . enc_hdr($subject);
    $h[] = 'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . (substr(strrchr($from, '@'), 1) ?: 'mrs') . '>';
    $h[] = 'MIME-Version: 1.0';
    $h[] = "Content-Type: multipart/mixed; boundary=\"$b1\"";
    $body = "--$b1\r\nContent-Type: multipart/alternative; boundary=\"$b2\"\r\n\r\n"
        . "--$b2\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text)) . "\r\n"
        . "--$b2\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "\r\n--$b2--\r\n";
    $types = ['pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'pdf' => 'application/pdf'];
    foreach ($attachments as [$name, $path]) {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION)); $ct = $types[$ext] ?? 'application/octet-stream';
        $en = "UTF-8''" . rawurlencode($name);
        $body .= "--$b1\r\nContent-Type: $ct; name=\"" . enc_hdr($name) . "\"\r\nContent-Transfer-Encoding: base64\r\n"
            . "Content-Disposition: attachment; filename=\"" . enc_hdr($name) . "\"; filename*=$en\r\n\r\n" . chunk_split(base64_encode((string)file_get_contents($path))) . "\r\n";
    }
    $body .= "--$b1--";
    return implode("\r\n", $h) . "\r\n\r\n" . $body;
}

/* ───────── 발송 방식 선택 ─────────
   mail_method = smtp  : 위의 smtp_send (아이디·비밀번호)
   mail_method = m365  : Microsoft 365 OAuth (Graph API sendMail, 클라이언트 자격 증명)
   회사 M365에 "보안 기본값"이 켜져 SMTP 로그인이 막혀도(535 5.7.139) 보안 정책을 바꾸지 않고 보낼 수 있습니다. */
function mail_ready() {
    if (conf('mail_method') === 'm365') return conf('m365_tenant') !== '' && conf('m365_client_id') !== '' && conf('m365_client_secret') !== '' && conf('mail_from') !== '';
    return (bool)conf('smtp_host');
}
function mail_send($to, $cc, $subject, $text, $html, $attachments) {
    if (conf('mail_method') === 'm365') return graph_send($to, $cc, $subject, $text, $html, $attachments);
    return smtp_send($to, $cc, $subject, $text, $html, $attachments);
}

function m365_base($kind) {
    // 테스트용 주소 바꾸기(환경변수). 운영에서는 항상 Microsoft 공식 주소를 씁니다.
    $t = getenv('MRS_M365_TEST_BASE');
    if ($t) return rtrim($t, '/') . '/' . $kind;
    return $kind === 'login' ? 'https://login.microsoftonline.com' : 'https://graph.microsoft.com';
}
function m365_http($url, $body, $headers) {
    if (!function_exists('curl_init')) throw new MailError('서버에 PHP cURL 확장이 없어 Microsoft 365로 보낼 수 없습니다. 호스팅 업체에 cURL 활성화를 요청해 주세요.');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 90, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
    $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($res === false) throw new MailError("Microsoft 서버에 연결할 수 없습니다: $err");
    return [$code, (string)$res];
}
function m365_token() {
    $tenant = conf('m365_tenant'); $cid = conf('m365_client_id'); $sec = conf('m365_client_secret');
    if ($tenant === '' || $cid === '' || $sec === '') throw new MailError('Microsoft 365 설정(테넌트 ID·클라이언트 ID·클라이언트 암호)을 [시스템 설정]에서 모두 입력해 주세요.');
    [$code, $res] = m365_http(m365_base('login') . '/' . rawurlencode($tenant) . '/oauth2/v2.0/token',
        http_build_query(['grant_type' => 'client_credentials', 'client_id' => $cid, 'client_secret' => $sec, 'scope' => 'https://graph.microsoft.com/.default']),
        ['Content-Type: application/x-www-form-urlencoded']);
    $j = json_decode($res, true);
    if ($code === 200 && !empty($j['access_token'])) return $j['access_token'];
    $d = (string)($j['error_description'] ?? $res);
    $hint = '';
    if (strpos($d, 'AADSTS7000215') !== false) $hint = '클라이언트 암호가 틀립니다. Azure에서 만든 암호의 "값"(비밀 ID 아님)을 다시 입력해 주세요.';
    elseif (strpos($d, 'AADSTS7000222') !== false) $hint = '클라이언트 암호가 만료되었습니다. Azure에서 새 암호를 만들어 다시 입력해 주세요.';
    elseif (strpos($d, 'AADSTS700016') !== false) $hint = '애플리케이션(클라이언트) ID를 찾을 수 없습니다. 값과 테넌트가 맞는지 확인해 주세요.';
    elseif (strpos($d, 'AADSTS90002') !== false || strpos($d, 'AADSTS900023') !== false) $hint = '디렉터리(테넌트) ID가 올바르지 않습니다.';
    throw new MailError('Microsoft 365 인증 실패' . ($hint ? ": $hint" : '') . ' [' . mb_substr(strtok($d, "\r\n"), 0, 200) . ']');
}
function graph_send($to, $cc, $subject, $text, $html, $attachments) {
    $from = conf('mail_from');
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) throw new MailError('Microsoft 365 방식은 "보내는 사람 주소"에 실제 회사 메일함 주소(예: report@회사도메인)를 넣어야 합니다.');
    foreach (array_merge($to, $cc) as $a) if (!filter_var($a, FILTER_VALIDATE_EMAIL)) throw new MailError("메일 주소 형식이 올바르지 않습니다: $a");
    $types = ['pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'pdf' => 'application/pdf'];
    $att = []; $total = 0;
    foreach ($attachments as [$name, $path]) {
        $bin = (string)@file_get_contents($path); $total += strlen($bin);
        $att[] = ['@odata.type' => '#microsoft.graph.fileAttachment', 'name' => $name, 'contentType' => $types[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? 'application/octet-stream', 'contentBytes' => base64_encode($bin)];
    }
    if ($total > 2.8 * 1024 * 1024) throw new MailError('첨부 파일 합계가 약 2.8MB를 넘어 Microsoft 365 간편 발송 한도를 초과합니다. 보고서를 내려받아 직접 보내 주세요.');
    $rcpt = function ($list) { return array_map(function ($a) { return ['emailAddress' => ['address' => $a]]; }, array_values($list)); };
    $msg = ['subject' => $subject, 'body' => ['contentType' => 'HTML', 'content' => $html !== '' ? $html : nl2br(htmlspecialchars($text))], 'toRecipients' => $rcpt($to)];
    if ($cc) $msg['ccRecipients'] = $rcpt($cc);
    if ($att) $msg['attachments'] = $att;
    $token = m365_token();
    [$code, $res] = m365_http(m365_base('graph') . '/v1.0/users/' . rawurlencode($from) . '/sendMail',
        json_encode(['message' => $msg, 'saveToSentItems' => true], JSON_UNESCAPED_UNICODE),
        ['Authorization: Bearer ' . $token, 'Content-Type: application/json']);
    if ($code === 202) return true;
    $j = json_decode($res, true); $ec = (string)($j['error']['code'] ?? ''); $em = (string)($j['error']['message'] ?? $res);
    $hint = '';
    if ($code === 403 || $ec === 'ErrorAccessDenied') $hint = 'Azure 앱에 Mail.Send "애플리케이션" 권한과 "관리자 동의"가 되어 있는지 확인해 주세요. (접근 제한 정책을 걸었다면 보내는 주소가 허용 목록에 있어야 합니다.)';
    elseif ($code === 404 || $ec === 'ErrorInvalidUser' || $ec === 'ResourceNotFound') $hint = '보내는 사람 주소의 메일함을 찾을 수 없습니다. Exchange 라이선스가 있는 실제 메일함 주소인지 확인해 주세요.';
    elseif ($code === 401) $hint = '토큰이 거부되었습니다. 테넌트 ID와 클라이언트 ID가 같은 회사(테넌트)의 값인지 확인해 주세요.';
    elseif ($code === 413) $hint = '첨부 파일이 너무 큽니다.';
    throw new MailError("Microsoft 365 발송 실패 ($code" . ($ec ? " $ec" : '') . ')' . ($hint ? ": $hint" : '') . ' [' . mb_substr($em, 0, 200) . ']');
}
