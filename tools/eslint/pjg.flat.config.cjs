'use strict';
/**
 * PJGuardian 서버 ESLint 설정(flat config, 명세 5.3, 19.10). 서버가 작성한 파일이며 `eslint -c <이 파일>`로 넘긴다.
 * -c로 주면 ESLint는 분석 대상의 eslint.config.*를 찾지 않는다. 업로드된 ESLint 설정은 읽지도 실행하지도 않는다.
 *
 * 입력: 환경 변수 PJG_ESLINT_OPTIONS = 서버(PHP, EslintRunner)가 만든 JSON 파일 경로
 *   { "eslint_dir": "<bin/eslint/<버전>>", "base_config": "<config/eslint.default.json>",
 *     "files": ["**\/*.js", ...], "ignores": ["**\/node_modules/**", ...] }
 * 기본 규칙은 config/eslint.default.json(eslintrc 형식: eslint:recommended + @typescript-eslint/recommended)을
 * FlatCompat으로 바꿔 쓴다(P03에서 결과 일치 확인). TS parser·plugin은 bin/eslint/<버전>의 것을 쓴다.
 */
const fs = require('node:fs');
const path = require('node:path');
const { createRequire } = require('node:module');

const optionsPath = process.env.PJG_ESLINT_OPTIONS;
if (!optionsPath) {
  throw new Error('PJG_ESLINT_OPTIONS 환경 변수가 필요함');
}
const options = JSON.parse(fs.readFileSync(optionsPath, 'utf8'));
const req = createRequire(path.join(options.eslint_dir, 'package.json'));
const { FlatCompat } = req('@eslint/eslintrc');
const js = req('@eslint/js');

// parser 이름을 bin/eslint/<버전>의 절대 경로로 바꾼다(분석 대상 폴더 기준으로 찾지 않게. 업로드 쪽 node_modules를 쓰지 않음)
function resolveParsers(cfg) {
  const out = { ...cfg };
  if (typeof out.parser === 'string') out.parser = req.resolve(out.parser);
  if (Array.isArray(out.overrides)) out.overrides = out.overrides.map(resolveParsers);
  return out;
}
const base = resolveParsers(JSON.parse(fs.readFileSync(options.base_config, 'utf8')));
const compat = new FlatCompat({
  baseDirectory: process.cwd(),
  resolvePluginsRelativeTo: options.eslint_dir,
  recommendedConfig: js.configs.recommended,
  allConfig: js.configs.all,
});

module.exports = [
  // 대상 확장자(설정 upload.target_extensions_javascript)만. flat 기본 대상(.mjs, .cjs)은 ignores로 뺀다
  { files: options.files },
  { ignores: options.ignores },
  // 규칙 없는 "사용하지 않는 eslint-disable" 메시지는 결함으로 만들지 않는다(ESLint 9+ 기본값은 warn)
  { linterOptions: { reportUnusedDisableDirectives: 'off' } },
  ...compat.config(base),
];
