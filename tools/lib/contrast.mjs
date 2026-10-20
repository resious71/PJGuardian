// WCAG 2.x 명도 대비(P21U 색 토큰 점검). '#rrggbb'만 받는다.
//   비텍스트(막대 테두리·표식)는 3:1, 글자는 4.5:1 이상(명세 20, WCAG 1.4.11·1.4.3)

function channel(v) {
  const s = v / 255;
  return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
}

export function luminance(hex) {
  const m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(String(hex).trim());
  if (!m) throw new Error(`색 형식이 아님: ${hex}`);
  const [r, g, b] = m.slice(1).map((x) => channel(parseInt(x, 16)));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** 대비(1~21), 소수 둘째 자리 */
export function contrastRatio(a, b) {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return Math.round(((hi + 0.05) / (lo + 0.05)) * 100) / 100;
}
