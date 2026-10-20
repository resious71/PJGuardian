// FAQ 화면 순수 함수(P29B): 항목 주소·분류 묶음·순서 바꾸기 계산·편집 검증(길이·필수·공백)·바뀜 판단·답변 링크 규칙·검색 상태,
// 안전한 마크다운(FAQ 답변 렌더 — <script>·onerror·javascript:·data:·이미지 태그가 요소로 만들어지지 않음), 문구 ko·en.
import test from 'node:test';
import assert from 'node:assert/strict';
import { LIMITS, anchorId, idFromHash, groupByCategory, moveIds, validateDraft, charCount, isDirty, faqHref, searchActive, oneLine } from '../../public/assets/js/lib/faq_core.js';
import { renderMarkdown } from '../../public/assets/js/lib/markdown.js';
import ko from '../../public/assets/js/i18n/ko.js';
import en from '../../public/assets/js/i18n/en.js';
import { FakeDocument } from './fake-dom.mjs';

test('항목 주소와 분류 묶음(서버 순서 그대로)', () => {
  assert.equal(anchorId(12), 'faq-12');
  assert.equal(idFromHash('#faq-12'), 12);
  for (const h of ['', '#', '#faq-', '#faq-0', '#faq-1a', '#faq-12345678901', '#other']) assert.equal(idFromHash(h), null, h);
  const g = groupByCategory([{ id: 1, category: '업로드' }, { id: 2, category: '업로드' }, { id: 3, category: '계정' }]);
  assert.deepEqual(g.map((x) => [x.category, x.items.map((i) => i.id)]), [['업로드', [1, 2]], ['계정', [3]]]);
  assert.deepEqual(groupByCategory([]), []);
});

test('순서 바꾸기 계산: 같은 분류 안 한 칸, 끝에서는 null(단추 끔)', () => {
  assert.deepEqual(moveIds([1, 2, 3], 2, 'up'), [2, 1, 3]);
  assert.deepEqual(moveIds([1, 2, 3], 2, 'down'), [1, 3, 2]);
  assert.equal(moveIds([1, 2, 3], 1, 'up'), null);
  assert.equal(moveIds([1, 2, 3], 3, 'down'), null);
  assert.equal(moveIds([1, 2, 3], 9, 'up'), null);
  const ids = [1, 2, 3];
  moveIds(ids, 2, 'up');
  assert.deepEqual(ids, [1, 2, 3], '원본을 바꾸지 않음');
});

test('편집 검증: 필수·길이(코드 포인트)·공백 정리, 바뀜 판단', () => {
  assert.deepEqual(validateDraft({ category: '업로드', question: '질문', answer: '답' }), []);
  assert.deepEqual(validateDraft({ category: '   ', question: '', answer: '\n\n' }).map((e) => `${e.field}:${e.kind}`), ['category:required', 'question:required', 'answer:required']);
  const long = validateDraft({ category: '가'.repeat(41), question: 'a'.repeat(201), answer: 'b'.repeat(10001) });
  assert.deepEqual(long.map((e) => [e.field, e.kind, e.max]), [['category', 'too_long', 40], ['question', 'too_long', 200], ['answer', 'too_long', 10000]]);
  assert.deepEqual(validateDraft({ category: `  ${'가'.repeat(40)}  `, question: '😀'.repeat(200), answer: 'b'.repeat(10000) }), [], '상한 딱 맞음(이모지 = 1글자)');
  assert.equal(charCount('😀가a'), 3);
  assert.equal(oneLine('  a\t b\n c  '), 'a b c');
  assert.deepEqual(LIMITS, { category: 40, question: 200, answer: 10000 });
  assert.equal(isDirty(null, { category: '', question: '', answer: '', is_published: true }), false, '빈 추가 대화상자');
  assert.equal(isDirty(null, { category: 'x', question: '', answer: '', is_published: true }), true);
  const o = { category: 'c', question: 'q', answer: 'a', is_published: false };
  assert.equal(isDirty(o, { ...o }), false);
  assert.equal(isDirty(o, { ...o, is_published: true }), true, '공개 여부도 변경');
});

