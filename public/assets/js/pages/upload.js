// 코드 업로드 화면(명세 5.4, 19.10). 프로토타입(tools/proto/ref_web/upload.js)의 동작을 제품 컴포넌트로 옮긴 것.
// - ZIP 파일 또는 폴더(로컬 fflate, **STORE만**: Deflate는 제공하지 않는다). 기본 제외 대상은 묶기 전에 뺀다(옵션, 기본 켜짐).
// - 2GB 초과는 업로드 전에 차단(파일 = 크기, 폴더 = STORE 기준 예상 크기). 묶는 중에도 넘으면 중단.
// - 청크 크기는 서버 init 응답값, 청크마다 SHA-256(crypto.subtle), 자동 재시도 3회(404·409 등 검증 오류는 재시도 안 함).
// - 이어 올리기: 대상 키 → upload_id를 localStorage(사용자별 키)에 두고, status의 청크 해시와 같으면 건너뜀.
// - 완료 후 상태를 폴링해 "조립 대기 → 조립 중 → 처리 중"을 보여 준다(해제·경고 확인·분석 연결은 P13).
// 사용자 입력과 서버 메시지는 textContent로만 넣는다.
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { clear, el } from '../core/dom.js';
import { createPoller } from '../core/poll.js';
import { icon } from '../ui/icons.js';
import { confirmDialog } from '../ui/dialog.js';
import { Zip, ZipPassThrough } from '../../vendor/fflate/fflate.esm.js';
import {
  chunkRanges, sha256Hex, prepareFolderEntries, estimateZipSize, resumeKey, loadResume, saveResume,
  receivedMap, shouldRetry, formatBytes, folderSummary, fileSummary, enumerateDirectory, uploadSummaryRows, previousLine, shortHash,
} from '../lib/upload_core.js';
import { formatDateTime, timezoneFromDocument } from '../core/time.js';

const api = createApi();
const tz = timezoneFromDocument();
const LIST_LIMIT = 200; // 선택한 파일 목록은 처음 200개만 그린다(수만 개여도 화면이 멈추지 않게)
const RETRIES = 3;
const POLL_MS = 3000;
const ZIP_FLUSH_BYTES = 64 * 1024 * 1024;
const $ = (id) => document.getElementById(id);

const PHASE_ICON = {
  preparing: 'analysis_status.running', zipping: 'analysis_status.running', uploading: 'analysis_status.running',
  paused: 'analysis_status.queued', assemble_pending: 'analysis_status.queued', assembling: 'analysis_status.running',
  extracting: 'analysis_status.running', processing: 'analysis_status.running',
  needs_confirmation: 'system.warn', queued: 'analysis_status.succeeded',
  failed: 'analysis_status.failed', aborted: 'analysis_status.cancelled',
};
// 처리 단계(업로드 → 조립 → 압축 해제·사전 검사 → 분석 대기열)와 서버 상태의 대응
const STEPS = ['upload', 'assemble', 'extract', 'queue'];
const STEP_OF = { receiving: 0, assemble_pending: 1, assembling: 1, extracting: 2, needs_confirmation: 2, queued: 4 };

// pickerMode: 'api' = showDirectoryPicker(선택 창 버튼 "폴더 선택"), 'input' = webkitdirectory(브라우저가 "업로드" 확인 창을 띄움)
const state = { target: null, excludes: [], paused: false, aborted: false, running: false, uploadId: null, poller: null, watching: null, dialogShownFor: null, enumerating: null, pickerMode: 'input' };
let form;
let maxBytes;
let userId;

function setText(id, text) { $(id).textContent = text; }

function phase(name, params = {}) {
  $('up-progress').hidden = false;
  const box = $('up-phase-icon');
  clear(box);
  box.appendChild(icon(PHASE_ICON[name] ?? 'analysis_status.running', 20));
  setText('up-phase-text', t(`upload.phase.${name}`, params));
}

function progress(done, total) {
  const pct = total > 0 ? Math.floor((done / total) * 100) : 0;
  $('up-bar').value = pct;
  setText('up-percent', `${pct}%`);
}

