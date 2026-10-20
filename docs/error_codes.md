# 오류·실패 코드표 (명세 15.2, P19)

업로드 오류와 분석 실패를 사용자에게 보여 주는 기준이다. 코드마다 **제목·원인·사용자가 할 수 있는 조치·가이드 링크 키**가 있다.
- 구현: `src/Support/ErrorCatalog.php`(코드 목록), 문구 `src/Lang/ko.php`의 `failure.<종류>.<코드>.title|cause|action`.
- 이 표는 그 카탈로그에서 뽑아 만든 것이다. `tests/Integration/AnalysisResultTest`가 코드마다 문구가 있고 이 문서에 실려 있는지 검사한다.
- 가이드 링크 키(P29): 사용자 가이드 절 key — 업로드 `upload-errors`, 분석 `analysis-failures`, SSO 로그인 `login-errors`(`ErrorCatalog::GUIDE_SECTION`). 화면의 "자세히 보기"는 `/guide/user#<key>`, 코드는 `/guide/errors#<코드>`(오류 코드 탭, 카탈로그에서 자동). P19~P28D에는 문구 키 모양(`upload.error.<코드>`·`analysis.failure.<코드>`)이었다. SSO 코드 `E-SSO-*`도 카탈로그(`ErrorCatalog::SSO`, 문구 `failure.sso.<코드>.*`)에 들어가 오류 코드 탭에 보인다.
- 사용자 문구에는 서버 내부 정보(경로, 스택, 예외 메시지)를 넣지 않는다. 금지 항목 등은 zip 안의 항목 이름만 덧붙인다(`error_message`, 화면은 textContent).
- 모르는 코드는 일반 문구 + 코드("관리자에게 코드를 알려 주세요"), 가이드 키 없음.
- 분석 행에 저장하는 한 문장 문구(`analyses.failure_message`)는 `analysis.failure.<코드>`(P14)이고, 로그·이력에 남는다. 화면은 카탈로그(원인·조치)를 쓴다.

## 1. 업로드 오류(업로드 상태 화면, GET /api/v1/uploads/{id}의 error)

| 코드 | 제목 | 원인 | 사용자 조치 | 가이드 링크 키 |
| --- | --- | --- | --- | --- |
| `invalid_zip` | 유효하지 않은 zip | zip 형식이 아니거나 파일이 손상되었습니다(중간에 잘림, 같은 이름 항목 중복 등). | 압축 프로그램으로 zip을 다시 만들어 올리세요. 폴더를 직접 고르면 브라우저가 zip으로 묶어 줍니다. | `upload.error.invalid_zip` |
| `zip_too_large` | 업로드 용량 초과 | zip 크기가 업로드 한도(기본 2GB)를 넘습니다. | 분석에 필요 없는 파일(빌드 결과, 의존성 폴더, 데이터 파일)을 빼고 다시 묶어 올리세요. | `upload.error.zip_too_large` |
| `extract_limit` | 해제 후 총량·파일 수 초과 | 압축을 푼 크기(기본 10GB) 또는 파일 수(기본 100만 개) 한도를 넘습니다. | 큰 데이터·생성 파일을 빼거나, 관리자에게 프로젝트 제외 패턴 추가를 요청하세요. | `upload.error.extract_limit` |
| `forbidden_entry` | 금지 항목 포함 | 경로 조작(..), 절대 경로, 역슬래시 경로, 심볼릭 링크, zip 안의 zip 같은 허용하지 않는 항목이 있습니다. | 오류에 표시된 항목을 빼거나 일반 파일로 바꿔 다시 묶어 올리세요. | `upload.error.forbidden_entry` |
| `filename_encoding` | 파일 이름 인코딩 | zip 안의 파일 이름을 UTF-8이나 CP949로 해석할 수 없습니다. | UTF-8 파일 이름을 지원하는 압축 프로그램으로 다시 묶거나, 폴더를 직접 골라 올리세요. | `upload.error.filename_encoding` |
| `unsupported_name` | 사용할 수 없는 파일 이름 | Windows에서 쓸 수 없는 이름(CON 같은 예약 이름, 금지 문자, 끝의 점·공백)이나 너무 긴 경로가 있습니다. | 표시된 파일의 이름을 바꾸거나 경로를 줄여 다시 올리세요. | `upload.error.unsupported_name` |
| `name_conflict` | 이름 충돌 | 대소문자나 유니코드 정규화만 다른 같은 이름의 파일(또는 같은 이름의 파일과 폴더)이 있습니다. | 둘 중 하나의 이름을 바꿔 다시 올리세요. | `upload.error.name_conflict` |
| `io_error` | 서버 파일 쓰기 오류 | 압축을 푸는 중 서버에서 파일을 쓸 수 없었습니다(디스크 공간, 권한 등). | 잠시 뒤 다시 올리고, 계속되면 관리자에게 문의하세요. | `upload.error.io_error` |
| `assemble_failed` | 조립 실패 | 받은 조각을 하나의 파일로 합치지 못했거나 합친 결과의 크기·해시가 맞지 않습니다. | 처음부터 다시 업로드하세요. 계속되면 관리자에게 문의하세요. | `upload.error.assemble_failed` |
| `extract_timeout` | 압축 해제 시간 초과 | 압축 해제가 제한 시간 안에 끝나지 않았습니다(파일이 아주 많을 때). | 분석에 필요 없는 파일을 빼고 다시 올리거나 관리자에게 문의하세요. | `upload.error.extract_timeout` |
| `extract_crashed` | 압축 해제 작업 중단 | 압축을 푸는 작업이 비정상 종료되었습니다(메모리 부족 등). | 다시 올리고, 계속되면 관리자에게 문의하세요. | `upload.error.extract_crashed` |

