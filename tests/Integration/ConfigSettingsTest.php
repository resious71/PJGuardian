<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Actor;
use PJGuardian\Services\Settings\SettingsStore;
use PJGuardian\Support\Config;
use PJGuardian\Support\FixedClock;
use PJGuardian\Support\Logger;
use PJGuardian\Support\Paths;
use PJGuardian\Support\SettingsRegistry;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 설정 관리(P27A): 접근 통제, settings.local.ini 저장(config.ini 불변·원자적·백업 순환·충돌), 우선순위·출처, 검증(422·400),
 * 손상 파일, 민감 값 비노출, 기본값으로, 변경 이력, 워커 다시 읽기. 설정은 파일에서 읽는 실제 경로(Config::load)로 시험한다.
 */
final class ConfigSettingsTest extends TestCase
{
    private string $dir;
    private string $ini;
    private \PJGuardian\Support\Db $db;

    public function setUp(): void
    {
        $this->dir = $this->tempDir('cfgset');
        $this->ini = TestApp::writeIni($this->dir . '/config.ini', [
            'paths' => ['work_dir' => $this->dir . '/work', 'logs_dir' => $this->dir . '/logs', 'uploads_dir' => $this->dir . '/uploads',
                'snapshots_dir' => $this->dir . '/snap', 'backups_dir' => $this->dir . '/backups'],
            'auth' => ['bcrypt_cost' => '4'],
            'disk' => ['warn_percent' => '70', 'block_percent' => '90'],
            // 민감·제외 키에 표지 값을 넣어 두고 어디에도 나오지 않는지 본다
            'app' => ['base_url' => 'https://marker-baseurl.example'],
            'ldap' => ['bind_dn' => 'cn=marker-binddn'],
        ]);
        // config.ini에 없는 키(출처 = 기본값)를 만들려고 두 줄을 뺀다
        file_put_contents($this->ini, (string) preg_replace('/^(project_defect_warn|host_test_max) = .*\R/m', '', (string) file_get_contents($this->ini)));
        $this->db = TestDb::migrated();
        TestDb::user($this->db, 'admin', 'admin');
        TestDb::user($this->db, 'admin2', 'admin');
        TestDb::user($this->db, 'kim', 'user');
    }

    /** 요청마다 새 App(웹과 같게 설정 파일을 다시 읽는다) */
    private function app(array $env = [], ?SettingsRegistry $registry = null): App
    {
        $config = Config::load($this->ini, $this->dir . '/secrets.ini', $env, $registry);
        $clock = new FixedClock('2026-10-09T01:00:00Z');
        $paths = new Paths(PJG_ROOT, $config);
        return new App(PJG_ROOT, $config, $clock, new Logger($paths->logs(), $clock, 'INFO'), $paths, $this->db);
    }

