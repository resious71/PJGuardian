// 규칙 설명(P22): 안전한 마크다운 렌더링, 검색 상태(주소·적용 방식), 화면 문장
import test from 'node:test';
import assert from 'node:assert/strict';
import { renderMarkdown, inline, safeHref } from '../../public/assets/js/lib/markdown.js';
import { emptyState, stateFromSearch, searchFromState, apiQuery, isDirty, isFiltered, toggle, resolveLanguage, backToListSearch, bulkFilter } from '../../public/assets/js/lib/rule_search.js';
import { basisText, tabCountText, staleTools, statusParts, severityBasis, changeText, historyEmptyText, missingText, overrideActions, overrideDialogLines, overrideSummaryLines, resetAllMatches, bulkDialogLines, needsTypedConfirm, bulkDoneText, selectionText, offerAllResults } from '../../public/assets/js/lib/rule_core.js';
import { toolOf, languageOf, LANGUAGES } from '../../public/assets/js/lib/tool_language.js';
import { has } from '../../public/assets/js/core/i18n.js';
import { FakeDocument } from './fake-dom.mjs';

const doc = new FakeDocument();
const tags = (nodes) => nodes.map((n) => (typeof n === 'string' ? '#text' : n.tagName));
/** 노드 트리에서 태그 이름 모으기 */
const allTags = (nodes, out = []) => {
  for (const n of nodes) {
    if (typeof n === 'string' || n.nodeType === 3) continue;
    out.push(n.tagName);
    allTags(n.childNodes, out);
  }
  return out;
};

test('마크다운: 제목·문단·목록·코드 블록·인라인 코드·링크', () => {
  const md = '## What it does\nChecks for `x`\nmore text.\n\n## Example\n```python\nimport os  # <b>\n```\n\nUse instead:\n- one\n- two **bold**\n\n1. first\n2. second\n\nSee [docs](https://docs.astral.sh/ruff/).';
  const nodes = renderMarkdown(md, { headingBase: 3 }, doc);
  assert.deepEqual(tags(nodes), ['H3', 'P', 'H3', 'PRE', 'P', 'UL', 'OL', 'P']);
  assert.equal(nodes[1].textContent, 'Checks for x more text.', '이어지는 줄은 한 문단');
  assert.equal(nodes[3].textContent, 'import os  # <b>', '코드 블록은 글자 그대로(태그 해석 없음)');
  assert.equal(nodes[3].dataset.lang, 'python');
  assert.deepEqual(allTags([nodes[5]]), ['UL', 'LI', 'LI', 'STRONG']);
  const a = nodes[7].childNodes.find((n) => n.tagName === 'A');
  assert.deepEqual([a.getAttribute('href'), a.getAttribute('rel'), a.getAttribute('target')], ['https://docs.astral.sh/ruff/', 'noopener noreferrer', null]);
});

test('마크다운 안전: 원시 HTML·스크립트·javascript:/data: 링크는 실행·렌더되지 않는다', () => {
  const nodes = renderMarkdown('<script>alert(1)</script>\n<img src=x onerror=alert(2)>\n[x](javascript:alert(3)) [y](data:text/html,hi) [z](//evil) [w](/rel)', {}, doc);
  assert.deepEqual(allTags(nodes), ['P'], '태그는 문단 하나뿐(script·img 없음)');
  assert.equal(nodes[0].textContent, '<script>alert(1)</script> <img src=x onerror=alert(2)> [x](javascript:alert(3)) [y](data:text/html,hi) [z](//evil) [w](/rel)');
  for (const bad of ['javascript:alert(1)', 'JAVASCRIPT:x', 'data:text/html,x', 'vbscript:x', '//evil.com', '/rel', 'https://a b', 'https://x"y']) assert.equal(safeHref(bad), null, bad);
  assert.equal(safeHref('https://eslint.org/docs/latest/rules/no-undef'), 'https://eslint.org/docs/latest/rules/no-undef');
  assert.deepEqual(tags(inline('a `<b>` c', doc)), ['#text', 'CODE', '#text']);
});

