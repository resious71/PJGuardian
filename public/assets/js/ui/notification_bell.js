// 상단 알림 아이콘(P24): 안 읽은 개수 배지 + 미리보기 패널(최근 8건, 모두 읽음, 모두 보기). 레이아웃의 [data-notif-bell]·#notif-panel에 붙는다.
// - 개수: 화면을 열 때 한 번 + 탭이 보이는 동안 <meta name="notification-poll">초마다(숨기면 멈춤). 요청에 X-PJG-Background: 1을 붙여
//   세션 활동으로 치지 않는다(열어 둔 탭이 유휴 만료를 막지 않게). 오류는 조용히 두고 배지는 이전 값 유지. 401이면 갱신을 멈춘다.
// - 패널: 버튼(aria-expanded·aria-controls)으로 열고 Esc·바깥 클릭·포커스가 밖으로 나가면 닫힌다. 항목 사이 ↓↑·Home·End.
//   항목을 누르면 읽음 처리 뒤 대상 화면으로 이동(대상이 없으면 읽음만). 안 읽음은 굵은 글씨 + 점 + "안 읽음" 글자(색만이 아님).
// 모든 글자는 textContent로 넣는다(프로젝트 이름 등).
import { t } from '../core/i18n.js';
import { el, clear, meta } from '../core/dom.js';
import { createApi } from '../core/api.js';
import { createPoller } from '../core/poll.js';
import { formatDateTime, timezoneFromDocument } from '../core/time.js';
import { icon } from './icons.js';
import { badgeText, bellLabel, notificationText, kindIcon, relativeTime, createUnreadCounter, panelKey } from '../lib/notification_core.js';

const PANEL_SIZE = 8;
const BACKGROUND = { 'X-PJG-Background': '1' };

/** 알림 항목 하나(패널·목록 공통 모양). onOpen(item, event) */
export function notificationItem(item, { onOpen, doc = globalThis.document, now = Date.now(), tz = timezoneFromDocument(doc), tag = 'li', extra = [] } = {}) {
  const unread = item.read_at === null;
  const base = meta('app-base', doc) ?? '';
  const text = notificationText(item);
  const when = relativeTime(item.created_at, now, (iso) => formatDateTime(iso, tz));
  const link = el('a', {
    class: 'notif-item__link', href: item.href ? `${base}${item.href}` : `${base}/notifications`,
    on: { click: (e) => onOpen?.(item, e) },
  }, [
    el('span', { class: 'notif-item__icon', 'aria-hidden': 'true' }, [icon(kindIcon(item), 16, doc)], doc),
    el('span', { class: 'notif-item__body' }, [
      el('span', { class: 'notif-item__text', text }, [], doc),
      el('span', { class: 'notif-item__meta' }, [
        el('span', { class: 'badge notif-item__kind', text: t(`notif.kind.${item.kind}`) }, [], doc),
        el('time', { datetime: item.created_at, title: formatDateTime(item.created_at, tz), text: when }, [], doc),
        unread ? el('span', { class: 'notif-item__unread' }, [el('span', { class: 'notif-dot', 'aria-hidden': 'true' }, [], doc), t('notif.unread_mark')], doc) : null,
      ].filter(Boolean), doc),
    ], doc),
  ], doc);
  return el(tag, { class: `notif-item${unread ? ' is-unread' : ''}`, dataset: { id: item.id } }, [link, ...extra], doc);
}

