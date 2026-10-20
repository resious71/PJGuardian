// 프로젝트 편집 화면의 "소스 저장소"(P25, admin): 유형별 입력 항목, 평문 경고, 자격 증명 상태(값은 보이지 않음 — 비우면 유지·입력하면 교체·삭제),
// 저장(개정 번호로 동시 수정 409), 연결 테스트(저장 전 입력으로도, 결과는 role=status), 연결 해제(확인 창 — 주기 분석도 자동으로 꺼짐, P26).
// 저장·테스트·해제 뒤 pjg:repo-changed를 보내 "주기 분석" 영역(P26)이 다시 읽게 한다.
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { confirmDialog } from '../ui/dialog.js';
import { errorText } from '../ui/form_dialog.js';
import { toast } from '../ui/toast.js';
import { fieldsFor, plaintextWarning, isSslPort, secretStatus, buildBody, testResultText } from '../lib/repo_core.js';

const root = document.querySelector('[data-repo-form]');
if (root) {
  const api = createApi();
  const id = root.dataset.projectId;
  const form = root.querySelector('[data-repo-fields]');
  const $ = (s) => root.querySelector(s);
  const err = $('[data-repo-error]');
  const result = $('[data-repo-result]');
  const hostHint = $('[data-host-hint]');
  let view = null;

  const kind = () => form.querySelector('input[name="kind"]:checked')?.value ?? 'none';
  const val = (n) => form.elements.namedItem(n);

  function sync() {
    const k = kind();
    const show = new Set(fieldsFor(k));
    for (const f of root.querySelectorAll('[data-field]')) {
      f.hidden = !show.has(f.dataset.field) || (f.dataset.field === 'fingerprint' && !isSslPort(val('port').value));
    }
    $('[data-path-label-git]').hidden = k !== 'git';
    $('[data-path-label-p4]').hidden = k !== 'perforce';
    $('[data-path-help]').textContent = k === 'perforce' ? t('repo.help.depot_path') : t('repo.help.sub_path');
    $('[data-username-help]').textContent = k === 'perforce' ? t('repo.help.username_p4') : t('repo.help.username_git');
    $('[data-secret-label]').textContent = k === 'perforce' ? t('repo.field.secret_p4') : t('repo.field.secret');
    const warn = plaintextWarning(k, k === 'git' ? val('url').value : val('port').value);
    $('[data-plaintext-warning]').hidden = !warn;
    $('[data-plaintext-text]').textContent = k === 'git' ? t('repo.warn.http') : t('repo.warn.p4_plain');
    const sameKind = view?.configured && view.kind === k;
    $('[data-secret-state]').textContent = sameKind ? secretStatus(view) : t('repo.secret_none');
    $('[data-clear-secret-wrap]').hidden = !(sameKind && view.secret_set);
    $('[data-repo-test]').disabled = k === 'none';
  }

  function fill(v) {
    view = v;
    $('[data-repo-key-missing]').hidden = v.key_available !== false;
    for (const r of form.querySelectorAll('input[name="kind"]')) r.checked = r.value === (v.kind ?? 'none');
    val('url').value = v.kind === 'git' ? v.location ?? '' : '';
    val('port').value = v.kind === 'perforce' ? v.location ?? '' : '';
    val('branch').value = v.branch ?? '';
    val('path').value = v.path ?? '';
    val('username').value = v.username ?? '';
    val('fingerprint').value = v.fingerprint ?? '';
    val('fetch_timeout').value = v.fetch_timeout ? String(Math.round(v.fetch_timeout / 60)) : '';
    val('secret').value = '';
    val('clear_secret').checked = false;
    for (const r of form.querySelectorAll('input[name="secret_kind"]')) r.checked = r.value === (v.secret_kind === 'ticket' ? 'ticket' : 'password');
    $('[data-repo-delete]').hidden = !v.configured;
    hostHint.hidden = !(v.configured && v.host_allowed === false);
    $('[data-host-hint-text]').textContent = t('repo.host_not_listed_saved');
    sync();
  }

  function formValues() {
    return {
      kind: kind(), url: val('url').value, port: val('port').value, branch: val('branch').value, path: val('path').value,
      username: val('username').value, secret: val('secret').value, fingerprint: val('fingerprint').value, fetch_timeout: val('fetch_timeout').value,
      secret_kind: form.querySelector('input[name="secret_kind"]:checked')?.value ?? 'password', clear_secret: val('clear_secret').checked,
    };
  }

  function showError(e) {
    err.textContent = errorText(e);
    err.hidden = false;
    if (e?.code === 'repo_host_not_listed') {
      $('[data-host-hint-text]').textContent = t('repo.host_not_listed', { host: e.details?.host ?? '' });
      hostHint.hidden = false;
    }
  }

  async function load() {
    try {
      fill(await api.get(`/api/v1/projects/${id}/repo`));
    } catch (e) {
      showError(e);
    }
  }

  form.addEventListener('change', sync);
  form.addEventListener('input', (e) => { if (['url', 'port'].includes(e.target.name)) sync(); });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    err.hidden = true;
    hostHint.hidden = true;
    result.textContent = '';
    const v = formValues();
    try {
      if (v.kind === 'none') {
        if (view?.configured) await disconnect();
        return;
      }
      const body = buildBody({ ...v, secret_kind: v.secret_kind }, view?.configured ? view.revision : null);
      if (v.kind === 'perforce') body.secret_kind = v.secret_kind;
      fill(await api.put(`/api/v1/projects/${id}/repo`, body));
      document.dispatchEvent(new CustomEvent('pjg:repo-changed')); // P26: 주기 분석 영역이 켜기 조건을 다시 읽는다
      result.textContent = t('repo.saved');
      toast(t('repo.saved'), { type: 'success' });
    } catch (x) {
      showError(x);
      if (x?.code === 'repo_conflict') await load();
    }
  });

  $('[data-repo-test]').addEventListener('click', async () => {
    const btn = $('[data-repo-test]');
    btn.disabled = true;
    btn.setAttribute('aria-busy', 'true');
    err.hidden = true;
    result.textContent = t('repo.testing');
    try {
      const v = formValues();
      const draft = buildBody(v, null);
      if (v.kind === 'perforce') draft.secret_kind = v.secret_kind;
      const r = await api.post(`/api/v1/projects/${id}/repo/test`, { draft });
      result.textContent = testResultText(r);
      result.dataset.ok = r.ok ? '1' : '0';
      document.dispatchEvent(new CustomEvent('pjg:repo-changed'));
    } catch (x) {
      result.textContent = '';
      showError(x);
    } finally {
      btn.disabled = false;
      btn.removeAttribute('aria-busy');
    }
  });

  async function disconnect() {
    const ok = await confirmDialog({ title: t('repo.disconnect'), message: t('repo.disconnect_confirm'), confirmLabel: t('repo.disconnect'), danger: true });
    if (!ok) return;
    await api.delete(`/api/v1/projects/${id}/repo`);
    toast(t('repo.disconnected'), { type: 'success' });
    await load();
    document.dispatchEvent(new CustomEvent('pjg:repo-changed')); // 주기 분석은 서버가 자동으로 껐다
  }
  $('[data-repo-delete]').addEventListener('click', async () => {
    try {
      await disconnect();
    } catch (x) {
      showError(x);
    }
  });

  load();
}
