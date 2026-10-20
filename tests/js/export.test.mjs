// 내보내기(P23): 파일 이름 읽기, 오류 코드별 문장, 안내 문장, 버튼 사용 가능, 버튼 동작(적용 조건 전송·연타해도 요청 하나·파일 이름·오류)
import test from 'node:test';
import assert from 'node:assert/strict';
import { filenameFromDisposition, exportErrorText, exportNote, exportEnabled } from '../../public/assets/js/lib/export_core.js';
import { mountExport } from '../../public/assets/js/ui/export_buttons.js';
import { has } from '../../public/assets/js/core/i18n.js';

test('파일 이름: filename*(한글) 우선, 없으면 filename, 경로 글자는 _', () => {
  const h = 'attachment; filename="PJGuardian___.xlsx"; filename*=UTF-8\'\'PJGuardian_%EA%B2%B0%ED%95%A8_20261007-1830_Run2.xlsx';
  assert.equal(filenameFromDisposition(h), 'PJGuardian_결함_20261007-1830_Run2.xlsx');
  assert.equal(filenameFromDisposition('attachment; filename="a.csv"'), 'a.csv');
  assert.equal(filenameFromDisposition("attachment; filename*=UTF-8''..%2F..%2Fevil.csv"), '.._.._evil.csv', '경로 구분자 무력화');
  assert.equal(filenameFromDisposition("attachment; filename*=UTF-8''%E0%A4%A"), 'export', '깨진 인코딩');
  assert.equal(filenameFromDisposition(null, 'export.csv'), 'export.csv');
});

test('오류 문장: 상한 초과는 건수·상한, 그 밖 코드별', () => {
  assert.equal(exportErrorText({ code: 'export_too_large', details: { count: 61234, limit: 50000 } }), '결과가 61,234건이라 한 번에 내보낼 수 없습니다(상한 50,000건). 조건을 좁혀 주세요.');
  assert.equal(exportErrorText({ code: 'export_busy' }), '다른 내보내기가 진행 중입니다. 잠시 뒤에 다시 시도하세요.');
  assert.equal(exportErrorText({ code: 'export_empty' }), '내보낼 항목이 없습니다.');
  assert.match(exportErrorText({ code: 'export_timeout' }), /시간 안에 끝나지 않아/);
  assert.match(exportErrorText({ code: 'network' }), /연결할 수 없어/);
  assert.equal(exportErrorText({ code: 'run_detail_deleted', message: 'Run 1의 상세는 삭제되었습니다.' }), 'Run 1의 상세는 삭제되었습니다.', '그 밖은 서버 문구');
  assert.equal(exportErrorText(null), '내보내기 파일을 만들지 못했습니다.');
  for (const k of ['export.xlsx', 'export.csv', 'export.applied_note', 'export.run_note', 'export.empty_tip', 'export.done']) assert.ok(has(k), k);
});

test('안내 문장·버튼 사용 가능', () => {
  assert.equal(exportNote({}), '');
  assert.equal(exportNote({ dirty: true }), '마지막으로 검색한 조건으로 내보냅니다.');
  assert.equal(exportNote({ run: 3, dirty: true }), 'Run 3 기준 · 마지막으로 검색한 조건으로 내보냅니다.');
  assert.equal(exportEnabled(0), false);
  assert.equal(exportEnabled(1), true);
});

