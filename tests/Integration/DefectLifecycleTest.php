<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Services\Analysis\AnalysisOutcome;
use PJGuardian\Services\Defects\DefectIngest;
use PJGuardian\Services\Defects\ScopeList;
use PJGuardian\Services\Defects\SnapshotService;
use PJGuardian\Services\Defects\SourceNormalizer;
use PJGuardian\Services\Events\AnalysisSucceeded;
use PJGuardian\Services\Events\AssignedDefectsChanged;
use PJGuardian\Support\Fs;
use PJGuardian\Tests\Support\DefectHarness;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 결함 저장·시그니처·자동 resolved·재오픈·스냅샷(명세 6, 7, 8, 19.10, P16). 프로토타입(P04) 단위 17 + 시나리오를 DB 통합 시험으로 옮기고
 * python_sample(ruff), js_sample·ts_sample(ESLint)에서 반복한다. 입력은 실제 도구 결과(NDJSON)와 소스 파일.
 */
final class DefectLifecycleTest extends TestCase
{
    private DefectHarness $h;

    public function setUp(): void
    {
        $this->h = new DefectHarness($this->tempDir('defl'));
    }

    private function needTools(): bool
    {
        if (!$this->h->toolsAvailable()) {
            fwrite(STDOUT, "      [건너뜀] bin/ruff·bin/eslint·Node 없음\n");
            return false;
        }
        return true;
    }

    private static function c(array $s): string
    {
        return sprintf('신규 %d, 해결 %d(미감지 %d, 삭제 %d), 재오픈 %d, 유지 %d, 범위 밖 %d', $s['new'], $s['resolved'], $s['resolved_not_detected'],
            $s['resolved_file_deleted'], $s['reopened'], $s['unchanged'], $s['out_of_scope']);
    }

    // ---- 단위(P04 단위 17건) ----

    public function testNormalizationUnits(): void
    {
        $this->assertSame('app/handlers/api.py', SourceNormalizer::path('app\\handlers\\api.py'));
        $this->assertSame('app/models.py', SourceNormalizer::path('./app//models.py'));
        $nfd = (string) \Normalizer::normalize('utils/한글_도구.py', \Normalizer::FORM_D);
        $this->assertSame('utils/한글_도구.py', SourceNormalizer::path($nfd));
        $this->assertSame('App/Models.py', SourceNormalizer::path('App/Models.py'), '대소문자 그대로');
        $this->assertThrows(\InvalidArgumentException::class, static fn () => SourceNormalizer::path('a/../b.py'));
        $this->assertSame('if x == None:', SourceNormalizer::normalizeLines(["  \tif  x ==   None:  "]));
        $this->assertSame("a = 1\nb = 2", SourceNormalizer::normalizeLines(['a = 1 ', '  b =  2']));
        $this->assertSame('메시지 = "안녕하세요"', SourceNormalizer::normalizeLines(['  메시지 = "안녕하세요"  ']), '"녕"(EB 85 95)의 0x85를 공백으로 보지 않음');
        $this->assertSame("x\u{00A0}y", SourceNormalizer::normalizeLines(["x\u{00A0}y"]), 'NBSP는 공백으로 보지 않음');
        $this->assertSame(['x = 1', 'y = 2', ''], SourceNormalizer::splitLines("\xEF\xBB\xBFx = 1\r\ny = 2\r\n", 'ruff')['lines'], 'BOM + CRLF');
        $this->assertSame(['a', 'b', 'c'], SourceNormalizer::splitLines("a\rb\nc", 'ruff')['lines'], '단독 CR');
        $this->assertSame(['a', 'b', 'c'], SourceNormalizer::splitLines("a\u{2028}b\nc", 'eslint')['lines'], 'ESLint는 U+2028도 줄바꿈');
        $this->assertSame(["a\u{2028}b", 'c'], SourceNormalizer::splitLines("a\u{2028}b\nc", 'ruff')['lines'], 'ruff는 아님');
        $cp = SourceNormalizer::splitLines("x = 1  # \xC7\xD1\xB1\xDB\n", 'ruff');
        $this->assertSame([false, "x = 1  # \xC7\xD1\xB1\xDB"], [$cp['utf8'], $cp['lines'][0]], 'UTF-8이 아니면 바이트 그대로(결정적)');
        $lines = ['a', 'b', 'c'];
        $this->assertSame('b', SourceNormalizer::codeOf($lines, 2, null), 'end_line 없으면 line만');
        $this->assertSame('b', SourceNormalizer::codeOf($lines, 2, 1), 'end_line < line이면 line만');
        $this->assertSame("b\nc\n", SourceNormalizer::codeOf($lines, 2, 4), '파일 끝을 넘는 줄은 빈 문자열');
        $this->assertSame('', SourceNormalizer::codeOf($lines, 99, 99));
        $this->assertSame(['text' => "a\u{FFFD}b", 'replaced' => true], SourceNormalizer::toUtf8Text("a\xC7b"));
        $s = ScopeList::fromPaths(['b.py', 'a.py', './a.py', $nfd]);
        $this->assertSame(['a.py', 'b.py', 'utils/한글_도구.py'], $s->paths());
        $this->assertTrue($s->contains('utils/한글_도구.py') && !$s->contains('c.py'));
        $this->assertSame($s->paths(), ScopeList::decode($s->encode())->paths());
        $this->assertSame(null, ScopeList::decode(''), '빈 BLOB = 정리됨');
    }

