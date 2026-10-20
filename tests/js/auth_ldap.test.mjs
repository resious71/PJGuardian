// 외부 인증·LDAP/AD(P28B) 화면 문구: 새 알림 3종의 문구·아이콘, 서버의 모든 디렉터리 사유 코드·점검 항목에 ko·en 문장, 인증 방식 이름.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { notificationText, kindIcon, KINDS } from '../../public/assets/js/lib/notification_core.js';
import ko from '../../public/assets/js/i18n/ko.js';
import en from '../../public/assets/js/i18n/en.js';

test('알림 3종: 종류 등록·아이콘·문구(하루 요약 포함)', () => {
  for (const k of ['account_pending', 'account_pending_limit', 'auth_identity_mismatch']) {
    assert.ok(KINDS.includes(k), k);
    assert.ok(ko[`notif.kind.${k}`] && en[`notif.kind.${k}`] && ko[`notif.kind_desc.${k}`] && en[`notif.kind_desc.${k}`], k);
  }
  const php = readFileSync(new URL('../../src/Services/Notifications/NotificationService.php', import.meta.url), 'utf8');
  for (const k of ['account_pending', 'account_pending_limit', 'auth_identity_mismatch']) assert.ok(php.includes(`'${k}'`), `서버 KINDS ${k}`);
  assert.equal(notificationText({ kind: 'account_pending', params: { login_id: 'lee', auth_type: 'ldap' } }), 'lee(LDAP)이(가) 처음 로그인해 승인을 기다립니다.');
  assert.equal(notificationText({ kind: 'account_pending', params: { count: 7, day: '2026-10-11' } }), '오늘 승인 대기 계정이 7건 생겼습니다.');
  assert.match(notificationText({ kind: 'account_pending_limit', params: { max: 50 } }), /상한\(50건\)/);
  assert.match(notificationText({ kind: 'auth_identity_mismatch', params: { login_id: 'kim' } }), /^kim의 외부 인증 식별자/);
  assert.equal(kindIcon({ kind: 'auth_identity_mismatch' }), 'system.error');
});

test('디렉터리 사유 코드·점검 항목·인증 방식 이름에 ko·en 문장', () => {
  const ex = readFileSync(new URL('../../src/Services/Auth/Ldap/DirectoryException.php', import.meta.url), 'utf8');
  const reasons = [...ex.matchAll(/^ \*   ([a-z_]+)\s/gm)].map((m) => m[1]);
  assert.ok(reasons.length >= 7, reasons.join(','));
  for (const r of [...reasons, 'not_found', 'skipped']) {
    assert.ok(ko[`ldap.reason.${r}`], `ko ${r}`);
    assert.ok(en[`ldap.reason.${r}`], `en ${r}`);
  }
  const admin = readFileSync(new URL('../../src/Services/Auth/Ldap/LdapAdmin.php', import.meta.url), 'utf8');
  const notes = new Set([...admin.matchAll(/'(ldap_[a-z_]+)'/g)].map((m) => m[1]).filter((n) => !n.endsWith('_') && n !== 'ldap_test_rate_limited')); // 오류 코드(문구는 error.*)는 점검 항목이 아님
  for (const step of ['service_bind', 'base_dn']) notes.add(`ldap_${step}_failed`);
  for (const n of notes) {
    assert.ok(ko[`env.note.${n}`], `ko env.note.${n}`);
    assert.ok(en[`env.note.${n}`], `en env.note.${n}`);
  }
  for (const a of ['local', 'ldap', 'oidc', 'saml']) {
    assert.ok(ko[`account.auth_${a}`] && en[`account.auth_${a}`], a);
  }
  for (const s of ['connect', 'service_bind', 'base_dn', 'user_search']) assert.ok(ko[`ldap.test.step.${s}`] && en[`ldap.test.step.${s}`], s);
});

test('설정 키 라벨: 등록부의 auth·auth_ldap 키 모두', () => {
  const php = readFileSync(new URL('../../src/Support/ConfigSchema.php', import.meta.url), 'utf8');
  const keys = [...php.matchAll(/'((?:auth|ldap)\.[a-z_]+)' => \['group' => '(auth|auth_ldap)'/g)].map((m) => m[1]);
  assert.equal(keys.length, 16);
  for (const k of keys) {
    for (const part of ['label', 'help']) {
      assert.ok(ko[`config_settings.key.${k}.${part}`], `ko ${k}.${part}`);
      assert.ok(en[`config_settings.key.${k}.${part}`], `en ${k}.${part}`);
    }
  }
});