## 2. 분석 실패(분석 상세, GET /api/v1/analyses/{id}의 failure)

| 코드 | 제목 | 원인 | 사용자 조치 | 가이드 링크 키 |
| --- | --- | --- | --- | --- |
| `timeout` | 분석 시간 초과 | 분석이 제한 시간(기본 4시간)을 넘어 하위 프로세스까지 중단했습니다. | 분석 범위를 줄여(제외 패턴, 불필요한 파일 제거) 다시 업로드하거나 관리자에게 문의하세요. | `analysis.failure.timeout` |
| `output_limit` | 도구 출력 한도 초과 | 분석 도구의 결과가 출력 크기 한도를 넘었습니다(결함이 매우 많거나 생성 코드가 포함됨). | 생성된 코드·압축된 JS 등을 빼고 다시 업로드하거나 관리자에게 한도 조정을 요청하세요. | `analysis.failure.output_limit` |
| `memory_limit` | 도구 메모리 한도 초과 | 분석 도구가 메모리 한도를 넘어 중단되었습니다. | 아주 큰 파일을 빼고 다시 업로드하거나 관리자에게 문의하세요. | `analysis.failure.memory_limit` |
| `tool_crash` | 도구 비정상 종료 | 분석 도구가 비정상 종료했거나 결과 형식을 읽을 수 없었습니다. | 다시 업로드해 보고, 계속되면 관리자에게 문의하세요(도구 버전 점검이 필요할 수 있음). | `analysis.failure.tool_crash` |
| `tool_config_error` | 도구 설정 오류 | 업로드한 설정 파일(pyproject.toml, ruff.toml 등)에 도구가 받아들이지 않는 값이 있습니다. | 설정 파일의 규칙 이름·옵션을 고치거나 설정 파일을 빼고 다시 업로드하세요(빼면 서버 기본 설정으로 분석). | `analysis.failure.tool_config_error` |
| `tool_unavailable` | 사용할 수 있는 도구 없음 | 설치·체크섬·점검을 통과한 분석 도구 버전이 없습니다. | 관리자에게 문의하세요(도구 설치 상태 확인 필요). | `analysis.failure.tool_unavailable` |
| `tool_failed` | 도구 실행 실패 | 분석 도구를 실행하지 못했습니다. | 관리자에게 문의하세요. | `analysis.failure.tool_failed` |
| `insufficient_storage` | 디스크 여유 부족 | 서버 디스크 여유 공간이 부족해 분석을 시작하지 못했습니다. | 관리자에게 문의하세요. 공간이 확보되면 다시 업로드하세요. | `analysis.failure.insufficient_storage` |
| `result_save_failed` | 결과 저장 실패 | 분석은 끝났지만 결과를 저장하는 중 오류가 나 결함을 반영하지 않았습니다(기존 결함은 그대로). | 다시 업로드하고, 계속되면 관리자에게 문의하세요. | `analysis.failure.result_save_failed` |
| `worker_crashed` | 작업자 비정상 종료 | 분석을 처리하던 작업자가 비정상 종료되어 분석을 중단했습니다. | 다시 업로드하세요(실패한 분석은 다시 시도하지 않습니다). | `analysis.failure.worker_crashed` |
| `worker_stopped` | 작업자 중지 | 관리자가 작업자를 중지하면서 진행 중이던 분석을 중단했습니다. | 작업자가 다시 시작된 뒤 다시 업로드하세요. | `analysis.failure.worker_stopped` |
| `analysis_crashed` | 분석 작업 비정상 종료 | 분석 작업이 결과를 남기지 못하고 끝났습니다. | 다시 업로드하고, 계속되면 관리자에게 문의하세요. | `analysis.failure.analysis_crashed` |
| `analysis_start_failed` | 분석 시작 실패 | 분석 작업을 시작하지 못했습니다. | 관리자에게 문의하세요. | `analysis.failure.analysis_start_failed` |
| `repo_auth_failed` | 저장소 인증 실패 | 저장소가 자격 증명(토큰·비밀번호·티켓)을 받아들이지 않았거나 읽기 권한이 없습니다. | 관리자가 프로젝트의 저장소 연결에서 자격 증명과 계정 권한을 확인한 뒤 연결 테스트를 다시 하세요. | `analysis.failure.repo_auth_failed` |
| `repo_unreachable` | 저장소에 연결할 수 없음 | 저장소 서버에 연결하지 못했습니다(이름 풀이 실패, 연결 거부, 네트워크 오류). | 주소와 포트, 서버 상태, 네트워크(방화벽·프록시)를 확인한 뒤 다시 시도하세요. | `analysis.failure.repo_unreachable` |
| `repo_not_found` | 저장소·브랜치·경로 없음 | 지정한 저장소, 브랜치, 하위 경로 또는 depot 경로를 찾지 못했습니다(이름 오타 또는 읽기 권한 없음). | 관리자가 연결 설정의 주소·브랜치·경로를 확인하세요. | `analysis.failure.repo_not_found` |
| `repo_timeout` | 가져오기 시간 초과 | 소스를 가져오는 작업이 제한 시간(기본 30분)을 넘어 중단했습니다. | 저장소 크기와 네트워크를 확인하고, 필요하면 관리자가 연결의 가져오기 시간을 늘리세요. | `analysis.failure.repo_timeout` |
| `repo_limit_exceeded` | 가져온 소스가 한도를 넘음 | 가져온 소스가 크기(10GB) 또는 파일 수(1,000,000개) 한도를 넘었습니다. | 하위 경로·depot 경로로 범위를 좁히거나 제외 대상을 추가하세요. | `analysis.failure.repo_limit_exceeded` |
| `repo_host_not_allowed` | 허용되지 않은 저장소 주소 | 저장소 주소가 허용 호스트 목록에 없거나, HTTP가 허용되지 않았거나, 접속할 수 없는 주소(사설·내부 주소로 풀림, 리다이렉트)입니다. | 관리자가 시스템 관리 > 설정 관리 > 저장소 연결에서 허용 호스트를 등록·확인하세요. | `analysis.failure.repo_host_not_allowed` |
| `repo_tls_untrusted` | 서버 인증서·지문을 신뢰할 수 없음 | HTTPS 인증서 검증에 실패했거나 Perforce SSL 서버 지문이 등록한 값과 다릅니다(또는 등록하지 않았습니다). | 관리자가 인증서(사내 CA 설정) 또는 서버 지문을 확인해 연결 설정에 입력하세요. | `analysis.failure.repo_tls_untrusted` |
| `repo_tool_unavailable` | git·p4를 쓸 수 없음 | 서버의 git 또는 p4 실행 파일이 없거나 체크섬·점검을 통과하지 못했습니다. | 관리자에게 문의하세요(bin/의 git·p4와 체크섬 확인 필요). | `analysis.failure.repo_tool_unavailable` |
| `repo_empty` | 분석할 소스 없음 | 가져온 소스에 파일이 없거나 분석 대상 확장자 파일이 하나도 없습니다(기존 결함은 그대로 둡니다). | 브랜치·하위 경로·depot 경로와 프로젝트 언어를 확인하세요. | `analysis.failure.repo_empty` |
| `repo_not_configured` | 저장소 연결 없음 | 이 프로젝트에 저장소 연결이 없거나 분석을 등록한 뒤 연결이 바뀌었습니다. | 관리자가 저장소 연결을 설정한 뒤 다시 실행하세요. | `analysis.failure.repo_not_configured` |
| `repo_secret_unreadable` | 자격 증명을 읽을 수 없음 | 저장된 자격 증명을 풀 수 없습니다(암호화 키가 바뀌었거나 없습니다). | 관리자가 암호화 키를 확인하거나 자격 증명을 다시 입력하세요. | `analysis.failure.repo_secret_unreadable` |
| `repo_fetch_failed` | 가져오기 실패 | 소스를 가져오는 중 알 수 없는 오류가 났습니다. | 다시 시도하고, 계속되면 관리자에게 문의하세요(서버 로그 확인). | `analysis.failure.repo_fetch_failed` |
| `repo_unsafe_content` | 안전하지 않은 소스 내용 | 가져온 소스에 심볼릭 링크, 경로 이탈, 대소문자만 다른 같은 이름, Windows에서 쓸 수 없는 이름 같은 항목이 있어 풀지 않았습니다. | 해당 경로를 프로젝트 제외 대상에 넣거나 저장소에서 정리한 뒤 다시 시도하세요. | `analysis.failure.repo_unsafe_content` |
| `defect_limit_exceeded` | 결함 수 상한 초과 | 이번 분석 결과를 반영하면 프로젝트의 결함이 허용 상한(project_defect_max)을 넘습니다. 기존 결함과 이전 분석 결과는 그대로 둡니다. | 생성 파일·압축(minified) 파일·외부 라이브러리 폴더를 프로젝트 제외 패턴에 넣은 뒤 다시 분석하세요. 상한 조정은 관리자가 합니다. | `analysis.failure.defect_limit_exceeded` |
## 3. 업로드 시작 단계(API 오류, 공통 오류 형식)

