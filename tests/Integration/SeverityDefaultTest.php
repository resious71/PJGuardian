<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Defects\DefectFilter;
use PJGuardian\Services\Process\ProcessOptions;
use PJGuardian\Services\Process\ProcessRunner;
use PJGuardian\Services\Severity\SeverityMap;
use PJGuardian\Support\Db;
use PJGuardian\Support\FixedClock;
use PJGuardian\Support\Logger;
use PJGuardian\Support\Migrator;
use PJGuardian\Support\Paths;
use PJGuardian\Tests\Support\RunFixture;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 규칙 기본 심각도(P21S): 분석 시 신규 결함 자동 부여(출처 auto), 기존 결함 불변(유지·재오픈·매핑 변경 뒤에도), 사람이 바꾼 값 보호(manual, not_defined 포함),
 * 설정 끄기, 마이그레이션 012(출처 채움·이력 표 다시 만들기·CHECK), 일괄 채움 도구(미리보기·적용·멱등·이어서·프로젝트·재계산·이력·건너뜀),
 * 과거 Run 일관성(적용 전 Run은 not_defined, P20S 최적화 = 전체 복원).
 */
final class SeverityDefaultTest extends TestCase
{
    private string $root;
    private App $app;
    private int $admin;
    private int $pid;
    private RunFixture $f;

    public function setUp(): void
    {
        $this->root = $this->tempDir('severity');
        $this->app = $this->appWith(TestDb::migrated($this->root . '/db.sqlite'));
        $db = $this->app->db();
        $this->admin = TestDb::user($db, 'admin', 'admin');
        $this->pid = TestDb::project($db, 'sev', 'python');
        $this->f = new RunFixture($this->app, $this->root, $this->pid, $this->admin);
    }

    /** @param array<string, array<string, string>> $over */
    private function appWith(Db $db, array $over = []): App
    {
        $over['paths']['logs_dir'] = $this->root . '/logs';
        $config = TestApp::config($over);
        $clock = new FixedClock('2026-10-05T12:00:00Z');
        $paths = new Paths(PJG_ROOT, $config);
        return new App(PJG_ROOT, $config, $clock, new Logger($paths->logs(), $clock, 'INFO'), $paths, $db);
    }

    private function real(): SeverityMap
    {
        return new SeverityMap(PJG_ROOT . '/resources/severity');
    }

    /** 실제 카탈로그를 쓰되 모든 분류군 기본값을 $severity로 바꾼 매핑(매핑이 바뀐 상황) */
    private function altered(string $severity): SeverityMap
    {
        $dir = $this->tempDir('sevalt');
        foreach (SeverityMap::TOOLS as $tool) {
            copy(PJG_ROOT . "/resources/severity/{$tool}.catalog.json", "{$dir}/{$tool}.catalog.json");
            $groups = array_values(array_unique(array_filter(array_column(json_decode((string) file_get_contents("{$dir}/{$tool}.catalog.json"), true)['rules'], 'group'))));
            file_put_contents("{$dir}/{$tool}.json", json_encode(['tool' => $tool, 'mapping_version' => 2, 'prefixes' => [], 'rules' => [],
                'defaults' => array_map(static fn (string $g): array => ['group' => $g, 'severity' => $severity, 'reason' => 'alt'], $groups)]));
        }
        return new SeverityMap($dir);
    }

    /** @return array<string, array{0:string, 1:string}> "파일:줄" → [심각도, 출처] */
    private function sev(): array
    {
        $out = [];
        foreach ($this->app->db()->all('SELECT file_path, line_no, severity, severity_source FROM defects WHERE project_id = ? ORDER BY file_path, line_no', [$this->pid]) as $r) {
            $out["{$r['file_path']}:{$r['line_no']}"] = [(string) $r['severity'], (string) $r['severity_source']];
        }
        return $out;
    }

