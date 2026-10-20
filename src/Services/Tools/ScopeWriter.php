<?php

declare(strict_types=1);

namespace PJGuardian\Services\Tools;

/**
 * 도구가 실제로 분석한 파일 목록(분석 범위, P16 입력). 한 줄에 루트 기준 상대 경로(NFC, '/' 구분) 하나, 정렬하지 않은 채로 쓴다.
 *   ruff: `ruff check --show-files`(같은 설정·제외 옵션)의 출력, ESLint: 서버 포매터가 결과의 모든 파일을 {"kind":"file"} 줄로 냄.
 * 범위가 필요한 이유: 업로드 설정의 제외(ruff exclude, .eslintignore)로 도구가 보지 않은 파일은 작업 디렉토리에 있어도
 * "감지되지 않음(resolved)"이 아니라 "범위 밖(상태 유지)"이어야 한다.
 */
final class ScopeWriter
{
    /** @var resource */
    private $fh;
    private int $count = 0;

    public function __construct(private readonly string $file, private readonly DefectWriter $paths)
    {
        $h = @fopen($file, 'wb');
        if ($h === false) {
            throw new \RuntimeException('범위 파일을 만들 수 없음');
        }
        $this->fh = $h;
    }

    public function add(string $toolPath): void
    {
        $rel = $this->paths->relative($toolPath);
        if ($rel === null) {
            return;
        }
        fwrite($this->fh, $rel . "\n");
        $this->count++;
    }

    public function close(): int
    {
        if (is_resource($this->fh)) {
            fclose($this->fh);
        }
        return $this->count;
    }

    public function path(): string
    {
        return $this->file;
    }
}
