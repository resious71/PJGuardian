// SSO(OIDC, P28C) 화면 문구: 로그인 ID 충돌 알림 문구, 연결 시험에 나올 수 있는 모든 서버 사유 코드·점검 항목·단계에 ko·en 문장, 설정 키 라벨.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { notificationText } from '../../public/assets/js/lib/notification_core.js';
import ko from '../../public/assets/js/i18n/ko.js';
import en from '../../public/assets/js/i18n/en.js';

const src = (p) => readFileSync(new URL(`../../src/Services/Auth/Oidc/${p}`, import.meta.url), 'utf8');

test('알림: SSO 로그인 ID 충돌(reason login_id_conflict)은 다른 문구, 그 밖은 식별자 불일치 문구', () => {
  assert.match(notificationText({ kind: 'auth_identity_mismatch', params: { login_id: 'kim', auth_type: 'oidc', reason: 'login_id_conflict' } }), /^kim: SSO 로그인 ID가 다른 계정/);
  assert.match(notificationText({ kind: 'auth_identity_mismatch', params: { login_id: 'kim', reason: 'identity_mismatch' } }), /^kim의 외부 인증 식별자/);
  assert.ok(en['notif.text.auth_login_conflict']);
});

test('연결 시험 사유 코드·단계·점검 항목에 ko·en 문장', () => {
  // 연결 시험(디스커버리·JWKS·토큰 엔드포인트)이 부르는 코드에서 나올 수 있는 사유(점 앞부분)
  const reasons = new Set();
  for (const f of ['OidcHttp.php', 'OidcProvider.php', 'StreamHttpTransport.php', 'OidcAdmin.php']) {
    for (const m of src(f).matchAll(/new OidcException\('([a-z_]+)/g)) reasons.add(m[1]);
  }
  for (const m of src('OidcHttp.php').matchAll(/\? '([a-z_]+)' : '([a-z_]+)'/g)) { reasons.add(m[1]); reasons.add(m[2]); }
  assert.ok(reasons.size >= 12, [...reasons].join(','));
  for (const r of reasons) {
    assert.ok(ko[`oidc.reason.${r}`], `ko oidc.reason.${r}`);
    assert.ok(en[`oidc.reason.${r}`], `en oidc.reason.${r}`);
  }
  for (const s of ['discovery', 'jwks', 'token_endpoint']) assert.ok(ko[`oidc.test.step.${s}`] && en[`oidc.test.step.${s}`], s);
  const notes = new Set([...src('OidcAdmin.php').matchAll(/'(oidc_[a-z_]+)'/g)].map((m) => m[1]).filter((n) => n !== 'oidc_test_rate_limited' && n !== 'oidc_secret_set'));
  assert.ok(notes.size >= 6, [...notes].join(','));
  for (const n of notes) {
    assert.ok(ko[`env.note.${n}`], `ko env.note.${n}`);
    assert.ok(en[`env.note.${n}`], `en env.note.${n}`);
  }
  for (const k of ['secret.set', 'secret.none', 'secret.unreadable', 'migration_required', 'redirect.label', 'redirect.copied', 'redirect.copy_failed']) {
    assert.ok(ko[`oidc.${k}`] && en[`oidc.${k}`], k);
  }
});

test('설정 키 라벨: 등록부의 auth_oidc 키 14개 모두', () => {
  const php = readFileSync(new URL('../../src/Support/ConfigSchema.php', import.meta.url), 'utf8');
  const keys = [...php.matchAll(/'(oidc\.[a-z_]+)' => \['group' => 'auth_oidc'/g)].map((m) => m[1]);
  assert.equal(keys.length, 14);
  for (const k of keys) {
    for (const part of ['label', 'help']) {
      assert.ok(ko[`config_settings.key.${k}.${part}`], `ko ${k}.${part}`);
      assert.ok(en[`config_settings.key.${k}.${part}`], `en ${k}.${part}`);
    }
  }
  assert.ok(ko['config_settings.group.auth_oidc'] && en['config_settings.group.auth_oidc']);
});
