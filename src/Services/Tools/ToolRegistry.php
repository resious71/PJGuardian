<?php

declare(strict_types=1);

namespace PJGuardian\Services\Tools;

use PJGuardian\Services\Process\ProcessTree;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\FileLock;
use PJGuardian\Support\Fs;
use PJGuardian\Support\Logger;
use PJGuardian\Support\SystemClock;

/**
 * bin/ 도구 레지스트리(명세 12, 19.1, 19.10): 버전 선택, 체크섬, 스모크 테스트, 차단·고정. 상태는 tool_versions 표.
 *
 * 배치(OS = windows | linux)
 *   bin/ruff/<버전>/<OS>/ruff(.exe)           bin/eslint/<버전>/node_modules/eslint/bin/eslint.js(+ Node 런타임)
 *   bin/git/<버전>/<OS>/git(.exe)             bin/p4/<버전>/<OS>/p4(.exe)              각 버전 디렉토리에 SHA256SUMS
 *
 * 선택(select)
 *   1. 고정(pinned) 버전이 있으면 그것만 본다(자동 최신 선택 중단). 없으면 디스크의 버전을 semver 내림차순으로, 차단(blocked)은 건너뛴다.
 *   2. 각 후보: 처음 보거나(새 버전) 크기·수정 시각 지문이 마지막 검증 때와 다르면 **전체 체크섬 검증 → 스모크 테스트**를 한다.
 *      통과하면 active, 실패하면 invalid(WARN 로그)로 기록하고 다음(직전) 버전으로 넘어간다. 지문이 같으면 기록된 상태를 그대로 쓴다.
 *   3. 쓸 수 있는 버전이 없으면 null(분석 실패 tool_unavailable).
 *   P20A: 삭제 예정(removal_requested_at)이거나 지운(removed_at) 버전은 차단과 같이 건너뛴다(마이그레이션 009).
 *   검증 결과의 지문은 <work_dir>/tools/<도구>-<OS>-<버전>.json에 둔다(캐시. 지우면 다음 선택 때 다시 검증할 뿐).
 *   여러 분석 자식이 같은 도구를 동시에 검증하지 않게 <work_dir>/tools/registry-<도구>.lock으로 직렬화한다(도구끼리는 서로 기다리지 않음).
 */
final class ToolRegistry
{
    public const TOOLS = ['ruff', 'eslint', 'git', 'p4'];
    /** 도구별로 허용하는 주 버전(명세 19.10: ESLint 10.x). 없으면 제한 없음 */
    public const SUPPORTED_MAJOR = ['eslint' => 10];

