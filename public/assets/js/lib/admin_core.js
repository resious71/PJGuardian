// 관리자 도구·워커·점검 화면의 순수 함수(P20A). DOM을 만들지 않아 node --test로 시험한다(tests/js/admin.test.mjs).

/** 도구 버전 하나의 상태 표시: [{icon, key, tone}] (아이콘 + 글자, 색에만 의존하지 않음). 여러 개일 수 있다(예: 사용 중 + 고정) */
export function toolBadges(v) {
  const out = [];
  if (v.removed_at) return [{ icon: 'tool_status.removal', key: 'admin_tools.state.removed', tone: 'neutral' }];
  if (v.verify_task && (v.verify_task.status === 'queued' || v.verify_task.status === 'running')) {
    out.push({ icon: 'tool_status.verifying', key: `admin_tools.state.verify_${v.verify_task.status}`, tone: 'info' });
  }
  if (v.removal_requested_at) out.push({ icon: 'tool_status.removal', key: 'admin_tools.state.removal', tone: 'warn' });
  if (v.status === 'blocked') out.push({ icon: 'tool_status.blocked', key: 'admin_tools.state.blocked', tone: 'warn' });
  else if (v.status === 'pinned') out.push({ icon: 'tool_status.pinned', key: 'admin_tools.state.pinned', tone: 'info' });
  if (v.removal_requested_at || v.status === 'blocked') {
    // 차단·삭제 예정은 위 표시로 충분
  } else if (v.status === 'invalid') out.push({ icon: 'system.error', key: 'admin_tools.state.invalid', tone: 'error' });
  else if (!v.supported) out.push({ icon: 'system.warn', key: 'admin_tools.state.unsupported', tone: 'warn' });
  else if (v.status === 'new') out.push({ icon: 'system.warn', key: 'admin_tools.state.new', tone: 'warn' });
  else if (v.fingerprint_changed) out.push({ icon: 'system.warn', key: 'admin_tools.state.changed', tone: 'warn' });
  if (v.selected) out.unshift({ icon: 'system.ok', key: 'admin_tools.state.selected', tone: 'ok' });
  else if (v.unused && !v.removal_requested_at) out.push({ icon: 'tool_status.unused', key: 'admin_tools.state.unused', tone: 'neutral' });
  return out;
}

/** "지금 선택되는 버전과 이유" 한 줄: {key, params} */
export function selectionLine(tool, sel) {
  const p = { tool, version: sel.version ?? '', newest: sel.newest ?? '', pending: sel.pending ?? '' };
  switch (sel.reason) {
    case 'pinned': return { key: 'admin_tools.selection.pinned', params: p };
    case 'latest': return { key: 'admin_tools.selection.latest', params: p };
    case 'fallback': return { key: 'admin_tools.selection.fallback', params: p };
    case 'pinned_unusable': return { key: 'admin_tools.selection.pinned_unusable', params: p };
    case 'pending': return { key: 'admin_tools.selection.pending', params: p };
    default: return { key: 'admin_tools.selection.none', params: p };
  }
}

/** 버전 행에서 보여 줄 동작 목록(서버가 다시 검사한다. 화면은 맞지 않는 버튼을 숨길 뿐) */
export function toolActions(v) {
  if (!v.on_disk || v.removed_at) return [];
  const verifying = v.verify_task && (v.verify_task.status === 'queued' || v.verify_task.status === 'running');
  const out = [];
  if (v.status === 'pinned') out.push('unpin');
  else if (v.status === 'active' && !v.removal_requested_at && v.supported) out.push('pin'); // 검증을 통과한 버전만(서버도 다시 검사)
  if (v.status === 'blocked') out.push('unblock');
  else if (v.status !== 'pinned') out.push('block');
  if (!verifying && v.status !== 'blocked' && v.supported && !v.removal_requested_at) out.push('verify');
  if (v.removal_requested_at) out.push('unmark_removal');
  else if (v.status !== 'pinned') out.push('mark_removal');
  return out;
}

/** 고정하기 전 경고가 필요한가(지금 쓸 수 있는 검증 기록이 없으면 서버가 거부한다) */
export function pinNeedsVerification(v) {
  return !(v.status === 'active' && !v.fingerprint_changed);
}

/** 스모크 결과 메시지에서 "시료별 통과 건수"처럼 보여 줄 짧은 문장. 없으면 '' */
export function smokeSummary(v) {
  if (v.smoke_ok === null || v.smoke_ok === undefined) return '';
  return String(v.smoke_message ?? '');
}

