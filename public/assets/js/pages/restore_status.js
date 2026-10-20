// 복원 진행 독립 상태 페이지(P27B): 로그인 없이 GET /api/v1/restore-status를 폴링해 상태·단계·결과·수동 복구 안내를 그린다.
// 진행 중에는 2초마다, 끝나면 멈춘다(유지보수 플래그가 풀린 뒤). 값은 모두 textContent(비밀·절대 경로 없음).
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { el, clear } from '../core/dom.js';
import { createPoller } from '../core/poll.js';
import { formatDateTime, timezoneFromDocument } from '../core/time.js';
import { iconWithLabel } from '../ui/icons.js';
import { errorText } from '../ui/form_dialog.js';
import { restoreStatusView, stepRows, isFinal, errorMessage } from '../lib/backup_core.js';

const root = document.querySelector('[data-restore-status]');
if (root) {
  const api = createApi({ onUnauthorized: () => {} });
  const tz = timezoneFromDocument();
  const $ = (s) => root.querySelector(s);
  const fmt = (iso) => (iso ? formatDateTime(iso, tz, { seconds: true }) : '—');
  const num = (n) => Number(n ?? 0).toLocaleString('ko-KR');
  const STEP_ICON = { done: 'system.ok', running: 'tool_status.verifying', skipped: 'ui.info', failed: 'system.error' }; // 시작 전(pending)은 아이콘 없이 글자만

  function render(s) {
    $('[data-loading]').hidden = true;
    $('[data-card]').hidden = false;
    $('[data-maintenance]').hidden = !s.maintenance;
    const v = restoreStatusView(s.status);
    const state = $('[data-state]');
    clear(state);
    state.appendChild(el('span', { class: `badge badge--tone-${v.tone}`, dataset: { restoreStatus: s.status ?? 'none' } }, [iconWithLabel(v.icon, 16, t(v.key))]));

    const meta = $('[data-meta]');
    clear(meta);
    const add = (label, value) => { meta.append(el('dt', { text: label }), el('dd', {}, [value])); };
    if (s.bundle) add(t('restore_status.bundle'), el('code', { class: 'mono break', text: s.bundle }));
    if (s.started_at) add(t('restore_status.started'), fmt(s.started_at));
    if (s.finished_at) add(t('restore_status.finished'), fmt(s.finished_at));

    const steps = $('[data-steps]');
    clear(steps);
    const showSteps = s.status && s.status !== 'requested';
    if (showSteps) {
      for (const st of stepRows(s)) {
        steps.appendChild(el('li', { dataset: { step: st.name, stepStatus: st.status }, class: `restore-status__step restore-status__step--${st.status}` }, [
          STEP_ICON[st.status] ? iconWithLabel(STEP_ICON[st.status], 16, t(`restore_status.step.${st.name}`)) : el('span', { text: t(`restore_status.step.${st.name}`) }),
          ' ', el('span', { class: 'muted', text: t(`restore_status.step_status.${st.status}`) }),
        ]));
      }
    }
    steps.hidden = !showSteps;

    const failure = $('[data-failure]');
    failure.hidden = !s.error;
    if (s.error) failure.textContent = errorMessage(s.error.code, s.error.params);

    const r = s.result ?? {};
    const list = $('[data-result-list]');
    clear(list);
    if (s.status === 'succeeded') {
      const items = [];
      if (r.counts) items.push(t('restore_status.result.counts', { users: num(r.counts.users), projects: num(r.counts.projects), analyses: num(r.counts.analyses), defects: num(r.counts.defects) }));
      if (r.pre_restore_backup) items.push(t('restore_status.result.pre_restore', { file: r.pre_restore_backup }));
      items.push(Array.isArray(r.migrated) && r.migrated.length > 0
        ? t('restore_status.result.migrated', { list: r.migrated.join(', ') }) : t('restore_status.result.migrated_none'));
      items.push(t('restore_status.result.sessions', { n: num(r.sessions_cleared) }));
      items.push(t('restore_status.result.config', { n: num(r.config_applied) }));
      items.push(t(r.key_imported ? 'restore_status.result.key_imported' : 'restore_status.result.key_kept'));
      if (Number(r.credentials_unreadable) > 0) items.push(t('restore_status.result.credentials', { n: num(r.credentials_unreadable) }));
      for (const text of items) list.appendChild(el('li', { text }));
    }
    $('[data-result]').hidden = s.status !== 'succeeded';

    const manual = $('[data-manual-list]');
    clear(manual);
    for (const m of s.manual ?? []) {
      manual.appendChild(el('li', {}, [t('restore_status.manual_item', { from: m.move, to: m.to })]));
    }
    $('[data-manual]').hidden = (s.manual ?? []).length === 0;
    $('[data-login]').hidden = !(isFinal(s) && s.status !== 'rollback_failed');
    $('[data-updated]').textContent = t('restore_status.updated', { at: fmt(new Date().toISOString()) });
  }

  const poller = createPoller(async () => {
    try {
      const s = await api.get('/api/v1/restore-status');
      $('[data-error]').hidden = true;
      render(s);
      return !isFinal(s) && (s.status !== null || s.maintenance);
    } catch (e) {
      $('[data-error]').textContent = errorText(e);
      $('[data-error]').hidden = false;
      throw e;
    }
  }, { interval: 2000, maxInterval: 15000 });
  poller.start({ immediate: true });
}
