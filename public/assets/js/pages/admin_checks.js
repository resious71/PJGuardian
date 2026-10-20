// 관리자 "점검"(P20A, 읽기 전용): 디스크(전체 + 구분별 캐시), 정리 작업 상태와 "지금 정리 실행", 최근 7일 실패 분석,
// 환경(check_env.php와 같은 검사), 설정(비밀값은 설정 여부만). 값은 textContent로만 넣는다.
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { el, clear, setText } from '../core/dom.js';
import { formatDateTime, timezoneFromDocument } from '../core/time.js';
import { createPoller } from '../core/poll.js';
import { iconWithLabel } from '../ui/icons.js';
import { toast } from '../ui/toast.js';
import { confirmDialog } from '../ui/dialog.js';
import { errorText } from '../ui/form_dialog.js';
import { diskLevel, diskCategories, taskBadge } from '../lib/admin_core.js';
import { formatBytes } from '../lib/upload_core.js';
import { estimateCapacity } from '../lib/capacity.js';

const POLL_MS = 4000;
const root = document.querySelector('[data-admin-checks]');
if (root) init(root);
const capacity = document.querySelector('[data-capacity]');
if (capacity) initCapacity(capacity);

/** P32B 용량 추정 도우미: 입력이 바뀔 때마다 순수 함수로 다시 계산(서버 요청 없음) */
function initCapacity(sec) {
  const d = sec.dataset;
  const settings = { maxConcurrent: Number(d.maxConcurrent), snapshotKeep: Number(d.snapshotKeep), runsKept: Number(d.runsKept),
    auditDays: Number(d.auditDays), warnPercent: Number(d.warnPercent), backupSnapshots: d.backupSnapshots === '1' };
  const form = sec.querySelector('[data-capacity-form]');
  const val = (name) => Number(form.elements[name]?.value ?? 0);
  const update = () => {
    const r = estimateCapacity({ projects: val('projects'), defectsPerProject: val('defects_per_project'), uploadMb: val('upload_mb'), backupsKept: val('backups_kept') }, settings);
    for (const cell of sec.querySelectorAll('[data-capacity-out]')) cell.textContent = formatBytes(r[cell.dataset.capacityOut]);
  };
  form.addEventListener('input', update);
  update();
}