/** 버튼 동작용 작은 가짜 화면 */
function fakeUi() {
  const mk = (format) => {
    const l = {};
    return { dataset: { exportFormat: format }, disabled: false, title: '', addEventListener: (e, f) => { l[e] = f; }, click: () => l.click?.() };
  };
  const buttons = [mk('xlsx'), mk('csv')];
  const note = { textContent: '', hidden: true };
  const attrs = {};
  const box = { hidden: false, querySelectorAll: () => buttons, querySelector: () => note, toggleAttribute: (n, v) => { attrs[n] = v; } };
  const downloads = [];
  const toasts = [];
  const region = { appendChild: (n) => { toasts.push(n); return n; } };
  const doc = {
    querySelector: (sel) => (sel.includes('csrf-token') ? { getAttribute: () => 'TOKEN' } : sel.includes('app-base') ? { getAttribute: () => '/base' } : null),
    getElementById: (id) => (id === 'toasts' ? region : null),
    createElement: (tag) => ({ tagName: tag, hidden: false, click() { downloads.push(this.download); }, remove() {}, setAttribute() {}, appendChild() {}, addEventListener() {}, classList: { add() {} }, dataset: {} }),
    createTextNode: (t) => ({ t }),
    createElementNS: () => ({ setAttribute() {}, appendChild() {}, dataset: {}, classList: { add() {} }, style: {} }),
    body: { appendChild: (n) => n },
  };
  return { buttons, note, box, doc, downloads, toasts, attrs };
}

test('버튼 동작: 적용 조건·CSRF로 POST, 연타해도 요청 하나, 응답 파일 이름으로 내려받기', async () => {
  const ui = fakeUi();
  const calls = [];
  let release;
  const gate = new Promise((r) => { release = r; });
  const fetchImpl = async (url, init) => {
    calls.push({ url, init });
    await gate;
    return {
      ok: true, status: 200,
      headers: { get: (h) => ({ 'content-disposition': "attachment; filename=\"x.xlsx\"; filename*=UTF-8''%EA%B2%B0%ED%95%A8.xlsx", 'x-export-rows': '12' })[h.toLowerCase()] ?? null },
      blob: async () => new Blob(['PK']),
    };
  };
  const exp = mountExport(ui.box, { url: '/api/v1/projects/7/defects/export', getBody: () => ({ q: 'applied', run: '2' }), doc: ui.doc, fetchImpl });
  assert.equal(ui.buttons[0].disabled, true, '목록을 읽기 전에는 꺼짐');
  exp.update({ total: 12, dirty: true, run: 2 });
  assert.equal(ui.buttons[0].disabled, false);
  assert.equal(ui.note.textContent, 'Run 2 기준 · 마지막으로 검색한 조건으로 내보냅니다.');
  ui.buttons[0].click();
  ui.buttons[0].click();
  ui.buttons[1].click();
  assert.equal(calls.length, 1, '진행 중에는 요청 하나');
  assert.equal(ui.buttons[1].disabled, true, '진행 중 두 버튼 모두 꺼짐');
  release();
  await new Promise((r) => setTimeout(r, 10));
  assert.equal(calls[0].url, '/base/api/v1/projects/7/defects/export');
  assert.equal(calls[0].init.method, 'POST');
  assert.equal(calls[0].init.headers['X-CSRF-Token'], 'TOKEN');
  assert.deepEqual(JSON.parse(calls[0].init.body), { format: 'xlsx', q: 'applied', run: '2' });
  assert.deepEqual(ui.downloads, ['결함.xlsx']);
  assert.equal(ui.buttons[0].disabled, false, '끝나면 다시 켜짐');
  exp.update({ total: 0 });
  assert.equal(ui.buttons[0].disabled, true, '0건이면 꺼짐');
  assert.equal(ui.buttons[0].title, '내보낼 항목이 없습니다.');
  exp.update({ total: 5, hidden: true });
  assert.equal(ui.box.hidden, true, '삭제된 Run 화면에서는 숨김');
});

test('버튼 동작: 오류 응답이면 내려받지 않고 코드별 안내', async () => {
  const ui = fakeUi();
  const fetchImpl = async () => ({ ok: false, status: 413, headers: { get: () => null }, json: async () => ({ error: { code: 'export_too_large', details: { count: 50001, limit: 50000 } } }) });
  const exp = mountExport(ui.box, { url: '/x', getBody: () => ({}), doc: ui.doc, fetchImpl });
  exp.update({ total: 50001 });
  ui.buttons[1].click();
  await new Promise((r) => setTimeout(r, 10));
  assert.deepEqual(ui.downloads, []);
  assert.equal(ui.toasts.length, 1);
});