test('검색 상태: 주소 ↔ 상태(잘못된 값 무시), API 쿼리, 변경 판정', () => {
  // X08: 도구 조건(tool)은 언어 탭(language)으로 바뀌었다. 예전 주소의 tool=eslint는 JavaScript 탭으로 읽고, 주소에는 늘 language=가 들어간다.
  const s = stateFromSearch('?q=unused&tool=eslint&severity=high,bogus,low&default_enabled=1&fixable=2&change=new&sort=severity&order=desc&page=3&tool2=x');
  // P22B: "기본 활성"(default_enabled) 조건은 "분석 적용"(analysis)으로 바뀌었다 — 예전 주소의 default_enabled=1은 analysis=1로 읽는다
  assert.deepEqual(s, { ...emptyState('javascript'), q: 'unused', severity: ['high', 'low'], analysis: '1', change: 'new', sort: 'severity', order: 'desc', page: 3 });
  assert.deepEqual(stateFromSearch('?tool=pylint&language=go&sort=id&page=-1&category=a<b'), emptyState(), '잘못된 값은 기본(탭은 Python)');
  assert.equal(searchFromState(s), '?language=javascript&q=unused&severity=high%2Clow&analysis=1&change=new&sort=severity&order=desc&page=3');
  assert.equal(searchFromState(emptyState()), '?language=python');
  assert.deepEqual(apiQuery({ ...emptyState(), q: 'x', severity: ['low'] }, 50), { page: '1', size: '50', tool: 'ruff', q: 'x', severity: 'low', sort: 'rule', order: 'asc' });
  assert.equal(apiQuery(emptyState('javascript'), 50).tool, 'eslint');
  const applied = emptyState();
  assert.equal(isDirty({ ...applied }, applied), false, '입력만으로는 변경 없음');
  assert.equal(isDirty({ ...applied, severity: ['low'] }, applied), true);
  assert.equal(isDirty({ ...applied, severity: ['low', 'high'] }, { ...applied, severity: ['high', 'low'] }), false, '순서 무관');
  assert.equal(isDirty({ ...applied, page: 5 }, applied), false, '쪽 번호는 조건이 아님');
  assert.equal(isFiltered({ ...applied, sort: 'name' }), false);
  assert.equal(isFiltered({ ...applied, category: 'pyflakes' }), true);
  assert.equal(isFiltered(emptyState('javascript')), false, '탭은 조건이 아님');
  assert.equal(isDirty(emptyState('javascript'), emptyState('python')), false, '탭 차이는 변경 표시 대상 아님');
  assert.deepEqual(toggle(['a'], 'b'), ['a', 'b']);
});