    // ---- 시나리오(Python / JavaScript / TypeScript) ----

    /**
     * 공통 시나리오. $v: sample, tool, language, total, shift(파일 목록), indent(fn), change([파일, from, to]), dup(파일, 내용, 가운데 고침 fn),
     * delete([파일, 건수]), exclude([패턴, 폴더, 건수]), nfd([파일]|null)
     *
     * @return list<string> 보고용 줄
     */
    private function scenarios(array $v): array
    {
        $h = $this->h;
        $report = [];
        $base = $h->copySample($v['sample']);
        $fresh = function (string $name) use ($h, $v): int {
            $pid = $h->project($v['sample'] . '-' . $name, $v['language']);
            return $pid;
        };

        // 첫 분석: 전체 신규, 비교 대상 없음
        $p = $fresh('base');
        $s0 = $h->analyze($p, $v['tool'], $base);
        $this->assertSame([$v['total'], $v['total'], 0, null], [$s0['total'], $s0['new'], $s0['resolved'], $s0['baseline']], "{$v['sample']} 첫 분석");
        $d = $h->defects($p)[0];
        // P21S: 신규 결함의 심각도 = 그 규칙의 기본 심각도(출처 auto). 매핑에 없으면 not_defined(none)
        $sev = $h->app->severityMap()->forRule((string) $d['tool'], (string) $d['rule_key']);
        $this->assertSame([$sev, $sev === 'not_defined' ? 'none' : 'auto', 'open', null, 1], [$d['severity'], $d['severity_source'], $d['status'], $d['assignee_id'], (int) $d['version']], '신규 기본값');
        foreach ($h->defects($p) as $x) {
            $this->assertSame($h->app->severityMap()->forRule((string) $x['tool'], (string) $x['rule_key']), $x['severity'], "신규 {$x['rule_key']}");
        }
        $this->assertSame(0, (int) $h->app->db()->value("SELECT COUNT(*) FROM defect_history WHERE field = 'severity'"), '신규 부여는 이력을 남기지 않음');
        // 1. 같은 코드 두 번(재업로드): 신규 0·해결 0, 분석 레코드는 생김
        $s1 = $h->analyze($p, $v['tool'], $base);
        $this->assertSame([0, 0, $v['total']], [$s1['new'], $s1['resolved'], $s1['unchanged']], "{$v['sample']} 1. " . self::c($s1));
        $this->assertSame(2, (int) $h->app->db()->value("SELECT COUNT(*) FROM analyses WHERE project_id = ? AND status = 'succeeded'", [$p]));
        $report[] = '1 같은 코드: ' . self::c($s1);

        $variant = function (string $name, callable $mutate, callable $check) use ($h, $v, $fresh, &$report): void {
            $pid = $fresh($name);
            $dir = $h->copySample($v['sample']);
            $h->analyze($pid, $v['tool'], $dir);
            $mutate($dir, $pid);
            $s = $h->analyze($pid, $v['tool'], $dir);
            $check($s, $pid);
            $report[] = $name . ': ' . self::c($s);
        };
        $zero = fn (string $what) => fn (array $s) => $this->assertSame([0, 0], [$s['new'], $s['resolved']], "{$v['sample']} {$what}: " . self::c($s));

        // 2. 줄 밀림(맨 위 주석·빈 줄)
        $variant('2 줄 밀림', function (string $dir) use ($v): void {
            foreach ($v['shift'] as $f) {
                DefectHarness::rewrite("{$dir}/{$f}", static fn (string $s): string => $v['comment'] . " 추가된 주석\n\n" . $v['comment'] . " 또 다른 주석\n" . $s);
            }
        }, $zero('줄 밀림'));
        // 3. 들여쓰기·공백
        $variant('3 공백·들여쓰기', fn (string $dir) => ($v['indent'])($dir), $zero('공백'));
        // 6. CRLF
        $variant('6 CRLF', function (string $dir): void {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) {
                    DefectHarness::rewrite($f->getPathname(), static fn (string $s): string => str_replace("\n", "\r\n", str_replace("\r\n", "\n", $s)));
                }
            }
        }, $zero('CRLF'));
        // 4. 결함 줄 내용 수정 → 해결 1 + 신규 1(알려진 한계)
        [$cf, $from, $to, $rule] = $v['change'];
        $variant('4 내용 수정', fn (string $dir) => DefectHarness::rewrite("{$dir}/{$cf}", static fn (string $s): string => str_replace($from, $to, $s)),
            function (array $s, int $pid) use ($cf, $rule, $v): void {
                $this->assertSame([1, 1], [$s['new'], $s['resolved']], "{$v['sample']} 4: " . self::c($s));
                $res = $this->h->app->db()->one("SELECT file_path, rule_key FROM defects WHERE project_id = ? AND status = 'resolved'", [$pid]);
                $this->assertSame([$cf, $rule], [$res['file_path'], $res['rule_key']]);
            });
        // 8. 파일 삭제 → file_deleted
        [$df, $dn] = $v['delete'];
        $variant('8 파일 삭제', fn (string $dir) => unlink("{$dir}/{$df}"), function (array $s, int $pid) use ($dn, $df, $v): void {
            $this->assertSame([0, $dn, $dn], [$s['new'], $s['resolved'], $s['resolved_file_deleted']], "{$v['sample']} 8: " . self::c($s));
            $reasons = $this->h->app->db()->all("SELECT DISTINCT h.reason_code FROM defect_history h JOIN defects d ON d.id = h.defect_id WHERE d.project_id = ? AND d.file_path = ?", [$pid, $df]);
            $this->assertSame(['file_deleted'], array_column($reasons, 'reason_code'));
        });
        // 9. 제외 대상 → 범위 밖(상태 유지). 해제 단계처럼 제외 폴더는 작업 디렉토리에 없다
        [$pat, $folder, $en] = $v['exclude'];
        $variant('9 제외 대상', function (string $dir, int $pid) use ($pat, $folder): void {
            $this->h->setExcludes($pid, [$pat]);
            Fs::removeTree("{$dir}/{$folder}");
        }, function (array $s, int $pid) use ($en, $v): void {
            $this->assertSame([0, 0, $en], [$s['new'], $s['resolved'], $s['out_of_scope']], "{$v['sample']} 9: " . self::c($s));
            $this->assertSame(['open' => $v['total']], $this->h->statusCounts($pid));
        });
        // 5·10. 같은 결함 줄 3개 → 순번 0·1·2, 가운데만 고침 → 마지막 순번이 해결(순번 한계, 있는 그대로 기록)
        if ($v['dup'] !== null) {
            [$dupFile, $dupContent, $fixMiddle] = $v['dup'];
            $pid = $fresh('dup');
            $dir = $h->copySample($v['sample']);
            file_put_contents("{$dir}/{$dupFile}", $dupContent);
            $h->analyze($pid, $v['tool'], $dir);
            $dup = $h->app->db()->all('SELECT id, signature, ordinal, line_no FROM defects WHERE project_id = ? AND file_path = ? ORDER BY line_no', [$pid, $dupFile]);
            $this->assertSame(3, count($dup), "{$v['sample']} 5");
            $this->assertSame([1, [0, 1, 2]], [count(array_unique(array_column($dup, 'signature'))), array_map('intval', array_column($dup, 'ordinal'))], '시그니처 1개, 순번 0·1·2');
            $h->app->db()->execute("UPDATE defects SET status = 'intentional' WHERE id = ?", [(int) $dup[1]['id']]); // 가운데 줄(이전 순번 1)을 의도됨으로
            DefectHarness::rewrite("{$dir}/{$dupFile}", $fixMiddle);
            $s = $h->analyze($pid, $v['tool'], $dir);
            $after = $h->app->db()->all('SELECT id, ordinal, line_no, status FROM defects WHERE project_id = ? AND file_path = ? ORDER BY ordinal', [$pid, $dupFile]);
            $this->assertSame([0, 1], [$s['new'], $s['resolved']], "{$v['sample']} 10: " . self::c($s));
            $states = array_map(static fn (array $r): string => "#{$r['ordinal']} L{$r['line_no']} {$r['status']}", $after);
            // 있는 그대로: 해결된 것은 "순번 2"(원래 마지막 줄) 레코드, 가운데 줄의 intentional은 남은 마지막 줄(순번 1)로 옮겨 감
            $this->assertSame((int) $dup[2]['id'], (int) $h->app->db()->value("SELECT id FROM defects WHERE project_id = ? AND file_path = ? AND status = 'resolved'", [$pid, $dupFile]));
            $this->assertSame('intentional', $after[1]['status']);
            $report[] = '10 가운데만 고침: ' . self::c($s) . ' → ' . implode(', ', $states);
        }
        // 11. 한글 파일명·주석 NFC → NFD
        if ($v['nfd'] !== null) {
            $nfcName = $v['nfd'];
            $variant('11 NFD', function (string $dir) use ($nfcName): void {
                $nfdName = (string) \Normalizer::normalize($nfcName, \Normalizer::FORM_D);
                rename("{$dir}/{$nfcName}", "{$dir}/{$nfdName}");
                DefectHarness::rewrite("{$dir}/{$nfdName}", static fn (string $s): string => (string) \Normalizer::normalize($s, \Normalizer::FORM_D));
            }, $zero('NFD'));
        }
        return $report;
    }

    public function testPythonScenarios(): void
    {
        if (!$this->needTools()) {
            return;
        }
        $r = $this->scenarios([
            'sample' => 'python_sample', 'tool' => 'ruff', 'language' => 'python', 'total' => 19, 'comment' => '#',
            'shift' => ['app/models.py', 'main.py', 'utils/helpers.py'],
            'indent' => static function (string $dir): void {
                DefectHarness::rewrite("{$dir}/config_loader.py", static function (string $s): string {
                    $s = (string) preg_replace_callback('/^( +)/m', static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)), $s);
                    return str_replace('if settings == None:', 'if settings  ==   None:   ', $s);
                });
                DefectHarness::rewrite("{$dir}/app/services.py", static fn (string $s): string => str_replace("import json\n", "import json   \t\n", $s));
            },
            'change' => ['utils/helpers.py', 'return value == None', 'return (value) == None', 'ruff:E711'],
            'dup' => ['dup3.py', "\"\"\"같은 결함 줄 3개.\"\"\"\n\n\ndef f():\n    print(missing_name)  \n    print(missing_name)\n    print(missing_name)\n",
                static function (string $s): string {
                    $l = explode("\n", $s);
                    $l[5] = '    print("fixed")';
                    return implode("\n", $l);
                }],
            'delete' => ['main.py', 3],
            'exclude' => ['utils/', 'utils', 4],
            'nfd' => 'utils/한글_도구.py',
        ]);
        // 7. 최상위 폴더만 다른 zip(my_project_v2/): 해제 단계(SafeUnzip, P13)가 한 번만 제거하고 여기서는 다시 제거하지 않는다
        $h = $this->h;
        $pid = $h->project('top', 'python');
        $h->analyze($pid, 'ruff', $h->copySample('python_sample'));
        $dest = $h->root . '/top_extract';
        $unzip = new \PJGuardian\Services\Archive\SafeUnzip(\PJGuardian\Services\Archive\UnzipLimits::fromConfig($h->app->config), $h->app->projects()->matcherFor($pid));
        $x = $unzip->extract(PJG_ROOT . '/samples/zips/python_sample_toplevel.zip', $dest, true);
        $s7 = $h->analyze($pid, 'ruff', $x['dest']);
        $this->assertSame(['my_project_v2/', 0, 0], [$x['stripped_prefix'], $s7['new'], $s7['resolved']], '7. ' . self::c($s7));
        $r[] = '7 최상위 폴더 zip: ' . self::c($s7);
        fwrite(STDOUT, '      [Python] ' . implode(' / ', $r) . "\n");
    }

    public function testJavaScriptScenarios(): void
    {
        if (!$this->needTools()) {
            return;
        }
        // 한글 파일명·주석 시료를 더한 JS 작업 디렉토리
        $r = $this->scenarios([
            'sample' => 'js_sample', 'tool' => 'eslint', 'language' => 'javascript', 'total' => 5, 'comment' => '//',
            'shift' => ['src/index.js', 'src/lib/storage.js'],
            'indent' => static function (string $dir): void {
                DefectHarness::rewrite("{$dir}/src/lib/storage.js", static fn (string $s): string => str_replace("  const unusedPrefix = 'pjg:';", "\t\tconst   unusedPrefix   =   'pjg:';  ", $s));
                DefectHarness::rewrite("{$dir}/src/index.js", static fn (string $s): string => str_replace('  return undefinedRuntime.start();', "        return   undefinedRuntime.start();\t", $s));
            },
            'change' => ['src/lib/storage.js', "const unusedPrefix = 'pjg:';", "let unusedPrefix = 'pjg:';", 'eslint:no-unused-vars'],
            'dup' => ['src/dup3.js', "// 같은 결함 줄 3개\nexport function f() {\n  console.log(missingName);\n  console.log(missingName);\n  console.log(missingName);\n}\n",
                static function (string $s): string {
                    $l = explode("\n", $s);
                    $l[3] = '  console.log("fixed");';
                    return implode("\n", $l);
                }],
            'delete' => ['src/index.js', 2],
            'exclude' => ['src/lib/', 'src/lib', 2],
            'nfd' => null,
        ]);
        // 11(ESLint): 한글 파일명·주석 NFD
        $h = $this->h;
        $pid = $h->project('js-nfd', 'javascript');
        $dir = $h->copySample('js_sample');
        file_put_contents("{$dir}/src/한글_모듈.js", "// 한글 주석: 안녕하세요\nconst 사용안함 = '값';\nexport const 메시지 = '안녕';\n");
        $first = $h->analyze($pid, 'eslint', $dir);
        $nfdName = (string) \Normalizer::normalize('src/한글_모듈.js', \Normalizer::FORM_D);
        rename("{$dir}/src/한글_모듈.js", "{$dir}/{$nfdName}");
        DefectHarness::rewrite("{$dir}/{$nfdName}", static fn (string $s): string => (string) \Normalizer::normalize($s, \Normalizer::FORM_D));
        $s = $h->analyze($pid, 'eslint', $dir);
        $this->assertSame([6, 0, 0], [$first['total'], $s['new'], $s['resolved']], 'ESLint 11. NFD: ' . self::c($s));
        $r[] = '11 NFD: ' . self::c($s);
        fwrite(STDOUT, '      [ESLint JS] ' . implode(' / ', $r) . "\n");
    }

    public function testTypeScriptScenarios(): void
    {
        if (!$this->needTools()) {
            return;
        }
        $r = $this->scenarios([
            'sample' => 'ts_sample', 'tool' => 'eslint', 'language' => 'javascript', 'total' => 6, 'comment' => '//',
            'shift' => ['src/api.ts', 'src/store.ts'],
            'indent' => static fn (string $dir) => DefectHarness::rewrite("{$dir}/src/api.ts", static fn (string $s): string => str_replace("  const unusedHeader = 'x-pjg';", "    const  unusedHeader  =  'x-pjg';\t", $s)),
            'change' => ['src/api.ts', "const unusedHeader = 'x-pjg';", "const unusedHeader = 'changed';", 'eslint:@typescript-eslint/no-unused-vars'],
            'dup' => null,
            'delete' => ['src/api.ts', 3],
            'exclude' => ['src/api.ts', 'src/api.ts', 3],
            'nfd' => null,
        ]);
        // 같은 줄의 any 두 개: 같은 시그니처, 순번 0·1(열 순서)
        $rows = $this->h->app->db()->all("SELECT d.signature, d.ordinal, d.col_no FROM defects d JOIN projects p ON p.id = d.project_id"
            . " WHERE p.name = 'ts_sample-base' AND d.file_path = 'src/api.ts' AND d.line_no = 2 ORDER BY d.col_no");
        $this->assertSame([1, [0, 1], [29, 35]], [count(array_unique(array_column($rows, 'signature'))), array_map('intval', array_column($rows, 'ordinal')), array_map('intval', array_column($rows, 'col_no'))]);
        fwrite(STDOUT, '      [ESLint TS] ' . implode(' / ', $r) . "\n");
    }

    // ---- 자동 resolved·재오픈·실패·원자성 ----

    public function testAutoResolveFromEveryStatusRecordsHistoryAndVersion(): void
    {
        if (!$this->needTools()) {
            return;
        }
        $h = $this->h;
        $db = $h->app->db();
        $pid = $h->project('st', 'python');
        $dir = $h->copySample('python_sample');
        $a1 = $h->startAnalysis($pid, 'ruff');
        $h->analyze($pid, 'ruff', $dir, $a1);
        $statuses = ['open', 'in_progress', 'postponed', 'intentional', 'not_fix'];
        $ids = array_map('intval', array_column($db->all("SELECT id FROM defects WHERE project_id = ? AND file_path = 'app/models.py' ORDER BY id", [$pid]), 'id'));
        $this->assertSame(4, count($ids));
        foreach (array_slice($statuses, 1) as $i => $st) {
            $db->execute('UPDATE defects SET status = ?, version = version + 1 WHERE id = ?', [$st, $ids[$i]]);
        }
        $assignee = TestDb::user($db, 'lee', 'user');
        $db->execute('UPDATE defects SET assignee_id = ? WHERE id = ?', [$assignee, $ids[0]]);
        $events = [];
        $h->app->events()->subscribe(AssignedDefectsChanged::class, static function (object $e) use (&$events): void {
            $events[] = $e;
        });
        $before = array_column($db->all('SELECT id, status, version FROM defects WHERE id IN (' . implode(',', $ids) . ') ORDER BY id'), null, 'id');
        // models.py의 결함 줄을 모두 고친다
        DefectHarness::rewrite("{$dir}/app/models.py", static fn (string $s): string => str_replace(['== None', 'missing_logger'], ['is None', 'print'], $s));
        $a2 = $h->startAnalysis($pid, 'ruff');
        $s = $h->analyze($pid, 'ruff', $dir, $a2);
        $this->assertSame([4, 4], [$s['resolved'], $s['resolved_not_detected']], self::c($s));
        foreach ($ids as $id) {
            $row = $db->one('SELECT status, version FROM defects WHERE id = ?', [$id]);
            $hist = $db->one('SELECT old_value, new_value, kind, reason_code, analysis_id, changed_by FROM defect_history WHERE defect_id = ? ORDER BY id DESC LIMIT 1', [$id]);
            $this->assertSame(['resolved', (int) $before[$id]['version'] + 1], [$row['status'], (int) $row['version']], "결함 {$id}");
            $this->assertSame([$before[$id]['status'], 'resolved', 'auto', 'not_detected', $a2, null],
                [$hist['old_value'], $hist['new_value'], $hist['kind'], $hist['reason_code'], (int) $hist['analysis_id'], $hist['changed_by']], '이전 상태가 이력에');
        }
        $this->assertSame(1, count($events));
        $this->assertSame([['defect_id' => $ids[0], 'assignee_id' => $assignee, 'change' => 'resolved', 'reason' => 'not_detected']], $events[0]->changes, '담당 결함 이벤트');
    }

    public function testReopenAfterAutoOrManualResolve(): void
    {
        if (!$this->needTools()) {
            return;
        }
        $h = $this->h;
        $db = $h->app->db();
        $pid = $h->project('re', 'python');
        $dir = $h->copySample('python_sample');
        $h->analyze($pid, 'ruff', $dir);
        $orig = (string) file_get_contents("{$dir}/main.py");
        unlink("{$dir}/main.py");
        $h->analyze($pid, 'ruff', $dir); // main.py 3건 file_deleted
        file_put_contents("{$dir}/main.py", $orig);
        // 다른 결함 하나는 사용자가 수동으로 resolved(코드는 그대로 → 계속 감지)
        $manual = (int) $db->value("SELECT id FROM defects WHERE project_id = ? AND file_path = 'config_loader.py' ORDER BY id LIMIT 1", [$pid]);
        $db->execute("UPDATE defects SET status = 'resolved', version = version + 1 WHERE id = ?", [$manual]);
        $db->execute("INSERT INTO defect_history (defect_id, field, old_value, new_value, changed_by, changed_at, kind) VALUES (?, 'status', 'open', 'resolved', 1, '2026-10-02T12:00:00Z', 'manual')", [$manual]);
        $s = $h->analyze($pid, 'ruff', $dir);
        $this->assertSame([0, 4, 0], [$s['new'], $s['reopened'], $s['resolved']], self::c($s));
        $this->assertSame(['open' => 19], $h->statusCounts($pid));
        $last = $db->one('SELECT old_value, new_value, kind, reason_code FROM defect_history WHERE defect_id = ? ORDER BY id DESC LIMIT 1', [$manual]);
        $this->assertSame(['resolved', 'open', 'auto', 'redetected'], array_values($last), '수동 resolved도 다시 감지되면 재오픈');
        $this->assertSame(3, (int) $db->value("SELECT version FROM defects WHERE id = ?", [$manual]), '수동 1 + 자동 재오픈 1');
    }

    public function testFailedAndCancelledAnalysesChangeNothing(): void
    {
        if (!$this->needTools()) {
            return;
        }
        $h = $this->h;
        $db = $h->app->db();
        $pid = $h->project('fc', 'python');
        $dir = $h->copySample('python_sample');
        $good = $h->startAnalysis($pid, 'ruff');
        $h->analyze($pid, 'ruff', $dir, $good);
        $snap = static fn () => $db->all('SELECT * FROM defects ORDER BY id');
        $before = $snap();
        unlink("{$dir}/main.py");
        // 도구 실패: 결함 반영 없이 failed
        $failed = $h->startAnalysis($pid, 'ruff');
        $this->assertTrue($h->app->analysisQueue()->complete($failed, AnalysisOutcome::failed('tool_crash')));
        // 취소: 저장 직전 취소 요청 확인 → 반영하지 않음
        $cancelled = $h->startAnalysis($pid, 'ruff');
        $o = $h->run($pid, 'ruff', $dir, $cancelled);
        $db->execute('UPDATE analyses SET cancel_requested = 1 WHERE id = ?', [$cancelled]);
        $this->assertSame(null, $h->app->analysisFinalizer()->finalize($cancelled, $db->one('SELECT * FROM analyses WHERE id = ?', [$cancelled]), $dir, $o));
        $h->app->analysisQueue()->markCancelled($cancelled);
        $this->assertSame($before, $snap(), '실패·취소된 분석은 어떤 결함도 바꾸지 않음');
        $this->assertSame(0, (int) $db->value('SELECT COUNT(*) FROM analysis_file_lists WHERE analysis_id IN (?, ?)', [$failed, $cancelled]));
        $this->assertFalse(is_dir($h->app->snapshots()->dirOf($pid, $cancelled)), '취소된 분석의 스냅샷 없음');
        // 다음 성공 분석의 비교 기준은 직전 "성공" 분석(실패·취소 건너뜀)
        $next = $h->startAnalysis($pid, 'ruff');
        $s = $h->analyze($pid, 'ruff', $dir, $next);
        $this->assertSame([$good, 3], [$s['baseline'], $s['resolved_file_deleted']]);
        $this->assertSame($good, (int) $db->value('SELECT baseline_analysis_id FROM analyses WHERE id = ?', [$next]));
    }

    public function testSaveErrorRollsBackEverything(): void
    {
        if (!$this->needTools()) {
            return;
        }
        $h = $this->h;
        $db = $h->app->db();
        $pid = $h->project('atom', 'python');
        $dir = $h->copySample('python_sample');
        $h->analyze($pid, 'ruff', $dir);
        $orig = (string) file_get_contents("{$dir}/main.py");
        unlink("{$dir}/main.py");
        $h->analyze($pid, 'ruff', $dir); // 3건 resolved
        file_put_contents("{$dir}/main.py", $orig); // 다음 분석에서 재오픈될 상태
        $before = $db->all('SELECT * FROM defects ORDER BY id');
        $hist = (int) $db->value('SELECT COUNT(*) FROM defect_history');
        $id = $h->startAnalysis($pid, 'ruff');
        $o = $h->run($pid, 'ruff', $dir, $id);
        // 결과 끝에 규칙 키가 너무 긴 결함(스키마 CHECK 위반) → 신규 삽입 단계(재오픈 뒤)에서 실패
        file_put_contents($o->tool['result_file'], json_encode(['v' => 1, 'tool' => 'ruff', 'language' => 'python', 'rule_key' => 'ruff:' . str_repeat('X', 300),
            'file' => 'zz_last.py', 'line' => 1, 'column' => 1, 'end_line' => 1, 'end_column' => 2, 'message' => 'm', 'syntax_error' => false, 'tool_severity' => null]) . "\n", FILE_APPEND);
        file_put_contents("{$dir}/zz_last.py", "x = 1\n");
        $thrown = null;
        try {
            $h->app->analysisFinalizer()->finalize($id, $db->one('SELECT * FROM analyses WHERE id = ?', [$id]), $dir, $o);
        } catch (\Throwable $e) {
            $thrown = $e;
            $h->app->analysisQueue()->complete($id, AnalysisOutcome::failed('result_save_failed', $e->getMessage()));
        }
        $this->assertTrue($thrown !== null, '저장 오류');
        $this->assertSame($before, $db->all('SELECT * FROM defects ORDER BY id'), '재오픈까지 모두 되돌아감');
        $this->assertSame($hist, (int) $db->value('SELECT COUNT(*) FROM defect_history'));
        $this->assertSame(['failed', 'result_save_failed'], array_values($db->one('SELECT status, failure_code FROM analyses WHERE id = ?', [$id])));
        $this->assertSame(0, (int) $db->value('SELECT COUNT(*) FROM analysis_file_lists WHERE analysis_id = ?', [$id]));
        $this->assertFalse(is_dir($h->app->snapshots()->dirOf($pid, $id)), '만든 스냅샷도 지움');
        $this->assertTrue(is_file($o->tool['result_file']), '저장 실패면 결과 파일을 남김(정리 작업이 1일 뒤 지움)');
    }

    public function testSyntaxErrorsAreDefectsAndAnalysisSucceeds(): void
    {
        if (!$this->needTools()) {
            return;
        }
        $pid = $this->h->project('syn', 'python');
        $id = $this->h->startAnalysis($pid, 'ruff');
        $s = $this->h->analyze($pid, 'ruff', $this->h->copySample('python_syntax_error'), $id);
        $this->assertSame([2, 2], [$s['total'], $s['ingest']['syntax']]);
        $this->assertSame(['syntax-error'], array_values(array_unique(array_column($this->h->defects($pid), 'rule_key'))));
        $this->assertSame('succeeded', $this->h->app->db()->value('SELECT status FROM analyses WHERE id = ?', [$id]));
    }

    public function testNoTargetAnalysisResolvesAllAndUploadConfigScopeKeepsState(): void
    {
        if (!$this->needTools()) {
            return;
        }
        $h = $this->h;
        // 대상 없음(사용자가 확인): 기존 결함 모두 resolved(파일 삭제)
        $pid = $h->project('none', 'python');
        $h->analyze($pid, 'ruff', $h->copySample('python_sample'));
        $s = $h->analyze($pid, 'ruff', $h->copySample('empty_no_target'));
        $this->assertSame([0, 19, 19], [$s['total'], $s['resolved'], $s['resolved_file_deleted']], self::c($s));
        // 업로드 설정(.eslintignore)으로 도구가 보지 않은 파일: 작업 디렉토리에 있으므로 범위 밖(상태 유지)
        $pj = $h->project('eign', 'javascript');
        $dir = $h->copySample('js_sample');
        $h->analyze($pj, 'eslint', $dir);
        file_put_contents("{$dir}/.eslintignore", "src/lib/\n");
        $s2 = $h->analyze($pj, 'eslint', $dir);
        $this->assertSame([0, 0, 2], [$s2['new'], $s2['resolved'], $s2['out_of_scope']], self::c($s2));
        $row = $h->app->db()->one('SELECT exclude_rules, file_count, length(files_blob) AS n FROM analysis_file_lists ORDER BY analysis_id DESC LIMIT 1');
        $rules = json_decode((string) $row['exclude_rules'], true);
        $this->assertSame(['server_default', 'project', 'config_files'], array_keys($rules));
        $this->assertTrue((int) $row['file_count'] > 0 && (int) $row['n'] > 0);
    }

    public function testStatisticsAndEvents(): void
    {
        if (!$this->needTools()) {
            return;
        }
        $h = $this->h;
        $got = [];
        $h->app->events()->subscribe(AnalysisSucceeded::class, static function (object $e) use (&$got): void {
            $got[] = $e;
        });
        $pid = $h->project('stats', 'python');
        $id = $h->startAnalysis($pid, 'ruff');
        $h->analyze($pid, 'ruff', $h->copySample('python_sample'), $id);
        $row = $h->app->db()->one('SELECT total_count, new_count, resolved_count, reopened_count, unchanged_count, severity_counts, baseline_analysis_id, tool_version FROM analyses WHERE id = ?', [$id]);
        $this->assertSame([19, 19, 0, 0, 0, null, '0.16.10'], [(int) $row['total_count'], (int) $row['new_count'], (int) $row['resolved_count'], (int) $row['reopened_count'], (int) $row['unchanged_count'], $row['baseline_analysis_id'], $row['tool_version']]);
        // P21S: 심각도별 건수 = 신규 결함 규칙의 기본 심각도 분포(합계 19)
        $expect = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'not_defined' => 0];
        foreach ($h->defects($pid) as $x) {
            $expect[$h->app->severityMap()->forRule((string) $x['tool'], (string) $x['rule_key'])]++;
        }
        $this->assertSame(19, array_sum($expect));
        $this->assertSame(0, $expect['not_defined'], '샘플 규칙은 모두 매핑됨');
        $this->assertSame($expect, json_decode((string) $row['severity_counts'], true));
        $this->assertSame([1, $id], [count($got), $got[0]->analysisId]);
        $this->assertFalse(is_file($h->app->resultsDir() . "/{$id}.ndjson") || is_file($h->app->resultsDir() . "/{$id}.scope"), '커밋 직후 결과·범위 파일 삭제');
    }

    // ---- 스냅샷 ----

    public function testSnapshotRetentionPrunesOldestWithExcerptsAndEmptiesFileList(): void
    {
        if (!$this->needTools()) {
            return;
        }
        $h = $this->h;
        $db = $h->app->db();
        $pid = $h->project('snap', 'python');
        $dir = $h->copySample('python_sample');
        $ids = [];
        $ids[] = $first = $h->startAnalysis($pid, 'ruff');
        $h->analyze($pid, 'ruff', $dir, $first);
        $zip = $h->app->snapshots()->dirOf($pid, $first) . '/source.zip';
        $this->assertTrue(is_file($zip));
        $snap = $db->one('SELECT storage_path, status, file_count FROM snapshots WHERE analysis_id = ?', [$first]);
        $this->assertSame(["{$pid}/{$first}", 'full'], [$snap['storage_path'], $snap['status']]);
        // 첫 분석 뒤 main.py의 결함 줄을 고쳐 그 결함들의 마지막 감지 분석을 첫 분석에 남긴다
        DefectHarness::rewrite("{$dir}/main.py", static fn (string $s): string => str_replace(['import os', 'import sys', 'undefined_config'], ['', '', 'None'], $s));
        for ($i = 2; $i <= 6; $i++) {
            $ids[] = $a = $h->startAnalysis($pid, 'ruff');
            $h->analyze($pid, 'ruff', $dir, $a);
        }
        $states = array_column($db->all('SELECT analysis_id, status FROM snapshots ORDER BY analysis_id'), 'status', 'analysis_id');
        $this->assertSame(['pruned', 'full', 'full', 'full', 'full', 'full'], array_values($states), '6번째 뒤 가장 오래된 스냅샷 정리');
        $this->assertFalse(is_file($zip), '스냅샷 파일 삭제');
        $this->assertSame(0, (int) $db->value('SELECT length(files_blob) FROM analysis_file_lists WHERE analysis_id = ?', [$first]), '같은 분석의 파일 목록 BLOB도 비움');
        $this->assertTrue((int) $db->value('SELECT file_count FROM analysis_file_lists WHERE analysis_id = ?', [$first]) > 0, 'file_count는 남김');
        $ex = $db->all('SELECT e.defect_id, e.start_line, e.end_line, e.content, e.content_replaced, d.line_no FROM defect_excerpts e JOIN defects d ON d.id = e.defect_id WHERE e.analysis_id = ?', [$first]);
        $this->assertSame(3, count($ex), 'main.py 결함 3건(마지막 감지 = 첫 분석)에 발췌');
        foreach ($ex as $e) {
            $this->assertTrue((int) $e['start_line'] === 1 && (int) $e['end_line'] >= (int) $e['line_no'], '앞뒤 20줄(파일 범위 안)');
            $this->assertSame(0, (int) $e['content_replaced']);
        }
        $this->assertContains('import os', $ex[0]['content'], '첫 분석 시점의 코드');
        // 그 결함이 다시 감지되면 발췌는 폐기
        DefectHarness::rewrite("{$dir}/main.py", static fn (string $s): string => "import os\nimport sys\n" . $s);
        $h->analyze($pid, 'ruff', $dir);
        $this->assertSame(1, (int) $db->value('SELECT COUNT(*) FROM defect_excerpts WHERE analysis_id = ?', [$first]), '다시 감지된 2건(os, sys)의 발췌 폐기');
    }

    public function testNonUtf8ExcerptUsesReplacementCharacterAndFlag(): void
    {
        $h = $this->h;
        $db = $h->app->db();
        $pid = $h->project('cp949', 'python');
        $user = (int) $db->value("SELECT id FROM users WHERE login_id = 'kim'");
        $aid = TestDb::analysis($db, $pid, $user, ['status' => 'succeeded']);
        // CP949 소스가 들어 있는 스냅샷과, 그 분석이 마지막 감지인 결함
        $dir = $h->app->snapshots()->dirOf($pid, $aid);
        Fs::ensureDir($dir);
        $z = new \ZipArchive();
        $z->open($dir . '/source.zip', \ZipArchive::CREATE);
        $z->addFromString('legacy.py', "# \xC7\xD1\xB1\xDB \xC1\xD6\xBC\xAE\nimport os\nx = 1\n");
        $z->close();
        $db->execute("INSERT INTO snapshots (analysis_id, storage_path, size_bytes, file_count, status, created_at) VALUES (?, ?, 1, 1, 'full', '2026-10-02T12:00:00Z')", [$aid, "{$pid}/{$aid}"]);
        $db->execute("INSERT INTO analysis_file_lists (analysis_id, exclude_rules, file_count, files_blob, created_at) VALUES (?, '{}', 1, ?, '2026-10-02T12:00:00Z')", [$aid, ScopeList::fromPaths(['legacy.py'])->encode()]);
        $def = TestDb::defect($db, $pid, $aid, ['file_path' => 'legacy.py', 'line_no' => 2, 'rule_key' => 'ruff:F401', 'last_seen_analysis_id' => $aid]);
        $svc = new SnapshotService($db, $h->app->clock, $h->app->logger, $h->root . '/snapshots', $h->root . '/work', 0, 20);
        $r = $svc->prune($pid);
        $this->assertSame([1, 1, 1], [$r['pruned'], $r['excerpts'], $r['replaced']]);
        $e = $db->one('SELECT content, content_replaced, start_line, end_line FROM defect_excerpts WHERE defect_id = ?', [$def]);
        $this->assertSame(1, (int) $e['content_replaced'], '대체 표시');
        $this->assertTrue(mb_check_encoding((string) $e['content'], 'UTF-8') && str_contains((string) $e['content'], "\u{FFFD}"), 'U+FFFD로 바꾼 UTF-8 텍스트');
        $this->assertContains('import os', (string) $e['content']);
        $this->assertSame([1, 4], [(int) $e['start_line'], (int) $e['end_line']]);
    }

    public function testIngestRejectsSplitFileGroups(): void
    {
        $h = $this->h;
        $dir = $h->root . '/split';
        Fs::ensureDir($dir);
        file_put_contents("{$dir}/a.py", "x\n");
        file_put_contents("{$dir}/b.py", "y\n");
        $line = static fn (string $f): string => json_encode(['v' => 1, 'tool' => 'ruff', 'language' => 'python', 'rule_key' => 'ruff:F', 'file' => $f, 'line' => 1, 'column' => 1, 'end_line' => 1, 'end_column' => 1, 'message' => 'm', 'syntax_error' => false]) . "\n";
        file_put_contents("{$dir}/r.ndjson", $line('a.py') . $line('b.py') . $line('a.py'));
        $this->assertThrows(\RuntimeException::class, fn () => (new DefectIngest($h->app->db()))->load("{$dir}/r.ndjson", $dir), '떨어져 나옴');
    }
}
