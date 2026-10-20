<?php

declare(strict_types=1);

namespace PJGuardian\Http\Middleware;

use PJGuardian\Http\ApiException;
use PJGuardian\Http\Middleware;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Support\Html;
use PJGuardian\Support\Lang;
use PJGuardian\Support\Logger;

/**
 * 예외를 공통 오류 응답으로 바꾼다.
 * - ApiException: 지정한 상태·코드·메시지. API 경로는 JSON, 그 밖은 HTML 오류 페이지.
 * - 그 밖의 예외: 500과 일반 메시지만 내보내고, 예외 종류·메시지·스택은 로그에만 남긴다.
 *   응답에 스택 트레이스나 내부 경로를 넣지 않는다.
 */
final class ErrorMiddleware implements Middleware
{
    public function __construct(private readonly Logger $logger)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        try {
            return $next($request);
        } catch (ApiException $e) {
            if ($e->status >= 500) {
                $this->logger->error('API 오류 ' . $e->errorCode, $e->logContext + ['path' => $request->path]);
            } elseif ($e->logContext !== []) {
                $this->logger->warn('요청 거부 ' . $e->errorCode, $e->logContext + ['path' => $request->path]);
            }
            $response = $this->render($request, $e->status, $e->errorCode, $e->userMessage(), $e->details);
            foreach ($e->headers as $k => $v) {
                $response = $response->withHeader($k, $v);
            }
            return $response;
        } catch (\PDOException $e) {
            // P31: 큰 분석 반영 같은 긴 쓰기가 끝나기를 busy_timeout만큼 기다려도 못 쓰면(SQLITE_BUSY 5) 서버 오류(500)가 아니라
            // "잠시 뒤 다시" 안내(503 db_busy + Retry-After). 데이터는 바뀌지 않았다(문장 하나가 통째로 실패)
            if ((int) ($e->errorInfo[1] ?? 0) === 5 || str_contains($e->getMessage(), 'database is locked')) {
                $this->logger->warn('DB 잠금 대기 초과(잠시 뒤 다시 안내)', ['path' => $request->path, 'method' => $request->method]);
                return $this->render($request, 503, 'db_busy', Lang::get('error.db_busy'))->withHeader('Retry-After', '5');
            }
            return $this->unhandled($request, $e);
        } catch (\Throwable $e) {
            return $this->unhandled($request, $e);
        }
    }

    private function unhandled(Request $request, \Throwable $e): Response
    {
        $this->logger->error('처리되지 않은 예외', [
            'exception' => get_class($e),
            'error' => $e->getMessage(),
            'at' => basename($e->getFile()) . ':' . $e->getLine(),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 20),
            'path' => $request->path,
            'method' => $request->method,
        ]);
        return $this->render($request, 500, 'internal', Lang::get('error.internal'));
    }

    /** @param array<string, mixed>|null $details API 응답의 error.details(ApiException::details) */
    private function render(Request $request, int $status, string $code, string $message, ?array $details = null): Response
    {
        $requestId = $request->attribute('request_id', $this->logger->requestId());
        if ($request->isApi()) {
            return Response::error($status, $code, $message, $requestId, $details);
        }
        $title = $status === 404 ? Lang::get('page.not_found_title') : Lang::get('page.error_title');
        // 빈 파비콘(data:, CSP img-src 허용): 없으면 브라우저가 /favicon.ico를 요청해 404가 남는다
        $html = '<!doctype html><html lang="' . \PJGuardian\Support\Lang::locale() . '"><head><meta charset="utf-8"><title>' . Html::e($title) . '</title>'
            . '<link rel="icon" href="data:,"></head><body>'
            . '<h1>' . Html::e($title) . '</h1><p>' . Html::e($message) . '</p>'
            . '<p>' . Html::e(Lang::get('page.request_id', ['id' => (string) $requestId])) . '</p></body></html>';
        return Response::html($html, $status);
    }
}
