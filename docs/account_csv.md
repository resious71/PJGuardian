# 계정 CSV 일괄 처리 (P28A, 명세 19.2)

관리자가 CSV 파일로 **계정을 한꺼번에 만들거나 비활성화**한다. 화면: 관리자 메뉴 "사용자 관리" → **CSV로 일괄 처리**(`/admin/users/import`, admin만).
기존 계정의 수정(역할·이름·재활성화)·그룹 지정·비밀번호 지정은 하지 않는다. 그룹 구성원은 그룹 화면에서 관리한다.

## 1. 형식

| 열 | 필수 | 설명 |
| --- | --- | --- |
| `action` | 아니오 | `create`(비우면 이것) 또는 `deactivate` |
| `login_id` | 예 | 영문·숫자로 시작, 영문·숫자·`. _ @ -`, 100자 이하(X06 규칙). 기존 계정·파일 안 중복은 **대소문자 무시** |
| `display_name` | create일 때 예 | 1~100자(NFC), 제어·양방향 서식 문자 금지, 다른 사용자(파일 안 다른 행 포함)의 로그인 ID와 같으면 안 됨 |
| `email` | 아니오 | 형식만 검사(254자 이하). 다른 계정·앞 행과 같으면 **경고**(이 앱은 이메일 유일성을 요구하지 않음) |
| `role` | 아니오 | `user`(기본) 또는 `admin`. admin은 미리보기에 경고 배지, 실행 확인에 "관리자 N명을 만듭니다" |
| `auth_type` | 아니오 | 비우면 기본 방식. **켜진 인증 방식**(`AuthProviderRegistry::enabledTypes()`, 지금 `local`)만. 아는 방식(ldap·oidc·saml)이지만 꺼져 있으면 "아직 켜지지 않은 인증 방식", 모르는 값은 "알 수 없는 인증 방식" |

- 첫 줄은 머리글(앞뒤 공백·대소문자 무시, 순서 자유). 같은 열이 두 번이면 파일 오류. 모르는 열은 경고 후 무시, `group`·`groups`·`group_name(s)` 열은 "그룹은 CSV로 관리하지 않습니다" 경고 후 무시.
- 쉼표 구분, 큰따옴표 인용(`""`는 따옴표 하나, 셀 안 쉼표·줄바꿈), CRLF·LF, 빈 줄·공백만 있는 줄은 건너뜀. 머리글보다 칸이 많고 넘친 칸에 값이 있으면 그 행은 오류(쉼표가 든 값을 따옴표로 묶지 않은 경우).
- 한도: **1,000행, 2 MiB**(`Services\Accounts\AccountCsvReader::MAX_ROWS`·`MAX_BYTES` 한 곳). 넘으면 파일 오류(본문이 2 MiB를 넘으면 API 413).
- 행 번호 = 파일의 줄 번호(머리글이 1, 셀 안 줄바꿈이 있으면 그 기록이 시작한 줄) — Excel의 행 번호와 같다(셀 안 줄바꿈이 없을 때).
- **양식 내려받기**: 머리글 + 예시 2행(create 1, deactivate 1), UTF-8 BOM(Excel에서 한글이 깨지지 않게).

## 2. 인코딩

- 자동 판별(기본): BOM이 있으면 UTF-8. 없으면 UTF-8로 유효한지 보고, 아니면 **CP949**(한국어 Excel의 CSV 저장 기본)로 바꾼 뒤 다시 CP949로 되돌린 바이트가 원래와 같을 때만 CP949로 인정한다(zip 파일 이름과 같은 왕복 검사, P05). 둘 다 아니면 "UTF-8로도 CP949로도 읽을 수 없습니다".
- 사용자가 UTF-8 / CP949를 직접 고를 수 있다(미리보기에서 "이 인코딩으로 다시 읽기"). 고른 인코딩으로 읽을 수 없으면 오류. 미리보기 위에 **인식한 인코딩**(BOM 여부 포함)을 보인다.
- ASCII만 있는 파일은 UTF-8로 본다(CP949를 골라도 같은 글).

## 3. 미리보기 → 실행 (서버가 판정, 서버 상태 없음)

1. **미리보기** `POST /api/v1/admin/account-import/preview?encoding=` — 본문 = 파일 그대로. 읽기·검증만 하고 아무것도 저장하지 않는다(감사 로그도 없음). 행별 판정 `ok` / `warning` / `error`와 사유 코드, 요약(생성·비활성화·건너뜀·오류·경고·관리자 생성), **digest** = SHA-256(파일 해시 + 인식한 인코딩 + 행별 판정)을 돌려준다.
2. **실행** `POST /api/v1/admin/account-import/execute?encoding=&digest=&skip_errors=` — 본문 = **미리보기와 같은 파일을 다시 보낸다**(파일 재전송 방식: 서버가 미리보기 결과를 보관하지 않아 만료·정리가 필요 없다). 서버는 같은 검증을 다시 하고 digest가 같을 때만 실행한다.
   - 파일이 다르거나, 미리보기 뒤(또는 실행 중 해시를 만드는 사이) 같은 ID가 생기는 등 **판정이 바뀌면 409 `import_preview_stale`** — 아무것도 반영하지 않는다. 화면은 같은 파일로 미리보기를 다시 해 새 판정을 보인다.
   - 오류 행이 있으면 "오류 행 제외하고 실행"(`skip_errors=1`)을 골라야 한다(없으면 422 `import_has_errors`). 처리할 행이 없으면 422 `import_nothing_to_do`.
   - **전부 반영하거나 하나도 반영하지 않는다**: 쓰기 트랜잭션 안에서 다시 판정·digest를 확인한 뒤 생성·비활성화·감사 로그를 쓰고, 중간에 실패하면 전체를 되돌린다.
   - 로컬 계정의 bcrypt 해시는 쓰기 트랜잭션 **밖에서** 미리 만든다(행마다 수십 ms — 1,000행이면 1분 남짓, 그동안 DB 쓰기를 막지 않음). 요청 시간 상한은 이 요청만 600초로 늘린다(제한이 없거나 더 길면 그대로 — 줄이지 않음).
