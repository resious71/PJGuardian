// 규칙 상세(P22). API /api/v1/rules/{tool}/{rule} → 설명(안전한 마크다운 부분집합, lib/markdown.js), 기본 심각도와 근거(P21S),
// 기본 활성·자동 수정·폐기·공식 문서 링크(도구가 준 https 주소만), 버전별 변경 내역, 볼 수 있는 프로젝트의 열린 결함 수.
// 활성 버전에 없는 규칙(예전 결함)은 안내와 마지막으로 알려진 설명. 값은 textContent로만.
// P22B: "분석 적용" 구역(적용 중/꺼짐, 기본값, 관리자 변경, 적용 범위). admin에게만 켜기·끄기·기본값으로 되돌리기(확인 창: 사유·서버 전체 열린 결함 수,
// 409면 최신 상태로 다시 그림). 켤 수 없는 규칙은 버튼 비활성 + 이유 글자. 변경자·사유는 서버가 admin에게만 준다.
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { el, clear, meta as metaTag } from '../core/dom.js';
import { icon } from '../ui/icons.js';
import { badge } from '../ui/badge.js';
import { errorText } from '../ui/form_dialog.js';
import { confirmRuleOverride } from '../ui/rule_override_dialog.js';
import { toast } from '../ui/toast.js';
import { formatDateTime, timezoneFromDocument } from '../core/time.js';
import { renderMarkdown, safeHref } from '../lib/markdown.js';
import { fixableText, severityBasis, changeText, historyEmptyText, missingText, overrideActions, onOffText } from '../lib/rule_core.js';
import { languageOf } from '../lib/tool_language.js';
import { backToListSearch } from '../lib/rule_search.js';

const root = document.querySelector('[data-rule-detail]');
if (root) init(root);

