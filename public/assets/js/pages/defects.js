// 결함 목록(프로젝트 상세의 "결함" 탭, 명세 6.4, P17). 필터(규칙·심각도·상태·담당자·파일), 검색, 정렬, 페이지.
// 필터 상태는 URL 쿼리에 반영한다(새로고침·링크 공유). 표와 칩은 el()/textContent로만 만든다(innerHTML 금지).
// 일괄 변경(P18): 현재 페이지에서 고른 결함(필터 결과 전체 선택은 없음)의 담당자·심각도·상태를 확인 단계를 거쳐 한 번에 바꾼다.
//   결함마다 version을 보내고, 서버가 항목별 결과(변경·변경 없음·충돌)를 돌려준다. 페이지·필터가 바뀌면 선택을 비운다.
// Run(P20R): 위쪽 Run 선택기(주소 ?run=N, 없으면 최신 Run). 과거 Run은 읽기 전용이다.
// 검색(P21V): 검색 영역의 입력은 고르기만 하고 "검색"(또는 검색어·파일·Run 입력칸의 Enter)으로 한꺼번에 적용한다(lib/defect_search.js).
//   쪽 이동·표 머리글 정렬·필터 지우기·분석 필터 해제는 바로 적용한다. 주소에는 적용된 조건만 들어간다.
//   읽기 전용 판정은 요약 API의 run_view.read_only 한 곳: 선택 열·일괄 변경을 그리지 않고 안내를 띄운다. 상세 링크에 ?run=N을 붙인다.
// 내보내기(P23): 표 위 오른쪽의 Excel·CSV 버튼(ui/export_buttons.js)은 **적용된 조건**(apiQuery(state) — 폼의 미적용 변경 아님)과 Run을 보낸다.
//   미적용 변경이 있으면 "마지막으로 검색한 조건으로 내보냅니다", 과거 Run이면 "Run N 기준". 0건이면 버튼 꺼짐, 삭제된 Run 안내 화면에는 없음.
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { el, clear, setText } from '../core/dom.js';
import { timezoneFromDocument } from '../core/time.js';
import { renderPagination, pageCount } from '../ui/pagination.js';
import { iconWithLabel } from '../ui/icons.js';
import { toast } from '../ui/toast.js';
import { confirmDialog } from '../ui/dialog.js';
import { defectRow } from '../ui/defect_table.js';
import { mountRunSelector, pastRunBanner } from '../ui/run_selector.js';
import { runSuffix, runName, withRun } from '../lib/run_core.js';
import { bulkChanges, bulkItems } from '../lib/defect_edit_core.js';
import {
  SEVERITIES, STATUSES, stateFromSearch, searchFromState, apiQuery, isFiltered,
} from '../lib/defect_filters.js';
import { createSearch, normalizeDraft } from '../lib/defect_search.js';
import { mountExport } from '../ui/export_buttons.js';

const root = document.querySelector('[data-defects]');
if (root) init(root);

