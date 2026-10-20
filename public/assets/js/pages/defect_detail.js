// 결함 상세(명세 6.4, 7, 8, P17): 코드 뷰어(마지막 감지 분석의 코드)와 변경 이력(최신순, 페이지).
// 코드는 CodeMirror 문서 텍스트로만, 이력은 el()/textContent로만 그린다(innerHTML 금지).
// P18: 담당자·심각도·상태 수정 폼(낙관적 잠금, 충돌 비교 대화상자). 사유 등 사용자 입력은 textContent로만 출력한다.
// P20R: Run 선택기(?run=N). 과거 Run이면 서버가 수정 폼 대신 안내를 그리고, 코드는 그 Run(또는 그 전 마지막 존재 Run)의 스냅샷을 읽는다.
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { el, clear, setText, meta } from '../core/dom.js';
import { formatDateTime, timezoneFromDocument } from '../core/time.js';
import { renderPagination, pageCount } from '../ui/pagination.js';
import { icon } from '../ui/icons.js';
import { badge } from '../ui/badge.js';
import { toast } from '../ui/toast.js';
import { formDialog } from '../ui/form_dialog.js';
import { assigneeCell } from '../ui/defect_table.js';
import { noticesFor } from '../lib/code_view_core.js';
import { mountRunSelector } from '../ui/run_selector.js';
import { runName, intervalsText } from '../lib/run_core.js';
import { contentDialog } from '../ui/dialog.js';
import { timelineRun, timelineSummary } from '../lib/history_core.js';
import { baseline, formValues, diffChanges, patchBody, conflictRows, changedFields } from '../lib/defect_edit_core.js';

const api = createApi();
const tz = timezoneFromDocument();

function reportError(e) {
  toast(e.requestId ? `${e.message} (${t('common.request_id', { id: e.requestId })})` : e.message, { type: 'error' });
}

// "목록으로": 같은 프로젝트의 결함 목록에서 왔으면 그 필터 그대로 돌아간다(같은 출처의 주소만)
const back = document.querySelector('[data-back-to-list]');
if (back && document.referrer) {
  try {
    const ref = new URL(document.referrer);
    const target = new URL(back.href);
    if (ref.origin === location.origin && ref.pathname === target.pathname) back.href = ref.pathname + ref.search;
  } catch {
    // 주소를 해석하지 못하면 기본 링크 그대로
  }
}

// ---- Run 선택기 ----
const runHost = document.querySelector('[data-run-selector]');
if (runHost) {
  mountRunSelector(runHost, {
    api, projectId: runHost.dataset.projectId, run: runHost.dataset.run ?? '', tz,
  }).catch(reportError);
}

// ---- 코드 뷰어 ----
const codeCard = document.querySelector('[data-code-viewer]');
if (codeCard) initCode(codeCard);

