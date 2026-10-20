# 감사 로그 작업 이름 (P30, X17 설명 사전)

감사 로그(`audit_log`, 명세 10·19.7)의 `action` 값 목록이다. 코드의 기준은 `src/Support/AuditActions.php` 한 곳이고, 감사 로그 화면(시스템 관리 메뉴 옆 **감사 로그**, `/admin/audit-logs`)의 "작업" 선택지가 이 표를 쓴다.
`tests/Integration/AuditLogTest::testActionNamesInCodeAreListed`가 코드에 문자열로 쓴 작업 이름이 모두 이 목록에 있는지 검사한다(새 작업을 기록하면 `AuditActions`와 이 문서를 함께 고친다).

- **감사 대상이 아닌 것**(명세 10장 결정): 로그인 성공·실패·로그아웃(앱 로그만 — 잠금·IP 차단 같은 보안 사건은 `auth.login_failed`), 결함 상태·담당자 변경(결함 이력), 분석 취소, 감사 로그 무결성 검사 실행(앱 로그만 — 읽기 동작).
- 세부 정보(`details`)에는 비밀번호·토큰·키·해시 원문을 넣지 않는다(기록 때 `Logger::maskArray`, 화면·API·CSV에서 `AuditQuery::safe`가 한 번 더 — 민감한 키 이름, 문자열 안의 비밀, 이메일 주소는 앞 글자만).
- 화면의 "결과"·"요청 ID" 칸은 감사 표의 열이 아니다(해시 대상 열을 바꾸지 않기 위해). 세부 정보에 `result`·`request_id`가 있을 때만 보인다.

| 접두사(화면 선택지) | 작업 이름 | 내용 |
| --- | --- | --- |
| `user.*` | `user.create`, `user.update`, `user.role_change`, `user.deactivate`, `user.activate`, `user.temp_password`, `user.password_set`, `user.unlock`, `user.auth_type_change`, `user.bulk_import`, `user.pending_create`, `user.approve`, `user.reject`, `user.pending_delete`, `user.pending_purge`, `user.external_link` | 계정(관리자 화면·승인 대기·CSV 일괄) |
| `cli.*` | `cli.user_unlock`, `cli.user_password_reset` | 서버 명령줄 계정 복구(tools/user_cli.php, 행위자 cli) |
| `auth.*` | `auth.login_failed`, `auth.ldap_secret_set`, `auth.ldap_secret_delete`, `auth.oidc_secret_set`, `auth.oidc_secret_delete`, `auth.saml_sp_key_generate`, `auth.saml_sp_key_delete`, `auth.saml_metadata_import` | 인증 보안 사건·외부 인증 비밀·SAML 키(로그인 성공·로그아웃은 앱 로그만 — 명세 10장 결정) |
| `group.*` | `group.create`, `group.rename`, `group.update`, `group.delete`, `group.member_add`, `group.member_remove`, `group.manager_set`, `group.manager_unset` | 그룹 |
| `project.*` | `project.create`, `project.update`, `project.delete`, `project.access_add`, `project.access_remove` | 프로젝트·접근 매핑 |
| `run.*` | `run.detail_delete` | Run 상세 수동 삭제 |
| `repo.*` | `repo.save`, `repo.delete`, `repo.test`, `repo.fetch_now`, `repo.host_test` | 소스 저장소 연결 |
| `repo_host.*` | `repo_host.add`, `repo_host.update`, `repo_host.delete` | 저장소 허용 호스트 |
| `schedule.*` | `schedule.save`, `schedule.auto_disable` | 주기 분석 |
| `rules.*` | `rules.override_set`, `rules.override_clear`, `rules.override_reset_all`, `rules.override_bulk`, `rules.reload_request`, `rules.load` | 규칙 켜기·끄기·데이터 적재 |
| `export.*` | `export.defects`, `export.rules` | 결함·규칙 내보내기 |
| `tool.*` | `tool.block`, `tool.unblock`, `tool.pin`, `tool.unpin`, `tool.mark_removal`, `tool.unmark_removal`, `tool.package_staged`, `tool.package_discarded`, `tool.package_hash_set`, `tool.reverify_request` | bin/ 도구 차단·고정·패키지 |
| `worker.*` | `worker.start_request`, `worker.stop_request`, `worker.restart_request` | 워커 시작·정지·재시작 요청 |
| `disk.*` | `disk.scan_request` | 디스크 사용량 계산 요청 |
| `cleanup.*` | `cleanup.run_request` | 정리 작업 실행 요청 |
| `config.*` | `config.update` | 설정 관리(settings.local.ini) 항목 변경 |
| `maintenance.*` | `maintenance.on`, `maintenance.off` | 업그레이드 유지보수 모드 켜기(직전 백업 묶음 이름)·끄기(P32B) — 같은 저장의 `config.update`와 함께 남는다 |
| `setting.*` | `setting.update` | DB 화면 설정(알림 보관 기간 등) |
| `backup.*` | `backup.create`, `backup.delete`, `backup.download`, `backup.upload`, `backup.verify` | 백업 묶음 |
| `restore.*` | `restore.start`, `restore.finish`, `restore.rollback`, `restore.rehearsal` | 복원·리허설 |
| `key.*` | `key.export`, `key.import` | 암호화 키 내보내기·가져오기 |
| `faq.*` | `faq.create`, `faq.update`, `faq.publish`, `faq.delete`, `faq.reorder`, `faq.import` | FAQ(P29B; `faq.import` = X16 시드 일괄 가져오기 한 줄, 행위자 cli) |
| `audit.*` | `audit.export`, `audit.purge` | 감사 로그 자체(P30: CSV 내보내기, 보존 기간 정리 — 행위자 system) |
| `mail.*` | `mail.secret_set`, `mail.secret_delete`, `mail.test` | 관리자 장애 이메일(P30B: SMTP 비밀번호 저장·삭제(값 없음), 시험 메일(받는 사람 가림). 장애 메일 자동 발송은 감사가 아니라 앱 로그) |

