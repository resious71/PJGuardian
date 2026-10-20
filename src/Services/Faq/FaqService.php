<?php

declare(strict_types=1);

namespace PJGuardian\Services\Faq;

use PJGuardian\Http\ApiException;
use PJGuardian\Services\Actor;
use PJGuardian\Services\AuditLog;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\SystemClock;

/**
 * FAQ(P29B, 가이드 메뉴의 FAQ 탭) — 읽기는 로그인한 모든 사용자, 쓰기(추가·수정·삭제·순서·공개 전환)는 admin만(라우트 $admin이 판정, 여기는 데이터 규칙).
 * - 비공개(초안) 항목은 admin만 본다: 일반 사용자의 목록·검색·건수·직접 조회(404)에 드러나지 않는다.
 * - 값 정리: NFC, 제어 문자 제거, 앞뒤 공백 제거. 분류·질문은 한 줄(연속 공백 하나로). 길이는 분류 1~40·질문 1~200·답변 1~10,000자(DB CHECK와 같음).
 * - 낙관적 잠금: 수정은 version이 같을 때만(다르면 409 faq_conflict + 최신 항목). 값이 모두 같으면 쓰지 않는다(version·감사 그대로).
 * - 순서: sort_order는 전체에서 이어지는 값 — 새 항목은 맨 끝, 분류 순서는 분류 안 가장 앞 항목의 sort_order. 순서 바꾸기는 그 분류가 가진
 *   sort_order 값들을 새 순서대로 다시 나눠 준다(다른 분류 영향 없음, version은 내용 수정에만 오른다). 보낸 목록이 실제와 다르면 409 faq_order_conflict.
 * - 검색: search_text(분류·질문·답변, NFC + 소문자)에 LIKE(%·_·\ 이스케이프), 낱말마다 AND. 500개 상한이라 색인 없이 충분(보고서 P29B 측정).
 * - 감사: faq.create·faq.update(바뀐 필드, 답변은 길이·해시만)·faq.publish·faq.delete(질문 제목)·faq.reorder·faq.import(X16 일괄 가져오기 한 줄).
 */
final class FaqService
{
    public const MAX_ITEMS = 500;
    public const MAX_CATEGORY = 40;
    public const MAX_QUESTION = 200;
    public const MAX_ANSWER = 10000;
    public const MAX_QUERY = 100;

    public function __construct(private readonly Db $db, private readonly Clock $clock, private readonly AuditLog $audit)
    {
    }

    /**
     * @return array{items: list<array<string, mixed>>, categories: list<string>, total: int}
     */
    public function list(bool $isAdmin, string $query = '', ?string $category = null, bool $includeDrafts = true): array
    {
        $where = [];
        $params = [];
        if (!$isAdmin || !$includeDrafts) {
            $where[] = 'f.is_published = 1';
        }
        if ($category !== null && $category !== '') {
            $where[] = 'f.category = ?';
            $params[] = self::oneLine($category);
        }
        foreach (self::queryWords($query) as $w) {
            $where[] = "f.search_text LIKE ? ESCAPE '\\'";
            $params[] = '%' . self::likeEscape($w) . '%';
        }
        $sql = 'SELECT f.*, u.display_name AS updated_by_name, (SELECT MIN(g.sort_order) FROM faq_items g WHERE g.category = f.category'
            . ($isAdmin && $includeDrafts ? '' : ' AND g.is_published = 1') . ') AS category_order'
            . ' FROM faq_items f LEFT JOIN users u ON u.id = f.updated_by'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY category_order, f.category, f.sort_order, f.id';
        $items = array_map(fn (array $r): array => $this->view($r, $isAdmin), $this->db->all($sql, $params));
        $cats = [];
        foreach ($items as $it) {
            $cats[$it['category']] = true;
        }
        // 분류 목록(검색 조건과 무관하게 보이는 모든 분류 — 화면의 분류 선택·편집 대화상자의 제안 목록)
        $all = $this->db->all('SELECT category, MIN(sort_order) AS o FROM faq_items' . ($isAdmin && $includeDrafts ? '' : ' WHERE is_published = 1')
            . ' GROUP BY category ORDER BY o, category');
        return ['items' => $items, 'categories' => array_column($all, 'category'), 'total' => count($items)];
    }