청크 업로드 API가 요청 단계에서 거부하는 코드다(`docs/architecture.md` 20절). 업로드 화면이 `error.message`를 그대로 보여 준다.

| 코드 | 상태 | 뜻 |
| --- | --- | --- |
| `upload_too_large` | 413 | 선언한 업로드 크기가 한도(2GB)를 넘음(명세 15.2 "업로드 용량 초과"의 시작 단계. 해제 단계는 `zip_too_large`) |
| `insufficient_storage`, `disk_usage_blocked` | 507 | 디스크 여유 부족 / 사용률 차단(명세 19.3) |
| `too_many_uploads` | 429 | 사용자당 동시 업로드 2개 초과 |
| `chunk_hash_mismatch` | 422 | 청크 SHA-256 불일치(브라우저가 3회 재시도) |
| `upload_not_found` | 404 | 없는 업로드, 다른 사용자의 업로드, 보이지 않는 프로젝트의 업로드 |

## 4. 결함 수정·조회(P17·P18)

히스토리(P21): `not_succeeded`(409, 성공하지 않은 분석의 비교 요청), 결함 목록 Run 필터 `from_run`·`in_run_from`·`in_run_to`의 형식 오류·범위 뒤집힘은 `invalid_defect_filter`(400).

Run(P20R): `invalid_run`(422, `run`이 정수가 아님), `run_not_found`(404, 이 프로젝트에 없는 Run 번호 — 보이지 않는 프로젝트는 먼저 `project_not_found` 404). 그 Run에 없던 결함의 상세는 오류가 아니라 200 + `run_view.in_run=false`, 코드는 `source=none, reason=not_in_run`. (Run 비교의 `same_run`·`invalid_compare_set`은 P20S에서 기능과 함께 제거)

