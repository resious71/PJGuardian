// 테마 명암비 점검(P27D): public/assets/css/tokens.css의 라이트(:root)·다크(:root[data-theme="dark"]) 토큰 쌍을 WCAG 2 상대 휘도 공식으로 계산한다.
//   node tools/check_theme_contrast.mjs [--json]
// 기준: 글자 4.5:1(AA), 큰 글자·아이콘·경계·포커스 3:1. 실패가 있으면 종료 코드 1.
// 또 다크 두 블록(명시 다크, 시스템 다크 @media)이 같은 값인지 확인한다(한쪽만 고치는 실수 방지).
// 반투명 색(rgb … / a)은 짝 배경 위에 합성한 색으로 계산한다.
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const css = readFileSync(path.join(ROOT, 'public/assets/css/tokens.css'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');

function block(source, startRe) {
  const m = startRe.exec(source);
  if (!m) throw new Error(`블록 없음: ${startRe}`);
  let depth = 0;
  for (let i = m.index + m[0].length - 1; i < source.length; i++) {
    if (source[i] === '{') depth++;
    else if (source[i] === '}' && --depth === 0) return source.slice(m.index + m[0].length, i);
  }
  throw new Error('블록이 닫히지 않음');
}
const vars = (text) => Object.fromEntries([...text.matchAll(/(--[\w-]+)\s*:\s*([^;]+);/g)].map((m) => [m[1], m[2].trim()]));

export const light = vars(block(css, /^:root\s*\{/m));
const darkExplicit = vars(block(css, /:root\[data-theme="dark"\]\s*\{/));
const darkMedia = vars(block(block(css, /@media \(prefers-color-scheme: dark\)\s*\{/), /:root:not\(\[data-theme="light"\]\)\s*\{/.exec(block(css, /@media \(prefers-color-scheme: dark\)\s*\{/)) ? /:root:not\(\[data-theme="light"\]\)\s*\{/ : /x^/));
export const dark = { ...light, ...darkExplicit };

function parse(value, vs, bg = null) {
  const v = value.startsWith('var(') ? parse(vs[/var\((--[\w-]+)\)/.exec(value)[1]], vs, bg) : value;
  if (Array.isArray(v)) return v;
  let m = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(v);
  if (m) {
    const h = m[1].length === 3 ? [...m[1]].map((c) => c + c).join('') : m[1];
    return [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16));
  }
  m = /^rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)(?:\s*[/,]\s*([\d.]+))?\s*\)$/.exec(v);
  if (m) {
    const rgb = [m[1], m[2], m[3]].map(Number);
    const a = m[4] === undefined ? 1 : Number(m[4]);
    if (a >= 1 || bg === null) return rgb;
    return rgb.map((c, i) => Math.round(c * a + bg[i] * (1 - a)));
  }
  throw new Error(`색 해석 실패: ${value}`);
}
const lum = (rgb) => {
  const [r, g, b] = rgb.map((c) => { const s = c / 255; return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4; });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};
export function ratio(a, b) {
  const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p);
  return (x + 0.05) / (y + 0.05);
}

/** 점검 쌍: [앞, 뒤, 기준, 설명]. 뒤(배경)가 반투명이면 surface 위에 합성 */
const TEXT = 4.5;
const UI = 3;
export const PAIRS = [
  ['--color-text', '--color-bg', TEXT, '본문 글자/바탕'],
  ['--color-text', '--color-surface', TEXT, '본문 글자/카드'],
  ['--color-text', '--color-row-hover', TEXT, '본문 글자/표 행 호버'],
  ['--color-text-muted', '--color-surface', TEXT, '보조 글자/카드'],
  ['--color-text-muted', '--color-bg', TEXT, '보조 글자/바탕'],
  ['--color-link', '--color-surface', TEXT, '링크·현재 탭/카드'],
  ['--color-link', '--color-bg', TEXT, '링크/바탕'],
  ['--guide-legend-border', '--color-bg', UI, '가이드 범례 표 선/바탕(X13)'],
  ['--color-primary-fg', '--color-primary', TEXT, '기본 버튼 글자'],
  ['--color-primary-fg', '--color-primary-hover', TEXT, '기본 버튼 글자(호버)'],
  ['--color-danger-fg', '--color-danger', TEXT, '위험 버튼 글자'],
  ['--color-danger-fg', '--color-danger-hover', TEXT, '위험 버튼 글자(호버)'],
  ['--color-surface', '--color-text', TEXT, '툴팁(반전) 글자'],
  ['--color-topbar-fg', '--color-topbar', TEXT, '헤더 글자'],
  ['--color-topbar-muted', '--color-topbar', TEXT, '헤더 보조 글자'],
  ['--color-border-strong', '--color-surface', UI, '입력 칸 경계'],
  ['--color-focus', '--color-surface', UI, '포커스 링/카드'],
  ['--color-focus', '--color-bg', UI, '포커스 링/바탕'],
  ['--color-primary', '--color-surface', UI, '선택 표시(밑줄·테두리)'],
  ['--status-icon-ok', '--color-surface', UI, '상태 아이콘(정상)'],
  ['--status-icon-warn', '--color-surface', UI, '상태 아이콘(경고)'],
  ['--status-icon-fail', '--color-surface', UI, '상태 아이콘(실패)'],
  ['--chart-text', '--color-surface', TEXT, '차트 글자'],
  ['--chart-bar-border', '--color-surface', UI, '막대 경계'],
  ['--chart-total', '--color-surface', UI, '추이 "전체" 선'],
  ...['meta', 'keyword', 'atom', 'literal', 'string', 'regexp', 'def', 'local', 'type', 'class', 'special', 'property', 'comment', 'invalid']
    .map((k) => [`--code-${k}`, '--color-surface', TEXT, `코드 ${k}`]),
  ...['critical', 'high', 'medium', 'low', 'none'].map((k) => [`--sev-${k}-fg`, `--sev-${k}-bg`, TEXT, `심각도 배지 ${k}`]),
  ...['open', 'progress', 'postponed', 'intentional', 'notfix', 'resolved'].map((k) => [`--st-${k}-fg`, `--st-${k}-bg`, TEXT, `결함 상태 배지 ${k}`]),
  ...['ok', 'warn', 'error', 'info', 'muted'].map((k) => [`--${k}-fg`, `--${k}-bg`, TEXT, `알림·상태 ${k}`]),
  ['--warn-fg', '--warn-bg', TEXT, '코드 뷰어 결함 줄 글자(배경 warn-bg)'],
  ['--color-text', '--warn-bg', TEXT, '코드 뷰어 결함 줄 본문'],
];

export function evaluate(vs) {
  const surface = parse(vs['--color-surface'], vs);
  return PAIRS.map(([fg, bg, min, label]) => {
    const b = parse(vs[bg], vs, surface);
    const f = parse(vs[fg], vs, b);
    const r = ratio(f, b);
    return { fg, bg, label, min, ratio: Math.round(r * 100) / 100, ok: r >= min };
  });
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
  const out = { light: evaluate(light), dark: evaluate(dark) };
  const diff = Object.keys({ ...darkExplicit, ...darkMedia }).filter((k) => darkExplicit[k] !== darkMedia[k]);
  if (process.argv.includes('--json')) {
    console.log(JSON.stringify({ ...out, darkBlocksDiffer: diff }, null, 1));
  } else {
    for (const theme of ['light', 'dark']) {
      const fails = out[theme].filter((x) => !x.ok);
      console.log(`[${theme}] 쌍 ${out[theme].length}개, 실패 ${fails.length}개, 최소 ${Math.min(...out[theme].map((x) => x.ratio))}:1`);
      for (const x of fails) console.log(`  실패 ${x.label}: ${x.fg} / ${x.bg} = ${x.ratio}:1 (기준 ${x.min}:1)`);
    }
    console.log(diff.length ? `다크 두 블록 값이 다름: ${diff.join(', ')}` : '다크 두 블록(명시·시스템) 값 같음');
  }
  const failed = out.light.some((x) => !x.ok) || out.dark.some((x) => !x.ok) || diff.length > 0;
  process.exit(failed ? 1 : 0);
}
