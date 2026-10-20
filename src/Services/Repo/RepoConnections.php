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
 * 프로젝트 저장소 연결 저장·조회(P25, 명세 18.1·18.4). 행이 없으면 "업로드 전용".
 *
 * - 자격 증명은 SecretBox(AES-256-GCM)로 암호화한 본문만 저장. **어떤 응답에도 평문을 내리지 않는다**(secret_set: 설정됨/미설정).
 *   수정 때 secret을 비워 보내면 유지, 값을 보내면 교체, clear_secret=true면 삭제. 종류(Git↔Perforce)를 바꾸면 이전 자격 증명은 버린다.
 * - 키가 없으면 자격 증명 저장을 거부(503 repo_secret_key_missing) — 자동 생성하지 않는다.
 * - 저장 전 검증: 형식(RepoInput) + 허용 호스트 목록 등록 여부(HTTP는 allow_http). 이름 풀이·접속은 하지 않는다(연결 테스트·가져오기에서).
 * - 낙관적 개정 번호: 기존 연결을 고칠 때 revision이 다르면 409 repo_conflict(다른 관리자가 먼저 바꿈).
 * - 감사 로그: repo.save(바뀐 필드 이름과 "자격 증명 변경됨" 여부만 — 값·주소의 비밀은 기록하지 않음), repo.delete.
 */
