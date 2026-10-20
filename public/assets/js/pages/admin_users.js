// 사용자 관리 화면(관리자 전용, 명세 13). 목록·생성·정보 수정(X05)·역할 변경·비활성화/활성화·임시 비밀번호 발급·잠금 해제.
// P28B: 인증 방식 열, 승인 대기(외부 인증 첫 로그인) 보기·승인(역할 선택)·거절·삭제, 인증 방식 변경(외부 방식이 켜져 있을 때만).
// 서버가 권한을 항상 검사한다(이 화면을 숨겨도 API는 403). DOM은 el()로만 만든다(innerHTML 금지).
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { el, clear } from '../core/dom.js';
import { formatDateTime, timezoneFromDocument } from '../core/time.js';
import { toast } from '../ui/toast.js';
import { confirmDialog, secretDialog, passwordDialog } from '../ui/dialog.js';
import { confirmUnlock } from '../lib/user_actions.js';
import { renderPagination, pageCount } from '../ui/pagination.js';
import { formDialog, field } from '../ui/form_dialog.js';
import { icon, iconWithLabel } from '../ui/icons.js';
import { fieldForError, profileBody } from '../lib/profile_core.js';

const api = createApi();
const tz = timezoneFromDocument();
const PAGE_SIZE = 50;
// approval: ''(계정) | pending | rejected — 알림 링크(?approval=pending)로 바로 열 수 있다
const state = { page: 1, q: '', role: '', status: '', approval: ['pending', 'rejected'].includes(new URLSearchParams(location.search).get('approval')) ? new URLSearchParams(location.search).get('approval') : '' };
let authTypes = ['local'];

/** 인증 방식 이름(account.auth_<방식>) */
function authLabel(type) {
  const k = `account.auth_${type}`;
  const v = t(k);
  return v === k ? type : v;
}

function roleLabel(role) {
  return t(role === 'admin' ? 'layout.role_admin' : 'layout.role_user');
}

function accountState(u) {
  const parts = [];
  if (u.locked) parts.push(t('users.state_locked', { until: formatDateTime(u.locked_until, tz) }));
  if (u.must_change_password) {
    parts.push(u.temp_password_expires_at
      ? t('users.state_temp', { until: formatDateTime(u.temp_password_expires_at, tz) })
      : t('users.state_must_change'));
  }
  if (u.approval_status && u.approval_status !== 'approved') parts.push(t('users.state_requested', { at: formatDateTime(u.created_at, tz) }));
  else if (u.auth_type !== 'local' && !u.external_linked) parts.push(t('users.state_not_linked'));
  return parts.length ? parts.join(' · ') : t('users.state_normal');
}

async function run(action, successKey) {
  try {
    const result = await action();
    if (successKey) toast(t(successKey), { type: 'success' });
    await load();
    return result;
  } catch (e) {
    toast(e.requestId ? `${e.message} (${t('common.request_id', { id: e.requestId })})` : e.message, { type: 'error' });
    return null;
  }
}

async function changeRole(u) {
  const to = u.role === 'admin' ? 'user' : 'admin';
  const ok = await confirmDialog({
    title: t('users.confirm_role_title', { name: u.display_name }),
    message: t(to === 'admin' ? 'users.confirm_promote' : 'users.confirm_demote'),
    confirmLabel: t('users.action_role_to', { role: roleLabel(to) }),
    danger: to === 'user',
  });
  if (ok) await run(() => api.put(`/api/v1/users/${u.id}/role`, { role: to }), 'users.done_role');
}

async function toggleStatus(u) {
  const to = u.status === 'active' ? 'inactive' : 'active';
  if (to === 'inactive') {
    const ok = await confirmDialog({
      title: t('users.confirm_deactivate_title', { name: u.display_name }),
      message: t('users.confirm_deactivate'),
      confirmLabel: t('users.action_deactivate'),
      danger: true,
    });
    if (!ok) return;
  }
  await run(() => api.put(`/api/v1/users/${u.id}/status`, { status: to }), to === 'inactive' ? 'users.done_deactivate' : 'users.done_activate');
}

