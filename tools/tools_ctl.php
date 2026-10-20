<?php

declare(strict_types=1);

/**
 * 도구 레지스트리 관리 CLI(명세 12, 19.10, P15). 관리자 화면은 P20A(/admin/tools).
 * install·remove는 bin/에 쓰므로 bin/ 쓰기 권한이 있는 **서버 관리자 계정**으로 실행한다(웹 계정은 bin/에 쓰지 않는다, docs/tool_setup.md 5.1).
 *
 * 사용법: php tools/tools_ctl.php [--config=<config.ini>] <명령> [인자]
 *   list [도구]               디스크의 버전과 상태(active·pinned·blocked·invalid·new), 체크섬·스모크 결과
 *   select <도구>             분석에 쓸 버전을 고른다(필요하면 체크섬·스모크를 실행)
 *   verify <도구> [버전]      지문과 관계없이 전체 체크섬 + 스모크 테스트를 다시 한다(버전 생략 시 디스크의 모든 버전)
 *   pin <도구> <버전>         고정(자동 최신 선택 중단). 검증을 통과한 버전만
 *   unpin <도구>              고정 해제
 *   block <도구> <버전>       차단(선택하지 않음)
 *   unblock <도구> <버전>     차단 해제(다음 선택 때 다시 검증)
 *   node                      Node 런타임 경로·버전·최소 버전 검사
 *   install --staging=<id>    관리자 화면에서 올린 패키지 zip을 검사해 bin/<도구>/<버전>/에 설치하고 전체 체크섬 + 스모크(P20A).
 *                             이미 있는 버전은 덮어쓰지 않고, 실패하면 bin/에 아무것도 남기지 않는다. 결과는 화면에 표시된다
 *   remove <도구> <버전>      설치된 버전 삭제(P20A). 고정된 버전·실행 중인 분석이 쓰는 버전·마지막 유효 버전은 거부.
 *                             bin/.trash로 옮긴 뒤 자식 프로세스(purge-trash)가 지운다. 기록(tool_versions)은 이력으로 남긴다
 *   purge-trash               bin/.trash 비우기(remove가 자식으로 부른다)
 * 도구: ruff, eslint, git, p4(install·remove는 ruff, eslint). 종료 코드: 0 성공, 1 실패(검증 실패·쓸 버전 없음·설치/삭제 거부), 2 사용법·설정 오류
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use PJGuardian\App;
use PJGuardian\Services\Tools\ToolInstaller;
use PJGuardian\Services\Tools\ToolRegistry;
use PJGuardian\Support\Config;

$opt = getopt('', ['config:', 'staging:'], $rest);
$args = array_slice($argv, $rest);
$cmd = $args[0] ?? '';
$usage = "사용법: php tools/tools_ctl.php [--config=<ini>] list|select|verify|pin|unpin|block|unblock|node [도구] [버전]\n"
    . "        php tools/tools_ctl.php [--config=<ini>] install --staging=<id> | remove <도구> <버전> | purge-trash\n";
$configPath = isset($opt['config']) ? (string) realpath((string) $opt['config']) : null;
try {
    $app = App::create(PJG_ROOT, $configPath !== null ? Config::load($configPath) : null);
    $reg = $app->toolRegistry();
    $tool = $args[1] ?? null;
    $version = $args[2] ?? null;
    $needTool = static function () use ($tool, $usage): string {
        if (!is_string($tool) || !in_array($tool, ToolRegistry::TOOLS, true)) {
            fwrite(STDERR, $usage);
            exit(2);
        }
        return $tool;
    };
    $needVersion = static function () use ($version, $usage): string {
        if (!is_string($version) || !ToolRegistry::validVersion($version)) {
            fwrite(STDERR, $usage);
            exit(2);
        }
        return $version;
    };
    switch ($cmd) {
        case 'list':
            foreach ($tool !== null ? [$needTool()] : ToolRegistry::TOOLS as $t) {
                $rows = $reg->list($t);
                if ($rows === []) {
                    printf("%-7s (설치된 버전 없음: bin/%s/<버전>/)\n", $t, $t);
                }
                foreach ($rows as $r) {
                    $mark = $r['removed_at'] !== null ? ' [삭제됨]' : ($r['removal_requested_at'] !== null ? ' [삭제 예정]' : '');
                    printf("%-7s %-10s %-8s%s 디스크=%s 체크섬=%s 스모크=%s %s\n", $t, $r['version'], $r['status'], $mark, $r['on_disk'] ? '있음' : '없음',
                        $r['checksum_ok'] === null ? '-' : ((int) $r['checksum_ok'] === 1 ? 'OK' : '실패'),
                        $r['smoke_ok'] === null ? '-' : ((int) $r['smoke_ok'] === 1 ? 'OK' : '실패'), (string) ($r['smoke_message'] ?? ''));
                }
            }
            exit(0);
        case 'select':
            $t = $needTool();
            $s = $reg->select($t);
            if ($s === null) {
                echo "{$t}: 쓸 수 있는 버전이 없습니다\n";
                exit(1);
            }
            echo "{$t}: {$s->label()} ({$s->executable})\n";
            exit(0);
        case 'verify':
            $t = $needTool();
            $versions = $version !== null ? [$needVersion()] : $reg->versionsOnDisk($t);
            $fail = 0;
            foreach ($versions as $v) {
                $r = $reg->ensureVerified($t, $v, true);
                printf("%s %s: %s (%s) %s\n", $t, $v, $r['ok'] ? '통과' : '실패', $r['status'], $r['message']);
                $fail += $r['ok'] ? 0 : 1;
            }
            exit($fail > 0 ? 1 : 0);
        case 'pin':
            $r = $reg->pin($needTool(), $needVersion());
            echo $r['ok'] ? "고정했습니다\n" : "고정할 수 없습니다: {$r['message']}\n";
            exit($r['ok'] ? 0 : 1);
        case 'unpin':
            $reg->unpin($needTool());
            echo "고정을 해제했습니다\n";
            exit(0);
        case 'block':
            $reg->block($needTool(), $needVersion());
            echo "차단했습니다\n";
            exit(0);
        case 'unblock':
            $reg->unblock($needTool(), $needVersion());
            echo "차단을 해제했습니다(다음 선택 때 다시 검증)\n";
            exit(0);
        case 'node':
            $c = $app->nodeRuntime()->check();
            printf("Node: %s, 경로 %s, 버전 %s, 최소 %s%s\n", $c['ok'] ? 'OK' : '문제', $c['path'] ?? '(없음)', $c['version'] ?? '-',
                $app->nodeRuntime()->minVersion(), $c['reason'] !== null ? ' (' . $c['reason'] . ')' : '');
            exit($c['ok'] ? 0 : 1);
        case 'install':
            // getopt는 명령 이름에서 멈추므로 명령 뒤의 --staging=<id>는 직접 읽는다
            $id = (string) ($opt['staging'] ?? '');
            foreach (array_slice($args, 1) as $a) {
                if (str_starts_with($a, '--staging=')) {
                    $id = substr($a, 10);
                }
            }
            if ($id === '') {
                fwrite(STDERR, $usage);
                exit(2);
            }
            $r = $app->toolInstaller()->install($id);
            printf("%s: %s%s%s\n", $r['ok'] ? '설치 완료' : '설치 실패', $r['code'],
                $r['tool'] !== null ? ' ' . $r['tool'] . ($r['version'] !== null ? ' ' . $r['version'] : '') : '', $r['message'] !== '' ? ' — ' . $r['message'] : '');
            exit($r['ok'] ? 0 : 1);
        case 'remove':
            $t = $needTool();
            $v = $needVersion();
            $r = $app->toolInstaller(ToolInstaller::childPurge(PJG_ROOT, $configPath, $app->config->int('worker.extract_timeout')))->remove($t, $v);
            printf("%s: %s %s %s%s\n", $r['ok'] ? '삭제 완료' : '삭제 거부', $r['code'], $t, $v, $r['message'] !== '' ? ' — ' . $r['message'] : '');
            exit($r['ok'] ? 0 : 1);
        case 'purge-trash':
            $n = $app->toolInstaller()->emptyBinTrash();
            echo "bin/.trash에서 {$n}개 지움\n";
            exit(0);
        default:
            fwrite(STDERR, $usage);
            exit(2);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, '오류: ' . $e->getMessage() . "\n");
    exit(2);
}
