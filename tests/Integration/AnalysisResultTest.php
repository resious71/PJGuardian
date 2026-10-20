<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Actor;
use PJGuardian\Support\ErrorCatalog;
use PJGuardian\Support\Lang;
use PJGuardian\Tests\Support\DefectHarness;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 분석 상태·결과·실패 화면과 오류 카탈로그(명세 5.2, 5.3, 8, 15.2, P19).
 * 접근: kim(매핑, 요청자), lee(매핑), park(매핑 없음 → 404), admin.
 */
final class AnalysisResultTest extends TestCase
{
    private App $app;
    private int $py;
    /** @var array<string, int> */
    private array $u = [];

    public function setUp(): void
    {
        $this->app = TestApp::make($this->tempDir('anres'));
        $db = $this->app->db();
        foreach (['admin' => 'admin', 'kim' => 'user', 'lee' => 'user', 'park' => 'user'] as $l => $r) {
            $this->u[$l] = TestDb::user($db, $l, $r);
        }
        $this->py = TestDb::project($db, 'py', 'python');
        $actor = Actor::fromUser(['id' => $this->u['admin'], 'login_id' => 'admin'], null);
        foreach (['kim', 'lee'] as $l) {
            $this->app->projectAccess()->add($actor, $this->py, ['subject_type' => 'user', 'login_id' => $l]);
        }
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status, $login);
        return $c;
    }

    // ---- 오류 카탈로그 ----

    public function testCatalogHasCauseActionGuideForEveryCodeAndDocsListThem(): void
    {
        $doc = (string) file_get_contents(PJG_ROOT . '/docs/error_codes.md');
        foreach (['upload' => ErrorCatalog::UPLOAD, 'analysis' => ErrorCatalog::ANALYSIS] as $kind => $codes) {
            foreach ($codes as $code) {
                foreach (['title', 'cause', 'action'] as $part) {
                    $this->assertTrue(Lang::has("failure.{$kind}.{$code}.{$part}"), "failure.{$kind}.{$code}.{$part}");
                }
                $d = ErrorCatalog::describe($kind, $code);
                $this->assertSame([$code, true], [$d['code'], $d['known']]);
                $this->assertContains("`{$code}`", $doc, "docs/error_codes.md에 {$kind} {$code}");
                $this->assertNotContains('\\', $d['cause'] . $d['action'], '경로 같은 내부 정보 없음');
                // P29(의도된 단언 변경): 가이드 키는 문구 키(upload.error.<코드>)에서 사용자 가이드 절 key로 — 절이 ko·en에 있는지는 GuideCatalogTest
                $this->assertSame(ErrorCatalog::GUIDE_SECTION[$kind], $d['guide_key'], "{$kind} 가이드 절");
            }
            $unknown = ErrorCatalog::describe($kind, 'mystery_code');
            $this->assertSame([false, null], [$unknown['known'], $unknown['guide_key']]);
            $this->assertContains('mystery_code', $unknown['cause']);
        }
        // 분석 실패 코드는 한 문장 문구(analyses.failure_message 저장용)도 있다
        foreach (ErrorCatalog::ANALYSIS as $code) {
            $this->assertTrue(Lang::has("analysis.failure.{$code}"), "analysis.failure.{$code}");
        }
        foreach (ErrorCatalog::UPLOAD as $code) {
            $this->assertTrue(Lang::has("error.{$code}"), "error.{$code}");
        }
    }

    /** 코드에서 실제로 내는 분석 실패 코드는 모두 카탈로그에 있다(새 코드를 만들면 카탈로그·문서·문구를 함께) */
    public function testEveryFailureCodeUsedInSourceIsCatalogued(): void
    {
        $found = [];
        // 분석 실패를 만드는 곳(실행기·워커·반영). zip 해제(Archive)의 $fail('사유')는 업로드 오류의 세부 사유라 대상이 아니다
        $files = [];
        foreach (['Services/Tools', 'Services/Queue', 'Services/Analysis', 'Services/Defects'] as $dir) {
            $files = [...$files, ...iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(PJG_ROOT . '/src/' . $dir, \FilesystemIterator::SKIP_DOTS)), false)];
        }
        foreach ($files as $f) {
            if ($f->getExtension() !== 'php') {
                continue;
            }
            preg_match_all("/AnalysisOutcome::failed\\('([a-z_]+)'|->fail\\(\\s*\\\$[a-zA-Z]+,\\s*'([a-z_]+)'|\\\$fail\\('([a-z_]+)'/", (string) file_get_contents($f->getPathname()), $m);
            foreach (array_merge($m[1], $m[2], $m[3]) as $c) {
                if ($c !== '') {
                    $found[$c] = true;
                }
            }
        }
        foreach (['timeout', 'output_limit', 'memory_limit', 'tool_config_error', 'worker_crashed', 'worker_stopped', 'analysis_crashed', 'analysis_start_failed'] as $c) {
            $found[$c] = true; // 실행기·워커가 상수·변수로 넘기는 코드(ProcessResult 사유, WorkerRecovery 등)
        }
        $this->assertSame([], array_values(array_diff(array_keys($found), ErrorCatalog::ANALYSIS)), '카탈로그에 없는 분석 실패 코드');
    }

    // ---- 분석 API(P19 필드) ----

    public function testAnalysisPayloadHasResultFailureNoticesAndCancelPermission(): void
    {
        $db = $this->app->db();
        $first = TestDb::analysis($db, $this->py, $this->u['kim'], [
            'started_at' => '2026-10-02T11:00:00Z', 'finished_at' => '2026-10-02T11:01:05Z', 'total_count' => 7, 'new_count' => 7,
            'severity_counts' => json_encode(['critical' => 0, 'high' => 1, 'medium' => 0, 'low' => 0, 'not_defined' => 6]),
            'ignored_configs' => json_encode([['file' => '.eslintrc.js', 'reason' => 'eslint_config_ignored'], ['file' => '.eslintignore', 'reason' => 'eslintignore_converted', 'count' => 2]]),
        ]);
        $second = TestDb::analysis($db, $this->py, $this->u['kim'], ['baseline_analysis_id' => $first, 'total_count' => 5, 'new_count' => 1, 'resolved_count' => 3, 'unchanged_count' => 4]);
        $failed = TestDb::analysis($db, $this->py, $this->u['kim'], ['status' => 'failed', 'failure_code' => 'tool_config_error', 'failure_message' => 'x', 'total_count' => null, 'new_count' => null, 'resolved_count' => null, 'reopened_count' => null, 'unchanged_count' => null]);
        $queued = TestDb::analysis($db, $this->py, $this->u['kim'], ['status' => 'queued']);
        $kim = $this->as('kim');
        $a = $kim->get("/api/v1/analyses/{$first}")->decoded()['data'];
        $this->assertSame([true, null, 65, ['critical' => 0, 'high' => 1, 'medium' => 0, 'low' => 0, 'not_defined' => 6]],
            [$a['first_analysis'], $a['baseline'], $a['duration_seconds'], $a['severity_counts']], '최초 분석 = 비교 대상 없음, 소요 65초');
        $this->assertSame(['eslint_config_ignored', 'eslintignore_converted'], array_column($a['ignored_configs'], 'reason'));
        $b = $kim->get("/api/v1/analyses/{$second}")->decoded()['data'];
        $this->assertSame([false, $first], [$b['first_analysis'], $b['baseline']['id']], '비교 대상 = 직전 성공 분석');
        $f = $kim->get("/api/v1/analyses/{$failed}")->decoded()['data'];
        // P29(의도된 단언 변경): 가이드 키 = 사용자 가이드 절 key
        $this->assertSame(['tool_config_error', true, 'analysis-failures', Lang::get('failure.analysis.tool_config_error.action')],
            [$f['failure']['code'], $f['failure']['known'], $f['failure']['guide_key'], $f['failure']['action']]);
        $this->assertSame([null, null], [$f['counts'], $f['severity_counts']]);
        // 취소 버튼: queued·running이고 요청자 본인 또는 admin
        $this->assertSame(true, $kim->get("/api/v1/analyses/{$queued}")->decoded()['data']['can_cancel']);
        $this->assertSame(false, $this->as('lee')->get("/api/v1/analyses/{$queued}")->decoded()['data']['can_cancel'], '다른 사용자');
        $this->assertSame(true, $this->as('admin')->get("/api/v1/analyses/{$queued}")->decoded()['data']['can_cancel'], 'admin');
        $this->assertSame(false, $kim->get("/api/v1/analyses/{$second}")->decoded()['data']['can_cancel'], '끝난 분석');
        $list = $kim->get("/api/v1/projects/{$this->py}/analyses")->decoded()['data'];
        $this->assertSame([true, false, false, false], array_column($list, 'can_cancel'), '목록도 같은 판정(최신순)');
    }

    public function testAnalysisPageAndProjectTabsAndAccess(): void
    {
        $db = $this->app->db();
        $a = TestDb::analysis($db, $this->py, $this->u['kim'], ['status' => 'failed', 'failure_code' => 'timeout', 'failure_message' => 'x',
            'total_count' => null, 'new_count' => null, 'resolved_count' => null, 'reopened_count' => null, 'unchanged_count' => null]);
        $kim = $this->as('kim');
        $page = $kim->get("/analyses/{$a}");
        $this->assertSame(200, $page->status);
        $this->assertContains('data-analysis-id="' . $a . '"', $page->body);
        $this->assertContains('js/pages/analysis_detail.js', $page->body);
        $hidden = $this->as('park')->get("/analyses/{$a}");
        $missing = $kim->get('/analyses/999999');
        $this->assertSame([404, 404], [$hidden->status, $missing->status], '보이지 않는 프로젝트의 분석 = 없는 분석');
        $this->assertSame(200, $this->as('admin')->get("/analyses/{$a}")->status);
        $proj = $kim->get("/projects/{$this->py}")->body;
        $this->assertContains('id="tab-analyses"', $proj, '"분석" 탭');
        $this->assertContains('data-analyses data-project-id="' . $this->py . '"', $proj);
        $this->assertContains(Lang::get('projects.summary_latest'), $proj, '최근 분석 카드');
        $this->assertContains('/analyses/' . $a, $proj);
    }

    public function testDefectListFilterByAnalysisChange(): void
    {
        $db = $this->app->db();
        $a1 = TestDb::analysis($db, $this->py, $this->u['kim']);
        $a2 = TestDb::analysis($db, $this->py, $this->u['kim'], ['baseline_analysis_id' => $a1]);
        $old = TestDb::defect($db, $this->py, $a1, ['last_seen_analysis_id' => $a2]);
        $new = TestDb::defect($db, $this->py, $a2);
        $gone = TestDb::defect($db, $this->py, $a1, ['status' => 'resolved']);
        TestDb::autoHistory($db, $gone, $a2, 'open', 'resolved', 'file_deleted');
        $back = TestDb::defect($db, $this->py, $a1, ['last_seen_analysis_id' => $a2]);
        TestDb::autoHistory($db, $back, $a2, 'resolved', 'open', 'redetected');
        $kim = $this->as('kim');
        $ids = fn (array $q): array => array_column($kim->get("/api/v1/projects/{$this->py}/defects", $q)->decoded()['data'], 'id');
        $this->assertSame([$new], $ids(['analysis' => (string) $a2, 'change' => 'new']));
        $this->assertSame([$gone], $ids(['analysis' => (string) $a2, 'change' => 'resolved']));
        $this->assertSame([$back], $ids(['analysis' => (string) $a2, 'change' => 'reopened']));
        $this->assertSame([$old, $gone, $back], $ids(['analysis' => (string) $a1, 'change' => 'new']));
        foreach ([['analysis' => (string) $a2], ['change' => 'new'], ['analysis' => 'x', 'change' => 'new'], ['analysis' => (string) $a2, 'change' => 'gone']] as $bad) {
            $this->assertSame(400, $kim->get("/api/v1/projects/{$this->py}/defects", $bad)->status, json_encode($bad));
        }
        $this->assertSame(1, $kim->get("/api/v1/projects/{$this->py}/defects/summary", ['analysis' => (string) $a2, 'change' => 'new'])->decoded()['data']['total']);
    }

    public function testUploadErrorCarriesCatalog(): void
    {
        $db = $this->app->db();
        $id = str_repeat('a', 32);
        $db->execute("INSERT INTO uploads (id, user_id, project_id, file_name, total_size, chunk_size, total_chunks, status, reserved_bytes, error_code, error_message, created_at, last_activity_at)"
            . " VALUES (?, ?, ?, 'x.zip', 10, 8388608, 1, 'failed', 0, 'forbidden_entry', '금지 항목: ../evil', ?, ?)", [$id, $this->u['kim'], $this->py, TestDb::T0, TestDb::T0]);
        $u = $this->as('kim')->get("/api/v1/uploads/{$id}")->decoded()['data'];
        // P29(의도된 단언 변경): 가이드 키 = 사용자 가이드 절 key
        $this->assertSame(['forbidden_entry', 'upload-errors', Lang::get('failure.upload.forbidden_entry.action')],
            [$u['error']['code'], $u['error']['guide_key'], $u['error']['action']]);
        $this->assertSame('금지 항목: ../evil', $u['error_message'], '항목 이름이 붙은 한 문장은 그대로');
    }

    /** "변경 없음"(no_change) 분석은 비교 기준이 되지 않는다(명세 18.2, 19.10; 감사에서 발견한 DefectStore 결함, 회귀 시험) */
    public function testNoChangeAnalysisIsNeverBaseline(): void
    {
        $h = new DefectHarness($this->tempDir('nochg'));
        if ($h->tool('ruff') === null) {
            echo "  (건너뜀: bin/ruff 없음)\n";
            return;
        }
        $db = $h->app->db();
        $pid = $h->project('nc', 'python');
        $dir = $h->copySample('python_sample');
        $h->analyze($pid, 'ruff', $dir);
        $first = (int) $db->value('SELECT MAX(id) FROM analyses WHERE project_id = ?', [$pid]);
        // 저장소 연동(P25)의 "변경 없음": 성공으로 기록하지만 통계가 없다
        $nc = TestDb::analysis($db, $pid, 1, ['no_change' => 1, 'total_count' => null, 'new_count' => null, 'resolved_count' => null, 'reopened_count' => null, 'unchanged_count' => null]);
        $h->analyze($pid, 'ruff', $dir);
        $third = (int) $db->value('SELECT MAX(id) FROM analyses WHERE project_id = ?', [$pid]);
        $this->assertTrue($third > $nc);
        $this->assertSame($first, (int) $db->value('SELECT baseline_analysis_id FROM analyses WHERE id = ?', [$third]), '비교 기준은 변경 없음 분석이 아니라 직전 실제 분석');
    }
}
