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

        return $this->mapKlines($rows);
    }

    public function getKlinesBatch(array $requests): array
    {
        $jobs = [];
        foreach ($requests as $key => $request) {
            $jobs[(string) $key] = [
                'path' => '/api/v3/klines',
                'query' => [
                    'symbol' => (string) ($request['symbol'] ?? ''),
                    'interval' => (string) ($request['interval'] ?? '1m'),
                    'limit' => max(2, (int) ($request['limit'] ?? 200)),
                ],
            ];
        }

        $responses = $this->requestMany($jobs);
        $results = [];

        foreach ($responses as $key => $response) {
            if (!($response['ok'] ?? false)) {
                $results[$key] = [
                    'ok' => false,
                    'error' => (string) ($response['error'] ?? 'Unknown Binance batch error.'),
                ];
                continue;
            }

            $results[$key] = [
                'ok' => true,
                'data' => $this->mapKlines((array) ($response['data'] ?? [])),
            ];
        }

        return $results;
    }

    private function mapKlines(array $rows): array
    {
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

    private function requestMany(array $jobs): array
    {
        if ($jobs === []) {
            return [];
        }

        $multiHandle = curl_multi_init();
        $handles = [];

        foreach ($jobs as $key => $job) {
            $url = self::BASE_URL . (string) $job['path'] . '?' . http_build_query((array) ($job['query'] ?? []));
            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 4,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);

            curl_multi_add_handle($multiHandle, $handle);
            $handles[(string) $key] = $handle;
        }

        $running = null;
        do {
            $status = curl_multi_exec($multiHandle, $running);
            if ($status > CURLM_OK) {
                break;
            }

            if ($running > 0) {
                curl_multi_select($multiHandle, 1.0);
            }
        } while ($running > 0);

        $results = [];
        foreach ($handles as $key => $handle) {
            $response = curl_multi_getcontent($handle);
            $error = curl_error($handle);
            $httpCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

            if ($error !== '') {
                $results[$key] = [
                    'ok' => false,
                    'error' => 'Binance request failed: ' . $error,
                ];
            } else {
                $decoded = json_decode($response, true);
                if (!is_array($decoded)) {
                    $results[$key] = [
                        'ok' => false,
                        'error' => 'Invalid Binance response payload.',
                    ];
                } elseif ($httpCode >= 400) {
                    $message = $decoded['msg'] ?? 'Unknown Binance API error.';
                    $results[$key] = [
                        'ok' => false,
                        'error' => sprintf('Binance API error %d: %s', $httpCode, $message),
                    ];
                } else {
                    $results[$key] = [
                        'ok' => true,
                        'data' => $decoded,
                    ];
                }
            }

            curl_multi_remove_handle($multiHandle, $handle);
            curl_close($handle);
        }

        curl_multi_close($multiHandle);

        return $results;
    }
}
