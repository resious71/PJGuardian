<?php

declare(strict_types=1);

namespace PJGuardian\Http;

/**
 * HTTP 응답.
 *
 * 공통 JSON 형식(명세 19.7, docs/architecture.md)
 *   성공 : {"data": ...}
 *   목록 : {"data": [...], "page": {"page": 1, "size": 50, "total": 123}}
 *   오류 : {"error": {"code": "snake_case", "message": "한국어", "request_id": "..."}}
 */
final class Response
{
    /** 본문 대신 보낼 파일(P23 내보내기). 보낸 뒤(성공·실패·연결 끊김) 지운다 */
    private ?string $file = null;
    /** 보낸 뒤 파일을 지우는가(내보내기 임시 파일 true, 백업 묶음 내려받기 false — P27B) */
    private bool $deleteFile = true;

    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status = 200,
        private array $headers = [],
        public readonly string $body = '',
    ) {
    }

    public static function data(mixed $data, int $status = 200): self
    {
        return self::json(['data' => $data], $status);
    }

    /** @param list<mixed> $items */
    /** @param array<string, mixed> $extra data·page 옆에 붙일 값(예: Run 목록의 status, P20R) */
    public static function page(array $items, Pagination $p, int $total, array $extra = []): self
    {
        return self::json(['data' => $items, 'page' => ['page' => $p->page, 'size' => $p->size, 'total' => $total]] + $extra);
    }

    /** @param array<string, mixed>|null $details 있으면 error.details(예: 409 충돌의 최신 값) */
    public static function error(int $status, string $code, string $message, ?string $requestId, ?array $details = null): self
    {
        $error = ['code' => $code, 'message' => $message, 'request_id' => $requestId];
        if ($details !== null) {
            $error['details'] = $details;
        }
        return self::json(['error' => $error], $status);
    }

    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200): self
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return new self($status, ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'], $body);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'private, no-cache'], $html);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self($status, ['Location' => $url, 'Cache-Control' => 'no-store'], '');
    }

    /**
     * 파일 응답(P23 내보내기): 이미 다 만든 임시 파일을 보내고 지운다. 오류는 이 응답을 만들기 전에 JSON으로 판정한다.
     * Cache-Control: no-store(내려받은 파일을 캐시에 남기지 않음). nosniff는 보안 헤더 미들웨어가 붙인다.
     */
    public static function file(string $path, string $contentType, string $disposition): self
    {
        $r = new self(200, ['Content-Type' => $contentType, 'Content-Disposition' => $disposition, 'Cache-Control' => 'no-store',
            'Content-Length' => (string) (int) @filesize($path)], '');
        $r->file = $path;
        return $r;
    }

    /** 있는 파일을 보낸다(지우지 않음 — P27B 백업 묶음 내려받기). 인증·재인증은 호출하는 쪽이 끝낸 뒤 */
    public static function stream(string $path, string $contentType, string $disposition): self
    {
        $r = self::file($path, $contentType, $disposition);
        $r->deleteFile = false;
        return $r;
    }

    public function filePath(): ?string
    {
        return $this->file;
    }

    /** 시험용: 파일 본문을 읽고 임시 파일을 지운다 */
    public function takeFile(): string
    {
        if ($this->file === null) {
            return $this->body;
        }
        $s = (string) @file_get_contents($this->file);
        if ($this->deleteFile) {
            @unlink($this->file);
        }
        return $s;
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return $v;
            }
        }
        return null;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** @return array<string, mixed>|null JSON 본문(테스트용) */
    public function decoded(): ?array
    {
        $d = json_decode($this->body, true);
        return is_array($d) ? $d : null;
    }

    public function send(bool $withBody = true): void
    {
        // PHP 버전을 알리는 헤더는 내보내지 않는다(명세 19.5 하드닝. php.ini expose_php=Off도 설치 체크리스트에 둔다)
        header_remove('X-Powered-By');
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . $v, true);
        }
        if ($this->file !== null && !$this->deleteFile) {
            if ($withBody) {
                readfile($this->file);
            }
            return;
        }
        if ($this->file !== null) {
            $file = $this->file;
            // 연결이 끊겨도 끝까지 실행해 임시 파일을 지운다(비정상 종료면 종료 함수가 지운다)
            ignore_user_abort(true);
            register_shutdown_function(static function () use ($file): void {
                if (is_file($file)) {
                    @unlink($file);
                }
            });
            try {
                if ($withBody) {
                    readfile($file);
                }
            } finally {
                @unlink($file);
            }
            return;
        }
        if ($withBody) {
            echo $this->body;
        }
    }
}
