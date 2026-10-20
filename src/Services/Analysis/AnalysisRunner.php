<?php

declare(strict_types=1);

namespace PJGuardian\Services\Analysis;

/**
 * 분석 실행기(P14 인터페이스 → P15 ruff/ESLint 실행, P16 결과 저장).
 *
 * 워커의 분석 자식 프로세스(tools/run_analysis.php)가 분석 하나마다 run()을 한 번 부른다. 부모 워커가 감독한다:
 * 타임아웃(analysis.timeout)·취소(cancel_requested) 때는 이 프로세스 트리를 통째로 끝내므로, 구현은 취소를 직접 확인하지 않아도 된다.
 *
 * 구현 규칙
 * - 도구는 ProcessRunner(허용 목록 bin/, 출력 한도 analysis.output_limit, 메모리 한도 analysis.process_memory_limit)로 실행한다.
 * - 실패(도구 크래시·출력 한도·설정 오류)는 예외가 아니라 AnalysisOutcome::failed(코드)로 돌려준다.
 *   예외가 나가면 자식이 비정상 종료(분석 실패 analysis_crashed)로 기록된다.
 * - 실패·취소에서는 결함과 비교 기준(직전 성공 분석)을 건드리지 않는다(명세 5.4). 결함 저장은 성공 결과로만(P16).
 * - 결과를 DB에 기록하는 것은 호출자(AnalysisQueue::complete)다. 단 P16의 결함 저장처럼 결과와 함께 써야 하는 것은
 *   AnalysisOutcome에 담아 넘긴다.
 */
interface AnalysisRunner
{
    public function run(AnalysisJob $job): AnalysisOutcome;
}
