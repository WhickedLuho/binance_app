<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Core\Config;
use App\Services\Market\MarketAnalyzer;
use App\Services\PaperTrading\PaperTradeService;
use App\Services\Prediction\PairPredictionService;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

final class AutoTradeExecutionService
{
    public function __construct(
        private readonly Config $config,
        private readonly AutoTradeSettingsService $settings,
        private readonly MarketAnalyzer $analyzer,
        private readonly PairPredictionService $predictions,
        private readonly PaperTradeService $paperTrades,
        private readonly string $lockFilePath
    ) {
    }

    public function heartbeat(): array
    {
        $lockHandle = $this->acquireLock();
        if ($lockHandle === null) {
            $settings = $this->settings->show();
            $paperTrading = $this->paperTrades->overview();

            return $this->buildResult(
                $settings,
                $paperTrading,
                [],
                [[
                    'symbol' => '*',
                    'status' => 'LOCKED',
                    'reason' => 'Automation heartbeat skipped because another run is still active.',
                ]],
                'Automation heartbeat skipped because a previous run is still active.'
            );
        }

        try {
            $settings = $this->settings->show();
            $paperTrading = $this->paperTrades->overview();
            $actions = [];
            $evaluations = [];

            if (!$settings['enabled']) {
                return $this->buildResult(
                    $settings,
                    $paperTrading,
                    $actions,
                    $evaluations,
                    'Automation is disabled. Saved settings stay ready, but no auto entries or exits will run.'
                );
            }

            [$paperTrading, $closeActions] = $this->closeTriggeredPositions($settings, $paperTrading);
            $actions = [...$actions, ...$closeActions];

            $signalsBySymbol = $this->indexBySymbol($this->analyzer->analyzeConfiguredPairs());
            $maxOpenPositions = max(1, (int) ($settings['max_open_positions'] ?? 1));
            $pairs = array_values(array_filter(
                $settings['pairs'] ?? [],
                static fn (array $pair): bool => (bool) ($pair['enabled'] ?? false)
            ));

            usort($pairs, static function (array $left, array $right): int {
                $allocationCompare = ((float) ($right['effective_allocation_percent'] ?? 0.0)) <=> ((float) ($left['effective_allocation_percent'] ?? 0.0));
                if ($allocationCompare !== 0) {
                    return $allocationCompare;
                }

                return strcmp((string) ($left['symbol'] ?? ''), (string) ($right['symbol'] ?? ''));
            });

            foreach ($pairs as $pair) {
                if (count($paperTrading['open_positions'] ?? []) >= $maxOpenPositions) {
                    $evaluations[] = [
                        'symbol' => (string) ($pair['symbol'] ?? ''),
                        'status' => 'SKIPPED',
                        'reason' => 'Maximum auto-managed open positions reached.',
                    ];
                    break;
                }

                $symbol = (string) ($pair['symbol'] ?? '');
                $capital = (float) ($pair['capital_usdt'] ?? 0.0);
                if ($symbol === '' || $capital <= 0.0) {
                    $evaluations[] = [
                        'symbol' => $symbol,
                        'status' => 'SKIPPED',
                        'reason' => 'Pair capital is zero, so no position can be opened.',
                    ];
                    continue;
                }

                if ($this->hasOpenPositionForSymbol($paperTrading['open_positions'] ?? [], $symbol)) {
                    $evaluations[] = [
                        'symbol' => $symbol,
                        'status' => 'HELD',
                        'reason' => 'An open paper position already exists for this symbol.',
                    ];
                    continue;
                }

                if ($this->isSymbolCoolingDown($settings, $paperTrading['history'] ?? [], $symbol)) {
                    $evaluations[] = [
                        'symbol' => $symbol,
                        'status' => 'COOLDOWN',
                        'reason' => 'The symbol is still in cooldown after the last automated trade.',
                    ];
                    continue;
                }

                $signal = $signalsBySymbol[$symbol] ?? null;
                $volatilityGate = $this->passesSignalVolatilityGate($settings, $signal);
                if (!$volatilityGate['allowed']) {
                    $evaluations[] = [
                        'symbol' => $symbol,
                        'status' => 'SKIPPED',
                        'reason' => $volatilityGate['reason'],
                    ];
                    continue;
                }

                $candidate = $this->candidateForSettings($settings);
                $prediction = $this->predictions->predict($symbol);
                $predictionGate = $this->passesPredictionGate($settings, $candidate, $prediction);
                if (!$predictionGate['allowed']) {
                    $evaluations[] = [
                        'symbol' => $symbol,
                        'status' => 'SKIPPED',
                        'reason' => $predictionGate['reason'],
                    ];
                    continue;
                }

                try {
                    $paperTrading = $this->paperTrades->openPosition([
                        'symbol' => $symbol,
                        'trade_type' => $candidate['trade_type'],
                        'side' => $candidate['side'],
                        'margin_type' => $candidate['trade_type'] === 'SPOT' ? 'SPOT' : (string) ($settings['default_margin_type'] ?? 'ISOLATED'),
                        'leverage' => $candidate['trade_type'] === 'SPOT' ? 1 : (int) ($settings['default_leverage'] ?? 1),
                        'capital' => round($capital, 4),
                        'entry_price' => (float) $prediction['current_price'],
                        'stop_loss' => $predictionGate['scenario']['invalidation'] ?? null,
                        'take_profit' => $predictionGate['scenario']['suggested_take_profit'] ?? null,
                        'notes' => sprintf(
                            'AUTO prediction %s | reward %.2f%% | trigger %.2f%%',
                            strtolower($candidate['label']),
                            (float) ($predictionGate['scenario']['reward_percent'] ?? 0.0),
                            (float) $candidate['min_reward_percent']
                        ),
                        'source' => 'AUTO_PREDICTION',
                        'automation' => [
                            'label' => $candidate['label'],
                            'prediction_bias' => $prediction['bias'] ?? 'UNKNOWN',
                            'prediction_confidence' => $prediction['confidence'] ?? 0,
                            'prediction_generated_at' => $prediction['generated_at'] ?? $this->nowAtom(),
                            'trigger_reward_percent' => round((float) ($predictionGate['scenario']['reward_percent'] ?? 0.0), 2),
                            'min_reward_percent' => round((float) $candidate['min_reward_percent'], 2),
                            'max_prediction_atr_percent' => round((float) ($settings['max_prediction_atr_percent'] ?? 0.0), 2),
                            'max_signal_candle_change_percent' => round((float) ($settings['max_signal_candle_change_percent'] ?? 0.0), 2),
                        ],
                    ]);

                    $openedPosition = $this->findLatestAutoPosition($paperTrading['open_positions'] ?? [], $symbol);
                    $actions[] = [
                        'type' => 'OPEN',
                        'symbol' => $symbol,
                        'position_id' => $openedPosition['id'] ?? null,
                        'reason' => sprintf(
                            'Opened %s because reward %.2f%% cleared the %.2f%% trigger.',
                            $candidate['label'],
                            (float) ($predictionGate['scenario']['reward_percent'] ?? 0.0),
                            (float) $candidate['min_reward_percent']
                        ),
                    ];
                    $evaluations[] = [
                        'symbol' => $symbol,
                        'status' => 'OPENED',
                        'reason' => 'Prediction trigger matched and the paper position was opened.',
                    ];
                } catch (Throwable $throwable) {
                    $evaluations[] = [
                        'symbol' => $symbol,
                        'status' => 'FAILED',
                        'reason' => $throwable->getMessage(),
                    ];
                }
            }

            $openedCount = count(array_filter($actions, static fn (array $action): bool => $action['type'] === 'OPEN'));
            $closedCount = count(array_filter($actions, static fn (array $action): bool => $action['type'] === 'CLOSE'));
            $message = sprintf(
                'Automation heartbeat finished: %d opened, %d closed, %d auto positions open.',
                $openedCount,
                $closedCount,
                count($this->autoPositions($paperTrading['open_positions'] ?? []))
            );

            return $this->buildResult($settings, $paperTrading, $actions, $evaluations, $message);
        } finally {
            $this->releaseLock($lockHandle);
        }
    }

