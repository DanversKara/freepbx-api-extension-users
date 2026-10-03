<?php
/**
 * API Users – minimal SMTP client for alert e-mails (no dependencies, unit-testable).
 *
 * Supports: STARTTLS (port 587, the normal choice), implicit TLS (465) and plain (25, LAN only),
 * AUTH LOGIN, UTF-8 subject/body. Certificate checking is on unless the admin turns it off
 * (only useful for a mail server on the LAN reached by IP).
 *
 * $cfg = ['host','port','security' => starttls|ssl|none,'user','pass','from','verify' => bool]
 * Returns null on success, or an error message.
 */

namespace ApiUsers;

class Mailer
{
    public static function send(array $cfg, string $to, string $subject, string $body, int $timeout = 15): ?string
    {
        $host = trim((string)($cfg['host'] ?? ''));
        $port = (int)($cfg['port'] ?? 587);
        $sec = (string)($cfg['security'] ?? 'starttls');
        $from = self::clean((string)($cfg['from'] ?? ''));
        $to = self::clean($to);
        if ($host === '' || $from === '' || $to === '') return 'mail server, sender and recipient are required';
        if (!filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)) return 'sender or recipient is not an e-mail address';

        $verify = !array_key_exists('verify', $cfg) || !empty($cfg['verify']);
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => $verify, 'verify_peer_name' => $verify, 'allow_self_signed' => !$verify,
            'peer_name' => $host, 'SNI_enabled' => true,
        ]]);
        $remote = ($sec === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) return "cannot connect to $host:$port ($errstr)";
        stream_set_timeout($fp, $timeout);

        $err = null;
        try {
            self::expect($fp, [220]);
            $me = gethostname() ?: 'pbx';
            $ext = self::cmd($fp, "EHLO $me", [250]);
            if ($sec === 'starttls') {
                if (stripos($ext, 'STARTTLS') === false) throw new \RuntimeException('server does not offer STARTTLS');
                self::cmd($fp, 'STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new \RuntimeException('TLS handshake failed (certificate?)');
                }
                self::cmd($fp, "EHLO $me", [250]);
            }
            if (($cfg['user'] ?? '') !== '') {
                self::cmd($fp, 'AUTH LOGIN', [334]);
                self::cmd($fp, base64_encode((string)$cfg['user']), [334], true);
                self::cmd($fp, base64_encode((string)($cfg['pass'] ?? '')), [235], true);
            }
            self::cmd($fp, "MAIL FROM:<$from>", [250]);
            self::cmd($fp, "RCPT TO:<$to>", [250, 251]);
            self::cmd($fp, 'DATA', [354]);
            $domain = substr(strrchr($from, '@'), 1) ?: 'localhost';
            $headers = [
                'From: API Users <' . $from . '>',
                'To: <' . $to . '>',
                'Subject: ' . self::encodeHeader(self::clean($subject)),
                'Date: ' . date('r'),
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
                'X-Mailer: apiusers',
            ];
            $text = str_replace(["\r\n", "\r"], "\n", $body);
            $lines = [];
            foreach (explode("\n", $text) as $l) $lines[] = (isset($l[0]) && $l[0] === '.') ? '.' . $l : $l;   // dot-stuffing
            fwrite($fp, implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $lines) . "\r\n.\r\n");
            self::expect($fp, [250]);
            self::cmd($fp, 'QUIT', [221]);
        } catch (\Throwable $e) {
            $err = $e->getMessage();
        }
        @fclose($fp);
        return $err;
    }

    /** Strip anything that could start a new header line. */
    public static function clean(string $s): string
    {
        return trim(str_replace(["\r", "\n", "\0"], ' ', $s));
    }

    public static function encodeHeader(string $s): string
    {
        return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }

    private static function cmd($fp, string $line, array $ok, bool $secret = false): string
    {
        fwrite($fp, $line . "\r\n");
        try {
            return self::expect($fp, $ok);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(($secret ? 'login' : strtok($line, ' ')) . ': ' . $e->getMessage());
        }
    }

    /** Read a (possibly multi-line) reply and check its code. */
    private static function expect($fp, array $ok): string
    {
        $all = '';
        while (($l = fgets($fp, 2048)) !== false) {
            $all .= $l;
            if (strlen($l) < 4 || $l[3] !== '-') break;
        }
        if ($all === '') {
            $meta = stream_get_meta_data($fp);
            throw new \RuntimeException(!empty($meta['timed_out']) ? 'mail server timed out' : 'mail server closed the connection');
        }
        $code = (int)substr($all, 0, 3);
        if (!in_array($code, $ok, true)) throw new \RuntimeException('server said: ' . trim(substr($all, 0, 200)));
        return $all;
    }
}
