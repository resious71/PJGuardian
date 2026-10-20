// FAQ 화면의 순수 함수(P29B, tests/js/faq.test.mjs). DOM을 만들지 않는다 — 화면(pages/faq.js)이 결과로 노드를 만든다(textContent만).

/** 길이 상한(서버 FaqService·DB CHECK와 같음) */
export const LIMITS = { category: 40, question: 200, answer: 10000 };

/** 항목 주소 조각: #faq-<id> */
export function anchorId(id) {
  return `faq-${Number(id)}`;
}

/** 주소 해시 → 항목 id(없거나 형식이 다르면 null) */
export function idFromHash(hash) {
  const m = /^#?faq-([1-9][0-9]{0,9})$/.exec(String(hash ?? ''));
  return m ? Number(m[1]) : null;
}

/**
 * 서버가 준 순서 그대로(분류 순서 → 분류 안 순서) 분류별로 묶는다.
 * @param {{category: string}[]} items
 * @returns {{category: string, items: object[]}[]}
 */
export function groupByCategory(items) {
  const groups = [];
  const byName = new Map();
  for (const it of items ?? []) {
    let g = byName.get(it.category);
    if (!g) {
      g = { category: it.category, items: [] };
      byName.set(it.category, g);
      groups.push(g);
    }
    g.items.push(it);
  }
  return groups;
}

/**
 * 같은 분류 안에서 한 칸 위·아래로 옮긴 id 목록. 끝에서 더 못 가면 null(단추를 끈다).
 * @param {number[]} ids 지금 순서
 * @param {number} id 옮길 항목
 * @param {'up'|'down'} dir
 */
export function moveIds(ids, id, dir) {
  const i = ids.indexOf(id);
  const j = dir === 'up' ? i - 1 : i + 1;
  if (i < 0 || j < 0 || j >= ids.length) return null;
  const out = [...ids];
  [out[i], out[j]] = [out[j], out[i]];
  return out;
}

// 제어 문자·줄 구분자(U+2028·2029)·BOM — 정규식 글자 안에 직접 쓰면 줄 끝으로 읽히므로 이스케이프 문자열로 만든다
const CONTROL = new RegExp('[\u0000-\u001f\u007f\u2028\u2029\ufeff]', 'g');

/** 한 줄 값 정리(서버와 같은 규칙의 간단판 — 화면 검증용, 최종 판정은 서버): 연속 공백 하나로, 앞뒤 공백 제거 */
export function oneLine(s) {
  return String(s ?? '').replace(CONTROL, ' ').replace(/\s+/g, ' ').trim();
}

/**
 * 편집 대화상자 검증. 반환: [{field, kind: 'required'|'too_long', max?}] — 비었으면 통과.
 * @param {{category?: string, question?: string, answer?: string}} draft
 */
export function validateDraft(draft) {
  const errors = [];
  for (const field of ['category', 'question', 'answer']) {
    const raw = String(draft?.[field] ?? '');
    const value = field === 'answer' ? raw.replace(/\r\n?/g, '\n').trim() : oneLine(raw);
    const len = [...value].length;
    if (len === 0) errors.push({ field, kind: 'required' });
    else if (len > LIMITS[field]) errors.push({ field, kind: 'too_long', max: LIMITS[field] });
  }
  return errors;
}

/** 글자 수(코드 포인트 — 서버 mb_strlen과 같음) */
export function charCount(s) {
  return [...String(s ?? '')].length;
}

/** 입력이 처음 값과 다른가(대화상자를 닫을 때 확인) */
export function isDirty(original, draft) {
  const o = original ?? { category: '', question: '', answer: '', is_published: true };
  return ['category', 'question', 'answer'].some((f) => String(draft?.[f] ?? '') !== String(o[f] ?? ''))
    || Boolean(draft?.is_published) !== Boolean(o.is_published ?? true);
}

/**
 * 답변 안 링크: 앱 안의 상대 주소(/…)만 앱 주소로 — 외부는 렌더러 기본 규칙(https·http만, rel="noopener noreferrer").
 * javascript:·data:·//다른 호스트는 링크가 아니다(null → 렌더러가 글자로 둔다).
 */
export function faqHref(href, base = '') {
  const h = String(href ?? '');
  return /^\/(?!\/)[A-Za-z0-9/_\-?=&%.#~]*$/.test(h) ? `${base}${h}` : null;
}

/** 검색 상태 안내 키: 검색어·분류가 없으면 null */
export function searchActive(q, category) {
  return oneLine(q) !== '' || String(category ?? '') !== '';
}
