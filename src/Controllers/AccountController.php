<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\ApiException;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Services\UiPreferences;
use PJGuardian\Support\Lang;

/**
 * "내 정보"(X05, 명세 19.10): 로그인한 모든 사용자가 본인의 표시 이름·이메일을 본다·고친다.
 * 수정은 관리자 화면과 같은 UserService::updateProfile(검증·권한·감사 로그 한 곳). 비밀번호 관련 값은 돌려주지 않는다.
 * 라우트는 로그인 + 임시 비밀번호 변경 완료($auth)라 변경이 강제된 사용자는 들어오지 못한다.
 */
final class AccountController
{
    public function __construct(private readonly App $app)
    {
    }

    /**
     * @param array<string, mixed> $u 공개 사용자 정보
     * @return array<string, mixed>
     */
    public static function me(array $u): array
    {
        return [
            'id' => (int) $u['id'],
            'login_id' => (string) $u['login_id'],
            'display_name' => (string) $u['display_name'],
            'email' => $u['email'] ?? null,
            'role' => (string) $u['role'],
            'auth_type' => (string) $u['auth_type'],
            // P28B: 외부 인증 계정도 앱에서 이름·이메일을 고친다(외부 값으로 갱신하지 않음 — 예전 X05의 LDAP 읽기 전용은 없앰)
            'editable' => true,
        ];
    }

    /** GET /api/v1/users/me — P27D: 화면 언어·테마(계정 값, 없으면 null)와 이번 요청의 유효 값 */
    public function show(Request $request): Response
    {
        return Response::data(self::me($request->attribute('user')) + $this->uiView($request));
    }

    /**
     * PUT /api/v1/users/me/preferences {language?: ko|en|null, theme?: light|dark|system|null} — 본인만(다른 사용자의 값을 바꾸는 API는 없음).
     * null = 계정 값을 지워 자동 결정(쿠키 > 브라우저 언어 > ko, 테마는 쿠키 > system). 둘 중 하나만 보내도 된다. 허용 밖 값·빈 요청은 400.
     */
    public function preferences(Request $request): Response
    {
        $body = $request->json();
        if (!is_array($body) || array_is_list($body) || $body === [] || array_diff(array_keys($body), ['language', 'theme']) !== []) {
            throw new ApiException(400, 'invalid_preferences');
        }
        $user = $request->attribute('user');
        foreach (['language' => UiPreferences::validLanguage(...), 'theme' => UiPreferences::validTheme(...)] as $field => $valid) {
            if (array_key_exists($field, $body) && $body[$field] !== null && $valid($body[$field]) === null) {
                throw new ApiException(400, 'invalid_preferences');
            }
        }
        $db = $this->app->db();
        if (array_key_exists('language', $body)) {
            $db->execute('UPDATE users SET ui_language = ? WHERE id = ?', [$body['language'], (int) $user['id']]);
        }
        if (array_key_exists('theme', $body)) {
            $db->execute('UPDATE users SET ui_theme = ? WHERE id = ?', [$body['theme'], (int) $user['id']]);
        }
        $row = $this->app->db()->one('SELECT ui_language, ui_theme FROM users WHERE id = ?', [(int) $user['id']]);
        $lang = UiPreferences::language($row['ui_language'] ?? null, $request->cookie(UiPreferences::LANG_COOKIE), $request->header('accept-language'));
        return Response::data([
            'ui_language' => $row['ui_language'] ?? null, 'ui_theme' => $row['ui_theme'] ?? null,
            'effective_language' => $lang,
            'effective_theme' => UiPreferences::theme($row['ui_theme'] ?? null, $request->cookie(UiPreferences::THEME_COOKIE)),
        ]);
    }

    /** @return array<string, ?string> */
    private function uiView(Request $request): array
    {
        $acc = (array) $request->attribute('ui_account', []);
        return [
            'ui_language' => $acc['language'] ?? null, 'ui_theme' => $acc['theme'] ?? null,
            'effective_language' => (string) $request->attribute('ui_lang', Lang::locale()),
            'effective_theme' => (string) $request->attribute('ui_theme', 'system'),
        ];
    }

    /** PATCH /api/v1/users/me {display_name?, email?} — 다른 필드는 무시 */
    public function update(Request $request): Response
    {
        $user = $request->attribute('user');
        $updated = $this->app->users()->updateProfile($user, $request->clientIp, (int) $user['id'], $request->json());
        return Response::data(self::me($updated) + $this->uiView($request));
    }

    /** GET /account: "내 정보" 화면 */
    public function page(Request $request): Response
    {
        $user = $request->attribute('user');
        $html = $this->app->view($request)->page('account', [
            'title' => Lang::get('account.title'),
            'user' => $user,
            'active' => 'account',
            'scripts' => ['js/pages/account.js'],
            'body_class' => 'page-account',
        ], ['me' => self::me($user)]);
        return Response::html($html);
    }
}
