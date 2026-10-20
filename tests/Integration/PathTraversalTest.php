<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Http\Request;
use PJGuardian\Services\Export\ExportService;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 경로 순회·파일 이름(P31 A-2): 사용자 입력이 파일 경로가 되는 지점을 HTTP로 찌른다(admin 세션 — 권한이 아니라 경로 검사가 막아야 함).
 *
 * 경로가 되는 입력과 막는 곳(코드에서 열거):
 *  - 정적 파일 /assets/v/<해시>/… → StaticAssets(영문·숫자·._-/만, 빈 조각·`..` 금지, realpath가 public/assets 안, 허용 확장자)
 *  - 가이드 그림 /guide/img/{audience}/{slug} → 라우트 제약(user|admin, [a-z0-9-]+) + GuideContent 목록에 있는 그림만
 *  - 백업 묶음 {name} → 라우트 제약 + BundleFormat::validName(정확한 이름 형식) + 백업 폴더 안 파일만, 내려받기 {token}은 서버가 만든 토큰
 *  - 업로드 {id}·도구 패키지 {id}·백업 올리기 {id} → 32자리 16진수만(서버가 만든 id), 청크 {index}는 정수
 *  - 분석 도구 {tool}/{version} → 레지스트리에 있는 도구·버전만, 규칙 {tool}/{rule} → DB 조회만(파일 경로 아님)
 *  - 설정 키 {key} → 허용 목록(ConfigSchema::EDITABLE), 경로 값(backup.dir)은 PathPolicy(PathPolicyTest)
 *  - zip 해제 → SafeUnzip(SafeUnzipTest), 코드 뷰어 → DB에 저장된 정규화 경로 + 스냅샷 폴더(사용자 입력 아님)
 *  - 내려받기 파일 이름 → ExportService::namePart·disposition(글자·숫자·_-만, RFC 5987)
 */
final class PathTraversalTest extends TestCase
{
    public function testTraversalAttemptsAreRejectedAndNothingOutsideIsTouched(): void
    {
        $dir = $this->tempDir('ptr');
        $app = TestApp::make($dir, ['paths' => ['work_dir' => $dir . '/work']]);
        TestDb::user($app->db(), 'admin', 'admin');
        $sentinel = $dir . '/secret.txt';
        file_put_contents($sentinel, 'TOP-SECRET-SENTINEL');
        $c = new TestClient($app);
        $c->login('admin', 'Passw0rd!x');
        $hash = $app->assetVersion()->hash();

        $static = [
            "/assets/v/{$hash}/../../../config/config.ini.example",
            "/assets/v/{$hash}/css/../../../../composer.json",
            "/assets/v/{$hash}/css/..\\..\\..\\composer.json",
            "/assets/v/{$hash}//etc/passwd",
            "/assets/v/{$hash}/css/tokens.css\0.php",
            "/assets/v/{$hash}/../../src/App.php",
            "/assets/v/{$hash}/" . str_replace('\\', '/', $sentinel),
        ];
        foreach ($static as $p) {
            $r = $app->handle(new Request('GET', $p, [], ['user-agent' => 't'], '', true, '127.0.0.1'));
            $this->assertTrue(in_array($r->status, [400, 404], true), "{$p} → {$r->status}");
            $this->assertFalse(str_contains($r->body, 'TOP-SECRET') || str_contains($r->body, '[app]') || str_contains($r->body, '"require"'), "{$p} 내용 노출");
        }

        $attempts = [
            ['GET', '/guide/img/admin/../../config'],
            ['GET', '/guide/img/../admin/x'],
            ['GET', '/guide/img/admin/' . rawurlencode('..')],
            ['POST', '/api/v1/admin/backups/pjguardian-../../secret.pjgbak/verify'],
            ['POST', '/api/v1/admin/backups/pjguardian-..pjgbak/download'],
            ['POST', '/api/v1/admin/backups/pjguardian-x.pjgbak/precheck'],
            ['DELETE', '/api/v1/admin/backups/pjguardian-..\\..\\secret.pjgbak'],
            ['GET', '/api/v1/admin/backups/download/../../secret.txt'],
            ['GET', '/api/v1/admin/backups/download/' . str_repeat('a', 64)],
            ['GET', '/api/v1/uploads/../../secret'],
            ['GET', '/api/v1/uploads/' . str_repeat('A', 32)],
            ['GET', '/api/v1/admin/tool-staging/..%2f..%2fsecret'],
            ['GET', '/api/v1/admin/tool-staging/../../secret'],
            ['DELETE', '/api/v1/admin/backup-uploads/../../secret'],
            ['POST', '/api/v1/admin/tools/../../secret/versions/1.0.0/pin'],
            ['POST', '/api/v1/admin/tools/ruff/versions/..%2F..%2F/pin'],
            ['POST', '/api/v1/admin/tools/ruff/versions/../../../secret/verify'],
            ['GET', '/api/v1/rules/ruff/../../secret'],
            ['DELETE', '/api/v1/admin/config-settings/../../secret'],
            ['DELETE', '/api/v1/admin/config-settings/paths.data_dir'],
        ];
        foreach ($attempts as [$m, $p]) {
            $r = $c->request($m, $p, [], $m === 'GET' ? null : []);
            $this->assertTrue($r->status >= 400 && $r->status < 500, "{$m} {$p} → {$r->status} {$r->body}");
            $this->assertFalse(str_contains($r->body, 'TOP-SECRET'), "{$m} {$p} 내용 노출");
        }
        foreach (['PUT /api/v1/uploads/' . str_repeat('a', 32) . '/chunks/..%2f1', 'PUT /api/v1/admin/tool-staging/' . str_repeat('b', 32) . '/chunks/-1'] as $k) {
            [$m, $p] = explode(' ', $k, 2);
            $r = $c->raw($m, $p, 'x');
            $this->assertTrue($r->status >= 400 && $r->status < 500, "{$k} → {$r->status}");
        }
        $this->assertSame('TOP-SECRET-SENTINEL', (string) file_get_contents($sentinel), '밖의 파일은 그대로');
    }

    public function testDownloadFileNamesCannotCarryPathsOrHeaderBreaks(): void
    {
        foreach (["../../etc/passwd", "a\r\nSet-Cookie: x=1", "C:\\Windows\\win.ini", "프로젝트/../x", "\"quoted\";x"] as $name) {
            $part = ExportService::namePart($name, 'project');
            $this->assertSame(0, preg_match('#[/\\\\\r\n".:;]#', $part), "조각 {$part}");
            $disp = ExportService::disposition("defects_{$part}_20261014.csv");
            $this->assertSame(0, preg_match('/[\r\n]/', $disp), '머리글 줄바꿈 없음');
            $this->assertTrue(str_starts_with($disp, 'attachment; filename="'), $disp);
            $ascii = (string) preg_replace('/^attachment; filename="([^"]*)".*$/', '$1', $disp);
            $this->assertSame(0, preg_match('#[/\\\\]#', $ascii), "ASCII 이름 {$ascii}");
        }
    }
}
