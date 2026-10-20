// 규칙 설명 목록(P22, 명세 11장). 기준은 활성 도구 버전(GET /api/v1/rules/meta). 값은 textContent로만(innerHTML 금지).
// 검색 영역은 결함 검색(P21V)과 같은 규칙: 입력·선택만으로는 요청하지 않고, 검색 버튼·검색어 칸 Enter로 적용한다. 쪽 이동은 적용된 조건으로 즉시.
// 단일 선택 상자(카테고리·예/아니오·정렬)는 콤보박스(ui/combobox.js), 기본 심각도는 다중 선택 칩(건수 없음).
// X08: 언어 탭(Python · ruff / JavaScript/TypeScript · ESLint)이 도구 조건을 대신한다. 탭을 바꾸면 새 탭은 기본 조건으로 시작하고(탭마다 독립),
// 주소에는 현재 탭의 조건만(language=…). 페이지를 다시 불러오지 않고 목록만 갱신, 이동 기록에 남겨 뒤로 가기가 탭을 되돌린다.
// P22C(admin만 — 서버가 data-admin·선택 열·일괄 막대를 그린다): "분석 적용" 칸의 스위치(상세와 같은 확인 창, 그 행만 갱신),
// 선택 열(이 쪽 안에서만 유지 — 쪽 이동·새 검색·탭 변경이면 해제)과 일괄 막대(선택한 규칙 / 검색 결과 전체 → 미리보기 → 확인 → 적용,
// 409 revision_changed면 다시 미리보기). 검색 결과 전체 = 마지막으로 적용한 조건(입력 중인 조건 아님).
// P23 내보내기: 표 위 오른쪽 Excel·CSV 버튼 — 현재 탭(language)과 적용된 검색·필터·정렬의 결과 전체(bulkFilter + 정렬). 누구나(읽기 전용).
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { el, clear, setText, meta as metaTag } from '../core/dom.js';
import { iconWithLabel, icon } from '../ui/icons.js';
import { badge } from '../ui/badge.js';
import { clipText, tipTarget } from '../ui/clip.js';
import { renderPagination, pageCount } from '../ui/pagination.js';
import { comboboxFromSelect } from '../ui/combobox.js';
import { toast } from '../ui/toast.js';
import { errorText } from '../ui/form_dialog.js';
import { confirmRuleOverride, confirmBulk } from '../ui/rule_override_dialog.js';
import { SEVERITIES, emptyState, stateFromSearch, searchFromState, apiQuery, isDirty, isFiltered, toggle, resolveLanguage, bulkFilter } from '../lib/rule_search.js';
import { basisText, staleTools, fixableText, statusParts, tabCountText, needsTypedConfirm, bulkDoneText, selectionText, offerAllResults } from '../lib/rule_core.js';
import { toolOf, LANGUAGES } from '../lib/tool_language.js';
import { initTabList } from '../ui/tabs.js';
import { mountExport } from '../ui/export_buttons.js';

const LAST_TAB_KEY = 'pjg.rules.language';
/** 단일 선택 상자(콤보박스) 이름 */
const SELECTS = ['category', 'analysis', 'overridden', 'fixable', 'deprecated', 'change', 'sort', 'order'];

/** "분석 적용" 칸(P22B): 적용 중/꺼짐 + 관리자 변경 배지(아이콘 + 짧은 글자 "변경됨", 툴팁·접근성 이름에 "관리자 변경 · 기본값").
 *  P22C: admin이면 앞에 스위치(role=switch). 켤 수 없는 규칙이 꺼져 있으면 스위치 비활성 + 이유(툴팁·화면 읽기 설명) */
function analysisCell(it, onSwitch = null) {
  const parts = [];
  if (onSwitch) {
    const blocked = !it.analysis_enabled && !it.enableable;
    const why = blocked ? t('rules.switch_blocked', { why: t(`rules.block.${it.enable_block}`) }) : null;
    const descId = blocked ? `rule-why-${it.key.replace(/[^A-Za-z0-9_-]/g, '_')}` : null;
    const sw = el('button', { type: 'button', class: 'switch', role: 'switch', 'aria-checked': String(it.analysis_enabled),
      'aria-label': t('rules.switch_label', { key: it.key }), disabled: blocked, title: why, 'aria-describedby': descId, dataset: { switch: it.key } });
    sw.addEventListener('click', () => onSwitch(it, sw));
    parts.push(sw);
    if (blocked) parts.push(el('span', { class: 'sr-only', id: descId, text: why }));
  }
  parts.push(el('span', { class: 'rule-analysis', text: t(it.analysis_enabled ? 'rules.analysis_on' : 'rules.analysis_off') }));
  if (it.overridden) {
    const base = t(it.default_enabled ? 'admin_rule_settings.base_on' : 'admin_rule_settings.base_off');
    const tip = t('rules.overridden_tip', { value: base });
    parts.push(' ', el('span', { class: 'tag rule-overridden', title: tip, 'aria-label': tip, role: 'img', dataset: { overridden: '1' } },
      [icon('ui.changed', 16), t('rules.overridden_short')]));
  }
  return parts;
}
/** 이 브라우저의 마지막 탭(읽기·쓰기 실패는 무시) */
function storedTab() {
  try { return localStorage.getItem(LAST_TAB_KEY); } catch { return null; }
}
function rememberTab(language) {
  try { localStorage.setItem(LAST_TAB_KEY, language); } catch { /* 저장소 없음 */ }
}