규칙 설명(P22): `invalid_rule_filter`(400, 규칙 검색 조건 — `error.details.field`), `rule_not_found`(404, 한 번도 적재된 적 없는 규칙. 활성 버전에만 없는 규칙은 200 + `missing`).

관리자 규칙 켜기·끄기(P22B, admin 전용): `rule_override_conflict`(409, `expected_enabled`가 지금 실효값과 다름 — 다른 관리자가 먼저 바꿈. `error.details.current`에 지금 상태), `rule_not_enableable`(422, 켤 수 없는 규칙 — `error.details.reason`: `removed`·`deprecated`·`preview`·`requires_type_info`·`requires_options`·`special_key`. 끄기는 언제나 된다), `rule_reset_confirm_mismatch`(400, 전체 되돌리기의 확인값이 도구 이름과 다름). 일괄 변경(P22C): `bulk_too_large`(400, 대상이 `rules.bulk_max`를 넘음 — `error.details.total`·`max`, 잘라서 적용하지 않음, 문구는 `error.rule_bulk_too_large`), `rule_override_conflict`(409, `error.details.reason = revision_changed`·`revision` — 미리보기 뒤 다른 변경이 있음, 아무것도 바꾸지 않음), `invalid_rule_filter`(400, `field = prefix` — 규칙 키 접두사 글자·길이). 일괄에서는 켤 수 없는 규칙을 422로 거부하지 않고 건너뛴 건수로 알린다, `invalid_input`(400, `error.details.field` = enabled·expected_enabled·reason(200자 초과)·tool·state), `rule_not_found`(404, 활성 버전에 없는 규칙 켜기·끄기, 알 수 없는 키).

