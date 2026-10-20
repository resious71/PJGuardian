// 스타일 가이드 화면(개발 전용)의 시연 동작.
import { t } from '../core/i18n.js';
import { toast } from '../ui/toast.js';
import { confirmDialog } from '../ui/dialog.js';
import { renderPagination } from '../ui/pagination.js';

function init() {
  for (const b of document.querySelectorAll('[data-demo-toast]')) {
    const type = b.dataset.demoToast;
    b.addEventListener('click', () => toast(t(`styleguide.toast_${type}_msg`), { type }));
  }
  const confirmBtn = document.querySelector('[data-demo-confirm]');
  const result = document.getElementById('sg-confirm-result');
  confirmBtn?.addEventListener('click', async () => {
    const ok = await confirmDialog({
      title: t('styleguide.confirm_title'),
      message: t('styleguide.confirm_message'),
      confirmLabel: t('styleguide.confirm_ok'),
      danger: true,
      confirmIcon: 'action.delete',
    });
    if (result) result.textContent = t(ok ? 'styleguide.confirm_result_yes' : 'styleguide.confirm_result_no');
  });
  for (const nav of document.querySelectorAll('[data-pagination]')) {
    const draw = (page) => renderPagination(nav, { page, pages: Number(nav.dataset.pages), onChange: draw });
    draw(Number(nav.dataset.page));
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
  init();
}
