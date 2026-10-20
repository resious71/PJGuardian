<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\ApiException;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Services\Accounts\AccountCsvReader;
use PJGuardian\Services\Accounts\AccountImportService;
use PJGuardian\Services\Actor;
use PJGuardian\Support\Lang;

/**
 * 계정 CSV 일괄 처리(P28A, admin만 — 라우트의 RequireAdmin, 쓰기는 CSRF).
 *   GET  /admin/users/import                                   화면(파일 선택 → 미리보기 → 결과)
 *   GET  /api/v1/admin/account-import/template                 양식 CSV(UTF-8 BOM, 머리글 + 예시 2행)
 *   POST /api/v1/admin/account-import/preview?encoding=        본문 = CSV 파일 그대로(octet-stream). 저장 없음
 *   POST /api/v1/admin/account-import/execute?encoding=&digest=&skip_errors=   본문 = 미리보기와 같은 파일
 * 파일은 서버에 보관하지 않는다(미리보기와 실행 사이 상태 없음 — 실행 때 파일을 다시 받아 digest로 같은 판정인지 확인).
 */
final class AccountImportController
{
    /** 실행 요청의 PHP 시간 상한(초) — 1,000행 bcrypt(비용 11에서 약 80초)에 여유 */
    private const EXECUTE_TIME_LIMIT = 600;

    public function __construct(private readonly App $app)
    {
    }

    public function page(Request $request): Response
    {
        $html = $this->app->view($request)->page('admin/users_import', [
            'title' => Lang::get('account_import.title'),
            'user' => $request->attribute('user'),
            'active' => 'admin.users',
            'scripts' => ['js/pages/admin_users_import.js'],
            'body_class' => 'page-admin-users-import',
        ], [
            'tempHours' => intdiv($this->app->authSettings()->tempPasswordExpiry, 3600),
            'maxRows' => AccountCsvReader::MAX_ROWS,
            'maxMib' => AccountCsvReader::MAX_BYTES / 1048576,
            'authTypes' => $this->app->authProviders()->enabledTypes(),
        ]);
        return Response::html($html);
    }

    public function template(Request $request): Response
    {
        $csv = AccountImportService::template(Lang::get('account_import.template_example_name'), 'old.user');
        return new Response(200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="accounts_template.csv"',
            'Cache-Control' => 'no-store',
        ], $csv);
    }

    public function preview(Request $request): Response
    {
        return Response::data($this->app->accountImport()->preview(self::actor($request), self::file($request), self::encoding($request)));
    }

    public function execute(Request $request): Response
    {
        $digest = is_string($request->query['digest'] ?? null) ? $request->query['digest'] : '';
        if (preg_match('/^[0-9a-f]{64}$/D', $digest) !== 1) {
            throw new ApiException(400, 'import_digest_required');
        }
        $skip = in_array($request->query['skip_errors'] ?? '0', ['1', 'true'], true);
        $bytes = self::file($request);
        // 로컬 계정마다 bcrypt 해시(1,000행이면 1분 남짓) — 요청 시간 상한을 넉넉히(쓰기 트랜잭션은 해시 뒤 짧게).
        // 늘리기만 한다: 제한 없음(0, CLI·시험 프로세스)이나 이미 더 긴 값은 그대로 둔다(set_time_limit은 새 제한을 거는 것이라 줄일 수 있음)
        $limit = (int) ini_get('max_execution_time');
        if ($limit > 0 && $limit < self::EXECUTE_TIME_LIMIT) {
            @set_time_limit(self::EXECUTE_TIME_LIMIT);
        }
        return Response::data($this->app->accountImport()->execute(self::actor($request), $bytes, self::encoding($request), $digest, $skip));
    }

    private static function actor(Request $request): Actor
    {
        return Actor::fromUser($request->attribute('user'), $request->clientIp);
    }

    private static function encoding(Request $request): string
    {
        $enc = is_string($request->query['encoding'] ?? null) ? strtolower($request->query['encoding']) : 'auto';
        if (!in_array($enc, AccountCsvReader::ENCODINGS, true)) {
            throw new ApiException(400, 'import_encoding_invalid');
        }
        return $enc;
    }

    /** 본문 = 파일. 선언 크기가 한도를 넘으면 읽기 전에 413, 읽은 뒤에도 실제 크기로 한 번 더 */
    private static function file(Request $request): string
    {
        $declared = $request->header('content-length');
        if ($declared !== null && ctype_digit($declared) && (int) $declared > AccountCsvReader::MAX_BYTES) {
            throw new ApiException(413, 'import_file_too_large', ['max_mib' => AccountCsvReader::MAX_BYTES / 1048576]);
        }
        $bytes = $request->body();
        if (strlen($bytes) > AccountCsvReader::MAX_BYTES) {
            throw new ApiException(413, 'import_file_too_large', ['max_mib' => AccountCsvReader::MAX_BYTES / 1048576]);
        }
        if ($bytes === '') {
            throw new ApiException(400, 'import_file_required');
        }
        return $bytes;
    }
}
