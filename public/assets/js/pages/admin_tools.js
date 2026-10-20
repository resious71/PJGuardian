// 관리자 "분석 도구"(P20A): 도구별 설치 버전·상태·선택 이유, Node 상태, 차단·고정·재검증·삭제 예정, 설치 패키지 스테이징.
// 웹은 bin/에 쓰지 않는다: 동작은 DB 값 변경·관리 작업 요청·패키지 보관뿐이고, 설치·삭제는 화면이 보여 주는 서버 명령으로 한다.
// 모든 변경은 확인 대화상자(경고 아이콘 + 글자, 사유 입력)를 거친다. 값은 textContent로만 넣는다(innerHTML 금지).
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { el, clear, setText } from '../core/dom.js';
import { formatDateTime, timezoneFromDocument } from '../core/time.js';
import { createPoller } from '../core/poll.js';
import { icon, iconWithLabel } from '../ui/icons.js';
import { toast } from '../ui/toast.js';
import { formDialog, errorText } from '../ui/form_dialog.js';
import { toolBadges, selectionLine, toolActions, pinNeedsVerification, stagingView, taskBadge } from '../lib/admin_core.js';
import { chunkRanges, sha256Hex, formatBytes } from '../lib/upload_core.js';

const POLL_MS = 4000;
const NODE_POLL_MS = 500; // Node "상태 갱신" 결과 기다리기(P21C)
const root = document.querySelector('[data-admin-tools]');
// P25: git·p4는 읽기 전용(버전·체크섬·스모크만, 동작 버튼 없음)
const TOOL_TITLES = { ruff: 'ruff', eslint: 'ESLint', git: 'git', p4: 'p4 (Perforce)' };
if (root) init(root);

/** 확인 대화상자: 경고 아이콘 + 문장 + 사유(선택). 확인하면 {reason}, 취소면 null */
export function confirmWithReason({ title, message, submitLabel }) {
  return formDialog({
    title, message: '', submitLabel,
    build: (id) => {
      const reason = el('textarea', { class: 'field__input', id: `${id}-reason`, rows: 2, maxlength: 500 });
      return {
        content: [
          el('p', { class: 'notice notice--warn', id: `${id}-warn` }, [icon('system.warn', 16), ' ', message]),
          el('div', { class: 'field' }, [
            el('label', { class: 'field__label', for: `${id}-reason`, text: t('admin.reason') }),
            reason,
          ]),
        ],
        focus: reason,
        onSubmit: async () => ({ reason: reason.value.trim() }),
      };
    },
  });
}

