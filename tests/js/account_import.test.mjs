// 계정 CSV 일괄 처리(P28A) 화면 로직: 사유·파일 문장, 요약 조각, 보기 조건·쪽, 실행 가능 여부, 확인 문장, 결과 CSV의 수식 주입 방지,
// 그리고 서버가 돌려주는 모든 사유 코드에 ko·en 문장이 있는지.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
  codeText, fileText, summaryParts, filterRows, pageOf, canExecute, confirmLines, csvCell, resultCsv, resultFileName, PAGE_SIZE,
} from '../../public/assets/js/lib/account_import_core.js';
import ko from '../../public/assets/js/i18n/ko.js';
import en from '../../public/assets/js/i18n/en.js';

test('사유·파일 문장(모르는 코드는 그대로)', () => {
  assert.equal(codeText('login_id_taken'), '이미 있는 계정입니다(대소문자 무시)');
  assert.equal(codeText('nope_code'), 'nope_code');
  assert.equal(fileText({ code: 'too_many_rows', params: { max: 1000 } }), '행이 너무 많습니다(최대 1000행). 파일을 나누어 올리세요');
  assert.match(fileText({ code: 'group_column_ignored', params: { column: 'groups' } }), /"groups" 열은 무시/);
});

test('서버의 모든 사유 코드·파일 코드에 ko·en 문장', () => {
  const svc = readFileSync(new URL('../../src/Services/Accounts/AccountImportService.php', import.meta.url), 'utf8');
  const reader = readFileSync(new URL('../../src/Services/Accounts/AccountCsvReader.php', import.meta.url), 'utf8');
  const rowCodes = new Set([...svc.matchAll(/\$(?:errors|warnings)\[\] = '([a-z_]+)'/g)].map((m) => m[1]));
  for (const c of [...rowCodes]) if (c.endsWith('_')) rowCodes.delete(c); // 'display_name_' . 사유처럼 이어 붙이는 코드는 아래에서
  for (const reason of ['required', 'too_long', 'invalid']) rowCodes.add(`display_name_${reason}`);
  for (const reason of ['invalid', 'too_long']) rowCodes.add(`email_${reason}`);
  const fileCodes = new Set([...reader.matchAll(/\$fail\('([a-z_]+)'/g), ...reader.matchAll(/'code' => '([a-z_]+)'/g)].map((m) => m[1]));
  fileCodes.add('encoding_unknown');
  fileCodes.add('encoding_mismatch');
  assert.ok(rowCodes.size >= 20, `행 코드 ${rowCodes.size}`);
  for (const c of rowCodes) {
    assert.ok(ko[`account_import.code.${c}`], `ko ${c}`);
    assert.ok(en[`account_import.code.${c}`], `en ${c}`);
  }
  for (const c of fileCodes) {
    assert.ok(ko[`account_import.file.${c}`], `ko file ${c}`);
    assert.ok(en[`account_import.file.${c}`], `en file ${c}`);
  }
});

test('요약 조각: 0인 항목은 빼고 행 수는 늘', () => {
  const parts = summaryParts({ rows: 5, create: 2, deactivate: 0, skip: 1, error: 2, warning: 0, admin_create: 1 });
  assert.deepEqual(parts.map((p) => [p.key, p.kind, p.text]), [
    ['rows', 'muted', '5행'], ['create', 'info', '생성 2'], ['skip', 'muted', '건너뜀 1'], ['error', 'error', '오류 2'], ['admin_create', 'warn', '관리자 생성 1'],
  ]);
});

test('보기 조건·쪽 나누기(1,000행)', () => {
  const rows = Array.from({ length: 1000 }, (_, i) => ({ row: i + 2, status: i % 10 === 0 ? 'error' : (i % 10 === 1 ? 'warning' : 'ok') }));
  assert.equal(filterRows(rows, 'all').length, 1000);
  assert.equal(filterRows(rows, 'error').length, 100);
  assert.equal(filterRows(rows, 'warning').length, 100);
  const p1 = pageOf(rows, 1);
  assert.deepEqual([p1.page, p1.pages, p1.from, p1.to, p1.items.length], [1, 10, 1, 100, PAGE_SIZE]);
  const last = pageOf(rows, 99);
  assert.deepEqual([last.page, last.from, last.to], [10, 901, 1000], '범위를 넘으면 마지막 쪽');
  assert.deepEqual([pageOf([], 1).from, pageOf([], 1).pages], [0, 1]);
});

test('실행 가능 여부: 파일 오류·오류 행(제외 안 함)·처리할 행 없음', () => {
  const base = { file_errors: [], summary: { create: 2, deactivate: 0, error: 0 } };
  assert.deepEqual(canExecute(base, false), { ok: true, reason: null });
  assert.deepEqual(canExecute({ ...base, file_errors: [{ code: 'no_rows' }] }, true), { ok: false, reason: 'file' });
  const err = { file_errors: [], summary: { create: 1, deactivate: 0, error: 3 } };
  assert.deepEqual(canExecute(err, false), { ok: false, reason: 'errors' });
  assert.deepEqual(canExecute(err, true), { ok: true, reason: null });
  assert.deepEqual(canExecute({ file_errors: [], summary: { create: 0, deactivate: 0, error: 2 } }, true), { ok: false, reason: 'nothing' });
  assert.deepEqual(canExecute(null, true), { ok: false, reason: 'file' });
});

test('확인 문장: 관리자 생성·오류 제외·로그아웃 안내', () => {
  assert.deepEqual(confirmLines({ create: 3, deactivate: 2, admin_create: 1, error: 4 }, true), [
    '계정 3개를 만들고 2개를 비활성화합니다. 모두 반영하거나 하나도 반영하지 않습니다.', '관리자 1명을 만듭니다.', '오류 4행은 건너뜁니다.', '비활성화한 사용자는 즉시 로그아웃됩니다.',
  ]);
  assert.equal(confirmLines({ create: 1, deactivate: 0, admin_create: 0, error: 0 }, false).length, 1);
});

test('결과 CSV: 수식 문자로 시작하는 셀 앞 작은따옴표, 인용, BOM·CRLF', () => {
  assert.equal(csvCell('=SUM(A1)'), "'=SUM(A1)");
  assert.equal(csvCell('+82'), "'+82");
  assert.equal(csvCell('-x'), "'-x");
  assert.equal(csvCell('@cmd'), "'@cmd");
  assert.equal(csvCell('a,b'), '"a,b"');
  assert.equal(csvCell('say "hi"'), '"say ""hi"""');
  assert.equal(csvCell('=a,b'), '"\'=a,b"');
  assert.equal(csvCell(null), '');
  assert.equal(csvCell(12), '12');
  const csv = resultCsv([
    { row: 2, action: 'create', login_id: 'kim', display_name: '=HYPERLINK("x")', role: 'user', result: 'created', temp_password: 'Ab3$xyzXYZ12', temp_password_expires_at: '2026-10-12T00:00:00Z' },
    { row: 3, action: 'deactivate', login_id: 'old', result: 'deactivated' },
  ]);
  assert.ok(csv.startsWith('﻿row,action,login_id,display_name,role,result,temp_password,temp_password_expires_at\r\n'));
  const lines = csv.slice(1).trim().split('\r\n');
  assert.equal(lines[1], '2,create,kim,"\'=HYPERLINK(""x"")",user,created,Ab3$xyzXYZ12,2026-10-12T00:00:00Z');
  assert.equal(lines[2], '3,deactivate,old,,,deactivated,,');
  assert.equal(resultFileName(new Date(Date.UTC(2026, 9, 11, 1, 2, 3))), 'accounts_result_20261011_010203.csv');
});
