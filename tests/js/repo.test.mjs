// 소스 저장소 연동(P25): 유형별 입력 항목, 평문 경고, 자격 증명 상태(값 비표시), 저장 본문(비우면 유지·개정 번호), 출처 표시,
// 커밋 링크 형식 검사, 연결 테스트 문장(지문), "지금 가져와 분석" 버튼 상태와 이유, 출처 셀(DOM, 링크 rel·textContent).
import test from 'node:test';
import assert from 'node:assert/strict';
import { fieldsFor, plaintextWarning, isSslPort, secretStatus, buildBody, sourceText, safeCommitLink, testResultText, statusKey, fetchButton } from '../../public/assets/js/lib/repo_core.js';
import { sourceNode } from '../../public/assets/js/ui/source_cell.js';
import { has } from '../../public/assets/js/core/i18n.js';
import { FakeDocument } from './fake-dom.mjs';

test('유형별 입력 항목·평문 경고·SSL', () => {
  assert.deepEqual(fieldsFor('none'), []);
  assert.deepEqual(fieldsFor('git'), ['url', 'branch', 'path', 'username', 'secret', 'fetch_timeout']);
  assert.ok(fieldsFor('perforce').includes('fingerprint') && fieldsFor('perforce').includes('secret_kind') && !fieldsFor('perforce').includes('branch'));
  assert.equal(plaintextWarning('git', 'HTTP://git.lan/x.git'), true);
  assert.equal(plaintextWarning('git', 'https://ghe/x.git'), false);
  assert.equal(plaintextWarning('perforce', 'p4:1666'), true);
  assert.equal(plaintextWarning('perforce', 'ssl:p4:1666'), false);
  assert.equal(plaintextWarning('perforce', ''), false);
  assert.equal(isSslPort(' SSL:p4:1666'), true);
});

test('자격 증명 상태는 값을 보이지 않는다', () => {
  assert.equal(secretStatus({ configured: true, secret_set: true, secret_kind: 'token' }), '설정됨(토큰)');
  assert.equal(secretStatus({ configured: true, secret_set: false }), '미설정');
  assert.match(secretStatus({ configured: true, secret_set: true, secret_readable: false }), /읽을 수 없음/);
});

test('저장 본문: 비운 비밀은 보내지 않음, 빈 칸은 null, 분 → 초, 개정 번호', () => {
  const b = buildBody({ kind: 'git', url: ' https://ghe/a.git ', branch: '', path: 'src', username: '', secret: '', fetch_timeout: '45' }, 3);
  assert.deepEqual(b, { kind: 'git', url: 'https://ghe/a.git', branch: null, path: 'src', username: null, fetch_timeout: 2700, revision: 3 });
  const p = buildBody({ kind: 'perforce', port: 'ssl:p4:1666', path: '//depot/x/...', username: 'r', secret: 'pw', secret_kind: 'ticket', fingerprint: '', fetch_timeout: '' }, null);
  assert.deepEqual(p, { kind: 'perforce', port: 'ssl:p4:1666', path: '//depot/x/...', username: 'r', secret_kind: 'ticket', fingerprint: null, secret: 'pw' });
  assert.equal('revision' in p, false, '새 연결은 개정 번호 없음');
  assert.equal(buildBody({ kind: 'git', url: 'https://x/a', clear_secret: true }, 2).clear_secret, true);
});

test('출처 표시와 커밋 링크 검사', () => {
  assert.equal(sourceText({ kind: 'upload', file_name: 'src.zip' }), 'src.zip');
  assert.equal(sourceText({ kind: 'git', ref: 'main', revision: 'a'.repeat(40) }), 'main @aaaaaaa');
  assert.equal(sourceText({ kind: 'git', ref: 'main', revision: 'NOT-A-HASH' }), 'main');
  assert.equal(sourceText({ kind: 'perforce', ref: '//depot/x/...', revision: '4321' }), '//depot/x/... @4321');
  const hash = '0123456789abcdef0123456789abcdef01234567';
  assert.equal(safeCommitLink(`https://github.com/org/repo/commit/${hash}`), `https://github.com/org/repo/commit/${hash}`);
  assert.equal(safeCommitLink(`https://ghe.corp:8443/team/app/commit/${hash.slice(0, 12)}`), `https://ghe.corp:8443/team/app/commit/${hash.slice(0, 12)}`);
  for (const bad of ['javascript:alert(1)', `https://u:p@github.com/o/r/commit/${hash}`, `https://github.com/o/r/tree/${hash}`, `https://github.com/o/r/commit/${hash}?x`, null, `//github.com/o/r/commit/${hash}`]) {
    assert.equal(safeCommitLink(bad), null, String(bad));
  }
});

