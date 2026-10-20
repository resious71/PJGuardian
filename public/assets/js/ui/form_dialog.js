// 입력 대화상자 공통 틀과 사용자 검색 대화상자(그룹 구성원 추가 P11A, 프로젝트 접근 매핑 P11B).
// 네이티브 <dialog>.showModal(): 바깥은 inert, Esc로 닫힘(취소). 닫히면 DOM에서 지우고 열기 전 요소로 포커스를 돌려준다.
// DOM은 el()로만 만든다(innerHTML 금지). 서버가 권한과 대상 상태를 다시 확인한다.
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { el, clear } from '../core/dom.js';
import { icon } from './icons.js';

const api = createApi();
const SEARCH_MIN = 2;
const SEARCH_DELAY_MS = 250;
let seq = 0;

/** 오류 문장(요청 ID가 있으면 함께) */
export function errorText(e) {
  return e?.requestId ? `${e.message} (${t('common.request_id', { id: e.requestId })})` : (e?.message ?? String(e));
}

/**
 * 폼 대화상자.
 *   build(id) → { content: Node[], focus?: Node, onSubmit?: async () => 값, bind?: ({close, showError}) => void }
 *   onSubmit이 null/undefined가 아닌 값을 돌려주면 그 값으로 닫힌다. 던진 오류는 대화상자 안에 보여 준다.
 *   취소·Esc면 null.
 *   wide: 넓은 창(P27B 복원 흐름), danger: 되돌리기 어려운 동작(위험 아이콘·위험 색 확인 버튼, P27B)
 */
export function formDialog({ title, message, submitLabel, build, wide = false, danger = false, doc = globalThis.document }) {
  return new Promise((resolve) => {
    const id = `fdlg-${++seq}`;
    const opener = doc.activeElement;
    const error = el('p', { class: 'field__error', role: 'alert', hidden: true }, [], doc);
    const parts = build(id);
    const cancel = el('button', { type: 'button', class: 'btn' }, [t('component.cancel')], doc);
    const actions = [cancel];
    let ok = null;
    if (submitLabel) {
      ok = el('button', { type: 'submit', class: danger ? 'btn btn--danger' : 'btn btn--primary' }, [submitLabel], doc);
      actions.push(ok);
    }
    const form = el('form', { class: 'dialog__form', novalidate: true }, [...parts.content, error, el('div', { class: 'dialog__actions' }, actions, doc)], doc);
    const dialog = el('dialog', { class: `dialog${wide ? ' dialog--wide' : ''}${danger ? ' dialog--danger' : ''}`, 'aria-labelledby': `${id}-title`, 'aria-describedby': `${id}-msg` }, [
      el('div', { class: 'dialog__body' }, [
        el('div', { class: 'dialog__icon' }, [icon(danger ? 'ui.danger' : 'ui.info', 24, doc)], doc),
        el('div', {}, [
          el('h2', { class: 'dialog__title', id: `${id}-title`, text: title }, [], doc),
          el('p', { id: `${id}-msg`, text: message }, [], doc),
        ], doc),
      ], doc),
      form,
    ], doc);
    let result = null;
    const showError = (text) => { error.textContent = text; error.hidden = false; };
    const close = (value) => { result = value; dialog.close(); };
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      if (!parts.onSubmit) return;
      error.hidden = true;
      if (ok) ok.disabled = true;
      try {
        const value = await parts.onSubmit();
        if (value !== undefined && value !== null) close(value);
      } catch (e) {
        showError(errorText(e));
        parts.focus?.focus();
      } finally {
        if (ok) ok.disabled = false;
      }
    });
    cancel.addEventListener('click', () => close(null));
    dialog.addEventListener('close', () => {
      dialog.remove();
      if (opener && typeof opener.focus === 'function') opener.focus();
      resolve(result);
    }, { once: true });
    parts.bind?.({ close, showError });
    doc.body.appendChild(dialog);
    dialog.showModal();
    parts.focus?.focus();
  });
}

/**
 * 입력 칸 바로 아래의 인라인 오류(아이콘 + 글자, aria-invalid). field()로 만든 칸(wrap)에 붙인다(X06).
 * 반환: set(message) — 빈 값이면 지운다.
 */
