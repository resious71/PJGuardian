<?php

declare(strict_types=1);

namespace PJGuardian\Services\Accounts;

/**
 * 계정 CSV 읽기(P28A, 명세 19.2): 인코딩 판별·CSV 해석만 한다(DB를 보지 않음 — 행 검증은 AccountImportService).
 *
 * - 크기 MAX_BYTES(2 MiB) 이하, 데이터 행 MAX_ROWS(1,000) 이하. 한도는 이 두 상수 한 곳.
 * - 인코딩: auto = BOM 떼고 UTF-8로 유효하면 UTF-8, 아니면 CP949(되돌려 바꾼 바이트가 원래와 같을 때만), 둘 다 아니면 오류.
 *   사용자가 utf-8 / cp949를 직접 고르면 그 인코딩으로만 해석한다(맞지 않으면 오류).
 * - 쉼표 구분, 큰따옴표 인용(""는 따옴표 하나, 셀 안 쉼표·줄바꿈), CRLF/LF, 빈 줄·공백만 있는 줄은 건너뜀. 역슬래시는 특별한 뜻이 없다.
 * - 첫 줄은 머리글(앞뒤 공백 제거·소문자). 열 순서는 자유. 모르는 열은 경고 후 무시, group/groups 열은 "그룹은 CSV로 관리하지 않음" 경고.
 * - 셀 값은 앞뒤 공백만 뗀다(수식 문자로 시작해도 올린 값은 그대로 검증 — 내려받는 결과 CSV에서만 막는다).
 *
 * 결과(파일 단위 오류가 있으면 rows는 비어 있다):
 *   ['encoding' => 'utf-8'|'cp949'|null, 'bom' => bool, 'columns' => list<string>, 'rows' => list<{row:int, cells:array<string,string>, extra:bool}>,
 *    'errors' => list<{code, params}>, 'warnings' => list<{code, params}>]
 *   row = 파일의 줄 번호(머리글이 1, 셀 안 줄바꿈이 있으면 그 기록이 시작한 줄)
 */