test('X08 언어 탭: 언어 ↔ 도구, 탭 정하기 순서(주소 → 예전 tool= → 마지막 탭 → Python), 목록으로', () => {
  assert.deepEqual(LANGUAGES, ['python', 'javascript']);
  assert.equal(toolOf('python'), 'ruff');
  assert.equal(toolOf('javascript'), 'eslint');
  assert.equal(toolOf('typescript'), null);
  assert.equal(languageOf('eslint'), 'javascript');
  assert.equal(languageOf('pylint'), null);

  assert.deepEqual(resolveLanguage('?language=javascript', 'python'), { language: 'javascript', rewrite: false }, '주소가 이김');
  assert.deepEqual(resolveLanguage('?tool=eslint', 'python'), { language: 'javascript', rewrite: true }, '예전 주소 → 고쳐 씀');
  assert.deepEqual(resolveLanguage('?language=python&tool=eslint'), { language: 'python', rewrite: true }, '충돌하면 language가 이김');
  assert.deepEqual(resolveLanguage('', 'javascript'), { language: 'javascript', rewrite: true }, '마지막 탭');
  assert.deepEqual(resolveLanguage('?language=go', 'javascript'), { language: 'javascript', rewrite: true }, '잘못된 language는 무시');
  assert.deepEqual(resolveLanguage('', 'cobol'), { language: 'python', rewrite: true }, '잘못된 저장값은 무시');
  assert.deepEqual(resolveLanguage(''), { language: 'python', rewrite: true });

  assert.equal(backToListSearch('javascript', '?language=javascript&q=x&page=2'), '?language=javascript&q=x&page=2', '그 탭의 마지막 조건');
  assert.equal(backToListSearch('javascript', '?language=python&q=x'), '?language=javascript', '다른 탭의 조건은 쓰지 않음');
  assert.equal(backToListSearch('python', null), '?language=python');
  assert.equal(backToListSearch('python', 'javascript:alert(1)'), '?language=python');

  const meta = { tools: { ruff: { version: '0.16.10', components: {}, rules: 958, loaded_at: '2026-10-06T12:00:00Z' }, eslint: { version: '10.11.0', components: { '@typescript-eslint/eslint-plugin': '8.71.0' }, rules: 1234, stale: true } } };
  assert.equal(tabCountText(meta, 'ruff'), ' (958)');
  assert.equal(tabCountText(meta, 'eslint'), ' (1,234)');
  assert.equal(tabCountText({ tools: { ruff: {} } }, 'ruff'), '', '적재 전이면 건수 없음');
  assert.equal(basisText(meta, 'eslint'), '기준: ESLint 10.11.0 · @typescript-eslint 8.71.0', '탭의 도구만');
  assert.match(basisText(meta, 'ruff'), /^기준: ruff 0\.16\.10 · 마지막 적재 2026-10-06 12:00:00 UTC$/);
  assert.deepEqual(staleTools(meta, 'ruff'), []);
  assert.deepEqual(staleTools(meta, 'eslint'), ['eslint']);
});

test('화면 문장: 기준 버전, 최신 아님, 상태, 심각도 근거, 변경 내역, 활성 버전에 없음', () => {
  const meta = { tools: { ruff: { version: '0.16.10', components: {} }, eslint: { version: '10.11.0', components: { '@typescript-eslint/eslint-plugin': '8.71.0' }, stale: true, registry_version: '10.12.0' } } };
  assert.equal(basisText(meta), '기준: ruff 0.16.10 · ESLint 10.11.0 · @typescript-eslint 8.71.0');
  assert.equal(basisText({ tools: { ruff: {}, eslint: {} } }), '기준: ruff 규칙 데이터 없음(적재 전) · ESLint 규칙 데이터 없음(적재 전)');
  assert.deepEqual(staleTools(meta), ['eslint']);
  assert.deepEqual(statusParts({ status: 'deprecated', change: 'new' }), ['폐기 예정', '이번 버전 신설']);
  assert.equal(severityBasis({ level: 'group', group: 'problem' }), '근거: 카테고리(problem) 기본값');
  assert.equal(severityBasis({ level: 'prefix' }), '근거: 규칙 키 접두사로 지정');
  assert.equal(changeText({ kind: 'default_enabled', before: '0', after: '1' }), '기본 활성 변경: 꺼짐 → 켜짐');
  assert.equal(changeText({ kind: 'default_severity', before: 'low', after: 'high' }), '기본 심각도 변경: 낮음 → 높음');
  assert.equal(changeText({ kind: 'renamed', before: 'old', after: 'new' }), '이름 변경: old → new');
  assert.equal(changeText({ kind: 'fixable', before: 'none', after: 'always' }), '자동 수정 변경: 없음 → 가능');
  assert.equal(historyEmptyText({ versions: ['0.16.10'], version: '0.16.10', baseline_version: '0.16.10' }), '이전 버전 정보 없음(기준 버전 0.16.10)');
  assert.equal(historyEmptyText({ versions: ['2', '1'], version: '2', baseline_version: '1' }), '기준 버전(1) 이후 변경 없음');
  assert.match(missingText({ active_version: '10.11.0', version: '8.57.1', fate: { kind: 'removed', version: '9.39.5' } }), /활성 버전 10\.11\.0에는 없는 규칙입니다\. 마지막으로 있던 버전: 8\.57\.1 — 9\.39\.5에서 삭제/);
  for (const k of ['rules.special.syntax-error', 'rules.special.eslint:no-rule-id', 'rules.missing_tip', 'rules.kind.description', 'rules.fixable.sometimes']) assert.ok(has(k), k);
});

