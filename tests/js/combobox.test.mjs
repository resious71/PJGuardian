// P21C: 콤보박스 상태(lib/combobox_core.js), 대시보드 언어 필터 주소, 업로드 내용 요약 줄
import test from 'node:test';
import assert from 'node:assert/strict';
import { normalize, filterOptions, initialState, visible, reduce } from '../../public/assets/js/lib/combobox_core.js';
import { languageFromSearch, withLanguage } from '../../public/assets/js/lib/dashboard_core.js';
import { uploadSummaryRows, previousLine, shortHash, signedBytes } from '../../public/assets/js/lib/upload_core.js';
import { t, has } from '../../public/assets/js/core/i18n.js';

const options = [
  { value: '', label: '전체(볼 수 있는 프로젝트)' },
  { value: '1', label: 'Pandas' },
  { value: '2', label: 'Chart.js' },
  { value: '3', label: '결제 서버' },
];
const run = (s, ...actions) => actions.reduce((acc, a) => reduce(acc, a), s);

test('입력으로 좁히기: 대소문자·NFC 무시, 빈 입력은 전체', () => {
  assert.deepEqual(filterOptions(options, 'chart').map((o) => o.value), ['2']);
  assert.deepEqual(filterOptions(options, 'PAN').map((o) => o.value), ['1']);
  assert.deepEqual(filterOptions(options, '결제'.normalize('NFD')).map((o) => o.value), ['3'], 'NFD로 입력해도');
  assert.equal(filterOptions(options, '  ').length, 4);
  assert.equal(normalize(' Ab '), 'ab');
});

test('포커스하면 전체 목록(작은 목록은 입력 없이 고름), 글자를 치면 좁힘, 일치 없음', () => {
  let s = run(initialState(options, '2'), { type: 'focus' });
  assert.equal(s.open, true);
  assert.equal(visible(s).length, 4, '포커스만으로는 전체');
  assert.equal(s.active, 2, '지금 값이 활성');
  s = reduce(s, { type: 'input', text: 'pa' });
  assert.deepEqual(visible(s).map((o) => o.value), ['1']);
  assert.equal(s.active, 0);
  s = reduce(s, { type: 'input', text: '없는 이름' });
  assert.deepEqual([visible(s).length, s.active], [0, -1]);
  s = reduce(s, { type: 'enter' });
  assert.equal(s.value, '2', '일치 없으면 Enter로 바뀌지 않음');
});

test('목록 밖 글자는 값이 되지 않는다(blur·Esc → 원래 값 표시)', () => {
  let s = run(initialState(options, '1'), { type: 'focus' }, { type: 'input', text: '아무거나' }, { type: 'blur' });
  assert.deepEqual([s.value, s.query, s.open, s.changed], ['1', 'Pandas', false, false]);
  s = run(initialState(options, '1'), { type: 'focus' }, { type: 'input', text: 'cha' }, { type: 'escape' });
  assert.deepEqual([s.value, s.query, s.open], ['1', 'Pandas', false]);
});

test('키보드: ↓↑ 이동(끝에서 멈춤), Home·End, Enter 선택 → changed', () => {
  let s = run(initialState(options, ''), { type: 'down' });
  assert.deepEqual([s.open, s.active], [true, 0], '닫혀 있으면 열기');
  s = run(s, { type: 'down' }, { type: 'down' }, { type: 'down' }, { type: 'down' });
  assert.equal(s.active, 3, '마지막에서 멈춤');
  s = run(s, { type: 'up' });
  assert.equal(s.active, 2);
  s = run(s, { type: 'home' });
  assert.equal(s.active, 0);
  s = run(s, { type: 'end' }, { type: 'enter' });
  assert.deepEqual([s.value, s.query, s.open, s.changed], ['3', '결제 서버', false, true]);
  s = run(s, { type: 'focus' }, { type: 'pick', index: 1 });
  assert.deepEqual([s.value, s.changed], ['1', true], '마우스로 고르기');
});

