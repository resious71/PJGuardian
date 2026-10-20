// 프로젝트 상세의 "분석" 탭(명세 5.2, 5.4, 8, P19): 분석 목록(최신순)과 취소.
// 진행 중(queued·running)인 분석이 있을 때만 주기적으로 다시 읽고, 화면이 숨겨지면 멈춘다(core/poll.js).
// 취소 버튼은 can_cancel(요청자 본인·admin, queued·running)일 때만 보이고 서버가 다시 검사한다. DOM은 el()/textContent로만.
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { el, clear, setText } from '../core/dom.js';
import { formatDateTime, timezoneFromDocument } from '../core/time.js';
import { createPoller } from '../core/poll.js';
import { renderPagination, pageCount } from '../ui/pagination.js';
import { iconWithLabel } from '../ui/icons.js';
import { badge } from '../ui/badge.js';
import { toast } from '../ui/toast.js';
import { cancelAnalysis } from '../ui/analysis_cancel.js';
import { isActive, durationKey, analysisTitle } from '../lib/analysis_view.js';
import { statusBadgeName, unchangedFold } from '../lib/history_core.js';
import { sourceNode } from '../ui/source_cell.js';

const POLL_MS = 3000;
/** "Run N · 분석 #id" / "Run —(실패) · 분석 #id"(P20R) */
function titleText(a) {
  return analysisTitle(a).map((p) => t(p.key, p.key === 'runs.none_status' ? { status: t(p.params.status) } : p.params)).join(' · ');
}

const root = document.querySelector('[data-analyses]');
if (root) init(root);

function init(section) {
  const api = createApi();
  const tz = timezoneFromDocument();
  const projectId = section.dataset.projectId;
  const size = Number(section.dataset.pageSize) || 50;
  const detailBase = section.dataset.detailBase;
  const count = section.querySelector('[data-count]');
  const table = section.querySelector('[data-table]');
  const rows = section.querySelector('[data-rows]');
  const pager = section.querySelector('[data-pagination]');
  const empty = section.querySelector('[data-empty]');
  const error = section.querySelector('[data-error]');
  const triggerSel = section.querySelector('[data-trigger]');
  const emptyFiltered = section.querySelector('[data-empty-filtered]');
  let page = 1;

  const time = (iso) => (iso ? el('time', { datetime: iso, text: formatDateTime(iso, tz) }) : '');

  function row(a) {
    const status = [badge(statusBadgeName(a))];
    // P27: 같은 리비전 연속 확인(접힘)
    if (a.unchanged) status.push(' ', el('span', { class: 'muted', text: unchangedFold(a, (iso) => formatDateTime(iso, tz)) }));
    if (a.status === 'queued' && a.queue_position) status.push(' ', el('span', { class: 'muted', text: t('analyses.queue_position', { n: a.queue_position }) }));
    if (a.cancel_requested && isActive(a)) status.push(' ', el('span', { class: 'tag', text: t('analyses.cancel_requested') }));
    // P26: 주기 분석 가져오기 재시도 대기 / 최종 실패의 시도 횟수
    if (a.status === 'queued' && a.retry?.after) {
      status.push(' ', el('span', { class: 'tag', title: a.retry.title ?? '', text: t('analyses.retry_wait', { n: a.retry.next_attempt, at: formatDateTime(a.retry.after, tz) }) }));
    } else if (a.status === 'failed' && a.retry?.attempts > 1) {
      status.push(' ', el('span', { class: 'muted', text: t('analyses.retry_attempts', { n: a.retry.attempts }) }));
    }
    const d = durationKey(a.duration_seconds);
    let defects = '';
    if (a.counts) defects = t('analyses.counts_short', a.counts);
    else if (a.failure) defects = a.failure.title;
    // P25: 저장소 출처는 브랜치 @짧은 해시(GitHub 호환 호스트면 커밋 링크) / depot 경로 @changelist
    const source = [sourceNode({ kind: a.source_kind, ref: a.source_ref, revision: a.source_revision, link: a.source_link, file_name: a.upload?.file_name })];
    const actions = a.can_cancel
      ? [el('button', { type: 'button', class: 'btn btn--small btn--danger-outline', on: { click: async () => {
        try {
          const r = await cancelAnalysis(api, a);
          if (r) { toast(t(r.status === 'cancelled' ? 'analyses.cancelled_toast' : 'analyses.cancel_requested_toast'), { type: 'success' }); load(); }
        } catch (e) { toast(e.message, { type: 'error' }); }
      } } }, [iconWithLabel('action.cancel', 16, t('analyses.cancel'))])]
      : [];
    return el('tr', { dataset: { analysisId: a.id, status: a.status } }, [
      el('td', {}, status),
      el('td', {}, [el('a', { href: `${detailBase}${a.id}` }, [titleText(a)])]),
      el('td', { class: 'cell-clip', title: a.upload?.file_name ?? '' }, source),
      el('td', {}, [a.requested_by ? a.requested_by.display_name : iconWithLabel('source.schedule', 16, t('analyses.by_schedule'))]),
      el('td', {}, [time(a.started_at ?? a.queued_at)]),
      el('td', { text: d ? t(d.key, d.params) : '' }),
      el('td', { text: a.tool_version ? `${a.tool} ${a.tool_version}` : a.tool }),
      el('td', { text: defects }),
      el('td', {}, actions),
    ]);
  }

  async function load() {
    try {
      const trigger = triggerSel?.value || undefined;
      const res = await api.get(`/api/v1/projects/${projectId}/analyses`, { query: { page, size, trigger } });
      error.hidden = true;
      const total = res.page.total;
      clear(rows);
      for (const a of res.data) rows.appendChild(row(a));
      setText(count, t('analyses.count', { total }));
      table.hidden = total === 0;
      empty.hidden = total !== 0 || Boolean(trigger);
      emptyFiltered.hidden = total !== 0 || !trigger;
      const pages = pageCount(total, size);
      pager.hidden = pages <= 1;
      if (pages > 1) renderPagination(pager, { page, pages, onChange: (p) => { page = p; load(); } });
      if (res.data.some(isActive)) poller.start({ immediate: false });
      return res.data.some(isActive);
    } catch (e) {
      setText(error, e.message);
      error.hidden = false;
      setText(count, '');
      throw e;
    }
  }

  const poller = createPoller(load, { interval: POLL_MS });
  triggerSel?.addEventListener('change', () => { page = 1; load().catch(() => {}); });
  load().catch(() => {});
}