    private function closeTriggeredPositions(array $settings, array $paperTrading): array
    {
        $actions = [];
        $closeOnTakeProfit = (bool) ($settings['close_on_take_profit'] ?? true);
        $closeOnStopLoss = (bool) ($settings['close_on_stop_loss'] ?? true);

        if (!$closeOnTakeProfit && !$closeOnStopLoss) {
            return [$paperTrading, $actions];
        }

        foreach ($paperTrading['open_positions'] ?? [] as $position) {
            if (($position['source'] ?? 'MANUAL') !== 'AUTO_PREDICTION') {
                continue;
            }

            $currentPrice = (float) ($position['current_price'] ?? 0.0);
            if ($currentPrice <= 0.0) {
                continue;
            }

            $reason = null;
            if ($closeOnTakeProfit && $this->hasReachedTakeProfit($position, $currentPrice)) {
                $reason = 'AUTO_TAKE_PROFIT';
            } elseif ($closeOnStopLoss && $this->hasReachedStopLoss($position, $currentPrice)) {
                $reason = 'AUTO_STOP_LOSS';
            }

            if ($reason === null) {
                continue;
            }

            try {
                $paperTrading = $this->paperTrades->closePosition([
                    'id' => $position['id'],
                    'close_price' => $currentPrice,
                    'reason' => $reason,
                ]);
                $actions[] = [
                    'type' => 'CLOSE',
                    'symbol' => $position['symbol'],
                    'position_id' => $position['id'],
                    'reason' => $reason,
                ];
            } catch (Throwable) {
                continue;
            }
        }

        return [$paperTrading, $actions];
    }

