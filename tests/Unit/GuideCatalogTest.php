<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Services\Guide\GuideCatalog;
use PJGuardian\Services\Guide\GuideContent;
use PJGuardian\Support\Config;
use PJGuardian\Support\ErrorCatalog;
use PJGuardian\Support\GuideLinks;
use PJGuardian\Support\Icons;
use PJGuardian\Support\Lang;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\TestCase;

/**
 * 가이드 본문 체계(P29): 실제 본문 전체 검사(머리말·key·ko/en 같은 key 집합·related·guide: 링크·자리표시자·원시 HTML·위험한 링크·이미지),
 * 오류 카탈로그·화면 도움말 링크의 가이드 키가 ko·en 절로 풀림, 머리말 해석 규칙(필수·형식·중복), 자리표시자 채우기(설정을 바꾸면 값이 따라 바뀜),
 * 검사기가 나쁜 본문을 실제로 잡는지(임시 폴더), 번역 없는 절의 한국어 대체, 검색(최소 2자·대소문자·한글·0건), 관리자 가이드는 웹 루트 밖.
 */
final class GuideCatalogTest extends TestCase
{
    private function content(string $root, array $configOver = []): GuideContent
    {
        $cfg = TestApp::config($configOver);
        $map = json_decode((string) file_get_contents(PJG_ROOT . '/public/assets/icons/icons.json'), true);
        return new GuideContent(new GuideCatalog($root), $cfg, new Icons($map, '', false), '9.9.9', static fn (string $t): ?string => $t === 'ruff' ? '0.15.1' : null, PJG_ROOT);
    }

    private function write(string $root, string $rel, string $text): void
    {
        @mkdir(dirname($root . '/' . $rel), 0777, true);
        file_put_contents($root . '/' . $rel, $text);
    }

    private static function md(string $key, int $order, string $body, string $title = '제목', string $extra = '', string $aud = 'user'): string
    {
        return "---\nkey: {$key}\ntitle: {$title}\norder: {$order}\nsummary: 요약\nkeywords: 가, 나\n{$extra}audience: {$aud}\n---\n{$body}";
    }

    public function testRealGuideContentIsValid(): void
    {
        $c = $this->content(PJG_ROOT . '/resources/guide');
        $errors = $c->validate();
        $this->assertSame([], $errors, '가이드 본문 검사: ' . implode(' | ', array_slice($errors, 0, 15)));
        $ko = $c->catalog()->keys('user', 'ko');
        $this->assertTrue(count($ko) >= 15, '사용자 가이드 절 ' . count($ko) . '개');
        $this->assertSame($ko, $c->catalog()->keys('user', 'en'), 'ko·en 같은 key·같은 순서');
        // P29C(의도된 단언 변경): 관리자 가이드 본문 23절(P29는 빈 틀 overview 하나) — ko·en 같은 집합은 validate()가 검사
        // X14(의도된 단언 변경): 24절 — install 뒤에 deploy-bundle(배포 묶음) 분리, ko·en 같은 순서
        // P30(의도된 단언 변경): 25절 — monitoring 뒤에 audit-log(감사 로그) 분리
        $this->assertSame($c->catalog()->keys('admin', 'ko'), $c->catalog()->keys('admin', 'en'), '관리자 ko·en 같은 key·같은 순서');
        $this->assertSame(['overview', 'install', 'deploy-bundle', 'worker', 'tools-bin', 'accounts', 'groups-access', 'account-csv', 'external-auth', 'auth-ldap', 'auth-oidc', 'auth-saml', 'settings', 'retention-disk',
            'backup-restore', 'repository', 'schedule', 'projects-admin', 'rules-admin', 'notifications-admin', 'monitoring', 'admin-mail', 'audit-log', 'security', 'troubleshooting', 'linux-ops'], $c->catalog()->keys('admin', 'ko'), '관리자 가이드 절');
        // 관리자 가이드 원본은 웹 루트(public/) 밖
        $this->assertFalse(is_dir(PJG_ROOT . '/public/guide') || glob(PJG_ROOT . '/public/**/*.md') !== []);
        $this->assertTrue(!str_starts_with(str_replace('\\', '/', (string) realpath(PJG_ROOT . '/resources/guide/admin')), str_replace('\\', '/', (string) realpath(PJG_ROOT . '/public'))));
    }

