// 분석 화면의 순수 계산(P19, 명세 5.2·5.3·8·15.2). DOM 없이 시험한다(tests/js/analyses.test.mjs).
// 분석 API(GET /api/v1/analyses/{id}, 프로젝트 분석 목록) 응답을 화면 문구 키·링크로 바꾼다.

export const ACTIVE = ['queued', 'running'];

export function isActive(a) {
  return ACTIVE.includes(a?.status);
}

/** 초 → { h, m, s } (표시는 화면이 문자열 리소스로) */
export function durationParts(seconds) {
  if (seconds == null || seconds < 0) return null;
  const s = Math.floor(seconds);
  return { h: Math.floor(s / 3600), m: Math.floor((s % 3600) / 60), s: s % 60 };
}

/** 소요 시간 문구 키와 값: 1시간 이상이면 시·분, 1분 이상이면 분·초, 아니면 초 */
export function durationKey(seconds) {
  const p = durationParts(seconds);
  if (!p) return null;
  if (p.h > 0) return { key: 'analyses.duration_hm', params: { h: p.h, m: p.m } };
  if (p.m > 0) return { key: 'analyses.duration_ms', params: { m: p.m, s: p.s } };
  return { key: 'analyses.duration_s', params: { s: p.s } };
}

/**
 * 분석 상세·목록 위에 보여 줄 안내 목록 [{ level: 'info'|'warn', key, params }].
 * - 취소됨/취소 요청 중(결함과 비교 기준에 영향 없음, 명세 5.4)
 * - 설정 무시됨(명세 5.3, 15.2): ESLint 업로드 설정 무시, ruff 하위 폴더 설정 무시, ruff 설정 거부, .eslintignore 변환
 * - 확인하고 진행한 업로드 경고(최상위 폴더 불일치), 대상 파일이 없어 결함이 모두 해결된 경우
 */
export function analysisNotices(a) {
  const out = [];
  if (!a) return out;
  if (a.status === 'cancelled') out.push({ level: 'info', key: 'analyses.notice.cancelled', params: {} });
  else if (isActive(a) && a.cancel_requested) out.push({ level: 'info', key: 'analyses.notice.cancel_requested', params: {} });

  const groups = {};
  for (const c of a.ignored_configs ?? []) (groups[c.reason] ??= []).push(c);
  const files = (list) => list.map((c) => (c.count ? `${c.file === '…' ? '' : `${c.file} `}+${c.count}` : c.file)).filter(Boolean).join(', ');
  if (groups.eslint_config_ignored) {
    out.push({ level: 'warn', key: 'analyses.notice.eslint_config_ignored', params: { files: files(groups.eslint_config_ignored) } });
  }
  if (groups.ruff_nested_config) {
    out.push({ level: 'warn', key: 'analyses.notice.ruff_nested_config', params: { files: files(groups.ruff_nested_config) } });
  }
  if (groups.ruff_config_rejected) {
    const c = groups.ruff_config_rejected[0];
    out.push({ level: 'warn', key: 'analyses.notice.ruff_config_rejected', params: { file: c.file, detail: c.detail ?? '' } });
  }
  if (groups.eslintignore_converted) {
    out.push({ level: 'info', key: 'analyses.notice.eslintignore_converted', params: { count: groups.eslintignore_converted[0].count ?? 0 } });
  }

  // P26B: 저장소에서 가져온 소스의 심볼릭 링크 건너뜀(개수와 처음 20개 경로 — 링크 대상은 저장하지 않음)
  for (const w of a.source_warnings ?? []) {
    if (w?.code === 'symlinks_skipped' && Number(w.count) > 0) {
      const paths = (Array.isArray(w.paths) ? w.paths : []).filter((x) => typeof x === 'string');
      const more = Number(w.count) - paths.length;
      out.push({ level: 'warn', key: more > 0 ? 'analyses.notice.symlinks_skipped_more' : 'analyses.notice.symlinks_skipped',
        params: { n: Number(w.count).toLocaleString('ko-KR'), paths: paths.join(', '), more: more.toLocaleString('ko-KR') } });
    }
  }

  const warnings = a.upload?.confirmed_at ? a.upload.warnings ?? [] : [];
  if (warnings.includes('top_folder_missing') || warnings.includes('top_folder_kept')) {
    out.push({ level: 'warn', key: 'analyses.notice.top_folder_confirmed', params: {} });
  }
  if (warnings.includes('no_target')) {
    out.push({
      level: 'warn',
      key: a.status === 'succeeded' ? 'analyses.notice.no_target_resolved' : 'analyses.notice.no_target_confirmed',
      params: { resolved: a.counts?.resolved ?? 0 },
    });
  }
  return out;
}

/** 결과 요약의 신규·해결·재오픈 건수 → 결함 목록(프로젝트 결함 탭) 필터 주소 */
export function changeLink(base, projectId, analysisId, change) {
  return `${base}/projects/${projectId}?analysis=${analysisId}&change=${change}`;
}

/** 비교 대상 문구 키: 최초 분석이면 "비교 대상 없음", 아니면 직전 성공 분석 */
export function baselineKey(a) {
  if (a?.status !== 'succeeded') return null;
  if (a.first_analysis || !a.baseline) return { key: 'analyses.baseline_none', params: {} };
  // P20R: 비교 대상 Run 번호가 있으면 "Run N(분석 #id)"
  if (Number.isInteger(a.baseline.run)) return { key: 'analyses.baseline_of_run', params: { id: a.baseline.id, run: a.baseline.run } };
  return { key: 'analyses.baseline_of', params: { id: a.baseline.id } };
}

/**
 * 분석의 이름(P20R 용어): 성공 = "Run N · 분석 #id", 그 밖(대기·실행 중·실패·취소, 되채움 전) = "Run —(상태) · 분석 #id" / "분석 #id"
 * @returns {{key: string, params: object}[]} 이어 붙일 조각들(문자열 리소스 키)
 */
export function analysisTitle(a) {
  if (Number.isInteger(a?.run)) return [{ key: 'runs.title_of', params: { run: a.run, id: a.id } }];
  // P27 성공(변경 없음): Run이 아니다 → "Run —(성공(변경 없음)) · 분석 #id"
  if (a?.unchanged) return [{ key: 'runs.none_status', params: { status: 'icon.analysis_status.unchanged' } }, { key: 'analyses.title_of', params: { id: a.id } }];
  if (a?.status && a.status !== 'succeeded') {
    return [{ key: 'runs.none_status', params: { status: `icon.analysis_status.${a.status}` } }, { key: 'analyses.title_of', params: { id: a.id } }];
  }
  return [{ key: 'analyses.title_of', params: { id: a?.id } }];
}
