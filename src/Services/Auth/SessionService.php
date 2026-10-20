<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth;

use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\SystemClock;

/**
 * DB 기반 세션(명세 3, 13). 웹 요청과 청크 업로드(P12)가 같은 활동 갱신 함수(touch)를 쓴다.
 *
 * - 세션 ID: 32바이트 난수의 base64url(43자). 쿠키로만 주고, DB에는 SHA-256(id_hash)만 저장한다.
 * - 로그인 전 세션(user_id NULL): 로그인 폼의 CSRF 토큰을 담는다. 로그인하면 rotate()로 새 ID·새 토큰을 만든다.
 * - 만료: 지금 - last_activity_at > session_idle(30분)이면 만료. 조회할 때 지운다.
 * - 활동 갱신: 마지막 기록 후 session_touch_interval(60초)이 지나지 않았으면 쓰지 않는다(쓰기 부하 감소).
 *   그래서 실제 만료는 최대 그 간격만큼 일찍 올 수 있다(30분 → 29분 이상).
 * - 즉시 무효화: destroyForUser()(비활성화, 역할 변경, 임시 비밀번호 발급, 비밀번호 변경 시 다른 세션).
 */
final class SessionService
{
    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly AuthSettings $settings,
    ) {
    }

    public static function hashId(string $rawId): string
    {
        return hash('sha256', $rawId);
    }

    public static function isWellFormed(string $rawId): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $rawId) === 1;
    }

    /**
     * 로그인 전 세션을 새로 만든다.
     *
     * @return array{0: string, 1: Session} [쿠키에 넣을 원래 ID, 세션]
     */
    public function start(?string $ip, ?string $userAgent): array
    {
        return $this->create(null, $ip, $userAgent);
    }

    public function resolve(string $rawId): SessionLookup
    {
        if (!self::isWellFormed($rawId)) {
            return new SessionLookup(null);
        }
        $row = $this->db->one('SELECT * FROM sessions WHERE id_hash = ?', [self::hashId($rawId)]);
        if ($row === null) {
            return new SessionLookup(null);
        }
        $session = Session::fromRow($row);
        if ($this->isExpired($session)) {
            $this->db->execute('DELETE FROM sessions WHERE id_hash = ?', [$session->idHash]);
            return new SessionLookup(null, true);
        }
        return new SessionLookup($session);
    }

    /** 활동 시각 갱신(간격 이내면 건너뜀). 청크 업로드 요청도 이 함수로 세션 활동을 기록한다(P12). */
    public function touch(Session $session): Session
    {
        $now = $this->clock->now();
        $last = new \DateTimeImmutable($session->lastActivityAt);
        if ($now->getTimestamp() - $last->getTimestamp() < $this->settings->sessionTouchInterval) {
            return $session;
        }
        $nowIso = SystemClock::iso($now);
        $this->db->execute('UPDATE sessions SET last_activity_at = ? WHERE id_hash = ?', [$nowIso, $session->idHash]);
        return new Session($session->idHash, $session->userId, $session->csrfToken, $session->createdAt, $nowIso, $session->flash);
    }

    /**
     * 다음 화면에 한 번 보여 줄 알림을 세션에 넣는다(로그인 후 리다이렉트된 화면이 takeFlash로 꺼냄).
     *
     * @param array<string, string> $flash 예: ['code' => 'password_set_by_admin', 'at' => UTC ISO]
     */
    public function setFlash(Session $session, array $flash): Session
    {
        $json = json_encode($flash, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->db->execute('UPDATE sessions SET flash = ? WHERE id_hash = ?', [$json, $session->idHash]);
        return new Session($session->idHash, $session->userId, $session->csrfToken, $session->createdAt, $session->lastActivityAt, $json);
    }

    /** @return array<string, string>|null 알림을 꺼내고 세션에서 지운다 */
    public function takeFlash(Session $session): ?array
    {
        if ($session->flash === null) {
            return null;
        }
        $this->db->execute('UPDATE sessions SET flash = NULL WHERE id_hash = ?', [$session->idHash]);
        $data = json_decode($session->flash, true);
        return is_array($data) ? $data : null;
    }

    /**
     * 로그인: 이전 세션(로그인 전 세션 포함)을 지우고 새 ID·새 CSRF 토큰으로 만든다(세션 고정 공격 방지).
     *
     * @return array{0: string, 1: Session}
     */
    public function rotate(?Session $old, int $userId, ?string $ip, ?string $userAgent): array
    {
        return $this->db->transaction(function () use ($old, $userId, $ip, $userAgent): array {
            if ($old !== null) {
                $this->destroy($old);
            }
            return $this->create($userId, $ip, $userAgent);
        });
    }

    public function destroy(Session $session): void
    {
        $this->db->execute('DELETE FROM sessions WHERE id_hash = ?', [$session->idHash]);
    }

    /** 사용자의 세션을 모두 폐기한다($exceptIdHash는 남김). 지운 수를 돌려준다. */
    public function destroyForUser(int $userId, ?string $exceptIdHash = null): int
    {
        return $this->db->execute('DELETE FROM sessions WHERE user_id = ? AND id_hash <> ?', [$userId, $exceptIdHash ?? '']);
    }

    /** 만료된 세션 정리(로그인할 때와 정리 작업 P14). 지운 수. */
    public function purgeExpired(): int
    {
        $cutoff = SystemClock::iso($this->clock->now()->modify('-' . $this->settings->sessionIdle . ' seconds'));
        return $this->db->execute('DELETE FROM sessions WHERE last_activity_at < ?', [$cutoff]);
    }

    private function isExpired(Session $s): bool
    {
        $idle = $this->clock->now()->getTimestamp() - (new \DateTimeImmutable($s->lastActivityAt))->getTimestamp();
        return $idle > $this->settings->sessionIdle;
    }

    /** @return array{0: string, 1: Session} */
    private function create(?int $userId, ?string $ip, ?string $userAgent): array
    {
        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $nowIso = SystemClock::iso($this->clock->now());
        $session = new Session(self::hashId($raw), $userId, bin2hex(random_bytes(32)), $nowIso, $nowIso);
        $this->db->execute(
            'INSERT INTO sessions (id_hash, user_id, csrf_token, created_at, last_activity_at, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$session->idHash, $userId, $session->csrfToken, $nowIso, $nowIso,
             $ip === null || $ip === '' ? null : substr($ip, 0, 45),
             $userAgent === null || $userAgent === '' ? null : mb_substr($userAgent, 0, 255)],
        );
        return [$raw, $session];
    }
}
