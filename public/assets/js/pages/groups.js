// 그룹 생성·수정 폼과 삭제 확인(admin, 명세 3.1). 서버가 권한·이름 규칙·중복을 다시 검사한다.
// 생성: POST /api/v1/groups → 상세로. 수정: PATCH /api/v1/groups/{id} → 상세로.
// 삭제: 이름을 정확히 입력해야 버튼이 켜진다(편의 기능). DELETE /api/v1/groups/{id} {confirm_name} → 목록으로.
import { createApi } from '../core/api.js';

const api = createApi();

function initForm() {
  const form = document.getElementById('group-form');
  if (!form) return;
  const error = document.getElementById('gf-error');
  const submit = form.querySelector('button[type=submit]');
  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    error.hidden = true;
    const data = new FormData(form);
    const body = { name: String(data.get('name') ?? ''), description: String(data.get('description') ?? '') };
    const id = form.dataset.groupId;
    submit.disabled = true;
    try {
      const group = id ? await api.patch(`/api/v1/groups/${id}`, body) : await api.post('/api/v1/groups', body);
      globalThis.location.assign(`${form.dataset.detailBase}/${group.id}`);
    } catch (e) {
      error.textContent = e.message;
      error.hidden = false;
      submit.disabled = false;
    }
  });
}

function initDelete() {
  const form = document.getElementById('group-delete');
  if (!form) return;
  const input = document.getElementById('gd-name');
  const submit = document.getElementById('group-delete-submit');
  const error = document.getElementById('gd-error');
  const expected = form.dataset.confirmName;
  const sync = () => { submit.disabled = input.value !== expected; };
  input.addEventListener('input', sync);
  sync();
  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    if (input.value !== expected) return;
    error.hidden = true;
    submit.disabled = true;
    try {
      await api.delete(`/api/v1/groups/${form.dataset.groupId}`, { body: { confirm_name: input.value } });
      globalThis.location.assign(form.dataset.doneUrl);
    } catch (e) {
      error.textContent = e.message;
      error.hidden = false;
      sync();
    }
  });
}

function init() {
  initForm();
  initDelete();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
  init();
}
