// JS 순수 함수 테스트: node --test tests/js/
import test from 'node:test';
import assert from 'node:assert/strict';
import { t, has } from '../../public/assets/js/core/i18n.js';
import { escapeHtml, isSafeUrl, el, clear, meta } from '../../public/assets/js/core/dom.js';
import { formatDateTime, formatDate, parseUtc, timezoneFromDocument } from '../../public/assets/js/core/time.js';
import { parseError, createApi, ApiError } from '../../public/assets/js/core/api.js';
import { nextDelay, createPoller } from '../../public/assets/js/core/poll.js';
import { resolve, names, label, ariaLabel, icon, meaningfulIcon, iconWithLabel } from '../../public/assets/js/ui/icons.js';
import { pageCount } from '../../public/assets/js/ui/pagination.js';
import { FakeDocument } from './fake-dom.mjs';

// ---- i18n ----
test('i18n: 키 조회와 자리표시자', () => {
  assert.equal(t('common.request_id', { id: 'abc' }), '요청 ID: abc');
  assert.equal(t('component.page_of', { page: 2, pages: 5 }), '2 / 5 페이지');
  assert.equal(t('no.such.key'), 'no.such.key', '없는 키는 키 그대로');
  assert.equal(t('common.request_id'), '요청 ID: {id}', '값을 주지 않은 자리표시자는 그대로');
  assert.equal(has('icon.severity.critical'), true);
  assert.equal(t('constructor'), 'constructor', 'Object 원형의 이름도 키로 오인하지 않음');
});

// ---- dom ----
test('dom: escapeHtml', () => {
  assert.equal(escapeHtml(`<img src=x onerror="alert('x')">&`), '&lt;img src=x onerror=&quot;alert(&#39;x&#39;)&quot;&gt;&amp;');
});

test('dom: 위험한 주소 거부', () => {
  for (const ok of ['/projects/1', '#top', '?page=2', 'https://example.com', 'mailto:a@b.c', 'relative/path']) assert.equal(isSafeUrl(ok), true, ok);
  for (const bad of ['javascript:alert(1)', ' JavaScript:alert(1)', 'java\nscript:alert(1)', 'data:text/html,<b>', 'vbscript:x']) assert.equal(isSafeUrl(bad), false, bad);
});

test('dom: el은 문자열을 텍스트 노드로만 넣는다', () => {
  const doc = new FakeDocument();
  const node = el('p', { class: ['a', null, 'b'], dataset: { id: 3 } }, ['<b>굵게</b>', 5], doc);
  assert.equal(node.className, 'a b');
  assert.equal(node.dataset.id, '3');
  assert.equal(node.childNodes.length, 2);
  assert.equal(node.childNodes[0].nodeType, 3, '텍스트 노드');
  assert.equal(node.textContent, '<b>굵게</b>5', 'HTML로 해석되지 않음');
  clear(node);
  assert.equal(node.childNodes.length, 0);
});

test('dom: on* 속성·innerHTML·javascript: 주소를 거부', () => {
  const doc = new FakeDocument();
  assert.throws(() => el('a', { onclick: 'alert(1)' }, [], doc), /이벤트 속성/);
  assert.throws(() => el('div', { innerHTML: '<b>x</b>' }, [], doc), /허용하지 않는 속성/);
  assert.throws(() => el('a', { href: 'javascript:alert(1)' }, [], doc), /안전하지 않은 주소/);
  const a = el('a', { href: '/ok', text: '링크' }, [], doc);
  assert.equal(a.getAttribute('href'), '/ok');
});

test('dom: meta 읽기', () => {
  const doc = new FakeDocument({ 'csrf-token': 'tok' });
  assert.equal(meta('csrf-token', doc), 'tok');
  assert.equal(meta('none', doc), '');
});

// ---- time ----
test('time: UTC → Asia/Seoul 표시', () => {
  assert.equal(formatDateTime('2026-10-02T15:30:00Z', 'Asia/Seoul'), '2026-10-03 00:30');
  assert.equal(formatDateTime('2026-10-02T15:30:09Z', 'Asia/Seoul', { seconds: true }), '2026-10-03 00:30:09');
  assert.equal(formatDate('2026-12-31T16:00:00Z', 'Asia/Seoul'), '2027-01-01');
  assert.equal(formatDateTime('2026-10-02T15:30:00Z', 'UTC'), '2026-10-02 15:30');
});

