// 긴 셀 말줄임 + 툴팁(P21 0C). 결함 표의 "파일:줄"·"메시지"와 대시보드 파일 상위 표가 함께 쓴다(한 곳). el()/textContent로만.
//   clipText(text, { kind: 'path'|'text', focusable }) → <span data-clip>
//     path: 앞부분(폴더)만 줄이고 끝부분(파일명:줄)은 항상 보인다 — `src/pages/rep…Summary.js:128`
//     text: 한 줄로 줄이고 끝에 …
//     DOM에는 전체 값이 그대로 있어 드래그 복사·화면 읽기 프로그램은 전체를 얻는다(말줄임은 CSS만).
//   tipTarget(node, text): 잘림과 관계없이 항상 설명을 띄울 요소(예: "버전/설정 변경" 배지의 판정 근거, P21). 포커스 가능하게 한다.
//   installClipTips(doc): 문서에 한 번. 마우스를 올리거나(hover) 키보드로 포커스하면 잘린 셀에만 전체 값을 띄우고, 벗어나거나 Esc면 닫는다.
//     툴팁은 화면 가장자리에서 잘리지 않게 위치를 고치고 최대 너비 안에서 줄바꿈한다. 셀에 title 속성은 쓰지 않는다(이중 툴팁 방지).
import { el } from '../core/dom.js';

/** 경로를 앞(폴더)과 끝(파일명 + :줄)으로 나눈다 */
export function splitPath(text) {
  const s = String(text);
  const i = s.lastIndexOf('/');
  return i < 0 ? { head: '', tail: s } : { head: s.slice(0, i + 1), tail: s.slice(i + 1) };
}

export function clipText(text, { kind = 'text', focusable = true } = {}, doc = globalThis.document) {
  const value = String(text ?? '');
  const props = { class: kind === 'path' ? 'clip clip--path' : 'clip', dataset: { clip: kind } };
  if (focusable) props.tabindex = '0';
  if (kind !== 'path') return el('span', props, [value], doc);
  const { head, tail } = splitPath(value);
  return el('span', props, [
    el('span', { class: 'clip__head', text: head }, [], doc),
    el('span', { class: 'clip__tail', text: tail }, [], doc),
  ], doc);
}

/** 마우스·포커스 때 항상 text를 툴팁으로 띄울 요소로 만든다 */
export function tipTarget(node, text) {
  node.dataset.tip = String(text);
  if (!node.hasAttribute('tabindex')) node.setAttribute('tabindex', '0');
  return node;
}

/** 잘렸는가(경로는 앞부분이, 글은 전체가 넘침). 크기를 알 수 없으면(측정 불가) 잘렸다고 본다 */
export function isTruncated(node) {
  if (node.dataset?.clip === 'path' && node.querySelector) {
    // 경로: 앞부분이 줄었거나, 칸이 아주 좁아 파일명:줄까지 줄었으면(P21U)
    const parts = [node.querySelector('.clip__head'), node.querySelector('.clip__tail')].filter((p) => p && p.textContent !== '');
    if (parts.length) return parts.some((p) => overflows(p));
  }
  return overflows(node);
}

function overflows(target) {
  if (typeof target.scrollWidth !== 'number' || typeof target.clientWidth !== 'number' || target.clientWidth === 0) return true;
  return target.scrollWidth > target.clientWidth + 1;
}

/** 툴팁 위치: 셀 아래(공간이 없으면 위), 화면 밖으로 나가지 않게 */
export function tipPosition(rect, tip, view, gap = 6) {
  let left = Math.min(Math.max(8, rect.left), Math.max(8, view.width - tip.width - 8));
  let top = rect.bottom + gap;
  if (top + tip.height > view.height - 8) top = Math.max(8, rect.top - tip.height - gap);
  left = Math.round(left);
  top = Math.round(top);
  return { left, top };
}

export function installClipTips(doc = globalThis.document) {
  if (doc.documentElement?.dataset.clipTips === '1') return;
  if (doc.documentElement) doc.documentElement.dataset.clipTips = '1';
  let tip = null;
  let owner = null;
  const hide = () => {
    if (!tip || tip.hidden) return;
    tip.hidden = true;
    owner?.removeAttribute('aria-describedby');
    owner = null;
  };
  const place = () => {
    if (!tip || tip.hidden || !owner) return;
    const r = owner.getBoundingClientRect();
    const t = tip.getBoundingClientRect();
    const pos = tipPosition(r, { width: t.width, height: t.height }, { width: doc.documentElement.clientWidth, height: doc.documentElement.clientHeight });
    tip.style.left = `${pos.left}px`;
    tip.style.top = `${pos.top}px`;
  };
  const show = (node) => {
    const fixed = node.dataset.tip;
    if (fixed === undefined && !isTruncated(node)) return;
    if (!tip) {
      tip = el('div', { class: 'clip-tip', role: 'tooltip', id: 'pjg-clip-tip' }, [], doc);
      tip.hidden = true;
      doc.body.appendChild(tip);
    }
    tip.textContent = fixed ?? node.textContent;
    tip.hidden = false;
    owner = node;
    node.setAttribute('aria-describedby', 'pjg-clip-tip');
    place();
  };
  const find = (target) => {
    if (!(target instanceof Element)) return null;
    return target.closest('[data-clip], [data-tip]') ?? (target.matches('a, button') ? target.querySelector('[data-clip]') : null);
  };
  doc.addEventListener('mouseover', (e) => {
    const n = find(e.target);
    if (n && n !== owner) show(n);
    else if (!n) hide();
  });
  doc.addEventListener('focusin', (e) => {
    const n = find(e.target);
    if (n) show(n);
    else hide();
  });
  doc.addEventListener('focusout', hide);
  doc.addEventListener('keydown', (e) => { if (e.key === 'Escape') hide(); });
  doc.addEventListener('scroll', place, true); // 스크롤하면 닫지 않고 셀을 따라간다
}
