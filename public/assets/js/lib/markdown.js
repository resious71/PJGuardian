// 안전한 마크다운 부분집합 렌더러(P22 규칙 설명). **HTML을 해석하지 않는다**: 모든 글자는 textContent로 들어가고(el()),
// 원시 HTML 태그(<script> 등)는 글자 그대로 보인다. 지원: 제목(#~####), 문단, 목록(-, *, 1.), 코드 블록(```), 인라인 코드(`x`),
// 굵게(**x**), 기울임(*x*, _x_), 링크 [글](주소) — 주소는 http(s)만(javascript:·data:·상대 주소는 링크가 아니라 글자).
// 외부 링크는 rel="noopener noreferrer"(새 창 없음). tests/js/markdown.test.mjs.
// P29 가이드: 선택 인자 opts로만 넓힌다(기본 동작은 그대로) — opts.link(주소) → 쓸 주소|null(guide:·앱 안 경로),
// opts.image(대체 텍스트, 주소) → 노드|null(![…](guide-img:…)), opts.token(이름) → 노드|null({{icon:…}}). 셋 다 노드를 직접 만든다(HTML 문자열 없음).
import { el } from '../core/dom.js';

/** 링크로 만들 수 있는 주소: http·https만(공백·제어 문자 없음) */
export function safeHref(url) {
  const s = String(url ?? '').trim();
  return /^https?:\/\/[^\s<>"'\x00-\x1f]+$/i.test(s) ? s : null;
}

/** 한 줄 안의 인라인 요소 → 노드 목록 */
export function inline(text, doc = globalThis.document, opts = {}) {
  const out = [];
  const re = /(`+)([^`]|[^`][\s\S]*?[^`])\1(?!`)|(!?)\[([^\]\n]*)\]\(([^)\s]+)\)|\*\*([^*\n]+)\*\*|(?<![\w*])\*([^*\n]+)\*(?![\w*])|(?<![\w])_([^_\n]+)_(?![\w])|\{\{icon:([a-z0-9_.-]+)\}\}/g;
  let last = 0;
  let m;
  while ((m = re.exec(text)) !== null) {
    if (m.index > last) out.push(text.slice(last, m.index));
    if (m[1]) {
      out.push(el('code', { text: m[2] }, [], doc));
    } else if (m[5] !== undefined && m[3] === '!') {
      const node = opts.image ? opts.image(m[4], m[5]) : null;
      out.push(node ?? `![${m[4]}](${m[5]})`);
    } else if (m[5] !== undefined && m[4] !== '') {
      const own = opts.link ? opts.link(m[5]) : null;
      const href = own ?? safeHref(m[5]);
      out.push(href ? el('a', own ? { href } : { href, rel: 'noopener noreferrer' }, inline(m[4], doc, opts), doc) : `[${m[4]}](${m[5]})`);
    } else if (m[5] !== undefined) {
      out.push(`[](${m[5]})`);
    } else if (m[6] !== undefined) {
      out.push(el('strong', {}, inline(m[6], doc, opts), doc));
    } else if (m[9] !== undefined) {
      const node = opts.token ? opts.token(m[9]) : null;
      out.push(node ?? m[0]);
    } else {
      out.push(el('em', {}, inline(m[7] ?? m[8], doc, opts), doc));
    }
    last = re.lastIndex;
  }
  if (last < text.length) out.push(text.slice(last));
  return out;
}

/**
 * 마크다운 → 블록 노드 목록. headingBase: 문서에서 가장 높은 단계의 제목이 될 태그 번호(페이지 안의 위치에 맞춤, 기본 h3).
 * @returns {Node[]}
 */
export function renderMarkdown(source, { headingBase = 3, ...opts } = {}, doc = globalThis.document) {
  const lines = String(source ?? '').replace(/\r\n?/g, '\n').split('\n');
  // 코드 블록 밖의 가장 높은 제목 단계 → headingBase(ruff 설명은 ##부터)
  let top = 4;
  let inFence = false;
  for (const l of lines) {
    if (/^\s*(```|~~~)/.test(l)) inFence = !inFence;
    const h = !inFence && /^(#{1,4})\s+\S/.exec(l);
    if (h) top = Math.min(top, h[1].length);
  }
  const blocks = [];
  let para = [];
  let list = null; // {ordered, items: [string]}
  const flushPara = () => {
    if (para.length) blocks.push(el('p', {}, inline(para.join(' '), doc, opts), doc));
    para = [];
  };
  const flushList = () => {
    if (list) blocks.push(el(list.ordered ? 'ol' : 'ul', {}, list.items.map((it) => el('li', {}, inline(it, doc, opts), doc)), doc));
    list = null;
  };
  for (let i = 0; i < lines.length; i += 1) {
    const line = lines[i];
    const fence = /^\s*(```+|~~~+)\s*([\w+-]*)\s*$/.exec(line);
    if (fence) {
      flushPara();
      flushList();
      const body = [];
      for (i += 1; i < lines.length && !new RegExp(`^\\s*${fence[1][0] === '`' ? '`' : '~'}{${fence[1].length},}\\s*$`).test(lines[i]); i += 1) body.push(lines[i]);
      blocks.push(el('pre', { class: 'md-code', dataset: fence[2] ? { lang: fence[2] } : {} }, [el('code', { text: body.join('\n') }, [], doc)], doc));
      continue;
    }
    const heading = /^(#{1,4})\s+(.+?)\s*#*\s*$/.exec(line);
    if (heading) {
      flushPara();
      flushList();
      blocks.push(el(`h${Math.min(6, headingBase + heading[1].length - top)}`, { class: 'md-heading' }, inline(heading[2], doc, opts), doc));
      continue;
    }
    const item = /^\s*(?:([-*+])|(\d+)[.)])\s+(.*)$/.exec(line);
    if (item) {
      flushPara();
      const ordered = item[2] !== undefined;
      if (list && list.ordered !== ordered) flushList();
      list ??= { ordered, items: [] };
      list.items.push(item[3]);
      continue;
    }
    if (line.trim() === '') {
      flushPara();
      flushList();
      continue;
    }
    if (list && /^\s{2,}\S/.test(line)) {
      list.items[list.items.length - 1] += ` ${line.trim()}`; // 목록 항목의 이어지는 줄
      continue;
    }
    flushList();
    para.push(line.trim());
  }
  flushPara();
  flushList();
  return blocks;
}