async function issueTemp(u) {
  const ok = await confirmDialog({
    title: t('users.confirm_temp_title', { name: u.display_name }),
    message: t('users.confirm_temp'),
    confirmLabel: t('users.action_temp'),
    danger: true,
  });
  if (!ok) return;
  const r = await run(() => api.post(`/api/v1/users/${u.id}/temp-password`, {}), null);
  if (r) {
    await secretDialog({
      title: t('users.temp_issued_title'),
      message: t('users.temp_issued', { login: u.login_id, until: formatDateTime(r.expires_at, tz) }),
      secret: r.temp_password,
    });
  }
}

// 관리자가 비밀번호를 정한다(명세 13장·19.10). 대상은 다음 로그인 때 알림만 받고 변경은 강제되지 않는다.
async function setPassword(u) {
  const table = document.getElementById('users-table');
  const changed = await passwordDialog({
    title: t('users.set_password_title', { name: u.display_name }),
    message: t('users.set_password_message'),
    policyText: table.dataset.policy,
    submitLabel: t('users.action_set_password'),
    onSubmit: (password) => api.put(`/api/v1/users/${u.id}/password`, { password }),
  });
  if (changed) {
    toast(t('users.done_set_password', { login: u.login_id }), { type: 'success' });
    await load();
  }
}

function actionButton(labelKey, onClick, extraClass = '') {
  return el('button', { type: 'button', class: `btn btn--small ${extraClass}`.trim(), on: { click: onClick } }, [t(labelKey)]);
}

/**
 * 정보 수정(X05): 표시 이름·이메일. 서버(UserService::updateProfile)가 검증하고, 입력 오류는 그 칸 옆에 보인다.
 * 저장하면 그 행만 다시 그리고 포커스를 그 행의 "정보 수정" 버튼으로 돌려준다.
 */
async function editProfile(u) {
  const errors = {};
  const setError = (name, message) => {
    const box = errors[name];
    clear(box.node);
    if (message) {
      box.node.append(icon('system.error', 16), ' ', message);
      box.node.hidden = false;
      box.input.setAttribute('aria-invalid', 'true');
    } else {
      box.node.hidden = true;
      box.input.removeAttribute('aria-invalid');
    }
  };
  const updated = await formDialog({
    title: t('users.edit_title', { name: u.display_name }),
    message: t('users.edit_message'),
    submitLabel: t('users.edit_submit'),
    build(id) {
      const login = el('div', { class: 'field' }, [
        el('span', { class: 'field__label', text: t('users.login_id') }),
        el('p', {}, [el('code', { text: u.login_id })]),
      ]);
      const name = field(id, 'display_name', t('users.display_name'), { value: u.display_name, maxlength: 100, required: true });
      const email = field(id, 'email', t('users.email'), { type: 'email', value: u.email ?? '', maxlength: 254 });
      for (const [key, wrap] of [['display_name', name], ['email', email]]) {
        const input = wrap.querySelector('input');
        const node = el('p', { class: 'field__error', id: `${id}-${key}-error`, hidden: true });
        input.setAttribute('aria-describedby', `${id}-${key}-error`);
        wrap.appendChild(node);
        errors[key] = { node, input };
      }
      const nameInput = errors.display_name.input;
      return {
        content: [login, name, email],
        focus: nameInput,
        async onSubmit() {
          setError('display_name', null);
          setError('email', null);
          try {
            return await api.patch(`/api/v1/users/${u.id}`, profileBody(nameInput.value, errors.email.input.value));
          } catch (e) {
            const key = fieldForError(e.code);
            if (!key) throw e; // 칸과 관계없는 오류는 대화상자 아래에
            setError(key, e.message);
            errors[key].input.focus();
            return null;
          }
        },
      };
    },
  });
  if (!updated) return;
  const old = document.querySelector(`#users-body tr[data-user-id="${u.id}"]`);
  const fresh = row({ ...u, ...updated, groups: u.groups });
  if (old) old.replaceWith(fresh);
  fresh.querySelector('button[data-action="edit-profile"]')?.focus();
  toast(t('users.done_edit', { login: u.login_id }), { type: 'success' });
}

