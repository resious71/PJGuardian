// 허용 호스트 연결 테스트(P26B): 결과 코드 → 수준·아이콘·문구·해결 안내, 단계별 체크 목록(이름 풀이·TCP·TLS·인증서·HTTP·지문·평문 포트),
// 표 칸(마지막 테스트·인증서 만료 — 임박 경고), 분석 상세의 심볼릭 링크 건너뜀 안내.
import test from 'node:test';
import assert from 'node:assert/strict';
import { CODES, codeLevel, codeKey, hintKey, steps, lastTestCell, certCell } from '../../public/assets/js/lib/host_test_core.js';
import { analysisNotices } from '../../public/assets/js/lib/analysis_view.js';
import { t, has } from '../../public/assets/js/core/i18n.js';

test('결과 코드 → 수준·아이콘·문구·안내', () => {
  assert.deepEqual(codeLevel('ok'), { level: 'ok', icon: 'system.ok' });
  assert.deepEqual(codeLevel('warn_cert_expiring'), { level: 'warn', icon: 'system.warn' });
  assert.equal(codeLevel('fail_dns').level, 'error');
  assert.equal(codeLevel('weird').level, 'neutral');
  assert.equal(hintKey('ok'), null);
  for (const c of CODES) {
    assert.ok(has(codeKey(c)), c);
    if (c !== 'ok') assert.ok(has(hintKey(c)), `hint ${c}`);
  }
  assert.equal(codeKey('weird'), 'host_test.code.unknown');
  assert.ok(t('host_test.hint.fail_tls_untrusted').includes('ca_cert_file'));
});

const base = { code: 'ok', dns: { ok: true, ips: [{ ip: '10.1.2.3', class: 'private' }], pinned: '10.1.2.3' } };
const cert = { not_after: '2027-01-01T00:00:00Z', days_left: 85, issuer: 'Corp CA', names: ['git.corp'], name_match: true, expired: false, expiring: false,
  key_sha1: 'AA:BB', fingerprint_sha256: 'CC:DD' };

test('단계별 체크 목록', () => {
  const ok = steps({ ...base, targets: [{ port: 443, tcp: { ok: true, ms: 3 }, tls: { ok: true, trusted: true, cert }, http: { ok: true, status: 401, method: 'HEAD', redirect: false } }] });
  assert.deepEqual(ok.map((s) => s.state), ['ok', 'ok', 'ok', 'ok', 'ok', 'ok']);
  assert.ok(ok[0].text.includes('10.1.2.3') && ok[0].text.includes(t('host_test.class.private')));
  assert.ok(ok[2].text.includes('Corp CA') && ok[2].text.includes('85'));
  assert.ok(ok[5].text.includes('401') && ok[5].text.includes('인증 필요'));
  // 실패 단계
  assert.deepEqual(steps({ code: 'fail_dns', dns: { ok: false, ips: [] } }).map((s) => s.state), ['fail']);
  assert.deepEqual(steps({ code: 'fail_blocked_address', dns: { ok: false, ips: [{ ip: '169.254.169.254', class: 'blocked' }] } }).map((s) => s.state), ['fail']);
  const refused = steps({ ...base, code: 'fail_connect', targets: [{ port: 443, tcp: { ok: false, error: 'refused' } }] });
  assert.equal(refused[1].text, t('host_test.step.tcp_refused', { port: 443 }));
  const timeout = steps({ ...base, code: 'fail_connect', targets: [{ port: 443, tcp: { ok: true, ms: 1 }, tls: { ok: false, error: 'timeout' } }] });
  assert.equal(timeout[2].text, t('host_test.step.tls_timeout', { port: 443 }));
  const bad = steps({ ...base, targets: [{ port: 443, tcp: { ok: true, ms: 1 }, tls: { ok: true, trusted: false, cert: { ...cert, expired: true, name_match: false } },
    http: { ok: false, status: 500, method: 'HEAD' } }] });
  assert.deepEqual(bad.map((s) => s.state), ['ok', 'ok', 'fail', 'fail', 'fail', 'fail']);
  const warn = steps({ ...base, targets: [{ port: 443, tcp: { ok: true, ms: 1 }, tls: { ok: true, trusted: true, cert: { ...cert, expiring: true, days_left: 9 } } }] });
  assert.equal(warn[2].state, 'warn');
  const redir = steps({ ...base, targets: [{ port: 443, tcp: { ok: true, ms: 1 }, http: { ok: true, status: 302, method: 'HEAD', redirect: true } }] });
  assert.ok(redir[2].text.includes('따라가지 않음'));
  // Perforce: SSL 지문 / 평문 포트
  const p4 = steps({ ...base, targets: [{ port: 1666, tcp: { ok: true, ms: 1 }, p4: true, tls: { ok: true, trusted: false, cert } }] });
  assert.ok(p4.at(-1).text.includes('AA:BB') && p4.at(-1).text.includes('CC:DD') && p4.at(-1).state === 'info');
  const plain = steps({ ...base, targets: [{ port: 1666, tcp: { ok: true, ms: 1 }, plaintext: true, tls: null }] });
  assert.deepEqual(plain.map((s) => s.state), ['ok', 'ok', 'info']);
});

