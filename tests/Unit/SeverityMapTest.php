<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Services\Process\ProcessOptions;
use PJGuardian\Services\Process\ProcessRunner;
use PJGuardian\Services\Severity\SeverityMap;
use PJGuardian\Services\Severity\SeverityMapException;
use PJGuardian\Tests\TestCase;

/**
 * 규칙 기본 심각도 매핑(P21S): 우선순위(개별 > 접두사 > 분류군 > not_defined), 규칙 키 형식(도구 접두·대소문자·null·syntax-error),
 * ruff 접두사 경계(D ≠ DJ), 대표 규칙 기대값, 데이터 파일 형식 검증, 실제 매핑의 적용 범위(카탈로그 규칙 전부 5단계 중 하나).
 */
final class SeverityMapTest extends TestCase
{
    private function real(): SeverityMap
    {
        return new SeverityMap(PJG_ROOT . '/resources/severity');
    }

    /** @param array<string, mixed> $ruff @param array<string, mixed> $catalog */
    private function fake(array $ruff, array $catalog = ['F401' => ['group' => 'suspicious'], 'F821' => ['group' => 'correctness'], 'D100' => ['group' => 'pedantic'], 'DJ001' => ['group' => 'suspicious'], 'PLE0101' => ['group' => 'correctness']]): SeverityMap
    {
        $dir = $this->tempDir('sevmap');
        $base = ['tool' => 'ruff', 'mapping_version' => 3, 'defaults' => [], 'prefixes' => [], 'rules' => []];
        file_put_contents("{$dir}/ruff.json", json_encode($ruff + $base));
        file_put_contents("{$dir}/ruff.catalog.json", json_encode(['tool' => 'ruff', 'rules' => $catalog]));
        file_put_contents("{$dir}/eslint.json", json_encode(['tool' => 'eslint'] + $base));
        file_put_contents("{$dir}/eslint.catalog.json", json_encode(['tool' => 'eslint', 'rules' => (object) []]));
        return new SeverityMap($dir);
    }

    public function testPriorityRulePrefixGroupNone(): void
    {
        $m = $this->fake([
            'defaults' => [['group' => 'suspicious', 'severity' => 'medium', 'reason' => 'g'], ['group' => 'correctness', 'severity' => 'high', 'reason' => 'g'], ['group' => 'pedantic', 'severity' => 'low', 'reason' => 'g']],
            'prefixes' => [['prefix' => 'F', 'severity' => 'low', 'reason' => 'p'], ['prefix' => 'F8', 'severity' => 'critical', 'reason' => 'p8'], ['prefix' => 'D', 'severity' => 'critical', 'reason' => 'd']],
            'rules' => [['rule' => 'f401', 'severity' => 'high', 'reason' => 'r'], ['rule' => 'syntax-error', 'severity' => 'high', 'reason' => 's']],
        ]);
        $this->assertSame(['severity' => 'high', 'reason' => 'r', 'level' => 'rule', 'group' => 'suspicious'], $m->explain('ruff', 'ruff:F401'), '개별 > 접두사 > 분류군');
        $this->assertSame(['critical', 'prefix'], [$m->explain('ruff', 'ruff:F821')['severity'], $m->explain('ruff', 'ruff:F821')['level']], '가장 긴 접두사 F8 > F, 접두사 > 분류군');
        $this->assertSame('critical', $m->forRule('ruff', 'ruff:D100'), 'D는 D100에 맞음');
        $this->assertSame(['medium', 'group'], [$m->forRule('ruff', 'ruff:DJ001'), $m->explain('ruff', 'ruff:DJ001')['level']], 'D는 DJ001에 맞지 않음(분류군)');
        $this->assertSame(['high', 'group'], [$m->forRule('ruff', 'ruff:PLE0101'), $m->explain('ruff', 'ruff:PLE0101')['level']]);
        $this->assertSame(['not_defined', 'none'], [$m->forRule('ruff', 'ruff:ZZZ999'), $m->explain('ruff', 'ruff:ZZZ999')['level']], '모르는 규칙');
        $this->assertSame('high', $m->forRule('ruff', null), '규칙 키 null = 구문 오류');
        $this->assertSame('high', $m->forRule('ruff', 'syntax-error'));
        $this->assertSame('high', $m->forRule('ruff', 'ruff:f401'), 'ruff 코드는 대소문자 무시');
        $this->assertSame('high', $m->forRule('ruff', 'F401'), '도구 접두 없이도');
        $this->assertSame(3, $m->version('ruff'));
        $this->assertSame('not_defined', $m->forRule('eslint', 'eslint:no-undef'), '빈 ESLint 매핑');
    }

