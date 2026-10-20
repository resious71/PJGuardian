// 화면 렌더링
export function render(target, text) {
  if (text === '') {
    return;
  }
  target.textContent = text;
}

export function clear(target) {
  target.textContent = '';
  return;
  target.hidden = true;
}
