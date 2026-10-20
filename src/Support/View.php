<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * PHP 템플릿 렌더러(src/Views/*.php). docs/architecture.md "화면" 절.
 *
 * 템플릿 안에서 쓸 수 있는 값:
 *   $e(string)            HTML 이스케이프(모든 동적 텍스트에 쓴다)
 *   $t(key, params)       문자열 리소스
 *   $icons                Support\Icons (SVG를 직접 쓰지 않는다)
 *   $assets               Support\Assets (자산 주소)
 *   $view                 이 렌더러(부분 템플릿: $view->partial('partials/x', [...]))
 *   그 밖에 render()에 넘긴 변수
 * 템플릿에 인라인 <script>·<style>·style 속성을 쓰지 않는다(CSP script-src 'self', style-src 'self').
 */
final class View
{
    public function __construct(
        private readonly string $dir,
        public readonly Icons $icons,
        public readonly Assets $assets,
        /** @var array<string, mixed> 모든 템플릿에 공통으로 넘길 값(시간대 등) */
        private readonly array $shared = [],
    ) {
    }

    /** @param array<string, mixed> $vars */
    public function render(string $template, array $vars = []): string
    {
        $file = $this->dir . '/' . $template . '.php';
        if (!is_file($file) || str_contains($template, '..')) {
            throw new \LogicException('템플릿 없음: ' . $template);
        }
        $vars = array_merge($this->shared, $vars, [
            'e' => static fn (?string $s): string => Html::e((string) $s),
            't' => static fn (string $key, array $params = []): string => Lang::get($key, $params),
            'icons' => $this->icons,
            'assets' => $this->assets,
            'view' => $this,
        ]);
        $render = static function (string $__file, array $__vars): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            try {
                require $__file;
                return (string) ob_get_contents();
            } finally {
                ob_end_clean();
            }
        };
        return $render($file, $vars);
    }

    /** @param array<string, mixed> $vars */
    public function partial(string $template, array $vars = []): string
    {
        return $this->render($template, $vars);
    }

    /**
     * 레이아웃 안에 본문 템플릿을 넣어 완성된 HTML을 만든다.
     *
     * @param array{title:string, user?:array{login_id:string,display_name:string,role:string}|null, active?:string|null,
     *              banners?:list<array{level:string,message:string}>, scripts?:list<string>, body_class?:string} $page
     * @param array<string, mixed> $vars 본문 템플릿 변수
     */
    public function page(string $template, array $page, array $vars = []): string
    {
        // P29 도움말 링크: 화면 → 가이드 절(GuideLinks 한 곳). 레이아웃이 머리 오른쪽에 그린다
        $page['help'] ??= GuideLinks::forTemplate($template);
        // P29C: 관리자 가이드 링크는 admin에게만(그룹 목록·상세처럼 그룹 관리자도 여는 화면 — 눌러도 403이라 그리지 않는다)
        if (is_array($page['help']) && $page['help'][0] === 'admin' && (($page['user']['role'] ?? null) !== 'admin')) {
            $page['help'] = null;
        }
        $content = $this->render($template, $vars + ['page' => $page]);
        $layout = array_key_exists('user', $page) && $page['user'] !== null ? 'layout' : 'layout_guest';
        return $this->render($layout, ['page' => $page, 'content' => $content]);
    }
}
