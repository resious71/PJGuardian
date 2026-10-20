'use strict';
/**
 * PJGuardian 서버 ESLint 설정 + 관리자 규칙 변경(P22B). 관리자 변경이 있을 때만 `eslint -c <이 파일>`로 넘긴다(EslintRunner).
 * 기본 설정(pjg.flat.config.cjs)을 그대로 읽고 맨 뒤에 규칙 변경을 얹는다 — 기본 설정의 TS·테스트 파일 overrides보다 뒤라서 같은 규칙을
 * 켜거나 끄면 이쪽이 이긴다. 기본 설정 파일을 고치지 않는 이유: 그 내용은 설정 해시에 들어가므로 고치면 변경이 없는 서버의 모든 ESLint 분석이
 * "설정 변경"으로 보인다.
 *
 * 입력: PJG_ESLINT_OPTIONS JSON의 rules = { "<규칙>": "error" | "off" }(서버가 활성 버전에 있고 켤 수 있는 규칙만 넣는다).
 * 안전망: 이 ESLint에 없는 규칙, 타입 정보가 필요한 규칙 켜기는 건너뛴다(설정 오류로 분석이 실패하지 않게).
 * 코어 규칙은 모든 대상 파일에, @typescript-eslint 규칙은 그 플러그인을 등록한 파일 설정(TS 파일)에만 얹는다.
 */
const fs = require('node:fs');
const path = require('node:path');
const { createRequire } = require('node:module');

const base = require('./pjg.flat.config.cjs');
const options = JSON.parse(fs.readFileSync(process.env.PJG_ESLINT_OPTIONS, 'utf8'));
const req = createRequire(path.join(options.eslint_dir, 'package.json'));
const { builtinRules } = req('eslint/use-at-your-own-risk');
let ts = null;
try {
  ts = req('@typescript-eslint/eslint-plugin');
} catch {
  ts = null; // 플러그인이 없는 버전
}
const TS = '@typescript-eslint/';
const core = {};
const tsRules = {};
for (const [name, level] of Object.entries(options.rules ?? {})) {
  if (level !== 'off' && level !== 'error') continue;
  if (name.startsWith(TS)) {
    const rule = ts?.rules?.[name.slice(TS.length)];
    if (!rule || (level === 'error' && rule.meta?.docs?.requiresTypeChecking)) continue;
    tsRules[name] = level;
  } else if (builtinRules.has(name)) {
    core[name] = level;
  }
}

const extra = [];
if (Object.keys(core).length) extra.push({ rules: core });
if (Object.keys(tsRules).length) {
  const seen = new Set();
  for (const cfg of base) {
    if (!cfg.plugins?.['@typescript-eslint'] || !Array.isArray(cfg.files)) continue;
    const key = JSON.stringify(cfg.files);
    if (seen.has(key)) continue;
    seen.add(key);
    extra.push({ files: cfg.files, rules: tsRules });
  }
}
module.exports = [...base, ...extra];