// ---- P28B 승인 대기(외부 인증 첫 로그인) ----

async function approve(u) {
  const role = await formDialog({
    title: t('users.approve_title', { login: u.login_id }),
    message: t('users.approve_message', { method: authLabel(u.auth_type) }),
    submitLabel: t('users.action_approve'),
    build(id) {
      const select = el('select', { class: 'field__input', id: `${id}-role`, name: 'role' }, [
        el('option', { value: 'user', text: t('layout.role_user'), selected: true }),
        el('option', { value: 'admin', text: t('layout.role_admin') }),
      ]);
      const wrap = el('div', { class: 'field' }, [el('label', { class: 'field__label', for: `${id}-role`, text: t('users.role') }), select]);
      const warn = u.name_conflict ? [el('p', { class: 'notice notice--warn' }, [iconWithLabel('system.warn', 16, t('users.name_conflict_note'))])] : [];
      return { content: [...warn, wrap], focus: select, onSubmit: async () => select.value };
    },
  });
  if (role === null || role === undefined) return;
  if (role === 'admin') {
    const ok = await confirmDialog({ title: t('users.approve_admin_title'), message: t('users.approve_admin_message', { login: u.login_id }), confirmLabel: t('users.action_approve'), warn: true });
    if (!ok) return;
  }
  await run(() => api.post(`/api/v1/users/${u.id}/approve`, { role, updated_at: u.updated_at }), 'users.done_approve');
}

async function reject(u) {
  const ok = await confirmDialog({ title: t('users.reject_title', { login: u.login_id }), message: t('users.reject_message'), confirmLabel: t('users.action_reject'), danger: true });
  if (ok) await run(() => api.post(`/api/v1/users/${u.id}/reject`, { updated_at: u.updated_at }), 'users.done_reject');
}

async function removePending(u) {
  const ok = await confirmDialog({ title: t('users.delete_pending_title', { login: u.login_id }), message: t('users.delete_pending_message'), confirmLabel: t('users.action_delete_pending'), danger: true });
  if (ok) await run(() => api.delete(`/api/v1/users/${u.id}`, { query: { updated_at: u.updated_at } }), 'users.done_delete_pending');
}

/** 인증 방식 변경(P28B): 외부로 바꾸면 비밀번호 로그인이 사라지고, 로컬로 바꾸면 임시 비밀번호(24시간)를 한 번 보인다 */
async function changeAuth(u) {
  const targets = authTypes.filter((a) => a !== u.auth_type);
  const target = await formDialog({
    title: t('users.auth_change_title', { login: u.login_id }),
    message: t('users.auth_change_message', { current: authLabel(u.auth_type) }),
    submitLabel: t('users.action_auth_change'),
    build(id) {
      const select = el('select', { class: 'field__input', id: `${id}-auth`, name: 'auth_type' }, targets.map((a) => el('option', { value: a, text: authLabel(a) })));
      const note = el('p', { class: 'notice notice--warn', id: `${id}-note` });
      const explain = () => { clear(note); note.append(iconWithLabel('system.warn', 16, t(select.value === 'local' ? 'users.auth_to_local_note' : 'users.auth_to_external_note', { method: authLabel(select.value) }))); };
      select.addEventListener('change', explain);
      explain();
      select.setAttribute('aria-describedby', `${id}-note`);
      return { content: [el('div', { class: 'field' }, [el('label', { class: 'field__label', for: `${id}-auth`, text: t('account.auth_type') }), select]), note], focus: select, onSubmit: async () => select.value };
    },
  });
  if (!target) return;
  const r = await run(() => api.put(`/api/v1/users/${u.id}/auth-type`, { auth_type: target }), 'users.done_auth_change');
  if (r?.temp_password) {
    await secretDialog({ title: t('users.temp_issued_title'), message: t('users.temp_issued', { login: u.login_id, until: formatDateTime(r.temp_password_expires_at, tz) }), secret: r.temp_password });
  }
}

