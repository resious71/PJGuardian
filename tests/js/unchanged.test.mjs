// 성공(변경 없음)(P27): 이력 배지·접기 문구·필터 주소 복원, 상세 기준 Run 문장, "지금 가져와 분석" 지켜보기 판단,
// 마지막 확인 문구, 강제 분석 확인 대화상자(취소가 기본 초점·Tab 순환·Esc = 취소·닫히면 연 요소로 초점 복귀).
import test from 'node:test';
import assert from 'node:assert/strict';
import { FakeDocument, FakeElement } from './fake-dom.mjs';
import {
  historyState, historySearch, historyChartData, rowName, statusBadgeName, unchangedFold, shortDateTime, shortRevision, unchangedBaseText, unchangedBaseShort,
} from '../../public/assets/js/lib/history_core.js';
import { analysisTitle } from '../../public/assets/js/lib/analysis_view.js';
import { fetchWatchState, lastCheckText } from '../../public/assets/js/lib/repo_core.js';
import { confirmDialog } from '../../public/assets/js/ui/dialog.js';
import { t } from '../../public/assets/js/core/i18n.js';

const fmt = (iso) => iso.replace('T', ' ').slice(0, 16); // 시험용 "yyyy-mm-dd HH:mm"

test('필터 주소: unchanged=hide ↔ hide, 기본은 보이기(주소에 없음), 다른 값은 무시', () => {
  assert.deepEqual(historyState('?unchanged=hide&page=2'), { analysis: null, page: 2, n: 20, hide: true });
  assert.deepEqual(historyState('?unchanged=show'), { analysis: null, page: 1, n: 20 });
  assert.deepEqual(historyState('?unchanged=x'), { analysis: null, page: 1, n: 20 });
  assert.equal(historySearch({ analysis: null, page: 1, n: 20, hide: true }), '?unchanged=hide');
  assert.equal(historySearch({ analysis: 3, page: 1, n: 20, hide: false }), '?analysis=3');
  // 왕복(새로 고침·뒤로 가기 복원)
  assert.deepEqual(historyState(historySearch({ analysis: 7, page: 4, n: 50, hide: true })), { analysis: 7, page: 4, n: 50, hide: true });
});

test('배지·이름: 상태 값은 succeeded 그대로, 표시는 성공(변경 없음), Run 번호 없음', () => {
  const r = { analysis_id: 9, run: null, status: 'succeeded', unchanged: true, counts: null };
  assert.equal(statusBadgeName(r), 'analysis_status.unchanged');
  assert.equal(statusBadgeName({ status: 'succeeded' }), 'analysis_status.succeeded');
  assert.equal(statusBadgeName({ status: 'failed', unchanged: false }), 'analysis_status.failed');
  assert.equal(rowName(r), 'Run — · 분석 #9', '이력 표가 넓어지지 않게 짧게(변경 없음은 배지가 말한다)');
  assert.equal(unchangedBaseShort({ analysis_id: 12, run: 3 }), '기준 Run 3');
  assert.equal(unchangedBaseShort({ analysis_id: 12, run: null }), '기준 분석 #12');
  assert.equal(unchangedBaseShort(null), null);
  assert.equal(t('icon.analysis_status.unchanged'), '성공(변경 없음)');
  const title = analysisTitle({ id: 9, status: 'succeeded', unchanged: true, run: null });
  assert.deepEqual(title.map((p) => p.key), ['runs.none_status', 'analyses.title_of']);
  assert.equal(title[0].params.status, 'icon.analysis_status.unchanged');
  // 추이 차트에는 들어가지 않는다(통계 없음)
  const d = historyChartData([r, { analysis_id: 5, run: 2, status: 'succeeded', counts: { new: 1, resolved: 0, reopened: 0 }, open_total: 3, changed: false }]);
  assert.deepEqual(d.analysisIds, [5]);
});

test('접기 문구: N회 확인 · 마지막 확인 yy-mm-dd HH:mm, 1회면 마지막 확인만', () => {
  assert.equal(shortDateTime('2026-10-08 19:05'), '26-10-08 19:05');
  assert.equal(shortDateTime('—'), '—');
  assert.equal(unchangedFold({ unchanged_check_count: 3, unchanged_last_checked_at: '2026-10-08T19:05:00Z' }, fmt), '3회 확인 · 마지막 확인 26-10-08 19:05');
  assert.equal(unchangedFold({ unchanged_check_count: 1200, unchanged_last_checked_at: '2026-10-08T19:05:00Z' }, fmt), '1,200회 확인 · 마지막 확인 26-10-08 19:05');
  assert.equal(unchangedFold({ unchanged_check_count: 1, unchanged_last_checked_at: '2026-10-08T19:05:00Z' }, fmt), '마지막 확인 26-10-08 19:05');
});

