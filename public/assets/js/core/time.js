// 시간 표시(명세 1): 서버는 UTC ISO 8601('2026-10-02T13:45:00Z')을 주고, 화면은 설정된 시간대로 보여 준다.
// 시간대는 서버가 <meta name="app-timezone">으로 전달한다(기본 Asia/Seoul).

export const DEFAULT_TIMEZONE = 'Asia/Seoul';

export function timezoneFromDocument(doc = globalThis.document) {
  return doc?.querySelector?.('meta[name="app-timezone"]')?.getAttribute('content') || DEFAULT_TIMEZONE;
}

/** UTC ISO 문자열을 Date로. UTC 표기(Z 또는 +00:00)가 없으면 거부한다(현지 시간으로 잘못 읽는 것 방지). */
export function parseUtc(iso) {
  if (typeof iso !== 'string' || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|\+00:00)$/.test(iso)) {
    throw new Error(`UTC ISO 8601 형식이 아님: ${iso}`);
  }
  return new Date(iso);
}

function parts(date, timeZone) {
  const fmt = new Intl.DateTimeFormat('en-CA', {
    timeZone, year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
  });
  return Object.fromEntries(fmt.formatToParts(date).filter((p) => p.type !== 'literal').map((p) => [p.type, p.value]));
}

/** 'YYYY-MM-DD HH:mm'(seconds=true면 ':ss'까지) */
export function formatDateTime(iso, timeZone = DEFAULT_TIMEZONE, { seconds = false } = {}) {
  const p = parts(parseUtc(iso), timeZone);
  return `${p.year}-${p.month}-${p.day} ${p.hour}:${p.minute}${seconds ? `:${p.second}` : ''}`;
}

export function formatDate(iso, timeZone = DEFAULT_TIMEZONE) {
  const p = parts(parseUtc(iso), timeZone);
  return `${p.year}-${p.month}-${p.day}`;
}

/** 'yy-mm-dd'(결함 표 "최근 감지", P21U). 날짜는 표시 시간대 기준(UTC 15:00 이후는 KST로 다음 날) */
export function formatShortDate(iso, timeZone = DEFAULT_TIMEZONE) {
  const p = parts(parseUtc(iso), timeZone);
  return `${p.year.slice(-2)}-${p.month}-${p.day}`;
}

/** <time datetime="UTC"> 요소들의 텍스트를 표시 시간대로 채운다 */
export function localizeTimes(root = globalThis.document, timeZone = timezoneFromDocument()) {
  for (const node of root.querySelectorAll('time[datetime]')) {
    try {
      node.textContent = formatDateTime(node.getAttribute('datetime'), timeZone, { seconds: node.dataset.seconds === 'true' });
      node.title = `${node.getAttribute('datetime')} (UTC)`;
    } catch {
      // 형식이 틀리면 서버가 넣은 텍스트를 그대로 둔다
    }
  }
}
