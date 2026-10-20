<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * 아이콘 모듈(PHP). 의미 이름 → 아이콘 매핑은 public/assets/icons/icons.json 하나에서만 관리한다(명세 20.2).
 * 화면 코드(템플릿)는 SVG를 직접 쓰지 않고 이 헬퍼만 쓴다. JS 쪽은 public/assets/js/ui/icons.js가 같은 JSON을 읽는다.
 *
 * - icon(name, size): 장식용(aria-hidden). 옆에 텍스트가 있을 때 쓴다.
 * - meaningful(name, size): 의미를 전달하는 아이콘(role="img" + aria-label). 아이콘만 있을 때 쓴다.
 * - withLabel(name, size): 아이콘 + 텍스트 라벨(명세 20.1: 아이콘은 텍스트를 대체하지 않는다).
 * - 크기는 16, 20, 24px만(명세 20.2).
 * - 매핑에 없는 이름: strict(app.env=dev)면 LogicException, 운영이면 ui.fallback 아이콘 + WARN 로그.
 */
final class Icons
{
    public const SIZES = [16, 20, 24];

    /** @var array<string, array<string, string>> */
    private array $icons;
    private string $fallback;

    /** @param array<string, mixed> $map icons.json 내용 */
    public function __construct(
        array $map,
        private readonly string $spriteUrl,
        private readonly bool $strict,
        private readonly ?Logger $logger = null,
    ) {
        $this->icons = $map['icons'];
        $this->fallback = (string) $map['fallback'];
        if (!isset($this->icons[$this->fallback])) {
            throw new \LogicException('icons.json의 fallback 이름이 매핑에 없음: ' . $this->fallback);
        }
    }

    public static function fromFile(string $jsonPath, string $spriteUrl, bool $strict, ?Logger $logger = null): self
    {
        $map = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
        return new self($map, $spriteUrl, $strict, $logger);
    }

    public function has(string $name): bool
    {
        return isset($this->icons[$name]);
    }

    /** @return list<string> 의미 이름 목록(매핑 순서) */
    public function names(?string $group = null): array
    {
        return array_keys(array_filter($this->icons, static fn (array $e): bool => $group === null || $e['group'] === $group));
    }

    /** @return array<string, string> */
    private function entry(string $name): array
    {
        if (isset($this->icons[$name])) {
            return $this->icons[$name];
        }
        if ($this->strict) {
            throw new \LogicException("매핑에 없는 아이콘 이름: {$name} (public/assets/icons/icons.json)");
        }
        $this->logger?->warn('매핑에 없는 아이콘 이름 — 일반 아이콘으로 대체', ['icon' => $name]);
        return $this->icons[$this->fallback];
    }

    public function label(string $name): string
    {
        return Lang::get($this->entry($name)['label']);
    }

    public function aria(string $name): string
    {
        return Lang::get($this->entry($name)['aria']);
    }

    public function iconId(string $name): string
    {
        return $this->entry($name)['icon'];
    }

    /** 장식용 아이콘(스크린 리더가 읽지 않음) */
    public function icon(string $name, int $size = 20): string
    {
        return $this->svg($name, $size, null);
    }

    /** 의미를 전달하는 아이콘(텍스트 없이 쓸 때). $ariaLabel을 주지 않으면 매핑의 접근성 라벨 */
    public function meaningful(string $name, int $size = 20, ?string $ariaLabel = null): string
    {
        return $this->svg($name, $size, $ariaLabel ?? $this->aria($name));
    }

    /** 아이콘 + 텍스트 라벨 */
    public function withLabel(string $name, int $size = 16, ?string $text = null): string
    {
        return '<span class="icon-label">' . $this->icon($name, $size)
            . '<span class="icon-label__text">' . Html::e($text ?? $this->label($name)) . '</span></span>';
    }

    private function svg(string $name, int $size, ?string $ariaLabel): string
    {
        if (!in_array($size, self::SIZES, true)) {
            throw new \InvalidArgumentException("아이콘 크기는 16, 20, 24만 쓴다: {$size}");
        }
        $e = $this->entry($name);
        $class = 'icon icon-' . $size . (($e['animate'] ?? '') === 'spin' ? ' icon-spin' : '');
        $a11y = $ariaLabel === null
            ? ' aria-hidden="true" focusable="false"'
            : ' role="img" aria-label="' . Html::e($ariaLabel) . '" focusable="false"';
        return '<svg class="' . $class . '" width="' . $size . '" height="' . $size . '"' . $a11y . ' data-icon="' . Html::e($name) . '">'
            . '<use href="' . Html::e($this->spriteUrl) . '#i-' . Html::e($e['icon']) . '"></use></svg>';
    }
}
