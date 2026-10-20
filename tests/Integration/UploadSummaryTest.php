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
 * zip 업로드 확인(P21C 7절): 직전 업로드와 같은 파일 감지(같은 프로젝트·같은 사용자만) + 업로드 내용 요약 + 암호화 zip.
 * 사용자: kim·lee(py·js 프로젝트에 매핑).
 */
final class UploadSummaryTest extends TestCase
{
    private const ZIPS = PJG_ROOT . '/samples/zips';
    private App $app;
    private string $root;
    private int $py;
    private int $js;

    public function setUp(): void
    {
        $this->root = $this->tempDir('upsum');
        foreach (['uploads', 'work', 'logs'] as $d) {
            mkdir($this->root . '/' . $d);
        }
        $this->app = TestApp::make($this->root . '/logs', [
            'paths' => ['uploads_dir' => $this->root . '/uploads', 'work_dir' => $this->root . '/work', 'snapshots_dir' => $this->root],
            'upload' => ['chunk_size' => '256KB', 'max_zip_size' => '2MB'],
        ]);
        $db = $this->app->db();
        $adminId = TestDb::user($db, 'admin', 'admin');
        TestDb::user($db, 'kim', 'user');
        TestDb::user($db, 'lee', 'user');
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

    /** 업로드 + 조립 + 해제. GET /api/v1/uploads/{id}의 data */
    private function upload(TestClient $c, string $zip, int $project): array
    {
        $data = (string) file_get_contents($zip);
        $r = $c->post("/api/v1/projects/{$project}/uploads", ['file_name' => basename($zip), 'total_size' => strlen($data)]);
        $this->assertSame(201, $r->status, $r->body);
        $id = $r->decoded()['data']['upload_id'];
        foreach (str_split($data, 256 * 1024) as $n => $part) {
            $this->assertSame(200, $c->raw('PUT', "/api/v1/uploads/{$id}/chunks/{$n}", $part, ['x-chunk-sha256' => hash('sha256', $part)])->status);
        }
        $this->assertSame(202, $c->post("/api/v1/uploads/{$id}/complete")->status);
        $this->app->uploadAssembler()->assembleNext();
        $this->app->uploadProcessor()->processNext();
        return $c->get("/api/v1/uploads/{$id}")->decoded()['data'];
    }

    /** zip을 직접 열어 센 값(요약과 비교): 파일 수, 확장자별 .py 수 */
    private static function count(string $zip): array
    {
        $z = new \ZipArchive();
        $z->open($zip);
        $files = 0;
        $py = 0;
        for ($i = 0; $i < $z->numFiles; $i++) {
            $name = (string) $z->getNameIndex($i);
            if (str_ends_with($name, '/') || str_contains($name, '__pycache__')) {
                continue;
            }
            $files++;
            $py += str_ends_with(strtolower($name), '.py') ? 1 : 0;
        }
        $z->close();
        return [$files, $py];
    }

    public function testSameFileDetectedOnlyForSameUserAndProject(): void
    {
        $zip = self::ZIPS . '/python_sample.zip';
        $kim = $this->as('kim');
        $first = $this->upload($kim, $zip, $this->py);
        $this->assertSame('queued', $first['status'], '첫 업로드는 경고 없음');
        $this->assertSame(null, $first['summary']['previous'], '비교할 직전 기록 없음');
        [$files, $py] = self::count($zip);
        $s = $first['summary'];
        $this->assertSame([$files, $py, $py, 0], [$s['files'], $s['targets'], $s['targets_by_extension']['py'], $s['config_skipped']], '요약 = 직접 센 값');
        $this->assertTrue($s['bytes'] > 0);
        // 분석이 성공하면 업로드 폴더(extract.json)가 바로 지워져도 요약은 DB(uploads.summary, 014)에 남는다
        \PJGuardian\Support\Fs::removeTree($this->root . '/uploads/' . $first['upload_id']);
        $this->assertSame($s, $kim->get("/api/v1/uploads/{$first['upload_id']}")->decoded()['data']['summary']);

        // 같은 파일 다시 → 확인 대기 + "직전과 같음"
        $second = $this->upload($kim, $zip, $this->py);
        $this->assertSame('needs_confirmation', $second['status']);
        $this->assertSame(['same_as_previous'], $second['warnings']);
        $this->assertSame([true, 0, filesize($zip)], [$second['summary']['previous']['same'], $second['summary']['previous']['size_diff'], $second['summary']['previous']['size']]);
        $this->assertContains('직전 업로드', $second['warning_messages'][0]['message']);
        $this->assertSame(false, $second['warning_messages'][0]['danger'], '막지 않고 확인만');
        // 계속 진행 가능(같은 파일이라는 이유로 막지 않음)
        $this->assertSame(200, $kim->post("/api/v1/uploads/{$second['upload_id']}/confirm")->status);

        // 다른 사용자(lee)·다른 프로젝트(js)는 kim의 업로드를 쓰지 않는다
        $lee = $this->upload($this->as('lee'), $zip, $this->py);
        $this->assertSame(['queued', null], [$lee['status'], $lee['summary']['previous']]);
        $other = $this->upload($kim, self::ZIPS . '/js_sample.zip', $this->js);
        $this->assertSame(null, $other['summary']['previous']);

        // 다른 파일 → 경고 없음, 크기 차이
        $diff = $this->upload($kim, self::ZIPS . '/python_sample_toplevel.zip', $this->py);
        $this->assertNotContains('same_as_previous', json_encode($diff['warnings']));
        $this->assertSame(false, $diff['summary']['previous']['same']);
        $this->assertSame(filesize(self::ZIPS . '/python_sample_toplevel.zip') - filesize($zip), $diff['summary']['previous']['size_diff']);

        // 같은 파일이 확인 대기 중이면 취소할 수 있다(직전 = 분석 대기열에 들어간 업로드라 diff를 먼저 확인)
        $this->assertSame(200, $kim->post("/api/v1/uploads/{$diff['upload_id']}/confirm")->status);
        $again = $this->upload($kim, self::ZIPS . '/python_sample_toplevel.zip', $this->py);
        $this->assertContains('same_as_previous', json_encode($again['warnings']));
        $this->assertSame(200, $kim->request('DELETE', "/api/v1/uploads/{$again['upload_id']}")->status);
    }

    public function testSummaryForEmptyEncryptedAndJsConfig(): void
    {
        $kim = $this->as('kim');
        // 빈(대상 없음) zip: 열림 + 파일 0 + 위험 경고
        $empty = $this->upload($kim, self::ZIPS . '/empty_no_target.zip', $this->py);
        $this->assertSame('needs_confirmation', $empty['status']);
        $this->assertSame(0, $empty['summary']['targets']);
        // 암호화된 zip: 손상과 구분되는 사유
        $enc = $this->root . '/enc.zip';
        $z = new \ZipArchive();
        $z->open($enc, \ZipArchive::CREATE);
        $z->addFromString('a.py', "x = 1\n");
        $z->setEncryptionName('a.py', \ZipArchive::EM_AES_256, 'secret');
        $z->close();
        $e = $this->upload($kim, $enc, $this->py);
        $this->assertSame(['failed', 'invalid_zip'], [$e['status'], $e['error_code']]);
        $this->assertContains('암호화', (string) $e['error_message']);
        $this->assertSame(null, $e['summary']);
        // JS: 업로드한 ESLint 설정은 대상에서 빼고 따로 센다
        $js = $this->upload($kim, self::ZIPS . '/js_with_js_config.zip', $this->js);
        $this->assertTrue($js['summary']['config_skipped'] >= 1, json_encode($js['summary']));
        $this->assertSame($js['summary']['targets'], array_sum($js['summary']['targets_by_extension']));
    }

    public function testOldExtractJsonWithoutNewFields(): void
    {
        $kim = $this->as('kim');
        $u = $this->upload($kim, self::ZIPS . '/python_sample.zip', $this->py);
        // P21C 이전 업로드: DB 요약 없음 + 이전 형식의 extract.json(확장자별·직전 비교 없음)
        $this->app->db()->execute('UPDATE uploads SET summary = NULL WHERE id = ?', [$u['upload_id']]);
        $file = $this->root . '/uploads/' . $u['upload_id'] . '/extract.json';
        file_put_contents($file, json_encode(['strip_rule' => true, 'top_folder' => 'x', 'stripped_prefix' => 'x/', 'files' => 3, 'bytes' => 10, 'skipped' => 1, 'targets' => 2]));
        $s = $kim->get("/api/v1/uploads/{$u['upload_id']}")->decoded()['data']['summary'];
        $this->assertSame([3, 10, 1, 2, null, null, true], [$s['files'], $s['bytes'], $s['excluded'], $s['targets'], $s['targets_by_extension'], $s['previous'], $s['top_folder_removed']]);
        $this->assertNotContains('"x/"', json_encode($s), '폴더 이름 같은 내부 값은 넣지 않음');
        // 다른 사용자는 남의 업로드를 볼 수 없다(기존 규칙, 404)
        $this->assertSame(404, $this->as('lee')->get("/api/v1/uploads/{$u['upload_id']}")->status);
    }
}
