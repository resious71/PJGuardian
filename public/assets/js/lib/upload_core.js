// 업로드 화면의 순수 함수(명세 5.4, 19.10). DOM·네트워크 없이 node --test로 시험한다(tests/js/upload.test.mjs).

/** 청크 범위: [{ index, start, end }] (end는 포함하지 않음) */
export function chunkRanges(size, chunkSize) {
  if (!(size > 0) || !(chunkSize > 0)) return [];
  const out = [];
  for (let i = 0, start = 0; start < size; i++, start += chunkSize) {
    out.push({ index: i, start, end: Math.min(size, start + chunkSize) });
  }
  return out;
}

/** ArrayBuffer(또는 TypedArray)의 SHA-256을 16진수 소문자로 */
export async function sha256Hex(buf, subtle = globalThis.crypto.subtle) {
  const d = await subtle.digest('SHA-256', buf);
  return [...new Uint8Array(d)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

/**
 * 경로가 기본 제외 대상인지: 폴더 조각 하나가 이름과 **정확히** 같을 때(대소문자 구분, P11의 서버 규칙과 같다).
 * 마지막 조각(파일 이름)도 본다: .DS_Store 같은 파일 이름도 목록에 있다.
 */
export function isExcluded(relPath, names) {
  const set = names instanceof Set ? names : new Set(names);
  return String(relPath).split('/').some((seg) => set.has(seg));
}

/**
 * 폴더 파일 목록을 거르고 경로 순으로 정렬한다(정렬을 고정해야 다시 묶어도 같은 zip 바이트 → 이어 올리기).
 * files: [{ path, size, lastModified, ... }]
 */
export function prepareFolderEntries(files, excludeNames, skipExcluded) {
  return files
    .filter((f) => !skipExcluded || !isExcluded(f.path, excludeNames))
    .sort((a, b) => (a.path < b.path ? -1 : a.path > b.path ? 1 : 0));
}

/**
 * STORE 기준 zip 크기 상한 추정: 데이터 + 항목마다 (로컬 헤더 30 + 중앙 디렉토리 46 + 데이터 설명자 16 + 이름 × 2) + 끝 레코드 22.
 * (fflate ZipPassThrough의 실제 크기보다 약간 크거나 같다. 2GB 한도 판단에 쓴다)
 */
export function estimateZipSize(entries) {
  const enc = new TextEncoder();
  return entries.reduce((s, f) => s + f.size + 92 + 2 * enc.encode(f.path).length, 22);
}

/** 이어 올리기 대상 키. 파일: 이름·크기·수정 시각, 폴더: 이름·zip 크기·옵션. 프로젝트도 넣는다(다른 프로젝트로 이어 쓰지 않게) */
export function resumeKey(target) {
  if (target.kind === 'file') {
    return ['file', target.projectId, target.name, target.size, target.lastModified].join('|');
  }
  return ['folder', target.projectId, target.name, target.size, target.skipExcluded ? 'skip' : 'all'].join('|');
}

/** localStorage 키(사용자 ID를 넣어 다른 사용자의 항목과 섞이지 않게) */
export function resumeStorageKey(userId) {
  return `pjg-upload-resume:${userId}`;
}

export function loadResume(storage, userId) {
  try {
    const v = JSON.parse(storage?.getItem(resumeStorageKey(userId)) ?? '{}');
    return v && typeof v === 'object' && !Array.isArray(v) ? v : {};
  } catch {
    return {};
  }
}

export function saveResume(storage, userId, map) {
  try {
    storage?.setItem(resumeStorageKey(userId), JSON.stringify(map));
  } catch {
    // 저장할 수 없으면(사생활 보호 모드 등) 이어 올리기만 안 된다
  }
}

/** 서버 status의 received([{index, sha256}])를 Map으로 */
export function receivedMap(received) {
  return new Map((received ?? []).map((c) => [c.index, c.sha256]));
}

/** 재시도할 오류인가: 404(업로드 없음)·409(상태 충돌)·4xx 검증 오류는 재시도하지 않는다. 네트워크(0)·5xx·422(해시 불일치)는 재시도 */
export function shouldRetry(status) {
  return status === 0 || status === 422 || status >= 500;
}

/** 사람이 읽는 크기 */
export function formatBytes(n) {
  if (n >= 1024 ** 3) return `${(n / 1024 ** 3).toFixed(2)}GB`;
  if (n >= 1024 ** 2) return `${(n / 1024 ** 2).toFixed(1)}MB`;
  if (n >= 1024) return `${(n / 1024).toFixed(1)}KB`;
  return `${n}B`;
}

// ---- 선택 요약(X04) ----
// 브라우저는 PC의 전체 경로(D:\…)를 알려 주지 않는다. 폴더는 고른 폴더 이름부터 시작하는 상대 경로(webkitRelativePath),
// 파일은 이름·크기·수정 시각만 안다. 화면은 이것을 그대로 보여 준다(추측한 경로·fakepath를 만들지 않는다).
// 값은 이스케이프하지 않은 원래 글자다. 화면은 textContent로만 넣는다.

/** 유니코드 NFC 정규화(서버의 경로 규칙과 같게, 명세 19.10) */
export function nfc(s) {
  return String(s).normalize('NFC');
}

/**
 * 폴더 선택 요약: 폴더 이름(첫 조각), 전체·포함·제외 개수, 예상 업로드 크기(포함 파일, STORE 기준),
 * 그릴 목록(경로 순, 처음 limit개)과 나머지 개수. 제외 표시는 skipExcluded일 때 기본 제외 목록 기준이다
 * (프로젝트 추가 패턴은 서버가 해제할 때 적용한다). excludedDirs: 열거하지 않은 제외 폴더 경로(showDirectoryPicker 방식).
 */
export function folderSummary(files, excludeNames, skipExcluded, limit = 200, excludedDirs = []) {
  const names = new Set(excludeNames);
  const rows = files
    .map((f) => ({ path: nfc(f.path), size: f.size, excluded: skipExcluded && isExcluded(nfc(f.path), names), dir: false }))
    .concat(excludedDirs.map((d) => ({ path: `${nfc(d)}/`, size: 0, excluded: true, dir: true })))
    .sort((a, b) => (a.path < b.path ? -1 : a.path > b.path ? 1 : 0));
  const fileRows = rows.filter((r) => !r.dir);
  const included = fileRows.filter((r) => !r.excluded);
  return {
    name: rows.length > 0 ? rows[0].path.split('/')[0] : '',
    total: fileRows.length,
    included: included.length,
    excluded: fileRows.length - included.length,
    excludedDirs: excludedDirs.length,
    estimate: estimateZipSize(included),
    shown: rows.slice(0, limit),
    more: Math.max(0, rows.length - limit),
  };
}

/**
 * showDirectoryPicker()가 준 디렉토리 핸들을 재귀로 열거한다(X04). 상대 경로는 "고른 폴더 이름/하위/…/파일".
 * skipExcluded면 기본 제외 목록과 이름이 같은 폴더는 안으로 들어가지 않고 excludedDirs에만 적는다(열거 시간 절약).
 * signal이 중단되면 AbortError로 멈춘다(열거 중 "선택 취소"). onProgress(n): 100개마다와 끝에. onFile: 파일마다(시험용).
 * 결과 files의 형태는 webkitdirectory 방식과 같다: { file, path, size, lastModified }.
 */
export async function enumerateDirectory(dirHandle, { excludeNames = [], skipExcluded = true, onProgress = null, onFile = null, signal = null } = {}) {
  const names = new Set(excludeNames);
  const files = [];
  const excludedDirs = [];
  const stop = () => { if (signal?.aborted) throw new DOMException('aborted', 'AbortError'); };
  async function walk(handle, prefix) {
    for await (const [name, child] of handle.entries()) {
      stop();
      const path = `${prefix}/${name}`;
      if (child.kind === 'directory') {
        if (skipExcluded && names.has(name)) { excludedDirs.push(path); continue; }
        await walk(child, path);
      } else {
        const file = await child.getFile();
        files.push({ file, path, size: file.size, lastModified: file.lastModified });
        onFile?.(path);
        if (onProgress && files.length % 100 === 0) onProgress(files.length);
      }
    }
  }
  await walk(dirHandle, dirHandle.name);
  stop();
  onProgress?.(files.length);
  return { name: dirHandle.name, files, excludedDirs };
}

/** zip 파일 하나의 요약: 이름(NFC), 크기, 수정 시각(밀리초) */
export function fileSummary(file) {
  return { name: nfc(file.name), size: file.size, lastModified: file.lastModified };
}

// ---- 업로드 내용 요약(P21C) ----
// 서버 GET /api/v1/uploads/{id}의 summary(해제 결과의 숫자)를 화면 줄로 바꾼다. 문구는 키와 값만(화면이 t()로).

/** 해시 앞 12자리(전체는 툴팁·복사) */
export function shortHash(h) {
  return typeof h === 'string' && h.length > 12 ? `${h.slice(0, 12)}…` : (h || '—');
}

/** 부호 있는 크기 차이("+1.2KB", "−300B", "0B") */
export function signedBytes(n) {
  if (!n) return '0B';
  return `${n > 0 ? '+' : '−'}${formatBytes(Math.abs(n))}`;
}

/** [{key, value:{key, params}}] — 정상으로 열림, 파일 수, 해제 크기, 분석 대상(확장자별), 제외, 경로 문제 */
export function uploadSummaryRows(s) {
  const by = Object.entries(s.targets_by_extension ?? {}).filter(([, n]) => n > 0).map(([ext, n]) => `.${ext} ${n.toLocaleString('ko-KR')}`).join(', ');
  return [
    { key: 'upload.summary_open', value: { key: s.files > 0 ? 'upload.summary_open_ok' : 'upload.summary_open_empty' } },
    { key: 'upload.summary_files', value: { key: 'upload.summary_count', params: { n: s.files.toLocaleString('ko-KR') } } },
    { key: 'upload.summary_bytes', value: { key: 'upload.summary_plain', params: { text: formatBytes(s.bytes) } } },
    { key: 'upload.summary_targets', value: { key: by ? 'upload.summary_targets_value' : 'upload.summary_count', params: { n: s.targets.toLocaleString('ko-KR'), detail: by } } },
    { key: 'upload.summary_excluded', value: { key: s.config_skipped ? 'upload.summary_excluded_value' : 'upload.summary_count', params: { n: s.excluded.toLocaleString('ko-KR'), config: s.config_skipped } } },
    { key: 'upload.summary_paths', value: { key: 'upload.summary_paths_none' } },
  ];
}

/** 직전 업로드 비교 줄: none(비교할 기록 없음) / same(같은 파일, 경고) / different(다른 파일, 크기 차이) */
export function previousLine(prev, formatTime = (iso) => iso) {
  if (!prev) return { state: 'none', tone: 'info', key: 'upload.previous_none', params: {} };
  const params = { time: formatTime(prev.created_at), size: formatBytes(prev.size), diff: signedBytes(prev.size_diff) };
  return prev.same
    ? { state: 'same', tone: 'warn', key: 'upload.previous_same', params }
    : { state: 'different', tone: 'info', key: 'upload.previous_different', params };
}
