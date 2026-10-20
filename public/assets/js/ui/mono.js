// 흑백 점검 모드(명세 20.5): body.mono로 그레이스케일 필터를 씌워 색 없이도 구분되는지 본다.
// 상태는 이 브라우저에만 저장한다(저장이 막혀도 동작).
const KEY = 'pjg-mono';

export function setMono(on, doc = globalThis.document) {
  doc.body.classList.toggle('mono', on);
  for (const b of doc.querySelectorAll('[data-mono-toggle]')) b.setAttribute('aria-pressed', String(on));
  try { globalThis.localStorage?.setItem(KEY, on ? '1' : '0'); } catch { /* 저장 불가 무시 */ }
}

export function initMono(doc = globalThis.document) {
  let saved = false;
  try { saved = globalThis.localStorage?.getItem(KEY) === '1'; } catch { /* 무시 */ }
  if (saved) setMono(true, doc);
  for (const b of doc.querySelectorAll('[data-mono-toggle]')) {
    b.addEventListener('click', () => setMono(!doc.body.classList.contains('mono'), doc));
  }
}