test('기준 문장: 리비전 짧게(Git 7자리, Perforce @번호) + 기준 Run과 날짜', () => {
  assert.equal(shortRevision('git', 'abcdef0123456789abcdef0123456789abcdef01'), 'abcdef0');
  assert.equal(shortRevision('perforce', '4321'), '@4321');
  const base = { analysis_id: 12, run: 3, finished_at: '2026-10-01T09:00:00Z' };
  assert.equal(unchangedBaseText('git', 'abcdef0123456789abcdef0123456789abcdef01', base, fmt), '변경 없음 — 리비전 abcdef0은 Run 3(26-10-01 09:00)에서 이미 분석했습니다');
  assert.equal(unchangedBaseText('perforce', '4321', { ...base, run: null }, fmt), '변경 없음 — 리비전 @4321은 분석 #12(26-10-01 09:00)에서 이미 분석했습니다');
  assert.equal(unchangedBaseText('git', null, null, fmt), '변경 없음 — 리비전 —은 직전에 분석한 리비전과 같습니다');
});

test('지금 가져와 분석 지켜보기: 대기·가져오기 전은 기다림, 변경 없음이면 확인, 가져왔으면(분석 중) 끝', () => {
  assert.equal(fetchWatchState({ status: 'queued' }), 'wait');
  assert.equal(fetchWatchState({ status: 'running', fetched_at: null }), 'wait');
  assert.equal(fetchWatchState({ status: 'running', fetched_at: '2026-10-08T10:00:00Z' }), 'analyzing');
  assert.equal(fetchWatchState({ status: 'succeeded', unchanged: true }), 'unchanged');
  assert.equal(fetchWatchState({ status: 'succeeded', unchanged: false }), 'done');
  assert.equal(fetchWatchState({ status: 'failed' }), 'done');
  assert.equal(fetchWatchState(null), 'done');
  assert.equal(lastCheckText({ last_check: { at: '2026-10-08T10:00:00Z', result: 'unchanged' } }, fmt), '2026-10-08 10:00 · 변경 없음');
  assert.equal(lastCheckText({ last_check: { at: '2026-10-08T10:00:00Z', result: 'error' } }, fmt), '2026-10-08 10:00 · 확인 실패');
  assert.equal(lastCheckText({ last_check: null }, fmt), '—');
});

// 대화상자 시험용 가짜 DOM 확장: showModal·close(close 이벤트)·focus(activeElement)
class DialogDocument extends FakeDocument {
  constructor() {
    super();
    this.activeElement = null;
  }

  createElement(tag) {
    const e = new FakeElement(this, tag);
    e.focus = () => { this.activeElement = e; };
    e.showModal = () => { e.open = true; };
    e.close = () => { e.open = false; e.dispatch('close'); };
    return e;
  }
}

const buttons = (doc) => {
  const dlg = doc.body.childNodes.find((n) => n.tagName === 'DIALOG');
  const find = (n, out = []) => { if (n.tagName === 'BUTTON') out.push(n); for (const c of n.childNodes ?? []) find(c, out); return out; };
  return { dlg, btns: dlg ? find(dlg) : [] };
};

test('강제 분석 확인: 문구, 취소가 기본 초점, Tab 순환, Esc·취소 = false, 확인 = true, 닫히면 연 요소로 초점', async () => {
  const doc = new DialogDocument();
  const opener = doc.createElement('button');
  opener.focus();
  const p = confirmDialog({ title: t('repo.force_title'), message: t('repo.force_message'), confirmLabel: t('repo.force_confirm'), warn: true, doc });
  const { dlg, btns } = buttons(doc);
  assert.ok(dlg?.open, '모달로 열림');
  assert.equal(dlg.textContent.includes('변경이 없습니다. 그래도 분석할까요?'), true);
  const [cancel, ok] = btns;
  assert.equal(ok.textContent, '그래도 분석');
  assert.equal(doc.activeElement, cancel, '취소가 기본 초점(실수로 다시 분석하지 않게)');
  let prevented = false;
  doc.activeElement = ok;
  dlg.dispatch('keydown', { key: 'Tab', shiftKey: false, preventDefault() { prevented = true; } });
  assert.deepEqual([prevented, doc.activeElement === cancel], [true, true], 'Tab: 확인 → 취소로 순환');
  prevented = false;
  dlg.dispatch('keydown', { key: 'Tab', shiftKey: true, preventDefault() { prevented = true; } });
  assert.deepEqual([prevented, doc.activeElement === ok], [true, true], 'Shift+Tab: 취소 → 확인으로 순환');
  dlg.close(); // Esc는 브라우저가 close 이벤트를 보낸다
  assert.equal(await p, false, 'Esc = 취소(아무것도 만들지 않음)');
  assert.equal(doc.activeElement, opener, '초점 복귀');
  assert.equal(doc.body.childNodes.includes(dlg), false, '대화상자 제거');
  const p2 = confirmDialog({ title: 'x', message: 'y', confirmLabel: t('repo.force_confirm'), doc });
  buttons(doc).btns[1].dispatch('click');
  assert.equal(await p2, true, '확인 = force 분석');
});
