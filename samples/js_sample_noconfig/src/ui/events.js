// 이벤트 처리
export function bind(button, handler) {
  button.addEventListener('click', function (event) {
    handler(event, event);
  });
}

export function compare(a, b) {
  return a != b;
}
