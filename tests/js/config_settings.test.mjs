// 설정 관리(P27A) 화면 로직: 입력 확인(기간·정수·비율·경로), 변경 목록(같은 값·표기만 다른 기간 제외), 반영 상태, 검색, 출처·경로 결과 문장,
// 그리고 서버 허용 목록(ConfigSchema::EDITABLE)의 모든 키에 라벨·도움말 문자열이 있는지.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
  durationSeconds, checkInput, sameValue, pendingChanges, statusOf, matches, sourceText, pathResultText, pathOk, labelOf, helpOf, rangeOf, formatBytes,
  valueLabel, boolText, inactiveReason, groupLabel, tabsFrom, tabFromHash, tabOfKey, countByTab, firstKeyByTab, searchCounts, searchHint, tabAccessibleName,
  mailPlaintext, mailMissingText, mailStatusText, mailTestBlock, mailTestResultText,
} from '../../public/assets/js/lib/config_settings_core.js';
import { t, has } from '../../public/assets/js/core/i18n.js';

const item = (over = {}) => ({ key: 'disk.warn_percent', group: 'disk', type: 'percent', value: '80', min: '0', max: '100', restart: 'none', ...over });

test('기간 해석', () => {
  assert.equal(durationSeconds('30m'), 1800);
  assert.equal(durationSeconds('4h'), 14400);
  assert.equal(durationSeconds('7d'), 604800);
  assert.equal(durationSeconds('90'), 90);
  assert.equal(durationSeconds('1.5h'), null);
  assert.equal(durationSeconds('abc'), null);
});

test('입력 확인: 정수·비율 범위, 기간 범위, 경로 형식', () => {
  assert.equal(checkInput(item(), '75'), null);
  assert.match(checkInput(item(), '101'), /범위 0 ~ 100/);
  assert.notEqual(checkInput(item(), '7.5'), null);
  assert.notEqual(checkInput(item(), ''), null);
  const d = item({ key: 'scheduler.tick_seconds', type: 'duration', value: '1m', min: '10s', max: '1h' });
  assert.equal(checkInput(d, '30s'), null);
  assert.equal(checkInput(d, '2m'), null);
  assert.notEqual(checkInput(d, '5s'), null);
  assert.notEqual(checkInput(d, '2h'), null);
  assert.notEqual(checkInput(d, '1분'), null);
  const p = item({ key: 'test.dummy_dir', type: 'path', value: '', min: null, max: null });
  assert.equal(checkInput(p, 'D:\\backups'), null);
  assert.notEqual(checkInput(p, 'a"b'), null);
  assert.notEqual(checkInput(p, 'x'.repeat(201)), null);
});

test('변경 목록: 지금 값과 같거나 표기만 다른 기간은 빠진다', () => {
  const items = [item(), item({ key: 'analysis.timeout', type: 'duration', value: '4h', min: '1s', max: '1d' }), item({ key: 'limits.project_defect_max', type: 'int', value: '1000000', min: '0', max: '100000000' })];
  const changes = pendingChanges(items, { 'disk.warn_percent': ' 80 ', 'analysis.timeout': '240m', 'limits.project_defect_max': '500000' });
  assert.deepEqual(changes.map((c) => [c.key, c.before, c.after]), [['limits.project_defect_max', '1000000', '500000']]);
  assert.equal(sameValue(items[1], '4h', '14400'), true);
  assert.equal(sameValue(items[0], '80', '81'), false);
});

test('반영 상태: 재시작 필요 > 워커 꺼짐 > 다시 읽기 대기 > 적용됨', () => {
  const v = 'abc';
  assert.equal(statusOf(item(), { running: true, version: v, pending_restart: [] }, v), 'applied');
  assert.equal(statusOf(item(), { running: true, version: 'old', pending_restart: [] }, v), 'pending_reload');
  assert.equal(statusOf(item(), { running: false, version: null, pending_restart: [] }, v), 'worker_off');
  const w = item({ key: 'test.dummy_worker', restart: 'worker' });
  assert.equal(statusOf(w, { running: true, version: v, pending_restart: ['test.dummy_worker'] }, v), 'restart');
  assert.equal(statusOf(w, { running: true, version: v, pending_restart: [] }, v), 'applied');
  assert.equal(statusOf(item(), { running: true, version: '', pending_restart: [] }, ''), 'applied', '화면 설정 파일 없음 = 빈 버전');
});

