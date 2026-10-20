// 분석 출처 표시(P25, 명세 18.5): 아이콘 + 업로드 파일 이름 / Git 브랜치 @짧은 해시 / Perforce depot 경로 @changelist.
// Git 커밋 링크는 서버가 허용 목록의 "GitHub 호환" 호스트에 대해서만 만들어 준 값(safeCommitLink로 형식 재확인)만 쓴다 — 새 탭, rel="noopener noreferrer".
// 모든 글자는 textContent(브랜치·경로·파일 이름은 사용자·저장소 입력).
import { t } from '../core/i18n.js';
import { el } from '../core/dom.js';
import { iconWithLabel } from './icons.js';
import { sourceText, safeCommitLink } from '../lib/repo_core.js';

const ICON = { upload: 'source.upload', git: 'source.git', perforce: 'source.perforce' };

/** @param {{kind:string, ref?:string|null, revision?:string|null, link?:string|null, file_name?:string|null}} source */
export function sourceNode(source, doc = globalThis.document) {
  const kind = ICON[source?.kind] ? source.kind : 'upload';
  if (kind === 'upload') return iconWithLabel('source.upload', 16, source?.file_name ?? undefined, doc);
  const wrap = el('span', { class: 'source-cell', dataset: { sourceKind: kind } }, [iconWithLabel(ICON[kind], 16, t(`repo.kind.${kind}`), doc)], doc);
  const text = sourceText(source);
  const link = kind === 'git' ? safeCommitLink(source.link) : null;
  if (link && source.revision) {
    wrap.appendChild(el('span', { class: 'muted', text: ` ${source.ref ?? ''} ` }, [], doc));
    wrap.appendChild(el('a', { href: link, target: '_blank', rel: 'noopener noreferrer', class: 'mono', title: source.revision, text: `@${source.revision.slice(0, 7)}`,
      'aria-label': t('repo.commit_link', { hash: source.revision.slice(0, 7) }) }, [], doc));
  } else if (text) {
    wrap.appendChild(el('span', { class: 'mono source-cell__text', title: source.revision ?? '', text: ` ${text}` }, [], doc));
  }
  return wrap;
}
