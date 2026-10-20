// node --test용 최소 가짜 DOM. innerHTML 같은 HTML 해석 기능은 일부러 두지 않는다
// (코드가 그런 기능에 기대면 테스트가 실패하도록).
class FakeNode {
  constructor(doc) { this.ownerDocument = doc; this.childNodes = []; this.parentNode = null; }
  appendChild(c) { c.parentNode = this; this.childNodes.push(c); return c; }
  removeChild(c) { this.childNodes = this.childNodes.filter((x) => x !== c); c.parentNode = null; return c; }
  remove() { this.parentNode?.removeChild(this); }
  get firstChild() { return this.childNodes[0] ?? null; }
  get textContent() { return this.childNodes.map((c) => c.textContent).join(''); }
  set textContent(v) { this.childNodes = []; this.appendChild(new FakeText(this.ownerDocument, String(v))); }
}

class FakeText extends FakeNode {
  constructor(doc, text) { super(doc); this.data = text; this.nodeType = 3; }
  get textContent() { return this.data; }
  set textContent(v) { this.data = String(v); }
}

export class FakeElement extends FakeNode {
  constructor(doc, tag, ns = null) {
    super(doc);
    this.tagName = tag.toUpperCase();
    this.namespaceURI = ns;
    this.attributes = new Map();
    this.dataset = {};
    this.listeners = {};
    this.nodeType = 1;
  }
  setAttribute(n, v) { this.attributes.set(n, String(v)); }
  getAttribute(n) { return this.attributes.has(n) ? this.attributes.get(n) : null; }
  hasAttribute(n) { return this.attributes.has(n); }
  removeAttribute(n) { this.attributes.delete(n); }
  get className() { return this.getAttribute('class') ?? ''; }
  set className(v) { this.setAttribute('class', v); }
  addEventListener(evt, fn) { (this.listeners[evt] ??= []).push(fn); }
  dispatch(evt, payload = {}) { for (const fn of this.listeners[evt] ?? []) fn({ type: evt, preventDefault() {}, ...payload }); }
}

export class FakeDocument {
  constructor(meta = {}) {
    this.meta = meta;
    this.body = new FakeElement(this, 'body');
  }
  createElement(tag) { return new FakeElement(this, tag); }
  createElementNS(ns, tag) { return new FakeElement(this, tag, ns); }
  createTextNode(text) { return new FakeText(this, text); }
  querySelector(sel) {
    const m = /^meta\[name="([^"]+)"\]$/.exec(sel);
    if (!m || !(m[1] in this.meta)) return null;
    return { getAttribute: (n) => (n === 'content' ? this.meta[m[1]] : null) };
  }
}
