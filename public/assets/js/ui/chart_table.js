// 차트 ↔ 표 전환 공통(P21 0B). 대시보드·히스토리의 차트가 함께 쓴다. el()/textContent로만 만든다.
//   chartFigure(title, { chart, table, tableMode }) → <figure>: 보이는 제목(figcaption) + 차트 상자 + 같은 데이터의 표(제목 바로 아래, 표 모드일 때만 보임)
//     차트가 여러 개(프로젝트별)여도 표가 자기 제목 아래에 놓여 어느 표가 어느 프로젝트인지 구분된다.
//   dataTable(caption, head, rows, { visibleCaption }) → <table>(첫 열은 행 머리). 가로가 넓으면 .table-scroll이 가로 스크롤.
//   setTableMode(section, on): 구역 안의 차트 상자(.chart-box)와 표([data-table], [data-table-part])를 바꾼다(버튼 포커스는 그대로).
import { el } from '../core/dom.js';

export function dataTable(caption, head, rows, { visibleCaption = false } = {}, doc = globalThis.document) {
  return el('table', { class: 'table table--compact' }, [
    el('caption', { class: visibleCaption ? '' : 'sr-only', text: caption }, [], doc),
    el('thead', {}, [el('tr', {}, head.map((h) => el('th', { scope: 'col', text: h }, [], doc)), doc)], doc),
    el('tbody', {}, rows.map((r) => el('tr', {}, r.map((c, i) => (i === 0 ? el('th', { scope: 'row' }, [c], doc) : el('td', {}, [c], doc))), doc)), doc),
  ], doc);
}

export function chartFigure(title, { chart = null, table = null, tableMode = false } = {}, doc = globalThis.document) {
  const fig = el('figure', { class: 'dash-figure' }, [el('figcaption', { text: title }, [], doc)], doc);
  if (chart) {
    const box = el('div', { class: 'chart-box' }, [chart], doc);
    box.hidden = tableMode;
    fig.appendChild(box);
  }
  if (table) {
    const part = el('div', { class: 'table-scroll', dataset: { tablePart: '' } }, [table], doc);
    part.hidden = !tableMode;
    fig.appendChild(part);
  }
  return fig;
}

export function setTableMode(section, on) {
  for (const c of section.querySelectorAll('.chart-box')) c.hidden = on;
  for (const t of section.querySelectorAll('[data-table], [data-table-part]')) t.hidden = !on;
}

/** 구역의 지금 모드(전환 버튼의 aria-pressed) */
export function isTableMode(section) {
  return section.querySelector('[data-toggle-table]')?.getAttribute('aria-pressed') === 'true';
}