function initCode(card) {
  const id = card.dataset.defectId;
  const run = card.dataset.run ?? '';
  const excerptContext = Number(card.dataset.excerptContext) || 20;
  const host = card.querySelector('[data-code-host]');
  const notices = card.querySelector('[data-code-notices]');
  const source = card.querySelector('[data-code-source]');
  const loading = card.querySelector('[data-code-loading]');
  const empty = card.querySelector('[data-code-empty]');
  const modeButtons = [...card.querySelectorAll('[data-code-mode]')];
  const wrapButton = card.querySelector('[data-code-wrap]');
  let viewer = null;
  let mode = 'window';
  let wrap = false;
  let seq = 0;

  function showEmpty(reason) {
    clear(empty);
    empty.appendChild(el('section', { class: 'empty-state' }, [
      el('div', { class: 'empty-state__icon' }, [icon('ui.empty', 24)]),
      el('h3', { class: 'empty-state__title', text: t('defects.code_none_title') }),
      el('p', { class: 'empty-state__body', text: t(`defects.code_none.${reason}`) }),
    ]));
    empty.hidden = false;
    host.hidden = true;
    for (const b of modeButtons) b.disabled = true;
    wrapButton.disabled = true;
  }

  async function load() {
    const my = ++seq;
    loading.hidden = false;
    try {
      const data = await api.get(`/api/v1/defects/${id}/code`, { query: run ? { mode, run } : { mode } });
      if (my !== seq) return;
      viewer?.destroy();
      viewer = null;
      clear(host);
      clear(notices);
      if (data.source === 'none') {
        setText(source, '');
        showEmpty(data.reason);
        return;
      }
      // 코드가 속한 Run(번호가 있으면 "Run N 시점의 코드", 없으면 분석 #id)
      setText(source, Number.isInteger(data.run)
        ? t(data.excerpt ? 'defects.code_source_run_excerpt' : 'defects.code_source_run', { run: data.run, file: data.file_path })
        : t(data.excerpt ? 'defects.code_source_excerpt' : 'defects.code_source', { id: data.analysis_id, file: data.file_path }));
      const runNotes = data.note === 'latest_location' ? [{ level: 'info', key: 'runs.code_latest_location', params: {} }]
        : (data.note === 'excerpt_latest' ? [{ level: 'warn', key: 'runs.code_excerpt_latest', params: {} }] : []);
      for (const n of [...runNotes, ...noticesFor(data, { excerptContext, mode })]) {
        notices.appendChild(el('p', { class: `notice notice--${n.level}`, dataset: { notice: n.key } }, [
          icon(n.level === 'warn' ? 'system.warn' : 'ui.info', 16), ' ', t(n.key, n.params),
        ]));
      }
      // 발췌본은 "전체 파일"이 없다(범위 밖은 보여 주지 않음, 명세 7)
      for (const b of modeButtons) b.disabled = data.excerpt;
      const { createCodeViewer } = await import('../lib/code_viewer.js');
      if (my !== seq) return;
      viewer = createCodeViewer(host, data, { nonce: meta('csp-nonce'), wrap, label: t('defects.code_aria', { file: data.file_path }) });
      host.hidden = false;
      empty.hidden = true;
    } catch (e) {
      if (my !== seq) return;
      showEmpty('load_failed');
      reportError(e);
    } finally {
      if (my === seq) loading.hidden = true;
    }
  }

  for (const b of modeButtons) {
    b.addEventListener('click', () => {
      if (mode === b.dataset.codeMode) return;
      mode = b.dataset.codeMode;
      for (const x of modeButtons) x.setAttribute('aria-pressed', String(x === b));
      load();
    });
  }
  wrapButton.addEventListener('click', () => {
    wrap = !wrap;
    wrapButton.setAttribute('aria-pressed', String(wrap));
    viewer?.setWrap(wrap);
  });
  load();
}

// ---- 변경 이력 ----
const historyCard = document.querySelector('[data-history]');
/** 이력 다시 읽기(수정 뒤 첫 페이지로) */
const historyReload = historyCard ? initHistory(historyCard) : null;

function valueText(field, value, user) {
  if (field === 'assignee') {
    if (value === null || value === '') return el('span', { class: 'muted', text: t('defects.unassigned') });
    const name = user ? user.display_name : `#${value}`;
    const parts = [el('span', { text: name, title: user?.login_id ?? '' })];
    if (user && !user.active) parts.push(' ', el('span', { class: 'tag tag--inactive', text: t('defects.inactive') }));
    return el('span', {}, parts);
  }
  if (value === null || value === '') return el('span', { class: 'muted', text: '—' });
  return badge(field === 'severity' ? `severity.${value}` : `defect_status.${value}`);
}