    private function setSeverity(string $file, int $line, string $severity): void
    {
        $user = $this->app->db()->one('SELECT * FROM users WHERE id = ?', [$this->admin]);
        $id = $this->f->id($file, $line);
        $v = (int) $this->app->db()->value('SELECT version FROM defects WHERE id = ?', [$id]);
        $this->app->defectUpdate()->update($user, $id, ['version' => $v, 'severity' => $severity]);
    }

    public function testNewDefectsGetRuleDefaultAndExistingNeverChange(): void
    {
        $f = $this->f;
        $f->severity = $this->real();
        $files = ['a.py' => 6];
        $hits = [['a.py', 1, 'ruff:F401'], ['a.py', 2, 'ruff:F821'], ['a.py', 3, 'ruff:E501'], ['a.py', 4, 'ruff:S105'], ['a.py', 5, 'ruff:ZZZ999']];
        $s1 = $f->analyze($files, $hits);
        $this->assertSame(['a.py:1' => ['medium', 'auto'], 'a.py:2' => ['high', 'auto'], 'a.py:3' => ['low', 'auto'], 'a.py:4' => ['critical', 'auto'], 'a.py:5' => ['not_defined', 'none']], $this->sev(), '신규 = 규칙 기본 심각도');
        $this->assertSame(['critical' => 1, 'high' => 1, 'medium' => 1, 'low' => 1, 'not_defined' => 1], $s1['severity'], '분석 통계');
        $this->assertSame(0, (int) $this->app->db()->value("SELECT COUNT(*) FROM defect_history WHERE field = 'severity'"), '신규 부여는 이력 없음');
        // 사람이 바꿈: F401 medium → low, F821 high → not_defined(사람이 정한 not_defined도 manual)
        $this->setSeverity('a.py', 1, 'low');
        $this->setSeverity('a.py', 2, 'not_defined');
        // 매핑이 바뀌어도(모두 critical) 기존 결함은 그대로. Run 2: E501(줄 3) 미감지 → 자동 해결
        $f->severity = $this->altered('critical');
        $f->analyze($files, [$hits[0], $hits[1], $hits[3], $hits[4]]);
        // Run 3: E501 재감지(재오픈) + 새 결함(줄 6) → 새 결함만 지금 매핑(critical)
        $f->analyze($files, [...$hits, ['a.py', 6, 'ruff:E501']]);
        $this->assertSame([
            'a.py:1' => ['low', 'manual'], 'a.py:2' => ['not_defined', 'manual'], 'a.py:3' => ['low', 'auto'], 'a.py:4' => ['critical', 'auto'],
            'a.py:5' => ['not_defined', 'none'], 'a.py:6' => ['critical', 'auto'],
        ], $this->sev(), '유지·재오픈·수동은 불변, 신규만 지금 매핑');
        $this->assertSame('open', (string) $this->app->db()->value('SELECT status FROM defects WHERE id = ?', [$f->id('a.py', 3)]), '재오픈됨');
        // 매핑 끔(null): 신규는 not_defined·none
        $f->severity = null;
        $f->analyze(['a.py' => 7], [...$hits, ['a.py', 6, 'ruff:E501'], ['a.py', 7, 'ruff:S105']]);
        $this->assertSame(['not_defined', 'none'], $this->sev()['a.py:7']);
    }

