// 결함 표의 행(P17 목록에서 뽑아 냄, P18 일괄 선택·"내게 지정된 결함"이 함께 쓴다). el()/textContent로만 만든다(innerHTML 금지).
//   defectRow(d, { detailBase, tz, select, project, detailQuery }) → <tr>
//     detailQuery: 상세 링크 뒤에 붙일 쿼리(과거 Run 보기면 '?run=N', P20R)
//     viewRun: 과거 Run 보기의 Run 번호(P21U: Run 열의 한정 안내 툴팁)
//     select: { checked, label, onChange(checked) } — 일괄 선택 체크박스 열(없으면 열 없음)
//     project: true — 맨 앞에 프로젝트 이름 열(내게 지정된 결함)
import { t } from '../core/i18n.js';
import { el, meta } from '../core/dom.js';
import { lastDetectedCells } from '../lib/run_core.js';
import { badge } from './badge.js';
import { icon } from './icons.js';
import { clipText, tipTarget } from './clip.js';

/** 담당자 칸: 이름(로그인 ID는 title) + 비활성 / 접근 권한 없음 표시 */
export function assigneeCell(a, doc = globalThis.document) {
  if (!a) return el('span', { class: 'muted', text: t('defects.unassigned') }, [], doc);
  const parts = [el('span', { text: a.display_name, title: a.login_id }, [], doc)];
  if (!a.active) parts.push(' ', el('span', { class: 'tag tag--inactive', text: t('defects.inactive') }, [], doc));
  else if (a.has_access === false) parts.push(' ', el('span', { class: 'tag tag--inactive', text: t('defects.no_access') }, [], doc));
  return el('span', {}, parts, doc);
}

export function defectRow(d, { detailBase, tz, select = null, project = false, detailQuery = '', viewRun = null }, doc = globalThis.document) {
  const location = `${d.file_path}:${d.line}`;
  const base = meta('app-base', doc) ?? '';
  const rule = d.rule_url
    ? el('a', { href: `${base}${d.rule_url}` }, [el('code', { text: d.rule_key }, [], doc)], doc) // P22 전에는 rule_url이 항상 null
    : el('code', { text: d.rule_key }, [], doc);
  const cells$ = lastDetectedCells(d, { base, tz, viewRun });
  const cells = [];
  if (select) {
    cells.push(el('td', { class: 'defects-table__select' }, [el('input', {
      type: 'checkbox', checked: select.checked, 'aria-label': select.label, dataset: { select: d.id },
      on: { change: (e) => select.onChange(e.target.checked) },
    }, [], doc)], doc));
  }
  if (project) cells.push(el('td', { class: 'cell-clip', title: d.project?.name ?? '', text: d.project?.name ?? '' }, [], doc));
  cells.push(
    el('td', {}, [severityCell(d, doc)], doc),
    el('td', {}, [badge(`defect_status.${d.status}`, doc)], doc),
    // P22: 규칙 키 → 규칙 설명(rule_url). 활성 도구 버전에 없는 규칙이면 앞에 표시(아이콘 + 화면 읽기용 글자 + 툴팁, 색에만 의존하지 않음)
    el('td', { class: 'cell-clip defects-table__rule', title: d.rule_key }, d.rule_missing
      ? [tipTarget(el('span', { class: 'rule-missing-mark', dataset: { ruleMissing: '' } }, [icon('system.warn', 16, doc), el('span', { class: 'sr-only', text: t('rules.missing_short') }, [], doc)], doc), t('rules.missing_tip')), rule]
      : [rule], doc),
    // P21 0C: 긴 "파일:줄"은 앞부분만 줄이고(파일명:줄은 항상 보임), 메시지는 끝을 줄인다. 전체 값은 마우스·포커스 툴팁(ui/clip.js)
    el('td', { class: 'defects-table__location' }, [el('a', { href: `${detailBase}${d.id}${detailQuery}` }, [el('code', {}, [clipText(location, { kind: 'path', focusable: false }, doc)], doc)], doc)], doc),
    el('td', { class: 'defects-table__message' }, [clipText(d.message, {}, doc)], doc),
    el('td', {}, [assigneeCell(d.assignee, doc)], doc),
    // P21U: "Run"(마지막 감지 Run → 히스토리 비교 패널) + "최근 감지"(그 분석의 종료 날짜 yy-mm-dd, 전체 일시는 툴팁)
    el('td', { class: 'defects-table__run' }, [runCell(cells$.run, cells$.href,
      [cells$.capped ? t('defects.last_run_capped', { run: viewRun }) : t('defects.last_run_title'), d.last_detected_detail_deleted ? t('runs.detail_deleted_tip', { run: d.last_detected_run }) : null].filter(Boolean).join(' · '), doc)], doc),
    el('td', { class: 'defects-table__seen' }, [cells$.full
      ? tipTarget(el('span', { class: 'defects-table__date', text: cells$.date }, [], doc), t('defects.last_seen_tip', { time: cells$.full }))
      : el('span', { class: 'muted', text: cells$.date }, [], doc)], doc),
  );
  return el('tr', { dataset: { defectId: d.id } }, cells, doc);
}

/**
 * 심각도 배지 + 출처(P21S, 새 열 없음): 출처는 공통 툴팁 글자로 알린다(색에만 의존하지 않음).
 * 사람이 바꾼 값(manual)은 배지 옆에 작은 "수동 변경" 글자도 둔다(자동 부여가 대부분이라 auto는 툴팁만).
 */
export function severityCell(d, doc = globalThis.document) {
  const b = badge(`severity.${d.severity}`, doc);
  const src = d.severity_source ?? 'none';
  if (src === 'none') return b;
  const parts = [b];
  if (src === 'manual') parts.push(el('span', { class: 'severity-source muted', text: t('defects.severity_source.manual') }, [], doc));
  return tipTarget(el('span', { class: 'severity-cell', dataset: { severitySource: src } }, parts, doc), t(`defects.severity_source_tip.${src}`));
}

function runCell(text, href, tip, doc) {
  if (!href) return el('span', { class: 'muted', text }, [], doc);
  return tipTarget(el('a', { class: 'run-tag', href, text }, [], doc), tip);
}
