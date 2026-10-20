<?php

declare(strict_types=1);

namespace PJGuardian\Services\Tools;

use PJGuardian\Services\Process\ProcessOptions;
use PJGuardian\Services\Process\ProcessResult;
use PJGuardian\Services\Process\ProcessRunner;
use PJGuardian\Services\Process\ProcessTree;

/**
 * ESLint를 실행할 Node.js 런타임(명세 2, 12, 19.10). bin/ 밖의 서버 런타임이라 설정 `tools.node_path`로 지정하고,
 * 비우면 PATH에서 찾는다(셸 없이 직접 찾음). 최소 버전은 `tools.node_min_version`. 버전 문자열은 분석 기록에 남긴다.
 */
final class NodeRuntime
{
    private ?string $path = null;
    private ?string $version = null;
    private bool $resolved = false;
    private bool $timedOut = false;

    /** $timeoutSeconds: `node --version` 시간 제한(관리자 화면 "상태 갱신"은 짧게, P21C) */
    public function __construct(private readonly ?string $configuredPath, private readonly string $minVersion, private readonly int $timeoutSeconds = 30)
    {
    }

    /** 실행 파일의 실제 경로. 없으면 null */
    public function path(): ?string
    {
        if ($this->resolved) {
            return $this->path;
        }
        $this->resolved = true;
        if ($this->configuredPath !== null && $this->configuredPath !== '') {
            $real = realpath($this->configuredPath);
            $this->path = $real !== false && is_file($real) ? $real : null;
            return $this->path;
        }
        $names = ProcessTree::isWindows() ? ['node.exe'] : ['node'];
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir === '') {
                continue;
            }
            foreach ($names as $n) {
                $cand = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $n;
                if (is_file($cand)) {
                    $this->path = realpath($cand) ?: $cand;
                    return $this->path;
                }
            }
        }
        return null;
    }

    /** `node --version`(앞의 v 제거). 실행할 수 없으면 null */
    public function version(): ?string
    {
        if ($this->version !== null) {
            return $this->version;
        }
        $node = $this->path();
        if ($node === null) {
            return null;
        }
        // 고정 명령만: [설정·PATH에서 찾은 node, '--version'] — 사용자 입력이 들어가지 않는다
        $r = (new ProcessRunner([], [$node]))->run([$node, '--version'], new ProcessOptions(timeoutSeconds: $this->timeoutSeconds));
        $out = trim($r->stdout(1024));
        $this->timedOut = $r->reason === ProcessResult::TIMEOUT;
        $r->cleanup();
        if ($r->ok() && preg_match('/^v?(\d+\.\d+\.\d+)/', $out, $m) === 1) {
            $this->version = $m[1];
        }
        return $this->version;
    }

    public function minVersion(): string
    {
        return $this->minVersion;
    }

    /** @return array{ok:bool, path:?string, version:?string, reason:?string} 기동 시 검사 */
    public function check(): array
    {
        $path = $this->path();
        if ($path === null) {
            return ['ok' => false, 'path' => null, 'version' => null, 'reason' => 'node_not_found'];
        }
        $v = $this->version();
        if ($v === null) {
            return ['ok' => false, 'path' => $path, 'version' => null, 'reason' => $this->timedOut ? 'node_timeout' : 'node_not_runnable'];
        }
        if (version_compare($v, $this->minVersion, '<')) {
            return ['ok' => false, 'path' => $path, 'version' => $v, 'reason' => 'node_too_old'];
        }
        return ['ok' => true, 'path' => $path, 'version' => $v, 'reason' => null];
    }
}
