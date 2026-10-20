<?php

declare(strict_types=1);

namespace PJGuardian\Services\Upload;

use PJGuardian\Http\ApiException;
use PJGuardian\Services\Archive\SafeUnzip;
use PJGuardian\Services\Archive\TargetScanner;
use PJGuardian\Services\Archive\UnzipException;
use PJGuardian\Services\Archive\UnzipLimits;
use PJGuardian\Services\ProjectService;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\Fs;
use PJGuardian\Support\Lang;
use PJGuardian\Support\Logger;
use PJGuardian\Support\Paths;
use PJGuardian\Support\SystemClock;
use PJGuardian\Support\Trash;

/**
 * 업로드 후처리(명세 5.4, 19.10): 조립된 zip(extracting) → 안전 해제 → 사전 검사 → (경고 확인) → 분석 큐 등록.
 * 워커 연결은 P14(그 전에는 tools/process_upload.php가 조립 다음에 부른다). 시스템 작업이라 접근 통제 대상이 아니다.
 *
 * - 가져가기: extracting이고 work_dir가 비어 있는 업로드 하나를 조건부 UPDATE(work_dir 기록)로 한 워커만 가져간다.
 * - 해제: SafeUnzip(프로젝트의 ExcludeMatcher, 최상위 폴더 규칙). 실패하면 failed(오류 코드·사용자 문구), 작업 디렉토리 삭제,
 *   zip은 보관(정리 작업이 upload.failed_zip_retention(1일) 뒤 삭제).
 * - 사전 검사: 대상 파일 0개면 no_target. 해제 경고(top_folder_missing·top_folder_kept)와 합친다.
 * - 경고 없음 → enqueue: analyses(queued) 생성, 업로드 queued(예약 0), 프로젝트 strip_top_folder가 NULL이면 이때 확정.
 * - 경고 있음 → needs_confirmation(경고 저장, 작업 디렉토리 보존). 업로드한 사용자가 confirm하면 enqueue,
 *   취소(DELETE)하면 모두 삭제. 확인 대기는 upload.confirmation_retention(24시간, 가정) 뒤 정리 작업이 취소한다.
 * - 해제 결과 요약(최상위 폴더 규칙 등)은 uploads/<id>/extract.json에 둔다(확인 뒤 enqueue가 쓴다).
 */
