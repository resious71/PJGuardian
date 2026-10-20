<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Services\Faq\FaqService;
use PJGuardian\Services\Guide\GuideCatalog;
use PJGuardian\Support\Db;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * FAQ 시드(X16, resources/faq/seed.ko.json)와 가져오기 도구(tools/faq_import.php)가 낡지 않게:
 * - 시드: JSON 형식, 모든 항목이 FAQ 검증(길이·공백 정리)을 통과, (분류, 질문) 중복 없음, 분량(분류 6~8, 질문 25~40).
 * - 답변의 앱 안 링크는 사용자 가이드 절 key(+ 소제목 앵커)로 풀리거나 오류 코드 탭 — 관리자 가이드 링크 없음.
 * - 금지어(개발 PC 경로·이메일·긴 토큰·자리표시자·원시 HTML·javascript:), 큰따옴표 안 화면 용어는 실제 ko 문구.
 * - 도구(임시 설정·파일 DB): dry-run은 쓰지 않음, 첫 실행 N건, 두 번째 0건(N건 건너뜀), 일부가 이미 있으면 나머지만, 검증 실패면 0건·종료 코드 1,
 *   029 없으면 쓰지 않고 2, --draft면 모두 비공개, 감사 faq.import 한 줄. 일반 사용자 API에 공개 항목이 보이고 검색이 질문 하나를 찾음.
 */
final class FaqSeedTest extends TestCase
{
    private const SEED = PJG_ROOT . '/resources/faq/seed.ko.json';

    /** @return list<array{category:string, question:string, answer:string}> */
    private function items(): array
    {
        $data = json_decode((string) file_get_contents(self::SEED), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $data['version'] ?? null, 'version 1');
        $this->assertTrue(is_array($data['items'] ?? null) && array_is_list($data['items']));
        return $data['items'];
    }

    public function testSeedIsValidAndSized(): void
    {
        $items = $this->items();
        $keys = [];
        $cats = [];
        foreach ($items as $i => $it) {
            $this->assertSame(['category', 'question', 'answer'], array_keys($it), "항목 {$i} 키");
            $v = FaqService::validate($it, true); // 길이·빈 값이면 예외
            $this->assertSame([$it['category'], $it['question']], [$v['category'], $v['question']], "항목 {$i}: 정리 전후 같음(앞뒤 공백·연속 공백 없음)");
            $keys[] = $v['category'] . "\n" . $v['question'];
            $cats[$v['category']] = true;
        }
        $this->assertSame(count($keys), count(array_unique($keys)), '(분류, 질문) 중복 없음');
        $this->assertTrue(count($cats) >= 6 && count($cats) <= 8, '분류 6~8개: ' . count($cats));
        $this->assertTrue(count($items) >= 25 && count($items) <= 40, '질문 약 30개(25~40): ' . count($items));
    }

    /** 사용자 가이드 ko 절 key → 소제목 앵커 slug 목록(화면 headingSlug와 같은 규칙, {{ui:…}}는 문구로 바꾼 뒤) */
    private function anchors(): array
    {
        $out = [];
        foreach (glob(PJG_ROOT . '/resources/guide/user/ko/*.md') ?: [] as $f) {
            $src = (string) file_get_contents($f);
            if (preg_match('/^key:\s*(\S+)/m', $src, $m) !== 1) {
                continue;
            }
            $used = [];
            $slugs = [];
            preg_match_all('/^#{2,3}\s+(.+)$/m', $src, $hs);
            foreach ($hs[1] as $h) {
                $text = (string) preg_replace_callback('/\{\{ui:([^}]+)\}\}/', fn (array $u): string => $this->uiText($u[1]), $h);
                $text = (string) preg_replace('/\{\{[^}]*\}\}/', '', $text);
                $text = str_replace(['**', '`'], '', $text);
                $base = trim((string) preg_replace('/[^0-9a-z\x{AC00}-\x{D7A3}]+/u', '-', mb_strtolower(\Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text)), '-');
                $base = mb_substr($base, 0, 60) ?: 'section';
                $slug = $base;
                for ($n = 2; isset($used[$slug]); $n++) {
                    $slug = "{$base}-{$n}";
                }
                $used[$slug] = true;
                $slugs[] = $slug;
            }
            $out[$m[1]] = $slugs;
        }
        return $out;
    }

