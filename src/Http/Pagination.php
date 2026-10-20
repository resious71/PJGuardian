<?php

declare(strict_types=1);

namespace PJGuardian\Http;

/**
 * 목록 API의 page/size (명세 19.7). 기본 size는 설정 ui.defect_page_size(50), 최대는 ui.page_size_max(200).
 * 응답 조립은 Response::page().
 */
final class Pagination
{
    private function __construct(public readonly int $page, public readonly int $size)
    {
    }

    public static function fromRequest(Request $req, int $defaultSize, int $maxSize): self
    {
        $page = self::parse($req->query['page'] ?? null, 1, $maxSize);
        $size = self::parse($req->query['size'] ?? null, $defaultSize, $maxSize);
        if ($page < 1 || $size < 1 || $size > $maxSize) {
            throw new ApiException(400, 'invalid_pagination', ['max' => $maxSize]);
        }
        return new self($page, $size);
    }

    private static function parse(mixed $v, int $default, int $max): int
    {
        if ($v === null || $v === '') {
            return $default;
        }
        if (!is_string($v) || preg_match('/^\d{1,9}$/', $v) !== 1) {
            throw new ApiException(400, 'invalid_pagination', ['max' => $max]);
        }
        return (int) $v;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->size;
    }
}
