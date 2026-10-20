<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Actor;
use PJGuardian\Services\Upload\DiskGuard;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 청크 업로드(명세 5.4, 19.3, 19.10): 프로토타입(P06) 시나리오를 자동 시험으로 옮긴 것.
 * 설정은 작게: 청크 256KB, 최대 2MB, 해제 상한 1MB, 사용자당 동시 2개. 디스크 여유는 모의한다.
 *
 * 사용자: admin, kim(프로젝트 p1에 직접 매핑), lee(p1에 직접 매핑, 다른 사용자), park(매핑 없음)
 */
final class UploadTest extends TestCase
{
    private const CHUNK = 256 * 1024;
    private App $app;
    private string $root;
    private int $p1;
    private int $p2;
    private float $free = 1e12;
    private float $total = 2e12;

    public function setUp(): void
    {
        $this->root = $this->tempDir('upload');
        mkdir($this->root . '/uploads');
        mkdir($this->root . '/logs');
        $this->app = TestApp::make($this->root . '/logs', [
            'paths' => ['uploads_dir' => $this->root . '/uploads', 'snapshots_dir' => $this->root, 'work_dir' => $this->root],
            'upload' => ['chunk_size' => '256KB', 'max_zip_size' => '2MB', 'max_concurrent_per_user' => '2'],
            'extract' => ['max_extracted_size' => '1MB'],
        ]);
        $db = $this->app->db();
        $adminId = TestDb::user($db, 'admin', 'admin');
        foreach (['kim', 'lee', 'park'] as $u) {
            TestDb::user($db, $u, 'user');
        }
        $this->p1 = TestDb::project($db, 'alpha');
        $this->p2 = TestDb::project($db, 'beta');
        $actor = Actor::fromUser(['id' => $adminId, 'login_id' => 'admin'], null);
        foreach (['kim', 'lee'] as $u) {
            $this->app->projectAccess()->add($actor, $this->p1, ['subject_type' => 'user', 'login_id' => $u]);
        }
        $this->app->setDiskGuard(new DiskGuard($db, $this->app->uploadSettings(), $this->app->logger, fn (): float => $this->free, fn (): float => $this->total));
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status, $login);
        return $c;
    }

    private function init(TestClient $c, int $size, ?int $project = null, string $name = 'code.zip'): \PJGuardian\Http\Response
    {
        return $c->post('/api/v1/projects/' . ($project ?? $this->p1) . '/uploads', ['file_name' => $name, 'total_size' => $size]);
    }

    private function put(TestClient $c, string $id, int $n, string $data, ?string $sha = null): \PJGuardian\Http\Response
    {
        return $c->raw('PUT', "/api/v1/uploads/{$id}/chunks/{$n}", $data, ['x-chunk-sha256' => $sha ?? hash('sha256', $data)]);
    }

    /** @return list<string> */
    private static function split(string $data): array
    {
        return str_split($data, self::CHUNK);
    }

    private function dir(string $id): string
    {
        return $this->root . '/uploads/' . $id;
    }

    // ---- 정상 흐름 ----

    public function testFullUploadThenAssembleMatchesHash(): void
    {
        $kim = $this->as('kim');
        $data = random_bytes(self::CHUNK * 2 + 1234);
        $r = $this->init($kim, strlen($data), null, '../../evil name.zip');
        $this->assertSame(201, $r->status, $r->body);
        $d = $r->decoded()['data'];
        $id = $d['upload_id'];
        $this->assertSame(1, preg_match('/^[0-9a-f]{32}$/', $id), '서버가 만든 32자리 16진수');
        $this->assertSame([self::CHUNK, 3], [$d['chunk_size'], $d['total_chunks']]);
        $this->assertTrue(is_dir($this->dir($id)), 'uploads/<upload_id>/');
        $row = $this->app->db()->one('SELECT * FROM uploads WHERE id = ?', [$id]);
        $this->assertSame('../../evil name.zip', $row['file_name'], '파일명은 표시용으로만 저장');
        $this->assertSame(strlen($data) + 1024 * 1024, (int) $row['reserved_bytes'], '예약 = S + max(S, 해제 상한)');

        foreach (self::split($data) as $n => $part) {
            $res = $this->put($kim, $id, $n, $part);
            $this->assertSame(200, $res->status, $res->body);
        }
        $st = $kim->get("/api/v1/uploads/{$id}/status")->decoded()['data'];
        $this->assertSame([0, 1, 2], array_column($st['received'], 'index'));
        $this->assertSame(hash('sha256', self::split($data)[1]), $st['received'][1]['sha256'], '청크 해시(이어 올리기 비교용)');

        $done = $kim->post("/api/v1/uploads/{$id}/complete");
        $this->assertSame([202, 'assemble_pending'], [$done->status, $done->decoded()['data']['status']]);
        $this->assertFalse(is_file($this->dir($id) . '/upload.zip'), '요청 안에서 조립하지 않음');
        $this->assertSame('upload_not_receiving', $this->put($kim, $id, 0, self::split($data)[0])->decoded()['error']['code'], '완료 후 청크 거부');

        $result = $this->app->uploadAssembler()->assembleNext();
        $this->assertSame('extracting', $result['status']);
        $this->assertSame(hash('sha256', $data), $result['sha256']);
        $this->assertSame(hash('sha256', $data), hash_file('sha256', $this->dir($id) . '/upload.zip'), '조립본 = 원본');
        $this->assertSame(['.lock', 'upload.zip'], $this->listDir($this->dir($id)), '청크·해시 파일 삭제');
        $info = $kim->get("/api/v1/uploads/{$id}")->decoded()['data'];
        $this->assertSame(['extracting', hash('sha256', $data), 3], [$info['status'], $info['sha256'], $info['received_chunks']]);
        $this->assertSame(null, $this->app->uploadAssembler()->assembleNext(), '조립 대기 없음');
    }

    /** @return list<string> */
    private function listDir(string $dir): array
    {
        $names = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        sort($names);
        return $names;
    }

    public function testSameChunkTwiceIsIdempotentAndTamperedChunkIsRejected(): void
    {
        $kim = $this->as('kim');
        $data = random_bytes(self::CHUNK + 10);
        $id = $this->init($kim, strlen($data))->decoded()['data']['upload_id'];
        [$a, $b] = self::split($data);
        $this->assertSame(1, $this->put($kim, $id, 0, $a)->decoded()['data']['received']);
        $again = $this->put($kim, $id, 0, $a);
        $this->assertSame([200, 1], [$again->status, $again->decoded()['data']['received']], '같은 청크 재전송: 받은 수 그대로');

        $tampered = $b;
        $tampered[0] = chr(ord($tampered[0]) ^ 0xFF);
        $bad = $this->put($kim, $id, 1, $tampered, hash('sha256', $b));
        $this->assertSame([422, 'chunk_hash_mismatch'], [$bad->status, $bad->decoded()['error']['code']]);
        $this->assertFalse(is_file($this->dir($id) . '/1.part'), '불일치 청크는 저장하지 않음');
        $this->assertSame([], glob($this->dir($id) . '/*.uploading.*'), '임시 파일도 남기지 않음');
        $this->assertSame(200, $this->put($kim, $id, 1, $b)->status, '올바른 본문 재전송');
        $this->assertSame(202, $kim->post("/api/v1/uploads/{$id}/complete")->status);
    }

    public function testChunkValidation(): void
    {
        $kim = $this->as('kim');
        $data = random_bytes(self::CHUNK + 10);
        $id = $this->init($kim, strlen($data))->decoded()['data']['upload_id'];
        [$a] = self::split($data);
        $cases = [
            ['2', $a, null, 400, 'invalid_chunk_index'],          // 범위 밖
            ['-1', $a, null, 400, 'invalid_chunk_index'],
            ['..%2F1', $a, null, 400, 'invalid_chunk_index'],     // 경로 문자
            ['0x1', $a, null, 400, 'invalid_chunk_index'],
            ['1', $a, null, 400, 'invalid_chunk_length'],         // 마지막 청크는 10바이트
            ['0', substr($a, 1), null, 400, 'invalid_chunk_length'],
            ['0', $a, 'zz', 400, 'invalid_chunk_hash'],
        ];
        foreach ($cases as [$idx, $body, $sha, $status, $code]) {
            $res = $kim->raw('PUT', "/api/v1/uploads/{$id}/chunks/{$idx}", $body, ['x-chunk-sha256' => $sha ?? hash('sha256', $body)]);
            $this->assertSame([$status, $code], [$res->status, $res->decoded()['error']['code'] ?? null], "index={$idx}");
        }
        // 선언한 길이와 실제 본문이 다르면(Content-Length 위조) 본문 길이로 다시 검사
        $res = $kim->raw('PUT', "/api/v1/uploads/{$id}/chunks/0", $a . 'xx', ['x-chunk-sha256' => hash('sha256', $a), 'content-length' => (string) self::CHUNK]);
        $this->assertSame('invalid_chunk_length', $res->decoded()['error']['code']);
        // upload_id 경로 조작: 형식이 틀리면 없는 업로드와 같은 404
        foreach (['../' . $id, strtoupper($id), $id . 'a', 'abc', '..%2F..%2Fetc'] as $bad) {
            $r = $kim->get('/api/v1/uploads/' . $bad . '/status');
            $this->assertSame(404, $r->status, $bad);
        }
        $this->assertSame([], glob($this->dir($id) . '/*.{part,sha256}', GLOB_BRACE) ?: [], '거부된 요청은 청크를 남기지 않음');
        $this->assertSame([], glob($this->dir($id) . '/*.uploading.*') ?: [], '임시 파일도 남기지 않음');
    }

    public function testInitLimitsAndValidation(): void
    {
        $kim = $this->as('kim');
        $over = $this->init($kim, 2 * 1024 * 1024 + 1);
        $this->assertSame([413, 'upload_too_large'], [$over->status, $over->decoded()['error']['code']]);
        $this->assertContains('2MB', $over->decoded()['error']['message']);
        $this->assertSame('invalid_upload_size', $this->init($kim, 0)->decoded()['error']['code']);
        $this->assertSame('invalid_upload_size', $kim->post("/api/v1/projects/{$this->p1}/uploads", ['file_name' => 'a.zip', 'total_size' => '100'])->decoded()['error']['code']);
        $this->assertSame('invalid_file_name', $this->init($kim, 10, null, "\x01")->decoded()['error']['code']);
        $this->assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM uploads'));
    }

    public function testConcurrentUploadLimitPerUser(): void
    {
        $kim = $this->as('kim');
        $first = $this->init($kim, 100)->decoded()['data']['upload_id'];
        $this->assertSame(201, $this->init($kim, 100)->status);
        $third = $this->init($kim, 100);
        $this->assertSame([429, 'too_many_uploads'], [$third->status, $third->decoded()['error']['code']]);
        $this->assertSame(201, $this->init($this->as('lee'), 100)->status, '다른 사용자는 따로 센다');
        $kim->request('DELETE', "/api/v1/uploads/{$first}");
        $this->assertSame(201, $this->init($kim, 100)->status, '취소하면 다시 시작 가능');

        // P13(명세 19.10, 사용자 결정): 전송을 마친 조립 대기·조립 중·해제 대기도 센다. 확인 대기·queued는 세지 않는다
        $this->app->db()->execute("UPDATE uploads SET status = 'aborted' WHERE user_id = (SELECT id FROM users WHERE login_id = 'kim')");
        $data = random_bytes(100);
        foreach ([0, 1] as $i) {
            $id = $this->init($kim, 100)->decoded()['data']['upload_id'];
            $this->put($kim, $id, 0, $data);
            $this->assertSame(202, $kim->post("/api/v1/uploads/{$id}/complete")->status);
        }
        $this->assertSame(429, $this->init($kim, 100)->status, '조립 대기 2개면 3번째 거부');
        $this->app->db()->execute("UPDATE uploads SET status = 'extracting', sha256 = ? WHERE status = 'assemble_pending'", [str_repeat('a', 64)]);
        $this->assertSame(429, $this->init($kim, 100)->status, '해제 대기 2개도 셈');
        $this->app->db()->execute("UPDATE uploads SET status = 'needs_confirmation' WHERE status = 'extracting'");
        $this->assertSame(201, $this->init($kim, 100)->status, '확인 대기는 세지 않음');
    }

    public function testDiskGuardReservationsAndUsage(): void
    {
        $kim = $this->as('kim');
        $mb = 1024 * 1024;
        // 2MB 업로드의 예약 = 2MB + max(2MB, 1MB) = 4MB
        $this->assertSame(4 * $mb, $this->app->diskGuard()->reserveFor(2 * $mb));
        $this->free = 7 * $mb;
        $this->total = 10 * $mb; // 사용률 30%
        $this->assertSame(201, $this->init($kim, 2 * $mb)->status, '여유 7MB ≥ 4MB');
        $second = $this->init($kim, 2 * $mb);
        $this->assertSame([507, 'insufficient_storage'], [$second->status, $second->decoded()['error']['code']], '진행 중 예약 4MB를 빼면 여유 3MB < 4MB');
        $msg = $second->decoded()['error']['message'];
        $this->assertContains('필요', $msg);
        $this->assertContains('여유', $msg);
        $this->assertSame(201, $this->init($this->as('lee'), 100 * 1024)->status, '작은 업로드(예약 1.1MB)는 들어감');

        // 사용률 90% 이상이면 차단(여유가 충분해도)
        $this->free = 1e11;
        $this->total = 1e12;
        $blocked = $this->init($this->as('park'), 10, $this->p2);
        $this->assertSame(404, $blocked->status, 'park은 p2를 볼 수 없음(접근 판정이 먼저)');
        $admin = $this->as('admin');
        $b = $this->init($admin, 10, $this->p2);
        $this->assertSame([507, 'disk_usage_blocked'], [$b->status, $b->decoded()['error']['code']]);
        $this->assertContains('90%', $b->decoded()['error']['message']);
        // 80%는 경고 로그만
        $this->free = 1.5e11;
        $this->assertSame(201, $this->init($admin, 10, $this->p2)->status);
        $log = (string) file_get_contents(glob($this->root . '/logs/*.log')[0]);
        $this->assertContains('디스크 사용률 경고 기준 초과', $log);
    }

    public function testCompleteWithMissingChunksAndAbort(): void
    {
        $kim = $this->as('kim');
        $data = random_bytes(self::CHUNK * 3);
        $id = $this->init($kim, strlen($data))->decoded()['data']['upload_id'];
        $this->put($kim, $id, 1, self::split($data)[1]);
        $r = $kim->post("/api/v1/uploads/{$id}/complete");
        $this->assertSame([409, 'chunks_missing'], [$r->status, $r->decoded()['error']['code']]);
        $this->assertContains('2개', $r->decoded()['error']['message']);

        $ab = $kim->request('DELETE', "/api/v1/uploads/{$id}");
        $this->assertSame([200, 'aborted'], [$ab->status, $ab->decoded()['data']['status']]);
        $this->assertFalse(is_dir($this->dir($id)), '조각 폴더 삭제');
        $row = $this->app->db()->one('SELECT status, reserved_bytes FROM uploads WHERE id = ?', [$id]);
        $this->assertSame(['aborted', 0], [$row['status'], (int) $row['reserved_bytes']]);
        $this->assertSame('upload_not_abortable', $kim->request('DELETE', "/api/v1/uploads/{$id}")->decoded()['error']['code']);
        $this->assertSame('upload_not_receiving', $this->put($kim, $id, 0, self::split($data)[0])->decoded()['error']['code']);
    }

    public function testOtherUsersGet404AndAccessRules(): void
    {
        $kim = $this->as('kim');
        $data = random_bytes(100);
        $id = $this->init($kim, 100)->decoded()['data']['upload_id'];
        $missing = str_repeat('0', 32);
        foreach (['lee' => '같은 프로젝트를 보는 다른 사용자', 'admin' => '관리자도 타인의 업로드를 이어 쓸 수 없음', 'park' => '프로젝트를 볼 수 없는 사용자'] as $who => $why) {
            $c = $this->as($who);
            $checks = [
                $c->get("/api/v1/uploads/{$id}"),
                $c->get("/api/v1/uploads/{$id}/status"),
                $this->put($c, $id, 0, $data),
                $c->post("/api/v1/uploads/{$id}/complete"),
                $c->request('DELETE', "/api/v1/uploads/{$id}"),
            ];
            foreach ($checks as $i => $res) {
                $this->assertSame([404, 'upload_not_found'], [$res->status, $res->decoded()['error']['code']], "{$who}({$why}) #{$i}");
            }
            $strip = static fn (array $d): array => [$d['error']['code'], $d['error']['message']];
            $this->assertSame($strip($c->get("/api/v1/uploads/{$missing}")->decoded()), $strip($c->get("/api/v1/uploads/{$id}")->decoded()), '없는 업로드와 같은 응답');
        }
        $this->assertSame([], glob($this->dir($id) . '/*.part') ?: [], '다른 사용자의 청크는 저장되지 않음');

        // 프로젝트 접근: 볼 수 있음(kim) 201, 볼 수 없음(park) 404, 없는 프로젝트 404, admin 201
        $this->assertSame(404, $this->init($this->as('park'), 10)->status);
        $nf = $this->init($kim, 10, 999999);
        $this->assertSame([404, 'project_not_found'], [$nf->status, $nf->decoded()['error']['code']]);
        $this->assertSame(404, $this->init($kim, 10, $this->p2)->status, '매핑 안 된 p2');
        $this->assertSame(201, $this->init($this->as('admin'), 10, $this->p2)->status, 'admin은 모든 프로젝트');

        // kim이 프로젝트 접근을 잃으면 자기 업로드도 404
        $this->app->db()->execute("DELETE FROM project_access WHERE subject_type = 'user' AND subject_id = (SELECT id FROM users WHERE login_id = 'kim')");
        $this->assertSame(404, $kim->get("/api/v1/uploads/{$id}")->status);
    }

    public function testLoginAndCsrfRequired(): void
    {
        $kim = $this->as('kim');
        $id = $this->init($kim, 100)->decoded()['data']['upload_id'];
        $anon = new TestClient($this->app);
        $this->assertSame(401, $anon->get("/api/v1/uploads/{$id}/status")->status);
        $anon->fetchCsrf(); // 로그인 전 세션의 CSRF 토큰은 있어도 로그인이 없으면 401
        $this->assertSame(401, $anon->raw('PUT', "/api/v1/uploads/{$id}/chunks/0", 'x', ['x-chunk-sha256' => hash('sha256', 'x')])->status);
        $this->assertSame(401, $anon->post("/api/v1/projects/{$this->p1}/uploads", ['file_name' => 'a.zip', 'total_size' => 10])->status);
        $noCsrf = [
            $kim->request('POST', "/api/v1/projects/{$this->p1}/uploads", [], ['file_name' => 'a.zip', 'total_size' => 10], null, [], false),
            $kim->raw('PUT', "/api/v1/uploads/{$id}/chunks/0", random_bytes(100), ['x-chunk-sha256' => str_repeat('a', 64)], false),
            $kim->request('POST', "/api/v1/uploads/{$id}/complete", [], null, null, [], false),
            $kim->request('DELETE', "/api/v1/uploads/{$id}", [], null, null, [], false),
        ];
        foreach ($noCsrf as $i => $res) {
            $this->assertSame([403, 'csrf_invalid'], [$res->status, $res->decoded()['error']['code']], "#{$i}");
        }
    }

    public function testResumeAfterSessionExpiryAndRelogin(): void
    {
        $kim = $this->as('kim');
        $data = random_bytes(self::CHUNK * 2);
        $id = $this->init($kim, strlen($data))->decoded()['data']['upload_id'];
        $this->put($kim, $id, 0, self::split($data)[0]);
        $this->app->clock->advance(31 * 60);
        $this->assertSame('session_expired', $kim->get("/api/v1/uploads/{$id}/status")->decoded()['error']['code']);
        $again = $this->as('kim');
        $st = $again->get("/api/v1/uploads/{$id}/status")->decoded()['data'];
        $this->assertSame([0], array_column($st['received'], 'index'), '다시 로그인한 같은 사용자는 이어 올리기');
        $this->assertSame(200, $this->put($again, $id, 1, self::split($data)[1])->status);
        $this->assertSame(202, $again->post("/api/v1/uploads/{$id}/complete")->status);
    }

    public function testChunkRequestsKeepSessionAlive(): void
    {
        $kim = $this->as('kim');
        $data = random_bytes(self::CHUNK * 3);
        $id = $this->init($kim, strlen($data))->decoded()['data']['upload_id'];
        foreach (self::split($data) as $n => $part) {
            $this->app->clock->advance(20 * 60); // 청크 사이 20분: 요청마다 활동이 갱신되므로 30분 무활동 만료가 오지 않음
            $this->assertSame(200, $this->put($kim, $id, $n, $part)->status, "청크 {$n}");
        }
    }

    public function testCleanupDeletesOnlyStaleIncompleteUploads(): void
    {
        $kim = $this->as('kim');
        $old = $this->init($kim, 100)->decoded()['data']['upload_id'];
        $this->put($kim, $old, 0, random_bytes(100));
        $this->app->clock->advance(25 * 3600);
        $kim = $this->as('kim');
        $fresh = $this->init($kim, 100)->decoded()['data']['upload_id'];
        // DB 행 없는 오래된 폴더, 이름 규칙 밖 폴더
        $orphan = str_repeat('ab', 16);
        mkdir($this->dir($orphan));
        touch($this->dir($orphan), $this->app->clock->now()->getTimestamp() - 30 * 3600);
        mkdir($this->root . '/uploads/keep-me');

        $dry = $this->app->cleanup()->purgeIncompleteUploads(true);
        $this->assertSame(1, $dry['expired_uploads']);
        $this->assertTrue(is_dir($this->dir($old)), 'dry-run은 지우지 않음');
        $r = $this->app->cleanup()->purgeIncompleteUploads();
        $this->assertSame(['expired_uploads' => 1, 'orphan_dirs' => 1], ['expired_uploads' => $r['expired_uploads'], 'orphan_dirs' => $r['orphan_dirs']]);
        $this->assertFalse(is_dir($this->dir($old)), '25시간 전 활동한 업로드 삭제');
        $this->assertTrue(is_dir($this->dir($fresh)), '방금 만든 업로드는 유지');
        $this->assertFalse(is_dir($this->dir($orphan)));
        $this->assertTrue(is_dir($this->root . '/uploads/keep-me'), '이름 규칙 밖 폴더는 건드리지 않음');
        $this->assertSame('aborted', $this->app->db()->value('SELECT status FROM uploads WHERE id = ?', [$old]));
    }

    public function testAssemblerStreamsWithSmallMemoryAndFailsSafely(): void
    {
        // 큰 청크(최대 2MB, 청크 256KB)로 조립 메모리가 청크·전체 크기와 무관하게 작은지
        $kim = $this->as('kim');
        $data = random_bytes(2 * 1024 * 1024);
        $id = $this->init($kim, strlen($data))->decoded()['data']['upload_id'];
        foreach (self::split($data) as $n => $part) {
            $this->put($kim, $id, $n, $part);
        }
        $kim->post("/api/v1/uploads/{$id}/complete");
        $before = memory_get_usage(true);
        $r = $this->app->uploadAssembler()->assembleNext();
        $this->assertSame(hash('sha256', $data), $r['sha256']);
        $this->assertTrue(memory_get_usage(true) - $before < 4 * 1024 * 1024, '조립 후 메모리 증가가 읽기 단위 수준');

        // 실패: 청크가 사라졌으면 failed(assemble_failed), 폴더 삭제, 예약 0
        $id2 = $this->init($kim, self::CHUNK * 2)->decoded()['data']['upload_id'];
        $d2 = random_bytes(self::CHUNK * 2);
        foreach (self::split($d2) as $n => $part) {
            $this->put($kim, $id2, $n, $part);
        }
        $kim->post("/api/v1/uploads/{$id2}/complete");
        unlink($this->dir($id2) . '/1.part');
        $f = $this->app->uploadAssembler()->assembleNext();
        $this->assertSame('failed', $f['status']);
        $info = $kim->get("/api/v1/uploads/{$id2}")->decoded()['data'];
        $this->assertSame('assemble_failed', $info['error_code']);
        $this->assertNotContains($this->root, (string) $info['error_message'], '내부 경로를 사용자 문구에 넣지 않음');
        $this->assertFalse(is_dir($this->dir($id2)));
        $this->assertSame(0, (int) $this->app->db()->value('SELECT reserved_bytes FROM uploads WHERE id = ?', [$id2]));
    }

    /** --big 옵션일 때만: 2GB 한 번(서비스 경로, 청크 8MB). 디스크에 시험 파일을 만들고 끝나면 지운다 */
    public function testBigTwoGigabytes(): void
    {
        if (getenv('PJG_TEST_BIG') !== '1') {
            return;
        }
        $root = $this->tempDir('upload_big');
        $app = TestApp::make($root, ['paths' => ['uploads_dir' => $root, 'logs_dir' => $root]]);
        $uid = TestDb::user($app->db(), 'bigu', 'admin');
        $pid = TestDb::project($app->db(), 'big');
        $user = ['id' => $uid, 'role' => 'admin', 'status' => 'active'];
        $size = 2 * 1024 * 1024 * 1024;
        $svc = $app->uploads();
        $init = $svc->init($user, $pid, ['file_name' => 'big.zip', 'total_size' => $size]);
        $chunk = $init['chunk_size'];
        $block = random_bytes(1024 * 1024);
        $ctx = hash_init('sha256');
        $t0 = microtime(true);
        memory_reset_peak_usage();
        for ($n = 0; $n < $init['total_chunks']; $n++) {
            $len = min($chunk, $size - $n * $chunk);
            $s = fopen('php://temp/maxmemory:0', 'w+b');
            $h = hash_init('sha256');
            for ($w = 0; $w < $len; $w += strlen($block)) {
                $piece = substr($block, 0, min(strlen($block), $len - $w));
                $piece[0] = chr($n & 0xFF);
                fwrite($s, $piece);
                hash_update($h, $piece);
                hash_update($ctx, $piece);
            }
            rewind($s);
            $svc->chunk($user, $init['upload_id'], (string) $n, hash_final($h), $s, $len);
            fclose($s);
        }
        $uploadSec = microtime(true) - $t0;
        $svc->complete($user, $init['upload_id']);
        $r = $app->uploadAssembler()->assembleNext();
        $this->assertSame(hash_final($ctx), $r['sha256']);
        fwrite(STDOUT, sprintf("      [2GB] 청크 %d개, 전송 %.1f초, 조립 %.1f초, 최대 메모리 %.1fMB\n", $init['total_chunks'], $uploadSec, $r['seconds'], memory_get_peak_usage(true) / 1048576));
        $this->assertTrue(memory_get_peak_usage(true) < 64 * 1024 * 1024, '최대 메모리가 청크 크기 수준');
        $app->uploadFiles()->remove($init['upload_id']);
    }
}