    /** @return array<string, mixed> 일반 사용자에게 비공개 항목은 없는 것과 같다(404) */
    public function get(int $id, bool $isAdmin): array
    {
        $r = $this->row($id);
        if ($r === null || (!$isAdmin && (int) $r['is_published'] !== 1)) {
            throw new ApiException(404, 'faq_not_found');
        }
        return $this->view($r, $isAdmin);
    }

    /** @param array<string, mixed> $input {category, question, answer, is_published?} */
    public function create(Actor $actor, array $input): array
    {
        $v = self::validate($input, true);
        $id = $this->db->transaction(function () use ($actor, $v): int {
            if ((int) $this->db->value('SELECT COUNT(*) FROM faq_items') >= self::MAX_ITEMS) {
                throw new ApiException(400, 'faq_limit', ['max' => self::MAX_ITEMS]);
            }
            $now = SystemClock::iso($this->clock->now());
            $order = (int) $this->db->value('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM faq_items');
            $this->db->execute('INSERT INTO faq_items (category, question, answer, search_text, sort_order, is_published, version, created_by, updated_by, created_at, updated_at)'
                . ' VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)',
                [$v['category'], $v['question'], $v['answer'], self::searchText($v), $order, $v['is_published'] ? 1 : 0, $actor->userId, $actor->userId, $now, $now]);
            $id = $this->db->lastInsertId();
            $this->audit->record($actor, 'faq.create', 'faq', (string) $id, ['category' => $v['category'], 'question' => $v['question'],
                'answer_length' => mb_strlen($v['answer']), 'answer_sha256' => substr(hash('sha256', $v['answer']), 0, 16), 'published' => $v['is_published']]);
            return $id;
        });
        return $this->get($id, true);
    }

    /**
     * 일괄 가져오기(X16, tools/faq_import.php — 시드 파일). **전부 검증한 뒤 한 트랜잭션**으로 넣는다(부분 등록 없음).
     * - 같은 (분류, 질문)(정리 뒤 값)이 이미 있으면 건너뛴다 — 수정·삭제·덮어쓰기 없음(관리자가 화면에서 고친 내용 보존). 새 항목만 맨 끝 순서로.
     * - 검증은 create와 같은 validate()(길이·NFC·공백 정리) + 파일 안 중복 + 500개 상한. 오류가 하나라도 있으면 아무것도 쓰지 않는다.
     * - $draft면 모두 비공개. 감사는 항목마다가 아니라 한 줄 faq.import(추가·건너뜀 건수·비공개 여부·출처) — 감사 화면에서 한 번의 가져오기로 읽힌다.
     *
     * @param list<mixed> $items [{category, question, answer}]
     * @return array{added: list<array{category:string, question:string}>, skipped: list<array{category:string, question:string}>,
     *   errors: list<array{index:int, code:string, field:?string}>}
     */
    public function import(Actor $actor, array $items, bool $draft, bool $dryRun, string $source = ''): array
    {
        $errors = [];
        $valid = [];
        $seen = [];
        foreach (array_values($items) as $i => $item) {
            if (!is_array($item)) {
                $errors[] = ['index' => $i, 'code' => 'invalid_faq', 'field' => null];
                continue;
            }
            try {
                $v = self::validate(array_intersect_key($item, ['category' => 1, 'question' => 1, 'answer' => 1]), true);
            } catch (ApiException $e) {
                $errors[] = ['index' => $i, 'code' => $e->errorCode, 'field' => is_string($e->params['field'] ?? null) ? $e->params['field'] : null];
                continue;
            }
            $key = $v['category'] . "\n" . $v['question'];
            if (isset($seen[$key])) {
                $errors[] = ['index' => $i, 'code' => 'duplicate_in_file', 'field' => 'question'];
                continue;
            }
            $seen[$key] = true;
            $valid[] = $v;
        }
        $existing = [];
        foreach ($this->db->all('SELECT category, question FROM faq_items') as $r) {
            $existing[$r['category'] . "\n" . $r['question']] = true;
        }
        $added = [];
        $skipped = [];
        foreach ($valid as $v) {
            if (isset($existing[$v['category'] . "\n" . $v['question']])) {
                $skipped[] = ['category' => $v['category'], 'question' => $v['question']];
            } else {
                $added[] = $v;
            }
        }
        if ($errors === [] && count($existing) + count($added) > self::MAX_ITEMS) {
            $errors[] = ['index' => -1, 'code' => 'faq_limit', 'field' => null];
        }
        $out = ['added' => array_map(static fn (array $v): array => ['category' => $v['category'], 'question' => $v['question']], $added), 'skipped' => $skipped, 'errors' => $errors];
        if ($errors !== [] || $dryRun || $added === []) {
            return $out;
        }
        $this->db->transaction(function () use ($actor, $added, $skipped, $draft, $source): void {
            $now = SystemClock::iso($this->clock->now());
            $order = (int) $this->db->value('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM faq_items');
            foreach ($added as $v) {
                $this->db->execute('INSERT INTO faq_items (category, question, answer, search_text, sort_order, is_published, version, created_by, updated_by, created_at, updated_at)'
                    . ' VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)',
                    [$v['category'], $v['question'], $v['answer'], self::searchText($v), $order++, $draft ? 0 : 1, $actor->userId, $actor->userId, $now, $now]);
            }
            $this->audit->record($actor, 'faq.import', 'faq', null, ['added' => count($added), 'skipped' => count($skipped), 'published' => !$draft,
                'source' => mb_substr($source, 0, 100)]);
        });
        return $out;
    }

