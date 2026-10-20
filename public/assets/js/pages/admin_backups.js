// 관리자 "백업·복원"(P27B): 요약(경고는 아이콘 + 문구), 지금 백업(진행 aria-live·취소), 복원 리허설, 키 내보내기(재인증 + 암호문구 두 번),
// 묶음 올리기(8MB 조각, 조각마다 SHA-256), 목록(검증·내려받기·복원·삭제), 복원 대화 흐름(사전 검증 → 미리보기 → 설정 → 키 → 확인).
// 값은 모두 textContent(경로·호스트 이름·묶음 이름도 텍스트). 비밀번호·암호문구는 요청에만 쓰고 DOM에 남기지 않는다(대화상자를 닫으면 지움).
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { el, clear, setText } from '../core/dom.js';
import { formatDateTime, timezoneFromDocument } from '../core/time.js';
import { iconWithLabel } from '../ui/icons.js';
import { errorText, formDialog, field } from '../ui/form_dialog.js';
import { confirmDialog } from '../ui/dialog.js';
import { toast } from '../ui/toast.js';
import { chunkRanges, sha256Hex, formatBytes } from '../lib/upload_core.js';
import {
  verifyState, canRestore, canDelete, summaryWarnings, jobText, jobPercent, defaultChecked, restoreBody, countRows, lossText,
  validBundleName, errorMessage, scheduleText, diskEstimate, estimateText, summaryEstimateInput,
} from '../lib/backup_core.js';