test('검색: 이름·설명·키·묶음 이름, 모든 낱말', () => {
  const it = item();
  assert.equal(matches(it, ''), true);
  assert.equal(matches(it, '디스크 경고'), true);
  assert.equal(matches(it, 'warn_percent'), true);
  assert.equal(matches(it, 'DISK'), true);
  assert.equal(matches(it, '디스크 시간'), false);
});

test('출처·경로 결과 문장', () => {
  assert.equal(sourceText('screen'), '화면 설정');
  assert.equal(sourceText('env'), '환경 변수');
  assert.equal(sourceText('config'), 'config.ini');
  assert.equal(sourceText('default'), '기본값');
  const ok = { code: 'ok', path: 'E:\\backups', free_bytes: 1610612736, created: false };
  assert.equal(pathResultText(ok, formatBytes), '확인됨: E:\\backups — 쓰기 시험 통과(여유 1.5 GB)');
  assert.equal(pathOk(ok), true);
  assert.equal(pathOk({ code: 'warn_same_volume' }), true);
  assert.equal(pathOk({ code: 'fail_not_found' }), false);
  assert.match(pathResultText({ code: 'ok', path: 'E:\\b', free_bytes: 1024, created: true }, formatBytes), /^폴더를 만들었습니다\. 확인됨/);
  for (const code of ['ok', 'warn_same_volume', 'fail_not_absolute', 'fail_forbidden_location', 'fail_not_found', 'fail_not_writable', 'fail_too_long', 'fail_unc_unreachable']) {
    assert.ok(has(`config_settings.path.${code}`), code);
  }
});

