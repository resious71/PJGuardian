// 탭(WAI-ARIA tabs 패턴): 화살표 왼쪽·오른쪽, Home, End로 이동하고 선택한다(roving tabindex).
// 마크업: [data-tabs] > [role=tablist] > [role=tab][aria-controls], [role=tabpanel]

export function initTabs(root) {
  const tabs = [...root.querySelectorAll('[role="tab"]')];
  const select = (tab, focus = true) => {
    for (const t of tabs) {
      const on = t === tab;
      t.setAttribute('aria-selected', String(on));
      t.tabIndex = on ? 0 : -1;
      const panel = root.ownerDocument.getElementById(t.getAttribute('aria-controls'));
      if (panel) panel.hidden = !on;
    }
    if (focus) tab.focus();
  };
  // 주소의 #<탭 id>(예: 프로젝트 상세 #analyses)로 바로 그 탭을 연다(P19). 탭을 고르면 주소의 해시도 바꾼다(새로고침·뒤로 가기 유지)
  const fromHash = () => tabs.find((t) => t.getAttribute('aria-controls') === `panel-${(root.ownerDocument.defaultView?.location?.hash ?? '').slice(1)}`);
  const initial = fromHash();
  if (initial) select(initial, false);
  root.ownerDocument.defaultView?.addEventListener?.('hashchange', () => { const t = fromHash(); if (t) select(t, false); });
  for (const tab of tabs) {
    tab.addEventListener('click', () => {
      select(tab);
      const id = tab.getAttribute('aria-controls')?.replace(/^panel-/, '');
      const loc = root.ownerDocument.defaultView?.location;
      if (id && loc && root.ownerDocument.defaultView.history) {
        root.ownerDocument.defaultView.history.replaceState(null, '', `${loc.pathname}${loc.search}#${id}`);
      }
    });
    tab.addEventListener('keydown', (e) => {
      const i = tabs.indexOf(tab);
      const next = { ArrowRight: tabs[(i + 1) % tabs.length], ArrowLeft: tabs[(i - 1 + tabs.length) % tabs.length], Home: tabs[0], End: tabs[tabs.length - 1] }[e.key];
      if (next) {
        e.preventDefault();
        select(next);
      }
    });
  }
}

/**
 * 패널 하나를 공유하는 탭(X08 규칙 설명 언어 탭): 같은 ARIA·키보드 규칙(←→·Home·End로 이동, Enter·Space로 선택 = 버튼 기본 동작)이되
 * 패널을 바꾸지 않고 onSelect(value)를 부른다(목록만 다시 불러옴). 주소는 부르는 쪽이 정한다(해시를 쓰지 않음).
 *   initTabList(root, { value: 'python', onSelect }) → { set(value) } — 선택 표시만 바꾼다(뒤로 가기 복원 등)
 *   탭: [role=tab][data-language]. 패널: aria-controls의 요소(aria-labelledby를 선택된 탭으로 맞춤)
 */
export function initTabList(root, { value, onSelect }) {
  const tabs = [...root.querySelectorAll('[role="tab"]')];
  const valueOf = (tab) => tab.dataset.language;
  const mark = (v) => {
    for (const t of tabs) {
      const on = valueOf(t) === v;
      t.setAttribute('aria-selected', String(on));
      t.tabIndex = on ? 0 : -1;
      if (on) root.ownerDocument.getElementById(t.getAttribute('aria-controls'))?.setAttribute('aria-labelledby', t.id);
    }
  };
  const choose = (tab, focus) => {
    if (focus) tab.focus();
    if (tab.getAttribute('aria-selected') === 'true') return;
    mark(valueOf(tab));
    onSelect(valueOf(tab));
  };
  for (const tab of tabs) {
    tab.addEventListener('click', () => choose(tab, false));
    tab.addEventListener('keydown', (e) => {
      const i = tabs.indexOf(tab);
      const next = { ArrowRight: tabs[(i + 1) % tabs.length], ArrowLeft: tabs[(i - 1 + tabs.length) % tabs.length], Home: tabs[0], End: tabs[tabs.length - 1] }[e.key];
      if (next) {
        e.preventDefault();
        choose(next, true); // 자동 선택(WAI-ARIA 탭 패턴: 포커스 이동 = 선택)
      }
    });
  }
  mark(value);
  return { set: mark };
}
