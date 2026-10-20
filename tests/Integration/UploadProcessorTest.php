<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Actor;
use PJGuardian\Services\Upload\DiskGuard;
use PJGuardian\Support\Fs;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 업로드 후처리 파이프라인(명세 5.4, 19.10, P13): 조립 → 해제 → 사전 검사 → 경고 확인 → 분석 큐.
 * 사용자: admin, kim·lee(py·js 프로젝트에 직접 매핑), park(매핑 없음).
 */
final class UploadProcessorTest extends TestCase
{
    private const GOOD = PJG_ROOT . '/samples/zips';
    private const BAD = PJG_ROOT . '/samples/zips_bad';
    private App $app;
    private string $root;
    private int $py;
    private int $js;

    public function setUp(): void
    {
        $this->root = $this->tempDir('uproc');
        foreach (['uploads', 'work', 'logs'] as $d) {
            mkdir($this->root . '/' . $d);
        }
        $this->app = TestApp::make($this->root . '/logs', [
            'paths' => ['uploads_dir' => $this->root . '/uploads', 'work_dir' => $this->root . '/work', 'snapshots_dir' => $this->root],
            'upload' => ['chunk_size' => '256KB', 'max_zip_size' => '2MB'],
        ]);
        $db = $this->app->db();
        $adminId = TestDb::user($db, 'admin', 'admin');
        foreach (['kim', 'lee', 'park'] as $u) {
            TestDb::user($db, $u, 'user');
        }
        $this->py = TestDb::project($db, 'py', 'python');
        $this->js = TestDb::project($db, 'js', 'javascript');
        $actor = Actor::fromUser(['id' => $adminId, 'login_id' => 'admin'], null);
        foreach ([$this->py, $this->js] as $pid) {
            foreach (['kim', 'lee'] as $u) {
                $this->app->projectAccess()->add($actor, $pid, ['subject_type' => 'user', 'login_id' => $u]);
            }
        }
        $this->app->setDiskGuard(new DiskGuard($db, $this->app->uploadSettings(), $this->app->logger, static fn (): float => 1e12, static fn (): float => 2e12));
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status, $login);
        return $c;
    }

    /** 업로드(init·청크·complete, HTTP)만. upload_id */
    private function send(TestClient $c, string $zip, ?int $project = null): string
    {
        $data = (string) file_get_contents($zip);
        $r = $c->post('/api/v1/projects/' . ($project ?? $this->py) . '/uploads', ['file_name' => basename($zip), 'total_size' => strlen($data)]);
        $this->assertSame(201, $r->status, $r->body);
        $id = $r->decoded()['data']['upload_id'];
        foreach (str_split($data, 256 * 1024) as $n => $part) {
            $this->assertSame(200, $c->raw('PUT', "/api/v1/uploads/{$id}/chunks/{$n}", $part, ['x-chunk-sha256' => hash('sha256', $part)])->status);
        }
        $this->assertSame(202, $c->post("/api/v1/uploads/{$id}/complete")->status);
        return $id;
    }

    /** 업로드 + 조립 + 해제(process_upload --once와 같은 순서). [upload_id, 처리 결과] */
    private function upload(TestClient $c, string $zip, ?int $project = null): array
    {
        $id = $this->send($c, $zip, $project);
        $this->assertSame('extracting', $this->app->uploadAssembler()->assembleNext()['status']);
        $p = $this->app->uploadProcessor()->processNext();
        $this->assertSame($id, $p['upload_id']);
        return [$id, $p];
    }

    private function row(string $id): array
    {
        return $this->app->db()->one('SELECT * FROM uploads WHERE id = ?', [$id]);
    }

    /** @return list<string> */
    private function workDirs(): array
    {
        return array_values(array_filter(Fs::listDir($this->root . '/work'), static fn (string $n): bool => preg_match('/^[0-9a-f]{8}$/', $n) === 1));
    }

    private function strip(int $pid): ?int
    {
        $v = $this->app->db()->value('SELECT strip_top_folder FROM projects WHERE id = ?', [$pid]);
        return $v === null ? null : (int) $v;
    }

    // ---- 정상 ----

    public function testNormalZipIsQueuedWithAnalysisRow(): void
    {
        $kim = $this->as('kim');
        [$id, $p] = $this->upload($kim, self::GOOD . '/python_sample_toplevel.zip');
        $this->assertSame(['queued', []], [$p['status'], $p['warnings']]);
        $row = $this->row($id);
        $this->assertSame(['queued', 0], [$row['status'], (int) $row['reserved_bytes']]);
        $a = $this->app->db()->one('SELECT * FROM analyses WHERE upload_id = ?', [$id]);
        $this->assertSame(
            ['queued', 'upload', 'user', 'ruff', $this->py, hash_file('sha256', self::GOOD . '/python_sample_toplevel.zip'), $row['work_dir']],
            [$a['status'], $a['source_kind'], $a['trigger_kind'], $a['tool'], (int) $a['project_id'], $a['assembled_sha256'], $a['work_dir']],
        );
        $this->assertSame((int) $this->app->db()->value("SELECT id FROM users WHERE login_id = 'kim'"), (int) $a['requested_by']);
        $this->assertSame(1, $this->strip($this->py), '첫 업로드에서 최상위 폴더 규칙 확정(제거)');
        $work = $this->root . '/work/' . $row['work_dir'];
        $this->assertTrue(is_file($work . '/main.py'), '감싸는 폴더를 뺀 경로로 해제');
        $info = $kim->get("/api/v1/uploads/{$id}")->decoded()['data'];
        $this->assertSame([(int) $a['id'], 'queued'], [$info['analysis_id'], $info['status']]);
        $this->assertTrue(is_file($this->root . "/uploads/{$id}/upload.zip"), '조립본은 분석이 끝날 때까지 보관(P14·P16이 정리)');
        $this->assertSame(null, $this->app->uploadProcessor()->processNext(), '처리할 것 없음');
    }

    public function testJavascriptProjectSkipsUploadedEslintConfigInTargetCount(): void
    {
        $kim = $this->as('kim');
        [$id, $p] = $this->upload($kim, self::GOOD . '/js_with_js_config.zip', $this->js);
        $this->assertSame('queued', $p['status']);
        $this->assertSame('eslint', $this->app->db()->value('SELECT tool FROM analyses WHERE upload_id = ?', [$id]));
        $work = $this->root . '/work/' . $this->row($id)['work_dir'];
        $all = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($work, \FilesystemIterator::SKIP_DOTS));
        $configs = 0;
        foreach ($it as $f) {
            if (preg_match('/\.(js|jsx|ts|tsx)$/', $f->getFilename())) {
                $all++;
                $configs += \PJGuardian\Services\Archive\TargetScanner::isJsConfig($f->getFilename()) ? 1 : 0;
            }
        }
        $this->assertTrue($configs >= 1, '샘플에 eslint.config.* 있음');
        $this->assertSame($all - $configs, $p['targets'], 'ESLint 설정 파일은 대상 수에서 뺌');
    }

    public function testProjectExcludePatternsApply(): void
    {
        $admin = Actor::fromUser(['id' => 1, 'login_id' => 'admin'], null);
        $this->app->projects()->update($admin, $this->py, ['exclude_patterns' => ['tests/']]);
        [$id, $p] = $this->upload($this->as('kim'), self::GOOD . '/python_sample.zip');
        $work = $this->root . '/work/' . $this->row($id)['work_dir'];
        $this->assertFalse(is_dir($work . '/tests'), '프로젝트 추가 패턴으로 제외');
        $this->assertTrue($p['skipped'] > 0);
    }

    // ---- 경고와 확인 ----

    public function testTopFolderMismatchWarningAndConfirm(): void
    {
        $kim = $this->as('kim');
        $this->upload($kim, self::GOOD . '/python_sample_toplevel.zip');
        $this->assertSame(1, $this->strip($this->py));
        // 두 번째 업로드: 감싸는 폴더 없음 → top_folder_missing
        [$id, $p] = $this->upload($kim, self::GOOD . '/python_sample.zip');
        $this->assertSame(['needs_confirmation', ['top_folder_missing']], [$p['status'], $p['warnings']]);
        $info = $kim->get("/api/v1/uploads/{$id}")->decoded()['data'];
        $this->assertSame(['top_folder_missing'], array_column($info['warning_messages'], 'code'));
        $this->assertContains('신규', $info['warning_messages'][0]['message']);
        $this->assertSame(null, $info['analysis_id']);
        $row = $this->row($id);
        $this->assertTrue(is_dir($this->root . '/work/' . $row['work_dir']), '확인 대기 중 해제 결과 보존');
        $this->assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM analyses WHERE upload_id = ?', [$id]));

        // 다른 사용자(같은 프로젝트), admin, 못 보는 사용자, 없는 id → 404
        foreach (['lee', 'admin', 'park'] as $who) {
            $r = $this->as($who)->post("/api/v1/uploads/{$id}/confirm");
            $this->assertSame([404, 'upload_not_found'], [$r->status, $r->decoded()['error']['code']], $who);
        }
        $this->assertSame(404, $kim->post('/api/v1/uploads/' . str_repeat('0', 32) . '/confirm')->status);
        $this->assertSame(403, $kim->request('POST', "/api/v1/uploads/{$id}/confirm", [], null, null, [], false)->status, 'CSRF');

        $ok = $kim->post("/api/v1/uploads/{$id}/confirm");
        $this->assertSame(200, $ok->status, $ok->body);
        $aid = $ok->decoded()['data']['analysis_id'];
        $this->assertSame(['queued', $aid], [$this->row($id)['status'], (int) $this->app->db()->value('SELECT id FROM analyses WHERE upload_id = ?', [$id])]);
        $this->assertTrue($this->row($id)['confirmed_at'] !== null);
        $this->assertSame(1, $this->strip($this->py), '이미 확정된 규칙은 그대로');
        $again = $kim->post("/api/v1/uploads/{$id}/confirm");
        $this->assertSame([409, 'upload_not_confirmable'], [$again->status, $again->decoded()['error']['code']], '두 번 확인해도 분석은 하나');
        $this->assertSame(1, (int) $this->app->db()->value('SELECT COUNT(*) FROM analyses WHERE upload_id = ?', [$id]));

        // 규칙=유지인 프로젝트에 감싸는 폴더가 생기면 top_folder_kept(폴더 이름을 문구에)
        $this->app->db()->execute('UPDATE projects SET strip_top_folder = 0 WHERE id = ?', [$this->py]);
        [$id2, $p2] = $this->upload($kim, self::GOOD . '/python_sample_toplevel.zip');
        $this->assertSame(['top_folder_kept'], $p2['warnings']);
        $this->assertContains('my_project_v2', $kim->get("/api/v1/uploads/{$id2}")->decoded()['data']['warning_messages'][0]['message']);
    }

    public function testNoTargetWarningCancelDeletesEverything(): void
    {
        $kim = $this->as('kim');
        $before = $this->workDirs();
        [$id, $p] = $this->upload($kim, self::GOOD . '/empty_no_target.zip');
        $this->assertSame(['needs_confirmation', ['no_target'], 0], [$p['status'], $p['warnings'], $p['targets']]);
        $msg = $kim->get("/api/v1/uploads/{$id}")->decoded()['data']['warning_messages'][0];
        $this->assertSame(true, $msg['danger'], '대상 없음은 위험 표시');
        $this->assertContains('.py', $msg['message']);
        $this->assertContains('해결', $msg['message'], '기존 결함이 resolved로 바뀔 수 있음을 문구에');
        $this->assertSame(null, $this->strip($this->py), '확인 전에는 규칙을 정하지 않음');

        $del = $kim->request('DELETE', "/api/v1/uploads/{$id}");
        $this->assertSame([200, 'aborted'], [$del->status, $del->decoded()['data']['status']]);
        $this->assertSame($before, $this->workDirs(), '해제 결과 삭제');
        $this->assertFalse(is_dir($this->root . "/uploads/{$id}"), '업로드 폴더(zip) 삭제');
        $this->assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM analyses'));
        $this->assertSame('upload_not_confirmable', $kim->post("/api/v1/uploads/{$id}/confirm")->decoded()['error']['code']);
    }

    public function testNoTargetConfirmOnFirstUploadSavesRuleFromExtraction(): void
    {
        $kim = $this->as('kim');
        [$id] = $this->upload($kim, self::GOOD . '/empty_no_target.zip');
        $this->assertSame(200, $kim->post("/api/v1/uploads/{$id}/confirm")->status);
        $this->assertTrue($this->strip($this->py) !== null, '확인 뒤 해제 때 정한 규칙을 저장');
    }

    // ---- 실패 ----

    public function testMaliciousZipFailsCleansWorkDirAndKeepsZipOneDay(): void
    {
        $kim = $this->as('kim');
        $before = $this->workDirs();
        foreach (['zip_slip' => 'forbidden_entry', 'corrupt' => 'invalid_zip', 'case_conflict' => 'name_conflict', 'reserved_names' => 'unsupported_name'] as $n => $code) {
            [$id, $p] = $this->upload($kim, self::BAD . "/{$n}.zip");
            $this->assertSame(['failed', $code], [$p['status'], $p['error_code']], $n);
            $info = $kim->get("/api/v1/uploads/{$id}")->decoded()['data'];
            $this->assertSame([$code, 'upload.error.' . $code], [$info['error_code'], $info['error_guide']], $n);
            $this->assertTrue($info['error_message'] !== '' && !str_contains($info['error_message'], $this->root), $n . ': 사용자 문구, 내부 경로 없음');
            $row = $this->row($id);
            $this->assertSame([null, 0], [$row['work_dir'], (int) $row['reserved_bytes']], $n);
            $this->assertTrue(is_file($this->root . "/uploads/{$id}/upload.zip"), $n . ': zip은 1일 보관');
        }
        $this->assertContains('../../evil.txt', $this->row($this->app->db()->value("SELECT id FROM uploads WHERE error_code = 'forbidden_entry'"))['error_message'], '항목 이름을 문구에');
        $this->assertSame($before, $this->workDirs(), '작업 디렉토리 삭제');
        $this->assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM analyses'));

        $this->app->clock->advance(23 * 3600);
        $this->assertSame(0, $this->app->cleanup()->purgeFailedZips()['failed_zips'], '1일 전에는 보관');
        $this->app->clock->advance(2 * 3600);
        $this->assertSame(4, $this->app->cleanup()->purgeFailedZips()['failed_zips']);
        $this->assertSame([], array_values(array_filter(Fs::listDir($this->root . '/uploads'), static fn (string $n): bool => strlen($n) === 32)));
    }

    // ---- 동시 업로드 수(P13 변경) ----

    public function testConcurrentLimitCountsUntilExtractionEnds(): void
    {
        $kim = $this->as('kim');
        $this->send($kim, self::GOOD . '/python_sample.zip');
        $this->send($kim, self::GOOD . '/empty_no_target.zip');
        $third = $kim->post("/api/v1/projects/{$this->py}/uploads", ['file_name' => 'c.zip', 'total_size' => 10]);
        $this->assertSame([429, 'too_many_uploads'], [$third->status, $third->decoded()['error']['code']], '조립 대기 2개면 3번째 거부');
        $this->app->uploadAssembler()->assembleNext();
        $this->app->uploadAssembler()->assembleNext();
        $this->assertSame(429, $kim->post("/api/v1/projects/{$this->py}/uploads", ['file_name' => 'c.zip', 'total_size' => 10])->status, '해제 대기 2개도 셈');
        $this->app->uploadProcessor()->processNext(); // queued
        $this->app->uploadProcessor()->processNext(); // needs_confirmation(no_target)
        $this->assertSame(201, $kim->post("/api/v1/projects/{$this->py}/uploads", ['file_name' => 'c.zip', 'total_size' => 10])->status, 'queued·needs_confirmation은 세지 않음');
    }

    // ---- 정리 ----

    public function testCleanupExpiresConfirmationsAndOrphanWorkDirs(): void
    {
        $kim = $this->as('kim');
        [$id] = $this->upload($kim, self::GOOD . '/empty_no_target.zip');
        $work = $this->row($id)['work_dir'];
        // 워커가 죽은 해제 작업(extracting + work_dir), 아무도 가리키지 않는 오래된 작업 디렉토리, 최근 것
        $stuck = $this->send($kim, self::GOOD . '/python_sample.zip');
        $this->app->uploadAssembler()->assembleNext();
        mkdir($this->root . '/work/deadbeef');
        $this->app->db()->execute("UPDATE uploads SET work_dir = 'deadbeef' WHERE id = ?", [$stuck]);
        mkdir($this->root . '/work/0badc0de');
        mkdir($this->root . '/work/0000aaaa');
        $nowTs = $this->app->clock->now()->getTimestamp();
        touch($this->root . '/work/0badc0de', $nowTs - 30 * 3600);
        touch($this->root . '/work/0000aaaa', $nowTs + 3600);

        $this->app->clock->advance(23 * 3600);
        $r = $this->app->cleanup()->runAll();
        $this->assertSame([0, 0], [$r['expired_confirmations'], $r['stuck_extractions']], '24시간 전');
        $this->app->clock->advance(2 * 3600);
        $dry = $this->app->cleanup()->runAll(true);
        $this->assertSame(1, $dry['expired_confirmations']);
        $this->assertSame('needs_confirmation', $this->row($id)['status'], 'dry-run은 바꾸지 않음');
        $r = $this->app->cleanup()->runAll();
        $this->assertSame([1, 1], [$r['expired_confirmations'], $r['stuck_extractions']]);
        $this->assertSame('aborted', $this->row($id)['status']);
        $this->assertFalse(is_dir($this->root . '/work/' . $work), '확인 대기 해제 결과 삭제');
        $this->assertFalse(is_dir($this->root . "/uploads/{$id}"));
        $this->assertSame([null, 'extracting'], [$this->row($stuck)['work_dir'], $this->row($stuck)['status']], '중단된 해제는 다시 처리 대기');
        $this->assertFalse(is_dir($this->root . '/work/deadbeef'));
        $this->assertFalse(is_dir($this->root . '/work/0badc0de'), '오래된 남은 작업 디렉토리');
        $this->assertTrue(is_dir($this->root . '/work/0000aaaa'), '최근 디렉토리는 유지');
        $this->assertSame('queued', $this->app->uploadProcessor()->processNext()['status'], '되돌린 업로드를 다시 처리');
    }

    public function testRoutesAreRegisteredWithAccessCheck(): void
    {
        $found = false;
        foreach ($this->app->router->routeTable() as $r) {
            if ($r['path'] === '/api/v1/uploads/{id}/confirm' && $r['method'] === 'POST') {
                $found = array_filter($r['middleware'], static fn (object $m): bool => $m instanceof \PJGuardian\Http\Middleware\RequireProjectAccess) !== [];
            }
        }
        $this->assertTrue($found, '확인 API에 접근 판정');
        // 프로젝트 접근을 잃으면 자기 업로드도 404(접근 판정이 먼저)
        $kim = $this->as('kim');
        [$id] = $this->upload($kim, self::GOOD . '/empty_no_target.zip');
        $this->app->db()->execute("DELETE FROM project_access WHERE subject_type = 'user' AND subject_id = (SELECT id FROM users WHERE login_id = 'kim')");
        $this->assertSame(404, $kim->post("/api/v1/uploads/{$id}/confirm")->status);
    }
}