    public function testConfigSwitchAndManualSource(): void
    {
        // 설정: 기본 true면 분석 반영에 매핑을 넘기고, false면 넘기지 않는다
        $prop = static fn (App $a): mixed => (new \ReflectionProperty($a->analysisFinalizer(), 'severity'))->getValue($a->analysisFinalizer());
        $this->assertTrue($this->app->config->bool('severity.auto_assign'));
        $this->assertTrue($prop($this->app) instanceof SeverityMap);
        $off = $this->appWith(TestDb::migrated(), ['severity' => ['auto_assign' => 'false']]);
        $this->assertSame(null, $prop($off));

        $f = $this->f;
        $f->severity = $this->real();
        $f->analyze(['a.py' => 3], [['a.py', 1, 'ruff:F401'], ['a.py', 2, 'ruff:F821'], ['a.py', 3, 'ruff:E501']]);
        // 일괄 변경도 manual. 값이 같으면(변경 없음) 출처도 그대로
        $user = $this->app->db()->one('SELECT * FROM users WHERE id = ?', [$this->admin]);
        $items = array_map(fn (int $l): array => ['id' => $f->id('a.py', $l), 'version' => (int) $this->app->db()->value('SELECT version FROM defects WHERE id = ?', [$f->id('a.py', $l)])], [1, 2]);
        $r = $this->app->defectUpdate()->bulk($user, $this->pid, ['items' => $items, 'changes' => ['severity' => 'high']]);
        $this->assertSame([[$f->id('a.py', 2)], 1], [$r['unchanged'], count($r['updated'])], 'F821은 이미 high');
        $this->assertSame(['a.py:1' => ['high', 'manual'], 'a.py:2' => ['high', 'auto'], 'a.py:3' => ['low', 'auto']], $this->sev());
        $h = $this->app->db()->one("SELECT kind, changed_by, old_value, new_value FROM defect_history WHERE field = 'severity'");
        $this->assertSame(['manual', $this->admin, 'medium', 'high'], [$h['kind'], (int) $h['changed_by'], $h['old_value'], $h['new_value']]);
        // 상세·목록 응답의 출처, 과거 Run 보기의 출처(그 시점: 수동 변경 전이면 auto)
        $this->assertSame('manual', $this->app->defectQuery()->detail($f->id('a.py', 1))['severity_source']);
        $f->analyze(['a.py' => 3], [['a.py', 1, 'ruff:F401'], ['a.py', 2, 'ruff:F821'], ['a.py', 3, 'ruff:E501']]);
        $runs = $this->app->runs();
        $run1 = $runs->resolve($this->pid, '1');
        [$rows] = $this->app->defectQuery()->list($this->pid, DefectFilter::fromQuery([]), 50, 0, $runs->source($this->pid, $run1), $run1);
        $this->assertSame([['medium', 'auto'], ['high', 'auto'], ['low', 'auto']], array_map(static fn (array $d): array => [$d['severity'], $d['severity_source']], $rows), 'Run 1 시점');
        [$rows] = $this->app->defectQuery()->list($this->pid, DefectFilter::fromQuery([]), 50, 0);
        $this->assertSame(['manual', 'auto', 'auto'], array_column($rows, 'severity_source'), '최신');
    }

