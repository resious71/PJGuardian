<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Analysis\AnalysisComparison;
use PJGuardian\Services\Analysis\AnalysisJob;
use PJGuardian\Services\Process\ProcessOptions;
use PJGuardian\Services\Tools\EffectiveRuleConfig;
use PJGuardian\Services\Tools\NodeRuntime;
use PJGuardian\Services\Tools\RuffConfigResolver;
use PJGuardian\Services\Tools\RuffRunner;
use PJGuardian\Services\Tools\SelectedTool;
use PJGuardian\Services\Tools\ToolAnalysisRunner;
use PJGuardian\Services\Tools\ToolRegistry;
use PJGuardian\Support\ExcludeMatcher;
use PJGuardian\Support\Fs;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 관리자 규칙 변경을 실제 도구에 반영(P22B): ruff(끄기·켜기·접두사 위 개별 켜기, --show-settings 켜진 규칙 집합, 업로드 설정이면 미적용),
 * ESLint(끄기·켜기, TS 규칙은 TS 파일에만, 테스트 파일 overrides 위에서도), 변경이 없을 때의 설정 해시는 P15 계산과 같음,
 * 분석 파이프라인(분석 시작 때 읽은 변경 → 결과·설정 해시 → "설정 변경" 판정).
 * bin/ruff/0.16.10, bin/eslint/10.11.0과 Node가 있어야 한다(없으면 건너뜀을 출력하고 통과로 둔다).
 *
 * @needs-bin bin/ruff·bin/eslint 실행 파일과 Node(모든 시험이 실제 도구로 확인)
 */
final class RuleOverrideToolsTest extends TestCase
{
    private App $app;
    private string $root;
    private ?string $node = null;

    public function setUp(): void
    {
        $this->root = $this->tempDir('rotool');
        mkdir($this->root . '/work');
        mkdir($this->root . '/logs');
        $this->node = (new NodeRuntime(null, '20.19.0'))->path() ?? (is_file('C:/tools/nodejs/node.exe') ? 'C:/tools/nodejs/node.exe' : null);
        $this->app = TestApp::make($this->root . '/logs', [
            'paths' => ['work_dir' => $this->root . '/work', 'uploads_dir' => $this->root . '/work', 'snapshots_dir' => $this->root . '/work'],
            'tools' => ['node_path' => (string) $this->node],
        ]);
    }

    private function ruff(): ?SelectedTool
    {
        $exe = realpath(PJG_ROOT . '/bin/ruff/0.16.10/' . ToolRegistry::executableRel('ruff'));
        return $exe === false ? null : new SelectedTool('ruff', '0.16.10', ToolRegistry::os(), (string) realpath(PJG_ROOT . '/bin/ruff/0.16.10'), $exe);
    }

    private function eslint(): ?SelectedTool
    {
        $dir = realpath(PJG_ROOT . '/bin/eslint/10.11.0');
        if ($dir === false || $this->node === null) {
            return null;
        }
        return new SelectedTool('eslint', '10.11.0', ToolRegistry::os(), $dir, (string) realpath($dir . '/node_modules/eslint/bin/eslint.js'), (string) realpath($this->node), '24.0.0');
    }

    private function skip(string $why): void
    {
        fwrite(STDOUT, "      [건너뜀] {$why}\n");
    }

    private function matcher(): ExcludeMatcher
    {
        return new ExcludeMatcher((array) $this->app->config->get('extract.default_excludes'), []);
    }

    private function project(array $files): string
    {
        $dir = $this->root . '/p' . bin2hex(random_bytes(3));
        foreach ($files as $rel => $content) {
            Fs::ensureDir(dirname($dir . '/' . $rel));
            file_put_contents($dir . '/' . $rel, $content);
        }
        return $dir;
    }

    /** @return list<string> "파일:규칙 키"(정렬) */
    private function found(string $file): array
    {
        $out = [];
        foreach (array_filter(explode("\n", (string) file_get_contents($file))) as $l) {
            $d = json_decode($l, true);
            $out[] = $d['file'] . ':' . $d['rule_key'];
        }
        sort($out);
        return array_values(array_unique($out));
    }

    private const PY = "import os\nLONG = \"" . 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' . "\"\n";

