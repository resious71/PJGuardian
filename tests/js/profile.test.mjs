// 사용자 정보 수정 순수 함수(X05, public/assets/js/lib/profile_core.js) 시험
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fieldForError, profileBody } from '../../public/assets/js/lib/profile_core.js';

test('오류 코드를 입력 칸에 연결한다', () => {
  for (const code of ['display_name_required', 'display_name_too_long', 'display_name_invalid', 'display_name_reserved']) {
    assert.equal(fieldForError(code), 'display_name');
  }
  assert.equal(fieldForError('email_invalid'), 'email');
  assert.equal(fieldForError('email_too_long'), 'email');
  assert.equal(fieldForError('invalid_display_name'), 'display_name', '생성 경로(P10 코드, X06)');
  assert.equal(fieldForError('invalid_email'), 'email');
  assert.equal(fieldForError('login_id_taken'), null, '로그인 ID 오류는 폼 아래에(생성 화면 기존 동작)');
  assert.equal(fieldForError('ldap_managed'), null, '칸과 관계없는 오류는 폼 아래에');
  assert.equal(fieldForError('network'), null);
  assert.equal(fieldForError(undefined), null);
});

test('저장 본문은 표시 이름·이메일만', () => {
  assert.deepEqual(profileBody('홍길동', 'a@b.cd'), { display_name: '홍길동', email: 'a@b.cd' });
  assert.deepEqual(profileBody(null, undefined), { display_name: '', email: '' });
  assert.deepEqual(Object.keys(profileBody('x', 'y')), ['display_name', 'email']);
});
