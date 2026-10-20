// 대시보드(명세 9, P20). 구역(요약·추이·규칙 상위·파일 상위·언어/도구)마다 API를 따로 불러 그린다: 한 구역이 느리거나 실패해도
// 다른 구역은 그대로 보이고, 실패한 구역에만 "다시 시도" 버튼이 나온다.
// - 차트: Chart.js 4(로컬 번들, CSP style-src 'self' + nonce 그대로, unsafe-inline 없음). 색은 CSS 변수 --chart-*(색각 이상에서도 구분되는 팔레트) 한 곳.
// - 접근성: 차트마다 요약 aria-label, "표로 보기"(같은 데이터를 표로), 심각도는 선 모양·점 모양을 달리 해 색에만 의존하지 않음.
// - 값은 모두 textContent(프로젝트 이름·파일 경로·규칙 키). 차트 라벨은 캔버스에 글자로 그려 HTML로 해석되지 않는다. 규칙 키는 링크가 아니다(P22).
import { Chart } from '../../vendor/chartjs/chart.js';
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { el, clear, setText } from '../core/dom.js';
import { formatDateTime, timezoneFromDocument } from '../core/time.js';
import { icon, iconWithLabel } from '../ui/icons.js';
import { errorText, formDialog, field } from '../ui/form_dialog.js';
import { comboboxFromSelect } from '../ui/combobox.js';
import { chartFigure, dataTable, setTableMode, isTableMode } from '../ui/chart_table.js';
import { clipText, tipTarget } from '../ui/clip.js';
import { toast } from '../ui/toast.js';
import { legendOptions, barStyle, barValueLabels, BAR_VALUE_PADDING } from '../ui/chart_style.js';
import {
  SEVERITIES, STATUSES, SEVERITY_POINT, SEVERITY_DASH, trendData, pointHref, trendTable, changeLines, barData, topSummary, emptyState, count, appLink, toolRows,
  languageFromSearch, withLanguage,
} from '../lib/dashboard_core.js';

const root = document.querySelector('[data-dashboard]');
if (root) init(root);

