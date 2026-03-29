<?php

declare(strict_types=1);

namespace App\Core;

use Closure;
use RuntimeException;

final class Container
{
    private array $entries = [];
    private array $resolved = [];

    public function set(string $id, Closure $factory): void
    {
        $this->entries[$id] = $factory;
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->resolved)) {
            return $this->resolved[$id];
        }

        if (!isset($this->entries[$id])) {
            throw new RuntimeException(sprintf('Service not found: %s', $id));
        }

        return $this->resolved[$id] = ($this->entries[$id])($this);
    }
}