const root = document.querySelector('[data-admin-backups]');
if (root) {
  const api = createApi();
  const tz = timezoneFromDocument();
  const $ = (s) => root.querySelector(s);
  const base = document.querySelector('meta[name="app-base"]')?.content ?? '';
  const fmt = (iso) => (iso ? formatDateTime(iso, tz) : '—');
  const time = (iso) => (iso ? el('time', { datetime: iso, text: fmt(iso) }) : '—');
  const num = (n) => Number(n ?? 0).toLocaleString('ko-KR');
  let data = null;
  let jobTimer = null;

  // ---------- 요약 ----------
  function renderWarnings(d) {
    const box = $('[data-warnings]');
    clear(box);
    for (const w of summaryWarnings(d)) {
      box.appendChild(el('li', { class: `notice notice--${w.tone}`, dataset: { warning: w.id } }, [
        iconWithLabel({ error: 'system.error', warn: 'system.warn' }[w.tone] ?? 'ui.info', 16, w.text),
      ]));
    }
    box.hidden = box.childElementCount === 0;
    const link = $('[data-restore-link]');
    const r = d.restore;
    const show = d.restore_pending || r?.maintenance || r?.status === 'running' || r?.status === 'requested';
    link.hidden = !show;
    if (show) {
      clear(link);
      link.appendChild(iconWithLabel('tool_status.verifying', 16, t('backups.restore_running_link')));
    }
  }

  function renderSummary(d) {
    const dl = $('[data-summary]');
    clear(dl);
    const add = (label, ...value) => dl.append(el('dt', { text: label }), el('dd', {}, value));
    const ls = d.last_success;
    add(t('backups.sum.last_success'), ls
      ? el('span', {}, [iconWithLabel('system.ok', 16, t('backups.sum.last_success_value', { at: fmt(ls.at), size: formatBytes(ls.size), kind: t(`backups.kind.${ls.kind}`) }))])
      : iconWithLabel('system.warn', 16, t('backups.sum.never')));
    if (d.last_failure) {
      add(t('backups.sum.last_failure'), iconWithLabel('system.error', 16, t('backups.sum.last_failure_value', { at: fmt(d.last_failure.at), reason: errorMessage(d.last_failure.code, d.last_failure.params) })));
    }
    // P27E: 자동 백업 — 주기 · 다음 예정, 꺼져 있으면 "자동 백업 꺼짐"(설정 관리 링크)
    const sched = d.schedule;
    const settingsLink = el('a', { href: `${root.dataset.settingsHref}#backup`, text: t('backups.sum.change_schedule') });
    add(t('backups.sum.auto'), ...(sched && sched.enabled === false
      ? [iconWithLabel('ui.info', 16, t('backups.sum.auto_off')), ' ', settingsLink]
      : [el('span', { dataset: { schedule: '' }, text: scheduleText(sched) }), ' · ', t('backups.sum.next'), ' ', time(d.next_daily), ' ',
        el('span', { class: 'muted', text: t('backups.sum.retention', { days: d.retention_days }) }), ' ', settingsLink]));
    const est = diskEstimate(summaryEstimateInput(d));
    if (est) add(t('backups.sum.estimate'), est.warn ? iconWithLabel('system.warn', 16, estimateText(est)) : el('span', { text: estimateText(est) }));
    const dirLink = el('a', { href: `${root.dataset.settingsHref}#backup`, text: t('backups.sum.change_dir') });
    add(t('backups.sum.dir'), el('code', { class: 'mono break', text: d.dir }), ' ',
      el('span', { class: 'muted', text: `(${t(`config_settings.source.${d.dir_source}`)})` }), ' ', dirLink);
    add(t('backups.sum.free'), d.dir_free_bytes === null || d.dir_free_bytes === undefined ? '—' : formatBytes(d.dir_free_bytes));
    const lr = d.last_rehearsal;
    add(t('backups.sum.rehearsal'), lr
      ? iconWithLabel(lr.ok ? 'system.ok' : 'system.error', 16, t(lr.ok ? 'backups.sum.rehearsal_ok' : 'backups.sum.rehearsal_failed',
        { at: fmt(lr.at), file: lr.file, reason: errorMessage(lr.code) }))
      : t('backups.sum.rehearsal_never'));
    add(t('backups.sum.key'), d.key.available
      ? el('span', {}, [t('backups.sum.key_fp'), ' ', el('code', { class: 'mono', text: d.key.fingerprint })])
      : iconWithLabel('system.warn', 16, t('backups.sum.key_none')));
    add(t('backups.sum.worker'), d.worker_running ? iconWithLabel('system.ok', 16, t('backups.sum.worker_on')) : iconWithLabel('system.warn', 16, t('backups.sum.worker_off')));
  }

  // ---------- 작업 진행 ----------
  function renderJob(d) {
    const job = d.job;
    $('[data-job]').hidden = !job;
    const busy = Boolean(job) || d.restore_pending || Boolean(d.restore?.maintenance);
    $('[data-backup-now]').disabled = busy || !d.worker_running;
    $('[data-rehearse]').disabled = busy || !d.worker_running || d.items.length === 0;
    $('[data-key-export]').disabled = !d.key.available;
    if (!job) return;
    setText($('[data-job-text]'), jobText(job));
    const pct = jobPercent(job);
    const bar = $('[data-job-progress]');
    if (pct === null) bar.removeAttribute('value'); else bar.value = pct; // 셀 수 없는 단계는 흐르는 막대
    $('[data-job-cancel]').hidden = job.type !== 'backup' || job.cancelling;
  }

  function scheduleJobPoll(d) {
    clearTimeout(jobTimer);
    if (d.job) jobTimer = setTimeout(() => load({ quiet: true }), 2000);
  }

  // ---------- 목록 ----------
  function rowActions(item) {
    const lastFile = data.last_success?.file;
    const btn = (cls, iconName, labelKey, handler, attrs = {}) => {
      const b = el('button', { type: 'button', class: `btn btn--small ${cls}`, 'aria-label': `${t(labelKey)}: ${item.name}`, ...attrs }, [iconWithLabel(iconName, 16, t(labelKey))]);
      b.addEventListener('click', () => handler(item, b));
      return b;
    };
    const busy = Boolean(data.job) || data.restore_pending || Boolean(data.restore?.maintenance);
    const restoreAttrs = canRestore(item) && !busy ? {} : { disabled: true, title: t(busy ? 'backups.restore.busy' : 'backups.restore.need_verify') };
    const deleteAttrs = canDelete(item, lastFile) ? {} : { disabled: true, title: t('backups.delete_protected') };
    return el('div', { class: 'admin-backups__row-actions' }, [
      btn('', 'action.verify', 'icon.action.verify', verifyBundle),
      btn('', 'action.export', 'backups.download', downloadBundle),
      btn('', 'action.restore', 'icon.action.restore', startRestore, restoreAttrs),
      btn('btn--danger-outline', 'action.delete', 'icon.action.delete', deleteBundle, deleteAttrs),
    ]);
  }

  function row(item) {
    const v = verifyState(item);
    const verify = [el('span', { class: `badge badge--tone-${v.tone}` }, [iconWithLabel(v.icon, 16, t(v.key))])];
    if (item.verified?.at) verify.push(el('div', { class: 'muted', text: fmt(item.verified.at) }));
    if (item.verified?.ok === false && item.verified.code) verify.push(el('div', { class: 'muted', text: errorMessage(item.verified.code) }));
    const created = item.created_at ? time(item.created_at) : el('span', { class: 'muted', text: t('backups.created_unknown') });
    const nameLine = el('div', { class: 'admin-backups__name mono', text: item.name });
    const tags = [];
    if (item.name === data.last_success?.file) tags.push(el('span', { class: 'tag', text: t('backups.tag.last_success') }));
    if (item.uploaded) tags.push(el('span', { class: 'tag', text: t('backups.tag.uploaded') }));
    if (item.host) tags.push(el('span', { class: 'muted', text: t('backups.tag.host', { host: item.host }) }));
    return el('tr', { dataset: { bundle: item.name, verify: v.state } }, [
      el('td', {}, [created, nameLine, ...tags.flatMap((n) => [' ', n])]),
      el('td', { text: t(`backups.kind.${item.kind}`) }),
      el('td', { class: 'admin-backups__num', text: formatBytes(item.size) }),
      el('td', { text: item.snapshots === null ? '—' : t(item.snapshots ? 'backups.snapshots_yes' : 'backups.snapshots_no') }),
      el('td', { text: item.schema_version ? t('backups.version_value', { app: item.app_version ?? '—', schema: item.schema_version }) : '—' }),
      el('td', {}, verify),
      el('td', {}, [rowActions(item)]),
    ]);
  }

  function renderList(d) {
    const rows = $('[data-rows]');
    clear(rows);
    for (const item of d.items) rows.appendChild(row(item));
    $('[data-table]').hidden = d.items.length === 0;
    $('[data-empty]').hidden = d.items.length > 0;
    setText($('[data-list-intro]'), t('backups.list_intro', { n: num(d.items.length) }));
    setText($('[data-upload-help]'), t('backups.upload.help', { max: num(d.upload_max_mb) }));
  }

  async function load({ quiet = false } = {}) {
    let d;
    try {
      d = await api.get('/api/v1/admin/backups');
      $('[data-error]').hidden = true;
    } catch (e) {
      $('[data-loading]').hidden = true;
      $('[data-error]').textContent = errorText(e);
      $('[data-error]').hidden = false;
      return;
    }
    const wasJob = data?.job;
    data = d;
    $('[data-loading]').hidden = true;
    for (const s of ['[data-summary-card]', '[data-actions-card]', '[data-list-card]']) $(s).hidden = false;
    renderWarnings(d);
    renderSummary(d);
    renderJob(d);
    renderList(d);
    if (wasJob && !d.job) jobFinished(wasJob, d);
    scheduleJobPoll(d);
    if (!quiet) root.dataset.loaded = '1';
  }

  /** 작업이 끝나면 결과를 한 줄로(진행 영역 아래, aria-live) */
  function jobFinished(job, d) {
    const done = $('[data-job-done]');
    if (job.type === 'rehearsal') {
      const lr = d.last_rehearsal;
      setText(done, lr ? t(lr.ok ? 'backups.job.rehearsal_done' : 'backups.job.rehearsal_failed', { file: lr.file, reason: errorMessage(lr.code), sec: ((lr.ms ?? 0) / 1000).toFixed(1) }) : '');
      return;
    }
    const lf = d.last_failure;
    const ls = d.last_success;
    if (ls && (!lf || String(ls.at) >= String(lf.at))) setText(done, t('backups.job.backup_done', { file: ls.file, size: formatBytes(ls.size) }));
    else if (lf) setText(done, t('backups.job.backup_failed', { reason: errorMessage(lf.code, lf.params) }));
  }

  // ---------- 동작 ----------
  async function backupNow() {
    const btn = $('[data-backup-now]');
    btn.disabled = true;
    setText($('[data-job-done]'), '');
    try {
      await api.post('/api/v1/admin/backups', { snapshots: $('[data-snapshots]').checked });
      toast(t('backups.job.requested'), { type: 'info' });
    } catch (e) {
      toast(errorText(e), { type: 'error' });
    }
    await load();
  }

  async function cancelJob() {
    const ok = await confirmDialog({ title: t('backups.job.cancel'), message: t('backups.job.cancel_confirm'), confirmLabel: t('backups.job.cancel'), warn: true, confirmIcon: 'action.cancel' });
    if (!ok) return;
    try {
      await api.post('/api/v1/admin/backups/cancel', {});
    } catch (e) {
      toast(errorText(e), { type: 'error' });
    }
    await load();
  }

  async function rehearse() {
    const latest = data.items.find((i) => canRestore(i));
    if (!latest) { toast(t('backups.rehearse_none'), { type: 'warn' }); return; }
    const ok = await confirmDialog({ title: t('icon.action.rehearse'), message: t('backups.rehearse_confirm', { file: latest.name }), confirmLabel: t('icon.action.rehearse'), confirmIcon: 'action.rehearse' });
    if (!ok) return;
    setText($('[data-job-done]'), '');
    try {
      await api.post('/api/v1/admin/backups/rehearse', { name: latest.name });
      toast(t('backups.job.rehearsal_requested'), { type: 'info' });
    } catch (e) {
      toast(errorText(e), { type: 'error' });
    }
    await load();
  }

  /** 비밀번호 칸(재인증) */
  const passwordField = (id) => field(id, 'password', t('backups.reauth.password'), { type: 'password', autocomplete: 'current-password', required: true }, t('backups.reauth.help'));

  async function keyExport() {
    const result = await formDialog({
      title: t('icon.action.export_key'),
      message: t('backups.key.export_message'),
      submitLabel: t('backups.key.export_submit'),
      build: (id) => {
        const pw = passwordField(id);
        const p1 = field(id, 'passphrase', t('backups.key.passphrase'), { type: 'password', autocomplete: 'new-password', minlength: 12, required: true }, t('backups.key.passphrase_help'));
        const p2 = field(id, 'passphrase2', t('backups.key.passphrase2'), { type: 'password', autocomplete: 'new-password', required: true });
        const warn = el('p', { class: 'notice notice--warn admin-backups__dialog-note' }, [iconWithLabel('system.warn', 16, t('backups.key.keep_apart'))]);
        const input = (w) => w.querySelector('input');
        return {
          content: [warn, pw, p1, p2],
          focus: input(pw),
          onSubmit: async () => {
            const r = await api.post('/api/v1/admin/backups/key-export', { password: input(pw).value, passphrase: input(p1).value, passphrase2: input(p2).value });
            for (const w of [pw, p1, p2]) input(w).value = '';
            return r;
          },
        };
      },
    });
    if (!result) return;
    const blob = new Blob([result.content], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    // 이 화면이 만든 blob: 주소(사용자 입력 아님) — el()의 주소 검사(http·https·상대만)를 거치지 않고 직접 만든다
    const a = document.createElement('a');
    a.href = url;
    a.download = result.file_name;
    a.hidden = true;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 10000);
    toast(t('backups.key.exported', { file: result.file_name, fp: result.fingerprint }), { type: 'success' });
  }

  async function verifyBundle(item, button) {
    button.disabled = true;
    try {
      const r = await api.post(`/api/v1/admin/backups/${encodeURIComponent(item.name)}/verify`, {});
      toast(r.ok ? t('backups.verify_done_ok', { file: item.name }) : t('backups.verify_done_failed', { file: item.name, reason: errorMessage(r.code, r.params) }), { type: r.ok ? 'success' : 'error' });
    } catch (e) {
      toast(errorText(e), { type: 'error' });
    }
    await load();
  }

  async function downloadBundle(item) {
    const r = await formDialog({
      title: t('backups.download'),
      message: t('backups.download_message', { file: item.name, size: formatBytes(item.size) }),
      submitLabel: t('backups.download'),
      build: (id) => {
        const pw = passwordField(id);
        const input = pw.querySelector('input');
        return {
          content: [pw],
          focus: input,
          onSubmit: async () => {
            const res = await api.post(`/api/v1/admin/backups/${encodeURIComponent(item.name)}/download`, { password: input.value });
            input.value = '';
            return res;
          },
        };
      },
    });
    if (r?.url) globalThis.location.assign(`${base}${r.url}`); // 한 번 쓰는 주소(60초), 서버가 스트리밍
  }

  async function deleteBundle(item) {
    const r = await formDialog({
      title: t('backups.delete_title'),
      message: t('backups.delete_message', { file: item.name }),
      submitLabel: t('icon.action.delete'),
      danger: true,
      build: (id) => {
        const pw = passwordField(id);
        const input = pw.querySelector('input');
        return {
          content: [pw],
          focus: input,
          onSubmit: async () => {
            const res = await api.delete(`/api/v1/admin/backups/${encodeURIComponent(item.name)}`, { body: { password: input.value } });
            input.value = '';
            return res ?? true;
          },
        };
      },
    });
    if (r) {
      toast(t('backups.deleted', { file: item.name }), { type: 'success' });
      await load();
    }
  }

  // ---------- 복원 대화 흐름 ----------
  function section(title, children, attrs = {}) {
    return el('section', { class: 'restore-flow__section', ...attrs }, [el('h3', { text: title }), ...children]);
  }

  function countsTable(pre) {
    const rows = countRows(pre.counts, pre.current);
    return el('table', { class: 'table restore-flow__counts' }, [
      el('caption', { class: 'sr-only', text: t('backups.restore.counts_caption') }),
      el('thead', {}, [el('tr', {}, [
        el('th', { scope: 'col', text: t('backups.restore.col_item') }),
        el('th', { scope: 'col', class: 'admin-backups__num', text: t('backups.restore.col_current') }),
        el('th', { scope: 'col', class: 'admin-backups__num', text: t('backups.restore.col_backup') }),
      ])]),
      el('tbody', {}, rows.map((r) => el('tr', { dataset: { count: r.key } }, [
        el('th', { scope: 'row', text: t(`backups.restore.count.${r.key}`) }),
        el('td', { class: 'admin-backups__num', text: r.current === null ? '—' : num(r.current) }),
        el('td', { class: 'admin-backups__num', text: num(r.backup) }),
      ]))),
    ]);
  }

  function configSection(pre, state) {
    const diff = pre.config ?? [];
    const toggle = el('input', { type: 'checkbox', dataset: { withConfig: '' } });
    const list = el('div', { class: 'restore-flow__config', hidden: true });
    if (diff.length === 0) {
      return section(t('backups.restore.step_config'), [el('p', { class: 'muted', text: t('backups.restore.config_none') })]);
    }
    const table = el('table', { class: 'table restore-flow__diff' }, [
      el('caption', { class: 'sr-only', text: t('backups.restore.config_caption') }),
      el('thead', {}, [el('tr', {}, [
        el('th', { scope: 'col', text: t('backups.restore.col_apply') }),
        el('th', { scope: 'col', text: t('backups.restore.col_key') }),
        el('th', { scope: 'col', text: t('backups.restore.col_now') }),
        el('th', { scope: 'col', text: t('backups.restore.col_bundle') }),
      ])]),
      el('tbody', {}, diff.map((d, i) => {
        const id = `rf-cfg-${i}`;
        const box = el('input', { type: 'checkbox', id, checked: defaultChecked(d), dataset: { configKey: `${d.file}:${d.key}` } });
        if (defaultChecked(d)) state.checked.add(`${d.file}:${d.key}`);
        box.addEventListener('change', () => { if (box.checked) state.checked.add(`${d.file}:${d.key}`); else state.checked.delete(`${d.file}:${d.key}`); });
        const label = el('label', { for: id }, [el('code', { class: 'mono break', text: d.key }), ' ',
          el('span', { class: 'muted', text: t(d.file === 'local' ? 'backups.restore.file_local' : 'backups.restore.file_config') })]);
        if (d.machine) label.append(' ', el('span', { class: 'tag', text: t('backups.restore.machine') }));
        return el('tr', {}, [el('td', {}, [box]), el('td', {}, [label]),
          el('td', { class: 'mono break', text: d.current ?? '—' }), el('td', { class: 'mono break', text: d.backup ?? '—' })]);
      })),
    ]);
    list.append(el('p', { class: 'muted', text: t('backups.restore.config_help') }), el('div', { class: 'table-scroll' }, [table]));
    toggle.addEventListener('change', () => { state.withConfig = toggle.checked; list.hidden = !toggle.checked; });
    return section(t('backups.restore.step_config'), [
      el('label', { class: 'checkbox' }, [toggle, ' ', t('backups.restore.with_config', { n: diff.length })]),
      list,
    ]);
  }

  function keySection(pre, state) {
    const status = {
      ok: ['system.ok', 'backups.restore.key_ok'],
      none: ['ui.info', 'backups.restore.key_none'],
      mismatch: ['system.warn', 'backups.restore.key_mismatch'],
    }[pre.key] ?? ['ui.info', 'backups.restore.key_none'];
    const toggle = el('input', { type: 'checkbox', dataset: { withKey: '' } });
    const fileInput = el('input', { class: 'field__input', type: 'file', id: 'rf-key-file', accept: '.pjgkey' });
    const pass = el('input', { class: 'field__input', type: 'password', id: 'rf-key-pass', autocomplete: 'off' });
    const box = el('div', { class: 'restore-flow__key', hidden: true }, [
      el('div', { class: 'field' }, [el('label', { class: 'field__label', for: 'rf-key-file', text: t('backups.restore.key_file') }), fileInput]),
      el('div', { class: 'field' }, [el('label', { class: 'field__label', for: 'rf-key-pass', text: t('backups.key.passphrase') }), pass]),
      el('p', { class: 'muted', text: t('backups.restore.key_help') }),
    ]);
    toggle.addEventListener('change', () => { state.withKey = toggle.checked; box.hidden = !toggle.checked; });
    state.readKey = async () => {
      if (!state.withKey) return null;
      const f = fileInput.files?.[0];
      if (!f) throw new Error(t('backups.restore.key_file_needed'));
      return { content: await f.text(), passphrase: pass.value };
    };
    state.clearKey = () => { pass.value = ''; };
    return section(t('backups.restore.step_key'), [
      el('p', {}, [iconWithLabel(status[0], 16, t(status[1]))]),
      el('label', { class: 'checkbox' }, [toggle, ' ', t('backups.restore.with_key')]),
      box,
    ]);
  }

  async function startRestore(item, button) {
    button.disabled = true;
    let pre;
    try {
      toast(t('backups.restore.prechecking', { file: item.name }), { type: 'info', timeout: 2000 });
      pre = await api.post(`/api/v1/admin/backups/${encodeURIComponent(item.name)}/precheck`, {});
    } catch (e) {
      button.disabled = false;
      await confirmDialog({ title: t('backups.restore.precheck_failed_title'), message: errorText(e), confirmLabel: t('component.close'), danger: true });
      return;
    }
    button.disabled = false;
    const state = { checked: new Set(), withConfig: false, withKey: false, readKey: async () => null, clearKey: () => {} };
    const confirmWord = t('backups.restore.confirm_word');
    const r = await formDialog({
      title: t('backups.restore.title'),
      message: t('backups.restore.message', { file: item.name }),
      submitLabel: t('backups.restore.submit'),
      wide: true,
      danger: true,
      build: (id) => {
        const danger = el('p', { class: 'notice notice--error restore-flow__danger' }, [iconWithLabel('ui.danger', 16, t('backups.restore.danger'))]);
        const info = el('dl', { class: 'detail-list' }, [
          el('dt', { text: t('backups.restore.created') }), el('dd', { text: pre.created_at_local ?? fmt(pre.created_at) }),
          el('dt', { text: t('backups.restore.source') }), el('dd', { text: t('backups.restore.source_value', { host: pre.host ?? '—', os: pre.os ?? '—' }) }),
          el('dt', { text: t('backups.restore.version') }), el('dd', { text: t('backups.restore.version_value', { app: pre.app_version ?? '—', schema: pre.schema_version, current: pre.app_schema }) }),
          el('dt', { text: t('backups.restore.snapshots') }), el('dd', { text: pre.snapshots ? t('backups.restore.snapshots_yes', { n: num(pre.snapshot_count) }) : t('backups.restore.snapshots_no') }),
          el('dt', { text: t('backups.restore.blanked') }), el('dd', { text: blankedText(pre.blanked) }),
        ]);
        const checks = section(t('backups.restore.step_precheck'), [
          el('p', {}, [iconWithLabel('system.ok', 16, t('backups.restore.precheck_ok', { size: formatBytes(pre.size), need: num(pre.need_mb) }))]),
          pre.migrate ? el('p', {}, [iconWithLabel('ui.info', 16, t('backups.restore.migrate', { from: pre.schema_version, to: pre.app_schema }))]) : null,
        ]);
        const preview = section(t('backups.restore.step_preview'), [
          info, countsTable(pre),
          el('p', { class: 'notice notice--warn', dataset: { loss: '' } }, [iconWithLabel('system.warn', 16, lossText(pre))]),
        ]);
        const waitName = `${id}-wait`;
        const radio = (value, key, checked) => el('label', { class: 'checkbox' }, [el('input', { type: 'radio', name: waitName, value, checked }), ' ', t(key)]);
        const wait = section(t('backups.restore.step_wait'), [radio('wait', 'backups.restore.wait_wait', true), radio('cancel', 'backups.restore.wait_cancel', false)]);
        const pw = passwordField(id);
        const word = field(id, 'confirm', t('backups.restore.confirm_label', { word: confirmWord }), { autocomplete: 'off', spellcheck: 'false' });
        const pwInput = pw.querySelector('input');
        const wordInput = word.querySelector('input');
        const confirmSec = section(t('backups.restore.step_confirm'), [el('p', { text: t('backups.restore.confirm_help') }), pw, word]);
        // 처음 포커스는 내용 영역(맨 위의 위험 안내·사전 검증부터 읽게 — 비밀번호 칸에 두면 맨 아래로 스크롤됨). 키보드로 스크롤할 수 있게 tabindex 0
        const scroll = el('div', { class: 'dialog__scroll restore-flow', tabindex: '0', role: 'region', 'aria-label': t('backups.restore.title') },
          [danger, checks, preview, configSection(pre, state), keySection(pre, state), wait, confirmSec]);
        return {
          content: [scroll],
          focus: scroll,
          onSubmit: async () => {
            if (wordInput.value.trim() !== confirmWord) throw new Error(t('backups.restore.confirm_mismatch', { word: confirmWord }));
            const key = await state.readKey();
            const waitMode = scroll.querySelector(`input[name="${waitName}"]:checked`)?.value ?? 'wait';
            const body = restoreBody({ name: item.name, password: pwInput.value, confirm: wordInput.value.trim(), waitMode, withConfig: state.withConfig, checked: state.checked, diff: pre.config, key });
            try {
              return await api.post('/api/v1/admin/restore', body);
            } finally {
              pwInput.value = '';
              state.clearKey();
            }
          },
        };
      },
    });
    if (r?.status_url) globalThis.location.assign(`${base}${r.status_url}`);
  }

  function blankedText(blanked) {
    const keys = [...(blanked?.config ?? []), ...(blanked?.settings_local ?? [])];
    return keys.length === 0 ? t('backups.restore.blanked_none') : t('backups.restore.blanked_list', { n: keys.length, list: keys.slice(0, 8).join(', ') + (keys.length > 8 ? ' …' : '') });
  }

  // ---------- 묶음 올리기 ----------
  async function upload(ev) {
    ev.preventDefault();
    const form = ev.currentTarget;
    const err = $('[data-upload-error]');
    err.hidden = true;
    const file = form.elements.file.files?.[0];
    const fail = (text) => { setText(err, text); err.hidden = false; };
    if (!file) { fail(t('backups.upload.need_file')); return; }
    if (!validBundleName(file.name)) { fail(t('backups.err.bundle_name_invalid')); return; }
    const progress = $('[data-upload-progress]');
    const text = $('[data-upload-text]');
    const btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    let id = null;
    try {
      const s = await api.post('/api/v1/admin/backup-uploads', { file_name: file.name, total_size: file.size });
      id = s.id;
      progress.hidden = false;
      const ranges = chunkRanges(file.size, s.chunk_size);
      for (const { index, start, end } of ranges) {
        const buf = await file.slice(start, end).arrayBuffer();
        await api.putRaw(`/api/v1/admin/backup-uploads/${s.id}/chunks/${index}`, buf, { headers: { 'X-Chunk-Sha256': await sha256Hex(buf) } });
        progress.value = Math.round(((index + 1) / ranges.length) * 100);
        setText(text, t('backups.upload.progress', { done: index + 1, total: ranges.length }));
      }
      const done = await api.post(`/api/v1/admin/backup-uploads/${s.id}/complete`, {});
      id = null;
      setText(text, '');
      toast(t('backups.upload.done', { file: done.name }), { type: 'success' });
      form.reset();
      await load();
    } catch (e) {
      fail(errorText(e));
      setText(text, '');
      if (id) api.delete(`/api/v1/admin/backup-uploads/${id}`).catch(() => {});
    } finally {
      btn.disabled = false;
      progress.hidden = true;
      progress.value = 0;
    }
  }

  $('[data-backup-now]').addEventListener('click', backupNow);
  $('[data-job-cancel]').addEventListener('click', cancelJob);
  $('[data-rehearse]').addEventListener('click', rehearse);
  $('[data-key-export]').addEventListener('click', keyExport);
  $('[data-upload-form]').addEventListener('submit', upload);
  load();
}
