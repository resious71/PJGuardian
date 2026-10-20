// 관리자 "Run 관리"(P21B): 프로젝트별 Run 상세 보관 현황, Run 선택(쪽을 넘어 유지), "오래된 Run 모두 선택(최근 N개 제외)",
// "선택한 Run 상세 삭제"(프로젝트 이름을 입력하는 확인 대화상자). 값은 textContent로만 넣는다(innerHTML 금지).
// 최신 Run은 체크박스가 꺼져 있고 툴팁으로 이유를 알린다. 서버가 권한·최신 Run·이미 삭제됨을 다시 판정한다.
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { el, clear, setText } from '../core/dom.js';
import { formatDateTime, formatShortDate, timezoneFromDocument } from '../core/time.js';
import { renderPagination, pageCount } from '../ui/pagination.js';
import { toast } from '../ui/toast.js';
import { typedConfirmDialog } from '../ui/dialog.js';
import { tipTarget } from '../ui/clip.js';
import { selectOlder, confirmMatches, stateText, summaryText, deleteErrorText, resultText } from '../lib/run_admin_core.js';

const PAGE_SIZE = 50;
const root = document.querySelector('[data-admin-runs]');
if (root) init(root);

function init(section) {
  const api = createApi();
  const tz = timezoneFromDocument();
  const max = Number(section.dataset.max) || 100;
  const projectSelect = section.querySelector('[data-project]');
  const summary = section.querySelector('[data-summary]');
  const tools = section.querySelector('[data-tools]');
  const keepN = section.querySelector('#ar-keep-n');
  const delButton = section.querySelector('[data-delete]');
  const table = section.querySelector('[data-table]');
  const rows = section.querySelector('[data-rows]');
  const pager = section.querySelector('[data-pagination]');
  const loading = section.querySelector('[data-loading]');
  const error = section.querySelector('[data-error]');
  const result = section.querySelector('[data-result]');
  let projectId = '';
  let page = 1;
  let info = null; // 요약(kept_runs, latest 등)
  const selected = new Set();
  let seq = 0;

  const projectName = () => projectSelect.selectedOptions[0]?.dataset.name ?? '';

  function syncButton() {
    delButton.disabled = selected.size === 0;
    setText(delButton.querySelector('.icon-label__text') ?? delButton, selected.size ? t('admin_runs.delete_count', { count: selected.size }) : t('admin_runs.delete'));
  }

  function row(r) {
    const box = el('input', { type: 'checkbox', checked: selected.has(r.run), disabled: r.latest || r.detail_deleted !== null,
      'aria-label': t('admin_runs.select_run', { run: r.run }), dataset: { run: r.run } });
    box.addEventListener('change', () => {
      if (box.checked) selected.add(r.run); else selected.delete(r.run);
      syncButton();
    });
    const selectCell = el('td', {}, [r.latest ? tipTarget(el('span', { class: 'admin-runs__latest' }, [box]), t('admin_runs.latest_not_deletable')) : box]);
    const state = el('span', { class: r.detail_deleted ? 'tag' : 'muted', text: stateText(r) });
    const stateCell = el('td', {}, [state]);
    if (r.detail_deleted) {
      stateCell.append(' ', el('span', { class: 'muted', text: formatShortDate(r.detail_deleted.at, tz) }));
      tipTarget(state, `${stateText(r)} · ${formatDateTime(r.detail_deleted.at, tz)}${r.detail_deleted.by_name ? ` · ${r.detail_deleted.by_name}` : ''}`);
    }
    return el('tr', { dataset: { run: r.run } }, [
      selectCell,
      el('td', {}, [el('span', { class: 'run-tag', text: t('runs.label', { run: r.run }) }), r.latest ? ` ${t('runs.latest_suffix')}` : '']),
      el('td', {}, [r.finished_at ? tipTarget(el('span', { text: formatShortDate(r.finished_at, tz) }), formatDateTime(r.finished_at, tz, { seconds: true })) : '—']),
      el('td', { text: r.open === null ? '—' : r.open.toLocaleString('ko-KR') }),
      el('td', { text: r.new.toLocaleString('ko-KR') }),
      el('td', { text: r.resolved.toLocaleString('ko-KR') }),
      el('td', { text: r.reopened.toLocaleString('ko-KR') }),
      el('td', { text: `${r.tool_name} ${r.tool_version ?? ''}`.trim() }),
      stateCell,
    ]);
  }

  async function load() {
    const my = ++seq;
    error.hidden = true;
    if (projectId === '') {
      table.hidden = true;
      tools.hidden = true;
      pager.hidden = true;
      setText(summary, '');
      return;
    }
    loading.hidden = false;
    try {
      const res = await api.get(`/api/v1/admin/projects/${projectId}/runs`, { query: { page, size: PAGE_SIZE } });
      if (my !== seq) return;
      info = res.summary;
      setText(summary, summaryText(info));
      clear(rows);
      for (const r of res.data) rows.appendChild(row(r));
      table.hidden = res.data.length === 0;
      tools.hidden = res.page.total === 0;
      const pages = pageCount(res.page.total, PAGE_SIZE);
      pager.hidden = pages <= 1;
      if (pages > 1) renderPagination(pager, { page, pages, onChange: (p) => { page = p; load(); } });
    } catch (e) {
      if (my !== seq) return;
      error.textContent = deleteErrorText(e);
      error.hidden = false;
    } finally {
      if (my === seq) loading.hidden = true;
    }
  }

  projectSelect.addEventListener('change', () => {
    projectId = projectSelect.value;
    page = 1;
    selected.clear();
    result.hidden = true;
    syncButton();
    const url = new URL(globalThis.location.href);
    if (projectId) url.searchParams.set('project', projectId); else url.searchParams.delete('project');
    history.replaceState(null, '', url.pathname + url.search);
    load();
  });
  section.querySelector('[data-select-old]').addEventListener('click', () => {
    if (!info) return;
    selected.clear();
    for (const n of selectOlder(info.kept_runs, info.latest, Number.parseInt(keepN.value, 10))) selected.add(n);
    for (const box of rows.querySelectorAll('input[data-run]')) box.checked = selected.has(Number(box.dataset.run));
    syncButton();
  });
  section.querySelector('[data-select-none]').addEventListener('click', () => {
    selected.clear();
    for (const box of rows.querySelectorAll('input[data-run]')) box.checked = false;
    syncButton();
  });
  delButton.addEventListener('click', async () => {
    const runs = [...selected].sort((a, b) => a - b);
    if (runs.length === 0) return;
    if (runs.length > max) {
      toast(t('admin_runs.too_many', { max }), { type: 'error' });
      return;
    }
    const name = projectName();
    const ok = await typedConfirmDialog({
      title: t('admin_runs.confirm_title', { count: runs.length }),
      message: t('admin_runs.confirm_message'),
      lines: [t('admin_runs.confirm_runs', { runs: runs.join(', ') })],
      inputLabel: t('admin_runs.confirm_input', { name }),
      expected: name,
      matches: (v) => confirmMatches(v, name),
      confirmLabel: t('admin_runs.delete'),
    });
    if (!ok) return;
    delButton.disabled = true;
    try {
      const r = await api.post(`/api/v1/admin/projects/${projectId}/runs/delete-detail`, { runs });
      selected.clear();
      result.textContent = resultText(r);
      result.hidden = false;
      toast(resultText(r), { type: 'success' });
      await load();
    } catch (e) {
      error.textContent = deleteErrorText(e);
      error.hidden = false;
      toast(deleteErrorText(e), { type: 'error' });
    } finally {
      syncButton();
    }
  });

  const fromUrl = new URL(globalThis.location.href).searchParams.get('project') ?? '';
  if (fromUrl && [...projectSelect.options].some((o) => o.value === fromUrl)) {
    projectSelect.value = fromUrl;
    projectId = fromUrl;
  }
  syncButton();
  load();
}
