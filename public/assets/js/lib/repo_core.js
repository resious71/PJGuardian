// 소스 저장소 연동(P25)의 순수 함수: 유형별 입력 항목, 평문 경고, 연결 테스트 결과 문장, 출처 표시(짧은 해시·changelist),
// 커밋 링크 검사, "지금 가져와 분석" 버튼 상태, 저장 본문 만들기. DOM을 쓰지 않는다(Node 시험).
import { t as defaultT } from '../core/i18n.js';

export const KINDS = ['none', 'git', 'perforce'];

/** 유형별로 보이는 입력 항목 */
export function fieldsFor(kind) {
  if (kind === 'git') return ['url', 'branch', 'path', 'username', 'secret', 'fetch_timeout'];
  if (kind === 'perforce') return ['port', 'path', 'username', 'secret', 'secret_kind', 'fingerprint', 'fetch_timeout'];
  return [];
}

/** 평문 전송 경고: Git http:// 또는 Perforce 비SSL(ssl: 접두 없음) */
export function plaintextWarning(kind, value) {
  const v = String(value ?? '').trim().toLowerCase();
  if (kind === 'git') return v.startsWith('http://');
  if (kind === 'perforce') return v !== '' && !v.startsWith('ssl:');
  return false;
}

/** Perforce SSL 여부(지문 입력칸을 보일지) */
export function isSslPort(value) {
  return String(value ?? '').trim().toLowerCase().startsWith('ssl:');
}

/** 자격 증명 상태 문장(값은 절대 보이지 않는다) */
export function secretStatus(view, t = defaultT) {
  if (!view?.configured) return t('repo.secret_none');
  if (!view.secret_set) return t('repo.secret_none');
  if (view.secret_readable === false) return t('repo.secret_unreadable');
  return t('repo.secret_set', { kind: t(`repo.secret_kind.${view.secret_kind ?? 'token'}`) });
}

/** 화면 폼 → PUT 본문. secret을 비우면 보내지 않는다(서버가 유지) */
export function buildBody(form, revision) {
  const body = { kind: form.kind };
  for (const f of fieldsFor(form.kind)) {
    if (f === 'secret') continue;
    const v = form[f];
    if (f === 'fetch_timeout') {
      if (v !== '' && v !== null && v !== undefined) body.fetch_timeout = Number(v) * 60;
      continue;
    }
    if (typeof v === 'string') body[f] = v.trim() === '' ? null : v.trim();
  }
  if (form.secret) body.secret = form.secret;
  if (form.clear_secret) body.clear_secret = true;
  if (revision) body.revision = revision;
  return body;
}

const short = (rev) => (typeof rev === 'string' && /^[0-9a-f]{7,64}$/.test(rev) ? rev.slice(0, 7) : null);

/** 출처 한 줄: 업로드는 파일 이름, Git은 브랜치 @ 짧은 해시, Perforce는 경로 @ changelist */
export function sourceText(source, t = defaultT) {
  if (!source || source.kind === 'upload') return source?.file_name ?? t('repo.kind.none');
  if (source.kind === 'git') {
    const h = short(source.revision);
    return [source.ref ?? '', h ? `@${h}` : ''].filter(Boolean).join(' ') || t('repo.kind.git');
  }
  if (source.kind === 'perforce') {
    return [source.ref ?? '', source.revision ? `@${source.revision}` : ''].filter(Boolean).join(' ') || t('repo.kind.perforce');
  }
  return '';
}

/** 서버가 준 커밋 링크만 쓴다(https/http + /commit/<해시>). 그 밖은 null */
export function safeCommitLink(link) {
  if (typeof link !== 'string') return null;
  return /^https?:\/\/[^\s/@]+(:\d+)?\/[A-Za-z0-9._-]+\/[A-Za-z0-9._-]+\/commit\/[0-9a-f]{7,64}$/.test(link) ? link : null;
}

/** 연결 테스트 결과 → 문장(성공: 브랜치·커밋 또는 changelist, 실패: 오류 코드별 안내 + 지문) */
export function testResultText(r, t = defaultT) {
  if (!r) return '';
  if (r.ok) {
    return r.change !== undefined
      ? t('repo.test_ok_p4', { change: r.change, seconds: r.seconds ?? 0 })
      : t('repo.test_ok_git', { branch: r.branch ?? '', commit: short(r.commit) ?? '', seconds: r.seconds ?? 0 });
  }
  const base = t(`repo.test_fail.${r.code}`) === `repo.test_fail.${r.code}` ? t('repo.test_fail.other', { code: r.code ?? '' }) : t(`repo.test_fail.${r.code}`);
  return r.fingerprint ? `${base} ${t('repo.test_fingerprint', { fp: r.fingerprint })}` : base;
}

/** 연결 상태 배지 키 */
export function statusKey(status) {
  return ({ ok: 'repo.status.ok', error: 'repo.status.error' })[status] ?? 'repo.status.unknown';
}

/**
 * "지금 가져와 분석" 버튼: 연결이 저장돼 있고(허용 호스트에 있고, 자격 증명을 풀 수 있고) 진행 중 분석이 없을 때만.
 * @returns {{ enabled: boolean, reason: string|null }} reason은 문자열 키
 */
export function fetchButton(view, { busy = false, pending = false } = {}) {
  if (!view?.configured) return { enabled: false, reason: 'repo.fetch_reason.none' };
  if (view.host_allowed === false) return { enabled: false, reason: 'repo.fetch_reason.host' };
  if (view.secret_readable === false) return { enabled: false, reason: 'repo.fetch_reason.secret' };
  if (busy) return { enabled: false, reason: 'repo.fetch_reason.busy' };
  if (pending) return { enabled: false, reason: 'repo.fetch_reason.pending' };
  return { enabled: true, reason: null };
}

/**
 * "지금 가져와 분석" 뒤 분석 하나를 지켜볼 때의 판단(P27). 워커가 가져오기 전에 변경 여부를 확인한다.
 *   'wait'      아직 대기·확인 중(queued, running인데 가져오기 전)
 *   'unchanged' 성공(변경 없음) — 분석을 생략했다 → "그래도 분석할까요?" 확인
 *   'analyzing' 가져오기를 마쳐 실제로 분석 중(변경 있음) → 지켜보기 끝
 *   'done'      그 밖에 끝남(성공·실패·취소) → 지켜보기 끝
 */
export function fetchWatchState(a) {
  if (!a) return 'done';
  if (a.status === 'succeeded' && a.unchanged) return 'unchanged';
  if (a.status === 'queued') return 'wait';
  if (a.status === 'running') return a.fetched_at ? 'analyzing' : 'wait';
  return 'done';
}

/** 마지막 확인 줄: "2026-10-08 19:05 · 변경 없음" / 없으면 "—" */
export function lastCheckText(view, fmt, t = defaultT) {
  const c = view?.last_check;
  if (!c?.at) return '—';
  return t('repo.last_check_value', { at: fmt(c.at), result: t(`repo.check_result.${c.result ?? 'changed'}`) });
}