    public function testRuffOverridesAndHash(): void
    {
        $tool = $this->ruff();
        if ($tool === null) {
            $this->skip('bin/ruff 없음');
            return;
        }
        $dir = $this->project(['a.py' => self::PY]);
        $runner = $this->app->ruffRunner();
        $base = $runner->run($tool, $dir, $this->matcher(), $this->root . '/r0.ndjson');
        $this->assertTrue($base->ok, (string) $base->detail);
        $this->assertSame(['a.py:ruff:E501', 'a.py:ruff:F401'], $this->found($this->root . '/r0.ndjson'));
        // 변경이 없으면 P15와 같은 해시(기존 Run과 "설정 변경"이 생기지 않음)
        $default = (string) realpath(PJG_ROOT . '/config/ruff.default.toml');
        $exclude = implode(',', $this->matcher()->defaults());
        $p15 = RuffConfigResolver::hashFiles([$default], json_encode([['check', '--output-format', 'json-lines', '--no-cache', '--no-respect-gitignore', '--exit-zero'], $exclude], JSON_UNESCAPED_SLASHES));
        $this->assertSame($p15, $base->configHash, 'P15 계산식 그대로');
        $empty = $runner->run($tool, $dir, $this->matcher(), $this->root . '/r0b.ndjson', null, null, ['enable' => [], 'disable' => []]);
        $this->assertSame($p15, $empty->configHash, '빈 변경 = 변경 없음');

        // E 아래 E501 끄기 + 선택되지 않은 D 계열 D100 켜기
        $set = ['enable' => ['D100'], 'disable' => ['E501']];
        $over = $runner->run($tool, $dir, $this->matcher(), $this->root . '/r1.ndjson', null, null, $set);
        $this->assertTrue($over->ok, (string) $over->detail);
        $this->assertSame(['a.py:ruff:D100', 'a.py:ruff:F401'], $this->found($this->root . '/r1.ndjson'));
        $this->assertSame('server_default', $over->configSource);
        $this->assertTrue($over->configHash !== $p15, '변경이 있으면 해시가 바뀜');
        $again = $runner->run($tool, $dir, $this->matcher(), $this->root . '/r2.ndjson', null, null, ['disable' => ['E501', 'E501'], 'enable' => ['D100']]);
        $this->assertSame($over->configHash, $again->configHash, '같은 집합이면 같은 해시(정렬·중복 제거)');

        // --show-settings: 켜진 규칙 집합 = 기본 ∪ {D100} − {E501}
        $enabled = function (string $config) use ($tool, $dir): array {
            $r = $this->app->toolRunner()->run([$tool->executable, 'check', '--show-settings', '--no-cache', '--config', $config, $dir . '/a.py'], new ProcessOptions(timeoutSeconds: 60));
            $out = $r->stdout();
            $r->cleanup();
            $start = (int) strpos($out, 'linter.rules.enabled = [');
            preg_match_all('/\(([A-Z]+[0-9]+)\)/', substr($out, $start, (int) strpos($out, ']', $start) - $start), $m);
            sort($m[1]);
            return $m[1];
        };
        $file = $this->root . '/eff.toml';
        file_put_contents($file, EffectiveRuleConfig::ruffToml($default, EffectiveRuleConfig::canonical('ruff', $set)));
        $expect = array_values(array_diff([...$enabled($default), 'D100'], ['E501']));
        sort($expect);
        $this->assertSame($expect, $enabled($file));
        // 기본이 접두사 ignore로 꺼 둔 규칙 하나 켜기(ignore = ["E5"] 위 E501)
        $base2 = $this->root . '/base2.toml';
        file_put_contents($base2, "[lint]\nselect = [\"E\", \"F\"]\nignore = [\"E5\"]\n");
        $file2 = $this->root . '/eff2.toml';
        file_put_contents($file2, EffectiveRuleConfig::ruffToml($base2, ['enable' => ['E501'], 'disable' => []]));
        $this->assertFalse(in_array('E501', $enabled($base2), true));
        $this->assertTrue(in_array('E501', $enabled($file2), true), '개별 코드가 접두사 ignore를 이김');

        // 프로젝트가 올린 ruff 설정은 그대로(변경 미적용, 해시도 변경 없을 때와 같음)
        $up = $this->project(['a.py' => self::PY, 'ruff.toml' => "[lint]\nselect = [\"E\", \"F\"]\n"]);
        $u0 = $runner->run($tool, $up, $this->matcher(), $this->root . '/u0.ndjson');
        $u1 = $runner->run($tool, $up, $this->matcher(), $this->root . '/u1.ndjson', null, null, $set);
        $this->assertSame(['upload', 'upload'], [$u0->configSource, $u1->configSource]);
        $this->assertSame($this->found($this->root . '/u0.ndjson'), $this->found($this->root . '/u1.ndjson'));
        $this->assertSame(['a.py:ruff:E501', 'a.py:ruff:F401'], $this->found($this->root . '/u1.ndjson'));
        $this->assertSame($u0->configHash, $u1->configHash);
    }

