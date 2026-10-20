// 차트 공통 모양(P21U). 대시보드(추이·규칙/파일 상위)와 히스토리 차트가 함께 쓴다(범례·막대 규칙을 한 곳에서).
//   legendOptions(textColor): 범례 — 표식(점 모양) 크기를 글자 크기 안으로, 항목 간격도 글자 크기에 맞춘다.
//     Chart.js 4는 usePointStyle일 때 표식 반지름을 boxHeight × √2 / 2로 그린다 → 표식 전체 크기 = boxHeight × √2(legendMarkSize).
//     범례 항목을 눌러 시리즈를 켜고 끄는 기본 동작은 그대로다(onClick을 바꾸지 않음).
//   barStyle(color): 상위 10 막대 — 파스텔 채움(--chart-bar) + 진한 테두리(--chart-bar-border, 배경과 3:1 이상).
//   barValueLabels: 막대 끝에 건수를 글자로(색에만 의존하지 않게). 캔버스에 글자로 그려 HTML로 해석되지 않는다.
export const LEGEND_FONT_SIZE = 12;
export const LEGEND_BOX = 8;

/** 범례 표식의 실제 크기(px) */
export function legendMarkSize(labels) {
  return labels.boxHeight * Math.SQRT2;
}

export function legendOptions(textColor) {
  return {
    labels: {
      usePointStyle: true,
      boxWidth: LEGEND_BOX,
      boxHeight: LEGEND_BOX,
      padding: LEGEND_FONT_SIZE,
      color: textColor,
      font: { size: LEGEND_FONT_SIZE },
      // 시리즈 선 굵기(예: "전체" 3px)가 범례 표식 테두리로 그대로 쓰이면 표식이 글자보다 커진다 → 범례에서는 1px
      generateLabels: (chart) => chart.constructor.defaults.plugins.legend.labels.generateLabels(chart).map((item) => ({ ...item, lineWidth: Math.min(item.lineWidth ?? 1, 1) })),
    },
  };
}

/** @param {(name:string)=>string} color --chart-<name> 값 */
export function barStyle(color) {
  return { backgroundColor: color('bar'), borderColor: color('bar-border'), borderWidth: 1.5, hoverBackgroundColor: color('bar-border') };
}

/** 막대 끝 건수 글자(가로 막대). 글자가 막대 밖(배경 위)에 놓이도록 오른쪽 여백(layout.padding.right)을 함께 둔다 */
export function barValueLabels(textColor) {
  return {
    id: 'pjgBarValues',
    afterDatasetsDraw(chart) {
      const { ctx } = chart;
      ctx.save();
      ctx.fillStyle = textColor;
      ctx.font = `${LEGEND_FONT_SIZE}px ${getComputedStyle(chart.canvas).fontFamily}`;
      ctx.textBaseline = 'middle';
      chart.data.datasets.forEach((ds, i) => {
        const meta = chart.getDatasetMeta(i);
        if (meta.hidden) return;
        meta.data.forEach((bar, j) => {
          const v = ds.data[j];
          if (v === null || v === undefined) return;
          ctx.fillText(Number(v).toLocaleString('ko-KR'), bar.x + 4, bar.y);
        });
      });
      ctx.restore();
    },
  };
}

export const BAR_VALUE_PADDING = 48;
