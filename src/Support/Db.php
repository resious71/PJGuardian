<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * SQLite 접속 (명세 2, 19.7)
 *
 * - PRAGMA: journal_mode=WAL, synchronous=NORMAL, foreign_keys=ON, busy_timeout(설정 database.busy_timeout)
 * - PDO 예외 모드. 모든 쿼리는 Prepared Statement로 실행한다(문자열 연결 금지).
 * - transaction(): 쓰기는 BEGIN IMMEDIATE(쓰기 잠금을 처음에 잡아 교착·SQLITE_BUSY 갱신 실패를 줄임),
 *   읽기 전용은 BEGIN DEFERRED. 안쪽에서 다시 부르면 SAVEPOINT로 중첩한다.
 *   PDO::beginTransaction()은 쓰지 않는다(BEGIN IMMEDIATE를 지정할 수 없음).
 * - 접속할 때 SQLite 버전이 MIN_SQLITE_VERSION 미만이면 중단한다(스키마 CHECK와 쿼리가 JSON 함수를 씀, 명세 19.10).
 */
final class Db
{
    /** JSON 함수(json_valid, json_type, ->> 등)를 안정적으로 쓰는 최소 버전(명세 19.10) */
    public const MIN_SQLITE_VERSION = '3.38.0';

    private \PDO $pdo;
    private int $depth = 0;

    public function __construct(private readonly string $file, int $busyTimeoutMs = 5000)
    {
        if ($file !== ':memory:') {
            Fs::ensureDir(dirname($file));
        }
        $this->pdo = new \PDO('sqlite:' . $file, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        self::assertSqliteVersion($this->sqliteVersion());
        $this->pdo->exec('PRAGMA busy_timeout = ' . max(0, $busyTimeoutMs));
        if ($file !== ':memory:') {
            // P20: 다른 연결이 큰 쓰기를 커밋·체크포인트하는 순간에는 busy_timeout이 있어도 journal_mode 문이 바로 SQLITE_BUSY를 낼 수 있다
            // (동시 100명 대시보드 + 분석 반영 겹침 시험에서 연결 열기 1건 실패). 이미 WAL이면 바꾸지 않고, 잠겨 있으면 busy_timeout 안에서 다시 한다
            $mode = self::retryOnBusy(fn (): string => strtolower((string) $this->pdo->query('PRAGMA journal_mode')->fetchColumn()), $busyTimeoutMs);
            if ($mode !== 'wal') {
                self::retryOnBusy(fn (): mixed => $this->pdo->exec('PRAGMA journal_mode = WAL'), $busyTimeoutMs);
            }
        }
        $this->pdo->exec('PRAGMA synchronous = NORMAL');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
    }

    /**
     * "database is locked"(SQLITE_BUSY, 5)이면 $timeoutMs 안에서 짧게 쉬며 다시 한다. 다른 오류는 그대로 던진다.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function retryOnBusy(callable $fn, int $timeoutMs): mixed
    {
        $deadline = microtime(true) + max(0, $timeoutMs) / 1000;
        while (true) {
            try {
                return $fn();
            } catch (\PDOException $e) {
                $busy = (int) ($e->errorInfo[1] ?? 0) === 5 || str_contains($e->getMessage(), 'database is locked');
                if (!$busy || microtime(true) >= $deadline) {
                    throw $e;
                }
                usleep(20_000);
            }
        }
    }

    public static function fromConfig(Config $config, Paths $paths): self
    {
        return new self($paths->dbFile(), $config->int('database.busy_timeout') * 1000);
    }

    public function sqliteVersion(): string
    {
        return (string) $this->pdo->query('SELECT sqlite_version()')->fetchColumn();
    }

    /** 버전이 최소 버전 미만이면 원인과 필요한 버전을 담은 예외 */
    public static function assertSqliteVersion(string $version): void
    {
        if (!self::versionAtLeast($version, self::MIN_SQLITE_VERSION)) {
            throw new \RuntimeException(sprintf(
                'SQLite %s은(는) 지원하지 않습니다. PJGuardian은 JSON 함수를 쓰므로 SQLite %s 이상이 필요합니다'
                . '(PHP의 pdo_sqlite가 묶은 SQLite를 확인하세요: php tools/check_env.php).',
                $version,
                self::MIN_SQLITE_VERSION,
            ));
        }
    }

    /** 점으로 구분한 숫자 버전 비교(빠진 자리는 0). '3.9' < '3.38' */
    public static function versionAtLeast(string $version, string $minimum): bool
    {
        $parse = static function (string $v): array {
            $parts = array_map('intval', explode('.', $v));
            return array_pad(array_slice($parts, 0, 3), 3, 0);
        };
        return $parse($version) >= $parse($minimum);
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    public function file(): string
    {
        return $this->file;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<int|string, mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    /**
     * INSERT/UPDATE/DELETE. 영향받은 행 수를 돌려준다(낙관적 잠금: 0이면 충돌).
     *
     * @param array<int|string, mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * 트랜잭션 안에서 $fn을 실행한다. 예외가 나면 롤백하고 다시 던진다.
     *
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function transaction(callable $fn, bool $write = true): mixed
    {
        if ($this->depth > 0) {
            $sp = 'sp' . $this->depth;
            $this->pdo->exec('SAVEPOINT ' . $sp);
            $this->depth++;
            try {
                $result = $fn($this);
                $this->pdo->exec('RELEASE ' . $sp);
                return $result;
            } catch (\Throwable $e) {
                $this->pdo->exec('ROLLBACK TO ' . $sp);
                $this->pdo->exec('RELEASE ' . $sp);
                throw $e;
            } finally {
                $this->depth--;
            }
        }
        $this->pdo->exec($write ? 'BEGIN IMMEDIATE' : 'BEGIN DEFERRED');
        $this->depth = 1;
        try {
            $result = $fn($this);
            $this->pdo->exec('COMMIT');
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        } finally {
            $this->depth = 0;
        }
    }

    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }
}
