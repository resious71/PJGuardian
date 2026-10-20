<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Services\Archive\UnzipException;
use PJGuardian\Support\Lang;
use PJGuardian\Tests\TestCase;

/**
 * 문자열 리소스 누락 방지: 코드가 참조하는 키(Lang::get('...'), 템플릿의 $t('...'), new ApiException(상태, '코드'))가
 * src/Lang/ko.php에 모두 있어야 한다.
 */
final class LangTest extends TestCase
{
    public function testPlaceholders(): void
    {
        $this->assertSame('요청 ID: abc', Lang::get('page.request_id', ['id' => 'abc']));
        $this->assertSame('no.such.key', Lang::get('no.such.key'));
    }

    public function testEveryReferencedKeyExists(): void
    {
        $missing = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(PJG_ROOT . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php' || str_contains(str_replace('\\', '/', $f->getPathname()), '/Lang/')) {
                continue;
            }
            $code = (string) file_get_contents($f->getPathname());
            preg_match_all("/Lang::get\\('([a-z0-9_.]+)'/", $code, $a);
            preg_match_all("/new ApiException\\(\\s*\\d+\\s*,\\s*'([a-z0-9_]+)'/", $code, $b);
            preg_match_all("/Response::error\\(\\s*\\d+\\s*,\\s*'([a-z0-9_]+)'/", $code, $c);
            // 템플릿(src/Views)의 $t('키'). 'prefix_' . $x 처럼 이어 붙이는 키는 아래 testDynamicTemplateKeys에서
            preg_match_all("/\\\$t\\('([a-z0-9_.]+)'\\s*[,)]/", $code, $d);
            $keys = array_merge($a[1], $d[1], array_map(static fn (string $k): string => 'error.' . $k, array_merge($b[1], $c[1])));
            foreach ($keys as $k) {
                if (!Lang::has($k)) {
                    $missing[] = $k . ' (' . $f->getFilename() . ')';
                }
            }
        }
        $this->assertSame([], array_values(array_unique($missing)), 'src/Lang/ko.php에 없는 문자열 키');
    }

    public function testDynamicTemplateKeys(): void
    {
        foreach (['admin', 'user', 'guest'] as $as) {
            $this->assertTrue(Lang::has('styleguide.as_' . $as), "styleguide.as_{$as} 없음");
        }
    }

    /** 제외 패턴 거부 이유 코드(ExcludeMatcher::validatePattern, normalizePatterns)마다 문구가 있어야 한다 */
    public function testExcludeReasonKeys(): void
    {
        foreach (['empty', 'too_long', 'control_char', 'backslash', 'absolute', 'dot_segment', 'empty_segment', 'bad_double_star', 'unsupported_syntax', 'not_string'] as $reason) {
            $this->assertTrue(Lang::has('exclude.invalid.' . $reason), "exclude.invalid.{$reason} 없음");
        }
    }

    /** 해제 오류 코드·가이드 키·사유 코드(SafeUnzip)와 업로드 경고 코드마다 문구가 있어야 한다(P13) */
    public function testUnzipErrorKeys(): void
    {
        foreach (UnzipException::GUIDE_KEYS as $code => $guideKey) {
            $this->assertTrue(Lang::has('error.' . $code), "error.{$code} 없음");
            $this->assertTrue(Lang::has($guideKey), "가이드 키 {$guideKey} 없음");
        }
        $reasons = ['parent_dir', 'absolute', 'drive_letter', 'backslash', 'control_char', 'empty_name', 'symlink', 'nested_zip', 'outside_dest',
            'reserved', 'forbidden_char', 'trailing_dot_space', 'path_too_long', 'conflict', 'size_mismatch', 'write_failed', 'open_failed'];
        foreach ($reasons as $r) {
            $this->assertTrue(Lang::has('unzip.reason.' . $r), "unzip.reason.{$r} 없음");
        }
        foreach (['no_target', 'top_folder_missing', 'top_folder_kept'] as $w) {
            $this->assertTrue(Lang::has('upload.warning.' . $w), "upload.warning.{$w} 없음");
        }
    }

    public function testRouterErrorCodesHaveMessages(): void
    {
        foreach (['not_found', 'method_not_allowed', 'internal', 'https_required', 'invalid_pagination', 'invalid_json', 'config_invalid'] as $code) {
            $this->assertTrue(Lang::has('error.' . $code), "error.{$code} 없음");
        }
    }
}
