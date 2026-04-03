<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Core\Config;
use App\Services\Binance\BinanceApiClient;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

final class PaperTradeService
{
    public function __construct(
        private readonly Config $config,
        private readonly BinanceApiClient $binance,
        private readonly PaperTradeRepository $repository
    ) {
    }

    public function overview(): array
    {
        $state = $this->repository->load();
        $quotes = $this->quotesForPositions($state['positions']);

        return $this->buildOverview($state, $quotes);
    }

    public function openPosition(array $payload): array
    {
        $state = $this->repository->load();
        $account = $this->buildOverview($state, $this->quotesForPositions($state['positions']))['account'];
        $config = $this->paperConfig();

        if (count($state['positions']) >= (int) $config['max_open_positions']) {
            throw new InvalidArgumentException('Maximum open paper positions reached.');
        }

        $position = $this->buildPosition($payload, $account['available_balance'], (int) $state['meta']['next_id']);
        $state['positions'][] = $position;
        $state['meta']['next_id'] = (int) $state['meta']['next_id'] + 1;

        $this->repository->save($state);

        return $this->overview();
    }

    public function updatePosition(array $payload): array
    {
        $state = $this->repository->load();
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            throw new InvalidArgumentException('Position id is required.');
        }

        $index = $this->findOpenPositionIndex($state['positions'], $id);
        $position = $state['positions'][$index];

        $entryPrice = (float) $position['entry_price'];
        $stopLoss = $payload['stop_loss'] ?? $position['stop_loss'];
        $takeProfit = $payload['take_profit'] ?? $position['take_profit'];
        $notes = trim((string) ($payload['notes'] ?? $position['notes'] ?? ''));

        $position['stop_loss'] = $this->nullablePositiveFloat($stopLoss);
        $position['take_profit'] = $this->nullablePositiveFloat($takeProfit);
        $position['notes'] = $notes;
        $position['updated_at'] = $this->nowAtom();

        $this->assertRiskLevels($position['trade_type'], $position['side'], $entryPrice, $position['stop_loss'], $position['take_profit']);

        $state['positions'][$index] = $position;
        $this->repository->save($state);