test('표 칸: 마지막 테스트·인증서 만료', () => {
  assert.equal(lastTestCell(null).text, t('host_test.never'));
  const c = lastTestCell({ at: '2026-10-08T00:00:00Z', code: 'fail_connect' }, () => 'F');
  assert.deepEqual([c.level, c.text], ['error', `${t('host_test.code.fail_connect')} · F`]);
  assert.deepEqual(certCell(null), { text: '—', warn: false });
  assert.deepEqual(certCell({ not_after: 'x', days_left: 85 }, () => 'D'), { text: t('host_test.cert_cell', { date: 'D', days: 85 }), warn: false });
  assert.equal(certCell({ not_after: 'x', days_left: 9, expiring: true }, () => 'D').warn, true);
  assert.deepEqual(certCell({ not_after: 'x', days_left: -1, expired: true }, () => 'D'), { text: t('host_test.cert_expired_cell', { date: 'D' }), warn: true });
});

test('분석 상세: 심볼릭 링크 건너뜀 안내(처음 20개 + 나머지 수)', () => {
  const n = analysisNotices({ status: 'succeeded', source_warnings: [{ code: 'symlinks_skipped', count: 2, paths: ['a/l1', 'a/l2'] }] });
  assert.deepEqual(n.map((x) => [x.level, x.key]), [['warn', 'analyses.notice.symlinks_skipped']]);
  assert.equal(t(n[0].key, n[0].params), t('analyses.notice.symlinks_skipped', { n: '2', paths: 'a/l1, a/l2', more: '0' }));
  const many = analysisNotices({ status: 'succeeded', source_warnings: [{ code: 'symlinks_skipped', count: 1029, paths: Array.from({ length: 20 }, (_, i) => `l${i}`) }] });
  assert.equal(many[0].key, 'analyses.notice.symlinks_skipped_more');
  assert.ok(t(many[0].key, many[0].params).includes('1,029') && t(many[0].key, many[0].params).includes('1,009'));
  assert.deepEqual(analysisNotices({ status: 'succeeded', source_warnings: [{ code: 'other' }] }), []);
  assert.deepEqual(analysisNotices({ status: 'succeeded' }), []);
});

test('HTTP 4xx(P27C): 407 뺀 4xx는 도달 성공 + 안내, 407은 프록시 안내와 함께 오류', () => {
  const httpStep = (http) => steps({ ...base, targets: [{ port: 443, tcp: { ok: true, ms: 1 }, http: { method: 'HEAD', redirect: false, ...http } }] })[2];
  const nf = httpStep({ ok: true, status: 404, note: 'no_page' });
  assert.equal(nf.state, 'ok');
  assert.equal(nf.text, t('host_test.step.http_no_page', { status: 404, method: 'HEAD' }));
  assert.ok(nf.text.includes('루트 경로에 페이지가 없습니다') && nf.text.includes('연결 테스트'));
  assert.equal(httpStep({ ok: true, status: 429, note: 'no_page' }).state, 'ok');
  assert.ok(httpStep({ ok: true, status: 403, note: 'auth' }).text.includes('인증 필요'));
  const px = httpStep({ ok: false, status: 407, note: 'proxy' });
  assert.equal(px.state, 'fail');
  assert.ok(px.text.includes('프록시'));
  // note가 없는 예전 결과(P26B 때 저장된 화면 응답)도 상태로 판단
  assert.ok(httpStep({ ok: false, status: 407 }).text.includes('프록시'));
  assert.equal(httpStep({ ok: false, status: 502 }).text, t('host_test.step.http_bad', { status: 502, method: 'HEAD' }));
  assert.ok(has('host_test.step.http_no_page') && has('host_test.step.http_proxy'));
});

test('평문 포트 단계 문구(P19C): HTTP 상태 줄을 확인했으면 "평문 HTTP로 확인", 비HTTP(Perforce 등)는 "TCP 접속만"', () => {
  const plainHttp = steps({ ...base, targets: [{ port: 8080, tcp: { ok: true, ms: 1 }, plaintext: true, tls: null, http: { ok: true, status: 404, method: 'HEAD', redirect: false, note: 'no_page' } }] });
  assert.equal(plainHttp[2].text, t('host_test.step.plaintext_http', { port: 8080 }));
  assert.ok(plainHttp[2].text.includes('평문 HTTP로 확인') && !plainHttp[2].text.includes('TCP 접속만'));
  const plainTcp = steps({ ...base, targets: [{ port: 1666, tcp: { ok: true, ms: 1 }, plaintext: true, tls: null, http: null }] });
  assert.equal(plainTcp[2].text, t('host_test.step.plaintext', { port: 1666 }));
  assert.ok(plainTcp[2].text.includes('TCP 접속만'));
  assert.notEqual(plainHttp[2].text.replace('8080', ''), plainTcp[2].text.replace('1666', ''));
});
