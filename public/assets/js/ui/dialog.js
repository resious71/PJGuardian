// 확인 대화상자(명세 20.4: 파괴적·되돌릴 수 없는 동작은 경고 색과 전용 아이콘으로 구분하고 확인 단계를 거친다)
// 네이티브 <dialog>.showModal()을 쓴다: 바깥은 inert, Esc로 닫힘(취소). Tab은 대화상자 안 버튼 사이에서만 돈다
// (브라우저 주소창 등으로 빠져나가지 않게 직접 가둔다).
// 닫히면 열기 전의 요소로 포커스를 돌려준다. 기본 포커스는 "취소"(실수로 Enter를 눌러도 지우지 않게).
//   const ok = await confirmDialog({ title, message, confirmLabel, danger: true, confirmIcon: 'action.delete' })
// confirmIcon: 확인 버튼에 붙일 동작 아이콘의 의미 이름(선택). 동작마다 뜻이 다르므로 기본값을 두지 않는다.
import { el } from '../core/dom.js';
import { t } from '../core/i18n.js';
import { icon } from './icons.js';

let seq = 0;

/**
 * 새 비밀번호 입력 대화상자(관리자가 다른 사용자의 비밀번호를 정할 때). 확인 칸과 일치해야 한다.
 * 정책 검사는 서버가 한다(onSubmit이 던진 오류 문장을 대화상자 안에 보여 주고 다시 입력받는다).
 *   await passwordDialog({ title, message, policyText, submitLabel, onSubmit: async (pw) => {...} })  → 성공하면 true, 취소하면 false
 */
export function passwordDialog({ title, message, policyText, submitLabel = t('component.confirm'), onSubmit, doc = globalThis.document } = {}) {
  return new Promise((resolve) => {
    const id = `dlg-${++seq}`;
    const opener = doc.activeElement;
    const field = (key, label, help) => el('div', { class: 'field' }, [
      el('label', { class: 'field__label', for: `${id}-${key}`, text: label }, [], doc),
      el('input', { class: 'field__input', id: `${id}-${key}`, type: 'password', autocomplete: 'new-password', required: true, 'aria-describedby': help ? `${id}-help` : null }, [], doc),
      help ? el('p', { class: 'field__help', id: `${id}-help`, text: help }, [], doc) : null,
    ], doc);
    const pwField = field('pw', t('users.new_password'), policyText);
    const confirmField = field('confirm', t('users.new_password_confirm'), null);
    const error = el('p', { class: 'field__error', role: 'alert', hidden: true }, [], doc);
    const cancel = el('button', { type: 'button', class: 'btn' }, [t('component.cancel')], doc);
    const ok = el('button', { type: 'submit', class: 'btn btn--primary' }, [submitLabel], doc);
    const form = el('form', { class: 'dialog__form', novalidate: true }, [pwField, confirmField, error,
      el('div', { class: 'dialog__actions' }, [cancel, ok], doc)], doc);
    const dialog = el('dialog', { class: 'dialog', 'aria-labelledby': `${id}-title`, 'aria-describedby': `${id}-msg` }, [
      el('div', { class: 'dialog__body' }, [
        el('div', { class: 'dialog__icon' }, [icon('ui.info', 24, doc)], doc),
        el('div', {}, [
          el('h2', { class: 'dialog__title', id: `${id}-title`, text: title }, [], doc),
          el('p', { id: `${id}-msg`, text: message }, [], doc),
        ], doc),
      ], doc),
      form,
    ], doc);
    const pw = pwField.querySelector('input');
    const confirm = confirmField.querySelector('input');
    let done = false;
    const showError = (text) => { error.textContent = text; error.hidden = false; pw.focus(); };
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      if (pw.value !== confirm.value) { showError(t('users.password_mismatch')); return; }
      ok.disabled = true;
      try {
        await onSubmit(pw.value);
        done = true;
        dialog.close();
      } catch (e) {
        showError(e?.message ?? String(e));
      } finally {
        ok.disabled = false;
      }
    });
    cancel.addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
      pw.value = ''; confirm.value = ''; // 값이 DOM에 남지 않게
      dialog.remove();
      if (opener && typeof opener.focus === 'function') opener.focus();
      resolve(done);
    }, { once: true });
    doc.body.appendChild(dialog);
    dialog.showModal();
    pw.focus();
  });
}

/**
 * 한 번만 보여 줄 값(임시 비밀번호 등)을 담은 안내 대화상자. 값은 텍스트로만 넣고 복사 버튼을 둔다.
 * 닫으면 DOM에서 지운다(값이 화면에 남지 않게).
 *   await secretDialog({ title, message, secret })
 */
