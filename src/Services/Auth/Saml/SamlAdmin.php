<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth\Saml;

use OneLogin\Saml2\IdPMetadataParser;
use PJGuardian\Http\ApiException;
use PJGuardian\Services\Actor;
use PJGuardian\Services\AuditLog;
use PJGuardian\Services\Auth\AppSecrets;
use PJGuardian\Services\Auth\Oidc\OidcException;
use PJGuardian\Services\Auth\Oidc\OidcHttp;
use PJGuardian\Services\Notifications\NotificationService;
use PJGuardian\Support\Clock;
use PJGuardian\Support\ConfigSchema;
use PJGuardian\Support\FileLock;
use PJGuardian\Support\Fs;
use PJGuardian\Support\Logger;
use PJGuardian\Support\SystemClock;

/**
 * 관리자 SSO(SAML) 화면 지원(P28D, admin만): 상태(SP 메타데이터 주소·ACS·SP EntityID·인증서 만료일), SP 키·자체 서명 인증서 생성·교체·삭제
 * (개인 키는 app_secrets에 암호화 — 화면·API·로그에 내리지 않음), IdP 메타데이터 가져오기(XML 붙여넣기 또는 https 주소 — 값만 돌려주고 저장은 설정 화면이),
 * 설정 확인(인증서 형식·만료, SSO 주소 도달, SP 설정, SP 메타데이터), 점검 항목, IdP 인증서 만료 임박 알림(워커가 시간마다).
 */
final class SamlAdmin
{
    public const METADATA_MAX_BYTES = 1048576;

    /** @param \Closure(string): OidcHttp $http 주소 판정 기준(발급자 자리)을 받아 만든 외부 요청 */
    public function __construct(
        private readonly SamlSettings $settings,
        private readonly SamlLogin $login,
        private readonly \Closure $http,
        private readonly AppSecrets $secrets,
        private readonly bool $schemaReady,
        private readonly bool $noticeReady,
        private readonly AuditLog $audit,
        private readonly Logger $logger,
        private readonly NotificationService $notifications,
        private readonly Clock $clock,
        private readonly string $workRoot,
    ) {
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        return [
            'enabled' => $this->settings->enabled,
            'schema_ready' => $this->schemaReady,
            'library' => SamlSettings::libraryAvailable(),
            'configured' => $this->settings->complete(),
            'sp_entity_id' => $this->settings->spEntityId(),
            'acs_url' => $this->settings->acsUrl(),
            'metadata_url' => $this->settings->metadataUrl(),
            'sp_key' => $this->schemaReady ? $this->secrets->state('saml.sp_private_key') : 'none',
            'sp_cert' => $this->spCertInfo(),
            'sp_key_needed' => $this->settings->needsSpKey(),
            'idp_certs' => $this->idpCertInfo(),
            'warn_days' => intdiv($this->settings->certWarnSeconds, 86400),
        ];
    }

    /** @return list<array{which:string, valid:bool, not_after:?string, days_left:?int, subject:?string, fingerprint:?string}> */
    public function idpCertInfo(): array
    {
        $now = $this->clock->now()->getTimestamp();
        $out = [];
        foreach ($this->settings->idpCerts() as $c) {
            $i = ConfigSchema::samlCertInfo($c['b64']);
            $out[] = $i === null ? ['which' => $c['which'], 'valid' => false, 'not_after' => null, 'days_left' => null, 'subject' => null, 'fingerprint' => null]
                : ['which' => $c['which'], 'valid' => true, 'not_after' => SystemClock::iso((new \DateTimeImmutable('@' . $i['not_after']))),
                    'days_left' => (int) floor(($i['not_after'] - $now) / 86400), 'subject' => $i['subject'], 'fingerprint' => self::colon(substr($i['fingerprint'], 0, 32))];
        }
        return $out;
    }