    public function testMigration012FillsSourceAndRebuildsHistory(): void
    {
        $dir = $this->tempDir('mig012');
        mkdir("{$dir}/m");
        foreach (glob(PJG_ROOT . '/migrations/0*.sql') as $file) {
            if (basename($file) < '012') {
                copy($file, "{$dir}/m/" . basename($file));
            }
        }
        $db = new Db("{$dir}/x.sqlite");
        (new Migrator($db, "{$dir}/m", new FixedClock()))->migrate();
        $uid = TestDb::user($db, 'admin', 'admin');
        $pid = TestDb::project($db, 'p', 'python');
        $aid = TestDb::analysis($db, $pid, $uid, ['status' => 'succeeded', 'finished_at' => '2026-10-01T00:00:00Z']);
        $plain = TestDb::defect($db, $pid, $aid);
        $high = TestDb::defect($db, $pid, $aid, ['severity' => 'high']);
        $back = TestDb::defect($db, $pid, $aid);
        $add = static fn (int $d, string $old, string $new) => $db->execute("INSERT INTO defect_history (defect_id, field, old_value, new_value, changed_by, changed_at, kind) VALUES (?, 'severity', ?, ?, ?, '2026-10-01T01:00:00Z', 'manual')", [$d, $old, $new, $uid]);
        $add($high, 'not_defined', 'high');
        $add($back, 'not_defined', 'low');
        $add($back, 'low', 'not_defined'); // 사람이 not_defined로 되돌림
        TestDb::autoHistory($db, $plain, $aid, 'open', 'resolved', 'not_detected');
        $before = $db->all('SELECT * FROM defect_history ORDER BY id');
        copy(PJG_ROOT . '/migrations/012_severity_source.sql', "{$dir}/m/012_severity_source.sql");
        $t = hrtime(true);
        $this->assertSame(['012'], (new Migrator($db, "{$dir}/m", new FixedClock()))->migrate());
        $ms = (hrtime(true) - $t) / 1e6;
        $src = array_column($db->all('SELECT id, severity_source FROM defects'), 'severity_source', 'id');
        $this->assertSame([$plain => 'none', $high => 'manual', $back => 'manual'], $src, '기존 결함 출처');
        $this->assertSame($before, $db->all('SELECT * FROM defect_history ORDER BY id'), '이력 행·id 그대로');
        $this->assertSame(['defect_history_analysis', 'defect_history_defect', 'defect_history_field'], array_values(array_map(static fn (array $r): string => (string) $r['name'],
            $db->all("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'defect_history' ORDER BY name"))), '인덱스 그대로');
        $max = (int) end($before)['id'];
        $db->execute("INSERT INTO defect_history (defect_id, field, old_value, new_value, changed_at, kind, reason_code, memo) VALUES (?, 'severity', 'not_defined', 'high', '2026-10-02T00:00:00Z', 'auto', 'rule_default', 'm')", [$plain]);
        $this->assertSame($max + 1, (int) $db->value('SELECT MAX(id) FROM defect_history'), 'AUTOINCREMENT 이어짐');
        $bad = [
            "('severity', 'auto', 'rule_default', {$aid}, NULL)" => '규칙 기본 적용에 분석 id',
            "('status', 'auto', 'rule_default', NULL, NULL)" => '상태에 rule_default',
            "('severity', 'auto', 'not_detected', {$aid}, NULL)" => '심각도 자동 이력에 다른 사유',
            "('severity', 'manual', 'rule_default', NULL, {$uid})" => '수동에 사유 코드',
        ];
        foreach ($bad as $values => $label) {
            $this->assertThrows(\PDOException::class, static fn () => $db->pdo()->exec("INSERT INTO defect_history (field, kind, reason_code, analysis_id, changed_by, defect_id, changed_at) SELECT x.*, {$plain}, '2026-10-02T00:00:00Z' FROM (SELECT {$values}) x"), '');
        }
        $this->assertThrows(\PDOException::class, static fn () => $db->execute("UPDATE defects SET severity_source = 'robot' WHERE id = ?", [$plain]), '');
        $this->assertTrue($ms < 5000, "작은 표 마이그레이션 {$ms}ms");
    }

    /** @return array{0:int, 1:string} 종료 코드, 출력 */
    private function tool(array $args): array
    {
        $ini = TestApp::writeIni($this->root . '/t.ini', ['paths' => ['logs_dir' => $this->root . '/logs', 'db_file' => $this->root . '/db.sqlite']]);
        $r = (new ProcessRunner([], [PHP_BINARY]))->run([PHP_BINARY, PJG_ROOT . '/tools/severity_apply.php', '--config=' . $ini, '--db=' . $this->root . '/db.sqlite', '--sleep-ms=0', ...$args],
            new ProcessOptions(timeoutSeconds: 120));
        $out = $r->stdout() . $r->stderr();
        $r->cleanup();
        return [(int) $r->exitCode, $out];
    }

    private function state(): string
    {
        return json_encode([$this->app->db()->all('SELECT id, severity, severity_source, version FROM defects ORDER BY id'), $this->app->db()->value('SELECT COUNT(*) FROM defect_history')]);
    }

