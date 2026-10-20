// 콤보박스 상태 계산(P21C, DOM 없음 — tests/js/combobox.test.mjs). 화면 부품은 ui/combobox.js.
// 규칙: 목록에 있는 값만 고른다(입력한 글자는 값이 되지 않음). 포커스하면 전체 목록, 글자를 치면 그 글자가 든 항목만(대소문자·NFC 무시).
// 한국어 입력(IME) 조합 중에는 Enter로 확정하지 않는다(조합이 끝난 뒤 Enter).

/** 비교용 글자: NFC + 소문자 + 앞뒤 공백 제거 */
export function normalize(s) {
  return String(s ?? '').normalize('NFC').toLowerCase().trim();
}

/** 입력 글자가 든 항목만(빈 입력이면 전체) */
export function filterOptions(options, query) {
  const q = normalize(query);
  return q === '' ? options : options.filter((o) => normalize(o.label).includes(q));
}

const labelOf = (options, value) => options.find((o) => o.value === value)?.label ?? '';

/** options: [{value, label}] (목록 순서 그대로), value: 지금 고른 값 */
export function initialState(options, value) {
  return { options, value, query: labelOf(options, value), typed: false, open: false, active: -1, composing: false };
}

/** 지금 보이는 항목(글자를 치기 전에는 전체) */
export function visible(state) {
  return state.typed ? filterOptions(state.options, state.query) : state.options;
}

function choose(state, item) {
  return { ...state, value: item.value, query: item.label, typed: false, open: false, active: -1, changed: item.value !== state.value };
}

function reset(state) {
  return { ...state, query: labelOf(state.options, state.value), typed: false, open: false, active: -1 };
}

/**
 * 동작 하나를 적용한 새 상태. changed가 true면 값이 바뀐 것(화면은 원래 <select>에 change를 보낸다).
 * action: {type: 'focus'|'input'|'compositionstart'|'compositionend'|'down'|'up'|'home'|'end'|'enter'|'escape'|'blur'|'pick'|'clear'|'options', ...}
 */
export function reduce(state, action) {
  const s = { ...state, changed: false };
  const list = visible(s);
  const indexOfValue = () => list.findIndex((o) => o.value === s.value);
  switch (action.type) {
    case 'focus':
      return { ...s, open: true, typed: false, active: indexOfValue() };
    case 'input': {
      const next = { ...s, query: String(action.text ?? ''), typed: true, open: true };
      return { ...next, active: visible(next).length ? 0 : -1 };
    }
    case 'compositionstart':
      return { ...s, composing: true };
    case 'compositionend':
      return { ...s, composing: false };
    case 'down':
      if (!s.open) return { ...s, open: true, active: Math.max(0, indexOfValue()) };
      return { ...s, active: list.length ? Math.min(list.length - 1, s.active + 1) : -1 };
    case 'up':
      if (!s.open) return { ...s, open: true, active: Math.max(0, indexOfValue()) };
      return { ...s, active: list.length ? Math.max(0, s.active - 1) : -1 };
    case 'home':
      return s.open && list.length ? { ...s, active: 0 } : s;
    case 'end':
      return s.open && list.length ? { ...s, active: list.length - 1 } : s;
    case 'enter':
      if (s.composing || !s.open) return s; // 조합 중 Enter는 글자 확정만
      return s.active >= 0 && list[s.active] ? choose(s, list[s.active]) : s;
    case 'pick':
      return list[action.index] ? choose(s, list[action.index]) : s;
    case 'escape':
      return reset(s);
    case 'blur':
      return reset(s); // 목록 밖 글자는 값이 되지 않고 원래 값 표시로 돌아간다
    case 'clear': {
      const all = s.options.find((o) => o.value === '');
      if (all) return { ...choose(s, all), open: false };
      return { ...s, query: '', typed: true, open: true, active: s.options.length ? 0 : -1 };
    }
    case 'options': {
      // 목록이 바뀜(예: 언어 필터로 프로젝트가 줄어듦) 또는 원래 <select>의 값이 코드로 바뀜(action.value, P22 주소 복원).
      // 고른 값이 목록에 없으면 '' 항목(전체)으로
      const options = action.options;
      const want = action.value !== undefined ? action.value : s.value;
      const keep = options.some((o) => o.value === want);
      const value = keep ? want : (options.find((o) => o.value === '')?.value ?? options[0]?.value ?? '');
      return { ...s, options, value, query: labelOf(options, value), typed: false, active: -1, changed: value !== s.value };
    }
    default:
      return s;
  }
}
