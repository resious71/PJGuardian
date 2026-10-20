<?php

declare(strict_types=1);

namespace PJGuardian\Services\Export;

/**
 * 내보내기 파일 작성기(CSV·XLSX 공통 모양). 열 정의는 [이름, 종류, 너비]: 종류 string | int | datetime(UTC ISO 문자열 → KST 표시).
 * 행은 열 순서대로의 값 목록(null = 빈 칸). 작성기는 임시 파일에 스트리밍으로 쓰고 finish()가 최종 파일 경로를 돌려준다.
 */
interface SheetWriter
{
    /** @param list<array{0:string, 1:string, 2:int}> $columns */
    public function begin(array $columns): void;

    /** @param list<mixed> $row */
    public function row(array $row): void;

    /**
     * 두 번째 시트 "조건"(XLSX만, CSV는 무시): [항목, 값] 목록.
     *
     * @param list<array{0:string, 1:string}> $info
     */
    public function finish(array $info): string;

    public function contentType(): string;

    public function extension(): string;
}