function showError(e, catalog = null) {
  const box = $('up-error');
  box.textContent = e?.requestId ? `${e.message} (${t('common.request_id', { id: e.requestId })})` : (e?.message ?? String(e));
  // P19: 업로드 오류 카탈로그(원인·조치·코드). 서버의 한 문장(항목 이름 포함) 아래에 조치를 덧붙인다
  const action = $('up-error-action');
  if (catalog && catalog.action) {
    action.textContent = t('upload.error_action', { action: catalog.action, code: catalog.code });
    action.hidden = false;
  } else {
    action.hidden = true;
  }
  // P29: "자세히 보기" → 사용자 가이드의 해결 절, 코드 → 가이드 오류 코드 탭의 그 줄
  const guide = $('up-error-guide');
  const appBase = document.querySelector('meta[name="app-base"]')?.getAttribute('content') ?? '';
  if (catalog?.guide_key && catalog.code) {
    $('up-error-guide-link').href = `${appBase}/guide/user#${catalog.guide_key}`;
    const codeLink = $('up-error-code-link');
    codeLink.href = `${appBase}/guide/errors#${encodeURIComponent(catalog.code)}`;
    codeLink.textContent = catalog.code;
    guide.hidden = false;
  } else {
    guide.hidden = true;
  }
  box.hidden = false;
  phase('failed');
}

function note(text) {
  const el = $('up-note');
  el.textContent = text;
  el.hidden = !text;
}

function buttons() {
  const blocked = !$('up-block').hidden;
  $('up-start').disabled = state.running || !state.target || blocked || state.enumerating !== null;
  $('up-pause').disabled = !state.running;
  // "선택 취소" = 아직 아무것도 보내지 않음(전송 전에만, 폴더 읽는 중에도). 자리는 그대로 두고 켜고 끈다(버튼 줄이 흔들리지 않게).
  // "업로드 취소" = 서버에 있는 전송 중단(전송이 시작된 뒤에만)
  $('up-clear').disabled = state.running || (!state.target && state.enumerating === null);
  $('up-abort').hidden = !state.uploadId;
}

// ---- 대상 선택 ----

/** 선택한 파일 목록(처음 200개, 제외 파일은 "제외" 글자로 구분). 값은 textContent로만 넣는다 */
function renderFileList(summary) {
  const list = $('up-file-list');
  clear(list);
  for (const row of summary.shown) {
    const li = document.createElement('li');
    li.className = row.excluded ? 'upload-files__item is-excluded' : 'upload-files__item';
    li.title = row.path;
    const path = document.createElement('span');
    path.className = 'upload-files__path';
    path.textContent = row.path;
    li.appendChild(path);
    if (row.excluded) {
      const tag = document.createElement('span');
      tag.className = 'tag tag--inactive';
      tag.textContent = t(row.dir ? 'upload.excluded_dir_tag' : 'upload.excluded_tag');
      li.appendChild(tag);
    }
    list.appendChild(li);
  }
  const more = $('up-file-more');
  more.textContent = summary.more > 0 ? t('upload.files_more', { n: summary.more }) : '';
  more.hidden = summary.more === 0;
}

function describe() {
  const block = $('up-block');
  block.hidden = true;
  const tg = state.target;
  const stats = $('up-stats');
  const files = $('up-files');
  if (!tg) {
    setText('up-target', t('upload.no_target'));
    stats.hidden = true;
    files.hidden = true;
    files.open = false;
    clear($('up-file-list'));
  } else if (tg.kind === 'file') {
    const f = fileSummary(tg);
    setText('up-target', t('upload.target_file_name', { name: f.name }));
    stats.textContent = t('upload.file_stats', { size: formatBytes(f.size), time: formatDateTime(new Date(f.lastModified).toISOString(), tz) });
    stats.hidden = false;
    files.hidden = true;
    if (tg.size > maxBytes) {
      block.textContent = t('upload.blocked_size', { size: formatBytes(tg.size), max: formatBytes(maxBytes) });
      block.hidden = false;
    }
  } else {
    const skip = $('up-skip').checked;
    tg.entries = prepareFolderEntries(tg.files, state.excludes, skip);
    tg.estimate = estimateZipSize(tg.entries);
    const sum = folderSummary(tg.files, state.excludes, skip, LIST_LIMIT, skip ? (tg.excludedDirs ?? []) : []);
    setText('up-target', t('upload.target_folder_name', { name: tg.name }));
    stats.textContent = t(sum.excludedDirs > 0 ? 'upload.folder_stats_dirs' : 'upload.folder_stats', {
      included: sum.included, excluded: sum.excluded, total: sum.total, dirs: sum.excludedDirs, size: formatBytes(tg.estimate),
    });
    stats.hidden = false;
    renderFileList(sum);
    files.hidden = false;
    if (tg.estimate > maxBytes) {
      block.textContent = t('upload.blocked_size', { size: formatBytes(tg.estimate), max: formatBytes(maxBytes) });
      block.hidden = false;
    }
  }
  buttons();
}

