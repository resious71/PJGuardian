'use strict';
/**
 * ESLint 규칙 목록과 meta(P21S 규칙 카탈로그, P22 규칙 설명). Services\Rules\RuleExtractor가 Node로 실행한다(네트워크 없음, 읽기만).
 *   node eslint_rules.cjs <bin/eslint/<버전> 폴더>
 * 출력(JSON): { "versions": {구성 요소: 버전}, "rules": { "<규칙 이름>": {
 *     group: meta.type, plugin: ""|"@typescript-eslint", deprecated: bool, recommended: bool, name: meta.docs.description,   ← P21S 카탈로그 필드
 *     defaultOn, url, fixable, hasSuggestions, hasOptions, deprecation: {message?, since?, replacedBy:[규칙…]} | null,             ← P22 추가
 *     requiresTypeChecking } } }                                                                                                  ← P22B 추가
 * 대상: ESLint 내장 규칙 + @typescript-eslint 플러그인(서버 flat config가 쓰는 것과 같은 패키지). 예시 코드는 meta에 없다.
 */
const path = require('node:path');
const { createRequire } = require('node:module');

const dir = path.resolve(process.argv[2] || '.');
const req = createRequire(path.join(dir, 'package.json'));
const { builtinRules } = req('eslint/use-at-your-own-risk');
const recommended = new Set(Object.keys(req('@eslint/js').configs.recommended.rules));
const versions = { eslint: req('eslint/package.json').version };

/** 폐기 정보: ESLint 9+는 객체({message, deprecatedSince, replacedBy:[{rule:{name}}]}), 8은 true + meta.replacedBy:[이름] */
function deprecation(meta, prefix) {
  if (!meta?.deprecated) return null;
  const d = typeof meta.deprecated === 'object' ? meta.deprecated : {};
  const raw = Array.isArray(d.replacedBy) ? d.replacedBy : (Array.isArray(meta.replacedBy) ? meta.replacedBy : []);
  const replacedBy = raw.map((r) => {
    if (typeof r === 'string') return prefix + r;
    if (r?.rule?.name && !r.plugin) return prefix + r.rule.name; // 같은 도구 안의 대체 규칙만(다른 플러그인 규칙은 이 도구에 없다)
    if (r?.rule?.name && r.plugin?.name === '@typescript-eslint/eslint-plugin') return `@typescript-eslint/${r.rule.name}`;
    return null;
  }).filter(Boolean);
  return { message: typeof d.message === 'string' ? d.message : null, since: d.deprecatedSince ?? null, replacedBy };
}

function entry(rule, plugin, isRecommended, defaultOn, prefix) {
  const meta = rule.meta ?? {};
  const schema = meta.schema;
  return {
    group: meta.type ?? null, plugin, deprecated: Boolean(meta.deprecated), recommended: isRecommended, name: meta.docs?.description ?? '',
    defaultOn, // 서버 기본 설정(eslint:recommended + @typescript-eslint/recommended)에서 켜짐(꺼짐 'off' 항목 제외)
    url: typeof meta.docs?.url === 'string' ? meta.docs.url : null,
    fixable: meta.fixable ?? null,
    hasSuggestions: Boolean(meta.hasSuggestions),
    hasOptions: schema === undefined ? null : (Array.isArray(schema) ? schema.length > 0 : schema !== false),
    requiresTypeChecking: Boolean(meta.docs?.requiresTypeChecking), // P22B: 타입 정보가 필요한 규칙(서버 설정에 타입 정보가 없어 켤 수 없음)
    deprecation: deprecation(meta, prefix),
  };
}

const rules = {};
for (const [name, rule] of builtinRules) {
  rules[name] = entry(rule, '', recommended.has(name), recommended.has(name), '');
}
let ts = null;
try {
  ts = req('@typescript-eslint/eslint-plugin');
  versions['@typescript-eslint/eslint-plugin'] = req('@typescript-eslint/eslint-plugin/package.json').version;
} catch {
  ts = null; // 플러그인이 없는 버전
}
if (ts) {
  const tsRec = ts.configs?.recommended?.rules ?? {};
  for (const [name, rule] of Object.entries(ts.rules)) {
    const key = `@typescript-eslint/${name}`;
    const level = tsRec[key];
    rules[key] = entry(rule, '@typescript-eslint', key in tsRec, level !== undefined && level !== 'off' && level !== 0, '@typescript-eslint/');
  }
}
process.stdout.write(JSON.stringify({ versions, rules }));
