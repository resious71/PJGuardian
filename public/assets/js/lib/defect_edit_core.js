// 결함 수정의 순수 계산(P18, 명세 6.2). DOM 없이 시험한다(tests/js/defects.test.mjs).
//   폼 값과 기준값(서버가 준 최신 값)의 차이 → PATCH 본문, 충돌 비교 표, 일괄 변경 요약.
// 담당자 값은 폼에서 문자열('' = 미지정, '12' = 사용자 id), API에서는 정수 또는 null이다.

export const FIELDS = ['assignee', 'severity', 'status'];

/** 결함 API 응답 → 기준값 { assignee: id|null, severity, status } */
export function baseline(defect) {
  return { assignee: defect.assignee ? defect.assignee.id : null, severity: defect.severity, status: defect.status };
}

/** 폼 값(문자열) → 같은 형식 */
export function formValues({ assignee, severity, status }) {
  return { assignee: assignee === '' || assignee == null ? null : Number(assignee), severity, status };
}

/** 바뀐 필드만 PATCH 본문 형식으로(assignee → assignee_id). 바뀐 것이 없으면 {} */
export function diffChanges(base, form) {
  const out = {};
  if (form.assignee !== base.assignee) out.assignee_id = form.assignee;
  if (form.severity !== base.severity) out.severity = form.severity;
  if (form.status !== base.status) out.status = form.status;
  return out;
}

/** PATCH 본문의 변경 → 필드 이름 목록(assignee_id → assignee). 충돌 뒤 다시 시도할 때 폼에 내 값을 남길 필드 */
export function changedFields(changes) {
  return Object.keys(changes).map((k) => (k === 'assignee_id' ? 'assignee' : k));
}

/** PATCH 본문: version + 바뀐 필드 + 사유(빈 사유는 보내지 않음) */
export function patchBody(version, changes, memo) {
  const body = { version, ...changes };
  const m = String(memo ?? '').trim();
  if (m !== '') body.memo = m;
  return body;
}

/**
 * 충돌 대화상자의 비교 행: 세 필드 모두 { field, edited, mine, latest, changedByOther }.
 *   edited: 내가 바꾸려던 필드인지(아니면 mine은 null이고 화면은 "바꾸지 않음")
 *   changedByOther: 내가 본 값(base)과 최신 값이 다른지 = 다른 사용자나 분석이 바꾼 필드
 * 값은 기준값 형식(담당자는 id|null). 표시 문구는 화면이 만든다.
 */
export function conflictRows(base, changes, latestDefect) {
  const latest = baseline(latestDefect);
  const mine = { assignee: changes.assignee_id, severity: changes.severity, status: changes.status };
  const edited = { assignee: 'assignee_id' in changes, severity: 'severity' in changes, status: 'status' in changes };
  return FIELDS.map((field) => ({
    field,
    edited: edited[field],
    mine: edited[field] ? mine[field] : null,
    latest: latest[field],
    changedByOther: base[field] !== latest[field],
  }));
}

/**
 * 일괄 변경 막대 값('keep' = 바꾸지 않음, '' = 미지정) → changes 객체. 바꿀 것이 없으면 {}
 */
export function bulkChanges({ assignee, severity, status }) {
  const out = {};
  if (assignee !== 'keep') out.assignee_id = assignee === '' ? null : Number(assignee);
  if (severity !== 'keep') out.severity = severity;
  if (status !== 'keep') out.status = status;
  return out;
}

/** 선택한 행(Map id → version) → 일괄 API의 items */
export function bulkItems(selected) {
  return [...selected.entries()].map(([id, version]) => ({ id: Number(id), version }));
}
