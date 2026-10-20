<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\TestCase;

/**
 * 등록된 모든 API 라우트가 docs/openapi.yaml에 있는지, 문서에만 있는 경로가 없는지 검사한다.
 * 이후 단계가 문서 갱신을 빠뜨리지 못하게 하는 장치다.
 */
final class OpenApiTest extends TestCase
{
    /**
     * openapi.yaml의 paths에서 (메서드, 경로) 목록을 읽는다. YAML 라이브러리 없이 들여쓰기로 판단한다:
     *   paths: 아래 2칸 들여쓴 "/경로:" 와 그 아래 4칸 들여쓴 "메서드:".
     *
     * @return list<string> "GET /api/v1/..."
     */
    private function documented(): array
    {
        $lines = file(PJG_ROOT . '/docs/openapi.yaml', FILE_IGNORE_NEW_LINES);
        $in = false;
        $path = null;
        $out = [];
        foreach ($lines as $line) {
            if (preg_match('/^paths:\s*$/', $line)) {
                $in = true;
                continue;
            }
            if ($in && preg_match('/^\S/', $line)) {
                break; // 다음 최상위 키
            }
            if (!$in) {
                continue;
            }
            if (preg_match('#^  (/[^:\s]*):\s*$#', $line, $m)) {
                $path = $m[1];
            } elseif ($path !== null && preg_match('/^    (get|post|put|patch|delete):\s*$/', $line, $m)) {
                $out[] = strtoupper($m[1]) . ' ' . $path;
            }
        }
        sort($out);
        return $out;
    }

    public function testEveryApiRouteIsDocumentedAndViceVersa(): void
    {
        $app = TestApp::make($this->tempDir('oa'));
        $registered = [];
        foreach ($app->router->routes() as $r) {
            // P30(의도된 확대): 인증 없는 헬스체크 /health도 문서와 대조한다
            if (str_starts_with($r['path'], '/api/') || $r['path'] === '/health') {
                $registered[] = $r['method'] . ' ' . $r['path'];
            }
        }
        sort($registered);
        $documented = $this->documented();
        $this->assertTrue($documented !== [], 'openapi.yaml에서 경로를 읽지 못함');
        $this->assertSame([], array_values(array_diff($registered, $documented)), '라우트는 있는데 openapi.yaml에 없음');
        $this->assertSame([], array_values(array_diff($documented, $registered)), 'openapi.yaml에는 있는데 라우트가 없음');
    }

    public function testVersionMatchesApp(): void
    {
        $yaml = (string) file_get_contents(PJG_ROOT . '/docs/openapi.yaml');
        $this->assertContains('version: ' . \PJGuardian\App::VERSION, $yaml, 'openapi.yaml의 info.version을 App::VERSION과 맞춘다');
    }
}
