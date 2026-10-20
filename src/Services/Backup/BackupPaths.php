<?php

declare(strict_types=1);

namespace PJGuardian\Services\Backup;

use PJGuardian\Services\Settings\PathPolicy;
use PJGuardian\Support\Config;
use PJGuardian\Support\Fs;

/**
 * 백업·복원 파일 위치(P27B).
 *
 * - 백업 폴더 = [backup] dir(프로젝트 루트 기준 상대 또는 절대·UNC, 기본 <프로젝트>/backups — 웹 루트 public/ 밖). paths.backups_dir는 읽지 않는다.
 *   폴더 안: 묶음 `pjguardian-<YYYYMMDD-HHMMSS>-<종류>.pjgbak`, 곁의 manifest 사본 `<묶음>.json`, 상태 `backup_state.json`, `.gitignore`(*),
 *   프로젝트 안이면 `.htaccess`(Require all denied — 웹 서비스 범위 밖이지만 방어적으로).
 * - 실행 파일(DB가 아님 — 복원이 DB를 바꾸므로): <work_dir>/run/ 의 maintenance.flag, restore_state.json, restore_request.json,
 *   backup_job.json(요청), backup_progress.json(진행), backup_cancel(취소), backup_upload/(묶음 올리기 받는 중).
 */
final class BackupPaths
{
    public function __construct(
        private readonly string $root,
        private readonly Config $config,
        private readonly string $workRoot,
    ) {
    }

    /** 백업 폴더(정규화한 절대 경로, "/" 구분) */
    public function dir(): string
    {
        $v = str_replace('\\', '/', trim((string) $this->config->get('backup.dir')));
        $abs = preg_match('#^([A-Za-z]:/|/)#', $v) === 1 ? $v : rtrim(str_replace('\\', '/', $this->root), '/') . '/' . $v;
        $real = @realpath($abs);
        return rtrim(str_replace('\\', '/', $real === false ? $abs : $real), '/');
    }

    public function runDir(): string
    {
        return rtrim(str_replace('\\', '/', $this->workRoot), '/') . '/run';
    }

    public function stateFile(): string
    {
        return $this->dir() . '/backup_state.json';
    }

    public function maintenanceFlag(): string
    {
        return $this->runDir() . '/maintenance.flag';
    }

    public function restoreState(): string
    {
        return $this->runDir() . '/restore_state.json';
    }

    public function restoreRequest(): string
    {
        return $this->runDir() . '/restore_request.json';
    }

    public function jobRequest(): string
    {
        return $this->runDir() . '/backup_job.json';
    }

    public function jobProgress(): string
    {
        return $this->runDir() . '/backup_progress.json';
    }

    public function jobCancel(): string
    {
        return $this->runDir() . '/backup_cancel';
    }

    public function uploadRoot(): string
    {
        return $this->runDir() . '/backup_upload';
    }

    /** 묶음 이름 → 백업 폴더 안의 경로. 이름 형식이 아니면 null(사용자 입력으로 경로를 만들지 않는다) */
    public function bundle(string $name): ?string
    {
        return BundleFormat::validName($name) ? $this->dir() . '/' . $name : null;
    }

    /** 백업 폴더가 프로젝트 폴더 안인가(기본 backups/) */
    public function insideProject(): bool
    {
        $root = rtrim(str_replace('\\', '/', (string) (realpath($this->root) ?: $this->root)), '/') . '/';
        $dir = $this->dir() . '/';
        return PHP_OS_FAMILY === 'Windows' ? str_starts_with(strtolower($dir), strtolower($root)) : str_starts_with($dir, $root);
    }

    /**
     * 백업 폴더 준비: PathPolicy로 검사(없으면 만듦·쓰기 시험) → .gitignore, 프로젝트 안이면 .htaccess.
     *
     * @return array{code: string, path: ?string, free_bytes: ?int}
     * @throws BackupException 쓸 수 없는 폴더(backup_dir_invalid, params code = PathPolicy 결과)
     */
    public function prepare(PathPolicy $policy): array
    {
        $r = $policy->check((string) $this->config->get('backup.dir'), true);
        if (!in_array($r['code'], ['ok', 'warn_same_volume'], true)) {
            throw new BackupException('backup_dir_invalid', ['code' => $r['code']]);
        }
        $dir = $this->dir();
        if (!is_file($dir . '/.gitignore')) {
            @file_put_contents($dir . '/.gitignore', "# PJGuardian 백업 폴더(P27B) — 백업 파일은 git에 올리지 않는다\n*\n!.gitignore\n!.htaccess\n");
        }
        if ($this->insideProject() && !is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "# PJGuardian 백업 폴더 — 웹 서버가 직접 서비스하지 않는다(내려받기는 PHP가 인증 뒤 스트리밍)\nRequire all denied\n");
        }
        Fs::ensureDir($this->runDir());
        return ['code' => $r['code'], 'path' => $r['path'], 'free_bytes' => $r['free_bytes']];
    }
}
