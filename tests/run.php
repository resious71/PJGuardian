<?php

declare(strict_types=1);

/**
 * PJGuardian 테스트 러너 (외부 의존성 없음)
 *
 * 사용법: php tests/run.php [필터] [--big] [--bigfiles] [--exclude-needs-bin]
 *   필터는 "클래스::메서드"의 부분 문자열(예: Config, LoggerTest::testMask)
 *   --big: 큰 파일 시험(2GB 업로드 등)도 실행한다(환경 변수 PJG_TEST_BIG=1). 기본은 건너뛴다
 *   --bigfiles: 항목 100만 개 zip 해제 측정도 실행한다(PJG_TEST_BIGFILES=1, 수 분 걸림)
 *   --exclude-needs-bin: 실제 bin/ 도구(ruff·ESLint·git·p4 실행 파일, Node)가 있어야 하는 시험을 건너뛴다(P33, CI처럼 bin/에 매니페스트만 있는 환경).
 *     시험은 클래스나 메서드 docblock의 `@needs-bin <이유>`로 표시한다. 건너뛴 시험은 "건너뜀(needs-bin)"으로 이름을 보이고 개수를 요약 줄에 적는다.
 *     이 옵션이 없으면 표시는 아무 영향이 없다(기본 동작 그대로).
 * tests/Unit/, tests/Integration/ 아래의 *Test.php를 찾아 test로 시작하는 메서드를 실행한다.
 * 실패나 오류가 하나라도 있으면 종료 코드 1.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use PJGuardian\Tests\AssertionFailed;
use PJGuardian\Tests\TestCase;

$args = array_slice($argv, 1);
if (in_array('--big', $args, true)) {
    putenv('PJG_TEST_BIG=1');
}
if (in_array('--bigfiles', $args, true)) {
    putenv('PJG_TEST_BIGFILES=1'); // 항목 100만 개 zip 해제 측정(SafeUnzipTest::testBigManyEntries)
}
$excludeNeedsBin = in_array('--exclude-needs-bin', $args, true);
$filter = (string) (array_values(array_filter($args, static fn (string $a): bool => !str_starts_with($a, '--')))[0] ?? '');
$files = [];
foreach (['Unit', 'Integration'] as $suite) {
    $dir = PJG_ROOT . '/tests/' . $suite;
    if (!is_dir($dir)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && str_ends_with($f->getFilename(), 'Test.php')) {
            $files[] = str_replace('\\', '/', $f->getPathname());
        }
    }
}
sort($files);

$passed = 0;
$skippedNeedsBin = 0;
$failed = [];
$leftovers = [];
$start = hrtime(true);
foreach ($files as $file) {
    $rel = substr($file, strlen(str_replace('\\', '/', PJG_ROOT) . '/tests/'), -4);
    $class = 'PJGuardian\\Tests\\' . str_replace('/', '\\', $rel);
    if (!class_exists($class) || !is_subclass_of($class, TestCase::class)) {
        $failed[] = "{$class}: 클래스를 찾을 수 없거나 TestCase를 상속하지 않음";
        continue;
    }
    $classNeedsBin = str_contains((string) (new ReflectionClass($class))->getDocComment(), '@needs-bin');
    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
        if (!str_starts_with($m->getName(), 'test')) {
            continue;
        }
        $name = (new ReflectionClass($class))->getShortName() . '::' . $m->getName();
        if ($filter !== '' && !str_contains($name, $filter)) {
            continue;
        }
        if ($excludeNeedsBin && ($classNeedsBin || str_contains((string) $m->getDocComment(), '@needs-bin'))) {
            $skippedNeedsBin++;
            echo "  건너뜀(needs-bin) {$name}\n";
            continue;
        }
        /** @var TestCase $t */
        $t = new $class();
        try {
            $t->setUp();
            $t->{$m->getName()}();
            $passed++;
            echo "  ok  {$name}\n";
        } catch (AssertionFailed $e) {
            $failed[] = "{$name}: {$e->getMessage()}";
            echo "  실패 {$name}\n";
        } catch (Throwable $e) {
            $failed[] = "{$name}: 오류 " . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
            echo "  오류 {$name}\n";
        } finally {
            try {
                $t->tearDown();
            } finally {
                $dirs = $t->takeTempDirs();
                $t = null; // 테스트 객체가 쥔 DB 연결·파일 핸들을 닫는다(Windows는 열린 파일을 지울 수 없음)
                gc_collect_cycles();
                foreach ($dirs as $d) {
                    if (!\PJGuardian\Support\Fs::removeTree($d)) {
                        $leftovers[] = "{$name}: {$d}";
                    }
                }
            }
        }
    }
}

$sec = (hrtime(true) - $start) / 1e9;
echo "\n";
foreach ($leftovers as $l) {
    echo "[경고] 임시 디렉토리를 지우지 못함(열린 파일?): {$l}\n";
}
foreach ($failed as $f) {
    echo "[실패] {$f}\n";
}
printf("결과: 통과 %d, 실패 %d%s (%.2f초, PHP %s, %s)\n", $passed, count($failed), $excludeNeedsBin ? ", 건너뜀(needs-bin) {$skippedNeedsBin}" : '', $sec, PHP_VERSION, PHP_OS_FAMILY);
exit($failed === [] ? 0 : 1);
