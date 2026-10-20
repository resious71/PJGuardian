<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Http\Middleware\RequireAdmin;
use PJGuardian\Http\Middleware\RequireLogin;
use PJGuardian\Http\Middleware\RequireProjectAccess;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\TestCase;

/**
 * 경로 점검(명세 3.1, 19.10): 프로젝트 또는 그 자식 리소스를 다루는 라우트는 모두 접근 판정(RequireProjectAccess)을 선언해야 한다.
 * 이후 단계(업로드 P12, 분석 P14~P16, 결함 P17~P18, 리포트, 저장소 …)가 새 경로를 등록할 때 이 시험을 통과해야 한다.
 *
 * 판정 대상: 경로가 아래 RESOURCE_PREFIXES 중 하나로 시작하는 라우트(API와 화면 모두).
 * 판정을 붙이지 않을 경로는 EXCEPTIONS에 이유와 함께 넣는다(목록 경로는 쿼리에서 ProjectAccess::scopeFor로 거른다).
 */
final class ProjectRouteAccessTest extends TestCase
{
    /** 프로젝트와 그 자식 리소스의 경로 접두어. 새 자식 리소스를 만들면 여기에 추가한다 */
    private const RESOURCE_PREFIXES = [
        '/api/v1/projects', '/projects',
        '/api/v1/uploads', '/uploads',
        '/api/v1/analyses', '/analyses',
        '/api/v1/defects', '/defects',
        '/api/v1/snapshots',
        '/api/v1/reports', '/reports',
        '/api/v1/repositories',
    ];

    /** 접근 판정을 선언하지 않는 경로와 이유 */
    private const EXCEPTIONS = [
        'GET /api/v1/projects' => '목록: 쿼리에서 ProjectAccess::scopeFor로 보이는 프로젝트만 거른다',
        'POST /api/v1/projects' => '생성: 아직 프로젝트가 없다(관리자 전용)',
        'GET /api/v1/projects/default-excludes' => '프로젝트와 무관한 전역 기본 제외 목록',
        'GET /projects' => '목록 화면: ProjectAccess::scopeFor로 거른다',
        'GET /projects/new' => '생성 화면(관리자 전용)',
        'GET /defects' => '"결함" 메뉴의 프로젝트 선택 화면: 목록은 ProjectAccess::scopeFor로 거른다(프로젝트 id를 받지 않음)',
    ];

    /** @return list<array{method:string, path:string, middleware:list<object>}> */
    private function scoped(): array
    {
        $app = TestApp::make($this->tempDir('routes'));
        $out = [];
        foreach ($app->router->routeTable() as $r) {
            foreach (self::RESOURCE_PREFIXES as $prefix) {
                if ($r['path'] === $prefix || str_starts_with($r['path'], $prefix . '/')) {
                    $out[] = $r;
                    break;
                }
            }
        }
        return $out;
    }

    public function testEveryProjectRouteDeclaresAccessCheck(): void
    {
        $missing = [];
        $declared = 0;
        foreach ($this->scoped() as $r) {
            $key = $r['method'] . ' ' . $r['path'];
            $has = array_filter($r['middleware'], static fn (object $m): bool => $m instanceof RequireProjectAccess) !== [];
            if ($has) {
                $declared++;
                $this->assertFalse(isset(self::EXCEPTIONS[$key]), "{$key}: 판정을 선언했으면 예외 목록에서 뺀다");
            } elseif (!isset(self::EXCEPTIONS[$key])) {
                $missing[] = $key;
            }
        }
        $this->assertSame([], $missing, '접근 판정(RequireProjectAccess)도 예외 사유도 없는 프로젝트 경로');
        $this->assertTrue($declared >= 10, "판정을 선언한 경로 수 {$declared}");
    }

    public function testExceptionListHasNoStaleEntries(): void
    {
        $registered = array_map(static fn (array $r): string => $r['method'] . ' ' . $r['path'], $this->scoped());
        foreach (array_keys(self::EXCEPTIONS) as $key) {
            $this->assertTrue(in_array($key, $registered, true), "예외 목록의 {$key}가 등록된 라우트에 없음(지웠으면 목록에서도 뺀다)");
        }
    }

    public function testAccessCheckRunsAfterLoginAndBeforeAdminCheck(): void
    {
        foreach ($this->scoped() as $r) {
            $classes = array_map(static fn (object $m): string => $m::class, $r['middleware']);
            $pa = array_search(RequireProjectAccess::class, $classes, true);
            if ($pa === false) {
                continue;
            }
            $key = $r['method'] . ' ' . $r['path'];
            $login = array_search(RequireLogin::class, $classes, true);
            $this->assertTrue($login !== false && $login < $pa, "{$key}: 로그인 확인 다음에 접근 판정");
            $admin = array_search(RequireAdmin::class, $classes, true);
            $this->assertTrue($admin === false || $admin > $pa, "{$key}: 접근 판정이 관리자 확인보다 먼저(보이지 않으면 404)");
            /** @var RequireProjectAccess $m */
            $m = $r['middleware'][$pa];
            $this->assertContains('{' . $m->param . '}', $r['path'], "{$key}: 판정이 읽는 경로 파라미터 {{$m->param}}가 경로에 있음");
        }
    }
}
