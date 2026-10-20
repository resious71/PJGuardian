# 화면 영어 용어집 (P27D)

화면 문구를 영어로 옮길 때 같은 개념은 아래 영어 하나로만 쓴다. 새 문구를 더하면 `ko`·`en` 리소스를 함께 고치고(`src/Lang/ko.php`·`en.php`, `public/assets/js/i18n/ko.js`·`en.js`) 이 표의 용어를 따른다.
**번역하지 않는 것**: 도구(ruff·ESLint)가 낸 메시지와 규칙 설명, 사용자가 입력한 값(프로젝트·그룹 이름, 결함 메모·사유), DB에 문장으로 저장된 값(아래 "번역하지 않는 구간").

## 1. 문체

- 메뉴·버튼·열 머리글: 짧은 명사·동사구, 제목식 대문자 쓰지 않음(Sentence case). 예: "Back up now", "Export to CSV", "Last detected".
- 안내·오류: "원인 + 조치" 구조 유지, 완전한 문장(마침표). 예: "The worker is not running. Start the worker or restore from the command line."
- 존댓말 어미("~하세요")는 명령문으로("Check …", "Try again …"). "~합니다"는 평서문.
- 괄호 보충 설명은 그대로 괄호로. 한국어 가운뎃점(·)은 쉼표 또는 " · "(구분 표시일 때)로.
- 숫자·단위: `{n}건`, `{n}개` 같은 수 결합은 복수형 표기 `{n} {n:defect|defects}`(아래 3절). 시간 "N분 전" → "{n} {n:minute|minutes} ago".

## 2. 핵심 용어

| 한국어 | English | 비고 |
| --- | --- | --- |
| 결함 | defect | |
| 분석 | analysis (복수 analyses) | |
| Run | Run | 고유 명칭, 그대로 |
| 성공(변경 없음) / 변경 없음 | Succeeded (unchanged) / unchanged | |
| 해결됨 | Resolved | 결함 상태 |
| 열림 | Open | |
| 진행 중 | In progress | |
| 보류 | Postponed | |
| 의도됨 | Intentional | |
| 수정 안 함 | Won't fix | |
| 재오픈 | reopened | |
| 신규 | new | |
| 유지 | kept | 분석 비교 |
| 미감지 | not detected | |
| 범위 밖 | out of scope | |
| 파일 삭제(사유) | file deleted | |
| 심각도 | severity | 치명적 Critical · 높음 High · 보통 Medium · 낮음 Low · 미지정 Not set |
| 담당자 | assignee | |
| 최근 감지 | last detected | |
| 규칙 | rule | |
| 규칙 기본 심각도 | rule default severity | |
| 제외 패턴 / 제외 대상 | exclude pattern / excluded | |
| 대상 확장자 | target extensions | |
| 문법 오류 | syntax error | |
| 코드 뷰어 / 발췌 | code viewer / excerpt | |
| 스냅샷 | snapshot | |
| 업로드 / 조각 | upload / chunk | |
| 묶기(폴더) | package (a folder) | |
| 가져오기(저장소) | fetch | "지금 가져와 분석" = "Fetch and analyze now" |
| 저장소 / 연결 | repository / connection | |
| 허용 호스트 | allowed host | |
| 연결 테스트 | connection test | |
| 자격 증명 | credentials | |
| 주기 분석 | scheduled analysis | 주기 = schedule, 주기 실행 = Scheduled |
| 워커 | worker | |
| 대기 / 실행 중 / 성공 / 실패 / 취소됨 | Queued / Running / Succeeded / Failed / Cancelled | 분석 상태 |
| 대시보드 / 히스토리 / 추이 | dashboard / history / trend | |
| 알림 | notification | |
| 프로젝트 / 그룹 / 그룹 관리자 | project / group / group manager | |
| 관리자(admin 역할) / 일반 사용자 | admin / regular user | |
| 계정 / 임시 비밀번호 / 잠금 | account / temporary password / locked | |
| 감사 로그 | audit log | |
| 시스템 관리 | System admin | 관리자 메뉴(X10, 예전 이름 "도구·워커 관리 / Tools & workers", X12에서 영어를 줄임). 탭: 분석 도구·워커·점검·Run 관리·규칙 설정·설정 관리·주기 분석·백업·복원 |
| 설정 관리 | Settings | 시스템 관리의 탭(규칙 설정 뒤, X10 전에는 별도 관리자 메뉴). 안내 문장 경로는 "시스템 관리 > 설정 관리 > …" |
| 규칙 설명(메뉴·화면 이름) | Rules | X13에서 영어만 줄임(예전 "Rule descriptions"). 도구가 낸 규칙 설명 글 자체(번역하지 않음)는 "rule descriptions" 그대로 |
| 저장소 연결 | Repository connections | 설정 관리의 카테고리 탭(X12) — 허용 호스트 구역 + 연결 테스트 설정 |
| 허용 호스트 | allowed hosts | 저장소 접속을 허용한 호스트 목록(예전 탭 이름 "저장소 허용 호스트") |
| 알림 보관 기간 | notification retention periods | 설정 관리 "알림" 탭의 구역(X12, 예전 탭 "알림 보관") |
| 시스템 모니터링 | Monitoring | 관리자 메뉴(P30에서 화면, X10 전 이름 "시스템 현황 / System status", X12에서 영어를 줄임). 상태 아이콘 묶음 "시스템 상태 / System status"와는 다른 말 |
| 화면 설정(출처) | screen setting | 설정 관리에서 바꾼 값 |
| 기본값으로 | Reset to default | |
| 고정 / 차단 / 검증 / 체크섬 / 스모크 테스트 | pin / block / verify / checksum / smoke test | 도구 버전 |
| 백업 / 복원 / 묶음 | backup / restore / bundle | |
| 지금 백업 / 자동 백업 | Back up now / automatic backup | |
| 복원 리허설 | restore rehearsal | |
| 키 내보내기 / 암호문구 / 키 지문 | Export key / passphrase / key fingerprint | |
| 유지보수 모드 | maintenance mode | |
| 내보내기 | export | |
| 점검 | checks | |
| 보관 / 보관 기간 | retention / retention period | |

## 3. 자리표시자와 복수형

- `{name}`: 값으로 바꾼다. **두 언어의 자리표시자 이름 집합이 같아야 한다**(시험이 검사).
- `{name:단수|복수}`: 값 `name`이 1이면 단수, 아니면 복수 낱말(값의 천 단위 쉼표는 무시). 숫자 자체는 `{name}`으로 따로 쓴다.
  예: ko `결함 {n}건` → en `{n} {n:defect|defects}`. 한국어 문구는 이 형식을 쓰지 않아도 된다(이름 집합 비교에서 `{n:…}`은 `n`으로 센다).
- PHP `Lang::get`, JS `t()`가 같은 규칙으로 처리한다.

## 4. 번역하지 않는 구간(알려진 한계)

| 구간 | 이유 |
| --- | --- |
| 결함 메시지·규칙 설명(ruff·ESLint 원문, 규칙 설명 데이터) | 사용자 결정 1 |
| 프로젝트·그룹·사용자 이름, 결함 메모·변경 사유, 업로드 파일 이름, 저장소 주소 | 사용자 입력 |
| 감사 로그의 사유(`details.reason`) | 사용자 입력 |
| 도구 레지스트리의 스모크 테스트 결과 문장(`smoke_message`) | 서버가 저장한 문장(도구 출력 포함) |
| 서버 로그·명령줄 도구 출력(`tools/*.php`) | 운영자용(화면 문구 아님) |