    private function candidateForSettings(array $settings): array
    {
        return match ((string) ($settings['default_position_type'] ?? 'FUTURES_LONG')) {
            'SPOT' => [
                'trade_type' => 'SPOT',
                'side' => 'LONG',
                'scenario_key' => 'long',
                'expected_bias' => 'BULLISH',
                'min_reward_percent' => (float) ($settings['min_profit_trigger_percent_spot'] ?? 0.0),
                'label' => 'spot long',
            ],
            'FUTURES_SHORT' => [
                'trade_type' => 'FUTURES',
                'side' => 'SHORT',
                'scenario_key' => 'short',
                'expected_bias' => 'BEARISH',
                'min_reward_percent' => (float) ($settings['min_profit_trigger_percent_short'] ?? 0.0),
                'label' => 'futures short',
            ],
            default => [
                'trade_type' => 'FUTURES',
                'side' => 'LONG',
                'scenario_key' => 'long',
                'expected_bias' => 'BULLISH',
                'min_reward_percent' => (float) ($settings['min_profit_trigger_percent_long'] ?? 0.0),
                'label' => 'futures long',
            ],
        };
    }

    private function passesSignalVolatilityGate(array $settings, ?array $signal): array
    {
        if (!is_array($signal) || ($signal['market_regime'] ?? null) === 'ERROR') {
            return [
                'allowed' => false,
                'reason' => 'Signal data is unavailable for this symbol right now.',
            ];
        }

        $candleChange = abs((float) ($signal['metrics']['last_candle_change'] ?? 0.0));
        $maxCandleChange = (float) ($settings['max_signal_candle_change_percent'] ?? 0.0);
        if ($maxCandleChange > 0.0 && $candleChange > $maxCandleChange) {
            return [
                'allowed' => false,
                'reason' => sprintf('Recent candle move %.2f%% is above the %.2f%% safety limit.', $candleChange, $maxCandleChange),
            ];
        }

        return [
            'allowed' => true,
            'reason' => 'Signal volatility gate passed.',
        ];
    }

    private function passesPredictionGate(array $settings, array $candidate, array $prediction): array
    {
        if (($prediction['bias'] ?? 'INSUFFICIENT_DATA') !== $candidate['expected_bias']) {
            return [
                'allowed' => false,
                'reason' => sprintf('Prediction bias is %s, so it does not support a %s entry.', (string) ($prediction['bias'] ?? 'UNKNOWN'), $candidate['label']),
            ];
        }

        $scenario = is_array($prediction['scenarios'][$candidate['scenario_key']] ?? null)
            ? $prediction['scenarios'][$candidate['scenario_key']]
            : [];
        $rewardPercent = (float) ($scenario['reward_percent'] ?? 0.0);
        if ($rewardPercent < (float) $candidate['min_reward_percent']) {
            return [
                'allowed' => false,
                'reason' => sprintf('Reward %.2f%% is below the configured %.2f%% trigger.', $rewardPercent, (float) $candidate['min_reward_percent']),
            ];
        }

        $predictionAtrPercent = $this->maxPredictionAtrPercent($prediction);
        $maxPredictionAtrPercent = (float) ($settings['max_prediction_atr_percent'] ?? 0.0);
        if ($maxPredictionAtrPercent > 0.0 && $predictionAtrPercent > $maxPredictionAtrPercent) {
            return [
                'allowed' => false,
                'reason' => sprintf('Prediction ATR %.2f%% is above the %.2f%% safety limit.', $predictionAtrPercent, $maxPredictionAtrPercent),
            ];
        }

        if ((float) ($prediction['current_price'] ?? 0.0) <= 0.0) {
            return [
                'allowed' => false,
                'reason' => 'Prediction price is not usable yet.',
            ];
        }

        if (($scenario['invalidation'] ?? null) === null || ($scenario['suggested_take_profit'] ?? null) === null) {
            return [
                'allowed' => false,
                'reason' => 'Prediction is missing invalidation or take-profit levels.',
            ];
        }

        return [
            'allowed' => true,
            'reason' => 'Prediction gate passed.',
            'scenario' => $scenario,
        ];
    }

    private function maxPredictionAtrPercent(array $prediction): float
    {
        $values = [];
        foreach ((array) ($prediction['timeframes'] ?? []) as $row) {
            if (($row['atr_percent'] ?? null) !== null) {
                $values[] = abs((float) $row['atr_percent']);
            }
        }

        return $values === [] ? 0.0 : max($values);
    }

