---
key: audit-log
title: 감사 로그
order: 22
summary: 감사 로그에 남는 것, 감사 로그 화면의 조건·설명·상세(요약·바뀐 항목·기술 정보)·무결성 검사·CSV 내보내기, 보존 기간 정리와 해시 체인 기준점, 서버 검증 명령, 작업 이름 목록
keywords: 감사 로그, 감사, 해시 체인, 무결성 검사, audit_verify, CSV 내보내기, 보존 기간, 정리, 작업 이름, 행위자, 요청 ID, 설명, 바뀐 항목, 설명 없음, 원본
related: monitoring, security, retention-disk, settings, troubleshooting
audience: admin
---
## 무엇이 남나요

감사 로그는 관리자 작업과 중요한 보안 사건을 지울 수 없는 기록으로 남깁니다. 행마다 시각(UTC), 행위자(로그인 ID 또는 cli·system), 작업 이름, 대상 종류와 ID, 세부 정보, IP, 해시가 있습니다. 비밀번호·토큰·키 원문은 남기지 않습니다.

- **남지 않는 것**: 로그인 성공·실패와 로그아웃은 감사 로그가 아니라 앱 로그에 남습니다(IP 차단·잠금 같은 보안 사건만 `auth.login_failed`). 결함 수정과 분석 취소도 감사 대상이 아닙니다.
- **수정·삭제 불가**: DB가 행 수정을 거부하고, 화면에도 고치거나 지우는 기능이 없습니다. 각 행은 앞 행의 해시를 이어 받는 **해시 체인**이라, 중간 행을 바꾸거나 지우면 검사에서 드러납니다.

## 감사 로그 화면

관리자 메뉴의 {{icon:admin.audit}} {{ui:icon.admin.audit}}([/admin/audit-logs](/admin/audit-logs))를 엽니다. 관리자만 볼 수 있고 읽기 전용입니다. 최신 항목이 위에 옵니다.

### 조건

- **{{ui:audit.filter.period}}**: 전체, 최근 1·7·30·90일, 직접 지정(시작일·종료일). 날짜는 화면 시간대 기준이고 저장은 UTC입니다.
- **{{ui:audit.filter.actor}}**: 로그인 ID 일부로 찾습니다.
- **{{ui:audit.filter.action}}**: 분류(사용자·그룹·프로젝트·백업…)로 묶여 있고, 각 작업은 읽을 수 있는 이름과 작업 이름이 함께 보입니다(예: "사용자 — 역할 변경 (user.role_change)"). 분류 전체(예: "백업 전체 (backup.*)")도 고를 수 있습니다.
- **{{ui:audit.filter.target}}**, **{{ui:audit.filter.q}}**: 대상과 자유 검색(행위자·작업·대상·세부 정보에서 찾음).

조건은 고르기만 해서는 적용되지 않습니다. **{{ui:audit.apply}}**를 누르거나 입력칸에서 Enter를 누르면 적용됩니다. **{{ui:audit.reset}}**은 조건을 비웁니다. 적용한 조건은 주소에 들어가므로 링크로 공유할 수 있고, 뒤로·앞으로 가기도 그대로 동작합니다.

### 목록과 설명

표의 열은 왼쪽부터 **{{ui:audit.col_rule}}**, **{{ui:audit.col_summary}}**, 행위자, 대상, 결과, 시각, 요청 ID입니다.

- **{{ui:audit.col_rule}}**은 어떤 작업인지를 보여 줍니다. 기술용 작업 이름(예: `user.role_change`)이 보이고, 마우스를 올리면 분류와 작업 이름(예: "사용자 — 역할 변경")이 보입니다. 설명 문구가 없는 작업은 **{{ui:audit.unknown_badge}}** 배지가 함께 붙습니다.
- **{{ui:audit.col_summary}}**은 "누가 무엇을 어떻게 했는지"를 한 문장으로 보여 줍니다(예: "관리자 alex가 사용자 Jordan Lee (jordan)의 역할을 일반 사용자 → 관리자로 바꿨습니다."). 긴 문장은 두 줄까지 보이고, 마우스를 올리거나 상세를 열면 전체가 보입니다.
- **대상**은 번호 대신 이름(사용자는 표시 이름과 로그인 ID, 프로젝트·그룹 이름, FAQ 질문)으로 보입니다. 이미 지운 대상은 기록에 남은 이름이나 "삭제된 항목(#번호)"으로 보입니다.
- **결과**는 성공·실패·요청됨이고, 글자 앞에 모양이 다른 아이콘이 붙습니다(성공은 파란 체크, 실패는 빨간 엑스, 요청됨은 회색 시계).
- **시각**은 화면 시간대 기준 `YY/MM/DD HH:MM`(예: `26/10/13 18:53`)입니다. 마우스를 올리면 초까지의 전체 시각과 UTC가, 상세에서는 화면 시간대와 UTC 시각이 보입니다.
- 설명은 화면을 열 때 만들어지며, 저장된 감사 기록과 해시 체인은 바뀌지 않습니다. 화면 언어를 바꾸면 설명도 그 언어로 보입니다.

### 상세 화면 읽는 법

