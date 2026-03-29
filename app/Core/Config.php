<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class Config
{
    private array $items = [];

    public function __construct(string $configPath)
    {
        foreach (glob($configPath . '/*.php') ?: [] as $file) {
            $key = pathinfo($file, PATHINFO_FILENAME);
            $this->items[$key] = require $file;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function require(string $key): mixed
    {
        $value = $this->get($key);
        if ($value === null) {
            throw new RuntimeException(sprintf('Missing config value: %s', $key));
        }

        return $value;
    }
}

