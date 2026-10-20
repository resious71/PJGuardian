<?php

declare(strict_types=1);

/**
 * 백업(P27B, docs/backup_restore.md). 웹 화면·워커와 같은 서비스(Services\Backup)를 쓴다.
 *
 *   php tools/backup.php [--kind=manual|pre_upgrade] [--no-snapshots] [--config=<ini>]   묶음 만들기(만든 직후 검증)
 *   php tools/backup.php --list [--config=<ini>]                                       목록(최신순)
 *   php tools/backup.php --verify[=<묶음 이름>]                                          다시 검증(기본 최신)
 *   php tools/backup.php --prune                                                       보관 정리(retention_days·retention_min_count·safety_retention_days)
 *   php tools/backup.php --rehearse[=<묶음 이름>]                                        복원 리허설(임시 폴더, 운영 데이터 변경 없음, 기본 최신)
 *   php tools/backup.php --job [--config=<ini>]                                        워커가 띄우는 자식: 요청 파일의 작업 하나 실행
 * 종료 코드: 0 성공, 1 실패, 2 사용법·설정 오류. 업그레이드 직전에는 --kind=pre_upgrade(업그레이드 절차에 자동으로 끼우지 않음).
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use PJGuardian\App;
use PJGuardian\Services\Backup\BackupException;
use PJGuardian\Support\Config;
use PJGuardian\Support\Lang;

$opt = getopt('', ['kind:', 'no-snapshots', 'list', 'verify::', 'prune', 'rehearse::', 'job', 'config:', 'help']);
if (isset($opt['help'])) {
    fwrite(STDOUT, "사용법: php tools/backup.php [--kind=manual|pre_upgrade] [--no-snapshots] | --list | --verify[=이름] | --prune | --rehearse[=이름] | --job  [--config=<ini>]\n");
    exit(0);
}
try {
    $configPath = isset($opt['config']) ? (string) $opt['config'] : null;
    $app = App::create(PJG_ROOT, $configPath !== null ? Config::load($configPath, dirname($configPath) . '/secrets.ini', getenv()) : null);
} catch (\Throwable $e) {
    fwrite(STDERR, '설정 오류: ' . $e->getMessage() . "\n");
    exit(2);
}
$say = static fn (string $s) => fwrite(STDOUT, $s . "\n");
$err = static fn (BackupException $e) => fwrite(STDERR, '실패: ' . Lang::get('backup.error.' . $e->errorCode, $e->params) . " ({$e->errorCode})\n");
$backups = $app->backups();
$latest = static function () use ($backups): ?string {
    foreach ($backups->list() as $b) {
        return $b['name'];
    }
    return null;
};

try {
    if (isset($opt['job'])) {
        $job = $app->backupJobs()->active();
        if ($job === null) {
            exit(0);
        }
        $r = $app->backupRunner()->run($job);
        $say(($r['ok'] ? '완료' : '실패') . ': ' . ($r['name'] ?? '-') . ($r['code'] !== null ? " ({$r['code']})" : ''));
        exit($r['ok'] ? 0 : 1);
    }
    if (isset($opt['list'])) {
        foreach ($backups->list() as $b) {
            $v = $b['verified'] === null ? '검증 전' : (($b['verified']['ok'] ?? false) ? '검증됨' : '검증 실패(' . ($b['verified']['code'] ?? '') . ')');
            $say(sprintf('%-48s %-11s %8.1fMB  스냅샷 %-3s 스키마 %-4s %s', $b['name'], (string) $b['kind'], $b['size'] / 1048576, $b['snapshots'] ? '예' : '아니오', (string) $b['schema_version'], $v));
        }
        $s = $backups->state()->read();
        $say('백업 폴더: ' . $backups->paths()->dir());
        $say('마지막 성공: ' . ($s['last_success']['at'] ?? '-') . '  마지막 리허설: ' . ($s['last_rehearsal']['at'] ?? '-'));
        exit(0);
    }
    if (array_key_exists('verify', $opt)) {
        $name = is_string($opt['verify']) && $opt['verify'] !== '' ? $opt['verify'] : $latest();
        if ($name === null) {
            throw new BackupException('bundle_not_found');
        }
        $r = $backups->verify($name);
        $say(($r['ok'] ? '검증 통과' : '검증 실패(' . $r['code'] . ')') . ": {$name} ({$r['ms']}ms)");
        $app->audit()->record(\PJGuardian\Services\Actor::cli(), 'backup.verify', 'backup', $name, ['result' => $r['ok'] ? 'ok' : 'failed', 'code' => $r['code']]);
        exit($r['ok'] ? 0 : 1);
    }
    if (isset($opt['prune'])) {
        $d = $backups->prune();
        $say('지운 묶음 ' . count($d) . '개' . ($d === [] ? '' : ': ' . implode(', ', $d)));
        if ($d !== []) {
            $app->audit()->record(\PJGuardian\Services\Actor::cli(), 'backup.delete', 'backup', null, ['files' => $d, 'reason' => 'retention']);
        }
        exit(0);
    }
    if (array_key_exists('rehearse', $opt)) {
        $name = is_string($opt['rehearse']) && $opt['rehearse'] !== '' ? $opt['rehearse'] : $latest();
        if ($name === null) {
            throw new BackupException('bundle_not_found');
        }
        $job = $app->backupJobs()->request(['type' => 'rehearsal', 'file' => $name, 'by' => 'cli', 'by_id' => null]);
        $r = $app->backupRunner()->run($job);
        $s = $backups->state()->read()['last_rehearsal'] ?? [];
        $say(($r['ok'] ? '리허설 통과' : '리허설 실패(' . $r['code'] . ')') . ": {$name} (" . ($s['ms'] ?? '-') . 'ms, 스키마 ' . ($s['schema'] ?? '-') . ')');
        exit($r['ok'] ? 0 : 1);
    }
    $kind = (string) ($opt['kind'] ?? 'manual');
    if (!in_array($kind, ['manual', 'pre_upgrade'], true)) {
        fwrite(STDERR, "--kind는 manual 또는 pre_upgrade\n");
        exit(2);
    }
    $job = $app->backupJobs()->request(['type' => 'backup', 'kind' => $kind, 'snapshots' => !isset($opt['no-snapshots']), 'by' => 'cli', 'by_id' => null]);
    $say('백업 중… (폴더 ' . $backups->paths()->dir() . ')');
    $r = $app->backupRunner()->run($job);
    if (!$r['ok']) {
        fwrite(STDERR, '실패: ' . Lang::get('backup.error.' . $r['code']) . " ({$r['code']})\n");
        exit(1);
    }
    $b = array_values(array_filter($backups->list(), static fn (array $x): bool => $x['name'] === $r['name']))[0] ?? null;
    $say(sprintf('완료: %s (%.1fMB, 검증됨)', $r['name'], ($b['size'] ?? 0) / 1048576));
    exit(0);
} catch (BackupException $e) {
    $err($e);
    exit(1);
}
