// 관리자 "주기 분석"(P26): 스케줄러 상태(마지막 확인·지연 경고), 일정 표(주기·다음 실행·마지막 결과·연속 실패·마지막 오류·건너뜀),
// 최근 실패(재시도 대기 포함, 다음 주기 재수행 예정)와 최근 건너뜀. 값은 모두 textContent(프로젝트 이름은 사용자 입력).
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { el, clear } from '../core/dom.js';
import { formatDateTime, timezoneFromDocument } from '../core/time.js';
import { iconWithLabel } from '../ui/icons.js';
import { errorText } from '../ui/form_dialog.js';
import { lastResultKey, skipReasonKey } from '../lib/schedule_core.js';

const root = document.querySelector('[data-admin-schedules]');
if (root) {
  const api = createApi();
  const tz = timezoneFromDocument();
  const $ = (s) => root.querySelector(s);
  const base = document.querySelector('meta[name="app-base"]')?.content ?? '';
  const fmt = (iso) => (iso ? formatDateTime(iso, tz) : '—');
  const time = (iso) => (iso ? el('time', { datetime: iso, text: fmt(iso) }) : '—');
  const projectLink = (p, hash = '') => el('a', { href: `${base}/projects/${p.id}${hash}`, text: p.name });

  function resultNode(s) {
    if (!s.last_result) return t(lastResultKey(null));
    const tone = { succeeded: ['tone-ok', 'system.ok'], failed: ['tone-error', 'system.error'], cancelled: ['tone-neutral', 'ui.info'] }[s.last_result];
    const badge = el('span', { class: `badge badge--${tone[0]}` }, [iconWithLabel(tone[1], 16, t(lastResultKey(s.last_result)))]);
    const nodes = [badge, ' ', time(s.last_result_at)];
    if (s.last_analysis_id) nodes.push(' ', el('a', { href: `${base}/analyses/${s.last_analysis_id}`, text: t('admin_schedules.analysis_link', { id: s.last_analysis_id }) }));
    return nodes;
  }

  function row(s) {
    const failures = s.consecutive_failures > 0
      ? [el('span', { class: 'badge badge--tone-warn' }, [iconWithLabel('system.warn', 16, t('schedule.failures_n', { n: s.consecutive_failures.toLocaleString('ko-KR') }))])]
      : [t('schedule.failures_none')];
    return el('tr', { dataset: { projectId: s.project.id, enabled: s.enabled ? '1' : '0' } }, [
      el('td', {}, [projectLink(s.project, '/edit#project-schedule'), s.enabled ? '' : el('span', { class: 'tag tag--inactive', text: ` ${t('schedule.off')}` })]),
      el('td', { text: t('admin_schedules.interval', { days: s.interval_days, time: s.run_time }) }),
      el('td', {}, [s.enabled ? time(s.next_run_at) : '—']),
      el('td', {}, [].concat(resultNode(s))),
      el('td', {}, failures),
      el('td', { class: 'admin-schedules__wrap' }, [s.last_error ? `${s.last_error.title ?? s.last_error.code} (${fmt(s.last_error.at)})` : '—']),
      el('td', { class: 'admin-schedules__wrap' }, [s.skip_count > 0
        ? t('admin_schedules.skips', { n: s.skip_count.toLocaleString('ko-KR'), reason: s.last_skip ? t(skipReasonKey(s.last_skip.reason)) : '' })
        : '0']),
    ]);
  }

  function failureItem(f) {
    const parts = [
      el('strong', {}, [projectLink(f.project)]), ' · ',
      el('a', { href: `${base}/analyses/${f.analysis_id}`, text: t('admin_schedules.analysis_link', { id: f.analysis_id }) }), ' · ',
    ];
    if (f.status === 'queued') {
      parts.push(iconWithLabel('tool_status.verifying', 16, t('admin_schedules.retry_wait', { attempt: f.attempts + 1, after: fmt(f.retry_after), reason: f.title ?? f.code ?? '' })));
    } else {
      parts.push(iconWithLabel('system.error', 16, t('admin_schedules.failed_item', { at: fmt(f.at), attempts: f.attempts, reason: f.title ?? f.code ?? '' })));
      parts.push(' ', el('span', { class: 'muted', text: f.next_run_at ? t('admin_schedules.rerun_at', { at: fmt(f.next_run_at) }) : t('admin_schedules.rerun_none') }));
    }
    return el('li', { dataset: { analysisId: f.analysis_id, status: f.status } }, parts);
  }

  function skipItem(s) {
    return el('li', { dataset: { result: s.result } }, [
      el('strong', {}, [projectLink(s.project)]), ' · ',
      t('admin_schedules.skip_item', { due: fmt(s.due_at), reason: t(skipReasonKey(s.detail ?? s.result)) }),
    ]);
  }

  function renderScheduler(sc) {
    const state = $('[data-scheduler-state]');
    clear(state);
    if (!sc.worker_running) {
      state.appendChild(iconWithLabel('system.warn', 16, t('admin_schedules.worker_off')));
    } else {
      state.textContent = sc.last_tick_at
        ? t('admin_schedules.last_tick', { at: fmt(sc.last_tick_at), seconds: sc.tick_seconds, due: sc.last?.due ?? 0, queued: sc.last?.queued ?? 0, skipped: sc.last?.skipped ?? 0 })
        : t('admin_schedules.no_tick');
    }
    $('[data-scheduler-delayed]').hidden = !sc.delayed;
    $('[data-scheduler-delayed-text]').textContent = sc.delayed ? t('admin_schedules.delayed', { seconds: sc.tick_seconds * 5 }) : '';
  }

  async function load() {
    let d;
    try {
      d = await api.get('/api/v1/admin/schedules');
      $('[data-error]').hidden = true;
    } catch (e) {
      $('[data-error]').textContent = errorText(e);
      $('[data-error]').hidden = false;
      return;
    }
    renderScheduler(d.scheduler);
    const rows = $('[data-rows]');
    clear(rows);
    for (const s of d.schedules) rows.appendChild(row(s));
    $('[data-table]').hidden = d.schedules.length === 0;
    $('[data-empty]').hidden = d.schedules.length > 0;
    $('[data-failures-intro]').textContent = t('admin_schedules.failures_intro', { days: d.failure_days });
    const fl = $('[data-failures]');
    clear(fl);
    for (const f of d.failures) fl.appendChild(failureItem(f));
    $('[data-failures-empty]').hidden = d.failures.length > 0;
    const sl = $('[data-skips]');
    clear(sl);
    for (const s of d.skips) sl.appendChild(skipItem(s));
    $('[data-skips-empty]').hidden = d.skips.length > 0;
  }

  load();
}
