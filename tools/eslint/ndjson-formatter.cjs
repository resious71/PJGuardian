'use strict';
/**
 * PJGuardian ESLint 포매터(서버 작성, `--format <이 파일>`): 메시지 하나를 JSON 한 줄로 출력한다(NDJSON).
 * PHP(ToolOutputParser::eslint)가 한 줄씩 읽어 결함 10만 건도 메모리에 다 올리지 않고 처리한다.
 * 필드: filePath, ruleId, fatal, severity, line, column, endLine, endColumn, message (그 밖의 필드는 넣지 않음)
 * 분석한 파일마다 {"kind":"file","filePath":…} 한 줄을 먼저 낸다(분석 범위, P16: 결함이 없는 파일도 포함)
 */
module.exports = function ndjson(results) {
  const out = [];
  for (const r of results) {
    out.push(JSON.stringify({ kind: 'file', filePath: r.filePath }));
    for (const m of r.messages) {
      out.push(JSON.stringify({
        filePath: r.filePath,
        ruleId: m.ruleId ?? null,
        fatal: m.fatal === true,
        severity: m.severity,
        line: m.line,
        column: m.column,
        endLine: m.endLine,
        endColumn: m.endColumn,
        message: m.message,
      }));
    }
  }
  return out.length ? out.join('\n') + '\n' : '';
};
