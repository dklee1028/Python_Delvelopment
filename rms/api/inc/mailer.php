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
