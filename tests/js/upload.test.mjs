// 업로드 화면 순수 함수 시험(node --test "tests/js/*.test.mjs")
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import {
  chunkRanges, sha256Hex, isExcluded, prepareFolderEntries, estimateZipSize, resumeKey, resumeStorageKey,
  loadResume, saveResume, receivedMap, shouldRetry, formatBytes,
} from '../../public/assets/js/lib/upload_core.js';
import { Zip, ZipPassThrough } from '../../public/assets/vendor/fflate/fflate.esm.js';

test('chunkRanges: 8MB 청크, 마지막은 남은 만큼', () => {
  const MB = 1024 * 1024;
  const r = chunkRanges(20 * MB + 5, 8 * MB);
  assert.equal(r.length, 3);
  assert.deepEqual(r[2], { index: 2, start: 16 * MB, end: 20 * MB + 5 });
  assert.deepEqual(chunkRanges(8 * MB, 8 * MB).map((c) => c.end - c.start), [8 * MB]);
  assert.deepEqual(chunkRanges(0, 8), []);
});

test('sha256Hex: Node crypto와 같은 값', async () => {
  const data = new TextEncoder().encode('PJGuardian 업로드');
  assert.equal(await sha256Hex(data), createHash('sha256').update(data).digest('hex'));
});

test('isExcluded: 경로 조각이 이름과 정확히 같을 때만(대소문자 구분)', () => {
  const names = ['node_modules', '.git', 'build', '.DS_Store'];
  assert.equal(isExcluded('proj/node_modules/a.js', names), true);
  assert.equal(isExcluded('proj/src/.DS_Store', names), true);
  assert.equal(isExcluded('proj/builder/a.py', names), false);
  assert.equal(isExcluded('proj/src/build.py', names), false);
  assert.equal(isExcluded('proj/Build/a.py', names), false);
});

test('prepareFolderEntries: 거르고 경로 순으로 고정 정렬', () => {
  const files = [
    { path: 'p/z.py', size: 1 }, { path: 'p/node_modules/x.js', size: 9 }, { path: 'p/a.py', size: 2 }, { path: 'p/한글.py', size: 3 },
  ];
  assert.deepEqual(prepareFolderEntries(files, ['node_modules'], true).map((f) => f.path), ['p/a.py', 'p/z.py', 'p/한글.py']);
  assert.equal(prepareFolderEntries(files, ['node_modules'], false).length, 4);
});

test('estimateZipSize: fflate STORE 결과 이상(2GB 판단은 안전한 쪽)', async () => {
  const entries = [
    { path: 'proj/a.py', data: new Uint8Array(1000).fill(65) },
    { path: 'proj/폴더/한글 파일.py', data: new Uint8Array(12345).fill(66) },
    { path: 'proj/empty.txt', data: new Uint8Array(0) },
  ];
  const chunks = [];
  await new Promise((resolve, reject) => {
    const zip = new Zip((err, chunk, final) => { if (err) reject(err); chunks.push(chunk); if (final) resolve(); });
    for (const e of entries) {
      const f = new ZipPassThrough(e.path);
      f.mtime = 1700000000000;
      zip.add(f);
      f.push(e.data, true);
    }
    zip.end();
  });
  const actual = chunks.reduce((s, c) => s + c.length, 0);
  const est = estimateZipSize(entries.map((e) => ({ path: e.path, size: e.data.length })));
  assert.ok(est >= actual, `추정 ${est} ≥ 실제 ${actual}`);
  assert.ok(est - actual < 200, '차이가 작다');
});

test('resumeKey: 대상과 프로젝트·옵션이 같아야 같은 키', () => {
  const f = { kind: 'file', projectId: 1, name: 'a.zip', size: 10, lastModified: 5 };
  assert.equal(resumeKey(f), resumeKey({ ...f }));
  assert.notEqual(resumeKey(f), resumeKey({ ...f, projectId: 2 }));
  assert.notEqual(resumeKey(f), resumeKey({ ...f, lastModified: 6 }));
  const d = { kind: 'folder', projectId: 1, name: 'src', size: 100, skipExcluded: true };
  assert.notEqual(resumeKey(d), resumeKey({ ...d, skipExcluded: false }));
});

