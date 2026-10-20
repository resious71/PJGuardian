// 프런트엔드 서드파티 번들 빌드(P17, 빌드 시에만 실행). 결과물만 public/assets/vendor/에 커밋하고 node_modules는 배포하지 않는다(CDN 금지, 명세 2).
//   node tools/build_vendor.mjs [codemirror] [chartjs]
// 대상마다 tools/vendor/<대상>/(package.json, package-lock.json, entry.js)에서 npm ci → esbuild로 ES 모듈 하나
//   → public/assets/vendor/<대상>/<파일> + LICENSES.txt(번들에 들어간 패키지의 라이선스 원문) + SOURCE.txt(버전·크기·SHA-256)
//   codemirror: codemirror.js(P17 코드 뷰어), chartjs: chart.js(P20 대시보드, Chart.js 4.x)
// 같은 package-lock.json이면 같은 결과가 나온다(날짜 등 가변 값을 넣지 않음). 자세한 내용은 docs/vendor_build.md
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

/** 이 node와 함께 설치된 npm을 셸 없이 실행한다(Windows의 npm.cmd는 셸이 필요하므로 npm-cli.js를 직접) */
function npm(args, cwd) {
  const dir = path.dirname(process.execPath);
  const candidates = [
    path.join(dir, 'node_modules/npm/bin/npm-cli.js'),
    path.join(dir, '../lib/node_modules/npm/bin/npm-cli.js'),
  ];
  const cli = candidates.find((p) => fs.existsSync(p));
  if (!cli) throw new Error(`npm-cli.js를 찾을 수 없음: ${candidates.join(', ')}`);
  const r = spawnSync(process.execPath, [cli, ...args], { cwd, stdio: 'inherit' });
  if (r.status !== 0) throw new Error(`npm ${args.join(' ')} 실패(종료 코드 ${r.status})`);
}

function sha256(file) {
  return createHash('sha256').update(fs.readFileSync(file)).digest('hex');
}

/** node_modules/<이름>(스코프 포함)을 품은 입력 경로 → 패키지 이름 */
function packageOf(input) {
  const m = input.replace(/\\/g, '/').match(/node_modules\/((?:@[^/]+\/)?[^/]+)\//);
  return m ? m[1] : null;
}

function licenseText(pkgDir) {
  for (const name of fs.readdirSync(pkgDir)) {
    if (/^(licen[cs]e|copying)(\.(md|txt))?$/i.test(name)) return fs.readFileSync(path.join(pkgDir, name), 'utf8').trim();
  }
  return null;
}

const TARGETS = {
  codemirror: { file: 'codemirror.js', title: 'CodeMirror 6 읽기 전용 코드 뷰어용 번들(P17)', label: 'CodeMirror 6' },
  chartjs: { file: 'chart.js', title: 'Chart.js 4 대시보드용 번들(P20, 선·막대 차트만 등록)', label: 'Chart.js' },
};

async function buildBundle(name) {
  const target = TARGETS[name];
  const src = path.join(ROOT, 'tools/vendor', name);
  const out = path.join(ROOT, 'public/assets/vendor', name);
  npm(fs.existsSync(path.join(src, 'package-lock.json')) ? ['ci', '--no-audit', '--no-fund'] : ['install', '--no-audit', '--no-fund'], src);
  const esbuild = await import(pathToFileURL(path.join(src, 'node_modules/esbuild/lib/main.js')).href);
  fs.mkdirSync(out, { recursive: true });
  const outfile = path.join(out, target.file);
  const result = await esbuild.build({
    entryPoints: [path.join(src, 'entry.js')],
    bundle: true,
    format: 'esm',
    minify: true,
    target: ['es2020'],
    legalComments: 'none', // 라이선스 원문은 LICENSES.txt에 따로 모은다
    metafile: true,
    outfile,
    logLevel: 'warning',
  });
  // 번들에 실제로 들어간 패키지만 라이선스·버전을 적는다
  const pkgs = new Set();
  for (const input of Object.keys(result.metafile.inputs)) {
    const p = packageOf(input);
    if (p) pkgs.add(p);
  }
  const sorted = [...pkgs].sort();
  const licenses = [];
  const versions = [];
  for (const name of sorted) {
    const dir = path.join(src, 'node_modules', name);
    const meta = JSON.parse(fs.readFileSync(path.join(dir, 'package.json'), 'utf8'));
    const text = licenseText(dir);
    if (!text) throw new Error(`라이선스 파일이 없음: ${name}`);
    versions.push(`${name} ${meta.version} (${meta.license})`);
    licenses.push(`${'='.repeat(70)}\n${name} ${meta.version} — ${meta.license}\n${'='.repeat(70)}\n${text}\n`);
  }
  fs.writeFileSync(path.join(out, 'LICENSES.txt'), `${target.label} 번들(${target.file})에 포함된 패키지의 라이선스 원문. tools/build_vendor.mjs가 만든다.\n\n${licenses.join('\n')}`);
  const size = fs.statSync(outfile).size;
  const esbuildVersion = JSON.parse(fs.readFileSync(path.join(src, 'node_modules/esbuild/package.json'), 'utf8')).version;
  fs.writeFileSync(path.join(out, 'SOURCE.txt'), [
    `${target.file} — ${target.title}`,
    `출처: npm 레지스트리(tools/vendor/${name}/package-lock.json의 버전과 무결성 값)`,
    `빌드: node tools/build_vendor.mjs ${name} (esbuild ${esbuildVersion}, ESM, minify, target es2020)`,
    `크기: ${size} 바이트, SHA-256: ${sha256(outfile)}`,
    '',
    '포함 패키지:',
    ...versions.map((v) => `  ${v}`),
    '',
  ].join('\n'));
  console.log(`${target.file} ${(size / 1024).toFixed(1)}KB, 패키지 ${sorted.length}개: ${sorted.join(', ')}`);
}

const targets = process.argv.slice(2);
for (const name of targets.length ? targets : Object.keys(TARGETS)) {
  if (TARGETS[name]) await buildBundle(name);
  else {
    console.error(`알 수 없는 대상: ${name}`);
    process.exit(2);
  }
}
