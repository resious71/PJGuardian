<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * 마이그레이션 실행기 (docs/architecture.md 13절, docs/db_schema.md)
 *
 * - migrations/NNN_이름.sql(3자리 순번)을 순번대로 적용하고 schema_migrations에 기록한다.
 * - 파일 하나 = 트랜잭션 하나(BEGIN IMMEDIATE). 실패하면 그 파일을 롤백하고 이후 파일은 적용하지 않는다.
 * - 이미 적용한 버전은 건너뛴다(여러 번 실행해도 안전).
 * - 적용한 파일의 SHA-256을 기록해, 적용 뒤 파일이 바뀌면 거부한다(적용한 마이그레이션은 고치지 않고 새 파일을 추가한다).
 * - 적용할 파일이 있으면 적용 직전에 백업 훅을 한 번 부른다(백업 구현은 P30. 훅이 예외를 던지면 적용하지 않는다).
 * - 마이그레이션 파일에는 PRAGMA를 쓰지 않는다(트랜잭션 안에서는 효과가 없거나 오류가 난다).
 * - 첫 줄이 `-- pjg:foreign-keys-off`인 파일(부모 테이블 재구성, P28B 026 users)은 SQLite 공식 재구성 절차로 적용한다:
 *   트랜잭션 밖에서 외래 키를 끄고(legacy_alter_table도 켬 — 옛 표를 참조하는 다른 표의 트리거가 이름 바꾸기 검사에 걸리지 않게) → 한 트랜잭션으로 실행 → 커밋 전에 PRAGMA foreign_key_check가 0건인지 확인(아니면 롤백) → 외래 키를 다시 켠다.
 *   외래 키를 켠 채 부모 테이블을 DROP하면 암묵적 DELETE가 ON DELETE CASCADE로 자식 행을 지우기 때문이다.
 */
final class Migrator
{
    public const FILE_PATTERN = '/^(\d{3})_([a-z0-9_]+)\.sql$/';
    /** 첫 줄 표지: 외래 키를 끄고 적용(부모 테이블 재구성) */
    public const FK_OFF_MARKER = '-- pjg:foreign-keys-off';

    /** @var callable(list<array{version:string,name:string,file:string}>, Db): void|null */
    private $backupHook;

    /**
     * @param callable(list<array{version:string,name:string,file:string}>, Db): void|null $backupHook
     */
    public function __construct(
        private readonly Db $db,
        private readonly string $dir,
        private readonly Clock $clock,
        ?callable $backupHook = null,
    ) {
        $this->backupHook = $backupHook;
    }

    /**
     * 기본 백업 훅: 아직 백업 기능이 없으므로(P30) 무엇을 백업해야 하는지만 알린다.
     * P30에서 SQLite online backup(VACUUM INTO)으로 바꾼다(명세 14, 19.4).
     */
    public static function defaultBackupHook(?Logger $logger = null): callable
    {
        return static function (array $pending, Db $db) use ($logger): void {
            $logger?->info('마이그레이션 전 백업 훅(백업 구현은 P30)', [
                'db' => $db->file(),
                'pending' => array_column($pending, 'version'),
            ]);
        };
    }

    /**
     * 마이그레이션 파일 목록(순번 순).
     *
     * @return list<array{version:string,name:string,file:string}>
     */
    public function files(): array
    {
        $out = [];
        foreach (Fs::listDir($this->dir) as $name) {
            if (preg_match(self::FILE_PATTERN, $name, $m) !== 1) {
                if (str_ends_with($name, '.sql')) {
                    throw new MigrationException("마이그레이션 파일 이름 규칙(NNN_이름.sql)에 맞지 않음: {$name}");
                }
                continue;
            }
            if (isset($out[$m[1]])) {
                throw new MigrationException("같은 순번의 마이그레이션이 둘 있음: {$m[1]}", $m[1]);
            }
            $out[$m[1]] = ['version' => $m[1], 'name' => $m[2], 'file' => $this->dir . '/' . $name];
        }
        ksort($out, SORT_STRING);
        return array_values($out);
    }

    private function ensureTable(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations ('
            . ' version TEXT PRIMARY KEY CHECK (length(version) = 3),'
            . ' name TEXT NOT NULL,'
            . ' checksum TEXT NOT NULL CHECK (length(checksum) = 64),'
            . ' applied_at TEXT NOT NULL'
            . ')'
        );
    }