/** 선택 취소: 서버 요청 없이 선택 상태를 모두 지우고, 같은 대상을 다시 고를 수 있게 입력을 비운다. 폴더를 읽는 중이면 멈춘다 */
function clearSelection() {
  const wasFolder = state.target?.kind === 'folder' || state.enumerating !== null;
  state.enumerating?.abort();
  state.enumerating = null;
  state.target = null;
  $('up-file').value = ''; // 비우지 않으면 같은 파일·폴더를 다시 골랐을 때 change 이벤트가 오지 않는다
  $('up-folder').value = '';
  note(''); // 이어 올리기 안내 등. 서버에 남은 청크는 건드리지 않는다(24시간 무활동 정리)
  describe();
  $(wasFolder ? (state.pickerMode === 'api' ? 'up-folder-btn' : 'up-folder') : 'up-file').focus();
}

/** webkitdirectory 방식으로 되돌린다(showDirectoryPicker가 없거나 브라우저가 폴더를 거부). 그때만 "업로드" 확인 창 안내를 보인다 */
function useInputPicker() {
  state.pickerMode = 'input';
  $('up-folder-btn').hidden = true;
  $('up-folder-label').hidden = false;
  $('up-folder').hidden = false;
  $('up-fallback-note').hidden = false;
}

/** 폴더 핸들을 열거해 대상으로 둔다. 열거 중 진행 표시, "선택 취소"로 중단 */
async function enumerateInto(handle) {
  state.enumerating?.abort();
  const ac = new AbortController();
  state.enumerating = ac;
  state.target = null;
  $('up-block').hidden = true;
  $('up-stats').hidden = true;
  $('up-files').hidden = true;
  setText('up-target', t('upload.reading_folder', { name: handle.name, n: 0 }));
  buttons();
  try {
    const r = await enumerateDirectory(handle, {
      excludeNames: state.excludes,
      skipExcluded: $('up-skip').checked,
      signal: ac.signal,
      onProgress: (n) => { if (state.enumerating === ac) setText('up-target', t('upload.reading_folder', { name: handle.name, n })); },
    });
    if (state.enumerating !== ac) return;
    state.enumerating = null;
    state.target = { kind: 'folder', files: r.files, name: r.name, excludedDirs: r.excludedDirs, handle };
    describe();
  } catch (e) {
    if (state.enumerating === ac) {
      state.enumerating = null;
      if (e?.name !== 'AbortError') showError(e);
      describe();
    }
  }
}

/** "폴더 선택"(showDirectoryPicker). 고르기만 하고 전송은 "업로드 시작"을 눌러야 한다 */
async function pickFolderApi() {
  let handle;
  try {
    handle = await globalThis.showDirectoryPicker({ mode: 'read', id: 'pjg-upload' });
  } catch (e) {
    if (e?.name === 'AbortError') return; // 선택 창에서 취소: 아무 일도 없었던 것처럼
    // 브라우저가 거부한 폴더(홈·바탕 화면 등) 또는 사용할 수 없음 → 기존 방식으로. 안내를 보이고 그 버튼에 포커스
    useInputPicker();
    $('up-folder').focus();
    return;
  }
  $('up-file').value = '';
  $('up-folder').value = '';
  await enumerateInto(handle);
}

function pickFile() {
  state.enumerating?.abort();
  state.enumerating = null;
  const f = $('up-file').files[0];
  $('up-folder').value = '';
  state.target = f ? { kind: 'file', file: f, name: f.name, size: f.size, lastModified: f.lastModified } : null;
  describe();
}

