<?php

declare(strict_types=1);

namespace PJGuardian\Services;

use PJGuardian\Http\ApiException;
use PJGuardian\Repositories\ProjectRepository;
use PJGuardian\Support\CleanupQueue;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\ExcludeMatcher;
use PJGuardian\Support\Fs;
use PJGuardian\Support\Logger;
use PJGuardian\Support\Paths;
use PJGuardian\Support\SystemClock;

/**
 * 프로젝트 관리(명세 4장, 13장, 19.10).
 * - 생성·수정·삭제는 관리자만(라우트에서 검사). 생성·수정·삭제는 감사 로그에 남긴다.
 * - 조회는 볼 수 있는 프로젝트만(명세 3.1): 목록은 ProjectAccess::scopeFor로 거르고, 상세·하위 경로는 라우트의
 *   RequireProjectAccess가 먼저 판정한다. 이 클래스는 접근 규칙을 따로 구현하지 않는다.
 * - 언어는 생성 후 바꿀 수 없다(409 language_immutable, DB 트리거도 막음).
 * - 제외 판정은 ExcludeMatcher(matcherFor)로만 한다.
 * - 삭제: 이름 정확히 일치 확인 → 대기·실행 중 작업이 있으면 거부(project_busy, 가정) → DB 트랜잭션으로 행 삭제(연쇄)
 *   → 커밋 뒤 파일(스냅샷, 업로드 조각, 작업 디렉토리) 삭제. 파일 삭제 실패는 로그와 정리 대상 목록에 남기고 삭제는 성공으로 처리.
 */
final class ProjectService
{
    public const LANGUAGES = ['python', 'javascript'];
    private const NAME_MAX = 100;
    private const DESCRIPTION_MAX = 2000;

    private readonly ProjectRepository $repo;
    /** @var \Closure(string): bool */
    private \Closure $remover;

