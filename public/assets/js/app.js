// 모든 화면의 공통 초기화(레이아웃이 불러온다). 화면별 동작은 pages/*.js에서.
import { localizeTimes } from './core/time.js';
import { initTabs } from './ui/tabs.js';
import { initMono } from './ui/mono.js';
import { installClipTips } from './ui/clip.js';
import { initNotificationBell } from './ui/notification_bell.js';
import { initUiPrefs } from './ui/ui_prefs.js';

function init() {
  localizeTimes(document);
  for (const root of document.querySelectorAll('[data-tabs]')) initTabs(root);
  initMono(document);
  installClipTips(document); // 긴 셀 툴팁(P21 0C): 표마다가 아니라 문서에 한 번
  initNotificationBell(document); // 상단 알림 아이콘·배지·미리보기 패널(P24, 로그인 화면만 있음)
  initUiPrefs(document); // 헤더 언어·테마 전환(P27D, 로그인 전 화면에도)
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
  init();
}