## 작업별 의미와 세부 정보 (X17)

감사 로그 화면·API·CSV의 **사람이 읽는 설명**(한 문장 요약·바뀐 항목 표·대상 이름)은 `src/Services/Audit/AuditDescriber.php`가 **보여 줄 때** 만든다(저장된 행·해시 체인은 그대로). 이 표가 설명 사전의 근거다.

- 문장: `audit.desc.<작업>`(변형은 `audit.desc.<작업>.<변형>`), 작업 이름 `audit.act.<작업>`, 분류 `audit.group.<접두사>`, 항목 이름 `audit.fname.<키>`(작업별 이름 `audit.fname.<작업>.<키>`가 먼저), 값 풀이 `audit.val.*` — `src/Lang/ko.php`·`en.php`. 한국어 조사는 "을(를)"처럼 두 꼴로 쓰고 `Support\Josa`가 고른다.
- **새 감사 작업을 만들면** `AuditActions`, 이 표, ko·en 문장·작업 이름, `tests/Support/AuditSamples.php`의 대표 샘플, `AuditDescriberTest`의 기대 문장을 함께 더한다(빠뜨리면 `AuditDescriberTest::testEveryActionHasTemplatesAndSample`이 실패).
- 세부 정보가 빠진 옛 행은 작업 이름만 쓴 일반 문장(`"…" 작업을 했습니다`), 목록에 없는 작업은 "작업 xxx이(가) 기록되었습니다" + 화면 배지 **설명 없음(원본만)**.
- 대상 이름: 사용자(표시 이름·로그인 ID)·프로젝트·그룹·FAQ(질문)는 한 화면 단위로 종류마다 한 번 일괄 조회, 못 찾으면 기록된 이름 또는 "삭제된 항목(#id)"(링크 없음). 설정 항목은 설정 관리 화면 이름.
- 가린 값은 풀지 않는다("(가려진 값)"), 공백 없는 40자 이상 덩어리는 "(긴 값 생략)", 비밀 모양 설정 키의 이전·이후 값은 화면 단계에서 가린다.

