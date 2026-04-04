<?php

return [
    'default_interval' => '15m',
    'decision_timeframe' => '15m',
    'confirmation_timeframe' => '1h',
    'trigger_timeframe' => '5m',
    'analysis_timeframes' => [
        '5m',
        '15m',
        '1h',
    ],
    'prediction_timeframes' => [
        '15m',
        '1h',
        '4h',
    ],
    'analysis_limit' => 240,
    'prediction_limit' => 160,
    'default_limit' => 240,
    'refresh_seconds' => 5,
    'pairs' => [
        'BTCUSDT',
        'ETHUSDT',
        'BNBUSDT',
    ],
];
