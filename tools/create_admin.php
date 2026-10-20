<?php

declare(strict_types=1);

/**
 * 최초 관리자 생성 + 하드닝 점검 (명세 13, 19.5)
 *
 * 사용법:
 *   php tools/create_admin.php --login=<로그인 ID> --name=<표시 이름> [--email=<이메일>]
 *        [--password-stdin] [--url=<기준 주소>] [--resolve-local] [--insecure] [--skip-http] [--config=<config.ini>]
 *
 *   비밀번호: 기본은 고정 초기 비밀번호 admin(X02, 사용자 확정 결정 — 개발·운영 모두). 첫 로그인 때 변경을 강제하지 않으며(2026-10-13 사용자 결정) 만료도 없다.
 *             바꾸기 전까지 하드닝 점검이 "관리자 초기 비밀번호" 경고를 낸다.
 *             비밀번호 정책의 유일한 예외로, UserService::createInitialAdmin() 한 곳에만 있다.
 *             --password-stdin이면 표준 입력 첫 줄을 비밀번호로 쓴다(정책 적용, 변경 강제 없음).
 *             명령행 인자로 비밀번호를 받지 않는다(프로세스 목록·셸 기록에 남지 않게).
 *   이미 활성 관리자가 있으면 만들지 않는다(추가 관리자는 화면에서, 복구는 tools/user_cli.php).
 *   하드닝 점검: 생성 뒤 항상 실행한다. HTTP 점검은 app.base_url(또는 --url)에 요청한다.
 *     --resolve-local  호스트 이름 대신 127.0.0.1로 접속(hosts에 개발용 이름이 없을 때)
 *     --insecure       인증서 검증 끔(자체 서명 인증서를 쓰는 개발 PC 전용)
 *     --skip-http      HTTP 점검 건너뜀
 *     --config=<ini>   다른 설정 파일(시험·E2E용 격리 DB, P19). 생략하면 config/config.ini
 *   php tools/create_admin.php --check-only [...]   관리자를 만들지 않고 하드닝 점검만
 * 종료 코드: 생성(또는 점검) 성공 0, 실패 1. 하드닝 경고가 있어도 0(경고는 출력으로 알림)
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use PJGuardian\App;
use PJGuardian\Http\ApiException;
use PJGuardian\Services\Actor;
use PJGuardian\Services\HardeningCheck;
use PJGuardian\Support\ConfigException;
use PJGuardian\Support\HttpProbe;

$opt = getopt('', ['login:', 'name:', 'email:', 'password-stdin', 'url:', 'resolve-local', 'insecure', 'skip-http', 'check-only', 'config:']);

try {
    $configPath = isset($opt['config']) ? realpath((string) $opt['config']) : null;
    if ($configPath === false) {
        fwrite(STDERR, "설정 파일을 찾을 수 없습니다: {$opt['config']}
");
        exit(1);
    }
    $app = App::create(PJG_ROOT, $configPath !== null ? \PJGuardian\Support\Config::load($configPath) : null);
} catch (ConfigException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

if (!isset($opt['check-only'])) {
    $login = $opt['login'] ?? null;
    $name = $opt['name'] ?? null;
    if (!is_string($login) || !is_string($name)) {
        fwrite(STDERR, "사용법: php tools/create_admin.php --login=<로그인 ID> --name=<표시 이름> [--email=<이메일>] [--password-stdin]\n");
        exit(1);
    }
    try {
        $db = $app->db();
        if ($db->value("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'sessions'") === null) {
            fwrite(STDERR, "DB 스키마가 없습니다. 먼저 php tools/migrate.php 를 실행하세요.\n");
            exit(1);
        }
        if ($app->users()->activeAdminCount() > 0) {
            fwrite(STDERR, "이미 활성 관리자가 있어 만들지 않습니다. 추가 관리자는 사용자 관리 화면에서 만들고,\n"
                . "잠금·비밀번호 분실은 php tools/user_cli.php unlock|reset-password <로그인 ID> 로 복구하세요.\n");
            exit(1);
        }
        $email = is_string($opt['email'] ?? null) ? $opt['email'] : null;
        if (isset($opt['password-stdin'])) {
            $line = fgets(STDIN);
            $password = $line === false ? '' : rtrim($line, "\r\n");
            $r = $app->users()->createAdmin(Actor::cli(), $login, $name, $email, $password);
        } else {
            $r = ['user' => $app->users()->createInitialAdmin(Actor::cli(), $login, $name, $email), 'temp_password' => null, 'initial' => true];
        }
    } catch (ApiException $e) {
        fwrite(STDERR, '관리자를 만들지 못했습니다: ' . $e->userMessage() . "\n");
        exit(1);
    } catch (\RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    $app->logger->info('최초 관리자 생성(CLI)', ['target_user_id' => $r['user']['id'], 'login_id' => $r['user']['login_id']]);
    echo "관리자를 만들었습니다: {$r['user']['login_id']} ({$r['user']['display_name']})\n";
    if (($r['initial'] ?? false) === true) {
        echo "초기 비밀번호는 admin입니다(첫 로그인 때 변경을 강제하지 않음).\n";
        echo "  - 누구나 아는 값입니다. 외부에 공개하는 서버라면 로그인 뒤 내 정보에서 바꾸세요. docs/hardening.md 0절\n";
    } elseif ($r['temp_password'] !== null) {
        echo "임시 비밀번호: {$r['temp_password']}\n";
        echo "  - {$r['user']['temp_password_expires_at']}(UTC)까지 유효하며, 첫 로그인 때 새 비밀번호로 바꿔야 합니다.\n";
        echo "  - 이 값은 다시 보여 주지 않습니다. 화면 기록·터미널 로그에 남지 않게 주의하세요.\n";
    }
    echo "감사 로그에 기록했습니다(user.create, 행위자 cli).\n";
}

// ---- 하드닝 점검 ----
$iniFile = php_ini_loaded_file();
$ini = $iniFile !== false ? (@parse_ini_file($iniFile, false, INI_SCANNER_RAW) ?: []) : [];
$baseUrl = is_string($opt['url'] ?? null) ? $opt['url'] : $app->config->string('app.base_url');
$fetch = isset($opt['skip-http']) ? null : static fn (string $path): ?array => HttpProbe::get(
    $baseUrl,
    $path,
    isset($opt['resolve-local']) ? '127.0.0.1' : null,
    !isset($opt['insecure']),
);
$xampp = null;
foreach ([dirname(PHP_BINARY), dirname(PHP_BINARY, 2)] as $dir) {
    if (is_dir($dir . '/htdocs') && is_dir($dir . '/apache')) {
        $xampp = str_replace('\\', '/', $dir);
        break;
    }
}
$pending = static function () use ($app): array {
    try {
        return $app->users()->initialPasswordPendingAdmins();
    } catch (\Throwable) {
        return []; // DB가 없거나 스키마 전이면 판정하지 않음(위에서 이미 안내)
    }
};
$results = (new HardeningCheck(PJG_ROOT, $app->config, $app->paths, $ini, $fetch, $xampp, $pending))->run();

echo "\n== 하드닝 점검 (명세 19.5, 체크리스트 docs/hardening.md)\n";
echo '   php.ini: ' . ($iniFile ?: '(없음)') . " (CLI 기준. XAMPP Windows는 Apache와 같은 파일)\n";
echo '   HTTP 점검 대상: ' . ($fetch === null ? '(건너뜀)' : $baseUrl . (isset($opt['resolve-local']) ? ' → 127.0.0.1' : '') . (isset($opt['insecure']) ? ', 인증서 검증 끔' : '')) . "\n";
$counts = ['OK' => 0, 'WARN' => 0, 'INFO' => 0];
foreach ($results as $r) {
    $counts[$r['status']]++;
    $pad = max(0, 30 - mb_strwidth($r['item'], 'UTF-8'));
    printf("[%-4s] %s%s %s\n", $r['status'], $r['item'], str_repeat(' ', $pad), $r['detail']);
}
printf("\n충족 %d, 경고 %d, 안내 %d\n", $counts['OK'], $counts['WARN'], $counts['INFO']);
if ($counts['WARN'] > 0) {
    echo "경고 항목을 운영 전에 고치세요(docs/hardening.md).\n";
}
exit(0);
