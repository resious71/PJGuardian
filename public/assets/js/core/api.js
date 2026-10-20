// API 호출 래퍼(명세 19.7, docs/architecture.md 4절)
// - 성공: {"data": ...} → data를 돌려줌(목록이면 { data, page }를 그대로)
// - 오류: {"error": {"code","message","request_id"}} → ApiError로 던짐
// - CSRF 토큰: <meta name="csrf-token">을 X-CSRF-Token 헤더로(상태를 바꾸는 요청, 명세 10)
// - 401 → onUnauthorized(기본: 로그인 화면으로 이동), 409 → onConflict 훅을 부른 뒤 ApiError
import { t } from './i18n.js';

export class ApiError extends Error {
  constructor({ status, code, message, requestId = null, details = null }) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.requestId = requestId;
    this.details = details;
  }
}

/** 응답 상태와 본문(이미 파싱한 JSON 또는 null)으로 ApiError를 만든다 */
export function parseError(status, body, headerRequestId = null) {
  const err = body && typeof body === 'object' && body.error && typeof body.error === 'object' ? body.error : null;
  const code = typeof err?.code === 'string' && err.code !== '' ? err.code : `http_${status}`;
  const message = typeof err?.message === 'string' && err.message !== '' ? err.message : t('error.unknown');
  const requestId = (typeof err?.request_id === 'string' && err.request_id) || headerRequestId || null;
  // error.details: 다시 시도에 필요한 공개 값(예: 409 version_conflict의 최신 결함 값, P18)
  const details = err?.details && typeof err.details === 'object' ? err.details : null;
  return new ApiError({ status, code, message, requestId, details });
}

const UNSAFE = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);

export function createApi({
  fetchImpl = globalThis.fetch?.bind(globalThis),
  doc = globalThis.document,
  base = null,
  onUnauthorized = null,
  onConflict = null,
} = {}) {
  const basePath = base ?? (doc?.querySelector?.('meta[name="app-base"]')?.getAttribute('content') || '');
  const csrf = () => doc?.querySelector?.('meta[name="csrf-token"]')?.getAttribute('content') || '';
  const goLogin = onUnauthorized ?? (() => globalThis.location?.assign(`${basePath}/login`));

  // raw: 원시 본문(ArrayBuffer·Blob, 청크 업로드 P12). headers: 덧붙일 요청 헤더
  async function request(method, path, { body, query, signal, raw, headers: extra } = {}) {
    const url = new URL(basePath + path, globalThis.location?.origin ?? 'http://localhost');
    for (const [k, v] of Object.entries(query ?? {})) if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, String(v));
    const headers = { Accept: 'application/json' };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (raw !== undefined) headers['Content-Type'] = 'application/octet-stream';
    Object.assign(headers, extra ?? {});
    if (UNSAFE.has(method)) headers['X-CSRF-Token'] = csrf();

    let res;
    try {
      res = await fetchImpl(url.pathname + url.search, {
        method, headers, credentials: 'same-origin', signal,
        body: raw !== undefined ? raw : (body === undefined ? undefined : JSON.stringify(body)),
      });
    } catch (e) {
      if (e?.name === 'AbortError') throw e;
      throw new ApiError({ status: 0, code: 'network', message: t('error.network') });
    }
    let json = null;
    const type = res.headers?.get?.('Content-Type') ?? '';
    if (type.includes('application/json')) {
      try { json = await res.json(); } catch { json = null; }
    }
    if (res.ok) {
      return json && Object.prototype.hasOwnProperty.call(json, 'page') ? json : json?.data;
    }
    const error = parseError(res.status, json, res.headers?.get?.('X-Request-Id') ?? null);
    if (res.status === 401) goLogin(error);
    if (res.status === 409 && onConflict) onConflict(error);
    throw error;
  }

  return {
    get: (path, opts) => request('GET', path, opts),
    post: (path, body, opts = {}) => request('POST', path, { ...opts, body }),
    put: (path, body, opts = {}) => request('PUT', path, { ...opts, body }),
    putRaw: (path, raw, opts = {}) => request('PUT', path, { ...opts, raw }),
    patch: (path, body, opts = {}) => request('PATCH', path, { ...opts, body }),
    delete: (path, opts) => request('DELETE', path, opts),
  };
}
