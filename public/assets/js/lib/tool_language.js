// 분석 언어 ↔ 분석 도구 대응(X08, 화면 쪽 한 곳). 서버는 src/Support/ToolLanguage.php(같은 값, 시험이 일치를 검사).
export const TOOL_BY_LANGUAGE = Object.freeze({ python: 'ruff', javascript: 'eslint' });
export const LANGUAGES = Object.freeze(Object.keys(TOOL_BY_LANGUAGE));

/** 언어 → 도구(모르는 언어는 null) */
export function toolOf(language) {
  return Object.hasOwn(TOOL_BY_LANGUAGE, language) ? TOOL_BY_LANGUAGE[language] : null;
}

/** 도구 → 언어(모르는 도구는 null) */
export function languageOf(tool) {
  return LANGUAGES.find((l) => TOOL_BY_LANGUAGE[l] === tool) ?? null;
}
