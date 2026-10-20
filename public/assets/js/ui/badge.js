// 심각도·상태 배지(JS): 아이콘 모양 + 텍스트 + 색(명세 20.1). PHP partials/badge.php와 같은 마크업·클래스.
//   badge('severity.high'), badge('defect_status.open')
import { el } from '../core/dom.js';
import { iconWithLabel } from './icons.js';

export function badge(name, doc = globalThis.document) {
  const modifier = String(name).replace(/[._]/g, '-');
  return el('span', { class: `badge badge--${modifier}`, dataset: { badge: name } }, [iconWithLabel(name, 16, null, doc)], doc);
}
