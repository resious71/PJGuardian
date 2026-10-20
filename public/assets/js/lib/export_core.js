// 내보내기(P23) 화면 규칙(DOM 없음 — tests/js/export.test.mjs): 파일 이름 읽기, 오류 문장, 안내 문장, 버튼 사용 가능 여부.
import { t } from '../core/i18n.js';

const nf = (n) => Number(n ?? 0).toLocaleString('ko-KR');

/**
 * 응답 Content-Disposition에서 파일 이름: filename*=UTF-8''…(한글)을 먼저, 없으면 filename="…". 경로 글자는 _로.
 * 읽을 수 없으면 fallback.
 */
export function filenameFromDisposition(header, fallback = 'export') {
  const h = String(header ?? '');
  let name = null;
  const star = /filename\*\s*=\s*UTF-8''([^;]+)/i.exec(h);
  if (star) {
    try { name = decodeURIComponent(star[1].trim()); } catch { name = null; }
  }
  if (!name) {
    const plain = /filename\s*=\s*"([^"]*)"/i.exec(h);
    if (plain) name = plain[1];
  }
  name = (name ?? '').replace(/[\\/:*?"<>|\u0000-\u001f]/g, '_').trim();
  return name === '' || name === '.' || name === '..' ? fallback : name;
}

/** 오류 코드별 안내(서버 문구가 있으면 그대로 쓰되 상한 초과는 건수·상한을 화면 형식으로) */
export function exportErrorText(e) {
  switch (e?.code) {
    case 'export_too_large': return t('export.too_large', { count: nf(e.details?.count), limit: nf(e.details?.limit) });
    case 'export_empty': return t('export.empty');
    case 'export_busy': return t('export.busy_error');
    case 'export_timeout': return t('export.timeout');
    case 'export_failed': return t('export.failed');
    case 'network': return t('export.network');
    default: return e?.message || t('export.failed');
  }
}

/** 버튼 옆 안내: 검색 영역에 적용하지 않은 변경이 있으면 "마지막으로 검색한 조건으로", 과거 Run이면 "Run N 기준" */
export function exportNote({ dirty = false, run = null } = {}) {
  const parts = [];
  if (run) parts.push(t('export.run_note', { run }));
  if (dirty) parts.push(t('export.applied_note'));
  return parts.join(' · ');
}

/** 버튼을 쓸 수 있는가(목록 0건이면 못 씀) */
export function exportEnabled(total) {
  return Number(total) > 0;
}
