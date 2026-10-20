// 가이드 > FAQ 탭(P29B). 목록·검색은 GET /api/v1/faq(검색은 검색 단추·Enter로만 — 입력만으로 요청하지 않음, P21V 방식),
// 분류별 묶음·질문을 누르면 답변 펼침(aria-expanded), 항목 주소 #faq-<id>(열면 펼쳐 보이고 링크 복사), 답변은 안전한 마크다운 렌더러(textContent만).
// 편집(추가·수정·삭제·순서·공개 전환)은 admin에게만 만든다(data-admin="1" — 일반 사용자 DOM에는 편집 요소가 없음, 서버도 쓰기 API를 다시 검사).
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { el, clear } from '../core/dom.js';
import { formatDateTime, timezoneFromDocument } from '../core/time.js';
import { icon, iconWithLabel } from '../ui/icons.js';
import { confirmDialog } from '../ui/dialog.js';
import { errorText } from '../ui/form_dialog.js';
import { toast } from '../ui/toast.js';
import { renderMarkdown } from '../lib/markdown.js';
import { LIMITS, anchorId, idFromHash, groupByCategory, moveIds, validateDraft, charCount, isDirty, faqHref, searchActive, oneLine } from '../lib/faq_core.js';

const api = createApi();
const base = document.querySelector('meta[name="app-base"]')?.getAttribute('content') ?? '';
const $ = (sel, r = document) => r.querySelector(sel);

const root = $('[data-faq]');
if (root && root.dataset.ready === '1') faqPage(root);