function pickFolder() {
  const all = [...$('up-folder').files];
  $('up-file').value = '';
  if (all.length === 0) { state.target = null; describe(); return; }
  const files = all.map((f) => ({ file: f, path: f.webkitRelativePath, size: f.size, lastModified: f.lastModified }));
  state.target = { kind: 'folder', files, name: files[0].path.split('/')[0] };
  describe();
}

// ---- 폴더 → zip(STORE, 스트리밍으로 Blob에 모음) ----

async function zipFolder(entries) {
  let pending = [];
  let pendingBytes = 0;
  let blob = new Blob([]);
  let total = 0;
  let failure = null;
  let resolveDone;
  let rejectDone;
  const done = new Promise((res, rej) => { resolveDone = res; rejectDone = rej; });
  // 만들어지는 대로 64MB마다 Blob으로 합쳐 메모리의 조각을 놓아 준다(브라우저가 큰 Blob을 디스크로 내릴 수 있다)
  const zip = new Zip((err, chunk, final) => {
    if (err) { rejectDone(err); return; }
    pending.push(chunk);
    pendingBytes += chunk.length;
    total += chunk.length;
    if (total > maxBytes && !failure) {
      failure = new Error(t('upload.blocked_during_zip', { max: formatBytes(maxBytes) }));
      rejectDone(failure);
    }
    if (pendingBytes >= ZIP_FLUSH_BYTES || final) {
      blob = new Blob([blob, ...pending], { type: 'application/zip' });
      pending = [];
      pendingBytes = 0;
    }
    if (final) resolveDone();
  });
  let i = 0;
  for (const e of entries) {
    if (failure) throw failure;
    if (state.paused) throw Object.assign(new Error(t('upload.phase.paused')), { paused: true });
    const entry = new ZipPassThrough(e.path); // STORE만(명세 19.10)
    entry.mtime = e.lastModified; // 같은 폴더 → 같은 zip 바이트(이어 올리기)
    zip.add(entry);
    const reader = e.file.stream().getReader();
    for (;;) {
      const { done: end, value } = await reader.read();
      if (end) { entry.push(new Uint8Array(0), true); break; }
      entry.push(value);
    }
    i++;
    if (i % 100 === 0 || i === entries.length) {
      phase('zipping', { i, n: entries.length });
      progress(i, entries.length);
      await new Promise((r) => setTimeout(r, 0));
    }
  }
  zip.end();
  await done;
  return blob;
}

// ---- 청크 업로드 ----

async function putChunk(id, index, part, sha) {
  for (let attempt = 1; ; attempt++) {
    try {
      return await api.putRaw(`/api/v1/uploads/${id}/chunks/${index}`, part, { headers: { 'X-Chunk-Sha256': sha } });
    } catch (e) {
      if (attempt >= RETRIES || !shouldRetry(e.status)) throw e;
      note(t('upload.retrying', { n: index, attempt, max: RETRIES - 1 }));
      await new Promise((r) => setTimeout(r, 500 * attempt));
    }
  }
}

async function upload(blob, target, projectId) {
  const key = resumeKey({ ...target, projectId, size: blob.size, skipExcluded: $('up-skip').checked });
  const map = loadResume(globalThis.localStorage, userId);
  let info = null;
  let have = new Map();
  if (map[key]) {
    try {
      const st = await api.get(`/api/v1/uploads/${map[key]}/status`);
      if (st.status !== 'receiving') {
        // 이미 전송을 마친 업로드: 상태만 이어서 본다
        delete map[key];
        saveResume(globalThis.localStorage, userId, map);
        state.uploadId = st.upload_id;
        return st.upload_id;
      }
      info = st;
      have = receivedMap(st.received);
      note(t('upload.resumed', { have: have.size, total: st.total_chunks }));
    } catch {
      delete map[key];
      info = null;
    }
  }
  if (!info) {
    const init = await api.post(`/api/v1/projects/${projectId}/uploads`, { file_name: target.kind === 'folder' ? `${target.name}.zip` : target.name, total_size: blob.size });
    info = { upload_id: init.upload_id, chunk_size: init.chunk_size, total_chunks: init.total_chunks };
    map[key] = init.upload_id;
    saveResume(globalThis.localStorage, userId, map);
  }
  state.uploadId = info.upload_id;
  buttons();

  const ranges = chunkRanges(blob.size, info.chunk_size);
  const t0 = performance.now();
  let sent = 0;
  let skipped = 0;
  phase('uploading');
  steps('receiving');
  for (const r of ranges) {
    if (state.paused) throw Object.assign(new Error(t('upload.phase.paused')), { paused: true });
    const part = await blob.slice(r.start, r.end).arrayBuffer();
    const sha = await sha256Hex(part);
    if (have.get(r.index) === sha) {
      skipped++;
    } else {
      await putChunk(info.upload_id, r.index, part, sha);
      sent += part.byteLength;
    }
    const done = r.index + 1;
    progress(done, ranges.length);
    const sec = Math.max((performance.now() - t0) / 1000, 0.001);
    setText('up-chunks', t('upload.chunks', { done, total: ranges.length, skipped }));
    setText('up-speed', t('upload.speed', { mb: (sent / 1024 / 1024 / sec).toFixed(1), sec: sec.toFixed(1) }));
  }
  await api.post(`/api/v1/uploads/${info.upload_id}/complete`);
  delete map[key];
  saveResume(globalThis.localStorage, userId, map);
  return info.upload_id;
}

