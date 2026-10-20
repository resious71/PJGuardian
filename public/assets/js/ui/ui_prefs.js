// 헤더의 언어·테마 전환(P27D). 저장: 로그인 상태면 계정(PUT /api/v1/users/me/preferences) + 쿠키, 아니면 쿠키만(서버에 쓰지 않음).
//   - 테마: 다시 불러오지 않고 <html data-theme>를 바로 바꾼다(system이면 속성을 빼서 CSS prefers-color-scheme을 따름).
//   - 언어: 서버가 그리는 문구가 많아 저장 뒤 화면을 다시 불러온다. 입력 중인 폼이 있으면 먼저 확인받는다(내용이 사라짐).
// 메뉴 키보드: 버튼 Enter/Space/↓ → 메뉴(지금 선택에 포커스), ↑↓ 이동, Home/End, Enter/Space 선택, Esc/Tab 닫기.
import { createApi } from '../core/api.js';
import { t } from '../core/i18n.js';
import { confirmDialog } from './dialog.js';
import { toast } from './toast.js';
import { iconWithLabel } from './icons.js';

const LANGS = ['ko', 'en'];
const THEMES = ['light', 'dark', 'system'];
const THEME_ICON = { light: 'ui.theme_light', dark: 'ui.theme_dark', system: 'ui.theme_system' };
const YEAR = 365 * 24 * 3600;

/** 쿠키 한 줄(민감 정보 없음, SameSite=Lax, 1년). 배포 경로가 있으면 그 경로 */
export function prefCookie(name, value, base = '') {
  const path = base && base !== '/' ? base : '/';
  const secure = globalThis.location?.protocol === 'https:' ? '; Secure' : '';
  return `${name}=${encodeURIComponent(value)}; Path=${path}; Max-Age=${YEAR}; SameSite=Lax${secure}`;
}

/** 입력 중인(기본값과 다른) 폼 칸이 있는가 — 언어를 바꾸면 다시 불러와 사라지므로 확인 대상 */
export function hasUnsavedInput(doc = globalThis.document) {
  for (const el of doc.querySelectorAll('main input, main textarea, main select, dialog[open] input, dialog[open] textarea')) {
    if (el.closest('[data-ui-switch]') || el.type === 'hidden' || el.type === 'search' || el.disabled) continue;
    if (el.type === 'checkbox' || el.type === 'radio') {
      if (el.checked !== el.defaultChecked) return true;
    } else if (el.tagName === 'SELECT') {
      if ([...el.options].some((o) => o.selected !== o.defaultSelected)) return true;
    } else if (el.type !== 'file' && el.value !== el.defaultValue) {
      return true;
    } else if (el.type === 'file' && el.files?.length) {
      return true;
    }
  }
  return false;
}

/** <html data-theme> 적용(system이면 속성 없음) */
export function applyTheme(theme, root = globalThis.document?.documentElement) {
  if (!root) return;
  if (theme === 'light' || theme === 'dark') root.setAttribute('data-theme', theme);
  else root.removeAttribute('data-theme');
}

export function initUiPrefs(doc = globalThis.document) {
  const box = doc.querySelector('[data-ui-switch]');
  if (!box) return;
  const api = createApi();
  const base = doc.querySelector('meta[name="app-base"]')?.content ?? '';
  const loggedIn = box.dataset.loggedIn === '1';
  const save = async (body) => {
    if (!loggedIn) return true;
    try {
      await api.put('/api/v1/users/me/preferences', body);
      return true;
    } catch {
      toast(t('ui_switch.save_failed'), { type: 'warn' });
      return false;
    }
  };

  // ---- 언어 ----
  const langBtn = box.querySelector('[data-ui-lang]');
  langBtn?.addEventListener('click', async () => {
    const next = langBtn.dataset.next;
    if (!LANGS.includes(next)) return;
    if (hasUnsavedInput(doc)) {
      const ok = await confirmDialog({ title: t('ui_switch.unsaved_title'), message: t('ui_switch.unsaved'), confirmLabel: t('ui_switch.continue'), warn: true });
      if (!ok) return;
    }
    doc.cookie = prefCookie('pjg_lang', next, base);
    await save({ language: next });
    globalThis.location.reload();
  });

  // ---- 테마 ----
  const themeBtn = box.querySelector('[data-ui-theme-btn]');
  const menu = box.querySelector('[data-ui-theme-menu]');
  const items = [...menu.querySelectorAll('[data-theme-value]')];
  const current = box.querySelector('[data-ui-theme-current]');
  const open = (focusIndex) => {
    menu.hidden = false;
    themeBtn.setAttribute('aria-expanded', 'true');
    const i = focusIndex ?? Math.max(0, items.findIndex((b) => b.getAttribute('aria-checked') === 'true'));
    items[i]?.focus();
  };
  const close = (refocus = true) => {
    if (menu.hidden) return;
    menu.hidden = true;
    themeBtn.setAttribute('aria-expanded', 'false');
    if (refocus) themeBtn.focus();
  };
  const choose = async (theme) => {
    if (!THEMES.includes(theme)) return;
    applyTheme(theme, doc.documentElement);
    box.dataset.themeChoice = theme;
    for (const b of items) b.setAttribute('aria-checked', b.dataset.themeValue === theme ? 'true' : 'false');
    current.replaceChildren(iconWithLabel(THEME_ICON[theme], 16)); // 버튼은 짧은 이름(라이트·다크·시스템), 메뉴는 긴 이름
    const label = t('ui_switch.theme_aria', { current: t(`ui_switch.theme.${theme}`) });
    themeBtn.setAttribute('aria-label', label);
    themeBtn.title = label;
    close();
    doc.cookie = prefCookie('pjg_theme', theme, base);
    await save({ theme });
    // 차트(캔버스)는 그릴 때 토큰 색을 읽으므로 차트가 있는 화면만 다시 불러온다(입력 폼이 없는 화면)
    if (doc.querySelector('main canvas')) globalThis.location.reload();
  };
  themeBtn.addEventListener('click', () => (menu.hidden ? open() : close()));
  themeBtn.addEventListener('keydown', (ev) => {
    if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
      ev.preventDefault();
      open(ev.key === 'ArrowUp' ? items.length - 1 : undefined);
    }
  });
  menu.addEventListener('keydown', (ev) => {
    const i = items.indexOf(doc.activeElement);
    const move = { ArrowDown: (i + 1) % items.length, ArrowUp: (i - 1 + items.length) % items.length, Home: 0, End: items.length - 1 }[ev.key];
    if (move !== undefined) {
      ev.preventDefault();
      items[move].focus();
    } else if (ev.key === 'Escape') {
      ev.preventDefault();
      close();
    } else if (ev.key === 'Tab') {
      close(false);
    }
  });
  for (const b of items) b.addEventListener('click', () => choose(b.dataset.themeValue));
  doc.addEventListener('click', (ev) => {
    if (!menu.hidden && !box.querySelector('.ui-switch__theme').contains(ev.target)) close(false);
  });
}