export function secretDialog({ title, message, secret, doc = globalThis.document } = {}) {
  return new Promise((resolve) => {
    const id = `dlg-${++seq}`;
    const opener = doc.activeElement;
    const value = el('code', { class: 'secret-value', id: `${id}-secret`, text: secret }, [], doc);
    const status = el('span', { class: 'muted', role: 'status' }, [], doc);
    const copy = el('button', { type: 'button', class: 'btn' }, [icon('action.copy', 16, doc), t('icon.action.copy')], doc);
    const close = el('button', { type: 'button', class: 'btn btn--primary' }, [t('component.close')], doc);
    const dialog = el('dialog', { class: 'dialog', 'aria-labelledby': `${id}-title`, 'aria-describedby': `${id}-msg` }, [
      el('div', { class: 'dialog__body' }, [
        el('div', { class: 'dialog__icon' }, [icon('ui.info', 24, doc)], doc),
        el('div', {}, [
          el('h2', { class: 'dialog__title', id: `${id}-title`, text: title }, [], doc),
          el('p', { id: `${id}-msg`, text: message }, [], doc),
          el('p', {}, [value], doc),
          status,
        ], doc),
      ], doc),
      el('div', { class: 'dialog__actions' }, [copy, close], doc),
    ], doc);
    copy.addEventListener('click', async () => {
      try {
        await globalThis.navigator.clipboard.writeText(secret);
        status.textContent = t('component.copied');
      } catch {
        status.textContent = t('component.copy_failed');
      }
    });
    close.addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
      dialog.remove();
      if (opener && typeof opener.focus === 'function') opener.focus();
      resolve();
    }, { once: true });
    doc.body.appendChild(dialog);
    dialog.showModal();
    copy.focus();
  });
}

// warn: 파괴적이지는 않지만 확인이 필요한 동작(예: 계정 잠금 해제, P20) — 경고 아이콘(system.warn), 확인 버튼은 기본 색
export function confirmDialog({ title, message, confirmLabel = t('component.confirm'), cancelLabel = t('component.cancel'), danger = false, warn = false, confirmIcon = null, doc = globalThis.document } = {}) {
  return new Promise((resolve) => {
    const id = `dlg-${++seq}`;
    const opener = doc.activeElement;
    const cancel = el('button', { type: 'button', class: 'btn', value: 'cancel' }, [cancelLabel], doc);
    const ok = el('button', { type: 'button', class: danger ? 'btn btn--danger' : 'btn btn--primary', value: 'ok' },
      confirmIcon ? [icon(confirmIcon, 16, doc), confirmLabel] : [confirmLabel], doc);
    const dialog = el('dialog', {
      class: danger ? 'dialog dialog--danger' : 'dialog',
      'aria-labelledby': `${id}-title`, 'aria-describedby': `${id}-msg`,
    }, [
      el('div', { class: 'dialog__body' }, [
        el('div', { class: 'dialog__icon' }, [icon(danger ? 'ui.danger' : (warn ? 'system.warn' : 'ui.info'), 24, doc)], doc),
        el('div', {}, [
          el('h2', { class: 'dialog__title', id: `${id}-title`, text: title }, [], doc),
          el('p', { id: `${id}-msg`, text: message }, [], doc),
        ], doc),
      ], doc),
      el('div', { class: 'dialog__actions' }, [cancel, ok], doc),
    ], doc);

    let result = false;
    const finish = () => {
      dialog.remove();
      if (opener && typeof opener.focus === 'function') opener.focus();
      resolve(result);
    };
    cancel.addEventListener('click', () => { result = false; dialog.close(); });
    ok.addEventListener('click', () => { result = true; dialog.close(); });
    dialog.addEventListener('close', finish, { once: true }); // Esc도 close 이벤트(result=false)
    dialog.addEventListener('keydown', (ev) => {
      if (ev.key !== 'Tab') return;
      const first = cancel;
      const last = ok;
      if (ev.shiftKey && doc.activeElement === first) { ev.preventDefault(); last.focus(); }
      else if (!ev.shiftKey && doc.activeElement === last) { ev.preventDefault(); first.focus(); }
    });
    doc.body.appendChild(dialog);
    dialog.showModal();
    cancel.focus();
  });
}

/**
 * 이름을 입력해야 하는 위험 확인(P21B: Run 상세 삭제 — 되돌릴 수 없음). 입력이 expected와 같을 때만 확인 버튼이 켜진다.
 * 취소가 기본 포커스, Esc = 취소, Tab은 입력·취소·확인 사이에서만 돈다. 닫히면 연 요소로 포커스를 돌려준다. → Promise<boolean>
 *   lines: 메시지 아래에 보일 목록(예: 대상 Run 번호), inputLabel: 입력 칸 설명
 */