    /** @return array<string, array{version:string,name:string,checksum:string,applied_at:string}> */
    private function applied(): array
    {
        $this->ensureTable();
        $rows = [];
        foreach ($this->db->all('SELECT version, name, checksum, applied_at FROM schema_migrations ORDER BY version') as $r) {
            $rows[(string) $r['version']] = $r;
        }
        return $rows;
    }

    /**
     * 적용 현황.
     *
     * @return list<array{version:string,name:string,state:string,applied_at:?string}>
     *   state: applied | pending | modified(적용 뒤 파일이 바뀜) | missing(기록은 있는데 파일이 없음)
     */
    public function status(): array
    {
        $applied = $this->applied();
        $out = [];
        $seen = [];
        foreach ($this->files() as $f) {
            $seen[$f['version']] = true;
            $a = $applied[$f['version']] ?? null;
            $state = $a === null ? 'pending' : (hash_file('sha256', $f['file']) === $a['checksum'] ? 'applied' : 'modified');
            $out[] = ['version' => $f['version'], 'name' => $f['name'], 'state' => $state, 'applied_at' => $a['applied_at'] ?? null];
        }
        foreach ($applied as $v => $a) {
            if (!isset($seen[$v])) {
                $out[] = ['version' => $v, 'name' => $a['name'], 'state' => 'missing', 'applied_at' => $a['applied_at']];
            }
        }
        usort($out, static fn (array $x, array $y): int => strcmp($x['version'], $y['version']));
        return $out;
    }

    /**
     * 적용할 마이그레이션을 모두 적용한다. 적용한 버전 목록을 돌려준다.
     *
     * @return list<string>
     */
    public function migrate(): array
    {
        $status = $this->status();
        $broken = array_filter($status, static fn (array $s): bool => in_array($s['state'], ['modified', 'missing'], true));
        if ($broken !== []) {
            $desc = implode(', ', array_map(static fn (array $s): string => "{$s['version']}({$s['state']})", $broken));
            throw new MigrationException("적용된 마이그레이션이 바뀌었거나 없어짐: {$desc}. 적용한 파일은 고치지 말고 새 마이그레이션을 추가한다");
        }
        $pendingVersions = array_column(array_filter($status, static fn (array $s): bool => $s['state'] === 'pending'), 'version');
        $pending = array_values(array_filter($this->files(), static fn (array $f): bool => in_array($f['version'], $pendingVersions, true)));
        if ($pending === []) {
            return [];
        }
        if ($this->backupHook !== null) {
            ($this->backupHook)($pending, $this->db);
        }
        $done = [];
        foreach ($pending as $f) {
            $sql = (string) file_get_contents($f['file']);
            if (preg_match('/^\s*PRAGMA\b/im', $sql) === 1) {
                throw new MigrationException("마이그레이션 파일에 PRAGMA를 쓰지 않는다: {$f['version']}", $f['version']);
            }
            $fkOff = str_starts_with(ltrim($sql, "\xEF\xBB\xBF"), self::FK_OFF_MARKER);
            if ($fkOff) {
                $this->db->pdo()->exec('PRAGMA foreign_keys = OFF'); // 트랜잭션 밖에서만 효과가 있다
                // 새 표를 옛 이름으로 바꿀 때 다른 표의 트리거(옛 표를 참조)를 다시 검사하지 않게(재구성 중엔 옛 표가 잠시 없다)
                $this->db->pdo()->exec('PRAGMA legacy_alter_table = ON');
            }
            try {
                $this->db->transaction(function (Db $db) use ($f, $sql, $fkOff): void {
                    $db->pdo()->exec($sql);
                    if ($fkOff) {
                        $broken = $db->all('PRAGMA foreign_key_check');
                        if ($broken !== []) {
                            throw new MigrationException('외래 키 검사 실패 ' . count($broken) . '건(' . implode(', ', array_unique(array_column($broken, 'table'))) . ')', $f['version']);
                        }
                    }
                    $db->execute(
                        'INSERT INTO schema_migrations (version, name, checksum, applied_at) VALUES (?, ?, ?, ?)',
                        [$f['version'], $f['name'], hash('sha256', $sql), SystemClock::iso($this->clock->now())],
                    );
                });
            } catch (\Throwable $e) {
                throw new MigrationException("마이그레이션 {$f['version']}_{$f['name']} 실패(롤백함, 이후 적용 중단): " . $e->getMessage(), $f['version'], $e);
            } finally {
                if ($fkOff) {
                    $this->db->pdo()->exec('PRAGMA legacy_alter_table = OFF');
                    $this->db->pdo()->exec('PRAGMA foreign_keys = ON');
                }
            }
            $done[] = $f['version'];
        }
        return $done;
    }
}