export function initNotificationBell(doc = globalThis.document) {
  const bell = doc.querySelector('[data-notif-bell]');
  const panel = doc.getElementById('notif-panel');
  if (!bell || !panel) return null;
  const badge = bell.querySelector('[data-notif-badge]');
  const seconds = Math.max(15, Number(meta('notification-poll', doc)) || 60);
  let counter = null;
  const api = createApi({ doc, onUnauthorized: () => counter?.stop() });
  let state = { open: false, index: -1, count: 0 };
  let links = [];

  function showCount(n) {
    const text = badgeText(n);
    badge.textContent = text;
    badge.hidden = text === '';
    bell.setAttribute('aria-label', bellLabel(n));
    bell.title = bellLabel(n);
    doc.dispatchEvent?.(new CustomEvent('pjg:notifications-count', { detail: { count: n } }));
  }

  counter = createUnreadCounter({
    fetchCount: async () => (await api.get('/api/v1/notifications/unread-count', { headers: BACKGROUND }))?.count ?? 0,
    onChange: showCount,
    interval: seconds * 1000,
    createPoller,
    doc,
  });

  // 스크립트가 있으면 링크 대신 패널을 여는 버튼으로 동작한다(없으면 목록 화면으로 이동)
  bell.setAttribute('role', 'button');
  bell.setAttribute('aria-expanded', 'false');
  bell.setAttribute('aria-haspopup', 'true');
  bell.setAttribute('aria-label', t('notif.bell'));

  async function openItem(item, e) {
    e.preventDefault();
    try {
      if (item.read_at === null) await api.post(`/api/v1/notifications/${item.id}/read`, {});
    } catch { /* 읽음 실패여도 이동은 한다 */ }
    if (item.href) {
      globalThis.location.assign(`${meta('app-base', doc) ?? ''}${item.href}`);
    } else {
      await load();
      counter.refresh();
    }
  }

  async function load() {
    clear(panel);
    const readAll = el('button', { type: 'button', class: 'btn btn--small', text: t('notif.read_all'), on: { click: markAll } }, [], doc);
    panel.appendChild(el('div', { class: 'notif-panel__head' }, [el('h2', { class: 'notif-panel__title', text: t('notif.panel_title') }, [], doc), readAll], doc));
    const list = el('ul', { class: 'notif-panel__list', 'aria-label': t('notif.panel_label') }, [], doc);
    panel.appendChild(list);
    panel.appendChild(el('div', { class: 'notif-panel__foot' }, [
      el('a', { href: `${meta('app-base', doc) ?? ''}/notifications`, class: 'notif-panel__all', text: t('notif.view_all') }, [], doc),
    ], doc));
    let items = [];
    try {
      const r = await api.get('/api/v1/notifications', { query: { page: 1, size: PANEL_SIZE } });
      items = r?.data ?? [];
    } catch {
      list.appendChild(el('li', { class: 'notif-panel__empty', text: t('notif.panel_error') }, [], doc));
      links = [];
      state = { ...state, count: 0, index: -1 };
      return;
    }
    if (items.length === 0) list.appendChild(el('li', { class: 'notif-panel__empty', text: t('notif.panel_empty') }, [], doc));
    const now = Date.now();
    for (const it of items) list.appendChild(notificationItem(it, { onOpen: openItem, doc, now }));
    readAll.disabled = !items.some((i) => i.read_at === null) && (counter.value ?? 0) === 0;
    links = [...list.querySelectorAll('.notif-item__link')];
    state = { ...state, count: links.length, index: -1 };
  }

  async function markAll() {
    try {
      await api.post('/api/v1/notifications/read-all', {});
      counter.set(0);
      await load();
      links[0]?.focus();
    } catch { /* 다음 주기 */ }
  }

  async function open(focusFirst) {
    state = { ...state, open: true };
    panel.hidden = false;
    bell.setAttribute('aria-expanded', 'true');
    await load();
    counter.refresh();
    if (focusFirst && links.length > 0) {
      state = { ...state, index: 0 };
      links[0].focus();
    }
  }

  function close(returnFocus) {
    state = { open: false, index: -1, count: state.count };
    panel.hidden = true;
    bell.setAttribute('aria-expanded', 'false');
    if (returnFocus) bell.focus();
  }

  bell.addEventListener('click', (e) => {
    e.preventDefault();
    if (state.open) close(false); else open(e.detail === 0); // 키보드로 열면(detail 0) 첫 항목에 포커스
  });
  bell.addEventListener('keydown', (e) => {
    if (e.key === ' ') { e.preventDefault(); bell.click(); }
    if (e.key === 'ArrowDown' && !state.open) { e.preventDefault(); open(true); }
    if (e.key === 'Escape' && state.open) { e.preventDefault(); close(true); }
  });
  panel.addEventListener('keydown', (e) => {
    const r = panelKey(state, e.key);
    if (r.action === null) return;
    e.preventDefault();
    state = r.state;
    if (r.action === 'close') close(true);
    if (r.action === 'focus') links[state.index]?.focus();
  });
  panel.addEventListener('focusin', (e) => {
    const i = links.indexOf(e.target);
    if (i >= 0) state = { ...state, index: i };
  });
  doc.addEventListener('click', (e) => {
    if (state.open && !panel.contains(e.target) && !bell.contains(e.target)) close(false);
  });
  panel.addEventListener('focusout', (e) => {
    const to = e.relatedTarget;
    if (state.open && to && !panel.contains(to) && !bell.contains(to)) close(false);
  });
  // 알림 목록 화면이 읽음·삭제를 하면 배지를 바로 다시 센다
  doc.addEventListener('pjg:notifications-changed', () => counter.refresh());

  counter.start();
  return { counter, open, close };
}