test('한국어 조합 중 Enter는 확정하지 않는다', () => {
  let s = run(initialState(options, ''), { type: 'focus' }, { type: 'compositionstart' }, { type: 'input', text: '결' });
  s = reduce(s, { type: 'enter' });
  assert.deepEqual([s.value, s.open], ['', true], '조합 중');
  s = run(s, { type: 'compositionend' }, { type: 'enter' });
  assert.deepEqual([s.value, s.changed], ['3', true], '조합이 끝난 뒤 Enter');
});

test('지우기 = 전체(빈 값), 목록 변경 시 사라진 값은 전체로', () => {
  let s = run(initialState(options, '2'), { type: 'clear' });
  assert.deepEqual([s.value, s.changed], ['', true]);
  s = initialState(options, '2');
  s = reduce(s, { type: 'options', options: options.filter((o) => o.value !== '2') });
  assert.deepEqual([s.value, s.query, s.changed], ['', '전체(볼 수 있는 프로젝트)', true]);
  s = reduce(s, { type: 'options', options });
  assert.equal(s.changed, false, '값이 남아 있으면 바뀌지 않음');
  // P22: 원래 <select>의 값을 코드로 바꾼 뒤 다시 읽기(주소에서 조건 복원)
  s = reduce(initialState(options, ''), { type: 'options', options, value: '3' });
  assert.deepEqual([s.value, s.query, s.changed], ['3', '결제 서버', true]);
  assert.equal(reduce(initialState(options, '1'), { type: 'options', options, value: 'nope' }).value, '', '목록에 없는 값이면 전체');
});

test('대시보드 언어 필터 주소: 잘못된 값은 전체, 다른 쿼리·해시 유지', () => {
  assert.equal(languageFromSearch('?language=python'), 'python');
  assert.equal(languageFromSearch('?language=javascript&x=1'), 'javascript');
  for (const bad of ['?language=PYTHON', '?language=ts', '?language=', '']) assert.equal(languageFromSearch(bad), '', bad);
  assert.equal(withLanguage('/dashboard?x=1#a', 'python'), '/dashboard?x=1&language=python#a');
  assert.equal(withLanguage('/dashboard?language=python', ''), '/dashboard');
});

test('업로드 내용 요약 줄과 직전 비교', () => {
  const s = { files: 1234, bytes: 2048, excluded: 5, targets: 10, targets_by_extension: { js: 7, ts: 3, jsx: 0 }, config_skipped: 1, previous: null };
  const rows = uploadSummaryRows(s).map((r) => [t(r.key), t(r.value.key, r.value.params)]);
  assert.deepEqual(rows, [
    ['zip 열기', '정상(암호화·손상 없음)'],
    ['압축 해제한 파일', '1,234개'],
    ['해제 후 크기', '2.0KB'],
    ['분석 대상 파일', '10개(.js 7, .ts 3)'],
    ['제외한 항목', '5개(+ 업로드한 ESLint 설정 1개는 대상에서 뺌)'],
    ['경로 문제', '없음(상위 경로·절대 경로·심볼릭 링크가 있으면 업로드 자체를 거부합니다)'],
  ]);
  assert.equal(t(uploadSummaryRows({ ...s, files: 0 })[0].value.key), '열렸지만 파일이 없음(빈 zip이거나 모두 제외됨)');
  assert.equal(previousLine(null).state, 'none');
  const same = previousLine({ same: true, size: 2048, size_diff: 0, created_at: 'T' });
  assert.deepEqual([same.state, same.tone, t(same.key, same.params)], ['same', 'warn', '직전 업로드(T, 2.0KB)와 같은 파일입니다.']);
  assert.equal(t(previousLine({ same: false, size: 1024, size_diff: -300, created_at: 'T' }).key, previousLine({ same: false, size: 1024, size_diff: -300, created_at: 'T' }).params),
    '직전 업로드(T, 1.0KB)와 다른 파일입니다. 크기 차이 −300B.');
  assert.deepEqual([shortHash('a'.repeat(64)), shortHash(null), signedBytes(0), signedBytes(1536)], [`${'a'.repeat(12)}…`, '—', '0B', '+1.5KB']);
});

test('문자열 키', () => {
  for (const k of ['combobox.clear', 'combobox.no_match', 'dashboard.trend_limit_button', 'admin_tools.node_refreshing', 'admin_tools.node_reason.node_timeout', 'upload.previous_same']) assert.ok(has(k), k);
});