function init(dash) {
  const api = createApi();
  const tz = timezoneFromDocument();
  const base = dash.dataset.appBase ?? '';
  const isAdmin = dash.dataset.admin === '1';
  const time = (iso) => (iso ? formatDateTime(iso, tz) : '—');
  const css = getComputedStyle(document.documentElement);
  const color = (name) => css.getPropertyValue(`--chart-${name}`).trim() || '#555';
  const charts = new Map(); // 구역 → Chart 목록
  const asOf = new Map();
  const section = (name) => dash.querySelector(`[data-section="${name}"]`);

  function destroy(name) {
    for (const c of charts.get(name) ?? []) c.destroy();
    charts.set(name, []);
  }

  function showAsOf(name, data) {
    asOf.set(name, data.as_of);
    const latest = [...asOf.values()].filter(Boolean).sort().pop();
    setText(document.querySelector('[data-as-of]'), latest ? t('dashboard.as_of', { time: time(latest) }) : '');
  }

  /** 구역 하나 불러오기: 스켈레톤 → 데이터 → 그리기, 실패하면 그 구역에만 오류와 다시 시도 */
  async function load(name, path, query, render) {
    const sec = section(name);
    const body = sec.querySelector('[data-body]');
    const err = sec.querySelector('[data-error-box]');
    sec.setAttribute('aria-busy', 'true');
    sec.querySelector('[data-skeleton]').hidden = false;
    err.hidden = true;
    try {
      const data = await api.get(path, { query });
      body.hidden = false;
      render(data, sec, body);
      showAsOf(name, data);
    } catch (e) {
      body.hidden = true;
      clear(err);
      const retry = el('button', { type: 'button', class: 'btn btn--small' }, [iconWithLabel('action.fetch_now', 16, t('dashboard.retry'))]);
      retry.addEventListener('click', () => load(name, path, query, render));
      err.append(el('p', { class: 'notice notice--error', role: 'alert' }, [icon('system.error', 16), ' ', t('dashboard.section_error', { error: errorText(e) })]), retry);
      err.hidden = false;
    } finally {
      sec.querySelector('[data-skeleton]').hidden = true;
      sec.setAttribute('aria-busy', 'false');
    }
  }

  // ---- 요약 ----
  function tile(label, value, extra = [], iconName = null) {
    return el('div', { class: 'stat-tile' }, [
      el('div', { class: 'stat-tile__label' }, [iconName ? iconWithLabel(iconName, 16, label) : label]),
      el('div', { class: 'stat-tile__value', text: count(value) }),
      ...extra,
    ]);
  }

  function renderSummary(s, sec, body) {
    clear(body);
    const empty = emptyState(s, isAdmin);
    renderEmpty(empty);
    body.append(
      tile(t('dashboard.projects_visible'), s.projects.visible, [el('div', { class: 'muted', text: t('dashboard.projects_detail', { analysed: s.projects.with_analysis, none: s.projects.without_analysis }) })], 'nav.projects'),
      tile(t('dashboard.open_total'), s.open.total, [el('ul', { class: 'stat-list' }, SEVERITIES.map((sv) => el('li', {}, [iconWithLabel(`severity.${sv}`, 16), ` ${s.open.by_severity[sv]}`])))], 'nav.defects'),
      tile(t('dashboard.by_status'), STATUSES.reduce((a, st) => a + s.by_status[st], 0), [el('ul', { class: 'stat-list' }, STATUSES.map((st) => el('li', {}, [iconWithLabel(`defect_status.${st}`, 16), ` ${s.by_status[st]}`])))]),
      tile(t('dashboard.mine_open'), s.mine.open, [el('a', { href: `${base}/my-defects`, text: t('dashboard.mine_link') })], 'nav.my_defects'),
      tile(t('dashboard.analyses_7d'), s.analyses_7d.succeeded + s.analyses_7d.failed + s.analyses_7d.cancelled, [el('ul', { class: 'stat-list' }, ['succeeded', 'failed', 'cancelled'].map((k) => el('li', {}, [iconWithLabel(`analysis_status.${k}`, 16), ` ${s.analyses_7d[k]}`])))]),
    );
    const failed = el('div', { class: 'stat-tile stat-tile--wide' }, [el('div', { class: 'stat-tile__label' }, [iconWithLabel('analysis_status.failed', 16, t('dashboard.failed_projects'))])]);
    if (!s.failed_projects.length) failed.append(el('p', { class: 'muted', text: t('dashboard.failed_none') }));
    else {
      failed.append(el('ul', { class: 'plain-list' }, s.failed_projects.map((f) => el('li', {}, [
        el('a', { href: `${base}/analyses/${f.analysis_id}`, text: f.project_name }), ` — ${f.failure_title} (${time(f.finished_at)})`,
      ]))));
    }
    body.append(failed);
  }

  function renderEmpty(kind) {
    const box = dash.querySelector('[data-empty]');
    clear(box);
    box.hidden = kind === null;
    const hide = kind !== null && kind !== 'no_defects';
    for (const n of ['trend', 'top-rules', 'top-files', 'tools']) section(n).hidden = hide;
    if (kind === null) return;
    const action = {
      no_projects: [t('dashboard.empty.no_projects.action'), `${base}/projects/new`],
      no_access: [null, null],
      no_analysis: [t('dashboard.empty.no_analysis.action'), `${base}/upload`],
      no_defects: [t('dashboard.empty.no_defects.action'), `${base}/projects`],
    }[kind];
    box.append(el('section', { class: 'empty-state', dataset: { empty: kind } }, [
      el('div', { class: 'empty-state__icon' }, [icon(kind === 'no_defects' ? 'system.ok' : 'ui.empty', 24)]),
      el('h2', { class: 'empty-state__title', text: t(`dashboard.empty.${kind}.title`) }),
      el('p', { class: 'empty-state__body', text: t(`dashboard.empty.${kind}.body`) }),
      ...(action[0] ? [el('a', { class: 'btn btn--primary', href: action[1], text: action[0] })] : []),
    ]));
  }

  // ---- 추이 ----
  function renderTrend(d, sec, body) {
    destroy('trend');
    const box = body.querySelector('[data-charts]');
    clear(box);
    const tableMode = isTableMode(sec);
    if (!d.series.length) {
      box.append(el('p', { class: 'muted', text: t('dashboard.trend_none') }));
      return;
    }
    for (const s of d.series) {
      if (s.state !== 'ok') {
        const fig = chartFigure(s.project_name);
        fig.append(el('p', { class: 'muted', text: t(`dashboard.trend_state.${s.state}`) }));
        box.append(fig);
        continue;
      }
      const data = trendData(s, time, (n) => t('runs.label', { run: n }));
      const last = s.points[s.points.length - 1];
      const canvas = el('canvas', { role: 'img', 'aria-label': t('dashboard.trend_aria', { project: s.project_name, n: s.points.length, total: last.total, changed: data.markers.filter((m) => m !== null).length }) });
      // 표로 보기(같은 데이터): 이 프로젝트의 제목 바로 아래(P21 0B — 표들이 제목 없이 따로 몰리지 않게)
      const tbl = trendTable(s, time);
      const tableNode = dataTable(s.project_name, tbl.head.map((h) => t(SEVERITIES.includes(h) ? `icon.severity.${h}` : `dashboard.col.${h}`)), tbl.rows.map((r, i) => [
        pointHref(base, s.project_id, s.points[i])
          ? el('a', { href: pointHref(base, s.project_id, s.points[i]), text: r[0] === null ? t('runs.none') : t('runs.label', { run: r[0] }) })
          : tipTarget(el('span', { text: `${t('runs.label', { run: r[0] })}(${t('runs.detail_deleted_short')})` }), t('dashboard.point_detail_deleted', { run: r[0] })),
        ...r.slice(1, -1).map(String), r[r.length - 1] ? t('dashboard.changed_yes') : '—',
      ]));
      box.append(chartFigure(s.project_name, { chart: canvas, table: tableNode, tableMode }));
      const datasets = [
        { label: t('dashboard.series_total'), data: data.total, borderColor: color('total'), backgroundColor: color('total'), borderWidth: 3, pointStyle: 'circle', pointRadius: 3 },
        ...SEVERITIES.map((sv) => ({
          label: t(`icon.severity.${sv}`), data: data.severity[sv], borderColor: color(sv), backgroundColor: color(sv), borderWidth: 1.5,
          borderDash: SEVERITY_DASH[sv], pointStyle: SEVERITY_POINT[sv], pointRadius: 4,
        })),
        { label: t('dashboard.series_changed'), data: data.markers, showLine: false, pointStyle: 'rectRot', pointRadius: 9, pointHoverRadius: 11,
          borderColor: color('marker'), backgroundColor: color('marker'), borderWidth: 2 },
      ];
      const chart = new Chart(canvas, {
        type: 'line',
        data: { labels: data.labels, datasets },
        options: {
          responsive: true, maintainAspectRatio: false, animation: false,
          interaction: { mode: 'nearest', intersect: true },
          scales: { y: { beginAtZero: true, ticks: { precision: 0, color: color('text') }, grid: { color: color('grid') } }, x: { ticks: { color: color('text') }, grid: { color: color('grid') } } },
          plugins: {
            legend: legendOptions(color('text')),
            tooltip: {
              callbacks: {
                title: (items) => `${data.labels[items[0]?.dataIndex ?? 0]} · ${data.times[items[0]?.dataIndex ?? 0]}`,
                afterBody: (items) => {
                  const p = s.points[items[0]?.dataIndex ?? -1];
                  if (!p?.changed) return [];
                  return [t('dashboard.changed_header'), ...changeLines(p).map((c) => t('dashboard.changed_line', { field: t(`dashboard.field.${c.field}`), from: c.from, to: c.to }))];
                },
                footer: () => t('dashboard.click_analysis'),
              },
            },
          },
          onClick: (_e, elements) => {
            const p = s.points[elements[0]?.index ?? -1];
            if (!p) return;
            const href = pointHref(base, s.project_id, p);
            if (href) location.assign(href);
            else toast(t('dashboard.point_detail_deleted', { run: p.run }), { type: 'info' });
          },
        },
      });
      charts.get('trend').push(chart);
    }
  }

  // ---- 규칙·파일 상위 ----
  function renderBars(kind) {
    return (d, sec, body) => {
      destroy(kind);
      const canvas = body.querySelector('[data-canvas]');
      const tableBox = body.querySelector('[data-table]');
      clear(tableBox);
      const prev = body.querySelector('[data-none]');
      if (prev) prev.remove();
      if (!d.items.length) {
        canvas.parentElement.hidden = true;
        body.prepend(el('p', { class: 'muted', dataset: { none: '1' }, text: t('dashboard.top_none') }));
        return;
      }
      canvas.parentElement.hidden = isTableMode(sec);
      const multi = d.project_id === null && new Set(d.items.map((it) => it.project_id ?? 0)).size > 1;
      const b = barData(d.items, kind === 'top-rules' ? 'rules' : 'files', { multiProject: multi });
      canvas.setAttribute('aria-label', t('dashboard.bars_aria', { title: t(kind === 'top-rules' ? 'dashboard.top_rules_title' : 'dashboard.top_files_title'), top: topSummary(d.items, kind === 'top-rules' ? 'rules' : 'files') }));
      const chart = new Chart(canvas, {
        type: 'bar',
        data: { labels: b.labels, datasets: [{ label: t('dashboard.series_open'), data: b.counts, ...barStyle(color) }] },
        plugins: [barValueLabels(color('text'))],
        options: {
          indexAxis: 'y', responsive: true, maintainAspectRatio: false, animation: false, layout: { padding: { right: BAR_VALUE_PADDING } },
          scales: { x: { beginAtZero: true, ticks: { precision: 0, color: color('text') }, grid: { color: color('grid') } }, y: { ticks: { color: color('text') } } },
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                title: (items) => b.fullLabels[items[0]?.dataIndex ?? 0],
                afterBody: (items) => SEVERITIES.map((sv) => `${t(`icon.severity.${sv}`)}: ${b.severity[sv][items[0]?.dataIndex ?? 0]}`),
                footer: () => t('dashboard.click_defects'),
              },
            },
          },
          onClick: (_e, elements) => {
            const href = appLink(base, b.links[elements[0]?.index ?? -1]);
            if (href) location.assign(href);
          },
        },
      });
      charts.get(kind).push(chart);
      const head = kind === 'top-rules'
        ? [t('dashboard.col.rule'), t('dashboard.col.count'), ...SEVERITIES.map((sv) => t(`icon.severity.${sv}`)), t('dashboard.col.projects')]
        : [t('dashboard.col.file'), t('dashboard.col.project'), t('dashboard.col.count'), ...SEVERITIES.map((sv) => t(`icon.severity.${sv}`))];
      tableBox.append(dataTable(t(kind === 'top-rules' ? 'dashboard.top_rules_title' : 'dashboard.top_files_title'), head, d.items.map((it, i) => {
        // 파일 경로는 결함 표와 같은 말줄임·툴팁(P21 0C)
        const link = kind === 'top-rules' ? el('a', { href: appLink(base, it.link) ?? '#', text: it.rule_key })
          : el('a', { href: appLink(base, it.link) ?? '#' }, [clipText(it.file_path, { kind: 'path', focusable: false })]);
        const sev = SEVERITIES.map((sv) => String(it.by_severity[sv]));
        return kind === 'top-rules'
          ? [link, String(it.count), ...sev, it.projects.map((p) => `${p.name} ${p.count}`).join(', ')]
          : [link, it.project_name, String(it.count), ...sev];
      })));
    };
  }

  // ---- 언어·도구 ----
  function renderTools(d, sec, body) {
    clear(body);
    const rows = toolRows(d.groups);
    body.append(dataTable(t('dashboard.tools_title'), [t('dashboard.col.tool'), t('dashboard.col.projects_n'), t('dashboard.col.open'), ...SEVERITIES.map((sv) => t(`icon.severity.${sv}`)), t('dashboard.col.versions')],
      rows.map((r) => [t(`dashboard.tool.${r.tool}`), t('dashboard.projects_of', { n: r.projects, analysed: r.withAnalysis }), String(r.open), ...r.severity.map(String), r.versions || t('dashboard.versions_none')]), { visibleCaption: true }));
  }

  // ---- 표로 보기 전환(키보드: 버튼) ----
  for (const btn of dash.querySelectorAll('[data-toggle-table]')) {
    btn.addEventListener('click', () => {
      const sec = btn.closest('[data-section]');
      const on = btn.getAttribute('aria-pressed') !== 'true';
      btn.setAttribute('aria-pressed', String(on));
      setText(btn, t(on ? 'dashboard.show_chart' : 'dashboard.show_table'));
      setTableMode(sec, on);
    });
  }

  // ---- 분석 언어 필터(P21C): 추이·규칙별 상위 10에만. 주소 ?language= 로 유지(잘못된 값은 전체) ----
  const langSelect = dash.querySelector('[data-language]');
  langSelect.value = languageFromSearch(location.search);
  const language = () => langSelect.value;
  const scopeOf = (name) => section(name).querySelector('[data-scope]');
  /** 언어에 맞는 프로젝트만 선택지에 남긴다(추이·규칙 상위). 파일별 상위는 언어 필터 대상이 아니다 */
  const filterProjectOptions = () => {
    for (const name of ['trend', 'top-rules']) {
      for (const o of scopeOf(name).options) o.hidden = o.value !== '' && language() !== '' && o.dataset.language !== language();
    }
  };
  filterProjectOptions();

  const loaders = {
    summary: () => load('summary', '/api/v1/dashboard/summary', {}, renderSummary),
    trend: () => load('trend', '/api/v1/dashboard/trend', { project_id: scopeOf('trend').value, language: language() }, renderTrend),
    'top-rules': () => load('top-rules', '/api/v1/dashboard/top-rules', { project_id: scopeOf('top-rules').value, language: language() }, renderBars('top-rules')),
    'top-files': () => load('top-files', '/api/v1/dashboard/top-files', { project_id: scopeOf('top-files').value }, renderBars('top-files')),
    tools: () => load('tools', '/api/v1/dashboard/tools', {}, renderTools),
  };
  // 선택 상자 → 입력해서 좁혀 고르는 콤보박스(P21C, ui/combobox.js). 값의 원천은 원래 <select>(change 그대로)
  let quiet = false; // 언어를 바꾸며 프로젝트 선택지를 다시 읽을 때 change로 두 번 부르지 않게
  const combos = {};
  for (const name of ['trend', 'top-rules', 'top-files']) {
    scopeOf(name).addEventListener('change', () => { if (!quiet) loaders[name](); });
    combos[name] = comboboxFromSelect(scopeOf(name));
  }
  comboboxFromSelect(langSelect);
  langSelect.addEventListener('change', () => {
    history.replaceState(null, '', withLanguage(`${location.pathname}${location.search}${location.hash}`, language()));
    filterProjectOptions();
    quiet = true;
    combos.trend.refresh();
    combos['top-rules'].refresh();
    quiet = false;
    loaders.trend();
    loaders['top-rules']();
  });

  // ---- 추이 프로젝트 수(admin, 전체 공통 설정 dashboard.trend_projects, P21C) ----
  const limitBtn = dash.querySelector('[data-trend-limit]');
  if (isAdmin && limitBtn) {
    limitBtn.addEventListener('click', async () => {
      const current = Number(limitBtn.dataset.value);
      let input = null;
      const saved = await formDialog({
        title: t('dashboard.trend_limit_title'),
        message: t('dashboard.trend_limit_message'),
        submitLabel: t('dashboard.trend_limit_save'),
        build: (id) => {
          const f = field(id, 'limit', t('dashboard.trend_limit_label'), { type: 'number', min: '1', max: '50', step: '1', value: String(current), inputmode: 'numeric', required: true },
            t('dashboard.trend_limit_help'));
          input = f.querySelector('input');
          return {
            content: [f],
            focus: input,
            onSubmit: async () => {
              const r = await api.patch('/api/v1/admin/settings', { 'dashboard.trend_projects': input.value.trim() });
              return r['dashboard.trend_projects'].value;
            },
          };
        },
      });
      if (saved === null) return;
      limitBtn.dataset.value = String(saved);
      setText(limitBtn, t('dashboard.trend_limit_button', { n: saved }));
      setText(scopeOf('trend').options[0], t('dashboard.trend_recent', { n: saved }));
      quiet = true;
      combos.trend.refresh();
      quiet = false;
      toast(t('dashboard.trend_limit_saved', { n: saved }), { type: 'success' });
      loaders.trend();
    });
  }
  // 새로 고침: 캐시를 우회하지 않는다(서버의 TTL·데이터 표지 규칙대로)
  document.querySelector('[data-refresh]').addEventListener('click', () => Object.values(loaders).forEach((f) => f()));
  Object.values(loaders).forEach((f) => f());
}
