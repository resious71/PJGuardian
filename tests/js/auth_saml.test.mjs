// SSO(SAML, P28D) 화면 문구: 인증서 만료 알림(종류·아이콘·문구), 설정 확인에 나올 수 있는 모든 서버 사유 코드·단계·점검 항목에 ko·en 문장, 설정 키 라벨.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { notificationText, kindIcon, KINDS } from '../../public/assets/js/lib/notification_core.js';
import ko from '../../public/assets/js/i18n/ko.js';
import en from '../../public/assets/js/i18n/en.js';

const src = (p) => readFileSync(new URL(`../../src/${p}`, import.meta.url), 'utf8');

test('알림 saml_cert_expiring: 종류 등록(서버·화면 같음)·아이콘·만료 임박/만료 문구', () => {
  assert.ok(KINDS.includes('saml_cert_expiring'));
  assert.ok(src('Services/Notifications/NotificationService.php').includes("'saml_cert_expiring'"));
  assert.ok(readFileSync(new URL('../../migrations/028_saml_cert_notifications.sql', import.meta.url), 'utf8').includes("'saml_cert_expiring'"));
  assert.equal(kindIcon({ kind: 'saml_cert_expiring' }), 'system.warn');
  assert.match(notificationText({ kind: 'saml_cert_expiring', params: { which: 'current', days: 12, not_after: '2026-11-01T00:00:00Z' } }), /현재 인증서가 2026-11-01에 만료됩니다\(12일 남음\)/);
  assert.match(notificationText({ kind: 'saml_cert_expiring', params: { which: 'next', days: -1, not_after: '2026-10-10T00:00:00Z' } }), /다음 인증서가 2026-10-10에 만료됐습니다/);
  for (const k of ['notif.kind.saml_cert_expiring', 'notif.kind_desc.saml_cert_expiring', 'notif.text.saml_cert_expiring', 'notif.text.saml_cert_expired']) assert.ok(ko[k] && en[k], k);
});

test('설정 확인 사유 코드·단계·점검 항목에 ko·en 문장', () => {
  const reasons = new Set();
  for (const f of ['Services/Auth/Saml/SamlAdmin.php', 'Services/Auth/Saml/SamlLogin.php', 'Services/Auth/Oidc/OidcHttp.php', 'Services/Auth/Oidc/StreamHttpTransport.php']) {
    for (const m of src(f).matchAll(/new (?:Saml|Oidc)Exception\('([a-z_]+)'/g)) reasons.add(m[1]);
  }
  for (const m of src('Services/Auth/Oidc/OidcHttp.php').matchAll(/\? '([a-z_]+)' : '([a-z_]+)'/g)) { reasons.add(m[1]); reasons.add(m[2]); }
  // 로그인 흐름에서만 나오는 사유(response.*·요청 기록 등)는 화면 단계에 나오지 않는다 — 설정 확인 단계가 부르는 코드만
  for (const r of ['response', 'disabled', 'unsolicited', 'request_unknown', 'relay_mismatch', 'finish_invalid', 'busy']) reasons.delete(r);
  assert.ok(reasons.size >= 12, [...reasons].join(','));
  for (const r of reasons) {
    assert.ok(ko[`saml.reason.${r}`], `ko saml.reason.${r}`);
    assert.ok(en[`saml.reason.${r}`], `en saml.reason.${r}`);
  }
  for (const s of ['idp_cert', 'sso_url', 'sp', 'sp_metadata']) assert.ok(ko[`saml.test.step.${s}`] && en[`saml.test.step.${s}`], s);
  const notes = new Set([...src('Services/Auth/Saml/SamlAdmin.php').matchAll(/'(saml_[a-z_]+)'/g)].map((m) => m[1])
    .filter((n) => !['saml_test_rate_limited', 'saml_metadata_invalid', 'saml_metadata_url_invalid', 'saml_metadata_unreachable', 'saml_key_generate_failed'].includes(n))); // 오류 코드(문구는 error.*) 제외
  assert.ok(notes.size >= 7, [...notes].join(','));
  for (const n of notes) {
    assert.ok(ko[`env.note.${n}`], `ko env.note.${n}`);
    assert.ok(en[`env.note.${n}`], `en env.note.${n}`);
  }
  assert.ok(ko['env.item.saml'] && en['env.item.saml']);
});

test('설정 키 라벨: 등록부의 auth_saml 키 14개 모두', () => {
  const php = src('Support/ConfigSchema.php');
  const keys = [...php.matchAll(/'(saml\.[a-z_]+)' => \['group' => 'auth_saml'/g)].map((m) => m[1]);
  assert.equal(keys.length, 14);
  for (const k of keys) {
    for (const part of ['label', 'help']) {
      assert.ok(ko[`config_settings.key.${k}.${part}`], `ko ${k}.${part}`);
      assert.ok(en[`config_settings.key.${k}.${part}`], `en ${k}.${part}`);
    }
  }
  assert.ok(ko['config_settings.group.auth_saml'] && en['config_settings.group.auth_saml']);
});
