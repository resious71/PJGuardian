<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Support;

use PJGuardian\Services\AuditLog;
use PJGuardian\Services\Auth\AuthSettings;
use PJGuardian\Services\Auth\LoginService;
use PJGuardian\Services\Auth\SessionService;
use PJGuardian\Services\UserService;
use PJGuardian\Support\Db;
use PJGuardian\Support\FixedClock;
use PJGuardian\Support\Logger;

/**
 * 인증 서비스 시험용 조립: 마이그레이션한 메모리 DB, 고정 시계, 설정 예시 값(bcrypt 비용만 4로 낮춤).
 * 시간 의존 시험은 $clock->advance()로 흘려보낸다.
 */
final class AuthFixture
{
    public readonly Db $db;
    public readonly FixedClock $clock;
    public readonly AuthSettings $settings;
    public readonly Logger $logger;
    public readonly SessionService $sessions;
    public readonly AuditLog $audit;
    public readonly LoginService $login;
    public readonly UserService $users;

    /** @param array<string, string> $authOverrides 설정 [auth] 덮어쓰기 */
    public function __construct(string $logDir, array $authOverrides = [])
    {
        $this->db = TestDb::migrated();
        $this->clock = new FixedClock(TestDb::T0);
        $this->settings = AuthSettings::fromConfig(TestApp::config(['auth' => $authOverrides + ['bcrypt_cost' => '4']]));
        $this->logger = new Logger($logDir, $this->clock, 'INFO');
        $this->sessions = new SessionService($this->db, $this->clock, $this->settings);
        $this->audit = new AuditLog($this->db, $this->clock);
        $this->login = new LoginService($this->db, $this->clock, $this->settings, $this->logger);
        $this->users = new UserService($this->db, $this->clock, $this->settings, $this->sessions, $this->audit, $this->logger);
    }

    /** 로컬 계정 생성(비밀번호 해시는 설정 비용으로). id를 돌려준다. */
    public function user(string $login, string $password = 'Passw0rd!x', string $role = 'user', string $status = 'active'): int
    {
        $now = TestDb::T0;
        $this->db->execute(
            'INSERT INTO users (login_id, display_name, role, status, auth_type, password_hash, created_at, updated_at)'
            . " VALUES (?, ?, ?, ?, 'local', ?, ?, ?)",
            [$login, $login, $role, $status, password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]), $now, $now],
        );
        return $this->db->lastInsertId();
    }

    /** @return array<string, mixed> */
    public function row(int $id): array
    {
        return (array) $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public function logText(string $logDir): string
    {
        $text = '';
        foreach (scandir($logDir) ?: [] as $f) {
            if (str_ends_with($f, '.log')) {
                $text .= (string) file_get_contents($logDir . '/' . $f);
            }
        }
        return $text;
    }
}
