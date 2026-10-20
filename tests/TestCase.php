<?php

declare(strict_types=1);

namespace PJGuardian\Tests;

/**
 * 테스트 기반 클래스. test로 시작하는 public 메서드가 테스트다.
 * setUp()/tearDown()을 재정의할 수 있다. tempDir()로 만든 디렉토리는 테스트가 끝나면 지운다.
 */
abstract class TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    /**
     * @internal 러너가 호출. 만든 임시 디렉토리 목록을 넘겨받는다.
     * 러너는 테스트 객체를 없앤 뒤(열린 DB 파일 핸들이 닫힌 뒤) 지운다. Windows는 열린 파일을 지울 수 없다.
     *
     * @return list<string>
     */
    public function takeTempDirs(): array
    {
        $dirs = $this->tempDirs;
        $this->tempDirs = [];
        return $dirs;
    }

    /** data/work/ 아래에 시험용 임시 디렉토리를 만든다(git 무시 대상). */
    protected function tempDir(string $prefix = 't'): string
    {
        $dir = PJG_ROOT . '/data/work/test_' . $prefix . '_' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $this->tempDirs[] = $dir;
        return str_replace('\\', '/', (string) realpath($dir));
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $this->fail(($message !== '' ? $message . ' — ' : '') . '기대: ' . self::show($expected) . ', 실제: ' . self::show($actual));
        }
    }

    protected function assertTrue(bool $cond, string $message = '조건이 참이 아님'): void
    {
        if (!$cond) {
            $this->fail($message);
        }
    }

    protected function assertFalse(bool $cond, string $message = '조건이 거짓이 아님'): void
    {
        if ($cond) {
            $this->fail($message);
        }
    }

    protected function assertContains(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            $this->fail(($message !== '' ? $message . ' — ' : '') . self::show($needle) . ' 이(가) 없음: ' . self::show(mb_substr($haystack, 0, 400)));
        }
    }

    protected function assertNotContains(string $needle, string $haystack, string $message = ''): void
    {
        if (str_contains($haystack, $needle)) {
            $this->fail(($message !== '' ? $message . ' — ' : '') . self::show($needle) . ' 이(가) 있으면 안 됨: ' . self::show(mb_substr($haystack, 0, 400)));
        }
    }

    /**
     * $fn이 $class 예외를 던져야 한다. 던진 예외를 돌려준다.
     *
     * @template T of \Throwable
     * @param class-string<T> $class
     * @return T
     */
    protected function assertThrows(string $class, callable $fn, string $messageContains = ''): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if (!$e instanceof $class) {
                $this->fail("예외 종류가 다름: 기대 {$class}, 실제 " . get_class($e) . ': ' . $e->getMessage());
            }
            if ($messageContains !== '' && !str_contains($e->getMessage(), $messageContains)) {
                $this->fail("예외 메시지에 '{$messageContains}' 없음: " . $e->getMessage());
            }
            return $e;
        }
        $this->fail("{$class} 예외가 발생하지 않음");
    }

    protected function fail(string $message): never
    {
        throw new AssertionFailed($message);
    }

    private static function show(mixed $v): string
    {
        return is_string($v) ? '"' . $v . '"' : (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