test('time: UTC 표기가 없는 문자열은 거부(현지 시간 오인 방지)', () => {
  assert.throws(() => parseUtc('2026-10-02 15:30:00'));
  assert.throws(() => parseUtc('2026-10-02T15:30:00+09:00'));
  assert.equal(parseUtc('2026-10-02T15:30:00Z').getTime(), Date.UTC(2026, 9, 2, 15, 30, 0));
});

test('time: 시간대는 meta에서, 없으면 Asia/Seoul', () => {
  assert.equal(timezoneFromDocument(new FakeDocument({ 'app-timezone': 'UTC' })), 'UTC');
  assert.equal(timezoneFromDocument(new FakeDocument()), 'Asia/Seoul');
});

// ---- api ----
test('api: 공통 오류 형식 파싱', () => {
  const e = parseError(409, { error: { code: 'version_conflict', message: '다른 사용자가 먼저 바꿨습니다.', request_id: 'r1' } });
  assert.ok(e instanceof ApiError);
  assert.deepEqual([e.status, e.code, e.message, e.requestId], [409, 'version_conflict', '다른 사용자가 먼저 바꿨습니다.', 'r1']);
  const odd = parseError(502, null, 'hdr-id');
  assert.deepEqual([odd.code, odd.message, odd.requestId], ['http_502', t('error.unknown'), 'hdr-id'], '형식이 다른 응답도 안전하게');
  assert.equal(parseError(400, { error: 'string' }).code, 'http_400');
});

function fakeFetch(responses, calls) {
  return async (url, init) => {
    calls.push({ url, init });
    const r = responses.shift();
    if (r instanceof Error) throw r;
    return {
      ok: r.status >= 200 && r.status < 300,
      status: r.status,
      headers: { get: (n) => ({ 'content-type': 'application/json; charset=utf-8', 'x-request-id': r.rid ?? null })[n.toLowerCase()] ?? null },
      json: async () => r.body,
    };
  };
}

test('api: CSRF 헤더, data 꺼내기, 오류·401·409 처리', async () => {
  const calls = [];
  let unauthorized = 0;
  let conflict = null;
  const api = createApi({
    fetchImpl: fakeFetch([
      { status: 200, body: { data: { ok: true } } },
      { status: 200, body: { data: [1, 2], page: { page: 1, size: 50, total: 2 } } },
      { status: 404, body: { error: { code: 'not_found', message: '없음', request_id: 'x1' } } },
      { status: 401, body: { error: { code: 'unauthenticated', message: '로그인 필요', request_id: 'x2' } } },
      { status: 409, body: { error: { code: 'version_conflict', message: '충돌', request_id: 'x3' } } },
      new TypeError('Failed to fetch'),
    ], calls),
    doc: new FakeDocument({ 'csrf-token': 'tok-123', 'app-base': '/JGuardian/public' }),
    onUnauthorized: () => { unauthorized++; },
    onConflict: (e) => { conflict = e.code; },
  });
  assert.deepEqual(await api.post('/api/v1/x', { a: 1 }), { ok: true });
  assert.equal(calls[0].url, '/JGuardian/public/api/v1/x', '배포 경로 접두사');
  assert.equal(calls[0].init.headers['X-CSRF-Token'], 'tok-123', '상태 변경 요청에 CSRF 헤더');
  assert.equal(calls[0].init.body, '{"a":1}');
  assert.deepEqual(await api.get('/api/v1/list', { query: { page: 1, q: '' } }), { data: [1, 2], page: { page: 1, size: 50, total: 2 } });
  assert.equal(calls[1].init.headers['X-CSRF-Token'], undefined, 'GET에는 CSRF 헤더 없음');
  assert.equal(calls[1].url, '/JGuardian/public/api/v1/list?page=1', '빈 값 쿼리는 뺌');
  await assert.rejects(api.get('/api/v1/nope'), (e) => e.code === 'not_found' && e.requestId === 'x1');
  await assert.rejects(api.get('/api/v1/me'), (e) => e.status === 401);
  assert.equal(unauthorized, 1, '401이면 로그인 화면으로 보내는 훅 호출');
  await assert.rejects(api.put('/api/v1/defects/1', { version: 1 }), (e) => e.status === 409);
  assert.equal(conflict, 'version_conflict', '409 훅 호출');
  await assert.rejects(api.get('/api/v1/x'), (e) => e.code === 'network' && e.status === 0);
});

