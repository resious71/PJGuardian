<?php

declare(strict_types=1);

namespace PJGuardian\Repositories;

use PJGuardian\Support\Db;

/** projects 테이블과 프로젝트 단위 집계(삭제 확인 정보, 최근 분석). 모든 쿼리는 Prepared Statement. */
final class ProjectRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM projects WHERE id = ?', [$id]);
    }

    public function nameExists(string $name, ?int $exceptId = null): bool
    {
        // projects.name은 COLLATE NOCASE: 대소문자만 다른 이름도 같은 이름으로 본다
        return $this->db->value('SELECT 1 FROM projects WHERE name = ? AND id <> ?', [$name, $exceptId ?? 0]) !== null;
    }

    /**
     * 목록. $scope는 ProjectAccess::scopeFor()의 조건(별칭 p)이다. 접근 매핑 요약(access_groups, access_users)을 함께 읽는다.
     *
     * @param array{0: string, 1: list<int>} $scope
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function search(?string $q, int $limit, int $offset, array $scope): array
    {
        [$cond, $scopeParams] = $scope;
        $where = " WHERE {$cond}";
        $params = $scopeParams;
        if ($q !== null && $q !== '') {
            $where .= " AND (p.name LIKE ? ESCAPE '\\' OR p.description LIKE ? ESCAPE '\\')";
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $params = [...$params, $like, $like];
        }
        $total = (int) $this->db->value('SELECT COUNT(*) FROM projects p' . $where, $params);
        $rows = $this->db->all(
            "SELECT p.*,"
            . " (SELECT COUNT(*) FROM project_access a WHERE a.project_id = p.id AND a.subject_type = 'group') AS access_groups,"
            . " (SELECT COUNT(*) FROM project_access a WHERE a.project_id = p.id AND a.subject_type = 'user') AS access_users"
            . ' FROM projects p' . $where . ' ORDER BY p.name COLLATE NOCASE LIMIT ? OFFSET ?',
            [...$params, $limit, $offset],
        );
        return [$rows, $total];
    }

    public function insert(string $name, string $description, string $language, string $now): int
    {
        $this->db->execute(
            'INSERT INTO projects (name, description, language, exclude_patterns, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$name, $description, $language, '[]', $now, $now],
        );
        return $this->db->lastInsertId();
    }

    /** @param list<string> $patterns */
    public function update(int $id, string $name, string $description, array $patterns, string $now): void
    {
        $this->db->execute(
            'UPDATE projects SET name = ?, description = ?, exclude_patterns = ?, updated_at = ? WHERE id = ?',
            [$name, $description, json_encode($patterns, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $now, $id],
        );
    }

    public function delete(int $id): int
    {
        return $this->db->execute('DELETE FROM projects WHERE id = ?', [$id]);
    }

    /** 대기·실행 중 분석 또는 워커가 처리 중인 업로드가 있는가(삭제 거부 판단, 가정) */
    public function isBusy(int $id): bool
    {
        return $this->db->value(
            "SELECT 1 FROM analyses WHERE project_id = ? AND status IN ('queued', 'running')"
            . " UNION ALL SELECT 1 FROM uploads WHERE project_id = ? AND status IN ('assembling', 'extracting') LIMIT 1",
            [$id, $id],
        ) !== null;
    }

    /** @return array{analyses:int, defects:int, snapshot_bytes:int, uploads:int} */
    public function deletionInfo(int $id): array
    {
        return [
            'analyses' => (int) $this->db->value('SELECT COUNT(*) FROM analyses WHERE project_id = ?', [$id]),
            'defects' => (int) $this->db->value('SELECT COUNT(*) FROM defects WHERE project_id = ?', [$id]),
            'snapshot_bytes' => (int) $this->db->value(
                'SELECT COALESCE(SUM(s.size_bytes), 0) FROM snapshots s JOIN analyses a ON a.id = s.analysis_id WHERE a.project_id = ?',
                [$id],
            ),
            'uploads' => (int) $this->db->value('SELECT COUNT(*) FROM uploads WHERE project_id = ?', [$id]),
        ];
    }

    /**
     * 삭제 뒤 지울 파일 위치(DB에 서버가 기록한 값만, 사용자 입력 아님).
     *
     * @return array{snapshots: list<string>, uploads: list<string>, work_dirs: list<string>}
     */
    public function storageRefs(int $id): array
    {
        return [
            'snapshots' => array_map('strval', array_column($this->db->all(
                'SELECT s.storage_path FROM snapshots s JOIN analyses a ON a.id = s.analysis_id WHERE a.project_id = ?',
                [$id],
            ), 'storage_path')),
            'uploads' => array_map('strval', array_column($this->db->all('SELECT id FROM uploads WHERE project_id = ?', [$id]), 'id')),
            'work_dirs' => array_map('strval', array_column($this->db->all(
                'SELECT work_dir FROM uploads WHERE project_id = ? AND work_dir IS NOT NULL',
                [$id],
            ), 'work_dir')),
        ];
    }

    /** @return array<string, mixed>|null 가장 최근 분석(상태 무관) */
    public function latestAnalysis(int $id): ?array
    {
        return $this->db->one(
            'SELECT id, status, source_kind, queued_at, started_at, finished_at, total_count, new_count, resolved_count, no_change'
            . ' FROM analyses WHERE project_id = ? ORDER BY id DESC LIMIT 1',
            [$id],
        );
    }

    /** @return array<string, mixed>|null 가장 최근 성공 분석(실제로 분석한 것 — "변경 없음"(P27) 제외) */
    public function latestSuccess(int $id): ?array
    {
        return $this->db->one(
            "SELECT id, finished_at, total_count, new_count, resolved_count, reopened_count, no_change"
            . " FROM analyses WHERE project_id = ? AND status = 'succeeded' AND no_change = 0 ORDER BY id DESC LIMIT 1",
            [$id],
        );
    }
}
