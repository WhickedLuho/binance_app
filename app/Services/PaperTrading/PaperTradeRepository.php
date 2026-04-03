<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use RuntimeException;

final class PaperTradeRepository
{
    public function __construct(private readonly string $filePath)
    {
    }

    public function load(): array
    {
        $this->ensureDirectory();
        if (!is_file($this->filePath)) {
            return $this->defaultState();
        }

        $raw = file_get_contents($this->filePath);
        if ($raw === false || trim($raw) === '') {
            return $this->defaultState();
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $this->normalizeState($decoded) : $this->defaultState();
    }

    public function save(array $state): void
    {
        $this->ensureDirectory();
        $normalized = $this->normalizeState($state);
        $json = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Unable to encode paper trade state.');
        }

        $handle = fopen($this->filePath, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open paper trade storage file.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock paper trade storage file.');
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $json);
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    private function ensureDirectory(): void
    {
        $directory = dirname($this->filePath);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create paper trade storage directory.');
        }
    }

    private function defaultState(): array
    {
        return [
            'positions' => [],
            'history' => [],
            'meta' => [
                'next_id' => 1,
            ],
        ];
    }

    private function normalizeState(array $state): array
    {
        return [
            'positions' => array_values($state['positions'] ?? []),
            'history' => array_values($state['history'] ?? []),
            'meta' => [
                'next_id' => max(1, (int) ($state['meta']['next_id'] ?? 1)),
            ],
        ];
    }
}
