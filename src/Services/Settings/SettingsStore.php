<?php

declare(strict_types=1);

namespace PJGuardian\Services\Settings;

use PJGuardian\Support\Config;
use PJGuardian\Support\FileLock;

/**
 * 화면 설정 파일 config/settings.local.ini(P27A) 읽기·쓰기. config.ini는 절대 쓰지 않는다. DB에도 두지 않는다(복원이 DB를 통째로 바꾸므로).
 *
 * - 쓰기: 잠금 전용 파일(settings.local.ini.lock, Support\FileLock — Windows flock은 강제 잠금이라 대상 파일은 잠그지 않는다) 안에서
 *   다시 읽어 버전 표지를 비교 → 임시 파일에 쓰고 fsync → 이름 바꾸기(원자적). 쓰기 도중 프로세스가 죽어도 원본은 그대로이고
 *   남은 임시 파일은 다음 쓰기가 지운다.
 * - 이전 파일은 settings.local.ini.bak.1(가장 최근) ~ .bak.5로 순환 보관한다.
 * - 버전 표지 = 파일 내용 SHA-256(없으면 ''). 화면이 읽을 때의 표지와 다르면 SettingsConflictException(→ 409 settings_conflict).
 *   내용이 같으면(다른 관리자가 바꿨다가 되돌림) 충돌로 보지 않는다 — 덮어써도 잃는 값이 없다.
 * - 파일 권한: Linux에서는 0640(웹 계정만 쓰기). Windows는 폴더 권한을 따른다(docs/settings.md).
 */
final class SettingsStore
{
    public const BACKUPS = 5;

    public function __construct(private readonly string $path)
    {
    }

    public function path(): string
    {
        return $this->path;
    }

    /** @return array{values: array<string, array<string, string>>, version: string, ok: bool} */
    public function read(): array
    {
        [$values, $version, $ok] = Config::readLocal($this->path);
        return ['values' => $values, 'version' => $version, 'ok' => $ok];
    }

    /**
     * 키별 변경을 적용해 저장한다. 값 null = 그 키를 지움(기본값으로). 검증은 호출하는 쪽(SettingsService)이 끝낸 뒤다.
     *
     * @param array<string, ?string> $changes "섹션.키" → 설정 파일 표기 값 또는 null
     * @return string 새 버전 표지
     * @throws SettingsConflictException
     */
    public function write(array $changes, string $expectedVersion): string
    {
        $lock = FileLock::acquire($this->path . '.lock');
        try {
            $current = $this->read();
            if ($current['version'] !== $expectedVersion) {
                throw new SettingsConflictException($current['version']);
            }
            // 깨진 파일은 읽지 못한 값이 있으므로(경고 중) 그대로 두고 백업으로 넘긴 뒤 이번 변경만으로 새로 쓴다
            $values = $current['ok'] ? $current['values'] : [];
            foreach ($changes as $name => $value) {
                [$section, $key] = explode('.', $name, 2);
                if ($value === null) {
                    unset($values[$section][$key]);
                    if (($values[$section] ?? []) === []) {
                        unset($values[$section]);
                    }
                } else {
                    if (preg_match('/["\x00-\x1f\x7f]/', $value) === 1) {
                        throw new \InvalidArgumentException('설정 파일에 쓸 수 없는 문자'); // 검증을 거친 값에는 없다(방어)
                    }
                    $values[$section][$key] = $value;
                }
            }
            $content = self::serialize($values);
            $this->cleanupTemp();
            if (is_file($this->path)) {
                $this->rotateBackups();
            }
            $this->atomicWrite($content);
            return hash('sha256', $content);
        } finally {
            $lock->release();
        }
    }

    /** @param array<string, array<string, string>> $values */
    public static function serialize(array $values): string
    {
        ksort($values);
        $out = "; PJGuardian 화면 설정(관리자 \"시스템 관리 > 설정 관리\"가 씁니다 — 직접 고치지 마세요). docs/settings.md\n";
        foreach ($values as $section => $keys) {
            if ($keys === []) {
                continue;
            }
            ksort($keys);
            $out .= "\n[{$section}]\n";
            foreach ($keys as $key => $value) {
                $out .= "{$key} = \"{$value}\"\n";
            }
        }
        return $out;
    }

    /** @return list<string> 보관 중인 백업 파일(최근 것부터) */
    public function backups(): array
    {
        $out = [];
        for ($i = 1; $i <= self::BACKUPS; $i++) {
            if (is_file($this->path . '.bak.' . $i)) {
                $out[] = $this->path . '.bak.' . $i;
            }
        }
        return $out;
    }

    private function rotateBackups(): void
    {
        @unlink($this->path . '.bak.' . self::BACKUPS);
        for ($i = self::BACKUPS - 1; $i >= 1; $i--) {
            if (is_file($this->path . '.bak.' . $i)) {
                @rename($this->path . '.bak.' . $i, $this->path . '.bak.' . ($i + 1));
            }
        }
        if (!@copy($this->path, $this->path . '.bak.1')) {
            throw new \RuntimeException('설정 파일 백업 실패');
        }
    }

    private function atomicWrite(string $content): void
    {
        $tmp = $this->path . '.tmp-' . bin2hex(random_bytes(4));
        $h = @fopen($tmp, 'xb');
        if ($h === false) {
            throw new \RuntimeException('설정 임시 파일을 만들 수 없음');
        }
        try {
            if (fwrite($h, $content) !== strlen($content) || !fflush($h) || !fsync($h)) {
                throw new \RuntimeException('설정 임시 파일 쓰기 실패');
            }
        } finally {
            fclose($h);
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            @chmod($tmp, 0640);
        }
        // Windows: 읽는 쪽이 파일을 연 순간이면 교체가 잠깐 실패할 수 있다(P14 생존 신호와 같은 사정) → 몇 번 다시
        for ($try = 0; $try < 5; $try++) {
            if (@rename($tmp, $this->path)) {
                return;
            }
            usleep(20_000);
        }
        @unlink($tmp);
        throw new \RuntimeException('설정 파일을 교체할 수 없음');
    }

    /** 쓰기 도중 죽은 프로세스가 남긴 임시 파일 정리(잠금 안에서만 부른다) */
    private function cleanupTemp(): void
    {
        $dir = dirname($this->path);
        $prefix = basename($this->path) . '.tmp-';
        foreach (\PJGuardian\Support\Fs::listDir($dir) as $name) {
            if (str_starts_with($name, $prefix)) {
                @unlink($dir . DIRECTORY_SEPARATOR . $name);
            }
        }
    }
}