test('허용 목록의 모든 키에 라벨·도움말, 묶음 이름', () => {
  const php = readFileSync(new URL('../../src/Support/ConfigSchema.php', import.meta.url), 'utf8');
  const block = php.slice(php.indexOf('public const EDITABLE = ['), php.indexOf('public const EDITABLE_GROUPS'));
  const keys = [...block.matchAll(/'([a-z_]+\.[a-z_]+)' => \['group' => '([a-z_]+)'/g)].map((m) => [m[1], m[2]]);
  assert.equal(keys.length, 103); // X21 분석 탭 업로드·해제 한도 3개(의도된 단언 변경) + P32B 신뢰 프록시 1개·유지보수 3개(의도된 단언 변경) + P30B 관리자 이메일 13개 + P27A 22개 + P27B 백업 9개 + P27E 자동 백업 5개 + P28B 계정 승인 2개·LDAP/AD 14개 + P28C SSO(OIDC) 14개 + P28D SSO(SAML) 14개 + P30 감사 로그 보존·헬스체크 허용 IP·큐 정체 3개(의도된 단언 변경)
  for (const [key, group] of keys) {
    assert.ok(has(`config_settings.key.${key}.label`), `${key} label`);
    assert.ok(has(`config_settings.key.${key}.help`), `${key} help`);
    assert.ok(has(`config_settings.group.${group}`), group);
    assert.notEqual(labelOf({ key }), key);
    assert.notEqual(helpOf({ key }), '');
  }
  assert.equal(labelOf({ key: 'test.dummy_dir' }), 'test.dummy_dir', '라벨이 없으면 키');
  assert.equal(rangeOf({ min: '10s', max: '1h' }), t('config_settings.range', { min: '10s', max: '1h' }));
  assert.equal(rangeOf({ min: null, max: null }), '');
});

test('P27E 선택 목록·켜기/끄기: 값 표시 이름, 형식 확인, 지금 방식에 쓰이지 않는 항목', () => {
  const mode = { key: 'backup.schedule_mode', type: 'enum', values: ['daily', 'weekly', 'monthly'], min: null, max: null };
  const day = { key: 'backup.monthly_day', type: 'enum', values: [...Array.from({ length: 31 }, (_, i) => String(i + 1)), 'last'], min: null, max: null };
  const on = { key: 'backup.enabled', type: 'bool', min: null, max: null };
  assert.equal(valueLabel(mode, 'weekly'), '매주(일주일)');
  assert.equal(valueLabel(day, 'last'), '말일');
  assert.equal(valueLabel(day, '31'), '31일');
  assert.equal(valueLabel({ key: 'x.y' }, 'raw'), 'raw', '이름이 없으면 값 그대로');
  assert.equal(valueLabel({ key: 'backup.weekly_day' }, 'sun'), '일요일');
  assert.equal(checkInput(mode, 'weekly'), null);
  assert.notEqual(checkInput(mode, 'hourly'), null);
  assert.notEqual(checkInput(day, '32'), null);
  assert.equal(checkInput(on, 'false'), null);
  assert.notEqual(checkInput(on, 'maybe'), null);
  assert.equal(boolText('true'), '켜짐');
  assert.equal(boolText('false'), '꺼짐');
  const values = (v) => (k) => v[k];
  const daily = values({ 'backup.enabled': 'true', 'backup.schedule_mode': 'daily' });
  assert.equal(inactiveReason('backup.weekly_day', daily), 'config_settings.inactive.weekly_day');
  assert.equal(inactiveReason('backup.monthly_day', daily), 'config_settings.inactive.monthly_day');
  assert.equal(inactiveReason('backup.daily_at', daily), null, '실행 시각은 세 방식 모두 씀');
  assert.equal(inactiveReason('backup.weekly_day', values({ 'backup.enabled': 'true', 'backup.schedule_mode': 'weekly' })), null);
  assert.equal(inactiveReason('backup.monthly_day', values({ 'backup.enabled': 'true', 'backup.schedule_mode': 'monthly' })), null);
  const off = values({ 'backup.enabled': 'false', 'backup.schedule_mode': 'weekly' });
  for (const k of ['backup.schedule_mode', 'backup.daily_at', 'backup.weekly_day', 'backup.monthly_day']) assert.equal(inactiveReason(k, off), 'config_settings.inactive.auto_off', k);
  for (const k of ['backup.enabled', 'backup.retention_days', 'backup.retention_max_count', 'disk.warn_percent']) assert.equal(inactiveReason(k, off), null, `${k}는 늘 씀`);
});

// ---- P27F 카테고리 탭 ----
const GROUPS = ['retention', 'disk', 'notification', 'schedule', 'repository', 'analysis', 'backup'];
const tabItems = [
  item({ key: 'upload.failed_zip_retention', group: 'retention', type: 'duration', value: '1d' }),
  item({ key: 'disk.warn_percent', group: 'disk' }),
  item({ key: 'disk.block_percent', group: 'disk', value: '95' }),
  item({ key: 'analysis.timeout', group: 'analysis', type: 'duration', value: '4h', min: '1s', max: '1d' }),
  item({ key: 'analysis.max_concurrent', group: 'analysis', type: 'int', value: '2', min: '1', max: '16' }),
  item({ key: 'backup.dir', group: 'backup', type: 'path', value: '', min: null, max: null }),
];

test('탭 모델: 등록부 묶음 순서, 항목 없는 묶음은 탭 없음, 새 묶음은 탭이 늘어남', () => {
  const tabs = tabsFrom(GROUPS, tabItems);
  assert.deepEqual(tabs.map((x) => x.id), ['retention', 'disk', 'analysis', 'backup'], '항목 없는 notification·schedule·repository는 빠짐');
  assert.deepEqual(tabs.map((x) => x.label), ['보존 기간', '디스크', '분석', '백업']);
  assert.deepEqual(tabs.find((x) => x.id === 'analysis').keys, ['analysis.timeout', 'analysis.max_concurrent'], '항목 순서 = 응답 순서');
  const more = tabsFrom([...GROUPS, 'test'], [...tabItems, item({ key: 'test.dummy_dir', group: 'test', type: 'path' })]);
  assert.deepEqual(more.map((x) => x.id), ['retention', 'disk', 'analysis', 'backup', 'test'], '새 묶음 = 마지막 탭');
  assert.equal(more.at(-1).label, 'test', '이름이 없는 묶음은 id');
  assert.equal(groupLabel('schedule'), '주기 분석·가져오기');
  // 모든 키가 정확히 한 탭에
  const all = more.flatMap((x) => x.keys);
  assert.equal(new Set(all).size, all.length);
  assert.equal(all.length, tabItems.length + 1);
  assert.deepEqual(tabsFrom(GROUPS, []), [], '항목이 없으면 탭도 없음');
});

test('해시 해석: #묶음, 예전 #group-묶음, 없거나 모르는 값은 첫 탭', () => {
  const tabs = tabsFrom(GROUPS, tabItems);
  assert.equal(tabFromHash('#backup', tabs), 'backup');
  assert.equal(tabFromHash('backup', tabs), 'backup');
  assert.equal(tabFromHash('#group-backup', tabs), 'backup', 'P27B·P27E 예전 링크');
  assert.equal(tabFromHash('#group-analysis', tabs), 'analysis');
  assert.equal(tabFromHash('', tabs), 'retention');
  assert.equal(tabFromHash('#zzz', tabs), 'retention');
  assert.equal(tabFromHash('#notification', tabs), 'retention', '항목이 없어 탭이 없는 묶음 = 모르는 값');
  assert.equal(tabFromHash('#%E0%A4%A', tabs), 'retention', '잘못된 인코딩');
  assert.equal(tabFromHash(undefined, tabs), 'retention');
  assert.equal(tabFromHash('#x', []), null);
  assert.equal(tabOfKey(tabs, 'disk.block_percent'), 'disk');
  assert.equal(tabOfKey(tabs, 'nope.key'), null);
});

test('탭 머리 계산: 변경 건수·오류 건수, 첫 오류 탭(탭 순서 → 항목 순서)', () => {
  const tabs = tabsFrom(GROUPS, tabItems);
  const changes = pendingChanges(tabItems, { 'analysis.timeout': '2h', 'analysis.max_concurrent': '3', 'disk.warn_percent': '80', 'backup.dir': 'D:\b' });
  assert.deepEqual(countByTab(tabs, changes.map((c) => c.key)), { retention: 0, disk: 0, analysis: 2, backup: 1 }, '같은 값(80)은 변경 아님');
  assert.deepEqual(countByTab(tabs, ['backup.dir', 'disk.block_percent', 'disk.warn_percent']), { retention: 0, disk: 2, analysis: 0, backup: 1 });
  assert.deepEqual(firstKeyByTab(tabs, ['backup.dir', 'analysis.max_concurrent', 'analysis.timeout']), { tab: 'analysis', key: 'analysis.timeout' });
  assert.equal(firstKeyByTab(tabs, []), null);
  assert.equal(firstKeyByTab(tabs, ['nope.key']), null);
  assert.equal(tabAccessibleName(tabs[2], { changes: 2, errors: 1 }), '분석, 변경 2건, 입력 오류 1건');
  assert.equal(tabAccessibleName(tabs[0], { matches: 0 }), '보존 기간, 검색 결과 0건');
  assert.equal(tabAccessibleName(tabs[0]), '보존 기간');
  assert.equal(t('config_settings.tab_error', { n: 1 }), '오류 1');
});

test('검색: 탭별 일치 건수, 지금 탭에 없으면 다른 탭 안내, 모두 없으면 기존 문구', () => {
  const tabs = tabsFrom(GROUPS, tabItems);
  assert.equal(searchCounts(tabs, tabItems, '  '), null, '검색어가 없으면 건수 표시 없음');
  assert.equal(searchHint(tabs, null, 'disk'), null);
  const c = searchCounts(tabs, tabItems, '디스크');
  assert.deepEqual(c, { retention: 0, disk: 2, analysis: 0, backup: 1 }, '백업 폴더 도움말에도 "디스크"');
  assert.equal(searchHint(tabs, c, 'disk'), null, '지금 탭에 있으면 안내 없음');
  assert.deepEqual(searchHint(tabs, c, 'retention'), { kind: 'other', n: 3, tab: 'disk' }, '다른 탭 합계, 첫 일치 탭');
  const both = searchCounts(tabs, tabItems, 'percent');
  assert.deepEqual(both, { retention: 0, disk: 2, analysis: 0, backup: 0 });
  const two = searchCounts(tabs, tabItems, 'max');
  assert.deepEqual(searchHint(tabs, two, 'retention'), { kind: 'other', n: 1, tab: 'analysis' });
  assert.deepEqual(searchHint(tabs, searchCounts(tabs, tabItems, 'zzzz'), 'disk'), { kind: 'none' });
  assert.equal(t('config_settings.search_none'), '찾는 설정이 없습니다.', '결과 없음은 기존 문구 그대로');
});

test('탭 간 상태 유지: 한 폼 상태(edits)로 다른 탭의 변경도 함께 센다 — 탭 선택과 무관', () => {
  const tabs = tabsFrom(GROUPS, tabItems);
  const edits = {};
  edits['analysis.timeout'] = '2h'; // 분석 탭에서 입력
  let current = tabFromHash('#backup', tabs); // 백업 탭으로 이동
  edits['backup.dir'] = 'E:\pjg'; // 백업 탭에서 입력
  current = tabFromHash('#analysis', tabs); // 다시 분석 탭
  assert.equal(current, 'analysis');
  assert.equal(edits['analysis.timeout'], '2h', '탭을 옮겨도 입력 유지');
  const changes = pendingChanges(tabItems, edits);
  assert.deepEqual(changes.map((x) => x.key), ['analysis.timeout', 'backup.dir'], '저장·미리보기는 모든 탭의 변경');
  assert.deepEqual(countByTab(tabs, changes.map((x) => x.key)), { retention: 0, disk: 0, analysis: 1, backup: 1 });
});

test('관리자 이메일 탭(P30B): 평문 경고·발송 상태·시험 단추 막힘 이유·결과 문장', () => {
  const v = (vals) => (k) => vals[k];
  assert.equal(mailPlaintext(v({ 'alert_mail.smtp_tls': 'none' })), true);
  assert.equal(mailPlaintext(v({ 'alert_mail.smtp_tls': 'starttls' })), false);
  assert.equal(inactiveReason('alert_mail.smtp_user', v({ 'alert_mail.smtp_auth': 'none' })), 'config_settings.inactive.mail_auth_none');
  assert.equal(inactiveReason('alert_mail.smtp_user', v({ 'alert_mail.smtp_auth': 'login' })), null);

  const on = { enabled: true, ready: true, state: 'on', missing: [], recipients: 2 };
  const setup = { enabled: true, ready: false, state: 'setup', missing: ['password', 'recipients'], recipients: 0 };
  const off = { enabled: false, ready: false, state: 'off', missing: ['smtp_host'], recipients: 1 };
  assert.equal(mailStatusText(on), t('alert_mail.status.on', { n: 2 }));
  assert.match(mailStatusText(setup), new RegExp(t('alert_mail.missing.password')));
  assert.equal(mailStatusText(off), t('alert_mail.status.off'));
  assert.equal(mailStatusText({ ...off, ready: true, missing: [] }), t('alert_mail.status.ready_off'));
  assert.equal(mailStatusText(null), '');
  assert.equal(mailMissingText(['smtp_host', 'password']), `${t('alert_mail.missing.smtp_host')}, ${t('alert_mail.missing.password')}`);

  // 시험은 저장된 설정으로만: alert_mail.* 저장 전 변경이 있으면 먼저 저장, 다른 탭 변경은 상관없음
  assert.deepEqual(mailTestBlock(on, ['alert_mail.smtp_port']), { key: 'alert_mail.test.unsaved' });
  assert.equal(mailTestBlock(on, ['disk.warn_percent']), null);
  // 받는 사람·마이그레이션은 시험에 필요 없음(서버 sendTest와 같음), 꺼져 있어도 시험은 됨
  assert.equal(mailTestBlock({ ...setup, missing: ['recipients', 'migration'] }, []), null);
  assert.equal(mailTestBlock({ ...off, missing: [] }, []), null);
  assert.deepEqual(mailTestBlock(setup, []), { key: 'alert_mail.test.not_ready', params: { missing: t('alert_mail.missing.password') } });
  assert.equal(mailTestBlock(null, []).key, 'alert_mail.test.not_ready', '상태를 못 읽으면 막음');

  assert.equal(mailTestResultText({ ok: true, code: null }), t('alert_mail.test.ok'));
  for (const code of ['connect', 'tls', 'auth', 'rejected', 'timeout', 'config', 'invalid']) {
    assert.ok(has(`alert_mail.reason.${code}`), code);
    assert.equal(mailTestResultText({ ok: false, code }), t('alert_mail.test.fail', { reason: t(`alert_mail.reason.${code}`) }));
  }
  for (const m of ['migration', 'library', 'smtp_host', 'from_address', 'smtp_user', 'password', 'recipients']) assert.ok(has(`alert_mail.missing.${m}`), m);
});