function pendingRow(u) {
  const actions = [
    el('button', { type: 'button', class: 'btn btn--small btn--primary', dataset: { action: 'approve' }, on: { click: () => approve(u) } }, [t('users.action_approve')]),
    u.approval_status === 'pending' ? actionButton('users.action_reject', () => reject(u), 'btn--danger-outline') : null,
    actionButton('users.action_delete_pending', () => removePending(u), 'btn--danger-outline'),
    el('button', { type: 'button', class: 'btn btn--small', dataset: { action: 'edit-profile' }, on: { click: () => editProfile(u) } }, [iconWithLabel('action.edit_profile', 16, t('users.action_edit'))]),
  ].filter(Boolean);
  const name = [el('span', { text: u.display_name })];
  if (u.name_conflict) name.push(' ', el('span', { class: 'tag tag--warn', title: t('users.name_conflict_note') }, [iconWithLabel('system.warn', 16, t('users.name_conflict'))]));
  return el('tr', { class: ['is-pending'], dataset: { userId: u.id, approval: u.approval_status } }, [
    el('td', {}, [el('code', { text: u.login_id })]),
    el('td', {}, name),
    el('td', { text: u.email ?? '' }),
    el('td', { text: '—' }),
    el('td', {}, [el('span', { class: `tag tag--${u.approval_status}`, text: t(`users.approval_${u.approval_status}`) })]),
    el('td', {}, [el('span', { class: 'tag tag--auth', text: authLabel(u.auth_type) })]),
    el('td', { text: '' }),
    el('td', { text: accountState(u) }),
    el('td', {}, [el('div', { class: 'row-actions' }, actions)]),
  ]);
}

function row(u) {
  if (u.approval_status && u.approval_status !== 'approved') return pendingRow(u);
  const actions = [];
  // P28B: 외부 인증 계정도 이름·이메일을 앱에서 고친다(외부 값으로 갱신하지 않음 — X05의 읽기 전용 폐지)
  actions.push(el('button', { type: 'button', class: 'btn btn--small', dataset: { action: 'edit-profile' }, on: { click: () => editProfile(u) } },
    [iconWithLabel('action.edit_profile', 16, t('users.action_edit'))]));
  actions.push(
    actionButton(u.role === 'admin' ? 'users.action_demote' : 'users.action_promote', () => changeRole(u)),
    u.status === 'active'
      ? actionButton('users.action_deactivate', () => toggleStatus(u), 'btn--danger-outline')
      : actionButton('users.action_activate', () => toggleStatus(u)),
  );
  const selfId = Number(document.getElementById('users-table').dataset.selfId);
  if (u.auth_type === 'local' && u.id !== selfId) actions.push(actionButton('users.action_set_password', () => setPassword(u)));
  if (u.auth_type === 'local') actions.push(actionButton('users.action_temp', () => issueTemp(u)));
  // 잠금 해제(P20): 확인 대화상자(경고 아이콘 + 문장, 포커스는 "취소") 뒤 요청. 감사 로그 user.unlock은 서버가 남긴다
  if (u.locked) actions.push(actionButton('users.action_unlock', () => confirmUnlock(u, confirmDialog, () => run(() => api.post(`/api/v1/users/${u.id}/unlock`, {}), 'users.done_unlock'))));
  if (authTypes.length > 1 && u.id !== selfId) actions.push(actionButton('users.action_auth_change', () => changeAuth(u)));
  return el('tr', { class: [u.status === 'inactive' ? 'is-inactive' : null], dataset: { userId: u.id } }, [
    el('td', {}, [el('code', { text: u.login_id })]),
    el('td', { text: u.display_name }),
    el('td', { text: u.email ?? '' }),
    el('td', {}, [el('span', { class: `tag tag--${u.role}`, text: roleLabel(u.role) })]),
    el('td', {}, [el('span', { class: `tag tag--${u.status}`, text: t(u.status === 'active' ? 'users.status_active' : 'users.status_inactive') })]),
    el('td', {}, [el('span', { class: 'tag tag--auth', text: authLabel(u.auth_type) })]),
    el('td', { text: (u.groups ?? []).join(', ') }), // 소속 그룹(읽기 전용, 관리는 그룹 화면)
    el('td', { text: accountState(u) }),
    el('td', {}, [el('div', { class: 'row-actions' }, actions)]),
  ]);
}

