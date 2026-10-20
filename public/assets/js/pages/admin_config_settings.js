// 관리자 "설정 관리"(P27A): GET /api/v1/admin/config-settings로 허용 목록 항목을 읽어 그린다. 모두 textContent(사용자 값 innerHTML 금지).
// P27F: 묶음마다 탭 하나(탭 목록은 응답의 groups = 등록부 묶음 순서 — 화면 코드에 따로 두지 않음), 주소 해시 #<묶음>(예전 #group-<묶음>도),
//   탭 안은 한 열 폼 목록. 모든 탭이 한 폼 상태(edits)를 쓰고 패널은 모두 그려 둔 채 숨기기만 하므로 탭을 옮겨도 입력이 남는다.
// 바꾼 값은 "변경 내용 확인" 대화상자(지금 → 저장 후)를 거쳐 PUT(버전 표지)으로 한 번에 저장한다(전체 성공·전체 실패).
// 경로 항목은 "경로 확인"(POST validate)을 통과해야 저장할 수 있고, 없는 폴더는 확인을 받고 만든다. "기본값으로"는 확인 뒤 DELETE.
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { el, clear } from '../core/dom.js';
import { icon, iconWithLabel } from '../ui/icons.js';
import { confirmDialog } from '../ui/dialog.js';
import { errorText } from '../ui/form_dialog.js';
import { formatDateTime, timezoneFromDocument } from '../core/time.js';
import * as core from '../lib/config_settings_core.js';
import * as backupCore from '../lib/backup_core.js';

const api = createApi();
const root = document.querySelector('[data-config-settings]');

