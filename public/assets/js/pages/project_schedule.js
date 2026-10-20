// 프로젝트 편집 화면의 "주기 분석"(P26, admin): 사용 여부·간격(일, "1주일" 빠른 선택)·실행 시각(KST HH:MM)·같은 시각 예약 수 안내·
// 다음 실행 예정·마지막 결과·연속 실패. 저장소 연결이 정상 설정이 아니면 입력을 끄고 이유를 보인다.
// 저장은 개정 번호로 동시 수정 409. 소스 저장소 영역이 연결을 저장·해제하면(pjg:repo-changed) 다시 읽는다. 값은 모두 textContent.
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { formatDateTime, timezoneFromDocument } from '../core/time.js';
import { errorText } from '../ui/form_dialog.js';
import { toast } from '../ui/toast.js';
import { RECOMMENDED_DAYS, validate, buildBody, sameTimeNote, blockerKey, warningKeys, lastResultKey, normalizeRunTime } from '../lib/schedule_core.js';

const root = document.querySelector('[data-schedule-form]');
if (root) {
  const api = createApi();
  const id = root.dataset.projectId;
  const tz = timezoneFromDocument();
  const form = root.querySelector('[data-schedule-fields]');
  const $ = (s) => root.querySelector(s);
  const enabled = $('[data-schedule-enabled]');
  const days = form.elements.namedItem('interval_days');
  const time = form.elements.namedItem('run_time');
  const err = $('[data-schedule-error]');
  const result = $('[data-schedule-result]');
  const same = $('[data-schedule-same-time]');
  let view = null;

  const fmt = (iso) => formatDateTime(iso, tz);

  function showFieldErrors(errors) {
    for (const node of root.querySelectorAll('[data-error-for]')) {
      const key = errors[node.dataset.errorFor];
      node.textContent = key ? t(key) : '';
      node.hidden = !key;
      const input = form.elements.namedItem(node.dataset.errorFor);
      if (key) input?.setAttribute('aria-invalid', 'true'); else input?.removeAttribute('aria-invalid');
    }
  }

  function syncSameTime() {
    // 켤 수 없는 상태(연결 없음 등)면 예약 안내는 보이지 않는다
    const note = view && !(view.blocker && !view.enabled) ? sameTimeNote(view.time_counts, time.value) : null;
    same.textContent = note ?? '';
    same.hidden = !note;
  }

  function fill(v) {
    view = v;
    const blocked = Boolean(v.blocker) && !v.enabled;
    $('[data-schedule-blocker]').hidden = !v.blocker;
    $('[data-schedule-blocker-text]').textContent = v.blocker ? t(blockerKey(v.blocker)) : '';
    enabled.checked = Boolean(v.enabled);
    // 연결이 정상 설정이 아니면 켤 수 없다(이미 켜져 있으면 끌 수는 있게 둔다)
    enabled.disabled = blocked;
    days.value = String(v.interval_days ?? RECOMMENDED_DAYS);
    days.min = String(v.min_days ?? 1);
    days.max = String(v.max_days ?? 365);
    time.value = v.run_time ?? '03:00';
    for (const n of [days, time, $('[data-schedule-week]'), $('[data-schedule-save]')]) n.disabled = blocked;
    $('[data-schedule-next]').textContent = v.enabled && v.next_run_at ? fmt(v.next_run_at) : t('schedule.next_none');
    $('[data-schedule-last]').textContent = v.last_result
      ? `${t(lastResultKey(v.last_result))} (${fmt(v.last_result_at)})${v.last_result === 'failed' && v.last_error_title ? ` — ${v.last_error_title}` : ''}`
      : t(lastResultKey(null));
    $('[data-schedule-failures]').textContent = v.consecutive_failures > 0
      ? t('schedule.failures_n', { n: v.consecutive_failures.toLocaleString('ko-KR') }) : t('schedule.failures_none');
    showFieldErrors({});
    syncSameTime();
  }

  async function load() {
    try {
      fill(await api.get(`/api/v1/projects/${id}/schedule`));
      $('[data-schedule-unavailable]').hidden = true;
    } catch (e) {
      if (e?.code === 'schedule_unavailable') {
        $('[data-schedule-unavailable]').hidden = false;
        form.hidden = true;
        return;
      }
      err.textContent = errorText(e);
      err.hidden = false;
    }
  }

  $('[data-schedule-week]').addEventListener('click', () => {
    days.value = String(RECOMMENDED_DAYS);
    days.focus();
  });
  time.addEventListener('input', syncSameTime);
  time.addEventListener('blur', () => {
    time.value = normalizeRunTime(time.value);
    syncSameTime();
  });

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    err.hidden = true;
    result.textContent = '';
    time.value = normalizeRunTime(time.value);
    const values = { enabled: enabled.checked, interval_days: days.value, run_time: time.value };
    const check = validate(values, { min: Number(days.min) || 1, max: Number(days.max) || 365 });
    showFieldErrors(check.errors);
    if (!check.ok) {
      form.querySelector('[aria-invalid="true"]')?.focus();
      return;
    }
    const save = $('[data-schedule-save]');
    save.disabled = true;
    try {
      const v = await api.put(`/api/v1/projects/${id}/schedule`, buildBody(values, view?.revision ?? undefined));
      fill(v);
      const warns = warningKeys(v.warnings).map((k) => t(k));
      const msg = [t(v.enabled ? 'schedule.saved_on' : 'schedule.saved_off', { next: v.next_run_at ? fmt(v.next_run_at) : '—' }), ...warns].join(' ');
      result.textContent = msg;
      result.dataset.ok = warns.length ? 'warn' : '1';
      toast(t(v.enabled ? 'schedule.saved_toast_on' : 'schedule.saved_toast_off'), { type: 'success' });
    } catch (e) {
      err.textContent = errorText(e);
      err.hidden = false;
      if (e?.code === 'schedule_conflict' || e?.code === 'schedule_connection_required') await load();
    } finally {
      save.disabled = Boolean(view?.blocker) && !view?.enabled;
    }
  });

  document.addEventListener('pjg:repo-changed', () => load());
  load();
}