async function load() {
  const body = document.getElementById('users-body');
  try {
    const res = await api.get('/api/v1/users', { query: { page: state.page, size: PAGE_SIZE, q: state.q, role: state.role, status: state.status, approval: state.approval } });
    authTypes = Array.isArray(res.auth_types) ? res.auth_types : ['local'];
    renderPendingBanner(res.approval_counts ?? { pending: 0, rejected: 0 });
    clear(body);
    for (const u of res.data) body.appendChild(row(u));
    document.getElementById('users-empty').hidden = res.data.length > 0;
    const pages = pageCount(res.page.total, res.page.size);
    renderPagination(document.getElementById('users-pagination'), {
      page: res.page.page, pages, onChange: (p) => { state.page = p; load(); },
    });
  } catch (e) {
    toast(e.message, { type: 'error' });
  }
}

// 생성 폼의 칸별 오류(X06): 표시 이름·이메일 오류는 칸 바로 아래에(아이콘 + 글자, aria-invalid)
const CREATE_FIELDS = { display_name: 'uc-name', email: 'uc-email' };
function setCreateError(key, message) {
  const input = document.getElementById(CREATE_FIELDS[key]);
  const box = document.getElementById(`${CREATE_FIELDS[key]}-error`);
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

function initCreate() {
  const form = document.getElementById('user-create');
  const error = document.getElementById('uc-error');
  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    error.hidden = true;
    setCreateError('display_name', null);
    setCreateError('email', null);
    const data = Object.fromEntries(new FormData(form).entries());
    try {
      const r = await api.post('/api/v1/users', data);
      form.reset();
      await load();
      await secretDialog({
        title: t('users.created_title'),
        message: t('users.temp_issued', { login: r.user.login_id, until: formatDateTime(r.user.temp_password_expires_at, tz) }),
        secret: r.temp_password,
      });
    } catch (e) {
      const key = fieldForError(e.code);
      if (key) {
        setCreateError(key, e.message);
        document.getElementById(CREATE_FIELDS[key]).focus();
      } else {
        error.textContent = e.message;
        error.hidden = false;
      }
    }
  });
}

/** 승인 대기 건수 안내(P28B): 계정 보기에서 대기가 있으면 "승인 대기 N건 — 보기" */
function renderPendingBanner(counts) {
  const box = document.getElementById('users-pending');
  clear(box);
  const n = Number(counts.pending ?? 0);
  box.hidden = n === 0 || state.approval === 'pending';
  if (box.hidden) return;
  const go = el('button', { type: 'button', class: 'btn btn--small', dataset: { showPending: '' }, text: t('users.pending_show') });
  go.addEventListener('click', () => {
    document.getElementById('uf-approval').value = 'pending';
    Object.assign(state, { page: 1, approval: 'pending' });
    load();
  });
  box.append(iconWithLabel('system.warn', 16, t('users.pending_banner', { n })), ' ', go);
}

function initFilter() {
  const form = document.getElementById('user-filter');
  document.getElementById('uf-approval').value = state.approval;
  form.addEventListener('submit', (ev) => {
    ev.preventDefault();
    const d = new FormData(form);
    Object.assign(state, { page: 1, q: String(d.get('q') ?? ''), role: String(d.get('role') ?? ''), status: String(d.get('status') ?? ''), approval: String(d.get('approval') ?? '') });
    load();
  });
}

function init() {
  initCreate();
  initFilter();
  load();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
  init();
}
