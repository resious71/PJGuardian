<?php

declare(strict_types=1);

namespace PJGuardian\Services\Tools;

use PJGuardian\Services\Admin\ToolStaging;
use PJGuardian\Services\Archive\SafeUnzip;
use PJGuardian\Services\Archive\UnzipException;
use PJGuardian\Services\Archive\UnzipLimits;
use PJGuardian\Services\Process\ProcessOptions;
use PJGuardian\Services\Process\ProcessRunner;
use PJGuardian\Support\Db;
use PJGuardian\Support\ExcludeMatcher;
use PJGuardian\Support\Fs;
use PJGuardian\Support\Logger;

/**
 * 도구 설치·삭제(P20A, 서버 CLI `tools/tools_ctl.php install|remove` 전용). **웹은 이 클래스를 쓰지 않는다**(웹 계정은 bin/에 쓰지 않는다).
 * CLI는 bin/ 쓰기 권한이 있는 서버 관리자 계정으로 실행한다(docs/tool_setup.md 5.1).
 *
 * install --staging=<id>
 *   1. 스테이징이 staged이고, 배포처 해시를 넣었다면 일치하고, package.zip의 SHA-256이 올릴 때 계산한 값과 같아야 한다(바뀐 파일 거부).
 *   2. SafeUnzip(크기 tools.package_max_size, 해제 한도 extract.*)으로 작업 폴더의 임시 폴더에 푼다. 경로 조작·절대 경로·심볼릭 링크·중첩 zip은
 *      SafeUnzip이 거부한다. 기본 제외 목록은 쓰지 않는다(ESLint 패키지는 node_modules 자체가 내용이다). 최상위 폴더는 벗기지 않는다.
 *   3. 레이아웃: 최상위에 `<도구>/<버전>/` 하나만(도구는 스테이징의 도구와 같고 버전은 semver), 그 안에 SHA256SUMS와 이 OS의 실행 파일
 *      (ruff: <OS>/ruff(.exe), eslint: node_modules/eslint/bin/eslint.js). 매니페스트 전체 체크섬을 **옮기기 전에** 확인한다.
 *   4. bin/<도구>/<버전>이 이미 있으면 거부(덮어쓰지 않음). 같은 볼륨이면 rename, 아니면 복사 후 임시 폴더 삭제.
 *   5. 레지스트리 잠금 안에서 전체 체크섬 + 스모크(ensureVerified force). 실패하면 bin/에서 다시 빼고 기록을 지운다(bin/에 흔적 없음).
 *   결과(ok, tool, version, status, code, message)를 스테이징 결과 파일과 DB에 쓴다. 관리자 화면이 읽어 보여 준다.
 *
 * remove <도구> <버전>
 *   거부: 고정된 버전(pinned), 실행 중인 같은 도구의 분석이 그 버전을 쓰는 중(기록된 버전, 또는 아직 기록 전이면 지금 선택될 버전),
 *   이 버전을 지우면 쓸 수 있는 버전이 하나도 남지 않음(마지막 유효 버전).
 *   삭제: bin/.trash/로 이름 바꾸기(즉시 선택 대상에서 빠짐) → 자식 프로세스(tools/tools_ctl.php purge-trash)가 지운다.
 *   tool_versions 행은 removed_at을 기록해 이력으로 남긴다.
 */
final class ToolInstaller
{
    public function __construct(
        private readonly Db $db,
        private readonly ToolRegistry $registry,
        private readonly ToolStaging $staging,
        private readonly UnzipLimits $limits,
        private readonly string $binDir,
        private readonly string $workDir,
        private readonly Logger $logger,
        /** @var (\Closure(): array{ok:bool, message:string})|null 휴지통 비우기(자식 프로세스). null이면 같은 프로세스에서 */
        private readonly ?\Closure $purgeTrash = null,
    ) {
    }

    /** @return array{ok:bool, code:string, tool:?string, version:?string, status:?string, message:string} */
    private static function result(bool $ok, string $code, ?string $tool = null, ?string $version = null, ?string $status = null, string $message = ''): array
    {
        return ['ok' => $ok, 'code' => $code, 'tool' => $tool, 'version' => $version, 'status' => $status, 'message' => $message];
    }

    public function binTrash(): string
    {
        return rtrim(str_replace('\\', '/', $this->binDir), '/') . '/.trash';
    }

