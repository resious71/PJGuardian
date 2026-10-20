---
key: upload
title: 업로드
order: 3
summary: 폴더나 ZIP을 올려 분석을 시작하는 방법, 이어 올리기, 사전 검사 경고, 한도와 설정 파일 규칙
keywords: 업로드, ZIP, 폴더, 이어 올리기, 일시 중지, 청크, 사전 검사, 경고, 제외 대상, 최상위 폴더, ruff 설정, ESLint 설정, pyproject.toml
related: upload-errors, analysis-status, projects, analysis-failures
audience: user
---

## 업로드로 하는 일

분석할 코드를 폴더나 ZIP 파일로 올리면 서버가 압축을 풀고 사전 검사를 한 뒤 분석 대기열에 넣습니다. 업로드 화면은 프로젝트 상세의 {{icon:action.upload}} {{ui:upload.button}} 단추나 [업로드 화면](/upload)에서 엽니다.

처리 단계는 화면에 순서대로 표시됩니다: {{ui:upload.step.upload}} → {{ui:upload.step.assemble}} → {{ui:upload.step.extract}} → {{ui:upload.step.queue}}.

![업로드 화면. 프로젝트를 고르고 ZIP 파일이나 폴더를 선택한 뒤 업로드 시작 단추를 누릅니다.](guide-img:upload-screen)

## 올리는 방법

1. {{ui:upload.project}}에서 대상 프로젝트를 고릅니다. 볼 수 있는 프로젝트만 나옵니다.
2. 올릴 대상을 고릅니다. {{ui:upload.pick_file}}은 이미 만든 ZIP 파일을, {{ui:upload.pick_folder}}는 폴더를 고릅니다. 폴더는 브라우저가 ZIP으로 묶어 올립니다(압축하지 않고 묶기만 합니다).
3. 고른 대상의 파일 수와 예상 크기를 확인합니다. "선택한 파일 보기"로 목록을 볼 수 있습니다.
4. {{icon:action.upload}} {{ui:upload.start}}을 누릅니다. 고르기만 해서는 전송되지 않습니다.
5. 대기열에 들어가면 "{{ui:upload.go_analysis}}" 링크로 분석 진행 상황을 봅니다([분석 상태](guide:analysis-status)).

잘못 골랐으면 {{ui:upload.clear_selection}}을 누르고 다시 고르세요.

일부 브라우저는 폴더를 고른 뒤 "업로드" 확인 창을 띄웁니다. 이 창은 폴더를 읽어도 되는지 묻는 것이고, 실제 전송은 {{ui:upload.start}}을 눌러야 시작됩니다. 브라우저 보안 정책 때문에 PC의 전체 경로는 보이지 않고 고른 폴더 이름부터 보입니다.

## 한도

- 업로드할 수 있는 크기: {{limit:upload.max_zip_size}}
- 압축을 푼 뒤 전체 크기: {{limit:extract.max_extracted_size}}
- 압축을 푼 뒤 파일 수: {{limit:extract.max_files}}
- 한 사람이 동시에 진행할 수 있는 업로드 수: {{limit:upload.max_concurrent_per_user}}

크기가 한도를 넘으면 전송 전에 막습니다. 폴더는 묶은 뒤의 예상 크기로 판단합니다. 서버 디스크 여유가 부족하면 업로드를 시작하지 못할 수 있습니다. 이때는 관리자에게 문의하세요.

## 기본 제외 대상

의존성 폴더, 빌드 결과, 버전 관리 폴더 같은 서버 기본 제외 대상은 압축을 풀 때 늘 빠집니다. 목록은 업로드 화면의 안내와 프로젝트 상세의 {{ui:projects.excludes_title}}에서 볼 수 있습니다.

- 폴더를 올릴 때 "{{ui:upload.skip_excluded}}"를 켜 두면(기본 켜짐) 묶기 전에 미리 빼서 업로드 크기가 줄어듭니다. 결과는 같습니다.
- 경로의 한 부분이 이름과 **정확히** 같을 때만 빠집니다. 예를 들어 `builder/`나 `src/dist.py`는 빠지지 않습니다.
- 관리자가 프로젝트에 더한 제외 패턴은 서버가 압축을 풀 때 적용합니다. 업로드 화면의 목록에는 표시되지 않습니다.

## 이어 올리기와 일시 중지

파일은 조각(청크, 하나에 {{limit:upload.chunk_size}})으로 나눠 보내고, 서버가 조각마다 무결성을 확인합니다. 조각 전송이 실패하면 자동으로 몇 번 다시 보냅니다.