export function typedConfirmDialog({ title, message, lines = [], inputLabel, expected, confirmLabel, matches, doc = globalThis.document } = {}) {
  return new Promise((resolve) => {
    const id = `dlg-${++seq}`;
    const opener = doc.activeElement;
    const input = el('input', { class: 'field__input', id: `${id}-input`, type: 'text', autocomplete: 'off', spellcheck: 'false' }, [], doc);
    const cancel = el('button', { type: 'button', class: 'btn', value: 'cancel' }, [t('component.cancel')], doc);
    const ok = el('button', { type: 'button', class: 'btn btn--danger', value: 'ok', disabled: true }, [icon('action.delete', 16, doc), confirmLabel], doc);
    const dialog = el('dialog', { class: 'dialog dialog--danger', 'aria-labelledby': `${id}-title`, 'aria-describedby': `${id}-msg` }, [
      el('div', { class: 'dialog__body' }, [
        el('div', { class: 'dialog__icon' }, [icon('ui.danger', 24, doc)], doc),
        el('div', {}, [
          el('h2', { class: 'dialog__title', id: `${id}-title`, text: title }, [], doc),
          el('p', { id: `${id}-msg`, text: message }, [], doc),
          ...lines.map((line) => el('p', { class: 'dialog__line', text: line }, [], doc)),
          el('div', { class: 'field' }, [
            el('label', { class: 'field__label', for: `${id}-input`, text: inputLabel }, [], doc),
            input,
          ], doc),
        ], doc),
      ], doc),
      el('div', { class: 'dialog__actions' }, [cancel, ok], doc),
    ], doc);
    const same = matches ?? ((v) => v.trim() !== '' && v.trim() === String(expected).trim());
    input.addEventListener('input', () => { ok.disabled = !same(input.value); });
    let result = false;
    cancel.addEventListener('click', () => { result = false; dialog.close(); });
    ok.addEventListener('click', () => {
      if (!same(input.value)) return;
      result = true;
      dialog.close();
    });
    dialog.addEventListener('close', () => {
      dialog.remove();
      if (opener && typeof opener.focus === 'function') opener.focus();
      resolve(result);
    }, { once: true });
    dialog.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter' && ev.target === input) {
        ev.preventDefault(); // Enter로 바로 지우지 않는다(버튼을 눌러야 함)
        return;
      }
      if (ev.key !== 'Tab') return;
      const order = [input, cancel, ok].filter((n) => !n.disabled);
      const i = order.indexOf(doc.activeElement);
      if (ev.shiftKey && i <= 0) { ev.preventDefault(); order[order.length - 1].focus(); }
      else if (!ev.shiftKey && i === order.length - 1) { ev.preventDefault(); order[0].focus(); }
    });
    doc.body.appendChild(dialog);
    dialog.showModal();
    cancel.focus();
  });
}

/**
 * 내용 창(P21: 결함 전체 이력). 넓은 대화상자, 본문은 창 안에서 스크롤, 닫기 버튼과 Esc로 닫힘, Tab은 창 안의 포커스 가능한 요소 사이에서만 돈다.
 *   contentDialog({ title, build: (bodyEl, dialogEl) => void|Promise }) → 닫힐 때 resolve. 닫히면 연 요소로 포커스를 돌려준다.
 */
export function contentDialog({ title, build, doc = globalThis.document } = {}) {
  return new Promise((resolve) => {
    const id = `dlg-${++seq}`;
    const opener = doc.activeElement;
    const close = el('button', { type: 'button', class: 'btn btn--primary', dataset: { dialogClose: '' } }, [t('component.close')], doc);
    const body = el('div', { class: 'dialog__scroll', id: `${id}-body` }, [], doc);
    const dialog = el('dialog', { class: 'dialog dialog--wide', 'aria-labelledby': `${id}-title` }, [
      el('div', { class: 'dialog__head' }, [el('h2', { class: 'dialog__title', id: `${id}-title`, text: title }, [], doc)], doc),
      body,
      el('div', { class: 'dialog__actions' }, [close], doc),
    ], doc);
    close.addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
      dialog.remove();
      if (opener && typeof opener.focus === 'function') opener.focus();
      resolve();
    }, { once: true });
    dialog.addEventListener('keydown', (ev) => {
      if (ev.key !== 'Tab') return;
      const items = [...dialog.querySelectorAll('a[href], button:not([disabled]), [tabindex="0"], select, input')].filter((n) => !n.closest('[hidden]'));
      if (!items.length) return;
      const first = items[0];
      const last = items[items.length - 1];
      if (ev.shiftKey && doc.activeElement === first) { ev.preventDefault(); last.focus(); }
      else if (!ev.shiftKey && doc.activeElement === last) { ev.preventDefault(); first.focus(); }
    });
    doc.body.appendChild(dialog);
    dialog.showModal();
    close.focus();
    Promise.resolve(build(body, dialog)).catch(() => {});
  });
}