function faqPage(box) {
  const isAdmin = box.dataset.admin === '1';
  const tz = timezoneFromDocument();
  const list = $('[data-faq-list]', box);
  const status = $('[data-faq-status]', box);
  const error = $('[data-faq-error]', box);
  const form = $('[data-faq-search]', box);
  const qInput = $('#faq-q', box);
  const catSelect = $('[data-faq-category]', box);
  let items = [];
  let categories = [];
  let applied = { q: '', category: '' };
  const expanded = new Set();
  let firstLoad = true;

  const md = (text) => renderMarkdown(text, { headingBase: 4, link: (href) => faqHref(href, base) });

  async function load() {
    error.hidden = true;
    try {
      const d = await api.get('/api/v1/faq', { query: { q: applied.q, category: applied.category } });
      items = d.items;
      categories = d.categories;
      fillCategories();
      render();
      if (firstLoad) {
        firstLoad = false;
        openFromHash(false);
      }
    } catch (e) {
      clear(list);
      error.textContent = errorText(e);
      error.hidden = false;
    }
  }

  function fillCategories() {
    const keep = catSelect.value;
    while (catSelect.options.length > 1) catSelect.remove(1);
    for (const c of categories) catSelect.append(el('option', { value: c, text: c }));
    catSelect.value = categories.includes(keep) ? keep : '';
  }

  function render() {
    clear(list);
    const searching = searchActive(applied.q, applied.category);
    if (searching) for (const it of items) expanded.add(it.id); // 검색 결과는 펼쳐서 보인다
    status.textContent = searching ? (items.length ? t('faq.results', { n: items.length }) : '') : '';
    if (!items.length) {
      if (searching) {
        list.append(el('p', { class: 'muted', dataset: { faqEmpty: 'search' }, text: t('faq.no_results') }));
      } else if (isAdmin) {
        const add = el('button', { type: 'button', class: 'btn btn--primary', dataset: { faqAddFirst: '' } }, [iconWithLabel('action.add', 16, t('faq.add_first'))]);
        add.addEventListener('click', () => editDialog(null));
        list.append(el('div', { class: 'faq__empty', dataset: { faqEmpty: 'admin' } }, [el('p', { text: t('faq.empty_admin') }), add]));
      } else {
        list.append(el('p', { class: 'muted', dataset: { faqEmpty: 'user' }, text: t('faq.empty_user') }));
      }
      return;
    }
    for (const g of groupByCategory(items)) {
      const gid = `faq-cat-${list.children.length}`;
      list.append(el('section', { class: 'faq-group', 'aria-labelledby': gid, dataset: { faqCategory: g.category } }, [
        el('h3', { id: gid, class: 'faq-group__title' }, [g.category, ' ', el('span', { class: 'muted faq-group__count', text: t('faq.count', { n: g.items.length }) })]),
        el('ul', { class: 'faq-items' }, g.items.map((it, i) => itemNode(it, g, i, searching))),
      ]));
    }
  }

  function itemNode(it, group, index, searching) {
    const open = expanded.has(it.id);
    const panelId = `faq-a-${it.id}`;
    const toggle = el('button', { type: 'button', class: 'faq-item__q', 'aria-expanded': String(open), 'aria-controls': panelId, dataset: { faqToggle: it.id } }, [
      el('span', { class: 'faq-item__marker', 'aria-hidden': 'true' }), el('span', { class: 'faq-item__text', text: it.question }),
    ]);
    toggle.addEventListener('click', () => setOpen(it.id, !expanded.has(it.id)));
    const head = [toggle];
    if (isAdmin && it.is_published === false) head.push(el('span', { class: 'badge faq-item__draft', dataset: { faqDraft: '' } }, [iconWithLabel('ui.draft', 16)]));
    const copy = el('button', { type: 'button', class: 'btn btn--small', dataset: { faqCopy: it.id }, 'aria-label': t('faq.copy_link_aria', { q: it.question }) },
      [iconWithLabel('action.copy_link', 16, t('faq.copy_link'))]);
    copy.addEventListener('click', () => copyLink(it.id));
    const tools = [copy];
    if (isAdmin) tools.push(...adminTools(it, group, index, searching));
    const panel = el('div', { class: 'faq-item__a', id: panelId, hidden: !open, dataset: { faqAnswer: it.id } }, [
      el('div', { class: 'faq-item__body md' }, md(it.answer)),
      isAdmin && it.updated_by_name ? el('p', { class: 'muted faq-item__meta', text: t('faq.updated_by', { name: it.updated_by_name, time: formatDateTime(it.updated_at, tz) }) }) : null,
    ].filter(Boolean));
    return el('li', { class: 'faq-item', id: anchorId(it.id), dataset: { faqId: it.id } }, [
      el('div', { class: 'faq-item__head' }, [el('div', { class: 'faq-item__title' }, head), el('div', { class: 'faq-item__tools' }, tools)]),
      panel,
    ]);
  }

  function adminTools(it, group, index, searching) {
    const ids = group.items.map((x) => x.id);
    const move = (dir) => {
      const b = el('button', { type: 'button', class: 'btn btn--small faq-item__move', dataset: { faqMove: dir, faqId: it.id },
        'aria-label': t(dir === 'up' ? 'faq.move_up_aria' : 'faq.move_down_aria', { q: it.question }), title: t(dir === 'up' ? 'icon.action.move_up' : 'icon.action.move_down') },
      [icon(dir === 'up' ? 'action.move_up' : 'action.move_down', 16)]);
      // 검색 중에는 분류의 일부만 보여 순서를 바꿀 수 없다(분류 전체 목록이 필요)
      b.disabled = searching || moveIds(ids, it.id, dir) === null;
      b.addEventListener('click', () => reorder(group.category, moveIds(ids, it.id, dir), it.id, dir));
      return b;
    };
    const edit = el('button', { type: 'button', class: 'btn btn--small', dataset: { faqEdit: it.id }, 'aria-label': t('faq.edit_aria', { q: it.question }) }, [iconWithLabel('action.edit', 16, t('faq.edit'))]);
    edit.addEventListener('click', () => editDialog(it));
    const pub = el('button', { type: 'button', class: 'btn btn--small', dataset: { faqPublish: it.id } }, [t(it.is_published ? 'faq.unpublish' : 'faq.publish')]);
    pub.addEventListener('click', () => togglePublish(it));
    const del = el('button', { type: 'button', class: 'btn btn--small btn--danger-outline', dataset: { faqDelete: it.id }, 'aria-label': t('faq.delete_aria', { q: it.question }) }, [iconWithLabel('action.delete', 16, t('faq.delete'))]);
    del.addEventListener('click', () => remove(it));
    return [move('up'), move('down'), edit, pub, del];
  }

  function setOpen(id, open) {
    if (open) expanded.add(id); else expanded.delete(id);
    const btn = list.querySelector(`[data-faq-toggle="${id}"]`);
    const panel = list.querySelector(`[data-faq-answer="${id}"]`);
    if (btn) btn.setAttribute('aria-expanded', String(open));
    if (panel) panel.hidden = !open;
  }

  function openFromHash(focus = true) {
    const id = idFromHash(location.hash);
    if (id === null) return;
    const node = document.getElementById(anchorId(id));
    if (!node) return;
    setOpen(id, true);
    node.scrollIntoView({ block: 'start' });
    const btn = node.querySelector('[data-faq-toggle]');
    if (btn) btn.focus({ preventScroll: true });
    if (!focus) btn?.blur();
  }

  async function copyLink(id) {
    const url = `${location.origin}${location.pathname}#${anchorId(id)}`;
    history.replaceState(null, '', `${location.pathname}${location.search}#${anchorId(id)}`);
    try {
      await navigator.clipboard.writeText(url);
      toast(t('faq.copied'), { type: 'success' });
    } catch {
      toast(t('faq.copy_failed', { url }), { type: 'error', timeout: 10000 });
    }
  }

  form.addEventListener('submit', (ev) => {
    ev.preventDefault();
    applied = { q: oneLine(qInput.value), category: catSelect.value };
    expanded.clear();
    load();
  });
  $('[data-faq-expand-all]', box).addEventListener('click', () => { for (const it of items) setOpen(it.id, true); });
  $('[data-faq-collapse-all]', box).addEventListener('click', () => { for (const it of items) setOpen(it.id, false); });
  window.addEventListener('hashchange', () => openFromHash(true));

  // ---- 편집(admin만) ----
  if (isAdmin) $('[data-faq-add]', box)?.addEventListener('click', () => editDialog(null));

  async function reorder(category, ids, id, dir) {
    if (!ids) return;
    try {
      await api.post('/api/v1/admin/faq/reorder', { category, ids });
      status.textContent = t('faq.reordered');
      await load();
    } catch (e) {
      if (e.status === 409) {
        status.textContent = t('faq.order_conflict');
        await load();
      } else {
        toast(errorText(e), { type: 'error' });
      }
    }
    list.querySelector(`[data-faq-move="${dir}"][data-faq-id="${id}"]:not([disabled])`)?.focus()
      ?? list.querySelector(`[data-faq-toggle="${id}"]`)?.focus();
  }

  async function togglePublish(it) {
    try {
      await api.patch(`/api/v1/admin/faq/${it.id}`, { version: it.version, is_published: !it.is_published });
      status.textContent = t(it.is_published ? 'faq.unpublished_now' : 'faq.published_now');
    } catch (e) {
      toast(e.status === 409 ? t('faq.conflict_reloaded') : errorText(e), { type: 'error' });
    }
    await load();
    list.querySelector(`[data-faq-publish="${it.id}"]`)?.focus();
  }

  async function remove(it) {
    const ok = await confirmDialog({ title: t('faq.delete_title'), message: `${t('faq.delete_message')}\n${it.question}`, confirmLabel: t('faq.delete'), danger: true });
    if (!ok) return;
    try {
      await api.delete(`/api/v1/admin/faq/${it.id}`);
      expanded.delete(it.id);
      status.textContent = t('faq.deleted');
      toast(t('faq.deleted'), { type: 'success' });
    } catch (e) {
      toast(errorText(e), { type: 'error' });
    }
    await load();
    $('[data-faq-add]', box)?.focus();
  }

  /** 추가·수정 대화상자. 취소·Esc는 바뀐 내용이 있으면 확인. 409면 최신 내용을 보여 주고 내 입력은 그대로(최신으로 바꾸거나 내 내용으로 다시 저장) */
  function editDialog(item) {
    const opener = document.activeElement;
    const original = item ? { category: item.category, question: item.question, answer: item.answer, is_published: item.is_published } : null;
    let version = item?.version ?? null;
    const id = 'faq-edit';
    const listId = `${id}-cats`;
    const fieldBox = (name, label, control, help) => {
      const err = el('p', { class: 'field__error', id: `${id}-${name}-err`, role: 'alert', hidden: true });
      const counter = LIMITS[name] ? el('span', { class: 'muted faq-edit__counter', id: `${id}-${name}-count`, 'aria-live': 'off' }) : null;
      control.setAttribute('aria-describedby', [help ? `${id}-${name}-help` : null, counter ? `${id}-${name}-count` : null, `${id}-${name}-err`].filter(Boolean).join(' '));
      return { err, counter, node: el('div', { class: 'field' }, [
        el('label', { class: 'field__label', for: control.id, text: label }), control,
        el('div', { class: 'faq-edit__under' }, [help ? el('p', { class: 'field__help', id: `${id}-${name}-help`, text: help }) : null, counter].filter(Boolean)), err,
      ]) };
    };
    const cat = el('input', { class: 'field__input', id: `${id}-category`, list: listId, maxlength: '80', autocomplete: 'off', value: original?.category ?? '' });
    const q = el('input', { class: 'field__input', id: `${id}-question`, maxlength: '400', autocomplete: 'off', value: original?.question ?? '' });
    const a = el('textarea', { class: 'field__input faq-edit__answer', id: `${id}-answer`, rows: '10', spellcheck: 'false' });
    a.value = original?.answer ?? '';
    const pub = el('input', { type: 'checkbox', id: `${id}-published` });
    pub.checked = original ? Boolean(original.is_published) : true;
    const fCat = fieldBox('category', t('faq.field_category'), cat, t('faq.category_help'));
    const fQ = fieldBox('question', t('faq.field_question'), q, null);
    const fA = fieldBox('answer', t('faq.field_answer'), a, t('faq.answer_help'));
    const dl = el('datalist', { id: listId }, categories.map((c) => el('option', { value: c })));
    // 답변 작성/미리보기(같은 렌더러)
    const writeTab = el('button', { type: 'button', class: 'btn btn--small', 'aria-pressed': 'true', dataset: { faqWrite: '' } }, [t('faq.tab_write')]);
    const previewTab = el('button', { type: 'button', class: 'btn btn--small', 'aria-pressed': 'false', dataset: { faqPreview: '' } }, [t('faq.tab_preview')]);
    const preview = el('div', { class: 'faq-edit__preview md', hidden: true, tabindex: '0', 'aria-label': t('faq.tab_preview'), dataset: { faqPreviewBox: '' } });
    const showPreview = (on) => {
      writeTab.setAttribute('aria-pressed', String(!on));
      previewTab.setAttribute('aria-pressed', String(on));
      a.hidden = on;
      preview.hidden = !on;
      if (on) {
        clear(preview);
        const text = a.value.trim();
        if (text) preview.append(...md(text)); else preview.append(el('p', { class: 'muted', text: t('faq.preview_empty') }));
      }
    };
    writeTab.addEventListener('click', () => { showPreview(false); a.focus(); });
    previewTab.addEventListener('click', () => showPreview(true));
    fA.node.insertBefore(el('div', { class: 'faq-edit__tabs' }, [writeTab, previewTab]), a);
    fA.node.insertBefore(preview, a.nextSibling);
    const counters = () => {
      for (const [f, input] of [[fCat, cat], [fQ, q], [fA, a]]) {
        const name = input.id.replace(`${id}-`, '');
        const n = charCount(name === 'answer' ? input.value.trim() : oneLine(input.value));
        f.counter.textContent = t('faq.counter', { n, max: LIMITS[name] });
        f.counter.classList.toggle('faq-edit__counter--over', n > LIMITS[name]);
      }
    };
    for (const x of [cat, q, a]) x.addEventListener('input', counters);
    counters();
    const conflict = el('div', { class: 'notice notice--warn faq-edit__conflict', role: 'alert', hidden: true, dataset: { faqConflict: '' } });
    const formError = el('p', { class: 'field__error', role: 'alert', hidden: true });
    const cancel = el('button', { type: 'button', class: 'btn' }, [t('component.cancel')]);
    const save = el('button', { type: 'submit', class: 'btn btn--primary', dataset: { faqSave: '' } }, [t('faq.save')]);
    const formNode = el('form', { class: 'dialog__form faq-edit', novalidate: true }, [
      fCat.node, dl, fQ.node, fA.node,
      el('label', { class: 'checkbox' }, [pub, ' ', t('faq.field_published')]),
      conflict, formError, el('div', { class: 'dialog__actions' }, [cancel, save]),
    ]);
    const dialog = el('dialog', { class: 'dialog dialog--wide', 'aria-labelledby': `${id}-title`, dataset: { faqDialog: '' } }, [
      el('div', { class: 'dialog__body' }, [
        el('div', { class: 'dialog__icon' }, [icon(item ? 'action.edit' : 'action.add', 24)]),
        el('div', {}, [el('h2', { class: 'dialog__title', id: `${id}-title`, text: t(item ? 'faq.dialog_edit' : 'faq.dialog_add') })]),
      ]),
      formNode,
    ]);
    const draft = () => ({ category: cat.value, question: q.value, answer: a.value, is_published: pub.checked });
    let closing = false;
    const close = () => { closing = true; dialog.close(); };
    const askClose = async () => {
      if (!isDirty(original, draft())) { close(); return; }
      const ok = await confirmDialog({ title: t('faq.discard_title'), message: t('faq.discard_message'), confirmLabel: t('faq.discard_confirm'), danger: true });
      if (ok) close();
    };
    cancel.addEventListener('click', askClose);
    dialog.addEventListener('cancel', (ev) => { ev.preventDefault(); askClose(); }); // Esc
    dialog.addEventListener('close', () => {
      dialog.remove();
      if (opener && typeof opener.focus === 'function' && document.contains(opener)) opener.focus();
      else $('[data-faq-add]', box)?.focus();
    }, { once: true });
    formNode.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      formError.hidden = true;
      for (const f of [fCat, fQ, fA]) f.err.hidden = true;
      for (const x of [cat, q, a]) x.removeAttribute('aria-invalid');
      const errs = validateDraft(draft());
      if (errs.length) {
        const map = { category: [fCat, cat], question: [fQ, q], answer: [fA, a] };
        for (const e of errs) {
          const [f, input] = map[e.field];
          f.err.textContent = t(e.kind === 'required' ? 'faq.required' : 'faq.too_long', { field: t(`faq.field_${e.field}`), max: e.max });
          f.err.hidden = false;
          input.setAttribute('aria-invalid', 'true');
        }
        if (!a.hidden || errs[0].field !== 'answer') map[errs[0].field][1].focus(); else { showPreview(false); a.focus(); }
        return;
      }
      save.disabled = true;
      dialog.setAttribute('aria-busy', 'true');
      try {
        const d = draft();
        const body = { category: d.category, question: d.question, answer: d.answer, is_published: d.is_published };
        if (item) await api.patch(`/api/v1/admin/faq/${item.id}`, { ...body, version });
        else await api.post('/api/v1/admin/faq', body);
        toast(t('faq.saved'), { type: 'success' });
        close();
        await load();
      } catch (e) {
        if (e.status === 409 && e.code === 'faq_conflict' && e.details?.current) {
          showConflict(e.details.current);
        } else if (e.status === 404 && item) {
          formError.textContent = t('faq.gone');
          formError.hidden = false;
        } else {
          formError.textContent = errorText(e);
          formError.hidden = false;
        }
      } finally {
        save.disabled = false;
        dialog.removeAttribute('aria-busy');
      }
    });
    function showConflict(latest) {
      clear(conflict);
      const useLatest = el('button', { type: 'button', class: 'btn btn--small', dataset: { faqUseLatest: '' } }, [t('faq.use_latest')]);
      const keepMine = el('button', { type: 'button', class: 'btn btn--small btn--primary', dataset: { faqKeepMine: '' } }, [t('faq.keep_mine')]);
      conflict.append(
        el('p', { class: 'faq-edit__conflict-title' }, [el('strong', { text: t('faq.conflict_title') })]),
        el('p', { text: t('faq.conflict_message') }),
        el('dl', { class: 'detail-list faq-edit__latest' }, [
          el('dt', { text: t('faq.field_category') }), el('dd', { text: latest.category }),
          el('dt', { text: t('faq.field_question') }), el('dd', { text: latest.question }),
          el('dt', { text: t('faq.field_answer') }), el('dd', {}, [el('pre', { class: 'faq-edit__latest-answer', text: latest.answer })]),
        ]),
        el('div', { class: 'faq-edit__conflict-actions' }, [useLatest, keepMine]),
      );
      conflict.hidden = false;
      useLatest.addEventListener('click', () => {
        cat.value = latest.category;
        q.value = latest.question;
        a.value = latest.answer;
        pub.checked = Boolean(latest.is_published);
        version = latest.version;
        Object.assign(original ?? {}, { category: latest.category, question: latest.question, answer: latest.answer, is_published: latest.is_published });
        counters();
        conflict.hidden = true;
        q.focus();
      });
      keepMine.addEventListener('click', () => {
        version = latest.version;
        conflict.hidden = true;
        formNode.requestSubmit();
      });
      useLatest.focus();
    }
    document.body.append(dialog);
    dialog.showModal();
    (item ? q : cat).focus();
    return closing;
  }

  load();
}
