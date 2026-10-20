<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Queue\Worker;
use PJGuardian\Services\Rules\RuleEnrichment;
use PJGuardian\Services\Rules\RuleExtractor;
use PJGuardian\Support\Db;
use PJGuardian\Support\FixedClock;
use PJGuardian\Support\Migrator;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 규칙 데이터 적재(P22): 실제 도구(bin/ 읽기만, 없으면 건너뜀) 추출·멱등·변경 내역, 실패의 격리, 마이그레이션 015, 워커 자동 요청, 보강 자료 형식.
 */
final class RuleLoadTest extends TestCase
{
    private function app(array $over = []): App
    {
        $root = $this->tempDir('ruleload');
        mkdir($root . '/work');
        $node = getenv('PJG_NODE') ?: 'C:/tools/nodejs/node.exe';
        return TestApp::make($root, array_replace_recursive(['paths' => ['work_dir' => $root . '/work', 'bin_dir' => PJG_ROOT . '/bin'],
            'tools' => ['node_path' => is_file($node) ? $node : '']], $over));
    }

    /** @needs-bin bin/ruff·bin/eslint 실행 파일과 Node */
    public function testRealToolsExtractAndLoadIdempotently(): void
    {
        $app = $this->app();
        $reg = $app->toolRegistry();
        if ($reg->versionsOnDisk('ruff') === [] || $reg->versionsOnDisk('eslint') === [] || $app->nodeRuntime()->path() === null) {
            echo "    (건너뜀: bin/에 ruff·ESLint 또는 Node가 없음)\n";
            $this->assertTrue(true);
            return;
        }
        $ex = $app->ruleExtractor();
        $ruff = $ex->extract('ruff', $reg->versionsOnDisk('ruff')[0]);
        $this->assertTrue(count($ruff['rules']) > 900, 'ruff 규칙 ' . count($ruff['rules']));
        $f = $ruff['rules']['F401'];
        $this->assertSame(['F401', 'unused-import', 'Pyflakes', true], [$f['rule'], $f['name'], $f['origin'], $f['default_enabled']], 'F401: 서버 기본 설정(E, F)에서 켜짐');
        $this->assertContains('## Example', $f['description']);
        $this->assertSame(false, $ruff['rules']['D100']['default_enabled'] ?? false, 'D 계열은 기본 설정 밖');
        $eslint = $ex->extract('eslint', $reg->versionsOnDisk('eslint')[0]);
        $this->assertTrue(count($eslint['rules']) > 400, 'ESLint 규칙 ' . count($eslint['rules']));
        $u = $eslint['rules']['@typescript-eslint/no-unused-vars'];
        $this->assertSame(['@typescript-eslint', 'problem', true], [$u['origin'], $u['category'], $u['default_enabled']]);
        $this->assertTrue(str_starts_with((string) $u['docs_url'], 'https://'));
        $this->assertSame('', $eslint['rules']['no-undef']['description'], 'ESLint meta에는 상세 설명·예시가 없다(지어내지 않음)');
        $this->assertTrue(isset($eslint['components']['@typescript-eslint/eslint-plugin']));
        // P21S 카탈로그와 규칙 수 일치(Removed 제외)
        $catalog = json_decode((string) file_get_contents(PJG_ROOT . '/resources/severity/ruff.catalog.json'), true);
        if ($catalog['tool_version'] === $ruff['version']) {
            $this->assertSame(count($catalog['rules']), count(array_filter($ruff['rules'], static fn (array $r): bool => $r['status'] !== 'removed')));
            $this->assertSame($catalog['rules']['F401'], RuleExtractor::catalogEntry('ruff', $f), '카탈로그 항목 형식 그대로');
        }
        // 적재 → 다시 적재(멱등)
        $r1 = $app->ruleLoader()->load('default', null, null, true);
        $this->assertSame([], $r1['errors']);
        $this->assertTrue($r1['changed']);
        $hashes = $app->db()->all('SELECT tool, version, content_hash, loaded_at FROM rule_versions ORDER BY tool, version');
        $r2 = $app->ruleLoader()->load('default', null, null, true);
        $this->assertFalse($r2['changed'], '같은 입력이면 바꾸지 않음');
        $this->assertSame($hashes, $app->db()->all('SELECT tool, version, content_hash, loaded_at FROM rule_versions ORDER BY tool, version'));
        $active = $app->db()->all('SELECT tool, version FROM rule_versions WHERE is_active = 1 ORDER BY tool');
        $this->assertSame([['tool' => 'eslint', 'version' => $reg->select('eslint')->version], ['tool' => 'ruff', 'version' => $reg->select('ruff')->version]], $active);
        if (count($reg->versionsOnDisk('eslint')) > 1) {
            $this->assertTrue((int) $app->db()->value("SELECT COUNT(*) FROM rule_changes WHERE tool = 'eslint'") > 0, '설치된 이전 버전이 있으면 변경 내역');
        }
        // 기본 심각도 = P21S explain
        $row = $app->db()->one("SELECT severity, severity_level FROM rules WHERE tool = 'ruff' AND rule = 'F401' AND version = ?", [$reg->select('ruff')->version]);
        $e = $app->severityMap()->explain('ruff', 'ruff:F401');
        $this->assertSame([$e['severity'], $e['level']], [$row['severity'], $row['severity_level']]);
    }

