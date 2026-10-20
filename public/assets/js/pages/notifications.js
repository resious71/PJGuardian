// 알림 화면(P24): "알림 목록" 탭과 "알림 설정" 탭.
// 목록 — 조건(종류 콤보박스, 안 읽은 알림만)은 "검색"을 눌러야 적용되고(P21V) 적용된 조건·쪽은 주소에 남는다(pushState, 뒤로 가기 popstate 복원).
//   항목: 클릭 → 읽음 + 이동, 항목별 읽음/안 읽음 표시·삭제. 위: "모두 읽음으로 표시"(내 모든 알림), "읽은 알림 모두 삭제"(확인 창).
//   읽음·삭제 뒤에는 헤더 배지에 알린다(pjg:notifications-changed).
// 설정 — 종류별 스위치(role=switch), 바꾸면 바로 저장하고 결과를 role=status로 알림. "기본값으로"(모두 켬).
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { el, clear, meta } from '../core/dom.js';
import { icon } from '../ui/icons.js';
import { renderPagination, pageCount } from '../ui/pagination.js';
import { comboboxFromSelect } from '../ui/combobox.js';
import { confirmDialog } from '../ui/dialog.js';
import { toast } from '../ui/toast.js';
import { errorText } from '../ui/form_dialog.js';
import { notificationItem } from '../ui/notification_bell.js';
import { KINDS, filterFromSearch, searchFromFilter, apiQuery, sameFilter } from '../lib/notification_core.js';

const api = createApi();
const changed = () => document.dispatchEvent(new CustomEvent('pjg:notifications-changed'));

function initList(root) {
  const size = Number(root.dataset.pageSize) || 50;
  const form = root.querySelector('[data-notif-filter]');
  const kindSel = root.querySelector('[data-kind]');
  const unreadBox = root.querySelector('[data-unread]');
  const dirtyNote = root.querySelector('[data-dirty]');
  const count = root.querySelector('[data-count]');
  const loading = root.querySelector('[data-loading]');
  const error = root.querySelector('[data-error]');
  const empty = root.querySelector('[data-empty]');
  const emptyFiltered = root.querySelector('[data-empty-filtered]');
  const list = root.querySelector('[data-items]');
  const pager = root.querySelector('[data-pagination]');
  const readAllBtn = root.querySelector('[data-read-all]');
  const deleteReadBtn = root.querySelector('[data-delete-read]');
  const combo = comboboxFromSelect(kindSel);
  let applied = filterFromSearch(location.search);
  let seq = 0;

  const formState = () => ({ kind: kindSel.value, unread: unreadBox.checked });
  function syncForm() {
    kindSel.value = applied.kind;
    combo.refresh();
    unreadBox.checked = applied.unread;
    updateDirty();
  }
  function updateDirty() {
    const dirty = !sameFilter(formState(), applied);
    dirtyNote.textContent = dirty ? t('notif.dirty') : '';
    dirtyNote.hidden = !dirty;
  }

  async function load() {
    const my = ++seq;
    loading.hidden = false;
    error.hidden = true;
    let r;
    try {
      r = await api.get('/api/v1/notifications', { query: apiQuery(applied, size) });
    } catch (e) {
      if (my !== seq) return;
      loading.hidden = true;
      error.textContent = errorText(e);
      error.hidden = false;
      return;
    }
    if (my !== seq) return; // 마지막 요청의 응답만 그린다
    loading.hidden = true;
    const items = r?.data ?? [];
    const total = r?.page?.total ?? items.length;
    const pages = pageCount(total, size);
    if (items.length === 0 && applied.page > 1 && total > 0) {
      applied = { ...applied, page: pages };
      history.replaceState(null, '', `${location.pathname}${searchFromFilter(applied)}`);
      load();
      return;
    }
    clear(list);
    const filtered = applied.kind !== '' || applied.unread;
    empty.hidden = !(total === 0 && !filtered);
    emptyFiltered.textContent = t('notif.empty_filtered');
    emptyFiltered.hidden = !(total === 0 && filtered);
    count.textContent = total === 0 ? '' : (applied.unread ? t('notif.count_unread', { n: total.toLocaleString('ko-KR') }) : t('notif.count', { n: total.toLocaleString('ko-KR') }));
    const now = Date.now();
    for (const it of items) list.appendChild(notificationItem(it, { onOpen: openItem, now, extra: [actions(it)] }));
    pager.hidden = total <= size;
    renderPagination(pager, { page: applied.page, pages, onChange: (p) => go({ ...applied, page: p }) });
  }

  function actions(it) {
    const unread = it.read_at === null;
    const toggle = el('button', {
      type: 'button', class: 'btn btn--small', text: unread ? t('notif.mark_read') : t('notif.mark_unread'),
      on: { click: async () => {
        try {
          await api.post(`/api/v1/notifications/${it.id}/${unread ? 'read' : 'unread'}`, {});
          changed();
          load();
        } catch (e) { toast(errorText(e), { type: 'error' }); }
      } },
    });
    const del = el('button', {
      type: 'button', class: 'btn btn--small btn--icon-text', 'aria-label': `${t('notif.delete')}`,
      on: { click: async () => {
        try {
          await api.delete(`/api/v1/notifications/${it.id}`);
          toast(t('notif.deleted'), { type: 'success' });
          changed();
          load();
        } catch (e) { toast(errorText(e), { type: 'error' }); }
      } },
    }, [icon('action.delete', 16), t('notif.delete')]);
    return el('div', { class: 'notif-item__actions' }, [toggle, del]);
  }

  async function openItem(item, e) {
    e.preventDefault();
    try {
      if (item.read_at === null) await api.post(`/api/v1/notifications/${item.id}/read`, {});
    } catch { /* 읽음 실패여도 이동 */ }
    if (item.href) location.assign(`${meta('app-base') ?? ''}${item.href}`);
    else { changed(); load(); }
  }

  function go(next, push = true) {
    applied = next;
    if (push) history.pushState(null, '', `${location.pathname}${searchFromFilter(applied)}`);
    syncForm();
    load();
  }

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    go({ ...formState(), page: 1 });
  });
  kindSel.addEventListener('change', updateDirty);
  unreadBox.addEventListener('change', updateDirty);
  addEventListener('popstate', () => go(filterFromSearch(location.search), false));

  readAllBtn.addEventListener('click', async () => {
    readAllBtn.disabled = true;
    try {
      const r = await api.post('/api/v1/notifications/read-all', {});
      toast(r?.updated > 0 ? t('notif.read_all_done', { n: r.updated.toLocaleString('ko-KR') }) : t('notif.read_all_none'), { type: 'success' });
      changed();
      load();
    } catch (e) { toast(errorText(e), { type: 'error' }); } finally { readAllBtn.disabled = false; }
  });
  deleteReadBtn.addEventListener('click', async () => {
    const ok = await confirmDialog({ title: t('notif.delete_read_title'), message: t('notif.delete_read_message'), confirmLabel: t('notif.delete_read_confirm'), danger: true, confirmIcon: 'action.delete' });
    if (!ok) return;
    try {
      const r = await api.delete('/api/v1/notifications/read');
      toast(t('notif.delete_read_done', { n: (r?.deleted ?? 0).toLocaleString('ko-KR') }), { type: 'success' });
      changed();
      load();
    } catch (e) { toast(errorText(e), { type: 'error' }); }
  });

  // 주소에 기본값이 아닌 것이 섞였으면(잘못된 kind 등) 고쳐 둔다
  history.replaceState(null, '', `${location.pathname}${searchFromFilter(applied)}${location.hash}`);
  syncForm();
  load();
}

