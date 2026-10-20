// 시스템 모니터링(P30, admin만, 읽기 전용): /api/v1/admin/monitoring의 판정(서버 SystemHealth — /health·배너와 같음)을 카드로 그린다.
// 자동 갱신은 탭이 보일 때만(core/poll), 갱신 중에도 포커스·스크롤을 지킨다(카드 내용만 바꾸고, 포커스가 있던 요소는 data-focus-key로 다시 찾음).
// 값은 textContent로만 넣고, 상태는 아이콘 + 글자로 보인다(색만으로 구분하지 않음).
// X18: 모든 카드는 공용 틀(_monitor_card.php) — 머리 상태 배지(정상·주의·오류·확인 불가·정보, 한 사전 badgeView) → 핵심 값 줄 → 세부(라벨-값 2열 또는 같은 표 스타일,
//   목록은 최대 MAX_ROWS행 + "외 N건") → 바닥 줄(링크는 서버가 그림, 확인 시각). 카드별 함수는 {badge?, value, detail}만 돌려준다.
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { el, clear } from '../core/dom.js';
import { formatDateTime, timezoneFromDocument } from '../core/time.js';
import { createPoller } from '../core/poll.js';
import { icon, iconWithLabel } from '../ui/icons.js';
import { errorText } from '../ui/form_dialog.js';
import { elapsed } from '../lib/admin_core.js';
import { formatBytes } from '../lib/upload_core.js';
import { levelIcon, statusLine, alertText, unavailableReason, checkView, cardLevel, focusKey, badgeView, limitRows, MAX_ROWS } from '../lib/monitoring_core.js';

const root = document.querySelector('[data-monitoring]');
if (root) init(root);

