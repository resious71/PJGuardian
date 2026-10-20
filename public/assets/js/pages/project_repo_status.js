// 프로젝트 화면의 소스 저장소 상태(P25): 출처·연결 상태·마지막 가져오기·마지막 분석한 커밋/changelist·
// 다음 예정(P26: 주기 분석 아이콘 + "N일마다 · 다음 예정", 사용 안 함이면 그 글자. admin에게는 연속 실패 경고),
// admin이면 "지금 가져와 분석"(조건이 안 맞으면 끄고 이유를 글로). 누르면 큐에 넣고 분석 탭으로 안내한다(git·p4는 워커가 실행).
// P27: 마지막 확인(원격 리비전만 본 시각·결과). 누른 뒤 그 분석을 잠시 지켜보다가 워커가 "변경 없음"으로 마감하면
// "변경이 없어 분석을 생략했습니다" + 확인 대화상자("그래도 분석할까요?", 취소가 기본 초점·Esc 닫기·초점 복귀) → 확인하면 force 분석.
// 모든 값은 textContent로(사용자 입력·저장소 이름).
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { el, clear } from '../core/dom.js';
import { iconWithLabel } from '../ui/icons.js';
import { formatDateTime, timezoneFromDocument } from '../core/time.js';
import { errorText } from '../ui/form_dialog.js';
import { toast } from '../ui/toast.js';
import { statusKey, fetchButton, safeCommitLink, fetchWatchState, lastCheckText } from '../lib/repo_core.js';
import { confirmDialog } from '../ui/dialog.js';
import { shortRevision } from '../lib/history_core.js';
import { statusLine, failureLine } from '../lib/schedule_core.js';