    /** @var array<string, string>|null */
    private ?array $koStrings = null;

    /** @return array<string, string> ko 문구(PHP + JS) 키 → 값 */
    private function ko(): array
    {
        if ($this->koStrings === null) {
            $php = require PJG_ROOT . '/src/Lang/ko.php';
            $js = [];
            $src = (string) file_get_contents(PJG_ROOT . '/public/assets/js/i18n/ko.js');
            preg_match_all("/^\s*(['\"])([a-z0-9_.\-]+)\\1:\s*(['\"])((?:\\\\.|(?!\\3).)*)\\3,?\s*$/mu", $src, $mm, PREG_SET_ORDER);
            foreach ($mm as $x) {
                $js[$x[2]] = stripcslashes($x[4]);
            }
            $this->koStrings = $php + $js;
        }
        return $this->koStrings;
    }

    private function uiText(string $key): string
    {
        return $this->ko()[$key] ?? $key;
    }

    public function testLinksForbiddenAndUiTerms(): void
    {
        $anchors = $this->anchors();
        $this->assertTrue(count($anchors) >= 15, '사용자 가이드 절');
        $values = array_values(array_filter($this->ko(), 'is_string'));
        $bad = [];
        $links = 0;
        foreach ($this->items() as $i => $it) {
            $text = $it['category'] . "\n" . $it['question'] . "\n" . $it['answer'];
            $where = '항목 ' . ($i + 1);
            preg_match_all('/\]\(([^)\s]+)\)/', $it['answer'], $lm);
            foreach ($lm[1] as $href) {
                $links++;
                // FAQ 화면의 링크 판정(faq_core.faqHref)과 같은 글자 규칙 — 밖이면 링크가 아니라 글자로 보인다(한글 소제목 앵커는 퍼센트 인코딩해야 함)
                if (preg_match('#^/(?!/)[A-Za-z0-9/_\-?=&%.\#~]*$#', $href) !== 1) {
                    $bad[] = "{$where}: 화면에서 링크가 되지 않는 주소 {$href}";
                }
                if (preg_match('#^/guide/user\#([a-z0-9-]+)(?:/([^\s)]+))?$#', $href, $g) === 1) {
                    if (!isset($anchors[$g[1]])) {
                        $bad[] = "{$where}: 없는 절 {$href}";
                    } elseif (isset($g[2]) && !in_array(rawurldecode($g[2]), $anchors[$g[1]], true)) {
                        $bad[] = "{$where}: 없는 소제목 {$href}";
                    }
                } elseif (preg_match('#^/guide/errors(\#[A-Za-z0-9_-]+)?$#', $href) !== 1) {
                    $bad[] = "{$where}: 허용하지 않는 링크 {$href}";
                }
            }
            foreach (['/guide/admin' => '관리자 가이드', 'javascript:' => 'javascript:', '{{' => '자리표시자', 'D:\\' => '개발 PC 경로', 'C:\\Users' => '개발 PC 경로', 'data:' => 'data:'] as $needle => $why) {
                if (str_contains($text, $needle)) {
                    $bad[] = "{$where}: {$why}";
                }
            }
            if (preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $text) === 1) {
                $bad[] = "{$where}: 이메일 모양";
            }
            if (preg_match('/[A-Za-z0-9_\-]{40,}/', $text) === 1) {
                $bad[] = "{$where}: 긴 토큰 모양";
            }
            if (preg_match('/<\/?[a-zA-Z][^>]*>/', $text) === 1) {
                $bad[] = "{$where}: 원시 HTML";
            }
            if (preg_match('/!\[/', $it['answer']) === 1) {
                $bad[] = "{$where}: 이미지";
            }
            // 큰따옴표 안 화면 용어 = 실제 ko 문구
            preg_match_all('/"([^"\n]{1,60})"/u', $it['answer'], $qm);
            foreach ($qm[1] as $term) {
                if (!in_array($term, $values, true)) {
                    $bad[] = "{$where}: 화면 문구에 없는 용어 \"{$term}\"";
                }
            }
        }
        $this->assertTrue($links >= 20, '가이드 링크가 대부분의 답변에 있음: ' . $links);
        $this->assertSame([], $bad);
    }

    // ---- 가져오기 도구 ----

    /** @return array{0:string, 1:string} [임시 폴더, config.ini] — 파일 DB(마이그레이션 적용) */
    private function install(bool $withFaq = true): array
    {
        $dir = $this->tempDir('faqseed');
        $dbFile = $dir . '/t.sqlite';
        $db = TestDb::migrated($dbFile);
        if (!$withFaq) {
            $db->pdo()->exec('DROP TABLE faq_items'); // 029 전 DB 흉내
        }
        unset($db);
        $ini = TestApp::writeIni($dir . '/config.ini', ['paths' => ['db_file' => $dbFile, 'work_dir' => $dir . '/work', 'logs_dir' => $dir . '/logs',
            'uploads_dir' => $dir . '/uploads', 'snapshots_dir' => $dir . '/snap'], 'backup' => ['dir' => $dir . '/bk']]);
        return [$dir, $ini];
    }

    /** @return array{0:int, 1:string} */
    private function tool(string $ini, array $args): array
    {
        $p = proc_open([PHP_BINARY, '-d', 'extension=intl', PJG_ROOT . '/tools/faq_import.php', '--config=' . $ini, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        return [proc_close($p), $out];
    }

    private function count(string $dir, string $sql = 'SELECT COUNT(*) FROM faq_items'): int
    {
        return (int) (new Db($dir . '/t.sqlite'))->value($sql);
    }

    public function testImportToolIsIdempotentAndAllOrNothing(): void
    {
        $n = count($this->items());
        [$dir, $ini] = $this->install();
        [$code, $out] = $this->tool($ini, ['--dry-run']);
        $this->assertSame(0, $code, $out);
        $this->assertContains("추가 {$n}건", $out);
        $this->assertSame(0, $this->count($dir), 'dry-run은 쓰지 않음');
        [$code, $out] = $this->tool($ini, []);
        $this->assertSame(0, $code, $out);
        $this->assertSame($n, $this->count($dir));
        $this->assertSame($n, $this->count($dir, 'SELECT COUNT(*) FROM faq_items WHERE is_published = 1'), '기본은 공개');
        $this->assertSame(range(0, $n - 1), array_map('intval', array_column((new Db($dir . '/t.sqlite'))->all('SELECT sort_order FROM faq_items ORDER BY id'), 'sort_order')), '파일 순서 = 표시 순서');
        [$code, $out] = $this->tool($ini, []);
        $this->assertSame(0, $code, $out);
        $this->assertContains("추가 0건, 건너뜀(이미 있음) {$n}건", $out);
        $this->assertSame($n, $this->count($dir), '두 번째 실행 0건');
        $audit = (new Db($dir . '/t.sqlite'))->all("SELECT actor_label, details FROM audit_log WHERE action = 'faq.import'");
        $this->assertSame(1, count($audit), '감사 한 줄(추가가 있었던 실행만)');
        $this->assertSame('cli', $audit[0]['actor_label']);
        $this->assertSame(['added' => $n, 'skipped' => 0, 'published' => true, 'source' => 'seed.ko.json'], json_decode((string) $audit[0]['details'], true));
        $this->assertSame(0, $this->count($dir, "SELECT COUNT(*) FROM audit_log WHERE action = 'faq.create'"), '항목마다 기록하지 않음');
    }

    public function testPartialExistingDraftAndFailures(): void
    {
        $items = $this->items();
        [$dir, $ini] = $this->install();
        // 관리자가 화면에서 이미 하나를 만들고 답변을 고쳐 둠 → 그대로(덮어쓰지 않음), 나머지만 추가
        $db = new Db($dir . '/t.sqlite');
        $db->execute("INSERT INTO faq_items (category, question, answer, search_text, sort_order, is_published, version, created_at, updated_at) VALUES (?, ?, '관리자가 고친 답변', 'x', 0, 1, 3, '2026-10-01T00:00:00Z', '2026-10-01T00:00:00Z')",
            [$items[0]['category'], $items[0]['question']]);
        unset($db);
        [$code, $out] = $this->tool($ini, ['--draft']);
        $this->assertSame(0, $code, $out);
        $this->assertContains('추가 ' . (count($items) - 1) . '건, 건너뜀(이미 있음) 1건', $out);
        $this->assertSame('관리자가 고친 답변', (string) (new Db($dir . '/t.sqlite'))->value('SELECT answer FROM faq_items WHERE question = ?', [$items[0]['question']]));
        $this->assertSame(count($items) - 1, $this->count($dir, 'SELECT COUNT(*) FROM faq_items WHERE is_published = 0'), '--draft면 새 항목 모두 비공개');
        // 검증 실패(질문 201자) → 0건·종료 코드 1
        [$dir2, $ini2] = $this->install();
        $bad = $items;
        $bad[3]['question'] = str_repeat('가', 201);
        file_put_contents($dir2 . '/bad.json', json_encode(['version' => 1, 'items' => $bad], JSON_UNESCAPED_UNICODE));
        [$code, $out] = $this->tool($ini2, ['--file=' . $dir2 . '/bad.json']);
        $this->assertSame(1, $code, $out);
        $this->assertContains('항목 4: invalid_faq_length (question)', $out);
        $this->assertSame(0, $this->count($dir2), '부분 등록 없음');
        // 파일 안 중복 → 1
        $dup = [$items[0], $items[1], $items[0]];
        file_put_contents($dir2 . '/dup.json', json_encode(['version' => 1, 'items' => $dup], JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $this->tool($ini2, ['--file=' . $dir2 . '/dup.json'])[0]);
        $this->assertSame(0, $this->count($dir2));
        // 형식 오류 파일 → 2
        file_put_contents($dir2 . '/broken.json', '{"version":2}');
        $this->assertSame(2, $this->tool($ini2, ['--file=' . $dir2 . '/broken.json'])[0]);
        // 029 없음 → 2, 쓰지 않음
        [$dir3, $ini3] = $this->install(false);
        [$code, $out] = $this->tool($ini3, []);
        $this->assertSame(2, $code, $out);
        $this->assertContains('php tools/migrate.php', $out);
        $this->assertSame(0, $this->count($dir3, "SELECT COUNT(*) FROM sqlite_master WHERE name = 'faq_items'"));
    }

    public function testImportedItemsVisibleToUsers(): void
    {
        $items = $this->items();
        $dir = $this->tempDir('faqseed_api');
        $app = TestApp::make($dir, ['paths' => ['work_dir' => $dir . '/work']]);
        TestDb::user($app->db(), 'kim', 'user');
        $r = $app->faq()->import(\PJGuardian\Services\Actor::cli(), $items, false, false, 'seed.ko.json');
        $this->assertSame([count($items), 0, []], [count($r['added']), count($r['skipped']), $r['errors']]);
        $c = new TestClient($app);
        $this->assertSame(200, $c->login('kim', 'Passw0rd!x')->status);
        $list = $c->get('/api/v1/faq')->decoded()['data'];
        $this->assertSame(count($items), $list['total']);
        // 검색: 첫 질문의 고유한 낱말 둘로 정확히 그 질문을 찾음
        $q = $items[0]['question'];
        $found = $c->get('/api/v1/faq', ['q' => $q])->decoded()['data'];
        $this->assertTrue(in_array($q, array_column($found['items'], 'question'), true), '질문 전체로 검색');
        $this->assertSame(1, $found['total'], '질문 하나');
    }
}