    private function hasOpenPositionForSymbol(array $positions, string $symbol): bool
    {
        foreach ($positions as $position) {
            if (($position['symbol'] ?? '') === $symbol) {
                return true;
            }
        }

        return false;
    }

    private function isSymbolCoolingDown(array $settings, array $history, string $symbol): bool
    {
        $cooldownMinutes = max(0, (int) ($settings['cooldown_minutes'] ?? 0));
        if ($cooldownMinutes === 0) {
            return false;
        }

        $threshold = $this->now()->sub(new DateInterval('PT' . $cooldownMinutes . 'M'));
        foreach ($history as $position) {
            if (($position['symbol'] ?? '') !== $symbol || ($position['source'] ?? 'MANUAL') !== 'AUTO_PREDICTION') {
                continue;
            }

            $closedAt = $position['closed_at'] ?? null;
            if (!is_string($closedAt) || trim($closedAt) === '') {
                continue;
            }

            try {
                $closedAtDate = new DateTimeImmutable($closedAt);
            } catch (Throwable) {
                continue;
            }

            if ($closedAtDate >= $threshold) {
                return true;
            }
        }

        return false;
    }

    private function hasReachedTakeProfit(array $position, float $currentPrice): bool
    {
        $takeProfit = $position['take_profit'] ?? null;
        if ($takeProfit === null || $takeProfit === '') {
            return false;
        }

        return ($position['side'] ?? 'LONG') === 'SHORT'
            ? $currentPrice <= (float) $takeProfit
            : $currentPrice >= (float) $takeProfit;
    }

    private function hasReachedStopLoss(array $position, float $currentPrice): bool
    {
        $stopLoss = $position['stop_loss'] ?? null;
        if ($stopLoss === null || $stopLoss === '') {
            return false;
        }

        return ($position['side'] ?? 'LONG') === 'SHORT'
            ? $currentPrice >= (float) $stopLoss
            : $currentPrice <= (float) $stopLoss;
    }

    private function autoPositions(array $positions): array
    {
        return array_values(array_filter(
            $positions,
            static fn (array $position): bool => ($position['source'] ?? 'MANUAL') === 'AUTO_PREDICTION'
        ));
    }

    private function indexBySymbol(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $symbol = (string) ($row['symbol'] ?? '');
            if ($symbol !== '') {
                $indexed[$symbol] = $row;
            }
        }

        return $indexed;
    }

    private function findLatestAutoPosition(array $positions, string $symbol): ?array
    {
        $matches = array_values(array_filter(
            $positions,
            static fn (array $position): bool => ($position['symbol'] ?? '') === $symbol && ($position['source'] ?? 'MANUAL') === 'AUTO_PREDICTION'
        ));

        if ($matches === []) {
            return null;
        }

        usort($matches, static fn (array $left, array $right): int => ((int) ($right['id'] ?? 0)) <=> ((int) ($left['id'] ?? 0)));

        return $matches[0];
    }

    private function buildResult(array $settings, array $paperTrading, array $actions, array $evaluations, string $message): array
    {
        $autoPositions = $this->autoPositions($paperTrading['open_positions'] ?? []);

        return [
            'enabled' => (bool) ($settings['enabled'] ?? false),
            'last_run_at' => $this->nowAtom(),
            'message' => $message,
            'stats' => [
                'opened_positions' => count(array_filter($actions, static fn (array $action): bool => $action['type'] === 'OPEN')),
                'closed_positions' => count(array_filter($actions, static fn (array $action): bool => $action['type'] === 'CLOSE')),
                'auto_open_positions' => count($autoPositions),
                'evaluated_pairs' => count(array_filter($settings['pairs'] ?? [], static fn (array $pair): bool => (bool) ($pair['enabled'] ?? false))),
            ],
            'actions' => $actions,
            'evaluations' => $evaluations,
            'open_positions' => $autoPositions,
            'paper_trading' => $paperTrading,
        ];
    }

    private function acquireLock(): mixed
    {
        $directory = dirname($this->lockFilePath);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create automation lock directory.');
        }

        $handle = fopen($this->lockFilePath, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open automation lock file.');
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        return $handle;
    }

    private function releaseLock(mixed $handle): void
    {
        if (!is_resource($handle)) {
            return;
        }

        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function now(): DateTimeImmutable
    {
        $timezone = (string) $this->config->get('app.timezone', 'UTC');

        return new DateTimeImmutable('now', new DateTimeZone($timezone !== '' ? $timezone : 'UTC'));
    }

    private function nowAtom(): string
    {
        return $this->now()->format(DATE_ATOM);
    }
}