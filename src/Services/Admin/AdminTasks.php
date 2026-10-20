<?php

declare(strict_types=1);

namespace PJGuardian\Services\Admin;

use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\SystemClock;

/**
 * 관리 작업 요청 표 admin_tasks(P20A, 마이그레이션 009). 웹은 요청을 넣기만 하고, 워커가 하나씩 가져가(claimNext)
 * 자식 프로세스(tools/run_admin_task.php)로 실행한다. 웹 요청 안에서 오래 걸리는 일(도구 전체 체크섬 + 스모크,
 * 정리 작업, 디스크 사용량 계산)을 하지 않기 위해서다.
 *
 * - 종류: tool_verify(대상 '<도구>@<버전>'), cleanup_run, disk_scan, rules_load(규칙 데이터 적재, P22·015)(대상 '').
 * - 같은 종류·대상의 대기·실행 중 요청은 하나뿐이다(부분 고유 인덱스). 중복 요청은 기존 요청으로 합친다(merged=true).
 * - 워커가 비정상 종료해 running으로 남은 요청은 다음 워커 기동 때 failed(worker_crashed)로 바꾼다.
 */
final class AdminTasks
{
    public const KINDS = ['tool_verify', 'cleanup_run', 'disk_scan', 'rules_load'];

    public function __construct(private readonly Db $db, private readonly Clock $clock)
    {
    }

    private function now(): string
    {
        return SystemClock::iso($this->clock->now());
    }

    /**
     * 요청. 이미 대기·실행 중인 같은 요청이 있으면 그것을 돌려준다.
     *
     * @return array{task: array<string, mixed>, merged: bool}
     */
    public function request(string $kind, string $target, ?int $userId): array
    {
        if (!in_array($kind, self::KINDS, true) || strlen($target) > 64) {
            throw new \InvalidArgumentException('알 수 없는 관리 작업');
        }
        return $this->db->transaction(function (Db $db) use ($kind, $target, $userId): array {
            $existing = $this->active($kind, $target);
            if ($existing !== null) {
                return ['task' => $existing, 'merged' => true];
            }
            $db->execute('INSERT INTO admin_tasks (kind, target, status, requested_by, created_at) VALUES (?, ?, ?, ?, ?)',
                [$kind, $target, 'queued', $userId, $this->now()]);
            return ['task' => $this->get((int) $db->lastInsertId()), 'merged' => false];
        });
    }

    /** @return array<string, mixed>|null */
    public function active(string $kind, string $target): ?array
    {
        $row = $this->db->one("SELECT * FROM admin_tasks WHERE kind = ? AND target = ? AND status IN ('queued', 'running') ORDER BY id LIMIT 1", [$kind, $target]);
        return $row === null ? null : self::format($row);
    }

    /** @return array<string, mixed>|null 가장 최근 요청(상태 무관) */
    public function latest(string $kind, string $target = ''): ?array
    {
        $row = $this->db->one('SELECT * FROM admin_tasks WHERE kind = ? AND target = ? ORDER BY id DESC LIMIT 1', [$kind, $target]);
        return $row === null ? null : self::format($row);
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        $row = $this->db->one('SELECT * FROM admin_tasks WHERE id = ?', [$id]);
        if ($row === null) {
            throw new \RuntimeException('관리 작업 없음');
        }
        return self::format($row);
    }

    /** @return list<array<string, mixed>> 최근 요청(화면 표시) */
    public function recent(int $limit = 20): array
    {
        return array_map(self::format(...), $this->db->all('SELECT * FROM admin_tasks ORDER BY id DESC LIMIT ?', [$limit]));
    }

    /** 대기 중인 가장 오래된 요청을 running으로 가져온다(원자적). 없으면 null */
    public function claimNext(): ?array
    {
        return $this->db->transaction(function (Db $db): ?array {
            $id = $db->value("SELECT id FROM admin_tasks WHERE status = 'queued' ORDER BY id LIMIT 1");
            if ($id === null) {
                return null;
            }
            $n = $db->execute("UPDATE admin_tasks SET status = 'running', started_at = ? WHERE id = ? AND status = 'queued'", [$this->now(), (int) $id]);
            return $n === 1 ? $this->get((int) $id) : null;
        });
    }

    /** @param array<string, mixed> $result 서버 경로·비밀값 없는 요약 */
    public function finish(int $id, bool $ok, array $result): void
    {
        $this->db->execute("UPDATE admin_tasks SET status = ?, result = ?, finished_at = ? WHERE id = ? AND status = 'running'",
            [$ok ? 'succeeded' : 'failed', (string) json_encode((object) $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $this->now(), $id]);
    }

    /** 워커 기동 때: 실행 중으로 남은 요청(이전 워커가 죽음) → failed. 바꾼 수 */
    public function recoverRunning(): int
    {
        return $this->db->execute("UPDATE admin_tasks SET status = 'failed', result = ?, finished_at = ? WHERE status = 'running'",
            [(string) json_encode(['code' => 'worker_crashed']), $this->now()]);
    }

    public function queuedCount(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM admin_tasks WHERE status = 'queued'");
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function format(array $row): array
    {
        $result = is_string($row['result'] ?? null) ? json_decode($row['result'], true) : null;
        return [
            'id' => (int) $row['id'], 'kind' => (string) $row['kind'], 'target' => (string) $row['target'], 'status' => (string) $row['status'],
            'requested_by' => $row['requested_by'] === null ? null : (int) $row['requested_by'], 'result' => is_array($result) ? $result : null,
            'created_at' => $row['created_at'], 'started_at' => $row['started_at'], 'finished_at' => $row['finished_at'],
        ];
    }
}
