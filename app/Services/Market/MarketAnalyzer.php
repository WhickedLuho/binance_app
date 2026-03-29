<?php

declare(strict_types=1);

namespace App\Services\Market;

use App\Core\Config;
use App\Services\Binance\BinanceApiClient;
use App\Services\Strategy\SignalEngine;
use Throwable;

final class MarketAnalyzer
{
    public function __construct(
        private readonly Config $config,
        private readonly BinanceApiClient $binance,
        private readonly SignalEngine $signalEngine
    ) {
    }

    public function analyzeConfiguredPairs(): array
    {
        $symbols = array_values(array_unique($this->config->get('pairs.pairs', [])));
        $timeframes = $this->config->get('pairs.analysis_timeframes', [$this->config->get('pairs.default_interval', '1m')]);
        $limit = (int) $this->config->get('pairs.default_limit', 200);
        $decisionTimeframe = (string) $this->config->get('pairs.decision_timeframe', '5m');

        $signals = [];
        foreach ($symbols as $symbol) {
            try {
                $candlesByTimeframe = [];
                foreach ($timeframes as $timeframe) {
                    $candlesByTimeframe[$timeframe] = $this->binance->getKlines($symbol, (string) $timeframe, $limit);
                }

                $signals[] = $this->signalEngine->analyze($symbol, $decisionTimeframe, $candlesByTimeframe);
            } catch (Throwable $throwable) {
                $signals[] = [
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
                        'flags' => ['A pár elemzése sikertelen'],
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
                    'reasons' => [$throwable->getMessage()],
                    'error' => $throwable->getMessage(),
                ];
            }
        }

        return $signals;
    }
}