행의 **{{ui:audit.detail_button}}** 단추를 누르면 위에서부터 다음 순서로 보입니다.

1. **한 문장 요약**(크게).
2. **{{ui:audit.section.changes}}**: 바뀐 항목을 항목·이전·이후 표로 보여 줍니다. 항목 이름과 값은 화면에서 쓰는 말로 풀어 씁니다(예: `admin` → 관리자, 켜짐/꺼짐, 비어 있으면 "(없음)", 시각은 화면 시간대). 바뀐 것이 없는 작업은 이 표가 없습니다.
3. **{{ui:audit.section.context}}**: 건수·사유 같은 나머지 기록 값.
4. **{{ui:audit.section.who}}**: 행위자(이름과 로그인 ID), 화면 시간대와 UTC 시각, 출처(웹·CLI·워커·예약 작업·로그인 화면), IP.
5. **{{ui:audit.section.target}}**: 대상 이름과 그 화면으로 가는 링크(지운 대상은 링크 없음).
6. **{{ui:audit.section.result}}**: 실패면 이유를 풀어 쓰고, 가이드의 오류 코드 탭에 있는 코드는 풀이로 가는 링크를 붙입니다.
7. **{{ui:audit.section.tech}}**(접혀 있음): 작업 이름, 요청 ID, 행 번호, 해시 앞 8자, 원본 세부 정보(JSON). **{{ui:audit.copy_json}}**으로 원본을 복사할 수 있습니다.

가려진 값(비밀번호류 이름의 값, 문자열 속 비밀, 이메일 주소의 앞 글자 뒤)은 설명과 원본 모두에서 가린 그대로이며 다시 풀지 않습니다. 공백 없이 아주 긴 값은 설명에서 "(긴 값 생략)"으로 줄이고 원본에만 둡니다.

**{{ui:audit.unknown_badge}}** 배지는 설명 문구가 없는 작업(앱보다 새 버전에서 생긴 작업 등)이라는 뜻입니다. 이때는 "작업 xxx가 기록되었습니다." 같은 일반 문장과 원본 값만 보이며, 기술 정보의 원본 세부 정보로 내용을 확인합니다. 옛 기록처럼 세부 정보가 빠진 경우에는 작업 이름만 쓴 일반 문장이 보입니다.

### 무결성 검사

**{{ui:audit.integrity}}**을 누르면 해시 체인 전체를 조각으로 나눠 검사하며 진행 막대를 보여 줍니다.

- 정상이면 몇 건의 체인이 끊김 없이 이어지는지 보입니다.
- 불일치면 처음 끊긴 항목 번호·시각과 이유(앞 행과 연결이 맞지 않음 — 행이 지워졌거나 끼워 넣어짐, 또는 행 내용이 해시와 다름)가 보입니다.
- 보존 기간 정리로 지운 경계(어느 번호·시각까지 지웠는지)와 남은 범위도 함께 보입니다.

검사 실행 자체는 감사 로그가 아니라 앱 로그에만 남습니다.

### CSV 내보내기

**{{ui:audit.export}}**은 지금 적용한 조건의 결과를 CSV(UTF-8 BOM)로 내려받습니다. 마지막 열 "설명"에 화면 언어의 한 문장 요약이 들어갑니다. 최대 {{limit:export.max_rows}}행이며, 넘으면 파일을 만들지 않고 조건을 좁히라고 안내합니다(`export_too_large`). 수식으로 해석될 수 있는 셀은 앞에 작은따옴표를 붙입니다. 내보내기는 `audit.export`로 감사 로그에 남습니다.

## 보존 기간과 정리

- 매일 도는 정리 작업(tools/cleanup.php)이 {{limit:log.audit_retention}}보다 오래된 항목을 앞에서부터 {{limit:log.audit_purge_batch}}건씩 나눠 지웁니다. 점검 탭의 {{ui:admin_checks.cleanup_run}}도 같은 일을 합니다.
- 지울 때마다 체인의 **기준점**(마지막으로 지운 항목 번호·시각·해시)을 옮기므로, 남은 항목의 검사는 계속 정상입니다. 정리하면 `audit.purge` 한 줄(지운 건수와 경계)이 남습니다.
- `--dry-run`을 붙이면 지울 건수만 보여 줍니다.
- 보존 기간은 **시스템 관리 > {{ui:config_settings.title}} > {{ui:config_settings.group.retention}}** 탭의 "{{ui:config_settings.key.log.audit_retention.label}}"에서 바꿉니다. 90일보다 짧게 줄이면 저장 전에 한 번 더 확인합니다. 줄이면 다음 정리 때 오래된 항목이 지워지고 되돌릴 수 없습니다.
- 마이그레이션 030을 적용하기 전에는 경계의 시각 없이 번호만 보이지만 정리는 동작합니다.

## 서버에서 검증하기

```
php tools/audit_verify.php
php tools/audit_verify.php --db=<DB 사본 경로>
```

- 종료 코드 0: 정상. 마지막 행과 마지막 해시를 출력합니다. 정리로 지운 범위가 있으면 기준점에서 시작했다고 알려 줍니다.
- 종료 코드 2: 변조 탐지. 끊긴 행 id와 이유를 출력합니다.
- 종료 코드 1: 실행 오류(DB 없음 등).