// ---- poll ----
test('poll: 실패 시 지수 백오프와 최대값', () => {
  assert.deepEqual([0, 1, 2, 3, 4, 10].map((f) => nextDelay(5000, f, 60000)), [5000, 10000, 20000, 40000, 60000, 60000]);
});

test('poll: 숨겨진 탭에서는 멈추고, task가 false면 끝', async () => {
  const timers = [];
  const doc = { visibilityState: 'visible', listeners: {}, addEventListener(e, fn) { this.listeners[e] = fn; }, removeEventListener() {} };
  let runs = 0;
  let results = [true, new Error('x'), false];
  const poller = createPoller(async () => {
    runs++;
    const r = results.shift();
    if (r instanceof Error) throw r;
    return r;
  }, { interval: 1000, maxInterval: 8000, doc, setTimer: (fn, ms) => { timers.push({ fn, ms }); return timers.length; }, clearTimer: () => {} });
  poller.start();
  assert.equal(timers.at(-1).ms, 0, '시작하면 곧바로');
  await timers.at(-1).fn();
  assert.equal(timers.at(-1).ms, 1000, '성공 → 기본 간격');
  await timers.at(-1).fn();
  assert.equal(timers.at(-1).ms, 2000, '실패 → 간격 2배');
  doc.visibilityState = 'hidden';
  doc.listeners.visibilitychange();
  const before = timers.length;
  await timers.at(-1).fn(); // 숨겨진 동안의 타이머는 실행해도 조회하지 않음
  assert.equal(runs, 2, '숨겨진 탭에서는 조회 안 함');
  doc.visibilityState = 'visible';
  doc.listeners.visibilitychange();
  assert.equal(timers.length, before + 1);
  assert.equal(timers.at(-1).ms, 0, '다시 보이면 곧바로 조회');
  await timers.at(-1).fn();
  assert.equal(runs, 3);
  assert.equal(poller.running, false, 'task가 false면 멈춤');
});

// ---- icons ----
test('icons: 매핑 조회와 라벨', () => {
  assert.equal(resolve('severity.critical').icon, 'octagon-alert');
  assert.equal(label('severity.critical'), '치명적');
  assert.equal(ariaLabel('defect_status.resolved'), '결함 상태: 해결됨');
  assert.deepEqual(names('system'), ['system.ok', 'system.warn', 'system.error', 'system.unknown']);
});

test('icons: 없는 이름은 strict(dev)면 오류, 아니면 대체 아이콘 + 경고', () => {
  assert.throws(() => resolve('no.such', { strict: true }), /매핑에 없는/);
  const warnings = [];
  const r = resolve('no.such', { strict: false, warn: (m) => warnings.push(m) });
  assert.equal(r.name, 'ui.fallback');
  assert.equal(warnings.length, 1);
});

test('icons: SVG 요소 생성(장식용/의미 전달/라벨)', () => {
  const doc = new FakeDocument({ 'icon-sprite': '/assets/icons/sprite.svg?v=abc', 'app-env': 'prod' });
  const deco = icon('analysis_status.running', 16, doc);
  assert.equal(deco.getAttribute('aria-hidden'), 'true');
  assert.equal(deco.getAttribute('class'), 'icon icon-16 icon-spin', 'running은 회전');
  assert.equal(deco.childNodes[0].getAttribute('href'), '/assets/icons/sprite.svg?v=abc#i-loader-circle');
  const mean = meaningfulIcon('action.delete', 20, null, doc);
  assert.equal(mean.getAttribute('role'), 'img');
  assert.equal(mean.getAttribute('aria-label'), '삭제');
  assert.equal(mean.getAttribute('aria-hidden'), null);
  const withLabel = iconWithLabel('severity.high', 16, null, doc);
  assert.equal(withLabel.textContent, '높음');
  assert.throws(() => icon('severity.high', 18, doc), /16, 20, 24/);
  const devDoc = new FakeDocument({ 'app-env': 'dev', 'icon-sprite': '' });
  assert.throws(() => icon('no.such', 16, devDoc), /매핑에 없는/);
});

test('pagination: 페이지 수 계산', () => {
  assert.deepEqual([pageCount(0, 50), pageCount(50, 50), pageCount(51, 50), pageCount(123, 50)], [1, 1, 2, 3]);
});