    /** @param list<string> $defaultExcludes 설정 extract.default_excludes */
    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly Paths $paths,
        private readonly AuditLog $audit,
        private readonly Logger $logger,
        private readonly array $defaultExcludes,
        private readonly ProjectAccess $access,
    ) {
        $this->repo = new ProjectRepository($db);
        $this->remover = static fn (string $path): bool => Fs::removeTree($path);
    }

    /** 시험용: 파일 삭제 함수를 바꾼다(삭제 실패 상황 재현) */
    public function setRemover(\Closure $remover): void
    {
        $this->remover = $remover;
    }

    private function now(): string
    {
        return SystemClock::iso($this->clock->now());
    }

    /** @return list<string> */
    public function defaultExcludes(): array
    {
        return array_values($this->defaultExcludes);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function toPublic(array $row, bool $isAdmin): array
    {
        $out = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'description' => (string) $row['description'],
            'language' => (string) $row['language'],
            'exclude_patterns' => self::patternsOf($row),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
        if ($isAdmin) {
            // 최상위 폴더 제거 규칙(19.10): null = 아직 업로드 없음. 관리자에게만 보인다
            $out['strip_top_folder'] = $row['strip_top_folder'] === null ? null : ((int) $row['strip_top_folder'] === 1);
            // 접근 매핑 요약(명세 3.1): 관리자에게만. 둘 다 0이면 관리자만 볼 수 있다
            $out['access'] = array_key_exists('access_groups', $row)
                ? ['groups' => (int) $row['access_groups'], 'users' => (int) $row['access_users']]
                : $this->access->summary((int) $row['id']);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private static function patternsOf(array $row): array
    {
        $list = json_decode((string) $row['exclude_patterns'], true);
        return is_array($list) ? array_values(array_map('strval', $list)) : [];
    }

    /**
     * 볼 수 있는 프로젝트 목록(admin = 전체).
     *
     * @param array<string, mixed>|null $user
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function list(?string $q, int $limit, int $offset, ?array $user): array
    {
        $isAdmin = $this->access->isAdmin($user);
        [$rows, $total] = $this->repo->search($q, $limit, $offset, $this->access->scopeFor($user));
        return [array_map(fn (array $r): array => $this->toPublic($r, $isAdmin), $rows), $total];
    }

    /** @return array<string, mixed> 상세: 제외 목록(기본 + 추가), 최근 분석 요약 */
    public function get(int $id, bool $isAdmin): array
    {
        $row = $this->repo->find($id) ?? throw new ApiException(404, 'project_not_found');
        $latest = $this->repo->latestAnalysis($id);
        $success = $this->repo->latestSuccess($id);
        return $this->toPublic($row, $isAdmin) + [
            'default_excludes' => $this->defaultExcludes(),
            'latest_analysis' => $latest === null ? null : self::ints($latest, ['id', 'total_count', 'new_count', 'resolved_count', 'no_change']),
            'latest_success' => $success === null ? null : self::ints($success, ['id', 'total_count', 'new_count', 'resolved_count', 'reopened_count', 'no_change']),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private static function ints(array $row, array $keys): array
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $row) && $row[$k] !== null) {
                $row[$k] = (int) $row[$k];
            }
        }
        return $row;
    }

    /** 이 프로젝트의 제외 판정기(서버 기본 목록 + 추가 패턴). 해제·판정·화면이 모두 이것을 쓴다 */
    public function matcherFor(int $id): ExcludeMatcher
    {
        $row = $this->repo->find($id) ?? throw new ApiException(404, 'project_not_found');
        return new ExcludeMatcher($this->defaultExcludes(), self::patternsOf($row));
    }

    /**
     * @param array<string, mixed> $data name, language, description?
     * @return array<string, mixed>
     */
    public function create(Actor $actor, array $data): array
    {
        $name = $this->validName($data['name'] ?? null);
        $description = $this->validDescription($data['description'] ?? '');
        $language = $data['language'] ?? null;
        if (!in_array($language, self::LANGUAGES, true)) {
            throw new ApiException(400, 'invalid_language');
        }
        $id = $this->db->transaction(function () use ($actor, $name, $description, $language): int {
            if ($this->repo->nameExists($name)) {
                throw new ApiException(409, 'project_name_taken');
            }
            $id = $this->repo->insert($name, $description, $language, $this->now());
            $this->audit->record($actor, 'project.create', 'project', (string) $id, ['name' => $name, 'language' => $language]);
            return $id;
        });
        $this->logger->info('프로젝트 생성', ['project_id' => $id, 'by' => $actor->label]);
        return $this->get($id, true);
    }

    /**
     * 이름·설명·추가 제외 패턴 수정. 언어는 바꿀 수 없다(같은 값이면 무시).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(Actor $actor, int $id, array $data): array
    {
        $row = $this->repo->find($id) ?? throw new ApiException(404, 'project_not_found');
        if (array_key_exists('language', $data) && $data['language'] !== $row['language']) {
            throw new ApiException(409, 'language_immutable');
        }
        $name = array_key_exists('name', $data) ? $this->validName($data['name']) : (string) $row['name'];
        $description = array_key_exists('description', $data) ? $this->validDescription($data['description']) : (string) $row['description'];
        $before = self::patternsOf($row);
        $patterns = array_key_exists('exclude_patterns', $data) ? $this->validPatterns($data['exclude_patterns']) : $before;

        $changes = [];
        foreach (['name' => [(string) $row['name'], $name], 'description' => [(string) $row['description'], $description], 'exclude_patterns' => [$before, $patterns]] as $k => [$from, $to]) {
            if ($from !== $to) {
                $changes[$k] = ['from' => $from, 'to' => $to];
            }
        }
        if ($changes !== []) {
            $this->db->transaction(function () use ($actor, $id, $name, $description, $patterns, $changes): void {
                if (isset($changes['name']) && $this->repo->nameExists($name, $id)) {
                    throw new ApiException(409, 'project_name_taken');
                }
                $this->repo->update($id, $name, $description, $patterns, $this->now());
                $this->audit->record($actor, 'project.update', 'project', (string) $id, $changes);
            });
            $this->logger->info('프로젝트 수정', ['project_id' => $id, 'fields' => array_keys($changes), 'by' => $actor->label]);
        }
        return $this->get($id, true);
    }

    /** @return array{analyses:int, defects:int, snapshot_bytes:int, uploads:int} 삭제 확인 화면용 */
    public function deletionInfo(int $id): array
    {
        $this->repo->find($id) ?? throw new ApiException(404, 'project_not_found');
        return $this->repo->deletionInfo($id);
    }

    /**
     * 즉시 영구 삭제(명세 4장). $confirmName이 프로젝트 이름과 정확히 같아야 한다(서버에서 검사).
     *
     * @return array{deleted: bool, file_cleanup_failures: int}
     */
    public function delete(Actor $actor, int $id, mixed $confirmName): array
    {
        $row = $this->repo->find($id) ?? throw new ApiException(404, 'project_not_found');
        if (!is_string($confirmName) || $confirmName !== (string) $row['name']) {
            throw new ApiException(400, 'project_name_mismatch');
        }
        // DB 삭제 전에 지울 파일 위치를 모은다(행이 지워지면 알 수 없으므로)
        [$info, $refs] = $this->db->transaction(function () use ($actor, $id, $row): array {
            if ($this->repo->isBusy($id)) {
                throw new ApiException(409, 'project_busy');
            }
            $info = $this->repo->deletionInfo($id);
            $refs = $this->repo->storageRefs($id);
            $this->repo->delete($id);
            $this->audit->record($actor, 'project.delete', 'project', (string) $id, [
                'name' => (string) $row['name'], 'language' => (string) $row['language'],
                'analyses' => $info['analyses'], 'defects' => $info['defects'], 'snapshot_bytes' => $info['snapshot_bytes'],
            ]);
            return [$info, $refs];
        });
        $failures = $this->removeFiles($id, $refs);
        $this->logger->info('프로젝트 삭제', ['project_id' => $id, 'by' => $actor->label] + $info + ['file_cleanup_failures' => $failures]);
        return ['deleted' => true, 'file_cleanup_failures' => $failures];
    }

    /**
     * 프로젝트 파일 삭제. 경로는 DB에 서버가 기록한 값으로만 만들고, 각 기준 폴더 밖이면 건드리지 않는다.
     *
     * @param array{snapshots: list<string>, uploads: list<string>, work_dirs: list<string>} $refs
     */
    private function removeFiles(int $projectId, array $refs): int
    {
        $targets = [];
        foreach ($refs['snapshots'] as $rel) {
            $targets[] = $this->inside($this->paths->snapshots(), $rel);
        }
        // 스냅샷은 data/snapshots/<프로젝트 id>/<분석 id>/ 구조(명세 7, P16). 프로젝트 폴더째 지운다
        $targets[] = $this->inside($this->paths->snapshots(), (string) $projectId);
        foreach ($refs['uploads'] as $uploadId) {
            if (preg_match('/^[0-9a-f]{32}$/', $uploadId) === 1) {
                $targets[] = $this->inside($this->paths->uploads(), $uploadId); // 업로드 조각·조립본은 uploads/<upload_id>/(P12)
            }
        }
        foreach ($refs['work_dirs'] as $dir) {
            if (preg_match('/^[0-9a-f]{8}$/', $dir) === 1) {
                $targets[] = $this->inside($this->paths->work(), $dir);
            }
        }
        $failures = 0;
        $queue = new CleanupQueue($this->paths->work() . '/cleanup_pending.jsonl', $this->clock);
        foreach (array_unique(array_filter($targets)) as $path) {
            if (!file_exists($path)) {
                continue;
            }
            if (!($this->remover)($path)) {
                $failures++;
                $this->logger->warn('정리하지 못한 파일(정리 대상 목록에 남김)', ['project_id' => $projectId, 'path' => $path]);
                $queue->add($path, 'project_delete', ['project_id' => $projectId]);
            }
        }
        return $failures;
    }

    /** 기준 폴더 안의 경로면 돌려주고, 밖이면(경로 조작) null */
    private function inside(string $base, string $rel): ?string
    {
        $rel = str_replace('\\', '/', $rel);
        if ($rel === '' || str_starts_with($rel, '/') || preg_match('#(^|/)\.\.(/|$)#', $rel) === 1 || preg_match('/^[A-Za-z]:/', $rel) === 1) {
            return null;
        }
        return rtrim(str_replace('\\', '/', $base), '/') . '/' . trim($rel, '/');
    }

    private function validName(mixed $v): string
    {
        $v = is_string($v) ? trim($v) : '';
        if ($v === '' || mb_strlen($v, 'UTF-8') > self::NAME_MAX || preg_match('/[\x00-\x1F\x7F]/', $v) === 1) {
            throw new ApiException(400, 'invalid_project_name', ['max' => self::NAME_MAX]);
        }
        return $v;
    }

    private function validDescription(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (!is_string($v)) {
            throw new ApiException(400, 'invalid_description', ['max' => self::DESCRIPTION_MAX]);
        }
        $v = trim($v);
        if (mb_strlen($v, 'UTF-8') > self::DESCRIPTION_MAX || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $v) === 1) {
            throw new ApiException(400, 'invalid_description', ['max' => self::DESCRIPTION_MAX]);
        }
        return $v;
    }

    /** @return list<string> */
    private function validPatterns(mixed $v): array
    {
        if (!is_array($v) || !array_is_list($v)) {
            throw new ApiException(400, 'invalid_input');
        }
        [$list, $errors] = ExcludeMatcher::normalizePatterns($v);
        if ($errors !== []) {
            // 메시지: "제외 패턴 '…'이(가) 올바르지 않습니다: <이유>". 이유 문구는 exclude.invalid.<코드>(LangTest가 코드별 키를 검사)
            $whyKey = 'exclude.invalid.' . $errors[0]['reason'];
            throw new ApiException(400, 'invalid_exclude_pattern', [
                'pattern' => $errors[0]['pattern'],
                'why' => \PJGuardian\Support\Lang::get($whyKey),
            ]);
        }
        if (count($list) > ExcludeMatcher::MAX_PATTERNS) {
            throw new ApiException(400, 'too_many_exclude_patterns', ['max' => ExcludeMatcher::MAX_PATTERNS]);
        }
        return $list;
    }
}
