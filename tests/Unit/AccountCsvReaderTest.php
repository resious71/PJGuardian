<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Services\Accounts\AccountCsvReader;
use PJGuardian\Tests\TestCase;

/**
 * 계정 CSV 읽기(P28A): 인코딩(UTF-8·BOM·CP949·선택), 줄바꿈(CRLF·LF), 인용(쉼표·따옴표·줄바꿈이 든 셀), 빈 줄,
 * 머리글 순서·모르는 열·그룹 열 경고, 1,000행·2 MiB 경계, 파일 단위 오류.
 */
final class AccountCsvReaderTest extends TestCase
{
    private const NAME = "\u{AE40}\u{AC1C}\u{BC1C}"; // 한글 표시 이름(코드에 한글 문자열을 직접 쓰지 않음)

    private static function cells(array $r): array
    {
        return array_map(static fn (array $row): array => $row['cells'], $r['rows']);
    }

    public function testEncodingsUtf8BomCp949AndChoice(): void
    {
        $csv = "login_id,display_name\r\nkim," . self::NAME . "\r\n";
        $plain = AccountCsvReader::read($csv);
        $this->assertSame(['utf-8', false, []], [$plain['encoding'], $plain['bom'], $plain['errors']]);
        $this->assertSame(self::NAME, $plain['rows'][0]['cells']['display_name']);

        $bom = AccountCsvReader::read("\xEF\xBB\xBF" . $csv);
        $this->assertSame(['utf-8', true], [$bom['encoding'], $bom['bom']]);
        $this->assertSame(['login_id', 'display_name'], $bom['columns'], 'BOM이 첫 열 이름에 붙지 않음');

        $cp = mb_convert_encoding($csv, 'CP949', 'UTF-8');
        $this->assertFalse(mb_check_encoding($cp, 'UTF-8'), '시험 데이터가 실제로 CP949 바이트');
        $auto = AccountCsvReader::read($cp);
        $this->assertSame(['cp949', self::NAME], [$auto['encoding'], $auto['rows'][0]['cells']['display_name']]);
        $this->assertSame('cp949', AccountCsvReader::read($cp, 'cp949')['encoding']);
        $this->assertSame('encoding_mismatch', AccountCsvReader::read($cp, 'utf-8')['errors'][0]['code'], 'UTF-8을 고르면 CP949 파일은 오류');
        // UTF-8 파일을 CP949로 고르면 한글은 왕복이 맞지 않아 오류(ASCII만 있는 파일은 둘 다 같음)
        $this->assertSame('encoding_mismatch', AccountCsvReader::read($csv, 'cp949')['errors'][0]['code']);
        $this->assertSame('cp949', AccountCsvReader::read("login_id\nkim\n", 'cp949')['encoding']);
        // 둘 다 아닌 바이트
        $bad = AccountCsvReader::read("login_id\n\xFF\xFE\xFF\n");
        $this->assertSame(['encoding_unknown', []], [$bad['errors'][0]['code'], $bad['rows']]);
        $this->assertSame('encoding_invalid', AccountCsvReader::read($csv, 'latin1')['errors'][0]['code']);
    }

    public function testLineEndingsQuotesBlankLinesAndRowNumbers(): void
    {
        $csv = "login_id,display_name,email\n"
            . "a1,\"Kim, Dev\",a@example.com\r\n"
            . "\n"
            . "a2,\"say \"\"hi\"\"\",\n"
            . "a3,\"two\r\nlines\",x@example.com\n"
            . "  \n"
            . "a4,last,";
        $r = AccountCsvReader::read($csv);
        $this->assertSame([], $r['errors']);
        $cells = self::cells($r);
        $this->assertSame(['a1', 'a2', 'a3', 'a4'], array_column($cells, 'login_id'));
        $this->assertSame('Kim, Dev', $cells[0]['display_name'], '따옴표 안 쉼표');
        $this->assertSame('say "hi"', $cells[1]['display_name'], '"" = 따옴표 하나');
        $this->assertSame("two\nlines", $cells[2]['display_name'], '셀 안 줄바꿈(CRLF → LF)');
        $this->assertSame([2, 4, 5, 8], array_column($r['rows'], 'row'), '줄 번호(머리글 1, 빈 줄·공백만 있는 줄 건너뜀, 셀 안 줄바꿈은 시작 줄)');
        $this->assertSame('', $cells[3]['email'], '마지막 줄에 줄바꿈 없음·빈 칸');
    }

    public function testHeaderOrderUnknownAndGroupColumns(): void
    {
        $r = AccountCsvReader::read(" Display_Name ,ROLE,login_id,Groups,nickname,action\nKim,admin,kim,dev,k,create\n");
        $this->assertSame([], $r['errors']);
        $this->assertSame(['action' => 'create', 'login_id' => 'kim', 'display_name' => 'Kim', 'email' => '', 'role' => 'admin', 'auth_type' => ''], $r['rows'][0]['cells'], '머리글 이름으로 읽음(대소문자·공백 무시)');
        $this->assertSame([['group_column_ignored', 'groups'], ['unknown_column', 'nickname']],
            array_map(static fn (array $w): array => [$w['code'], $w['params']['column']], $r['warnings']));
        $this->assertSame('duplicate_column', AccountCsvReader::read("login_id,Login_ID\na,b\n")['errors'][0]['code']);
        $this->assertSame('missing_login_id_column', AccountCsvReader::read("user,display_name\na,b\n")['errors'][0]['code']);
        $this->assertSame('no_rows', AccountCsvReader::read("login_id,display_name\r\n\r\n")['errors'][0]['code']);
        $this->assertSame('file_empty', AccountCsvReader::read("\xEF\xBB\xBF \r\n")['errors'][0]['code']);
        $extra = AccountCsvReader::read("login_id,display_name\nkim,Kim,Dev\nlee,Lee,\n");
        $this->assertSame([true, false], array_column($extra['rows'], 'extra'), '넘친 칸에 값이 있을 때만 표시');
    }

    public function testRowAndSizeLimits(): void
    {
        $make = static function (int $n): string {
            $s = "login_id,display_name\n";
            for ($i = 1; $i <= $n; $i++) {
                $s .= "user{$i},User {$i}\n";
            }
            return $s;
        };
        $this->assertSame(AccountCsvReader::MAX_ROWS, count(AccountCsvReader::read($make(1000))['rows']), '1,000행 통과');
        $over = AccountCsvReader::read($make(1001));
        $this->assertSame(['too_many_rows', 1000, []], [$over['errors'][0]['code'], $over['errors'][0]['params']['max'], $over['rows']]);
        // 2 MiB 경계: 정확히 2 MiB는 통과, 1바이트 넘으면 오류
        $head = "login_id,display_name\nkim,";
        $exact = $head . str_repeat('a', AccountCsvReader::MAX_BYTES - strlen($head));
        $this->assertSame(AccountCsvReader::MAX_BYTES, strlen($exact));
        $this->assertSame([], AccountCsvReader::read($exact)['errors']);
        $this->assertSame('file_too_large', AccountCsvReader::read($exact . 'a')['errors'][0]['code']);
    }
}
