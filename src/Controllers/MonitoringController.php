<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\ApiException;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Support\FileLock;
use PJGuardian\Support\Fs;
use PJGuardian\Support\Lang;

/**
 * 시스템 모니터링(P30, 명세 14·19.3).
 *   GET /admin/monitoring              화면(admin만, 읽기 전용) — 카드는 pages/admin_monitoring.js가 API로 그림
 *   GET /api/v1/admin/monitoring       {checked_at, status, alerts, cards, refresh_seconds} (?refresh=1이면 캐시를 쓰지 않고 다시 판정)
 *   GET /health                        헬스체크(인증 없음, 허용 IP만 — 밖이면 없는 주소와 같은 404). 고정 스키마, 식별 정보 없음. ok/warn 200, fail 503.
 *                                      허용 IP당 분당 health.per_minute회(넘으면 429), 같은 본문을 health.cache 동안 다시 씀.
 * 판정은 모두 Services\Monitoring\SystemHealth 한 곳.
 */
final class MonitoringController
{
    public function __construct(private readonly App $app)
    {
    }

    public function page(Request $request): Response
    {
        return Response::html($this->app->view($request)->page('admin/monitoring', [
            'title' => Lang::get('monitoring.title'),
            'user' => $request->attribute('user'),
            'active' => 'admin.system',
            'scripts' => ['js/pages/admin_monitoring.js'],
            'body_class' => 'page-admin-monitoring',
        ], ['refreshSeconds' => $this->app->config->int('monitoring.refresh')]));
    }

    public function show(Request $request): Response
    {
        $snap = $this->app->systemHealth()->snapshot(($request->query['refresh'] ?? '') === '1');
        // P31: 초기 관리자 비밀번호가 남아 있으면 이 화면의 경고 목록에 한 건(판정 출처는 하드닝 점검과 같은 App::initialPasswordAdmins).
        // 운영 장애가 아니므로 /health·장애 배너(SystemHealth 판정)는 바꾸지 않는다
        $initial = $this->app->initialPasswordAdmins();
        if ($initial !== []) {
            $snap['alerts'][] = ['code' => 'initial_admin_password', 'level' => 'warn', 'params' => ['ids' => implode(', ', $initial)]];
            if ($snap['status'] === 'ok') {
                $snap['status'] = 'warn';
            }
        }
        return Response::data($snap + ['refresh_seconds' => $this->app->config->int('monitoring.refresh')])->withHeader('Cache-Control', 'no-store');
    }

    /** GET /health */
    public function health(Request $request): Response
    {
        $ip = $request->clientIp; // 접속 주소 — 신뢰 프록시(security.trusted_proxies, P32B)를 거쳤을 때만 X-Forwarded-For(Http\ForwardedHeaders)
        if (!self::allowed($ip, (array) ($this->app->config->get('health.allowed_ips') ?? []))) {
            // 존재를 알리지 않는다: 등록되지 않은 경로와 같은 응답
            throw new ApiException(404, 'not_found');
        }
        $dir = rtrim($this->app->paths->work(), '/') . '/monitoring/health_rate';
        Fs::ensureDir($dir);
        $this->quota($dir, $ip);
        $body = $this->cachedBody(dirname($dir));
        $res = Response::json($body, $body['status'] === 'fail' ? 503 : 200);
        return $res->withHeader('Cache-Control', 'no-store');
    }

    /**
     * 허용 목록(IP 또는 CIDR, IPv4·IPv6) 안인가. 빈 목록은 모두 거부.
     *
     * @param list<string> $list
     */
    public static function allowed(string $ip, array $list): bool
    {
        return \PJGuardian\Support\IpList::contains($ip, $list);
    }

    /** 허용 IP당 분당 횟수(파일 이름은 주소의 해시 — 사용자 입력으로 경로를 만들지 않음) */
    private function quota(string $dir, string $ip): void
    {
        $per = $this->app->config->int('health.per_minute');
        $key = substr(hash('sha256', $ip), 0, 32);
        $lock = FileLock::acquire($dir . '/' . $key . '.lock');
        try {
            $file = $dir . '/' . $key . '.json';
            $now = $this->app->clock->now()->getTimestamp();
            $list = array_values(array_filter((array) json_decode((string) @file_get_contents($file), true), static fn ($t): bool => is_int($t) && $t > $now - 60));
            if (count($list) >= $per) {
                throw new ApiException(429, 'health_rate_limited', ['n' => $per]);
            }
            $list[] = $now;
            Fs::atomicWrite($file, (string) json_encode($list));
        } finally {
            $lock->release();
        }
    }

    /** @return array{status:string, time:string, checks:array<string, mixed>} */
    private function cachedBody(string $dir): array
    {
        $file = $dir . '/health_body.json';
        $ttl = $this->app->config->int('health.cache');
        $raw = @file_get_contents($file);
        $c = $raw === false ? null : json_decode($raw, true);
        $now = $this->app->clock->now()->getTimestamp();
        if ($ttl > 0 && is_array($c) && is_string($c['time'] ?? null) && $now - (int) strtotime($c['time']) < $ttl && $now >= (int) strtotime($c['time'])) {
            return $c;
        }
        $body = $this->app->systemHealth()->healthBody();
        Fs::atomicWrite($file, (string) json_encode($body, JSON_UNESCAPED_SLASHES));
        return $body;
    }
}