3. **결과**: 처리 건수와 행별 결과(생성됨·비활성화됨·건너뜀·오류(건너뜀)). 로컬 계정의 **임시 비밀번호는 이 화면에서 한 번만** 보인다(새로 고치거나 떠나면 사라짐, 서버는 평문을 보관하지 않음). "결과 CSV 내려받기"는 이 화면에서 **한 번**(브라우저가 응답으로 만든 파일, 서버 보관 없음) — 임시 비밀번호 열 포함, 셀이 `= + - @`로 시작하면 앞에 작은따옴표. 임시 비밀번호는 이 문자로 시작하지 않게 만들어 결과 파일의 값이 화면 값과 같다.

임시 비밀번호의 유효 시간(`auth.temp_password_expiry`, 기본 24시간)·첫 로그인 때 변경 강제는 사용자 관리의 계정 생성(P10)과 같다.

## 4. 검증 규칙

| 동작 | 규칙 | 판정 |
| --- | --- | --- |
| 모두 | 로그인 ID 비었음 / 형식 / 파일 안 앞 행과 같음(대소문자 무시, 동작과 무관) | 오류 `login_id_required` / `login_id_invalid` / `duplicate_in_file` |
| 모두 | action 값이 create·deactivate가 아님 | 오류 `action_invalid` |
| 모두 | 머리글보다 칸이 많고 값이 있음 | 오류 `column_count` |
| create | 이미 있는 계정(대소문자 무시) | 오류 `login_id_taken` |
| create | 표시 이름 비었음·100자 초과·금지 문자·다른 사용자의 로그인 ID | 오류 `display_name_required`·`_too_long`·`_invalid`·`_reserved` |
| create | 이메일 형식·길이 | 오류 `email_invalid`·`email_too_long` |
| create | 이메일이 다른 계정·앞 행과 같음 | 경고 `email_in_use`(만듦) |
| create | role 값 | 오류 `role_invalid`, admin이면 경고 `role_admin`(만듦) |
| create | 인증 방식 모름 / 꺼짐 | 오류 `auth_type_invalid` / `auth_type_not_enabled` |
| deactivate | 없는 계정 | 오류 `user_not_found` |
| deactivate | 자기 자신 | 오류 `self_deactivate` |
| deactivate | 이 파일의 비활성화를 모두 반영하면 **활성 로컬(앱) 관리자가 한 명도 남지 않음**(앞 행부터 세어 마지막 관리자 행) | 오류 `last_admin` |
| deactivate | 이미 비활성 | 경고 `already_inactive`(건너뜀) |
| deactivate | 이름·이메일·역할·인증 방식 칸에 값이 있음 | 경고 `fields_ignored` |

- 한 행의 오류는 다른 행의 판정에 영향을 주지 않는다(파일 안 중복은 앞 행이 기준).
- 비활성화되는 사용자의 세션은 즉시 폐기한다(P10 `SessionService::destroyForUser`). DB 트리거(마지막 활성 관리자)는 그대로 한 번 더 막는다.
- 행위자는 활성 관리자라 자기 자신을 비활성화할 수 없으므로 화면에서 `last_admin`은 행위자가 로컬 관리자가 아닐 때(P28B 이후 외부 인증 관리자)에만 생긴다.

## 5. 감사 로그·로그

- 실행 1건당 `user.bulk_import`: `{created, deactivated, skipped, errors_skipped, warnings, rows, encoding, file_sha256(앞 12자), skip_errors}`.
- 사용자별: `user.create {login_id, role, auth_type, source: csv}`, `user.deactivate {login_id, source: csv}`(사용자 관리 화면과 같은 이름, 출처만 다름).
- 임시 비밀번호·이메일·CSV 원문은 감사 로그·로그·오류 메시지에 남기지 않는다(로그는 건수와 파일 해시 앞 12자만).

## 6. 인증 방식 목록(P28B~D 준비)

`Services\Auth\AuthProviderRegistry`가 켜진 방식 목록 한 곳이다(`enabledTypes()`, 이 단계는 `['local']`). CSV 검증과 사용자 생성 API(`POST /api/v1/users`의 `auth_type`, 켜지지 않은 값은 400 `auth_type_not_enabled`)가 이 목록을 쓴다.
새 방식을 켜면 이 목록과 화면 라벨(`account.auth_<방식>`, ko·en)만 늘리면 CSV가 그 값을 받아들인다. 외부 인증 계정은 비밀번호가 없으므로 임시 비밀번호를 발급하지 않는다(P28B에서 생성 경로를 더함 — 지금 DB 제약도 local·ldap만).
