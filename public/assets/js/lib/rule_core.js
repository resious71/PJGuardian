// 규칙 설명 화면의 문장(P22, DOM 없음 — tests/js/rules.test.mjs). 값은 API 그대로, 문구는 i18n.
import { t } from '../core/i18n.js';

/** "기준: ruff 0.16.10 · ESLint 10.11.0 · @typescript-eslint 8.71.0" (적재 전이면 "적재 안 됨") */
export function basisText(meta, onlyTool = null) {
  const parts = [];
  // X08: 탭이 있으면 그 탭의 도구만("기준: ESLint 10.11.0 · @typescript-eslint 8.71.0 · 마지막 적재 …")
  for (const tool of onlyTool ? [onlyTool] : ['ruff', 'eslint']) {
    const m = meta.tools?.[tool];
    if (!m?.version) {
      parts.push(t('rules.basis_missing', { tool: t(`rules.tool.${tool}`) }));
      continue;
    }
    parts.push(`${t(`rules.tool.${tool}`)} ${m.version}`);
    for (const [name, v] of Object.entries(m.components ?? {})) parts.push(`${name.replace('/eslint-plugin', '')} ${v}`);
  }
  const loaded = (onlyTool ? [meta.tools?.[onlyTool]?.loaded_at] : [meta.tools?.ruff?.loaded_at, meta.tools?.eslint?.loaded_at]).filter(Boolean).sort().pop();
  if (loaded) parts.push(t('rules.loaded_at_short', { time: loaded.replace('T', ' ').replace('Z', ' UTC') }));
  return t('rules.basis', { list: parts.join(' · ') });
}

/** 탭 머리글의 총 규칙 수(활성 버전 기준, 검색 조건과 무관). 적재 전이면 '' */
export function tabCountText(meta, tool) {
  const m = meta.tools?.[tool];
  return m?.version ? ` (${Number(m.rules ?? 0).toLocaleString('ko-KR')})` : '';
}

/** 활성 도구 버전과 적재 버전이 다른 도구 목록 */
export function staleTools(meta, onlyTool = null) {
  return (onlyTool ? [onlyTool] : ['ruff', 'eslint']).filter((tool) => meta.tools?.[tool]?.stale);
}

export function fixableText(v) {
  return t(`rules.fixable.${v}`);
}

export function statusParts(item) {
  const parts = [];
  if (item.status === 'deprecated' || item.status === 'removed') parts.push(t(`rules.status.${item.status}`));
  if (item.status === 'preview') parts.push(t('rules.status.preview'));
  if (item.change === 'new') parts.push(t('rules.change.new'));
  if (item.change === 'changed') parts.push(t('rules.change.changed'));
  return parts;
}

/** 기본 심각도 근거 문장: 개별 규칙 지정 / 접두 Exxx / 그룹(분류) 기본 / 미정의 */
export function severityBasis(sev) {
  const level = sev?.level ?? 'none';
  return t(`rules.severity_level.${level}`, { group: sev?.group ?? '—' });
}

/** 변경 내역 한 줄: "9.39.5 → 10.11.0 · 기본 활성 변경: 꺼짐 → 켜짐" */
export function changeText(h) {
  const kind = t(`rules.kind.${h.kind}`);
  const val = (v) => {
    if (v === null || v === undefined) return '—';
    if (h.kind === 'default_enabled') return t(v === '1' ? 'rules.on' : 'rules.off');
    if (h.kind === 'fixable') return t(`rules.fixable.${v}`);
    if (h.kind === 'deprecated') return t(`rules.status.${v}`);
    if (h.kind === 'default_severity') return t(`icon.severity.${v}`);
    return v;
  };
  if (h.kind === 'added' || h.kind === 'removed') return kind;
  if (h.kind === 'renamed') return `${kind}: ${h.before} → ${h.after}`;
  if (h.kind === 'description') return kind; // 이전·이후 설명은 접어서 따로 보여 준다
  return `${kind}: ${val(h.before)} → ${val(h.after)}`;
}

/** 버전 내역이 없을 때의 문장 */
export function historyEmptyText(detail) {
  if ((detail.versions ?? []).length <= 1 && detail.baseline_version === detail.version) return t('rules.history_baseline_only', { version: detail.version });
  return t('rules.history_none', { version: detail.baseline_version ?? detail.version });
}

/** 활성 버전에 없는 규칙의 안내 */
export function missingText(detail) {
  const f = detail.fate;
  if (f?.kind === 'renamed') return t('rules.missing_renamed', { active: detail.active_version ?? '—', last: detail.version, to: f.to, version: f.version });
  if (f?.kind === 'removed') return t('rules.missing_removed', { active: detail.active_version ?? '—', last: detail.version, version: f.version });
  return t('rules.missing_unknown', { active: detail.active_version ?? '—', last: detail.version });
}

/**
 * 상세 "분석 적용"의 admin 버튼(P22B): 켜져 있으면 끄기(언제나 가능), 꺼져 있으면 켜기(켤 수 없으면 비활성 + 이유),
 * 관리자 변경 상태면 "기본값으로 되돌리기"도.
 * @returns {{kind: 'enable'|'disable'|'reset', disabled: boolean, why: string|null}[]}
 */
