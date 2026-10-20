// 그룹 상세(명세 3.1): 구성원 추가(검색)·제거, 그룹 관리자 지정·해제, 그룹 안에서 계정 생성.
// 서버가 매 요청마다 권한(admin 또는 이 그룹의 관리자)과 대상 상태를 다시 확인한다. DOM은 el()로만 만든다(innerHTML 금지).
// 변경이 끝나면 화면을 다시 불러 서버가 그린 표를 보여 준다.
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { formatDateTime, timezoneFromDocument } from '../core/time.js';
import { toast } from '../ui/toast.js';
import { confirmDialog, secretDialog } from '../ui/dialog.js';
import { formDialog, field, errorText, userSearchDialog, inlineError, SEARCH_MIN } from '../ui/form_dialog.js';
import { fieldForError } from '../lib/profile_core.js';

const api = createApi();
const tz = timezoneFromDocument();

function reload() {
  globalThis.location.reload();
}

// 구성원 추가: 로그인 ID 또는 이름으로 검색(최소 2자, 활성 사용자, 20건). 결과의 "추가" 버튼으로 바로 추가한다.
function addMember(groupId) {
  return userSearchDialog({
    title: t('groups.add_title'),
    message: t('groups.add_message', { min: SEARCH_MIN }),
    pick: (u) => api.post(`/api/v1/groups/${groupId}/members`, { login_id: u.login_id }),
  });
}

// 그룹 안에서 계정 생성: 역할은 서버가 일반 사용자로 고정하고 이 그룹의 구성원으로 넣는다. 임시 비밀번호는 한 번만 보여 준다.
function createAccount(groupId, hours) {
  return formDialog({
    title: t('groups.create_account_title'),
    message: t('groups.create_account_message', { hours }),
    submitLabel: t('groups.create_account_submit'),
    build(id) {
      const login = field(id, 'login_id', t('users.login_id'), { required: true, maxlength: 100 }, t('users.login_id_help'));
      const name = field(id, 'display_name', t('users.display_name'), { required: true, maxlength: 100 });
      const email = field(id, 'email', t('users.email'), { type: 'email', maxlength: 254 });
      const val = (f) => f.querySelector('input').value;
      // 표시 이름·이메일 오류(사칭 이름 display_name_reserved 등, X06)는 그 칸 바로 아래에, 나머지는 대화상자 아래에
      const errors = { display_name: inlineError(name), email: inlineError(email) };
      return {
        content: [login, name, email],
        focus: login.querySelector('input'),
        async onSubmit() {
          errors.display_name.set(null);
          errors.email.set(null);
          try {
            return await api.post(`/api/v1/groups/${groupId}/accounts`, { login_id: val(login), display_name: val(name), email: val(email) });
          } catch (e) {
            const key = fieldForError(e.code);
            if (!key) throw e;
            errors[key].set(e.message);
            errors[key].input.focus();
            return null;
          }
        },
      };
    },
  });
}

async function onRowAction(root, action, row) {
  const groupId = root.dataset.groupId;
  const userId = row.dataset.userId;
  const who = { login: row.dataset.login, name: row.dataset.name };
  try {
    if (action === 'remove-member') {
      const ok = await confirmDialog({
        title: t('groups.confirm_remove_title', who),
        message: t('groups.confirm_remove'),
        confirmLabel: t('icon.action.remove_member'),
        confirmIcon: 'action.remove_member',
        danger: true,
      });
      if (!ok) return;
      await api.delete(`/api/v1/groups/${groupId}/members/${userId}`);
    } else if (action === 'set-manager' || action === 'unset-manager') {
      const set = action === 'set-manager';
      const ok = await confirmDialog({
        title: t(set ? 'groups.confirm_set_title' : 'groups.confirm_unset_title', who),
        message: t(set ? 'groups.confirm_set' : 'groups.confirm_unset'),
        confirmLabel: t(set ? 'groups.action_set_manager' : 'groups.action_unset_manager'),
        danger: !set,
      });
      if (!ok) return;
      await api.put(`/api/v1/groups/${groupId}/members/${userId}/manager`, { is_manager: set });
    } else {
      return;
    }
    reload();
  } catch (e) {
    toast(errorText(e), { type: 'error' });
  }
}

function init() {
  const root = document.getElementById('group-detail');
  if (!root) return;
  const groupId = root.dataset.groupId;
  root.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('button[data-action]');
    if (!btn || !root.contains(btn)) return;
    const action = btn.dataset.action;
    if (action === 'add-member') {
      if (await addMember(groupId)) reload();
    } else if (action === 'create-account') {
      const r = await createAccount(groupId, Number(root.dataset.tempHours));
      if (r) {
        await secretDialog({
          title: t('groups.account_created_title'),
          message: t('users.temp_issued', { login: r.user.login_id, until: formatDateTime(r.user.temp_password_expires_at, tz) }),
          secret: r.temp_password,
        });
        reload();
      }
    } else {
      const row = btn.closest('tr[data-user-id]');
      if (row) await onRowAction(root, action, row);
    }
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
  init();
}
