// 계정 CSV 일괄 처리(P28A, admin): 파일 선택 → 미리보기(서버가 판정, 저장 없음) → 실행(같은 파일을 다시 보내 digest 확인, 전부 또는 하나도) → 결과.
// 모두 textContent로 그린다(사용자 값 innerHTML 금지). 임시 비밀번호는 결과 단계의 메모리에만 있고(서버는 보관하지 않음),
// 결과 CSV는 이 화면에서 한 번만 만든다. 파일 원문은 로그·화면 어디에도 남기지 않는다.
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { el, clear } from '../core/dom.js';
import { iconWithLabel } from '../ui/icons.js';
import { confirmDialog } from '../ui/dialog.js';
import { errorText } from '../ui/form_dialog.js';
import { formatDateTime, timezoneFromDocument } from '../core/time.js';
import * as core from '../lib/account_import_core.js';

const api = createApi();
const root = document.querySelector('[data-account-import]');

if (root) {
  const $ = (sel) => root.querySelector(sel);
  const tz = timezoneFromDocument();
  const form = $('[data-file-form]');
  const fileInput = $('[data-file]');
  const fileError = $('[data-file-error]');
  const encodingSelect = $('[data-encoding]');
  const previewBtn = $('[data-preview-btn]');
  const previewBody = $('[data-preview-body]');
  const resultBody = $('[data-result-body]');
  const status = $('[data-status]');
  const errorBox = $('[data-error]');
  const STATUS_ICON = { ok: 'system.ok', warning: 'system.warn', error: 'system.error' };

  let file = null; // 고른 File(실행 때 같은 바이트를 다시 보냄)
  let bytes = null;
  let preview = null;
  let filter = 'all';
  let page = 1;
  let skipErrors = false;
  let busy = false;

  function showError(message) {
    errorBox.textContent = message ?? '';
    errorBox.hidden = !message;
  }

  function setStep(name, { focus = true } = {}) {
    for (const section of root.querySelectorAll('[data-step]')) section.hidden = section.dataset.step !== name;
    for (const mark of root.querySelectorAll('[data-step-mark]')) {
      if (mark.dataset.stepMark === name) mark.setAttribute('aria-current', 'step'); else mark.removeAttribute('aria-current');
    }
    // 단계 제목에 포커스(화면 읽기 프로그램이 새 단계를 읽음)
    if (focus) root.querySelector(`[data-step="${name}"] h2`)?.focus();
  }

  function setBusy(on) {
    busy = on;
    previewBtn.disabled = on;
    for (const b of root.querySelectorAll('[data-step="preview"] button, [data-step="preview"] input, [data-step="preview"] select')) b.disabled = on;
  }

  async function runPreview(encoding) {
    showError('');
    status.textContent = t('account_import.reading');
    setBusy(true);
    try {
      preview = await api.post('/api/v1/admin/account-import/preview', undefined, { raw: bytes, query: { encoding } });
      filter = 'all';
      page = 1;
      skipErrors = false;
      status.textContent = '';
      renderPreview();
      setStep('preview');
    } catch (e) {
      status.textContent = '';
      showError(errorText(e));
    } finally {
      setBusy(false);
      renderActions();
    }
  }

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    if (busy) return;
    file = fileInput.files?.[0] ?? null;
    fileError.hidden = !!file;
    fileError.textContent = file ? '' : t('account_import.choose_file');
    if (!file) {
      fileInput.setAttribute('aria-invalid', 'true');
      fileInput.focus();
      return;
    }
    fileInput.removeAttribute('aria-invalid');
    bytes = await file.arrayBuffer();
    await runPreview(encodingSelect.value);
  });

  const roleText = (role) => (role ? t(`account_import.role.${role}`) : '');
  const actionText = (action) => (['create', 'deactivate'].includes(action) ? t(`account_import.action.${action}`) : action);
  const statusCell = (st) => iconWithLabel(STATUS_ICON[st], 16, t(`account_import.status.${st}`));

  function reasons(codes) {
    if (!codes?.length) return '';
    return el('ul', { class: 'account-import__reasons' }, codes.map((c) => el('li', { text: core.codeText(c) })));
  }

  function noticeList(kind, title, items) {
    return el('div', { class: `notice notice--${kind}`, role: kind === 'error' ? 'alert' : null }, [
      el('p', { class: 'account-import__notice-title' }, [iconWithLabel(kind === 'error' ? 'system.error' : 'system.warn', 16, title)]),
      el('ul', {}, items.map((x) => el('li', { text: core.fileText(x) }))),
    ]);
  }

  function renderPreview() {
    clear(previewBody);
    const p = preview;
    const encName = p.encoding ? t(`account_import.enc.${p.encoding}`) : '—';
    const encText = `${t('account_import.encoding_detected', { encoding: encName })}${p.bom ? ` (${t('account_import.bom')})` : ''}`;
    const reread = el('select', { class: 'field__input account-import__encoding', id: 'ai-reread', 'aria-label': t('account_import.reread') },
      ['auto', 'utf-8', 'cp949'].map((v) => el('option', { value: v, text: v === 'auto' ? encodingSelect.options[0].textContent : t(`account_import.enc.${v}`), selected: v === p.encoding_requested })));
    const rereadBtn = el('button', { type: 'button', class: 'btn', text: t('account_import.reread'), dataset: { reread: '' } });
    rereadBtn.addEventListener('click', () => { encodingSelect.value = reread.value; runPreview(reread.value); });
    previewBody.append(
      el('p', { class: 'account-import__file', text: t('account_import.file_name', { name: file?.name ?? '' }) }),
      el('div', { class: 'account-import__encoding-line' }, [el('span', { dataset: { encodingText: '' }, text: encText }), reread, rereadBtn]),
    );
    if (p.file_errors.length) previewBody.append(noticeList('error', t('account_import.file_errors_title'), p.file_errors));
    if (p.file_warnings.length) previewBody.append(noticeList('warn', t('account_import.file_warnings_title'), p.file_warnings));
    if (!p.file_errors.length) {
      previewBody.append(el('ul', { class: 'account-import__summary', dataset: { summary: '' } },
        core.summaryParts(p.summary).map((part) => el('li', { class: `account-import__chip account-import__chip--${part.kind}`, dataset: { sum: part.key }, text: part.text }))));
      const radios = ['all', 'error', 'warning'].map((f) => {
        const id = `ai-filter-${f}`;
        const input = el('input', { type: 'radio', name: 'ai-filter', id, value: f, checked: f === filter });
        input.addEventListener('change', () => { filter = f; page = 1; renderTable(); });
        return el('span', { class: 'account-import__radio' }, [input, el('label', { for: id, text: t(`account_import.filter.${f}`) })]);
      });
      previewBody.append(
        el('fieldset', { class: 'account-import__filter' }, [el('legend', { text: t('account_import.filter_label') }), ...radios]),
        el('div', { dataset: { table: '' } }),
      );
      renderTable();
    }
    previewBody.append(el('div', { class: 'account-import__run', dataset: { run: '' } }));
    renderActions();
  }

  function renderTable() {
    const box = previewBody.querySelector('[data-table]');
    if (!box) return;
    clear(box);
    const rows = core.filterRows(preview.rows, filter);
    const pg = core.pageOf(rows, page);
    page = pg.page;
    if (!rows.length) {
      box.append(el('p', { class: 'muted', text: t('account_import.none_filtered') }));
      return;
    }
    const head = ['col_row', 'col_action', 'col_login', 'col_name', 'col_role', 'col_status', 'col_reason'];
    box.append(el('div', { class: 'table-wrap' }, [el('table', { class: 'table account-import__table' }, [
      el('caption', { class: 'sr-only', text: t('account_import.table_caption') }),
      el('thead', {}, [el('tr', {}, head.map((k) => el('th', { scope: 'col', text: t(`account_import.${k}`) })))]),
      el('tbody', {}, pg.items.map((r) => el('tr', { class: `account-import__row--${r.status}`, dataset: { row: r.row, status: r.status } }, [
        el('td', { class: 'num', text: String(r.row) }),
        el('td', { class: 'nowrap', text: actionText(r.action) }),
        el('td', { class: 'mono', text: r.login_id }),
        el('td', { text: r.display_name ?? '' }),
        el('td', { class: 'nowrap', text: roleText(r.role) }),
        el('td', { class: 'nowrap' }, [statusCell(r.status)]),
        el('td', {}, [reasons(r.codes)]),
      ]))),
    ])]));
    if (pg.pages > 1) {
      const prev = el('button', { type: 'button', class: 'btn btn--small', text: t('account_import.page_prev'), disabled: pg.page <= 1, dataset: { pagePrev: '' } });
      const next = el('button', { type: 'button', class: 'btn btn--small', text: t('account_import.page_next'), disabled: pg.page >= pg.pages, dataset: { pageNext: '' } });
      prev.addEventListener('click', () => { page -= 1; renderTable(); box.querySelector('[data-page-prev]')?.focus(); });
      next.addEventListener('click', () => { page += 1; renderTable(); box.querySelector('[data-page-next]')?.focus(); });
      box.append(el('div', { class: 'account-import__pager' }, [prev,
        el('span', { role: 'status', text: t('account_import.page_info', { from: pg.from, to: pg.to, total: pg.total }) }), next]));
    }
  }

  function renderActions() {
    const box = previewBody.querySelector('[data-run]');
    if (!box || !preview) return;
    clear(box);
    const s = preview.summary;
    if (!preview.file_errors.length && s.error > 0) {
      const skip = el('input', { type: 'checkbox', id: 'ai-skip', checked: skipErrors, dataset: { skipErrors: '' } });
      skip.addEventListener('change', () => { skipErrors = skip.checked; renderActions(); previewBody.querySelector('#ai-skip')?.focus(); });
      box.append(el('p', { class: 'account-import__skip' }, [skip, el('label', { for: 'ai-skip', text: t('account_import.skip_errors', { n: s.error }) })]));
    }
    const can = core.canExecute(preview, skipErrors);
    const run = el('button', { type: 'button', class: 'btn btn--primary', text: t('account_import.execute'), disabled: !can.ok || busy, dataset: { execute: '' } });
    run.addEventListener('click', execute);
    const restart = el('button', { type: 'button', class: 'btn', text: t('account_import.restart'), dataset: { restart: '' } });
    restart.addEventListener('click', reset);
    const note = can.reason === 'errors' ? t('account_import.blocked_errors') : (can.reason === 'nothing' ? t('account_import.nothing') : '');
    box.append(el('div', { class: 'account-import__actions' }, [run, restart]));
    if (note) {
      const id = 'ai-run-note';
      box.append(el('p', { class: 'muted', id, dataset: { runNote: '' }, text: note }));
      run.setAttribute('aria-describedby', id);
    }
  }

  async function execute() {
    if (busy || !core.canExecute(preview, skipErrors).ok) return;
    const ok = await confirmDialog({ title: t('account_import.confirm_title'), message: core.confirmLines(preview.summary, skipErrors).join(' '),
      confirmLabel: t('account_import.execute'), warn: true });
    if (!ok) return;
    showError('');
    status.textContent = t('account_import.executing');
    setBusy(true);
    renderActions();
    try {
      const result = await api.post('/api/v1/admin/account-import/execute', undefined, {
        raw: bytes, query: { encoding: preview.encoding_requested, digest: preview.digest, skip_errors: skipErrors ? '1' : '0' },
      });
      status.textContent = '';
      renderResult(result);
      setStep('result');
    } catch (e) {
      status.textContent = '';
      setBusy(false);
      if (e.code === 'import_preview_stale') {
        // 그사이 바뀜 — 아무것도 반영되지 않았다. 같은 파일로 미리보기를 다시 해 새 판정을 보인다
        await runPreview(preview.encoding_requested);
        showError(errorText(e));
        return;
      }
      showError(errorText(e));
      renderActions();
    } finally {
      busy = false;
    }
  }

  function renderResult(result) {
    clear(resultBody);
    const s = result.summary;
    const created = result.rows.filter((r) => r.result === 'created');
    resultBody.append(el('p', { dataset: { resultSummary: '' }, text: t('account_import.result_summary', { created: s.created, deactivated: s.deactivated, skip: s.skip, error: s.error }) }));
    if (created.some((r) => r.temp_password)) {
      const at = created.find((r) => r.temp_password_expires_at)?.temp_password_expires_at;
      resultBody.append(
        el('div', { class: 'notice notice--warn', role: 'status' }, [iconWithLabel('system.warn', 16, t('account_import.result_once'))]),
        el('p', { class: 'muted', text: t('account_import.result_expires', { at: at ? formatDateTime(at, tz) : '—', hours: result.temp_password_hours }) }),
      );
      const csvBtn = el('button', { type: 'button', class: 'btn', text: t('account_import.result_csv'), dataset: { resultCsv: '' } });
      const done = el('p', { class: 'muted', role: 'status', 'aria-live': 'polite', dataset: { resultCsvDone: '' } });
      csvBtn.addEventListener('click', () => {
        const blob = new Blob([core.resultCsv(result.rows)], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        // 이 화면이 만든 blob: 주소(사용자 입력 아님) — el()의 주소 검사를 거치지 않고 직접 만든다
        const a = document.createElement('a');
        a.href = url;
        a.download = core.resultFileName();
        a.hidden = true;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 10000);
        csvBtn.disabled = true; // 한 번만
        done.textContent = t('account_import.result_csv_done');
      });
      resultBody.append(el('div', { class: 'account-import__actions' }, [csvBtn]), done);
    }
    const head = ['col_row', 'col_action', 'col_login', 'col_name', 'col_result', 'col_temp', 'col_reason'];
    const resultIcon = { created: 'system.ok', deactivated: 'system.ok', skipped: 'system.warn', error: 'system.error' };
    resultBody.append(el('div', { class: 'table-wrap' }, [el('table', { class: 'table account-import__table', dataset: { resultTable: '' } }, [
      el('caption', { class: 'sr-only', text: t('account_import.step_result') }),
      el('thead', {}, [el('tr', {}, head.map((k) => el('th', { scope: 'col', text: t(`account_import.${k}`) })))]),
      el('tbody', {}, result.rows.map((r) => el('tr', { dataset: { row: r.row, result: r.result } }, [
        el('td', { class: 'num', text: String(r.row) }),
        el('td', { class: 'nowrap', text: actionText(r.action) }),
        el('td', { class: 'mono', text: r.login_id }),
        el('td', { text: r.display_name ?? '' }),
        el('td', { class: 'nowrap' }, [iconWithLabel(resultIcon[r.result], 16, t(`account_import.result.${r.result}`))]),
        r.result === 'created' && !r.temp_password
          ? el('td', { class: 'muted', text: t('account_import.external_login') }) // P28B 외부 인증 계정(비밀번호 없음)
          : el('td', { class: 'mono account-import__secret', text: r.temp_password ?? '' }),
        el('td', {}, [reasons(r.codes)]),
      ]))),
    ])]));
    const again = el('button', { type: 'button', class: 'btn', text: t('account_import.restart') });
    again.addEventListener('click', reset);
    const meta = document.querySelector('meta[name="app-base"]')?.getAttribute('content') || '';
    resultBody.append(el('div', { class: 'account-import__actions' }, [el('a', { class: 'btn btn--primary', href: `${meta}/admin/users`, text: t('account_import.to_users') }), again]));
    // 파일 바이트·미리보기는 더 쓰지 않는다
    bytes = null;
    preview = null;
  }

  function reset() {
    file = null;
    bytes = null;
    preview = null;
    form.reset();
    clear(previewBody);
    clear(resultBody); // 임시 비밀번호를 화면에서 지운다
    showError('');
    status.textContent = '';
    setStep('file');
    fileInput.focus();
  }
}