    /** @return array{ok:bool, code:string, tool:?string, version:?string, status:?string, message:string} */
    public function install(string $stagingId): array
    {
        if (!ToolStaging::validId($stagingId)) {
            return self::result(false, 'staging_not_found', message: '스테이징 id 형식이 아닙니다');
        }
        $row = $this->db->one('SELECT * FROM tool_staging WHERE id = ?', [$stagingId]);
        if ($row === null) {
            return self::result(false, 'staging_not_found', message: '스테이징이 없습니다');
        }
        $tool = (string) $row['tool'];
        if ($row['status'] !== 'staged') {
            return self::result(false, 'staging_not_staged', $tool, message: '설치할 수 있는 상태가 아닙니다(현재 ' . $row['status'] . ')');
        }
        if ($row['expected_sha256'] !== null && !hash_equals((string) $row['expected_sha256'], (string) $row['sha256'])) {
            return self::result(false, 'hash_mismatch', $tool, message: '배포처 해시와 올린 파일의 해시가 다릅니다');
        }
        if (!$this->staging->claimForInstall($stagingId)) {
            return self::result(false, 'staging_not_staged', $tool, message: '다른 설치가 진행 중입니다');
        }
        $r = $this->doInstall($stagingId, $row);
        $this->staging->recordResult($stagingId, $r['ok'], $r);
        $this->logger->log($r['ok'] ? 'INFO' : 'WARN', $r['ok'] ? '도구 설치 완료' : '도구 설치 실패', ['staging_id' => $stagingId] + $r);
        return $r;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{ok:bool, code:string, tool:?string, version:?string, status:?string, message:string}
     */
    private function doInstall(string $id, array $row): array
    {
        $tool = (string) $row['tool'];
        $zip = $this->staging->packageFile($id);
        if (!is_file($zip) || !hash_equals((string) $row['sha256'], (string) hash_file('sha256', $zip))) {
            return self::result(false, 'package_changed', $tool, message: '올린 뒤 패키지 파일이 바뀌었거나 없습니다');
        }
        // 해제 경로를 짧게(Windows 259자): <work>/ti<8자리>
        $tmp = rtrim(str_replace('\\', '/', $this->workDir), '/') . '/ti' . bin2hex(random_bytes(4));
        try {
            try {
                (new SafeUnzip($this->limits, new ExcludeMatcher([], [])))->extract($zip, $tmp, false);
            } catch (UnzipException $e) {
                return self::result(false, 'unzip_' . $e->errorCode, $tool, message: '압축을 풀 수 없습니다: ' . $e->errorCode
                    . (isset($e->detail['reason']) ? ' (' . $e->detail['reason'] . ')' : ''));
            }
            // ---- 레이아웃 ----
            $top = Fs::listDir($tmp);
            if ($top !== [$tool]) {
                return self::result(false, 'bad_layout', $tool, message: "최상위에 '{$tool}/' 폴더 하나만 있어야 합니다(현재: " . implode(', ', array_slice($top, 0, 5)) . ')');
            }
            $versions = Fs::listDir("{$tmp}/{$tool}");
            if (count($versions) !== 1 || !is_dir("{$tmp}/{$tool}/{$versions[0]}")) {
                return self::result(false, 'bad_layout', $tool, message: "'{$tool}/' 아래에 버전 폴더 하나만 있어야 합니다");
            }
            $version = $versions[0];
            if (!ToolRegistry::validVersion($version)) {
                return self::result(false, 'bad_version', $tool, message: '버전 폴더 이름이 semver(예: 0.16.10)가 아닙니다');
            }
            $src = "{$tmp}/{$tool}/{$version}";
            $exeRel = ToolRegistry::executableRel($tool);
            if (!is_file("{$src}/" . ToolManifest::FILE)) {
                return self::result(false, 'bad_layout', $tool, $version, message: 'SHA256SUMS가 없습니다');
            }
            if (!is_file("{$src}/{$exeRel}")) {
                return self::result(false, 'bad_layout', $tool, $version, message: "이 OS의 실행 파일이 없습니다({$exeRel})");
            }
            $sum = ToolManifest::verify($src, [$exeRel]);
            if (!$sum['ok']) {
                return self::result(false, 'checksum_failed', $tool, $version, 'invalid', '체크섬 실패: ' . implode('; ', array_slice($sum['problems'], 0, 5)));
            }
            // ---- 옮기기(덮어쓰지 않음) + 검증 ----
            $dest = $this->registry->versionDir($tool, $version);
            $lock = $this->registry->lock($tool);
            try {
                if (file_exists($dest)) {
                    return self::result(false, 'version_exists', $tool, $version, message: '이미 설치된 버전입니다(덮어쓰지 않음)');
                }
                Fs::ensureDir(dirname($dest));
                if (!@rename($src, $dest) && !$this->copyTree($src, $dest)) {
                    Fs::removeTree($dest);
                    return self::result(false, 'io_error', $tool, $version, message: 'bin/으로 옮길 수 없습니다(권한 확인)');
                }
                $this->registry->forget($tool, $version); // 지웠다가 다시 설치한 버전이면 이전 기록(removed_at)을 지운다
                $v = $this->registry->ensureVerified($tool, $version, true);
                if (!$v['ok']) {
                    Fs::removeTree($dest);
                    $this->registry->forget($tool, $version);
                    return self::result(false, 'verify_failed', $tool, $version, $v['status'], '검증 실패(bin/에서 다시 뺌): ' . $v['message']);
                }
                return self::result(true, 'installed', $tool, $version, $v['status'], $v['message']);
            } finally {
                $lock->release();
            }
        } finally {
            Fs::removeTree($tmp);
        }
    }

    private function copyTree(string $src, string $dst): bool
    {
        if (!@mkdir($dst, 0755)) {
            return false;
        }
        foreach (Fs::listDir($src) as $name) {
            $a = "{$src}/{$name}";
            $b = "{$dst}/{$name}";
            if (is_dir($a) && !is_link($a)) {
                if (!$this->copyTree($a, $b)) {
                    return false;
                }
            } elseif (!@copy($a, $b)) {
                return false;
            } else {
                @touch($b, (int) filemtime($a));
            }
        }
        return true;
    }

    /** @return array{ok:bool, code:string, tool:?string, version:?string, status:?string, message:string} */
    public function remove(string $tool, string $version): array
    {
        if (!in_array($tool, ToolRegistry::TOOLS, true) || !ToolRegistry::validVersion($version)) {
            return self::result(false, 'usage', message: '도구 또는 버전 형식 오류');
        }
        $dir = $this->registry->versionDir($tool, $version);
        $lock = $this->registry->lock($tool);
        try {
            if (!is_dir($dir)) {
                return self::result(false, 'not_installed', $tool, $version, message: '설치되어 있지 않은 버전입니다');
            }
            $rows = [];
            foreach ($this->registry->list($tool) as $r) {
                $rows[$r['version']] = $r;
            }
            $row = $rows[$version] ?? null;
            if (($row['status'] ?? null) === 'pinned') {
                return self::result(false, 'pinned', $tool, $version, 'pinned', '고정된 버전은 지울 수 없습니다(먼저 고정 해제)');
            }
            if ($this->inUse($tool, $version)) {
                return self::result(false, 'in_use', $tool, $version, $row['status'] ?? null, '실행 중인 분석이 이 버전을 쓰고 있습니다(끝난 뒤 다시)');
            }
            if ($this->registry->isFresh($tool, $version)) {
                $others = 0;
                foreach ($rows as $v => $r) {
                    if ((string) $v !== $version && $r['on_disk'] && $r['supported'] && $r['status'] !== 'blocked' && $r['removal_requested_at'] === null
                        && $this->registry->isFresh($tool, (string) $v)) {
                        $others++;
                    }
                }
                if ($others === 0) {
                    return self::result(false, 'last_valid', $tool, $version, $row['status'] ?? null, '쓸 수 있는 마지막 버전이라 지울 수 없습니다(새 버전을 먼저 설치)');
                }
            }
            Fs::ensureDir($this->binTrash());
            $target = $this->binTrash() . '/' . $tool . '-' . $version . '-' . bin2hex(random_bytes(4));
            if (!@rename($dir, $target)) {
                return self::result(false, 'io_error', $tool, $version, message: '폴더를 옮길 수 없습니다(사용 중이거나 권한 없음)');
            }
            $this->registry->markRemoved($tool, $version);
        } finally {
            $lock->release();
        }
        $purge = $this->purgeTrash !== null ? ($this->purgeTrash)() : ['ok' => $this->emptyBinTrash() >= 0, 'message' => ''];
        $this->logger->info('도구 버전 삭제', ['tool' => $tool, 'version' => $version, 'trash_purged' => $purge['ok']]);
        return self::result(true, 'removed', $tool, $version, 'removed', $purge['ok'] ? '' : '휴지통 비우기는 다음에 다시 합니다: ' . $purge['message']);
    }

    /** 실행 중인 같은 도구의 분석이 이 버전을 쓰는가(기록된 버전, 또는 아직 기록 전이면 지금 선택될 버전) */
    private function inUse(string $tool, string $version): bool
    {
        $running = $this->db->all("SELECT tool_version FROM analyses WHERE status = 'running' AND tool = ?", [$tool]);
        if ($running === []) {
            return false;
        }
        $current = $this->registry->explain($tool)['version'];
        foreach ($running as $r) {
            $label = $r['tool_version'];
            if ($label === null || $label === '') {
                if ($current === $version || $current === null) {
                    return true; // 어느 버전을 쓰는지 아직 모르면 안전하게 거부
                }
            } elseif ($label === $version || str_starts_with((string) $label, $version . ' ')) {
                return true;
            }
        }
        return false;
    }

    /** bin/.trash 비우기(자식 프로세스 tools_ctl.php purge-trash가 부른다). 지운 항목 수 */
    public function emptyBinTrash(): int
    {
        $n = 0;
        foreach (Fs::listDir($this->binTrash()) as $name) {
            if (Fs::removeTree($this->binTrash() . '/' . $name)) {
                $n++;
            }
        }
        return $n;
    }

    /** CLI가 자식 프로세스로 휴지통을 비우는 함수(셸 없음, PHP_BINARY만) */
    public static function childPurge(string $root, ?string $configPath, int $timeoutSeconds): \Closure
    {
        return static function () use ($root, $configPath, $timeoutSeconds): array {
            $cmd = [PHP_BINARY, $root . '/tools/tools_ctl.php', ...($configPath !== null ? ['--config=' . $configPath] : []), 'purge-trash'];
            $r = (new ProcessRunner([], [PHP_BINARY]))->run($cmd, new ProcessOptions(cwd: $root, timeoutSeconds: (float) $timeoutSeconds));
            $out = ['ok' => $r->ok(), 'message' => $r->ok() ? '' : $r->reason . ' exit=' . ($r->exitCode ?? '-')];
            $r->cleanup();
            return $out;
        };
    }
}
