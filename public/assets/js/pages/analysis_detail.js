// 분석 상세(명세 5.2, 5.3, 8, 15.2, P19): 상태(아이콘+텍스트, 대기 순번), 결과 요약(신규·해결·재오픈 링크, 심각도별, 비교 대상),
// 실패 원인·조치(다시 업로드 안내, 재시도 기능 없음), 설정 무시됨·경고 확인 안내, 취소. queued·running이면 주기적으로 갱신(숨기면 멈춤).
// 모든 값은 textContent로만 넣는다(innerHTML 금지).
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { el, clear, setText } from '../core/dom.js';
import { formatDateTime, timezoneFromDocument } from '../core/time.js';
import { createPoller } from '../core/poll.js';
import { icon, iconWithLabel } from '../ui/icons.js';
import { badge } from '../ui/badge.js';
import { toast } from '../ui/toast.js';
import { cancelAnalysis } from '../ui/analysis_cancel.js';
import { isActive, analysisNotices, durationKey, changeLink, baselineKey, analysisTitle } from '../lib/analysis_view.js';
import { sourceNode } from '../ui/source_cell.js';
import { statusBadgeName, unchangedFold, unchangedBaseText } from '../lib/history_core.js';

const POLL_MS = 3000;
const root = document.querySelector('[data-analysis]');
if (root) init(root);

