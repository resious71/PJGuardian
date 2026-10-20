// 사용자 관리 확인 흐름(P20): node --test "tests/js/*.test.mjs"
import test from 'node:test';
import assert from 'node:assert/strict';
import { confirmUnlock } from '../../public/assets/js/lib/user_actions.js';
import { has } from '../../public/assets/js/core/i18n.js';

test('잠금 해제: 확인 대화상자(경고 아이콘) 뒤에만 요청, 취소하면 요청하지 않음', async () => {
  const user = { id: 3, display_name: '<b>김</b>' };
  const seen = [];
  let calls = 0;
  const confirmNo = async (opts) => { seen.push(opts); return false; };
  assert.equal(await confirmUnlock(user, confirmNo, async () => { calls++; }), false);
  assert.equal(calls, 0, '취소 → 요청 없음');
  assert.equal(seen[0].warn, true, '경고 아이콘');
  assert.equal(seen[0].danger, undefined, '파괴적 동작 색은 쓰지 않음');
  assert.ok(seen[0].title.includes('<b>김</b>'), '이름은 글자 그대로(대화상자가 textContent로 넣음)');
  for (const k of ['users.confirm_unlock_title', 'users.confirm_unlock', 'users.action_unlock']) assert.ok(has(k), k);
  assert.equal(await confirmUnlock(user, async () => true, async () => { calls++; }), true);
  assert.equal(calls, 1, '확인 → 요청 1번');
});
