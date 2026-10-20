// 감사 로그(P30, admin만, 읽기 전용): 조건(기간·행위자·작업·대상·검색)은 주소 질의 문자열과 같고(링크로 공유, 뒤로/앞으로),
// 조회 단추·Enter로만 적용한다. 목록·상세(대화상자)·무결성 검사(조각을 이어 부름, 진행률)·CSV 내보내기(P23 단추 모듈). 값은 textContent로만.
// X17: 목록 "설명" 열과 상세의 요약·바뀐 항목 표·기술 정보는 서버(AuditDescriber)가 만든 글자를 그대로 쓴다.
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { el, clear } from '../core/dom.js';
import { formatDateTime, timezoneFromDocument } from '../core/time.js';
import { iconWithLabel } from '../ui/icons.js';
import { errorText } from '../ui/form_dialog.js';
import { contentDialog } from '../ui/dialog.js';
import { renderPagination } from '../ui/pagination.js';
import { mountExport } from '../ui/export_buttons.js';
import { toast } from '../ui/toast.js';
import { fromQuery, toQuery, apiParams, progress, shortDateTime } from '../lib/audit_core.js';

const root = document.querySelector('[data-audit]');
if (root) init(root);

function init(section) {
  const api = createApi();
  const tz = timezoneFromDocument();
  const $ = (sel) => section.querySelector(sel);
  const form = $('[data-audit-filters]');
  const fields = { days: $('[data-period]'), from: $('[data-from]'), to: $('[data-to]'), actor: $('[data-actor]'), action: $('[data-action]'), target: $('[data-target]'), q: $('[data-q]') };
  const time = (iso) => (iso ? formatDateTime(iso, tz, { seconds: true }) : '—');
  const base = document.querySelector('meta[name="app-base"]')?.getAttribute('content') ?? '';
  let applied = fromQuery(location.search);
  let total = 0;
  let seq = 0;
  const exporter = mountExport($('[data-export-box]'), { url: '/api/v1/admin/audit-logs/export', getBody: () => apiParams(applied, tz) ?? {} });

  function customShown() {
    const custom = fields.days.value === 'custom';
    for (const n of section.querySelectorAll('[data-custom]')) n.hidden = !custom;
  }

  function fillForm(s) {
    fields.days.value = s.from || s.to ? 'custom' : s.days;
    fields.from.value = s.from;
    fields.to.value = s.to;
    fields.actor.value = s.actor;
    fields.action.value = [...fields.action.options].some((o) => o.value === s.action) ? s.action : '';
    fields.target.value = s.target;
    fields.q.value = s.q;
    customShown();
  }

  function readForm() {
    const custom = fields.days.value === 'custom';
    return {
      days: custom ? '' : fields.days.value, from: custom ? fields.from.value : '', to: custom ? fields.to.value : '',
      actor: fields.actor.value.trim(), action: fields.action.value, target: fields.target.value.trim(), q: fields.q.value.trim(), page: 1,
    };
  }

  function showError(msg) {
    $('[data-error]').textContent = msg;
    $('[data-error]').hidden = msg === '';
  }

  function cell(text, cls) {
    return el('td', { class: cls, text: text ?? '—' });
  }

  // 결과(X19): 모양이 다른 아이콘 + 글자 — 성공 체크(파랑), 실패 엑스(빨강), 요청됨·결과 없음 시계(회색). 아이콘은 장식(aria-hidden), 글자가 접근 이름
  const OUTCOME_ICON = { success: 'system.ok', failure: 'system.error', requested: 'analysis_status.queued' };
  function outcomeNode(r) {
    const kind = Object.hasOwn(OUTCOME_ICON, r.outcome) ? r.outcome : 'requested';
    const node = iconWithLabel(OUTCOME_ICON[kind], 16, r.outcome_label ?? '—');
    node.classList.add('audit__result', `audit__result--${kind}`);
    node.dataset.result = kind;
    return node;
  }

  function unknownBadge() {
    return el('span', { class: 'tag audit__nodesc', title: t('audit.unknown_hint'), dataset: { unknown: '' } }, [t('audit.unknown_badge')]);
  }

  // 표 한 행(X19 순서): 규칙(작업 키) · 설명(문장만) · 행위자 · 대상 · 결과 · 시간(YY/MM/DD HH:MM) · 요청 ID · 상세
  function rowNode(r) {
    const btn = el('button', { type: 'button', class: 'btn btn--small', 'aria-label': t('audit.detail_aria', { id: r.id, summary: r.summary }), dataset: { detail: r.id } }, [t('audit.detail_button')]);
    btn.addEventListener('click', () => openDetail(r.id));
    // 규칙 열: 작업 키만(사용자 결정 2026-10-13 — 분류·작업 이름 줄 없음). 설명 문구가 없는 작업만 "설명 없음(원본만)" 배지를 함께
    const rule = el('td', { class: 'audit__rule', dataset: { rule: r.action } }, [
      ...(r.known ? [] : [unknownBadge()]),
      el('code', { class: 'audit__action', title: r.known ? `${r.action_group} — ${r.action_label}` : r.action, text: r.action }),
    ]);
    const summary = el('td', { class: 'audit__summary-cell' }, [el('span', { class: 'audit__summary', title: r.summary, text: r.summary, dataset: { summary: '' } })]);
    const raw = r.target_type ? `${r.target_type}${r.target_id ? `:${r.target_id}` : ''}` : '';
    return el('tr', { dataset: { id: r.id, outcome: r.outcome } }, [
      rule, summary,
      cell(r.actor_display ?? r.actor, 'audit__actor'),
      el('td', { class: 'audit__target', title: raw, text: r.target_label || '—' }),
      el('td', { class: 'audit__outcome' }, [outcomeNode(r)]),
      el('td', { class: 'audit__time' }, [el('time', { datetime: r.occurred_at, title: `${time(r.occurred_at)} (${r.occurred_at})`, text: shortDateTime(r.occurred_at, tz) })]),
      cell(r.request_id, 'audit__req'), el('td', {}, [btn]),
    ]);
  }
  async function load() {
    const params = apiParams(applied, tz);
    if (params === null) {
      showError(t('audit.date_invalid'));
      return;
    }
    const my = ++seq;
    $('[data-loading]').hidden = false;
    try {
      const res = await api.get('/api/v1/admin/audit-logs', { query: { ...params, page: applied.page, size: 50 } });
      if (my !== seq) return;
      showError('');
      total = res.page.total;
      const rows = $('[data-rows]');
      clear(rows);
      for (const r of res.data) rows.append(rowNode(r));
      $('[data-table]').hidden = res.data.length === 0;
      $('[data-empty]').hidden = res.data.length !== 0;
      $('[data-audit-count]').textContent = t('audit.count', { n: total.toLocaleString() });
      renderPagination($('[data-pager]'), { page: res.page.page, pages: res.page.pages, onChange: (p) => { applied = { ...applied, page: p }; push(); load(); } });
      exporter.update({ total });
    } catch (e) {
      if (my === seq) showError(errorText(e));
    } finally {
      if (my === seq) $('[data-loading]').hidden = true;
    }
  }

  function push() {
    history.pushState(null, '', `${location.pathname}${toQuery(applied)}`);
  }

  const dlList = (rows, cls = '') => el('dl', { class: `detail-list audit__detail ${cls}`.trim() },
    rows.flatMap(([k, v]) => [el('dt', { text: k }), el('dd', {}, [v instanceof Node ? v : document.createTextNode(v ?? '—')])]));
  const block = (key, children) => el('section', { class: 'audit__section', dataset: { section: key } }, [el('h3', { class: 'audit__detail-title', text: t(`audit.section.${key}`) }), ...children]);

  // 상세(X17): 요약 → 무엇이 바뀌었나 → 세부 내용 → 누가·언제·어디서 → 대상 → 결과 → 기술 정보(접힘, 원본 JSON·복사)
  function buildDetail(body, d) {
    body.append(el('p', { class: 'audit__detail-summary', dataset: { summary: '' }, text: d.summary }));
    if (!d.known) body.append(el('p', { class: 'audit__detail-unknown' }, [unknownBadge(), ' ', el('span', { class: 'muted', text: t('audit.unknown_hint') })]));
    const changed = (d.fields ?? []).filter((f) => f.changed);
    const context = (d.fields ?? []).filter((f) => !f.changed);
    if (changed.length) {
      body.append(block('changes', [el('div', { class: 'table-wrap' }, [el('table', { class: 'table audit__changes', dataset: { changes: '' } }, [
        el('thead', {}, [el('tr', {}, ['item', 'before', 'after'].map((c) => el('th', { scope: 'col', text: t(`audit.change.${c}`) })))]),
        el('tbody', {}, changed.map((f) => el('tr', {}, [el('th', { scope: 'row', text: f.label }), el('td', { text: f.before ?? '—' }), el('td', { text: f.after })]))),
      ])])]));
    }
    if (context.length) body.append(block('context', [dlList(context.map((f) => [f.label, f.after]), 'audit__context')]));
    const who = [[t('audit.field.actor'), d.actor_display ?? d.actor]];
    if (d.actor_name) who.push([t('audit.field.actor_name'), d.actor_name]);
    who.push([t('audit.field.time'), time(d.occurred_at)], [t('audit.field.time_utc'), d.occurred_at], [t('audit.field.source'), d.source_label], [t('audit.field.ip'), d.ip ?? '—']);
    body.append(block('who', [dlList(who)]));
    let target;
    if (!d.target_label) target = el('p', { class: 'muted', text: t('audit.target_none') });
    else if (d.target_href && !d.target_deleted) target = el('p', {}, [el('a', { href: `${base}${d.target_href}`, dataset: { targetLink: '' }, text: d.target_label })]);
    else target = el('p', {}, [el('span', { text: d.target_label }), ...(d.target_deleted ? [' ', el('span', { class: 'muted', text: t('audit.target_deleted') })] : [])]);
    body.append(block('target', [target]));
    const result = [el('p', { class: 'audit__result', dataset: { outcome: d.outcome } }, [outcomeNode(d)])];
    if (d.outcome === 'failure' && (d.outcome_reason || d.outcome_code)) {
      const reason = el('span', {}, [d.outcome_reason ?? d.outcome_code]);
      if (d.outcome_code && d.outcome_reason !== d.outcome_code) reason.append(' ', el('code', { text: d.outcome_code }));
      if (d.outcome_href) reason.append(' ', el('a', { href: `${base}${d.outcome_href}`, text: t('audit.reason_guide') }));
      result.push(dlList([[t('audit.field.reason'), reason]]));
    }
    body.append(block('result', result));
    const raw = JSON.stringify(d.details ?? {}, null, 2);
    const copy = el('button', { type: 'button', class: 'btn btn--small', dataset: { copyJson: '' } }, [iconWithLabel('action.copy', 16, t('audit.copy_json'))]);
    copy.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(raw);
        toast(t('audit.copied'), { type: 'success' });
      } catch {
        toast(t('audit.copy_failed'), { type: 'error', timeout: 10000 });
      }
    });
    const rawId = `audit-raw-${d.id}`;
    body.append(el('details', { class: 'audit__tech', dataset: { tech: '' } }, [
      el('summary', { text: t('audit.section.tech') }),
      dlList([[t('audit.field.action'), el('code', { text: d.action })],
        [t('audit.field.target_raw'), d.target_type ? el('code', { text: `${d.target_type}${d.target_id ? `:${d.target_id}` : ''}` }) : '—'],
        [t('audit.field.request_id'), d.request_id ?? '—'], [t('audit.field.row_id'), String(d.id)],
        [t('audit.field.prev_hash'), el('code', { class: 'audit__hash', text: String(d.prev_hash ?? '').slice(0, 8) })],
        [t('audit.field.hash'), el('code', { class: 'audit__hash', text: String(d.hash ?? '').slice(0, 8) })]]),
      el('div', { class: 'audit__raw-head' }, [el('span', { class: 'audit__raw-title', id: rawId, text: t('audit.field.details') }), copy]),
      el('pre', { class: 'audit__raw', tabindex: '0', 'aria-labelledby': rawId, dataset: { raw: '' }, text: raw }),
    ]));
  }

  async function openDetail(id) {
    await contentDialog({
      title: t('audit.detail_title', { id }),
      build: async (body) => {
        try {
          buildDetail(body, await api.get(`/api/v1/admin/audit-logs/${id}`));
        } catch (e) {
          body.append(el('p', { class: 'notice notice--error', role: 'alert', text: errorText(e) }));
        }
      },
    });
  }
  /** P31 DB 밖 기록 대조 결과 → 아이콘·문구 키 */
  function anchorView(a) {
    if (!a) return null;
    if (a.state === 'ok') return { icon: 'system.ok', key: 'audit.anchor_ok' };
    if (a.state === 'mismatch') return { icon: 'system.error', key: 'audit.anchor_mismatch' };
    return { icon: 'system.unknown', key: 'audit.anchor_unknown' };
  }

  async function integrity() {
    const box = $('[data-integrity-box]');
    const status = $('[data-integrity-status]');
    const bar = $('[data-integrity-progress]');
    const rangeText = $('[data-integrity-range]');
    const btn = $('[data-integrity]');
    box.hidden = false;
    btn.disabled = true;
    box.setAttribute('aria-busy', 'true');
    bar.value = 0;
    let after = 0;
    let checked = 0;
    let range = null;
    let lastAnchor = null;
    try {
      for (;;) {
        const r = await api.get('/api/v1/admin/audit-logs/integrity', { query: { after } });
        range = r.range ?? range;
        checked += r.checked;
        if (range) {
          const parts = [];
          if (range.purged_through > 0) {
            parts.push(range.purged_through_at ? t('audit.integrity_purged', { id: range.purged_through, time: time(range.purged_through_at) })
              : t('audit.integrity_purged_noat', { id: range.purged_through }));
          }
          if (range.first) parts.push(t('audit.integrity_range', { first: range.first.id, first_at: time(range.first.at), last: range.last.id, last_at: time(range.last.at) }));
          rangeText.textContent = parts.join(' ');
        }
        if (!r.ok) {
          bar.value = progress(r.last_id, range);
          clear(status).append(iconWithLabel('system.error', 16, t('audit.integrity_broken', { id: r.broken_at, time: time(r.broken_at_time), reason: t(`audit.reason.${r.reason}`) })));
          status.dataset.result = 'broken';
          return;
        }
        bar.value = progress(r.last_id, range);
        status.textContent = t('audit.integrity_running', { done: checked.toLocaleString(), total: (range?.rows ?? checked).toLocaleString() });
        if (r.anchor) lastAnchor = r.anchor;
        if (r.done) break;
        after = r.last_id;
      }
      bar.value = 100;
      clear(status).append(range?.rows === 0 ? iconWithLabel('ui.info', 16, t('audit.integrity_empty'))
        : iconWithLabel('system.ok', 16, t('audit.integrity_ok', { n: checked.toLocaleString() })));
      status.dataset.result = 'ok';
      // P31: DB 밖 기록과 대조한 결과 한 줄(불일치면 오류, 기록이 없으면 확인 불가 안내)
      const a = anchorView(lastAnchor);
      if (a) {
        status.append(el('br'), iconWithLabel(a.icon, 16, t(a.key, { time: lastAnchor.latest_at ? time(lastAnchor.latest_at) : '', id: lastAnchor.broken_id ?? '',
          reason: lastAnchor.reason ? t(`audit.anchor_reason.${lastAnchor.reason}`) : '' })));
        status.dataset.anchor = lastAnchor.state;
        if (lastAnchor.state === 'mismatch') status.dataset.result = 'broken';
      }
    } catch (e) {
      clear(status).append(iconWithLabel('system.error', 16, t('audit.integrity_failed', { error: errorText(e) })));
      status.dataset.result = 'error';
    } finally {
      btn.disabled = false;
      box.removeAttribute('aria-busy');
    }
  }

  fields.days.addEventListener('change', customShown);
  form.addEventListener('submit', (ev) => {
    ev.preventDefault();
    const next = readForm();
    if (apiParams(next, tz) === null) {
      showError(t('audit.date_invalid'));
      return;
    }
    applied = next;
    push();
    load();
  });
  $('[data-reset]').addEventListener('click', () => {
    applied = fromQuery('');
    fillForm(applied);
    push();
    load();
  });
  $('[data-integrity]').addEventListener('click', integrity);
  window.addEventListener('popstate', () => {
    applied = fromQuery(location.search);
    fillForm(applied);
    load();
  });
  fillForm(applied);
  load();
}
