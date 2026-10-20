// 규칙 쉬운 설명(P32B) 검증 — resources/rules/enrichment/<도구>.json의 guide를 bin/의 실제 도구로 확인한다(읽기만, 인터넷 없음).
//   node tools/rules_guide_check.mjs [--tool=ruff|eslint] [--rule=F401] [--write]
// - ruff: `ruff check --isolated --select <규칙>`에 나쁜 예(bad)를 넣으면 그 규칙이 1건 이상, 좋은 예(good)는 0건이어야 한다. 규칙 이름은 `ruff rule <규칙>`과 같아야 한다.
// - ESLint: Linter API로 그 규칙 하나만 켜고(@typescript-eslint 규칙·guide.lang = ts는 TS 파서) 같은 기준.
// - --write: 통과한 규칙에 guide.checked = {tool, version}을 적는다(실패한 규칙은 checked를 지운다 — 화면에 나오지 않음).
// 결과: 규칙별 한 줄, 실패가 있으면 종료 코드 1.
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { readFileSync, writeFileSync, existsSync, readdirSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const arg = (k) => process.argv.find((a) => a.startsWith(`--${k}=`))?.slice(k.length + 3);
const WRITE = process.argv.includes('--write');
const only = arg('tool');
const onlyRule = arg('rule');
const semver = (a, b) => a.split('.').map(Number).reduce((r, x, i) => r || x - Number(b.split('.')[i] ?? 0), 0);
const newest = (dir) => (existsSync(dir) ? readdirSync(dir).filter((v) => /^\d+\.\d+\.\d+$/.test(v)).sort(semver).pop() : null);

const ruffVer = newest(path.join(ROOT, 'bin/ruff'));
const ruffExe = ruffVer && path.join(ROOT, 'bin/ruff', ruffVer, process.platform === 'win32' ? 'windows/ruff.exe' : 'linux/ruff');
const eslintVer = newest(path.join(ROOT, 'bin/eslint'));
let failures = 0;
let passes = 0;

function ruffHits(code, rule) {
  const r = spawnSync(ruffExe, ['check', '--isolated', '--no-cache', '--select', rule, '--output-format', 'json', '--stdin-filename', 'example.py', '-'], { input: code, encoding: 'utf8' });
  const out = JSON.parse(r.stdout || '[]');
  return { hits: out.filter((m) => m.code === rule).length, other: out.filter((m) => m.code !== rule).map((m) => m.code ?? 'syntax') };
}

let linter = null;
let tsPlugin = null;
let tsParser = null;
let builtin = null;
function eslintSetup() {
  if (linter) return;
  const req = createRequire(path.join(ROOT, 'bin/eslint', eslintVer, 'package.json'));
  const { Linter } = req('eslint');
  linter = new Linter({ configType: 'flat' });
  builtin = req('eslint/use-at-your-own-risk').builtinRules;
  tsPlugin = req('@typescript-eslint/eslint-plugin');
  tsParser = req('@typescript-eslint/parser');
}
function eslintHits(code, rule, ts) {
  eslintSetup();
  const cfg = ts
    ? [{ files: ['**/*.ts'], languageOptions: { parser: tsParser, ecmaVersion: 'latest', sourceType: 'module' }, plugins: { '@typescript-eslint': tsPlugin }, rules: { [rule]: 'error' } }]
    : [{ files: ['**/*.js'], languageOptions: { ecmaVersion: 'latest', sourceType: 'module' }, rules: { [rule]: 'error' } }];
  const out = linter.verify(code, cfg, ts ? 'example.ts' : 'example.js');
  return { hits: out.filter((m) => m.ruleId === rule).length, other: out.filter((m) => m.ruleId !== rule).map((m) => m.ruleId ?? `fatal: ${m.message}`) };
}
function eslintExists(rule) {
  eslintSetup();
  if (rule.startsWith('@typescript-eslint/')) return !!tsPlugin.rules[rule.slice('@typescript-eslint/'.length)];
  return builtin.has(rule);
}

for (const tool of ['ruff', 'eslint']) {
  if (only && only !== tool) continue;
  const file = path.join(ROOT, 'resources/rules/enrichment', `${tool}.json`);
  const doc = JSON.parse(readFileSync(file, 'utf8'));
  const version = tool === 'ruff' ? ruffVer : eslintVer;
  if (!version) { console.log(`${tool}: bin/에 도구 없음 — 건너뜀`); continue; }
  for (const [rule, e] of Object.entries(doc.rules)) {
    if (!e.guide || (onlyRule && onlyRule !== rule)) continue;
    const g = e.guide;
    const problems = [];
    if (tool === 'ruff') {
      const info = spawnSync(ruffExe, ['rule', rule, '--output-format', 'json'], { encoding: 'utf8' });
      if (info.status !== 0) problems.push('도구에 없는 규칙');
      else if (g.name && JSON.parse(info.stdout).name !== g.name) problems.push(`이름 불일치(도구 ${JSON.parse(info.stdout).name})`);
    } else if (!eslintExists(rule)) problems.push('도구에 없는 규칙');
    if (!problems.length) {
      const ts = tool === 'eslint' && (rule.startsWith('@typescript-eslint/') || g.lang === 'ts');
      const bad = tool === 'ruff' ? ruffHits(g.bad, rule) : eslintHits(g.bad, rule, ts);
      const good = tool === 'ruff' ? ruffHits(g.good, rule) : eslintHits(g.good, rule, ts);
      if (bad.hits < 1) problems.push(`나쁜 예가 규칙에 걸리지 않음${bad.other.length ? ` (다른 것: ${bad.other.join(',')})` : ''}`);
      if (good.hits > 0) problems.push(`좋은 예가 규칙에 걸림(${good.hits})`);
      if (good.other.some((x) => String(x).startsWith('fatal') || x === 'syntax')) problems.push(`좋은 예 문법 오류 ${good.other.join(',')}`);
    }
    if (problems.length) {
      failures++;
      console.log(`XX ${tool}:${rule} — ${problems.join('; ')}`);
      if (WRITE) delete g.checked;
    } else {
      passes++;
      if (WRITE) g.checked = { tool, version };
    }
  }
  if (WRITE) writeFileSync(file, `${JSON.stringify(doc, null, 2)}\n`);
}
console.log(`통과 ${passes}, 실패 ${failures}${WRITE ? ' (checked 기록)' : ''}`);
process.exit(failures ? 1 : 0);
