// 관리자 "규칙 설정"(P22B): 관리자가 켜거나 끈 규칙 목록(도구별·상태별), 한 건 되돌리기, 도구별 "모두 기본값으로 되돌리기"(도구 이름 입력 확인).
// 요약: 도구별 변경 수·마지막 변경·실효 설정 해시(앞 12자리)·ruff 업로드 설정 프로젝트 수. 값은 textContent로만(innerHTML 금지).
// 서버가 권한(admin)·확인값·건수 상한을 다시 판정한다.
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { el, clear, setText, meta as metaTag } from '../core/dom.js';
import { formatDateTime, timezoneFromDocument } from '../core/time.js';
import { renderPagination, pageCount } from '../ui/pagination.js';
import { toast } from '../ui/toast.js';
import { confirmDialog, typedConfirmDialog } from '../ui/dialog.js';
import { errorText } from '../ui/form_dialog.js';
import { overrideSummaryLines, resetAllMatches } from '../lib/rule_core.js';

const PAGE_SIZE = 50;
/** 규칙 설명에서 마지막으로 연 언어 탭(X08, 없거나 읽을 수 없으면 Python) */
function lastLanguage() {
  try {
    const v = localStorage.getItem('pjg.rules.language');
    return v === 'javascript' ? 'javascript' : 'python';
  } catch {
    return 'python';
  }
}
const root = document.querySelector('[data-admin-rule-settings]');
if (root) init(root);