function init(section) {
  const api = createApi();
  const tz = timezoneFromDocument();
  const base = document.querySelector('meta[name="app-base"]')?.content ?? '';
  const $ = (sel) => section.querySelector(sel);
  const time = (iso) => (iso ? formatDateTime(iso, tz) : '—');
  let pending = false;
  const tone = { ok: 'system.ok', warn: 'system.warn', fail: 'system.error', info: 'ui.info', unknown: 'system.unknown' }; // P31: 정보·확인 불가

  // CSP(style-src)가 인라인 style 속성을 막으므로 너비·위치는 CSSOM(node.style)으로 정한다
  function styled(node, prop, value) {
    node.style[prop] = value;
    return node;
  }

  function taskLine(task) {
    const b = taskBadge(task);
    if (!b) return null;
    return el('p', { class: 'muted', dataset: { task: task.status } }, [iconWithLabel(b.icon, 16, `${t(b.key)} (${time(task.finished_at ?? task.started_at ?? task.created_at)})`)]);
  }

  function renderDisk(d) {
    const box = $('[data-disk]');
    clear(box);
    const lv = diskLevel(d.used_percent, d.warn_percent, d.block_percent);
    const pct = d.used_percent ?? 0;
    box.append(
      el('p', { class: `notice notice--${lv.tone === 'ok' ? 'info' : lv.tone}`, dataset: { disk: lv.tone } },
        [iconWithLabel(lv.icon, 16, t(lv.key, { used: d.used_percent ?? '?', warn: d.warn_percent, block: d.block_percent }))]),
      el('div', { class: `meter meter--${lv.tone}`, role: 'meter', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(pct),
        'aria-label': t('admin_checks.disk_meter') }, [
        styled(el('span', { class: 'meter__bar' }), 'width', `${Math.min(100, Math.max(0, pct))}%`),
        styled(el('span', { class: 'meter__mark meter__mark--warn', title: t('admin_checks.mark_warn', { p: d.warn_percent }) }), 'left', `${d.warn_percent}%`),
        styled(el('span', { class: 'meter__mark meter__mark--block', title: t('admin_checks.mark_block', { p: d.block_percent }) }), 'left', `${d.block_percent}%`),
      ]),
      el('p', { text: d.free !== null ? t('admin_checks.disk_free', { free: formatBytes(d.free), total: formatBytes(d.total) }) : t('admin_checks.disk_unknown') }),
    );
    if (d.usage) {
      const rows = diskCategories(d.usage.categories);
      box.append(
        el('p', { class: 'muted', text: t('admin_checks.disk_scanned', { at: time(d.usage.scanned_at), ms: d.usage.duration_ms }) }),
        el('table', { class: 'table' }, [
          el('caption', { class: 'sr-only', text: t('admin_checks.disk_categories') }),
          el('thead', {}, [el('tr', {}, ['col_category', 'col_size', 'col_files', 'col_share'].map((k) => el('th', { scope: 'col', text: t(`admin_checks.${k}`) })))]),
          el('tbody', {}, rows.map((r) => el('tr', {}, [
            el('th', { scope: 'row', text: t(`admin_checks.cat.${r.key}`) }),
            el('td', { text: formatBytes(r.bytes) }), el('td', { text: String(r.files) }),
            el('td', {}, [el('span', { class: 'meter meter--inline', 'aria-hidden': 'true' }, [styled(el('span', { class: 'meter__bar' }), 'width', `${r.percent}%`)]), ` ${r.percent}%`]),
          ]))),
        ]),
      );
    } else {
      box.append(el('p', { class: 'muted', text: t('admin_checks.disk_not_scanned') }));
    }
    const tl = taskLine(d.task);
    if (tl) box.append(tl);
  }

  function renderCleanup(c) {
    const dl = $('[data-cleanup]');
    clear(dl);
    const add = (label, value) => dl.append(el('dt', { text: label }), el('dd', {}, [value]));
    add(t('admin_checks.cleanup_last'), c.last ? t('admin_checks.cleanup_last_value', { at: time(c.last.at), source: t(`admin_checks.cleanup_source.${c.last.source}`) }) : t('admin_checks.cleanup_never'));
    add(t('admin_checks.cleanup_trash'), String(c.trash_items));
    add(t('admin_checks.cleanup_pending'), String(c.pending_items));
    if (c.preview) {
      const p = c.preview;
      add(t('admin_checks.cleanup_preview'), t('admin_checks.cleanup_preview_value', {
        at: time(c.preview_at), uploads: (p.expired_uploads ?? 0) + (p.orphan_dirs ?? 0), zips: p.failed_zips ?? 0,
        results: p.results_deleted ?? 0, work: p.orphan_work_dirs ?? 0, staging: p.staging_purged ?? 0,
      }));
    }
    if (c.last?.result) {
      const r = c.last.result;
      add(t('admin_checks.cleanup_last_result'), t('admin_checks.cleanup_result_value', {
        uploads: (r.expired_uploads ?? 0) + (r.orphan_dirs ?? 0), zips: r.failed_zips ?? 0, results: r.results_deleted ?? 0,
        logs: r.logs_deleted ?? 0, snapshots: r.snapshots_pruned ?? 0, staging: r.staging_purged ?? 0,
      }));
    }
    const tl = taskLine(c.task);
    if (tl) add(t('admin_checks.cleanup_request'), tl);
  }

  function renderFailures(f) {
    const box = $('[data-failures]');
    clear(box);
    if (!f.by_code.length) {
      box.append(el('p', {}, [iconWithLabel('system.ok', 16, t('admin_checks.failures_none', { days: f.days }))]));
      return;
    }
    box.append(el('ul', { class: 'plain-list' }, f.by_code.map((c) => el('li', { dataset: { code: c.code } }, [
      iconWithLabel(c.admin_href ? 'system.warn' : 'analysis_status.failed', 16, t('admin_checks.failure_count', { title: c.title, n: c.count })),
      ' ', el('code', { class: 'mono', text: c.code }),
      ...(c.admin_href ? [' — ', el('a', { href: `${base}/${c.admin_href}`, text: t('admin_checks.failure_admin_link') })] : []),
      el('div', { class: 'muted', text: c.action }),
    ]))));
    box.append(el('h3', { class: 'subhead', text: t('admin_checks.failures_recent') }),
      el('ul', { class: 'plain-list' }, f.recent.map((a) => el('li', {}, [
        el('a', { href: `${base}/analyses/${a.id}`, text: `#${a.id}` }), ' ',
        el('a', { href: `${base}/projects/${a.project_id}`, text: a.project_name }), ' · ',
        el('code', { class: 'mono', text: a.failure_code ?? '' }), ` · ${time(a.finished_at)}`,
      ]))));
  }

  function renderEnv(env) {
    const tb = $('[data-env]');
    clear(tb);
    const row = (label, status, value) => tb.appendChild(el('tr', { dataset: { status } }, [
      el('th', { scope: 'row', text: label }),
      // P21C: 상태 아이콘 색(정상 녹색·경고 주황·문제 빨강, 토큰) + 모양 + 글자
      el('td', { class: 'status-cell', dataset: { status } }, [iconWithLabel(tone[status] ?? 'system.warn', 16, t(`admin_checks.status.${status}`))]),
      el('td', { text: value }),
    ]));
    for (const it of env.items) {
      const label = t(`env.item.${it.id}`, it.params);
      const note = it.note ? ` — ${t(`env.note.${it.note}`, it.params)}` : '';
      row(label, it.status, `${it.value}${note}`);
    }
    row(t('admin_checks.time'), 'ok', `${env.time_utc} (${env.timezone}: ${time(env.time_utc)})`);
    row(t('admin_checks.https'), env.https ? 'ok' : 'warn', t(env.https ? 'admin_checks.https_on' : 'admin_checks.https_off'));
    const n = env.node;
    row(t('admin_tools.node_title'), n ? (n.ok ? 'ok' : 'fail') : 'warn',
      n ? (n.ok ? `${n.version} (${t('admin_tools.node_min', { min: n.min })})` : t(`admin_tools.node_reason.${n.reason ?? 'unknown'}`)) : t('admin_tools.node_unknown'));
  }

  function renderConfig(rows) {
    const tb = $('[data-config]');
    clear(tb);
    for (const r of rows) {
      let value;
      if (r.kind === 'secret' || r.kind === 'presence') value = t(r.value === 'set' ? 'admin_checks.config_set' : 'admin_checks.config_unset');
      else if (r.kind === 'absolute_path') value = t('admin_checks.config_absolute_path');
      else value = r.value === '' ? t('admin_checks.config_empty') : r.value;
      const def = r.kind === 'value' && r.changed ? t('admin_checks.config_changed', { value: r.default ?? '' }) : (r.changed ? t('admin_checks.config_changed_plain') : t('admin_checks.config_default'));
      tb.appendChild(el('tr', { dataset: { key: r.key, kind: r.kind } }, [
        el('th', { scope: 'row' }, [el('code', { class: 'mono', text: r.key })]),
        el('td', { text: value }),
        el('td', {}, r.kind === 'secret' ? ['—'] : [r.changed ? iconWithLabel('system.warn', 16, def) : def]),
      ]));
    }
  }

  async function load() {
    try {
      const d = await api.get('/api/v1/admin/checks');
      $('[data-loading]').hidden = true;
      $('[data-error]').hidden = true;
      renderDisk(d.disk);
      renderCleanup(d.cleanup);
      renderFailures(d.failures);
      renderEnv(d.environment);
      renderConfig(d.config);
      pending = [d.disk.task, d.cleanup.task].some((x) => x && ['queued', 'running'].includes(x.status));
      return true;
    } catch (e) {
      const box = $('[data-error]');
      setText(box, errorText(e));
      box.hidden = false;
      throw e;
    }
  }

  async function request(path, titleKey, messageKey) {
    const ok = await confirmDialog({ title: t(titleKey), message: t(messageKey), danger: path.includes('cleanup'), confirmLabel: t(titleKey) });
    if (!ok) return;
    try {
      const r = await api.post(path);
      toast(t(r.merged ? 'admin.task_merged' : 'admin.task_queued'), { type: 'success' });
      await load();
    } catch (e) {
      toast(errorText(e), { type: 'error' });
    }
  }

  $('[data-disk-scan]').addEventListener('click', () => request('/api/v1/admin/checks/disk-scan', 'admin_checks.disk_scan', 'admin_checks.confirm_disk_scan'));
  $('[data-cleanup-run]').addEventListener('click', () => request('/api/v1/admin/cleanup/run', 'admin_checks.cleanup_run', 'admin_checks.confirm_cleanup'));
  load().catch(() => {});
  createPoller(async () => (pending ? load() : true), { interval: POLL_MS }).start({ immediate: false });
}
