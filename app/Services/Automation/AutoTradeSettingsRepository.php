<?php

declare(strict_types=1);

namespace App\Services\Automation;

use RuntimeException;

final class AutoTradeSettingsRepository
{
    public function __construct(private readonly string $filePath)
    {
    }

    public function load(): array
    {
        $this->ensureDirectory();
        if (!is_file($this->filePath)) {
            return [];
        }

        $raw = file_get_contents($this->filePath);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function save(array $state): void
    {
        $this->ensureDirectory();
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Unable to encode auto trade settings.');
        }

        $handle = fopen($this->filePath, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open auto trade settings storage file.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock auto trade settings storage file.');
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
            throw new RuntimeException('Unable to create auto trade settings storage directory.');
        }
    }
}
