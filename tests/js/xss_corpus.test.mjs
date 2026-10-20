// 출력 이스케이프 — 브라우저 쪽(P31 A-2). 같은 문자열 모음(tests/Support/xss_corpus.json)을 PHP 시험(XssCorpusTest)과 함께 쓴다.
// 1) 정적 검사: 앱 JS(public/assets/js, vendor 제외)에 HTML 문자열을 해석하는 API가 없다(innerHTML·outerHTML·insertAdjacentHTML·document.write·
//    eval·new Function·setTimeout 문자열·srcdoc). 화면은 el()/textContent로만 그린다.
// 2) el()은 위험한 속성·주소를 거부한다. 3) 마크다운(FAQ·가이드)은 모음의 링크·태그를 글자로만 남기고 안전한 주소만 링크로.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { el } from '../../public/assets/js/core/dom.js';
import { renderMarkdown, safeHref } from '../../public/assets/js/lib/markdown.js';
import { FakeDocument } from './fake-dom.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const corpus = JSON.parse(readFileSync(path.join(ROOT, 'tests/Support/xss_corpus.json'), 'utf8'));

function jsFiles(dir) {
  return readdirSync(dir).flatMap((n) => {
    const p = path.join(dir, n);
    if (statSync(p).isDirectory()) return n === 'vendor' ? [] : jsFiles(p);
    return n.endsWith('.js') ? [p] : [];
  });
}

test('앱 JS에 HTML 문자열을 해석하는 API가 없다(주석 제외)', () => {
  const banned = [
    [/\.innerHTML\s*[+]?=/, 'innerHTML 대입'], [/\.outerHTML\s*[+]?=/, 'outerHTML 대입'], [/insertAdjacentHTML\s*\(/, 'insertAdjacentHTML'],
    [/document\.write(ln)?\s*\(/, 'document.write'], [/\beval\s*\(/, 'eval'], [/new\s+Function\s*\(/, 'new Function'],
    [/set(Timeout|Interval)\s*\(\s*['"`]/, '문자열 타이머'], [/\.srcdoc\s*=/, 'srcdoc'], [/createContextualFragment\s*\(/, 'createContextualFragment'],
    [/DOMParser\s*\(\s*\)\s*\.parseFromString/, 'DOMParser'],
  ];
  const files = jsFiles(path.join(ROOT, 'public/assets/js'));
  assert.ok(files.length > 40, `파일 ${files.length}`);
  const found = [];
  for (const f of files) {
    const code = readFileSync(f, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '').split('\n').map((l) => l.replace(/(^|[^:'"`])\/\/.*$/, '$1')).join('\n');
    for (const [re, name] of banned) if (re.test(code)) found.push(`${path.relative(ROOT, f)}: ${name}`);
  }
  assert.deepEqual(found, []);
});

test('el()은 모음 문자열을 글자로만 넣고 위험한 속성·주소를 거부', () => {
  const doc = new FakeDocument();
  for (const s of corpus.html) {
    const n = el('p', { title: s, text: s }, [], doc);
    assert.equal(n.textContent, s, '글자 그대로');
    assert.equal(n.children?.length ?? 0, 0, '자식 요소가 생기지 않음');
  }
  assert.throws(() => el('a', { href: 'javascript:alert(1)' }, [], doc));
  assert.throws(() => el('a', { href: ' JaVaScRiPt:alert(1)' }, [], doc));
  assert.throws(() => el('div', { onclick: 'alert(1)' }, [], doc));
  assert.throws(() => el('iframe', { srcdoc: '<script>alert(1)</script>' }, [], doc));
});

test('마크다운: 모음의 위험한 링크·태그는 글자로만, 안전한 주소만 링크', () => {
  const doc = new FakeDocument();
  for (const md of [...corpus.markdown, ...corpus.html]) {
    const nodes = renderMarkdown(md, {}, doc);
    const all = [];
    const walk = (n) => { if (n.tagName) all.push(n); for (const c of n.children ?? n.childNodes ?? []) walk(c); };
    nodes.forEach(walk);
    for (const e of all) {
      assert.ok(!['SCRIPT', 'IMG', 'IFRAME', 'SVG', 'STYLE', 'OBJECT', 'EMBED'].includes(e.tagName), `${md} → ${e.tagName}`);
      for (const [name, value] of e.attributes) {
        assert.ok(!/^on/i.test(name) && name !== 'srcdoc', `${md} → ${name}`);
        if (name === 'href') assert.ok(/^(https?:\/\/|\/(?!\/)|#)/.test(value), `${md} → href ${value}`);
      }
    }
  }
  for (const u of ['javascript:alert(1)', 'JaVaScRiPt:alert(1)', ' javascript:x', 'vbscript:x', 'data:text/html,x', '//evil.example/x', 'java\tscript:x']) {
    assert.equal(safeHref(u), null, u);
  }
});
