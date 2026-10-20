// 허용 호스트 연결 테스트(P26B)의 순수 함수: 결과 코드 → 문구·아이콘·해결 안내, 단계별 체크 목록, 마지막 테스트·인증서 만료 칸.
// DOM을 쓰지 않는다(Node 시험). 화면은 글자 + 아이콘(색에만 의존하지 않음).
import { t as defaultT } from '../core/i18n.js';

export const CODES = ['ok', 'warn_cert_expiring', 'fail_dns', 'fail_blocked_address', 'fail_connect', 'fail_tls_untrusted', 'fail_tls_expired',
  'fail_tls_name_mismatch', 'fail_http_status'];

/** 결과 코드 → { level: ok|warn|error, icon } */
export function codeLevel(code) {
  if (code === 'ok') return { level: 'ok', icon: 'system.ok' };
  if (code === 'warn_cert_expiring') return { level: 'warn', icon: 'system.warn' };
  return CODES.includes(code) ? { level: 'error', icon: 'system.error' } : { level: 'neutral', icon: 'ui.info' };
}

/** 결과 코드 문구 키 */
export function codeKey(code) {
  return CODES.includes(code) ? `host_test.code.${code}` : 'host_test.code.unknown';
}

/** 해결 안내 문구 키(ok면 null) */
export function hintKey(code) {
  return code && code !== 'ok' && CODES.includes(code) ? `host_test.hint.${code}` : null;
}

const CLASSES = ['public', 'private', 'loopback', 'blocked'];

/**
 * 단계별 체크 목록. 각 항목 { state: ok|warn|fail|info, text }.
 * @param {object} r API 결과 { code, dns, targets, cert }
 */
export function steps(r, fmtDate = (s) => s, t = defaultT) {
  const out = [];
  if (!r) return out;
  const ips = (r.dns?.ips ?? []).map((a) => `${a.ip}(${t(`host_test.class.${CLASSES.includes(a.class) ? a.class : 'blocked'}`)})`).join(', ');
  if (r.code === 'fail_dns') {
    out.push({ state: 'fail', text: t('host_test.step.dns_fail') });
    return out;
  }
  out.push({ state: r.dns?.ok ? 'ok' : 'fail', text: t(r.dns?.ok ? 'host_test.step.dns_ok' : 'host_test.step.dns_blocked', { ips }) });
  if (!r.dns?.ok) return out;
  for (const g of r.targets ?? []) {
    const port = g.port;
    if (!g.tcp?.ok) {
      out.push({ state: 'fail', text: t(g.tcp?.error === 'timeout' ? 'host_test.step.tcp_timeout' : 'host_test.step.tcp_refused', { port }) });
      continue;
    }
    out.push({ state: 'ok', text: t('host_test.step.tcp_ok', { port, ms: g.tcp.ms }) });
    if (g.plaintext) {
      // P19C: 평문 HTTP 상태 줄을 실제로 확인했으면 "평문 HTTP로 확인", 아니면(Perforce 등 비HTTP) TCP 접속만
      out.push({ state: 'info', text: t(Number.isInteger(g.http?.status) ? 'host_test.step.plaintext_http' : 'host_test.step.plaintext', { port }) });
    }
    if (g.tls && !g.tls.ok) {
      out.push({ state: 'fail', text: t(g.tls.error === 'timeout' ? 'host_test.step.tls_timeout' : 'host_test.step.tls_fail', { port }) });
    }
    const c = g.tls?.cert;
    if (c) {
      const until = { date: fmtDate(c.not_after), days: c.days_left, issuer: c.issuer || '—' };
      out.push({ state: c.expired ? 'fail' : (c.expiring ? 'warn' : 'ok'), text: t(c.expired ? 'host_test.step.cert_expired' : 'host_test.step.cert_valid', until) });
      out.push({ state: c.name_match ? 'ok' : 'fail', text: t(c.name_match ? 'host_test.step.name_ok' : 'host_test.step.name_mismatch', { names: (c.names ?? []).join(', ') || '—' }) });
      out.push({ state: g.tls.trusted ? 'ok' : 'fail', text: t(g.tls.trusted ? 'host_test.step.trusted' : 'host_test.step.untrusted') });
      if (g.p4) {
        out.push({ state: 'info', text: t('host_test.step.fingerprint', { sha1: c.key_sha1 || '—', sha256: c.fingerprint_sha256 || '—' }) });
      }
    }
    if (g.http) {
      if (g.http.status === null || g.http.status === undefined) {
        out.push({ state: 'fail', text: t('host_test.step.http_none', { port }) });
      } else {
        const s = g.http.status;
        // P27C: 4xx(407 제외)도 도달 성공 — 401·403 = 인증 필요, 그 밖 = 루트에 페이지 없음. 407 = 프록시 인증(오류). 서버의 note가 없으면(예전 결과) 상태로 판단
        const note = g.http.note ?? ((s === 401 || s === 403) ? 'auth' : (s === 407 ? 'proxy' : null));
        const key = note === 'auth' ? 'host_test.step.http_auth' : note === 'no_page' ? 'host_test.step.http_no_page' : note === 'proxy' ? 'host_test.step.http_proxy'
          : (g.http.redirect ? 'host_test.step.http_redirect' : (g.http.ok ? 'host_test.step.http_ok' : 'host_test.step.http_bad'));
        out.push({ state: g.http.ok ? 'ok' : 'fail', text: t(key, { status: s, method: g.http.method }) });
      }
    }
  }
  return out;
}

/** 표의 "마지막 테스트" 칸: { level, icon, text } 또는 아직 없음 */
export function lastTestCell(last, fmt = (s) => s, t = defaultT) {
  if (!last?.at) return { level: 'neutral', icon: 'ui.info', text: t('host_test.never') };
  const lv = codeLevel(last.code);
  return { ...lv, text: `${t(codeKey(last.code))} · ${fmt(last.at)}` };
}

/** 표의 "인증서 만료" 칸: { text, warn } (없으면 —) */
export function certCell(cert, fmtDate = (s) => s, t = defaultT) {
  if (!cert?.not_after) return { text: '—', warn: false };
  if (cert.expired) return { text: t('host_test.cert_expired_cell', { date: fmtDate(cert.not_after) }), warn: true };
  return { text: t('host_test.cert_cell', { date: fmtDate(cert.not_after), days: cert.days_left }), warn: Boolean(cert.expiring) };
}