const root = document.querySelector('[data-rules]');
if (root) init(root);

function init(section) {
  const api = createApi();
  const base = metaTag('app-base') ?? '';
  const size = Number(section.dataset.pageSize) || 50;
  const form = section.querySelector('[data-filter-form]');
  const $ = (sel) => section.querySelector(sel);
  const dirtyMark = $('[data-search-dirty]');
  let applied = stateFromSearch(location.search, storedTab());
  let draft = { ...applied, severity: [...applied.severity] };
  // 주소에 language가 없거나 예전 tool=이면 language=로 고쳐 쓴다(이동 기록을 늘리지 않음)
  if (resolveLanguage(location.search, storedTab()).rewrite) history.replaceState(null, '', `${location.pathname}${searchFromState(applied)}`);
  rememberTab(applied.language);
  const tool = () => toolOf(applied.language);
  let meta = null;
  let seq = 0;
  const combos = {};
  const field = (name) => form.elements.namedItem(name);
  const isAdmin = section.dataset.admin === '1';
  // 일괄 선택(admin): 이 쪽 안의 규칙 키, 검색 결과 전체 여부, 마지막으로 그린 쪽(키·전체 수)
  const selected = new Set();
  let allResults = false;
  let shown = { keys: [], total: 0 };

  // ---- 폼 ↔ draft ----
  function fillForm(s) {
    field('q').value = s.q;
    field('prefix').value = s.prefix;
    for (const k of SELECTS) field(k).value = s[k];
    for (const c of form.querySelectorAll('[data-facet="severity"] .chip')) c.setAttribute('aria-pressed', String(s.severity.includes(c.dataset.value)));
    Object.values(combos).forEach((c) => c.refresh());
  }
  let shownTotal = 0;
  const exporter = mountExport(section.querySelector('[data-export]'), {
    url: '/api/v1/rules/export',
    getBody: () => ({ ...bulkFilter(applied), sort: applied.sort, order: applied.order }),
  });
  function syncDirty() {
    const dirty = isDirty(draft, applied);
    exporter.update({ total: shownTotal, dirty });
    dirtyMark.classList.toggle('is-dirty', dirty);
    setText(dirtyMark, dirty ? t('defects.search_dirty') : '');
  }
  function readForm() {
    draft.q = field('q').value.trim();
    draft.prefix = field('prefix').value.trim();
    for (const k of SELECTS) draft[k] = field(k).value;
    syncDirty();
  }

  // 기본 심각도 칩(선택만 하고 검색 버튼으로 적용)
  const chips = $('[data-facet="severity"] [data-facet-items]');
  for (const v of SEVERITIES) {
    const chip = el('button', { type: 'button', class: `chip badge badge--severity-${v.replace('_', '-')}`, 'aria-pressed': 'false', dataset: { value: v } }, [iconWithLabel(`severity.${v}`, 16)]);
    chip.addEventListener('click', () => {
      draft.severity = toggle(draft.severity, v);
      chip.setAttribute('aria-pressed', String(draft.severity.includes(v)));
      syncDirty();
    });
    chips.appendChild(chip);
  }

  /** 카테고리 선택지: 현재 탭 도구의 카테고리만 */
  function fillCategories() {
    const sel = field('category');
    const keep = sel.value;
    while (sel.options.length > 1) sel.remove(1);
    if (!meta) return;
    const cats = [...(meta.tools[tool()]?.categories ?? [])].sort();
    for (const c of cats) sel.appendChild(el('option', { value: c, text: c }));
    sel.value = cats.includes(keep) ? keep : '';
    combos.category?.refresh();
  }

  for (const name of SELECTS) {
    const sel = field(name);
    sel.addEventListener('change', readForm);
    combos[name] = comboboxFromSelect(sel);
  }
  field('q').addEventListener('input', readForm);
  field('prefix').addEventListener('input', readForm);

  // ---- 적용·불러오기 ----
  function apply(next, push = true) {
    applied = { ...next, severity: [...next.severity] };
    draft = { ...applied, severity: [...applied.severity] };
    if (push) history.pushState(null, '', `${location.pathname}${searchFromState(applied)}`);
    syncDirty();
    load();
  }

  // ---- 언어 탭: 바꾸면 그 탭의 기본 조건으로(검색·필터·쪽은 탭마다 독립) ----
  const tabs = initTabList(document.querySelector('[data-rule-tabs]'), {
    value: applied.language,
    onSelect: (language) => {
      rememberTab(language);
      const s = emptyState(language);
      applied = s; // fillCategories가 새 탭 도구를 보도록 먼저 바꾼다
      fillCategories();
      fillForm(s);
      renderTabInfo();
      apply(s);
    },
  });
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    readForm();
    apply({ ...draft, page: 1 });
  });
  $('[data-clear-filters]').addEventListener('click', () => {
    const s = emptyState(applied.language);
    fillForm(s);
    fillCategories();
    apply(s);
  });
  window.addEventListener('popstate', () => {
    applied = stateFromSearch(location.search, storedTab());
    draft = { ...applied, severity: [...applied.severity] };
    tabs.set(applied.language);
    fillCategories();
    renderTabInfo();
    fillForm(draft);
    syncDirty();
    load();
  });

  function row(it) {
    const link = el('a', { href: `${base}${it.href}`, class: 'rule-key' }, [el('code', {}, [clipText(it.rule, { focusable: false })])]);
    const status = statusParts(it);
    const cells = [];
    if (isAdmin) {
      const box = el('input', { type: 'checkbox', checked: selected.has(it.rule), 'aria-label': t('rules.select_row', { key: it.key }), dataset: { select: it.rule } });
      box.addEventListener('change', () => {
        if (box.checked) selected.add(it.rule); else selected.delete(it.rule);
        allResults = false;
        renderBulk();
      });
      cells.push(el('td', { class: 'rcol-select' }, [box]));
    }
    return el('tr', { dataset: { rule: it.key } }, [
      ...cells,
      el('td', { class: 'rcol-rule' }, [link]),
      el('td', { class: 'rcol-summary' }, [clipText(it.summary || t('rules.no_description'))]),
      el('td', { class: 'rcol-category' }, [clipText(it.category ?? '—', { focusable: false })]),
      el('td', { class: 'rcol-severity' }, [tipTarget(badge(`severity.${it.severity}`), t(`rules.severity_level.${it.severity_level}`, { group: it.category ?? '—' }))]),
      el('td', { class: 'rcol-default' }, analysisCell(it, isAdmin ? onSwitch : null)),
      el('td', { class: 'rcol-fix', text: fixableText(it.fixable) }),
      el('td', { class: 'rcol-status' }, status.length ? [clipText(status.join(' · '), { focusable: false })] : [el('span', { class: 'muted', text: '—' })]),
    ]);
  }

  async function load() {
    const my = ++seq; // 탭을 연달아 바꿔도 마지막 요청만 반영(오래된 응답은 버림)
    // 상세의 "목록으로"가 이 탭의 마지막 조건으로 돌아오게(탭별)
    try { sessionStorage.setItem(`pjg.rules.search.${applied.language}`, searchFromState(applied)); } catch { /* 저장소 없음 */ }
    section.setAttribute('aria-busy', 'true');
    try {
      const res = await api.get('/api/v1/rules', { query: apiQuery(applied, size) });
      if (my !== seq) return;
      const tb = $('[data-rows]');
      clear(tb);
      // 새 목록이면 선택은 해제(쪽 이동·새 검색·탭 변경·일괄 적용 뒤)
      selected.clear();
      allResults = false;
      shown = { keys: res.data.map((it) => it.rule), total: res.page.total };
      for (const it of res.data) tb.appendChild(row(it));
      renderBulk();
      const total = res.page.total;
      shownTotal = total;
      exporter.update({ total, dirty: isDirty(draft, applied) });
      setText($('[data-count]'), t('rules.count', { total: total.toLocaleString('ko-KR') }));
      $('[data-table]').hidden = total === 0;
      const empty = $('[data-empty]');
      empty.hidden = total !== 0;
      setText(empty, t(!meta?.tools?.[tool()]?.version ? 'rules.empty_none' : (isFiltered(applied) ? 'rules.empty_filtered' : 'rules.empty_none')));
      const pages = pageCount(total, size);
      const pager = $('[data-pagination]');
      pager.hidden = pages <= 1;
      if (pages > 1) renderPagination(pager, { page: applied.page, pages, onChange: (p) => apply({ ...applied, page: p }) });
    } catch (e) {
      if (my !== seq) return;
      setText($('[data-count]'), errorText(e));
    } finally {
      if (my === seq) section.removeAttribute('aria-busy');
    }
  }

  // ---- admin: 행 스위치(상세와 같은 확인 창·같은 서버 함수). 최신 상태(상세 API)로 창을 열고, 끝나면 그 행만 다시 그린다 ----
  const rowBusy = new Set();
  async function onSwitch(it, sw) {
    if (rowBusy.has(it.key)) return; // 연타: 행당 요청 하나
    rowBusy.add(it.key);
    sw.disabled = true;
    try {
      const d = await api.get(`/api/v1/rules/${it.tool}/${it.rule.split('/').map(encodeURIComponent).join('/')}`);
      const kind = d.analysis.enabled ? 'disable' : 'enable';
      const result = d.analysis.enabled === it.analysis_enabled
        ? await confirmRuleOverride({ api, tool: it.tool, rule: it.rule, key: it.key, kind, a: d.analysis, openDefects: d.defects.total })
        : 'conflict'; // 목록을 그린 뒤 이미 바뀜 → 최신으로 다시 그림
      if (result !== null) {
        await refreshRow(it);
        toast(t(result === 'conflict' ? 'rules.override_conflict' : 'rules.override_done'), { type: result === 'conflict' ? 'warn' : 'success' });
      }
    } catch (e) {
      toast(errorText(e), { type: 'error' });
    } finally {
      rowBusy.delete(it.key);
      if (sw.isConnected) sw.disabled = !it.analysis_enabled && !it.enableable; // 취소·오류: 그린 때의 상태로
    }
  }

  /** 한 행만 최신 상태로(쪽·조건·스크롤 위치 유지) */
  async function refreshRow(it) {
    const d = await api.get(`/api/v1/rules/${it.tool}/${it.rule.split('/').map(encodeURIComponent).join('/')}`);
    const next = { ...it, analysis_enabled: d.analysis.enabled, overridden: d.analysis.overridden, enableable: d.analysis.enableable, enable_block: d.analysis.block };
    const tr = $(`[data-rows] tr[data-rule="${CSS.escape(it.key)}"]`);
    if (!tr) return;
    const fresh = row(next);
    tr.replaceWith(fresh);
    const sw = fresh.querySelector('[data-switch]');
    if (sw && !sw.disabled) sw.focus();
  }

  // ---- admin: 선택과 일괄 변경 ----
  const bar = $('[data-bulk-bar]');
  const selectAll = $('[data-select-all]');
  function renderBulk() {
    if (!isAdmin || !bar) return;
    const n = allResults ? shown.total : selected.size;
    bar.hidden = n === 0;
    setText($('[data-bulk-count]'), n ? selectionText(selected.size, allResults, shown.total) : '');
    const all = $('[data-bulk-all]');
    const offer = offerAllResults(selected.size, shown.keys.length, shown.total, allResults);
    all.hidden = !offer;
    if (offer) setText(all, t('rules.bulk_select_all', { n: shown.total.toLocaleString('ko-KR') }));
    if (selectAll) {
      selectAll.checked = shown.keys.length > 0 && selected.size === shown.keys.length;
      selectAll.indeterminate = selected.size > 0 && selected.size < shown.keys.length;
    }
  }
  if (isAdmin && bar) {
    selectAll?.addEventListener('change', () => {
      selected.clear();
      if (selectAll.checked) for (const k of shown.keys) selected.add(k);
      allResults = false;
      for (const b of section.querySelectorAll('[data-rows] [data-select]')) b.checked = selectAll.checked;
      renderBulk();
    });
    $('[data-bulk-all]').addEventListener('click', () => {
      allResults = true;
      renderBulk();
      bar.querySelector('[data-bulk-action]')?.focus(); // 링크가 사라지므로 첫 동작 버튼으로
    });
    $('[data-bulk-clear]').addEventListener('click', () => {
      selected.clear();
      allResults = false;
      for (const b of section.querySelectorAll('[data-rows] [data-select]')) b.checked = false;
      renderBulk();
      selectAll?.focus();
    });
    let bulkBusy = false;
    for (const btn of bar.querySelectorAll('[data-bulk-action]')) {
      btn.addEventListener('click', async () => {
        if (bulkBusy) return;
        bulkBusy = true;
        btn.disabled = true;
        try {
          await runBulk(btn.dataset.bulkAction);
        } finally {
          bulkBusy = false;
          btn.disabled = false;
        }
      });
    }
  }

  /** 미리보기 → 확인 → 적용. 409(개정 번호 바뀜)면 알리고 다시 미리보기(최대 3번) */
  async function runBulk(action) {
    const target = allResults ? { mode: 'filter', filter: bulkFilter(applied) } : { mode: 'keys', keys: [...selected] };
    const body = { tool: tool(), action, target };
    const result = $('[data-bulk-result]');
    for (let attempt = 0; attempt < 3; attempt++) {
      let preview;
      try {
        preview = await api.post('/api/v1/admin/rules/overrides/bulk/preview', body);
      } catch (e) {
        setText(result, e.code === 'bulk_too_large' ? t('rules.bulk_too_large', { max: e.details?.max ?? '', total: e.details?.total ?? '' }) : errorText(e));
        return;
      }
      const done = await confirmBulk({
        preview, typed: needsTypedConfirm(target.mode, preview.changes),
        apply: (reason) => api.post('/api/v1/admin/rules/overrides/bulk', { ...body, reason, expected_revision: preview.revision }),
      });
      if (done === null) return;
      if (done.status === 'revision') {
        setText(result, t('rules.bulk_revision_changed'));
        toast(t('rules.bulk_revision_changed'), { type: 'warn' });
        continue;
      }
      await load(); // 현재 조건으로 다시 불러온 뒤(선택 해제) 결과를 알린다
      setText(result, bulkDoneText(done.result));
      toast(bulkDoneText(done.result), { type: 'success' });
      return;
    }
  }

  /** 현재 탭의 기준 버전 줄·최신 아님 안내, 두 탭 머리글의 총 규칙 수 */
  function renderTabInfo() {
    if (!meta) return;
    setText($('[data-basis]'), basisText(meta, tool()));
    const stale = staleTools(meta, tool());
    const box = $('[data-stale]');
    box.hidden = stale.length === 0;
    clear(box);
    if (stale.length) box.append(icon('system.warn', 16), ' ', t('rules.stale', { tools: stale.map((x) => `${t(`rules.tool.${x}`)} ${meta.tools[x].registry_version}`).join(', ') }));
    for (const lang of LANGUAGES) {
      const n = document.querySelector(`[data-rule-tabs] [data-language="${lang}"] [data-tab-count]`);
      if (n) setText(n, tabCountText(meta, toolOf(lang)));
    }
  }

  async function loadMeta() {
    meta = await api.get('/api/v1/rules/meta');
    renderTabInfo();
    fillCategories();
    return meta;
  }

  // ---- admin: 규칙 데이터 다시 불러오기(워커가 처리, 요청 하나) ----
  const reloadBtn = document.querySelector('[data-rules-reload]');
  if (reloadBtn) {
    let busy = false;
    reloadBtn.addEventListener('click', async () => {
      if (busy) return;
      busy = true;
      reloadBtn.disabled = true;
      reloadBtn.setAttribute('aria-busy', 'true');
      const status = $('[data-reload-status]');
      setText(status, t('rules.reload_requested'));
      try {
        const r = await api.post('/api/v1/admin/rules/reload', {});
        const id = r.task.id;
        const deadline = Date.now() + 5 * 60 * 1000;
        let task = r.task;
        while (Date.now() < deadline && ['queued', 'running'].includes(task?.status)) {
          await new Promise((res) => setTimeout(res, 2000));
          task = (await api.get('/api/v1/rules/meta')).task;
          if (task && task.id !== id && !['queued', 'running'].includes(task.status)) break;
          setText(status, t(task?.status === 'running' ? 'rules.reload_running' : 'rules.reload_requested'));
        }
        if (['queued', 'running'].includes(task?.status)) {
          setText(status, t('rules.reload_timeout'));
        } else {
          setText(status, t(task?.status === 'succeeded' ? 'rules.reload_done' : 'rules.reload_failed'));
          toast(t(task?.status === 'succeeded' ? 'rules.reload_done' : 'rules.reload_failed'), { type: task?.status === 'succeeded' ? 'success' : 'error' });
          await loadMeta();
          load();
        }
      } catch (e) {
        setText(status, errorText(e));
      } finally {
        busy = false;
        reloadBtn.disabled = false;
        reloadBtn.removeAttribute('aria-busy');
      }
    });
  }

  fillForm(draft);
  syncDirty();
  loadMeta().then(() => { fillForm(draft); syncDirty(); }).catch((e) => setText($('[data-basis]'), errorText(e)));
  load();
}

