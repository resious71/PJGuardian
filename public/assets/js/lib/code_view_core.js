// 코드 뷰어의 순수 계산(P17, 명세 7). CodeMirror 없이 시험한다(tests/js/defects.test.mjs).
// 코드 API(GET /api/v1/defects/{id}/code) 응답: { source: snapshot|excerpt|none, start_line, end_line, total_lines, partial, excerpt,
//   lines: [텍스트 줄], truncated_lines, content_replaced, reason, highlight: { line, column, end_line, end_column }, file_path }

/** 확장자 → 하이라이팅 언어. 모르는 확장자는 null(일반 텍스트) */
export function languageForPath(path) {
  const m = /\.([A-Za-z0-9]+)$/.exec(String(path ?? ''));
  const ext = m ? m[1].toLowerCase() : '';
  if (ext === 'py' || ext === 'pyi') return 'python';
  if (ext === 'js' || ext === 'mjs' || ext === 'cjs') return 'javascript';
  if (ext === 'jsx') return 'jsx';
  if (ext === 'ts' || ext === 'mts' || ext === 'cts') return 'typescript';
  if (ext === 'tsx') return 'tsx';
  return null;
}

/**
 * 결함 위치 → 문서 안 위치(1부터, 돌려받은 줄 범위 기준).
 * 반환: { fromLine, toLine, fromCol, toCol } — 줄은 문서의 줄 번호, 열은 0부터(없으면 null). 결함 줄이 범위 밖이면 null.
 * 열은 도구 기준 1부터(ruff·ESLint)이고 0이 오면 줄 처음으로 본다.
 */
export function defectRange(data) {
  const h = data?.highlight;
  if (!h || data.start_line == null || !Array.isArray(data.lines) || data.lines.length === 0) return null;
  const first = data.start_line;
  const last = first + data.lines.length - 1;
  const line = h.line;
  const endLine = Math.max(line, h.end_line ?? line);
  if (endLine < first || line > last) return null;
  const fromLine = Math.max(line, first) - first + 1;
  const toLine = Math.min(endLine, last) - first + 1;
  const sameStart = line >= first;
  const sameEnd = endLine <= last;
  const fromCol = sameStart && h.column != null ? Math.max(0, h.column - 1) : null;
  const toCol = sameEnd && h.end_column != null ? Math.max(0, h.end_column - 1) : null;
  return { fromLine, toLine, fromCol, toCol };
}

/** 화면 줄 번호(문서 n번째 줄 → 원본 파일의 줄 번호) */
export function displayLineNumber(n, startLine) {
  return n + (startLine ?? 1) - 1;
}

/**
 * 뷰어 위에 보여 줄 안내 목록: [{ level: 'warn'|'info', key, params }]
 * - 발췌본(명세 7: 발췌임을 표시, 범위 밖은 보여 주지 않음), 인코딩 대체, 일부만 표시, 긴 줄 자름
 */
export function noticesFor(data, { excerptContext = 20, mode = 'window' } = {}) {
  const out = [];
  if (!data || data.source === 'none') return out;
  if (data.excerpt) {
    out.push({ level: 'warn', key: 'defects.code_excerpt', params: { n: excerptContext, start: data.start_line, end: data.end_line } });
  }
  if (data.content_replaced) out.push({ level: 'warn', key: 'defects.code_replaced', params: {} });
  if (!data.excerpt && data.partial) {
    out.push({
      level: 'info',
      key: mode === 'full' ? 'defects.code_partial_too_long' : 'defects.code_partial',
      params: { start: data.start_line, end: data.end_line, total: data.total_lines },
    });
  }
  if (data.truncated_lines?.length) {
    out.push({ level: 'info', key: 'defects.code_truncated', params: { count: data.truncated_lines.length, max: data.max_line_length } });
  }
  return out;
}
