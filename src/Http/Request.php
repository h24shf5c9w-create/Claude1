<?php

declare(strict_types=1);

namespace RoyalSpin\Http;

final class Request
{
    /** @param array<string,mixed> $input */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $input,
        public readonly bool $wantsJson,
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri    = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path   = parse_url($uri, PHP_URL_PATH);
        $path   = is_string($path) ? $path : '/';
        $path   = '/' . trim($path, '/');

        $input = $_GET;
        if ($method !== 'GET') {
            $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
            if (str_contains($contentType, 'application/json')) {
                $raw     = file_get_contents('php://input') ?: '';
                $decoded = json_decode($raw, true);
                $input   = array_merge($input, is_array($decoded) ? $decoded : []);
            } else {
                $input = array_merge($input, $_POST);
            }
        }

        $accept    = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        $requested = (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
        $wantsJson = str_starts_with($path, '/api/')
            || str_contains($accept, 'application/json')
            || strtolower($requested) === 'xmlhttprequest';

        return new self($method, $path, $input, $wantsJson);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->input[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input[$key] ?? $default;
        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input[$key] ?? $default;
        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->input[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->input;
    }

    public function ip(): string
    {
        // Only trust a forwarding header when explicitly configured, otherwise
        // a client could spoof its way around rate limiting.
        if (\RoyalSpin\Support\Env::bool('TRUST_PROXY', false)) {
            $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($forwarded !== '') {
                $first = trim(explode(',', $forwarded)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    return $first;
                }
            }
        }
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        return filter_var($remote, FILTER_VALIDATE_IP) === false ? '0.0.0.0' : $remote;
    }

    public function csrfToken(): ?string
    {
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (is_string($header) && $header !== '') {
            return $header;
        }
        $field = $this->input['_csrf'] ?? null;
        return is_string($field) ? $field : null;
    }
}
