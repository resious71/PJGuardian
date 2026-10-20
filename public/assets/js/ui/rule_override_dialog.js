// 규칙 켜기·끄기 확인 창(P22B 상세 → P22C 목록 스위치도 같은 창)과 일괄 변경 확인 창(P22C).
// formDialog(ui/form_dialog.js) 위에 만든다: 포커스는 사유 칸(또는 확인 입력), Esc = 취소, 닫히면 연 요소로 포커스. 값은 textContent로만.
// 서버 함수는 단일 PUT·DELETE /api/v1/admin/rules/{tool}/{rule}/override, 일괄 POST …/overrides/bulk(미리보기가 준 개정 번호로).
import { t } from '../core/i18n.js';
import { el } from '../core/dom.js';
import { formDialog } from './form_dialog.js';
import { overrideDialogLines, bulkDialogLines, resetAllMatches } from '../lib/rule_core.js';

/** 규칙 경로(키의 / 는 구간, 각 구간은 인코딩) */
export function overridePath(tool, rule) {
  return `/api/v1/admin/rules/${tool}/${rule.split('/').map(encodeURIComponent).join('/')}/override`;
}

/**
 * 한 규칙 켜기·끄기·기본값으로. a = 상세 API의 analysis(지금 상태), openDefects = 서버 전체 열린 결함 수(admin의 상세 defects.total).
 * → 'done' | 'conflict'(409 — 다른 관리자가 먼저 바꿈) | null(취소)
 */
export function confirmRuleOverride({ api, tool, rule, key, kind, a, openDefects }) {
  const titleKey = kind === 'enable' ? 'rules.override_dialog_on' : kind === 'disable' ? 'rules.override_dialog_off' : 'rules.override_dialog_reset';
  const [first, ...rest] = overrideDialogLines(kind, a, openDefects);
  return formDialog({
    title: t(titleKey, { rule: key }),
    message: first,
    submitLabel: t(kind === 'enable' ? 'rules.override_submit_on' : kind === 'disable' ? 'rules.override_submit_off' : 'rules.override_submit_reset'),
    build: (id) => {
      const input = el('input', { class: 'field__input', id: `${id}-reason`, name: 'reason', maxlength: 200, autocomplete: 'off' });
      const lines = rest.map((line) => el('p', { class: 'rule-dialog-line', text: line }));
      const content = kind === 'reset' ? lines : [...lines, el('div', { class: 'field' }, [el('label', { class: 'field__label', for: `${id}-reason`, text: t('rules.override_reason') }), input])];
      return {
        content,
        focus: kind === 'reset' ? null : input,
        onSubmit: async () => {
          try {
            if (kind === 'reset') await api.delete(overridePath(tool, rule));
            else await api.put(overridePath(tool, rule), { enabled: kind === 'enable', expected_enabled: a.enabled, reason: input.value.trim() || null });
            return 'done';
          } catch (e) {
            if (e.status === 409) return 'conflict';
            throw e;
          }
        },
      };
    },
  });
}

/**
 * 일괄 변경 확인 창. preview = 미리보기 응답, typed = 도구 이름 입력이 필요한가(검색 결과 전체 대상 또는 바뀔 수 20개 초과).
 * 확인하면 apply(reason)를 부른다 → 'done' 결과 객체 | 'revision'(409 개정 번호 바뀜 — 다시 미리보기) | null(취소)
 */
export function confirmBulk({ preview, typed, apply }) {
  const toolName = t(`rules.tool.${preview.tool}`);
  const [first, ...rest] = bulkDialogLines(preview);
  return formDialog({
    title: t(`rules.bulk_title.${preview.action}`, { tool: toolName, n: preview.changes }),
    message: first,
    submitLabel: t(`rules.bulk_submit.${preview.action}`),
    build: (id) => {
      const reason = el('input', { class: 'field__input', id: `${id}-reason`, name: 'reason', maxlength: 200, autocomplete: 'off' });
      const confirmInput = typed ? el('input', { class: 'field__input', id: `${id}-confirm`, name: 'confirm', autocomplete: 'off', spellcheck: 'false' }) : null;
      const content = [
        ...rest.map((line) => el('p', { class: 'rule-dialog-line', text: line })),
        el('div', { class: 'field' }, [el('label', { class: 'field__label', for: `${id}-reason`, text: t('rules.override_reason') }), reason]),
      ];
      if (confirmInput) content.push(el('div', { class: 'field' }, [el('label', { class: 'field__label', for: `${id}-confirm`, text: t('rules.bulk_typed', { tool: toolName }) }), confirmInput]));
      return {
        content,
        focus: confirmInput ?? reason,
        // 확인 버튼: 바뀌는 것이 없거나 도구 이름이 맞지 않으면 꺼 둔다
        bind: () => {
          const ok = reason.closest('form')?.querySelector('button[type="submit"]');
          if (!ok) return;
          const sync = () => { ok.disabled = preview.changes === 0 || (confirmInput !== null && !resetAllMatches(confirmInput.value, preview.tool)); };
          confirmInput?.addEventListener('input', sync);
          sync();
        },
        onSubmit: async () => {
          if (preview.changes === 0 || (confirmInput && !resetAllMatches(confirmInput.value, preview.tool))) return null;
          try {
            return { status: 'done', result: await apply(reason.value.trim() || null) };
          } catch (e) {
            if (e.status === 409 && e.details?.reason === 'revision_changed') return { status: 'revision' };
            throw e;
          }
        },
      };
    },
  });
}