// ---- 완료 후 상태 폴링, 경고 확인(P13) ----

const watchKey = () => `pjg-upload-watch:${userId}`;
function remember(id) {
  try { if (id) globalThis.localStorage.setItem(watchKey(), id); else globalThis.localStorage.removeItem(watchKey()); } catch { /* 저장 불가 */ }
}

/** 단계 표시: done(완료) / current(진행 중) / failed(실패) / warn(확인 필요) */
function steps(status, failedAt = null) {
  const at = failedAt ?? STEP_OF[status] ?? 0;
  for (const li of document.querySelectorAll('#up-steps [data-step]')) {
    const i = STEPS.indexOf(li.dataset.step);
    let st = i < at ? 'done' : i === at ? 'current' : 'todo';
    if (failedAt !== null && i === at) st = 'failed';
    if (status === 'needs_confirmation' && i === at) st = 'warn';
    li.dataset.state = st;
    const box = li.querySelector('.upload-steps__icon');
    clear(box);
    const name = { done: 'analysis_status.succeeded', current: 'analysis_status.running', failed: 'analysis_status.failed', warn: 'system.warn', todo: 'analysis_status.queued' }[st];
    box.appendChild(icon(name, 16));
  }
}

function hideOutcome() {
  $('up-warnings').hidden = true;
  $('up-queued').hidden = true;
  $('up-content').hidden = true;
}

/** P21C: 업로드 내용 요약(정상으로 열림, 파일 수·크기·대상·제외, 직전 업로드 비교, 해시 앞 12자리 + 복사) */
function showSummary(u) {
  const box = $('up-content');
  if (!u.summary) {
    box.hidden = true;
    return;
  }
  const list = $('up-summary-list');
  clear(list);
  for (const row of uploadSummaryRows(u.summary)) {
    list.append(el('dt', { text: t(row.key) }), el('dd', { text: t(row.value.key, row.value.params) }));
  }
  const prev = previousLine(u.summary.previous, (iso) => formatDateTime(iso, tz));
  const p = $('up-summary-previous');
  p.className = `upload-summary__previous notice notice--${prev.tone}`;
  p.dataset.previous = prev.state;
  clear(p);
  p.append(icon(prev.tone === 'warn' ? 'system.warn' : 'ui.info', 16), ' ', t(prev.key, prev.params));
  setText('up-summary-hash', shortHash(u.sha256));
  $('up-summary-hash').title = u.sha256 ?? '';
  state.fullHash = u.sha256 ?? '';
  box.hidden = false;
}

/** 경고 패널(항상 화면에 남김) + 확인 대화상자(처음 한 번). 대화상자를 닫아도 패널의 버튼으로 다시 고를 수 있다 */
/** 경고 문구: "직전과 같은 파일"은 업로드 시각을 표시 시간대로 붙여 화면에서 만든다(P21C) */
function warningText(w, u) {
  const prev = u.summary?.previous;
  if (w.code === 'same_as_previous' && prev) return t('upload.warning_same', { time: formatDateTime(prev.created_at, tz), size: formatBytes(prev.size) });
  return w.message;
}

