<?php

declare(strict_types=1);

namespace SmartHeart\Http;

use RuntimeException;

/**
 * An error the client should see, rendered as RFC 9457 application/problem+json.
 * `type` is a slug appended to PROBLEM_BASE_URI (e.g. "validation").
 */
final class HttpError extends RuntimeException
{
    /**
     * @param list<array{field: string, code: string, message?: string}> $errors
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $type,
        public readonly string $title,
        public readonly ?string $detail = null,
        public readonly array $errors = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($detail ?? $title);
    }

    public static function badRequest(string $detail): self
    {
        return new self(400, 'bad-request', 'Bad request', $detail);
    }

    public static function unauthorized(string $detail = 'Missing or invalid credentials'): self
    {
        return new self(401, 'unauthorized', 'Unauthorized', $detail);
    }

    public static function forbidden(string $detail = 'Your role cannot do this'): self
    {
        return new self(403, 'forbidden', 'Forbidden', $detail);
    }

    public static function notFound(string $detail = 'No such resource'): self
    {
        return new self(404, 'not-found', 'Not found', $detail);
    }

    /** @param list<string> $allowed */
    public static function methodNotAllowed(array $allowed): self
    {
        return new self(405, 'method-not-allowed', 'Method not allowed', null, [], ['Allow' => implode(', ', $allowed)]);
    }

    public static function unsupportedMediaType(): self
    {
        return new self(415, 'unsupported-media-type', 'Unsupported media type', 'Send the body as application/json');
    }

    /** @param list<array{field: string, code: string, message?: string}> $errors */
    public static function validation(array $errors, string $detail = 'One or more fields are invalid'): self
    {
        return new self(422, 'validation', 'Validation failed', $detail, $errors);
    }

    public static function tooManyRequests(int $retryAfterSeconds): self
    {
        return new self(429, 'too-many-requests', 'Too many requests', null, [], ['Retry-After' => (string) $retryAfterSeconds]);
    }
}
