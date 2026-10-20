<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * 한국어 조사 고르기(X17 감사 로그 설명). 문구에 "을(를)·이(가)·은(는)·와(과)·(으)로"처럼 두 꼴을 함께 쓰고, 앞말의 끝소리로 하나를 고른다.
 * - 앞말이 괄호로 끝나면 괄호 앞 낱말을 본다("김철수(kim)을(를)" → "김철수(kim)를"), 닫는 따옴표는 건너뛴다.
 * - 한글은 받침, 숫자는 읽는 소리, 영문은 끝 글자로 어림(대부분의 로그인 ID·이름에 맞음), 그 밖(기호)은 두 꼴을 그대로 둔다.
 */
final class Josa
{
    private const PAIRS = ['을(를)' => ['을', '를'], '이(가)' => ['이', '가'], '은(는)' => ['은', '는'], '와(과)' => ['과', '와'], '(으)로' => ['으로', '로']];

    public static function fix(string $s): string
    {
        if (preg_match_all('/을\(를\)|이\(가\)|은\(는\)|와\(과\)|\(으\)로/u', $s, $m, PREG_OFFSET_CAPTURE) === 0) {
            return $s;
        }
        $out = '';
        $pos = 0;
        foreach ($m[0] as [$marker, $at]) {
            $before = substr($s, $pos, $at - $pos);
            $out .= $before;
            $pos = $at + strlen($marker);
            $b = self::batchim(self::word($out), $marker === '(으)로');
            $out .= $b === null ? $marker : self::PAIRS[$marker][$b ? 0 : 1];
        }
        return $out . substr($s, $pos);
    }

    /** 조사가 붙는 앞말의 끝(닫는 따옴표·괄호 묶음을 건너뜀) */
    private static function word(string $text): string
    {
        $t = rtrim($text, " \t");
        for ($i = 0; $i < 3; $i++) {
            $t = (string) preg_replace('/[\'"’”」』]+$/u', '', $t);
            if (preg_match('/(?:^|[^\p{L}\p{N}]\s)\(([^()]+)\)$/su', $t, $mm) === 1) {
                return $mm[1]; // 낱말 전체가 괄호 묶음("→ (없음)")이면 괄호 안 글자 — "Jordan Lee (jordan)"처럼 낱말 뒤 괄호는 아래에서 건너뜀
            }
            if (preg_match('/^(.*\S)\s*\([^()]*\)$/su', $t, $mm) === 1) {
                $t = $mm[1];
                continue;
            }
            break;
        }
        return $t;
    }

    /**
     * 끝소리에 받침이 있는가(null = 알 수 없음). $ro면 "(으)로" 규칙 — ㄹ 받침은 받침 없음처럼 "로".
     */
    public static function batchim(string $word, bool $ro = false): ?bool
    {
        if ($word === '') {
            return null;
        }
        $ch = mb_substr($word, -1);
        $code = mb_ord($ch, 'UTF-8');
        if ($code >= 0xAC00 && $code <= 0xD7A3) {
            $final = ($code - 0xAC00) % 28;
            return $final !== 0 && !($ro && $final === 8);
        }
        if (ctype_digit($ch)) {
            // 영 일 이 삼 사 오 육 칠 팔 구 — 일·칠·팔은 ㄹ 받침
            return $ro ? in_array($ch, ['0', '3', '6'], true) : in_array($ch, ['0', '1', '3', '6', '7', '8'], true);
        }
        if (ctype_alpha($ch)) {
            $l = strtolower($ch);
            if ($l === 'g') {
                return strtolower(mb_substr($word, -2, 1)) === 'n'; // -ng(링)은 받침, 그 밖(로그)은 없음
            }
            if (in_array($l, ['m', 'n', 'b', 'k', 'p'], true)) {
                return true; // 엠·엔·웹·북·집
            }
            if ($l === 'l') {
                return !$ro; // 엘 — (으)로에서는 "로"
            }
            return false;
        }
        return null;
    }
}