function initHistory(card) {
  const id = card.dataset.defectId;
  const size = Number(card.dataset.pageSize) || 50;
  const table = card.querySelector('[data-history-table]');
  const rows = card.querySelector('[data-history-rows]');
  const pager = card.querySelector('[data-history-pagination]');
  const empty = card.querySelector('[data-history-empty]');
  let page = 1;

  async function load() {
    try {
      const res = await api.get(`/api/v1/defects/${id}/history`, { query: { page, size } });
      const total = res.page.total;
      clear(rows);
      for (const h of res.data) {
        const by = h.kind === 'auto'
          ? el('span', { class: 'tag', text: t('defects.history_auto') })
          : el('span', {}, [
            el('span', { text: h.changed_by?.display_name ?? '—', title: h.changed_by?.login_id ?? '' }),
            ...(h.changed_by && !h.changed_by.active ? [' ', el('span', { class: 'tag tag--inactive', text: t('defects.inactive') })] : []),
          ]);
        // 자동 이력: 사유 · Run N(분석 #id). 번호가 없으면(되채움 전) 분석 #id만
        const ref = Number.isInteger(h.run) ? `${runName(h.run)}(${t('defects.analysis_ref', { id: h.analysis_id })})` : t('defects.analysis_ref', { id: h.analysis_id });
        // 규칙 기본 심각도 일괄 적용(P21S)은 분석이 아니라 도구가 남긴 자동 이력: 메모 "규칙 기본 심각도 적용(매핑 ruff v1)"
        const reason = h.kind !== 'auto' ? (h.memo ?? '')
          : h.analysis_id === null ? (h.memo ?? t(`defects.reason.${h.reason_code}`)) : `${t(`defects.reason.${h.reason_code}`)} · ${ref}`;
        rows.appendChild(el('tr', {}, [
          el('td', {}, [el('time', { datetime: h.changed_at, text: formatDateTime(h.changed_at, tz) })]),
          el('td', {}, [by]),
          el('td', { text: t(`defects.history_field.${h.field}`) }),
          el('td', { class: 'history-change' }, [valueText(h.field, h.old_value, h.old_user), ' → ', valueText(h.field, h.new_value, h.new_user)]),
          el('td', { text: t(`defects.history_kind.${h.kind}`) }),
          el('td', { class: 'history-reason', text: reason }),
        ]));
      }
      table.hidden = total === 0;
      empty.hidden = total !== 0;
      setText(empty, t('defects.history_empty'));
      const pages = pageCount(total, size);
      pager.hidden = pages <= 1;
      if (pages > 1) renderPagination(pager, { page, pages, onChange: (p) => { page = p; load(); } });
    } catch (e) {
      reportError(e);
    }
  }
  load();
  return () => { page = 1; load(); };
}

// ---- 전체 이력 창(P21): 발생한 Run부터 사라질 때까지 모든 변경, 시간순(Run 흐름 순), 창 안에서 페이지 ----
const timelineButton = document.querySelector('[data-open-timeline]');
if (timelineButton && historyCard) {
  timelineButton.addEventListener('click', () => {
    const id = historyCard.dataset.defectId;
    contentDialog({
      title: t('history.tl_title', { id }),
      build: (body) => {
        let page = 1;
        const size = 50;
        const head = el('div', {});
        const table = el('table', { class: 'table table--compact timeline-table', dataset: { timeline: '' } });
        const pager = el('nav', { class: 'pagination', 'aria-label': t('history.tl_pagination') });
        body.append(head, el('div', { class: 'table-scroll' }, [table]), pager);
        const value = (field, v) => {
          if (v === null || v === undefined || v === '') return field === 'assignee' ? t('defects.unassigned') : '—';
          if (field === 'status') return t(`icon.defect_status.${v}`);
          if (field === 'severity') return t(`icon.severity.${v}`);
          return field === 'assignee' ? `#${v}` : String(v);
        };
        const load = async () => {
          const res = await api.get(`/api/v1/defects/${id}/timeline`, { query: { page, size } });
          clear(head);
          head.append(
            el('p', { class: 'timeline-summary', dataset: { timelineSummary: '' }, text: timelineSummary(res.summary) }),
            el('p', { class: 'muted', text: `${t('runs.field_intervals')}: ${intervalsText(res.summary.intervals)}` }),
          );
          clear(table);
          table.append(
            el('caption', { class: 'sr-only', text: t('history.tl_caption') }),
            el('thead', {}, [el('tr', {}, ['tl_col_time', 'tl_col_run', 'tl_col_kind', 'tl_col_change', 'tl_col_by', 'tl_col_reason'].map((k) => el('th', { scope: 'col', text: t(`history.${k}`) })))]),
            el('tbody', {}, res.data.map((it) => el('tr', { dataset: { kind: it.kind } }, [
              el('td', {}, [it.at ? el('time', { datetime: it.at, text: formatDateTime(it.at, tz) }) : '—']),
              el('td', { text: timelineRun(it) }),
              el('td', { text: t(`history.tl_kind.${it.kind}`) }),
              el('td', { text: it.field ? `${t(`defects.history_field.${it.field}`)}: ${value(it.field, it.from)} → ${value(it.field, it.to)}` : '—' }),
              el('td', { text: it.by ? it.by.display_name : it.kind === 'severity_default' ? t('history.tl_system_rule') : (it.kind === 'appeared' || it.kind === 'disappeared' || it.analysis_id ? t('history.tl_system') : '—') }),
              el('td', { text: it.reason === 'rule_default' ? (it.memo ?? t('defects.reason.rule_default')) : it.reason ? t(`defects.reason.${it.reason}`) : (it.memo ?? '') }),
            ]))),
          );
          const pages = pageCount(res.page.total, size);
          pager.hidden = pages <= 1;
          if (pages > 1) renderPagination(pager, { page, pages, onChange: (p) => { page = p; load().catch(reportError); } });
        };
        return load().catch(reportError);
      },
    });
  });
}

