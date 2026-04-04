<?php

declare(strict_types=1);

namespace App\Services\Market;

use App\Core\Config;
use App\Services\Strategy\SignalEngine;
use Throwable;

final class MarketAnalyzer
{
    public function __construct(
        private readonly Config $config,
        private readonly MarketSnapshotService $snapshots,
        private readonly SignalEngine $signalEngine
    ) {
    }

    public function analyzeConfiguredPairs(): array
    {
        $symbols = array_values(array_unique($this->config->get('pairs.pairs', [])));
        $decisionTimeframe = (string) $this->config->get('pairs.decision_timeframe', '15m');
        $signalTimeframes = $this->snapshots->signalTimeframes();
        $limit = max(2, (int) $this->config->get('pairs.analysis_limit', $this->config->get('pairs.default_limit', 240)));

        try {
            $snapshots = $this->snapshots->buildSymbolSnapshots($symbols, $signalTimeframes, $limit);
        } catch (Throwable $throwable) {
            return array_map(fn (string $symbol): array => $this->errorSignal($symbol, $decisionTimeframe, $throwable->getMessage()), $symbols);
        }

        $signals = [];
        foreach ($symbols as $symbol) {
            try {
                $snapshot = $snapshots[$symbol] ?? null;
                if (!is_array($snapshot)) {
                    $signals[] = $this->errorSignal($symbol, $decisionTimeframe, 'Snapshot missing for symbol.');
                    continue;
                }

                $signal = $this->signalEngine->analyze($symbol, $decisionTimeframe, $snapshot);
                $snapshotIssues = $snapshot['meta']['issues'] ?? [];
                if (($signal['market_regime'] ?? '') === 'ERROR' && $snapshotIssues !== []) {
                    $signal['error'] = implode('; ', array_map('strval', $snapshotIssues));
                }

                $signals[] = $signal;
            } catch (Throwable $throwable) {
                $signals[] = $this->errorSignal($symbol, $decisionTimeframe, $throwable->getMessage());
            }
        }

        return $signals;
    }

    private function errorSignal(string $symbol, string $decisionTimeframe, string $message): array
    {
        return [
            'symbol' => $symbol,
            'interval' => $decisionTimeframe,
            'market_regime' => 'ERROR',
            'action' => 'NO_TRADE',
            'direction' => 'NO_TRADE',
            'price' => 0.0,
            'confidence' => 0,
            'bull_score' => 0,
            'bear_score' => 0,
            'long_score' => 0,
            'short_score' => 0,
            'risk_penalty' => 100,
            'risk' => [
                'allowed' => false,
                'flags' => ['The pair analysis failed due to an error. Check the error message for details.'],
            ],
            'metrics' => [
                'ema20' => null,
                'ema50' => null,
                'ema200' => null,
                'rsi14' => null,
                'macd_histogram' => null,
                'atr_percent' => null,
                'volume_ratio' => null,
                'last_candle_change' => null,
            ],
            'timeframes' => [],
            'reasons' => [$message],
            'error' => $message,
        ];
    }
}