    private function as(string $login, ?App $app = null): TestClient
    {
        $c = new TestClient($app ?? $this->app());
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status, $login);
        return $c;
    }

    /** @return array<string, array<string, mixed>> 키 → 항목 */
    private function items(array $data): array
    {
        return array_column($data['items'], null, 'key');
    }

    public function testAccessControlAndCsrf(): void
    {
        $anon = new TestClient($this->app());
        $this->assertSame(401, $anon->get('/api/v1/admin/config-settings')->status);
        $kim = $this->as('kim');
        $this->assertSame(403, $kim->get('/api/v1/admin/config-settings')->status);
        $this->assertSame(403, $kim->request('PUT', '/api/v1/admin/config-settings', [], ['changes' => ['disk.warn_percent' => '75'], 'version' => ''])->status);
        $this->assertSame(403, $kim->request('POST', '/api/v1/admin/config-settings/validate', [], ['key' => 'x.y', 'value' => 'C:/x'])->status);
        $this->assertSame(403, $kim->request('DELETE', '/api/v1/admin/config-settings/disk.warn_percent', ['version' => ''])->status);
        $this->assertSame(403, $kim->get('/admin/config-settings')->status, '화면도 서버에서 차단');
        $this->assertNotContains('/admin/config-settings', $kim->get('/projects')->body, '일반 사용자 메뉴에 없음');
        $admin = $this->as('admin');
        $this->assertSame(200, $admin->get('/admin/config-settings')->status);
        // X12(의도된 단언 변경): 메뉴 "시스템 관리"는 첫 탭 분석 도구(/admin/tools)를 열고, 설정 관리는 그 탭 줄에서
        $this->assertContains('/admin/tools" data-nav="admin.tools"', $admin->get('/projects')->body, 'admin 메뉴(시스템 관리 → 첫 탭 분석 도구)');
        $r = $admin->request('PUT', '/api/v1/admin/config-settings', [], ['changes' => ['disk.warn_percent' => '75'], 'version' => ''], null, [], false);
        $this->assertSame(403, $r->status, 'CSRF 없음');
        $this->assertFalse(is_file($this->dir . '/settings.local.ini'));
    }

    public function testSaveWritesOnlyLocalFileAuditsAndAppliesNextRequest(): void
    {
        $before = (string) file_get_contents($this->ini);
        $admin = $this->as('admin');
        $data = $admin->get('/api/v1/admin/config-settings')->decoded()['data'];
        $this->assertSame('', $data['version']);
        $items = $this->items($data);
        $this->assertSame(103, count($items), '허용 목록 103개(X21 분석 탭 업로드·해제 한도 3 추가 — 의도된 단언 변경; 이전 100개: P32B 신뢰 프록시 1·유지보수 3 추가 — 의도된 단언 변경; 이전 96개: P30B 관리자 이메일 13 추가 — 의도된 단언 변경; P27A 22 + P27B 백업 9 + P27E 자동 백업 5 + P28B 계정 승인 2·LDAP/AD 14 + P28C SSO(OIDC) 14 + P28D SSO(SAML) 14 + P30 감사 로그 보존·헬스체크 허용 IP·큐 정체 3, 의도된 단언 변경)');
        $this->assertSame(['70', 'config'], [$items['disk.warn_percent']['value'], $items['disk.warn_percent']['source']]);
        $this->assertSame(['4h', 'config'], [$items['analysis.timeout']['value'], $items['analysis.timeout']['source']]);
        $this->assertSame(['200000', 'default'], [$items['limits.project_defect_warn']['value'], $items['limits.project_defect_warn']['source']]);

        $r = $admin->put('/api/v1/admin/config-settings', ['changes' => ['disk.warn_percent' => '75', 'analysis.timeout' => '120m', 'limits.project_defect_warn' => '5000'], 'version' => '']);
        $this->assertSame(200, $r->status, $r->body);
        $after = $this->items($r->decoded()['data']);
        $this->assertSame(['75', 'screen'], [$after['disk.warn_percent']['value'], $after['disk.warn_percent']['source']]);
        $this->assertSame(['2h', 'screen'], [$after['analysis.timeout']['value'], $after['analysis.timeout']['source']], '설정 파일 표기로 정규화');
        $this->assertSame(['70', 'config'], [$after['disk.warn_percent']['base']['value'], $after['disk.warn_percent']['base']['source']]);
        $this->assertSame($before, (string) file_get_contents($this->ini), 'config.ini 바이트 그대로');
        $local = (string) file_get_contents($this->dir . '/settings.local.ini');
        $this->assertContains("[disk]\nwarn_percent = \"75\"", $local);
        $this->assertContains("[analysis]\ntimeout = \"2h\"", $local);

        // 다음 요청(새 App)에 바로 반영 — 웹은 요청마다 설정 파일을 읽는다
        $next = $this->app();
        $this->assertSame([75, 7200, 5000], [$next->config->int('disk.warn_percent'), $next->config->int('analysis.timeout'), $next->config->int('limits.project_defect_warn')]);
        $this->assertSame(hash('sha256', $local), $next->config->localVersion());

        // 감사: 키마다 한 줄(config.update, 대상 config/키)
        $rows = $this->db->all("SELECT action, target_type, target_id, details, actor_label FROM audit_log WHERE action = 'config.update' ORDER BY id");
        $this->assertSame(['disk.warn_percent', 'analysis.timeout', 'limits.project_defect_warn'], array_column($rows, 'target_id'), '보낸 순서대로 키마다 한 줄');
        $byKey = array_column($rows, null, 'target_id');
        $this->assertSame(['config', 'admin'], [$byKey['disk.warn_percent']['target_type'], $byKey['disk.warn_percent']['actor_label']]);
        $this->assertSame(['old' => '70', 'new' => '75', 'old_source' => 'config', 'file' => 'settings.local.ini'], json_decode($byKey['disk.warn_percent']['details'], true));
        $this->assertSame(['old' => '4h', 'new' => '2h', 'old_source' => 'config', 'file' => 'settings.local.ini'], json_decode($byKey['analysis.timeout']['details'], true));
        // 변경 이력(최근부터) = 감사 로그
        $history = $r->decoded()['data']['history'];
        $this->assertSame(['limits.project_defect_warn', 'analysis.timeout', 'disk.warn_percent'], array_column($history, 'key'));
        $this->assertSame(['admin', '70', '75'], [$history[2]['user'], $history[2]['old'], $history[2]['new']]);
    }

    public function testValidationErrorsAndConflict(): void
    {
        $admin = $this->as('admin');
        $put = fn (array $changes, string $version = '') => $admin->put('/api/v1/admin/config-settings', ['changes' => $changes, 'version' => $version]);
        $code = static fn ($r) => [$r->status, $r->decoded()['error']['code'] ?? null];
        // 허용 목록 밖(민감·제외 키 포함)과 모르는 키는 400
        foreach (['app.base_url' => 'https://x.example', 'database.busy_timeout' => '9s', 'paths.backups_dir' => 'D:/x', 'upload.chunk_size' => '4MB', 'no.such' => '1'] as $k => $v) {
            $r = $put([$k => $v]);
            $this->assertSame([400, 'setting_not_editable'], $code($r), $k);
        }
        // 형식·범위 422(details key·범위)
        foreach (['disk.warn_percent' => '101', 'analysis.timeout' => 'abc', 'analysis.max_concurrent' => '0', 'scheduler.tick_seconds' => '5s'] as $k => $v) {
            $r = $put([$k => $v]);
            $this->assertSame([422, 'setting_invalid_value'], $code($r), $k);
            $this->assertSame($k, $r->decoded()['error']['details']['key']);
        }
        // 키 간 규칙: 경고 ≥ 차단, Run 상세 보관 1~2
        $r = $put(['disk.warn_percent' => '95']);
        $this->assertSame([422, 'setting_cross_check'], $code($r));
        $this->assertTrue(in_array('disk.block_percent', $r->decoded()['error']['details']['keys'], true));
        $this->assertSame([422, 'setting_cross_check'], $code($put(['runs.detail_keep' => '2'])));
        // 전체 실패: 하나라도 틀리면 아무것도 쓰지 않는다
        $this->assertSame([422, 'setting_invalid_value'], $code($put(['disk.warn_percent' => '75', 'analysis.timeout' => 'x'])));
        $this->assertFalse(is_file($this->dir . '/settings.local.ini'));
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'config.update'"));
        // 두 값을 함께 바꾸면 규칙을 지킬 수 있다(경고 95·차단 98)
        $this->assertSame(200, $put(['disk.warn_percent' => '95', 'disk.block_percent' => '98'])->status);
        // 버전 표지 불일치 409(다른 관리자가 먼저 바꿈)
        $other = $this->as('admin2');
        $v = $other->get('/api/v1/admin/config-settings')->decoded()['data']['version'];
        $this->assertSame(200, $other->put('/api/v1/admin/config-settings', ['changes' => ['scheduler.max_per_tick' => '10'], 'version' => $v])->status);
        $r = $put(['scheduler.max_per_tick' => '20'], $v);
        $this->assertSame([409, 'settings_conflict'], $code($r));
        $this->assertSame(10, $this->app()->config->int('scheduler.max_per_tick'), '나중 저장은 반영되지 않음');
        // 본문 형식
        $this->assertSame([400, 'invalid_config_settings'], $code($admin->put('/api/v1/admin/config-settings', ['changes' => [], 'version' => ''])));
        $this->assertSame([400, 'invalid_config_settings'], $code($admin->put('/api/v1/admin/config-settings', ['changes' => ['disk.warn_percent' => '75']])));
    }

    /** X21: 분석 탭의 업로드·해제 한도 3개 — 저장·출처·업로드 설정과 해제 한도에 반영, 범위·키 간 규칙, 나머지 extract.*는 그대로 편집 금지 */
    public function testUploadAndExtractLimitsEditable(): void
    {
        $admin = $this->as('admin');
        $put = fn (array $changes) => $admin->put('/api/v1/admin/config-settings', ['changes' => $changes, 'version' => '']);
        $code = static fn ($r) => [$r->status, $r->decoded()['error']['code'] ?? null];
        $tab = array_values(array_filter($admin->get('/api/v1/admin/config-settings')->decoded()['data']['items'], static fn ($it) => $it['group'] === 'analysis'));
        $this->assertSame(['upload.max_zip_size', 'extract.max_extracted_size', 'extract.max_files'], array_slice(array_column($tab, 'key'), 4), '분석 탭 끝에 3개');
        foreach (['extract.worker_memory_limit' => '1GB', 'extract.default_excludes' => 'node_modules', 'upload.chunk_size' => '4MB'] as $k => $v) {
            $this->assertSame([400, 'setting_not_editable'], $code($put([$k => $v])), $k);
        }
        foreach (['upload.max_zip_size' => '32GB', 'extract.max_extracted_size' => 'abc', 'extract.max_files' => '0'] as $k => $v) {
            $r = $put([$k => $v]);
            $this->assertSame([422, 'setting_invalid_value'], $code($r), $k);
            $this->assertSame($k, $r->decoded()['error']['details']['key']);
        }
        // 청크 크기보다 작은 zip 한도는 키 간 규칙 위반
        $this->assertSame(8 * 1024 * 1024, $this->app()->config->int('upload.chunk_size'));
        $this->assertSame([422, 'setting_cross_check'], $code($put(['upload.max_zip_size' => '4MB'])));
        $this->assertFalse(is_file($this->dir . '/settings.local.ini'));

        $this->assertSame(200, $put(['upload.max_zip_size' => '1GB', 'extract.max_extracted_size' => '20GB', 'extract.max_files' => '500000'])->status);
        $c = $this->app()->config;
        $this->assertSame(['screen', 'screen', 'screen'], [$c->source('upload.max_zip_size'), $c->source('extract.max_extracted_size'), $c->source('extract.max_files')]);
        $u = \PJGuardian\Services\Upload\UploadSettings::fromConfig($c);
        $this->assertSame([1024 ** 3, 20 * 1024 ** 3], [$u->maxBytes, $u->extractMaxBytes], '업로드 init·디스크 예약에 반영');
        $l = \PJGuardian\Services\Archive\UnzipLimits::fromConfig($c);
        $this->assertSame([1024 ** 3, 20 * 1024 ** 3, 500000], [$l->maxZipBytes, $l->maxTotalBytes, $l->maxFiles], '해제 한도에 반영');
        $this->assertSame(3, (int) $this->db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'config.update'"), '키마다 감사 한 줄');
    }

    public function testPriorityEnvOverScreenOverConfigOverDefault(): void
    {
        file_put_contents($this->dir . '/settings.local.ini', "[disk]\nwarn_percent = \"75\"\n[scheduler]\nmax_per_tick = \"30\"\n");
        $env = ['PJG_DISK_WARN_PERCENT' => '60', 'PJG_ANALYSIS_TIMEOUT' => '90m'];
        $app = $this->app($env);
        $c = $app->config;
        $this->assertSame([60, 'env'], [$c->int('disk.warn_percent'), $c->source('disk.warn_percent')], '환경 변수 > 화면 설정');
        $this->assertSame([30, 'screen'], [$c->int('scheduler.max_per_tick'), $c->source('scheduler.max_per_tick')], '화면 설정 > 기본값');
        $this->assertSame([5400, 'env'], [$c->int('analysis.timeout'), $c->source('analysis.timeout')], '환경 변수 > config.ini');
        $this->assertSame([90, 'config'], [$c->int('disk.block_percent'), $c->source('disk.block_percent')]);
        $this->assertSame([20, 'default'], [$c->int('repository.host_test_max'), $c->source('repository.host_test_max')], 'config.ini에 없는 키 = 기본값');
        // 환경 변수가 정한 키는 화면에서 바꿀 수도 되돌릴 수도 없다
        $admin = $this->as('admin', $app);
        $data = $admin->get('/api/v1/admin/config-settings')->decoded()['data'];
        $it = $this->items($data)['disk.warn_percent'];
        $this->assertSame([true, 'PJG_DISK_WARN_PERCENT', 'env'], [$it['env_locked'], $it['env_name'], $it['source']]);
        $r = $admin->put('/api/v1/admin/config-settings', ['changes' => ['disk.warn_percent' => '65'], 'version' => $data['version']]);
        $this->assertSame([422, 'setting_env_locked'], [$r->status, $r->decoded()['error']['code']]);
        $r = $admin->request('DELETE', '/api/v1/admin/config-settings/disk.warn_percent', ['version' => $data['version']]);
        $this->assertSame([422, 'setting_env_locked'], [$r->status, $r->decoded()['error']['code']]);
        // 환경 변수는 편집 가능 키만(제외 키의 PJG_ 이름은 무시)
        $c2 = $this->app(['PJG_APP_BASE_URL' => 'https://env.example'])->config;
        $this->assertSame('https://marker-baseurl.example', $c2->string('app.base_url'));
        // 틀린 환경 변수 값은 그 키만 무시하고 경고
        $c3 = $this->app(['PJG_DISK_BLOCK_PERCENT' => 'abc'])->config;
        $this->assertSame([90, 'config'], [$c3->int('disk.block_percent'), $c3->source('disk.block_percent')]);
        $this->assertTrue(in_array(['key' => 'disk.block_percent', 'code' => 'env_invalid'], $c3->warnings(), true));
    }

    public function testResetToDefault(): void
    {
        $admin = $this->as('admin');
        $r = $admin->put('/api/v1/admin/config-settings', ['changes' => ['disk.warn_percent' => '80', 'limits.project_defect_max' => '0'], 'version' => '']);
        $v = $r->decoded()['data']['version'];
        $r = $admin->request('DELETE', '/api/v1/admin/config-settings/disk.warn_percent', ['version' => $v]);
        $this->assertSame(200, $r->status, $r->body);
        $it = $this->items($r->decoded()['data']);
        $this->assertSame(['70', 'config'], [$it['disk.warn_percent']['value'], $it['disk.warn_percent']['source']], 'config.ini 값으로');
        $this->assertSame(['0', 'screen'], [$it['limits.project_defect_max']['value'], $it['limits.project_defect_max']['source']], '다른 키는 그대로');
        $this->assertNotContains('warn_percent', (string) file_get_contents($this->dir . '/settings.local.ini'));
        $last = $this->db->one("SELECT target_id, details FROM audit_log WHERE action = 'config.update' ORDER BY id DESC LIMIT 1");
        $this->assertSame(['disk.warn_percent', '80', '70', 'screen'], [$last['target_id'], json_decode($last['details'], true)['old'], json_decode($last['details'], true)['new'], json_decode($last['details'], true)['old_source']]);
        // 화면 설정이 없는 키 → 바꿀 것 없음(200), 버전이 낡았으면 409
        $v2 = $r->decoded()['data']['version'];
        $this->assertSame(200, $admin->request('DELETE', '/api/v1/admin/config-settings/disk.block_percent', ['version' => $v2])->status);
        $this->assertSame(409, $admin->request('DELETE', '/api/v1/admin/config-settings/disk.block_percent', ['version' => 'old'])->status);
        $this->assertSame(400, $admin->request('DELETE', '/api/v1/admin/config-settings/app.env', ['version' => $v2])->status);
        // 되돌리면 다른 화면 설정과 규칙이 어긋나는 경우 422(경고 92·차단 95 → 차단만 되돌리면 90 ≤ 92)
        $data = $admin->put('/api/v1/admin/config-settings', ['changes' => ['disk.warn_percent' => '92', 'disk.block_percent' => '95'], 'version' => $v2])->decoded()['data'];
        $r = $admin->request('DELETE', '/api/v1/admin/config-settings/disk.block_percent', ['version' => $data['version']]);
        $this->assertSame([422, 'setting_cross_check'], [$r->status, $r->decoded()['error']['code']]);
    }

    /**
     * P32 인수 시험에서 찾은 결함: 화면에서 한 번에 저장한 값들이 settings.local.ini에 알파벳 순서로 적히고, 읽을 때 하나씩 키 간 규칙을 보면
     * 앞 키(alert_mail.enabled, disk.block_percent)가 아직 덧씌우지 않은 config.ini 값과 어긋나 버려졌다(저장은 성공, 실제로는 꺼진 채).
     * 이제 적용 순서와 무관하게 — 함께 맞는 값은 모두 반영, 정말로 어긋나는 키만 무시.
     */
    public function testOverlayIsOrderIndependentForCrossChecks(): void
    {
        $admin = $this->as('admin');
        $v = $admin->get('/api/v1/admin/config-settings')->decoded()['data']['version'];
        $r = $admin->put('/api/v1/admin/config-settings', ['changes' => [
            'alert_mail.enabled' => 'true', 'alert_mail.smtp_host' => '127.0.0.1', 'alert_mail.smtp_port' => '2525', 'alert_mail.smtp_tls' => 'none',
            'alert_mail.smtp_auth' => 'none', 'alert_mail.from_address' => 'pjg@example.test', 'alert_mail.recipients' => 'ops@example.test',
            'disk.warn_percent' => '50', 'disk.block_percent' => '60',
        ], 'version' => $v]);
        $this->assertSame(200, $r->status, $r->body);
        $local = (string) file_get_contents($this->dir . '/settings.local.ini');
        $this->assertTrue(strpos($local, 'enabled') < strpos($local, 'smtp_host') && strpos($local, 'block_percent') < strpos($local, 'warn_percent'), '파일 순서: 규칙상 뒤 키가 먼저 적힘');
        $c = $this->app()->config; // 워커·다음 웹 요청처럼 파일에서 다시 읽음
        $this->assertSame([true, 'screen', 50, 60, 'screen'], [$c->bool('alert_mail.enabled'), $c->source('alert_mail.enabled'), $c->int('disk.warn_percent'), $c->int('disk.block_percent'), $c->source('disk.block_percent')]);
        $this->assertSame([], $c->warnings());
        $this->assertSame('on', $this->app()->alertMailer()->status()['state'], '관리자 이메일 켜짐');
        // 정말로 어긋나는 값은 여전히 그 키만 무시(차단 ≤ 경고)
        file_put_contents($this->dir . '/settings.local.ini', "[disk]\nblock_percent = \"40\"\nwarn_percent = \"50\"\n");
        $c = $this->app()->config;
        $this->assertSame([50, 90, ['disk.block_percent:cross_check']], [$c->int('disk.warn_percent'), $c->int('disk.block_percent'),
            array_map(static fn ($w) => $w['key'] . ':' . $w['code'], $c->warnings())]);
        // 환경 변수가 화면 설정보다 우선(같은 키는 후보 하나)
        $c = $this->app(['PJG_DISK_WARN_PERCENT' => '30'])->config;
        $this->assertSame([30, 'env', 40, 'screen'], [$c->int('disk.warn_percent'), $c->source('disk.warn_percent'), $c->int('disk.block_percent'), $c->source('disk.block_percent')]);
    }

    public function testCorruptFileIgnoredWithWarningsAndBackupsRotate(): void
    {
        // 키 하나가 틀림·편집 금지 키·키 간 규칙 위반 → 그 키만 무시, 나머지는 반영, 앱은 기동
        file_put_contents($this->dir . '/settings.local.ini', "[disk]\nwarn_percent = \"abc\"\nblock_percent = \"60\"\n[database]\nbusy_timeout = \"1s\"\n[scheduler]\nmax_per_tick = \"7\"\n");
        $c = $this->app()->config;
        $this->assertSame([70, 90, 7, 5], [$c->int('disk.warn_percent'), $c->int('disk.block_percent'), $c->int('scheduler.max_per_tick'), $c->int('database.busy_timeout')]);
        $codes = array_map(static fn ($w) => ($w['key'] ?? '') . ':' . $w['code'], $c->warnings());
        $this->assertSame(['disk.warn_percent:invalid_value', 'disk.block_percent:cross_check', 'database.busy_timeout:not_editable'], array_values(array_filter($codes, static fn ($s) => !str_starts_with($s, 'scheduler'))));
        // 문법이 깨진 파일 → 파일 전체 무시 + 경고, 앱 기동, 화면에 경고
        file_put_contents($this->dir . '/settings.local.ini', "warn_percent = \"75\"\n[disk\n");
        $app = $this->app();
        $this->assertSame([70, 'config'], [$app->config->int('disk.warn_percent'), $app->config->source('disk.warn_percent')]);
        $this->assertSame([['key' => null, 'code' => 'file_unreadable']], $app->config->warnings());
        $admin = $this->as('admin', $app);
        $data = $admin->get('/api/v1/admin/config-settings')->decoded()['data'];
        $this->assertSame('file_unreadable', $data['warnings'][0]['code']);
        // 저장하면 새로 쓰고 깨진 파일은 .bak.1로 남는다
        $r = $admin->put('/api/v1/admin/config-settings', ['changes' => ['disk.warn_percent' => '76'], 'version' => $data['version']]);
        $this->assertSame(200, $r->status, $r->body);
        $this->assertSame("warn_percent = \"75\"\n[disk\n", (string) file_get_contents($this->dir . '/settings.local.ini.bak.1'));
        $this->assertSame([], $r->decoded()['data']['warnings']);
        // 백업은 최근 5개만
        $store = new SettingsStore($this->dir . '/settings.local.ini');
        for ($i = 0; $i < 7; $i++) {
            $store->write(['scheduler.max_per_tick' => (string) (10 + $i)], $store->read()['version']);
        }
        $this->assertSame(5, count($store->backups()));
        $this->assertFalse(is_file($this->dir . '/settings.local.ini.bak.6'));
        $this->assertContains('max_per_tick = "15"', (string) file_get_contents($this->dir . '/settings.local.ini.bak.1'), '가장 최근 이전 내용');
    }

    public function testAtomicWriteSurvivesKilledWriterAndCleansTemp(): void
    {
        $path = $this->dir . '/settings.local.ini';
        $store = new SettingsStore($path);
        $store->write(['scheduler.max_per_tick' => '11'], '');
        // 다른 프로세스가 쓰는 도중에 강제 종료해도 파일은 온전하다(임시 파일 → fsync → 이름 바꾸기)
        $script = $this->dir . '/writer.php';
        file_put_contents($script, '<?php require ' . var_export(PJG_ROOT . '/src/bootstrap.php', true) . '; $s = new PJGuardian\Services\Settings\SettingsStore(' . var_export($path, true) . ');'
            . ' for ($i = 0; $i < 100000; $i++) { $v = $s->read()["version"]; try { $s->write(["scheduler.max_per_tick" => (string) (20 + $i % 900), "repository.test_per_minute" => (string) (1 + $i % 50)], $v); } catch (Throwable) {} }');
        $proc = proc_open([PHP_BINARY, $script], [1 => ['file', $this->dir . '/w.out', 'w'], 2 => ['file', $this->dir . '/w.err', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        usleep(700_000);
        proc_terminate($proc, 9);
        proc_close($proc);
        $read = $store->read();
        $this->assertTrue(count($store->backups()) >= 2 && $read['version'] !== '', '강제 종료 전에 여러 번 썼음(시험이 실제로 쓰기 도중을 끊었는지)');
        $this->assertTrue($read['ok'], '죽은 뒤에도 문법이 온전');
        $this->assertTrue(isset($read['values']['scheduler']['max_per_tick']));
        $c = Config::load($this->ini, null, []);
        $this->assertSame([], $c->warnings(), '읽을 때 무시한 값 없음');
        // 남은 임시 파일은 다음 쓰기가 지운다
        file_put_contents($path . '.tmp-deadbeef', 'partial');
        $store->write(['scheduler.max_per_tick' => '12'], $store->read()['version']);
        $this->assertSame([], array_values(array_filter(scandir($this->dir), static fn ($n) => str_contains($n, '.tmp-'))));
    }

    public function testSensitiveAndExcludedValuesNeverExposed(): void
    {
        file_put_contents($this->dir . '/secrets.ini', "encryption_key = \"" . base64_encode(str_repeat('k', 32)) . "\"\n");
        $admin = $this->as('admin');
        $api = $admin->get('/api/v1/admin/config-settings')->body;
        $page = $admin->get('/admin/config-settings')->body;
        $admin->put('/api/v1/admin/config-settings', ['changes' => ['disk.warn_percent' => '71'], 'version' => '']);
        $admin->put('/api/v1/admin/config-settings', ['changes' => ['app.base_url' => 'https://y.example'], 'version' => '']);
        $audit = json_encode($this->db->all('SELECT * FROM audit_log'));
        // P28B(의도된 단언 변경): ldap.bind_dn은 이제 화면에서 고치는 설정(허용 목록 auth_ldap)이라 설정 API에는 나온다 — 대신 감사 로그·변경 이력에는
        // 값을 남기지 않는다(아래). 나머지 표지는 그대로 어디에도 나오지 않아야 한다
        $markers = ['marker-baseurl', base64_encode(str_repeat('k', 32)), $this->dir . '/work', 'busy_timeout', 'db_file', 'encryption_key'];
        foreach ($markers as $m) {
            foreach (['api' => $api, 'page' => $page, 'audit' => (string) $audit, 'local' => (string) file_get_contents($this->dir . '/settings.local.ini')] as $where => $text) {
                $this->assertFalse(str_contains($text, $m), "{$m} 이(가) {$where}에 나옴");
            }
        }
        $this->assertFalse(str_contains((string) $audit, 'marker-binddn'), '바인드 DN이 감사 로그에 없음(설정 전)');
        $view = $admin->get('/api/v1/admin/config-settings')->decoded()['data'];
        $r = $admin->put('/api/v1/admin/config-settings', ['changes' => ['ldap.bind_dn' => 'CN=marker-newdn,DC=test'], 'version' => $view['version']]);
        $this->assertSame(200, $r->status, $r->body);
        $audit = (string) json_encode($this->db->all("SELECT * FROM audit_log WHERE action = 'config.update'"));
        $this->assertFalse(str_contains($audit, 'marker-newdn') || str_contains($audit, 'marker-binddn'), '바인드 DN 바꿈: 감사 로그엔 *** 만');
        $this->assertContains('"ldap.bind_dn"', $audit);
        $hist = (string) json_encode($admin->get('/api/v1/admin/config-settings')->decoded()['data']['history']);
        $this->assertFalse(str_contains($hist, 'marker-newdn') || str_contains($hist, 'marker-binddn'), '변경 이력에도 값 없음');
    }

    public function testPathKeyValidationAndCreateFlow(): void
    {
        // 경로 형식 키는 P27B가 정식 등록 — 여기서는 시험용 더미 키만 등록부에 더한다
        $registry = SettingsRegistry::default()->with([
            'test.dummy_dir' => ['spec' => ['type' => 'path', 'default' => 'unset'], 'group' => 'test', 'machine_specific' => true],
        ]);
        $outside = str_replace('\\', '/', sys_get_temp_dir()) . '/pjg_p27a_' . bin2hex(random_bytes(4));
        try {
            $admin = $this->as('admin', $this->app([], $registry));
            $check = fn (string $v, bool $create = false) => $admin->request('POST', '/api/v1/admin/config-settings/validate', [], ['key' => 'test.dummy_dir', 'value' => $v, 'create' => $create])->decoded()['data'];
            $this->assertSame('fail_forbidden_location', $check(PJG_ROOT . '/config')['code']);
            $this->assertSame('fail_forbidden_location', $check(PJG_ROOT . '/public/x/../../data')['code'], '.. 우회');
            $this->assertSame('fail_forbidden_location', $check('src')['code'], '프로젝트 기준 상대 경로');
            $r = $check($outside);
            $this->assertSame(['fail_not_found', true], [$r['code'], $r['can_create']]);
            $this->assertFalse(is_dir($outside), '확인만으로는 만들지 않음');
            // 확인 없이 저장하면(없는 폴더) 422, 만들기를 확인받은 키로 저장하면 만들고 정규화한 경로를 저장
            $put = fn (array $body) => $admin->put('/api/v1/admin/config-settings', $body + ['version' => '']);
            $r = $put(['changes' => ['test.dummy_dir' => $outside]]);
            $this->assertSame([422, 'setting_path_invalid', 'fail_not_found'], [$r->status, $r->decoded()['error']['code'], $r->decoded()['error']['details']['code']]);
            $r = $put(['changes' => ['test.dummy_dir' => $outside], 'create_paths' => ['test.dummy_dir']]);
            $this->assertSame(200, $r->status, $r->body);
            $this->assertTrue(is_dir($outside));
            $it = $this->items($r->decoded()['data'])['test.dummy_dir'];
            $expected = PHP_OS_FAMILY === 'Windows' ? str_replace('/', '\\', (string) realpath($outside)) : (string) realpath($outside);
            $this->assertSame([$expected, 'screen', true], [$it['value'], $it['source'], $it['machine_specific']]);
            $this->assertSame(400, $admin->request('POST', '/api/v1/admin/config-settings/validate', [], ['key' => 'disk.warn_percent', 'value' => 'x'])->status, '경로 형식이 아닌 키는 400');
        } finally {
            @rmdir($outside);
        }
    }

    /**
     * P27F 카테고리 탭: 화면은 응답의 groups 순서로 탭을 만들고(항목 없는 묶음은 탭 없음) 항목을 group으로 나눈다.
     * 허용 키가 모두 정확히 한 탭(묶음)에, 탭 순서 = 요구 7개, 빈 탭 없음, 새 묶음을 등록하면 탭이 하나 늘어난다. 화면에는 펼치기·접기 단추가 없다.
     */
    public function testCategoryTabsCoverEveryEditableKeyOnce(): void
    {
        // P28B(의도된 단언 변경): 계정 승인(auth)·LDAP/AD(auth_ldap) 묶음이 등록부에 더해져 탭이 2개 늘었다(P27F: 새 묶음 = 새 탭)
        // P28C(의도된 단언 변경): SSO(OIDC)(auth_oidc) 묶음 — 탭 하나 더. P28D(의도된 단언 변경): SSO(SAML)(auth_saml) — 하나 더
        // P30(의도된 단언 변경): 알림 다음에 모니터링(헬스체크 허용 IP·큐 정체)
        $this->assertSame(['retention', 'disk', 'notification', 'monitoring', 'admin_mail', 'schedule', 'repository', 'analysis', 'backup', 'auth', 'auth_ldap', 'auth_oidc', 'auth_saml', 'maintenance'], \PJGuardian\Support\ConfigSchema::EDITABLE_GROUPS, '요구 순서(P30B 관리자 이메일 — 모니터링 뒤, P32B 유지보수 — 맨 뒤, 의도된 단언 변경)');
        $admin = $this->as('admin');
        $data = $admin->get('/api/v1/admin/config-settings')->decoded()['data'];
        $this->assertSame(\PJGuardian\Support\ConfigSchema::EDITABLE_GROUPS, $data['groups'], '탭 순서 = 등록부 묶음 순서');
        $byTab = [];
        foreach ($data['items'] as $it) {
            $this->assertTrue(in_array($it['group'], $data['groups'], true), $it['key'] . ' 묶음이 탭 목록에');
            $byTab[$it['group']][] = $it['key'];
        }
        $keys = array_column($data['items'], 'key');
        $this->assertSame(count($keys), count(array_unique($keys)), '중복 0');
        $expected = array_keys(\PJGuardian\Support\ConfigSchema::EDITABLE);
        sort($expected);
        sort($keys);
        $this->assertSame($expected, $keys, '누락 0(허용 목록 그대로)');
        foreach ($data['groups'] as $g) {
            $this->assertTrue(count($byTab[$g] ?? []) > 0, "{$g} 탭이 비어 있지 않음");
        }
        $this->assertSame(['retention' => 7, 'disk' => 2, 'notification' => 2, 'monitoring' => 3, 'admin_mail' => 13, 'schedule' => 5, 'repository' => 3, 'analysis' => 7, 'backup' => 14, 'auth' => 2, 'auth_ldap' => 14, 'auth_oidc' => 14, 'auth_saml' => 14, 'maintenance' => 3],
            array_map('count', $byTab));
        foreach (\PJGuardian\Support\ConfigSchema::EDITABLE as $key => $meta) {
            $this->assertSame($meta['group'], $this->items($data)[$key]['group'], $key);
        }
        // 등록부에 새 묶음(시험용 더미)을 더하면 탭이 마지막에 하나 늘어난다
        $registry = SettingsRegistry::default()->with([
            'test.dummy_worker' => ['spec' => ['type' => 'int', 'min' => 1, 'max' => 9, 'default' => '1'], 'group' => 'test', 'restart' => 'worker'],
        ]);
        $more = $this->as('admin', $this->app([], $registry))->get('/api/v1/admin/config-settings')->decoded()['data'];
        $this->assertSame([...\PJGuardian\Support\ConfigSchema::EDITABLE_GROUPS, 'test'], $more['groups']);
        $this->assertSame('test', $this->items($more)['test.dummy_worker']['group']);
        // 화면: 탭 목록 자리와 패널 자리, 펼치기·접기 단추 없음(탭 목록은 화면 코드가 아니라 응답에서)
        $page = $admin->get('/admin/config-settings')->body;
        $this->assertContains('role="tablist"', $page);
        $this->assertContains('data-groups', $page);
        $this->assertNotContains('data-expand-all', $page);
        $this->assertNotContains('data-collapse-all', $page);
        $js = (string) file_get_contents(PJG_ROOT . '/public/assets/js/pages/admin_config_settings.js');
        foreach (\PJGuardian\Support\ConfigSchema::EDITABLE_GROUPS as $g) {
            $this->assertFalse(str_contains($js, "'{$g}'"), "화면 스크립트에 탭 '{$g}'를 따로 적지 않음");
        }
    }

    /**
     * X10: 설정 관리는 "시스템 관리"(예전 도구·워커 관리) 화면의 첫 탭 — 별도 메뉴 없음, 주소 /admin/config-settings와 권한 그대로,
     * 화면 이름 시스템 관리·시스템 모니터링(ko·en), 문구·화면 틀·가이드에 예전 이름이 남지 않음.
     */
    public function testSystemAdministrationMenuAndTab(): void
    {
        $admin = $this->as('admin');
        $page = $admin->get('/admin/config-settings');
        $this->assertSame(200, $page->status);
        $body = $page->body;
        $this->assertNotContains('data-nav="admin.settings"', $body, '설정 관리 메뉴 항목 없음');
        $this->assertSame(1, preg_match('#<a class="nav-item" href="[^"]*/admin/tools" data-nav="admin.tools"[^>]*aria-current="page"#', $body), '메뉴 시스템 관리(→ 분석 도구, X12), 설정 관리 화면에서도 현재 메뉴');
        $this->assertContains('data-system-subnav', $body, '시스템 관리 탭 줄');
        $this->assertSame(1, preg_match('#<a class="subnav__item" href="[^"]*/admin/config-settings" aria-current="page">#', $body), '설정 관리 탭이 현재 탭');
        // X12(의도된 단언 변경): 탭 순서 — 설정 관리는 규칙 설정 바로 뒤, 알림 보관·저장소 허용 호스트 탭 없음
        preg_match('#<nav class="subnav" data-system-subnav.*?</nav>#s', $body, $nav);
        preg_match_all('#href="[^"]*/admin/([a-z-]+)"#', $nav[0] ?? '', $hrefs);
        $this->assertSame(['tools', 'worker', 'checks', 'runs', 'rule-settings', 'config-settings', 'schedules', 'backups'], $hrefs[1], '탭 순서');
        foreach (['/admin/tools', '/admin/worker', '/admin/checks', '/admin/backups'] as $p) {
            $this->assertContains('/admin/config-settings">', $admin->get($p)->body, "{$p}에도 설정 관리 탭");
        }
        $this->assertSame(['시스템 관리', '시스템 모니터링', '설정 관리'], [\PJGuardian\Support\Lang::get('icon.admin.tools'), \PJGuardian\Support\Lang::get('icon.admin.system'), \PJGuardian\Support\Lang::get('config_settings.title')]);
        $en = require PJG_ROOT . '/src/Lang/en.php';
        // X12(사용자 결정): 영어 이름 단축
        $this->assertSame(['System admin', 'Monitoring', 'Settings', 'System admin'], [$en['icon.admin.tools'], $en['icon.admin.system'], $en['config_settings.title'], $en['admin_system.title']]);
        // 권한 그대로: 일반 사용자 403(내용 없음), 비로그인은 로그인으로
        $kim = $this->as('kim');
        foreach (['/admin/config-settings', '/admin/tools'] as $p) {
            $r = $kim->get($p);
            $this->assertSame(403, $r->status, $p);
            $this->assertNotContains('data-system-subnav', $r->body, "{$p} 내용 없음");
        }
        $this->assertNotContains('admin.tools', $kim->get('/projects')->body, '일반 사용자 메뉴에 없음');
        $this->assertTrue(in_array((new TestClient($this->app()))->get('/admin/config-settings')->status, [302, 303, 401], true), '비로그인');
        // 예전 이름이 화면 문구·화면 틀·가이드에 남지 않음. 허용: 영어 'System status'는 상태 아이콘(시스템 상태: icon.group.system, icon.system.*)의 뜻
        $files = array_merge(glob(PJG_ROOT . '/src/Lang/*.php'), glob(PJG_ROOT . '/public/assets/js/i18n/*.js'));
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(PJG_ROOT . '/src/Views', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $files[] = $f->getPathname();
        }
        foreach (glob(PJG_ROOT . '/resources/guide/*/*/*.md') as $f) {
            $files[] = $f;
        }
        $left = [];
        foreach ($files as $f) {
            foreach (file($f) as $n => $line) {
                // X12: 옛 탭 이름(알림 보관·저장소 허용 호스트·저장소 연결 테스트)과 긴 영어 이름도. 허용: 구역 제목 "알림 보관 기간"·입력 라벨 "…알림 보관(일)"(탭 이름이 아님)
                if (preg_match('/도구·워커 관리|시스템 현황|Tools & workers|Tools and workers|저장소 허용 호스트|저장소 연결 테스트|> 알림 보관|알림 보관\(시스템|System administration|System monitoring|Allowed repository hosts|Repository allowed hosts|Repository connection test|Notification retention \(|변경 내용 확인\(|Review changes \(|"변경 내용 확인"|Review changes\\\\?"/u', $line) === 1
                    // X14: 단추 이름을 따옴표로 말하는 안내(saml.import.help 등)도 예전 이름이면 안 됨
                    // X13: 메뉴 이름 영어 "Rule descriptions" → "Rules". 허용: 도구가 낸 규칙 설명 글 자체를 말하는 가이드 문장("Rule descriptions are not translated")
                    || (str_contains($line, 'Rule descriptions') && !str_contains($line, 'Rule descriptions are not translated'))
                    || (str_contains($line, 'System status') && !preg_match('/icon\.(group\.system|system\.)/', $line))) {
                    $left[] = basename($f) . ':' . ($n + 1);
                }
            }
        }
        $this->assertSame([], $left, '예전 이름');
    }

    /**
     * X12: 알림 보관 기간(app_settings)·허용 호스트(DB 표)는 설정 관리의 "알림"·"저장소 연결" 탭 구역 — 같은 화면에 싣고(각자 자기 API·단추),
     * 옛 화면 주소는 그 탭으로 이동(비관리자는 이동 전에 403, 내용 없음). 저장 위치·API·권한은 그대로.
     */
    public function testMergedSectionsAndOldAddresses(): void
    {
        $admin = $this->as('admin');
        $body = $admin->get('/admin/config-settings')->body;
        foreach (['data-merge-into="notification"', 'data-setting="notifications.retain_read_days"', 'data-setting="notifications.max_per_user"',
            'data-repo-hosts data-merge-into="repository" data-merge-pos="start"', 'data-host-form', 'data-test-all', 'data-host-rows', 'js/pages/admin_notifications.js', 'js/pages/admin_repo_hosts.js'] as $m) {
            $this->assertContains($m, $body, $m);
        }
        foreach (['/admin/notifications' => '/admin/config-settings#notification', '/admin/repo-hosts' => '/admin/config-settings#repository'] as $old => $new) {
            $r = $admin->get($old);
            $this->assertSame(302, $r->status, $old);
            $this->assertSame($new, $r->header('Location'), $old);
        }
        $kim = $this->as('kim');
        foreach (['/admin/notifications', '/admin/repo-hosts', '/admin/config-settings'] as $p) {
            $r = $kim->get($p);
            $this->assertSame(403, $r->status, $p);
            $this->assertTrue($r->header('Location') === null && !str_contains($r->body, 'config-settings#') && !str_contains($r->body, 'data-merge-into'), "{$p} 내용·이동 주소 없음");
        }
        // 각 구역의 API 권한은 그대로(admin만)
        $this->assertSame(403, $kim->get('/api/v1/admin/settings')->status);
        $this->assertSame(403, $kim->get('/api/v1/admin/repo-hosts')->status);
        $this->assertSame(200, $admin->get('/api/v1/admin/settings')->status);
        $this->assertSame(200, $admin->get('/api/v1/admin/repo-hosts')->status);
        $this->assertTrue(in_array((new TestClient($this->app()))->get('/admin/repo-hosts')->status, [302, 303, 401], true), '비로그인은 로그인으로');
    }

    public function testWorkerReloadsSettingsWithoutRestartAndFlagsWorkerKeys(): void
    {
        $registry = SettingsRegistry::default()->with([
            'test.dummy_worker' => ['spec' => ['type' => 'int', 'min' => 1, 'max' => 9, 'default' => '1'], 'group' => 'test', 'restart' => 'worker'],
        ]);
        $app = $this->app([], $registry);
        $worker = new \PJGuardian\Services\Queue\Worker($app, $this->ini);
        $reload = new \ReflectionMethod($worker, 'reloadSettings');
        $extra = new \ReflectionProperty($worker, 'extra');
        $this->assertTrue($reload->invoke($worker), '처음: 시작 때 버전을 생존 신호에');
        $this->assertSame(['', []], [$extra->getValue($worker)['config']['version'], $extra->getValue($worker)['config']['pending_restart']]);
        $this->assertFalse($reload->invoke($worker), '바뀐 것 없음');
        $store = new SettingsStore($this->dir . '/settings.local.ini');
        $v = $store->write(['scheduler.max_per_tick' => '9', 'test.dummy_worker' => '5'], '');
        $this->assertTrue($reload->invoke($worker), '파일이 바뀌면 다시 읽음');
        $this->assertSame(9, $app->config->int('scheduler.max_per_tick'), '재시작 없이 다음 작업부터 새 값(App 설정 교체)');
        $this->assertSame([$v, ['test.dummy_worker']], [$extra->getValue($worker)['config']['version'], $extra->getValue($worker)['config']['pending_restart']]);
        // P32 인수 시험에서 찾은 결함: 같은 초에 같은 길이로 다시 저장(9 → 7)하면 [수정 시각, 크기]가 같아 놓쳤다 — 내용 해시로 잡는다
        $local = $this->dir . '/settings.local.ini';
        $mtime = (int) filemtime($local);
        file_put_contents($local, str_replace('max_per_tick = "9"', 'max_per_tick = "7"', (string) file_get_contents($local)));
        touch($local, $mtime);
        clearstatcache();
        $this->assertTrue($reload->invoke($worker), '같은 시각·같은 크기라도 내용이 바뀌면 다시 읽음');
        $this->assertSame(7, $app->config->int('scheduler.max_per_tick'));
        $v = (new SettingsStore($local))->write(['scheduler.max_per_tick' => '9', 'test.dummy_worker' => '5'], (string) $app->config->localVersion());
        $reload->invoke($worker);
        // 화면: 워커가 읽은 버전 = 지금 버전 → 반영됨, restart=worker 키는 재시작 필요 목록
        $svc = new \PJGuardian\Services\Settings\SettingsService($app->config, $store, $app->pathPolicy(), $app->audit(), $this->db, $app->logger,
            static fn () => $app->config->reload(), ['running' => true, 'version' => $v, 'beat_at' => null, 'pending_restart' => ['test.dummy_worker']]);
        $view = $svc->view();
        $this->assertSame([true, ['test.dummy_worker']], [$view['worker']['applied'], $view['worker']['pending_restart']]);
        // 설정 파일이 틀리게 바뀌면 그 키만 무시(이전 값 대신 config.ini 값) — 워커는 멈추지 않는다
        file_put_contents($this->dir . '/settings.local.ini', "[scheduler]\nmax_per_tick = \"0\"\n");
        clearstatcache();
        $this->assertTrue($reload->invoke($worker));
        $this->assertSame(50, $app->config->int('scheduler.max_per_tick'));
        $this->assertTrue(in_array(['key' => 'scheduler.max_per_tick', 'code' => 'invalid_value'], $app->config->warnings(), true));
    }
}
