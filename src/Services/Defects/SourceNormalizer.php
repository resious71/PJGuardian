<?php

declare(strict_types=1);

namespace PJGuardian\Services\Defects;

/**
 * 결함 시그니처의 정규화 규칙(명세 6.1, 19.10 P04). 프로토타입 tools/proto/signature.php를 다시 썼다(require 없음).
 *
 * 시그니처 = SHA-256(정규화 경로 \0 규칙 키 \0 정규화 코드). 같은 파일·같은 시그니처는 등장 순서 순번(0, 1, 2…)으로 구분한다.
 *
 * - 경로: 분석 루트 기준 상대 경로, '/' 구분, './'·빈 조각 제거, '..'는 오류, 유니코드 NFC. 대소문자는 바꾸지 않는다.
 *   최상위 폴더는 해제 단계(P13)에서 이미 한 번 제거된 경로를 그대로 쓴다(여기서 다시 제거하지 않음).
 * - 코드: 소스 파일에서 line ~ end_line을 직접 읽는다(end_line이 없거나 line보다 작으면 line만, 파일 끝을 넘는 줄은 빈 문자열).
 *   BOM 제거 → 줄 나누기(도구 기준: ruff는 \r\n·\r·\n, ESLint는 여기에 U+2028·U+2029) → 줄마다 앞뒤 공백 제거·연속 공백 하나로
 *   (공백 = ASCII 공백·탭·VT·FF만) → LF로 잇는다.
 * - 인코딩: 유효한 UTF-8이면 NFC로 정규화한다. **UTF-8이 아니면(예: CP949) 변환하지 않고 바이트 그대로** 쓴다(결정적이고,
 *   CP949 추정 변환은 오판할 수 있다). 이런 파일의 줄 나누기는 ASCII 줄바꿈만 본다. 파일을 읽을 수 없으면 코드는 빈 문자열.
 * - 정규식 주의: `\v`·`\s`를 `/u` 없이 쓰지 않는다(`\v`가 한글 안의 0x85 바이트를 공백으로 봐 글자를 깨뜨린 P04 버그). 바이트로 쓴다.
 */
final class SourceNormalizer
{
    private const WS = " \t\x0B\x0C";

    public static function path(string $rel): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $rel)) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                throw new \InvalidArgumentException('경로에 .. 가 있음');
            }
            $parts[] = $seg;
        }
        $path = implode('/', $parts);
        $nfc = \Normalizer::normalize($path, \Normalizer::FORM_C);
        return is_string($nfc) ? $nfc : $path;
    }

    /**
     * 디스크의 실제 파일 경로. 결과 파일의 경로는 NFC인데, 해제 단계를 거치지 않은 작업 디렉토리(시험, 저장소 가져오기)에는
     * NFD 이름이 있을 수 있어 NTFS에서는 다른 파일이 된다. NFC → NFD 순으로 찾고, 없으면 null.
     */
    public static function onDisk(string $root, string $rel): ?string
    {
        $p = $root . '/' . $rel;
        if (is_file($p)) {
            return $p;
        }
        $nfd = \Normalizer::normalize($rel, \Normalizer::FORM_D);
        if (is_string($nfd) && $nfd !== $rel && is_file($root . '/' . $nfd)) {
            return $root . '/' . $nfd;
        }
        return null;
    }

    /**
     * 파일을 도구 기준 줄로 나눈다.
     *
     * @return array{lines: list<string>, utf8: bool}
     */
    public static function readLines(string $file, string $tool): array
    {
        $bytes = @file_get_contents($file);
        if ($bytes === false) {
            return ['lines' => [], 'utf8' => true];
        }
        return self::splitLines($bytes, $tool);
    }

    /** @return array{lines: list<string>, utf8: bool} */
    public static function splitLines(string $bytes, string $tool): array
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }
        $utf8 = mb_check_encoding($bytes, 'UTF-8');
        if ($utf8) {
            $nfc = \Normalizer::normalize($bytes, \Normalizer::FORM_C);
            if (is_string($nfc)) {
                $bytes = $nfc;
            }
        }
        $pattern = ($tool === 'eslint' && $utf8) ? '/\r\n|\r|\n|\x{2028}|\x{2029}/u' : '/\r\n|\r|\n/';
        $lines = preg_split($pattern, $bytes);
        return ['lines' => $lines === false ? [$bytes] : $lines, 'utf8' => $utf8];
    }

    /** @param list<string> $lines */
    public static function normalizeLines(array $lines): string
    {
        $out = [];
        foreach ($lines as $line) {
            $out[] = trim((string) preg_replace('/[ \t\x0B\x0C]+/', ' ', $line), self::WS);
        }
        return implode("\n", $out);
    }

    /** @param list<string> $lines 파일의 줄(readLines) */
    public static function codeOf(array $lines, int $line, ?int $endLine): string
    {
        $start = max(1, $line);
        $end = $endLine !== null && $endLine >= $start ? $endLine : $start;
        // 큰 범위에서도 필요한 줄만 꺼낸다(파일 끝을 넘으면 빈 줄)
        $slice = [];
        for ($i = $start; $i <= $end; $i++) {
            $slice[] = $lines[$i - 1] ?? '';
        }
        return self::normalizeLines($slice);
    }

    public static function signature(string $path, string $ruleKey, string $code): string
    {
        return hash('sha256', $path . "\0" . $ruleKey . "\0" . $code);
    }

    /**
     * 발췌·표시용 텍스트: UTF-8이 아니면 잘못된 바이트를 대체 문자(U+FFFD)로 바꾼다.
     *
     * @return array{text: string, replaced: bool}
     */
    public static function toUtf8Text(string $bytes): array
    {
        if (mb_check_encoding($bytes, 'UTF-8')) {
            return ['text' => $bytes, 'replaced' => false];
        }
        $prev = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        try {
            $text = (string) mb_convert_encoding($bytes, 'UTF-8', 'UTF-8');
        } finally {
            mb_substitute_character($prev);
        }
        return ['text' => $text, 'replaced' => true];
    }
}
