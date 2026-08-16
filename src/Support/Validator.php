<?php

declare(strict_types=1);

namespace RoyalSpin\Support;

/**
 * Server-side input validation. The client is never trusted, so every inbound
 * field (HTTP form, JSON body or WebSocket frame) passes through here.
 */
final class Validator
{
    /** Room-code alphabet: no O/0, no I/1, no L — easy to read and type on a phone. */
    public const ROOM_CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    public const ROOM_CODE_LENGTH   = 4;

    /** @var array<string,string> */
    private array $errors = [];

    /** @param array<string,mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    public function string(string $field, int $min, int $max, ?string $label = null): ?string
    {
        $label = $label ?? $field;
        $value = $this->data[$field] ?? null;
        if (!is_string($value)) {
            $this->errors[$field] = "{$label} is required.";
            return null;
        }
        $value  = trim($value);
        $length = mb_strlen($value);
        if ($length < $min) {
            $this->errors[$field] = "{$label} must be at least {$min} characters.";
            return null;
        }
        if ($length > $max) {
            $this->errors[$field] = "{$label} may be at most {$max} characters.";
            return null;
        }
        return $value;
    }

    public function username(string $field = 'username'): ?string
    {
        $value = $this->string($field, 3, 20, 'Username');
        if ($value === null) {
            return null;
        }
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $value)) {
            $this->errors[$field] = 'Username may only contain letters, numbers, dot, dash and underscore.';
            return null;
        }
        return $value;
    }

    public function email(string $field = 'email'): ?string
    {
        $value = $this->string($field, 5, 190, 'Email');
        if ($value === null) {
            return null;
        }
        $filtered = filter_var($value, FILTER_VALIDATE_EMAIL);
        if ($filtered === false) {
            $this->errors[$field] = 'Please enter a valid email address.';
            return null;
        }
        return strtolower((string) $filtered);
    }

    public function password(string $field = 'password'): ?string
    {
        $value = $this->data[$field] ?? null;
        if (!is_string($value) || $value === '') {
            $this->errors[$field] = 'Password is required.';
            return null;
        }
        if (strlen($value) < 8) {
            $this->errors[$field] = 'Password must be at least 8 characters.';
            return null;
        }
        if (strlen($value) > 200) {
            $this->errors[$field] = 'Password is too long.';
            return null;
        }
        return $value;
    }

    public function intBetween(string $field, int $min, int $max, ?string $label = null): ?int
    {
        $label = $label ?? $field;
        $value = $this->data[$field] ?? null;
        if (!is_numeric($value)) {
            $this->errors[$field] = "{$label} is required.";
            return null;
        }
        $int = (int) $value;
        if ($int < $min || $int > $max) {
            $this->errors[$field] = "{$label} must be between {$min} and {$max}.";
            return null;
        }
        return $int;
    }

    public function roomCode(string $field = 'code'): ?string
    {
        $value = $this->data[$field] ?? null;
        if (!is_string($value)) {
            $this->errors[$field] = 'Please enter a room code.';
            return null;
        }
        $normalised = self::normaliseRoomCode($value);
        if ($normalised === null) {
            $this->errors[$field] = 'That room code does not look right.';
            return null;
        }
        return $normalised;
    }

    /**
     * Uppercases and strips whitespace/separators so "k7-m4" and "K7M4" are the
     * same code. The alphabet deliberately excludes O/0, I/1 and L, so a code
     * containing one of those is a genuine typo and is rejected rather than
     * silently "corrected" into somebody else's room.
     */
    public static function normaliseRoomCode(string $raw): ?string
    {
        $value = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '');

        if (strlen($value) !== self::ROOM_CODE_LENGTH) {
            return null;
        }
        for ($i = 0; $i < strlen($value); $i++) {
            if (!str_contains(self::ROOM_CODE_ALPHABET, $value[$i])) {
                return null;
            }
        }
        return $value;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        foreach ($this->errors as $message) {
            return $message;
        }
        return null;
    }

    public function addError(string $field, string $message): void
    {
        $this->errors[$field] = $message;
    }
}