async function showWarnings(u) {
  const list = $('up-warning-list');
  clear(list);
  for (const w of u.warning_messages ?? []) {
    const li = document.createElement('li');
    li.className = w.danger ? 'upload-warnings__item is-danger' : 'upload-warnings__item';
    li.appendChild(icon(w.danger ? 'ui.danger' : 'system.warn', 16));
    li.appendChild(document.createTextNode(` ${warningText(w, u)}`));
    list.appendChild(li);
  }
  const panel = $('up-warnings');
  panel.classList.toggle('is-danger', (u.warning_messages ?? []).some((w) => w.danger));
  panel.hidden = false;
  if (state.dialogShownFor === u.upload_id) return;
  state.dialogShownFor = u.upload_id;
  const danger = (u.warning_messages ?? []).some((w) => w.danger);
  const ok = await confirmDialog({
    title: t('upload.warning_dialog_title'),
    message: (u.warning_messages ?? []).map((w) => warningText(w, u)).join('\n\n'),
    confirmLabel: t('upload.confirm_continue'),
    cancelLabel: t('upload.decide_later'),
    danger,
    confirmIcon: danger ? 'system.warn' : null,
  });
  if (ok) await confirmUpload(u.upload_id);
}

async function confirmUpload(id) {
  $('up-confirm').disabled = true;
  try {
    const r = await api.post(`/api/v1/uploads/${id}/confirm`);
    $('up-warnings').hidden = true;
    watch(r.upload_id);
  } catch (e) {
    showError(e);
  } finally {
    $('up-confirm').disabled = false;
  }
}

async function cancelWarned(id) {
  const ok = await confirmDialog({
    title: t('upload.confirm_abort_title'),
    message: t('upload.confirm_abort_warned'),
    confirmLabel: t('upload.abort'),
    confirmIcon: 'action.cancel',
    danger: true,
  });
  if (!ok) return;
  try {
    await api.delete(`/api/v1/uploads/${id}`);
    hideOutcome();
    phase('aborted');
    remember(null);
  } catch (e) {
    showError(e);
  }
}

function showQueued(u) {
  setText('up-queued-text', t('upload.queued', { id: u.analysis_id }));
  // P19: 분석 상세(상태 자동 갱신)로 이동
  $('up-project-link').setAttribute('href', `${form.dataset.analysisBase}${u.analysis_id}`);
  $('up-queued').hidden = false;
}

function watch(id) {
  state.poller?.stop?.();
  state.watching = id;
  remember(id);
  hideOutcome();
  $('up-error').hidden = true;
  $('up-error-guide').hidden = true;
  const show = (u) => {
    if (u.sha256) setText('up-sha', u.sha256);
    if (u.status === 'failed') {
      // 실패 단계: 조립본이 없으면 조립에서, 있으면 해제·사전 검사에서
      steps(u.status, u.sha256 ? 2 : 1);
      showError({ message: `${u.error?.title ? `${u.error.title}: ` : ''}${u.error_message ?? t('error.unknown')}` }, u.error);
      remember(null);
      return false;
    }
    if (u.status === 'aborted') { phase('aborted'); remember(null); return false; }
    steps(u.status);
    progress(1, 1);
    if (u.status === 'needs_confirmation' || u.status === 'queued') showSummary(u);
    if (u.status === 'needs_confirmation') {
      phase('needs_confirmation');
      if (!u.confirmed_at) showWarnings(u);
      return false; // 사용자가 고를 때까지 멈춤(확인하면 다시 폴링)
    }
    if (u.status === 'queued') {
      phase('queued');
      showQueued(u);
      remember(null);
      return false;
    }
    phase(['assemble_pending', 'assembling', 'extracting'].includes(u.status) ? u.status : 'processing');
    return true;
  };
  phase('assemble_pending');
  steps('assemble_pending');
  progress(1, 1);
  state.poller = createPoller(async () => show(await api.get(`/api/v1/uploads/${id}`)), { interval: POLL_MS });
  state.poller.start();
}

// ---- 동작 ----

