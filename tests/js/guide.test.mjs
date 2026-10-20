// 가이드 화면 순수 함수(P29): 검색 강조(대소문자·한글·HTML 주입 없음), 가이드 링크·이미지 주소, 해시, 이전/다음, 렌더러 확장(guide: 링크·앱 경로·이미지·아이콘 자리표시자), 문구 ko·en.
import test from 'node:test';
import assert from 'node:assert/strict';
import { normalizeQuery, highlightSegments, guideHref, imageSlug, sectionFromHash, neighbors, parseHash, headingSlug, plainText, snippetAround } from '../../public/assets/js/lib/guide_core.js';
import { inline } from '../../public/assets/js/lib/markdown.js';
import ko from '../../public/assets/js/i18n/ko.js';
import en from '../../public/assets/js/i18n/en.js';
import { FakeDocument } from './fake-dom.mjs';

test('검색어: 2자 미만은 빈 값, 강조는 글자 조각으로만(HTML 해석 없음)', () => {
  assert.equal(normalizeQuery('a'), '');
  assert.equal(normalizeQuery(' 업로 '), '업로');
  assert.deepEqual(highlightSegments('ZIP 파일과 zip', 'zip'), [{ text: 'ZIP', mark: true }, { text: ' 파일과 ', mark: false }, { text: 'zip', mark: true }]);
  assert.deepEqual(highlightSegments('<b>굵게</b>', '<b>'), [{ text: '<b>', mark: true }, { text: '굵게</b>', mark: false }]);
  assert.deepEqual(highlightSegments('본문', 'x'), [{ text: '본문', mark: false }]);
  assert.deepEqual(highlightSegments('a.*b', '.*'), [{ text: 'a', mark: false }, { text: '.*', mark: true }, { text: 'b', mark: false }], '정규식 아님');
});

test('링크·이미지 주소·해시·이전/다음', () => {
  assert.equal(guideHref('guide:upload', { base: '/pjg', audience: 'user' }), '/pjg/guide/user#upload');
  assert.equal(guideHref('/projects', { base: '/pjg' }), '/pjg/projects');
  for (const bad of ['//evil.example', 'javascript:alert(1)', 'guide:UP', 'http://x']) assert.equal(guideHref(bad), null, bad);
  assert.equal(imageSlug('guide-img:defect-list'), 'defect-list');
  assert.equal(imageSlug('https://x/y.png'), null);
  assert.equal(sectionFromHash('#upload', ['getting-started', 'upload']), 'upload');
  assert.equal(sectionFromHash('#nope', ['upload']), null);
  assert.deepEqual(neighbors(['a', 'b', 'c'], 'a'), { prev: null, next: 'b' });
  assert.deepEqual(neighbors(['a', 'b', 'c'], 'c'), { prev: 'b', next: null });
});

test('렌더러 확장: guide: 링크·이미지·아이콘은 opts가 있을 때만, 없으면 글자 그대로', () => {
  const doc = new FakeDocument();
  const plain = inline('[가](guide:upload) ![그림](guide-img:x) {{icon:action.upload}}', doc);
  assert.ok(plain.every((n) => typeof n === 'string'), '기본은 링크·이미지·아이콘을 만들지 않음');
  const out = inline('[가](guide:upload) ![그림](guide-img:x) {{icon:action.upload}}', doc, {
    link: (h) => (h === 'guide:upload' ? '/guide/user#upload' : null),
    image: (alt, src) => ({ img: [alt, src] }),
    token: (name) => ({ icon: name }),
  });
  const nodes = out.filter((n) => typeof n !== 'string');
  assert.equal(nodes[0].tagName, 'A');
  assert.deepEqual(nodes[1], { img: ['그림', 'guide-img:x'] });
  assert.deepEqual(nodes[2], { icon: 'action.upload' });
});

test('가이드 화면 문구 ko·en', () => {
  for (const k of ['guide.prev', 'guide.next', 'guide.top', 'guide.related', 'guide.search_short', 'guide.search_found', 'guide.search_none', 'guide.hits', 'guide.image_open',
    'guide.translation_pending', 'guide.errors_section_link', 'guide.errors_col.code', 'guide.errors_col.action', 'guide.detail_link', 'help.link']) {
    assert.ok(ko[k] && en[k], k);
  }
});

