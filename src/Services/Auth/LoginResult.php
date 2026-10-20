<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth;

/**
 * 로그인 판정 결과.
 * code: ok | login_failed(없는 ID·틀린 비밀번호·잠금·비활성을 구분하지 않음) | ip_blocked | temp_password_expired
 *       P28B 외부 인증: pending(승인 대기) | rejected(승인되지 않음) | pending_limit(대기 상한 — 요청을 받지 않음) | directory_unavailable(인증 서비스 문제)
 *       — 앞의 셋은 외부 인증을 통과한 사람에게만 나오므로 계정 존재가 드러나지 않는다. 모두 세션을 만들지 않는다.
 * notice: 로그인 성공 때 한 번 보여 줄 알림(예: ['code' => 'password_set_by_admin', 'at' => UTC ISO]). 없으면 null
 */
final class LoginResult
{
    /**
     * @param array<string, mixed>|null $user 공개용 사용자 정보(비밀번호 해시 없음)
     * @param array{code:string, at:string}|null $notice
     */
    private function __construct(
        public readonly bool $ok,
        public readonly string $code,
        public readonly ?array $user,
        public readonly ?array $notice = null,
    ) {
    }

    /**
     * @param array<string, mixed> $user
     * @param array{code:string, at:string}|null $notice
     */
    public static function success(array $user, ?array $notice = null): self
    {
        return new self(true, 'ok', $user, $notice);
    }

    public static function failure(string $code): self
    {
        return new self(false, $code, null);
    }
}
