<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Http\ApiException;
use PJGuardian\Services\Actor;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 프로젝트 관리(명세 4장, 13장, 19.10): 생성·수정(언어 변경 불가)·삭제(이름 확인, 연쇄 삭제, 파일 정리)·제외 패턴.
 * 파일 경로(스냅샷·업로드·작업 디렉토리)는 시험용 임시 폴더로 바꾼다.
 */
final class ProjectServiceTest extends TestCase
{
    private string $root;
    private App $app;
    private int $adminId;
    private Actor $admin;

    public function setUp(): void
    {
        $this->root = $this->tempDir('projects');
        foreach (['snapshots', 'uploads', 'work', 'logs'] as $d) {
            mkdir($this->root . '/' . $d);
        }
        $this->app = TestApp::make($this->root . '/logs', ['paths' => [
            'snapshots_dir' => $this->root . '/snapshots',
            'uploads_dir' => $this->root . '/uploads',
            'work_dir' => $this->root . '/work',
        ]]);
        $this->adminId = TestDb::user($this->app->db(), 'admin', 'admin');
        $this->admin = Actor::fromUser(['id' => $this->adminId, 'login_id' => 'admin'], '10.0.0.1');
    }

    private function create(string $name = 'demo', string $language = 'python', string $description = ''): array
    {
        return $this->app->projects()->create($this->admin, ['name' => $name, 'language' => $language, 'description' => $description]);
    }

    private function count(string $table, string $where = '1', array $params = []): int
    {
        return (int) $this->app->db()->value("SELECT COUNT(*) FROM {$table} WHERE {$where}", $params);
    }

    // ---- 생성 ----

    public function testCreateTrimsAndValidates(): void
    {
        $p = $this->create('  웹 서비스  ', 'javascript', '  설명  ');
        $this->assertSame('웹 서비스', $p['name'], '앞뒤 공백 제거');
        $this->assertSame('설명', $p['description']);
        $this->assertSame('javascript', $p['language']);
        $this->assertSame([], $p['exclude_patterns']);

        $cases = [
            [['name' => '웹 서비스', 'language' => 'python'], 'project_name_taken', 409],
            [['name' => '   ', 'language' => 'python'], 'invalid_project_name', 400],
            [['name' => str_repeat('가', 101), 'language' => 'python'], 'invalid_project_name', 400],
            [['name' => "a\nb", 'language' => 'python'], 'invalid_project_name', 400],
            [['name' => 'x', 'language' => 'java'], 'invalid_language', 400],
            [['name' => 'y'], 'invalid_language', 400],
            [['name' => 'z', 'language' => 'python', 'description' => str_repeat('a', 2001)], 'invalid_description', 400],
        ];
        foreach ($cases as [$data, $code, $status]) {
            $e =$this->assertThrows(ApiException::class, fn () => $this->app->projects()->create($this->admin, $data));
            $this->assertSame([$code, $status], [$e->errorCode, $e->status], json_encode($data, JSON_UNESCAPED_UNICODE));
        }
        $e = $this->assertThrows(ApiException::class, fn () => $this->create('웹 서비스 ', 'python'));
        $this->assertSame('project_name_taken', $e->errorCode, '공백 제거 후 중복');
    }

    public function testNameUniquenessIgnoresCase(): void
    {
        $this->create('Demo');
        $e = $this->assertThrows(ApiException::class, fn () => $this->create('demo'));
        $this->assertSame('project_name_taken', $e->errorCode);
    }

    // ---- 수정 ----

    public function testUpdateNameDescriptionAndPatterns(): void
    {
        $p = $this->create('demo');
        $u = $this->app->projects()->update($this->admin, (int) $p['id'], [
            'name' => ' demo2 ', 'description' => '새 설명', 'exclude_patterns' => ['*.log', '  /docs/**  ', '', '*.log'],
        ]);
        $this->assertSame('demo2', $u['name']);
        $this->assertSame('새 설명', $u['description']);
        $this->assertSame(['*.log', '/docs/**'], $u['exclude_patterns'], '공백 제거, 빈 줄 무시, 중복 제거');
    }

