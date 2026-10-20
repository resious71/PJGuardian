// 주기적 조회(분석 상태, 알림 등: 명세 5.2, 17)
// - 탭이 숨겨지면 멈추고, 다시 보이면 곧바로 한 번 조회한 뒤 이어 간다.
// - 실패하면 간격을 지수적으로 늘린다(최대 maxInterval). 성공하면 원래 간격으로 돌아간다.
// - task가 false를 돌려주면 멈춘다(예: 분석이 끝남).

/** 연속 실패 횟수에 따른 다음 대기 시간(ms) */
export function nextDelay(interval, failures, maxInterval) {
  if (failures <= 0) return interval;
  return Math.min(maxInterval, interval * 2 ** Math.min(failures, 16));
}

export function createPoller(task, {
  interval = 5000,
  maxInterval = 60000,
  doc = globalThis.document,
  setTimer = (fn, ms) => globalThis.setTimeout(fn, ms),
  clearTimer = (id) => globalThis.clearTimeout(id),
} = {}) {
  let timer = null;
  let running = false;
  let failures = 0;
  let inFlight = false;

  const hidden = () => doc?.visibilityState === 'hidden';

  function schedule(ms) {
    clearTimer(timer);
    timer = running && !hidden() ? setTimer(tick, ms) : null;
  }

  async function tick() {
    timer = null;
    if (!running || hidden() || inFlight) return;
    inFlight = true;
    try {
      const keepGoing = await task();
      failures = 0;
      if (keepGoing === false) {
        running = false;
        return;
      }
    } catch {
      failures += 1;
    } finally {
      inFlight = false;
    }
    schedule(nextDelay(interval, failures, maxInterval));
  }

  function onVisibility() {
    if (!running) return;
    if (hidden()) {
      clearTimer(timer);
      timer = null;
    } else {
      schedule(0);
    }
  }

  return {
    start({ immediate = true } = {}) {
      if (running) return;
      running = true;
      doc?.addEventListener?.('visibilitychange', onVisibility);
      schedule(immediate ? 0 : interval);
    },
    stop() {
      running = false;
      clearTimer(timer);
      timer = null;
      doc?.removeEventListener?.('visibilitychange', onVisibility);
    },
    get failures() { return failures; },
    get running() { return running; },
  };
}
