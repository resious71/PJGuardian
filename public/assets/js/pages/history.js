// 히스토리(P21, 명세 8): 추이 차트(신규·해결·재오픈 막대 + 열린 결함 선, 변경 표식), 분석 이력 표, 직전 성공 분석 대비 비교 패널.
// 주소 ?analysis=&page=&n=&unchanged=hide(새로 고침·링크 공유). P27: 성공(변경 없음) 행은 배지·"N회 확인 · 마지막 확인"·기준 Run 링크, 비교·차트에서 빠진다. 차트·표·패널의 값은 textContent로만 넣는다(innerHTML 금지). 시각은 KST.
// 진행 중 분석은 이력에 보이되 패널은 "끝나면 볼 수 있음"(자동 갱신 없음, 진행은 분석 탭).
import { Chart } from '../../vendor/chartjs/chart.js';
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { el, clear, setText } from '../core/dom.js';
import { formatDateTime, timezoneFromDocument } from '../core/time.js';
import { icon, iconWithLabel } from '../ui/icons.js';
import { badge } from '../ui/badge.js';
import { toast } from '../ui/toast.js';
import { renderPagination, pageCount } from '../ui/pagination.js';
import { chartFigure, dataTable, setTableMode, isTableMode } from '../ui/chart_table.js';
import { clipText, tipTarget } from '../ui/clip.js';
import { legendOptions } from '../ui/chart_style.js';
import { sourceNode } from '../ui/source_cell.js';
import {
  historyState, historySearch, historyChartData, historyChartTable, delta, rowName, changeLines, emptyKind, noChange, SOURCES,
  statusBadgeName, unchangedFold, unchangedBaseText, unchangedBaseShort,
} from '../lib/history_core.js';

const SEVERITIES = ['critical', 'high', 'medium', 'low', 'not_defined'];
const root = document.querySelector('[data-history-page]');
if (root) init(root);