test('답변 링크: 앱 안 상대 주소만 내부 링크, 나머지는 렌더러 기본(https·http만)', () => {
  assert.equal(faqHref('/projects', '/pjg'), '/pjg/projects');
  assert.equal(faqHref('/guide/user#upload'), '/guide/user#upload');
  for (const h of ['//evil.example/x', 'javascript:alert(1)', 'data:text/html,x', 'https://example.com', 'guide:upload', '/a b', '/x"y']) assert.equal(faqHref(h), null, h);
  assert.equal(searchActive('', ''), false);
  assert.equal(searchActive('  ', ''), false);
  assert.equal(searchActive('zip', ''), true);
  assert.equal(searchActive('', '업로드'), true);
});

/** 렌더 결과 트리의 모든 요소 */
function elements(nodes) {
  const out = [];
  const walk = (n) => {
    if (n && n.tagName) {
      out.push(n);
      for (const c of n.childNodes ?? []) walk(c);
    }
  };
  for (const n of nodes) walk(n);
  return out;
}

test('FAQ 답변 마크다운은 안전: 원시 HTML·이벤트 속성·위험 링크·이미지가 요소가 되지 않음', () => {
  const doc = new FakeDocument();
  const opts = { headingBase: 4, link: (h) => faqHref(h, '') };
  const src = [
    '<script>alert(1)</script>',
    '<img src=x onerror=alert(1)>',
    '[나쁜](javascript:alert(1)) [데이터](data:text/html;base64,PHNjcmlwdD4=) [다른 호스트](//evil.example/x)',
    '![그림](https://example.com/a.png)',
    '[외부](https://example.com/docs) [앱](/projects)',
    '```',
    '<b>코드</b>',
    '```',
  ].join('\n\n');
  const nodes = renderMarkdown(src, opts, doc);
  const els = elements(nodes);
  const tags = els.map((e) => e.tagName);
  assert.ok(!tags.includes('SCRIPT') && !tags.includes('IMG') && !tags.includes('B'), tags.join(','));
  for (const e of els) {
    for (const [name] of e.attributes) assert.ok(!/^on/i.test(name), `${e.tagName} ${name}`);
  }
  const links = els.filter((e) => e.tagName === 'A').map((a) => [a.getAttribute('href'), a.getAttribute('rel')]);
  assert.deepEqual(links, [['https://example.com/docs', 'noopener noreferrer'], ['/projects', null]], '안전한 링크만');
  const text = nodes.map((n) => n.textContent).join('\n');
  assert.ok(text.includes('<script>alert(1)</script>') && text.includes('javascript:alert(1)') && text.includes('<b>코드</b>'), '위험한 글은 글자 그대로');
});

test('FAQ 문구 ko·en', () => {
  for (const k of ['faq.results', 'faq.no_results', 'faq.empty_user', 'faq.empty_admin', 'faq.add_first', 'faq.copy_link', 'faq.copied', 'faq.move_up_aria', 'faq.move_down_aria',
    'faq.edit', 'faq.delete', 'faq.publish', 'faq.unpublish', 'faq.delete_title', 'faq.delete_message', 'faq.dialog_add', 'faq.dialog_edit', 'faq.field_category', 'faq.field_question',
    'faq.field_answer', 'faq.field_published', 'faq.tab_write', 'faq.tab_preview', 'faq.counter', 'faq.required', 'faq.too_long', 'faq.save', 'faq.discard_title', 'faq.conflict_title',
    'faq.use_latest', 'faq.keep_mine', 'icon.nav.faq', 'icon.action.add', 'icon.action.edit', 'icon.ui.draft']) {
    assert.ok(ko[k] && en[k], k);
  }
});
