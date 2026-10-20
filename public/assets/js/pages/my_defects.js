// 내게 지정된 결함(명세 17, P18): 상태 칩(다중 선택)·프로젝트 선택·페이지. 행은 P17 목록과 같은 모양(ui/defect_table.js) + 프로젝트 열.
// 필터 상태는 URL 쿼리(status, project, page). 읽기 위주 화면이라 수정은 결함 상세에서 한다.
// 내보내기(P23): 표 위 오른쪽 Excel·CSV 버튼 — 지금 적용된 상태·프로젝트 조건(쪽과 무관하게 전체), 0건이면 꺼짐.
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { clear, setText } from '../core/dom.js';
import { timezoneFromDocument } from '../core/time.js';
import { renderPagination, pageCount } from '../ui/pagination.js';
import { toast } from '../ui/toast.js';
import { defectRow } from '../ui/defect_table.js';
import { STATUSES, toggle } from '../lib/defect_filters.js';
import { mountExport } from '../ui/export_buttons.js';

const root = document.querySelector('[data-my-defects]');
if (root) init(root);

function init(section) {
  const api = createApi();
  const tz = timezoneFromDocument();
  const size = Number(section.dataset.pageSize) || 50;
  const detailBase = section.dataset.detailBase;
  const project = section.querySelector('#md-project');
  const chips = [...section.querySelectorAll('[data-facet="status"] .chip')];
  const count = section.querySelector('[data-count]');
  const table = section.querySelector('[data-table]');
  const rows = section.querySelector('[data-rows]');
  const pager = section.querySelector('[data-pagination]');
  const empty = section.querySelector('[data-empty-filtered]');
  let seq = 0;
  const exporter = mountExport(section.querySelector('[data-export]'), {
    url: '/api/v1/my-defects/export',
    getBody: () => ({ ...(state.status.length ? { status: state.status.join(',') } : {}), ...(state.project ? { project: state.project } : {}) }),
  });

  function readUrl() {
    const p = new URLSearchParams(location.search);
    const page = Number.parseInt(p.get('page') ?? '1', 10);
    return {
      status: (p.get('status') ?? '').split(',').filter((v) => STATUSES.includes(v)),
      project: /^[1-9][0-9]*$/.test(p.get('project') ?? '') ? p.get('project') : '',
      page: Number.isFinite(page) && page >= 1 ? page : 1,
    };
  }
  let state = readUrl();

  function apply(changes, push = true) {
    state = { ...state, ...changes };
    if (!('page' in changes)) state.page = 1;
    const p = new URLSearchParams();
    if (state.status.length) p.set('status', state.status.join(','));
    if (state.project) p.set('project', state.project);
    if (state.page > 1) p.set('page', String(state.page));
    const q = p.toString();
    if (push) history.pushState(null, '', location.pathname + (q ? `?${q}` : ''));
    sync();
    load();
  }

  function sync() {
    project.value = [...project.options].some((o) => o.value === state.project) ? state.project : '';
    for (const c of chips) c.setAttribute('aria-pressed', String(state.status.includes(c.dataset.value)));
  }

  async function load() {
    const my = ++seq;
    setText(count, t('defects.loading'));
    try {
      const query = { page: state.page, size };
      if (state.status.length) query.status = state.status.join(',');
      if (state.project) query.project = state.project;
      const res = await api.get('/api/v1/my-defects', { query });
      if (my !== seq) return;
      const total = res.page.total;
      const pages = pageCount(total, size);
      if (total > 0 && state.page > pages) {
        apply({ page: pages }, false);
        return;
      }
      clear(rows);
      for (const d of res.data) rows.appendChild(defectRow(d, { detailBase, tz, project: true }));
      setText(count, t('defects.count', { total: total.toLocaleString('ko-KR') }));
      exporter.update({ total });
      table.hidden = total === 0;
      empty.hidden = total !== 0;
      pager.hidden = pages <= 1;
      if (pages > 1) renderPagination(pager, { page: state.page, pages, onChange: (p) => apply({ page: p }) });
    } catch (e) {
      if (my !== seq) return;
      setText(count, t('defects.load_failed'));
      toast(e.requestId ? `${e.message} (${t('common.request_id', { id: e.requestId })})` : e.message, { type: 'error' });
    }
  }

  for (const c of chips) c.addEventListener('click', () => apply({ status: toggle(state.status, c.dataset.value) }));
  project.addEventListener('change', () => apply({ project: project.value }));
  window.addEventListener('popstate', () => { state = readUrl(); sync(); load(); });
  sync();
  load();
}