    public function testLanguageCannotBeChanged(): void
    {
        $p = $this->create('demo', 'python');
        $e = $this->assertThrows(ApiException::class, fn () => $this->app->projects()->update($this->admin, (int) $p['id'], ['language' => 'javascript']));
        $this->assertSame(['language_immutable', 409], [$e->errorCode, $e->status]);
        $same = $this->app->projects()->update($this->admin, (int) $p['id'], ['language' => 'python', 'description' => 'x']);
        $this->assertSame('python', $same['language'], '같은 값은 허용(무시)');
        // DB 트리거도 막는다
        $e = $this->assertThrows(\PDOException::class, fn () => $this->app->db()->execute("UPDATE projects SET language = 'javascript' WHERE id = ?", [$p['id']]));
        $this->assertContains('language', $e->getMessage());
    }

    public function testInvalidPatternsAreRejectedOnSave(): void
    {
        $p = $this->create('demo');
        $e = $this->assertThrows(ApiException::class, fn () => $this->app->projects()->update($this->admin, (int) $p['id'], ['exclude_patterns' => ['ok/*', '../etc']]));
        $this->assertSame(['invalid_exclude_pattern', 400], [$e->errorCode, $e->status]);
        $this->assertSame('../etc', $e->params['pattern']);
        $e = $this->assertThrows(ApiException::class, fn () => $this->app->projects()->update($this->admin, (int) $p['id'], ['exclude_patterns' => array_map(static fn ($i) => "p{$i}", range(1, 101))]));
        $this->assertSame('too_many_exclude_patterns', $e->errorCode);
        $e = $this->assertThrows(ApiException::class, fn () => $this->app->projects()->update($this->admin, (int) $p['id'], ['exclude_patterns' => 'a,b']));
        $this->assertSame('invalid_input', $e->errorCode);
        $this->assertSame([], $this->app->projects()->get((int) $p['id'], true)['exclude_patterns'], '실패하면 바뀌지 않음');
    }

    // ---- 조회 ----

    public function testDetailIncludesExcludesAndHidesStripRuleFromUsers(): void
    {
        $p = $this->create('demo');
        $this->app->projects()->update($this->admin, (int) $p['id'], ['exclude_patterns' => ['*.log']]);
        $admin = $this->app->projects()->get((int) $p['id'], true);
        $this->assertSame(['node_modules', '.venv', 'venv', '__pycache__', '.git', 'dist', 'build', '__MACOSX', '.DS_Store'], $admin['default_excludes']);
        $this->assertSame(['*.log'], $admin['exclude_patterns']);
        $this->assertSame(null, $admin['latest_analysis'], '분석 없음 → 빈 값');
        $this->assertTrue(array_key_exists('strip_top_folder', $admin), '관리자에게만');
        $user = $this->app->projects()->get((int) $p['id'], false);
        $this->assertFalse(array_key_exists('strip_top_folder', $user));
        $e = $this->assertThrows(ApiException::class, fn () => $this->app->projects()->get(9999, true));
        $this->assertSame('project_not_found', $e->errorCode);

        $m = $this->app->projects()->matcherFor((int) $p['id']);
        $this->assertTrue($m->matches('logs/a.log'));
        $this->assertTrue($m->matches('node_modules/x.js'));
        $this->assertFalse($m->matches('src/a.py'));
    }

    public function testLatestAnalysisSummary(): void
    {
        $pid = (int) $this->create('demo')['id'];
        TestDb::analysis($this->app->db(), $pid, $this->adminId, ['status' => 'succeeded', 'total_count' => 7]);
        TestDb::analysis($this->app->db(), $pid, $this->adminId, ['status' => 'queued']);
        $latest = $this->app->projects()->get($pid, false)['latest_analysis'];
        $this->assertSame('queued', $latest['status'], '가장 최근 분석');
        $this->assertSame(7, $this->app->projects()->get($pid, false)['latest_success']['total_count']);
    }

    // ---- 삭제 ----