화면 설정·Node 상태 갱신(P21C): `invalid_setting`(400, 알 수 없는 설정 키·빈 본문), `invalid_setting_value`(400, 정수 범위 밖 — `error.details.min`, `max`), `settings_unavailable`(503, 마이그레이션 014 전 DB), `worker_not_running`(409, Node 상태 갱신을 요청했지만 워커가 멈췄거나 응답 없음 — `error.details.node`에 마지막 확인 결과). 업로드: 암호화된 zip은 `invalid_zip`의 사유 `encrypted`(항목 이름과 "암호화된 항목" 문구), 직전 업로드와 같은 파일은 경고 `same_as_previous`(확인 대기, 오류 아님).

Run 상세 보관(P21B): `run_detail_deleted`(410, 상세가 삭제된 Run을 `run=N`으로 열려고 함 — `error.details.run`, `latest`. 결함 목록·요약·상세·코드 API와 결함 화면), 관리자 삭제 API의 `invalid_run_set`(400, 정수 Run 번호 1~100개가 아님), `run_not_found`(404, `error.details.runs`에 없는 번호), `run_latest_not_deletable`(409, 최신 Run 포함 — `error.details.runs`, 아무것도 바뀌지 않음).

`version_conflict`(409, `error.details.current`에 최신 결함), `invalid_defect_filter`·`invalid_sort`·`invalid_code_mode`·`invalid_version`·`invalid_severity`·`invalid_status`·`invalid_assignee`·`invalid_memo`·`no_change_fields`·`invalid_bulk`·`bulk_too_large`·`defect_not_in_project`(400), `defect_not_found`(404). 문구는 `error.<코드>`.

내보내기(P23): `invalid_export_format`(400, 형식이 xlsx·csv가 아님), `export_too_large`(413, 행 수 상한 초과 — `error.details.count`·`limit`, 파일을 만들지 않음), `export_empty`(409, 내보낼 항목 0건 — 204 대신 안내 문구를 주려고 오류 응답), `export_busy`(429, 동시 실행 — `error.details.scope` = user | server), `export_timeout`(503, 시간 상한으로 중단), `export_failed`(503, 메모리 상한 등으로 중단). 조건 오류는 목록과 같은 `invalid_defect_filter`·`invalid_sort`·`invalid_rule_filter`(알 수 없는 키 포함), 보이지 않는 프로젝트 `project_not_found`(404), 삭제된 Run `run_detail_deleted`(410).

앱 안 알림(P24): `notification_not_found`(404, 없는 id·다른 사용자의 알림·지금 볼 수 없는 프로젝트의 알림 — 구분하지 않음, admin도 같다), `invalid_notification_filter`(400, 목록 조건 — `error.details.field` = kind | unread), `invalid_notification_pref`(400, 알 수 없는 종류·true/false가 아닌 값·빈 본문 — `error.details.kind`), `notifications_unavailable`(503, 마이그레이션 017 전 DB. 안 읽은 개수 API는 오류 대신 `count: 0, available: false`). 보관 설정은 화면 설정 API의 `invalid_setting_value`(400)를 그대로 쓴다.

