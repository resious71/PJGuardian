// 대시보드(P20)에 필요한 것만 등록한다(선·막대 차트, 범주·선형 축, 툴팁·범례). 트리 셰이킹으로 나머지(파이·레이더 등)는 빠진다.
import {
  Chart, LineController, BarController, CategoryScale, LinearScale, PointElement, LineElement, BarElement, Tooltip, Legend,
} from 'chart.js';

Chart.register(LineController, BarController, CategoryScale, LinearScale, PointElement, LineElement, BarElement, Tooltip, Legend);

export { Chart };
