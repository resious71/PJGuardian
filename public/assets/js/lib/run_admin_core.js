// Run 관리(P21B) 화면 계산. DOM 없이 시험한다(tests/js/run_admin.test.mjs).
import { t } from '../core/i18n.js';

/** "오래된 Run 모두 선택(최근 N개 제외)": 상세가 남은 Run 중 최신부터 N개(Run 번호 기준)를 빼고, 최신 Run은 언제나 뺀다 */
export function selectOlder(keptRuns, latest, n) {
  const keep = Number.isInteger(n) && n >= 1 ? n : 1;
  return keptRuns.filter((r) => r !== latest && r <= latest - keep).sort((a, b) => a - b);
}

/** 확인 대화상자의 이름 입력이 프로젝트 이름과 같은가(앞뒤 공백만 무시, 대소문자 구분) */
export function confirmMatches(input, expected) {
  return typeof input === 'string' && input.trim() !== '' && input.trim() === String(expected).trim();
}

/** 상세 상태 글자: 상세 있음 / 수동 삭제됨 / 자동 삭제됨 */
export function stateText(row) {
  if (!row.detail_deleted) return t('admin_runs.state_kept');
  return t(row.detail_deleted.reason === 'manual' ? 'admin_runs.state_manual' : 'admin_runs.state_retention');
}

/** "상세 있는 Run n개(자동 보관 기준 15개), 삭제된 Run m개" + 다음 자동 삭제 대상 */
export function summaryText(s) {
  const base = t(s.keep > 0 ? 'admin_runs.summary' : 'admin_runs.summary_off', { kept: s.kept, keep: s.keep, deleted: s.deleted });
  return s.next_retention?.length ? `${base} ${t('admin_runs.next_retention', { runs: s.next_retention.join(', ') })}` : base;
}

/** 삭제 요청 오류 → 사용자 문장(어느 Run이 문제인지 함께) */
export function deleteErrorText(e) {
  const runs = e?.details?.runs;
  const base = e?.message ?? String(e);
  return Array.isArray(runs) && runs.length ? `${base} (${t('admin_runs.problem_runs', { runs: runs.join(', ') })})` : base;
}

/** 삭제 결과 문장 */
export function resultText(r) {
  const parts = [t('admin_runs.result_deleted', { count: r.deleted.length, runs: r.deleted.join(', ') || '—' })];
  if (r.already.length) parts.push(t('admin_runs.result_already', { runs: r.already.join(', ') }));
  return parts.join(' ');
}
