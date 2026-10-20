// docs/openapi.yaml을 엄격한 YAML 파서(yaml 2.x, 시험 전용 개발 의존성 tests/tools)로 전체 읽기(P20).
// PHP OpenApiTest는 들여쓰기로 경로·메서드만 읽어 YAML 문법 오류를 놓친다(P18 Error 스키마 설명의 {"current": …}가 엄격한 파서에서 오류였음).
// 의존성 설치: tools/run_all_tests.php가 자동으로, 직접은 `node <npm-cli.js> ci --prefix tests/tools`.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const lib = path.join(ROOT, 'tests/tools/node_modules/yaml/dist/index.js');

test('openapi.yaml: 엄격한 YAML 파서로 전체가 읽히고 경로·참조가 성립한다', async () => {
  assert.ok(existsSync(lib), 'tests/tools에 yaml 패키지가 없음(npm ci --prefix tests/tools)');
  const { parseDocument } = await import(pathToFileURL(lib).href);
  const doc = parseDocument(readFileSync(path.join(ROOT, 'docs/openapi.yaml'), 'utf8'), { strict: true, uniqueKeys: true, prettyErrors: true });
  assert.deepEqual(doc.errors.map((e) => e.message), [], 'YAML 오류');
  assert.deepEqual(doc.warnings.map((e) => e.message), [], 'YAML 경고');
  const api = doc.toJS();
  assert.ok(Object.keys(api.paths).length >= 60, `경로 ${Object.keys(api.paths).length}개`);
  // 모든 $ref가 components 안에 있는지
  const missing = [];
  const walk = (o) => {
    if (Array.isArray(o)) { o.forEach(walk); return; }
    if (!o || typeof o !== 'object') return;
    for (const [k, v] of Object.entries(o)) {
      if (k === '$ref' && typeof v === 'string') {
        const [, , group, name] = v.split('/');
        if (!api.components?.[group]?.[name]) missing.push(v);
      } else walk(v);
    }
  };
  walk(api);
  assert.deepEqual([...new Set(missing)], [], '없는 $ref');
});