final class UploadProcessor
{
    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly Paths $paths,
        private readonly UploadFiles $files,
        private readonly UploadSettings $settings,
        private readonly UnzipLimits $limits,
        private readonly ProjectService $projects,
        private readonly Logger $logger,
    ) {
    }

    private function now(): string
    {
        return SystemClock::iso($this->clock->now());
    }

    public static function toolFor(string $language): string
    {
        return \PJGuardian\Support\ToolLanguage::tool($language) ?? 'eslint'; // 대응은 한 곳(X08). 언어는 스키마 CHECK로 둘뿐
    }

    /**
     * 해제 대기(extracting) 업로드 하나를 처리한다. 없으면 null.
     *
     * @return array<string, mixed>|null
     */
    public function processNext(): ?array
    {
        $id = $this->claimNext();
        return $id === null ? null : $this->processClaimed($id);
    }

    /**
     * 해제 대기(extracting, work_dir 없음) 하나를 가져온다: 작업 디렉토리를 만들고 조건부 UPDATE로 work_dir를 기록한다. 없으면 null.
     * 워커(P14)는 부모가 가져오고 자식 프로세스(tools/run_upload.php)가 processClaimed()를 부른다.
     */
    public function claimNext(): ?string
    {
        for ($try = 0; $try < 5; $try++) {
            $id = $this->db->value("SELECT id FROM uploads WHERE status = 'extracting' AND work_dir IS NULL ORDER BY created_at, id LIMIT 1");
            if ($id === null) {
                return null;
            }
            $dir = $this->paths->newWorkDir();
            $work = basename(str_replace('\\', '/', $dir));
            $claimed = $this->db->execute(
                "UPDATE uploads SET work_dir = ?, last_activity_at = ? WHERE id = ? AND status = 'extracting' AND work_dir IS NULL",
                [$work, $this->now(), $id],
            );
            if ($claimed === 1) {
                return (string) $id;
            }
            @rmdir($dir);
        }
        return null;
    }

    /**
     * 가져온 업로드(extracting + work_dir)를 해제·사전 검사한다. 그 상태가 아니면 null.
     *
     * @return array<string, mixed>|null
     */
    public function processClaimed(string $id): ?array
    {
        $row = $this->db->one("SELECT work_dir FROM uploads WHERE id = ? AND status = 'extracting' AND work_dir IS NOT NULL", [$id]);
        if ($row === null) {
            return null;
        }
        return $this->process($id, $this->workPath((string) $row['work_dir']));
    }

    /**
     * 해제 자식이 시간 초과·비정상 종료한 경우(워커가 부름): failed, 작업 디렉토리 삭제. 바꿨으면 true.
     * 메모리 한도 초과처럼 다시 해도 같은 결과일 수 있어 되돌리지 않는다(명세 5.4: 재시도 없음, 다시 업로드).
     */
    public function failClaimed(string $id, string $code, string $reason): bool
    {
        $messageKey = 'error.' . $code;
        $work = $this->db->value("SELECT work_dir FROM uploads WHERE id = ? AND status = 'extracting' AND work_dir IS NOT NULL", [$id]);
        if (!is_string($work)) {
            return false;
        }
        $n = $this->db->execute(
            "UPDATE uploads SET status = 'failed', error_code = ?, error_message = ?, reserved_bytes = 0, work_dir = NULL, last_activity_at = ? WHERE id = ? AND status = 'extracting' AND work_dir = ?",
            [$code, Lang::get($messageKey), $this->now(), $id, $work],
        );
        if ($n === 1) {
            // 워커 부모가 부르므로 큰 디렉토리를 여기서 지우지 않고 휴지통으로 옮긴다(비우기는 자식 프로세스)
            if (!Trash::forWork($this->paths->work())->move($this->workPath($work))) {
                Fs::removeTree($this->workPath($work));
            }
            $this->logger->error('업로드 해제 실패(작업 프로세스)', ['upload_id' => $id, 'code' => $code, 'reason' => $reason]);
        }
        return $n === 1;
    }

    private function workPath(string $work): string
    {
        if (preg_match('/^[0-9a-f]{8}$/', $work) !== 1) {
            throw new \RuntimeException('작업 디렉토리 이름 형식 오류');
        }
        return rtrim(str_replace('\\', '/', $this->paths->work()), '/') . '/' . $work;
    }

    /** @return array<string, mixed> */
    private function process(string $id, string $dir): array
    {
        $start = hrtime(true);
        $row = $this->db->one('SELECT u.*, p.language, p.strip_top_folder FROM uploads u JOIN projects p ON p.id = u.project_id WHERE u.id = ?', [$id]);
        $language = (string) $row['language'];
        $rule = $row['strip_top_folder'] === null ? null : ((int) $row['strip_top_folder'] === 1);
        try {
            $unzip = new SafeUnzip($this->limits, $this->projects->matcherFor((int) $row['project_id']));
            $r = $unzip->extract($this->files->zip($id), $dir, $rule);
        } catch (UnzipException $e) {
            return $this->fail($id, $dir, $e->errorCode, $e->userMessage(), ['detail' => $e->detail, 'guide' => $e->guideKey()]);
        } catch (\Throwable $e) {
            $this->logger->error('업로드 해제 중 예기치 않은 오류', ['upload_id' => $id, 'error' => $e->getMessage()]);
            return $this->fail($id, $dir, 'io_error', Lang::get('error.io_error'), []);
        }
        $scan = TargetScanner::scan($r['dest'], $language, $this->settings->targetExtensions[$language] ?? []);
        $targets = $scan['total'];
        $warnings = $r['warnings'];
        if ($targets === 0) {
            $warnings[] = 'no_target';
        }
        // P21C: 같은 프로젝트에 같은 사용자가 직전에 올린 업로드와 비교(해시는 조립 때 스트리밍으로 구한 uploads.sha256)
        $previous = $this->previousUpload($id, (int) $row['project_id'], (int) $row['user_id'], (string) $row['sha256'], (int) $row['total_size']);
        if ($previous !== null && $previous['same']) {
            $warnings[] = 'same_as_previous'; // 막지 않고 확인을 받는다(의도한 재분석일 수 있음)
        }
        $summary = [
            'strip_rule' => $r['strip_rule'], 'top_folder' => $r['top_folder'], 'stripped_prefix' => $r['stripped_prefix'],
            'files' => $r['files'], 'bytes' => $r['bytes'], 'skipped' => $r['skipped'], 'targets' => $targets,
            'targets_by_extension' => $scan['by_extension'], 'config_skipped' => $scan['config_skipped'], 'previous' => $previous,
        ];
        Fs::atomicWrite($this->files->dir($id) . '/extract.json', (string) json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        // P21C: 화면의 내용 요약은 DB에도 둔다(분석이 성공하면 업로드 폴더가 바로 지워진다, 014)
        $this->db->execute('UPDATE uploads SET summary = ? WHERE id = ?', [json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
        $sec = round((hrtime(true) - $start) / 1e9, 2);
        if ($warnings !== []) {
            $this->db->execute(
                "UPDATE uploads SET status = 'needs_confirmation', warnings = ?, last_activity_at = ? WHERE id = ? AND status = 'extracting'",
                [json_encode($warnings), $this->now(), $id],
            );
            $this->logger->info('업로드 해제 완료(경고 확인 대기)', ['upload_id' => $id, 'warnings' => $warnings] + $summary);
            return ['upload_id' => $id, 'status' => 'needs_confirmation', 'warnings' => $warnings, 'seconds' => $sec] + $summary;
        }
        $analysisId = $this->enqueue($id, $r['strip_rule'], null);
        $this->logger->info('업로드 해제 완료(분석 대기열 등록)', ['upload_id' => $id, 'analysis_id' => $analysisId] + $summary);
        return ['upload_id' => $id, 'status' => 'queued', 'analysis_id' => $analysisId, 'warnings' => [], 'seconds' => $sec] + $summary;
    }

    /**
     * 직전 업로드(P21C): 같은 프로젝트·같은 사용자의 업로드 중 해제를 마치고 분석 대기열에 들어간(status queued) 가장 최근 것.
     * 분석이 아직 끝나지 않았어도 직전 업로드로 본다(연속으로 올린 같은 파일을 잡으려고). 다른 사용자·다른 프로젝트의 업로드는 보지 않는다.
     * 해시가 없는 업로드는 비교하지 않는다(→ null = 비교할 직전 기록 없음).
     *
     * @return array{same:bool, size:int, size_diff:int, created_at:string}|null
     */
    private function previousUpload(string $id, int $projectId, int $userId, string $sha, int $size): ?array
    {
        $p = $this->db->one(
            "SELECT sha256, total_size, created_at FROM uploads WHERE project_id = ? AND user_id = ? AND id <> ? AND status = 'queued' AND sha256 IS NOT NULL"
            . ' ORDER BY created_at DESC, rowid DESC LIMIT 1',
            [$projectId, $userId, $id],
        );
        if ($p === null || $sha === '') {
            return null;
        }
        return ['same' => hash_equals((string) $p['sha256'], $sha), 'size' => (int) $p['total_size'], 'size_diff' => $size - (int) $p['total_size'], 'created_at' => (string) $p['created_at']];
    }

    /** @return array<string, mixed> */
    private function fail(string $id, string $dir, string $code, string $message, array $log): array
    {
        Fs::removeTree($dir);
        $this->db->execute(
            "UPDATE uploads SET status = 'failed', error_code = ?, error_message = ?, reserved_bytes = 0, work_dir = NULL, last_activity_at = ? WHERE id = ?",
            [$code, $message, $this->now(), $id],
        );
        $this->logger->warn('업로드 해제 실패', ['upload_id' => $id, 'code' => $code] + $log);
        return ['upload_id' => $id, 'status' => 'failed', 'error_code' => $code, 'error_message' => $message];
    }

    /**
     * 분석 큐 등록: analyses(queued) 생성, 업로드 queued(예약 0). 프로젝트의 최상위 폴더 규칙이 비어 있으면 확정한다.
     */
    private function enqueue(string $id, ?bool $stripRule, ?string $confirmedAt): int
    {
        return $this->db->transaction(function () use ($id, $stripRule, $confirmedAt): int {
            $row = $this->db->one('SELECT u.*, p.language FROM uploads u JOIN projects p ON p.id = u.project_id WHERE u.id = ?', [$id]);
            if ($stripRule !== null) {
                $this->db->execute('UPDATE projects SET strip_top_folder = ? WHERE id = ? AND strip_top_folder IS NULL', [$stripRule ? 1 : 0, (int) $row['project_id']]);
            }
            $now = $this->now();
            $this->db->execute(
                'INSERT INTO analyses (project_id, upload_id, status, source_kind, trigger_kind, requested_by, tool, queued_at, assembled_sha256, work_dir)'
                . " VALUES (?, ?, 'queued', 'upload', 'user', ?, ?, ?, ?, ?)",
                [(int) $row['project_id'], $id, (int) $row['user_id'], self::toolFor((string) $row['language']), $now, $row['sha256'], $row['work_dir']],
            );
            $analysisId = $this->db->lastInsertId();
            $this->db->execute(
                "UPDATE uploads SET status = 'queued', reserved_bytes = 0, confirmed_at = ?, last_activity_at = ? WHERE id = ?",
                [$confirmedAt, $now, $id],
            );
            return $analysisId;
        });
    }

    /**
     * 경고 확인(업로드한 사용자 본인, 소유 확인은 호출한 쪽에서). needs_confirmation이 아니면 409 upload_not_confirmable.
     *
     * @param array<string, mixed> $row 내 업로드 행
     * @return array{upload_id:string, status:string, analysis_id:int}
     */
    public function confirm(array $row): array
    {
        $id = (string) $row['id'];
        $summary = json_decode((string) @file_get_contents($this->files->dir($id) . '/extract.json'), true);
        $rule = is_array($summary) && is_bool($summary['strip_rule'] ?? null) ? $summary['strip_rule'] : null;
        // 두 번 눌러도 한 번만: 확인 시각을 조건부로 기록해 차지하고, 같은 트랜잭션에서 큐에 넣는다
        $analysisId = $this->db->transaction(function () use ($id, $rule): int {
            $now = $this->now();
            $won = $this->db->execute(
                "UPDATE uploads SET confirmed_at = ?, last_activity_at = ? WHERE id = ? AND status = 'needs_confirmation' AND confirmed_at IS NULL",
                [$now, $now, $id],
            );
            if ($won !== 1) {
                throw new ApiException(409, 'upload_not_confirmable');
            }
            return $this->enqueue($id, $rule, $now);
        });
        $this->logger->info('업로드 경고 확인(분석 대기열 등록)', ['upload_id' => $id, 'analysis_id' => $analysisId]);
        return ['upload_id' => $id, 'status' => 'queued', 'analysis_id' => $analysisId];
    }

    /**
     * 경고 코드 → 사용자 문구(화면 표시용).
     *
     * @param list<string> $codes
     * @param array<string, mixed>|null $summary extract.json
     * @return list<array{code:string, message:string, danger:bool}>
     */
    public static function warningMessages(array $codes, string $language, UploadSettings $settings, ?array $summary): array
    {
        $out = [];
        foreach ($codes as $code) {
            $params = match ($code) {
                'no_target' => ['extensions' => implode(', ', array_map(static fn (string $e): string => '.' . $e, $settings->targetExtensions[$language] ?? []))],
                'top_folder_kept' => ['folder' => (string) ($summary['top_folder'] ?? '')],
                // 시각은 화면이 표시 시간대로 붙인다(서버는 UTC만, upload.js)
                'same_as_previous' => ['size' => number_format((int) ($summary['previous']['size'] ?? 0))],
                default => [],
            };
            $key = 'upload.warning.' . $code;
            $out[] = ['code' => $code, 'message' => Lang::has($key) ? Lang::get($key, $params) : $code, 'danger' => $code === 'no_target'];
        }
        return $out;
    }
}