    /**
     * @param array<string, mixed> $input {version, category?, question?, answer?, is_published?}
     * @return array{item: array<string, mixed>, changed: bool}
     */
    public function update(Actor $actor, int $id, array $input): array
    {
        $version = $input['version'] ?? null;
        if (!is_int($version) || $version < 1) {
            throw new ApiException(400, 'invalid_faq', ['field' => 'version'], null, [], [], ['field' => 'version']);
        }
        $v = self::validate($input, false);
        return $this->db->transaction(function () use ($actor, $id, $version, $v): array {
            $r = $this->row($id);
            if ($r === null) {
                throw new ApiException(404, 'faq_not_found');
            }
            if ((int) $r['version'] !== $version) {
                throw new ApiException(409, 'faq_conflict', [], null, [], [], ['current' => $this->view($r, true)]);
            }
            $next = ['category' => $v['category'] ?? $r['category'], 'question' => $v['question'] ?? $r['question'], 'answer' => $v['answer'] ?? $r['answer'],
                'is_published' => array_key_exists('is_published', $v) ? $v['is_published'] : (int) $r['is_published'] === 1];
            $changed = [];
            foreach (['category', 'question', 'answer'] as $f) {
                if ($next[$f] !== $r[$f]) {
                    $changed[] = $f;
                }
            }
            $publishChanged = $next['is_published'] !== ((int) $r['is_published'] === 1);
            if (!$changed && !$publishChanged) {
                return ['item' => $this->view($r, true), 'changed' => false];
            }
            // 분류를 옮기면 새 분류의 맨 끝으로
            $order = in_array('category', $changed, true) ? (int) $this->db->value('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM faq_items') : (int) $r['sort_order'];
            $now = SystemClock::iso($this->clock->now());
            $this->db->execute('UPDATE faq_items SET category = ?, question = ?, answer = ?, search_text = ?, sort_order = ?, is_published = ?, version = version + 1,'
                . ' updated_by = ?, updated_at = ? WHERE id = ? AND version = ?',
                [$next['category'], $next['question'], $next['answer'], self::searchText($next), $order, $next['is_published'] ? 1 : 0, $actor->userId, $now, $id, $version]);
            if ($changed) {
                $details = ['fields' => $changed, 'question' => $next['question']];
                if (in_array('category', $changed, true)) {
                    $details['category'] = ['from' => $r['category'], 'to' => $next['category']];
                }
                if (in_array('answer', $changed, true)) {
                    $details['answer_length'] = mb_strlen($next['answer']);
                    $details['answer_sha256'] = substr(hash('sha256', $next['answer']), 0, 16);
                }
                $this->audit->record($actor, 'faq.update', 'faq', (string) $id, $details);
            }
            if ($publishChanged) {
                $this->audit->record($actor, 'faq.publish', 'faq', (string) $id, ['published' => $next['is_published'], 'question' => $next['question']]);
            }
            return ['item' => $this->view((array) $this->row($id), true), 'changed' => true];
        });
    }

