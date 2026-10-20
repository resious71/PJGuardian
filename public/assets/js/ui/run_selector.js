// Run 선택기와 과거 Run 안내(P20R). 결함 탭·결함 상세(코드 뷰어 포함)가 함께 쓴다. el()/textContent로만 만든다.
//   mountRunSelector(host, { api, projectId, run, tz }) → Promise<{ runs, latest, current, status } | null>
//     - 최신순 <select>(고른 값은 select 자체가 스크린 리더에 읽힌다). 마우스로 고르면 바로, 키보드는 화살표로 고른 뒤 Enter로
//       주소의 ?run=N을 바꿔 다시 연다(닫힌 select에서 화살표마다 이동하지 않게)
//       (최신 Run이면 run을 뺀다 → 기존 링크와 같은 주소). Run이 하나뿐이면 읽기 전용 글자.
//     - 번호 없는 성공 분석이 있으면(되채움 전) 안내 문구.
//   pastRunBanner(run, latest, latestHref) → 과거 Run 안내(아이콘 + 글자 + 최신 Run 링크)
import { t } from '../core/i18n.js';
import { el, clear } from '../core/dom.js';
import { icon } from './icons.js';
import { runOptionLabel, withRun } from '../lib/run_core.js';

let uid = 0;

export async function mountRunSelector(host, { api, projectId, run = '', tz, nav = (href) => globalThis.location.assign(href) }) {
  clear(host);
  let res;
  try {
    res = await api.get(`/api/v1/projects/${projectId}/runs`, { query: { size: 200 } });
  } catch (e) {
    host.appendChild(el('p', { class: 'muted', text: t('runs.load_failed') }));
    throw e;
  }
  const runs = res.data;
  const status = res.status ?? { latest: runs[0]?.run ?? null, backfill_needed: false };
  const latest = status.latest ?? runs[0]?.run ?? null;
  const wanted = run === '' ? latest : Number(run);
  const current = runs.find((r) => r.run === wanted && !r.detail_deleted) ? wanted : latest; // 상세가 삭제된 Run은 고를 수 없다(P21B)
  const id = `run-select-${++uid}`;
  const wrap = el('div', { class: 'run-selector' });
  if (runs.length === 0) {
    wrap.appendChild(el('p', { class: 'muted', text: t('runs.none_yet') }));
  } else if (runs.length === 1) {
    wrap.append(
      el('span', { class: 'run-selector__label' }, [icon('ui.run', 16), ' ', t('runs.select_label')]),
      el('span', { class: 'run-selector__value', dataset: { runValue: String(runs[0].run) }, text: t('runs.only_one', { label: runOptionLabel(runs[0], { latest, tz }) }) }),
    );
  } else {
    const select = el('select', { class: 'field__input run-selector__select', id, 'aria-describedby': `${id}-help`, dataset: { runSelect: '' } });
    for (const r of runs) {
      // P21B: 상세가 삭제된 Run은 보이되 고를 수 없다("상세 삭제됨")
      const label = runOptionLabel(r, { latest, tz });
      select.appendChild(el('option', { value: String(r.run), selected: r.run === current, disabled: Boolean(r.detail_deleted), dataset: r.detail_deleted ? { detailDeleted: '' } : {} },
        [r.detail_deleted ? t('runs.detail_deleted_option', { label }) : label]));
    }
    if (res.page.total > runs.length) {
      select.appendChild(el('option', { value: '', disabled: true }, [`… (${res.page.total - runs.length})`]));
    }
    let viaKey = false;
    let leaving = false;
    const go = () => {
      if (leaving || select.value === '') return;
      if (select.value === String(current)) return;
      leaving = true;
      const here = `${globalThis.location.pathname}${globalThis.location.search}${globalThis.location.hash}`;
      nav(withRun(here, select.value, { latest }));
    };
    select.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        go();
      } else if (['ArrowUp', 'ArrowDown', 'Home', 'End', 'PageUp', 'PageDown'].includes(e.key) || e.key.length === 1) {
        viaKey = true;
      }
    });
    select.addEventListener('change', () => {
      if (viaKey) {
        viaKey = false; // 키보드: Enter를 기다린다
        return;
      }
      go();
    });
    wrap.append(
      el('label', { class: 'run-selector__label', for: id }, [icon('ui.run', 16), ' ', t('runs.select_label')]),
      select,
      el('span', { class: 'sr-only', id: `${id}-help`, text: t('runs.select_help') }),
    );
  }
  host.appendChild(wrap);
  if (status.backfill_needed) {
    host.appendChild(el('p', { class: 'notice notice--warn', dataset: { backfillNeeded: '' } }, [icon('system.warn', 16), ' ', t('runs.backfill_needed')]));
  }
  // 상세가 삭제된 Run은 화면이 따로 안내한다(P21B) — "찾을 수 없음" 경고를 겹쳐 띄우지 않는다
  const wantedDeleted = runs.some((r) => r.run === wanted && r.detail_deleted);
  if (run !== '' && current !== wanted && !wantedDeleted) {
    host.appendChild(el('p', { class: 'notice notice--warn', role: 'status' }, [icon('system.warn', 16), ' ', t('runs.invalid')]));
  }
  return { runs, latest, current, status };
}

/** 과거 Run 안내: "Run 3 시점 보기(읽기 전용) — 최신 Run 7로 이동" */
export function pastRunBanner(run, latest, latestHref, doc = globalThis.document) {
  return el('div', { class: 'notice notice--info run-banner', role: 'status', dataset: { runBanner: String(run) } }, [
    el('p', { class: 'run-banner__title' }, [
      icon('ui.read_only', 16, doc), ' ',
      el('strong', { text: t('runs.past_banner', { run }) }, [], doc),
      ' — ',
      el('a', { href: latestHref, dataset: { goLatest: '' } }, [t('runs.go_latest', { run: latest })], doc),
    ], doc),
    el('p', { class: 'run-banner__help', text: t('runs.past_banner_help') }, [], doc),
  ], doc);
}
