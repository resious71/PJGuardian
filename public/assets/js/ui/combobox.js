// 입력해서 좁혀 고르는 콤보박스(P21C, 공통 부품 — 이 단계에서는 대시보드에만 씀). 외부 라이브러리 없음, el()/textContent로만 만든다.
//   comboboxFromSelect(select) → { refresh() }
//     기존 <select>는 숨긴 채 값의 원천으로 남는다: 고르면 select.value를 바꾸고 change를 보낸다(기존 코드가 select.value·change를 그대로 씀).
//     select의 id는 입력칸으로 옮긴다(<label for>가 입력칸을 가리킴). hidden인 <option>은 목록에서 뺀다(refresh()로 다시 읽음).
//   ARIA 1.2 콤보박스: input[role=combobox][aria-expanded][aria-controls][aria-activedescendant] + ul[role=listbox] > li[role=option][aria-selected].
//   키: ↓↑ 이동(닫혀 있으면 열기), Home·End, Enter 선택, Esc 닫기(입력 취소), Tab 이동 시 닫힘. 한국어 조합 중 Enter는 확정하지 않는다.
import { t } from '../core/i18n.js';
import { el, clear } from '../core/dom.js';
import { icon } from './icons.js';
import { initialState, reduce, visible } from '../lib/combobox_core.js';

let seq = 0;

function readOptions(select) {
  return [...select.options].filter((o) => !o.hidden && !o.disabled).map((o) => ({ value: o.value, label: o.textContent.trim() }));
}

export function comboboxFromSelect(select, doc = globalThis.document) {
  const id = select.id || `combo-${++seq}`;
  const listId = `${id}-list`;
  select.id = `${id}-native`;
  select.hidden = true;
  select.tabIndex = -1;
  select.setAttribute('aria-hidden', 'true');
  const input = el('input', {
    type: 'text', class: 'field__input combobox__input', id, role: 'combobox', autocomplete: 'off', spellcheck: 'false',
    'aria-autocomplete': 'list', 'aria-expanded': 'false', 'aria-controls': listId, dataset: { combobox: '' },
  }, [], doc);
  const list = el('ul', { class: 'combobox__list', id: listId, role: 'listbox', hidden: true }, [], doc);
  const clearBtn = el('button', { type: 'button', class: 'combobox__clear', 'aria-label': t('combobox.clear'), title: t('combobox.clear'), tabindex: '-1' }, [icon('ui.close', 16, doc)], doc);
  const wrap = el('div', { class: 'combobox' }, [input, clearBtn, list], doc);
  select.after(wrap);
  let state = initialState(readOptions(select), select.value);

  function render() {
    input.value = state.query;
    input.setAttribute('aria-expanded', String(state.open));
    list.hidden = !state.open;
    // 전체(빈 값) 항목이 없는 상자(정렬 등)는 지우기 없음. 전체를 고른 상태면 지울 것이 없다
    clearBtn.hidden = !state.options.some((o) => o.value === '') || (state.value === '' && !state.typed);
    clear(list);
    const items = visible(state);
    if (state.open) {
      if (!items.length) {
        list.appendChild(el('li', { class: 'combobox__empty', role: 'option', 'aria-disabled': 'true', id: `${listId}-none`, text: t('combobox.no_match') }, [], doc));
      }
      items.forEach((o, i) => {
        const li = el('li', {
          class: `combobox__option${i === state.active ? ' is-active' : ''}`, role: 'option', id: `${listId}-${i}`,
          'aria-selected': String(o.value === state.value), text: o.label, dataset: { value: o.value },
        }, [], doc);
        li.addEventListener('mousedown', (e) => e.preventDefault()); // 입력칸 포커스를 잃지 않게
        li.addEventListener('click', () => dispatch({ type: 'pick', index: i }));
        list.appendChild(li);
      });
    }
    if (state.open && state.active >= 0) {
      input.setAttribute('aria-activedescendant', `${listId}-${state.active}`);
      list.querySelector('.is-active')?.scrollIntoView?.({ block: 'nearest' });
    } else {
      input.removeAttribute('aria-activedescendant');
    }
  }

  function dispatch(action) {
    state = reduce(state, action);
    render();
    if (state.changed) {
      select.value = state.value;
      select.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  input.addEventListener('focus', () => {
    dispatch({ type: 'focus' });
    input.select(); // 지금 값 글자를 모두 선택 → 치면 바로 덮어쓴다(목록은 전체)
  });
  input.addEventListener('click', () => { if (!state.open) dispatch({ type: 'focus' }); });
  input.addEventListener('input', () => {
    state = reduce(state, { type: 'input', text: input.value });
    const caret = input.selectionStart;
    render();
    input.setSelectionRange?.(caret, caret); // 다시 그려도 입력 위치 유지
  });
  input.addEventListener('compositionstart', () => { state = reduce(state, { type: 'compositionstart' }); });
  input.addEventListener('compositionend', () => { state = reduce(state, { type: 'compositionend' }); });
  input.addEventListener('blur', () => dispatch({ type: 'blur' }));
  input.addEventListener('keydown', (e) => {
    const map = { ArrowDown: 'down', ArrowUp: 'up', Enter: 'enter', Escape: 'escape' };
    let type = map[e.key];
    if (!type && state.open && (e.key === 'Home' || e.key === 'End') && !state.typed) type = e.key === 'Home' ? 'home' : 'end';
    if (!type) return;
    if (e.isComposing || e.keyCode === 229) {
      if (type === 'enter') return; // 조합 확정용 Enter
    }
    if (type === 'escape' && !state.open && !state.typed) return; // 닫힌 상태의 Esc는 다른 곳(대화상자 등)으로
    if (type === 'enter' && !state.open) return; // 닫혀 있으면 폼 동작 그대로
    e.preventDefault();
    dispatch({ type });
  });
  clearBtn.addEventListener('mousedown', (e) => e.preventDefault());
  clearBtn.addEventListener('click', () => { dispatch({ type: 'clear' }); input.focus(); });
  render();
  return {
    input,
    /** <select>의 선택지(hidden 포함)가 바뀌면 다시 읽는다 */
    refresh() {
      state = reduce(state, { type: 'options', options: readOptions(select), value: select.value });
      render();
      if (state.changed) {
        select.value = state.value;
        select.dispatchEvent(new Event('change', { bubbles: true }));
      }
    },
  };
}