export function inlineError(wrap, doc = globalThis.document) {
  const input = wrap.querySelector('input');
  const node = el('p', { class: 'field__error', id: `${input.id}-error`, hidden: true }, [], doc);
  input.setAttribute('aria-describedby', [input.getAttribute('aria-describedby'), node.id].filter(Boolean).join(' '));
  wrap.appendChild(node);
  return {
    input,
    set(message) {
      clear(node);
      if (message) {
        node.append(icon('system.error', 16, doc), ' ', message);
        node.hidden = false;
        input.setAttribute('aria-invalid', 'true');
      } else {
        node.hidden = true;
        input.removeAttribute('aria-invalid');
      }
    },
  };
}

/** 라벨 + 입력 칸(+ 도움말) */
export function field(id, key, label, attrs = {}, help = null, doc = globalThis.document) {
  return el('div', { class: 'field' }, [
    el('label', { class: 'field__label', for: `${id}-${key}`, text: label }, [], doc),
    el('input', { class: 'field__input', id: `${id}-${key}`, name: key, autocomplete: 'off', 'aria-describedby': help ? `${id}-${key}-help` : null, ...attrs }, [], doc),
    help ? el('p', { class: 'field__help', id: `${id}-${key}-help`, text: help }, [], doc) : null,
  ], doc);
}

/**
 * 사용자 검색 대화상자(GET /api/v1/users/search: 활성 사용자, 2자 이상, 20건, 로그인 ID와 이름만).
 * 결과마다 "추가" 버튼. pick(u)이 성공하면 u로 닫히고, 던진 오류는 대화상자 안에 보여 준다. 취소면 null.
 * 키보드: 입력 칸에서 Enter = 바로 검색, ↓ = 첫 결과 버튼으로 이동.
 */
export function userSearchDialog({ title, message, pick }) {
  return formDialog({
    title,
    message,
    submitLabel: null,
    build(id) {
      const search = field(id, 'q', t('groups.search_label'), { type: 'search', role: 'combobox', 'aria-controls': `${id}-results`, 'aria-expanded': 'false' });
      const input = search.querySelector('input');
      const status = el('p', { class: 'muted', role: 'status', id: `${id}-status` });
      const list = el('ul', { class: 'search-results', id: `${id}-results`, 'aria-label': t('groups.search_results') });
      let timer = null;
      let latest = 0;
      let ctl = null;
      const render = (items) => {
        clear(list);
        input.setAttribute('aria-expanded', items.length ? 'true' : 'false');
        for (const u of items) {
          const btn = el('button', { type: 'button', class: 'btn btn--small' }, [t('groups.add_button')]);
          btn.setAttribute('aria-label', t('groups.add_aria', { login: u.login_id, name: u.display_name }));
          btn.addEventListener('click', async () => {
            btn.disabled = true;
            try {
              await pick(u);
              ctl.close(u);
            } catch (e) {
              ctl.showError(errorText(e));
              btn.disabled = false;
            }
          });
          list.appendChild(el('li', { class: 'search-results__item' }, [
            el('span', {}, [el('code', { text: u.login_id }), ' ', u.display_name]),
            btn,
          ]));
        }
      };
      const run = async () => {
        const q = input.value.trim();
        if (q.length < SEARCH_MIN) { render([]); status.textContent = t('groups.search_hint', { min: SEARCH_MIN }); return; }
        const mine = ++latest;
        try {
          const items = await api.get('/api/v1/users/search', { query: { q } });
          if (mine !== latest) return; // 늦게 온 이전 응답은 버린다
          render(items);
          status.textContent = items.length ? t('groups.search_count', { n: items.length }) : t('groups.search_none');
        } catch (e) {
          if (mine === latest) { render([]); status.textContent = errorText(e); }
        }
      };
      input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(run, SEARCH_DELAY_MS); });
      input.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter') { ev.preventDefault(); clearTimeout(timer); run(); }
        if (ev.key === 'ArrowDown') { const b = list.querySelector('button'); if (b) { ev.preventDefault(); b.focus(); } }
      });
      status.textContent = t('groups.search_hint', { min: SEARCH_MIN });
      return { content: [search, status, list], focus: input, bind: (c) => { ctl = c; } };
    },
  });
}

export { SEARCH_MIN };
