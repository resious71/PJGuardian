<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Support;

/**
 * 시험용 가짜 SMTP 수신기 띄우기(P30B, tests/Support/fake_smtp_server.php). 127.0.0.1의 빈 포트, 외부 연결 없음.
 * TLS 시험용 사설 CA와 서버 인증서(이름 localhost)를 PHP openssl 확장으로 만든다 — 클라이언트는 이 CA를 신뢰 목록으로만 받고 검증은 그대로 켠다.
 */
final class FakeSmtp
{
    /** @var resource|null */
    private $proc = null;
    public readonly int $port;
    public readonly string $out;

    /**
     * @param array{user?:string, pass?:string, reject_rcpt?:string, silent?:bool, untrusted?:bool} $opt untrusted: CA가 아닌 자체 서명 인증서로(검증 실패 시험)
     */
    public function __construct(private readonly string $dir, public readonly string $mode = 'plain', array $opt = [])
    {
        @mkdir($dir, 0777, true);
        $this->port = self::freePort();
        $this->out = $dir . '/mail-' . $this->port . '.jsonl';
        $certs = self::certs($dir);
        $args = [PHP_BINARY, PJG_ROOT . '/tests/Support/fake_smtp_server.php', '--port=' . $this->port, '--mode=' . $mode, '--out=' . $this->out,
            '--ready=' . $dir . '/ready-' . $this->port];
        if ($mode !== 'plain') {
            $args[] = '--cert=' . (($opt['untrusted'] ?? false) ? $certs['self_cert'] : $certs['cert']);
            $args[] = '--key=' . (($opt['untrusted'] ?? false) ? $certs['self_key'] : $certs['key']);
        }
        if (isset($opt['user'])) {
            $args[] = '--user=' . $opt['user'];
            $args[] = '--pass=' . ($opt['pass'] ?? '');
        }
        if (isset($opt['reject_rcpt'])) {
            $args[] = '--reject-rcpt=' . $opt['reject_rcpt'];
        }
        if ($opt['silent'] ?? false) {
            $args[] = '--silent';
        }
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $this->proc = proc_open($args, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $dir . '/err-' . $this->port . '.txt', 'w']], $pipes);
        for ($i = 0; $i < 100 && !is_file($dir . '/ready-' . $this->port); $i++) {
            usleep(50_000);
        }
        if (!is_file($dir . '/ready-' . $this->port)) {
            throw new \RuntimeException('가짜 SMTP 수신기가 뜨지 않음: ' . @file_get_contents($dir . '/err-' . $this->port . '.txt'));
        }
    }

    /** @return array{cafile:string, peer_name:string} 클라이언트가 신뢰할 CA(검증은 켠 그대로) */
    public function trust(): array
    {
        return ['cafile' => self::certs($this->dir)['ca'], 'peer_name' => 'localhost'];
    }

    /** @return list<array{from:string, rcpt:list<string>, data:string, tls:bool, auth:bool}> */
    public function messages(): array
    {
        $out = [];
        foreach ((is_file($this->out) ? file($this->out, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : []) ?: [] as $line) {
            $out[] = json_decode($line, true);
        }
        return $out;
    }

    public function stop(): void
    {
        if (is_resource($this->proc)) {
            $st = proc_get_status($this->proc);
            if ($st['running']) {
                if (PHP_OS_FAMILY === 'Windows') {
                    $k = proc_open(['taskkill', '/F', '/T', '/PID', (string) $st['pid']], [1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $p);
                    if (is_resource($k)) {
                        proc_close($k);
                    }
                } else {
                    proc_terminate($this->proc, 9);
                }
            }
            proc_close($this->proc);
        }
        $this->proc = null;
    }

    public function __destruct()
    {
        $this->stop();
    }

    private static function freePort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0', $no, $str);
        $name = (string) stream_socket_get_name($s, false);
        fclose($s);
        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /** @return array{ca:string, cert:string, key:string, self_cert:string, self_key:string} 한 폴더에 한 번만 만든다 */
    public static function certs(string $dir): array
    {
        $f = ['ca' => "{$dir}/ca.pem", 'cert' => "{$dir}/srv.pem", 'key' => "{$dir}/srv.key", 'self_cert' => "{$dir}/self.pem", 'self_key' => "{$dir}/self.key"];
        if (is_file($f['self_key'])) {
            return $f;
        }
        @mkdir($dir, 0777, true);
        $cnf = "{$dir}/o.cnf";
        file_put_contents($cnf, "[ req ]\ndistinguished_name = dn\n[ dn ]\n[ v3_ca ]\nbasicConstraints = critical,CA:true\nkeyUsage = keyCertSign, cRLSign\n"
            . "[ v3_srv ]\nbasicConstraints = CA:false\nsubjectAltName = DNS:localhost, IP:127.0.0.1\nextendedKeyUsage = serverAuth\n");
        $opt = ['config' => $cnf, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];
        $caKey = openssl_pkey_new($opt);
        $ca = openssl_csr_sign(openssl_csr_new(['commonName' => 'PJG Mail Test CA'], $caKey, $opt), null, $caKey, 30, $opt + ['x509_extensions' => 'v3_ca'], 1);
        $srvKey = openssl_pkey_new($opt);
        $srv = openssl_csr_sign(openssl_csr_new(['commonName' => 'localhost'], $srvKey, $opt), $ca, $caKey, 30, $opt + ['x509_extensions' => 'v3_srv'], 2);
        $selfKey = openssl_pkey_new($opt);
        $self = openssl_csr_sign(openssl_csr_new(['commonName' => 'localhost'], $selfKey, $opt), null, $selfKey, 30, $opt + ['x509_extensions' => 'v3_srv'], 3);
        openssl_x509_export_to_file($ca, $f['ca']);
        openssl_x509_export_to_file($srv, $f['cert']);
        openssl_pkey_export_to_file($srvKey, $f['key'], null, $opt);
        openssl_x509_export_to_file($self, $f['self_cert']);
        openssl_pkey_export_to_file($selfKey, $f['self_key'], null, $opt);
        while (openssl_error_string() !== false) {
        }
        return $f;
    }
}
