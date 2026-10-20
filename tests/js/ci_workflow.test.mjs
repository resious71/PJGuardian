// .github/workflows/ci.yml을 엄격한 YAML 파서(tests/tools의 yaml 2.x)로 읽고 구조를 확인한다(P33 5절).
// 이 PC에서 CI를 실제로 돌릴 수는 없으므로 구문과 핵심 구성(이벤트·매트릭스·첫 실행 방침·단계)만 고정한다.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const lib = path.join(ROOT, 'tests/tools/node_modules/yaml/dist/index.js');

test('ci.yml: 엄격한 YAML로 읽히고 이벤트·권한·매트릭스·첫 실행 방침·단계가 있다', async () => {
  assert.ok(existsSync(lib), 'tests/tools에 yaml 패키지가 없음(npm ci --prefix tests/tools)');
  const { parseDocument } = await import(pathToFileURL(lib).href);
  const doc = parseDocument(readFileSync(path.join(ROOT, '.github/workflows/ci.yml'), 'utf8'), { strict: true, uniqueKeys: true, prettyErrors: true });
  assert.deepEqual(doc.errors.map((e) => e.message), [], 'YAML 오류');
  assert.deepEqual(doc.warnings.map((e) => e.message), [], 'YAML 경고');
  const wf = doc.toJS();

  assert.deepEqual(Object.keys(wf.on).sort(), ['pull_request', 'push']);
  assert.deepEqual(wf.permissions, { contents: 'read' }, '최소 권한');

  const job = wf.jobs.test;
  assert.equal(job['runs-on'], 'ubuntu-latest');
  assert.deepEqual(job.strategy.matrix.php, ['8.2', '8.3']);
  assert.equal(job['continue-on-error'], true, 'Linux 첫 실행 전에는 실패해도 막지 않음(통과 확인 뒤 제거 — docs/ci_status.md)');

  const steps = job.steps;
  const uses = steps.filter((s) => s.uses).map((s) => s.uses);
  for (const u of uses) assert.match(u, /^[\w.-]+\/[\w.-]+@v\d+$/, `액션은 주 버전 태그로 고정: ${u}`);
  const node = steps.find((s) => String(s.uses).startsWith('actions/setup-node@'));
  assert.equal(String(node.with['node-version']), '24');
  const php = steps.find((s) => String(s.uses).startsWith('shivammathur/setup-php@'));
  for (const ext of ['pdo_sqlite', 'zip', 'mbstring', 'openssl', 'fileinfo', 'intl']) {
    assert.ok(php.with.extensions.split(',').map((x) => x.trim()).includes(ext), `PHP 확장 ${ext}`);
  }

  const runs = steps.filter((s) => s.run).map((s) => s.run);
  const order = [
    /^composer install\b/,
    /^php tools\/check_doc_links\.php$/,
    /^php tools\/scan_release\.php --git=\.$/,
    /^node --test "tests\/js\/\*\.test\.mjs"$/,
    /^php tests\/run\.php --exclude-needs-bin$/,
  ];
  let at = -1;
  for (const re of order) {
    const i = runs.findIndex((r, idx) => idx > at && re.test(r));
    assert.ok(i > at, `단계 순서·존재: ${re}`);
    at = i;
  }
});