    /** 결함·이력·분석·발췌·스냅샷·파일 목록·업로드 행과 파일을 만든다 */
    private function seed(int $pid): array
    {
        $db = $this->app->db();
        $a1 = TestDb::analysis($db, $pid, $this->adminId);
        $a2 = TestDb::analysis($db, $pid, $this->adminId);
        $d1 = TestDb::defect($db, $pid, $a1);
        $d2 = TestDb::defect($db, $pid, $a2, ['ordinal' => 1]);
        TestDb::autoHistory($db, $d1, $a2, 'open', 'resolved', 'not_detected');
        $db->execute("INSERT INTO defect_excerpts (defect_id, analysis_id, file_path, start_line, end_line, content, created_at) VALUES (?, ?, 'src/a.py', 1, 3, 'x', ?)", [$d1, $a1, TestDb::T0]);
        $db->execute("INSERT INTO analysis_file_lists (analysis_id, exclude_rules, file_count, files_blob, created_at) VALUES (?, '{}', 1, ?, ?)", [$a1, gzcompress('src/a.py'), TestDb::T0]);
        foreach ([$a1 => 1000, $a2 => 2345] as $aid => $size) {
            $rel = "{$pid}/{$aid}";
            mkdir($this->root . "/snapshots/{$rel}", 0777, true);
            file_put_contents($this->root . "/snapshots/{$rel}/snap.zip", str_repeat('x', 10));
            $db->execute("INSERT INTO snapshots (analysis_id, storage_path, size_bytes, file_count, created_at) VALUES (?, ?, ?, 1, ?)", [$aid, $rel, $size, TestDb::T0]);
        }
        $uid = str_repeat('a', 31) . (string) ($pid % 10);
        mkdir($this->root . "/uploads/{$uid}");
        file_put_contents($this->root . "/uploads/{$uid}/0.part", 'x');
        // 작업 디렉토리 이름은 프로젝트마다 다르게(한 시험에서 두 프로젝트를 시드해도 같은 폴더를 다시 만들지 않게)
        $work = sprintf('0000ab%02x', $pid % 256);
        mkdir($this->root . "/work/{$work}");
        file_put_contents($this->root . "/work/{$work}/main.py", 'x');
        $db->execute(
            "INSERT INTO uploads (id, user_id, project_id, file_name, total_size, chunk_size, total_chunks, status, error_code, work_dir, last_activity_at, created_at)"
            . " VALUES (?, ?, ?, 'a.zip', 10, 10, 1, 'failed', 'test', ?, ?, ?)",
            [$uid, $this->adminId, $pid, $work, TestDb::T0, TestDb::T0],
        );
        return ['analyses' => [$a1, $a2], 'defects' => [$d1, $d2], 'upload' => $uid];
    }

    public function testDeletionInfoCountsRealRows(): void
    {
        $pid = (int) $this->create('demo')['id'];
        $empty = $this->app->projects()->deletionInfo($pid);
        $this->assertSame(['analyses' => 0, 'defects' => 0, 'snapshot_bytes' => 0, 'uploads' => 0], $empty, '빈 테이블에서도 쿼리 동작');
        $this->seed($pid);
        $other = (int) $this->create('other')['id'];
        $this->seed($other);
        $this->assertSame(['analyses' => 2, 'defects' => 2, 'snapshot_bytes' => 3345, 'uploads' => 1], $this->app->projects()->deletionInfo($pid), '다른 프로젝트 행은 세지 않음');
    }

    public function testDeleteRequiresExactName(): void
    {
        $pid = (int) $this->create('Demo')['id'];
        foreach (['demo', 'Demo ', '', 'Dem'] as $wrong) {
            $e = $this->assertThrows(ApiException::class, fn () => $this->app->projects()->delete($this->admin, $pid, $wrong));
            $this->assertSame('project_name_mismatch', $e->errorCode, json_encode($wrong));
        }
        $this->assertSame(1, $this->count('projects'));
    }

    public function testDeleteRefusedWhileAnalysisQueuedOrRunning(): void
    {
        $pid = (int) $this->create('demo')['id'];
        foreach (['queued', 'running'] as $status) {
            $aid = TestDb::analysis($this->app->db(), $pid, $this->adminId, ['status' => $status]);
            $e = $this->assertThrows(ApiException::class, fn () => $this->app->projects()->delete($this->admin, $pid, 'demo'));
            $this->assertSame(['project_busy', 409], [$e->errorCode, $e->status], $status);
            $this->app->db()->execute("UPDATE analyses SET status = 'cancelled', finished_at = ? WHERE id = ?", [TestDb::T0, $aid]);
        }
        $this->app->projects()->delete($this->admin, $pid, 'demo');
        $this->assertSame(0, $this->count('projects'));
    }

