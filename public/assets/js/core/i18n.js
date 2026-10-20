// 문자열 리소스 조회(명세 1, P27D 한국어·영어). 문구는 ../i18n/ko.js·en.js에만 둔다.
// 언어는 서버가 정한 <html lang>(ko|en) — 서버(UiLanguage)와 같은 값을 쓴다. 영어에 없는 키는 한국어로 대체한다(키 불일치는 시험이 잡음).
// t('key', { name: 값 }) — 키가 없으면 키를 그대로 돌려준다(화면이 깨지지 않게).
// 복수형: '{n:defect|defects}' — 값이 1이면 앞, 아니면 뒤(천 단위 쉼표 무시). PHP Lang::format과 같은 규칙.
import ko from '../i18n/ko.js';

const docLang = globalThis.document?.documentElement?.getAttribute?.('lang');
let current = 'ko';
let messages = ko;
if (docLang === 'en') {
  const en = (await import('../i18n/en.js')).default;
  messages = { ...ko, ...en };
  current = 'en';
}

const isOne = (v) => {
  const n = Number(String(v).replace(/[,\s]/g, ''));
  return Number.isFinite(n) && n === 1;
};

/** 자리표시자·복수형 채우기 */
export function format(text, params = {}) {
  return String(text).replace(/\{(\w+)(?::([^{}|]*)\|([^{}]*))?\}/g, (whole, name, one, other) => {
    if (!Object.prototype.hasOwnProperty.call(params, name)) return whole;
    if (one === undefined) return String(params[name]);
    return isOne(params[name]) ? one : other;
  });
}

export function t(key, params = {}) {
  const text = Object.prototype.hasOwnProperty.call(messages, key) ? messages[key] : key;
  return format(text, params);
}

export function has(key) {
  return Object.prototype.hasOwnProperty.call(messages, key);
}

/** 현재 언어(ko|en) */
export function language() {
  return current;
}

/** 시험용: 언어 바꾸기(en이면 en 문구를 넘긴다) */
export function useMessages(lang, extra = null) {
  current = lang === 'en' ? 'en' : 'ko';
  messages = current === 'en' && extra ? { ...ko, ...extra } : ko;
}
