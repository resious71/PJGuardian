-- 010: 대시보드 파일별 상위 10(P20, 명세 9, 19.10)
-- "열린 결함이 많은 파일" 집계(프로젝트·파일별 건수, 심각도 분포)를 결함 표를 읽지 않고 인덱스만으로 끝내기 위한 커버링 인덱스.
-- 측정(docs/results/P20.md): 결함 50만 건·프로젝트 200개에서 전체 범위 파일별 상위 10이 2.4초(defects_project_file_line + 표 조회)
--   → 67ms. 인덱스 크기 +약 15%(258MB → 296MB), 결함 반영(P16) 쓰기 시간 변화는 보고서 표 참고.
-- 기존 defects_project_file_line(project_id, file_path, line_no)은 결함 목록의 파일 정렬·접두사 필터가 계속 쓴다.
CREATE INDEX defects_project_file_status ON defects (project_id, file_path, status, severity);