test('연결 테스트 문장·상태 배지·버튼 이유', () => {
  assert.equal(testResultText({ ok: true, branch: 'main', commit: 'b'.repeat(40), seconds: 0.4 }), '연결됨 — 브랜치 main, 최신 커밋 bbbbbbb (0.4초)');
  assert.equal(testResultText({ ok: true, change: 4321, seconds: 1 }), '연결됨 — 최신 changelist 4321 (1초)');
  assert.equal(testResultText({ ok: false, code: 'repo_auth_failed' }), '인증 실패(자격 증명·권한 확인)');
  assert.equal(testResultText({ ok: false, code: 'weird_code' }), '연결 실패(weird_code)');
  assert.match(testResultText({ ok: false, code: 'repo_tls_untrusted', fingerprint: 'AB:CD' }), /AB:CD/);
  assert.equal(statusKey('ok'), 'repo.status.ok');
  assert.equal(statusKey(null), 'repo.status.unknown');
  assert.deepEqual(fetchButton(null), { enabled: false, reason: 'repo.fetch_reason.none' });
  assert.deepEqual(fetchButton({ configured: true, host_allowed: false }), { enabled: false, reason: 'repo.fetch_reason.host' });
  assert.deepEqual(fetchButton({ configured: true, host_allowed: true, secret_readable: false }), { enabled: false, reason: 'repo.fetch_reason.secret' });
  assert.deepEqual(fetchButton({ configured: true, host_allowed: true }, { busy: true }), { enabled: false, reason: 'repo.fetch_reason.busy' });
  assert.deepEqual(fetchButton({ configured: true, host_allowed: true }), { enabled: true, reason: null });
  for (const k of ['repo.fetch_reason.none', 'repo.fetch_reason.host', 'repo.fetch_reason.secret', 'repo.fetch_reason.busy', 'repo.status.ok', 'repo.status.error', 'repo.status.unknown',
    'repo.test_fail.repo_unreachable', 'repo.test_fail.repo_host_not_allowed', 'repo.test_fail.repo_tls_untrusted', 'repo.kind.git', 'repo.kind.perforce']) {
    assert.ok(has(k), k);
  }
});

test('출처 셀: 링크는 새 탭·noopener, 글자는 textContent', () => {
  const doc = new FakeDocument();
  globalThis.document = doc;
  const hash = 'c'.repeat(40);
  const node = sourceNode({ kind: 'git', ref: '<b>main</b>', revision: hash, link: `https://github.com/o/r/commit/${hash}` }, doc);
  const find = (n, tag) => (n.tagName === tag ? n : (n.childNodes ?? []).map((c) => find(c, tag)).find(Boolean));
  const a = find(node, 'A');
  assert.equal(a.getAttribute('rel'), 'noopener noreferrer');
  assert.equal(a.getAttribute('target'), '_blank');
  assert.equal(a.textContent, '@ccccccc');
  assert.ok(node.textContent.includes('<b>main</b>'), '브랜치 이름은 글자 그대로');
  const plain = sourceNode({ kind: 'git', ref: 'main', revision: hash, link: 'javascript:alert(1)' }, doc);
  assert.equal(find(plain, 'A'), undefined, '형식이 아닌 링크는 쓰지 않음');
  const p4 = sourceNode({ kind: 'perforce', ref: '//depot/x/...', revision: '77' }, doc);
  assert.ok(p4.textContent.includes('//depot/x/... @77'));
});