| 작업 | 의미(화면 작업 이름) | 대상 | `details` 키(화면 항목 이름) | 문장 예(ko, 샘플) |
| --- | --- | --- | --- | --- |
| `user.create` | 사용자 — 사용자 만들기 · 변형: `csv`, `initial` | `user` | `login_id`(로그인 ID), `role`(역할), `auth_type`(인증 방식), `actor_role`(행위자 역할) | 관리자 alex가 사용자 Jordan Lee (jordan)를 만들었습니다(역할 일반 사용자, 인증 방식 로컬). |
| `user.update` | 사용자 — 사용자 정보 변경 · 변형: `self` | `user` | `actor_role`(행위자 역할), `self`(자기 정보 변경), `display_name`(표시 이름), `email`(이메일) | 관리자 alex가 사용자 Jordan Lee (jordan)의 표시 이름, 이메일을 바꿨습니다. |
| `user.role_change` | 사용자 — 역할 변경 | `user` | `from`(이전), `to`(이후) | 관리자 alex가 사용자 Jordan Lee (jordan)의 역할을 일반 사용자 → 관리자로 바꿨습니다. |
| `user.deactivate` | 사용자 — 비활성화 | `user` | `login_id`(로그인 ID) | 관리자 alex가 사용자 Jordan Lee (jordan)를 비활성화했습니다. |
| `user.activate` | 사용자 — 다시 활성화 | `user` | `login_id`(로그인 ID) | 관리자 alex가 사용자 Jordan Lee (jordan)를 다시 활성화했습니다. |
| `user.temp_password` | 사용자 — 임시 비밀번호 발급 | `user` | `login_id`(로그인 ID), `expires_at`(만료 시각) | 관리자 alex가 사용자 Jordan Lee (jordan)에게 임시 비밀번호를 발급했습니다(만료 2026-10-03 12:00 KST). |
| `user.password_set` | 사용자 — 비밀번호 설정(관리자) | `user` | `login_id`(로그인 ID) | 관리자 alex가 사용자 Jordan Lee (jordan)의 비밀번호를 새로 정했습니다. |
| `user.unlock` | 사용자 — 잠금 해제 | `user` | `login_id`(로그인 ID), `was_locked`(잠겨 있었음) | 관리자 alex가 사용자 Jordan Lee (jordan)의 로그인 잠금을 풀었습니다. |
| `user.auth_type_change` | 사용자 — 인증 방식 변경 | `user` | `login_id`(로그인 ID), `from`(이전), `to`(이후) | 관리자 alex가 사용자 Jordan Lee (jordan)의 인증 방식을 로컬 → LDAP/AD로 바꿨습니다. |
| `user.bulk_import` | 사용자 — 계정 CSV 일괄 처리 | — | `created`(만든 계정), `deactivated`(비활성화한 계정), `skipped`(건너뜀), `errors_skipped`(오류로 건너뛴 행), `warnings`(경고), `rows`(행 수), `encoding`(인코딩), `file_sha256`(파일 해시(앞부분)), `skip_errors`(오류 행 제외) | 관리자 alex가 계정 CSV 일괄 처리를 적용했습니다(만듦 12, 비활성화 2, 건너뜀 1). |
| `user.pending_create` | 사용자 — 승인 대기 계정 생성 | `user` | `login_id`(로그인 ID), `auth_type`(인증 방식) | SSO(OIDC) 첫 로그인으로 승인 대기 계정 Jordan Lee (jordan)가 만들어졌습니다. |
| `user.approve` | 사용자 — 승인 대기 계정 승인 | `user` | `login_id`(로그인 ID), `role`(역할), `from`(이전 상태), `auth_type`(인증 방식) | 관리자 alex가 승인 대기 계정 Jordan Lee (jordan)를 일반 사용자 역할로 승인했습니다. |
| `user.reject` | 사용자 — 승인 대기 계정 거절 | `user` | `login_id`(로그인 ID), `auth_type`(인증 방식) | 관리자 alex가 승인 대기 계정 Jordan Lee (jordan)를 거절했습니다. |
| `user.pending_delete` | 사용자 — 승인 대기 계정 삭제 | `user` | `login_id`(로그인 ID), `from`(이전 상태) | 관리자 alex가 승인 대기 계정 ghost를 삭제했습니다. |
| `user.pending_purge` | 사용자 — 승인 대기 계정 정리 | — | `count`(개수), `retention_days`(보관 기간(일)) | 시스템이 보관 기간(30일)이 지난 승인 대기 계정 4개를 정리했습니다. |
| `user.external_link` | 사용자 — 외부 로그인 연결 | `user` | `login_id`(로그인 ID), `auth_type`(인증 방식) | 시스템이 기존 계정 Jordan Lee (jordan)를 LDAP/AD 로그인에 연결했습니다. |
| `cli.user_unlock` | 서버 명령줄 — 잠금 해제(명령줄) | `user` | `login_id`(로그인 ID), `was_locked`(잠겨 있었음) | 서버 명령줄 도구가 사용자 Jordan Lee (jordan)의 로그인 잠금을 풀었습니다. |
| `cli.user_password_reset` | 서버 명령줄 — 임시 비밀번호 발급(명령줄) | `user` | `login_id`(로그인 ID), `expires_at`(만료 시각) | 서버 명령줄 도구가 사용자 Jordan Lee (jordan)에게 임시 비밀번호를 발급했습니다(만료 2026-10-03 12:00 KST). |
| `auth.login_failed` | 계정 연동·로그인 — 외부 로그인 거부 | — | `login_id`(로그인 ID), `reason`(사유), `auth_type`(인증 방식) | sam 계정의 LDAP/AD 로그인을 거부했습니다(로컬 계정은 외부 로그인 불가). |
| `auth.ldap_secret_set` | 계정 연동·로그인 — LDAP/AD 검색 계정 비밀번호 저장 · 변형: `replaced` | — | `replaced`(기존 값 바꿈) | 관리자 alex가 LDAP/AD 검색 계정 비밀번호를 바꿨습니다. |
| `auth.ldap_secret_delete` | 계정 연동·로그인 — LDAP/AD 검색 계정 비밀번호 삭제 | — | — | 관리자 alex가 LDAP/AD 검색 계정 비밀번호를 지웠습니다. |
| `auth.oidc_secret_set` | 계정 연동·로그인 — OIDC 클라이언트 비밀 저장 · 변형: `replaced` | — | `replaced`(기존 값 바꿈) | 관리자 alex가 SSO(OIDC) 클라이언트 비밀을 저장했습니다. |
| `auth.oidc_secret_delete` | 계정 연동·로그인 — OIDC 클라이언트 비밀 삭제 | — | — | 관리자 alex가 SSO(OIDC) 클라이언트 비밀을 지웠습니다. |
| `auth.saml_sp_key_generate` | 계정 연동·로그인 — SAML SP 키 생성 · 변형: `replaced` | — | `replaced`(기존 값 바꿈) | 관리자 alex가 SSO(SAML) SP 키와 인증서를 만들었습니다. |
| `auth.saml_sp_key_delete` | 계정 연동·로그인 — SAML SP 키 삭제 | — | — | 관리자 alex가 SSO(SAML) SP 키와 인증서를 지웠습니다. |
| `auth.saml_metadata_import` | 계정 연동·로그인 — SAML 메타데이터 가져오기 | — | `source`(출처), `certs`(인증서 수) | 관리자 alex가 SSO(SAML) IdP 메타데이터를 가져왔습니다(주소에서, 인증서 2개). |
| `group.create` | 그룹 — 그룹 만들기 | `group` | `name`(이름) | 관리자 alex가 그룹 Platform을 만들었습니다. |
| `group.rename` | 그룹 — 그룹 이름 변경 | `group` | `name`(이름) | 관리자 alex가 그룹 이름을 Infra → Platform으로 바꿨습니다. |
| `group.update` | 그룹 — 그룹 정보 변경 | `group` | `description`(설명) | 관리자 alex가 그룹 Platform의 설명을 바꿨습니다. |
| `group.delete` | 그룹 — 그룹 삭제 | `group` | `name`(이름), `members`(구성원 수), `projects`(연결된 프로젝트 수) | 관리자 alex가 그룹 Legacy를 삭제했습니다(구성원 3명, 연결된 프로젝트 1개). |
| `group.member_add` | 그룹 — 구성원 추가 | `group` | `user_id`(사용자 번호), `login_id`(로그인 ID), `actor_role`(행위자 역할) | 관리자 alex가 그룹 Platform에 jordan을 구성원으로 추가했습니다. |
| `group.member_remove` | 그룹 — 구성원 빼기 | `group` | `user_id`(사용자 번호), `login_id`(로그인 ID), `actor_role`(행위자 역할), `was_manager`(그룹 관리자였음) | 관리자 alex가 그룹 Platform에서 구성원 jordan을 뺐습니다. |
| `group.manager_set` | 그룹 — 그룹 관리자 지정 | `group` | `user_id`(사용자 번호), `login_id`(로그인 ID), `actor_role`(행위자 역할) | 관리자 alex가 jordan을 그룹 Platform의 그룹 관리자로 지정했습니다. |
| `group.manager_unset` | 그룹 — 그룹 관리자 해제 | `group` | `user_id`(사용자 번호), `login_id`(로그인 ID), `actor_role`(행위자 역할) | 관리자 alex가 그룹 Platform에서 jordan의 그룹 관리자 지정을 해제했습니다. |
| `project.create` | 프로젝트 — 프로젝트 만들기 | `project` | `name`(이름), `language`(언어) | 관리자 alex가 프로젝트 Billing을 만들었습니다(언어 Python). |
| `project.update` | 프로젝트 — 프로젝트 정보 변경 | `project` | `description`(설명), `exclude_patterns`(제외 패턴) | 관리자 alex가 프로젝트 Billing의 설명, 제외 패턴을 바꿨습니다. |
| `project.delete` | 프로젝트 — 프로젝트 삭제 | `project` | `name`(이름), `language`(언어), `analyses`(분석 수), `defects`(결함 수), `snapshot_bytes`(스냅샷 크기) | 관리자 alex가 프로젝트 Old app을 삭제했습니다(분석 14건, 결함 230건). |
| `project.access_add` | 프로젝트 — 접근 권한 추가 | `project` | `subject_type`(권한 대상 종류), `subject_id`(권한 대상 번호), `name`(권한 대상 이름) | 관리자 alex가 프로젝트 Billing의 접근 권한에 그룹 Platform을 추가했습니다. |
| `project.access_remove` | 프로젝트 — 접근 권한 빼기 | `project` | `subject_type`(권한 대상 종류), `subject_id`(권한 대상 번호), `name`(권한 대상 이름) | 관리자 alex가 프로젝트 Billing의 접근 권한에서 사용자 jordan을 뺐습니다. |
| `run.detail_delete` | Run — Run 상세 삭제 | `project` | `runs`(Run), `reason`(사유) | 시스템이 프로젝트 Billing의 Run 상세 2개를 지웠습니다(보관 기간 지남). |
| `repo.save` | 저장소 — 저장소 연결 저장 · 변형: `created` | `project` | `kind`(종류), `created`(새 연결), `fields`(바뀐 항목), `auth_change`(자격 증명 변경) | 관리자 alex가 프로젝트 Billing에 Git 저장소 연결을 만들었습니다. |
| `repo.delete` | 저장소 — 저장소 연결 삭제 | `project` | — | 관리자 alex가 프로젝트 Billing의 저장소 연결을 지웠습니다. |
| `repo.test` | 저장소 — 프로젝트 연결 테스트 · 변형: `ok`, `fail` | `project` | `ok`(성공), `code`(결과 코드), `draft`(저장 전 값으로 테스트) | 관리자 alex가 프로젝트 Billing의 저장소 연결을 테스트했습니다(실패: repo_auth_failed). |
| `repo.fetch_now` | 저장소 — 지금 가져와 분석 · 변형: `force` | `project` | `analysis_id`(분석 번호), `kind`(종류) | 관리자 alex가 프로젝트 Billing의 저장소에서 지금 가져와 분석을 요청했습니다(분석 #42). |
| `repo.host_test` | 저장소 — 허용 호스트 연결 테스트 · 변형: `ok`, `fail` | `repo_host` | `code`(결과 코드), `ms`(걸린 시간) | 관리자 alex가 허용 호스트 git.example.com의 연결 테스트를 했습니다(성공). |
| `repo_host.add` | 허용 호스트 — 허용 호스트 추가 | `repo_host` | `allow_http`(HTTP 허용), `github_links`(GitHub 링크) | 관리자 alex가 허용 호스트 git.example.com을 추가했습니다(HTTP 허용 꺼짐, GitHub 링크 켜짐). |
| `repo_host.update` | 허용 호스트 — 허용 호스트 변경 | `repo_host` | `allow_http`(HTTP 허용), `github_links`(GitHub 링크) | 관리자 alex가 허용 호스트 git.example.com:8443의 설정을 바꿨습니다. |
| `repo_host.delete` | 허용 호스트 — 허용 호스트 삭제 | `repo_host` | — | 관리자 alex가 허용 호스트 git.example.com을 삭제했습니다. |
| `schedule.save` | 주기 분석 — 주기 분석 설정 | `project` | `changes`(changes), `next_run_at`(다음 실행) | 관리자 alex가 프로젝트 Billing의 주기 분석 설정을 바꿨습니다. |
| `schedule.auto_disable` | 주기 분석 — 주기 분석 자동 끄기 | `project` | `reason`(사유) | 관리자 alex가 프로젝트 Billing의 주기 분석을 자동으로 껐습니다(저장소 연결 해제). |
| `rules.override_set` | 규칙 — 규칙 켜기·끄기 · 변형: `on`, `off` | `rule` | `tool`(도구), `rule`(규칙), `before`(이전), `after`(이후), `base`(도구 기본값), `reason`(사유) | 관리자 alex가 규칙 ruff:F401을 껐습니다. |
| `rules.override_clear` | 규칙 — 규칙 기본값으로 | `rule` | `tool`(도구), `rule`(규칙), `before`(이전), `after`(이후) | 관리자 alex가 규칙 eslint:no-console의 변경을 지우고 기본값(켜짐)으로 되돌렸습니다. |
| `rules.override_reset_all` | 규칙 — 규칙 변경 모두 되돌리기 | `rule` | `tool`(도구), `count`(개수), `rules`(규칙 목록) | 관리자 alex가 ruff 규칙 변경 3개를 모두 기본값으로 되돌렸습니다. |
| `rules.override_bulk` | 규칙 — 규칙 일괄 변경 · 변형: `enable`, `disable`, `reset` | `rule` | `tool`(도구), `action`(작업), `target`(대상 조건), `total`(조건에 맞은 규칙), `changed`(바뀐 수), `unchanged`(그대로), `skipped`(건너뜀), `revision`(개정 번호), `reason`(사유), `rules`(규칙 목록), `rules_more`(목록에 없는 규칙 수) | 관리자 alex가 eslint 규칙 12개를 일괄로 껐습니다. |
| `rules.reload_request` | 규칙 — 규칙 설명 다시 불러오기 요청 | `rules` | `task_id`(작업 번호) | 관리자 alex가 규칙 설명 다시 불러오기를 요청했습니다. |
| `rules.load` | 규칙 — 규칙 설명 적재 | `rules` | `tools`(도구별 결과), `changed`(바뀐 수), `errors`(오류) | 서버 명령줄 도구가 규칙 설명 데이터를 적재했습니다(바뀐 규칙 5개, 오류 0건). |
| `export.defects` | 내보내기 — 결함 내보내기 · 변형: `mine` | `project` | `format`(형식), `rows`(행 수), `run`(Run), `filter`(조건) | 관리자 alex가 프로젝트 Billing의 결함 120건을 XLSX로 내보냈습니다. |
| `export.rules` | 내보내기 — 규칙 목록 내보내기 | `rules` | `format`(형식), `rows`(행 수), `filter`(조건) | 관리자 alex가 규칙 목록 800건을 CSV로 내보냈습니다. |
| `tool.block` | 분석 도구 — 도구 버전 차단 | `tool` | `reason`(사유) | 관리자 alex가 도구 버전 ruff@0.6.8을 차단했습니다. |
| `tool.unblock` | 분석 도구 — 도구 버전 차단 해제 | `tool` | — | 관리자 alex가 도구 버전 ruff@0.6.8의 차단을 풀었습니다. |
| `tool.pin` | 분석 도구 — 도구 버전 고정 | `tool` | — | 관리자 alex가 도구 버전 eslint@10.11.0을 사용 버전으로 고정했습니다. |
| `tool.unpin` | 분석 도구 — 도구 버전 고정 해제 | `tool` | — | 관리자 alex가 도구 eslint의 버전 고정을 풀었습니다. |
| `tool.mark_removal` | 분석 도구 — 삭제 예정 표시 | `tool` | — | 관리자 alex가 도구 버전 ruff@0.5.0을 삭제 예정으로 표시했습니다. |
| `tool.unmark_removal` | 분석 도구 — 삭제 예정 표시 해제 | `tool` | — | 관리자 alex가 도구 버전 ruff@0.5.0의 삭제 예정 표시를 지웠습니다. |
| `tool.package_staged` | 분석 도구 — 도구 패키지 올리기 | `tool_staging` | `tool`(도구), `size`(크기), `sha256`(SHA-256(앞부분)), `hash_match`(해시 일치) | 관리자 alex가 ruff 도구 패키지를 올렸습니다(설치 대기 #3, 15.0 MB). |
| `tool.package_discarded` | 분석 도구 — 도구 패키지 폐기 | `tool_staging` | `tool`(도구) | 관리자 alex가 올린 ruff 도구 패키지를 폐기했습니다(설치 대기 #3). |
| `tool.package_hash_set` | 분석 도구 — 도구 패키지 공개 해시 입력 | `tool_staging` | `hash_match`(해시 일치) | 관리자 alex가 올린 도구 패키지의 공개 해시를 입력했습니다(설치 대기 #3, 일치 예). |
| `tool.reverify_request` | 분석 도구 — 도구 다시 검증 요청 | `tool` | `task_id`(작업 번호), `merged`(기존 요청에 합침) | 관리자 alex가 도구 ruff@0.6.9의 다시 검증을 요청했습니다. |
| `worker.start_request` | 워커 — 워커 시작 요청 · 변형: `fail` | `worker` | `ok`(성공), `exit_code`(종료 코드), `reason`(사유) | 관리자 alex가 분석 워커 시작을 요청했습니다. |
| `worker.stop_request` | 워커 — 워커 정지 요청 | `worker` | `running_analyses`(진행 중 분석), `supervised`(서비스 관리자 아래 실행) | 관리자 alex가 분석 워커 정지를 요청했습니다(진행 중 분석 1건). |
| `worker.restart_request` | 워커 — 워커 재시작 요청 | `worker` | `running_analyses`(진행 중 분석), `supervised`(서비스 관리자 아래 실행) | 관리자 alex가 분석 워커 재시작을 요청했습니다(진행 중 분석 0건). |
| `disk.scan_request` | 디스크 — 디스크 사용량 다시 계산 요청 | `admin_task` | `merged`(기존 요청에 합침) | 관리자 alex가 디스크 사용량 다시 계산을 요청했습니다. |
| `cleanup.run_request` | 정리 작업 — 정리 작업 실행 요청 | `admin_task` | `merged`(기존 요청에 합침) | 관리자 alex가 정리 작업을 지금 실행하도록 요청했습니다. |
| `config.update` | 설정 — 설정 변경 | `config` | `old`(old), `new`(new), `old_source`(이전 값 출처), `file`(파일) | 관리자 alex가 설정 실패한 업로드 zip 보관을 1d → 3d로 바꿨습니다. |
| `maintenance.on` | 유지보수 모드 — 유지보수 모드 켜기 | `config`(`maintenance.enabled`) | `bundle`(켜기 직전 DB 백업 묶음) | 관리자 alex가 업그레이드 유지보수 모드를 켰습니다(직전 백업 pjguardian-20261014-220000-pre_upgrade.pjgbak). |
| `maintenance.off` | 유지보수 모드 — 유지보수 모드 끄기 | `config`(`maintenance.enabled`) | — | 관리자 alex가 업그레이드 유지보수 모드를 껐습니다. |
| `setting.update` | 화면 설정 — 화면 설정 변경 | `setting` | `key`(키 가져옴), `from`(이전), `to`(이후) | 관리자 alex가 설정 읽은 알림 보관 기간을 7 → 14로 바꿨습니다. |
| `backup.create` | 백업 — 백업 만들기 · 변형: `requested`, `ok`, `failed` | `backup` | `kind`(종류), `result`(result), `size`(크기), `ms`(걸린 시간) | 관리자 alex의 수동 백업 PJGuardian_backup_20261002_210000_manual.zip이 만들어졌습니다(50.0 MB). |
| `backup.delete` | 백업 — 백업 삭제 · 변형: `retention` | `backup` | `size`(크기), `reason`(사유) | 관리자 alex가 백업 PJGuardian_backup_20260901_210000_manual.zip을 삭제했습니다. |
| `backup.download` | 백업 — 백업 내려받기 | `backup` | `size`(크기) | 관리자 alex가 백업 PJGuardian_backup_20261002_210000_manual.zip을 내려받았습니다(50.0 MB). |
| `backup.upload` | 백업 — 백업 묶음 올리기 | `backup` | `size`(크기) | 관리자 alex가 백업 묶음 PJGuardian_backup_20260930_210000_daily.zip을 올렸습니다(40.0 MB). |
| `backup.verify` | 백업 — 백업 검증 · 변형: `ok`, `fail` | `backup` | `result`(result), `code`(결과 코드) | 관리자 alex가 백업 PJGuardian_backup_20261002_210000_manual.zip을 검증했습니다(정상). |
| `restore.start` | 복원 — 복원 시작 | `backup` | `wait_mode`(대기 방식), `config_keys`(복원할 설정 수), `key`(키 가져옴) | 관리자 alex가 백업 PJGuardian_backup_20261002_210000_manual.zip으로 복원을 시작했습니다. |
| `restore.finish` | 복원 — 복원 완료 | `backup` | `schema_from`(이전 스키마), `migrated`(마이그레이션 적용), `config_applied`(설정 적용), `key_imported`(키 가져옴), `credentials_unreadable`(풀 수 없는 자격 증명), `sessions_cleared`(지운 세션) | 복원 작업(alex)이 백업 PJGuardian_backup_20261002_210000_manual.zip의 복원을 마쳤습니다(지운 세션 5개). |
| `restore.rollback` | 복원 — 복원 되돌리기 | `backup` | `code`(결과 코드) | 복원 작업(alex)이 복원에 실패해 이전 상태로 되돌렸습니다(restore_io_failed). |
| `restore.rehearsal` | 복원 — 복원 리허설 · 변형: `ok`, `fail` | `backup` | `result`(result), `code`(결과 코드), `ms`(걸린 시간) | 관리자 alex가 백업 PJGuardian_backup_20261002_210000_manual.zip으로 복원 리허설을 했습니다(실패: bundle_hash_mismatch). |
| `key.export` | 암호화 키 — 키 내보내기 | `backup` | `result`(result) | 관리자 alex가 자격 증명 암호화 키를 내보냈습니다(키 지문 SHA256:ab12cd34). |
| `key.import` | 암호화 키 — 키 가져오기 | `backup` | `result`(result), `bundle`(백업 묶음) | 관리자 alex가 백업 PJGuardian_backup_20261002_210000_manual.zip의 자격 증명 암호화 키를 가져왔습니다(키 지문 SHA256:ab12cd34). |
| `faq.create` | FAQ — FAQ 추가 · 변형: `draft` | `faq` | `category`(분류), `question`(질문), `answer_length`(답변 길이), `answer_sha256`(답변 해시(앞부분)), `published`(공개) | 관리자 alex가 FAQ 항목을 공개로 추가했습니다: 비밀번호를 잊었어요 |
| `faq.update` | FAQ — FAQ 수정 | `faq` | `fields`(바뀐 항목), `question`(질문), `answer_length`(답변 길이), `answer_sha256`(답변 해시(앞부분)) | 관리자 alex가 FAQ 항목의 답변을 고쳤습니다: 비밀번호를 잊었어요 |
| `faq.publish` | FAQ — FAQ 공개 전환 · 변형: `show`, `hide` | `faq` | `published`(공개), `question`(질문) | 관리자 alex가 FAQ 항목을 비공개로 바꿨습니다: 비밀번호를 잊었어요 |
| `faq.delete` | FAQ — FAQ 삭제 | `faq` | `category`(분류), `question`(질문) | 관리자 alex가 FAQ 항목을 삭제했습니다: 예전 질문 |
| `faq.reorder` | FAQ — FAQ 순서 변경 | `faq_category` | `ids`(항목 순서) | 관리자 alex가 FAQ 분류 계정의 항목 순서를 바꿨습니다. |
| `faq.import` | FAQ — FAQ 시드 가져오기 | `faq` | `added`(추가), `skipped`(건너뜀), `published`(공개), `source`(출처) | 서버 명령줄 도구가 FAQ 시드를 가져왔습니다(추가 35개, 건너뜀 0개). |
| `audit.export` | 감사 로그 — 감사 로그 내보내기 | `audit_log` | `format`(형식), `rows`(행 수), `filter`(조건) | 관리자 alex가 감사 로그 250건을 CSV로 내보냈습니다. |
| `audit.purge` | 감사 로그 — 감사 로그 보존 정리 | `audit_log` | `deleted`(지운 행), `through_id`(마지막으로 지운 번호), `through_at`(마지막으로 지운 시각), `cutoff`(기준 시각) | 시스템이 보존 기간이 지난 감사 로그 1,200건을 정리했습니다. |
| `mail.secret_set` | 관리자 이메일 — SMTP 비밀번호 저장 · 변형: `replaced` | — | `replaced`(기존 값 바꿈) | 관리자 alex가 관리자 이메일 SMTP 비밀번호를 저장했습니다. |
| `mail.secret_delete` | 관리자 이메일 — SMTP 비밀번호 삭제 | — | (없음) | 관리자 alex가 관리자 이메일 SMTP 비밀번호를 지웠습니다. |
| `mail.test` | 관리자 이메일 — 시험 메일 보내기 · 변형: `ok`, `fail` | — | `ok`(성공), `code`(실패 사유 connect·tls·auth·rejected·timeout·config·invalid), `recipient`(받는 사람, 첫 글자·도메인만) | 관리자 alex가 시험 메일을 o***@example.com에게 보내려 했지만 실패했습니다(auth). |

샘플에 없는 선택 키: `user.create`의 `group_id`·`initial_admin`·`auth_setup`·`source`(`csv`), `user.deactivate`·`user.activate`의 `source`(`csv`), `user.update`의 바뀐 항목(`display_name`·`email` 중 바뀐 것만 `{from,to}`), `project.update`의 `name`·`description`·`exclude_patterns`(바뀐 것만), `group.update`·`group.rename`의 `name`·`description`(바뀐 것만), `repo.fetch_now`의 `force`, `worker.start_request` 실패의 `reason`(`invalid_command` 등), `backup.create`의 결과별 모양(요청 `{kind, result:requested, snapshots}`·성공 `{kind, result:ok, size, ms}`·실패 `{kind, result:failed, code}`), `backup.delete`의 보관 정리 모양(`{files, reason:retention}`, 대상 없음), `export.defects`의 내 결함 모양(대상 `mine`, `{format, rows, project, status}`), `rules.override_bulk`의 `action`(`enable`·`disable`·`reset`).

## 보존과 체인

- 보존 기간 `log.audit_retention`(기본 90일, 설정 관리 > 보존 기간 — 90일 미만으로 줄이면 저장 전에 한 번 더 확인). `tools/cleanup.php`(매일)와 점검 탭의 "지금 정리 실행"이 앞에서부터 `log.audit_purge_batch`행씩 지우고 체인 기준점(`audit_log_meta`: `anchor_hash` = 마지막으로 지운 행의 해시, `purged_through` = 그 번호, `purged_through_at` = 그 시각(030), `purged_at` = 정리 시각)을 옮긴 뒤 `audit.purge` 한 줄을 남긴다. `--dry-run`은 건수만.
- 무결성 검사: 화면 "무결성 검사"(API `/api/v1/admin/audit-logs/integrity`를 조각으로 이어 부름) 또는 `php tools/audit_verify.php`. 기준점부터 남은 행의 해시 체인을 다시 계산해 첫 불일치 행(번호·시각·사유)을 알려 준다.
- **DB 밖 기록(P31, `ServicesAuditAuditAnchor`)**: 워커가 `log.audit_anchor_interval`(1시간)마다, 보존 정리 직후에 `{at, last_id, last_hash, purged_through, anchor_hash}` 한 줄을 로그 폴더 `audit-anchor.jsonl`(웹 루트 밖, 덧붙이기만, Linux 0640)에 남긴다(마지막 기록과 같으면 안 씀). 무결성 검사 API가 끝까지 검사하면 `anchor`(ok·mismatch·unknown)를 더하고, 모니터링이 `audit_anchor_mismatch`(오류)로 판정한다. 대조: 경계보다 뒤의 기록은 그 행·해시가 그대로여야 함(`missing_row`·`hash_changed`), 경계와 같은 기록은 `anchor_hash`와 같아야 함, 경계가 기록보다 앞이면 `purge_rewound`, 정리로 지운 범위는 건너뜀. 복원하면 파일을 `audit-anchor.<UTC>.before-restore.jsonl`로 옮기고 새로 시작. 파일이 없으면 unknown(경고 아님). 이 파일을 서버 밖으로 정기 복사하면 서버 전체를 가진 사람의 위조까지 잡을 수 있다(운영 권장).
