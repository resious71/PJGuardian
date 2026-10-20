<?php

declare(strict_types=1);

namespace PJGuardian\Services\Queue;

use PJGuardian\Support\Clock;
use PJGuardian\Support\FileLock;
use PJGuardian\Support\Fs;
use PJGuardian\Support\SystemClock;

/**
 * 워커의 파일 상태: <work_dir>/worker/ 아래(docs/architecture.md 22절).
 *
 * - worker.lock      단일 인스턴스 잠금(FileLock). 워커가 살아 있는 동안 잡고 있다. OS가 프로세스 종료 때 풀어 준다.
 * - heartbeat.json   생존 신호(worker.heartbeat_interval마다 원자적 교체). P30 시스템 모니터링·헬스체크가 읽는다.
 *     {"pid":123,"host":"PC","version":"1.0.0","started_at":"…Z","beat_at":"…Z","state":"running|stopping|stopped",
 *      "analyses":[{"id":5,"pid":456}],"uploads":[{"id":"<32자리>","pid":457,"stage":"assemble|extract"}]}
 *   analyses·uploads는 지금 감독 중인 자식 프로세스. 워커가 비정상 종료하면 복구가 이 PID들을 정리한다(분석은 DB의 process_group_id도 본다).
 * - stop.request     정상 종료 요청(tools/worker.php --stop, 관리자 화면의 정지·재시작 요청 P20A). 워커는 새 작업 선점을 멈추고
 *                    진행 중인 자식을 기다린다. 내용은 JSON {"kind":"stop|restart","requested_at":"…Z","by":사용자 id|null}
 *                    (옛 형식인 시각 한 줄도 stop으로 읽는다). 워커 시작 시각보다 오래된 요청은 무시하고 지운다(오래된 신호로 새 워커가 바로 멈추지 않게).
 *                    restart면 워커가 종료 코드 75로 끝나 서비스 관리자(Restart=on-failure 등)가 다시 띄운다.
 * - start.lock, start.json  관리자 화면 "시작" 버튼(WorkerControl)의 직렬화 잠금과 마지막 시작 요청 기록(P20A).
 * - node_check.request  관리자 화면 Node.js "상태 갱신" 요청(P21C). JSON {"requested_at":"…Z","by":사용자 id}. 워커가 루프에서 보고
 *                    설정을 다시 읽어 Node를 확인한 뒤 생존 신호의 node(checked_at = 확인 시각)를 바꾸고 지운다(워커 재시작 없이).
 */
