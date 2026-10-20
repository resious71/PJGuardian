// 읽기 전용 코드 뷰어(CodeMirror 6, 로컬 번들, 명세 7, P17).
// - 코드는 CodeMirror 문서(텍스트)로만 넣는다. HTML로 해석하지 않으므로 코드 안의 <script>·엔티티는 글자 그대로 보인다.
// - 줄 번호는 원본 파일 기준(발췌·일부만 받았으면 시작 줄부터), 결함 줄은 배경색 + 열 범위 밑줄, 그 줄로 스크롤.
// - CSP(style-src 'self' 'nonce-…'): CodeMirror가 넣는 <style>에 요청마다 다른 nonce를 붙인다(EditorView.cspNonce). unsafe-inline 없음.
import {
  EditorState, EditorView, Decoration, Compartment, RangeSetBuilder, lineNumbers, highlightSpecialChars,
  syntaxHighlighting, HighlightStyle, tags, python, javascript,
} from '../../vendor/codemirror/codemirror.js';
import { languageForPath, defectRange, displayLineNumber } from './code_view_core.js';

// 문법 색(P27D): CodeMirror defaultHighlightStyle과 같은 구분을 CSS 토큰(--code-*)으로 — 라이트는 기본과 같은 색, 다크는 tokens.css의 다크 값
const tokenHighlight = HighlightStyle.define([
  { tag: tags.meta, color: 'var(--code-meta)' },
  { tag: tags.link, textDecoration: 'underline' },
  { tag: tags.heading, textDecoration: 'underline', fontWeight: 'bold' },
  { tag: tags.emphasis, fontStyle: 'italic' },
  { tag: tags.strong, fontWeight: 'bold' },
  { tag: tags.strikethrough, textDecoration: 'line-through' },
  { tag: tags.keyword, color: 'var(--code-keyword)' },
  { tag: [tags.atom, tags.bool, tags.url, tags.contentSeparator, tags.labelName], color: 'var(--code-atom)' },
  { tag: [tags.literal, tags.inserted], color: 'var(--code-literal)' },
  { tag: [tags.string, tags.deleted], color: 'var(--code-string)' },
  { tag: [tags.regexp, tags.escape, tags.special(tags.string)], color: 'var(--code-regexp)' },
  { tag: tags.definition(tags.variableName), color: 'var(--code-def)' },
  { tag: tags.local(tags.variableName), color: 'var(--code-local)' },
  { tag: [tags.typeName, tags.namespace], color: 'var(--code-type)' },
  { tag: tags.className, color: 'var(--code-class)' },
  { tag: [tags.special(tags.variableName), tags.macroName], color: 'var(--code-special)' },
  { tag: tags.definition(tags.propertyName), color: 'var(--code-property)' },
  { tag: tags.comment, color: 'var(--code-comment)' },
  { tag: tags.invalid, color: 'var(--code-invalid)' },
]);

function languageExtension(path) {
  switch (languageForPath(path)) {
    case 'python': return python();
    case 'javascript': return javascript();
    case 'jsx': return javascript({ jsx: true });
    case 'typescript': return javascript({ typescript: true });
    case 'tsx': return javascript({ jsx: true, typescript: true });
    default: return [];
  }
}

/** 결함 줄(배경)과 열 범위(밑줄) 장식 */
function defectDecorations(doc, range) {
  const b = new RangeSetBuilder();
  if (!range) return b.finish();
  const marks = [];
  for (let n = range.fromLine; n <= range.toLine; n++) {
    const line = doc.line(n);
    marks.push({ from: line.from, to: line.from, deco: Decoration.line({ class: 'cm-defect-line' }) });
    const start = n === range.fromLine && range.fromCol != null ? Math.min(line.from + range.fromCol, line.to) : line.from;
    const end = n === range.toLine && range.toCol != null ? Math.min(line.from + range.toCol, line.to) : line.to;
    if (end > start) marks.push({ from: start, to: end, deco: Decoration.mark({ class: 'cm-defect-mark' }) });
  }
  marks.sort((a, b2) => a.from - b2.from || (a.to === a.from ? -1 : 1));
  for (const m of marks) b.add(m.from, m.to, m.deco);
  return b.finish();
}

/**
 * @param {HTMLElement} host
 * @param {object} data 코드 API 응답(source가 none이 아닌 것)
 * @param {{ nonce?: string, wrap?: boolean, label?: string }} opts
 */
export function createCodeViewer(host, data, { nonce = '', wrap = false, label = '' } = {}) {
  const wrapping = new Compartment();
  const doc = data.lines.join('\n');
  const state = EditorState.create({
    doc,
    extensions: [
      EditorState.readOnly.of(true),
      lineNumbers({ formatNumber: (n) => String(displayLineNumber(n, data.start_line)) }),
      highlightSpecialChars(),
      syntaxHighlighting(tokenHighlight, { fallback: true }),
      languageExtension(data.file_path),
      wrapping.of(wrap ? EditorView.lineWrapping : []),
      EditorView.decorations.of((view) => defectDecorations(view.state.doc, defectRange(data))),
      EditorView.contentAttributes.of({ 'aria-label': label, 'aria-readonly': 'true' }),
      nonce ? EditorView.cspNonce.of(nonce) : [],
    ],
  });
  const view = new EditorView({ state, parent: host });
  const range = defectRange(data);
  if (range) {
    const pos = view.state.doc.line(range.fromLine).from;
    view.dispatch({ effects: EditorView.scrollIntoView(pos, { y: 'center' }) });
  }
  return {
    view,
    setWrap(on) {
      view.dispatch({ effects: wrapping.reconfigure(on ? EditorView.lineWrapping : []) });
    },
    destroy() {
      view.destroy();
    },
  };
}
