<?php

declare(strict_types=1);

namespace PJGuardian\Services\Tools;

use PJGuardian\Support\ExcludeMatcher;
use PJGuardian\Support\Logger;

/**
 * 정규화한 결함을 NDJSON으로 한 줄씩 쓴다(명세 6.1, 19.1, P15). 결함을 메모리에 모으지 않는다(10만 건 대비).
 * 형식(한 줄 = 결함 하나, UTF-8, LF, 키 순서 고정, docs/architecture.md 23절):
 *   {"v":1,"tool":"ruff","language":"python","rule_key":"ruff:F401","file":"app/x.py","line":2,"column":8,
 *    "end_line":2,"end_column":10,"message":"…","syntax_error":false,"tool_severity":"error"}
 *
 * 방어(이상 입력): 도구가 준 경로가 분석 루트 밖이거나 실제 파일이 아니면 버리고 WARN, 제외 대상(ExcludeMatcher)이면 버린다.
 * 경로는 루트 기준 상대 경로, '/' 구분, NFC. 같은 파일의 결함은 도구 출력 순서대로 이어서 나온다(ruff·ESLint 모두 파일 단위 출력).
 */
final class DefectWriter
{
    /** @var resource */
    private $fh;
    private string $root;
    private string $rootKey;
    private int $written = 0;
    private int $syntax = 0;
    /** @var array<string, int> */
    private array $skipped = ['outside' => 0, 'missing' => 0, 'excluded' => 0];
    private ?string $lastAbs = null;
    private ?string $lastRel = null;
    /** @var array<string, int> 규칙별 건수(보고·스모크 테스트용, 규칙 종류 수만큼만 메모리) */
    private array $byRule = [];

    public function __construct(private readonly string $file, string $root, private readonly ExcludeMatcher $matcher, private readonly Logger $logger)
    {
        $real = realpath($root);
        if ($real === false) {
            throw new \RuntimeException('분석 루트 없음');
        }
        $this->root = rtrim(str_replace('\\', '/', $real), '/');
        $this->rootKey = PHP_OS_FAMILY === 'Windows' ? strtolower($this->root) : $this->root;
        $h = @fopen($file, 'wb');
        if ($h === false) {
            throw new \RuntimeException('결과 파일을 만들 수 없음');
        }
        $this->fh = $h;
    }

    /**
     * 도구가 준 경로(절대 또는 루트 기준 상대) → 루트 기준 상대 경로. 루트 밖이거나 실제 파일이 아니면 null.
     */
    public function relative(string $toolPath): ?string
    {
        if ($toolPath === $this->lastAbs) {
            return $this->lastRel;
        }
        $p = str_replace('\\', '/', $toolPath);
        if (!preg_match('#^([A-Za-z]:)?/#', $p)) {
            $p = $this->root . '/' . $p;
        }
        $real = realpath($p);
        $rel = null;
        if ($real === false || !is_file($real)) {
            $this->skipped['missing']++;
            $this->logger->warn('도구가 보고한 파일이 없음(결함 무시)', ['path' => mb_substr($toolPath, 0, 300)]);
        } else {
            $r = str_replace('\\', '/', $real);
            $key = PHP_OS_FAMILY === 'Windows' ? strtolower($r) : $r;
            if (!str_starts_with($key, $this->rootKey . '/')) {
                $this->skipped['outside']++;
                $this->logger->warn('도구가 분석 루트 밖의 파일을 보고함(결함 무시)', ['path' => mb_substr($toolPath, 0, 300)]);
            } else {
                $rel = (string) \Normalizer::normalize(substr($r, strlen($this->root) + 1), \Normalizer::FORM_C);
            }
        }
        $this->lastAbs = $toolPath;
        $this->lastRel = $rel;
        return $rel;
    }

    /**
     * @param array{tool:string, language:string, rule_key:string, file:string, line:int, column:?int, end_line:?int,
     *   end_column:?int, message:string, syntax_error:bool, tool_severity:string|int|null} $d file은 도구가 준 경로
     */
    public function write(array $d): void
    {
        $rel = $this->relative($d['file']);
        if ($rel === null) {
            return;
        }
        if ($this->matcher->matches($rel)) {
            $this->skipped['excluded']++;
            return;
        }
        $line = [
            'v' => 1, 'tool' => $d['tool'], 'language' => $d['language'], 'rule_key' => $d['rule_key'], 'file' => $rel,
            'line' => $d['line'], 'column' => $d['column'], 'end_line' => $d['end_line'], 'end_column' => $d['end_column'],
            'message' => $d['message'], 'syntax_error' => $d['syntax_error'], 'tool_severity' => $d['tool_severity'],
        ];
        $json = json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false || fwrite($this->fh, $json . "\n") === false) {
            throw new \RuntimeException('결과 파일 쓰기 실패');
        }
        $this->written++;
        if ($d['syntax_error']) {
            $this->syntax++;
        }
        $this->byRule[$d['rule_key']] = ($this->byRule[$d['rule_key']] ?? 0) + 1;
    }

    /** @return array{written:int, syntax:int, skipped:array<string,int>, by_rule:array<string,int>} */
    public function close(): array
    {
        if (is_resource($this->fh)) {
            fclose($this->fh);
        }
        ksort($this->byRule);
        return ['written' => $this->written, 'syntax' => $this->syntax, 'skipped' => $this->skipped, 'by_rule' => $this->byRule];
    }

    public function path(): string
    {
        return $this->file;
    }
}