final class AccountCsvReader
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const MAX_ROWS = 1000;
    public const COLUMNS = ['action', 'login_id', 'display_name', 'email', 'role', 'auth_type'];
    public const ENCODINGS = ['auto', 'utf-8', 'cp949'];
    private const GROUP_COLUMNS = ['group', 'groups', 'group_name', 'group_names'];

    /** @return array<string, mixed> */
    public static function read(string $bytes, string $encoding = 'auto'): array
    {
        $out = ['encoding' => null, 'bom' => false, 'columns' => [], 'rows' => [], 'errors' => [], 'warnings' => []];
        $fail = static function (string $code, array $params = []) use (&$out): array {
            $out['errors'][] = ['code' => $code, 'params' => $params];
            $out['rows'] = [];
            return $out;
        };
        if (!in_array($encoding, self::ENCODINGS, true)) {
            return $fail('encoding_invalid');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            return $fail('file_too_large', ['max_mib' => self::MAX_BYTES / 1048576]);
        }
        [$text, $enc, $bom] = self::decode($bytes, $encoding);
        $out['bom'] = $bom;
        if ($text === null) {
            return $fail($encoding === 'auto' ? 'encoding_unknown' : 'encoding_mismatch', ['encoding' => $encoding]);
        }
        $out['encoding'] = $enc;
        if (trim($text) === '') {
            return $fail('file_empty');
        }

        $records = self::records($text);
        if ($records === []) {
            return $fail('file_empty');
        }
        [, $header] = array_shift($records);
        $columns = array_map(static fn (string $h): string => strtolower(trim($h)), $header);
        $out['columns'] = $columns;
        $seen = [];
        foreach ($columns as $c) {
            if ($c === '') {
                continue;
            }
            if (isset($seen[$c])) {
                return $fail('duplicate_column', ['column' => $c]);
            }
            $seen[$c] = true;
            if (in_array($c, self::GROUP_COLUMNS, true)) {
                $out['warnings'][] = ['code' => 'group_column_ignored', 'params' => ['column' => $c]];
            } elseif (!in_array($c, self::COLUMNS, true)) {
                $out['warnings'][] = ['code' => 'unknown_column', 'params' => ['column' => self::shortText($c)]];
            }
        }
        if (!isset($seen['login_id'])) {
            return $fail('missing_login_id_column');
        }
        if (count($records) > self::MAX_ROWS) {
            return $fail('too_many_rows', ['max' => self::MAX_ROWS]);
        }
        if ($records === []) {
            return $fail('no_rows');
        }
        foreach ($records as [$line, $cells]) {
            $row = [];
            foreach (self::COLUMNS as $name) {
                $i = array_search($name, $columns, true);
                $row[$name] = $i === false ? '' : trim((string) ($cells[$i] ?? ''));
            }
            // 머리글보다 칸이 많고 넘친 칸에 값이 있으면 그 행은 오류(쉼표가 든 값을 따옴표로 묶지 않은 경우)
            $extra = false;
            for ($i = count($columns); $i < count($cells); $i++) {
                if (trim((string) $cells[$i]) !== '') {
                    $extra = true;
                }
            }
            $out['rows'][] = ['row' => $line, 'cells' => $row, 'extra' => $extra];
        }
        return $out;
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: bool} [UTF-8 글, 인식한 인코딩, BOM 있었음]
     */
    public static function decode(string $bytes, string $encoding): array
    {
        $bom = str_starts_with($bytes, "\xEF\xBB\xBF");
        $body = $bom ? substr($bytes, 3) : $bytes;
        $utf8 = mb_check_encoding($body, 'UTF-8');
        if ($encoding === 'utf-8' || ($encoding === 'auto' && ($utf8 || $bom))) {
            return $utf8 ? [$body, 'utf-8', $bom] : [null, null, $bom];
        }
        // CP949: BOM이 있으면 UTF-8 파일이므로 CP949로 보지 않는다
        if ($bom) {
            return [null, null, true];
        }
        $text = @mb_convert_encoding($bytes, 'UTF-8', 'CP949');
        $back = is_string($text) ? @mb_convert_encoding($text, 'CP949', 'UTF-8') : false;
        if (!is_string($text) || $back !== $bytes || !mb_check_encoding($text, 'UTF-8')) {
            return [null, null, false];
        }
        return [$text, 'cp949', false];
    }

    /**
     * CSV 기록 나누기(RFC 4180 + LF 허용). 빈 줄은 건너뛴다.
     *
     * @return list<array{0: int, 1: list<string>}> [시작 줄 번호, 칸 목록]
     */
    private static function records(string $text): array
    {
        $records = [];
        $len = strlen($text);
        $line = 1;
        $start = 1;
        $cells = [];
        $cell = '';
        $quoted = false;
        $wasQuoted = false;
        $i = 0;
        $flush = static function () use (&$records, &$cells, &$cell, &$start, &$wasQuoted): void {
            $cells[] = $cell;
            $blank = count($cells) === 1 && trim($cells[0]) === '' && !$wasQuoted; // 빈 줄·공백만 있는 줄
            if (!$blank) {
                $records[] = [$start, $cells];
            }
            $cells = [];
            $cell = '';
            $wasQuoted = false;
        };
        while ($i < $len) {
            $ch = $text[$i];
            if ($quoted) {
                if ($ch === '"') {
                    if ($i + 1 < $len && $text[$i + 1] === '"') {
                        $cell .= '"';
                        $i += 2;
                        continue;
                    }
                    $quoted = false;
                    $i++;
                    continue;
                }
                if ($ch === "\n") {
                    $line++;
                }
                $cell .= $ch;
                $i++;
                continue;
            }
            if ($ch === '"' && trim($cell) === '') {
                $quoted = true;
                $wasQuoted = true;
                $cell = '';
                $i++;
                continue;
            }
            if ($ch === ',') {
                $cells[] = $cell;
                $cell = '';
                $i++;
                continue;
            }
            if ($ch === "\r" || $ch === "\n") {
                $flush();
                if ($ch === "\r" && $i + 1 < $len && $text[$i + 1] === "\n") {
                    $i++;
                }
                $i++;
                $line++;
                $start = $line;
                continue;
            }
            $cell .= $ch;
            $i++;
        }
        if ($cell !== '' || $cells !== [] || $wasQuoted) {
            $flush();
        }
        // 셀 안의 CRLF는 LF 하나로(값 비교·표시용)
        foreach ($records as &$r) {
            $r[1] = array_map(static fn (string $c): string => str_replace("\r\n", "\n", $c), $r[1]);
        }
        unset($r);
        return $records;
    }

    /** 경고에 되돌려 보이는 열 이름은 짧게(사용자 입력) */
    private static function shortText(string $s): string
    {
        return mb_strlen($s) > 40 ? mb_substr($s, 0, 40) . '…' : $s;
    }
}