    public function testApplyToolPreviewApplyIdempotentResume(): void
    {
        // 매핑 없이(이전 동작) 쌓인 결함: Run 1·2, 사람이 바꾼 결함 1건, 매핑에 없는 규칙 1건, 다른 프로젝트
        $f = $this->f;
        $hits = [['a.py', 1, 'ruff:F401'], ['a.py', 2, 'ruff:F821'], ['a.py', 3, 'ruff:E501'], ['a.py', 4, 'ruff:S105'], ['a.py', 5, 'ruff:ZZZ999']];
        $f->analyze(['a.py' => 5], $hits);
        $this->setSeverity('a.py', 2, 'low');
        $f->analyze(['a.py' => 5], $hits);
        $other = TestDb::project($this->app->db(), 'other', 'python');
        (new RunFixture($this->app, $this->root, $other, $this->admin))->analyze(['b.py' => 1], [['b.py', 1, 'ruff:E501']]);

        // 미리보기: 바꾸지 않는다
        $before = $this->state();
        [$code, $out] = $this->tool([]);
        $this->assertSame(0, $code, $out);
        $this->assertSame($before, $this->state(), '미리보기는 DB 그대로');
        $this->assertTrue(str_contains($out, '변경 예정 4건') && str_contains($out, '건너뜀 1건') && str_contains($out, 'ruff:ZZZ999'), $out);

        // 프로젝트 지정 적용: 그 프로젝트만
        [$code, $out] = $this->tool(['--apply', '--project=' . $other]);
        $this->assertSame(0, $code, $out);
        $this->assertSame(['low', 'auto'], [(string) $this->app->db()->value('SELECT severity FROM defects WHERE project_id = ?', [$other]), (string) $this->app->db()->value('SELECT severity_source FROM defects WHERE project_id = ?', [$other])]);
        $this->assertSame(['not_defined', 'none'], $this->sev()['a.py:1'], '다른 프로젝트는 그대로');

        // 전체 적용: 사람이 바꾼 값(manual)·매핑 없는 규칙은 건너뛴다. 변경마다 자동 이력
        [$code, $out] = $this->tool(['--apply', '--chunk=2']);
        $this->assertSame(0, $code, $out);
        $this->assertTrue(str_contains($out, '변경 3건'), $out);
        $this->assertSame(['a.py:1' => ['medium', 'auto'], 'a.py:2' => ['low', 'manual'], 'a.py:3' => ['low', 'auto'], 'a.py:4' => ['critical', 'auto'], 'a.py:5' => ['not_defined', 'none']], $this->sev());
        $hist = $this->app->db()->all("SELECT h.defect_id, h.old_value, h.new_value, h.kind, h.reason_code, h.memo, h.changed_by, h.analysis_id FROM defect_history h WHERE h.reason_code = 'rule_default' ORDER BY h.id");
        $this->assertSame(4, count($hist), '이력 행 수 = 변경 건수(다른 프로젝트 1 + 3)');
        $this->assertSame(['not_defined', 'auto', 'rule_default', '규칙 기본 심각도 적용(매핑 ruff v1)', null, null], [$hist[1]['old_value'], $hist[1]['kind'], $hist[1]['reason_code'], $hist[1]['memo'], $hist[1]['changed_by'], $hist[1]['analysis_id']]);
        $this->assertSame(2, (int) $this->app->db()->value('SELECT version FROM defects WHERE id = ?', [$f->id('a.py', 1)]), 'version + 1');

        // 멱등: 다시 실행하면 0건
        $after = $this->state();
        [, $out] = $this->tool(['--apply']);
        $this->assertTrue(str_contains($out, '변경 0건'), $out);
        $this->assertSame($after, $this->state());

        // 중간에 멈춘 상황(일부가 아직 none): 다시 실행하면 남은 것만 이어서
        $id1 = $f->id('a.py', 1);
        $this->app->db()->execute("UPDATE defects SET severity = 'not_defined', severity_source = 'none' WHERE id = ?", [$id1]);
        $this->app->db()->execute("DELETE FROM defect_history WHERE defect_id = ? AND reason_code = 'rule_default'", [$id1]);
        [, $out] = $this->tool(['--apply']);
        $this->assertTrue(str_contains($out, '변경 1건'), $out);
        $this->assertSame(['medium', 'auto'], $this->sev()['a.py:1']);

        // --reapply-auto: 출처 auto는 지금 매핑으로 다시(여기서는 값을 흐트러뜨린 결함만 돌아온다), manual은 그대로
        $this->app->db()->execute("UPDATE defects SET severity = 'high' WHERE id = ?", [$f->id('a.py', 3)]);
        [, $out] = $this->tool(['--apply']);
        $this->assertTrue(str_contains($out, '변경 0건'), '기본 옵션은 auto를 건드리지 않음');
        [, $out] = $this->tool(['--apply', '--reapply-auto']);
        $this->assertTrue(str_contains($out, '변경 1건'), $out);
        $this->assertSame(['a.py:2' => ['low', 'manual'], 'a.py:3' => ['low', 'auto']], array_intersect_key($this->sev(), ['a.py:3' => 1, 'a.py:2' => 1]));
        // 잘못된 옵션
        $this->assertSame(2, $this->tool(['--project=x'])[0]);
    }

