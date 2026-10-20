// 프로젝트 접근 권한 영역(관리자, 명세 3.1): 그룹 매핑(선택 상자), 사용자 매핑(검색 대화상자), 매핑 제거(확인).
// 서버가 관리자 권한·대상 존재·중복을 다시 확인한다. 변경이 끝나면 화면을 다시 불러 서버가 그린 목록을 보여 준다.
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { toast } from '../ui/toast.js';
import { confirmDialog } from '../ui/dialog.js';
import { errorText, userSearchDialog, SEARCH_MIN } from '../ui/form_dialog.js';

const api = createApi();

function init() {
  const root = document.getElementById('project-access');
  if (!root) return;
  const base = `/api/v1/projects/${root.dataset.projectId}/access`;
  const reload = () => globalThis.location.reload();

  root.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('button[data-action]');
    if (!btn || !root.contains(btn)) return;
    try {
      if (btn.dataset.action === 'add-group') {
        const select = document.getElementById('pa-group');
        if (!select.value) { select.focus(); return; }
        btn.disabled = true;
        await api.post(base, { subject_type: 'group', group_id: Number(select.value) });
        reload();
      } else if (btn.dataset.action === 'add-user') {
        const picked = await userSearchDialog({
          title: t('access.add_user_title'),
          message: t('access.add_user_message', { min: SEARCH_MIN }),
          pick: (u) => api.post(base, { subject_type: 'user', login_id: u.login_id }),
        });
        if (picked) reload();
      } else if (btn.dataset.action === 'remove') {
        const ok = await confirmDialog({
          title: t('access.confirm_remove_title', { name: btn.dataset.name }),
          message: t(btn.dataset.type === 'group' ? 'access.confirm_remove_group' : 'access.confirm_remove_user'),
          confirmLabel: t('access.remove'),
          danger: true,
        });
        if (!ok) return;
        await api.delete(`${base}/${btn.dataset.type}/${btn.dataset.id}`);
        reload();
      }
    } catch (e) {
      btn.disabled = false;
      toast(errorText(e), { type: 'error' });
    }
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
  init();
}
