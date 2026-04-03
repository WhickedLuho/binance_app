<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
        public readonly string $rawBody
    ) {
    }

    public static function capture(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $rawBody = file_get_contents('php://input') ?: '';
        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
        $body = self::parseBody($rawBody, $contentType);

        return new self(
            method: strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            path: $path,
            query: $_GET,
            body: $body,
            rawBody: $rawBody
        );
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    private static function parseBody(string $rawBody, string $contentType): array
    {
        if ($rawBody === '') {
            return $_POST;
        }

        if (str_contains(strtolower($contentType), 'application/json')) {
            $decoded = json_decode($rawBody, true);

            return is_array($decoded) ? $decoded : [];
        }

        if ($_POST !== []) {
            return $_POST;
        }

        parse_str($rawBody, $parsed);

        return is_array($parsed) ? $parsed : [];
    }
}
