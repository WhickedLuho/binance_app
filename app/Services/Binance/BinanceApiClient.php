<?php

declare(strict_types=1);

namespace App\Services\Binance;

use RuntimeException;

final class BinanceApiClient
{
    private const BASE_URL = 'https://api.binance.com';

    public function getPrice(string $symbol): float
    {
        $data = $this->request('/api/v3/ticker/price', ['symbol' => $symbol]);

        return (float) ($data['price'] ?? 0.0);
    }

    public function getKlines(string $symbol, string $interval = '1m', int $limit = 200): array
    {
        $rows = $this->request('/api/v3/klines', [
            'symbol' => $symbol,
            'interval' => $interval,
            'limit' => $limit,
        ]);

        return array_map(static fn (array $row): array => [
            'open_time' => (int) $row[0],
            'open' => (float) $row[1],
            'high' => (float) $row[2],
            'low' => (float) $row[3],
            'close' => (float) $row[4],
            'volume' => (float) $row[5],
            'close_time' => (int) $row[6],
        ], $rows);
    }

    private function request(string $path, array $query = []): array
    {
        $url = self::BASE_URL . $path . '?' . http_build_query($query);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Binance request failed: ' . $error);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid Binance response payload.');
        }

        if ($httpCode >= 400) {
            $message = $decoded['msg'] ?? 'Unknown Binance API error.';
            throw new RuntimeException(sprintf('Binance API error %d: %s', $httpCode, $message));
        }

        return $decoded;
    }
}

