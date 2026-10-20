<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * 관리자 화면에 내보내는 문자열에서 비밀값과 서버 경로를 가린다(P20A: 로그 보기, 시작 명령 출력, 점검 화면).
 *
 * 1. 비밀값: Logger::maskString(로그 마스킹 규칙 재사용: password=…, token: …, Authorization, URL 자격 증명).
 * 2. 알려진 폴더(앱 루트, data 폴더들, bin, PHP·임시 폴더 …)를 이름표(<app>, <work> …)로 바꾼다. 긴 경로부터 바꾼다.
 * 3. 남은 절대 경로(Windows 드라이브 경로, \\서버\공유, 흔한 유닉스 최상위 폴더로 시작하는 경로)는 <path>로 바꾼다.
 * JSON 로그 줄에서는 경로의 역슬래시가 두 번(\\) 나오므로 그 형태도 함께 바꾼다.
 */
final class Redact
{
    public const PATH = '<path>';

    /** @param array<string, string> $roots 이름표 → 폴더 경로 */
    public function __construct(private readonly array $roots)
    {
    }

    public function text(string $s): string
    {
        $s = Logger::maskString($s);
        $pairs = [];
        foreach ($this->roots as $label => $dir) {
            $dir = rtrim(str_replace('\\', '/', $dir), '/');
            if (strlen($dir) < 3) {
                continue;
            }
            foreach ([$dir, str_replace('/', '\\', $dir), str_replace('/', '\\\\', $dir), str_replace('/', '\\/', $dir)] as $form) {
                $pairs[$form] = '<' . $label . '>';
            }
        }
        uksort($pairs, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($pairs as $from => $to) {
            $s = (string) preg_replace('/' . preg_quote($from, '/') . '/i', $to, $s);
        }
        // 드라이브 경로 C:\… C:/… C:\\… (JSON), UNC \\server\share
        $s = (string) preg_replace('#\b[A-Za-z]:(?:\\\\\\\\|\\\\|/)[^\s"\',;<>|]*#', self::PATH, $s);
        $s = (string) preg_replace('#(?<![\w:/\\\\])\\\\\\\\[A-Za-z0-9._-]+\\\\[^\s"\',;<>|]*#', self::PATH, $s);
        // 유닉스 절대 경로(흔한 최상위 폴더). API 경로(/api/…)·URL은 건드리지 않는다
        $s = (string) preg_replace('#(?<![\w.:/<>-])(?:\\\\?/)(?:home|usr|var|opt|etc|tmp|srv|mnt|root|data|run|proc|Users|private|Library|Applications)(?:\\\\?/[^\s"\',;<>|]*)?#', self::PATH, $s);
        return $s;
    }

    /** @param list<string> $lines @return list<string> */
    public function lines(array $lines): array
    {
        return array_map($this->text(...), $lines);
    }
}