final class RepoConnections
{
    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly AuditLog $audit,
        private readonly SecretBox $box,
        private readonly HostPolicy $hosts,
    ) {
    }

    /** @return array<string, mixed>|null DB 행 */
    public function row(int $projectId): ?array
    {
        return $this->db->one('SELECT * FROM project_repo_connections WHERE project_id = ?', [$projectId]);
    }

    /**
     * 화면·API용 상태. admin이면 설정값(비밀 제외), 아니면 상태·출처·마지막 커밋만(주소·사용자명·허용 목록 없음).
     *
     * @return array<string, mixed>
     */
    public function view(int $projectId, bool $admin): array
    {
        $r = $this->row($projectId);
        $last = $this->db->one(
            "SELECT id, source_kind, source_ref, source_revision, fetched_at, finished_at, run_number FROM analyses WHERE project_id = ? AND status = 'succeeded'"
            . " AND no_change = 0 AND source_kind IN ('git', 'perforce') ORDER BY id DESC LIMIT 1",
            [$projectId],
        );
        $base = [
            'configured' => $r !== null,
            'kind' => $r['kind'] ?? 'none',
            'status' => $r === null ? null : self::status($r),
            'last_fetch_at' => $r['last_fetch_at'] ?? null,
            // 마지막 분석 = 실제로 가져와 분석한 성공("변경 없음" 제외), 마지막 확인 = 원격 리비전만 본 시각(P27, 분석 여부와 무관)
            'last_analyzed' => $last === null ? null : ['analysis_id' => (int) $last['id'], 'kind' => (string) $last['source_kind'],
                'ref' => $last['source_ref'], 'revision' => $last['source_revision'], 'fetched_at' => $last['fetched_at'],
                'run' => $last['run_number'] === null ? null : (int) $last['run_number']],
            'last_check' => ($r['last_check_at'] ?? null) === null ? null
                : ['at' => $r['last_check_at'], 'result' => $r['last_check_result'], 'revision' => $r['last_check_revision']],
            // P26(주기 분석)이 채운다
            'next_run_at' => null,
            'label' => $r === null ? null : self::label($r),
        ];
        if (!$admin || $r === null) {
            return $base + ['key_available' => $admin ? $this->box->available() : null];
        }
        $isGit = $r['kind'] === 'git';
        return $base + [
            'location' => (string) $r['location'],
            'branch' => $r['branch'],
            'path' => $r['path'],
            'username' => $r['username'],
            'secret_set' => $r['secret_cipher'] !== null,
            'secret_kind' => $r['secret_kind'],
            'secret_readable' => $r['secret_key_id'] === null || $this->readable($r),
            'fingerprint' => $r['p4_fingerprint'],
            'fetch_timeout' => $r['fetch_timeout'] === null ? null : (int) $r['fetch_timeout'],
            'plaintext_warning' => $isGit ? str_starts_with((string) $r['location'], 'http://') : !str_starts_with((string) $r['location'], 'ssl:'),
            'host_allowed' => $this->hostListed($r),
            'test' => ['status' => $r['last_test_status'], 'at' => $r['last_test_at'], 'code' => $r['last_test_code']],
            'fetch' => ['status' => $r['last_fetch_status'], 'at' => $r['last_fetch_at'], 'code' => $r['last_fetch_code'], 'revision' => $r['last_fetch_revision']],
            'revision' => (int) $r['revision'],
            'key_available' => $this->box->available(),
            'updated_at' => $r['updated_at'],
        ];
    }

    /** 정상(ok) / 오류(error) / 미확인(unknown): 마지막 가져오기, 없으면 마지막 연결 테스트 */
    public static function status(array $r): string
    {
        $times = array_filter(['fetch' => $r['last_fetch_at'] ?? null, 'test' => $r['last_test_at'] ?? null]);
        if ($times === []) {
            return 'unknown';
        }
        arsort($times);
        $which = array_key_first($times);
        return (string) ($r["last_{$which}_status"] ?? 'unknown');
    }

    /** 일반 사용자에게도 보여 줄 출처 이름: Git = 호스트/경로(사용자 정보 없음), Perforce = depot 경로 */
    public static function label(array $r): string
    {
        if ($r['kind'] === 'perforce') {
            return (string) $r['path'];
        }
        $p = parse_url(RepoInput::redactUrl((string) $r['location']));
        return ($p['host'] ?? '') . ($p['path'] ?? '') . ($r['branch'] !== null ? ' @' . $r['branch'] : '') . ($r['path'] !== null ? ' : ' . $r['path'] : '');
    }

    /**
     * 저장(만들기·고치기). $in: kind, url(Git) | port(Perforce), branch, path, username, secret, secret_kind(Perforce), clear_secret,
     * fingerprint, fetch_timeout, revision(고칠 때 필수).
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed> 저장 뒤 view
     */
    public function save(Actor $actor, int $userId, int $projectId, array $in): array
    {
        $kind = $in['kind'] ?? null;
        if (!in_array($kind, ['git', 'perforce'], true)) {
            throw new ApiException(400, 'invalid_repo_input', ['field' => 'kind'], null, [], [], ['field' => 'kind']);
        }
        $v = $kind === 'git' ? $this->gitValues($in) : $this->p4Values($in);
        $secret = $in['secret'] ?? null;
        $clear = ($in['clear_secret'] ?? false) === true;
        $newSecret = $secret === null || $secret === '' ? null : RepoInput::secret($secret);
        $secretKind = $kind === 'git' ? 'token' : (($in['secret_kind'] ?? 'password') === 'ticket' ? 'ticket' : 'password');
        if ($newSecret !== null && !$this->box->available()) {
            throw new ApiException(503, 'repo_secret_key_missing');
        }
        $now = SystemClock::iso($this->clock->now());
        $changed = $this->db->transaction(function (Db $db) use ($actor, $userId, $projectId, $in, $kind, $v, $newSecret, $clear, $secretKind, $now): array {
            $old = $db->one('SELECT * FROM project_repo_connections WHERE project_id = ?', [$projectId]);
            if ($old !== null) {
                $rev = $in['revision'] ?? null;
                if (!is_int($rev) || $rev !== (int) $old['revision']) {
                    throw new ApiException(409, 'repo_conflict', [], null, [], [], ['revision' => (int) $old['revision']]);
                }
            }
            $secretCols = ['secret_kind' => $old['secret_kind'] ?? null, 'secret_cipher' => $old['secret_cipher'] ?? null,
                'secret_nonce' => $old['secret_nonce'] ?? null, 'secret_key_id' => $old['secret_key_id'] ?? null];
            $secretChange = null;
            if ($newSecret !== null) {
                $enc = $this->box->encrypt($newSecret, self::aad($projectId, $kind));
                $secretCols = ['secret_kind' => $secretKind, 'secret_cipher' => $enc['cipher'], 'secret_nonce' => $enc['nonce'], 'secret_key_id' => $enc['key_id']];
                $secretChange = $old !== null && $old['secret_cipher'] !== null ? 'replaced' : 'set';
            } elseif ($clear || ($old !== null && $old['kind'] !== $kind)) {
                $secretChange = ($old['secret_cipher'] ?? null) !== null ? 'cleared' : null;
                $secretCols = array_fill_keys(array_keys($secretCols), null);
            } elseif ($old !== null && $old['secret_cipher'] !== null && $kind === 'perforce' && $old['secret_kind'] !== $secretKind && isset($in['secret_kind'])) {
                throw new ApiException(400, 'invalid_repo_input', ['field' => 'secret'], null, [], [], ['field' => 'secret']); // 종류만 바꾸려면 값도 다시 입력
            } elseif ($old !== null && $old['secret_cipher'] !== null && $this->box->currentKeyId() !== null && $old['secret_key_id'] !== $this->box->currentKeyId()) {
                // 키 교체 뒤 저장: 이전 키(encryption_key_previous)로 풀어 현재 키로 다시 암호화(값은 그대로)
                try {
                    $enc = $this->box->encrypt($this->decrypt($old), self::aad($projectId, $kind));
                    $secretCols = ['secret_kind' => $old['secret_kind'], 'secret_cipher' => $enc['cipher'], 'secret_nonce' => $enc['nonce'], 'secret_key_id' => $enc['key_id']];
                    $secretChange = 'reencrypted';
                } catch (RepoException) {
                    // 풀 수 없으면(키 분실) 그대로 둔다 — 화면이 "다시 입력"을 안내한다
                }
            }
            $cols = ['kind' => $kind] + $v + $secretCols;
            $fields = [];
            foreach (['kind', 'location', 'branch', 'path', 'username', 'p4_fingerprint', 'fetch_timeout'] as $f) {
                if ($old === null || (string) ($old[$f] ?? '') !== (string) ($cols[$f] ?? '')) {
                    $fields[] = $f;
                }
            }
            if ($old === null) {
                $names = array_keys($cols);
                $db->execute('INSERT INTO project_repo_connections (project_id, ' . implode(', ', $names) . ', revision, created_by, created_at, updated_by, updated_at) VALUES (?, '
                    . implode(', ', array_fill(0, count($names), '?')) . ', 1, ?, ?, ?, ?)', [$projectId, ...array_values($cols), $userId, $now, $userId, $now]);
            } else {
                $set = implode(', ', array_map(static fn (string $n): string => "{$n} = ?", array_keys($cols)));
                // 주소·경로가 바뀌면 이전 테스트·가져오기 결과는 의미가 없다
                $reset = array_intersect($fields, ['kind', 'location', 'branch', 'path', 'username', 'p4_fingerprint']) !== [] || $secretChange !== null
                    ? ', last_test_status = NULL, last_test_at = NULL, last_test_code = NULL' : '';
                $n = $db->execute("UPDATE project_repo_connections SET {$set}{$reset}, revision = revision + 1, updated_by = ?, updated_at = ? WHERE project_id = ? AND revision = ?",
                    [...array_values($cols), $userId, $now, $projectId, (int) $old['revision']]);
                if ($n !== 1) {
                    throw new ApiException(409, 'repo_conflict');
                }
            }
            return ['fields' => $fields, 'secret' => $secretChange, 'created' => $old === null];
        }, write: true);
        if (array_intersect($changed['fields'], ['kind', 'location', 'branch', 'path']) !== []) {
            // P27: 연결이 바뀌면 이전 "마지막 확인"은 다른 출처의 것 — 지운다(021 전 DB에는 열이 없다)
            try {
                $this->db->execute('UPDATE project_repo_connections SET last_check_at = NULL, last_check_result = NULL, last_check_revision = NULL WHERE project_id = ?', [$projectId]);
            } catch (\PDOException $e) {
                if (!str_contains($e->getMessage(), 'no such column: last_check')) {
                    throw $e;
                }
            }
        }
        if ($changed['fields'] !== [] || $changed['secret'] !== null) {
            $this->audit->record($actor, 'repo.save', 'project', (string) $projectId, ['kind' => $kind, 'created' => $changed['created'],
                'fields' => $changed['fields'], 'auth_change' => $changed['secret'] ?? 'unchanged']); // 값이 아니라 변경 종류만(키 이름은 로그 마스킹 대상이 아니게)
        }
        return $this->view($projectId, true);
    }

    public function delete(Actor $actor, int $projectId): void
    {
        $n = $this->db->execute('DELETE FROM project_repo_connections WHERE project_id = ?', [$projectId]);
        if ($n === 1) {
            $this->audit->record($actor, 'repo.delete', 'project', (string) $projectId, []);
        }
    }

    /** 연결 테스트·가져오기 결과 기록 */
    public function mark(int $projectId, string $what, bool $ok, ?string $code, ?string $revision = null): void
    {
        $now = SystemClock::iso($this->clock->now());
        if ($what === 'test') {
            $this->db->execute('UPDATE project_repo_connections SET last_test_status = ?, last_test_at = ?, last_test_code = ? WHERE project_id = ?',
                [$ok ? 'ok' : 'error', $now, $code, $projectId]);
        } else {
            $this->db->execute('UPDATE project_repo_connections SET last_fetch_status = ?, last_fetch_at = ?, last_fetch_code = ?, last_fetch_revision = COALESCE(?, last_fetch_revision) WHERE project_id = ?',
                [$ok ? 'ok' : 'error', $now, $code, $revision, $projectId]);
        }
    }

    /**
     * 저장된 연결(또는 화면에서 저장 전 입력값)을 접속 대상으로. 비밀은 여기서만 복호화한다.
     *
     * @param array<string, mixed>|null $draft 저장 전 입력(연결 테스트). secret을 비우면 저장된 값을 쓴다
     */
    public function target(int $projectId, ?array $draft = null): GitTarget|P4Target
    {
        $r = $this->row($projectId);
        if ($draft !== null) {
            $kind = $draft['kind'] ?? null;
            if (!in_array($kind, ['git', 'perforce'], true)) {
                throw new ApiException(400, 'invalid_repo_input', ['field' => 'kind'], null, [], [], ['field' => 'kind']);
            }
            $v = $kind === 'git' ? $this->gitValues($draft) : $this->p4Values($draft);
            $secret = isset($draft['secret']) && $draft['secret'] !== '' ? RepoInput::secret($draft['secret']) : null;
            $sk = $kind === 'git' ? 'token' : (($draft['secret_kind'] ?? 'password') === 'ticket' ? 'ticket' : 'password');
            if ($secret === null && $r !== null && $r['kind'] === $kind && $r['secret_cipher'] !== null && ($draft['clear_secret'] ?? false) !== true) {
                $secret = $this->decrypt($r);
                $sk = (string) $r['secret_kind'];
            }
            $r = ['kind' => $kind] + $v + ['secret_kind' => $secret === null ? null : $sk];
        } elseif ($r === null) {
            throw new RepoException('repo_not_configured');
        } else {
            $secret = $r['secret_cipher'] !== null ? $this->decrypt($r) : null;
        }
        if ($r['kind'] === 'git') {
            $u = RepoInput::gitUrl($r['location']);
            return new GitTarget($u['url'], $u['scheme'], $u['host'], $u['port'], $r['branch'], $r['path'], $r['username'], $secret);
        }
        $p = RepoInput::p4Port($r['location']);
        return new P4Target($p['port'], $p['ssl'], $p['host'], $p['number'], (string) $r['path'], (string) $r['username'],
            $secret === null ? null : (string) $r['secret_kind'], $secret, $r['p4_fingerprint']);
    }

    /** @return array<string, mixed> */
    private function gitValues(array $in): array
    {
        $u = RepoInput::gitUrl($in['url'] ?? null);
        $this->requireListed($u['host'], $u['port'], $u['scheme']);
        return ['location' => $u['url'], 'branch' => RepoInput::branch($in['branch'] ?? null), 'path' => RepoInput::subPath($in['path'] ?? null),
            'username' => RepoInput::username($in['username'] ?? null, false), 'p4_fingerprint' => null, 'fetch_timeout' => RepoInput::fetchTimeout($in['fetch_timeout'] ?? null)];
    }

    /** @return array<string, mixed> */
    private function p4Values(array $in): array
    {
        $p = RepoInput::p4Port($in['port'] ?? null);
        $this->requireListed($p['host'], $p['number'], 'p4');
        return ['location' => $p['port'], 'branch' => null, 'path' => RepoInput::depotPath($in['path'] ?? null),
            'username' => RepoInput::username($in['username'] ?? null, true),
            'p4_fingerprint' => $p['ssl'] ? RepoInput::fingerprint($in['fingerprint'] ?? null) : null, 'fetch_timeout' => RepoInput::fetchTimeout($in['fetch_timeout'] ?? null)];
    }

    /** 허용 목록 등록 여부(이름 풀이 없이). 없으면 400 + 허용 방법 안내용 host */
    private function requireListed(string $host, int $port, string $scheme): void
    {
        $e = $this->hosts->entry($host, $port);
        if ($e === null || ($scheme === 'http' && (int) $e['allow_http'] !== 1)) {
            throw new ApiException(400, 'repo_host_not_listed', ['host' => $host], null, [], [], ['host' => $host, 'port' => $port, 'http' => $scheme === 'http']);
        }
    }

    private function hostListed(array $r): bool
    {
        try {
            if ($r['kind'] === 'git') {
                $u = RepoInput::gitUrl($r['location']);
                $this->requireListed($u['host'], $u['port'], $u['scheme']);
            } else {
                $p = RepoInput::p4Port($r['location']);
                $this->requireListed($p['host'], $p['number'], 'p4');
            }
            return true;
        } catch (ApiException) {
            return false;
        }
    }

    private function readable(array $r): bool
    {
        try {
            $this->decrypt($r);
            return true;
        } catch (RepoException) {
            return false;
        }
    }

    private function decrypt(array $r): string
    {
        return $this->box->decrypt((string) $r['secret_cipher'], (string) $r['secret_nonce'], (string) $r['secret_key_id'], self::aad((int) $r['project_id'], (string) $r['kind']));
    }

    /** 암호문을 다른 행·다른 종류에 옮겨 붙이면 풀리지 않게 하는 추가 인증 데이터 */
    private static function aad(int $projectId, string $kind): string
    {
        return 'pjg-repo:' . $projectId . ':' . $kind;
    }
}
