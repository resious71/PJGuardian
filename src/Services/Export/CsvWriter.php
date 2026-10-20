<?php

declare(strict_types=1);

namespace PJGuardian\Services\Export;

/**
 * CSV(P23): UTF-8 BOM(한국어 Excel 호환), 쉼표, CRLF, RFC 4180 인용(따옴표·쉼표·CR·LF가 있으면 큰따옴표로 감싸고 안의 "는 ""),
 * 문자열 값은 SheetText::csv(정리 + 수식 시작 글자 앞 '). 날짜는 KST "yyyy-mm-dd HH:mm".
 */
final class CsvWriter implements SheetWriter
{
    /** @var resource */
    private $h;
    /** @var list<string> */
    private array $types = [];

    public function __construct(private readonly string $path, private readonly \DateTimeZone $tz)
    {
        $h = fopen($path, 'wb');
        if ($h === false) {
            throw new \RuntimeException('내보내기 임시 파일을 만들 수 없음');
        }
        $this->h = $h;
        fwrite($this->h, "\xEF\xBB\xBF");
    }

    public function begin(array $columns): void
    {
        $this->types = array_map(static fn (array $c): string => $c[1], $columns);
        $this->line(array_map(static fn (array $c): string => SheetText::csv($c[0]), $columns));
    }

    public function row(array $row): void
    {
        $out = [];
        foreach ($row as $i => $v) {
            $out[] = match (true) {
                $v === null || $v === '' => '',
                ($this->types[$i] ?? 'string') === 'int' => (string) (int) $v,
                ($this->types[$i] ?? 'string') === 'datetime' => self::localTime((string) $v, $this->tz),
                default => SheetText::csv((string) $v),
            };
        }
        $this->line($out);
    }

    public function finish(array $info): string
    {
        fclose($this->h);
        return $this->path;
    }

    public function contentType(): string
    {
        return 'text/csv; charset=UTF-8';
    }

    public function extension(): string
    {
        return 'csv';
    }

    /** UTC ISO → 화면 시간대 "yyyy-mm-dd HH:mm"(빈 값·잘못된 값은 빈 칸) */
    public static function localTime(string $iso, \DateTimeZone $tz): string
    {
        try {
            return (new \DateTimeImmutable($iso, new \DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d H:i');
        } catch (\Exception) {
            return '';
        }
    }

    /** @param list<string> $cells */
    private function line(array $cells): void
    {
        $quoted = array_map(static fn (string $c): string => strpbrk($c, "\",\r\n") === false ? $c : '"' . str_replace('"', '""', $c) . '"', $cells);
        fwrite($this->h, implode(',', $quoted) . "\r\n");
    }
}