if (root) {
  const $ = (sel) => root.querySelector(sel);
  const panelsBox = $('[data-groups]');
  const tablist = $('[data-tablist]');
  const searchHintBox = $('[data-search-hint]');
  const search = document.querySelector('[data-search]');
  const previewBtn = $('[data-preview]');
  const discardBtn = $('[data-discard]');
  const status = $('[data-status]');
  const errorBox = $('[data-error]');
  const loading = $('[data-loading]');
  const tz = timezoneFromDocument();
  const reloadBtn = $('[data-reload]');
  reloadBtn.textContent = t('config_settings.reload');
  discardBtn.textContent = t('config_settings.discard');
  search.placeholder = t('config_settings.search_placeholder');
  tablist.setAttribute('aria-label', t('config_settings.tabs_label'));
  $('[data-history-title]').textContent = t('config_settings.history_title');

  let data = null;
  let edits = {}; // 키 → 입력 문자열(지금 값과 다를 수도 같을 수도)
  let paths = {}; // 키 → {value, result} 마지막 경로 확인
  const created = new Set(); // 만들기를 확인받은 경로 키
  const errors = new Map(); // 키 → 항목 아래 오류 문장(입력 형식·경로 확인 필요·서버 거부). 탭 머리 오류 표시의 근거
  let tabs = []; // [{id, label, keys}] — core.tabsFrom(data.groups, data.items)
  let current = null; // 지금 탭 id(다시 불러오기·저장 뒤에도 유지)
  // X12: 저장소가 다른 구역(알림 보관 기간 = app_settings, 허용 호스트 = DB 표). 카테고리 탭 패널로 옮겨 보이고(data-merge-into·data-merge-pos),
  // 저장은 각 구역의 단추·API(pages/admin_notifications.js·admin_repo_hosts.js). 구역은 저장 전 변경 수를 data-dirty에 두고 'config-merge-dirty'로 알린다
  const extras = [...root.querySelectorAll('[data-merge-sources] [data-merge-into]')];
  const extrasOf = (tabId, pos) => extras.filter((x) => x.dataset.mergeInto === tabId && (pos === undefined || (x.dataset.mergePos ?? 'end') === pos));
  const extraDirty = (x) => Number(x.dataset.dirty ?? 0) || 0;
  const extraMatches = (x, query) => {
    const words = String(query ?? '').toLowerCase().split(/\s+/).filter(Boolean);
    const hay = x.textContent.toLowerCase();
    return words.every((w) => hay.includes(w));
  };
  /** 설정 항목 + 구역(구역 하나 = 1건) 검색 건수 — 검색어가 없으면 null(core.searchCounts와 같음) */
  function searchCountsAll(query) {
    const counts = core.searchCounts(tabs, data.items, query);
    if (counts) for (const x of extras) if (x.dataset.mergeInto in counts && extraMatches(x, query)) counts[x.dataset.mergeInto] += 1;
    return counts;
  }
  /** 저장 전 변경 수 = 설정 항목 + 구역(입력 보존·이동 확인에 같이 센다) */
  const pendingAll = () => (data ? core.pendingChanges(data.items, edits).length : 0) + extras.reduce((n, x) => n + extraDirty(x), 0);
  document.addEventListener('config-merge-dirty', () => refreshTabHeads());

  const itemOf = (key) => data.items.find((i) => i.key === key);
  const inputOf = (key) => root.querySelector(`[data-input="${CSS.escape(key)}"]`);
  const rowOf = (key) => root.querySelector(`[data-item="${CSS.escape(key)}"]`);

  function showError(message) {
    errorBox.textContent = message;
    errorBox.hidden = !message;
  }

  function fieldError(key, message) {
    if (message) errors.set(key, message); else errors.delete(key);
    const row = rowOf(key);
    if (!row) return;
    const box = row.querySelector('[data-field-error]');
    const input = inputOf(key);
    box.textContent = message ?? '';
    box.hidden = !message;
    if (message) input?.setAttribute('aria-invalid', 'true'); else input?.removeAttribute('aria-invalid');
  }

  // 저장을 막는 키: 형식 오류, 확인을 통과하지 않은 경로(같은 값으로), 서버가 거부한 항목(고칠 때까지)
  function blockingKeys(changes) {
    const keys = new Set([...errors.keys()].filter((k) => k in edits));
    for (const c of changes) {
      if (core.checkInput(c.item, c.after)) keys.add(c.key);
      if (c.item.type === 'path' && !(paths[c.key]?.value === c.after && core.pathOk(paths[c.key].result))) keys.add(c.key);
    }
    return [...keys];
  }

  function refreshBar() {
    const changes = data ? core.pendingChanges(data.items, edits) : [];
    previewBtn.textContent = t('config_settings.preview', { n: changes.length });
    // P27F: 막는 오류가 있어도 누를 수 있다 — 누르면 첫 오류 탭으로 옮겨 그 항목에 포커스(대화상자는 열지 않음)
    previewBtn.disabled = changes.length === 0;
    previewBtn.dataset.blocked = changes.length && blockingKeys(changes).length ? '1' : '0';
    discardBtn.disabled = changes.length === 0;
    for (const item of data?.items ?? []) {
      const row = rowOf(item.key);
      const changed = changes.some((c) => c.key === item.key);
      row?.classList.toggle('config-item--changed', changed);
      const mark = row?.querySelector('[data-changed-mark]');
      if (mark) mark.hidden = !changed;
    }
    refreshTabHeads();
  }

  function badge(text, kind) {
    return el('span', { class: `config-item__badge config-item__badge--${kind}`, text });
  }

  /** 지금 화면의 값(고친 값 우선) — 흐리게 판단·디스크 추정용 */
  const currentValue = (key) => (key in edits ? edits[key] : itemOf(key)?.value);

  /** 입력 칸: 선택 목록(enum)은 select, 켜기/끄기(bool)는 체크박스(P27E), 나머지는 글자 칸 */
  function inputFor(item, id, value) {
    const common = { id, 'aria-describedby': `${id}-help ${id}-hint ${id}-err`, dataset: { input: item.key } };
    if (item.type === 'enum' && Array.isArray(item.values)) {
      const select = el('select', { class: 'field__input config-item__select', ...common },
        item.values.map((v) => el('option', { value: v, text: core.valueLabel(item, v), selected: v === value })));
      return [select, () => select.value, 'change'];
    }
    if (item.type === 'bool') {
      const box = el('input', { type: 'checkbox', class: 'config-item__check', ...common, checked: value === 'true' });
      return [box, () => (box.checked ? 'true' : 'false'), 'change'];
    }
    const input = el('input', { class: 'field__input', type: 'text', value, spellcheck: 'false', autocomplete: 'off', ...common });
    return [input, () => input.value, 'input'];
  }

  function renderItem(item) {
    const id = `cs-${item.key.replace('.', '-')}`;
    const value = item.key in edits ? edits[item.key] : item.value;
    const [input, read, eventName] = inputFor(item, id, value);
    if (item.env_locked) input.disabled = true;
    input.addEventListener(eventName, () => {
      edits[item.key] = read();
      fieldError(item.key, core.checkInput(item, read()));
      if (item.type === 'path') updatePathResult(item, null);
      if (item.type === 'bool') input.nextElementSibling.textContent = core.boolText(read());
      refreshBar();
      refreshInactive();
      refreshBackupEstimate();
      refreshMailExtras();
    });

    const st = core.statusOf(item, data.worker, data.version);
    const head = el('div', { class: 'config-item__head' }, [
      el('label', { class: 'config-item__label', for: id, text: core.labelOf(item) }),
      badge(core.sourceText(item.source), `source-${item.source}`),
      item.machine_specific ? badge(t('config_settings.machine_specific'), 'machine') : null,
      el('span', { class: 'config-item__changed', dataset: { changedMark: '' }, hidden: true, text: t('config_settings.changed_mark') }),
    ].filter(Boolean));
    head.querySelector('[data-changed-mark]').hidden = true;

    const hint = [core.hintOf(item), core.rangeOf(item)].filter(Boolean).join(' · ');
    const control = el('div', { class: 'config-item__control' }, item.type === 'bool'
      ? [input, el('span', { class: 'config-item__check-text', 'aria-hidden': 'true', text: core.boolText(value) })]
      : [input]);
    // 한 열 폼: 왼쪽 = 이름·배지·도움말, 오른쪽 = 입력·안내·오류·상태(넓은 화면), 좁으면 위아래로
    const about = el('div', { class: 'config-item__about' }, [
      head,
      el('p', { class: 'config-item__help muted', id: `${id}-help`, text: core.helpOf(item) }),
    ]);
    const field = el('div', { class: 'config-item__field' }, [
      control,
      el('p', { class: 'config-item__hint muted', id: `${id}-hint`, text: hint }),
      el('p', { class: 'field__error', id: `${id}-err`, role: 'alert', dataset: { fieldError: '' }, hidden: true }),
      // P27E: 지금 방식에 쓰이지 않는 항목은 흐리게(비활성) — 이유를 한 줄로(숨기지 않음)
      el('p', { class: 'config-item__inactive muted', id: `${id}-inactive`, dataset: { inactive: '' }, hidden: true }),
    ]);
    if (item.type === 'path') {
      const check = el('button', { type: 'button', class: 'btn', dataset: { checkPath: item.key } }, [iconWithLabel('action.check_path', 16)]);
      check.disabled = item.env_locked;
      check.addEventListener('click', () => checkPath(item));
      control.append(check);
      field.append(el('p', { class: 'config-item__path', role: 'status', 'aria-live': 'polite', dataset: { pathResult: item.key } }));
    }
    const meta = el('p', { class: 'config-item__meta muted' }, [
      el('span', { text: t('config_settings.base', { value: item.base.value === '' ? '—' : core.shortValue(item.base.value), source: core.sourceText(item.base.source) }), title: item.base.value.length > 60 ? item.base.value : undefined }),
      el('span', { class: `config-item__status config-item__status--${st}`, dataset: { applyStatus: st }, text: t(`config_settings.status.${st}`) }),
    ]);
    field.append(meta);
    if (item.env_locked) {
      field.append(el('p', { class: 'config-item__env' }, [icon('ui.read_only', 16), el('span', { text: t('config_settings.env_locked', { env: item.env_name }) })]));
    } else if (item.source === 'screen') {
      const reset = el('button', { type: 'button', class: 'btn btn--small', dataset: { reset: item.key } }, [iconWithLabel('action.reset_default', 16)]);
      reset.addEventListener('click', () => resetItem(item));
      meta.append(reset);
    }
    const row = el('div', { class: 'config-item', dataset: { item: item.key } }, [about, field]);
    if (errors.has(item.key)) {
      const box = row.querySelector('[data-field-error]');
      box.textContent = errors.get(item.key);
      box.hidden = false;
      input.setAttribute('aria-invalid', 'true');
    }
    return row;
  }

  // ---- 탭(WAI-ARIA tabs: ←→·Home·End로 이동과 선택, 지금 탭만 Tab 순서) ----
  const tabButton = (id) => tablist.querySelector(`[data-tab="${CSS.escape(id)}"]`);

  // P29C: 머리의 "이 화면 도움말"을 지금 탭에 맞는 관리자 가이드 절로(GuideLinks::CONFIG_TABS, 없는 탭은 서버가 준 기본 절 그대로)
  let helpTabs = {};
  try { helpTabs = JSON.parse(root.dataset.helpTabs ?? '{}'); } catch { helpTabs = {}; }
  const helpLink = document.querySelector('.page-help a.help-link[data-guide-audience="admin"]');
  const helpDefault = helpLink?.dataset.guide ?? null;
  function updateHelpLink(tabId) {
    if (!helpLink) return;
    const key = helpTabs[tabId] ?? helpDefault;
    helpLink.dataset.guide = key;
    helpLink.href = helpLink.href.replace(/#.*$/, `#${key}`);
  }

  function selectTab(id, { focus = false, writeHash = true } = {}) {
    if (!tabs.some((tab) => tab.id === id)) id = tabs[0]?.id ?? null;
    current = id;
    for (const tab of tabs) {
      const on = tab.id === id;
      const btn = tabButton(tab.id);
      btn.setAttribute('aria-selected', String(on));
      btn.tabIndex = on ? 0 : -1;
      document.getElementById(`panel-${tab.id}`).hidden = !on;
    }
    if (focus) tabButton(id)?.focus();
    if (writeHash && id && location.hash !== `#${id}`) history.replaceState(null, '', `${location.pathname}${location.search}#${id}`);
    updateHelpLink(id);
    applySearch();
  }

  function renderTabs() {
    clear(tablist);
    clear(panelsBox);
    for (const tab of tabs) {
      const btn = el('button', {
        type: 'button', role: 'tab', class: 'config-tab', id: `cs-tab-${tab.id}`, 'aria-controls': `panel-${tab.id}`, dataset: { tab: tab.id },
      }, [
        el('span', { class: 'config-tab__name', text: tab.label }),
        el('span', { class: 'config-tab__count', 'aria-hidden': 'true', dataset: { tabChanges: '' }, hidden: true }),
        el('span', { class: 'config-tab__error', 'aria-hidden': 'true', dataset: { tabErrors: '' }, hidden: true }),
        el('span', { class: 'config-tab__matches', 'aria-hidden': 'true', dataset: { tabMatches: '' }, hidden: true }),
      ]);
      btn.addEventListener('click', () => selectTab(tab.id));
      btn.addEventListener('keydown', (e) => {
        const i = tabs.indexOf(tab);
        const next = { ArrowRight: tabs[(i + 1) % tabs.length], ArrowLeft: tabs[(i - 1 + tabs.length) % tabs.length], Home: tabs[0], End: tabs[tabs.length - 1] }[e.key];
        if (next) {
          e.preventDefault();
          selectTab(next.id, { focus: true });
        }
      });
      tablist.append(btn);
      const items = tab.keys.map(itemOf);
      const list = el('div', { class: 'config-list' }, items.map(renderItem));
      // X12: 다른 저장소 구역이 있는 탭은 설정 항목에도 구역 제목을 달아 구분한다(구역은 다시 그려도 같은 노드 — 입력·이벤트 유지)
      const merged = extrasOf(tab.id).length > 0;
      panelsBox.append(el('section', { class: 'config-panel', role: 'tabpanel', id: `panel-${tab.id}`, 'aria-labelledby': `cs-tab-${tab.id}`, tabindex: '0', dataset: { group: tab.id } }, [
        el('h2', { class: 'sr-only', text: tab.label }),
        ...extrasOf(tab.id, 'start'),
        merged ? el('div', { class: 'config-merge config-merge--settings' }, [el('h3', { class: 'config-merge__title', text: t(`config_settings.section.${tab.id}`) }), list]) : list,
        ...extrasOf(tab.id, 'end'),
      ]));
    }
    tablist.hidden = tabs.length === 0;
  }

  /** 탭 머리: 변경 건수(숫자), 오류(아이콘 + "오류 n"), 검색 중이면 일치 건수와 0건 흐리게. 접근 가능한 이름에 같은 내용을 글로 */
  function refreshTabHeads() {
    if (!data) return;
    const changes = core.countByTab(tabs, core.pendingChanges(data.items, edits).map((c) => c.key));
    for (const x of extras) if (x.dataset.mergeInto in changes) changes[x.dataset.mergeInto] += extraDirty(x); // X12: 구역의 저장 전 변경도 탭 머리 건수에
    const errs = core.countByTab(tabs, [...errors.keys()]);
    const found = searchCountsAll(search.value);
    for (const tab of tabs) {
      const btn = tabButton(tab.id);
      if (!btn) continue;
      const c = btn.querySelector('[data-tab-changes]');
      c.textContent = String(changes[tab.id]);
      c.hidden = changes[tab.id] === 0;
      const e = btn.querySelector('[data-tab-errors]');
      clear(e);
      if (errs[tab.id]) e.append(icon('system.error', 16), el('span', { text: t('config_settings.tab_error', { n: errs[tab.id] }) }));
      e.hidden = errs[tab.id] === 0;
      const m = btn.querySelector('[data-tab-matches]');
      m.textContent = found ? `(${found[tab.id]})` : '';
      m.hidden = !found;
      btn.classList.toggle('config-tab--nomatch', !!found && found[tab.id] === 0);
      btn.classList.toggle('config-tab--error', errs[tab.id] > 0);
      btn.setAttribute('aria-label', core.tabAccessibleName(tab, { changes: changes[tab.id], errors: errs[tab.id], matches: found ? found[tab.id] : null }));
    }
  }

  function renderGroups() {
    tabs = core.tabsFrom(data.groups, data.items);
    renderTabs();
    for (const [key, p] of Object.entries(paths)) {
      const item = itemOf(key);
      if (item) updatePathResult(item, p.value === (key in edits ? edits[key] : item.value) ? p.result : null);
    }
    refreshInactive();
    renderBackupEstimate();
    renderLdapExtras();
    renderOidcExtras();
    renderSamlExtras();
    renderMailExtras();
    // 처음엔 주소 해시(#backup, 예전 #group-backup)로, 다시 그릴 때(저장·다시 불러오기)는 지금 탭 그대로
    // (해시가 없으면 주소를 바꾸지 않음, 예전 #group-<묶음>·모르는 값은 실제 탭 주소로 고쳐 씀)
    selectTab(current ?? core.tabFromHash(location.hash, tabs), { writeHash: location.hash !== '' });
  }

  /** 모드에 쓰이지 않는 항목 흐리게(P27E 자동 백업: 꺼짐이면 주기 항목, 방식에 맞지 않는 요일·날짜) */
  function refreshInactive() {
    for (const item of data?.items ?? []) {
      const reason = core.inactiveReason(item.key, currentValue);
      const row = rowOf(item.key);
      if (!row) continue;
      const input = inputOf(item.key);
      const note = row.querySelector('[data-inactive]');
      row.classList.toggle('config-item--inactive', reason !== null);
      if (input && !item.env_locked) input.disabled = reason !== null;
      note.textContent = reason ? t(reason) : '';
      note.hidden = reason === null;
      if (input) {
        const ids = (input.getAttribute('aria-describedby') ?? '').split(' ').filter((x) => x && x !== note.id);
        input.setAttribute('aria-describedby', [...ids, ...(reason ? [note.id] : [])].join(' '));
      }
    }
  }

  // P27E: 백업 보관 항목 바로 아래 디스크 영향 추정(마지막 백업 크기 × 보관 기간 안 개수) — 백업 요약 API에서 크기·여유를 한 번 읽는다
  let backupFacts = null;
  async function renderBackupEstimate() {
    const anchor = rowOf('backup.retention_max_count')?.querySelector('.config-item__field');
    if (!anchor) return;
    if (!panelsBox.querySelector('[data-backup-estimate]')) {
      anchor.append(el('p', { class: 'config-item__estimate', role: 'status', 'aria-live': 'polite', dataset: { backupEstimate: '' } }));
    }
    if (backupFacts === null) {
      try {
        const d = await api.get('/api/v1/admin/backups');
        backupFacts = { lastSize: d.last_success?.size ?? null, free: d.dir_free_bytes ?? null };
      } catch {
        backupFacts = { lastSize: null, free: null };
      }
    }
    refreshBackupEstimate();
  }

  function refreshBackupEstimate() {
    const node = panelsBox.querySelector('[data-backup-estimate]');
    if (!node || backupFacts === null) return;
    const num = (k) => Number(currentValue(k));
    const est = backupCore.diskEstimate({
      enabled: currentValue('backup.enabled') === 'true', mode: currentValue('backup.schedule_mode'), retentionDays: num('backup.retention_days'),
      minCount: num('backup.retention_min_count'), maxCount: num('backup.retention_max_count'), lastSize: backupFacts.lastSize, free: backupFacts.free,
    });
    clear(node);
    if (est) node.append(est.warn ? iconWithLabel('system.warn', 16, backupCore.estimateText(est)) : el('span', { text: backupCore.estimateText(est) }));
    node.dataset.warn = est?.warn ? '1' : '0';
    node.hidden = est === null;
  }

  // ---- P28B LDAP/AD 탭: 검색 계정 비밀번호(설정 저장과 따로, 값은 다시 보이지 않음)·연결 시험(단계별) ----
  // 화면 코드에 탭을 따로 두지 않는다 — 등록부의 ldap.bind_dn 항목이 있을 때만 그 아래·탭 끝에 붙인다
  let ldapStatus = null;
  async function renderLdapExtras() {
    const bindRow = rowOf('ldap.bind_dn');
    if (!bindRow) return;
    const field = bindRow.querySelector('.config-item__field');
    const box = el('div', { class: 'ldap-secret', dataset: { ldapSecret: '' } });
    field.append(box);
    const panel = bindRow.closest('[role="tabpanel"]');
    const test = el('section', { class: 'ldap-test', 'aria-labelledby': 'ldap-test-title', dataset: { ldapTest: '' } });
    panel.append(test);
    try {
      ldapStatus = await api.get('/api/v1/admin/auth/ldap');
    } catch {
      ldapStatus = null;
    }
    renderLdapSecret(box);
    renderLdapTest(test);
  }

  function renderLdapSecret(box) {
    clear(box);
    const state = ldapStatus?.bind_password ?? 'none';
    const stateLine = el('p', { class: 'config-item__meta', role: 'status', 'aria-live': 'polite', dataset: { secretState: state } },
      [state === 'set' ? iconWithLabel('system.ok', 16, t('ldap.secret.set')) : iconWithLabel(state === 'unreadable' ? 'system.error' : 'system.warn', 16, t(`ldap.secret.${state}`))]);
    const input = el('input', { class: 'field__input', type: 'password', id: 'cs-ldap-bind-password', autocomplete: 'new-password', spellcheck: 'false', maxlength: 1024,
      'aria-describedby': 'cs-ldap-bind-password-help' });
    const save = el('button', { type: 'button', class: 'btn', dataset: { secretSave: '' }, text: t(state === 'none' ? 'ldap.secret.save' : 'ldap.secret.replace') });
    const del = el('button', { type: 'button', class: 'btn btn--small btn--danger-outline', dataset: { secretDelete: '' }, text: t('ldap.secret.delete'), hidden: state === 'none' });
    const msg = el('p', { class: 'field__error', role: 'alert', hidden: true });
    save.addEventListener('click', async () => {
      if (input.value === '') { msg.textContent = t('ldap.secret.empty'); msg.hidden = false; input.focus(); return; }
      try {
        await api.put('/api/v1/admin/auth/ldap/bind-password', { password: input.value });
        input.value = '';
        ldapStatus = { ...(ldapStatus ?? {}), bind_password: 'set' };
        renderLdapSecret(box);
        box.querySelector('[data-secret-state]').textContent = '';
        box.querySelector('[data-secret-state]').append(iconWithLabel('system.ok', 16, t('ldap.secret.saved')));
      } catch (e) { msg.textContent = errorText(e); msg.hidden = false; }
    });
    del.addEventListener('click', async () => {
      const ok = await confirmDialog({ title: t('ldap.secret.delete'), message: t('ldap.secret.delete_confirm'), confirmLabel: t('ldap.secret.delete'), danger: true });
      if (!ok) return;
      try {
        await api.delete('/api/v1/admin/auth/ldap/bind-password');
        ldapStatus = { ...(ldapStatus ?? {}), bind_password: 'none' };
        renderLdapSecret(box);
      } catch (e) { msg.textContent = errorText(e); msg.hidden = false; }
    });
    box.append(
      el('label', { class: 'config-item__label', for: 'cs-ldap-bind-password', text: t('ldap.secret.label') }),
      el('p', { class: 'config-item__help muted', id: 'cs-ldap-bind-password-help', text: t('ldap.secret.help') }),
      el('div', { class: 'config-item__control' }, [input, save, del]),
      msg, stateLine,
    );
  }

  function renderLdapTest(box) {
    clear(box);
    const notes = [];
    if (ldapStatus && !ldapStatus.extension_loaded) notes.push(el('p', { class: 'notice notice--error', role: 'status' }, [iconWithLabel('system.error', 16, t('ldap.extension_missing'))]));
    const login = el('input', { class: 'field__input', type: 'text', id: 'cs-ldap-test-login', autocomplete: 'off', spellcheck: 'false', maxlength: 100, 'aria-describedby': 'cs-ldap-test-help' });
    const run = el('button', { type: 'button', class: 'btn', dataset: { ldapTestRun: '' } }, [iconWithLabel('action.verify', 16, t('ldap.test.run'))]);
    const out = el('ol', { class: 'ldap-test__steps', dataset: { ldapSteps: '' }, 'aria-live': 'polite' });
    const err = el('p', { class: 'field__error', role: 'alert', hidden: true });
    run.addEventListener('click', async () => {
      err.hidden = true;
      clear(out);
      out.append(el('li', { class: 'muted', text: t('ldap.test.running') }));
      run.disabled = true;
      try {
        const r = await api.post('/api/v1/admin/auth/ldap/test', { login_id: login.value.trim() || null });
        clear(out);
        for (const s of r.steps) {
          const st = s.ok === true ? 'ok' : (s.ok === false ? 'fail' : 'skipped');
          const icn = st === 'ok' ? 'system.ok' : (st === 'fail' ? 'system.error' : 'system.warn');
          const label = t(`ldap.test.step.${s.step}`);
          const text = st === 'ok' ? t('ldap.test.ok', { step: label }) : (st === 'fail' ? t('ldap.test.fail', { step: label, reason: t(`ldap.reason.${s.code}`) }) : t('ldap.test.skipped', { step: label }));
          const li = el('li', { class: `ldap-test__step ldap-test__step--${st}`, dataset: { step: s.step, state: st } }, [iconWithLabel(icn, 16, text)]);
          if (s.step === 'user_search' && s.ok) {
            const d = s.detail;
            li.append(el('dl', { class: 'ldap-test__detail' }, [
              ['dn', d.dn], ['login', d.login], ['id', d.id], ['name', d.name ?? '—'], ['email', d.email ?? '—'], ['disabled', t(d.disabled ? 'ldap.test.yes' : 'ldap.test.no')],
            ].flatMap(([k, v]) => [el('dt', { text: t(`ldap.test.attr.${k}`) }), el('dd', { class: 'mono', text: String(v) })])));
          }
          out.append(li);
        }
      } catch (e) {
        clear(out);
        err.textContent = errorText(e);
        err.hidden = false;
      } finally {
        run.disabled = false;
      }
    });
    box.append(
      el('h3', { id: 'ldap-test-title', text: t('ldap.test.title') }),
      el('p', { class: 'muted', text: t('ldap.test.intro') }),
      ...notes,
      el('div', { class: 'field' }, [
        el('label', { class: 'field__label', for: 'cs-ldap-test-login', text: t('ldap.test.login_label') }),
        el('div', { class: 'config-item__control' }, [login, run]),
        el('p', { class: 'field__help', id: 'cs-ldap-test-help', text: t('ldap.test.login_help') }),
      ]),
      err, out,
    );
  }

  // ---- P28C SSO(OIDC) 탭: 마이그레이션 안내·클라이언트 비밀(값은 다시 보이지 않음)·리다이렉트 URI 복사·연결 시험(단계별) ----
  // LDAP 탭과 같은 방식 — 등록부의 oidc.client_auth·oidc.redirect_base 항목이 있을 때만 그 아래·탭 끝에 붙인다
  let oidcStatus = null;
  async function renderOidcExtras() {
    const authRow = rowOf('oidc.client_auth');
    if (!authRow) return;
    const panel = authRow.closest('[role="tabpanel"]');
    const notice = el('div', { dataset: { oidcNotice: '' } });
    panel.prepend(notice);
    const box = el('div', { class: 'ldap-secret', dataset: { oidcSecret: '' } });
    authRow.querySelector('.config-item__field').append(box);
    const uris = el('div', { class: 'oidc-uris', dataset: { oidcUris: '' } });
    (rowOf('oidc.redirect_base') ?? authRow).querySelector('.config-item__field').append(uris);
    const test = el('section', { class: 'ldap-test', 'aria-labelledby': 'oidc-test-title', dataset: { oidcTest: '' } });
    panel.append(test);
    try {
      oidcStatus = await api.get('/api/v1/admin/auth/oidc');
    } catch {
      oidcStatus = null;
    }
    if (oidcStatus && !oidcStatus.schema_ready) {
      notice.append(el('p', { class: 'notice notice--error', role: 'status' }, [iconWithLabel('system.error', 16, t('oidc.migration_required'))]));
    }
    renderOidcSecret(box);
    renderOidcUris(uris);
    renderOidcTest(test);
  }

  function renderOidcSecret(box) {
    clear(box);
    const state = oidcStatus?.client_secret ?? 'none';
    const stateLine = el('p', { class: 'config-item__meta', role: 'status', 'aria-live': 'polite', dataset: { secretState: state } },
      [state === 'set' ? iconWithLabel('system.ok', 16, t('oidc.secret.set')) : iconWithLabel(state === 'unreadable' ? 'system.error' : 'system.warn', 16, t(`oidc.secret.${state}`))]);
    const input = el('input', { class: 'field__input', type: 'password', id: 'cs-oidc-client-secret', autocomplete: 'new-password', spellcheck: 'false', maxlength: 1024,
      'aria-describedby': 'cs-oidc-client-secret-help', disabled: oidcStatus ? !oidcStatus.schema_ready : false });
    const save = el('button', { type: 'button', class: 'btn', dataset: { secretSave: '' }, text: t(state === 'none' ? 'oidc.secret.save' : 'oidc.secret.replace'),
      disabled: oidcStatus ? !oidcStatus.schema_ready : false });
    const del = el('button', { type: 'button', class: 'btn btn--small btn--danger-outline', dataset: { secretDelete: '' }, text: t('oidc.secret.delete'), hidden: state === 'none' });
    const msg = el('p', { class: 'field__error', role: 'alert', hidden: true });
    save.addEventListener('click', async () => {
      if (input.value === '') { msg.textContent = t('oidc.secret.empty'); msg.hidden = false; input.focus(); return; }
      try {
        await api.put('/api/v1/admin/auth/oidc/client-secret', { secret: input.value });
        input.value = '';
        oidcStatus = { ...(oidcStatus ?? {}), client_secret: 'set' };
        renderOidcSecret(box);
        box.querySelector('[data-secret-state]').textContent = '';
        box.querySelector('[data-secret-state]').append(iconWithLabel('system.ok', 16, t('oidc.secret.saved')));
      } catch (e) { msg.textContent = errorText(e); msg.hidden = false; }
    });
    del.addEventListener('click', async () => {
      const ok = await confirmDialog({ title: t('oidc.secret.delete'), message: t('oidc.secret.delete_confirm'), confirmLabel: t('oidc.secret.delete'), danger: true });
      if (!ok) return;
      try {
        await api.delete('/api/v1/admin/auth/oidc/client-secret');
        oidcStatus = { ...(oidcStatus ?? {}), client_secret: 'none' };
        renderOidcSecret(box);
      } catch (e) { msg.textContent = errorText(e); msg.hidden = false; }
    });
    box.append(
      el('label', { class: 'config-item__label', for: 'cs-oidc-client-secret', text: t('oidc.secret.label') }),
      el('p', { class: 'config-item__help muted', id: 'cs-oidc-client-secret-help', text: t('oidc.secret.help') }),
      el('div', { class: 'config-item__control' }, [input, save, del]),
      msg, stateLine,
    );
  }

  /** IdP에 등록할 주소(서버가 공개 주소 설정으로 만든 값 — 저장 뒤 다시 불러오면 바뀐 값) */
  function renderOidcUris(box) {
    clear(box);
    if (!oidcStatus) return;
    const line = (id, label, value) => {
      const out = el('span', { class: 'oidc-uris__msg muted', role: 'status', 'aria-live': 'polite' });
      const copy = el('button', { type: 'button', class: 'btn btn--small', dataset: { copy: id }, 'aria-describedby': `cs-oidc-${id}` }, [iconWithLabel('action.copy', 16, t('oidc.redirect.copy'))]);
      copy.addEventListener('click', async () => {
        try {
          await navigator.clipboard.writeText(value);
          out.textContent = t('oidc.redirect.copied');
        } catch {
          out.textContent = t('oidc.redirect.copy_failed');
          const range = document.createRange();
          range.selectNodeContents(box.querySelector(`#cs-oidc-${id}`));
          getSelection().removeAllRanges();
          getSelection().addRange(range);
        }
      });
      return el('div', { class: 'oidc-uris__row' }, [
        el('p', { class: 'config-item__label', text: label }),
        el('div', { class: 'config-item__control' }, [el('code', { class: 'oidc-uris__value mono', id: `cs-oidc-${id}`, text: value }), copy]),
        out,
      ]);
    };
    box.append(line('redirect', t('oidc.redirect.label'), oidcStatus.redirect_uri), line('logout', t('oidc.redirect.logout_label'), oidcStatus.post_logout_uri));
  }

  function renderOidcTest(box) {
    clear(box);
    const run = el('button', { type: 'button', class: 'btn', dataset: { oidcTestRun: '' }, disabled: oidcStatus ? !oidcStatus.schema_ready : false },
      [iconWithLabel('action.verify', 16, t('oidc.test.run'))]);
    const out = el('ol', { class: 'ldap-test__steps', dataset: { oidcSteps: '' }, 'aria-live': 'polite' });
    const err = el('p', { class: 'field__error', role: 'alert', hidden: true });
    run.addEventListener('click', async () => {
      err.hidden = true;
      clear(out);
      out.append(el('li', { class: 'muted', text: t('oidc.test.running') }));
      run.disabled = true;
      try {
        const r = await api.post('/api/v1/admin/auth/oidc/test', {});
        clear(out);
        for (const s of r.steps) {
          const st = s.ok === true ? 'ok' : (s.ok === false ? 'fail' : 'skipped');
          const icn = st === 'ok' ? 'system.ok' : (st === 'fail' ? 'system.error' : 'system.warn');
          const label = t(`oidc.test.step.${s.step}`);
          const text = st === 'ok' ? t('oidc.test.ok', { step: label }) : (st === 'fail' ? t('oidc.test.fail', { step: label, reason: t(`oidc.reason.${s.code}`) }) : t('oidc.test.skipped', { step: label }));
          const li = el('li', { class: `ldap-test__step ldap-test__step--${st}`, dataset: { step: s.step, state: st } }, [iconWithLabel(icn, 16, text)]);
          if (st === 'ok' && s.step === 'jwks') li.append(el('p', { class: 'muted', text: t('oidc.test.keys', { n: s.detail.keys, algs: s.detail.algs.join(', ') }) }));
          if (st === 'ok' && s.step === 'token_endpoint') li.append(el('p', { class: 'muted', text: t('oidc.test.status', { status: s.detail.status }) }));
          out.append(li);
        }
      } catch (e) {
        clear(out);
        err.textContent = errorText(e);
        err.hidden = false;
      } finally {
        run.disabled = false;
      }
    });
    box.append(
      el('h3', { id: 'oidc-test-title', text: t('oidc.test.title') }),
      el('p', { class: 'muted', text: t('oidc.test.intro') }),
      el('div', { class: 'config-item__control' }, [run]),
      err, out,
    );
  }

  // ---- P30B 관리자 이메일 탭: 발송 상태·평문 경고·SMTP 비밀번호(설정 저장과 따로, 값은 다시 보이지 않음)·시험 메일 ----
  // LDAP 탭과 같은 방식 — 등록부의 alert_mail.smtp_user 항목이 있을 때만 탭 위·그 아래·탭 끝에 붙인다
  let mailStatus = null;
  let mailBoxes = null;
  async function renderMailExtras() {
    mailBoxes = null;
    const userRow = rowOf('alert_mail.smtp_user');
    if (!userRow) return;
    const panel = userRow.closest('[role="tabpanel"]');
    const head = el('div', { class: 'mail-status', dataset: { mailStatus: '' } });
    const plain = el('p', { class: 'notice notice--warn mail-plaintext', role: 'status', dataset: { mailPlaintext: '' }, hidden: true },
      [iconWithLabel('system.warn', 16, t('alert_mail.plaintext_warning'))]);
    const tlsRow = rowOf('alert_mail.smtp_tls');
    (tlsRow?.querySelector('.config-item__field') ?? panel).append(plain);
    const box = el('div', { class: 'ldap-secret', dataset: { mailSecret: '' } });
    userRow.querySelector('.config-item__field').append(box);
    const test = el('section', { class: 'ldap-test', 'aria-labelledby': 'mail-test-title', dataset: { mailTest: '' } });
    panel.prepend(head);
    panel.append(test);
    mailBoxes = { head, plain, box, test };
    await reloadMailStatus();
  }

  async function reloadMailStatus() {
    try {
      mailStatus = await api.get('/api/v1/admin/alert-mail');
    } catch {
      mailStatus = null;
    }
    if (!mailBoxes) return;
    renderMailSecret(mailBoxes.box);
    renderMailTest(mailBoxes.test);
    refreshMailExtras();
  }

  /** 입력이 바뀔 때: 평문 경고(저장 전 입력 기준)·상태 줄(저장된 설정 기준)·시험 단추 막힘 이유 */
  function refreshMailExtras() {
    if (!mailBoxes) return;
    mailBoxes.plain.hidden = !core.mailPlaintext(currentValue);
    clear(mailBoxes.head);
    if (mailStatus) {
      const icn = mailStatus.state === 'on' ? 'system.ok' : (mailStatus.state === 'setup' ? 'system.warn' : 'system.unknown');
      mailBoxes.head.dataset.state = mailStatus.state;
      mailBoxes.head.append(el('p', { class: 'config-item__meta', role: 'status' }, [
        el('strong', { text: `${t('alert_mail.status.title')}: ` }), iconWithLabel(icn, 16, core.mailStatusText(mailStatus)),
      ]));
    }
    const run = mailBoxes.test.querySelector('[data-mail-test-run]');
    const why = mailBoxes.test.querySelector('[data-mail-test-why]');
    if (!run) return;
    const block = core.mailTestBlock(mailStatus, data ? core.pendingChanges(data.items, edits).map((c) => c.key) : []);
    run.disabled = block !== null;
    why.textContent = block ? t(block.key, block.params) : '';
    why.hidden = block === null;
  }

  function renderMailSecret(box) {
    clear(box);
    const state = mailStatus?.password ?? 'none';
    const stateLine = el('p', { class: 'config-item__meta', role: 'status', 'aria-live': 'polite', dataset: { secretState: state } },
      [state === 'set' ? iconWithLabel('system.ok', 16, t('alert_mail.secret.set'))
        : iconWithLabel(state === 'unreadable' ? 'system.error' : 'system.warn', 16, t(`alert_mail.secret.${state}`))]);
    const input = el('input', { class: 'field__input', type: 'password', id: 'cs-mail-password', autocomplete: 'new-password', spellcheck: 'false', maxlength: 1024,
      'aria-describedby': 'cs-mail-password-help' });
    const save = el('button', { type: 'button', class: 'btn', dataset: { secretSave: '' }, text: t(state === 'none' ? 'alert_mail.secret.save' : 'alert_mail.secret.replace') });
    const del = el('button', { type: 'button', class: 'btn btn--small btn--danger-outline', dataset: { secretDelete: '' }, text: t('alert_mail.secret.delete'), hidden: state === 'none' });
    const msg = el('p', { class: 'field__error', role: 'alert', hidden: true });
    save.addEventListener('click', async () => {
      if (input.value === '') { msg.textContent = t('alert_mail.secret.empty'); msg.hidden = false; input.focus(); return; }
      try {
        await api.put('/api/v1/admin/alert-mail/password', { password: input.value });
        input.value = '';
        await reloadMailStatus();
        const line = mailBoxes?.box.querySelector('[data-secret-state]');
        if (line) { clear(line); line.append(iconWithLabel('system.ok', 16, t('alert_mail.secret.saved'))); }
      } catch (e) { msg.textContent = errorText(e); msg.hidden = false; }
    });
    del.addEventListener('click', async () => {
      const ok = await confirmDialog({ title: t('alert_mail.secret.delete'), message: t('alert_mail.secret.delete_confirm'), confirmLabel: t('alert_mail.secret.delete'), danger: true });
      if (!ok) return;
      try {
        await api.delete('/api/v1/admin/alert-mail/password');
        await reloadMailStatus();
      } catch (e) { msg.textContent = errorText(e); msg.hidden = false; }
    });
    box.append(
      el('label', { class: 'config-item__label', for: 'cs-mail-password', text: t('alert_mail.secret.label') }),
      el('p', { class: 'config-item__help muted', id: 'cs-mail-password-help', text: t('alert_mail.secret.help') }),
      el('div', { class: 'config-item__control' }, [input, save, del]),
      msg, stateLine,
    );
  }

  function renderMailTest(box) {
    clear(box);
    const to = el('input', { class: 'field__input', type: 'email', id: 'cs-mail-test-to', autocomplete: 'off', spellcheck: 'false', maxlength: 254,
      'aria-describedby': 'cs-mail-test-help cs-mail-test-why' });
    const run = el('button', { type: 'button', class: 'btn', dataset: { mailTestRun: '' } }, [iconWithLabel('action.verify', 16, t('alert_mail.test.run'))]);
    const why = el('p', { class: 'field__help', id: 'cs-mail-test-why', dataset: { mailTestWhy: '' }, hidden: true });
    const out = el('p', { class: 'mail-test__result', role: 'status', 'aria-live': 'polite', dataset: { mailTestResult: '' } });
    const err = el('p', { class: 'field__error', role: 'alert', hidden: true });
    run.addEventListener('click', async () => {
      err.hidden = true;
      clear(out);
      out.append(el('span', { class: 'muted', text: t('alert_mail.test.running') }));
      run.disabled = true;
      try {
        const r = await api.post('/api/v1/admin/alert-mail/test', { to: to.value.trim() || null });
        clear(out);
        out.dataset.state = r.ok ? 'ok' : 'fail';
        out.dataset.code = r.code ?? '';
        out.append(iconWithLabel(r.ok ? 'system.ok' : 'system.error', 16, core.mailTestResultText(r)));
      } catch (e) {
        clear(out);
        err.textContent = errorText(e);
        err.hidden = false;
      } finally {
        refreshMailExtras();
      }
    });
    box.append(
      el('h3', { id: 'mail-test-title', text: t('alert_mail.test.title') }),
      el('p', { class: 'muted', text: t('alert_mail.test.intro') }),
      el('div', { class: 'field' }, [
        el('label', { class: 'field__label', for: 'cs-mail-test-to', text: t('alert_mail.test.to_label') }),
        el('div', { class: 'config-item__control' }, [to, run]),
        el('p', { class: 'field__help', id: 'cs-mail-test-help', text: t('alert_mail.test.to_help') }),
        why,
      ]),
      err, out,
    );
  }

  // ---- P28D SSO(SAML) 탭: 마이그레이션 안내·IdP 메타데이터 가져오기(값 채우기만 — 저장은 아래 확인·저장)·IdP 인증서 만료일·
  //      SP 정보(EntityID·ACS·메타데이터 복사/내려받기)·SP 키 생성/교체/삭제(개인 키는 보이지 않음)·설정 확인(단계별) ----
  let samlStatus = null;
  /** PEM을 붙여 넣으면 머리줄·꼬리줄·공백을 뗀 base64 본문으로(설정 값은 한 줄) */
  const pemBody = (v) => v.replace(/-----(BEGIN|END) CERTIFICATE-----/g, '').replace(/\s+/g, '');
  function setValue(key, value) {
    const input = inputOf(key);
    if (!input || input.disabled) return;
    input.value = value;
    input.dispatchEvent(new Event('input'));
  }
  async function renderSamlExtras() {
    const certRow = rowOf('saml.idp_cert');
    if (!certRow) return;
    const panel = certRow.closest('[role="tabpanel"]');
    const notice = el('div', { dataset: { samlNotice: '' } });
    panel.prepend(notice);
    for (const key of ['saml.idp_cert', 'saml.idp_cert_next']) {
      const input = inputOf(key);
      input?.addEventListener('change', () => {
        const body = pemBody(input.value);
        if (body !== input.value) setValue(key, body);
      });
    }
    const certs = el('div', { class: 'oidc-uris', dataset: { samlCerts: '' } });
    const importBox = el('div', { class: 'ldap-secret', dataset: { samlImport: '' } });
    certRow.querySelector('.config-item__field').append(certs, importBox);
    const spBox = el('div', { class: 'oidc-uris', dataset: { samlSp: '' } });
    (rowOf('saml.sp_entity_id') ?? certRow).querySelector('.config-item__field').append(spBox);
    const keyBox = el('div', { class: 'ldap-secret', dataset: { samlKey: '' } });
    (rowOf('saml.sign_requests') ?? certRow).querySelector('.config-item__field').append(keyBox);
    const test = el('section', { class: 'ldap-test', 'aria-labelledby': 'saml-test-title', dataset: { samlTest: '' } });
    panel.append(test);
    try {
      samlStatus = await api.get('/api/v1/admin/auth/saml');
    } catch {
      samlStatus = null;
    }
    if (samlStatus && !samlStatus.library) {
      notice.append(el('p', { class: 'notice notice--error', role: 'status' }, [iconWithLabel('system.error', 16, t('saml.library_missing'))]));
    } else if (samlStatus && !samlStatus.schema_ready) {
      notice.append(el('p', { class: 'notice notice--error', role: 'status' }, [iconWithLabel('system.error', 16, t('saml.migration_required'))]));
    }
    renderSamlCerts(certs);
    renderSamlImport(importBox);
    renderSamlSp(spBox);
    renderSamlKey(keyBox);
    renderSamlTest(test);
  }

  function renderSamlCerts(box) {
    clear(box);
    const list = samlStatus?.idp_certs ?? [];
    if (list.length === 0) return;
    const warn = samlStatus.warn_days ?? 30;
    box.append(el('p', { class: 'config-item__label', text: t('saml.cert.saved_title') }));
    for (const c of list) {
      const which = t(c.which === 'next' ? 'saml.cert.next' : 'saml.cert.current');
      let line;
      if (!c.valid) line = iconWithLabel('system.error', 16, t('saml.cert.invalid', { which }));
      else if (c.days_left < 0) line = iconWithLabel('system.error', 16, t('saml.cert.expired', { which, date: c.not_after.slice(0, 10) }));
      else if (c.days_left < warn) line = iconWithLabel('system.warn', 16, t('saml.cert.expiring', { which, date: c.not_after.slice(0, 10), n: c.days_left }));
      else line = iconWithLabel('system.ok', 16, t('saml.cert.ok', { which, date: c.not_after.slice(0, 10), n: c.days_left }));
      box.append(el('p', { class: 'config-item__meta', dataset: { samlCert: c.which, state: !c.valid || c.days_left < 0 ? 'error' : (c.days_left < warn ? 'warn' : 'ok') } }, [line]),
        c.valid ? el('p', { class: 'muted oidc-uris__msg', text: t('saml.cert.subject', { subject: c.subject || '—', fp: c.fingerprint }) }) : null);
    }
  }

  function renderSamlImport(box) {
    clear(box);
    const url = el('input', { class: 'field__input', type: 'text', id: 'cs-saml-meta-url', spellcheck: 'false', autocomplete: 'off', maxlength: 1000, 'aria-describedby': 'cs-saml-meta-help',
      placeholder: 'https://idp.example.com/federationmetadata.xml' });
    const xml = el('textarea', { class: 'field__input saml-import__xml', id: 'cs-saml-meta-xml', rows: 3, spellcheck: 'false', 'aria-describedby': 'cs-saml-meta-help' });
    const run = el('button', { type: 'button', class: 'btn', dataset: { samlImportRun: '' }, disabled: samlStatus ? !samlStatus.schema_ready : false }, [iconWithLabel('action.upload', 16, t('saml.import.run'))]);
    const msg = el('p', { class: 'config-item__meta', role: 'status', 'aria-live': 'polite', dataset: { samlImportMsg: '' } });
    const err = el('p', { class: 'field__error', role: 'alert', hidden: true });
    run.addEventListener('click', async () => {
      err.hidden = true;
      msg.textContent = '';
      if (url.value.trim() === '' && xml.value.trim() === '') { err.textContent = t('saml.import.empty'); err.hidden = false; url.focus(); return; }
      run.disabled = true;
      try {
        const r = await api.post('/api/v1/admin/auth/saml/metadata-import', url.value.trim() !== '' ? { url: url.value.trim() } : { xml: xml.value });
        setValue('saml.idp_entity_id', r.idp_entity_id);
        setValue('saml.idp_sso_url', r.idp_sso_url);
        setValue('saml.idp_cert', r.idp_cert);
        setValue('saml.idp_cert_next', r.idp_cert_next);
        msg.append(iconWithLabel('system.ok', 16, t('saml.import.done', { n: r.certs })));
      } catch (e) {
        err.textContent = errorText(e);
        err.hidden = false;
      } finally {
        run.disabled = false;
      }
    });
    box.append(
      el('p', { class: 'config-item__label', text: t('saml.import.title') }),
      el('p', { class: 'config-item__help muted', id: 'cs-saml-meta-help', text: t('saml.import.help') }),
      el('label', { class: 'field__label', for: 'cs-saml-meta-url', text: t('saml.import.url_label') }), url,
      el('label', { class: 'field__label', for: 'cs-saml-meta-xml', text: t('saml.import.xml_label') }), xml,
      el('div', { class: 'config-item__control' }, [run]),
      err, msg,
    );
  }

  /** IdP에 등록할 SP 값(서버가 공개 주소 설정으로 만든 값) — 복사·메타데이터 내려받기 */
  function renderSamlSp(box) {
    clear(box);
    if (!samlStatus) return;
    const line = (id, label, value) => {
      const out = el('span', { class: 'oidc-uris__msg muted', role: 'status', 'aria-live': 'polite' });
      const copy = el('button', { type: 'button', class: 'btn btn--small', dataset: { copy: `saml-${id}` }, 'aria-describedby': `cs-saml-${id}` }, [iconWithLabel('action.copy', 16, t('oidc.redirect.copy'))]);
      copy.addEventListener('click', async () => {
        try {
          await navigator.clipboard.writeText(value);
          out.textContent = t('oidc.redirect.copied');
        } catch {
          out.textContent = t('oidc.redirect.copy_failed');
        }
      });
      return el('div', { class: 'oidc-uris__row' }, [
        el('p', { class: 'config-item__label', text: label }),
        el('div', { class: 'config-item__control' }, [el('code', { class: 'oidc-uris__value mono', id: `cs-saml-${id}`, text: value }), copy]),
        out,
      ]);
    };
    const base = document.querySelector('meta[name="app-base"]')?.content ?? '';
    box.append(
      line('entity', t('saml.sp.entity_id'), samlStatus.sp_entity_id),
      line('acs', t('saml.sp.acs_url'), samlStatus.acs_url),
      line('metadata', t('saml.sp.metadata_url'), samlStatus.metadata_url),
      el('p', {}, [el('a', { href: `${base}/auth/saml/metadata?download=1`, dataset: { samlMetadataDownload: '' } }, [iconWithLabel('action.export', 16, t('saml.sp.metadata_download'))])]),
      el('p', { class: 'muted oidc-uris__msg', text: t('saml.sp.metadata_note') }),
    );
  }

  function renderSamlKey(box) {
    clear(box);
    const state = samlStatus?.sp_key ?? 'none';
    const cert = samlStatus?.sp_cert;
    const needed = samlStatus?.sp_key_needed;
    const stateText = state === 'set' && cert ? t('saml.key.set', { date: cert.not_after.slice(0, 10), fp: cert.fingerprint }) : t(`saml.key.${state}`);
    const stateLine = el('p', { class: 'config-item__meta', role: 'status', 'aria-live': 'polite', dataset: { samlKeyState: state } },
      [iconWithLabel(state === 'set' ? 'system.ok' : (state === 'unreadable' || needed ? 'system.warn' : 'ui.info'), 16, stateText)]);
    const gen = el('button', { type: 'button', class: 'btn', dataset: { samlKeyGenerate: '' }, text: t(state === 'none' ? 'saml.key.generate' : 'saml.key.replace'),
      disabled: samlStatus ? !samlStatus.schema_ready : false });
    const del = el('button', { type: 'button', class: 'btn btn--small btn--danger-outline', dataset: { samlKeyDelete: '' }, text: t('saml.key.delete'), hidden: state === 'none' });
    const msg = el('p', { class: 'field__error', role: 'alert', hidden: true });
    gen.addEventListener('click', async () => {
      if (state !== 'none') {
        const ok = await confirmDialog({ title: t('saml.key.replace'), message: t('saml.key.replace_confirm'), confirmLabel: t('saml.key.replace') });
        if (!ok) return;
      }
      try {
        samlStatus = await api.post('/api/v1/admin/auth/saml/sp-key', {});
        renderSamlKey(box);
        renderSamlSp(panelsBox.querySelector('[data-saml-sp]'));
      } catch (e) { msg.textContent = errorText(e); msg.hidden = false; }
    });
    del.addEventListener('click', async () => {
      const ok = await confirmDialog({ title: t('saml.key.delete'), message: t('saml.key.delete_confirm'), confirmLabel: t('saml.key.delete'), danger: true });
      if (!ok) return;
      try {
        samlStatus = await api.delete('/api/v1/admin/auth/saml/sp-key');
        renderSamlKey(box);
      } catch (e) { msg.textContent = errorText(e); msg.hidden = false; }
    });
    box.append(
      el('p', { class: 'config-item__label', text: t('saml.key.label') }),
      el('p', { class: 'config-item__help muted', text: t('saml.key.help') }),
      el('div', { class: 'config-item__control' }, [gen, del]),
      msg, stateLine,
    );
  }

  function renderSamlTest(box) {
    clear(box);
    const run = el('button', { type: 'button', class: 'btn', dataset: { samlTestRun: '' }, disabled: samlStatus ? !samlStatus.schema_ready : false },
      [iconWithLabel('action.verify', 16, t('saml.test.run'))]);
    const out = el('ol', { class: 'ldap-test__steps', dataset: { samlSteps: '' }, 'aria-live': 'polite' });
    const err = el('p', { class: 'field__error', role: 'alert', hidden: true });
    run.addEventListener('click', async () => {
      err.hidden = true;
      clear(out);
      out.append(el('li', { class: 'muted', text: t('saml.test.running') }));
      run.disabled = true;
      try {
        const r = await api.post('/api/v1/admin/auth/saml/test', {});
        clear(out);
        for (const s of r.steps) {
          const st = s.ok === true ? 'ok' : (s.ok === false ? 'fail' : 'skipped');
          const icn = st === 'ok' ? 'system.ok' : (st === 'fail' ? 'system.error' : 'system.warn');
          const label = t(`saml.test.step.${s.step}`);
          const text = st === 'ok' ? t('saml.test.ok', { step: label }) : (st === 'fail' ? t('saml.test.fail', { step: label, reason: t(`saml.reason.${s.code}`) }) : t('saml.test.skipped', { step: label }));
          const li = el('li', { class: `ldap-test__step ldap-test__step--${st}`, dataset: { step: s.step, state: st } }, [iconWithLabel(icn, 16, text)]);
          if (st === 'ok' && s.step === 'idp_cert') {
            for (const c of s.detail.certs) li.append(el('p', { class: 'muted', text: t('saml.test.cert', { which: t(c.which === 'next' ? 'saml.cert.next' : 'saml.cert.current'), date: c.not_after.slice(0, 10), n: c.days_left }) }));
          }
          if (st === 'ok' && s.step === 'sso_url') li.append(el('p', { class: 'muted', text: t('oidc.test.status', { status: s.detail.status }) }));
          out.append(li);
        }
      } catch (e) {
        clear(out);
        err.textContent = errorText(e);
        err.hidden = false;
      } finally {
        run.disabled = false;
      }
    });
    box.append(
      el('h3', { id: 'saml-test-title', text: t('saml.test.title') }),
      el('p', { class: 'muted', text: t('saml.test.intro') }),
      el('div', { class: 'config-item__control' }, [run]),
      err, out,
    );
  }

  function renderBanners() {
    const warnBox = $('[data-warnings]');
    clear(warnBox);
    if (data.warnings.length) {
      warnBox.append(el('p', { text: t('config_settings.warnings_title') }),
        el('ul', {}, data.warnings.map((w) => el('li', { text: t(`config_settings.warn.${w.code}`, { key: w.key ?? '' }) }))));
    }
    warnBox.hidden = data.warnings.length === 0;
    const banner = $('[data-restart-banner]');
    clear(banner);
    const pending = data.worker?.pending_restart ?? [];
    if (pending.length) {
      const names = pending.map((k) => (itemOf(k) ? core.labelOf(itemOf(k)) : k)).join(', ');
      const href = `${document.querySelector('meta[name="app-base"]')?.getAttribute('content') || ''}/admin/worker`;
      banner.append(icon('system.warn', 16), el('span', { text: t('config_settings.restart_banner', { keys: names }) }), ' ',
        el('a', { href, text: t('config_settings.restart_link') }));
    }
    banner.hidden = pending.length === 0;
  }

  function renderHistory() {
    const box = $('[data-history]');
    clear(box);
    $('[data-history-intro]').textContent = t('config_settings.history_intro', { n: data.history.length });
    if (!data.history.length) {
      box.append(el('p', { class: 'muted', text: t('config_settings.history_none') }));
      return;
    }
    const rows = data.history.map((h) => {
      const item = itemOf(h.key);
      return el('tr', {}, [
        el('td', { text: formatDateTime(h.at, tz) }),
        el('td', { text: h.user }),
        el('td', { title: h.key, text: item ? core.labelOf(item) : h.key }),
        el('td', { class: 'mono', text: h.old ?? '' }),
        el('td', { class: 'mono', text: h.new ?? '' }),
      ]);
    });
    box.append(el('table', { class: 'table config-settings__history-table' }, [
      el('thead', {}, [el('tr', {}, ['col_time', 'col_user', 'col_item', 'col_old', 'col_new'].map((k) => el('th', { scope: 'col', text: t(`config_settings.${k}`) })))]),
      el('tbody', {}, rows),
    ]));
  }

  function show(view, { keepEdits = false } = {}) {
    data = view;
    if (!keepEdits) {
      edits = {};
      paths = {};
      created.clear();
      errors.clear();
    }
    loading.hidden = true;
    for (const b of [search, reloadBtn]) b.disabled = false;
    renderBanners();
    renderGroups();
    renderHistory();
    refreshBar();
  }

  /** 검색은 모든 탭에서: 지금 탭은 맞는 항목만, 탭 머리에 건수(0건 흐리게), 지금 탭에 없으면 "다른 탭에 N건" + 그 탭으로 */
  function applySearch() {
    if (!data) return;
    const query = search.value;
    for (const row of panelsBox.querySelectorAll('[data-item]')) row.hidden = !core.matches(itemOf(row.dataset.item), query);
    for (const x of extras) x.hidden = !extraMatches(x, query); // X12: 구역은 이름·설명·호스트 글자로 찾는다
    const counts = searchCountsAll(query);
    const hint = core.searchHint(tabs, counts, current);
    const none = $('[data-search-none]');
    none.textContent = t('config_settings.search_none');
    none.hidden = hint?.kind !== 'none';
    clear(searchHintBox);
    if (hint?.kind === 'other') {
      const label = tabs.find((tab) => tab.id === hint.tab).label;
      const go = el('button', { type: 'button', class: 'btn btn--small', dataset: { searchGo: hint.tab }, text: t('config_settings.search_go', { tab: label }) });
      go.addEventListener('click', () => selectTab(hint.tab, { focus: true }));
      searchHintBox.append(el('span', { text: t('config_settings.search_other', { n: hint.n }) }), ' ', go);
    }
    searchHintBox.hidden = hint?.kind !== 'other';
    // 지금 탭에 일치가 없으면 빈 패널(테두리만 남는 상자)을 감추고 안내만 보인다
    for (const panel of panelsBox.querySelectorAll('[role="tabpanel"]')) panel.classList.toggle('config-panel--empty', !!counts && counts[panel.dataset.group] === 0);
    refreshTabHeads();
  }

  function updatePathResult(item, result) {
    const box = root.querySelector(`[data-path-result="${CSS.escape(item.key)}"]`);
    if (!box) return;
    box.textContent = result ? core.pathResultText(result, core.formatBytes) : '';
    box.className = `config-item__path${result ? (core.pathOk(result) ? ' config-item__path--ok' : ' config-item__path--fail') : ''}`;
    if (result === null) delete paths[item.key];
  }

  async function checkPath(item, create = false) {
    const value = (item.key in edits ? edits[item.key] : item.value).trim();
    const box = root.querySelector(`[data-path-result="${CSS.escape(item.key)}"]`);
    box.textContent = t('config_settings.path_checking');
    try {
      let result = await api.post('/api/v1/admin/config-settings/validate', { key: item.key, value, create });
      if (result.code === 'fail_not_found' && result.can_create && !create) {
        const ok = await confirmDialog({ title: t('config_settings.path_create'), message: t('config_settings.path_create_confirm', { path: result.path ?? value }),
          confirmLabel: t('config_settings.path_create'), warn: true });
        if (ok) {
          result = await api.post('/api/v1/admin/config-settings/validate', { key: item.key, value, create: true });
          if (result.created) created.add(item.key);
        }
      }
      paths[item.key] = { value, result };
      updatePathResult(item, result);
      if (errors.get(item.key) === t('config_settings.path_need_check')) fieldError(item.key, null);
    } catch (e) {
      box.textContent = errorText(e);
    }
    refreshBar();
  }

  function previewDialog(changes) {
    return new Promise((resolve) => {
      const opener = document.activeElement;
      const restart = changes.filter((c) => c.item.restart === 'worker').map((c) => core.labelOf(c.item));
      const cancel = el('button', { type: 'button', class: 'btn', text: t('config_settings.cancel') });
      const save = el('button', { type: 'button', class: 'btn btn--primary', text: t('config_settings.save') });
      const notes = [el('p', { text: t('config_settings.dialog_intro') })];
      if (changes.some((c) => c.item.type === 'path')) notes.push(el('p', { text: t('config_settings.dialog_path') }));
      if (restart.length) notes.push(el('p', { class: 'config-dialog__restart' }, [icon('system.warn', 16), el('span', { text: t('config_settings.dialog_restart', { keys: restart.join(', ') }) })]));
      notes.push(el('p', { class: 'muted', text: t('config_settings.dialog_next') }));
      const table = el('table', { class: 'table config-dialog__table' }, [
        el('thead', {}, [el('tr', {}, ['col_item', 'col_before', 'col_after'].map((k) => el('th', { scope: 'col', text: t(`config_settings.${k}`) })))]),
        el('tbody', {}, changes.map((c) => el('tr', {}, [
          el('td', { title: c.key, text: core.labelOf(c.item) }), el('td', { class: 'mono', text: c.before }), el('td', { class: 'mono', text: c.after }),
        ]))),
      ]);
      const dialog = el('dialog', { class: 'dialog dialog--wide', 'aria-labelledby': 'cs-dlg-title' }, [
        el('div', { class: 'dialog__head' }, [el('h2', { class: 'dialog__title', id: 'cs-dlg-title', text: t('config_settings.dialog_title') })]),
        el('div', { class: 'dialog__scroll' }, [...notes, table]),
        el('div', { class: 'dialog__actions' }, [cancel, save]),
      ]);
      let result = false;
      cancel.addEventListener('click', () => dialog.close());
      save.addEventListener('click', () => { result = true; dialog.close(); });
      dialog.addEventListener('close', () => { dialog.remove(); opener?.focus?.(); resolve(result); }, { once: true });
      dialog.addEventListener('keydown', (ev) => {
        if (ev.key !== 'Tab') return;
        if (ev.shiftKey && document.activeElement === cancel) { ev.preventDefault(); save.focus(); }
        else if (!ev.shiftKey && document.activeElement === save) { ev.preventDefault(); cancel.focus(); }
      });
      document.body.append(dialog);
      dialog.showModal();
      cancel.focus();
    });
  }

  /** 오류 항목으로: 그 탭을 열고 검색으로 숨었으면 검색을 비운 뒤 입력 칸에 포커스 */
  function goToKey(key) {
    const tab = core.tabOfKey(tabs, key);
    if (tab) selectTab(tab);
    if (rowOf(key)?.hidden) {
      search.value = '';
      applySearch();
    }
    const input = inputOf(key);
    input?.focus();
    rowOf(key)?.scrollIntoView({ block: 'center' });
  }

  function handleSaveError(e) {
    if (e.code === 'settings_conflict') {
      showError(t('config_settings.conflict'));
      reloadBtn.focus();
      return;
    }
    const keys = e.details?.keys ?? (e.details?.key ? [e.details.key] : []);
    for (const key of keys) {
      const item = itemOf(key);
      if (!item) continue;
      const msg = e.code === 'setting_cross_check' ? t('config_settings.cross_check')
        : e.code === 'setting_path_invalid' ? t(`config_settings.path.${e.details.code}`, { path: '', free: '' })
          : e.code === 'setting_invalid_value' ? core.checkInput(item, '') : errorText(e);
      fieldError(key, msg);
    }
    showError(errorText(e));
    refreshTabHeads();
    // P27F: 서버가 거부한 항목도 첫 오류 탭으로 옮겨 포커스
    const first = core.firstKeyByTab(tabs, keys);
    if (first) goToKey(first.key);
  }

  previewBtn.addEventListener('click', async () => {
    const changes = core.pendingChanges(data.items, edits);
    if (!changes.length) return;
    const blocked = blockingKeys(changes);
    if (blocked.length) {
      // 저장 시도 때 막는 오류가 있으면 첫 오류 탭(탭 순서·항목 순서)으로 옮겨 그 항목에 포커스
      for (const key of blocked) {
        const item = itemOf(key);
        if (!errors.has(key) && item?.type === 'path') fieldError(key, t('config_settings.path_need_check'));
      }
      const first = core.firstKeyByTab(tabs, blocked);
      refreshBar();
      if (first) {
        status.textContent = t('config_settings.save_blocked', { tab: tabs.find((tab) => tab.id === first.tab).label, label: core.labelOf(itemOf(first.key)) });
        goToKey(first.key);
      }
      return;
    }
    if (!(await previewDialog(changes))) return;
    // P30: 감사 로그 보존을 90일(명세 기본)보다 짧게 — 다음 정리 때 되돌릴 수 없게 지워지므로 한 번 더 확인
    const auditChange = changes.find((c) => c.key === 'log.audit_retention');
    if (auditChange && core.auditRetentionTooShort(auditChange.after)) {
      const ok = await confirmDialog({ title: t('config_settings.audit_short_title'), message: t('config_settings.audit_short_message', { value: auditChange.after }),
        confirmLabel: t('config_settings.audit_short_confirm'), danger: true });
      if (!ok) return;
    }
    showError('');
    status.textContent = t('config_settings.saving');
    previewBtn.disabled = true;
    try {
      const body = { changes: Object.fromEntries(changes.map((c) => [c.key, c.after])), version: data.version, create_paths: [...created] };
      show(await api.put('/api/v1/admin/config-settings', body));
      status.textContent = t('config_settings.saved', { n: changes.length });
    } catch (e) {
      status.textContent = '';
      handleSaveError(e);
      refreshBar();
    }
  });

  async function resetItem(item) {
    const label = core.labelOf(item);
    const params = { label, value: item.base.value === '' ? '—' : item.base.value, source: core.sourceText(item.base.source) };
    const ok = await confirmDialog({ title: t('config_settings.reset_confirm_title'), message: t('config_settings.reset_confirm', params),
      confirmLabel: t('icon.action.reset_default'), confirmIcon: 'action.reset_default', warn: true });
    if (!ok) return;
    showError('');
    try {
      show(await api.delete(`/api/v1/admin/config-settings/${encodeURIComponent(item.key)}`, { query: { version: data.version } }), { keepEdits: true });
      delete edits[item.key];
      errors.delete(item.key);
      renderGroups();
      refreshBar();
      status.textContent = t('config_settings.reset_done', params);
    } catch (e) {
      handleSaveError(e);
    }
  }

  discardBtn.addEventListener('click', () => {
    edits = {};
    paths = {};
    created.clear();
    errors.clear();
    renderGroups();
    refreshBar();
    showError('');
    status.textContent = '';
  });

  async function load() {
    showError('');
    try {
      show(await api.get('/api/v1/admin/config-settings'));
    } catch (e) {
      loading.hidden = true;
      showError(`${t('config_settings.load_failed')} ${errorText(e)}`);
    }
  }
  reloadBtn.addEventListener('click', () => { status.textContent = ''; load(); });
  search.addEventListener('input', applySearch);
  // 주소 해시가 바뀌면(뒤로 가기·같은 화면 안 링크) 그 탭으로. 예전 #group-<묶음>·모르는 해시는 실제 탭 주소로 고쳐 씀
  window.addEventListener('hashchange', () => { if (tabs.length) selectTab(core.tabFromHash(location.hash, tabs), { writeHash: location.hash !== '' }); });
  // X10: 시스템 관리의 다른 탭(바깥 탭 — 다른 화면)으로 갈 때 저장 전 변경이 있으면 확인. 안쪽 카테고리 탭 사이는 P27F처럼 한 폼 상태라 그대로 유지
  document.querySelector('[data-system-subnav]')?.addEventListener('click', async (ev) => {
    const a = ev.target.closest('a[href]');
    if (!a || a.getAttribute('aria-current') === 'page' || ev.button !== 0 || ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.altKey) return;
    const n = pendingAll(); // X12: 알림 보관·허용 호스트 구역의 저장 전 변경 포함
    if (!n) return;
    ev.preventDefault();
    const ok = await confirmDialog({ title: t('config_settings.leave_title'), message: t('config_settings.leave_message', { n }), confirmLabel: t('config_settings.leave_confirm'), danger: true });
    if (ok) location.href = a.href;
  });
  previewBtn.textContent = t('config_settings.preview', { n: 0 });
  load();
}
