<?php

declare(strict_types=1);

namespace PJGuardian\Services\Repo;

use PJGuardian\Support\Db;

/**
 * 커밋 링크(P25, 명세 18.5 "GitHub 저장소는 커밋 링크"). 허용 호스트 목록에서 **"GitHub 호환(커밋 링크)"**으로 표시한 호스트(github.com은 자동)이고
 * 분석에 기록한 저장소 주소(analyses.source_url — 사용자 정보 없음)의 경로가 `/{owner}/{repo}(.git)`일 때만 만든다:
 * `{scheme}://{host}[:{port}]/{owner}/{repo}/commit/{hash}`. 해시는 `[0-9a-f]{7,64}`만. 그 밖(사내 일반 Git, 표시 없는 호스트)은 null(짧은 해시만 표시).
 * 018 전 DB(열·표 없음)에서는 링크 없이 동작한다.
 */
final class RepoLinks
{
    /** @var array<string, true>|null "host:port" 또는 "host:0" */
    private ?array $hosts = null;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * 분석 id → 링크(없으면 키 없음).
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public function forAnalyses(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        try {
            $rows = $this->db->all("SELECT id, source_url, source_revision FROM analyses WHERE id IN (SELECT value FROM json_each(?)) AND source_kind = 'git'",
                [json_encode(array_values($ids))]);
        } catch (\PDOException) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $link = $this->commitUrl($r['source_url'], $r['source_revision']);
            if ($link !== null) {
                $out[(int) $r['id']] = $link;
            }
        }
        return $out;
    }

    public function commitUrl(?string $url, ?string $revision): ?string
    {
        return self::build($url, $revision, fn (string $host, int $port): bool => $this->isGithub($host, $port));
    }

    /** @param callable(string, int): bool $isGithub */
    public static function build(?string $url, ?string $revision, callable $isGithub): ?string
    {
        if ($url === null || $revision === null || preg_match('/^[0-9a-f]{7,64}$/', $revision) !== 1) {
            return null;
        }
        $p = parse_url($url);
        if ($p === false || !in_array($p['scheme'] ?? '', ['http', 'https'], true) || !isset($p['host']) || isset($p['user']) || isset($p['query'])) {
            return null;
        }
        $host = HostPolicy::normalizeHost((string) $p['host']);
        $port = (int) ($p['port'] ?? ($p['scheme'] === 'https' ? 443 : 80));
        if ($host === null || preg_match('#^/([A-Za-z0-9._-]+)/([A-Za-z0-9._-]+?)(?:\.git)?/?$#', (string) ($p['path'] ?? ''), $m) !== 1 || !$isGithub($host, $port)) {
            return null;
        }
        $default = ($p['scheme'] === 'https' && $port === 443) || ($p['scheme'] === 'http' && $port === 80);
        $hostPart = str_contains($host, ':') ? '[' . $host . ']' : $host;
        return $p['scheme'] . '://' . $hostPart . ($default ? '' : ':' . $port) . '/' . $m[1] . '/' . $m[2] . '/commit/' . $revision;
    }

    private function isGithub(string $host, int $port): bool
    {
        if ($host === 'github.com') {
            return true;
        }
        if ($this->hosts === null) {
            $this->hosts = [];
            try {
                foreach ($this->db->all('SELECT host, port FROM repo_allowed_hosts WHERE github_links = 1') as $r) {
                    $this->hosts[$r['host'] . ':' . (int) $r['port']] = true;
                }
            } catch (\PDOException) {
                $this->hosts = [];
            }
        }
        return isset($this->hosts[$host . ':' . $port]) || isset($this->hosts[$host . ':0']);
    }
}
