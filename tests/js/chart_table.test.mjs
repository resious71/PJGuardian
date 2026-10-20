// 차트 ↔ 표 공통(P21 0B): 표 모드에서 프로젝트마다 "제목 → 표"가 붙어 나오고, 보이는 제목이 있다. 값은 텍스트로만.
import test from 'node:test';
import assert from 'node:assert/strict';
import { chartFigure, dataTable } from '../../public/assets/js/ui/chart_table.js';
import { FakeDocument } from './fake-dom.mjs';

const doc = new FakeDocument();

/** 컨테이너의 보이는 순서(숨김 제외): 제목·표 이름 나열 */
function visibleOrder(node, out = []) {
  for (const c of node.childNodes) {
    if (c.nodeType !== 1 || c.hidden) continue;
    if (c.tagName === 'FIGCAPTION') out.push(`제목:${c.textContent}`);
    else if (c.tagName === 'TABLE') out.push(`표:${c.childNodes[0].textContent}`);
    else if (c.className === 'chart-box') out.push('차트');
    else visibleOrder(c, out);
  }
  return out;
}

test('표 모드: 프로젝트 3개 → 제목 → 표 → 제목 → 표 …(제목이 위에 몰리지 않음)', () => {
  const box = doc.createElement('div');
  for (const name of ['pandas', 'Chart.js', '<b>x</b>']) {
    const table = dataTable(name, ['Run', '결함'], [['Run 1', '3']], {}, doc);
    box.appendChild(chartFigure(name, { chart: doc.createElement('canvas'), table, tableMode: true }, doc));
  }
  assert.deepEqual(visibleOrder(box), ['제목:pandas', '표:pandas', '제목:Chart.js', '표:Chart.js', '제목:<b>x</b>', '표:<b>x</b>']);
  // 차트 모드
  const box2 = doc.createElement('div');
  for (const name of ['a', 'b']) box2.appendChild(chartFigure(name, { chart: doc.createElement('canvas'), table: dataTable(name, ['h'], [['v']], {}, doc) }, doc));
  assert.deepEqual(visibleOrder(box2), ['제목:a', '차트', '제목:b', '차트']);
});

test('표: 행 머리·열 머리, 값은 텍스트(HTML 해석 없음), 보이는 캡션 선택', () => {
  const t = dataTable('도구', ['이름', '수'], [['<img src=x onerror=1>', '2']], { visibleCaption: true }, doc);
  const [caption, thead, tbody] = t.childNodes;
  assert.equal(caption.className, '');
  assert.equal(thead.childNodes[0].childNodes[1].getAttribute('scope'), 'col');
  const row = tbody.childNodes[0];
  assert.equal(row.childNodes[0].tagName, 'TH');
  assert.equal(row.childNodes[0].textContent, '<img src=x onerror=1>');
  assert.ok(row.childNodes[0].childNodes.every((c) => c.nodeType === 3), '텍스트 노드만');
  assert.equal(dataTable('x', [], [], {}, doc).childNodes[0].className, 'sr-only', '기본 캡션은 화면 읽기용');
});