    public function delete(Actor $actor, int $id): void
    {
        $this->db->transaction(function () use ($actor, $id): void {
            $r = $this->row($id);
            if ($r === null) {
                throw new ApiException(404, 'faq_not_found');
            }
            $this->db->execute('DELETE FROM faq_items WHERE id = ?', [$id]);
            $this->audit->record($actor, 'faq.delete', 'faq', (string) $id, ['category' => $r['category'], 'question' => $r['question']]);
        });
    }

    /**
     * 같은 분류 안 순서 바꾸기. ids는 그 분류의 모든 항목(비공개 포함)을 새 순서로 — 하나라도 다르면 409(그사이 다른 관리자가 바꿈).
     *
     * @param list<mixed> $ids
     * @return list<array<string, mixed>> 그 분류의 항목(새 순서)
     */
    public function reorder(Actor $actor, string $category, array $ids): array
    {
        $category = self::oneLine($category);
        if ($category === '' || $ids === [] || count($ids) > self::MAX_ITEMS || array_filter($ids, static fn ($i): bool => !is_int($i) || $i < 1) !== []) {
            throw new ApiException(400, 'invalid_faq', ['field' => 'ids'], null, [], [], ['field' => 'ids']);
        }
        return $this->db->transaction(function () use ($actor, $category, $ids): array {
            $rows = $this->db->all('SELECT id, sort_order FROM faq_items WHERE category = ? ORDER BY sort_order, id', [$category]);
            $current = array_map('intval', array_column($rows, 'id'));
            $a = $current;
            $b = $ids;
            sort($a);
            sort($b);
            if ($a !== $b) {
                throw new ApiException(409, 'faq_order_conflict', [], null, [], [], ['current' => $current]);
            }
            if ($current === $ids) {
                return $this->categoryItems($category);
            }
            $orders = array_map('intval', array_column($rows, 'sort_order'));
            sort($orders);
            foreach ($ids as $i => $id) {
                $this->db->execute('UPDATE faq_items SET sort_order = ? WHERE id = ?', [$orders[$i], $id]);
            }
            $this->audit->record($actor, 'faq.reorder', 'faq_category', $category, ['ids' => $ids]);
            return $this->categoryItems($category);
        });
    }

    /** @return list<array<string, mixed>> */
    private function categoryItems(string $category): array
    {
        return array_map(fn (array $r): array => $this->view($r, true),
            $this->db->all('SELECT f.*, u.display_name AS updated_by_name FROM faq_items f LEFT JOIN users u ON u.id = f.updated_by WHERE f.category = ? ORDER BY f.sort_order, f.id', [$category]));
    }

    /** @return array<string, mixed>|null */
    private function row(int $id): ?array
    {
        return $this->db->one('SELECT f.*, u.display_name AS updated_by_name FROM faq_items f LEFT JOIN users u ON u.id = f.updated_by WHERE f.id = ?', [$id]);
    }