function init(section) {
  const api = createApi();
  const tz = timezoneFromDocument();
  const base = document.querySelector('meta[name="app-base"]')?.content ?? '';
  const $ = (sel) => section.querySelector(sel);
  const time = (iso) => (iso ? formatDateTime(iso, tz) : t('monitoring.none'));
  const dur = (s) => { const e = elapsed(s); return t(e.key, e.params); };
  const refreshMs = Math.max(10, Number(section.dataset.refresh) || 30) * 1000;
  let loading = false;

  // ---- 공용 부품 ----
  const text = (v) => (v instanceof Node ? v : document.createTextNode(String(v)));
  /** 핵심 값 줄: 큰 값 + 작은 단위 */
  const value = (main, unit = '') => [el('span', { class: 'mon-card__num', text: String(main) }), ...(unit ? [el('span', { class: 'mon-card__unit', text: unit })] : [])];
  /** 빈 상태·확인 불가(같은 회색 보조 글자, 같은 자리) */
  const empty = (msg, data = {}) => el('p', { class: 'mon-card__empty', dataset: data, text: msg });
  /** 세부 라벨-값 2열(긴 라벨은 말줄임 + 툴팁) */
  const pairs = (rows) => el('dl', { class: 'mon-card__pairs' }, rows.flatMap(([k, v]) => [el('dt', { title: k, text: k }), el('dd', {}, [text(v)])]));
  /** 세부 표(같은 스타일): caption·머리글 연결, 최대 MAX_ROWS행 + "외 N건" */
  const table = (caption, heads, items, cells, { captionVisible = false, emptyText = '' } = {}) => {
    if (!items.length) {
      return [captionVisible ? el('h3', { class: 'mon-card__sub', text: caption }) : null, empty(emptyText)].filter(Boolean);
    }
    const { shown, more } = limitRows(items, MAX_ROWS);
    const out = [el('div', { class: 'mon-card__table-wrap' }, [el('table', { class: 'mon-table' }, [
      el('caption', { class: captionVisible ? 'mon-table__caption' : 'sr-only', text: caption }),
      el('thead', {}, [el('tr', {}, heads.map((h) => el('th', { scope: 'col', title: h, text: h })))]),
      el('tbody', {}, shown.map((it) => el('tr', {}, cells(it).map((c, i) => (i === 0 ? el('th', { scope: 'row' }, [text(c)]) : el('td', {}, [text(c)])))))),
    ])])];
    if (more > 0) out.push(el('p', { class: 'mon-card__more', dataset: { more: String(more) }, text: t('monitoring.more', { n: more }) }));
    return out;
  };
  const link = (href, label, key) => el('a', { href: `${base}${href}`, text: label, dataset: { focusKey: key } });
  const check = (v) => { const c = checkView(v); return iconWithLabel(c.icon, 16, t(c.key)); };

  // ---- 카드별: {value, detail, badge?} ----
  const render = {
    worker(c) {
      return {
        value: [el('span', { class: 'mon-card__num mon-card__num--text', text: t(`admin_worker.state.${c.state}`) })],
        detail: [pairs([
          [t('monitoring.worker.last_beat'), c.beat_at ? `${time(c.beat_at)} (${dur(c.seconds_since_beat)})` : t('monitoring.none')],
          [t('monitoring.worker.children'), String(c.children)],
        ])],
      };
    },
    queue(c, alerts) {
      const stalled = alerts.some((a) => a.code === 'queue_stalled');
      return {
        value: value(c.waiting, t('monitoring.unit.queue')),
        detail: [pairs([
          [t('monitoring.queue.oldest'), c.oldest_wait_seconds === null ? t('monitoring.none') : dur(c.oldest_wait_seconds)],
          [t('monitoring.queue.judge'), stalled ? t('monitoring.queue.stalled', { min: Math.round(c.stall_after_seconds / 60) }) : t('monitoring.queue.not_stalled')],
          [t('monitoring.queue.running'), `${c.running.length} / ${c.max_concurrent}`],
        ]), ...table(t('monitoring.queue.running_list'), [t('monitoring.col.project'), t('monitoring.col.run'), t('monitoring.col.elapsed')], c.running,
          (r) => [link(`/analyses/${r.analysis_id}`, r.project.name, `run-${r.analysis_id}`), r.run_number ? `Run ${r.run_number}` : '—', dur(r.elapsed_seconds)],
          { emptyText: t('monitoring.queue.none_running') })],
      };
    },
    disk(c) {
      return {
        value: value(c.used_percent, t('monitoring.unit.disk')),
        detail: [pairs([
          [t('monitoring.disk.free'), `${formatBytes(c.free_bytes)} / ${formatBytes(c.total_bytes)}`],
          [t('monitoring.disk.thresholds'), t('monitoring.disk.thresholds_value', { warn: c.warn_percent, block: c.block_percent })],
        ])],
      };
    },
    backup(c, alerts, data) {
      const stale = alerts.some((a) => a.code === 'backup_stale');
      const result = c.last_result === 'failed' ? t('monitoring.backup.result.failed', { code: c.last_failure.code })
        : (c.last_result === 'succeeded' ? t(stale ? 'monitoring.backup.stale' : 'monitoring.backup.result.succeeded', { hours: c.stale_hours }) : t('monitoring.backup.result.none'));
      const ago = c.last_success_at ? Math.max(0, Math.round((Date.parse(data.checked_at) - Date.parse(c.last_success_at)) / 1000)) : null;
      const detail = [pairs([
        [t('monitoring.backup.auto'), t(c.enabled ? 'monitoring.on' : 'monitoring.off')],
        [t('monitoring.backup.last_success'), time(c.last_success_at)],
        [t('monitoring.backup.last_result'), result],
        [t('monitoring.backup.next'), c.next_at ? time(c.next_at) : t('monitoring.none')],
      ])];
      if (c.same_disk) detail.push(el('p', { class: 'mon-card__note', dataset: { sameDisk: '' } }, [iconWithLabel('system.warn', 16, t('monitoring.backup.same_disk'))]));
      return {
        value: ago === null ? [empty(t('monitoring.backup.result.none'))] : value(t('monitoring.value.ago', { time: dur(ago) }), t('monitoring.unit.backup')),
        detail,
      };
    },
    tools(c) {
      const analysis = c.items.filter((it) => !it.read_only);
      const healthy = analysis.filter((it) => it.healthy).length;
      return {
        value: value(healthy, t('monitoring.unit.tools', { n: analysis.length })),
        detail: table(t('monitoring.card.tools'), [t('monitoring.col.tool'), t('monitoring.tools.version'), t('monitoring.col.verify')],
          c.items, (it) => {
            const ver = el('span', { class: 'mon-table__ver', dataset: { tool: it.tool, healthy: String(it.healthy) } });
            if (it.version) ver.append(it.version);
            else ver.append(it.read_only ? t('monitoring.tools.none') : iconWithLabel('system.error', 16, t('monitoring.tools.none')));
            if (it.pinned) ver.append(' ', el('span', { class: 'badge badge--tone-info', text: t('monitoring.tools.pinned') }));
            if (it.blocked.length) ver.append(' ', el('span', { class: 'badge badge--tone-warn', text: t('monitoring.tools.blocked', { list: it.blocked.join(', ') }) }));
            if (it.read_only) return [it.tool, ver, el('span', { class: 'muted', text: t('monitoring.tools.read_only') })];
            // 체크섬·스모크(아이콘 + 글자)와 마지막 검증 시각을 한 칸에(좁은 카드에서 열이 꺾이지 않게)
            return [it.tool, ver, el('span', { class: 'mon-table__checks' }, [
              el('span', {}, [`${t('monitoring.tools.checksum')} `, check(it.checksum_ok)]),
              el('span', {}, [`${t('monitoring.tools.smoke')} `, check(it.smoke_ok)]),
              el('span', { class: 'muted', text: `${t('monitoring.tools.checked_at')} ${time(it.checked_at)}` }),
            ])];
          }, { emptyText: t('monitoring.tools.none') }),
      };
    },
    cert(c) {
      const over = c.days_left < 0;
      return {
        value: value(Math.abs(c.days_left), t(over ? 'monitoring.unit.cert_over' : 'monitoring.unit.cert', { n: Math.abs(c.days_left) })),
        detail: [pairs([
          [t('monitoring.cert.not_after'), time(c.not_after)],
          [t('monitoring.cert.warn_rule'), t('monitoring.cert.warn_days', { n: c.warn_days })],
        ])],
      };
    },
    schedules(c) {
      return {
        value: value(c.upcoming.length, t('monitoring.unit.schedules')),
        detail: [
          ...table(t('monitoring.schedules.upcoming'), [t('monitoring.col.project'), t('monitoring.col.next_run')], c.upcoming,
            (s) => [link(`/projects/${s.project.id}`, s.project.name, `sch-${s.project.id}`), time(s.next_run_at)], { captionVisible: true, emptyText: t('monitoring.schedules.none') }),
          ...table(t('monitoring.schedules.failing'), [t('monitoring.col.project'), t('monitoring.col.failures'), t('monitoring.col.code'), t('monitoring.col.retry')], c.failing,
            (s) => [s.project.name, iconWithLabel(s.consecutive_failures >= c.alert_after ? 'system.warn' : 'ui.info', 16, String(s.consecutive_failures)), s.last_error?.code ?? '—',
              s.retry_next ? t('monitoring.schedules.retry_next') : '—'], { captionVisible: true, emptyText: t('monitoring.schedules.no_failures') }),
        ],
      };
    },
    failures(c) {
      return {
        value: value(c.items.length, t('monitoring.unit.failures', { n: c.limit })),
        detail: table(t('monitoring.card.failures'), [t('monitoring.col.project'), t('monitoring.col.run'), t('monitoring.col.code'), t('monitoring.col.time')], c.items,
          (f) => [link(`/analyses/${f.analysis_id}`, f.project.name, `fail-${f.analysis_id}`), f.run_number ? `Run ${f.run_number}` : `#${f.analysis_id}`, f.code, time(f.at)],
          { emptyText: t('monitoring.failures.none') }),
      };
    },
    app(c) {
      return {
        badge: 'info',
        value: [el('span', { class: 'mon-card__num mon-card__num--text', text: c.version }), el('span', { class: 'mon-card__unit', text: t('monitoring.unit.app') })],
        detail: [pairs([
          [t('monitoring.app.schema'), c.schema || t('monitoring.none')],
          [t('monitoring.app.php'), c.php], [t('monitoring.app.sqlite'), c.sqlite], [t('monitoring.app.os'), c.os],
          // P30B 관리자 이메일(정보 한 줄 — 판정에는 넣지 않음): 꺼짐·설정 필요·켜짐, 설정 관리 관리자 이메일 탭으로
          ...(c.alert_mail ? [[t('monitoring.app.alert_mail'), link('/admin/config-settings#admin_mail', t(`monitoring.app.alert_mail.${c.alert_mail}`), 'alert-mail')]] : []),
        ])],
      };
    },
  };

  function fill(sec, name, card, data) {
    const level = cardLevel(name, card, data.alerts);
    sec.dataset.level = level;
    const parts = { value: [], detail: [], badge: null };
    const reason = unavailableReason(card);
    if (reason) {
      parts.value = [empty(t('monitoring.unavailable', { reason: t(reason) }), { unavailable: card.reason })];
      if (name === 'cert') parts.detail = [empty(t('monitoring.cert.how'))];
    } else {
      try {
        Object.assign(parts, render[name](card, data.alerts, data));
      } catch {
        sec.dataset.level = 'unknown';
        parts.value = [empty(t('monitoring.unavailable', { reason: t('monitoring.reason.error') }), { unavailable: 'error' })];
        parts.detail = [];
      }
    }
    const b = badgeView(parts.badge ?? sec.dataset.level);
    const badge = sec.querySelector('[data-badge]');
    badge.dataset.state = b.state;
    sec.dataset.badge = b.state;
    clear(badge).append(iconWithLabel(b.icon, 16, t(b.key)));
    const mark = sec.querySelector('[data-state-icon]');
    mark.dataset.state = b.state;
    clear(mark).append(icon(b.icon, 20));
    clear(sec.querySelector('[data-value]')).append(...parts.value);
    clear(sec.querySelector('[data-detail]')).append(...parts.detail);
    const checked = sec.querySelector('[data-checked]');
    checked.textContent = t('monitoring.checked', { time: formatDateTime(data.checked_at, tz, { seconds: true }) });
  }

  function show(data) {
    // 포커스·스크롤 유지: 바꾸기 전 포커스 키와 스크롤 위치를 기억
    const key = focusKey(document.activeElement, section);
    const y = window.scrollY;
    const sl = statusLine(data.status, data.alerts);
    clear($('[data-status]')).append(iconWithLabel(sl.icon, 20, t(sl.key, sl.params)));
    $('[data-status]').dataset.status = data.status;
    $('[data-updated]').textContent = t('monitoring.updated', { time: formatDateTime(data.checked_at, tz, { seconds: true }) });
    const list = $('[data-alerts]');
    clear(list);
    for (const a of data.alerts) {
      const txt = alertText(a);
      list.append(el('li', { class: `monitoring__alert monitoring__alert--${a.level}`, dataset: { alert: a.code, level: a.level } }, [iconWithLabel(levelIcon(a.level), 16, t(txt.key, txt.params))]));
    }
    list.hidden = data.alerts.length === 0;
    for (const [name, card] of Object.entries(data.cards)) {
      const sec = section.querySelector(`[data-card="${name}"]`);
      if (sec) fill(sec, name, card, data);
    }
    if (key) section.querySelector(`[data-focus-key="${CSS.escape(key)}"]`)?.focus({ preventScroll: true });
    window.scrollTo({ top: y });
  }

  async function load(fresh = false) {
    if (loading) return true;
    loading = true;
    const btn = $('[data-refresh-now]');
    btn.setAttribute('aria-busy', 'true');
    try {
      const data = await api.get('/api/v1/admin/monitoring', { query: fresh ? { refresh: '1' } : {} });
      $('[data-error]').hidden = true;
      show(data);
    } catch (e) {
      $('[data-error]').textContent = errorText(e);
      $('[data-error]').hidden = false;
      throw e;
    } finally {
      $('[data-loading]').hidden = true;
      btn.removeAttribute('aria-busy');
      loading = false;
    }
    return true;
  }

  $('[data-refresh-now]').addEventListener('click', () => { $('[data-updated]').textContent = t('monitoring.refreshing'); load(true).catch(() => {}); });
  load().catch(() => {});
  createPoller(() => load(), { interval: refreshMs, maxInterval: refreshMs * 4 }).start({ immediate: false });
}
