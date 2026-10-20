<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Support\Db;
use PJGuardian\Support\Logger;
use PJGuardian\Support\SystemClock;
use PJGuardian\Support\WalCheckpoint;
use PJGuardian\Tests\TestCase;

/**
 * WAL 체크포인트 분리(P20S): 반영 연결의 자동 체크포인트 끄기, PASSIVE·TRUNCATE, 다른 연결에 막혔을 때 예외 없이 경고만.
 */
final class WalCheckpointTest extends TestCase
{
    private string $dir;
    private string $file;

    public function setUp(): void
    {
        $this->dir = $this->tempDir('walcp');
        $this->file = $this->dir . '/t.sqlite';
    }

    /** 다른 연결이 열린 채로 큰 쓰기(약 $mb MB)를 커밋 → WAL에 남는다 */
    private function bigWrite(Db $writer, int $mb): void
    {
        $writer->pdo()->exec('CREATE TABLE IF NOT EXISTS blob_t (id INTEGER PRIMARY KEY, b BLOB)');
        $writer->transaction(function (Db $db) use ($mb): void {
            $st = $db->pdo()->prepare('INSERT INTO blob_t (b) VALUES (?)');
            for ($i = 0; $i < $mb * 4; $i++) {
                $st->execute([random_bytes(256 * 1024)]);
            }
        });
    }

    private function wal(): int
    {
        clearstatcache();
        return is_file($this->file . '-wal') ? (int) filesize($this->file . '-wal') : 0;
    }

    public function testDisableAutoKeepsWalAndWorkerCheckpointsIt(): void
    {
        $holder = new Db($this->file); // 마지막 연결이 닫힐 때의 체크포인트를 막는다(웹·워커 연결이 열려 있는 상황)
        $writer = new Db($this->file);
        WalCheckpoint::disableAuto($writer);
        $this->bigWrite($writer, 8);
        $this->assertTrue($this->wal() > 7 * 1048576, '자동 체크포인트를 끄면 WAL이 그대로 남는다(' . $this->wal() . ')');
        $logger = new Logger($this->dir . '/logs', new SystemClock(), 'INFO');

        // 바쁘지 않을 때: PASSIVE는 모든 프레임을 DB로 옮긴다(파일 크기는 그대로)
        $cp = new WalCheckpoint($holder, $logger, 64 * 1048576);
        $r = $cp->run(true);
        $this->assertSame(['PASSIVE', true], [$r['mode'], $r['ok']]);
        $this->assertSame($r['log'], $r['checkpointed'], '모두 옮김');

        // 임계값을 넘고 한가하면 PASSIVE로 다 옮긴 뒤 TRUNCATE → WAL 파일이 0(P19C: 바로 TRUNCATE하던 P20S에서 순서 변경, 방식 이름 PASSIVE+TRUNCATE)
        $this->bigWrite($writer, 8);
        $r = (new WalCheckpoint($holder, $logger, 4 * 1048576))->run(true);
        $this->assertSame(['PASSIVE+TRUNCATE', true, 'done', 0], [$r['mode'], $r['ok'], $r['truncate'], $this->wal()]);
        $this->assertSame($r['log'], $r['checkpointed'], 'PASSIVE가 모두 옮긴 뒤에만 TRUNCATE');
        // 한가하지 않으면 임계값을 넘어도 PASSIVE만(다른 작업을 막지 않음)
        $this->bigWrite($writer, 8);
        $r = (new WalCheckpoint($holder, $logger, 4 * 1048576))->run(false);
        $this->assertSame(['PASSIVE', 'skipped_active'], [$r['mode'], $r['truncate']]);
        // 임계값 아래면 PASSIVE만(TRUNCATE 생략)
        $r = (new WalCheckpoint($holder, $logger, 1024 * 1048576))->run(true);
        $this->assertSame(['PASSIVE', null, true], [$r['mode'], $r['truncate'], $r['ok']]);
        // 기본 연결은 자동 체크포인트가 켜져 있다(웹·CLI 동작은 바뀌지 않음)
        $this->assertSame(1000, (int) $holder->pdo()->query('PRAGMA wal_autocheckpoint')->fetchColumn());
        $this->assertSame(0, (int) $writer->pdo()->query('PRAGMA wal_autocheckpoint')->fetchColumn());
    }

