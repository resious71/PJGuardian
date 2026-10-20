# 청크 업로드를 위한 PHP·Apache 설정

PJGuardian은 최대 2GB zip을 **청크(기본 8MB)** 단위로 받는다 (명세 5.4). 각 요청은 청크 하나만 실어 보내므로 PHP와 Apache의 한도는 **전체 파일 크기가 아니라 청크 크기**를 기준으로 정한다. 측정값은 P06 프로토타입(Windows, XAMPP Apache 2.4.58 + PHP 8.2.12)과 P12 제품 구현(`src/Services/Upload/`)의 결과다(P12 결과는 `docs/results/P12.md`).

점검: `php tools/check_env.php` (php.ini 항목, intl 확장, 디스크 여유를 점검한다. 아래 "점검" 열이 이 도구가 보는 기준이다)

## 1. 청크를 받는 방식과 영향

- 청크 본문은 multipart가 아니라 **요청 본문 그대로**(`PUT /api/v1/uploads/{id}/chunks/{n}`, `Content-Type: application/octet-stream`, 헤더 `X-Chunk-Sha256`) 받는다.
- 그래서 PHP 임시 업로드 파일(`upload_tmp_dir`)과 `upload_max_filesize`를 거치지 않는다. 본문 크기는 `post_max_size`와 Apache `LimitRequestBody`의 영향만 받는다.
- 서버는 본문을 1MB씩 읽어 SHA-256을 계산하며 디스크에 쓰고, 조립도 1MB씩 이어 붙인다. 프로토타입에서 측정한 PHP 최대 메모리는 8MB 청크 수신과 2GB 조립 모두 **4MB**였다. Apache 프로세스 전체 작업 집합은 2GB 업로드 내내 약 60MB였다.
- 조립은 요청 안에서 하지 않는다. `complete`는 "조립 대기"(202)로 응답하고, 조립은 `php tools/process_upload.php --once`(P14부터 워커)가 한다. 그래서 `max_execution_time`은 청크 하나를 받는 시간만 고려하면 된다.

## 2. php.ini

| 항목 | 권장값 | 이유 | 이 PC의 현재 값(2026-10-03) |
| --- | --- | --- | --- |
| `post_max_size` | **청크 크기보다 크게** (예: 16M 이상). 점검: 청크보다 작으면 WARN | 청크 하나가 요청 본문 하나다 | 40M (충분) |
| `upload_max_filesize` | 청크 크기 이상 (예: 16M) | 원시 본문 방식에서는 쓰이지 않지만 multipart로 바꿀 경우를 대비 | 40M |
| `max_execution_time` | **120초 이상**. 점검: 120 미만이면 WARN | Windows에서는 실제 경과 시간 기준이다. 느린 회선에서 8MB 청크를 받는 시간(0.55Mbps 미만이면 120초 초과). 조립은 요청 밖(CLI)에서 하므로 상관없다 | 120 |
| `max_input_time` | 60 이상(기본값 유지) | 원시 본문 수신에 적용되는지는 미확인 | 60 |
| `memory_limit` | 128M 이상 권장. 점검: 청크 크기의 4배(32M) 미만이면 WARN | 청크·조립은 스트리밍이라 적게 쓴다. 다른 기능(분석 결과 파싱 등)은 더 필요할 수 있다 | 512M |
| `upload_tmp_dir` | 쓰기 가능한 디렉토리 | 원시 본문 방식에서는 쓰지 않지만 점검 대상으로 둔다 | `C:\xampp\tmp` |
| `extension=intl` | **켜기**. 점검: 없으면 FAIL | 경로·코드의 NFC 정규화(명세 19.10, P04·P05) | 켜져 있음 |

`post_max_size`를 청크보다 작게 두면 PHP가 본문을 버리고 빈 요청으로 처리한다. 서버는 이를 "청크 크기 오류"로 거부한다.

## 3. Apache

| 항목 | 권장값 | 이유 | 이 PC |
| --- | --- | --- | --- |
| `LimitRequestBody` | 청크 크기보다 크게(설정하지 않으면 2.4.53 이상 기본 1GiB) | 청크 요청 본문 한도 | 설정 없음(1GiB) |
| `Timeout` | 60~300초 | 패킷 사이 대기 시간. 청크 하나 전송 중 멈춤 허용 범위 | 설정 없음(기본 60초). `httpd-default.conf`가 Include되어 있지 않음 |
| `RequestReadTimeout`(mod_reqtimeout) | 사용 시 `body=20,MinRate=500` 이상 | 느린 클라이언트 차단과 균형 | 모듈 미사용 |
| `ThreadsPerChild`(mpm_winnt) | 기본 150 | 동시 청크 요청 수 | 150 |
| `KeepAlive` | On | 청크 요청이 연속되므로 연결 재사용 | 기본 On |

HTTPS, VirtualHost, 웹 루트 설정은 `docs/xampp_vhost.md`를 따른다.

## 4. 임시 폴더와 디스크

- 업로드 파일은 모두 **`data/uploads/<upload_id>/`**(웹 루트 밖) 아래에 둔다: 청크 `<n>.part`, 청크 해시 `<n>.sha256`, 잠금 `.lock`, 조립본 `upload.zip`. 프로젝트를 지우면 이 폴더가 함께 지워진다(docs/architecture.md 17.2).
- **디스크 예약**(업로드 시작 `init`, `DiskGuard`): 크기 S, 해제 상한 X(`extract.max_extracted_size`, 10GB)일 때 **S + max(S, X)**. 조립 중(청크 S + 조립본 S = 2S)과 해제 중(조립본 S + 해제 결과 ≤ X)은 시점이 겹치지 않으므로 최대 사용량은 둘 중 큰 쪽이다(프로토타입의 2S + X는 두 최대치를 더해 과했다). 2GB 업로드는 12GB를 예약한다.
- 여유 판정은 **실제 여유 공간 − 진행 중 업로드의 예약 합계**로 한다(여러 사람이 동시에 시작해도 넘치지 않게). 부족하면 507 `insufficient_storage`(필요량·여유량을 문구에). 디스크 사용률이 `disk.block_percent`(90%) 이상이면 507 `disk_usage_blocked`, `disk.warn_percent`(80%) 이상이면 WARN 로그(명세 19.3).
- `check_env.php`의 디스크 여유 점검(동시 분석 수 × (zip 한도 + 해제 상한) = 24GB)은 같은 계산을 동시 작업 수만큼 더한 값이다.
- 완료되지 않은 조각은 `php tools/cleanup.php`(마지막 활동 후 `upload.incomplete_chunk_retention`, 기본 24시간)가 지운다. 운영에서는 작업 스케줄러·cron으로 매일 실행한다(P14에서 스케줄러에 통합).
- 사용자당 동시에 받는 중인 업로드는 `upload.max_concurrent_per_user`(2개)까지다(429 `too_many_uploads`).

## 5. 측정 요약 (Windows, 같은 PC 안 HTTPS 루프백)

| 크기 | 전송 | 조립 | 합계 | PHP 최대 메모리 |
| --- | --- | --- | --- | --- |
| 100MB | 1.1초 | 0.7초 | 1.8초 | 4MB |
| 500MB | 5.7초 | 4.9초 | 10.6초 | 4MB |
| 2GB | 48.0초 | 22.2초 | 70.2초 | 4MB |

실제 네트워크에서는 전송 시간이 회선 속도에 따라 달라진다(예: 100Mbps에서 2GB 약 3분). 조립 시간은 서버 디스크 속도에 달려 있다.

P12 제품 구현의 측정(브라우저 화면 업로드, 2GB 1회)은 `docs/results/P12.md`에 적는다.
