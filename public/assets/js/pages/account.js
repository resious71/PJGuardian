// "내 정보"(X05): 본인의 표시 이름·이메일 저장(PATCH /api/v1/users/me). 검증은 서버가 한다.
// 입력 오류는 그 칸 옆에(글자 + 아이콘, aria-invalid), 그 밖의 오류는 폼 아래에. 저장하면 상단 바의 이름도 바로 바꾼다.
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { clear } from '../core/dom.js';
import { icon } from '../ui/icons.js';
import { errorText } from '../ui/form_dialog.js';
import { fieldForError, profileBody } from '../lib/profile_core.js';

const api = createApi();
const FIELDS = { display_name: 'account-name', email: 'account-email' };

function setFieldError(name, message) {
  const input = document.getElementById(FIELDS[name]);
  const box = document.getElementById(`${FIELDS[name]}-error`);
  clear(box);
  if (message) {
    box.append(icon('system.error', 16), ' ', message);
    box.hidden = false;
    input.setAttribute('aria-invalid', 'true');
  } else {
    box.hidden = true;
    input.removeAttribute('aria-invalid');
  }
}

/** 상단 바의 사용자 이름(과 툴팁)을 새 이름으로 */
export function updateTopbarName(name, doc = globalThis.document) {
  const span = doc.querySelector('.topbar__username');
  if (span) span.textContent = name;
  const link = doc.querySelector('.topbar__account');
  if (link) link.title = t('account.link_title', { name, role: link.dataset.roleText ?? '' });
}

function init() {
  const form = document.getElementById('account-form');
  if (!form || form.dataset.readonly === '1') return;
  const status = document.getElementById('account-status');
  const general = document.getElementById('account-error');
  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    status.textContent = '';
    general.hidden = true;
    setFieldError('display_name', null);
    setFieldError('email', null);
    const save = document.getElementById('account-save');
    save.disabled = true;
    try {
      const me = await api.patch('/api/v1/users/me', profileBody(form.elements.display_name.value, form.elements.email.value));
      form.elements.display_name.value = me.display_name;
      form.elements.email.value = me.email ?? '';
      updateTopbarName(me.display_name);
      status.textContent = t('account.saved');
    } catch (e) {
      const name = fieldForError(e.code);
      if (name) {
        setFieldError(name, e.message);
        document.getElementById(FIELDS[name]).focus();
      } else {
        general.textContent = errorText(e);
        general.hidden = false;
      }
    } finally {
      save.disabled = false;
    }
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
  init();
}
