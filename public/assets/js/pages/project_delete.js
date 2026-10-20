// 프로젝트 삭제 확인(관리자, 명세 4장). 이름을 정확히 입력해야 버튼이 켜진다(편의 기능, 서버가 이름을 다시 확인한다).
// DELETE /api/v1/projects/{id} {confirm_name} → 성공하면 프로젝트 목록으로.
import { createApi } from '../core/api.js';

const api = createApi();

function init() {
  const form = document.getElementById('project-delete');
  if (!form) return;
  const input = document.getElementById('pd-name');
  const submit = document.getElementById('project-delete-submit');
  const error = document.getElementById('pd-error');
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
      await api.delete(`/api/v1/projects/${form.dataset.projectId}`, { body: { confirm_name: input.value } });
      globalThis.location.assign(form.dataset.doneUrl);
    } catch (e) {
      error.textContent = e.message;
      error.hidden = false;
      sync();
    }
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
  init();
}