function init(section) {
  const api = createApi();
  const tz = timezoneFromDocument();
  const pid = section.dataset.projectId;
  const size = Number(section.dataset.pageSize) || 20;
  const projectBase = section.dataset.projectBase;
  const analysisBase = section.dataset.analysisBase;
  const $ = (s) => section.querySelector(s);
  const time = (iso) => (iso ? formatDateTime(iso, tz) : '—');
  const css = (n) => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
  let state = historyState(location.search);
  let chart = null;
  let seq = 0;

  function report(e) {
    toast(e.requestId ? `${e.message} (${t('common.request_id', { id: e.requestId })})` : e.message, { type: 'error' });
  }

  function go(next, push = true) {
    state = { ...state, ...next };
    const url = location.pathname + historySearch(state);
    if (push) history.pushState(null, '', url);
    else history.replaceState(null, '', url);
  }

  const listHref = (filter) => `${projectBase}?${new URLSearchParams(Object.entries(filter).map(([k, v]) => [k, String(v)])).toString()}`;

  function deltaNode(n) {
    const d = delta(n);
    if (!d.dir) return el('span', { class: 'muted', text: d.text });
    return el('span', { class: `delta delta--${d.dir}` }, [icon(`delta.${d.dir}`, 16), ' ', d.text, el('span', { class: 'sr-only', text: ` ${t(`icon.delta.${d.dir}`)}` })]);
  }

  function changedBadge(changes) {
    const lines = changeLines(changes);
    return tipTarget(el('span', { class: 'tag tag--changed', dataset: { changed: '' }, 'aria-label': `${t('icon.ui.changed')}: ${lines.join(', ')}` },
      [icon('ui.changed', 16), ' ', t('icon.ui.changed')]), lines.join('\n'));
  }

  // ---- 이력 표 ----
  async function loadList() {
    const res = await api.get(`/api/v1/projects/${pid}/history`, { query: { page: state.page, size, ...(state.hide ? { unchanged: 'hide' } : {}) } });
    const rows = res.data;
    const total = res.page.total;
    const body = $('[data-history-rows]');
    clear(body);
    for (const r of rows) {
      const ok = r.status === 'succeeded' && r.counts;
      const select = el('button', { type: 'button', class: 'btn btn--small btn--link', 'aria-pressed': String(r.analysis_id === state.analysis), disabled: r.status !== 'succeeded' || r.unchanged,
        on: { click: () => { go({ analysis: r.analysis_id }); markSelected(); loadPanel(); } } }, [t('history.show_compare')]);
      const src = SOURCES[r.source.kind] ?? 'source.upload';
      body.appendChild(el('tr', { dataset: { analysisId: r.analysis_id, status: r.status, ...(r.unchanged ? { unchanged: '' } : {}) }, class: r.analysis_id === state.analysis ? 'is-selected' : '' }, [
        el('th', { scope: 'row' }, [el('a', { href: `${analysisBase}${r.analysis_id}`, text: rowName(r) }),
          // P21B: 상세가 삭제된 Run(번호·건수·변경 표시는 그대로)
          r.detail_deleted ? tipTarget(el('span', { class: 'tag history-deleted', dataset: { detailDeleted: '' }, text: t('history.run_deleted_mark') }), t('runs.detail_deleted_tip', { run: r.run })) : '']),
        el('td', {}, [time(r.finished_at ?? r.queued_at)]),
        el('td', {}, [badge(statusBadgeName(r)), ...(r.failure ? [' ', el('span', { class: 'muted', text: r.failure.title })] : []), ...(r.unchanged ? unchangedNodes(r) : [])]),
        el('td', {}, ok ? [String(r.open_total ?? '—'), ' ', deltaNode(r.open_delta)] : ['—']),
        el('td', {}, ok ? [deltaNode(r.counts.new)] : ['—']),
        el('td', {}, ok ? [deltaNode(-r.counts.resolved)] : ['—']),
        el('td', {}, ok ? [deltaNode(r.counts.reopened)] : ['—']),
        el('td', {}, [`${r.tool_name} ${r.tool_version ?? ''}`.trim(), ...(r.changed ? [' ', changedBadge(r.changes)] : [])]),
        el('td', {}, [src && r.source.kind !== 'upload' ? sourceNode(r.source) : iconWithLabel(src, 16, r.source.file_name ?? undefined)]),
        el('td', {}, [select]),
      ]));
    }
    const pages = pageCount(total, size);
    const pager = $('[data-history-pagination]');
    pager.hidden = pages <= 1;
    if (pages > 1) renderPagination(pager, { page: state.page, pages, onChange: (p) => { go({ page: p }); loadList().catch(report); } });
    $('[data-history-list]').hidden = total === 0 && !state.hide;
    if (total === 0 && state.hide) body.appendChild(el('tr', {}, [el('td', { colspan: '10', class: 'muted', text: t('history.hidden_all') })]));
    return { rows, total };
  }

  // P27: "3회 확인 · 마지막 확인 26-10-08 19:05" + 기준 Run(그 리비전을 실제로 분석한 분석)으로 이동
  function unchangedNodes(r) {
    const fmt = (iso) => formatDateTime(iso, tz);
    const base = r.unchanged_base;
    return [
      ' ',
      base
        ? tipTarget(el('a', { href: `${analysisBase}${base.analysis_id}`, class: 'history-unchanged__base', dataset: { unchangedBase: '' },
          'aria-label': unchangedBaseText(r.source.kind, r.source.revision, base, fmt), text: unchangedBaseShort(base) }), unchangedBaseText(r.source.kind, r.source.revision, base, fmt))
        : '',
      el('br'),
      el('span', { class: 'muted history-unchanged__fold', dataset: { unchangedFold: '' }, text: unchangedFold(r, fmt) }),
    ];
  }

  function markSelected() {
    for (const tr of section.querySelectorAll('[data-history-rows] tr')) {
      const on = Number(tr.dataset.analysisId) === state.analysis;
      tr.classList.toggle('is-selected', on);
      tr.querySelector('button')?.setAttribute('aria-pressed', String(on));
    }
  }

  // ---- 차트 ----
  async function loadChart() {
    // 변경 없음은 분석이 아니므로 추이에 넣지 않는다(서버에서 빼고 받는다)
    const res = await api.get(`/api/v1/projects/${pid}/history`, { query: { page: 1, size: 100, unchanged: 'hide' } });
    const rows = res.data.filter((r) => r.status === 'succeeded' && !r.unchanged).slice(0, state.n);
    const box = $('[data-history-figure]');
    clear(box);
    chart?.destroy();
    chart = null;
    const sec = $('[data-history-chart]');
    sec.hidden = rows.length === 0;
    if (!rows.length) return;
    const d = historyChartData(rows);
    const canvas = el('canvas', { role: 'img', 'aria-label': t('history.chart_aria', { n: d.labels.length, newer: d.newer.reduce((a, b) => a + b, 0), resolved: -d.resolved.reduce((a, b) => a + b, 0), changed: d.markers.filter((m) => m !== null).length }) });
    const table = dataTable(t('history.chart_title'), [t('history.col_run'), t('history.col_new'), t('history.col_resolved'), t('history.col_reopened'), t('history.col_open'), t('icon.ui.changed')],
      historyChartTable(rows).map((r) => r.map(String)));
    box.append(chartFigure(t('history.chart_caption', { n: d.labels.length }), { chart: canvas, table, tableMode: isTableMode(sec) }));
    chart = new Chart(canvas, {
      data: {
        labels: d.labels,
        datasets: [
          { type: 'bar', label: t('history.col_new'), data: d.newer, backgroundColor: css('--chart-critical'), stack: 'c', yAxisID: 'y' },
          { type: 'bar', label: t('history.col_reopened'), data: d.reopened, backgroundColor: css('--chart-medium'), borderColor: css('--chart-text'), borderWidth: 1, stack: 'c', yAxisID: 'y' },
          { type: 'bar', label: t('history.col_resolved_down'), data: d.resolved, backgroundColor: css('--chart-low'), stack: 'c', yAxisID: 'y' },
          { type: 'line', label: t('history.col_open'), data: d.open, borderColor: css('--chart-total'), backgroundColor: css('--chart-total'), borderWidth: 2, pointRadius: 3, yAxisID: 'y1' },
          { type: 'line', label: t('icon.ui.changed'), data: d.markers, showLine: false, pointStyle: 'rectRot', pointRadius: 9, borderColor: css('--chart-marker'), backgroundColor: css('--chart-marker'), yAxisID: 'y1' },
        ],
      },
      options: {
        responsive: true, maintainAspectRatio: false, animation: false,
        scales: {
          x: { stacked: true, ticks: { color: css('--chart-text') } },
          y: { stacked: true, ticks: { precision: 0, color: css('--chart-text') }, title: { display: true, text: t('history.axis_changes'), color: css('--chart-text') } },
          y1: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, ticks: { precision: 0, color: css('--chart-text') }, title: { display: true, text: t('history.col_open'), color: css('--chart-text') } },
        },
        plugins: { legend: legendOptions(css('--chart-text')), tooltip: { callbacks: { footer: () => t('history.chart_click') } } },
        onClick: (_e, elements) => {
          const id = d.analysisIds[elements[0]?.index ?? -1];
          if (id) { go({ analysis: id }); markSelected(); loadPanel(); }
        },
      },
    });
  }

  // ---- 비교 패널 ----
  async function loadPanel() {
    const my = ++seq;
    const panel = $('[data-history-panel]');
    const body = $('[data-panel-body]');
    const notices = $('[data-panel-notices]');
    panel.hidden = false;
    clear(body);
    clear(notices);
    setText($('[data-panel-baseline]'), t('defects.loading'));
    try {
      const c = await api.get(`/api/v1/analyses/${state.analysis}/comparison`);
      if (my !== seq) return;
      renderPanel(c);
    } catch (e) {
      if (my !== seq) return;
      setText($('[data-panel-title]'), t('analyses.title_of', { id: state.analysis }));
      setText($('[data-panel-baseline]'), e.code === 'not_succeeded' ? t('history.panel_not_ready') : (e.code === 'analysis_unchanged' ? e.message : t('history.panel_failed')));
      for (const b of [$('[data-panel-prev]'), $('[data-panel-next]')]) b.hidden = true;
      if (e.code !== 'not_succeeded' && e.code !== 'analysis_unchanged') report(e);
    }
  }

  function renderPanel(c) {
    const a = c.analysis;
    setText($('[data-panel-title]'), c.baseline
      ? t('history.panel_title', { run: a.run ?? '—', base: c.baseline.run ?? '—' })
      : t('history.panel_title_first', { run: a.run ?? '—' }));
    setText($('[data-panel-baseline]'), c.baseline
      ? t('history.panel_baseline', { run: c.baseline.run ?? '—', id: c.baseline.analysis_id, time: time(c.baseline.finished_at) })
      : t('history.panel_no_baseline'));
    const notices = $('[data-panel-notices]');
    const note = (level, text, key) => notices.appendChild(el('p', { class: `notice notice--${level}`, dataset: { notice: key } }, [icon(level === 'warn' ? 'system.warn' : 'ui.info', 16), ' ', text]));
    if (c.changed) note('warn', `${t('history.warn_tool_change')} (${changeLines(c.changes).join(', ')})`, 'tool_change');
    if (c.scope?.changed) note('warn', t('history.warn_scope_change', { from: c.scope.from_files, to: c.scope.to_files }), 'scope_change');
    if (noChange(c)) note('info', t('history.no_change'), 'no_change');
    // P21B: 상세가 삭제된 Run은 건수(저장 값)만 보이고 결함 목록으로 가는 링크는 끈다
    const linkable = !a.detail_deleted;
    if (!linkable) note('info', t('history.cards_disabled'), 'detail_deleted');
    // 이전·다음(성공 분석만)
    const nav = (btn, id) => {
      btn.hidden = false;
      btn.disabled = !id;
      btn.onclick = id ? () => { go({ analysis: id }); markSelected(); loadPanel(); } : null;
    };
    nav($('[data-panel-prev]'), c.nav.prev);
    nav($('[data-panel-next]'), c.nav.next);
    const body = $('[data-panel-body]');
    // 건수 카드(누르면 결함 탭의 analysis+change 목록)
    const cards = el('ul', { class: 'result-counts history-cards' });
    for (const k of ['new', 'resolved', 'reopened', 'retained', 'out_of_scope']) {
      const n = c.counts[k];
      if (n === null) continue;
      const value = c.filters[k] && linkable
        ? el('a', { class: 'result-counts__n', href: listHref(c.filters[k]), 'aria-label': t('history.card_link', { label: t(`history.card.${k}`), n }) }, [n.toLocaleString('ko-KR')])
        : el('span', { class: 'result-counts__n', text: n.toLocaleString('ko-KR') });
      cards.appendChild(el('li', { class: 'result-counts__item', dataset: { card: k } }, [
        el('span', { class: 'result-counts__label', text: t(`history.card.${k}`) }), value, el('span', { class: 'muted history-card__help', text: t(`history.card_help.${k}`) }),
      ]));
    }
    body.appendChild(cards);
    // 심각도별 분포
    body.appendChild(el('div', { class: 'table-scroll' }, [dataTable(t('history.severity_title'), [t('history.col_severity'), t('history.col_new'), t('history.col_resolved'), t('history.col_reopened')],
      SEVERITIES.map((s) => [t(`icon.severity.${s}`), String(c.sets.new.by_severity[s]), String(c.sets.resolved.by_severity[s]), String(c.sets.reopened.by_severity[s])]), { visibleCaption: true })]));
    // 신규·해결 상위 규칙·파일(규칙 키는 텍스트, 건수가 필터 목록 링크)
    const grid = el('div', { class: 'history-tops' });
    for (const set of ['new', 'resolved']) {
      for (const [kind, key, filter] of [['top_rules', 'rule_key', 'rule'], ['top_files', 'file_path', 'file']]) {
        const items = c.sets[set][kind];
        grid.appendChild(el('section', { class: 'history-top', dataset: { top: `${set}-${kind}` } }, [
          el('h3', { class: 'subhead', text: t(`history.top_${set}`, { what: t(`history.${kind}`) }) }),
          items.length ? el('ol', { class: 'count-list count-list--plain' }, items.map((it) => el('li', {}, [
            kind === 'top_files' ? clipText(it[key], { kind: 'path' }) : el('code', { text: it[key] }), ' ',
            linkable
              ? el('a', { class: 'count-list__n', href: listHref({ ...c.filters[set], [filter]: it[key] }), 'aria-label': `${it[key]} ${it.count}` }, [String(it.count)])
              : el('span', { class: 'count-list__n', text: String(it.count) }),
          ]))) : el('p', { class: 'muted', text: t('history.top_empty') }),
        ]));
      }
    }
    body.appendChild(grid);
    body.appendChild(el('p', { class: 'muted', text: t('history.resolved_note') }));
  }

  // ---- 빈 상태 ----
  function showEmpty(kind) {
    const box = $('[data-history-empty]');
    clear(box);
    box.hidden = kind === null;
    if (!kind) return;
    box.appendChild(el('section', { class: 'card empty-state', dataset: { empty: kind } }, [
      el('div', { class: 'empty-state__icon' }, [icon('ui.empty', 24)]),
      el('h2', { class: 'empty-state__title', text: t(`history.empty.${kind}.title`) }),
      el('p', { class: 'empty-state__body', text: t(`history.empty.${kind}.body`) }),
      ...(kind === 'no_analysis' ? [el('a', { class: 'btn btn--primary', href: section.dataset.uploadHref, text: t('upload.button') })] : []),
    ]));
  }

  // ---- 시작 ----
  const hideBox = $('[data-history-hide-unchanged]');
  if (hideBox) {
    hideBox.checked = state.hide;
    hideBox.addEventListener('change', () => { go({ hide: hideBox.checked, page: 1 }); loadList().catch(report); });
  }
  const nSel = $('[data-history-n]');
  nSel.value = String(state.n);
  nSel.addEventListener('change', () => { go({ n: Number(nSel.value) }, false); loadChart().catch(report); });
  const toggle = $('[data-history-chart] [data-toggle-table]');
  toggle.addEventListener('click', () => {
    const on = toggle.getAttribute('aria-pressed') !== 'true';
    toggle.setAttribute('aria-pressed', String(on));
    setText(toggle, t(on ? 'dashboard.show_chart' : 'dashboard.show_table'));
    setTableMode($('[data-history-chart]'), on);
  });
  window.addEventListener('popstate', () => { state = historyState(location.search); if (hideBox) hideBox.checked = state.hide; start(); });

  async function start() {
    try {
      const { rows, total } = await loadList();
      setText($('[data-history-status]'), '');
      const kind = state.hide && total === 0 ? null : emptyKind(rows, total);
      showEmpty(kind === 'single' ? null : kind);
      if (!state.analysis) {
        const latest = rows.find((r) => r.status === 'succeeded' && !r.unchanged);
        if (latest) go({ analysis: latest.analysis_id }, false);
      }
      markSelected();
      await loadChart();
      if (state.analysis) await loadPanel();
    } catch (e) {
      setText($('[data-history-status]'), t('history.load_failed'));
      report(e);
    }
  }
  start();
}
