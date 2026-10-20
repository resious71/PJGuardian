-- 008: 결함 필터 요약용 커버링 인덱스(P17, 명세 6.4·19.6)
-- 필터 요약(GET /api/v1/projects/{id}/defects/summary)은 (심각도, 상태, 규칙)과 (심각도, 상태, 담당자)별 건수를 한 번씩 훑어 센다.
-- 이 인덱스가 없으면 필터가 있을 때 결함 5만 건 프로젝트에서 140~250ms(행을 하나씩 읽음), 있으면 수 ms(인덱스만 읽음).
-- 목록의 건수 쿼리(심각도·상태·규칙·담당자 필터 조합)도 이 인덱스만으로 끝난다. 크기: 결함 10만 건에 약 3.8MB(DB의 약 7%).
CREATE INDEX defects_project_facets ON defects (project_id, severity, status, rule_key, assignee_id);
