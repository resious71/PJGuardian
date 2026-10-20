<?php

declare(strict_types=1);

namespace PJGuardian\Services\Tools;

/**
 * 도구 출력(한 줄 = JSON 하나)을 한 줄씩 읽어 공통 형식으로 바꾼다(명세 6.1, 19.1). 전체를 메모리에 올리지 않는다.
 * 알 수 없는 필드는 무시하고 필수 필드가 없거나 형식이 틀릴 때만 ToolOutputException.
 *
 * ruff `--output-format json-lines`: filename, location.row, location.column, message 필수, code는 문자열 또는 null.
 *   문법 오류 = code가 null(0.12 이하) 또는 "invalid-syntax"(0.16) → rule_key "syntax-error".
 * ESLint(서버 포매터 tools/eslint/ndjson-formatter.cjs): filePath, line, message 필수, ruleId는 문자열 또는 null.
 *   문법 오류 = ruleId null + fatal true. 규칙 없는 비치명 메시지는 "Unused eslint-disable directive…"면
 *   eslint:unused-disable-directive, 그 밖은 eslint:no-rule-id([가정], 실제 사례를 보면 다시 정한다).
 */
final class ToolOutputParser
{
    /** @param \Closure(array<string, mixed>): void $emit */
    public static function ruff(string $file, \Closure $emit): void
    {
        self::lines($file, static function (array $d, int $no) use ($emit): void {
            $loc = is_array($d['location'] ?? null) ? $d['location'] : [];
            $end = is_array($d['end_location'] ?? null) ? $d['end_location'] : [];
            $code = $d['code'] ?? null;
            if ($code !== null && !is_string($code)) {
                throw new ToolOutputException("ruff {$no}줄: code 형식 오류");
            }
            $syntax = $code === null || $code === 'invalid-syntax';
            $emit([
                'tool' => 'ruff',
                'language' => 'python',
                'rule_key' => $syntax ? 'syntax-error' : 'ruff:' . $code,
                'file' => self::str($d['filename'] ?? null, "ruff {$no}줄: filename"),
                'line' => self::int($loc['row'] ?? null, "ruff {$no}줄: location.row"),
                'column' => self::int($loc['column'] ?? null, "ruff {$no}줄: location.column"),
                'end_line' => is_int($end['row'] ?? null) ? $end['row'] : null,
                'end_column' => is_int($end['column'] ?? null) ? $end['column'] : null,
                'message' => self::str($d['message'] ?? null, "ruff {$no}줄: message"),
                'syntax_error' => $syntax,
                'tool_severity' => is_string($d['severity'] ?? null) ? $d['severity'] : null,
            ]);
        });
    }

    /**
     * @param \Closure(array<string, mixed>): void $emit
     * @param (\Closure(string): void)|null $onFile 분석한 파일({"kind":"file"} 줄)마다
     */
    public static function eslint(string $file, \Closure $emit, ?\Closure $onFile = null): void
    {
        self::lines($file, static function (array $m, int $no) use ($emit, $onFile): void {
            $path = self::str($m['filePath'] ?? null, "eslint {$no}줄: filePath");
            if (($m['kind'] ?? null) === 'file') {
                if ($onFile !== null) {
                    $onFile($path);
                }
                return;
            }
            $text = self::str($m['message'] ?? null, "eslint {$no}줄: message");
            $ruleId = $m['ruleId'] ?? null;
            if ($ruleId !== null && !is_string($ruleId)) {
                throw new ToolOutputException("eslint {$no}줄: ruleId 형식 오류");
            }
            $fatal = ($m['fatal'] ?? false) === true;
            if ($ruleId !== null) {
                $key = 'eslint:' . $ruleId;
            } elseif ($fatal) {
                $key = 'syntax-error';
            } elseif (str_starts_with($text, 'Unused eslint-disable directive')) {
                $key = 'eslint:unused-disable-directive';
            } else {
                $key = 'eslint:no-rule-id';
            }
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $emit([
                'tool' => 'eslint',
                'language' => in_array($ext, ['ts', 'tsx'], true) ? 'typescript' : 'javascript',
                'rule_key' => $key,
                'file' => $path,
                'line' => self::int($m['line'] ?? null, "eslint {$no}줄: line"),
                'column' => is_int($m['column'] ?? null) ? $m['column'] : null,
                'end_line' => is_int($m['endLine'] ?? null) ? $m['endLine'] : null,
                'end_column' => is_int($m['endColumn'] ?? null) ? $m['endColumn'] : null,
                'message' => $text,
                'syntax_error' => $key === 'syntax-error',
                'tool_severity' => is_int($m['severity'] ?? null) ? $m['severity'] : null,
            ]);
        });
    }

    /** @param \Closure(array<string, mixed>, int): void $each */
    private static function lines(string $file, \Closure $each): void
    {
        $h = @fopen($file, 'rb');
        if ($h === false) {
            throw new ToolOutputException('도구 출력 파일을 열 수 없음');
        }
        try {
            $no = 0;
            while (($line = fgets($h)) !== false) {
                $no++;
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                try {
                    $d = json_decode($line, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                } catch (\JsonException) {
                    throw new ToolOutputException("{$no}줄: JSON이 아님");
                }
                if (!is_array($d) || array_is_list($d)) {
                    throw new ToolOutputException("{$no}줄: 객체가 아님");
                }
                $each($d, $no);
            }
        } finally {
            fclose($h);
        }
    }

    private static function str(mixed $v, string $what): string
    {
        if (!is_string($v)) {
            throw new ToolOutputException("필수 필드 누락 또는 형식 오류: {$what}");
        }
        return $v;
    }

    private static function int(mixed $v, string $what): int
    {
        if (!is_int($v)) {
            throw new ToolOutputException("필수 필드 누락 또는 형식 오류: {$what}");
        }
        return $v;
    }
}
