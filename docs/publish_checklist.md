# 오픈소스 공개 절차 (P33)

개발 저장소를 그대로 공개하지 않는다. `tools/export_open_source.php`로 만든 **공개용 트리**를 새 저장소의 첫 커밋으로 올린다(과거 기록 없이). 아래는 사용자가 직접 하는 순서이며, 명령은 예시다. 이 문서의 어떤 단계도 자동으로 실행되지 않는다.

## 0. 공개 전에 정할 것

정한 것(X22, 2026-10-20 — 반영 완료):

- [x] 저작권자 — `NOTICE`: `Copyright (c) 2026 resious71`
- [x] 보안 연락처 — GitHub 비공개 보안 신고 우선, 보조 이메일 `resious@gmail.com`(`SECURITY.md`). 행동 강령 연락처도 같은 이메일(`CODE_OF_CONDUCT.md`)
- [x] AI 보조 개발 고지 — 포함(`docs/legal/AI_ASSISTED.md`, README 영·한 한 단락)
- [x] SPDX 머리말 — 넣지 않음
- [x] 초기 관리자 비밀번호 — 지금대로(`admin`, 변경 강제 없음, README 맨 앞 경고)
- [x] 공개 제외 — `prompts/`, `docs/results/`, `docs/presentations/`, `tools/proto/`, `PJGuardian_requirements.md`(`tools/export_open_source.rules.php`)
- [x] 상세 설치 가이드 `docs/install_guide.md` — 고쳐서 공개(HSTS 예시 정리·예시 IP). **사용자가 커밋하면** 공개용 트리에 그대로 들어간다(커밋 전에는 공개용 사본에서 `docs/install_quick.ko.md`로 바뀜)

남은 것(사람이 직접):

- [ ] 이름 "PJGuardian"이 이미 쓰이는지 검색(예: "PJGuardian", "PJGuardian static analysis", GitHub·패키지 저장소·상표 검색)
- [ ] 그림(`docs/screenshots/`, `resources/guide/*/img/`)을 눈으로 확인(1절)
- [ ] `samples/`의 코드가 이 프로젝트에서 만든 시험 시료인지 확인(1절)

## 1. 공개용 트리 만들기와 확인

```
git status                                  # 내보낼 파일에 미커밋 변경이 없어야 함(있으면 커밋 먼저)
php tools/export_open_source.php            # dist/oss/ — 제외 목록·bin 버전·바꾸기 목록을 출력, 스캐너·링크 검사 자동 실행
```

- [ ] 마지막 줄이 "완료 — 검사 통과", 종료 코드 0
- [ ] `dist/oss/OSS_MANIFEST.json`의 `dirty`가 `false`, `bin_versions`가 의도한 버전(ruff·ESLint 각 하나)
- [ ] 이미지(`docs/screenshots/`, `resources/guide/*/img/`)를 **눈으로** 확인 — 실제 이름·이메일·경로·사내 주소가 없는지(스캐너는 글자만 본다). `docs/screenshots/SCREENSHOTS.json`에 각 그림의 출처 장면이 있다
- [ ] `samples/`의 코드는 이 프로젝트에서 만든 시험 시료인지 다시 확인

## 2. 새 저장소(먼저 비공개)

```
cd dist/oss
git init -b main
git config user.name  "<공개해도 되는 이름>"
git config user.email "<공개해도 되는 이메일 또는 GitHub noreply 주소>"
git add -A
git commit -m "PJGuardian 1.0.0"
git log -1 --format="%an <%ae>"            # 작성자 확인
git remote add origin <새 비공개 저장소 주소>
git push -u origin main
```

`dist/oss/OSS_MANIFEST.json`은 확인용이라 저장소에 넣지 않아도 된다(넣으려면 그대로 두고, 빼려면 `git add` 전에 지운다).

## 3. GitHub 설정(비공개일 때 먼저)

- [ ] Settings > Code security: **Secret scanning**, **Push protection**, **Dependabot alerts·security updates**, **Private vulnerability reporting** 켜기
- [ ] Branch protection(main): 직접 푸시 금지, PR·CI 필수(처음 CI가 녹색이 된 뒤 — `docs/ci_status.md`)
- [ ] Issues 켜기, Discussions(선택), 저장소 설명·토픽(예: `static-analysis`, `ruff`, `eslint`, `php`, `self-hosted`)
- [ ] 라이선스가 "Apache-2.0"으로 인식되는지(저장소 오른쪽 About)
- [ ] Actions 첫 실행 결과 확인 — Linux 첫 실행이므로 실패할 수 있다. 녹색이 되면 `ci.yml`의 `continue-on-error: true`를 지운다(`docs/ci_status.md`)

## 4. 한 번 더 비밀 검사(사용자 PC)

인터넷이 되는 PC에서 공개용 트리나 새 저장소에 대해 외부 스캐너를 한 번 더 돌린다(이 프로젝트는 설치하지 않음):

```
gitleaks detect --source dist/oss --no-git -v
trufflehog filesystem dist/oss
```

- [ ] 발견 0(가짜 시험 값이면 이유를 확인)
- [ ] `composer audit`, `npm audit --prefix tests/tools` — 알려진 취약점

## 5. 이름·라이선스 최종 확인

- [ ] `THIRD_PARTY_NOTICES`·`NOTICE`·`docs/third_party.md`가 지금 의존성과 같음(`php tests/run.php LicenseNotice`)
- [ ] `bin/`에 실행 파일이 없음(공개용 트리 만들 때 자동 검사), p4는 어떤 형태로도 포함하지 않음
- [ ] 이 문서들은 법률 자문이 아니다 — 필요하면 조직의 법무 검토

## 6. 공개와 릴리스

- [ ] 저장소를 Public으로 전환
- [ ] 태그 `v1.0.0`과 GitHub Release 만들기: 본문은 `CHANGELOG.md`의 1.0.0, 첨부는 **`--with-bin` 없이** 만든 배포 묶음(`php tools/build_bundle.php --zip`)의 zip과 `SHA256SUMS`(`--with-bin` 묶음에는 p4가 들어가므로 공개 첨부 금지)

## 7. 공개 뒤

- [ ] 비공개 취약점 보고가 실제로 들어오는지(테스트 보고 하나)와 보안 연락처 이메일 동작
- [ ] README의 응답 기대치(SECURITY.md: 7일 안 확인)를 지킬 수 있는 사람·순번
- [ ] 개발 저장소에서 바뀐 것을 다시 공개하려면: 개발 저장소에서 커밋 → `php tools/export_open_source.php` → 공개 저장소 작업 폴더에 덮어쓰기(`.git` 제외) → 차이 확인 → 커밋
