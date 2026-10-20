<?php

declare(strict_types=1);

namespace PJGuardian\Services\Events;

use PJGuardian\Support\Logger;

/**
 * 도메인 이벤트 디스패처(P16 자리, P24 알림이 구독). 이벤트 클래스 이름으로 구독하고 dispatch로 동기 호출한다.
 * 구독자의 예외는 로그만 남기고 다른 구독자·호출자에 퍼뜨리지 않는다(알림 실패가 분석 결과를 바꾸지 않게).
 * 결과 반영 트랜잭션이 커밋된 뒤에만 dispatch한다.
 */
final class EventDispatcher
{
    /** @var array<class-string, list<callable(object): void>> */
    private array $listeners = [];

    public function __construct(private readonly Logger $logger)
    {
    }

    /** @param class-string $event */
    public function subscribe(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function dispatch(object $event): void
    {
        foreach ($this->listeners[$event::class] ?? [] as $l) {
            try {
                $l($event);
            } catch (\Throwable $e) {
                $this->logger->error('이벤트 구독자 오류', ['event' => $event::class, 'error' => $e->getMessage()]);
            }
        }
    }
}
