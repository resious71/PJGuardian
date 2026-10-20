<?php

declare(strict_types=1);

namespace PJGuardian\Services\Repo;

use PJGuardian\Http\ApiException;
use PJGuardian\Services\Actor;
use PJGuardian\Services\AuditLog;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\SystemClock;

/**
 * 저장소 허용 호스트 목록 관리(P25, admin 전용 — 라우트 미들웨어). 변경은 감사 로그 repo_host.add|update|delete.
 * 호스트는 HostPolicy::normalizeHost로 정규화해 저장한다(대소문자·후행 점·IPv6 대괄호가 달라도 한 항목).
 */
final class AllowedHosts
{
    public function __construct(
        private readonly Db $db,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
        // 인증서 만료 경고 시작(초, notification.cert_expiry_warn) — 표의 만료 임박 표시(P26B)
        private readonly int $certWarnSeconds = 30 * 86400,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return array_map($this->row(...), $this->db->all('SELECT * FROM repo_allowed_hosts ORDER BY host, port'));
    }

    /** @param array<string, mixed> $in */
    public function add(Actor $actor, ?int $userId, array $in): array
    {
        [$host, $port, $http, $gh, $note] = self::validate($in);
        $now = SystemClock::iso($this->clock->now());
        try {
            $this->db->execute('INSERT INTO repo_allowed_hosts (host, port, allow_http, github_links, note, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$host, $port, $http, $gh, $note, $userId, $now, $now]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                throw new ApiException(409, 'repo_host_exists');
            }
            throw $e;
        }
        $id = $this->db->lastInsertId();
        $this->audit->record($actor, 'repo_host.add', 'repo_host', $host . ($port > 0 ? ':' . $port : ''), ['allow_http' => $http === 1, 'github_links' => $gh === 1]);
        return $this->get($id);
    }

    /** @param array<string, mixed> $in */
    public function update(Actor $actor, int $id, array $in): array
    {
        $old = $this->get($id);
        [$host, $port, $http, $gh, $note] = self::validate($in + ['host' => $old['host'], 'port' => $old['port']]);
        if ($host !== $old['host'] || $port !== $old['port']) {
            throw new ApiException(400, 'invalid_repo_input', ['field' => 'host'], null, [], [], ['field' => 'host']); // 호스트·포트는 지우고 다시 추가
        }
        $this->db->execute('UPDATE repo_allowed_hosts SET allow_http = ?, github_links = ?, note = ?, updated_at = ? WHERE id = ?',
            [$http, $gh, $note, SystemClock::iso($this->clock->now()), $id]);
        $this->audit->record($actor, 'repo_host.update', 'repo_host', $host . ($port > 0 ? ':' . $port : ''), [
            'allow_http' => [$old['allow_http'], $http === 1], 'github_links' => [$old['github_links'], $gh === 1],
        ]);
        return $this->get($id);
    }

    public function delete(Actor $actor, int $id): void
    {
        $old = $this->get($id);
        $this->db->execute('DELETE FROM repo_allowed_hosts WHERE id = ?', [$id]);
        $this->audit->record($actor, 'repo_host.delete', 'repo_host', $old['host'] . ($old['port'] > 0 ? ':' . $old['port'] : ''), []);
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        $r = $this->db->one('SELECT * FROM repo_allowed_hosts WHERE id = ?', [$id]);
        return $r === null ? throw new ApiException(404, 'repo_host_not_found') : $this->row($r);
    }

    /** @return array{0:string, 1:int, 2:int, 3:int, 4:string} */
    private static function validate(array $in): array
    {
        $host = is_string($in['host'] ?? null) ? HostPolicy::normalizeHost((string) $in['host']) : null;
        if ($host === null) {
            throw new ApiException(400, 'invalid_repo_input', ['field' => 'host'], null, [], [], ['field' => 'host']);
        }
        $port = $in['port'] ?? 0;
        if ($port === null || $port === '') {
            $port = 0;
        }
        if (!is_int($port) || $port < 0 || $port > 65535) {
            throw new ApiException(400, 'invalid_repo_input', ['field' => 'port'], null, [], [], ['field' => 'port']);
        }
        foreach (['allow_http', 'github_links'] as $f) {
            if (array_key_exists($f, $in) && !is_bool($in[$f])) {
                throw new ApiException(400, 'invalid_repo_input', ['field' => $f], null, [], [], ['field' => $f]);
            }
        }
        $note = $in['note'] ?? '';
        if (!is_string($note) || mb_strlen($note) > 200 || preg_match('/[\x00-\x1F\x7F]/', $note) === 1) {
            throw new ApiException(400, 'invalid_repo_input', ['field' => 'note'], null, [], [], ['field' => 'note']);
        }
        return [$host, $port, ($in['allow_http'] ?? false) ? 1 : 0, ($in['github_links'] ?? false) ? 1 : 0, trim($note)];
    }

    /** @param array<string, mixed> $r */
    private function row(array $r): array
    {
        $notAfter = $r['cert_not_after'] ?? null;
        $left = $notAfter === null ? null : (int) strtotime((string) $notAfter) - $this->clock->now()->getTimestamp();
        return ['id' => (int) $r['id'], 'host' => (string) $r['host'], 'port' => (int) $r['port'], 'allow_http' => (int) $r['allow_http'] === 1,
            'github_links' => (int) $r['github_links'] === 1 || $r['host'] === 'github.com', 'note' => (string) $r['note'],
            'created_at' => (string) $r['created_at'], 'updated_at' => (string) $r['updated_at'],
            // P26B: 마지막 호스트 연결 테스트(020 전 DB면 모두 null)
            'last_test' => ($r['last_test_at'] ?? null) === null ? null : ['at' => $r['last_test_at'], 'code' => $r['last_test_code'], 'ms' => $r['last_test_ms'] === null ? null : (int) $r['last_test_ms']],
            'cert' => $notAfter === null ? null : ['not_after' => $notAfter, 'issuer' => $r['cert_issuer'] ?? null, 'days_left' => intdiv((int) $left, 86400),
                'expired' => $left < 0, 'expiring' => $left >= 0 && $left < $this->certWarnSeconds]];
    }
}