function init(section) {
  const api = createApi();
  const tz = timezoneFromDocument();
  const id = section.dataset.analysisId;
  const base = section.dataset.appBase ?? '';
  const $ = (sel) => section.querySelector(sel);
  const time = (iso) => (iso ? formatDateTime(iso, tz) : '—');
  let current = null;

  /** 제목: 성공 = "Run N · 분석 #id", 그 밖 = "Run —(상태) · 분석 #id"(P20R). 상태가 바뀌면 다시 쓴다 */
  function title(a) {
    const node = $('[data-analysis-title]');
    if (!node) return;
    setText(node, analysisTitle(a).map((p) => t(p.key, p.key === 'runs.none_status' ? { status: t(p.params.status) } : p.params)).join(' · '));
    const link = $('[data-run-defects]');
    if (link) {
      link.hidden = !Number.isInteger(a.run);
      if (!link.hidden) {
        link.setAttribute('href', `${base}/projects/${a.project_id}?run=${a.run}`);
        setText(link, t('analyses.run_defects', { run: a.run }));
      }
    }
  }

  function info(a) {
    const dl = $('[data-info]');
    clear(dl);
    const add = (label, value) => dl.append(el('dt', { text: label }), el('dd', {}, [value]));
    // P25: 저장소 출처(Git 브랜치 @커밋 — GitHub 호환 호스트면 링크, Perforce 경로 @changelist)와 가져온 시각
    const source = sourceNode({ kind: a.source_kind, ref: a.source_ref, revision: a.source_revision, link: a.source_link, file_name: a.upload?.file_name });
    add(t('analyses.col_source'), source);
    if (a.source_revision && a.source_kind !== 'upload') add(t('repo.revision_full'), el('span', { class: 'mono', text: a.source_revision }));
    if (a.fetched_at) add(t('repo.fetched_at'), time(a.fetched_at));
    add(t('analyses.col_requester'), a.requested_by ? `${a.requested_by.display_name} (${a.requested_by.login_id})` : t('analyses.by_schedule'));
    if (a.status === 'queued' && a.queue_position) add(t('analyses.queue'), t('analyses.queue_position', { n: a.queue_position }));
    add(t('analyses.col_requested'), time(a.queued_at));
    add(t('analyses.col_started'), time(a.started_at));
    add(t('analyses.col_finished'), time(a.finished_at));
    const d = durationKey(a.duration_seconds);
    add(t('analyses.col_duration'), d ? t(d.key, d.params) : '—');
    add(t('analyses.col_tool'), a.tool_version ? `${a.tool} ${a.tool_version}` : a.tool);
    if (a.config_source) add(t('analyses.config_source'), t(`analyses.config_source.${a.config_source}`));
    // P27: 변경 없음 — 확인 횟수·마지막 확인(접힌 연속 확인), 강제 분석 표시
    if (a.unchanged) {
      add(t('unchanged.checks'), String(a.unchanged_check_count ?? 1));
      add(t('unchanged.last_checked'), time(a.unchanged_last_checked_at));
    }
    if (a.force_analysis) add(t('unchanged.force_label'), t('unchanged.forced'));
  }

  /** P27: "변경 없음 — 리비전 abcdef0은 Run N(날짜)에서 이미 분석했습니다" + 그 Run(분석 상세)으로 이동 */
  function unchangedNotice(a) {
    const box = $('[data-notices]');
    if (!a.unchanged) return;
    const fmt = (iso) => formatDateTime(iso, tz);
    const text = unchangedBaseText(a.source_kind, a.source_revision, a.unchanged_base, fmt);
    box.appendChild(el('div', { class: 'notice notice--info', dataset: { notice: 'unchanged' } }, [
      el('p', {}, [icon('analysis_status.unchanged', 16), ' ', a.unchanged_base
        ? el('a', { href: `${base}/analyses/${a.unchanged_base.analysis_id}`, dataset: { unchangedBase: '' }, text }) : text]),
      el('p', { class: 'muted', text: `${t('unchanged.detail_body')} ${unchangedFold(a, fmt)}` }),
    ]));
  }

  function notices(a) {
    const box = $('[data-notices]');
    clear(box);
    for (const n of analysisNotices(a)) {
      box.appendChild(el('p', { class: `notice notice--${n.level}`, dataset: { notice: n.key } }, [
        icon(n.level === 'warn' ? 'system.warn' : 'ui.info', 16), ' ', t(n.key, n.params),
      ]));
    }
  }

  function result(a) {
    const box = $('[data-result]');
    box.hidden = a.status !== 'succeeded' || !a.counts;
    if (box.hidden) return;
    const b = baselineKey(a);
    setText($('[data-baseline]'), b ? t(b.key, b.params) : '');
    const counts = $('[data-counts]');
    clear(counts);
    const item = (key, n, change) => el('li', { class: 'result-counts__item', dataset: { count: key } }, [
      el('span', { class: 'result-counts__label', text: t(`analyses.count.${key}`) }),
      change && n > 0
        ? el('a', { class: 'result-counts__n', href: changeLink(base, a.project_id, a.id, change) }, [String(n)])
        : el('span', { class: 'result-counts__n', text: String(n) }),
    ]);
    counts.append(
      item('total', a.counts.total, null),
      item('new', a.counts.new, 'new'),
      item('resolved', a.counts.resolved, 'resolved'),
      item('reopened', a.counts.reopened, 'reopened'),
      item('unchanged', a.counts.unchanged, null),
    );
    const sev = $('[data-severity]');
    clear(sev);
    for (const [k, n] of Object.entries(a.severity_counts ?? {})) {
      sev.appendChild(el('li', {}, [badge(`severity.${k}`), ' ', el('span', { class: 'count-list__n', text: String(n) })]));
    }
  }

  function failure(a) {
    const box = $('[data-failure]');
    box.hidden = !a.failure;
    if (!a.failure) return;
    setText($('[data-failure-title]'), a.failure.title);
    setText($('[data-failure-cause]'), a.failure.cause);
    setText($('[data-failure-action]'), a.failure.action);
    setText($('[data-failure-code]'), a.failure.code);
    // P29: 오류 코드 → 가이드 오류 코드 탭의 그 줄, "자세히 보기" → 사용자 가이드의 해결 절
    $('[data-failure-code-link]').href = `${base}/guide/errors#${encodeURIComponent(a.failure.code)}`;
    const guide = $('[data-failure-guide]');
    guide.hidden = !a.failure.guide_key;
    if (a.failure.guide_key) $('[data-failure-guide-link]').href = `${base}/guide/user#${a.failure.guide_key}`;
  }

  function render(a) {
    current = a;
    const badgeBox = $('[data-status-badge]');
    clear(badgeBox).appendChild(badge(statusBadgeName(a)));
    $('[data-cancel]').hidden = !a.can_cancel;
    title(a);
    info(a);
    notices(a);
    unchangedNotice(a);
    result(a);
    failure(a);
  }

  async function load() {
    try {
      const a = await api.get(`/api/v1/analyses/${id}`);
      $('[data-loading]').hidden = true;
      $('[data-error]').hidden = true;
      render(a);
      return isActive(a);
    } catch (e) {
      $('[data-loading]').hidden = true;
      setText($('[data-error]'), e.message);
      $('[data-error]').hidden = false;
      throw e;
    }
  }

  $('[data-cancel]').addEventListener('click', async () => {
    if (!current) return;
    try {
      const r = await cancelAnalysis(api, current);
      if (!r) return;
      toast(t(r.status === 'cancelled' ? 'analyses.cancelled_toast' : 'analyses.cancel_requested_toast'), { type: 'success' });
      render(r);
      if (isActive(r)) poller.start({ immediate: false });
    } catch (e) {
      toast(e.requestId ? `${e.message} (${t('common.request_id', { id: e.requestId })})` : e.message, { type: 'error' });
    }
  });

  const poller = createPoller(load, { interval: POLL_MS });
  poller.start();
}
