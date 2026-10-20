// 아이콘 모듈(JS). 매핑은 PHP Support\Icons와 같은 public/assets/icons/icons.json 하나만 읽는다(명세 20.2).
// 화면 코드는 SVG를 직접 만들지 않고 이 모듈만 쓴다.
//   icon(name, size)           장식용(aria-hidden). 옆에 텍스트가 있을 때
//   meaningfulIcon(name, size) 의미 전달(role=img + aria-label). 아이콘만 있을 때
//   iconWithLabel(name, size)  아이콘 + 텍스트
// 매핑에 없는 이름: app-env=dev면 오류, 운영이면 대체 아이콘 + console.warn
import map from '../../icons/icons.json' with { type: 'json' };
import { t } from '../core/i18n.js';

const SVG_NS = 'http://www.w3.org/2000/svg';
export const SIZES = [16, 20, 24];

function metaContent(name, doc) {
  return doc?.querySelector?.(`meta[name="${name}"]`)?.getAttribute('content') ?? '';
}

/** 의미 이름 → 매핑 항목. strict면 없는 이름에 오류 */
export function resolve(name, { strict = false, warn = (msg) => console.warn(msg) } = {}) {
  const entry = map.icons[name];
  if (entry) return { name, ...entry };
  if (strict) throw new Error(`매핑에 없는 아이콘 이름: ${name} (public/assets/icons/icons.json)`);
  warn(`매핑에 없는 아이콘 이름 — 일반 아이콘으로 대체: ${name}`);
  return { name: map.fallback, ...map.icons[map.fallback] };
}

export function label(name, opts) {
  return t(resolve(name, opts).label);
}

export function ariaLabel(name, opts) {
  return t(resolve(name, opts).aria);
}

export function names(group = null) {
  return Object.keys(map.icons).filter((n) => group === null || map.icons[n].group === group);
}

function build(name, size, aria, doc) {
  if (!SIZES.includes(size)) throw new Error(`아이콘 크기는 16, 20, 24만 쓴다: ${size}`);
  const strict = metaContent('app-env', doc) === 'dev';
  const entry = resolve(name, { strict });
  const svg = doc.createElementNS(SVG_NS, 'svg');
  svg.setAttribute('class', `icon icon-${size}${entry.animate === 'spin' ? ' icon-spin' : ''}`);
  svg.setAttribute('width', String(size));
  svg.setAttribute('height', String(size));
  svg.setAttribute('focusable', 'false');
  svg.dataset.icon = name;
  if (aria === null) {
    svg.setAttribute('aria-hidden', 'true');
  } else {
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', aria);
  }
  const use = doc.createElementNS(SVG_NS, 'use');
  use.setAttribute('href', `${metaContent('icon-sprite', doc)}#i-${entry.icon}`);
  svg.appendChild(use);
  return svg;
}

export function icon(name, size = 20, doc = globalThis.document) {
  return build(name, size, null, doc);
}

export function meaningfulIcon(name, size = 20, aria = null, doc = globalThis.document) {
  return build(name, size, aria ?? ariaLabel(name), doc);
}

export function iconWithLabel(name, size = 16, text = null, doc = globalThis.document) {
  const span = doc.createElement('span');
  span.className = 'icon-label';
  span.appendChild(icon(name, size, doc));
  const textNode = doc.createElement('span');
  textNode.className = 'icon-label__text';
  textNode.textContent = text ?? label(name);
  span.appendChild(textNode);
  return span;
}

export default map;