    public function testDeleteCascadesRowsAndRemovesFiles(): void
    {
        $pid = (int) $this->create('demo')['id'];
        $seed = $this->seed($pid);
        $keep = (int) $this->create('keep')['id'];
        $this->seed($keep);

        $r = $this->app->projects()->delete($this->admin, $pid, 'demo');
        $this->assertSame(0, $r['file_cleanup_failures']);

        $in = static fn (array $ids): string => implode(',', array_map('intval', $ids));
        $this->assertSame(0, $this->count('projects', 'id = ?', [$pid]));
        $this->assertSame(0, $this->count('analyses', 'project_id = ?', [$pid]));
        $this->assertSame(0, $this->count('defects', 'project_id = ?', [$pid]));
        $this->assertSame(0, $this->count('defect_history', 'defect_id IN (' . $in($seed['defects']) . ')'));
        $this->assertSame(0, $this->count('defect_excerpts', 'defect_id IN (' . $in($seed['defects']) . ')'));
        $this->assertSame(0, $this->count('snapshots', 'analysis_id IN (' . $in($seed['analyses']) . ')'));
        $this->assertSame(0, $this->count('analysis_file_lists', 'analysis_id IN (' . $in($seed['analyses']) . ')'));
        $this->assertSame(0, $this->count('uploads', 'project_id = ?', [$pid]));
        $this->assertFalse(is_dir($this->root . "/snapshots/{$pid}"), '스냅샷 폴더 삭제');
        $this->assertFalse(is_dir($this->root . "/uploads/{$seed['upload']}"), '업로드 조각 삭제');

        // 다른 프로젝트와 사용자는 그대로
        $this->assertSame(1, $this->count('projects', 'id = ?', [$keep]));
        $this->assertSame(2, $this->count('analyses', 'project_id = ?', [$keep]));
        $this->assertSame(2, $this->count('defects', 'project_id = ?', [$keep]));
        $this->assertTrue(is_dir($this->root . "/snapshots/{$keep}"));
        $this->assertSame(1, $this->count('users'));

        $audit = $this->app->db()->one("SELECT details FROM audit_log WHERE action = 'project.delete'");
        $this->assertSame(['name' => 'demo', 'language' => 'python', 'analyses' => 2, 'defects' => 2, 'snapshot_bytes' => 3345], json_decode((string) $audit['details'], true));
    }

    public function testFileCleanupFailureIsLoggedAndQueuedButDeleteSucceeds(): void
    {
        $pid = (int) $this->create('demo')['id'];
        $this->seed($pid);
        $this->app->projects()->setRemover(static fn (string $path): bool => false); // 모든 파일 삭제 실패를 흉내
        $r = $this->app->projects()->delete($this->admin, $pid, 'demo');
        $this->assertTrue($r['file_cleanup_failures'] > 0);
        $this->assertSame(0, $this->count('projects', 'id = ?', [$pid]), 'DB 삭제는 성공');
        $queue = (string) file_get_contents($this->root . '/work/cleanup_pending.jsonl');
        $this->assertContains('"reason":"project_delete"', $queue);
        $this->assertContains('snapshots', str_replace('\\', '/', $queue));
        $this->assertContains('정리하지 못한 파일', (string) file_get_contents(glob($this->root . '/logs/app-*.log')[0]));
    }

    public function testAuditForCreateAndUpdate(): void
    {
        $p = $this->create('demo', 'python', 'd');
        $this->app->projects()->update($this->admin, (int) $p['id'], ['name' => 'demo2', 'exclude_patterns' => ['*.log']]);
        $rows = $this->app->db()->all("SELECT action, target_type, target_id, details FROM audit_log WHERE action LIKE 'project.%' ORDER BY id");
        $this->assertSame(['project.create', 'project.update'], array_column($rows, 'action'));
        $this->assertSame(['name' => 'demo', 'language' => 'python'], json_decode((string) $rows[0]['details'], true));
        $this->assertSame([
            'name' => ['from' => 'demo', 'to' => 'demo2'],
            'exclude_patterns' => ['from' => [], 'to' => ['*.log']],
        ], json_decode((string) $rows[1]['details'], true));
        $this->assertSame(true, $this->app->audit()->verify()['ok']);
    }
}