저장소 연동(P25, 분석 실패는 2절 표의 `repo_*`, 그 밖에 기존 `insufficient_storage`): `invalid_repo_input`, `invalid_repo_url_scheme`, `repo_host_not_listed`, `repo_secret_key_missing`, `repo_conflict`, `repo_test_rate_limited`, `analysis_in_progress`, `repo_not_configured`, `repo_secret_unreadable`, `repo_host_exists`, `repo_host_not_found` — `invalid_repo_input`(400, `error.details.field` = kind·url·branch·path·location·username·secret·fingerprint·fetch_timeout·host·port·note·allow_http·github_links), `invalid_repo_url_scheme`(400), `repo_host_not_listed`(400, 저장할 때 허용 목록에 없음 — `details.host`·`port`·`http`), `repo_secret_key_missing`(503), `repo_conflict`(409, `details.revision`), `repo_test_rate_limited`(429), `analysis_in_progress`(409, 지금 가져와 분석 — 대기·실행 중 분석 있음), `repo_not_configured`·`repo_secret_unreadable`(409), `repo_host_exists`(409), `repo_host_not_found`(404). 연결 테스트 결과는 200 + `{ok:false, code:<분석 실패 코드>}`(Perforce SSL은 `fingerprint`).

허용 호스트 연결 테스트(P26B): `repo_host_not_found`(404, 등록되지 않은 호스트 id), `repo_host_none`(409, 전체 테스트할 호스트가 없음), `repo_test_rate_limited`(429, 저장소 연결 테스트와 같은 분당 한도). 테스트 결과 자체는 200 + `code`(ok·warn_cert_expiring·fail_dns·fail_blocked_address·fail_connect·fail_tls_untrusted·fail_tls_expired·fail_tls_name_mismatch·fail_http_status, `docs/repo_integration.md` 10절). 저장소 심볼릭 링크는 실패가 아니라 분석 경고(`source_warnings`)다.

성공(변경 없음)(P27): `invalid_unchanged_filter`(400, 이력 `unchanged`는 show·hide), `analysis_unchanged`(409, 변경 없음으로 생략한 분석의 비교 요청 — 기준 Run을 본다), 지금 가져와 분석의 `force`가 불리언이 아니면 `invalid_repo_input`(400, `details.field` = force). 변경 없음은 실패 코드가 아니다(원격 조회 실패는 가져오기와 같은 `repo_*`로 기록).

주기 분석(P26): `invalid_schedule_input`(400, `details.field` = enabled·interval_days·run_time·body), `schedule_connection_required`(409, `details.reason` = repo_not_configured·repo_host_not_allowed·repo_secret_unreadable), `schedule_conflict`(409, `details.revision`), `schedule_unavailable`(503, 019 전 DB), 분석 목록 `invalid_trigger_filter`(400, trigger는 user·schedule). 주기 분석의 실패 코드는 2절 표의 `repo_*`·분석 실패 코드와 같다(재시도 대기 중인 분석은 `failure_code`가 비어 있고 `retry.code`에 직전 코드).

설정 관리(P27A, `/api/v1/admin/config-settings`, admin만): `invalid_config_settings`(400, 본문 형식 — `changes` 객체·`version` 문자열, DELETE의 `version` 질의), `setting_not_editable`(400, 허용 목록 밖 키 — 민감·제외 키 포함, `error.details.key`), `setting_invalid_value`(422, 형식·범위 — `error.details.key`·`min`·`max`), `setting_cross_check`(422, 키 간 규칙 — 디스크 경고 < 차단, Run 상세 보관 0 또는 3 이상 등, `error.details.keys`), `setting_env_locked`(422, 환경 변수가 정한 키 — `error.details.key`, 문구에 변수 이름), `setting_path_invalid`(422, 경로 확인 실패 — `error.details.key`·`code`(`fail_*`)·`can_create`), `settings_conflict`(409, 다른 관리자가 먼저 바꿈 — `error.details.version`, 다시 불러오기). 경로 확인(`POST …/validate`)은 실패도 200이며 결과 코드 `ok`·`warn_same_volume`·`fail_not_absolute`·`fail_forbidden_location`·`fail_not_found`·`fail_not_writable`·`fail_too_long`·`fail_unc_unreachable`(docs/settings.md).
P28B: 외부 인증 — 로그인 `account_pending`·`account_rejected`·`account_requests_closed`(403, 디렉터리 인증을 통과한 사람에게만, 세션 없음), `auth_service_unavailable`(503, 디렉터리에 닿지 않음). 사용자 관리 `approval_conflict`(409, 다른 관리자가 먼저 승인·거절·삭제했거나 대기 계정이 아님), `account_not_approved`(409, 승인 안 된 계정의 상태·역할 변경), `user_not_deletable`(409, 승인된 계정 삭제), `invalid_approval`(400), `self_auth_type_change`(400), `last_admin`(409, 활성 로컬 관리자가 0이 되는 변경 — P28B부터 인증 방식 전환·비활성화·강등 모두). LDAP 관리 `app_secret_invalid`(400), `ldap_test_rate_limited`(429, `details.n`), `repo_secret_key_missing`(409, 암호화 키 없음). X05의 `ldap_managed`(409)는 더 이상 나오지 않는다(외부 계정도 이름·이메일 수정 가능).