function initSettings(root) {
  const switches = new Map([...root.querySelectorAll('[data-pref]')].map((b) => [b.dataset.pref, b]));
  const reset = root.querySelector('[data-pref-reset]');
  const status = root.querySelector('[data-pref-status]');
  const show = (prefs) => {
    for (const k of KINDS) {
      const b = switches.get(k);
      if (!b) continue;
      b.setAttribute('aria-checked', String(prefs[k] !== false));
      b.disabled = false;
    }
    reset.disabled = [...switches.keys()].every((k) => prefs[k] !== false);
  };
  async function save(body, message) {
    for (const b of switches.values()) b.disabled = true;
    try {
      show(await api.put('/api/v1/notifications/preferences', body));
      status.textContent = message;
    } catch (e) {
      status.textContent = `${t('notif.pref_failed')} ${errorText(e)}`;
      try { show(await api.get('/api/v1/notifications/preferences')); } catch { /* 그대로 */ }
    }
  }
  for (const [kind, b] of switches) {
    b.addEventListener('click', () => {
      const on = b.getAttribute('aria-checked') !== 'true';
      save({ [kind]: on }, t('notif.pref_saved', { kind: t(`notif.kind.${kind}`), state: t(on ? 'notif.pref_on' : 'notif.pref_off') }));
    });
  }
  // 화면에 있는 종류만(admin 전용 종류 schedule_failed는 admin 화면에만 있다, P26)
  reset.addEventListener('click', () => save(Object.fromEntries([...switches.keys()].map((k) => [k, true])), t('notif.pref_reset_done')));
  api.get('/api/v1/notifications/preferences').then(show).catch((e) => { status.textContent = errorText(e); });
}

const listRoot = document.querySelector('[data-notif-list]');
if (listRoot) initList(listRoot);
const settingsRoot = document.querySelector('[data-notif-settings]');
if (settingsRoot) initSettings(settingsRoot);
