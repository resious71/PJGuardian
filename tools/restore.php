<?php

declare(strict_types=1);

/**
 * 복원(P27B, docs/backup_restore.md). **웹 서버 없이** 쓸 수 있다(다른 PC로 옮길 때 새 PC에는 로그인할 관리자가 없음). 웹과 같은 RestoreService.
 *
 *   php tools/restore.php --file=<묶음 경로 또는 이름> [--key=<.pjgkey>] [--with-config] [--dry-run] [--yes] [--config=<ini>]
 *       사전 검증·미리보기 → (키 가져오기: 암호문구는 프롬프트, 비대화형은 환경 변수 PJG_KEY_PASSPHRASE만) → 확인("복원" 입력, --yes면 생략) → 복원.
 *       --with-config: 설정 차이를 보여 주고 고른다(PC 고유 항목·[backup] config_machine_keys는 기본 제외). --yes와 함께면 PC 고유가 아닌 항목만.
 *   php tools/restore.php --file=… --check-bin          묶음의 bin/ 도구·버전·체크섬 목록과 이 PC의 bin/ 비교(누락·버전·체크섬 차이)
 *   php tools/restore.php --leave-maintenance           남은 유지보수 플래그 끄기(복원 프로세스가 비정상 종료한 경우)
 *   php tools/restore.php --request [--config=<ini>]    워커가 띄우는 자식: 웹이 남긴 요청 파일로 복원
 * 워커가 실행 중이면 거부한다(워커가 DB를 열고 있음 — 화면의 복원은 워커가 직접 처리). 종료 코드: 0 성공, 1 실패·거부, 2 사용법·설정 오류.
 * 암호문구·키는 명령줄 인자·로그·출력에 남기지 않는다.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use PJGuardian\App;
use PJGuardian\Services\Backup\BackupException;
use PJGuardian\Services\Backup\BundleFormat;
use PJGuardian\Services\Backup\BundleReader;
use PJGuardian\Services\Backup\KeyVault;
use PJGuardian\Support\Config;
use PJGuardian\Support\Fs;
use PJGuardian\Support\Lang;

$opt = getopt('', ['file:', 'key:', 'with-config', 'dry-run', 'yes', 'force', 'check-bin', 'leave-maintenance', 'request', 'config:', 'help']);
$say = static fn (string $s) => fwrite(STDOUT, $s . "\n");
if (isset($opt['help']) || (!isset($opt['file']) && !isset($opt['request']) && !isset($opt['leave-maintenance']))) {
    $say('사용법: php tools/restore.php --file=<묶음> [--key=<.pjgkey>] [--with-config] [--check-bin] [--dry-run] [--yes] [--config=<ini>] | --leave-maintenance | --request');
    exit(isset($opt['help']) ? 0 : 2);
}
try {
    $configPath = isset($opt['config']) ? (string) $opt['config'] : null;
    $app = App::create(PJG_ROOT, $configPath !== null ? Config::load($configPath, dirname($configPath) . '/secrets.ini', getenv()) : null);
} catch (\Throwable $e) {
    fwrite(STDERR, '설정 오류: ' . $e->getMessage() . "\n(새 PC라면 config/config.ini.example을 config/config.ini로 복사한 뒤 다시 실행)\n");
    exit(2);
}
$fail = static function (BackupException $e): never {
    fwrite(STDERR, '실패: ' . Lang::get('backup.error.' . $e->errorCode, $e->params) . " ({$e->errorCode})\n");
    exit(1);
};
$paths = $app->backupPaths();
$restore = $app->restoreService();
// 강제 종료된 복원이 남았으면 먼저 되돌린다(상태 running인데 그 프로세스가 없음)
if ($restore->recoverInterrupted()) {
    $say('중단된 이전 복원을 되돌렸습니다(restore_state.json 참고).');
}

if (isset($opt['leave-maintenance'])) {
    $app->maintenance()->leave();
    $say('유지보수 플래그를 껐습니다.');
    exit(0);
}

// ---- 워커 자식: 웹 요청 파일 ----
if (isset($opt['request'])) {
    $req = json_decode((string) @file_get_contents($paths->restoreRequest()), true);
    $file = is_array($req) ? $paths->bundle((string) ($req['bundle'] ?? '')) : null;
    if ($file === null) {
        $app->maintenance()->leave();
        exit(1);
    }
    $s = $restore->execute(['bundle' => $file, 'by' => (string) ($req['by'] ?? 'admin'), 'config_keys' => (array) ($req['config_keys'] ?? []),
        'local_keys' => (array) ($req['local_keys'] ?? [])]);
    exit(($s['status'] ?? '') === 'succeeded' ? 0 : 1);
}

// ---- 명령줄 복원 ----
$arg = (string) $opt['file'];
$file = is_file($arg) ? (string) realpath($arg) : ($paths->bundle($arg) ?? '');
if ($file === '' || !is_file($file)) {
    $fail(new BackupException('bundle_not_found'));
}

if (isset($opt['check-bin'])) {
    try {
        $r = new BundleReader($file);
        $r->open();
        $tmp = $paths->runDir() . '/checkbin-' . bin2hex(random_bytes(4));
        $bin = [];
        if (in_array(BundleFormat::BIN, $r->names(), true)) {
            $r->extract(BundleFormat::BIN, $tmp . '/bin.json');
            $bin = (array) json_decode((string) file_get_contents($tmp . '/bin.json'), true);
        }
        $r->close();
        \PJGuardian\Services\Backup\BackupService::removeTree($tmp);
    } catch (BackupException $e) {
        $fail($e);
    }
    $local = $app->backups()->binManifest();
    $have = array_map(static fn (array $t): string => $t['tool'] . '@' . $t['version'], $local['tools']);
    $want = array_map(static fn (array $t): string => $t['tool'] . '@' . $t['version'], (array) ($bin['tools'] ?? []));
    $missing = array_values(array_diff($want, $have));
    $diff = [];
    foreach ((array) ($bin['files'] ?? []) as $f => $content) {
        if (isset($local['files'][$f]) && $local['files'][$f] !== $content) {
            $diff[] = $f;
        }
    }
    $say('묶음의 도구: ' . ($want === [] ? '(없음)' : implode(', ', $want)));
    $say('이 PC의 도구: ' . ($have === [] ? '(없음)' : implode(', ', $have)));
    $say('누락: ' . ($missing === [] ? '없음' : implode(', ', $missing)));
    $say('체크섬 목록이 다름: ' . ($diff === [] ? '없음' : implode(', ', $diff)));
    $say('도구는 OS마다 다시 배치한다(docs/vcs_tools.md·docs/tool_setup.md) — 배치 뒤 php tools/tools_ctl.php verify');
    exit($missing === [] && $diff === [] ? 0 : 1);
}

// 안전: 워커가 돌고 있으면 DB를 바꿀 수 없다
$beat = $app->workerState()->read();
$since = $app->workerState()->secondsSinceBeat();
if (is_array($beat) && ($beat['state'] ?? '') !== 'stopped' && $since !== null && $since <= $app->config->int('worker.heartbeat_stale')) {
    fwrite(STDERR, "워커가 실행 중입니다. 먼저 워커를 정지하세요(php tools/worker.php --stop) — 화면에서 복원하면 워커가 직접 처리합니다.\n");
    exit(1);
}
if (is_file($paths->restoreRequest())) {
    fwrite(STDERR, "화면에서 요청한 복원이 기다리는 중입니다(restore_request.json). 겹쳐 실행하지 않습니다.\n");
    exit(1);
}

try {
    $pre = $restore->precheck($file);
} catch (BackupException $e) {
    $fail($e);
}
$say('== 묶음 ' . $pre['bundle'] . ' (' . number_format($pre['size'] / 1048576, 1) . 'MB)');
$say('만든 시각 ' . ($pre['created_at_local'] ?? '-') . ', PC ' . ($pre['host'] ?? '-') . ' (' . ($pre['os'] ?? '-') . '), 앱 ' . ($pre['app_version'] ?? '-')
    . ', 스키마 ' . $pre['schema_version'] . ($pre['migrate'] ? " → 복원 뒤 {$pre['app_schema']}까지 마이그레이션" : ''));
foreach ($pre['counts'] as $t => $n) {
    $say(sprintf('  %-9s 백업 %8d  지금 %s', $t, $n, $pre['current'] === null ? '(없음)' : (string) $pre['current']['counts'][$t]));
}
if ($pre['loss'] !== null) {
    $say("이 시각 이후의 변경은 사라집니다: 분석 {$pre['loss']['analyses']}건, 결함 변경 {$pre['loss']['defect_changes']}건, 계정 변경 {$pre['loss']['accounts']}건");
}
$say('스냅샷: ' . ($pre['snapshots'] ? "포함({$pre['snapshot_count']}개)" : '없음(지금 스냅샷 폴더 유지)') . ', 비운 설정: ' . (implode(', ', array_merge(...array_values(array_map('array_values', $pre['blanked'] ?: [[]])))) ?: '없음'));
$say('저장소 자격 증명: ' . ['ok' => '지금 키로 풀림', 'mismatch' => '지금 키로 풀리지 않음(--key로 가져오기, 없으면 연동만 "다시 입력")', 'none' => '없음'][$pre['key']]);

// 설정 고르기
$configKeys = [];
$localKeys = [];
if (isset($opt['with-config']) && $pre['config'] !== []) {
    $say('== 설정 차이(민감 값 제외). [*] = 적용, PC 고유 항목은 기본 제외');
    foreach ($pre['config'] as $c) {
        $on = !$c['machine'];
        if (!isset($opt['yes'])) {
            fwrite(STDOUT, sprintf('%s %s: %s → %s %s [%s] ', $c['file'] === 'local' ? '화면' : 'ini', $c['key'], $c['current'] ?? '(없음)', $c['backup'], $c['machine'] ? '(PC 고유)' : '', $on ? 'Y/n' : 'y/N'));
            $a = strtolower(trim((string) fgets(STDIN)));
            $on = $a === '' ? $on : in_array($a, ['y', 'yes'], true);
        } else {
            $say(sprintf('[%s] %s %s: %s → %s', $on ? '*' : ' ', $c['file'] === 'local' ? '화면' : 'ini', $c['key'], $c['current'] ?? '(없음)', $c['backup']));
        }
        if ($on) {
            $c['file'] === 'local' ? $localKeys[] = $c['key'] : $configKeys[] = $c['key'];
        }
    }
}
if (isset($opt['dry-run'])) {
    $say('(--dry-run: 아무것도 바꾸지 않았습니다)');
    exit(0);
}

// 키 가져오기(복호화 시험을 통과해야 적용)
@unlink($restore->pendingKeyFile());
if (isset($opt['key'])) {
    $content = @file_get_contents((string) $opt['key']);
    if ($content === false) {
        $fail(new BackupException('key_file_invalid'));
    }
    $pass = getenv('PJG_KEY_PASSPHRASE');
    if (!is_string($pass) || $pass === '') {
        fwrite(STDOUT, '키 암호문구(입력이 화면에 보일 수 있습니다): ');
        $pass = rtrim((string) fgets(STDIN), "\r\n");
    }
    try {
        $keys = $app->keyVault()->import((string) $content, $pass);
        $test = $restore->testKeyAgainstBundle($file, $keys);
        if ($test === false) {
            throw new BackupException('key_test_failed');
        }
    } catch (BackupException $e) {
        $fail($e);
    } finally {
        $pass = null;
    }
    Fs::atomicWrite($restore->pendingKeyFile(), (string) json_encode(['encryption_key' => $keys['encryption_key'], 'encryption_key_previous' => $keys['encryption_key_previous']]));
    $say('키 확인: 지문 ' . $keys['fingerprint'] . ($test === null ? '(묶음에 자격 증명이 없어 시험하지 않음)' : ', 묶음의 자격 증명이 풀림'));
}

if (!isset($opt['yes'])) {
    fwrite(STDOUT, "지금 데이터를 이 백업으로 바꿉니다. 계속하려면 \"복원\"을 입력하세요: ");
    if (trim((string) fgets(STDIN)) !== '복원') {
        @unlink($restore->pendingKeyFile());
        $say('취소했습니다.');
        exit(1);
    }
}
$s = $restore->execute(['bundle' => $file, 'by' => 'cli', 'config_keys' => $configKeys, 'local_keys' => $localKeys]);
$say('결과: ' . ($s['status'] ?? '?') . (($s['error']['code'] ?? null) ? ' — ' . Lang::get('backup.error.' . $s['error']['code'], (array) ($s['error']['params'] ?? [])) . " ({$s['error']['code']})" : ''));
foreach ((array) ($s['manual'] ?? []) as $m) {
    $say("  수동 복구: {$m['move']} → {$m['to']}");
}
if (($s['status'] ?? '') !== 'succeeded') {
    exit(1);
}
$r = (array) ($s['result'] ?? []);
$say('세션 ' . ($r['sessions_cleared'] ?? 0) . '개 무효(모두 다시 로그인), 설정 ' . ($r['config_applied'] ?? 0) . '개 적용, 키 ' . (($r['key_imported'] ?? false) ? '가져옴' : '그대로')
    . ', 다시 입력할 저장소 연결 ' . ($r['credentials_unreadable'] ?? 0) . '개');
$say('다음: php tools/migrate.php --status, php tools/check_env.php, php tools/tools_ctl.php verify');
$say('PC 고유 설정 점검: app.base_url, paths.*, tools.node_path, [backup] dir(설정 관리), SMTP·LDAP 비밀번호(secrets.ini), 허용 호스트 도달성(연결 테스트), Apache vhost');
exit(0);
