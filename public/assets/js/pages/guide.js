// 가이드 화면(P29). 사용자·관리자 가이드: API에서 절을 받아 목차·본문(안전한 마크다운 렌더러 — textContent만)·검색(절 목록 + 일치 어구 요약·목차 표시·본문 강조)·
// 절 단위 페이지(X11 — 해시 #key[/<소제목>]로 한 번에 한 절만, 새로고침 없이 바꾸고 제목에 포커스·문서 제목 갱신)·이전/다음·맨 위로, 이미지는 guide-img: → /guide/img/<audience>/<slug>(누르면 원본 대화상자, Esc로 닫고 초점 복귀).
// 오류 코드 탭: 카탈로그 표(#코드로 그 줄 강조, 관련 가이드 절 링크). 라이선스 탭: 글자 검색(강조·건수).
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { el, clear } from '../core/dom.js';
import { icon, iconWithLabel } from '../ui/icons.js';
import { errorText } from '../ui/form_dialog.js';
import { renderMarkdown } from '../lib/markdown.js';
import { normalizeQuery, highlightSegments, guideHref, imageSlug, sectionFromHash, neighbors, parseHash, headingSlug, plainText, snippetAround } from '../lib/guide_core.js';

const api = createApi();
const base = document.querySelector('meta[name="app-base"]')?.getAttribute('content') ?? '';
const $ = (sel, root = document) => root.querySelector(sel);

/** 글자 노드 안의 검색어를 <mark>로(노드를 새로 만들어 넣음 — HTML 해석 없음) */
function markText(root, query) {
  for (const m of root.querySelectorAll('mark.guide-mark')) m.replaceWith(document.createTextNode(m.textContent));
  root.normalize();
  if (!normalizeQuery(query)) return 0;
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
  const nodes = [];
  for (let n = walker.nextNode(); n; n = walker.nextNode()) {
    if (!n.parentElement?.closest('.guide-toc-skip')) nodes.push(n);
  }
  let count = 0;
  for (const n of nodes) {
    const parts = highlightSegments(n.textContent, query);
    if (parts.length === 1 && !parts[0].mark) continue;
    const frag = document.createDocumentFragment();
    for (const p of parts) {
      if (p.mark) count += 1;
      frag.append(p.mark ? el('mark', { class: 'guide-mark', text: p.text }) : document.createTextNode(p.text));
    }
    n.replaceWith(frag);
  }
  return count;
}

