// 사용자 관리 동작의 확인 흐름(P20). 대화상자 함수를 주입받아 node --test로 시험한다(tests/js/users.test.mjs).
import { t } from '../core/i18n.js';

/**
 * 계정 잠금 해제: 확인(경고 아이콘 + 문장, 포커스는 "취소" — confirmDialog 기본) → 확인하면 action 실행.
 * @returns {Promise<boolean>} 실행했으면 true
 */
export async function confirmUnlock(user, confirm, action) {
  const ok = await confirm({
    title: t('users.confirm_unlock_title', { name: user.display_name }),
    message: t('users.confirm_unlock'),
    confirmLabel: t('users.action_unlock'),
    warn: true,
  });
  if (!ok) return false;
  await action();
  return true;
}