    public function testFailureIsIsolated(): void
    {
        // Node가 없으면 ESLint만 실패하고 ruff는 적재된다(분석과 무관)
        $app = $this->app(['tools' => ['node_path' => PJG_ROOT . '/no-such-node.exe']]);
        if ($app->toolRegistry()->versionsOnDisk('ruff') === []) {
            $this->assertTrue(true);
            return;
        }
        $r = $app->ruleLoader()->load('default', null, null, true);
        $this->assertTrue($r['errors'] !== [] && str_contains(implode(' ', $r['errors']), 'eslint'), json_encode($r['errors']));
        $this->assertTrue((int) $app->db()->value("SELECT COUNT(*) FROM rules WHERE tool = 'ruff'") > 900);
        $this->assertSame(0, (int) $app->db()->value("SELECT COUNT(*) FROM rules WHERE tool = 'eslint'"));
    }

    public function testWorkerRequestsRulesLoadWhenEnabled(): void
    {
        $off = $this->app();
        $w = new Worker($off);
        $m = new \ReflectionMethod($w, 'requestRulesLoad');
        $m->invoke($w, microtime(true));
        $this->assertSame(0, (int) $off->db()->value("SELECT COUNT(*) FROM admin_tasks WHERE kind = 'rules_load'"), '꺼져 있으면 요청 없음');
        $on = $this->app(['rules' => ['auto_load' => 'true', 'load_interval' => '1h']]);
        $w = new Worker($on);
        $m = new \ReflectionMethod($w, 'requestRulesLoad');
        $t = microtime(true);
        $m->invoke($w, $t);
        $m->invoke($w, $t + 10); // 주기 안: 다시 요청하지 않음
        $this->assertSame(1, (int) $on->db()->value("SELECT COUNT(*) FROM admin_tasks WHERE kind = 'rules_load'"));
        // 도구 고정·차단(관리자 화면)도 요청한다(대기 중이면 합쳐짐)
        $admin = TestDb::user($on->db(), 'admin', 'admin');
        $on->db()->execute("UPDATE admin_tasks SET status = 'running', started_at = created_at WHERE kind = 'rules_load'");
        $on->db()->execute("UPDATE admin_tasks SET status = 'succeeded', finished_at = created_at WHERE kind = 'rules_load'");
        try {
            $on->toolAdmin()->unpin(\PJGuardian\Services\Actor::fromUser(['id' => $admin, 'login_id' => 'admin'], null), 'ruff', 'test');
        } catch (\Throwable) {
            // 고정이 없어도 기록·요청 경로는 같다
        }
        $this->assertSame(1, (int) $on->db()->value("SELECT COUNT(*) FROM admin_tasks WHERE kind = 'rules_load' AND status = 'queued'"), 'unpin 뒤 요청');
        $this->assertSame(['tool_verify', 'cleanup_run', 'disk_scan', 'rules_load'], \PJGuardian\Services\Admin\AdminTasks::KINDS);
    }

