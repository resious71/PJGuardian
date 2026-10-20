<?php

declare(strict_types=1);

namespace PJGuardian\Services\Backup;

use PJGuardian\Support\Clock;
use PJGuardian\Support\Fs;
use PJGuardian\Support\SystemClock;

/**
 * 복원 상태 파일 `<work>/run/restore_state.json`(P27B). 복원 진행 독립 페이지(/restore-status, 로그인 없이·DB 없이)가 읽는다 —
 * 비밀·절대 경로를 넣지 않는다(수동 복구 안내는 프로젝트 기준 상대 경로).
 *
 *   {id, status: requested|running|succeeded|failed|rolled_back|rollback_failed, bundle(이름), by, started_at, finished_at,
 *    step, steps: [{name, status: running|done|skipped, at}], error: {code, params}|null, result: {...}, manual: [{move, to}]}
 */
final class RestoreStateFile
{
    public function __construct(private readonly string $file, private readonly Clock $clock)
    {
    }

    /** @return array<string, mixed> */
    public function read(): array
    {
        $d = json_decode((string) @file_get_contents($this->file), true);
        return is_array($d) ? $d : [];
    }

    /** 요청 접수(웹이 복원을 요청한 순간 — 워커가 집기 전) */
    public function requested(string $bundle, string $by): void
    {
        $this->write(['id' => bin2hex(random_bytes(6)), 'status' => 'requested', 'bundle' => $bundle, 'by' => $by, 'started_at' => $this->now(), 'finished_at' => null,
            'step' => null, 'steps' => [], 'error' => null, 'result' => [], 'manual' => []]);
    }

    public function start(string $bundle, string $by): void
    {
        $prev = $this->read();
        $this->write(['id' => ($prev['status'] ?? null) === 'requested' ? ($prev['id'] ?? bin2hex(random_bytes(6))) : bin2hex(random_bytes(6)), 'status' => 'running',
            'bundle' => $bundle, 'by' => $by, 'started_at' => $this->now(), 'finished_at' => null, 'step' => null, 'steps' => [], 'error' => null, 'result' => [], 'manual' => [],
            'pid' => (int) getmypid()]);
    }

    public function step(string $name): void
    {
        $d = $this->read();
        foreach ($d['steps'] as &$s) {
            if ($s['status'] === 'running') {
                $s['status'] = 'done';
            }
        }
        unset($s);
        $d['steps'][] = ['name' => $name, 'status' => 'running', 'at' => $this->now()];
        $d['step'] = $name;
        $this->write($d);
    }

    public function skip(string $name): void
    {
        $d = $this->read();
        foreach ($d['steps'] as &$s) {
            if ($s['name'] === $name) {
                $s['status'] = 'skipped';
            }
        }
        unset($s);
        $this->write($d);
    }

    /**
     * @param array{code: string, params: array<string, scalar>}|null $error
     * @param array<string, mixed> $result
     * @param list<array{move: string, to: string}> $manual
     */
    public function finish(string $status, ?array $error, array $result, array $manual = []): void
    {
        $d = $this->read();
        foreach ($d['steps'] as &$s) {
            if ($s['status'] === 'running') {
                $s['status'] = $status === 'succeeded' ? 'done' : 'failed';
            }
        }
        unset($s);
        $this->write(['status' => $status, 'finished_at' => $this->now(), 'error' => $error, 'result' => $result, 'manual' => $manual] + $d);
    }

    /** @param array<string, mixed> $d */
    private function write(array $d): void
    {
        Fs::ensureDir(dirname($this->file));
        Fs::atomicWrite($this->file, (string) json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function now(): string
    {
        return SystemClock::iso($this->clock->now());
    }
}
