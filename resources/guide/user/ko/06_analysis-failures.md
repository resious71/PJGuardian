---
key: analysis-failures
title: 분석 실패 해결
order: 6
summary: 분석이 실패했을 때 원인별로 직접 할 수 있는 일과 관리자에게 문의할 때
keywords: 분석 실패, timeout, output_limit, memory_limit, tool_crash, tool_config_error, tool_unavailable, tool_failed, insufficient_storage, result_save_failed, worker_crashed, worker_stopped, analysis_crashed, analysis_start_failed, repo, 저장소, defect_limit_exceeded, 결함 수 상한
related: analysis-status, upload, upload-errors, errors-help
audience: user
---

## 실패 정보가 보이는 곳

분석이 실패하면 분석 상세 화면의 "{{ui:analyses.failure_title}}"에 원인, 할 수 있는 조치, {{ui:analyses.failure_code}}가 나옵니다. 화면의 {{ui:analyses.reupload}} 단추로 같은 프로젝트의 업로드 화면을 바로 열 수 있습니다.

모든 실패 코드는 [오류 코드](/guide/errors) 탭에 정리되어 있습니다. 여기서는 원인별로 묶어 설명합니다.

알아 둘 점:

- 실패한 분석은 결함을 하나도 바꾸지 않습니다. 비교 대상(직전 성공 분석)도 그대로입니다.
- 실패한 분석을 다시 실행하는 기능은 없습니다. 원인을 고친 뒤 다시 업로드하세요.
- 코드의 문법 오류는 실패 원인이 아닙니다. 문법 오류는 `syntax-error` 결함으로 기록되고 분석은 성공합니다.

## 분석 범위가 너무 큼

- `timeout`(분석 시간 초과): 분석이 제한 시간({{limit:analysis.timeout}})을 넘었습니다.
- `output_limit`(도구 출력 한도 초과): 도구 결과가 출력 크기 한도를 넘었습니다. 결함이 매우 많거나 생성된 코드가 들어 있을 때 생깁니다.
- `memory_limit`(도구 메모리 한도 초과): 분석 도구가 메모리 한도를 넘었습니다.
- `defect_limit_exceeded`(결함 수 상한 초과): 이번 결과를 반영하면 프로젝트의 결함 수가 상한({{limit:limits.project_defect_max}})을 넘습니다. 기존 결함과 이전 분석 결과는 그대로 둡니다.

직접 할 수 있는 일:

1. 생성된 코드, 압축(minified)된 JS, 외부 라이브러리 폴더, 아주 큰 파일을 빼고 다시 업로드하세요.
2. 계속 빼야 하는 폴더는 관리자에게 프로젝트 제외 패턴으로 넣어 달라고 요청하세요.
3. 그래도 실패하면 관리자에게 한도 조정을 문의하세요.

## 업로드한 설정 파일 문제

- `tool_config_error`(도구 설정 오류): 업로드한 설정 파일(`pyproject.toml`, `ruff.toml` 등)에 도구가 받아들이지 않는 값이 있습니다.

직접 할 수 있는 일:

1. 설정 파일의 규칙 이름과 옵션을 고친 뒤 다시 업로드하세요.
2. 또는 설정 파일을 빼고 올리세요. 그러면 서버 기본 설정으로 분석합니다.

어떤 설정 파일을 쓰는지는 [업로드](guide:upload)의 "도구 설정 파일 규칙"을 보세요.

## 분석 도구 문제

- `tool_crash`(도구 비정상 종료): 분석 도구가 비정상 종료했거나 결과를 읽을 수 없었습니다.
- `tool_unavailable`(사용할 수 있는 도구 없음): 사용할 수 있는 분석 도구 버전이 없습니다.
- `tool_failed`(도구 실행 실패): 분석 도구를 실행하지 못했습니다.

`tool_crash`는 한 번 다시 업로드해 보세요. 같은 실패가 계속되거나 `tool_unavailable`·`tool_failed`가 나오면 관리자에게 문의하세요. 서버의 도구 상태를 확인해야 합니다.

## 서버 저장 공간과 결과 저장

- `insufficient_storage`(디스크 여유 부족): 서버 디스크 여유가 부족해 분석을 시작하지 못했습니다.
- `result_save_failed`(결과 저장 실패): 분석은 끝났지만 결과를 저장하지 못해 결함에 반영하지 않았습니다.

`insufficient_storage`는 관리자에게 문의하고, 공간이 확보된 뒤 다시 업로드하세요. `result_save_failed`는 다시 업로드해 보고, 계속되면 관리자에게 문의하세요.

## 작업이 중간에 멈춤

- `worker_crashed`(작업자 비정상 종료): 분석을 처리하던 작업자(워커)가 비정상 종료되었습니다.
- `worker_stopped`(작업자 중지): 관리자가 작업자를 멈추면서 진행 중이던 분석이 중단되었습니다.
- `analysis_crashed`(분석 작업 비정상 종료): 분석 작업이 결과를 남기지 못하고 끝났습니다.
- `analysis_start_failed`(분석 시작 실패): 분석 작업을 시작하지 못했습니다.

대부분 다시 업로드하면 됩니다. `worker_stopped`는 작업자가 다시 시작된 뒤 올리세요. `analysis_start_failed`나 같은 실패가 반복되면 관리자에게 문의하세요.

## 저장소에서 가져오기 실패(repo_ 로 시작하는 코드)

저장소가 연결된 프로젝트에서 코드를 가져오다 실패하면 `repo_`로 시작하는 코드가 나옵니다. 저장소 연결과 주기 분석은 관리자가 설정하므로 대부분 관리자의 확인이 필요합니다.

- 연결·인증: `repo_auth_failed`(저장소 인증 실패), `repo_unreachable`(저장소에 연결할 수 없음), `repo_tls_untrusted`(서버 인증서·지문을 신뢰할 수 없음), `repo_host_not_allowed`(허용되지 않은 저장소 주소)
- 설정: `repo_not_found`(저장소·브랜치·경로 없음), `repo_not_configured`(저장소 연결 없음), `repo_secret_unreadable`(자격 증명을 읽을 수 없음), `repo_tool_unavailable`(git·p4를 쓸 수 없음)
- 가져온 내용: `repo_empty`(분석할 소스 없음), `repo_limit_exceeded`(가져온 소스가 한도를 넘음), `repo_unsafe_content`(안전하지 않은 소스 내용)
- 그 밖: `repo_timeout`(가져오기 시간 초과), `repo_fetch_failed`(가져오기 실패)

직접 할 수 있는 일:

1. 화면의 오류 코드와 분석 번호, 시각을 관리자에게 알려 주세요.
2. `repo_empty`이면 브랜치나 하위 경로에 이 프로젝트 언어의 파일이 있는지 확인해 알려 주세요. 기존 결함은 그대로입니다.
3. `repo_unreachable`, `repo_timeout`처럼 일시적인 문제는 다음 주기 실행에서 저절로 풀릴 수 있습니다.

## 관리자에게 문의할 때

다음 정보를 함께 알려 주면 빨리 확인할 수 있습니다.

- 프로젝트 이름과 분석 번호(분석 #id)
- 화면에 보인 오류 코드
- 업로드한 시각과 대략의 파일 크기

## 관련 항목

- [분석 상태](guide:analysis-status)
- [업로드](guide:upload)
- [업로드 오류 해결](guide:upload-errors)
- [오류 메시지 해결](guide:errors-help)