    public function testBlockedCheckpointWarnsWithoutThrowing(): void
    {
        $holder = new Db($this->file);
        $writer = new Db($this->file);
        WalCheckpoint::disableAuto($writer);
        $this->bigWrite($writer, 4);
        // 다른 연결이 읽기 트랜잭션을 열고 있으면 TRUNCATE는 끝낼 수 없다 → PASSIVE는 옮기고, TRUNCATE는 상한(여기 300ms) 안에서 포기 + 경고
        // (P19C: P20S에서는 TRUNCATE 하나가 busy_timeout만큼 기다리며 ok=false)
        $reader = new Db($this->file, 200);
        $reader->pdo()->exec('BEGIN');
        $reader->value('SELECT COUNT(*) FROM blob_t');
        $logs = $this->dir . '/logs';
        $cpDb = new Db($this->file, 5000);
        $r = (new WalCheckpoint($cpDb, new Logger($logs, new SystemClock(), 'INFO'), 1048576, 300, 5000))->run(true);
        $this->assertSame(['PASSIVE+TRUNCATE', true, 'skipped_busy'], [$r['mode'], $r['ok'], $r['truncate']]);
        $this->assertTrue($r['truncate_ms'] < 1500, '쓰기를 막는 대기는 상한 안(' . $r['truncate_ms'] . 'ms, 상한 300ms + 여유)');
        $this->assertSame(5000, (int) $cpDb->pdo()->query('PRAGMA busy_timeout')->fetchColumn(), '연결의 busy_timeout은 원래대로');
        $this->assertTrue($this->wal() > 0, 'WAL은 남아 있다(다음 기회에 다시)');
        $log = '';
        foreach (glob($logs . '/*') ?: [] as $f) {
            $log .= (string) file_get_contents($f);
        }
        $this->assertTrue(str_contains($log, 'WAL 체크포인트가 다른 연결에 막혀'), '경고 로그');
        $reader->pdo()->exec('COMMIT');
        $r = (new WalCheckpoint($holder, new Logger($logs, new SystemClock(), 'INFO'), 1048576))->run(true);
        $this->assertSame([true, 0], [$r['ok'], $this->wal()], '막힘이 풀리면 다음 시도에서 정리');
    }

    /** P19C: 분석(큰 쓰기)이 이어져도 분석 사이 체크포인트(PASSIVE 뒤 임계 초과 때만 TRUNCATE)로 WAL이 임계 아래로 수렴한다 */
    public function testWalConvergesAcrossConsecutiveAnalyses(): void
    {
        $holder = new Db($this->file);
        $writer = new Db($this->file);
        WalCheckpoint::disableAuto($writer);
        $logger = new Logger($this->dir . '/logs', new SystemClock(), 'INFO');
        $cp = new WalCheckpoint($holder, $logger, 6 * 1048576, 2000, 5000);
        $sizes = [];
        $modes = [];
        foreach ([8, 3, 9, 2, 7] as $mb) {
            $this->bigWrite($writer, $mb);
            $r = $cp->run(true);
            $sizes[] = $this->wal();
            $modes[] = $r['truncate'] ?? '-';
            $this->assertTrue($r['ok'] && $r['log'] === $r['checkpointed'], 'PASSIVE가 모두 옮김');
        }
        // 8MB·9MB·7MB 쓰기 뒤에는 WAL이 임계(6MB)를 넘어 TRUNCATE(0), 3MB·2MB 뒤에는 PASSIVE만(WAL 파일은 다시 쓰여 임계 아래)
        $this->assertSame(['done', '-', 'done', '-', 'done'], $modes, json_encode($sizes));
        foreach ($sizes as $i => $sz) {
            $this->assertTrue($sz <= 6 * 1048576, "분석 {$i} 뒤 WAL {$sz}B ≤ 임계");
        }
    }
}
