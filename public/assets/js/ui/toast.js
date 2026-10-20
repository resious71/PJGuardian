// 토스트 알림. 레이아웃의 #toasts(role=status, aria-live=polite) 영역에 넣는다.
// 종류: success | warn | error | info. 아이콘 + 텍스트 + 색(명세 20.1).
import { el } from '../core/dom.js';
import { t } from '../core/i18n.js';
import { icon, meaningfulIcon } from './icons.js';

const ICON = { success: 'system.ok', warn: 'system.warn', error: 'system.error', info: 'ui.info' };

export function toast(message, { type = 'info', timeout = 5000, doc = globalThis.document } = {}) {
  const region = doc.getElementById('toasts');
  if (!region) return null;
  const close = el('button', { type: 'button', class: 'btn btn--icon', 'aria-label': t('component.close') }, [icon('ui.close', 16, doc)], doc);
  const node = el('div', { class: `toast toast--${type}` }, [
    meaningfulIcon(ICON[type] ?? 'ui.info', 20, null, doc),
    el('span', { class: 'toast__message', text: message }, [], doc),
    close,
  ], doc);
  const remove = () => node.remove();
  close.addEventListener('click', remove);
  region.appendChild(node);
  if (timeout > 0 && type !== 'error') setTimeout(remove, timeout); // 오류는 사용자가 닫을 때까지 남긴다
  return node;
}
