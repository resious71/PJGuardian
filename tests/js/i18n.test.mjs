// 화면 언어(P27D) 스크립트 쪽: ko.js·en.js 키·자리표시자 일치, 빈 값·영어에 한글 없음, 복수형 형식, 영어 문구 렌더링(알림·백업 오류),
// 헤더 전환의 순수 함수(쿠키 줄·입력 중 판정·테마 적용). PHP 쪽은 tests/Unit/I18nTest.php.
import test from 'node:test';
import assert from 'node:assert/strict';
import ko from '../../public/assets/js/i18n/ko.js';
import en from '../../public/assets/js/i18n/en.js';
import { t, format, useMessages, language } from '../../public/assets/js/core/i18n.js';
import { notificationText } from '../../public/assets/js/lib/notification_core.js';
import { errorMessage } from '../../public/assets/js/lib/backup_core.js';
import { prefCookie, applyTheme } from '../../public/assets/js/ui/ui_prefs.js';

const names = (s) => [...new Set([...String(s).matchAll(/\{(\w+)(?::[^{}|]*\|[^{}]*)?\}/g)].map((m) => m[1]))].sort();

test('ko·en 키 집합·자리표시자 같음, 빈 값·한글 없음', () => {
  assert.deepEqual(Object.keys(ko).filter((k) => !(k in en)), [], 'en에 없는 키');
  assert.deepEqual(Object.keys(en).filter((k) => !(k in ko)), [], 'ko에 없는 키');
  const bad = [];
  for (const [k, v] of Object.entries(ko)) {
    if (names(v).join() !== names(en[k]).join()) bad.push(`자리표시자 ${k}`);
    if ((v === '') !== (en[k] === '')) bad.push(`빈 값 ${k}`);
    if (/[가-힣]/.test(en[k])) bad.push(`한글 ${k}`);
  }
  assert.deepEqual(bad, []);
});

test('복수형·자리표시자 형식(PHP Lang::format과 같은 규칙)', () => {
  assert.equal(format('{n} {n:defect|defects}', { n: 1 }), '1 defect');
  assert.equal(format('{n} {n:defect|defects}', { n: '1,234' }), '1,234 defects');
  assert.equal(format('{n} {n:defect|defects}', { n: 0 }), '0 defects');
  assert.equal(format('{n} {n:defect|defects}', {}), '{n} {n:defect|defects}');
  assert.equal(format('결함 {n}건', { n: 3 }), '결함 3건');
});

test('영어 문구 렌더링: 알림·백업 오류(같은 저장 데이터를 두 언어로)', () => {
  const item = { kind: 'analysis_summary', project: { name: 'Pandas' }, params: { run: 5, new: 1, resolved: 9, reopened: 0, mine_resolved: 0, mine_reopened: 0 } };
  const koText = notificationText(item);
  useMessages('en', en);
  try {
    assert.equal(language(), 'en');
    const enText = notificationText(item);
    assert.notEqual(enText, koText);
    assert.ok(!/[가-힣]/.test(enText), enText);
    assert.match(enText, /Pandas/, '프로젝트 이름(사용자 입력)은 그대로');
    assert.match(enText, /\b1 new\b|\bnew 1\b|1 new/i);
    assert.ok(!/[가-힣]/.test(errorMessage('backup_disk_low', { need_mb: 10, free_mb: 1 })));
    assert.equal(t('ui_switch.theme.dark'), 'Dark');
    assert.equal(t('no.such.key'), 'no.such.key');
  } finally {
    useMessages('ko');
  }
  assert.equal(notificationText(item), koText, '되돌리면 한국어');
});

test('헤더 전환: 쿠키 줄(SameSite=Lax·1년·경로), 테마 적용(system이면 속성 없음)', () => {
  const c = prefCookie('pjg_lang', 'en', '/PJGuardian');
  assert.match(c, /^pjg_lang=en; Path=\/PJGuardian; Max-Age=31536000; SameSite=Lax/);
  assert.match(prefCookie('pjg_theme', 'dark', ''), /Path=\/;/);
  const attrs = new Map();
  const root = { setAttribute: (k, v) => attrs.set(k, v), removeAttribute: (k) => attrs.delete(k) };
  applyTheme('dark', root);
  assert.equal(attrs.get('data-theme'), 'dark');
  applyTheme('light', root);
  assert.equal(attrs.get('data-theme'), 'light');
  applyTheme('system', root);
  assert.equal(attrs.has('data-theme'), false);
});