test('P22B 분석 적용: 주소 조건, 상세 버튼(켤 수 없으면 비활성 + 이유), 확인 창 문장, 관리 화면 요약', () => {
  const s = stateFromSearch('?language=python&analysis=0&overridden=1&default_enabled=1');
  assert.equal(s.analysis, '0', 'analysis가 있으면 예전 default_enabled는 무시');
  assert.equal(s.overridden, '1');
  assert.equal(stateFromSearch('?overridden=2&analysis=x').overridden, '', '잘못된 값은 무시');
  assert.equal(searchFromState(s), '?language=python&analysis=0&overridden=1');
  assert.deepEqual(apiQuery(s, 50), { page: '1', size: '50', tool: 'ruff', analysis: '0', overridden: '1', sort: 'rule', order: 'asc' });
  assert.equal(isFiltered({ ...emptyState(), overridden: '1' }), true);

  assert.deepEqual(overrideActions({ enabled: true, enableable: true, overridden: false }), [{ kind: 'disable', disabled: false, why: null }]);
  assert.deepEqual(overrideActions({ enabled: false, enableable: false, block: 'requires_type_info', overridden: false }),
    [{ kind: 'enable', disabled: true, why: '타입 정보가 필요한 규칙(서버 기본 설정에 타입 정보 설정이 없음)' }]);
  assert.deepEqual(overrideActions({ enabled: false, enableable: true, overridden: true }).map((a) => a.kind), ['enable', 'reset'], '변경 상태면 되돌리기도');
  assert.deepEqual(overrideActions({ enabled: true, enableable: false, block: 'deprecated', overridden: false }).map((a) => [a.kind, a.disabled]), [['disable', false]], '폐기 예정은 끌 수만');
  assert.deepEqual(overrideActions(null), []);

  const lines = overrideDialogLines('disable', { enabled: true, base_enabled: true }, 1234);
  assert.equal(lines[0], '변경: 켜짐 → 꺼짐');
  assert.match(lines[2], /이미 끝난 Run의 결함은 바뀌지 않으며/);
  assert.equal(lines[3], '서버 전체의 이 규칙 열린 결함: 1,234건');
  assert.equal(overrideDialogLines('reset', { enabled: false, base_enabled: true }, 0)[0], '변경: 꺼짐 → 켜짐', '되돌리기 = 기본값으로');

  const sum = overrideSummaryLines({
    ruff: { count: 2, enabled: 1, disabled: 1, last_changed_at: '2026-10-06T13:00:00Z', config_hash: 'abcdef012345', upload_config_projects: 3 },
    eslint: { count: 0, enabled: 0, disabled: 0, last_changed_at: null, config_hash: '0123456789ab' },
  }, (iso) => `T(${iso})`);
  assert.deepEqual(sum, [
    'ruff: 변경 2건(켬 1 · 끔 1) · 마지막 변경 T(2026-10-06T13:00:00Z) · 실효 설정 해시 abcdef012345',
    'ESLint: 변경 0건(켬 0 · 끔 0) · 변경 없음 · 실효 설정 해시 0123456789ab',
    'ruff 설정을 직접 올려 변경이 적용되지 않는 프로젝트 3개',
  ]);
  assert.equal(resetAllMatches(' ESLint ', 'eslint'), true);
  assert.equal(resetAllMatches('ruf', 'ruff'), false);
  assert.equal(resetAllMatches('', 'ruff'), false);
  for (const k of ['rules.block.removed', 'rules.block.preview', 'rules.block.requires_options', 'rules.block.special_key', 'rules.analysis_scope_all',
    'rules.analysis_scope_server_default', 'admin_rule_settings.state.missing', 'admin_rule_settings.state.not_enableable']) assert.ok(has(k), k);
});