    public function testMigration015KeepsAdminTasksAndAddsKind(): void
    {
        $dir = $this->tempDir('mig015');
        mkdir("{$dir}/m");
        foreach (glob(PJG_ROOT . '/migrations/0*.sql') as $file) {
            if (basename($file) < '015') {
                copy($file, "{$dir}/m/" . basename($file));
            }
        }
        $db = new Db("{$dir}/x.sqlite");
        (new Migrator($db, "{$dir}/m", new FixedClock()))->migrate();
        $uid = TestDb::user($db, 'admin', 'admin');
        foreach ([['tool_verify', 'ruff@1.0.0', 'succeeded'], ['disk_scan', '', 'failed'], ['cleanup_run', '', 'queued']] as [$k, $t, $s]) {
            $db->execute('INSERT INTO admin_tasks (kind, target, status, requested_by, result, created_at, started_at, finished_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$k, $t, $s, $uid, $s === 'queued' ? null : '{"ok":1}', TestDb::T0, $s === 'queued' ? null : TestDb::T0, $s === 'queued' ? null : TestDb::T0]);
        }
        $this->assertThrows(\PDOException::class, static fn () => $db->execute("INSERT INTO admin_tasks (kind, created_at) VALUES ('rules_load', ?)", [TestDb::T0]), 'CHECK'); // 015 전에는 rules_load 거부
        $before = $db->all('SELECT * FROM admin_tasks ORDER BY id');
        copy(PJG_ROOT . '/migrations/015_rules.sql', "{$dir}/m/015_rules.sql");
        $this->assertSame(['015'], (new Migrator($db, "{$dir}/m", new FixedClock()))->migrate());
        $this->assertSame($before, $db->all('SELECT * FROM admin_tasks ORDER BY id'), '기존 행 그대로(같은 id)');
        $db->execute("INSERT INTO admin_tasks (kind, created_at) VALUES ('rules_load', ?)", [TestDb::T0]);
        $this->assertSame(4, (int) $db->value("SELECT id FROM admin_tasks WHERE kind = 'rules_load'"), 'AUTOINCREMENT 이어짐');
        $this->assertThrows(\PDOException::class, static fn () => $db->execute("INSERT INTO admin_tasks (kind, created_at) VALUES ('cleanup_run', ?)", [TestDb::T0]), 'UNIQUE'); // 대기 중 중복(부분 고유 인덱스 유지)
        foreach (['rule_versions', 'rules', 'rules_fts', 'rule_changes'] as $t) {
            $this->assertSame(1, (int) $db->value("SELECT COUNT(*) FROM sqlite_master WHERE name = ?", [$t]), $t);
        }
        $this->assertThrows(\PDOException::class, static fn () => $db->execute("INSERT INTO rule_versions (tool, version, content_hash, loaded_at) VALUES ('pylint', '1', ?, ?)", [str_repeat('a', 64), TestDb::T0]));
        unset($db);
    }

    public function testEnrichmentFormatAndFillOnlyEmpty(): void
    {
        $this->assertSame([], (new RuleEnrichment(PJG_ROOT . '/resources/rules/enrichment'))->validate(), '저장소의 보강 자료(지금은 빈 틀) 형식');
        $dir = $this->tempDir('enrich');
        file_put_contents("{$dir}/eslint.json", json_encode(['format' => 1, 'rules' => [
            'no-undef' => ['description' => '## 예시', 'sources' => ['https://eslint.org/docs/latest/rules/no-undef']],
        ]]));
        $e = new RuleEnrichment($dir);
        $this->assertSame([], $e->validate());
        $r = $e->apply('eslint', ['rule' => 'no-undef', 'summary' => '도구 요약', 'description' => '', 'docs_url' => null]);
        $this->assertSame(['도구 요약', '## 예시', ['https://eslint.org/docs/latest/rules/no-undef']], [$r['summary'], $r['description'], $r['enrichment_sources']], '비어 있는 항목만');
        $kept = $e->apply('eslint', ['rule' => 'no-undef', 'summary' => 's', 'description' => '도구 설명', 'docs_url' => null]);
        $this->assertSame(['도구 설명', false], [$kept['description'], isset($kept['enrichment_sources'])], '도구 값이 우선');
        foreach ([
            ['no-undef' => ['description' => 'x']],                                  // 출처 없음
            ['no-undef' => ['description' => 'x', 'sources' => ['http://insecure']]],
            ['no-undef' => ['description' => 'x', 'sources' => ['https://a'], 'examples' => 'y']],
            ['no-undef' => ['docs_url' => 'javascript:alert(1)', 'sources' => ['https://a']]],
        ] as $bad) {
            file_put_contents("{$dir}/eslint.json", json_encode(['format' => 1, 'rules' => $bad]));
            $this->assertTrue((new RuleEnrichment($dir))->validate() !== [], json_encode($bad));
        }
    }
}