    public function testErrorCatalogAndHelpLinksResolveToSections(): void
    {
        $c = $this->content(PJG_ROOT . '/resources/guide');
        foreach (['ko', 'en'] as $lang) {
            $keys = $c->catalog()->keys('user', $lang);
            foreach (['upload', 'analysis', 'sso'] as $kind) {
                foreach (ErrorCatalog::codes($kind) as $code) {
                    $d = ErrorCatalog::describe($kind, $code);
                    $this->assertTrue(in_array($d['guide_key'], $keys, true), "{$lang} {$kind}.{$code} → {$d['guide_key']}");
                    $this->assertTrue($d['title'] !== "failure.{$kind}.{$code}.title", "{$kind}.{$code} 제목 문구");
                }
            }
            foreach (GuideLinks::SCREENS as $tpl => [$aud, $key]) {
                $this->assertTrue(in_array($key, $c->catalog()->keys($aud, $lang), true), "{$lang} 화면 {$tpl} → {$aud}#{$key}");
                $this->assertTrue(is_file(PJG_ROOT . '/src/Views/' . $tpl . '.php'), "화면 템플릿 {$tpl} 있음");
            }
            // P29C: 설정 관리 카테고리 탭 → 관리자 가이드 절(모든 편집 묶음에 하나씩)
            $this->assertSame([], array_values(array_diff(\PJGuardian\Support\ConfigSchema::EDITABLE_GROUPS, array_keys(GuideLinks::CONFIG_TABS))), '모든 설정 탭에 절');
            foreach (GuideLinks::CONFIG_TABS as $tab => $key) {
                $this->assertTrue(in_array($key, $c->catalog()->keys('admin', $lang), true), "{$lang} 설정 탭 {$tab} → admin#{$key}");
            }
        }
        $this->assertSame(null, ErrorCatalog::describe('upload', 'nope')['guide_key'], '모르는 코드는 키 없음');
    }