const box = document.querySelector('[data-repo-status]');
if (box) {
  const api = createApi();
  const id = box.dataset.projectId;
  const admin = box.dataset.admin === '1';
  const tz = timezoneFromDocument();
  const $ = (s) => box.querySelector(s);
  const fetchBtn = $('[data-repo-fetch]');
  const reason = $('[data-repo-fetch-reason]');
  const message = $('[data-repo-message]');
  let view = null;
  let pending = false;

  const set = (sel, nodes) => {
    const n = $(sel);
    clear(n);
    for (const x of [].concat(nodes)) n.appendChild(typeof x === 'string' ? document.createTextNode(x) : x);
  };

  async function busyNow() {
    try {
      const r = await api.get(`/api/v1/projects/${id}/analyses`, { query: { status: 'queued,running', size: 1 } });
      return (r?.page?.total ?? (r?.data ?? []).length) > 0;
    } catch {
      return false;
    }
  }

  async function load() {
    try {
      view = await api.get(`/api/v1/projects/${id}/repo`);
    } catch {
      return;
    }
    if (!view?.configured && !admin) {
      box.hidden = true;
      return;
    }
    box.hidden = false;
    const kind = view?.kind ?? 'none';
    set('[data-repo-source]', [iconWithLabel(kind === 'none' ? 'source.upload' : `source.${kind}`, 16, t(`repo.kind.${kind}`)), view?.label ? ` · ${view.label}` : '']);
    // 상태는 글자 + 아이콘(색만으로 구분하지 않음)
    const tone = { ok: ['tone-ok', 'system.ok'], error: ['tone-error', 'system.error'] }[view?.status] ?? ['tone-neutral', 'ui.info'];
    set('[data-repo-state]', view?.configured ? [el('span', { class: `badge badge--${tone[0]}`, dataset: { repoState: view.status ?? 'unknown' } }, [iconWithLabel(tone[1], 16, t(statusKey(view.status)))]),
      view.status === 'error' && view.fetch?.code ? el('span', { class: 'muted', text: ` ${t(`repo.test_fail.${view.fetch.code}`)}` }) : '']
      : [t('repo.status.none')]);
    set('[data-repo-last-fetch]', view?.last_fetch_at ? formatDateTime(view.last_fetch_at, tz) : '—');
    const last = view?.last_analyzed;
    if (last?.revision) {
      const text = last.kind === 'git' ? last.revision.slice(0, 7) : `@${last.revision}`;
      const a = el('a', { href: `${document.querySelector('meta[name="app-base"]')?.content ?? ''}/analyses/${last.analysis_id}`, class: 'mono', text, title: last.revision });
      set('[data-repo-last-revision]', [a, last.ref ? ` (${last.ref})` : '']);
    } else {
      set('[data-repo-last-revision]', '—');
    }
    set('[data-repo-last-check]', lastCheckText(view, (iso) => formatDateTime(iso, tz)));
    const sch = view?.schedule;
    set('[data-repo-next-run]', sch?.enabled
      ? [iconWithLabel('source.schedule', 16, t('schedule.on_badge')), ` ${statusLine(sch, (iso) => formatDateTime(iso, tz))}`]
      : (sch === null || sch === undefined ? t('repo.next_run_none') : t('schedule.off')));
    const failing = failureLine(sch);
    $('[data-repo-schedule-failing]').hidden = !failing;
    $('[data-repo-schedule-failing-text]').textContent = failing ?? '';
    if (admin && fetchBtn) {
      const st = fetchButton(view, { busy: await busyNow(), pending });
      fetchBtn.disabled = !st.enabled;
      reason.textContent = st.reason ? t(st.reason) : '';
    }
    void safeCommitLink; // 커밋 링크는 분석 상세·이력에서(이 상자는 분석 상세로 이동)
  }

  const WATCH_MS = 2000;
  const WATCH_MAX = 60; // 2분: 워커가 바쁘면 그 뒤에는 분석 탭에서 본다
  const sleep = (ms) => new Promise((r) => { setTimeout(r, ms); });

  async function fetchNow(force) {
    if (fetchBtn.disabled) return;
    pending = true;
    fetchBtn.disabled = true;
    fetchBtn.setAttribute('aria-busy', 'true');
    let queued = null;
    try {
      const r = await api.post(`/api/v1/projects/${id}/repo/fetch`, force ? { force: true } : {});
      queued = r.analysis_id;
      const text = t(force ? 'repo.force_queued' : 'repo.fetch_queued', { id: r.analysis_id });
      message.textContent = force ? text : t('repo.fetch_checking', { id: r.analysis_id });
      toast(text, { type: 'success' });
      document.dispatchEvent(new CustomEvent('pjg:analysis-queued', { detail: { id: r.analysis_id } }));
    } catch (e) {
      message.textContent = errorText(e);
      toast(errorText(e), { type: 'error' });
    } finally {
      pending = false;
      fetchBtn.removeAttribute('aria-busy');
      await load();
    }
    if (queued !== null && !force) await watch(queued);
  }

  /** 워커가 가져오기 전에 변경 여부를 판단한다 — 결과가 나올 때까지 잠시 지켜본다(강제 분석은 판단하지 않으므로 지켜보지 않음) */
  async function watch(aid) {
    for (let i = 0; i < WATCH_MAX; i++) {
      await sleep(WATCH_MS);
      let a;
      try {
        a = await api.get(`/api/v1/analyses/${aid}`);
      } catch {
        return;
      }
      const st = fetchWatchState(a);
      if (st === 'wait') continue;
      if (st === 'analyzing') message.textContent = t('repo.fetch_queued', { id: aid });
      if (st !== 'unchanged') { await load(); return; }
      const text = t('repo.fetch_unchanged', { rev: shortRevision(a.source_kind, a.source_revision) });
      message.textContent = text;
      document.dispatchEvent(new CustomEvent('pjg:analysis-queued', { detail: { id: aid } })); // 분석 탭 목록 갱신(변경 없음 행)
      await load();
      if (fetchBtn.disabled) return; // 그사이 다른 분석이 시작됨
      fetchBtn.focus(); // 대화상자가 닫히면 이 버튼으로 초점이 돌아온다
      const ok = await confirmDialog({ title: t('repo.force_title'), message: t('repo.force_message'), confirmLabel: t('repo.force_confirm'), warn: true });
      if (ok) await fetchNow(true);
      else message.textContent = `${text} ${t('repo.force_cancelled')}`;
      return;
    }
  }

  fetchBtn?.addEventListener('click', () => { fetchNow(false); });

  load();
}