function init(section) {
  const api = createApi();
  const tz = timezoneFromDocument();
  const base = metaTag('app-base') ?? '';
  const max = Number(section.dataset.resetMax) || 1000;
  const toolSelect = section.querySelector('[data-tool]');
  const stateSelect = section.querySelector('[data-state]');
  const summary = section.querySelector('[data-summary]');
  const resetAll = section.querySelector('[data-reset-all]');
  const table = section.querySelector('[data-table]');
  const rows = section.querySelector('[data-rows]');
  const pager = section.querySelector('[data-pagination]');
  const count = section.querySelector('[data-count]');
  const loading = section.querySelector('[data-loading]');
  const error = section.querySelector('[data-error]');
  const empty = section.querySelector('[data-empty]');
  let page = 1;
  let seq = 0;
  let last = null;

  const toolName = (tool) => t(`rules.tool.${tool}`);

  function row(r) {
    const resetBtn = el('button', { type: 'button', class: 'btn', dataset: { reset: r.key }, 'aria-label': t('admin_rule_settings.reset_one_label', { rule: r.key }) },
      [t('admin_rule_settings.reset_one')]);
    resetBtn.addEventListener('click', () => resetOne(r, resetBtn));
    return el('tr', { dataset: { key: r.key, state: r.state } }, [
      el('td', { class: 'arc-rule' }, [r.href ? el('a', { href: `${base}${r.href}`, class: 'rule-key' }, [el('code', { text: r.rule })]) : el('code', { text: r.rule })]),
      el('td', { class: 'arc-name', text: r.name ?? t('admin_rule_settings.none') }),
      el('td', { class: 'arc-change', text: t(r.enabled ? 'admin_rule_settings.change_on' : 'admin_rule_settings.change_off') }),
      el('td', { class: 'arc-base', text: t(r.base_enabled ? 'admin_rule_settings.base_on' : 'admin_rule_settings.base_off') }),
      el('td', { class: 'arc-reason', text: r.reason || t('admin_rule_settings.none') }),
      el('td', { class: 'arc-by', text: r.changed_by_name ?? t('admin_rule_settings.none') }),
      el('td', { class: 'arc-at', text: formatDateTime(r.changed_at, tz) }),
      el('td', { class: 'arc-state' }, [el('span', { class: r.state === 'active' ? '' : 'tag', text: t(`admin_rule_settings.state.${r.state}`) }),
        ...(r.block ? [el('span', { class: 'muted', text: ` · ${t(`rules.block.${r.block}`)}` })] : [])]),
      el('td', { class: 'arc-action' }, [resetBtn]),
    ]);
  }

  async function load() {
    const my = ++seq;
    loading.hidden = false;
    error.hidden = true;
    try {
      const res = await api.get('/api/v1/admin/rules/overrides', { query: { tool: toolSelect.value, state: stateSelect.value, page, size: PAGE_SIZE } });
      if (my !== seq) return;
      last = res;
      clear(summary);
      for (const line of overrideSummaryLines(res.summary, (iso) => formatDateTime(iso, tz))) summary.append(el('p', { text: line }));
      const n = res.summary[toolSelect.value]?.count ?? 0;
      resetAll.disabled = n === 0;
      setText(count, t('admin_rule_settings.count', { n: res.page.total.toLocaleString('ko-KR') }));
      clear(rows);
      for (const r of res.data) rows.append(row(r));
      table.hidden = res.data.length === 0;
      empty.hidden = res.data.length !== 0;
      // 빈 상태(P22C): 변경이 하나도 없으면 안내 + 규칙 설명(마지막으로 연 언어 탭)으로 이동, 도구·상태 조건 때문에 비면 구분해서 알림
      const none = ((res.summary.ruff?.count ?? 0) + (res.summary.eslint?.count ?? 0)) === 0;
      setText(empty.querySelector('[data-empty-text]'), t(none ? 'admin_rule_settings.empty_none' : 'admin_rule_settings.empty_filtered'));
      empty.querySelector('[data-empty-go]').hidden = !none;
      empty.querySelector('[data-go-rules]').search = `?language=${lastLanguage()}`;
      renderPagination(pager, { page, pages: pageCount(res.page.total, PAGE_SIZE), onChange: (p) => { page = p; load(); } });
      pager.hidden = res.page.total <= PAGE_SIZE;
    } catch (e) {
      if (my !== seq) return;
      error.hidden = false;
      setText(error, errorText(e));
    } finally {
      if (my === seq) loading.hidden = true;
    }
  }

  async function resetOne(r, btn) {
    btn.disabled = true;
    try {
      const ok = await confirmDialog({ title: t('admin_rule_settings.reset_one_title', { rule: r.key }), message: t('admin_rule_settings.reset_one_message'),
        confirmLabel: t('admin_rule_settings.reset_one'), warn: true });
      if (!ok) return;
      await api.delete(`/api/v1/admin/rules/${r.tool}/${r.rule.split('/').map(encodeURIComponent).join('/')}/override`);
      toast(t('admin_rule_settings.reset_done'), { type: 'success' });
      await load();
    } catch (e) {
      toast(errorText(e), { type: 'error' });
    } finally {
      if (btn.isConnected) btn.disabled = false;
    }
  }

  resetAll.addEventListener('click', async () => {
    const tool = toolSelect.value;
    const n = last?.summary?.[tool]?.count ?? 0;
    resetAll.disabled = true;
    try {
      const ok = await typedConfirmDialog({
        title: t('admin_rule_settings.reset_all_title', { tool: toolName(tool), n }),
        message: t('admin_rule_settings.reset_all_message', { max }),
        inputLabel: t('admin_rule_settings.reset_all_input', { tool: toolName(tool) }),
        matches: (v) => resetAllMatches(v, tool),
        confirmLabel: t('admin_rule_settings.reset_all'),
      });
      if (!ok) return;
      const res = await api.post('/api/v1/admin/rules/overrides/reset', { tool, confirm: tool });
      toast(res.remaining > 0 ? t('admin_rule_settings.reset_all_remaining', { n: res.reset, left: res.remaining }) : t('admin_rule_settings.reset_all_done', { n: res.reset }),
        { type: 'success' });
      page = 1;
    } catch (e) {
      toast(errorText(e), { type: 'error' });
    } finally {
      await load();
    }
  });

  toolSelect.addEventListener('change', () => { page = 1; load(); });
  stateSelect.addEventListener('change', () => { page = 1; load(); });
  load();
}