export function overrideActions(a) {
  if (!a) return [];
  const out = [a.enabled
    ? { kind: 'disable', disabled: false, why: null }
    : { kind: 'enable', disabled: !a.enableable, why: a.enableable ? null : t(`rules.block.${a.block}`) }];
  if (a.overridden) out.push({ kind: 'reset', disabled: false, why: null });
  return out;
}

/** 켜짐/꺼짐 글자 */
export function onOffText(v) {
  return t(v ? 'admin_rule_settings.base_on' : 'admin_rule_settings.base_off');
}

/** 확인 창 문장(변경 내용·적용 시점·영향·서버 전체 열린 결함 수) */
export function overrideDialogLines(kind, a, openDefects) {
  const to = kind === 'reset' ? a.base_enabled : kind === 'enable';
  return [
    t('rules.override_change', { from: onOffText(a.enabled), to: onOffText(to) }),
    t('rules.override_next'),
    t('rules.override_effect'),
    t('rules.override_open_defects', { n: Number(openDefects ?? 0).toLocaleString('ko-KR') }),
  ];
}

/** 관리자 "규칙 설정" 요약 줄(P22B): 도구별 변경 수·마지막 변경·실효 설정 해시, ruff 업로드 설정 프로젝트 수 */
export function overrideSummaryLines(summary, formatTime) {
  const lines = [];
  for (const tool of ['ruff', 'eslint']) {
    const s = summary?.[tool];
    if (!s) continue;
    lines.push([
      t('admin_rule_settings.summary_tool', { tool: t(`rules.tool.${tool}`), count: s.count, on: s.enabled, off: s.disabled }),
      s.last_changed_at ? t('admin_rule_settings.summary_last', { time: formatTime(s.last_changed_at) }) : t('admin_rule_settings.summary_never'),
      t('admin_rule_settings.summary_hash', { hash: s.config_hash ?? '—' }),
    ].join(' · '));
  }
  if (summary?.ruff) lines.push(t('admin_rule_settings.summary_upload', { n: summary.ruff.upload_config_projects ?? 0 }));
  return lines;
}

/** 전체 되돌리기 확인값: 도구 이름(대소문자 무시, 서버와 같은 규칙) */
export function resetAllMatches(input, tool) {
  return typeof input === 'string' && input.trim() !== '' && input.trim().toLowerCase() === String(tool).toLowerCase();
}

// ---- P22C 일괄 변경 ----
const SKIP_ORDER = ['removed', 'deprecated', 'preview', 'requires_type_info', 'requires_options', 'special_key', 'missing'];
const nf = (n) => Number(n ?? 0).toLocaleString('ko-KR');

/** 일괄 확인 창 문장: 대상·바뀔 수, 그대로, 건너뜀(이유별), 예시, 다음 분석부터, 서버 전체 열린 결함(0이면 뺌) */
export function bulkDialogLines(p) {
  const lines = [t('rules.bulk_target', { total: nf(p.total), changes: nf(p.changes), on: nf(p.turn_on), off: nf(p.turn_off) })];
  if (p.unchanged) lines.push(t('rules.bulk_unchanged', { n: nf(p.unchanged) }));
  const skips = SKIP_ORDER.filter((k) => p.skipped?.[k]).map((k) => t(`rules.bulk_skip.${k}`, { n: nf(p.skipped[k]) }));
  if (skips.length) lines.push(t('rules.bulk_skipped', { n: nf(p.skipped_total), list: skips.join(', ') }));
  if (p.examples?.length) {
    const list = p.examples.map((x) => `${x.rule}(${onOffText(x.from)} → ${onOffText(x.to)})`).join(', ');
    lines.push(p.changes > p.examples.length ? t('rules.bulk_examples_more', { list, n: nf(p.changes - p.examples.length) }) : t('rules.bulk_examples', { list }));
  }
  lines.push(p.changes ? t('rules.bulk_next') : t('rules.bulk_nothing'));
  if (p.open_defects) lines.push(t('rules.override_open_defects', { n: nf(p.open_defects) }));
  return lines;
}

/** 도구 이름을 입력해야 하는가: 검색 결과 전체가 대상이거나 바뀔 규칙이 20개를 넘을 때 */
export function needsTypedConfirm(mode, changes) {
  return mode === 'filter' || changes > 20;
}

/** 적용 결과 문장 */
export function bulkDoneText(r) {
  return t('rules.bulk_done', { changes: nf(r.changes), action: t(`rules.bulk_done_action.${r.action}`), skipped: nf(r.skipped_total) });
}

/** 일괄 막대의 선택 문장: 쪽 안 선택 N개 / 검색 결과 전체 M개 */
export function selectionText(count, allResults, total) {
  return allResults ? t('rules.bulk_selected_all', { n: nf(total) }) : t('rules.bulk_selected', { n: nf(count) });
}

/** "검색 결과 전체 M개 선택" 링크를 보여 줄까: 이 쪽을 모두 골랐고 결과가 이 쪽보다 많을 때 */
export function offerAllResults(selectedCount, pageCount, total, allResults) {
  return !allResults && pageCount > 0 && selectedCount === pageCount && total > pageCount;
}
