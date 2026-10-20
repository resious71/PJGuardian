// CodeMirror 6 번들 진입점(P17, 명세 7). 코드 뷰어(public/assets/js/lib/code_viewer.js)가 쓰는 것만 내보낸다.
// 편집 기능(자동 완성, 검색·바꾸기, 실행 취소 등)은 넣지 않는다: 뷰어는 읽기 전용이다.
// 빌드: node tools/build_vendor.mjs → public/assets/vendor/codemirror/codemirror.js (docs/vendor_build.md)
export { EditorState, StateField, RangeSetBuilder, Compartment } from '@codemirror/state';
export { EditorView, Decoration, lineNumbers, highlightSpecialChars, drawSelection } from '@codemirror/view';
export { syntaxHighlighting, defaultHighlightStyle, HighlightStyle, bracketMatching, indentUnit } from '@codemirror/language';
export { python } from '@codemirror/lang-python';
export { javascript } from '@codemirror/lang-javascript';
export { tags } from '@lezer/highlight';
