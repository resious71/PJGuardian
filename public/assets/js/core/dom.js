// 안전한 DOM 생성(명세 10: XSS 방지). innerHTML을 쓰지 않고 textContent와 속성 API만 쓴다.
//   el('button', { class: 'btn', type: 'button', dataset: { id: 3 }, on: { click: fn } }, ['텍스트', 자식요소])
// - 문자열 자식은 항상 텍스트 노드가 된다(HTML로 해석되지 않음).
// - on* 속성 문자열, innerHTML/outerHTML/srcdoc, javascript: 주소는 거부한다.

const FORBIDDEN_ATTRS = new Set(['innerhtml', 'outerhtml', 'srcdoc']);
const URL_ATTRS = new Set(['href', 'src', 'action', 'formaction', 'xlink:href']);

/** HTML 특수 문자를 이스케이프한다(DOM 밖에서 문자열을 다뤄야 할 때, 예: title 비교·로그). */
export function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/** 안전한 URL인지(상대 경로, http, https, mailto만) */
export function isSafeUrl(url) {
  const s = String(url).trim().toLowerCase().replace(/[\u0000-\u001f\s]+/g, '');
  if (s.startsWith('/') || s.startsWith('#') || s.startsWith('?') || s.startsWith('./') || s.startsWith('../')) return true;
  return /^(https?:|mailto:)/.test(s) || !/^[a-z][a-z0-9+.-]*:/.test(s);
}

export function setAttr(node, name, value) {
  const key = String(name).toLowerCase();
  if (key.startsWith('on')) throw new Error(`이벤트 속성은 on 옵션으로 연결한다: ${name}`);
  if (FORBIDDEN_ATTRS.has(key)) throw new Error(`허용하지 않는 속성: ${name}`);
  if (URL_ATTRS.has(key) && !isSafeUrl(value)) throw new Error(`안전하지 않은 주소: ${value}`);
  if (value === false || value === null || value === undefined) {
    node.removeAttribute(name);
  } else {
    node.setAttribute(name, value === true ? '' : String(value));
  }
}

export function el(tag, props = {}, children = [], doc = globalThis.document) {
  const node = doc.createElement(tag);
  for (const [name, value] of Object.entries(props)) {
    if (name === 'class') {
      node.className = Array.isArray(value) ? value.filter(Boolean).join(' ') : String(value);
    } else if (name === 'text') {
      node.textContent = String(value);
    } else if (name === 'dataset') {
      for (const [k, v] of Object.entries(value)) node.dataset[k] = String(v);
    } else if (name === 'on') {
      for (const [evt, fn] of Object.entries(value)) node.addEventListener(evt, fn);
    } else {
      setAttr(node, name, value);
    }
  }
  append(node, children, doc);
  return node;
}

export function append(parent, children, doc = globalThis.document) {
  for (const child of [].concat(children)) {
    if (child === null || child === undefined || child === false) continue;
    parent.appendChild(typeof child === 'string' || typeof child === 'number' ? doc.createTextNode(String(child)) : child);
  }
  return parent;
}

/** 자식을 모두 지운다(innerHTML = '' 대신) */
export function clear(node) {
  while (node.firstChild) node.removeChild(node.firstChild);
  return node;
}

export function setText(node, text) {
  node.textContent = String(text);
  return node;
}

export function meta(name, doc = globalThis.document) {
  return doc.querySelector(`meta[name="${name}"]`)?.getAttribute('content') ?? '';
}