    /**
     * P29C 정확성 장치(관리자 가이드 — 이후 단계에서 가이드를 고칠 때 낡은 설명을 막는다):
     * - 본문의 백틱 식별자: 점 이름(`config.update`, `disk.warn_percent`)은 설정 키(ConfigSchema) 또는 코드에 실제로 쓰는 문자열(감사 작업 이름 등),
     *   밑줄 이름(`repo_auth_failed`)은 오류 코드 문서·마이그레이션 식별자·코드 문자열 중 하나여야 한다. 파일 이름(확장자)·경로·명령은 건너뛴다.
     * - SSO 오류 코드(E-SSO-*)는 오류 카탈로그에 있어야 한다.
     * - 금지어: 개발 PC·사용자 폴더 경로, 이메일 주소 모양, 토큰처럼 보이는 긴 문자열(사용자 가이드에도 같은 규칙).
     */
    public function testGuideIdentifiersAndForbiddenStrings(): void
    {
        $known = [];
        foreach (\PJGuardian\Support\ConfigSchema::definition() as $section => $keys) {
            foreach (array_keys($keys) as $k) {
                $known["{$section}.{$k}"] = true;
                $known[$k] = true;
            }
        }
        // 코드 문자열: src/(문구 리소스 제외)와 tools/의 PHP(명령줄 도구의 감사 이름 cli.* 등)
        $files = iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(PJG_ROOT . '/src', \FilesystemIterator::SKIP_DOTS)), false);
        foreach (glob(PJG_ROOT . '/tools/*.php') as $tool) {
            $files[] = new \SplFileInfo($tool);
        }
        foreach ($files as $file) {
            if ($file->getExtension() === 'php' && !str_contains(str_replace('\\', '/', $file->getPathname()), '/Lang/')) {
                preg_match_all("/'([a-z][a-z0-9_]*(?:[._][a-z0-9_]+)+)'/", (string) file_get_contents($file->getPathname()), $m);
                foreach ($m[1] as $id) {
                    $known[$id] = true;
                }
            }
        }
        foreach (glob(PJG_ROOT . '/migrations/*.sql') as $sql) {
            preg_match_all('/\b[a-z][a-z0-9]*(?:_[a-z0-9]+)+\b/', (string) file_get_contents($sql), $m);
            foreach ($m[0] as $id) {
                $known[$id] = true;
            }
        }
        preg_match_all('/`([a-z][a-z0-9_]*(?:[._][a-z0-9_]+)+)`/', (string) file_get_contents(PJG_ROOT . '/docs/error_codes.md'), $m);
        foreach ($m[1] as $id) {
            $known[$id] = true;
        }
        // php.ini 지시어(설치 절이 PHP 설정 값을 말할 때 — 이 앱의 식별자가 아님)
        foreach (['upload_max_filesize', 'post_max_size', 'max_execution_time', 'max_input_time', 'memory_limit', 'max_file_uploads', 'default_socket_timeout'] as $ini) {
            $known[$ini] = true;
        }
        $sso = ErrorCatalog::codes('sso');
        $bad = [];
        foreach (['user', 'admin'] as $aud) {
            foreach (['ko', 'en'] as $lang) {
                foreach (glob(PJG_ROOT . "/resources/guide/{$aud}/{$lang}/*.md") as $md) {
                    $where = "{$aud}/{$lang}/" . basename($md);
                    $text = (string) file_get_contents($md);
                    // 금지어
                    if (preg_match('/\b[A-Za-z]:\\\\(?!xampp\\\\|PJGuardian\b)|\\\\Users\\\\|\/home\/[a-z]|\/Users\//', $text, $pm) === 1) {
                        $bad[] = "{$where}: 개발 PC·사용자 폴더 경로 {$pm[0]}";
                    }
                    if (preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+\.[A-Za-z.]{2,}/', $text, $pm) === 1) {
                        $bad[] = "{$where}: 이메일 주소 모양 {$pm[0]}";
                    }
                    if (preg_match('/(?<![A-Za-z0-9\/._-])[A-Za-z0-9+\/_-]{40,}={0,2}(?![A-Za-z0-9\/._-])/', $text, $pm) === 1) {
                        $bad[] = "{$where}: 토큰처럼 보이는 긴 문자열 {$pm[0]}";
                    }
                    if ($aud !== 'admin') {
                        continue;
                    }
                    preg_match_all('/E-SSO-[A-Z]+(?:-[A-Z]+)*/', $text, $em);
                    foreach (array_unique($em[0]) as $code) {
                        if (!in_array($code, $sso, true)) {
                            $bad[] = "{$where}: 없는 SSO 오류 코드 {$code}";
                        }
                    }
                    preg_match_all('/`([^`\n]+)`/', $text, $tm);
                    foreach (array_unique($tm[1]) as $tok) {
                        if (preg_match('/^[a-z][a-z0-9_]*(?:[._][a-z0-9_]+)+$/', $tok) !== 1) {
                            continue; // 명령·경로·대문자·공백 있는 글은 대상 아님
                        }
                        if (preg_match('/\.(ini|php|md|json|sqlite|pjgbak|pjgkey|zip|toml|lock|log|txt|example|mjs|js|sql|exe|sh|service|bat|ps1|csv|pem|crt|key|xml)$/', $tok) === 1) {
                            continue; // 파일 이름
                        }
                        if (!isset($known[$tok])) {
                            $bad[] = "{$where}: 모르는 식별자 `{$tok}`(설정 키·감사 작업 이름·오류 코드·DB 식별자가 아님)";
                        }
                    }
                }
            }
        }
        $this->assertSame([], $bad, '가이드 식별자·금지어');
    }

    public function testFrontMatterRules(): void
    {
        $ok = GuideCatalog::parse(self::md('ab-c', 3, "## 소제목\n본문\n", '제목', "related: x, y\n"));
        $this->assertSame(['ab-c', 3, ['가', '나'], ['x', 'y'], 'user'], [$ok['key'], $ok['order'], $ok['keywords'], $ok['related'], $ok['audience']]);
        $bad = [
            '머리말 없음' => "## 본문\n",
            'key 형식' => self::md('Bad_Key', 1, "x\n"),
            'order 정수' => str_replace('order: 1', 'order: first', self::md('a', 1, "x\n")),
            'audience' => self::md('a', 1, "x\n", '제목', '', 'guest'),
            'title 없음' => str_replace("title: 제목\n", '', self::md('a', 1, "x\n")),
            '중복' => str_replace("summary: 요약\n", "summary: 요약\nsummary: 또\n", self::md('a', 1, "x\n")),
            '모르는 항목' => str_replace("summary: 요약\n", "summary: 요약\nauthor: x\n", self::md('a', 1, "x\n")),
        ];
        foreach ($bad as $label => $text) {
            $this->assertThrows(\InvalidArgumentException::class, fn () => GuideCatalog::parse($text, 'f.md'), '', $label);
        }
    }

    public function testValidatorCatchesBadContentAndFallbackWorks(): void
    {
        $root = $this->tempDir('guide');
        $this->write($root, 'user/ko/01_a.md', self::md('a', 1, "## 가\n[다음](guide:b) {{limit:upload.max_zip_size}} {{icon:action.upload}} {{version:ruff}} {{ui:guide.tab.user}}\n"));
        $this->write($root, 'user/en/01_a.md', self::md('a', 1, "## A\n[next](guide:b) {{limit:upload.max_zip_size}} {{icon:action.upload}} {{version:ruff}} {{ui:guide.tab.user}}\n", 'Title'));
        $this->write($root, 'user/ko/02_b.md', self::md('b', 2, "## 나\n본문\n", '둘째'));
        $this->write($root, 'user/en/02_b.md', self::md('b', 2, "## B\nbody\n", 'Second'));
        $c = $this->content($root);
        $this->assertSame([], $c->validate());
        unlink($root . '/user/en/02_b.md');
        // 영어에 없는 절은 한국어로 대신(fallback) — 검사는 실패
        $en = $c->catalog()->sections('user', 'en');
        $this->assertSame([['a', false, 'en'], ['b', true, 'ko']], array_map(static fn (array $s): array => [$s['key'], $s['fallback'], $s['lang']], $en));
        $this->assertTrue(str_contains(implode('|', $c->validate()), 'ko·en key 집합이 다름'));
        $this->write($root, 'user/en/02_b.md', self::md('b', 2, "## B\nbody\n", 'Second'));
        $this->assertSame([], $c->validate());
        // 나쁜 본문들
        $cases = [
            '원시 HTML 금지' => "<script>alert(1)</script>\n",
            '모르는 자리표시자' => "{{secret:x}}\n",
            '설정 키 upload.no_such 없음' => "{{limit:upload.no_such}}\n",
            '아이콘 nope.none 없음' => "{{icon:nope.none}}\n",
            '버전 이름 git' => "{{version:git}}\n",
            '문구 키 no.such.key' => "{{ui:no.such.key}}\n",
            '허용하지 않는 링크' => "[x](javascript:alert(1))\n",
            'http' => "[x](http://example.com)\n",
            '없는 key' => "[x](guide:zzz)\n",
            '이미지는 guide-img:' => "![a](https://evil.example/x.png)\n",
            'nothere 파일 없음' => "![a](guide-img:nothere)\n",
            '대체 텍스트' => "![](guide-img:x)\n",
            '# 제목 금지' => "# 큰 제목\n",
            '표·인용' => "| a | b |\n",
        ];
        foreach ($cases as $want => $body) {
            $this->write($root, 'user/ko/03_c.md', self::md('c', 3, $body));
            $this->write($root, 'user/en/03_c.md', self::md('c', 3, $body));
            $errs = implode(' | ', $c->validate());
            $this->assertTrue(str_contains($errs, $want), "{$want}: {$errs}");
        }
        // ko·en 자리표시자가 다르면 실패, 쓰이지 않는 이미지·SVG·크기 초과
        $this->write($root, 'user/ko/03_c.md', self::md('c', 3, "{{limit:upload.max_zip_size}}\n"));
        $this->write($root, 'user/en/03_c.md', self::md('c', 3, "plain\n"));
        $this->assertTrue(str_contains(implode('|', $c->validate()), 'ko·en의 자리표시자'));
        $this->write($root, 'user/en/03_c.md', self::md('c', 3, "{{limit:upload.max_zip_size}}\n"));
        $this->write($root, 'user/img/orphan.png', self::png(10, 10));
        $this->write($root, 'user/img/bad.svg', '<svg/>');
        $this->write($root, 'user/img/wide.png', self::png(1700, 10));
        $errs = implode(' | ', $c->validate());
        foreach (['orphan.png: 본문에서 쓰지 않는 이미지', 'bad.svg: 파일 이름·형식', 'wide.png: 가로 1700px'] as $want) {
            $this->assertTrue(str_contains($errs, $want), "{$want}: {$errs}");
        }
    }

    public function testPlaceholdersFollowSettingsAndLanguage(): void
    {
        $root = $this->tempDir('guide2');
        $body = "{{limit:upload.max_zip_size}} / {{limit:auth.session_idle}} / {{limit:auth.lockout_max_failures}} / {{version:app}} / {{version:ruff}} / {{version:eslint}} / {{ui:guide.tab.errors}} / {{icon:action.upload}}\n";
        $this->write($root, 'user/ko/01_a.md', self::md('a', 1, $body));
        $this->write($root, 'user/en/01_a.md', self::md('a', 1, $body, 'A'));
        $c = $this->content($root, ['upload' => ['max_zip_size' => '1GB'], 'auth' => ['session_idle' => '30m', 'lockout_max_failures' => '5']]);
        $ko = $c->rendered('user', 'ko')[0]['body'];
        $this->assertSame("1 GiB / 30분 / 5 / 9.9.9 / 0.15.1 / 설치 안 됨 / 오류 코드 / {{icon:action.upload}}\n", $ko);
        $en = $c->rendered('user', 'en')[0]['body'];
        $this->assertSame("1 GiB / 30 minutes / 5 / 9.9.9 / 0.15.1 / Not installed / Error codes / {{icon:action.upload}}\n", $en);
        $c2 = $this->content($root, ['upload' => ['max_zip_size' => '2GB'], 'auth' => ['session_idle' => '1h', 'lockout_max_failures' => '7']]);
        $this->assertSame("2 GiB / 1시간 / 7 / ", substr($c2->rendered('user', 'ko')[0]['body'], 0, strlen("2 GiB / 1시간 / 7 / ")), '설정을 바꾸면 가이드 값이 따라 바뀜');
        $this->assertSame('ko', Lang::locale(), '채운 뒤 화면 언어 되돌림');
        $this->assertSame(['1.5 GiB', '512 MiB', '100 B'], [GuideContent::size(1610612736), GuideContent::size(536870912), GuideContent::size(100)]);
        // 아이콘 바로 뒤 단추 이름: 아이콘은 이름 글자 없이(.bare — "⬆ 업로드 업로드 시작" 반복 방지), 떨어져 있으면 그대로
        $this->assertSame('{{icon:action.upload.bare}} 오류 코드 / {{icon:action.upload}} x {{ui:no.such}}', $c->fill('{{icon:action.upload}} {{ui:guide.tab.errors}} / {{icon:action.upload}} x {{ui:no.such}}', 'ko'));
        // JS 문구(PHP에 없는 키)도 ui로
        $this->assertSame(['맨 위로', 'Back to top'], [$c->ui('guide.top', 'ko'), $c->ui('guide.top', 'en')]);
    }

    public function testSearch(): void
    {
        $root = $this->tempDir('guide3');
        $this->write($root, 'user/ko/01_a.md', self::md('a', 1, "## 업로드\nZIP 파일을 올립니다. zip은 두 번.\n", '업로드 방법'));
        $this->write($root, 'user/ko/02_b.md', self::md('b', 2, "## 결함\n상태를 바꿉니다 <b>.\n", '결함'));
        $c = $this->content($root);
        $this->assertSame([], $c->search('user', 'ko', 'z'), '1자는 검색하지 않음');
        $r = $c->search('user', 'ko', 'Zip');
        $this->assertSame([['a', 2]], array_map(static fn (array $x): array => [$x['key'], $x['hits']], $r), '대소문자 무시');
        $this->assertSame(['a'], array_column($c->search('user', 'ko', '업로드'), 'key'), '한글');
        $this->assertSame([], $c->search('user', 'ko', '없는말'), '0건');
        $this->assertSame(['b'], array_column($c->search('user', 'ko', '<b>'), 'key'), '특수문자는 글자 그대로(정규식·HTML 아님)');
        $this->assertSame([], $c->search('admin', 'ko', '업로드'), '관리자 가이드는 따로');
    }

    private static function png(int $w, int $h): string
    {
        $raw = '';
        for ($y = 0; $y < $h; $y++) {
            $raw .= "\0" . str_repeat("\xff\xff\xff", $w);
        }
        $chunk = static fn (string $t, string $d): string => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0)) . $chunk('IDAT', (string) gzcompress($raw)) . $chunk('IEND', '');
    }
}