test('이어 올리기 저장: 사용자별 키, 깨진 값은 빈 객체', () => {
  const mem = new Map();
  const storage = { getItem: (k) => mem.get(k) ?? null, setItem: (k, v) => mem.set(k, v) };
  saveResume(storage, 7, { k: 'u1' });
  assert.deepEqual(loadResume(storage, 7), { k: 'u1' });
  assert.deepEqual(loadResume(storage, 8), {}, '다른 사용자의 항목은 보이지 않음');
  assert.equal(resumeStorageKey(7), 'pjg-upload-resume:7');
  mem.set(resumeStorageKey(9), '{bad');
  assert.deepEqual(loadResume(storage, 9), {});
  const throwing = { getItem: () => { throw new Error('x'); }, setItem: () => { throw new Error('x'); } };
  assert.deepEqual(loadResume(throwing, 1), {});
  saveResume(throwing, 1, {});
});

test('receivedMap, shouldRetry, formatBytes', () => {
  assert.equal(receivedMap([{ index: 2, sha256: 'ab' }]).get(2), 'ab');
  assert.deepEqual([0, 422, 500, 503, 404, 409, 400, 413].map(shouldRetry), [true, true, true, true, false, false, false, false]);
  assert.equal(formatBytes(2 * 1024 ** 3), '2.00GB');
  assert.equal(formatBytes(1536), '1.5KB');
});

// ---- X04: 선택 요약(폴더 이름, 개수, 제외, 200개 제한, NFC) ----
import { folderSummary, fileSummary, nfc } from '../../public/assets/js/lib/upload_core.js';

test('folderSummary: 폴더 이름·개수·제외·예상 크기·200개 제한', () => {
  const files = [];
  for (let i = 0; i < 250; i++) files.push({ path: `proj/src/m${String(i).padStart(3, '0')}.py`, size: 10 });
  files.push({ path: 'proj/node_modules/x/index.js', size: 999 }, { path: 'proj/.git/HEAD', size: 5 });
  const s = folderSummary(files, ['node_modules', '.git'], true);
  assert.equal(s.name, 'proj');
  assert.deepEqual([s.total, s.included, s.excluded], [252, 250, 2]);
  assert.equal(s.shown.length, 200, '처음 200개만 그린다');
  assert.equal(s.more, 52, '외 N개');
  assert.equal(s.estimate, estimateZipSize(files.filter((f) => !f.path.includes('node_modules') && !f.path.includes('.git'))), '포함 파일로 예상 크기');
  const ex = folderSummary(files, ['node_modules', '.git'], true, 1000).shown.filter((r) => r.excluded).map((r) => r.path);
  assert.deepEqual(ex, ['proj/.git/HEAD', 'proj/node_modules/x/index.js'], '제외 파일 표시(정렬)');
  const off = folderSummary(files, ['node_modules', '.git'], false);
  assert.deepEqual([off.included, off.excluded], [252, 0], '제외 옵션을 끄면 모두 포함');
});

test('folderSummary: 경로 순 정렬, NFC 정규화, 값은 그대로(이스케이프는 화면에서 textContent)', () => {
  const nfd = '한글'.normalize('NFD');
  const s = folderSummary([{ path: `${nfd}폴더/b.py`, size: 1 }, { path: `${nfd}폴더/<b>x</b>.py`, size: 1 }], [], true);
  assert.equal(s.name, '한글폴더');
  assert.equal(s.name.normalize('NFC'), s.name);
  assert.deepEqual(s.shown.map((r) => r.path), ['한글폴더/<b>x</b>.py', '한글폴더/b.py']);
  assert.deepEqual(folderSummary([], [], true), { name: '', total: 0, included: 0, excluded: 0, excludedDirs: 0, estimate: 22, shown: [], more: 0 });
});