    /**
     * 응답 모양. 계정 정보는 admin에게만 표시 이름 정도(이메일 없음).
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function view(array $r, bool $isAdmin): array
    {
        $out = ['id' => (int) $r['id'], 'category' => (string) $r['category'], 'question' => (string) $r['question'], 'answer' => (string) $r['answer'],
            'sort_order' => (int) $r['sort_order'], 'version' => (int) $r['version'], 'updated_at' => (string) $r['updated_at']];
        if ($isAdmin) {
            $out['is_published'] = (int) $r['is_published'] === 1;
            $out['updated_by_name'] = isset($r['updated_by_name']) ? (string) $r['updated_by_name'] : null;
        }
        return $out;
    }

    /**
     * 입력 정리·검증. $full이면 분류·질문·답변이 모두 있어야 한다(추가), 아니면 온 것만(수정).
     *
     * @param array<string, mixed> $input
     * @return array{category?: string, question?: string, answer?: string, is_published?: bool}
     */
    public static function validate(array $input, bool $full): array
    {
        $out = [];
        $limits = ['category' => self::MAX_CATEGORY, 'question' => self::MAX_QUESTION, 'answer' => self::MAX_ANSWER];
        foreach ($limits as $field => $max) {
            if (!array_key_exists($field, $input)) {
                if ($full) {
                    throw new ApiException(400, 'invalid_faq', ['field' => $field], null, [], [], ['field' => $field]);
                }
                continue;
            }
            $raw = $input[$field];
            if (!is_string($raw)) {
                throw new ApiException(400, 'invalid_faq', ['field' => $field], null, [], [], ['field' => $field]);
            }
            $value = $field === 'answer' ? self::multiLine($raw) : self::oneLine($raw);
            $len = mb_strlen($value);
            if ($len < 1 || $len > $max) {
                throw new ApiException(400, 'invalid_faq_length', ['field' => $field, 'max' => $max], null, [], [], ['field' => $field, 'max' => $max]);
            }
            $out[$field] = $value;
        }
        if (array_key_exists('is_published', $input)) {
            if (!is_bool($input['is_published'])) {
                throw new ApiException(400, 'invalid_faq', ['field' => 'is_published'], null, [], [], ['field' => 'is_published']);
            }
            $out['is_published'] = $input['is_published'];
        } elseif ($full) {
            $out['is_published'] = true;
        }
        return $out;
    }

    /** 한 줄 값: NFC, 제어 문자·줄바꿈은 공백으로, 연속 공백 하나로, 앞뒤 공백 제거 */
    public static function oneLine(string $s): string
    {
        $s = self::nfc($s);
        $s = (string) preg_replace('/[\p{Cc}\p{Zl}\p{Zp}\x{FEFF}]/u', ' ', $s);
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /** 여러 줄 값(답변): NFC, 줄바꿈은 \n으로, 탭·줄바꿈 밖의 제어 문자 제거, 줄 끝 공백과 앞뒤 빈 줄 제거 */
    public static function multiLine(string $s): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", self::nfc($s));
        $s = (string) preg_replace('/[^\P{Cc}\n\t]|\x{FEFF}/u', '', $s);
        $s = (string) preg_replace('/[ \t]+$/mu', '', $s);
        return trim($s, "\n");
    }

    /** @return list<string> 검색 낱말(정리·소문자, 100자까지) */
    public static function queryWords(string $q): array
    {
        $q = mb_strtolower(mb_substr(self::oneLine($q), 0, self::MAX_QUERY));
        return $q === '' ? [] : array_values(array_unique(explode(' ', $q)));
    }

    public static function likeEscape(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }

    /** @param array{category: string, question: string, answer: string} $v */
    private static function searchText(array $v): string
    {
        return mb_substr(mb_strtolower($v['category'] . "\n" . $v['question'] . "\n" . $v['answer']), 0, 10300);
    }

    private static function nfc(string $s): string
    {
        if (!mb_check_encoding($s, 'UTF-8')) {
            throw new ApiException(400, 'invalid_faq', ['field' => 'encoding'], null, [], [], ['field' => 'encoding']);
        }
        $n = \Normalizer::normalize($s, \Normalizer::FORM_C);
        return is_string($n) ? $n : $s;
    }
}
