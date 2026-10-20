<?php

declare(strict_types=1);

/**
 * 계정 복구 CLI (명세 13: 관리자 계정이 잠겼거나 비밀번호를 잃은 경우)
 *
 * 사용법:
 *   php tools/user_cli.php status <로그인 ID>           계정 상태(역할, 활성, 잠금, 임시 비밀번호)
 *   php tools/user_cli.php unlock <로그인 ID>           잠금 해제
 *   php tools/user_cli.php reset-password <로그인 ID>   임시 비밀번호 발급(유효 시간 = 설정 auth.temp_password_expiry, 첫 로그인 때 변경). 세션은 모두 폐기
 *
 * 임의 계정에 쓸 수 있다. 작업은 감사 로그에 남는다(행위자 cli, cli.user_unlock / cli.user_password_reset).
 * 임시 비밀번호는 화면에만 한 번 출력하고 로그·감사 로그에는 남기지 않는다.
 * 종료 코드: 성공 0, 실패 1
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use PJGuardian\App;
use PJGuardian\Http\ApiException;
use PJGuardian\Services\Actor;
use PJGuardian\Support\ConfigException;

$cmd = $argv[1] ?? '';
$login = $argv[2] ?? '';
if (!in_array($cmd, ['status', 'unlock', 'reset-password'], true) || $login === '') {
    fwrite(STDERR, "사용법: php tools/user_cli.php status|unlock|reset-password <로그인 ID>\n");
    exit(1);
}

try {
    $app = App::create(PJG_ROOT);
    $users = $app->users();
    $id = $users->findIdByLogin($login);
    $show = static function (array $u): void {
        printf("%s (%s) 역할=%s 상태=%s 인증=%s 잠김=%s%s\n", $u['login_id'], $u['display_name'], $u['role'], $u['status'], $u['auth_type'],
            $u['locked'] ? '예(' . $u['locked_until'] . ' UTC까지)' : '아니오',
            $u['must_change_password'] ? ' 비밀번호변경필요' . ($u['temp_password_expires_at'] ? '(임시 ' . $u['temp_password_expires_at'] . ' UTC까지)' : '') : '');
    };
    switch ($cmd) {
        case 'status':
            $show($users->get($id));
            break;
        case 'unlock':
            $u = $users->unlock(Actor::cli(), $id, 'cli.user_unlock');
            echo "잠금을 해제했습니다.\n";
            $show($u);
            break;
        case 'reset-password':
            $r = $users->issueTempPassword(Actor::cli(), $id, 'cli.user_password_reset');
            echo "임시 비밀번호: {$r['temp_password']}\n";
            echo "  - {$r['expires_at']}(UTC)까지 유효하며, 첫 로그인 때 새 비밀번호로 바꿔야 합니다. 잠금도 풀렸습니다.\n";
            echo "  - 이 사용자의 기존 로그인 세션은 모두 끊겼습니다. 이 값은 다시 보여 주지 않습니다.\n";
            $show($r['user']);
            break;
    }
    if ($cmd !== 'status') {
        $app->logger->info('계정 복구 CLI 실행', ['command' => $cmd, 'target_user_id' => $id]);
        echo "감사 로그에 기록했습니다.\n";
    }
    exit(0);
} catch (ApiException $e) {
    fwrite(STDERR, $e->userMessage() . "\n");
    exit(1);
} catch (ConfigException | \RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
