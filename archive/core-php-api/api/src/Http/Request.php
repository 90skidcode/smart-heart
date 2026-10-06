<?php

declare(strict_types=1);

namespace SmartHeart\Http;

use JsonException;

final readonly class Request
{
    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers keys lower-cased
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query = [],
        public array $headers = [],
        public string $body = '',
        public string $ip = '0.0.0.0',
        public string $id = '',
    ) {
    }

    /** The same request carrying the id the kernel assigned (for logs, audit and problem responses). */
    public function withId(string $id): self
    {
        return new self($this->method, $this->path, $this->query, $this->headers, $this->body, $this->ip, $id);
    }

    public function userAgent(): string
    {
        return substr($this->header('user-agent') ?? '', 0, 255);
    }

    /** Request id, client IP and user agent, as audit_log stores them. */
    public function auditContext(): array
    {
        return ['request_id' => $this->id, 'ip' => $this->ip, 'user_agent' => $this->userAgent()];
    }

    /** The token from an `Authorization: Bearer …` header, or null. */
    public function bearerToken(): ?string
    {
        $value = $this->header('authorization') ?? '';
        return preg_match('/^Bearer\s+(\S+)$/i', $value, $m) ? $m[1] : null;
    }

    /** Builds the request from PHP's globals. `$basePath` (e.g. /api/v1) is removed from the path. */
    public static function fromGlobals(string $basePath): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
            $path = substr($path, strlen($basePath)) ?: '/';
        } else {
            $path = '/__outside_base_path__' . $path; // never matches a route → 404
        }
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        $query = array_map(static fn($v) => is_array($v) ? '' : (string) $v, $_GET);

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            rtrim($path, '/') ?: '/',
            $query,
            $headers,
            (string) file_get_contents('php://input'),
            // REMOTE_ADDR only: X-Forwarded-For is client-controlled until a trusted proxy is configured.
            (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * The body as a JSON object.
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $type = strtolower(trim(explode(';', $this->header('content-type') ?? '')[0]));
        if ($type !== 'application/json') {
            throw HttpError::unsupportedMediaType();
        }
        try {
            $data = json_decode($this->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw HttpError::badRequest('The body is not valid JSON');
        }
        if (!is_array($data) || (array_is_list($data) && $data !== [])) {
            throw HttpError::badRequest('The body must be a JSON object');
        }
        return $data;
    }
}