function guidePage(root) {
  const audience = root.dataset.audience;
  const body = $('[data-guide-body]', root);
  const toc = $('[data-guide-toc]', root);
  const search = $('[data-guide-search]', root);
  const status = $('[data-guide-search-status]', root);
  const results = $('[data-guide-results]', root);
  const live = $('[data-guide-live]', root);
  const dialog = $('[data-guide-img-dialog]');
  const baseTitle = document.title;
  let sections = [];
  let keys = [];
  let images = {};
  let opener = null;
  let current = null;
  let query = '';
  let scrollToMark = false;

  const imageNode = (alt, src) => {
    const slug = imageSlug(src);
    if (!slug || !alt.trim()) return null;
    const size = images[slug] ?? {};
    const url = `${base}/guide/img/${audience}/${slug}`;
    // 폭·높이 속성으로 자리를 미리 잡는다(레이아웃 흔들림 없음), 화면에서는 CSS max-width:100%
    const img = el('img', { src: url, alt, loading: 'lazy', class: 'guide-img', ...(size.width ? { width: size.width, height: size.height } : {}) });
    const btn = el('button', { type: 'button', class: 'guide-img-btn', 'aria-label': t('guide.image_open', { alt }), dataset: { guideImg: slug } }, [img]);
    btn.addEventListener('click', () => {
      opener = btn;
      const full = $('[data-guide-img-full]', dialog);
      full.src = url;
      full.alt = alt;
      dialog.showModal();
      $('[data-guide-img-close]', dialog).focus();
    });
    return btn;
  };
  dialog?.addEventListener('close', () => { opener?.focus(); opener = null; });
  $('[data-guide-img-close]', dialog ?? document)?.addEventListener('click', () => dialog.close());

  const opts = {
    headingBase: 3,
    link: (href) => guideHref(href, { base, audience }),
    image: imageNode,
    // {{icon:<이름>.bare}}: 바로 뒤에 단추 이름 글자가 있어 꾸밈 아이콘만(서버 GuideContent::BARE)
    token: (name) => (name.endsWith('.bare') ? icon(name.slice(0, -5), 16) : iconWithLabel(name, 16)),
  };
  const byKey = (k) => sections.find((x) => x.key === k);

  /** 절 하나를 본문 전체로(X11 — 한 번에 한 절만 DOM에). 화면 밖에서 다 만든 뒤 한 번에 바꿔 깜빡임을 줄인다 */
  function buildSection(s) {
    const md = el('div', { class: 'guide-md' }, renderMarkdown(s.body, opts));
    // X10: {{legend:icons}} 한 줄 → 아이콘 범례(서버가 레지스트리에서 그린 숨김 원본을 복제 — 사용자 가이드 "화면 읽는 법" 절에만)
    for (const p of md.querySelectorAll('p')) {
      if (p.textContent.trim() !== '{{legend:icons}}') continue;
      const src = document.querySelector('[data-guide-legend-source] .icon-legend');
      if (src) p.replaceWith(el('div', { class: 'guide-legend', dataset: { guideLegend: '' } }, [src.cloneNode(true)]));
      else p.remove();
    }
    // 소제목(##→h3, ###→h4)에 앵커 id(#key/<slug>로 바로 이동), 3개 이상이면 "이 절의 내용"
    const used = new Set();
    const subs = [];
    for (const h of md.querySelectorAll('h3.md-heading, h4.md-heading')) {
      const slug = headingSlug(h.textContent, used);
      h.id = `guide-${s.key}--${slug}`;
      h.tabIndex = -1;
      // X13: 범례 카테고리 제목(data-toc="skip")은 "이 절의 내용"에 넣지 않는다(본문·#key/<slug> 이동은 그대로)
      if (h.tagName === 'H3' && h.dataset.toc !== 'skip') subs.push({ slug, text: h.textContent });
    }
    const contents = subs.length >= 3 ? el('nav', { class: 'guide-section__contents guide-toc-skip', 'aria-label': t('guide.contents') }, [
      el('p', { class: 'guide-section__contents-title', text: t('guide.contents') }),
      el('ul', {}, subs.map((x) => el('li', {}, [el('a', { href: `#${encodeURIComponent(s.key)}/${encodeURIComponent(x.slug)}` }, [x.text])]))),
    ]) : null;
    const { prev, next } = neighbors(keys, s.key);
    const navBtn = (k, label) => (k
      ? el('a', { href: `#${k}`, class: 'btn btn--small', dataset: { nav: label } }, [t(`guide.${label}`, { title: byKey(k).title })])
      : el('button', { type: 'button', class: 'btn btn--small', disabled: true, dataset: { nav: label } }, [t(`guide.${label}_none`)]));
    const related = s.related.filter((k) => keys.includes(k));
    return el('article', { class: 'guide-section', id: `guide-${s.key}`, dataset: { key: s.key, lang: s.lang }, 'aria-labelledby': `guide-h-${s.key}` }, [
      el('h2', { id: `guide-h-${s.key}`, class: 'guide-section__title', tabindex: '-1', text: s.title }),
      s.fallback ? el('p', { class: 'notice notice--info guide-toc-skip', dataset: { fallback: '' } }, [iconWithLabel('ui.info', 16, t('guide.translation_pending'))]) : null,
      el('p', { class: 'guide-section__summary muted', text: s.summary }),
      contents,
      md,
      related.length ? el('p', { class: 'guide-section__related guide-toc-skip' }, [
        `${t('guide.related')} `,
        ...related.flatMap((k, j) => [j ? ', ' : '', el('a', { href: `#${k}` }, [byKey(k).title])]),
      ]) : null,
      el('nav', { class: 'guide-section__nav guide-toc-skip', 'aria-label': t('guide.section_nav') }, [
        navBtn(prev, 'prev'),
        el('a', { href: '#main', class: 'btn btn--small', dataset: { top: '' } }, [t('guide.top')]),
        navBtn(next, 'next'),
      ]),
    ].filter(Boolean));
  }

  /**
   * X13: 범례 표 10개를 같은 열 폭·같은 전체 폭으로 — 모든 표의 가장 긴 칸(줄바꿈 없는 자연 폭)에 여백을 더한 값을 CSS 변수로 모든 표에 똑같이.
   * 화면에 붙인 뒤에 잰다(지금 화면 언어 기준). 좁으면 표 영역만 가로 스크롤(.icon-legend__scroll)
   */
  function sizeLegend(article) {
    const legend = article.querySelector('[data-guide-legend]');
    if (!legend) return;
    legend.classList.remove('guide-legend--sized');
    const max = [0, 0, 0, 0];
    for (const row of legend.querySelectorAll('tr')) {
      [...row.cells].forEach((c, i) => { if (i < 4) max[i] = Math.max(max[i], c.scrollWidth); });
    }
    const pad = [12, 16, 16, 16]; // 아이콘 열은 큰 아이콘 좌우 여백, 글자 열은 넉넉한 좌우 여백(P29C: 영어 1280px에서 표가 본문 폭을 22px 넘어 프롬프트의 하한까지 줄임)
    max.forEach((w, i) => legend.style.setProperty(`--legend-col-${i + 1}`, `${Math.ceil(w + pad[i])}px`));
    legend.classList.add('guide-legend--sized');
  }

  /** 해시 → 절 열기. 같은 절 안 소제목 이동은 다시 그리지 않는다 */
  function show({ focus = true } = {}) {
    const { key, sub } = parseHash(location.hash);
    let target = keys.includes(key) ? key : keys[0];
    const unknown = key !== '' && !keys.includes(key);
    if (unknown) {
      // 없는 key: 첫 절로(주소도 고쳐 북마크·뒤로 가기에 남지 않게) + 안내 한 줄
      history.replaceState(null, '', `${location.pathname}${location.search}#${target}`);
    }
    if (!target) return;
    const s = byKey(target);
    if (current !== target) {
      const article = buildSection(s);
      if (unknown) article.insertBefore(el('p', { class: 'notice notice--warn', role: 'status', dataset: { unknownSection: '' }, text: t('guide.unknown_section') }), article.children[1]);
      body.replaceChildren(article);
      sizeLegend(article);
      current = target;
      document.title = `${s.title} - ${baseTitle}`;
      for (const a of toc.querySelectorAll('a')) {
        if (a.dataset.key === target) a.setAttribute('aria-current', 'page');
        else a.removeAttribute('aria-current');
      }
      if (query) markText(article, query);
      // X10: 바닥글의 "아이콘의 뜻" 링크는 범례가 있는 절에서는 숨긴다
      const legendLink = document.querySelector('[data-guide-legend-link-wrap]');
      if (legendLink) legendLink.hidden = Boolean(article.querySelector('[data-guide-legend]'));
      if (live && !focus) live.textContent = s.title; // 포커스를 옮기지 않을 때만 알림(포커스 이동이 제목을 읽어 줌)
    }
    const subEl = sub ? document.getElementById(`guide-${target}--${sub}`) : null;
    const firstMark = scrollToMark ? body.querySelector('mark.guide-mark') : null;
    scrollToMark = false;
    if (subEl) {
      subEl.scrollIntoView({ block: 'start' });
      if (focus) subEl.focus({ preventScroll: true });
    } else if (firstMark) {
      firstMark.scrollIntoView({ block: 'center' });
      if (focus) $('.guide-section__title', body)?.focus({ preventScroll: true });
    } else if (focus) {
      root.scrollIntoView({ block: 'start' });
      $('.guide-section__title', body)?.focus({ preventScroll: true });
    }
  }

  async function load() {
    try {
      const d = await api.get(`/api/v1/guide/${audience}`);
      images = d.images ?? {};
      sections = d.sections;
      keys = sections.map((s) => s.key);
      clear(toc);
      for (const s of sections) {
        toc.append(el('li', { dataset: { key: s.key } }, [el('a', { href: `#${s.key}`, dataset: { key: s.key } }, [
          el('span', { class: 'guide__toc-title', text: s.title }),
          el('span', { class: 'guide__toc-hits', dataset: { hits: '' }, hidden: true }),
        ])]));
      }
      // 처음 열 때: 해시가 있으면(도움말 링크·오류 "자세히 보기"·북마크) 그 절에 포커스, 없으면 첫 절을 포커스 없이
      show({ focus: location.hash !== '' });
    } catch (e) {
      clear(body);
      body.append(el('p', { class: 'notice notice--error', role: 'alert', text: errorText(e) }));
    }
  }

  // 해시: 목차·관련 링크·이전/다음·검색 결과·오류 화면의 "자세히 보기"·뒤로/앞으로 가기
  window.addEventListener('hashchange', () => { if (keys.length) show(); });
  body.addEventListener('click', (ev) => {
    const a = ev.target.closest('a[data-top]');
    if (a) {
      ev.preventDefault();
      window.scrollTo({ top: 0 });
      search.focus();
    }
  });
  // 목차 키보드: 위/아래 화살표·Home·End로 항목 이동, Enter로 열기(링크 기본 동작)
  toc.addEventListener('keydown', (ev) => {
    const links = [...toc.querySelectorAll('a')];
    const i = links.indexOf(document.activeElement);
    if (i === -1) return;
    const to = { ArrowDown: i + 1, ArrowUp: i - 1, Home: 0, End: links.length - 1 }[ev.key];
    if (to === undefined) return;
    ev.preventDefault();
    links[Math.max(0, Math.min(links.length - 1, to))].focus();
  });

  // 검색: 서버가 절을 찾고(관리자 가이드는 관리자 가이드 안에서만), 결과는 절 목록(일치 어구 요약) — 누르면 그 절을 열어 강조·첫 일치로
  let timer = null;
  let seq = 0;
  async function runSearch() {
    const q = normalizeQuery(search.value);
    const my = ++seq;
    for (const li of toc.querySelectorAll('li')) {
      li.classList.remove('guide__toc-match', 'guide__toc-miss');
      li.querySelector('[data-hits]').hidden = true;
    }
    query = '';
    clear(results);
    results.hidden = true;
    markText(body, '');
    if (!q) {
      status.textContent = search.value.trim() ? t('guide.search_short') : '';
      return;
    }
    try {
      const d = await api.get(`/api/v1/guide/${audience}/search`, { query: { q } });
      if (my !== seq) return;
      query = q;
      const found = new Map(d.results.map((r) => [r.key, r.hits]));
      for (const li of toc.querySelectorAll('li')) {
        const n = found.get(li.dataset.key);
        li.classList.add(n ? 'guide__toc-match' : 'guide__toc-miss');
        const hits = li.querySelector('[data-hits]');
        hits.textContent = n ? t('guide.hits', { n }) : '';
        hits.hidden = !n;
      }
      for (const r of d.results) {
        const s = byKey(r.key);
        const snip = s ? snippetAround(plainText(s.body), q) || snippetAround(s.summary, q) || s.summary : r.summary;
        const p = el('p', { class: 'guide__result-snippet muted' });
        for (const part of highlightSegments(snip, q)) p.append(part.mark ? el('mark', { class: 'guide-mark', text: part.text }) : document.createTextNode(part.text));
        const a = el('a', { href: `#${r.key}`, dataset: { result: r.key } }, [el('span', { class: 'guide__result-title', text: r.title }), ` (${t('guide.hits', { n: r.hits })})`]);
        a.addEventListener('click', () => {
          scrollToMark = true;
          if (current === r.key && sectionFromHash(location.hash, keys) === r.key) {
            // 이미 열린 절: 해시가 그대로라 hashchange가 없다 — 직접 첫 일치로
            setTimeout(() => show(), 0);
          }
        });
        results.append(el('li', {}, [a, p]));
      }
      results.hidden = d.results.length === 0;
      const article = $('article.guide-section', body);
      if (article) markText(article, q);
      status.textContent = d.results.length ? t('guide.search_found', { n: d.results.length }) : t('guide.search_none');
    } catch (e) {
      status.textContent = errorText(e);
    }
  }
  search.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(runSearch, 250);
  });
  search.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape' && search.value !== '') {
      ev.preventDefault();
      search.value = '';
      runSearch();
    } else if (ev.key === 'Enter') {
      ev.preventDefault();
      results.querySelector('a')?.click();
    }
  });

  load();
}