P28C: SSO(OIDC) 관리 `oidc_test_rate_limited`(429, `details.n`), `app_secret_migration_required`(409, 마이그레이션 027 전에 OIDC 클라이언트 비밀 저장), `app_secret_invalid`(400). 로그인 화면 SSO 오류는 API 오류가 아니라 화면 코드(`/login?sso_error=`): `E-SSO-STATE`·`E-SSO-IDP`·`E-SSO-TOKEN`·`E-SSO-CONFIG`·`E-SSO-UNAVAILABLE`·`E-SSO-RATE`·`E-SSO-DENIED` — 문구는 하나("SSO 로그인에 실패했습니다"), 사유는 감사 로그(`docs/auth_oidc.md` 5절).

P28D: SSO(SAML) 관리 `saml_test_rate_limited`(429, `details.n` — 설정 확인·메타데이터 가져오기 합산), `saml_metadata_invalid`(400 — XML이 아니거나 DOCTYPE·IDPSSODescriptor·SSO 주소·인증서 없음, 1 MiB 초과), `saml_metadata_url_invalid`(400 — https 아님), `saml_metadata_unreachable`(502, `details.reason`), `saml_key_generate_failed`(500), `app_secret_migration_required`(409, 027 전 SP 키 생성). 로그인 화면 코드는 P28C와 같은 `E-SSO-*`(사유는 감사 `saml_…`, `docs/auth_saml.md` 6절). ACS(`POST /auth/saml/acs`)는 CSRF 토큰 없이 받는 유일한 경로다.

P28A: 계정 CSV(`/api/v1/admin/account-import/*`, admin만) — `import_file_required`(400, 빈 본문), `import_encoding_invalid`(400, auto·utf-8·cp949 밖), `import_digest_required`(400, 실행에 미리보기 digest 없음), `import_file_invalid`(400, 파일 단위 오류가 있는 파일을 실행, `details.codes`), `import_file_too_large`(413, 2 MiB, `details.max_mib`), `import_preview_stale`(409, 파일이 다르거나 미리보기 뒤 판정이 바뀜 — 아무것도 반영 안 함), `import_has_errors`(422, 오류 행이 있는데 `skip_errors` 없음, `details.errors`), `import_nothing_to_do`(422). 행·파일 판정 코드는 응답 본문(`rows[].codes`, `file_errors[].code`)이며 목록은 `docs/account_csv.md` 4절. 사용자 생성 `auth_type_not_enabled`(400, `POST /api/v1/users`의 `auth_type`이 켜진 인증 방식이 아님).

P27D: 화면 설정 `invalid_preferences`(400, `PUT /api/v1/users/me/preferences` — 언어 ko·en, 테마 light·dark·system 또는 null만, 빈 요청·다른 키도). API 오류 문구(`message`)는 요청 언어(계정 > 쿠키 > Accept-Language)로 나오고 `code`는 언어와 무관하다.

P27B: 설정 저장에 `setting_busy`(409, 진행 중인 작업 때문에 지금 바꿀 수 없음 — `backup.dir`은 백업·복원 진행·대기 중, `error.details.key`·`reason` = backup_busy·restore_in_progress).