- {{ui:upload.pause}}를 누르면 전송을 멈춥니다. 다시 {{ui:upload.start}}을 누르면 이어서 보냅니다.
- 네트워크가 끊기거나 화면을 닫았으면, **같은 브라우저에서** 같은 프로젝트와 같은 대상을 다시 고르고 {{ui:upload.start}}을 누르세요. 이미 받은 조각은 건너뜁니다.
- 폴더도 내용이 바뀌지 않았으면 같은 결과로 묶이므로 이어 올릴 수 있습니다.
- 받는 중인 업로드가 {{limit:upload.incomplete_chunk_retention}} 넘게 멈춰 있으면 서버가 조각을 지울 수 있습니다. 그때는 처음부터 올립니다.
- {{icon:action.cancel}} {{ui:upload.abort}}를 누르면 서버에 받은 조각을 모두 지웁니다. 전송을 마친 업로드는 취소할 수 없고 상태만 볼 수 있습니다.

## 사전 검사와 경고 확인

압축을 푼 뒤 서버가 내용을 검사합니다. 아래와 같은 경우에는 분석 전에 확인을 받습니다("확인이 필요합니다").

- 분석할 대상 파일이 없음: 이대로 분석하면 이 프로젝트의 기존 결함이 모두 해결(resolved)로 바뀔 수 있습니다.
- 최상위 폴더가 이전 업로드와 다름: 파일 경로가 달라져 기존 결함이 모두 해결로, 같은 결함이 신규로 바뀔 수 있습니다.
- 직전 업로드와 같은 파일: 같은 코드를 다시 분석하려는 것이 아니면 올바른 ZIP인지 확인하세요.

경고 창에서 고를 수 있는 것:

- {{ui:upload.confirm_continue}}: 경고를 확인하고 분석 대기열에 넣습니다.
- {{ui:upload.decide_later}}: 창만 닫습니다. 같은 브라우저에서 업로드 화면을 다시 열면 확인을 기다리는 업로드가 다시 보입니다.
- {{ui:upload.abort}}: 압축을 푼 결과와 올린 파일을 모두 지우고 분석하지 않습니다.

확인을 기다리는 업로드는 {{limit:upload.confirmation_retention}}이 지나면 자동으로 취소될 수 있습니다. 확인은 업로드한 본인만 할 수 있습니다.

화면의 "업로드 내용 요약"에서 압축 해제한 파일 수, 분석 대상 파일 수, 제외한 항목, 파일 해시(SHA-256)와 직전 업로드와의 비교를 볼 수 있습니다.

## 최상위 폴더 처리

ZIP 전체가 폴더 하나로 감싸져 있으면 그 폴더를 벗겨 낼지를 **프로젝트의 첫 업로드**에서 정하고 이후에도 같은 규칙을 씁니다. 결함은 파일 경로로 추적하므로, 매번 같은 구조(같은 방식으로 만든 ZIP 또는 같은 폴더)로 올리세요. 구조가 달라지면 위의 최상위 폴더 경고가 나옵니다.

## 도구 설정 파일 규칙

**Python(ruff)**

- 분석 루트의 설정만 씁니다. 우선순위는 `.ruff.toml`, `ruff.toml`, `pyproject.toml`의 ruff 설정 순서입니다.
- 하위 폴더의 ruff 설정은 쓰지 않습니다.
- 설정의 `extend`가 프로젝트 밖을 가리키면 그 설정을 쓰지 않고 서버 기본 설정으로 분석합니다.
- 업로드한 `.gitignore`는 무시합니다.

**JavaScript/TypeScript(ESLint)**

- 업로드한 ESLint 설정은 **모두 무시**하고 서버 기본 설정으로 분석합니다.
- JS로 된 ESLint 설정 파일(`eslint.config.*`, `.eslintrc.*`)은 분석 대상에서도 뺍니다.
- 분석 루트의 `.eslintignore`만 서버 제외 패턴으로 바꿔 적용합니다. `.gitignore`는 무시합니다.

설정을 무시했으면 분석 상세 화면에 "설정 무시됨" 안내가 나옵니다. 설정 파일 때문에 분석이 실패하면 [분석 실패 해결](guide:analysis-failures)을 보세요.

## 자주 하는 실수

- 프로젝트를 잘못 고름: 업로드한 코드로 그 프로젝트의 결함이 갱신됩니다. 시작 전에 프로젝트 이름을 확인하세요.
- 빈 ZIP이나 다른 언어의 코드를 올림: "대상 파일 없음" 경고를 그냥 계속하면 기존 결함이 모두 해결로 바뀝니다.
- ZIP 안에 다른 ZIP을 넣음: 제외 대상 밖의 `.zip` 파일은 업로드 오류가 됩니다([업로드 오류 해결](guide:upload-errors)).

## 관련 항목

- [업로드 오류 해결](guide:upload-errors)
- [분석 상태](guide:analysis-status)
- [프로젝트 보기](guide:projects)
