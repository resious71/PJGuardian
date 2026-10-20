// 관리자 "알림 보관 기간"(P24, X12부터 설정 관리 "알림" 탭의 구역): app_settings notifications.* 세 값을 GET/PATCH /api/v1/admin/settings로 읽고 저장한다(P21C와 같은 API).
// 범위 밖·정수가 아닌 값은 보내기 전에 막고(서버도 400 invalid_setting_value), 결과는 role=status로 알린다. 바뀐 값만 보낸다.
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { errorText } from '../ui/form_dialog.js';

const api = createApi();
const form = document.querySelector('[data-admin-notif]');

if (form) {
  const inputs = [...form.querySelectorAll('[data-setting]')];
  const save = form.querySelector('[data-save]');
  const status = form.querySelector('[data-status]');
  const error = form.querySelector('[data-error]');
  let current = {};

  const section = form.closest('[data-merge-into]') ?? form.parentElement;
  const help = section.querySelector('[data-help]');
  const dirtyMark = section.querySelector('[data-merge-dirty]');
  // X12: 저장 전 변경 수(지금 값과 다른 칸) — 구역 제목 옆 표시, 설정 관리 탭 머리 건수·바깥 탭 이동 확인에 쓰인다
  function markDirty() {
    const n = inputs.filter((i) => current[i.dataset.setting] && i.value.trim() !== String(current[i.dataset.setting].value)).length;
    section.dataset.dirty = String(n);
    if (dirtyMark) {
      dirtyMark.textContent = n ? t('config_settings.section_dirty', { n }) : '';
      dirtyMark.hidden = n === 0;
    }
    document.dispatchEvent(new CustomEvent('config-merge-dirty'));
  }
  form.addEventListener('input', markDirty);
  const fieldError = (input) => input.closest('.field')?.querySelector('[data-field-error]');
  function show(all) {
    current = all;
    // 지금 값으로 안내(읽은 알림은 읽은 뒤 N일, 안 읽은 알림은 생성 뒤 N일)
    if (help && all['notifications.retain_read_days'] && all['notifications.retain_unread_days']) {
      help.textContent = t('admin_notifications.help_values', { read: all['notifications.retain_read_days'].value, unread: all['notifications.retain_unread_days'].value });
    }
    for (const input of inputs) {
      const s = all[input.dataset.setting];
      if (!s) continue;
      input.value = String(s.value);
      input.min = String(s.min);
      input.max = String(s.max);
      input.disabled = false;
      input.closest('.field').querySelector('[data-range]').textContent = t('admin_notifications.range', { min: s.min, max: s.max, default: s.default });
      fieldError(input).hidden = true;
      input.removeAttribute('aria-invalid');
    }
    save.disabled = false;
    markDirty();
  }
  function validate() {
    let ok = true;
    for (const input of inputs) {
      const s = current[input.dataset.setting];
      const v = input.value.trim();
      const bad = !/^[0-9]{1,9}$/.test(v) || Number(v) < s.min || Number(v) > s.max;
      const err = fieldError(input);
      err.textContent = bad ? t('admin_notifications.invalid', { min: s.min, max: s.max }) : '';
      err.hidden = !bad;
      if (bad) input.setAttribute('aria-invalid', 'true'); else input.removeAttribute('aria-invalid');
      ok = ok && !bad;
    }
    return ok;
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    error.hidden = true;
    status.textContent = '';
    if (!validate()) {
      form.querySelector('[aria-invalid="true"]')?.focus();
      return;
    }
    const body = {};
    for (const input of inputs) {
      const v = Number(input.value.trim());
      if (v !== current[input.dataset.setting].value) body[input.dataset.setting] = v;
    }
    if (Object.keys(body).length === 0) {
      status.textContent = t('admin_notifications.saved');
      return;
    }
    save.disabled = true;
    try {
      show(await api.patch('/api/v1/admin/settings', body));
      status.textContent = t('admin_notifications.saved');
    } catch (err) {
      error.textContent = errorText(err);
      error.hidden = false;
      save.disabled = false;
    }
  });

  api.get('/api/v1/admin/settings').then(show).catch((err) => {
    error.textContent = errorText(err);
    error.hidden = false;
  });
}