백업·복원(P27B, `/api/v1/admin/backups…`·`/api/v1/admin/restore`·`/api/v1/admin/backup-uploads…`, admin만, 문구 `backup.error.<code>`, docs/backup_restore.md):
- 400: `bundle_name_invalid`, `upload_size_invalid`, `chunk_index_invalid`, `chunk_hash_invalid`, `chunk_length_invalid`, `key_file_invalid`, `key_passphrase_weak`(`min`), `key_passphrase_mismatch`, `restore_confirm_mismatch`, `invalid_backup_request`
- 403: `reauth_failed`(관리자 비밀번호 재입력 실패 — 권한 거부가 아니라 본인 확인 실패)
- 404: `bundle_not_found`(없는 묶음·만료되거나 쓴 내려받기 표), `upload_not_found`(남의 올리기 포함)
- 409: `backup_busy`, `restore_in_progress`, `bundle_exists`, `backup_last_success_protected`, `worker_not_running`, `bundle_needs_verify`, `chunk_out_of_order`(`next`), `upload_incomplete`
- 413: `upload_too_large`(`max_mb`) · 429: `reauth_rate_limited`, `key_import_rate_limited`(분당 5회) · 507: `backup_disk_full`(`percent`), `backup_disk_low`·`restore_disk_low`(`need_mb`·`free_mb`)
- 422(그 밖, 묶음 검증·복원): `bundle_corrupt`, `bundle_entry_not_allowed`, `manifest_invalid`, `manifest_mismatch`, `bundle_format_newer`, `bundle_hash_mismatch`(`entry`), `bundle_db_corrupt`, `bundle_counts_mismatch`, `restore_schema_newer`(`bundle`·`app`), `key_missing`, `key_import_failed`(틀린 암호문구와 변조를 구분하지 않음), `key_test_failed`, `backup_dir_invalid`(`code`), `chunk_hash_mismatch`
- 상태 파일·알림·목록의 코드(API 응답이 아님): `backup_io_failed`, `backup_verify_failed`(`code`), `backup_cancelled`, `backup_failed`, `rehearsal_failed`, `restore_io_failed`, `restore_config_invalid`, `restore_failed`, `restore_busy`, `restore_start_failed`, `restore_interrupted`
- 유지보수 중(복원 진행) 모든 API(상태 API 제외): 503 `maintenance`.
- 업그레이드 유지보수 모드(P32B, `[maintenance] enabled`): admin이 아닌 사용자의 쓰기 요청(GET·HEAD·OPTIONS 밖, 로그인·로그아웃·자기 비밀번호 변경 제외)은 503 `maintenance`(`error.maintenance_upgrade` + 관리자 안내 문구, `Retry-After`). 켜기 직전 백업이 안 되면 설정 저장이 409 `maintenance_backup_failed`(`reason` = 백업 오류 코드 또는 `worker_not_running`·`maintenance_backup_timeout`, 아무것도 바꾸지 않음).

프로젝트 관리(P10, `POST /api/v1/projects`·`PATCH`·`DELETE /api/v1/projects/{id}`, admin만): `project_name_taken`(409, 같은 이름의 프로젝트가 이미 있음 — 대소문자 구분 없음, 만들기·이름 바꾸기 — 조치: 다른 이름을 쓴다), `project_busy`(409, 대기·실행 중인 분석이나 처리 중인 업로드가 있어 삭제 거부 — 조치: 끝나거나 취소한 뒤 다시 삭제). 문구 `error.project_name_taken`·`error.project_busy`.

P29B: FAQ(`/api/v1/faq…` 로그인한 모든 사용자, `/api/v1/admin/faq…` admin만) — `invalid_faq`(400, `details.field` — 비었거나 형식이 틀림·`version` 없음·잘못된 UTF-8), `invalid_faq_length`(400, `details.field`·`max` — 분류 40·질문 200·답변 10,000·검색어 100자), `faq_limit`(400, 항목 500개 상한, `details.max`), `faq_not_found`(404, 없는 항목 — 일반 사용자에게 비공개 항목도 같음), `faq_conflict`(409, 다른 관리자가 먼저 수정, `details.current` = 최신 항목), `faq_order_conflict`(409, 순서를 바꾸는 동안 분류의 항목이 바뀜, `details.current` = 실제 id 목록), `faq_unavailable`(503, 마이그레이션 029 전).

P30: 감사 로그(`/api/v1/admin/audit-logs…`, admin만) — `invalid_audit_filter`(400, `details.field` — days(1·7·30·90만)·from·to(UTC ISO 8601, from ≤ to)·actor·action(AuditActions의 이름 또는 접두사)·target·q(100자)·page·size(1~200)·after·limit(1~20,000)), `audit_not_found`(404). 내보내기는 P23과 같은 `export_empty`(409)·`export_too_large`(413)·`export_busy`(429). 헬스체크 `GET /health` — 허용 IP 밖은 등록되지 않은 경로와 같은 `not_found`(404, 존재를 알리지 않음), `health_rate_limited`(429, `params.n`). 상태 판정은 오류 코드가 아니라 본문 `status`(ok·warn → 200, fail → 503).