function init(section) {
  const api = createApi();
  const tz = timezoneFromDocument();
  const $ = (sel) => section.querySelector(sel);
  const time = (iso) => (iso ? formatDateTime(iso, tz) : '—');
  let busy = false;

  function badgeEl(b) {
    return el('span', { class: `badge badge--tone-${b.tone}`, dataset: { state: b.key } }, [iconWithLabel(b.icon, 16, t(b.key))]);
  }

  function renderNode(node) {
    const dl = $('[data-node]');
    clear(dl);
    const add = (label, value) => dl.append(el('dt', { text: label }), el('dd', {}, [value]));
    if (!node) {
      add(t('admin_tools.node_state'), badgeEl({ icon: 'system.warn', key: 'admin_tools.node_unknown', tone: 'warn' }));
      return;
    }
    add(t('admin_tools.node_state'), badgeEl(node.ok ? { icon: 'system.ok', key: 'admin_tools.node_ok', tone: 'ok' }
      : { icon: 'system.error', key: `admin_tools.node_reason.${node.reason ?? 'unknown'}`, tone: 'error' }));
    add(t('admin_tools.node_version'), node.version ? `${node.version} (${t('admin_tools.node_min', { min: node.min })})` : '—');
    add(t('admin_tools.node_source'), t(`admin_tools.node_source.${node.source === 'config' ? 'config' : 'path'}`));
    add(t('admin_tools.node_checked'), time(node.checked_at));
    if (node.config_error) add(t('admin_tools.node_config'), el('span', { class: 'muted', text: t('admin_tools.node_config_error') }));
  }

  // ---- Node.js "상태 갱신"(P21C): 워커에게 다시 확인을 요청하고(웹은 node를 실행하지 않음) 결과가 생존 신호에 올 때까지 최대 10초 기다린다 ----
  let refreshing = false;
  const refreshBtn = $('[data-node-refresh]');
  const refreshStatus = $('[data-node-refresh-status]');
  async function refreshNode() {
    if (refreshing) return; // 연타해도 요청은 하나
    refreshing = true;
    refreshBtn.disabled = true;
    refreshBtn.setAttribute('aria-busy', 'true');
    setText(refreshStatus, t('admin_tools.node_refreshing'));
    try {
      const r = await api.post('/api/v1/admin/tools/node/refresh', {});
      const node = await waitNode(r.requested_at, r.wait_seconds);
      if (node) {
        renderNode(node);
        setText(refreshStatus, t('admin_tools.node_refreshed', { time: time(node.checked_at) }));
      } else {
        setText(refreshStatus, t('admin_tools.node_refresh_timeout', { seconds: r.wait_seconds }));
      }
    } catch (e) {
      if (e.code === 'worker_not_running') {
        const last = e.details?.node?.checked_at;
        setText(refreshStatus, t('admin_tools.node_worker_stopped', { time: last ? time(last) : '—' }));
      } else {
        setText(refreshStatus, errorText(e));
      }
    } finally {
      refreshing = false;
      refreshBtn.disabled = false;
      refreshBtn.removeAttribute('aria-busy');
    }
  }
  async function waitNode(requestedAt, seconds) {
    const deadline = Date.now() + seconds * 1000;
    while (Date.now() < deadline) {
      await new Promise((res) => setTimeout(res, NODE_POLL_MS));
      const d = await api.get('/api/v1/admin/tools');
      if (d.node && d.node.request_at === requestedAt) return d.node;
    }
    return null;
  }
  refreshBtn?.addEventListener('click', refreshNode);

  async function act(tool, v, action) {
    if (busy) return;
    const params = { tool, version: v?.version ?? '' };
    let message = t(`admin_tools.confirm.${action}`, params);
    if (action === 'pin' && pinNeedsVerification(v)) message = `${message} ${t('admin_tools.confirm.pin_needs_verify')}`;
    const r = await confirmWithReason({ title: t(`admin_tools.action.${action}`), message, submitLabel: t(`admin_tools.action.${action}`) });
    if (!r) return;
    busy = true;
    const path = action === 'unpin' ? `/api/v1/admin/tools/${tool}/unpin`
      : `/api/v1/admin/tools/${tool}/versions/${v.version}/${({ block: 'block', unblock: 'unblock', pin: 'pin', verify: 'verify', mark_removal: 'removal', unmark_removal: 'removal' })[action]}`;
    try {
      if (action === 'unmark_removal') await api.delete(path, { body: { reason: r.reason } });
      else {
        const res = await api.post(path, { reason: r.reason });
        if (action === 'verify') toast(t(res?.merged ? 'admin_tools.verify_merged' : 'admin_tools.verify_queued', params), { type: 'success' });
      }
      if (action !== 'verify') toast(t(`admin_tools.done.${action}`, params), { type: 'success' });
      await load();
    } catch (e) {
      toast(errorText(e), { type: 'error' });
    } finally {
      busy = false;
    }
  }

  function versionRow(tool, v, readOnly = false) {
    const badges = el('div', { class: 'badge-row' }, toolBadges(v).map(badgeEl));
    const check = v.last_check_at
      ? `${time(v.last_check_at)} · ${t(`admin_tools.method.${v.last_check_method ?? 'full'}`)}`
      : t('admin_tools.never_checked');
    const smoke = v.smoke_ok === null || v.smoke_ok === undefined ? '—'
      : el('span', {}, [iconWithLabel(Number(v.smoke_ok) === 1 ? 'system.ok' : 'system.error', 16,
        `${t(Number(v.smoke_ok) === 1 ? 'admin_tools.smoke_ok' : 'admin_tools.smoke_fail')}${v.smoke_message ? ` — ${v.smoke_message}` : ''}`)]);
    const problems = [];
    if (v.checksum_ok !== null && v.checksum_ok !== undefined && Number(v.checksum_ok) === 0) problems.push(t('admin_tools.checksum_fail'));
    for (const p of (v.problems ?? []).slice(0, 5)) problems.push(p);
    if (v.status === 'invalid' && Number(v.checksum_ok) === 1 && v.smoke_message && Number(v.smoke_ok) !== 0) problems.push(v.smoke_message);
    const task = taskBadge(v.verify_task);
    const actions = el('div', { class: 'toolbar toolbar--compact' }, (readOnly ? [] : toolActions(v)).map((a) => {
      const b = el('button', { type: 'button', class: a === 'mark_removal' || a === 'block' ? 'btn btn--danger-outline btn--small' : 'btn btn--small', dataset: { action: a } },
        [t(`admin_tools.action.${a}`)]);
      b.addEventListener('click', () => act(tool, v, a));
      return b;
    }));
    const extra = [];
    if (v.remove_command) {
      extra.push(el('p', { class: 'notice notice--warn' }, [icon('tool_status.removal', 16), ' ', t('admin_tools.removal_hint')]),
        commandBlock(v.remove_command));
    }
    if (task && (v.verify_task.status === 'failed' || v.verify_task.status === 'succeeded')) {
      extra.push(el('p', { class: 'muted' }, [iconWithLabel(task.icon, 16, `${t('admin_tools.last_verify_request')}: ${t(task.key)} (${time(v.verify_task.finished_at)})`)]));
    }
    return el('tr', { dataset: { version: v.version } }, [
      el('th', { scope: 'row' }, [el('span', { class: 'mono', text: v.version }), el('div', { class: 'muted', text: v.os })]),
      el('td', {}, [badges]),
      el('td', { text: check }),
      el('td', {}, [smoke]),
      el('td', {}, problems.length ? [el('ul', { class: 'plain-list' }, problems.map((p) => el('li', { text: p })))] : ['—']),
      el('td', {}, readOnly ? [el('span', { class: 'muted', text: t('admin_tools.read_only') })] : [actions, ...extra]),
    ]);
  }

  function renderTools(tools) {
    const box = $('[data-tools]');
    clear(box);
    for (const tl of tools) {
      const sel = selectionLine(tl.tool, tl.selection);
      const unpin = el('button', { type: 'button', class: 'btn btn--small', hidden: tl.selection.reason !== 'pinned' && tl.selection.reason !== 'pinned_unusable' },
        [iconWithLabel('tool_status.pinned', 16, t('admin_tools.action.unpin'))]);
      unpin.addEventListener('click', () => act(tl.tool, null, 'unpin'));
      const okSel = ['pinned', 'latest', 'fallback'].includes(tl.selection.reason);
      box.appendChild(el('section', { class: 'card', dataset: { tool: tl.tool }, 'aria-labelledby': `tool-${tl.tool}` }, [
        el('div', { class: 'card__header' }, [el('h2', { id: `tool-${tl.tool}`, text: TOOL_TITLES[tl.tool] ?? tl.tool }), tl.read_only ? null : unpin].filter(Boolean)),
        tl.read_only ? el('p', { class: 'muted', dataset: { readOnly: '' }, text: t('admin_tools.vcs_note') }) : null,
        el('p', { class: `notice notice--${okSel ? 'info' : 'warn'}`, dataset: { selection: tl.selection.reason } },
          [icon(okSel ? 'system.ok' : 'system.warn', 16), ' ', t(sel.key, sel.params)]),
        tl.versions.length === 0
          ? el('p', { class: 'muted', text: t('admin_tools.no_versions', { tool: tl.tool }) })
          : el('table', { class: 'table' }, [
            el('caption', { class: 'sr-only', text: t('admin_tools.versions_caption', { tool: tl.tool }) }),
            el('thead', {}, [el('tr', {}, ['col_version', 'col_state', 'col_checked', 'col_smoke', 'col_problems', 'col_actions']
              .map((k) => el('th', { scope: 'col', text: t(`admin_tools.${k}`) })))]),
            el('tbody', {}, tl.versions.map((v) => versionRow(tl.tool, v, tl.read_only === true))),
          ]),
      ].filter(Boolean)));
    }
  }

  function commandBlock(command) {
    const copy = el('button', { type: 'button', class: 'btn btn--small' }, [iconWithLabel('action.copy', 16, t('admin.copy'))]);
    copy.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(command);
        toast(t('admin.copied'), { type: 'success' });
      } catch {
        toast(t('admin.copy_failed'), { type: 'warn' });
      }
    });
    return el('div', { class: 'command-row' }, [el('code', { class: 'command', text: command }), copy]);
  }

  function renderStaging(items) {
    const box = $('[data-staging-list]');
    clear(box);
    if (!items.length) {
      box.appendChild(el('p', { class: 'muted', text: t('admin_tools.staging_empty') }));
      return;
    }
    for (const s of items) {
      const v = stagingView(s);
      const dl = el('dl', { class: 'detail-list' });
      const add = (label, value) => dl.append(el('dt', { text: label }), el('dd', {}, [value]));
      add(t('admin_tools.staging_state'), el('span', { class: `badge badge--tone-${v.tone}` }, [t(`admin_tools.staging_status.${s.status}`)]));
      add(t('admin_tools.staging_tool'), s.tool);
      add(t('admin_tools.staging_file'), `${s.file_name} (${formatBytes(s.total_size)})`);
      if (s.sha256) add('SHA-256', el('code', { class: 'mono break', text: s.sha256 }));
      add(t('admin_tools.staging_expected'), s.expected_sha256 ? el('code', { class: 'mono break', text: s.expected_sha256 }) : t('admin_tools.staging_expected_none'));
      if (v.hash !== 'none') {
        add(t('admin_tools.staging_hash'), iconWithLabel(v.hash === 'match' ? 'system.ok' : 'system.error', 16, t(`admin_tools.staging_hash_${v.hash}`)));
      }
      if (s.result) {
        add(t('admin_tools.staging_result'), iconWithLabel(s.result.ok ? 'system.ok' : 'system.error', 16,
          `${s.result.ok ? t('admin_tools.install_ok', { version: s.result.version ?? '' }) : t('admin_tools.install_fail')}${s.result.message ? ` — ${s.result.message}` : ''}`));
      }
      add(t('admin_tools.staging_created'), time(s.created_at));
      const children = [dl];
      if (v.showCommand) {
        children.push(el('p', {}, [iconWithLabel('ui.command', 16, t('admin_tools.install_hint'))]), commandBlock(s.install_command));
      } else if (v.hash === 'mismatch') {
        children.push(el('p', { class: 'notice notice--error' }, [icon('system.error', 16), ' ', t('admin_tools.install_blocked_hash')]));
      }
      const actions = el('div', { class: 'toolbar' });
      if (['receiving', 'staged', 'install_failed'].includes(s.status)) {
        const setHash = el('button', { type: 'button', class: 'btn btn--small' }, [t('admin_tools.staging_set_hash')]);
        setHash.addEventListener('click', () => editHash(s));
        actions.appendChild(setHash);
      }
      if (s.status !== 'installing') {
        const discard = el('button', { type: 'button', class: 'btn btn--danger-outline btn--small' }, [iconWithLabel('action.delete', 16, t('admin_tools.staging_discard'))]);
        discard.addEventListener('click', () => discardStaging(s));
        actions.appendChild(discard);
      }
      children.push(actions);
      box.appendChild(el('article', { class: 'staging-item', dataset: { staging: s.id, status: s.status } }, children));
    }
  }

  async function editHash(s) {
    const r = await formDialog({
      title: t('admin_tools.staging_set_hash'), message: t('admin_tools.staging_expected_help'), submitLabel: t('component.confirm'),
      build: (id) => {
        const input = el('input', { class: 'field__input mono', id: `${id}-sha`, maxlength: 64, value: s.expected_sha256 ?? '', autocomplete: 'off', spellcheck: 'false' });
        return {
          content: [el('div', { class: 'field' }, [el('label', { class: 'field__label', for: `${id}-sha`, text: t('admin_tools.staging_expected') }), input])],
          focus: input,
          onSubmit: async () => api.patch(`/api/v1/admin/tool-staging/${s.id}`, { expected_sha256: input.value.trim() }),
        };
      },
    });
    if (r) await load();
  }

  async function discardStaging(s) {
    const r = await confirmWithReason({ title: t('admin_tools.staging_discard'), message: t('admin_tools.confirm.discard', { file: s.file_name }), submitLabel: t('admin_tools.staging_discard') });
    if (!r) return;
    try {
      await api.delete(`/api/v1/admin/tool-staging/${s.id}`);
      toast(t('admin_tools.done.discard'), { type: 'success' });
      await load();
    } catch (e) {
      toast(errorText(e), { type: 'error' });
    }
  }

  async function upload(ev) {
    ev.preventDefault();
    const form = ev.currentTarget;
    const err = $('[data-staging-error]');
    err.hidden = true;
    const file = form.elements.file.files?.[0];
    if (!file) {
      setText(err, t('admin_tools.staging_need_file'));
      err.hidden = false;
      return;
    }
    const expected = form.elements.expected_sha256.value.trim().toLowerCase();
    const progress = $('[data-staging-progress]');
    const text = $('[data-staging-progress-text]');
    const btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    try {
      const s = await api.post('/api/v1/admin/tool-staging', {
        tool: form.elements.tool.value, file_name: file.name, total_size: file.size, ...(expected ? { expected_sha256: expected } : {}),
      });
      progress.hidden = false;
      const ranges = chunkRanges(file.size, s.chunk_size);
      for (const { index, start, end } of ranges) {
        const buf = await file.slice(start, end).arrayBuffer();
        await api.putRaw(`/api/v1/admin/tool-staging/${s.id}/chunks/${index}`, buf, { headers: { 'X-Chunk-Sha256': await sha256Hex(buf) } });
        progress.value = Math.round(((index + 1) / ranges.length) * 100);
        setText(text, t('admin_tools.staging_uploading', { done: index + 1, total: ranges.length }));
      }
      setText(text, t('admin_tools.staging_hashing'));
      const done = await api.post(`/api/v1/admin/tool-staging/${s.id}/complete`);
      setText(text, '');
      toast(t(done.hash_match === false ? 'admin_tools.staging_done_mismatch' : 'admin_tools.staging_done'), { type: done.hash_match === false ? 'warn' : 'success' });
      form.reset();
      await load();
    } catch (e) {
      setText(err, errorText(e));
      err.hidden = false;
      setText(text, '');
    } finally {
      btn.disabled = false;
      progress.hidden = true;
      progress.value = 0;
    }
  }

  let pending = false;
  async function load() {
    try {
      const [data, staging] = await Promise.all([api.get('/api/v1/admin/tools'), api.get('/api/v1/admin/tool-staging')]);
      $('[data-loading]').hidden = true;
      $('[data-error]').hidden = true;
      renderNode(data.node);
      renderTools(data.tools);
      renderStaging(staging.items);
      // 재검증 대기·진행 중이거나 설치 중이면 계속 갱신
      pending = data.tools.some((tl) => tl.versions.some((v) => v.verify_task && ['queued', 'running'].includes(v.verify_task.status)))
        || staging.items.some((s) => s.status === 'installing');
      return true;
    } catch (e) {
      const box = $('[data-error]');
      setText(box, errorText(e));
      box.hidden = false;
      throw e;
    }
  }

  $('[data-staging-form]').addEventListener('submit', upload);
  load().catch(() => {});
  createPoller(async () => (pending && !busy ? load() : true), { interval: POLL_MS }).start();
}