async function start(ev) {
  ev.preventDefault();
  const target = state.target;
  if (!target || state.running) return;
  state.running = true;
  state.paused = false;
  state.aborted = false;
  $('up-error').hidden = true;
  $('up-error-guide').hidden = true;
  note('');
  setText('up-sha', '-');
  buttons();
  const projectId = Number($('up-project').value);
  try {
    phase('preparing');
    let blob;
    if (target.kind === 'file') {
      if (target.size > maxBytes) throw new Error(t('upload.blocked_size', { size: formatBytes(target.size), max: formatBytes(maxBytes) }));
      blob = target.file;
    } else {
      if (target.estimate > maxBytes) throw new Error(t('upload.blocked_size', { size: formatBytes(target.estimate), max: formatBytes(maxBytes) }));
      blob = await zipFolder(target.entries);
    }
    const id = await upload(blob, target, projectId);
    state.uploadId = null; // 전송을 마친 업로드는 취소할 수 없다(서버도 409). 상태만 본다
    note('');
    watch(id);
  } catch (e) {
    if (state.aborted) { phase('aborted'); note(''); } // 취소하는 동안 진행 중이던 요청의 오류는 보이지 않는다
    else if (e?.paused) { phase('paused'); note(t('upload.paused_note')); }
    else { showError(e); }
  } finally {
    state.running = false;
    buttons();
  }
}

async function abort() {
  const id = state.uploadId;
  if (!id) return;
  const ok = await confirmDialog({
    title: t('upload.confirm_abort_title'),
    message: t('upload.confirm_abort'),
    confirmLabel: t('upload.abort'),
    confirmIcon: 'action.cancel',
    danger: true,
  });
  if (!ok) return;
  state.paused = true;
  state.aborted = true;
  state.poller?.stop?.();
  try {
    await api.delete(`/api/v1/uploads/${id}`);
    const map = loadResume(globalThis.localStorage, userId);
    for (const k of Object.keys(map)) if (map[k] === id) delete map[k];
    saveResume(globalThis.localStorage, userId, map);
    state.uploadId = null;
    phase('aborted');
    note('');
  } catch (e) {
    state.aborted = false;
    showError(e);
  } finally {
    buttons();
  }
}

async function init() {
  form = $('upload-form');
  if (!form) return;
  maxBytes = Number(form.dataset.maxBytes);
  userId = Number(form.dataset.userId);
  $('up-file').addEventListener('change', pickFile);
  $('up-folder').addEventListener('change', pickFolder);
  $('up-skip').addEventListener('change', () => (state.target?.handle ? enumerateInto(state.target.handle) : describe()));
  $('up-project').addEventListener('change', () => { state.uploadId = null; buttons(); });
  form.addEventListener('submit', start);
  $('up-pause').addEventListener('click', () => { state.paused = true; });
  $('up-abort').addEventListener('click', abort);
  $('up-clear').addEventListener('click', clearSelection);
  if (typeof globalThis.showDirectoryPicker === 'function') {
    state.pickerMode = 'api';
    $('up-folder-btn').hidden = false;
    $('up-folder-label').hidden = true;
    $('up-folder').hidden = true;
    $('up-folder-btn').addEventListener('click', pickFolderApi);
  } else {
    useInputPicker();
  }
  $('up-confirm').addEventListener('click', () => state.watching && confirmUpload(state.watching));
  $('up-cancel-warning').addEventListener('click', () => state.watching && cancelWarned(state.watching));
  // 새로 고쳐도 처리 중이거나 확인을 기다리는 업로드를 이어서 보여 준다
  try {
    const last = globalThis.localStorage.getItem(watchKey());
    if (last) {
      state.dialogShownFor = null;
      watch(last);
    }
  } catch { /* 저장소 없음 */ }
  // P21C: 해시 복사(전체 64자리)
  $('up-summary-copy')?.addEventListener('click', async () => {
    try {
      await globalThis.navigator.clipboard.writeText(state.fullHash ?? '');
      setText('up-summary-copy-status', t('component.copied'));
    } catch {
      setText('up-summary-copy-status', t('component.copy_failed'));
    }
  });
  try {
    const res = await api.get('/api/v1/projects/default-excludes');
    state.excludes = res.names;
    setText('up-excludes', res.names.join(', '));
  } catch {
    state.excludes = [];
  }
  describe();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
  init();
}
