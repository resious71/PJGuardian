// 페이지네이션(목록 API의 page/size, 명세 6.4·19.7). 이전·다음 아이콘 단독 버튼 + "n / m 페이지".
//   renderPagination(navElement, { page, pages, onChange })
import { el, clear } from '../core/dom.js';
import { t } from '../core/i18n.js';
import { icon } from './icons.js';

export function pageCount(total, size) {
  return Math.max(1, Math.ceil(Math.max(0, total) / Math.max(1, size)));
}

export function renderPagination(nav, { page, pages, onChange = () => {} }, doc = globalThis.document) {
  clear(nav);
  const button = (name, target, disabled) => {
    const label = t(`icon.${name}`);
    return el('button', {
      type: 'button', class: 'btn btn--icon', 'aria-label': label, disabled,
      on: { click: () => onChange(target) },
    }, [icon(name, 20, doc), el('span', { class: 'tooltip', 'aria-hidden': 'true', text: label }, [], doc)], doc);
  };
  nav.appendChild(button('ui.prev', page - 1, page <= 1));
  nav.appendChild(el('span', { class: 'pagination__info', text: t('component.page_of', { page, pages }) }, [], doc));
  nav.appendChild(button('ui.next', page + 1, page >= pages));
}