function init(section) {
  const api = createApi();
  const tz = timezoneFromDocument();
  const projectId = section.dataset.projectId;
  const size = Number(section.dataset.pageSize) || 50;
  const detailBase = section.dataset.detailBase;
  const form = section.querySelector('[data-filter-form]');
  const q = form.querySelector('#df-q');
  const fromRun = form.querySelector('#df-from-run');
  const inFrom = form.querySelector('#df-in-from');
  const inTo = form.querySelector('#df-in-to');
  const file = form.querySelector('#df-file');
  const fileMode = form.querySelector('#df-file-mode');
  const ruleSelect = form.querySelector('#df-rule');
  const assigneeSelect = form.querySelector('#df-assignee');
  const sort = form.querySelector('#df-sort');
  const order = form.querySelector('#df-order');
  const count = section.querySelector('[data-count]');
  const table = section.querySelector('[data-table]');
  const rows = section.querySelector('[data-rows]');
  const pager = section.querySelector('[data-pagination]');
  const emptyFiltered = section.querySelector('[data-empty-filtered]');
  const runBanner = section.querySelector('[data-run-banner]');
  const dirtyMark = form.querySelector('[data-search-dirty]');
  let state = stateFromSearch(location.search);
  let pushHistory = true; // 다음 적용을 새 기록으로(false = 주소만 바꿈: 범위를 넘은 쪽 바로잡기·뒤로 가기)
  /** 적용(목록·요약 요청과 주소 반영)은 이 컨트롤러가 정한 때만: 검색, 쪽 이동, 머리글 정렬, 필터 지우기, 분석 필터 해제, 뒤로 가기 */
  const search = createSearch(state, (applied, { resetForm }) => {
    selected.clear();
    state = applied;
    const url = location.pathname + searchFromState(state) + location.hash;
    if (pushHistory) history.pushState(null, '', url);
    else history.replaceState(null, '', url);
    pushHistory = true;
    if (resetForm) syncForm();
    else syncResultControls();
    load();
  });
  /** 요약 API의 run_view: { run, latest, read_only } */
  let view = { run: null, latest: null, read_only: false };
  const analysisRuns = new Map(); // 분석 id → Run 번호(필터 안내 문구)
  let seq = 0;
  /** 일괄 변경 선택: 결함 id → 화면에 그린 version(현재 페이지만) */
  const selected = new Map();
  let pageItems = [];

  const toolbarClear = form.querySelector('[data-clear-filters]');
  const exporter = mountExport(section.querySelector('[data-export]'), {
    url: `/api/v1/projects/${projectId}/defects/export`,
    getBody: () => apiQuery(state, null, false),
  });
  let shownTotal = 0;
  let runDeleted = false;
  const syncExport = () => exporter.update({ total: shownTotal, dirty: search.dirty(), run: view.read_only ? view.run : null, hidden: runDeleted });

  /** 폼 입력칸 → 고르는 중인 조건(검색어·파일·Run·정렬·순서). 칩·규칙·담당자는 고를 때 바로 기록한다 */
  function readForm() {
    for (const [key, input] of [['q', q], ['file', file], ['file_mode', fileMode], ['from_run', fromRun], ['in_run_from', inFrom], ['in_run_to', inTo], ['sort', sort], ['order', order]]) {
      search.edit(key, input.value);
    }
  }

  /** "조건이 변경되었습니다"(검색 버튼 옆, 점 + 글자, 화면 읽기 프로그램에도 전달)와 "필터 지우기" 활성 */
  function syncDirty() {
    readForm();
    const dirty = search.dirty();
    dirtyMark.classList.toggle('is-dirty', dirty);
    setText(dirtyMark, dirty ? t('defects.search_dirty') : '');
    toolbarClear.disabled = !isFiltered(state) && !isFiltered({ ...state, ...normalizeDraft(search.draft) });
    syncExport();
  }

  /** 결과 표 동작(쪽 이동·머리글 정렬) 뒤: 정렬·순서 입력칸과 머리글 표시만 적용 조건에 맞춘다(고르던 다른 입력은 그대로) */
  function syncResultControls() {
    sort.value = search.draft.sort;
    order.value = search.draft.order;
    syncSortHeads();
    syncDirty();
  }

  /** 폼 전체를 적용된 조건으로(검색·필터 지우기·주소 이동 뒤) */
  function syncForm() {
    const d = search.draft;
    q.value = d.q;
    fromRun.value = d.from_run;
    inFrom.value = d.in_run_from;
    inTo.value = d.in_run_to;
    file.value = d.file;
    fileMode.value = d.file_mode;
    sort.value = d.sort;
    order.value = d.order;
    for (const opt of ruleSelect.options) opt.selected = d.rule.includes(opt.value);
    for (const opt of assigneeSelect.options) opt.selected = d.assignee.includes(opt.value);
    for (const b of form.querySelectorAll('[data-facet] .chip')) b.setAttribute('aria-pressed', String(d[b.closest('[data-facet]').dataset.facet].includes(b.dataset.value)));
    syncSortHeads();
    syncDirty();
    // 분석 결과 요약에서 온 필터(P19): "분석 #N의 신규" 등. 지우면 그 조건만 뺀다
    const af = form.querySelector('[data-analysis-filter]');
    af.hidden = !(state.analysis && state.change);
    if (!af.hidden) analysisFilterText(af.querySelector('[data-analysis-filter-text]'));
  }

  /** 머리글 정렬 표시(P21U): 지금 정렬 열에 aria-sort와 ▲▼(머리글 글자 옆, 줄바꿈 없음) */
  function syncSortHeads() {
    for (const th of table.querySelectorAll('th[data-sort-col]')) {
      const on = th.dataset.sortCol === state.sort;
      th.setAttribute('aria-sort', on ? (state.order === 'desc' ? 'descending' : 'ascending') : 'none');
      setText(th.querySelector('[data-sort-mark]'), on ? t(`defects.sort_mark.${state.order}`) : '');
    }
  }

  /** "Run N(분석 #id)의 신규" — 분석 id로 Run 번호를 한 번 읽는다(주소의 analysis=<id>는 P19 호환) */
  async function analysisFilterText(node) {
    const id = state.analysis;
    const change = state.change;
    const show = () => setText(node, t(`defects.analysis_filter.${change}`, { id, run: runName(analysisRuns.get(id) ?? null) }));
    show();
    if (analysisRuns.has(id)) return;
    try {
      const a = await api.get(`/api/v1/analyses/${id}`);
      analysisRuns.set(id, a.run ?? null);
      if (state.analysis === id) show();
    } catch {
      analysisRuns.set(id, null);
    }
  }

  /** 읽기 전용(과거 Run) 표시: 안내, 선택 열·일괄 변경 숨김, "최신 감지 위치" 머리글 */
  function applyView(v) {
    view = v;
    const ro = Boolean(v.read_only);
    section.classList.toggle('is-read-only', ro);
    section.querySelector('[data-select-page]').closest('th').hidden = ro;
    if (ro) section.querySelector('[data-bulk-bar]').hidden = true;
    for (const n of section.querySelectorAll('[data-latest-location]')) {
      n.hidden = !ro;
      setText(n, ro ? `(${t('runs.latest_location')})` : '');
    }
    clear(runBanner);
    if (ro) {
      runBanner.appendChild(pastRunBanner(v.run, v.latest, withRun(`${location.pathname}${location.search}${location.hash}`, '', {})));
    }
  }

  function reportError(e) {
    toast(e.requestId ? `${e.message} (${t('common.request_id', { id: e.requestId })})` : e.message, { type: 'error' });
  }

  async function load() {
    const my = ++seq;
    setText(count, t('defects.loading'));
    section.setAttribute('aria-busy', 'true');
    try {
      const [list, summary] = await Promise.all([
        api.get(`/api/v1/projects/${projectId}/defects`, { query: apiQuery(state, size) }),
        api.get(`/api/v1/projects/${projectId}/defects/summary`, { query: apiQuery(state, null, false) }),
      ]);
      if (my !== seq) return; // 더 새 요청이 있음
      const total = list.page.total;
      const pages = pageCount(total, size);
      if (total > 0 && state.page > pages) {
        pushHistory = false;
        search.replace({ ...state, page: pages });
        return;
      }
      applyView(summary.run_view ?? { run: null, latest: null, read_only: false });
      renderFacets(summary);
      renderRows(list.data);
      setText(count, t('defects.count', { total: total.toLocaleString('ko-KR') }));
      shownTotal = total;
      runDeleted = false;
      syncExport();
      table.hidden = total === 0;
      emptyFiltered.hidden = total !== 0;
      pager.hidden = pages <= 1;
      if (pages > 1) renderPagination(pager, { page: state.page, pages, onChange: (p) => search.page(p) });
    } catch (e) {
      if (my !== seq) return;
      if (e.status === 410 && e.code === 'run_detail_deleted') {
        showRunDeleted(e.details?.run ?? state.run);
        return;
      }
      setText(count, t('defects.load_failed'));
      shownTotal = 0;
      syncExport();
      reportError(e);
    } finally {
      if (my === seq) section.removeAttribute('aria-busy');
    }
  }

  /** 칩: 눌러서 고르기만 한다(적용은 검색). 숫자는 마지막으로 적용된 조건으로 센 건수 */
  /** P21B: 주소의 Run 상세가 삭제됨(410) — 표 대신 안내와 최신 Run 링크 */
  function showRunDeleted(run) {
    setText(count, '');
    shownTotal = 0;
    runDeleted = true;
    syncExport();
    table.hidden = true;
    pager.hidden = true;
    emptyFiltered.hidden = true;
    clear(runBanner);
    runBanner.appendChild(el('section', { class: 'notice notice--warn run-deleted', dataset: { runDeleted: String(run) }, role: 'status' }, [
      el('p', { class: 'run-banner__title', text: t('runs.detail_deleted_title', { run }) }),
      el('p', { class: 'run-banner__help', text: t('runs.detail_deleted_body') }),
      el('a', { class: 'btn btn--primary', href: withRun(`${location.pathname}${location.search}${location.hash}`, '', {}), text: t('runs.view_latest') }),
    ]));
  }

  // P21B 6절: 칩은 라벨만(건수 "(숫자)" 없음, 화면 읽기용 이름에도 없음). 요약 API는 규칙·담당자 선택지·Run 보기에 계속 쓴다
  function chip(facet, name, value) {
    const pressed = search.draft[facet].includes(value);
    const modifier = name.replace(/[._]/g, '-');
    return el('button', {
      type: 'button',
      class: `chip badge badge--${modifier}`,
      'aria-pressed': String(pressed),
      dataset: { value },
      on: {
        click: (e) => {
          search.toggle(facet, value);
          e.currentTarget.setAttribute('aria-pressed', String(search.draft[facet].includes(value)));
          syncDirty();
        },
      },
    }, [iconWithLabel(name, 16)]);
  }

  function renderFacets(s) {
    const sev = form.querySelector('[data-facet="severity"] [data-facet-items]');
    clear(sev);
    for (const v of SEVERITIES) sev.appendChild(chip('severity', `severity.${v}`, v));
    const st = form.querySelector('[data-facet="status"] [data-facet-items]');
    clear(st);
    for (const v of STATUSES) st.appendChild(chip('status', `defect_status.${v}`, v));

    clear(ruleSelect);
    for (const r of s.rules) {
      const label = `${r.rule_key} (${r.count.toLocaleString('ko-KR')})`;
      // 상자보다 긴 규칙 키는 잘리므로 선택지에 전체 글자(브라우저 기본 툴팁. 선택지 안은 공통 툴팁 모듈이 닿지 않음)
      ruleSelect.appendChild(el('option', { value: r.rule_key, selected: search.draft.rule.includes(r.rule_key), title: label }, [label]));
    }
    clear(assigneeSelect);
    const assignees = [...s.assignees];
    if (!assignees.some((a) => a.id === null)) assignees.unshift({ id: null, count: 0 });
    for (const a of assignees) {
      const value = a.id === null ? 'none' : String(a.id);
      const name = a.id === null ? t('defects.unassigned') : `${a.display_name} (${a.login_id})${a.active ? '' : ` · ${t('defects.inactive')}`}`;
      const label = `${name} (${a.count.toLocaleString('ko-KR')})`;
      assigneeSelect.appendChild(el('option', { value, selected: search.draft.assignee.includes(value), title: label }, [label]));
    }
    // 선택했지만 지금 결과에 없는 담당자(건수 0)도 선택을 풀 수 있게 남긴다
    for (const v of search.draft.assignee) {
      if (![...assigneeSelect.options].some((o) => o.value === v)) {
        assigneeSelect.appendChild(el('option', { value: v, selected: true }, [`#${v} (0)`]));
      }
    }
  }

  function renderRows(items) {
    pageItems = items;
    for (const id of [...selected.keys()]) if (!items.some((d) => d.id === id)) selected.delete(id);
    clear(rows);
    const ro = Boolean(view.read_only);
    for (const d of items) {
      rows.appendChild(defectRow(d, {
        detailBase, tz,
        detailQuery: ro ? runSuffix(view.run) : '',
        viewRun: ro ? view.run : null,
        select: ro ? null : {
          checked: selected.has(d.id),
          label: t('defects.select_row', { location: `${d.file_path}:${d.line}` }),
          onChange: (on) => { if (on) selected.set(d.id, d.version); else selected.delete(d.id); bulk.sync(); },
        },
      }));
    }
    bulk.sync();
  }

  // ---- 일괄 변경 ----
  function initBulk() {
    const bar = section.querySelector('[data-bulk-bar]');
    const countText = bar.querySelector('[data-bulk-count]');
    const selectPage = section.querySelector('[data-select-page]');
    const result = section.querySelector('[data-bulk-result]');
    const assignee = bar.querySelector('#bulk-assignee');
    const severity = bar.querySelector('#bulk-severity');
    const status = bar.querySelector('#bulk-status');
    const memo = bar.querySelector('#bulk-memo');
    const applyButton = bar.querySelector('[data-bulk-apply]');
    let candidatesLoaded = false;

    async function loadCandidates() {
      if (candidatesLoaded) return;
      candidatesLoaded = true;
      try {
        // 담당자 후보: 이 프로젝트에 접근할 수 있는 활성 사용자(서버가 지정할 때도 다시 검사)
        for (let page = 1; page <= 20; page++) {
          const res = await api.get(`/api/v1/projects/${projectId}/assignees`, { query: { page, size: 200 } });
          for (const u of res.data) assignee.appendChild(el('option', { value: String(u.id) }, [`${u.display_name} (${u.login_id})`]));
          if (page * 200 >= res.page.total) break;
        }
      } catch (e) {
        candidatesLoaded = false;
        reportError(e);
      }
    }

    function changes() {
      return bulkChanges({ assignee: assignee.value, severity: severity.value, status: status.value });
    }

    function sync() {
      const n = selected.size;
      bar.hidden = n === 0 || Boolean(view.read_only);
      setText(countText, t('defects.bulk_selected', { count: n }));
      selectPage.checked = n > 0 && n === pageItems.length;
      selectPage.indeterminate = n > 0 && n < pageItems.length;
      applyButton.disabled = n === 0 || Object.keys(changes()).length === 0;
      if (n > 0) loadCandidates();
    }

    function valueLabel(field, value) {
      if (field === 'assignee_id') {
        if (value === null) return t('defects.unassigned');
        return [...assignee.options].find((o) => o.value === String(value))?.textContent ?? `#${value}`;
      }
      return t(field === 'severity' ? `icon.severity.${value}` : `icon.defect_status.${value}`);
    }

    function showResult(r) {
      clear(result);
      result.hidden = false;
      const level = r.conflicts.length ? 'warn' : 'info';
      result.appendChild(el('p', { class: `notice notice--${level}`, role: 'status' }, [
        t('defects.bulk_result', { updated: r.updated.length, unchanged: r.unchanged.length, conflicts: r.conflicts.length }),
      ]));
      if (r.conflicts.length) {
        result.appendChild(el('p', { class: 'muted', text: t('defects.bulk_conflict_help') }));
        result.appendChild(el('ul', { class: 'bulk-result__list' }, r.conflicts.map((c) => el('li', { dataset: { conflict: c.id } }, [
          el('a', { href: `${detailBase}${c.id}` }, [el('code', { text: `${c.current.file_path}:${c.current.line}` })]),
          ' — ',
          t('defects.bulk_conflict_item', {
            status: t(`icon.defect_status.${c.current.status}`),
            severity: t(`icon.severity.${c.current.severity}`),
            assignee: c.current.assignee ? c.current.assignee.display_name : t('defects.unassigned'),
          }),
        ]))));
      }
    }

    selectPage.addEventListener('change', () => {
      selected.clear();
      if (selectPage.checked) for (const d of pageItems) selected.set(d.id, d.version);
      for (const box of rows.querySelectorAll('input[data-select]')) box.checked = selectPage.checked;
      sync();
    });
    for (const x of [assignee, severity, status]) x.addEventListener('change', sync);
    bar.querySelector('[data-bulk-clear]').addEventListener('click', () => {
      selected.clear();
      for (const box of rows.querySelectorAll('input[data-select]')) box.checked = false;
      sync();
    });
    bar.addEventListener('submit', async (e) => {
      e.preventDefault();
      const ch = changes();
      if (selected.size === 0 || Object.keys(ch).length === 0) return;
      const summary = Object.entries(ch)
        .map(([f, v]) => `${t(`defects.history_field.${f === 'assignee_id' ? 'assignee' : f}`)} → ${valueLabel(f, v)}`)
        .join(', ');
      const ok = await confirmDialog({
        title: t('defects.bulk_confirm_title', { count: selected.size }),
        message: t('defects.bulk_confirm', { count: selected.size, changes: summary }) + ('severity' in ch ? ` ${t('defects.bulk_severity_note')}` : ''),
        confirmLabel: t('defects.bulk_apply'),
        confirmIcon: 'action.bulk_edit',
      });
      if (!ok) return;
      applyButton.disabled = true;
      try {
        const body = { items: bulkItems(selected), changes: ch };
        if (memo.value.trim() !== '') body.memo = memo.value.trim();
        const r = await api.post(`/api/v1/projects/${projectId}/defects/bulk`, body);
        const message = t('defects.bulk_result', { updated: r.updated.length, unchanged: r.unchanged.length, conflicts: r.conflicts.length });
        toast(message, { type: r.conflicts.length ? 'warn' : 'success' });
        selected.clear();
        memo.value = '';
        await load();
        showResult(r);
      } catch (err) {
        reportError(err);
      } finally {
        sync();
      }
    });
    return { sync };
  }

  // 검색: 버튼, 또는 검색어·파일·Run 숫자 입력칸의 Enter(폼 기본 제출). 선택 상자의 Enter는 목록 선택에 쓰이므로 제출하지 않는다
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    readForm();
    search.search();
  });
  // 입력은 고르기만(요청 없음): 변경 표시만 갱신
  form.addEventListener('input', (e) => { if (e.target.matches('input, select')) syncDirty(); });
  form.addEventListener('change', (e) => { if (e.target.matches('select:not([multiple])')) syncDirty(); });
  ruleSelect.addEventListener('change', () => { search.edit('rule', [...ruleSelect.selectedOptions].map((o) => o.value)); syncDirty(); });
  assigneeSelect.addEventListener('change', () => { search.edit('assignee', [...assigneeSelect.selectedOptions].map((o) => o.value)); syncDirty(); });
  // 머리글 버튼(결과 표 동작, 바로 적용): 다른 열이면 그 열 오름차순, 같은 열이면 순서를 뒤집는다. 정렬·순서 입력칸도 같은 값
  for (const b of table.querySelectorAll('[data-sort-key]')) {
    b.addEventListener('click', () => {
      readForm();
      search.sortBy(b.dataset.sortKey);
    });
  }
  // 필터 지우기(바로 적용): 조건을 기본값으로(정렬·보는 Run 유지)
  for (const b of section.querySelectorAll('[data-clear-filters]')) {
    b.addEventListener('click', () => {
      search.reset();
      q.focus();
    });
  }
  form.querySelector('[data-analysis-filter-clear]').addEventListener('click', () => search.replace({ ...state, analysis: '', change: '', page: 1 }));
  window.addEventListener('popstate', () => {
    pushHistory = false;
    search.replace(stateFromSearch(location.search), { resetForm: true });
  });

  const bulk = initBulk();
  syncForm();
  load();
  mountRunSelector(section.querySelector('[data-run-selector]'), { api, projectId, run: state.run, tz }).catch(reportError);
}
