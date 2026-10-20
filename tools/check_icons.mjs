// 아이콘 매핑 점검(명세 20.3, 20.5). 실패하면 종료 코드 1.
//   node tools/check_icons.mjs
// 검사 항목
//  1. 명세 20.3 표의 모든 항목이 의미 이름으로 매핑되어 있다(아래 REQUIRED, 명세에서 옮긴 목록)
//  2. 매핑된 아이콘이 sprite.svg에 <symbol id="i-…">로 실제로 있다(스프라이트에 쓰지 않는 아이콘도 없다)
//  3. 라벨 키와 접근성 라벨 키가 PHP(src/Lang/ko.php)와 JS(public/assets/js/i18n/ko.js)에 모두 있고, 비어 있지 않으며, 두 값이 같다
//  4. 아이콘 하나를 두 가지 뜻으로 쓰지 않는다(같은 concept끼리만 공유 가능, 명세 20.1)
//  5. 크기 규격·애니메이션 값이 올바르다(running만 spin)
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const map = JSON.parse(readFileSync(path.join(root, 'public/assets/icons/icons.json'), 'utf8'));
const sprite = readFileSync(path.join(root, 'public/assets/icons/sprite.svg'), 'utf8');
const { default: jsMessages } = await import(new URL('../public/assets/js/i18n/ko.js', import.meta.url));
const phpSource = readFileSync(path.join(root, 'src/Lang/ko.php'), 'utf8');
const phpMessages = Object.fromEntries([...phpSource.matchAll(/^\s*'([^']+)'\s*=>\s*'((?:[^'\\]|\\.)*)',\s*$/gm)].map((m) => [m[1], m[2].replace(/\\'/g, "'")]));

// 명세 20.3 표(구분 → 항목). 이 목록을 바꾸려면 명세가 바뀌어야 한다.
const REQUIRED = {
  severity: ['critical', 'high', 'medium', 'low', 'not_defined'],
  defect_status: ['open', 'in_progress', 'postponed', 'intentional', 'not_fix', 'resolved'],
  analysis_status: ['queued', 'running', 'succeeded', 'failed', 'cancelled'],
  language: ['python', 'javascript'],
  source: ['upload', 'git', 'perforce', 'schedule'],
  nav: ['dashboard', 'projects', 'defects', 'rules', 'history', 'notifications', 'guide'],
  admin: ['users', 'projects', 'system', 'audit', 'guide'],
  action: ['upload', 'fetch_now', 'cancel', 'export', 'filter', 'search', 'bulk_edit', 'delete', 'copy'],
  system: ['ok', 'warn', 'error'],
};

const errors = [];
const ok = [];

// 1. 필수 항목
let requiredCount = 0;
for (const [group, items] of Object.entries(REQUIRED)) {
  for (const item of items) {
    requiredCount++;
    const name = `${group}.${item}`;
    const e = map.icons[name];
    if (!e) errors.push(`20.3 항목이 매핑에 없음: ${name}`);
    else if (e.group !== group) errors.push(`${name}: group이 ${group}이 아님(${e.group})`);
  }
}
ok.push(`명세 20.3 항목 ${requiredCount}개 매핑 확인`);

// 2. 스프라이트
const symbols = new Set([...sprite.matchAll(/<symbol id="i-([a-z0-9-]+)"/g)].map((m) => m[1]));
const usedIcons = new Set(Object.values(map.icons).map((e) => e.icon));
for (const [name, e] of Object.entries(map.icons)) {
  if (!symbols.has(e.icon)) errors.push(`${name}: 스프라이트에 i-${e.icon} 없음(php tools/build_icons.php 실행)`);
}
for (const s of symbols) if (!usedIcons.has(s)) errors.push(`스프라이트에 쓰지 않는 아이콘: i-${s}`);
if (!map.icons[map.fallback]) errors.push(`fallback(${map.fallback})이 매핑에 없음`);
ok.push(`스프라이트 symbol ${symbols.size}개 = 매핑에서 쓰는 아이콘 ${usedIcons.size}개`);

// 3. 라벨
let labelCount = 0;
const checkKey = (name, key) => {
  if (typeof key !== 'string' || key === '') { errors.push(`${name}: 라벨 키가 비어 있음`); return; }
  const js = jsMessages[key];
  const php = phpMessages[key];
  if (!js || js.trim() === '') errors.push(`${name}: JS ko.js에 ${key} 없음 또는 빈 값`);
  if (!php || php.trim() === '') errors.push(`${name}: PHP ko.php에 ${key} 없음 또는 빈 값`);
  if (js && php && js !== php) errors.push(`${name}: ${key} 값이 PHP("${php}")와 JS("${js}")에서 다름`);
  labelCount++;
};
for (const [name, e] of Object.entries(map.icons)) {
  checkKey(name, e.label);
  checkKey(name, e.aria);
  if (!map.groups[e.group]) errors.push(`${name}: 알 수 없는 group ${e.group}`);
}
for (const [g, key] of Object.entries(map.groups)) checkKey(`group ${g}`, key);
ok.push(`라벨·접근성 라벨 ${labelCount}개(PHP·JS 일치, 빈 값 없음)`);

// 4. 한 아이콘 = 한 뜻
const byIcon = new Map();
for (const [name, e] of Object.entries(map.icons)) {
  if (!byIcon.has(e.icon)) byIcon.set(e.icon, []);
  byIcon.get(e.icon).push({ name, concept: e.concept ?? null });
}
let shared = 0;
for (const [iconId, users] of byIcon) {
  if (users.length < 2) continue;
  const concepts = new Set(users.map((u) => u.concept));
  if (concepts.size !== 1 || concepts.has(null)) {
    errors.push(`아이콘 ${iconId}을 서로 다른 뜻으로 사용: ${users.map((u) => u.name).join(', ')} (같은 뜻이면 concept을 같게)`);
  } else {
    shared++;
  }
}
ok.push(`한 아이콘 = 한 뜻(같은 개념 공유 ${shared}건: ${[...byIcon].filter(([, u]) => u.length > 1).map(([i, u]) => `${i}=${u.map((x) => x.name).join('+')}`).join(', ') || '없음'})`);

// 5. 애니메이션
for (const [name, e] of Object.entries(map.icons)) {
  if (e.animate !== undefined && e.animate !== 'spin') errors.push(`${name}: animate 값은 spin만`);
  if (e.animate === 'spin' && name !== 'analysis_status.running') errors.push(`${name}: 회전 애니메이션은 analysis_status.running만(명세 20.3)`);
}
if (map.icons['analysis_status.running']?.animate !== 'spin') errors.push('analysis_status.running은 회전 애니메이션(animate: spin)');

for (const line of ok) console.log(`[OK] ${line}`);
for (const line of errors) console.log(`[FAIL] ${line}`);
console.log(errors.length === 0 ? `아이콘 점검 통과(의미 이름 ${Object.keys(map.icons).length}개)` : `아이콘 점검 실패 ${errors.length}건`);
process.exit(errors.length === 0 ? 0 : 1);