DB 파일에 쓸 수 있는 사람은 체인 전체를 다시 계산할 수 있습니다. 그래서 워커가 1시간마다(그리고 보존 정리 직후) 마지막 항목의 번호·해시와 정리 경계를 **DB 밖**(데이터 폴더 logs 아래 `audit-anchor.jsonl`, 덧붙이기만)에 남기고, **{{ui:audit.integrity}}**은 체인 검사가 끝나면 이 기록과도 대조해 한 줄로 보여 줍니다(일치·불일치·아직 기록 없음). 불일치면 시스템 모니터링에 오류가 뜹니다. 끝의 항목을 지우거나 체인을 다시 계산한 위조도 여기서 드러납니다. 복원하면 DB가 그 시점으로 돌아가므로 기록 파일은 옆(`audit-anchor.<시각>.before-restore.jsonl`)으로 옮기고 새로 시작합니다. 이 파일도 서버 밖(백업 기록 등)에 정기적으로 복사해 두면 더 안전합니다. 변조가 탐지되면 서버 접근 기록을 확인하고 [백업과 복원](guide:backup-restore)의 이전 백업과 비교합니다. 운영 DB 파일을 직접 고치지 마세요.

복원하면 DB가 백업 시점으로 바뀌므로 감사 로그도 그 시점으로 돌아갑니다. `restore.finish`는 복원된 DB에, `restore.rollback`은 복원 전 DB에 남습니다.

## 작업 이름

작업 이름은 `<대상>.<동작>` 형식이며 화면의 {{ui:audit.filter.action}} 선택지와 같습니다. 작업마다의 뜻과 세부 정보 항목은 서버의 `docs/audit_actions.md`에 있습니다.

- **계정**: `user.create`, `user.update`, `user.role_change`, `user.deactivate`, `user.activate`, `user.temp_password`, `user.password_set`, `user.unlock`, `user.auth_type_change`, `user.bulk_import`, `user.pending_create`, `user.approve`, `user.reject`, `user.pending_delete`, `user.pending_purge`, `user.external_link`
- **서버 명령줄 계정 복구**: `cli.user_unlock`, `cli.user_password_reset`
- **인증**: `auth.login_failed`(세부 정보에 사유), `auth.ldap_secret_set`, `auth.ldap_secret_delete`, `auth.oidc_secret_set`, `auth.oidc_secret_delete`, `auth.saml_sp_key_generate`, `auth.saml_sp_key_delete`, `auth.saml_metadata_import`
- **그룹**: `group.create`, `group.rename`, `group.update`, `group.delete`, `group.member_add`, `group.member_remove`, `group.manager_set`, `group.manager_unset`
- **프로젝트·Run**: `project.create`, `project.update`, `project.delete`, `project.access_add`, `project.access_remove`, `run.detail_delete`
- **저장소·주기 분석**: `repo.save`, `repo.delete`, `repo.test`, `repo.fetch_now`, `repo.host_test`, `repo_host.add`, `repo_host.update`, `repo_host.delete`, `schedule.save`, `schedule.auto_disable`
- **규칙**: `rules.override_set`, `rules.override_clear`, `rules.override_reset_all`, `rules.override_bulk`, `rules.reload_request`, `rules.load`
- **내보내기**: `export.defects`, `export.rules`
- **분석 도구**: `tool.block`, `tool.unblock`, `tool.pin`, `tool.unpin`, `tool.mark_removal`, `tool.unmark_removal`, `tool.package_staged`, `tool.package_discarded`, `tool.package_hash_set`, `tool.reverify_request`
- **워커·점검**: `worker.start_request`, `worker.stop_request`, `worker.restart_request`, `disk.scan_request`, `cleanup.run_request`
- **설정**: `config.update`(설정 관리의 설정 항목), `setting.update`(알림 보관 기간 등 DB 화면 설정)
- **백업·복원**: `backup.create`, `backup.delete`, `backup.download`, `backup.upload`, `backup.verify`, `restore.start`, `restore.finish`, `restore.rollback`, `restore.rehearsal`, `key.export`, `key.import`
- **FAQ**: `faq.create`, `faq.update`, `faq.publish`, `faq.delete`, `faq.reorder`, `faq.import`(시드 일괄 가져오기 한 줄 — 추가·건너뜀 건수)(답변 원문은 남기지 않고 길이·해시만)
- **감사 로그**: `audit.export`(CSV 내보내기), `audit.purge`(보존 기간 정리)
- **관리자 이메일**: `mail.secret_set`, `mail.secret_delete`(SMTP 비밀번호 — 값은 남기지 않음), `mail.test`(시험 메일 — 받는 사람은 가림). 장애 메일 자동 발송은 감사 로그가 아니라 앱 로그에 남습니다

## 관련 절

- [시스템 점검과 모니터링](guide:monitoring)
- [보안 운영](guide:security)
- [보존 기간과 디스크](guide:retention-disk)
- [설정 관리](guide:settings)
- [백업과 복원](guide:backup-restore)
