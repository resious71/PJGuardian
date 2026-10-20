# Security policy

## Reporting a vulnerability

**Please do not report security problems in public issues, discussions or pull requests.**

Report them privately instead:

1. **Preferred: GitHub private vulnerability reporting** — "Report a vulnerability" on the repository's Security tab. The report stays private between you and the maintainer, and the fix and advisory are prepared there.
2. **Alternative: e-mail** `resious@gmail.com` — only if you cannot use GitHub. Put "PJGuardian security" in the subject.

Please include the affected version (shown in **System admin > Monitoring** and at `/api/v1/meta/version`), steps to reproduce, and the impact you expect. Do not include real passwords, tokens or personal data — mask them.

## What to expect

- We aim to acknowledge a report within 7 days and to agree on a fix and disclosure timeline with you.
- This is a volunteer-maintained project; response times are a goal, not a guarantee.
- After a fix is released, the advisory credits the reporter unless you prefer otherwise.

## Supported versions

| Version | Security fixes |
| --- | --- |
| 1.0.x | yes |
| older | no |

## Deploying safely

Most incidents come from deployment, not code. Before production use:

- **Change the initial administrator password `admin` immediately** — it is public knowledge.
- Serve only `public/` over **HTTPS**; keep `config/`, `data/`, `bin/` and backups outside the web root.
- Keep `config/secrets.ini`, backup bundles and exported key files (`.pjgkey`) private and in separate places.
- Configure trusted proxies if the application runs behind a reverse proxy.

The full checklist is in [docs/hardening.md](docs/hardening.md) (Korean) and [docs/release_checklist.md](docs/release_checklist.md).

[한국어 요약] 보안 문제는 공개 이슈가 아니라 **GitHub 비공개 보안 신고**(저장소 Security 탭의 "Report a vulnerability")로 먼저 알려 주세요. GitHub를 쓸 수 없을 때만 보조 연락처 `resious@gmail.com`으로 보내 주세요. 실제 비밀번호·토큰·개인 정보는 가려서 보내 주세요. 운영 전 초기 `admin` 비밀번호 변경, HTTPS, 비밀 파일·백업 보호는 `docs/hardening.md`를 따릅니다.
