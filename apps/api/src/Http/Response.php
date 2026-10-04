<?php

declare(strict_types=1);

namespace SmartHeart\Http;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly mixed $body = null,
        public array $headers = [],
    ) {
    }

    public static function json(mixed $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'application/json']);
    }

    public static function noContent(): self
    {
        return new self(204);
    }

    public static function problem(HttpError $e, string $baseUri, string $requestId): self
    {
        $body = [
            'type' => $baseUri . '/' . $e->type,
            'title' => $e->title,
            'status' => $e->status,
        ];
        if ($e->detail !== null) {
            $body['detail'] = $e->detail;
        }
        if ($e->errors !== []) {
            $body['errors'] = $e->errors;
        }
        $body['request_id'] = $requestId;

        return new self($e->status, $body, ['Content-Type' => 'application/problem+json'] + $e->headers);
    }

    public function encodedBody(): string
    {
        if ($this->body === null) {
            return '';
        }
        return json_encode($this->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        echo $this->encodedBody();
    }
}