    public function testPastRunsKeepPreviousSeverityAfterApply(): void
    {
        $f = $this->f;
        $hits = [['a.py', 1, 'ruff:F401'], ['a.py', 2, 'ruff:F821'], ['a.py', 3, 'ruff:S105']];
        $f->analyze(['a.py' => 3], $hits);
        $f->analyze(['a.py' => 3], [$hits[0], $hits[1]]);
        $f->analyze(['a.py' => 3], $hits);
        [$code, $out] = $this->tool(['--apply']);
        $this->assertSame(0, $code, $out);
        // 다음 분석(Run 4)까지 지나면 Run 1~3은 과거 Run
        $f->analyze(['a.py' => 3], $hits);
        $q = $this->app->defectQuery();
        $runs = $this->app->runs();
        $count = function (int $n, array $query) use ($q, $runs): int {
            $run = $runs->resolve($this->pid, (string) $n);
            return $q->list($this->pid, DefectFilter::fromQuery($query), 50, 0, $run === null ? null : $runs->source($this->pid, $run), $run)[1];
        };
        foreach ([1, 2, 3] as $n) {
            $this->assertSame(0, $count($n, ['severity' => 'high,medium,critical']), "Run {$n}(적용 전)은 not_defined");
        }
        $this->assertSame([3, 1, 1, 1], [$count(3, ['severity' => 'not_defined']), $count(4, ['severity' => 'critical']), $count(4, ['severity' => 'high']), $count(4, ['severity' => 'medium'])], '최신 Run은 새 값');
        // P20S: 과거 Run 최적화 경로 = 전체 복원(심각도 필터·정렬 포함)
        foreach ([1, 2, 3] as $n) {
            $run = $runs->resolve($this->pid, (string) $n);
            foreach ([[], ['severity' => 'not_defined'], ['sort' => 'severity', 'order' => 'desc'], ['severity' => 'high']] as $fq) {
                $flt = DefectFilter::fromQuery($fq);
                $this->assertSame($q->list($this->pid, $flt, 50, 0, $runs->source($this->pid, $run), $run, true), $q->list($this->pid, $flt, 50, 0, $runs->source($this->pid, $run), $run), "Run {$n} " . json_encode($fq));
            }
        }
        // 전체 이력: 규칙 기본 심각도 적용은 "Run 3 이후"의 자동 항목
        $tl = $this->app->history()->timeline($f->id('a.py', 3), 50, 0)['items'];
        $item = array_values(array_filter($tl, static fn (array $e): bool => $e['kind'] === 'severity_default'))[0] ?? null;
        $this->assertSame([3, true, 'not_defined', 'critical', 'rule_default', null], [$item['run'], $item['run_after'], $item['from'], $item['to'], $item['reason'], $item['by']]);
    }
}