/** 워커 상태 표시 */
export function workerBadge(state) {
  switch (state) {
    case 'running': return { icon: 'system.ok', key: 'admin_worker.state.running', tone: 'ok' };
    case 'stopping': return { icon: 'tool_status.verifying', key: 'admin_worker.state.stopping', tone: 'warn' };
    case 'unresponsive': return { icon: 'system.error', key: 'admin_worker.state.unresponsive', tone: 'error' };
    default: return { icon: 'system.warn', key: 'admin_worker.state.stopped', tone: 'warn' };
  }
}

/** 워커 화면의 버튼 상태: {stop, restart, start, startReason, restartReason} */
export function workerButtons(st) {
  const alive = st.state === 'running' || st.state === 'stopping';
  const pendingStop = st.stop_request !== null && st.stop_request !== undefined;
  return {
    stop: (alive || st.state === 'unresponsive') && !pendingStop,
    restart: st.supervised && alive && !pendingStop,
    restartReason: st.supervised ? null : 'not_supervised',
    startVisible: Boolean(st.start?.configured) && st.start?.reason !== 'proc_open_disabled',
    start: Boolean(st.start?.available),
    startReason: st.start?.reason ?? null,
  };
}

/** 정지 요청 확인 문구 키(진행 중 N건, supervised 여부) */
export function stopConfirm(st, kind) {
  const n = st.running_analyses?.length ?? 0;
  if (kind === 'restart') return { key: 'admin_worker.confirm_restart', params: { n } };
  return { key: st.supervised ? 'admin_worker.confirm_stop_supervised' : 'admin_worker.confirm_stop', params: { n } };
}

/** 마지막 신호로부터 지난 시간 표시: {key, params} */
export function beatAge(seconds, staleAfter) {
  if (seconds === null || seconds === undefined) return { key: 'admin_worker.beat_none', params: {} };
  return { key: seconds > staleAfter ? 'admin_worker.beat_stale' : 'admin_worker.beat_ok', params: { s: seconds, stale: staleAfter } };
}

/** 디스크 막대: 사용률과 상태 */
export function diskLevel(usedPercent, warn, block) {
  if (usedPercent === null || usedPercent === undefined) return { tone: 'warn', icon: 'system.warn', key: 'admin_checks.disk_unknown' };
  if (usedPercent >= block) return { tone: 'error', icon: 'system.error', key: 'admin_checks.disk_block' };
  if (usedPercent >= warn) return { tone: 'warn', icon: 'system.warn', key: 'admin_checks.disk_warn' };
  return { tone: 'ok', icon: 'system.ok', key: 'admin_checks.disk_ok' };
}

/** 구분별 사용량을 큰 순으로 [{key, bytes, files, percent}] (percent = 전체 사용량 대비) */
export function diskCategories(categories) {
  const rows = Object.entries(categories ?? {}).map(([key, v]) => ({ key, bytes: Number(v.bytes) || 0, files: Number(v.files) || 0 }));
  const total = rows.reduce((a, r) => a + r.bytes, 0);
  return rows.sort((a, b) => b.bytes - a.bytes || a.key.localeCompare(b.key))
    .map((r) => ({ ...r, percent: total > 0 ? Math.round((r.bytes / total) * 1000) / 10 : 0 }));
}

/** 스테이징 상태 표시와 설치 안내 가능 여부 */
export function stagingView(s) {
  const status = s.status;
  const hash = s.hash_match === false ? 'mismatch' : (s.hash_match === true ? 'match' : 'none');
  const tone = status === 'install_failed' || hash === 'mismatch' ? 'error' : (status === 'installed' ? 'ok' : (status === 'staged' ? 'info' : 'neutral'));
  return { status, hash, tone, showCommand: typeof s.install_command === 'string' && s.install_command !== '' && hash !== 'mismatch' };
}

/** 관리 작업 상태 → {icon, key} */
export function taskBadge(task) {
  if (!task) return null;
  switch (task.status) {
    case 'queued': return { icon: 'tool_status.verifying', key: 'admin.task.queued' };
    case 'running': return { icon: 'tool_status.verifying', key: 'admin.task.running' };
    case 'succeeded': return { icon: 'system.ok', key: 'admin.task.succeeded' };
    default: return { icon: 'system.error', key: 'admin.task.failed' };
  }
}

/** 경과 초 → {key, params} (시간·분·초) */
export function elapsed(seconds) {
  if (seconds === null || seconds === undefined) return { key: 'admin.elapsed_unknown', params: {} };
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = seconds % 60;
  if (h > 0) return { key: 'admin.elapsed_h', params: { h, m } };
  if (m > 0) return { key: 'admin.elapsed_m', params: { m, s } };
  return { key: 'admin.elapsed_s', params: { s } };
}