// X11 절 단위 화면(X10에서 시험 추가): 해시 → 절/소제목, 소제목 slug, 검색 요약
test('해시: 없음·절·소제목·인코딩·깨진 인코딩', () => {
  assert.deepEqual(parseHash(''), { key: '', sub: '' });
  assert.deepEqual(parseHash('#'), { key: '', sub: '' });
  assert.deepEqual(parseHash('#upload'), { key: 'upload', sub: '' });
  assert.deepEqual(parseHash('#no-such-key'), { key: 'no-such-key', sub: '' }, '없는 key도 그대로 — 화면이 첫 절로 바꿈');
  assert.deepEqual(parseHash(`#upload/${encodeURIComponent('올리는-방법')}`), { key: 'upload', sub: '올리는-방법' });
  assert.deepEqual(parseHash('#upload/a/b'), { key: 'upload', sub: 'a/b' });
  assert.deepEqual(parseHash('#%E0%A4%A'), { key: '', sub: '' }, '디코딩 실패는 빈 값(첫 절)');
  assert.equal(sectionFromHash('#upload/올리는-방법', ['upload']), 'upload');
});

test('소제목 slug: 영문·한글·기호·중복·빈 제목', () => {
  const used = new Set();
  assert.equal(headingSlug('How to upload', used), 'how-to-upload');
  assert.equal(headingSlug('올리는 방법', used), '올리는-방법');
  assert.equal(headingSlug('올리는 방법', used), '올리는-방법-2', '같은 절 안 중복');
  assert.equal(headingSlug('올리는 방법', used), '올리는-방법-3');
  assert.equal(headingSlug('  ZIP (파일) — 한도!  ', used), 'zip-파일-한도');
  assert.equal(headingSlug('<b>x</b>', new Set()), 'b-x-b', 'HTML은 글자로만');
  assert.equal(headingSlug('!!!', new Set()), 'section');
  assert.equal(headingSlug('가'.repeat(80), new Set()).length, 60);
  assert.equal(headingSlug('한글', new Set()), '한글'.normalize('NFC'));
});

test('검색 요약: 평문(그림·아이콘·범례 자리·링크·강조 기호 제거)과 일치 앞뒤 자르기', () => {
  const md = '## 제목\n\n![그림](guide-img:x) {{icon:action.upload}} [링크 글](guide:upload)에서 **굵게** `코드`\n\n- 항목 하나\n1. 단계\n\n{{legend:icons}}\n<script>alert(1)</script>';
  const p = plainText(md);
  assert.equal(p, '제목 링크 글에서 굵게 코드 항목 하나 단계 <script>alert(1)</script>');
  assert.ok(!p.includes('guide-img') && !p.includes('{{') && !p.includes('**'));
  assert.equal(snippetAround('가나다라마바사아자차카타파하', '아자', 3), '…마바사아자차카타…');
  assert.equal(snippetAround('ZIP 파일', 'zip', 40), 'ZIP 파일', '앞뒤가 짧으면 … 없음');
  assert.equal(snippetAround('본문', 'x'), '', '일치 없음');
  assert.equal(snippetAround('본문', 'a'), '', '2자 미만');
  const long = `${'앞'.repeat(100)}<b>찾기</b>${'뒤'.repeat(100)}`;
  const s = snippetAround(long, '<b>찾기', 10);
  assert.ok(s.startsWith('…') && s.endsWith('…') && s.includes('<b>찾기') && [...s].length === 10 + [...'<b>찾기'].length + 10 + 2, 'HTML 섞인 입력도 글자로만');
  for (const k of ['guide.prev_none', 'guide.next_none', 'guide.contents', 'guide.unknown_section', 'config_settings.leave_title', 'config_settings.leave_message', 'config_settings.leave_confirm']) {
    assert.ok(ko[k] && en[k], k);
  }
});