async function errorsPage(root) {
  const box = $('[data-guide-errors-body]', root);
  try {
    const d = await api.get('/api/v1/guide/errors');
    clear(box);
    for (const g of d.groups) {
      box.append(el('section', { class: 'guide-errors__group', 'aria-labelledby': `ge-${g.kind}` }, [
        el('h3', { id: `ge-${g.kind}`, text: g.title }),
        el('p', {}, [el('a', { href: `${base}/guide/user#${g.guide_key}`, dataset: { guideLink: g.guide_key } }, [t('guide.errors_section_link')])]),
        el('div', { class: 'table-wrap' }, [el('table', { class: 'table guide-errors__table' }, [
          el('thead', {}, [el('tr', {}, ['code', 'title', 'cause', 'action'].map((c) => el('th', { scope: 'col', text: t(`guide.errors_col.${c}`) })))]),
          el('tbody', {}, g.items.map((it) => el('tr', { id: it.code, dataset: { code: it.code } }, [
            el('td', {}, [el('code', { text: it.code })]),
            el('td', { text: it.title }),
            el('td', { text: it.cause }),
            el('td', { text: it.action }),
          ]))),
        ])]),
      ]));
    }
    const focusRow = () => {
      for (const r of box.querySelectorAll('tr.guide-errors__row--target')) r.classList.remove('guide-errors__row--target');
      const code = decodeURIComponent(location.hash.replace(/^#/, ''));
      const row = code ? document.getElementById(code) : null;
      if (row?.dataset.code) {
        row.classList.add('guide-errors__row--target');
        row.setAttribute('tabindex', '-1');
        row.scrollIntoView({ block: 'center' });
        row.focus({ preventScroll: true });
      }
    };
    window.addEventListener('hashchange', focusRow);
    focusRow();
  } catch (e) {
    clear(box);
    box.append(el('p', { class: 'notice notice--error', role: 'alert', text: errorText(e) }));
  }
}

function licensesPage() {
  const pre = $('[data-licenses]');
  const input = $('[data-licenses-search]');
  const status = $('[data-licenses-status]');
  let timer = null;
  const run = () => {
    const n = markText(pre, input.value);
    status.textContent = normalizeQuery(input.value) ? (n ? t('guide.search_found_marks', { n }) : t('guide.search_none')) : '';
    pre.querySelector('mark.guide-mark')?.scrollIntoView({ block: 'center' });
  };
  input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(run, 200); });
  input.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape' && input.value !== '') { ev.preventDefault(); input.value = ''; run(); }
  });
}

const guideRoot = $('[data-guide]');
if (guideRoot) guidePage(guideRoot);
const errorsRoot = $('[data-guide-errors]');
if (errorsRoot) errorsPage(errorsRoot);
if ($('[data-licenses]')) licensesPage();
