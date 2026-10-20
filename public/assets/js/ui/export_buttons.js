// 내보내기 버튼(P23, 결함 목록·내게 지정된 결함·규칙 설명 목록 공통). 서버가 그린 [data-export] 상자의 두 버튼을 연결한다.
// 동작: fetch POST(CSRF 헤더) → 성공이면 응답 blob을 a[download]로 내려받음(파일 이름은 응답 Content-Disposition에서), 오류면 코드별 안내.
// 진행 중에는 두 버튼을 끄고 aria-busy, 연타해도 요청 하나. 보낼 조건은 화면이 마지막으로 적용한 조건(getBody) — 폼의 미적용 변경은 보내지 않는다.
import { t } from '../core/i18n.js';
import { meta } from '../core/dom.js';
import { toast } from './toast.js';
import { filenameFromDisposition, exportErrorText, exportNote, exportEnabled } from '../lib/export_core.js';

/**
 * @param {HTMLElement} box [data-export] 상자(버튼 [data-export-format], 안내 [data-export-note])
 * @param {{ url: string, getBody: () => object }} opts
 * @returns {{ update: (s: { total: number, dirty?: boolean, run?: number|null, hidden?: boolean }) => void }}
 */
export function mountExport(box, { url, getBody, doc = globalThis.document, fetchImpl = globalThis.fetch?.bind(globalThis) }) {
  const buttons = [...box.querySelectorAll('[data-export-format]')];
  const note = box.querySelector('[data-export-note]');
  let busy = false;
  let enabled = false;

  function sync() {
    for (const b of buttons) {
      b.disabled = busy || !enabled;
      b.title = enabled ? '' : t('export.empty_tip');
    }
    box.toggleAttribute('aria-busy', busy);
  }

  async function run(format) {
    if (busy || !enabled) return;
    busy = true;
    sync();
    const base = meta('app-base', doc) ?? '';
    try {
      let res;
      try {
        res = await fetchImpl(`${base}${url}`, {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json, */*', 'X-CSRF-Token': meta('csrf-token', doc) ?? '' },
          body: JSON.stringify({ format, ...getBody() }),
        });
      } catch {
        toast(exportErrorText({ code: 'network' }), { type: 'error', doc });
        return;
      }
      if (!res.ok) {
        let err = null;
        try { err = (await res.json())?.error ?? null; } catch { err = null; }
        toast(exportErrorText(err), { type: 'error', doc });
        return;
      }
      const name = filenameFromDisposition(res.headers.get('Content-Disposition'), `export.${format}`);
      const blob = await res.blob();
      const href = URL.createObjectURL(blob);
      const a = doc.createElement('a');
      a.href = href;
      a.download = name;
      a.hidden = true;
      doc.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(() => URL.revokeObjectURL(href), 30000)?.unref?.(); // 내려받기가 시작될 시간을 둔 뒤 정리(Node 시험에서는 프로세스를 붙잡지 않음)
      const rows = Number(res.headers.get('X-Export-Rows'));
      toast(Number.isFinite(rows) && rows > 0 ? t('export.done', { n: rows.toLocaleString('ko-KR') }) : t('export.done_file', { file: name }), { type: 'success', doc });
    } finally {
      busy = false;
      sync();
    }
  }

  for (const b of buttons) b.addEventListener('click', () => run(b.dataset.exportFormat));
  sync();
  return {
    update({ total, dirty = false, run: runNo = null, hidden = false }) {
      enabled = exportEnabled(total);
      box.hidden = hidden;
      if (note) {
        note.textContent = exportNote({ dirty, run: runNo });
        note.hidden = note.textContent === '';
      }
      sync();
    },
  };
}
