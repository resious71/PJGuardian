// 가이드 화면의 순수 함수(P29, tests/js/guide.test.mjs). DOM은 만들지 않는다 — 화면(pages/guide.js)이 결과로 노드를 만든다(textContent만).

/** 검색어 최소 길이(서버 GuideContent::search와 같음) */
export const MIN_QUERY = 2;

/** 검색어 정리: 앞뒤 공백 제거, 너무 짧으면 '' */
export function normalizeQuery(q) {
  const s = String(q ?? '').trim();
  return [...s].length >= MIN_QUERY ? s.slice(0, 100) : '';
}

/**
 * 글자를 검색어 일치 부분과 나머지로 나눈다(대소문자 무시, 한글 그대로). 강조는 화면이 <mark>를 만들어 글자만 넣는다 — HTML 해석 없음.
 * @returns {{text: string, mark: boolean}[]}
 */
export function highlightSegments(text, query) {
  const s = String(text ?? '');
  const q = normalizeQuery(query);
  if (!q) return [{ text: s, mark: false }];
  const lower = s.toLowerCase();
  const needle = q.toLowerCase();
  const out = [];
  let from = 0;
  for (let i = lower.indexOf(needle); i !== -1; i = lower.indexOf(needle, i + needle.length)) {
    if (i > from) out.push({ text: s.slice(from, i), mark: false });
    out.push({ text: s.slice(i, i + needle.length), mark: true });
    from = i + needle.length;
  }
  if (from < s.length || out.length === 0) out.push({ text: s.slice(from), mark: false });
  return out;
}

/**
 * 가이드 본문의 링크 주소 → 실제 주소. guide:<key>는 같은 가이드의 절(#key), /앱 경로는 배포 경로를 붙인다. 그 밖은 null(렌더러 기본 규칙 — https만).
 */
export function guideHref(href, { base = '', audience = 'user' } = {}) {
  const h = String(href ?? '');
  const g = /^guide:([a-z0-9][a-z0-9-]{0,63})$/.exec(h);
  if (g) return `${base}/guide/${audience}#${g[1]}`;
  if (/^\/(?!\/)[A-Za-z0-9/_\-?=&%.#]*$/.test(h)) return `${base}${h}`;
  return null;
}

/** 이미지 주소 guide-img:<slug> → slug, 아니면 null */
export function imageSlug(src) {
  const m = /^guide-img:([a-z0-9][a-z0-9-]{0,63})$/.exec(String(src ?? ''));
  return m ? m[1] : null;
}

/** 주소 해시 → 있는 절 key(없으면 null). #key 또는 #key/추가 */
export function sectionFromHash(hash, keys) {
  const k = decodeURIComponent(String(hash ?? '').replace(/^#/, '')).split('/')[0];
  return keys.includes(k) ? k : null;
}

/** 이전·다음 절 */
export function neighbors(keys, key) {
  const i = keys.indexOf(key);
  return { prev: i > 0 ? keys[i - 1] : null, next: i >= 0 && i < keys.length - 1 ? keys[i + 1] : null };
}

/** 주소 해시 → {key, sub}. #key 또는 #key/<소제목 slug>(X11). 디코딩 실패는 빈 값 */
export function parseHash(hash) {
  let s = String(hash ?? '').replace(/^#/, '');
  try { s = decodeURIComponent(s); } catch { s = ''; }
  const i = s.indexOf('/');
  return i === -1 ? { key: s, sub: '' } : { key: s.slice(0, i), sub: s.slice(i + 1) };
}

/**
 * 소제목 글자 → 앵커 slug(X11, #key/<slug>). 소문자, 한글·영문·숫자만 남기고 나머지는 '-', 같은 절 안 중복은 -2, -3.
 * @param {Set<string>} used 이 절에서 이미 쓴 slug(함수가 더함)
 */
export function headingSlug(text, used = new Set()) {
  const base = String(text ?? '').toLowerCase().normalize('NFC').replace(/[^0-9a-z\uac00-\ud7a3]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60) || 'section';
  let slug = base;
  for (let n = 2; used.has(slug); n += 1) slug = `${base}-${n}`;
  used.add(slug);
  return slug;
}

/** 마크다운 본문 → 검색 요약용 평문(그림·아이콘 자리표시자 빼고 링크는 글자만, 강조 기호 제거) */
export function plainText(md) {
  return String(md ?? '')
    .replace(/!\[[^\]]*\]\([^)]*\)/g, ' ')
    .replace(/\{\{(?:icon|legend):[^}]+\}\}/g, ' ')
    .replace(/\[([^\]]*)\]\([^)]*\)/g, '$1')
    .replace(/^#+\s*/gm, '')
    .replace(/^\s*(?:[-*]|\d+\.)\s+/gm, '')
    .replace(/[`*_]/g, '')
    .replace(/\s+/g, ' ')
    .trim();
}

/** 검색 결과 한 줄 요약: 첫 일치 앞뒤 radius자(넘치면 …). 일치가 없으면 '' */
export function snippetAround(text, query, radius = 40) {
  const q = normalizeQuery(query);
  const s = String(text ?? '');
  if (!q) return '';
  const i = s.toLowerCase().indexOf(q.toLowerCase());
  if (i === -1) return '';
  const from = Math.max(0, i - radius);
  const to = Math.min(s.length, i + q.length + radius);
  return `${from > 0 ? '…' : ''}${s.slice(from, to)}${to < s.length ? '…' : ''}`;
}
