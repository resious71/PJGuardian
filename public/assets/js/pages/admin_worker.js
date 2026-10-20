// 관리자 "워커"(P20A): 상태(실행 중/정지 중/중지/응답 없음), 실행 중 분석·대기 수(읽기 전용, 관리자 취소 없음),
// 안전 정지·재시작 요청(파일 신호), 시작(설정의 고정 명령 실행), 최근 로그(경로·비밀값 가림). 값은 textContent로만 넣는다.
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { el, clear, setText } from '../core/dom.js';
import { formatDateTime, timezoneFromDocument } from '../core/time.js';
import { createPoller } from '../core/poll.js';
import { icon, iconWithLabel } from '../ui/icons.js';
import { toast } from '../ui/toast.js';
import { confirmDialog } from '../ui/dialog.js';
import { errorText } from '../ui/form_dialog.js';
import { workerBadge, workerButtons, stopConfirm, beatAge, elapsed } from '../lib/admin_core.js';

const POLL_MS = 3000;
const START_WATCH_MS = 30000;
const root = document.querySelector('[data-admin-worker]');
if (root) init(root);

function init(section) {
  const api = createApi();
  const tz = timezoneFromDocument();
  const $ = (sel) => section.querySelector(sel);
  const time = (iso) => (iso ? formatDateTime(iso, tz, { seconds: true }) : '—');
  let st = null;
  let busy = false;
  let watchUntil = 0;

  function render(s) {
    st = s;
    const b = workerBadge(s.state);
    const badgeBox = $('[data-state-badge]');
    clear(badgeBox);
    badgeBox.appendChild(el('span', { class: `badge badge--tone-${b.tone}`, dataset: { state: s.state } }, [iconWithLabel(b.icon, 16, t(b.key))]));

    const dl = $('[data-status]');
    clear(dl);
    const add = (label, value) => dl.append(el('dt', { text: label }), el('dd', {}, [value]));
    add(t('admin_worker.pid'), s.pid ? String(s.pid) : '—');
    add(t('admin_worker.started_at'), time(s.started_at));
    const age = beatAge(s.seconds_since_beat, s.stale_after);
    add(t('admin_worker.beat_at'), `${time(s.beat_at)} (${t(age.key, age.params)})`);
    add(t('admin_worker.max_concurrent'), String(s.max_concurrent));
    add(t('admin_worker.last_recovery'), s.last_recovery
      ? t('admin_worker.recovery_summary', { at: time(s.last_recovery.at), analyses: s.last_recovery.analyses ?? 0, uploads: s.last_recovery.uploads ?? 0 })
      : '—');
    add(t('admin_worker.supervised'), t(s.supervised ? 'admin_worker.supervised_on' : 'admin_worker.supervised_off'));
    if (s.node) add(t('admin_tools.node_title'), s.node.ok ? `${s.node.version}` : t(`admin_tools.node_reason.${s.node.reason ?? 'unknown'}`));

    const noWorker = $('[data-no-worker]');
    noWorker.hidden = !s.warning_no_worker;
    setText($('[data-no-worker-text]'), t('admin_worker.no_worker_warning', { queued: s.queued_analyses, uploads: s.pending_uploads }));
    if (s.warning_no_worker) $('[data-help]').open = true;

    const pending = $('[data-stop-pending]');
    pending.hidden = !s.stop_request || s.state === 'stopped';
    if (s.stop_request) {
      setText(pending, t(s.stop_request.kind === 'restart' ? 'admin_worker.restart_pending' : 'admin_worker.stop_pending',
        { n: s.running_analyses.length, at: time(s.stop_request.requested_at) }));
    }

    const btn = workerButtons(s);
    $('[data-stop]').hidden = !btn.stop;
    const restart = $('[data-restart]');
    restart.hidden = !s.supervised || !btn.restart;
    const start = $('[data-start]');
    start.hidden = !btn.startVisible;
    start.disabled = !btn.start;
    const hint = $('[data-start-hint]');
    const hintKey = !s.start?.configured ? 'admin_worker.start_not_configured'
      : (s.start.reason === 'proc_open_disabled' ? 'admin_worker.start_proc_open_disabled'
        : (s.start.reason === 'already_running' ? 'admin_worker.start_already_running'
          : (s.start.reason === 'recently_requested' ? 'admin_worker.start_recent' : null)));
    hint.hidden = hintKey === null || (s.start?.reason === 'already_running' && s.state !== 'stopped');
    if (hintKey) setText(hint, t(hintKey));
    if (!s.supervised && (s.state === 'running' || s.state === 'stopping')) {
      restart.hidden = true;
    }

    const tbody = $('[data-running]');
    clear(tbody);
    $('[data-running-table]').hidden = s.running_analyses.length === 0;
    for (const a of s.running_analyses) {
      const e = elapsed(a.elapsed_seconds);
      tbody.appendChild(el('tr', {}, [
        el('td', {}, [el('a', { href: `${document.querySelector('meta[name="app-base"]')?.content ?? ''}/analyses/${a.id}`, text: `#${a.id}` })]),
        el('td', {}, [el('a', { href: `${document.querySelector('meta[name="app-base"]')?.content ?? ''}/projects/${a.project_id}`, text: a.project_name })]),
        el('td', { text: a.tool }),
        el('td', { text: time(a.started_at) }),
        el('td', { text: t(e.key, e.params) }),
      ]));
    }
    setText($('[data-queue-summary]'), t('admin_worker.queue_summary', { running: s.running_analyses.length, queued: s.queued_analyses, uploads: s.pending_uploads, max: s.max_concurrent }));
  }

  async function load() {
    try {
      const s = await api.get('/api/v1/admin/worker');
      $('[data-loading]').hidden = true;
      $('[data-error]').hidden = true;
      render(s);
      if (watchUntil > 0) {
        const res = $('[data-start-result]');
        if (s.state === 'running') {
          watchUntil = 0;
          res.className = 'notice notice--info';
          setText(res, t('admin_worker.start_confirmed'));
        } else if (Date.now() > watchUntil) {
          watchUntil = 0;
          res.className = 'notice notice--error';
          setText(res, t('admin_worker.start_not_confirmed', { code: s.start?.last?.exit_code ?? '-' }));
        }
      }
      return true;
    } catch (e) {
      const box = $('[data-error]');
      setText(box, errorText(e));
      box.hidden = false;
      throw e;
    }
  }

  async function requestStop(kind) {
    if (busy || !st) return;
    const c = stopConfirm(st, kind);
    const ok = await confirmDialog({
      title: t(kind === 'restart' ? 'admin_worker.restart' : 'admin_worker.stop'),
      message: t(c.key, c.params), danger: true, confirmIcon: kind === 'restart' ? 'action.restart' : 'action.stop',
      confirmLabel: t(kind === 'restart' ? 'admin_worker.restart' : 'admin_worker.stop'),
    });
    if (!ok) return;
    busy = true;
    try {
      render(await api.post(`/api/v1/admin/worker/${kind}`));
      toast(t(kind === 'restart' ? 'admin_worker.restart_sent' : 'admin_worker.stop_sent'), { type: 'success' });
    } catch (e) {
      toast(errorText(e), { type: 'error' });
    } finally {
      busy = false;
    }
  }

  async function start() {
    if (busy) return;
    const ok = await confirmDialog({ title: t('admin_worker.start'), message: t('admin_worker.confirm_start'), confirmIcon: 'action.start', confirmLabel: t('admin_worker.start') });
    if (!ok) return;
    busy = true;
    const res = $('[data-start-result]');
    try {
      const r = await api.post('/api/v1/admin/worker/start');
      res.hidden = false;
      res.className = `notice notice--${r.ok ? 'info' : 'error'}`;
      clear(res);
      res.append(icon(r.ok ? 'tool_status.verifying' : 'system.error', 16), ' ',
        t(r.ok ? 'admin_worker.start_sent' : 'admin_worker.start_failed', { code: r.exit_code ?? '-', reason: r.reason ?? '' }),
        r.output ? el('code', { class: 'mono', text: ` ${r.output}` }) : '');
      // 하트비트를 최대 30초 확인해 "시작됨/시작 확인 실패"를 표시
      watchUntil = r.ok ? Date.now() + START_WATCH_MS : 0;
    } catch (e) {
      toast(errorText(e), { type: 'error' });
    } finally {
      busy = false;
    }
  }

  async function loadLogs() {
    const box = $('[data-log]');
    try {
      const r = await api.get('/api/v1/admin/logs', { query: { scope: $('[data-log-scope]').value, lines: section.dataset.logLines } });
      setText(box, r.lines.join('\n'));
      setText($('[data-log-info]'), r.file ? t('admin_worker.log_info', { file: r.file, n: r.lines.length }) : t('admin_worker.log_none'));
      box.scrollTop = box.scrollHeight;
    } catch (e) {
      setText($('[data-log-info]'), errorText(e));
    }
  }

  $('[data-stop]').addEventListener('click', () => requestStop('stop'));
  $('[data-restart]').addEventListener('click', () => requestStop('restart'));
  $('[data-start]').addEventListener('click', start);
  $('[data-log-refresh]').addEventListener('click', loadLogs);
  $('[data-log-scope]').addEventListener('change', loadLogs);
  load().catch(() => {});
  loadLogs();
  createPoller(async () => (busy ? true : load()), { interval: POLL_MS }).start({ immediate: false });
}
