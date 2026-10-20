// 분석 화면 계산(P19): node --test "tests/js/*.test.mjs"
import test from 'node:test';
import assert from 'node:assert/strict';
import { isActive, durationParts, durationKey, analysisNotices, changeLink, baselineKey, analysisTitle } from '../../public/assets/js/lib/analysis_view.js';
import { t, has } from '../../public/assets/js/core/i18n.js';

test('분석: 진행 중 판정과 소요 시간', () => {
  assert.equal(isActive({ status: 'queued' }), true);
  assert.equal(isActive({ status: 'running' }), true);
  assert.equal(isActive({ status: 'failed' }), false);
  assert.deepEqual(durationParts(3725), { h: 1, m: 2, s: 5 });
  assert.equal(durationKey(null), null);
  assert.equal(t(durationKey(3725).key, durationKey(3725).params), '1시간 2분');
  assert.equal(t(durationKey(65).key, durationKey(65).params), '1분 5초');
  assert.equal(t(durationKey(7).key, durationKey(7).params), '7초');
});

test('분석: 안내(취소, 설정 무시됨, 경고 확인, 대상 없음)', () => {
  const keys = (a) => analysisNotices(a).map((n) => n.key);
  assert.deepEqual(keys({ status: 'cancelled' }), ['analyses.notice.cancelled']);
  assert.deepEqual(keys({ status: 'running', cancel_requested: true }), ['analyses.notice.cancel_requested']);
  const a = {
    status: 'succeeded',
    counts: { resolved: 19 },
    ignored_configs: [
      { file: '.eslintrc.js', reason: 'eslint_config_ignored' },
      { file: '…', reason: 'eslint_config_ignored', count: 3 },
      { file: 'sub/ruff.toml', reason: 'ruff_nested_config' },
      { file: 'pyproject.toml', reason: 'ruff_config_rejected', detail: 'extend가 프로젝트 밖' },
      { file: '.eslintignore', reason: 'eslintignore_converted', count: 2 },
    ],
    upload: { confirmed_at: '2026-10-04T00:00:00Z', warnings: ['no_target', 'top_folder_kept'] },
  };
  const n = analysisNotices(a);
  assert.deepEqual(n.map((x) => x.key), [
    'analyses.notice.eslint_config_ignored', 'analyses.notice.ruff_nested_config', 'analyses.notice.ruff_config_rejected',
    'analyses.notice.eslintignore_converted', 'analyses.notice.top_folder_confirmed', 'analyses.notice.no_target_resolved',
  ]);
  assert.equal(n[0].params.files, '.eslintrc.js, +3');
  assert.match(t(n[5].key, n[5].params), /19건이 해결/);
  assert.deepEqual(keys({ status: 'succeeded', upload: { confirmed_at: null, warnings: ['no_target'] } }), [], '확인하지 않은 경고는 안내하지 않음');
  for (const x of n) assert.equal(has(x.key), true, x.key);
});

test('분석: 결과 링크와 비교 대상', () => {
  assert.equal(changeLink('/PJGuardian', 3, 9, 'new'), '/PJGuardian/projects/3?analysis=9&change=new');
  assert.deepEqual(baselineKey({ status: 'succeeded', first_analysis: true, baseline: null }), { key: 'analyses.baseline_none', params: {} });
  assert.deepEqual(baselineKey({ status: 'succeeded', first_analysis: false, baseline: { id: 4 } }), { key: 'analyses.baseline_of', params: { id: 4 } });
  assert.equal(baselineKey({ status: 'failed' }), null);
  // P20R: 비교 대상 Run 번호, 분석 이름(성공 = Run N · 분석 #id, 그 밖 = Run —(상태))
  assert.deepEqual(baselineKey({ status: 'succeeded', baseline: { id: 4, run: 2 } }), { key: 'analyses.baseline_of_run', params: { id: 4, run: 2 } });
  assert.deepEqual(analysisTitle({ id: 9, status: 'succeeded', run: 3 }), [{ key: 'runs.title_of', params: { run: 3, id: 9 } }]);
  assert.deepEqual(analysisTitle({ id: 9, status: 'failed', run: null }).map((p) => p.key), ['runs.none_status', 'analyses.title_of']);
  assert.deepEqual(analysisTitle({ id: 9, status: 'succeeded', run: null }).map((p) => p.key), ['analyses.title_of'], '되채움 전');
  for (const k of ['analyses.count.new', 'analyses.cancel_message', 'upload.error_action', 'defects.analysis_filter.new']) assert.equal(has(k), true, k);
});
