<?php

declare(strict_types=1);

namespace SmartHeart\Http;

/**
 * Reads fields from a JSON body and collects every problem before failing, so the client sees
 * all invalid fields in one 422. Unknown fields are ignored.
 */
final class Input
{
    /** @var list<array{field: string, code: string, message: string}> */
    private array $errors = [];

    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    public static function fromJson(Request $request): self
    {
        return new self($request->json());
    }

    public function string(string $field, int $maxLength = 255, bool $required = true): ?string
    {
        $value = $this->data[$field] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                $this->fail($field, 'required', 'This field is required');
            }
            return null;
        }
        if (!is_string($value)) {
            $this->fail($field, 'type', 'Must be a string');
            return null;
        }
        if (mb_strlen($value) > $maxLength) {
            $this->fail($field, 'too_long', "At most $maxLength characters");
            return null;
        }
        return $value;
    }

    /** @param list<string> $allowed */
    public function enum(string $field, array $allowed, bool $required = true): ?string
    {
        $value = $this->string($field, 64, $required);
        if ($value !== null && !in_array($value, $allowed, true)) {
            $this->fail($field, 'invalid', 'One of: ' . implode(', ', $allowed));
            return null;
        }
        return $value;
    }

    public function uuid(string $field, bool $required = true): ?string
    {
        $value = $this->string($field, 36, $required);
        if ($value !== null && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
            $this->fail($field, 'invalid', 'Must be a UUID');
            return null;
        }
        return $value === null ? null : strtolower($value);
    }

    public function int(string $field, bool $required = true): ?int
    {
        $value = $this->data[$field] ?? null;
        if ($value === null) {
            if ($required) {
                $this->fail($field, 'required', 'This field is required');
            }
            return null;
        }
        if (!is_int($value)) {
            $this->fail($field, 'type', 'Must be an integer');
            return null;
        }
        return $value;
    }

    public function fail(string $field, string $code, string $message): void
    {
        $this->errors[] = ['field' => $field, 'code' => $code, 'message' => $message];
    }

    /** @throws HttpError 422 listing every invalid field */
    public function validate(): void
    {
        if ($this->errors !== []) {
            throw HttpError::validation($this->errors);
        }
    }
}
