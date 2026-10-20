<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Support\EnvironmentCheck;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 점검 탭 보안 항목(P31 A-4 — 알리되 막지 않음): config/ 쓰기(정보), config.ini·secrets.ini 쓰기 가능(경고)·읽기 전용(정상),
 * secrets.ini 위치(웹 루트 안이면 문제)·권한(Linux o+r 경고, Windows 확인 불가), public/ 노출(문제), display_errors·expose_php, 세션 저장 위치(DB).
 * 관리자 점검 API(/api/v1/admin/checks)에 항목이 나오고 경로 원문은 값에 없다(이름만).
 */
final class SecurityChecksTest extends TestCase
{
    /** @return array<string, array<string, mixed>> id(+이름) → 항목 */
    private static function byId(array $items): array
    {
        $out = [];
        foreach ($items as $it) {
            $out[$it['id'] . (isset($it['params']['name']) ? ':' . $it['params']['name'] : '')] = $it;
        }
        return $out;
    }

    public function testItemsFollowFileState(): void
    {
        $root = $this->tempDir('secchk');
        $config = $root . '/config';
        $public = $root . '/public';
        mkdir($config);
        mkdir($public);
        file_put_contents($config . '/config.ini', "[app]\n");
        file_put_contents($config . '/secrets.ini', "encryption_key = x\n");
        $items = self::byId(EnvironmentCheck::security($config, $public));
        $this->assertSame('info', $items['config_dir']['status'], 'config/ 쓰기 가능 = 허용된 방침(정보)');
        $this->assertSame('config_dir_writable_policy', $items['config_dir']['note']);
        $this->assertSame('warn', $items['config_file:config.ini']['status'], '쓰기 가능한 config.ini = 경고');
        $this->assertSame('warn', $items['config_file:secrets.ini']['status']);
        $this->assertSame('ok', $items['secrets_location']['status']);
        $this->assertSame(PHP_OS_FAMILY === 'Windows' ? 'unknown' : 'warn', $items['secrets_perm']['status'], '권한(Windows는 확인 불가, 임시 파일은 보통 644)');
        $this->assertSame('ok', $items['public_exposure']['status']);
        $this->assertSame('info', $items['session_store']['status']);
        foreach (['display_errors', 'expose_php'] as $k) {
            $this->assertTrue(in_array($items[$k]['status'], ['ok', 'warn'], true));
        }

        // 완화책 적용: 파일 단위 읽기 전용
        chmod($config . '/config.ini', 0444);
        chmod($config . '/secrets.ini', 0440);
        clearstatcache();
        $ro = self::byId(EnvironmentCheck::security($config, $public));
        $this->assertSame(['ok', 'ok'], [$ro['config_file:config.ini']['status'], $ro['config_file:secrets.ini']['status']], '읽기 전용이면 정상');
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame('ok', $ro['secrets_perm']['status']);
        }
        chmod($config . '/config.ini', 0644);
        chmod($config . '/secrets.ini', 0644);

        // public/ 노출: 금지 이름·백업 확장자
        mkdir($public . '/.git');
        file_put_contents($public . '/config.ini', 'x');
        file_put_contents($public . '/pjguardian-20261001-000000-manual.pjgbak', 'x');
        $exp = self::byId(EnvironmentCheck::security($config, $public));
        $this->assertSame('fail', $exp['public_exposure']['status']);
        foreach (['.git', 'config.ini', 'pjguardian-20261001-000000-manual.pjgbak'] as $n) {
            $this->assertTrue(str_contains($exp['public_exposure']['value'], $n), $n);
        }
        // secrets.ini가 웹 루트 안(설정 폴더를 public 아래로 잘못 둔 경우)
        $bad = $public . '/conf';
        mkdir($bad);
        file_put_contents($bad . '/secrets.ini', 'x');
        $this->assertSame('fail', self::byId(EnvironmentCheck::security($bad, $public))['secrets_location']['status']);
        // secrets.ini 없음 = 정보(환경 변수 사용)
        unlink($config . '/secrets.ini');
        $this->assertSame('info', self::byId(EnvironmentCheck::security($config, $public))['config_file:secrets.ini']['status']);
    }

    public function testAdminChecksApiIncludesSecurityItemsWithoutPaths(): void
    {
        $dir = $this->tempDir('secchk2');
        $app = TestApp::make($dir, ['paths' => ['work_dir' => $dir . '/work']]);
        TestDb::user($app->db(), 'admin', 'admin');
        $c = new TestClient($app);
        $c->login('admin', 'Passw0rd!x');
        $r = $c->get('/api/v1/admin/checks');
        $this->assertSame(200, $r->status, $r->body);
        $env = $r->decoded()['data']['environment']['items'] ?? [];
        $sec = array_values(array_filter($env, static fn (array $i): bool => $i['section'] === 'security'));
        $ids = array_unique(array_column($sec, 'id'));
        foreach (['config_dir', 'config_file', 'public_exposure', 'display_errors', 'expose_php', 'session_store'] as $id) {
            $this->assertTrue(in_array($id, $ids, true), $id);
        }
        foreach ($sec as $i) {
            $this->assertFalse(str_contains((string) $i['value'], PJG_ROOT) || str_contains((string) $i['value'], str_replace('/', '\\', PJG_ROOT)), '값에 절대 경로 없음');
        }
        $this->assertSame('ok', array_values(array_filter($sec, static fn (array $i): bool => $i['id'] === 'public_exposure'))[0]['status'], '저장소 public/은 깨끗함');
    }
}