test('fileSummary, nfc', () => {
  const f = { name: '코드'.normalize('NFD') + '.zip', size: 2048, lastModified: 1700000000000 };
  assert.deepEqual(fileSummary(f), { name: '코드.zip', size: 2048, lastModified: 1700000000000 });
  assert.equal(nfc('가'.normalize('NFD')), '가');
});

// ---- X04 0절: showDirectoryPicker 핸들의 재귀 열거(가짜 핸들) ----
import { enumerateDirectory } from '../../public/assets/js/lib/upload_core.js';

/** 가짜 디렉토리 핸들: tree = { 이름: 문자열(파일 내용) | 객체(하위 폴더) }. entered는 열어 본 폴더 경로 */
function fakeDir(name, tree, entered, prefix = name) {
  return {
    kind: 'directory',
    name,
    async *entries() {
      entered.push(prefix);
      for (const [k, v] of Object.entries(tree)) {
        if (typeof v === 'string') {
          yield [k, { kind: 'file', name: k, getFile: async () => ({ name: k, size: v.length, lastModified: 1700000000000, stream: () => null }) }];
        } else {
          yield [k, fakeDir(k, v, entered, `${prefix}/${k}`)];
        }
      }
    },
  };
}

test('enumerateDirectory: 재귀 열거, 상대 경로, 제외 폴더는 들어가지 않음', async () => {
  const entered = [];
  const root = fakeDir('proj', { 'a.py': 'x', src: { 'b.py': 'yy', deep: { 'c.py': 'zzz' } }, node_modules: { lib: { 'x.js': '1' } }, '.git': { HEAD: 'r' }, '.DS_Store': 'd' }, entered);
  const progress = [];
  const r = await enumerateDirectory(root, { excludeNames: ['node_modules', '.git', '.DS_Store'], skipExcluded: true, onProgress: (n) => progress.push(n) });
  assert.equal(r.name, 'proj');
  assert.deepEqual(r.files.map((f) => f.path).sort(), ['proj/.DS_Store', 'proj/a.py', 'proj/src/b.py', 'proj/src/deep/c.py']);
  assert.deepEqual(r.excludedDirs.sort(), ['proj/.git', 'proj/node_modules']);
  assert.ok(!entered.some((e) => e.includes('node_modules') || e.includes('.git')), '제외 폴더 안은 열거하지 않음');
  assert.equal(r.files.find((f) => f.path === 'proj/src/b.py').size, 2);
  assert.equal(progress.at(-1), 4, '마지막 진행 표시는 전체 개수');
  const s = folderSummary(r.files, ['node_modules', '.git', '.DS_Store'], true, 200, r.excludedDirs);
  assert.deepEqual([s.included, s.excluded, s.excludedDirs], [3, 1, 2]);
  assert.ok(s.shown.some((x) => x.path === 'proj/node_modules/' && x.excluded && x.dir), '제외 폴더도 목록에 표시');
});

test('enumerateDirectory: 제외 옵션을 끄면 제외 폴더도 열거, 중단 신호로 멈춤', async () => {
  const entered = [];
  const tree = { node_modules: { 'x.js': '1' } };
  for (let i = 0; i < 50; i++) tree[`m${i}.py`] = 'x';
  const all = await enumerateDirectory(fakeDir('p', tree, entered), { excludeNames: ['node_modules'], skipExcluded: false });
  assert.equal(all.files.length, 51);
  assert.deepEqual(all.excludedDirs, []);
  const ac = new AbortController();
  let seen = 0;
  await assert.rejects(
    enumerateDirectory(fakeDir('p', tree, []), { excludeNames: [], skipExcluded: true, signal: ac.signal, onProgress: () => {}, onFile: () => { if (++seen === 10) ac.abort(); } }),
    (e) => e.name === 'AbortError',
  );
  assert.equal(seen, 10, '중단 신호 뒤에는 더 읽지 않음');
});
