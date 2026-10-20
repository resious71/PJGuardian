// 분석 취소(명세 5.4, P19): 확인 대화상자(파괴적 동작) → POST /api/v1/analyses/{id}/cancel. 분석 탭·분석 상세가 함께 쓴다.
// 취소해도 결함과 비교 기준(직전 성공 분석)은 바뀌지 않는다는 안내를 대화상자에 넣는다. 권한은 서버가 다시 검사한다.
import { t } from '../core/i18n.js';
import { confirmDialog } from './dialog.js';

/** 사용자가 확인하면 취소 요청 결과(분석), 아니면 null */
export async function cancelAnalysis(api, a) {
  const ok = await confirmDialog({
    title: t('analyses.cancel_title', { id: a.id }),
    message: t('analyses.cancel_message'),
    confirmLabel: t('analyses.cancel'),
    confirmIcon: 'action.cancel',
    danger: true,
  });
  if (!ok) return null;
  return api.post(`/api/v1/analyses/${a.id}/cancel`, {});
}