        return $this->overview();
    }

    public function closePosition(array $payload): array
    {
        $state = $this->repository->load();
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            throw new InvalidArgumentException('Position id is required.');
        }

        $index = $this->findOpenPositionIndex($state['positions'], $id);
        $position = $state['positions'][$index];
        $closePrice = isset($payload['close_price']) && $payload['close_price'] !== ''
            ? $this->positiveFloat($payload['close_price'], 'Close price')
            : $this->binance->getPrice($position['symbol']);

        $reason = trim((string) ($payload['reason'] ?? 'MANUAL_CLOSE'));
        $snapshot = $this->positionSnapshot($position, $closePrice, $this->startingBalanceWithRealizedPnl($state));

        $position['status'] = 'CLOSED';
        $position['closed_at'] = $this->nowAtom();
        $position['updated_at'] = $position['closed_at'];
        $position['closed_price'] = round($closePrice, 8);
        $position['exit_reason'] = $reason !== '' ? $reason : 'MANUAL_CLOSE';
        $position['realized_pnl'] = $snapshot['pnl_value'];
        $position['realized_percent'] = $snapshot['pnl_percent'];
        $position['realized_roe'] = $snapshot['roe_percent'];

        array_splice($state['positions'], $index, 1);
        array_unshift($state['history'], $position);
        $state['history'] = array_slice($state['history'], 0, (int) $this->paperConfig()['history_limit']);

        $this->repository->save($state);

        return $this->overview();
    }

    private function buildPosition(array $payload, float $accountBalance, int $nextId): array
    {
        $config = $this->paperConfig();
        $symbol = strtoupper(trim((string) ($payload['symbol'] ?? '')));
        $tradeType = strtoupper(trim((string) ($payload['trade_type'] ?? 'SPOT')));
        $side = strtoupper(trim((string) ($payload['side'] ?? 'LONG')));
        $marginType = strtoupper(trim((string) ($payload['margin_type'] ?? $config['default_margin_type'] ?? 'ISOLATED')));
        $entryPrice = $this->positiveFloat($payload['entry_price'] ?? null, 'Entry price');
        $capital = $this->positiveFloat($payload['capital'] ?? null, 'Capital');
        $requestedLeverage = (int) ($payload['leverage'] ?? $config['default_leverage'] ?? 1);
        $stopLoss = $this->nullablePositiveFloat($payload['stop_loss'] ?? null);
        $takeProfit = $this->nullablePositiveFloat($payload['take_profit'] ?? null);
        $notes = trim((string) ($payload['notes'] ?? ''));

        $allowedPairs = array_map('strtoupper', (array) $this->config->get('pairs.pairs', []));
        if ($symbol === '' || !in_array($symbol, $allowedPairs, true)) {
            throw new InvalidArgumentException('Unsupported trading pair.');
        }

        if (!in_array($tradeType, ['SPOT', 'FUTURES'], true)) {
            throw new InvalidArgumentException('Trade type must be SPOT or FUTURES.');
        }

        if (!in_array($side, ['LONG', 'SHORT'], true)) {
            throw new InvalidArgumentException('Side must be LONG or SHORT.');
        }

        if ($tradeType === 'SPOT' && $side !== 'LONG') {
            throw new InvalidArgumentException('Spot simulation currently supports buy and hold only.');
        }

        if (!in_array($marginType, ['ISOLATED', 'CROSS'], true)) {
            throw new InvalidArgumentException('Margin type must be ISOLATED or CROSS.');
        }

        $leverage = $tradeType === 'SPOT' ? 1 : max(1, min((int) $config['max_leverage'], $requestedLeverage));
        $marginType = $tradeType === 'SPOT' ? 'SPOT' : $marginType;
        $notional = round($capital * $leverage, 8);
        $quantity = round($notional / $entryPrice, 8);
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Position size must be greater than zero.');
        }

        if ($capital > $accountBalance) {
            throw new InvalidArgumentException('Not enough paper balance for this position.');
        }

        $this->assertRiskLevels($tradeType, $side, $entryPrice, $stopLoss, $takeProfit);

        return [
            'id' => $nextId,
            'symbol' => $symbol,
            'trade_type' => $tradeType,
            'side' => $side,
            'margin_type' => $marginType,
            'leverage' => $leverage,
            'capital' => round($capital, 8),
            'notional' => $notional,
            'quantity' => $quantity,
            'entry_price' => round($entryPrice, 8),
            'stop_loss' => $stopLoss,
            'take_profit' => $takeProfit,
            'notes' => $notes,
            'status' => 'OPEN',
            'opened_at' => $this->nowAtom(),
            'updated_at' => $this->nowAtom(),
        ];
    }

    private function assertRiskLevels(string $tradeType, string $side, float $entryPrice, ?float $stopLoss, ?float $takeProfit): void
    {
        if ($stopLoss === null && $takeProfit === null) {
            return;
        }

        if ($side === 'LONG') {
            if ($stopLoss !== null && $stopLoss >= $entryPrice) {
                throw new InvalidArgumentException('Long stop loss must be below entry.');
            }
            if ($takeProfit !== null && $takeProfit <= $entryPrice) {
                throw new InvalidArgumentException('Long take profit must be above entry.');
            }
            return;
        }

        if ($tradeType === 'SPOT') {
            throw new InvalidArgumentException('Spot simulation does not support short positions.');
        }

        if ($stopLoss !== null && $stopLoss <= $entryPrice) {
            throw new InvalidArgumentException('Short stop loss must be above entry.');
        }
        if ($takeProfit !== null && $takeProfit >= $entryPrice) {
            throw new InvalidArgumentException('Short take profit must be below entry.');
        }
    }

    private function buildOverview(array $state, array $quotes): array
    {
        $accountBalance = $this->startingBalanceWithRealizedPnl($state);
        $openPositions = [];
        $floatingPnl = 0.0;
        $marginInUse = 0.0;

        foreach ($state['positions'] as $position) {
            $currentPrice = $quotes[$position['symbol']] ?? (float) $position['entry_price'];
            $snapshot = $this->positionSnapshot($position, $currentPrice, $accountBalance);
            $openPositions[] = array_merge($position, $snapshot, [
                'entry_gap_percent' => round($snapshot['pnl_percent'], 2),
                'distance_to_stop_percent' => $this->distancePercent($currentPrice, $position['stop_loss']),
                'distance_to_take_profit_percent' => $this->distancePercent($currentPrice, $position['take_profit']),
            ]);
            $floatingPnl += $snapshot['pnl_value'];
            $marginInUse += (float) $position['capital'];
        }

        $realizedPnl = $this->realizedPnl($state['history']);
        $equity = $accountBalance + $floatingPnl;

        return [
            'account' => [
                'starting_balance' => round((float) $this->paperConfig()['starting_balance'], 2),
                'balance' => round($accountBalance, 2),
                'equity' => round($equity, 2),
                'realized_pnl' => round($realizedPnl, 2),
                'floating_pnl' => round($floatingPnl, 2),
                'margin_in_use' => round($marginInUse, 2),
                'available_balance' => round(max(0.0, $accountBalance - $marginInUse), 2),
                'open_positions' => count($openPositions),
            ],
            'open_positions' => $openPositions,
            'history' => array_map(function (array $position): array {
                return [
                    'id' => $position['id'],
                    'symbol' => $position['symbol'],
                    'trade_type' => $position['trade_type'],
                    'side' => $position['side'],
                    'margin_type' => $position['margin_type'],
                    'leverage' => $position['leverage'],
                    'entry_price' => $position['entry_price'],
                    'closed_price' => $position['closed_price'] ?? null,
                    'opened_at' => $position['opened_at'],
                    'closed_at' => $position['closed_at'] ?? null,
                    'exit_reason' => $position['exit_reason'] ?? null,
                    'realized_pnl' => round((float) ($position['realized_pnl'] ?? 0.0), 2),
                    'realized_percent' => round((float) ($position['realized_percent'] ?? 0.0), 2),
                    'realized_roe' => round((float) ($position['realized_roe'] ?? 0.0), 2),
                ];
            }, $state['history']),
        ];
    }

    private function positionSnapshot(array $position, float $currentPrice, float $accountBalance): array
    {
        $entryPrice = (float) $position['entry_price'];
        $quantity = (float) $position['quantity'];
        $capital = (float) $position['capital'];
        $leverage = max(1, (int) $position['leverage']);
        $side = (string) $position['side'];
        $tradeType = (string) $position['trade_type'];
        $marginType = (string) $position['margin_type'];

        $priceDelta = $side === 'SHORT' ? ($entryPrice - $currentPrice) : ($currentPrice - $entryPrice);
        $pnlValue = $priceDelta * $quantity;
        $pnlPercent = $entryPrice > 0 ? ($priceDelta / $entryPrice) * 100 : 0.0;
        $roePercent = $capital > 0 ? ($pnlValue / $capital) * 100 : 0.0;
        $maintenance = (float) $this->paperConfig()['maintenance_margin_rate'];

        return [
            'current_price' => round($currentPrice, 8),
            'mark_price' => round($currentPrice, 8),
            'pnl_value' => round($pnlValue, 2),
            'pnl_percent' => round($pnlPercent, 2),
            'roe_percent' => round($roePercent, 2),
            'liquidation_price_estimate' => round($this->estimateLiquidation($tradeType, $side, $marginType, $entryPrice, $capital, $accountBalance, $quantity, $maintenance), 8),
            'risk_to_stop_value' => round($this->riskToLevel($position, $position['stop_loss']), 2),
            'reward_to_take_profit_value' => round($this->rewardToLevel($position, $position['take_profit']), 2),
        ];
    }

    private function estimateLiquidation(string $tradeType, string $side, string $marginType, float $entryPrice, float $capital, float $accountBalance, float $quantity, float $maintenance): float
    {
        if ($tradeType === 'SPOT' || $quantity <= 0) {
            return 0.0;
        }

        $buffer = $marginType === 'CROSS' ? max($capital, $accountBalance * 0.65) : $capital;
        $lossBuffer = max(0.0, $buffer - ($buffer * $maintenance));
        $moveAgainst = $lossBuffer / $quantity;

        if ($side === 'LONG') {
            return max(0.0, $entryPrice - $moveAgainst);
        }

        return max(0.0, $entryPrice + $moveAgainst);
    }

    private function riskToLevel(array $position, mixed $level): float
    {
        if ($level === null || $level === '') {
            return 0.0;
        }

        return abs(((float) $position['entry_price'] - (float) $level) * (float) $position['quantity']);
    }

    private function rewardToLevel(array $position, mixed $level): float
    {
        if ($level === null || $level === '') {
            return 0.0;
        }

        return abs(((float) $level - (float) $position['entry_price']) * (float) $position['quantity']);
    }

    private function distancePercent(float $currentPrice, mixed $level): float
    {
        if ($level === null || $level === '' || $currentPrice <= 0) {
            return 0.0;
        }

        return round(abs((((float) $level - $currentPrice) / $currentPrice) * 100), 2);
    }

    private function quotesForPositions(array $positions): array
    {
        $quotes = [];
        $symbols = array_values(array_unique(array_map(static fn (array $position): string => (string) $position['symbol'], $positions)));

        foreach ($symbols as $symbol) {
            try {
                $quotes[$symbol] = $this->binance->getPrice($symbol);
            } catch (RuntimeException) {
                $quotes[$symbol] = null;
            }
        }

        return $quotes;
    }

    private function realizedPnl(array $history): float
    {
        return array_reduce($history, static function (float $carry, array $position): float {
            return $carry + (float) ($position['realized_pnl'] ?? 0.0);
        }, 0.0);
    }

    private function startingBalanceWithRealizedPnl(array $state): float
    {
        return (float) $this->paperConfig()['starting_balance'] + $this->realizedPnl($state['history']);
    }

    private function findOpenPositionIndex(array $positions, int $id): int
    {
        foreach ($positions as $index => $position) {
            if ((int) ($position['id'] ?? 0) === $id) {
                return $index;
            }
        }

        throw new InvalidArgumentException('Open position not found.');
    }

    private function positiveFloat(mixed $value, string $field): float
    {
        if ($value === null || $value === '') {
            throw new InvalidArgumentException($field . ' is required.');
        }

        $float = (float) $value;
        if ($float <= 0) {
            throw new InvalidArgumentException($field . ' must be greater than zero.');
        }

        return $float;
    }

    private function nullablePositiveFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $float = (float) $value;
        if ($float <= 0) {
            throw new InvalidArgumentException('Optional price levels must be greater than zero.');
        }

        return round($float, 8);
    }

    private function paperConfig(): array
    {
        return (array) $this->config->get('paper', []);
    }

    private function nowAtom(): string
    {
        $timezone = (string) $this->config->get('app.timezone', 'UTC');

        return (new DateTimeImmutable('now', new DateTimeZone($timezone !== '' ? $timezone : 'UTC')))->format(DATE_ATOM);
    }
}