function init(article) {
  const api = createApi();
  const base = metaTag('app-base') ?? '';
  const tool = article.dataset.tool;
  const rule = article.dataset.rule;
  const body = article.querySelector('[data-body]');
  // 목록으로 돌아가기(X08): 이 규칙의 언어 탭 + 그 탭의 마지막 목록 조건(브라우저 탭의 sessionStorage, 없으면 탭 기본 목록)
  const language = languageOf(tool);
  if (language) {
    let saved = null;
    try { saved = sessionStorage.getItem(`pjg.rules.search.${language}`); } catch { /* 저장소 없음 */ }
    document.querySelector('[data-back-to-list]').search = backToListSearch(language, saved);
  }

  const isAdmin = article.dataset.admin === '1';
  const encoded = rule.split('/').map(encodeURIComponent).join('/');
  const path = tool === 'special' ? '/api/v1/rules/syntax-error' : `/api/v1/rules/${tool}/${encoded}`;
  const reload = () => api.get(path).then((d) => render(d));
  api.get(path).then((d) => render(d)).catch((e) => {
    article.querySelector('[data-loading]').hidden = true;
    const box = article.querySelector('[data-error-box]');
    box.hidden = false;
    box.append(el('p', { class: 'notice notice--error', text: e.status === 404 ? t('rules.not_found') : errorText(e) }));
  });

  const dl = (rows) => el('dl', { class: 'detail-list' }, rows.flatMap(([k, v]) => [el('dt', { text: k }), el('dd', {}, [v])]));

  function defectsBlock(d) {
    const sec = el('section', { class: 'card', dataset: { defects: String(d.defects.total) } }, [el('h2', { text: t('rules.defects_title') })]);
    if (!d.defects.total) {
      sec.append(el('p', { class: 'muted', text: t('rules.defects_none') }));
      return sec;
    }
    sec.append(el('p', { text: t('rules.defects_total', { n: d.defects.total.toLocaleString('ko-KR') }) }));
    sec.append(el('ul', { class: 'rule-defects' }, d.defects.projects.map((p) => el('li', {}, [
      el('a', { href: `${base}${p.href}` }, [t('rules.defects_link', { project: p.name, n: p.count.toLocaleString('ko-KR') })]),
    ]))));
    return sec;
  }

  /** "분석 적용" 구역(P22B) */
  function analysisBlock(d) {
    const a = d.analysis;
    const sec = el('section', { class: 'card rule-analysis', dataset: { analysis: a.enabled ? 'on' : 'off', overridden: a.overridden ? '1' : '0' } },
      [el('h2', { text: t('rules.analysis_title') })]);
    const value = [el('strong', { text: t(a.enabled ? 'rules.analysis_on' : 'rules.analysis_off') })];
    if (a.overridden) value.push(' ', el('span', { class: 'tag rule-overridden', title: t('rules.analysis_tip_base', { value: onOffText(a.base_enabled) }) }, [icon('ui.changed', 16), t('rules.overridden_badge')]));
    const rows = [
      [t('rules.analysis_value'), el('span', {}, value)],
      [t('rules.analysis_base'), t(a.base_enabled ? 'rules.analysis_base_on' : 'rules.analysis_base_off')],
      [t('rules.analysis_overridden'), t(a.overridden ? 'rules.analysis_overridden_yes' : 'rules.analysis_overridden_no')],
      [t('rules.analysis_scope'), t(`rules.analysis_scope_${a.scope}`)],
    ];
    if (a.override) {
      const when = a.override.changed_at ? formatDateTime(a.override.changed_at, timezoneFromDocument()) : '—';
      rows.push([t('rules.analysis_last_change'), t('rules.analysis_last_change_value', { time: when, who: a.override.changed_by_name ?? '—' })]);
      rows.push([t('rules.analysis_reason'), a.override.reason || '—']);
    }
    sec.append(dl(rows));
    if (!a.enableable && !a.enabled) {
      sec.append(el('p', { class: 'muted rule-analysis-block', dataset: { block: a.block } }, [icon('system.warn', 16), t('rules.not_enableable', { why: t(`rules.block.${a.block}`) })]));
    }
    if (isAdmin) {
      const bar = el('div', { class: 'rule-analysis-actions' });
      for (const act of overrideActions(a)) {
        const label = t(act.kind === 'enable' ? 'rules.enable' : act.kind === 'disable' ? 'rules.disable' : 'rules.reset_default');
        const btn = el('button', { type: 'button', class: act.kind === 'reset' ? 'btn' : 'btn btn--primary', dataset: { override: act.kind }, disabled: act.disabled,
          'aria-describedby': act.disabled ? 'rule-block-why' : null }, [label]);
        btn.addEventListener('click', () => change(act.kind, d, btn));
        bar.append(btn);
      }
      if (!a.enableable && !a.enabled) bar.append(el('span', { class: 'sr-only', id: 'rule-block-why', text: t(`rules.block.${a.block}`) }));
      sec.append(bar, el('p', { class: 'field__help', text: t('rules.analysis_next') }));
    }
    return sec;
  }

  /** admin 켜기·끄기·되돌리기: 확인 창(목록 스위치와 같은 ui/rule_override_dialog.js) → 요청 하나 → 다시 그림. 409면 최신 상태로 다시 그림 */
  async function change(kind, d, btn) {
    btn.disabled = true;
    try {
      const result = await confirmRuleOverride({ api, tool, rule, key: d.key, kind, a: d.analysis, openDefects: d.defects.total });
      if (result === null) return;
      await reload();
      toast(t(result === 'conflict' ? 'rules.override_conflict' : 'rules.override_done'), { type: result === 'conflict' ? 'warn' : 'success' });
    } finally {
      if (btn.isConnected) btn.disabled = false;
    }
  }

  /** 쉬운 설명(P32B, 명세 11): 직접 쓴 설명 + 나쁜 예·좋은 예(코드는 텍스트로) + 고치는 법. 실제 도구로 확인한 버전을 함께 */
  function guideBlock(g) {
    const code = (label, text, kind) => el('figure', { class: `rule-guide__example rule-guide__example--${kind}` }, [
      el('figcaption', {}, [icon(kind === 'bad' ? 'system.error' : 'system.ok', 16), ' ', label]),
      el('pre', { class: 'rule-guide__code' }, [el('code', { text })]),
    ]);
    return el('section', { class: 'card rule-guide', dataset: { ruleGuide: '' } }, [
      el('h2', { text: t('rules.guide.title') }),
      el('div', { class: 'md' }, renderMarkdown(g.summary)),
      el('div', { class: 'rule-guide__examples' }, [code(t('rules.guide.bad'), g.bad, 'bad'), code(t('rules.guide.good'), g.good, 'good')]),
      el('h3', { text: t('rules.guide.fix') }),
      el('div', { class: 'md' }, renderMarkdown(g.fix)),
      el('p', { class: 'field__help', text: t('rules.guide.checked', { tool: t(`rules.tool.${g.checked.tool}`), version: g.checked.version }) }),
    ]);
  }

  function render(d) {
    article.querySelector('[data-loading]').hidden = true;
    body.hidden = false;
    clear(body);
    if (d.special) {
      body.append(
        el('div', { class: 'page-header' }, [el('h1', {}, [el('code', { text: d.key })])]),
        el('section', { class: 'card' }, [el('p', { text: t(`rules.special.${d.key}`) }), el('p', { class: 'muted', text: t('rules.special_note') })]),
        defectsBlock(d),
      );
      return;
    }
    const title = el('div', { class: 'page-header' }, [
      el('h1', { class: 'rule-title' }, [el('code', { text: d.key })]),
      el('p', { class: 'muted', text: [t(`rules.tool.${d.tool}`), d.version, d.category ?? null, d.origin || null].filter(Boolean).join(' · ') }),
    ]);
    body.append(title);
    if (d.missing) {
      body.append(el('p', { class: 'notice notice--warn', dataset: { missing: d.fate?.kind ?? 'unknown' } }, [icon('system.warn', 16), ' ', missingText(d),
        ...(d.fate?.href ? [' ', el('a', { href: `${base}${d.fate.href}`, text: t('rules.missing_go', { rule: d.fate.to }) })] : [])]));
    }
    // 요약 + 속성
    const sev = el('span', { class: 'rule-severity' }, [badge(`severity.${d.severity.value}`), ' ', el('span', { class: 'muted', text: severityBasis(d.severity) })]);
    const deprecation = d.deprecation
      ? el('span', {}, [d.deprecation.since ? t('rules.deprecated_since', { since: d.deprecation.since }) : t('rules.status.deprecated'),
        ...(d.deprecation.replaced_by.length ? [' · ', t('rules.replaced_by'), ' ', ...d.deprecation.replaced_by.flatMap((r, i) => [i ? ', ' : '', el('a', { href: `${base}${r.href}` }, [el('code', { text: r.rule })])])] : []),
        ...(d.deprecation.message ? [el('br'), el('span', { class: 'muted', text: d.deprecation.message })] : [])])
      : t('rules.no');
    const docs = safeHref(d.docs_url)
      ? el('a', { href: d.docs_url, rel: 'noopener noreferrer' }, [d.docs_url])
      : el('span', { class: 'muted', text: t('rules.no_docs') });
    const source = d.source.kind === 'tool'
      ? t('rules.source_tool', { tool: t(`rules.tool.${d.tool}`), version: d.version })
      : el('span', {}, [t('rules.source_enrichment'), ' ', ...d.source.urls.filter(safeHref).flatMap((u, i) => [i ? ', ' : '', el('a', { href: u, rel: 'noopener noreferrer' }, [u])])]);
    body.append(el('section', { class: 'card rule-summary' }, [
      el('p', { class: 'rule-summary__text', text: d.summary || t('rules.no_description') }),
      dl([
        [t('rules.col.severity'), sev],
        ['', el('span', { class: 'field__help', text: t('rules.severity_note') })],
        ...(d.analysis ? [] : [[t('rules.col.default_enabled'), t(d.default_enabled ? 'rules.default_on' : 'rules.default_off', { tool: t(`rules.tool.${d.tool}`) })]]),
        [t('rules.col.fixable'), fixableText(d.fixable) + (d.has_suggestions ? ` · ${t('rules.has_suggestions')}` : '')],
        [t('rules.has_options'), d.has_options === null ? t('rules.unknown') : t(d.has_options ? 'rules.yes' : 'rules.no')],
        [t('rules.filter_deprecated'), deprecation],
        [t('rules.docs'), docs],
        [t('rules.source'), source],
      ]),
    ]));
    if (d.guide) body.append(guideBlock(d.guide));
    if (d.analysis) body.append(analysisBlock(d));
    // 상세 설명(마크다운)
    const desc = el('section', { class: 'card rule-description' }, [el('h2', { text: t('rules.description') })]);
    if (d.description) desc.append(el('div', { class: 'md' }, renderMarkdown(d.description, { headingBase: 3 })));
    else desc.append(el('p', { class: 'muted', text: t('rules.no_description_long') }));
    body.append(desc);
    // 버전별 변경 내역
    const hist = el('section', { class: 'card', dataset: { history: String(d.history.length) } }, [el('h2', { text: t('rules.history') })]);
    if (!d.history.length) {
      hist.append(el('p', { class: 'muted', text: historyEmptyText(d) }));
    } else {
      hist.append(el('table', { class: 'table rule-history' }, [
        el('thead', {}, [el('tr', {}, [el('th', { scope: 'col', text: t('rules.history_version') }), el('th', { scope: 'col', text: t('rules.history_change') })])]),
        el('tbody', {}, d.history.map((h) => el('tr', { dataset: { kind: h.kind } }, [
          el('td', { text: `${h.from} → ${h.to}` }),
          el('td', {}, h.kind === 'description' ? [changeText(h), el('details', { class: 'rule-diff' }, [
            el('summary', { text: t('rules.diff_show') }),
            el('div', { class: 'rule-diff__cols' }, [
              el('div', {}, [el('h3', { text: t('rules.diff_before', { version: h.from }) }), el('pre', { class: 'md-code', text: h.before ?? '' })]),
              el('div', {}, [el('h3', { text: t('rules.diff_after', { version: h.to }) }), el('pre', { class: 'md-code', text: h.after ?? '' })]),
            ]),
          ])] : [changeText(h)]),
        ]))),
      ]));
      if (d.baseline_version) hist.append(el('p', { class: 'muted', text: t('rules.history_baseline', { version: d.baseline_version }) }));
    }
    body.append(hist, defectsBlock(d));
    document.title = `${d.key} · ${t('rules.title')}`;
  }
}

