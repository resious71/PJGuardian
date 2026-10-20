// 사용자 정보(표시 이름·이메일)의 순수 함수(X05 수정, X06 생성). "내 정보"(pages/account.js), 사용자 관리(pages/admin_users.js),
// 그룹 계정 생성(pages/group_detail.js)이 같이 쓴다.
// 검증은 서버(UserService::updateProfile) 한 곳에서 한다. 여기서는 서버 오류 코드를 어느 입력 칸 옆에 보일지만 정한다.

const FIELD_OF = {
  display_name_required: 'display_name',
  display_name_too_long: 'display_name',
  display_name_invalid: 'display_name',
  display_name_reserved: 'display_name',
  email_invalid: 'email',
  email_too_long: 'email',
  // 생성 경로(X06)는 P10 오류 코드를 유지한다
  invalid_display_name: 'display_name',
  invalid_email: 'email',
};

/** 오류 코드 → 입력 칸 이름('display_name' | 'email'), 칸과 관계없는 오류면 null */
export function fieldForError(code) {
  return FIELD_OF[code] ?? null;
}

/** 저장할 본문: 표시 이름·이메일만(다른 값은 보내지 않는다). 이메일 빈 문자열은 서버가 NULL로 저장 */
export function profileBody(displayName, email) {
  return { display_name: String(displayName ?? ''), email: String(email ?? '') };
}
