<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * 화면에서 바꿀 수 있는 설정 키의 등록부(P27A). 기본은 ConfigSchema::EDITABLE(허용 목록) + definition()의 형식.
 *
 * 항목: name("섹션.키"), spec(형식·범위·기본값 — ConfigSchema와 같은 모양), group, restart(none|worker|web), machine_specific.
 * ConfigSchema::NOT_EDITABLE에 걸리는 키는 등록할 수 없다(LogicException) — 민감·운영 인프라 값이 실수로 편집 대상이 되지 않게.
 * 시험·격리 점검은 with()로 등록부 밖의 더미 키(예: path 형식)를 더할 수 있다(설정 파일 형식에는 없는 키라 config.ini에는 둘 수 없고
 * settings.local.ini와 기본값만 쓴다). 운영 코드는 default()만 쓴다.
 */
final class SettingsRegistry
{
    public const RESTART = ['none', 'worker', 'web'];

    /** @param array<string, array{spec: array<string, mixed>, group: string, restart: string, machine_specific: bool}> $items */
    private function __construct(private readonly array $items)
    {
        foreach ($items as $name => $item) {
            self::assertAllowed($name);
            if (!in_array($item['restart'], self::RESTART, true)) {
                throw new \LogicException("restart 값이 잘못됨: {$name}");
            }
            if (!array_key_exists('default', $item['spec']) && !self::inSchema($name)) {
                throw new \LogicException("등록부 밖 키에는 기본값이 필요함: {$name}");
            }
        }
    }

    public static function default(): self
    {
        $schema = ConfigSchema::definition();
        $items = [];
        foreach (ConfigSchema::EDITABLE as $name => $meta) {
            [$section, $key] = explode('.', $name, 2);
            if (!isset($schema[$section][$key])) {
                throw new \LogicException("허용 목록의 키가 ConfigSchema에 없음: {$name}");
            }
            $items[$name] = ['spec' => $schema[$section][$key], 'group' => $meta['group'], 'restart' => $meta['restart'],
                'machine_specific' => (bool) ($meta['machine_specific'] ?? false)];
        }
        return new self($items);
    }

    /**
     * 시험·격리 점검용: 항목을 더한(또는 바꾼) 새 등록부.
     *
     * @param array<string, array{spec: array<string, mixed>, group: string, restart?: string, machine_specific?: bool}> $extra
     */
    public function with(array $extra): self
    {
        $items = $this->items;
        foreach ($extra as $name => $item) {
            $items[$name] = ['spec' => $item['spec'], 'group' => $item['group'], 'restart' => $item['restart'] ?? 'none',
                'machine_specific' => (bool) ($item['machine_specific'] ?? false)];
        }
        return new self($items);
    }

    public function has(string $name): bool
    {
        return isset($this->items[$name]);
    }

    /** @return array{spec: array<string, mixed>, group: string, restart: string, machine_specific: bool} */
    public function get(string $name): array
    {
        return $this->items[$name] ?? throw new \LogicException("등록되지 않은 설정 키: {$name}");
    }

    /** @return array<string, array{spec: array<string, mixed>, group: string, restart: string, machine_specific: bool}> */
    public function all(): array
    {
        return $this->items;
    }

    /** 환경 변수 이름(PJG_<섹션>_<키> 대문자). 시크릿의 PJG_<키>와 겹치지 않는다(섹션이 붙음) */
    public static function envName(string $name): string
    {
        return 'PJG_' . strtoupper(str_replace('.', '_', $name));
    }

    /** ConfigSchema(config.ini 형식)에 있는 키인지 — 아니면 시험용 더미(설정 파일에는 settings.local.ini에만) */
    public static function inSchema(string $name): bool
    {
        [$section, $key] = explode('.', $name, 2) + [1 => ''];
        return isset(ConfigSchema::definition()[$section][$key]);
    }

    private static function assertAllowed(string $name): void
    {
        if (preg_match('/^[a-z_]+\.[a-z_]+$/', $name) !== 1) {
            throw new \LogicException("설정 키 이름 형식: {$name}");
        }
        if (in_array($name, ConfigSchema::NOT_EDITABLE_EXCEPTIONS, true)) {
            return;
        }
        foreach (ConfigSchema::NOT_EDITABLE as $deny) {
            if ($name === $deny || (str_ends_with($deny, '.') && str_starts_with($name, $deny))) {
                throw new \LogicException("편집 금지 키: {$name}");
            }
        }
    }
}
