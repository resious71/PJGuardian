// 관리자 "저장소 허용 호스트"(P25): 목록·추가·HTTP 허용/GitHub 호환 바꾸기·삭제(확인 창). 값은 textContent로.
// P26B: 행별 "연결 테스트"·표 위 "전체 테스트"(등록된 호스트만, 저장소·자격 증명 없이), 마지막 테스트·인증서 만료(만료 임박 경고) 열,
// 결과 영역(aria-live)에 단계별 체크 목록과 해결 안내. 진행 중에는 버튼을 끄고 글자로 알린다.
import { createApi } from '../core/api.js';
import { formatDateTime, formatDate, timezoneFromDocument } from '../core/time.js';
import { iconWithLabel, icon } from '../ui/icons.js';
import { codeLevel, codeKey, hintKey, steps, lastTestCell, certCell } from '../lib/host_test_core.js';
import { t } from '../core/i18n.js';
import { el, clear } from '../core/dom.js';
import { confirmDialog } from '../ui/dialog.js';
import { errorText } from '../ui/form_dialog.js';

const root = document.querySelector('[data-repo-hosts]');
// X12: 설정 관리 "저장소 연결" 탭의 구역 — 추가 폼에 입력 중인 값이 있으면 저장 전 변경 1건(구역 제목 옆 표시·탭 머리 건수·바깥 탭 이동 확인)
if (root) {
  const form = root.querySelector('[data-host-form]');
  const mark = root.querySelector('[data-merge-dirty]');
  const markDirty = () => {
    const n = form && [...form.elements].some((x) => (x.type === 'checkbox' ? x.checked : (x.value ?? '').trim() !== '' && x.tagName !== 'BUTTON')) ? 1 : 0;
    root.dataset.dirty = String(n);
    if (mark) {
      mark.textContent = n ? t('config_settings.section_dirty', { n }) : '';
      mark.hidden = n === 0;
    }
    document.dispatchEvent(new CustomEvent('config-merge-dirty'));
  };
  form?.addEventListener('input', markDirty);
  form?.addEventListener('change', markDirty);
  form?.addEventListener('reset', () => setTimeout(markDirty, 0));
}
if (root) {
  const api = createApi();
  const $ = (s) => root.querySelector(s);
  const rows = $('[data-host-rows]');
  const error = $('[data-error]');
  const status = $('[data-status]');
  const form = $('[data-host-form]');
  const tz = timezoneFromDocument();
  const fmt = (iso) => formatDateTime(iso, tz);
  const fmtDate = (iso) => formatDate(iso, tz);
  const result = $('[data-host-result]');
  const testAllBtn = $('[data-test-all]');
  let busy = false;

  const STATE_ICON = { ok: 'system.ok', warn: 'system.warn', fail: 'system.error', info: 'ui.info' };

  function showResult(r) {
    const lv = codeLevel(r.code);
    const host = r.host ? `${r.host.host}${r.host.port ? `:${r.host.port}` : ''}` : '';
    const body = $('[data-result-body]');
    clear(body);
    body.appendChild(el('p', { class: `badge badge--tone-${lv.level === 'neutral' ? 'neutral' : lv.level}`, dataset: { code: r.code } }, [iconWithLabel(lv.icon, 16, t(codeKey(r.code)))]));
    const list = el('ul', { class: 'plain-list repo-hosts__steps' });
    for (const st of steps(r, fmtDate)) list.appendChild(el('li', { dataset: { state: st.state } }, [icon(STATE_ICON[st.state], 16), ' ', st.text]));
    body.appendChild(list);
    const hint = hintKey(r.code);
    if (hint) body.appendChild(el('p', { class: 'notice notice--info' }, [icon('ui.info', 16), ' ', t(hint)]));
    body.appendChild(el('p', { class: 'muted', text: t('host_test.took', { ms: r.ms }) }));
    $('[data-result-title]').textContent = t('host_test.result_title', { host });
    result.hidden = false;
  }

  async function runTest(h, btn) {
    if (busy) return;
    busy = true;
    for (const b of root.querySelectorAll('[data-test-host], [data-test-all]')) b.disabled = true;
    const label = btn.textContent;
    btn.textContent = t('host_test.testing');
    status.textContent = t('host_test.testing_host', { host: h.host });
    error.hidden = true;
    let done = null;
    try {
      const r = await api.post(`/api/v1/admin/repo-hosts/${h.id}/test`, {});
      showResult(r);
      done = t('host_test.done', { host: h.host, result: t(codeKey(r.code)) });
    } catch (e) {
      fail(e);
    } finally {
      busy = false;
      btn.textContent = label;
      await load(); // 표(마지막 테스트·인증서 만료)를 먼저 고친 뒤 끝났다고 알린다
      status.textContent = done ?? '';
    }
  }

  testAllBtn?.addEventListener('click', async () => {
    if (busy) return;
    busy = true;
    for (const b of root.querySelectorAll('[data-test-host], [data-test-all]')) b.disabled = true;
    status.textContent = t('host_test.testing_all');
    error.hidden = true;
    try {
      const r = await api.post('/api/v1/admin/repo-hosts/test-all', {});
      const body = $('[data-result-body]');
      clear(body);
      const list = el('ul', { class: 'plain-list repo-hosts__steps' });
      for (const x of r.results) {
        const lv = codeLevel(x.code);
        list.appendChild(el('li', { dataset: { code: x.code } }, [iconWithLabel(lv.icon, 16, `${x.host.host}${x.host.port ? `:${x.host.port}` : ''} — ${t(codeKey(x.code))}`),
          hintKey(x.code) ? el('span', { class: 'muted', text: ` ${t(hintKey(x.code))}` }) : '']));
      }
      body.appendChild(list);
      if (r.truncated > 0) body.appendChild(el('p', { class: 'notice notice--warn' }, [icon('system.warn', 16), ' ', t('host_test.truncated', { max: r.max, rest: r.truncated })]));
      $('[data-result-title]').textContent = t('host_test.all_title', { n: r.tested, total: r.total });
      result.hidden = false;
      status.textContent = t('host_test.all_done', { n: r.tested });
    } catch (e) {
      fail(e);
      status.textContent = '';
    } finally {
      busy = false;
      await load();
    }
  });

  const fail = (e) => {
    error.textContent = errorText(e);
    error.hidden = false;
  };

  function toggle(h, field) {
    const box = el('input', { type: 'checkbox', checked: h[field], 'aria-label': t(`repo_hosts.toggle.${field}`, { host: h.host }), disabled: field === 'github_links' && h.host === 'github.com' });
    box.addEventListener('change', async () => {
      error.hidden = true;
      try {
        await api.patch(`/api/v1/admin/repo-hosts/${h.id}`, { [field]: box.checked });
        status.textContent = t('repo_hosts.updated', { host: h.host });
        await load();
      } catch (e) {
        fail(e);
        box.checked = !box.checked;
      }
    });
    return box;
  }

  function render(list) {
    clear(rows);
    $('[data-empty]').hidden = list.length > 0;
    if (testAllBtn) testAllBtn.disabled = busy || list.length === 0;
    for (const h of list) {
      const del = el('button', { type: 'button', class: 'btn btn--small btn--danger-outline', text: t('repo_hosts.delete') });
      const test = el('button', { type: 'button', class: 'btn btn--small', dataset: { testHost: h.id }, text: t('host_test.test'), 'aria-label': t('host_test.test_aria', { host: h.host }), disabled: busy });
      test.addEventListener('click', () => runTest(h, test));
      del.addEventListener('click', async () => {
        const ok = await confirmDialog({ title: t('repo_hosts.delete'), message: t('repo_hosts.delete_confirm', { host: h.host }), confirmLabel: t('repo_hosts.delete'), danger: true });
        if (!ok) return;
        try {
          await api.delete(`/api/v1/admin/repo-hosts/${h.id}`);
          status.textContent = t('repo_hosts.deleted', { host: h.host });
          await load();
        } catch (e) {
          fail(e);
        }
      });
      rows.appendChild(el('tr', { dataset: { hostId: h.id } }, [
        el('th', { scope: 'row', class: 'mono', text: h.host }),
        el('td', { text: h.port === 0 ? t('repo_hosts.any_port') : String(h.port) }),
        el('td', {}, [toggle(h, 'allow_http')]),
        el('td', {}, [toggle(h, 'github_links')]),
        el('td', { text: h.note || '—' }),
        el('td', { class: 'repo-hosts__last' }, [(() => { const c = lastTestCell(h.last_test, fmt); return iconWithLabel(c.icon, 16, c.text); })()]),
        el('td', {}, [(() => {
          const c = certCell(h.cert, fmtDate);
          return c.warn ? el('span', { class: 'badge badge--tone-warn' }, [iconWithLabel('system.warn', 16, c.text)]) : c.text;
        })()]),
        el('td', { class: 'repo-hosts__actions' }, [test, ' ', del]),
      ]));
    }
  }

  async function load() {
    try {
      render(await api.get('/api/v1/admin/repo-hosts'));
    } catch (e) {
      fail(e);
    }
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    error.hidden = true;
    const f = form.elements;
    const port = f.namedItem('port').value.trim();
    try {
      const h = await api.post('/api/v1/admin/repo-hosts', {
        host: f.namedItem('host').value.trim(), port: port === '' ? 0 : Number(port),
        allow_http: f.namedItem('allow_http').checked, github_links: f.namedItem('github_links').checked, note: f.namedItem('note').value.trim(),
      });
      status.textContent = t('repo_hosts.added', { host: h.host });
      form.reset();
      await load();
    } catch (x) {
      fail(x);
    }
  });

  load();
}