    /** @param (\Closure(SelectedTool): array{ok:bool, message:string})|null $smoke 스모크 테스트(도구별). null이면 통과로 본다(git·p4는 P25가 채운다) */
    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly string $binDir,
        private readonly string $stateDir,
        private readonly NodeRuntime $node,
        private readonly ?\Closure $smoke = null,
    ) {
    }

    public static function os(): string
    {
        return ProcessTree::isWindows() ? 'windows' : 'linux';
    }

    private function now(): string
    {
        return SystemClock::iso($this->clock->now());
    }

    private static function assertTool(string $tool): void
    {
        if (!in_array($tool, self::TOOLS, true)) {
            throw new \InvalidArgumentException('알 수 없는 도구: ' . $tool);
        }
    }

    public static function validVersion(string $v): bool
    {
        return preg_match('/^\d{1,5}\.\d{1,5}\.\d{1,6}$/', $v) === 1;
    }

    public function versionDir(string $tool, string $version): string
    {
        self::assertTool($tool);
        if (!self::validVersion($version)) {
            throw new \InvalidArgumentException('버전 형식 오류');
        }
        return rtrim(str_replace('\\', '/', $this->binDir), '/') . '/' . $tool . '/' . $version;
    }

    /** 버전 디렉토리 기준 실행 파일 상대 경로 */
    public static function executableRel(string $tool, ?string $os = null): string
    {
        $os ??= self::os();
        $exe = $os === 'windows' ? '.exe' : '';
        return match ($tool) {
            'ruff' => "{$os}/ruff{$exe}",
            'eslint' => 'node_modules/eslint/bin/eslint.js',
            // P25(X09): MinGit 런처 windows/cmd/git.exe(자기 위치 기준으로 ucrt64/를 찾음), Linux는 소스 빌드(RUNTIME_PREFIX)의 linux/bin/git
            'git' => $os === 'windows' ? 'windows/cmd/git.exe' : 'linux/bin/git',
            'p4' => "{$os}/p4{$exe}",
            default => throw new \InvalidArgumentException('알 수 없는 도구: ' . $tool),
        };
    }

    /** @return list<string> 디스크에 있는(이 OS의 실행 파일이 있는) 버전, semver 내림차순 */
    public function versionsOnDisk(string $tool): array
    {
        self::assertTool($tool);
        $out = [];
        foreach (Fs::listDir(rtrim($this->binDir, '/\\') . '/' . $tool) as $name) {
            if (self::validVersion($name) && is_file($this->versionDir($tool, $name) . '/' . self::executableRel($tool))) {
                $out[] = $name;
            }
        }
        usort($out, static fn (string $a, string $b): int => version_compare($b, $a));
        return $out;
    }

    /** @return array<string, array<string, mixed>> 버전 → tool_versions 행(이 OS) */
    private function rows(string $tool): array
    {
        $out = [];
        foreach ($this->db->all('SELECT * FROM tool_versions WHERE tool = ? AND os = ?', [$tool, self::os()]) as $r) {
            $out[(string) $r['version']] = $r;
        }
        return $out;
    }

    /** 분석에 쓸 도구. 없으면 null(tool_unavailable) */
    public function select(string $tool): ?SelectedTool
    {
        self::assertTool($tool);
        Fs::ensureDir($this->stateDir);
        $lock = FileLock::acquire($this->stateDir . '/registry-' . $tool . '.lock');
        try {
            $rows = $this->rows($tool);
            $pinned = null;
            foreach ($rows as $v => $r) {
                if ($r['status'] === 'pinned') {
                    $pinned = (string) $v;
                }
            }
            $candidates = $pinned !== null ? [$pinned] : $this->versionsOnDisk($tool);
            $newest = $candidates[0] ?? null;
            foreach ($candidates as $v) {
                if (($rows[$v]['status'] ?? null) === 'blocked' || self::removalMarked($rows[$v] ?? null)) {
                    continue;
                }
                $r = $this->ensureVerified($tool, $v, false);
                if ($r['ok']) {
                    if ($v !== $newest) {
                        $this->logger->warn('최신 도구 버전을 쓸 수 없어 직전 유효 버전을 사용', ['tool' => $tool, 'newest' => $newest, 'using' => $v]);
                    }
                    return $this->selected($tool, $v);
                }
            }
            if ($pinned !== null) {
                $this->logger->error('고정한 도구 버전을 쓸 수 없음(검증 실패 또는 파일 없음)', ['tool' => $tool, 'version' => $pinned]);
            }
            return null;
        } finally {
            $lock->release();
        }
    }

    private function selected(string $tool, string $version): SelectedTool
    {
        $dir = $this->versionDir($tool, $version);
        $exe = (string) realpath($dir . '/' . self::executableRel($tool));
        if ($tool === 'eslint') {
            return new SelectedTool($tool, $version, self::os(), (string) realpath($dir), $exe, $this->node->path(), $this->node->version());
        }
        return new SelectedTool($tool, $version, self::os(), (string) realpath($dir), $exe);
    }

    /**
     * 버전 하나를 검증한다(필요할 때만, $force면 항상). 결과를 tool_versions와 지문 캐시에 기록한다.
     *
     * @return array{ok:bool, status:string, verified:bool, message:string}
     */
    public function ensureVerified(string $tool, string $version, bool $force): array
    {
        $dir = $this->versionDir($tool, $version);
        $row = $this->rows($tool)[$version] ?? null;
        if (!is_file($dir . '/' . self::executableRel($tool))) {
            return ['ok' => false, 'status' => $row['status'] ?? 'missing', 'verified' => false, 'message' => '실행 파일 없음'];
        }
        if (($row['status'] ?? null) === 'blocked') {
            return ['ok' => false, 'status' => 'blocked', 'verified' => false, 'message' => '차단된 버전'];
        }
        $major = self::SUPPORTED_MAJOR[$tool] ?? null;
        if ($major !== null && (int) explode('.', $version)[0] !== $major) {
            // 명세 19.10: ESLint는 10.x만(서버 생성 flat config). 다른 주 버전은 검증하지 않고 쓰지 않는다
            return ['ok' => false, 'status' => $row['status'] ?? 'unsupported', 'verified' => false, 'message' => "지원하지 않는 주 버전({$major}.x만 사용)"];
        }
        if ($tool === 'eslint') {
            $n = $this->node->check();
            if (!$n['ok']) {
                return ['ok' => false, 'status' => $row['status'] ?? 'new', 'verified' => false, 'message' => 'Node 런타임 문제: ' . $n['reason']];
            }
        }
        $fp = ToolManifest::fingerprint($dir);
        $cache = $this->loadCache($tool, $version);
        if (!$force && $row !== null && $fp !== null && ($cache['fingerprint'] ?? null) === $fp) {
            $ok = in_array($row['status'], ['active', 'pinned'], true);
            // 지문 대조 시각(관리자 화면의 "마지막 확인: 지문"). 캐시라 못 써도 그만
            $this->saveCache($tool, $version, ['checked_at' => $this->now(), 'method' => 'fingerprint'] + $cache);
            return ['ok' => $ok, 'status' => (string) $row['status'], 'verified' => false, 'message' => (string) ($row['smoke_message'] ?? '')];
        }

        // 전체 검증: 체크섬 → 스모크
        $now = $this->now();
        $sum = ToolManifest::verify($dir, [self::executableRel($tool)]);
        $smokeOk = null;
        $message = '';
        if (!$sum['ok']) {
            $message = '체크섬 실패: ' . implode('; ', array_slice($sum['problems'], 0, 5));
        } else {
            $s = $this->smoke !== null ? ($this->smoke)($this->selected($tool, $version)) : ['ok' => true, 'message' => '스모크 테스트 없음(이 도구는 이후 단계)'];
            $smokeOk = $s['ok'];
            $message = $s['message'];
        }
        $ok = $sum['ok'] && $smokeOk === true;
        $status = $ok ? (($row['status'] ?? null) === 'pinned' ? 'pinned' : 'active') : 'invalid';
        $this->db->execute(
            'INSERT INTO tool_versions (tool, version, os, status, checksum_ok, checksum_checked_at, smoke_ok, smoke_checked_at, smoke_message, detected_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            . ' ON CONFLICT (tool, version, os) DO UPDATE SET status = excluded.status, checksum_ok = excluded.checksum_ok,'
            . ' checksum_checked_at = excluded.checksum_checked_at, smoke_ok = excluded.smoke_ok, smoke_checked_at = excluded.smoke_checked_at,'
            . ' smoke_message = excluded.smoke_message, updated_at = excluded.updated_at',
            [$tool, $version, self::os(), $status, $sum['ok'] ? 1 : 0, $now, $smokeOk === null ? null : ($smokeOk ? 1 : 0),
                $smokeOk === null ? null : $now, mb_substr($message, 0, 1000), $now, $now],
        );
        $this->saveCache($tool, $version, ['fingerprint' => $fp, 'verified_at' => $now, 'checked_at' => $now, 'method' => 'full', 'ok' => $ok,
            'files' => $sum['files'], 'problems' => array_slice($sum['problems'], 0, 20)]);
        if ($ok) {
            $this->logger->info('도구 버전 검증 통과', ['tool' => $tool, 'version' => $version, 'files' => $sum['files'], 'new' => $row === null]);
        } else {
            $this->logger->warn('도구 버전 검증 실패(사용하지 않음)', ['tool' => $tool, 'version' => $version, 'reason' => $message]);
        }
        return ['ok' => $ok, 'status' => $status, 'verified' => true, 'message' => $message];
    }

    // ---- 차단·고정(관리자, tools/tools_ctl.php · P30 화면) ----

    public function block(string $tool, string $version): void
    {
        $this->versionDir($tool, $version);
        $now = $this->now();
        $this->db->execute(
            "INSERT INTO tool_versions (tool, version, os, status, detected_at, updated_at) VALUES (?, ?, ?, 'blocked', ?, ?)"
            . " ON CONFLICT (tool, version, os) DO UPDATE SET status = 'blocked', updated_at = excluded.updated_at",
            [$tool, $version, self::os(), $now, $now],
        );
        $this->logger->warn('도구 버전 차단', ['tool' => $tool, 'version' => $version]);
    }

    /** 차단 해제: 행을 지워 다음 선택 때 다시 검증하게 한다 */
    public function unblock(string $tool, string $version): void
    {
        $this->versionDir($tool, $version);
        $this->db->execute("DELETE FROM tool_versions WHERE tool = ? AND version = ? AND os = ? AND status = 'blocked'", [$tool, $version, self::os()]);
        @unlink($this->cacheFile($tool, $version));
        $this->logger->info('도구 버전 차단 해제', ['tool' => $tool, 'version' => $version]);
    }

    /**
     * 고정: 검증을 통과한 버전만. 같은 도구의 다른 고정은 풀린다.
     * $allowVerify=false(관리자 화면, P20A): 검증을 실행하지 않는다. 기록이 유효(active)하고 지문이 마지막 검증과 같을 때만 고정하고,
     * 아니면 ok=false, status='needs_verification'(먼저 재검증 요청). 웹 요청 안에서 체크섬·스모크를 돌리지 않기 위해서다.
     *
     * @return array{ok:bool, status:string, verified:bool, message:string}
     */
    public function pin(string $tool, string $version, bool $allowVerify = true): array
    {
        Fs::ensureDir($this->stateDir);
        $lock = FileLock::acquire($this->stateDir . '/registry-' . $tool . '.lock');
        try {
            $row = $this->rows($tool)[$version] ?? null;
            if (self::removalMarked($row)) {
                return ['ok' => false, 'status' => 'removal_requested', 'verified' => false, 'message' => '삭제 예정인 버전'];
            }
            if (!$allowVerify && !$this->isFresh($tool, $version)) {
                return ['ok' => false, 'status' => 'needs_verification', 'verified' => false, 'message' => '검증 기록이 없거나 파일이 바뀜'];
            }
            $r = $this->ensureVerified($tool, $version, false);
            if (!$r['ok']) {
                return $r;
            }
            $this->db->transaction(function (Db $db) use ($tool, $version): void {
                $now = $this->now();
                $db->execute("UPDATE tool_versions SET status = 'active', updated_at = ? WHERE tool = ? AND os = ? AND status = 'pinned'", [$now, $tool, self::os()]);
                $db->execute("UPDATE tool_versions SET status = 'pinned', updated_at = ? WHERE tool = ? AND version = ? AND os = ?", [$now, $tool, $version, self::os()]);
            });
            $this->logger->info('도구 버전 고정', ['tool' => $tool, 'version' => $version]);
            return ['ok' => true, 'status' => 'pinned', 'verified' => $r['verified'], 'message' => ''];
        } finally {
            $lock->release();
        }
    }

    public function unpin(string $tool): void
    {
        self::assertTool($tool);
        $this->db->execute("UPDATE tool_versions SET status = 'active', updated_at = ? WHERE tool = ? AND os = ? AND status = 'pinned'", [$this->now(), $tool, self::os()]);
        $this->logger->info('도구 버전 고정 해제', ['tool' => $tool]);
    }

    /** 검증 전용(재검증 요청, P20A): 선택과 같은 도구별 잠금 안에서 전체 체크섬 + 스모크를 다시 한다 */
    public function reverify(string $tool, string $version): array
    {
        $this->versionDir($tool, $version);
        Fs::ensureDir($this->stateDir);
        $lock = FileLock::acquire($this->stateDir . '/registry-' . $tool . '.lock');
        try {
            return $this->ensureVerified($tool, $version, true);
        } finally {
            $lock->release();
        }
    }

    // ---- 삭제 예정·삭제 기록(P20A, 마이그레이션 009) ----

    /** @param array<string, mixed>|null $row */
    public static function removalMarked(?array $row): bool
    {
        return $row !== null && (($row['removal_requested_at'] ?? null) !== null || ($row['removed_at'] ?? null) !== null);
    }

    /**
     * 삭제 예정 표시(웹). 선택에서 빠진다(차단과 같은 효과). 실제 삭제는 서버 CLI remove. 고정된 버전은 표시하지 않는다.
     *
     * @return bool false면 고정된 버전이라 거부
     */
    public function markRemoval(string $tool, string $version): bool
    {
        $this->versionDir($tool, $version);
        $now = $this->now();
        $row = $this->rows($tool)[$version] ?? null;
        if (($row['status'] ?? null) === 'pinned') {
            return false;
        }
        // 기록이 없던 버전(검증한 적 없음)은 검증하지 않은 채 표시만 한다(invalid = 쓸 수 없음, 메시지로 구분)
        $this->db->execute(
            "INSERT INTO tool_versions (tool, version, os, status, smoke_message, removal_requested_at, detected_at, updated_at) VALUES (?, ?, ?, 'invalid', ?, ?, ?, ?)"
            . ' ON CONFLICT (tool, version, os) DO UPDATE SET removal_requested_at = excluded.removal_requested_at, updated_at = excluded.updated_at',
            [$tool, $version, self::os(), '삭제 예정(검증하지 않음)', $now, $now, $now],
        );
        $this->logger->info('도구 버전 삭제 예정 표시', ['tool' => $tool, 'version' => $version]);
        return true;
    }

    public function unmarkRemoval(string $tool, string $version): void
    {
        $this->versionDir($tool, $version);
        // 표시 때문에 만든 행(검증 기록 없음)은 지워 다음 선택 때 새로 검증하게 한다
        $this->db->execute(
            "DELETE FROM tool_versions WHERE tool = ? AND version = ? AND os = ? AND removed_at IS NULL AND checksum_checked_at IS NULL AND status = 'invalid'",
            [$tool, $version, self::os()],
        );
        $this->db->execute('UPDATE tool_versions SET removal_requested_at = NULL, updated_at = ? WHERE tool = ? AND version = ? AND os = ? AND removed_at IS NULL',
            [$this->now(), $tool, $version, self::os()]);
        $this->logger->info('도구 버전 삭제 예정 해제', ['tool' => $tool, 'version' => $version]);
    }

    /** CLI remove가 폴더를 지운 뒤: 행은 이력으로 남긴다(분석 기록의 tool_version 문자열이 가리키는 버전) */
    public function markRemoved(string $tool, string $version): void
    {
        $now = $this->now();
        $this->db->execute(
            "INSERT INTO tool_versions (tool, version, os, status, removal_requested_at, removed_at, detected_at, updated_at) VALUES (?, ?, ?, 'invalid', ?, ?, ?, ?)"
            . ' ON CONFLICT (tool, version, os) DO UPDATE SET removal_requested_at = COALESCE(tool_versions.removal_requested_at, excluded.removed_at),'
            . ' removed_at = excluded.removed_at, updated_at = excluded.updated_at',
            [$tool, $version, self::os(), $now, $now, $now, $now],
        );
        @unlink($this->cacheFile($tool, $version));
    }

    /** CLI install이 실패해 bin/에서 다시 뺀 버전: 기록과 지문 캐시를 지운다(같은 버전을 다시 설치하면 새로 검증) */
    public function forget(string $tool, string $version): void
    {
        $this->versionDir($tool, $version);
        $this->db->execute('DELETE FROM tool_versions WHERE tool = ? AND version = ? AND os = ?', [$tool, $version, self::os()]);
        @unlink($this->cacheFile($tool, $version));
    }

    /** 도구별 잠금(설치·삭제가 선택·검증과 겹치지 않게, P20A CLI) */
    public function lock(string $tool): FileLock
    {
        self::assertTool($tool);
        Fs::ensureDir($this->stateDir);
        return FileLock::acquire($this->stateDir . '/registry-' . $tool . '.lock');
    }

    // ---- 조회(관리자 화면·CLI list). 검증을 실행하지 않는다(파일 크기·수정 시각만 읽음) ----

    /** 기록이 쓸 수 있는 상태(active·pinned)이고 지문이 마지막 검증 때와 같은지(= 다음 선택 때 다시 검증하지 않음) */
    public function isFresh(string $tool, string $version): bool
    {
        $row = $this->rows($tool)[$version] ?? null;
        if ($row === null || !in_array($row['status'], ['active', 'pinned'], true) || self::removalMarked($row)) {
            return false;
        }
        $fp = ToolManifest::fingerprint($this->versionDir($tool, $version));
        return $fp !== null && ($this->loadCache($tool, $version)['fingerprint'] ?? null) === $fp;
    }

    /** @return list<array<string, mixed>> 디스크 버전과 기록을 합친 목록(이 OS) */
    public function list(string $tool): array
    {
        $rows = $this->rows($tool);
        $onDisk = $this->versionsOnDisk($tool);
        $versions = array_unique([...$onDisk, ...array_map('strval', array_keys($rows))]);
        usort($versions, static fn ($a, $b): int => version_compare((string) $b, (string) $a));
        $major = self::SUPPORTED_MAJOR[$tool] ?? null;
        $out = [];
        foreach ($versions as $v) {
            $v = (string) $v;
            $r = $rows[$v] ?? null;
            $disk = in_array($v, $onDisk, true);
            $cache = $disk ? $this->loadCache($tool, $v) : [];
            $fp = $disk ? ToolManifest::fingerprint($this->versionDir($tool, $v)) : null;
            $out[] = [
                'tool' => $tool, 'version' => $v, 'os' => self::os(), 'on_disk' => $disk,
                'status' => $r['status'] ?? 'new', 'checksum_ok' => $r['checksum_ok'] ?? null, 'smoke_ok' => $r['smoke_ok'] ?? null,
                'smoke_message' => $r['smoke_message'] ?? null, 'updated_at' => $r['updated_at'] ?? null,
                'checksum_checked_at' => $r['checksum_checked_at'] ?? null, 'smoke_checked_at' => $r['smoke_checked_at'] ?? null,
                'removal_requested_at' => $r['removal_requested_at'] ?? null, 'removed_at' => $r['removed_at'] ?? null,
                // 마지막 확인(전체 검증 또는 지문 대조)과, 지금 파일이 마지막 전체 검증 때와 같은지
                'last_check_at' => $cache['checked_at'] ?? ($cache['verified_at'] ?? null),
                'last_check_method' => $cache['method'] ?? (isset($cache['verified_at']) ? 'full' : null),
                'verified_at' => $cache['verified_at'] ?? null, 'files' => $cache['files'] ?? null, 'problems' => $cache['problems'] ?? [],
                'fingerprint_changed' => $disk && $r !== null && $fp !== ($cache['fingerprint'] ?? null),
                'supported' => $major === null || (int) explode('.', $v)[0] === $major,
            ];
        }
        return $out;
    }

    /**
     * 지금 선택이 고를 버전의 **예상**(관리자 화면, 검증을 실행하지 않음). select()와 같은 순서로 보되,
     * 기록이 없거나 지문이 바뀐 후보를 만나면 "다음 선택 때 검증"(pending)으로 멈춘다.
     *
     * @return array{version:?string, reason:string, newest:?string, pending:?string}
     *   reason: pinned(고정) | latest(최신) | fallback(최신을 쓸 수 없어 직전 유효 버전) | pinned_unusable | none | pending
     */
    public function explain(string $tool): array
    {
        self::assertTool($tool);
        $rows = $this->rows($tool);
        $pinned = null;
        foreach ($rows as $v => $r) {
            if ($r['status'] === 'pinned') {
                $pinned = (string) $v;
            }
        }
        if ($pinned !== null) {
            return ['version' => $pinned, 'reason' => $this->isFresh($tool, $pinned) ? 'pinned' : 'pinned_unusable', 'newest' => $pinned, 'pending' => null];
        }
        $major = self::SUPPORTED_MAJOR[$tool] ?? null;
        $newest = null;
        foreach ($this->versionsOnDisk($tool) as $v) {
            $r = $rows[$v] ?? null;
            if (($r['status'] ?? null) === 'blocked' || self::removalMarked($r) || ($major !== null && (int) explode('.', $v)[0] !== $major)) {
                continue;
            }
            $newest ??= $v;
            $fp = ToolManifest::fingerprint($this->versionDir($tool, $v));
            if ($r === null || $fp === null || ($this->loadCache($tool, $v)['fingerprint'] ?? null) !== $fp) {
                return ['version' => null, 'reason' => 'pending', 'newest' => $newest, 'pending' => $v];
            }
            if (in_array($r['status'], ['active', 'pinned'], true)) {
                return ['version' => $v, 'reason' => $v === $newest ? 'latest' : 'fallback', 'newest' => $newest, 'pending' => null];
            }
        }
        return ['version' => null, 'reason' => 'none', 'newest' => $newest, 'pending' => null];
    }

    private function cacheFile(string $tool, string $version): string
    {
        return $this->stateDir . '/' . $tool . '-' . self::os() . '-' . $version . '.json';
    }

    /** @return array<string, mixed> */
    private function loadCache(string $tool, string $version): array
    {
        $raw = @file_get_contents($this->cacheFile($tool, $version));
        $v = $raw === false ? null : json_decode($raw, true);
        return is_array($v) ? $v : [];
    }

    /** @param array<string, mixed> $data */
    private function saveCache(string $tool, string $version, array $data): void
    {
        try {
            Fs::atomicWrite($this->cacheFile($tool, $version), (string) json_encode($data));
        } catch (\Throwable) {
            // 캐시를 못 쓰면 다음 선택 때 다시 검증할 뿐
        }
    }
}
