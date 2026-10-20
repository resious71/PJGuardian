// 점검 도구 공통: 대상 주소(app.base_url)와 Chrome 실행 인자.
// - 주소는 명령행 인자가 있으면 그것, 없으면 config/config.ini(없으면 config.ini.example)의 [app] base_url.
// - 이름이 localhost·IP가 아니면(예: pjguardian.local) hosts 없이도 열리게 127.0.0.1로 연결한다.
// - basePath는 주소의 경로 부분(예: https://localhost/PJGuardian → '/PJGuardian'). 화면 주소 비교에 쓴다.
import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';

export function readBaseUrl(root) {
  const file = [path.join(root, 'config/config.ini'), path.join(root, 'config/config.ini.example')].find((f) => existsSync(f));
  if (!file) return 'https://localhost';
  let section = '';
  for (const raw of readFileSync(file, 'utf8').split(/\r?\n/)) {
    const line = raw.trim();
    const sec = /^\[(.+)\]$/.exec(line);
    if (sec) { section = sec[1]; continue; }
    const kv = /^base_url\s*=\s*"?([^"]*)"?\s*$/.exec(line);
    if (section === 'app' && kv) return kv[1].replace(/\/+$/, '');
  }
  return 'https://localhost';
}

export function target(root, argValue) {
  const base = (argValue ?? readBaseUrl(root)).replace(/\/+$/, '');
  const url = new URL(base);
  const basePath = url.pathname.replace(/\/+$/, '');
  const isLocal = url.hostname === 'localhost' || /^[\d.]+$/.test(url.hostname) || url.hostname.startsWith('[');
  const chromeArgs = ['--ignore-certificate-errors', ...(isLocal ? [] : [`--host-resolver-rules=MAP ${url.hostname} 127.0.0.1`])];
  /** 화면 주소에서 배포 경로 접두사를 뺀 앱 안 경로(+쿼리) */
  const rel = (href, withSearch = false) => {
    const u = new URL(href);
    const p = basePath !== '' && u.pathname.startsWith(basePath) ? u.pathname.slice(basePath.length) || '/' : u.pathname;
    return withSearch ? p + u.search : p;
  };
  return { base, basePath, chromeArgs, rel };
}
