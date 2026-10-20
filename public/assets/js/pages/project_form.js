// 프로젝트 생성·수정 폼(관리자). 서버가 모든 값을 다시 검사한다(이름 중복, 언어 변경 불가, 패턴 문법).
// 생성: POST /api/v1/projects → 상세 화면으로. 수정: PATCH /api/v1/projects/{id}(언어는 보내지 않음) → 상세 화면으로.
import { createApi } from '../core/api.js';

const api = createApi();

function init() {
  const form = document.getElementById('project-form');
  if (!form) return;
  const error = document.getElementById('pf-error');
  const submit = form.querySelector('button[type=submit]');
  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    error.hidden = true;
    const data = new FormData(form);
    const body = { name: String(data.get('name') ?? ''), description: String(data.get('description') ?? '') };
    const isCreate = form.dataset.mode === 'create';
    if (isCreate) {
      body.language = data.get('language');
    } else {
      // 한 줄에 패턴 하나. 정리(공백·빈 줄·중복)는 서버가 한다
      body.exclude_patterns = String(data.get('exclude_patterns') ?? '').split(/\r?\n/);
    }
    submit.disabled = true;
    try {
      const project = isCreate
        ? await api.post('/api/v1/projects', body)
        : await api.patch(`/api/v1/projects/${form.dataset.projectId}`, body);
      globalThis.location.assign(`${form.dataset.detailBase}/${project.id}`);
    } catch (e) {
      error.textContent = e.message;
      error.hidden = false;
      submit.disabled = false;
    }
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
  init();
}
