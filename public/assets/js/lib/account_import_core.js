// 계정 CSV 일괄 처리(P28A) 화면 순수 로직: 판정·사유 문장, 보기 조건·쪽 나누기, 실행 가능 여부, 확인 문장, 결과 CSV(수식 주입 방지). DOM 없음(node --test).
import { t } from '../core/i18n.js';

export const PAGE_SIZE = 100;
/** 내려받는 CSV에서 수식으로 해석될 수 있는 첫 글자(CLAUDE.md 내보내기 규칙) */
const FORMULA_START = ['=', '+', '-', '@'];

/** 행 사유 코드 → 문장(모르는 코드는 코드 그대로) */
export function codeText(code) {
  const k = `account_import.code.${code}`;
  const v = t(k);
  return v === k ? code : v;
}

/** 파일 단위 오류·경고 {code, params} → 문장 */
export function fileText({ code, params = {} }) {
  const k = `account_import.file.${code}`;
  const v = t(k, params);
  return v === k ? code : v;
}

/** 요약 → 표시 조각 [{key, n, kind}] (0인 항목은 뺌, 행 수는 늘 보임) */
export function summaryParts(summary) {
  const order = [['rows', 'muted'], ['create', 'info'], ['deactivate', 'info'], ['skip', 'muted'], ['error', 'error'], ['warning', 'warn'], ['admin_create', 'warn']];
  return order
    .filter(([key]) => key === 'rows' || (summary?.[key] ?? 0) > 0)
    .map(([key, kind]) => ({ key, n: summary?.[key] ?? 0, kind, text: t(`account_import.sum.${key === 'admin_create' ? 'admin' : key}`, { n: summary?.[key] ?? 0 }) }));
}

/** 보기 조건: all | error | warning */
export function filterRows(rows, filter) {
  if (filter === 'error' || filter === 'warning') return rows.filter((r) => r.status === filter);
  return rows;
}

/** 쪽 나누기(1부터). 범위를 넘으면 마지막 쪽 */
export function pageOf(rows, page, size = PAGE_SIZE) {
  const pages = Math.max(1, Math.ceil(rows.length / size));
  const p = Math.min(Math.max(1, page), pages);
  const from = rows.length === 0 ? 0 : (p - 1) * size + 1;
  const to = Math.min(rows.length, p * size);
  return { page: p, pages, from, to, total: rows.length, items: rows.slice((p - 1) * size, p * size) };
}

/**
 * 실행 가능 여부 → {ok, reason}: reason = file(파일 오류) | errors(오류 행, 제외 안 함) | nothing(처리할 행 없음) | null
 */
export function canExecute(preview, skipErrors) {
  if (!preview || (preview.file_errors ?? []).length > 0) return { ok: false, reason: 'file' };
  const s = preview.summary ?? {};
  if ((s.error ?? 0) > 0 && !skipErrors) return { ok: false, reason: 'errors' };
  if ((s.create ?? 0) + (s.deactivate ?? 0) === 0) return { ok: false, reason: 'nothing' };
  return { ok: true, reason: null };
}

/** 실행 확인 대화상자 문장들 */
export function confirmLines(summary, skipErrors) {
  const lines = [t('account_import.confirm_body', { create: summary.create ?? 0, deactivate: summary.deactivate ?? 0 })];
  if ((summary.admin_create ?? 0) > 0) lines.push(t('account_import.confirm_admin', { n: summary.admin_create }));
  if (skipErrors && (summary.error ?? 0) > 0) lines.push(t('account_import.confirm_skip', { n: summary.error }));
  if ((summary.deactivate ?? 0) > 0) lines.push(t('account_import.confirm_deactivate'));
  return lines;
}

/** CSV 셀: 수식 문자로 시작하면 앞에 작은따옴표, 쉼표·따옴표·줄바꿈이 있으면 큰따옴표로 묶음 */
export function csvCell(value) {
  let v = value === null || value === undefined ? '' : String(value);
  if (v !== '' && FORMULA_START.includes(v[0])) v = `'${v}`;
  return /[",\r\n]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v;
}

/** 결과 CSV(UTF-8 BOM, CRLF): 머리글은 기계용 영문(언어와 무관) — 임시 비밀번호 열 포함, 서버는 이 파일을 보관하지 않는다 */
export function resultCsv(rows) {
  const head = ['row', 'action', 'login_id', 'display_name', 'role', 'result', 'temp_password', 'temp_password_expires_at'];
  const lines = [head.join(',')];
  for (const r of rows) {
    lines.push([r.row, r.action, r.login_id, r.display_name ?? '', r.role ?? '', r.result, r.temp_password ?? '', r.temp_password_expires_at ?? ''].map(csvCell).join(','));
  }
  return `﻿${lines.join('\r\n')}\r\n`;
}

/** 결과 파일 이름(시각은 표시 시간대가 아니라 UTC 숫자 — 파일 이름 충돌만 피함) */
export function resultFileName(now = new Date()) {
  const p = (n) => String(n).padStart(2, '0');
  return `accounts_result_${now.getUTCFullYear()}${p(now.getUTCMonth() + 1)}${p(now.getUTCDate())}_${p(now.getUTCHours())}${p(now.getUTCMinutes())}${p(now.getUTCSeconds())}.csv`;
}