final class WorkerState
{
    public function __construct(private readonly string $dir, private readonly Clock $clock)
    {
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function lockFile(): string
    {
        return $this->dir . '/worker.lock';
    }

    public function heartbeatFile(): string
    {
        return $this->dir . '/heartbeat.json';
    }

    public function stopFile(): string
    {
        return $this->dir . '/stop.request';
    }

    /** 단일 인스턴스 잠금. 다른 워커가 잡고 있으면 null */
    public function tryLock(): ?FileLock
    {
        return FileLock::tryAcquire($this->lockFile());
    }

    /**
     * @param list<array{id:int, pid:int}> $analyses
     * @param list<array{id:string, pid:int, stage:string}> $uploads
     * @param array<string, mixed> $extra 관리자 화면용 추가 정보(P20A: node 검사 결과, 마지막 복구, 관리 작업, 정지 종류). 서버 경로·비밀값 없음
     */
    public function beat(int $pid, string $startedAt, string $state, array $analyses, array $uploads, string $version, array $extra = []): void
    {
        $data = [
            'pid' => $pid, 'host' => (string) gethostname(), 'version' => $version, 'started_at' => $startedAt,
            'beat_at' => SystemClock::iso($this->clock->now()), 'state' => $state, 'analyses' => $analyses, 'uploads' => $uploads,
        ] + $extra;
        // Windows에서는 읽는 쪽이 파일을 연 순간이거나 백신이 새 임시 파일을 검사하는 중이면 교체(rename)가 실패할 수 있다(P14:
        // 파일 2만 개 해제 중 신호가 2초 넘게 끊긴 적이 있음). 잠깐 쉬고 몇 번 다시 하고, 그래도 안 되면 다음 주기에 쓴다
        for ($try = 0; $try < 5; $try++) {
            try {
                Fs::ensureDir($this->dir);
                Fs::atomicWrite($this->heartbeatFile(), (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return;
            } catch (\Throwable) {
                usleep(20_000);
            }
        }
    }

    /** @return array<string, mixed>|null */
    public function read(): ?array
    {
        // Windows에서 쓰는 쪽의 교체(rename)와 겹치면 순간적으로 열지 못할 수 있어 몇 번 다시 읽는다
        for ($try = 0; $try < 5; $try++) {
            $raw = @file_get_contents($this->heartbeatFile());
            if ($raw !== false) {
                $v = json_decode($raw, true);
                return is_array($v) ? $v : null;
            }
            if (!is_file($this->heartbeatFile())) {
                return null;
            }
            usleep(20_000);
        }
        return null;
    }

    /** 마지막 생존 신호 후 지난 초(시각이 초 단위라 최대 1초 크게 나온다). 신호 파일이 없거나 읽을 수 없으면 null */
    public function secondsSinceBeat(): ?int
    {
        $hb = $this->read();
        if ($hb === null || !is_string($hb['beat_at'] ?? null)) {
            return null;
        }
        $t = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $hb['beat_at'], new \DateTimeZone('UTC'));
        return $t === false ? null : max(0, $this->clock->now()->getTimestamp() - $t->getTimestamp());
    }

    /** @param string $kind stop | restart */
    public function requestStop(string $kind = 'stop', ?int $userId = null): void
    {
        if (!in_array($kind, ['stop', 'restart'], true)) {
            throw new \InvalidArgumentException('정지 요청 종류 오류');
        }
        Fs::ensureDir($this->dir);
        Fs::atomicWrite($this->stopFile(), (string) json_encode(['kind' => $kind, 'requested_at' => SystemClock::iso($this->clock->now()), 'by' => $userId]));
    }

    public function stopRequested(): bool
    {
        return is_file($this->stopFile());
    }

    /** @return array{kind:string, requested_at:?string, by:?int}|null 정지 요청 내용(없으면 null) */
    public function stopRequest(): ?array
    {
        $raw = @file_get_contents($this->stopFile());
        if ($raw === false) {
            return null;
        }
        $v = json_decode($raw, true);
        if (is_array($v)) {
            return [
                'kind' => ($v['kind'] ?? null) === 'restart' ? 'restart' : 'stop',
                'requested_at' => is_string($v['requested_at'] ?? null) ? $v['requested_at'] : null,
                'by' => is_int($v['by'] ?? null) ? $v['by'] : null,
            ];
        }
        $t = trim($raw);
        return ['kind' => 'stop', 'requested_at' => preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $t) === 1 ? $t : null, 'by' => null];
    }

    public function nodeCheckFile(): string
    {
        return $this->dir . '/node_check.request';
    }

    public function requestNodeCheck(?int $userId): string
    {
        Fs::ensureDir($this->dir);
        $at = SystemClock::iso($this->clock->now());
        Fs::atomicWrite($this->nodeCheckFile(), (string) json_encode(['requested_at' => $at, 'by' => $userId]));
        return $at;
    }

    /** @return array{requested_at:?string, by:?int}|null */
    public function nodeCheckRequest(): ?array
    {
        $raw = @file_get_contents($this->nodeCheckFile());
        if ($raw === false) {
            return null;
        }
        $v = json_decode($raw, true);
        return ['requested_at' => is_string($v['requested_at'] ?? null) ? $v['requested_at'] : null, 'by' => is_int($v['by'] ?? null) ? $v['by'] : null];
    }

    public function clearNodeCheck(): void
    {
        @unlink($this->nodeCheckFile());
    }

    public function startLockFile(): string
    {
        return $this->dir . '/start.lock';
    }

    public function startRecordFile(): string
    {
        return $this->dir . '/start.json';
    }

    public function clearStop(): void
    {
        @unlink($this->stopFile());
    }
}