    /** @return array{not_after:string, fingerprint:string}|null */
    private function spCertInfo(): ?array
    {
        if (!$this->schemaReady || $this->secrets->state('saml.sp_cert') !== 'set') {
            return null;
        }
        $pem = $this->secrets->get('saml.sp_cert');
        $i = @openssl_x509_parse($pem);
        if (!is_array($i)) {
            return null;
        }
        $der = base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $pem), true);
        return ['not_after' => SystemClock::iso(new \DateTimeImmutable('@' . (int) $i['validTo_time_t'])), 'fingerprint' => self::colon(substr(hash('sha256', (string) $der), 0, 32))];
    }

    private static function colon(string $hex): string
    {
        return strtoupper(implode(':', str_split($hex, 2)));
    }

    /**
     * SP 개인 키(RSA 2048)와 자체 서명 인증서(10년, SHA-256)를 만들어 암호화 저장(있으면 교체). 인증서(공개)만 메타데이터에 나간다.
     *
     * @return array<string, mixed> 새 상태
     */
    public function generateSpKey(Actor $actor): array
    {
        if (!$this->schemaReady) {
            throw new ApiException(409, 'app_secret_migration_required');
        }
        $cnf = $this->workRoot . '/saml/openssl-min.cnf';
        Fs::ensureDir(dirname($cnf));
        if (!is_file($cnf)) {
            Fs::atomicWrite($cnf, "# PJGuardian SAML SP 키 생성용 최소 설정(openssl.cnf 없이)\n[ req ]\ndistinguished_name = req_dn\n[ req_dn ]\n");
        }
        $opt = ['config' => $cnf, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
        $key = openssl_pkey_new($opt);
        $csr = $key === false ? false : openssl_csr_new(['commonName' => 'PJGuardian SAML SP'], $key, $opt);
        $x509 = $csr === false || $csr === true ? false : openssl_csr_sign($csr, null, $key, 3650, $opt, random_int(1, PHP_INT_MAX));
        $ok = $x509 !== false && openssl_pkey_export($key, $keyPem, null, $opt) && openssl_x509_export($x509, $certPem);
        while (openssl_error_string() !== false) {
            // 오류 큐 비우기(기본 설정 파일을 찾는 경고)
        }
        if (!$ok) {
            $this->logger->error('SAML SP 키 생성 실패');
            throw new ApiException(500, 'saml_key_generate_failed');
        }
        $replaced = $this->secrets->state('saml.sp_private_key') !== 'none';
        $this->secrets->put('saml.sp_private_key', (string) $keyPem, $actor->userId);
        $this->secrets->put('saml.sp_cert', (string) $certPem, $actor->userId);
        $this->audit->record($actor, 'auth.saml_sp_key_generate', null, null, ['replaced' => $replaced]); // 키·인증서 값은 남기지 않는다
        $this->logger->info('SAML SP 키 생성', ['by' => $actor->label, 'replaced' => $replaced]);
        return $this->status();
    }

    public function deleteSpKey(Actor $actor): array
    {
        if ($this->schemaReady && $this->secrets->delete('saml.sp_private_key')) {
            $this->secrets->delete('saml.sp_cert');
            $this->audit->record($actor, 'auth.saml_sp_key_delete', null, null, []);
        }
        return $this->status();
    }

    /**
     * IdP 메타데이터 가져오기 — 저장하지 않고 설정 화면이 채울 값만(관리자가 확인하고 설정 저장).
     *
     * @return array{idp_entity_id:string, idp_sso_url:string, idp_cert:string, idp_cert_next:string, certs:int}
     */
    public function importMetadata(Actor $actor, mixed $xml, mixed $url): array
    {
        $this->consumeQuota((int) $actor->userId);
        if (is_string($url) && $url !== '') {
            if (!OidcHttp::validUrl($url)) {
                throw new ApiException(400, 'saml_metadata_url_invalid');
            }
            try {
                $res = ($this->http)($url)->get($url, ['Accept' => 'application/samlmetadata+xml, application/xml']);
            } catch (OidcException $e) {
                $this->logger->warn('SAML 메타데이터 가져오기 실패', ['reason' => $e->reason]);
                throw new ApiException(502, 'saml_metadata_unreachable', ['reason' => explode('.', $e->reason)[0]]);
            }
            if ($res['status'] !== 200) {
                throw new ApiException(502, 'saml_metadata_unreachable', ['reason' => 'http_status']);
            }
            $xml = $res['body'];
        }
        if (!is_string($xml) || $xml === '' || strlen($xml) > self::METADATA_MAX_BYTES) {
            throw new ApiException(400, 'saml_metadata_invalid');
        }
        try {
            $parsed = IdPMetadataParser::parseXML($xml); // XML을 안전하게 읽음(DOCTYPE·ENTITY 거부)
        } catch (\Throwable) {
            throw new ApiException(400, 'saml_metadata_invalid');
        }
        $idp = $parsed['idp'] ?? null;
        if (!is_array($idp) || !is_string($idp['entityId'] ?? null) || !is_string($idp['singleSignOnService']['url'] ?? null)) {
            throw new ApiException(400, 'saml_metadata_invalid');
        }
        $certs = [];
        foreach ([$idp['x509cert'] ?? null, ...(array) ($idp['x509certMulti']['signing'] ?? [])] as $c) {
            $b64 = is_string($c) ? (string) preg_replace('/-----[^-]+-----|\s+/', '', $c) : '';
            if ($b64 !== '' && ConfigSchema::samlCertInfo($b64) !== null && !in_array($b64, $certs, true)) {
                $certs[] = $b64;
            }
        }
        if ($certs === []) {
            throw new ApiException(400, 'saml_metadata_invalid');
        }
        $this->audit->record($actor, 'auth.saml_metadata_import', null, null, ['source' => is_string($url) && $url !== '' ? 'url' : 'xml', 'certs' => count($certs)]);
        return ['idp_entity_id' => $idp['entityId'], 'idp_sso_url' => $idp['singleSignOnService']['url'], 'idp_cert' => $certs[0], 'idp_cert_next' => $certs[1] ?? '', 'certs' => count($certs)];
    }

    /** SP 메타데이터 XML(공개 — 서명 인증서 포함). 설정이 덜 됐으면 SamlException */
    public function spMetadata(): string
    {
        $settings = $this->login->librarySettings(true, true);
        $xml = $settings->getSPMetadata();
        if ($settings->validateMetadata($xml) !== []) {
            throw new SamlException('settings_invalid');
        }
        return $xml;
    }

    /**
     * 설정 확인(단계별): IdP 인증서 형식·만료(현재·다음) → SSO 주소 도달(https, 주소 판정, 시간 상한 — 4xx도 도달) → SP 설정(키가 필요하면 있는지) → SP 메타데이터.
     *
     * @return array{steps: list<array{step:string, ok:?bool, code:?string, detail:array<string,mixed>}>, ok: bool}
     */
    public function test(Actor $actor): array
    {
        $this->consumeQuota((int) $actor->userId);
        $steps = [];
        $failed = false;
        $run = function (string $step, \Closure $fn) use (&$steps, &$failed): void {
            if ($failed) {
                $steps[] = ['step' => $step, 'ok' => null, 'code' => null, 'detail' => []];
                return;
            }
            try {
                $steps[] = ['step' => $step, 'ok' => true, 'code' => null, 'detail' => $fn()];
            } catch (SamlException | OidcException $e) {
                $failed = true;
                $steps[] = ['step' => $step, 'ok' => false, 'code' => explode('.', $e->reason, 2)[0], 'detail' => []];
            }
        };
        $run('idp_cert', function (): array {
            if (!$this->settings->complete()) {
                throw new SamlException('not_configured');
            }
            $certs = $this->idpCertInfo();
            foreach ($certs as $c) {
                if (!$c['valid']) {
                    throw new SamlException('cert_invalid');
                }
                if ($c['which'] === 'current' && $c['days_left'] < 0) {
                    throw new SamlException('cert_expired');
                }
            }
            return ['certs' => array_map(static fn (array $c): array => ['which' => $c['which'], 'days_left' => $c['days_left'], 'not_after' => $c['not_after']], $certs)];
        });
        $run('sso_url', function (): array {
            $res = ($this->http)($this->settings->idpSsoUrl)->get($this->settings->idpSsoUrl, ['Accept' => 'text/html']);
            if ($res['status'] >= 500) {
                throw new SamlException('http_status');
            }
            return ['status' => $res['status']];
        });
        $run('sp', function (): array {
            if ($this->settings->needsSpKey() && $this->secrets->state('saml.sp_private_key') !== 'set') {
                throw new SamlException($this->secrets->state('saml.sp_private_key') === 'unreadable' ? 'sp_key_unreadable' : 'sp_key_missing');
            }
            return ['entity_id' => $this->settings->spEntityId(), 'acs_url' => $this->settings->acsUrl(), 'signs_requests' => $this->settings->signRequests,
                'wants_encrypted' => $this->settings->wantEncrypted];
        });
        $run('sp_metadata', function (): array {
            return ['bytes' => strlen($this->spMetadata())];
        });
        $ok = !$failed;
        $this->logger->info('SAML 설정 확인', ['ok' => $ok, 'steps' => implode(',', array_map(static fn (array $s): string => $s['step'] . ':' . ($s['code'] ?? ($s['ok'] === null ? 'skipped' : 'ok')), $steps)), 'by' => $actor->label]);
        return ['steps' => $steps, 'ok' => $ok];
    }

    /**
     * 점검 항목(SAML이 켜졌을 때만): 마이그레이션 027 없음·설정 빠짐·SP 키 없음(필요할 때)·인증서 만료(실패)·만료 임박(경고, notification.cert_expiry_warn).
     *
     * @return list<array{section:string, id:string, status:string, value:string, note:?string, params:array<string,mixed>}>
     */
    public function checkItems(): array
    {
        if (!$this->settings->enabled) {
            return [];
        }
        $item = static fn (string $status, string $note, array $params = []): array => ['section' => 'auth', 'id' => 'saml', 'status' => $status, 'value' => '', 'note' => $note, 'params' => $params];
        if (!SamlSettings::libraryAvailable()) {
            return [$item('fail', 'saml_library_missing')];
        }
        if (!$this->schemaReady) {
            return [$item('warn', 'saml_migration_required')];
        }
        if (!$this->settings->complete()) {
            return [$item('warn', 'saml_not_configured')];
        }
        if ($this->settings->needsSpKey() && $this->secrets->state('saml.sp_private_key') !== 'set') {
            return [$item('warn', 'saml_sp_key_missing')];
        }
        $warnDays = intdiv($this->settings->certWarnSeconds, 86400);
        foreach ($this->idpCertInfo() as $c) {
            if (!$c['valid']) {
                return [$item('fail', 'saml_cert_invalid')];
            }
            if ($c['days_left'] < 0 && $c['which'] === 'current') {
                return [$item('fail', 'saml_cert_expired', ['date' => substr((string) $c['not_after'], 0, 10)])];
            }
            if ($c['days_left'] < $warnDays && $c['days_left'] >= 0) {
                return [$item('warn', 'saml_cert_expiring', ['days' => $c['days_left'], 'date' => substr((string) $c['not_after'], 0, 10)])];
            }
        }
        return [$item('ok', 'saml_ok')];
    }

    /** 워커(시간마다): IdP 인증서가 만료 임박이거나 만료면 관리자 알림 — 인증서마다 하루 한 건. 028 전이면 하지 않는다 @return int 만든 알림 수 */
    public function notifyCertExpiry(): int
    {
        if (!$this->settings->enabled || !$this->noticeReady) {
            return 0;
        }
        $warnDays = intdiv($this->settings->certWarnSeconds, 86400);
        $day = substr(SystemClock::iso($this->clock->now()), 0, 10);
        $n = 0;
        foreach ($this->settings->idpCerts() as $c) {
            $i = ConfigSchema::samlCertInfo($c['b64']);
            if ($i === null) {
                continue;
            }
            $days = (int) floor(($i['not_after'] - $this->clock->now()->getTimestamp()) / 86400);
            if ($days >= $warnDays) {
                continue;
            }
            $n += $this->notifications->emit('saml_cert_expiring', $this->notifications->activeAdmins(), [],
                ['which' => $c['which'], 'days' => $days, 'not_after' => SystemClock::iso(new \DateTimeImmutable('@' . $i['not_after']))],
                'saml_cert_expiring:' . substr($i['fingerprint'], 0, 16) . ':' . $day);
        }
        return $n;
    }

    /** 사용자당 분당 횟수(설정 확인·메타데이터 가져오기 합산, LDAP·OIDC 연결 시험과 같은 방식) */
    private function consumeQuota(int $userId): void
    {
        $dir = $this->workRoot . '/saml/test';
        Fs::ensureDir($dir);
        $lock = FileLock::acquire($dir . '/rate-' . $userId . '.lock');
        try {
            $file = $dir . '/rate-' . $userId . '.json';
            $now = $this->clock->now()->getTimestamp();
            $list = array_values(array_filter((array) json_decode((string) @file_get_contents($file), true), static fn ($t): bool => is_int($t) && $t > $now - 60));
            if (count($list) >= $this->settings->testPerMinute) {
                throw new ApiException(429, 'saml_test_rate_limited', ['n' => $this->settings->testPerMinute]);
            }
            $list[] = $now;
            Fs::atomicWrite($file, (string) json_encode($list));
        } finally {
            $lock->release();
        }
    }
}