// ---- 담당자·심각도·상태 변경(P18, 명세 6.2) ----
// 값이 같으면 저장 버튼 비활성. 저장은 바뀐 필드만 PATCH(version으로 낙관적 잠금). 성공하면 요약·배지·이력을 갱신한다.
// 409(다른 사용자나 분석이 먼저 바꿈)면 "내가 입력한 값"과 "최신 값"을 비교하는 대화상자를 띄운다.
//   "최신 값으로 다시 시도": 기준값·version·폼을 최신으로 바꾸되 **내가 바꾸려던 필드만** 내 값으로 남긴다(사유도 그대로).
//     내가 손대지 않은 필드는 최신 값이 되므로, 다시 저장해도 다른 사용자의 변경을 되돌리지 않는다.
//   "취소": 화면과 폼을 최신 값으로 맞춘다. 입력한 사유는 어느 쪽이든 지우지 않는다.
const editForm = document.querySelector('[data-edit-form]');
if (editForm) initEdit(editForm);

function initEdit(form) {
  const summary = document.querySelector('[data-defect-summary]');
  const id = summary.dataset.defectId;
  const assignee = form.querySelector('#de-assignee');
  const severity = form.querySelector('#de-severity');
  const status = form.querySelector('#de-status');
  const memo = form.querySelector('#de-memo');
  const save = form.querySelector('[data-edit-save]');
  const hint = form.querySelector('[data-edit-hint]');
  let version = Number(summary.dataset.version);
  let base = formValues({ assignee: assignee.value, severity: severity.value, status: status.value });

  const current = () => formValues({ assignee: assignee.value, severity: severity.value, status: status.value });

  function sync() {
    const changed = Object.keys(diffChanges(base, current())).length > 0;
    save.disabled = !changed;
    hint.hidden = changed;
    for (const [field, prefix] of [['severity', 'severity'], ['status', 'defect_status']]) {
      const box = form.querySelector(`[data-preview="${field}"]`);
      clear(box);
      box.appendChild(badge(`${prefix}.${form.querySelector(`#de-${field}`).value}`));
    }
  }

  function assigneeLabel(value) {
    if (value === null) return t('defects.unassigned');
    return [...assignee.options].find((o) => o.value === String(value))?.textContent ?? `#${value}`;
  }

  function valueNode(field, value) {
    if (field === 'assignee') return el('span', { text: assigneeLabel(value) });
    return badge(field === 'severity' ? `severity.${value}` : `defect_status.${value}`);
  }

  /** 서버의 최신 결함으로 요약·배지·기준값·version·폼을 맞춘다. keep에 든 필드(assignee·severity·status)는 폼의 내 값을 남긴다 */
  function applyLatest(d, keep = []) {
    version = d.version;
    summary.dataset.version = String(d.version);
    base = baseline(d);
    // 담당자 선택지에 없는 값(비활성·접근 상실 담당자)은 표시용으로 하나만 남긴다
    for (const o of [...assignee.options]) if (o.dataset.keepOnly !== undefined) o.remove();
    if (d.assignee && ![...assignee.options].some((o) => o.value === String(d.assignee.id))) {
      const tag = d.assignee.active ? t('defects.no_access') : t('defects.inactive');
      assignee.appendChild(el('option', { value: String(d.assignee.id), dataset: { keepOnly: '' } }, [`${d.assignee.display_name} (${d.assignee.login_id}) · ${tag}`]));
    }
    if (!keep.includes('assignee')) assignee.value = d.assignee ? String(d.assignee.id) : '';
    if (!keep.includes('severity')) severity.value = d.severity;
    if (!keep.includes('status')) status.value = d.status;
    const header = document.querySelector('.page-header .badge-row');
    if (header) {
      clear(header);
      header.append(badge(`severity.${d.severity}`), ' ', badge(`defect_status.${d.status}`));
    }
    const sev = summary.querySelector('[data-field="severity"]');
    clear(sev).appendChild(badge(`severity.${d.severity}`));
    // P21S: 심각도 출처(규칙 기본값 / 수동 변경)
    const src = d.severity_source ?? 'none';
    sev.append(' ', el('span', { class: 'severity-source muted', dataset: { severitySource: '' }, hidden: src === 'none', text: src === 'none' ? '' : t(`defects.severity_source.${src}`) }));
    const st = summary.querySelector('[data-field="status"]');
    clear(st).appendChild(badge(`defect_status.${d.status}`));
    const as = summary.querySelector('[data-field="assignee"]');
    clear(as).appendChild(assigneeCell(d.assignee));
    sync();
  }

  async function conflictDialog(changes, latest) {
    const rows = conflictRows(base, changes, latest);
    return formDialog({
      title: t('defects.conflict_title'),
      message: t('defects.conflict_message', { version: latest.version }),
      submitLabel: t('defects.conflict_retry'),
      build: () => ({
        content: [el('table', { class: 'table table--compact conflict-table' }, [
          el('caption', { class: 'sr-only', text: t('defects.conflict_caption') }),
          el('thead', {}, [el('tr', {}, [
            el('th', { scope: 'col', text: t('defects.conflict_col_field') }),
            el('th', { scope: 'col', text: t('defects.conflict_col_mine') }),
            el('th', { scope: 'col', text: t('defects.conflict_col_latest') }),
          ])]),
          el('tbody', {}, rows.map((r) => el('tr', { class: r.changedByOther ? 'is-different' : '' }, [
            el('th', { scope: 'row', text: t(`defects.history_field.${r.field}`) }),
            el('td', {}, [r.edited ? valueNode(r.field, r.mine) : el('span', { class: 'muted', text: t('defects.conflict_not_edited') })]),
            el('td', {}, [valueNode(r.field, r.latest), ...(r.changedByOther ? [' ', el('span', { class: 'tag', text: t('defects.conflict_changed') })] : [])]),
          ]))),
        ])],
        onSubmit: async () => 'retry',
      }),
    });
  }

  form.addEventListener('change', sync);
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const changes = diffChanges(base, current());
    if (Object.keys(changes).length === 0) return;
    save.disabled = true;
    try {
      const d = await api.patch(`/api/v1/defects/${id}`, patchBody(version, changes, memo.value));
      applyLatest(d);
      memo.value = '';
      toast(t('defects.saved'), { type: 'success' });
      historyReload?.();
    } catch (err) {
      if (err.code === 'version_conflict' && err.details?.current) {
        const latest = err.details.current;
        const choice = await conflictDialog(changes, latest);
        applyLatest(latest, choice === 'retry' ? changedFields(changes) : []);
        historyReload?.();
        (choice === 'retry' ? save : assignee).focus();
      } else {
        reportError(err);
        sync();
      }
    }
  });
  sync();
}
