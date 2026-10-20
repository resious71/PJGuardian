// 대시보드 화면 계산(P20): node --test "tests/js/*.test.mjs"
import test from 'node:test';
import assert from 'node:assert/strict';
import {
  SEVERITIES, SEVERITY_POINT, trendData, trendTable, pointHref, changeLines, shortenPath, barData, topSummary, emptyState, count, appLink, toolRows,
} from '../../public/assets/js/lib/dashboard_core.js';
import { has } from '../../public/assets/js/core/i18n.js';

const point = (id, total, changed = false, changes = {}) => ({
  analysis_id: id, finished_at: `2026-09-0${id}T00:00:00Z`, total, by_severity: { critical: 1, high: total - 1 }, tool_name: 'ruff', tool_version: '0.16.10', changed, changes,
});

test('추이: 차트 데이터·표식 위치·표 데이터', () => {
  const s = { project_name: 'p', points: [point(1, 5), point(2, 7, true, { tool_version: { from: '0.15.0', to: '0.16.10' } }), point(3, 6)] };
  const d = trendData(s, (iso) => iso.slice(5, 10));
  assert.deepEqual(d.labels, ['09-01', '09-02', '09-03']);
  assert.deepEqual(d.total, [5, 7, 6]);
  assert.deepEqual(d.markers, [null, 7, null], '바뀐 지점에만 표식(총 건수 위치)');
  assert.deepEqual(d.severity.high, [4, 6, 5]);
  assert.deepEqual(d.severity.low, [0, 0, 0], '없는 심각도는 0');
  assert.deepEqual(d.analysisIds, [1, 2, 3]);
  assert.deepEqual(changeLines(s.points[1]), [{ field: 'tool_version', from: '0.15.0', to: '0.16.10' }]);
  assert.deepEqual(changeLines(s.points[0]), []);
  const tbl = trendTable(s);
  assert.equal(tbl.head.length, tbl.rows[0].length, '머리글과 행 길이');
  assert.deepEqual(tbl.rows[1].slice(-2), ['ruff 0.16.10', 1]);
  assert.deepEqual(trendData({ points: [] }).total, []);
  // P20R: Run 번호가 있으면 "Run N"으로 부르고 누르면 그 Run 시점의 결함 목록, 없으면(되채움 전) 시각·분석 상세
  const r = { project_name: 'p', points: [{ ...point(4, 3), run: 2 }, point(5, 4)] };
  assert.deepEqual(trendData(r, (iso) => iso.slice(5, 10), (n) => `R${n}`).labels, ['R2', '09-05']);
  assert.deepEqual(trendData(r).runs, [2, null]);
  assert.equal(pointHref('/b', 7, r.points[0]), '/b/projects/7?run=2');
  assert.equal(pointHref('/b', 7, r.points[1]), '/b/analyses/5');
  assert.deepEqual(trendTable(r).rows.map((row) => row[0]), [2, null]);
  // 심각도 점 모양은 모두 다르고 변경 표식(마름모)과 겹치지 않음
  const shapes = SEVERITIES.map((s2) => SEVERITY_POINT[s2]);
  assert.equal(new Set(shapes).size, SEVERITIES.length);
  assert.ok(!shapes.includes('rectRot'));
});

test('상위 10 막대: 라벨은 글자 그대로(HTML 해석 없음), 긴 경로는 끝부분', () => {
  const items = [
    { project_id: 1, project_name: '<img src=x onerror=1>', file_path: 'very/long/path/to/some/deeply/nested/package/module_<b>.py', count: 3, by_severity: { high: 3 }, link: '/projects/1?file=x' },
    { project_id: 2, project_name: 'b', file_path: 'a.py', count: 1, by_severity: {}, link: '/projects/2?file=a.py' },
  ];
  const b = barData(items, 'files', { multiProject: true, max: 30 });
  assert.ok(b.labels[0].startsWith('<img src=x onerror=1> · …/'), '프로젝트 이름은 글자 그대로');
  assert.ok(b.labels[0].endsWith('module_<b>.py'), '파일 이름이 보이게 끝부분');
  assert.equal(b.fullLabels[0], '<img src=x onerror=1> · very/long/path/to/some/deeply/nested/package/module_<b>.py', '툴팁에 전체 경로');
  assert.deepEqual(b.counts, [3, 1]);
  assert.deepEqual(b.severity.high, [3, 0]);
  const r = barData([{ rule_key: 'ruff:F401<script>', count: 2, by_severity: {} }], 'rules');
  assert.deepEqual(r.labels, ['ruff:F401<script>']);
  assert.equal(shortenPath('short.py'), 'short.py');
  assert.ok(shortenPath('a'.repeat(100), 20).length <= 20);
  assert.equal(topSummary(items, 'files'), 'very/long/path/to/some/deeply/nested/package/module_<b>.py 3, a.py 1');
});

test('빈 상태·0과 모름·링크', () => {
  const sum = (visible, analysed, statuses) => ({ projects: { visible, with_analysis: analysed }, by_status: statuses });
  assert.equal(emptyState(sum(0, 0, {}), true), 'no_projects');
  assert.equal(emptyState(sum(0, 0, {}), false), 'no_access');
  assert.equal(emptyState(sum(2, 0, {}), false), 'no_analysis');
  assert.equal(emptyState(sum(2, 1, { open: 0, resolved: 0 }), false), 'no_defects');
  assert.equal(emptyState(sum(2, 1, { open: 0, resolved: 3 }), false), null, '해결된 결함만 있어도 빈 상태 아님');
  for (const k of ['no_projects', 'no_access', 'no_analysis', 'no_defects']) {
    assert.ok(has(`dashboard.empty.${k}.title`) && has(`dashboard.empty.${k}.body`), k);
  }
  assert.equal(count(0), '0');
  assert.equal(count(null), '—');
  assert.equal(appLink('/app', '/projects/1?rule=a'), '/app/projects/1?rule=a');
  assert.equal(appLink('', '//evil.example/x'), null, '다른 호스트로 가는 링크 거부');
  assert.equal(appLink('', 'javascript:alert(1)'), null);
  const rows = toolRows([{ tool: 'ruff', language: 'python', projects: 2, projects_with_analysis: 1, open: 5, by_severity: { high: 5 }, versions: [{ version: '0.16.10', projects: 1 }] }]);
  assert.deepEqual([rows[0].severity, rows[0].versions], [[0, 5, 0, 0, 0], '0.16.10 (1)']);
});