test('P22C 규칙 키 접두사·일괄 대상 조건·확인 창 문장·선택 문장', () => {
  const s = stateFromSearch('?language=python&prefix=D&q=doc&page=2&sort=name');
  assert.equal(s.prefix, 'D');
  assert.equal(searchFromState(s), '?language=python&q=doc&prefix=D&sort=name&page=2', '주소 복원');
  for (const bad of ['a b', 'D%', "D'", 'x'.repeat(65)]) assert.equal(stateFromSearch(`?prefix=${encodeURIComponent(bad)}`).prefix, '', bad);
  assert.equal(stateFromSearch('?prefix=%40typescript-eslint%2F').prefix, '@typescript-eslint/');
  assert.equal(apiQuery({ ...emptyState(), prefix: 'E5' }, 50).prefix, 'E5');
  assert.equal(isFiltered({ ...emptyState(), prefix: 'D' }), true);
  assert.equal(isDirty({ ...emptyState(), prefix: 'D' }, emptyState()), true, '입력만 하면 변경 표시(적용 전)');
  // 검색 결과 전체 = 적용된 조건(쪽·정렬 없음, 탭 = language)
  assert.deepEqual(bulkFilter({ ...emptyState('javascript'), prefix: 'no-', severity: ['high', 'low'], analysis: '0', sort: 'name', order: 'desc', page: 4 }),
    { language: 'javascript', prefix: 'no-', severity: 'high,low', analysis: '0' });
  assert.deepEqual(bulkFilter(emptyState()), { language: 'python' });

  const p = { tool: 'ruff', action: 'enable', total: 47, changes: 40, turn_on: 40, turn_off: 0, unchanged: 2,
    skipped: { removed: 0, deprecated: 0, preview: 4, requires_type_info: 0, requires_options: 0, special_key: 0, missing: 1 }, skipped_total: 5,
    examples: [{ rule: 'D100', from: false, to: true }, { rule: 'D101', from: false, to: true }], open_defects: 1234 };
  assert.deepEqual(bulkDialogLines(p), [
    '대상 47개 중 실제로 바뀔 규칙 40개(켜짐 40 · 꺼짐 0)',
    '이미 그 상태라 바뀌지 않음 2개',
    '건너뜀 5개: 미리보기 4, 활성 버전에 없음 1',
    'D100(꺼짐 → 켜짐), D101(꺼짐 → 켜짐) 외 38개',
    '다음 분석부터 서버 전체에 적용됩니다.',
    '서버 전체의 이 규칙 열린 결함: 1,234건',
  ]);
  const none = bulkDialogLines({ ...p, changes: 0, turn_on: 0, unchanged: 47, skipped_total: 0, skipped: {}, examples: [], open_defects: 0 });
  assert.deepEqual(none, ['대상 47개 중 실제로 바뀔 규칙 0개(켜짐 0 · 꺼짐 0)', '이미 그 상태라 바뀌지 않음 47개', '바뀌는 규칙이 없습니다.'], '열린 결함 0이면 숨김');
  assert.equal(needsTypedConfirm('filter', 1), true, '검색 결과 전체는 언제나 도구 이름 입력');
  assert.equal(needsTypedConfirm('keys', 20), false);
  assert.equal(needsTypedConfirm('keys', 21), true, '20개 초과');
  assert.equal(bulkDoneText({ action: 'reset', changes: 3, skipped_total: 1 }), '3개를 바꿨습니다(되돌림), 건너뜀 1개.');
  assert.equal(selectionText(5, false, 970), '5개 선택됨');
  assert.equal(selectionText(50, true, 970), '검색 결과 전체 970개 선택됨');
  assert.equal(offerAllResults(50, 50, 970, false), true, '이 쪽을 모두 골랐고 결과가 더 있음');
  assert.equal(offerAllResults(49, 50, 970, false), false);
  assert.equal(offerAllResults(50, 50, 50, false), false, '결과가 이 쪽뿐');
  assert.equal(offerAllResults(50, 50, 970, true), false, '이미 전체');
  for (const k of ['rules.bulk_skip.requires_type_info', 'rules.bulk_skip.special_key', 'rules.switch_label', 'rules.bulk_revision_changed', 'rules.prefix_label',
    'admin_rule_settings.empty_none', 'admin_rule_settings.go_rules']) assert.ok(has(k), k);
});