    public function testEslintOverrides(): void
    {
        $tool = $this->eslint();
        if ($tool === null) {
            $this->skip('bin/eslint 또는 Node 없음');
            return;
        }
        $dir = $this->project([
            'a.js' => "var unusedVar = 1;\nvar k = 2;\nif (k == 2) { console.log(k); }\n",
            'b.ts' => "const x: any = 1;\nexport function f(y: number) { return y + x; }\n",
            'tests/c.test.js' => "var t = 1;\n",
        ]);
        $runner = $this->app->eslintRunner();
        $base = $runner->run($tool, $dir, $this->matcher(), $this->root . '/e0.ndjson');
        $this->assertTrue($base->ok, (string) $base->detail);
        $this->assertSame(['a.js:eslint:no-unused-vars', 'b.ts:eslint:@typescript-eslint/no-explicit-any', 'tests/c.test.js:eslint:no-unused-vars'], $this->found($this->root . '/e0.ndjson'));
        [$files, $ignores] = $runner->patterns($this->matcher());
        $p15 = hash('sha256', json_encode([
            'base' => json_decode((string) file_get_contents(PJG_ROOT . '/config/eslint.default.json'), true), 'files' => $files, 'ignores' => $ignores,
            'script' => hash_file('sha256', PJG_ROOT . '/tools/eslint/pjg.flat.config.cjs'), 'formatter' => hash_file('sha256', PJG_ROOT . '/tools/eslint/ndjson-formatter.cjs'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame($p15, $base->configHash, 'P15 계산식 그대로');

        $set = ['enable' => ['eqeqeq', '@typescript-eslint/explicit-function-return-type'], 'disable' => ['no-unused-vars', '@typescript-eslint/no-explicit-any']];
        $over = $runner->run($tool, $dir, $this->matcher(), $this->root . '/e1.ndjson', null, null, $set);
        $this->assertTrue($over->ok, (string) $over->detail);
        $this->assertSame(['a.js:eslint:eqeqeq', 'b.ts:eslint:@typescript-eslint/explicit-function-return-type'], $this->found($this->root . '/e1.ndjson'),
            '끈 규칙은 테스트 파일 overrides 위에서도 꺼짐, TS 규칙은 TS 파일에만');
        $this->assertTrue($over->configHash !== $p15);
        // 안전망: 타입 정보가 필요한 규칙·없는 규칙을 켜도 분석은 실패하지 않는다(서버는 애초에 넣지 않음)
        $safe = $runner->run($tool, $dir, $this->matcher(), $this->root . '/e2.ndjson', null, null, ['enable' => ['@typescript-eslint/await-thenable', 'no-such-rule'], 'disable' => []]);
        $this->assertTrue($safe->ok, (string) $safe->detail);
        $this->assertSame($this->found($this->root . '/e0.ndjson'), $this->found($this->root . '/e2.ndjson'));
    }

    /** 켤 수 없는 규칙(rule_enable_blocks)은 실제 도구 meta와 resources/rules/requires_options.json으로 적재 때 채워진다 */
    public function testEnableBlocksFromRealTools(): void
    {
        if ($this->ruff() === null || $this->eslint() === null) {
            $this->skip('bin/ruff 또는 bin/eslint·Node 없음');
            return;
        }
        $report = $this->app->ruleLoader()->load('default', ['ruff', 'eslint']);
        $this->assertSame([], $report['errors']);
        $count = fn (string $tool, string $version, string $reason): int => (int) $this->app->db()->value(
            'SELECT COUNT(*) FROM rule_enable_blocks WHERE tool = ? AND version = ? AND reason = ?', [$tool, $version, $reason]);
        $this->assertSame(3, $count('ruff', '0.16.10', 'requires_options'), 'TID251·TID253·ICN003');
        $this->assertSame(0, $count('ruff', '0.16.10', 'requires_type_info'));
        $this->assertSame(63, $count('eslint', '10.11.0', 'requires_type_info'), '@typescript-eslint 8.71.0 meta.docs.requiresTypeChecking');
        $this->assertSame(9, $count('eslint', '10.11.0', 'requires_options'));
        // 적재를 다시 해도(내용 해시 같음) 같은 행
        $this->app->ruleLoader()->load('default', ['ruff']);
        $this->assertSame(3, $count('ruff', '0.16.10', 'requires_options'));
    }

    /** P22C: 실제 ruff 규칙 데이터로 D 접두사 일괄 켜기 → 켜진 규칙 집합·설정 해시가 하나씩 켰을 때와 같다 */
    public function testBulkPrefixEnableMatchesIndividualChanges(): void
    {
        $tool = $this->ruff();
        if ($tool === null) {
            $this->skip('bin/ruff 없음');
            return;
        }
        $db = $this->app->db();
        $this->assertSame([], $this->app->ruleLoader()->load('default', ['ruff'])['errors']);
        $user = $this->app->users()->get(TestDb::user($db, 'admin', 'admin'));
        $o = $this->app->ruleOverrides();
        $f = \PJGuardian\Services\Rules\RuleQuery::filter(['tool' => 'ruff', 'prefix' => 'D']);
        $resolve = fn (): array => $this->app->ruleQuery()->matching($f, $o->bulkMax());
        $pv = $o->bulkPreview('ruff', 'enable', $resolve);
        $r = $o->bulkApply($user, null, 'ruff', 'enable', $resolve, ['mode' => 'filter'], 'test', $pv['revision']);
        $this->assertSame([$pv['changes'], $pv['skipped']], [$r['changes'], $r['skipped']]);
        $this->assertTrue($r['changes'] > 40, 'pydocstyle D 규칙 다수: ' . $r['changes']);
        $bulkSet = $o->snapshotFor('ruff', '0.16.10');
        // 실제 ruff에서 켜진 규칙 집합: 기본 ∪ (켤 수 있는 D 규칙)
        $default = (string) realpath(PJG_ROOT . '/config/ruff.default.toml');
        $file = $this->root . '/bulk.toml';
        file_put_contents($file, EffectiveRuleConfig::ruffToml($default, EffectiveRuleConfig::canonical('ruff', $bulkSet)));
        $dir = $this->project(['a.py' => "x = 1\n"]);
        $enabled = function (string $config) use ($tool, $dir): array {
            $p = $this->app->toolRunner()->run([$tool->executable, 'check', '--show-settings', '--no-cache', '--config', $config, $dir . '/a.py'], new ProcessOptions(timeoutSeconds: 60));
            $out = $p->stdout();
            $p->cleanup();
            $start = (int) strpos($out, 'linter.rules.enabled = [');
            preg_match_all('/\(([A-Z]+[0-9]+)\)/', substr($out, $start, (int) strpos($out, ']', $start) - $start), $m);
            return $m[1];
        };
        $on = $enabled($file);
        $d = array_values(array_filter($on, static fn (string $c): bool => str_starts_with($c, 'D')));
        sort($d);
        // ruff는 서로 맞지 않는 규칙 쌍(D203↔D211, D212↔D213)을 함께 켜면 D203·D213을 스스로 끈다(ruff 동작 — 보고서 남은 문제)
        $this->assertSame(['D203', 'D213'], array_values(array_diff($bulkSet['enable'], $d)), '켜진 D 규칙 = 일괄로 켠 규칙 − ruff 비호환 쌍');
        $this->assertSame([], array_values(array_diff($d, $bulkSet['enable'])));
        $this->assertSame(count($enabled($default)) + count($bulkSet['enable']) - 2, count($on));
        $matcher = $this->matcher();
        $bulkHash = RuffRunner::configHash([$default], implode(',', $matcher->defaults()), EffectiveRuleConfig::canonical('ruff', $bulkSet));
        // 같은 규칙을 하나씩 켜면 같은 실효 설정·같은 해시
        $o->bulkApply($user, null, 'ruff', 'reset', fn (): array => $this->app->ruleQuery()->matching(\PJGuardian\Services\Rules\RuleQuery::filter(['tool' => 'ruff', 'overridden' => '1']), 1000), ['mode' => 'filter'], null, $o->revision('ruff'));
        $this->assertSame(null, $o->snapshotFor('ruff', '0.16.10'));
        foreach ($bulkSet['enable'] as $rule) {
            $o->set($user, null, 'ruff', $rule, true, false, null);
        }
        $one = $o->snapshotFor('ruff', '0.16.10');
        $this->assertSame([$bulkSet['enable'], $bulkSet['disable']], [$one['enable'], $one['disable']]);
        $this->assertSame($bulkHash, RuffRunner::configHash([$default], implode(',', $matcher->defaults()), EffectiveRuleConfig::canonical('ruff', $one)));
    }

    /** P22C: 실제 ESLint 규칙 데이터로 키 목록 일괄 끄기 → 실제 분석에서 사라지고 해시가 바뀐다 */
    public function testBulkKeysDisableWithRealEslint(): void
    {
        $tool = $this->eslint();
        if ($tool === null) {
            $this->skip('bin/eslint 또는 Node 없음');
            return;
        }
        $this->assertSame([], $this->app->ruleLoader()->load('default', ['eslint'])['errors']);
        $user = $this->app->users()->get(TestDb::user($this->app->db(), 'admin', 'admin'));
        $o = $this->app->ruleOverrides();
        $keys = ['no-unused-vars', '@typescript-eslint/no-explicit-any', '@typescript-eslint/await-thenable', 'no-such-rule'];
        $resolve = fn (): array => $o->rowsForKeys('eslint', $keys);
        $pv = $o->bulkPreview('eslint', 'disable', $resolve);
        $this->assertSame([4, 2, 1, 1], [$pv['total'], $pv['changes'], $pv['unchanged'], $pv['skipped']['missing']], 'await-thenable은 이미 꺼짐, 없는 키는 건너뜀');
        $o->bulkApply($user, null, 'eslint', 'disable', $resolve, ['mode' => 'keys'], null, $pv['revision']);
        $dir = $this->project(['a.js' => "var unusedVar = 1;\n", 'b.ts' => "const x: any = 1;\nexport const y = x;\n"]);
        $runner = $this->app->eslintRunner();
        $base = $runner->run($tool, $dir, $this->matcher(), $this->root . '/b0.ndjson');
        $this->assertSame(['a.js:eslint:no-unused-vars', 'b.ts:eslint:@typescript-eslint/no-explicit-any'], $this->found($this->root . '/b0.ndjson'));
        $after = $runner->run($tool, $dir, $this->matcher(), $this->root . '/b1.ndjson', null, null, $o->snapshotFor('eslint', '10.11.0'));
        $this->assertTrue($after->ok, (string) $after->detail);
        $this->assertSame([], $this->found($this->root . '/b1.ndjson'));
        $this->assertTrue($after->configHash !== $base->configHash);
    }

    public function testAnalysisPipelineUsesSnapshotAndMarksConfigChange(): void
    {
        $tool = $this->ruff();
        if ($tool === null) {
            $this->skip('bin/ruff 없음');
            return;
        }
        $db = $this->app->db();
        $report = $this->app->ruleLoader()->load('default', ['ruff']);
        $this->assertSame([], $report['errors']);
        $admin = TestDb::user($db, 'admin', 'admin');
        $user = $this->app->users()->get($admin);
        $project = TestDb::project($db, 'py', 'python');
        $work = $this->root . '/work/abcd0002';
        Fs::ensureDir($work);
        file_put_contents($work . '/a.py', self::PY);
        // 결과 파일 이름에만 쓰는 분석 id(실행기는 analyses 행을 읽지 않는다)
        $run = function (int $aid) use ($project, $work): array {
            $o = $this->app->analysisRunner()->run(new AnalysisJob($aid, $project, 'ruff', $work, []));
            $this->assertTrue($o->succeeded, (string) $o->failureCode . ' ' . $o->detail);
            return ['found' => $this->found(ToolAnalysisRunner::resultFile($this->app->resultsDir(), $aid)), 'tool' => $o->tool];
        };
        $r0 = $run(9001);
        $this->assertSame(['a.py:ruff:E501', 'a.py:ruff:F401'], $r0['found']);
        $this->app->ruleOverrides()->set($user, null, 'ruff', 'E501', false, true, 'test');
        $r1 = $run(9002);
        $this->assertSame(['a.py:ruff:F401'], $r1['found'], '다음 분석부터 반영');
        $diff = AnalysisComparison::differences(
            ['tool' => 'ruff', 'tool_version' => $r0['tool']['tool_version'], 'config_source' => $r0['tool']['config_source'], 'config_hash' => $r0['tool']['config_hash']],
            ['tool' => 'ruff', 'tool_version' => $r1['tool']['tool_version'], 'config_source' => $r1['tool']['config_source'], 'config_hash' => $r1['tool']['config_hash']],
        );
        $this->assertSame(['config_hash'], array_keys($diff), '"설정 변경"으로 보임');
        // 되돌리면 해시도 처음과 같다
        $this->app->ruleOverrides()->clear($user, null, 'ruff', 'E501');
        $r2 = $run(9003);
        $this->assertSame([$r0['found'], $r0['tool']['config_hash']], [$r2['found'], $r2['tool']['config_hash']]);
    }
}