    public function testRepresentativeRules(): void
    {
        $m = $this->real();
        $ruff = [
            'ruff:S105' => 'critical', 'ruff:S608' => 'critical', 'ruff:S307' => 'critical', 'ruff:S301' => 'critical', 'ruff:S602' => 'critical', 'ruff:PLE2502' => 'critical',
            'ruff:F821' => 'high', 'ruff:F632' => 'high', 'ruff:B023' => 'high', 'ruff:S311' => 'high', 'syntax-error' => 'high',
            'ruff:F401' => 'medium', 'ruff:F841' => 'medium', 'ruff:B006' => 'medium', 'ruff:C901' => 'medium', 'ruff:E722' => 'medium', 'ruff:E721' => 'medium',
            'ruff:E501' => 'low', 'ruff:E731' => 'low', 'ruff:E741' => 'low', 'ruff:I001' => 'low', 'ruff:N802' => 'low', 'ruff:D100' => 'low', 'ruff:UP006' => 'low', 'ruff:Q000' => 'low',
        ];
        foreach ($ruff as $k => $v) {
            $this->assertSame($v, $m->forRule('ruff', $k), $k);
        }
        $eslint = [
            'eslint:no-eval' => 'critical', 'eslint:no-new-func' => 'critical', 'eslint:no-implied-eval' => 'critical', 'eslint:@typescript-eslint/no-implied-eval' => 'critical', 'eslint:no-script-url' => 'critical',
            'eslint:no-undef' => 'high', 'eslint:no-dupe-keys' => 'high', 'eslint:no-unreachable' => 'high', 'eslint:valid-typeof' => 'high', 'eslint:@typescript-eslint/no-floating-promises' => 'high', 'syntax-error' => 'high',
            'eslint:no-unused-vars' => 'medium', 'eslint:@typescript-eslint/no-unused-vars' => 'medium', 'eslint:eqeqeq' => 'medium', 'eslint:@typescript-eslint/no-explicit-any' => 'medium', 'eslint:complexity' => 'medium',
            'eslint:prefer-const' => 'low', 'eslint:no-var' => 'low', 'eslint:camelcase' => 'low', 'eslint:semi' => 'low', 'eslint:@typescript-eslint/array-type' => 'low', 'eslint:unused-disable-directive' => 'low',
        ];
        foreach ($eslint as $k => $v) {
            $this->assertSame($v, $m->forRule('eslint', $k), $k);
        }
        $this->assertSame('not_defined', $m->forRule('eslint', 'eslint:some-plugin/unknown-rule'), '모르는 플러그인');
        $this->assertSame('No-Undef', SeverityMap::ruleName('eslint', 'eslint:No-Undef'), 'ESLint 이름은 대소문자 그대로');
        $this->assertSame('not_defined', $m->forRule('eslint', 'eslint:No-Undef'));
        $this->assertSame('ruff v1, eslint v1', $m->versionLabel());
    }

    public function testRealMappingCoversCatalog(): void
    {
        // 적용 범위 고정: 카탈로그(활성 도구 버전의 규칙 목록)의 모든 규칙이 5단계 중 하나(not_defined 없음)
        $m = $this->real();
        $m->validate();
        foreach (SeverityMap::TOOLS as $tool) {
            $none = [];
            foreach (array_keys($m->catalog($tool)) as $name) {
                if ($m->forRule($tool, "{$tool}:{$name}") === 'not_defined') {
                    $none[] = $name;
                }
            }
            $this->assertSame([], $none, "{$tool} not_defined 규칙");
            $this->assertTrue(count($m->catalog($tool)) > ($tool === 'ruff' ? 900 : 400), "{$tool} 카탈로그 규칙 수");
        }
    }

    /** @needs-bin bin/ruff·bin/eslint 실행 파일과 Node(tools/severity_coverage.php) */
    public function testCoverageToolAgainstInstalledTools(): void
    {
        // 실제 bin/ 도구(읽기만)로 카탈로그가 최신이고 not_defined가 없는지. 도구가 없는 환경이면 건너뛴다(보고서에 표시)
        if (!is_dir(PJG_ROOT . '/bin/ruff') || !is_dir(PJG_ROOT . '/bin/eslint')) {
            $this->assertTrue(true);
            return;
        }
        $r = (new ProcessRunner([], [PHP_BINARY]))->run([PHP_BINARY, PJG_ROOT . '/tools/severity_coverage.php', '--strict'], new ProcessOptions(timeoutSeconds: 180));
        $out = $r->stdout() . $r->stderr();
        $r->cleanup();
        if ($r->exitCode === 2 && str_contains($out, 'bin/에 버전 없음')) {
            $this->assertTrue(true);
            return;
        }
        $this->assertSame(0, $r->exitCode, $out);
        $this->assertTrue(str_contains($out, 'not_defined로 남는 규칙(0)'), $out);
    }

    public function testInvalidFilesFailClearly(): void
    {
        $ok = ['defaults' => [], 'prefixes' => [], 'rules' => [['rule' => 'F401', 'severity' => 'high', 'reason' => 'r']]];
        $cases = [
            '잘못된 심각도' => [['rules' => [['rule' => 'F401', 'severity' => 'urgent', 'reason' => 'r']]] + $ok, '잘못된 심각도'],
            '중복 키(대소문자)' => [['rules' => [['rule' => 'F401', 'severity' => 'high', 'reason' => 'r'], ['rule' => 'f401', 'severity' => 'low', 'reason' => 'r']]] + $ok, '중복 키 F401'],
            '버전 없음' => [['mapping_version' => null] + $ok, 'mapping_version'],
            '근거 없음' => [['rules' => [['rule' => 'F401', 'severity' => 'high']]] + $ok, '근거'],
            '목록 아님' => [['rules' => ['F401' => ['severity' => 'high', 'reason' => 'r']]] + $ok, '목록'],
            '도구 다름' => [['tool' => 'eslint'] + $ok, 'tool'],
        ];
        foreach ($cases as $label => [$data, $needle]) {
            $m = $this->fake($data);
            try {
                $m->forRule('ruff', 'ruff:F401');
                $this->fail("{$label}: 예외가 나야 함");
            } catch (SeverityMapException $e) {
                $this->assertTrue(str_contains($e->getMessage(), $needle), "{$label}: {$e->getMessage()}");
            }
        }
        $dir = $this->tempDir('sevmap-bad');
        file_put_contents("{$dir}/ruff.json", '{ "tool": "ruff", ');
        $this->assertThrows(SeverityMapException::class, static fn () => (new SeverityMap($dir))->validate(), 'JSON 형식 오류');
        $empty = $this->tempDir('sevmap-none');
        $this->assertThrows(SeverityMapException::class, static fn () => (new SeverityMap($empty))->validate(), '매핑 파일 없음');
    }
}
