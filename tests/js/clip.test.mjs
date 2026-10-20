// 긴 셀 말줄임·툴팁(P21 0C): 경로 나누기(파일명:줄은 항상 끝 조각), 텍스트로만 넣기, 잘림 판정, 화면 가장자리 위치 보정.
import test from 'node:test';
import assert from 'node:assert/strict';
import { splitPath, clipText, isTruncated, tipPosition } from '../../public/assets/js/ui/clip.js';
import { FakeDocument } from './fake-dom.mjs';

const doc = new FakeDocument();

test('경로: 앞(폴더)과 끝(파일명:줄)으로 나눈다', () => {
  assert.deepEqual(splitPath('src/pages/report/Summary.js:128'), { head: 'src/pages/report/', tail: 'Summary.js:128' });
  assert.deepEqual(splitPath('main.py:3'), { head: '', tail: 'main.py:3' });
  assert.deepEqual(splitPath('a/b/'), { head: 'a/b/', tail: '' });
});

test('셀: 전체 값이 DOM에 텍스트로(복사·화면 읽기), HTML은 해석하지 않음, title 없음', () => {
  const p = clipText('src/<b>x</b>/f.py:9', { kind: 'path', focusable: false }, doc);
  assert.equal(p.textContent, 'src/<b>x</b>/f.py:9');
  assert.equal(p.getAttribute('tabindex'), null, '링크 안이면 링크가 포커스를 받는다');
  assert.equal(p.childNodes[1].textContent, 'f.py:9');
  assert.equal(p.getAttribute('title'), null);
  const m = clipText('<img src=x onerror=alert(1)> 메시지', {}, doc);
  assert.equal(m.getAttribute('tabindex'), '0', '키보드 포커스');
  assert.equal(m.dataset.clip, 'text');
  assert.ok(m.childNodes.every((c) => c.nodeType === 3));
});

test('잘림 판정: 넘치면 참, 아니면 거짓, 크기를 모르면 참', () => {
  const n = { dataset: { clip: 'text' }, scrollWidth: 300, clientWidth: 200 };
  assert.equal(isTruncated(n), true);
  assert.equal(isTruncated({ ...n, scrollWidth: 200 }), false);
  assert.equal(isTruncated({ dataset: { clip: 'text' } }), true);
  const head = { scrollWidth: 50, clientWidth: 50 };
  assert.equal(isTruncated({ dataset: { clip: 'path' }, querySelector: () => head }), false, '경로는 앞부분 기준');
  // P21U: 칸이 아주 좁아 파일명:줄까지 줄었으면(앞부분은 비었거나 다 맞아도) 잘린 것
  const parts = { '.clip__head': { textContent: '', scrollWidth: 0, clientWidth: 0 }, '.clip__tail': { textContent: 'very_long_name.py:9', scrollWidth: 160, clientWidth: 120 } };
  assert.equal(isTruncated({ dataset: { clip: 'path' }, querySelector: (s) => parts[s] }), true, '파일명:줄이 잘림');
  parts['.clip__tail'].scrollWidth = 120;
  assert.equal(isTruncated({ dataset: { clip: 'path' }, querySelector: (s) => parts[s] }), false, '모두 보임(빈 앞부분은 보지 않음)');
});

test('툴팁 위치: 아래 우선, 공간이 없으면 위, 좌우는 화면 안으로', () => {
  const view = { width: 800, height: 600 };
  assert.deepEqual(tipPosition({ left: 100, top: 100, bottom: 120 }, { width: 200, height: 40 }, view), { left: 100, top: 126 });
  assert.deepEqual(tipPosition({ left: 700, top: 100, bottom: 120 }, { width: 300, height: 40 }, view), { left: 492, top: 126 }, '오른쪽 끝');
  assert.deepEqual(tipPosition({ left: 100, top: 560, bottom: 580 }, { width: 200, height: 60 }, view), { left: 100, top: 494 }, '아래 공간 없음 → 위');
});
